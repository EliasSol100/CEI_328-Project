<?php
session_start();
require_once __DIR__ . "/database.php";

header("Content-Type: text/plain; charset=UTF-8");

$username = trim($_POST["username"] ?? "");
if ($username === "") {
    echo "taken";
    exit();
}

$currentUserId = isset($_SESSION["user_id"]) ? (int)$_SESSION["user_id"] : 0;

if ($currentUserId > 0) {
    $stmt = $conn->prepare("SELECT userID FROM users WHERE username = ? AND userID != ? LIMIT 1");
} else {
    $stmt = $conn->prepare("SELECT userID FROM users WHERE username = ? LIMIT 1");
}

if (!$stmt) {
    echo "taken";
    exit();
}

if ($currentUserId > 0) {
    $stmt->bind_param("si", $username, $currentUserId);
} else {
    $stmt->bind_param("s", $username);
}

$stmt->execute();
$stmt->store_result();

echo ($stmt->num_rows > 0) ? "taken" : "available";
$stmt->close();
