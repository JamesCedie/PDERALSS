<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/damage.php';
require_once '../includes/md.php';
db_ensure_damage_assessments_table();
db_ensure_disaster_event_status();

$currentUser = db_user_with_barangay($_SESSION['user']['id'] ?? null);
$swBarangay  = $currentUser['address'] ?? '';

// AJAX: search household heads of this Social Worker's barangay by name
if (isset($_GET['search_household'])) {
    header('Content-Type: application/json');
    $q   = trim($_GET['q'] ?? '');
    $bid = (int) ($currentUser['barangay_id'] ?? 0);
    if (strlen($q) < 2 || !$bid) { echo json_encode([]); exit; }
    $like = '%' . preg_replace('/\s+/', ' ', $q) . '%';
    $out  = [];
    foreach (db_query(
        "SELECT household_id, firstname, middlename, lastname, nameextension FROM household
         WHERE barangay_id = ? AND concat_ws(' ', firstname, middlename, lastname, nameextension) ILIKE ?
         ORDER BY lastname ASC, firstname ASC LIMIT 10", [$bid, $like])->fetchAll() as $r) {
        $out[] = ['id' => (int) $r['household_id'],
                  'name' => preg_replace('/\s+/', ' ', trim(implode(' ', array_filter([$r['firstname'], $r['middlename'], $r['lastname'], $r['nameextension']]))))];
    }
    echo json_encode($out);
    exit;
}

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
        $_SESSION['da_flash'] = ['success', 'Assessment ' . $saved['reference_code'] . ' submitted.'];
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
     WHERE h.barangay_id = ? AND d.event_id = ?
     ORDER BY d.created_at DESC',
    [(int) ($currentUser['barangay_id'] ?? 0), $activeEvent ? (int) $activeEvent['event_id'] : 0]
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
        'reporter' => $r['reporter'],
        'notes'    => $r['description'],
        'lat'      => $r['latitude'] !== null ? (float) $r['latitude'] : null,
        'lng'      => $r['longitude'] !== null ? (float) $r['longitude'] : null,
        'accuracy' => $r['gps_accuracy_m'] !== null ? (int) round((float) $r['gps_accuracy_m']) : null,
        'address'  => $r['address'] ?? null,
        'flags'    => da_flag_labels($r['location_flags']),
    ];
}
$totalAssessments = count($records);

?>

<div class="md-page">
<div class="sw-section-head">
    <div class="sw-event-label">Event:<strong><?= md_h($activeEvent ? $activeEvent['event_name'] . ' · ' . md_short_barangay((string) $swBarangay) : 'No active event') ?></strong></div>
    <button class="btn btn-primary" onclick="openModal('damageModal')">Submit Assessment</button>
</div>

