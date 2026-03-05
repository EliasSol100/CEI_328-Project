<?php
/**
 * Custom Orders Management Module
 *
 * Implements function 3.2.6.16: Manage Custom Orders (Create / Update / Track)
 *
 * @package CreationsByAthina
 */

// Prevent direct access
if (!defined('INCLUDE_CHECK') && !defined('CUSTOM_ORDERS_DIRECT')) {
    die('Direct access not permitted');
}

/**
 * Allowed custom order statuses.
 */
const CUSTOM_ORDER_STATUSES = [
    'pending',
    'in_discussion',
    'accepted',
    'in_production',
    'ready_for_checkout',
    'completed',
    'declined'
];

/**
 * Create a new custom order request.
 *
 * @param mysqli $conn            Database connection
 * @param int    $userId           User ID (must be a valid registered user)
 * @param string $email            Customer email
 * @param string $requestDescription Detailed description of the custom order
 * @param array  $options          Optional parameters:
 *                                 - special_instructions: additional notes (stored in expertNotes)
 * @return array                   Result with customOrderID and access_token
 * @throws InvalidArgumentException On validation failure
 * @throws Exception                On database error
 */
function createCustomOrderRequest($conn, $userId, $email, $requestDescription, $options = []) {
    // Validate required fields
    if (!$userId || $userId <= 0) {
        throw new InvalidArgumentException("Valid user ID is required to create a custom order.");
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException("Valid email is required.");
    }
    if (empty($requestDescription)) {
        throw new InvalidArgumentException("Request description cannot be empty.");
    }

    // Ensure table structure is up to date (adds access_token, token_expires_at, created_at if missing)
    ensureCustomOrdersTable($conn);

    // Generate a secure access token for the customer to view this order later
    $accessToken = bin2hex(random_bytes(32));
    $tokenExpiry = date('Y-m-d H:i:s', strtotime('+30 days')); // Token valid for 30 days

    // Insert custom order
    $stmt = $conn->prepare("
        INSERT INTO custom_orders (
            userID, email, requestDescription, status, expertNotes, aiWritingAcknowledgeFlag,
            access_token, token_expires_at, created_at
        ) VALUES (?, ?, ?, 'pending', ?, 0, ?, ?, NOW())
    ");
    if (!$stmt) {
        throw new Exception("Failed to prepare insert: " . $conn->error);
    }

    $expertNotes = $options['special_instructions'] ?? null;
    // userID is integer, all others strings
    $stmt->bind_param("issssss", $userId, $email, $requestDescription, $expertNotes, $accessToken, $tokenExpiry);
    if (!$stmt->execute()) {
        throw new Exception("Failed to insert custom order: " . $stmt->error);
    }
    $customOrderId = $stmt->insert_id;
    $stmt->close();

    // Log to audit
    logCustomOrderAction($conn, $customOrderId, 'created', "Custom order created by user ID: $userId");

    // Notify admin
    notifyAdminNewCustomOrder($conn, $customOrderId, $email, $requestDescription);

    return [
        'custom_order_id' => $customOrderId,
        'access_token'    => $accessToken
    ];
}

/**
 * Update an existing custom order.
 *
 * @param mysqli $conn            Database connection
 * @param int    $customOrderId    Custom order ID
 * @param array  $updates          Fields to update:
 *                                 - status (must be in CUSTOM_ORDER_STATUSES)
 *                                 - expertNotes
 * @return bool                    True on success
 * @throws InvalidArgumentException If status is invalid or order not found
 * @throws Exception                On database error
 */
function updateCustomOrder($conn, $customOrderId, $updates) {
    // Verify order exists
    $stmt = $conn->prepare("SELECT customOrderID, status FROM custom_orders WHERE customOrderID = ?");
    $stmt->bind_param("i", $customOrderId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new InvalidArgumentException("Custom order #$customOrderId not found.");
    }
    $current = $result->fetch_assoc();
    $stmt->close();

    // Build update query dynamically
    $allowedFields = ['status', 'expertNotes'];
    $setParts = [];
    $params = [];
    $types = "";

    foreach ($updates as $field => $value) {
        if (!in_array($field, $allowedFields)) continue;
        if ($field === 'status') {
            if (!in_array($value, CUSTOM_ORDER_STATUSES)) {
                throw new InvalidArgumentException("Invalid status: $value");
            }
        }
        $setParts[] = "$field = ?";
        $params[] = $value;
        $types .= "s";
    }

    if (empty($setParts)) {
        return true; // nothing to update
    }

    $params[] = $customOrderId;
    $types .= "i";
    $sql = "UPDATE custom_orders SET " . implode(', ', $setParts) . " WHERE customOrderID = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare update: " . $conn->error);
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        throw new Exception("Failed to update custom order: " . $stmt->error);
    }
    $stmt->close();

    // Log
    logCustomOrderAction($conn, $customOrderId, 'updated', "Updated fields: " . implode(', ', array_keys($updates)));

    return true;
}

