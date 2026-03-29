<?php
// Ξεκινάμε τη session για να μπορούμε να αποθηκεύουμε/διαβάζουμε session variables
session_start();
require_once __DIR__ . "/../include/security.php";

// Φορτώνουμε τη σύνδεση με τη βάση δεδομένων από το database.php
require_once "database.php";

$googleState = app_oauth_state('google');
$facebookState = app_oauth_state('facebook');
$_SESSION['oauth_origin_google'] = 'registration';
$_SESSION['oauth_origin_facebook'] = 'registration';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    app_require_csrf(false, "Invalid request token. Please refresh and try again.");
}

// ── Anti-bot: FingerprintJS validation ──────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["manual_email"])) {

    define('FP_MAX_ATTEMPTS',  5);
    define('FP_WINDOW_HOURS', 24);

    $raw_fp     = trim((string)($_POST['fp_visitor_id'] ?? ''));
    $visitor_id = preg_replace('/[^a-zA-Z0-9\-]/', '', $raw_fp);

    // No fingerprint = JS disabled or headless bot
    if ($visitor_id === '') {
        $_SESSION["registration_error"] = "Registration could not be completed. Please enable JavaScript and try again.";
        header("Location: registration.php");
        exit();
    }

    $is_headless = (int)($_POST['fp_headless']    ?? 0);
    $mouse_moved = (int)($_POST['fp_mouse_moved'] ?? 0);
    $timing_ms   = (int)($_POST['fp_timing_ms']   ?? 0);

    // Headless browser detected
    if ($is_headless === 1) {
        $_SESSION["registration_error"] = "Automated registration is not allowed.";
        header("Location: registration.php");
        exit();
    }

    // Submitted too fast with no mouse movement = bot
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
// ── End Anti-bot ─────────────────────────────────────────────────────────────

// Αν ο χρήστης είναι ήδη συνδεδεμένος, τον στέλνουμε πίσω στην αρχική σελίδα
if (isset($_SESSION["user"])) {
    header("Location: ../index.php");
    exit();
}

