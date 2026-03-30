<?php

if (!function_exists('ensureMadeToOrderProductSchema')) {
    function ensureMadeToOrderProductSchema(mysqli $conn): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $requiredColumns = [
            'privateCustomerEmail' => "ALTER TABLE products ADD COLUMN privateCustomerEmail VARCHAR(255) NULL AFTER category",
            'privateAccessToken' => "ALTER TABLE products ADD COLUMN privateAccessToken VARCHAR(80) NULL AFTER privateCustomerEmail",
            'privateLinkSentAt' => "ALTER TABLE products ADD COLUMN privateLinkSentAt DATETIME NULL AFTER privateAccessToken",
        ];

        foreach ($requiredColumns as $columnName => $sql) {
            $safeColumn = $conn->real_escape_string($columnName);
            $check = $conn->query("SHOW COLUMNS FROM products LIKE '{$safeColumn}'");
            $exists = ($check && $check->num_rows > 0);
            if (!$exists) {
                $conn->query($sql);
            }
        }
    }
}

if (!function_exists('normalizeCustomerEmail')) {
    function normalizeCustomerEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}

if (!function_exists('generateMadeToOrderAccessToken')) {
    function generateMadeToOrderAccessToken(): string
    {
        return bin2hex(random_bytes(24));
    }
}

if (!function_exists('isCurrentSessionAdminUser')) {
    function isCurrentSessionAdminUser(): bool
    {
        $role = '';
        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $role = (string)($_SESSION['user']['role'] ?? '');
        } else {
            $role = (string)($_SESSION['role'] ?? '');
        }

        return in_array(strtolower(trim($role)), ['admin', 'administrator', 'superadmin'], true);
    }
}

if (!function_exists('currentSessionUserEmail')) {
    function currentSessionUserEmail(): string
    {
        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $email = (string)($_SESSION['user']['email'] ?? '');
            return normalizeCustomerEmail($email);
        }
        return normalizeCustomerEmail((string)($_SESSION['email'] ?? ''));
    }
}

if (!function_exists('setMadeToOrderSessionAccess')) {
    function setMadeToOrderSessionAccess(int $productId, string $token): void
    {
        if ($productId <= 0 || trim($token) === '') {
            return;
        }
        if (!isset($_SESSION['made_to_order_access']) || !is_array($_SESSION['made_to_order_access'])) {
            $_SESSION['made_to_order_access'] = [];
        }
        $_SESSION['made_to_order_access'][(string)$productId] = $token;
    }
}

if (!function_exists('getMadeToOrderSessionToken')) {
    function getMadeToOrderSessionToken(int $productId): string
    {
        if ($productId <= 0) {
            return '';
        }
        $map = $_SESSION['made_to_order_access'] ?? [];
        if (!is_array($map)) {
            return '';
        }
        return trim((string)($map[(string)$productId] ?? ''));
    }
}

if (!function_exists('loadMadeToOrderProductAccessRow')) {
    function loadMadeToOrderProductAccessRow(mysqli $conn, int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        ensureMadeToOrderProductSchema($conn);
        $stmt = $conn->prepare("
            SELECT productID, cartStatus, privateCustomerEmail, privateAccessToken
            FROM products
            WHERE productID = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('grantMadeToOrderAccessFromLink')) {
    function grantMadeToOrderAccessFromLink(mysqli $conn, int $productId, string $token): array
    {
        $token = trim($token);
        if ($productId <= 0 || $token === '') {
            return ['ok' => false, 'reason' => 'invalid_link'];
        }

        $row = loadMadeToOrderProductAccessRow($conn, $productId);
        if (!$row || (string)($row['cartStatus'] ?? '') !== 'made_to_order') {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        $expectedToken = trim((string)($row['privateAccessToken'] ?? ''));
        if ($expectedToken === '' || !hash_equals($expectedToken, $token)) {
            return ['ok' => false, 'reason' => 'invalid_token'];
        }

        if (isCurrentSessionAdminUser()) {
            setMadeToOrderSessionAccess($productId, $token);
            return ['ok' => true, 'reason' => 'admin_granted'];
        }

        $sessionEmail = currentSessionUserEmail();
        $targetEmail = normalizeCustomerEmail((string)($row['privateCustomerEmail'] ?? ''));
        if ($sessionEmail === '') {
            return ['ok' => false, 'reason' => 'login_required'];
        }
        if ($targetEmail === '' || $sessionEmail !== $targetEmail) {
            return ['ok' => false, 'reason' => 'email_mismatch'];
        }

        setMadeToOrderSessionAccess($productId, $token);
        return ['ok' => true, 'reason' => 'granted'];
    }
}

if (!function_exists('getAccessibleMadeToOrderProductIds')) {
    function getAccessibleMadeToOrderProductIds(mysqli $conn): array
    {
        ensureMadeToOrderProductSchema($conn);

        $sessionEmail = currentSessionUserEmail();
        if ($sessionEmail === '') {
            return [];
        }

        $map = $_SESSION['made_to_order_access'] ?? [];
        if (!is_array($map) || empty($map)) {
            return [];
        }

        $pairs = [];
        foreach ($map as $pid => $token) {
            $pid = (int)$pid;
            $token = trim((string)$token);
            if ($pid > 0 && $token !== '') {
                $pairs[$pid] = $token;
            }
        }
        if (empty($pairs)) {
            return [];
        }

        $idsSql = implode(',', array_map('intval', array_keys($pairs)));
        $safeEmail = $conn->real_escape_string($sessionEmail);
        $res = $conn->query("
            SELECT productID, privateAccessToken
            FROM products
            WHERE cartStatus = 'made_to_order'
              AND productID IN ({$idsSql})
              AND LOWER(TRIM(COALESCE(privateCustomerEmail, ''))) = '{$safeEmail}'
        ");
        if (!$res) {
            return [];
        }

        $allowed = [];
        while ($row = $res->fetch_assoc()) {
            $pid = (int)$row['productID'];
            $dbToken = trim((string)($row['privateAccessToken'] ?? ''));
            $sessionToken = $pairs[$pid] ?? '';
            if ($dbToken !== '' && $sessionToken !== '' && hash_equals($dbToken, $sessionToken)) {
                $allowed[] = $pid;
            }
        }

        return $allowed;
    }
}

if (!function_exists('isMadeToOrderProductAccessible')) {
    function isMadeToOrderProductAccessible(mysqli $conn, int $productId): bool
    {
        $row = loadMadeToOrderProductAccessRow($conn, $productId);
        if (!$row) {
            return false;
        }

        if ((string)($row['cartStatus'] ?? '') !== 'made_to_order') {
            return true;
        }

        if (isCurrentSessionAdminUser()) {
            return true;
        }

        $sessionEmail = currentSessionUserEmail();
        $targetEmail = normalizeCustomerEmail((string)($row['privateCustomerEmail'] ?? ''));
        if ($sessionEmail === '' || $targetEmail === '' || $sessionEmail !== $targetEmail) {
            return false;
        }

        $token = getMadeToOrderSessionToken($productId);
        $expectedToken = trim((string)($row['privateAccessToken'] ?? ''));
        if ($token === '' || $expectedToken === '') {
            return false;
        }
        return hash_equals($expectedToken, $token);
    }
}
