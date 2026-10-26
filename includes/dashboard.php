<?php
/**
 * includes/dashboard.php
 * -----------------------------------------------------------------------
 * Live dashboard for both portals (MDRRMO Officer + Social Worker).
 *
 *   dash_build($barangay, $requestedEventId)  -> collects every metric from the database
 *   dash_render($ctx, $isMd)                  -> prints the dashboard HTML
 *
 * $barangay = null  -> municipality-wide totals (MDRRMO Officer)
 * $barangay = '...' -> only that barangay      (Social Worker)
 *
 * Event-scoped numbers (casualties, evacuees, evacuation-center status) follow the
 * selected disaster event. Households, damage assessments, relief goods and vehicle
 * requests are not linked to an event in the database, so they are cumulative.
 *
 * Every query is wrapped so that one missing table/column never breaks the page.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/md.php';
require_once __DIR__ . '/relief.php';
require_once __DIR__ . '/vehicle.php';

// Iloilo City map pins (same coordinates as the Evacuation Center Monitoring page).
const DASH_SPOTS = [
    'Brgy. Jaro'       => [10.7202, 122.5621],
    'Brgy. Molo'       => [10.6969, 122.5436],
    'Brgy. Mandurriao' => [10.7116, 122.5395],
    'Brgy. La Paz'     => [10.7160, 122.5490],
    'Brgy. Arevalo'    => [10.6830, 122.5240],
];

// ── Small helpers ───────────────────────────────────────────────────────────
function dash_rows(string $sql, array $params = []): array
{
    try {
        return db_query($sql, $params)->fetchAll();
    } catch (Throwable $e) {
        error_log('dashboard query failed: ' . $e->getMessage());
        return [];
    }
}

function dash_row(string $sql, array $params = []): array
{
    $rows = dash_rows($sql, $params);
    return $rows[0] ?? [];
}

function dash_ensure(): void
{
    foreach ([
        'db_ensure_disaster_event_status',
        'db_ensure_casualties_table',
        'db_ensure_damage_assessments_table',
        'db_ensure_evac_center_status_table',
        'rg_ensure_tables',
        'vr_ensure_table',
    ] as $fn) {
        try { $fn(); } catch (Throwable $e) { error_log('dashboard ensure ' . $fn . ': ' . $e->getMessage()); }
    }
}

function dash_n($v): string
{
    return number_format((int) $v);
}

function dash_ago(?string $ts): string
{
    if (!$ts) return '—';
    try { $d = new DateTimeImmutable($ts); } catch (Throwable $e) { return md_h($ts); }
    $s = max(0, time() - $d->getTimestamp());
    if ($s < 60)     return 'Just now';
    if ($s < 3600)   return (int) floor($s / 60) . ' min ago';
    if ($s < 86400)  return (int) floor($s / 3600) . ' hr ago';
    if ($s < 172800) return 'Yesterday';
    return $d->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y');
}

function dash_blank_barangay(): array
{
    return [
        'households' => 0, 'evac_people' => 0, 'evac_hh' => 0,
        'casualties' => 0, 'fatal' => 0, 'missing' => 0, 'injured' => 0,
        'damage' => 0, 'relief_qty' => 0, 'veh_pending' => 0, 'veh_total' => 0,
        'status' => null,
    ];
}

// ── Data collection ─────────────────────────────────────────────────────────
function dash_pick_event(array $events, int $requested): ?array
{
    foreach ($events as $e) if ((int) $e['event_id'] === $requested) return $e;
    foreach ($events as $e) if (($e['status'] ?? '') === 'Active')   return $e;
    return $events[0] ?? null;
}

function dash_metrics(?string $b, ?array $event): array
{
    $bw  = $b ? ' AND barangay = ?' : '';
    $bp  = $b ? [$b] : [];
    $eid = $event ? (int) $event['event_id'] : 0;
    $per = [];
    $touch = static function (array &$per, string $name): void {
        if (!isset($per[$name])) $per[$name] = dash_blank_barangay();
    };

    $m = [
        'households' => 0, 'members' => 0, 'pwd' => 0, 'seniors' => 0, 'fourps' => 0,
        'evac_people' => 0, 'evac_hh' => 0,
        'cas'  => ['Fatality' => 0, 'Missing' => 0, 'Injured' => 0, 'total' => 0],
        'sev'  => ['Critical' => 0, 'Moderate' => 0, 'Minor' => 0],
        'dmg'  => ['Fully Damaged' => 0, 'Partially Damaged' => 0, 'Minor Damage' => 0, 'total' => 0],
        'dmg_assets' => [], 'dmg_review' => 0, 'dmg_stale' => 0,
        'relief' => ['items' => [], 'qty' => 0, 'completed' => 0, 'open' => 0, 'by_status' => ['Pending' => 0, 'In Transit' => 0, 'Completed' => 0]],
        'veh'  => ['status' => array_fill_keys(VR_STATUSES, 0), 'purpose' => [], 'pending' => 0, 'high_pending' => 0, 'stale' => 0, 'avg_hours' => null, 'total' => 0],
    ];
    foreach (RG_ITEMS as $item => $unit) $m['relief']['items'][$item] = 0;

    // Households + vulnerable groups
    $r = dash_row(
        "SELECT COUNT(*) AS n,
                COALESCE(SUM(total_family_members),0) AS members,
                COALESCE(SUM(pwd_count),0) AS pwd,
                COALESCE(SUM(senior_citizens),0) AS seniors,
                COUNT(*) FILTER (WHERE is_4ps_member = 'Yes') AS fourps
         FROM household WHERE 1=1{$bw}", $bp);
    $m['households'] = (int) ($r['n'] ?? 0);
    $m['members']    = (int) ($r['members'] ?? 0);
    $m['pwd']        = (int) ($r['pwd'] ?? 0);
    $m['seniors']    = (int) ($r['seniors'] ?? 0);
    $m['fourps']     = (int) ($r['fourps'] ?? 0);
    foreach (dash_rows("SELECT barangay, COUNT(*) AS n FROM household WHERE 1=1{$bw} GROUP BY barangay", $bp) as $x) {
        $touch($per, $x['barangay']); $per[$x['barangay']]['households'] = (int) $x['n'];
    }

    if ($eid) {
        // Evacuees (people = household_no, matching the Evacuation Center pages)
        foreach (dash_rows(
            "SELECT barangay, COUNT(*) AS hh, COALESCE(SUM(household_no),0) AS people
             FROM evacuation_evacuees WHERE event_id = ?{$bw} GROUP BY barangay", array_merge([$eid], $bp)) as $x) {
            $touch($per, $x['barangay']);
            $per[$x['barangay']]['evac_hh']     = (int) $x['hh'];
            $per[$x['barangay']]['evac_people'] = (int) $x['people'];
            $m['evac_hh']     += (int) $x['hh'];
            $m['evac_people'] += (int) $x['people'];
        }
        // Evacuation-center availability chosen by Social Workers
        foreach (dash_rows("SELECT barangay, status FROM evacuation_center_status WHERE event_id = ?{$bw}", array_merge([$eid], $bp)) as $x) {
            $touch($per, $x['barangay']); $per[$x['barangay']]['status'] = $x['status'];
        }
        // Casualties
        foreach (dash_rows(
            "SELECT barangay, type, severity, COUNT(*) AS n
             FROM casualties WHERE event_id = ?{$bw} GROUP BY barangay, type, severity", array_merge([$eid], $bp)) as $x) {
            $n = (int) $x['n'];
            $touch($per, $x['barangay']);
            $per[$x['barangay']]['casualties'] += $n;
            $key = ['Fatality' => 'fatal', 'Missing' => 'missing', 'Injured' => 'injured'][$x['type']] ?? null;
            if ($key) $per[$x['barangay']][$key] += $n;
            if (isset($m['cas'][$x['type']])) $m['cas'][$x['type']] += $n;
            if (isset($m['sev'][$x['severity']])) $m['sev'][$x['severity']] += $n;
            $m['cas']['total'] += $n;
        }
    }

    // Damage assessments (not event-linked)
    foreach (dash_rows(
        "SELECT barangay, damage_status, asset_type, status, COUNT(*) AS n,
                COUNT(*) FILTER (WHERE status = 'Under Review' AND created_at < NOW() - INTERVAL '3 days') AS stale
         FROM damage_assessments WHERE 1=1{$bw} GROUP BY barangay, damage_status, asset_type, status", $bp) as $x) {
        $n = (int) $x['n'];
        $touch($per, $x['barangay']); $per[$x['barangay']]['damage'] += $n;
        if (isset($m['dmg'][$x['damage_status']])) $m['dmg'][$x['damage_status']] += $n;
        $m['dmg']['total'] += $n;
        $m['dmg_assets'][$x['asset_type']] = ($m['dmg_assets'][$x['asset_type']] ?? 0) + $n;
        if ($x['status'] === 'Under Review') $m['dmg_review'] += $n;
        $m['dmg_stale'] += (int) $x['stale'];
    }

    // Relief goods (not event-linked)
    foreach (dash_rows(
        "SELECT barangay, item, status, COALESCE(SUM(quantity),0)::bigint AS q, COUNT(*) AS n
         FROM relief_distributions WHERE 1=1{$bw} GROUP BY barangay, item, status", $bp) as $x) {
        $q = (int) $x['q']; $n = (int) $x['n'];
        $touch($per, $x['barangay']); $per[$x['barangay']]['relief_qty'] += $q;
        $m['relief']['items'][$x['item']] = ($m['relief']['items'][$x['item']] ?? 0) + $q;
        $m['relief']['qty'] += $q;
        if (isset($m['relief']['by_status'][$x['status']])) $m['relief']['by_status'][$x['status']] += $n;
        if ($x['status'] === 'Completed') $m['relief']['completed'] += $n; else $m['relief']['open'] += $n;
    }

    // Vehicle requests (not event-linked)
    foreach (dash_rows(
        "SELECT barangay, purpose, urgency, status, COUNT(*) AS n
         FROM vehicle_requests WHERE 1=1{$bw} GROUP BY barangay, purpose, urgency, status", $bp) as $x) {
        $n = (int) $x['n'];
        $touch($per, $x['barangay']);
        $per[$x['barangay']]['veh_total'] += $n;
        $m['veh']['total'] += $n;
        $m['veh']['status'][$x['status']] = ($m['veh']['status'][$x['status']] ?? 0) + $n;
        $m['veh']['purpose'][$x['purpose']] = ($m['veh']['purpose'][$x['purpose']] ?? 0) + $n;
        if ($x['status'] === 'Pending') {
            $per[$x['barangay']]['veh_pending'] += $n;
            $m['veh']['pending'] += $n;
            if ($x['urgency'] === 'High') $m['veh']['high_pending'] += $n;
        }
    }
    $r = dash_row(
        "SELECT COUNT(*) FILTER (WHERE status = 'Pending' AND created_at < NOW() - INTERVAL '24 hours') AS stale,
                AVG(EXTRACT(EPOCH FROM (reviewed_at - created_at))) / 3600 AS avg_hours
         FROM vehicle_requests WHERE 1=1{$bw}", $bp);
    $m['veh']['stale']     = (int) ($r['stale'] ?? 0);
    $m['veh']['avg_hours'] = isset($r['avg_hours']) && $r['avg_hours'] !== null ? (float) $r['avg_hours'] : null;

    // Fallback centre status when no Social Worker has set one yet
    foreach ($per as $name => $row) {
        if ($per[$name]['status'] === null) $per[$name]['status'] = $row['evac_people'] > 0 ? 'Occupied' : 'Available';
    }

    $m['per'] = $per;
    return $m;
}

function dash_activity(?string $b, ?int $eid): array
{
    $bw = $b ? ' AND barangay = ?' : '';
    $bp = $b ? [$b] : [];
    $out = [];
    $add = static function (array $rows, string $kind, string $tone) use (&$out): void {
        foreach ($rows as $r) $out[] = ['kind' => $kind, 'tone' => $tone, 'text' => $r['label'], 'barangay' => $r['barangay'], 'at' => $r['at']];
    };
    if ($eid) {
        $add(dash_rows("SELECT type || ' (' || severity || ')' AS label, barangay, created_at AS at FROM casualties WHERE event_id = ?{$bw} ORDER BY created_at DESC LIMIT 6", array_merge([$eid], $bp)), 'Casualty', 'red');
        $add(dash_rows("SELECT 'Evacuee added: ' || name AS label, barangay, created_at AS at FROM evacuation_evacuees WHERE event_id = ?{$bw} ORDER BY created_at DESC LIMIT 6", array_merge([$eid], $bp)), 'Evacuee', 'blue');
    }
    $add(dash_rows("SELECT asset_type || ' · ' || damage_status AS label, barangay, created_at AS at FROM damage_assessments WHERE 1=1{$bw} ORDER BY created_at DESC LIMIT 6", $bp), 'Damage', 'amber');
    $add(dash_rows("SELECT COALESCE(request_code, 'Request') || ' · ' || purpose || ' (' || status || ')' AS label, barangay, created_at AS at FROM vehicle_requests WHERE 1=1{$bw} ORDER BY created_at DESC LIMIT 6", $bp), 'Vehicle', 'purple');
    $add(dash_rows("SELECT quantity::text || ' ' || item || ' (' || status || ')' AS label, barangay, created_at AS at FROM relief_distributions WHERE 1=1{$bw} ORDER BY created_at DESC LIMIT 6", $bp), 'Relief', 'green');

    usort($out, static fn($a, $c) => strcmp((string) $c['at'], (string) $a['at']));
    return array_slice($out, 0, 8);
}

function dash_map_data(?string $b, array $m): array
{
    $centers = [];
    foreach (DASH_SPOTS as $name => $spot) {
        if ($b && $b !== $name) continue;
        $row = $m['per'][$name] ?? dash_blank_barangay();
        $centers[] = [
            'name' => md_short_barangay($name) . ' Evacuation Center', 'barangay' => $name,
            'lat' => $spot[0], 'lng' => $spot[1],
            'status' => $row['status'] ?? 'Available', 'people' => $row['evac_people'], 'households' => $row['evac_hh'],
        ];
    }
    $bw = $b ? ' AND barangay = ?' : '';
    $damage = [];
    foreach (dash_rows(
        "SELECT latitude, longitude, damage_status, asset_type, barangay, reference_code
         FROM damage_assessments WHERE latitude IS NOT NULL AND longitude IS NOT NULL{$bw}
         ORDER BY created_at DESC LIMIT 300", $b ? [$b] : []) as $x) {
        $damage[] = [
            'lat' => (float) $x['latitude'], 'lng' => (float) $x['longitude'],
            'level' => $x['damage_status'], 'asset' => $x['asset_type'], 'barangay' => $x['barangay'], 'ref' => $x['reference_code'],
        ];
    }
    return ['centers' => $centers, 'damage' => $damage];
}

/** Computed alerts (replaces the old hard-coded "Recent Alerts"). */
function dash_alerts(array $m, ?array $event, bool $isMd): array
{
    $eid  = $event ? (int) $event['event_id'] : 0;
    $q    = $eid ? '?event_id=' . $eid : '';
    $out  = [];
    $per  = $m['per'];

    $full = [];
    foreach ($per as $name => $row) if (($row['status'] ?? '') === 'Full') $full[] = md_short_barangay($name);
    if ($full) $out[] = ['danger', count($full) . ' evacuation center' . (count($full) > 1 ? 's' : '') . ' at full capacity', implode(', ', $full) . ' cannot take more evacuees. Consider redistributing.', 'evacuation-centers.php' . $q];

    if (($m['sev']['Critical'] ?? 0) > 0) $out[] = ['danger', dash_n($m['sev']['Critical']) . ' critical casualt' . ($m['sev']['Critical'] > 1 ? 'ies' : 'y'), 'Recorded for the selected event. Make sure medical response is in place.', 'casualties.php' . $q];

    $noRelief = [];
    foreach ($per as $name => $row) if ($row['evac_people'] > 0 && $row['relief_qty'] === 0) $noRelief[] = md_short_barangay($name);
    if ($noRelief) $out[] = ['warning', 'Evacuees with no relief recorded', implode(', ', $noRelief) . ': no relief distribution has been logged yet.', $isMd ? 'relief-goods.php' : ''];

    if ($m['veh']['high_pending'] > 0) $out[] = ['danger', dash_n($m['veh']['high_pending']) . ' high-urgency vehicle request' . ($m['veh']['high_pending'] > 1 ? 's' : '') . ' pending', 'Waiting for approval.', 'vehicle-requests.php'];
    if ($m['veh']['stale'] > 0)        $out[] = ['warning', dash_n($m['veh']['stale']) . ' vehicle request' . ($m['veh']['stale'] > 1 ? 's' : '') . ' pending over 24 hours', 'These requests have not been reviewed yet.', 'vehicle-requests.php'];
    if ($m['dmg_stale'] > 0)           $out[] = ['warning', dash_n($m['dmg_stale']) . ' damage report' . ($m['dmg_stale'] > 1 ? 's' : '') . ' under review for over 3 days', 'Verify or reject them to keep damage figures accurate.', 'damage-assessment.php' . $q];
    if ($m['relief']['open'] > 0)      $out[] = ['info', dash_n($m['relief']['open']) . ' relief distribution' . ($m['relief']['open'] > 1 ? 's' : '') . ' not yet completed', 'Pending or still in transit.', $isMd ? 'relief-goods.php' : ''];

    if (!$out) $out[] = ['success', 'All clear', 'Nothing needs attention right now.', ''];
    return $out;
}