// --- Χειρισμός φόρμας email (POST request) ---
// Εκτελείται μόνο όταν ο χρήστης υποβάλλει τη φόρμα με το email του
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["manual_email"])) {

    // Αφαιρούμε κενά από την αρχή/τέλος του email
    $manual_email = trim($_POST["manual_email"]);

    // Ελέγχουμε αν το email έχει έγκυρη μορφή (π.χ. user@example.com)
    if (!filter_var($manual_email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION["registration_error"] = "Please enter a valid email address.";
        header("Location: registration.php");
        exit();
    }

    // Ψάχνουμε στη βάση αν υπάρχει ήδη λογαριασμός με αυτό το email
    // users table primary key is userID
    $stmt = $conn->prepare("SELECT userID FROM users WHERE email = ?");
    $stmt->bind_param("s", $manual_email); // "s" = string parameter
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        // Βρέθηκε χρήστης → email υπάρχει ήδη, εμφανίζουμε σφάλμα
        $_SESSION["registration_error"] = "An account with this email already exists!";
        header("Location: registration.php");
        exit();
    } else {
        // Το email είναι ελεύθερο → αποθηκεύουμε το email στη session
        // και προχωράμε στο επόμενο βήμα (συμπλήρωση στοιχείων)
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
    <!-- Bootstrap CSS για στυλ και layout -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons (για το εικονίδιο του Facebook) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom στυλ του site -->
    <link rel="stylesheet" href="../assets/styling/style.css">
    <link rel="stylesheet" href="../assets/styling/authentication.css">
</head>
<body class="registration_page">

    <!-- Κεντρικό κουτί εγγραφής -->
    <div class="wizard-box text-center">
        <div class="wizard-header">
            <div class="wizard-logo">
                <img src="../assets/images/athina-eshop-logo.png" alt="Athina E-Shop Logo">
            </div>
            <h3 class="mt-2">Create Your Account</h3>
            <p class="wizard-subtitle mb-0">
                Join Athina E-Shop to save your details and easily track your orders.
            </p>
        </div>

        <!-- Εμφάνιση μηνύματος σφάλματος (αν υπάρχει από redirect) -->
        <?php if (isset($_SESSION["registration_error"])): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars((string)$_SESSION["registration_error"]) ?><?php unset($_SESSION["registration_error"]); ?>
            </div>
        <?php endif; ?>

        <!-- Κουμπί σύνδεσης μέσω Google -->
        <div class="mb-3">
            <button
                type="button"
                id="google-signin-btn"
                class="btn btn-light border d-flex align-items-center justify-content-center gap-2 mx-auto auth-social-btn"
            >
                <img src="https://developers.google.com/identity/images/g-logo.png"
                     class="auth-social-logo" alt="Google logo">
                Continue with Google
            </button>
        </div>

        <!-- Κουμπί σύνδεσης μέσω Facebook -->
        <div class="mb-3">
            <button
                type="button"
                id="facebook-signin-btn"
                class="btn d-flex align-items-center justify-content-center gap-2 mx-auto auth-social-btn auth-facebook-btn"
            >
                <i class="bi bi-facebook"></i>
                Continue with Facebook
            </button>
        </div>

        <!-- Διαχωριστής: "Ή χρησιμοποίησε το email σου" -->
        <p class="mt-2 mb-1 text-muted auth-divider-text">Or use your email</p>

        <!-- Φόρμα εισαγωγής email (στέλνει POST στην ίδια σελίδα) -->
        <form method="POST" action="registration.php" class="mt-2 auth-email-form">
            <?= app_csrf_input() ?>
            <!-- Anti-bot hidden fields — populated by FingerprintJS on page load -->
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

        <!-- Σύνδεσμος για χρήστες που έχουν ήδη λογαριασμό -->
        <div class="form-footer mt-4">
            Already have an account? <a href="login.php">Login</a>
        </div>
    </div>

    <script>
    // --- Σύνδεση μέσω Google OAuth2 ---
    // Όταν ο χρήστης κάνει κλικ στο κουμπί Google,
    // τον ανακατευθύνουμε στη σελίδα εξουσιοδότησης της Google
    document.getElementById('google-signin-btn').addEventListener('click', function () {
        // Παράμετροι για το Google OAuth2 request
        const params = new URLSearchParams({
            client_id: '901502356414-324b839ks2vas27hoq8hq0448qa6a0oj.apps.googleusercontent.com', // ID εφαρμογής από Google Console
            redirect_uri: 'http://localhost/ATHINA-ESHOP/authentication/google_callback.php',       // Σελίδα που θα λάβει την απάντηση
            response_type: 'code',           // Ζητάμε authorization code (όχι token απευθείας)
            scope: 'email profile',          // Θέλουμε πρόσβαση σε email και βασικό προφίλ
            access_type: 'online',           // Δεν χρειαζόμαστε offline access
            include_granted_scopes: 'true',  // Συμπεριλαμβάνουμε τυχόν προηγούμενα δοσμένα δικαιώματα
            prompt: 'select_account'         // Εμφάνιση επιλογής λογαριασμού ακόμα κι αν είναι ήδη συνδεδεμένος
        });

        // Κατασκευάζουμε το URL και ανακατευθύνουμε τον browser
        const authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' + params.toString();
        window.location.href = authUrl;
    });

    // --- Σύνδεση μέσω Facebook OAuth2 ---
    // Όταν ο χρήστης κάνει κλικ στο κουμπί Facebook,
    // τον ανακατευθύνουμε στη σελίδα εξουσιοδότησης του Facebook
    document.getElementById('facebook-signin-btn').addEventListener('click', function () {
        // Παράμετροι για το Facebook OAuth2 request
        const params = new URLSearchParams({
            client_id: '924345056652857',    // ID εφαρμογής από Facebook Developers
            redirect_uri: 'http://localhost/ATHINA-ESHOP/authentication/facebook_callback.php', // Σελίδα που θα λάβει την απάντηση
            response_type: 'code',           // Ζητάμε authorization code
            auth_type: 'rerequest'           // Ξαναζητάμε δικαιώματα αν τα είχε αρνηθεί παλιά
        });

        // Κατασκευάζουμε το URL και ανακατευθύνουμε τον browser
        const fbAuthUrl = 'https://www.facebook.com/v18.0/dialog/oauth?' + params.toString();
        window.location.href = fbAuthUrl;
    });
    </script>

    <!-- Bootstrap JS (για components που χρειάζονται JavaScript) -->
    <script>
    document.getElementById('google-signin-btn').addEventListener('click', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();

        const params = new URLSearchParams({
            state: <?= json_encode($googleState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            client_id: '901502356414-324b839ks2vas27hoq8hq0448qa6a0oj.apps.googleusercontent.com',
            redirect_uri: 'http://localhost/ATHINA-ESHOP/authentication/google_callback.php',
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
            client_id: '924345056652857',
            redirect_uri: 'http://localhost/ATHINA-ESHOP/authentication/facebook_callback.php',
            response_type: 'code',
            auth_type: 'rerequest'
        });

        window.location.href = 'https://www.facebook.com/v18.0/dialog/oauth?' + params.toString();
    }, true);
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Anti-bot: record page load time and mouse movement before FingerprintJS loads -->
    <script>
        const _fpPageLoadTime = Date.now();
        let   _fpMouseMoved   = false;

        document.addEventListener('mousemove', function () {
            _fpMouseMoved = true;
        }, { once: true });

        // Mobile: treat touchstart as "mouse moved"
        document.addEventListener('touchstart', function () {
            _fpMouseMoved = true;
        }, { once: true });
    </script>

    <!-- FingerprintJS free CDN — generates a stable visitorId per browser -->
    <script>
        import('https://openfpcdn.io/fingerprintjs/v4')
            .then(FingerprintJS => FingerprintJS.load())
            .then(fp => fp.get())
            .then(result => {
                document.getElementById('fp_visitor_id').value = result.visitorId;

                // Detect headless browsers (Puppeteer, Selenium, etc.)
                const isHeadless = (
                    navigator.webdriver === true ||
                    /HeadlessChrome/.test(navigator.userAgent) ||
                    (!window.chrome && /Chrome/.test(navigator.userAgent) && navigator.plugins.length === 0)
                ) ? 1 : 0;

                document.getElementById('fp_headless').value = isHeadless;
            })
            .catch(function () {
                // FingerprintJS blocked (adblocker/network) — leave fp_visitor_id empty
                // Backend will reject the empty fingerprint
            });

        // On submit: record elapsed time and whether the user moved the mouse
        document.querySelector('.auth-email-form').addEventListener('submit', function () {
            document.getElementById('fp_timing_ms').value   = Date.now() - _fpPageLoadTime;
            document.getElementById('fp_mouse_moved').value = _fpMouseMoved ? 1 : 0;
        });
    </script>
</body>
</html>
