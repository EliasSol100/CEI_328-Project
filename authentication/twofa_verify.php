<?php
session_start();
require_once "database.php";

if (!isset($_SESSION["temp_user_id"])) {
    header("Location: login.php");
    exit();
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

$userId        = $_SESSION["temp_user_id"];
$error         = '';
$infoMessage   = '';
$emailForUser  = '';
$mailerDetails = ''; // dev-only: holds PHPMailer error text

// Helper: send 2FA email and save code + expiry
function sendTwoFACode(mysqli $conn, int $userId, string &$errorOut, string &$mailerDetailsOut): ?string
{
    // Fetch user email + name
    $stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
    if (!$stmt) {
        $errorOut = "Something went wrong while preparing the 2FA query.";
        return null;
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user   = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $errorOut = "User not found for 2FA.";
        return null;
    }

    $email = $user["email"];
    $name  = $user["full_name"] ?: 'User';

    // Generate 6-digit code & expiry (+48 hours)
    $code       = str_pad((string)rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresObj = new DateTime("now", new DateTimeZone("UTC"));
    $expiresObj->modify("+48 hours");
    $expires = $expiresObj->format("Y-m-d H:i:s");

    // Save code + expiry
    $upd = $conn->prepare("UPDATE users SET twofa_code = ?, twofa_expires = ? WHERE id = ?");
    if (!$upd) {
        $errorOut = "Failed to update 2FA code.";
        return null;
    }
    $upd->bind_param("ssi", $code, $expires, $userId);
    $upd->execute();
    $upd->close();

    // Send email via PHPMailer (same settings as forgot_password.php)
    try {
        $mail = new PHPMailer(true);

        // $mail->SMTPDebug = 2; // uncomment for verbose debug in your PHP error log
        $mail->isSMTP();
        $mail->Host       = 'premium245.web-hosting.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'admin@festival-web.com';
        $mail->Password   = '!g3$~8tYju*D';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('admin@festival-web.com', 'Athina E-Shop');
        $mail->addAddress($email, $name);

        $mail->CharSet = 'UTF-8';
        $mail->isHTML(false);
        $mail->Subject = 'Your Athina E-Shop 2FA Code';
        $mail->Body    = "Dear $name,\n\n"
                       . "Your Two-Factor Authentication code is: $code\n\n"
                       . "This code is valid for 48 hours.\n\n"
                       . "If you did not attempt to log in, please secure your account.";

        if (!$mail->send()) {
            $errorOut        = "We couldn't send the 2FA email. Please try again later.";
            $mailerDetailsOut = $mail->ErrorInfo;
            return null;
        }

        // Success
        return $email;

    } catch (Exception $e) {
        $errorOut         = "We couldn't send the 2FA email. Please try again later.";
        $mailerDetailsOut = $e->getMessage();
        return null;
    }
}

// Decide if we should send/resend the code
if ($_SERVER["REQUEST_METHOD"] === "GET" || isset($_POST["resend"])) {

    // Try sending the code
    $emailSentTo = sendTwoFACode($conn, $userId, $error, $mailerDetails);

    if ($emailSentTo) {
        $emailForUser = $emailSentTo;
        if (isset($_POST["resend"])) {
            $infoMessage = "A new 2FA code has been sent to your email.";
        }
    } else {
        // If sending failed, we still want to show which email we *would* use (if possible)
        $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $emailForUser = $row['email'];
        }
        $stmt->close();
    }
} else {
    // POST verify, we just need email for display
    $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $emailForUser = $row['email'];
    }
    $stmt->close();
}

// Verification handler
if (isset($_POST["verify"])) {
    $code = trim($_POST["twofa_code"] ?? '');

    if ($code === '') {
        $error = "Please enter your 2FA code.";
    } else {
        $stmt = $conn->prepare("
            SELECT * 
            FROM users 
            WHERE id = ? 
              AND twofa_code = ? 
              AND twofa_expires > NOW()
        ");
        $stmt->bind_param("is", $userId, $code);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            // Clear code, but keep the expiry so we know 2FA is valid for 48h
            $clear = $conn->prepare("UPDATE users SET twofa_code = NULL, last_login = NOW() WHERE id = ?");
            $clear->bind_param("i", $userId);
            $clear->execute();
            $clear->close();

            // get previous last_login for session
            $prevLogin = null;
            if (!empty($user['last_login'])) {
                $prevLogin = $user['last_login'];
            }

            $_SESSION["user"] = [
                "id"         => $user["id"],
                "email"      => $user["email"],
                "full_name"  => $user["full_name"],
                "role"       => $user["role"],
                "last_login" => $prevLogin
            ];
            $_SESSION["user_id"] = $user["id"];
            $_SESSION["role"]    = $user["role"];

            unset($_SESSION['temp_user_id']);

            header("Location: ../index.php");
            exit();
        } else {
            $error = "Invalid or expired code.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>2FA Verification - Athina E-Shop</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet"
    >
    <link rel="stylesheet" href="../assets/styling/style.css">
    <link rel="stylesheet" href="../assets/styling/authentication.css">
</head>
<body class="registration_page">

    <div class="wizard-box">
        <div class="wizard-header">
            <div class="wizard-logo">
                <img src="../assets/images/athina-eshop-logo.png" alt="Athina E-Shop Logo">
            </div>
            <h3 class="mt-2">Two-Factor Authentication</h3>
            <p class="wizard-subtitle mb-0">
                We send a 6-digit code to your email. For security, this code is valid for 48 hours.<br>
                <?php if ($emailForUser): ?>
                    Email: <strong><?= htmlspecialchars($emailForUser) ?></strong>
                <?php endif; ?>
            </p>
        </div>

        <div class="wizard-content">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error) ?><br>
                    <?php if (!empty($mailerDetails)): ?>
                        <small class="text-muted">
                            <!-- DEV ONLY: remove this line in production -->
                            Mailer detail: <?= htmlspecialchars($mailerDetails) ?>
                        </small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($infoMessage)): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($infoMessage) ?>
                </div>
            <?php endif; ?>

            <form action="twofa_verify.php" method="post">
                <div class="form-group mb-3">
                    <label for="twofa_code">2FA Code</label>
                    <input
                        type="text"
                        name="twofa_code"
                        id="twofa_code"
                        class="form-control"
                        placeholder="Enter your 6-digit code"
                        maxlength="6"
                        required
                    >
                </div>

                <div class="wizard-actions mb-2">
                    <button type="submit" name="verify" class="btn btn-success w-100">
                        Verify Code
                    </button>
                </div>
            </form>

            <form action="twofa_verify.php" method="post" class="mt-2">
                <button type="submit" name="resend" class="btn btn-outline-secondary w-100">
                    Resend Code
                </button>
            </form>

            <div class="form-footer text-center mt-3">
                <a href="login.php">Back to Login</a>
            </div>
        </div>
    </div>

</body>
</html>