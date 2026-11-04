<?php
require_once '../includes/access.php'; require_page_access(); require '../includes/layout.php';
page_start('Reports');
require_once '../includes/reports.php';
$swUser = db_user_with_barangay($_SESSION['user']['id'] ?? null);
$totals = report_totals(trim((string) ($swUser['address'] ?? '')) ?: null);

$types = [
    ['Casualty Report',           'Comprehensive report of all casualties by barangay and severity'],
    ['Evacuation Report',         'Evacuation center occupancy and household distribution'],
];
?>

<div class="page-head">
    <h1 class="page-title">Reports & Summaries</h1>
</div>

<div class="grid g4">
    <?php foreach([
        ['Total Households',     $totals['households']],
        ['Total Casualties',     $totals['casualties']],
        ['Relief Distributions', $totals['relief']],
        ['Vehicle Requests',     $totals['vehicles']],
    ] as $s): ?>
        <div class="card sw-summary-card">
            <div class="stat-label"><?=$s[0]?></div>
            <div class="stat-value"><?=number_format($s[1])?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mt">
    <h2>Generate Report</h2>
    <div class="grid g2">
        <?php foreach($types as $t): ?>
            <div class="card report-type-card">
                <h3><?=$t[0]?></h3>
                <p class="mini"><?=$t[1]?></p>
                <?php if ($t[0] === 'Evacuation Report'): ?>
                    <a class="btn btn-primary btn-block" href="evacuation-report.php">Generate</a>
                <?php else: ?>
                    <a class="btn btn-primary btn-block" href="casualty-report.php">Generate</a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php page_end(); ?>
