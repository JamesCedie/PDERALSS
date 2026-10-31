<?php
require_once '../includes/access.php'; require_page_access();
require '../includes/layout.php';
require_once '../includes/dashboard.php';

// A Social Worker only ever sees their own barangay (users.address), same as the other SW pages.
$currentUser = db_user_with_barangay($_SESSION['user']['id'] ?? null);
$myBarangay  = trim((string) ($currentUser['address'] ?? ''));

if ($myBarangay !== '') dash_live_endpoint($myBarangay);   // ?live=1 -> JSON for the Live Evacuee Count card

page_start('Dashboard');

if ($myBarangay === '') {
    echo '<div class="card dash-card"><div class="dash-empty">No barangay is assigned to your account yet, so there is nothing to show. '
       . 'Please ask an MDRRMO Officer to set your barangay in User Management.</div></div>';
} else {
    dash_render(dash_build($myBarangay, (int) ($_GET['event_id'] ?? 0)), false);
}

page_end();
