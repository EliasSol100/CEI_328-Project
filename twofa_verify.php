<?php
session_start();
require_once "database.php";

if (isset($_POST["verify"])) {
    $code = trim($_POST["twofa_code"]);
    $user_id = $_SESSION["temp_user_id"];

    $stmt = $conn->prepare("SELECT * FROM users WHERE id=? AND twofa_code=? AND twofa_expires > NOW()");
    $stmt->bind_param("is", $user_id, $code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        $_SESSION["user"] = [
            "id" => $user["id"],
            "email" => $user["email"],
            "full_name" => $user["full_name"],
            "role" => $user["role"]
            
        ];
        unset($_SESSION['temp_user_id']);
        unset($_SESSION['temp_user_role']);

        header("Location: index.php");
        exit();
    } else {
        $error = "Invalid or expired code.";
    }
}
?>

<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <title>2FA Verification</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <form action="twofa_verify.php" method="post">
        <h2>Two-Factor Authentication (2FA) Verification</h2>
        <?php if (isset($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>
        <div class="form-group">
            <label for="twofa_code">2FA Code (Check your email for the 2FA code)</label>
            <input type="text" name="twofa_code" id="twofa_code" class="form-control" placeholder="Enter the 2FA code" required maxlength="6">
        </div>
        <input type="submit" name="verify" class="btn btn-primary" value="Verify Code">
    </form>
</div>
</body>
</html>
