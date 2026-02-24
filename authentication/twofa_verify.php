<?php
session_start();
require_once "database.php";

// If there is no pending 2FA session, go back to login
if (!isset($_SESSION["temp_user_id"])) {
    header("Location: login.php");
    exit();
}

$error = '';

if (isset($_POST["verify"])) {
    $code    = trim($_POST["twofa_code"] ?? '');
    $user_id = $_SESSION["temp_user_id"];

    if ($code === '') {
        $error = "Please enter your 2FA code.";
    } else {
        // Check that code matches and is not expired
        $stmt = $conn->prepare("
            SELECT * 
            FROM users 
            WHERE id = ? 
              AND twofa_code = ? 
              AND twofa_expires > NOW()
        ");
        $stmt->bind_param("is", $user_id, $code);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            // Clear 2FA code after successful verification (optional but safer)
            $clear = $conn->prepare("UPDATE users SET twofa_code = NULL WHERE id = ?");
            $clear->bind_param("i", $user_id);
            $clear->execute();
            $clear->close();

            // Restore last_login (same pattern as in login.php if you want)
            $prevLogin  = null;
            $getLogin   = $conn->prepare("SELECT last_login FROM users WHERE id = ?");
            $getLogin->bind_param("i", $user['id']);
            $getLogin->execute();
            $loginRes = $getLogin->get_result();
            if ($row = $loginRes->fetch_assoc()) {
                $prevLogin = $row['last_login'];
            }
            $getLogin->close();

            // Update last_login to NOW
            $updateLogin = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $updateLogin->bind_param("i", $user['id']);
            $updateLogin->execute();
            $updateLogin->close();

            // Log user in (same structure as login.php)
            $_SESSION["user"] = [
                "id"         => $user["id"],
                "email"      => $user["email"],
                "full_name"  => $user["full_name"],
                "role"       => $user["role"],
                "last_login" => $prevLogin
            ];
            $_SESSION["user_id"] = $user["id"];
            $_SESSION["role"]    = $user["role"];

            // Clean up temp session flags
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
    <link rel="stylesheet" href="assets/styling/style.css">
</head>
<body class="registration_page">

    <!-- Crochet GIF background + overlay -->
    <div class="registration-bg"></div>
    <div class="registration-overlay"></div>

    <div class="wizard-box">
        <div class="wizard-header">
            <!-- Athina E-Shop crochet badge logo -->
            <div class="wizard-logo">
                <img src="assets/images/athina-eshop-logo.png" alt="Athina E-Shop Logo">
            </div>
            <h3 class="mt-2">Two-Factor Authentication</h3>
            <p class="text-muted mb-0" style="font-size: 0.9rem;">
                We sent a 6-digit code to your email. <br>
                For security, this code is valid for 48 hours.
            </p>
        </div>

        <div class="wizard-content">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error) ?>
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

                <div class="wizard-actions">
                    <button type="submit" name="verify" class="btn btn-success w-100">
                        Verify Code
                    </button>
                </div>
            </form>

            <div class="form-footer text-center mt-3">
                <a href="login.php">Back to Login</a>
            </div>
        </div>
    </div>

</body>
</html>
