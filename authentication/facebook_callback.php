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

$redirectUri = app_url('/authentication/facebook_callback.php');
$redirectPage = (($_SESSION['oauth_origin_facebook'] ?? 'registration') === 'login') ? 'login.php' : 'registration.php';
unset($_SESSION['oauth_origin_facebook']);

if (!app_verify_oauth_state('facebook', $_GET['state'] ?? null)) {
    $_SESSION["registration_error"] = "Facebook login could not be verified. Please try again.";
    header("Location: " . $redirectPage);
    exit();
}

$facebookConfig = app_social_auth_config($conn, 'facebook');
$appId = (string)($facebookConfig['client_id'] ?? '');
$appSecret = (string)($facebookConfig['client_secret'] ?? '');

if ($appId === '' || $appSecret === '') {
    $_SESSION["registration_error"] = "Facebook login is not configured yet.";
    header("Location: " . $redirectPage);
    exit();
}

if (!isset($_GET['code'])) {
    $_SESSION["registration_error"] = "Facebook login was cancelled or failed. Please try again or use your email.";
    header("Location: " . $redirectPage);
    exit();
}

$code = trim((string)$_GET['code']);

$tokenUrl = 'https://graph.facebook.com/v21.0/oauth/access_token?' . http_build_query([
    'client_id' => $appId,
    'redirect_uri' => $redirectUri,
    'client_secret' => $appSecret,
    'code' => $code,
]);

$tokenRequest = curl_init($tokenUrl);
curl_setopt($tokenRequest, CURLOPT_RETURNTRANSFER, true);
$tokenResponse = curl_exec($tokenRequest);
curl_close($tokenRequest);

$tokenData = json_decode((string)$tokenResponse, true);
if (!is_array($tokenData) || empty($tokenData['access_token'])) {
    $_SESSION["registration_error"] = "Failed to get an access token from Facebook.";
    header("Location: " . $redirectPage);
    exit();
}

$userInfoRequest = curl_init('https://graph.facebook.com/v21.0/me?fields=id,name,email&access_token=' . urlencode((string)$tokenData['access_token']));
curl_setopt($userInfoRequest, CURLOPT_RETURNTRANSFER, true);
$userInfoResponse = curl_exec($userInfoRequest);
curl_close($userInfoRequest);

$userInfo = json_decode((string)$userInfoResponse, true);
$facebookId = trim((string)($userInfo['id'] ?? ''));
$fullName = trim((string)($userInfo['name'] ?? 'Facebook User'));
$email = trim((string)($userInfo['email'] ?? ''));

if ($facebookId === '') {
    $_SESSION["registration_error"] = "Facebook did not return a valid account ID.";
    header("Location: " . $redirectPage);
    exit();
}

$user = null;

$stmt = $conn->prepare("SELECT *, userID AS id FROM users WHERE facebook_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("s", $facebookId);
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

if ($email === '' && !$user) {
    $_SESSION["registration_error"] = "We couldn't retrieve your email from Facebook. Please make sure your Facebook account has an email address available or register with your email address first.";
    header("Location: " . $redirectPage);
    exit();
}

if ($user) {
    $userId = (int)($user['id'] ?? 0);
    if ($userId > 0 && trim((string)($user['facebook_id'] ?? '')) === '') {
        $linkStmt = $conn->prepare("UPDATE users SET facebook_id = ?, is_verified = 1 WHERE userID = ?");
        if ($linkStmt) {
            $linkStmt->bind_param("si", $facebookId, $userId);
            $linkStmt->execute();
            $linkStmt->close();
        }
        $user['facebook_id'] = $facebookId;
        $user['is_verified'] = 1;
    }
} else {
    $dummyPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $username = app_social_auth_generate_username($conn, 'facebook', $facebookId);

    $stmt = $conn->prepare("
        INSERT INTO users (full_name, email, facebook_id, username, password, is_verified, profile_complete, role)
        VALUES (?, ?, ?, ?, ?, 1, 0, 'user')
    ");

    if (!$stmt) {
        $_SESSION["registration_error"] = "We could not create your Facebook account. Please try again.";
        header("Location: " . $redirectPage);
        exit();
    }

    $stmt->bind_param("sssss", $fullName, $email, $facebookId, $username, $dummyPassword);
    $ok = $stmt->execute();
    $newUserId = (int)$stmt->insert_id;
    $stmt->close();

    if (!$ok || $newUserId <= 0) {
        $_SESSION["registration_error"] = "We could not create your Facebook account. Please try again.";
        header("Location: " . $redirectPage);
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
    $_SESSION["registration_error"] = "Facebook login could not be completed. Please try again.";
    header("Location: " . $redirectPage);
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
