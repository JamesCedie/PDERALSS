<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';
require_once '../includes/md.php';
db_ensure_disaster_event_status();
db_ensure_evac_center_status_table();
db_ensure_evacuee_members();

$currentUser         = db_user_with_barangay($_SESSION['user']['id'] ?? null);
$socialWorkerAddress = $currentUser['address'] ?? '';
$myBarangayId        = (int) ($currentUser['barangay_id'] ?? 0);
// The Social Worker's evacuation center (one center per barangay).
$myCenterId = $myBarangayId
    ? (int) db_query('SELECT center_id FROM evacuation_centers WHERE barangay_id = ?', [$myBarangayId])->fetchColumn()
    : 0;

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
if ($eventId && $myCenterId) {
    $fullRow = db_select_one('evacuation_center_status', 'event_id = ? AND center_id = ?', [$eventId, $myCenterId], 'status');
    $centerIsFull = ($fullRow['status'] ?? '') === 'Full';
}

// Handle Add Evacuee (from DB or manual)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_evacuee']) && $eventId && $centerIsFull) {
    $errorMsg = 'This evacuation center is marked Full. Set it back to Available to add more evacuees.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_evacuee']) && $eventId) {
    $name          = trim($_POST['name'] ?? '');
    $householdId   = (int) ($_POST['household_id'] ?? 0) ?: null;
    $compositionId = (int) ($_POST['composition_id'] ?? 0) ?: null;
    $peopleCount   = 1;   // every person added counts as exactly 1

    if (!$myCenterId) {
        $errorMsg = 'Your account has no barangay assigned. Please contact an MDRRMO Officer.';
    } elseif ($householdId) {
        // Registered resident: trust the database (not the form) for the name, the family link and the head count.
        $hh = db_query('SELECT household_id, firstname, middlename, lastname, nameextension, total_family_members
                        FROM household WHERE household_id = ? AND barangay_id = ?', [$householdId, $myBarangayId])->fetch();
        if (!$hh) {
            $errorMsg = 'That household could not be found in your barangay.';
        } else {
            $headRegistered = (bool) db_query('SELECT 1 FROM evacuation_evacuees WHERE event_id = ? AND household_id = ? AND composition_id IS NULL',
                [$eventId, $householdId])->fetchColumn();
            if ($compositionId) {
                // A single family member: counts as 1 person but stays linked to their family head.
                $mem = db_query('SELECT first_name, middle_name, last_name FROM family_composition WHERE composition_id = ? AND household_id = ?',
                    [$compositionId, $householdId])->fetch();
                if (!$mem) {
                    $errorMsg = 'That family member could not be found.';
                } else {
                    // Allowed even when the head is already registered: the member is saved as their own record.
                    $name = preg_replace('/\s+/', ' ', trim(implode(' ', array_filter([$mem['first_name'], $mem['middle_name'], $mem['last_name']]))));
                }
            } else {
                // The family head is one person; members are added separately.
                $name = preg_replace('/\s+/', ' ', trim(implode(' ', array_filter([$hh['firstname'], $hh['middlename'], $hh['lastname'], $hh['nameextension']]))));
            }
        }
    } elseif ($name === '') {
        $errorMsg = 'Please enter a name.';
    }

    if ($errorMsg === null && $name !== '') {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                db_insert('evacuation_evacuees', [
                    'event_id'       => $eventId,
                    'center_id'      => $myCenterId,
                    'household_id'   => $householdId,
                    'composition_id' => $householdId ? $compositionId : null,
                    'name'           => $name,
                    'people_count'   => $peopleCount,
                    'added_by'       => $_SESSION['user']['id'] ?? null,
                ]);
                $pdo->commit();
            } catch (Throwable $inner) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $inner;
            }
            $_SESSION['flash_success'] = 'Evacuee added.';
            header('Location: evacuation-centers.php?event_id=' . $eventId);
            exit;
        } catch (PDOException $e) {
            // Unique indexes: a household (or an individual member) can be checked in only once per event.
            $errorMsg = ($e->getCode() === '23505')
                ? 'This person is already registered as evacuated for this event.'
                : 'Could not save the evacuee. Please try again.';
        }
    }
}

// Handle the Occupancy Status dropdown (saved per event + barangay)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_center_status']) && $eventId) {
    $newStatus = (string) ($_POST['center_status'] ?? '');
    if (!md_csrf_valid()) {
        $errorMsg = 'Your session expired. Please reload the page and try again.';
    } elseif (!$currentEvent || ($currentEvent['status'] ?? 'Active') !== 'Active') {
        $errorMsg = 'This disaster event is no longer active, so the status can no longer be changed.';
    } elseif (!$myCenterId) {
        $errorMsg = 'Your account has no barangay assigned. Please contact an MDRRMO Officer.';
    } elseif (!in_array($newStatus, EVAC_CENTER_SW_OPTIONS, true)) {
        $errorMsg = 'Please choose a valid occupancy status.';
    } else {
        db_query(
            'INSERT INTO public.evacuation_center_status (event_id, center_id, status, updated_by, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON CONFLICT (event_id, center_id)
             DO UPDATE SET status = EXCLUDED.status, updated_by = EXCLUDED.updated_by, updated_at = NOW()',
            [$eventId, $myCenterId, $newStatus, $_SESSION['user']['id'] ?? null]
        );
        $_SESSION['flash_success'] = 'Occupancy status set to ' . $newStatus . '.';
        header('Location: evacuation-centers.php?event_id=' . $eventId);
        exit;
    }
}

