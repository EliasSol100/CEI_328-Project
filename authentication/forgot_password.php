<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/../include/security.php";
require_once __DIR__ . "/../include/auth_branding.php";

require_once __DIR__ . "/../PHPMailer-master/src/Exception.php";
require_once __DIR__ . "/../PHPMailer-master/src/PHPMailer.php";
require_once __DIR__ . "/../PHPMailer-master/src/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error   = "";
$success = "";
$authLogoUrl = app_auth_logo_url($conn, '../');

function buildResetLink(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/authentication/forgot_password.php');
    $basePath   = rtrim(dirname($scriptName), '/');
    if ($basePath === '') {
        $basePath = '/authentication';
    }
    $path = $basePath . '/reset_password.php';

    return "{$scheme}://{$host}{$path}?token=" . rawurlencode($token);
}

if (isset($_POST["submit"])) {
    app_require_csrf(false, "Invalid request token. Please refresh and try again.");
    $email = trim($_POST["email"] ?? "");

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {

        $stmt = $conn->prepare("SELECT userID, full_name, email FROM users WHERE email = ? LIMIT 1");
        if (!$stmt) {
            $error = "Something went wrong. Please try again later.";
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user   = $result->fetch_assoc();
            $stmt->close();

            if ($user) {

                $resetToken = bin2hex(random_bytes(32));
                $tokenHash  = hash('sha256', $resetToken);

                $expiresAt  = date('Y-m-d H:i:s', time() + 20 * 60);

                $conn->query("
                    CREATE TABLE IF NOT EXISTS password_resets (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        email VARCHAR(255) NOT NULL,
                        token_hash VARCHAR(255) NOT NULL,
                        expires_at DATETIME NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        KEY idx_password_resets_email (email),
                        KEY idx_password_resets_token_hash (token_hash)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");

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

                    $resetLink = buildResetLink($resetToken);
                    $mail      = new PHPMailer(true);

                    try {
                        $mail->SMTPDebug  = 0;
                        $mail->isSMTP();
                        $mail->Host       = 'premium245.web-hosting.com';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = 'admin@festival-web.com';
                        $mail->Password   = '!g3$~8tYju*D';
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = 587;
                        $mail->Timeout    = 20;
                        $mail->SMTPOptions = [
                            'ssl' => [
                                'verify_peer' => false,
                                'verify_peer_name' => false,
                                'allow_self_signed' => true,
                            ],
                        ];

                        $mail->setFrom('admin@festival-web.com', 'Athina E-Shop');
                        $recipientName = !empty($user['full_name']) ? $user['full_name'] : 'Customer';
                        $mail->addAddress($email, $recipientName);

                        $mail->CharSet = 'UTF-8';
                        $mail->isHTML(false);
                        $mail->Subject = 'Athina E-Shop - Password Reset';

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

                            $error = "We couldn't send the reset email right now. Please try again later.";
                        }
                    } catch (Exception $e) {

                        $error = "We couldn't send the reset email right now. Please try again later.";
                    }
                }
            }

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
                <img src="<?= htmlspecialchars($authLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Creations by Athina logo">
            </div>
            <h3 class="mt-2">Password Recovery</h3>
            <p class="wizard-subtitle mb-0">
                Enter your email and we'll send you a reset link (valid for 20 minutes).
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
                    <?= app_csrf_input() ?>
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
