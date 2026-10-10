<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';
require_once '../includes/md.php';
db_ensure_disaster_event_status();
db_ensure_evac_center_status_table();

$currentUser         = db_select_one('users', 'user_id = ?', [$_SESSION['user']['id'] ?? null]);
$socialWorkerAddress = $currentUser['address'] ?? '';

// Which event are we managing? Default to most recent Active event
$eventId = (int) ($_GET['event_id'] ?? 0);
if (!$eventId) {
    $latestEvent = db_select_one('disaster_events', "status = 'Active'", [], 'event_id', 'event_id DESC');
    $eventId = $latestEvent['event_id'] ?? 0;
}
$currentEvent = $eventId ? db_select_one('disaster_events', 'event_id = ?', [$eventId]) : null;

$successMsg = null;
$errorMsg   = null;

// A center marked Full cannot take new evacuees.
$centerIsFull = false;
if ($eventId && $socialWorkerAddress) {
    $fullRow = db_select_one('evacuation_center_status', 'event_id = ? AND barangay = ?', [$eventId, $socialWorkerAddress], 'status');
    $centerIsFull = ($fullRow['status'] ?? '') === 'Full';
}

// Handle Add Evacuee (from DB or manual)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_evacuee']) && $eventId && $centerIsFull) {
    $errorMsg = 'This evacuation center is marked Full. Set it back to Available to add more evacuees.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_evacuee']) && $eventId) {
    $name        = trim($_POST['name'] ?? '');
    $householdId = (int) ($_POST['household_id'] ?? 0) ?: null;
    $householdNo = (int) ($_POST['household_no'] ?? 0) ?: null;

    if ($name && $socialWorkerAddress) {
        db_insert('evacuation_evacuees', [
            'event_id'     => $eventId,
            'barangay'     => $socialWorkerAddress,
            'household_id' => $householdId,
            'name'         => $name,
            'household_no' => $householdNo,
            'added_by'     => $_SESSION['user']['id'] ?? null,
        ]);
        $_SESSION['flash_success'] = 'Evacuee added.';
        header('Location: evacuation-centers.php?event_id=' . $eventId);
        exit;
    }
    $errorMsg = 'Please enter a name.';
}

// Handle the Occupancy Status dropdown (saved per event + barangay)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_center_status']) && $eventId) {
    $newStatus = (string) ($_POST['center_status'] ?? '');
    if (!md_csrf_valid()) {
        $errorMsg = 'Your session expired. Please reload the page and try again.';
    } elseif (!$currentEvent || ($currentEvent['status'] ?? 'Active') !== 'Active') {
        $errorMsg = 'This disaster event is no longer active, so the status can no longer be changed.';
    } elseif (!$socialWorkerAddress) {
        $errorMsg = 'Your account has no barangay assigned. Please contact an MDRRMO Officer.';
    } elseif (!in_array($newStatus, EVAC_CENTER_SW_OPTIONS, true)) {
        $errorMsg = 'Please choose a valid occupancy status.';
    } else {
        db_query(
            'INSERT INTO public.evacuation_center_status (event_id, barangay, status, updated_by, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON CONFLICT (event_id, barangay)
             DO UPDATE SET status = EXCLUDED.status, updated_by = EXCLUDED.updated_by, updated_at = NOW()',
            [$eventId, $socialWorkerAddress, $newStatus, $_SESSION['user']['id'] ?? null]
        );
        $_SESSION['flash_success'] = 'Occupancy status set to ' . $newStatus . '.';
        header('Location: evacuation-centers.php?event_id=' . $eventId);
        exit;
    }
}

// AJAX: search household heads from database by name (barangay-scoped)
if (isset($_GET['search_household'])) {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    $like = '%' . $q . '%';
    $results = db_select('household',
        "(firstname ILIKE ? OR lastname ILIKE ?) AND barangay = ?",
        [$like, $like, $socialWorkerAddress],
        'household_id, firstname, middlename, lastname, nameextension, total_family_members',
        'lastname ASC', 10
    );
    $out = [];
    foreach ($results as $r) {
        $fullName = preg_replace('/\s+/', ' ', trim(implode(' ', array_filter([
            $r['firstname'], $r['middlename'], $r['lastname'], $r['nameextension']
        ]))));
        $out[] = ['id' => $r['household_id'], 'name' => $fullName, 'members' => $r['total_family_members']];
    }
    echo json_encode($out);
    exit;
}


