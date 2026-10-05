<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/md.php';
db_ensure_disaster_event_status();

$successMsg = null;
$errorMsg   = null;

// Create a new event as Active so it is immediately available to Social Workers.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_event'])) {
    $eventName   = trim($_POST['event_name'] ?? '');
    $type        = $_POST['type'] ?? '';
    $date        = trim((string) ($_POST['date'] ?? ''));
    $description = trim($_POST['description'] ?? '');
    $dateOk      = ($d = DateTimeImmutable::createFromFormat('!Y-m-d', $date)) && $d->format('Y-m-d') === $date;

    if (!md_csrf_valid()) {
        $errorMsg = 'Your session expired. Please try again.';
    } elseif ($eventName === '' || !$dateOk || !in_array($type, MD_DISASTER_TYPES, true)) {
        $errorMsg = 'Please fill in the event name, disaster type, and date.';
    } else {
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
    }
}

// Toggle the lifecycle state of an event. The Social Worker page reads the same
// status column, so changing it here changes the controls they see as well.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_event_status'])) {
    $eventId = (int) ($_POST['event_id'] ?? 0);
    $status  = $_POST['set_event_status'];

    if (!md_csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired. Please try again.';
    } elseif ($eventId > 0 && in_array($status, ['Active', 'Passed'], true)) {
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

$events = db_query('SELECT * FROM disaster_events ORDER BY event_id DESC')->fetchAll();
?>

<div class="md-page">
    <?php if ($successMsg): ?><div class="alert alert-success mb"><?= md_h($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="alert alert-danger mb"><?= md_h($errorMsg) ?></div><?php endif; ?>

    <div class="card md-panel">
        <div class="md-panel-head">
            <h2>Disaster Events</h2>
            <button class="btn btn-primary" onclick="openModal('eventModal')">＋ &nbsp;Add Disaster Event</button>
        </div>

        <?php if (empty($events)): ?>
            <div class="empty">No disaster events recorded yet.</div>
        <?php else: ?>
            <div class="md-event-grid">
                <?php foreach ($events as $e):
                    $isActive = ($e['status'] ?? 'Active') === 'Active';
                ?>
                    <div class="card md-event-card">
                        <h3><?= md_h($e['event_name']) ?></h3>
                        <div class="mini">DE-<?= md_h($e['event_id']) ?> · <?= md_h($e['type']) ?></div>

                        <div class="event-status-row">
                            <?= status_badge($isActive ? 'Active' : 'Passed') ?>
                            <form method="post" style="margin:0;">
                                <?= md_csrf_field() ?>
                                <input type="hidden" name="event_id" value="<?= (int) $e['event_id'] ?>">
                                <button class="btn <?= $isActive ? 'event-status-passed' : 'event-status-active' ?>" name="set_event_status" value="<?= $isActive ? 'Passed' : 'Active' ?>">
                                    <?= $isActive ? 'Mark as Passed' : 'Set Active' ?>
                                </button>
                            </form>
                        </div>

                        <p class="mini"><b>Date:</b> <?= md_h($e['date']) ?></p>
                        <p class="event-desc"><?= md_h($e['description'] ?: 'No description.') ?></p>

                        <div class="md-event-actions md-event-actions--stack">
                            <a href="evacuation-centers.php?event_id=<?= (int) $e['event_id'] ?>" class="btn btn-primary">
                                <svg class="md-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/></svg>
                                View Evacuation Centers
                            </a>
                            <a href="casualties.php?event_id=<?= (int) $e['event_id'] ?>" class="btn btn-primary">
                                <svg class="md-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.4"/><path d="M16 14.2c2.9 0 5 2.2 5 5.8"/></svg>
                                View Casualties
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($events)): ?>
        <div class="card md-panel mt">
            <h2>List of Disaster Events</h2>
            <div class="sw-data-table">
                <table class="table">
                    <thead>
                        <tr><th>Event</th><th>Status</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($events as $e): ?>
                        <tr>
                            <td><?= md_h($e['event_name']) ?></td>
                            <td><?= status_badge(($e['status'] ?? 'Active') === 'Active' ? 'Active' : 'Passed') ?></td>
                            <td><?= md_h($e['date']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="eventModal" class="modal">
    <div class="modal-box md-event-modal">
        <div class="modal-head">
            <h2>Add New Disaster Event</h2>
            <button type="button" class="icon-btn" onclick="closeModal('eventModal')" aria-label="Close">✕</button>
        </div>
        <form method="post">
            <?= md_csrf_field() ?>
            <input type="hidden" name="add_event" value="1">
            <div class="form-grid">
                <div class="field">
                    <label>Event Name</label>
                    <input name="event_name" placeholder="Enter event name" required>
                </div>
                <div class="field">
                    <label>Type</label>
                    <select name="type" required>
                        <option value="" disabled selected>Select disaster type</option>
                        <?php foreach (MD_DISASTER_TYPES as $t): ?>
                            <option><?= md_h($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Date</label>
                    <input type="date" name="date" required>
                </div>
                <div class="field">
                    <label>Description</label>
                    <textarea name="description" placeholder="Add notes or details"></textarea>
                </div>
            </div>
            <div class="actions mt">
                <button class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<?php page_end(); ?>
