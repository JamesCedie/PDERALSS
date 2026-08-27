<?php
require_once 'includes/access.php'; require_page_access(); require 'includes/layout.php';
page_start('Notifications');

$notes = [
    ['Evacuation Center Alpha at 95% capacity', 'Center Alpha has reached near-full occupancy. Consider redistribution.', '10 mins ago', 'warning', false],
    ['New damage assessment verified',          'The assessment for Brgy. Jaro was verified by MDRRMO.',                 '25 mins ago', 'info',    false],
    ['Low stock: Water supplies',               'Water inventory is below the configured threshold.',                    '1 hour ago',  'danger',  true],
    ['Vehicle request approved',                'Request VR-001 for Brgy. Molo was approved.',                            '2 hours ago', 'success', true],
];
?>

<div class="page-head">
    <h1 class="page-title">Notifications & Alerts</h1>
    <button class="btn btn-light" onclick="alert('All notifications marked as read.')">Mark All as Read</button>
</div>

<div class="grid g2">
    <div class="card">
        <h2>Recent Alerts</h2>
        <?php foreach($notes as $n): ?>
            <div class="alert alert-<?=$n[3]?> notif-alert">
                <div class="notif-row">
                    <b class="notif-title"><?=$n[0]?></b>
                    <?=!$n[4] ? '<span class="badge b-blue">NEW</span>' : ''?>
                </div>
                <div class="notif-message"><?=$n[1]?></div>
                <div class="mini notif-time"><?=$n[2]?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Notification Settings</h2>
        <div class="field mb">
            <label>Email Notifications</label>
            <select>
                <option>Enabled</option>
                <option>Disabled</option>
            </select>
        </div>
        <div class="field mb">
            <label>SMS Disaster Alerts</label>
            <select>
                <option>Enabled</option>
                <option>Disabled</option>
            </select>
        </div>
        <div class="field mb">
            <label>Low Inventory Alerts</label>
            <select>
                <option>Enabled</option>
                <option>Disabled</option>
            </select>
        </div>
        <div class="field">
            <label>Vehicle Request Updates</label>
            <select>
                <option>Enabled</option>
                <option>Disabled</option>
            </select>
        </div>
        <button class="btn btn-primary mt" onclick="alert('Notification settings saved.')">Save Settings</button>
    </div>
</div>

<?php page_end(); ?>
