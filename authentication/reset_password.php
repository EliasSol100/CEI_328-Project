<?php
session_start();
require_once __DIR__ . "/database.php";

$error        = "";
$success      = "";
$emailDisplay = ""; // for showing in UI
$token        = "";
$expiresAt    = null; // raw datetime from DB for countdown

// Helper: validate token and return row (email, expires_at)
function findResetRecord(mysqli $conn, string $token): ?array {
    if ($token === "") {
        return null;
    }
    $token_hash = hash('sha256', $token);

    $stmt = $conn->prepare("SELECT email, expires_at FROM password_resets WHERE token_hash = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("s", $token_hash);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    // Check expiry
    if (strtotime($row["expires_at"]) < time()) {
        return null;
    }

    return $row; // ['email' => ..., 'expires_at' => ...]
}

// 1) First load: coming from email link (?token=...)
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $token = $_GET["token"] ?? "";

    $record = findResetRecord($conn, $token);
    if (!$record) {
        $error = "This password reset link is invalid or has expired. Please request a new one.";
    } else {
        $emailDisplay = $record["email"];
        $expiresAt    = $record["expires_at"];
    }
}

// 2) Form submission: POST with token + new password
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token           = $_POST["token"] ?? "";
    $password        = $_POST["password"] ?? "";
    $repeat_password = $_POST["repeat_password"] ?? "";

    $record = findResetRecord($conn, $token);
    if (!$record) {
        $error = "This password reset link is invalid or has expired. Please request a new one.";
    } else {
        $emailDisplay = $record["email"];
        $expiresAt    = $record["expires_at"];

        // Validate password (server-side guard)
        if (empty($password) || empty($repeat_password)) {
            $error = "Please fill in both password fields.";
        } elseif ($password !== $repeat_password) {
            $error = "Passwords do not match.";
        } elseif (
            strlen($password) < 8 ||
            !preg_match('/[A-Z]/', $password) ||
            !preg_match('/[0-9]/', $password) ||
            !preg_match('/[\W_]/', $password)
        ) {
            $error = "Password must be at least 8 characters and include an uppercase letter, a number, and a symbol.";
        } else {
            // Update user's password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $email        = $record["email"];

            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
            if ($stmt) {
                $stmt->bind_param("ss", $passwordHash, $email);
                $stmt->execute();
                $stmt->close();

                // Delete reset record so link can't be reused
                $token_hash = hash('sha256', $token);
                $stmt = $conn->prepare("DELETE FROM password_resets WHERE token_hash = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $token_hash);
                    $stmt->execute();
                    $stmt->close();
                }

                // Log user in
                $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
                $stmt->bind_param("s", $email);
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
                        "profile_complete" => $userRow["profile_complete"] ?? 0,
                        "is_verified"      => $userRow["is_verified"] ?? 0,
                    ];
                }

                // Redirect to homepage
                header("Location: ../index.php");
                exit();
            } else {
                $error = "Something went wrong while updating your password. Please try again.";
            }
        }
    }
}

