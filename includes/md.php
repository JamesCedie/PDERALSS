<?php
/**
 * includes/md.php
 * Shared helpers for the MDRRMO (MD Officer) monitoring pages:
 * Disaster Events, Casualty Monitoring, Evacuation Center Monitoring
 * and Damage Assessment.
 */

require_once __DIR__ . '/db.php';

// Same barangay list used by User Management / Households.
const MD_BARANGAYS     = ['Brgy. Jaro', 'Brgy. Molo', 'Brgy. Mandurriao', 'Brgy. Arevalo', 'Brgy. La Paz'];
const MD_DISASTER_TYPES = ['Typhoon', 'Flood', 'Earthquake', 'Landslide', 'Fire'];
const MD_DAMAGE_LEVELS = ['Totally Damaged', 'Partially Damaged'];

function md_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** "Brgy. Arevalo" -> "Arevalo" (used in the "Event: Typhoon · Arevalo" labels). */
function md_short_barangay(string $barangay): string
{
    $short = trim((string) preg_replace('/^Brgy\.?\s*/i', '', $barangay));
    return $short !== '' ? $short : $barangay;
}

/** Fixed barangay list, plus any extra barangay names found in the data. */
function md_barangay_names(array $extra = []): array
{
    $extra = array_values(array_diff(array_unique(array_filter(array_map('strval', $extra))), MD_BARANGAYS));
    sort($extra);
    return array_merge(MD_BARANGAYS, $extra);
}

/** Formats a date / timestamp in Philippine time. */
function md_date(?string $value, string $format = 'M j, Y'): string
{
    if (!$value) return '—';
    try {
        $d = new DateTimeImmutable($value);
        if (strlen($value) > 10) $d = $d->setTimezone(new DateTimeZone('Asia/Manila'));
        return $d->format($format);
    } catch (Throwable $e) {
        return md_h($value);
    }
}

function md_find_event(int $eventId): ?array
{
    return $eventId > 0 ? db_select_one('disaster_events', 'event_id = ?', [$eventId]) : null;
}

// ---- CSRF (per-session token for MD POST forms) ---------------------------
function md_csrf_token(): string
{
    if (empty($_SESSION['md_csrf'])) {
        $_SESSION['md_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['md_csrf'];
}

function md_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . md_h(md_csrf_token()) . '">';
}

function md_csrf_valid(): bool
{
    return !empty($_SESSION['md_csrf'])
        && hash_equals($_SESSION['md_csrf'], (string) ($_POST['csrf'] ?? ''));
}
