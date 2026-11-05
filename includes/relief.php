<?php
/**
 * includes/relief.php
 * Helpers for the "Relief Goods" pages (Supabase / Postgres).
 *   - MDRRMO Officer: Relief Goods & Resource Allocation (record + track + update status)
 *   - Social Worker : Relief Goods (read-only view of what the MD sent to the assigned barangay)
 * Table definition lives in pderalss_relief_goods_schema.sql.
 *
 * Everything comes from ONE table, relief_distributions, which the MD Officer fills in
 * through the "Distribute Goods" form. The summary cards are totals of the recipients per item.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/md.php';

// item => unit shown under the MD summary card
const RG_ITEMS = [
    'Food Packs'      => 'packs',
    'Materials'       => 'units',
    'Cash Assistance' => 'beneficiaries',
];
const RG_STATUSES  = ['Pending', 'In Transit', 'Completed'];
const RG_MAX_QTY   = 1000000;   // max recipients per distribution
const RG_PER_PAGE  = 10;

/** The table now comes from the schema file; kept as a no-op for existing callers. */
function rg_ensure_tables(): void {}

/** "Jaro" -> "Brgy. Jaro" (the tables show the barangay this way). */
function rg_brgy_label(string $name): string
{
    return 'Brgy. ' . md_short_barangay($name);
}

/**
 * Summary cards (the page header). Computed live from what the MD Officer recorded:
 *   Food Packs      => total quantity (packs)
 *   Materials       => total quantity (units)
 *   Cash Assistance => total amount in pesos
 * Each item also carries 'recipients' (sum of "No. of Recipients").
 * $barangayId limits the totals to one barangay; $completedOnly counts only distributions
 * marked Completed (what a barangay has actually received).
 */
function rg_totals(?int $barangayId = null, bool $completedOnly = false): array
{
    $where  = [];
    $params = [];
    if ($barangayId !== null) { $where[] = 'barangay_id = ?'; $params[] = $barangayId; }
    if ($completedOnly)       { $where[] = "status = 'Completed'"; }
    $sql = 'SELECT item, COALESCE(SUM(quantity), 0)::bigint AS q, COALESCE(SUM(amount), 0) AS a,
                   COALESCE(SUM(recipients), 0)::bigint AS r
            FROM relief_distributions' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' GROUP BY item';

    $by = [];
    foreach (db_query($sql, $params)->fetchAll() as $r) $by[$r['item']] = $r;

    $out = [];
    foreach (RG_ITEMS as $item => $unit) {
        $r = $by[$item] ?? null;
        $value = $item === 'Cash Assistance' ? (float) ($r['a'] ?? 0) : (int) ($r['q'] ?? 0);
        $out[$item] = ['unit' => $unit, 'value' => $value, 'recipients' => (int) ($r['r'] ?? 0)];
    }
    return $out;
}

/** "₱3,010" (cents shown only when there are any). */
function rg_money($v): string
{
    $v = (float) $v;
    return '₱' . number_format($v, abs($v - round($v)) > 0.004 ? 2 : 0);
}

/** Header value of a summary card: peso amount for cash, plain count otherwise. */
function rg_total_label(string $item, $value): string
{
    return $item === 'Cash Assistance' ? rg_money($value) : number_format((int) $value);
}

/** What was given, for one row: "250 packs", "Tarpaulin · 100 units", "₱5,000". */
function rg_detail(array $r): string
{
    switch ($r['item']) {
        case 'Cash Assistance': return $r['amount'] !== null ? rg_money($r['amount']) : '—';
        case 'Materials':
            $q = $r['quantity'] !== null ? number_format((int) $r['quantity']) . ' units' : '';
            return trim(($r['material_name'] ?? '') . ($r['material_name'] && $q ? ' · ' : '') . $q) ?: '—';
        default:
            return $r['quantity'] !== null ? number_format((int) $r['quantity']) . ' packs' : '—';
    }
}

/** Reads + validates the list filters (search, status, date, page) from $_GET. */
function rg_filters(array $src): array
{
    $status = (string) ($src['status'] ?? '');
    return [
        'q'      => mb_substr(trim((string) ($src['q'] ?? '')), 0, 100),
        'status' => in_array($status, RG_STATUSES, true) ? $status : '',
        'date'   => rg_parse_date((string) ($src['date'] ?? '')) ?? '',
        'page'   => max(1, (int) ($src['page'] ?? 1)),
    ];
}

function rg_url(array $f, array $override = []): string
{
    $f = array_filter(array_merge($f, $override), fn($v) => $v !== '' && $v !== null && $v !== 1);
    return 'relief-goods.php' . ($f ? '?' . http_build_query($f) : '');
}

