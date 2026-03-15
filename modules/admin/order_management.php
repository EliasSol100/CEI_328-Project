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
    'delivered'     => 'badge-dark',
    'completed'     => 'badge-dark',
    'cancelled'     => 'badge-red',
];

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
        'elta_courier' => 'ELTA Courier',
        'speedex' => 'Speedex',
        'geniki' => 'Geniki Taxydromiki',
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
    if (strpos($probe, 'elta') !== false) {
        return 'elta_courier';
    }
    if (strpos($probe, 'speedex') !== false) {
        return 'speedex';
    }
    if (strpos($probe, 'geniki') !== false || strpos($probe, 'taxydromiki') !== false) {
        return 'geniki';
    }
    return '';
}

function makeTrackingCode(string $courierCode, string $orderNumber): string {
    $prefixMap = [
        'akis_express' => 'AKI',
        'boxnow' => 'BXN',
        'acs' => 'ACS',
        'elta_courier' => 'ELT',
        'speedex' => 'SPD',
        'geniki' => 'GNK',
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

function sendOrderStatusEmails(mysqli $conn, array $orderContext, string $statusLabel): array {
    require_once __DIR__ . '/../../PHPMailer-master/src/Exception.php';
    require_once __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php';
    require_once __DIR__ . '/../../PHPMailer-master/src/SMTP.php';

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

function sendReviewInvitationEmail(array $payload): array {
    $toEmail = trim((string)($payload['to_email'] ?? ''));
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'error' => 'invalid recipient'];
    }

    $customerName = trim((string)($payload['customer_name'] ?? 'Customer'));
    if ($customerName === '') {
        $customerName = 'Customer';
    }
    $orderNumber = trim((string)($payload['order_number'] ?? ''));
    $reviewUrl = trim((string)($payload['review_url'] ?? ''));
    if ($reviewUrl === '') {
        return ['sent' => false, 'error' => 'missing review url'];
    }

    require_once __DIR__ . '/../../PHPMailer-master/src/Exception.php';
    require_once __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php';
    require_once __DIR__ . '/../../PHPMailer-master/src/SMTP.php';

    $subject = 'Your order is delivered - leave a product review';
    $body =
        "Hello {$customerName},\n\n" .
        "Your order " . ($orderNumber !== '' ? $orderNumber : '') . " has been delivered and payment is confirmed.\n" .
        "You can now submit your product review using this link:\n" .
        $reviewUrl . "\n\n" .
        "Thank you,\nAthina E-Shop";

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

            $mail->setFrom('admin@festival-web.com', 'Athina E-Shop');
            $mail->addAddress($toEmail, $customerName);
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body = $body;
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

/* Update order status */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['admin_order_token'], (string)$token)) {
        $flash = 'err:Invalid request token.';
        header('Location: order_management.php?flash=' . urlencode($flash));
        exit;
    }

    $orderID = (int)($_POST['orderID'] ?? 0);
    $status  = trim((string)($_POST['status'] ?? ''));

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
                    if ($status === 'shipped') {
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
                        }
                    }

                    $afterStatus = strtolower(trim((string)($afterContext['status'] ?? '')));
                    if ($beforeStatus !== $afterStatus) {
                        sendOrderStatusEmails($conn, $afterContext, $statusLabel);

                        if (in_array($afterStatus, ['delivered', 'completed'], true)) {
                            if (isOrderPaymentConfirmed($conn, $orderID)) {
                                $ctx = fetchOrderReviewInviteContext($conn, $orderID);
                                $recipientEmail = trim((string)($ctx['recipientEmail'] ?? ''));
                                if ($ctx && $recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                                    ensureOrderReviewNotificationTable($conn);
                                    if (!orderReviewNotificationAlreadySent($conn, $orderID)) {
                                        $reviewUrl = buildReviewInviteUrl($ctx);
                                        $mailResult = sendReviewInvitationEmail([
                                            'to_email' => $recipientEmail,
                                            'customer_name' => (string)($ctx['customerName'] ?? 'Customer'),
                                            'order_number' => (string)($ctx['orderNumber'] ?? ''),
                                            'review_url' => $reviewUrl,
                                        ]);
                                        if ($mailResult['sent']) {
                                            markOrderReviewNotificationSent($conn, $orderID, $recipientEmail);
                                            $reviewInfo = ' Review notification sent.';
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

    header('Location: order_management.php?flash=' . urlencode($flash));
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
            <p class="text-sm text-muted">Tracking: <?= htmlspecialchars($trackingCodeView !== '' ? $trackingCodeView : 'Not assigned yet') ?></p>
          </div>
          <div class="order-detail-block">
            <h4>Shipping</h4>
            <p class="text-sm mb-1"><?= htmlspecialchars($shippingAddressText) ?></p>
            <p class="text-sm text-muted">Address: <?= htmlspecialchars($shippingAddressLine !== '' ? $shippingAddressLine : '-') ?></p>
            <p class="text-sm text-muted">City: <?= htmlspecialchars($shippingCityLine !== '' ? $shippingCityLine : '-') ?></p>
            <p class="text-sm text-muted">Country: <?= htmlspecialchars($shippingCountryLine !== '' ? $shippingCountryLine : '-') ?></p>
            <p class="text-sm text-muted">Postcode: <?= htmlspecialchars($shippingPostcodeLine !== '' ? $shippingPostcodeLine : '-') ?></p>
            <p class="text-sm text-muted">Label: <?= htmlspecialchars($shippingLabelText !== '' ? $shippingLabelText : 'None') ?></p>
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
                  <form method="POST" style="display:inline-flex;gap:4px;align-items:center;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_order_token']) ?>">
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

<script src="assets/admin.js"></script>
</body>
</html>
