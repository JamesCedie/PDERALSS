<?php
/**
 * includes/dashboard.php
 * Live dashboard for both portals (MDRRMO Officer + Social Worker).
 *
 *   dash_build($barangay, $requestedEventId)  -> collects every metric from the database
 *   dash_render($ctx, $isMd)                  -> prints the dashboard HTML
 *
 * $barangay = null  -> municipality-wide totals (MDRRMO Officer)
 * $barangay = '...' -> only that barangay      (Social Worker)
 *
 * Event-scoped numbers (evacuees, casualties, damage assessments, evacuation-center status)
 * follow the selected disaster event. Households and vehicle requests are not linked to an
 * event in the database, so they are cumulative.
 *
 * Every query is wrapped so that one missing table/column never breaks the page.
 * All charts are inline SVG (no JavaScript library, works offline).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/md.php';
require_once __DIR__ . '/vehicle.php';

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

/** Column names of a table (empty array when the table does not exist). */
function dash_cols(string $table): array
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = array_column(dash_rows(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?',
            [$table]
        ), 'column_name');
    }
    return $cache[$table];
}

/**
 * Tables are now created by the schema file (pderalss_schema_proposal.sql), not at runtime.
 * Kept as a no-op so existing callers keep working.
 */
function dash_ensure(): void
{
}

/** Evacuation center name + capacity for every barangay: [barangay => [name, capacity]]. */
function dash_centers(array $extraBarangays = []): array
{
    $out = [];
    foreach (dash_rows('SELECT b.name AS barangay, c.center_name, c.capacity
                        FROM public.evacuation_centers c
                        JOIN public.barangays b ON b.barangay_id = c.barangay_id
                        ORDER BY b.name') as $r) {
        $out[$r['barangay']] = [(string) $r['center_name'], (int) $r['capacity']];
    }
    foreach ($extraBarangays as $b) {
        if (!isset($out[$b])) $out[$b] = [md_short_barangay((string) $b) . ' Evacuation Center', 0];
    }
    return $out;
}

function dash_n($v): string
{
    return number_format((int) $v);
}

/** Any DB timestamp -> unix time (null when empty / unreadable). */
function dash_ts($v): ?int
{
    if ($v === null || $v === '') return null;
    $s = preg_replace('/([+-]\d\d)$/', '$1:00', trim((string) $v));
    try { return (new DateTimeImmutable($s))->getTimestamp(); } catch (Throwable $e) { return null; }
}

/** Midnight (Manila) of the day a unix time falls on. */
function dash_day(int $ts): DateTimeImmutable
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Asia/Manila'))->setTime(0, 0);
}

/** "10:25 AM" for today, "Oct 25 · 10:25 AM" for older entries. */
function dash_time(?int $ts): string
{
    if (!$ts) return '—';
    $tz  = new DateTimeZone('Asia/Manila');
    $d   = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
    $now = new DateTimeImmutable('now', $tz);
    return $d->format('Y-m-d') === $now->format('Y-m-d') ? $d->format('g:i A') : $d->format('M j · g:i A');
}

