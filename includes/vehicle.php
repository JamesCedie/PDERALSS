<?php
/**
 * includes/vehicle.php
 * Shared helpers for the Vehicle Requests pages (Social Worker + MDRRMO).
 * Table definition lives in pderalss_schema_proposal.sql (Supabase).
 */

require_once __DIR__ . '/db.php';

const VR_PURPOSES   = ['Evacuation Transport', 'Relief Goods Delivery', 'Supply Transport', 'Medical Emergency', 'Field Visit'];
const VR_URGENCIES  = ['High', 'Medium', 'Low'];
const VR_STATUSES   = ['Pending', 'Approved', 'Scheduled', 'Completed', 'Rejected'];
const VR_MAX_PASSENGERS = 500;
const VR_PER_PAGE   = 10;

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

function vr_badge(string $label, ?string $class = null): string
{
    return status_badge($label);
}

function vr_status_badge(string $status): string
{
    return status_badge($status);
}

function vr_urgency_badge(string $urgency): string
{
    return status_badge($urgency);
}

// ---- CSRF (simple per-session token for the POST forms) -------------------
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