/** Everything the dashboard needs, in one call. */
function dash_build(?string $barangay, int $requestedEvent = 0): array
{
    dash_ensure();
    $events = dash_rows("SELECT event_id, event_name, type, date, status FROM disaster_events ORDER BY event_id DESC LIMIT 30");
    $event  = dash_pick_event($events, $requestedEvent);
    $m      = dash_metrics($barangay, $event);
    return [
        'barangay' => $barangay,
        'events'   => $events,
        'event'    => $event,
        'm'        => $m,
        'alerts'   => dash_alerts($m, $event, $barangay === null),
        'activity' => dash_activity($barangay, $event ? (int) $event['event_id'] : null),
        'map'      => dash_map_data($barangay, $m),
    ];
}

// ── Rendering helpers ───────────────────────────────────────────────────────
function dash_tone_var(string $tone): string
{
    $ok = ['blue', 'green', 'amber', 'red', 'purple', 'gray'];
    return 'var(--sig-' . (in_array($tone, $ok, true) ? $tone : 'blue') . ')';
}

/** Simple horizontal bars. $rows = [[label, value, tone, suffix?], ...] */
function dash_bars(array $rows, string $empty = 'No records yet.'): void
{
    $max = 0;
    foreach ($rows as $r) $max = max($max, (int) $r[1]);
    if ($max === 0) { echo '<div class="dash-empty">' . md_h($empty) . '</div>'; return; }
    echo '<div class="dash-bars">';
    foreach ($rows as $r) {
        $pct = (int) $r[1] > 0 ? max(3, round(((int) $r[1] / $max) * 100)) : 0;
        echo '<div class="dash-bar-row"><span class="dash-bar-label">' . md_h($r[0]) . '</span>'
           . '<span class="dash-bar-track"><i class="dash-bar-fill" style="width:' . $pct . '%;--tone:' . dash_tone_var($r[2] ?? 'blue') . '"></i></span>'
           . '<b class="dash-bar-val">' . dash_n($r[1]) . md_h($r[3] ?? '') . '</b></div>';
    }
    echo '</div>';
}

