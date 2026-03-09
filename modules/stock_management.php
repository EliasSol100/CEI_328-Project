<?php
/**
 * Stock Management Module
 *
 * Handles stock deduction after order placement and triggers threshold checks.
 *
 * @package CreationsByAthina
 */

// Prevent direct access if needed.
if (!defined('INCLUDE_CHECK') && !defined('STOCK_MANAGEMENT_DIRECT')) {
    die('Direct access not permitted');
}

// Reuse threshold logic module (3.2.2.6).
require_once __DIR__ . '/stock_threshold.php';

/**
 * Deduct stock for all items in an order and run threshold checks.
 *
 * IMPORTANT:
 * - Must be called inside an active DB transaction.
 * - Uses current project schema: orders/order_items/variation_stock/products.
 *
 * @param int $orderId
 * @param mysqli $conn
 * @throws Exception
 */
function deductStockAfterOrderCompletion(int $orderId, mysqli $conn): void {
    // 1) Verify order exists and status is eligible for stock handling.
    $orderStmt = $conn->prepare("SELECT status FROM orders WHERE orderID = ?");
    if (!$orderStmt) {
        throw new Exception("Failed to prepare order check: " . $conn->error);
    }
    $orderStmt->bind_param("i", $orderId);
    $orderStmt->execute();
    $orderRes = $orderStmt->get_result();
    if (!$orderRes || $orderRes->num_rows === 0) {
        $orderStmt->close();
        throw new Exception("Order #$orderId not found.");
    }
    $order = $orderRes->fetch_assoc();
    $orderStmt->close();

    $allowedStatuses = ['accepted', 'in_production', 'shipped', 'completed'];
    if (!in_array((string)$order['status'], $allowedStatuses, true)) {
        return;
    }

    // 2) Read order lines.
    $itemsStmt = $conn->prepare("
        SELECT productID, variationID, quantity
        FROM order_items
        WHERE orderID = ?
    ");
    if (!$itemsStmt) {
        throw new Exception("Failed to prepare order items query: " . $conn->error);
    }
    $itemsStmt->bind_param("i", $orderId);
    $itemsStmt->execute();
    $itemsRes = $itemsStmt->get_result();
    $itemsStmt->close();

    if (!$itemsRes || $itemsRes->num_rows === 0) {
        throw new Exception("No order items found for order #$orderId.");
    }

    // 3) Deduct per line.
    while ($item = $itemsRes->fetch_assoc()) {
        $productId = (int)$item['productID'];
        $variationId = isset($item['variationID']) ? (int)$item['variationID'] : 0;
        $qtyOrdered = max(1, (int)$item['quantity']);

        if ($variationId > 0) {
            handleVariantStockDeduction($conn, $orderId, $productId, $variationId, $qtyOrdered);
        } else {
            handleProductStockDeduction($conn, $orderId, $productId, $qtyOrdered);
        }
    }
}

/**
 * Deduct stock for a specific variation and trigger threshold checks.
 *
 * @param mysqli $conn
 * @param int $orderId
 * @param int $productId
 * @param int $variationId
 * @param int $qtyOrdered
 * @throws Exception
 */
function handleVariantStockDeduction(mysqli $conn, int $orderId, int $productId, int $variationId, int $qtyOrdered): void {
    // Lock variation stock row to avoid race conditions.
    $stockStmt = $conn->prepare("
        SELECT quantityAvailable, lowStockThreshold
        FROM variation_stock
        WHERE variationID = ?
        FOR UPDATE
    ");
    if (!$stockStmt) {
        throw new Exception("Failed to prepare variation stock query: " . $conn->error);
    }
    $stockStmt->bind_param("i", $variationId);
    $stockStmt->execute();
    $stockRes = $stockStmt->get_result();
    if (!$stockRes || $stockRes->num_rows === 0) {
        $stockStmt->close();
        throw new Exception("Stock record not found for variation ID: $variationId");
    }

    $stockRow = $stockRes->fetch_assoc();
    $stockStmt->close();

    $oldStock = (int)$stockRow['quantityAvailable'];
    $threshold = (int)$stockRow['lowStockThreshold'];
    $newStock = $oldStock - $qtyOrdered;

    if ($newStock < 0) {
        throw new Exception("Insufficient stock for variation ID: $variationId (ordered: $qtyOrdered, available: $oldStock)");
    }

    $updStmt = $conn->prepare("UPDATE variation_stock SET quantityAvailable = ? WHERE variationID = ?");
    if (!$updStmt) {
        throw new Exception("Failed to prepare variation stock update: " . $conn->error);
    }
    $updStmt->bind_param("ii", $newStock, $variationId);
    if (!$updStmt->execute()) {
        $updStmt->close();
        throw new Exception("Failed to update variation stock for variation ID: $variationId");
    }
    $updStmt->close();

    logStockChange($conn, $orderId, $productId, $variationId, $qtyOrdered, $oldStock, $newStock);

    // Trigger 3.2.2.6 function (stock_threshold module).
    checkStockThreshold($conn, $variationId, $newStock, $threshold);
}

/**
 * Deduct stock from products.inventory when item has no variation.
 *
 * @param mysqli $conn
 * @param int $orderId
 * @param int $productId
 * @param int $qtyOrdered
 * @throws Exception
 */
function handleProductStockDeduction(mysqli $conn, int $orderId, int $productId, int $qtyOrdered): void {
    $pStmt = $conn->prepare("
        SELECT inventory, cartStatus
        FROM products
        WHERE productID = ?
        FOR UPDATE
    ");
    if (!$pStmt) {
        throw new Exception("Failed to prepare product stock query: " . $conn->error);
    }
    $pStmt->bind_param("i", $productId);
    $pStmt->execute();
    $pRes = $pStmt->get_result();
    if (!$pRes || $pRes->num_rows === 0) {
        $pStmt->close();
        throw new Exception("Product #$productId not found during stock deduction.");
    }
    $pRow = $pRes->fetch_assoc();
    $pStmt->close();

    $cartStatus = (string)$pRow['cartStatus'];
    if ($cartStatus === 'made_to_order') {
        // Made-to-order products do not use inventory deduction.
        return;
    }

    $oldStock = (int)$pRow['inventory'];
    $newStock = $oldStock - $qtyOrdered;
    if ($newStock < 0) {
        throw new Exception("Insufficient stock for product ID: $productId (ordered: $qtyOrdered, available: $oldStock)");
    }

    $upd = $conn->prepare("UPDATE products SET inventory = ? WHERE productID = ?");
    if (!$upd) {
        throw new Exception("Failed to prepare product stock update: " . $conn->error);
    }
    $upd->bind_param("ii", $newStock, $productId);
    if (!$upd->execute()) {
        $upd->close();
        throw new Exception("Failed to update product stock for product ID: $productId");
    }
    $upd->close();

    logStockChange($conn, $orderId, $productId, null, $qtyOrdered, $oldStock, $newStock);
}

/**
 * Log stock deduction event in audit_logs using the current project schema.
 *
 * @param mysqli $conn
 * @param int $orderId
 * @param int $productId
 * @param int|null $variationId
 * @param int $quantityChange
 * @param int $oldStock
 * @param int $newStock
 */
function logStockChange(
    mysqli $conn,
    int $orderId,
    int $productId,
    ?int $variationId,
    int $quantityChange,
    int $oldStock,
    int $newStock
): void {
    $details = json_encode([
        'order_id' => $orderId,
        'product_id' => $productId,
        'variation_id' => $variationId,
        'quantity' => $quantityChange,
        'old_stock' => $oldStock,
        'new_stock' => $newStock
    ]);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $entityType = $variationId ? 'variation' : 'product';
    $entityId = $variationId ?: $productId;

    $stmt = $conn->prepare("
        INSERT INTO audit_logs (userID, role, actionType, entityType, entityID, ipAddress, detailsJSON)
        VALUES (NULL, 'system', 'stock_deducted', ?, ?, ?, ?)
    ");
    if ($stmt) {
        $stmt->bind_param("siss", $entityType, $entityId, $ip, $details);
        $stmt->execute();
        $stmt->close();
    }
}

