<?php
/** How long an OTP stays valid, in seconds. */
const OTP_TTL_SECONDS = 45;

/**
 * Sends a verification code by email through the Brevo API.
 * Returns null on success, or an error message on failure.
 */
function otp_send_email(string $to, string $otp, string $subject = 'Verify your email'): ?string
{
    $key = getenv('BREVO_API_KEY') ?: '';
    if ($key === '') return 'No email provider configured. Set BREVO_API_KEY in environment.';

    $payload = json_encode([
        'sender'      => ['email' => getenv('BREVO_FROM') ?: 'pderalss@gmail.com'],
        'to'          => [['email' => $to]],
        'subject'     => $subject,
        'textContent' => "Your verification code is: {$otp}\nIt expires in " . OTP_TTL_SECONDS . " seconds.",
    ]);
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['api-key: ' . $key, 'Content-Type: application/json'],
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    // Brevo returns a messageId when it accepts the email; log it so delivery can be traced in Brevo's logs.
    error_log('[otp] brevo http=' . $http . ' to=' . $to . ' res=' . substr((string) $res, 0, 300) . ($err ? ' curl_err=' . $err : ''));

    $body = json_decode((string) $res, true);
    if ($http >= 200 && $http < 300 && empty($body['messageId'])) return 'Email provider did not confirm the message was queued.';

    return ($res === false || $http < 200 || $http >= 300) ? ('Email failed: ' . ($err ?: $res)) : null;
}
