<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';
require_once '../includes/dromic.php';
require '../includes/layout.php';

$swUser = db_user_with_barangay($_SESSION['user']['id'] ?? null);
dromic_report_page('casualty', (int) ($swUser['barangay_id'] ?? 0));
