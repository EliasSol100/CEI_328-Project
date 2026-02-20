<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once "database.php";

/*
 * Facebook OAuth config (localhost)
 * Make sure the redirect URI below is added in your Facebook app:
 * http://localhost/athina-eshop/facebook_callback.php
 */
$appId        = '924345056652857';
$appSecret    = '961389e18dd6d117327fb0ad668e8d0e';
$redirectUri  = 'http://localhost/athina-eshop/facebook_callback.php';

if (!isset($_GET['code'])) {
    // User cancelled or something went wrong at FB side
    $_SESSION["registration_error"] = "Facebook login was cancelled or failed. Please try again or use your email.";
    header("Location: registration.php");
    exit();
}

$code = $_GET['code'];

/* === 1. Exchange code for access token === */
$tokenUrl = 'https://graph.facebook.com/v18.0/oauth/access_token';

$tokenParams = [
    'client_id'     => $appId,
    'redirect_uri'  => $redirectUri,
    'client_secret' => $appSecret,
    'code'          => $code
];

$ch = curl_init($tokenUrl . '?' . http_build_query($tokenParams));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$tokenResponse = curl_exec($ch);
curl_close($ch);

$tokenData = json_decode($tokenResponse, true);

if (!isset($tokenData['access_token'])) {
    $_SESSION["registration_error"] = "Failed to get access token from Facebook.";
    header("Location: registration.php");
    exit();
}

$accessToken = $tokenData['access_token'];

/* === 2. Fetch user info === */
// We ask for id, name, email. If email is not available, we'll handle that.
$userInfoUrl = 'https://graph.facebook.com/me?fields=id,name,email';

$ch = curl_init($userInfoUrl . '&access_token=' . urlencode($accessToken));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$userInfoResponse = curl_exec($ch);
curl_close($ch);

$userInfo = json_decode($userInfoResponse, true);

$fullName = $userInfo['name']  ?? '';
$email    = $userInfo['email'] ?? '';

if (!$email) {
    // Without an email we can't link to your user table, so bail out gracefully.
    $_SESSION["registration_error"] = "We couldn't retrieve your email from Facebook. Please sign up with your email instead.";
    header("Location: registration.php");
    exit();
}

/* === 3. Check if user already exists === */
$stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    // Existing user
    $user = $result->fetch_assoc();
} else {
    // New Facebook user: create minimal record (verified but NOT profile_complete)
    $dummyPassword = password_hash(uniqid(), PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        INSERT INTO users (full_name, email, password, is_verified, profile_complete, role)
        VALUES (?, ?, ?, 1, 0, 'user')
    ");
    $stmt->bind_param("sss", $fullName, $email, $dummyPassword);
    $stmt->execute();

    $user = [
        'id'               => $stmt->insert_id,
        'full_name'        => $fullName,
        'email'            => $email,
        'country'          => null,
        'city'             => null,
        'address'          => null,
        'postcode'         => null,
        'dob'              => null,
        'phone'            => null,
        'role'             => 'user',
        'profile_complete' => 0,
    ];
}

/* === 4. Fetch previous last_login before updating === */
$prevLogin = null;
$getLogin  = $conn->prepare("SELECT last_login FROM users WHERE id = ?");
$getLogin->bind_param("i", $user['id']);
$getLogin->execute();
$loginResult = $getLogin->get_result();
if ($row = $loginResult->fetch_assoc()) {
    $prevLogin = $row['last_login'];
}

/* === 5. Update last_login to now === */
$updateLogin = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
$updateLogin->bind_param("i", $user['id']);
$updateLogin->execute();

/* === 6. Store in session === */
$profileComplete = !empty($user['profile_complete']);

$_SESSION['user'] = [
    'id'               => $user['id'],
    'full_name'        => $user['full_name'],
    'email'            => $user['email'],
    'role'             => $user['role'] ?? 'user',
    'profile_complete' => $profileComplete,
    'last_login'       => $prevLogin
];

/* === 7. Redirect like Google:
 * - If profile is complete, go to dashboard (index.php)
 * - If not complete (new user or missing data), go to complete_profile.php
 */
if ($profileComplete) {
    header("Location: index.php");
} else {
    header("Location: complete_profile.php");
}
exit();