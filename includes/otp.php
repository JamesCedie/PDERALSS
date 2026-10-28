<?php
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
        'textContent' => "Your verification code is: {$otp}\nIt expires in 5 minutes.",
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

    return ($res === false || $http < 200 || $http >= 300) ? ('Email failed: ' . ($err ?: $res)) : null;
}
