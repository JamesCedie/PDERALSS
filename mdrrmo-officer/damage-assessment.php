<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/md.php';

require_once '../includes/damage.php';
db_ensure_damage_assessments_table();
db_ensure_disaster_event_status();

$eventId = (int) ($_GET['event_id'] ?? 0);
$event   = md_find_event($eventId);

// (Social Workers no longer have a Damage Assessment page; MD Officers submit and review here.)
// Submit Assessment lives inside a barangay's records: needs a real barangay and an Active disaster event.
$reqBarangay = (string) ($_GET['barangay'] ?? '');
$reqBarangay = in_array($reqBarangay, brgy_names(), true) ? $reqBarangay : '';
$canSubmit   = $reqBarangay !== '' && $event && ($event['status'] ?? 'Active') === 'Active';

// AJAX: search household heads of the open barangay by name
if (isset($_GET['search_household'])) {
    header('Content-Type: application/json');
    $q   = trim($_GET['q'] ?? '');
    $bid = brgy_id($reqBarangay);
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
        if (!$canSubmit) throw new DaException('Assessments can only be submitted for a barangay under an active disaster event.');
        $_POST['event_id'] = $eventId;   // the event being viewed
        $saved = da_submit(
            (string) ($_SESSION['user']['id'] ?? ''),
            (string) ($_SESSION['user']['name'] ?? ''),
            $reqBarangay,
            $_POST,
            $_FILES['photo'] ?? null
        );
        $_SESSION['da_flash'] = ['success', 'Assessment ' . $saved['reference_code'] . ' submitted.'];
    } catch (DaException $e) {
        $_SESSION['da_flash'] = ['danger', $e->getMessage()];
    } catch (Throwable $e) {
        $_SESSION['da_flash'] = ['danger', 'Something went wrong while submitting the assessment.'];
    }
    $qs = array_filter(['event_id' => $eventId ?: null, 'barangay' => $reqBarangay ?: null]);
    header('Location: damage-assessment.php' . ($qs ? '?' . http_build_query($qs) : ''));
    exit;
}

$flash = $_SESSION['da_flash'] ?? null;
unset($_SESSION['da_flash']);

// Assessments (all barangays). Family head comes from the linked household.
$assessments = [];
// Scoped to the selected disaster event; with no event selected, every assessment is listed.
$daRows = db_query(
    'SELECT d.*, b.name AS barangay, h.firstname, h.middlename, h.lastname, h.nameextension
     FROM damage_assessments d
     JOIN household h ON h.household_id = d.household_id
     JOIN barangays b ON b.barangay_id = h.barangay_id
     ' . ($event ? 'WHERE d.event_id = ?' : '') . '
     ORDER BY d.created_at DESC',
    $event ? [$eventId] : []
)->fetchAll();
foreach ($daRows as $r) {
    $head = trim((string) ($r['lastname'] ?? ''));
    if ($head !== '') {
        $given = trim(implode(' ', array_filter([$r['firstname'] ?? '', $r['middlename'] ?? '', $r['nameextension'] ?? ''])));
        $head .= $given !== '' ? ', ' . $given : '';
    } else {
        $head = (string) $r['reporter'];
    }
    try {
        $date = (new DateTimeImmutable($r['created_at']))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d');
    } catch (Throwable $e) {
        $date = substr((string) $r['created_at'], 0, 10);
    }
    $assessments[] = [
        'id'               => (int) $r['assessment_id'],
        'barangay'         => $r['barangay'],
        'family_head_name' => $head,
        'damage_level'     => da_md_level((string) $r['damage_status']),
        'assessed_date'    => $date,
        'notes'            => $r['description'],
        'ref'              => $r['reference_code'],
        'reporter'         => $r['reporter'],
        'lat'              => $r['latitude'] !== null ? (float) $r['latitude'] : null,
        'lng'              => $r['longitude'] !== null ? (float) $r['longitude'] : null,
        'accuracy'         => $r['gps_accuracy_m'] !== null ? (int) round((float) $r['gps_accuracy_m']) : null,
        'source'           => $r['location_source'],
        'address'          => $r['address'] ?? null,
        'flags'            => da_flag_labels($r['location_flags']),
        'has_photo'        => !empty($r['photo_path']),
        'signed'           => da_row_signature_valid($r),
        'reported_level'   => $r['damage_status'],
    ];
}

