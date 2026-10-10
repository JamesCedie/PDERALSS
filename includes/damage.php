<?php
/**
 * includes/damage.php
 * Damage-assessment evidence pipeline:
 *   1. georeferencing  - device GPS (required) cross-checked against photo EXIF and the city bounds
 *   2. watermarking    - visible stamp (ref, barangay, reporter, GPS, time, hash) burned in with GD
 *   3. integrity       - SHA-256 of original + watermarked file, HMAC signature over the record
 *   4. storage         - both files go to the private Supabase Storage bucket
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/supabase.php';

const DA_MAX_BYTES    = 12 * 1024 * 1024;
const DA_MAX_EDGE     = 1600;
const DA_ASSET_TYPES  = ['House', 'Infrastructure', 'Road', 'Facility'];
const DA_DAMAGE_TYPES = ['Fully Damaged', 'Partially Damaged', 'Minor Damage'];
// Rough bounding box of Iloilo City: lat min, lat max, lng min, lng max.
const DA_CITY_BBOX    = [10.64, 10.78, 122.47, 122.62];
// GPS accuracy (metres) worse than this is flagged 'low_accuracy' and refused by the form.
// Laptops have no GPS (Wi-Fi/IP location is typically 100-5000 m), so for localhost testing set
// DA_MAX_ACCURACY_M=100000 in .env. Keep the default (50) in production.
define('DA_MAX_ACC_M', max(1, (int) (getenv('DA_MAX_ACCURACY_M') ?: 50)));

class DaException extends RuntimeException {}

// ---- CSRF ------------------------------------------------------------------
function da_csrf_token(): string
{
    if (empty($_SESSION['da_csrf'])) {
        $_SESSION['da_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['da_csrf'];
}

function da_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(da_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function da_csrf_valid(): bool
{
    return !empty($_SESSION['da_csrf']) && hash_equals($_SESSION['da_csrf'], (string) ($_POST['csrf'] ?? ''));
}

// ---- Georeferencing --------------------------------------------------------
function da_haversine_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r  = 6371000.0;
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lng2 - $lng1);
    $a  = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

function da_in_city(float $lat, float $lng): bool
{
    [$latMin, $latMax, $lngMin, $lngMax] = DA_CITY_BBOX;
    return $lat >= $latMin && $lat <= $latMax && $lng >= $lngMin && $lng <= $lngMax;
}

function da_rational($v): float
{
    if (is_string($v) && strpos($v, '/') !== false) {
        [$n, $d] = array_map('floatval', explode('/', $v, 2));
        return $d != 0.0 ? $n / $d : 0.0;
    }
    return (float) $v;
}

/** Reads GPS, capture time and orientation from a JPEG's EXIF (all optional). */
function da_exif_read(string $file, string $mime): array
{
    $out = ['lat' => null, 'lng' => null, 'taken' => null, 'orientation' => 1];
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return $out;
    }
    $ex = @exif_read_data($file);
    if (!is_array($ex)) {
        return $out;
    }
    $out['orientation'] = (int) ($ex['Orientation'] ?? 1);

    if (!empty($ex['GPSLatitude']) && !empty($ex['GPSLongitude']) && !empty($ex['GPSLatitudeRef']) && !empty($ex['GPSLongitudeRef'])
        && is_array($ex['GPSLatitude']) && is_array($ex['GPSLongitude'])) {
        $conv = function (array $p): float {
            return da_rational($p[0] ?? 0) + da_rational($p[1] ?? 0) / 60 + da_rational($p[2] ?? 0) / 3600;
        };
        $lat = $conv($ex['GPSLatitude']);
        $lng = $conv($ex['GPSLongitude']);
        if (strtoupper((string) $ex['GPSLatitudeRef']) === 'S')  $lat = -$lat;
        if (strtoupper((string) $ex['GPSLongitudeRef']) === 'W') $lng = -$lng;
        if (abs($lat) <= 90 && abs($lng) <= 180 && ($lat != 0.0 || $lng != 0.0)) {
            $out['lat'] = $lat;
            $out['lng'] = $lng;
        }
    }

    if (!empty($ex['DateTimeOriginal'])) {
        $d = DateTimeImmutable::createFromFormat('Y:m:d H:i:s', (string) $ex['DateTimeOriginal'], new DateTimeZone('Asia/Manila'));
        if ($d) $out['taken'] = $d->getTimestamp();
    }
    return $out;
}

