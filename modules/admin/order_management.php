<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'order_management';
$flash = '';

if (empty($_SESSION['admin_order_token'])) {
    $_SESSION['admin_order_token'] = bin2hex(random_bytes(32));
}

$statusLabels = [
    'pending'       => 'Pending',
    'accepted'      => 'Pending',
    'in_production' => 'In Production',
    'shipped'       => 'Shipped',
    'delivered'     => 'Completed (Delivered)',
    'completed'     => 'Completed (Delivered)',
    'cancelled'     => 'Cancelled',
];
$statusUpdateOptions = [
    'pending'       => 'Pending',
    'in_production' => 'In Production',
    'shipped'       => 'Shipped',
    'completed'     => 'Completed (Delivered)',
    'cancelled'     => 'Cancelled',
];
$statusBadge = [
    'pending'       => 'badge-muted',
    'accepted'      => 'badge-muted',
    'in_production' => 'badge-orange',
    'shipped'       => 'badge-purple',
    'delivered'     => 'badge-completed',
    'completed'     => 'badge-completed',
    'cancelled'     => 'badge-red',
];

// ----------------------------------------------------------------------
// PHPMailer autoloader (robust, checks multiple paths)
// ----------------------------------------------------------------------
function loadPHPMailer(): bool {
    static $loaded = false;
    if ($loaded) return true;

    // First try Composer autoload
    $composerAutoload = __DIR__ . '/../../vendor/autoload.php';
    if (file_exists($composerAutoload)) {
        require_once $composerAutoload;
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $loaded = true;
            return true;
        }
    }

    // Manual paths for PHPMailer
    $phpmailerPaths = [
        __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php',
        __DIR__ . '/../../PHPMailer/PHPMailer.php',
        __DIR__ . '/../PHPMailer-master/src/PHPMailer.php',
        __DIR__ . '/../PHPMailer/PHPMailer.php',
    ];

    $found = false;
    foreach ($phpmailerPaths as $path) {
        if (file_exists($path)) {
            $baseDir = dirname($path);
            $exceptionPath = $baseDir . '/Exception.php';
            $smtpPath = $baseDir . '/SMTP.php';
            if (file_exists($exceptionPath)) require_once $exceptionPath;
            if (file_exists($smtpPath)) require_once $smtpPath;
            require_once $path;
            $found = true;
            break;
        }
    }

    if ($found && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        $loaded = true;
        return true;
    }

    error_log("PHPMailer not found. Checked paths: " . implode(', ', $phpmailerPaths));
    return false;
}
// ----------------------------------------------------------------------

function ensureOrderShippingSchema(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $requiredColumns = [
        'shippingAddress' => "ALTER TABLE orders ADD COLUMN shippingAddress VARCHAR(255) NULL AFTER email",
        'shippingCity' => "ALTER TABLE orders ADD COLUMN shippingCity VARCHAR(120) NULL AFTER shippingAddress",
        'shippingPostalCode' => "ALTER TABLE orders ADD COLUMN shippingPostalCode VARCHAR(20) NULL AFTER shippingCity",
        'shippingCountry' => "ALTER TABLE orders ADD COLUMN shippingCountry VARCHAR(120) NULL AFTER shippingPostalCode",
        'shippingLabel' => "ALTER TABLE orders ADD COLUMN shippingLabel VARCHAR(120) NULL AFTER shippingCountry",
        'courierCode' => "ALTER TABLE orders ADD COLUMN courierCode VARCHAR(60) NULL AFTER shippingLabel",
        'shippingPriority' => "ALTER TABLE orders ADD COLUMN shippingPriority VARCHAR(20) NULL AFTER courierCode",
        'fulfillmentMode' => "ALTER TABLE orders ADD COLUMN fulfillmentMode VARCHAR(20) NULL AFTER shippingPriority",
    ];

    foreach ($requiredColumns as $columnName => $alterSql) {
        $safeCol = $conn->real_escape_string($columnName);
        $check = $conn->query("SHOW COLUMNS FROM orders LIKE '{$safeCol}'");
        $exists = ($check && $check->num_rows > 0);
        if (!$exists) {
            $conn->query($alterSql);
        }
    }
}

