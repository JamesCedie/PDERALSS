<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';

// The address on file for the logged-in social worker becomes the fixed
// barangay for any household they add — no more picking from a dropdown.
$currentUser          = db_select_one('users', 'user_id = ?', [$_SESSION['user']['id'] ?? null]);
$socialWorkerAddress  = $currentUser['address'] ?? '';

$successMsg = null;
$errorMsg   = null;

// Handle "Add Household" submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_household'])) {
    $firstname     = trim($_POST['first_name'] ?? '');
    $middlename    = trim($_POST['middle_name'] ?? '');
    $lastname      = trim($_POST['last_name'] ?? '');
    $nameextension = trim($_POST['name_extension'] ?? '');
    $dob           = $_POST['date_of_birth'] ?? null;
    $sex           = $_POST['sex'] ?? '';
    $civilStatus   = $_POST['civil_status'] ?? '';
    $totalMembers  = (int) ($_POST['total_family_members'] ?? 1);
    $is4ps         = $_POST['is_4ps_member'] ?? 'No';
    $pwdCount      = (int) ($_POST['pwd_count'] ?? 0);
    $seniorCount   = (int) ($_POST['senior_citizens'] ?? 0);

    if ($firstname && $lastname && $socialWorkerAddress) {
        db_insert('household', [
            'firstname'            => $firstname,
            'middlename'           => $middlename ?: null,
            'lastname'             => $lastname,
            'nameextension'        => $nameextension ?: null,
            'date_of_birth'        => $dob ?: null,
            'sex'                  => $sex,
            'civil_status'         => $civilStatus,
            'total_family_members' => $totalMembers,
            'is_4ps_member'        => $is4ps,
            'pwd_count'            => $pwdCount,
            'senior_citizens'      => $seniorCount,
            'barangay'             => $socialWorkerAddress,
            'created_by'           => $_SESSION['user']['id'] ?? null,
        ]);
        $successMsg = 'Household saved successfully.';
    } else {
        $errorMsg = $socialWorkerAddress
            ? 'Please fill in the required fields.'
            : 'Your account has no address on file — ask an MDRRMO Officer to set it before adding households.';
    }
}

// Handle "Edit Household" submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_household'])) {
    $id     = $_POST['household_id'] ?? '';
    $target = db_select_one('household', 'household_id = ?', [$id]);

    if (!$target) {
        $errorMsg = 'Household not found.';
    } elseif ($target['barangay'] !== $socialWorkerAddress) {
        $errorMsg = 'You can only edit households in your own barangay.';
    } else {
        $data = [
            'firstname'            => trim($_POST['first_name'] ?? ''),
            'middlename'           => trim($_POST['middle_name'] ?? '') ?: null,
            'lastname'             => trim($_POST['last_name'] ?? ''),
            'nameextension'        => trim($_POST['name_extension'] ?? '') ?: null,
            'date_of_birth'        => $_POST['date_of_birth'] ?: null,
            'sex'                  => $_POST['sex'] ?? '',
            'civil_status'         => $_POST['civil_status'] ?? '',
            'total_family_members' => (int) ($_POST['total_family_members'] ?? 1),
            'is_4ps_member'        => $_POST['is_4ps_member'] ?? 'No',
            'pwd_count'            => (int) ($_POST['pwd_count'] ?? 0),
            'senior_citizens'      => (int) ($_POST['senior_citizens'] ?? 0),
        ];

        db_update('household', $data, 'household_id = ?', [$id]);
        $successMsg = 'Household updated successfully.';
    }
}

require '../includes/layout.php';
page_start('Household Management');

// Each social worker only manages households in their own barangay.
$households = db_select('household', 'barangay = ?', [$socialWorkerAddress], '*', 'household_id DESC');

$totalHouseholds = count($households);
$fourPsCount     = count(array_filter($households, fn($h) => $h['is_4ps_member'] === 'Yes'));
$totalPwd        = array_sum(array_column($households, 'pwd_count'));
$totalSeniors    = array_sum(array_column($households, 'senior_citizens'));
?>

<div class="page-head">
    <h1 class="page-title">Household Management</h1>
    <button class="btn btn-primary" onclick="openModal('householdModal')">＋ Add Household</button>
</div>

