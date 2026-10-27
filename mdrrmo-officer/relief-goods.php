<?php
require_once '../includes/access.php'; require_page_access(); require '../includes/layout.php';
require_once '../includes/relief.php';
rg_ensure_tables();

// ── Record a distribution (POST → redirect → GET) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'distribute') {
    if (!md_csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired. Please try again.';
    } else {
        [$ok, $msg] = rg_record_distribution($_POST, (string) ($_SESSION['user']['id'] ?? ''));
        $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $msg;
        if (!$ok) $_SESSION['rg_old'] = array_intersect_key($_POST, array_flip(['barangay', 'item', 'quantity', 'date_released', 'received_by']));
    }
    header('Location: relief-goods.php');
    exit;
}

$GLOBALS['page_live_status'] = true;
page_start('Relief Goods & Resource Allocation');

$successMsg = $_SESSION['flash_success'] ?? null;
$errorMsg   = $_SESSION['flash_error'] ?? null;
$old        = $_SESSION['rg_old'] ?? [];
unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['rg_old']);

$stock   = rg_totals();
<<<<<<< HEAD
$f = rg_filters($_GET);
[$rows, $total, $page, $totalPages] = rg_search($f);
$totalAll  = (int) db_query('SELECT COUNT(*) FROM relief_distributions')->fetchColumn();
$filtering = $f['q'] !== '' || $f['item'] !== '' || $f['status'] !== '' || $f['date'] !== '';
=======
$rows    = rg_distributions();
>>>>>>> b533edfc890dc8aff1b7f2c3d3319a2016a45c25
$today   = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$oldDist = $errorMsg ? $old : [];   // after a failed save, reopen the popup with what was typed
?>

