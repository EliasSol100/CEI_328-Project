<?php
session_start();
require_once __DIR__ . "/database.php";

// PHPMailer from project ROOT (one level above /authentication)
require_once __DIR__ . "/../PHPMailer-master/src/Exception.php";
require_once __DIR__ . "/../PHPMailer-master/src/PHPMailer.php";
require_once __DIR__ . "/../PHPMailer-master/src/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error   = "";
$success = "";

/**
 * Helper: Build the reset link URL
 */
function buildResetLink(string $token): string
{
    // Adjust this to your local / live URL if needed
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Path to reset_password.php inside /authentication
    $path   = '/athina-eshop/authentication/reset_password.php';

    return "{$scheme}://{$host}{$path}?token={$token}";
}

if (isset($_POST["submit"])) {
    $email = trim($_POST["email"] ?? "");

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        // 1) Look up user by email
        $stmt = $conn->prepare("SELECT id, full_name, email FROM users WHERE email = ? LIMIT 1");
        if (!$stmt) {
            $error = "Something went wrong. Please try again later.";
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user   = $result->fetch_assoc();
            $stmt->close();

            // For security: respond the same whether user exists or not.
            // Only send email if user is real.
            if ($user) {
                // 2) Create token + hashed token
                $resetToken = bin2hex(random_bytes(32));
                $tokenHash  = hash('sha256', $resetToken);
                // 🔁 20-minute validity instead of 1 hour
                $expiresAt  = date('Y-m-d H:i:s', time() + 20 * 60); // valid for 20 minutes

                // 3) Store token in password_resets table
                // Optional: delete any existing reset records for this email
                $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
                if ($del) {
                    $del->bind_param("s", $email);
                    $del->execute();
                    $del->close();
                }

                $ins = $conn->prepare("
                    INSERT INTO password_resets (email, token_hash, expires_at)
                    VALUES (?, ?, ?)
                ");
                if ($ins) {
                    $ins->bind_param("sss", $email, $tokenHash, $expiresAt);
                    $ins->execute();
                    $ins->close();
                } else {
                    $error = "Something went wrong while creating the reset link. Please try again.";
                }

                if (empty($error)) {
                    // 4) Send reset email via PHPMailer
                    $resetLink = buildResetLink($resetToken);
                    $mail      = new PHPMailer(true);

                    try {
                        // --- SMTP settings (update if needed) ---
                        $mail->SMTPDebug  = 0; // set to 2 while debugging
                        $mail->isSMTP();
                        $mail->Host       = 'premium245.web-hosting.com';  // your SMTP host
                        $mail->SMTPAuth   = true;
                        $mail->Username   = 'admin@festival-web.com';      // your SMTP username
                        $mail->Password   = '!g3$~8tYju*D';                // your SMTP password
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = 587;

                        // --- Recipients ---
                        $mail->setFrom('admin@festival-web.com', 'Athina E-Shop');
                        $recipientName = !empty($user['full_name']) ? $user['full_name'] : 'Customer';
                        $mail->addAddress($email, $recipientName);

                        // --- Content ---
                        $mail->CharSet = 'UTF-8';
                        $mail->isHTML(false);
                        $mail->Subject = 'Athina E-Shop - Password Reset';

                        // 🔁 Email text mentions 20 minutes
                        $mail->Body =
                            "Dear {$recipientName},\n\n" .
                            "We received a request to reset the password for your Athina E-Shop account.\n\n" .
                            "To choose a new password, please click the link below (or copy it into your browser):\n" .
                            "{$resetLink}\n\n" .
                            "This link is valid for 20 minutes.\n\n" .
                            "If you did not request this change, you can safely ignore this email.\n\n" .
                            "Best regards,\n" .
                            "Athina E-Shop";

                        if (!$mail->send()) {
                            // If mail failed, treat as generic error
                            $error = "We couldn't send the reset email right now. Please try again later.";
                        }
                    } catch (Exception $e) {
                        // PHPMailer threw an exception
                        $error = "We couldn't send the reset email right now. Please try again later.";
                    }
                }
            }

            // 5) Generic message (even if user not found)
            if (empty($error)) {
                $success = "If this email is registered with Athina E-Shop, we've sent a password reset link. 
                            Please check your inbox and Spam folder.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"> 
    <title>Forgot Password - Athina E-Shop</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="../assets/styling/style.css">
    <link rel="stylesheet" href="../assets/styling/authentication.css">
</head>

<body class="registration_page">

    <div class="wizard-box">
        <div class="wizard-header text-center">
            <div class="wizard-logo">
                <img src="../assets/images/athina-eshop-logo.png" alt="Athina E-Shop Logo">
            </div>
            <h3 class="mt-2">Password Recovery</h3>
            <p class="wizard-subtitle mb-0">
                Enter your email and we’ll send you a reset link (valid for 20 minutes).
            </p>
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
                            Send Reset Link
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <div class="form-footer">
                <a href="login.php">Back to Login</a>
            </div>
        </div>
    </div>

</body>
</html>