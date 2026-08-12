<?php require 'includes/layout.php'; page_start('Dashboard'); ?>
<div class="grid g5">
<?php foreach([
['1,247','Total Affected Households','👥','blue'],['89','Total Casualties','⚠','red'],['12','Available Evacuation Centers','⌂','green'],['23','Pending Vehicle Requests','🚚','yellow'],['5,420','Relief Goods Available','▣','purple']
] as $s): ?><div class="card"><div class="stat"><div class="stat-icon <?=$s[3]?>"><?=$s[2]?></div><div><div class="stat-value"><?=$s[0]?></div><div class="stat-label"><?=$s[1]?></div></div></div></div><?php endforeach; ?>
</div>
<div class="grid g2 mt">
<div class="card"><h2>Barangay and Evacuation Centers Map</h2><div class="map">
<?php foreach([['Brgy. Jaro',15,20],['Brgy. Molo',30,32],['Brgy. Mandurriao',45,44],['Brgy. Arevalo',60,56],['Brgy. La Paz',75,68]] as $p): ?><div class="pin" style="left:<?=$p[1]?>%;top:<?=$p[2]?>%">📍 <?=$p[0]?></div><?php endforeach; ?>
<div style="position:absolute;bottom:14px;left:14px;background:#fff;padding:9px 12px;border-radius:8px;font-size:11px;color:#6b7280">Affected barangays and evacuation centers</div></div></div>
<div class="card"><h2>Recent Alerts & Notifications</h2>
<?php foreach([['warning','Evacuation Center Alpha at 95% capacity','10 mins ago'],['info','New damage assessment report verified for Brgy. Jaro','25 mins ago'],['danger','Low stock alert: Water supplies below threshold','1 hour ago'],['success','Vehicle request approved for Brgy. Molo','2 hours ago']] as $a): ?><div class="alert alert-<?=$a[0]?>"><div style="font-size:13px"><?=$a[1]?></div><div class="mini"><?=$a[2]?></div></div><?php endforeach; ?>
<div class="actions mt"><a class="btn btn-primary" href="households.php">Add Household</a><a class="btn btn-success" href="disasters.php">Log Event</a></div></div></div>
<div class="card mt"><h2>Barangay Overview</h2><div class="table-wrap"><table class="table"><thead><tr><th>Barangay</th><th>Affected Households</th><th>Evacuation Center</th><th>Status</th></tr></thead><tbody>
<?php foreach([['Brgy. Jaro',234,'Center Alpha','Near Capacity'],['Brgy. Molo',189,'Center Beta','Available'],['Brgy. Mandurriao',156,'Center Gamma','Available'],['Brgy. Arevalo',198,'Center Delta','Full'],['Brgy. La Paz',145,'Center Epsilon','Available']] as $r): ?><tr><td><?=$r[0]?></td><td><?=$r[1]?></td><td><?=$r[2]?></td><td><?=status_badge($r[3])?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php page_end(); ?>