function da_flag_labels(?string $flags): array
{
    $map = [
        'outside_city'  => 'Location is outside Iloilo City',
        'exif_mismatch' => 'Photo GPS metadata differs from the submitted location',
        'old_photo'     => 'Photo was taken more than 24 hours before submission',
        'low_accuracy'  => 'GPS accuracy was worse than ' . DA_MAX_ACC_M . ' m',
    ];
    $out = [];
    foreach (array_filter(explode(',', (string) $flags)) as $f) {
        $out[] = $map[$f] ?? $f;
    }
    return $out;
}

// ---- Visible watermark -----------------------------------------------------
/** Word-wraps text to a pixel width (TTF metrics when a font exists, ~7px/char otherwise). */
function da_wrap(string $text, int $size, ?string $font, int $maxW): array
{
    $measure = function (string $t) use ($size, $font): int {
        if ($font) {
            $bb = imagettfbbox($size, 0, $font, $t);
            return (int) ($bb[2] - $bb[0]);
        }
        return strlen($t) * 7;
    };
    $out = [];
    $cur = '';
    foreach (preg_split('/\s+/', trim($text)) as $word) {
        $try = $cur === '' ? $word : $cur . ' ' . $word;
        if ($cur === '' || $measure($try) <= $maxW) {
            $cur = $try;
        } else {
            $out[] = $cur;
            $cur = $word;
        }
    }
    if ($cur !== '') $out[] = $cur;
    return $out ?: [''];
}

/**
 * Street-level address for a coordinate via OpenStreetMap Nominatim (server side, so the
 * address printed on the photo is not taken from the browser). Returns '' when unavailable.
 * Optional env NOMINATIM_EMAIL is sent as contact info, as Nominatim's usage policy asks.
 */
function da_reverse_geocode(float $lat, float $lng): string
{
    if (!function_exists('curl_init')) return '';
    $qs = http_build_query([
        'format' => 'jsonv2', 'addressdetails' => 1, 'zoom' => 18, 'accept-language' => 'en',
        'lat' => number_format($lat, 6, '.', ''), 'lon' => number_format($lng, 6, '.', ''),
    ] + (getenv('NOMINATIM_EMAIL') ? ['email' => getenv('NOMINATIM_EMAIL')] : []));
    $ch = curl_init('https://nominatim.openstreetmap.org/reverse?' . $qs);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 7,
        CURLOPT_USERAGENT      => 'PDERALSS-DamageAssessment/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) return '';

    $j = json_decode((string) $body, true);
    if (!is_array($j)) return '';
    $a = is_array($j['address'] ?? null) ? $j['address'] : [];

    $street = trim(implode(' ', array_filter([$a['house_number'] ?? '', $a['road'] ?? ''])));
    $parts  = [];
    foreach ([
        $street,
        $a['neighbourhood'] ?? ($a['quarter'] ?? ($a['suburb'] ?? ($a['village'] ?? ''))),
        $a['city_district'] ?? '',
        $a['city'] ?? ($a['town'] ?? ($a['municipality'] ?? ($a['county'] ?? ''))),
    ] as $p) {
        $p = trim((string) $p);
        if ($p !== '' && !in_array($p, $parts, true)) $parts[] = $p;
    }
    if (!$parts && !empty($j['display_name'])) {
        $parts = array_slice(array_map('trim', explode(',', (string) $j['display_name'])), 0, 4);
    }
    $addr = mb_substr(implode(', ', $parts), 0, 300);
    return preg_replace('/[^\P{C}\n]+/u', '', $addr) ?? '';
}

function da_font(): ?string
{
    foreach ([
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        'C:\\Windows\\Fonts\\arial.ttf',
    ] as $f) {
        if (is_readable($f)) return $f;
    }
    return null;
}

/**
 * Decodes, auto-rotates, downsizes (max edge DA_MAX_EDGE), stamps and re-encodes as JPEG.
 * Re-encoding also strips the original EXIF (the untouched original is kept separately).
 * Returns the JPEG bytes.
 */
