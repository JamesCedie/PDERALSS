<?php
<<<<<<< HEAD
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
=======
require_once '../includes/access.php'; require_page_access(); require '../includes/layout.php';
page_start('Damage Assessment');

$rows = [
    ['DA-001', 'Brgy. Jaro',    'Pedro Santos', 'House',          'Fully Damaged',     'Verified',     '2026-05-01'],
    ['DA-002', 'Brgy. Molo',    'Maria Reyes',  'House',          'Partially Damaged', 'Under Review', '2026-05-01'],
    ['DA-003', 'Brgy. Arevalo', 'Jose Garcia',  'Infrastructure', 'Severe',            'Verified',     '2026-04-30'],
    ['DA-004', 'Brgy. La Paz',  'Ana Cruz',     'House',          'Minor Damage',      'Verified',     '2026-04-30'],
];
?>

<div class="page-head">
    <h1 class="page-title">Damage Assessment &amp; Verification</h1>
    <button class="btn btn-primary" onclick="openModal('damageModal')">＋ Submit Assessment</button>
</div>

<div class="grid g3">
    <div class="card">
        <div class="stat">
            <div class="stat-icon blue">✓</div>
            <div>
                <div class="stat-value">156</div>
                <div class="stat-label">Total Assessments</div>
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
            </div>
        </div>
    </div>

<<<<<<< HEAD
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
=======
    <div class="card">
        <div class="stat">
            <div class="stat-icon green">✓</div>
            <div>
                <div class="stat-value">121</div>
                <div class="stat-label">Verified</div>
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
            </div>
        </div>
    </div>

<<<<<<< HEAD
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
=======
    <div class="card">
        <div class="stat">
            <div class="stat-icon yellow">⌛</div>
            <div>
                <div class="stat-value">35</div>
                <div class="stat-label">Under Review</div>
            </div>
        </div>
    </div>
</div>

<div class="card mt">
    <div class="alert alert-info">
        <b>Secure Report Submission</b>
        <div class="mini">Assessment records can be validated before inclusion in official allocation and logistics reports.</div>
    </div>

    <h2>Assessment Records</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Barangay</th>
                    <th>Reporter</th>
                    <th>Asset Type</th>
                    <th>Damage</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <?php foreach ($r as $i => $v): ?>
                            <td><?= $i === 5 ? status_badge($v) : htmlspecialchars($v) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="damageModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Submit Damage Assessment</h2>
            <button class="icon-btn" onclick="closeModal('damageModal')">✕</button>
        </div>

        <form onsubmit="event.preventDefault(); alert('Assessment submitted for verification.'); closeModal('damageModal')">
            <div class="form-grid">
                <div class="field">
                    <label>Barangay</label>
                    <input required>
                </div>

                <div class="field">
                    <label>Reporter</label>
                    <input required>
                </div>

                <div class="field">
                    <label>Asset Type</label>
                    <select>
                        <option>House</option>
                        <option>Infrastructure</option>
                        <option>Road</option>
                        <option>Facility</option>
                    </select>
                </div>

                <div class="field">
                    <label>Damage Status</label>
                    <select>
                        <option>Fully Damaged</option>
                        <option>Partially Damaged</option>
                        <option>Minor Damage</option>
                    </select>
                </div>

                <div class="field field-full">
                    <label>Description</label>
                    <textarea></textarea>
                </div>
            </div>

            <div class="actions mt">
                <button class="btn btn-primary">Submit Assessment</button>
            </div>
        </form>
    </div>
</div>

<?php page_end(); ?>
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
