<?php
session_start();
require_once "database.php";

$feedbackMessage = '';
$feedbackClass   = '';

// We must know which user is being verified
if (!isset($_SESSION["verification_user_id"])) {
    // If someone opens verify.php directly or session is lost, restart
    header("Location: registration.php");
    exit();
}

$verificationUserId = (int)$_SESSION["verification_user_id"];
$verificationEmail  = $_SESSION["verification_email"] ?? '';

// Fetch user for display (optional, but nice to show email)
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $verificationUserId);
$stmt->execute();
$result = $stmt->get_result();
$userRow = $result->fetch_assoc();
$stmt->close();

if (!$userRow) {
    // Somehow the user does not exist anymore
    $feedbackMessage = "User not found.";
    $feedbackClass   = "danger";
}

if (isset($_POST["verify"]) && $userRow) {
    $input_code = trim($_POST["verification_code"] ?? '');

    if ($input_code === '') {
        $feedbackMessage = "Please enter the verification code.";
        $feedbackClass   = "danger";
    } else {
        // Compare codes
        if (trim($userRow["verification_code"] ?? '') === $input_code) {

            // Mark user as verified & clear verification_code
            $updateSql  = "UPDATE users SET is_verified = 1, verification_code = NULL WHERE id = ?";
            $stmtUpdate = $conn->prepare($updateSql);
            $stmtUpdate->bind_param("i", $verificationUserId);
            $stmtUpdate->execute();
            $stmtUpdate->close();

            // Reload user row to get fresh data (is_verified updated)
            $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->bind_param("i", $verificationUserId);
            $stmt->execute();
            $result = $stmt->get_result();
            $userRow = $result->fetch_assoc();
            $stmt->close();

            // Log the user in
            $_SESSION["user_id"]  = $userRow["id"];
            $_SESSION["username"] = $userRow["username"]; // or full_name if you prefer
            $_SESSION["email"]    = $userRow["email"];
            $_SESSION["role"]     = $userRow["role"];

            $_SESSION["user"] = [
                "id"               => $userRow["id"],
                "email"            => $userRow["email"],
                "full_name"        => $userRow["full_name"],
                "role"             => $userRow["role"],
                "profile_complete" => $userRow["profile_complete"],
                "is_verified"      => $userRow["is_verified"],
            ];

            // Clear helper session values
            unset($_SESSION["verification_email"], $_SESSION["verification_user_id"]);

            // Redirect to dashboard
            header("Location: ../index.php");
            exit();
        } else {
            $feedbackMessage = "Incorrect verification code.";
            $feedbackClass   = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Your Email</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/styling/style.css">
</head>
<body class="registration_page">

    <!-- Crochet GIF background + overlay -->
    <div class="registration-bg"></div>
    <div class="registration-overlay"></div>

    <div class="wizard-box">
        <div class="wizard-header">
            <!-- Athina E-Shop crochet badge logo -->
            <div class="wizard-logo">
                <img src="../assets/images/athina-eshop-logo.png" alt="Athina E-Shop Logo">
            </div>
            <h3 class="mt-2">Email Verification</h3>
        </div>

        <?php if (!empty($feedbackMessage)): ?>
            <div class="alert alert-<?= htmlspecialchars($feedbackClass) ?>">
                <?= htmlspecialchars($feedbackMessage) ?>
            </div>
        <?php endif; ?>

        <form action="verify.php" method="post">
            <div class="wizard-content">

                <?php if ($userRow): ?>
                    <p class="mb-3 text-center">
                        We sent a verification code to<br>
                        <strong><?= htmlspecialchars($userRow["email"]) ?></strong>
                    </p>
                <?php endif; ?>

                <div class="form-group mb-3">
                    <label for="verification_code">Verification Code</label>
                    <input
                        type="text"
                        id="verification_code"
                        name="verification_code"
                        placeholder="Verification Code"
                        class="form-control"
                        required
                        maxlength="6"
                    >
                </div>

                <div class="wizard-actions">
                    <button type="submit" name="verify" class="btn btn-success w-100">
                        Verify Email
                    </button>
                </div>

            </div>
        </form>
    </div>

</body>
</html>
