<?php

if (!defined('INCLUDE_CHECK') && !defined('CUSTOM_ORDERS_DIRECT')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/../include/image_storage.php';

const CUSTOM_ORDER_STATUSES = [
    'pending',
    'in_discussion',
    'accepted',
    'in_production',
    'ready_for_checkout',
    'completed',
    'declined',
    'cancelled',
    'in_progress',
];

function getCustomOrderStatusLabels(): array
{
    return [
        'pending'            => 'Pending',
        'in_discussion'      => 'In Discussion',
        'accepted'           => 'Accepted',
        'in_production'      => 'In Production',
        'ready_for_checkout' => 'Ready for Checkout',
        'completed'          => 'Completed',
        'declined'           => 'Declined',
        'cancelled'          => 'Cancelled',
        'in_progress'        => 'In Progress',
    ];
}

function createCustomOrderRequest($conn, $userId, $email, $requestDescription, $options = [])
{
    if (!$userId || $userId <= 0) {
        throw new InvalidArgumentException('Valid user ID is required to create a custom order.');
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Valid email is required.');
    }
    if (empty($requestDescription)) {
        throw new InvalidArgumentException('Request description cannot be empty.');
    }

    ensureCustomOrdersTable($conn);

    $accessToken = bin2hex(random_bytes(32));
    $tokenExpiry = date('Y-m-d H:i:s', strtotime('+30 days'));

    $stmt = $conn->prepare("
        INSERT INTO custom_orders (
            userID, email, requestDescription, status, expertNotes, aiWritingAcknowledgeFlag,
            customerName, photoReferencePath, access_token, token_expires_at, created_at
        ) VALUES (?, ?, ?, 'pending', ?, 0, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare insert: ' . $conn->error);
    }

    $expertNotes = $options['special_instructions'] ?? null;
    $customerName = trim((string)($options['customer_name'] ?? ''));
    $customerName = $customerName !== '' ? $customerName : null;
    $photoReferencePath = trim((string)($options['photo_reference_path'] ?? ''));
    $photoReferencePath = $photoReferencePath !== '' ? $photoReferencePath : null;

    $stmt->bind_param(
        'isssssss',
        $userId,
        $email,
        $requestDescription,
        $expertNotes,
        $customerName,
        $photoReferencePath,
        $accessToken,
        $tokenExpiry
    );

    if (!$stmt->execute()) {
        throw new Exception('Failed to insert custom order: ' . $stmt->error);
    }

    $customOrderId = (int)$stmt->insert_id;
    $stmt->close();

    addCustomOrderMessage(
        $conn,
        $customOrderId,
        'system',
        null,
        'Custom order request received. The shop owner will review it and reply with details.'
    );

    logCustomOrderAction($conn, $customOrderId, 'created', "Custom order created by user ID: $userId");
    notifyAdminNewCustomOrder($conn, $customOrderId, $email, $requestDescription);

    return [
        'custom_order_id' => $customOrderId,
        'access_token' => $accessToken,
    ];
}

function updateCustomOrder($conn, $customOrderId, $updates)
{
    ensureCustomOrdersTable($conn);

    $stmt = $conn->prepare('SELECT customOrderID FROM custom_orders WHERE customOrderID = ?');
    if (!$stmt) {
        throw new Exception('Failed to prepare existence check: ' . $conn->error);
    }
    $stmt->bind_param('i', $customOrderId);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        throw new InvalidArgumentException("Custom order #$customOrderId not found.");
    }
    $stmt->close();

    $allowedFields = ['status', 'expertNotes', 'agreedPrice', 'deadline', 'customerName', 'email', 'requestDescription', 'accessCode'];
    $setParts = [];
    $params = [];
    $types = '';

    foreach ($updates as $field => $value) {
        if (!in_array($field, $allowedFields, true)) {
            continue;
        }
        if ($field === 'status' && !in_array((string)$value, CUSTOM_ORDER_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid status: $value");
        }

        $setParts[] = $field . ' = ?';

        if ($field === 'agreedPrice') {
            $params[] = (float)$value;
            $types .= 'd';
            continue;
        }

        $params[] = $value;
        $types .= 's';
    }

    if (empty($setParts)) {
        return true;
    }

    $params[] = $customOrderId;
    $types .= 'i';

    $sql = 'UPDATE custom_orders SET ' . implode(', ', $setParts) . ' WHERE customOrderID = ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare update: ' . $conn->error);
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        throw new Exception('Failed to update custom order: ' . $stmt->error);
    }
    $stmt->close();

    logCustomOrderAction($conn, $customOrderId, 'updated', 'Updated fields: ' . implode(', ', array_keys($updates)));

    return true;
}

