<?php
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