<?php
require 'includes/layout.php';
page_start('Evacuation Centers');

$centers = [
    ['EC-001', 'Center Alpha',   'Brgy. Jaro',        '500', '475', 'Near Capacity'],
    ['EC-002', 'Center Beta',    'Brgy. Molo',         '400', '220', 'Available'],
    ['EC-003', 'Center Gamma',   'Brgy. Mandurriao',   '350', '180', 'Available'],
    ['EC-004', 'Center Delta',   'Brgy. Arevalo',      '300', '300', 'Full'],
    ['EC-005', 'Center Epsilon', 'Brgy. La Paz',       '450', '240', 'Available'],
];
?>

<div class="page-head">
    <h1 class="page-title">Evacuation Center Management</h1>
    <button class="btn btn-primary" onclick="openModal('centerModal')">＋ Add Evacuation Center</button>
</div>

<div class="grid g4">
    <?php foreach([['12', 'Total Centers', 'blue'], ['8', 'Available', 'green'], ['3', 'Near Capacity', 'yellow'], ['1', 'Full', 'red']] as $s): ?>
        <div class="card">
            <div class="stat">
                <div class="stat-icon <?=$s[2]?>">⌂</div>
                <div>
                    <div class="stat-value"><?=$s[0]?></div>
                    <div class="stat-label"><?=$s[1]?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid g2 mt">
    <div class="card">
        <h2>Evacuation Centers Map</h2>
        <div class="map">
            <?php foreach([['Alpha', 18, 25], ['Beta', 40, 45], ['Gamma', 65, 20], ['Delta', 70, 65], ['Epsilon', 30, 70]] as $p): ?>
                <div class="pin" style="--x:<?=$p[1]?>%;--y:<?=$p[2]?>%">⌂ <?=$p[0]?></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <h2>Evacuation Center Overview</h2>
        <?php foreach($centers as $c): $pct = round($c[4] / $c[3] * 100); ?>
            <div class="center-row">
                <div class="center-row-head">
                    <b><?=$c[1]?></b>
                    <?=status_badge($c[5])?>
                </div>
                <div class="mini"><?=$c[2]?> · <?=$c[4]?> / <?=$c[3]?> occupants</div>
                <div class="progress-track">
                    <div class="progress-fill" style="--fill:<?=$pct?>%"></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card mt">
    <h2>Evacuation Centers</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Barangay</th>
                    <th>Capacity</th>
                    <th>Occupants</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($centers as $c): ?>
                    <tr>
                        <td><?=$c[0]?></td>
                        <td><?=$c[1]?></td>
                        <td><?=$c[2]?></td>
                        <td><?=$c[3]?></td>
                        <td><?=$c[4]?></td>
                        <td><?=status_badge($c[5])?></td>
                        <td><button class="btn btn-light">View</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="centerModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Add Evacuation Center</h2>
            <button class="icon-btn" onclick="closeModal('centerModal')">✕</button>
        </div>
        <form onsubmit="event.preventDefault();alert('Evacuation center added.');closeModal('centerModal')">
            <div class="form-grid">
                <div class="field">
                    <label>Center Name</label>
                    <input required>
                </div>
                <div class="field">
                    <label>Barangay</label>
                    <input required>
                </div>
                <div class="field">
                    <label>Capacity</label>
                    <input type="number" min="1">
                </div>
                <div class="field">
                    <label>Contact Person</label>
                    <input>
                </div>
                <div class="field field-full">
                    <label>Address</label>
                    <textarea></textarea>
                </div>
            </div>
            <div class="actions mt">
                <button class="btn btn-primary">Save Center</button>
            </div>
        </form>
    </div>
</div>

<?php page_end(); ?>
