<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/vehicle.php';

// access.php allows pages by file name only, and Social Workers are allowed a
// "vehicle-requests.php" too — so make sure only officers can use THIS one.
if (($_SESSION['user']['role'] ?? '') !== 'MDRRMO Officer') {
    http_response_code(403);
    exit('Access denied.');
}

vr_ensure_table();
$officerId = (string) ($_SESSION['user']['id'] ?? '');

/** Reads the list filters from GET/POST and validates each one. */
function vr_filters(array $src): array
{
    $status  = $src['status'] ?? '';
    $purpose = $src['purpose'] ?? '';
    return [
        'q'       => mb_substr(trim((string) ($src['q'] ?? '')), 0, 100),
        'status'  => in_array($status, VR_STATUSES, true) ? $status : '',
        'purpose' => in_array($purpose, VR_PURPOSES, true) ? $purpose : '',
        'date'    => vr_parse_date($src['date'] ?? '') ?? '',
        'page'    => max(1, (int) ($src['page'] ?? 1)),
    ];
}

function vr_url(array $filters, array $override = []): string
{
    $f = array_filter(array_merge($filters, $override), fn($v) => $v !== '' && $v !== null && $v !== 1);
    return 'vehicle-requests.php' . ($f ? '?' . http_build_query($f) : '');
}

