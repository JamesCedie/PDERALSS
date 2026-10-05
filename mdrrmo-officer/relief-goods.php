<?php
require_once '../includes/access.php'; require_page_access(); require '../includes/layout.php';
page_start('Relief Goods');

$inventory = [
    ['Food Packs',      2450, 'packs',   500,  '▣'],
    ['Water (Liters)',  3200, 'liters',  1000, '💧'],
    ['Medicine',        890,  'boxes',   200,  '❤'],
    ['Hygiene Kits',    1560, 'kits',    300,  '▣'],
];

$rows = [
    ['RD-001', 'Brgy. Jaro',        'Food Packs',      500, '2026-04-28', 'Completed', 'Brgy. Captain Santos'],
    ['RD-002', 'Brgy. Molo',        'Water (Liters)',  800, '2026-04-29', 'In Transit', '-'],
    ['RD-003', 'Brgy. Mandurriao',  'Hygiene Kits',    300, '2026-04-30', 'Completed', 'Brgy. Kagawad Cruz'],
    ['RD-004', 'Brgy. Arevalo',     'Medicine',        150, '2026-05-01', 'Pending',   '-'],
    ['RD-005', 'Brgy. La Paz',      'Food Packs',      400, '2026-04-27', 'Completed', 'Brgy. Secretary Reyes'],
];
?>

<div class="page-head">
    <h1 class="page-title">Relief Goods & Resource Allocation</h1>
    <button class="btn btn-primary" onclick="openModal('reliefModal')">＋ Distribute Goods</button>
</div>

<div class="grid g4">
    <?php foreach($inventory as $i): ?>
        <div class="card">
            <div class="stat">
                <div class="stat-icon blue"><?=$i[4]?></div>
                <div>
                    <div class="stat-value"><?=number_format($i[1])?></div>
                    <div class="stat-label"><?=$i[0]?></div>
                    <div class="mini"><?=$i[2]?></div>
                </div>
            </div>
            <?php if($i[1] < $i[3]): ?>
                <div class="alert alert-danger mt">Low Stock Warning</div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mt">
    <h2>Distribution Tracking</h2>
    <div class="table-wrap">
        <table class="table">
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
                <?php foreach($rows as $r): ?>
                    <tr>
                        <td><?=$r[0]?></td>
                        <td><?=$r[1]?></td>
                        <td><?=$r[2]?></td>
                        <td><?=number_format($r[3])?></td>
                        <td><?=$r[4]?></td>
                        <td><?=status_badge($r[5])?></td>
                        <td><?=$r[6]?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mt">
    <h2>Inventory Summary by Barangay</h2>
    <div class="grid g3">
        <?php foreach(['Brgy. Jaro' => 1250, 'Brgy. Molo' => 980, 'Brgy. Mandurriao' => 740, 'Brgy. Arevalo' => 630, 'Brgy. La Paz' => 820] as $b => $q): ?>
            <div class="barangay-summary">
                <b><?=$b?></b>
                <div class="mini">Allocated goods</div>
                <div class="barangay-summary-value"><?=number_format($q)?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="reliefModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Distribute Relief Goods</h2>
            <button class="icon-btn" onclick="closeModal('reliefModal')">✕</button>
        </div>
        <form onsubmit="event.preventDefault();alert('Distribution recorded.');closeModal('reliefModal')">
            <div class="form-grid">
                <div class="field">
                    <label>Barangay</label>
                    <input required>
                </div>
                <div class="field">
                    <label>Item</label>
                    <select>
                        <option>Food Packs</option>
                        <option>Water (Liters)</option>
                        <option>Medicine</option>
                        <option>Hygiene Kits</option>
                    </select>
                </div>
                <div class="field">
                    <label>Quantity</label>
                    <input type="number" min="1">
                </div>
                <div class="field">
                    <label>Date Released</label>
                    <input type="date">
                </div>
                <div class="field">
                    <label>Received By</label>
                    <input>
                </div>
            </div>
            <div class="actions mt">
                <button class="btn btn-primary">Record Distribution</button>
            </div>
        </form>
    </div>
</div>

<?php page_end(); ?>
