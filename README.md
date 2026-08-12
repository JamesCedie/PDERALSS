# Post-Disaster Management Dashboard — PHP Conversion

This is a page-by-page PHP conversion of the supplied React/TypeScript dashboard.

## Pages
- login.php
- dashboard.php
- households.php
- casualties.php
- disasters.php
- damage-assessment.php
- evacuation-centers.php
- vehicle-requests.php
- relief-goods.php
- reports.php
- notifications.php
- users.php
- logout.php

## Run with XAMPP
1. Extract this folder into `htdocs`.
2. Start Apache in XAMPP.
3. Open `http://localhost/post_disaster_php/login.php`.
4. Login using any username/password for the demo.

## Notes
- The original React routing was converted to separate PHP pages.
- Shared layout, navigation, CSS, and JavaScript are in `includes/` and `assets/`.
- Demo records are stored as PHP arrays to preserve the supplied prototype data.
- Forms currently demonstrate the UI flow and do not persist to MySQL.
- MySQL CRUD, authentication, role-based access, steganography verification, SMS, and report export can be connected as the next backend layer.
"# PDERALSS" 