// 3) Compute remaining seconds for countdown (if we have a valid record)
$remainingSeconds = 0;
if (!empty($expiresAt)) {
    $expiresTs = strtotime($expiresAt);
    if ($expiresTs !== false) {
        $remainingSeconds = max(0, $expiresTs - time());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"> 
    <title>Reset Password - Athina E-Shop</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="../assets/styling/style.css">
    <link rel="stylesheet" href="../assets/styling/authentication.css">
    <!-- Font Awesome for check / x icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        .password-checklist {
            list-style: none;
            padding-left: 0;
            margin-top: 0.5rem;
        }
        .password-checklist li {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: #4b5563; /* neutral */
        }
        .password-checklist li i {
            font-size: 0.9rem;
        }
        .password-checklist li.valid {
            color: #16a34a; /* green-600 */
        }
        .password-checklist li.valid i {
            color: #16a34a;
        }
        .password-checklist li.invalid {
            color: #dc2626; /* red-600 */
        }
        .password-checklist li.invalid i {
            color: #dc2626;
        }
    </style>
</head>

<body class="registration_page">

    <div class="wizard-box">
        <div class="wizard-header text-center">
            <div class="wizard-logo">
                <img src="../assets/images/athina-eshop-logo.png" alt="Athina E-Shop Logo">
            </div>
            <h3 class="mt-2">Set a New Password</h3>
            <p class="wizard-subtitle mb-0">
                <?php if ($emailDisplay): ?>
                    Choose a strong password for <strong><?= htmlspecialchars($emailDisplay) ?></strong>.
                <?php else: ?>
                    Enter a new password for your account.
                <?php endif; ?>
            </p>
        </div>

        <div class="wizard-content">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (empty($error) || $token !== ""): ?>
                <?php if ($remainingSeconds > 0): ?>
                    <p id="reset-timer" class="mb-2 text-center text-muted-small">
                        Link expires in <span id="reset-countdown"></span>
                    </p>
                <?php endif; ?>

                <form action="reset_password.php" method="post">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <div class="form-group mb-3">
                        <label for="password">New Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Enter new password"
                            required
                        >
                    </div>

                    <div class="form-group mb-3">
                        <label for="repeat_password">Confirm New Password</label>
                        <input
                            type="password"
                            id="repeat_password"
                            name="repeat_password"
                            class="form-control"
                            placeholder="Repeat new password"
                            required
                        >
                    </div>

                    <ul class="password-checklist mt-2">
                        <li id="rule-length" class="invalid">
                            <i class="fa-solid fa-circle-xmark"></i>
                            <span>At least 8 characters</span>
                        </li>
                        <li id="rule-uppercase" class="invalid">
                            <i class="fa-solid fa-circle-xmark"></i>
                            <span>At least 1 uppercase letter</span>
                        </li>
                        <li id="rule-number" class="invalid">
                            <i class="fa-solid fa-circle-xmark"></i>
                            <span>At least 1 number</span>
                        </li>
                        <li id="rule-symbol" class="invalid">
                            <i class="fa-solid fa-circle-xmark"></i>
                            <span>At least 1 symbol</span>
                        </li>
                        <li id="rule-match" class="invalid">
                            <i class="fa-solid fa-circle-xmark"></i>
                            <span>Passwords match</span>
                        </li>
                    </ul>

                    <div class="wizard-actions mb-2">
                        <button type="submit" name="submit" class="btn btn-success w-100" id="save-btn">
                            Save New Password
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="form-footer">
                    <a href="forgot_password.php">Request a new reset link</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Password checklist
        (function () {
            const passwordInput = document.getElementById('password');
            const repeatInput   = document.getElementById('repeat_password');

            const rules = {
                length:   document.getElementById('rule-length'),
                upper:    document.getElementById('rule-uppercase'),
                number:   document.getElementById('rule-number'),
                symbol:   document.getElementById('rule-symbol'),
                match:    document.getElementById('rule-match'),
            };

            function setRuleState(li, isValid) {
                if (!li) return;
                li.classList.toggle('valid', isValid);
                li.classList.toggle('invalid', !isValid);

                const icon = li.querySelector('i');
                if (!icon) return;

                if (isValid) {
                    icon.classList.remove('fa-circle-xmark');
                    icon.classList.add('fa-circle-check');
                } else {
                    icon.classList.remove('fa-circle-check');
                    icon.classList.add('fa-circle-xmark');
                }
            }

            function evaluatePassword() {
                const pwd  = passwordInput ? (passwordInput.value || "") : "";
                const copy = repeatInput ? (repeatInput.value || "") : "";

                const hasLength = pwd.length >= 8;
                const hasUpper  = /[A-Z]/.test(pwd);
                const hasNumber = /[0-9]/.test(pwd);
                const hasSymbol = /[\W_]/.test(pwd);
                const matches   = pwd.length > 0 && pwd === copy;

                setRuleState(rules.length,  hasLength);
                setRuleState(rules.upper,   hasUpper);
                setRuleState(rules.number,  hasNumber);
                setRuleState(rules.symbol,  hasSymbol);
                setRuleState(rules.match,   matches);
            }

            if (passwordInput && repeatInput) {
                passwordInput.addEventListener('input', evaluatePassword);
                repeatInput.addEventListener('input', evaluatePassword);
            }

            // Run once on load (in case browser autofills)
            evaluatePassword();
        })();

        // Live countdown for reset link
        (function () {
            var remaining = <?php echo (int)$remainingSeconds; ?>;
            if (remaining <= 0) return;

            var countdownEl = document.getElementById('reset-countdown');
            var timerContainer = document.getElementById('reset-timer');
            var saveBtn = document.getElementById('save-btn');

            if (!countdownEl || !timerContainer) return;

            function formatTime(sec) {
                var m = Math.floor(sec / 60);
                var s = sec % 60;
                return m + ':' + (s < 10 ? '0' + s : s);
            }

            function setExpiredState() {
                timerContainer.textContent = 'This reset link has expired. Please request a new one.';
                if (saveBtn) {
                    saveBtn.disabled = true;
                    saveBtn.classList.add('disabled');
                }
            }

            function tick() {
                if (remaining <= 0) {
                    setExpiredState();
                    return;
                }
                countdownEl.textContent = formatTime(remaining);
                remaining--;
                setTimeout(tick, 1000);
            }

            countdownEl.textContent = formatTime(remaining);
            tick();
        })();
    </script>
</body>
</html>