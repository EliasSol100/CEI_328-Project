<?php
session_start();
require_once "database.php";

if (isset($_SESSION["user"])) {
    header("Location: ../index.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["manual_email"])) {
    $manual_email = trim($_POST["manual_email"]);

    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
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
                <img src="../assets/images/athina-eshop-logo.png" alt="Athina E-Shop Logo">
            </div>
            <h3 class="mt-2">Create Your Account</h3>
            <p class="wizard-subtitle mb-0">
                Join Athina E-Shop to save your details and easily track your orders.
            </p>
        </div>

        <?php if (isset($_SESSION["registration_error"])): ?>
            <div class="alert alert-danger">
                <?= $_SESSION["registration_error"]; unset($_SESSION["registration_error"]); ?>
            </div>
        <?php endif; ?>

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

        <p class="mt-2 mb-1 text-muted auth-divider-text">Or use your email</p>

        <form method="POST" action="registration.php" class="mt-2 auth-email-form">
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
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>