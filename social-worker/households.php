<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';

$currentUser         = db_select_one('users', 'user_id = ?', [$_SESSION['user']['id'] ?? null]);
$socialWorkerAddress = $currentUser['address'] ?? '';

$successMsg = null;
$errorMsg   = null;

const EDU_OPTIONS = [
    'Elementary',
    'Highschool Undergraduate',
    'Highschool Graduate',
    'College Undergraduate',
    'College Graduate',
];

const CIVIL_OPTIONS = ['Married', 'Single', 'Widowed', 'Separated'];

// ── Stage 1: Save household head ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stage']) && $_POST['stage'] === '1') {
    $firstname     = trim($_POST['first_name'] ?? '');
    $middlename    = trim($_POST['middle_name'] ?? '');
    $lastname      = trim($_POST['last_name'] ?? '');
    $nameextension = trim($_POST['name_extension'] ?? '');
    $age           = (int) ($_POST['age'] ?? 0);
    $sex           = $_POST['sex'] ?? '';
    $civilStatus   = $_POST['civil_status'] ?? '';
    $education     = $_POST['educational_attainment'] ?? '';
    $occupation    = trim($_POST['occupation'] ?? '');
    $dob           = $_POST['date_of_birth'] ?? null;
    $contact       = trim($_POST['contact_no'] ?? '');
    $is4ps         = $_POST['is_4ps_member'] ?? 'No';
    $totalMembers  = max(1, (int) ($_POST['total_family_members'] ?? 1));
    $pwdCount      = (int) ($_POST['pwd_count'] ?? 0);
    $seniorCount   = (int) ($_POST['senior_citizens'] ?? 0);

    if ($firstname && $lastname && $socialWorkerAddress) {
        $row = db_insert('household', [
            'firstname'             => $firstname,
            'middlename'            => $middlename ?: null,
            'lastname'              => $lastname,
            'nameextension'         => $nameextension ?: null,
            'age'                   => $age ?: null,
            'date_of_birth'         => $dob ?: null,
            'sex'                   => $sex,
            'civil_status'          => $civilStatus,
            'educational_attainment'=> $education,
            'occupation'            => $occupation ?: null,
            'contact_no'            => $contact ?: null,
            'total_family_members'  => $totalMembers,
            'is_4ps_member'         => $is4ps,
            'pwd_count'             => $pwdCount,
            'senior_citizens'       => $seniorCount,
            'barangay'              => $socialWorkerAddress,
            'created_by'            => $_SESSION['user']['id'] ?? null,
        ]);

        if ($row && $totalMembers > 1) {
            // Move to stage 2 — pass the new household_id via session
            $_SESSION['pending_household_id']    = $row['household_id'];
            $_SESSION['pending_total_members']   = $totalMembers;
            header('Location: households.php?stage=2');
            exit;
        }

        $_SESSION['flash_success'] = 'Household saved successfully.';
        header('Location: households.php');
        exit;
    } else {
        $errorMsg = $socialWorkerAddress
            ? 'Please fill in all required fields.'
            : 'Your account has no address on file — ask an MDRRMO Officer to set it.';
    }
}

// ── Stage 2: Save family composition members ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stage']) && $_POST['stage'] === '2') {
    $householdId  = $_SESSION['pending_household_id'] ?? null;
    $totalMembers = $_SESSION['pending_total_members'] ?? 0;

    if ($householdId) {
        $membersToAdd = $totalMembers - 1; // head already saved in stage 1
        for ($i = 0; $i < $membersToAdd; $i++) {
            $fn = trim($_POST["member_{$i}_first_name"] ?? '');
            $ln = trim($_POST["member_{$i}_last_name"] ?? '');
            if (!$fn || !$ln) continue;

            db_insert('family_composition', [
                'household_id'           => $householdId,
                'first_name'             => $fn,
                'middle_name'            => trim($_POST["member_{$i}_middle_name"] ?? '') ?: null,
                'last_name'              => $ln,
                'age'                    => (int) ($_POST["member_{$i}_age"] ?? 0) ?: null,
                'sex'                    => $_POST["member_{$i}_sex"] ?? '',
                'civil_status'           => $_POST["member_{$i}_civil_status"] ?? '',
                'relationship'           => trim($_POST["member_{$i}_relationship"] ?? '') ?: null,
                'educational_attainment' => $_POST["member_{$i}_educational_attainment"] ?? '',
                'occupation'             => trim($_POST["member_{$i}_occupation"] ?? '') ?: null,
            ]);
        }
        unset($_SESSION['pending_household_id'], $_SESSION['pending_total_members']);
    }

    $_SESSION['flash_success'] = 'Household and family composition saved.';
    header('Location: households.php');
    exit;
}

