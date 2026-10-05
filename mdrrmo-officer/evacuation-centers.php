<?php
require_once '../includes/access.php'; require_page_access();
<<<<<<< HEAD
require_once '../includes/md.php';
db_ensure_disaster_event_status();

$eventId = (int) ($_GET['event_id'] ?? 0);
$event   = md_find_event($eventId);

// MDRRMO is not barangay-scoped: every barangay that reported evacuees for this event is combined.
// Note: evacuation_evacuees.household_no stores the number of people in that household
// (the Social Worker page sums it as "Total People"), so the same convention is used here.
$perBarangay = [];
if ($event) {
    $rows = db_query(
        'SELECT barangay, COUNT(*) AS households, COALESCE(SUM(household_no), 0) AS people
         FROM evacuation_evacuees WHERE event_id = ? GROUP BY barangay',
        [$eventId]
    )->fetchAll();
    foreach ($rows as $r) {
        $perBarangay[$r['barangay']] = ['households' => (int) $r['households'], 'people' => (int) $r['people']];
    }
}
$names = md_barangay_names(array_keys($perBarangay));

$selected = $_GET['barangay'] ?? '';
$selected = ($event && in_array($selected, $names, true)) ? $selected : null;

require '../includes/layout.php';
if ($selected !== null) {
    page_start('Evacuation Center Records', null, ['evacuation-centers.php?event_id=' . $eventId, '← Evacuation Center Monitoring']);
} else {
    page_start('Evacuation Center Monitoring', null, ['disasters.php', '← Disaster Events']);
}
?>

<div class="md-page">
<?php if (!$event): ?>
    <div class="card empty">Select a disaster event from Disaster Events to view its evacuation center monitoring.</div>

<?php elseif ($selected === null): ?>
    <?php
        $totalPeople = array_sum(array_column($perBarangay, 'people'));
        $totalHH     = array_sum(array_column($perBarangay, 'households'));
        $reporting   = count($perBarangay);
        $max         = max(1, $perBarangay ? max(array_column($perBarangay, 'people')) : 1);

        // Map pin positions (% of the map area). Barangays without a preset get a spot along the bottom row.
        $pinSpots = [
            'Brgy. Jaro'       => [24, 16],
            'Brgy. Molo'       => [58, 26],
            'Brgy. Mandurriao' => [14, 52],
            'Brgy. La Paz'     => [76, 52],
            'Brgy. Arevalo'    => [46, 72],
        ];
        $centers  = [];
        $extraIdx = 0;
        foreach ($names as $n) {
            $spot   = $pinSpots[$n] ?? [12 + (($extraIdx++ * 18) % 72), 86];
            $people = $perBarangay[$n]['people'] ?? 0;
            $centers[] = [
                'barangay'   => $n,
                'name'       => md_short_barangay($n) . ' Evacuation Center',
                'people'     => $people,
                'households' => $perBarangay[$n]['households'] ?? 0,
                'status'     => $people > 0 ? 'Occupied' : 'Available',
                'x'          => $spot[0],
                'y'          => $spot[1],
                'url'        => 'evacuation-centers.php?event_id=' . $eventId . '&barangay=' . urlencode($n),
            ];
        }
        $first = 0;
        foreach ($centers as $i => $c) { if ($c['people'] > 0) { $first = $i; break; } }
    ?>
    <div class="sw-event-label">Event:<strong><?= md_h($event['event_name']) ?></strong></div>

    <div class="sw-casualty-title">Evacuation Count Breakdown</div>
    <div class="grid g3 md-stats">
        <?php foreach ([[$totalPeople, 'Total Evacuees'], [$totalHH, 'Evacuated Households'], [$reporting, 'Affected Barangays Reporting']] as $s): ?>
            <div class="card sw-summary-card">
                <div class="stat-label"><?= md_h($s[1]) ?></div>
                <div class="stat-value"><?= number_format($s[0]) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card mt md-map-card" id="mdMapCard">
        <div class="md-map-head">
            <div>
                <h2>Barangay and Evacuation Centers Map</h2>
                <div class="mini">Select an Evacuation Center from the map to view its details</div>
            </div>
            <button type="button" class="btn btn-dark-sm" id="mdMapToggle">View Full Map</button>
        </div>

        <div class="md-map-body">
            <div class="md-map" id="mdMap">
                <svg class="md-map-bg" viewBox="0 0 400 300" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 250 C70 215 120 275 190 240 S300 200 400 235" fill="none" stroke="#0f3a5c" stroke-width="16" stroke-linecap="round" opacity=".7"/>
                    <path d="M20 40 L120 90 L210 60 L330 120" fill="none" stroke="#17304a" stroke-width="1.5"/>
                    <path d="M60 160 L170 130 L260 170 L370 150" fill="none" stroke="#17304a" stroke-width="1.5"/>
                </svg>
                <div class="md-map-label">MUNICIPAL COVERAGE</div>
                <div class="md-map-zoom">
                    <button type="button" id="mdZoomIn" aria-label="Zoom in">+</button>
                    <button type="button" id="mdZoomOut" aria-label="Zoom out">−</button>
                </div>
                <div class="md-map-layer" id="mdMapLayer">
                    <?php foreach ($centers as $i => $c): ?>
                        <button type="button" class="md-pin" data-i="<?= $i ?>" style="left:<?= $c['x'] ?>%;top:<?= $c['y'] ?>%">
                            <span class="md-pin-label">📍 <?= md_h($c['barangay']) ?></span>
                            <span class="md-pin-dot">⌂</span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="md-map-legend">
                    <span><i class="dot blue"></i> Barangay</span>
                    <span><i class="dot green"></i> Evacuation center</span>
                    <span class="md-map-count"><?= count($centers) ?> centers available</span>
                </div>
            </div>

            <aside class="md-center-panel" id="mdCenterPanel">
                <div class="md-center-kicker">EVACUATION CENTER</div>
                <h3 id="mdCName"></h3>
                <div class="mini" id="mdCBarangay"></div>
                <div class="md-center-stats">
                    <div class="sw-breakdown-item"><label>TOTAL EVACUEES</label><strong id="mdCPeople">0</strong></div>
                    <div class="sw-breakdown-item"><label>HOUSEHOLDS</label><strong id="mdCHouseholds">0</strong></div>
                </div>
                <div class="md-center-status">
                    <span class="mini">OCCUPATION STATUS</span>
                    <span class="badge" id="mdCStatus"></span>
                </div>
                <a class="btn btn-dark-sm btn-block" id="mdCOpen" href="#">Open Records</a>
            </aside>