require '../includes/layout.php';
page_start('Assigned Evacuation Center');

if (isset($_SESSION['flash_success'])) { $successMsg = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }

$evacuees    = $eventId ? db_select('evacuation_evacuees', 'event_id = ? AND barangay = ?', [$eventId, $socialWorkerAddress], '*', 'created_at DESC') : [];
$totalPeople = array_sum(array_column($evacuees, 'household_no'));
$totalHH     = count($evacuees);

$storedStatus  = ($eventId && $socialWorkerAddress)
    ? db_select_one('evacuation_center_status', 'event_id = ? AND barangay = ?', [$eventId, $socialWorkerAddress], 'status')
    : null;
$centerStatus  = $storedStatus['status'] ?? 'Available';
// Legacy values (Occupied / Near Capacity) are no longer selectable; show them as Available in the dropdown.
$centerSelect  = in_array($centerStatus, EVAC_CENTER_SW_OPTIONS, true) ? $centerStatus : 'Available';
$eventIsActive = $currentEvent && ($currentEvent['status'] ?? 'Active') === 'Active';
?>

<div class="md-page">
<div class="sw-section-head">
    <?php if ($currentEvent): ?>
        <div class="sw-event-label">Event:<strong><?= md_h($currentEvent['event_name']) ?> · <?= md_h(md_short_barangay((string) $socialWorkerAddress)) ?></strong></div>
    <?php endif; ?>
    <?php if ($eventIsActive): ?>
        <?php if ($centerStatus === 'Full'): ?>
            <button class="btn btn-primary" disabled style="opacity:.5;cursor:not-allowed;" title="This evacuation center is Full. Set it to Available to add evacuees.">＋ &nbsp;Add Evacuee</button>
        <?php else: ?>
            <button class="btn btn-primary" onclick="openModal('addEvacueeModal')">＋ &nbsp;Add Evacuee</button>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($successMsg): ?><div class="sw-inline-alert sw-inline-success"><?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="sw-inline-alert sw-inline-error"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>

<?php if (!$currentEvent): ?>
    <div class="card empty">No active disaster event found.</div>
<?php else: ?>
    <div class="sw-casualty-title">Occupancy Status</div>
    <?php if ($eventIsActive): ?>
        <form method="post" class="sw-status-form">
            <?= md_csrf_field() ?>
            <input type="hidden" name="set_center_status" value="1">
            <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
            <select name="center_status" class="sw-status-select" aria-label="Evacuation center occupancy status" onchange="this.form.submit()">
                <?php foreach (EVAC_CENTER_SW_OPTIONS as $opt): ?>
                    <option value="<?= md_h($opt) ?>" <?= $opt === $centerSelect ? 'selected' : '' ?>><?= md_h($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php else: ?>
        <div class="sw-status-form"><?= status_badge($centerStatus) ?></div>
    <?php endif; ?>

    <?php if ($centerStatus === 'Full'): ?><div class="sw-inline-alert sw-inline-error">This center is Full — adding evacuees is disabled. Set the status to Available to add more.</div><?php endif; ?>

    <div class="grid g2 md-stats">
        <div class="card sw-summary-card"><div class="stat-label">Total People</div><div class="stat-value"><?= htmlspecialchars($totalPeople) ?></div></div>
        <div class="card sw-summary-card"><div class="stat-label">Total Households</div><div class="stat-value"><?= htmlspecialchars($totalHH) ?></div></div>
    </div>

    <div class="sw-casualty-title mt">Evacuation Records</div>
    <label class="sw-search">
        <span aria-hidden="true">⌕</span>
        <input type="search" id="swSearch" placeholder="Search..." autocomplete="off">
    </label>

    <div class="sw-data-table">
        <div style="max-height:420px;overflow-y:auto;">
            <table class="table" id="swTable">
                <thead>
                    <tr><th>Name</th><th>Household No.</th><th>Date/Time</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($evacuees)): ?>
                        <tr><td colspan="3" class="empty">No evacuees recorded yet.</td></tr>
                    <?php else: foreach ($evacuees as $e): ?>
                        <tr data-search="<?= md_h(strtolower($e['name'])) ?>">
                            <td><?= htmlspecialchars($e['name']) ?></td>
                            <td><?= htmlspecialchars($e['household_no'] ?? '—') ?></td>
                            <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($e['created_at']))) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    <tr id="swNoMatch" hidden><td colspan="3" class="empty">No evacuees match your search.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
