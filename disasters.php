<?php
require 'includes/layout.php';
page_start('Disaster Events');

$events = [
    ['DE-001', 'Typhoon Odette',         'Typhoon',    '2026-04-27', 'Active',    'High',   'Strong winds and heavy rainfall affected multiple barangays.'],
    ['DE-002', 'Flash Flood Incident',   'Flood',      '2026-04-29', 'Active',    'High',   'Rising water levels prompted evacuation in low-lying areas.'],
    ['DE-003', 'Earthquake Drill Event', 'Earthquake', '2026-05-01', 'Completed', 'Medium', 'Preparedness and response exercise.'],
];

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

<div class="grid g3">
    <?php foreach ($events as $e): ?>
        <div class="card">
            <div class="page-head card-head-gap">
                <div>
                    <h3><?= $e[1] ?></h3>
                    <div class="mini"><?= $e[0] ?> · <?= $e[2] ?></div>
                </div>
                <?= status_badge($e[4]) ?>
            </div>

            <p class="mini"><b>Date:</b> <?= $e[3] ?> · <b>Priority:</b> <?= status_badge($e[5]) ?></p>
            <p class="event-desc"><?= $e[6] ?></p>

            <div class="actions">
                <button class="btn btn-light">View Details</button>
                <button class="btn btn-primary">Manage Response</button>
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

        <form onsubmit="event.preventDefault(); alert('Disaster event added.'); closeModal('eventModal')">
            <div class="form-grid">
                <div class="field">
                    <label>Event Name</label>
                    <input required>
                </div>

                <div class="field">
                    <label>Type</label>
                    <select>
                        <option>Typhoon</option>
                        <option>Flood</option>
                        <option>Earthquake</option>
                        <option>Landslide</option>
                        <option>Fire</option>
                    </select>
                </div>

                <div class="field">
                    <label>Date</label>
                    <input type="date" required>
                </div>

                <div class="field">
                    <label>Priority</label>
                    <select>
                        <option>High</option>
                        <option>Medium</option>
                        <option>Low</option>
                    </select>
                </div>

                <div class="field field-full">
                    <label>Description</label>
                    <textarea></textarea>
                </div>
            </div>

            <div class="actions mt">
                <button class="btn btn-primary">Save Event</button>
            </div>
        </form>
    </div>
</div>

<?php page_end(); ?>