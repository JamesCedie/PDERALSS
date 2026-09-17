<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';

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

// Handle Add Evacuee (from DB or manual)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_evacuee']) && $eventId) {
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
page_start('Evacuation Centers');

if (isset($_SESSION['flash_success'])) { $successMsg = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }

$evacuees    = $eventId ? db_select('evacuation_evacuees', 'event_id = ? AND barangay = ?', [$eventId, $socialWorkerAddress], '*', 'created_at DESC') : [];
$totalPeople = array_sum(array_column($evacuees, 'household_no'));
$totalHH     = count($evacuees);
?>

<div class="page-head">
    <a href="disasters.php" class="btn btn-light">← Disaster Events</a>
    <h1 class="page-title">Assigned Evacuation Center</h1>
    <?php if ($currentEvent && ($currentEvent['status'] ?? 'Active') === 'Active'): ?>
        <button class="btn btn-primary" onclick="openModal('addEvacueeModal')">＋ Add Evacuee</button>
    <?php endif; ?>
</div>

<?php if ($successMsg): ?><div class="alert alert-success mb"><?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-danger mb"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>

<?php if (!$currentEvent): ?>
    <div class="card empty">No active disaster event found.</div>
<?php else: ?>
    <div class="mini mb">Event: <b><?= htmlspecialchars($currentEvent['event_name']) ?></b> &nbsp;·&nbsp; <?= htmlspecialchars($socialWorkerAddress) ?></div>

    <!-- Occupancy Level card -->
    <div class="card mb">
        <h2>Occupancy Level</h2>
        <!-- <p class="mini">This card shows occupancy level through:<br>
        1. Total number of people within the center.<br>
        2. Total number of household within the center (grouping through identifying the head of household from database).</p> -->
        <div class="grid g2 mt">
            <div><div class="stat-value"><?= $totalPeople ?></div><div class="stat-label">Total People</div></div>
            <div><div class="stat-value"><?= $totalHH ?></div><div class="stat-label">Total Households</div></div>
        </div>
    </div>

    <!-- Search bar -->
    <div style="margin-bottom:12px;">
        <div style="border:1px solid #d1d5db;border-radius:999px;padding:10px 18px;display:flex;align-items:center;gap:10px;background:#fff;">
            <span style="color:#9ca3af;">🔍</span>
            <input type="text" id="tableSearch" placeholder="Search..." oninput="filterTable()"
                style="border:none;outline:none;width:100%;font-size:13px;background:transparent;">
        </div>
    </div>

    <!-- Scrollable table -->
    <div class="card" style="padding:0;overflow:hidden;">
        <div style="overflow-y:auto;max-height:420px;scrollbar-width:thin;scrollbar-color:#d1d5db transparent;">
            <table class="table" id="evacueeTable">
                <thead style="position:sticky;top:0;background:#f8fafc;z-index:1;">
                    <tr>
                        <th>Name</th>
                        <th>Household No.</th>
                        <th>Date/Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($evacuees)): ?>
                        <tr><td colspan="3" class="empty">No evacuees recorded yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($evacuees as $e): ?>
                        <tr>
                            <td><?= htmlspecialchars($e['name']) ?></td>
                            <td><?= htmlspecialchars($e['household_no'] ?? '—') ?></td>
                            <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($e['created_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Add Evacuee Modal -->
<div id="addEvacueeModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Search Resident Name</h2>
            <button class="icon-btn" onclick="closeEvacModal()">✕</button>
        </div>

        <!-- Phase 1: Search -->
        <div id="searchPhase">
            <div style="border:2px solid #3b82f6;border-radius:12px;padding:16px;">
                <div style="font-weight:600;margin-bottom:10px;font-size:14px;">Search Resident Name</div>
                <div style="position:relative;">
                    <input type="text" id="residentSearch" placeholder="Type to search..."
                        oninput="searchResident(this.value)"
                        style="width:100%;border:1px solid #d1d5db;border-radius:999px;padding:10px 40px 10px 16px;font-size:13px;outline:none;">
                    <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:14px;">🔍</span>
                </div>

                <!-- Search results dropdown -->
                <div id="searchResults" style="margin-top:8px;"></div>

                <!-- No results + Add New button -->
                <div id="noResultsRow" style="display:none;text-align:center;margin-top:12px;">
                    <div class="mini" style="margin-bottom:10px;">No results found.</div>
                    <button type="button" onclick="showManualForm()"
                        style="background:#f3f4f6;border:none;border-radius:8px;padding:12px 24px;cursor:pointer;font-size:13px;width:100%;">
                        ＋ &nbsp; Add New Evacuee Record
                    </button>
                </div>
            </div>
        </div>

        <!-- Phase 2: Manual entry (shown when "Add New Evacuee Record" is clicked) -->
        <div id="manualPhase" style="display:none;">
            <div style="border:2px solid #3b82f6;border-radius:12px;padding:16px;">
                <div style="font-weight:600;margin-bottom:10px;font-size:14px;">Add New Evacuee Record</div>
                <form method="post" id="addEvacueeForm">
                    <input type="hidden" name="add_evacuee" value="1">
                    <input type="hidden" name="household_id" id="selectedHouseholdId" value="">
                    <div class="field mb">
                        <label>Full Name</label>
                        <input name="name" id="evacueeNameInput" required placeholder="Enter full name">
                    </div>
                    <div class="field mb">
                        <label>Household No. (no. of members)</label>
                        <input type="number" name="household_no" id="evacueeHHNo" min="1" value="1">
                    </div>
                    <div class="actions">
                        <button type="button" class="btn btn-light" onclick="backToSearch()">← Back</button>
                        <button class="btn btn-primary">Save Evacuee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function filterTable() {
    const q = document.getElementById('tableSearch').value.toLowerCase();
    document.querySelectorAll('#evacueeTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

function closeEvacModal() {
    closeModal('addEvacueeModal');
    resetEvacModal();
}

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
    const results   = document.getElementById('searchResults');
    const noResults = document.getElementById('noResultsRow');
    results.innerHTML = '';
    noResults.style.display = 'none';

    if (q.length < 2) return;

    searchTimeout = setTimeout(() => {
        fetch('evacuation-centers.php?search_household=1&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                if (!data.length) {
                    noResults.style.display = 'block';
                    return;
                }
                results.innerHTML = data.map(d =>
                    `<div onclick="selectResident(${d.id},'${d.name.replace(/'/g,"\\'").replace(/"/g,'&quot;')}',${d.members})"
                        style="padding:10px 14px;cursor:pointer;border-radius:8px;font-size:13px;background:#f9fafb;margin-top:4px;"
                        onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f9fafb'">
                        ${d.name}
                    </div>`
                ).join('');
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
    document.querySelector('#manualPhase [name="name"]').value = name;
}
</script>

<?php page_end(); ?>