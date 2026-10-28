<?php
/**
 * includes/db.php
 * Database connector for Supabase, connecting directly to its underlying
 * Postgres database via PDO (not the REST API).
 *
 * SETUP:
 *   1. Supabase dashboard -> Project Settings -> Database -> Connection
 *      string -> "URI" tab under Direct connection. Copy the host and
 *      your database password into the constants below.
 *   2. Make sure pdo_pgsql is enabled in php.ini (see setup notes) and
 *      Apache has been restarted.
 *   3. require this file wherever you need database access:
 *         require_once 'includes/db.php';
 *
 * USAGE EXAMPLES:
 *
 *   // SELECT all rows
 *   $rows = db_select('users');
 *
 *   // SELECT with a WHERE condition
 *   $rows = db_select('users', 'email = ?', ['juan@mdrrmo.gov.ph']);
 *
 *   // SELECT with extra SQL (order, limit, columns)
 *   $rows = db_select('casualties', '1=1', [], 'id, name, severity', 'created_at DESC', 10);
 *
 *   // Run any raw query yourself for full control
 *   $rows = db_query('SELECT * FROM users WHERE user_id > ?', [4])->fetchAll();
 *
 *   // INSERT a row -> returns the full inserted row (incl. generated id)
 *   $row = db_insert('users', [
 *       'firstname' => 'Pedro',
 *       'lastname'  => 'Santos',
 *       'email'     => 'pedro@mdrrmo.gov.ph',
 *       'password'  => password_hash('secret123', PASSWORD_DEFAULT),
 *       'role'      => 'Social Worker',
 *   ]);
 *   echo $row['user_id'];
 *
 *   // UPDATE rows matching a condition -> returns affected row count
 *   $count = db_update('vehicle_requests', ['status' => 'Approved'], 'id = ?', ['VR-002']);
 *
 *   // DELETE rows matching a condition -> returns affected row count
 *   $count = db_delete('users', 'user_id = ?', [5]);
 */

// LOCAL DEVELOPMENT: .env
// If a file named ".env" exists in the project root, its KEY=VALUE lines are loaded as
// environment variables (real environment variables, e.g. Render's, always win).
// ".env" is listed in .gitignore and .dockerignore, so it never reaches GitHub or the server.
(function () {
    $file = dirname(__DIR__) . '/.env';
    if (!is_readable($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, "\"'");
        if ($k !== '' && getenv($k) === false) putenv($k . '=' . $v);
    }
})();

// CONFIGURE THESE
// Values are read from environment variables set in Render's dashboard,
// so credentials are never hardcoded in the source code.
// For local XAMPP development, you can set these in your system's
// environment variables, or temporarily replace getenv() with the
// actual values (but don't commit that to GitHub).
define('DB_HOST', getenv('DB_HOST'));
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS') ?: '');

/**
 * Returns a shared PDO connection, creating it on first use.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        // Fail with a clear message when connection settings are missing
        // (an empty DB_HOST otherwise produces a confusing 'could not translate host name "port=5432"').
        $missing = [];
        foreach (['DB_HOST' => DB_HOST, 'DB_NAME' => DB_NAME, 'DB_USER' => DB_USER] as $k => $v) {
            if ($v === false || $v === null || trim((string) $v) === '') $missing[] = $k;
        }
        if ($missing) {
            die('Database is not configured. Missing environment variable(s): ' . implode(', ', $missing)
                . '. Set them in Render (Environment tab) or in a local .env file - see .env.example.');
        }
        $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
        }
    }

    return $pdo;
}

// Schema is created by the SQL file (pderalss_schema_proposal.sql), not at
// runtime. The old db_ensure_* functions are kept as no-ops so the pages
// that still call them keep working.
/** Login lockout counter on users (added automatically if missing). */
function db_ensure_login_lock(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS failed_attempts integer NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
        // No permission to alter: run the SQL in the README note by hand.
    }
}
function db_ensure_casualties_table(): void {}
function db_ensure_damage_assessments_table(): void {}
function db_ensure_disaster_event_status(): void {}
function db_ensure_evac_center_status_table(): void {}

/**
 * Lets a single family member be checked in while staying linked to their household
 * (evacuation_evacuees.composition_id). Idempotent; same DDL as migration_evacuee_members.sql.
 */
function db_ensure_evacuee_members(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db_query('ALTER TABLE public.evacuation_evacuees ADD COLUMN IF NOT EXISTS composition_id INTEGER REFERENCES public.family_composition (composition_id) ON UPDATE CASCADE ON DELETE SET NULL');
        db_query('DROP INDEX IF EXISTS public.uq_evacuees_event_household');
        db_query('CREATE UNIQUE INDEX IF NOT EXISTS uq_evacuees_event_head ON public.evacuation_evacuees (event_id, household_id) WHERE household_id IS NOT NULL AND composition_id IS NULL');
        db_query('CREATE UNIQUE INDEX IF NOT EXISTS uq_evacuees_event_member ON public.evacuation_evacuees (event_id, composition_id) WHERE composition_id IS NOT NULL');
        // Every evacuee record is exactly one person (older records stored a whole household's headcount).
        db_query('UPDATE public.evacuation_evacuees SET people_count = 1 WHERE people_count <> 1');
    } catch (PDOException $e) {
        error_log('db_ensure_evacuee_members: ' . $e->getMessage());
    }
}

