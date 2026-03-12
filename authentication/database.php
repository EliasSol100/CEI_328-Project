<?php
// Enable detailed error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$hostName = "127.0.0.1";   // Use IP instead of localhost
$dbUser = "root";
$dbPassword = "";          // No password
$dbName = "athina_eshop";
$port = 3306;              // XAMPP MySQL default port
$port = 3306;              

$conn = mysqli_connect($hostName, $dbUser, $dbPassword, $dbName, $port);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set charset to utf8mb4
mysqli_set_charset($conn, "utf8mb4");
?>
