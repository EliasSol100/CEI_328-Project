<?php
/**
 * Stock Management Module
 * 
 * Handles stock deduction after order completion, threshold checks,
 * admin notifications, and audit logging.
 * 
 * @package CreationsByAthina
 */

// Prevent direct access if needed
if (!defined('INCLUDE_CHECK') && !defined('STOCK_MANAGEMENT_DIRECT')) {
    die('Direct access not permitted');
}

/**
 * Deduct stock for all items in a confirmed order.
 * 
 * This function should be called **within an active transaction**
 * to ensure atomicity with order creation.
 *
 * @param int      $orderId The ID of the order (orderID column)
 * @param mysqli   $conn    Active database connection (already in transaction)
 * @throws Exception If any error occurs (rollback handled by caller)
 */
function deductStockAfterOrderCompletion($orderId, $conn) {
    // 1. Verify order exists and status allows stock deduction
    $stmt = $conn->prepare("SELECT status FROM orders WHERE orderID = ?");
    if (!$stmt) {
        throw new Exception("Failed to prepare order check: " . $conn->error);
    }
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception("Order #$orderId not found.");
    }
    $order = $result->fetch_assoc();
    $allowedStatuses = ['confirmed', 'paid', 'processing']; // adjust as needed
    if (!in_array($order['status'], $allowedStatuses)) {
        throw new Exception("Order #$orderId status '{$order['status']}' not eligible for stock deduction.");
    }
    $stmt->close();

    // 2. Fetch all order items
    $stmt = $conn->prepare("SELECT productID, variationID, quantity FROM order_items WHERE orderID = ?");
    if (!$stmt) {
        throw new Exception("Failed to prepare order items fetch: " . $conn->error);
    }
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $items = $stmt->get_result();
    $stmt->close();

    if ($items->num_rows === 0) {
        throw new Exception("No items found for order #$orderId.");
    }

    // 3. Process each item
    while ($item = $items->fetch_assoc()) {
        $productId   = (int)$item['productID'];
        $variationId = (int)$item['variationID'];
        $qtyOrdered  = (int)$item['quantity'];

        // Skip if variation_id is null (maybe product without variations)
        if (!$variationId) {
            continue; // or handle differently if you have a separate product stock
        }

        // Lock the stock row to prevent race conditions
        $stockStmt = $conn->prepare("SELECT quantityAvailable FROM variation_stock WHERE variationID = ? FOR UPDATE");
        if (!$stockStmt) {
            throw new Exception("Failed to prepare stock select: " . $conn->error);
        }
        $stockStmt->bind_param("i", $variationId);
        $stockStmt->execute();
        $stockRes = $stockStmt->get_result();
        if ($stockRes->num_rows === 0) {
            // If no stock record exists, create one with 0 stock? Or throw?
            // Better to throw because product should have stock record.
            throw new Exception("Stock record not found for variation ID: $variationId");
        }
        $stockRow = $stockRes->fetch_assoc();
        $currentStock = (int)$stockRow['quantityAvailable'];
        $newStock = $currentStock - $qtyOrdered;

        if ($newStock < 0) {
            throw new Exception("Insufficient stock for variation ID: $variationId (ordered: $qtyOrdered, available: $currentStock)");
        }

        // Update stock quantity
        $updateStmt = $conn->prepare("UPDATE variation_stock SET quantityAvailable = ? WHERE variationID = ?");
        if (!$updateStmt) {
            throw new Exception("Failed to prepare stock update: " . $conn->error);
        }
        $updateStmt->bind_param("ii", $newStock, $variationId);
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update stock for variation ID: $variationId");
        }
        $updateStmt->close();

        // Log the stock change for audit
        logStockChange($conn, $orderId, $productId, $variationId, $qtyOrdered, $currentStock, $newStock);

        // Check stock thresholds and trigger notifications/status updates
        checkStockThreshold($conn, $productId, $variationId, $newStock);
    }

    // Optionally mark order as stock_deducted (if column exists)
    // $markStmt = $conn->prepare("UPDATE orders SET stock_deducted = 1 WHERE orderID = ?");
    // if ($markStmt) {
    //     $markStmt->bind_param("i", $orderId);
    //     $markStmt->execute();
    //     $markStmt->close();
    // }
}

/**
 * Check stock level against threshold and update product status.
 * 
 * @param mysqli $conn
 * @param int    $productId
 * @param int    $variationId
 * @param int    $newStock
 * @throws Exception
 */
