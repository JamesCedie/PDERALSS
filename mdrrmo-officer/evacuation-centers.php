<?php
require_once '../includes/access.php'; require_page_access(); require '../includes/layout.php';
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
    <a href="disasters.php" class="btn btn-light">← Back to Disaster Events</a>
</div>

<!-- Stat cards: no emojis, no icon container -->
<div class="grid g4">
    <?php foreach([['12', 'Total Centers'], ['8', 'Available'], ['3', 'Near Capacity'], ['1', 'Full']] as $s): ?>
        <div class="card">
            <div class="stat-value"><?= $s[0] ?></div>
            <div class="stat-label"><?= $s[1] ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid g2 mt">
    <div class="card">
        <h2>Evacuation Centers Map</h2>
        <div class="map">
            <?php foreach([['Alpha', 18, 25], ['Beta', 40, 45], ['Gamma', 65, 20], ['Delta', 70, 65], ['Epsilon', 30, 70]] as $p): ?>
                <div class="pin" style="--x:<?=$p[1]?>%;--y:<?=$p[2]?>%"><?=$p[0]?></div>
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

<?php page_end(); ?>