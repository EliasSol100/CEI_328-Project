<?php
session_start();
require_once __DIR__ . "/authentication/database.php";
require_once __DIR__ . "/include/security.php";

function newsletterRedirectTarget(): string
{
    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer !== '') {
        $parts = parse_url($referer);
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || $host === 'creationsbyathina.com' || $host === 'www.creationsbyathina.com') {
            return $referer;
        }
    }
    return 'index.php';
}

function newsletterFlash(string $type, string $message): void
{
    $_SESSION['newsletter_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

$redirectTarget = newsletterRedirectTarget();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . $redirectTarget);
    exit;
}

app_require_csrf(false, 'Invalid subscription request. Please refresh and try again.');

$email = trim((string)($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    newsletterFlash('error', 'Please enter a valid email address.');
    header('Location: ' . $redirectTarget);
    exit;
}

$createTableSql = "
    CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        subscriberID INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        sourcePage VARCHAR(255) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        subscribedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_newsletter_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

$sourcePage = trim((string)parse_url($redirectTarget, PHP_URL_PATH));

if (!($conn instanceof mysqli) || !$conn->query($createTableSql)) {
    newsletterFlash('error', 'We could not save your subscription right now. Please try again later.');
    header('Location: ' . $redirectTarget);
    exit;
}

$stmt = $conn->prepare("
    INSERT INTO newsletter_subscribers (email, sourcePage, status)
    VALUES (?, ?, 'active')
    ON DUPLICATE KEY UPDATE
        sourcePage = VALUES(sourcePage),
        status = 'active',
        updatedAt = CURRENT_TIMESTAMP
");

if (!$stmt) {
    newsletterFlash('error', 'We could not save your subscription right now. Please try again later.');
    header('Location: ' . $redirectTarget);
    exit;
}

$stmt->bind_param('ss', $email, $sourcePage);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    newsletterFlash('error', 'We could not save your subscription right now. Please try again later.');
    header('Location: ' . $redirectTarget);
    exit;
}

newsletterFlash('success', 'Thanks for subscribing. We will keep you updated.');
header('Location: ' . $redirectTarget);
exit;
