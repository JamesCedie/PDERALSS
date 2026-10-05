<?php
<<<<<<< HEAD
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';

if (($_SESSION['user']['role'] ?? '') !== 'MDRRMO Officer') {
    http_response_code(403);
    exit('Access denied.');
}

const EDU_OPTIONS   = ['Elementary', 'Highschool Undergraduate', 'Highschool Graduate', 'College Undergraduate', 'College Graduate'];
const CIVIL_OPTIONS = ['Married', 'Single', 'Widowed', 'Separated'];
// Same barangay list used by User Management; barangays found in the data are added on top.
const HH_BARANGAYS  = ['Brgy. Jaro', 'Brgy. Molo', 'Brgy. Mandurriao', 'Brgy. Arevalo', 'Brgy. La Paz'];

function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function sex_value(?string $value): string
{
    return match (strtolower(trim((string) $value))) {
        'male', 'm'     => 'Male',
        'female', 'f'   => 'Female',
        default         => '',
    };
}

function sex_label(?string $value): string
{
    return sex_value($value) ?: '—';
}

function full_name(array $h): string
{
    return preg_replace('/\s+/', ' ', trim(implode(' ', array_filter([
        $h['firstname'] ?? '', $h['middlename'] ?? '', $h['lastname'] ?? '', $h['nameextension'] ?? '',
    ]))));
}

