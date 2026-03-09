<?php
/**
 * Place Order Module
 *
 * Implements function 3.2.4.1: Place Order
 *
 * @package CreationsByAthina
 */

// Prevent direct access unless explicitly allowed by caller.
if (!defined('INCLUDE_CHECK') && !defined('PLACE_ORDER_DIRECT')) {
    die('Direct access not permitted');
}

// Trigger stock deduction + threshold checks after order placement.
require_once __DIR__ . '/stock_management.php';

/**
 * Create an admin notification row for newly placed orders.
 * Reuses a shared lightweight table used by other modules.
 *
 * @param mysqli $conn
 * @param string $message
 * @return void
 */
function notifyAdminNewOrder(mysqli $conn, string $message): void {
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
    }
}

/**
 * Generate next order number using format ORD-YYYY-XXX.
 *
 * @param mysqli $conn
 * @return string
 */
function generateOrderNumber(mysqli $conn): string {
    $yearPrefix = 'ORD-' . date('Y') . '-';
    $escPrefix = $conn->real_escape_string($yearPrefix . '%');
    $res = $conn->query("SELECT orderNumber FROM orders WHERE orderNumber LIKE '{$escPrefix}' ORDER BY orderID DESC LIMIT 1");

    $next = 1;
    if ($res && ($row = $res->fetch_assoc())) {
        if (preg_match('/^ORD-\d{4}-(\d+)$/', (string)$row['orderNumber'], $m)) {
            $next = (int)$m[1] + 1;
        }
    }

    return $yearPrefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/**
 * Create a full order record (header + items + payment + shipment summary).
 *
 * IMPORTANT: This function expects to run inside an active transaction.
 * Caller is responsible for begin/commit/rollback.
 *
 * @param mysqli $conn
 * @param array $input
 * @return array ['order_id' => int, 'order_number' => string, 'total' => float]
 * @throws InvalidArgumentException
 * @throws Exception
 */
function placeOrder(mysqli $conn, array $input): array {
    $paymentConfirmed = (bool)($input['payment_confirmed'] ?? false);
    if (!$paymentConfirmed) {
        throw new InvalidArgumentException('Payment confirmation is required before placing an order.');
    }

    $items = $input['items'] ?? [];
    if (!is_array($items) || empty($items)) {
        throw new InvalidArgumentException('Cart items are required.');
    }

    $subtotal = (float)($input['subtotal'] ?? 0);
    $shippingCost = (float)($input['shipping_cost'] ?? 0);
    $discountTotal = (float)($input['discount_total'] ?? 0);
    $totalAmount = (float)($input['total_amount'] ?? 0);
    if ($totalAmount <= 0) {
        $totalAmount = max(0, $subtotal - $discountTotal + $shippingCost);
    }

    $orderNumber = generateOrderNumber($conn);
    $userID = isset($input['user_id']) && (int)$input['user_id'] > 0 ? (int)$input['user_id'] : null;
    $isGuestFlag = (int)($input['is_guest'] ?? ($userID ? 0 : 1));
    $email = trim((string)($input['email'] ?? ''));
    $status = trim((string)($input['order_status'] ?? 'accepted'));

    // 1) Order header
    $stmt = $conn->prepare(
        "INSERT INTO orders (
            orderNumber, userID, isGuestFlag, email, status, subtotal, discountTotal, shippingCost, totalAmount
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        throw new Exception('Failed to prepare order insert: ' . $conn->error);
    }
    $stmt->bind_param(
        "siissdddd",
        $orderNumber,
        $userID,
        $isGuestFlag,
        $email,
        $status,
        $subtotal,
        $discountTotal,
        $shippingCost,
        $totalAmount
    );
    if (!$stmt->execute()) {
        throw new Exception('Failed to insert order header: ' . $stmt->error);
    }
    $orderID = (int)$stmt->insert_id;
    $stmt->close();

    // 2) Order lines (products + variations + gift add-ons)
    $lineStmt = $conn->prepare(
        "INSERT INTO order_items (
            orderID, productID, variationID, quantity, unitPrice, costPriceSnapshot, giftWrapping, giftBagFlag, giftMessage
        ) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?)"
    );
    if (!$lineStmt) {
        throw new Exception('Failed to prepare order item insert: ' . $conn->error);
    }

    foreach ($items as $item) {
        $productID = (int)($item['productID'] ?? $item['product_id'] ?? $item['product']['id'] ?? 0);
        if ($productID <= 0) {
            throw new InvalidArgumentException('Invalid product in cart line.');
        }

        $variationID = null;
        if (isset($item['variation']['variationID'])) {
            $variationID = (int)$item['variation']['variationID'];
        } elseif (isset($item['variation_id'])) {
            $variationID = (int)$item['variation_id'];
        }
        if ($variationID !== null && $variationID <= 0) {
            $variationID = null;
        }

        $quantity = max(1, (int)($item['quantity'] ?? 1));
        $unitPrice = (float)($item['price'] ?? $item['pricing']['unitTotal'] ?? $item['product']['basePrice'] ?? 0);
        $giftWrapping = !empty($item['addons']['giftWrapping']) ? 1 : 0;
        $giftBagFlag = !empty($item['addons']['giftBagFlag']) ? 1 : 0;
        $giftMessage = trim((string)($item['addons']['giftMessage'] ?? ''));
        if ($giftMessage === '') {
            $giftMessage = null;
        }

        $lineStmt->bind_param(
            "iiiidiis",
            $orderID,
            $productID,
            $variationID,
            $quantity,
            $unitPrice,
            $giftWrapping,
            $giftBagFlag,
            $giftMessage
        );
        if (!$lineStmt->execute()) {
            throw new Exception('Failed to insert order line: ' . $lineStmt->error);
        }
    }
    $lineStmt->close();

    // 3) Payment record
    $provider = trim((string)($input['payment_provider'] ?? 'manual'));
    $paymentStatus = trim((string)($input['payment_status'] ?? 'paid'));
    $transactionID = trim((string)($input['transaction_id'] ?? ''));
    if ($transactionID === '') {
        $transactionID = 'TXN_' . strtoupper(bin2hex(random_bytes(5)));
    }

    $payStmt = $conn->prepare(
        "INSERT INTO payments (orderID, provider, transactionID, paymentStatus, amount, currency)
         VALUES (?, ?, ?, ?, ?, 'EUR')"
    );
    if (!$payStmt) {
        throw new Exception('Failed to prepare payment insert: ' . $conn->error);
    }
    $payStmt->bind_param("isssd", $orderID, $provider, $transactionID, $paymentStatus, $totalAmount);
    if (!$payStmt->execute()) {
        throw new Exception('Failed to insert payment: ' . $payStmt->error);
    }
    $payStmt->close();

    // 4) Shipment summary
    // Schema has courierName but no dedicated priority field, so we append priority in label.
    $courier = trim((string)($input['courier'] ?? ''));
    $shippingPriority = trim((string)($input['shipping_priority'] ?? 'standard'));
    $courierLabel = $courier !== '' ? ($courier . ' - ' . $shippingPriority) : null;

    $shipStmt = $conn->prepare(
        "INSERT INTO shipments (orderID, courierName, shippingCost) VALUES (?, ?, ?)"
    );
    if ($shipStmt) {
        $shipStmt->bind_param("isd", $orderID, $courierLabel, $shippingCost);
        $shipStmt->execute();
        $shipStmt->close();
    }

    // 5) Trigger stock functions after the order has been fully stored.
    // This runs 3.2.2.5 (stock management) and internally 3.2.2.6 (stock threshold).
    deductStockAfterOrderCompletion($orderID, $conn);

    // 6) Notify admin about the new order.
    notifyAdminNewOrder(
        $conn,
        "New order placed: {$orderNumber} | Total: â‚¬" . number_format($totalAmount, 2)
    );

    return [
        'order_id' => $orderID,
        'order_number' => $orderNumber,
        'total' => $totalAmount,
    ];
}

