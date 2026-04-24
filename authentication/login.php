<?php
session_start();
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../include/platform_integrations.php';
require_once __DIR__ . '/../include/auth_branding.php';

app_system_config_seed_defaults($conn, app_platform_integrations_default_values());
$authLogoUrl = app_auth_logo_url($conn, '../');

$googleOauthConfig = app_social_auth_config($conn, 'google');
$facebookOauthConfig = app_social_auth_config($conn, 'facebook');
$googleEnabled = !empty($googleOauthConfig['enabled']);
$facebookEnabled = !empty($facebookOauthConfig['enabled']);
$googleClientId = (string)($googleOauthConfig['client_id'] ?? '');
$facebookAppId = (string)($facebookOauthConfig['client_id'] ?? '');

$loginError = '';
$rememberMeChecked = !empty($_POST['remember_me']);
$loginInputValue = trim((string)($_POST['login_input'] ?? ''));
if ($loginInputValue === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $loginInputValue = app_auth_login_hint();
}

if (isset($_GET['redirect'])) {
    rememberAuthRedirectTarget((string)$_GET['redirect']);
}

if (isset($_SESSION['user'])) {
    header('Location: ' . app_auth_post_login_target($_SESSION['user'], '../index.php'));
    exit();
}

$googleState = app_oauth_state('google');
$facebookState = app_oauth_state('facebook');
$googleRedirectUri = app_url('/authentication/google_callback.php');
$facebookRedirectUri = app_url('/authentication/facebook_callback.php');
$_SESSION['oauth_origin_google'] = 'login';
$_SESSION['oauth_origin_facebook'] = 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!app_verify_csrf()) {
        $loginError = 'Your login form expired. Please try again.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        if ($loginInputValue === '' || $password === '') {
            $loginError = 'Please enter both your email/username and password.';
        } else {
            $stmt = $conn->prepare("SELECT *, userID AS id FROM users WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?) LIMIT 1");
            if (!$stmt) {
                $loginError = 'Something went wrong. Please try again.';
            } else {
                $stmt->bind_param('ss', $loginInputValue, $loginInputValue);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result ? $result->fetch_assoc() : null;
                $stmt->close();

                if (!$user) {
                    $loginError = 'Email or username not found.';
                } elseif (!password_verify($password, (string)($user['password'] ?? ''))) {
                    $loginError = 'Incorrect password.';
                } else {
                    $userId = (int)($user['id'] ?? 0);
                    $trustedBrowser = $userId > 0 && app_auth_is_trusted_browser($conn, $userId);

                    if ($trustedBrowser) {
                        $mode = $rememberMeChecked ? 'forever' : 'day';
                        app_auth_clear_two_factor();
                        app_auth_complete_login($conn, $user, $mode, [
                            'login_identifier' => $loginInputValue,
                            'keep_login_hint' => false,
                        ]);
                        header('Location: ' . app_auth_post_login_target($user, '../index.php'));
                        exit();
                    }

                    session_regenerate_id(true);
                    $challenge = app_auth_start_two_factor_challenge($user, $loginInputValue, $rememberMeChecked);
                    if (!empty($challenge['success'])) {
                        header('Location: two_factor.php');
                        exit();
                    }

                    $loginError = (string)($challenge['message'] ?? "We couldn't send your 2FA code right now. Please try again.");
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Login - Athina E-Shop</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styling/style.css">
    <link rel="stylesheet" href="../assets/styling/authentication.css">
</head>
<body class="registration_page">

    <div class="wizard-box">
        <div class="wizard-header text-center">
            <div class="wizard-logo">
                <img src="<?= htmlspecialchars($authLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Creations by Athina logo">
            </div>
            <h3 class="mt-2">System Login</h3>
            <p class="wizard-subtitle mb-0">
                Sign in to your Athina E-Shop account to view your details and track your orders.
            </p>
        </div>

        <div class="wizard-content">
            <div class="mb-3">
                <button
                    type="button"
                    id="google-signin-btn"
                    class="btn btn-light border d-flex align-items-center justify-content-center gap-2 mx-auto auth-social-btn"
                    <?= $googleEnabled ? '' : 'disabled aria-disabled="true" title="Google login is not configured yet."' ?>
                >
                    <img src="https://developers.google.com/identity/images/g-logo.png"
                         class="auth-social-logo" alt="Google logo">
                    <?= $googleEnabled ? 'Continue with Google' : 'Google login unavailable' ?>
                </button>
            </div>

            <div class="mb-3">
                <button
                    type="button"
                    id="facebook-signin-btn"
                    class="btn d-flex align-items-center justify-content-center gap-2 mx-auto auth-social-btn auth-facebook-btn"
                    <?= $facebookEnabled ? '' : 'disabled aria-disabled="true" title="Facebook login is not configured yet."' ?>
                >
                    <i class="bi bi-facebook"></i>
                    <?= $facebookEnabled ? 'Continue with Facebook' : 'Facebook login unavailable' ?>
                </button>
            </div>

            <p class="mt-2 mb-3 text-muted text-center auth-divider-text">
                Or login with your email or username
            </p>

            <?php if (isset($_SESSION['registration_error'])): ?>
                <div class="alert alert-danger"><?= htmlspecialchars((string)$_SESSION['registration_error']) ?></div>
                <?php unset($_SESSION['registration_error']); ?>
            <?php endif; ?>

            <?php if ($loginError !== ''): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($loginError) ?></div>
            <?php endif; ?>

            <form action="login.php" method="post" class="mt-3">
                <?= app_csrf_input() ?>
                <div class="form-group mb-3">
                    <label for="login_input">Username or Email Address</label>
                    <input
                        type="text"
                        class="form-control"
                        id="login_input"
                        name="login_input"
                        placeholder="e.g. user150 or user@example.com"
                        value="<?= htmlspecialchars($loginInputValue) ?>"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="form-group mb-3">
                    <label for="password">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" name="password" id="password" autocomplete="current-password" required>
                        <span class="input-group-text toggle-password" data-target="#password">
                            <i class="bi bi-eye"></i>
                        </span>
                    </div>
                </div>

                <div class="form-check mb-3 text-start">
                    <input class="form-check-input" type="checkbox" value="1" id="remember_me" name="remember_me" <?= $rememberMeChecked ? 'checked' : '' ?>>
                    <label class="form-check-label" for="remember_me">
                        Remember Me
                    </label>
                </div>

                <div class="wizard-actions mb-2">
                    <button type="submit" name="login" class="btn btn-success w-100">
                        Login
                    </button>
                </div>
            </form>

            <div class="form-footer text-center mt-2">
                Don't have an account? <a href="registration.php">Register</a><br>
                <a href="forgot_password.php">Forgot your password?</a>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        var googleEnabled = <?= $googleEnabled ? 'true' : 'false' ?>;
        var facebookEnabled = <?= $facebookEnabled ? 'true' : 'false' ?>;

        if (googleEnabled) {
            document.getElementById('google-signin-btn').addEventListener('click', function () {
                const params = new URLSearchParams({
                    client_id: <?= json_encode($googleClientId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                    redirect_uri: <?= json_encode($googleRedirectUri, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                    response_type: 'code',
                    scope: 'email profile',
                    access_type: 'online',
                    include_granted_scopes: 'true',
                    prompt: 'select_account',
                    state: <?= json_encode($googleState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
                });

                window.location.href = 'https://accounts.google.com/o/oauth2/v2/auth?' + params.toString();
            });
        }

        if (facebookEnabled) {
            document.getElementById('facebook-signin-btn').addEventListener('click', function () {
                const params = new URLSearchParams({
                    client_id: <?= json_encode($facebookAppId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                    redirect_uri: <?= json_encode($facebookRedirectUri, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                    response_type: 'code',
                    auth_type: 'rerequest',
                    state: <?= json_encode($facebookState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
                });

                window.location.href = 'https://www.facebook.com/v21.0/dialog/oauth?' + params.toString();
            });
        }

        $(document).ready(function () {
            $(".toggle-password").click(function () {
                const input = $($(this).data("target"));
                const type = input.attr("type") === "password" ? "text" : "password";
                input.attr("type", type);
                $(this).find("i").toggleClass("bi-eye bi-eye-slash");
            });
        });
    </script>

</body>
</html>
