<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';
require_once '../includes/dromic.php';
require '../includes/layout.php';

// MDRRMO Officer: reviews / prints ONE barangay's form (opened from Reports > Barangay Reports). The Social Worker prepares it.
dromic_report_page('evac', null);
