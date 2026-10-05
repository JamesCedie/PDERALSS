<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';

// The one account nobody should be able to edit or delete through this screen.
const PROTECTED_ADMIN_EMAIL = 'ADMIN@gmail.com';

const ROLES = ['MDRRMO Officer', 'Social Worker'];

// Same barangay list used in the household form.
const BARANGAYS = ['Brgy. Jaro', 'Brgy. Molo', 'Brgy. Mandurriao', 'Brgy. Arevalo', 'Brgy. La Paz'];

function is_protected_admin(array $user): bool
{
    return strcasecmp($user['email'], PROTECTED_ADMIN_EMAIL) === 0;
}

/**
 * Generates the next role-prefixed user ID (e.g. "MD3" or "SW3") by finding
 * the highest existing number for that prefix and incrementing it.
 */
function generate_next_user_id(string $role): string
{
    $prefix = $role === 'MDRRMO Officer' ? 'md' : 'sw';

    $existing = db_select('users', 'user_id LIKE ?', [$prefix . '%'], 'user_id');

    $maxNum = 0;
    foreach ($existing as $row) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $row['user_id'], $m)) {
            $maxNum = max($maxNum, (int) $m[1]);
        }
    }

    return $prefix . ($maxNum + 1);
}

$successMsg = null;
$errorMsg   = null;

