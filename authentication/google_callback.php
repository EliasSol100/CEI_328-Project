<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

session_start();
require_once "database.php";
require_once __DIR__ . "/../include/security.php";
require_once __DIR__ . "/../include/platform_integrations.php";

app_system_config_seed_defaults($conn, app_platform_integrations_default_values());
app_social_auth_ensure_user_schema($conn);

$redirectUri = app_url('/authentication/google_callback.php');
$redirectPage = (($_SESSION['oauth_origin_google'] ?? 'registration') === 'login') ? 'login.php' : 'registration.php';
unset($_SESSION['oauth_origin_google']);

if (!app_verify_oauth_state('google', $_GET['state'] ?? null)) {
    $_SESSION["registration_error"] = "Google login could not be verified. Please try again.";
    header('Location: ' . $redirectPage);
    exit();
}

$googleConfig = app_social_auth_config($conn, 'google');
$clientID = (string)($googleConfig['client_id'] ?? '');
$clientSecret = (string)($googleConfig['client_secret'] ?? '');

if ($clientID === '' || $clientSecret === '') {
    $_SESSION["registration_error"] = "Google login is not configured yet.";
    header('Location: ' . $redirectPage);
    exit();
}

if (!isset($_GET['code'])) {
    $_SESSION["registration_error"] = "Google login was cancelled or failed. Please try again.";
    header('Location: ' . $redirectPage);
    exit();
}

$code = trim((string)$_GET['code']);

$tokenRequest = curl_init('https://oauth2.googleapis.com/token');
curl_setopt($tokenRequest, CURLOPT_POST, true);
curl_setopt($tokenRequest, CURLOPT_RETURNTRANSFER, true);
curl_setopt($tokenRequest, CURLOPT_POSTFIELDS, http_build_query([
    'code' => $code,
    'client_id' => $clientID,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code',
]));
$tokenResponse = curl_exec($tokenRequest);
curl_close($tokenRequest);

$tokenData = json_decode((string)$tokenResponse, true);
if (!is_array($tokenData) || empty($tokenData['access_token'])) {
    $_SESSION["registration_error"] = "Failed to get an access token from Google.";
    header('Location: ' . $redirectPage);
    exit();
}

$userRequest = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
curl_setopt($userRequest, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $tokenData['access_token'],
]);
curl_setopt($userRequest, CURLOPT_RETURNTRANSFER, true);
$userInfo = json_decode((string)curl_exec($userRequest), true);
curl_close($userRequest);

$googleId = trim((string)($userInfo['id'] ?? ''));
$fullName = trim((string)($userInfo['name'] ?? 'Google User'));
$email = trim((string)($userInfo['email'] ?? ''));

if ($googleId === '' || $email === '') {
    $_SESSION["registration_error"] = "Google did not return the account details required for sign-in.";
    header('Location: ' . $redirectPage);
    exit();
}

$user = null;

$stmt = $conn->prepare("SELECT *, userID AS id FROM users WHERE google_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("s", $googleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();
}

if (!$user) {
    $stmt = $conn->prepare("SELECT *, userID AS id FROM users WHERE email = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}

if ($user) {
    $userId = (int)($user['id'] ?? 0);
    if ($userId > 0 && trim((string)($user['google_id'] ?? '')) === '') {
        $linkStmt = $conn->prepare("UPDATE users SET google_id = ?, is_verified = 1 WHERE userID = ?");
        if ($linkStmt) {
            $linkStmt->bind_param("si", $googleId, $userId);
            $linkStmt->execute();
            $linkStmt->close();
        }
        $user['google_id'] = $googleId;
        $user['is_verified'] = 1;
    }
} else {
    $dummyPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $username = app_social_auth_generate_username($conn, 'google', $googleId);

    $stmt = $conn->prepare("
        INSERT INTO users (full_name, email, google_id, username, password, is_verified, profile_complete, role)
        VALUES (?, ?, ?, ?, ?, 1, 0, 'user')
    ");

    if (!$stmt) {
        $_SESSION["registration_error"] = "We could not create your Google account. Please try again.";
        header('Location: ' . $redirectPage);
        exit();
    }

    $stmt->bind_param("sssss", $fullName, $email, $googleId, $username, $dummyPassword);
    $ok = $stmt->execute();
    $newUserId = (int)$stmt->insert_id;
    $stmt->close();

    if (!$ok || $newUserId <= 0) {
        $_SESSION["registration_error"] = "We could not create your Google account. Please try again.";
        header('Location: ' . $redirectPage);
        exit();
    }

    $stmt = $conn->prepare("SELECT *, userID AS id FROM users WHERE userID = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $newUserId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}

if (!$user || empty($user['id'])) {
    $_SESSION["registration_error"] = "Google login could not be completed. Please try again.";
    header('Location: ' . $redirectPage);
    exit();
}

$userId = (int)$user['id'];
$previousLogin = $user['last_login'] ?? null;

$updateLogin = $conn->prepare("UPDATE users SET last_login = NOW() WHERE userID = ?");
if ($updateLogin) {
    $updateLogin->bind_param("i", $userId);
    $updateLogin->execute();
    $updateLogin->close();
}

session_regenerate_id(true);

$profileComplete = !empty($user['profile_complete']);

$_SESSION['user'] = [
    'id' => $userId,
    'full_name' => (string)($user['full_name'] ?? $fullName),
    'email' => (string)($user['email'] ?? $email),
    'role' => (string)($user['role'] ?? 'user'),
    'profile_complete' => $profileComplete,
    'is_verified' => 1,
    'last_login' => $previousLogin,
];
$_SESSION['user_id'] = $userId;
$_SESSION['role'] = (string)($user['role'] ?? 'user');
$_SESSION['email'] = (string)($user['email'] ?? $email);
$_SESSION['full_name'] = (string)($user['full_name'] ?? $fullName);

if ($profileComplete) {
    header("Location: " . consumeAuthRedirectTarget("../index.php"));
} else {
    header("Location: complete_profile.php");
}
exit();