function trackCustomOrder($conn, $customOrderId = null, $accessToken = null)
{
    ensureCustomOrdersTable($conn);

    if (!$customOrderId && !$accessToken) {
        throw new InvalidArgumentException('Either customOrderId or accessToken must be provided.');
    }

    $sql = "
        SELECT
            co.*,
            COALESCE(
                NULLIF(co.customerName, ''),
                NULLIF(u.full_name, ''),
                NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), '')
            ) AS customer_name,
            u.phone AS customer_phone
        FROM custom_orders co
        LEFT JOIN users u ON co.userID = u.userID
        WHERE
    ";
    $params = [];
    $types = '';

    if ($customOrderId) {
        $sql .= ' co.customOrderID = ?';
        $params[] = (int)$customOrderId;
        $types .= 'i';
    } else {
        $sql .= ' co.access_token = ? AND co.token_expires_at > NOW()';
        $params[] = (string)$accessToken;
        $types .= 's';
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare select: ' . $conn->error);
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        throw new InvalidArgumentException('Custom order not found or token expired.');
    }

    $order = $result->fetch_assoc();
    $stmt->close();

    if ($customOrderId) {
        unset($order['access_token'], $order['token_expires_at']);
    }

    return $order;
}

function getCustomOrderById($conn, $customOrderId, $userId = null)
{
    ensureCustomOrdersTable($conn);

    $sql = "
        SELECT
            co.*,
            COALESCE(
                NULLIF(co.customerName, ''),
                NULLIF(u.full_name, ''),
                NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), '')
            ) AS displayName
        FROM custom_orders co
        LEFT JOIN users u ON u.userID = co.userID
        WHERE co.customOrderID = ?
    ";
    $types = 'i';
    $params = [(int)$customOrderId];

    if ($userId !== null) {
        $sql .= ' AND co.userID = ?';
        $types .= 'i';
        $params[] = (int)$userId;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare custom order fetch: ' . $conn->error);
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$order) {
        throw new InvalidArgumentException('Custom order not found.');
    }

    return $order;
}

function getCustomOrdersForUser($conn, $userId)
{
    ensureCustomOrdersTable($conn);

    $sql = "
        SELECT
            co.*,
            (
                SELECT COUNT(*)
                FROM custom_order_offers ofr
                WHERE ofr.customOrderID = co.customOrderID
                  AND ofr.offerStatus = 'pending'
            ) AS pendingOfferCount,
            (
                SELECT ofr.offeredPrice
                FROM custom_order_offers ofr
                WHERE ofr.customOrderID = co.customOrderID
                ORDER BY ofr.createdAt DESC, ofr.offerID DESC
                LIMIT 1
            ) AS latestOfferedPrice,
            (
                SELECT ofr.proposedDeadline
                FROM custom_order_offers ofr
                WHERE ofr.customOrderID = co.customOrderID
                ORDER BY ofr.createdAt DESC, ofr.offerID DESC
                LIMIT 1
            ) AS latestOfferDeadline,
            (
                SELECT ofr.offerStatus
                FROM custom_order_offers ofr
                WHERE ofr.customOrderID = co.customOrderID
                ORDER BY ofr.createdAt DESC, ofr.offerID DESC
                LIMIT 1
            ) AS latestOfferStatus,
            (
                SELECT msg.createdAt
                FROM custom_order_messages msg
                WHERE msg.customOrderID = co.customOrderID
                ORDER BY msg.createdAt DESC, msg.messageID DESC
                LIMIT 1
            ) AS lastMessageAt
        FROM custom_orders co
        WHERE co.userID = ?
        ORDER BY co.customOrderID DESC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $orders = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
    }

    $stmt->close();
    return $orders;
}

