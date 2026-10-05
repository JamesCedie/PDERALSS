<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/md.php';

// UI-ONLY SAMPLE DATA — no database is used on this page yet.
// Replace this array with a real query when the damage-assessment table is ready.
$assessments = [
    ['id' => 1, 'barangay' => 'Brgy. Arevalo',     'family_head_name' => 'Dela Cruz, Juan',  'damage_level' => 'Totally Damaged',   'assessed_date' => '2026-09-29', 'notes' => 'House washed away by floodwater.'],
    ['id' => 2, 'barangay' => 'Brgy. Arevalo',     'family_head_name' => 'Santos, Maria',    'damage_level' => 'Partially Damaged', 'assessed_date' => '2026-09-28', 'notes' => 'Roof and walls damaged.'],
    ['id' => 3, 'barangay' => 'Brgy. Jaro',        'family_head_name' => 'Reyes, Pedro',     'damage_level' => 'Partially Damaged', 'assessed_date' => '2026-09-26', 'notes' => null],
    ['id' => 4, 'barangay' => 'Brgy. Molo',        'family_head_name' => 'Garcia, Ana',      'damage_level' => 'Totally Damaged',   'assessed_date' => '2026-09-18', 'notes' => 'Collapsed after landslide.'],
    ['id' => 5, 'barangay' => 'Brgy. La Paz',      'family_head_name' => 'Flores, Jose',     'damage_level' => 'Partially Damaged', 'assessed_date' => '2026-09-20', 'notes' => null],
];

$perBarangay = [];
$updatedAt   = [];
foreach ($assessments as $a) {
    $perBarangay[$a['barangay']][$a['damage_level']] = ($perBarangay[$a['barangay']][$a['damage_level']] ?? 0) + 1;
    if (empty($updatedAt[$a['barangay']]) || $a['assessed_date'] > $updatedAt[$a['barangay']]) {
        $updatedAt[$a['barangay']] = $a['assessed_date'];
    }
}
$names = md_barangay_names(array_keys($perBarangay));

function md_dmg_counts(array $perLevel): array
{
    $totally   = (int) ($perLevel['Totally Damaged'] ?? 0);
    $partially = (int) ($perLevel['Partially Damaged'] ?? 0);
    return [$totally + $partially, $totally, $partially];
}

$selected = $_GET['barangay'] ?? '';
$selected = in_array($selected, $names, true) ? $selected : null;

require '../includes/layout.php';
if ($selected !== null) {
    page_start('Damage Assessment', null, ['damage-assessment.php', '← Damage Assessment']);
} else {
    page_start('Damage Assessment');
}
?>

<div class="md-page">
<?php if ($selected === null): ?>
    <?php
        $all = [0, 0, 0];
        foreach ($names as $n) {
            foreach (md_dmg_counts($perBarangay[$n] ?? []) as $i => $v) $all[$i] += $v;
        }
    ?>
    <div class="sw-casualty-title">Damage Assessment Monitoring</div>
    <div class="md-split">
        <div class="card sw-total-card">
            <div class="stat-label">Assessments Count</div>
            <div class="stat-value"><?= number_format($all[0]) ?></div>
        </div>
        <div class="card sw-breakdown-card">
            <h3>Assessment Count Breakdown</h3>
            <div class="sw-breakdown md-breakdown-2">
                <div class="sw-breakdown-item fatal"><label>Totally Damaged</label><strong><?= number_format($all[1]) ?></strong></div>
                <div class="sw-breakdown-item injured"><label>Partially Damaged</label><strong><?= number_format($all[2]) ?></strong></div>
            </div>
        </div>
    </div>

    <div class="hh-dir-head">
        <div class="sw-records-title">Barangay Damage Assessment Records</div>
        <span class="hh-count"><?= count($names) ?> Barangay folders</span>
    </div>

    <div class="hh-folders">
        <?php foreach ($names as $n):
            $count = md_dmg_counts($perBarangay[$n] ?? [])[0];
            $meta  = !empty($updatedAt[$n]) ? 'Updated ' . md_date($updatedAt[$n], 'j M Y') : 'No records yet';
        ?>
            <div class="hh-folder">
                <div>
                    <div class="hh-folder-name"><?= md_h($n) ?></div>
                    <div class="hh-folder-meta"><?= md_h($meta) ?></div>
                    <div class="hh-folder-count"><strong><?= $count ?></strong> <span><?= $count === 1 ? 'household' : 'households' ?></span></div>
                </div>
                <a class="btn btn-dark-sm" href="damage-assessment.php?barangay=<?= urlencode($n) ?>">Open records</a>
            </div>
        <?php endforeach; ?>
    </div>

