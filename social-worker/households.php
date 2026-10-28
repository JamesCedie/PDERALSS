<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';

$currentUser         = db_user_with_barangay($_SESSION['user']['id'] ?? null);
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
const RELATIONSHIP_OPTIONS = ['Spouse', 'Son', 'Daughter', 'Parent', 'Sibling', 'Other'];

// Realistic limits for Philippine households (adjust here if the LGU needs different caps).
const HH_MIN_HEAD_AGE = 18;   // household head must be of legal age
const HH_MAX_AGE      = 120;  // oldest realistic age on record
const HH_MAX_MEMBERS  = 15;   // average PH household is ~4-5; 15 already covers large extended families
const HH_SENIOR_AGE   = 60;   // senior citizen per RA 9994

/** Whole non-negative number only (no decimals, signs or exponents) within [$min, $max]; null if invalid. */
function hh_int(mixed $v, int $min, int $max): ?int
{
    $v = trim((string) $v);
    if ($v === '' || !preg_match('/^\d{1,4}$/', $v)) return null;
    $n = (int) $v;
    return ($n < $min || $n > $max) ? null : $n;
}

/** True when the date of birth is a real date, not in the future, and gives exactly $age years today. */
function hh_dob_matches_age(string $dob, int $age): bool
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $dob, new DateTimeZone('Asia/Manila'));
    if (!$d || $d->format('Y-m-d') !== $dob) return false;
    $today = new DateTimeImmutable(today_ph(), new DateTimeZone('Asia/Manila'));
    if ($d > $today) return false;
    return $d->diff($today)->y === $age;
}

// Sex is stored in the database as the full values Male/Female.
// Accept the legacy M/F values when reading older records, but always write
// the database values used by the current schema: Male or Female.
function sex_value(string $value): string
{
    return match (strtolower(trim($value))) {
        'male', 'm' => 'Male',
        'female', 'f' => 'Female',
        default => '',
    };
}

function sex_label(?string $value): string
{
    return match (strtolower(trim((string) $value))) {
        'male', 'm' => 'Male',
        'female', 'f' => 'Female',
        default => '—',
    };
}

