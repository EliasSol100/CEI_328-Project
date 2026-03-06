<?php
declare(strict_types=1);

session_start();
require_once "authentication/database.php";
header('Content-Type: application/json; charset=utf-8');

/* =========================
   GET: Return cart  OR  variations for a product
   ========================= */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {

    // GET ?action=variations&product_id=N
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

    // Default: return cart
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

/* =========================
   POST: Add item to cart
   ========================= */
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET or POST.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection ($conn) not found. Check authentication/database.php');
    }

    $payload = readRequestPayload();

    $productId = toInt($payload['product_id'] ?? null);
    $qty       = toInt($payload['quantity'] ?? null);

    if ($productId === null || $productId <= 0) badRequest('Invalid product_id.');
    if ($qty === null || $qty <= 0) badRequest('Invalid quantity. Must be >= 1.');

    $variationInput = is_array($payload['variation'] ?? null) ? $payload['variation'] : [];
    $variationId = toInt($variationInput['variation_id'] ?? ($payload['variation_id'] ?? null));
    $size        = trim((string)($variationInput['size'] ?? ''));
    $yarnType    = trim((string)($variationInput['yarn_type'] ?? ''));
    $colorId     = toInt($variationInput['color_id'] ?? null);

    $addons = normalizeAddons($payload['addons'] ?? []);

    $product = fetchProduct($conn, $productId);
    if ($product === null) notFound('Product not found.');

    $cartStatus  = (string)$product['cartStatus'];
    $hasVariants = ((int)$product['hasVariants'] === 1);

    if ($cartStatus !== 'active' && $cartStatus !== 'made_to_order') {
        badRequest('Product is not available for cart.');
    }

    $variation = null;
    if ($hasVariants) {
        if ($variationId !== null && $variationId > 0) {
            $variation = fetchVariationById($conn, $variationId, $productId);
        } elseif ($colorId !== null && $colorId > 0) {
            $variation = fetchVariationByFields($conn, $productId, $size, $yarnType, $colorId);
        } else {
            // No variation specified — pick the first available one automatically
            $variation = fetchFirstVariation($conn, $productId);
        }
        if ($variation === null) badRequest('Selected variation not found for this product.');
    }

    if ($cartStatus === 'made_to_order') {
        $availableStock = PHP_INT_MAX;
    } else {
        $availableStock = $hasVariants
            ? fetchVariationStock($conn, (int)$variation['variationID'])
            : (int)$product['inventory'];

        if ($availableStock <= 0) badRequest('Out of stock.');
    }

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
    if ($newQty > $availableStock) badRequest('Not enough stock for requested quantity.');

    $addonsCost = 0.0; // TODO: prices later

    $unitPrice = (float)$product['basePrice'];
    $unitTotal = $unitPrice + $addonsCost;
    $lineTotal = $unitTotal * $newQty;

    $lineItem = [
        'product' => [
            'id' => (int)$product['productID'],
            'sku' => (string)$product['sku'],
            'nameGR' => (string)$product['nameGR'],
            'nameEN' => (string)$product['nameEN'],
            'basePrice' => round($unitPrice, 2),
            'cartStatus' => $cartStatus,
            'hasVariants' => $hasVariants,
        ],
        'variation' => $hasVariants ? [
            'variationID' => (int)$variation['variationID'],
            'size' => (string)$variation['size'],
            'yarnType' => (string)$variation['yarnType'],
            'colorID' => (int)$variation['colorID'],
            'colorName' => (string)($variation['colorName'] ?? ''),
        ] : null,
        'quantity' => $newQty,
        'addons' => [
            'giftWrapping' => $addons['gift_wrapping'],
            'giftBagFlag' => $addons['gift_bag'],
            'giftMessage' => $addons['message'],
            'addonsCost' => 0.0,
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

    echo json_encode(['success' => true, 'cart' => $cart], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== Helpers ===== */

function readRequestPayload(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '[]', true);
        return is_array($data) ? $data : [];
    }
    return $_POST ?? [];
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
function findExistingLineIndex(array $items, int $productId, ?int $variationId, array $addons): ?int {
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

/* ===== DB (mysqli) ===== */
function fetchProduct(mysqli $conn, int $productId): ?array {
    $sql = "SELECT productID, sku, nameGR, nameEN, inventory, basePrice, cartStatus, hasVariants
            FROM products WHERE productID = ? LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: ".$conn->error);
    $st->bind_param("i", $productId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}
function fetchVariationById(mysqli $conn, int $variationId, int $productId): ?array {
    $sql = "SELECT pv.variationID, pv.productID, pv.size, pv.yarnType, pv.colorID, c.colorName
            FROM product_variations pv
            LEFT JOIN colors c ON c.colorID = pv.colorID
            WHERE pv.variationID = ? AND pv.productID = ? LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: ".$conn->error);
    $st->bind_param("ii", $variationId, $productId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}
function fetchVariationByFields(mysqli $conn, int $productId, string $size, string $yarnType, int $colorId): ?array {
    $sql = "SELECT pv.variationID, pv.productID, pv.size, pv.yarnType, pv.colorID, c.colorName
            FROM product_variations pv
            LEFT JOIN colors c ON c.colorID = pv.colorID
            WHERE pv.productID=? AND pv.size=? AND pv.yarnType=? AND pv.colorID=? LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: ".$conn->error);
    $st->bind_param("issi", $productId, $size, $yarnType, $colorId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}
function fetchFirstVariation(mysqli $conn, int $productId): ?array {
    $sql = "SELECT pv.variationID, pv.productID, pv.size, pv.yarnType, pv.colorID, c.colorName
            FROM product_variations pv
            LEFT JOIN colors c ON c.colorID = pv.colorID
            WHERE pv.productID = ?
            ORDER BY pv.variationID ASC LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: ".$conn->error);
    $st->bind_param("i", $productId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}
function fetchAllVariations(mysqli $conn, int $productId): array {
    $sql = "SELECT pv.variationID, pv.size, pv.yarnType, pv.colorID, c.colorName,
                   COALESCE(vs.quantityAvailable, 0) AS stock
            FROM product_variations pv
            LEFT JOIN colors c ON c.colorID = pv.colorID
            LEFT JOIN variation_stock vs ON vs.variationID = pv.variationID
            WHERE pv.productID = ?
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
            'colorID'     => (int)$row['colorID'],
            'colorName'   => (string)($row['colorName'] ?? ''),
            'stock'       => (int)$row['stock'],
        ];
    }
    $st->close();
    return $rows;
}
function fetchVariationStock(mysqli $conn, int $variationId): int {
    $sql = "SELECT quantityAvailable FROM variation_stock WHERE variationID = ? LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException("SQL prepare failed: ".$conn->error);
    $st->bind_param("i", $variationId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ? (int)$row['quantityAvailable'] : 0;
}