// AJAX: search household heads AND their family members by name (barangay-scoped)
if (isset($_GET['search_household'])) {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    $like = '%' . preg_replace('/\s+/', ' ', $q) . '%';
    $join = static fn(array $parts): string => preg_replace('/\s+/', ' ', trim(implode(' ', array_filter($parts))));

    // Who is already checked in for this event (whole households, and individual members)
    $doneHeads = $doneMembers = [];
    if ($eventId) {
        foreach (db_select('evacuation_evacuees', 'event_id = ? AND household_id IS NOT NULL', [$eventId], 'household_id, composition_id') as $x) {
            if ($x['composition_id'] === null) $doneHeads[(int) $x['household_id']] = true;
            else $doneMembers[(int) $x['composition_id']] = true;
        }
    }

    $out = [];
    $heads = db_query(
        "SELECT household_id, firstname, middlename, lastname, nameextension, total_family_members
         FROM household
         WHERE barangay_id = ? AND concat_ws(' ', firstname, middlename, lastname, nameextension) ILIKE ?
         ORDER BY lastname ASC LIMIT 10", [$myBarangayId, $like])->fetchAll();
    foreach ($heads as $r) {
        $out[] = ['type' => 'head', 'id' => (int) $r['household_id'], 'composition_id' => 0,
                  'name' => $join([$r['firstname'], $r['middlename'], $r['lastname'], $r['nameextension']]),
                  'head' => '', 'done' => isset($doneHeads[(int) $r['household_id']])];
    }
    $members = db_query(
        "SELECT f.composition_id, f.household_id, f.first_name, f.middle_name, f.last_name, f.relationship,
                h.firstname, h.middlename, h.lastname, h.nameextension
         FROM family_composition f JOIN household h ON h.household_id = f.household_id
         WHERE h.barangay_id = ? AND concat_ws(' ', f.first_name, f.middle_name, f.last_name) ILIKE ?
         ORDER BY f.last_name ASC LIMIT 10", [$myBarangayId, $like])->fetchAll();
    foreach ($members as $r) {
        $hid = (int) $r['household_id'];
        $out[] = ['type' => 'member', 'id' => $hid, 'composition_id' => (int) $r['composition_id'],
                  'name' => $join([$r['first_name'], $r['middle_name'], $r['last_name']]),
                  'head' => $join([$r['firstname'], $r['middlename'], $r['lastname'], $r['nameextension']]),
                  'relationship' => (string) ($r['relationship'] ?? ''),
                  'done' => isset($doneMembers[(int) $r['composition_id']])];
    }
    echo json_encode($out);
    exit;
}


require '../includes/layout.php';
page_start('Assigned Evacuation Center');

if (isset($_SESSION['flash_success'])) { $successMsg = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }

$evacuees    = ($eventId && $myCenterId) ? db_query(
    "SELECT x.*, concat_ws(' ', h.firstname, h.middlename, h.lastname, h.nameextension) AS head_name
     FROM evacuation_evacuees x LEFT JOIN household h ON h.household_id = x.household_id
     WHERE x.event_id = ? AND x.center_id = ? ORDER BY x.created_at DESC", [$eventId, $myCenterId])->fetchAll() : [];
$totalPeople = array_sum(array_column($evacuees, 'people_count'));
// A household counts once, however many of its members were checked in separately.
$totalHH     = count(array_unique(array_map(static fn($e) => $e['household_id'] ? 'h' . $e['household_id'] : 'e' . $e['evacuee_id'], $evacuees)));

$storedStatus  = ($eventId && $myCenterId)
    ? db_select_one('evacuation_center_status', 'event_id = ? AND center_id = ?', [$eventId, $myCenterId], 'status')
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
            <button class="btn btn-primary" disabled style="opacity:.5;cursor:not-allowed;" title="This evacuation center is Full. Set it to Available to add evacuees.">Add Evacuee</button>
        <?php else: ?>
            <button class="btn btn-primary" onclick="openModal('addEvacueeModal')">Add Evacuee</button>
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
        <span aria-hidden="true"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
        <input type="search" id="swSearch" placeholder="Search..." autocomplete="off">
    </label>

    <div class="sw-data-table">
        <div style="max-height:420px;overflow-y:auto;">
            <table class="table" id="swTable">
                <thead>
                    <tr><th>Name</th><th>Family Head</th><th>Date/Time</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($evacuees)): ?>
                        <tr><td colspan="3" class="empty">No evacuees recorded yet.</td></tr>
                    <?php else: foreach ($evacuees as $e): ?>
                        <?php
                            // A member shows their family head's name; the head themselves shows "Family head";
                            // a manually-entered person with no household record has no head to show.
                            $headCell = $e['composition_id']
                                ? preg_replace('/\s+/', ' ', trim((string) $e['head_name']))
                                : ($e['household_id'] ? 'Family head' : '—');
                        ?>
                        <tr data-search="<?= md_h(strtolower($e['name'] . ' ' . $headCell)) ?>">
                            <td><?= htmlspecialchars($e['name']) ?></td>
                            <td><?= htmlspecialchars($headCell) ?></td>
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
            <button class="icon-btn" onclick="closeEvacModal()" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>

        <div id="searchPhase">
            <div class="sw-search-modal-input">
                <input type="text" id="residentSearch" placeholder="Search resident..." oninput="searchResident(this.value)" autocomplete="off">
                <span aria-hidden="true"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
            </div>
            <div id="searchResults" class="sw-resident-results"></div>
            <div id="noResultsRow" class="sw-no-results" style="display:none;">
                <div>No results found.</div>
                <button type="button" onclick="showManualForm()" class="btn btn-primary">Add New Evacuee Record</button>
            </div>
        </div>

        <div id="manualPhase" style="display:none;">
            <form method="post" id="addEvacueeForm">
                <input type="hidden" name="add_evacuee" value="1">
                <input type="hidden" name="household_id" id="selectedHouseholdId" value="">
                <input type="hidden" name="composition_id" id="selectedCompositionId" value="">
                <div class="field mb"><label>Full Name</label><input name="name" id="evacueeNameInput" required placeholder="Enter full name"></div>
                <div class="mini mb" id="evacueeFamilyNote" style="display:none;"></div>
                <div class="actions"><button type="button" class="btn btn-light" onclick="backToSearch()">Back</button><button class="btn btn-primary">Save Evacuee</button></div>
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
    setEvacuee(null);
}
function setEvacuee(d) {
    const nameInput = document.getElementById('evacueeNameInput');
    const note = document.getElementById('evacueeFamilyNote');
    document.getElementById('selectedHouseholdId').value = d ? d.id : '';
    document.getElementById('selectedCompositionId').value = (d && d.type === 'member') ? d.composition_id : '';
    nameInput.value = d ? d.name : '';
    nameInput.readOnly = !!d;               // a registered resident's name comes from the record
    note.style.display = (d && d.type === 'member') ? 'block' : 'none';
    note.textContent = (d && d.type === 'member') ? 'Family member of ' + d.head + (d.relationship ? ' (' + d.relationship + ')' : '') : '';
}
function backToSearch() {
    setEvacuee(null);
    document.getElementById('manualPhase').style.display = 'none';
    document.getElementById('searchPhase').style.display = 'block';
}
function showManualForm() {
    setEvacuee(null);
    document.getElementById('searchPhase').style.display = 'none';
    document.getElementById('manualPhase').style.display = 'block';
    document.getElementById('evacueeNameInput').value = document.getElementById('residentSearch').value;
    document.getElementById('evacueeNameInput').focus();
}
let searchTimeout;
function searchResident(q) {
    clearTimeout(searchTimeout);
    const results = document.getElementById('searchResults');
    const noResults = document.getElementById('noResultsRow');
    results.innerHTML = '';
    noResults.style.display = 'none';
    if (q.trim().length < 2) return;

    searchTimeout = setTimeout(() => {
        fetch('evacuation-centers.php?search_household=1&event_id=<?= (int) $eventId ?>&q=' + encodeURIComponent(q.trim()))
            .then(r => r.json())
            .then(data => {
                if (!data.length) { noResults.style.display = 'block'; return; }
                data.forEach(d => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'sw-resident-result';
                    btn.textContent = d.name;
                    if (d.type === 'member') {
                        const sub = document.createElement('small');
                        sub.style.display = 'block';
                        sub.style.opacity = '.7';
                        sub.textContent = 'Family member of ' + d.head;
                        btn.appendChild(sub);
                    }
                    if (d.done) {
                        btn.disabled = true;
                        btn.style.opacity = '.55';
                        btn.style.cursor = 'not-allowed';
                        const tag = document.createElement('small');
                        tag.style.display = 'block';
                        tag.textContent = 'Already evacuated for this event';
                        btn.appendChild(tag);
                    } else {
                        btn.addEventListener('click', () => selectResident(d));
                    }
                    results.appendChild(btn);
                });
            })
            .catch(() => { noResults.style.display = 'block'; });
    }, 300);
}
function selectResident(d) {
    setEvacuee(d);
    document.getElementById('searchPhase').style.display = 'none';
    document.getElementById('manualPhase').style.display = 'block';
}
</script>

<?php page_end(); ?>
