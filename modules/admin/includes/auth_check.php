<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// Which roles are allowed to access the admin module
$allowedAdminRoles = ['admin', 'administrator', 'superadmin'];

/**
 * Hard gate:
 *  - If not logged in  -> send to login page
 *  - If logged in but not admin -> send to storefront home
 */
if (!$userId) {
    header('Location: /athina-eshop/authentication/login.php');
    exit;
}

if (!in_array($userRole, $allowedAdminRoles, true)) {
    header('Location: /athina-eshop/index.php');
    exit;
}

// Make admin ID/role available to all admin pages
$ADMIN_USER_ID = $userId;
$ADMIN_ROLE    = $userRole;