// ── Stage 1: Save household head ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stage']) && $_POST['stage'] === '1') {
    $firstname     = trim($_POST['first_name'] ?? '');
    $middlename    = trim($_POST['middle_name'] ?? '');
    $lastname      = trim($_POST['last_name'] ?? '');
    $nameextension = trim($_POST['name_extension'] ?? '');
    $age           = hh_int($_POST['age'] ?? '', HH_MIN_HEAD_AGE, HH_MAX_AGE);
    $sex           = sex_value($_POST['sex'] ?? '');
    $civilStatus   = $_POST['civil_status'] ?? '';
    $education     = $_POST['educational_attainment'] ?? '';
    $occupation    = trim($_POST['occupation'] ?? '');
    $dob           = trim((string) ($_POST['date_of_birth'] ?? '')) ?: null;
    $contact       = trim($_POST['contact_no'] ?? '');
    $is4ps         = $_POST['is_4ps_member'] ?? 'No';
    $totalMembers  = hh_int($_POST['total_family_members'] ?? '', 1, HH_MAX_MEMBERS);
    $pwdCount      = hh_int($_POST['pwd_count'] ?? '', 0, HH_MAX_MEMBERS);
    $seniorCount   = hh_int($_POST['senior_citizens'] ?? '', 0, HH_MAX_MEMBERS);

    if ($age === null) {
        $errorMsg = 'Age must be a whole number from ' . HH_MIN_HEAD_AGE . ' to ' . HH_MAX_AGE . ' (no decimals or negatives).';
    } elseif ($totalMembers === null) {
        $errorMsg = 'Total Family Members must be a whole number from 1 to ' . HH_MAX_MEMBERS . '.';
    } elseif ($pwdCount === null || $pwdCount > $totalMembers) {
        $errorMsg = 'PWDs in Household must be a whole number and cannot exceed the Total Family Members (' . $totalMembers . ').';
    } elseif ($seniorCount === null || $seniorCount > $totalMembers) {
        $errorMsg = 'Senior Citizens must be a whole number and cannot exceed the Total Family Members (' . $totalMembers . ').';
    } elseif ($age >= HH_SENIOR_AGE && $seniorCount < 1) {
        $errorMsg = 'The household head is ' . HH_SENIOR_AGE . '+ years old, so Senior Citizens must be at least 1.';
    } elseif ($dob && !hh_dob_matches_age($dob, $age)) {
        $errorMsg = 'Date of birth does not match the age entered (or is later than today).';
    } elseif ($firstname && $lastname && $socialWorkerAddress && in_array($sex, ['Male', 'Female'], true)) {
        $row = db_insert('household', [
            'firstname'             => $firstname,
            'middlename'            => $middlename ?: null,
            'lastname'              => $lastname,
            'nameextension'         => $nameextension ?: null,
            'age'                   => $age,
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
            'barangay_id'           => (int) $currentUser['barangay_id'],
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
            ? 'Please fill in all required fields, including Sex.'
            : 'Your account has no address on file — ask an MDRRMO Officer to set it.';
    }
}

// ── Stage 2: Save family composition members ──────────────────────────────────
// Every member (total family members - 1, the head is already saved) must be fully filled in.
// Nothing is saved unless ALL of them are valid.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stage']) && $_POST['stage'] === '2') {
    $householdId  = $_SESSION['pending_household_id'] ?? null;
    $totalMembers = (int) ($_SESSION['pending_total_members'] ?? 0);

    if (!$householdId || $totalMembers < 2) {
        header('Location: households.php');
        exit;
    }

    $head = db_select_one('household', 'household_id = ?', [$householdId]);
    $membersToAdd = $totalMembers - 1;
    $rows = [];
    $problem = null;
    $seniorsFound = ($head && (int) ($head['age'] ?? 0) >= HH_SENIOR_AGE) ? 1 : 0;

    for ($i = 0; $i < $membersToAdd && !$problem; $i++) {
        $n   = $i + 1;
        $fn  = trim($_POST["member_{$i}_first_name"] ?? '');
        $ln  = trim($_POST["member_{$i}_last_name"] ?? '');
        $age = hh_int($_POST["member_{$i}_age"] ?? '', 0, HH_MAX_AGE);
        $sex = sex_value($_POST["member_{$i}_sex"] ?? '');
        $civ = $_POST["member_{$i}_civil_status"] ?? '';
        $rel = $_POST["member_{$i}_relationship"] ?? '';
        $edu = $_POST["member_{$i}_educational_attainment"] ?? '';
        $occ = trim($_POST["member_{$i}_occupation"] ?? '');

        if ($fn === '' || $ln === '' || $occ === ''
            || !in_array($sex, ['Male', 'Female'], true)
            || !in_array($civ, CIVIL_OPTIONS, true)
            || !in_array($rel, RELATIONSHIP_OPTIONS, true)
            || !in_array($edu, EDU_OPTIONS, true)) {
            $problem = "Member {$n}: please fill in all the information.";
        } elseif ($age === null) {
            $problem = "Member {$n}: age must be a whole number from 0 to " . HH_MAX_AGE . '.';
        }
        if ($problem) break;

        if ($age >= HH_SENIOR_AGE) $seniorsFound++;
        $rows[] = [
            'household_id'           => $householdId,
            'first_name'             => $fn,
            'middle_name'            => trim($_POST["member_{$i}_middle_name"] ?? '') ?: null,
            'last_name'              => $ln,
            'age'                    => $age,
            'sex'                    => $sex,
            'civil_status'           => $civ,
            'relationship'           => $rel,
            'educational_attainment' => $edu,
            'occupation'             => $occ,
        ];
    }

    if (!$problem && $head && $seniorsFound > (int) ($head['senior_citizens'] ?? 0)) {
        $problem = $seniorsFound . ' people in this household are ' . HH_SENIOR_AGE . '+ years old, but Senior Citizens in Step 1 is '
                 . (int) $head['senior_citizens'] . '. Go back to Step 1 and correct it.';
    }

    if ($problem) {
        $_SESSION['flash_error'] = $problem;
        header('Location: households.php?stage=2');
        exit;
    }

    foreach ($rows as $row) db_insert('family_composition', $row);
    unset($_SESSION['pending_household_id'], $_SESSION['pending_total_members']);

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
    } elseif ((int) $target['barangay_id'] !== (int) ($currentUser['barangay_id'] ?? 0)) {
        $errorMsg = 'You can only edit households in your own barangay.';
    } elseif (($editAge = hh_int($_POST['age'] ?? '', HH_MIN_HEAD_AGE, HH_MAX_AGE)) === null) {
        $errorMsg = 'Age must be a whole number from ' . HH_MIN_HEAD_AGE . ' to ' . HH_MAX_AGE . ' (no decimals or negatives).';
    } elseif (($_POST['date_of_birth'] ?? '') !== '' && !hh_dob_matches_age((string) $_POST['date_of_birth'], $editAge)) {
        $errorMsg = 'Date of birth does not match the age entered (or is later than today).';
    } elseif (array_filter((array) ($_POST['member_ids'] ?? []), fn($cid, $ix) => $cid && hh_int($_POST["comp_{$ix}_age"] ?? '', 0, HH_MAX_AGE) === null, ARRAY_FILTER_USE_BOTH)) {
        $errorMsg = 'Each family member age must be a whole number from 0 to ' . HH_MAX_AGE . '.';
    } else {
        db_update('household', [
            'firstname'              => trim($_POST['first_name'] ?? ''),
            'middlename'             => trim($_POST['middle_name'] ?? '') ?: null,
            'lastname'               => trim($_POST['last_name'] ?? ''),
            'nameextension'          => trim($_POST['name_extension'] ?? '') ?: null,
            'age'                    => $editAge,
            'date_of_birth'          => ($_POST['date_of_birth'] ?? '') ?: null,
            'sex'                    => sex_value($_POST['sex'] ?? ''),
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
                'age'                    => hh_int($_POST["comp_{$idx}_age"] ?? '', 0, HH_MAX_AGE),
                'sex'                    => sex_value($_POST["comp_{$idx}_sex"] ?? ''),
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

if (isset($_SESSION['flash_error'])) {
    $errorMsg = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
if (isset($_SESSION['flash_success'])) {
    $successMsg = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$stage2 = isset($_GET['stage']) && $_GET['stage'] === '2' && isset($_SESSION['pending_household_id']);
$stage2TotalMembers = $stage2 ? (int) ($_SESSION['pending_total_members'] ?? 1) : 1;
$stage2Head = $stage2 ? db_select_one('household', 'household_id = ?', [$_SESSION['pending_household_id']]) : null;

// ── Household list data ───────────────────────────────────────────────────────
$households = db_query('SELECT h.*, b.name AS barangay FROM household h JOIN barangays b ON b.barangay_id = h.barangay_id WHERE h.barangay_id = ? ORDER BY h.household_id DESC', [(int) ($currentUser['barangay_id'] ?? 0)])->fetchAll();
$totalHouseholds = count($households);
$fourPsCount     = count(array_filter($households, fn($h) => $h['is_4ps_member'] === 'Yes'));
$totalPwd        = array_sum(array_column($households, 'pwd_count'));
$totalSeniors    = array_sum(array_column($households, 'senior_citizens'));
?>

<div class="sw-household-list">
    <div class="page-head sw-section-head">
        <div class="assigned-label">Assigned Barangay:<strong><?= htmlspecialchars($socialWorkerAddress ?: '—') ?></strong></div>
        <button class="btn btn-primary" onclick="openModal('householdModal')">Add Household</button>
    </div>

    <?php if ($successMsg): ?>
        <div class="alert alert-success mb"><?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="alert alert-danger mb"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div class="sw-summary-title">Household Summary</div>
    <div class="grid g4">
        <?php foreach([
            [$totalHouseholds, 'Total Households'],
            [$fourPsCount, "4P's Member Households"],
            [$totalPwd, 'Total PWD'],
            [$totalSeniors, 'Total Senior Citizens']
        ] as $s): ?>
            <div class="card sw-summary-card">
                <div class="stat-label"><?= htmlspecialchars($s[1]) ?></div>
                <div class="stat-value"><?= htmlspecialchars($s[0]) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="sw-records-title">Household Records</div>
    <label class="sw-search">
        <span aria-hidden="true"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
        <input type="search" id="swSearch" placeholder="Search..." autocomplete="off">
    </label>
    <div class="sw-records">
        <div class="table-wrap">
            <table class="table sw-table" id="swTable">
                <thead>
                    <tr>
                        <th>Family Head Name</th>
                        <th>Members</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($households)): ?>
                        <tr><td colspan="3" class="empty">No households recorded yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach($households as $h):
                        $fullName = preg_replace('/\s+/', ' ', trim(implode(' ', array_filter([
                            $h['firstname'], $h['middlename'], $h['lastname'], $h['nameextension']
                        ]))));
                    ?>
                        <tr data-search="<?= htmlspecialchars(strtolower($fullName)) ?>">
                            <td><strong><?= htmlspecialchars($fullName) ?></strong></td>
                            <td><?= htmlspecialchars($h['total_family_members']) ?></td>
                            <td class="sw-record-actions">
                                <button class="btn btn-dark-sm" onclick="openModal('viewModal-<?= $h['household_id'] ?>')">View</button>
                                <button class="btn btn-dark-sm" onclick="openModal('editModal-<?= $h['household_id'] ?>')">Edit</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="swNoMatch" hidden><td colspan="3" class="empty">No households match your search.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>document.addEventListener('DOMContentLoaded', function () { mdBindSearch('swSearch', 'swTable', 'swNoMatch'); });</script>

<!-- Step 1: Add Household -->
<div id="householdModal" class="modal">
    <div class="modal-box household-modal">
        <div class="modal-head">
            <div>
                <h2>Add Household</h2>
                <div class="mini">Step 1 of 2: Household Head Information</div>
            </div>
            <button class="icon-btn" onclick="closeModal('householdModal')" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>

        <form method="post" data-hh-head data-max-members="<?= HH_MAX_MEMBERS ?>" data-max-age="<?= HH_MAX_AGE ?>" data-senior-age="<?= HH_SENIOR_AGE ?>">
            <input type="hidden" name="stage" value="1">
            <div class="form-grid">
                <div class="field"><label>First Name</label><input name="first_name" placeholder="e.g. Juan" required></div>
                <div class="field"><label>Middle Name</label><input name="middle_name" placeholder="e.g. Ramos"></div>
                <div class="field"><label>Last Name</label><input name="last_name" placeholder="e.g. Dela Cruz" required></div>

                <div class="field"><label>Name Extension</label><input name="name_extension" placeholder="e.g. Jr., III (Optional)"></div>
                <div class="field"><label>Age</label><input type="number" name="age" data-int min="<?= HH_MIN_HEAD_AGE ?>" max="<?= HH_MAX_AGE ?>" step="1" inputmode="numeric" placeholder="e.g. 42" required><small class="field-hint"><?= HH_MIN_HEAD_AGE ?>–<?= HH_MAX_AGE ?> years, whole number</small></div>
                <div class="field"><label>Sex</label>
                    <select name="sex" required><option value="" disabled selected>Select sex</option><option value="M">Male</option><option value="F">Female</option></select>
                </div>

                <div class="field"><label>Civil Status</label>
                    <select name="civil_status" required><option value="" disabled selected>Select status</option><?php foreach (CIVIL_OPTIONS as $opt): ?><option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option><?php endforeach; ?></select>
                </div>
                <div class="field"><label>Educational Attainment</label>
                    <select name="educational_attainment" required><option value="" disabled selected>Select education</option><?php foreach (EDU_OPTIONS as $opt): ?><option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option><?php endforeach; ?></select>
                </div>
                <div class="field"><label>Occupation</label><input name="occupation" placeholder="e.g. Carpenter"></div>

                <div class="field"><label>Date of Birth</label><input type="date" name="date_of_birth" max="<?= htmlspecialchars(today_ph()) ?>" placeholder="YYYY-MM-DD"><small class="field-hint">Enter the age first; only matching dates can be picked</small></div>
                <div class="field"><label>Contact No.</label><input name="contact_no" maxlength="11" pattern="\d{11}" placeholder="e.g. 0917XXXXXXX"></div>
                <div class="field"><label>4P's Member</label>
                    <select name="is_4ps_member" required><option value="" disabled selected>Select Yes / No</option><option value="No">No</option><option value="Yes">Yes</option></select>
                </div>

                <div class="field"><label>Total Family Members</label><input type="number" name="total_family_members" data-int min="1" max="<?= HH_MAX_MEMBERS ?>" step="1" inputmode="numeric" value="1" required><small class="field-hint">1–<?= HH_MAX_MEMBERS ?> including the head</small></div>
                <div class="field"><label>PWDs in Household</label><input type="number" name="pwd_count" data-int min="0" max="<?= HH_MAX_MEMBERS ?>" step="1" inputmode="numeric" value="0" required><small class="field-hint">Cannot exceed total family members</small></div>
                <div class="field"><label>Senior Citizens</label><input type="number" name="senior_citizens" data-int min="0" max="<?= HH_MAX_MEMBERS ?>" step="1" inputmode="numeric" value="0" required><small class="field-hint">Cannot exceed total family members</small></div>
            </div>

            <div class="actions">
                <button type="button" class="btn btn-light" onclick="closeModal('householdModal')">Cancel</button>
                <button class="btn btn-primary">Enter Family Composition</button>
            </div>
        </form>
    </div>
</div>

<!-- Step 2: Add Family Composition. It remains a modal so the household list stays behind it. -->
<?php if ($stage2): ?>
<div id="stage2Modal" class="modal show">
    <div class="modal-box household-modal">
        <div class="modal-head">
            <div>
                <h2>Add Household</h2>
                <div class="mini">Step 2 of 2: Family Composition Information</div>
            </div>
            <button class="icon-btn" onclick="window.location='households.php?stage=back'" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>

        <form method="post" data-hh-members data-head-age="<?= (int) ($stage2Head['age'] ?? 0) ?>" data-seniors="<?= (int) ($stage2Head['senior_citizens'] ?? 0) ?>" data-senior-age="<?= HH_SENIOR_AGE ?>" novalidate>
            <input type="hidden" name="stage" value="2">
            <?php $membersToAdd = max(1, $stage2TotalMembers - 1); ?>
            <?php for ($i = 0; $i < $membersToAdd; $i++): ?>
                <div class="sw-modal-section-title">Member <?= $i + 1 ?></div>
                <div class="form-grid">
                    <div class="field"><label>First Name</label><input name="member_<?= $i ?>_first_name" placeholder="e.g. Juan" required pattern="\S.*" title="This field is required"></div>
                    <div class="field"><label>Middle Name</label><input name="member_<?= $i ?>_middle_name" placeholder="e.g. Ramos (optional)"></div>
                    <div class="field"><label>Last Name</label><input name="member_<?= $i ?>_last_name" placeholder="e.g. Dela Cruz" required pattern="\S.*" title="This field is required"></div>
                    <div class="field"><label>Age</label><input type="number" name="member_<?= $i ?>_age" data-int min="0" max="<?= HH_MAX_AGE ?>" step="1" inputmode="numeric" placeholder="e.g. 21" required></div>
                    <div class="field"><label>Sex</label>
                        <select name="member_<?= $i ?>_sex" required><option value="" disabled selected>Select sex</option><option value="M">Male</option><option value="F">Female</option></select>
                    </div>
                    <div class="field"><label>Civil Status</label>
                        <select name="member_<?= $i ?>_civil_status" required><option value="" disabled selected>Select status</option><?php foreach (CIVIL_OPTIONS as $opt): ?><option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="field"><label>Relationship to Head</label><select name="member_<?= $i ?>_relationship" required><option value="" disabled selected>Select relationship</option><?php foreach (RELATIONSHIP_OPTIONS as $opt): ?><option><?= htmlspecialchars($opt) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Educational Attainment</label>
                        <select name="member_<?= $i ?>_educational_attainment" required><option value="" disabled selected>Select education</option><?php foreach (EDU_OPTIONS as $opt): ?><option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="field"><label>Occupation</label><input name="member_<?= $i ?>_occupation" placeholder="e.g. Student, None" required pattern="\S.*" title="This field is required"></div>
                </div>
                <?php if ($i < $membersToAdd - 1): ?><hr class="sw-member-divider"><?php endif; ?>
            <?php endfor; ?>

            <div id="hhMemberMsg" class="field-hint hh-member-msg" role="status" aria-live="polite"></div>
            <div class="actions">
                <a href="households.php?stage=back" class="btn btn-light">Back to Step 1</a>
                <button class="btn btn-primary" id="hhSaveBtn" disabled>Save Household</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- View / Edit modals -->
<?php foreach ($households as $h):
    $fullName = preg_replace('/\s+/', ' ', trim(implode(' ', array_filter([
        $h['firstname'], $h['middlename'], $h['lastname'], $h['nameextension']
    ]))));
    $members = db_select('family_composition', 'household_id = ?', [$h['household_id']], '*', 'composition_id ASC');
?>

<div id="viewModal-<?= $h['household_id'] ?>" class="modal">
    <div class="modal-box household-modal">
        <div class="modal-head">
            <div>
                <h2>Household Details</h2>
                <div class="mini">Barangay <?= htmlspecialchars($h['barangay']) ?> · System Record #<?= htmlspecialchars($h['household_id']) ?></div>
            </div>
            <button class="icon-btn" onclick="closeModal('viewModal-<?= $h['household_id'] ?>')" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>

        <div class="sw-modal-section-title">Family Head Information</div>
        <div class="sw-view-grid">
            <div class="sw-view-item"><label>First Name</label><div><?= htmlspecialchars($h['firstname']) ?></div></div>
            <div class="sw-view-item"><label>Middle Name</label><div><?= htmlspecialchars($h['middlename'] ?? 'N/A') ?></div></div>
            <div class="sw-view-item"><label>Last Name</label><div><?= htmlspecialchars($h['lastname']) ?></div></div>
            <div class="sw-view-item"><label>Name Extension</label><div><?= htmlspecialchars($h['nameextension'] ?? 'N/A') ?></div></div>
            <div class="sw-view-item"><label>Age</label><div><?= htmlspecialchars($h['age'] ?? '—') ?></div></div>
            <div class="sw-view-item"><label>Sex</label><div><?= htmlspecialchars(sex_label($h['sex'] ?? null)) ?></div></div>
            <div class="sw-view-item"><label>Educational Attainment</label><div><?= htmlspecialchars($h['educational_attainment'] ?? '—') ?></div></div>
            <div class="sw-view-item"><label>Occupation</label><div><?= htmlspecialchars($h['occupation'] ?? '—') ?></div></div>
            <div class="sw-view-item"><label>Date of Birth</label><div><?= htmlspecialchars($h['date_of_birth'] ?? '—') ?></div></div>
            <div class="sw-view-item"><label>Contact No.</label><div><?= htmlspecialchars($h['contact_no'] ?? '—') ?></div></div>
            <div class="sw-view-item"><label>4P's Member</label><div><?= htmlspecialchars($h['is_4ps_member']) ?></div></div>
            <div class="sw-view-item"><label>Civil Status</label><div><?= htmlspecialchars($h['civil_status'] ?? '—') ?></div></div>
        </div>

        <div class="sw-modal-section-title">Family Composition</div>
        <div class="sw-member-table">
            <table class="table">
                <thead><tr><th>Name</th><th>Age</th><th>Sex</th><th>Civil Status</th><th>Relationship</th><th>Education</th><th>Occupation</th></tr></thead>
                <tbody>
                <?php if (empty($members)): ?>
                    <tr><td colspan="7" class="empty">No family composition records.</td></tr>
                <?php else: foreach ($members as $m): ?>
                    <tr>
                        <td><?= htmlspecialchars(trim($m['first_name'].' '.($m['middle_name'] ?? '').' '.$m['last_name'])) ?></td>
                        <td><?= htmlspecialchars($m['age'] ?? '—') ?></td>
                        <td><?= htmlspecialchars(sex_label($m['sex'] ?? null)) ?></td>
                        <td><?= htmlspecialchars($m['civil_status'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($m['relationship'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($m['educational_attainment'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($m['occupation'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="actions">
            <button type="button" class="btn btn-primary" onclick="closeModal('viewModal-<?= $h['household_id'] ?>')">Close</button>
        </div>
    </div>
</div>

<div id="editModal-<?= $h['household_id'] ?>" class="modal">
    <div class="modal-box household-modal">
        <div class="modal-head">
            <div>
                <h2>Edit Household Record</h2>
                <div class="mini">System Control ID: #<?= htmlspecialchars($h['household_id']) ?> &nbsp;·&nbsp; Assigned Barangay: <?= htmlspecialchars($h['barangay']) ?></div>
            </div>
            <button class="icon-btn" onclick="closeModal('editModal-<?= $h['household_id'] ?>')" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>

        <form method="post" class="sw-edit-form" data-hh-dob data-max-age="<?= HH_MAX_AGE ?>">
            <input type="hidden" name="edit_household" value="1">
            <input type="hidden" name="household_id" value="<?= htmlspecialchars($h['household_id']) ?>">

            <div class="sw-modal-section-title">Family Head Information</div>
            <div class="form-grid">
                <div class="field"><label>First Name</label><input name="first_name" value="<?= htmlspecialchars($h['firstname']) ?>" required></div>
                <div class="field"><label>Middle Name</label><input name="middle_name" value="<?= htmlspecialchars($h['middlename'] ?? '') ?>"></div>
                <div class="field"><label>Last Name</label><input name="last_name" value="<?= htmlspecialchars($h['lastname']) ?>" required></div>
                <div class="field"><label>Name Extension</label><input name="name_extension" value="<?= htmlspecialchars($h['nameextension'] ?? '') ?>"></div>
                <div class="field"><label>Age</label><input type="number" name="age" data-int min="<?= HH_MIN_HEAD_AGE ?>" max="<?= HH_MAX_AGE ?>" step="1" inputmode="numeric" required value="<?= htmlspecialchars($h['age'] ?? '') ?>"></div>
                <div class="field"><label>Sex</label><select name="sex" required><?php foreach([['Male','Male'],['Female','Female']] as $opt): ?><option value="<?= $opt[0] ?>" <?= sex_value($h['sex']??'')===$opt[0]?'selected':'' ?>><?= $opt[1] ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Civil Status</label><select name="civil_status"><?php foreach(CIVIL_OPTIONS as $opt): ?><option <?= ($h['civil_status']??'')===$opt?'selected':'' ?>><?= $opt ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Educational Attainment</label><select name="educational_attainment"><?php foreach(EDU_OPTIONS as $opt): ?><option <?= ($h['educational_attainment']??'')===$opt?'selected':'' ?>><?= $opt ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Occupation</label><input name="occupation" value="<?= htmlspecialchars($h['occupation'] ?? '') ?>"></div>
                <div class="field"><label>Date of Birth</label><input type="date" name="date_of_birth" max="<?= htmlspecialchars(today_ph()) ?>" value="<?= htmlspecialchars($h['date_of_birth'] ?? '') ?>"></div>
                <div class="field"><label>Contact No.</label><input name="contact_no" maxlength="11" pattern="\d{11}" value="<?= htmlspecialchars($h['contact_no'] ?? '') ?>"></div>
                <div class="field"><label>4P's Member</label><select name="is_4ps_member"><?php foreach(['No','Yes'] as $opt): ?><option <?= ($h['is_4ps_member']??'')===$opt?'selected':'' ?>><?= $opt ?></option><?php endforeach; ?></select></div>
            </div>

            <div class="sw-modal-section-title">Family Composition</div>
            <div class="sw-member-table">
                <table class="table">
                    <thead><tr><th>Name</th><th>Age</th><th>Sex</th><th>Civil Status</th><th>Relationship</th><th>Education</th><th>Occupation</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if (empty($members)): ?>
                        <tr><td colspan="8" class="empty">No family members recorded.</td></tr>
                    <?php else: foreach ($members as $idx => $m): ?>
                        <tr>
                            <td><?= htmlspecialchars(trim($m['first_name'].' '.($m['middle_name'] ?? '').' '.$m['last_name'])) ?></td>
                            <td><?= htmlspecialchars($m['age'] ?? '—') ?></td>
                            <td><?= htmlspecialchars(sex_label($m['sex'] ?? null)) ?></td>
                            <td><?= htmlspecialchars($m['civil_status'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($m['relationship'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($m['educational_attainment'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($m['occupation'] ?? '—') ?></td>
                            <td>
                                <button type="button" class="btn btn-light member-edit-btn" aria-expanded="false" aria-controls="memberEditor<?= $h['household_id'] ?>-<?= $idx ?>" onclick="toggleMemberEditor('memberEditor<?= $h['household_id'] ?>-<?= $idx ?>', this)">Edit</button>
                            </td>
                        </tr>
                        <tr class="member-editor-row" id="memberEditor<?= $h['household_id'] ?>-<?= $idx ?>" hidden>
                            <td colspan="8" style="padding:0;border-bottom:0;">
                                <div class="member-editor">
                                    <input type="hidden" name="member_ids[<?= $idx ?>]" value="<?= htmlspecialchars($m['composition_id']) ?>">
                                    <div class="form-grid">
                                        <div class="field"><label>First Name</label><input name="comp_<?= $idx ?>_first_name" value="<?= htmlspecialchars($m['first_name']) ?>" required></div>
                                        <div class="field"><label>Middle Name</label><input name="comp_<?= $idx ?>_middle_name" value="<?= htmlspecialchars($m['middle_name'] ?? '') ?>"></div>
                                        <div class="field"><label>Last Name</label><input name="comp_<?= $idx ?>_last_name" value="<?= htmlspecialchars($m['last_name']) ?>" required></div>
                                        <div class="field"><label>Age</label><input type="number" name="comp_<?= $idx ?>_age" data-int min="0" max="<?= HH_MAX_AGE ?>" step="1" inputmode="numeric" required value="<?= htmlspecialchars($m['age'] ?? '') ?>"></div>
                                        <div class="field"><label>Sex</label><select name="comp_<?= $idx ?>_sex"><?php foreach([['Male','Male'],['Female','Female']] as $opt): ?><option value="<?= $opt[0] ?>" <?= sex_value($m['sex']??'')===$opt[0]?'selected':'' ?>><?= $opt[1] ?></option><?php endforeach; ?></select></div>
                                        <div class="field"><label>Civil Status</label><select name="comp_<?= $idx ?>_civil_status"><?php foreach(CIVIL_OPTIONS as $opt): ?><option <?= ($m['civil_status']??'')===$opt?'selected':'' ?>><?= $opt ?></option><?php endforeach; ?></select></div>
                                        <div class="field"><label>Relationship</label><input name="comp_<?= $idx ?>_relationship" value="<?= htmlspecialchars($m['relationship'] ?? '') ?>"></div>
                                        <div class="field"><label>Educational Attainment</label><select name="comp_<?= $idx ?>_educational_attainment"><?php foreach(EDU_OPTIONS as $opt): ?><option <?= ($m['educational_attainment']??'')===$opt?'selected':'' ?>><?= $opt ?></option><?php endforeach; ?></select></div>
                                        <div class="field"><label>Occupation</label><input name="comp_<?= $idx ?>_occupation" value="<?= htmlspecialchars($m['occupation'] ?? '') ?>"></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>


            <div class="actions">
                <button type="button" class="btn btn-light" onclick="closeModal('editModal-<?= $h['household_id'] ?>')">Cancel</button>
                <button class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php endforeach; ?>

<?php page_end(); ?>
<script>
function toggleMemberEditor(id, btn) {
    var row = document.getElementById(id);
    if (!row) return;
    var show = row.hidden;
    row.hidden = !show;
    btn.textContent = show ? 'Done' : 'Edit';
    btn.setAttribute('aria-expanded', show ? 'true' : 'false');
}
<?php if ($stage2): ?>
document.addEventListener('DOMContentLoaded', function(){ openModal('stage2Modal'); });
<?php elseif (isset($_GET['reopen'])): ?>
document.addEventListener('DOMContentLoaded', function(){ openModal('householdModal'); });
<?php endif; ?>
</script>
