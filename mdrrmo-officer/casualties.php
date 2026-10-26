<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/md.php';
db_ensure_disaster_event_status();
db_ensure_casualties_table();

$eventId = (int) ($_GET['event_id'] ?? 0);
$event   = md_find_event($eventId);

// Counts per barangay and type for this event (MDRRMO is municipality-wide).
$perBarangay = [];
$lastUpdate  = [];
if ($event) {
    foreach (db_query(
        'SELECT barangay, MAX(created_at) AS last FROM public.casualties WHERE event_id = ? GROUP BY barangay',
        [$eventId]
    )->fetchAll() as $r) {
        $lastUpdate[$r['barangay']] = $r['last'];
    }
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
    page_start('Casualty Monitoring', null, ['casualties.php?event_id=' . $eventId, 'Casualty Monitoring']);
} else {
    page_start('Casualty Monitoring', null, ['disasters.php', 'Disaster Events']);
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
    ?>
    <div class="sw-event-label">Event:<strong><?= md_h($event['event_name']) ?></strong></div>

    <div class="sw-casualty-title">Casualty Monitoring</div>
    <div class="md-split cm-top">
        <div class="card sw-summary-card cm-total">
            <div class="stat-label">Total Casualties</div>
            <div class="stat-value"><?= number_format($all[0]) ?></div>
        </div>
        <div class="card sw-breakdown-card">
            <h3>Casualty Count Breakdown</h3>
            <div class="sw-breakdown">
                <div class="sw-breakdown-item"><label>Injured</label><strong><?= number_format($all[1]) ?></strong></div>
                <div class="sw-breakdown-item"><label>Missing</label><strong><?= number_format($all[2]) ?></strong></div>
                <div class="sw-breakdown-item"><label>Fatalities</label><strong><?= number_format($all[3]) ?></strong></div>
            </div>
        </div>
    </div>

    <div class="cm-folders-head">
        <div class="sw-casualty-title">Barangay Casualty Records</div>
        <span class="cm-folders-count"><?= count($names) ?> Barangay Folders</span>
    </div>
    <div class="cm-folders">
        <?php foreach ($names as $n): $t = $totals[$n][0]; ?>
            <div class="card cm-folder">
                <div>
                    <div class="cm-folder-name"><?= md_h($n) ?></div>
                    <div class="cm-folder-updated">Updated <?= md_h(md_date($lastUpdate[$n] ?? ($event['date'] ?? null), 'j M Y')) ?></div>
                    <div class="cm-folder-count"><strong><?= number_format($t) ?></strong> <span>casualties</span></div>
                </div>
                <a class="btn btn-dark-sm" href="casualties.php?event_id=<?= $eventId ?>&amp;barangay=<?= urlencode($n) ?>">Open records</a>
            </div>
        <?php endforeach; ?>
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
        <div class="card sw-summary-card">
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
        <span aria-hidden="true"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
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
                    <td><?= status_badge($r['type']) ?></td>
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
