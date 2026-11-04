<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';
require_once '../includes/md.php';
db_ensure_disaster_event_status();
db_ensure_casualties_table();

$eventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$currentUser = db_user_with_barangay($_SESSION['user']['id'] ?? null);
$socialWorkerAddress = trim($currentUser['address'] ?? '');
$currentEvent = $eventId ? db_select_one('disaster_events', 'event_id = ?', [$eventId]) : null;
$successMsg = $_SESSION['casualty_success'] ?? null;
$errorMsg = null;
unset($_SESSION['casualty_success']);

$myBarangayId = (int) ($currentUser['barangay_id'] ?? 0);
$joinName = static fn(array $parts): string => preg_replace('/\s+/', ' ', trim(implode(' ', array_filter($parts))));
$sexOf    = static fn($v): string => match (strtolower(trim((string) $v))) { 'male', 'm' => 'Male', 'female', 'f' => 'Female', default => '' };

// AJAX: search registered residents (household heads + family members) of the Social Worker's barangay
if (isset($_GET['search_resident'])) {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2 || !$myBarangayId) { echo json_encode([]); exit; }
    $like = '%' . preg_replace('/\s+/', ' ', $q) . '%';

    // Names already recorded as casualties for this event (so the same person is not added twice)
    $recorded = [];
    if ($eventId) {
        foreach (db_query('SELECT name FROM public.casualties WHERE event_id = ? AND barangay_id = ?', [$eventId, $myBarangayId])->fetchAll() as $x) {
            $recorded[mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $x['name'])))] = true;
        }
    }
    $out = [];
    $heads = db_query(
        "SELECT household_id, firstname, middlename, lastname, nameextension, age, sex, address
         FROM household
         WHERE barangay_id = ? AND concat_ws(' ', firstname, middlename, lastname, nameextension) ILIKE ?
         ORDER BY lastname ASC LIMIT 10", [$myBarangayId, $like])->fetchAll();
    foreach ($heads as $r) {
        $nm = $joinName([$r['firstname'], $r['middlename'], $r['lastname'], $r['nameextension']]);
        $out[] = ['type' => 'head', 'id' => (int) $r['household_id'], 'composition_id' => 0, 'name' => $nm, 'head' => '',
                  'first' => (string) $r['firstname'], 'mi' => mb_strtoupper(mb_substr((string) $r['middlename'], 0, 1)),
                  'last' => $joinName([$r['lastname'], $r['nameextension']]),
                  'age' => $r['age'] !== null ? (int) $r['age'] : null, 'sex' => $sexOf($r['sex']), 'address' => trim((string) $r['address']),
                  'done' => isset($recorded[mb_strtolower($nm)])];
    }
    $members = db_query(
        "SELECT f.composition_id, f.household_id, f.first_name, f.middle_name, f.last_name, f.relationship, f.age, f.sex, h.address,
                h.firstname, h.middlename, h.lastname, h.nameextension
         FROM family_composition f JOIN household h ON h.household_id = f.household_id
         WHERE h.barangay_id = ? AND concat_ws(' ', f.first_name, f.middle_name, f.last_name) ILIKE ?
         ORDER BY f.last_name ASC LIMIT 10", [$myBarangayId, $like])->fetchAll();
    foreach ($members as $r) {
        $nm = $joinName([$r['first_name'], $r['middle_name'], $r['last_name']]);
        $out[] = ['type' => 'member', 'id' => (int) $r['household_id'], 'composition_id' => (int) $r['composition_id'], 'name' => $nm,
                  'head' => $joinName([$r['firstname'], $r['middlename'], $r['lastname'], $r['nameextension']]),
                  'relationship' => (string) ($r['relationship'] ?? ''),
                  'first' => (string) $r['first_name'], 'mi' => mb_strtoupper(mb_substr((string) $r['middle_name'], 0, 1)), 'last' => (string) $r['last_name'],
                  'age' => $r['age'] !== null ? (int) $r['age'] : null, 'sex' => $sexOf($r['sex']), 'address' => trim((string) $r['address']),
                  'done' => isset($recorded[mb_strtolower($nm)])];
    }
    echo json_encode($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_casualty') {
    $type          = trim($_POST['type'] ?? '');
    $date          = trim($_POST['date'] ?? '');
    $householdId   = (int) ($_POST['household_id'] ?? 0);
    $compositionId = (int) ($_POST['composition_id'] ?? 0);
    // The form only asks for Type and Date; the database still requires a severity, so a neutral default is stored.
    $severity      = 'Minor';
    $allowedTypes  = ['Fatality', 'Missing', 'Injured'];

    // DROMIC Form 3 fields (typed in for people who are not in the household registry)
    $first  = trim((string) ($_POST['first_name'] ?? ''));
    $last   = trim((string) ($_POST['last_name'] ?? ''));
    $mi     = mb_strtoupper(mb_substr(trim((string) ($_POST['middle_initial'] ?? '')), 0, 1));
    $rawAge = trim((string) ($_POST['age'] ?? ''));
    $age    = (ctype_digit($rawAge) && (int) $rawAge <= 120) ? (int) $rawAge : null;
    $sex    = $sexOf($_POST['sex'] ?? '');
    $addr   = mb_substr(trim((string) ($_POST['address'] ?? '')), 0, 150);
    $cause  = mb_substr(trim((string) ($_POST['cause'] ?? '')), 0, 150);
    $notes  = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 255);   // printed as "Remarks"
    $name   = '';
    $r      = null;

    // A registered resident: trust the database (not the form) for the name, age, sex and address.
    if ($householdId) {
        if ($compositionId) {
            $r = db_query('SELECT f.first_name, f.middle_name, f.last_name, f.age, f.sex, h.address
                           FROM family_composition f JOIN household h ON h.household_id = f.household_id
                           WHERE f.composition_id = ? AND f.household_id = ? AND h.barangay_id = ?', [$compositionId, $householdId, $myBarangayId])->fetch();
            if ($r) {
                $name  = $joinName([$r['first_name'], $r['middle_name'], $r['last_name']]);
                $first = (string) $r['first_name']; $last = (string) $r['last_name']; $mi = mb_strtoupper(mb_substr((string) $r['middle_name'], 0, 1));
            }
        } else {
            $r = db_query('SELECT firstname, middlename, lastname, nameextension, age, sex, address FROM household WHERE household_id = ? AND barangay_id = ?', [$householdId, $myBarangayId])->fetch();
            if ($r) {
                $name  = $joinName([$r['firstname'], $r['middlename'], $r['lastname'], $r['nameextension']]);
                $first = (string) $r['firstname']; $last = $joinName([$r['lastname'], $r['nameextension']]); $mi = mb_strtoupper(mb_substr((string) $r['middlename'], 0, 1));
            }
        }
        if ($r) {   // database values win; the typed values are only used when the record has none
            if ($r['age'] !== null) $age = (int) $r['age'];
            if ($sexOf($r['sex']) !== '') $sex = $sexOf($r['sex']);
            if (trim((string) $r['address']) !== '') $addr = trim((string) $r['address']);
        }
    } else {
        $name = $joinName([$first, $mi !== '' ? $mi . '.' : '', $last]);
    }

    if (!$currentEvent) {
        $errorMsg = 'The selected disaster event could not be found.';
    } elseif (($currentEvent['status'] ?? 'Active') !== 'Active') {
        $errorMsg = 'This disaster event is already marked as Passed and can no longer receive new casualty records.';
    } elseif (!$socialWorkerAddress) {
        $errorMsg = 'Your account has no barangay/address assigned. Please contact an MDRRMO Officer.';
    } elseif ($name === '' || ($householdId === 0 && ($first === '' || $last === '')) || !in_array($type, $allowedTypes, true) || $date === '') {
        $errorMsg = 'Please select a resident (or enter the first and last name), then choose a Type and Date.';
    } elseif ($age === null || $sex === '') {
        $errorMsg = 'Age and sex are required for the casualty masterlist (DROMIC Form 3).';
    } elseif ($type !== 'Missing' && $cause === '') {
        $errorMsg = 'Please enter the cause of ' . ($type === 'Fatality' ? 'death.' : 'injury.');
    } elseif ($date > today_ph()) {
        $errorMsg = 'The date cannot be later than today.';
    } else {
        try {
            db_insert('casualties', [
                'event_id'       => $eventId,
                'barangay_id'    => $myBarangayId,
                'name'           => $name,
                'type'           => $type,
                'severity'       => $severity,
                'date'           => $date,
                'household_id'   => $householdId ?: null,
                'composition_id' => ($householdId && $compositionId) ? $compositionId : null,
                'last_name'      => $last,
                'first_name'     => $first,
                'middle_initial' => $mi ?: null,
                'age'            => $age,
                'sex'            => $sex,
                'address'        => $addr ?: null,
                'cause'          => $cause ?: null,
                'notes'          => $notes ?: null,
                'added_by'       => $_SESSION['user']['id'] ?? null,
            ]);
            $_SESSION['casualty_success'] = 'Casualty record saved successfully.';
            header('Location: casualties.php?event_id=' . urlencode((string)$eventId));
            exit;
        } catch (Throwable $e) {
            error_log('PDERALSS casualty save failed: ' . $e->getMessage());
            $errorMsg = 'The casualty record could not be saved. Please try again.';
        }
    }
}

$rows = $eventId && $socialWorkerAddress
    ? db_query(
        'SELECT casualty_id, name, type, severity, date, notes, age, sex, cause FROM public.casualties WHERE event_id = ? AND barangay_id = ? ORDER BY date DESC, casualty_id DESC',
        [$eventId, (int) $currentUser['barangay_id']]
      )->fetchAll()
    : [];

$total = count($rows);
$fatalities = 0;
$missing = 0;
$injured = 0;
foreach ($rows as $row) {
    if ($row['type'] === 'Fatality') $fatalities++;
    elseif ($row['type'] === 'Missing') $missing++;
    elseif ($row['type'] === 'Injured') $injured++;
}

require '../includes/layout.php';
page_start('Casualty Recording');
?>

<div class="md-page">
<div class="sw-section-head">
    <div class="sw-event-label">
        Event:<strong><?= md_h(($currentEvent['event_name'] ?? 'No event selected') . ($currentEvent ? ' · ' . ($socialWorkerAddress ? md_short_barangay($socialWorkerAddress) : 'No barangay assigned') : '')) ?></strong>
    </div>
    <?php if ($currentEvent && ($currentEvent['status'] ?? 'Active') === 'Active'): ?>
        <button class="btn btn-primary" onclick="openModal('casualtyModal')">Add Casualty</button>
    <?php endif; ?>
</div>

<?php if ($successMsg): ?>
    <div class="sw-inline-alert sw-inline-success"><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
    <div class="sw-inline-alert sw-inline-error"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<div class="sw-casualty-title">Total Casualties Recorded</div>
<div class="md-split">
    <div class="card sw-summary-card">
        <div class="stat-label">Casualty Count</div>
        <div class="stat-value"><?= $total ?></div>
    </div>
    <div class="card sw-breakdown-card">
        <h3>Casualty Count Breakdown</h3>
        <div class="sw-breakdown">
            <div class="sw-breakdown-item fatal"><label>Fatalities</label><strong><?= $fatalities ?></strong></div>
            <div class="sw-breakdown-item missing"><label>Missing</label><strong><?= $missing ?></strong></div>
            <div class="sw-breakdown-item injured"><label>Injured</label><strong><?= $injured ?></strong></div>
        </div>
    </div>
</div>

<div class="sw-casualty-title mt">Casualty Records</div>
<label class="sw-search">
    <span aria-hidden="true"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
    <input type="search" id="swSearch" placeholder="Search..." autocomplete="off">
</label>

<div class="sw-data-table">
    <table class="table" id="swTable">
        <thead><tr><th>Name</th><th>Type</th><th>Age</th><th>Sex</th><th>Cause</th><th>Date</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="empty">No casualties recorded yet.</td></tr>
        <?php else: foreach ($rows as $row): ?>
            <tr data-search="<?= md_h(strtolower($row['name'] . ' ' . $row['type'] . ' ' . $row['date'])) ?>">
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= status_badge($row['type']) ?></td>
                <td><?= htmlspecialchars((string) ($row['age'] ?? '—')) ?></td>
                <td><?= htmlspecialchars((string) ($row['sex'] ?: '—')) ?></td>
                <td><?= htmlspecialchars((string) ($row['cause'] ?: '—')) ?></td>
                <td><?= htmlspecialchars($row['date']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        <tr id="swNoMatch" hidden><td colspan="6" class="empty">No casualties match your search.</td></tr>
        </tbody>
    </table>
</div>
</div>
<script>document.addEventListener('DOMContentLoaded', function () { mdBindSearch('swSearch', 'swTable', 'swNoMatch'); });</script>

<div id="casualtyModal" class="modal">
    <div class="modal-box sw-search-modal">
        <div class="modal-head">
            <h2 id="casModalTitle">Search Resident Name</h2>
            <button type="button" class="icon-btn" onclick="closeCasualtyModal()" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>

        <div id="casSearchPhase">
            <div class="sw-search-modal-input">
                <input type="text" id="casResidentSearch" placeholder="Search resident..." oninput="searchCasualtyResident(this.value)" autocomplete="off">
                <span aria-hidden="true"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
            </div>
            <div id="casSearchResults" class="sw-resident-results"></div>
            <div id="casNoResults" class="sw-no-results" style="display:none;">
                <div>No results found.</div>
                <button type="button" onclick="showCasualtyForm(null)" class="btn btn-primary">Add New Casualty Record</button>
            </div>
        </div>

        <div id="casFormPhase" style="display:none;">
            <form method="post" action="casualties.php?event_id=<?= urlencode((string)$eventId) ?>" id="casualtyForm">
                <input type="hidden" name="action" value="add_casualty">
                <input type="hidden" name="event_id" value="<?= htmlspecialchars((string)$eventId) ?>">
                <input type="hidden" name="household_id" id="casHouseholdId" value="">
                <input type="hidden" name="composition_id" id="casCompositionId" value="">
                                    <div class="form-grid mb">
                    <div class="field"><label>Last Name</label><input name="last_name" id="casLast" required></div>
                    <div class="field"><label>First Name</label><input name="first_name" id="casFirst" required></div>
                    <div class="field"><label>MI</label><input name="middle_initial" id="casMi" maxlength="1"></div>
                    <div class="field"><label>Age</label><input type="number" name="age" id="casAge" min="0" max="120" step="1" inputmode="numeric" required></div>
                    <div class="field"><label>Sex</label><select name="sex" id="casSex" required><option value="" selected disabled>Select sex</option><option value="Male">Male</option><option value="Female">Female</option></select></div>
                    <div class="field"><label>Address (Sitio/Purok/Zone/Street)</label><input name="address" id="casAddr" maxlength="150"></div>
                </div>
                <div class="mini mb" id="casFamilyNote" style="display:none;"></div>
                <div class="field mb"><label>Type</label><select name="type" required><option value="" selected disabled>Select type</option><option value="Injured">Injured</option><option value="Missing">Missing</option><option value="Fatality">Fatality</option></select></div>
                    <div class="field mb"><label>Date</label><input name="date" type="date" required max="<?= htmlspecialchars(today_ph()) ?>" value="<?= htmlspecialchars(date('Y-m-d')) ?>"></div>
                <div class="field mb"><label>Cause of Death / Injury</label><input name="cause" id="casCause" maxlength="150" placeholder="e.g. Drowning, fallen debris (optional for Missing)"></div>
                <div class="field mb"><label>Remarks</label><input name="notes" id="casNotes" maxlength="255" placeholder="Optional"></div>
                <div class="actions"><button type="button" class="btn btn-light" onclick="backToCasualtySearch()">Back</button><button type="submit" class="btn btn-primary">Save</button></div>
            </form>
        </div>
    </div>
</div>

<script>
function closeCasualtyModal() { closeModal('casualtyModal'); resetCasualtyModal(); }
function resetCasualtyModal() {
    document.getElementById('casResidentSearch').value = '';
    document.getElementById('casSearchResults').innerHTML = '';
    document.getElementById('casNoResults').style.display = 'none';
    document.getElementById('casSearchPhase').style.display = 'block';
    document.getElementById('casFormPhase').style.display = 'none';
    document.getElementById('casModalTitle').textContent = 'Search Resident Name';
    setCasualtyPerson(null);
    var f = document.getElementById('casualtyForm');
    f.querySelector('[name=type]').selectedIndex = 0;
    f.querySelector('[name=date]').value = '<?= date('Y-m-d') ?>';
}
function setCasualtyPerson(d) {
    var note = document.getElementById('casFamilyNote');
    document.getElementById('casHouseholdId').value = d ? d.id : '';
    document.getElementById('casCompositionId').value = (d && d.type === 'member') ? d.composition_id : '';
    // A registered resident's details come from the record; empty ones stay editable.
    function put(id, val, lock) { var el = document.getElementById(id); el.value = (val === null || val === undefined) ? '' : val; el.readOnly = !!lock; }
    put('casFirst', d ? d.first : '', !!d);
    put('casMi',    d ? d.mi : '',    !!d);
    put('casLast',  d ? d.last : '',  !!d);
    put('casAge',   d ? d.age : '',   !!(d && d.age !== null));
    put('casAddr',  d ? d.address : '', !!(d && d.address));
    var sx = document.getElementById('casSex');
    sx.value = (d && d.sex) ? d.sex : '';
    sx.disabled = !!(d && d.sex);            // the server uses the saved value
    document.getElementById('casCause').value = '';
    document.getElementById('casNotes').value = '';
    note.style.display = (d && d.type === 'member') ? 'block' : 'none';
    note.textContent = (d && d.type === 'member') ? 'Family member of ' + d.head + (d.relationship ? ' (' + d.relationship + ')' : '') : '';
}
function showCasualtyForm(d) {
    setCasualtyPerson(d);
    if (!d) {   // not in the registry: split what was typed in the search box into first / last name
        var parts = document.getElementById('casResidentSearch').value.trim().split(/\s+/).filter(Boolean);
        document.getElementById('casLast').value  = parts.length > 1 ? parts.pop() : '';
        document.getElementById('casFirst').value = parts.join(' ');
    }
    document.getElementById('casSearchPhase').style.display = 'none';
    document.getElementById('casFormPhase').style.display = 'block';
    document.getElementById('casModalTitle').textContent = 'Add Casualty';
    var first = document.querySelector('#casualtyForm [name=type]'); if (first) first.focus();
}
function backToCasualtySearch() {
    setCasualtyPerson(null);
    document.getElementById('casFormPhase').style.display = 'none';
    document.getElementById('casSearchPhase').style.display = 'block';
    document.getElementById('casModalTitle').textContent = 'Search Resident Name';
}
var casSearchTimeout;
function searchCasualtyResident(q) {
    clearTimeout(casSearchTimeout);
    var results = document.getElementById('casSearchResults'), noResults = document.getElementById('casNoResults');
    results.innerHTML = ''; noResults.style.display = 'none';
    if (q.trim().length < 2) return;
    casSearchTimeout = setTimeout(function () {
        fetch('casualties.php?search_resident=1&event_id=<?= (int) $eventId ?>&q=' + encodeURIComponent(q.trim()))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.length) { noResults.style.display = 'block'; return; }
                data.forEach(function (d) {
                    var btn = document.createElement('button');
                    btn.type = 'button'; btn.className = 'sw-resident-result'; btn.textContent = d.name;
                    if (d.type === 'member') {
                        var sub = document.createElement('small');
                        sub.style.display = 'block'; sub.style.opacity = '.7'; sub.textContent = 'Family member of ' + d.head;
                        btn.appendChild(sub);
                    }
                    if (d.done) {
                        btn.disabled = true; btn.style.opacity = '.55'; btn.style.cursor = 'not-allowed';
                        var tag = document.createElement('small');
                        tag.style.display = 'block'; tag.textContent = 'Already recorded for this event';
                        btn.appendChild(tag);
                    } else {
                        btn.addEventListener('click', function () { showCasualtyForm(d); });
                    }
                    results.appendChild(btn);
                });
            })
            .catch(function () { noResults.style.display = 'block'; });
    }, 300);
}
</script>
<?php page_end(); ?>
