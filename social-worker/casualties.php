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
        "SELECT household_id, firstname, middlename, lastname, nameextension
         FROM household
         WHERE barangay_id = ? AND concat_ws(' ', firstname, middlename, lastname, nameextension) ILIKE ?
         ORDER BY lastname ASC LIMIT 10", [$myBarangayId, $like])->fetchAll();
    foreach ($heads as $r) {
        $nm = $joinName([$r['firstname'], $r['middlename'], $r['lastname'], $r['nameextension']]);
        $out[] = ['type' => 'head', 'id' => (int) $r['household_id'], 'composition_id' => 0, 'name' => $nm, 'head' => '',
                  'done' => isset($recorded[mb_strtolower($nm)])];
    }
    $members = db_query(
        "SELECT f.composition_id, f.household_id, f.first_name, f.middle_name, f.last_name, f.relationship,
                h.firstname, h.middlename, h.lastname, h.nameextension
         FROM family_composition f JOIN household h ON h.household_id = f.household_id
         WHERE h.barangay_id = ? AND concat_ws(' ', f.first_name, f.middle_name, f.last_name) ILIKE ?
         ORDER BY f.last_name ASC LIMIT 10", [$myBarangayId, $like])->fetchAll();
    foreach ($members as $r) {
        $nm = $joinName([$r['first_name'], $r['middle_name'], $r['last_name']]);
        $out[] = ['type' => 'member', 'id' => (int) $r['household_id'], 'composition_id' => (int) $r['composition_id'], 'name' => $nm,
                  'head' => $joinName([$r['firstname'], $r['middlename'], $r['lastname'], $r['nameextension']]),
                  'relationship' => (string) ($r['relationship'] ?? ''),
                  'done' => isset($recorded[mb_strtolower($nm)])];
    }
    echo json_encode($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_casualty') {
    $name          = trim($_POST['name'] ?? '');
    $type          = trim($_POST['type'] ?? '');
    $date          = trim($_POST['date'] ?? '');
    $householdId   = (int) ($_POST['household_id'] ?? 0);
    $compositionId = (int) ($_POST['composition_id'] ?? 0);
    // The form only asks for Type and Date; the database still requires a severity, so a neutral default is stored.
    $severity      = 'Minor';

    $allowedTypes = ['Fatality', 'Missing', 'Injured'];

    // A registered resident: trust the database (not the form) for the name.
    if ($householdId && !$errorMsg) {
        if ($compositionId) {
            $r = db_query('SELECT f.first_name, f.middle_name, f.last_name FROM family_composition f JOIN household h ON h.household_id = f.household_id
                           WHERE f.composition_id = ? AND f.household_id = ? AND h.barangay_id = ?', [$compositionId, $householdId, $myBarangayId])->fetch();
            $name = $r ? $joinName([$r['first_name'], $r['middle_name'], $r['last_name']]) : '';
        } else {
            $r = db_query('SELECT firstname, middlename, lastname, nameextension FROM household WHERE household_id = ? AND barangay_id = ?', [$householdId, $myBarangayId])->fetch();
            $name = $r ? $joinName([$r['firstname'], $r['middlename'], $r['lastname'], $r['nameextension']]) : '';
        }
    }

    if (!$currentEvent) {
        $errorMsg = 'The selected disaster event could not be found.';
    } elseif (($currentEvent['status'] ?? 'Active') !== 'Active') {
        $errorMsg = 'This disaster event is already marked as Passed and can no longer receive new casualty records.';
    } elseif (!$socialWorkerAddress) {
        $errorMsg = 'Your account has no barangay/address assigned. Please contact an MDRRMO Officer.';
    } elseif ($name === '' || !in_array($type, $allowedTypes, true) || $date === '') {
        $errorMsg = 'Please select a resident, then choose a Type and Date.';
    } elseif ($date > today_ph()) {
        $errorMsg = 'The date cannot be later than today.';
    } else {
        try {
            db_insert('casualties', [
                'event_id' => $eventId,
                'barangay_id' => $myBarangayId,
                'name' => $name,
                'type' => $type,
                'severity' => $severity,
                'date' => $date,
                'added_by' => $_SESSION['user']['id'] ?? null,
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
        'SELECT casualty_id, name, type, severity, date, notes FROM public.casualties WHERE event_id = ? AND barangay_id = ? ORDER BY date DESC, casualty_id DESC',
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
        <thead><tr><th>Name</th><th>Type</th><th>Date</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="3" class="empty">No casualties recorded yet.</td></tr>
        <?php else: foreach ($rows as $row): ?>
            <tr data-search="<?= md_h(strtolower($row['name'] . ' ' . $row['type'] . ' ' . $row['date'])) ?>">
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= status_badge($row['type']) ?></td>
                <td><?= htmlspecialchars($row['date']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        <tr id="swNoMatch" hidden><td colspan="3" class="empty">No casualties match your search.</td></tr>
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
                                    <div class="field mb"><label>Name</label><input name="name" id="casNameInput" required placeholder="Enter full name"></div>
                <div class="mini mb" id="casFamilyNote" style="display:none;"></div>
                <div class="field mb"><label>Type</label><select name="type" required><option value="" selected disabled>Select type</option><option value="Injured">Injured</option><option value="Missing">Missing</option><option value="Fatality">Fatality</option></select></div>
                    <div class="field mb"><label>Date</label><input name="date" type="date" required max="<?= htmlspecialchars(today_ph()) ?>" value="<?= htmlspecialchars(date('Y-m-d')) ?>"></div>
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
    var nameInput = document.getElementById('casNameInput'), note = document.getElementById('casFamilyNote');
    document.getElementById('casHouseholdId').value = d ? d.id : '';
    document.getElementById('casCompositionId').value = (d && d.type === 'member') ? d.composition_id : '';
    nameInput.value = d ? d.name : '';
    nameInput.readOnly = !!d;               // a registered resident's name comes from the record
    note.style.display = (d && d.type === 'member') ? 'block' : 'none';
    note.textContent = (d && d.type === 'member') ? 'Family member of ' + d.head + (d.relationship ? ' (' + d.relationship + ')' : '') : '';
}
function showCasualtyForm(d) {
    setCasualtyPerson(d);
    if (!d) document.getElementById('casNameInput').value = document.getElementById('casResidentSearch').value;
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