/**
 * Filtered + paged list, latest first. $barangayId = null lists every barangay (MD Officer).
 * Returns [rows, total, page, totalPages]. Each row has `barangay` and `distributor`
 * (name of the user who recorded it, '' when unknown).
 */
function rg_list(array $f, ?int $barangayId = null): array
{
    $where  = ['1=1'];
    $params = [];
    if ($barangayId !== null) { $where[] = 'd.barangay_id = ?'; $params[] = $barangayId; }
    if ($f['q'] !== '') {
        $like = '%' . addcslashes($f['q'], '%_\\') . '%';
        $where[] = "(COALESCE(d.distribution_code, '') ILIKE ? OR b.name ILIKE ? OR d.item ILIKE ? OR COALESCE(d.material_name, '') ILIKE ? OR COALESCE(d.received_by, '') ILIKE ?)";
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($f['status'] !== '') { $where[] = 'd.status = ?';        $params[] = $f['status']; }
    if ($f['date'] !== '')   { $where[] = 'd.date_released = ?'; $params[] = $f['date']; }
    $w    = implode(' AND ', $where);
    $from = 'FROM relief_distributions d JOIN barangays b ON b.barangay_id = d.barangay_id
             LEFT JOIN users u ON u.user_id::text = d.recorded_by::text';

    $total      = (int) db_query("SELECT COUNT(*) {$from} WHERE {$w}", $params)->fetchColumn();
    $totalPages = max(1, (int) ceil($total / RG_PER_PAGE));
    $page       = max(1, min($f['page'], $totalPages));

    $rows = db_query(
        "SELECT d.*, b.name AS barangay, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS distributor
         {$from} WHERE {$w}
         ORDER BY d.date_released DESC, d.distribution_time DESC NULLS LAST, d.distribution_id DESC
         LIMIT " . RG_PER_PAGE . ' OFFSET ' . (($page - 1) * RG_PER_PAGE),
        $params
    )->fetchAll();

    return [$rows, $total, $page, $totalPages];
}

/** Strict Y-m-d parse; returns the date string or null. */
function rg_parse_date(string $value): ?string
{
    $value = trim($value);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

/** Strict HH:MM (24h, as sent by <input type="time">); returns "HH:MM" or null. */
function rg_parse_time(string $value): ?string
{
    $value = trim($value);
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $value, $m)) return $m[1] . ':' . $m[2];
    return null;
}

function rg_parse_qty(string $raw): ?int
{
    $raw = trim($raw);
    return (ctype_digit($raw) && (int) $raw >= 1 && (int) $raw <= RG_MAX_QTY) ? (int) $raw : null;
}

/** Peso amount: up to 2 decimals, > 0, max 999,999,999.99. Returns a "1234.50" string or null. */
function rg_parse_amount(string $raw): ?string
{
    $raw = str_replace(',', '', trim($raw));
    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $raw) || (float) $raw <= 0) return null;
    return number_format((float) $raw, 2, '.', '');
}

/** "28 Sep 2026" */
function rg_date_label($value): string
{
    $v = substr((string) $value, 0, 10);
    $t = $v !== '' ? strtotime($v) : false;
    return $t ? date('j M Y', $t) : '—';
}

/** "08:30 AM" (legacy rows without a time show a dash) */
function rg_time_label($value): string
{
    $v = (string) $value;
    $t = $v !== '' ? strtotime($v) : false;
    return $t ? date('h:i A', $t) : '—';
}

/**
 * Validates the Distribute / Edit form. Returns [false, 'error'] or [true, $row, $barangayName]
 * where $row holds the relief_distributions columns the form controls.
 */
