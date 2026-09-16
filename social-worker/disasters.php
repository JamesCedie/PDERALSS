<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';

// Get the logged-in social worker's barangay from the database
$currentUser          = db_select_one('users', 'user_id = ?', [$_SESSION['user']['id'] ?? null]);
$socialWorkerBarangay = $currentUser['address'] ?? '';

require '../includes/layout.php';
page_start('Disaster Events');

$events = db_query(
    "SELECT de.*, u.first_name, u.last_name
     FROM disaster_events de
     LEFT JOIN users u ON de.created_by = u.user_id
     ORDER BY de.event_id DESC"
)->fetchAll();

// Stats will be populated once the Casualties and Evacuation Centers
// tables are built. All show — for now.
$totalEvacuees       = '—';
$evacuatedHouseholds = '—';
$reportedCasualties  = '—';
?>

<div class="page-head">
    <h1 class="page-title">Disaster Events</h1>
</div>

<!-- Scrollable event cards -->
<div style="display:flex; gap:16px; overflow-x:auto; padding-bottom:8px;">
    <?php if (empty($events)): ?>
        <div class="card empty" style="flex:1;">No disaster events recorded yet.</div>
    <?php endif; ?>
    <?php foreach ($events as $e): ?>
        <div class="card" style="flex:0 0 320px;">
            <div class="page-head card-head-gap">
                <div>
                    <h3><?= htmlspecialchars($e['event_name']) ?></h3>
                    <div class="mini">DE-<?= htmlspecialchars($e['event_id']) ?> · <?= htmlspecialchars($e['type']) ?></div>
                </div>
            </div>
            <p class="mini"><b>Date:</b> <?= htmlspecialchars($e['date']) ?></p>
            <p class="event-desc"><?= htmlspecialchars($e['description']) ?></p>
            <div class="actions">
                <a href="evacuation-centers.php" class="btn btn-light">Manage Evacuation Centers</a>
                <a href="casualties.php" class="btn btn-light">Manage Casualties</a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Current Disaster Events summary — data from casualties/evac tables (not yet built) -->
<?php if (!empty($events)): ?>
<div class="card mt">
    <h2>Current Disaster Events</h2>
    <table class="table">
        <tbody>
            <tr>
                <td><b>Total Evacuees</b></td>
                <td><?= htmlspecialchars($totalEvacuees) ?></td>
            </tr>
            <tr>
                <td><b>Evacuated Households</b></td>
                <td><?= htmlspecialchars($evacuatedHouseholds) ?></td>
            </tr>
            <tr>
                <td><b>Reported Casualties</b></td>
                <td><?= htmlspecialchars($reportedCasualties) ?></td>
            </tr>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php page_end(); ?>