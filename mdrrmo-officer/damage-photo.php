<?php
// View-only proxy for damage evidence photos. The Supabase bucket is private; only a signed-in
// MDRRMO Officer can load an image, and it is refused if the stored file no longer matches its hash.
//
//   damage-photo.php?id=7              -> the watermarked photo (shown in the View modal)
//   damage-photo.php?id=7&original=1   -> the untouched original (opens in a new tab)
require_once '../includes/access.php'; require_page_access();
require_once '../includes/damage.php';

if (($_SESSION['user']['role'] ?? '') !== 'MDRRMO Officer') {
    http_response_code(403);
    exit('Forbidden');
}

$id       = (int) ($_GET['id'] ?? 0);
$original = ($_GET['original'] ?? '') === '1';
$pathCol  = $original ? 'original_path'   : 'photo_path';
$shaCol   = $original ? 'original_sha256' : 'photo_sha256';

// The browser only ever sends an id; the storage path always comes from the database.
$row = $id > 0
    ? db_select_one('damage_assessments', 'assessment_id = ?', [$id], "reference_code, $pathCol, $shaCol")
    : null;
if (!$row || empty($row[$pathCol])) {
    http_response_code(404);
    exit('Not found');
}

$res = sb_download($row[$pathCol]);
if (!$res['ok']) {
    http_response_code(502);
    exit('Photo storage unavailable');
}
if (!hash_equals(trim((string) $row[$shaCol]), hash('sha256', $res['body']))) {
    http_response_code(422);
    exit('Integrity check failed: this image no longer matches its recorded hash.');
}

$name = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $row['reference_code']) . ($original ? '-original' : '') . '.jpg';

header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($res['body']));
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo $res['body'];
