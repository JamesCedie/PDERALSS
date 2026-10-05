<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/md.php';
db_ensure_disaster_event_status();
db_ensure_casualties_table();

$eventId = (int) ($_GET['event_id'] ?? 0);
$event   = md_find_event($eventId);

// Counts per barangay and type for this event (MDRRMO is municipality-wide).
$perBarangay = [];
if ($event) {
    $rows = db_query(
        'SELECT barangay, type, COUNT(*) AS c FROM public.casualties WHERE event_id = ? GROUP BY barangay, type',
        [$eventId]
    )->fetchAll();
    foreach ($rows as $r) {
        $perBarangay[$r['barangay']][$r['type']] = (int) $r['c'];
    }
}
$names = md_barangay_names(array_keys($perBarangay));

$selected = $_GET['barangay'] ?? '';
$selected = ($event && in_array($selected, $names, true)) ? $selected : null;

function md_cas_counts(array $perType): array
{
    $injured  = (int) ($perType['Injured'] ?? 0);
    $missing  = (int) ($perType['Missing'] ?? 0);
    $fatality = (int) ($perType['Fatality'] ?? 0);
    return [$injured + $missing + $fatality, $injured, $missing, $fatality];
}

require '../includes/layout.php';
if ($selected !== null) {
    page_start('Casualty Monitoring', null, ['casualties.php?event_id=' . $eventId, '← Casualty Monitoring']);
} else {
    page_start('Casualty Monitoring', null, ['disasters.php', '← Disaster Events']);
}
?>

<div class="md-page">
<?php if (!$event): ?>
    <div class="card empty">Select a disaster event from Disaster Events to view its casualty monitoring.</div>

<?php elseif ($selected === null): ?>
    <?php
        $all = [0, 0, 0, 0];
        $totals = [];
        foreach ($names as $n) {
            $totals[$n] = md_cas_counts($perBarangay[$n] ?? []);
            foreach ($totals[$n] as $i => $v) $all[$i] += $v;
        }
        $max = max(1, ...array_column($totals, 0));
    ?>
    <div class="sw-event-label">Event:<strong><?= md_h($event['event_name']) ?></strong></div>

    <div class="sw-casualty-title">Casualty Count Breakdown</div>
    <div class="grid g4 md-stats">
        <?php foreach ([[$all[0], 'Total Casualties'], [$all[1], 'Injured'], [$all[2], 'Missing'], [$all[3], 'Fatalities']] as $s): ?>
            <div class="card sw-summary-card">
                <div class="stat-label"><?= md_h($s[1]) ?></div>
                <div class="stat-value"><?= number_format($s[0]) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card mt">
        <h2>Casualty Count per Barangay</h2>
        <?php foreach ($names as $n): $t = $totals[$n][0]; ?>
            <div class="barangay-row">
                <div class="barangay-row-head">
                    <span><?= md_h($n) ?></span>
                    <b><?= $t ?></b>
                </div>
                <div class="progress-track progress-track--lg">
                    <div class="progress-fill progress-fill--red" style="--fill:<?= round($t / $max * 100, 1) ?>%"></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card mt">
        <h2>Casualty Records</h2>
        <div class="sw-data-table">
            <table class="table">
                <thead>
                    <tr><th>Barangay</th><th>Casualty Count</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($names as $n): ?>
                    <tr>
                        <td><?= md_h($n) ?></td>
                        <td><?= $totals[$n][0] ?></td>
                        <td><a class="btn btn-dark-sm" href="casualties.php?event_id=<?= $eventId ?>&amp;barangay=<?= urlencode($n) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>
    <?php
        [$total, $injured, $missing, $fatality] = md_cas_counts($perBarangay[$selected] ?? []);
        $records = db_query(
            'SELECT name, type, date FROM public.casualties WHERE event_id = ? AND barangay = ? ORDER BY date DESC, casualty_id DESC',
            [$eventId, $selected]
        )->fetchAll();
    ?>
    <div class="sw-event-label">Event:<strong><?= md_h($event['event_name']) ?> · <?= md_h(md_short_barangay($selected)) ?></strong></div>

    <div class="sw-casualty-title">Total Casualties Recorded</div>
    <div class="md-split">
        <div class="card sw-total-card">
            <div class="stat-label">Casualty Count</div>
            <div class="stat-value"><?= $total ?></div>
        </div>
        <div class="card sw-breakdown-card">
            <h3>Casualty Count Breakdown</h3>
            <div class="sw-breakdown">
                <div class="sw-breakdown-item fatal"><label>Fatalities</label><strong><?= $fatality ?></strong></div>
                <div class="sw-breakdown-item missing"><label>Missing</label><strong><?= $missing ?></strong></div>
                <div class="sw-breakdown-item injured"><label>Injured</label><strong><?= $injured ?></strong></div>
            </div>
        </div>
    </div>

    <div class="sw-casualty-title mt">Casualty Records</div>
    <label class="sw-search">
        <span aria-hidden="true">⌕</span>
        <input type="search" id="mdSearch" placeholder="Search..." autocomplete="off">
    </label>

    <div class="sw-data-table">
        <table class="table" id="mdTable">
            <thead>
                <tr><th>Name</th><th>Type</th><th>Date</th></tr>
            </thead>
            <tbody>
            <?php if (!$records): ?>
                <tr><td colspan="3" class="empty">No casualties recorded yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($records as $r): ?>
                <tr data-search="<?= md_h(strtolower($r['name'] . ' ' . $r['type'])) ?>">
                    <td><?= md_h($r['name']) ?></td>
                    <td><?= md_h($r['type']) ?></td>
                    <td><?= md_h(md_date($r['date'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr id="mdNoMatch" hidden><td colspan="3" class="empty">No casualties match your search.</td></tr>
            </tbody>
        </table>
    </div>
    <script>document.addEventListener('DOMContentLoaded', function () { mdBindSearch('mdSearch', 'mdTable', 'mdNoMatch'); });</script>
<?php endif; ?>
</div>

<?php page_end(); ?>