function checkStockThreshold($conn, $productId, $variationId, $newStock) {
    // Get threshold from variation_stock (lowStockThreshold) or default 5
    $threshold = 5;
    $productName = "Product #$productId";

    // Try to get product name from products table
    $prodStmt = $conn->prepare("SELECT nameEN FROM products WHERE productID = ?");
    if ($prodStmt) {
        $prodStmt->bind_param("i", $productId);
        $prodStmt->execute();
        $prodRes = $prodStmt->get_result();
        if ($prodRow = $prodRes->fetch_assoc()) {
            $productName = $prodRow['nameEN'] ?? $productName;
        }
        $prodStmt->close();
    }

    // Get threshold from variation_stock
    $threshStmt = $conn->prepare("SELECT lowStockThreshold FROM variation_stock WHERE variationID = ?");
    if ($threshStmt) {
        $threshStmt->bind_param("i", $variationId);
        $threshStmt->execute();
        $threshRes = $threshStmt->get_result();
        if ($threshRow = $threshRes->fetch_assoc()) {
            $threshold = (int)($threshRow['lowStockThreshold'] ?? $threshold);
        }
        $threshStmt->close();
    }

    // Determine status (optional – you may not have a stock_status column)
    $status = 'available';
    if ($newStock <= 0) {
        $status = 'out_of_stock';
    } elseif ($newStock <= $threshold) {
        $status = 'low_stock';
    }

    // Update stock_status in variation_stock if column exists
    // If not, you can ignore or add the column.
    // $updateStatus = $conn->prepare("UPDATE variation_stock SET stock_status = ? WHERE variationID = ?");
    // if ($updateStatus) {
    //     $updateStatus->bind_param("si", $status, $variationId);
    //     $updateStatus->execute();
    //     $updateStatus->close();
    // }

    // If out of stock, you may want to disable the variation (if is_active column exists)
    if ($status === 'out_of_stock') {
        // Check if is_active column exists in product_variations
        // $disableStmt = $conn->prepare("UPDATE product_variations SET is_active = 0 WHERE variationID = ?");
        // if ($disableStmt) {
        //     $disableStmt->bind_param("i", $variationId);
        //     $disableStmt->execute();
        //     $disableStmt->close();
        // }

        // Notify admin about out of stock
        createNotification($conn, "Product '$productName' (variation ID: $variationId) is now OUT OF STOCK.");
    } elseif ($status === 'low_stock') {
        createNotification($conn, "Product '$productName' (variation ID: $variationId) is LOW ON STOCK ($newStock left).");
    }
}

/**
 * Create an admin notification.
 * 
 * @param mysqli $conn
 * @param string $message
 */
function createNotification($conn, $message) {
    // Check if admin_notifications table exists; if not, create it.
    $tableCheck = $conn->query("SHOW TABLES LIKE 'admin_notifications'");
    if ($tableCheck->num_rows === 0) {
        $createTable = "CREATE TABLE IF NOT EXISTS admin_notifications (
            notification_id INT PRIMARY KEY AUTO_INCREMENT,
            message TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $conn->query($createTable);
    }

    $stmt = $conn->prepare("INSERT INTO admin_notifications (message, created_at, is_read) VALUES (?, NOW(), 0)");
    if ($stmt) {
        $stmt->bind_param("s", $message);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log("Failed to create notification: " . $conn->error);
    }
}

/**
 * Log stock change for audit trail.
 * 
 * @param mysqli $conn
 * @param int    $orderId
 * @param int    $productId
 * @param int    $variationId
 * @param int    $quantityChange
 * @param int    $oldStock
 * @param int    $newStock
 */
function logStockChange($conn, $orderId, $productId, $variationId, $quantityChange, $oldStock, $newStock) {
    $actionType = 'stock_deduction';
    $entityType = 'order';
    $entityID = $orderId;
    $detailsJSON = json_encode([
        'product_id'   => $productId,
        'variation_id' => $variationId,
        'quantity'     => $quantityChange,
        'old_stock'    => $oldStock,
        'new_stock'    => $newStock
    ]);

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $stmt = $conn->prepare("INSERT INTO audit_logs (userID, actionType, entityType, entityID, detailsJSON, ipAddress, timestamp) VALUES (NULL, ?, ?, ?, ?, ?, NOW())");
    if ($stmt) {
        $stmt->bind_param("ssiss", $actionType, $entityType, $entityID, $detailsJSON, $ipAddress);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log("Failed to log stock change: " . $conn->error);
    }
}
?>