function dash_render(array $ctx, bool $isMd): void
{
    $m       = $ctx['m'];
    $event   = $ctx['event'];
    $events  = $ctx['events'];
    $b       = $ctx['barangay'];
    $eid     = $event ? (int) $event['event_id'] : 0;
    $q       = $eid ? '?event_id=' . $eid : '';
    $scope   = $b ? md_short_barangay($b) . ' only' : 'Municipality-wide';
    $per     = $m['per'];

    $kpis = [
        ['Registered Households', dash_n($m['households']), 'blue',   dash_n($m['members']) . ' family members',                                                   'households.php'],
        ['Evacuees',              dash_n($m['evac_people']), 'green', dash_n($m['evac_hh']) . ' households in centers',                                           $eid ? 'evacuation-centers.php' . $q : ''],
        ['Casualties',            dash_n($m['cas']['total']), 'red',  dash_n($m['cas']['Fatality']) . ' fatal · ' . dash_n($m['cas']['Missing']) . ' missing · ' . dash_n($m['cas']['Injured']) . ' injured', $eid ? 'casualties.php' . $q : ''],
        ['Damaged Properties',    dash_n($m['dmg']['total']), 'amber', dash_n($m['dmg']['Fully Damaged']) . ' fully · ' . dash_n($m['dmg']['Partially Damaged']) . ' partially',                        $eid ? 'damage-assessment.php' . $q : 'damage-assessment.php'],
        ['Pending Vehicle Requests', dash_n($m['veh']['pending']), 'purple', dash_n($m['veh']['high_pending']) . ' high urgency',                                      'vehicle-requests.php'],
        ['Relief Goods Distributed', dash_n($m['relief']['qty']), 'blue', dash_n($m['relief']['completed']) . ' completed · ' . dash_n($m['relief']['open']) . ' open',  $isMd ? 'relief-goods.php' : ''],
    ];
    ?>
<div class="dash">

    <!-- Event + scope -->
    <div class="card dash-head">
        <div class="dash-head-main">
            <label class="dash-eyebrow" for="dashEvent">Disaster event</label>
            <?php if ($events): ?>
                <form method="get" class="dash-event-form">
                    <select id="dashEvent" name="event_id" class="dash-select" onchange="this.form.submit()">
                        <?php foreach ($events as $e): ?>
                            <option value="<?= (int) $e['event_id'] ?>" <?= (int) $e['event_id'] === $eid ? 'selected' : '' ?>>
                                <?= md_h($e['event_name']) ?> · <?= md_h($e['type']) ?> (<?= md_h($e['status'] ?? 'Active') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button class="btn btn-light" type="submit">Apply</button></noscript>
                </form>
                <?php if ($event): ?>
                    <?= status_badge($event['status'] ?? 'Active') ?>
                    <span class="dash-meta"><?= md_h($event['type']) ?> · <?= md_h(md_date($event['date'])) ?></span>
                <?php endif; ?>
            <?php else: ?>
                <span class="dash-meta">No disaster event has been logged yet.</span>
            <?php endif; ?>
        </div>
        <div class="dash-head-side">
            <span class="dash-chip"><?= md_h($scope) ?></span>
            <?php if ($b && isset($per[$b]['status'])): ?>
                <span class="dash-chip">Evacuation center: <?= status_badge($per[$b]['status']) ?></span>
            <?php endif; ?>
            <span class="dash-meta">Updated <?= md_h((new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('M j, g:i A')) ?></span>
        </div>
    </div>

    <!-- KPI cards -->
    <div class="dash-kpis">
        <?php foreach ($kpis as $k): $tag = $k[4] !== '' ? 'a' : 'div'; ?>
            <<?= $tag ?> class="card dash-kpi" style="--tone:<?= dash_tone_var($k[2]) ?>" <?= $k[4] !== '' ? 'href="' . md_h($k[4]) . '"' : '' ?>>
                <span class="dash-kpi-label"><?= md_h($k[0]) ?></span>
                <span class="dash-kpi-value"><?= md_h($k[1]) ?></span>
                <span class="dash-kpi-sub"><?= md_h($k[3]) ?></span>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </div>

    <!-- Vulnerable groups -->
    <div class="card dash-card">
        <div class="dash-card-head">
            <h2>Vulnerable Groups</h2>
            <span class="mini">From registered households · prioritise these families during response</span>
        </div>
        <div class="dash-strip">
            <?php foreach ([['PWD', $m['pwd']], ['Senior Citizens', $m['seniors']], ["4Ps Households", $m['fourps']], ['Family Members', $m['members']]] as $v): ?>
                <div class="dash-strip-item"><strong><?= dash_n($v[1]) ?></strong><span><?= md_h($v[0]) ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Casualties + needs attention -->
    <div class="dash-row r-2">
        <div class="card dash-card">
            <div class="dash-card-head">
                <h2><?= $isMd ? 'Casualties by Barangay' : 'Casualty Breakdown' ?></h2>
                <?php if ($event): ?><span class="mini">Event: <?= md_h($event['event_name']) ?></span><?php endif; ?>
            </div>
            <?php if (!$event): ?>
                <div class="dash-empty">No disaster event selected.</div>
            <?php elseif ($isMd): ?>
                <?php
                $maxCas = 0;
                foreach ($per as $row) $maxCas = max($maxCas, $row['casualties']);
                if ($maxCas === 0): ?>
                    <div class="dash-empty">No casualties recorded for this event.</div>
                <?php else: ?>
                    <div class="dash-legend"><span><i style="background:var(--sig-red)"></i>Fatality</span><span><i style="background:var(--sig-amber)"></i>Missing</span><span><i style="background:var(--sig-blue)"></i>Injured</span></div>
                    <div class="dash-bars">
                        <?php foreach (md_barangay_names(array_keys($per)) as $name): $row = $per[$name] ?? dash_blank_barangay(); ?>
                            <div class="dash-bar-row">
                                <span class="dash-bar-label"><?= md_h(md_short_barangay($name)) ?></span>
                                <span class="dash-bar-track">
                                    <?php foreach ([['fatal', 'red'], ['missing', 'amber'], ['injured', 'blue']] as $seg): $w = $row[$seg[0]] > 0 ? round(($row[$seg[0]] / $maxCas) * 100, 1) : 0; if ($w > 0): ?>
                                        <i class="dash-bar-fill" style="width:<?= $w ?>%;--tone:<?= dash_tone_var($seg[1]) ?>"></i>
                                    <?php endif; endforeach; ?>
                                </span>
                                <b class="dash-bar-val"><?= dash_n($row['casualties']) ?></b>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php dash_bars([
                    ['Fatality', $m['cas']['Fatality'], 'red'], ['Missing', $m['cas']['Missing'], 'amber'], ['Injured', $m['cas']['Injured'], 'blue'],
                    ['Critical', $m['sev']['Critical'], 'red'], ['Moderate', $m['sev']['Moderate'], 'amber'], ['Minor', $m['sev']['Minor'], 'green'],
                ], 'No casualties recorded for this event.'); ?>
            <?php endif; ?>
        </div>

        <div class="card dash-card">
            <div class="dash-card-head"><h2>Needs Attention</h2><span class="mini"><?= count($ctx['alerts']) ?> item<?= count($ctx['alerts']) === 1 ? '' : 's' ?></span></div>
            <div class="dash-alerts">
                <?php foreach ($ctx['alerts'] as $a): $tag = $a[3] !== '' ? 'a' : 'div'; ?>
                    <<?= $tag ?> class="dash-alert is-<?= md_h($a[0]) ?>" <?= $a[3] !== '' ? 'href="' . md_h($a[3]) . '"' : '' ?>>
                        <b><?= md_h($a[1]) ?></b>
                        <span><?= md_h($a[2]) ?></span>
                    </<?= $tag ?>>
                <?php endforeach; ?>
            </div>
            <div class="actions mt">
                <?php if ($isMd): ?>
                    <a class="btn btn-primary" href="households.php">Households</a>
                    <a class="btn btn-light" href="disasters.php">Disaster Events</a>
                    <a class="btn btn-light" href="relief-goods.php">Relief Goods</a>
                <?php else: ?>
                    <a class="btn btn-primary" href="households.php">Add Household</a>
                    <a class="btn btn-light" href="evacuation-centers.php<?= md_h($q) ?>">Evacuees</a>
                    <a class="btn btn-light" href="vehicle-requests.php">Request Vehicle</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Map + recent activity -->
    <div class="dash-row r-2-1">
        <div class="card dash-card">
            <div class="dash-card-head">
                <h2>Evacuation Centers &amp; Damage Map</h2>
                <span class="mini"><?= count($ctx['map']['damage']) ?> geotagged damage report<?= count($ctx['map']['damage']) === 1 ? '' : 's' ?></span>
            </div>
            <div id="dashMap" class="dash-map" role="img" aria-label="Map of evacuation centers and damage reports"></div>
            <div class="dash-legend dash-legend-map">
                <span><i style="background:#067647"></i>Center available</span>
                <span><i style="background:#B54708"></i>Occupied / near capacity</span>
                <span><i style="background:#B42318"></i>Center full</span>
                <span><i class="sq" style="background:#B42318"></i>Fully damaged</span>
                <span><i class="sq" style="background:#B54708"></i>Partially</span>
                <span><i class="sq" style="background:#1D5FA8"></i>Minor</span>
            </div>
        </div>

        <div class="card dash-card">
            <div class="dash-card-head"><h2>Recent Activity</h2></div>
            <?php if (!$ctx['activity']): ?>
                <div class="dash-empty">No activity recorded yet.</div>
            <?php else: ?>
                <ul class="dash-activity">
                    <?php foreach ($ctx['activity'] as $a): ?>
                        <li style="--tone:<?= dash_tone_var($a['tone']) ?>">
                            <span class="dash-act-kind"><?= md_h($a['kind']) ?></span>
                            <span class="dash-act-text"><?= md_h($a['text']) ?></span>
                            <span class="dash-act-meta"><?= md_h(md_short_barangay((string) $a['barangay'])) ?> · <?= md_h(dash_ago($a['at'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Damage / Relief / Vehicles -->
    <div class="dash-row r-3">
        <div class="card dash-card">
            <div class="dash-card-head"><h2>Damage Assessments</h2><span class="mini"><?= dash_n($m['dmg_review']) ?> under review</span></div>
            <?php dash_bars([
                ['Fully damaged', $m['dmg']['Fully Damaged'], 'red'],
                ['Partially damaged', $m['dmg']['Partially Damaged'], 'amber'],
                ['Minor damage', $m['dmg']['Minor Damage'], 'blue'],
            ], 'No damage reports yet.'); ?>
            <?php if ($m['dmg_assets']): ?>
                <div class="dash-sub">By asset type</div>
                <?php $rows = []; foreach ($m['dmg_assets'] as $k => $v) $rows[] = [$k, $v, 'gray']; dash_bars($rows); ?>
            <?php endif; ?>
        </div>

        <div class="card dash-card">
            <div class="dash-card-head"><h2>Relief Goods</h2><span class="mini"><?= dash_n($m['relief']['qty']) ?> total</span></div>
            <?php $rows = []; foreach (RG_ITEMS as $item => $unit) $rows[] = [$item, $m['relief']['items'][$item] ?? 0, 'green', ' ' . $unit]; dash_bars($rows, 'No relief distributions yet.'); ?>
            <div class="dash-sub">Distribution status</div>
            <?php dash_bars([
                ['Pending', $m['relief']['by_status']['Pending'], 'amber'],
                ['In transit', $m['relief']['by_status']['In Transit'], 'blue'],
                ['Completed', $m['relief']['by_status']['Completed'], 'green'],
            ]); ?>
        </div>

        <div class="card dash-card">
            <div class="dash-card-head">
                <h2>Vehicle Requests</h2>
                <span class="mini"><?= $m['veh']['avg_hours'] !== null ? 'Avg. review ' . md_h(number_format($m['veh']['avg_hours'], 1)) . ' hr' : dash_n($m['veh']['total']) . ' total' ?></span>
            </div>
            <?php dash_bars([
                ['Pending', $m['veh']['status']['Pending'] ?? 0, 'amber'],
                ['Approved', $m['veh']['status']['Approved'] ?? 0, 'green'],
                ['Scheduled', $m['veh']['status']['Scheduled'] ?? 0, 'blue'],
                ['Completed', $m['veh']['status']['Completed'] ?? 0, 'gray'],
                ['Rejected', $m['veh']['status']['Rejected'] ?? 0, 'red'],
            ], 'No vehicle requests yet.'); ?>
            <?php if ($m['veh']['purpose']): ?>
                <div class="dash-sub">By purpose</div>
                <?php $rows = []; foreach ($m['veh']['purpose'] as $k => $v) $rows[] = [$k, $v, 'purple']; dash_bars($rows); ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isMd): ?>
    <!-- Barangay overview -->
    <div class="card dash-card">
        <div class="dash-card-head"><h2>Barangay Overview</h2><span class="mini">Event figures follow the selected event</span></div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Barangay</th><th>Households</th><th>Evacuees</th><th>Casualties</th>
                        <th>Damage Reports</th><th>Relief Qty</th><th>Pending Vehicles</th><th>Evacuation Center</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (md_barangay_names(array_keys($per)) as $name): $row = $per[$name] ?? dash_blank_barangay(); if ($row['status'] === null) $row['status'] = 'Available'; ?>
                        <tr>
                            <td><strong><?= md_h($name) ?></strong></td>
                            <td><?= dash_n($row['households']) ?></td>
                            <td><?= dash_n($row['evac_people']) ?></td>
                            <td><?= dash_n($row['casualties']) ?></td>
                            <td><?= dash_n($row['damage']) ?></td>
                            <td><?= dash_n($row['relief_qty']) ?></td>
                            <td><?= dash_n($row['veh_pending']) ?></td>
                            <td><?= status_badge($row['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
(function () {
    var data = <?= json_encode($ctx['map'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    var el = document.getElementById('dashMap');
    if (!el) return;
    if (typeof L === 'undefined') { el.innerHTML = '<div class="dash-empty">The map could not be loaded (no internet connection).</div>'; return; }

    var map = L.map(el, { scrollWheelZoom: false }).setView([10.705, 122.545], 13);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var statusColor = { 'Available': '#067647', 'Occupied': '#B54708', 'Near Capacity': '#B54708', 'Full': '#B42318' };
    var levelColor  = { 'Fully Damaged': '#B42318', 'Partially Damaged': '#B54708', 'Minor Damage': '#1D5FA8' };
    var pts = [];

    function popup(title, lines) {
        var box = document.createElement('div');
        var h = document.createElement('strong'); h.textContent = title; box.appendChild(h);
        lines.forEach(function (t) { var d = document.createElement('div'); d.textContent = t; box.appendChild(d); });
        return box;
    }

    data.damage.forEach(function (d) {
        L.circleMarker([d.lat, d.lng], { radius: 5, weight: 1, color: '#fff', fillColor: levelColor[d.level] || '#475467', fillOpacity: .9 })
            .addTo(map).bindPopup(popup(d.asset + ' · ' + d.level, [d.barangay, d.ref]));
        pts.push([d.lat, d.lng]);
    });
    data.centers.forEach(function (c) {
        L.circleMarker([c.lat, c.lng], { radius: 11, weight: 3, color: '#fff', fillColor: statusColor[c.status] || '#067647', fillOpacity: 1 })
            .addTo(map).bindPopup(popup(c.name, ['Status: ' + c.status, c.people + ' evacuees · ' + c.households + ' households']));
        pts.push([c.lat, c.lng]);
    });
    if (pts.length > 1) map.fitBounds(pts, { padding: [30, 30], maxZoom: 15 });
    else if (pts.length === 1) map.setView(pts[0], 15);
    setTimeout(function () { map.invalidateSize(); }, 250);
    window.addEventListener('resize', function () { map.invalidateSize(); });
})();
</script>
    <?php
}
