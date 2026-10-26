<?php
require_once '../includes/access.php'; require_page_access(); require '../includes/layout.php';
page_start('Notifications');

require_once '../includes/dashboard.php';
$ctx   = dash_build(null, 0, true);
$tones = ['danger' => 'danger', 'warning' => 'warning', 'info' => 'info', 'success' => 'success'];
$notes = [];
foreach ($ctx['alerts'] as $a) $notes[] = [$a[1], $a[2], 'Live', $tones[$a[0]] ?? 'info', true];
?>

<div class="page-head">
    <h1 class="page-title">Notifications & Alerts</h1>
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
