<?php
session_start();
require_once 'includes/db.php';

$error = null;

// Show session-kicked message
$reason = $_GET['reason'] ?? '';

// Handle "Forgot Password?" lookup (called via fetch from this page)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lookup_username'])) {
    header('Content-Type: application/json');
    $lookupUsername = trim($_POST['username'] ?? '');
    if ($lookupUsername === '') { echo json_encode(['success' => false, 'message' => 'Please enter your username first.']); exit; }
    $lookupUser = db_select_one('users', 'username = ?', [$lookupUsername]);
    if (!$lookupUser) { echo json_encode(['success' => false, 'message' => 'No account found for that username.']); exit; }
    $parts  = explode('@', $lookupUser['email']);
    $local  = $parts[0] ?? '';
    $domain = $parts[1] ?? '';
    $masked = (strlen($local) > 1 ? $local[0] . str_repeat('*', strlen($local) - 1) : $local) . '@' . $domain;
    echo json_encode(['success' => true, 'masked_email' => $masked]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['lookup_username'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $user = db_select_one('users', 'username = ?', [$username]);

    if ($user && password_verify($password, $user['password'])) {
        // Generate a unique session token and store it in the DB
        // This invalidates any existing session for this account (single-session enforcement)
        $token = bin2hex(random_bytes(32));
        db_update('users', ['session_token' => $token], 'user_id = ?', [$user['user_id']]);

        $_SESSION['user'] = [
            'id'   => $user['user_id'],
            'name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'role' => $user['role'],
        ];
        $_SESSION['session_token'] = $token;

        require_once 'includes/access.php';
        $home = ROLE_HOME[$user['role']] ?? 'login.php';
        header('Location: ' . $home);
        exit;
    }

    $error = 'Invalid username or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login - PDERALSS Portal</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-page">
    <div class="login-card">
        <div class="shield" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 3l7 3v5c0 4.7-3 8.1-7 10-4-1.9-7-5.3-7-10V6l7-3z"/>
            </svg>
        </div>
        <h1>Post-Disaster Evacuation<br>Resource Allocation and<br>Logistics Scheduling System</h1>
        <p>Secure access for emergency response teams, resource coordinators, and logistics operators.</p>

        <?php if ($reason === 'session'): ?>
            <div class="alert alert-warning mb">This account was logged in from another device. Please log in again.</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger mb"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="field mb">
                <label>Username</label>
                <input name="username" type="text" placeholder="Enter your username" required>
            </div>
            <div class="field mb">
                <label>Password</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            <button class="btn btn-primary btn-block">Login</button>
        </form>

        <p class="mini mt" style="text-align:center">
            <a href="#" onclick="event.preventDefault(); openModal('forgotPasswordModal')">Forgot Password?</a>
        </p>
    </div>

    <!-- Forgot Password modal -->
    <div id="forgotPasswordModal" class="modal">
        <div class="modal-box">
            <div class="modal-head">
                <h2>Forgot Password</h2>
                <button class="icon-btn" onclick="closeModal('forgotPasswordModal')" aria-label="Close">×</button>
            </div>
            <p class="mini mb">Enter your username and we'll send a code to the email on your account.</p>
            <form onsubmit="event.preventDefault(); requestOtp()">
                <div class="field mb">
                    <label>Username</label>
                    <input type="text" id="forgotUsername" placeholder="Enter your username" required>
                </div>
                <button type="submit" class="btn btn-primary">Send OTP</button>
            </form>
        </div>
    </div>

    <!-- Enter OTP modal -->
    <div id="otpModal" class="modal">
        <div class="modal-box">
            <div class="modal-head">
                <h2>Enter OTP</h2>
                <button class="icon-btn" onclick="closeModal('otpModal')" aria-label="Close">×</button>
            </div>
            <p class="mini mb">We sent a 6-digit code to <b id="otpEmailDisplay"></b>.</p>
            <form onsubmit="event.preventDefault(); alert('OTP verified. You can now reset your password.'); closeModal('otpModal');">
                <div class="field mb">
                    <label>OTP Code</label>
                    <input type="text" name="otp_code" maxlength="6" pattern="[0-9]{6}" placeholder="Enter 6-digit code" required>
                </div>
                <button class="btn btn-primary">Verify OTP</button>
            </form>
            <p class="mini mt" style="text-align:center">
                <a href="#" onclick="event.preventDefault(); requestOtp()">Didn't get a code? Resend</a>
            </p>
        </div>
    </div>
</div>
<script src="assets/app.js"></script>
</body>
</html>
