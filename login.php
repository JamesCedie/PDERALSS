<?php
session_start();
require_once 'includes/db.php';

require_once __DIR__ . '/includes/PHPMailer/src/Exception.php';
require_once __DIR__ . '/includes/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/includes/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error = null;

// Show session-kicked message
$reason = $_GET['reason'] ?? '';

// Handle "Forgot Password?": look up by username, send OTP to that account's email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lookup_username'])) {
    header('Content-Type: application/json');
    $lookupUsername = trim($_POST['username'] ?? '');
    if ($lookupUsername === '') { echo json_encode(['success' => false, 'message' => 'Please enter your username first.']); exit; }
    $lookupUser = db_select_one('users', 'username = ?', [$lookupUsername]);
    if (!$lookupUser) { echo json_encode(['success' => false, 'message' => 'No account found for that username.']); exit; }
    $email = trim($lookupUser['email'] ?? '');
    if ($email === '') { echo json_encode(['success' => false, 'message' => 'No email on this account.']); exit; }

    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['password_reset'] = [
        'username' => $lookupUsername,
        'otp'      => $otp,
        'expires'  => time() + 300,
    ];

    $pass = getenv('SMTP_PASS') ?: '';
    $resendKey = getenv('RESEND_API_KEY') ?: '';

    if ($resendKey !== '') {
        $from = getenv('RESEND_FROM') ?: 'onboarding@resend.dev';
        $payload = json_encode([
            'from' => $from,
            'to' => [$email],
            'subject' => 'Your OTP Code',
            'text' => "Your OTP code is: {$otp}\nIt expires in 5 minutes.",
        ]);
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $resendKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false || $http < 200 || $http >= 300) {
            echo json_encode(['success' => false, 'message' => 'Email failed: ' . ($err ?: $res)]); exit;
        }
    } elseif ($pass === '') {
        echo json_encode(['success' => false, 'message' => 'No email provider configured. Set RESEND_API_KEY on Render or SMTP_PASS locally.']); exit;
    } else {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'pderalss@gmail.com';
            $mail->Password   = $pass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->setFrom('pderalss@gmail.com', 'PDERALSS Portal');
            $mail->addAddress($email);
            $mail->Subject = 'Your OTP Code';
            $mail->Body    = "Your OTP code is: {$otp}\nIt expires in 5 minutes.";
            $mail->send();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to send email: ' . $mail->ErrorInfo]); exit;
        }
    }

    $parts  = explode('@', $email);
    $local  = $parts[0] ?? '';
    $domain = $parts[1] ?? '';
    $masked = (strlen($local) > 1 ? $local[0] . str_repeat('*', strlen($local) - 1) : $local) . '@' . $domain;
    echo json_encode(['success' => true, 'masked_email' => $masked]);
    exit;
}

// Handle OTP verification + password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    header('Content-Type: application/json');
    $username  = trim($_POST['username'] ?? '');
    $otp       = trim($_POST['otp_code'] ?? '');
    $newPass   = $_POST['new_password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    $reset = $_SESSION['password_reset'] ?? null;
    if (!$reset || $reset['username'] !== $username || time() > $reset['expires']) {
        echo json_encode(['success' => false, 'message' => 'OTP expired or invalid. Request a new one.']); exit;
    }
    if ($otp !== $reset['otp']) { echo json_encode(['success' => false, 'message' => 'Invalid OTP code.']); exit; }
    if ($newPass === '' || $newPass !== $confirm) { echo json_encode(['success' => false, 'message' => 'Passwords do not match.']); exit; }

    $affected = db_update('users', ['password' => password_hash($newPass, PASSWORD_DEFAULT)], 'username = ?', [$username]);
    unset($_SESSION['password_reset']);
    if ($affected < 1) { echo json_encode(['success' => false, 'message' => 'Failed to update password.']); exit; }
    echo json_encode(['success' => true, 'message' => 'Password updated. You can now log in.']);
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
            <p class="mini mb">We'll send a code to the email on the username you entered.</p>
            <form onsubmit="event.preventDefault(); requestOtp()">
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
            <form onsubmit="event.preventDefault(); verifyOtp()">
                <div class="field mb">
                    <label>OTP Code</label>
                    <input type="text" id="otpCode" name="otp_code" maxlength="6" pattern="[0-9]{6}" placeholder="Enter 6-digit code" required>
                </div>
                <div class="field mb">
                    <label>New Password</label>
                    <input type="password" id="otpNewPass" placeholder="Enter new password" required>
                </div>
                <div class="field mb">
                    <label>Confirm Password</label>
                    <input type="password" id="otpConfirmPass" placeholder="Confirm new password" required>
                </div>
                <button type="submit" class="btn btn-primary">Reset Password</button>
            </form>
            <p class="mini mt" style="text-align:center">
                <a href="#" onclick="event.preventDefault(); requestOtp()">Didn't get a code? Resend</a>
            </p>
        </div>
    </div>
</div>
<script src="assets/app.js"></script>
<script>
function verifyOtp() {
    const forgotField = document.getElementById('forgotUsername');
    const loginField = document.querySelector('form [name="username"]');
    const username = (forgotField && forgotField.value.trim()) ? forgotField.value.trim() : (loginField ? loginField.value.trim() : '');
    const otp = document.getElementById('otpCode').value.trim();
    const newPass = document.getElementById('otpNewPass').value;
    const confirm = document.getElementById('otpConfirmPass').value;
    const body = 'verify_otp=1&username=' + encodeURIComponent(username) + '&otp_code=' + encodeURIComponent(otp) +
                '&new_password=' + encodeURIComponent(newPass) + '&confirm_password=' + encodeURIComponent(confirm);
    fetch('login.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                closeModal('otpModal'); closeModal('forgotPasswordModal');
            } else {
                alert(data.message || 'Reset failed.');
            }
        })
        .catch(() => alert('Something went wrong.'));
}
</script>
</body>
</html>