/**
 * Get custom order details by ID or access token.
 *
 * @param mysqli $conn          Database connection
 * @param int    $customOrderId  Custom order ID (optional if token provided)
 * @param string $accessToken     Access token (optional if ID provided)
 * @return array                 Custom order details (sensitive token removed unless requested by token)
 * @throws InvalidArgumentException If not found or token invalid/expired
 * @throws Exception               On database error
 */
function trackCustomOrder($conn, $customOrderId = null, $accessToken = null) {
    if (!$customOrderId && !$accessToken) {
        throw new InvalidArgumentException("Either customOrderId or accessToken must be provided.");
    }

    $sql = "SELECT 
                co.customOrderID,
                co.userID,
                co.email,
                co.requestDescription,
                co.status,
                co.expertNotes,
                co.aiWritingAcknowledgeFlag,
                co.access_token,
                co.token_expires_at,
                co.created_at,
                u.full_name as customer_name,
                u.phone as customer_phone
            FROM custom_orders co
            LEFT JOIN users u ON co.userID = u.id
            WHERE ";
    $params = [];
    $types = "";

    if ($customOrderId) {
        $sql .= "co.customOrderID = ?";
        $params[] = $customOrderId;
        $types .= "i";
    } else {
        $sql .= "co.access_token = ? AND co.token_expires_at > NOW()";
        $params[] = $accessToken;
        $types .= "s";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare select: " . $conn->error);
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new InvalidArgumentException("Custom order not found or token expired.");
    }
    $order = $result->fetch_assoc();
    $stmt->close();

    // If retrieving by ID, hide the token fields (admin view)
    if ($customOrderId) {
        unset($order['access_token']);
        unset($order['token_expires_at']);
    }

    return $order;
}

/**
 * Ensure custom_orders table has necessary columns for access tokens.
 * Adds columns if missing.
 *
 * @param mysqli $conn
 */
function ensureCustomOrdersTable($conn) {
    static $checked = false;
    if ($checked) return;

    $result = $conn->query("SHOW COLUMNS FROM custom_orders LIKE 'access_token'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE custom_orders ADD COLUMN access_token VARCHAR(255) NULL AFTER aiWritingAcknowledgeFlag");
    }
    $result = $conn->query("SHOW COLUMNS FROM custom_orders LIKE 'token_expires_at'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE custom_orders ADD COLUMN token_expires_at DATETIME NULL AFTER access_token");
    }
    $result = $conn->query("SHOW COLUMNS FROM custom_orders LIKE 'created_at'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE custom_orders ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
    $checked = true;
}

/**
 * Log custom order action to audit_logs.
 *
 * @param mysqli $conn
 * @param int    $customOrderId
 * @param string $actionType
 * @param string $message
 */
function logCustomOrderAction($conn, $customOrderId, $actionType, $message) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $detailsJSON = json_encode(['message' => $message]);
    $stmt = $conn->prepare("
        INSERT INTO audit_logs (userID, role, actionType, entityType, entityID, ipAddress, detailsJSON)
        VALUES (NULL, 'system', ?, 'custom_order', ?, ?, ?)
    ");
    if ($stmt) {
        $stmt->bind_param("siss", $actionType, $customOrderId, $ip, $detailsJSON);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Notify admin about new custom order.
 * Creates an entry in admin_notifications.
 *
 * @param mysqli $conn
 * @param int    $customOrderId
 * @param string $customerEmail
 * @param string $description
 */
function notifyAdminNewCustomOrder($conn, $customOrderId, $customerEmail, $description) {
    $message = "New custom order request #$customOrderId from $customerEmail: " . substr($description, 0, 100) . "...";
    createAdminNotification($conn, $message);
}

/**
 * Create admin notification (reused from stock_alerts module).
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