<div class="sw-casualty-title">Total Assessments Submitted</div>
<div class="md-split">
    <div class="card sw-summary-card">
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
    <span aria-hidden="true"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
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
                <td><?= status_badge($r['level']) ?></td>
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
                <button type="button" class="icon-btn" onclick="closeModal('<?= $mid ?>')" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
            </div>
            <div class="sw-view-grid">
                <div class="sw-view-item"><label>Household Head</label><div><?= md_h($r['head']) ?></div></div>
                <div class="sw-view-item"><label>Damage Level</label><div><?= status_badge($r['level']) ?></div></div>
                <div class="sw-view-item"><label>Date Assessed</label><div><?= md_h($r['date']) ?></div></div>
                <div class="sw-view-item"><label>Reported Damage</label><div><?= md_h($r['reported']) ?></div></div>
                <div class="sw-view-item"><label>Reporter</label><div><?= md_h($r['reporter']) ?></div></div>
                <div class="sw-view-item" style="grid-column:1 / -1"><label>Description</label><div><?= $r['notes'] ? md_h($r['notes']) : 'No description.' ?></div></div>
                <div class="sw-view-item" style="grid-column:1 / -1"><label>Georeference</label>
                    <div>
                        <?php if ($r['lat'] !== null): ?>
                            <?= md_h(number_format($r['lat'], 6)) ?>, <?= md_h(number_format($r['lng'], 6)) ?>
                            <?= $r['accuracy'] !== null ? '(±' . (int) $r['accuracy'] . ' m)' : '' ?>
                            <?php if (!empty($r['address'])): ?><div class="mini"><?= md_h($r['address']) ?></div><?php endif; ?>
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
            <button class="icon-btn" onclick="closeModal('damageModal')" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>

        <form id="damageForm" method="post" enctype="multipart/form-data">
            <?= da_csrf_field() ?>
            <input type="hidden" name="submit_assessment" value="1">
            <input type="hidden" name="event_id" value="<?= $activeEvent ? (int) $activeEvent['event_id'] : 0 ?>">
            <input type="hidden" name="latitude" id="daLat">
            <input type="hidden" name="longitude" id="daLng">
            <input type="hidden" name="gps_accuracy" id="daAcc">
            <input type="hidden" name="location_source" id="daSrc" value="device">
            <input type="hidden" name="captured_ts" id="daTs">
            <div class="form-grid">
                <div class="field field-full">
                    <label>Household (Family Head)</label>
                    <input type="hidden" name="household_id" id="daHouseholdId" value="">
                    <div id="daHhPicked" class="sw-search-modal-input" hidden>
                        <input type="text" id="daHhName" readonly>
                        <button type="button" class="btn btn-light" id="daHhChange">Change</button>
                    </div>
                    <div id="daHhSearch">
                        <div class="sw-search-modal-input">
                            <input type="text" id="daHhInput" placeholder="Search family head..." autocomplete="off">
                            <span aria-hidden="true"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
                        </div>
                        <div id="daHhResults" class="sw-resident-results"></div>
                        <div id="daHhNone" class="sw-no-results" style="display:none;">No results found.</div>
                    </div>
                </div>

                <div class="field">
                    <label>Damage Status</label>
                    <select name="damage_status">
                        <option>Fully Damaged</option>
                        <option>Partially Damaged</option>
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
                        <button type="button" class="btn btn-dark-sm" id="daCapture" disabled>Capture Photo</button>
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

    // Household (family head) search
    var hhId = document.getElementById('daHouseholdId'), hhInput = document.getElementById('daHhInput');
    var hhResults = document.getElementById('daHhResults'), hhNone = document.getElementById('daHhNone');
    var hhPicked = document.getElementById('daHhPicked'), hhName = document.getElementById('daHhName'), hhSearch = document.getElementById('daHhSearch');
    var hhTimer = null;
    function resetHousehold() {
        hhId.value = ''; hhInput.value = ''; hhResults.innerHTML = ''; hhNone.style.display = 'none';
        hhPicked.hidden = true; hhSearch.hidden = false;
    }
    hhInput.addEventListener('input', function () {
        clearTimeout(hhTimer);
        var q = hhInput.value.trim();
        hhResults.innerHTML = ''; hhNone.style.display = 'none';
        if (q.length < 2) return;
        hhTimer = setTimeout(function () {
            fetch('damage-assessment.php?search_household=1&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.length) { hhNone.style.display = 'block'; return; }
                    data.forEach(function (d) {
                        var b = document.createElement('button');
                        b.type = 'button'; b.className = 'sw-resident-result'; b.textContent = d.name;
                        b.addEventListener('click', function () {
                            hhId.value = d.id; hhName.value = d.name;
                            hhPicked.hidden = false; hhSearch.hidden = true;
                            hhResults.innerHTML = '';
                        });
                        hhResults.appendChild(b);
                    });
                })
                .catch(function () { hhNone.style.display = 'block'; });
        }, 300);
    });
    document.getElementById('daHhChange').addEventListener('click', function () { resetHousehold(); hhInput.focus(); });

    var latEl = document.getElementById('daLat'), lngEl = document.getElementById('daLng');
    var accEl = document.getElementById('daAcc'), srcEl = document.getElementById('daSrc');
    var statusEl = document.getElementById('daLocStatus'), mapEl = document.getElementById('daMap'), relocateBtn = document.getElementById('daRelocate');
    var IDLE_MSG = 'Take a photo and its location is recorded automatically.';
    var map = null, marker = null, circle = null, locating = false;
    var watchId = null, samples = [], waitTimer = null;
    var TARGET_ACC = 15;                          // stop early once we are within 15 m
    var WINDOW_MS  = 20000;                       // only readings from the last 20 s are used (older ones are stale)
    var MAX_WAIT   = 40000;                       // otherwise use the best estimate after 40 s
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
        var street = [a.house_number, a.road].filter(Boolean).join(' ').trim();
        var fields = [
            ['Address Line 1', street],
            ['Locality / City', a.city || a.town || a.municipality || a.village || ''],
            ['Region / State', a.state || a.province || a.state_district || ''],
            ['Postal Code', a.postcode || ''],
            ['Country', a.country || '']
        ];
        fields.forEach(function (field) {
            var value = String(field[1] || '').trim();
            if (value) parts.push(field[0] + ': ' + value);
        });
        return parts.join(' | ');
    }
    function lookupAddress(lat, lng, acc, id) {
        fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18&accept-language=en&lat=' + lat + '&lon=' + lng)
            .then(function (r) { return r.json(); })
            .then(function (j) { if (id === fixId) renderStamp(lat, lng, acc, composeAddress(j) || 'unavailable'); })
            .catch(function () { if (id === fixId) renderStamp(lat, lng, acc, 'unavailable'); });
    }

    function clearFix(msg) {
        fixId++; locating = false; clearTimeout(waitTimer);
        latEl.value = ''; lngEl.value = ''; accEl.value = ''; srcEl.value = 'device';
        statusEl.textContent = msg; mapEl.hidden = true; relocateBtn.hidden = true; stampEl.hidden = true;
    }

    // Shows the photo's georeference (read-only: coordinates + a locked map pin).
    function setFix(lat, lng, acc) {
        latEl.value = lat.toFixed(6); lngEl.value = lng.toFixed(6);
        accEl.value = acc == null ? '' : Math.round(acc);
        srcEl.value = 'device';
        statusEl.textContent = 'Photo georeferenced: ' + lat.toFixed(6) + ', ' + lng.toFixed(6) +
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

    // GPS
    // The GPS is switched on as soon as the modal opens (a cold GPS chip needs 10-30 s to settle) and keeps
    // running, so the fix is already good when the photo is taken. Several recent readings are combined:
    // coarse ones (Wi-Fi/cell guesses) are ignored and the accurate ones are averaged, weighted by accuracy.
    function estimate() {
        var cut = Date.now() - WINDOW_MS;
        samples = samples.filter(function (s) { return s.t >= cut; });
        if (!samples.length) return null;
        var minAcc = Math.min.apply(null, samples.map(function (s) { return s.acc; }));
        var sw = 0, la = 0, lo = 0;
        samples.forEach(function (s) {
            if (s.acc > minAcc * 1.5 + 5) return;
            var w = 1 / Math.max(s.acc * s.acc, 1);
            sw += w; la += s.lat * w; lo += s.lng * w;
        });
        return { lat: la / sw, lng: lo / sw, acc: minAcc };
    }

    function onPos(pos) {
        var c = pos.coords;
        if (!isFinite(c.accuracy) || c.accuracy <= 0) return;
        if (pos.timestamp && pos.timestamp < Date.now() - 15000) return;   // cached position from the browser, not a live reading
        samples.push({ lat: c.latitude, lng: c.longitude, acc: c.accuracy, t: Date.now() });
        var e = estimate();
        if (!e) return;
        if (locating) {
            statusEl.textContent = 'Improving GPS fix… ±' + Math.round(e.acc) + ' m';
            if (e.acc <= TARGET_ACC) finish();
        } else if (!photoBlob) {
            statusEl.textContent = 'GPS ready: ±' + Math.round(e.acc) + ' m' + (e.acc > MAX_OK_ACC ? ' — weak signal, move to an open area' : '');
        }
    }
    function onPosError(err) {
        if (err.code === 1) {   // permission denied
            stopWatch();
            clearTimeout(waitTimer); locating = false;
            statusEl.textContent = 'Location permission denied. Allow location access in your browser, then tap Retry GPS.';
            relocateBtn.hidden = false;
        }
        // timeout / position unavailable: keep listening; finish() reports it if nothing arrives in time
    }
    function startWatch() {
        if (watchId !== null || !navigator.geolocation) return;
        watchId = navigator.geolocation.watchPosition(onPos, onPosError, { enableHighAccuracy: true, timeout: 30000, maximumAge: 0 });
    }
    function stopWatch() {
        if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
        samples = [];
    }

    function finish() {
        if (!locating) return;
        clearTimeout(waitTimer); locating = false;
        var e = estimate();
        if (!e) {
            statusEl.textContent = 'Could not get a GPS fix. Move to an open area and tap Retry GPS.';
            relocateBtn.hidden = false;
            return;
        }
        setFix(e.lat, e.lng, e.acc);
        if (e.acc > MAX_OK_ACC) {
            statusEl.textContent += ' — LOW ACCURACY. Step outside and tap Retry GPS.';
            relocateBtn.hidden = false;
        }
    }

    // Called when the photo is captured: uses the GPS estimate right away if it is already good,
    // otherwise keeps improving it for up to MAX_WAIT.
    function locate() {
        if (locating) return;
        if (!navigator.geolocation) { statusEl.textContent = 'This browser does not support GPS location.'; relocateBtn.hidden = false; return; }
        locating = true;
        startWatch();
        statusEl.textContent = 'Getting an accurate GPS fix…';
        waitTimer = setTimeout(finish, MAX_WAIT);
        var e = estimate();
        if (e && e.acc <= TARGET_ACC) finish();
    }

    relocateBtn.addEventListener('click', function () { stopWatch(); locate(); });   // restart the GPS from scratch

    // In-app camera (no file picker)
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
            startWatch();   // warm up the GPS while the camera is open so the capture-time fix is accurate
        } else { stopCamera(); stopWatch(); }
    }).observe(modal, { attributes: true, attributeFilter: ['class'] });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (sending) return;
        if (!hhId.value) { showToast('Search and select the household (family head) first.', 'danger'); hhInput.focus(); return; }
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