// ── Review / update a request ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review') {
    $back     = vr_filters($_POST);
    $id       = (int) ($_POST['request_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $vehicle  = trim((string) ($_POST['assigned_vehicle'] ?? ''));
    $error    = null;
    $success  = null;

    $transitions = [
        'approve'  => ['from' => ['Pending'],             'to' => 'Approved',  'vehicle' => true],
        'reject'   => ['from' => ['Pending', 'Approved'], 'to' => 'Rejected',  'vehicle' => false],
        'schedule' => ['from' => ['Approved'],            'to' => 'Scheduled', 'vehicle' => true],
        'complete' => ['from' => ['Scheduled'],           'to' => 'Completed', 'vehicle' => false],
    ];

    $req   = $id > 0 ? db_select_one('vehicle_requests', 'request_id = ?', [$id]) : null;
    $clash = null;

    // Don't double-book a vehicle on the same date.
    if ($req && isset($transitions[$decision]) && $transitions[$decision]['vehicle'] && $vehicle !== '') {
        $clash = db_select_one(
            'vehicle_requests',
            "LOWER(assigned_vehicle) = LOWER(?) AND requested_date = ? AND status IN ('Approved','Scheduled') AND request_id <> ?",
            [$vehicle, $req['requested_date'], $id],
            'request_code'
        );
    }

    if (!vr_csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$req) {
        $error = 'Vehicle request not found.';
    } elseif (!isset($transitions[$decision])) {
        $error = 'Unknown action.';
    } elseif (!in_array($req['status'], $transitions[$decision]['from'], true)) {
        $error = "{$req['request_code']} is already {$req['status']} — it can't be changed that way.";
    } elseif ($transitions[$decision]['vehicle'] && $vehicle === '') {
        $error = 'Enter the assigned vehicle first.';
    } elseif (mb_strlen($vehicle) > 100) {
        $error = 'Assigned vehicle must be 100 characters or fewer.';
    } elseif ($clash) {
        $error = "{$vehicle} is already assigned to {$clash['request_code']} on " . vr_date($req['requested_date']) . '.';
    } else {
        $t    = $transitions[$decision];
        $data = [
            'status'      => $t['to'],
            'reviewed_by' => $officerId,
            'reviewed_at' => gmdate('c'),
            'updated_at'  => gmdate('c'),
        ];
        if ($t['vehicle'])           $data['assigned_vehicle'] = $vehicle;
        if ($decision === 'reject')  $data['assigned_vehicle'] = null;

        $in      = implode(',', array_fill(0, count($t['from']), '?'));
        $changed = db_update('vehicle_requests', $data, "request_id = ? AND status IN ({$in})", array_merge([$id], $t['from']));

        if ($changed === 0) {
            $error = "{$req['request_code']} was just updated by someone else. Please review it again.";
        } else {
            $success = match ($decision) {
                'approve'  => "{$req['request_code']} approved and assigned to {$vehicle}.",
                'reject'   => "{$req['request_code']} rejected.",
                'schedule' => "{$req['request_code']} scheduled with {$vehicle}.",
                'complete' => "{$req['request_code']} marked as completed.",
            };
        }
    }

    if ($error)   $_SESSION['flash_error']   = $error;
    if ($success) $_SESSION['flash_success'] = $success;
    header('Location: ' . vr_url($back));
    exit;
}

// ── Page data ───────────────────────────────────────────────────────────────
require '../includes/layout.php';
page_start('Vehicle Requests', 'Municipal Disaster Officer · Fleet request management');

$successMsg = $_SESSION['flash_success'] ?? null;
$errorMsg   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$f = vr_filters($_GET);

$where  = ['1=1'];
$params = [];
if ($f['q'] !== '') {
    $like = '%' . addcslashes($f['q'], '%_\\') . '%';
    $where[] = "(v.request_code ILIKE ? OR v.barangay ILIKE ? OR v.purpose ILIKE ? OR COALESCE(v.assigned_vehicle, '') ILIKE ? OR CONCAT(u.first_name, ' ', u.last_name) ILIKE ?)";
    array_push($params, $like, $like, $like, $like, $like);
}
if ($f['status'] !== '')  { $where[] = 'v.status = ?';         $params[] = $f['status']; }
if ($f['purpose'] !== '') { $where[] = 'v.purpose = ?';        $params[] = $f['purpose']; }
if ($f['date'] !== '')    { $where[] = 'v.requested_date = ?'; $params[] = $f['date']; }
$whereSql = implode(' AND ', $where);
$join     = 'FROM vehicle_requests v LEFT JOIN users u ON u.user_id::text = v.requested_by';

$total      = (int) db_query("SELECT COUNT(*) {$join} WHERE {$whereSql}", $params)->fetchColumn();
$totalPages = max(1, (int) ceil($total / VR_PER_PAGE));
$page       = min($f['page'], $totalPages);
$offset     = ($page - 1) * VR_PER_PAGE;

$requests = db_query(
    "SELECT v.*, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS requester_name
     {$join} WHERE {$whereSql}
     ORDER BY (v.status = 'Pending') DESC, v.created_at DESC, v.request_id DESC
     LIMIT " . VR_PER_PAGE . " OFFSET {$offset}",
    $params
)->fetchAll();

$counts    = vr_status_counts();
$totalAll  = (int) db_query('SELECT COUNT(*) FROM vehicle_requests')->fetchColumn();
$filtering = $f['q'] !== '' || $f['status'] !== '' || $f['purpose'] !== '' || $f['date'] !== '';
?>

<div class="vr-page">
    <?php if ($successMsg): ?><div class="alert alert-success mb"><?= vr_h($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="alert alert-danger mb"><?= vr_h($errorMsg) ?></div><?php endif; ?>

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
        <div>
            <div class="vr-section-title" style="margin-bottom:2px">Social Worker Vehicle Requests</div>
            <div class="mini">Review, approve, schedule, and track municipal transport support</div>
        </div>
        <div class="mini">Showing <?= count($requests) ?> of <?= $total ?><?= $filtering ? ' matching' : '' ?> requests</div>
    </div>

    <form method="get" class="vr-filters" id="vrFilters">
        <label class="sw-search vr-search">
            <span aria-hidden="true">⌕</span>
            <input type="search" name="q" value="<?= vr_h($f['q']) ?>" placeholder="Search request ID, barangay, or purpose" maxlength="100">
        </label>
        <select name="status" onchange="this.form.submit()" aria-label="Filter by status">
            <option value="">All statuses</option>
            <?php foreach (VR_STATUSES as $s): ?><option <?= $f['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
        </select>
        <select name="purpose" onchange="this.form.submit()" aria-label="Filter by purpose">
            <option value="">All purposes</option>
            <?php foreach (VR_PURPOSES as $p): ?><option <?= $f['purpose'] === $p ? 'selected' : '' ?>><?= vr_h($p) ?></option><?php endforeach; ?>
        </select>
        <input type="date" name="date" value="<?= vr_h($f['date']) ?>" onchange="this.form.submit()" aria-label="Filter by requested date">
        <?php if ($filtering): ?><a class="vr-link" href="vehicle-requests.php">Clear</a><?php endif; ?>
    </form>

    <div class="sw-data-table">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Requested by</th>
                        <th>Barangay</th>
                        <th>Purpose</th>
                        <th>Passengers</th>
                        <th>Urgency</th>
                        <th>Date</th>
                        <th>Assigned vehicle</th>
                        <th>Status</th>
                        <th class="vr-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$requests): ?>
                        <tr><td colspan="9" class="empty"><?= $totalAll === 0 ? 'No vehicle requests have been submitted yet.' : 'No requests match your filters.' ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td>
                                <strong><?= vr_h($r['requester_name'] ?: 'Unknown user') ?></strong>
                                <div class="vr-code"><?= vr_h($r['request_code']) ?></div>
                            </td>
                            <td><?= vr_h($r['barangay']) ?></td>
                            <td><?= vr_h($r['purpose']) ?></td>
                            <td><?= (int) $r['passengers'] ?></td>
                            <td><?= vr_urgency_badge($r['urgency']) ?></td>
                            <td><?= vr_h(vr_date($r['requested_date'], 'j M Y')) ?></td>
                            <td><?= $r['assigned_vehicle'] ? vr_h($r['assigned_vehicle']) : '<span class="vr-muted">—</span>' ?></td>
                            <td><?= vr_status_badge($r['status']) ?></td>
                            <td class="vr-right"><button class="btn btn-dark-sm" onclick="openModal('manageVehicle-<?= (int) $r['request_id'] ?>')">Manage</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="vr-pager">
            <?php if ($page > 1): ?><a class="btn btn-light" href="<?= vr_h(vr_url($f, ['page' => $page - 1])) ?>">← Previous</a><?php endif; ?>
            <span class="mini">Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?><a class="btn btn-light" href="<?= vr_h(vr_url($f, ['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Manage modals -->
<?php foreach ($requests as $r):
    $mid      = 'manageVehicle-' . (int) $r['request_id'];
    $status   = $r['status'];
    $editable = in_array($status, ['Pending', 'Approved'], true);
    $primary  = ['Pending' => ['approve', 'Approve'], 'Approved' => ['schedule', 'Schedule'], 'Scheduled' => ['complete', 'Mark completed']][$status] ?? null;
?>
    <div id="<?= $mid ?>" class="modal">
        <div class="modal-box vr-modal">
            <div class="modal-head">
                <div>
                    <h2>Manage Vehicle Request</h2>
                    <div class="mini"><?= vr_h($r['requester_name'] ?: 'Unknown user') ?> · <?= vr_h($r['barangay']) ?> · <?= vr_h($r['request_code']) ?></div>
                </div>
                <button type="button" class="icon-btn" onclick="closeModal('<?= $mid ?>')" aria-label="Close">×</button>
            </div>

            <form method="post" class="vr-form">
                <input type="hidden" name="action" value="review">
                <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
                <?= vr_csrf_field() ?>
                <?php foreach (['q', 'status', 'purpose', 'date'] as $k): if ($f[$k] !== ''): ?>
                    <input type="hidden" name="<?= $k ?>" value="<?= vr_h($f[$k]) ?>">
                <?php endif; endforeach; ?>
                <input type="hidden" name="page" value="<?= (int) $page ?>">

                <div class="field full vr-assign">
                    <label>Assigned vehicle</label>
                    <?php if ($editable): ?>
                        <input name="assigned_vehicle" value="<?= vr_h($r['assigned_vehicle']) ?>" maxlength="100" placeholder="Enter assigned vehicle">
                    <?php else: ?>
                        <div class="vr-readonly"><?= $r['assigned_vehicle'] ? vr_h($r['assigned_vehicle']) : '<span class="vr-muted">None</span>' ?></div>
                    <?php endif; ?>
                </div>
                <div class="field full"><label>Purpose</label><div class="vr-readonly"><?= vr_h($r['purpose']) ?></div></div>
                <div class="field"><label>Passengers</label><div class="vr-readonly"><?= (int) $r['passengers'] ?></div></div>
                <div class="field"><label>Urgency</label><div class="vr-readonly"><?= vr_h($r['urgency']) ?></div></div>
                <div class="field full"><label>Requested date</label><div class="vr-readonly"><?= vr_h(vr_date($r['requested_date'], 'j M Y')) ?></div></div>
                <div class="field full"><label>Notes</label><div class="vr-readonly vr-notes"><?= $r['notes'] ? vr_h($r['notes']) : '<span class="vr-muted">No notes</span>' ?></div></div>

                <div class="vr-actions vr-actions-split">
                    <button type="button" class="btn btn-light" onclick="closeModal('<?= $mid ?>')"><?= $primary ? 'Cancel' : 'Close' ?></button>
                    <div class="vr-actions-right">
                        <?php if ($editable): ?>
                            <button type="submit" name="decision" value="reject" formnovalidate class="btn btn-reject" onclick="return confirm('Reject <?= vr_h($r['request_code']) ?>?')">Reject</button>
                        <?php endif; ?>
                        <?php if ($primary): ?>
                            <button type="submit" name="decision" value="<?= $primary[0] ?>" class="btn btn-primary"><?= $primary[1] ?></button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<?php page_end(); ?>
