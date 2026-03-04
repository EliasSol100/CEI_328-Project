<?php
/**
 * Stock Alerts Module
 *
 * Implements function 3.2.2.7: Notify Admin & Disable Product Availability
 *
 * @package CreationsByAthina
 */

// Prevent direct access
if (!defined('INCLUDE_CHECK') && !defined('STOCK_ALERTS_DIRECT')) {
    die('Direct access not permitted');
}

/**
 * Handle stock alerts and disable product/variation when out of stock.
 *
 * @param mysqli $conn         Database connection
 * @param int    $productId     Product ID
 * @param int    $variationId   Variation ID (can be null if product has no variations)
 * @param int    $currentStock  Current stock quantity
 * @param int    $threshold     Low stock threshold
 * @param string $productName   Product name (optional, will be fetched if not provided)
 * @return void
 */
function handleStockAlert($conn, $productId, $variationId, $currentStock, $threshold, $productName = null) {
    // Get product name if not provided
    if ($productName === null) {
        $productName = getProductName($conn, $productId);
    }

    // Determine alert type
    if ($currentStock <= 0) {
        // Out of stock
        $message = "Product '$productName' (variation ID: " . ($variationId ?: 'N/A') . ") is now OUT OF STOCK.";
        $type = 'out_of_stock';
        
        // Disable the variation if it exists
        if ($variationId) {
            disableVariation($conn, $variationId);
            
            // Check if all variations of this product are disabled
            checkAndDisableProduct($conn, $productId);
        } else {
            // If no variations, disable the product itself
            disableProduct($conn, $productId);
        }
    } elseif ($currentStock <= $threshold) {
        // Low stock
        $message = "Product '$productName' (variation ID: " . ($variationId ?: 'N/A') . ") is LOW ON STOCK ($currentStock left).";
        $type = 'low_stock';
    } else {
        // No alert needed
        return;
    }

    // Log to audit_logs
    logStockAlert($conn, $productId, $variationId, $type, $currentStock, $threshold, $message);

    // Create admin notification
    createAdminNotification($conn, $message);
}

/**
 * Disable a specific variation (set is_active = 0).
 * If the column doesn't exist, it will be added.
 *
 * @param mysqli $conn
 * @param int    $variationId
 */
function disableVariation($conn, $variationId) {
    // Check if is_active column exists in product_variations
    static $columnChecked = false;
    if (!$columnChecked) {
        $result = $conn->query("SHOW COLUMNS FROM product_variations LIKE 'is_active'");
        if ($result->num_rows == 0) {
            // Column doesn't exist, add it
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

/**
 * Disable a product (set cartStatus = 'inactive').
 *
 * @param mysqli $conn
 * @param int    $productId
 */
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

/**
 * Check if all variations of a product are inactive, and if so, disable the product.
 *
 * @param mysqli $conn
 * @param int    $productId
 */
function checkAndDisableProduct($conn, $productId) {
    // Count active variations
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

/**
 * Get product name (prefer English, fallback to Greek).
 *
 * @param mysqli $conn
 * @param int    $productId
 * @return string
 */
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

/**
 * Log stock alert to audit_logs.
 *
 * @param mysqli $conn
 * @param int    $productId
 * @param int    $variationId
 * @param string $type
 * @param int    $currentStock
 * @param int    $threshold
 * @param string $message
 */
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

/**
 * Create an admin notification.
 * Creates a simple table if it doesn't exist and stores the message.
 *
 * @param mysqli $conn
 * @param string $message
 */
function createAdminNotification($conn, $message) {
    static $tableChecked = false;
    if (!$tableChecked) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS admin_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
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