<?php
session_start();
require_once "database.php";

// Optional feedback for Bootstrap alert
$feedbackMessage = '';
$feedbackClass   = '';

// If phone is passed via GET, store it
if (isset($_GET['phone'])) {
    $_SESSION["phone"] = $_GET['phone'];
}

// We must have phone & verification_code in session to be here
if (!isset($_SESSION["phone"]) || !isset($_SESSION["verification_code"])) {
    header("Location: registration.php");
    exit();
}

if (isset($_POST["verify"])) {
    $phone            = $_SESSION["phone"];
    $verificationCode = trim($_POST["verification_code"] ?? '');

    if ($verificationCode === '') {
        $feedbackMessage = "Please enter the verification code.";
        $feedbackClass   = "danger";
    } else {
        // Check if the verification code matches for this phone
        $sql  = "SELECT * FROM users WHERE phone = ? AND verification_code = ?";
        $stmt = mysqli_stmt_init($conn);

        if (mysqli_stmt_prepare($stmt, $sql)) {
            mysqli_stmt_bind_param($stmt, "ss", $phone, $verificationCode);
            mysqli_stmt_execute($stmt);
            $result   = mysqli_stmt_get_result($stmt);
            $rowCount = mysqli_num_rows($result);

            if ($rowCount > 0) {
                // Fetch user data
                $row = mysqli_fetch_assoc($result);

                // Mark user as verified (and clear verification_code if you want)
                $updateSql  = "UPDATE users SET is_verified = 1 WHERE phone = ?";
                $stmtUpdate = mysqli_stmt_init($conn);

                if (mysqli_stmt_prepare($stmtUpdate, $updateSql)) {
                    mysqli_stmt_bind_param($stmtUpdate, "s", $phone);
                    mysqli_stmt_execute($stmtUpdate);

                    // Store user data in session (login)
                    $_SESSION["user_id"]  = $row["id"];
                    $_SESSION["username"] = $row["username"]; // or full_name if you prefer
                    $_SESSION["email"]    = $row["email"];
                    $_SESSION["role"]     = $row["role"];

                    $_SESSION["user"] = [
                        "id"               => $row["id"],
                        "email"            => $row["email"],
                        "full_name"        => $row["full_name"],
                        "role"             => $row["role"],
                        "profile_complete" => $row["is_verified"],
                    ];

                    $_SESSION["verification_success"] = true;

                    // Redirect to home/dashboard
                    header("Location: index.php");
                    exit();
                } else {
                    $feedbackMessage = "Something went wrong while updating your account. Please try again.";
                    $feedbackClass   = "danger";
                }
            } else {
                $feedbackMessage = "Incorrect verification code.";
                $feedbackClass   = "danger";
            }
        } else {
            $feedbackMessage = "Something went wrong. Please try again later.";
            $feedbackClass   = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Phone</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="registration_page">

    <!-- Crochet GIF background + overlay (same as other pages) -->
    <div class="registration-bg"></div>
    <div class="registration-overlay"></div>

    <div class="wizard-box">
        <div class="wizard-header">
            <!-- Athina E-Shop crochet badge logo -->
            <div class="wizard-logo">
                <img src="assets/images/athina-eshop-logo.png" alt="Athina E-Shop Logo">
            </div>
            <h3 class="mt-2">Verify Your Phone Number</h3>
        </div>

        <?php if (!empty($feedbackMessage)): ?>
            <div class="alert alert-<?= htmlspecialchars($feedbackClass) ?>">
                <?= htmlspecialchars($feedbackMessage) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="verify_phone.php">
            <div class="wizard-content">
                <p class="mb-3 text-center">
                    We sent a verification code to your phone number
                    <br><strong><?= htmlspecialchars($_SESSION["phone"] ?? '') ?></strong>
                </p>

                <div class="form-group mb-3">
                    <label for="verification_code">Verification Code</label>
                    <input
                        type="text"
                        class="form-control"
                        id="verification_code"
                        name="verification_code"
                        placeholder="Enter your verification code"
                        required
                    >
                </div>

                <div class="wizard-actions">
                    <button type="submit" class="btn btn-success w-100" name="verify">
                        Verify Phone
                    </button>
                </div>
            </div>
        </form>
    </div>

</body>
</html>