<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/md.php';
db_ensure_disaster_event_status();
db_ensure_evac_center_status_table();

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
// Availability chosen by each Social Worker (Occupancy Status dropdown); falls back to Occupied/Available by headcount.
$storedStatus = [];
if ($event) {
    foreach (db_select('evacuation_center_status', 'event_id = ?', [$eventId], 'barangay, status') as $r) {
        $storedStatus[$r['barangay']] = $r['status'];
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

        // Map pin positions (real lat/lng, Iloilo City). Barangays without a preset coordinate are listed but not pinned.
        $pinSpots = [
            'Brgy. Jaro'       => [10.7202, 122.5621],
            'Brgy. Molo'       => [10.6969, 122.5436],
            'Brgy. Mandurriao' => [10.7116, 122.5395],
            'Brgy. La Paz'     => [10.7160, 122.5490],
            'Brgy. Arevalo'    => [10.6830, 122.5240],
        ];
        $centers  = [];
        foreach ($names as $n) {
            $spot   = $pinSpots[$n] ?? [null, null];
            $people = $perBarangay[$n]['people'] ?? 0;
            $centers[] = [
                'barangay'   => $n,
                'name'       => md_short_barangay($n) . ' Evacuation Center',
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
                <div id="mdLeaflet" class="md-leaflet" aria-label="Map of Iloilo City"></div>
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

        // Live OpenStreetMap (Leaflet) centred on Iloilo City
        var map = L.map('mdLeaflet', { zoomControl: false, scrollWheelZoom: true }).setView([10.705, 122.545], 13);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        // Dim everything outside Iloilo City: a world-sized mask with the city boundary cut out as a hole.
        var cityLayers = null;
        function drawBoundary(outerRings) {
            if (cityLayers) { cityLayers.remove(); }
            var world = [[-85, -180], [-85, 180], [85, 180], [85, -180]];
            var mask = L.polygon([world].concat(outerRings), {
                stroke: false, fillColor: '#071522', fillOpacity: 0.62, interactive: false
            });
            var outline = L.polygon(outerRings, {
                color: '#3d8fe0', weight: 2, dashArray: '6 4', fill: false, interactive: false
            });
            cityLayers = L.layerGroup([mask, outline]).addTo(map);
            map.setMaxBounds(outline.getBounds().pad(0.5));
        }
        // Approximate outline, used only if the live boundary cannot be fetched.
        var fallbackRing = [[10.760,122.545],[10.755,122.575],[10.735,122.592],[10.715,122.590],[10.700,122.580],
            [10.685,122.565],[10.670,122.540],[10.660,122.510],[10.670,122.490],[10.690,122.495],[10.705,122.515],
            [10.725,122.520],[10.745,122.525]];
        function ringsFromGeoJSON(g) {
            var polys = g.type === 'Polygon' ? [g.coordinates] : (g.type === 'MultiPolygon' ? g.coordinates : []);
            return polys.map(function (poly) {
                return poly[0].map(function (c) { return [c[1], c[0]]; });
            });
        }
        drawBoundary([fallbackRing]);
        (function loadBoundary() {
            var KEY = 'mdIloiloBoundary';
            try {
                var cached = JSON.parse(localStorage.getItem(KEY) || 'null');
                if (cached && cached.length) { drawBoundary(cached); return; }
            } catch (e) {}
            fetch('https://nominatim.openstreetmap.org/search?q=' + encodeURIComponent('Iloilo City, Philippines') +
                  '&format=jsonv2&polygon_geojson=1&polygon_threshold=0.0005&limit=5&countrycodes=ph')
                .then(function (r) { return r.json(); })
                .then(function (list) {
                    var hit = (list || []).filter(function (x) {
                        return x.geojson && (x.geojson.type === 'Polygon' || x.geojson.type === 'MultiPolygon') && x.osm_type === 'relation';
                    })[0];
                    if (!hit) return;
                    var rings = ringsFromGeoJSON(hit.geojson);
                    if (!rings.length) return;
                    drawBoundary(rings);
                    try { localStorage.setItem(KEY, JSON.stringify(rings)); } catch (e) {}
                })
                .catch(function () { /* keep the approximate outline */ });
        })();

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

<?php page_end(); ?>
