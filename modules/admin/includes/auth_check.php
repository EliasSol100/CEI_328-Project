<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Uncomment the block below when the login/session system is fully integrated.
// For development, admin access is open.
/*
if (empty($_SESSION['userID']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: /athina-eshop/authentication/login.php');
    exit;
}
*/
