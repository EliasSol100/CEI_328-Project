<?php
/**
 * Stock Threshold Check Module
 *
 * Implements function 3.2.2.6: Check Stock Threshold (Low / Out-of-Stock Detection)
 *
 * @package CreationsByAthina
 */

// Prevent direct access
if (!defined('INCLUDE_CHECK') && !defined('STOCK_THRESHOLD_DIRECT')) {
    die('Direct access not permitted');
}

/**
 * Check stock level against threshold and log status changes.
 *
 * This function evaluates the current stock of a variation against its low‑stock
 * threshold and records the result in audit_logs. It also creates an admin
 * notification if the stock becomes low or out of stock.
 *
 * @param mysqli $conn         Database connection
 * @param int    $variationId   Variation ID (must exist in variation_stock)
 * @param int    $currentStock  Current stock quantity (optional; if omitted, fetched from DB)
 * @param int    $threshold     Low stock threshold (optional; if omitted, fetched from DB)
 * @throws Exception On database error
 * @return string               New status: 'available', 'low_stock', or 'out_of_stock'
 */
function checkStockThreshold($conn, $variationId, $currentStock = null, $threshold = null) {
    // If current stock not provided, fetch it from variation_stock
    if ($currentStock === null || $threshold === null) {
        $stmt = $conn->prepare("
            SELECT quantityAvailable, lowStockThreshold 
            FROM variation_stock 
            WHERE variationID = ?
        ");
        if (!$stmt) {
            throw new Exception("Failed to prepare stock select: " . $conn->error);
        }
        $stmt->bind_param("i", $variationId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            throw new Exception("Variation ID $variationId not found in variation_stock.");
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($currentStock === null) {
            $currentStock = (int)$row['quantityAvailable'];
        }
        if ($threshold === null) {
            $threshold = (int)$row['lowStockThreshold'];
        }
    }

    // Determine status
    $status = 'available';
    if ($currentStock <= 0) {
        $status = 'out_of_stock';
    } elseif ($currentStock <= $threshold) {
        $status = 'low_stock';
    }

    // Get product name for logging
    $productId = getProductIdByVariation($conn, $variationId);
    $productName = getProductName($conn, $productId);

    // Log the status check in audit_logs
    $details = json_encode([
        'variation_id'  => $variationId,
        'product_id'    => $productId,
        'product_name'  => $productName,
        'current_stock' => $currentStock,
        'threshold'     => $threshold,
        'status'        => $status
    ]);

    $logStmt = $conn->prepare("
        INSERT INTO audit_logs (userID, role, actionType, entityType, entityID, ipAddress, detailsJSON)
        VALUES (NULL, 'system', 'stock_threshold_check', 'variation', ?, ?, ?)
    ");
    if ($logStmt) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $logStmt->bind_param("iss", $variationId, $ip, $details);
        $logStmt->execute();
        $logStmt->close();
    } else {
        error_log("Failed to log stock threshold check: " . $conn->error);
    }

    // Keep product-level stock/status synced from all its variations.
    syncProductStockStatusFromVariations($conn, $productId);

    // If low or out of stock, create an admin notification
    if ($status !== 'available') {
        $message = ($status === 'out_of_stock')
            ? "Product '$productName' (variation ID: $variationId) is now OUT OF STOCK."
            : "Product '$productName' (variation ID: $variationId) is LOW ON STOCK ($currentStock left).";
        createAdminNotification($conn, $message);
    }

    return $status;
}

/**
 * Sync products.inventory and products.cartStatus from variation_stock rows.
 *
 * Rules:
 * - out_of_stock: total stock <= 0
 * - low_stock: total stock > 0 and no variation above its threshold
 * - active: at least one variation is comfortably above threshold
 *
 * Keeps manual/admin workflow intact because admins can still override later.
 *
 * @param mysqli $conn
 * @param int $productId
 * @return void
 */
function syncProductStockStatusFromVariations(mysqli $conn, int $productId): void {
    if ($productId <= 0) return;

    // Do not override made-to-order products.
    $p = $conn->prepare("SELECT cartStatus FROM products WHERE productID = ? LIMIT 1");
    if (!$p) return;
    $p->bind_param("i", $productId);
    $p->execute();
    $pr = $p->get_result();
    $prow = $pr ? $pr->fetch_assoc() : null;
    $p->close();
    if (!$prow) return;
    if ((string)($prow['cartStatus'] ?? '') === 'made_to_order') return;

    $stmt = $conn->prepare("
        SELECT vs.quantityAvailable, vs.lowStockThreshold
        FROM variation_stock vs
        JOIN product_variations pv ON pv.variationID = vs.variationID
        WHERE pv.productID = ?
    ");
    if (!$stmt) return;
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $res = $stmt->get_result();

    $totalStock = 0;
    $hasComfortableStock = false;
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $qty = (int)($row['quantityAvailable'] ?? 0);
            $thr = (int)($row['lowStockThreshold'] ?? 0);
            $totalStock += $qty;
            if ($qty > $thr) {
                $hasComfortableStock = true;
            }
        }
    }
    $stmt->close();

    $newStatus = 'active';
    if ($totalStock <= 0) {
        $newStatus = 'out_of_stock';
    } elseif (!$hasComfortableStock) {
        $newStatus = 'low_stock';
    }

    $upd = $conn->prepare("UPDATE products SET inventory = ?, cartStatus = ? WHERE productID = ?");
    if (!$upd) return;
    $upd->bind_param("isi", $totalStock, $newStatus, $productId);
    $upd->execute();
    $upd->close();
}

/**
 * Helper: Get product ID from variation ID.
 *
 * @param mysqli $conn
 * @param int    $variationId
 * @return int
 */
function getProductIdByVariation($conn, $variationId) {
    $stmt = $conn->prepare("SELECT productID FROM product_variations WHERE variationID = ?");
    if (!$stmt) return 0;
    $stmt->bind_param("i", $variationId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $pid = (int)$row['productID'];
        $stmt->close();
        return $pid;
    }
    $stmt->close();
    return 0;
}

/**
 * Helper: Get product name (prefer English, fallback to Greek).
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
 * Helper: Create an admin notification.
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