=======
require_once '../includes/db.php';
db_ensure_disaster_event_status();
require '../includes/layout.php';

$eventId = (int) ($_GET['event_id'] ?? 0);
$currentEvent = $eventId ? db_select_one('disaster_events', 'event_id = ?', [$eventId]) : null;

page_start('Evacuation Centers');

// MDRRMO is not barangay-scoped. The summary below is therefore filtered only
// by event_id and combines every barangay that reported evacuees for that event.
$summary = [
    'people'    => 0,
    'households'=> 0,
    'barangays' => 0,
];
$barangays = [];

if ($currentEvent) {
    $summary = db_query(
        "SELECT
            COALESCE(SUM(household_no), 0) AS people,
            COUNT(*) AS households,
            COUNT(DISTINCT barangay) AS barangays
         FROM evacuation_evacuees
         WHERE event_id = ?",
        [$eventId]
    )->fetch() ?: $summary;

    $barangays = db_query(
        "SELECT barangay,
                COUNT(*) AS households,
                COALESCE(SUM(household_no), 0) AS people
         FROM evacuation_evacuees
         WHERE event_id = ?
         GROUP BY barangay
         ORDER BY barangay ASC",
        [$eventId]
    )->fetchAll();
}
?>

<div class="page-head">
    <div style="display:flex;align-items:center;gap:12px;">
        <a href="disasters.php" class="btn btn-light">← Disaster Events</a>
        <h1 class="page-title">Evacuation Center Management</h1>
    </div>
</div>

<?php if (!$currentEvent): ?>
    <div class="card empty">Select a disaster event from Disaster Events to view its evacuation-center summary.</div>
<?php else: ?>
    <div class="event-status-row" style="justify-content:flex-start;margin-top:0;">
        <div class="mini">Event: <strong><?= htmlspecialchars($currentEvent['event_name']) ?></strong> · <?= htmlspecialchars($currentEvent['type']) ?> · <?= htmlspecialchars($currentEvent['date']) ?></div>
        <?= status_badge(($currentEvent['status'] ?? 'Active') === 'Active' ? 'Active' : 'Passed') ?>
    </div>

    <div class="grid g3 md-event-summary-grid">
        <div class="card md-event-summary-card">
            <div class="stat-value"><?= htmlspecialchars($summary['people']) ?></div>
            <div class="stat-label">Total People Evacuated</div>
        </div>
        <div class="card md-event-summary-card">
            <div class="stat-value"><?= htmlspecialchars($summary['households']) ?></div>
            <div class="stat-label">Evacuated Households</div>
        </div>
        <div class="card md-event-summary-card">
            <div class="stat-value"><?= htmlspecialchars($summary['barangays']) ?></div>
            <div class="stat-label">Affected Barangays Reporting</div>
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
        </div>
    </div>

    <div class="card mt">
