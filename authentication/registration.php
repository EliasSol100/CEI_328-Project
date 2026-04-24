<?php

session_start();
require_once __DIR__ . "/../include/security.php";

require_once "database.php";
require_once __DIR__ . "/../include/platform_integrations.php";
require_once __DIR__ . "/../include/auth_branding.php";

app_system_config_seed_defaults($conn, app_platform_integrations_default_values());
$authLogoUrl = app_auth_logo_url($conn, '../');

$googleOauthConfig = app_social_auth_config($conn, 'google');
$facebookOauthConfig = app_social_auth_config($conn, 'facebook');
$googleEnabled = !empty($googleOauthConfig['enabled']);
$facebookEnabled = !empty($facebookOauthConfig['enabled']);
$googleClientId = (string)($googleOauthConfig['client_id'] ?? '');
$facebookAppId = (string)($facebookOauthConfig['client_id'] ?? '');

$googleState = app_oauth_state('google');
$facebookState = app_oauth_state('facebook');
$googleRedirectUri = app_url('/authentication/google_callback.php');
$facebookRedirectUri = app_url('/authentication/facebook_callback.php');
$_SESSION['oauth_origin_google'] = 'registration';
$_SESSION['oauth_origin_facebook'] = 'registration';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    app_require_csrf(false, "Invalid request token. Please refresh and try again.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["manual_email"])) {

    define('FP_MAX_ATTEMPTS',  5);
    define('FP_WINDOW_HOURS', 24);

    if (trim((string)($_POST['website'] ?? '')) !== '') {
        $_SESSION["registration_error"] = "Automated registration is not allowed.";
        header("Location: registration.php");
        exit();
    }

    $raw_fp     = trim((string)($_POST['fp_visitor_id'] ?? ''));
    $visitor_id = preg_replace('/[^a-zA-Z0-9\-]/', '', $raw_fp);

    if ($visitor_id === '') {
        $_SESSION["registration_error"] = "Registration could not be completed. Please enable JavaScript and try again.";
        header("Location: registration.php");
        exit();
    }

    $is_headless = (int)($_POST['fp_headless']    ?? 0);
    $mouse_moved = (int)($_POST['fp_mouse_moved'] ?? 0);
    $timing_ms   = (int)($_POST['fp_timing_ms']   ?? 0);

    if ($is_headless === 1) {
        $_SESSION["registration_error"] = "Automated registration is not allowed.";
        header("Location: registration.php");
        exit();
    }

    if ($timing_ms < 800 && $mouse_moved === 0) {
        $_SESSION["registration_error"] = "Please interact with the page before submitting.";
        header("Location: registration.php");
        exit();
    }

    $fp_stmt = $conn->prepare("
        SELECT id, attempt_count, is_blocked, first_seen
        FROM   bot_fingerprints
        WHERE  visitor_id = ?
        LIMIT  1
    ");
    $fp_stmt->bind_param("s", $visitor_id);
    $fp_stmt->execute();
    $fp_row = $fp_stmt->get_result()->fetch_assoc();
    $fp_stmt->close();

    if ($fp_row) {
        if ((int)$fp_row['is_blocked'] === 1) {
            $_SESSION["registration_error"] = "This device has been blocked due to suspicious activity.";
            header("Location: registration.php");
            exit();
        }

        $window_start = (new DateTime())->modify('-' . FP_WINDOW_HOURS . ' hours');
        $first_seen   = new DateTime($fp_row['first_seen']);

        if ($first_seen >= $window_start) {
            if ((int)$fp_row['attempt_count'] >= FP_MAX_ATTEMPTS) {
                $blk = $conn->prepare("UPDATE bot_fingerprints SET is_blocked=1, last_attempt=NOW() WHERE visitor_id=?");
                $blk->bind_param("s", $visitor_id);
                $blk->execute();
                $blk->close();
                $_SESSION["registration_error"] = "Too many registration attempts. This device has been blocked.";
                header("Location: registration.php");
                exit();
            }
            $inc = $conn->prepare("UPDATE bot_fingerprints SET attempt_count=attempt_count+1, last_attempt=NOW() WHERE visitor_id=?");
            $inc->bind_param("s", $visitor_id);
            $inc->execute();
            $inc->close();
        } else {
            $rst = $conn->prepare("UPDATE bot_fingerprints SET attempt_count=1, is_blocked=0, first_seen=NOW(), last_attempt=NOW() WHERE visitor_id=?");
            $rst->bind_param("s", $visitor_id);
            $rst->execute();
            $rst->close();
        }
    } else {
        $ins = $conn->prepare("INSERT INTO bot_fingerprints (visitor_id, attempt_count, is_blocked) VALUES (?, 1, 0)");
        $ins->bind_param("s", $visitor_id);
        $ins->execute();
        $ins->close();
    }
}

if (isset($_SESSION["user"])) {
    header("Location: ../index.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["manual_email"])) {

    $manual_email = trim($_POST["manual_email"]);

    if (!filter_var($manual_email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION["registration_error"] = "Please enter a valid email address.";
        header("Location: registration.php");
        exit();
    }

    $stmt = $conn->prepare("SELECT userID FROM users WHERE email = ?");
    $stmt->bind_param("s", $manual_email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {

        $_SESSION["registration_error"] = "An account with this email already exists!";
        header("Location: registration.php");
        exit();
    } else {

        $_SESSION["manual_email"] = $manual_email;
        header("Location: complete_profile.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register or Continue</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../assets/styling/style.css">
    <link rel="stylesheet" href="../assets/styling/authentication.css">
</head>
<body class="registration_page">

    <div class="wizard-box text-center">
        <div class="wizard-header">
            <div class="wizard-logo">
                <img src="<?= htmlspecialchars($authLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Creations by Athina logo">
            </div>
            <h3 class="mt-2">Create Your Account</h3>
            <p class="wizard-subtitle mb-0">
                Join Athina E-Shop to save your details and easily track your orders.
            </p>
        </div>

        <?php if (isset($_SESSION["registration_error"])): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars((string)$_SESSION["registration_error"]) ?><?php unset($_SESSION["registration_error"]); ?>
            </div>
        <?php endif; ?>

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

        <p class="mt-2 mb-1 text-muted auth-divider-text">Or use your email</p>

        <form method="POST" action="registration.php" class="mt-2 auth-email-form">
            <?= app_csrf_input() ?>

            <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;">
                <label for="website">Website</label>
                <input type="text" name="website" id="website" value="" tabindex="-1" autocomplete="off">
            </div>

            <input type="hidden" name="fp_visitor_id"  id="fp_visitor_id"  value="">
            <input type="hidden" name="fp_headless"    id="fp_headless"    value="0">
            <input type="hidden" name="fp_mouse_moved" id="fp_mouse_moved" value="0">
            <input type="hidden" name="fp_timing_ms"   id="fp_timing_ms"   value="0">
            <div class="form-group mb-3 text-start">
                <label for="manual_email" class="visually-hidden">Email</label>
                <input
                    type="email"
                    id="manual_email"
                    name="manual_email"
                    class="form-control"
                    placeholder="Enter your email"
                    required
                >
            </div>
            <button type="submit" class="btn btn-primary w-100">Continue</button>
        </form>

        <div class="form-footer mt-4">
            Already have an account? <a href="login.php">Login</a>
        </div>
    </div>

    <script>

    document.getElementById('google-signin-btn').addEventListener('click', function () {

        const params = new URLSearchParams({
            client_id: <?= json_encode($googleClientId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            redirect_uri: <?= json_encode($googleRedirectUri, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            response_type: 'code',
            scope: 'email profile',
            access_type: 'online',
            include_granted_scopes: 'true',
            prompt: 'select_account'
        });

        const authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' + params.toString();
        window.location.href = authUrl;
    });

    document.getElementById('facebook-signin-btn').addEventListener('click', function () {

        const params = new URLSearchParams({
            client_id: <?= json_encode($facebookAppId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            redirect_uri: <?= json_encode($facebookRedirectUri, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            response_type: 'code',
            auth_type: 'rerequest'
        });

        const fbAuthUrl = 'https://www.facebook.com/v21.0/dialog/oauth?' + params.toString();
        window.location.href = fbAuthUrl;
    });
    </script>

    <script>
    document.getElementById('google-signin-btn').addEventListener('click', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();

        const params = new URLSearchParams({
            state: <?= json_encode($googleState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            client_id: <?= json_encode($googleClientId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            redirect_uri: <?= json_encode($googleRedirectUri, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            response_type: 'code',
            scope: 'email profile',
            access_type: 'online',
            include_granted_scopes: 'true',
            prompt: 'select_account'
        });

        window.location.href = 'https://accounts.google.com/o/oauth2/v2/auth?' + params.toString();
    }, true);

    document.getElementById('facebook-signin-btn').addEventListener('click', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();

        const params = new URLSearchParams({
            state: <?= json_encode($facebookState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            client_id: <?= json_encode($facebookAppId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            redirect_uri: <?= json_encode($facebookRedirectUri, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            response_type: 'code',
            auth_type: 'rerequest'
        });

        window.location.href = 'https://www.facebook.com/v21.0/dialog/oauth?' + params.toString();
    }, true);
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const _fpPageLoadTime = Date.now();
        let   _fpMouseMoved   = false;
        const _fpStorageKey   = 'athina_registration_browser_id';

        function _fpGenerateFallbackId() {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return 'browser-' + window.crypto.randomUUID();
            }

            if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
                const bytes = new Uint8Array(16);
                window.crypto.getRandomValues(bytes);
                return 'browser-' + Array.from(bytes, function (byte) {
                    return byte.toString(16).padStart(2, '0');
                }).join('');
            }

            return 'browser-' + String(Date.now()) + '-' + Math.random().toString(16).slice(2);
        }

        function _fpGetOrCreateFallbackId() {
            try {
                const existing = window.localStorage.getItem(_fpStorageKey) || '';
                if (/^[A-Za-z0-9-]{16,64}$/.test(existing)) {
                    return existing;
                }

                const generated = _fpGenerateFallbackId().replace(/[^A-Za-z0-9-]/g, '').slice(0, 64);
                window.localStorage.setItem(_fpStorageKey, generated);
                return generated;
            } catch (error) {
                return _fpGenerateFallbackId().replace(/[^A-Za-z0-9-]/g, '').slice(0, 64);
            }
        }

        function _fpSetVisitorId(value) {
            const field = document.getElementById('fp_visitor_id');
            if (!field) {
                return;
            }

            const sanitized = String(value || '').replace(/[^A-Za-z0-9-]/g, '').slice(0, 64);
            if (sanitized !== '') {
                field.value = sanitized;
            }
        }

        function _fpDetectHeadless() {
            return (
                navigator.webdriver === true ||
                /HeadlessChrome/.test(navigator.userAgent) ||
                (!window.chrome && /Chrome/.test(navigator.userAgent) && navigator.plugins.length === 0)
            ) ? 1 : 0;
        }

        _fpSetVisitorId(_fpGetOrCreateFallbackId());

        const _fpHeadlessField = document.getElementById('fp_headless');
        if (_fpHeadlessField) {
            _fpHeadlessField.value = _fpDetectHeadless();
        }

        document.addEventListener('mousemove', function () {
            _fpMouseMoved = true;
        }, { once: true });

        document.addEventListener('touchstart', function () {
            _fpMouseMoved = true;
        }, { once: true });
    </script>

    <script>
        import('https://openfpcdn.io/fingerprintjs/v4')
            .then(FingerprintJS => FingerprintJS.load())
            .then(fp => fp.get())
            .then(result => {
                _fpSetVisitorId(result.visitorId);
            })
            .catch(function () {

            });

        document.querySelector('.auth-email-form').addEventListener('submit', function () {
            if (document.getElementById('fp_visitor_id').value === '') {
                _fpSetVisitorId(_fpGetOrCreateFallbackId());
            }
            document.getElementById('fp_timing_ms').value   = Date.now() - _fpPageLoadTime;
            document.getElementById('fp_mouse_moved').value = _fpMouseMoved ? 1 : 0;
        });
    </script>
</body>
</html>
