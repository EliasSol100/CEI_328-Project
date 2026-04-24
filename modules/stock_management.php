<?php

if (!defined('INCLUDE_CHECK') && !defined('STOCK_MANAGEMENT_DIRECT')) {
    die('Direct access not permitted');
}

function deductStockAfterOrderCompletion($orderId, $conn) {

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
    $allowedStatuses = ['pending', 'confirmed', 'paid', 'processing', 'accepted'];
    if (!in_array($order['status'], $allowedStatuses)) {
        throw new Exception("Order #$orderId status '{$order['status']}' not eligible for stock deduction.");
    }
    $stmt->close();

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

    while ($item = $items->fetch_assoc()) {
        $productId   = (int)$item['productID'];
        $variationId = (int)$item['variationID'];
        $qtyOrdered  = (int)$item['quantity'];

        if (!$variationId) {
            $pStmt = $conn->prepare("SELECT inventory, cartStatus FROM products WHERE productID = ? FOR UPDATE");
            if (!$pStmt) {
                throw new Exception("Failed to prepare product stock select: " . $conn->error);
            }
            $pStmt->bind_param("i", $productId);
            $pStmt->execute();
            $pRes = $pStmt->get_result();
            $pRow = $pRes ? $pRes->fetch_assoc() : null;
            $pStmt->close();

            if (!$pRow) {
                throw new Exception("Product not found for product ID: $productId");
            }

            if ((string)($pRow['cartStatus'] ?? '') === 'made_to_order') {
                continue;
            }

            $oldStock = (int)$pRow['inventory'];
            $newStock = $oldStock - $qtyOrdered;
            if ($newStock < 0) {
                throw new Exception("Insufficient stock for product ID: $productId (ordered: $qtyOrdered, available: $oldStock)");
            }

            $autoStatus = 'active';
            if ($newStock <= 0) {
                $autoStatus = 'out_of_stock';
            } elseif ($newStock <= 3) {
                $autoStatus = 'low_stock';
            }

            $upd = $conn->prepare("UPDATE products SET inventory = ?, cartStatus = ? WHERE productID = ?");
            if (!$upd) {
                throw new Exception("Failed to prepare product stock update: " . $conn->error);
            }
            $upd->bind_param("isi", $newStock, $autoStatus, $productId);
            if (!$upd->execute()) {
                $upd->close();
                throw new Exception("Failed to update product stock for product ID: $productId");
            }
            $upd->close();

            logStockChange($conn, $orderId, $productId, null, $qtyOrdered, $oldStock, $newStock);
            continue;
        }

        $stockStmt = $conn->prepare("SELECT quantityAvailable FROM variation_stock WHERE variationID = ? FOR UPDATE");
        if (!$stockStmt) {
            throw new Exception("Failed to prepare stock select: " . $conn->error);
        }
        $stockStmt->bind_param("i", $variationId);
        $stockStmt->execute();
        $stockRes = $stockStmt->get_result();
        if ($stockRes->num_rows === 0) {

            throw new Exception("Stock record not found for variation ID: $variationId");
        }
        $stockRow = $stockRes->fetch_assoc();
        $currentStock = (int)$stockRow['quantityAvailable'];
        $newStock = $currentStock - $qtyOrdered;

        if ($newStock < 0) {
            throw new Exception("Insufficient stock for variation ID: $variationId (ordered: $qtyOrdered, available: $currentStock)");
        }

        $updateStmt = $conn->prepare("UPDATE variation_stock SET quantityAvailable = ? WHERE variationID = ?");
        if (!$updateStmt) {
            throw new Exception("Failed to prepare stock update: " . $conn->error);
        }
        $updateStmt->bind_param("ii", $newStock, $variationId);
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update stock for variation ID: $variationId");
        }
        $updateStmt->close();

        logStockChange($conn, $orderId, $productId, $variationId, $qtyOrdered, $currentStock, $newStock);

        checkStockThreshold($conn, $productId, $variationId, $newStock);
    }

}

function checkStockThreshold($conn, $productId, $variationId, $newStock) {

    $threshold = 5;
    $productName = "Product #$productId";

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

    $status = 'available';
    if ($newStock <= 0) {
        $status = 'out_of_stock';
    } elseif ($newStock <= $threshold) {
        $status = 'low_stock';
    }

    if ($status === 'out_of_stock') {

        createNotification($conn, "Product '$productName' (variation ID: $variationId) is now OUT OF STOCK.");
    } elseif ($status === 'low_stock') {
        createNotification($conn, "Product '$productName' (variation ID: $variationId) is LOW ON STOCK ($newStock left).");
    }
}

function createNotification($conn, $message) {

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
    } else {
        error_log("Failed to log stock change: " . $conn->error);
    }
}
?>
