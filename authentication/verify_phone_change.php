<?php
session_start();
require_once "database.php";

// Ensure the user is logged in
if (!isset($_SESSION["user"])) {
    header("Location: login.php");
    exit();
}

if (!isset($_SESSION["phone_change_code"]) || !isset($_SESSION["pending_phone"])) {
    header("Location: ../index.php");
    exit();
}

$userId = $_SESSION["user"]["id"];
$expectedCode = $_SESSION["phone_change_code"];
$pendingPhone = $_SESSION["pending_phone"];

if (isset($_POST["verify"])) {
    $enteredCode = trim($_POST["verification_code"]);

    if ($enteredCode == $expectedCode) {
        // Update phone number in database
        $sql = "UPDATE users SET phone = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "si", $pendingPhone, $userId);
        mysqli_stmt_execute($stmt);

        // Clear session values
        unset($_SESSION["phone_change_code"]);
        unset($_SESSION["pending_phone"]);

        $_SESSION["success"] = "Your phone number has been updated successfully.";
        header("Location: ../index.php");
        exit();
    } else {
        $_SESSION["error"] = "Invalid verification code. Please try again.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Phone Change</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap & Toastr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/styling/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
</head>
<body>
    <div class="container py-5">
        <h2 class="mb-4">Verify Phone Change</h2>
        <p>Enter the 6-digit code sent to your email to confirm your phone number update.</p>
        <form method="post" action="verify_phone_change.php">
            <div class="form-group mb-3">
                <label for="verification_code">Verification Code</label>
                <input type="text" class="form-control" name="verification_code" required placeholder="e.g. 123456">
            </div>
            <input type="submit" class="btn btn-success" value="Verify" name="verify">
        </form>
    </div>

    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <script>
        <?php if (isset($_SESSION['error'])): ?>
            toastr.error("<?php echo $_SESSION['error']; ?>");
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            toastr.success("<?php echo $_SESSION['success']; ?>");
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
    </script>
</body>
</html>