<?php if ($successMsg): ?>
    <div class="alert alert-success mb"><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
    <div class="alert alert-danger mb"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<div class="grid g4">
    <?php foreach([[$totalHouseholds, 'Total Households', 'blue'], [$fourPsCount, '4Ps Member Households', 'green'], [$totalPwd, 'Total PWD', 'yellow'], [$totalSeniors, 'Total Senior Citizens', 'purple']] as $s): ?>
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
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($households)): ?>
                    <tr><td colspan="5" class="empty">No households recorded yet for <?= htmlspecialchars($socialWorkerAddress ?: 'your barangay') ?>.</td></tr>
                <?php endif; ?>
                <?php foreach($households as $h):
                    $fullName = trim($h['firstname'] . ' ' . $h['middlename'] . ' ' . $h['lastname'] . ' ' . $h['nameextension']);
                    $fullName = preg_replace('/\s+/', ' ', $fullName);
                ?>
                    <tr>
                        <td><?= htmlspecialchars($h['household_id']) ?></td>
                        <td><?= htmlspecialchars($fullName) ?></td>
                        <td><?= htmlspecialchars($h['barangay']) ?></td>
                        <td><?= htmlspecialchars($h['total_family_members']) ?></td>
                        <td class="actions">
                            <button class="btn btn-light" onclick="openModal('viewModal-<?= $h['household_id'] ?>')">View</button>
                            <button class="btn btn-primary" onclick="openModal('editModal-<?= $h['household_id'] ?>')">Edit</button>
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
        <form method="post">
            <input type="hidden" name="add_household" value="1">
            <div class="form-grid">
                <div class="field">
                    <label>Barangay</label>
                    <input type="text" value="<?= htmlspecialchars($socialWorkerAddress) ?>" readonly>
                </div>
                <div class="field">
                    <label>First Name</label>
                    <input name="first_name" required>
                </div>
                <div class="field">
                    <label>Middle Name</label>
                    <input name="middle_name" placeholder="Optional">
                </div>
                <div class="field">
                    <label>Last Name</label>
                    <input name="last_name" required>
                </div>
                <div class="field">
                    <label>Name Extension</label>
                    <input name="name_extension" placeholder="Jr., Sr., III, etc. (optional)">
                </div>
                <div class="field">
                    <label>Date of Birth</label>
                    <input type="date" name="date_of_birth">
                </div>
                <div class="field">
                    <label>Sex</label>
                    <select name="sex">
                        <option>Male</option>
                        <option>Female</option>
                    </select>
                </div>
                <div class="field">
                    <label>Civil Status</label>
                    <select name="civil_status">
                        <option>Married</option>
                        <option>Single</option>
                        <option>Widowed</option>
                        <option>Separated</option>
                    </select>
                </div>
                <div class="field">
                    <label>Total Family Members</label>
                    <input type="number" name="total_family_members" min="1" value="1">
                </div>
                <div class="field">
                    <label>4Ps Member</label>
                    <select name="is_4ps_member">
                        <option>No</option>
                        <option>Yes</option>
                    </select>
                </div>
                <div class="field">
                    <label>PWD Count</label>
                    <input type="number" name="pwd_count" min="0" value="0">
                </div>
                <div class="field">
                    <label>Senior Citizens</label>
                    <input type="number" name="senior_citizens" min="0" value="0">
                </div>
            </div>
            <div class="actions mt">
                <button class="btn btn-primary">Save Household</button>
                <button type="button" class="btn btn-light" onclick="closeModal('householdModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($households as $h):
    $fullName = trim($h['firstname'] . ' ' . $h['middlename'] . ' ' . $h['lastname'] . ' ' . $h['nameextension']);
    $fullName = preg_replace('/\s+/', ' ', $fullName);
