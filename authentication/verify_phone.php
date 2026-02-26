<?php
session_start();
require_once "database.php";
require_once "send_sms.php";

$feedbackMessage = '';
$feedbackClass   = 'danger';
$userRow         = null;

// Must have logged-in user (created just before verification)
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$userId = (int) $_SESSION["user_id"];

// Load current user
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
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

// If already verified, no need to be here
if (!empty($userRow["is_verified"]) && (int)$userRow["is_verified"] === 1) {
    header("Location: ../index.php");
    exit();
}

$displayPhone = $_SESSION["phone"] ?? $userRow["phone"] ?? '';

// Handle POST actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Switch back to email verification
    if (isset($_POST["switch_to_email"])) {
        header("Location: verify.php");
        exit();
    }

    // Resend SMS code
    if (isset($_POST["resend_code"])) {
        if (!empty($displayPhone) && !empty($userRow["email"])) {
            $newCode = (string) random_int(100000, 999999);
            $body    = "Your Athina E-Shop verification code is {$newCode}. It is valid for 20 minutes.";

            $result = sendVerificationSms(
                $conn,
                $userRow["email"],
                $displayPhone,
                $newCode,
                $body
            );

            if ($result['success']) {
                $feedbackMessage = "A new verification code has been sent via SMS. It will be valid for 20 minutes.";
                $feedbackClass   = "success";

                // Reload user row to pick up the new expiry time
                $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $res      = $stmt->get_result();
                $userRow  = $res->fetch_assoc();
                $stmt->close();
            } else {
                $feedbackMessage = "We couldn't resend the SMS code: " . $result['message'];
                $feedbackClass   = "danger";
            }
        } else {
            $feedbackMessage = "We couldn't find a phone number for your account.";
            $feedbackClass   = "danger";
        }
    }

    // Verify SMS code
    if (isset($_POST["verify"])) {
        $code = trim($_POST["verification_code"] ?? '');

        if ($code === '') {
            $feedbackMessage = "Please enter your verification code.";
            $feedbackClass   = "danger";
        } else {
            // Load current verification_code + expiry
            $stmt = $conn->prepare("
                SELECT id, verification_code, verification_expires_at, email, full_name,
                       role, profile_complete, is_verified
                FROM users
                WHERE id = ?
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $res      = $stmt->get_result();
            $matchRow = $res->fetch_assoc();
            $stmt->close();

            if (!$matchRow || empty($matchRow["verification_code"])) {
                $feedbackMessage = "There is no active verification code. Please click 'Resend Code'.";
                $feedbackClass   = "danger";
            } else {
                $storedCode = $matchRow["verification_code"];
                $expiresAt  = $matchRow["verification_expires_at"] ?? null;

                $isExpired = false;
                if (!empty($expiresAt)) {
                    $isExpired = (strtotime($expiresAt) < time());
                }

                if ($isExpired) {
                    $feedbackMessage = "Your verification code has expired. Please click 'Resend Code' to receive a new one.";
                    $feedbackClass   = "danger";
                } elseif ($storedCode !== $code) {
                    $feedbackMessage = "Invalid verification code. Please try again.";
                    $feedbackClass   = "danger";
                } else {
                    // Mark as verified & clear verification_code and expiry
                    $stmt = $conn->prepare("
                        UPDATE users
                        SET is_verified = '1',
                            verification_code = NULL,
                            verification_expires_at = NULL
                        WHERE id = ?
                    ");
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $stmt->close();

                    // Reload user to refresh session
                    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $res         = $stmt->get_result();
                    $updatedUser = $res->fetch_assoc();
                    $stmt->close();

                    if ($updatedUser) {
                        $_SESSION["user"] = [
                            "id"               => $updatedUser["id"],
                            "email"            => $updatedUser["email"],
                            "full_name"        => $updatedUser["full_name"],
                            "role"             => $updatedUser["role"] ?? "user",
                            "profile_complete" => $updatedUser["profile_complete"],
                            "is_verified"      => $updatedUser["is_verified"],
                        ];

                        $_SESSION["user_id"] = $updatedUser["id"];
                        $_SESSION["role"]    = $updatedUser["role"] ?? "user";
                    }

                    // Done → go home
                    header("Location: ../index.php");
                    exit();
                }
            }

            // keep $userRow in sync for countdown
            if (!empty($matchRow)) {
                $userRow = array_merge($userRow ?? [], $matchRow);
            }
        }
    }
}

// ------------------------------------
// 3. Ensure expiry exists for existing code & compute remaining
// ------------------------------------
if (!empty($userRow["verification_code"]) && empty($userRow["verification_expires_at"])) {
    // If there is a code but no expiry yet (old records), set a new 20-min window from now
    $expiresAt = date('Y-m-d H:i:s', time() + 20 * 60);
    $stmt = $conn->prepare("UPDATE users SET verification_expires_at = ? WHERE id = ?");
    $stmt->bind_param("si", $expiresAt, $userId);
    $stmt->execute();
    $stmt->close();
    $userRow["verification_expires_at"] = $expiresAt;
}

$remainingSeconds = 0;
if (!empty($userRow["verification_expires_at"])) {
    $expiresTs = strtotime($userRow["verification_expires_at"]);
    if ($expiresTs !== false) {
        $remainingSeconds = max(0, $expiresTs - time());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Phone</title>
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
            <h3 class="mt-2">Verify Your Phone</h3>
            <p class="wizard-subtitle mb-0">
                Enter the SMS code we sent to your number.
            </p>
        </div>

        <?php if (!empty($feedbackMessage)): ?>
            <div class="alert alert-<?= htmlspecialchars($feedbackClass) ?>">
                <?= htmlspecialchars($feedbackMessage) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="verify_phone.php">
            <div class="wizard-content">
                <p class="mb-1 text-center text-muted-small">
                    Code sent to <strong><?= htmlspecialchars($displayPhone) ?></strong>
                </p>

                <p id="code-timer" class="mb-3 text-center text-muted-small">
                    <span id="countdown"></span>
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
                        maxlength="6"
                    >
                </div>

                <div class="wizard-actions">
                    <button type="submit" class="btn btn-success w-100" name="verify" id="verify-btn">
                        Verify Phone
                    </button>

                    <!-- Resend Code (white button) -->
                    <button type="submit" name="resend_code"
                            class="btn w-100 mt-2 bg-white border text-dark"
                            formnovalidate>
                        Resend Code
                    </button>

                    <!-- Switch to Email Verification -->
                    <button type="submit" name="switch_to_email"
                            class="btn btn-link w-100 mt-2"
                            formnovalidate>
                        Prefer email instead? Verify via email
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        (function () {
            var remaining = <?php echo (int)$remainingSeconds; ?>;
            var countdownEl = document.getElementById('countdown');
            var timerContainer = document.getElementById('code-timer');
            var verifyBtn = document.getElementById('verify-btn');

            if (!countdownEl || !timerContainer) return;

            function formatTime(sec) {
                var m = Math.floor(sec / 60);
                var s = sec % 60;
                return m + ':' + (s < 10 ? '0' + s : s);
            }

            function setExpiredState() {
                timerContainer.textContent = 'Code expired. Please click "Resend Code" to get a new one.';
                if (verifyBtn) {
                    verifyBtn.disabled = true;
                    verifyBtn.classList.add('disabled');
                }
            }

            function tick() {
                if (remaining <= 0) {
                    setExpiredState();
                    return;
                }
                countdownEl.textContent = 'Code expires in ' + formatTime(remaining);
                remaining--;
                setTimeout(tick, 1000);
            }

            if (remaining <= 0) {
                setExpiredState();
            } else {
                countdownEl.textContent = 'Code expires in ' + formatTime(remaining);
                tick();
            }
        })();
    </script>

</body>
</html>