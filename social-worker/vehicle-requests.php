<?php
<<<<<<< HEAD
require_once '../includes/access.php'; require_page_access();
require_once '../includes/vehicle.php';
vr_ensure_table();

$userId      = (string) ($_SESSION['user']['id'] ?? '');
$currentUser = db_select_one('users', 'user_id = ?', [$userId]);
$barangay    = trim((string) ($currentUser['address'] ?? ''));

// ── Create a vehicle request ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $error = null;

    $purpose    = $_POST['purpose'] ?? '';
    $urgency    = $_POST['urgency'] ?? '';
    $passengers = filter_var($_POST['passengers'] ?? '', FILTER_VALIDATE_INT);
    $date       = vr_parse_date($_POST['requested_date'] ?? '');
    $notes      = trim((string) ($_POST['notes'] ?? ''));

    if (!vr_csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($barangay === '') {
        $error = 'Your account has no barangay assigned. Please contact an MDRRMO Officer.';
    } elseif (!in_array($purpose, VR_PURPOSES, true)) {
        $error = 'Please select a purpose.';
    } elseif ($passengers === false || $passengers < 0 || $passengers > VR_MAX_PASSENGERS) {
        $error = 'Passengers must be a number from 0 to ' . VR_MAX_PASSENGERS . '.';
    } elseif (!in_array($urgency, VR_URGENCIES, true)) {
        $error = 'Please select an urgency level.';
    } elseif ($date === null) {
        $error = 'Please select a valid requested date.';
    } elseif ($date < vr_today()->format('Y-m-d')) {
        $error = 'The requested date cannot be in the past.';
    } elseif (mb_strlen($notes) > 500) {
        $error = 'Notes must be 500 characters or fewer.';
    }

    if ($error === null) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $row = db_insert('vehicle_requests', [
                'barangay'       => $barangay,
                'purpose'        => $purpose,
                'passengers'     => $passengers,
                'urgency'        => $urgency,
                'requested_date' => $date,
                'notes'          => $notes !== '' ? $notes : null,
                'status'         => 'Pending',
                'requested_by'   => $userId,
            ]);
            $code = sprintf('VR-%s-%03d', vr_today()->format('Y'), $row['request_id']);
            db_update('vehicle_requests', ['request_code' => $code], 'request_id = ?', [$row['request_id']]);
            $pdo->commit();
            $_SESSION['flash_success'] = "Vehicle request {$code} submitted. The MDRRMO will review it shortly.";
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log('vehicle request insert failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Could not save your request. Please try again.';
        }
    } else {
        $_SESSION['flash_error'] = $error;
    }

    header('Location: vehicle-requests.php');
    exit;
}

require '../includes/layout.php';
page_start('Vehicle Requests', 'Social Work Officer · Request municipal transport assistance');

$successMsg = $_SESSION['flash_success'] ?? null;
$errorMsg   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// History is scoped to the worker's assigned barangay, so every barangay sees
// only its own requests. With no barangay assigned, nothing is shown.
$scope    = $barangay !== '' ? 'barangay = ?' : '1=0';
$scopeArg = $barangay !== '' ? [$barangay] : [];

$counts   = vr_status_counts($scope, $scopeArg);
$requests = db_query(
    "SELECT v.*, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS requester_name
     FROM vehicle_requests v
     LEFT JOIN users u ON u.user_id::text = v.requested_by
     WHERE v." . ($barangay !== '' ? 'barangay = ?' : '1=0') . "
     ORDER BY v.created_at DESC, v.request_id DESC",
    $scopeArg
)->fetchAll();
$visible  = 5;
?>

