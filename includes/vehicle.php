<?php
/**
 * includes/vehicle.php
 * Shared helpers for the Vehicle Requests pages (Social Worker + MDRRMO).
 * Table definition lives in pderalss_schema_proposal.sql (Supabase).
 */

require_once __DIR__ . '/db.php';

const VR_PURPOSES   = [
    'Medical Checkup', 'Dialysis Session', 'Physical Therapy', 'Chemotherapy', 'Prenatal Checkup',
    'Postnatal Checkup', 'Birthing Assistance', 'Hospital-to-Home Transport', 'Home-to-Hospital Transport',
];
// Purposes used by requests made before the redesign (still shown / filterable, no longer selectable).
const VR_LEGACY_PURPOSES = ['Evacuation Transport', 'Relief Goods Delivery', 'Supply Transport', 'Medical Emergency', 'Field Visit'];
const VR_URGENCIES  = ['High', 'Medium', 'Low'];
const VR_STATUSES   = ['Pending', 'Approved', 'Scheduled', 'Completed', 'Rejected', 'Cancelled'];
// A Social Worker may cancel a booking while it is still one of these (and the date has not passed).
const VR_CANCELLABLE = ['Pending', 'Approved', 'Scheduled'];
const VR_MAX_PASSENGERS = 500;
const VR_PER_PAGE   = 10;

// Hourly slots across the whole day (start times). 00:00 = 00:00-01:00 ... 23:00 = 23:00-24:00.
const VR_TIME_SLOTS    = [
    '00:00', '01:00', '02:00', '03:00', '04:00', '05:00', '06:00', '07:00',
    '08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00',
    '16:00', '17:00', '18:00', '19:00', '20:00', '21:00', '22:00', '23:00',
];
const VR_SLOT_CAPACITY = 3;    // requests (pending/approved/scheduled) one slot can hold before it shows Unavailable
const VR_BOOKING_DAYS  = 90;   // how far ahead a request can be made
const VR_ACTIVE_STATUSES = ['Pending', 'Approved', 'Scheduled'];

function vr_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** The table now comes from the schema file; kept as a no-op for existing callers. */
function vr_ensure_table(): void {}

/** Today's date in the Philippines (the server may run in UTC). */
function vr_today(): DateTimeImmutable
{
    return new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
}

/** Strictly parses Y-m-d; returns the normalized string or null. */
function vr_parse_date(?string $value): ?string
{
    $value = trim((string) $value);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$d || ($errors && ($errors['warning_count'] || $errors['error_count']))) return null;
    return $d->format('Y-m-d') === $value ? $value : null;
}

function vr_date(?string $date, string $format = 'M j, Y'): string
{
    if (!$date) return '—';
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
    return $d ? $d->format($format) : vr_h($date);
}

/** "08:00:00" / "14:00" -> "08:00 AM" / "02:00 PM" (12-hour); empty -> "—". */
function vr_time12(?string $time): string
{
    $time = trim((string) $time);
    if ($time === '') return '—';
    $d = DateTimeImmutable::createFromFormat('H:i', substr($time, 0, 5));
    return $d ? $d->format('h:i A') : vr_h($time);
}

/** Vehicle number 0-9 -> "MD-04"; falls back to the old free-text vehicle on legacy rows. */
function vr_vehicle_label(array $r): string
{
    if (isset($r['vehicle_number']) && $r['vehicle_number'] !== null && $r['vehicle_number'] !== '') {
        return sprintf('MD-%02d', (int) $r['vehicle_number']);
    }
    return trim((string) ($r['assigned_vehicle'] ?? ''));
}

/** Purposes offered in the MD filter: current ones plus any legacy ones. */
function vr_filter_purposes(): array
{
    return array_merge(VR_PURPOSES, VR_LEGACY_PURPOSES);
}

/** Active hospitals for the dropdown: [['hospital_id' => 1, 'name' => '...'], ...]. */
function vr_hospitals(): array
{
    return db_query('SELECT hospital_id, name FROM public.hospitals WHERE is_active ORDER BY hospital_id')->fetchAll();
}

/**
 * Calendar data for the create form: for each bookable date, how many places are
 * left in each slot (0 = Unavailable). A slot is closed when it is in the past,
 * blocked in vehicle_blackouts, or already holds VR_SLOT_CAPACITY requests.
 */
