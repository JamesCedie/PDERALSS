<?php
/**
 * includes/relief.php
 * Helpers for the MDRRMO "Relief Goods & Resource Allocation" page (Supabase / Postgres).
 * Table definition lives in pderalss_schema_proposal.sql.
 *
 * Everything on the page comes from ONE table, relief_distributions, which the MD Officer
 * fills in through the "Distribute Goods" form. The four summary cards are simply the
 * totals of what has been submitted, per item.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/md.php';

// item => unit shown under the count
const RG_ITEMS = [
    'Food Packs'     => 'packs',
    'Water (Liters)' => 'liters',
    'Medicine'       => 'boxes',
    'Hygiene Kits'   => 'kits',
];
const RG_MAX_QTY = 1000000;

/** The table now comes from the schema file; kept as a no-op for existing callers. */
function rg_ensure_tables(): void {}

/** Summary cards: item => ['unit' => ..., 'quantity' => total submitted]. All four items always present. */
function rg_totals(): array
{
    $sums = [];
    foreach (db_query("SELECT item, SUM(quantity)::bigint AS t FROM relief_distributions GROUP BY item")->fetchAll() as $r) {
        $sums[$r['item']] = (int) $r['t'];
    }
    $out = [];
    foreach (RG_ITEMS as $item => $unit) $out[$item] = ['unit' => $unit, 'quantity' => $sums[$item] ?? 0];
    return $out;
}

/** Latest distributions first. */
function rg_distributions(int $limit = 100): array
{
    return db_query(
        'SELECT d.*, b.name AS barangay
         FROM relief_distributions d
         JOIN barangays b ON b.barangay_id = d.barangay_id
         ORDER BY d.date_released DESC, d.distribution_id DESC
         LIMIT ' . (int) $limit
    )->fetchAll();
}

/** Strict Y-m-d parse; returns the date string or null. */
function rg_parse_date(string $value): ?string
{
    $value = trim($value);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

function rg_parse_qty(string $raw): ?int
{
    $raw = trim($raw);
    return (ctype_digit($raw) && (int) $raw >= 1 && (int) $raw <= RG_MAX_QTY) ? (int) $raw : null;
}

/**
 * Validates + saves one distribution submitted by the MD Officer.
 * Returns [true, 'message'] or [false, 'error'].
 */
function rg_record_distribution(array $in, string $userId): array
{
    $barangay = trim((string) ($in['barangay'] ?? ''));
    $item     = (string) ($in['item'] ?? '');
    $qty      = rg_parse_qty((string) ($in['quantity'] ?? ''));
    $date     = rg_parse_date((string) ($in['date_released'] ?? ''));
    $received = trim((string) ($in['received_by'] ?? ''));

    if ($barangay === '')            return [false, 'Enter a barangay.'];
    if (!isset(RG_ITEMS[$item]))     return [false, 'Choose a valid item.'];
    if ($qty === null)               return [false, 'Quantity must be a whole number of at least 1.'];
    if ($date === null)              return [false, 'Enter a valid release date.'];
    if (mb_strlen($received) > 150)  return [false, 'Received By is too long (max 150 characters).'];

    // Barangay must be one of the municipality's barangays (case-insensitive); store its id.
    $barangayId = brgy_id($barangay);
    if ($barangayId === 0) return [false, 'Choose a barangay from the list.'];
    $match = brgy_name($barangayId);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $row = db_insert('relief_distributions', [
            'barangay_id'   => $barangayId,
            'item'          => $item,
            'quantity'      => $qty,
            'date_released' => $date,
            'status'        => $received !== '' ? 'Completed' : 'Pending',   // signed for = completed, otherwise awaiting release
            'received_by'   => $received !== '' ? $received : null,
            'recorded_by'   => $userId,
        ]);
        $code = 'RD-' . str_pad((string) $row['distribution_id'], 3, '0', STR_PAD_LEFT);
        db_update('relief_distributions', ['distribution_code' => $code], 'distribution_id = ?', [$row['distribution_id']]);
        $pdo->commit();
        return [true, "Distribution {$code} recorded: " . number_format($qty) . " {$item} to {$match}."];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('rg_record_distribution: ' . $e->getMessage());
        return [false, 'Could not save the distribution. Please try again.'];
    }
}

const RG_STATUSES = ['Pending', 'In Transit', 'Completed'];
const RG_PER_PAGE = 10;

/** Reads + validates the list filters (search, item, status, date, page). */
function rg_filters(array $src): array
{
    $item   = (string) ($src['item'] ?? '');
    $status = (string) ($src['status'] ?? '');
    return [
        'q'      => mb_substr(trim((string) ($src['q'] ?? '')), 0, 100),
        'item'   => isset(RG_ITEMS[$item]) ? $item : '',
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

/** Filtered + paged list. Returns [rows, total, page, totalPages]. */
function rg_search(array $f): array
{
    $where = ['1=1'];
    $params = [];
    if ($f['q'] !== '') {
        $like = '%' . addcslashes($f['q'], '%_\\') . '%';
        $where[] = "(d.distribution_code ILIKE ? OR b.name ILIKE ? OR d.item ILIKE ? OR COALESCE(d.received_by, '') ILIKE ?)";
        array_push($params, $like, $like, $like, $like);
    }
    if ($f['item'] !== '')   { $where[] = 'd.item = ?';          $params[] = $f['item']; }
    if ($f['status'] !== '') { $where[] = 'd.status = ?';        $params[] = $f['status']; }
    if ($f['date'] !== '')   { $where[] = 'd.date_released = ?'; $params[] = $f['date']; }
    $w    = implode(' AND ', $where);
    $from = 'FROM relief_distributions d JOIN barangays b ON b.barangay_id = d.barangay_id';

    $total      = (int) db_query("SELECT COUNT(*) {$from} WHERE {$w}", $params)->fetchColumn();
    $totalPages = max(1, (int) ceil($total / RG_PER_PAGE));
    $page       = min($f['page'], $totalPages);
    $rows = db_query(
        "SELECT d.*, b.name AS barangay {$from} WHERE {$w}
         ORDER BY d.date_released DESC, d.distribution_id DESC
         LIMIT " . RG_PER_PAGE . ' OFFSET ' . (($page - 1) * RG_PER_PAGE),
        $params
    )->fetchAll();
    return [$rows, $total, $page, $totalPages];
}