function getCustomOrderMessages($conn, $customOrderId)
{
    ensureCustomOrdersTable($conn);

    $stmt = $conn->prepare("
        SELECT messageID, customOrderID, senderRole, senderUserID, messageBody, createdAt
        FROM custom_order_messages
        WHERE customOrderID = ?
        ORDER BY createdAt ASC, messageID ASC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $customOrderId);
    $stmt->execute();
    $result = $stmt->get_result();

    $messages = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
    }

    $stmt->close();
    return $messages;
}

function getActiveCustomOrderOffer($conn, $customOrderId)
{
    ensureCustomOrdersTable($conn);

    $stmt = $conn->prepare("
        SELECT offerID, customOrderID, offeredByUserID, offeredPrice, proposedDeadline, offerNote, offerStatus, createdAt, respondedAt
        FROM custom_order_offers
        WHERE customOrderID = ? AND offerStatus = 'pending'
        ORDER BY createdAt DESC, offerID DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $customOrderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $offer = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $offer ?: null;
}

function getLatestCustomOrderOffer($conn, $customOrderId)
{
    ensureCustomOrdersTable($conn);

    $stmt = $conn->prepare("
        SELECT offerID, customOrderID, offeredByUserID, offeredPrice, proposedDeadline, offerNote, offerStatus, createdAt, respondedAt
        FROM custom_order_offers
        WHERE customOrderID = ?
        ORDER BY createdAt DESC, offerID DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $customOrderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $offer = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $offer ?: null;
}

function addCustomOrderMessage($conn, $customOrderId, $senderRole, $senderUserId, $messageBody)
{
    ensureCustomOrdersTable($conn);

    $senderRole = strtolower(trim((string)$senderRole));
    $allowedRoles = ['admin', 'customer', 'system'];
    if (!in_array($senderRole, $allowedRoles, true)) {
        throw new InvalidArgumentException('Invalid sender role for custom order message.');
    }

    $messageBody = trim((string)$messageBody);
    if ($messageBody === '') {
        throw new InvalidArgumentException('Message cannot be empty.');
    }

    $order = getCustomOrderById($conn, $customOrderId);

    $senderUserId = $senderUserId ? (int)$senderUserId : null;
    $stmt = $conn->prepare("
        INSERT INTO custom_order_messages (customOrderID, senderRole, senderUserID, messageBody)
        VALUES (?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare message insert: ' . $conn->error);
    }

    $stmt->bind_param('isis', $customOrderId, $senderRole, $senderUserId, $messageBody);
    if (!$stmt->execute()) {
        throw new Exception('Failed to save custom order message: ' . $stmt->error);
    }

    $messageId = (int)$stmt->insert_id;
    $stmt->close();

    logCustomOrderAction($conn, $customOrderId, 'message_posted', ucfirst($senderRole) . ' posted a discussion message.');

    if ($senderRole === 'customer') {
        createAdminNotification($conn, "Customer replied on custom order #{$customOrderId}.");
        sendCustomOrderAdminEmail(
            $conn,
            "Customer reply on custom order #{$customOrderId}",
            "A customer replied on custom order #{$customOrderId}.\n\n" .
            "Customer: " . trim((string)($order['displayName'] ?? $order['customerName'] ?? 'Customer')) . "\n" .
            "Email: " . trim((string)($order['email'] ?? '')) . "\n\n" .
            "Message:\n{$messageBody}\n\n" .
            "Open in admin:\n" . customOrderAdminUrl((int)$customOrderId)
        );
    }

    return $messageId;
}

function createCustomOrderOffer($conn, $customOrderId, $adminUserId, $price, $deadline = null, $offerNote = null)
{
    ensureCustomOrdersTable($conn);

    getCustomOrderById($conn, $customOrderId);

    if (!is_numeric($price) || (float)$price <= 0) {
        throw new InvalidArgumentException('Offer price must be a positive amount.');
    }
    $price = round((float)$price, 2);

    $deadline = trim((string)$deadline);
    if ($deadline !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $deadline);
        if (!$dt || $dt->format('Y-m-d') !== $deadline) {
            throw new InvalidArgumentException('Offer deadline must be a valid date.');
        }
    } else {
        $deadline = null;
    }

    $offerNote = trim((string)$offerNote);
    $offerNote = $offerNote !== '' ? $offerNote : null;
    $adminUserId = $adminUserId ? (int)$adminUserId : null;

    $conn->begin_transaction();

    try {

        $supersedeStmt = $conn->prepare("
            UPDATE custom_order_offers
            SET offerStatus = 'superseded', respondedAt = NOW()
            WHERE customOrderID = ? AND offerStatus = 'pending'
        ");
        if ($supersedeStmt) {
            $supersedeStmt->bind_param('i', $customOrderId);
            $supersedeStmt->execute();
            $supersedeStmt->close();
        }

        $stmt = $conn->prepare("
            INSERT INTO custom_order_offers (
                customOrderID, offeredByUserID, offeredPrice, proposedDeadline, offerNote, offerStatus
            ) VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        if (!$stmt) {
            throw new Exception('Failed to prepare custom order offer insert: ' . $conn->error);
        }
        $stmt->bind_param('iidss', $customOrderId, $adminUserId, $price, $deadline, $offerNote);
        if (!$stmt->execute()) {
            throw new Exception('Failed to save custom order offer: ' . $stmt->error);
        }
        $offerId = (int)$stmt->insert_id;
        $stmt->close();

        $updateStmt = $conn->prepare("
            UPDATE custom_orders
            SET agreedPrice = ?, deadline = ?, status = 'in_discussion'
            WHERE customOrderID = ?
        ");
        if (!$updateStmt) {
            throw new Exception('Failed to prepare custom order offer update: ' . $conn->error);
        }
        $updateStmt->bind_param('dsi', $price, $deadline, $customOrderId);
        $updateStmt->execute();
        $updateStmt->close();

        $systemMessage = 'A new price offer was sent: EUR ' . number_format($price, 2);
        if ($deadline !== null) {
            $systemMessage .= ' with target date ' . $deadline;
        }
        $systemMessage .= '.';
        addCustomOrderMessage($conn, $customOrderId, 'system', null, $systemMessage);

        if ($offerNote !== null) {
            addCustomOrderMessage($conn, $customOrderId, 'admin', $adminUserId, $offerNote);
        }

        logCustomOrderAction($conn, $customOrderId, 'offer_created', 'Admin created a price offer.');
        $conn->commit();
        sendCustomOrderOfferEmail($conn, $customOrderId, $price, $deadline, $offerNote);

        return $offerId;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function respondToCustomOrderOffer($conn, $customOrderId, $offerId, $customerUserId, $decision)
{
    ensureCustomOrdersTable($conn);

    $order = getCustomOrderById($conn, $customOrderId, $customerUserId);
    if ((int)($order['userID'] ?? 0) !== (int)$customerUserId) {
        throw new InvalidArgumentException('You do not have access to this custom order.');
    }

    $decision = strtolower(trim((string)$decision));
    if (!in_array($decision, ['accept', 'decline'], true)) {
        throw new InvalidArgumentException('Invalid custom order offer response.');
    }

    $stmt = $conn->prepare("
        SELECT offerID, offeredPrice, proposedDeadline, offerStatus
        FROM custom_order_offers
        WHERE offerID = ? AND customOrderID = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare custom order offer lookup: ' . $conn->error);
    }
    $stmt->bind_param('ii', $offerId, $customOrderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $offer = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$offer) {
        throw new InvalidArgumentException('Offer not found.');
    }
    if ((string)$offer['offerStatus'] !== 'pending') {
        throw new InvalidArgumentException('That offer is no longer awaiting a response.');
    }

    $newOfferStatus = $decision === 'accept' ? 'accepted' : 'declined';
    $newOrderStatus = $decision === 'accept' ? 'accepted' : 'in_discussion';
    $systemMessage = $decision === 'accept'
        ? 'Customer accepted the latest price offer.'
        : 'Customer declined the latest price offer.';

    $conn->begin_transaction();

    try {
        $offerUpdate = $conn->prepare("
            UPDATE custom_order_offers
            SET offerStatus = ?, respondedAt = NOW()
            WHERE offerID = ?
        ");
        if (!$offerUpdate) {
            throw new Exception('Failed to prepare offer response update: ' . $conn->error);
        }
        $offerUpdate->bind_param('si', $newOfferStatus, $offerId);
        $offerUpdate->execute();
        $offerUpdate->close();

        if ($decision === 'accept') {
            $orderUpdate = $conn->prepare("
                UPDATE custom_orders
                SET status = ?, agreedPrice = ?, deadline = ?
                WHERE customOrderID = ?
            ");
            if (!$orderUpdate) {
                throw new Exception('Failed to prepare accepted order update: ' . $conn->error);
            }
            $acceptedPrice = (float)$offer['offeredPrice'];
            $acceptedDeadline = $offer['proposedDeadline'];
            $orderUpdate->bind_param('sdsi', $newOrderStatus, $acceptedPrice, $acceptedDeadline, $customOrderId);
            $orderUpdate->execute();
            $orderUpdate->close();
        } else {
            $orderUpdate = $conn->prepare('UPDATE custom_orders SET status = ? WHERE customOrderID = ?');
            if (!$orderUpdate) {
                throw new Exception('Failed to prepare declined order update: ' . $conn->error);
            }
            $orderUpdate->bind_param('si', $newOrderStatus, $customOrderId);
            $orderUpdate->execute();
            $orderUpdate->close();
        }

        addCustomOrderMessage($conn, $customOrderId, 'system', null, $systemMessage);
        logCustomOrderAction($conn, $customOrderId, 'offer_' . $newOfferStatus, 'Customer responded to the latest price offer.');
        createAdminNotification($conn, "Customer {$newOfferStatus} offer on custom order #{$customOrderId}.");
        sendCustomOrderAdminEmail(
            $conn,
            "Custom order #{$customOrderId} offer {$newOfferStatus}",
            "The customer {$newOfferStatus} the latest offer for custom order #{$customOrderId}.\n\n" .
            "Open in admin:\n" . customOrderAdminUrl((int)$customOrderId)
        );

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function deleteCustomOrderConversation($conn, $customOrderId)
{
    ensureCustomOrdersTable($conn);

    $deleteOffers = $conn->prepare('DELETE FROM custom_order_offers WHERE customOrderID = ?');
    if ($deleteOffers) {
        $deleteOffers->bind_param('i', $customOrderId);
        $deleteOffers->execute();
        $deleteOffers->close();
    }

    $deleteMessages = $conn->prepare('DELETE FROM custom_order_messages WHERE customOrderID = ?');
    if ($deleteMessages) {
        $deleteMessages->bind_param('i', $customOrderId);
        $deleteMessages->execute();
        $deleteMessages->close();
    }
}

function ensureCustomOrdersTable($conn)
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $tableCheck = $conn->query("SHOW TABLES LIKE 'custom_orders'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS custom_orders (
                customOrderID INT AUTO_INCREMENT PRIMARY KEY,
                userID INT NULL,
                email VARCHAR(255) NULL,
                requestDescription LONGTEXT NOT NULL,
                avgReadTime DOUBLE NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'pending',
                expertNotes VARCHAR(500) NULL,
                aiWritingAcknowledgeFlag TINYINT(1) NOT NULL DEFAULT 0,
                access_token VARCHAR(255) NULL,
                token_expires_at DATETIME NULL,
                customerName VARCHAR(255) NULL,
                agreedPrice DOUBLE NULL,
                deadline DATE NULL,
                accessCode VARCHAR(50) NULL,
                sourceOrderID INT NULL,
                sourceOrderNumber VARCHAR(64) NULL,
                sourceProductID INT NULL,
                linkedProductName VARCHAR(255) NULL,
                photoReferencePath VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_custom_orders_userID (userID),
                INDEX idx_custom_orders_status (status)
            )
        ");
    }

    $userColumnCheck = $conn->query("SHOW COLUMNS FROM custom_orders LIKE 'userID'");
    if ($userColumnCheck && $userColumnCheck->num_rows > 0) {
        $conn->query("ALTER TABLE custom_orders MODIFY COLUMN userID INT(11) NULL");
    }

    $columnChecks = [
        'photoReferencePath' => "ALTER TABLE custom_orders ADD COLUMN photoReferencePath VARCHAR(255) NULL AFTER accessCode",
        'access_token' => "ALTER TABLE custom_orders ADD COLUMN access_token VARCHAR(255) NULL AFTER aiWritingAcknowledgeFlag",
        'token_expires_at' => "ALTER TABLE custom_orders ADD COLUMN token_expires_at DATETIME NULL AFTER access_token",
        'created_at' => "ALTER TABLE custom_orders ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'customerName' => "ALTER TABLE custom_orders ADD COLUMN customerName VARCHAR(255) NULL AFTER aiWritingAcknowledgeFlag",
        'sourceOrderID' => "ALTER TABLE custom_orders ADD COLUMN sourceOrderID INT NULL AFTER accessCode",
        'sourceOrderNumber' => "ALTER TABLE custom_orders ADD COLUMN sourceOrderNumber VARCHAR(64) NULL AFTER sourceOrderID",
        'sourceProductID' => "ALTER TABLE custom_orders ADD COLUMN sourceProductID INT NULL AFTER sourceOrderNumber",
        'linkedProductName' => "ALTER TABLE custom_orders ADD COLUMN linkedProductName VARCHAR(255) NULL AFTER sourceProductID",
    ];

    foreach ($columnChecks as $column => $sql) {
        $safeColumn = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM custom_orders LIKE '{$safeColumn}'");
        if ($result && $result->num_rows === 0) {
            $conn->query($sql);
        }
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS custom_order_messages (
            messageID INT AUTO_INCREMENT PRIMARY KEY,
            customOrderID INT NOT NULL,
            senderRole VARCHAR(20) NOT NULL,
            senderUserID INT NULL,
            messageBody TEXT NOT NULL,
            createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_custom_order_messages_order (customOrderID),
            INDEX idx_custom_order_messages_created (createdAt)
        )
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS custom_order_offers (
            offerID INT AUTO_INCREMENT PRIMARY KEY,
            customOrderID INT NOT NULL,
            offeredByUserID INT NULL,
            offeredPrice DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            proposedDeadline DATE NULL,
            offerNote TEXT NULL,
            offerStatus VARCHAR(20) NOT NULL DEFAULT 'pending',
            createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            respondedAt DATETIME NULL,
            INDEX idx_custom_order_offers_order (customOrderID),
            INDEX idx_custom_order_offers_status (offerStatus)
        )
    ");

    $checked = true;
}

function storeCustomOrderReferencePhoto(array $file): string
{
    if (empty($file) || !isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Please upload a valid reference photo.');
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new InvalidArgumentException('Uploaded photo could not be verified.');
    }

    $mimeType = (string)(mime_content_type($tmpPath) ?: '');
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'jpg',
        'image/webp' => 'jpg',
        'image/gif' => 'jpg',
    ];
    if (!isset($allowed[$mimeType])) {
        throw new InvalidArgumentException('Photo must be a JPG, PNG, WEBP, or GIF image.');
    }

    $maxBytes = 4 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        throw new InvalidArgumentException('Photo must be 4MB or smaller.');
    }

    $projectRoot = dirname(__DIR__);
    $relativeDir = 'uploads/assets/images/custom_orders/';
    $targetDir = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Could not create the custom order upload folder.');
    }

    $filename = 'custom_order_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.jpg';
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
    if (!app_image_convert_file_to_jpeg($tmpPath, $targetPath, 1600, 1600, 84)) {
        throw new RuntimeException('Could not convert the uploaded photo to JPG.');
    }

    return $relativeDir . $filename;
}

function deleteCustomOrderReferencePhoto(string $relativePath): void
{
    $relativePath = trim(str_replace('\\', '/', $relativePath));
    if ($relativePath === '') {
        return;
    }

    $projectRoot = str_replace('\\', '/', dirname(__DIR__));
    $fullPath = str_replace('\\', '/', $projectRoot . '/' . ltrim($relativePath, '/'));
    if (strpos($fullPath, $projectRoot . '/') !== 0) {
        return;
    }

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function logCustomOrderAction($conn, $customOrderId, $actionType, $message)
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $detailsJSON = json_encode(['message' => $message]);
    $stmt = $conn->prepare("
        INSERT INTO audit_logs (userID, role, actionType, entityType, entityID, ipAddress, detailsJSON)
        VALUES (NULL, 'system', ?, 'custom_order', ?, ?, ?)
    ");
    if ($stmt) {
        $stmt->bind_param('siss', $actionType, $customOrderId, $ip, $detailsJSON);
        $stmt->execute();
        $stmt->close();
    }
}

function notifyAdminNewCustomOrder($conn, $customOrderId, $customerEmail, $description)
{
    $message = "New custom order request #$customOrderId from $customerEmail: " . substr($description, 0, 100) . '...';
    createAdminNotification($conn, $message);
    sendCustomOrderAdminEmail(
        $conn,
        "New custom order request #{$customOrderId}",
        "A customer submitted a new website custom order request.\n\n" .
        "Custom Order: #{$customOrderId}\n" .
        "Customer email: {$customerEmail}\n\n" .
        "Request preview:\n" . trim(substr($description, 0, 1200)) . "\n\n" .
        "Open in admin:\n" . customOrderAdminUrl((int)$customOrderId)
    );
}

function createAdminNotification($conn, $message)
{
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

    $stmt = $conn->prepare('INSERT INTO admin_notifications (message) VALUES (?)');
    if ($stmt) {
        $stmt->bind_param('s', $message);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log('Failed to create admin notification: ' . $conn->error);
    }
}

function customOrderCustomerUrl(int $customOrderId): string
{
    $path = 'custom_order.php?view=' . max(0, $customOrderId) . '#discussion';
    if (function_exists('app_url')) {
        return app_url($path);
    }
    return '/' . ltrim($path, '/');
}

function customOrderAdminUrl(int $customOrderId): string
{
    $path = 'modules/admin/custom_orders.php?view=' . max(0, $customOrderId);
    if (function_exists('app_url')) {
        return app_url($path);
    }
    return '/' . ltrim($path, '/');
}

function sendCustomOrderPlainEmail(string $toEmail, string $toName, string $subject, string $body): bool
{
    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if (!function_exists('app_auth_send_plaintext_email')) {
        require_once __DIR__ . '/../authentication/auth_mailer.php';
    }

    $result = app_auth_send_plaintext_email($toEmail, $toName, $subject, $body);
    return !empty($result['success']);
}

function customOrderAdminRecipients(mysqli $conn): array
{
    $recipients = [];
    $seen = [];

    $res = $conn->query("
        SELECT full_name, email
        FROM users
        WHERE LOWER(role) IN ('admin','administrator','superadmin')
          AND email IS NOT NULL
          AND email <> ''
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $email = strtolower(trim((string)($row['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $recipients[] = [
                'name' => trim((string)($row['full_name'] ?? 'Admin')),
                'email' => $email,
            ];
        }
    }

    $fallbacks = [];
    if (function_exists('app_env_value')) {
        $fallbacks[] = app_env_value('CUSTOM_ORDER_ADMIN_EMAIL', '');
        $fallbacks[] = app_env_value('ADMIN_NOTIFICATION_EMAIL', '');
        $fallbacks[] = app_env_value('SMTP_FROM_EMAIL', '');
        $fallbacks[] = app_env_value('SMTP_USERNAME', '');
    }

    foreach ($fallbacks as $fallbackEmail) {
        $email = strtolower(trim((string)$fallbackEmail));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) {
            continue;
        }
        $seen[$email] = true;
        $recipients[] = ['name' => 'Admin', 'email' => $email];
    }

    return $recipients;
}

function sendCustomOrderAdminEmail(mysqli $conn, string $subject, string $body): array
{
    $sent = 0;
    $failed = 0;
    foreach (customOrderAdminRecipients($conn) as $recipient) {
        if (sendCustomOrderPlainEmail($recipient['email'], $recipient['name'], '[Custom Orders] ' . $subject, $body)) {
            $sent++;
        } else {
            $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}

function sendCustomOrderCustomerEmail(mysqli $conn, int $customOrderId, string $subject, string $body): bool
{
    try {
        $order = getCustomOrderById($conn, $customOrderId);
    } catch (Throwable $e) {
        return false;
    }

    $email = normalizeCustomerEmail((string)($order['email'] ?? ''));
    $name = trim((string)($order['displayName'] ?? $order['customerName'] ?? 'Customer'));
    if ($name === '') {
        $name = 'Customer';
    }

    return sendCustomOrderPlainEmail($email, $name, $subject, $body);
}

function sendCustomOrderOfferEmail(mysqli $conn, int $customOrderId, float $price, ?string $deadline, ?string $note = null): bool
{
    $deadline = trim((string)$deadline);
    $note = trim((string)$note);
    $body =
        "Hello,\n\n" .
        "Athina reviewed your custom order request and sent you an offer.\n\n" .
        "Custom Order: #{$customOrderId}\n" .
        "Proposed price: EUR " . number_format($price, 2) . "\n" .
        ($deadline !== '' ? "Target date: {$deadline}\n" : '') .
        ($note !== '' ? "\nMessage from the shop:\n{$note}\n" : '') .
        "\nYou can accept, decline, or reply here:\n" . customOrderCustomerUrl($customOrderId) . "\n\n" .
        "Thank you,\nAthina E-Shop";

    return sendCustomOrderCustomerEmail($conn, $customOrderId, 'Your custom order offer is ready', $body);
}

function sendCustomOrderStatusEmail(mysqli $conn, int $customOrderId, string $status, string $note = ''): bool
{
    $labels = getCustomOrderStatusLabels();
    $statusLabel = $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
    $note = trim($note);
    $body =
        "Hello,\n\n" .
        "Your custom order #{$customOrderId} status was updated to: {$statusLabel}.\n\n" .
        ($note !== '' ? $note . "\n\n" : '') .
        "You can view the request here:\n" . customOrderCustomerUrl($customOrderId) . "\n\n" .
        "Thank you,\nAthina E-Shop";

    return sendCustomOrderCustomerEmail($conn, $customOrderId, "Custom order #{$customOrderId} update", $body);
}
?>
