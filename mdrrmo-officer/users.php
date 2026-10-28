<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';
require_once '../includes/password.php';

// The one account nobody should be able to edit or delete through this screen.
const PROTECTED_ADMIN_EMAIL = 'ADMIN@gmail.com';

const ROLES = ['MDRRMO Officer', 'Social Worker'];

// Barangay list comes from the barangays table (brgy_names()).
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

require_once '../includes/otp.php';

// AJAX: send an email-verification OTP to the address typed in the Add User form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email_otp'])) {
    header('Content-Type: application/json');
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'message' => 'Enter a valid email first.']); exit; }
    if (db_select_one('users', 'email = ?', [$email])) { echo json_encode(['success' => false, 'message' => 'That email is already used.']); exit; }
    $prev = $_SESSION['email_verify'] ?? null;
    if ($prev && time() - $prev['sent_at'] < 60) { echo json_encode(['success' => false, 'message' => 'Please wait a minute before resending.']); exit; }

    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    if ($err = otp_send_email($email, $otp)) { echo json_encode(['success' => false, 'message' => $err]); exit; }
    $_SESSION['email_verify'] = [
        'email'    => strtolower($email),
        'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
        'expires'  => time() + 300,
        'sent_at'  => time(),
        'tries'    => 0,
        'verified' => false,
    ];
    echo json_encode(['success' => true, 'message' => 'OTP sent to ' . $email . '.']);
    exit;
}

// AJAX: check the code the officer typed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_email_otp'])) {
    header('Content-Type: application/json');
    $v     = $_SESSION['email_verify'] ?? null;
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!$v || $v['email'] !== $email || time() > $v['expires']) { echo json_encode(['success' => false, 'message' => 'OTP expired. Send a new one.']); exit; }
    if ($v['tries'] >= 5) { echo json_encode(['success' => false, 'message' => 'Too many attempts. Send a new OTP.']); exit; }
    $_SESSION['email_verify']['tries']++;
    if (!password_verify(trim($_POST['otp_code'] ?? ''), $v['otp_hash'])) { echo json_encode(['success' => false, 'message' => 'Invalid OTP code.']); exit; }
    $_SESSION['email_verify']['verified'] = true;
    echo json_encode(['success' => true, 'message' => 'Email verified.']);
    exit;
}

// Handle "Add User" submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $firstname = trim($_POST['first_name'] ?? '');
    $lastname  = trim($_POST['last_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $role      = in_array($_POST['role'] ?? '', ROLES, true) ? $_POST['role'] : 'Social Worker';
    $password  = $_POST['password'] ?? '';
    $barangayId = ($role === 'Social Worker') ? (brgy_id($_POST['address'] ?? '') ?: null) : null;

    $ev = $_SESSION['email_verify'] ?? null;
    if (!$ev || empty($ev['verified']) || $ev['email'] !== strtolower($email)) {
        $errorMsg = 'Please verify the email with an OTP first.';
    } elseif ($password !== '' && ($pwErr = password_policy_error($password))) {
        $errorMsg = $pwErr;
    } elseif ($firstname && $lastname && $username && $email && $password) {
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
                'barangay_id' => $barangayId,
            ]);
            unset($_SESSION['email_verify']);
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
        $barangayId = ($role === 'Social Worker') ? (brgy_id($_POST['address'] ?? '') ?: null) : null;

        $data = [
            'first_name' => $firstname,
            'last_name'  => $lastname,
            'username'  => $username,
            'email'     => $email,
            'role'      => $role,
            'barangay_id' => $barangayId,
        ];
        $pwErr = $password !== '' ? password_policy_error($password) : null;
        if ($pwErr) {
            $errorMsg = $pwErr;
        } else {
            if ($password !== '') {
                db_ensure_login_lock();
                $data['password'] = password_hash($password, PASSWORD_DEFAULT);
                $data['failed_attempts'] = 0;   // a new password also unlocks the account
            }
            db_update('users', $data, 'user_id = ?', [$id]);
            $successMsg = 'User updated.';
        }
    }
}

// Handle "Delete" action
if (isset($_GET['delete'])) {
    $target = db_select_one('users', 'user_id = ?', [$_GET['delete']]);
    if ($target && !is_protected_admin($target)) {
        try {
            db_delete('users', 'user_id = ?', [$_GET['delete']]);
        } catch (PDOException $e) {
            // vehicle_requests.requested_by is RESTRICT: a user with requests on record cannot be removed.
            $errorMsg = 'This user has vehicle requests on record and cannot be deleted.';
        }
    }
    if (empty($errorMsg)) {
        header('Location: users.php');
        exit;
    }
}

