<?php
require_once '../includes/access.php'; require_page_access();
require_once '../includes/db.php';
require_once '../includes/dromic.php';
require '../includes/layout.php';

dromic_report_page('casualty', null);
