<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 3) . '/authentication/database.php';

$userId   = null;
$userRole = 'guest';

if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {

    $userId       = $_SESSION['user']['id']     ?? $_SESSION['user']['userID'] ?? null;
    $userRoleRaw  = $_SESSION['user']['role']   ?? 'user';
} else {

    $userId       = $_SESSION['user_id']        ?? $_SESSION['userID'] ?? null;
    $userRoleRaw  = $_SESSION['role']           ?? 'user';
}

$userRole = strtolower((string)$userRoleRaw);

if ($userId && (!isset($_SESSION['user']) || !is_array($_SESSION['user']))) {
    $_SESSION['user'] = [
        'id' => $userId,
        'userID' => $userId,
        'role' => $userRole,
        'email' => $_SESSION['email'] ?? null,
        'full_name' => $_SESSION['full_name'] ?? 'User',
        'profile_complete' => $_SESSION['profile_complete'] ?? true,
        'is_verified' => $_SESSION['is_verified'] ?? 1,
    ];
}

$allowedAdminRoles = ['admin', 'administrator', 'superadmin'];

function adminBuildProjectBasePath(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = rtrim(str_replace('\\', '/', dirname(dirname(dirname($script)))), '/');
    if ($base === '/' || $base === '.' || $base === '') {
        return '';
    }
    return $base;
}

$projectBasePath = adminBuildProjectBasePath();

if (!$userId) {
    header('Location: ' . $projectBasePath . '/authentication/login.php');
    exit;
}

if (!in_array($userRole, $allowedAdminRoles, true)) {
    header('Location: ' . $projectBasePath . '/index.php');
    exit;
}

$ADMIN_USER_ID = $userId;
$ADMIN_ROLE    = $userRole;
