<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once "database.php";

/*
 * IMPORTANT (localhost):
 * Make sure this redirect URI is also configured in your Google Cloud console
 * and matches the one you use in registration.php.
 */
$clientID     = '901502356414-324b839ks2vas27hoq8hq0448qa6a0oj.apps.googleusercontent.com';
$clientSecret = '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';
$redirectUri  = 'http://localhost/ATHINA-ESHOP/authentication/google_callback.php';

if (!isset($_GET['code'])) {
    header('Location: registration.php');
    exit();
}

$code = $_GET['code'];

/* === 1. Exchange code for access token === */
$tokenRequest = curl_init('https://oauth2.googleapis.com/token');
curl_setopt($tokenRequest, CURLOPT_POST, true);
curl_setopt($tokenRequest, CURLOPT_RETURNTRANSFER, true);
curl_setopt($tokenRequest, CURLOPT_POSTFIELDS, http_build_query([
    'code'          => $code,
    'client_id'     => $clientID,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code'
]));
$response  = curl_exec($tokenRequest);
curl_close($tokenRequest);
$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    echo "Failed to get access token.";
    exit();
}

/* === 2. Fetch user info === */
$userRequest = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
curl_setopt($userRequest, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $tokenData['access_token']
]);
curl_setopt($userRequest, CURLOPT_RETURNTRANSFER, true);
$userInfo = json_decode(curl_exec($userRequest), true);
curl_close($userRequest);

$fullName = $userInfo['name']  ?? '';
$email    = $userInfo['email'] ?? '';

if (!$email) {
    echo "Google account did not return an email.";
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
    // New Google user: create minimal record (verified but NOT profile_complete)
    $dummyPassword = password_hash(uniqid(), PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        INSERT INTO users (full_name, email, password, is_verified, profile_complete, role)
        VALUES (?, ?, ?, 1, 0, 'user')
    ");
    $stmt->bind_param("sss", $fullName, $email, $dummyPassword);
    $stmt->execute();

    $user = [
        'id'              => $stmt->insert_id,
        'full_name'       => $fullName,
        'email'           => $email,
        'country'         => null,
        'city'            => null,
        'address'         => null,
        'postcode'        => null,
        'dob'             => null,
        'phone'           => null,
        'role'            => 'user',
        'profile_complete'=> 0,
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

/* === 7. Redirect:
 * - If profile is complete, go to dashboard
 * - If not complete (new user or missing data), go to complete_profile.php
 *   where the Email Address field will be prefilled & read-only.
 */
if ($profileComplete) {
    header("Location: ../index.php");
} else {
    header("Location: complete_profile.php");
}
exit();
