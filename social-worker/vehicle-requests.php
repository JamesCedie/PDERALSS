<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/vehicle.php';
vr_ensure_table();

$userId      = (string) ($_SESSION['user']['id'] ?? '');
$currentUser = db_user_with_barangay($userId);
$barangay    = trim((string) ($currentUser['address'] ?? ''));

// ── Create a vehicle request ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $error = null;

    $purpose   = $_POST['purpose'] ?? '';
    $passenger = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['passenger_name'] ?? '')));
    $hospital  = filter_var($_POST['hospital_id'] ?? '', FILTER_VALIDATE_INT);
    $date      = vr_parse_date($_POST['requested_date'] ?? '');
    $slot      = (string) ($_POST['requested_time'] ?? '');
    $notes     = trim((string) ($_POST['notes'] ?? ''));

    $hospitalOk = $hospital !== false
        && db_select_one('hospitals', 'hospital_id = ? AND is_active', [$hospital], 'hospital_id') !== null;

    if (!vr_csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($barangay === '') {
        $error = 'Your account has no barangay assigned. Please contact an MDRRMO Officer.';
    } elseif (!in_array($purpose, VR_PURPOSES, true)) {
        $error = 'Please select a purpose.';
    } elseif (!preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\'\-]{1,99}$/u', $passenger)) {
        $error = 'Enter the passenger name (letters only, 2 to 100 characters).';
    } elseif (!$hospitalOk) {
        $error = 'Please select a hospital.';
    } elseif ($date === null) {
        $error = 'Please select a requested date.';
    } elseif ($date < vr_today()->format('Y-m-d')) {
        $error = 'The requested date cannot be in the past.';
    } elseif (!in_array($slot, VR_TIME_SLOTS, true)) {
        $error = 'Please select a time slot.';
    } elseif (mb_strlen($notes) > 500) {
        $error = 'Notes must be 500 characters or fewer.';
    }

    if ($error === null) {
        $pdo = null;
        try {
            $pdo = db();
            $pdo->beginTransaction();
            // Serialize bookings for this slot so two people cannot take the last place at once.
            db_query('SELECT pg_advisory_xact_lock(hashtext(?))', ["vr:{$date} {$slot}"]);

            if (!vr_slot_available($date, $slot)) {
                $pdo->rollBack();
                $_SESSION['flash_error'] = 'That date and time is no longer available. Please choose another slot.';
            } else {
                $row = db_insert('vehicle_requests', [
                    'barangay_id'    => (int) $currentUser['barangay_id'],
                    'purpose'        => $purpose,
                    'passenger_name' => $passenger,
                    'hospital_id'    => $hospital,
                    'requested_date' => $date,
                    'requested_time' => $slot,
                    'notes'          => $notes !== '' ? $notes : null,
                    'status'         => 'Pending',
                    'requested_by'   => $userId,
                ]);
                $code = sprintf('VR-%s-%03d', vr_today()->format('Y'), $row['request_id']);
                db_update('vehicle_requests', ['request_code' => $code], 'request_id = ?', [$row['request_id']]);
                $pdo->commit();
                $_SESSION['flash_success'] = "Vehicle request {$code} submitted. The MDRRMO will review it shortly.";
            }
        } catch (Throwable $e) {
            if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
            error_log('vehicle request insert failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Could not save your request. Please try again.';
        }
    } else {
        $_SESSION['flash_error'] = $error;
    }

    header('Location: vehicle-requests.php');
    exit;
}

