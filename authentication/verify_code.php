<?php
session_start();
require_once "database.php";

// Get data from the AJAX request
$phone = $_POST['phone'];
$verification_code = $_POST['verification_code'];

// Verification code is valid, fetch user data and update is_verified
$sql = "SELECT * FROM users WHERE phone = ? AND verification_code = ?";
$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("ss", $phone, $verification_code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // Update verification status
        $update_sql = "UPDATE users SET is_verified = 1 WHERE phone = ?";
        $update_stmt = $conn->prepare($update_sql);

        if ($update_stmt) {
            $update_stmt->bind_param("s", $phone);
            $update_stmt->execute();

            // Set session variables
            $_SESSION["user_id"] = $user["id"];
            $_SESSION["username"] = $user["username"];
            $_SESSION["email"] = $user["email"];
            $_SESSION["role"] = $user["role"]; // optional
            $_SESSION["user"] = [
                "id" => $user["id"],
                "email" => $user["email"],
                "full_name" => $user["full_name"]
            ];

            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Update failed.']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid code.']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Statement prepare failed.']);
}
?>


