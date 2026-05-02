<?php
session_start();
require_once "database.php";
require_once __DIR__ . "/../include/security.php";
require_once __DIR__ . "/../include/homepage_customization.php";
require_once __DIR__ . "/../include/auth_branding.php";
require_once __DIR__ . "/auth_mailer.php";

if (isset($_SESSION["user"])) {
    $userId        = $_SESSION["user"]["id"]    ?? null;
    $email         = $_SESSION["user"]["email"] ?? '';
    $isSocialLogin = true;
} elseif (isset($_SESSION["manual_email"])) {
    $userId        = null;
    $email         = $_SESSION["manual_email"];
    $isSocialLogin = false;
} else {

    header("Location: registration.php");
    exit();
}

$errors = [];

function parseProfileDobInput(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    foreach (['d/m/Y', 'Y-m-d'] as $format) {
        $date = DateTime::createFromFormat('!' . $format, $value);
        $lastErrors = DateTime::getLastErrors();
        $hasErrors = is_array($lastErrors) && (($lastErrors['warning_count'] ?? 0) > 0 || ($lastErrors['error_count'] ?? 0) > 0);

        if ($date instanceof DateTime && !$hasErrors && $date->format($format) === $value) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function formatProfileDobInputValue(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $parsed = parseProfileDobInput($value);
    if ($parsed === null) {
        return $value;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $parsed);
    return $date instanceof DateTime ? $date->format('Y-m-d') : $value;
}

function profilePasswordHasAsciiSymbol(string $password): bool
{
    return (bool)preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\\\|,.<>\/?`~]/', $password);
}

function profileCleanText(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function profileValidLetters(string $value): bool
{
    return $value !== '' && (bool)preg_match('/^[\p{L} ]+$/u', $value);
}

function profileValidAddress(string $value): bool
{
    return $value !== '' && (bool)preg_match('/^[\p{L}\p{N} ]+$/u', $value);
}

function profileValidPostcode(string $value): bool
{
    return $value !== '' && (bool)preg_match('/^\d{3,10}$/', $value);
}

function profileSyncDefaultAddress(mysqli $conn, int $userId, string $country, string $city, string $address, string $postcode): void
{
    if ($userId <= 0 || $country === '' || $city === '' || $address === '' || $postcode === '') {
        return;
    }

    $existingId = 0;
    $check = $conn->prepare("SELECT id FROM user_addresses WHERE user_id = ? AND is_default = 1 LIMIT 1");
    if ($check) {
        $check->bind_param('i', $userId);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        $existingId = (int)($row['id'] ?? 0);
        $check->close();
    }

    if ($existingId > 0) {
        $stmt = $conn->prepare("UPDATE user_addresses SET label = 'Home', country = ?, city = ?, address = ?, postcode = ?, is_default = 1 WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('ssssi', $country, $city, $address, $postcode, $existingId);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }

    $stmt = $conn->prepare("INSERT INTO user_addresses (user_id, label, country, city, address, postcode, is_default) VALUES (?, 'Home', ?, ?, ?, ?, 1)");
    if ($stmt) {
        $stmt->bind_param('issss', $userId, $country, $city, $address, $postcode);
        $stmt->execute();
        $stmt->close();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    app_require_csrf(false, "Invalid request token. Please refresh and try again.");

    $fullName        = profileCleanText((string)($_POST["fullname"] ?? ''));
    $nameParts       = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
    $username        = profileCleanText((string)($_POST["username"] ?? ''));
    $password        = $_POST["password"]              ?? '';
    $repeat_password = $_POST["repeat_password"]       ?? '';
    $country         = profileCleanText((string)($_POST["country"] ?? ''));
    $city            = profileCleanText((string)($_POST["city"] ?? ''));
    $address         = profileCleanText((string)($_POST["address"] ?? ''));
    $postcode        = profileCleanText((string)($_POST["postcode"] ?? ''));
    $dobInput        = trim($_POST["dob"]              ?? '');
    $dob             = parseProfileDobInput($dobInput);
    $phone           = profileCleanText((string)($_POST["phone"] ?? ''));

    if (count($nameParts) < 2 || count($nameParts) > 3) {
        $errors[] = "Full name must be 2 or 3 words (e.g., First Last or First Middle Last).";
    } elseif (!profileValidLetters($fullName)) {
        $errors[] = "Full name must contain letters only.";
    }

    $firstName  = $nameParts[0] ?? '';
    $lastName   = $nameParts[count($nameParts) - 1] ?? '';
    $middleName = (count($nameParts) > 2)
        ? implode(" ", array_slice($nameParts, 1, -1))
        : null;

    if (
        empty($fullName) || empty($username) || empty($password) || empty($repeat_password) ||
        empty($country)  || empty($city)     || empty($address)  || empty($postcode) ||
        empty($dobInput) || empty($phone)
    ) {
        $errors[] = "All fields are required!";
    }

    if ($dobInput !== '' && $dob === null) {
        $errors[] = "Date of birth is not valid.";
    }
    if ($dob !== null) {
        $dobDate = DateTime::createFromFormat('!Y-m-d', $dob);
        $today = new DateTime('today');
        if ($dobDate instanceof DateTime && $dobDate > $today) {
            $errors[] = "Date of birth cannot be in the future.";
        }
        if ($dobDate instanceof DateTime && (int)$dobDate->format('Y') < 1900) {
            $errors[] = "Date of birth year cannot be before 1900.";
        }
    }

    if (!app_is_valid_username($username)) {
        $errors[] = "Username must be 3-32 characters and use only letters, numbers, dots, underscores, or hyphens.";
    }

    if ($city !== '' && !profileValidLetters($city)) {
        $errors[] = "City must contain letters only.";
    }

    if ($address !== '' && !profileValidAddress($address)) {
        $errors[] = "Address must contain letters, numbers and spaces only.";
    }

    if ($postcode !== '' && !profileValidPostcode($postcode)) {
        $errors[] = "Postal code must contain numbers only.";
    }

    if (!preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
        $errors[] = "Phone number is not valid!";
    } elseif (preg_match('/^\+357/', $phone)) {
        $nationalNumber = preg_replace('/^\+357/', '', $phone);
        if (!preg_match('/^[0-9]{8}$/', $nationalNumber)) {
            $errors[] = "Cyprus phone numbers must have exactly 8 digits.";
        }
    }

    $allowedCountries = ['Greece', 'Cyprus', 'Ελλάδα', 'Κύπρος'];
    if (!empty($country) && !in_array($country, $allowedCountries, true)) {
        $errors[] = "We currently ship only to Greece and Cyprus.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Email is not valid!";
    }

    if (
        strlen($password) < 8 ||
        !preg_match('/^[\x20-\x7E]+$/', $password) ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[0-9]/', $password) ||
        !profilePasswordHasAsciiSymbol($password)
    ) {
        $errors[] = "Password must be at least 8 characters and include an uppercase letter, a number, and an English keyboard symbol.";
    }

    if ($password !== $repeat_password) {
        $errors[] = "Passwords do not match!";
    }

    if ($isSocialLogin && $userId) {
        $stmt = $conn->prepare("SELECT userID FROM users WHERE username = ? AND userID != ?");
    } else {
        $stmt = $conn->prepare("SELECT userID FROM users WHERE username = ?");
    }
    if ($stmt) {
        if ($isSocialLogin && $userId) {
            $stmt->bind_param("si", $username, $userId);
        } else {
            $stmt->bind_param("s", $username);
        }
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $errors[] = "This username already exists, please choose a different one.";
        }
        $stmt->close();
    } else {
        $errors[] = "We couldn't validate your username. Please try again.";
    }

    if (!$isSocialLogin) {
        $emailCheck = $conn->prepare("SELECT userID FROM users WHERE email = ? LIMIT 1");
        if ($emailCheck) {
            $emailCheck->bind_param("s", $email);
            $emailCheck->execute();
            $emailCheck->store_result();
            if ($emailCheck->num_rows > 0) {
                $errors[] = "An account with this email already exists!";
            }
            $emailCheck->close();
        }
    }

    if (empty($errors)) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $newUserId = null;

        if ($isSocialLogin && $userId) {

            $stmt = $conn->prepare("
                UPDATE users
                SET full_name        = ?,
                    first_name       = ?,
                    middle_name      = ?,
                    last_name        = ?,
                    username         = ?,
                    password         = ?,
                    country          = ?,
                    city             = ?,
                    address          = ?,
                    postcode         = ?,
                    dob              = ?,
                    phone            = ?,
                    profile_complete = 1
                WHERE userID = ?
            ");
            if (!$stmt) {
                $errors[] = "We couldn't update your profile. Please try again.";
            } else {
                $stmt->bind_param(
                    "ssssssssssssi",
                    $fullName,
                    $firstName,
                    $middleName,
                    $lastName,
                    $username,
                    $passwordHash,
                    $country,
                    $city,
                    $address,
                    $postcode,
                    $dob,
                    $phone,
                    $userId
                );
                if (!$stmt->execute()) {
                    $errors[] = "We couldn't update your profile. Please try again.";
                } else {
                    $newUserId = $userId;
                }
                $stmt->close();
            }

        } else {

            $stmt = $conn->prepare("
                INSERT INTO users (
                    full_name,
                    first_name,
                    middle_name,
                    last_name,
                    username,
                    email,
                    password,
                    country,
                    city,
                    address,
                    postcode,
                    dob,
                    phone,
                    is_verified,
                    profile_complete
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1
                )
            ");

            if (!$stmt) {
                $errors[] = "We couldn't create your account. Please try again.";
            } else {
                $stmt->bind_param(
                    "sssssssssssss",
                    $fullName,
                    $firstName,
                    $middleName,
                    $lastName,
                    $username,
                    $email,
                    $passwordHash,
                    $country,
                    $city,
                    $address,
                    $postcode,
                    $dob,
                    $phone
                );
                if (!$stmt->execute()) {
                    if ((int)$stmt->errno === 1062) {
                        $errors[] = "An account with this email or username already exists.";
                    } else {
                        $errors[] = "We couldn't create your account. Please try again.";
                    }
                } else {
                    $newUserId = (int)$stmt->insert_id;

                    unset($_SESSION["manual_email"]);
                }
                $stmt->close();
            }
        }

        if (empty($errors) && !empty($newUserId)) {
            profileSyncDefaultAddress($conn, (int)$newUserId, $country, $city, $address, $postcode);

            $stmt = $conn->prepare("SELECT *, userID AS id FROM users WHERE userID = ?");
            $stmt->bind_param("i", $newUserId);
            $stmt->execute();
            $result  = $stmt->get_result();
            $userRow = $result->fetch_assoc();
            $stmt->close();

            if ($userRow) {

                $_SESSION["user_id"]   = $userRow["id"];
                $_SESSION["email"]     = $userRow["email"];
                $_SESSION["full_name"] = $userRow["full_name"];
                $_SESSION["role"]      = $userRow["role"] ?? 'user';

                $_SESSION["user"] = [
                    "id"               => $userRow["id"],
                    "email"            => $userRow["email"],
                    "full_name"        => $userRow["full_name"],
                    "role"             => $userRow["role"] ?? 'user',
                    "profile_complete" => $userRow["profile_complete"],
                    "is_verified"      => $userRow["is_verified"],
                ];

                if ((int)($userRow["is_verified"] ?? 0) !== 1) {
                    app_auth_send_email_verification_code($conn, (int)$userRow["id"], (string)$userRow["email"], (string)$userRow["full_name"]);
                }

                $nextStep = ((int)($userRow["is_verified"] ?? 0) === 1)
                    ? consumeAuthRedirectTarget("../index.php")
                    : "verify.php";
                header("Location: " . $nextStep);
                echo '<script>window.location.href=' . json_encode($nextStep) . ';</script>';
                exit();
            }

            $errors[] = "We couldn't start your verification session. Please log in and try again.";
        }
    }
}

$initialStep = 1;
if (!empty($errors)) {
    foreach ($errors as $errorText) {
        if (
            str_contains($errorText, 'Password') ||
            str_contains($errorText, 'Passwords do not match')
        ) {
            $initialStep = 2;
            break;
        }
        if (
            str_contains($errorText, 'Country') ||
            str_contains($errorText, 'City') ||
            str_contains($errorText, 'address') ||
            str_contains($errorText, 'Postal')
        ) {
            $initialStep = 3;
        }
    }
}

$profileLogoUrl = app_auth_logo_url($conn, '../');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Complete Your Profile</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styling/style.css">
    <link rel="stylesheet" href="../assets/styling/authentication.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/css/intlTelInput.min.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/country-select-js/2.1.0/css/countrySelect.min.css" />
</head>
<body class="registration_page">

    <div class="wizard-box">
        <div class="wizard-header">

            <div class="wizard-logo">
                <img src="<?= htmlspecialchars($profileLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Creations by Athina logo">
            </div>

            <h3 class="mt-2">Complete Your Profile</h3>
            <div class="step-indicator position-relative">
                <div class="step-indicator-fill" id="step-progress" style="width: 33%;"></div>
                <div id="progress-percentage" class="progress-text">33%</div>
            </div>
        </div>

        <form method="post" id="complete-profile-form">
            <?= app_csrf_input() ?>
            <div class="wizard-content">

                <div class="form-step active" id="step1">
                    <?php if (!empty($errors) && isset($_POST["fullname"])): ?>
                        <?php foreach ($errors as $error): ?>
                            <?php if (in_array($error, ["All fields are required!", "Phone number is not valid!", "Email is not valid!"])): ?>
                                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="fullname" class="form-control"
                               placeholder="e.g. John Doe"
                               title="Use letters and spaces only"
                               value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>" required>
                        <?php
                        if (!empty($errors)) {
                            foreach ($errors as $error) {
                                if (str_contains($error, "Full name must be")) {
                                    echo "<div class='text-danger mt-1'>" . htmlspecialchars($error) . "</div>";
                                }
                            }
                        }
                        ?>
                    </div>

                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" id="username" class="form-control"
                               placeholder="Choose a username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                        <small id="username-status" class="text-danger"></small>
                        <?php
                        if (!empty($errors)) {
                            foreach ($errors as $error) {
                                if (str_contains($error, "username")) {
                                    echo "<div class='text-danger mt-1'>" . htmlspecialchars($error) . "</div>";
                                }
                            }
                        }
                        ?>
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" class="form-control"
                               value="<?= htmlspecialchars($email) ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" class="form-control" id="phone" name="phone"
                               inputmode="tel"
                               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
                        <div id="phone-error" class="text-danger mt-1" style="display:none;">
                            Please enter a phone number.
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date"
                               name="dob"
                               class="form-control"
                               id="dob"
                               min="1900-01-01"
                               max="<?= date('Y-m-d') ?>"
                               value="<?= htmlspecialchars(formatProfileDobInputValue($_POST['dob'] ?? '')) ?>"
                               required>
                        <div id="dob-error" class="text-danger mt-1" style="display:none;">
                            Please choose a valid date of birth.
                        </div>
                        <?php
                        if (!empty($errors)) {
                            foreach ($errors as $error) {
                                if (str_contains($error, "Date of birth")) {
                                    echo "<div class='text-danger mt-1'>" . htmlspecialchars($error) . "</div>";
                                }
                            }
                        }
                        ?>
                    </div>
                </div>

                <div class="form-step" id="step2">
                    <?php if (!empty($errors) && isset($_POST["password"])): ?>
                        <?php foreach ($errors as $error): ?>
                            <?php if (in_array($error, [
                                "All fields are required!",
                                "Password must be at least 8 characters and include an uppercase letter, a number, and an English keyboard symbol.",
                                "Passwords do not match!"
                            ])): ?>
                                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Password</label>
                        <div class="input-group">
                            <input type="password" name="password" class="form-control" id="password" required>
                            <span class="input-group-text toggle-password" data-target="#password">
                                <i class="bi bi-eye"></i>
                            </span>
                        </div>
                        <ul class="password-checklist mt-2" style="list-style: none; padding-left: 0; font-size: 14px;">
                            <li id="check-length"><span class="text-danger">✖</span> At least 8 characters</li>
                            <li id="check-uppercase"><span class="text-danger">✖</span> At least 1 uppercase letter</li>
                            <li id="check-number"><span class="text-danger">✖</span> At least 1 number</li>
                            <li id="check-symbol"><span class="text-danger">✖</span> At least 1 symbol</li>
                        </ul>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password</label>
                        <div class="input-group">
                            <input type="password" name="repeat_password" class="form-control" id="confirm_password" required>
                            <span class="input-group-text toggle-password" data-target="#confirm_password">
                                <i class="bi bi-eye"></i>
                            </span>
                        </div>
                        <div id="confirm-password-error" class="text-danger mt-1" style="display:none;">
                            Passwords do not match.
                        </div>
                    </div>
                </div>

                <div class="form-step" id="step3">
                    <?php if (!empty($errors) && isset($_POST["country"])): ?>
                        <?php foreach ($errors as $error): ?>
                            <?php if ($error === "All fields are required!" || str_contains($error, "City") || str_contains($error, "Address") || str_contains($error, "Postal")): ?>
                                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Country</label>
                        <input type="text" name="country" class="form-control country_input" id="country"
                               value="<?= htmlspecialchars($_POST['country'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>City</label>
                        <input type="text" name="city" class="form-control"
                               pattern="[A-Za-zÀ-ÖØ-öø-ÿΑ-Ωα-ωΆ-ώ ]+"
                               title="City must contain letters only"
                               value="<?= htmlspecialchars($_POST['city'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" name="address" class="form-control"
                               pattern="[A-Za-zÀ-ÖØ-öø-ÿΑ-Ωα-ωΆ-ώ0-9 ]+"
                               title="Address must contain letters, numbers and spaces only"
                               value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Postal Code</label>
                        <input type="text" name="postcode" class="form-control"
                               inputmode="numeric"
                               pattern="[0-9]{3,10}"
                               title="Postal code must contain numbers only"
                               value="<?= htmlspecialchars($_POST['postcode'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="wizard-actions">
                    <button type="button" class="btn btn-secondary" id="prevBtn" style="display: none;">Back</button>
                    <button type="button" class="btn btn-primary" id="nextBtn">Next</button>
                    <button type="submit" class="btn btn-success" id="submitBtn" style="display: none;">Finish</button>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/country-select-js/2.1.0/js/countrySelect.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/zxcvbn/4.4.2/zxcvbn.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/intlTelInput.min.js"></script>
    <script src="../assets/js/date-input-format.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/date-input-format.js') ?>"></script>
    <script>
    let usernameValid = false;
    let usernameChecked = false;

    $(document).ready(function () {
        function isValidDobInput(value) {
            const rawValue = String(value || "").trim();
            let match = rawValue.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            let year;
            let month;
            let day;

            if (match) {
                year = Number(match[1]);
                month = Number(match[2]) - 1;
                day = Number(match[3]);
            } else {
                match = rawValue.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
                if (!match) {
                    return false;
                }
                day = Number(match[1]);
                month = Number(match[2]) - 1;
                year = Number(match[3]);
            }

            const date = new Date(year, month, day);
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            return date.getFullYear() === year && date.getMonth() === month && date.getDate() === day && date <= today;
        }

        function getFieldName($field) {
            return $field.attr("name") || $field.attr("data-date-name") || "";
        }

        function getFieldValue($field) {
            return String($field.val() || "").trim();
        }

        function showDobError(message) {
            $("#dob").addClass("is-invalid");
            $("#dob-error").text(message).show();
        }

        function clearDobError() {
            $("#dob").removeClass("is-invalid");
            $("#dob-error").hide();
        }

        function validateDobField() {
            const dobVal = getFieldValue($("#dob"));
            if (dobVal === "") {
                return false;
            }
            if (!isValidDobInput(dobVal)) {
                showDobError("Please choose a valid date of birth.");
                return false;
            }
            clearDobError();
            return true;
        }

        function validateProfileTextField(selector, validator, message) {
            const $field = $(selector);
            if (!$field.length) {
                return true;
            }
            const value = getFieldValue($field);
            const errorId = selector.replace(/[^a-z0-9]/gi, "") + "-error";
            $("#" + errorId).remove();

            if (!validator(value)) {
                $field.addClass("is-invalid");
                $("<div id='" + errorId + "' class='text-danger mt-1'></div>").text(message).insertAfter($field);
                return false;
            }

            $field.removeClass("is-invalid");
            return true;
        }

        function isLettersOnly(value) {
            return /^[\p{L} ]+$/u.test(value);
        }

        function isAddressOnly(value) {
            return /^[\p{L}\p{N} ]+$/u.test(value);
        }

        function isPostcodeOnly(value) {
            return /^[0-9]{3,10}$/.test(value);
        }

        function validateAddressStep() {
            let valid = true;
            valid = validateProfileTextField("input[name='city']", isLettersOnly, "City must contain letters only.") && valid;
            valid = validateProfileTextField("input[name='address']", isAddressOnly, "Address must contain letters, numbers and spaces only.") && valid;
            valid = validateProfileTextField("input[name='postcode']", isPostcodeOnly, "Postal code must contain numbers only.") && valid;
            return valid;
        }

        $("input[name='fullname']").on("input", function () {
            const nameParts = $(this).val().trim().split(/\s+/);
            if (nameParts.length >= 2 && nameParts.length <= 3) {
                $(this).removeClass("is-invalid");
                $("#fullname-error").remove();
            }
        });

        $("#country").countrySelect({ defaultCountry: "cy", onlyCountries: ["cy", "gr"] });

        const iti = window.intlTelInput(document.querySelector("#phone"), {
            separateDialCode: true,
            initialCountry: "cy",
            preferredCountries: ['cy', 'gr', 'us'],
            utilsScript: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/utils.js"
        });

        let currentStep = <?= (int)$initialStep ?>;
        const totalSteps = 3;

        function showStep(step) {
            $(".form-step").removeClass("active");
            $("#step" + step).addClass("active");

            $("#prevBtn").toggle(step > 1);
            $("#nextBtn").toggle(step < totalSteps);
            $("#submitBtn").toggle(step === totalSteps);

            $("#step-progress").css("width", (step / totalSteps) * 100 + "%");
            $("#progress-percentage").text(Math.round((step / totalSteps) * 100) + "%");
            $(`#step${step} input:not([type=hidden]):first`).focus();
        }

        $("#nextBtn").click(function () {
            const currentFields = $(`#step${currentStep} input:required`);
            let valid = true;

            currentFields.each(function () {
                const $field = $(this);
                const value = getFieldValue($field);
                const name = getFieldName($field);

                if (!value) {
                    $field.addClass("is-invalid");
                    if (currentStep === 1) {
                        if (name === "phone") {
                            $("#phone-error").show();
                        } else if (name === "dob") {
                            showDobError("Please choose a valid date of birth.");
                        }
                    }
                    valid = false;
                } else {
                    $field.removeClass("is-invalid");
                    if (name === "phone") {
                        $("#phone-error").hide();
                    } else if (name === "dob") {
                        $("#dob-error").hide();
                    }
                }
            });

            if (currentStep === 1) {
                const usernameVal = $("#username").val().trim();

                if (usernameVal === "") {
                    $("#username").addClass("is-invalid");
                    $("#username-status").text("Please enter a username.");
                    valid = false;
                } else if (usernameChecked && !usernameValid) {
                    $("#username").addClass("is-invalid");
                    $("#username-status").text("This username already exists, please choose a different one.");
                    valid = false;
                }

                const fullName = $("input[name='fullname']").val().trim();
                const nameParts = fullName.split(/\s+/);
                if (nameParts.length < 2 || nameParts.length > 3) {
                    $("input[name='fullname']").addClass("is-invalid");
                    if ($("#fullname-error").length === 0) {
                        $("<div id='fullname-error' class='text-danger mt-1'>Full name must be 2 or 3 words (e.g., First Last or First Middle Last).</div>").insertAfter("input[name='fullname']");
                    }
                    valid = false;
                } else if (!isLettersOnly(fullName)) {
                    $("input[name='fullname']").addClass("is-invalid");
                    if ($("#fullname-error").length === 0) {
                        $("<div id='fullname-error' class='text-danger mt-1'>Full name must contain letters only.</div>").insertAfter("input[name='fullname']");
                    }
                    valid = false;
                } else {
                    $("input[name='fullname']").removeClass("is-invalid");
                    $("#fullname-error").remove();
                }

                if (!validateDobField()) {
                    valid = false;
                }

                if (typeof iti !== 'undefined') {
                    if (!iti.isValidNumber()) {
                        $("#phone").addClass("is-invalid");
                        $("#phone-error").text("Please enter a valid phone number.").show();
                        valid = false;
                    } else {
                        const fullPhone = iti.getNumber();
                        if (fullPhone.startsWith('+357')) {
                            const national = fullPhone.replace('+357', '');
                            if (!/^[0-9]{8}$/.test(national)) {
                                $("#phone").addClass("is-invalid");
                                $("#phone-error").text("Cyprus phone numbers must have exactly 8 digits.").show();
                                valid = false;
                            }
                        }
                    }
                }
            }

            if (currentStep === 2) {
                const passwordVal = $("#password").val();
                const confirmVal = $("#confirm_password").val();
                const strongPassword =
                    passwordVal.length >= 8 &&
                    /^[\x20-\x7E]+$/.test(passwordVal) &&
                    /[A-Z]/.test(passwordVal) &&
                    /[0-9]/.test(passwordVal) &&
                    /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?`~]/.test(passwordVal);

                if (!strongPassword) {
                    $("#password").addClass("is-invalid");
                    valid = false;
                } else {
                    $("#password").removeClass("is-invalid");
                }

                if (passwordVal !== confirmVal) {
                    $("#confirm_password").addClass("is-invalid");
                    $("#confirm-password-error").show();
                    valid = false;
                } else {
                    $("#confirm_password").removeClass("is-invalid");
                    $("#confirm-password-error").hide();
                }
            }

            if (currentStep === 3 && !validateAddressStep()) {
                valid = false;
            }

            if (valid && currentStep < totalSteps) {
                currentStep++;
                showStep(currentStep);
            }
        });

        $("#prevBtn").click(function () {
            if (currentStep > 1) {
                currentStep--;
                showStep(currentStep);
            }
        });

        $(".toggle-password").click(function () {
            const input = $($(this).data("target"));
            const type = input.attr("type") === "password" ? "text" : "password";
            input.attr("type", type);
            $(this).find("i").toggleClass("bi-eye bi-eye-slash");
        });

        $("#password").on("input", function () {
            const val = $(this).val();

            const checks = {
                length: val.length >= 8,
                uppercase: /[A-Z]/.test(val),
                number: /[0-9]/.test(val),
                symbol: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?`~]/.test(val) && /^[\x20-\x7E]*$/.test(val)
            };

            $("#check-length").html((checks.length ? '✅' : '<span class="text-danger">✖</span>') + ' At least 8 characters');
            $("#check-uppercase").html((checks.uppercase ? '✅' : '<span class="text-danger">✖</span>') + ' At least 1 uppercase letter');
            $("#check-number").html((checks.number ? '✅' : '<span class="text-danger">✖</span>') + ' At least 1 number');
            $("#check-symbol").html((checks.symbol ? '✅' : '<span class="text-danger">✖</span>') + ' At least 1 symbol');
            $("#confirm_password").trigger("input");
        });

        $("#confirm_password").on("input", function () {
            const hasValue = $(this).val().length > 0;
            const mismatch = $("#password").val() !== $(this).val();
            $(this).toggleClass("is-invalid", hasValue && mismatch);
            $("#confirm-password-error").toggle(hasValue && mismatch);
        });

        $("#phone").on("input", function () {
            if ($(this).val().trim() !== "") {
                $(this).removeClass("is-invalid");
                $("#phone-error").hide();
            }
        });

        $("#dob").on("input", function () {
            const value = $(this).val();

            if (value === "" || isValidDobInput(value)) {
                $(this).removeClass("is-invalid");
                $("#dob-error").hide();
            } else {
                $(this).addClass("is-invalid");
                $("#dob-error").text("Please choose a valid date of birth.").show();
            }
        });

        $("#complete-profile-form").on("submit", function (e) {
            const fullPhone = iti.getNumber();

            if (!validateAddressStep()) {
                e.preventDefault();
                return false;
            }

            if (!iti.isValidNumber()) {
                e.preventDefault();
                $("#phone").addClass("is-invalid");
                $("#phone-error").text("Please enter a valid phone number.").show();
                return false;
            }

            if (fullPhone.startsWith('+357')) {
                const national = fullPhone.replace('+357', '');
                if (!/^[0-9]{8}$/.test(national)) {
                    e.preventDefault();
                    $("#phone").addClass("is-invalid");
                    $("#phone-error").text("Cyprus phone numbers must have exactly 8 digits.").show();
                    return false;
                }
            }

            $('#phone').val(fullPhone);
        });

        $("#username").on("blur", function () {
            const username = $(this).val().trim();
            const csrfToken = $("input[name='csrf_token']").first().val() || "";
            const usernamePattern = /^[A-Za-z0-9._-]{3,32}$/;

            if (username.length === 0) {
                $("#username-status").text("Please enter a username.");
                $("#username").addClass("is-invalid");
                usernameValid   = false;
                usernameChecked = false;
                return;
            }

            if (!usernamePattern.test(username)) {
                $("#username-status").text("Use 3-32 letters, numbers, dots, underscores, or hyphens.");
                $("#username").addClass("is-invalid");
                usernameValid = false;
                usernameChecked = true;
                return;
            }

            $.post("check_username.php", { username, csrf_token: csrfToken }, function (response) {
                usernameChecked = true;
                if (response === "taken") {
                    $("#username-status").text("This username already exists, please choose a different one.");
                    $("#username").addClass("is-invalid");
                    usernameValid = false;
                } else if (response === "invalid") {
                    $("#username-status").text("Use 3-32 letters, numbers, dots, underscores, or hyphens.");
                    $("#username").addClass("is-invalid");
                    usernameValid = false;
                } else {
                    $("#username-status").text("");
                    $("#username").removeClass("is-invalid");
                    usernameValid = true;
                }
            }).fail(function () {
                usernameChecked = false;
                usernameValid = false;
                $("#username-status").text("We couldn't verify this username right now. Please try again.");
                $("#username").addClass("is-invalid");
            });
        });

        showStep(currentStep);
    });
    </script>
</body>
</html>