/**
 * Send customer order confirmation email after a successful checkout commit.
 * Uses the same SMTP provider/settings currently used by auth emails.
 *
 * @param array $payload
 * @return array ['sent' => bool, 'error' => string]
 */
function sendOrderConfirmationEmail(array $payload): array {
    $toEmail = trim((string)($payload['to_email'] ?? ''));
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'error' => 'Invalid recipient email'];
    }

    $customerName = trim((string)($payload['customer_name'] ?? 'Customer'));
    if ($customerName === '') {
        $customerName = 'Customer';
    }

    $orderNumber = trim((string)($payload['order_number'] ?? ''));
    $orderId = (int)($payload['order_id'] ?? 0);
    $total = (float)($payload['total'] ?? 0);
    $shippingCost = (float)($payload['shipping_cost'] ?? 0);
    $courier = trim((string)($payload['courier'] ?? ''));
    $shippingPriority = trim((string)($payload['shipping_priority'] ?? 'standard'));
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

    require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
    require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
    require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

    $itemLines = [];
    foreach ($items as $item) {
        $name = trim((string)($item['name'] ?? $item['product']['name'] ?? 'Item'));
        $qty = max(1, (int)($item['quantity'] ?? 1));
        $unitPrice = (float)($item['price'] ?? $item['pricing']['unitTotal'] ?? $item['product']['basePrice'] ?? 0);
        $lineTotal = $qty * $unitPrice;
        $itemLines[] = "- {$name} x {$qty} = €" . number_format($lineTotal, 2);
    }
    if (empty($itemLines)) {
        $itemLines[] = '- Items recorded in your order details';
    }

    $body =
        "Hello {$customerName},\n\n" .
        "Thank you for your order at Athina E-Shop.\n\n" .
        "Order Number: " . ($orderNumber !== '' ? $orderNumber : ('#' . $orderId)) . "\n" .
        "Shipping: €" . number_format($shippingCost, 2) . "\n" .
        "Courier: " . ($courier !== '' ? "{$courier} ({$shippingPriority})" : 'Not specified') . "\n" .
        "Total Paid: €" . number_format($total, 2) . "\n\n" .
        "Items:\n" . implode("\n", $itemLines) . "\n\n" .
        "We will notify you when your order status changes.\n\n" .
        "Best regards,\n" .
        "Athina E-Shop";

    // Transport fallback: STARTTLS on 587, then SMTPS on 465.
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
            $mail->Subject = 'Athina E-Shop - Order Confirmation ' . ($orderNumber !== '' ? $orderNumber : ('#' . $orderId));
            $mail->Body = $body;
            $mail->send();
            return ['sent' => true, 'error' => ''];
        } catch (\Throwable $e) {
            $detail = trim((string)($mail->ErrorInfo ?? ''));
            $message = trim((string)$e->getMessage());
            $combined = $detail !== '' ? $detail : $message;
            $attemptErrors[] = $transport['label'] . ': ' . ($combined !== '' ? $combined : 'send failed');
        }
    }

    $finalError = implode(' | ', $attemptErrors);
    if ($finalError === '') {
        $finalError = 'Mailer send failed on all transports';
    }
    error_log('Order confirmation email failed: ' . $finalError);
    return ['sent' => false, 'error' => $finalError];
}
