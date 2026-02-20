<?php
session_start();
require 'PHPMailer-master/src/Exception.php';
require 'PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error   = "";
$success = "";

if (isset($_POST["submit"])) {
    $email = trim($_POST["email"]);
    require_once "database.php";

    try {
        // Check if the email exists in the database
        $sql  = "SELECT * FROM users WHERE email = ?";
        $stmt = mysqli_stmt_init($conn);
        if (!mysqli_stmt_prepare($stmt, $sql)) {
            throw new Exception("Something went wrong.");
        }
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user   = mysqli_fetch_assoc($result);

        if (!$user) {
            throw new Exception("Email not found!");
        }

        // Generate a unique token for password reset
        $resetToken = bin2hex(random_bytes(50));
        $expiry     = gmdate("Y-m-d H:i:s", time() + 3600); // Token valid for 1 hour (UTC)

        // Store the token in the database
        $sql  = "UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE email = ?";
        $stmt = mysqli_stmt_init($conn);
        if (!mysqli_stmt_prepare($stmt, $sql)) {
            throw new Exception("Something went wrong.");
        }
        mysqli_stmt_bind_param($stmt, "sss", $resetToken, $expiry, $email);
        mysqli_stmt_execute($stmt);

        // Send password reset email
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->SMTPDebug  = 0;
            $mail->isSMTP();
            $mail->Host       = 'premium245.web-hosting.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'admin@festival-web.com';
            $mail->Password   = '!g3$~8tYju*D';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            // Recipients
            $mail->setFrom('admin@festival-web.com', 'Athina E-Shop');
            $mail->addAddress($email, $user["full_name"]);

            // Content
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(false);

            $mail->Subject = 'Password Reset Request';

            $userName   = !empty($user['full_name']) ? $user['full_name'] : 'User';
            $resetLink  = "http://localhost/athina-eshop/reset_password.php?token=$resetToken";

            $mail->Body = "Dear $userName,\n\n"
                . "We received a request to reset your password. Click the link below to change it:\n"
                . "$resetLink\n\n"
                . "If you did not request this change, please ignore this email.";

            if (!$mail->send()) {
                throw new Exception("Email could not be sent. Error: " . $mail->ErrorInfo);
            }

            $success = "The password reset link has been sent to your email. "
                     . "Please check your inbox or your Spam folder if you don’t see it.";
        } catch (Exception $e) {
            throw new Exception("Failed to send email: " . $e->getMessage());
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password - Athina E-Shop</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Custom Styling -->
    <link rel="stylesheet" href="style.css">
</head>

<body class="registration_page">

    <!-- Crochet GIF background + overlay -->
    <div class="registration-bg"></div>
    <div class="registration-overlay"></div>

    <div class="wizard-box">
        <div class="wizard-header text-center">
            <!-- Athina E-Shop crochet badge logo -->
            <div class="wizard-logo">
                <img src="assets/images/athina-eshop-logo.png" alt="Athina E-Shop Logo">
            </div>
            <h3 class="mt-2">Password Recovery</h3>
        </div>

        <div class="wizard-content">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php else: ?>
                <form action="forgot_password.php" method="post">
                    <div class="form-group mb-3">
                        <label for="email">Email Address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control"
                            placeholder="e.g. user@example.com"
                            required
                        >
                    </div>
                    <div class="wizard-actions mb-2">
                        <button type="submit" name="submit" class="btn btn-success w-100">
                            Send Link
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>