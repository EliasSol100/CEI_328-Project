<?php
declare(strict_types=1);

session_start();
require_once "authentication/database.php";
require_once "include/security.php";
require_once __DIR__ . '/include/product_option_helpers.php';
require_once __DIR__ . '/include/coupon_helpers.php';
require_once __DIR__ . '/include/made_to_order_access.php';
header('Content-Type: application/json; charset=utf-8');

app_product_options_ensure_schema($conn);
app_coupon_ensure_schema($conn);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {

    if (($_GET['action'] ?? '') === 'variations') {
        $pid = (int)($_GET['product_id'] ?? 0);
        if ($pid <= 0 || !isset($conn) || !($conn instanceof mysqli)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }
        echo json_encode(['success' => true, 'variations' => fetchAllVariations($conn, $pid)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [
            'items' => [],
            'totals' => [
                'items_count'  => 0,
                'subtotal'     => 0.0,
                'addons_total' => 0.0,
                'grand_total'  => 0.0,
            ],
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
    }
    echo json_encode(['success' => true, 'cart' => $_SESSION['cart']], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET or POST.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    app_require_csrf(true, 'Invalid CSRF token.');

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection ($conn) not found. Check authentication/database.php');
    }
    ensureMadeToOrderProductSchema($conn);

    $payload = readRequestPayload();

    $action = strtolower(trim((string)($payload['action'] ?? '')));
    if ($action === 'set_coupon') {
        $couponCode = app_coupon_normalize_code((string)($payload['coupon_code'] ?? ''));
        if ($couponCode === '') {
            badRequest('Enter a coupon code.');
        }
        $cart = &getOrInitCart();
        $evaluation = app_coupon_evaluate_cart($conn, $cart['items'] ?? [], $couponCode);
        if (empty($evaluation['valid'])) {
            badRequest((string)($evaluation['message'] ?? 'Coupon code is invalid, expired, or not applicable to your cart.'));
        }
        $_SESSION['cart_coupon_code'] = $couponCode;
        echo json_encode([
            'success' => true,
            'message' => (string)$evaluation['message'],
            'coupon_code' => $couponCode,
            'discount_amount' => (float)$evaluation['discount_amount'],
            'cart' => $cart,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'remove_coupon' || $action === 'clear_coupon') {
        unset($_SESSION['cart_coupon_code']);
        $cart = &getOrInitCart();
        echo json_encode([
            'success' => true,
            'message' => 'Coupon removed.',
            'coupon_code' => '',
            'cart' => $cart,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $productId = toInt($payload['product_id'] ?? null);
    $qty       = toInt($payload['quantity'] ?? null);
    if ($productId === null || $productId <= 0) badRequest('Invalid product_id.');
    if ($qty === null || $qty <= 0) badRequest('Invalid quantity. Must be >= 1.');

    $variationInput = is_array($payload['variation'] ?? null) ? $payload['variation'] : [];
    $variationId = toInt($variationInput['variation_id'] ?? ($payload['variation_id'] ?? null));
    $size        = trim((string)($variationInput['size'] ?? ''));
    $yarnType    = trim((string)($variationInput['yarn_type'] ?? ''));
    $colorId     = toInt($variationInput['color_id'] ?? null);
    $customizationInput = is_array($payload['customization'] ?? null) ? $payload['customization'] : [];
    $selectedInformationalSize = app_product_size_label_normalize((string)($customizationInput['selectedSize'] ?? $payload['selected_size'] ?? ''));
    $payloadCustomizationNote = mb_substr(trim((string)($payload['customizationNote'] ?? '')), 0, 255);
    if ($payloadCustomizationNote === '' && $selectedInformationalSize !== '') {
        $payloadCustomizationNote = mb_substr('Size: ' . $selectedInformationalSize, 0, 255);
    }

    $addons = normalizeAddons($payload['addons'] ?? []);

    $product = fetchProduct($conn, $productId);
    if ($product === null) notFound('Product not found.');

    $cartStatus  = (string)$product['cartStatus'];
    $hasVariants = ((int)$product['hasVariants'] === 1);
    $customColorFields = (int)($product['customColorFields'] ?? 0);
    $hasStoredVariations = $hasVariants ? productHasVariationRows($conn, $productId) : false;

    if ($cartStatus !== 'active' && $cartStatus !== 'made_to_order' && $cartStatus !== 'low_stock') {
        badRequest('Product is not available for cart.');
    }
    if ($cartStatus === 'made_to_order' && !isMadeToOrderProductAccessible($conn, $productId)) {
        badRequest('This made-to-order product is private to another customer.');
    }

    $variation = null;
    $customVariation = null;
    $effectiveHasVariants = $hasVariants && $hasStoredVariations;
    $selectedSizePrice = null;
    if (!$effectiveHasVariants) {
        $sizePriceMap = app_product_size_prices_for_product($conn, $productId);
        if (!empty($sizePriceMap)) {
            if ($selectedInformationalSize === '') {
                badRequest('Please choose a size to continue.');
            }
            $availableSizes = app_product_available_sizes_from_string((string)($product['availableSizes'] ?? ''));
            if (!empty($availableSizes)) {
                $selectedKey = normalizeVariationValue($selectedInformationalSize);
                $availableKeys = array_map('normalizeVariationValue', $availableSizes);
                if (!in_array($selectedKey, $availableKeys, true)) {
                    badRequest('Please choose a valid size.');
                }
            }
            $selectedSizePrice = app_product_size_price_for_product_size($conn, $productId, $selectedInformationalSize);
            if ($selectedSizePrice === null) {
                badRequest('Please choose a valid size.');
            }
        }
    }
    if ($effectiveHasVariants) {
        if ($variationId !== null && $variationId > 0) {
            $variation = fetchVariationById($conn, $variationId, $productId);
        } elseif ($colorId !== null && $colorId > 0) {
            $variation = fetchVariationByFields($conn, $productId, $size, $yarnType, $colorId);
            if ($variation === null && $size !== '') {
                $variation = fetchVariationByColorAndSize($conn, $productId, $size, $colorId);
            }
        } elseif ($size !== '') {
            $variation = fetchVariationBySize($conn, $productId, $size);
        }

        if ($variation === null && ($size !== '' || $yarnType !== '' || ($colorId !== null && $colorId > 0))) {
            $customVariation = [
                'variationID' => null,
                'size' => $size,
                'yarnType' => $yarnType,
                'colorID' => (int)($colorId ?? 0),
                'colorName' => ($colorId !== null && $colorId > 0) ? fetchColorName($conn, (int)$colorId) : '',
            ];
        }
    }

    $customization = normalizeCustomization($customizationInput, $product);
    if ($customColorFields > 0) {
        if ($customization['field1'] === '') {
            badRequest('Please enter your custom colour request.');
        }
        if ($customColorFields > 1 && $customization['field2'] === '') {
            badRequest('Please complete all custom colour fields.');
        }
    }

    if ($cartStatus === 'made_to_order') {
        $availableStock = PHP_INT_MAX;
    } else {
        if ($effectiveHasVariants) {
            $resolvedVariation = $variation ?? $customVariation;
            $resolvedVariationId = (int)($resolvedVariation['variationID'] ?? 0);
            if ($resolvedVariationId <= 0) {
                badRequest('Please select a valid size and color.');
            }
            $availableStock = fetchVariationStock($conn, $resolvedVariationId, (int)$product['inventory']);
        } else {
            $availableStock = (int)$product['inventory'];
        }

        if ($availableStock <= 0) badRequest('Out of stock.');
    }

    $cart = &getOrInitCart();

    $existingIndex = findExistingLineIndex(
        $cart['items'],
        $productId,
        $effectiveHasVariants ? ($variation ?? $customVariation) : null,
        $customization,
        $addons,
        $payloadCustomizationNote
    );

    $newQty = $qty;
    if ($existingIndex !== null) {
        $newQty = (int)$cart['items'][$existingIndex]['quantity'] + $qty;
    }
    if ($newQty > $availableStock) {
        badRequest('Only ' . $availableStock . ' left in stock.');
    }

    $addonsCost = 0.0;
    if (!empty($addons['gift_wrapping'])) $addonsCost += 2.0;
    if (!empty($addons['gift_bag'])) $addonsCost += 1.5;

    $resolvedVariation = $variation ?? $customVariation;
    $unitPrice = $selectedSizePrice !== null
        ? (float)$selectedSizePrice
        : (float)($resolvedVariation['price'] ?? $product['basePrice'] ?? 0);
    $unitTotal = $unitPrice + $addonsCost;
    $lineTotal = $unitTotal * $newQty;

    $customizationSummary = app_product_options_build_customization_summary($product, $customization);
    if ($payloadCustomizationNote !== '') {
        $customizationSummary = $customizationSummary !== ''
            ? $payloadCustomizationNote . ' | ' . $customizationSummary
            : $payloadCustomizationNote;
    } elseif ($selectedInformationalSize !== '') {
        $customizationSummary = $customizationSummary !== ''
            ? 'Size: ' . $selectedInformationalSize . ' | ' . $customizationSummary
            : 'Size: ' . $selectedInformationalSize;
    }
    $lineItem = [
        'product' => [
            'id' => (int)$product['productID'],
            'sku' => (string)$product['sku'],
            'nameGR' => (string)$product['nameGR'],
            'nameEN' => (string)$product['nameEN'],
            'basePrice' => round($unitPrice, 2),
            'cartStatus' => $cartStatus,
            'hasVariants' => $effectiveHasVariants,
        ],
        'variation' => $effectiveHasVariants ? [
            'variationID' => isset($resolvedVariation['variationID']) ? toInt($resolvedVariation['variationID']) : null,
            'size' => trim((string)($resolvedVariation['size'] ?? '')),
            'yarnType' => trim((string)($resolvedVariation['yarnType'] ?? '')),
            'colorID' => toInt($resolvedVariation['colorID'] ?? null),
            'colorName' => (string)($resolvedVariation['colorName'] ?? ''),
            'price' => round((float)($resolvedVariation['price'] ?? $unitPrice), 2),
        ] : null,
        'quantity' => $newQty,
        'price' => round($unitPrice, 2),
        'addons' => [
            'giftWrapping' => $addons['gift_wrapping'],
            'giftBagFlag' => $addons['gift_bag'],
            'giftMessage' => $addons['message'],
            'addonsCost' => round($addonsCost, 2),
        ],
        'customizationNote' => $payloadCustomizationNote,
        'customization' => [
            'field1'       => $customization['field1'],
            'field2'       => $customization['field2'],
            'selectedSize' => $selectedInformationalSize,
            'label1'       => $customization['label1'],
            'label2'       => $customization['label2'],
            'colorSchemeA' => $customization['colorSchemeA'],
            'colorSchemeB' => $customization['colorSchemeB'],
            'colorSchemeC' => $customization['colorSchemeC'],
            'summary'      => $customizationSummary,
        ],
        'pricing' => [
            'unitTotal' => round($unitTotal, 2),
            'lineTotal' => round($lineTotal, 2),
        ],
        'updated_at' => gmdate('c'),
    ];

    if ($existingIndex === null) $cart['items'][] = $lineItem;
    else $cart['items'][$existingIndex] = $lineItem;

    $cart['totals'] = recalcCartTotals($cart['items']);
    $cart['updated_at'] = gmdate('c');

    $notice = null;
    if ($cartStatus !== 'made_to_order' && $availableStock <= 3) {
        $notice = 'Low stock: only ' . $availableStock . ' left.';
    }

    echo json_encode(['success' => true, 'cart' => $cart, 'notice' => $notice], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

function readRequestPayload(): array {
    if (!empty($_POST) && is_array($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if (is_string($raw)) {
        $trimmed = trim($raw);
        if ($trimmed !== '') {
            $data = json_decode($trimmed, true);
            if (is_array($data)) {
                return $data;
            }

            parse_str($trimmed, $parsed);
            if (is_array($parsed) && !empty($parsed)) {
                return $parsed;
            }
        }
    }

    return [];
}
function toInt($v): ?int {
    if ($v === null || $v === '') return null;
    if (is_int($v)) return $v;
    if (is_numeric($v)) return (int)$v;
    return null;
}
function badRequest(string $msg): void {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function notFound(string $msg): void {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function normalizeAddons($a): array {
    $a = is_array($a) ? $a : [];
    $giftWrapping = filter_var($a['gift_wrapping'] ?? $a['giftWrapping'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $giftBag      = filter_var($a['gift_bag'] ?? $a['giftBagFlag'] ?? $a['giftBag'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $message = trim((string)($a['message'] ?? $a['giftMessage'] ?? ''));
    if (mb_strlen($message) > 255) $message = mb_substr($message, 0, 255);
    return ['gift_wrapping'=>(bool)$giftWrapping,'gift_bag'=>(bool)$giftBag,'message'=>$message];
}
function normalizeCouponCode(string $code): string {
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
    return (string)$code;
}
function normalizeCustomization($input, array $product): array {
    $input = is_array($input) ? $input : [];
    $field1 = trim((string)($input['field1'] ?? ''));
    $field2 = trim((string)($input['field2'] ?? ''));
    if (mb_strlen($field1) > 120) $field1 = mb_substr($field1, 0, 120);
    if (mb_strlen($field2) > 120) $field2 = mb_substr($field2, 0, 120);

    $csA = app_product_options_pick_color_scheme_value($input, 'A');
    $csB = app_product_options_pick_color_scheme_value($input, 'B');
    $csC = app_product_options_pick_color_scheme_value($input, 'C');
    if (mb_strlen($csA) > 120) $csA = mb_substr($csA, 0, 120);
    if (mb_strlen($csB) > 120) $csB = mb_substr($csB, 0, 120);
    if (mb_strlen($csC) > 120) $csC = mb_substr($csC, 0, 120);

    return [
        'field1'        => $field1,
        'field2'        => $field2,
        'label1'        => trim((string)($product['customColorLabel1'] ?? '')),
        'label2'        => trim((string)($product['customColorLabel2'] ?? '')),
        'colorSchemeA'  => $csA,
        'colorSchemeB'  => $csB,
        'colorSchemeC'  => $csC,
    ];
}
function &getOrInitCart(): array {
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [
            'items' => [],
            'totals' => ['items_count'=>0,'subtotal'=>0.0,'addons_total'=>0.0,'grand_total'=>0.0],
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
    }
    return $_SESSION['cart'];
}
function findExistingLineIndex(array $items, int $productId, ?array $variation, array $customization, array $addons, string $customizationNote = ''): ?int {
    $targetCsA = app_product_options_pick_color_scheme_value($customization, 'A');
    $targetCsB = app_product_options_pick_color_scheme_value($customization, 'B');
    $targetCsC = app_product_options_pick_color_scheme_value($customization, 'C');

    foreach ($items as $i => $item) {
        if ((int)($item['product']['id'] ?? 0) !== $productId) continue;

        $existingVariation = is_array($item['variation'] ?? null) ? $item['variation'] : null;
        $targetHasVariation = is_array($variation);
        if ($targetHasVariation !== ($existingVariation !== null)) continue;

        if ($targetHasVariation && $existingVariation !== null) {
            $targetVarId = toInt($variation['variationID'] ?? null);
            $existingVarId = toInt($existingVariation['variationID'] ?? null);

            if ($targetVarId !== null || $existingVarId !== null) {
                if ($targetVarId !== $existingVarId) continue;
            } else {
                $targetSize  = normalizeVariationValue((string)($variation['size'] ?? ''));
                $targetYarn  = normalizeVariationValue((string)($variation['yarnType'] ?? ''));
                $targetColor = toInt($variation['colorID'] ?? null) ?? 0;

                $existingSize  = normalizeVariationValue((string)($existingVariation['size'] ?? ''));
                $existingYarn  = normalizeVariationValue((string)($existingVariation['yarnType'] ?? ''));
                $existingColor = toInt($existingVariation['colorID'] ?? null) ?? 0;

                if ($targetSize !== $existingSize) continue;
                if ($targetYarn !== $existingYarn) continue;
                if ($targetColor !== $existingColor) continue;
            }
        }

        $existingCustomization = is_array($item['customization'] ?? null) ? $item['customization'] : [];
        if ((string)($existingCustomization['field1'] ?? '') !== (string)($customization['field1'] ?? '')) continue;
        if ((string)($existingCustomization['field2'] ?? '') !== (string)($customization['field2'] ?? '')) continue;
        if (app_product_options_pick_color_scheme_value($existingCustomization, 'A') !== $targetCsA) continue;
        if (app_product_options_pick_color_scheme_value($existingCustomization, 'B') !== $targetCsB) continue;
        if (app_product_options_pick_color_scheme_value($existingCustomization, 'C') !== $targetCsC) continue;

        $ad = $item['addons'] ?? [];
        if ((bool)($ad['giftWrapping'] ?? false) !== (bool)$addons['gift_wrapping']) continue;
        if ((bool)($ad['giftBagFlag'] ?? false) !== (bool)$addons['gift_bag']) continue;
        if ((string)($ad['giftMessage'] ?? '') !== (string)$addons['message']) continue;
        if ((string)($item['customizationNote'] ?? '') !== $customizationNote) continue;
        return (int)$i;
    }
    return null;
}
function normalizeVariationValue(string $value): string {
    return mb_strtolower(trim($value));
}
function recalcCartTotals(array $items): array {
    $itemsCount=0; $subtotal=0.0; $addonsTotal=0.0;
    foreach ($items as $item) {
        $q = (int)($item['quantity'] ?? 0);
        $itemsCount += $q;
        $unitProductPrice = (float)($item['product']['basePrice'] ?? 0.0);
        $unitAddonsCost   = (float)($item['addons']['addonsCost'] ?? 0.0);
        $subtotal += $unitProductPrice * $q;
        $addonsTotal += $unitAddonsCost * $q;
    }
    return [
        'items_count'=>$itemsCount,
        'subtotal'=>round($subtotal,2),
        'addons_total'=>round($addonsTotal,2),
        'grand_total'=>round($subtotal+$addonsTotal,2),
    ];
}

function fetchProduct(mysqli $conn, int $productId): ?array {
    $sql = "SELECT productID, sku, nameGR, nameEN, inventory, basePrice, cartStatus, hasVariants,
                   availableSizes, customColorFields, customColorLabel1, customColorLabel2
            FROM products WHERE productID = ? LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: ".$conn->error);
    $st->bind_param("i", $productId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}
function productHasVariationRows(mysqli $conn, int $productId): bool {
    $sql = "SELECT 1 FROM product_variations WHERE productID = ? LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: ".$conn->error);
    $st->bind_param("i", $productId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (bool)$row;
}
function fetchVariationById(mysqli $conn, int $variationId, int $productId): ?array {
    $colorDisplaySql = app_color_display_sql('c');
    $sql = "SELECT pv.variationID, pv.productID, pv.size, pv.yarnType, pv.colorID, pv.price, {$colorDisplaySql} AS colorName
            FROM product_variations pv
            LEFT JOIN colors c ON c.colorID = pv.colorID
            LEFT JOIN product_color_availability pca ON pca.productID = pv.productID AND pca.colorID = pv.colorID
            WHERE pv.variationID = ? AND pv.productID = ?
              AND (pv.colorID IS NULL OR (c.isActive = 1 AND COALESCE(pca.isAvailable, 1) = 1)) LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: ".$conn->error);
    $st->bind_param("ii", $variationId, $productId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}
function fetchVariationByFields(mysqli $conn, int $productId, string $size, string $yarnType, int $colorId): ?array {
    $colorDisplaySql = app_color_display_sql('c');
    $sql = "SELECT pv.variationID, pv.productID, pv.size, pv.yarnType, pv.colorID, pv.price, {$colorDisplaySql} AS colorName
            FROM product_variations pv
            LEFT JOIN colors c ON c.colorID = pv.colorID
            LEFT JOIN product_color_availability pca ON pca.productID = pv.productID AND pca.colorID = pv.colorID
            WHERE pv.productID=? AND pv.size=? AND pv.yarnType=? AND pv.colorID=?
              AND (pv.colorID IS NULL OR (c.isActive = 1 AND COALESCE(pca.isAvailable, 1) = 1)) LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: ".$conn->error);
    $st->bind_param("issi", $productId, $size, $yarnType, $colorId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}
function fetchVariationByColorAndSize(mysqli $conn, int $productId, string $size, int $colorId): ?array {
    $colorDisplaySql = app_color_display_sql('c');
    $sql = "SELECT pv.variationID, pv.productID, pv.size, pv.yarnType, pv.colorID, pv.price, {$colorDisplaySql} AS colorName
            FROM product_variations pv
            LEFT JOIN colors c ON c.colorID = pv.colorID
            LEFT JOIN product_color_availability pca ON pca.productID = pv.productID AND pca.colorID = pv.colorID
            WHERE pv.productID=? AND pv.size=? AND pv.colorID=?
              AND (pv.colorID IS NULL OR (c.isActive = 1 AND COALESCE(pca.isAvailable, 1) = 1))
            ORDER BY pv.variationID ASC LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: ".$conn->error);
    $st->bind_param("isi", $productId, $size, $colorId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}
function fetchVariationBySize(mysqli $conn, int $productId, string $size): ?array {
    $colorDisplaySql = app_color_display_sql('c');
    $sql = "SELECT pv.variationID, pv.productID, pv.size, pv.yarnType, pv.colorID, pv.price, {$colorDisplaySql} AS colorName
            FROM product_variations pv
            LEFT JOIN colors c ON c.colorID = pv.colorID
            LEFT JOIN product_color_availability pca ON pca.productID = pv.productID AND pca.colorID = pv.colorID
            WHERE pv.productID=? AND pv.size=?
              AND (pv.colorID IS NULL OR (c.isActive = 1 AND COALESCE(pca.isAvailable, 1) = 1))
            ORDER BY pv.variationID ASC LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: ".$conn->error);
    $st->bind_param("is", $productId, $size);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}
function fetchColorName(mysqli $conn, int $colorId): string {
    $colorDisplaySql = app_color_display_sql('c');
    $sql = "SELECT {$colorDisplaySql} AS colorName FROM colors c WHERE colorID = ? LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) return '';
    $st->bind_param("i", $colorId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return trim((string)($row['colorName'] ?? ''));
}
function fetchAllVariations(mysqli $conn, int $productId): array {
    $colorDisplaySql = app_color_display_sql('c');
    $sql = "SELECT pv.variationID, pv.size, pv.yarnType, pv.colorID, pv.price, {$colorDisplaySql} AS colorName,
                   COALESCE(vs.quantityAvailable, p.inventory, 0) AS stock
            FROM product_variations pv
            LEFT JOIN colors c ON c.colorID = pv.colorID
            LEFT JOIN product_color_availability pca ON pca.productID = pv.productID AND pca.colorID = pv.colorID
            LEFT JOIN variation_stock vs ON vs.variationID = pv.variationID
            JOIN products p ON p.productID = pv.productID
            WHERE pv.productID = ?
              AND (pv.colorID IS NULL OR (c.isActive = 1 AND COALESCE(pca.isAvailable, 1) = 1))
            ORDER BY pv.variationID ASC";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: " . $conn->error);
    $st->bind_param("i", $productId);
    $st->execute();
    $res  = $st->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'variationID' => (int)$row['variationID'],
            'size'        => (string)$row['size'],
            'yarnType'    => (string)$row['yarnType'],
            'colorID'     => toInt($row['colorID'] ?? null),
            'colorName'   => (string)($row['colorName'] ?? ''),
            'price'       => isset($row['price']) ? (float)$row['price'] : null,
            'stock'       => (int)$row['stock'],
        ];
    }
    $st->close();
    return $rows;
}
function fetchVariationStock(mysqli $conn, int $variationId, int $productInventoryFallback = 0): int {
    $sql = "SELECT quantityAvailable FROM variation_stock WHERE variationID = ? LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: ".$conn->error);
    $st->bind_param("i", $variationId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row && array_key_exists('quantityAvailable', $row)) {
        return max(0, (int)$row['quantityAvailable']);
    }
    return max(0, $productInventoryFallback);
}