</div>

<!-- Add Evacuee: the same modal transitions through the three PDF states -->
<div id="addEvacueeModal" class="modal">
    <div class="modal-box sw-search-modal">
        <div class="modal-head">
            <h2>Search Resident Name</h2>
            <button class="icon-btn" onclick="closeEvacModal()" aria-label="Close">×</button>
        </div>

        <div id="searchPhase">
            <div class="sw-search-modal-input">
                <input type="text" id="residentSearch" placeholder="Search resident..." oninput="searchResident(this.value)" autocomplete="off">
                <span>⌕</span>
            </div>
            <div id="searchResults" class="sw-resident-results"></div>
            <div id="noResultsRow" class="sw-no-results" style="display:none;">
                <div>No results found.</div>
                <button type="button" onclick="showManualForm()" class="btn btn-primary">＋ &nbsp;Add New Evacuee Record</button>
            </div>
        </div>

        <div id="manualPhase" style="display:none;">
            <form method="post" id="addEvacueeForm">
                <input type="hidden" name="add_evacuee" value="1">
                <input type="hidden" name="household_id" id="selectedHouseholdId" value="">
                <div class="field mb"><label>Full Name</label><input name="name" id="evacueeNameInput" required placeholder="Enter full name"></div>
                <div class="field mb"><label>Household No. (no. of members)</label><input type="number" name="household_no" id="evacueeHHNo" min="1" value="1"></div>
                <div class="actions"><button type="button" class="btn btn-light" onclick="backToSearch()">← Back</button><button class="btn btn-primary">Save Evacuee</button></div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () { mdBindSearch('swSearch', 'swTable', 'swNoMatch'); });
function closeEvacModal() { closeModal('addEvacueeModal'); resetEvacModal(); }
function resetEvacModal() {
    document.getElementById('residentSearch').value = '';
    document.getElementById('searchResults').innerHTML = '';
    document.getElementById('noResultsRow').style.display = 'none';
    document.getElementById('searchPhase').style.display = 'block';
    document.getElementById('manualPhase').style.display = 'none';
    document.getElementById('selectedHouseholdId').value = '';
    document.getElementById('evacueeNameInput').value = '';
    document.getElementById('evacueeHHNo').value = '1';
}
function backToSearch() {
    document.getElementById('manualPhase').style.display = 'none';
    document.getElementById('searchPhase').style.display = 'block';
}
function showManualForm() {
    document.getElementById('searchPhase').style.display = 'none';
    document.getElementById('manualPhase').style.display = 'block';
    document.getElementById('evacueeNameInput').focus();
}
let searchTimeout;
function searchResident(q) {
    clearTimeout(searchTimeout);
    const results = document.getElementById('searchResults');
    const noResults = document.getElementById('noResultsRow');
    results.innerHTML = '';
    noResults.style.display = 'none';
    if (q.length < 2) return;

    searchTimeout = setTimeout(() => {
        fetch('evacuation-centers.php?search_household=1&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                if (!data.length) { noResults.style.display = 'block'; return; }
                results.innerHTML = data.map(d => {
                    const safeName = d.name.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
                    return `<button type="button" class="sw-resident-result" onclick="selectResident(${d.id},'${safeName}',${d.members})">${d.name}</button>`;
                }).join('');
            })
            .catch(() => { noResults.style.display = 'block'; });
    }, 300);
}
function selectResident(id, name, members) {
    document.getElementById('selectedHouseholdId').value = id;
    document.getElementById('evacueeNameInput').value = name;
    document.getElementById('evacueeHHNo').value = members;
    document.getElementById('searchPhase').style.display = 'none';
    document.getElementById('manualPhase').style.display = 'block';
}
</script>

<?php page_end(); ?>