<?php else: ?>
    <?php
        [$total, $totally, $partially] = md_dmg_counts($perBarangay[$selected] ?? []);
        $records = array_values(array_filter($assessments, fn($a) => $a['barangay'] === $selected));
        usort($records, fn($a, $b) => strcmp($b['assessed_date'], $a['assessed_date']));
    ?>
    <div class="sw-event-label">Barangay:<strong><?= md_h(md_short_barangay($selected)) ?></strong></div>

    <div class="sw-casualty-title">Total Assessments</div>
    <div class="md-split">
        <div class="card sw-total-card">
            <div class="stat-label">Assessments Count</div>
            <div class="stat-value"><?= number_format($total) ?></div>
        </div>
        <div class="card sw-breakdown-card">
            <h3>Assessment Count Breakdown</h3>
            <div class="sw-breakdown md-breakdown-2">
                <div class="sw-breakdown-item fatal"><label>Totally Damaged</label><strong><?= number_format($totally) ?></strong></div>
                <div class="sw-breakdown-item injured"><label>Partially Damaged</label><strong><?= number_format($partially) ?></strong></div>
            </div>
        </div>
    </div>

    <div class="sw-casualty-title mt">Assessment Records</div>
    <label class="sw-search">
        <span aria-hidden="true">⌕</span>
        <input type="search" id="mdSearch" placeholder="Search..." autocomplete="off">
    </label>

    <div class="sw-data-table">
        <table class="table" id="mdTable">
            <thead>
                <tr><th>Family Head Name</th><th>Damage Level</th><th>Date</th><th class="vr-right">Action</th></tr>
            </thead>
            <tbody>
            <?php if (!$records): ?>
                <tr><td colspan="4" class="empty">No damage assessments recorded for <?= md_h($selected) ?> yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($records as $r): ?>
                <tr data-search="<?= md_h(strtolower($r['family_head_name'] . ' ' . $r['damage_level'])) ?>">
                    <td><strong><?= md_h($r['family_head_name']) ?></strong></td>
                    <td><?= md_h($r['damage_level']) ?></td>
                    <td><?= md_h(md_date($r['assessed_date'])) ?></td>
                    <td class="vr-right"><button type="button" class="btn btn-dark-sm" onclick="openModal('damageView-<?= (int) $r['id'] ?>')">View</button></td>
                </tr>
            <?php endforeach; ?>
            <tr id="mdNoMatch" hidden><td colspan="4" class="empty">No assessments match your search.</td></tr>
            </tbody>
        </table>
    </div>

    <?php foreach ($records as $r): $mid = 'damageView-' . (int) $r['id']; ?>
        <div id="<?= $mid ?>" class="modal">
            <div class="modal-box md-view-modal">
                <div class="modal-head">
                    <div>
                        <h2>Damage Assessment Details</h2>
                        <div class="mini"><?= md_h($selected) ?> · Record #<?= (int) $r['id'] ?></div>
                    </div>
                    <button type="button" class="icon-btn" onclick="closeModal('<?= $mid ?>')" aria-label="Close">×</button>
                </div>
                <div class="sw-view-grid">
                    <div class="sw-view-item"><label>Family Head</label><div><?= md_h($r['family_head_name']) ?></div></div>
                    <div class="sw-view-item"><label>Damage Level</label><div><?= md_h($r['damage_level']) ?></div></div>
                    <div class="sw-view-item"><label>Date Assessed</label><div><?= md_h(md_date($r['assessed_date'])) ?></div></div>
                    <div class="sw-view-item md-view-wide"><label>Notes</label><div><?= $r['notes'] ? md_h($r['notes']) : 'No notes.' ?></div></div>
                </div>
                <div class="actions">
                    <button type="button" class="btn btn-primary" onclick="closeModal('<?= $mid ?>')">Close</button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <script>document.addEventListener('DOMContentLoaded', function () { mdBindSearch('mdSearch', 'mdTable', 'mdNoMatch'); });</script>
<?php endif; ?>
</div>

<?php page_end(); ?>