?>
    <!-- View Household -->
    <div id="viewModal-<?= $h['household_id'] ?>" class="modal">
        <div class="modal-box">
            <div class="modal-head">
                <h2>Household Details</h2>
                <button class="icon-btn" onclick="closeModal('viewModal-<?= $h['household_id'] ?>')">✕</button>
            </div>
            <div class="form-grid">
                <div class="field"><label>Household ID</label><div><?= htmlspecialchars($h['household_id']) ?></div></div>
                <div class="field"><label>Barangay</label><div><?= htmlspecialchars($h['barangay']) ?></div></div>
                <div class="field"><label>Full Name</label><div><?= htmlspecialchars($fullName) ?></div></div>
                <div class="field"><label>Date of Birth</label><div><?= htmlspecialchars($h['date_of_birth'] ?? '—') ?></div></div>
                <div class="field"><label>Sex</label><div><?= htmlspecialchars($h['sex'] ?? '—') ?></div></div>
                <div class="field"><label>Civil Status</label><div><?= htmlspecialchars($h['civil_status'] ?? '—') ?></div></div>
                <div class="field"><label>Total Family Members</label><div><?= htmlspecialchars($h['total_family_members']) ?></div></div>
                <div class="field"><label>4Ps Member</label><div><?= htmlspecialchars($h['is_4ps_member']) ?></div></div>
                <div class="field"><label>PWD Count</label><div><?= htmlspecialchars($h['pwd_count']) ?></div></div>
                <div class="field"><label>Senior Citizens</label><div><?= htmlspecialchars($h['senior_citizens']) ?></div></div>
            </div>
            <div class="actions mt">
                <button type="button" class="btn btn-light" onclick="closeModal('viewModal-<?= $h['household_id'] ?>')">Close</button>
            </div>
        </div>
    </div>

    <!-- Edit Household -->
    <div id="editModal-<?= $h['household_id'] ?>" class="modal">
        <div class="modal-box">
            <div class="modal-head">
                <h2>Edit Household</h2>
                <button class="icon-btn" onclick="closeModal('editModal-<?= $h['household_id'] ?>')">✕</button>
            </div>
            <form method="post">
                <input type="hidden" name="edit_household" value="1">
                <input type="hidden" name="household_id" value="<?= htmlspecialchars($h['household_id']) ?>">
                <div class="form-grid">
                    <div class="field">
                        <label>Barangay</label>
                        <input type="text" value="<?= htmlspecialchars($h['barangay']) ?>" readonly>
                    </div>
                    <div class="field">
                        <label>First Name</label>
                        <input name="first_name" value="<?= htmlspecialchars($h['firstname']) ?>" required>
                    </div>
                    <div class="field">
                        <label>Middle Name</label>
                        <input name="middle_name" value="<?= htmlspecialchars($h['middlename'] ?? '') ?>" placeholder="Optional">
                    </div>
                    <div class="field">
                        <label>Last Name</label>
                        <input name="last_name" value="<?= htmlspecialchars($h['lastname']) ?>" required>
                    </div>
                    <div class="field">
                        <label>Name Extension</label>
                        <input name="name_extension" value="<?= htmlspecialchars($h['nameextension'] ?? '') ?>" placeholder="Jr., Sr., III, etc. (optional)">
                    </div>
                    <div class="field">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth" value="<?= htmlspecialchars($h['date_of_birth'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label>Sex</label>
                        <select name="sex">
                            <?php foreach (['Male', 'Female'] as $opt): ?>
                                <option <?= $h['sex'] === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Civil Status</label>
                        <select name="civil_status">
                            <?php foreach (['Married', 'Single', 'Widowed', 'Separated'] as $opt): ?>
                                <option <?= $h['civil_status'] === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Total Family Members</label>
                        <input type="number" name="total_family_members" min="1" value="<?= htmlspecialchars($h['total_family_members']) ?>">
                    </div>
                    <div class="field">
                        <label>4Ps Member</label>
                        <select name="is_4ps_member">
                            <?php foreach (['No', 'Yes'] as $opt): ?>
                                <option <?= $h['is_4ps_member'] === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>PWD Count</label>
                        <input type="number" name="pwd_count" min="0" value="<?= htmlspecialchars($h['pwd_count']) ?>">
                    </div>
                    <div class="field">
                        <label>Senior Citizens</label>
                        <input type="number" name="senior_citizens" min="0" value="<?= htmlspecialchars($h['senior_citizens']) ?>">
                    </div>
                </div>
                <div class="actions mt">
                    <button class="btn btn-primary">Save Changes</button>
                    <button type="button" class="btn btn-light" onclick="closeModal('editModal-<?= $h['household_id'] ?>')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<?php page_end(); ?>