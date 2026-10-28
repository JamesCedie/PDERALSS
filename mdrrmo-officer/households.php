<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';

if (($_SESSION['user']['role'] ?? '') !== 'MDRRMO Officer') {
    http_response_code(403);
    exit('Access denied.');
}

const EDU_OPTIONS   = ['Elementary', 'Highschool Undergraduate', 'Highschool Graduate', 'College Undergraduate', 'College Graduate'];
const CIVIL_OPTIONS = ['Married', 'Single', 'Widowed', 'Separated'];

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

/** Barangay names that can be opened: every barangay in the barangays table. */
function hh_folder_names(array $households): array
{
    $names = brgy_names();
    $extra = array_values(array_diff(array_unique(array_column($households, 'barangay')), $names));
    sort($extra);
    return array_values(array_filter(array_merge($names, $extra)));
}

// ── Page data ───────────────────────────────────────────────────────────────
$households = db_query('SELECT h.*, b.name AS barangay FROM household h JOIN barangays b ON b.barangay_id = h.barangay_id ORDER BY b.name ASC, h.household_id DESC')->fetchAll();
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
    page_start('Household Management', $subtitle, ['households.php', 'Household Management']);
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
        <span aria-hidden="true"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
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
            <button class="icon-btn" onclick="closeModal('viewModal-<?= $hid ?>')" aria-label="Close"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
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