const EVAC_CENTER_STATUSES = ['Available', 'Occupied', 'Near Capacity', 'Full'];
// Options a Social Worker can pick from the Occupancy Status dropdown.
const EVAC_CENTER_SW_OPTIONS = ['Available', 'Full'];

// Barangays (public.barangays) - the single source of truth.

/** All barangays: [['barangay_id' => 1, 'name' => 'Abilay Norte', 'latitude' => .., 'longitude' => ..], ...] ordered by name. */
function brgy_all(): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = db_query('SELECT barangay_id, name, latitude, longitude FROM public.barangays ORDER BY name')->fetchAll();
    }
    return $rows;
}

/** Barangay names only, ordered by name. */
function brgy_names(): array
{
    return array_column(brgy_all(), 'name');
}

/** name (case-insensitive) => barangay_id, or 0 when the name is not a known barangay. */
function brgy_id(?string $name): int
{
    $name = trim((string) $name);
    if ($name === '') return 0;
    foreach (brgy_all() as $b) {
        if (strcasecmp($b['name'], $name) === 0) return (int) $b['barangay_id'];
    }
    return 0;
}

/** barangay_id => name ('' when unknown). */
function brgy_name(?int $id): string
{
    foreach (brgy_all() as $b) {
        if ((int) $b['barangay_id'] === (int) $id) return (string) $b['name'];
    }
    return '';
}

/**
 * A users row with the assigned barangay resolved:
 *   $u['barangay_id'] (int|null) and $u['address'] (barangay name, '' when none).
 * 'address' is kept as the key so the Social Worker pages keep reading the barangay NAME from it.
 */
function db_user_with_barangay($userId): ?array
{
    $u = db_select_one('users', 'user_id = ?', [$userId]);
    if (!$u) return null;
    $u['address'] = !empty($u['barangay_id']) ? brgy_name((int) $u['barangay_id']) : '';
    return $u;
}

/**
 * Runs a raw prepared query and returns the PDOStatement.
 * Use this directly for anything the helpers below don't cover (JOINs, etc.).
 */
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * SELECT helper.
 *
 * @param string $table    Table name
 * @param string $where    WHERE clause without the word WHERE, e.g. 'email = ?'. '1=1' = no filter.
 * @param array  $params   Bound params for $where, in order
 * @param string $columns  Columns to select, default '*'
 * @param string|null $orderBy  e.g. 'created_at DESC'
 * @param int|null $limit
 * @return array  Array of associative rows
 */
function db_select(
    string $table,
    string $where = '1=1',
    array $params = [],
    string $columns = '*',
    ?string $orderBy = null,
    ?int $limit = null
): array {
    $sql = "SELECT {$columns} FROM {$table} WHERE {$where}";
    if ($orderBy) $sql .= " ORDER BY {$orderBy}";
    if ($limit)   $sql .= " LIMIT " . (int) $limit;

    return db_query($sql, $params)->fetchAll();
}

/** SELECT a single row, or null if none found. */
function db_select_one(string $table, string $where = '1=1', array $params = [], string $columns = '*'): ?array
{
    $rows = db_select($table, $where, $params, $columns, null, 1);
    return $rows[0] ?? null;
}

/**
 * INSERT a row. $data is an associative array of column => value.
 * Returns the full inserted row (Postgres RETURNING *), or null on failure.
 */
function db_insert(string $table, array $data): ?array
{
    $columns = array_keys($data);
    $placeholders = array_fill(0, count($columns), '?');

    $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ") RETURNING *";
    $stmt = db_query($sql, array_values($data));

    return $stmt->fetch() ?: null;
}

/**
 * UPDATE rows matching $where with $data (column => value).
 * Returns the number of affected rows.
 */
function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $set = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($data)));
    $sql = "UPDATE {$table} SET {$set} WHERE {$where}";

    $stmt = db_query($sql, array_merge(array_values($data), $whereParams));
    return $stmt->rowCount();
}

/**
 * DELETE rows matching $where.
 * Returns the number of affected rows.
 */
function db_delete(string $table, string $where, array $params = []): int
{
    $stmt = db_query("DELETE FROM {$table} WHERE {$where}", $params);
    return $stmt->rowCount();
}

/** Today's date (Y-m-d) in Philippine time - the latest date a user may pick in a date field. */
if (!function_exists('today_ph')) {
    function today_ph(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    }
}
