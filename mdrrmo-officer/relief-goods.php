<?php
require_once '../includes/access.php'; require_page_access(); require '../includes/layout.php';
require_once '../includes/relief.php';
rg_ensure_tables();

// ── POST actions (POST → redirect → GET) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = (string) ($_POST['action'] ?? '');
    $backTo  = rg_url(rg_filters(['q' => $_POST['q'] ?? '', 'date' => $_POST['date'] ?? '', 'status' => $_POST['f_status'] ?? '', 'page' => $_POST['page'] ?? 1]));

    if (!md_csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired. Please try again.';
    } elseif ($action === 'distribute') {
        [$ok, $msg] = rg_record_distribution($_POST, (string) ($_SESSION['user']['id'] ?? ''));
        $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $msg;
        if (!$ok) $_SESSION['rg_old'] = array_intersect_key($_POST, array_flip(['barangay', 'item', 'recipients', 'quantity', 'amount', 'material_name', 'date_released', 'distribution_time', 'description', 'received_by']));
        $backTo = 'relief-goods.php';
    } elseif ($action === 'update') {
        $editId = (int) ($_POST['distribution_id'] ?? 0);
        [$ok, $msg] = rg_update_distribution($editId, $_POST);
        $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $msg;
        if (!$ok) $_SESSION['rg_old'] = array_intersect_key($_POST, array_flip(['distribution_id', 'barangay', 'item', 'recipients', 'quantity', 'amount', 'material_name', 'date_released', 'distribution_time', 'description', 'received_by']));
    } elseif ($action === 'set_status') {
        [$ok, $msg] = rg_set_status((int) ($_POST['distribution_id'] ?? 0), (string) ($_POST['status'] ?? ''));
        $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $msg;
    }
    header('Location: ' . $backTo);
    exit;
}

$GLOBALS['page_updated_only'] = true;
page_start('Relief Goods & Resource Allocation');

$successMsg = $_SESSION['flash_success'] ?? null;
$errorMsg   = $_SESSION['flash_error'] ?? null;
$old        = $_SESSION['rg_old'] ?? [];
unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['rg_old']);

$totals = rg_totals();
$f = rg_filters($_GET);
[$rows, $total, $page, $totalPages] = rg_list($f);
$totalAll  = (int) db_query('SELECT COUNT(*) FROM relief_distributions')->fetchColumn();
$filtering = $f['q'] !== '' || $f['status'] !== '' || $f['date'] !== '';
$today   = today_ph();
$oldDist = $errorMsg ? $old : [];   // after a failed save, reopen the popup with what was typed
?>

