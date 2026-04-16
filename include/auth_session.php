<?php

if (!function_exists('app_auth_ensure_schema')) {
    function app_auth_ensure_schema(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $conn->query("
            CREATE TABLE IF NOT EXISTS auth_remember_tokens (
                tokenID INT AUTO_INCREMENT PRIMARY KEY,
                userID INT NOT NULL,
                selector VARCHAR(32) NOT NULL,
                tokenHash CHAR(64) NOT NULL,
                sessionMode VARCHAR(20) NOT NULL DEFAULT 'day',
                expiresAt DATETIME DEFAULT NULL,
                createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                lastUsedAt DATETIME DEFAULT NULL,
                UNIQUE KEY uniq_auth_remember_selector (selector),
                KEY idx_auth_remember_user (userID),
                KEY idx_auth_remember_expires (expiresAt)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS auth_trusted_devices (
                trustID INT AUTO_INCREMENT PRIMARY KEY,
                userID INT NOT NULL,
                deviceHash CHAR(64) NOT NULL,
                createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                lastUsedAt DATETIME DEFAULT NULL,
                UNIQUE KEY uniq_auth_trusted_device (userID, deviceHash),
                KEY idx_auth_trusted_user (userID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $ensured = true;
    }
}

if (!function_exists('app_auth_cookie_path')) {
    function app_auth_cookie_path(): string
    {
        return '/';
    }
}

if (!function_exists('app_auth_cookie_names')) {
    function app_auth_cookie_names(): array
    {
        return [
            'remember' => 'athina_auth_token',
            'device' => 'athina_browser_device',
            'login_hint' => 'athina_login_hint',
        ];
    }
}

if (!function_exists('app_auth_cookie_set')) {
    function app_auth_cookie_set(string $name, string $value, int $expiresAt): void
    {
        $options = [
            'expires' => $expiresAt,
            'path' => app_auth_cookie_path(),
            'secure' => app_is_https_request(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, $value, $options);
        } else {
            $cookiePath = $options['path'] . '; samesite=' . $options['samesite'];
            setcookie($name, $value, $options['expires'], $cookiePath, '', $options['secure'], $options['httponly']);
        }

        $_COOKIE[$name] = $value;
    }
}

if (!function_exists('app_auth_cookie_clear')) {
    function app_auth_cookie_clear(string $name): void
    {
        $options = [
            'expires' => time() - 3600,
            'path' => app_auth_cookie_path(),
            'secure' => app_is_https_request(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, '', $options);
        } else {
            $cookiePath = $options['path'] . '; samesite=' . $options['samesite'];
            setcookie($name, '', $options['expires'], $cookiePath, '', $options['secure'], $options['httponly']);
        }

        unset($_COOKIE[$name]);
    }
}

if (!function_exists('app_auth_device_cookie_value')) {
    function app_auth_device_cookie_value(bool $createIfMissing = true): string
    {
        $names = app_auth_cookie_names();
        $deviceCookie = trim((string)($_COOKIE[$names['device']] ?? ''));
        if ($deviceCookie !== '') {
            return $deviceCookie;
        }

        if (!$createIfMissing) {
            return '';
        }

        $deviceCookie = bin2hex(random_bytes(32));
        app_auth_cookie_set($names['device'], $deviceCookie, time() + (86400 * 365 * 10));
        return $deviceCookie;
    }
}

if (!function_exists('app_auth_hash_value')) {
    function app_auth_hash_value(string $value): string
    {
        return hash('sha256', $value);
    }
}

if (!function_exists('app_auth_mask_email')) {
    function app_auth_mask_email(string $email): string
    {
        $email = trim($email);
        if ($email === '' || strpos($email, '@') === false) {
            return $email;
        }

        [$localPart, $domain] = explode('@', $email, 2);
        $localPart = trim($localPart);
        if ($localPart === '') {
            return '***@' . $domain;
        }

        if (strlen($localPart) <= 2) {
            $maskedLocal = substr($localPart, 0, 1) . str_repeat('*', max(1, strlen($localPart) - 1));
        } else {
            $maskedLocal = substr($localPart, 0, 1) . str_repeat('*', strlen($localPart) - 2) . substr($localPart, -1);
        }

        return $maskedLocal . '@' . $domain;
    }
}

if (!function_exists('app_auth_session_mode_ttl')) {
    function app_auth_session_mode_ttl(string $mode): ?int
    {
        $mode = strtolower(trim($mode));
        if ($mode === 'week') {
            return 86400 * 7;
        }
        if ($mode === 'forever') {
            return null;
        }

        return 86400;
    }
}

if (!function_exists('app_auth_mode_cookie_expiry')) {
    function app_auth_mode_cookie_expiry(string $mode): int
    {
        $ttl = app_auth_session_mode_ttl($mode);
        if ($ttl === null) {
            return time() + (86400 * 365 * 10);
        }

        return time() + $ttl;
    }
}

if (!function_exists('app_auth_fetch_user_by_id')) {
    function app_auth_fetch_user_by_id(mysqli $conn, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $conn->prepare("SELECT *, userID AS id FROM users WHERE userID = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return is_array($user) ? $user : null;
    }
}

if (!function_exists('app_auth_profile_complete')) {
    function app_auth_profile_complete(array $userRow): bool
    {
        if (array_key_exists('profile_complete', $userRow)) {
            return !empty($userRow['profile_complete']);
        }

        return !empty($userRow['country']) &&
            !empty($userRow['city']) &&
            !empty($userRow['address']) &&
            !empty($userRow['postcode']) &&
            !empty($userRow['dob']) &&
            !empty($userRow['phone']);
    }
}

if (!function_exists('app_auth_update_last_login')) {
    function app_auth_update_last_login(mysqli $conn, int $userId): ?string
    {
        $previous = null;

        $read = $conn->prepare("SELECT last_login FROM users WHERE userID = ? LIMIT 1");
        if ($read) {
            $read->bind_param('i', $userId);
            $read->execute();
            $result = $read->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if ($row) {
                $previous = $row['last_login'] ?? null;
            }
            $read->close();
        }

        $update = $conn->prepare("UPDATE users SET last_login = NOW() WHERE userID = ?");
        if ($update) {
            $update->bind_param('i', $userId);
            $update->execute();
            $update->close();
        }

        return $previous;
    }
}

if (!function_exists('app_auth_remember_cookie_value')) {
    function app_auth_remember_cookie_value(): array
    {
        $names = app_auth_cookie_names();
        $raw = trim((string)($_COOKIE[$names['remember']] ?? ''));
        if ($raw === '' || strpos($raw, ':') === false) {
            return ['', ''];
        }

        [$selector, $validator] = array_pad(explode(':', $raw, 2), 2, '');
        return [trim($selector), trim($validator)];
    }
}

if (!function_exists('app_auth_store_session_payload')) {
    function app_auth_store_session_payload(array $userRow, ?string $previousLastLogin, string $mode, ?string $selector = null): void
    {
        $profileComplete = app_auth_profile_complete($userRow);
        $isVerified = ((int)($userRow['is_verified'] ?? 0) === 1);
        $role = (string)($userRow['role'] ?? 'user');
        $userId = (int)($userRow['id'] ?? $userRow['userID'] ?? 0);

        $_SESSION['user'] = [
            'id' => $userId,
            'email' => (string)($userRow['email'] ?? ''),
            'full_name' => (string)($userRow['full_name'] ?? 'User'),
            'role' => $role,
            'last_login' => $previousLastLogin,
            'profile_complete' => $profileComplete ? 1 : 0,
            'is_verified' => $isVerified ? 1 : 0,
        ];
        $_SESSION['user_id'] = $userId;
        $_SESSION['role'] = $role;
        $_SESSION['email'] = (string)($userRow['email'] ?? '');
        $_SESSION['full_name'] = (string)($userRow['full_name'] ?? 'User');
        $_SESSION['auth_session_mode'] = $mode;
        $_SESSION['auth_token_selector'] = $selector;

        $ttl = app_auth_session_mode_ttl($mode);
        $_SESSION['auth_session_expires_at'] = $ttl === null ? null : (time() + $ttl);
    }
}

if (!function_exists('app_auth_delete_remember_token_selector')) {
    function app_auth_delete_remember_token_selector(mysqli $conn, string $selector): void
    {
        $selector = trim($selector);
        if ($selector === '') {
            return;
        }

        app_auth_ensure_schema($conn);
        $stmt = $conn->prepare("DELETE FROM auth_remember_tokens WHERE selector = ?");
        if ($stmt) {
            $stmt->bind_param('s', $selector);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('app_auth_clear_current_remember_token')) {
    function app_auth_clear_current_remember_token(mysqli $conn): void
    {
        [$selector] = app_auth_remember_cookie_value();
        if ($selector !== '') {
            app_auth_delete_remember_token_selector($conn, $selector);
        }

        if (!empty($_SESSION['auth_token_selector'])) {
            app_auth_delete_remember_token_selector($conn, (string)$_SESSION['auth_token_selector']);
        }

        $names = app_auth_cookie_names();
        app_auth_cookie_clear($names['remember']);
    }
}

if (!function_exists('app_auth_issue_remember_token')) {
    function app_auth_issue_remember_token(mysqli $conn, int $userId, string $mode): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        app_auth_ensure_schema($conn);
        app_auth_clear_current_remember_token($conn);

        $selector = bin2hex(random_bytes(8));
        $validator = bin2hex(random_bytes(32));
        $tokenHash = app_auth_hash_value($validator);
        $expiresAt = app_auth_session_mode_ttl($mode) === null
            ? null
            : date('Y-m-d H:i:s', time() + (int)app_auth_session_mode_ttl($mode));

        $stmt = $conn->prepare("
            INSERT INTO auth_remember_tokens (userID, selector, tokenHash, sessionMode, expiresAt, lastUsedAt)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('issss', $userId, $selector, $tokenHash, $mode, $expiresAt);
        $stmt->execute();
        $stmt->close();

        $names = app_auth_cookie_names();
        app_auth_cookie_set($names['remember'], $selector . ':' . $validator, app_auth_mode_cookie_expiry($mode));

        return $selector;
    }
}

if (!function_exists('app_auth_set_login_hint')) {
    function app_auth_set_login_hint(string $loginIdentifier): void
    {
        $loginIdentifier = trim($loginIdentifier);
        if ($loginIdentifier === '') {
            return;
        }

        $names = app_auth_cookie_names();
        app_auth_cookie_set($names['login_hint'], $loginIdentifier, time() + (86400 * 30));
    }
}

if (!function_exists('app_auth_clear_login_hint')) {
    function app_auth_clear_login_hint(): void
    {
        $names = app_auth_cookie_names();
        app_auth_cookie_clear($names['login_hint']);
    }
}

if (!function_exists('app_auth_login_hint')) {
    function app_auth_login_hint(): string
    {
        $names = app_auth_cookie_names();
        return trim((string)($_COOKIE[$names['login_hint']] ?? ''));
    }
}

if (!function_exists('app_auth_complete_login')) {
    function app_auth_complete_login(mysqli $conn, array $userRow, string $mode = 'day', array $options = []): void
    {
        $userId = (int)($userRow['id'] ?? $userRow['userID'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unable to complete login because the user account is invalid.');
        }

        session_regenerate_id(true);
        $previousLastLogin = app_auth_update_last_login($conn, $userId);
        $selector = app_auth_issue_remember_token($conn, $userId, $mode);
        app_auth_store_session_payload($userRow, $previousLastLogin, $mode, $selector);

        $loginIdentifier = trim((string)($options['login_identifier'] ?? ''));
        $keepLoginHint = !empty($options['keep_login_hint']);
        if ($keepLoginHint && $loginIdentifier !== '') {
            app_auth_set_login_hint($loginIdentifier);
        } else {
            app_auth_clear_login_hint();
        }
    }
}

if (!function_exists('app_auth_post_login_target')) {
    function app_auth_post_login_target(array $userRow, string $default = '../index.php'): string
    {
        if (!app_auth_profile_complete($userRow)) {
            return 'complete_profile.php';
        }

        if ((int)($userRow['is_verified'] ?? 0) !== 1) {
            return 'verify.php';
        }

        if (function_exists('consumeAuthRedirectTarget')) {
            return consumeAuthRedirectTarget($default);
        }

        return $default;
    }
}

if (!function_exists('app_auth_restore_from_remember_token')) {
    function app_auth_restore_from_remember_token(mysqli $conn): bool
    {
        if (!empty($_SESSION['user']) || headers_sent()) {
            return !empty($_SESSION['user']);
        }

        app_auth_ensure_schema($conn);
        [$selector, $validator] = app_auth_remember_cookie_value();
        if ($selector === '' || $validator === '') {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT userID, tokenHash, sessionMode, expiresAt
            FROM auth_remember_tokens
            WHERE selector = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $selector);
        $stmt->execute();
        $result = $stmt->get_result();
        $tokenRow = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$tokenRow) {
            app_auth_clear_current_remember_token($conn);
            return false;
        }

        $expiresAt = trim((string)($tokenRow['expiresAt'] ?? ''));
        if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
            app_auth_delete_remember_token_selector($conn, $selector);
            app_auth_clear_current_remember_token($conn);
            return false;
        }

        $expectedHash = trim((string)($tokenRow['tokenHash'] ?? ''));
        if ($expectedHash === '' || !hash_equals($expectedHash, app_auth_hash_value($validator))) {
            app_auth_delete_remember_token_selector($conn, $selector);
            app_auth_clear_current_remember_token($conn);
            return false;
        }

        $user = app_auth_fetch_user_by_id($conn, (int)($tokenRow['userID'] ?? 0));
        if (!$user) {
            app_auth_delete_remember_token_selector($conn, $selector);
            app_auth_clear_current_remember_token($conn);
            return false;
        }

        $mode = trim((string)($tokenRow['sessionMode'] ?? 'day'));
        $newValidator = bin2hex(random_bytes(32));
        $newHash = app_auth_hash_value($newValidator);
        $newExpiresAt = app_auth_session_mode_ttl($mode) === null
            ? null
            : date('Y-m-d H:i:s', time() + (int)app_auth_session_mode_ttl($mode));

        $update = $conn->prepare("
            UPDATE auth_remember_tokens
            SET tokenHash = ?, expiresAt = ?, lastUsedAt = NOW()
            WHERE selector = ?
        ");
        if ($update) {
            $update->bind_param('sss', $newHash, $newExpiresAt, $selector);
            $update->execute();
            $update->close();
        }

        $names = app_auth_cookie_names();
        app_auth_cookie_set($names['remember'], $selector . ':' . $newValidator, app_auth_mode_cookie_expiry($mode));
        session_regenerate_id(true);
        app_auth_store_session_payload($user, $user['last_login'] ?? null, $mode, $selector);
        return true;
    }
}

if (!function_exists('app_auth_is_trusted_browser')) {
    function app_auth_is_trusted_browser(mysqli $conn, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        app_auth_ensure_schema($conn);
        $deviceValue = app_auth_device_cookie_value(false);
        if ($deviceValue === '') {
            return false;
        }

        $deviceHash = app_auth_hash_value($deviceValue);
        $stmt = $conn->prepare("
            SELECT trustID
            FROM auth_trusted_devices
            WHERE userID = ? AND deviceHash = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('is', $userId, $deviceHash);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return false;
        }

        $touch = $conn->prepare("UPDATE auth_trusted_devices SET lastUsedAt = NOW() WHERE trustID = ?");
        if ($touch) {
            $trustId = (int)$row['trustID'];
            $touch->bind_param('i', $trustId);
            $touch->execute();
            $touch->close();
        }

        return true;
    }
}

if (!function_exists('app_auth_trust_current_browser')) {
    function app_auth_trust_current_browser(mysqli $conn, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        app_auth_ensure_schema($conn);
        $deviceHash = app_auth_hash_value(app_auth_device_cookie_value(true));

        $stmt = $conn->prepare("
            INSERT INTO auth_trusted_devices (userID, deviceHash, lastUsedAt)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE lastUsedAt = NOW()
        ");
        if ($stmt) {
            $stmt->bind_param('is', $userId, $deviceHash);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('app_auth_clear_session_only')) {
    function app_auth_clear_session_only(): void
    {
        unset(
            $_SESSION['user'],
            $_SESSION['user_id'],
            $_SESSION['role'],
            $_SESSION['email'],
            $_SESSION['full_name'],
            $_SESSION['auth_session_mode'],
            $_SESSION['auth_session_expires_at'],
            $_SESSION['auth_token_selector'],
            $_SESSION['pending_2fa_login']
        );
    }
}

if (!function_exists('app_auth_logout')) {
    function app_auth_logout(mysqli $conn, bool $manualLogout = false): void
    {
        app_auth_clear_current_remember_token($conn);
        if ($manualLogout) {
            app_auth_clear_login_hint();
        }

        app_auth_clear_session_only();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}

if (!function_exists('app_auth_bootstrap')) {
    function app_auth_bootstrap(mysqli $conn): void
    {
        app_auth_ensure_schema($conn);
        app_auth_device_cookie_value(true);

        if (!empty($_SESSION['user'])) {
            $mode = trim((string)($_SESSION['auth_session_mode'] ?? ''));
            $expiresAt = $_SESSION['auth_session_expires_at'] ?? null;

            if ($mode !== 'forever' && is_numeric($expiresAt) && (int)$expiresAt > 0 && time() > (int)$expiresAt) {
                app_auth_logout($conn, false);
                return;
            }

            if ($mode === '') {
                $currentUserId = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
                $user = app_auth_fetch_user_by_id($conn, $currentUserId);
                if ($user) {
                    $selector = app_auth_issue_remember_token($conn, $currentUserId, 'day');
                    app_auth_store_session_payload($user, $_SESSION['user']['last_login'] ?? null, 'day', $selector);
                }
            }
            return;
        }

        app_auth_restore_from_remember_token($conn);
    }
}

if (!function_exists('app_auth_start_two_factor_challenge')) {
    function app_auth_start_two_factor_challenge(array $userRow, string $loginIdentifier, bool $rememberRequested): array
    {
        $userId = (int)($userRow['id'] ?? $userRow['userID'] ?? 0);
        $email = trim((string)($userRow['email'] ?? ''));
        if ($userId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => "We couldn't start two-factor authentication right now. Please try again.",
            ];
        }

        if (!function_exists('app_auth_send_two_factor_code_email')) {
            require_once dirname(__DIR__) . '/authentication/auth_mailer.php';
        }

        $code = (string)random_int(100000, 999999);
        $mailResult = app_auth_send_two_factor_code_email(
            $email,
            trim((string)($userRow['full_name'] ?? 'Customer')),
            $code
        );

        if (empty($mailResult['success'])) {
            return [
                'success' => false,
                'message' => (string)($mailResult['message'] ?? "We couldn't send the two-factor code right now. Please try again."),
            ];
        }

        $_SESSION['pending_2fa_login'] = [
            'user_id' => $userId,
            'email' => $email,
            'masked_email' => app_auth_mask_email($email),
            'login_identifier' => trim($loginIdentifier),
            'remember_requested' => $rememberRequested ? 1 : 0,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => time() + 600,
            'resend_available_at' => time() + 60,
            'attempt_count' => 0,
            'max_attempts' => 5,
        ];

        return [
            'success' => true,
            'message' => 'A 2FA code has been sent to your email address.',
        ];
    }
}

if (!function_exists('app_auth_pending_two_factor')) {
    function app_auth_pending_two_factor(): ?array
    {
        $pending = $_SESSION['pending_2fa_login'] ?? null;
        return is_array($pending) ? $pending : null;
    }
}

if (!function_exists('app_auth_clear_two_factor')) {
    function app_auth_clear_two_factor(): void
    {
        unset($_SESSION['pending_2fa_login']);
    }
}

if (!function_exists('app_auth_resend_two_factor')) {
    function app_auth_resend_two_factor(mysqli $conn): array
    {
        $pending = app_auth_pending_two_factor();
        if (!$pending) {
            return [
                'success' => false,
                'message' => 'Your login verification session expired. Please sign in again.',
            ];
        }

        $nextResendAt = (int)($pending['resend_available_at'] ?? 0);
        if ($nextResendAt > time()) {
            return [
                'success' => false,
                'message' => 'Please wait ' . max(1, $nextResendAt - time()) . ' seconds before requesting another code.',
            ];
        }

        $user = app_auth_fetch_user_by_id($conn, (int)($pending['user_id'] ?? 0));
        if (!$user) {
            app_auth_clear_two_factor();
            return [
                'success' => false,
                'message' => 'Your login verification session expired. Please sign in again.',
            ];
        }

        return app_auth_start_two_factor_challenge(
            $user,
            (string)($pending['login_identifier'] ?? ''),
            !empty($pending['remember_requested'])
        );
    }
}

if (!function_exists('app_auth_verify_two_factor_code')) {
    function app_auth_verify_two_factor_code(string $code): array
    {
        $pending = app_auth_pending_two_factor();
        if (!$pending) {
            return [
                'success' => false,
                'message' => 'Your login verification session expired. Please sign in again.',
            ];
        }

        if ((int)($pending['expires_at'] ?? 0) < time()) {
            app_auth_clear_two_factor();
            return [
                'success' => false,
                'message' => 'Your two-factor code expired. Please sign in again.',
            ];
        }

        $code = trim($code);
        if ($code === '') {
            return [
                'success' => false,
                'message' => 'Please enter the 2FA code from your email.',
            ];
        }

        $pending['attempt_count'] = (int)($pending['attempt_count'] ?? 0) + 1;
        $_SESSION['pending_2fa_login'] = $pending;

        $codeHash = (string)($pending['code_hash'] ?? '');
        if ($codeHash === '' || !password_verify($code, $codeHash)) {
            if ((int)$pending['attempt_count'] >= (int)($pending['max_attempts'] ?? 5)) {
                app_auth_clear_two_factor();
                return [
                    'success' => false,
                    'message' => 'Too many incorrect attempts. Please sign in again to receive a new code.',
                ];
            }

            return [
                'success' => false,
                'message' => 'The 2FA code is incorrect. Please try again.',
            ];
        }

        return [
            'success' => true,
            'pending' => $pending,
        ];
    }
}
