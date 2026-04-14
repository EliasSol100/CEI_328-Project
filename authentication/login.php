<?php
session_start();
require_once __DIR__ . "/../include/security.php";
require_once "database.php";

if (isset($_GET['redirect'])) {
    rememberAuthRedirectTarget((string)$_GET['redirect']);
}

// Αν ο χρήστης είναι ήδη logged in, δεν χρειάζεται να ξαναμπεί — πήγαινε στο index
if (isset($_SESSION["user"])) {
    $currentUser = $_SESSION["user"];
    $profileComplete = !empty($currentUser["profile_complete"]);
    $isVerified = !empty($currentUser["is_verified"]);
    if (!$profileComplete) {
        header("Location: complete_profile.php");
    } elseif (!$isVerified) {
        header("Location: verify.php");
    } else {
        header("Location: " . consumeAuthRedirectTarget("../index.php"));
    }
    exit();
}

$googleState = app_oauth_state('google');
$facebookState = app_oauth_state('facebook');
$googleRedirectUri = app_url('/authentication/google_callback.php');
$facebookRedirectUri = app_url('/authentication/facebook_callback.php');
$_SESSION['oauth_origin_google'] = 'login';
$_SESSION['oauth_origin_facebook'] = 'login';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    app_require_csrf(false, "Invalid request token. Please refresh and try again.");
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
                <img src="../assets/images/athina-eshop-logo.png" alt="Athina E-Shop Logo">
            </div>
            <h3 class="mt-2">System Login</h3>
            <p class="wizard-subtitle mb-0">
                Sign in to your Athina E-Shop account to view your details and track your orders.
            </p>
        </div>

        <div class="wizard-content">
            <!-- Social Sign-In -->
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

            <p class="mt-2 mb-3 text-muted text-center auth-divider-text">
                Or login with your email or username
            </p>

            <?php if (isset($_SESSION["registration_error"])): ?>
                <div class="alert alert-danger"><?= htmlspecialchars((string)$_SESSION["registration_error"]) ?></div>
                <?php unset($_SESSION["registration_error"]); ?>
            <?php endif; ?>

            <?php
            // Εκτελείται μόνο όταν ο χρήστης υποβάλλει το form (πατάει Login)
            if (isset($_POST["login"])) {
                $loginInput = trim($_POST["login_input"]); // email ή username
                $password   = $_POST["password"];

                // Ψάχνει στη DB με email ή username (case-insensitive)
                // Το userID AS id το κάνει alias ώστε να δουλεύει παντού ως $user["id"]
                $sql  = "SELECT *, userID AS id FROM users WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?)";
                $stmt = mysqli_stmt_init($conn);

                if (mysqli_stmt_prepare($stmt, $sql)) {
                    // Περνάει το loginInput δύο φορές: μία για email, μία για username
                    mysqli_stmt_bind_param($stmt, "ss", $loginInput, $loginInput);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);

                    if ($user = mysqli_fetch_assoc($result)) {
                        // password_verify: συγκρίνει plain text με το hashed password της DB
                        if (password_verify($password, $user["password"])) {

                            // Αποθηκεύει την προηγούμενη σύνδεση πριν την αντικαταστήσει
                            $prevLogin = null;
                            $getLogin  = $conn->prepare("SELECT last_login FROM users WHERE userID = ?");
                            $getLogin->bind_param("i", $user['id']);
                            $getLogin->execute();
                            $loginResult = $getLogin->get_result();
                            if ($row = $loginResult->fetch_assoc()) {
                                $prevLogin = $row['last_login'];
                            }
                            $getLogin->close();

                            // Ενημερώνει το last_login στη DB με την τωρινή ώρα
                            $updateLogin = $conn->prepare("UPDATE users SET last_login = NOW() WHERE userID = ?");
                            $updateLogin->bind_param("i", $user['id']); //bind_param san to pdo
                            $updateLogin->execute();
                            $updateLogin->close();

                            // Ελέγχει αν τα υποχρεωτικά πεδία προφίλ είναι συμπληρωμένα
                            $fieldsComplete =
                                !empty($user["country"])  &&
                                !empty($user["city"])     &&
                                !empty($user["address"])  &&
                                !empty($user["postcode"]) &&
                                !empty($user["dob"])      && //date of birth
                                !empty($user["phone"]);

                            // Ελέγχει αν το email έχει επαληθευτεί (0 ή 1 στη DB)
                            $isVerified = ((int)($user["is_verified"] ?? 0) === 1);
                            //$user["is_verified"] = "1"  → (int)"1" = 1 → 1 === 1 → true
                            //$user["is_verified"] = "0"  → (int)"0" = 0 → 0 === 1 → false
                            //$user["is_verified"] = null → ?? 0       → 0 === 1 → false

                            // Δημιουργεί το session — αυτό είναι το "login"
                            $_SESSION["user"] = [
                                "id"               => $user["id"],
                                "email"            => $user["email"],
                                "full_name"        => $user["full_name"],
                                "role"             => $user["role"],
                                "last_login"       => $prevLogin,
                                "profile_complete" => $fieldsComplete ? 1 : 0,
                                "is_verified"      => $isVerified ? 1 : 0
                            ];
                            // Παλιές μεταβλητές session για backwards compatibility με άλλες σελίδες
                            $_SESSION['user_id']   = $user['id'];
                            $_SESSION['role']      = $user['role'];
                            $_SESSION['email']     = $user['email'];
                            $_SESSION['full_name'] = $user['full_name'];

                            // Αν το προφίλ δεν είναι ολοκληρωμένο, πήγαινε στο wizard
                            if (!$fieldsComplete) {
                                header("Location: complete_profile.php");
                                exit();
                            }

                            // Αν το email δεν είναι verified, πήγαινε στην επαλήθευση
                            if (!$isVerified) {
                                header("Location: verify.php");
                                exit();
                            }

                            // Όλα εντάξει — πήγαινε στην αρχική
                            header("Location: " . consumeAuthRedirectTarget("../index.php"));
                            exit();
                        } else {
                            echo "<div class='alert alert-danger'>Incorrect password.</div>";
                        }
                    } else {
                        echo "<div class='alert alert-danger'>Email or username not found.</div>";
                    }
                } else {
                    echo "<div class='alert alert-danger'>Something went wrong. Please try again.</div>";
                }
            }
            ?>

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
                        required
                    >
                </div>

                <div class="form-group mb-3">
                    <label for="password">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" name="password" id="password" required>
                        <span class="input-group-text toggle-password" data-target="#password">
                            <i class="bi bi-eye"></i>
                        </span>
                    </div>
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
        document.getElementById('google-signin-btn').addEventListener('click', function () {
            const params = new URLSearchParams({
                client_id: '901502356414-324b839ks2vas27hoq8hq0448qa6a0oj.apps.googleusercontent.com',
                redirect_uri: <?= json_encode($googleRedirectUri, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                response_type: 'code',
                scope: 'email profile',
                access_type: 'online',
                include_granted_scopes: 'true',
                prompt: 'select_account',
                state: <?= json_encode($googleState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
            });

            const authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' + params.toString();
            window.location.href = authUrl;
        });

        document.getElementById('facebook-signin-btn').addEventListener('click', function () {
            const params = new URLSearchParams({
                client_id: '924345056652857',
                redirect_uri: <?= json_encode($facebookRedirectUri, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                response_type: 'code',
                auth_type: 'rerequest',
                state: <?= json_encode($facebookState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
            });

            const fbAuthUrl = 'https://www.facebook.com/v18.0/dialog/oauth?' + params.toString();
            window.location.href = fbAuthUrl;
        });

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
