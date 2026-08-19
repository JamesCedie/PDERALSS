<?php
require 'includes/layout.php';
page_start('Vehicle Requests');

$rows = [
    ['VR-001', 'Brgy. Jaro',        'Evacuation Transport',   '45', 'High',   '2026-05-01', 'Approved',  'Truck-01'],
    ['VR-002', 'Brgy. Molo',        'Relief Goods Delivery',  '0',  'Medium', '2026-05-02', 'Pending',   '-'],
    ['VR-003', 'Brgy. Mandurriao',  'Medical Emergency',      '3',  'High',   '2026-05-01', 'Scheduled', 'Ambulance-02'],
    ['VR-004', 'Brgy. Arevalo',     'Evacuation Transport',   '30', 'Low',    '2026-05-03', 'Rejected',  '-'],
    ['VR-005', 'Brgy. La Paz',      'Supply Transport',       '0',  'Medium', '2026-05-02', 'Completed', 'Van-03'],
];

$counts = ['Pending' => 1, 'Approved' => 1, 'Scheduled' => 1, 'Completed' => 1];
?>

<div class="page-head">
    <h1 class="page-title">Vehicle Request & Logistics Scheduling</h1>
    <button class="btn btn-primary" onclick="openModal('vehicleModal')">＋ New Request</button>
</div>

<div class="grid g4">
    <?php foreach([['Pending', 'yellow', '⌛'], ['Approved', 'green', '✓'], ['Scheduled', 'blue', '🚚'], ['Completed', 'gray', '✓']] as $s): ?>
        <div class="card">
            <div class="stat">
                <div class="stat-icon <?=$s[1]?>"><?=$s[2]?></div>
                <div>
                    <div class="stat-value"><?=$counts[$s[0]]?></div>
                    <div class="stat-label"><?=$s[0]?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mt">
    <h2>Vehicle Requests</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Barangay</th>
                    <th>Purpose</th>
                    <th>Passengers</th>
                    <th>Urgency</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Assigned Vehicle</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($rows as $r): ?>
                    <tr>
                        <td><?=$r[0]?></td>
                        <td><?=$r[1]?></td>
                        <td><?=$r[2]?></td>
                        <td><?=$r[3]?></td>
                        <td><?=status_badge($r[4])?></td>
                        <td><?=$r[5]?></td>
                        <td><?=status_badge($r[6])?></td>
                        <td><?=$r[7]?></td>
                        <td class="actions">
                            <?php if($r[6] === 'Pending'): ?>
                                <button class="btn btn-success" onclick="alert('Request approved.')">Approve</button>
                                <button class="btn btn-danger" onclick="alert('Request rejected.')">Reject</button>
                            <?php else: ?>
                                <button class="btn btn-light">View</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="vehicleModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>New Vehicle Request</h2>
            <button class="icon-btn" onclick="closeModal('vehicleModal')">✕</button>
        </div>
        <form onsubmit="event.preventDefault();alert('Vehicle request submitted.');closeModal('vehicleModal')">
            <div class="form-grid">
                <div class="field">
                    <label>Barangay</label>
                    <input required>
                </div>
                <div class="field">
                    <label>Purpose</label>
                    <select>
                        <option>Evacuation Transport</option>
                        <option>Relief Goods Delivery</option>
                        <option>Medical Emergency</option>
                        <option>Supply Transport</option>
                    </select>
                </div>
                <div class="field">
                    <label>Passengers</label>
                    <input type="number" min="0">
                </div>
                <div class="field">
                    <label>Urgency</label>
                    <select>
                        <option>High</option>
                        <option>Medium</option>
                        <option>Low</option>
                    </select>
                </div>
                <div class="field">
                    <label>Requested Date</label>
                    <input type="date">
                </div>
                <div class="field">
                    <label>Notes</label>
                    <input>
                </div>
            </div>
            <div class="actions mt">
                <button class="btn btn-primary">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<?php page_end(); ?>
