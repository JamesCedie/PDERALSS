<?php
/**
 * Role-Based Access Control + Single-Session Enforcement
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const ROLE_ACCESS = [
    'MDRRMO Officer' => ['*'],

    'Social Worker' => [
        'dashboard.php',
        'households.php',
        'casualties.php',
        'evacuation-centers.php',
        'damage-assessment.php',
        'vehicle-requests.php',
        'reports.php',
        'disasters.php',
    ],
];

const ROLE_HOME = [
    'MDRRMO Officer' => 'mdrrmo-officer/dashboard.php',
    'Social Worker'  => 'social-worker/dashboard.php',
];

/**
 * Single-session enforcement.
 * Stores a unique session token in the DB (users.session_token).
 * If a new login overwrites the token, older sessions are invalidated
 * on their next page load.
 */
function enforce_single_session(): void
{
    $user = $_SESSION['user'] ?? null;
    if (!$user || empty($user['id'])) return;

    require_once __DIR__ . '/db.php';

    $sessionToken = $_SESSION['session_token'] ?? null;
    if (!$sessionToken) {
        // No token in this session — force re-login
        session_destroy();
        header('Location: ../login.php?reason=session');
        exit;
    }

    $dbUser = db_select_one('users', 'user_id = ?', [$user['id']], 'session_token');
    if (!$dbUser || $dbUser['session_token'] !== $sessionToken) {
        // Token mismatch — another device/browser logged in with this account
        session_destroy();
        header('Location: ../login.php?reason=session');
        exit;
    }
}

function require_page_access(): void
{
    $user = $_SESSION['user'] ?? null;

    if (!$user) {
        header('Location: ../login.php');
        exit;
    }

    enforce_single_session();

    $role    = $user['role'] ?? '';
    $page    = basename($_SERVER['SCRIPT_NAME']);
    $allowed = ROLE_ACCESS[$role] ?? [];

    if (in_array('*', $allowed, true) || in_array($page, $allowed, true)) {
        return;
    }

    http_response_code(403);
    $homePath = '../' . (ROLE_HOME[$role] ?? 'login.php');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Access Denied</title>
        <link rel="stylesheet" href="../assets/style.css">
    </head>
    <body>
        <div class="login-page">
            <div class="login-card">
                <div class="shield">🚫</div>
                <h1>Access Denied</h1>
                <p>Your role (<b><?= htmlspecialchars($role) ?></b>) does not have permission
                   to view <b><?= htmlspecialchars($page) ?></b>.</p>
                <a class="btn btn-primary btn-block" href="<?= htmlspecialchars($homePath) ?>">Back to Dashboard</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function can_access(string $page): bool
{
    $role    = $_SESSION['user']['role'] ?? '';
    $allowed = ROLE_ACCESS[$role] ?? [];
    return in_array('*', $allowed, true) || in_array($page, $allowed, true);
}