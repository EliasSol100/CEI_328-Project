<?php
session_start();
require_once "database.php";

// If user already logged in, redirect to index
if (isset($_SESSION["user"])) {
    header("Location: index.php");
    exit();
}

// Handle manual email submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["manual_email"])) {
    $manual_email = trim($_POST["manual_email"]);

    // Check if email already exists
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="registration_page">

    <!-- Crochet GIF background + overlay -->
    <div class="registration-bg"></div>
    <div class="registration-overlay"></div>

    <div class="wizard-box text-center">
        <div class="wizard-header">
            <!-- Athina E-Shop crochet badge logo -->
            <div class="wizard-logo">
                <img src="assets/images/athina-eshop-logo.png" alt="Athina E-Shop Logo">
            </div>
            <h3 class="mt-2">Create Your Account</h3>
        </div>

        <!-- Display registration errors if any (manual + Facebook failures) -->
        <?php if (isset($_SESSION["registration_error"])): ?>
            <div class="alert alert-danger">
                <?= $_SESSION["registration_error"]; unset($_SESSION["registration_error"]); ?>
            </div>
        <?php endif; ?>

        <!-- Google Sign-In -->
        <div class="mb-3">
            <button
                type="button"
                id="google-signin-btn"
                class="btn btn-light border d-flex align-items-center justify-content-center gap-2 mx-auto"
                style="max-width: 300px;"
            >
                <img src="https://developers.google.com/identity/images/g-logo.png"
                     style="height: 20px;" alt="Google logo">
                Continue with Google
            </button>
        </div>

        <!-- Facebook Sign-In -->
        <div class="mb-3">
            <button
                type="button"
                id="facebook-signin-btn"
                class="btn btn-primary d-flex align-items-center justify-content-center gap-2 mx-auto"
                style="max-width: 300px; background-color: #1877f2; border-color: #1877f2;"
            >
                <i class="bi bi-facebook"></i>
                Continue with Facebook
            </button>
        </div>

        <!-- Divider text -->
        <p class="mt-2 mb-1 text-muted" style="font-size: 0.9rem;">Or use your email</p>

        <!-- Manual Email Entry -->
        <form method="POST" action="registration.php" class="mt-2" style="max-width: 300px; margin: auto;">
            <div class="form-group mb-3 text-start">
                <input
                    type="email"
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
    // ---- Google OAuth ----
    document.getElementById('google-signin-btn').addEventListener('click', function () {
        const params = new URLSearchParams({
            client_id: '901502356414-324b839ks2vas27hoq8hq0448qa6a0oj.apps.googleusercontent.com',
            redirect_uri: 'http://localhost/athina-eshop/google_callback.php',
            response_type: 'code',
            scope: 'email profile',
            access_type: 'online',
            include_granted_scopes: 'true',
            prompt: 'select_account'
        });

        const authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' + params.toString();
        window.location.href = authUrl;
    });

    // ---- Facebook OAuth ----
    document.getElementById('facebook-signin-btn').addEventListener('click', function () {
        const params = new URLSearchParams({
            client_id: '924345056652857',
            redirect_uri: 'http://localhost/athina-eshop/facebook_callback.php',
            response_type: 'code',
            // you *can* add scope here (email,public_profile) once it's properly configured in Meta
            // scope: 'email,public_profile',
            auth_type: 'rerequest'
        });

        const fbAuthUrl = 'https://www.facebook.com/v18.0/dialog/oauth?' + params.toString();
        window.location.href = fbAuthUrl;
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>