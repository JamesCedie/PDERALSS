<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current = basename($_SERVER['PHP_SELF']);

$nav = [
    ['dashboard.php',           'Dashboard'],
    ['households.php',          'Household'],
    ['disasters.php',           'Disaster Events'],
    // Damage Assessment is intentionally not a top-level MDRRMO item: it is reached
    // from Disaster Events (the event tree below, "View Damage Assessment").
    ['vehicle-requests.php',    'Vehicle Requests'],
    ['relief-goods.php',        'Relief Goods'],
    ['reports.php',             'Reports'],
    ['users.php',              'User Management'],
];

function page_start($title = 'LGU Disaster Management System', $subtitle = null, $back = null)
{
    global $nav, $current;
    $isSocialWorker = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/social-worker/') !== false;
    // Every portal page (Social Worker and MDRRMO) now uses the dark PDERALSS shell.
    $modern = true;
    $isMd   = !$isSocialWorker;
    $swNav = [
        ['dashboard.php', 'Dashboard'],
        ['households.php', 'Household'],
        ['disasters.php', 'Disaster Events'],
        ['vehicle-requests.php', 'Vehicle Requests'],
        ['reports.php', 'Reports'],
    ];
    $activeNav = $isSocialWorker ? $swNav : $nav;
    // MD sub-pages that belong to the Disaster Events section keep it highlighted.
    $eventSubPages = [
        'evacuation-centers.php' => 'Evacuation Centers',
        'casualties.php'         => 'Casualties',
        'damage-assessment.php'  => 'Damage Assessment',
    ];
    $inEventTree = isset($eventSubPages[$current]);
    $navCurrent  = $inEventTree ? 'disasters.php' : $current;
    // Keep the chosen disaster event in the tree links. Social Workers fall back to the latest Active event.
    $treeEventId = (int) ($_GET['event_id'] ?? 0);
    if (!$treeEventId && $inEventTree && $isSocialWorker && function_exists('db_select_one')) {
        try {
            $latest = db_select_one('disaster_events', "status = 'Active'", [], 'event_id', 'event_id DESC');
            $treeEventId = (int) ($latest['event_id'] ?? 0);
        } catch (Throwable $e) { $treeEventId = 0; }
    }
    $treeQuery = $treeEventId ? '?event_id=' . $treeEventId : '';
    $roleLabel  = $isMd ? 'MD Officer' : ($_SESSION['user']['role'] ?? '');
    $backPage = null;
    if ($isSocialWorker && $current === 'evacuation-centers.php') $backPage = 'disasters.php';
    if ($isSocialWorker && $current === 'casualties.php') $backPage = 'disasters.php';
    if ($isSocialWorker && $current === 'damage-assessment.php') $backPage = 'disasters.php';
    // Optional back button for the top bar: [href, label]. Pages can pass their own.
    if (!$back && $backPage) $back = [$backPage, '← Disaster Events'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <script>
        // Apply the saved theme before first paint (no flash). Light is the default;
        // the OS colour-scheme preference is intentionally ignored.
        (function () {
            var t = 'light';
            try { if (localStorage.getItem('theme') === 'dark') t = 'dark'; } catch (e) {}
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="sw-ui <?= $isMd ? 'md-ui' : '' ?>">
    <div class="app">
        <div id="sidebarOverlay" class="sidebar-overlay"></div>
        <aside id="sidebar" class="sidebar">
            <?php if ($modern): ?>
                <div class="brand sw-brand">
                    <span class="brand-shield" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 3l7 3v5c0 4.7-3 8.1-7 10-4-1.9-7-5.3-7-10V6l7-3z"/>
                        </svg>
                    </span>
                    <span><strong>PDERALSS</strong><small><?= $isMd ? 'MUNICIPAL PORTAL' : 'PORTAL' ?></small></span>
                </div>
            <?php else: ?>
                <div class="brand">MDRRMO Portal</div>
            <?php endif; ?>

            <nav class="nav">
                <?php foreach ($activeNav as $item): ?>
                    <?php if (!function_exists('can_access') || can_access($item[0])): ?>
                        <a href="<?= $item[0] ?>" class="<?= $navCurrent === $item[0] ? 'active' : '' ?>">
                            <span><?= htmlspecialchars($item[1]) ?></span>
                        </a>
                        <?php if ($item[0] === 'disasters.php' && $inEventTree): ?>
                            <div class="nav-tree" role="group" aria-label="Disaster event sections">
                                <?php $last = array_key_last($eventSubPages); foreach ($eventSubPages as $file => $label): ?>
                                    <a href="<?= $file . htmlspecialchars($treeQuery) ?>" class="nav-tree-item <?= $file === $last ? 'is-last' : '' ?> <?= $current === $file ? 'active' : '' ?>">
                                        <span><?= htmlspecialchars($label) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>

            <?php if ($modern): ?>
                <div class="sw-sidebar-user">
                    <div>
                        <strong><?= htmlspecialchars($_SESSION['user']['name']) ?></strong>
                        <small><?= htmlspecialchars($roleLabel) ?></small>
                    </div>
                    <span class="sw-user-actions">
                        <button type="button" class="theme-toggle" id="themeToggle" title="Switch theme" aria-label="Switch to dark mode">
                            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                        </button>
                        <a title="Logout" href="../logout.php" aria-label="Logout">↪</a>
                    </span>
                </div>
            <?php endif; ?>
        </aside>

        <div id="main" class="main">
            <header class="topbar">
                <div class="top-left">
                    <?php if ($modern): ?>
                        <button class="sw-mobile-menu icon-btn" onclick="toggleSidebar()" aria-label="Open navigation">☰</button>
                        <div class="sw-top-title">
                            <h1><?= htmlspecialchars($title) ?></h1>
                            <p><?= htmlspecialchars($subtitle ?? ($isMd ? 'LGU Disaster Management System · Municipality-wide Control' : 'LGU Disaster Management System · Central Control')) ?></p>
                        </div>
                    <?php else: ?>
                        <button class="icon-btn" onclick="toggleSidebar()">☰</button>
                        <strong>LGU Disaster Management System</strong>
                    <?php endif; ?>
                </div>

                <div class="top-right">
                    <?php if (!empty($GLOBALS['page_live_status'])): ?>
                        <div class="live-status">
                            <span class="live-status-line"><i class="live-dot" aria-hidden="true"></i>Municipality monitoring active</span>
                            <small>Updated <?= date('d M Y · h:i A') ?></small>
                        </div>
                    <?php endif; ?>
                    <?php if ($back): ?>
                        <a class="sw-top-back" href="<?= htmlspecialchars($back[0]) ?>"><?= htmlspecialchars($back[1]) ?></a>
                    <?php elseif (!$modern): ?>
                        <div class="user">
                            <div class="user-name"><?= htmlspecialchars($_SESSION['user']['name']) ?></div>
                            <div class="user-role"><?= htmlspecialchars($_SESSION['user']['role']) ?></div>
                        </div>
                        <a class="icon-btn" title="Logout" href="../logout.php">↪</a>
                    <?php endif; ?>
                </div>
            </header>

            <main class="content">
                <?php if ($modern): ?><div id="swToastContainer" class="sw-toast-container" aria-live="polite" aria-atomic="true"></div><?php endif; ?>
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
    'Occupied'      => 'b-yellow',
    ];

    $c = $map[$status] ?? 'b-gray';

    return '<span class="badge ' . $c . '">' . htmlspecialchars($status) . '</span>';
}
