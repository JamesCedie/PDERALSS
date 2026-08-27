<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current = basename($_SERVER['PHP_SELF']);

$nav = [
    ['dashboard.php',           'Dashboard',           '▦'],
    ['households.php',          'Households',          '👥'],
    ['casualties.php',          'Casualties',          '⚠'],
    ['disasters.php',           'Disaster Events',     '☁'],
    ['damage-assessment.php',   'Damage Assessment',   '✓'],
    ['evacuation-centers.php',  'Evacuation Centers',  '⌂'],
    ['vehicle-requests.php',    'Vehicle Requests',    '🚚'],
    ['relief-goods.php',        'Relief Goods',        '▣'],
    ['reports.php',             'Reports',             '▤'],
    ['notifications.php',       'Notifications',       '🔔'],
    ['users.php',               'User Management',     '⚙'],
];

function page_start($title = 'LGU Disaster Management System')
{
    global $nav, $current;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/components.css">
</head>
<body>
    <div class="app">
        <aside id="sidebar" class="sidebar">
            <div class="brand">MDRRMO Portal</div>
            <nav class="nav">
                <?php foreach ($nav as $item): ?>
                    <?php if (!function_exists('can_access') || can_access($item[0])): ?>
                        <a href="<?= $item[0] ?>" class="<?= $current === $item[0] ? 'active' : '' ?>">
                            <span class="nav-icon"><?= $item[2] ?></span>
                            <span><?= $item[1] ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        </aside>

        <div id="main" class="main">
            <header class="topbar">
                <div class="top-left">
                    <button class="icon-btn" onclick="toggleSidebar()">☰</button>
                    <strong>LGU Disaster Management System</strong>
                </div>

                <div class="top-right">
                    <span>🔔 <span class="badge b-red">3</span></span>
                    <div class="user">
                        <div class="user-name"><?= htmlspecialchars($_SESSION['user']['name']) ?></div>
                        <div class="user-role"><?= htmlspecialchars($_SESSION['user']['role']) ?></div>
                    </div>
                    <a class="icon-btn" title="Logout" href="logout.php">↪</a>
                </div>
            </header>

            <main class="content">
<?php
}

function page_end()
{
    echo '</main></div></div><script src="assets/app.js"></script></body></html>';
}

function status_badge($status)
{
    $map = [
        'Available'     => 'b-green',
        'Active'        => 'b-green',
        'Approved'      => 'b-green',
        'Completed'     => 'b-green',
        'Success'       => 'b-green',
        'Scheduled'     => 'b-blue',
        'Pending'       => 'b-yellow',
        'Near Capacity' => 'b-yellow',
        'In Transit'    => 'b-blue',
        'Full'          => 'b-red',
        'Rejected'      => 'b-red',
        'Inactive'      => 'b-gray',
        'High'          => 'b-red',
        'Medium'        => 'b-yellow',
        'Low'           => 'b-green',
        'Verified'      => 'b-green',
        'Under Review'  => 'b-yellow',
    ];

    $c = $map[$status] ?? 'b-gray';

    return '<span class="badge ' . $c . '">' . htmlspecialchars($status) . '</span>';
}