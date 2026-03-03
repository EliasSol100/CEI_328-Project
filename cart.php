<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/authentication/database.php'; // <-- path to your mysqli $conn

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = readRequestPayload();

    $productId = toInt($payload['product_id'] ?? null);
    $qty       = toInt($payload['quantity'] ?? null);

    if ($productId === null || $productId <= 0) badRequest('Invalid product_id.');
    if ($qty === null || $qty <= 0) badRequest('Invalid quantity. Must be >= 1.');
    if ($qty > 99) badRequest('Quantity too large (max 99).');

    // Variation: best is to send variation_id
    $variationInput = is_array($payload['variation'] ?? null) ? $payload['variation'] : [];
    $variationId = toInt($variationInput['variation_id'] ?? ($payload['variation_id'] ?? null));

    // Or match by fields
    $size     = trim((string)($variationInput['size'] ?? ''));
    $yarnType = trim((string)($variationInput['yarn_type'] ?? ''));
    $colorId  = toInt($variationInput['color_id'] ?? null);

    // Gift add-ons (no prices yet -> cost=0)
    $addons = normalizeAddons($payload['addons'] ?? []);

    // 1) Fetch product from DB
    $product = fetchProduct($conn, $productId);
    if ($product === null) notFound('Product not found.');

    $cartStatus  = (string)$product['cartStatus'];   // active / made_to_order / ...
    $hasVariants = ((int)$product['hasVariants'] === 1);

    // If product not allowed in cart
    if ($cartStatus !== 'active' && $cartStatus !== 'made_to_order') {
        badRequest('Product is not available for cart.');
    }

    // 2) Resolve variation (only if hasVariants=1)
    $variation = null;
    if ($hasVariants) {
        if ($variationId !== null && $variationId > 0) {
            $variation = fetchVariationById($conn, $variationId, $productId);
        } else {
            if ($colorId === null || $colorId <= 0) {
                badRequest('Missing variation: send variation_id OR send (color_id + size + yarn_type).');
            }
            $variation = fetchVariationByFields($conn, $productId, $size, $yarnType, $colorId);
        }

        if ($variation === null) {
            badRequest('Selected variation not found for this product.');
        }
    }

    // 3) Stock / availability check
    // If made_to_order, allow adding regardless of inventory (common behavior).
    // If you want strict stock even for made_to_order, remove the if-block below.
    if ($cartStatus === 'made_to_order') {
        $availableStock = PHP_INT_MAX;
    } else {
        if ($hasVariants) {
            $availableStock = fetchVariationStock($conn, (int)$variation['variationID']);
        } else {
            $availableStock = (int)$product['inventory'];
        }

        if ($availableStock <= 0) {
            badRequest('Out of stock.');
        }
    }

    // 4) Update session cart
    $cart = &getOrInitCart();

    $existingIndex = findExistingLineIndex(
        $cart['items'],
        $productId,
        $hasVariants ? (int)$variation['variationID'] : null,
        $addons
    );

    $newQty = $qty;
    if ($existingIndex !== null) {
        $newQty = (int)$cart['items'][$existingIndex]['quantity'] + $qty;
    }

    if ($newQty > $availableStock) {
        badRequest('Not enough stock for requested quantity.');
    }

    // Gift add-ons cost: no prices yet
    $addonsCost = calculateAddonsCost($addons); // 0.00 with TODO

    $unitPrice = (float)$product['basePrice'];
    $unitTotal = $unitPrice + $addonsCost;
    $lineTotal = $unitTotal * $newQty;

    $lineItem = [
        'product' => [
            'id'         => (int)$product['productID'],
            'sku'        => (string)$product['sku'],
            'nameGR'     => (string)$product['nameGR'],
            'nameEN'     => (string)$product['nameEN'],
            'basePrice'  => round($unitPrice, 2),
            'cartStatus' => $cartStatus,
            'hasVariants'=> $hasVariants,
        ],
        'variation' => $hasVariants ? [
            'variationID' => (int)$variation['variationID'],
            'size'        => (string)$variation['size'],
            'yarnType'    => (string)$variation['yarnType'],
            'colorID'     => (int)$variation['colorID'],
            'colorName'   => (string)($variation['colorName'] ?? ''),
        ] : null,
        'quantity' => $newQty,
        'addons' => [
            'giftWrapping' => $addons['gift_wrapping'],
            'giftBagFlag'  => $addons['gift_bag'],
            'giftMessage'  => $addons['message'],
            'addonsCost'   => round($addonsCost, 2),
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

    echo json_encode([
        'success' => true,
        'message' => 'Item added to cart.',
        'cart'    => $cart,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error.',
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================
   Helpers
   ========================= */

function readRequestPayload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '[]', true);
        return is_array($data) ? $data : [];
    }
    return $_POST ?? [];
}

function toInt($v): ?int
{
    if ($v === null || $v === '') return null;
    if (is_int($v)) return $v;
    if (is_numeric($v)) return (int)$v;
    return null;
}

function badRequest(string $msg): void
{
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function notFound(string $msg): void
{
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizeAddons($a): array
{
    $a = is_array($a) ? $a : [];

    $giftWrapping = filter_var($a['gift_wrapping'] ?? $a['giftWrapping'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $giftBag      = filter_var($a['gift_bag'] ?? $a['giftBagFlag'] ?? $a['giftBag'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $message = trim((string)($a['message'] ?? $a['giftMessage'] ?? ''));
    if (mb_strlen($message) > 255) $message = mb_substr($message, 0, 255);

    return [
        'gift_wrapping' => (bool)$giftWrapping,
        'gift_bag'      => (bool)$giftBag,
        'message'       => $message,
    ];
}

function &getOrInitCart(): array
{
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
    return $_SESSION['cart'];
}

function findExistingLineIndex(array $items, int $productId, ?int $variationId, array $addons): ?int
{
    foreach ($items as $i => $item) {
        if ((int)($item['product']['id'] ?? 0) !== $productId) continue;

        $existingVarId = $item['variation']['variationID'] ?? null;
        if (($variationId ?? null) !== ($existingVarId ?? null)) continue;

        $ad = $item['addons'] ?? [];
        if ((bool)($ad['giftWrapping'] ?? false) !== (bool)$addons['gift_wrapping']) continue;
        if ((bool)($ad['giftBagFlag'] ?? false) !== (bool)$addons['gift_bag']) continue;
        if ((string)($ad['giftMessage'] ?? '') !== (string)$addons['message']) continue;

        return (int)$i;
    }
    return null;
}

function calculateAddonsCost(array $addons): float
{
    // Δεν έχετε τιμές ακόμα -> 0.00
    // TODO: αποφασίστε τιμές (π.χ. στο system_config ή σε νέο table gift_addons).
    return 0.0;
}

function recalcCartTotals(array $items): array
{
    $itemsCount = 0;
    $subtotal = 0.0;
    $addonsTotal = 0.0;

    foreach ($items as $item) {
        $q = (int)($item['quantity'] ?? 0);
        $itemsCount += $q;

        $unitProductPrice = (float)($item['product']['basePrice'] ?? 0.0);
        $unitAddonsCost   = (float)($item['addons']['addonsCost'] ?? 0.0);

        $subtotal    += $unitProductPrice * $q;
        $addonsTotal += $unitAddonsCost * $q;
    }

    return [
        'items_count'  => $itemsCount,
        'subtotal'     => round($subtotal, 2),
        'addons_total' => round($addonsTotal, 2),
        'grand_total'  => round($subtotal + $addonsTotal, 2),
    ];
}

/* =========================
   DB (MySQLi) queries
   ========================= */

function fetchProduct(mysqli $conn, int $productId): ?array
{
    $sql = "SELECT productID, sku, nameGR, nameEN, inventory, basePrice, cartStatus, hasVariants
            FROM products
            WHERE productID = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: " . $conn->error);

    $st->bind_param("i", $productId);
    $st->execute();
    $res = $st->get_result();
    $row = $res->fetch_assoc();
    $st->close();

    return $row ?: null;
}

function fetchVariationById(mysqli $conn, int $variationId, int $productId): ?array
{
    $sql = "SELECT pv.variationID, pv.productID, pv.size, pv.yarnType, pv.colorID, c.colorName
            FROM product_variations pv
            LEFT JOIN colors c ON c.colorID = pv.colorID
            WHERE pv.variationID = ? AND pv.productID = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: " . $conn->error);

    $st->bind_param("ii", $variationId, $productId);
    $st->execute();
    $res = $st->get_result();
    $row = $res->fetch_assoc();
    $st->close();

    return $row ?: null;
}

function fetchVariationByFields(mysqli $conn, int $productId, string $size, string $yarnType, int $colorId): ?array
{
    $sql = "SELECT pv.variationID, pv.productID, pv.size, pv.yarnType, pv.colorID, c.colorName
            FROM product_variations pv
            LEFT JOIN colors c ON c.colorID = pv.colorID
            WHERE pv.productID = ?
              AND pv.size = ?
              AND pv.yarnType = ?
              AND pv.colorID = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: " . $conn->error);

    $st->bind_param("issi", $productId, $size, $yarnType, $colorId);
    $st->execute();
    $res = $st->get_result();
    $row = $res->fetch_assoc();
    $st->close();

    return $row ?: null;
}

function fetchVariationStock(mysqli $conn, int $variationId): int
{
    $sql = "SELECT quantityAvailable
            FROM variation_stock
            WHERE variationID = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: " . $conn->error);

    $st->bind_param("i", $variationId);
    $st->execute();
    $res = $st->get_result();
    $row = $res->fetch_assoc();
    $st->close();

    return $row ? (int)$row['quantityAvailable'] : 0;
}