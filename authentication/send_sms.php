<?php
// Do NOT start the session here – the caller (e.g. select_verification_method.php)
// already starts session_start() at the top.

require_once "database.php";

/**
 * Reusable helper to update the user's phone + verification_code
 * and send an SMS via Twilio.
 *
 * Returns: ['success' => bool, 'message' => string]
 */
function sendVerificationSms(mysqli $conn, string $email, string $phone, string $verification_code, ?string $body = null): array
{
    // Twilio credentials
    $accountSid        = 'ACed50809afda0163369b2505abc4354f7'; // Your Twilio Account SID
    $authToken         = '6079e764164a51911b9fb45ca215a4df';   // Your Twilio Auth Token
    $twilioPhoneNumber = '+12182616825';                        // Your Twilio phone number

    if ($body === null) {
        $body = "Your Athina E-Shop verification code is {$verification_code}. It is valid for 20 minutes.";
    }

    // Remember phone in session (for display on verify_phone.php)
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION["phone"] = $phone;
    }

    // Set expiry 20 minutes from now
    $expiresAt = date('Y-m-d H:i:s', time() + 20 * 60);

    // Update user data in the database (phone + verification_code + expiry)
    $sql  = "UPDATE users SET phone = ?, verification_code = ?, verification_expires_at = ? WHERE email = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['success' => false, 'message' => 'DB prepare failed'];
    }

    $stmt->bind_param("ssss", $phone, $verification_code, $expiresAt, $email);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'DB update failed'];
    }
    $stmt->close();

    // Prepare POST data for Twilio
    $postData = http_build_query([
        'From' => $twilioPhoneNumber,
        'To'   => $phone,
        'Body' => $body,
    ]);

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL            => "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode("{$accountSid}:{$authToken}"),
        ],
        // NOTE: disabling verification is not recommended in production
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($curl);

    if (curl_errno($curl)) {
        $err = curl_error($curl);
        curl_close($curl);
        return ['success' => false, 'message' => 'Failed to send SMS: ' . $err];
    }

    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $responseBody = $response;
    curl_close($curl);

    if ($httpCode === 201) {
        return ['success' => true, 'message' => 'SMS sent successfully!'];
    }

    $responseData = json_decode($responseBody ?? '', true);
    $errorMessage = $responseData['message'] ?? 'Failed to send SMS.';

    return ['success' => false, 'message' => $errorMessage];
}