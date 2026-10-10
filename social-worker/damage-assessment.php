<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/damage.php';
require_once '../includes/md.php';
db_ensure_damage_assessments_table();
db_ensure_disaster_event_status();

$currentUser = db_select_one('users', 'user_id = ?', [$_SESSION['user']['id'] ?? null]);
$swBarangay  = $currentUser['address'] ?? '';

// Handle "Submit Assessment" (photo + georeference + watermark), then redirect back (PRG).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assessment'])) {
    try {
        if (!da_csrf_valid()) throw new DaException('Your session expired. Please reload the page and try again.');
        $saved = da_submit(
            (string) ($_SESSION['user']['id'] ?? ''),
            (string) ($_SESSION['user']['name'] ?? ''),
            $swBarangay,
            $_POST,
            $_FILES['photo'] ?? null
        );
        $_SESSION['da_flash'] = ['success', 'Assessment ' . $saved['reference_code'] . ' submitted for verification.'];
    } catch (DaException $e) {
        $_SESSION['da_flash'] = ['danger', $e->getMessage()];
    } catch (Throwable $e) {
        $_SESSION['da_flash'] = ['danger', 'Something went wrong while submitting the assessment.'];
    }
    $redirEvent = (int) ($_GET['event_id'] ?? 0);
    header('Location: damage-assessment.php' . ($redirEvent ? '?event_id=' . $redirEvent : ''));
    exit;
}

$flash = $_SESSION['da_flash'] ?? null;
unset($_SESSION['da_flash']);

require '../includes/layout.php';
page_start('Damage Assessment');

// Latest active disaster event (shown in the "Event:" label, same as the other pages).
$reqEventId  = (int) ($_GET['event_id'] ?? 0);
$activeEvent = $reqEventId ? db_select_one('disaster_events', 'event_id = ?', [$reqEventId], 'event_id, event_name') : null;
if (!$activeEvent) $activeEvent = db_select_one('disaster_events', "status = 'Active'", [], 'event_id, event_name', 'event_id DESC');

// This barangay's assessments. Family head comes from the linked household.
$records = [];
$totally = 0;
$partially = 0;
$daRows = db_query(
    'SELECT d.*, h.firstname, h.middlename, h.lastname, h.nameextension
     FROM damage_assessments d LEFT JOIN household h ON h.household_id = d.household_id
     WHERE d.barangay = ?
     ORDER BY d.created_at DESC',
    [$swBarangay]
)->fetchAll();
foreach ($daRows as $r) {
    $head = trim((string) ($r['lastname'] ?? ''));
    if ($head !== '') {
        $given = trim(implode(' ', array_filter([$r['firstname'] ?? '', $r['middlename'] ?? '', $r['nameextension'] ?? ''])));
        $head .= $given !== '' ? ', ' . $given : '';
    } else {
        $head = (string) $r['reporter'];
    }
    $level = da_md_level((string) $r['damage_status']);
    if ($level === 'Totally Damaged') $totally++; else $partially++;
    $records[] = [
        'id'       => (int) $r['assessment_id'],
        'head'     => $head,
        'level'    => $level,
        'reported' => $r['damage_status'],
        'date'     => md_date($r['created_at']),
        'ref'      => $r['reference_code'],
        'asset'    => $r['asset_type'],
        'reporter' => $r['reporter'],
        'notes'    => $r['description'],
        'status'   => $r['status'],
        'lat'      => $r['latitude'] !== null ? (float) $r['latitude'] : null,
        'lng'      => $r['longitude'] !== null ? (float) $r['longitude'] : null,
        'accuracy' => $r['gps_accuracy_m'] !== null ? (int) round((float) $r['gps_accuracy_m']) : null,
        'address'  => $r['address'] ?? null,
        'flags'    => da_flag_labels($r['location_flags']),
    ];
}
$totalAssessments = count($records);

