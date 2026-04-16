<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 3) . '/authentication/database.php';

/**
 * Resolve current user and role from session.
 * Supports both the new $_SESSION['user'] array and legacy flat keys.
 */
$userId   = null;
$userRole = 'guest';

if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    // New structure: id / role / full_name / email ...
    $userId       = $_SESSION['user']['id']     ?? $_SESSION['user']['userID'] ?? null;
    $userRoleRaw  = $_SESSION['user']['role']   ?? 'user';
} else {
    // Legacy fallback: flat keys
    $userId       = $_SESSION['user_id']        ?? $_SESSION['userID'] ?? null;
    $userRoleRaw  = $_SESSION['role']           ?? 'user';
}

// Normalise role for comparison
$userRole = strtolower((string)$userRoleRaw);

// Keep storefront session structure available even on older admin-only logins.
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

// Which roles are allowed to access the admin module
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

/**
 * Hard gate:
 *  - If not logged in  -> send to login page
 *  - If logged in but not admin -> send to storefront home
 */
if (!$userId) {
    header('Location: ' . $projectBasePath . '/authentication/login.php');
    exit;
}

if (!in_array($userRole, $allowedAdminRoles, true)) {
    header('Location: ' . $projectBasePath . '/index.php');
    exit;
}

// Make admin ID/role available to all admin pages
$ADMIN_USER_ID = $userId;
$ADMIN_ROLE    = $userRole;
