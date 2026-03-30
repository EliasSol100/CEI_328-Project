<?php
// Enable detailed error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$hostName = "127.0.0.1";   // Use IP instead of localhost
$dbUser = "root";
$dbPassword = "";          // No password
$dbName = "athina_eshop";
$port = 3306;              // XAMPP MySQL port

$conn = mysqli_connect($hostName, $dbUser, $dbPassword, $dbName, $port);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set charset to utf8mb4
mysqli_set_charset($conn, "utf8mb4");

if (!function_exists('authNormalizeRedirectTarget')) {
    function authNormalizeRedirectTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            return '';
        }
        if (preg_match('/[\r\n]/', $target)) {
            return '';
        }
        if (preg_match('#^(?:https?:)?//#i', $target)) {
            return '';
        }
        if ($target[0] !== '/') {
            return '';
        }
        return $target;
    }
}

if (!function_exists('rememberAuthRedirectTarget')) {
    function rememberAuthRedirectTarget(string $target): void
    {
        $normalized = authNormalizeRedirectTarget($target);
        if ($normalized !== '') {
            $_SESSION['post_auth_redirect'] = $normalized;
        }
    }
}

if (!function_exists('peekAuthRedirectTarget')) {
    function peekAuthRedirectTarget(string $fallback = '../index.php'): string
    {
        $target = authNormalizeRedirectTarget((string)($_SESSION['post_auth_redirect'] ?? ''));
        return $target !== '' ? $target : $fallback;
    }
}

if (!function_exists('consumeAuthRedirectTarget')) {
    function consumeAuthRedirectTarget(string $fallback = '../index.php'): string
    {
        $target = peekAuthRedirectTarget($fallback);
        unset($_SESSION['post_auth_redirect']);
        return $target;
    }
}
?>
