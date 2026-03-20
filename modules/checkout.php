<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
define('INCLUDE_CHECK', true);

// Correct relative path: go up one level from 'modules' to project root, then into 'authentication'
require_once __DIR__ . '/../authentication/database.php';
require_once __DIR__ . '/../include/loyalty_program.php';
require_once __DIR__ . '/place_order.php';

// Optional: include get_config.php if it exists (to avoid errors if missing)
$configPath = __DIR__ . '/../authentication/get_config.php';
if (file_exists($configPath)) {
    require_once $configPath;
    $system_title = function_exists('getSystemConfig') ? getSystemConfig('site_title') : 'Creations by Athina';
} else {
    $system_title = 'Creations by Athina'; // fallback title
}

// Check database connection
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn->connect_error ?? 'Unknown error'));
}

function ensurePromotionCouponColumn(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $check = $conn->query("SHOW COLUMNS FROM promotions LIKE 'couponCode'");
    $exists = ($check && $check->num_rows > 0);
    if (!$exists) {
        $conn->query("ALTER TABLE promotions ADD COLUMN couponCode VARCHAR(64) NULL AFTER promotionName");
    }
}

function normalizeCouponCode(string $code): string {
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
    return (string)$code;
}

function findActiveCouponPromotion(mysqli $conn, string $couponCode): ?array {
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
    $st = $conn->prepare($sql);
    if (!$st) {
        return null;
    }
    $st->bind_param("s", $couponCode);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return $row ?: null;
}

function checkoutSanitizePositiveInt($value): int {
    $digits = preg_replace('/\D/', '', (string)$value);
    return max(0, (int)$digits);
}

function checkoutResetLoyaltySelection(): void {
    unset($_SESSION['cart_loyalty_points_redeem'], $_SESSION['cart_loyalty_user_id']);
}

function checkoutStoreLoyaltySelection(int $userId, int $points): void {
    if ($userId <= 0 || $points <= 0) {
        checkoutResetLoyaltySelection();
        return;
    }

    $_SESSION['cart_loyalty_points_redeem'] = $points;
    $_SESSION['cart_loyalty_user_id'] = $userId;
}

function cartLineTotalsWithCategory(mysqli $conn, array $cartItems): array {
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
            'line_total' => $unit * $qty,
            'category' => '',
        ];
        if ($productId > 0) {
            $productIds[$productId] = true;
        }
    }

    $categoryByProduct = [];
    if (!empty($productIds)) {
        $ids = implode(',', array_map('intval', array_keys($productIds)));
        $res = $conn->query("SELECT productID, COALESCE(category, '') AS category FROM products WHERE productID IN ({$ids})");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $categoryByProduct[(int)$row['productID']] = (string)$row['category'];
            }
        }
    }

    foreach ($lineTotals as &$line) {
        $pid = (int)$line['product_id'];
        $line['category'] = $categoryByProduct[$pid] ?? '';
    }
    unset($line);

    return $lineTotals;
}

function computePromotionDiscount(mysqli $conn, array $cartItems, array $promotion): float {
    $discountType = strtolower(trim((string)($promotion['discountType'] ?? 'percentage')));
    $discountValue = max(0, (float)($promotion['discountValue'] ?? 0));
    $scope = strtolower(trim((string)($promotion['scope'] ?? 'store')));

    $lines = cartLineTotalsWithCategory($conn, $cartItems);
    $eligibleSubtotal = 0.0;

    if ($scope === 'category') {
        $targetCategory = trim((string)($promotion['categoryName'] ?? ''));
        foreach ($lines as $line) {
            if ($targetCategory !== '' && strcasecmp((string)$line['category'], $targetCategory) === 0) {
                $eligibleSubtotal += (float)$line['line_total'];
            }
        }
    } else {
        foreach ($lines as $line) {
            $eligibleSubtotal += (float)$line['line_total'];
        }
    }

    if ($eligibleSubtotal <= 0) {
        return 0.0;
    }

    if ($discountType === 'fixed') {
        return min($eligibleSubtotal, $discountValue);
    }

    return min($eligibleSubtotal, ($eligibleSubtotal * $discountValue) / 100);
}

function checkoutNormalizeCountry(string $country, array $availableCountries): string {
    $country = trim($country);
    foreach ($availableCountries as $allowed) {
        if (strcasecmp($country, (string)$allowed) === 0) {
            return (string)$allowed;
        }
    }
    return (string)($availableCountries[0] ?? 'Cyprus');
}

function checkoutCountryCouriers(string $country, array $countryCouriers): array {
    return $countryCouriers[$country] ?? [];
}

function checkoutIsCourierAllowed(string $country, string $courierCode, array $countryCouriers): bool {
    if ($courierCode === '') {
        return false;
    }
    $allowed = checkoutCountryCouriers($country, $countryCouriers);
    return array_key_exists($courierCode, $allowed);
}

function checkoutShippingCost(
    string $country,
    string $speed,
    float $cartTotal,
    float $freeShippingThreshold,
    array $shippingRatesByCountry
): float {
    if ($cartTotal >= $freeShippingThreshold) {
        return 0.0;
    }
    if (!isset($shippingRatesByCountry[$country])) {
        return 0.0;
    }
    if (!isset($shippingRatesByCountry[$country][$speed])) {
        return 0.0;
    }
    return (float)$shippingRatesByCountry[$country][$speed];
}