// ── Cancel a booking (e.g. the passenger details changed) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    $id     = (int) ($_POST['request_id'] ?? 0);
    $reason = trim((string) ($_POST['cancel_reason'] ?? ''));
    $req    = ($id > 0 && $barangay !== '')
        ? db_select_one('vehicle_requests', 'request_id = ? AND barangay_id = ?', [$id, (int) $currentUser['barangay_id']])
        : null;

    if (!vr_csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired. Please try again.';
    } elseif (!$req) {
        $_SESSION['flash_error'] = 'Vehicle request not found.';
    } elseif (!in_array($req['status'], VR_CANCELLABLE, true)) {
        $_SESSION['flash_error'] = "{$req['request_code']} is already {$req['status']} and can't be cancelled.";
    } elseif ($req['requested_date'] < vr_today()->format('Y-m-d')) {
        $_SESSION['flash_error'] = "{$req['request_code']} is for a past date and can't be cancelled.";
    } elseif (mb_strlen($reason) > 200) {
        $_SESSION['flash_error'] = 'The reason must be 200 characters or fewer.';
    } else {
        $in = implode(',', array_fill(0, count(VR_CANCELLABLE), '?'));
        $changed = db_update('vehicle_requests', [
            'status'        => 'Cancelled',
            'cancelled_by'  => $userId,
            'cancelled_at'  => gmdate('c'),
            'cancel_reason' => $reason !== '' ? $reason : null,
            'updated_at'    => gmdate('c'),
        ], "request_id = ? AND status IN ({$in})", array_merge([$id], VR_CANCELLABLE));

        if ($changed === 0) {
            $_SESSION['flash_error'] = "{$req['request_code']} was just updated by the MDRRMO. Please check its status.";
        } else {
            $_SESSION['flash_success'] = "Vehicle request {$req['request_code']} cancelled. You can now submit a new request with the updated details.";
        }
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
$scope    = $barangay !== '' ? 'barangay_id = ?' : '1=0';
$scopeArg = $barangay !== '' ? [(int) $currentUser['barangay_id']] : [];

$counts   = vr_status_counts($scope, $scopeArg);
$requests = db_query(
    "SELECT v.*, h.name AS hospital_name, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS requester_name
     FROM vehicle_requests v
     LEFT JOIN hospitals h ON h.hospital_id = v.hospital_id
     LEFT JOIN users u ON u.user_id::text = v.requested_by
     WHERE v." . ($barangay !== '' ? 'barangay_id = ?' : '1=0') . "
     ORDER BY v.created_at DESC, v.request_id DESC",
    $scopeArg
)->fetchAll();
$hospitals = vr_hospitals();
$avail     = $barangay !== '' ? vr_availability() : ['today' => vr_today()->format('Y-m-d'), 'slots' => VR_TIME_SLOTS, 'dates' => []];
$visible  = 5;
?>

<div class="vr-page">
    <div class="vr-head">
        <div class="assigned-label">Assigned Barangay:<strong><?= vr_h($barangay ?: '—') ?></strong></div>
        <button class="btn btn-primary" onclick="openModal('createVehicleModal')" <?= $barangay === '' ? 'disabled' : '' ?>>Create Vehicle Request</button>
    </div>

    <?php if ($successMsg): ?><div class="alert alert-success mb"><?= vr_h($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="alert alert-danger mb"><?= vr_h($errorMsg) ?></div><?php endif; ?>
    <?php if ($barangay === ''): ?>
        <div class="alert alert-warning mb">Your account has no barangay assigned, so you can't create requests yet. Please contact an MDRRMO Officer.</div>
    <?php endif; ?>

    <div class="vr-section-title">Request Summary</div>
    <div class="grid g4 md-stats">
        <?php foreach (['Approved', 'Pending', 'Scheduled', 'Completed'] as $s): ?>
            <div class="card sw-summary-card">
                <div class="stat-label"><?= $s ?></div>
                <div class="stat-value"><?= $counts[$s] ?></div>
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
                        <th>Purpose</th>
                        <th>Passenger Name</th>
                        <th>Hospital</th>
                        <th>Requested Date</th>
                        <th>Requested Time</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th class="vr-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$requests): ?>
                        <tr><td colspan="8" class="empty">You haven't made any vehicle requests yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($requests as $i => $r): ?>
                        <tr class="<?= $i >= $visible ? 'vr-extra' : '' ?>" <?= $i >= $visible ? 'hidden' : '' ?>>
                            <td class="vr-wrap"><?= vr_h($r['purpose']) ?></td>
                            <td class="vr-wrap"><?= $r['passenger_name'] ? vr_h($r['passenger_name']) : '<span class="vr-muted">—</span>' ?></td>
                            <td class="vr-wrap vr-wrap-wide"><?= $r['hospital_name'] ? vr_h($r['hospital_name']) : '<span class="vr-muted">—</span>' ?></td>
                            <td><?= vr_h(vr_date($r['requested_date'])) ?></td>
                            <td><?= vr_h(vr_time12($r['requested_time'])) ?></td>
                            <td><?= vr_status_badge($r['status']) ?></td>
                            <td class="vr-wrap"><?= $r['notes'] ? vr_h($r['notes']) : '<span class="vr-muted">—</span>' ?></td>
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
            <button type="button" class="icon-btn" onclick="closeModal('createVehicleModal')" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>
        <form method="post" class="vr-form" id="vrCreateForm" novalidate>
            <input type="hidden" name="action" value="create">
            <?= vr_csrf_field() ?>

            <div class="field full">
                <label for="vrPurpose">Purpose</label>
                <select name="purpose" id="vrPurpose" required>
                    <option value="" disabled selected>Select purpose</option>
                    <?php foreach (VR_PURPOSES as $p): ?><option><?= vr_h($p) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="vrPassenger">Passenger name</label>
                <input type="text" name="passenger_name" id="vrPassenger" maxlength="100" placeholder="Enter passenger name" autocomplete="off" required>
            </div>
            <div class="field">
                <label for="vrHospital">Hospital</label>
                <select name="hospital_id" id="vrHospital" required>
                    <option value="" disabled selected>Select hospital</option>
                    <?php foreach ($hospitals as $h): ?><option value="<?= (int) $h['hospital_id'] ?>"><?= vr_h($h['name']) ?></option><?php endforeach; ?>
                </select>
            </div>

            <div class="field full">
                <label>Requested date</label>
                <div class="vr-cal" id="vrCal">
                    <div class="vr-cal-head">
                        <strong id="vrCalTitle"></strong>
                        <div class="vr-cal-tools">
                            <span class="vr-legend"><i class="vr-lg ok"></i>Available</span>
                            <span class="vr-legend"><i class="vr-lg no"></i>Unavailable</span>
                            <button type="button" class="vr-nav" id="vrPrev" aria-label="Previous month">‹</button>
                            <button type="button" class="vr-nav" id="vrNext" aria-label="Next month">›</button>
                        </div>
                    </div>
                    <div class="vr-cal-grid vr-cal-dow"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
                    <div class="vr-cal-grid" id="vrCalGrid"></div>
                    <div class="vr-cal-foot" id="vrCalFoot">Select an available date.</div>
                </div>
                <input type="hidden" name="requested_date" id="vrDate">
            </div>

            <div class="field full">
                <label>Requested time <span class="vr-date-tag" id="vrDateTag"></span></label>
                <div class="vr-slots">
                    <div class="vr-slots-head"><span>Time slot</span><span>Availability</span></div>
                    <div id="vrSlotRows"><div class="vr-slot-empty">Choose a date first.</div></div>
                </div>
            </div>

            <div class="field full">
                <label for="vrNotes">Notes</label>
                <textarea name="notes" id="vrNotes" maxlength="500" rows="3" placeholder="Add transport details or special requirements"></textarea>
            </div>

            <div class="vr-form-error full" id="vrFormError" role="alert" hidden></div>
            <div class="vr-actions">
                <button type="submit" class="btn btn-primary">Submit</button>
            </div>
        </form>
    </div>
</div>
<script type="application/json" id="vrAvail"><?= json_encode($avail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="../assets/vehicle-request.js"></script>

<!-- View request details -->
<?php foreach ($requests as $r): ?>
    <div id="viewVehicle-<?= (int) $r['request_id'] ?>" class="modal">
        <div class="modal-box vr-modal">
            <div class="modal-head">
                <div>
                    <h2><?= vr_h($r['request_code']) ?></h2>
                    <div class="mini">Submitted <?= vr_h(vr_date(substr($r['created_at'], 0, 10))) ?> by <?= vr_h($r['requester_name'] ?: 'Unknown user') ?></div>
                </div>
                <button type="button" class="icon-btn" onclick="closeModal('viewVehicle-<?= (int) $r['request_id'] ?>')" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
            </div>
            <div class="vr-form">
                <div class="field"><label>Status</label><div class="vr-readonly"><?= vr_status_badge($r['status']) ?></div></div>
                <div class="field"><label>Assigned vehicle</label><div class="vr-readonly"><?= vr_vehicle_label($r) !== '' ? vr_h(vr_vehicle_label($r)) : '<span class="vr-muted">Not yet assigned</span>' ?></div></div>
                <div class="field full"><label>Purpose</label><div class="vr-readonly"><?= vr_h($r['purpose']) ?></div></div>
                <div class="field"><label>Passenger name</label><div class="vr-readonly"><?= $r['passenger_name'] ? vr_h($r['passenger_name']) : '<span class="vr-muted">—</span>' ?></div></div>
                <div class="field"><label>Hospital</label><div class="vr-readonly"><?= $r['hospital_name'] ? vr_h($r['hospital_name']) : '<span class="vr-muted">—</span>' ?></div></div>
                <div class="field"><label>Requested date</label><div class="vr-readonly"><?= vr_h(vr_date($r['requested_date'])) ?></div></div>
                <div class="field"><label>Requested time</label><div class="vr-readonly"><?= vr_h(vr_time12($r['requested_time'])) ?></div></div>
                <div class="field full"><label>Notes</label><div class="vr-readonly vr-notes"><?= $r['notes'] ? vr_h($r['notes']) : '<span class="vr-muted">No notes</span>' ?></div></div>
                <?php if ($r['status'] === 'Cancelled'): ?>
                    <div class="field full"><label>Cancellation reason</label><div class="vr-readonly vr-notes"><?= !empty($r['cancel_reason']) ? vr_h($r['cancel_reason']) : '<span class="vr-muted">No reason given</span>' ?></div></div>
                <?php endif; ?>
                <div class="vr-actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('viewVehicle-<?= (int) $r['request_id'] ?>')">Close</button>
                </div>
            </div>
            <?php if (in_array($r['status'], VR_CANCELLABLE, true) && $r['requested_date'] >= vr_today()->format('Y-m-d')): ?>
                <form method="post" class="vr-cancel-box" onsubmit="return confirm('Cancel <?= vr_h($r['request_code']) ?>? The time slot will be released.');">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
                    <?= vr_csrf_field() ?>
                    <div class="vr-cancel-title">Need to change something?</div>
                    <div class="vr-cancel-text">
                        Cancel this booking, then submit a new request with the correct details.
                        <?php if ($r['status'] !== 'Pending'): ?><strong>The MDRRMO has already <?= vr_h(strtolower($r['status'])) ?> this request, so please inform them.</strong><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="cr-<?= (int) $r['request_id'] ?>">Reason (optional)</label>
                        <input type="text" id="cr-<?= (int) $r['request_id'] ?>" name="cancel_reason" maxlength="200" placeholder="e.g. Wrong passenger name">
                    </div>
                    <button type="submit" class="btn btn-reject">Cancel booking</button>
                </form>
            <?php endif; ?>
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

<?php page_end(); ?>