function da_watermark(string $srcFile, string $mime, int $orientation, array $lines, string $tileText): string
{
    if (!extension_loaded('gd')) {
        throw new DaException('Image processing (GD) is not available on the server.');
    }

    switch ($mime) {
        case 'image/jpeg': $img = @imagecreatefromjpeg($srcFile); break;
        case 'image/png':  $img = @imagecreatefrompng($srcFile);  break;
        case 'image/webp': $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcFile) : false; break;
        default:           $img = false;
    }
    if (!$img) {
        throw new DaException('The image could not be read. Please upload a JPEG, PNG or WebP photo.');
    }

    $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
    if ($angle !== 0) {
        $rot = imagerotate($img, $angle, 0);
        if ($rot) { imagedestroy($img); $img = $rot; }
    }

    $w = imagesx($img);
    $h = imagesy($img);
    if (max($w, $h) > DA_MAX_EDGE) {
        $scale = DA_MAX_EDGE / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $small = imagescale($img, $nw, $nh);
        if ($small) { imagedestroy($img); $img = $small; $w = $nw; $h = $nh; }
    }

    // Flatten onto white (handles PNG/WebP transparency and palette images).
    $canvas = imagecreatetruecolor($w, $h);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
    imagedestroy($img);
    $img = $canvas;
    imagealphablending($img, true);

    $font = da_font();

    // 1) faint diagonal tiled stamp - hard to crop out
    if ($font) {
        $ts    = max(10, (int) round($w / 42));
        $bb    = imagettfbbox($ts, 0, $font, $tileText);
        $tw    = $bb[2] - $bb[0];
        $stepX = (int) ($tw * 1.45);
        $stepY = (int) ($ts * 7);
        $light = imagecolorallocatealpha($img, 255, 255, 255, 104);
        $dark  = imagecolorallocatealpha($img, 0, 0, 0, 112);
        $row   = 0;
        for ($yy = (int) ($ts * 4); $yy < $h + $stepY; $yy += $stepY, $row++) {
            for ($xx = -($row % 2) * (int) ($stepX / 2); $xx < $w; $xx += $stepX) {
                imagettftext($img, $ts, 28, $xx + 1, $yy + 1, $dark, $font, $tileText);
                imagettftext($img, $ts, 28, $xx, $yy, $light, $font, $tileText);
            }
        }
    }

    // 2) information banner along the bottom (long lines such as the address are word-wrapped)
    $pad  = 10;
    $size = max(10, (int) round($w / 52));
    $lh   = $font ? (int) round($size * 1.8) : 16;
    $wrapped = [];
    foreach ($lines as $l) {
        foreach (da_wrap($l, $size, $font, $w - 24) as $wl) $wrapped[] = $wl;
    }
    $lines = array_slice($wrapped, 0, 9);
    $bandH = $pad * 2 + $lh * count($lines) - (int) ($lh * 0.25);
    imagefilledrectangle($img, 0, $h - $bandH, $w, $h, imagecolorallocatealpha($img, 0, 0, 0, 48));
    $white = imagecolorallocate($img, 255, 255, 255);
    $y = $h - $bandH + $pad + (int) ($lh * 0.75);
    foreach ($lines as $l) {
        if ($font) {
            imagettftext($img, $size, 0, 12, $y, $white, $font, $l);
        } else {
            imagestring($img, 3, 12, $y - 12, $l, $white);
        }
        $y += $lh;
    }

    ob_start();
    imagejpeg($img, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    if ($bytes === '') {
        throw new DaException('The watermarked image could not be generated.');
    }
    return $bytes;
}

// ---- Integrity -------------------------------------------------------------
function da_secret(): string
{
    $s = getenv('WATERMARK_SECRET');
    if ($s) return (string) $s;
    return hash('sha256', 'pderalss-damage|' . (sb_key() ?: DB_PASS));
}

function da_sign_fields(string $ref, string $origSha, string $photoSha, float $lat, float $lng, int $ts, string $userId): string
{
    $msg = implode('|', [
        $ref, $origSha, $photoSha,
        number_format($lat, 6, '.', ''), number_format($lng, 6, '.', ''),
        $ts, $userId,
    ]);
    return hash_hmac('sha256', $msg, da_secret());
}

