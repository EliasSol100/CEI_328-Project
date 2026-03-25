<?php
session_start();
require_once __DIR__ . '/../include/security.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed');
}

app_require_csrf(false, 'Invalid logout request');

session_destroy();
header("Location: ../index.php");
exit();
?>
