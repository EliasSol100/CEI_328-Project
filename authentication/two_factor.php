<?php
session_start();
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../include/auth_branding.php';

$pending = app_auth_pending_two_factor();
if (!$pending) {
    header('Location: login.php');
    exit();
}

$twoFactorError = '';
$twoFactorSuccess = '';
$trustBrowserChecked = !empty($_POST['trust_browser']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');

    if (isset($_POST['cancel'])) {
        app_auth_clear_two_factor();
        header('Location: login.php');
        exit();
    }

    if (isset($_POST['resend_code'])) {
        $resend = app_auth_resend_two_factor($conn);
        if (!empty($resend['success'])) {
            $twoFactorSuccess = 'A new 2FA code has been sent to your email.';
            $pending = app_auth_pending_two_factor();
        } else {
            $twoFactorError = (string)($resend['message'] ?? "We couldn't resend the code right now. Please try again.");
        }
    }

    if (isset($_POST['verify_code'])) {
        $verification = app_auth_verify_two_factor_code((string)($_POST['two_factor_code'] ?? ''));
        if (!empty($verification['success'])) {
            $pending = (array)($verification['pending'] ?? []);
            $user = app_auth_fetch_user_by_id($conn, (int)($pending['user_id'] ?? 0));

            if (!$user) {
                app_auth_clear_two_factor();
                $twoFactorError = 'Your login verification session expired. Please sign in again.';
            } else {
                if ($trustBrowserChecked) {
                    app_auth_trust_current_browser($conn, (int)$user['id']);
                }

                $rememberRequested = !empty($pending['remember_requested']);
                if ($trustBrowserChecked) {
                    $mode = $rememberRequested ? 'forever' : 'day';
                } else {
                    $mode = $rememberRequested ? 'week' : 'day';
                }

                app_auth_complete_login($conn, $user, $mode, [
                    'login_identifier' => (string)($pending['login_identifier'] ?? ''),
                    'keep_login_hint' => $rememberRequested && !$trustBrowserChecked,
                ]);
                app_auth_clear_two_factor();

                header('Location: ' . app_auth_post_login_target($user, '../index.php'));
                exit();
            }
        } else {
            $twoFactorError = (string)($verification['message'] ?? 'The 2FA code could not be verified.');
            $pending = app_auth_pending_two_factor();
            if (!$pending && $twoFactorError !== '') {
                $pending = null;
            }
        }
    }
}

if (!$pending) {
    header('Location: login.php');
    exit();
}

$maskedEmail = (string)($pending['masked_email'] ?? app_auth_mask_email((string)($pending['email'] ?? '')));
$rememberRequested = !empty($pending['remember_requested']);
$secondsUntilExpiry = max(0, (int)($pending['expires_at'] ?? 0) - time());
$secondsUntilResend = max(0, (int)($pending['resend_available_at'] ?? 0) - time());
$twoFactorExpirySeconds = function_exists('app_auth_two_factor_code_ttl_seconds')
    ? app_auth_two_factor_code_ttl_seconds()
    : 20 * 60;