<div class="rg-page vr-page">

    <?php if ($successMsg): ?><div class="alert alert-success rg-alert"><?= md_h($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="alert alert-danger rg-alert"><?= md_h($errorMsg) ?></div><?php endif; ?>

    <div class="page-head rg-head">
        <h2 class="rg-title">Relief Goods &amp; Resource Allocation</h2>
        <button type="button" class="btn btn-primary rg-add" onclick="rgNew()">
            Distribute Goods
        </button>
    </div>

    <div class="grid g3 mb">
        <?php foreach ($totals as $item => $t): ?>
            <div class="card sw-summary-card">
                <div class="stat-label"><?= md_h($item) ?></div>
                <div class="stat-value"><?= md_h(rg_total_label($item, $t['value'])) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="vr-table-head">
        <div>
            <div class="vr-section-title" style="margin-bottom:0">Distribution Tracking</div>
            <div class="mini">Record, update, and monitor relief goods released to barangays.</div>
        </div>
        <div class="mini">Showing <?= count($rows) ?> of <?= $total ?><?= $filtering ? ' matching' : '' ?> distributions</div>
    </div>

    <form method="get" class="vr-filters" id="rgFilters">
        <label class="sw-search vr-search">
            <span aria-hidden="true"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
            <input type="search" name="q" value="<?= md_h($f['q']) ?>" placeholder="Search distribution ID, barangay, item, or recipient" maxlength="100">
        </label>
        <select name="status" onchange="this.form.submit()" aria-label="Filter by status">
            <option value="">All statuses</option>
            <?php foreach (RG_STATUSES as $s): ?><option <?= $f['status'] === $s ? 'selected' : '' ?>><?= md_h($s) ?></option><?php endforeach; ?>
        </select>
        <input type="date" name="date" max="<?= $today ?>" value="<?= md_h($f['date']) ?>" onchange="this.form.submit()" aria-label="Filter by distribution date" title="Distribution date">
        <?php if ($filtering): ?><a class="vr-link" href="relief-goods.php">Clear</a><?php endif; ?>
    </form>

    <div class="sw-data-table">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Brgy</th>
                        <th>Item</th>
                        <th>No. of Recipients</th>
                        <th>Date</th>
                        <th>Time of Day</th>
                        <th>Received By</th>
                        <th>Status</th>
                        <th class="vr-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="8" class="empty"><?= $totalAll === 0 ? 'No distributions recorded yet. Click “Distribute Goods” to add the first one.' : 'No distributions match your filters.' ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><strong><?= md_h(rg_brgy_label($r['barangay'])) ?></strong></td>
                            <td><?= md_h($r['item']) ?><div class="mini"><?= md_h(rg_detail($r)) ?></div></td>
                            <td><?= number_format((int) $r['recipients']) ?></td>
                            <td><?= md_h(rg_date_label($r['date_released'])) ?></td>
                            <td><?= md_h(rg_time_label($r['distribution_time'] ?? '')) ?></td>
                            <td><?= $r['received_by'] ? md_h($r['received_by']) : '<span class="vr-muted">—</span>' ?></td>
                            <td>
                                <form method="post" action="relief-goods.php" class="rg-status-form">
                                    <?= md_csrf_field() ?>
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="distribution_id" value="<?= (int) $r['distribution_id'] ?>">
                                    <input type="hidden" name="page" value="<?= $page ?>">
                                    <input type="hidden" name="f_status" value="<?= md_h($f['status']) ?>">
                                    <input type="hidden" name="q" value="<?= md_h($f['q']) ?>">
                                    <input type="hidden" name="date" value="<?= md_h($f['date']) ?>">
                                    <select name="status" class="rg-status" onchange="this.form.submit()" aria-label="Status for <?= md_h(rg_brgy_label($r['barangay'])) ?>, <?= md_h($r['item']) ?>">
                                        <?php foreach (RG_STATUSES as $s): ?>
                                            <option value="<?= md_h($s) ?>" <?= $r['status'] === $s ? 'selected' : '' ?>><?= md_h($s) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <noscript><button class="btn btn-light">Save</button></noscript>
                                </form>
                            </td>
                            <td class="vr-right">
                                <button type="button" class="btn btn-dark-sm" onclick="rgEdit(this)" data-row="<?= md_h(json_encode([
                                    'distribution_id'   => (int) $r['distribution_id'],
                                    'barangay'          => $r['barangay'],
                                    'item'              => $r['item'],
                                    'recipients'        => (int) $r['recipients'],
                                    'quantity'          => $r['quantity'],
                                    'amount'            => $r['amount'] !== null ? rtrim(rtrim((string) $r['amount'], '0'), '.') : '',
                                    'material_name'     => $r['material_name'],
                                    'date_released'     => substr((string) $r['date_released'], 0, 10),
                                    'distribution_time' => $r['distribution_time'] ? substr((string) $r['distribution_time'], 0, 5) : '',
                                    'description'       => $r['description'],
                                    'received_by'       => $r['received_by'],
                                ], JSON_UNESCAPED_UNICODE)) ?>" aria-label="Edit distribution for <?= md_h(rg_brgy_label($r['barangay'])) ?>, <?= md_h($r['item']) ?>">Edit</button>
                            </td>
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

    <div id="reliefModal" class="modal rg-modal-overlay<?= $oldDist ? ' show' : '' ?>" onclick="if(event.target===this)closeModal('reliefModal')">
        <div class="modal-box rg-modal rg-modal-wide" role="dialog" aria-modal="true" aria-labelledby="reliefModalTitle">
            <div class="rg-modal-head">
                <h3 id="reliefModalTitle" data-new="Distribute Relief Goods" data-edit="Edit Distribution">Distribute Relief Goods</h3>
                <button type="button" class="rg-close" onclick="closeModal('reliefModal')" aria-label="Close">
                    <svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <form method="post" action="relief-goods.php">
                <?= md_csrf_field() ?>
                <input type="hidden" name="action" id="rgAction" value="distribute">
                <input type="hidden" name="distribution_id" id="rgId" value="<?= md_h($oldDist['distribution_id'] ?? '') ?>">
                <input type="hidden" name="page" value="<?= $page ?>">
                <input type="hidden" name="f_status" value="<?= md_h($f['status']) ?>">
                <input type="hidden" name="q" value="<?= md_h($f['q']) ?>">
                <input type="hidden" name="date" value="<?= md_h($f['date']) ?>">
                <div class="rg-form">
                    <div class="rg-field">
                        <label for="rgBrgy">Brgy</label>
                        <select id="rgBrgy" name="barangay" required>
                            <option value="">Select barangay</option>
                            <?php foreach (brgy_names() as $b): ?>
                                <option value="<?= md_h($b) ?>" <?= strcasecmp((string) ($oldDist['barangay'] ?? ''), $b) === 0 ? 'selected' : '' ?>><?= md_h($b) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rg-field">
                        <label for="rgItem">Item</label>
                        <select id="rgItem" name="item" required>
                            <?php foreach (array_keys(RG_ITEMS) as $it): ?>
                                <option value="<?= md_h($it) ?>" <?= ($oldDist['item'] ?? 'Food Packs') === $it ? 'selected' : '' ?>><?= md_h($it) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rg-field">
                        <label for="rgQty">No. of Recipients</label>
                        <input id="rgQty" name="recipients" type="number" inputmode="numeric" min="1" max="<?= RG_MAX_QTY ?>" step="1" required value="<?= md_h($oldDist['recipients'] ?? '') ?>">
                    </div>
                    <div class="rg-field">
                        <label for="rgDate">Distribution Date</label>
                        <input id="rgDate" name="date_released" type="date" required max="<?= $today ?>" value="<?= md_h($oldDist['date_released'] ?? '') ?>">
                    </div>
                    <div class="rg-field rg-full">
                        <label for="rgTime">Distribution Time</label>
                        <input id="rgTime" name="distribution_time" type="time" required value="<?= md_h($oldDist['distribution_time'] ?? '') ?>">
                    </div>
                    <div class="rg-field rg-full rg-item-fields" data-for="Food Packs">
                        <label for="rgPacks">Quantity <span class="rg-unit">(food packs)</span></label>
                        <input id="rgPacks" name="quantity" type="number" inputmode="numeric" min="1" max="<?= RG_MAX_QTY ?>" step="1" value="<?= md_h(($oldDist['item'] ?? 'Food Packs') === 'Food Packs' ? ($oldDist['quantity'] ?? '') : '') ?>">
                    </div>
                    <div class="rg-field rg-full rg-item-fields" data-for="Materials" hidden>
                        <label for="rgMaterial">Material</label>
                        <input id="rgMaterial" name="material_name" maxlength="100" placeholder="e.g. Tarpaulin, blankets, hygiene kits" autocomplete="off" value="<?= md_h($oldDist['material_name'] ?? '') ?>">
                    </div>
                    <div class="rg-field rg-full rg-item-fields" data-for="Materials" hidden>
                        <label for="rgUnits">Quantity <span class="rg-unit">(units)</span></label>
                        <input id="rgUnits" name="quantity" type="number" inputmode="numeric" min="1" max="<?= RG_MAX_QTY ?>" step="1" value="<?= md_h(($oldDist['item'] ?? '') === 'Materials' ? ($oldDist['quantity'] ?? '') : '') ?>">
                    </div>
                    <div class="rg-field rg-full rg-item-fields" data-for="Cash Assistance" hidden>
                        <label for="rgAmount">Amount <span class="rg-unit">(₱ pesos)</span></label>
                        <input id="rgAmount" name="amount" type="text" inputmode="decimal" pattern="\d{1,9}(\.\d{1,2})?" placeholder="0.00" value="<?= md_h($oldDist['amount'] ?? '') ?>">
                    </div>
                    <div class="rg-field rg-full">
                        <label for="rgDesc">Description</label>
                        <input id="rgDesc" name="description" maxlength="255" value="<?= md_h($oldDist['description'] ?? '') ?>">
                    </div>
                    <div class="rg-field rg-full">
                        <label for="rgBy">Received By</label>
                        <input id="rgBy" name="received_by" maxlength="150" value="<?= md_h($oldDist['received_by'] ?? '') ?>">
                    </div>
                </div>
                <div class="actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('reliefModal')">Cancel</button>
                    <button class="btn btn-primary rg-submit" id="rgSubmit" data-new="Record Distribution" data-edit="Save Changes">Record Distribution</button>
                </div>
            </form>
        </div>
    </div>

