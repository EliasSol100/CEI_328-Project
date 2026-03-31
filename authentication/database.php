<?php
// Enable detailed error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Localhost (XAMPP) fallback — σε Docker διαβάζει από environment variables
$hostName = getenv('DB_HOST')     ?: "127.0.0.1";
$dbUser   = getenv('DB_USER')     ?: "root";
$dbPassword = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : "";
$dbName   = getenv('DB_NAME')     ?: "athina_eshop";
$port     = (int)(getenv('DB_PORT') ?: 3306);

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
