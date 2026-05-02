<?php

if (!function_exists('app_cart_current_user_id')) {
    function app_cart_current_user_id(): int
    {
        $sessionUser = $_SESSION['user'] ?? [];
        if (is_array($sessionUser)) {
            return (int)($sessionUser['id'] ?? $sessionUser['userID'] ?? 0);
        }
        return (int)($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('app_cart_default_state')) {
    function app_cart_default_state(): array
    {
        return [
            'items' => [],
            'totals' => [
                'items_count' => 0,
                'subtotal' => 0.0,
                'addons_total' => 0.0,
                'grand_total' => 0.0,
            ],
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
    }
}

if (!function_exists('app_cart_ensure_persistence_schema')) {
    function app_cart_ensure_persistence_schema(mysqli $conn): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        $conn->query("
            CREATE TABLE IF NOT EXISTS user_cart_snapshots (
                userID INT NOT NULL PRIMARY KEY,
                cartJSON MEDIUMTEXT NOT NULL,
                updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_user_cart_snapshots_user FOREIGN KEY (userID) REFERENCES users(userID) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
}

if (!function_exists('app_cart_normalize_state')) {
    function app_cart_normalize_state($cart): array
    {
        if (!is_array($cart)) {
            return app_cart_default_state();
        }
        if (!isset($cart['items']) || !is_array($cart['items'])) {
            $cart['items'] = [];
        }
        if (!isset($cart['totals']) || !is_array($cart['totals'])) {
            $cart['totals'] = app_cart_default_state()['totals'];
        }
        $cart['totals']['items_count'] = array_reduce($cart['items'], static function (int $carry, array $item): int {
            return $carry + max(0, (int)($item['quantity'] ?? 0));
        }, 0);
        $cart['updated_at'] = (string)($cart['updated_at'] ?? gmdate('c'));
        $cart['created_at'] = (string)($cart['created_at'] ?? gmdate('c'));
        return $cart;
    }
}

if (!function_exists('app_cart_restore_for_current_user')) {
    function app_cart_restore_for_current_user(mysqli $conn): void
    {
        $userId = app_cart_current_user_id();
        if ($userId <= 0) {
            return;
        }
        $currentItems = $_SESSION['cart']['items'] ?? [];
        if (is_array($currentItems) && count($currentItems) > 0) {
            return;
        }
        app_cart_ensure_persistence_schema($conn);
        $stmt = $conn->prepare("SELECT cartJSON FROM user_cart_snapshots WHERE userID = ? LIMIT 1");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            return;
        }
        $decoded = json_decode((string)$row['cartJSON'], true);
        $_SESSION['cart'] = app_cart_normalize_state($decoded);
    }
}

if (!function_exists('app_cart_persist_for_current_user')) {
    function app_cart_persist_for_current_user(mysqli $conn): void
    {
        $userId = app_cart_current_user_id();
        if ($userId <= 0) {
            return;
        }
        app_cart_ensure_persistence_schema($conn);
        $cart = app_cart_normalize_state($_SESSION['cart'] ?? app_cart_default_state());
        $json = json_encode($cart, JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            return;
        }
        $stmt = $conn->prepare("
            INSERT INTO user_cart_snapshots (userID, cartJSON)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE cartJSON = VALUES(cartJSON), updatedAt = CURRENT_TIMESTAMP
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('is', $userId, $json);
        $stmt->execute();
        $stmt->close();
    }
}
