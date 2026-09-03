<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';

$successMsg = null;
$errorMsg   = null;

// Handle "Add Disaster Event" submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_event'])) {
    $eventName   = trim($_POST['event_name'] ?? '');
    $type        = $_POST['type'] ?? '';
    $date        = $_POST['date'] ?? null;
    $description = trim($_POST['description'] ?? '');

    if ($eventName && $date) {
        db_insert('disaster_events', [
            'event_name' => $eventName,
            'type'       => $type,
            'date'       => $date,
            'description' => $description,
            'created_by' => $_SESSION['user']['id'] ?? null,
        ]);
        $_SESSION['flash_success'] = 'Disaster event added.';
        header('Location: disasters.php');
        exit;
    } else {
        $errorMsg = 'Please fill in the required fields.';
    }
}

require '../includes/layout.php';
page_start('Disaster Events');

if (isset($_SESSION['flash_success'])) {
    $successMsg = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

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
    <button class="btn btn-primary" onclick="openModal('eventModal')">＋ Add Disaster Event</button>
</div>

<?php if ($successMsg): ?>
    <div class="alert alert-success mb"><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
    <div class="alert alert-danger mb"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

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

<div id="eventModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Add New Disaster Event</h2>
            <button class="icon-btn" onclick="closeModal('eventModal')">✕</button>
        </div>

        <form method="post">
            <input type="hidden" name="add_event" value="1">
            <div class="form-grid">
                <div class="field">
                    <label>Event Name</label>
                    <input name="event_name" required>
                </div>

                <div class="field">
                    <label>Type</label>
                    <select name="type">
                        <option>Typhoon</option>
                        <option>Flood</option>
                        <option>Earthquake</option>
                        <option>Landslide</option>
                        <option>Fire</option>
                    </select>
                </div>

                <div class="field">
                    <label>Date</label>
                    <input type="date" name="date" required>
                </div>

                <div class="field field-full">
                    <label>Description</label>
                    <textarea name="description"></textarea>
                </div>
            </div>

            <div class="actions mt">
                <button class="btn btn-primary">Save Event</button>
            </div>
        </form>
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