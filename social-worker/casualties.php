<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';
db_ensure_disaster_event_status();

$eventId = (int) ($_GET['event_id'] ?? 0);
$currentEvent = $eventId ? db_select_one('disaster_events', 'event_id = ?', [$eventId]) : null;
require '../includes/layout.php';
page_start('Casualty Recording');

$rows = [];
?>

<div class="sw-section-head">
    <div class="sw-event-label">
        Event:<strong><?= htmlspecialchars(($currentEvent['event_name'] ?? 'Typhoon') . ' · ' . ($currentEvent ? 'Arevalo' : 'Arevalo')) ?></strong>
    </div>
    <button class="btn btn-primary" onclick="openModal('casualtyModal')">＋ &nbsp;Add Casualty</button>
</div>

<div class="sw-casualty-title">Casualty Count</div>
<div class="grid g2 sw-casualty-grid">
    <div class="card sw-total-card">
        <div class="stat-label">Total People</div>
        <div class="stat-value">0</div>
    </div>
    <div class="card sw-breakdown-card">
        <h3>Casualty Breakdown</h3>
        <div class="sw-breakdown">
            <div class="sw-breakdown-item fatal"><label>Fatalities</label><strong>0</strong></div>
            <div class="sw-breakdown-item missing"><label>Missing</label><strong>0</strong></div>
            <div class="sw-breakdown-item injured"><label>Injured</label><strong>0</strong></div>
        </div>
    </div>
</div>

<div class="sw-casualty-title" style="margin-top:25px;">Casualty Records</div>
<div class="sw-data-table">
    <table class="table">
        <thead><tr><th>Name</th><th>Type</th><th>Severity</th><th>Date</th></tr></thead>
        <tbody><tr><td colspan="4" class="empty">No casualties recorded yet.</td></tr></tbody>
    </table>
</div>

<div id="casualtyModal" class="modal">
    <div class="modal-box sw-casualty-modal">
        <div class="modal-head">
            <h2>Add Casualty</h2>
            <button class="icon-btn" onclick="closeModal('casualtyModal')" aria-label="Close">×</button>
        </div>
        <form onsubmit="event.preventDefault(); showToast('Casualty record saved.', 'success'); closeModal('casualtyModal');">
            <div class="sw-casualty-form">
                <div class="field"><label>Name</label><input required placeholder="Enter full name"></div>
                <div class="field"><label>Type</label><select><option value="" selected>Select type</option><option>Injured</option><option>Missing</option><option>Fatality</option></select></div>
                <div class="field"><label>Severity</label><select><option value="" selected>Select severity</option><option>Minor</option><option>Moderate</option><option>Critical</option></select></div>
                <div class="field"><label>Date</label><input type="date" placeholder="Select date"></div>
                <div class="field"><label>Notes</label><textarea rows="4" placeholder="Add notes or details"></textarea></div>
            </div>
            <div class="sw-casualty-modal-footer"><button class="btn btn-primary">Save</button></div>
        </form>
    </div>
</div>
<?php page_end(); ?>