<div class="vr-page">
    <div class="vr-head">
        <div class="assigned-label">Assigned Barangay:<strong><?= vr_h($barangay ?: '—') ?></strong></div>
        <button class="btn btn-primary" onclick="openModal('createVehicleModal')" <?= $barangay === '' ? 'disabled' : '' ?>>＋ &nbsp;Create Vehicle Request</button>
    </div>

    <?php if ($successMsg): ?><div class="alert alert-success mb"><?= vr_h($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="alert alert-danger mb"><?= vr_h($errorMsg) ?></div><?php endif; ?>
    <?php if ($barangay === ''): ?>
        <div class="alert alert-warning mb">Your account has no barangay assigned, so you can't create requests yet. Please contact an MDRRMO Officer.</div>
    <?php endif; ?>

    <div class="vr-section-title">Request Summary</div>
    <div class="vr-cards">
        <?php foreach (['Approved', 'Pending', 'Scheduled', 'Completed'] as $s): ?>
            <div class="vr-card">
                <div class="vr-card-top"><span><?= $s ?></span><i class="vr-dot <?= strtolower($s) ?>"></i></div>
                <div class="vr-card-value"><?= $counts[$s] ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="vr-table-head">
        <div class="vr-section-title"><?= vr_h($barangay ?: 'Barangay') ?> Request History</div>
        <?php if (count($requests) > $visible): ?>
            <a href="#" class="vr-link" id="vrToggleAll">View all requests</a>
        <?php endif; ?>
    </div>

    <div class="sw-data-table">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Request ID</th>
                        <th>Purpose</th>
                        <th>Requested Date</th>
                        <th>Passengers</th>
                        <th>Status</th>
                        <th class="vr-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$requests): ?>
                        <tr><td colspan="6" class="empty">You haven't made any vehicle requests yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($requests as $i => $r): ?>
                        <tr class="<?= $i >= $visible ? 'vr-extra' : '' ?>" <?= $i >= $visible ? 'hidden' : '' ?>>
                            <td><strong><?= vr_h($r['request_code']) ?></strong></td>
                            <td><?= vr_h($r['purpose']) ?></td>
                            <td><?= vr_h(vr_date($r['requested_date'])) ?></td>
                            <td><?= (int) $r['passengers'] ?></td>
                            <td><?= vr_status_badge($r['status']) ?></td>
                            <td class="vr-right"><button class="btn btn-dark-sm" onclick="openModal('viewVehicle-<?= (int) $r['request_id'] ?>')">View</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create request -->
<div id="createVehicleModal" class="modal">
    <div class="modal-box vr-modal">
        <div class="modal-head">
            <h2>Create Vehicle Request</h2>
            <button type="button" class="icon-btn" onclick="closeModal('createVehicleModal')" aria-label="Close">×</button>
        </div>
        <form method="post" class="vr-form">
            <input type="hidden" name="action" value="create">
            <?= vr_csrf_field() ?>

            <div class="field full">
                <label>Purpose</label>
                <select name="purpose" required>
                    <option value="" disabled selected>Select purpose</option>
                    <?php foreach (VR_PURPOSES as $p): ?><option><?= vr_h($p) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Passengers</label>
                <input type="number" name="passengers" min="0" max="<?= VR_MAX_PASSENGERS ?>" placeholder="Enter number" required>
            </div>
            <div class="field">
                <label>Urgency</label>
                <select name="urgency" required>
                    <option value="" disabled selected>Select urgency</option>
                    <?php foreach (VR_URGENCIES as $u): ?><option><?= vr_h($u) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field full">
                <label>Requested date</label>
                <input type="date" name="requested_date" min="<?= vr_today()->format('Y-m-d') ?>" required>
            </div>
            <div class="field full">
                <label>Notes</label>
                <textarea name="notes" maxlength="500" rows="3" placeholder="Add transport details or special requirements"></textarea>
            </div>

            <div class="vr-actions">
                <button type="submit" class="btn btn-primary">Submit</button>
=======
require_once '../includes/access.php'; require_page_access(); require '../includes/layout.php';
page_start('Vehicle Requests');

$rows = [
    ['VR-001', 'Brgy. Jaro',        'Evacuation Transport',   '45', 'High',   '2026-05-01', 'Approved',  'Truck-01'],
    ['VR-002', 'Brgy. Molo',        'Relief Goods Delivery',  '0',  'Medium', '2026-05-02', 'Pending',   '-'],
    ['VR-003', 'Brgy. Mandurriao',  'Medical Emergency',      '3',  'High',   '2026-05-01', 'Scheduled', 'Ambulance-02'],
    ['VR-004', 'Brgy. Arevalo',     'Evacuation Transport',   '30', 'Low',    '2026-05-03', 'Rejected',  '-'],
    ['VR-005', 'Brgy. La Paz',      'Supply Transport',       '0',  'Medium', '2026-05-02', 'Completed', 'Van-03'],
];

$counts = ['Pending' => 1, 'Approved' => 1, 'Scheduled' => 1, 'Completed' => 1];
?>

<div class="page-head">
    <h1 class="page-title">Vehicle Request & Logistics Scheduling</h1>
    <button class="btn btn-primary" onclick="openModal('vehicleModal')">＋ New Request</button>
</div>

<div class="grid g4">
    <?php foreach([['Pending', 'yellow', '⌛'], ['Approved', 'green', '✓'], ['Scheduled', 'blue', '🚚'], ['Completed', 'gray', '✓']] as $s): ?>
        <div class="card">
            <div class="stat">
                <div class="stat-icon <?=$s[1]?>"><?=$s[2]?></div>
                <div>
                    <div class="stat-value"><?=$counts[$s[0]]?></div>
                    <div class="stat-label"><?=$s[0]?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mt">
    <h2>Vehicle Requests</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Barangay</th>
                    <th>Purpose</th>
                    <th>Passengers</th>
                    <th>Urgency</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Assigned Vehicle</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($rows as $r): ?>
                    <tr>
                        <td><?=$r[0]?></td>
                        <td><?=$r[1]?></td>
                        <td><?=$r[2]?></td>
                        <td><?=$r[3]?></td>
                        <td><?=status_badge($r[4])?></td>
                        <td><?=$r[5]?></td>
                        <td><?=status_badge($r[6])?></td>
                        <td><?=$r[7]?></td>
                        <td class="actions">
                            <?php if($r[6] === 'Pending'): ?>
                                <button class="btn btn-success" onclick="showToast('Request approved.', 'success')">Approve</button>
                                <button class="btn btn-danger" onclick="showToast('Request rejected.', 'danger')">Reject</button>
                            <?php else: ?>
                                <button class="btn btn-light">View</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="vehicleModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>New Vehicle Request</h2>
            <button class="icon-btn" onclick="closeModal('vehicleModal')">✕</button>
        </div>
        <form onsubmit="event.preventDefault();showToast('Vehicle request submitted.', 'success');closeModal('vehicleModal')">
            <div class="form-grid">
                <div class="field">
                    <label>Barangay</label>
                    <input required>
                </div>
                <div class="field">
                    <label>Purpose</label>
                    <select>
                        <option>Evacuation Transport</option>
                        <option>Relief Goods Delivery</option>
                        <option>Medical Emergency</option>
                        <option>Supply Transport</option>
                    </select>
                </div>
                <div class="field">
                    <label>Passengers</label>
                    <input type="number" min="0">
                </div>
                <div class="field">
                    <label>Urgency</label>
                    <select>
                        <option>High</option>
                        <option>Medium</option>
                        <option>Low</option>
                    </select>
                </div>
                <div class="field">
                    <label>Requested Date</label>
                    <input type="date">
                </div>
                <div class="field">
                    <label>Notes</label>
                    <input>
                </div>
            </div>
            <div class="actions mt">
                <button class="btn btn-primary">Submit Request</button>
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
            </div>
        </form>
    </div>
</div>

<<<<<<< HEAD
<!-- View request details -->
<?php foreach ($requests as $r): ?>
    <div id="viewVehicle-<?= (int) $r['request_id'] ?>" class="modal">
        <div class="modal-box vr-modal">
            <div class="modal-head">
                <div>
                    <h2><?= vr_h($r['request_code']) ?></h2>
                    <div class="mini">Submitted <?= vr_h(vr_date(substr($r['created_at'], 0, 10))) ?> by <?= vr_h($r['requester_name'] ?: 'Unknown user') ?></div>
                </div>
                <button type="button" class="icon-btn" onclick="closeModal('viewVehicle-<?= (int) $r['request_id'] ?>')" aria-label="Close">×</button>
            </div>
            <div class="vr-form">
                <div class="field"><label>Status</label><div class="vr-readonly"><?= vr_status_badge($r['status']) ?></div></div>
                <div class="field"><label>Urgency</label><div class="vr-readonly"><?= vr_urgency_badge($r['urgency']) ?></div></div>
                <div class="field full"><label>Assigned vehicle</label><div class="vr-readonly"><?= $r['assigned_vehicle'] ? vr_h($r['assigned_vehicle']) : '<span class="vr-muted">Not yet assigned</span>' ?></div></div>
                <div class="field full"><label>Purpose</label><div class="vr-readonly"><?= vr_h($r['purpose']) ?></div></div>
                <div class="field"><label>Passengers</label><div class="vr-readonly"><?= (int) $r['passengers'] ?></div></div>
                <div class="field"><label>Requested date</label><div class="vr-readonly"><?= vr_h(vr_date($r['requested_date'])) ?></div></div>
                <div class="field full"><label>Notes</label><div class="vr-readonly vr-notes"><?= $r['notes'] ? vr_h($r['notes']) : '<span class="vr-muted">No notes</span>' ?></div></div>
                <div class="vr-actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('viewVehicle-<?= (int) $r['request_id'] ?>')">Close</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
(function () {
    var t = document.getElementById('vrToggleAll');
    if (!t) return;
    t.addEventListener('click', function (e) {
        e.preventDefault();
        var rows = document.querySelectorAll('.vr-extra');
        var show = rows[0] && rows[0].hidden;
        rows.forEach(function (r) { r.hidden = !show; });
        t.textContent = show ? 'Show fewer' : 'View all requests';
    });
})();
</script>

=======
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
<?php page_end(); ?>
