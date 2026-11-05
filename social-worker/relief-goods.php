<?php
require_once '../includes/access.php'; require_page_access(); require '../includes/layout.php';
require_once '../includes/relief.php';
rg_ensure_tables();

// Read-only page: a Social Worker only sees what the MD Officer sent to their assigned barangay.
$currentUser = db_user_with_barangay($_SESSION['user']['id'] ?? null);
$barangayId  = (int) ($currentUser['barangay_id'] ?? 0);
$barangay    = trim((string) ($currentUser['address'] ?? ''));

$GLOBALS['page_updated_only'] = true;
page_start('Relief Goods', 'Social Work Officer · View relief goods received from MD');

$totals = $rows = [];
$total = 0; $page = 1; $totalPages = 1;
if ($barangayId > 0) {
    $totals = rg_totals($barangayId, true);   // what the barangay has actually received (Completed)
    $f = rg_filters(['page' => $_GET['page'] ?? 1]);
    [$rows, $total, $page, $totalPages] = rg_list($f, $barangayId);
}
?>

<div class="rg-page vr-page">

    <div class="rg-sw-meta">
        <div>Assigned Barangay: <strong><?= $barangay !== '' ? md_h($barangay) : '—' ?></strong></div>
        <div class="rg-sw-source">Source: Municipal Disaster Office (MD)</div>
    </div>

    <?php if ($barangayId === 0): ?>
        <div class="alert alert-danger rg-alert">Your account has no barangay assigned. Please contact an MDRRMO Officer.</div>
    <?php else: ?>

        <h2 class="rg-section-title">Received Goods Summary</h2>
        <div class="rg-sums">
            <?php foreach ($totals as $item => $t): ?>
                <div class="rg-sum">
                    <div class="rg-sum-value"><?= md_h(rg_total_label($item, $t['value'])) ?></div>
                    <div class="rg-sum-label"><?= md_h($item) ?></div>
                    <div class="rg-sum-unit"><?= number_format($t['recipients']) ?> recipients</div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="vr-table-head">
            <div>
                <div class="vr-section-title" style="margin-bottom:0">Relief Goods Received from MD</div>
                <div class="mini">Received distributions for <?= md_h(rg_brgy_label($barangay)) ?>. Records are maintained by the MD Office.</div>
            </div>
            <span class="rg-readonly">Read-only</span>
        </div>

        <div class="sw-data-table">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>No. of Recipients</th>
                            <th>Date</th>
                            <th>Time of Day</th>
                            <th>Distributed By</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="6" class="empty">No relief goods have been distributed to your barangay yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?= md_h($r['item']) ?><div class="mini"><?= md_h(rg_detail($r)) ?></div></td>
                                <td><?= number_format((int) $r['recipients']) ?></td>
                                <td><?= md_h(rg_date_label($r['date_released'])) ?></td>
                                <td><?= md_h(rg_time_label($r['distribution_time'] ?? '')) ?></td>
                                <td>
                                    <?php if ($r['distributor'] !== null && $r['distributor'] !== ''): ?>
                                        <strong><?= md_h($r['distributor']) ?></strong>
                                        <div class="mini">MD Officer</div>
                                    <?php else: ?>
                                        <span class="vr-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= status_badge($r['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($total > 0): ?>
            <div class="mini" style="margin-top:10px">Showing <?= $totalPages === 1 ? 'all ' . $total : count($rows) . ' of ' . $total ?> distribution<?= $total === 1 ? '' : 's' ?> &middot; Status is updated by MD</div>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
            <div class="vr-pager">
                <?php if ($page > 1): ?><a class="btn btn-light" href="<?= md_h(rg_url($f, ['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
                <span class="mini">Page <?= $page ?> of <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?><a class="btn btn-light" href="<?= md_h(rg_url($f, ['page' => $page + 1])) ?>">Next</a><?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php page_end(); ?>
