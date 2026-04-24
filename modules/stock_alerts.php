<?php

if (!defined('INCLUDE_CHECK') && !defined('STOCK_ALERTS_DIRECT')) {
    die('Direct access not permitted');
}

function handleStockAlert($conn, $productId, $variationId, $currentStock, $threshold, $productName = null) {

    if ($productName === null) {
        $productName = getProductName($conn, $productId);
    }

    if ($currentStock <= 0) {

        $message = "Product '$productName' (variation ID: " . ($variationId ?: 'N/A') . ") is now OUT OF STOCK.";
        $type = 'out_of_stock';

        if ($variationId) {
            disableVariation($conn, $variationId);

            checkAndDisableProduct($conn, $productId);
        } else {

            disableProduct($conn, $productId);
        }
    } elseif ($currentStock <= $threshold) {

        $message = "Product '$productName' (variation ID: " . ($variationId ?: 'N/A') . ") is LOW ON STOCK ($currentStock left).";
        $type = 'low_stock';
    } else {

        return;
    }

    logStockAlert($conn, $productId, $variationId, $type, $currentStock, $threshold, $message);

    createAdminNotification($conn, $message);
}

function disableVariation($conn, $variationId) {

    static $columnChecked = false;
    if (!$columnChecked) {
        $result = $conn->query("SHOW COLUMNS FROM product_variations LIKE 'is_active'");
        if ($result->num_rows == 0) {

            $conn->query("ALTER TABLE product_variations ADD COLUMN is_active TINYINT(1) DEFAULT 1");
        }
        $columnChecked = true;
    }

    $stmt = $conn->prepare("UPDATE product_variations SET is_active = 0 WHERE variationID = ?");
    if ($stmt) {
        $stmt->bind_param("i", $variationId);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log("Failed to disable variation: " . $conn->error);
    }
}

function disableProduct($conn, $productId) {
    $stmt = $conn->prepare("UPDATE products SET cartStatus = 'inactive' WHERE productID = ?");
    if ($stmt) {
        $stmt->bind_param("i", $productId);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log("Failed to disable product: " . $conn->error);
    }
}

function checkAndDisableProduct($conn, $productId) {

    $stmt = $conn->prepare("
        SELECT COUNT(*) as active_count
        FROM product_variations
        WHERE productID = ? AND (is_active = 1 OR is_active IS NULL)
    ");
    if (!$stmt) return;
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $activeCount = (int)$row['active_count'];
    $stmt->close();

    if ($activeCount == 0) {
        disableProduct($conn, $productId);
    }
}

function getProductName($conn, $productId) {
    if (!$productId) return "Unknown Product";
    $stmt = $conn->prepare("SELECT nameEN, nameGR FROM products WHERE productID = ?");
    if (!$stmt) return "Product #$productId";
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $name = $row['nameEN'] ?: $row['nameGR'];
        $stmt->close();
        return $name ?: "Product #$productId";
    }
    $stmt->close();
    return "Product #$productId";
}

function logStockAlert($conn, $productId, $variationId, $type, $currentStock, $threshold, $message) {
    $details = json_encode([
        'product_id'    => $productId,
        'variation_id'  => $variationId,
        'type'          => $type,
        'current_stock' => $currentStock,
        'threshold'     => $threshold,
        'message'       => $message
    ]);

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $entityId = $variationId ?: $productId;
    $entityType = $variationId ? 'variation' : 'product';

    $stmt = $conn->prepare("
        INSERT INTO audit_logs (userID, role, actionType, entityType, entityID, ipAddress, detailsJSON)
        VALUES (NULL, 'system', ?, ?, ?, ?, ?)
    ");
    if ($stmt) {
        $actionType = "stock_alert_$type";
        $stmt->bind_param("ssiss", $actionType, $entityType, $entityId, $ip, $details);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log("Failed to log stock alert: " . $conn->error);
    }
}

function createAdminNotification($conn, $message) {
    static $tableChecked = false;
    if (!$tableChecked) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS admin_notifications (
                notification_id INT AUTO_INCREMENT PRIMARY KEY,
                message TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                is_read TINYINT(1) DEFAULT 0
            )
        ");
        $tableChecked = true;
    }
    $stmt = $conn->prepare("INSERT INTO admin_notifications (message) VALUES (?)");
    if ($stmt) {
        $stmt->bind_param("s", $message);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log("Failed to create admin notification: " . $conn->error);
    }
}
?>
