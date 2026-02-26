<?php
session_start();
require_once "database.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../PHPMailer-master/src/Exception.php';
require '../PHPMailer-master/src/PHPMailer.php';
require '../PHPMailer-master/src/SMTP.php';

$feedbackMessage = '';
$feedbackClass   = 'danger';

// You must have a user in session at this point
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$userId = (int) $_SESSION["user_id"];

// Load user email + phone
$stmt = $conn->prepare("SELECT email, phone, full_name, role FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$userRow = $result->fetch_assoc();
$stmt->close();

if (!$userRow) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

$email     = $userRow["email"];
$userPhone = $userRow["phone"];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["select_method"])) {
    $method = $_POST["verification_method"] ?? "email";

    // 6-digit code (shared for email & phone)
    $verification_code = random_int(100000, 999999);

    if ($method === "email") {
        // Store code on the user
        $stmt = $conn->prepare("UPDATE users SET verification_code = ? WHERE id = ?");
        $stmt->bind_param("si", $verification_code, $userId);
        $stmt->execute();
        $stmt->close();

        // Send email with the code
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = 'premium245.web-hosting.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'admin@festival-web.com';
            $mail->Password   = '!g3$~8tYju*D';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom('admin@festival-web.com', 'Athina E-Shop');
            $mail->addAddress($email, $userRow["full_name"]);
            $mail->isHTML(true);
            $mail->Subject = "Your Athina E-Shop Verification Code";
            $mail->Body    = "<p>Your verification code is: <b>{$verification_code}</b></p>";

            $mail->send();

            header("Location: verify.php");
            exit();
        } catch (Exception $e) {
            $feedbackMessage = "We couldn't send the verification email. Please try again.";
            $feedbackClass   = "danger";
        }

    } elseif ($method === "phone") {
        // Use shared SMS helper in send_sms.php
        require_once "send_sms.php";

        // If user didn't have a phone yet, we still try with DB value
        $phoneToUse = $userPhone;

        if (empty($phoneToUse)) {
            $feedbackMessage = "No phone number is saved on your profile.";
            $feedbackClass   = "danger";
        } else {
            $smsResult = sendVerificationSms(
                $conn,
                $email,
                $phoneToUse,
                (string)$verification_code,
                "Your Athina E-Shop verification code is {$verification_code}"
            );

            if (!$smsResult["success"]) {
                $feedbackMessage = "We couldn't send the SMS: " . $smsResult["message"];
                $feedbackClass   = "danger";
            } else {
                // Remember phone in session just for display text on verify_phone.php
                $_SESSION["phone"] = $phoneToUse;

                header("Location: verify_phone.php");
                exit();
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/styling/style.css">
    <link rel="stylesheet" href="../assets/styling/authentication.css">
</head>
<body class="registration_page">

    <div class="wizard-box">
        <div class="wizard-header">
            <div class="wizard-logo">
                <img src="../assets/images/athina-eshop-logo.png" alt="Athina E-Shop Logo">
            </div>
            <h3 class="mt-2">Verification Method</h3>
            <p class="wizard-subtitle mb-0">
                Choose how you’d like to confirm your new account.
            </p>
        </div>

        <?php if (!empty($feedbackMessage)): ?>
            <div class="alert alert-<?= htmlspecialchars($feedbackClass) ?>">
                <?= htmlspecialchars($feedbackMessage) ?>
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

                <div class="form-group mb-3" id="email-display">
                    <label>Registered Email</label>
                    <input type="text" class="form-control"
                           value="<?= htmlspecialchars($email) ?>" readonly>
                </div>

                <div class="form-group mb-3" id="phone-display">
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

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
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