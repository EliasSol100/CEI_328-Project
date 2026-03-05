<?php
/**
 * Create Custom Product from Request Module
 *
 * Implements function 3.2.6.17: Create Custom Product from Request
 *
 * @package CreationsByAthina
 */

// Prevent direct access
if (!defined('INCLUDE_CHECK') && !defined('CREATE_CUSTOM_PRODUCT_DIRECT')) {
    die('Direct access not permitted');
}

/**
 * Create a hidden custom product from an approved custom order request.
 *
 * @param mysqli $conn          Database connection
 * @param int    $customOrderId  ID of the custom order request
 * @param float  $price          Admin-defined price for the product
 * @param string $description    Product description (optional, defaults to request description)
 * @param array  $imageFiles     Array of file paths to uploaded images (optional)
 * @param string $accessMethod   'token' or 'password' (default 'token')
 * @return array                 Result with product_id, access_token/password, and customer email
 * @throws InvalidArgumentException On validation failure
 * @throws Exception                On database error
 */
function createCustomProductFromRequest($conn, $customOrderId, $price, $description = null, $imageFiles = [], $accessMethod = 'token') {
    // Validate access method
    if (!in_array($accessMethod, ['token', 'password'])) {
        throw new InvalidArgumentException("Access method must be 'token' or 'password'.");
    }

    // Fetch custom order request
    $stmt = $conn->prepare("
        SELECT co.customOrderID, co.userID, co.email, co.requestDescription, co.status
        FROM custom_orders co
        WHERE co.customOrderID = ?
    ");
    if (!$stmt) {
        throw new Exception("Failed to prepare custom order fetch: " . $conn->error);
    }
    $stmt->bind_param("i", $customOrderId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new InvalidArgumentException("Custom order #$customOrderId not found.");
    }
    $customOrder = $result->fetch_assoc();
    $stmt->close();

    // Verify the request is in an appropriate status (e.g., 'accepted' or 'in_discussion')
    $allowedStatuses = ['accepted', 'in_discussion']; // adjust as needed
    if (!in_array($customOrder['status'], $allowedStatuses)) {
        throw new InvalidArgumentException("Custom order status '{$customOrder['status']}' not eligible for product creation.");
    }

    // Validate price
    if (!is_numeric($price) || $price <= 0) {
        throw new InvalidArgumentException("Price must be a positive number.");
    }
    $price = (float)$price;

    // Prepare product data
    $productDescription = $description ?? $customOrder['requestDescription'];
    
    // Generate a unique SKU for the custom product
    $sku = 'CUSTOM-' . strtoupper(uniqid());

    // Begin transaction
    $conn->begin_transaction();

    try {
        // 1. Insert into products table as a hidden product
        $stmt = $conn->prepare("
            INSERT INTO products (
                sku, nameGR, nameEN, descriptionGR, descriptionEN,
                basePrice, costPrice, cartStatus, hasVariants
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'private', 0)
        ");
        if (!$stmt) {
            throw new Exception("Failed to prepare product insert: " . $conn->error);
        }
        $productName = "Custom Order #$customOrderId";
        $stmt->bind_param(
            "sssssdd",
            $sku,
            $productName,
            $productName,
            $productDescription,
            $productDescription,
            $price,
            $price
        );
        if (!$stmt->execute()) {
            throw new Exception("Failed to create product: " . $stmt->error);
        }
        $productId = $stmt->insert_id;
        $stmt->close();

        // 2. Handle images (if provided) – store binary data in photos table
        if (!empty($imageFiles)) {
            $photoStmt = $conn->prepare("INSERT INTO photos (productID, photo) VALUES (?, ?)");
            if (!$photoStmt) {
                throw new Exception("Failed to prepare image insert: " . $conn->error);
            }
            foreach ($imageFiles as $filePath) {
                if (!file_exists($filePath)) {
                    throw new Exception("Image file not found: $filePath");
                }
                $imageData = file_get_contents($filePath);
                if ($imageData === false) {
                    throw new Exception("Failed to read image file: $filePath");
                }
                // Bind as blob
                $photoStmt->bind_param("is", $productId, $imageData);
                $photoStmt->send_long_data(1, $imageData); // for blob
                if (!$photoStmt->execute()) {
                    throw new Exception("Failed to insert image: " . $photoStmt->error);
                }
            }
            $photoStmt->close();
        }

        // 3. Create access credentials in custom_product_access table
        ensureCustomProductAccessTable($conn);
        
        if ($accessMethod === 'token') {
            $accessToken = bin2hex(random_bytes(32));
            $accessPassword = null;
        } else {
            // Generate a random password (12 chars)
            $accessToken = null;
            $accessPassword = bin2hex(random_bytes(6)); // 12 hex chars
        }

        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days')); // 30 days validity

        $accessStmt = $conn->prepare("
            INSERT INTO custom_product_access (productID, userID, access_token, access_password, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$accessStmt) {
            throw new Exception("Failed to prepare access insert: " . $conn->error);
        }
        $accessStmt->bind_param("iisss", $productId, $customOrder['userID'], $accessToken, $accessPassword, $expiresAt);
        if (!$accessStmt->execute()) {
            throw new Exception("Failed to store access credentials: " . $accessStmt->error);
        }
        $accessStmt->close();

        // 4. Update custom order status to 'ready_for_checkout'
        $updateStmt = $conn->prepare("UPDATE custom_orders SET status = 'ready_for_checkout' WHERE customOrderID = ?");
        if (!$updateStmt) {
            throw new Exception("Failed to prepare custom order update: " . $conn->error);
        }
        $updateStmt->bind_param("i", $customOrderId);
        $updateStmt->execute();
        $updateStmt->close();

        // Commit transaction
        $conn->commit();

        // 5. Send email to customer with access details
        $accessLink = generateAccessLink($productId, $accessMethod, $accessToken, $accessPassword);
        sendCustomProductAccessEmail($customOrder['email'], $productId, $accessLink, $accessMethod);

        // 6. Log action
        logCustomProductCreation($conn, $customOrderId, $productId, $accessMethod);

        return [
            'product_id'       => $productId,
            'access_token'     => $accessToken,
            'access_password'  => $accessPassword,
            'customer_email'   => $customOrder['email']
        ];

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Ensure the custom_product_access table exists.
 *
 * @param mysqli $conn
 */
function ensureCustomProductAccessTable($conn) {
    static $checked = false;
    if ($checked) return;

    $conn->query("
        CREATE TABLE IF NOT EXISTS custom_product_access (
            id INT AUTO_INCREMENT PRIMARY KEY,
            productID INT NOT NULL,
            userID INT NOT NULL,
            access_token VARCHAR(255) NULL,
            access_password VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            INDEX idx_product (productID),
            INDEX idx_user (userID),
            INDEX idx_token (access_token)
        )
    ");
    $checked = true;
}

/**
 * Generate a secure access link for the custom product.
 *
 * @param int    $productId
 * @param string $method        'token' or 'password'
 * @param string $token
 * @param string $password
 * @return string               Full URL
 */
function generateAccessLink($productId, $method, $token, $password) {
    // Determine base URL (adjust as needed for your project structure)
    $baseUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    $baseUrl = rtrim($baseUrl, '/') . '/CEI_328-Project/';

    if ($method === 'token') {
        return $baseUrl . "custom-product.php?token=" . urlencode($token);
    } else {
        return $baseUrl . "custom-product.php?product_id=$productId&auth=password";
    }
}

/**
 * Send email with custom product access details.
 *
 * @param string $toEmail
 * @param int    $productId
 * @param string $accessLink
 * @param string $method
 */
function sendCustomProductAccessEmail($toEmail, $productId, $accessLink, $method) {
    $subject = "Your Custom Product is Ready – Creations by Athina";
    $message = "Dear customer,\n\n";
    $message .= "Your custom product has been created and is ready for viewing and checkout.\n\n";
    $message .= "Access your private product using the link below:\n";
    $message .= $accessLink . "\n\n";
    if ($method === 'password') {
        $message .= "You will need to enter the password that was included in the link or provided separately.\n";
    }
    $message .= "This access link will expire in 30 days.\n\n";
    $message .= "Thank you for choosing Creations by Athina!\n";

    $headers = "From: no-reply@creationsbyathina.com\r\n";
    // In production, use a proper mail library (e.g., PHPMailer, SMTP)
    mail($toEmail, $subject, $message, $headers);
}

/**
 * Log custom product creation to audit_logs.
 *
 * @param mysqli $conn
 * @param int    $customOrderId
 * @param int    $productId
 * @param string $accessMethod
 */
function logCustomProductCreation($conn, $customOrderId, $productId, $accessMethod) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $details = json_encode([
        'custom_order_id' => $customOrderId,
        'product_id'      => $productId,
        'access_method'   => $accessMethod
    ]);
    $stmt = $conn->prepare("
        INSERT INTO audit_logs (userID, role, actionType, entityType, entityID, ipAddress, detailsJSON)
        VALUES (NULL, 'system', 'custom_product_created', 'product', ?, ?, ?)
    ");
    if ($stmt) {
        $stmt->bind_param("iss", $productId, $ip, $details);
        $stmt->execute();
        $stmt->close();
    }
}
?>