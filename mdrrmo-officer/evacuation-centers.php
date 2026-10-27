<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/md.php';
db_ensure_disaster_event_status();
db_ensure_evac_center_status_table();

$eventId = (int) ($_GET['event_id'] ?? 0);
$event   = md_find_event($eventId);

// MDRRMO is not barangay-scoped: every barangay that reported evacuees for this event is combined.
// Note: evacuation_evacuees.people_count stores the number of people in that household
// (the Social Worker page sums it as "Total People"), so the same convention is used here.
$perBarangay = [];
if ($event) {
    $rows = db_query(
        'SELECT b.name AS barangay, COUNT(*) AS households, COALESCE(SUM(x.people_count), 0) AS people
         FROM evacuation_evacuees x
         JOIN evacuation_centers c ON c.center_id = x.center_id
         JOIN barangays b ON b.barangay_id = c.barangay_id
         WHERE x.event_id = ? GROUP BY b.name',
        [$eventId]
    )->fetchAll();
    foreach ($rows as $r) {
        $perBarangay[$r['barangay']] = ['households' => (int) $r['households'], 'people' => (int) $r['people']];
    }
}
// Availability chosen by each Social Worker (Occupancy Status dropdown); falls back to Occupied/Available by headcount.
$storedStatus = [];
if ($event) {
    foreach (db_query(
        'SELECT b.name AS barangay, s.status
         FROM evacuation_center_status s
         JOIN evacuation_centers c ON c.center_id = s.center_id
         JOIN barangays b ON b.barangay_id = c.barangay_id
         WHERE s.event_id = ?',
        [$eventId]
    )->fetchAll() as $r) {
        $storedStatus[$r['barangay']] = $r['status'];
    }
}
// Center name + map position per barangay. A center with no coordinates of its own uses its barangay's.
$centerInfo = [];
foreach (db_query(
    'SELECT b.name AS barangay, c.center_name,
            COALESCE(c.latitude,  b.latitude)  AS lat,
            COALESCE(c.longitude, b.longitude) AS lng
     FROM evacuation_centers c JOIN barangays b ON b.barangay_id = c.barangay_id'
)->fetchAll() as $r) {
    $centerInfo[$r['barangay']] = $r;
}
$names = md_barangay_names(array_keys($perBarangay));

$selected = $_GET['barangay'] ?? '';
$selected = ($event && in_array($selected, $names, true)) ? $selected : null;