function tableExists(mysqli $conn, string $tableName): bool {
    $safe = $conn->real_escape_string($tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function fallbackShippingLabelForUser(mysqli $conn, int $userId): string {
    if ($userId <= 0 || !tableExists($conn, 'user_addresses')) {
        return '';
    }

    $st = $conn->prepare("
        SELECT label
        FROM user_addresses
        WHERE user_id = ?
          AND TRIM(COALESCE(label, '')) <> ''
        ORDER BY is_default DESC, created_at DESC, id DESC
        LIMIT 1
    ");
    if (!$st) {
        return '';
    }
    $st->bind_param('i', $userId);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return trim((string)($row['label'] ?? ''));
}

function courierLabelFromCode(string $courierCode): string {
    $map = [
        'akis_express' => 'Akis Express',
        'boxnow' => 'BoxNow',
        'acs' => 'ACS',
    ];
    $key = strtolower(trim($courierCode));
    return $map[$key] ?? ($courierCode !== '' ? $courierCode : 'Not specified');
}

function inferCourierCode(string $courierCode, string $shipmentCourierName): string {
    $code = strtolower(trim($courierCode));
    if ($code !== '') {
        return $code;
    }
    $probe = strtolower($shipmentCourierName);
    if (strpos($probe, 'boxnow') !== false) {
        return 'boxnow';
    }
    if (strpos($probe, 'acs') !== false) {
        return 'acs';
    }
    if (strpos($probe, 'akis') !== false) {
        return 'akis_express';
    }
    return '';
}

function makeTrackingCode(string $courierCode, string $orderNumber): string {
    $prefixMap = [
        'akis_express' => 'AKI',
        'boxnow' => 'BXN',
        'acs' => 'ACS',
    ];
    $normalized = strtolower(trim($courierCode));
    $prefix = $prefixMap[$normalized] ?? 'TRK';
    $orderPart = strtoupper(preg_replace('/[^A-Z0-9]/', '', $orderNumber));
    if ($orderPart === '') {
        $orderPart = 'ORDER';
    }
    return $prefix . '-' . substr($orderPart, -6) . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function ensureShipmentTracking(mysqli $conn, int $orderId, string $orderNumber, string $courierCode, string $shipmentCourierName): string {
    if ($orderId <= 0) {
        return '';
    }

    // Ensure shipments table exists
    $conn->query("CREATE TABLE IF NOT EXISTS shipments (
        shipmentID INT AUTO_INCREMENT PRIMARY KEY,
        orderID INT NOT NULL,
        courierName VARCHAR(120),
        shippingCost DECIMAL(10,2),
        trackingCode VARCHAR(100),
        FOREIGN KEY (orderID) REFERENCES orders(orderID) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $resolvedCode = inferCourierCode($courierCode, $shipmentCourierName);
    $resolvedLabel = courierLabelFromCode($resolvedCode);

    $row = null;
    $sel = $conn->prepare("SELECT shipmentID, trackingCode FROM shipments WHERE orderID = ? LIMIT 1");
    if ($sel) {
        $sel->bind_param('i', $orderId);
        $sel->execute();
        $res = $sel->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $sel->close();
    }

    if ($row && !empty($row['trackingCode'])) {
        return (string)$row['trackingCode'];
    }

    $trackingCode = makeTrackingCode($resolvedCode, $orderNumber);

    if ($row) {
        $shipmentId = (int)$row['shipmentID'];
        $upd = $conn->prepare("UPDATE shipments SET trackingCode = ?, courierName = COALESCE(NULLIF(courierName, ''), ?) WHERE shipmentID = ?");
        if ($upd) {
            $upd->bind_param('ssi', $trackingCode, $resolvedLabel, $shipmentId);
            $upd->execute();
            $upd->close();
        }
    } else {
        $ins = $conn->prepare("INSERT INTO shipments (orderID, courierName, shippingCost, trackingCode) VALUES (?, ?, 0, ?)");
        if ($ins) {
            $ins->bind_param('iss', $orderId, $resolvedLabel, $trackingCode);
            $ins->execute();
            $ins->close();
        }
    }

    return $trackingCode;
}

function fetchOrderStatusContext(mysqli $conn, int $orderId): ?array {
    if ($orderId <= 0) {
        return null;
    }

    $sql = "
        SELECT
            o.orderID,
            o.orderNumber,
            o.status,
            o.email,
            o.shippingAddress,
            o.shippingCity,
            o.shippingPostalCode,
            o.shippingCountry,
            o.shippingLabel,
            o.courierCode,
            o.shippingPriority,
            COALESCE(NULLIF(u.full_name, ''), 'Customer') AS customerName,
            COALESCE(NULLIF(u.email, ''), o.email, '') AS customerEmail,
            COALESCE(s.courierName, '') AS shipmentCourierName,
            COALESCE(s.trackingCode, '') AS trackingCode
        FROM orders o
        LEFT JOIN users u ON u.userID = o.userID
        LEFT JOIN shipments s ON s.orderID = o.orderID
        WHERE o.orderID = ?
        LIMIT 1
    ";
    $st = $conn->prepare($sql);
    if (!$st) {
        return null;
    }
    $st->bind_param('i', $orderId);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return $row ?: null;
}

function sendOrderStatusEmails(mysqli $conn, array $orderContext, string $statusLabel, bool $sendCustomer = true): array {
    if (!loadPHPMailer()) {
        error_log("PHPMailer not available for status emails");
        return ['sent_customer' => false, 'sent_admins' => 0, 'failed_admins' => 0];
    }

    $orderNumber = (string)($orderContext['orderNumber'] ?? ('#' . (int)($orderContext['orderID'] ?? 0)));
    $customerName = trim((string)($orderContext['customerName'] ?? 'Customer'));
    $customerEmail = trim((string)($orderContext['customerEmail'] ?? ''));
    $courierCode = (string)($orderContext['courierCode'] ?? '');
    $shipmentCourierName = (string)($orderContext['shipmentCourierName'] ?? '');
    $courierCode = inferCourierCode($courierCode, $shipmentCourierName);
    $courierLabel = courierLabelFromCode($courierCode);
    $shippingPriority = trim((string)($orderContext['shippingPriority'] ?? 'standard'));
    $trackingCode = trim((string)($orderContext['trackingCode'] ?? ''));
    $shippingAddress = trim((string)($orderContext['shippingAddress'] ?? ''));
    $shippingCity = trim((string)($orderContext['shippingCity'] ?? ''));
    $shippingPostal = trim((string)($orderContext['shippingPostalCode'] ?? ''));
    $shippingCountry = trim((string)($orderContext['shippingCountry'] ?? ''));

    $shippingLine = trim($shippingAddress . ', ' . $shippingCity . ', ' . $shippingPostal . ', ' . $shippingCountry, " ,");
    if ($shippingLine === '') {
        $shippingLine = 'Not provided';
    }
    if ($trackingCode === '') {
        $trackingCode = 'Not assigned yet';
    }

    $subject = "Order Update {$orderNumber}: {$statusLabel}";
    $body = "Order Number: {$orderNumber}\n" .
        "Status: {$statusLabel}\n" .
        "Courier: {$courierLabel} ({$shippingPriority})\n" .
        "Tracking Number: {$trackingCode}\n" .
        "Shipping Address: {$shippingLine}\n\n" .
        "Thank you,\nAthina E-Shop";

    $adminRecipients = [];
    $adminRes = $conn->query("
        SELECT full_name, email
        FROM users
        WHERE LOWER(role) IN ('admin','administrator','superadmin')
          AND email IS NOT NULL AND email <> ''
    ");
    if ($adminRes) {
        while ($row = $adminRes->fetch_assoc()) {
            $adminRecipients[] = [
                'name' => trim((string)($row['full_name'] ?? 'Admin')),
                'email' => trim((string)($row['email'] ?? '')),
            ];
        }
    }

    $sentCustomer = false;
    $sentAdmins = 0;
    $failedAdmins = 0;
    $transports = [
        ['port' => 587, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS],
        ['port' => 465, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS],
    ];

    $deliver = function (string $toEmail, string $toName, string $subjectText, string $bodyText) use ($transports): bool {
        foreach ($transports as $transport) {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->SMTPDebug = 0;
                $mail->isSMTP();
                $mail->Host = 'premium245.web-hosting.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'admin@festival-web.com';
                $mail->Password = '!g3$~8tYju*D';
                $mail->SMTPSecure = $transport['secure'];
                $mail->Port = (int)$transport['port'];
                $mail->Timeout = 20;
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ];

                $mail->setFrom('admin@festival-web.com', 'Athina E-Shop');
                $mail->addAddress($toEmail, $toName !== '' ? $toName : 'Customer');
                $mail->CharSet = 'UTF-8';
                $mail->isHTML(false);
                $mail->Subject = $subjectText;
                $mail->Body = $bodyText;
                $mail->send();
                return true;
            } catch (\Throwable $e) {
                error_log('Status email send failure: ' . $e->getMessage());
            }
        }
        return false;
    };

    if ($sendCustomer && $customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $sentCustomer = $deliver($customerEmail, $customerName, $subject, "Hello {$customerName},\n\n" . $body);
    }

    foreach ($adminRecipients as $admin) {
        if ($admin['email'] === '' || !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $ok = $deliver($admin['email'], $admin['name'], '[Admin] ' . $subject, "Admin notification:\n\n" . $body);
        if ($ok) {
            $sentAdmins++;
        } else {
            $failedAdmins++;
        }
    }

    return [
        'sent_customer' => $sentCustomer,
        'sent_admins' => $sentAdmins,
        'failed_admins' => $failedAdmins,
    ];
}

function buildOrderShippingLine(array $orderContext): string {
    $shippingAddress = trim((string)($orderContext['shippingAddress'] ?? ''));
    $shippingCity = trim((string)($orderContext['shippingCity'] ?? ''));
    $shippingPostal = trim((string)($orderContext['shippingPostalCode'] ?? ''));
    $shippingCountry = trim((string)($orderContext['shippingCountry'] ?? ''));
    $shippingLabel = trim((string)($orderContext['shippingLabel'] ?? ''));

    $parts = array_filter([
        $shippingAddress,
        $shippingCity,
        $shippingPostal,
        $shippingCountry,
    ], static fn($value): bool => trim((string)$value) !== '');

    if ($shippingLabel !== '') {
        $parts[] = $shippingLabel;
    }

    return !empty($parts) ? implode(', ', $parts) : 'Not provided';
}

function sendOrderMetaUpdateEmails(mysqli $conn, array $orderContext, string $subjectSuffix, string $updateBody): array {
    if (!loadPHPMailer()) {
        error_log("PHPMailer not available for meta update emails");
        return ['sent_customer' => false, 'sent_admins' => 0, 'failed_admins' => 0];
    }

    $orderNumber = (string)($orderContext['orderNumber'] ?? ('#' . (int)($orderContext['orderID'] ?? 0)));
    $customerName = trim((string)($orderContext['customerName'] ?? 'Customer'));
    $customerEmail = trim((string)($orderContext['customerEmail'] ?? ''));
    $shippingLine = buildOrderShippingLine($orderContext);
    $trackingCode = trim((string)($orderContext['trackingCode'] ?? ''));
    if ($trackingCode === '') {
        $trackingCode = 'Not assigned yet';
    }

    $subject = "Order Update {$orderNumber}: {$subjectSuffix}";
    $body = $updateBody . "\n\n" .
        "Order Number: {$orderNumber}\n" .
        "Shipping Address: {$shippingLine}\n" .
        "Tracking Number: {$trackingCode}\n\n" .
        "Thank you,\nAthina E-Shop";

    $adminRecipients = [];
    $adminRes = $conn->query("
        SELECT full_name, email
        FROM users
        WHERE LOWER(role) IN ('admin','administrator','superadmin')
          AND email IS NOT NULL AND email <> ''
    ");
    if ($adminRes) {
        while ($row = $adminRes->fetch_assoc()) {
            $adminRecipients[] = [
                'name' => trim((string)($row['full_name'] ?? 'Admin')),
                'email' => trim((string)($row['email'] ?? '')),
            ];
        }
    }

    $sentCustomer = false;
    $sentAdmins = 0;
    $failedAdmins = 0;
    $transports = [
        ['port' => 587, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS],
        ['port' => 465, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS],
    ];

    $deliver = function (string $toEmail, string $toName, string $subjectText, string $bodyText) use ($transports): bool {
        foreach ($transports as $transport) {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->SMTPDebug = 0;
                $mail->isSMTP();
                $mail->Host = 'premium245.web-hosting.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'admin@festival-web.com';
                $mail->Password = '!g3$~8tYju*D';
                $mail->SMTPSecure = $transport['secure'];
                $mail->Port = (int)$transport['port'];
                $mail->Timeout = 20;
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ];

                $mail->setFrom('admin@festival-web.com', 'Athina E-Shop');
                $mail->addAddress($toEmail, $toName !== '' ? $toName : 'Customer');
                $mail->CharSet = 'UTF-8';
                $mail->isHTML(false);
                $mail->Subject = $subjectText;
                $mail->Body = $bodyText;
                $mail->send();
                return true;
            } catch (\Throwable $e) {
                error_log('Order meta update email send failure: ' . $e->getMessage());
            }
        }
        return false;
    };

    if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $sentCustomer = $deliver($customerEmail, $customerName, $subject, "Hello {$customerName},\n\n" . $body);
    }

    foreach ($adminRecipients as $admin) {
        if ($admin['email'] === '' || !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $ok = $deliver($admin['email'], $admin['name'], '[Admin] ' . $subject, "Admin notification:\n\n" . $body);
        if ($ok) {
            $sentAdmins++;
        } else {
            $failedAdmins++;
        }
    }

    return [
        'sent_customer' => $sentCustomer,
        'sent_admins' => $sentAdmins,
        'failed_admins' => $failedAdmins,
    ];
}

ensureOrderShippingSchema($conn);

function buildGuestReviewKeyForOrder(int $orderId, string $orderNumber, string $email): string {
    $payload = $orderId . "|" . strtolower(trim($email)) . "|" . trim($orderNumber);
    return hash_hmac("sha256", $payload, "athina_guest_review_v1");
}

function isOrderPaymentConfirmed(mysqli $conn, int $orderId): bool {
    if ($orderId <= 0) {
        return false;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1
         FROM payments
         WHERE orderID = ?
           AND LOWER(paymentStatus) IN ('paid', 'completed', 'captured', 'succeeded', 'confirmed')
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $orderId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ok = ($res && mysqli_num_rows($res) > 0);
    mysqli_stmt_close($stmt);
    return $ok;
}

function fetchOrderReviewInviteContext(mysqli $conn, int $orderId): ?array {
    if ($orderId <= 0) {
        return null;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            o.orderID,
            o.orderNumber,
            o.userID,
            o.createdAt AS orderDate,
            o.email AS orderEmail,
            COALESCE(NULLIF(TRIM(u.email), ''), o.email, '') AS recipientEmail,
            COALESCE(NULLIF(TRIM(u.full_name), ''), 'Customer') AS customerName
         FROM orders o
         LEFT JOIN users u ON u.userID = o.userID
         WHERE o.orderID = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $orderId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function buildProjectBasePath(): string {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = rtrim(str_replace('\\', '/', dirname(dirname(dirname($script)))), '/');
    if ($base === '/' || $base === '.' || $base === '') {
        return '';
    }
    return $base;
}

function buildReviewInviteUrl(array $ctx): string {
    $orderId = (int)($ctx['orderID'] ?? 0);
    $orderNumber = (string)($ctx['orderNumber'] ?? '');
    $orderEmail = (string)($ctx['orderEmail'] ?? '');
    $userId = (int)($ctx['userID'] ?? 0);

    $params = ['order_id' => (string)$orderId];
    if ($userId <= 0 && $orderId > 0 && $orderNumber !== '' && $orderEmail !== '') {
        $params['review_key'] = buildGuestReviewKeyForOrder($orderId, $orderNumber, $orderEmail);
    }

    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $path = buildProjectBasePath() . '/submit_product_review.php?' . http_build_query($params) . '#spr-form';

    if ($host !== '') {
        return $scheme . '://' . $host . $path;
    }
    return $path;
}

function ensureOrderReviewNotificationTable(mysqli $conn): void {
    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS order_review_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            orderID INT NOT NULL UNIQUE,
            recipientEmail VARCHAR(255) NOT NULL,
            sentAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function orderReviewNotificationAlreadySent(mysqli $conn, int $orderId): bool {
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM order_review_notifications WHERE orderID = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $orderId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $exists = ($res && mysqli_num_rows($res) > 0);
    mysqli_stmt_close($stmt);
    return $exists;
}

function markOrderReviewNotificationSent(mysqli $conn, int $orderId, string $recipientEmail): void {
    $stmt = mysqli_prepare($conn, "INSERT INTO order_review_notifications (orderID, recipientEmail) VALUES (?, ?) ON DUPLICATE KEY UPDATE recipientEmail = VALUES(recipientEmail), sentAt = CURRENT_TIMESTAMP");
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'is', $orderId, $recipientEmail);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// ========== HTML Review Invitation Email (Styled like original) ==========
function sendReviewInvitationEmailHTML(array $payload): array {
    if (!loadPHPMailer()) {
        return ['sent' => false, 'error' => 'PHPMailer not available'];
    }

    $toEmail = trim((string)($payload['to_email'] ?? ''));
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'error' => 'invalid recipient'];
    }

    $customerName = trim((string)($payload['customer_name'] ?? 'Customer'));
    if ($customerName === '') {
        $customerName = 'Customer';
    }
    $orderNumber = trim((string)($payload['order_number'] ?? ''));
    $orderId = (int)($payload['order_id'] ?? 0);
    $products = $payload['products'] ?? [];
    $reviewKey = $payload['review_key'] ?? '';
    $orderDate = date('F j, Y', strtotime($payload['order_date'] ?? 'now'));

    if (empty($products)) {
        return ['sent' => false, 'error' => 'No products to review'];
    }

    $productListHtml = '';
    $siteUrl = buildProjectBasePath();
    foreach ($products as $product) {
        $productId = (int)($product['product_id'] ?? 0);
        $productName = htmlspecialchars($product['name'] ?? 'Product');
        $reviewLink = $siteUrl . '/submit_product_review.php?order_id=' . $orderId . '&product_id=' . $productId;
        if ($reviewKey !== '') {
            $reviewLink .= '&review_key=' . urlencode($reviewKey);
        }
        $productListHtml .= '
        <tr>
            <td style="padding: 12px; border-bottom: 1px solid #ede2ff;">
                <strong>' . $productName . '</strong>
             </td>
            <td style="padding: 12px; text-align: center; border-bottom: 1px solid #ede2ff;">
                <a href="' . $reviewLink . '" style="display: inline-block; background: #6a1b9a; color: #fff; padding: 8px 16px; text-decoration: none; border-radius: 30px; font-size: 14px;">Write a Review</a>
             </td>
         </tr>';
    }

    $subject = "We'd love your feedback on order #{$orderNumber}";
    $bodyHtml = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Review Request</title>
    <style>
        body { font-family: "Helvetica Neue", Arial, sans-serif; line-height: 1.6; color: #333; background: #faf5ff; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 24px; box-shadow: 0 8px 20px rgba(0,0,0,0.05); overflow: hidden; }
        .header { background: linear-gradient(135deg, #f8e1ff 0%, #e9d4ff 100%); padding: 30px 20px; text-align: center; border-bottom: 2px solid #d9b8ff; }
        .header h1 { margin: 0; font-size: 28px; color: #6a1b9a; letter-spacing: -0.5px; }
        .header p { margin: 5px 0 0; color: #8a6aad; font-size: 14px; }
        .content { padding: 30px 25px; }
        .order-details { background: #fef9ff; border-left: 4px solid #c9a9f5; padding: 15px; margin: 20px 0; border-radius: 12px; }
        .order-details p { margin: 5px 0; }
        .product-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .product-table th { background: #f5edff; text-align: left; padding: 12px; font-weight: 600; color: #4a2f6e; }
        .footer { background: #f9f3ff; padding: 20px; text-align: center; font-size: 12px; color: #8a6aad; border-top: 1px solid #e6d6ff; }
        .button { display: inline-block; background: #6a1b9a; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 30px; margin-top: 10px; }
        .discount-note { background: #fef9ff; border-left: 4px solid #c9a9f5; padding: 15px; margin: 20px 0; border-radius: 12px; text-align: center; color: #6a1b9a; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Creations by Athina</h1>
        <p>Handmade with love</p>
    </div>
    <div class="content">
        <p>Hello ' . htmlspecialchars($customerName) . ',</p>
        <p>Thank you for your order <strong>#' . htmlspecialchars($orderNumber) . '</strong> (placed on ' . htmlspecialchars($orderDate) . '). We hope you\'re enjoying your items!</p>
        <div class="order-details">
            <p><strong>Order Number:</strong> ' . htmlspecialchars($orderNumber) . '</p>
            <p><strong>Order Date:</strong> ' . htmlspecialchars($orderDate) . '</p>
        </div>

        <p>We’d love to hear what you think. Your feedback helps us improve and helps other customers make informed choices.</p>
        <p>Please click the button next to each product to leave a review:</p>

        <table class="product-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th style="text-align:center;">Action</th>
                </tr>
            </thead>
            <tbody>
                ' . $productListHtml . '
            </tbody>
        </table>

        <div class="discount-note">
            <p><strong>Special thanks!</strong> As a thank you, you\'ll receive a special discount code after your review.</p>
        </div>

        <p>If you have any issues, feel free to reply to this email or contact us at <a href="mailto:admin@festival-web.com">admin@festival-web.com</a>.</p>
    </div>
    <div class="footer">
        <p>Creations by Athina — Handmade with love</p>
        <p><a href="mailto:admin@festival-web.com">admin@festival-web.com</a> | +30 123 456 7890</p>
        <p><a href="' . htmlspecialchars(buildProjectBasePath() . '/contact.php') . '">Contact Us</a></p>
    </div>
</div>
</body>
</html>';

    $plainBody = "Hello {$customerName},\n\n"
        . "Thank you for your order #{$orderNumber} (placed on {$orderDate}). We hope you're enjoying your items!\n\n"
        . "We'd love to hear what you think. Please leave a review for each product:\n\n";
    foreach ($products as $product) {
        $reviewLink = buildProjectBasePath() . '/submit_product_review.php?order_id=' . $orderId . '&product_id=' . $product['product_id'];
        if ($reviewKey !== '') $reviewLink .= '&review_key=' . $reviewKey;
        $plainBody .= "- {$product['name']}: {$reviewLink}\n";
    }
    $plainBody .= "\nAs a thank you, you'll receive a special discount code after your review.\n"
        . "If you have any issues, contact us at admin@festival-web.com.\n\n"
        . "Creations by Athina";

    $transports = [
        ['port' => 587, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS, 'label' => '587/STARTTLS'],
        ['port' => 465, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS, 'label' => '465/SMTPS'],
    ];
    $attemptErrors = [];

    foreach ($transports as $transport) {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->SMTPDebug = 0;
            $mail->isSMTP();
            $mail->Host = 'premium245.web-hosting.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'admin@festival-web.com';
            $mail->Password = '!g3$~8tYju*D';
            $mail->SMTPSecure = $transport['secure'];
            $mail->Port = (int)$transport['port'];
            $mail->Timeout = 20;
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];

            $mail->setFrom('admin@festival-web.com', 'Creations by Athina');
            $mail->addAddress($toEmail, $customerName);
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $bodyHtml;
            $mail->AltBody = $plainBody;
            $mail->send();
            return ['sent' => true, 'error' => ''];
        } catch (\Throwable $e) {
            $detail = trim((string)($mail->ErrorInfo ?? ''));
            $msg = $detail !== '' ? $detail : trim((string)$e->getMessage());
            $attemptErrors[] = $transport['label'] . ': ' . ($msg !== '' ? $msg : 'send failed');
        }
    }

    $error = implode(' | ', $attemptErrors);
    return ['sent' => false, 'error' => $error !== '' ? $error : 'email delivery failed'];
}

// ========== Admin Notification for Review Request Sent ==========
function sendAdminReviewRequestNotification($conn, int $orderId, string $orderNumber, string $recipientEmail): array {
    if (!loadPHPMailer()) {
        return ['sent' => 0, 'failed' => 0];
    }

    $subject = "Review Request Sent for Order #{$orderNumber}";

    $bodyHtml = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Review Request Sent</title>
    <style>
        body { font-family: "Helvetica Neue", Arial, sans-serif; line-height: 1.6; color: #333; background: #faf5ff; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 24px; box-shadow: 0 8px 20px rgba(0,0,0,0.05); overflow: hidden; }
        .header { background: linear-gradient(135deg, #f8e1ff 0%, #e9d4ff 100%); padding: 30px 20px; text-align: center; border-bottom: 2px solid #d9b8ff; }
        .header h1 { margin: 0; font-size: 28px; color: #6a1b9a; }
        .content { padding: 30px 25px; }
        .admin-note { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 12px; color: #856404; }
        .footer { background: #f9f3ff; padding: 20px; text-align: center; font-size: 12px; color: #8a6aad; border-top: 1px solid #e6d6ff; }
        .button { display: inline-block; background: #6a1b9a; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 30px; margin-top: 10px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Review Request Sent</h1>
        <p>Order #' . htmlspecialchars($orderNumber) . '</p>
    </div>
    <div class="content">
        <p>Hello Admin,</p>
        <p>A review request email has been successfully sent to the customer for order <strong>#' . htmlspecialchars($orderNumber) . '</strong>.</p>
        <p><strong>Recipient:</strong> ' . htmlspecialchars($recipientEmail) . '</p>
        <div class="admin-note">
            <strong>📝 Next steps:</strong> No further action required. When the customer submits a review, you will receive another notification.
        </div>
        <p><a href="' . htmlspecialchars(buildProjectBasePath() . '/admin/order_management.php?view=' . $orderId) . '" class="button">View order in admin panel</a></p>
    </div>
    <div class="footer">
        <p>Creations by Athina — Handmade with love</p>
    </div>
</div>
</body>
</html>';

    $plainBody = "Admin Notification\n\n"
        . "A review request email was sent to {$recipientEmail} for order #{$orderNumber}.\n"
        . "View order: " . buildProjectBasePath() . "/admin/order_management.php?view={$orderId}";

    $adminRecipients = [];
    $adminRes = $conn->query("
        SELECT full_name, email
        FROM users
        WHERE LOWER(role) IN ('admin','administrator','superadmin')
          AND email IS NOT NULL AND email <> ''
    ");
    if ($adminRes) {
        while ($row = $adminRes->fetch_assoc()) {
            $adminRecipients[] = [
                'name' => trim((string)($row['full_name'] ?? 'Admin')),
                'email' => trim((string)($row['email'] ?? '')),
            ];
        }
    }
    if (empty($adminRecipients)) {
        $adminRecipients[] = ['name' => 'Admin', 'email' => 'admin@festival-web.com'];
    }

    $transports = [
        ['port' => 587, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS],
        ['port' => 465, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS],
    ];
    $sentCount = 0;
    $failedCount = 0;

    foreach ($adminRecipients as $admin) {
        if (empty($admin['email']) || !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) continue;
        $sent = false;
        foreach ($transports as $transport) {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->SMTPDebug = 0;
                $mail->isSMTP();
                $mail->Host = 'premium245.web-hosting.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'admin@festival-web.com';
                $mail->Password = '!g3$~8tYju*D';
                $mail->SMTPSecure = $transport['secure'];
                $mail->Port = (int)$transport['port'];
                $mail->Timeout = 20;
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ];

                $mail->setFrom('admin@festival-web.com', 'Creations by Athina');
                $mail->addAddress($admin['email'], $admin['name']);
                $mail->addBCC('chrisanton1705@gmail.com');
                $mail->CharSet = 'UTF-8';
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $bodyHtml;
                $mail->AltBody = $plainBody;
                $mail->send();
                $sent = true;
                break;
            } catch (\Throwable $e) {
                error_log('Admin review request notification failed: ' . $e->getMessage());
            }
        }
        if ($sent) $sentCount++;
        else $failedCount++;
    }

    return ['sent' => $sentCount, 'failed' => $failedCount];
}

// ========== HTML Tracking Email Functions ==========

function sendTrackingEmailHTML($conn, array $orderContext, string $trackingCode): array {
    error_log("sendTrackingEmailHTML called for order " . ($orderContext['orderID'] ?? 0));
    if (!loadPHPMailer()) {
        error_log("PHPMailer not available for tracking email");
        return ['sent' => false, 'error' => 'PHPMailer not available'];
    }

    $orderNumber = (string)($orderContext['orderNumber'] ?? ('#' . (int)($orderContext['orderID'] ?? 0)));
    $customerName = trim((string)($orderContext['customerName'] ?? 'Customer'));
    $customerEmail = trim((string)($orderContext['customerEmail'] ?? ''));
    $courierCode = (string)($orderContext['courierCode'] ?? '');
    $shipmentCourierName = (string)($orderContext['shipmentCourierName'] ?? '');
    $resolvedCourierCode = inferCourierCode($courierCode, $shipmentCourierName);
    $courierLabel = courierLabelFromCode($resolvedCourierCode);
    $shippingPriority = trim((string)($orderContext['shippingPriority'] ?? 'standard'));
    $shippingAddress = trim((string)($orderContext['shippingAddress'] ?? ''));
    $shippingCity = trim((string)($orderContext['shippingCity'] ?? ''));
    $shippingPostal = trim((string)($orderContext['shippingPostalCode'] ?? ''));
    $shippingCountry = trim((string)($orderContext['shippingCountry'] ?? ''));
    $shippingLine = implode(', ', array_filter([$shippingAddress, $shippingCity, $shippingPostal, $shippingCountry]));
    if ($shippingLine === '') $shippingLine = 'Not provided';

    // Build courier tracking URL for supported couriers only
    $trackingUrl = '';
    $trackingNumber = trim($trackingCode);
    if ($trackingNumber !== '') {
        switch ($resolvedCourierCode) {
            case 'akis_express':
                $trackingUrl = 'https://akisexpress.com.cy/track/?code=' . urlencode($trackingNumber);
                break;
            case 'acs':
                $trackingUrl = 'https://www.acscourier.net/en/track/' . urlencode($trackingNumber);
                break;
            case 'boxnow':
                $trackingUrl = 'https://boxnow.cy/en/track/' . urlencode($trackingNumber);
                break;
            default:
                $trackingUrl = '';
        }
    }

    $subject = "Your order #{$orderNumber} has been shipped!";
    $bodyHtml = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Order Shipped</title>
    <style>
        body { font-family: "Helvetica Neue", Arial, sans-serif; line-height: 1.6; color: #333; background: #faf5ff; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 24px; box-shadow: 0 8px 20px rgba(0,0,0,0.05); overflow: hidden; }
        .header { background: linear-gradient(135deg, #f8e1ff 0%, #e9d4ff 100%); padding: 30px 20px; text-align: center; border-bottom: 2px solid #d9b8ff; }
        .header h1 { margin: 0; font-size: 28px; color: #6a1b9a; letter-spacing: -0.5px; }
        .header p { margin: 5px 0 0; color: #8a6aad; font-size: 14px; }
        .content { padding: 30px 25px; }
        .tracking-box { background: #fef9ff; border-left: 4px solid #c9a9f5; padding: 15px; margin: 20px 0; border-radius: 12px; text-align: center; }
        .tracking-number { font-size: 18px; font-weight: bold; background: #f5edff; display: inline-block; padding: 8px 16px; border-radius: 30px; margin: 10px 0; }
        .button { display: inline-block; background: #6a1b9a; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 30px; margin-top: 10px; }
        .footer { background: #f9f3ff; padding: 20px; text-align: center; font-size: 12px; color: #8a6aad; border-top: 1px solid #e6d6ff; }
        .address-box { background: #fef9ff; padding: 12px; margin: 10px 0; border-radius: 12px; border: 1px solid #ede2ff; }
        .address-box h4 { margin: 0 0 8px 0; color: #6a1b9a; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Creations by Athina</h1>
        <p>Handmade with love</p>
    </div>
    <div class="content">
        <p>Hello ' . htmlspecialchars($customerName) . ',</p>
        <p>Great news! Your order <strong>#' . htmlspecialchars($orderNumber) . '</strong> is on its way.</p>
        <div class="tracking-box">
            <p><strong>Courier:</strong> ' . htmlspecialchars($courierLabel) . ' (' . htmlspecialchars($shippingPriority) . ')</p>
            <p><strong>Tracking Number:</strong></p>
            <div class="tracking-number">' . htmlspecialchars($trackingNumber) . '</div>
            ' . ($trackingUrl !== '' ? '<p><a href="' . htmlspecialchars($trackingUrl) . '" class="button" target="_blank">Track Your Order</a></p>' : '<p>Tracking number will be active shortly.</p>') . '
        </div>

        <div class="address-box">
            <h4>Shipping Address</h4>
            <p>' . nl2br(htmlspecialchars($shippingLine)) . '</p>
        </div>

        <p>You will receive an email when your order is delivered. If you have any questions, please <a href="' . htmlspecialchars(buildProjectBasePath() . '/contact.php') . '">contact us</a>.</p>
    </div>
    <div class="footer">
        <p>Creations by Athina — Handmade with love</p>
        <p><a href="mailto:admin@festival-web.com">admin@festival-web.com</a> | +30 123 456 7890</p>
        <p><a href="' . htmlspecialchars(buildProjectBasePath() . '/contact.php') . '">Contact Us</a></p>
    </div>
</div>
</body>
</html>';

    $plainBody = "Hello {$customerName},\n\n"
        . "Great news! Your order #{$orderNumber} is on its way.\n"
        . "Courier: {$courierLabel} ({$shippingPriority})\n"
        . "Tracking Number: {$trackingNumber}\n"
        . ($trackingUrl !== '' ? "Track it here: {$trackingUrl}\n" : "")
        . "Shipping Address: {$shippingLine}\n\n"
        . "You will receive an email when your order is delivered. If you have any questions, please contact us at admin@festival-web.com.\n\n"
        . "Creations by Athina";

    $transports = [
        ['port' => 587, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS, 'label' => '587/STARTTLS'],
        ['port' => 465, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS, 'label' => '465/SMTPS'],
    ];
    $attemptErrors = [];

    foreach ($transports as $transport) {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->SMTPDebug = 0;
            $mail->isSMTP();
            $mail->Host = 'premium245.web-hosting.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'admin@festival-web.com';
            $mail->Password = '!g3$~8tYju*D';
            $mail->SMTPSecure = $transport['secure'];
            $mail->Port = (int)$transport['port'];
            $mail->Timeout = 20;
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];

            $mail->setFrom('admin@festival-web.com', 'Creations by Athina');
            $mail->addAddress($customerEmail, $customerName);
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $bodyHtml;
            $mail->AltBody = $plainBody;
            $mail->send();
            error_log("Tracking email sent successfully to $customerEmail");
            return ['sent' => true, 'error' => ''];
        } catch (\Throwable $e) {
            $detail = trim((string)($mail->ErrorInfo ?? ''));
            $msg = $detail !== '' ? $detail : trim((string)$e->getMessage());
            $attemptErrors[] = $transport['label'] . ': ' . ($msg !== '' ? $msg : 'send failed');
            error_log("Tracking email send failure: " . $msg);
        }
    }

    $error = implode(' | ', $attemptErrors);
    return ['sent' => false, 'error' => $error !== '' ? $error : 'email delivery failed'];
}

function sendAdminTrackingNotification($conn, array $orderContext, string $trackingCode): array {
    if (!loadPHPMailer()) {
        return ['sent' => 0, 'failed' => 0];
    }

    $orderNumber = (string)($orderContext['orderNumber'] ?? ('#' . (int)($orderContext['orderID'] ?? 0)));
    $customerEmail = trim((string)($orderContext['customerEmail'] ?? ''));
    $courierCode = (string)($orderContext['courierCode'] ?? '');
    $shipmentCourierName = (string)($orderContext['shipmentCourierName'] ?? '');
    $resolvedCourierCode = inferCourierCode($courierCode, $shipmentCourierName);
    $courierLabel = courierLabelFromCode($resolvedCourierCode);
    $trackingNumber = trim($trackingCode);

    $subject = "Tracking Email Sent for Order #{$orderNumber}";
    $bodyHtml = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Tracking Email Sent</title>
    <style>
        body { font-family: "Helvetica Neue", Arial, sans-serif; line-height: 1.6; color: #333; background: #faf5ff; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 24px; box-shadow: 0 8px 20px rgba(0,0,0,0.05); overflow: hidden; }
        .header { background: linear-gradient(135deg, #f8e1ff 0%, #e9d4ff 100%); padding: 30px 20px; text-align: center; border-bottom: 2px solid #d9b8ff; }
        .header h1 { margin: 0; font-size: 28px; color: #6a1b9a; }
        .content { padding: 30px 25px; }
        .admin-note { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 12px; color: #856404; }
        .footer { background: #f9f3ff; padding: 20px; text-align: center; font-size: 12px; color: #8a6aad; border-top: 1px solid #e6d6ff; }
        .button { display: inline-block; background: #6a1b9a; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 30px; margin-top: 10px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Tracking Email Sent</h1>
        <p>Order #' . htmlspecialchars($orderNumber) . '</p>
    </div>
    <div class="content">
        <p>Hello Admin,</p>
        <p>A tracking email was successfully sent to the customer for order <strong>#' . htmlspecialchars($orderNumber) . '</strong>.</p>
        <p><strong>Recipient:</strong> ' . htmlspecialchars($customerEmail) . '<br>
        <strong>Courier:</strong> ' . htmlspecialchars($courierLabel) . '<br>
        <strong>Tracking Number:</strong> ' . htmlspecialchars($trackingNumber) . '</p>
        <div class="admin-note">
            <strong>📦 Next steps:</strong> No further action required. The customer has been notified of the shipment.
        </div>
        <p><a href="' . htmlspecialchars(buildProjectBasePath() . '/admin/order_management.php?view=' . (int)($orderContext['orderID'] ?? 0)) . '" class="button">View order in admin panel</a></p>
    </div>
    <div class="footer">
        <p>Creations by Athina — Handmade with love</p>
    </div>
</div>
</body>
</html>';

    $plainBody = "Admin Notification\n\n"
        . "Tracking email sent to {$customerEmail} for order #{$orderNumber}\n"
        . "Courier: {$courierLabel}\nTracking: {$trackingNumber}\n"
        . "View order: " . buildProjectBasePath() . "/admin/order_management.php?view=" . (int)($orderContext['orderID'] ?? 0);

    $adminRecipients = [];
    $adminRes = $conn->query("
        SELECT full_name, email
        FROM users
        WHERE LOWER(role) IN ('admin','administrator','superadmin')
          AND email IS NOT NULL AND email <> ''
    ");
    if ($adminRes) {
        while ($row = $adminRes->fetch_assoc()) {
            $adminRecipients[] = [
                'name' => trim((string)($row['full_name'] ?? 'Admin')),
                'email' => trim((string)($row['email'] ?? '')),
            ];
        }
    }
    if (empty($adminRecipients)) {
        $adminRecipients[] = ['name' => 'Admin', 'email' => 'admin@festival-web.com'];
    }

    $transports = [
        ['port' => 587, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS],
        ['port' => 465, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS],
    ];
    $sentCount = 0;
    $failedCount = 0;

    foreach ($adminRecipients as $admin) {
        if (empty($admin['email']) || !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) continue;
        $sent = false;
        foreach ($transports as $transport) {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->SMTPDebug = 0;
                $mail->isSMTP();
                $mail->Host = 'premium245.web-hosting.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'admin@festival-web.com';
                $mail->Password = '!g3$~8tYju*D';
                $mail->SMTPSecure = $transport['secure'];
                $mail->Port = (int)$transport['port'];
                $mail->Timeout = 20;
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ];

                $mail->setFrom('admin@festival-web.com', 'Creations by Athina');
                $mail->addAddress($admin['email'], $admin['name']);
                $mail->addBCC('chrisanton1705@gmail.com');
                $mail->CharSet = 'UTF-8';
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $bodyHtml;
                $mail->AltBody = $plainBody;
                $mail->send();
                $sent = true;
                break;
            } catch (\Throwable $e) {
                error_log('Admin tracking notification failed: ' . $e->getMessage());
            }
        }
        if ($sent) $sentCount++;
        else $failedCount++;
    }

    return ['sent' => $sentCount, 'failed' => $failedCount];
}

// ========== END FUNCTIONS ==========

/* Update order status */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['admin_order_token'], (string)$token)) {
        $flash = 'err:Invalid request token.';
        header('Location: order_management.php?flash=' . urlencode($flash));
        exit;
    }

    $returnView = (int)($_POST['return_view'] ?? 0);
    $redirectBase = 'order_management.php';
    $redirectTo = $returnView > 0 ? ($redirectBase . '?view=' . $returnView) : $redirectBase;
    $action = trim((string)($_POST['action'] ?? 'update_status'));
    $orderID = (int)($_POST['orderID'] ?? 0);

    if ($action === 'update_status') {
        $status = trim((string)($_POST['status'] ?? ''));

        if ($orderID > 0 && isset($statusUpdateOptions[$status])) {
            $beforeContext = fetchOrderStatusContext($conn, $orderID);
            $beforeStatus = strtolower(trim((string)($beforeContext['status'] ?? '')));

            $stmt = mysqli_prepare($conn, 'UPDATE orders SET status = ? WHERE orderID = ?');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'si', $status, $orderID);
                mysqli_stmt_execute($stmt);
                $affected = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);

                if ($affected >= 0) {
                    $afterContext = fetchOrderStatusContext($conn, $orderID);
                    if ($afterContext) {
                        $trackingInfo = '';
                        $reviewInfo = '';
                        $statusLabel = $statusLabels[$status] ?? ucwords(str_replace('_', ' ', $status));

                        // If status is 'shipped', ensure tracking exists (generate if needed)
                        if ($status === 'shipped') {
                            error_log("Status is shipped, generating tracking for order $orderID");
                            $trackingCode = ensureShipmentTracking(
                                $conn,
                                $orderID,
                                (string)($afterContext['orderNumber'] ?? ('#' . $orderID)),
                                (string)($afterContext['courierCode'] ?? ''),
                                (string)($afterContext['shipmentCourierName'] ?? '')
                            );
                            if ($trackingCode !== '') {
                                $afterContext['trackingCode'] = $trackingCode;
                                $trackingInfo = ' Tracking: ' . $trackingCode . '.';
                                error_log("Tracking code generated: $trackingCode");
                            } else {
                                error_log("Failed to generate tracking code for order $orderID");
                            }
                        }

                        $afterStatus = strtolower(trim((string)($afterContext['status'] ?? '')));
                        if ($beforeStatus !== $afterStatus) {
                            // For shipped, skip the plain customer email and send HTML tracking email
                            $sendCustomer = ($afterStatus !== 'shipped');
                            sendOrderStatusEmails($conn, $afterContext, $statusLabel, $sendCustomer);

                            // Send review request when status becomes delivered or completed
                            if (in_array($afterStatus, ['delivered', 'completed'], true)) {
                                if (isOrderPaymentConfirmed($conn, $orderID)) {
                                    $ctx = fetchOrderReviewInviteContext($conn, $orderID);
                                    $recipientEmail = trim((string)($ctx['recipientEmail'] ?? ''));
                                    if ($ctx && $recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                                        ensureOrderReviewNotificationTable($conn);
                                        if (!orderReviewNotificationAlreadySent($conn, $orderID)) {
                                            // Fetch order items to build product list
                                            $products = [];
                                            $itemsQuery = $conn->query("
                                                SELECT oi.productID, COALESCE(p.nameEN, p.nameGR, 'Product') AS productName
                                                FROM order_items oi
                                                LEFT JOIN products p ON p.productID = oi.productID
                                                WHERE oi.orderID = $orderID
                                            ");
                                            if ($itemsQuery) {
                                                while ($item = $itemsQuery->fetch_assoc()) {
                                                    $products[] = [
                                                        'product_id' => (int)$item['productID'],
                                                        'name' => $item['productName'],
                                                    ];
                                                }
                                            }

                                            // Build review key for guests
                                            $reviewKey = '';
                                            if ((int)($ctx['userID'] ?? 0) <= 0 && !empty($ctx['orderEmail'])) {
                                                $reviewKey = buildGuestReviewKeyForOrder($orderID, $ctx['orderNumber'], $ctx['orderEmail']);
                                            }

                                            $mailResult = sendReviewInvitationEmailHTML([
                                                'to_email' => $recipientEmail,
                                                'customer_name' => (string)($ctx['customerName'] ?? 'Customer'),
                                                'order_number' => (string)($ctx['orderNumber'] ?? ''),
                                                'order_id' => $orderID,
                                                'order_date' => $ctx['orderDate'] ?? date('Y-m-d H:i:s'),
                                                'products' => $products,
                                                'review_key' => $reviewKey,
                                            ]);
                                            if ($mailResult['sent']) {
                                                markOrderReviewNotificationSent($conn, $orderID, $recipientEmail);
                                                $adminNotif = sendAdminReviewRequestNotification($conn, $orderID, (string)($ctx['orderNumber'] ?? ''), $recipientEmail);
                                                $reviewInfo = ' Review notification sent.';
                                                if ($adminNotif['sent'] > 0) {
                                                    $reviewInfo .= ' Admin notified.';
                                                } else {
                                                    $reviewInfo .= ' Admin notification failed.';
                                                }
                                            } else {
                                                error_log('Review notification email failed for order #' . $orderID . ': ' . (string)$mailResult['error']);
                                                $reviewInfo = ' Review notification email failed.';
                                            }
                                        } else {
                                            $reviewInfo = ' Review notification already sent.';
                                        }
                                    } else {
                                        $reviewInfo = ' No valid customer email for review notification.';
                                    }
                                } else {
                                    $reviewInfo = ' Review notification pending payment confirmation.';
                                }
                            }

                            // Send HTML tracking email when status becomes shipped
                            if ($afterStatus === 'shipped') {
                                $trackingCode = (string)($afterContext['trackingCode'] ?? '');
                                if ($trackingCode !== '') {
                                    $trackingEmailResult = sendTrackingEmailHTML($conn, $afterContext, $trackingCode);
                                    if ($trackingEmailResult['sent']) {
                                        $adminNotif = sendAdminTrackingNotification($conn, $afterContext, $trackingCode);
                                        $trackingInfo .= ' Tracking email sent.';
                                        if ($adminNotif['sent'] > 0) {
                                            $trackingInfo .= ' Admin notified.';
                                        } else {
                                            $trackingInfo .= ' Admin notification failed.';
                                        }
                                    } else {
                                        $errorMsg = $trackingEmailResult['error'];
                                        $trackingInfo .= " Tracking email failed: $errorMsg";
                                    }
                                } else {
                                    $trackingInfo .= ' No tracking code available.';
                                }
                            }
                        }
                        $flash = 'ok:Order status updated to ' . $statusLabel . '.' . $trackingInfo . $reviewInfo;
                    } else {
                        $flash = 'ok:Order status updated.';
                    }
                } else {
                    $flash = 'err:Order update failed.';
                }
            } else {
                $flash = 'err:Could not update order.';
            }
        } else {
            $flash = 'err:Invalid status update.';
        }
    } elseif ($action === 'update_shipping') {
        if ($orderID <= 0) {
            $flash = 'err:Invalid order.';
        } else {
            $shippingAddress = trim((string)($_POST['shippingAddress'] ?? ''));
            $shippingCity = trim((string)($_POST['shippingCity'] ?? ''));
            $shippingCountry = trim((string)($_POST['shippingCountry'] ?? ''));
            $shippingPostalCode = trim((string)($_POST['shippingPostalCode'] ?? ''));
            $shippingLabel = trim((string)($_POST['shippingLabel'] ?? ''));

            $updateStmt = mysqli_prepare(
                $conn,
                "UPDATE orders
                 SET shippingAddress = ?, shippingCity = ?, shippingCountry = ?, shippingPostalCode = ?, shippingLabel = ?
                 WHERE orderID = ?"
            );
            if ($updateStmt) {
                mysqli_stmt_bind_param(
                    $updateStmt,
                    'sssssi',
                    $shippingAddress,
                    $shippingCity,
                    $shippingCountry,
                    $shippingPostalCode,
                    $shippingLabel,
                    $orderID
                );
                mysqli_stmt_execute($updateStmt);
                mysqli_stmt_close($updateStmt);

                $afterContext = fetchOrderStatusContext($conn, $orderID);
                if ($afterContext) {
                    sendOrderMetaUpdateEmails(
                        $conn,
                        $afterContext,
                        'Shipping details updated',
                        'Shipping details for your order were updated by our team.'
                    );
                }
                $flash = 'ok:Shipping details updated.';
            } else {
                $flash = 'err:Could not update shipping details.';
            }
        }
    } elseif ($action === 'update_tracking') {
        $trackingCode = trim((string)($_POST['trackingCode'] ?? ''));
        if ($orderID <= 0 || $trackingCode === '') {
            $flash = 'err:Tracking number is required.';
        } else {
            $ctx = fetchOrderStatusContext($conn, $orderID);
            if (!$ctx) {
                $flash = 'err:Order not found.';
            } else {
                $shipmentId = 0;
                $shipmentStmt = mysqli_prepare($conn, "SELECT shipmentID FROM shipments WHERE orderID = ? LIMIT 1");
                if ($shipmentStmt) {
                    mysqli_stmt_bind_param($shipmentStmt, 'i', $orderID);
                    mysqli_stmt_execute($shipmentStmt);
                    $shipmentRes = mysqli_stmt_get_result($shipmentStmt);
                    if ($shipmentRes && ($shipmentRow = mysqli_fetch_assoc($shipmentRes))) {
                        $shipmentId = (int)($shipmentRow['shipmentID'] ?? 0);
                    }
                    mysqli_stmt_close($shipmentStmt);
                }

                $resolvedCourier = courierLabelFromCode(
                    inferCourierCode((string)($ctx['courierCode'] ?? ''), (string)($ctx['shipmentCourierName'] ?? ''))
                );

                if ($shipmentId > 0) {
                    $updateShipment = mysqli_prepare($conn, "UPDATE shipments SET trackingCode = ?, courierName = COALESCE(NULLIF(courierName, ''), ?) WHERE shipmentID = ?");
                    if ($updateShipment) {
                        mysqli_stmt_bind_param($updateShipment, 'ssi', $trackingCode, $resolvedCourier, $shipmentId);
                        mysqli_stmt_execute($updateShipment);
                        mysqli_stmt_close($updateShipment);
                    }
                } else {
                    $insertShipment = mysqli_prepare($conn, "INSERT INTO shipments (orderID, courierName, shippingCost, trackingCode) VALUES (?, ?, 0, ?)");
                    if ($insertShipment) {
                        mysqli_stmt_bind_param($insertShipment, 'iss', $orderID, $resolvedCourier, $trackingCode);
                        mysqli_stmt_execute($insertShipment);
                        mysqli_stmt_close($insertShipment);
                    }
                }

                $afterContext = fetchOrderStatusContext($conn, $orderID);
                if ($afterContext) {
                    sendOrderMetaUpdateEmails(
                        $conn,
                        $afterContext,
                        'Tracking number assigned',
                        'A tracking number has been assigned to your order.'
                    );
                }
                $flash = 'ok:Tracking number updated.';
            }
        }
    } else {
        $flash = 'err:Unknown order action.';
    }

    $separator = strpos($redirectTo, '?') === false ? '?' : '&';
    header('Location: ' . $redirectTo . $separator . 'flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) {
    $flash = (string)$_GET['flash'];
}

/* Single order details (optional) */
$viewOrder = null;
$viewItems = [];

if (isset($_GET['view'])) {
    $vid = (int)$_GET['view'];

    $viewSql = '
        SELECT
            o.orderID,
            o.orderNumber,
            o.status,
            o.totalAmount,
            o.createdAt,
            o.userID,
            o.email,
            o.shippingAddress,
            o.shippingCity,
            o.shippingPostalCode,
            o.shippingCountry,
            o.shippingLabel,
            o.courierCode,
            o.shippingPriority,
            COALESCE(NULLIF(u.full_name, ""), "Guest") AS customer,
            COALESCE(u.email, o.email, "-") AS customerEmail,
            COALESCE(u.phone, "-") AS phone,
            COALESCE(s.courierName, "") AS shipmentCourierName,
            COALESCE(s.trackingCode, "") AS trackingCode,
            lp.paymentStatus,
            lp.provider
        FROM orders o
        LEFT JOIN users u ON u.userID = o.userID
        LEFT JOIN shipments s ON s.orderID = o.orderID
        LEFT JOIN (
            SELECT p.orderID, p.paymentStatus, p.provider, p.timestamp
            FROM payments p
            INNER JOIN (
                SELECT orderID, MAX(timestamp) AS maxTimestamp
                FROM payments
                GROUP BY orderID
            ) latest ON latest.orderID = p.orderID AND latest.maxTimestamp = p.timestamp
        ) lp ON lp.orderID = o.orderID
        WHERE o.orderID = ?
        LIMIT 1
    ';
    $vst = mysqli_prepare($conn, $viewSql);
    if ($vst) {
        mysqli_stmt_bind_param($vst, 'i', $vid);
        mysqli_stmt_execute($vst);
        $r = mysqli_stmt_get_result($vst);
        $viewOrder = $r ? mysqli_fetch_assoc($r) : null;
        mysqli_stmt_close($vst);
    }

    $itemsSql = '
        SELECT
            oi.quantity,
            oi.unitPrice,
            p.nameEN,
            p.category
        FROM order_items oi
        LEFT JOIN products p ON p.productID = oi.productID
        WHERE oi.orderID = ?
        ORDER BY oi.orderItemID ASC
    ';
    $ist = mysqli_prepare($conn, $itemsSql);
    if ($ist) {
        mysqli_stmt_bind_param($ist, 'i', $vid);
        mysqli_stmt_execute($ist);
        $r2 = mysqli_stmt_get_result($ist);
        if ($r2) {
            while ($row = mysqli_fetch_assoc($r2)) {
                $viewItems[] = $row;
            }
        }
        mysqli_stmt_close($ist);
    }
}

/* Orders list */
$orders = [];
$listSql = '
    SELECT
        o.orderID,
        o.orderNumber,
        o.status,
        o.totalAmount,
        DATE_FORMAT(o.createdAt, "%m/%d/%Y") AS date,
        COUNT(oi.orderItemID) AS item_count,
        o.userID,
        COALESCE(NULLIF(u.full_name, ""), "Guest") AS customer,
        COALESCE(u.email, o.email, "-") AS email,
        COALESCE(lp.paymentStatus, "-") AS paymentStatus
    FROM orders o
    LEFT JOIN users u ON u.userID = o.userID
    LEFT JOIN order_items oi ON oi.orderID = o.orderID
    LEFT JOIN (
        SELECT p.orderID, p.paymentStatus, p.timestamp
        FROM payments p
        INNER JOIN (
            SELECT orderID, MAX(timestamp) AS maxTimestamp
            FROM payments
            GROUP BY orderID
        ) latest ON latest.orderID = p.orderID AND latest.maxTimestamp = p.timestamp
    ) lp ON lp.orderID = o.orderID
    GROUP BY
        o.orderID, o.orderNumber, o.status, o.totalAmount, o.createdAt,
        o.userID,
        COALESCE(NULLIF(u.full_name, ""), "Guest"),
        COALESCE(u.email, o.email, "-"),
        lp.paymentStatus
    ORDER BY o.createdAt DESC
';
$lr = mysqli_query($conn, $listSql);
if ($lr) {
    while ($row = mysqli_fetch_assoc($lr)) {
        $orders[] = $row;
    }
}

$receiptStatuses = ['paid', 'completed', 'captured', 'succeeded'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Order Management - Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
      <div class="content-header">
        <div class="content-header-left">
          <h1>Order Management</h1>
          <p>View, update and track all customer orders.</p>
        </div>
        <div class="content-header-right">
          <button id="testEmailBtn" class="btn-secondary" style="background:#6a1b9a; color:white;">
            <i class="fas fa-envelope"></i> Test Email
          </button>
        </div>
      </div>

    <div class="content-body">
      <?php if ($flash): ?>
        <?php [$type, $msg] = array_pad(explode(':', $flash, 2), 2, ''); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>">
          <?= htmlspecialchars($msg) ?>
        </div>
      <?php endif; ?>

      <?php if ($viewOrder): ?>
      <div class="card mb-6">
        <div class="card-header-flex">
          <div>
            <div class="card-title">Order #<?= htmlspecialchars((string)$viewOrder['orderNumber']) ?></div>
            <p class="text-sm text-muted">Placed on <?= htmlspecialchars(date('m/d/Y', strtotime((string)$viewOrder['createdAt']))) ?></p>
          </div>
          <div style="display:flex; gap:8px; align-items:center;">
            <a href="../receipt.php?order_id=<?= (int)$viewOrder['orderID'] ?>" class="btn-secondary" target="_blank" rel="noopener">
              <i class="fas fa-receipt"></i> Receipt
            </a>
            <a href="order_management.php" class="btn-secondary">
              <i class="fas fa-arrow-left"></i> Back to all orders
            </a>
          </div>
        </div>

        <?php
          $viewCourierCode = (string)($viewOrder['courierCode'] ?? '');
          $viewShipmentCourier = (string)($viewOrder['shipmentCourierName'] ?? '');
          $resolvedCourierCode = inferCourierCode($viewCourierCode, $viewShipmentCourier);
          $resolvedCourierLabel = courierLabelFromCode($resolvedCourierCode);
          $resolvedPriority = trim((string)($viewOrder['shippingPriority'] ?? 'standard'));
          $trackingCodeView = trim((string)($viewOrder['trackingCode'] ?? ''));
          $shippingLabelText = trim((string)($viewOrder['shippingLabel'] ?? ''));
          if ($shippingLabelText === '') {
              $shippingLabelText = fallbackShippingLabelForUser($conn, (int)($viewOrder['userID'] ?? 0));
          }
          $shippingAddressLine = trim((string)($viewOrder['shippingAddress'] ?? ''));
          $shippingCityLine = trim((string)($viewOrder['shippingCity'] ?? ''));
          $shippingCountryLine = trim((string)($viewOrder['shippingCountry'] ?? ''));
          $shippingPostcodeLine = trim((string)($viewOrder['shippingPostalCode'] ?? ''));
          $shippingAddressBits = array_filter([
              $shippingAddressLine,
              $shippingCityLine,
              $shippingPostcodeLine,
              $shippingCountryLine,
              $shippingLabelText,
          ], static function ($value) { return $value !== ''; });
          $shippingAddressText = !empty($shippingAddressBits) ? implode(', ', $shippingAddressBits) : 'Not provided';
        ?>
        <div class="order-detail-grid">
          <div class="order-detail-block">
            <h4>Customer</h4>
            <p class="mb-1"><strong><?= htmlspecialchars((string)$viewOrder['customer']) ?></strong></p>
            <p class="text-sm text-muted"><?= htmlspecialchars((string)$viewOrder['customerEmail']) ?></p>
            <p class="text-sm text-muted">Phone: <?= htmlspecialchars((string)$viewOrder['phone']) ?></p>
          </div>
          <div class="order-detail-block">
            <h4>Order Info</h4>
            <p class="text-sm mb-1">Status: <strong><?= htmlspecialchars((string)$viewOrder['status']) ?></strong></p>
            <p class="text-sm mb-1">Total: <strong>EUR <?= number_format((float)$viewOrder['totalAmount'], 2) ?></strong></p>
            <p class="text-sm text-muted">
              Payment: <?= htmlspecialchars((string)($viewOrder['paymentStatus'] ?? '-')) ?>
              <?php if (!empty($viewOrder['provider'])): ?>
                (<?= htmlspecialchars((string)$viewOrder['provider']) ?>)
              <?php endif; ?>
            </p>
            <p class="text-sm text-muted">Courier: <?= htmlspecialchars($resolvedCourierLabel) ?> (<?= htmlspecialchars($resolvedPriority) ?>)</p>
            <p class="text-sm text-muted" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
              <span>Tracking: <?= htmlspecialchars($trackingCodeView !== '' ? $trackingCodeView : 'Not assigned yet') ?></span>
              <button type="button" class="btn-edit" data-toggle-target="#tracking-edit-form">
                <i class="fas fa-pen"></i> Edit
              </button>
            </p>
            <form id="tracking-edit-form" method="POST" data-ignore-unsaved-warning style="display:none;margin-top:10px;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_order_token']) ?>">
              <input type="hidden" name="action" value="update_tracking">
              <input type="hidden" name="orderID" value="<?= (int)$viewOrder['orderID'] ?>">
              <input type="hidden" name="return_view" value="<?= (int)$viewOrder['orderID'] ?>">
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input
                  type="text"
                  name="trackingCode"
                  class="form-input"
                  placeholder="Enter courier tracking number"
                  value="<?= htmlspecialchars($trackingCodeView) ?>"
                  style="max-width:280px;"
                  required
                >
                <button type="submit" class="btn-primary btn-sm"><i class="fas fa-save"></i> Save</button>
              </div>
            </form>
          </div>
          <div class="order-detail-block">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
              <h4 style="margin-bottom:0;">Shipping</h4>
              <button type="button" class="btn-edit" data-toggle-target="#shipping-edit-form">
                <i class="fas fa-pen"></i> Edit
              </button>
            </div>
            <p class="text-sm mb-1"><?= htmlspecialchars($shippingAddressText) ?></p>
            <p class="text-sm text-muted">Address: <?= htmlspecialchars($shippingAddressLine !== '' ? $shippingAddressLine : '-') ?></p>
            <p class="text-sm text-muted">City: <?= htmlspecialchars($shippingCityLine !== '' ? $shippingCityLine : '-') ?></p>
            <p class="text-sm text-muted">Country: <?= htmlspecialchars($shippingCountryLine !== '' ? $shippingCountryLine : '-') ?></p>
            <p class="text-sm text-muted">Postcode: <?= htmlspecialchars($shippingPostcodeLine !== '' ? $shippingPostcodeLine : '-') ?></p>
            <p class="text-sm text-muted">Label: <?= htmlspecialchars($shippingLabelText !== '' ? $shippingLabelText : 'None') ?></p>
            <form id="shipping-edit-form" method="POST" data-ignore-unsaved-warning style="display:none;margin-top:10px;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_order_token']) ?>">
              <input type="hidden" name="action" value="update_shipping">
              <input type="hidden" name="orderID" value="<?= (int)$viewOrder['orderID'] ?>">
              <input type="hidden" name="return_view" value="<?= (int)$viewOrder['orderID'] ?>">
              <div class="form-grid-2" style="gap:8px;">
                <div class="form-group" style="margin-bottom:8px;">
                  <label class="form-label">Address</label>
                  <input type="text" name="shippingAddress" class="form-input" value="<?= htmlspecialchars($shippingAddressLine) ?>">
                </div>
                <div class="form-group" style="margin-bottom:8px;">
                  <label class="form-label">City</label>
                  <input type="text" name="shippingCity" class="form-input" value="<?= htmlspecialchars($shippingCityLine) ?>">
                </div>
                <div class="form-group" style="margin-bottom:8px;">
                  <label class="form-label">Country</label>
                  <input type="text" name="shippingCountry" class="form-input" value="<?= htmlspecialchars($shippingCountryLine) ?>">
                </div>
                <div class="form-group" style="margin-bottom:8px;">
                  <label class="form-label">Postcode</label>
                  <input type="text" name="shippingPostalCode" class="form-input" value="<?= htmlspecialchars($shippingPostcodeLine) ?>">
                </div>
              </div>
              <div class="form-group" style="margin-bottom:10px;">
                <label class="form-label">Label</label>
                <input type="text" name="shippingLabel" class="form-input" value="<?= htmlspecialchars($shippingLabelText) ?>" placeholder="e.g. apartment">
              </div>
              <button type="submit" class="btn-primary btn-sm"><i class="fas fa-save"></i> Save Shipping</button>
            </form>
          </div>
        </div>

        <h4 style="margin-top:24px;margin-bottom:8px;">Items</h4>
        <?php if (empty($viewItems)): ?>
          <p class="text-sm text-muted">No items found for this order.</p>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Category</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Line Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($viewItems as $it): ?>
              <tr>
                <td><?= htmlspecialchars((string)($it['nameEN'] ?? 'Product')) ?></td>
                <td class="text-muted"><?= htmlspecialchars((string)($it['category'] ?? '-')) ?></td>
                <td><?= (int)$it['quantity'] ?></td>
                <td>EUR <?= number_format((float)$it['unitPrice'], 2) ?></td>
                <td class="font-600">EUR <?= number_format((float)$it['quantity'] * (float)$it['unitPrice'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-title">All Orders</div>
        <?php if (empty($orders)): ?>
          <p class="text-sm text-muted">No orders have been placed yet.</p>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Order #</th>
                <th>Customer</th>
                <th>Role</th>
                <th>Items</th>
                <th>Total</th>
                <th>Date</th>
                <th>Payment</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($orders as $o): ?>
              <?php
                $st = $statusBadge[$o['status']] ?? 'badge-muted';
                $label = $statusLabels[$o['status']] ?? (string)$o['status'];
                $paymentStatus = strtolower((string)$o['paymentStatus']);
                $canGenerateReceipt = in_array($paymentStatus, $receiptStatuses, true);
              ?>
              <tr>
                <td class="font-600"><?= htmlspecialchars((string)$o['orderNumber']) ?></td>
                <td>
                  <div><?= htmlspecialchars((string)$o['customer']) ?></div>
                  <div class="text-sm text-muted"><?= htmlspecialchars((string)$o['email']) ?></div>
                </td>
                <td>
                  <?php if ($o['userID']): ?>
                    <span class="user-type-badge user-type-member"><i class="fas fa-user"></i> Member</span>
                  <?php else: ?>
                    <span class="user-type-badge user-type-guest"><i class="fas fa-user-secret"></i> Guest</span>
                  <?php endif; ?>
                </td>
                <td><?= (int)$o['item_count'] ?></td>
                <td class="font-600">EUR <?= number_format((float)$o['totalAmount'], 2) ?></td>
                <td class="text-muted"><?= htmlspecialchars((string)$o['date']) ?></td>
                <td class="text-sm"><?= htmlspecialchars((string)$o['paymentStatus']) ?></td>
                <td>
                  <span class="badge <?= $st ?>"><?= htmlspecialchars((string)$label) ?></span>
                </td>
                <td style="text-align:right; white-space:nowrap;">
                  <a href="order_management.php?view=<?= (int)$o['orderID'] ?>" class="btn-secondary btn-sm">
                    <i class="fas fa-eye"></i> View
                  </a>
                  <?php if ($canGenerateReceipt): ?>
                    <a href="../receipt.php?order_id=<?= (int)$o['orderID'] ?>" class="btn-secondary btn-sm" target="_blank" rel="noopener">
                      <i class="fas fa-receipt"></i> Receipt
                    </a>
                  <?php else: ?>
                    <button type="button" class="btn-secondary btn-sm" disabled>
                      <i class="fas fa-receipt"></i> No Receipt
                    </button>
                  <?php endif; ?>
                  <form method="POST" style="display:inline-flex;gap:4px;align-items:center;" data-ignore-unsaved-warning>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_order_token']) ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="orderID" value="<?= (int)$o['orderID'] ?>">
                    <select name="status" class="form-input" style="width:140px;padding:4px 6px;font-size:12px;">
                      <?php foreach ($statusUpdateOptions as $val => $lbl): ?>
                        <?php $isSelected = ($o['status'] === $val) || ($o['status'] === 'accepted' && $val === 'pending'); ?>
                        <option value="<?= $val ?>" <?= $isSelected ? 'selected' : '' ?>>
                          <?= htmlspecialchars($lbl) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-primary btn-sm">
                      <i class="fas fa-save"></i>
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    </div>
  </main>
</div>

<script src="assets/admin.js?v=<?= (int)filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var warningMessage = 'You have unsaved changes. Are you sure you want to leave this page?';
  var dirtyForms = new Set();
  var isSubmitting = false;

  document.querySelectorAll('[data-toggle-target]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var targetSelector = btn.getAttribute('data-toggle-target');
      if (!targetSelector) return;
      var target = document.querySelector(targetSelector);
      if (!target) return;
      var isHidden = target.style.display === 'none' || target.style.display === '';
      target.style.display = isHidden ? 'block' : 'none';
    });
  });

  function isEditableField(field) {
    if (!field || field.disabled || !field.name) return false;
    if (field.type === 'hidden' || field.type === 'submit' || field.type === 'button' || field.type === 'reset') return false;
    return true;
  }

  document.querySelectorAll('form[data-ignore-unsaved-warning]').forEach(function (form) {
    var statusSelect = form.querySelector('select[name="status"]');
    if (statusSelect) {
      var initialValue = statusSelect.value;
      function refreshStatusFormState() {
        if (statusSelect.value !== initialValue) {
          dirtyForms.add(form);
        } else {
          dirtyForms.delete(form);
        }
      }
      statusSelect.addEventListener('change', refreshStatusFormState);
      statusSelect.addEventListener('input', refreshStatusFormState);
    } else {
      form.querySelectorAll('input, select, textarea').forEach(function (field) {
        if (!isEditableField(field)) return;
        field.addEventListener('input', function () {
          dirtyForms.add(form);
        });
        field.addEventListener('change', function () {
          dirtyForms.add(form);
        });
      });
    }

    form.addEventListener('submit', function () {
      isSubmitting = true;
      dirtyForms.delete(form);
    });
  });

  window.addEventListener('beforeunload', function (e) {
    if (dirtyForms.size === 0 || isSubmitting) return;
    e.preventDefault();
    e.returnValue = warningMessage;
  });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var testBtn = document.getElementById('testEmailBtn');
  if (testBtn) {
    testBtn.addEventListener('click', function(e) {
      if (!confirm('Send a test email to your admin address? This will check SMTP settings.')) return;
      fetch('test_email_ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
      })
      .then(r => r.json())
      .then(data => {
        alert(data.success ? 'Test email sent! Check your inbox.' : 'Failed: ' + data.error);
      })
      .catch(e => alert('Request failed: ' + e.message));
    });
  }
});
</script>
</body>
</html>