$authLogoUrl = app_auth_logo_url($conn, '../');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Two-Factor Verification - Athina E-Shop</title>
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
            <h3 class="mt-2">Two-Factor Verification</h3>
            <p class="wizard-subtitle mb-0">
                Enter the 6-digit code we sent to <?= htmlspecialchars($maskedEmail) ?> to complete your login.
            </p>
        </div>

        <div class="wizard-content">
            <?php if ($twoFactorError !== ''): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($twoFactorError) ?></div>
            <?php endif; ?>

            <?php if ($twoFactorSuccess !== ''): ?>
                <div class="alert alert-success"><?= htmlspecialchars($twoFactorSuccess) ?></div>
            <?php endif; ?>

            <form action="two_factor.php" method="post">
                <?= app_csrf_input() ?>

                <div id="two-factor-timer"
                     class="two-factor-timer mb-3"
                     data-expiry-seconds="<?= (int)$secondsUntilExpiry ?>"
                     data-expiry-total="<?= (int)$twoFactorExpirySeconds ?>">
                    <div class="two-factor-timer-copy">
                        <span class="two-factor-timer-label">Code expires in</span>
                        <strong id="two-factor-expiry-text">00:00</strong>
                    </div>
                    <div class="two-factor-timer-bar" aria-hidden="true">
                        <span id="two-factor-expiry-bar"></span>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label for="two_factor_code">Verification Code</label>
                    <input
                        type="text"
                        class="form-control text-center"
                        id="two_factor_code"
                        name="two_factor_code"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        autocomplete="one-time-code"
                        placeholder="Enter the 6-digit code"
                        required
                    >
                </div>

                <div class="form-check mb-3 text-start">
                    <input class="form-check-input" type="checkbox" value="1" id="trust_browser" name="trust_browser" <?= $trustBrowserChecked ? 'checked' : '' ?>>
                    <label class="form-check-label" for="trust_browser">
                        Do not ask on this device again
                    </label>
                </div>

                <div class="text-start mb-3 small text-muted">
                    <?php if ($rememberRequested): ?>
                        Remember Me was selected, so this login will stay active for 7 days unless you trust this browser permanently.
                    <?php else: ?>
                        This login will stay active for 24 hours unless you trust this browser permanently.
                    <?php endif; ?>
                </div>

                <div class="wizard-actions mb-2">
                    <button type="submit" name="verify_code" id="verify-code-btn" class="btn btn-success w-100" <?= $secondsUntilExpiry <= 0 ? 'disabled' : '' ?>>
                        Verify Code
                    </button>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit"
                            name="resend_code"
                            id="resend-code-btn"
                            class="btn btn-outline-secondary flex-fill"
                            formnovalidate
                            data-wait-seconds="<?= (int)$secondsUntilResend ?>"
                            data-default-label="Resend Code"
                            <?= $secondsUntilResend > 0 ? 'disabled' : '' ?>>
                        <?= $secondsUntilResend > 0 ? 'Resend in ' . $secondsUntilResend : 'Resend Code' ?>
                    </button>
                    <button type="submit" name="cancel" class="btn btn-outline-danger flex-fill" formnovalidate>
                        Back to Login
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            var timerContainer = document.getElementById('two-factor-timer');
            var expiryText = document.getElementById('two-factor-expiry-text');
            var expiryBar = document.getElementById('two-factor-expiry-bar');
            var verifyBtn = document.getElementById('verify-code-btn');
            var codeInput = document.getElementById('two_factor_code');
            var resendBtn = document.getElementById('resend-code-btn');

            if (!timerContainer || !expiryText || !expiryBar || !resendBtn) {
                return;
            }

            var expiryRemaining = parseInt(timerContainer.getAttribute('data-expiry-seconds') || '0', 10);
            var expiryTotal = parseInt(timerContainer.getAttribute('data-expiry-total') || '1200', 10);
            var resendRemaining = parseInt(resendBtn.getAttribute('data-wait-seconds') || '0', 10);
            var resendDefaultLabel = resendBtn.getAttribute('data-default-label') || 'Resend Code';

            function pad(value) {
                return String(value).padStart(2, '0');
            }

            function formatTime(seconds) {
                var minutes = Math.floor(seconds / 60);
                var remainder = seconds % 60;
                return pad(minutes) + ':' + pad(remainder);
            }

            function updateExpiry() {
                if (expiryRemaining <= 0) {
                    expiryText.textContent = '00:00';
                    expiryBar.style.width = '0%';
                    timerContainer.classList.add('is-expired');
                    if (verifyBtn) {
                        verifyBtn.disabled = true;
                    }
                    if (codeInput) {
                        codeInput.disabled = true;
                    }
                    return;
                }

                expiryText.textContent = formatTime(expiryRemaining);
                expiryBar.style.width = Math.max(0, Math.min(100, (expiryRemaining / Math.max(1, expiryTotal)) * 100)) + '%';
                expiryRemaining--;
                window.setTimeout(updateExpiry, 1000);
            }

            function updateResend() {
                if (resendRemaining <= 0) {
                    resendBtn.textContent = resendDefaultLabel;
                    resendBtn.disabled = false;
                    resendBtn.classList.remove('is-waiting');
                    return;
                }

                resendBtn.textContent = 'Resend in ' + resendRemaining;
                resendBtn.disabled = true;
                resendBtn.classList.add('is-waiting');
                resendRemaining--;
                window.setTimeout(updateResend, 1000);
            }

            updateExpiry();
            updateResend();
        })();
    </script>

</body>
</html>
