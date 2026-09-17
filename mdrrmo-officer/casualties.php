<?php
require_once '../includes/access.php'; require_page_access(); require '../includes/layout.php';
page_start('Casualty Monitoring');

$rows = [
    ['CR-001','Brgy. Jaro','John Santos','Injured','Minor','2026-05-01'],
    ['CR-002','Brgy. Jaro','Maria Cruz','Injured','Moderate','2026-05-01'],
    ['CR-003','Brgy. Molo','Pedro Reyes','Missing','—','2026-05-01'],
    ['CR-004','Brgy. Arevalo','Ana Garcia','Fatality','Critical','2026-04-30'],
    ['CR-005','Brgy. La Paz','Jose Flores','Injured','Minor','2026-04-30'],
];

$total    = 89;
$injured  = 62;
$missing  = 18;
$fatality = 9;
?>

<div class="page-head">
    <div style="display:flex;align-items:center;gap:12px;">
        <a href="disasters.php" class="btn btn-light">← Disaster Events</a>
        <h1 class="page-title">Casualty Monitoring</h1>
    </div>
</div>

<!-- Stat cards: no emojis, no icon container, number + label only -->
<div class="grid g4">
    <?php foreach([[$total, 'Total Casualties'], [$injured, 'Injured'], [$missing, 'Missing'], [$fatality, 'Fatalities']] as $s): ?>
        <div class="card">
            <div class="stat-value"><?= $s[0] ?></div>
            <div class="stat-label"><?= $s[1] ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mt">
    <h2>Casualty Count per Barangay</h2>
    <?php foreach([['Jaro',31],['Molo',20],['Mandurriao',16],['Arevalo',12],['La Paz',10]] as $x): ?>
        <div class="barangay-row">
            <div class="barangay-row-head">
                <span>Brgy. <?=$x[0]?></span>
                <b><?=$x[1]?></b>
            </div>
            <div class="progress-track progress-track--lg">
                <div class="progress-fill progress-fill--red" style="--fill:<?=$x[1]*2.8?>%"></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mt">
    <h2>Casualty Records</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Barangay</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Severity</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($rows as $r): ?>
                    <tr>
                        <?php foreach($r as $i => $v): ?>
                            <td><?= $i === 3 ? status_badge($v) : htmlspecialchars($v) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php page_end(); ?>