<?php
require_once '../includes/access.php'; require_page_access(); require '../includes/layout.php';
page_start('Reports');
require_once '../includes/reports.php';
require_once '../includes/md.php';

// ── Barangay forms: prepared by each barangay's Social Worker, reviewed here ──────────────────────────────
// (DROMIC Form 3 and Form 4.2 are signed by the Barangay Secretary and noted by the BDRRM Chairman, so the
//  officer reviews / prints one barangay's form at a time instead of generating it.)
$events  = db_query('SELECT * FROM disaster_events ORDER BY event_id DESC')->fetchAll();
$eventId = (int) ($_GET['event_id'] ?? 0);
if (!$eventId) {
    foreach ($events as $e) { if (($e['status'] ?? '') === 'Active') { $eventId = (int) $e['event_id']; break; } }
    if (!$eventId && $events) $eventId = (int) $events[0]['event_id'];
}

$casualties = $evacuees = [];
if ($eventId) {
    try {
        foreach (db_query('SELECT barangay_id, COUNT(*) AS n FROM casualties WHERE event_id = ? GROUP BY barangay_id', [$eventId])->fetchAll() as $r)
            $casualties[(int) $r['barangay_id']] = (int) $r['n'];
        foreach (db_query('SELECT c.barangay_id, COUNT(*) AS n FROM evacuation_evacuees x JOIN evacuation_centers c ON c.center_id = x.center_id WHERE x.event_id = ? GROUP BY c.barangay_id', [$eventId])->fetchAll() as $r)
            $evacuees[(int) $r['barangay_id']] = (int) $r['n'];
    } catch (Throwable $e) { error_log('reports review counts: ' . $e->getMessage()); }
}
$q = static fn(int $b): string => '?event_id=' . $eventId . '&barangay_id=' . $b;
?>

<div class="page-head">
    <h1 class="page-title">Reports & Summaries</h1>
</div>

<div class="card mt">
    <h2>Barangay Reports</h2>
    <p class="mini">Casualty (DROMIC Form 3) and Evacuation (DROMIC Form 4.2) reports are prepared by each barangay's Social Worker and signed by the Barangay Secretary and the BDRRM Chairman. Review or print a barangay's form here.</p>

    <?php if (!$events): ?>
        <div class="empty">No disaster event found. These reports are generated per disaster event.</div>
    <?php else: ?>
        <div class="dash-top-main" style="margin:14px 0">
            <form method="get" class="dash-event-form">
                <select id="reportEvent" name="event_id" class="dash-select" aria-label="Disaster event" onchange="this.form.submit()">
                    <?php foreach ($events as $e): ?>
                        <option value="<?= (int) $e['event_id'] ?>" <?= (int) $e['event_id'] === $eventId ? 'selected' : '' ?>><?= md_h($e['event_name']) ?> (<?= md_h(md_date($e['date'])) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-light" type="submit">Apply</button></noscript>
            </form>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Barangay</th>
                        <th>Casualties Recorded</th>
                        <th>Evacuees in Center</th>
                        <th>Casualty Report (Form 3)</th>
                        <th>Evacuation Report (Form 4.2)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (brgy_all() as $b): $bid = (int) $b['barangay_id']; ?>
                        <tr>
                            <td><?= md_h($b['name']) ?></td>
                            <td><?= number_format($casualties[$bid] ?? 0) ?></td>
                            <td><?= number_format($evacuees[$bid] ?? 0) ?></td>
                            <td><a class="btn btn-light" href="casualty-report.php<?= md_h($q($bid)) ?>">Review</a></td>
                            <td><a class="btn btn-light" href="evacuation-report.php<?= md_h($q($bid)) ?>">Review</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php page_end(); ?>
