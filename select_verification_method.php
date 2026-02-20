<?php
session_start();

require_once "database.php";

// Get user id from session safely
$userId = $_SESSION["user_id"] ?? ($_SESSION["user"]["id"] ?? null);

// If we don't know which user this is, send them back
if (!$userId) {
    header("Location: registration.php");
    exit();
}

// Load PHPMailer
require 'PHPMailer-master/src/Exception.php';
require 'PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Twilio credentials
$accountSid        = 'ACed50809afda0163369b2505abc4354f7';
$authToken         = '3c6a61da6d5b335f41d05f9a7caef847';
$twilioPhoneNumber = '+12182616825';

// Get user's email, full_name and phone DIRECTLY from DB
$email     = '';
$full_name = '';
$userPhone = '';

$stmt = $conn->prepare("SELECT email, full_name, phone FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->bind_result($email, $full_name, $userPhone);
$stmt->fetch();
$stmt->close();

// If for some reason user row doesn't exist, bail out
if (empty($email)) {
    header("Location: registration.php");
    exit();
}

// Feedback message for UI
$feedbackMessage = '';
$feedbackClass   = '';

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["select_method"])) {
    $verificationMethod = $_POST["verification_method"] ?? 'email';
    $verification_code  = rand(100000, 999999);

    // Save verification code in DB for this user
    $stmt = $conn->prepare("UPDATE users SET verification_code = ? WHERE id = ?");
    $stmt->bind_param("si", $verification_code, $userId);
    $stmt->execute();
    $stmt->close();

    if ($verificationMethod === 'email') {

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'premium245.web-hosting.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'admin@festival-web.com';
            $mail->Password   = '!g3$~8tYju*D';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom('admin@festival-web.com', 'Athina E-Shop Verification');
            $mail->addAddress($email, $full_name);

            $mail->isHTML(true);
            $mail->Subject = 'Verify your email';
            $mail->Body    = "<p>Your verification code is: <b>$verification_code</b></p>";
            $mail->send();

            // Store the EXACT email + user id for verify.php
            $_SESSION["verification_email"]    = $email;
            $_SESSION["verification_user_id"]  = $userId;

            // Go directly to the email verification page
            header("Location: verify.php");
            exit();

        } catch (Exception $e) {
            $feedbackMessage = "Failed to send email. Please try again later.";
            $feedbackClass   = "danger";
        }

    } elseif ($verificationMethod === 'phone') {

        if (empty($userPhone)) {
            $feedbackMessage = "Phone number is missing. You cannot verify by phone.";
            $feedbackClass   = "danger";
        } else {
            $_SESSION["phone"]             = $userPhone;
            $_SESSION["verification_code"] = $verification_code;

            $body = "Your verification code is: $verification_code";

            $postData = http_build_query([
                'From' => $twilioPhoneNumber,
                'To'   => $userPhone,
                'Body' => $body,
            ]);

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL            => "https://api.twilio.com/2010-04-01/Accounts/$accountSid/Messages.json",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postData,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Authorization: Basic ' . base64_encode("$accountSid:$authToken"),
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($httpCode === 201) {
                header("Location: verify_phone.php");
                exit();
            } else {
                $responseData    = json_decode($response, true);
                $errorMessage    = $responseData['message'] ?? 'Failed to send SMS.';
                $feedbackMessage = $errorMessage;
                $feedbackClass   = "danger";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Select Verification Method</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="registration_page">

    <!-- Animated crochet background -->
    <div class="registration-bg"></div>
    <div class="registration-overlay"></div>

    <div class="wizard-box">
        <div class="wizard-header">
            <div class="wizard-logo">
                <img src="assets/images/athina-eshop-logo.png" alt="Athina E-Shop Logo">
            </div>
            <h3 class="mt-2">Select Verification Method</h3>
        </div>

        <?php if (!empty($feedbackMessage)): ?>
            <div class="alert alert-<?= htmlspecialchars($feedbackClass) ?>">
                <?= $feedbackMessage ?>
            </div>
        <?php endif; ?>

        <form method="post" action="select_verification_method.php" id="verification-form">
            <div class="wizard-content">
                <div class="form-group mb-3">
                    <label for="verification_method">Verification Method</label>
                    <select class="form-control" name="verification_method" id="verification_method" required>
                        <option value="email">Email Verification</option>
                        <option value="phone">Phone Verification</option>
                    </select>
                </div>

                <div class="form-group mb-3" id="email-display" style="display: none;">
                    <label>Registered Email</label>
                    <input type="text" class="form-control"
                           value="<?= htmlspecialchars($email) ?>" readonly>
                </div>

                <div class="form-group mb-3" id="phone-display" style="display: none;">
                    <label>Registered Phone</label>
                    <input type="text" class="form-control"
                           value="<?= htmlspecialchars($userPhone) ?>" readonly>
                </div>

                <div class="wizard-actions">
                    <button type="submit" class="btn btn-success w-100" name="select_method">Continue</button>
                </div>
            </div>
        </form>
    </div>

    <script
        src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function () {
            $('#verification_method').on('change', function () {
                const method = $(this).val();
                $('#email-display').toggle(method === 'email');
                $('#phone-display').toggle(method === 'phone');
            }).trigger('change');
        });
    </script>
</body>
</html>