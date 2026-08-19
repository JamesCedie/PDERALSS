<?php
require 'includes/layout.php';
page_start('User Management');

$users = [
    ['USR-001', 'Juan Dela Cruz',   'juan.delacruz@mdrrmo.gov.ph',      'MDRRMO Officer',    'Active',   '2026-05-01 08:30 AM'],
    ['USR-002', 'Maria Santos',     'maria.santos@socialwelfare.gov.ph','Social Worker',      'Active',   '2026-05-01 09:15 AM'],
    ['USR-003', 'Pedro Garcia',     'pedro.garcia@brgy-jaro.gov.ph',    'Barangay Official',  'Active',   '2026-04-30 04:20 PM'],
    ['USR-004', 'Ana Reyes',        'ana.reyes@mdrrmo.gov.ph',          'MDRRMO Officer',    'Active',   '2026-05-01 07:45 AM'],
    ['USR-005', 'Carlos Mendoza',   'carlos.mendoza@brgy-molo.gov.ph',  'Barangay Official',  'Inactive', '2026-04-25 02:30 PM'],
];

$perms = [
    'MDRRMO Officer' => [
        'View all data', 'Manage households', 'Manage casualties', 'Approve vehicle requests',
        'Manage evacuation centers', 'Distribute relief goods', 'Generate reports', 'Manage users',
    ],
    'Social Worker' => [
        'View all data', 'Manage households', 'Manage casualties', 'Submit damage assessments',
        'View evacuation centers', 'View relief goods', 'Generate reports',
    ],
    'Barangay Official' => [
        'View barangay data', 'Submit household data', 'Request vehicles', 'Submit damage assessments',
        'View evacuation centers', 'Request relief goods',
    ],
];
?>

<div class="page-head">
    <h1 class="page-title">User Management</h1>
    <button class="btn btn-primary" onclick="openModal('userModal')">＋ Add User</button>
</div>

<div class="grid g3">
    <?php foreach([
        [count($users), 'Total Users', 'blue'],
        [count(array_filter($users, fn($u) => $u[4] === 'Active')), 'Active Users', 'green'],
        [count(array_filter($users, fn($u) => $u[4] === 'Inactive')), 'Inactive Users', 'gray'],
    ] as $s): ?>
        <div class="card">
            <div class="stat">
                <div class="stat-icon <?=$s[2]?>">👤</div>
                <div>
                    <div class="stat-value"><?=$s[0]?></div>
                    <div class="stat-label"><?=$s[1]?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mt">
    <h2>User Accounts</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>User ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($users as $u): ?>
                    <tr>
                        <td><?=$u[0]?></td>
                        <td><?=$u[1]?></td>
                        <td><?=$u[2]?></td>
                        <td><?=status_badge($u[3])?></td>
                        <td><?=status_badge($u[4])?></td>
                        <td><?=$u[5]?></td>
                        <td class="actions">
                            <button class="btn btn-light">Edit</button>
                            <button class="btn btn-danger" onclick="confirmAction('Delete this user?')">Delete</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mt">
    <h2>Role Permissions</h2>
    <div class="grid g3">
        <?php foreach($perms as $role => $list): ?>
            <div>
                <h3><?=$role?></h3>
                <ul class="permission-list">
                    <?php foreach($list as $p): ?>
                        <li><?=$p?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="userModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Add New User</h2>
            <button class="icon-btn" onclick="closeModal('userModal')">✕</button>
        </div>
        <form onsubmit="event.preventDefault();alert('User account created.');closeModal('userModal')">
            <div class="form-grid">
                <div class="field">
                    <label>Full Name</label>
                    <input required>
                </div>
                <div class="field">
                    <label>Email</label>
                    <input type="email" required>
                </div>
                <div class="field">
                    <label>Role</label>
                    <select>
                        <option>MDRRMO Officer</option>
                        <option>Social Worker</option>
                        <option>Barangay Official</option>
                    </select>
                </div>
                <div class="field">
                    <label>Password</label>
                    <input type="password" required>
                </div>
            </div>
            <div class="actions mt">
                <button class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<?php page_end(); ?>
