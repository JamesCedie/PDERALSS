<?php
require_once '../includes/access.php'; require_page_access();
require '../includes/layout.php';
require_once '../includes/dashboard.php';

// Municipality-wide figures, all read live from the database.
$ctx = dash_build(null, (int) ($_GET['event_id'] ?? 0));

page_start('Dashboard');
dash_render($ctx, true);
page_end();
