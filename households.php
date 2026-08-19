<?php
require 'includes/layout.php';
page_start('Household Management');

$rows = [
    ['HH-001', 'Pedro Santos',  'Brgy. Jaro',        '5', 'Fully Damaged',     'High'],
    ['HH-002', 'Maria Reyes',   'Brgy. Molo',        '4', 'Partially Damaged', 'Medium'],
    ['HH-003', 'Jose Garcia',   'Brgy. Mandurriao',  '6', 'Fully Damaged',     'High'],
    ['HH-004', 'Ana Cruz',      'Brgy. Arevalo',     '3', 'Minor Damage',      'Low'],
    ['HH-005', 'Ramon Flores',  'Brgy. La Paz',      '7', 'Fully Damaged',     'High'],
];
?>

<div class="page-head">
    <h1 class="page-title">Household Management</h1>
    <button class="btn btn-primary" onclick="openModal('householdModal')">＋ Add Household</button>
</div>

<div class="grid g4">
    <?php foreach([['1,247', 'Total Households', 'blue'], ['623', 'Fully Damaged', 'red'], ['412', 'Partially Damaged', 'yellow'], ['212', 'Minor / No Damage', 'green']] as $s): ?>
        <div class="card">
            <div class="stat">
                <div class="stat-icon <?=$s[2]?>">👥</div>
                <div>
                    <div class="stat-value"><?=$s[0]?></div>
                    <div class="stat-label"><?=$s[1]?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mt">
    <h2>Household Records</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Family Head</th>
                    <th>Barangay</th>
                    <th>Members</th>
                    <th>Housing Damage</th>
                    <th>Priority</th>
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
                        <td><?=$r[4]?></td>
                        <td><?=status_badge($r[5])?></td>
                        <td class="actions">
                            <button class="btn btn-light">View</button>
                            <button class="btn btn-primary">Edit</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="householdModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Add New Household</h2>
            <button class="icon-btn" onclick="closeModal('householdModal')">✕</button>
        </div>
        <form onsubmit="event.preventDefault();alert('Household saved successfully.');closeModal('householdModal')">
            <div class="form-grid">
                <div class="field">
                    <label>Barangay</label>
                    <select required>
                        <option>Brgy. Jaro</option>
                        <option>Brgy. Molo</option>
                        <option>Brgy. Mandurriao</option>
                        <option>Brgy. Arevalo</option>
                        <option>Brgy. La Paz</option>
                    </select>
                </div>
                <div class="field">
                    <label>Family Head Name</label>
                    <input required>
                </div>
                <div class="field">
                    <label>Date of Birth</label>
                    <input type="date">
                </div>
                <div class="field">
                    <label>Sex</label>
                    <select>
                        <option>Male</option>
                        <option>Female</option>
                    </select>
                </div>
                <div class="field">
                    <label>Civil Status</label>
                    <select>
                        <option>Married</option>
                        <option>Single</option>
                        <option>Widowed</option>
                        <option>Separated</option>
                    </select>
                </div>
                <div class="field">
                    <label>Total Family Members</label>
                    <input type="number" min="1" value="1">
                </div>
                <div class="field">
                    <label>4Ps Member</label>
                    <select>
                        <option>No</option>
                        <option>Yes</option>
                    </select>
                </div>
                <div class="field">
                    <label>PWD Count</label>
                    <input type="number" min="0" value="0">
                </div>
                <div class="field">
                    <label>Senior Citizens</label>
                    <input type="number" min="0" value="0">
                </div>
                <div class="field">
                    <label>Housing Damage</label>
                    <select>
                        <option>Fully Damaged</option>
                        <option>Partially Damaged</option>
                        <option>Minor Damage</option>
                        <option>No Damage</option>
                    </select>
                </div>
            </div>
            <div class="actions mt">
                <button class="btn btn-primary">Save Household</button>
                <button type="button" class="btn btn-light" onclick="closeModal('householdModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php page_end(); ?>