function vr_availability(): array
{
    $tz    = new DateTimeZone('Asia/Manila');
    $now   = new DateTimeImmutable('now', $tz);
    $start = vr_today();
    $end   = $start->modify('+' . VR_BOOKING_DAYS . ' days');
    $in    = "'" . implode("','", VR_ACTIVE_STATUSES) . "'";

    $used = [];
    $rows = db_query(
        "SELECT requested_date::text AS d, to_char(requested_time, 'HH24:MI') AS t, COUNT(*) AS c
         FROM vehicle_requests
         WHERE requested_date BETWEEN ? AND ? AND requested_time IS NOT NULL AND status IN ({$in})
         GROUP BY 1, 2",
        [$start->format('Y-m-d'), $end->format('Y-m-d')]
    )->fetchAll();
    foreach ($rows as $r) $used[$r['d']][$r['t']] = (int) $r['c'];

    $blocked = [];
    $rows = db_query(
        "SELECT blackout_date::text AS d, to_char(start_time, 'HH24:MI') AS t
         FROM vehicle_blackouts WHERE blackout_date BETWEEN ? AND ?",
        [$start->format('Y-m-d'), $end->format('Y-m-d')]
    )->fetchAll();
    foreach ($rows as $r) $blocked[$r['d']][$r['t'] ?? '*'] = true;

    $dates = [];
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        $key = $d->format('Y-m-d');
        foreach (VR_TIME_SLOTS as $slot) {
            $slotStart = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "{$key} {$slot}", $tz);
            $free = VR_SLOT_CAPACITY - ($used[$key][$slot] ?? 0);
            if ($slotStart <= $now || isset($blocked[$key]['*']) || isset($blocked[$key][$slot])) $free = 0;
            $dates[$key][$slot] = max(0, $free);
        }
    }
    return ['today' => $start->format('Y-m-d'), 'slots' => VR_TIME_SLOTS, 'dates' => $dates];
}

/** Server-side re-check of one slot (call inside the transaction, after the advisory lock). */
function vr_slot_available(string $date, string $slot): bool
{
    if (!in_array($slot, VR_TIME_SLOTS, true)) return false;
    $tz    = new DateTimeZone('Asia/Manila');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$slot}", $tz);
    if (!$start || $start <= new DateTimeImmutable('now', $tz)) return false;
    if ($date > vr_today()->modify('+' . VR_BOOKING_DAYS . ' days')->format('Y-m-d')) return false;

    $blocked = db_query(
        'SELECT 1 FROM vehicle_blackouts WHERE blackout_date = ? AND (start_time IS NULL OR start_time = CAST(? AS time)) LIMIT 1',
        [$date, $slot]
    )->fetchColumn();
    if ($blocked) return false;

    $in   = "'" . implode("','", VR_ACTIVE_STATUSES) . "'";
    $used = (int) db_query(
        "SELECT COUNT(*) FROM vehicle_requests WHERE requested_date = ? AND requested_time = CAST(? AS time) AND status IN ({$in})",
        [$date, $slot]
    )->fetchColumn();
    return $used < VR_SLOT_CAPACITY;
}

function vr_badge(string $label, ?string $class = null): string
{
    return status_badge($label);
}

function vr_status_badge(string $status): string
{
    return status_badge($status);   // one shared colour map (includes/layout.php)
}

function vr_badge_html(string $label, string $class): string
{
    return '<span class="badge ' . $class . '">' . vr_h($label) . '</span>';
}

function vr_urgency_badge(string $urgency): string
{
    return status_badge($urgency);
}

