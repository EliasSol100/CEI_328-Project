<?php
session_start();
require_once "database.php";

$error   = null;
$success = null;
$token   = null;

if (isset($_GET["token"])) {
    $token = trim($_GET["token"]); // Trim the token
    error_log("Token from URL: $token");

    // Check if the token is valid and not expired (UTC)
    $sql  = "SELECT * FROM users WHERE BINARY reset_token = ? AND reset_token_expiry > UTC_TIMESTAMP()";
    $stmt = mysqli_stmt_init($conn);

    if (mysqli_stmt_prepare($stmt, $sql)) {
        mysqli_stmt_bind_param($stmt, "s", $token);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user   = mysqli_fetch_assoc($result);

        if ($user) {
            error_log("Token found in database: " . $user['reset_token']);
            error_log("Token expiry: " . $user['reset_token_expiry']);

            if (isset($_POST["submit"])) {
                $newPassword     = $_POST["new_password"] ?? '';
                $confirmPassword = $_POST["confirm_password"] ?? '';

                // 1. Check passwords match
                if ($newPassword !== $confirmPassword) {
                    $error = "Passwords do not match!";
                } else {
                    // 2. Enforce complexity rules
                    if (
                        strlen($newPassword) < 8 ||
                        !preg_match('/[A-Z]/', $newPassword) ||
                        !preg_match('/[0-9]/', $newPassword) ||
                        !preg_match('/[\W_]/', $newPassword)
                    ) {
                        $error = "Password must be at least 8 characters and include an uppercase letter, a number, and a symbol.";
                    } else {
                        // 3. Hash the new password
                        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                        // 4. Update the user's password and clear the reset token
                        $sql  = "UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE reset_token = ?";
                        $stmt = mysqli_stmt_init($conn);
                        if (mysqli_stmt_prepare($stmt, $sql)) {
                            mysqli_stmt_bind_param($stmt, "ss", $newPasswordHash, $token);
                            mysqli_stmt_execute($stmt);

                            $success = "Your password has been successfully changed. You can now <a href='login.php'>log in here</a> with your new password.";
                        } else {
                            $error = "Something went wrong. Please try again later.";
                        }
                    }
                }
            }
        } else {
            $error = "The link has possibly expired. Please request a new password reset link.";
        }
    } else {
        $error = "Something went wrong. Please try again later.";
    }
} else {
    $error = "No reset link was provided.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Password Reset - Athina E-Shop</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Custom Styling -->
    <link rel="stylesheet" href="assets/styling/style.css">
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
            <h3 class="mt-2">Password Reset</h3>
        </div>

        <?php if (isset($error) && $error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (isset($success) && $success): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php elseif ($token): ?>
            <form action="reset_password.php?token=<?= htmlspecialchars($token) ?>" method="post" id="reset-password-form">
                <div class="wizard-content">

                    <div class="form-group mb-3">
                        <label for="new_password">New Password</label>
                        <div class="input-group">
                            <input type="password" name="new_password" id="new_password" class="form-control" required>
                            <span class="input-group-text toggle-password" data-target="#new_password">
                                <i class="bi bi-eye"></i>
                            </span>
                        </div>

                        <!-- Password rules checklist (same logic as complete_profile.php) -->
                        <ul class="password-checklist mt-2" style="list-style: none; padding-left: 0; font-size: 14px;">
                            <li id="check-length"><span class="text-danger">âœ–</span> At least 8 characters</li>
                            <li id="check-uppercase"><span class="text-danger">âœ–</span> At least 1 uppercase letter</li>
                            <li id="check-number"><span class="text-danger">âœ–</span> At least 1 number</li>
                            <li id="check-symbol"><span class="text-danger">âœ–</span> At least 1 symbol</li>
                        </ul>
                    </div>

                    <div class="form-group mb-3">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                            <span class="input-group-text toggle-password" data-target="#confirm_password">
                                <i class="bi bi-eye"></i>
                            </span>
                        </div>
                    </div>

                    <div class="wizard-actions mt-3">
                        <button type="submit" name="submit" class="btn btn-success w-100">
                            Reset Password
                        </button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- jQuery -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>

    <script>
        $(document).ready(function () {
            // Toggle password visibility
            $(".toggle-password").on("click", function () {
                const input = $($(this).data("target"));
                const icon  = $(this).find("i");
                const type  = input.attr("type") === "password" ? "text" : "password";
                input.attr("type", type);
                icon.toggleClass("bi-eye bi-eye-slash");
            });

            // Password checklist updates (mirrors complete_profile.php)
            $("#new_password").on("input", function () {
                const val = $(this).val();

                const checks = {
                    length: val.length >= 8,
                    uppercase: /[A-Z]/.test(val),
                    number: /[0-9]/.test(val),
                    symbol: /[\W_]/.test(val)
                };

                $("#check-length").html((checks.length ? 'âœ…' : '<span class="text-danger">âœ–</span>') + ' At least 8 characters');
                $("#check-uppercase").html((checks.uppercase ? 'âœ…' : '<span class="text-danger">âœ–</span>') + ' At least 1 uppercase letter');
                $("#check-number").html((checks.number ? 'âœ…' : '<span class="text-danger">âœ–</span>') + ' At least 1 number');
                $("#check-symbol").html((checks.symbol ? 'âœ…' : '<span class="text-danger">âœ–</span>') + ' At least 1 symbol');
            });
        });
    </script>
</body>
</html>