function dash_blank_barangay(): array
{
    return [
        'evac_people' => 0, 'evac_hh' => 0,
        'casualties' => 0, 'fatal' => 0, 'missing' => 0, 'injured' => 0,
        'totally' => 0, 'partially' => 0,
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

/** Cumulative evacuees per day. $rows = [[unix ts, people], ...] */
function dash_timeline(array $rows, ?array $event, int $maxDays = 14): array
{
    $empty = ['labels' => [], 'values' => []];
    $by = []; $first = null; $last = null;
    foreach ($rows as $r) {
        if (!$r[0]) continue;
        $d = dash_day((int) $r[0]);
        $k = $d->format('Y-m-d');
        $by[$k] = ($by[$k] ?? 0) + (int) $r[1];
        if (!$first || $d < $first) $first = $d;
        if (!$last  || $d > $last)  $last  = $d;
    }
    if (!$by) return $empty;

    $tz    = new DateTimeZone('Asia/Manila');
    $start = $first;
    if ($event && !empty($event['date'])) {
        try {
            $ed = new DateTimeImmutable(substr((string) $event['date'], 0, 10), $tz);
            if ($ed < $start) $start = $ed->setTime(0, 0);
        } catch (Throwable $e) { /* keep $first */ }
    }
    $end = $last;
    if (($event['status'] ?? '') === 'Active') {
        $today = new DateTimeImmutable('today', $tz);
        if ($today > $end) $end = $today;
    }
    if ($end->diff($start)->days + 1 > $maxDays) $start = $end->modify('-' . ($maxDays - 1) . ' days');
    if ($start >= $end) $start = $end->modify('-1 day');

    $cum = 0;
    foreach ($by as $k => $v) if ($k < $start->format('Y-m-d')) $cum += $v;

    $labels = []; $values = [];
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        $cum += $by[$d->format('Y-m-d')] ?? 0;
        $labels[] = $d->format('j M');
        $values[] = $cum;
    }
    return ['labels' => $labels, 'values' => $values];
}

function dash_metrics(?string $b, ?array $event): array
{
    // One barangay filter per table shape (all take the same single barangay_id parameter).
    $bid   = $b ? brgy_id($b) : 0;
    $bp    = $b ? [$bid] : [];
    $fEvac = $b ? ' AND c.barangay_id = ?' : '';   // evacuees / center status, via evacuation_centers c
    $fCas  = $b ? ' AND x.barangay_id = ?' : '';   // casualties x
    $fDmg  = $b ? ' AND h.barangay_id = ?' : '';   // damage assessments, via household h
    $fVeh  = $b ? ' AND barangay_id = ?' : '';     // vehicle_requests
    $eid = $event ? (int) $event['event_id'] : 0;
    $per = [];
    $touch = static function (array &$per, string $name): void {
        if (!isset($per[$name])) $per[$name] = dash_blank_barangay();
    };

    $m = [
        'households' => 0, 'members' => 0, 'pwd' => 0, 'seniors' => 0, 'fourps' => 0,
        'children' => 0, 'pregnant' => null,
        'evac_people' => 0, 'evac_hh' => 0,
        'cas' => ['Fatality' => 0, 'Missing' => 0, 'Injured' => 0, 'total' => 0],
        'cas_muni' => 0, 'cas_updated' => null,
        'dmg' => ['total' => 0, 'totally' => 0, 'partially' => 0, 'households' => 0],
        'veh' => ['pending' => 0, 'transit' => 0, 'completed' => 0, 'rejected' => 0],
        'timeline' => ['labels' => [], 'values' => []],
        'reporting' => 0,
    ];
    $reported = [];

    // Households + vulnerable groups (Social Worker view)
    if ($b !== null) {
        $r = dash_row(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(total_family_members),0) AS members,
                    COALESCE(SUM(pwd_count),0) AS pwd,
                    COALESCE(SUM(senior_citizens),0) AS seniors,
                    COUNT(*) FILTER (WHERE is_4ps_member = 'Yes') AS fourps
             FROM household WHERE barangay_id = ?", [$bid]);
        $m['households'] = (int) ($r['n'] ?? 0);
        $m['members']    = (int) ($r['members'] ?? 0);
        $m['pwd']        = (int) ($r['pwd'] ?? 0);
        $m['seniors']    = (int) ($r['seniors'] ?? 0);
        $m['fourps']     = (int) ($r['fourps'] ?? 0);

        $r = dash_row(
            'SELECT COUNT(*) AS n FROM family_composition fc
             JOIN household h ON h.household_id = fc.household_id
             WHERE h.barangay_id = ? AND fc.age IS NOT NULL AND fc.age < 18', [$bid]);
        $m['children'] = (int) ($r['n'] ?? 0);

        // Pregnant / lactating is only counted when the household table has such a column.
        $hc = dash_cols('household');
        $pc = array_values(array_intersect(['pregnant_lactating', 'pregnant_lactating_count', 'pregnant_count', 'lactating_count'], $hc));
        if ($pc) {
            $sum = implode(' + ', array_map(static fn($c) => 'COALESCE(SUM(' . $c . '),0)', $pc));
            $r = dash_row("SELECT ({$sum}) AS n FROM household WHERE barangay_id = ?", [$bid]);
            if (isset($r['n'])) $m['pregnant'] = (int) $r['n'];
        }
    }

    if ($eid) {
        // Evacuees (people = people_count, matching the Evacuation Center pages)
        foreach (dash_rows(
            "SELECT b.name AS barangay, COUNT(DISTINCT COALESCE(x.household_id, -x.evacuee_id)) AS hh, COALESCE(SUM(x.people_count),0) AS people
             FROM evacuation_evacuees x
             JOIN evacuation_centers c ON c.center_id = x.center_id
             JOIN barangays b ON b.barangay_id = c.barangay_id
             WHERE x.event_id = ?{$fEvac} GROUP BY b.name", array_merge([$eid], $bp)) as $x) {
            $touch($per, $x['barangay']);
            $per[$x['barangay']]['evac_hh']     = (int) $x['hh'];
            $per[$x['barangay']]['evac_people'] = (int) $x['people'];
            $m['evac_hh']     += (int) $x['hh'];
            $m['evac_people'] += (int) $x['people'];
            $reported[$x['barangay']] = true;
        }

        // Evacuees over time (bucketed per day in PHP so the DB column type never matters)
        $tl = [];
        foreach (dash_rows(
            "SELECT x.created_at, x.people_count AS p
             FROM evacuation_evacuees x
             JOIN evacuation_centers c ON c.center_id = x.center_id
             WHERE x.event_id = ?{$fEvac}", array_merge([$eid], $bp)) as $x) {
            $tl[] = [dash_ts($x['created_at']), (int) $x['p']];
        }
        $m['timeline'] = dash_timeline($tl, $event);

        // Evacuation-center availability chosen by Social Workers
        foreach (dash_rows("SELECT b.name AS barangay, s.status
                            FROM evacuation_center_status s
                            JOIN evacuation_centers c ON c.center_id = s.center_id
                            JOIN barangays b ON b.barangay_id = c.barangay_id
                            WHERE s.event_id = ?{$fEvac}", array_merge([$eid], $bp)) as $x) {
            $touch($per, $x['barangay']); $per[$x['barangay']]['status'] = $x['status'];
            $reported[$x['barangay']] = true;
        }

        // Casualties
        foreach (dash_rows(
            "SELECT b.name AS barangay, x.type, COUNT(*) AS n, MAX(x.created_at) AS last_at
             FROM casualties x JOIN barangays b ON b.barangay_id = x.barangay_id
             WHERE x.event_id = ?{$fCas} GROUP BY b.name, x.type", array_merge([$eid], $bp)) as $x) {
            $n = (int) $x['n'];
            $touch($per, $x['barangay']);
            $per[$x['barangay']]['casualties'] += $n;
            $key = ['Fatality' => 'fatal', 'Missing' => 'missing', 'Injured' => 'injured'][$x['type']] ?? null;
            if ($key) $per[$x['barangay']][$key] += $n;
            if (isset($m['cas'][$x['type']])) $m['cas'][$x['type']] += $n;
            $m['cas']['total'] += $n;
            $reported[$x['barangay']] = true;
            $t = dash_ts($x['last_at']);
            if ($t && $t > (int) $m['cas_updated']) $m['cas_updated'] = $t;
        }
        // Municipality-wide total, for the Social Worker footnote
        if ($b !== null) {
            $r = dash_row('SELECT COUNT(*) AS n FROM casualties WHERE event_id = ?', [$eid]);
            $m['cas_muni'] = (int) ($r['n'] ?? 0);
        } else {
            $m['cas_muni'] = $m['cas']['total'];
        }
    }

    // Damage assessments of the selected event (none when no event exists). Fully Damaged = "totally"; everything else = "partially".
    $dmgEvent  = $eid ? 'x.event_id = ?' : '1=0';
    $dmgParams = $eid ? array_merge([$eid], $bp) : [];
    foreach (dash_rows(
        "SELECT b.name AS barangay, x.damage_status, COUNT(*) AS n
         FROM damage_assessments x
         JOIN household h ON h.household_id = x.household_id
         JOIN barangays b ON b.barangay_id = h.barangay_id
         WHERE {$dmgEvent}{$fDmg} GROUP BY b.name, x.damage_status", $dmgParams) as $x) {
        $n = (int) $x['n'];
        $touch($per, $x['barangay']);
        $totally = in_array($x['damage_status'], ['Fully Damaged', 'Totally Damaged'], true);
        $per[$x['barangay']][$totally ? 'totally' : 'partially'] += $n;
        $m['dmg'][$totally ? 'totally' : 'partially'] += $n;
        $m['dmg']['total'] += $n;
    }
    $r = dash_row("SELECT COUNT(DISTINCT x.household_id) AS n
                   FROM damage_assessments x JOIN household h ON h.household_id = x.household_id
                   WHERE {$dmgEvent}{$fDmg}", $dmgParams);
    $m['dmg']['households'] = (int) ($r['n'] ?? 0);

    // Vehicle requests (not event-linked). Approved + Scheduled count as "in transit".
    foreach (dash_rows("SELECT status, COUNT(*) AS n FROM vehicle_requests WHERE 1=1{$fVeh} GROUP BY status", $bp) as $x) {
        $n = (int) $x['n'];
        switch ($x['status']) {
            case 'Pending':   $m['veh']['pending']   += $n; break;
            case 'Approved':
            case 'Scheduled': $m['veh']['transit']   += $n; break;
            case 'Completed': $m['veh']['completed'] += $n; break;
            case 'Rejected':  $m['veh']['rejected']  += $n; break;
        }
    }

    $m['reporting'] = count($reported);
    $m['per']       = $per;
    return $m;
}

// ── Activity / records ──────────────────────────────────────────────────────
/** SQL pieces that turn a user-id column into "First Last". */
function dash_user_join(string $alias, string $col, string $u): string
{
    return "LEFT JOIN users {$u} ON {$u}.user_id::text = {$alias}.{$col}::text";
}
function dash_user_name(string $alias, string $col, string $u): string
{
    return "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', {$u}.first_name, {$u}.last_name)), ''), {$alias}.{$col}::text)";
}

/** Municipality activity feed (MD Officer): latest 5 rows across all modules. */
function dash_activity(?int $eid): array
{
    $out = [];
    $push = static function (array $rows, callable $fmt) use (&$out): void {
        foreach ($rows as $r) {
            $ts = dash_ts($r['ts'] ?? null);
            if (!$ts) continue;
            [$text, $status] = $fmt($r);
            $out[] = ['ts' => $ts, 'barangay' => (string) ($r['barangay'] ?? ''), 'text' => $text, 'who' => (string) ($r['who'] ?? ''), 'status' => $status];
        }
    };

    if ($eid) {
        $push(dash_rows(
            'SELECT b.name AS barangay, x.created_at AS ts, x.people_count AS n, ' . dash_user_name('x', 'added_by', 'u1') . ' AS who
             FROM evacuation_evacuees x
             JOIN evacuation_centers c ON c.center_id = x.center_id
             JOIN barangays b ON b.barangay_id = c.barangay_id ' . dash_user_join('x', 'added_by', 'u1') . '
             WHERE x.event_id = ? ORDER BY x.created_at DESC LIMIT 6', [$eid]),
            static fn($r) => [dash_n($r['n']) . ' evacuee' . ((int) $r['n'] === 1 ? '' : 's') . ' registered', 'Recorded']);

        $push(dash_rows(
            'SELECT b.name AS barangay, x.created_at AS ts, x.type, x.severity, ' . dash_user_name('x', 'added_by', 'u1') . ' AS who
             FROM casualties x JOIN barangays b ON b.barangay_id = x.barangay_id ' . dash_user_join('x', 'added_by', 'u1') . '
             WHERE x.event_id = ? ORDER BY x.created_at DESC LIMIT 6', [$eid]),
            static fn($r) => ['Casualty recorded · ' . $r['type'] . ' (' . $r['severity'] . ')', 'Recorded']);

        $push(dash_rows(
            'SELECT b.name AS barangay, x.updated_at AS ts, ' . dash_user_name('x', 'updated_by', 'u1') . ' AS who
             FROM evacuation_center_status x
             JOIN evacuation_centers c ON c.center_id = x.center_id
             JOIN barangays b ON b.barangay_id = c.barangay_id ' . dash_user_join('x', 'updated_by', 'u1') . '
             WHERE x.event_id = ? ORDER BY x.updated_at DESC LIMIT 6', [$eid]),
            static fn($r) => ['Center occupancy updated', 'Updated']);
    }

    $push(dash_rows(
        'SELECT b.name AS barangay, x.created_at AS ts, COALESCE(NULLIF(x.reporter, \'\'), ' . dash_user_name('x', 'submitted_by', 'u1') . ') AS who
         FROM damage_assessments x
         JOIN household h ON h.household_id = x.household_id
         JOIN barangays b ON b.barangay_id = h.barangay_id ' . dash_user_join('x', 'submitted_by', 'u1') . '
         ORDER BY x.created_at DESC LIMIT 6'),
        static fn($r) => ['Damage assessment submitted', 'Submitted']);

    $push(dash_rows(
        'SELECT b.name AS barangay, x.status, COALESCE(x.reviewed_at, x.created_at) AS ts,
                CASE WHEN x.reviewed_by IS NOT NULL THEN ' . dash_user_name('x', 'reviewed_by', 'u2') . ' ELSE ' . dash_user_name('x', 'requested_by', 'u1') . ' END AS who
         FROM vehicle_requests x JOIN barangays b ON b.barangay_id = x.barangay_id ' . dash_user_join('x', 'requested_by', 'u1') . ' ' . dash_user_join('x', 'reviewed_by', 'u2') . '
         ORDER BY COALESCE(x.reviewed_at, x.created_at) DESC LIMIT 6'),
        static function ($r) {
            $verb = ['Pending' => 'submitted', 'Approved' => 'approved', 'Scheduled' => 'scheduled', 'Completed' => 'completed', 'Rejected' => 'rejected'][$r['status']] ?? strtolower((string) $r['status']);
            return ['Vehicle request ' . $verb, (string) $r['status']];
        });

    $hc = dash_cols('household');
    if (in_array('created_at', $hc, true)) {
        $push(dash_rows(
            'SELECT b.name AS barangay, x.created_at AS ts, ' . dash_user_name('x', 'created_by', 'u1') . ' AS who
             FROM household x JOIN barangays b ON b.barangay_id = x.barangay_id ' . dash_user_join('x', 'created_by', 'u1') . '
             ORDER BY x.created_at DESC LIMIT 6'),
            static fn($r) => ['Household record added', 'Recorded']);
    }

    usort($out, static fn($a, $c) => $c['ts'] <=> $a['ts']);
    return array_slice($out, 0, 5);
}

/** Social Worker "Recent Records" (one barangay): latest 5 rows. */
function dash_records(string $b, ?int $eid): array
{
    $hc   = dash_cols('household');
    $zone = 'NULL::text';
    foreach (['zone', 'purok', 'sitio'] as $c) if (in_array($c, $hc, true)) { $zone = 'h.' . $c . '::text'; break; }
    $head = "CONCAT_WS(', ', NULLIF(h.lastname, ''), NULLIF(TRIM(CONCAT_WS(' ', h.firstname, h.middlename)), ''))";

    $bid = brgy_id($b);
    $out = [];
    $push = static function (array $rows, callable $fmt) use (&$out): void {
        foreach ($rows as $r) {
            $ts = dash_ts($r['ts'] ?? null);
            if (!$ts) continue;
            [$headName, $type, $id] = $fmt($r);
            $out[] = ['ts' => $ts, 'head' => $headName !== '' ? $headName : '—', 'type' => $type, 'zone' => (string) ($r['zone'] ?? ''), 'id' => $id];
        }
    };

    $hasCreated = in_array('created_at', $hc, true);
    $hasUpdated = in_array('updated_at', $hc, true);
    if ($hasCreated) {
        $push(dash_rows("SELECT h.household_id, h.created_at AS ts, {$head} AS head, {$zone} AS zone FROM household h WHERE h.barangay_id = ? ORDER BY h.created_at DESC LIMIT 6", [$bid]),
            static fn($r) => [(string) $r['head'], 'Household added', 'HH-' . $r['household_id']]);
    }
    if ($hasUpdated) {
        $cond = $hasCreated ? "AND h.updated_at > h.created_at + INTERVAL '1 minute'" : '';
        $push(dash_rows("SELECT h.household_id, h.updated_at AS ts, {$head} AS head, {$zone} AS zone FROM household h WHERE h.barangay_id = ? {$cond} ORDER BY h.updated_at DESC LIMIT 6", [$bid]),
            static fn($r) => [(string) $r['head'], 'Household updated', 'HH-' . $r['household_id']]);
    }
    if ($eid) {
        $push(dash_rows(
            "SELECT x.name, x.created_at AS ts, {$head} AS head, {$zone} AS zone
             FROM evacuation_evacuees x LEFT JOIN household h ON h.household_id::text = x.household_id::text
             WHERE x.event_id = ? AND x.center_id IN (SELECT center_id FROM evacuation_centers WHERE barangay_id = ?)
             ORDER BY x.created_at DESC LIMIT 6", [$eid, $bid]),
            static fn($r) => [(string) ($r['head'] !== null && $r['head'] !== '' ? $r['head'] : $r['name']), 'Evacuees registered', '']);
    }
    $push(dash_rows(
        "SELECT x.reference_code, x.damage_status, x.reporter, x.created_at AS ts, {$head} AS head, {$zone} AS zone
         FROM damage_assessments x LEFT JOIN household h ON h.household_id::text = x.household_id::text
         WHERE h.barangay_id = ? ORDER BY x.created_at DESC LIMIT 6", [$bid]),
        static function ($r) {
            $type = ['Fully Damaged' => 'Totally damaged', 'Totally Damaged' => 'Totally damaged', 'Partially Damaged' => 'Partially damaged', 'Minor Damage' => 'Minor damage'][$r['damage_status']] ?? (string) $r['damage_status'];
            $name = $r['head'] !== null && $r['head'] !== '' ? $r['head'] : $r['reporter'];
            return [(string) $name, $type, (string) $r['reference_code']];
        });

    usort($out, static fn($a, $c) => $c['ts'] <=> $a['ts']);
    return array_slice($out, 0, 5);
}

/**
 * Computed alerts for the Notifications page (not used by the dashboards, so the extra
 * queries only run when asked for). Returns [[tone, title, body, link], ...].
 */
function dash_alerts(array $ctx): array
{
    $event = $ctx['event'];
    $per   = $ctx['m']['per'];
    $eid   = $event ? (int) $event['event_id'] : 0;
    $q     = $eid ? '?event_id=' . $eid : '';
    $out   = [];

    $full = [];
    foreach ($ctx['names'] as $name) if (dash_center_status($per, $name) === 'Full') $full[] = md_short_barangay($name);
    if ($full) $out[] = ['danger', count($full) . ' evacuation center' . (count($full) > 1 ? 's' : '') . ' at full capacity', implode(', ', $full) . ' cannot take more evacuees. Consider redistributing.', 'evacuation-centers.php' . $q];

    if ($eid) {
        $r = dash_row("SELECT COUNT(*) AS n FROM casualties WHERE event_id = ? AND severity = 'Critical'", [$eid]);
        $n = (int) ($r['n'] ?? 0);
        if ($n > 0) $out[] = ['danger', dash_n($n) . ' critical casualt' . ($n > 1 ? 'ies' : 'y'), 'Recorded for the selected event. Make sure medical response is in place.', 'casualties.php' . $q];
    }

    $r = dash_row("SELECT COUNT(*) FILTER (WHERE urgency = 'High') AS high,
                          COUNT(*) FILTER (WHERE created_at < NOW() - INTERVAL '24 hours') AS stale
                   FROM vehicle_requests WHERE status = 'Pending'");
    if ((int) ($r['high'] ?? 0) > 0)  $out[] = ['danger',  dash_n($r['high']) . ' high-urgency vehicle request' . ((int) $r['high'] > 1 ? 's' : '') . ' pending', 'Waiting for approval.', 'vehicle-requests.php'];
    if ((int) ($r['stale'] ?? 0) > 0) $out[] = ['warning', dash_n($r['stale']) . ' vehicle request' . ((int) $r['stale'] > 1 ? 's' : '') . ' pending over 24 hours', 'These requests have not been reviewed yet.', 'vehicle-requests.php'];

    $r = dash_row("SELECT COUNT(*) AS n FROM relief_distributions WHERE status <> 'Completed'");
    if ((int) ($r['n'] ?? 0) > 0) $out[] = ['info', dash_n($r['n']) . ' relief distribution' . ((int) $r['n'] > 1 ? 's' : '') . ' not yet completed', 'Pending or still in transit.', 'relief-goods.php'];

    if (!$out) $out[] = ['success', 'All clear', 'Nothing needs attention right now.', ''];
    return $out;
}

/** Everything the dashboard needs, in one call. */
function dash_build(?string $barangay, int $requestedEvent = 0, bool $withAlerts = false): array
{
    $events = dash_rows('SELECT event_id, event_name, type, date, status FROM disaster_events ORDER BY event_id DESC LIMIT 30');
    $event  = dash_pick_event($events, $requestedEvent);
    $eid    = $event ? (int) $event['event_id'] : null;
    $m      = dash_metrics($barangay, $event);
    $names  = md_barangay_names(array_keys($m['per']));

    $ctx = [
        'barangay' => $barangay,
        'events'   => $events,
        'event'    => $event,
        'm'        => $m,
        'names'    => $names,
        'centers'  => dash_centers($names),
        'rows'     => $barangay === null ? dash_activity($eid) : dash_records($barangay, $eid),
        'alerts'   => [],
        'live'     => dash_live_evacuees($barangay, $event),
    ];
    if ($withAlerts) $ctx['alerts'] = dash_alerts($ctx);
    return $ctx;
}

// ── Live evacuee In/Out count ───────────────────────────────────────────────
/**
 * Current head-count for one event: "In" = still checked in (time_out IS NULL), "Out" = timed out.
 * $b = null -> municipality-wide (with a per-barangay split); $b = '...' -> that barangay only.
 */
function dash_live_evacuees(?string $b, ?array $event): array
{
    $out = ['in' => 0, 'out' => 0, 'total' => 0, 'in_hh' => 0, 'per' => []];
    if ($event) {
        $f = $b ? ' AND c.barangay_id = ?' : '';
        foreach (dash_rows(
            "SELECT br.name AS barangay,
                    COALESCE(SUM(x.people_count) FILTER (WHERE x.time_out IS NULL), 0)     AS n_in,
                    COALESCE(SUM(x.people_count) FILTER (WHERE x.time_out IS NOT NULL), 0) AS n_out,
                    COUNT(DISTINCT CASE WHEN x.time_out IS NULL THEN COALESCE(x.household_id, -x.evacuee_id) END) AS hh_in
             FROM evacuation_evacuees x
             JOIN evacuation_centers c ON c.center_id = x.center_id
             JOIN barangays br ON br.barangay_id = c.barangay_id
             WHERE x.event_id = ?{$f} GROUP BY br.name ORDER BY n_in DESC, br.name",
            array_merge([(int) $event['event_id']], $b ? [brgy_id($b)] : [])) as $r) {
            $row = ['barangay' => md_short_barangay((string) $r['barangay']), 'in' => (int) $r['n_in'], 'out' => (int) $r['n_out']];
            $out['per'][]  = $row;
            $out['in']    += $row['in'];
            $out['out']   += $row['out'];
            $out['in_hh'] += (int) $r['hh_in'];
        }
    }
    $out['total']   = $out['in'] + $out['out'];
    $out['updated'] = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('g:i:s A');
    return $out;
}

/** JSON endpoint polled by the live card: dashboard.php?live=1&event_id=N (call before any output). */
function dash_live_endpoint(?string $b): void
{
    if (!isset($_GET['live'])) return;
    $events = dash_rows('SELECT event_id, event_name, type, date, status FROM disaster_events ORDER BY event_id DESC LIMIT 30');
    $event  = dash_pick_event($events, (int) ($_GET['event_id'] ?? 0));
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(dash_live_evacuees($b, $event));
    exit;
}

/** Status of one barangay's center: what the Social Worker set, otherwise Available. */
function dash_center_status(array $per, string $name): string
{
    return (string) (($per[$name]['status'] ?? null) ?: 'Available');
}

// ── Chart helpers (inline SVG) ──────────────────────────────────────────────
function dash_tone_var(string $tone): string
{
    if (strpos($tone, 'dc-') === 0) return 'var(--' . $tone . ')';
    $ok = ['blue', 'green', 'amber', 'red', 'purple', 'gray'];
    return 'var(--sig-' . (in_array($tone, $ok, true) ? $tone : 'blue') . ')';
}

/** Nice axis: returns [top, step] with about four gridline intervals. */
function dash_scale(int $max): array
{
    if ($max <= 0) return [4, 1];
    $pow  = 10 ** (int) floor(log10(max($max / 4, 1)));
    $step = $pow;
    foreach ([1, 2, 5, 10] as $mul) {
        $step = $mul * $pow;
        if ($step * 4 >= $max) break;
    }
    $step = max(1, (int) ceil($step));
    return [(int) (ceil($max / $step) * $step), $step];
}

function dash_chart_legend(array $items): void
{
    echo '<div class="dash-chart-legend">';
    foreach ($items as $it) echo '<span style="--tone:' . dash_tone_var($it[1]) . '"><i></i>' . md_h($it[0]) . '</span>';
    echo '</div>';
}

/**
 * Vertical (optionally stacked) bar chart.
 * $items = [['label' => 'Jaro', 'segs' => [[value, tone, name], ...]], ...]  (first seg is the bottom)
 */
function dash_bar_chart(array $items, string $empty = 'No records yet.'): void
{
    $max = 0;
    foreach ($items as $it) {
        $t = 0;
        foreach ($it['segs'] as $sg) $t += max(0, (int) $sg[0]);
        $max = max($max, $t);
    }
    if ($max === 0) { echo '<div class="dash-empty">' . md_h($empty) . '</div>'; return; }

    [$top, $step] = dash_scale($max);

    $n = count($items);
    // Many categories (e.g. 37 barangays): give every bar its own slot and let the chart scroll sideways.
    $visible = 5;                       // bars visible at once; the rest scroll in from the right
    $minBand = 100;
    $scroll  = $n > $visible;
    $L = 38; $R = 8; $T = 24; $B = $scroll ? 62 : 30; $H = $scroll ? 282 : 250;
    $W = $scroll ? (int) ($L + $R + $n * $minBand) : 560;
    $wPct = $scroll ? round($W / ($L + $R + $visible * $minBand) * 100, 2) : 100;   // svg width as % of the card
    $pw = $W - $L - $R; $ph = $H - $T - $B;
    $band = $pw / $n; $bw = min(44, $band * 0.5);
    $desc = [];
    foreach ($items as $it) {
        $t = 0; foreach ($it['segs'] as $sg) $t += max(0, (int) $sg[0]);
        $desc[] = $it['label'] . ' ' . $t;
    }
    if ($scroll) echo '<div class="dash-scroll-hint" style="margin:0 0 4px;font-size:11px;color:#8a94a6;text-align:right">Showing ' . $visible . ' of ' . $n . ' barangays &middot; scroll sideways for more &rarr;</div>'
                    . '<div class="dash-scroll" style="overflow-x:auto;overflow-y:hidden;padding-bottom:6px">';
    echo '<svg class="dash-svg' . ($scroll ? ' is-wide' : '') . '" viewBox="0 0 ' . $W . ' ' . $H . '"'
       . ($scroll ? ' style="width:' . $wPct . '%;max-width:none;height:auto;display:block;overflow:visible"' : '')
       . ' role="img" aria-label="' . md_h(implode(', ', $desc)) . '">';
    for ($v = 0; $v <= $top; $v += $step) {
        $y = round($T + $ph - ($v / $top) * $ph, 1);
        echo '<line class="dc-grid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $y . '" y2="' . $y . '"/>';
        echo '<text class="dc-y" x="' . ($L - 8) . '" y="' . ($y + 3.5) . '">' . dash_n($v) . '</text>';
    }
    foreach ($items as $i => $it) {
        $x  = round($L + $band * $i + ($band - $bw) / 2, 1);
        $yb = $T + $ph; $total = 0;
        foreach ($it['segs'] as $sg) {
            $v = max(0, (int) $sg[0]); $total += $v;
            if ($v === 0) continue;
            $h = round(($v / $top) * $ph, 1);
            $yb -= $h;
            echo '<rect class="dc-bar" x="' . $x . '" y="' . round($yb, 1) . '" width="' . round($bw, 1) . '" height="' . $h . '" rx="2" style="--tone:' . dash_tone_var($sg[1]) . '">'
               . '<title>' . md_h($it['label'] . ($sg[2] ?? '' ? ' · ' . $sg[2] : '') . ': ' . dash_n($v)) . '</title></rect>';
        }
        $cx = round($x + $bw / 2, 1);
        if ($total > 0) echo '<text class="dc-val" x="' . $cx . '" y="' . round($yb - 6, 1) . '">' . dash_n($total) . '</text>';
        if ($scroll) {
            $ly = $T + $ph + 14;
            echo '<text class="dc-x dc-x-rot" x="' . $cx . '" y="' . $ly . '" transform="rotate(-35 ' . $cx . ' ' . $ly . ')">' . md_h($it['label']) . '</text>';
        } else {
            echo '<text class="dc-x" x="' . $cx . '" y="' . ($H - 10) . '">' . md_h($it['label']) . '</text>';
        }
    }
    echo '</svg>';
    if ($scroll) echo '</div>';
}

/** Line (optionally area) chart. $labels / $values are parallel arrays. */
function dash_line_chart(array $labels, array $values, bool $area = false, string $empty = 'No evacuees recorded yet.'): void
{
    $n = count($values);
    $max = $n ? max($values) : 0;
    if ($n === 0 || $max <= 0) { echo '<div class="dash-empty">' . md_h($empty) . '</div>'; return; }

    [$top, $step] = dash_scale((int) $max);
    $W = 560; $H = 230; $L = 40; $R = 16; $T = 14; $B = 30;
    $pw = $W - $L - $R; $ph = $H - $T - $B;
    $px = static fn(int $i) => round($n > 1 ? $L + $pw * $i / ($n - 1) : $L + $pw / 2, 1);
    $py = static fn($v) => round($T + $ph - ($v / $top) * $ph, 1);

    echo '<svg class="dash-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . md_h('Evacuees over time, latest ' . dash_n(end($values))) . '">';
    for ($v = 0; $v <= $top; $v += $step) {
        $y = $py($v);
        echo '<line class="dc-grid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $y . '" y2="' . $y . '"/>';
        echo '<text class="dc-y" x="' . ($L - 8) . '" y="' . ($y + 3.5) . '">' . dash_n($v) . '</text>';
    }
    $pts = [];
    foreach ($values as $i => $v) $pts[] = $px($i) . ',' . $py($v);
    if ($area && $n > 1) {
        echo '<path class="dc-area" d="M' . $px(0) . ',' . $py(0) . ' L' . implode(' L', $pts) . ' L' . $px($n - 1) . ',' . $py(0) . ' Z"/>';
    }
    echo '<polyline class="dc-line" points="' . implode(' ', $pts) . '"/>';
    $every = max(1, (int) ceil($n / 7));
    foreach ($values as $i => $v) {
        echo '<circle class="dc-dot" cx="' . $px($i) . '" cy="' . $py($v) . '" r="2.6"><title>' . md_h($labels[$i] . ': ' . dash_n($v)) . '</title></circle>';
        if ($i % $every === 0 || $i === $n - 1) {
            echo '<text class="dc-x" x="' . $px($i) . '" y="' . ($H - 10) . '">' . md_h($labels[$i]) . '</text>';
        }
    }
    echo '</svg>';
}

/**
 * Donut chart with the total in the middle and a legend carrying the exact numbers.
 * $segs = [[label, value|null, tone], ...] drawn in this order; $legendReverse flips the legend only.
 */
function dash_donut(array $segs, string $caption, string $empty = 'No records yet.', bool $legendReverse = false): void
{
    $total = 0;
    foreach ($segs as $sg) $total += max(0, (int) $sg[1]);
    if ($total === 0) { echo '<div class="dash-empty">' . md_h($empty) . '</div>'; return; }

    $r = 42; $c = 2 * M_PI * $r; $off = 0.0;
    $live = count(array_filter($segs, static fn($sg) => (int) $sg[1] > 0));
    $gap  = $live > 1 ? 1.5 : 0.0;
    $desc = [];
    foreach ($segs as $sg) if ((int) $sg[1] > 0) $desc[] = $sg[0] . ' ' . (int) $sg[1];

    echo '<div class="dash-donut-wrap">';
    echo '<svg class="dash-donut" viewBox="0 0 120 120" role="img" aria-label="' . md_h($caption . ': ' . implode(', ', $desc)) . '">';
    echo '<circle class="dash-donut-bg" cx="60" cy="60" r="' . $r . '"/>';
    foreach ($segs as $sg) {
        $v = max(0, (int) $sg[1]);
        if ($v === 0) continue;
        $len  = $v / $total * $c;
        $dash = max(0.1, $len - $gap);
        echo '<circle class="dash-donut-seg" cx="60" cy="60" r="' . $r . '" transform="rotate(-90 60 60)" style="--tone:' . dash_tone_var($sg[2]) . '"'
           . ' stroke-dasharray="' . round($dash, 2) . ' ' . round($c - $dash, 2) . '" stroke-dashoffset="' . round(-$off, 2) . '">'
           . '<title>' . md_h($sg[0]) . ': ' . dash_n($v) . '</title></circle>';
        $off += $len;
    }
    echo '<text class="dash-donut-total" x="60" y="61">' . md_h(dash_n($total)) . '</text>';
    echo '<text class="dash-donut-cap" x="60" y="73">' . md_h($caption) . '</text>';
    echo '</svg>';

    $legend = $legendReverse ? array_reverse($segs) : $segs;
    echo '<ul class="dash-donut-legend">';
    foreach ($legend as $sg) {
        $val = $sg[1] === null ? '—' : dash_n($sg[1]);
        echo '<li style="--tone:' . dash_tone_var($sg[2]) . '"><i></i><span>' . md_h($sg[0]) . '</span><b>' . $val . '</b></li>';
    }
    echo '</ul></div>';
}

// ── Page rendering ──────────────────────────────────────────────────────────
function dash_event_bar(array $ctx, string $sideHtml = ''): void
{
    $event  = $ctx['event'];
    $events = $ctx['events'];
    $eid    = $event ? (int) $event['event_id'] : 0;
    ?>
    <div class="dash-top">
        <div class="dash-top-main">
            <?php if ($events): ?>
                <label class="dash-label" for="dashEvent">Dashboard data for:</label>
                <form method="get" class="dash-event-form">
                    <select id="dashEvent" name="event_id" class="dash-select" onchange="this.form.submit()">
                        <?php foreach ($events as $e): ?>
                            <option value="<?= (int) $e['event_id'] ?>" <?= (int) $e['event_id'] === $eid ? 'selected' : '' ?>><?= md_h($e['event_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button class="btn btn-light" type="submit">Apply</button></noscript>
                </form>
                <?php if ($event): ?>
                    <?php $active = ($event['status'] ?? 'Active') === 'Active'; ?>
                    <span class="dash-pill <?= $active ? 'is-active' : 'is-passed' ?>"><?= $active ? 'Active' : 'Passed' ?> Disaster Event</span>
                <?php endif; ?>
            <?php else: ?>
                <span class="dash-label">No disaster event has been logged yet.</span>
            <?php endif; ?>
        </div>
        <?php if ($sideHtml !== ''): ?><div class="dash-top-side"><?= $sideHtml ?></div><?php endif; ?>
    </div>
    <?php
}

function dash_kpi_row(array $kpis): void
{
    echo '<div class="dash-kpis">';
    foreach ($kpis as $k) {
        echo '<div class="card sw-summary-card dash-kpi"><div class="stat-label">' . md_h($k[0]) . '</div><div class="stat-value">' . md_h($k[1]) . '</div></div>';
    }
    echo '</div>';
}

/** Live In/Out evacuee card. Refreshes itself every 10 s while the selected event is Active. */
function dash_live_card(array $ctx, bool $isMd): void
{
    $event  = $ctx['event'];
    $live   = $ctx['live'];
    $active = $event && ($event['status'] ?? 'Active') === 'Active';
    $eid    = $event ? (int) $event['event_id'] : 0;

    echo '<div class="card dash-card dash-live" id="dashLive" data-event="' . $eid . '" data-active="' . ($active ? '1' : '0') . '" data-md="' . ($isMd ? '1' : '0') . '">';
    echo '<div class="dash-live-head"><h2>Live Evacuee Count</h2>';
    if ($event) {
        echo '<span class="dash-live-status' . ($active ? ' is-live' : '') . '" id="dashLiveStatus"><i class="live-dot" aria-hidden="true"></i><span>'
           . ($active ? 'Live · Updated ' . md_h($live['updated']) : 'Event passed · final count') . '</span></span>';
    }
    echo '</div>';

    if (!$event) {
        echo '<div class="dash-empty">No disaster event selected.</div></div>';
        return;
    }

    echo '<div class="dash-live-tiles">'
       . '<div class="dash-live-tile is-in"><small>Currently In</small><strong data-live="in">' . dash_n($live['in']) . '</strong>'
       . '<em><span data-live="in_hh">' . dash_n($live['in_hh']) . '</span> household' . ($live['in_hh'] === 1 ? '' : 's') . ' sheltered</em></div>'
       . '<div class="dash-live-tile is-out"><small>Checked Out</small><strong data-live="out">' . dash_n($live['out']) . '</strong><em>Left the center</em></div>'
       . '<div class="dash-live-tile"><small>Total Registered</small><strong data-live="total">' . dash_n($live['total']) . '</strong><em>In + Out</em></div>'
       . '</div>';

    if ($isMd) {
        echo '<div class="dash-live-table"><table class="table"><thead><tr><th>Barangay</th><th class="num">In</th><th class="num">Out</th></tr></thead><tbody id="dashLiveBody">';
        if (!$live['per']) echo '<tr><td colspan="3" class="empty">No evacuees recorded for this event.</td></tr>';
        foreach ($live['per'] as $r) {
            echo '<tr><td>' . md_h($r['barangay']) . '</td><td class="num">' . dash_n($r['in']) . '</td><td class="num">' . dash_n($r['out']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    ?>
    <script>
    (function () {
        var card = document.getElementById('dashLive');
        if (!card || card.dataset.active !== '1') return;
        var status = document.querySelector('#dashLiveStatus span'), dot = document.getElementById('dashLiveStatus');
        var body = document.getElementById('dashLiveBody'), md = card.dataset.md === '1', busy = false;
        function fmt(n) { return Number(n).toLocaleString('en-US'); }
        function set(k, v) { var e = card.querySelector('[data-live="' + k + '"]'); if (e) e.textContent = fmt(v); }
        function cell(tr, text, cls) { var td = document.createElement('td'); td.textContent = text; if (cls) td.className = cls; tr.appendChild(td); }
        function refresh() {
            if (busy || document.hidden) return;
            busy = true;
            fetch(location.pathname + '?live=1&event_id=' + encodeURIComponent(card.dataset.event), { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { if (!r.ok) throw 0; return r.json(); })
                .then(function (d) {
                    set('in', d.in); set('out', d.out); set('total', d.total); set('in_hh', d.in_hh);
                    if (md && body) {
                        body.textContent = '';
                        if (!d.per.length) { var t = document.createElement('tr'), c = document.createElement('td'); c.colSpan = 3; c.className = 'empty'; c.textContent = 'No evacuees recorded for this event.'; t.appendChild(c); body.appendChild(t); }
                        d.per.forEach(function (r) { var tr = document.createElement('tr'); cell(tr, r.barangay); cell(tr, fmt(r.in), 'num'); cell(tr, fmt(r.out), 'num'); body.appendChild(tr); });
                    }
                    status.textContent = 'Live · Updated ' + d.updated; dot.classList.add('is-live'); dot.classList.remove('is-stale');
                })
                .catch(function () { status.textContent = 'Reconnecting…'; dot.classList.add('is-stale'); })
                .then(function () { busy = false; });
        }
        setInterval(refresh, 10000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
    })();
    </script>
    <?php
}

function dash_render(array $ctx, bool $isMd): void
{
    echo '<div class="dash">';
    if ($isMd) dash_render_md($ctx); else dash_render_sw($ctx);
    echo '</div>';
}

function dash_render_md(array $ctx): void
{
    $m     = $ctx['m'];
    $event = $ctx['event'];
    $per   = $m['per'];
    $names = $ctx['names'];
    $eid   = $event ? (int) $event['event_id'] : 0;
    $noEv  = 'No disaster event selected.';

    dash_event_bar($ctx);

    echo '<h2 class="dash-section">Municipality Overview</h2>';
    dash_kpi_row([
        ['Total Evacuees',        dash_n($m['evac_people'])],
        ['Evacuated Households',  dash_n($m['evac_hh'])],
        ['Total Casualties',      dash_n($m['cas']['total'])],
        ['Barangays Reporting',   dash_n(min($m['reporting'], count($names))) . ' / ' . dash_n(count($names))],
    ]);

    dash_live_card($ctx, true);

    // Row 1: evacuees per barangay + casualty breakdown
    echo '<div class="dash-row r-2">';
    echo '<div class="card dash-card"><h2>Evacuees per Barangay</h2>';
    if (!$event) { echo '<div class="dash-empty">' . $noEv . '</div>'; }
    else {
        dash_chart_legend([['Evacuees', 'dc-b1']]);
        $items = [];
        foreach ($names as $n) $items[] = ['label' => md_short_barangay($n), 'segs' => [[$per[$n]['evac_people'] ?? 0, 'dc-b1', 'Evacuees']]];
        dash_bar_chart($items, 'No evacuees recorded for this event.');
    }
    echo '</div>';

    echo '<div class="card dash-card"><h2>Casualty Breakdown</h2>';
    if (!$event) { echo '<div class="dash-empty">' . $noEv . '</div>'; }
    else {
        dash_donut([
            ['Fatalities', $m['cas']['Fatality'], 'red'],
            ['Missing',    $m['cas']['Missing'],  'amber'],
            ['Injured',    $m['cas']['Injured'],  'blue'],
        ], 'casualties', 'No casualties recorded for this event.', true);
        if ($m['cas']['total'] > 0) {
            echo '<div class="dash-note">Recorded reports · Updated ' . md_h(dash_time($m['cas_updated'])) . '</div>';
        }
    }
    echo '</div></div>';

    // Row 2: evacuees over time + damage by barangay
    echo '<div class="dash-row r-2">';
    echo '<div class="card dash-card"><h2>Evacuees Over Time</h2>';
    if (!$event) { echo '<div class="dash-empty">' . $noEv . '</div>'; }
    else {
        dash_chart_legend([['Total evacuees', 'dc-b1']]);
        dash_line_chart($m['timeline']['labels'], $m['timeline']['values'], false);
    }
    echo '</div>';

    echo '<div class="card dash-card"><h2>Damage by Barangay</h2>';
    dash_chart_legend([['Totally damaged', 'dc-totally'], ['Partially damaged', 'dc-partially']]);
    $items = [];
    foreach ($names as $n) {
        $items[] = ['label' => md_short_barangay($n), 'segs' => [
            [$per[$n]['totally'] ?? 0,   'dc-totally',   'Totally damaged'],
            [$per[$n]['partially'] ?? 0, 'dc-partially', 'Partially damaged'],
        ]];
    }
    dash_bar_chart($items, 'No damage assessments yet.');
    echo '</div></div>';

    // Row 3: center occupancy + vehicle requests
    echo '<div class="dash-row r-2">';
    echo '<div class="card dash-card"><h2>Center Occupancy</h2><ul class="dash-centers">';
    foreach ($names as $n) {
        echo '<li><span>' . md_h($ctx['centers'][$n][0] ?? $n) . '</span>' . status_badge(dash_center_status($per, $n)) . '</li>';
    }
    echo '</ul></div>';

    $v = $m['veh'];
    echo '<div class="card dash-card dash-veh-card"><h2>Vehicle Requests</h2>';
    echo '<div class="dash-tiles"><div class="dash-tile"><small>Active requests</small><strong>' . dash_n($v['pending'] + $v['transit']) . '</strong></div>'
       . '<div class="dash-tile"><small>Completed</small><strong>' . dash_n($v['completed']) . '</strong></div></div>';
    echo '<div class="dash-note dash-note-inline">' . dash_n($v['pending']) . ' pending request' . ($v['pending'] === 1 ? '' : 's') . ' awaiting municipal dispatch</div>';
    dash_donut([
        ['Completed', $v['completed'], 'dc-b1'],
        ['In transit', $v['transit'],  'dc-b2'],
        ['Pending',   $v['pending'],   'dc-b3'],
    ], 'requests', 'No vehicle requests yet.');
    echo '</div></div>';

    // Recent activity
    $nowLabel = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('j M Y');
    echo '<div class="dash-table-head"><h2 class="dash-section">Recent Activity</h2><span>' . md_h($nowLabel) . '</span></div>';
    echo '<div class="card dash-tablecard"><div class="table-wrap"><table class="table"><thead><tr>'
       . '<th>Time</th><th>Barangay</th><th>Activity</th><th>Reported by</th><th>Status</th></tr></thead><tbody>';
    if (!$ctx['rows']) echo '<tr><td colspan="5" class="empty">No activity recorded yet.</td></tr>';
    foreach ($ctx['rows'] as $a) {
        echo '<tr><td>' . md_h(dash_time($a['ts'])) . '</td>'
           . '<td><strong>' . md_h(md_short_barangay($a['barangay'])) . '</strong></td>'
           . '<td>' . md_h($a['text']) . '</td>'
           . '<td>' . md_h($a['who'] !== '' ? $a['who'] : '—') . '</td>'
           . '<td>' . md_h($a['status']) . '</td></tr>';
    }
    echo '</tbody></table></div><div class="dash-tablefoot"><span>Showing ' . count($ctx['rows']) . ' recent ' . (count($ctx['rows']) === 1 ? 'activity' : 'activities')
       . '</span><a href="reports.php">View all activity &rarr;</a></div></div>';
}

function dash_render_sw(array $ctx): void
{
    $m      = $ctx['m'];
    $event  = $ctx['event'];
    $b      = (string) $ctx['barangay'];
    $short  = md_short_barangay($b);
    $per    = $m['per'];
    $eid    = $event ? (int) $event['event_id'] : 0;
    $q      = $eid ? '?event_id=' . $eid : '';
    $noEv   = 'No disaster event selected.';
    $center = $ctx['centers'][$b] ?? [$short . ' Evacuation Center', 0];
    $people = (int) ($per[$b]['evac_people'] ?? 0);
    $hh     = (int) ($per[$b]['evac_hh'] ?? 0);
    $cap    = (int) $center[1];

    dash_event_bar($ctx, 'Assigned Barangay: <strong>' . md_h($short) . '</strong>');

    echo '<h2 class="dash-section">Household Summary</h2>';
    dash_kpi_row([
        ['Total Households',     dash_n($m['households'])],
        ['4Ps Households',       dash_n($m['fourps'])],
        ['Total PWD',            dash_n($m['pwd'])],
        ['Total Senior Citizens', dash_n($m['seniors'])],
    ]);

    dash_live_card($ctx, false);

    // Row 1: damage assessments + casualties
    echo '<div class="dash-row r-2">';
    $d = $m['dmg'];
    echo '<div class="card dash-card"><h2>Damage Assessments</h2>';
    echo '<div class="dash-bigstat">' . dash_n($d['total']) . '</div><div class="dash-card-sub" style="margin:0">Total assessments submitted</div>';
    echo '<div class="dash-tiles"><div class="dash-tile"><small>Totally Damaged</small><strong>' . dash_n($d['totally']) . '</strong></div>'
       . '<div class="dash-tile"><small>Partially Damaged</small><strong>' . dash_n($d['partially']) . '</strong></div></div>';
    if ($m['households'] > 0 && $d['households'] > 0) {
        $pct = round($d['households'] / $m['households'] * 100, 1);
        echo '<div class="dash-note">' . md_h($pct . '% of ' . dash_n($m['households']) . ' registered households assessed') . '</div>';
    }
    echo '</div>';

    echo '<div class="card dash-card"><h2>Casualty Breakdown</h2>';
    if (!$event) { echo '<div class="dash-empty">' . $noEv . '</div>'; }
    else {
        dash_donut([
            ['Fatalities', $m['cas']['Fatality'], 'red'],
            ['Missing',    $m['cas']['Missing'],  'amber'],
            ['Injured',    $m['cas']['Injured'],  'blue'],
        ], 'casualties', 'No casualties recorded for this event.');
        if ($m['cas']['total'] > 0) {
            echo '<div class="dash-note">Included in the municipality’s ' . dash_n($m['cas_muni']) . ' reported casualties.</div>';
        }
    }
    echo '</div></div>';

    // Row 2: evacuees over time + assigned center
    echo '<div class="dash-row r-2">';
    echo '<div class="card dash-card"><h2>Evacuees Over Time</h2>';
    if (!$event) { echo '<div class="dash-empty">' . $noEv . '</div>'; }
    else {
        dash_chart_legend([[$short . ' evacuees', 'dc-b1']]);
        dash_line_chart($m['timeline']['labels'], $m['timeline']['values'], true);
        if ($people > 0) echo '<div class="dash-note">' . dash_n($people) . ' evacuees from ' . dash_n($hh) . ' household' . ($hh === 1 ? '' : 's') . ' currently sheltered</div>';
    }
    echo '</div>';

    echo '<div class="card dash-card dash-center-card"><h2>Assigned Evacuation Center</h2><div class="dash-card-sub">' . md_h($b) . '</div>';
    echo '<div class="dash-center-head"><h3>' . md_h($center[0]) . '</h3>' . status_badge(dash_center_status($per, $b)) . '</div>';
    echo '<div class="dash-tiles"><div class="dash-tile"><small>Total Evacuees</small><strong>' . dash_n($people) . '</strong></div>'
       . '<div class="dash-tile"><small>Households</small><strong>' . dash_n($hh) . '</strong></div></div>';
    echo '<div class="dash-center-foot"><a class="dash-link" href="evacuation-centers.php' . md_h($q) . '">Open center records &rarr;</a></div>';
    echo '</div></div>';

    // Row 3: vulnerable sectors
    $sectors = [
        ['Children',            $m['children'], 'blue'],
        ['Senior citizens',     $m['seniors'],  'amber'],
        ['PWD',                 $m['pwd'],      'purple'],
    ];
    echo '<div class="dash-row r-2"><div class="card dash-card"><h2>Vulnerable Sectors</h2>';
    dash_donut($sectors, 'memberships', 'No registered households yet.');
    echo '<div class="dash-note">A household member may belong to more than one sector.</div></div></div>';

    // Recent records
    $nowLabel = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('j M Y');
    echo '<div class="dash-table-head"><h2 class="dash-section">Recent Records</h2><span>' . md_h($nowLabel) . '</span></div>';
    echo '<label class="dash-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>'
       . '<input type="search" id="dashRecSearch" placeholder="Search by household head or record ID..." autocomplete="off"></label>';
    echo '<div class="card dash-tablecard"><div class="table-wrap"><table class="table" id="dashRecTable"><thead><tr>'
       . '<th>Household Head</th><th>Record Type</th><th>Zone</th><th>Updated</th></tr></thead><tbody>';
    if (!$ctx['rows']) echo '<tr><td colspan="4" class="empty">No records yet.</td></tr>';
    foreach ($ctx['rows'] as $r) {
        $hay = strtolower($r['head'] . ' ' . $r['id'] . ' ' . $r['type']);
        echo '<tr data-search="' . md_h($hay) . '"><td><strong>' . md_h($r['head']) . '</strong></td><td>' . md_h($r['type']) . '</td>'
           . '<td>' . md_h($r['zone'] !== '' ? $r['zone'] : '—') . '</td><td>' . md_h(dash_time($r['ts'])) . '</td></tr>';
    }
    echo '<tr id="dashRecNone" hidden><td colspan="4" class="empty">No records match your search.</td></tr>';
    echo '</tbody></table></div><div class="dash-tablefoot"><span>Showing ' . count($ctx['rows']) . ' recent record' . (count($ctx['rows']) === 1 ? '' : 's')
       . '</span><a href="households.php">View all records &rarr;</a></div></div>';
    ?>
    <script>
    (function () {
        var input = document.getElementById('dashRecSearch');
        if (!input) return;
        var rows = document.querySelectorAll('#dashRecTable tbody tr[data-search]');
        var none = document.getElementById('dashRecNone');
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase(), shown = 0;
            rows.forEach(function (r) {
                var ok = !q || r.getAttribute('data-search').indexOf(q) !== -1;
                r.hidden = !ok;
                if (ok) shown++;
            });
            if (none) none.hidden = shown !== 0 || rows.length === 0;
        });
    })();
    </script>
    <?php
}