$households = $swBarangay !== ''
    ? db_select('household', 'barangay = ?', [$swBarangay], 'household_id, firstname, middlename, lastname, nameextension', 'lastname ASC, firstname ASC')
    : [];
?>

<div class="md-page">
<div class="sw-section-head">
    <div class="sw-event-label">Event:<strong><?= md_h($activeEvent ? $activeEvent['event_name'] . ' · ' . md_short_barangay((string) $swBarangay) : 'No active event') ?></strong></div>
    <button class="btn btn-primary" onclick="openModal('damageModal')">＋ &nbsp;Submit Assessment</button>
</div>

<div class="sw-casualty-title">Total Assessments Submitted</div>
<div class="md-split">
    <div class="card sw-total-card">
        <div class="stat-label">Assessments Count</div>
        <div class="stat-value"><?= number_format($totalAssessments) ?></div>
    </div>
    <div class="card sw-breakdown-card">
        <h3>Assessment Count Breakdown</h3>
        <div class="sw-breakdown md-breakdown-2">
            <div class="sw-breakdown-item fatal"><label>Totally Damaged</label><strong><?= number_format($totally) ?></strong></div>
            <div class="sw-breakdown-item injured"><label>Partially Damaged</label><strong><?= number_format($partially) ?></strong></div>
        </div>
    </div>
</div>

<div class="sw-casualty-title mt">Assessment Records</div>
<label class="sw-search">
    <span aria-hidden="true">⌕</span>
    <input type="search" id="swSearch" placeholder="Search..." autocomplete="off">
</label>

<div class="sw-data-table">
    <table class="table" id="swTable">
        <thead>
            <tr><th>Household Head</th><th>Damage Level</th><th>Date</th><th class="vr-right">Action</th></tr>
        </thead>
        <tbody>
        <?php if (!$records): ?>
            <tr><td colspan="4" class="empty">No assessments recorded yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($records as $r): ?>
            <tr data-search="<?= md_h(strtolower($r['head'] . ' ' . $r['level'] . ' ' . $r['ref'])) ?>">
                <td><strong><?= md_h($r['head']) ?></strong></td>
                <td><?= md_h($r['level']) ?></td>
                <td><?= md_h($r['date']) ?></td>
                <td class="vr-right"><button type="button" class="btn btn-dark-sm" onclick="openModal('damageView-<?= $r['id'] ?>')">View</button></td>
            </tr>
        <?php endforeach; ?>
        <tr id="swNoMatch" hidden><td colspan="4" class="empty">No assessments match your search.</td></tr>
        </tbody>
    </table>
</div>
</div>

