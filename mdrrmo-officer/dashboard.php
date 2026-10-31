<?php
require_once '../includes/access.php'; require_page_access();
require '../includes/layout.php';
require_once '../includes/dashboard.php';

dash_live_endpoint(null);   // ?live=1 -> JSON for the Live Evacuee Count card

// Municipality-wide figures, all read live from the database.
$ctx = dash_build(null, (int) ($_GET['event_id'] ?? 0));

page_start('Dashboard');
dash_render($ctx, true);
page_end();
