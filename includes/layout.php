<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current = basename($_SERVER['PHP_SELF']);

$nav = [
    ['dashboard.php',           'Dashboard'],
    ['households.php',          'Households'],
    ['disasters.php',           'Disaster Events'],
    ['damage-assessment.php',   'Damage Assessment'],
    ['vehicle-requests.php',    'Vehicle Requests'],
    ['relief-goods.php',        'Relief Goods'],
    ['reports.php',             'Reports'],
    ['notifications.php',       'Notifications'],
    ['users.php',               'User Management'],
];

function page_start($title = 'LGU Disaster Management System')
{
    global $nav, $current;
    $isSocialWorker = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/social-worker/') !== false;
    $swNav = [
        ['dashboard.php', 'Dashboard'],
        ['households.php', 'Household'],
        ['disasters.php', 'Disaster Events'],
        ['damage-assessment.php', 'Damage Assessment'],
        ['vehicle-requests.php', 'Vehicle Requests'],
        ['reports.php', 'Reports'],
    ];
    $activeNav = $isSocialWorker ? $swNav : $nav;
    $backPage = null;
    if ($isSocialWorker && $current === 'evacuation-centers.php') $backPage = 'disasters.php';
    if ($isSocialWorker && $current === 'casualties.php') $backPage = 'disasters.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="<?= $isSocialWorker ? 'sw-ui' : 'legacy-ui' ?>">
    <div class="app">
        <div id="sidebarOverlay" class="sidebar-overlay"></div>
        <aside id="sidebar" class="sidebar">
            <?php if ($isSocialWorker): ?>
                <div class="brand sw-brand">
                    <span class="brand-shield" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 3l7 3v5c0 4.7-3 8.1-7 10-4-1.9-7-5.3-7-10V6l7-3z"/>
                        </svg>
                    </span>
                    <span><strong>PDERALSS</strong><small>PORTAL</small></span>
                </div>
            <?php else: ?>
                <div class="brand">MDRRMO Portal</div>
            <?php endif; ?>

            <nav class="nav">
                <?php foreach ($activeNav as $item): ?>
                    <?php if (!function_exists('can_access') || can_access($item[0])): ?>
                        <a href="<?= $item[0] ?>" class="<?= $current === $item[0] ? 'active' : '' ?>">
                            <?php if ($isSocialWorker): ?><span><?= htmlspecialchars($item[1]) ?></span><?php else: ?><span><?= $item[1] ?></span><?php endif; ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>

            <?php if ($isSocialWorker): ?>
                <div class="sw-sidebar-user">
                    <div>
                        <strong><?= htmlspecialchars($_SESSION['user']['name']) ?></strong>
                        <small><?= htmlspecialchars($_SESSION['user']['role']) ?></small>
                    </div>
                    <a title="Logout" href="../logout.php" aria-label="Logout">↪</a>
                </div>
            <?php endif; ?>
        </aside>

        <div id="main" class="main">
            <header class="topbar">
                <div class="top-left">
                    <?php if ($isSocialWorker): ?>
                        <button class="sw-mobile-menu icon-btn" onclick="toggleSidebar()" aria-label="Open navigation">☰</button>
                        <div class="sw-top-title">
                            <h1><?= htmlspecialchars($title) ?></h1>
                            <p>LGU Disaster Management System · Central Control</p>
                        </div>
                    <?php else: ?>
                        <button class="icon-btn" onclick="toggleSidebar()">☰</button>
                        <strong>LGU Disaster Management System</strong>
                    <?php endif; ?>
                </div>

                <div class="top-right">
                    <?php if ($isSocialWorker && $backPage): ?>
                        <a class="sw-top-back" href="<?= $backPage ?>">← Disaster Events</a>
                    <?php elseif (!$isSocialWorker): ?>
                        <div class="user">
                            <div class="user-name"><?= htmlspecialchars($_SESSION['user']['name']) ?></div>
                            <div class="user-role"><?= htmlspecialchars($_SESSION['user']['role']) ?></div>
                        </div>
                        <a class="icon-btn" title="Logout" href="../logout.php">↪</a>
                    <?php endif; ?>
                </div>
            </header>

            <main class="content">
                <?php if ($isSocialWorker): ?><div id="swToastContainer" class="sw-toast-container" aria-live="polite" aria-atomic="true"></div><?php endif; ?>
<?php
}

function page_end()
{
    echo '</main></div></div><script src="../assets/app.js"></script></body></html>';
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
        'Passed'        => 'b-gray',
        'High'          => 'b-red',
        'Medium'        => 'b-yellow',
        'Low'           => 'b-green',
        'Verified'      => 'b-green',
        'Under Review'  => 'b-yellow',
    ];

    $c = $map[$status] ?? 'b-gray';

    return '<span class="badge ' . $c . '">' . htmlspecialchars($status) . '</span>';
}