<?php
session_start();
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/database.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed');
}

app_require_csrf(false, 'Invalid logout request');

app_auth_logout($conn, true);
header("Location: ../index.php");
exit();
?>
