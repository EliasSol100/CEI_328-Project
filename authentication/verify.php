<?php
session_start();
require_once "database.php";
require_once __DIR__ . "/../include/security.php";
require_once __DIR__ . "/auth_mailer.php";
require_once __DIR__ . "/../include/homepage_customization.php";

$feedbackMessage = '';
$feedbackClass   = 'danger';
$userRow         = null;

// ------------------------------------
// 1. Make sure we have a logged-in user
// ------------------------------------
if (!isset($_SESSION["user_id"])) {
    // No user in session → go back to login
    header("Location: login.php");
    exit();
}

$userId = (int) $_SESSION["user_id"];

// Load user row for display (email)
$stmt = $conn->prepare("SELECT *, userID AS id FROM users WHERE userID = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$userRow = $result->fetch_assoc();
$stmt->close();

if (!$userRow) {
    // User disappeared from DB → reset session and redirect
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

// If already verified, no need to be here
if (!empty($userRow["is_verified"]) && (int)$userRow["is_verified"] === 1) {
    header("Location: " . consumeAuthRedirectTarget("../index.php"));
    exit();
}

// ------------------------------------
// Helper: generate new code & expiry (email)
// ------------------------------------
function generateEmailVerificationCode(mysqli $conn, int $userId, string $email): array
{
    return app_auth_send_email_verification_code($conn, $userId, $email, (string)($_SESSION["full_name"] ?? ''));
}

function emailVerificationHasActiveCode(array $userRow): bool
{
    $code = trim((string)($userRow["verification_code"] ?? ''));
    if ($code === '') {
        return false;
    }

    $expiresAt = trim((string)($userRow["verification_expires_at"] ?? ''));
    if ($expiresAt === '') {
        return false;
    }

    $expiresTs = strtotime($expiresAt);
    return $expiresTs !== false && $expiresTs > time();
}

function reloadEmailVerificationUser(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare("SELECT *, userID AS id FROM users WHERE userID = ?");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function ensureActiveEmailVerificationCode(mysqli $conn, int $userId, array &$userRow, bool $force = false): array
{
    if (!$force && emailVerificationHasActiveCode($userRow)) {
        return ['success' => true, 'sent' => false, 'message' => ''];
    }

    $email = trim((string)($userRow["email"] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'sent' => false,
            'message' => "We couldn't find a valid email address for your account.",
        ];
    }

    $result = generateEmailVerificationCode($conn, $userId, $email);
    if (empty($result['success'])) {
        $result['sent'] = false;
        return $result;
    }

    $reloaded = reloadEmailVerificationUser($conn, $userId);
    if ($reloaded) {
        $userRow = $reloaded;
    }

    return [
        'success' => true,
        'sent' => true,
        'message' => 'A verification code has been sent to your email. It will be valid for 20 minutes.',
    ];
}

// ------------------------------------
// 2. Handle POST actions (verify / resend / switch)
// ------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    app_require_csrf(false, "Invalid request token. Please refresh and try again.");

    // 2a. Resend code by email
    if (isset($_POST["resend_code"])) {
        if ($userRow && !empty($userRow["email"])) {
            $resend = ensureActiveEmailVerificationCode($conn, $userId, $userRow, true);
            if (!empty($resend["success"])) {
                $feedbackMessage = trim((string)($resend["message"] ?? '')) !== ''
                    ? (string)$resend["message"]
                    : "A new verification code has been sent to your email. It will be valid for 20 minutes.";
                $feedbackClass   = "success";
            } else {
                $feedbackMessage = (string)($resend["message"] ?? "We couldn't resend the code at the moment. Please try again later.");
                $feedbackClass   = "danger";
            }
        } else {
            $feedbackMessage = "We couldn't find an email address for your account.";
            $feedbackClass   = "danger";
        }
    }

    // 2c. Verify code
    if (isset($_POST["verify"])) {
        $code = trim($_POST["verification_code"] ?? '');

        if ($code === '') {
            $feedbackMessage = "Please enter your verification code.";
            $feedbackClass   = "danger";
        } else {
            // Re-fetch minimal fields (including stored verification_code & expiry)
            $stmt = $conn->prepare("
                SELECT userID AS id, email, full_name, role, profile_complete, is_verified,
                       verification_code, verification_expires_at
                FROM users
                WHERE userID = ?
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $res    = $stmt->get_result();
            $dbUser = $res->fetch_assoc();
            $stmt->close();

            if (!$dbUser || empty($dbUser["verification_code"])) {
                $feedbackMessage = "There is no active verification code. Please click 'Resend Code'.";
                $feedbackClass   = "danger";
            } else {
                $storedCode = $dbUser["verification_code"];
                $expiresAt  = $dbUser["verification_expires_at"] ?? null;

                $isExpired = false;
                if (!empty($expiresAt)) {
                    $isExpired = (strtotime($expiresAt) < time());
                }

                if ($isExpired) {
                    $feedbackMessage = "Your verification code has expired. Please click 'Resend Code' to receive a new one.";
                    $feedbackClass   = "danger";
                } elseif ($storedCode !== $code) {
                    $feedbackMessage = "The verification code is incorrect. Please try again.";
                    $feedbackClass   = "danger";
                } else {
                    // Mark user as verified & clear the code and expiry
                    $stmt = $conn->prepare("
                        UPDATE users
                        SET is_verified = '1',
                            verification_code = NULL,
                            verification_expires_at = NULL
                        WHERE userID = ?
                    ");
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $stmt->close();

                    // Reload fresh user row to rebuild the session
                    $stmt = $conn->prepare("SELECT *, userID AS id FROM users WHERE userID = ?");
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

                    // All good → go to homepage
                    header("Location: " . consumeAuthRedirectTarget("../index.php"));
                    exit();
                }
            }

            // keep $userRow in sync for countdown (if it changed)
            if (!empty($dbUser)) {
                $userRow = array_merge($userRow ?? [], $dbUser);
            }
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    $ensure = ensureActiveEmailVerificationCode($conn, $userId, $userRow, false);
    if (!empty($ensure["sent"])) {
        $feedbackMessage = (string)$ensure["message"];
        $feedbackClass = "success";
    } elseif (empty($ensure["success"]) && trim((string)($ensure["message"] ?? '')) !== '') {
        $feedbackMessage = (string)$ensure["message"];
        $feedbackClass = "danger";
    }
}

// ------------------------------------
// 3. Ensure expiry exists for existing code (fallback) & compute remaining
// ------------------------------------
if (!empty($userRow["verification_code"]) && empty($userRow["verification_expires_at"])) {
    // If there is a code but no expiry yet (old records), set a new 20-min window from now
    $expiresAt = date('Y-m-d H:i:s', time() + 20 * 60);
    $stmt = $conn->prepare("UPDATE users SET verification_expires_at = ? WHERE userID = ?");
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

$verifyLogoPath = app_homepage_get_config_value($conn, 'homepage_header_logo_path', 'uploads/assets/images/homepage/header-logo.jpg');
$verifyLogoUrl = app_homepage_asset_url($verifyLogoPath, '../');
if ($verifyLogoUrl === '') {
    $verifyLogoUrl = '../uploads/assets/images/homepage/header-logo.jpg';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Your Email</title>
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
                <img src="<?= htmlspecialchars($verifyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Athina E-Shop Logo">
            </div>
            <h3 class="mt-2">Email Verification</h3>
            <p class="wizard-subtitle mb-0">
                Enter the 6-digit code we sent to your email to finish setting up your account.
            </p>
        </div>

        <?php if (!empty($feedbackMessage)): ?>
            <div class="alert alert-<?= htmlspecialchars($feedbackClass) ?>">
                <?= htmlspecialchars($feedbackMessage) ?>
            </div>
        <?php endif; ?>

        <form action="verify.php" method="post">
            <?= app_csrf_input() ?>
            <div class="wizard-content">

                <?php if ($userRow): ?>
                    <p class="mb-1 text-center text-muted-small">
                        Code sent to <strong><?= htmlspecialchars($userRow["email"]) ?></strong>
                    </p>
                <?php endif; ?>

                <p id="code-timer" class="mb-3 text-center text-muted-small">
                    <span id="countdown"></span>
                </p>

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
                    <button type="submit" name="verify" id="verify-btn" class="btn btn-success w-100">
                        Verify Email
                    </button>

                    <!-- Resend Code (white button) -->
                    <button type="submit" name="resend_code"
                            class="btn w-100 mt-2 bg-white border text-dark"
                            formnovalidate>
                        Resend Code
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