require '../includes/layout.php';
page_start('User Management');

$users = db_query('SELECT u.*, b.name AS address FROM users u LEFT JOIN barangays b ON b.barangay_id = u.barangay_id ORDER BY u.user_id DESC')->fetchAll();

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
    <button class="btn btn-primary" onclick="openModal('userModal')">Add User</button>
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
        <div class="card sw-summary-card">
            <div class="stat-label"><?=$s[1]?></div>
            <div class="stat-value"><?=$s[0]?></div>
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
            <button class="icon-btn" onclick="closeModal('userModal')" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
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
                    <input type="email" name="email" id="addEmail" required>
                    <button type="button" class="btn btn-light" id="sendEmailOtpBtn" onclick="sendEmailOtp()" style="margin-top:6px">Send OTP</button>
                    <div id="emailOtpBox" style="display:none;margin-top:6px">
                        <input type="text" id="addEmailOtp" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="6-digit code">
                        <button type="button" class="btn btn-light" onclick="verifyEmailOtp()" style="margin-top:6px">Verify</button>
                    </div>
                    <span class="mini" id="emailOtpStatus"></span>
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
                        <?php foreach (brgy_names() as $b): ?>
                            <option><?= $b ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Password</label>
                    <input type="password" name="password" required pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s]).{8,16}" title="8-16 characters with uppercase and lowercase letters, a number and a special character." maxlength="16">
                    <span class="mini">8-16 characters, uppercase + lowercase, a number and a special character.</span>
                </div>
            </div>
            <div class="actions mt">
                <button class="btn btn-primary" id="createUserBtn" disabled>Create User</button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($users as $u): if (is_protected_admin($u)) continue; ?>
    <div id="editModal-<?= $u['user_id'] ?>" class="modal">
        <div class="modal-box">
            <div class="modal-head">
                <h2>Edit User</h2>
                <button class="icon-btn" onclick="closeModal('editModal-<?= $u['user_id'] ?>')" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
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
                            <?php foreach (brgy_names() as $b): ?>
                                <option <?= $u['address'] === $b ? 'selected' : '' ?>><?= $b ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>New Password</label>
                        <input type="password" name="password" placeholder="Leave blank to keep current password" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s]).{8,16}" title="8-16 characters with uppercase and lowercase letters, a number and a special character." maxlength="16">
                        <span class="mini">8-16 characters, uppercase + lowercase, a number and a special character.</span>
                    </div>
                </div>
                <div class="actions mt">
                    <button class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<script>
(function () {
    const post = (body) => fetch('users.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body
    }).then(r => r.json());
    const $ = (id) => document.getElementById(id);

    // Typing a different email invalidates any earlier verification.
    $('addEmail').addEventListener('input', function () {
        $('createUserBtn').disabled = true;
        $('emailOtpBox').style.display = 'none';
        $('emailOtpStatus').textContent = '';
        $('sendEmailOtpBtn').disabled = false;
        $('sendEmailOtpBtn').textContent = 'Send OTP';
    });

    window.sendEmailOtp = function () {
        const email = $('addEmail').value.trim();
        if (!email) { alert('Enter the email first.'); return; }
        const btn = $('sendEmailOtpBtn');
        btn.disabled = true; btn.textContent = 'Sending...';
        post('send_email_otp=1&email=' + encodeURIComponent(email)).then(function (d) {
            $('emailOtpStatus').textContent = d.message || '';
            if (d.success) { $('emailOtpBox').style.display = ''; btn.textContent = 'OTP sent'; }
            else { btn.disabled = false; btn.textContent = 'Send OTP'; }
        }).catch(function () { btn.disabled = false; btn.textContent = 'Send OTP'; alert('Something went wrong.'); });
    };

    window.verifyEmailOtp = function () {
        const email = $('addEmail').value.trim();
        const code = $('addEmailOtp').value.trim();
        post('verify_email_otp=1&email=' + encodeURIComponent(email) + '&otp_code=' + encodeURIComponent(code))
            .then(function (d) {
                $('emailOtpStatus').textContent = d.message || '';
                $('createUserBtn').disabled = !d.success;
            }).catch(function () { alert('Something went wrong.'); });
    };
})();
</script>

<?php page_end(); ?>
