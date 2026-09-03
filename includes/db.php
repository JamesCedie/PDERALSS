<?php
/**
 * includes/db.php
 * -----------------------------------------------------------------------
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
 * -----------------------------------------------------------------------
 */

// ---- CONFIGURE THESE -------------------------------------------------
// Values are read from environment variables set in Render's dashboard,
// so credentials are never hardcoded in the source code.
// For local XAMPP development, you can set these in your system's
// environment variables, or temporarily replace getenv() with the
// actual values (but don't commit that to GitHub).
define('DB_HOST', getenv('DB_HOST') ?: 'aws-0-ap-northeast-1.pooler.supabase.com');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'postgres');
define('DB_USER', getenv('DB_USER') ?: 'postgres.viscaridmbunxdkzayau');
define('DB_PASS', getenv('DB_PASS') ?: 'hipusoncapstone');
// ------------------------------------------------------------------------

/**
 * Returns a shared PDO connection, creating it on first use.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
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