</div>

<script>
// The fields under "Item" change with the chosen item (hidden ones are disabled so they are not submitted).
(function () {
    var sel = document.getElementById('rgItem');
    if (!sel) return;
    var groups = document.querySelectorAll('.rg-item-fields');
    function sync() {
        groups.forEach(function (g) {
            var on = g.getAttribute('data-for') === sel.value;
            g.hidden = !on;
            g.querySelectorAll('input').forEach(function (i) { i.disabled = !on; i.required = on; });
        });
    }
    sel.addEventListener('change', sync);
    sync();

    var form = document.querySelector('#reliefModal form');
    var title = document.getElementById('reliefModalTitle');
    var submit = document.getElementById('rgSubmit');
    function mode(edit) {
        document.getElementById('rgAction').value = edit ? 'update' : 'distribute';
        title.textContent = title.getAttribute(edit ? 'data-edit' : 'data-new');
        submit.textContent = submit.getAttribute(edit ? 'data-edit' : 'data-new');
    }
    function fill(d) {
        document.getElementById('rgId').value = d.distribution_id || '';
        document.getElementById('rgBrgy').value = d.barangay || '';
        sel.value = d.item || 'Food Packs';
        document.getElementById('rgQty').value = d.recipients || '';
        document.getElementById('rgDate').value = d.date_released || '';
        document.getElementById('rgTime').value = d.distribution_time || '';
        document.getElementById('rgPacks').value = d.item === 'Food Packs' ? (d.quantity || '') : '';
        document.getElementById('rgMaterial').value = d.material_name || '';
        document.getElementById('rgUnits').value = d.item === 'Materials' ? (d.quantity || '') : '';
        document.getElementById('rgAmount').value = d.amount || '';
        document.getElementById('rgDesc').value = d.description || '';
        document.getElementById('rgBy').value = d.received_by || '';
        sync();
    }
    window.rgNew = function () { form.reset(); fill({}); mode(false); openModal('reliefModal'); };
    window.rgEdit = function (btn) { fill(JSON.parse(btn.getAttribute('data-row'))); mode(true); openModal('reliefModal'); };
    <?php if ($oldDist && !empty($oldDist['distribution_id'])): ?>mode(true);<?php endif; ?>
})();
</script>

<?php page_end(); ?>
