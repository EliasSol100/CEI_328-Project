<?php

if (!defined('INCLUDE_CHECK') && !defined('CREATE_CUSTOM_PRODUCT_DIRECT')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/../include/made_to_order_access.php';

if (!function_exists('ensureCustomOrdersTable')) {
    require_once __DIR__ . '/custom_orders.php';
}

function createCustomProductFromRequest($conn, $customOrderId, $price = null, $description = null, $imageFiles = [], $accessMethod = 'token')
{
    ensureCustomOrdersTable($conn);
    ensureMadeToOrderProductSchema($conn);

    if ($accessMethod !== 'token') {
        throw new InvalidArgumentException("Custom order checkout currently supports token links only.");
    }

    $customOrderId = (int)$customOrderId;
    if ($customOrderId <= 0) {
        throw new InvalidArgumentException('A valid custom order ID is required.');
    }

    $stmt = $conn->prepare("
        SELECT
            customOrderID,
            userID,
            email,
            customerName,
            requestDescription,
            status,
            agreedPrice,
            photoReferencePath,
            sourceProductID
        FROM custom_orders
        WHERE customOrderID = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare custom order lookup: ' . $conn->error);
    }
    $stmt->bind_param('i', $customOrderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $customOrder = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$customOrder) {
        throw new InvalidArgumentException("Custom order #{$customOrderId} was not found.");
    }

    $status = strtolower(trim((string)($customOrder['status'] ?? 'pending')));
    if (in_array($status, ['declined', 'cancelled', 'completed'], true)) {
        throw new InvalidArgumentException("Custom order #{$customOrderId} is not eligible for checkout link creation in its current status.");
    }

    $customerEmail = normalizeCustomerEmail((string)($customOrder['email'] ?? ''));
    if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('The custom order must have a valid customer email before a checkout link can be created.');
    }

    $price = $price !== null ? (float)$price : (float)($customOrder['agreedPrice'] ?? 0);
    if ($price <= 0) {
        throw new InvalidArgumentException('Set a valid agreed price before creating the checkout link.');
    }
    $price = round($price, 2);

    $productDescription = trim((string)($description ?? $customOrder['requestDescription'] ?? ''));
    if ($productDescription === '') {
        throw new InvalidArgumentException('Add a description to the custom order before creating the checkout link.');
    }

    $customerName = trim((string)($customOrder['customerName'] ?? ''));
    $productName = $customerName !== ''
        ? "Custom Order for {$customerName}"
        : "Custom Order #{$customOrderId}";

    $existingProductId = (int)($customOrder['sourceProductID'] ?? 0);
    $existingProduct = loadExistingCustomProductRow($conn, $existingProductId);

    $conn->begin_transaction();

    try {
        $privateAccessToken = '';
        $productId = 0;
        $emailChanged = false;

        if ($existingProduct) {
            $productId = (int)$existingProduct['productID'];
            $previousEmail = normalizeCustomerEmail((string)($existingProduct['privateCustomerEmail'] ?? ''));
            $privateAccessToken = trim((string)($existingProduct['privateAccessToken'] ?? ''));
            $emailChanged = ($previousEmail !== $customerEmail);
        }

        if ($privateAccessToken === '' || $emailChanged) {
            $privateAccessToken = generateMadeToOrderAccessToken();
        }

        if ($existingProduct) {
            $updateStmt = $conn->prepare("
                UPDATE products
                SET
                    nameEN = ?,
                    nameGR = ?,
                    descriptionEN = ?,
                    descriptionGR = ?,
                    basePrice = ?,
                    costPrice = ?,
                    inventory = 1,
                    cartStatus = 'made_to_order',
                    category = 'Custom Orders',
                    hasVariants = 0,
                    privateCustomerEmail = ?,
                    privateAccessToken = ?,
                    privateLinkSentAt = NOW()
                WHERE productID = ?
            ");
            if (!$updateStmt) {
                throw new Exception('Failed to prepare custom product update: ' . $conn->error);
            }
            $updateStmt->bind_param(
                'ssssddssi',
                $productName,
                $productName,
                $productDescription,
                $productDescription,
                $price,
                $price,
                $customerEmail,
                $privateAccessToken,
                $productId
            );
            if (!$updateStmt->execute()) {
                throw new Exception('Failed to update custom product: ' . $updateStmt->error);
            }
            $updateStmt->close();
        } else {
            $sku = 'CUSTOM-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
            $insertStmt = $conn->prepare("
                INSERT INTO products (
                    sku,
                    nameGR,
                    nameEN,
                    descriptionGR,
                    descriptionEN,
                    basePrice,
                    costPrice,
                    inventory,
                    cartStatus,
                    category,
                    hasVariants,
                    privateCustomerEmail,
                    privateAccessToken,
                    privateLinkSentAt
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'made_to_order', 'Custom Orders', 0, ?, ?, NOW())
            ");
            if (!$insertStmt) {
                throw new Exception('Failed to prepare custom product insert: ' . $conn->error);
            }
            $insertStmt->bind_param(
                'sssssddss',
                $sku,
                $productName,
                $productName,
                $productDescription,
                $productDescription,
                $price,
                $price,
                $customerEmail,
                $privateAccessToken
            );
            if (!$insertStmt->execute()) {
                throw new Exception('Failed to create custom product: ' . $insertStmt->error);
            }
            $productId = (int)$insertStmt->insert_id;
            $insertStmt->close();
        }

        attachCustomOrderReferencePhotoToProduct(
            $conn,
            $productId,
            (string)($customOrder['photoReferencePath'] ?? ''),
            $imageFiles
        );

        $orderUpdate = $conn->prepare("
            UPDATE custom_orders
            SET
                agreedPrice = ?,
                status = 'ready_for_checkout',
                sourceProductID = ?,
                linkedProductName = ?
            WHERE customOrderID = ?
        ");
        if (!$orderUpdate) {
            throw new Exception('Failed to prepare custom order link update: ' . $conn->error);
        }
        $orderUpdate->bind_param('disi', $price, $productId, $productName, $customOrderId);
        if (!$orderUpdate->execute()) {
            throw new Exception('Failed to update custom order link fields: ' . $orderUpdate->error);
        }
        $orderUpdate->close();

        $actionType = $existingProduct ? 'custom_product_updated' : 'custom_product_created';
        logCustomOrderAction($conn, $customOrderId, $actionType, 'Private checkout product prepared for the customer.');
        logCustomProductCreation($conn, $customOrderId, $productId, 'token');

        $conn->commit();

        return [
            'product_id' => $productId,
            'access_token' => $privateAccessToken,
            'customer_email' => $customerEmail,
            'private_link' => generateAccessLink($productId, 'token', $privateAccessToken, null),
            'product_name' => $productName,
            'was_updated' => $existingProduct ? 1 : 0,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function loadExistingCustomProductRow($conn, $productId)
{
    $productId = (int)$productId;
    if ($productId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT productID, privateCustomerEmail, privateAccessToken
        FROM products
        WHERE productID = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function generateAccessLink($productId, $method, $token, $password)
{
    $productId = (int)$productId;
    $method = strtolower(trim((string)$method));
    $token = trim((string)$token);

    if ($method !== 'token' || $productId <= 0 || $token === '') {
        throw new InvalidArgumentException('A valid product/token pair is required to build the checkout link.');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (strpos($script, '/modules/admin/') !== false) {
        $projectBase = rtrim(str_replace('\\', '/', dirname(dirname(dirname($script)))), '/');
    } elseif (strpos($script, '/modules/') !== false) {
        $projectBase = rtrim(str_replace('\\', '/', dirname(dirname($script))), '/');
    } else {
        $projectBase = rtrim(str_replace('\\', '/', dirname($script)), '/');
    }
    $projectBase = ($projectBase === '.' || $projectBase === '/') ? '' : $projectBase;

    $path = $projectBase . '/product.php?' . http_build_query([
        'id' => $productId,
        'mto_token' => $token,
    ]);

    if ($host !== '') {
        return $scheme . '://' . $host . $path;
    }

    return $path;
}

function attachCustomOrderReferencePhotoToProduct(mysqli $conn, int $productId, string $photoReferencePath, array $imageFiles = []): void
{
    if ($productId <= 0) {
        return;
    }

    $countStmt = $conn->prepare("SELECT COUNT(*) AS photo_count FROM photos WHERE productID = ?");
    if ($countStmt) {
        $countStmt->bind_param('i', $productId);
        $countStmt->execute();
        $countRes = $countStmt->get_result();
        $countRow = $countRes ? $countRes->fetch_assoc() : null;
        $countStmt->close();
        if ((int)($countRow['photo_count'] ?? 0) > 0) {
            return;
        }
    }

    $candidateFiles = [];
    foreach ($imageFiles as $imageFile) {
        $candidateFiles[] = (string)$imageFile;
    }
    if (trim($photoReferencePath) !== '') {
        $candidateFiles[] = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $photoReferencePath), '/');
    }

    foreach ($candidateFiles as $candidateFile) {
        $candidateFile = trim($candidateFile);
        if ($candidateFile === '' || !is_file($candidateFile)) {
            continue;
        }
        $binary = file_get_contents($candidateFile);
        if (!is_string($binary) || $binary === '') {
            continue;
        }
        if (function_exists('app_image_optimize_photo_blob_for_storage')) {
            $binary = app_image_optimize_photo_blob_for_storage($binary, 1400, 1400, 78);
        }
        $photoStmt = $conn->prepare("INSERT INTO photos (photo, productID) VALUES (?, ?)");
        if (!$photoStmt) {
            return;
        }
        $photoStmt->bind_param('si', $binary, $productId);
        $photoStmt->execute();
        $photoStmt->close();
        return;
    }
}

function sendCustomProductAccessEmail($toEmail, $productId, $accessLink, $method)
{
    $toEmail = normalizeCustomerEmail((string)$toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $body =
        "Hello,\n\n" .
        "Your private made-to-order product is ready to purchase.\n\n" .
        "Private checkout link:\n" . $accessLink . "\n\n" .
        "For security, this link only works when you are signed in with {$toEmail}.\n\n" .
        "Thank you,\nAthina E-Shop";

    if (function_exists('sendCustomOrderPlainEmail')) {
        return sendCustomOrderPlainEmail($toEmail, $toEmail, 'Your private custom order checkout link', $body);
    }

    require_once __DIR__ . '/../authentication/auth_mailer.php';
    $result = app_auth_send_plaintext_email($toEmail, $toEmail, 'Your private custom order checkout link', $body);
    return !empty($result['success']);
}

function logCustomProductCreation($conn, $customOrderId, $productId, $accessMethod)
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $details = json_encode([
        'custom_order_id' => (int)$customOrderId,
        'product_id' => (int)$productId,
        'access_method' => (string)$accessMethod,
    ]);

    $stmt = $conn->prepare("
        INSERT INTO audit_logs (userID, role, actionType, entityType, entityID, ipAddress, detailsJSON)
        VALUES (NULL, 'system', 'custom_product_created', 'product', ?, ?, ?)
    ");
    if ($stmt) {
        $stmt->bind_param('iss', $productId, $ip, $details);
        $stmt->execute();
        $stmt->close();
    }
}
?>