function checkoutTableExists(mysqli $conn, string $tableName): bool {
    $safe = $conn->real_escape_string($tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function checkoutLoadDefaultAddress(mysqli $conn, int $userId): array {
    $default = [
        'address' => '',
        'city' => '',
        'postal_code' => '',
        'country' => '',
        'label' => '',
        'source' => '',
    ];
    if ($userId <= 0) {
        return $default;
    }

    if (checkoutTableExists($conn, 'user_addresses')) {
        $addressStmt = $conn->prepare("
            SELECT label, country, city, address, postcode
            FROM user_addresses
            WHERE user_id = ? AND is_default = 1
            ORDER BY created_at ASC, id ASC
            LIMIT 1
        ");
        if ($addressStmt) {
            $addressStmt->bind_param('i', $userId);
            $addressStmt->execute();
            $res = $addressStmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $addressStmt->close();

            if ($row) {
                $default['address'] = trim((string)($row['address'] ?? ''));
                $default['city'] = trim((string)($row['city'] ?? ''));
                $default['postal_code'] = trim((string)($row['postcode'] ?? ''));
                $default['country'] = trim((string)($row['country'] ?? ''));
                $default['label'] = trim((string)($row['label'] ?? ''));
                $default['source'] = 'address_book';
                return $default;
            }
        }
    }

    $profileStmt = $conn->prepare("
        SELECT country, city, address, postcode
        FROM users
        WHERE userID = ?
        LIMIT 1
    ");
    if (!$profileStmt) {
        return $default;
    }
    $profileStmt->bind_param('i', $userId);
    $profileStmt->execute();
    $res = $profileStmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $profileStmt->close();

    if ($row) {
        $default['address'] = trim((string)($row['address'] ?? ''));
        $default['city'] = trim((string)($row['city'] ?? ''));
        $default['postal_code'] = trim((string)($row['postcode'] ?? ''));
        $default['country'] = trim((string)($row['country'] ?? ''));
        $default['source'] = 'registration';
    }

    return $default;
}

ensurePromotionCouponColumn($conn);
ensureLoyaltyProgramSchema($conn);

// Build project root for URLs dynamically so the page works in nested folders too.
$project = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($project === '' || $project === '.') {
    $project = '';
}

// ----- CSRF TOKEN -----
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ----- USER INFO -----
$isLoggedIn = isset($_SESSION["user"]);
$userId = $isLoggedIn ? (int)($_SESSION["user"]["id"] ?? $_SESSION["user"]["userID"] ?? 0) : 0;
$userEmail = $isLoggedIn ? ($_SESSION["user"]["email"] ?? null) : null;
$userFullName = $isLoggedIn ? ($_SESSION["user"]["full_name"] ?? 'User') : null;
if (!$isLoggedIn || $userId <= 0) {
    checkoutResetLoyaltySelection();
} elseif (isset($_SESSION['cart_loyalty_user_id']) && (int)$_SESSION['cart_loyalty_user_id'] !== $userId) {
    checkoutResetLoyaltySelection();
}

// ----- CART -----
// Support both cart shapes:
// 1) New shape from cart_api.php: $_SESSION['cart']['items']
// 2) Legacy shape: $_SESSION['cart'] as plain item list
$sessionCart = $_SESSION['cart'] ?? [];
$cartItems = (is_array($sessionCart) && isset($sessionCart['items']) && is_array($sessionCart['items']))
    ? $sessionCart['items']
    : (is_array($sessionCart) ? $sessionCart : []);
$cartTotal = 0;
$cartCount = 0;
foreach ($cartItems as $item) {
    $basePrice = (float)($item['product']['basePrice'] ?? 0);
    $addonsCost = (float)($item['addons']['addonsCost'] ?? 0);
    if ($addonsCost <= 0) {
        if (!empty($item['addons']['giftWrapping'])) $addonsCost += 2.0;
        if (!empty($item['addons']['giftBagFlag'])) $addonsCost += 1.5;
    }
    $price = (float)($item['price'] ?? $item['pricing']['unitTotal'] ?? ($basePrice + $addonsCost));
    $qty = (int)($item['quantity'] ?? 1);
    $cartTotal += $price * $qty;
    $cartCount += $qty;
}
if (empty($cartItems)) {
    header('Location: ' . $project . '/cart.php');
    exit;
}

$couponAction = '';
$loyaltyAction = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $couponAction = strtolower(trim((string)($_POST['coupon_action'] ?? '')));
    if (!in_array($couponAction, ['apply', 'remove'], true)) {
        $couponAction = '';
    }
    if ($couponAction === 'remove') {
        $_POST['coupon_code'] = '';
    }

    $loyaltyAction = strtolower(trim((string)($_POST['loyalty_action'] ?? '')));
    if (!in_array($loyaltyAction, ['apply', 'remove'], true)) {
        $loyaltyAction = '';
    }
    if ($loyaltyAction === 'remove') {
        $_POST['loyalty_points'] = '';
    }
}

$selectedCouponCode = normalizeCouponCode((string)($_POST['coupon_code'] ?? ($_SESSION['cart_coupon_code'] ?? '')));
$activeCoupon = null;
$couponDiscount = 0.0;
$couponMessage = '';
if ($selectedCouponCode !== '') {
    $activeCoupon = findActiveCouponPromotion($conn, $selectedCouponCode);
    if ($activeCoupon) {
        $couponDiscount = round(computePromotionDiscount($conn, $cartItems, $activeCoupon), 2);
        if ($couponDiscount > 0) {
            $_SESSION['cart_coupon_code'] = $selectedCouponCode;
            $couponMessage = 'Coupon applied: ' . (string)($activeCoupon['promotionName'] ?? $selectedCouponCode);
        } else {
            unset($_SESSION['cart_coupon_code']);
        }
    } else {
        unset($_SESSION['cart_coupon_code']);
    }
} else {
    unset($_SESSION['cart_coupon_code']);
}

$availableLoyaltyBalance = ($isLoggedIn && $userId > 0)
    ? loyaltyGetCurrentBalance($conn, $userId)
    : 0;
$loyaltyEligibleSubtotal = max(0, round($cartTotal - $couponDiscount, 2));
$selectedLoyaltyPoints = ($isLoggedIn && $userId > 0)
    ? checkoutSanitizePositiveInt($_POST['loyalty_points'] ?? ($_SESSION['cart_loyalty_points_redeem'] ?? 0))
    : 0;
$loyaltyMessage = '';
$loyaltyRedemption = loyaltyBuildRedemptionPreview(
    $selectedLoyaltyPoints,
    $availableLoyaltyBalance,
    $loyaltyEligibleSubtotal
);

if ($selectedLoyaltyPoints > 0 && $loyaltyRedemption['error'] !== '' && $loyaltyAction !== 'apply') {
    if ((int)$loyaltyRedemption['max_points_allowed'] > 0 && $isLoggedIn && $userId > 0) {
        $selectedLoyaltyPoints = (int)$loyaltyRedemption['max_points_allowed'];
        checkoutStoreLoyaltySelection($userId, $selectedLoyaltyPoints);
        $loyaltyRedemption = loyaltyBuildRedemptionPreview(
            $selectedLoyaltyPoints,
            $availableLoyaltyBalance,
            $loyaltyEligibleSubtotal
        );
        $loyaltyMessage = 'Loyalty redemption was adjusted to match your current cart.';
    } else {
        checkoutResetLoyaltySelection();
        $selectedLoyaltyPoints = 0;
        $loyaltyRedemption = loyaltyBuildRedemptionPreview(0, $availableLoyaltyBalance, $loyaltyEligibleSubtotal);
        $loyaltyMessage = 'Loyalty redemption was removed because this order is no longer eligible.';
    }
}

$loyaltyDiscount = (float)($loyaltyRedemption['discount_amount'] ?? 0);
$estimatedEarnedPoints = (($isLoggedIn && $userId > 0) || (!empty($_POST['create_account']) && $_POST['create_account'] === 'yes'))
    ? loyaltyCalculateEarnedPoints(max(0, round($loyaltyEligibleSubtotal - $loyaltyDiscount, 2)))
    : 0;

// ----- SHIPPING -----
$countryCouriers = [
    'Cyprus' => [
        'akis_express' => 'Akis Express',
        'boxnow' => 'BoxNow',
        'acs' => 'ACS',
    ],
    'Greece' => [
        'elta_courier' => 'ELTA Courier',
        'speedex' => 'Speedex',
        'geniki' => 'Geniki Taxydromiki',
    ],
];
$shippingRatesByCountry = [
    'Cyprus' => ['standard' => 2.00, 'express' => 5.00],
    'Greece' => ['standard' => 4.00, 'express' => 10.00],
];
$fulfillmentModes = ['delivery', 'pickup'];
$shippingSpeeds = ['standard', 'express'];
$shippingModeLabels = [
    'delivery' => 'Deliver to my address',
    'pickup' => 'Pickup from courier point',
];
$freeShippingThreshold = 100.0;
$freeShippingEligible = $cartTotal >= $freeShippingThreshold;
$shippingDifference = max(0.0, $freeShippingThreshold - $cartTotal);
$availableCountries = array_keys($countryCouriers);
$defaultAddress = $isLoggedIn && $userId > 0 ? checkoutLoadDefaultAddress($conn, $userId) : [
    'address' => '',
    'city' => '',
    'postal_code' => '',
    'country' => '',
    'label' => '',
    'source' => '',
];
$hasDefaultAddressData = (
    trim((string)$defaultAddress['address']) !== '' ||
    trim((string)$defaultAddress['city']) !== '' ||
    trim((string)$defaultAddress['postal_code']) !== '' ||
    trim((string)$defaultAddress['country']) !== ''
);

// ----- FORM HANDLING -----
$errors = [];
$error = '';
$formData = $_POST;
if (!isset($formData['coupon_code']) && $selectedCouponCode !== '') {
    $formData['coupon_code'] = $selectedCouponCode;
}
if (!isset($formData['loyalty_points']) && $selectedLoyaltyPoints > 0) {
    $formData['loyalty_points'] = (string)$selectedLoyaltyPoints;
}
if (!isset($formData['shipping_speed']) || !in_array((string)$formData['shipping_speed'], $shippingSpeeds, true)) {
    $formData['shipping_speed'] = 'standard';
}
if (!isset($formData['fulfillment_mode']) || !in_array((string)$formData['fulfillment_mode'], $fulfillmentModes, true)) {
    $formData['fulfillment_mode'] = 'delivery';
}
if (!isset($formData['shipping_label'])) {
    $formData['shipping_label'] = '';
}
if (!isset($formData['shipping_country']) || trim((string)$formData['shipping_country']) === '') {
    $fallbackCountry = trim((string)($defaultAddress['country'] ?? '')) !== ''
        ? (string)$defaultAddress['country']
        : (string)($availableCountries[0] ?? 'Cyprus');
    $formData['shipping_country'] = checkoutNormalizeCountry($fallbackCountry, $availableCountries);
}

if ($isLoggedIn) {
    if (!isset($formData['use_saved_address'])) {
        $formData['use_saved_address'] = ($_SERVER['REQUEST_METHOD'] === 'POST')
            ? '0'
            : ($hasDefaultAddressData ? '1' : '0');
    }
    if ((string)$formData['use_saved_address'] === '1' && $hasDefaultAddressData) {
        if (trim((string)($formData['shipping_address'] ?? '')) === '') {
            $formData['shipping_address'] = (string)$defaultAddress['address'];
        }
        if (trim((string)($formData['shipping_city'] ?? '')) === '') {
            $formData['shipping_city'] = (string)$defaultAddress['city'];
        }
        if (trim((string)($formData['shipping_postal_code'] ?? '')) === '') {
            $formData['shipping_postal_code'] = (string)$defaultAddress['postal_code'];
        }
        if (trim((string)($formData['shipping_country'] ?? '')) === '') {
            $formData['shipping_country'] = checkoutNormalizeCountry((string)$defaultAddress['country'], $availableCountries);
        }
        if (trim((string)($formData['shipping_label'] ?? '')) === '') {
            $formData['shipping_label'] = (string)$defaultAddress['label'];
        }
    }
}

$selectedCountry = checkoutNormalizeCountry((string)($formData['shipping_country'] ?? ''), $availableCountries);
$formData['shipping_country'] = $selectedCountry;
$selectedSpeed = (string)$formData['shipping_speed'];
$countryCourierOptions = checkoutCountryCouriers($selectedCountry, $countryCouriers);
$selectedCourier = (string)($formData['courier'] ?? '');
if (!checkoutIsCourierAllowed($selectedCountry, $selectedCourier, $countryCouriers)) {
    $selectedCourier = (string)(array_key_first($countryCourierOptions) ?? '');
}
$formData['courier'] = $selectedCourier;
$displayShippingCost = checkoutShippingCost(
    $selectedCountry,
    $selectedSpeed,
    (float)$cartTotal,
    (float)$freeShippingThreshold,
    $shippingRatesByCountry
);
$displayTotal = max(0, ($cartTotal - $couponDiscount - $loyaltyDiscount) + $displayShippingCost);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }

    $isCouponOnlyPost = ($couponAction !== '');
    $isLoyaltyOnlyPost = ($loyaltyAction !== '');
    if ($isCouponOnlyPost) {
        if ($couponAction === 'remove') {
            unset($_SESSION['cart_coupon_code']);
            $selectedCouponCode = '';
            $activeCoupon = null;
            $couponDiscount = 0.0;
            $couponMessage = 'Coupon removed.';
            $_POST['coupon_code'] = '';
            $formData['coupon_code'] = '';
        } else {
            $formData['coupon_code'] = $selectedCouponCode;
            if ($selectedCouponCode === '') {
                unset($_SESSION['cart_coupon_code']);
                $errors['coupon_code'] = 'Enter a coupon code to apply.';
            } elseif (!$activeCoupon || $couponDiscount <= 0) {
                unset($_SESSION['cart_coupon_code']);
                $errors['coupon_code'] = 'Coupon code is invalid, expired, or not applicable to your cart.';
                $couponMessage = '';
            } else {
                $couponMessage = 'Coupon applied: ' . (string)($activeCoupon['promotionName'] ?? $selectedCouponCode);
            }
        }
        $loyaltyEligibleSubtotal = max(0, round($cartTotal - $couponDiscount, 2));
        if ($selectedLoyaltyPoints > 0) {
            $loyaltyRedemption = loyaltyBuildRedemptionPreview(
                $selectedLoyaltyPoints,
                $availableLoyaltyBalance,
                $loyaltyEligibleSubtotal
            );
            if ($loyaltyRedemption['error'] !== '') {
                if ((int)$loyaltyRedemption['max_points_allowed'] > 0 && $isLoggedIn && $userId > 0) {
                    $selectedLoyaltyPoints = (int)$loyaltyRedemption['max_points_allowed'];
                    checkoutStoreLoyaltySelection($userId, $selectedLoyaltyPoints);
                    $loyaltyRedemption = loyaltyBuildRedemptionPreview(
                        $selectedLoyaltyPoints,
                        $availableLoyaltyBalance,
                        $loyaltyEligibleSubtotal
                    );
                    $loyaltyMessage = 'Loyalty redemption was adjusted to match your current cart.';
                } else {
                    checkoutResetLoyaltySelection();
                    $selectedLoyaltyPoints = 0;
                    $loyaltyRedemption = loyaltyBuildRedemptionPreview(0, $availableLoyaltyBalance, $loyaltyEligibleSubtotal);
                    $loyaltyMessage = 'Loyalty redemption was removed because this order is no longer eligible.';
                }
            }
        }
        $loyaltyDiscount = (float)($loyaltyRedemption['discount_amount'] ?? 0);
        $estimatedEarnedPoints = (($isLoggedIn && $userId > 0) || (!empty($_POST['create_account']) && $_POST['create_account'] === 'yes'))
            ? loyaltyCalculateEarnedPoints(max(0, round($loyaltyEligibleSubtotal - $loyaltyDiscount, 2)))
            : 0;
        if ($selectedLoyaltyPoints > 0 && !isset($formData['loyalty_points'])) {
            $formData['loyalty_points'] = (string)$selectedLoyaltyPoints;
        }
        $displayTotal = max(0, ($cartTotal - $couponDiscount - $loyaltyDiscount) + $displayShippingCost);
    } elseif ($isLoyaltyOnlyPost) {
        $formData['loyalty_points'] = trim((string)($_POST['loyalty_points'] ?? ''));
        if (!$isLoggedIn || $userId <= 0) {
            checkoutResetLoyaltySelection();
            $selectedLoyaltyPoints = 0;
            $loyaltyRedemption = loyaltyBuildRedemptionPreview(0, 0, $loyaltyEligibleSubtotal);
            $loyaltyDiscount = 0.0;
            $errors['loyalty_points'] = 'Please log in to redeem loyalty points.';
        } elseif ($loyaltyAction === 'remove') {
            checkoutResetLoyaltySelection();
            $selectedLoyaltyPoints = 0;
            $loyaltyRedemption = loyaltyBuildRedemptionPreview(0, $availableLoyaltyBalance, $loyaltyEligibleSubtotal);
            $loyaltyDiscount = 0.0;
            $loyaltyMessage = 'Loyalty points removed.';
            $_POST['loyalty_points'] = '';
            $formData['loyalty_points'] = '';
        } else {
            $selectedLoyaltyPoints = checkoutSanitizePositiveInt($_POST['loyalty_points'] ?? 0);
            $formData['loyalty_points'] = $selectedLoyaltyPoints > 0 ? (string)$selectedLoyaltyPoints : '';
            $loyaltyRedemption = loyaltyBuildRedemptionPreview(
                $selectedLoyaltyPoints,
                $availableLoyaltyBalance,
                $loyaltyEligibleSubtotal
            );
            if ($selectedLoyaltyPoints <= 0) {
                checkoutResetLoyaltySelection();
                $loyaltyDiscount = 0.0;
                $errors['loyalty_points'] = 'Enter how many loyalty points you want to redeem.';
            } elseif ($loyaltyRedemption['error'] !== '') {
                checkoutResetLoyaltySelection();
                $loyaltyDiscount = 0.0;
                $errors['loyalty_points'] = (string)$loyaltyRedemption['error'];
            } else {
                checkoutStoreLoyaltySelection($userId, (int)$loyaltyRedemption['points_to_redeem']);
                $selectedLoyaltyPoints = (int)$loyaltyRedemption['points_to_redeem'];
                $loyaltyDiscount = (float)$loyaltyRedemption['discount_amount'];
                $loyaltyMessage = 'Using ' . $selectedLoyaltyPoints . ' points for €' . number_format($loyaltyDiscount, 2) . ' off.';
            }
        }
        $estimatedEarnedPoints = (($isLoggedIn && $userId > 0) || (!empty($_POST['create_account']) && $_POST['create_account'] === 'yes'))
            ? loyaltyCalculateEarnedPoints(max(0, round($loyaltyEligibleSubtotal - $loyaltyDiscount, 2)))
            : 0;
        $displayTotal = max(0, ($cartTotal - $couponDiscount - $loyaltyDiscount) + $displayShippingCost);
    } else {
        $required = [
            'shipping_address' => 'Shipping address',
            'shipping_city' => 'City',
            'shipping_postal_code' => 'Postal code',
            'shipping_country' => 'Country',
            'courier' => 'Courier',
            'fulfillment_mode' => 'Delivery method',
            'payment_method' => 'Payment method',
        ];
        foreach ($required as $field => $label) {
            if (empty($_POST[$field])) $errors[$field] = "$label is required";
        }

        $allowedPaymentMethods = ['stripe', 'paypal'];
        $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
        if ($paymentMethod !== '' && !in_array($paymentMethod, $allowedPaymentMethods, true)) {
            $errors['payment_method'] = 'Selected payment method is not available.';
        }

        $shippingCountry = checkoutNormalizeCountry((string)($_POST['shipping_country'] ?? ''), $availableCountries);
        $_POST['shipping_country'] = $shippingCountry;
        $formData['shipping_country'] = $shippingCountry;

        $shippingSpeed = trim((string)($_POST['shipping_speed'] ?? 'standard'));
        if (!in_array($shippingSpeed, $shippingSpeeds, true)) {
            $errors['shipping_speed'] = 'Please select a valid shipping speed.';
        }

        $fulfillmentMode = trim((string)($_POST['fulfillment_mode'] ?? 'delivery'));
        if (!in_array($fulfillmentMode, $fulfillmentModes, true)) {
            $errors['fulfillment_mode'] = 'Please select delivery or pickup.';
        }

        $courier = trim((string)($_POST['courier'] ?? ''));
        if (!checkoutIsCourierAllowed($shippingCountry, $courier, $countryCouriers)) {
            $errors['courier'] = 'Selected courier is not available for the chosen country.';
        }

        if (!$isLoggedIn) {
            if (empty($_POST['full_name'])) {
                $errors['full_name'] = 'Full name is required';
            } elseif (str_word_count(trim($_POST['full_name'])) < 2) {
                $errors['full_name'] = 'Enter first and last name';
            }
            if (empty($_POST['email'])) {
                $errors['email'] = 'Email is required';
            } elseif (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
            }
            if (empty($_POST['phone'])) {
                $errors['phone'] = 'Phone is required';
            }
        }

        if (!empty($_POST['shipping_postal_code'])) {
            $postal = preg_replace('/[^0-9]/', '', (string)$_POST['shipping_postal_code']);
            $country = $shippingCountry;
            $isPostalValid = false;
            $postalError = 'Postal code is invalid.';

            if ($country === 'Cyprus') {
                $isPostalValid = (bool)preg_match('/^[0-9]{4}$/', $postal);
                $postalError = 'Cyprus postal code must be exactly 4 digits.';
            } elseif ($country === 'Greece') {
                $isPostalValid = (bool)preg_match('/^[0-9]{5}$/', $postal);
                $postalError = 'Greece postal code must be exactly 5 digits.';
            }

            if (!$isPostalValid) {
                $errors['shipping_postal_code'] = $postalError;
            }

            // Keep sanitized numeric value in-memory for re-render and order payload.
            $_POST['shipping_postal_code'] = $postal;
            $formData['shipping_postal_code'] = $postal;
        }

        if (empty($_POST['accept_terms'])) {
            $errors['accept_terms'] = 'You must accept Terms & Conditions';
        }
        if ($selectedCouponCode !== '' && (!$activeCoupon || $couponDiscount <= 0)) {
            $errors['coupon_code'] = 'Coupon code is invalid, expired, or not applicable to your cart.';
        }
        if (!empty($_POST['loyalty_points'])) {
            $selectedLoyaltyPoints = checkoutSanitizePositiveInt($_POST['loyalty_points']);
            $formData['loyalty_points'] = (string)$selectedLoyaltyPoints;
            $loyaltyRedemption = loyaltyBuildRedemptionPreview(
                $selectedLoyaltyPoints,
                $availableLoyaltyBalance,
                $loyaltyEligibleSubtotal
            );
            $loyaltyDiscount = (float)($loyaltyRedemption['discount_amount'] ?? 0);
        }
        if ($selectedLoyaltyPoints > 0) {
            if (!$isLoggedIn || $userId <= 0) {
                $errors['loyalty_points'] = 'Please log in to redeem loyalty points.';
            } elseif ($loyaltyRedemption['error'] !== '') {
                $errors['loyalty_points'] = (string)$loyaltyRedemption['error'];
            }
        }

        if (empty($errors)) {
            try {
                $conn->begin_transaction();

                $shippingCost = checkoutShippingCost(
                    $shippingCountry,
                    $shippingSpeed,
                    (float)$cartTotal,
                    (float)$freeShippingThreshold,
                    $shippingRatesByCountry
                );
                $freeShippingFlag = $shippingCost <= 0 ? 1 : 0;
                $shippingMessage = $freeShippingFlag
                    ? "Free Shipping Applied!"
                    : "Add €" . number_format($shippingDifference, 2) . " more for free delivery!";

                $lockedLoyaltyBalance = ($isLoggedIn && $userId > 0)
                    ? loyaltyGetCurrentBalance($conn, $userId, true)
                    : 0;
                $finalLoyaltyRedemption = loyaltyBuildRedemptionPreview(
                    $selectedLoyaltyPoints,
                    $lockedLoyaltyBalance,
                    $loyaltyEligibleSubtotal
                );
                if ($selectedLoyaltyPoints > 0 && $finalLoyaltyRedemption['error'] !== '') {
                    throw new RuntimeException((string)$finalLoyaltyRedemption['error']);
                }

                $loyaltyDiscount = (float)($finalLoyaltyRedemption['discount_amount'] ?? 0);
                $combinedDiscountTotal = round($couponDiscount + $loyaltyDiscount, 2);
                $loyaltyEarnEligibleAmount = max(0, round($loyaltyEligibleSubtotal - $loyaltyDiscount, 2));
                $earnedPoints = loyaltyCalculateEarnedPoints($loyaltyEarnEligibleAmount);
                $totalAmount = max(0, ($cartTotal - $combinedDiscountTotal) + $shippingCost);

                // Centralized Place Order module call:
                // creates order header, order lines, payment row and shipment summary.
                $placed = placeOrder($conn, [
                    'payment_confirmed' => true,
                    'items' => $cartItems,
                    'user_id' => $userId > 0 ? $userId : null,
                    'is_guest' => $isLoggedIn ? 0 : 1,
                    'email' => $isLoggedIn ? $userEmail : trim((string)($_POST['email'] ?? '')),
                    'customer_name' => $isLoggedIn ? (string)$userFullName : trim((string)($_POST['full_name'] ?? 'Customer')),
                    'order_status' => 'pending',
                    'payment_status' => 'paid',
                    'payment_provider' => trim((string)($_POST['payment_method'] ?? 'manual')),
                    'subtotal' => $cartTotal,
                    'discount_total' => $combinedDiscountTotal,
                    'shipping_cost' => $shippingCost,
                    'total_amount' => $totalAmount,
                    'shipping_address' => trim((string)($_POST['shipping_address'] ?? '')),
                    'shipping_city' => trim((string)($_POST['shipping_city'] ?? '')),
                    'shipping_postal_code' => trim((string)($_POST['shipping_postal_code'] ?? '')),
                    'shipping_country' => $shippingCountry,
                    'shipping_label' => trim((string)($_POST['shipping_label'] ?? '')),
                    'courier' => $courier,
                    'shipping_priority' => $shippingSpeed,
                    'fulfillment_mode' => $fulfillmentMode,
                ]);
                $orderId = (int)$placed['order_id'];
                $orderNumber = (string)$placed['order_number'];

                $accountCreated = false;
                $loyaltyUserId = $isLoggedIn && $userId > 0 ? $userId : 0;
                if (!$isLoggedIn && !empty($_POST['create_account']) && $_POST['create_account'] === 'yes') {
                    $tempPassword = bin2hex(random_bytes(5));
                    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
                    $nameParts = explode(' ', trim($_POST['full_name']), 2);
                    $first = $nameParts[0];
                    $last = $nameParts[1] ?? '';

                            $check = $conn->prepare("SELECT userID FROM users WHERE email = ?");
                    $check->bind_param("s", $_POST['email']);
                    $check->execute();
                    $check->store_result();
                    if ($check->num_rows == 0) {
                        // Keep new-account creation aligned with the current users table constraints.
                        $username = strtolower(preg_replace('/[^a-z0-9]/', '', strstr($_POST['email'], '@', true) ?: 'user')) . rand(100, 999);
                        $fullName = trim($first . ' ' . $last);
                        $insert = $conn->prepare("INSERT INTO users (full_name, email, username, password, phone, role) VALUES (?,?,?,?,?,'user')");
                        $insert->bind_param("sssss", $fullName, $_POST['email'], $username, $hash, $_POST['phone']);
                        if ($insert->execute()) {
                            $newUserId = $insert->insert_id;
                            $upd = $conn->prepare("UPDATE orders SET userID = ?, isGuestFlag = 0 WHERE orderID = ?");
                            $upd->bind_param("ii", $newUserId, $orderId);
                            $upd->execute();
                            $upd->close();
                            $_SESSION['temp_password'] = $tempPassword;
                            $accountCreated = true;
                            $loyaltyUserId = (int)$newUserId;
                        }
                        $insert->close();
                    }
                    $check->close();
                }

                $loyaltyOutcome = loyaltyApplyOrderTransactions(
                    $conn,
                    $loyaltyUserId,
                    $orderId,
                    (int)($finalLoyaltyRedemption['points_to_redeem'] ?? 0),
                    $loyaltyDiscount,
                    $earnedPoints
                );

                $conn->commit();

                // Send customer confirmation email only after DB commit succeeds.
                // If email fails, order is still valid and stored.
                $confirmationEmailTo = $isLoggedIn ? (string)$userEmail : trim((string)($_POST['email'] ?? ''));
                $confirmationName = $isLoggedIn ? (string)$userFullName : trim((string)($_POST['full_name'] ?? 'Customer'));
                $emailResult = sendOrderConfirmationEmail([
                    'to_email' => $confirmationEmailTo,
                    'customer_name' => $confirmationName,
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'total' => $totalAmount,
                    'shipping_cost' => $shippingCost,
                    'shipping_address' => trim((string)($_POST['shipping_address'] ?? '')),
                    'shipping_city' => trim((string)($_POST['shipping_city'] ?? '')),
                    'shipping_postal_code' => trim((string)($_POST['shipping_postal_code'] ?? '')),
                    'shipping_country' => $shippingCountry,
                    'shipping_label' => trim((string)($_POST['shipping_label'] ?? '')),
                    'courier' => $courier,
                    'shipping_priority' => $shippingSpeed,
                    'fulfillment_mode' => $fulfillmentMode,
                    'items' => $cartItems,
                ]);

                unset($_SESSION['cart']);
                unset($_SESSION['cart_coupon_code']);
                checkoutResetLoyaltySelection();

                $_SESSION['checkout_result'] = [
                    'order_id'         => $orderId,
                    'order_number'     => $orderNumber,
                    'total'            => $totalAmount,
                    'shipping_message' => $shippingMessage,
                    'free_shipping'    => $freeShippingEligible,
                    'account_created'  => $accountCreated,
                    'discount_total'   => $combinedDiscountTotal,
                    'coupon_discount'  => $couponDiscount,
                    'loyalty_redeemed_points' => (int)($loyaltyOutcome['redeemed_points'] ?? 0),
                    'loyalty_redeem_discount' => (float)($loyaltyOutcome['redeem_discount'] ?? 0),
                    'loyalty_earned_points' => (int)($loyaltyOutcome['earned_points'] ?? 0),
                    'loyalty_balance_after' => (int)($loyaltyOutcome['balance_after'] ?? 0),
                    'loyalty_account_available' => $loyaltyUserId > 0,
                    'coupon_code'      => $selectedCouponCode,
                    'confirmation_email_to' => $confirmationEmailTo,
                    'confirmation_email_sent' => (bool)($emailResult['sent'] ?? false),
                    'confirmation_email_error' => (string)($emailResult['error'] ?? '')
                ];

                // NOTE: underscore filename is the actual file in this project.
                header('Location: ' . $project . '/modules/checkout_success.php');
                exit;

            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Order failed: ' . $e->getMessage();
                error_log("Checkout error: " . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - <?= htmlspecialchars($system_title) ?></title>
    <link rel="stylesheet" href="../assets/styling/styles.css">
    <link rel="stylesheet" href="../assets/styling/header.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/translations.js') ?>" defer></script>
</head>
<body class="site-page">
<?php
$headerPath = __DIR__ . '/../include/header.php';
if (file_exists($headerPath)) {
    $activePage = 'checkout';
    include $headerPath;
}
?>
<div class="checkout-container">
    <h1 class="checkout-title" data-translate="checkoutTitle">Checkout</h1>
    <?php if ($shippingDifference > 0): ?>
        <div class="free-shipping-notice"><span data-translate="checkoutAdd">Add</span> &euro;<?= number_format($shippingDifference,2) ?> <span data-translate="checkoutMoreForFreeDelivery">more for FREE Delivery!</span></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="checkout-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="checkout-grid">
        <div class="checkout-form">
            <?php if (!$isLoggedIn): ?>
                <div class="guest-notice"><strong data-translate="checkoutGuestCheckout">Guest checkout</strong> - <a href="<?= $project ?>/authentication/login.php" data-translate="checkoutLogin">Login</a> <span data-translate="checkoutForFasterCheckout">for faster checkout.</span></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <?php if (!$isLoggedIn): ?>
                <fieldset>
                    <legend data-translate="checkoutContact">Contact</legend>
                    <div class="form-group">
                        <label><span data-translate="checkoutFullName">Full Name</span> *</label>
                        <input type="text" name="full_name" value="<?= htmlspecialchars($formData['full_name']??'') ?>" class="<?= isset($errors['full_name'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['full_name'])): ?><span class="error"><?= $errors['full_name'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($formData['email']??'') ?>" class="<?= isset($errors['email'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['email'])): ?><span class="error"><?= $errors['email'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label><span data-translate="checkoutPhone">Phone</span> *</label>
                        <input type="tel" name="phone" value="<?= htmlspecialchars($formData['phone']??'') ?>" class="<?= isset($errors['phone'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['phone'])): ?><span class="error"><?= $errors['phone'] ?></span><?php endif; ?>
                    </div>
                </fieldset>
                <?php endif; ?>

                <fieldset>
                    <legend data-translate="checkoutShipping">Shipping</legend>
                    <?php if ($isLoggedIn && $hasDefaultAddressData): ?>
                    <div class="form-group">
                        <label class="option-label">
                            <input type="checkbox" id="use_saved_address" name="use_saved_address" value="1" <?= (($formData['use_saved_address'] ?? '0') === '1') ? 'checked' : '' ?>>
                            Auto-fill with my default address
                        </label>
                        <span class="form-helper">Uses your default address from My Account. You can still edit every field manually.</span>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label><span data-translate="checkoutAddress">Address</span> *</label>
                        <input type="text" id="shipping_address" name="shipping_address" value="<?= htmlspecialchars($formData['shipping_address']??'') ?>" class="<?= isset($errors['shipping_address'])?'error-field':'' ?>" required>
                        <?php if (isset($errors['shipping_address'])): ?><span class="error"><?= $errors['shipping_address'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label><span data-translate="checkoutCity">City</span> *</label>
                            <input type="text" id="shipping_city" name="shipping_city" value="<?= htmlspecialchars($formData['shipping_city']??'') ?>" class="<?= isset($errors['shipping_city'])?'error-field':'' ?>" required>
                            <?php if (isset($errors['shipping_city'])): ?><span class="error"><?= $errors['shipping_city'] ?></span><?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label><span data-translate="checkoutPostalCode">Postal Code</span> *</label>
                            <input
                                type="text"
                                id="shipping_postal_code"
                                name="shipping_postal_code"
                                value="<?= htmlspecialchars($formData['shipping_postal_code']??'') ?>"
                                class="<?= isset($errors['shipping_postal_code'])?'error-field':'' ?>"
                                autocomplete="postal-code"
                                inputmode="numeric"
                                pattern="[0-9]{4,5}"
                                maxlength="5"
                                required
                            >
                            <?php if (isset($errors['shipping_postal_code'])): ?><span class="error"><?= $errors['shipping_postal_code'] ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><span data-translate="checkoutCountry">Country</span> *</label>
                        <select id="shipping_country" name="shipping_country" class="<?= isset($errors['shipping_country'])?'error-field':'' ?>" required>
                            <option value="" data-translate="checkoutSelect">Select</option>
                            <?php foreach ($availableCountries as $countryOption): ?>
                                <option value="<?= htmlspecialchars($countryOption) ?>" <?= ($formData['shipping_country']??'')===$countryOption ? 'selected' : '' ?>><?= htmlspecialchars($countryOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['shipping_country'])): ?><span class="error"><?= $errors['shipping_country'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Label (optional)</label>
                        <input type="text" id="shipping_label" name="shipping_label" value="<?= htmlspecialchars($formData['shipping_label']??'') ?>" placeholder="Home, Office, Gift address...">
                    </div>
                </fieldset>

                <fieldset>
                    <legend data-translate="checkoutShippingMethod">Shipping Method</legend>
                    <div class="form-group">
                        <label>Delivery Option *</label>
                        <div class="form-options">
                            <?php foreach ($shippingModeLabels as $modeKey => $modeLabel): ?>
                                <label class="option-label"><input type="radio" name="fulfillment_mode" value="<?= htmlspecialchars($modeKey) ?>" <?= (($formData['fulfillment_mode'] ?? 'delivery') === $modeKey) ? 'checked' : '' ?>> <?= htmlspecialchars($modeLabel) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <?php if (isset($errors['fulfillment_mode'])): ?><span class="error"><?= $errors['fulfillment_mode'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label><span data-translate="checkoutCourier">Courier</span> *</label>
                        <select id="courier_select" name="courier" class="<?= isset($errors['courier'])?'error-field':'' ?>" required>
                            <option value="" data-translate="checkoutSelect">Select</option>
                            <?php foreach ($countryCourierOptions as $courierCode => $courierName): ?>
                                <option value="<?= htmlspecialchars($courierCode) ?>" <?= ($formData['courier']??'')===$courierCode ? 'selected' : '' ?>><?= htmlspecialchars($courierName) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="form-helper">Couriers update automatically based on the selected country.</span>
                        <?php if (isset($errors['courier'])): ?><span class="error"><?= $errors['courier'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group" id="akis-point-wrapper" style="display:none;">
                        <label>Επιλέξτε Σημείο Παραλαβής Akis Express *</label>
                        <select id="akis_pickup_point" name="akis_pickup_point">
                            <option value="">-- Επιλέξτε Πόλη --</option>
                            <option>Αγία Βαρβάρα (Αντιπρόσωπος)</option>
                            <option>Αγία Νάπα (Αντιπρόσωπος)</option>
                            <option>Αγία Φύλα (Αντιπρόσωπος)</option>
                            <option>Άγιος Αθανάσιος Κέντρο Διαλογής</option>
                            <option>Άγιος Δομέτιος</option>
                            <option>Άγιος Τύχωνας (Αντιπρόσωπος)</option>
                            <option>Αγίου Σύλα Αποθήκη</option>
                            <option>Αθηαίνου (Αντιπρόσωπος)</option>
                            <option>Ακάκι (Αντιπρόσωπος)</option>
                            <option>Αλεθρικό (Αντιπρόσωπος)</option>
                            <option>Ασγάτα (Αντιπρόσωπος)</option>
                            <option>Βαβυλά, Βιομηχανική Περιοχή Ιδαλίου</option>
                            <option>Βιομηχανική Στροβόλου</option>
                            <option>Βρυσούλες (Αντιπρόσωπος)</option>
                            <option>Γερμασόγεια (Αντιπρόσωπος)</option>
                            <option>Γεροσκήπου (Αντιπρόσωπος)</option>
                            <option>Δάλι</option>
                            <option>Δάλι Βιομηχανική περιοχή (EXLOG DALI)</option>
                            <option>Δασάκι Άχνας (Αντιπρόσωπος)</option>
                            <option>Δερύνεια (Αντιπρόσωπος)</option>
                            <option>Δευτερά Staroil (Agent)</option>
                            <option>Δευτερά Talbot (Αντιπρόσωπος)</option>
                            <option>Δρομολαξιά (Αντιπρόσωπος)</option>
                            <option>Δροσιά</option>
                            <option>Επισκοπή (Αντιπρόσωπος)</option>
                            <option>Εργάτες (Αντιπρόσωπος)</option>
                            <option>Ευρύχου (Αντιπρόσωπος)</option>
                            <option>Θερμοπυλών (Αντιπρόσωπος)</option>
                            <option>Θεσσαλονίκης</option>
                            <option>Καλό Χωριό Λάρνακας (Αντιπρόσωπος)</option>
                            <option>Κέννεντυ</option>
                            <option>Κέντρο Διαλογής Αραδίππου</option>
                            <option>Κέντρο Διαλογής Παραλιμνίου</option>
                            <option>Κέντρο Διαλογής Στροβόλου</option>
                            <option>Κινύρας</option>
                            <option>Κίτι (Αντιπρόσωπος)</option>
                            <option>Κλήρου/Μαλούντα (Αντιπρόσωπος)</option>
                            <option>Κοκκινοτριμιθιά (Αντιπρόσωπος)</option>
                            <option>Κολόσσι (Αντιπρόσωπος)</option>
                            <option>Κόρνος (Αντιπρόσωπος)</option>
                            <option>Κυπερούντα (Αντιπρόσωπος)</option>
                            <option>Λακατάμια</option>
                            <option>Λατσιά</option>
                            <option>Λεύκαρα (Αντιπρόσωπος)</option>
                            <option>Λεωφόρος Ελλάδος</option>
                            <option>Λιβάδια (Αντιπρόσωπος)</option>
                            <option>Λιοπέτρι</option>
                            <option>Λυθροδόντας (Αντιπρόσωπος)</option>
                            <option>Μαζωτός (Αντιπρόσωπος)</option>
                            <option>Μακεδονίτισσα</option>
                            <option>Μόλος (Αντιπρόσωπος)</option>
                            <option>Μονή (Αντιπρόσωπος)</option>
                            <option>Νάξου</option>
                            <option>Νεάπολη</option>
                            <option>Ξυλοτύμπου (Αντιπρόσωπος)</option>
                            <option>Ξυλοφάγου (Αντιπρόσωπος)</option>
                            <option>Ομονοίας</option>
                            <option>Ορμήδεια (Αντιπρόσωπος)</option>
                            <option>Οροκλίνη (Αντιπρόσωπος)</option>
                            <option>Παλαιομέτοχο (Αντιπρόσωπος)</option>
                            <option>Παλλουριώτισσα Αποθήκη</option>
                            <option>Πέγια (Αντιπρόσωπος)</option>
                            <option>Πισσούρι (Αντιπρόσωπος)</option>
                            <option>Πλατεία Ελευθερίας</option>
                            <option>Πόλης Χρυσοχούς</option>
                            <option>Πρωταράς (Αντιπρόσωπος)</option>
                            <option>Πύργος Τυλληρίας (Αντιπρόσωπος)</option>
                            <option>Στασίνου Branch</option>
                            <option>Στρόβολος</option>
                            <option>Τάλα (Αντιπρόσωπος)</option>
                            <option>Τεύκρου Άνθια</option>
                            <option>Τσέρι (Αντιπρόσωπος)</option>
                            <option>Ύψωνας</option>
                            <option>Φραγκλίνου Ρούσβελτ</option>
                            <option>Χλώρακα (Αντιπρόσωπος)</option>
                            <option>Χοιροκοιτία (Αντιπρόσωπος)</option>
                            <option>Χρυσοπολίτισσα</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label data-translate="checkoutSpeed">Speed</label>
                        <div class="form-options">
                            <label class="option-label"><input type="radio" name="shipping_speed" value="standard" <?= ($formData['shipping_speed']??'standard')=='standard'?'checked':'' ?>> <span data-translate="checkoutStandard">Standard</span> <span id="standard-cost-label"></span></label>
                            <label class="option-label"><input type="radio" name="shipping_speed" value="express" <?= ($formData['shipping_speed']??'')=='express'?'checked':'' ?>> <span data-translate="checkoutExpress">Express</span> <span id="express-cost-label"></span></label>
                        </div>
                        <?php if (isset($errors['shipping_speed'])): ?><span class="error"><?= $errors['shipping_speed'] ?></span><?php endif; ?>
                    </div>
                </fieldset>

                <fieldset>
                    <legend data-translate="checkoutPayment">Payment</legend>
                    <div class="form-options form-options-column">
                        <label class="option-label"><input type="radio" name="payment_method" value="stripe" <?= ($formData['payment_method']??'stripe')=='stripe'?'checked':'' ?> required> Credit Card (Stripe)</label>
                        <label class="option-label"><input type="radio" name="payment_method" value="paypal" <?= ($formData['payment_method']??'')=='paypal'?'checked':'' ?>> PayPal</label>
                    </div>
                    <?php if (isset($errors['payment_method'])): ?><span class="error"><?= $errors['payment_method'] ?></span><?php endif; ?>
                </fieldset>

                <fieldset>
                    <legend>Coupon</legend>
                    <div class="form-group">
                        <label>Coupon Code</label>
                        <div class="coupon-row">
                            <input type="text" name="coupon_code" value="<?= htmlspecialchars($formData['coupon_code'] ?? '') ?>" placeholder="Enter coupon code (if available)">
                        </div>
                        <div class="coupon-actions">
                            <button type="submit" name="coupon_action" value="apply" class="btn-inline btn-apply" formnovalidate>Apply</button>
                            <button type="submit" name="coupon_action" value="remove" class="btn-inline" formnovalidate>Remove</button>
                        </div>
                        <?php if ($couponMessage !== ''): ?><span class="form-helper"><?= htmlspecialchars($couponMessage) ?></span><?php endif; ?>
                        <?php if (isset($errors['coupon_code'])): ?><span class="error"><?= $errors['coupon_code'] ?></span><?php endif; ?>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Loyalty Program</legend>
                    <?php if ($isLoggedIn && $userId > 0): ?>
                    <div class="form-group">
                        <label>Your loyalty balance</label>
                        <span class="form-helper">
                            <strong><?= number_format($availableLoyaltyBalance) ?> points</strong>
                            worth about €<?= number_format($availableLoyaltyBalance * loyaltyPointValueEuro(), 2) ?>.
                            Earn <?= loyaltyPointsEarnedPerEuro() ?> point per €1 spent and redeem <?= loyaltyPointsRedeemPerEuro() ?> points for every €1.00 off.
                        </span>
                    </div>
                    <div class="form-group">
                        <label>Redeem points</label>
                        <div class="coupon-row">
                            <input type="number" min="0" step="1" name="loyalty_points" value="<?= htmlspecialchars($formData['loyalty_points'] ?? '') ?>" placeholder="Enter points to redeem">
                        </div>
                        <div class="coupon-actions">
                            <button type="submit" name="loyalty_action" value="apply" class="btn-inline btn-apply" formnovalidate>Apply</button>
                            <button type="submit" name="loyalty_action" value="remove" class="btn-inline" formnovalidate>Remove</button>
                        </div>
                        <span class="form-helper">
                            Max usable on this order: <strong><?= number_format((int)($loyaltyRedemption['max_points_allowed'] ?? 0)) ?> points</strong>
                            for up to €<?= number_format(((int)($loyaltyRedemption['max_points_allowed'] ?? 0)) * loyaltyPointValueEuro(), 2) ?> off.
                        </span>
                        <span class="form-helper">
                            Estimated points after this purchase: <strong><?= number_format($estimatedEarnedPoints) ?> points</strong>.
                        </span>
                        <?php if ($loyaltyMessage !== ''): ?><span class="form-helper"><?= htmlspecialchars($loyaltyMessage) ?></span><?php endif; ?>
                        <?php if (isset($errors['loyalty_points'])): ?><span class="error"><?= $errors['loyalty_points'] ?></span><?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="form-group">
                        <label>Loyalty rewards</label>
                        <span class="form-helper">Sign in to redeem points now, or create an account during checkout to start earning points from this order.</span>
                    </div>
                    <?php endif; ?>
                </fieldset>

                <?php if (!$isLoggedIn): ?>
                <fieldset>
                    <legend data-translate="checkoutOptional">Optional</legend>
                    <label class="option-label"><input type="checkbox" name="create_account" value="yes" <?= isset($formData['create_account'])?'checked':'' ?>> <span data-translate="checkoutCreateAccount">Create an account with these details</span></label>
                </fieldset>
                <?php endif; ?>

                <div class="terms-row">
                    <label class="terms-label"><input type="checkbox" name="accept_terms" value="yes" <?= isset($formData['accept_terms'])?'checked':'' ?> class="<?= isset($errors['accept_terms'])?'error-field':'' ?>" required> <span data-translate="checkoutAcceptTermsPrivacy">I accept Terms & Privacy</span></label>
                    <?php if (isset($errors['accept_terms'])): ?><span class="error"><?= $errors['accept_terms'] ?></span><?php endif; ?>
                </div>

                <button type="submit" class="btn-primary"><span data-translate="checkoutPlaceOrder">Place Order</span> &bull; &euro;<span id="placeOrderTotal"><?= number_format($displayTotal,2) ?></span></button>
            </form>
        </div>

        <div>
            <div class="order-summary">
                <h2><span data-translate="checkoutYourOrder">Your Order</span> (<?= $cartCount ?>)</h2>
                <?php foreach ($cartItems as $item): 
                    $name = $item['name'] ?? $item['product']['nameEN'] ?? $item['product']['nameGR'] ?? 'Product';
                    $basePrice = (float)($item['product']['basePrice'] ?? 0);
                    $addonsCost = (float)($item['addons']['addonsCost'] ?? 0);
                    if ($addonsCost <= 0) {
                        if (!empty($item['addons']['giftWrapping'])) $addonsCost += 2.0;
                        if (!empty($item['addons']['giftBagFlag'])) $addonsCost += 1.5;
                    }
                    $price = (float)($item['price'] ?? $item['pricing']['unitTotal'] ?? ($basePrice + $addonsCost));
                    $qty = (int)($item['quantity'] ?? 1);
                    $giftBits = [];
                    if (!empty($item['addons']['giftWrapping'])) $giftBits[] = 'Gift Wrapping (+€2.00)';
                    if (!empty($item['addons']['giftBagFlag'])) $giftBits[] = 'Gift Bag (+€1.50)';
                    if (!empty($item['addons']['giftMessage'])) $giftBits[] = 'Note: ' . (string)$item['addons']['giftMessage'];
                ?>
                <div class="order-item">
                    <div class="order-item-main">
                        <span><?= htmlspecialchars($name) ?> x<?= $qty ?></span>
                        <span>&euro;<?= number_format($price*$qty,2) ?></span>
                    </div>
                    <?php if (!empty($giftBits)): ?>
                    <div class="order-item-addons">
                        <?= htmlspecialchars(implode(' | ', $giftBits)) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <hr class="summary-divider">
                <div class="summary-row"><span data-translate="subtotal">Subtotal</span><span>&euro;<span id="orderSubtotal"><?= number_format($cartTotal,2) ?></span></span></div>
                <div class="summary-row"><span>Coupon Discount</span><span>-&euro;<span id="orderCouponDiscount"><?= number_format($couponDiscount,2) ?></span></span></div>
                <div class="summary-row"><span>Loyalty Discount</span><span>-&euro;<span id="orderLoyaltyDiscount"><?= number_format($loyaltyDiscount,2) ?></span></span></div>
                <div class="summary-row"><span data-translate="shipping">Shipping</span><span id="orderShipping"><?= $freeShippingEligible ? 'FREE' : ('€' . number_format($displayShippingCost,2)) ?></span></div>
                <div class="summary-row summary-row-total"><span data-translate="total">Total</span><span>&euro;<span id="orderTotal"><?= number_format($displayTotal,2) ?></span></span></div>
            </div>

            <div class="courier-map-card" id="courier-map-card">
                <h3 id="courier-map-title">Courier Locations</h3>
                <p id="courier-map-hint">Select a courier to preview pickup spots.</p>
                <div id="courier-map-frame" class="courier-map-frame" aria-label="Courier pickup spot map"></div>
                <div class="courier-map-links" id="courier-map-links"></div>
                <div class="courier-map-points" id="courier-map-points"></div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var freeThreshold = <?= json_encode((float)$freeShippingThreshold) ?>;
    var subtotal = <?= json_encode((float)$cartTotal) ?>;
    var couponDiscount = <?= json_encode((float)$couponDiscount) ?>;
    var loyaltyDiscount = <?= json_encode((float)$loyaltyDiscount) ?>;
    var shippingRatesByCountry = <?= json_encode($shippingRatesByCountry) ?>;
    var countryCouriers = <?= json_encode($countryCouriers) ?>;
    var defaultAddress = <?= json_encode($defaultAddress) ?>;

    var countryBounds = {
        Cyprus: [[34.56, 32.15], [35.75, 34.95]],
        Greece: [[34.76, 19.34], [41.82, 29.89]]
    };

    var cityCenters = {
        Cyprus: {
            nicosia: { lat: 35.1856, lng: 33.3823 },
            lefkosia: { lat: 35.1856, lng: 33.3823 },
            limassol: { lat: 34.6841, lng: 33.0379 },
            lemesos: { lat: 34.6841, lng: 33.0379 },
            larnaca: { lat: 34.9167, lng: 33.6290 },
            paphos: { lat: 34.7754, lng: 32.4257 },
            pafos: { lat: 34.7754, lng: 32.4257 },
            paralimni: { lat: 35.0396, lng: 33.9819 },
            famagusta: { lat: 35.0396, lng: 33.9819 }
        },
        Greece: {
            athens: { lat: 37.9838, lng: 23.7275 },
            athina: { lat: 37.9838, lng: 23.7275 },
            thessaloniki: { lat: 40.6401, lng: 22.9444 },
            patra: { lat: 38.2466, lng: 21.7346 },
            patras: { lat: 38.2466, lng: 21.7346 },
            heraklion: { lat: 35.3387, lng: 25.1442 },
            iraklio: { lat: 35.3387, lng: 25.1442 },
            larissa: { lat: 39.6390, lng: 22.4191 },
            volos: { lat: 39.3610, lng: 22.9420 },
            ioannina: { lat: 39.6650, lng: 20.8537 },
            chania: { lat: 35.5138, lng: 24.0180 }
        }
    };

    var countryDefaults = {
        Cyprus: { lat: 35.1400, lng: 33.3600 },
        Greece: { lat: 38.1200, lng: 23.7200 }
    };

    var mapConfigs = {
        akis_express: {
            title: 'Akis Express Pickup Spots',
            country: 'Cyprus',
            color: '#8a4dd6',
            points: [
                { name: 'Akis Express Latsia Hub', city: 'Nicosia', postal: '2235', country: 'Cyprus', lat: 35.1032, lng: 33.3838 },
                { name: 'Akis Express Limassol Center', city: 'Limassol', postal: '3012', country: 'Cyprus', lat: 34.6841, lng: 33.0379 },
                { name: 'Akis Express Larnaca Drosia', city: 'Larnaca', postal: '6035', country: 'Cyprus', lat: 34.9157, lng: 33.6142 },
                { name: 'Akis Express Paphos Kiniras', city: 'Paphos', postal: '8011', country: 'Cyprus', lat: 34.7736, lng: 32.4260 },
                { name: 'Akis Express Paphos Hellados', city: 'Paphos', postal: '8020', country: 'Cyprus', lat: 34.7664, lng: 32.4215 }
            ],
            links: [
                { href: 'https://akisexpress.com.cy/', label: 'Akis Express Official' }
            ]
        },
        boxnow: {
            title: 'BoxNow Locker Pickup Spots',
            country: 'Cyprus',
            color: '#f58f3d',
            points: [
                { name: 'BoxNow Locker Strovolos', city: 'Nicosia', postal: '2018', country: 'Cyprus', lat: 35.1285, lng: 33.3450 },
                { name: 'BoxNow Locker Mesa Geitonia', city: 'Limassol', postal: '4003', country: 'Cyprus', lat: 34.6935, lng: 33.0547 },
                { name: 'BoxNow Locker Finikoudes', city: 'Larnaca', postal: '6022', country: 'Cyprus', lat: 34.9140, lng: 33.6350 },
                { name: 'BoxNow Locker Paphos Center', city: 'Paphos', postal: '8010', country: 'Cyprus', lat: 34.7748, lng: 32.4245 },
                { name: 'BoxNow Locker Paralimni', city: 'Paralimni', postal: '5290', country: 'Cyprus', lat: 35.0396, lng: 33.9819 }
            ],
            links: [
                { href: 'https://boxnow.cy/en/locker-finder', label: 'BoxNow Cyprus Locker Finder' }
            ]
        },
        acs: {
            title: 'ACS Pickup Spots',
            country: 'Cyprus',
            color: '#2c7be5',
            points: [
                { name: 'ACS Strovolos Branch', city: 'Nicosia', postal: '2018', country: 'Cyprus', lat: 35.1487, lng: 33.3416 },
                { name: 'ACS Agia Zoni Branch', city: 'Limassol', postal: '3031', country: 'Cyprus', lat: 34.6830, lng: 33.0441 },
                { name: 'ACS Aradippou Branch', city: 'Larnaca', postal: '7101', country: 'Cyprus', lat: 34.9498, lng: 33.5911 },
                { name: 'ACS Mesogi Branch', city: 'Paphos', postal: '8280', country: 'Cyprus', lat: 34.8217, lng: 32.4622 },
                { name: 'ACS Chloraka Branch', city: 'Paphos', postal: '8010', country: 'Cyprus', lat: 34.7929, lng: 32.4068 }
            ],
            links: [
                { href: 'https://www.acscourier.net/en/home', label: 'ACS Official' }
            ]
        },
        elta_courier: {
            title: 'ELTA Courier Pickup Spots',
            country: 'Greece',
            color: '#3fa77b',
            points: [
                { name: 'ELTA Athens Central', city: 'Athens', postal: '10557', country: 'Greece', lat: 37.9755, lng: 23.7348 },
                { name: 'ELTA Thessaloniki Center', city: 'Thessaloniki', postal: '54624', country: 'Greece', lat: 40.6380, lng: 22.9444 },
                { name: 'ELTA Patra Center', city: 'Patra', postal: '26221', country: 'Greece', lat: 38.2460, lng: 21.7350 },
                { name: 'ELTA Heraklion Center', city: 'Heraklion', postal: '71202', country: 'Greece', lat: 35.3393, lng: 25.1333 },
                { name: 'ELTA Larissa Center', city: 'Larissa', postal: '41222', country: 'Greece', lat: 39.6390, lng: 22.4191 },
                { name: 'ELTA Ioannina Center', city: 'Ioannina', postal: '45444', country: 'Greece', lat: 39.6651, lng: 20.8520 }
            ],
            links: [
                { href: 'https://www.elta-courier.gr/', label: 'ELTA Courier Official' }
            ]
        },
        speedex: {
            title: 'Speedex Pickup Spots',
            country: 'Greece',
            color: '#d96459',
            points: [
                { name: 'Speedex Athens Hub', city: 'Athens', postal: '10437', country: 'Greece', lat: 37.9860, lng: 23.7207 },
                { name: 'Speedex Thessaloniki Hub', city: 'Thessaloniki', postal: '54627', country: 'Greece', lat: 40.6420, lng: 22.9285 },
                { name: 'Speedex Patra Point', city: 'Patra', postal: '26222', country: 'Greece', lat: 38.2445, lng: 21.7252 },
                { name: 'Speedex Heraklion Point', city: 'Heraklion', postal: '71306', country: 'Greece', lat: 35.3235, lng: 25.1312 },
                { name: 'Speedex Larissa Point', city: 'Larissa', postal: '41334', country: 'Greece', lat: 39.6320, lng: 22.4225 },
                { name: 'Speedex Chania Point', city: 'Chania', postal: '73134', country: 'Greece', lat: 35.5098, lng: 24.0323 }
            ],
            links: [
                { href: 'https://www.speedex.gr/', label: 'Speedex Official' }
            ]
        },
        geniki: {
            title: 'Geniki Taxydromiki Pickup Spots',
            country: 'Greece',
            color: '#5661d9',
            points: [
                { name: 'Geniki Athens Hub', city: 'Athens', postal: '17778', country: 'Greece', lat: 37.9641, lng: 23.6978 },
                { name: 'Geniki Thessaloniki Hub', city: 'Thessaloniki', postal: '54628', country: 'Greece', lat: 40.6507, lng: 22.9346 },
                { name: 'Geniki Patra Point', city: 'Patra', postal: '26223', country: 'Greece', lat: 38.2490, lng: 21.7427 },
                { name: 'Geniki Heraklion Point', city: 'Heraklion', postal: '71307', country: 'Greece', lat: 35.3321, lng: 25.1288 },
                { name: 'Geniki Larissa Point', city: 'Larissa', postal: '41335', country: 'Greece', lat: 39.6375, lng: 22.4140 },
                { name: 'Geniki Ioannina Point', city: 'Ioannina', postal: '45445', country: 'Greece', lat: 39.6630, lng: 20.8453 }
            ],
            links: [
                { href: 'https://www.taxydromiki.com/', label: 'Geniki Taxydromiki Official' }
            ]
        }
    };

    var courierEl = document.getElementById('courier_select');
    var speedEls = document.querySelectorAll('input[name="shipping_speed"]');
    var modeEls = document.querySelectorAll('input[name="fulfillment_mode"]');
    var shippingOut = document.getElementById('orderShipping');
    var totalOut = document.getElementById('orderTotal');
    var btnTotalOut = document.getElementById('placeOrderTotal');
    var standardCostLabelEl = document.getElementById('standard-cost-label');
    var expressCostLabelEl = document.getElementById('express-cost-label');
    var courierMapFrame = document.getElementById('courier-map-frame');
    var courierMapTitle = document.getElementById('courier-map-title');
    var courierMapHint = document.getElementById('courier-map-hint');
    var courierMapLinks = document.getElementById('courier-map-links');
    var courierMapPoints = document.getElementById('courier-map-points');
    var countryEl = document.getElementById('shipping_country');
    var postalEl = document.getElementById('shipping_postal_code');
    var useSavedAddressEl = document.getElementById('use_saved_address');
    var shippingAddressEl = document.getElementById('shipping_address');
    var shippingCityEl = document.getElementById('shipping_city');
    var shippingLabelEl = document.getElementById('shipping_label');
    var map = null;
    var mapLayer = null;

    if (typeof L !== 'undefined' && courierMapFrame) {
        map = L.map(courierMapFrame, {
            zoomControl: true,
            scrollWheelZoom: false
        });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);
        mapLayer = L.layerGroup().addTo(map);
    }

    function normalizeCountry(country) {
        var keys = Object.keys(countryCouriers || {});
        var target = (country || '').trim().toLowerCase();
        for (var i = 0; i < keys.length; i++) {
            if (keys[i].toLowerCase() === target) {
                return keys[i];
            }
        }
        return keys.length ? keys[0] : 'Cyprus';
    }

    function normalizeKey(value) {
        return String(value || '').toLowerCase().replace(/[^a-z0-9]/g, '');
    }

    function selectedCountry() {
        return normalizeCountry(countryEl ? countryEl.value : '');
    }

    function selectedSpeed() {
        var checked = document.querySelector('input[name="shipping_speed"]:checked');
        return checked ? checked.value : 'standard';
    }

    function selectedMode() {
        var checked = document.querySelector('input[name="fulfillment_mode"]:checked');
        return checked ? checked.value : 'delivery';
    }

    function getCountryRates(country) {
        return shippingRatesByCountry[country] || { standard: 0, express: 0 };
    }

    function formatMoney(value) {
        return '\u20AC' + Number(value || 0).toFixed(2);
    }

    function shippingCost(country, speed) {
        if (subtotal >= freeThreshold) {
            return 0;
        }
        var rates = getCountryRates(country);
        return Number(rates[speed] || 0);
    }

    function updateTotals() {
        var country = selectedCountry();
        var speed = selectedSpeed();
        var currentShippingCost = shippingCost(country, speed);
        if (shippingOut) shippingOut.textContent = currentShippingCost === 0 ? 'FREE' : formatMoney(currentShippingCost);
        var total = Math.max(0, subtotal - couponDiscount - loyaltyDiscount + currentShippingCost);
        if (totalOut) totalOut.textContent = total.toFixed(2);
        if (btnTotalOut) btnTotalOut.textContent = total.toFixed(2);
    }

    function updateSpeedLabels() {
        var country = selectedCountry();
        var rates = getCountryRates(country);
        var freeText = '(FREE over \u20AC' + Number(freeThreshold).toFixed(0) + ')';
        if (standardCostLabelEl) {
            standardCostLabelEl.textContent = subtotal >= freeThreshold ? freeText : '(' + formatMoney(rates.standard || 0) + ')';
        }
        if (expressCostLabelEl) {
            expressCostLabelEl.textContent = subtotal >= freeThreshold ? freeText : '(' + formatMoney(rates.express || 0) + ')';
        }
    }

    function refreshCourierOptions() {
        if (!courierEl) {
            return;
        }

        var country = selectedCountry();
        var previous = courierEl.value;
        var options = countryCouriers[country] || {};
        var keys = Object.keys(options);

        courierEl.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select';
        courierEl.appendChild(placeholder);

        keys.forEach(function (code, index) {
            var option = document.createElement('option');
            option.value = code;
            option.textContent = options[code];
            if (code === previous || (!previous && index === 0)) {
                option.selected = true;
            }
            courierEl.appendChild(option);
        });

        if (!courierEl.value && keys.length > 0) {
            courierEl.value = keys[0];
        }
    }

    function getCityCenter(country, cityValue) {
        var lookup = cityCenters[country] || {};
        var key = normalizeKey(cityValue);
        if (key !== '' && lookup[key]) {
            return lookup[key];
        }
        return null;
    }

    function getPostalCenter(country, postalValue) {
        var digits = String(postalValue || '').replace(/\D/g, '');
        if (digits === '') {
            return null;
        }

        if (country === 'Cyprus') {
            var first = Number(digits.charAt(0));
            if (first === 1 || first === 2) return cityCenters.Cyprus.nicosia;
            if (first === 3 || first === 4) return cityCenters.Cyprus.limassol;
            if (first === 8) return cityCenters.Cyprus.paphos;
            if (first === 5 || first === 6 || first === 7) return cityCenters.Cyprus.larnaca;
            return cityCenters.Cyprus.nicosia;
        }

        if (country === 'Greece') {
            var prefix = Number(digits.slice(0, 2));
            if (prefix >= 10 && prefix <= 19) return cityCenters.Greece.athens;
            if (prefix >= 20 && prefix <= 29) return cityCenters.Greece.patras;
            if (prefix >= 30 && prefix <= 39) return cityCenters.Greece.volos;
            if (prefix >= 40 && prefix <= 43) return cityCenters.Greece.larissa;
            if (prefix >= 45 && prefix <= 46) return cityCenters.Greece.ioannina;
            if (prefix >= 54 && prefix <= 57) return cityCenters.Greece.thessaloniki;
            if (prefix >= 70 && prefix <= 72) return cityCenters.Greece.heraklion;
            if (prefix === 73) return cityCenters.Greece.chania;
            if (prefix === 74) return cityCenters.Greece.heraklion;
            return cityCenters.Greece.athens;
        }

        return null;
    }

    function resolveTargetPoint(country) {
        var cityPoint = getCityCenter(country, shippingCityEl ? shippingCityEl.value : '');
        if (cityPoint) {
            return cityPoint;
        }

        var postalPoint = getPostalCenter(country, postalEl ? postalEl.value : '');
        if (postalPoint) {
            return postalPoint;
        }

        return countryDefaults[country] || { lat: 35.14, lng: 33.36 };
    }

    function toRad(deg) {
        return deg * (Math.PI / 180);
    }

    function haversineKm(a, b) {
        var earth = 6371;
        var dLat = toRad(b.lat - a.lat);
        var dLng = toRad(b.lng - a.lng);
        var lat1 = toRad(a.lat);
        var lat2 = toRad(b.lat);
        var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1) * Math.cos(lat2) *
            Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 2 * earth * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    }

    function pointKey(point) {
        return [point.name, point.city, point.postal].join('|');
    }

    function findClosestPoint(points, targetPoint) {
        if (!Array.isArray(points) || points.length === 0) {
            return null;
        }
        if (!targetPoint) {
            return points[0];
        }

        var closest = points[0];
        var shortest = Infinity;
        points.forEach(function (point) {
            var distance = haversineKm(targetPoint, point);
            if (distance < shortest) {
                shortest = distance;
                closest = point;
            }
        });
        return closest;
    }

    function formatPointLabel(point) {
        if (!point) return '';
        return point.name + ' - ' + point.city + ' ' + point.postal;
    }

    function buildCourierConfig() {
        var country = selectedCountry();
        var courier = courierEl ? courierEl.value : '';
        var mode = selectedMode();
        var courierOptions = countryCouriers[country] || {};

        if (!courier || !courierOptions[courier]) {
            courier = Object.keys(courierOptions)[0] || '';
        }

        var base = courier ? mapConfigs[courier] : null;
        if (!base) {
            return {
                title: 'Courier Pickup Spots',
                hint: 'Select a courier to preview pickup spots.',
                color: '#8a4dd6',
                links: [],
                points: [],
                closestPoint: null,
                mode: mode,
                coverage: countryBounds[country] || null
            };
        }

        var points = (base.points || []).filter(function (point) {
            return normalizeCountry(point.country || country) === country;
        });

        var target = resolveTargetPoint(country);
        var closest = findClosestPoint(points, target);
        var mapPoints = points.slice();

        if (mode === 'delivery') {
            mapPoints = closest ? [closest] : (points.length > 0 ? [points[0]] : []);
        }

        return {
            title: base.title,
            hint: mode === 'delivery'
                ? 'Showing the closest pickup point for the selected courier.'
                : 'Showing pickup points for the selected courier.',
            color: base.color,
            links: base.links || [],
            points: mapPoints,
            closestPoint: closest,
            mode: mode,
            coverage: countryBounds[country] || null
        };
    }

    function renderMap(config) {
        if (courierMapTitle) courierMapTitle.textContent = config.title || 'Courier Pickup Spots';
        if (courierMapHint) courierMapHint.textContent = config.hint || '';

        if (courierMapLinks) {
            courierMapLinks.innerHTML = '';
            (config.links || []).forEach(function (link) {
                var a = document.createElement('a');
                a.href = link.href;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.textContent = link.label;
                courierMapLinks.appendChild(a);
            });
        }

        if (courierMapPoints) {
            courierMapPoints.innerHTML = '';

            if (!config.points || config.points.length === 0) {
                var emptyChip = document.createElement('span');
                emptyChip.textContent = 'No pickup points available for this courier.';
                courierMapPoints.appendChild(emptyChip);
            } else if (config.mode === 'delivery' && config.closestPoint) {
                var closestOnly = document.createElement('span');
                closestOnly.classList.add('is-closest');
                closestOnly.textContent = 'Closest: ' + formatPointLabel(config.closestPoint);
                courierMapPoints.appendChild(closestOnly);
            } else {
                var closestKey = config.closestPoint ? pointKey(config.closestPoint) : '';
                (config.points || []).forEach(function (point) {
                    var chip = document.createElement('span');
                    if (closestKey !== '' && pointKey(point) === closestKey) {
                        chip.classList.add('is-closest');
                        chip.textContent = formatPointLabel(point) + ' (Closest)';
                    } else {
                        chip.textContent = formatPointLabel(point);
                    }
                    courierMapPoints.appendChild(chip);
                });
            }
        }

        if (!map || !mapLayer) {
            return;
        }

        mapLayer.clearLayers();
        var bounds = [];

        (config.points || []).forEach(function (point) {
            var marker = L.circleMarker([point.lat, point.lng], {
                radius: 6,
                color: config.color || '#8a4dd6',
                fillColor: '#ffffff',
                fillOpacity: 1,
                weight: 2
            });
            marker.bindPopup('<strong>' + point.name + '</strong><br>' + point.city + ' ' + point.postal + ', ' + point.country);
            marker.addTo(mapLayer);
            bounds.push([point.lat, point.lng]);
        });

        if (bounds.length === 1) {
            map.setView(bounds[0], 11);
        } else if (bounds.length > 1) {
            map.fitBounds(bounds, { padding: [18, 18] });
        } else {
            map.setView([35.15, 33.35], 6);
        }
    }

    function updateCourierMap() {
        var config = buildCourierConfig();
        renderMap(config);
    }

    function getPostalRule(country) {
        if (country === 'Cyprus') {
            return {
                pattern: '[0-9]{4}',
                maxLength: 4,
                error: 'Cyprus postal code must be exactly 4 digits.'
            };
        }

        return {
            pattern: '[0-9]{5}',
            maxLength: 5,
            error: 'Greece postal code must be exactly 5 digits.'
        };
    }

    function sanitizePostalInput() {
        if (!postalEl) return;
        var maxLength = Number(postalEl.maxLength) || 5;
        var digits = postalEl.value.replace(/\D/g, '');
        if (digits.length > maxLength) {
            digits = digits.slice(0, maxLength);
        }
        if (digits !== postalEl.value) {
            postalEl.value = digits;
        }
    }

    function validatePostalCode() {
        if (!postalEl) return true;
        var code = postalEl.value.trim();
        if (code === '') {
            postalEl.setCustomValidity('');
            return true;
        }

        var country = selectedCountry();
        var rule = getPostalRule(country);
        var isValid = new RegExp('^' + rule.pattern + '$').test(code);
        postalEl.setCustomValidity(isValid ? '' : rule.error);
        return isValid;
    }

    function applyPostalRule() {
        if (!postalEl) return;
        var country = selectedCountry();
        var rule = getPostalRule(country);
        postalEl.maxLength = rule.maxLength;
        postalEl.setAttribute('pattern', rule.pattern);
        postalEl.setAttribute('title', rule.error);
        sanitizePostalInput();
        validatePostalCode();
    }

    function applySavedAddress(forceFill) {
        if (!useSavedAddressEl || !useSavedAddressEl.checked) {
            return;
        }
        if (!defaultAddress) {
            return;
        }

        var savedCountry = normalizeCountry(defaultAddress.country || '');
        if (countryEl && (forceFill || !countryEl.value)) {
            countryEl.value = savedCountry;
        }
        if (shippingAddressEl && defaultAddress.address && (forceFill || shippingAddressEl.value.trim() === '')) {
            shippingAddressEl.value = defaultAddress.address;
        }
        if (shippingCityEl && defaultAddress.city && (forceFill || shippingCityEl.value.trim() === '')) {
            shippingCityEl.value = defaultAddress.city;
        }
        if (postalEl && defaultAddress.postal_code && (forceFill || postalEl.value.trim() === '')) {
            postalEl.value = String(defaultAddress.postal_code).replace(/\D/g, '');
        }
        if (shippingLabelEl && defaultAddress.label && (forceFill || shippingLabelEl.value.trim() === '')) {
            shippingLabelEl.value = defaultAddress.label;
        }

        refreshCourierOptions();
        updateSpeedLabels();
        applyPostalRule();
        updateTotals();
        updateCourierMap();
    }

    if (useSavedAddressEl) {
        useSavedAddressEl.addEventListener('change', function () {
            if (useSavedAddressEl.checked) {
                applySavedAddress(true);
            }
        });
    }

    if (countryEl) {
        countryEl.addEventListener('change', function () {
            refreshCourierOptions();
            updateSpeedLabels();
            applyPostalRule();
            updateTotals();
            updateCourierMap();
            toggleAkisPointWrapper();
        });
    }

    var akisPointWrapper = document.getElementById('akis-point-wrapper');

    function toggleAkisPointWrapper() {
        if (!akisPointWrapper) return;
        var isAkis = courierEl && courierEl.value === 'akis_express';
        akisPointWrapper.style.display = isAkis ? '' : 'none';
        var sel = document.getElementById('akis_pickup_point');
        if (sel) sel.required = isAkis;
    }

    if (courierEl) {
        courierEl.addEventListener('change', function () {
            updateTotals();
            updateCourierMap();
            toggleAkisPointWrapper();
        });
    }

    speedEls.forEach(function (el) {
        el.addEventListener('change', function () {
            updateTotals();
            updateCourierMap();
        });
    });

    modeEls.forEach(function (el) {
        el.addEventListener('change', updateCourierMap);
    });

    if (postalEl) {
        postalEl.addEventListener('input', function () {
            sanitizePostalInput();
            validatePostalCode();
            updateCourierMap();
        });
        postalEl.addEventListener('blur', function () {
            validatePostalCode();
            updateCourierMap();
        });
    }

    if (shippingCityEl) {
        shippingCityEl.addEventListener('input', updateCourierMap);
        shippingCityEl.addEventListener('blur', updateCourierMap);
    }

    refreshCourierOptions();
    updateSpeedLabels();
    applyPostalRule();
    updateTotals();
    toggleAkisPointWrapper();
    applySavedAddress(false);
    updateCourierMap();
})();
</script>
<?php
$footerPath = __DIR__ . '/../include/footer.php';
if (file_exists($footerPath)) {
    include $footerPath;
} else {
    echo "</body></html>";
}
?>