function hh_csrf(): string
{
    if (empty($_SESSION['hh_csrf'])) $_SESSION['hh_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['hh_csrf'];
}

function hh_date(?string $value, string $fmt = 'j M Y'): string
{
    if (!$value) return '—';
    try { return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Asia/Manila'))->format($fmt); }
    catch (Throwable $e) { return h($value); }
}

/** Returns the name of a timestamp column on `household` if one exists (created_at / updated_at), else null. */
function hh_timestamp_column(): ?string
{
    $cols = db_query(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema = current_schema() AND table_name = 'household'
           AND column_name IN ('updated_at','created_at')"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach (['updated_at', 'created_at'] as $c) if (in_array($c, $cols, true)) return $c;
    return null;
}

/** Barangay names that can be opened: the fixed list plus any found in the data. */
function hh_folder_names(array $households): array
{
    $names = HH_BARANGAYS;
    $extra = array_values(array_diff(array_unique(array_column($households, 'barangay')), $names));
    sort($extra);
    return array_values(array_filter(array_merge($names, $extra)));
}

// ── Edit household (MDRRMO can edit any barangay) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_household'])) {
    $id     = (int) ($_POST['household_id'] ?? 0);
    $target = $id > 0 ? db_select_one('household', 'household_id = ?', [$id]) : null;
    $back   = 'households.php' . ($target ? '?barangay=' . urlencode($target['barangay']) : '');
    $error  = null;

    $first   = trim($_POST['first_name'] ?? '');
    $last    = trim($_POST['last_name'] ?? '');
    $sex     = sex_value($_POST['sex'] ?? '');
    $civil   = $_POST['civil_status'] ?? '';
    $edu     = $_POST['educational_attainment'] ?? '';
    $is4ps   = $_POST['is_4ps_member'] ?? '';
    $contact = trim($_POST['contact_no'] ?? '');
    $ageRaw  = trim((string) ($_POST['age'] ?? ''));
    $age     = $ageRaw === '' ? null : filter_var($ageRaw, FILTER_VALIDATE_INT);
    $dobRaw  = trim((string) ($_POST['date_of_birth'] ?? ''));
    $dob     = $dobRaw === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $dobRaw);
    $members = filter_var($_POST['total_family_members'] ?? '', FILTER_VALIDATE_INT);
    $pwd     = filter_var($_POST['pwd_count'] ?? '', FILTER_VALIDATE_INT);
    $seniors = filter_var($_POST['senior_citizens'] ?? '', FILTER_VALIDATE_INT);

    if (empty($_SESSION['hh_csrf']) || !hash_equals($_SESSION['hh_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$target) {
        $error = 'Household not found.';
    } elseif ($first === '' || $last === '') {
        $error = 'First name and last name are required.';
    } elseif ($sex === '') {
        $error = 'Please select a valid sex.';
    } elseif (!in_array($civil, CIVIL_OPTIONS, true) || !in_array($edu, EDU_OPTIONS, true)) {
        $error = 'Please select a valid civil status and educational attainment.';
    } elseif (!in_array($is4ps, ['Yes', 'No'], true)) {
        $error = "Please select whether the head is a 4P's member.";
    } elseif ($contact !== '' && !preg_match('/^\d{11}$/', $contact)) {
        $error = 'Contact number must be 11 digits.';
    } elseif ($age === false || ($age !== null && ($age < 0 || $age > 120))) {
        $error = 'Age must be between 0 and 120.';
    } elseif ($dobRaw !== '' && (!$dob || $dob->format('Y-m-d') !== $dobRaw)) {
        $error = 'Date of birth is not valid.';
    } elseif ($members === false || $members < 1 || $pwd === false || $pwd < 0 || $seniors === false || $seniors < 0) {
        $error = 'Family members must be at least 1, and PWD / senior counts cannot be negative.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            $data = [
                'firstname'              => $first,
                'middlename'             => trim($_POST['middle_name'] ?? '') ?: null,
                'lastname'               => $last,
                'nameextension'          => trim($_POST['name_extension'] ?? '') ?: null,
                'age'                    => $age,
                'date_of_birth'          => $dobRaw ?: null,
                'sex'                    => $sex,
                'civil_status'           => $civil,
                'educational_attainment' => $edu,
                'occupation'             => trim($_POST['occupation'] ?? '') ?: null,
                'contact_no'             => $contact ?: null,
                'total_family_members'   => $members,
                'is_4ps_member'          => $is4ps,
                'pwd_count'              => $pwd,
                'senior_citizens'        => $seniors,
            ];
            if (hh_timestamp_column() === 'updated_at') $data['updated_at'] = gmdate('c'); // keeps "Updated …" on the folder accurate
            db_update('household', $data, 'household_id = ?', [$id]);

            foreach (($_POST['member_ids'] ?? []) as $idx => $compId) {
                $compId = (int) $compId;
                if ($compId <= 0) continue;
                $mf = trim($_POST["comp_{$idx}_first_name"] ?? '');
                $ml = trim($_POST["comp_{$idx}_last_name"] ?? '');
                if ($mf === '' || $ml === '') continue;
                $mAge = filter_var($_POST["comp_{$idx}_age"] ?? '', FILTER_VALIDATE_INT);
                db_update('family_composition', [
                    'first_name'             => $mf,
                    'middle_name'            => trim($_POST["comp_{$idx}_middle_name"] ?? '') ?: null,
                    'last_name'              => $ml,
                    'age'                    => ($mAge !== false && $mAge >= 0 && $mAge <= 120) ? $mAge : null,
                    'sex'                    => sex_value($_POST["comp_{$idx}_sex"] ?? ''),
                    'civil_status'           => in_array($_POST["comp_{$idx}_civil_status"] ?? '', CIVIL_OPTIONS, true) ? $_POST["comp_{$idx}_civil_status"] : '',
                    'relationship'           => trim($_POST["comp_{$idx}_relationship"] ?? '') ?: null,
                    'educational_attainment' => in_array($_POST["comp_{$idx}_educational_attainment"] ?? '', EDU_OPTIONS, true) ? $_POST["comp_{$idx}_educational_attainment"] : '',
                    'occupation'             => trim($_POST["comp_{$idx}_occupation"] ?? '') ?: null,
                ], 'composition_id = ? AND household_id = ?', [$compId, $id]); // only members of THIS household
            }

            $pdo->commit();
            $_SESSION['flash_success'] = 'Household record updated.';
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log('household edit failed: ' . $e->getMessage());
            $error = 'Could not save the changes. Please try again.';
        }
    }

    if ($error) $_SESSION['flash_error'] = $error;
    header('Location: ' . $back);
    exit;
}

// ── Page data ───────────────────────────────────────────────────────────────
$households = db_select('household', '1=1', [], '*', 'barangay ASC, household_id DESC');
$folders    = hh_folder_names($households);

$byBarangay = array_fill_keys($folders, []);
foreach ($households as $row) $byBarangay[$row['barangay']][] = $row;

$selected = $_GET['barangay'] ?? '';
$selected = in_array($selected, $folders, true) ? $selected : null;

$successMsg = $_SESSION['flash_success'] ?? null;
$errorMsg   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

function hh_totals(array $list): array
{
    return [
        count($list),
        count(array_filter($list, fn($r) => ($r['is_4ps_member'] ?? '') === 'Yes')),
        (int) array_sum(array_column($list, 'pwd_count')),
        (int) array_sum(array_column($list, 'senior_citizens')),
    ];
}

require '../includes/layout.php';
$subtitle = 'LGU Disaster Management System · Municipality-wide Control';
if ($selected !== null) {
    page_start('Household Management', $subtitle, ['households.php', '← Household Management']);
} else {
    page_start('Household Management', $subtitle);
}
?>

<div class="hh-page">
    <?php if ($successMsg): ?><div class="alert alert-success mb"><?= h($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="alert alert-danger mb"><?= h($errorMsg) ?></div><?php endif; ?>

<?php if ($selected === null): ?>
    <?php [$tHouseholds, $t4ps, $tPwd, $tSeniors] = hh_totals($households); ?>

    <div class="sw-summary-title">Municipal Household Summary</div>
    <div class="grid g4">
        <?php foreach ([[$tHouseholds, 'Municipal Total Household Count'], [$t4ps, "4P's Member Households"], [$tPwd, 'Total PWD'], [$tSeniors, 'Total Senior Citizens']] as $s): ?>
            <div class="card sw-summary-card">
                <div class="stat-label"><?= h($s[1]) ?></div>
                <div class="stat-value"><?= number_format($s[0]) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php $tsCol = hh_timestamp_column(); ?>
    <div class="hh-dir-head">
        <div class="sw-records-title">Barangay Household Directory</div>
        <span class="hh-count"><?= count($folders) ?> Barangay folders</span>
    </div>

    <div class="hh-folders">
        <?php foreach ($folders as $name):
            $list    = $byBarangay[$name];
            $updated = null;
            if ($tsCol && $list) {
                $stamps  = array_filter(array_column($list, $tsCol));
                $updated = $stamps ? max($stamps) : null;
            }
        ?>
            <div class="hh-folder">
                <div>
                    <div class="hh-folder-name"><?= h($name) ?></div>
                    <div class="hh-folder-meta">
                        <?= $updated ? 'Updated ' . h(hh_date($updated)) : (count($list) ? '&nbsp;' : 'No records yet') ?>
                    </div>
                    <div class="hh-folder-count"><strong><?= count($list) ?></strong> <span><?= count($list) === 1 ? 'household' : 'households' ?></span></div>
                </div>
                <a class="btn btn-dark-sm" href="households.php?barangay=<?= urlencode($name) ?>">Open records</a>
            </div>
        <?php endforeach; ?>
    </div>

<?php else:
    $list = $byBarangay[$selected];
    [$tHouseholds, $t4ps, $tPwd, $tSeniors] = hh_totals($list);
?>
    <div class="assigned-label mb">Barangay: <strong><?= h($selected) ?></strong></div>

    <div class="sw-summary-title">Household Summary</div>
    <div class="grid g4">
        <?php foreach ([[$tHouseholds, 'Total Households'], [$t4ps, "4P's Member Households"], [$tPwd, 'Total PWD'], [$tSeniors, 'Total Senior Citizens']] as $s): ?>
            <div class="card sw-summary-card">
                <div class="stat-label"><?= h($s[1]) ?></div>
                <div class="stat-value"><?= number_format($s[0]) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="sw-records-title">Household Records</div>
    <label class="sw-search vr-search" style="margin:0 0 14px">
        <span aria-hidden="true">⌕</span>
        <input type="search" id="hhSearch" placeholder="Search..." autocomplete="off">
    </label>

    <div class="sw-data-table">
        <div class="table-wrap">
            <table class="table" id="hhTable">
                <thead>
                    <tr><th>Family Head Name</th><th>Members</th><th class="vr-right">Action</th></tr>
                </thead>
                <tbody>
                    <?php if (!$list): ?>
                        <tr><td colspan="3" class="empty">No households recorded for <?= h($selected) ?> yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($list as $row): ?>
                        <tr data-name="<?= h(strtolower(full_name($row))) ?>">
                            <td><strong><?= h(full_name($row)) ?></strong></td>
                            <td><?= (int) $row['total_family_members'] ?></td>
                            <td class="vr-right hh-actions">
                                <button class="btn btn-dark-sm" onclick="openModal('viewModal-<?= (int) $row['household_id'] ?>')">View</button>
                                <button class="btn btn-dark-sm" onclick="openModal('editModal-<?= (int) $row['household_id'] ?>')">Edit</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="hhNoMatch" hidden><td colspan="3" class="empty">No households match your search.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
</div>

<?php if ($selected !== null): foreach ($byBarangay[$selected] as $row):
    $hid     = (int) $row['household_id'];
    $members = db_select('family_composition', 'household_id = ?', [$hid], '*', 'composition_id ASC');
?>
<!-- View -->
<div id="viewModal-<?= $hid ?>" class="modal">
    <div class="modal-box household-modal">
        <div class="modal-head">
            <div>
                <h2>Household Details</h2>
                <div class="mini"><?= h($row['barangay']) ?> · System Record #<?= $hid ?></div>
            </div>
            <button class="icon-btn" onclick="closeModal('viewModal-<?= $hid ?>')" aria-label="Close">×</button>
        </div>

        <div class="sw-modal-section-title">Family Head Information</div>
        <div class="sw-view-grid">
            <div class="sw-view-item"><label>First Name</label><div><?= h($row['firstname']) ?></div></div>
            <div class="sw-view-item"><label>Middle Name</label><div><?= h($row['middlename'] ?: 'N/A') ?></div></div>
            <div class="sw-view-item"><label>Last Name</label><div><?= h($row['lastname']) ?></div></div>
            <div class="sw-view-item"><label>Name Extension</label><div><?= h($row['nameextension'] ?: 'N/A') ?></div></div>
            <div class="sw-view-item"><label>Age</label><div><?= h($row['age'] ?? '—') ?></div></div>
            <div class="sw-view-item"><label>Sex</label><div><?= h(sex_label($row['sex'] ?? null)) ?></div></div>
            <div class="sw-view-item"><label>Civil Status</label><div><?= h($row['civil_status'] ?: '—') ?></div></div>
            <div class="sw-view-item"><label>Educational Attainment</label><div><?= h($row['educational_attainment'] ?: '—') ?></div></div>
            <div class="sw-view-item"><label>Occupation</label><div><?= h($row['occupation'] ?: '—') ?></div></div>
            <div class="sw-view-item"><label>Date of Birth</label><div><?= h($row['date_of_birth'] ?: '—') ?></div></div>
            <div class="sw-view-item"><label>Contact No.</label><div><?= h($row['contact_no'] ?: '—') ?></div></div>
            <div class="sw-view-item"><label>4P's Member</label><div><?= h($row['is_4ps_member']) ?></div></div>
            <div class="sw-view-item"><label>Family Members</label><div><?= (int) $row['total_family_members'] ?></div></div>
            <div class="sw-view-item"><label>PWD</label><div><?= (int) $row['pwd_count'] ?></div></div>
            <div class="sw-view-item"><label>Senior Citizens</label><div><?= (int) $row['senior_citizens'] ?></div></div>
        </div>

        <div class="sw-modal-section-title">Family Composition</div>
        <div class="sw-member-table">
            <table class="table">
                <thead><tr><th>Name</th><th>Age</th><th>Sex</th><th>Civil Status</th><th>Relationship</th><th>Education</th><th>Occupation</th></tr></thead>
                <tbody>
                <?php if (!$members): ?>
                    <tr><td colspan="7" class="empty">No family composition records.</td></tr>
                <?php else: foreach ($members as $m): ?>
                    <tr>
                        <td><?= h(trim($m['first_name'] . ' ' . ($m['middle_name'] ?? '') . ' ' . $m['last_name'])) ?></td>
                        <td><?= h($m['age'] ?? '—') ?></td>
                        <td><?= h(sex_label($m['sex'] ?? null)) ?></td>
                        <td><?= h($m['civil_status'] ?: '—') ?></td>
                        <td><?= h($m['relationship'] ?: '—') ?></td>
                        <td><?= h($m['educational_attainment'] ?: '—') ?></td>
                        <td><?= h($m['occupation'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="actions">
            <button type="button" class="btn btn-primary" onclick="closeModal('viewModal-<?= $hid ?>')">Close</button>
        </div>
    </div>
</div>

<!-- Edit -->
<div id="editModal-<?= $hid ?>" class="modal">
    <div class="modal-box household-modal">
        <div class="modal-head">
            <div>
                <h2>Edit Household Record</h2>
                <div class="mini">System Control ID: #<?= $hid ?> &nbsp;·&nbsp; <?= h($row['barangay']) ?></div>
            </div>
            <button class="icon-btn" onclick="closeModal('editModal-<?= $hid ?>')" aria-label="Close">×</button>
        </div>

        <form method="post" class="sw-edit-form">
            <input type="hidden" name="edit_household" value="1">
            <input type="hidden" name="household_id" value="<?= $hid ?>">
            <input type="hidden" name="csrf" value="<?= h(hh_csrf()) ?>">

            <div class="sw-modal-section-title">Family Head Information</div>
            <div class="form-grid">
                <div class="field"><label>First Name</label><input name="first_name" value="<?= h($row['firstname']) ?>" required></div>
                <div class="field"><label>Middle Name</label><input name="middle_name" value="<?= h($row['middlename']) ?>"></div>
                <div class="field"><label>Last Name</label><input name="last_name" value="<?= h($row['lastname']) ?>" required></div>
                <div class="field"><label>Name Extension</label><input name="name_extension" value="<?= h($row['nameextension']) ?>"></div>
                <div class="field"><label>Age</label><input type="number" name="age" min="0" max="120" value="<?= h($row['age']) ?>"></div>
                <div class="field"><label>Sex</label>
                    <select name="sex" required><?php foreach (['Male', 'Female'] as $o): ?><option <?= sex_value($row['sex'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select>
                </div>
                <div class="field"><label>Civil Status</label>
                    <select name="civil_status"><?php foreach (CIVIL_OPTIONS as $o): ?><option <?= ($row['civil_status'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select>
                </div>
                <div class="field"><label>Educational Attainment</label>
                    <select name="educational_attainment"><?php foreach (EDU_OPTIONS as $o): ?><option <?= ($row['educational_attainment'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select>
                </div>
                <div class="field"><label>Occupation</label><input name="occupation" value="<?= h($row['occupation']) ?>"></div>
                <div class="field"><label>Date of Birth</label><input type="date" name="date_of_birth" value="<?= h($row['date_of_birth']) ?>"></div>
                <div class="field"><label>Contact No.</label><input name="contact_no" maxlength="11" pattern="\d{11}" value="<?= h($row['contact_no']) ?>"></div>
                <div class="field"><label>4P's Member</label>
                    <select name="is_4ps_member"><?php foreach (['No', 'Yes'] as $o): ?><option <?= ($row['is_4ps_member'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select>
                </div>
                <div class="field"><label>Total Family Members</label><input type="number" name="total_family_members" min="1" value="<?= (int) $row['total_family_members'] ?>" required></div>
                <div class="field"><label>PWDs in Household</label><input type="number" name="pwd_count" min="0" value="<?= (int) $row['pwd_count'] ?>" required></div>
                <div class="field"><label>Senior Citizens</label><input type="number" name="senior_citizens" min="0" value="<?= (int) $row['senior_citizens'] ?>" required></div>
            </div>

            <div class="sw-modal-section-title">Family Composition</div>
            <div class="sw-member-table">
                <table class="table">
                    <thead><tr><th>Name</th><th>Age</th><th>Sex</th><th>Relationship</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if (!$members): ?>
                        <tr><td colspan="5" class="empty">No family members recorded.</td></tr>
                    <?php else: foreach ($members as $idx => $m): ?>
                        <tr>
                            <td><?= h(trim($m['first_name'] . ' ' . ($m['middle_name'] ?? '') . ' ' . $m['last_name'])) ?></td>
                            <td><?= h($m['age'] ?? '—') ?></td>
                            <td><?= h(sex_label($m['sex'] ?? null)) ?></td>
                            <td><?= h($m['relationship'] ?: '—') ?></td>
                            <td><button type="button" class="btn btn-light" onclick="document.getElementById('memberEditor<?= $hid ?>-<?= $idx ?>').open = true">Edit</button></td>
                        </tr>
                        <tr>
                            <td colspan="5" style="padding:0;border-bottom:0;">
                                <details class="member-editor" id="memberEditor<?= $hid ?>-<?= $idx ?>">
                                    <summary>Editing Member <?= $idx + 1 ?></summary>
                                    <input type="hidden" name="member_ids[<?= $idx ?>]" value="<?= (int) $m['composition_id'] ?>">
                                    <div class="form-grid">
                                        <div class="field"><label>First Name</label><input name="comp_<?= $idx ?>_first_name" value="<?= h($m['first_name']) ?>" required></div>
                                        <div class="field"><label>Middle Name</label><input name="comp_<?= $idx ?>_middle_name" value="<?= h($m['middle_name']) ?>"></div>
                                        <div class="field"><label>Last Name</label><input name="comp_<?= $idx ?>_last_name" value="<?= h($m['last_name']) ?>" required></div>
                                        <div class="field"><label>Age</label><input type="number" name="comp_<?= $idx ?>_age" min="0" max="120" value="<?= h($m['age']) ?>"></div>
                                        <div class="field"><label>Sex</label>
                                            <select name="comp_<?= $idx ?>_sex"><?php foreach (['Male', 'Female'] as $o): ?><option <?= sex_value($m['sex'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select>
                                        </div>
                                        <div class="field"><label>Civil Status</label>
                                            <select name="comp_<?= $idx ?>_civil_status"><?php foreach (CIVIL_OPTIONS as $o): ?><option <?= ($m['civil_status'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select>
                                        </div>
                                        <div class="field"><label>Relationship</label><input name="comp_<?= $idx ?>_relationship" value="<?= h($m['relationship']) ?>"></div>
                                        <div class="field"><label>Educational Attainment</label>
                                            <select name="comp_<?= $idx ?>_educational_attainment"><?php foreach (EDU_OPTIONS as $o): ?><option <?= ($m['educational_attainment'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select>
                                        </div>
                                        <div class="field"><label>Occupation</label><input name="comp_<?= $idx ?>_occupation" value="<?= h($m['occupation']) ?>"></div>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="actions">
                <button type="button" class="btn btn-light" onclick="closeModal('editModal-<?= $hid ?>')">Cancel</button>
                <button class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; endif; ?>

<script>
(function () {
    var box = document.getElementById('hhSearch');
    if (!box) return;
    var rows = document.querySelectorAll('#hhTable tbody tr[data-name]');
    var none = document.getElementById('hhNoMatch');
    box.addEventListener('input', function () {
        var q = box.value.trim().toLowerCase(), shown = 0;
        rows.forEach(function (r) {
            var match = r.dataset.name.indexOf(q) !== -1;
            r.hidden = !match;
            if (match) shown++;
        });
        if (none) none.hidden = shown > 0 || rows.length === 0;
    });
})();
</script>

<?php page_end(); ?>
=======
require_once '../includes/access.php'; require_page_access(); require '../includes/layout.php';
require_once '../includes/db.php';
page_start('Household Management');

$households = db_select('household', '1=1', [], '*', 'barangay ASC, household_id DESC');

$byBarangay = [];
foreach ($households as $h) {
    $byBarangay[$h['barangay']][] = $h;
}
ksort($byBarangay);

$totalHouseholds = count($households);
$fourPsCount     = count(array_filter($households, fn($h) => $h['is_4ps_member'] === 'Yes'));
$totalPwd        = array_sum(array_column($households, 'pwd_count'));
$totalSeniors    = array_sum(array_column($households, 'senior_citizens'));
?>

<div class="page-head">
    <h1 class="page-title">Household Management</h1>
</div>

<!-- Stat cards: no emojis, no icon container, just number + label -->
<div class="grid g4">
    <?php foreach([[$totalHouseholds, 'Total Households'], [$fourPsCount, '4Ps Member Households'], [$totalPwd, 'Total PWD'], [$totalSeniors, 'Total Senior Citizens']] as $s): ?>
        <div class="card">
            <div class="stat-value"><?= $s[0] ?></div>
            <div class="stat-label"><?= $s[1] ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mt">
    <h2>Households by Barangay</h2>
    <?php if (empty($byBarangay)): ?>
        <p class="empty">No households have been recorded yet.</p>
    <?php endif; ?>
    <div class="grid g3">
        <?php foreach ($byBarangay as $barangay => $list): $bIndex = md5($barangay); ?>
            <div class="card">
                <div class="stat-value"><?= count($list) ?></div>
                <div class="stat-label"><?= htmlspecialchars($barangay) ?></div>
                <div class="actions mt">
                    <button class="btn btn-primary btn-block" onclick="openModal('barangayModal-<?= $bIndex ?>')">View</button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Per-barangay detail modals with all fields -->
<?php foreach ($byBarangay as $barangay => $list): $bIndex = md5($barangay); ?>
    <div id="barangayModal-<?= $bIndex ?>" class="modal">
        <div class="modal-box" style="max-width:960px;">
            <div class="modal-head">
                <h2><?= htmlspecialchars($barangay) ?> — Households</h2>
                <button class="icon-btn" onclick="closeModal('barangayModal-<?= $bIndex ?>')">✕</button>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Family Head</th>
                            <th>Age</th>
                            <th>Sex</th>
                            <th>Civil Status</th>
                            <th>Education</th>
                            <th>Occupation</th>
                            <th>Contact No.</th>
                            <th>DOB</th>
                            <th>4Ps</th>
                            <th>Members</th>
                            <th>PWD</th>
                            <th>Seniors</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($list as $h):
                            $fullName = preg_replace('/\s+/', ' ', trim(implode(' ', array_filter([
                                $h['firstname'], $h['middlename'], $h['lastname'], $h['nameextension']
                            ]))));
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($h['household_id']) ?></td>
                                <td><?= htmlspecialchars($fullName) ?></td>
                                <td><?= htmlspecialchars($h['age'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($h['sex'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($h['civil_status'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($h['educational_attainment'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($h['occupation'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($h['contact_no'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($h['date_of_birth'] ?? '—') ?></td>
                                <td><?= status_badge($h['is_4ps_member']) ?></td>
                                <td><?= htmlspecialchars($h['total_family_members']) ?></td>
                                <td><?= htmlspecialchars($h['pwd_count']) ?></td>
                                <td><?= htmlspecialchars($h['senior_citizens']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="actions mt">
                <button type="button" class="btn btn-light" onclick="closeModal('barangayModal-<?= $bIndex ?>')">Close</button>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php page_end(); ?>
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
