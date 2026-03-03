<?php
/**
 * SMS helper — defines sendVerificationSms() for use by other files.
 * Do NOT call this file directly as an endpoint.
 *
 * Credentials are loaded from twilio_config.php (gitignored).
 */

require_once __DIR__ . '/twilio_config.php';

/**
 * Send a verification SMS via Twilio and save the code + expiry to the DB.
 *
 * @param mysqli $conn             Active DB connection
 * @param string $email            User's email (used to look up the record)
 * @param string $phone            Destination phone number (E.164 format, e.g. +35799123456)
 * @param string $code             6-digit verification code
 * @param string $body             Full SMS message body
 * @return array ['success' => bool, 'message' => string]
 */
function sendVerificationSms(mysqli $conn, string $email, string $phone, string $code, string $body): array
{
    global $TWILIO_ACCOUNT_SID, $TWILIO_AUTH_TOKEN, $TWILIO_PHONE_NUMBER;

    // Save code + expiry (20 min) to the users table
    $expiresAt = date('Y-m-d H:i:s', time() + 20 * 60);
    $stmt = $conn->prepare("
        UPDATE users
        SET verification_code = ?, verification_expires_at = ?
        WHERE email = ?
    ");
    if (!$stmt) {
        return ['success' => false, 'message' => 'DB prepare failed: ' . $conn->error];
    }
    $stmt->bind_param('sss', $code, $expiresAt, $email);
    $stmt->execute();
    $stmt->close();

    // Send via Twilio REST API
    $postData = http_build_query([
        'From' => $TWILIO_PHONE_NUMBER,
        'To'   => $phone,
        'Body' => $body,
    ]);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => "https://api.twilio.com/2010-04-01/Accounts/{$TWILIO_ACCOUNT_SID}/Messages.json",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode("{$TWILIO_ACCOUNT_SID}:{$TWILIO_AUTH_TOKEN}"),
        ],
    ]);

    $response = curl_exec($curl);
    $curlErr  = curl_errno($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($curlErr) {
        return ['success' => false, 'message' => 'cURL error: ' . curl_error($curl)];
    }

    if ($httpCode === 201) {
        return ['success' => true, 'message' => 'SMS sent successfully.'];
    }

    $data = json_decode($response, true);
    $msg  = $data['message'] ?? "Twilio returned HTTP $httpCode.";
    return ['success' => false, 'message' => $msg];
}