/** True when the stored record still matches the signature made at submission. */
function da_row_signature_valid(array $r): bool
{
    if (empty($r['signature']) || $r['latitude'] === null || $r['longitude'] === null) return false;
    $expected = da_sign_fields(
        (string) $r['reference_code'],
        trim((string) $r['original_sha256']),
        trim((string) $r['photo_sha256']),
        (float) $r['latitude'],
        (float) $r['longitude'],
        (int) strtotime((string) $r['captured_at']),
        (string) ($r['submitted_by'] ?? '')
    );
    return hash_equals($expected, trim((string) $r['signature']));
}

/** MD summary uses two levels; map the Social Worker's three options onto them. */
function da_md_level(string $damageStatus): string
{
    return $damageStatus === 'Fully Damaged' ? 'Totally Damaged' : 'Partially Damaged';
}

// ---- Submission ------------------------------------------------------------
/**
 * Validates and stores one assessment with its photo. Returns the inserted row.
 * Throws DaException with a user-facing message on any problem.
 */
function da_submit(string $userId, string $userName, string $barangay, array $in, ?array $file): array
{
    if ($barangay === '') {
        throw new DaException('Your account has no barangay assigned, so assessments cannot be submitted.');
    }
    if (!sb_configured()) {
        throw new DaException('Photo storage is not configured on the server (SUPABASE_SERVICE_KEY is missing).');
    }

    $reporter = mb_substr(trim((string) ($in['reporter'] ?? '')), 0, 150);
    if ($reporter === '') $reporter = $userName;

    $asset = (string) ($in['asset_type'] ?? '');
    if (!in_array($asset, DA_ASSET_TYPES, true)) throw new DaException('Please choose a valid asset type.');

    $damage = (string) ($in['damage_status'] ?? '');
    if (!in_array($damage, DA_DAMAGE_TYPES, true)) throw new DaException('Please choose a valid damage status.');

    $desc = mb_substr(trim((string) ($in['description'] ?? '')), 0, 2000);

    $hid = (int) ($in['household_id'] ?? 0);
    $hh  = $hid > 0 ? db_select_one('household', 'household_id = ? AND barangay = ?', [$hid, $barangay], 'household_id') : null;
    if (!$hh) throw new DaException('Please select a household from your barangay.');

    if (!isset($in['latitude'], $in['longitude']) || !is_numeric($in['latitude']) || !is_numeric($in['longitude'])) {
        throw new DaException('The photo location was not captured. Allow location access and try again.');
    }
    $lat = round((float) $in['latitude'], 6);
    $lng = round((float) $in['longitude'], 6);
    if (abs($lat) > 90 || abs($lng) > 180) throw new DaException('The submitted location is invalid.');
    $acc = (isset($in['gps_accuracy']) && is_numeric($in['gps_accuracy'])) ? max(0.0, min(100000.0, (float) $in['gps_accuracy'])) : null;
    $src = 'device';   // location is read automatically at capture time; manual pins are not accepted

    // ---- file checks (photos must come from the in-app camera)
    if (($in['capture_method'] ?? '') !== 'camera') {
        throw new DaException('Photos must be taken with the in-app camera.');
    }
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new DaException('Please attach a photo of the damage.');
    }
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new DaException('The photo is too large (maximum 12 MB).');
        }
        throw new DaException('The photo upload failed. Please try again.');
    }
    $tmp = (string) $file['tmp_name'];
    if (!is_uploaded_file($tmp)) throw new DaException('Invalid upload.');
    if (($file['size'] ?? 0) > DA_MAX_BYTES) throw new DaException('The photo is too large (maximum 12 MB).');

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $exts = ['image/jpeg' => 'jpg'];   // the in-app camera always produces JPEG
    if (!isset($exts[$mime])) throw new DaException('Only photos taken with the in-app camera are accepted.');

    $origBytes = (string) file_get_contents($tmp);
    $origSha   = hash('sha256', $origBytes);
    $exif      = da_exif_read($tmp, $mime);

    // ---- georeference checks (flagged for MD review, not blocking)
    $flags = [];
    if (!da_in_city($lat, $lng)) $flags[] = 'outside_city';
    if ($exif['lat'] !== null && da_haversine_m($lat, $lng, $exif['lat'], $exif['lng']) > 300) $flags[] = 'exif_mismatch';
    if ($exif['taken'] !== null && abs(time() - $exif['taken']) > 86400) $flags[] = 'old_photo';
    if ($acc !== null && $acc > DA_MAX_ACC_M) $flags[] = 'low_accuracy';

    // ---- capture time: use the browser's capture moment when it is plausible, else server time
    $ts = time();
    if (isset($in['captured_ts']) && is_numeric($in['captured_ts'])) {
        $c = (int) $in['captured_ts'];
        if ($c <= $ts + 60 && $c >= $ts - 1800) $ts = $c;
    }
    $ref = 'DA-' . gmdate('ymd', $ts) . '-' . strtoupper(bin2hex(random_bytes(3)));
    $local = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Asia/Manila'))->format('d M Y H:i:s');

    // ---- address (reverse geocoded server side)
    $address = da_reverse_geocode($lat, $lng);
    if ($address !== '' && !da_font()) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $address);
        if ($t !== false) $address = $t;
    }

    // ---- watermark
    $lines = [
        'PDERALSS Damage Assessment  ' . $ref,
        $barangay . '  |  Reporter: ' . $reporter,
        'Date/Time: ' . $local . ' PHT',
        sprintf('Latitude: %.6f   Longitude: %.6f', $lat, $lng) . ($acc !== null ? sprintf('  (+/- %d m)', (int) round($acc)) : ''),
        'Address: ' . ($address !== '' ? $address : 'unavailable'),
        'SHA-256 ' . substr($origSha, 0, 16),
    ];
    $wmBytes  = da_watermark($tmp, $mime, $exif['orientation'], $lines, 'PDERALSS  ' . $ref);
    $photoSha = hash('sha256', $wmBytes);

    // ---- store both files privately in Supabase
    $slug      = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($barangay)), '-') ?: 'barangay';
    $photoPath = $slug . '/' . $ref . '.jpg';
    $origPath  = 'original/' . $slug . '/' . $ref . '.' . $exts[$mime];

    if (!sb_upload($photoPath, $wmBytes, 'image/jpeg')) {
        throw new DaException('The photo could not be saved to storage. Please try again.');
    }
    if (!sb_upload($origPath, $origBytes, $mime)) {
        sb_delete([$photoPath]);
        throw new DaException('The original photo could not be saved to storage. Please try again.');
    }

    $signature = da_sign_fields($ref, $origSha, $photoSha, $lat, $lng, $ts, $userId);

    try {
        $row = db_insert('damage_assessments', [
            'reference_code'  => $ref,
            'household_id'    => $hid,
            'barangay'        => $barangay,
            'reporter'        => $reporter,
            'asset_type'      => $asset,
            'damage_status'   => $damage,
            'description'     => $desc !== '' ? $desc : null,
            'latitude'        => $lat,
            'longitude'       => $lng,
            'gps_accuracy_m'  => $acc,
            'location_source' => $src,
            'location_flags'  => $flags ? implode(',', $flags) : null,
            'exif_lat'        => $exif['lat'],
            'exif_lng'        => $exif['lng'],
            'exif_taken_at'   => $exif['taken'] !== null ? gmdate('Y-m-d H:i:sP', $exif['taken']) : null,
            'address'         => $address !== '' ? $address : null,
            'photo_path'      => $photoPath,
            'original_path'   => $origPath,
            'original_sha256' => $origSha,
            'photo_sha256'    => $photoSha,
            'signature'       => $signature,
            'captured_at'     => gmdate('Y-m-d H:i:sP', $ts),
            'submitted_by'    => $userId,
        ]);
    } catch (Throwable $e) {
        sb_delete([$photoPath, $origPath]);
        throw new DaException('The assessment could not be saved. Please try again.');
    }

    if (!$row) {
        sb_delete([$photoPath, $origPath]);
        throw new DaException('The assessment could not be saved. Please try again.');
    }
    return $row;
}