$perBarangay = [];
$updatedAt   = [];
foreach ($assessments as $a) {
    $perBarangay[$a['barangay']][$a['damage_level']] = ($perBarangay[$a['barangay']][$a['damage_level']] ?? 0) + 1;
    if (empty($updatedAt[$a['barangay']]) || $a['assessed_date'] > $updatedAt[$a['barangay']]) {
        $updatedAt[$a['barangay']] = $a['assessed_date'];
    }
}
$names = md_barangay_names(array_keys($perBarangay));

function md_dmg_counts(array $perLevel): array
{
    $totally   = (int) ($perLevel['Totally Damaged'] ?? 0);
    $partially = (int) ($perLevel['Partially Damaged'] ?? 0);
    return [$totally + $partially, $totally, $partially];
}

$evQuery  = $event ? 'event_id=' . $eventId : '';
$selected = $_GET['barangay'] ?? '';
$selected = in_array($selected, $names, true) ? $selected : null;

require '../includes/layout.php';
if ($selected !== null) {
    page_start('Damage Assessment', null, ['damage-assessment.php' . ($evQuery ? '?' . $evQuery : ''), 'Damage Assessment']);
} else {
    page_start('Damage Assessment', null, ['disasters.php', 'Disaster Events']);
}
?>

<div class="md-page">
<?php if ($selected === null): ?>
    <?php
        $all = [0, 0, 0];
        foreach ($names as $n) {
            foreach (md_dmg_counts($perBarangay[$n] ?? []) as $i => $v) $all[$i] += $v;
        }
    ?>
    <?php if ($event): ?><div class="sw-event-label">Event:<strong><?= md_h($event['event_name']) ?></strong></div><?php endif; ?>
    <div class="sw-casualty-title">Damage Assessment Monitoring</div>
    <div class="md-split">
        <div class="card sw-summary-card">
            <div class="stat-label">Assessments Count</div>
            <div class="stat-value"><?= number_format($all[0]) ?></div>
        </div>
        <div class="card sw-breakdown-card">
            <h3>Assessment Count Breakdown</h3>
            <div class="sw-breakdown md-breakdown-2">
                <div class="sw-breakdown-item fatal"><label>Totally Damaged</label><strong><?= number_format($all[1]) ?></strong></div>
                <div class="sw-breakdown-item injured"><label>Partially Damaged</label><strong><?= number_format($all[2]) ?></strong></div>
            </div>
        </div>
    </div>

    <div class="hh-dir-head">
        <div class="sw-records-title">Barangay Damage Assessment Records</div>
        <span class="hh-count"><?= count($names) ?> Barangay folders</span>
    </div>

    <div class="hh-folders">
        <?php foreach ($names as $n):
            $count = md_dmg_counts($perBarangay[$n] ?? [])[0];
            $meta  = !empty($updatedAt[$n]) ? 'Updated ' . md_date($updatedAt[$n], 'j M Y') : 'No records yet';
        ?>
            <div class="hh-folder">
                <div>
                    <div class="hh-folder-name"><?= md_h($n) ?></div>
                    <div class="hh-folder-meta"><?= md_h($meta) ?></div>
                    <div class="hh-folder-count"><strong><?= $count ?></strong> <span><?= $count === 1 ? 'household' : 'households' ?></span></div>
                </div>
                <a class="btn btn-dark-sm" href="damage-assessment.php?<?= $evQuery ? $evQuery . '&amp;' : '' ?>barangay=<?= urlencode($n) ?>">Open records</a>
            </div>
        <?php endforeach; ?>
    </div>

