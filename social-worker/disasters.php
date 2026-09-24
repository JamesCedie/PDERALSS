<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';
db_ensure_disaster_event_status();
db_ensure_casualties_table();

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
            'casualties' => (int) db_query('SELECT COUNT(*) FROM public.casualties WHERE event_id = ? AND barangay = ?', [$e['event_id'], $socialWorkerAddress])->fetchColumn()
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
        $cas = db_query('SELECT COUNT(*) FROM public.casualties WHERE event_id = ? AND barangay = ?', [$latestActive['event_id'], $socialWorkerAddress])->fetchColumn();
        $stats = [$ev['people'] ?? 0, $ev['hh'] ?? 0, (int) $cas];
    }
}
?>
<div class="sw-impact-title">Current Cumulative Evacuation Impact</div>
<div class="grid g3 sw-impact-cards">
    <div class="card"><div class="stat-label">Total Evacuees</div><div class="stat-value"><?= htmlspecialchars($stats[0]) ?></div></div>
    <div class="card"><div class="stat-label">Evacuated Households</div><div class="stat-value"><?= htmlspecialchars($stats[1]) ?></div></div>
    <div class="card"><div class="stat-label">Reported Casualties</div><div class="stat-value"><?= htmlspecialchars($stats[2]) ?></div></div>
</div>

<div class="sw-incidents-title" style="margin-top:32px;">Monitored Incidents</div>
<div class="sw-events-row">
    <?php if (empty($events)): ?>
        <div class="card empty" style="flex:1;">No disaster events recorded yet.</div>
    <?php endif; ?>

    <?php foreach ($events as $e):
        $isActive = ($e['status'] ?? 'Active') === 'Active';
    ?>
        <div class="card sw-event-card">
            <h3><?= htmlspecialchars($e['event_name']) ?> Event</h3>
            <div class="event-code">DE-<?= htmlspecialchars($e['event_id']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($e['type']) ?></div>
            <div class="event-status-row" style="justify-content:flex-start;margin:0 0 14px;">
                <?= status_badge($isActive ? 'Active' : 'Passed') ?>
            </div>

            <div class="sw-event-description">
                <span class="event-date">▣ &nbsp;Reported: <?= htmlspecialchars($e['date']) ?></span>
                <?= htmlspecialchars($e['description']) ?>
            </div>

            <div class="sw-event-actions">
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

<?php foreach ($events as $e):
    if (($e['status'] ?? 'Active') === 'Active') continue;
    $sum = $eventSummaries[$e['event_id']] ?? ['evacuees' => 0, 'households' => 0, 'casualties' => 0];
?>
<div id="viewModal-<?= $e['event_id'] ?>" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <div><h2><?= htmlspecialchars($e['event_name']) ?></h2><div class="mini"><?= htmlspecialchars($e['type']) ?> · <?= htmlspecialchars($e['date']) ?></div></div>
            <button class="icon-btn" onclick="closeModal('viewModal-<?= $e['event_id'] ?>')">×</button>
        </div>
        <p class="mini"><?= htmlspecialchars($e['description']) ?></p>
        <div class="sw-modal-section-title">Event Summary — All Reports</div>
        <div class="sw-view-grid">
            <div class="sw-view-item"><label>People Evacuated</label><div><?= htmlspecialchars($sum['evacuees']) ?></div></div>
            <div class="sw-view-item"><label>Households Evacuated</label><div><?= htmlspecialchars($sum['households']) ?></div></div>
            <div class="sw-view-item"><label>Casualties Reported</label><div><?= htmlspecialchars($sum['casualties']) ?></div></div>
        </div>
        <div class="actions"><button class="btn btn-primary" onclick="closeModal('viewModal-<?= $e['event_id'] ?>')">Close</button></div>
    </div>
</div>
<?php endforeach; ?>

<?php page_end(); ?>