<<<<<<< HEAD
        <h2>Evacuee Count per Barangay</h2>
        <?php foreach ($names as $n): $p = $perBarangay[$n]['people'] ?? 0; ?>
            <div class="barangay-row">
                <div class="barangay-row-head">
                    <span><?= md_h($n) ?></span>
                    <b><?= $p ?></b>
                </div>
                <div class="progress-track progress-track--lg">
                    <div class="progress-fill" style="--fill:<?= round($p / $max * 100, 1) ?>%"></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var centers = <?= json_encode($centers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        var pins = document.querySelectorAll('#mdMap .md-pin');
        var badgeClass = { Available: 'b-green', Occupied: 'b-yellow' };

        function select(i) {
            var c = centers[i];
            if (!c) return;
            pins.forEach(function (p) { p.classList.toggle('active', +p.dataset.i === i); });
            document.getElementById('mdCName').textContent = c.name;
            document.getElementById('mdCBarangay').textContent = c.barangay;
            document.getElementById('mdCPeople').textContent = c.people;
            document.getElementById('mdCHouseholds').textContent = c.households;
            var st = document.getElementById('mdCStatus');
            st.textContent = c.status;
            st.className = 'badge ' + (badgeClass[c.status] || 'b-gray');
            document.getElementById('mdCOpen').href = c.url;
        }
        pins.forEach(function (p) { p.addEventListener('click', function () { select(+p.dataset.i); }); });
        select(<?= (int) $first ?>);

        var zoom = 1, layer = document.getElementById('mdMapLayer');
        function setZoom(z) { zoom = Math.max(0.8, Math.min(1.4, z)); layer.style.transform = 'scale(' + zoom + ')'; }
        document.getElementById('mdZoomIn').addEventListener('click', function () { setZoom(zoom + 0.2); });
        document.getElementById('mdZoomOut').addEventListener('click', function () { setZoom(zoom - 0.2); });

        var card = document.getElementById('mdMapCard'), toggle = document.getElementById('mdMapToggle');
        toggle.addEventListener('click', function () {
            var full = card.classList.toggle('md-map-full');
            toggle.textContent = full ? 'Exit Full Map' : 'View Full Map';
        });
    });
    </script>

<?php else: ?>
    <?php
        $records = db_query(
            'SELECT name, household_no, created_at FROM evacuation_evacuees WHERE event_id = ? AND barangay = ? ORDER BY created_at DESC',
            [$eventId, $selected]
        )->fetchAll();
        $people = $perBarangay[$selected]['people'] ?? 0;
        $hh     = $perBarangay[$selected]['households'] ?? 0;
    ?>
    <div class="sw-event-label">Event:<strong><?= md_h($event['event_name']) ?> · <?= md_h(md_short_barangay($selected)) ?></strong></div>

    <div class="sw-casualty-title">Occupancy Count Breakdown</div>
    <div class="grid g2 md-stats">
        <div class="card sw-summary-card"><div class="stat-label">Total People</div><div class="stat-value"><?= number_format($people) ?></div></div>
        <div class="card sw-summary-card"><div class="stat-label">Total Households</div><div class="stat-value"><?= number_format($hh) ?></div></div>
    </div>

    <div class="sw-casualty-title mt">Evacuation Records</div>
    <label class="sw-search">
        <span aria-hidden="true">⌕</span>
        <input type="search" id="mdSearch" placeholder="Search..." autocomplete="off">
    </label>

    <div class="sw-data-table">
        <table class="table" id="mdTable">
            <thead>
                <tr><th>Name</th><th>Household No</th><th>Date/Time</th></tr>
            </thead>
            <tbody>
            <?php if (!$records): ?>
                <tr><td colspan="3" class="empty">No evacuees recorded yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($records as $r): ?>
                <tr data-search="<?= md_h(strtolower($r['name'])) ?>">
                    <td><?= md_h($r['name']) ?></td>
                    <td><?= md_h($r['household_no'] ?? '—') ?></td>
                    <td><?= md_h(md_date($r['created_at'], 'M j, Y g:i A')) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr id="mdNoMatch" hidden><td colspan="3" class="empty">No evacuees match your search.</td></tr>
            </tbody>
        </table>
    </div>
    <script>document.addEventListener('DOMContentLoaded', function () { mdBindSearch('mdSearch', 'mdTable', 'mdNoMatch'); });</script>
<?php endif; ?>
</div>
=======
        <h2>Event Evacuation Overview</h2>
        <p class="mini">This view is event-specific and combines evacuation reports from all barangays affected by <strong><?= htmlspecialchars($currentEvent['event_name']) ?></strong>.</p>

        <?php if (empty($barangays)): ?>
            <div class="empty">No evacuation records have been reported for this event yet.</div>
        <?php else: ?>
            <div class="md-affected-barangays">
                <?php foreach ($barangays as $row): ?>
                    <span class="barangay-chip"><?= htmlspecialchars($row['barangay']) ?> · <?= htmlspecialchars($row['people']) ?> people</span>
                <?php endforeach; ?>
            </div>

            <div class="table-wrap mt">
                <table class="table">
                    <thead>
                        <tr><th>Barangay Reporting</th><th>Household Records</th><th>People Evacuated</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($barangays as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['barangay']) ?></td>
                            <td><?= htmlspecialchars($row['households']) ?></td>
                            <td><?= htmlspecialchars($row['people']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929

<?php page_end(); ?>
