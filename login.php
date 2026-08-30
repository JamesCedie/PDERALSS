<?php
session_start();
require_once 'includes/db.php';

// Handle the "Forgot Password" username lookup (called via fetch() from this
// same page's JS). Responds with JSON and exits, without touching the normal
// login flow or rendering any HTML below.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lookup_username'])) {
    header('Content-Type: application/json');

    $lookupUsername = trim($_POST['username'] ?? '');

    if ($lookupUsername === '') {
        echo json_encode(['success' => false, 'message' => 'Please enter your username first.']);
        exit;
    }

    $lookupUser = db_select_one('users', 'username = ?', [$lookupUsername]);

    if (!$lookupUser) {
        echo json_encode(['success' => false, 'message' => 'No account found for that username.']);
        exit;
    }

    // Mask the email so it isn't exposed in full over the network,
    // e.g. "juan.delacruz@mdrrmo.gov.ph" -> "j***********@mdrrmo.gov.ph"
    $parts  = explode('@', $lookupUser['email']);
    $local  = $parts[0] ?? '';
    $domain = $parts[1] ?? '';
    $masked = (strlen($local) > 1 ? $local[0] . str_repeat('*', strlen($local) - 1) : $local) . '@' . $domain;

    echo json_encode(['success' => true, 'masked_email' => $masked]);
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $user = db_select_one('users', 'username = ?', [$username]);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user'] = [
            'id'   => $user['user_id'],
            'name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'role' => $user['role'],
        ];
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
    <title>Login - MDRRMO Portal</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="login-page">
        <div class="login-card">
            <div class="shield">🛡</div>
            <h1>Post-Disaster Evacuation Resource Allocation and Logistics Scheduling System</h1>
            <p>LGU/MDRRMO Management Portal</p>

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
                    <input type="password" name="password" placeholder="Enter your password" required>
                </div>
                <button class="btn btn-primary btn-block">Login</button>
            </form>

            <p class="mini mt" style="text-align:center">
                <a href="#" onclick="event.preventDefault(); requestOtp()">Forgot Password?</a>
            </p>
        </div>
    </div>

    <!-- OTP entry -->
    <div id="otpModal" class="modal">
        <div class="modal-box">
            <div class="modal-head">
                <h2>Enter OTP</h2>
                <button class="icon-btn" onclick="closeModal('otpModal')">✕</button>
            </div>
            <p class="mini mb">We sent a 6-digit code to <b id="otpEmailDisplay"></b>.</p>
            <form onsubmit="event.preventDefault(); alert('OTP verified. You can now reset your password.'); closeModal('otpModal');">
                <div class="field mb">
                    <label>OTP Code</label>
                    <input type="text" name="otp_code" maxlength="6" pattern="[0-9]{6}" placeholder="Enter 6-digit code" required>
                </div>
                <div class="actions">
                    <button class="btn btn-primary btn-block">Verify OTP</button>
                </div>
            </form>
            <p class="mini mt" style="text-align:center">
                <a href="#" onclick="event.preventDefault(); requestOtp()">Didn't get a code? Resend</a>
            </p>
        </div>
    </div>

    <script src="assets/app.js"></script>
</body>
</html>
