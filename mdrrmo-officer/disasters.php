<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';
db_ensure_disaster_event_status();

$successMsg = null;
$errorMsg   = null;

// Create a new event as Active so it is immediately available to Social Workers.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_event'])) {
    $eventName   = trim($_POST['event_name'] ?? '');
    $type        = $_POST['type'] ?? '';
    $date        = $_POST['date'] ?? null;
    $description = trim($_POST['description'] ?? '');

    if ($eventName && $date) {
        db_insert('disaster_events', [
            'event_name'  => $eventName,
            'type'        => $type,
            'date'        => $date,
            'description' => $description,
            'status'      => 'Active',
            'created_by'  => $_SESSION['user']['id'] ?? null,
        ]);
        $_SESSION['flash_success'] = 'Disaster event added and marked Active.';
        header('Location: disasters.php');
        exit;
    } else {
        $errorMsg = 'Please fill in the required fields.';
    }
}

// Toggle the lifecycle state of an event. The Social Worker page reads the same
// status column, so changing it here changes the controls they see as well.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_event_status'])) {
    $eventId = (int) ($_POST['event_id'] ?? 0);
    $status  = $_POST['set_event_status'];

    if ($eventId > 0 && in_array($status, ['Active', 'Passed'], true)) {
        $event = db_select_one('disaster_events', 'event_id = ?', [$eventId], 'event_id, status');
        if ($event) {
            db_update('disaster_events', ['status' => $status], 'event_id = ?', [$eventId]);
            $_SESSION['flash_success'] = 'Event status changed to ' . $status . '.';
        } else {
            $_SESSION['flash_error'] = 'Disaster event not found.';
        }
    } else {
        $_SESSION['flash_error'] = 'Invalid event status change.';
    }

    header('Location: disasters.php');
    exit;
}

require '../includes/layout.php';
page_start('Disaster Events');

if (isset($_SESSION['flash_success'])) {
    $successMsg = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $errorMsg = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$events = db_query(
    "SELECT de.*, u.first_name, u.last_name
     FROM disaster_events de
     LEFT JOIN users u ON de.created_by = u.user_id
     ORDER BY de.event_id DESC"
)->fetchAll();
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

    <?php foreach ($events as $e):
        $isActive = ($e['status'] ?? 'Active') === 'Active';
    ?>
        <div class="card" style="flex:0 0 320px;">
            <div class="page-head card-head-gap">
                <div>
                    <h3><?= htmlspecialchars($e['event_name']) ?></h3>
                    <div class="mini">DE-<?= htmlspecialchars($e['event_id']) ?> · <?= htmlspecialchars($e['type']) ?></div>
                </div>
            </div>

            <div class="event-status-row">
                <?= status_badge($isActive ? 'Active' : 'Passed') ?>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="event_id" value="<?= htmlspecialchars($e['event_id']) ?>">
                    <button class="btn <?= $isActive ? 'event-status-passed' : 'event-status-active' ?>" name="set_event_status" value="<?= $isActive ? 'Passed' : 'Active' ?>">
                        <?= $isActive ? 'Mark as Passed' : 'Set Active' ?>
                    </button>
                </form>
            </div>

            <p class="mini"><b>Date:</b> <?= htmlspecialchars($e['date']) ?></p>
            <p class="event-desc"><?= htmlspecialchars($e['description']) ?></p>

            <div class="md-event-actions">
                <a href="evacuation-centers.php?event_id=<?= urlencode($e['event_id']) ?>" class="btn btn-light">View Evacuation Centers</a>
                <a href="casualties.php?event_id=<?= urlencode($e['event_id']) ?>" class="btn btn-light">View Casualties</a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (!empty($events)): ?>
<div class="card mt">
    <h2>Current Disaster Events</h2>
    <table class="table">
        <thead>
            <tr><th>Event</th><th>Status</th><th>Date</th></tr>
        </thead>
        <tbody>
        <?php foreach ($events as $e): ?>
            <tr>
                <td><?= htmlspecialchars($e['event_name']) ?></td>
                <td><?= status_badge(($e['status'] ?? 'Active') === 'Active' ? 'Active' : 'Passed') ?></td>
                <td><?= htmlspecialchars($e['date']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

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

<?php page_end(); ?>