<?php foreach ($records as $r): $mid = 'damageView-' . $r['id']; ?>
    <div id="<?= $mid ?>" class="modal">
        <div class="modal-box md-view-modal">
            <div class="modal-head">
                <div>
                    <h2>Damage Assessment Details</h2>
                    <div class="mini"><?= md_h($swBarangay) ?> · <?= md_h($r['ref']) ?></div>
                </div>
                <button type="button" class="icon-btn" onclick="closeModal('<?= $mid ?>')" aria-label="Close">×</button>
            </div>
            <div class="sw-view-grid">
                <div class="sw-view-item"><label>Household Head</label><div><?= md_h($r['head']) ?></div></div>
                <div class="sw-view-item"><label>Damage Level</label><div><?= md_h($r['level']) ?></div></div>
                <div class="sw-view-item"><label>Date Assessed</label><div><?= md_h($r['date']) ?></div></div>
                <div class="sw-view-item"><label>Status</label><div><?= status_badge($r['status']) ?></div></div>
                <div class="sw-view-item"><label>Asset Type · Reported Damage</label><div><?= md_h($r['asset']) ?> · <?= md_h($r['reported']) ?></div></div>
                <div class="sw-view-item"><label>Reporter</label><div><?= md_h($r['reporter']) ?></div></div>
                <div class="sw-view-item" style="grid-column:1 / -1"><label>Description</label><div><?= $r['notes'] ? md_h($r['notes']) : 'No description.' ?></div></div>
                <div class="sw-view-item" style="grid-column:1 / -1"><label>Georeference</label>
                    <div>
                        <?php if ($r['lat'] !== null): ?>
                            <?= md_h(number_format($r['lat'], 6)) ?>, <?= md_h(number_format($r['lng'], 6)) ?>
                            <?= $r['accuracy'] !== null ? '(±' . (int) $r['accuracy'] . ' m)' : '' ?>
                            <?php if (!empty($r['address'])): ?><div class="mini">📍 <?= md_h($r['address']) ?></div><?php endif; ?>
                            <?php foreach ($r['flags'] as $fl): ?><span class="badge b-yellow"><?= md_h($fl) ?></span> <?php endforeach; ?>
                        <?php else: ?>No location recorded.<?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="actions">
                <button type="button" class="btn btn-primary" onclick="closeModal('<?= $mid ?>')">Close</button>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<div id="damageModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Submit Damage Assessment</h2>
            <button class="icon-btn" onclick="closeModal('damageModal')">✕</button>
        </div>

        <form id="damageForm" method="post" enctype="multipart/form-data">
            <?= da_csrf_field() ?>
            <input type="hidden" name="submit_assessment" value="1">
            <input type="hidden" name="latitude" id="daLat">
            <input type="hidden" name="longitude" id="daLng">
            <input type="hidden" name="gps_accuracy" id="daAcc">
            <input type="hidden" name="location_source" id="daSrc" value="device">
            <input type="hidden" name="captured_ts" id="daTs">
            <div class="form-grid">
                <div class="field">
                    <label>Barangay</label>
                    <input required value="<?= htmlspecialchars($swBarangay) ?>" readonly>
                </div>

                <div class="field">
                    <label>Reporter</label>
                    <input name="reporter" required value="<?= htmlspecialchars($_SESSION['user']['name'] ?? '') ?>">
                </div>

                <div class="field field-full">
                    <label>Household (Family Head)</label>
                    <select name="household_id" required>
                        <option value="">Select household…</option>
                        <?php foreach ($households as $h):
                            $nm = preg_replace('/\s+/', ' ', trim(($h['lastname'] ?? '') . ', ' . implode(' ', array_filter([$h['firstname'] ?? '', $h['middlename'] ?? '', $h['nameextension'] ?? '']))));
                        ?>
                            <option value="<?= (int) $h['household_id'] ?>"><?= htmlspecialchars($nm) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Asset Type</label>
                    <select name="asset_type">
                        <option>House</option>
                        <option>Infrastructure</option>
                        <option>Road</option>
                        <option>Facility</option>
                    </select>
                </div>

                <div class="field">
                    <label>Damage Status</label>
                    <select name="damage_status">
                        <option>Fully Damaged</option>
                        <option>Partially Damaged</option>
                        <option>Minor Damage</option>
                    </select>
                </div>

                <div class="field field-full">
                    <label>Description</label>
                    <textarea name="description"></textarea>
                </div>

                <div class="field field-full">
                    <label>Photo Evidence (live camera only)</label>
                    <input type="hidden" name="capture_method" value="camera">
                    <div class="da-camera" id="daCamera">
                        <video id="daVideo" class="da-video" autoplay playsinline muted></video>
                        <img id="daShot" class="da-shot" alt="Captured photo" hidden>
                        <div id="daCamMsg" class="da-cam-msg">Starting camera…</div>
                        <div id="daStamp" class="da-stamp" hidden></div>
                    </div>
                    <div class="da-cam-actions">
                        <button type="button" class="btn btn-dark-sm" id="daCapture" disabled>📷 Capture Photo</button>
                        <button type="button" class="btn btn-dark-sm" id="daRetake" hidden>Retake</button>
                    </div>
                    <div class="mini">Photos can only be taken inside this system; picking a file from your gallery is not allowed. A watermark with the location, date/time and a reference code is added automatically.</div>
                </div>

                <div class="field field-full">
                    <label>Photo Georeference (automatic)</label>
                    <div id="daLocStatus" class="da-loc-status">Take a photo and its location is recorded automatically.</div>
                    <div id="daMap" class="da-map" hidden></div>
                    <button type="button" class="btn btn-dark-sm" id="daRelocate" hidden>Retry GPS</button>
                </div>
            </div>

            <div class="actions mt">
                <button class="btn btn-primary">Submit Assessment</button>
            </div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    mdBindSearch('swSearch', 'swTable', 'swNoMatch');
    <?php if ($flash): ?>
    showToast(<?= json_encode($flash[1], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($flash[0]) ?>);
    <?php endif; ?>

    var modal = document.getElementById('damageModal'), form = document.getElementById('damageForm');
    var latEl = document.getElementById('daLat'), lngEl = document.getElementById('daLng');
    var accEl = document.getElementById('daAcc'), srcEl = document.getElementById('daSrc');
    var statusEl = document.getElementById('daLocStatus'), mapEl = document.getElementById('daMap'), relocateBtn = document.getElementById('daRelocate');
    var IDLE_MSG = 'Take a photo and its location is recorded automatically.';
    var map = null, marker = null, circle = null, locating = false;
    var watchId = null, best = null;
    var TARGET_ACC = 20;                          // stop early once we are within 20 m
    var MAX_WAIT   = 25000;                       // otherwise use the best reading after 25 s
    var MAX_OK_ACC = <?= (int) DA_MAX_ACC_M ?>;   // worse than this = low accuracy (set DA_MAX_ACCURACY_M in .env to relax it on a laptop)

    var stampEl = document.getElementById('daStamp'), tsEl = document.getElementById('daTs');
    var capturedAt = null, fixId = 0;

    function fmtTime(d) {
        return d.toLocaleString('en-GB', { timeZone: 'Asia/Manila', day: '2-digit', month: 'short', year: 'numeric',
                                           hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).replace(',', '') + ' PHT';
    }
    // On-screen preview of what gets stamped into the photo (the server burns in the final watermark).
    function renderStamp(lat, lng, acc, addr) {
        stampEl.textContent = '';
        [ 'Date/Time: ' + (capturedAt ? fmtTime(capturedAt) : '—'),
          'Latitude: ' + lat.toFixed(6) + '   Longitude: ' + lng.toFixed(6) + (acc != null ? '  (±' + Math.round(acc) + ' m)' : ''),
          'Address: ' + addr ].forEach(function (t) {
            var line = document.createElement('div'); line.textContent = t; stampEl.appendChild(line);
        });
        stampEl.hidden = false;
    }
    function composeAddress(j) {
        var a = (j && j.address) || {}, parts = [];
        var street = [a.house_number, a.road].filter(Boolean).join(' ');
        [street, a.neighbourhood || a.quarter || a.suburb || a.village, a.city_district, a.city || a.town || a.municipality || a.county].forEach(function (p) {
            if (p && parts.indexOf(p) === -1) parts.push(p);
        });
        if (!parts.length && j && j.display_name) parts = j.display_name.split(',').slice(0, 4).map(function (x) { return x.trim(); });
        return parts.join(', ');
    }
    function lookupAddress(lat, lng, acc, id) {
        fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18&accept-language=en&lat=' + lat + '&lon=' + lng)
            .then(function (r) { return r.json(); })
            .then(function (j) { if (id === fixId) renderStamp(lat, lng, acc, composeAddress(j) || 'unavailable'); })
            .catch(function () { if (id === fixId) renderStamp(lat, lng, acc, 'unavailable'); });
    }

    function clearFix(msg) {
        fixId++;
        latEl.value = ''; lngEl.value = ''; accEl.value = ''; srcEl.value = 'device';
        statusEl.textContent = msg; mapEl.hidden = true; relocateBtn.hidden = true; stampEl.hidden = true;
    }

    // Shows the photo's georeference (read-only: coordinates + a locked map pin).
    function setFix(lat, lng, acc) {
        latEl.value = lat.toFixed(6); lngEl.value = lng.toFixed(6);
        accEl.value = acc == null ? '' : Math.round(acc);
        srcEl.value = 'device';
        statusEl.textContent = '📍 Photo georeferenced: ' + lat.toFixed(6) + ', ' + lng.toFixed(6) +
            (acc != null ? ' (±' + Math.round(acc) + ' m)' : '');
        relocateBtn.hidden = true;
        mapEl.hidden = false;
        if (!map) {
            map = L.map('daMap', { zoomControl: false, dragging: false, scrollWheelZoom: false, doubleClickZoom: false,
                                   boxZoom: false, keyboard: false, touchZoom: false, tap: false });
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
        }
        map.invalidateSize();
        // Accuracy circle: a small circle = good fix, a big one = the pin is only approximate.
        if (circle) { circle.remove(); circle = null; }
        if (acc != null) circle = L.circle([lat, lng], { radius: acc, weight: 1, color: '#5aa6f0', fillOpacity: 0.15 }).addTo(map);
        if (circle && acc > 30) map.fitBounds(circle.getBounds(), { maxZoom: 18 });
        else map.setView([lat, lng], 18);
        setTimeout(function () { map.invalidateSize(); }, 250);   // modal animation can leave Leaflet with the wrong size
        if (!marker) { marker = L.marker([lat, lng], { interactive: false, keyboard: false }).addTo(map); }
        else { marker.setLatLng([lat, lng]); }
        var id = ++fixId;
        renderStamp(lat, lng, acc, 'looking up address…');
        lookupAddress(lat, lng, acc, id);
    }

    function stopWatch() {
        if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
    }

    // Reads the device GPS. Called automatically when the photo is captured. Keeps listening and
    // uses the most accurate reading (the first one the browser returns is often a coarse Wi-Fi/cell guess).
    function locate() {
        if (locating) return;
        if (!navigator.geolocation) { statusEl.textContent = 'This browser does not support GPS location.'; relocateBtn.hidden = false; return; }
        locating = true; best = null;
        statusEl.textContent = 'Getting an accurate GPS fix…';

        var timer = setTimeout(finish, MAX_WAIT);

        function finish() {
            if (!locating) return;
            clearTimeout(timer); stopWatch(); locating = false;
            if (!best) {
                statusEl.textContent = 'Could not get a GPS fix. Move to an open area and tap Retry GPS.';
                relocateBtn.hidden = false;
                return;
            }
            setFix(best.lat, best.lng, best.acc);
            if (best.acc > MAX_OK_ACC) {
                statusEl.textContent += ' — LOW ACCURACY. Step outside and tap Retry GPS.';
                relocateBtn.hidden = false;
            }
        }

        watchId = navigator.geolocation.watchPosition(function (pos) {
            var c = pos.coords;
            if (!best || c.accuracy < best.acc) best = { lat: c.latitude, lng: c.longitude, acc: c.accuracy };
            statusEl.textContent = 'Improving GPS fix… ±' + Math.round(best.acc) + ' m';
            if (best.acc <= TARGET_ACC) finish();
        }, function (err) {
            if (best) { finish(); return; }
            clearTimeout(timer); stopWatch(); locating = false;
            statusEl.textContent = err.code === 1
                ? 'Location permission denied. Allow location access in your browser, then tap Retry GPS.'
                : 'Could not get a GPS fix. Move to an open area and tap Retry GPS.';
            relocateBtn.hidden = false;
        }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    }

    relocateBtn.addEventListener('click', function () { locate(); });

    // ---- In-app camera (no file picker) ----
    var video = document.getElementById('daVideo'), shot = document.getElementById('daShot');
    var camMsg = document.getElementById('daCamMsg'), btnCap = document.getElementById('daCapture'), btnRetake = document.getElementById('daRetake');
    var stream = null, photoBlob = null, sending = false;

    function stopCamera() {
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        video.srcObject = null;
    }
    function startCamera() {
        photoBlob = null; shot.hidden = true; video.hidden = false; btnRetake.hidden = true; btnCap.hidden = false; btnCap.disabled = true;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            camMsg.textContent = 'Camera is not available. Open this page over https in a browser that supports camera access.'; camMsg.hidden = false; return;
        }
        camMsg.textContent = 'Starting camera…'; camMsg.hidden = false;
        navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false })
            .then(function (s) {
                stream = s; video.srcObject = s;
                return video.play();
            })
            .then(function () { camMsg.hidden = true; btnCap.disabled = false; })
            .catch(function (err) {
                camMsg.hidden = false;
                camMsg.textContent = (err && err.name === 'NotAllowedError')
                    ? 'Camera permission denied. Allow camera access in your browser, then tap Retake.'
                    : 'No usable camera was found on this device.';
                btnRetake.hidden = false;
            });
    }

    btnCap.addEventListener('click', function () {
        if (!video.videoWidth) return;
        var scale = Math.min(1, 1600 / Math.max(video.videoWidth, video.videoHeight));
        var c = document.createElement('canvas');
        c.width = Math.round(video.videoWidth * scale); c.height = Math.round(video.videoHeight * scale);
        c.getContext('2d').drawImage(video, 0, 0, c.width, c.height);
        c.toBlob(function (blob) {
            if (!blob) { showToast('Could not capture the photo. Please try again.', 'danger'); return; }
            photoBlob = blob;
            capturedAt = new Date();
            tsEl.value = Math.floor(capturedAt.getTime() / 1000);
            shot.src = URL.createObjectURL(blob);
            shot.hidden = false; video.hidden = true; btnCap.hidden = true; btnRetake.hidden = false;
            stopCamera();
            clearFix('Getting the photo location…');
            locate();   // georeference is read automatically at the moment of capture
        }, 'image/jpeg', 0.9);
    });
    btnRetake.addEventListener('click', function () { stopCamera(); clearFix(IDLE_MSG); startCamera(); });

    // camera runs only while the modal is open
    new MutationObserver(function () {
        if (modal.classList.contains('show')) {
            if (!stream && !photoBlob) startCamera();
            // ask for location permission early so the capture-time fix is fast
            if (navigator.geolocation && !latEl.value) navigator.geolocation.getCurrentPosition(function () {}, function () {}, { maximumAge: 60000 });
        } else { stopCamera(); }
    }).observe(modal, { attributes: true, attributeFilter: ['class'] });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (sending) return;
        if (!photoBlob) { showToast('Take a photo with the camera first.', 'danger'); return; }
        if (!latEl.value || !lngEl.value) { showToast('The photo location is not ready yet. Wait for the georeference to appear, or tap Retry GPS.', 'danger'); return; }
        if (+accEl.value > MAX_OK_ACC) { showToast('GPS accuracy is only ±' + accEl.value + ' m. Move to an open area and tap Retry GPS.', 'danger'); relocateBtn.hidden = false; return; }
        var fd = new FormData(form);
        fd.append('photo', photoBlob, 'capture.jpg');
        sending = true;
        var submitBtn = form.querySelector('.actions .btn-primary');
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Submitting…'; }
        fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function () { window.location.href = 'damage-assessment.php' + location.search; })
            .catch(function () {
                sending = false;
                if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Submit Assessment'; }
                showToast('Network error while submitting. Please try again.', 'danger');
            });
    });
});
</script>

<?php page_end(); ?>
