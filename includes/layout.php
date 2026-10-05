<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current = basename($_SERVER['PHP_SELF']);

$nav = [
    ['dashboard.php',           'Dashboard'],
<<<<<<< HEAD
    ['households.php',          'Household'],
    ['disasters.php',           'Disaster Events'],
    ['damage-assessment.php',   'Damage Assessment'],
    ['vehicle-requests.php',    'Vehicle Requests'],
    ['reports.php',             'Reports'],
];

function page_start($title = 'LGU Disaster Management System', $subtitle = null, $back = null)
{
    global $nav, $current;
    $isSocialWorker = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/social-worker/') !== false;
    // Every portal page (Social Worker and MDRRMO) now uses the dark PDERALSS shell.
    $modern = true;
    $isMd   = !$isSocialWorker;
=======
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
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
    $swNav = [
        ['dashboard.php', 'Dashboard'],
        ['households.php', 'Household'],
        ['disasters.php', 'Disaster Events'],
        ['damage-assessment.php', 'Damage Assessment'],
        ['vehicle-requests.php', 'Vehicle Requests'],
        ['reports.php', 'Reports'],
    ];
    $activeNav = $isSocialWorker ? $swNav : $nav;
<<<<<<< HEAD
    // MD sub-pages that belong to the Disaster Events section keep it highlighted.
    $navCurrent = (!$isSocialWorker && in_array($current, ['casualties.php', 'evacuation-centers.php'], true)) ? 'disasters.php' : $current;
    $roleLabel  = $isMd ? 'MD Officer' : ($_SESSION['user']['role'] ?? '');
    $backPage = null;
    if ($isSocialWorker && $current === 'evacuation-centers.php') $backPage = 'disasters.php';
    if ($isSocialWorker && $current === 'casualties.php') $backPage = 'disasters.php';
    // Optional back button for the top bar: [href, label]. Pages can pass their own.
    if (!$back && $backPage) $back = [$backPage, '← Disaster Events'];
=======
    $backPage = null;
    if ($isSocialWorker && $current === 'evacuation-centers.php') $backPage = 'disasters.php';
    if ($isSocialWorker && $current === 'casualties.php') $backPage = 'disasters.php';
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<<<<<<< HEAD
<body class="sw-ui <?= $isMd ? 'md-ui' : '' ?>">
    <div class="app">
        <div id="sidebarOverlay" class="sidebar-overlay"></div>
        <aside id="sidebar" class="sidebar">
            <?php if ($modern): ?>
=======
<body class="<?= $isSocialWorker ? 'sw-ui' : 'legacy-ui' ?>">
    <div class="app">
        <div id="sidebarOverlay" class="sidebar-overlay"></div>
        <aside id="sidebar" class="sidebar">
            <?php if ($isSocialWorker): ?>
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
                <div class="brand sw-brand">
                    <span class="brand-shield" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 3l7 3v5c0 4.7-3 8.1-7 10-4-1.9-7-5.3-7-10V6l7-3z"/>
                        </svg>
                    </span>
<<<<<<< HEAD
                    <span><strong>PDERALSS</strong><small><?= $isMd ? 'MUNICIPAL PORTAL' : 'PORTAL' ?></small></span>
=======
                    <span><strong>PDERALSS</strong><small>PORTAL</small></span>
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
                </div>
            <?php else: ?>
                <div class="brand">MDRRMO Portal</div>
            <?php endif; ?>

            <nav class="nav">
                <?php foreach ($activeNav as $item): ?>
                    <?php if (!function_exists('can_access') || can_access($item[0])): ?>
<<<<<<< HEAD
                        <a href="<?= $item[0] ?>" class="<?= $navCurrent === $item[0] ? 'active' : '' ?>">
                            <span><?= htmlspecialchars($item[1]) ?></span>
=======
                        <a href="<?= $item[0] ?>" class="<?= $current === $item[0] ? 'active' : '' ?>">
                            <?php if ($isSocialWorker): ?><span><?= htmlspecialchars($item[1]) ?></span><?php else: ?><span><?= $item[1] ?></span><?php endif; ?>
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>

<<<<<<< HEAD
            <?php if ($modern): ?>
                <div class="sw-sidebar-user">
                    <div>
                        <strong><?= htmlspecialchars($_SESSION['user']['name']) ?></strong>
                        <small><?= htmlspecialchars($roleLabel) ?></small>
=======
            <?php if ($isSocialWorker): ?>
                <div class="sw-sidebar-user">
                    <div>
                        <strong><?= htmlspecialchars($_SESSION['user']['name']) ?></strong>
                        <small><?= htmlspecialchars($_SESSION['user']['role']) ?></small>
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
                    </div>
                    <a title="Logout" href="../logout.php" aria-label="Logout">↪</a>
                </div>
            <?php endif; ?>
        </aside>

        <div id="main" class="main">
            <header class="topbar">
                <div class="top-left">
<<<<<<< HEAD
                    <?php if ($modern): ?>
                        <button class="sw-mobile-menu icon-btn" onclick="toggleSidebar()" aria-label="Open navigation">☰</button>
                        <div class="sw-top-title">
                            <h1><?= htmlspecialchars($title) ?></h1>
                            <p><?= htmlspecialchars($subtitle ?? ($isMd ? 'LGU Disaster Management System · Municipality-wide Control' : 'LGU Disaster Management System · Central Control')) ?></p>
=======
                    <?php if ($isSocialWorker): ?>
                        <button class="sw-mobile-menu icon-btn" onclick="toggleSidebar()" aria-label="Open navigation">☰</button>
                        <div class="sw-top-title">
                            <h1><?= htmlspecialchars($title) ?></h1>
                            <p>LGU Disaster Management System · Central Control</p>
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
                        </div>
                    <?php else: ?>
                        <button class="icon-btn" onclick="toggleSidebar()">☰</button>
                        <strong>LGU Disaster Management System</strong>
                    <?php endif; ?>
                </div>

                <div class="top-right">
<<<<<<< HEAD
                    <?php if ($back): ?>
                        <a class="sw-top-back" href="<?= htmlspecialchars($back[0]) ?>"><?= htmlspecialchars($back[1]) ?></a>
                    <?php elseif (!$modern): ?>
=======
                    <?php if ($isSocialWorker && $backPage): ?>
                        <a class="sw-top-back" href="<?= $backPage ?>">← Disaster Events</a>
                    <?php elseif (!$isSocialWorker): ?>
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
                        <div class="user">
                            <div class="user-name"><?= htmlspecialchars($_SESSION['user']['name']) ?></div>
                            <div class="user-role"><?= htmlspecialchars($_SESSION['user']['role']) ?></div>
                        </div>
                        <a class="icon-btn" title="Logout" href="../logout.php">↪</a>
                    <?php endif; ?>
                </div>
            </header>

            <main class="content">
<<<<<<< HEAD
                <?php if ($modern): ?><div id="swToastContainer" class="sw-toast-container" aria-live="polite" aria-atomic="true"></div><?php endif; ?>
=======
                <?php if ($isSocialWorker): ?><div id="swToastContainer" class="sw-toast-container" aria-live="polite" aria-atomic="true"></div><?php endif; ?>
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
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
<<<<<<< HEAD
    'Occupied'      => 'b-yellow',
=======
>>>>>>> c30f084779a5c5d2486373655c78752b847aa929
    ];

    $c = $map[$status] ?? 'b-gray';

    return '<span class="badge ' . $c . '">' . htmlspecialchars($status) . '</span>';
}