// ── Edit household ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_household'])) {
    $id     = $_POST['household_id'] ?? '';
    $target = db_select_one('household', 'household_id = ?', [$id]);

    if (!$target) {
        $errorMsg = 'Household not found.';
    } elseif ($target['barangay'] !== $socialWorkerAddress) {
        $errorMsg = 'You can only edit households in your own barangay.';
    } else {
        db_update('household', [
            'firstname'              => trim($_POST['first_name'] ?? ''),
            'middlename'             => trim($_POST['middle_name'] ?? '') ?: null,
            'lastname'               => trim($_POST['last_name'] ?? ''),
            'nameextension'          => trim($_POST['name_extension'] ?? '') ?: null,
            'age'                    => (int) ($_POST['age'] ?? 0) ?: null,
            'date_of_birth'          => $_POST['date_of_birth'] ?: null,
            'sex'                    => $_POST['sex'] ?? '',
            'civil_status'           => $_POST['civil_status'] ?? '',
            'educational_attainment' => $_POST['educational_attainment'] ?? '',
            'occupation'             => trim($_POST['occupation'] ?? '') ?: null,
            'contact_no'             => trim($_POST['contact_no'] ?? '') ?: null,
            'total_family_members'   => max(1, (int) ($_POST['total_family_members'] ?? 1)),
            'is_4ps_member'          => $_POST['is_4ps_member'] ?? 'No',
            'pwd_count'              => (int) ($_POST['pwd_count'] ?? 0),
            'senior_citizens'        => (int) ($_POST['senior_citizens'] ?? 0),
        ], 'household_id = ?', [$id]);

        // Update each family composition member submitted in the edit form
        $memberIds = $_POST['member_ids'] ?? [];
        foreach ($memberIds as $idx => $compId) {
            if (!$compId) continue;
            db_update('family_composition', [
                'first_name'             => trim($_POST["comp_{$idx}_first_name"] ?? ''),
                'middle_name'            => trim($_POST["comp_{$idx}_middle_name"] ?? '') ?: null,
                'last_name'              => trim($_POST["comp_{$idx}_last_name"] ?? ''),
                'age'                    => (int) ($_POST["comp_{$idx}_age"] ?? 0) ?: null,
                'sex'                    => $_POST["comp_{$idx}_sex"] ?? '',
                'civil_status'           => $_POST["comp_{$idx}_civil_status"] ?? '',
                'relationship'           => trim($_POST["comp_{$idx}_relationship"] ?? '') ?: null,
                'educational_attainment' => $_POST["comp_{$idx}_educational_attainment"] ?? '',
                'occupation'             => trim($_POST["comp_{$idx}_occupation"] ?? '') ?: null,
            ], 'composition_id = ?', [$compId]);
        }

        $_SESSION['flash_success'] = 'Household updated.';
        header('Location: households.php');
        exit;
    }
}

// ── Stage 2: Back to Step 1 (delete the household row just created and return to Add form) ──
if (isset($_GET['stage']) && $_GET['stage'] === 'back' && isset($_SESSION['pending_household_id'])) {
    $idToDelete = $_SESSION['pending_household_id'];
    db_delete('household', 'household_id = ?', [$idToDelete]);
    unset($_SESSION['pending_household_id'], $_SESSION['pending_total_members']);
    header('Location: households.php?reopen=1');
    exit;
}
require '../includes/layout.php';
page_start('Household Management');

