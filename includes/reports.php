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

/* Header values printed on every DROMIC form (San Agustin, Romblon - MIMAROPA). */
const DROMIC_REGION       = 'MIMAROPA';
const DROMIC_PROVINCE     = 'Romblon';
const DROMIC_MUNICIPALITY = 'San Agustin';

/* Casualty type stored in the database => word printed on DROMIC Form 3. */
const DROMIC_CASUALTY_TYPES = ['Fatality' => 'Dead', 'Injured' => 'Injured', 'Missing' => 'Missing'];

/* ─────────────────────────────────────────────────────────────────────────────
 * DROMIC Forms 3, 4.1, 4.2, 4.3 - Summary of Profile of Evacuees Inside and Outside Evacuation Centers
 * ─────────────────────────────────────────────────────────────────────────────
 * Age brackets used by the form, in column order.
 */
const DROMIC_AGE_BRACKETS = [
    ['0-3',   0,   3],   // Pre-schooler
    ['4-6',   4,   6],   // Pre-schooler
    ['7-12',  7,   12],  // Older children
    ['13-17', 13,  17],  // Youth
    ['18-59', 18,  59],  // Adult
    ['60+',   60,  200], // Old
];

/** Index (0-5) of the DROMIC age bracket for an age, or null when the age is unknown. */
function dromic_bracket($age): ?int
{
    if ($age === null || $age === '' || !is_numeric($age)) return null;
    $age = (int) $age;
    foreach (DROMIC_AGE_BRACKETS as $i => [$label, $min, $max]) {
        if ($age >= $min && $age <= $max) return $i;
    }
    return null;
}

/** 'm' / 'f' / null from the Male/Female/M/F values stored in the database. */
function dromic_sex($sex): ?string
{
    $s = strtolower(trim((string) $sex));
    return in_array($s, ['male', 'm'], true) ? 'm' : (in_array($s, ['female', 'f'], true) ? 'f' : null);
}

/** One person: name, DROMIC age bracket (0-5 or null) and sex ('m'/'f'/null). */
function dromic_person(string $name, $age, $sex): array
{
    return ['name' => trim((string) preg_replace('/\s+/', ' ', $name)), 'b' => dromic_bracket($age), 'sex' => dromic_sex($sex)];
}

/** Makes sure outside_evacuation_families is up to date for an event (the DB triggers already do this; this is a safety net). */
function dromic_sync_outside(int $eventId): void
{
    try {
        db_query('SELECT public.sync_outside_families(?::int)', [$eventId]);
    } catch (Throwable $e) {
        error_log('dromic_sync_outside: ' . $e->getMessage());
    }
}

/**
 * Families of one disaster event, ready for Forms 4.1 / 4.2 / 4.3.
 *   $scope 'inside'  = people with an evacuation_evacuees record (grouped per household)
 *   $scope 'outside' = households in outside_evacuation_families (head + every family member)
 * Pass a barangay_id to limit to one barangay, or null for all.
 *
 * @return array<int,array{area:string,barangay:string,head_name:string,head:?array,deps:array,size:int}>
 */
