<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';
db_ensure_disaster_event_status();
db_ensure_casualties_table();

$currentUser         = db_user_with_barangay($_SESSION['user']['id'] ?? null);
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
$myBarangayId   = (int) ($currentUser['barangay_id'] ?? 0);
$eventSummaries = [];
foreach ($events as $e) {
    if (($e['status'] ?? 'Active') !== 'Active') {
        $evac = db_query(
            'SELECT COUNT(DISTINCT COALESCE(x.household_id, -x.evacuee_id)) as hh, COALESCE(SUM(x.people_count),0) as people
             FROM evacuation_evacuees x JOIN evacuation_centers c ON c.center_id = x.center_id
             WHERE x.event_id = ? AND c.barangay_id = ?',
            [$e['event_id'], $myBarangayId]
        )->fetch();
        $eventSummaries[$e['event_id']] = [
            'evacuees'   => $evac['people'] ?? 0,
            'households' => $evac['hh'] ?? 0,
            'casualties' => (int) db_query('SELECT COUNT(*) FROM public.casualties WHERE event_id = ? AND barangay_id = ?', [$e['event_id'], $myBarangayId])->fetchColumn()
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
        $ev = db_query('SELECT COUNT(DISTINCT COALESCE(x.household_id, -x.evacuee_id)) as hh, COALESCE(SUM(x.people_count),0) as people FROM evacuation_evacuees x JOIN evacuation_centers c ON c.center_id = x.center_id WHERE x.event_id = ? AND c.barangay_id = ?', [$latestActive['event_id'], $myBarangayId])->fetch();
        $cas = db_query('SELECT COUNT(*) FROM public.casualties WHERE event_id = ? AND barangay_id = ?', [$latestActive['event_id'], $myBarangayId])->fetchColumn();
        $stats = [$ev['people'] ?? 0, $ev['hh'] ?? 0, (int) $cas];
    }
}
?>
<div class="sw-impact-title">Current Cumulative Evacuation Impact</div>
<div class="grid g3 sw-impact-cards">
    <div class="card sw-summary-card"><div class="stat-label">Total Evacuees</div><div class="stat-value"><?= htmlspecialchars($stats[0]) ?></div></div>
    <div class="card sw-summary-card"><div class="stat-label">Evacuated Households</div><div class="stat-value"><?= htmlspecialchars($stats[1]) ?></div></div>
    <div class="card sw-summary-card"><div class="stat-label">Reported Casualties</div><div class="stat-value"><?= htmlspecialchars($stats[2]) ?></div></div>
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
                <span class="event-date">Reported: <?= htmlspecialchars($e['date']) ?></span>
                <?= htmlspecialchars($e['description']) ?>
            </div>

            <div class="sw-event-actions">
                <?php if ($isActive): ?>
                    <a href="evacuation-centers.php?event_id=<?= $e['event_id'] ?>" class="btn btn-primary">Manage Evacuation Centers</a>
                    <a href="casualties.php?event_id=<?= $e['event_id'] ?>" class="btn btn-primary">Manage Casualties</a>
                    <a href="damage-assessment.php?event_id=<?= $e['event_id'] ?>" class="btn btn-primary">Damage Assessment</a>
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
            <button class="icon-btn" onclick="closeModal('viewModal-<?= $e['event_id'] ?>')" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
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
