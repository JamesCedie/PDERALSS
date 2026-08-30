<?php
/**
 * Role-Based Access Control
 * -------------------------
 * Restricts which pages each role may open, and lets includes/layout.php
 * hide nav links a role can't use. Based on the system's use case diagram,
 * the Social Worker only has access to:
 *   - Manage Household Data      -> households.php
 *   - Show Casualty Data         -> casualties.php   (<<include>> of Household Data)
 *   - Manage Evacuation Center   -> evacuation-centers.php
 *   - Upload Damage Assessment   -> damage-assessment.php
 *   - Request Vehicle            -> vehicle-requests.php
 *   - Generate Reports           -> reports.php
 *   - Record Disaster Event      -> disasters.php
 *   - dashboard.php (landing page after login)
 *
 * MDRRMO Officer keeps full access, including User Management, Relief
 * Goods, and Notifications, which are outside the Social Worker's use cases.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const ROLE_ACCESS = [
    'MDRRMO Officer' => ['*'], // full system access

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

// Which folder each role's dashboard lives in, relative to the project root.
const ROLE_HOME = [
    'MDRRMO Officer' => 'mdrrmo-officer/dashboard.php',
    'Social Worker'  => 'social-worker/dashboard.php',
];

/**
 * Blocks the request unless the logged-in user's role is permitted to view
 * the current script. Call this at the very top of every protected page,
 * before includes/layout.php loads.
 */
function require_page_access(): void
{
    $user = $_SESSION['user'] ?? null;

    if (!$user) {
        header('Location: ../login.php');
        exit;
    }

    $role    = $user['role'] ?? '';
    $page    = basename($_SERVER['SCRIPT_NAME']);
    $allowed = ROLE_ACCESS[$role] ?? [];

    if (in_array('*', $allowed, true) || in_array($page, $allowed, true)) {
        return; // access granted
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

/**
 * Returns true if the current session's role may access the given page
 * filename (e.g. 'users.php'). Used in includes/layout.php to hide nav
 * links a role can't use:
 *
 *   <?php if (can_access($item[0])): ?>
 *       <a href="...">...</a>
 *   <?php endif; ?>
 */
function can_access(string $page): bool
{
    $role    = $_SESSION['user']['role'] ?? '';
    $allowed = ROLE_ACCESS[$role] ?? [];
    return in_array('*', $allowed, true) || in_array($page, $allowed, true);
}