function rg_validate(array $in): array
{
    $barangay    = trim((string) ($in['barangay'] ?? ''));
    $item        = (string) ($in['item'] ?? '');
    $recipients  = rg_parse_qty((string) ($in['recipients'] ?? ''));
    $date        = rg_parse_date((string) ($in['date_released'] ?? ''));
    $time        = rg_parse_time((string) ($in['distribution_time'] ?? ''));
    $description = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['description'] ?? '')));
    $received    = trim((string) ($in['received_by'] ?? ''));
    $material    = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['material_name'] ?? '')));
    $quantity    = null;
    $amount      = null;

    if ($barangay === '')              return [false, 'Enter a barangay.'];
    if (!isset(RG_ITEMS[$item]))       return [false, 'Choose a valid item.'];
    if ($recipients === null)          return [false, 'No. of recipients must be a whole number of at least 1.'];
    if ($date === null)                return [false, 'Enter a valid distribution date.'];
    if ($date > today_ph())            return [false, 'The distribution date cannot be in the future.'];
    if ($time === null)                return [false, 'Enter a valid distribution time.'];

    // The fields depend on the chosen item.
    if ($item === 'Cash Assistance') {
        $amount = rg_parse_amount((string) ($in['amount'] ?? ''));
        if ($amount === null) return [false, 'Enter the cash amount in pesos (more than 0, up to 2 decimals).'];
        $material = '';
    } else {
        $quantity = rg_parse_qty((string) ($in['quantity'] ?? ''));
        if ($quantity === null) return [false, ($item === 'Materials' ? 'Units' : 'Packs') . ' must be a whole number of at least 1.'];
        if ($item === 'Materials') {
            if ($material === '' || mb_strlen($material) > 100) return [false, 'Type the material (up to 100 characters).'];
        } else {
            $material = '';
        }
    }
    if (mb_strlen($description) > 255) return [false, 'Description is too long (max 255 characters).'];
    if (mb_strlen($received) > 150)    return [false, 'Received By is too long (max 150 characters).'];

    // Barangay must be one of the municipality's barangays (case-insensitive); store its id.
    $barangayId = brgy_id($barangay);
    if ($barangayId === 0) return [false, 'Choose a barangay from the list.'];

    return [true, [
        'barangay_id'       => $barangayId,
        'item'              => $item,
        'recipients'        => $recipients,
        'quantity'          => $quantity,
        'amount'            => $amount,
        'material_name'     => $material !== '' ? $material : null,
        'date_released'     => $date,
        'distribution_time' => $time,
        'description'       => $description !== '' ? $description : null,
        'received_by'       => $received !== '' ? $received : null,
    ], brgy_name($barangayId)];
}

/** Short sentence describing what was given, e.g. "₱5,000 cash assistance". */
function rg_what(array $row): string
{
    if ($row['item'] === 'Cash Assistance') return rg_money($row['amount']) . ' cash assistance';
    return number_format((int) $row['quantity']) . ($row['item'] === 'Materials' ? ' units of ' . $row['material_name'] : ' food packs');
}

/**
 * Validates + saves one distribution submitted by the MD Officer.
 * Returns [true, 'message'] or [false, 'error'].
 */
function rg_record_distribution(array $in, string $userId): array
{
    $v = rg_validate($in);
    if (!$v[0]) return $v;
    [, $row, $match] = $v;

    // Signed for = completed, otherwise awaiting release.
    $row['status']      = $row['received_by'] !== null ? 'Completed' : 'Pending';
    $row['recorded_by'] = $userId;

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $saved = db_insert('relief_distributions', $row);
        $code = 'RD-' . str_pad((string) $saved['distribution_id'], 3, '0', STR_PAD_LEFT);
        db_update('relief_distributions', ['distribution_code' => $code], 'distribution_id = ?', [$saved['distribution_id']]);
        $pdo->commit();
        return [true, "Distribution {$code} recorded: " . rg_what($row) . ' for ' . number_format($row['recipients']) . ' recipient' . ($row['recipients'] === 1 ? '' : 's') . " in {$match}."];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('rg_record_distribution: ' . $e->getMessage());
        return [false, 'Could not save the distribution. Please try again.'];
    }
}

/**
 * MD Officer edits an existing distribution (everything except who recorded it and the status,
 * which has its own dropdown in the table). Returns [true, 'message'] or [false, 'error'].
 */
function rg_update_distribution(int $distributionId, array $in): array
{
    $existing = $distributionId > 0 ? db_select_one('relief_distributions', 'distribution_id = ?', [$distributionId], 'distribution_id, distribution_code') : null;
    if (!$existing) return [false, 'Distribution not found.'];

    $v = rg_validate($in);
    if (!$v[0]) return $v;
    [, $row, $match] = $v;

    try {
        db_update('relief_distributions', $row, 'distribution_id = ?', [$distributionId]);
        $code = $existing['distribution_code'] ?: 'RD-' . str_pad((string) $distributionId, 3, '0', STR_PAD_LEFT);
        return [true, "Distribution {$code} updated: " . rg_what($row) . " for {$match}."];
    } catch (Throwable $e) {
        error_log('rg_update_distribution: ' . $e->getMessage());
        return [false, 'Could not update the distribution. Please try again.'];
    }
}

/** MD Officer changes a distribution's status from the table dropdown. Returns [true|false, message]. */
function rg_set_status(int $distributionId, string $status): array
{
    if ($distributionId < 1)                        return [false, 'Distribution not found.'];
    if (!in_array($status, RG_STATUSES, true))      return [false, 'Choose a valid status.'];
    try {
        $n = db_update('relief_distributions', ['status' => $status], 'distribution_id = ?', [$distributionId]);
        return $n > 0 ? [true, "Status updated to {$status}."] : [false, 'Distribution not found.'];
    } catch (Throwable $e) {
        error_log('rg_set_status: ' . $e->getMessage());
        return [false, 'Could not update the status. Please try again.'];
    }
}
