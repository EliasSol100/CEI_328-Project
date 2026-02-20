<?php
// Database connection
require_once "database.php";

// Twilio credentials
$accountSid = 'ACed50809afda0163369b2505abc4354f7'; // Your Twilio Account SID
$authToken = 'a0e8ced97d0ab07db55e20f99fa7121e'; // Your Twilio Auth Token
$twilioPhoneNumber = '+12182616825'; // Your Twilio phone number

// Get data from the AJAX request
$email = $_POST['email']; // Get email from the request
$phone = $_POST['phone']; // Get phone number from the request
$body = $_POST['message']; // Get the SMS message body
$verification_code = $_POST['verification_code']; // Get the verification code
 
$_SESSION["phone"] = $phone;
 
// Update user data in the database (only phone and verification_code)
$sql = "UPDATE users SET phone = ?, verification_code = ? WHERE email = ?";
$stmt = mysqli_stmt_init($conn);
if (mysqli_stmt_prepare($stmt, $sql)) {
    mysqli_stmt_bind_param($stmt, "sss", $phone, $verification_code, $email);
    mysqli_stmt_execute($stmt);

    // Prepare the POST data for Twilio
    $postData = http_build_query([
        'From' => $twilioPhoneNumber,
        'To' => $phone,
        'Body' => $body,
    ]);

    // Initialize cURL
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.twilio.com/2010-04-01/Accounts/$accountSid/Messages.json",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode("$accountSid:$authToken"),
        ],
        CURLOPT_SSL_VERIFYPEER => false, // Disable SSL verification (not recommended)
        CURLOPT_SSL_VERIFYHOST => false, // Disable SSL host verification (not recommended)
    ]);

    // Execute the request
    $response = curl_exec($curl);

    // Check for errors
    if (curl_errno($curl)) {
        echo json_encode(['success' => false, 'message' => 'Failed to send SMS: ' . curl_error($curl)]);
    } else {
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($httpCode === 201) {
            echo json_encode(['success' => true, 'message' => 'SMS sent successfully!', 'verification_code' => $verification_code]);
        } else {
            $responseData = json_decode($response, true);
            $errorMessage = $responseData['message'] ?? 'Failed to send SMS.';
            echo json_encode(['success' => true, 'message' => $errorMessage, 'verification_code' => $verification_code]);
        }
    }

    // Close cURL
    curl_close($curl);
} else {
    die("Something went wrong with the database query.");
}
?>
