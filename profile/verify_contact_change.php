<?php
session_start();
require_once "../authentication/database.php";

if (!isset($_SESSION["user"]) || !isset($_SESSION["user"]["id"])) {
    header("Location: ../authentication/login.php");
    exit();
}

$pending = $_SESSION["contact_change_pending"] ?? null;
if (!$pending || $pending["user_id"] != $_SESSION["user"]["id"]) {
    header("Location: account.php?tab=settings");
    exit();
}

$userId = (int) $_SESSION["user"]["id"];
$successMessage = "";
$errorMessage   = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $codeInput = trim($_POST["code"] ?? "");

    if (!$codeInput) {
        $errorMessage = "Please enter the verification code.";
    } else {
        $stmt = $conn->prepare("
            SELECT verification_code, verification_expires_at
            FROM users WHERE userID = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if (!$row || !$row["verification_code"]) {
            $errorMessage = "No verification request found.";
        } else {
            $now     = time();
            $expires = strtotime($row["verification_expires_at"] ?? '');

            if ($now > $expires) {
                $errorMessage = "This code has expired. Please go back and try again.";
            } elseif ($codeInput !== $row["verification_code"]) {
                $errorMessage = "The code you entered is not correct.";
            } else {
                // Update email/phone
                $stmt = $conn->prepare("
                    UPDATE users
                    SET email = ?, phone = ?, verification_code = NULL, verification_expires_at = NULL
                    WHERE userID = ?
                ");
                $stmt->bind_param("ssi", $pending["new_email"], $pending["new_phone"], $userId);
                $stmt->execute();
                $stmt->close();

                // Refresh session
                $_SESSION["user"]["email"] = $pending["new_email"];

                unset($_SESSION["contact_change_pending"]);

                // Redirect back to settings with success flag
                header("Location: account.php?tab=settings&updated=1");
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
    <title>Verify Contact Change - Athina E-Shop</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap (for buttons / alerts baseline) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Athina auth styling -->
    <link rel="stylesheet" href="../assets/styling/authentication.css">
</head>
<body class="registration_page">

<div class="wizard-box verify-contact-box">
    <div class="wizard-header">
        <div class="wizard-logo">
            <img src="../assets/images/athina-eshop-logo.png" alt="Athina E-Shop Logo">
        </div>
        <h3>Verify Contact Change</h3>
        <p class="wizard-subtitle">
            We sent a 6-digit code to your current email:
            <strong><?= htmlspecialchars($pending["code_sent_to"]) ?></strong>.<br>
            Enter it below to confirm your changes.
        </p>
    </div>

    <?php if ($errorMessage): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <form method="post" class="wizard-content auth-email-form">
        <div class="form-group">
            <label for="code">Verification Code</label>
            <input
                type="text"
                id="code"
                name="code"
                class="form-control"
                maxlength="6"
                pattern="\d*"
                inputmode="numeric"
                placeholder="Enter 6-digit code"
                required
            >
        </div>

        <div class="wizard-actions">
            <button type="submit" class="btn btn-primary w-100">
                Confirm
            </button>
        </div>
    </form>

    <div class="form-footer">
        <a href="account.php?tab=settings">&larr; Back to Settings</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>