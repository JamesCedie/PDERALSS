<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';
db_ensure_disaster_event_status();
require '../includes/layout.php';

$eventId = (int) ($_GET['event_id'] ?? 0);
$currentEvent = $eventId ? db_select_one('disaster_events', 'event_id = ?', [$eventId]) : null;

page_start('Evacuation Centers');

// MDRRMO is not barangay-scoped. The summary below is therefore filtered only
// by event_id and combines every barangay that reported evacuees for that event.
$summary = [
    'people'    => 0,
    'households'=> 0,
    'barangays' => 0,
];
$barangays = [];

if ($currentEvent) {
    $summary = db_query(
        "SELECT
            COALESCE(SUM(household_no), 0) AS people,
            COUNT(*) AS households,
            COUNT(DISTINCT barangay) AS barangays
         FROM evacuation_evacuees
         WHERE event_id = ?",
        [$eventId]
    )->fetch() ?: $summary;

    $barangays = db_query(
        "SELECT barangay,
                COUNT(*) AS households,
                COALESCE(SUM(household_no), 0) AS people
         FROM evacuation_evacuees
         WHERE event_id = ?
         GROUP BY barangay
         ORDER BY barangay ASC",
        [$eventId]
    )->fetchAll();
}
?>

<div class="page-head">
    <div style="display:flex;align-items:center;gap:12px;">
        <a href="disasters.php" class="btn btn-light">← Disaster Events</a>
        <h1 class="page-title">Evacuation Center Management</h1>
    </div>
</div>

<?php if (!$currentEvent): ?>
    <div class="card empty">Select a disaster event from Disaster Events to view its evacuation-center summary.</div>
<?php else: ?>
    <div class="event-status-row" style="justify-content:flex-start;margin-top:0;">
        <div class="mini">Event: <strong><?= htmlspecialchars($currentEvent['event_name']) ?></strong> · <?= htmlspecialchars($currentEvent['type']) ?> · <?= htmlspecialchars($currentEvent['date']) ?></div>
        <?= status_badge(($currentEvent['status'] ?? 'Active') === 'Active' ? 'Active' : 'Passed') ?>
    </div>

    <div class="grid g3 md-event-summary-grid">
        <div class="card md-event-summary-card">
            <div class="stat-value"><?= htmlspecialchars($summary['people']) ?></div>
            <div class="stat-label">Total People Evacuated</div>
        </div>
        <div class="card md-event-summary-card">
            <div class="stat-value"><?= htmlspecialchars($summary['households']) ?></div>
            <div class="stat-label">Evacuated Households</div>
        </div>
        <div class="card md-event-summary-card">
            <div class="stat-value"><?= htmlspecialchars($summary['barangays']) ?></div>
            <div class="stat-label">Affected Barangays Reporting</div>
        </div>
    </div>

    <div class="card mt">
        <h2>Event Evacuation Overview</h2>
        <p class="mini">This view is event-specific and combines evacuation reports from all barangays affected by <strong><?= htmlspecialchars($currentEvent['event_name']) ?></strong>.</p>

        <?php if (empty($barangays)): ?>
            <div class="empty">No evacuation records have been reported for this event yet.</div>
        <?php else: ?>
            <div class="md-affected-barangays">
                <?php foreach ($barangays as $row): ?>
                    <span class="barangay-chip"><?= htmlspecialchars($row['barangay']) ?> · <?= htmlspecialchars($row['people']) ?> people</span>
                <?php endforeach; ?>
            </div>

            <div class="table-wrap mt">
                <table class="table">
                    <thead>
                        <tr><th>Barangay Reporting</th><th>Household Records</th><th>People Evacuated</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($barangays as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['barangay']) ?></td>
                            <td><?= htmlspecialchars($row['households']) ?></td>
                            <td><?= htmlspecialchars($row['people']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php page_end(); ?>
