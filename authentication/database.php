<?php
// Enable detailed error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once dirname(__DIR__) . '/include/security.php';

if (!function_exists('app_load_env_file')) {
    function app_load_env_file(string $filePath): void
    {
        static $loadedFiles = [];

        $normalizedPath = str_replace('\\', '/', $filePath);
        if (isset($loadedFiles[$normalizedPath]) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            $loadedFiles[$normalizedPath] = true;
            return;
        }

        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if ($trimmedLine === '' || $trimmedLine[0] === '#') {
                continue;
            }

            $parts = explode('=', $trimmedLine, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if ($key === '') {
                continue;
            }

            $length = strlen($value);
            if ($length >= 2) {
                $firstChar = $value[0];
                $lastChar = $value[$length - 1];
                if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if (getenv($key) === false) {
                putenv($key . '=' . $value);
            }

            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }

            if (!array_key_exists($key, $_SERVER)) {
                $_SERVER[$key] = $value;
            }
        }

        $loadedFiles[$normalizedPath] = true;
    }
}

if (!function_exists('app_env_value')) {
    function app_env_value(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        if (array_key_exists($key, $_ENV)) {
            return (string)$_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER)) {
            return (string)$_SERVER[$key];
        }

        return $default;
    }
}

if (!function_exists('app_project_path')) {
    function app_project_path(): string
    {
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $projectPath = rtrim(str_replace('\\', '/', dirname(dirname($scriptName))), '/');

        if ($projectPath === '' || $projectPath === '.' || $projectPath === '/') {
            return '';
        }

        return $projectPath;
    }
}

if (!function_exists('app_base_url')) {
    function app_base_url(): string
    {
        $configuredUrl = trim((string)app_env_value('APP_URL', ''));
        if ($configuredUrl !== '') {
            return rtrim($configuredUrl, '/');
        }

        $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwardedProto !== '') {
            $scheme = trim(explode(',', $forwardedProto)[0]);
        } else {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        }

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        return $scheme . '://' . $host . app_project_path();
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        $baseUrl = app_base_url();
        $normalizedPath = trim($path);
        if ($normalizedPath === '') {
            return $baseUrl;
        }

        return $baseUrl . '/' . ltrim($normalizedPath, '/');
    }
}

// Shared hosting usually does not inject .env into getenv(), so load it manually when present.
app_load_env_file(dirname(__DIR__) . '/.env');
app_apply_security_headers();
app_harden_session_cookie();

$hostName = app_env_value('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
$dbUser = app_env_value('DB_USER', 'root') ?? 'root';
$dbPassword = app_env_value('DB_PASSWORD', '') ?? '';
$dbName = app_env_value('DB_NAME', 'athina_eshop') ?? 'athina_eshop';
$port = (int)(app_env_value('DB_PORT', '3306') ?: 3306);

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
