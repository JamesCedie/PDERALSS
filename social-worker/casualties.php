<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';
db_ensure_disaster_event_status();
db_ensure_casualties_table();

$eventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$currentUser = db_select_one('users', 'user_id = ?', [$_SESSION['user']['id'] ?? null]);
$socialWorkerAddress = trim($currentUser['address'] ?? '');
$currentEvent = $eventId ? db_select_one('disaster_events', 'event_id = ?', [$eventId]) : null;
$successMsg = $_SESSION['casualty_success'] ?? null;
$errorMsg = null;
unset($_SESSION['casualty_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_casualty') {
    $name = trim($_POST['name'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $severity = trim($_POST['severity'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $allowedTypes = ['Fatality', 'Missing', 'Injured'];
    $allowedSeverity = ['Minor', 'Moderate', 'Critical'];

    if (!$currentEvent) {
        $errorMsg = 'The selected disaster event could not be found.';
    } elseif (($currentEvent['status'] ?? 'Active') !== 'Active') {
        $errorMsg = 'This disaster event is already marked as Passed and can no longer receive new casualty records.';
    } elseif (!$socialWorkerAddress) {
        $errorMsg = 'Your account has no barangay/address assigned. Please contact an MDRRMO Officer.';
    } elseif ($name === '' || !in_array($type, $allowedTypes, true) || !in_array($severity, $allowedSeverity, true) || $date === '') {
        $errorMsg = 'Please complete Name, Type, Severity, and Date.';
    } else {
        try {
            db_insert('casualties', [
                'event_id' => $eventId,
                'barangay' => $socialWorkerAddress,
                'name' => $name,
                'type' => $type,
                'severity' => $severity,
                'date' => $date,
                'notes' => $notes !== '' ? $notes : null,
                'added_by' => $_SESSION['user']['id'] ?? null,
            ]);
            $_SESSION['casualty_success'] = 'Casualty record saved successfully.';
            header('Location: casualties.php?event_id=' . urlencode((string)$eventId));
            exit;
        } catch (Throwable $e) {
            $dbError = $e->getMessage();
            error_log('PDERALSS casualty save failed: ' . $dbError);
            // Show the actual PostgreSQL/PDO error while diagnosing the schema mismatch.
            // This can be removed once the database schema is confirmed.
            $errorMsg = 'The casualty record could not be saved. Database error: ' . $dbError;
        }
    }
}

$rows = $eventId && $socialWorkerAddress
    ? db_query(
        'SELECT casualty_id, name, type, severity, date, notes FROM public.casualties WHERE event_id = ? AND barangay = ? ORDER BY date DESC, casualty_id DESC',
        [$eventId, $socialWorkerAddress]
      )->fetchAll()
    : [];

$total = count($rows);
$fatalities = 0;
$missing = 0;
$injured = 0;
foreach ($rows as $row) {
    if ($row['type'] === 'Fatality') $fatalities++;
    elseif ($row['type'] === 'Missing') $missing++;
    elseif ($row['type'] === 'Injured') $injured++;
}

require '../includes/layout.php';
page_start('Casualty Recording');
?>

<div class="sw-section-head">
    <div class="sw-event-label">
        Event:<strong><?= htmlspecialchars(($currentEvent['event_name'] ?? 'No event selected') . ($currentEvent ? ' · ' . ($socialWorkerAddress ?: 'No barangay assigned') : '')) ?></strong>
    </div>
    <?php if ($currentEvent && ($currentEvent['status'] ?? 'Active') === 'Active'): ?>
        <button class="btn btn-primary" onclick="openModal('casualtyModal')">＋ &nbsp;Add Casualty</button>
    <?php endif; ?>
</div>

<?php if ($successMsg): ?>
    <div class="sw-inline-alert sw-inline-success"><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
    <div class="sw-inline-alert sw-inline-error"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<div class="sw-casualty-title">Casualty Count</div>
<div class="grid g2 sw-casualty-grid">
    <div class="card sw-total-card">
        <div class="stat-label">Total People</div>
        <div class="stat-value"><?= $total ?></div>
    </div>
    <div class="card sw-breakdown-card">
        <h3>Casualty Breakdown</h3>
        <div class="sw-breakdown">
            <div class="sw-breakdown-item fatal"><label>Fatalities</label><strong><?= $fatalities ?></strong></div>
            <div class="sw-breakdown-item missing"><label>Missing</label><strong><?= $missing ?></strong></div>
            <div class="sw-breakdown-item injured"><label>Injured</label><strong><?= $injured ?></strong></div>
        </div>
    </div>
</div>

<div class="sw-casualty-title" style="margin-top:25px;">Casualty Records</div>
<div class="sw-data-table">
    <table class="table">
        <thead><tr><th>Name</th><th>Type</th><th>Severity</th><th>Date</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="4" class="empty">No casualties recorded yet.</td></tr>
        <?php else: foreach ($rows as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= htmlspecialchars($row['type']) ?></td>
                <td><?= htmlspecialchars($row['severity']) ?></td>
                <td><?= htmlspecialchars($row['date']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div id="casualtyModal" class="modal">
    <div class="modal-box sw-casualty-modal">
        <div class="modal-head">
            <h2>Add Casualty</h2>
            <button class="icon-btn" onclick="closeModal('casualtyModal')" aria-label="Close">×</button>
        </div>
        <form method="post" action="casualties.php?event_id=<?= urlencode((string)$eventId) ?>">
            <input type="hidden" name="action" value="add_casualty">
            <input type="hidden" name="event_id" value="<?= htmlspecialchars((string)$eventId) ?>">
            <div class="sw-casualty-form">
                <div class="field"><label>Name</label><input name="name" required placeholder="Enter full name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"></div>
                <div class="field"><label>Type</label><select name="type" required><option value="" selected disabled>Select type</option><option value="Injured" <?= (($_POST['type'] ?? '') === 'Injured') ? 'selected' : '' ?>>Injured</option><option value="Missing" <?= (($_POST['type'] ?? '') === 'Missing') ? 'selected' : '' ?>>Missing</option><option value="Fatality" <?= (($_POST['type'] ?? '') === 'Fatality') ? 'selected' : '' ?>>Fatality</option></select></div>
                <div class="field"><label>Severity</label><select name="severity" required><option value="" selected disabled>Select severity</option><option value="Minor" <?= (($_POST['severity'] ?? '') === 'Minor') ? 'selected' : '' ?>>Minor</option><option value="Moderate" <?= (($_POST['severity'] ?? '') === 'Moderate') ? 'selected' : '' ?>>Moderate</option><option value="Critical" <?= (($_POST['severity'] ?? '') === 'Critical') ? 'selected' : '' ?>>Critical</option></select></div>
                <div class="field"><label>Date</label><input name="date" type="date" required value="<?= htmlspecialchars($_POST['date'] ?? date('Y-m-d')) ?>"></div>
                <div class="field"><label>Notes</label><textarea name="notes" rows="4" placeholder="Add notes or details"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea></div>
            </div>
            <div class="sw-casualty-modal-footer"><button type="button" class="btn btn-light" onclick="closeModal('casualtyModal')">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
        </form>
    </div>
</div>
<?php page_end(); ?>
