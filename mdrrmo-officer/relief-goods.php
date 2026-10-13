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

// Inline icons (stroke = currentColor) so they pick up the tile colour.
$icons = [
    'box'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3.3 7.5L12 12.4l8.7-4.9M12 12.4V21"/></svg>',
    'water' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3s6 6.2 6 10.5A6 6 0 0 1 6 13.5C6 9.2 12 3 12 3z"/></svg>',
    'heart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg>',
    'kit'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M12 11v5M9.5 13.5h5"/></svg>',
];

$iconFor = ['Food Packs' => $icons['box'], 'Water (Liters)' => $icons['water'], 'Medicine' => $icons['heart'], 'Hygiene Kits' => $icons['kit']];
$stock   = rg_totals();
$rows    = rg_distributions();
$today   = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$oldDist = $errorMsg ? $old : [];   // after a failed save, reopen the popup with what was typed
?>

<div class="rg-page">

    <?php if ($successMsg): ?><div class="alert alert-success rg-alert"><?= md_h($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="alert alert-danger rg-alert"><?= md_h($errorMsg) ?></div><?php endif; ?>

    <div class="page-head rg-head">
        <h2 class="rg-title">Relief Goods &amp; Resource Allocation</h2>
        <button type="button" class="btn btn-primary rg-add" onclick="openModal('reliefModal')">
            <span aria-hidden="true">＋</span> Distribute Goods
        </button>
    </div>
    </div>

    <div class="rg-stats">
        <?php foreach ($stock as $item => $st): ?>
            <div class="rg-stat">
                <span class="rg-stat-icon"><?= $iconFor[$item] ?? $icons['box'] ?></span>
                <div>
                    <div class="rg-stat-value"><?= number_format($st['quantity']) ?></div>
                    <div class="rg-stat-label"><?= md_h($item) ?></div>
                    <div class="rg-stat-unit"><?= md_h($st['unit']) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <section class="rg-panel">
        <h3 class="rg-panel-title">Distribution Tracking</h3>
        <div class="rg-table-wrap">
            <table class="rg-table">
                <thead>
                    <tr>
                        <th>Distribution ID</th>
                        <th>Barangay</th>
                        <th>Item</th>
                        <th>Quantity</th>
                        <th>Date Released</th>
                        <th>Status</th>
                        <th>Received By</th>
                    </tr>
                </thead>
                <tbody>
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

        <div id="reliefModal" class="modal rg-modal-overlay<?= $oldDist ? ' show' : '' ?>" onclick="if(event.target===this)closeModal('reliefModal')">
        <div class="modal-box rg-modal" role="dialog" aria-modal="true" aria-labelledby="reliefModalTitle">
            <div class="rg-modal-head">
                <h3 id="reliefModalTitle">Distribute Relief Goods</h3>
                <button type="button" class="rg-close" onclick="closeModal('reliefModal')" aria-label="Close">✕</button>
            </div>
            <form method="post" action="relief-goods.php">
                <?= md_csrf_field() ?>
                <input type="hidden" name="action" value="distribute">
                <div class="rg-form">
                    <div class="rg-field">
                        <label for="rgBrgy">Barangay</label>
                        <input id="rgBrgy" name="barangay" list="rgBrgyList" maxlength="100" required autocomplete="off" value="<?= md_h($oldDist['barangay'] ?? '') ?>">
                        <datalist id="rgBrgyList"><?php foreach (MD_BARANGAYS as $b): ?><option value="<?= md_h($b) ?>"><?php endforeach; ?></datalist>
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
