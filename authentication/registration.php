<?php
// Ξεκινάμε τη session για να μπορούμε να αποθηκεύουμε/διαβάζουμε session variables
session_start();

// Φορτώνουμε τη σύνδεση με τη βάση δεδομένων από το database.php
require_once "database.php";

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
                <?= $_SESSION["registration_error"]; unset($_SESSION["registration_error"]); ?>
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