<<<<<<< HEAD
<div class="rg-page vr-page">
=======
<div class="rg-page">
>>>>>>> b533edfc890dc8aff1b7f2c3d3319a2016a45c25

    <?php if ($successMsg): ?><div class="alert alert-success rg-alert"><?= md_h($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="alert alert-danger rg-alert"><?= md_h($errorMsg) ?></div><?php endif; ?>

    <div class="page-head rg-head">
        <h2 class="rg-title">Relief Goods &amp; Resource Allocation</h2>
        <button type="button" class="btn btn-primary rg-add" onclick="openModal('reliefModal')">
            Distribute Goods
        </button>
    </div>
<<<<<<< HEAD
=======
    </div>
>>>>>>> b533edfc890dc8aff1b7f2c3d3319a2016a45c25

    <div class="grid g4 md-stats">
        <?php foreach ($stock as $item => $st): ?>
            <div class="card sw-summary-card">
                <div class="stat-label"><?= md_h($item) ?> &middot; <?= md_h($st['unit']) ?></div>
                <div class="stat-value"><?= number_format($st['quantity']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

<<<<<<< HEAD
    <div class="vr-table-head">
        <div class="vr-section-title" style="margin-bottom:0">Distribution Tracking</div>
        <div class="mini">Showing <?= count($rows) ?> of <?= $total ?><?= $filtering ? ' matching' : '' ?> distributions</div>
    </div>

    <form method="get" class="vr-filters" id="rgFilters">
        <label class="sw-search vr-search">
            <span aria-hidden="true"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
            <input type="search" name="q" value="<?= md_h($f['q']) ?>" placeholder="Search distribution ID, barangay, or item" maxlength="100">
        </label>
        <select name="status" onchange="this.form.submit()" aria-label="Filter by status">
            <option value="">All statuses</option>
            <?php foreach (RG_STATUSES as $s): ?><option <?= $f['status'] === $s ? 'selected' : '' ?>><?= md_h($s) ?></option><?php endforeach; ?>
        </select>
        <select name="item" onchange="this.form.submit()" aria-label="Filter by item">
            <option value="">All items</option>
            <?php foreach (array_keys(RG_ITEMS) as $it): ?><option <?= $f['item'] === $it ? 'selected' : '' ?>><?= md_h($it) ?></option><?php endforeach; ?>
        </select>
        <input type="date" name="date" value="<?= md_h($f['date']) ?>" onchange="this.form.submit()" aria-label="Filter by date released">
        <?php if ($filtering): ?><a class="vr-link" href="relief-goods.php">Clear</a><?php endif; ?>
    </form>

    <div class="sw-data-table">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
=======
    <section class="rg-panel">
        <h3 class="rg-panel-title">Distribution Tracking</h3>
        <div class="rg-table-wrap">
            <table class="rg-table">
                <thead>
                    <tr>
                        <th>Distribution ID</th>
>>>>>>> b533edfc890dc8aff1b7f2c3d3319a2016a45c25
                        <th>Barangay</th>
                        <th>Item</th>
                        <th>Quantity</th>
                        <th>Date Released</th>
                        <th>Status</th>
                        <th>Received By</th>
                    </tr>
                </thead>
                <tbody>
<<<<<<< HEAD
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" class="empty"><?= $totalAll === 0 ? 'No distributions recorded yet. Click “Distribute Goods” to add the first one.' : 'No distributions match your filters.' ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><strong><?= md_h($r['distribution_code'] ?: 'RD-' . str_pad((string) $r['distribution_id'], 3, '0', STR_PAD_LEFT)) ?></strong></td>
                            <td><?= md_h($r['barangay']) ?></td>
                            <td><?= md_h($r['item']) ?></td>
                            <td><?= number_format((int) $r['quantity']) ?></td>
                            <td><?= md_h(date('j M Y', strtotime(substr((string) $r['date_released'], 0, 10)))) ?></td>
                            <td><?= status_badge($r['status']) ?></td>
                            <td><?= $r['received_by'] ? md_h($r['received_by']) : '<span class="vr-muted">—</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="vr-pager">
            <?php if ($page > 1): ?><a class="btn btn-light" href="<?= md_h(rg_url($f, ['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
            <span class="mini">Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?><a class="btn btn-light" href="<?= md_h(rg_url($f, ['page' => $page + 1])) ?>">Next</a><?php endif; ?>
        </div>
    <?php endif; ?>
=======
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="rg-id"><?= md_h($r['distribution_code'] ?: 'RD-' . str_pad((string) $r['distribution_id'], 3, '0', STR_PAD_LEFT)) ?></td>
                            <td><?= md_h($r['barangay']) ?></td>
                            <td><?= md_h($r['item']) ?></td>
                            <td><?= number_format((int) $r['quantity']) ?></td>
                            <td><?= md_h(substr((string) $r['date_released'], 0, 10)) ?></td>
                            <td><?= status_badge($r['status']) ?></td>
                            <td><?= md_h($r['received_by'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" class="rg-empty">No distributions recorded yet. Click “Distribute Goods” to add the first one.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
>>>>>>> b533edfc890dc8aff1b7f2c3d3319a2016a45c25

        <div id="reliefModal" class="modal rg-modal-overlay<?= $oldDist ? ' show' : '' ?>" onclick="if(event.target===this)closeModal('reliefModal')">
        <div class="modal-box rg-modal" role="dialog" aria-modal="true" aria-labelledby="reliefModalTitle">
            <div class="rg-modal-head">
                <h3 id="reliefModalTitle">Distribute Relief Goods</h3>
                <button type="button" class="rg-close" onclick="closeModal('reliefModal')" aria-label="Close">Close</button>
            </div>
            <form method="post" action="relief-goods.php">
                <?= md_csrf_field() ?>
                <input type="hidden" name="action" value="distribute">
                <div class="rg-form">
                    <div class="rg-field">
                        <label for="rgBrgy">Barangay</label>
                        <input id="rgBrgy" name="barangay" list="rgBrgyList" maxlength="100" required autocomplete="off" value="<?= md_h($oldDist['barangay'] ?? '') ?>">
                        <datalist id="rgBrgyList"><?php foreach (brgy_names() as $b): ?><option value="<?= md_h($b) ?>"><?php endforeach; ?></datalist>
                    </div>
                    <div class="rg-field">
                        <label for="rgItem">Item</label>
                        <select id="rgItem" name="item">
                            <?php foreach (array_keys(RG_ITEMS) as $it): ?>
                                <option<?= ($oldDist['item'] ?? '') === $it ? ' selected' : '' ?>><?= md_h($it) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rg-field">
                        <label for="rgQty">Quantity</label>
                        <input id="rgQty" name="quantity" type="number" min="1" max="<?= RG_MAX_QTY ?>" step="1" required value="<?= md_h($oldDist['quantity'] ?? '') ?>">
                    </div>
                    <div class="rg-field">
                        <label for="rgDate">Date Released</label>
                        <input id="rgDate" name="date_released" type="date" required max="<?= $today ?>" value="<?= md_h($oldDist['date_released'] ?? '') ?>">
                    </div>
                    <div class="rg-field">
                        <label for="rgBy">Received By</label>
                        <input id="rgBy" name="received_by" maxlength="150" value="<?= md_h($oldDist['received_by'] ?? '') ?>">
                    </div>
                </div>
                <button class="btn btn-primary rg-submit">Record Distribution</button>
            </form>
        </div>
    </div>

</div>

<?php page_end(); ?>
