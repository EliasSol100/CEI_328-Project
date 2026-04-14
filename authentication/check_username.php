<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/../include/security.php";

header("Content-Type: text/plain; charset=UTF-8");

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
    http_response_code(405);
    echo "invalid";
    exit();
}

if (!app_verify_csrf()) {
    http_response_code(403);
    echo "forbidden";
    exit();
}

$username = trim($_POST["username"] ?? "");
if (!app_is_valid_username($username)) {
    echo "invalid";
    exit();
}

$currentUserId = 0;
if (isset($_SESSION["user"]["id"])) {
    $currentUserId = (int)$_SESSION["user"]["id"];
} elseif (isset($_SESSION["user_id"])) {
    $currentUserId = (int)$_SESSION["user_id"];
}

if ($currentUserId > 0) {
    $stmt = $conn->prepare("SELECT userID FROM users WHERE username = ? AND userID != ? LIMIT 1");
    if (!$stmt) {
        echo "available";
        exit();
    }
    $stmt->bind_param("si", $username, $currentUserId);
} else {
    $stmt = $conn->prepare("SELECT userID FROM users WHERE username = ? LIMIT 1");
    if (!$stmt) {
        echo "available";
        exit();
    }
    $stmt->bind_param("s", $username);
}

$stmt->execute();
$stmt->store_result();

echo ($stmt->num_rows > 0) ? "taken" : "available";
$stmt->close();
