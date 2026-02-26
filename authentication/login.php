<?php
session_start();
if (isset($_SESSION["user"])) {
    header("Location: ../index.php");
    exit();
}

require_once "database.php";
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

            <?php
            if (isset($_POST["login"])) {
                $loginInput = trim($_POST["login_input"]);
                $password   = $_POST["password"];

                $sql  = "SELECT * FROM users WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?)";
                $stmt = mysqli_stmt_init($conn);

                if (mysqli_stmt_prepare($stmt, $sql)) {
                    mysqli_stmt_bind_param($stmt, "ss", $loginInput, $loginInput);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);

                    if ($user = mysqli_fetch_assoc($result)) {
                        if (password_verify($password, $user["password"])) {

                            $now = new DateTime();

                            // Check if previous 2FA is still valid (within 48 hours)
                            $twofaValid =
                                !empty($user["twofa_expires"]) &&
                                (new DateTime($user["twofa_expires"])) > $now;

                            if ($twofaValid) {
                                // 2FA still valid → direct login

                                // get previous last_login for session display
                                $prevLogin = null;
                                $getLogin  = $conn->prepare("SELECT last_login FROM users WHERE id = ?");
                                $getLogin->bind_param("i", $user['id']);
                                $getLogin->execute();
                                $loginResult = $getLogin->get_result();
                                if ($row = $loginResult->fetch_assoc()) {
                                    $prevLogin = $row['last_login'];
                                }
                                $getLogin->close();

                                // update last_login to now
                                $updateLogin = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                                $updateLogin->bind_param("i", $user['id']);
                                $updateLogin->execute();
                                $updateLogin->close();

                                // create full user session
                                $_SESSION["user"] = [
                                    "id"         => $user["id"],
                                    "email"      => $user["email"],
                                    "full_name"  => $user["full_name"],
                                    "role"       => $user["role"],
                                    "last_login" => $prevLogin
                                ];
                                $_SESSION['user_id'] = $user['id'];
                                $_SESSION['role']    = $user['role'];

                                header("Location: ../index.php");
                                exit();
                            } else {
                                // 2FA required (first time or expired > 48h)
                                $_SESSION['temp_user_id'] = $user['id'];
                                header("Location: twofa_verify.php");
                                exit();
                            }
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
                redirect_uri: 'http://localhost/ATHINA-ESHOP/authentication/google_callback.php',
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
                client_id: '924345056652857',
                redirect_uri: 'http://localhost/ATHINA-ESHOP/authentication/facebook_callback.php',
                response_type: 'code',
                auth_type: 'rerequest'
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