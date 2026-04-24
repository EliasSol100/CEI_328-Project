<?php

if (!function_exists('app_coupon_table_exists')) {
    function app_coupon_table_exists(mysqli $conn, string $tableName): bool
    {
        $safeTable = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '" . $safeTable . "'");
        return (bool)($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('app_coupon_ensure_schema')) {
    function app_coupon_ensure_schema(mysqli $conn): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        if (!app_coupon_table_exists($conn, 'promotions')) {
            return;
        }

        $check = mysqli_query($conn, "SHOW COLUMNS FROM promotions LIKE 'couponCode'");
        if (!$check || mysqli_num_rows($check) === 0) {
            mysqli_query($conn, "ALTER TABLE promotions ADD COLUMN couponCode VARCHAR(64) NULL AFTER promotionName");
        }
    }
}

if (!function_exists('app_coupon_normalize_code')) {
    function app_coupon_normalize_code(string $code): string
    {
        $code = strtoupper(trim($code));
        return (string)preg_replace('/[^A-Z0-9_-]/', '', $code);
    }
}

if (!function_exists('app_coupon_find_active')) {
    function app_coupon_find_active(mysqli $conn, string $couponCode): ?array
    {
        app_coupon_ensure_schema($conn);
        $couponCode = app_coupon_normalize_code($couponCode);
        if ($couponCode === '') {
            return null;
        }

        $sql = "
            SELECT p.promotionID, p.promotionName, p.discountType, p.discountValue, p.scope, p.categoryID, c.categoryName
            FROM promotions p
            LEFT JOIN categories c ON c.categoryID = p.categoryID
            WHERE p.isActive = 1
              AND UPPER(TRIM(COALESCE(p.couponCode, ''))) = ?
              AND (p.startDate IS NULL OR p.startDate <= CURDATE())
              AND (p.endDate IS NULL OR p.endDate >= CURDATE())
            ORDER BY p.createdAt DESC, p.promotionID DESC
            LIMIT 1
        ";
        $st = mysqli_prepare($conn, $sql);
        if (!$st) {
            return null;
        }
        mysqli_stmt_bind_param($st, 's', $couponCode);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($st);

        return $row ?: null;
    }
}

if (!function_exists('app_coupon_cart_line_totals_with_category')) {
    function app_coupon_cart_line_totals_with_category(mysqli $conn, array $cartItems): array
    {
        $lineTotals = [];
        $productIds = [];

        foreach ($cartItems as $item) {
            $productId = (int)($item['product']['id'] ?? $item['productID'] ?? $item['product_id'] ?? 0);
            $qty = max(1, (int)($item['quantity'] ?? 1));
            $basePrice = (float)($item['product']['basePrice'] ?? 0);
            $addonsCost = (float)($item['addons']['addonsCost'] ?? 0);
            if ($addonsCost <= 0) {
                if (!empty($item['addons']['giftWrapping'])) $addonsCost += 2.0;
                if (!empty($item['addons']['giftBagFlag'])) $addonsCost += 1.5;
            }
            $unit = (float)($item['price'] ?? $item['pricing']['unitTotal'] ?? ($basePrice + $addonsCost));
            $lineTotals[] = [
                'product_id' => $productId,
                'line_total' => max(0.0, $unit * $qty),
                'category' => '',
            ];
            if ($productId > 0) {
                $productIds[$productId] = true;
            }
        }

        $categoryByProduct = [];
        if (!empty($productIds)) {
            $ids = implode(',', array_map('intval', array_keys($productIds)));
            $res = mysqli_query($conn, "SELECT productID, COALESCE(category, '') AS category FROM products WHERE productID IN ({$ids})");
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $categoryByProduct[(int)$row['productID']] = (string)$row['category'];
                }
            }
        }

        foreach ($lineTotals as &$line) {
            $line['category'] = $categoryByProduct[(int)$line['product_id']] ?? '';
        }
        unset($line);

        return $lineTotals;
    }
}

if (!function_exists('app_coupon_compute_discount')) {
    function app_coupon_compute_discount(mysqli $conn, array $cartItems, array $promotion): float
    {
        $discountType = strtolower(trim((string)($promotion['discountType'] ?? 'percentage')));
        $discountValue = max(0, (float)($promotion['discountValue'] ?? 0));
        $scope = strtolower(trim((string)($promotion['scope'] ?? 'store')));
        $targetCategory = trim((string)($promotion['categoryName'] ?? ''));
        $eligibleSubtotal = 0.0;

        foreach (app_coupon_cart_line_totals_with_category($conn, $cartItems) as $line) {
            if ($scope === 'category' && ($targetCategory === '' || strcasecmp((string)$line['category'], $targetCategory) !== 0)) {
                continue;
            }
            $eligibleSubtotal += (float)$line['line_total'];
        }

        if ($eligibleSubtotal <= 0 || $discountValue <= 0) {
            return 0.0;
        }

        if ($discountType === 'fixed') {
            return round(min($eligibleSubtotal, $discountValue), 2);
        }

        return round(min($eligibleSubtotal, $eligibleSubtotal * ($discountValue / 100)), 2);
    }
}

if (!function_exists('app_coupon_evaluate_cart')) {
    function app_coupon_evaluate_cart(mysqli $conn, array $cartItems, string $couponCode): array
    {
        $couponCode = app_coupon_normalize_code($couponCode);
        $result = [
            'valid' => false,
            'coupon_code' => $couponCode,
            'promotion_name' => '',
            'discount_amount' => 0.0,
            'message' => '',
        ];

        if ($couponCode === '') {
            $result['message'] = 'Enter a coupon code.';
            return $result;
        }

        if (empty($cartItems)) {
            $result['message'] = 'Add products to your cart before applying a coupon.';
            return $result;
        }

        $promotion = app_coupon_find_active($conn, $couponCode);
        if (!$promotion) {
            $result['message'] = 'Coupon code is invalid, expired, or not applicable to your cart.';
            return $result;
        }

        $discountAmount = app_coupon_compute_discount($conn, $cartItems, $promotion);
        if ($discountAmount <= 0) {
            $result['message'] = 'Coupon code is invalid, expired, or not applicable to your cart.';
            return $result;
        }

        $result['valid'] = true;
        $result['promotion_name'] = (string)($promotion['promotionName'] ?? $couponCode);
        $result['discount_amount'] = $discountAmount;
        $result['message'] = 'Coupon applied: ' . $result['promotion_name'];

        return $result;
    }
}
