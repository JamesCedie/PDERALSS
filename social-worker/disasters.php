<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';

$currentUser         = db_select_one('users', 'user_id = ?', [$_SESSION['user']['id'] ?? null]);
$socialWorkerAddress = $currentUser['address'] ?? '';

require '../includes/layout.php';
page_start('Disaster Events');

$events = db_query(
    "SELECT de.*, u.first_name, u.last_name
     FROM disaster_events de
     LEFT JOIN users u ON de.created_by = u.user_id
     ORDER BY de.event_id DESC"
)->fetchAll();

// For finished events - get summary per event for this barangay
$eventSummaries = [];
foreach ($events as $e) {
    if (($e['status'] ?? 'Active') !== 'Active') {
        $evac = db_query(
            'SELECT COUNT(*) as hh, COALESCE(SUM(household_no),0) as people
             FROM evacuation_evacuees WHERE event_id = ? AND barangay = ?',
            [$e['event_id'], $socialWorkerAddress]
        )->fetch();
        $eventSummaries[$e['event_id']] = [
            'evacuees'   => $evac['people'] ?? 0,
            'households' => $evac['hh'] ?? 0,
            'casualties' => '—', // casualties table not yet built
        ];
    }
}

$stats = ['—', '—', '—'];
if (!empty($events)) {
    $latestActive = null;
    foreach ($events as $e) {
        if (($e['status'] ?? 'Active') === 'Active') { $latestActive = $e; break; }
    }
    if ($latestActive) {
        $ev = db_query('SELECT COUNT(*) as hh, COALESCE(SUM(household_no),0) as people FROM evacuation_evacuees WHERE event_id = ? AND barangay = ?', [$latestActive['event_id'], $socialWorkerAddress])->fetch();
        $stats = [$ev['people'] ?? 0, $ev['hh'] ?? 0, '—']; // casualties not yet available
    }
}
?>

<div class="page-head">
    <h1 class="page-title">Disaster Events</h1>
</div>

<!-- Scrollable event cards -->
<div style="display:flex;gap:16px;overflow-x:auto;padding-bottom:8px;">
    <?php if (empty($events)): ?>
        <div class="card empty" style="flex:1;">No disaster events recorded yet.</div>
    <?php endif; ?>
    <?php foreach ($events as $e):
        $isActive = ($e['status'] ?? 'Active') === 'Active';
    ?>
        <div class="card" style="flex:0 0 320px;">
            <div class="page-head card-head-gap">
                <div>
                    <h3><?= htmlspecialchars($e['event_name']) ?></h3>
                    <div class="mini">DE-<?= htmlspecialchars($e['event_id']) ?> · <?= htmlspecialchars($e['type']) ?></div>
                </div>
                <?= status_badge($isActive ? 'Active' : 'Completed') ?>
            </div>
            <p class="mini"><b>Date:</b> <?= htmlspecialchars($e['date']) ?></p>
            <p class="event-desc"><?= htmlspecialchars($e['description']) ?></p>
            <div class="actions">
                <?php if ($isActive): ?>
                    <a href="evacuation-centers.php?event_id=<?= $e['event_id'] ?>" class="btn btn-light">Manage Evacuation Centers</a>
                    <a href="casualties.php?event_id=<?= $e['event_id'] ?>" class="btn btn-light">Manage Casualties</a>
                <?php else: ?>
                    <button class="btn btn-light" onclick="openModal('viewModal-<?= $e['event_id'] ?>')">View Details</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Current Disaster Events summary -->
<?php if (!empty($events)): ?>
<div class="card mt">
    <h2>Current Disaster Events</h2>
    <table class="table">
        <tbody>
            <tr><td><b>Total Evacuees</b></td><td><?= $stats[0] ?></td></tr>
            <tr><td><b>Evacuated Households</b></td><td><?= $stats[1] ?></td></tr>
            <tr><td><b>Reported Casualties</b></td><td><?= $stats[2] ?></td></tr>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- View Details modals for finished events -->
<?php foreach ($events as $e):
    if (($e['status'] ?? 'Active') === 'Active') continue;
    $sum = $eventSummaries[$e['event_id']] ?? ['evacuees' => 0, 'households' => 0, 'casualties' => 0];
?>
    <div id="viewModal-<?= $e['event_id'] ?>" class="modal">
        <div class="modal-box">
            <div class="modal-head">
                <h2><?= htmlspecialchars($e['event_name']) ?></h2>
                <button class="icon-btn" onclick="closeModal('viewModal-<?= $e['event_id'] ?>')">✕</button>
            </div>
            <p class="mini"><b>Type:</b> <?= htmlspecialchars($e['type']) ?> &nbsp;·&nbsp; <b>Date:</b> <?= htmlspecialchars($e['date']) ?></p>
            <p class="event-desc"><?= htmlspecialchars($e['description']) ?></p>
            <h3 style="margin:14px 0 8px;">Event Summary — <?= htmlspecialchars($socialWorkerAddress) ?></h3>
            <table class="table">
                <tbody>
                    <tr><td><b>People Evacuated</b></td><td><?= $sum['evacuees'] ?></td></tr>
                    <tr><td><b>Households Evacuated</b></td><td><?= $sum['households'] ?></td></tr>
                    <tr><td><b>Casualties Reported</b></td><td><?= $sum['casualties'] ?></td></tr>
                </tbody>
            </table>
            <div class="actions mt">
                <button type="button" class="btn btn-light" onclick="closeModal('viewModal-<?= $e['event_id'] ?>')">Close</button>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php page_end(); ?>