require '../includes/layout.php';
if ($selected !== null) {
    page_start('Evacuation Center Records', null, ['evacuation-centers.php?event_id=' . $eventId, 'Evacuation Center Monitoring']);
} else {
    page_start('Evacuation Center Monitoring', null, ['disasters.php', 'Disaster Events']);
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

        // Oton boundary for the map mask (run oton_map_setup.sql to create/fill the table).
        $boundaryRings = [];
        try {
            $bRow = db_query('SELECT geojson FROM public.municipality_boundary WHERE id = 1')->fetch();
            $bGeo = $bRow ? json_decode(is_string($bRow['geojson']) ? $bRow['geojson'] : json_encode($bRow['geojson']), true) : null;
            if ($bGeo && ($bGeo['type'] ?? '') === 'Polygon') {
                $boundaryRings[] = array_map(fn($c) => [(float) $c[1], (float) $c[0]], $bGeo['coordinates'][0]);
            }
        } catch (Throwable $e) { /* table not created yet: map shows without a boundary */ }

        // Map pin positions come from the database (center's own coordinates, else its barangay's).
        // Barangays without coordinates yet are listed but not pinned.
        $centers  = [];
        foreach ($names as $n) {
            $ci     = $centerInfo[$n] ?? null;
            $spot   = [isset($ci['lat']) ? (float) $ci['lat'] : null, isset($ci['lng']) ? (float) $ci['lng'] : null];
            $people = $perBarangay[$n]['people'] ?? 0;
            $centers[] = [
                'barangay'   => $n,
                'name'       => $ci['center_name'] ?? (md_short_barangay($n) . ' Evacuation Center'),
                'people'     => $people,
                'households' => $perBarangay[$n]['households'] ?? 0,
                'status'     => $storedStatus[$n] ?? 'Available',
                'lat'        => $spot[0],
                'lng'        => $spot[1],
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
                <div id="mdLeaflet" class="md-leaflet" aria-label="Map of Oton, Iloilo"></div>
                <div class="md-map-label">MUNICIPAL COVERAGE</div>
                <div class="md-map-zoom">
                    <button type="button" id="mdZoomIn" aria-label="Zoom in">+</button>
                    <button type="button" id="mdZoomOut" aria-label="Zoom out">−</button>
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
        </div>
    </div>

    <div class="card mt">
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

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var centers = <?= json_encode($centers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        var badgeClass = { Available: 'b-green', Occupied: 'b-yellow', 'Near Capacity': 'b-yellow', Full: 'b-red' };
        var pinEls = {}, markers = {};

        // Live OpenStreetMap (Leaflet) centred on Oton
        var map = L.map('mdLeaflet', { zoomControl: false, scrollWheelZoom: true }).setView([10.726, 122.472], 12);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        // Dim everything outside Oton: a world-sized mask with the municipal boundary cut out as a hole.
        var cityLayers = null, lastRings = null;
        function themeVar(name, fallback) {
            var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            return v || fallback;
        }
        function drawBoundary(outerRings) {
            lastRings = outerRings;
            if (cityLayers) { cityLayers.remove(); }
            var world = [[-85, -180], [-85, 180], [85, 180], [85, -180]];
            var mask = L.polygon([world].concat(outerRings), {
                stroke: false, fillColor: themeVar('--map-mask', '#F4F6FA'), fillOpacity: 0.62, interactive: false
            });
            var outline = L.polygon(outerRings, {
                color: themeVar('--sig-blue', '#1D5FA8'), weight: 2, dashArray: '6 4', fill: false, interactive: false
            });
            cityLayers = L.layerGroup([mask, outline]).addTo(map);
            map.setMaxBounds(outline.getBounds().pad(0.5));
        }
        // Oton boundary from public.municipality_boundary (GeoJSON Polygon, [lng,lat] -> Leaflet [lat,lng]).
        var otonRings = <?= json_encode($boundaryRings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        if (otonRings && otonRings.length) drawBoundary(otonRings);
        document.addEventListener('themechange', function () { if (lastRings) drawBoundary(lastRings); });

        function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

        function select(i) {
            var c = centers[i];
            if (!c) return;
            Object.keys(pinEls).forEach(function (k) { pinEls[k].classList.toggle('active', +k === i); });
            Object.keys(markers).forEach(function (k) { markers[k].setZIndexOffset(+k === i ? 1000 : 0); });
            document.getElementById('mdCName').textContent = c.name;
            document.getElementById('mdCBarangay').textContent = c.barangay;
            document.getElementById('mdCPeople').textContent = Number(c.people) || 0;
            document.getElementById('mdCHouseholds').textContent = Number(c.households) || 0;
            var st = document.getElementById('mdCStatus');
            st.textContent = c.status;
            st.className = 'badge ' + (badgeClass[c.status] || 'b-gray');
            document.getElementById('mdCOpen').href = c.url;
        }

        var pts = [];
        centers.forEach(function (c, i) {
            if (c.lat === null || c.lng === null) return;
            var icon = L.divIcon({
                className: 'md-leaflet-pin',
                iconSize: [0, 0],
                html: '<button type="button" class="md-pin" data-i="' + i + '"><span class="md-pin-label">\uD83D\uDCCD ' + esc(c.barangay) + '</span><span class="md-pin-dot">\u2302</span></button>'
            });
            var m = L.marker([c.lat, c.lng], { icon: icon, title: c.barangay, keyboard: false }).addTo(map);
            markers[i] = m;
            pinEls[i] = m.getElement().querySelector('.md-pin');
            pts.push([c.lat, c.lng]);
        });
        if (pts.length) map.fitBounds(pts, { padding: [60, 60], maxZoom: 14 });

        // Same behaviour as before: clicking a pin reads its index and shows that barangay's numbers.
        document.getElementById('mdLeaflet').addEventListener('click', function (e) {
            var pin = e.target.closest ? e.target.closest('.md-pin') : null;
            if (pin && pin.dataset.i !== undefined) select(+pin.dataset.i);
        });
        select(<?= (int) $first ?>);

        document.getElementById('mdZoomIn').addEventListener('click', function () { map.zoomIn(); });
        document.getElementById('mdZoomOut').addEventListener('click', function () { map.zoomOut(); });

        var card = document.getElementById('mdMapCard'), toggle = document.getElementById('mdMapToggle');
        toggle.addEventListener('click', function () {
            var full = card.classList.toggle('md-map-full');
            toggle.textContent = full ? 'Exit Full Map' : 'View Full Map';
            setTimeout(function () {
                map.invalidateSize();
                if (pts.length) map.fitBounds(pts, { padding: [60, 60], maxZoom: 14 });
            }, 50);
        });
    });
    </script>

<?php else: ?>
    <?php
        $records = db_query(
            'SELECT x.name, x.people_count, x.created_at
             FROM evacuation_evacuees x
             JOIN evacuation_centers c ON c.center_id = x.center_id
             WHERE x.event_id = ? AND c.barangay_id = ? ORDER BY x.created_at DESC',
            [$eventId, brgy_id($selected)]
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
        <span aria-hidden="true"><svg class="ui-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
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
                    <td><?= md_h($r['people_count'] ?? '—') ?></td>
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

<?php page_end(); ?>
