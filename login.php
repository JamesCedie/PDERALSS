<?php
session_start();
if($_SERVER['REQUEST_METHOD']==='POST'){
    $name=trim($_POST['username']??'');
    $_SESSION['user']=['name'=>$name ?: 'Juan Dela Cruz','role'=>$_POST['role']??'MDRRMO Officer'];
    header('Location: dashboard.php'); exit;
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login - MDRRMO Portal</title><link rel="stylesheet" href="assets/style.css"></head><body>
<div class="login-page"><div class="login-card"><div class="shield">🛡</div>
<h1>Post-Disaster Evacuation Resource Allocation and Logistics Scheduling System</h1><p>LGU/MDRRMO Management Portal</p>
<form method="post">
<div class="field mb"><label>Email / Username</label><input name="username" placeholder="Enter your username" required></div>
<div class="field mb"><label>Password</label><input type="password" name="password" placeholder="Enter your password" required></div>
<div class="field mb"><label>Role</label><select name="role"><option>MDRRMO Officer</option><option>Social Worker</option><option>Barangay Official</option></select></div>
<button class="btn btn-primary" style="width:100%;justify-content:center">Login</button>
</form></div></div></body></html>