<?php else: ?>
    <?php
        [$total, $totally, $partially] = md_dmg_counts($perBarangay[$selected] ?? []);
        $records = array_values(array_filter($assessments, fn($a) => $a['barangay'] === $selected));
        usort($records, fn($a, $b) => strcmp($b['assessed_date'], $a['assessed_date']));
    ?>
    <div class="sw-section-head">
        <div class="sw-event-label"><?= $event ? 'Event:' : 'Barangay:' ?><strong><?= md_h($event ? $event['event_name'] . ' · ' . md_short_barangay($selected) : md_short_barangay($selected)) ?></strong></div>
        <?php if ($canSubmit && $selected === $reqBarangay): ?>
            <button type="button" class="btn btn-primary" onclick="openModal('damageModal')">Submit Assessment</button>
        <?php else: ?>
            <button type="button" class="btn btn-primary" disabled title="<?= md_h(!$event ? 'Open this barangay from a disaster event to submit an assessment.' : (brgy_id($selected) <= 0 ? 'This barangay is not registered.' : 'Only an active disaster event accepts new assessments.')) ?>">Submit Assessment</button>
        <?php endif; ?>
    </div>

    <div class="sw-casualty-title">Total Assessments</div>
    <div class="md-split">
        <div class="card sw-summary-card">
            <div class="stat-label">Assessments Count</div>
            <div class="stat-value"><?= number_format($total) ?></div>
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
        <input type="search" id="mdSearch" placeholder="Search..." autocomplete="off">
    </label>

    <div class="sw-data-table">
        <table class="table" id="mdTable">
            <thead>
                <tr><th>Family Head Name</th><th>Damage Level</th><th>Date</th><th class="vr-right">Action</th></tr>
            </thead>
            <tbody>
            <?php if (!$records): ?>
                <tr><td colspan="4" class="empty">No damage assessments recorded for <?= md_h($selected) ?> yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($records as $r): ?>
                <tr data-search="<?= md_h(strtolower($r['family_head_name'] . ' ' . $r['damage_level'])) ?>">
                    <td><strong><?= md_h($r['family_head_name']) ?></strong></td>
                    <td><?= status_badge($r['damage_level']) ?></td>
                    <td><?= md_h(md_date($r['assessed_date'])) ?></td>
                    <td class="vr-right"><button type="button" class="btn btn-dark-sm" onclick="openModal('damageView-<?= (int) $r['id'] ?>')">View</button></td>
                </tr>
            <?php endforeach; ?>
            <tr id="mdNoMatch" hidden><td colspan="4" class="empty">No assessments match your search.</td></tr>
            </tbody>
        </table>
    </div>

    <?php foreach ($records as $r): $mid = 'damageView-' . (int) $r['id']; ?>
        <div id="<?= $mid ?>" class="modal">
            <div class="modal-box md-view-modal">
                <div class="modal-head">
                    <div>
                        <h2>Damage Assessment Details</h2>
                        <div class="mini"><?= md_h($selected) ?> · Record #<?= (int) $r['id'] ?></div>
                    </div>
                    <button type="button" class="icon-btn" onclick="closeModal('<?= $mid ?>')" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
                </div>
                <div class="sw-view-grid">
                    <div class="sw-view-item"><label>Family Head</label><div><?= md_h($r['family_head_name']) ?></div></div>
                    <div class="sw-view-item"><label>Damage Level</label><div><?= status_badge($r['damage_level']) ?></div></div>
                    <div class="sw-view-item"><label>Date Assessed</label><div><?= md_h(md_date($r['assessed_date'])) ?></div></div>
                    <div class="sw-view-item md-view-wide"><label>Notes</label><div><?= $r['notes'] ? md_h($r['notes']) : 'No notes.' ?></div></div>
                    <div class="sw-view-item"><label>Reference</label><div><?= md_h($r['ref']) ?></div></div>
                    <div class="sw-view-item"><label>Reported Damage</label><div><?= md_h($r['reported_level']) ?></div></div>
                    <div class="sw-view-item md-view-wide"><label>Photo Evidence (watermarked)</label>
                        <div>
                            <?php if ($r['has_photo']): ?>
                                <img class="md-dmg-photo" alt="Damage photo for record <?= (int) $r['id'] ?>" data-src="damage-photo.php?id=<?= (int) $r['id'] ?>">
                            <?php else: ?>No photo submitted.<?php endif; ?>
                            <?php if ($r['has_photo']): ?>
                                <div class="mini"><a class="md-dmg-link" target="_blank" rel="noopener" href="damage-photo.php?id=<?= (int) $r['id'] ?>&amp;original=1">View original (unwatermarked)</a></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="sw-view-item md-view-wide"><label>Georeference</label>
                        <div>
                            <?php if ($r['lat'] !== null): ?>
                                <?= md_h(number_format($r['lat'], 6)) ?>, <?= md_h(number_format($r['lng'], 6)) ?>
                                <?= $r['accuracy'] !== null ? '(±' . (int) $r['accuracy'] . ' m)' : '' ?>
                                · device GPS read at photo capture
                                · <a class="md-dmg-link" target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=<?= urlencode((string) $r['lat']) ?>&amp;mlon=<?= urlencode((string) $r['lng']) ?>#map=18/<?= urlencode((string) $r['lat']) ?>/<?= urlencode((string) $r['lng']) ?>">Open in OpenStreetMap</a>
                                <?php if (!empty($r['address'])): ?><div class="mini"><?= md_h($r['address']) ?></div><?php endif; ?>
                                <div class="md-dmg-map" data-lat="<?= md_h($r['lat']) ?>" data-lng="<?= md_h($r['lng']) ?>" data-acc="<?= md_h($r['accuracy'] ?? '') ?>"></div>
                            <?php else: ?>No location recorded.<?php endif; ?>
                        </div>
                    </div>
                    <div class="sw-view-item md-view-wide"><label>Integrity Check</label>
                        <div>
                            <span class="badge <?= $r['signed'] ? 'b-green' : 'b-red' ?>"><?= $r['signed'] ? 'Signature valid' : 'Signature mismatch' ?></span>
                            <?php foreach ($r['flags'] as $fl): ?><span class="badge b-yellow"><?= md_h($fl) ?></span> <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="actions">
                    <button type="button" class="btn btn-primary" onclick="closeModal('<?= $mid ?>')">Close</button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($canSubmit && $selected === $reqBarangay): ?>
    <div id="damageModal" class="modal">
        <div class="modal-box">
            <div class="modal-head">
                <h2>Submit Damage Assessment</h2>
                <button class="icon-btn" onclick="closeModal('damageModal')" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
            </div>

            <form id="damageForm" method="post" enctype="multipart/form-data">
                <?= da_csrf_field() ?>
                <input type="hidden" name="submit_assessment" value="1">
                <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
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
    <?php endif; ?>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        mdBindSearch('mdSearch', 'mdTable', 'mdNoMatch');
        <?php if ($flash): ?>
        showToast(<?= json_encode($flash[1], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($flash[0]) ?>);
        <?php endif; ?>

        <?php if ($canSubmit && $selected === $reqBarangay): ?>
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
                fetch('damage-assessment.php?search_household=1&barangay=' + encodeURIComponent(<?= json_encode($selected) ?>) + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
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
            fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'manual' })
                .then(function () { window.location.href = 'damage-assessment.php' + location.search; })
                .catch(function () {
                    sending = false;
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Submit Assessment'; }
                    showToast('Network error while submitting. Please try again.', 'danger');
                });
        });
        <?php endif; ?>

        // Load each record's photo and mini-map only when its modal is opened.
        document.querySelectorAll('.modal[id^="damageView-"]').forEach(function (modal) {
            var done = false;
            new MutationObserver(function () {
                if (!modal.classList.contains('show')) return;
                var img = modal.querySelector('.md-dmg-photo');
                if (img && !img.getAttribute('src')) img.src = img.dataset.src;
                var el = modal.querySelector('.md-dmg-map');
                if (!el) return;
                if (done) { el._map.invalidateSize(); return; }
                done = true;
                var pos = [+el.dataset.lat, +el.dataset.lng];
                var m = L.map(el, { scrollWheelZoom: false }).setView(pos, 17);
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(m);
                L.marker(pos).addTo(m);
                if (el.dataset.acc) L.circle(pos, { radius: +el.dataset.acc, weight: 1, color: '#5aa6f0', fillOpacity: 0.15 }).addTo(m);
                el._map = m;
            }).observe(modal, { attributes: true, attributeFilter: ['class'] });
        });
    });
    </script>
<?php endif; ?>
</div>

<?php page_end(); ?>