function dromic_families(int $eventId, string $scope, ?int $barangayId = null): array
{
    $fams = [];
    $norm = static fn($s) => trim((string) preg_replace('/\s+/', ' ', (string) $s));
    try {
        if ($scope === 'inside') {
            $sql = "SELECT x.evacuee_id, x.household_id, x.composition_id, x.name, c.center_name, b.name AS barangay,
                           CASE WHEN x.composition_id IS NOT NULL THEN fc.age WHEN x.household_id IS NOT NULL THEN h.age ELSE x.age END AS age,
                           CASE WHEN x.composition_id IS NOT NULL THEN fc.sex WHEN x.household_id IS NOT NULL THEN h.sex ELSE x.sex END AS sex,
                           concat_ws(' ', h.firstname, h.middlename, h.lastname, h.nameextension) AS head_name
                    FROM evacuation_evacuees x
                    JOIN evacuation_centers c ON c.center_id = x.center_id
                    JOIN barangays b ON b.barangay_id = c.barangay_id
                    LEFT JOIN household h ON h.household_id = x.household_id
                    LEFT JOIN family_composition fc ON fc.composition_id = x.composition_id
                    WHERE x.event_id = ?";
            $p = [$eventId];
            if ($barangayId) { $sql .= ' AND c.barangay_id = ?'; $p[] = $barangayId; }
            $sql .= ' ORDER BY b.name, c.center_name, x.household_id NULLS LAST, (x.composition_id IS NOT NULL), x.evacuee_id';
            foreach (db_query($sql, $p)->fetchAll() as $r) {
                $key = $r['household_id'] !== null ? 'h' . $r['household_id'] : 'e' . $r['evacuee_id'];
                $person = dromic_person((string) $r['name'], $r['age'], $r['sex']);
                $fams[$key] ??= ['area' => (string) $r['center_name'], 'barangay' => (string) $r['barangay'],
                                 'head_name' => $r['household_id'] !== null ? $norm($r['head_name']) : $person['name'],
                                 'head' => null, 'deps' => []];
                if ($r['composition_id'] !== null) $fams[$key]['deps'][] = $person;   // family member = dependent
                else                               $fams[$key]['head'] = $person;     // head, or a walk-in with no household
            }
        } else {
            $sql = "SELECT o.household_id, b.name AS barangay, h.age, h.sex,
                           concat_ws(' ', h.firstname, h.middlename, h.lastname, h.nameextension) AS head_name
                    FROM outside_evacuation_families o
                    JOIN household h ON h.household_id = o.household_id
                    JOIN barangays b ON b.barangay_id = o.barangay_id
                    WHERE o.event_id = ?";
            $p = [$eventId];
            if ($barangayId) { $sql .= ' AND o.barangay_id = ?'; $p[] = $barangayId; }
            $sql .= ' ORDER BY b.name, h.lastname, h.firstname, o.household_id';
            foreach (db_query($sql, $p)->fetchAll() as $r) {
                $fams['h' . $r['household_id']] = ['area' => 'Outside Evacuation Center', 'barangay' => (string) $r['barangay'],
                    'head_name' => $norm($r['head_name']), 'head' => dromic_person((string) $r['head_name'], $r['age'], $r['sex']), 'deps' => []];
            }
            $sql = "SELECT f.household_id, concat_ws(' ', f.first_name, f.middle_name, f.last_name) AS name, f.age, f.sex
                    FROM family_composition f
                    JOIN outside_evacuation_families o ON o.household_id = f.household_id
                    WHERE o.event_id = ?";
            $p = [$eventId];
            if ($barangayId) { $sql .= ' AND o.barangay_id = ?'; $p[] = $barangayId; }
            $sql .= ' ORDER BY f.household_id, f.composition_id';
            foreach (db_query($sql, $p)->fetchAll() as $r) {
                if (isset($fams['h' . $r['household_id']])) $fams['h' . $r['household_id']]['deps'][] = dromic_person((string) $r['name'], $r['age'], $r['sex']);
            }
        }
    } catch (Throwable $e) {
        error_log('dromic_families(' . $scope . '): ' . $e->getMessage());
        return [];
    }
    foreach ($fams as &$f) $f['size'] = ($f['head'] ? 1 : 0) + count($f['deps']);
    unset($f);
    return array_values($fams);
}

function dromic_blank_row(string $label = ''): array
{
    return ['label' => $label, 'heads' => 0, 'dependents' => 0, 'm' => array_fill(0, 6, 0), 'f' => array_fill(0, 6, 0), 'persons' => 0, 'unspecified' => 0];
}

/** Adds up a list of families: heads (= families), dependents, persons per age bracket and sex. */
function dromic_tally(array $families, string $label = ''): array
{
    $row = dromic_blank_row($label);
    foreach ($families as $f) {
        $row['heads']++;
        $row['dependents'] += count($f['deps']);
        foreach (array_merge($f['head'] ? [$f['head']] : [], $f['deps']) as $p) {
            $row['persons']++;
            if ($p['b'] === null || $p['sex'] === null) $row['unspecified']++;   // no age/sex on record: counted only in the grand total
            else $row[$p['sex']][$p['b']]++;
        }
    }
    return $row;
}