if (isset($_SESSION['flash_success'])) {
    $successMsg = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// ── Stage 2 view ─────────────────────────────────────────────────────────────
if (isset($_GET['stage']) && $_GET['stage'] === '2' && isset($_SESSION['pending_household_id'])) {
    $totalMembers = $_SESSION['pending_total_members'] ?? 1;
    $membersToAdd = $totalMembers - 1;
    ?>
    <div class="page-head">
        <h1 class="page-title">Family Composition</h1>
        <div class="mini">Step 2 of 2 — Enter details for each family member</div>
    </div>
    <div class="card">
        <form method="post">
            <input type="hidden" name="stage" value="2">
            <?php for ($i = 0; $i < $membersToAdd; $i++): ?>
                <h3 style="margin:18px 0 10px;">Member <?= $i + 2 ?></h3>
                <div class="form-grid">
                    <div class="field">
                        <label>First Name</label>
                        <input name="member_<?= $i ?>_first_name" required>
                    </div>
                    <div class="field">
                        <label>Middle Name</label>
                        <input name="member_<?= $i ?>_middle_name" placeholder="Optional">
                    </div>
                    <div class="field">
                        <label>Last Name</label>
                        <input name="member_<?= $i ?>_last_name" required>
                    </div>
                    <div class="field">
                        <label>Age</label>
                        <input type="number" name="member_<?= $i ?>_age" min="0">
                    </div>
                    <div class="field">
                        <label>Sex</label>
                        <select name="member_<?= $i ?>_sex">
                            <option>Male</option>
                            <option>Female</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Civil Status</label>
                        <select name="member_<?= $i ?>_civil_status">
                            <?php foreach (CIVIL_OPTIONS as $opt): ?>
                                <option><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Relationship to Head</label>
                        <input name="member_<?= $i ?>_relationship" placeholder="e.g. Spouse, Child, Parent">
                    </div>
                    <div class="field">
                        <label>Educational Attainment</label>
                        <select name="member_<?= $i ?>_educational_attainment">
                            <?php foreach (EDU_OPTIONS as $opt): ?>
                                <option><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Occupation</label>
                        <input name="member_<?= $i ?>_occupation" placeholder="Optional">
                    </div>
                </div>
                <?php if ($i < $membersToAdd - 1): ?>
                    <hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0;">
                <?php endif; ?>
            <?php endfor; ?>
            <div class="actions mt">
                <a href="households.php?stage=back" class="btn btn-light">← Back to Step 1</a>
                <button class="btn btn-primary">Save Family Composition</button>
            </div>
        </form>
    </div>
    <?php
    page_end();
    exit;
}

// ── Normal household list view ────────────────────────────────────────────────
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
    <?php foreach([[$totalHouseholds, 'Total Households'], [$fourPsCount, '4Ps Member Households'], [$totalPwd, 'Total PWD'], [$totalSeniors, 'Total Senior Citizens']] as $s): ?>
        <div class="card">
            <div class="stat-value"><?= $s[0] ?></div>
            <div class="stat-label"><?= $s[1] ?></div>
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
                    $fullName = preg_replace('/\s+/', ' ', trim(implode(' ', array_filter([
                        $h['firstname'], $h['middlename'], $h['lastname'], $h['nameextension']
                    ]))));
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

<!-- Add Household Modal (Stage 1) -->
<div id="householdModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Add New Household — Step 1 of 2</h2>
            <button class="icon-btn" onclick="closeModal('householdModal')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="stage" value="1">
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
                    <input name="name_extension" placeholder="Jr., Sr., III, etc.">
                </div>
                <div class="field">
                    <label>Age</label>
                    <input type="number" name="age" min="0">
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
                        <?php foreach (CIVIL_OPTIONS as $opt): ?>
                            <option><?= $opt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Educational Attainment</label>
                    <select name="educational_attainment">
                        <?php foreach (EDU_OPTIONS as $opt): ?>
                            <option><?= $opt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Occupation</label>
                    <input name="occupation" placeholder="Optional">
                </div>
                <div class="field">
                    <label>Date of Birth</label>
                    <input type="date" name="date_of_birth">
                </div>
                <div class="field">
                    <label>Contact No.</label>
                    <input name="contact_no" maxlength="11" pattern="\d{11}" placeholder="11 digits">
                </div>
                <div class="field">
                    <label>4Ps Member</label>
                    <select name="is_4ps_member">
                        <option>No</option>
                        <option>Yes</option>
                    </select>
                </div>
                <div class="field">
                    <label>Total Family Members</label>
                    <input type="number" name="total_family_members" min="1" value="1">
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
                <button class="btn btn-primary">Next: Family Composition →</button>
                <button type="button" class="btn btn-light" onclick="closeModal('householdModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- View / Edit modals per household -->
<?php foreach ($households as $h):
    $fullName = preg_replace('/\s+/', ' ', trim(implode(' ', array_filter([
        $h['firstname'], $h['middlename'], $h['lastname'], $h['nameextension']
    ]))));
    $members = db_select('family_composition', 'household_id = ?', [$h['household_id']], '*', 'composition_id ASC');
?>

<!-- View Modal -->
<div id="viewModal-<?= $h['household_id'] ?>" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Household Details</h2>
            <button class="icon-btn" onclick="closeModal('viewModal-<?= $h['household_id'] ?>')">✕</button>
        </div>
        <h3>Household Head</h3>
        <div class="form-grid">
            <div class="field"><label>Full Name</label><div><?= htmlspecialchars($fullName) ?></div></div>
            <div class="field"><label>Barangay</label><div><?= htmlspecialchars($h['barangay']) ?></div></div>
            <div class="field"><label>Age</label><div><?= htmlspecialchars($h['age'] ?? '—') ?></div></div>
            <div class="field"><label>Date of Birth</label><div><?= htmlspecialchars($h['date_of_birth'] ?? '—') ?></div></div>
            <div class="field"><label>Sex</label><div><?= htmlspecialchars($h['sex'] ?? '—') ?></div></div>
            <div class="field"><label>Civil Status</label><div><?= htmlspecialchars($h['civil_status'] ?? '—') ?></div></div>
            <div class="field"><label>Educational Attainment</label><div><?= htmlspecialchars($h['educational_attainment'] ?? '—') ?></div></div>
            <div class="field"><label>Occupation</label><div><?= htmlspecialchars($h['occupation'] ?? '—') ?></div></div>
            <div class="field"><label>Contact No.</label><div><?= htmlspecialchars($h['contact_no'] ?? '—') ?></div></div>
            <div class="field"><label>4Ps Member</label><div><?= htmlspecialchars($h['is_4ps_member']) ?></div></div>
            <div class="field"><label>Total Family Members</label><div><?= htmlspecialchars($h['total_family_members']) ?></div></div>
            <div class="field"><label>PWD Count</label><div><?= htmlspecialchars($h['pwd_count']) ?></div></div>
            <div class="field"><label>Senior Citizens</label><div><?= htmlspecialchars($h['senior_citizens']) ?></div></div>
        </div>

        <?php if (!empty($members)): ?>
            <h3 style="margin-top:18px;">Family Composition</h3>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Age</th>
                            <th>Sex</th>
                            <th>Civil Status</th>
                            <th>Relationship</th>
                            <th>Education</th>
                            <th>Occupation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $m): ?>
                            <tr>
                                <td><?= htmlspecialchars(trim($m['first_name'] . ' ' . $m['middle_name'] . ' ' . $m['last_name'])) ?></td>
                                <td><?= htmlspecialchars($m['age'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($m['sex'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($m['civil_status'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($m['relationship'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($m['educational_attainment'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($m['occupation'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="actions mt">
            <button type="button" class="btn btn-light" onclick="closeModal('viewModal-<?= $h['household_id'] ?>')">Close</button>
        </div>
    </div>
</div>

<!-- Edit Modal -->
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
                    <input name="middle_name" value="<?= htmlspecialchars($h['middlename'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Last Name</label>
                    <input name="last_name" value="<?= htmlspecialchars($h['lastname']) ?>" required>
                </div>
                <div class="field">
                    <label>Name Extension</label>
                    <input name="name_extension" value="<?= htmlspecialchars($h['nameextension'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Age</label>
                    <input type="number" name="age" min="0" value="<?= htmlspecialchars($h['age'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Sex</label>
                    <select name="sex">
                        <?php foreach (['Male', 'Female'] as $opt): ?>
                            <option <?= ($h['sex'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Civil Status</label>
                    <select name="civil_status">
                        <?php foreach (CIVIL_OPTIONS as $opt): ?>
                            <option <?= ($h['civil_status'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Educational Attainment</label>
                    <select name="educational_attainment">
                        <?php foreach (EDU_OPTIONS as $opt): ?>
                            <option <?= ($h['educational_attainment'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Occupation</label>
                    <input name="occupation" value="<?= htmlspecialchars($h['occupation'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Date of Birth</label>
                    <input type="date" name="date_of_birth" value="<?= htmlspecialchars($h['date_of_birth'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Contact No.</label>
                    <input name="contact_no" maxlength="11" pattern="\d{11}" value="<?= htmlspecialchars($h['contact_no'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>4Ps Member</label>
                    <select name="is_4ps_member">
                        <?php foreach (['No', 'Yes'] as $opt): ?>
                            <option <?= ($h['is_4ps_member'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Total Family Members</label>
                    <input type="number" name="total_family_members" min="1" value="<?= htmlspecialchars($h['total_family_members']) ?>">
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

            <?php if (!empty($members)): ?>
                <h3 style="margin:18px 0 10px;">Family Composition</h3>
                <?php foreach ($members as $idx => $m): ?>
                    <input type="hidden" name="member_ids[<?= $idx ?>]" value="<?= htmlspecialchars($m['composition_id']) ?>">
                    <h4 style="margin:14px 0 8px;font-size:13px;color:#6b7280;">Member <?= $idx + 2 ?></h4>
                    <div class="form-grid">
                        <div class="field">
                            <label>First Name</label>
                            <input name="comp_<?= $idx ?>_first_name" value="<?= htmlspecialchars($m['first_name']) ?>" required>
                        </div>
                        <div class="field">
                            <label>Middle Name</label>
                            <input name="comp_<?= $idx ?>_middle_name" value="<?= htmlspecialchars($m['middle_name'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label>Last Name</label>
                            <input name="comp_<?= $idx ?>_last_name" value="<?= htmlspecialchars($m['last_name']) ?>" required>
                        </div>
                        <div class="field">
                            <label>Age</label>
                            <input type="number" name="comp_<?= $idx ?>_age" min="0" value="<?= htmlspecialchars($m['age'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label>Sex</label>
                            <select name="comp_<?= $idx ?>_sex">
                                <?php foreach (['Male', 'Female'] as $opt): ?>
                                    <option <?= ($m['sex'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Civil Status</label>
                            <select name="comp_<?= $idx ?>_civil_status">
                                <?php foreach (CIVIL_OPTIONS as $opt): ?>
                                    <option <?= ($m['civil_status'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Relationship</label>
                            <input name="comp_<?= $idx ?>_relationship" value="<?= htmlspecialchars($m['relationship'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label>Educational Attainment</label>
                            <select name="comp_<?= $idx ?>_educational_attainment">
                                <?php foreach (EDU_OPTIONS as $opt): ?>
                                    <option <?= ($m['educational_attainment'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Occupation</label>
                            <input name="comp_<?= $idx ?>_occupation" value="<?= htmlspecialchars($m['occupation'] ?? '') ?>">
                        </div>
                    </div>
                    <?php if ($idx < count($members) - 1): ?>
                        <hr style="border:none;border-top:1px solid #e5e7eb;margin:14px 0;">
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="actions mt">
                <button class="btn btn-primary">Save Changes</button>
                <button type="button" class="btn btn-light" onclick="closeModal('editModal-<?= $h['household_id'] ?>')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php endforeach; ?>

<?php page_end(); ?>
<script>
<?php if (isset($_GET['reopen'])): ?>
openModal('householdModal');
<?php endif; ?>
</script>