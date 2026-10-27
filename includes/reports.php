<?php
/**
 * includes/reports.php
 * Live totals for the Reports & Summaries cards. Every number is counted from the
 * records that MDRRMO Officers and Social Workers have submitted; nothing is hardcoded.
 *
 * Pass a barangay to limit the totals to that barangay (used for Social Workers),
 * or null/'' to count everything (MDRRMO Officer).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/relief.php';
require_once __DIR__ . '/vehicle.php';

/** Counts rows in $table, optionally limited to one barangay. Returns 0 if the table does not exist yet. */
function report_count(string $table, ?string $barangay): int
{
    try {
        $sql    = "SELECT COUNT(*) FROM {$table}";
        $params = [];
        if ($barangay !== null && $barangay !== '') {
            $sql    .= ' WHERE barangay_id = ?';
            $params[] = brgy_id($barangay);
        }
        return (int) db_query($sql, $params)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return array{households:int,casualties:int,relief:int,vehicles:int} */
function report_totals(?string $barangay = null): array
{
    return [
        'households' => report_count('household', $barangay),
        'casualties' => report_count('casualties', $barangay),
        'relief'     => report_count('relief_distributions', $barangay),
        'vehicles'   => report_count('vehicle_requests', $barangay),
    ];
}