// Handle "Add User" submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $firstname = trim($_POST['first_name'] ?? '');
    $lastname  = trim($_POST['last_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $role      = in_array($_POST['role'] ?? '', ROLES, true) ? $_POST['role'] : 'Social Worker';
    $password  = $_POST['password'] ?? '';
    $address   = ($role === 'Social Worker' && in_array($_POST['address'] ?? '', BARANGAYS, true))
        ? $_POST['address']
        : null;

    if ($firstname && $lastname && $username && $email && $password) {
        $existing = db_select_one('users', 'email = ? OR username = ?', [$email, $username]);
        if ($existing) {
            $errorMsg = 'A user with that email or username already exists.';
        } else {
            db_insert('users', [
                'user_id'    => generate_next_user_id($role),
                'first_name' => $firstname,
                'last_name'  => $lastname,
                'username'  => $username,
                'email'     => $email,
                'password'  => password_hash($password, PASSWORD_DEFAULT),
                'role'      => $role,
                'address'   => $address,
            ]);
            $successMsg = 'User account created.';
        }
    } else {
        $errorMsg = 'Please fill in all required fields.';
    }
}

// Handle "Edit User" submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $id = $_POST['user_id'] ?? '';
    $target = db_select_one('users', 'user_id = ?', [$id]);

    if (!$target) {
        $errorMsg = 'User not found.';
    } elseif (is_protected_admin($target)) {
        $errorMsg = 'The admin account cannot be edited.';
    } else {
        $firstname = trim($_POST['first_name'] ?? '');
        $lastname  = trim($_POST['last_name'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $role      = in_array($_POST['role'] ?? '', ROLES, true) ? $_POST['role'] : 'Social Worker';
        $password  = $_POST['password'] ?? '';
        $address   = ($role === 'Social Worker' && in_array($_POST['address'] ?? '', BARANGAYS, true))
            ? $_POST['address']
            : null;

        $data = [
            'first_name' => $firstname,
            'last_name'  => $lastname,
            'username'  => $username,
            'email'     => $email,
            'role'      => $role,
            'address'   => $address,
        ];
        if ($password !== '') {
            $data['password'] = password_hash($password, PASSWORD_DEFAULT);
        }

        db_update('users', $data, 'user_id = ?', [$id]);
        $successMsg = 'User updated.';
    }
}

// Handle "Delete" action
if (isset($_GET['delete'])) {
    $target = db_select_one('users', 'user_id = ?', [$_GET['delete']]);
    if ($target && !is_protected_admin($target)) {
        db_delete('users', 'user_id = ?', [$_GET['delete']]);
    }
    header('Location: users.php');
    exit;
}

require '../includes/layout.php';
page_start('User Management');

$users = db_select('users', '1=1', [], '*', 'user_id DESC');

$roleCounts = [
    'MDRRMO Officer' => 0,
    'Social Worker'  => 0,
];
foreach ($users as $u) {
    if (isset($roleCounts[$u['role']])) $roleCounts[$u['role']]++;
}
?>

<div class="page-head">
    <h1 class="page-title">User Management</h1>
    <button class="btn btn-primary" onclick="openModal('userModal')">＋ Add User</button>
</div>

<?php if ($successMsg): ?>
    <div class="alert alert-success mb"><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
    <div class="alert alert-danger mb"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<div class="grid g3">
    <?php foreach ([
        [count($users), 'Total Users', 'blue'],
        [$roleCounts['MDRRMO Officer'], 'MDRRMO Officers', 'green'],
        [$roleCounts['Social Worker'], 'Social Workers', 'gray'],
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
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Address</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): $protected = is_protected_admin($u); ?>
                    <tr>
                        <td><?= htmlspecialchars($u['user_id']) ?></td>
                        <td><?= htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name'])) ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><?= status_badge($u['role']) ?></td>
                        <td><?= htmlspecialchars($u['address'] ?? '—') ?></td>
                        <td class="actions">
                            <?php if ($protected): ?>
                                <span class="badge b-gray">Protected</span>
                            <?php else: ?>
                                <button class="btn btn-light" onclick="openModal('editModal-<?= $u['user_id'] ?>')">Edit</button>
                                <button class="btn btn-danger"
                                        onclick="if(confirmAction('Delete this user?')) window.location='users.php?delete=<?= urlencode($u['user_id']) ?>'">
                                    Delete
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="userModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Add New User</h2>
            <button class="icon-btn" onclick="closeModal('userModal')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="create_user" value="1">
            <div class="form-grid">
                <div class="field">
                    <label>First Name</label>
                    <input name="first_name" required>
                </div>
                <div class="field">
                    <label>Last Name</label>
                    <input name="last_name" required>
                </div>
                <div class="field">
                    <label>Username</label>
                    <input name="username" required>
                </div>
                <div class="field">
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>
                <div class="field">
                    <label>Role</label>
                    <select name="role" id="addRole" onchange="document.getElementById('addAddressField').style.display = (this.value === 'Social Worker') ? '' : 'none'">
                        <?php foreach (ROLES as $r): ?>
                            <option><?= $r ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" id="addAddressField" style="display:none">
                    <label>Address (Barangay)</label>
                    <select name="address">
                        <?php foreach (BARANGAYS as $b): ?>
                            <option><?= $b ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
            </div>
            <div class="actions mt">
                <button class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($users as $u): if (is_protected_admin($u)) continue; ?>
    <div id="editModal-<?= $u['user_id'] ?>" class="modal">
        <div class="modal-box">
            <div class="modal-head">
                <h2>Edit User</h2>
                <button class="icon-btn" onclick="closeModal('editModal-<?= $u['user_id'] ?>')">✕</button>
            </div>
            <form method="post">
                <input type="hidden" name="edit_user" value="1">
                <input type="hidden" name="user_id" value="<?= htmlspecialchars($u['user_id']) ?>">
                <div class="form-grid">
                    <div class="field">
                        <label>First Name</label>
                        <input name="first_name" value="<?= htmlspecialchars($u['first_name']) ?>" required>
                    </div>
                    <div class="field">
                        <label>Last Name</label>
                        <input name="last_name" value="<?= htmlspecialchars($u['last_name']) ?>" required>
                    </div>
                    <div class="field">
                        <label>Username</label>
                        <input name="username" value="<?= htmlspecialchars($u['username']) ?>" required>
                    </div>
                    <div class="field">
                        <label>Email</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($u['email']) ?>" required>
                    </div>
                    <div class="field">
                        <label>Role</label>
                        <select name="role" id="editRole-<?= $u['user_id'] ?>" onchange="document.getElementById('editAddressField-<?= $u['user_id'] ?>').style.display = (this.value === 'Social Worker') ? '' : 'none'">
                            <?php foreach (ROLES as $r): ?>
                                <option <?= $u['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" id="editAddressField-<?= $u['user_id'] ?>" style="<?= $u['role'] === 'Social Worker' ? '' : 'display:none' ?>">
                        <label>Address (Barangay)</label>
                        <select name="address">
                            <?php foreach (BARANGAYS as $b): ?>
                                <option <?= $u['address'] === $b ? 'selected' : '' ?>><?= $b ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>New Password</label>
                        <input type="password" name="password" placeholder="Leave blank to keep current password">
                    </div>
                </div>
                <div class="actions mt">
                    <button class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<?php page_end(); ?>