// CSRF (simple per-session token for the POST forms)
function vr_csrf_token(): string
{
    if (empty($_SESSION['vr_csrf'])) {
        $_SESSION['vr_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['vr_csrf'];
}

function vr_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . vr_h(vr_csrf_token()) . '">';
}

function vr_csrf_valid(): bool
{
    return !empty($_SESSION['vr_csrf'])
        && hash_equals($_SESSION['vr_csrf'], (string) ($_POST['csrf'] ?? ''));
}

/** Counts per status -> ['Pending' => n, ...] with every status present. */
function vr_status_counts(string $where = '1=1', array $params = []): array
{
    $counts = array_fill_keys(VR_STATUSES, 0);
    $rows = db_query("SELECT status, COUNT(*) AS c FROM vehicle_requests WHERE {$where} GROUP BY status", $params)->fetchAll();
    foreach ($rows as $r) {
        if (isset($counts[$r['status']])) $counts[$r['status']] = (int) $r['c'];
    }
    return $counts;
}

/** Fetch-all helper that logs and returns [] on failure, so a calendar problem never breaks the page. */
function vr_rows(string $sql, array $params = []): array
{
    try {
        return db_query($sql, $params)->fetchAll();
    } catch (Throwable $e) {
        error_log('vehicle schedule query failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * "Schedule for Today" card for the dashboards: the vehicle trips set for the current day
 * (Philippine time), earliest first. Pass null for the MDRRMO (all barangays) or a barangay
 * name for the Social Worker (only that barangay's trips). Rejected / cancelled requests are left out.
 */
function vr_render_today_schedule(?string $barangay = null): void
{
    $today = vr_today();
    $where = "v.requested_date = ? AND v.status NOT IN ('Rejected', 'Cancelled')";
    $args  = [$today->format('Y-m-d')];
    if ($barangay !== null) {
        $where .= ' AND v.barangay_id = ?';
        $args[] = brgy_id($barangay);
    }
    $rows = vr_rows(
        "SELECT v.request_code AS code, to_char(v.requested_time, 'HH24:MI') AS t, v.purpose, v.passenger_name AS passenger,
                v.status, v.vehicle_number AS veh, v.assigned_vehicle AS legacy_veh, b.name AS barangay, h.name AS hospital
         FROM vehicle_requests v
         JOIN barangays b ON b.barangay_id = v.barangay_id
         LEFT JOIN hospitals h ON h.hospital_id = v.hospital_id
         WHERE {$where}
         ORDER BY v.requested_time NULLS LAST, v.request_id",
        $args
    );

    $link = $barangay === null ? 'Manage requests' : 'View vehicle schedule';
    echo '<div class="dash-table-head"><h2 class="dash-section">Schedule for Today</h2>'
       . '<span>' . vr_h($today->format('l, j M Y')) . ' · <a class="vr-link" href="vehicle-requests.php">' . $link . ' &rarr;</a></span></div>';
    echo '<div class="card dash-card vs-today">';
    if (!$rows) {
        echo '<div class="vs-empty">No vehicle trips scheduled for today' . ($barangay !== null ? ' in your barangay' : '') . '.</div>';
    }
    foreach ($rows as $r) {
        $veh  = vr_vehicle_label(['vehicle_number' => $r['veh'], 'assigned_vehicle' => $r['legacy_veh']]);
        $meta = array_filter([$r['hospital'] ?? '', $barangay === null ? 'Brgy. ' . $r['barangay'] : '', $r['code']]);
        echo '<div class="vs-trip">'
           . '<div class="vs-trip-top"><strong>' . vr_h($r['t'] ? vr_time12($r['t']) : 'No time set') . '</strong>'
           . '<span class="vs-veh">' . vr_h($veh !== '' ? $veh : 'No vehicle yet') . '</span>' . vr_status_badge($r['status']) . '</div>'
           . '<div class="vs-trip-main">' . vr_h(($r['passenger'] ?: 'Passenger not specified') . ' · ' . $r['purpose']) . '</div>'
           . '<div class="vs-trip-meta">' . vr_h(implode(' · ', $meta)) . '</div>'
           . '</div>';
    }
    echo '</div>';
}

/**
 * Vehicle schedule calendar (MDRRMO): every non-rejected request in a window around today,
 * grouped by date for the month calendar. Rendered by assets/vehicle-schedule.js.
 * Shown on the MDRRMO Vehicle Requests page, above the requests table.
 */
function vr_render_schedule_calendar(): void
{
    $today = vr_today();
    $from  = $today->modify('-62 days')->format('Y-m-d');
    $to    = $today->modify('+120 days')->format('Y-m-d');
    $rows  = vr_rows(
        "SELECT v.request_code AS code, v.requested_date::text AS d, to_char(v.requested_time, 'HH24:MI') AS t,
                v.purpose, v.passenger_name AS passenger, v.status, v.vehicle_number AS veh, v.assigned_vehicle AS legacy_veh,
                b.name AS barangay, h.name AS hospital
         FROM vehicle_requests v
         JOIN barangays b ON b.barangay_id = v.barangay_id
         LEFT JOIN hospitals h ON h.hospital_id = v.hospital_id
         WHERE v.requested_date BETWEEN ? AND ? AND v.status NOT IN ('Rejected', 'Cancelled')
         ORDER BY v.requested_date, v.requested_time NULLS LAST, v.request_id",
        [$from, $to]
    );

    $days = [];
    foreach ($rows as $r) {
        $days[$r['d']][] = [
            'code'      => (string) $r['code'],
            'time'      => (string) ($r['t'] ?? ''),
            'purpose'   => (string) $r['purpose'],
            'passenger' => (string) ($r['passenger'] ?? ''),
            'status'    => (string) $r['status'],
            'vehicle'   => vr_vehicle_label(['vehicle_number' => $r['veh'], 'assigned_vehicle' => $r['legacy_veh']]),
            'barangay'  => (string) $r['barangay'],
            'hospital'  => (string) ($r['hospital'] ?? ''),
        ];
    }
    $payload = ['today' => $today->format('Y-m-d'), 'from' => $from, 'to' => $to, 'days' => (object) $days];

    echo '<div class="vr-section-title">Vehicle Schedule</div>';
    echo '<div class="card dash-card vs-card" id="vsRoot">'
       . '<div class="vs-cal">'
       .   '<div class="vs-head"><strong id="vsTitle"></strong><div class="vs-tools">'
       .     '<span class="vs-key"><i class="vs-dot s-pending"></i>Pending</span>'
       .     '<span class="vs-key"><i class="vs-dot s-approved"></i>Approved</span>'
       .     '<span class="vs-key"><i class="vs-dot s-scheduled"></i>Scheduled</span>'
       .     '<span class="vs-key"><i class="vs-dot s-completed"></i>Completed</span>'
       .     '<button type="button" class="vr-nav" id="vsPrev" aria-label="Previous month">‹</button>'
       .     '<button type="button" class="vr-nav" id="vsToday" aria-label="Go to today">Today</button>'
       .     '<button type="button" class="vr-nav" id="vsNext" aria-label="Next month">›</button>'
       .   '</div></div>'
       .   '<div class="vs-grid vs-dow"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>'
       .   '<div class="vs-grid" id="vsGrid"></div>'
       . '</div>'
       . '<div class="vs-day"><div class="vs-day-head" id="vsDayTitle"></div><div id="vsDayList"></div></div>'
       . '</div>';
    echo '<script type="application/json" id="vsData">' . json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
    echo '<script src="../assets/vehicle-schedule.js"></script>';
}

/**
 * Vehicle availability calendar (Social Worker Vehicle Requests page, above the history table). Same availability the booking form uses,
 * read-only: green day = at least one free slot, red = fully booked / closed. Clicking a day lists
 * its time slots. The worker's own barangay bookings are marked; other barangays' details stay private.
 */
function vr_render_availability_calendar(string $barangay): void
{
    try {
        $avail = vr_availability();
        $bid   = brgy_id($barangay);
        $mine  = [];
        if ($bid > 0) {
            foreach (db_query(
                "SELECT requested_date::text AS d, to_char(requested_time, 'HH24:MI') AS t, passenger_name AS passenger, status, request_code AS code
                 FROM vehicle_requests
                 WHERE barangay_id = ? AND requested_date >= ? AND requested_time IS NOT NULL AND status IN ('Pending','Approved','Scheduled')
                 ORDER BY requested_date, requested_time",
                [$bid, $avail['today']]
            )->fetchAll() as $r) {
                $mine[$r['d']][] = ['time' => (string) $r['t'], 'passenger' => (string) ($r['passenger'] ?? ''), 'status' => (string) $r['status'], 'code' => (string) $r['code']];
            }
        }
    } catch (Throwable $e) {
        error_log('vehicle availability card failed: ' . $e->getMessage());
        return;
    }
    $avail['mine'] = (object) $mine;

    echo '<div class="vr-section-title">Vehicle Availability</div>';
    echo '<div class="card dash-card vs-card" id="vaRoot">'
       . '<div class="vs-cal">'
       .   '<div class="vs-head"><strong id="vaTitle"></strong><div class="vs-tools">'
       .     '<span class="vs-key"><i class="vs-dot s-approved"></i>Available</span>'
       .     '<span class="vs-key"><i class="vs-dot s-full"></i>Fully booked</span>'
       .     '<span class="vs-key"><i class="vs-dot s-scheduled"></i>Your barangay</span>'
       .     '<button type="button" class="vr-nav" id="vaPrev" aria-label="Previous month">‹</button>'
       .     '<button type="button" class="vr-nav" id="vaToday" aria-label="Go to today">Today</button>'
       .     '<button type="button" class="vr-nav" id="vaNext" aria-label="Next month">›</button>'
       .   '</div></div>'
       .   '<div class="vs-grid vs-dow"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>'
       .   '<div class="vs-grid" id="vaGrid"></div>'
       . '</div>'
       . '<div class="vs-day"><div class="vs-day-head" id="vaDayTitle"></div><div id="vaDayList"></div></div>'
       . '</div>';
    echo '<script type="application/json" id="vaData">' . json_encode($avail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
    echo '<script src="../assets/vehicle-availability.js"></script>';
}

