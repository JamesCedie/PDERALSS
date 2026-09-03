<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';
require '../includes/layout.php';
page_start('Disaster Events');

$events = db_query(
    "SELECT de.*, u.first_name, u.last_name
     FROM disaster_events de
     LEFT JOIN users u ON de.created_by = u.user_id
     ORDER BY de.event_id DESC"
)->fetchAll();

$timeline = [
    ['08:30 AM', 'Incident reported by barangay officials.'],
    ['09:15 AM', 'MDRRMO validated the incident and activated response.'],
    ['10:00 AM', 'Evacuation and vehicle requests initiated.'],
    ['11:30 AM', 'Relief distribution and monitoring started.'],
];
?>

<div class="page-head">
    <h1 class="page-title">Disaster Events</h1>
</div>

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
                <button class="btn btn-light" onclick="openModal('viewModal-<?= $e['event_id'] ?>')">View Details</button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mt">
    <h2>Disaster Event Timeline</h2>
    <div class="timeline">
        <?php foreach ($timeline as $t): ?>
            <div class="timeline-item">
                <b><?= $t[0] ?></b>
                <div class="mini"><?= $t[1] ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php foreach ($events as $e):
    $reporter = trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? ''));
?>
    <div id="viewModal-<?= $e['event_id'] ?>" class="modal">
        <div class="modal-box">
            <div class="modal-head">
                <h2><?= htmlspecialchars($e['event_name']) ?></h2>
                <button class="icon-btn" onclick="closeModal('viewModal-<?= $e['event_id'] ?>')">✕</button>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label>Event ID</label>
                    <p>DE-<?= htmlspecialchars($e['event_id']) ?></p>
                </div>
                <div class="field">
                    <label>Type</label>
                    <p><?= htmlspecialchars($e['type']) ?></p>
                </div>
                <div class="field">
                    <label>Date</label>
                    <p><?= htmlspecialchars($e['date']) ?></p>
                </div>
                <div class="field">
                    <label>Logged By</label>
                    <p><?= htmlspecialchars($reporter ?: 'Unknown') ?></p>
                </div>
                <div class="field field-full">
                    <label>Description</label>
                    <p><?= nl2br(htmlspecialchars($e['description'])) ?></p>
                </div>
            </div>
            <div class="actions mt">
                <button type="button" class="btn btn-light" onclick="closeModal('viewModal-<?= $e['event_id'] ?>')">Close</button>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php page_end(); ?>