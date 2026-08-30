<?php
require_once '../includes/access.php'; require_page_access(); require '../includes/layout.php';
require_once '../includes/db.php';
page_start('Household Management');

$households = db_select('household', '1=1', [], '*', 'barangay ASC, household_id DESC');

// Group households by barangay for the "folder" view.
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
    <h2>Households by Barangay</h2>
    <?php if (empty($byBarangay)): ?>
        <p class="empty">No households have been recorded yet.</p>
    <?php endif; ?>
    <div class="grid g3">
        <?php foreach ($byBarangay as $barangay => $list): $bIndex = md5($barangay); ?>
            <div class="card">
                <div class="stat">
                    <div class="stat-icon blue">📁</div>
                    <div>
                        <div class="stat-value"><?= count($list) ?></div>
                        <div class="stat-label"><?= htmlspecialchars($barangay) ?></div>
                    </div>
                </div>
                <div class="actions mt">
                    <button class="btn btn-primary btn-block" onclick="openModal('barangayModal-<?= $bIndex ?>')">View</button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php foreach ($byBarangay as $barangay => $list): $bIndex = md5($barangay); ?>
    <div id="barangayModal-<?= $bIndex ?>" class="modal">
        <div class="modal-box" style="max-width: 900px;">
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
                            <th>Members</th>
                            <th>4Ps</th>
                            <th>PWD</th>
                            <th>Senior Citizens</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($list as $h):
                            $fullName = trim($h['firstname'] . ' ' . $h['middlename'] . ' ' . $h['lastname'] . ' ' . $h['nameextension']);
                            $fullName = preg_replace('/\s+/', ' ', $fullName);
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($h['household_id']) ?></td>
                                <td><?= htmlspecialchars($fullName) ?></td>
                                <td><?= htmlspecialchars($h['total_family_members']) ?></td>
                                <td><?= status_badge($h['is_4ps_member']) ?></td>
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