/** Tally of a single person (one "1" in the right age/sex cell). */
function dromic_person_tally(array $p): array
{
    $t = dromic_blank_row();
    $t['persons'] = 1;
    if ($p['b'] !== null && $p['sex'] !== null) $t[$p['sex']][$p['b']] = 1; else $t['unspecified'] = 1;
    return $t;
}

/** Families with less than 5 / 5 or more members. */
function dromic_family_counts(array $families): array
{
    $lt5 = 0; $ge5 = 0;
    foreach ($families as $f) { if ($f['size'] < 5) $lt5++; else $ge5++; }
    return ['lt5' => $lt5, 'ge5' => $ge5];
}

/**
 * DROMIC Form 4.3: one row per evacuation center (inside) plus one "Outside Evacuation Center" row
 * (one per barangay when all barangays are shown), and the total.
 */
function dromic_summary(int $eventId, ?int $barangayId = null): array
{
    $inside  = dromic_families($eventId, 'inside', $barangayId);
    $outside = dromic_families($eventId, 'outside', $barangayId);

    $rows = [];
    $byCenter = [];
    foreach ($inside as $f) $byCenter[$f['area']][] = $f;
    foreach ($byCenter as $area => $fs) $rows[] = dromic_tally($fs, (string) $area);

    $byBrgy = [];
    foreach ($outside as $f) $byBrgy[$f['barangay']][] = $f;
    foreach ($byBrgy as $brgy => $fs) $rows[] = dromic_tally($fs, $barangayId ? 'Outside Evacuation Center' : 'Outside Evacuation Center - ' . $brgy);

    $total = dromic_blank_row('TOTAL');
    foreach ($rows as $r) {
        foreach (['heads', 'dependents', 'persons', 'unspecified'] as $k) $total[$k] += $r[$k];
        for ($i = 0; $i < 6; $i++) { $total['m'][$i] += $r['m'][$i]; $total['f'][$i] += $r['f'][$i]; }
    }
    $c = dromic_family_counts(array_merge($inside, $outside));
    return ['rows' => $rows, 'total' => $total, 'lt5' => $c['lt5'], 'ge5' => $c['ge5'], 'unspecified' => $total['unspecified']];
}

/**
 * DROMIC Form 3 rows for one casualty type ('Fatality' | 'Injured' | 'Missing').
 * Old rows saved before last_name/first_name existed are split from the single `name` column.
 */
function dromic_casualties(int $eventId, string $type, ?int $barangayId = null): array
{
    $sql = "SELECT c.casualty_id, c.name, c.last_name, c.first_name, c.middle_initial, c.age, c.sex, c.address,
                   c.cause, c.notes, b.name AS barangay
            FROM casualties c JOIN barangays b ON b.barangay_id = c.barangay_id
            WHERE c.event_id = ? AND c.type = ?";
    $p = [$eventId, $type];
    if ($barangayId) { $sql .= ' AND c.barangay_id = ?'; $p[] = $barangayId; }
    $sql .= ' ORDER BY b.name, COALESCE(c.last_name, c.name), c.casualty_id';
    try {
        $rows = db_query($sql, $p)->fetchAll();
    } catch (Throwable $e) {
        error_log('dromic_casualties: ' . $e->getMessage());
        return [];
    }
    foreach ($rows as &$r) {
        if (($r['last_name'] ?? '') === '' && ($r['first_name'] ?? '') === '') {
            $parts = preg_split('/\s+/', trim((string) $r['name'])) ?: [];
            $r['last_name']  = count($parts) > 1 ? array_pop($parts) : '';
            $r['first_name'] = implode(' ', $parts);
        }
    }
    unset($r);
    return $rows;
}

/** Signatory names for the form footer (empty strings when not set). */
function dromic_signatories(?int $barangayId): array
{
    if (!$barangayId) return ['secretary' => '', 'chairman' => ''];
    try {
        $r = db_query('SELECT secretary_name, bdrrm_chairman FROM barangays WHERE barangay_id = ?', [$barangayId])->fetch();
        return ['secretary' => (string) ($r['secretary_name'] ?? ''), 'chairman' => (string) ($r['bdrrm_chairman'] ?? '')];
    } catch (Throwable $e) {
        return ['secretary' => '', 'chairman' => ''];
    }
}
