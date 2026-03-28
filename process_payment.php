<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once __DIR__ . '/../vendor/autoload.php'; // Composer autoloader
require_once __DIR__ . '/../authentication/database.php';
require_once __DIR__ . '/../authentication/get_config.php';
require_once __DIR__ . '/../config.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$project = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($project === '' || $project === '.') {
    $project = '';
}

$paymentIntentId = $_GET['payment_intent'] ?? null;
$paymentIntentClientSecret = $_GET['payment_intent_client_secret'] ?? null;

if (!$paymentIntentId || !$paymentIntentClientSecret) {
    die('Invalid payment confirmation.');
}

try {
    $intent = \Stripe\PaymentIntent::retrieve($paymentIntentId);

    // Verify the client secret matches to prevent tampering
    if ($intent->client_secret !== $paymentIntentClientSecret) {
        die('Invalid client secret.');
    }

    $orderId = (int)($intent->metadata['order_id'] ?? 0);
    $amount = $intent->amount / 100; // amount is in cents

    if (!$orderId) {
        die('Order not found in payment metadata.');
    }

    // Update order status based on payment intent status
    $status = $intent->status;

    // Map Stripe status to your order payment_status
    $orderStatus = 'pending';
    if ($status === 'succeeded') {
        $orderStatus = 'paid';
    } elseif ($status === 'requires_payment_method') {
        $orderStatus = 'failed';
    }

    // Update order in database
    $stmt = $conn->prepare("UPDATE orders SET payment_status = ?, transaction_id = ? WHERE orderID = ?");
    $stmt->bind_param("ssi", $orderStatus, $paymentIntentId, $orderId);
    $stmt->execute();
    $stmt->close();

    if ($status === 'succeeded') {
        // Payment succeeded – redirect to success page
        header('Location: ' . $project . '/modules/checkout_success.php?order_id=' . $orderId);
        exit;
    } else {
        // Payment failed or requires action – redirect to checkout with error
        $_SESSION['checkout_error'] = 'Payment failed. Please try again.';
        header('Location: ' . $project . '/checkout.php');
        exit;
    }
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('Stripe webhook error: ' . $e->getMessage());
    die('Payment processing error: ' . $e->getMessage());
}
?>

// Dompdf (optional)
$dompdfAvailable = false;
$dompdfAutoloadPath = __DIR__ . '/PHPMailer-master/vendor/autoload.php';
if (file_exists($dompdfAutoloadPath)) {
    require_once $dompdfAutoloadPath;
    $dompdfAvailable = true;
} else {
    error_log("Dompdf autoload not found at $dompdfAutoloadPath; PDF attachment will be skipped.");
}

// Include PHPMailer manually
require_once __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/PHPMailer-master/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

// ========== SMTP CONFIGURATION ==========
define('ADMIN_EMAIL', 'admin@festival-web.com');
define('SMTP_HOST', 'premium245.web-hosting.com');
define('SMTP_USER', 'admin@festival-web.com');
define('SMTP_PASS', '!g3$~8tYju*D');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
// ===========================================

/**
 * Helper: courier name from code
 */
function courierLabelFromCode($courierCode) {
    $map = [
        'akis_express' => 'Akis Express',
        'boxnow'       => 'BoxNow',
        'acs'          => 'ACS',
        'elta_courier' => 'ELTA Courier',
        'speedex'      => 'Speedex',
        'geniki'       => 'Geniki Taxydromiki',
    ];
    $key = strtolower(trim($courierCode));
    return $map[$key] ?? ($courierCode !== '' ? $courierCode : 'Not specified');
}

/**
 * Clean country name: remove parentheses and any extra text (e.g., "Cyprus (Κύπρος)" → "Cyprus")
 */
function cleanCountryName($country) {
    $country = trim($country);
    if (($pos = strpos($country, '(')) !== false) {
        $country = trim(substr($country, 0, $pos));
    }
    return $country;
}

/**
 * Generate PDF receipt – clean monochrome style (like 0.png)
 */
function generateReceiptPDF($conn, $orderId, $siteUrl) {
    global $dompdfAvailable;
    if (!$dompdfAvailable) return null;

    // Fetch order details (including user data for billing)
    $order = $conn->query("
        SELECT 
            o.orderNumber, o.createdAt,
            o.subtotal, o.discountTotal, o.shippingCost, o.totalAmount,
            o.payment_method, o.courier, o.shipping_speed,
            o.shipping_address, o.shipping_city, o.shipping_postal_code, o.shipping_country,
            o.email, o.userID, o.status, o.transaction_id,
            u.full_name AS customerName,
            u.phone AS customerPhone,
            u.address AS billing_address,
            u.city AS billing_city,
            u.postcode AS billing_postal_code,
            u.country AS billing_country
        FROM orders o
        LEFT JOIN users u ON u.userID = o.userID
        WHERE o.orderID = $orderId
    ")->fetch_assoc();
    if (!$order) return null;

    // Fetch order items
    $items = $conn->query("
        SELECT 
            oi.quantity, oi.unitPrice,
            oi.giftWrapping, oi.giftBagFlag, oi.giftMessage,
            p.nameEN, p.nameGR, p.sku
        FROM order_items oi
        LEFT JOIN products p ON oi.productID = p.productID
        WHERE oi.orderID = $orderId
    ");

    // Build display data
    $orderNumber = $order['orderNumber'] ?? 'ORD-' . $orderId;
    $orderDate = date('F j, Y', strtotime($order['createdAt']));
    $orderTime = date('g:i a', strtotime($order['createdAt']));
    $subtotal = number_format((float)$order['subtotal'], 2);
    $discount = number_format((float)($order['discountTotal'] ?? 0), 2);
    $shipping = number_format((float)$order['shippingCost'], 2);
    $total = number_format((float)$order['totalAmount'], 2);
    $customerName = $order['customerName'] ?: ($order['email'] ?: 'Guest');
    $customerEmail = $order['email'] ?? '—';
    $customerPhone = $order['customerPhone'] ?: 'Not provided';
    $paymentMethod = ucfirst(str_replace('_', ' ', $order['payment_method'] ?? 'N/A'));
    $transactionId = htmlspecialchars($order['transaction_id'] ?? '—');
    $courierLabel = courierLabelFromCode($order['courier']);
    $shippingSpeed = $order['shipping_speed'] ?? 'standard';
    $orderStatus = ucfirst($order['status']);

    // Clean country names
    $billingCountry = cleanCountryName($order['billing_country'] ?? '');
    $shippingCountry = cleanCountryName($order['shipping_country'] ?? '');

    // Addresses
    $shippingParts = array_filter([
        $order['shipping_address'],
        $order['shipping_city'],
        $order['shipping_postal_code'],
        $shippingCountry
    ]);
    $shippingAddress = $shippingParts ? implode(', ', $shippingParts) : 'Not provided';

    $billingParts = array_filter([
        $order['billing_address'] ?: $order['shipping_address'],
        $order['billing_city'] ?: $order['shipping_city'],
        $order['billing_postal_code'] ?: $order['shipping_postal_code'],
        $billingCountry
    ]);
    $billingAddress = $billingParts ? implode(', ', $billingParts) : 'Not provided';

    // Items HTML – no escaped closing tags
    $itemsHtml = '';
    while ($item = $items->fetch_assoc()) {
        $name = htmlspecialchars($item['nameEN'] ?: $item['nameGR'] ?: 'Product');
        $sku = htmlspecialchars($item['sku'] ?? '—');
        $qty = (int)$item['quantity'];
        $price = number_format((float)$item['unitPrice'], 2);
        $lineTotal = number_format($qty * $item['unitPrice'], 2);

        $giftBits = [];
        if (!empty($item['giftWrapping'])) $giftBits[] = 'Gift Wrap (+€2.00)';
        if (!empty($item['giftBagFlag'])) $giftBits[] = 'Gift Bag (+€1.50)';
        if (!empty($item['giftMessage'])) $giftBits[] = 'Message: "' . htmlspecialchars($item['giftMessage']) . '"';
        $giftText = $giftBits ? '<br><small>' . implode(' | ', $giftBits) . '</small>' : '';

        $itemsHtml .= '
        <tr>
            <td style="padding:8px; border-bottom:1px solid #ddd;"><strong>' . $name . '</strong><br><small>SKU: ' . $sku . '</small>' . $giftText . '</td>
            <td style="padding:8px; border-bottom:1px solid #ddd; text-align:center;">' . $qty . '</td>
            <td style="padding:8px; border-bottom:1px solid #ddd; text-align:right;">€' . $price . '</td>
            <td style="padding:8px; border-bottom:1px solid #ddd; text-align:right;">€' . $lineTotal . '</td>
          </tr>';
    }

    // Build HTML – clean monochrome layout
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Order Receipt ' . $orderNumber . '</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #fff;
            margin: 0;
            padding: 20px;
            color: #000;
        }
        .container {
            max-width: 700px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 20px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #eee;
            margin-bottom: 20px;
            padding-bottom: 10px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #000;
        }
        .order-info {
            background: #f9f9f9;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #ccc;
        }
        .address-grid {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .address-box {
            flex: 1;
            background: #fafafa;
            padding: 12px;
            border: 1px solid #eee;
            border-radius: 4px;
        }
        .address-box h4 {
            margin: 0 0 8px 0;
            font-size: 14px;
            text-transform: uppercase;
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th {
            background: #f0f0f0;
            padding: 8px;
            text-align: left;
            font-weight: 600;
            border-bottom: 1px solid #ddd;
        }
        td {
            padding: 8px;
            border-bottom: 1px solid #eee;
        }
        .totals {
            text-align: right;
            margin-top: 20px;
            padding: 10px;
            border-top: 2px solid #eee;
        }
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 12px;
            color: #777;
            border-top: 1px solid #eee;
            padding-top: 15px;
        }
        .tracking-note {
            margin: 20px 0 10px;
            font-size: 13px;
            text-align: center;
            color: #555;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Creations by Athina</h1>
        <p>Order Receipt</p>
    </div>

    <div class="order-info">
        <p><strong>Order Number:</strong> ' . $orderNumber . '<br>
        <strong>Order Date:</strong> ' . $orderDate . ' at ' . $orderTime . '<br>
        <strong>Order Status:</strong> ' . $orderStatus . '<br>
        <strong>Payment Method:</strong> ' . $paymentMethod . '<br>
        <strong>Transaction ID:</strong> ' . $transactionId . '<br>
        <strong>Courier:</strong> ' . $courierLabel . ' (' . $shippingSpeed . ')</p>
    </div>

    <div class="address-grid">
        <div class="address-box">
            <h4>Billing Address</h4>
            <p>' . nl2br(htmlspecialchars($billingAddress)) . '<br>
            Phone: ' . htmlspecialchars($customerPhone) . '<br>
            Email: ' . htmlspecialchars($customerEmail) . '</p>
        </div>
        <div class="address-box">
            <h4>Shipping Address</h4>
            <p>' . nl2br(htmlspecialchars($shippingAddress)) . '</p>
        </div>
    </div>

    <h3>Order Items</h3>
    <table>
        <thead>
            <tr><th>Item</th><th style="width:60px;">Qty</th><th style="width:100px;">Unit Price</th><th style="width:100px;">Total</th></tr>
        </thead>
        <tbody>' . $itemsHtml . '</tbody>
    </table>

    <div class="totals">
        <p>Subtotal: €' . $subtotal . '</p>
        ' . ($discount > 0 ? '<p>Discount: -€' . $discount . '</p>' : '') . '
        <p>Shipping: €' . $shipping . '</p>
        <p><strong>Total: €' . $total . '</strong></p>
    </div>

    <div class="tracking-note">
        <p>You will receive a separate email with tracking information once your order is in transit.</p>
    </div>

    <div class="footer">
        <p>If you have any questions, please contact us at <a href="mailto:admin@festival-web.com">admin@festival-web.com</a> or call +30 123 456 7890.</p>
        <p>&copy; ' . date('Y') . ' Creations by Athina. All rights reserved.</p>
        <p><a href="' . $siteUrl . '/contact.php">Contact Us</a></p>
    </div>
</div>
</body>
</html>';

    // Fix asset paths if needed
    $html = str_replace('href="../assets/', 'href="' . $siteUrl . '/assets/', $html);
    $html = str_replace('src="../assets/', 'src="' . $siteUrl . '/assets/', $html);

    // Generate PDF
    $options = new \Dompdf\Options();
    $options->set('defaultFont', 'Helvetica');
    $options->set('isRemoteEnabled', true);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/**
 * Send customer order confirmation email – original purple/pink style
 */
function sendCustomerEmail($conn, $orderId, $customerEmail, $customerName, $pdfContent = null, $siteUrl = '') {
    // Fetch order details (same as PDF)
    $order = $conn->query("
        SELECT 
            o.orderNumber, o.createdAt,
            o.subtotal, o.discountTotal, o.shippingCost, o.totalAmount,
            o.payment_method, o.courier, o.shipping_speed,
            o.shipping_address, o.shipping_city, o.shipping_postal_code, o.shipping_country,
            o.email, o.userID, o.status, o.transaction_id,
            u.full_name AS customerName,
            u.phone AS customerPhone,
            u.address AS billing_address,
            u.city AS billing_city,
            u.postcode AS billing_postal_code,
            u.country AS billing_country
        FROM orders o
        LEFT JOIN users u ON u.userID = o.userID
        WHERE o.orderID = $orderId
    ")->fetch_assoc();
    if (!$order) return ['success' => false, 'error' => 'Order not found'];

    $items = $conn->query("
        SELECT 
            oi.quantity, oi.unitPrice,
            oi.giftWrapping, oi.giftBagFlag, oi.giftMessage,
            p.nameEN, p.nameGR, p.sku
        FROM order_items oi
        LEFT JOIN products p ON oi.productID = p.productID
        WHERE oi.orderID = $orderId
    ");

    // Build data (same as PDF)
    $orderNumber = $order['orderNumber'] ?? 'ORD-' . $orderId;
    $orderDate = date('F j, Y', strtotime($order['createdAt']));
    $orderTime = date('g:i a', strtotime($order['createdAt']));
    $subtotal = number_format((float)$order['subtotal'], 2);
    $discount = number_format((float)($order['discountTotal'] ?? 0), 2);
    $shipping = number_format((float)$order['shippingCost'], 2);
    $total = number_format((float)$order['totalAmount'], 2);
    $customerName = $order['customerName'] ?: ($order['email'] ?: 'Guest');
    $customerEmailAddr = $order['email'] ?? '—';
    $customerPhone = $order['customerPhone'] ?: 'Not provided';
    $paymentMethod = ucfirst(str_replace('_', ' ', $order['payment_method'] ?? 'N/A'));
    $transactionId = htmlspecialchars($order['transaction_id'] ?? '—');
    $courierLabel = courierLabelFromCode($order['courier']);
    $shippingSpeed = $order['shipping_speed'] ?? 'standard';
    $orderStatus = ucfirst($order['status']);

    // Clean country names
    $billingCountry = cleanCountryName($order['billing_country'] ?? '');
    $shippingCountry = cleanCountryName($order['shipping_country'] ?? '');

    // Addresses
    $shippingParts = array_filter([
        $order['shipping_address'],
        $order['shipping_city'],
        $order['shipping_postal_code'],
        $shippingCountry
    ]);
    $shippingAddress = $shippingParts ? implode(', ', $shippingParts) : 'Not provided';

    $billingParts = array_filter([
        $order['billing_address'] ?: $order['shipping_address'],
        $order['billing_city'] ?: $order['shipping_city'],
        $order['billing_postal_code'] ?: $order['shipping_postal_code'],
        $billingCountry
    ]);
    $billingAddress = $billingParts ? implode(', ', $billingParts) : 'Not provided';

    // Items HTML
    $itemsHtml = '';
    while ($item = $items->fetch_assoc()) {
        $name = htmlspecialchars($item['nameEN'] ?: $item['nameGR'] ?: 'Product');
        $sku = htmlspecialchars($item['sku'] ?? '—');
        $qty = (int)$item['quantity'];
        $price = number_format((float)$item['unitPrice'], 2);
        $lineTotal = number_format($qty * $item['unitPrice'], 2);

        $giftBits = [];
        if (!empty($item['giftWrapping'])) $giftBits[] = 'Gift Wrap (+€2.00)';
        if (!empty($item['giftBagFlag'])) $giftBits[] = 'Gift Bag (+€1.50)';
        if (!empty($item['giftMessage'])) $giftBits[] = 'Message: "' . htmlspecialchars($item['giftMessage']) . '"';
        $giftText = $giftBits ? '<br><small>' . implode(' | ', $giftBits) . '</small>' : '';

        $itemsHtml .= '
          <tr>
            <td style="padding:8px; border-bottom:1px solid #ede2ff;"><strong>' . $name . '</strong><br><small>SKU: ' . $sku . '</small>' . $giftText . '</td>
            <td style="padding:8px; border-bottom:1px solid #ede2ff; text-align:center;">' . $qty . '</td>
            <td style="padding:8px; border-bottom:1px solid #ede2ff; text-align:right;">€' . $price . '</td>
            <td style="padding:8px; border-bottom:1px solid #ede2ff; text-align:right;">€' . $lineTotal . '</td>
          </tr>';
    }

    // Build email HTML – original purple/pink style
    $body = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Order Confirmation ' . $orderNumber . '</title>
    <style>
        body { font-family: "Helvetica Neue", Arial, sans-serif; line-height: 1.6; color: #333; background: #faf5ff; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 24px; box-shadow: 0 8px 20px rgba(0,0,0,0.05); overflow: hidden; }
        .header { background: linear-gradient(135deg, #f8e1ff 0%, #e9d4ff 100%); padding: 30px 20px; text-align: center; border-bottom: 2px solid #d9b8ff; }
        .header h1 { margin: 0; font-size: 28px; color: #6a1b9a; letter-spacing: -0.5px; }
        .header p { margin: 5px 0 0; color: #8a6aad; font-size: 14px; }
        .content { padding: 30px 25px; }
        .order-details { background: #fef9ff; border-left: 4px solid #c9a9f5; padding: 15px; margin: 20px 0; border-radius: 12px; }
        .order-details p { margin: 5px 0; }
        .order-summary { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .order-summary th { background: #f5edff; text-align: left; padding: 12px; font-weight: 600; color: #4a2f6e; }
        .order-summary td { padding: 10px; border-bottom: 1px solid #ede2ff; }
        .totals { text-align: right; margin-top: 20px; padding: 10px; background: #faf5ff; border-radius: 12px; }
        .footer { background: #f9f3ff; padding: 20px; text-align: center; font-size: 12px; color: #8a6aad; border-top: 1px solid #e6d6ff; }
        .address-box { background: #fef9ff; padding: 12px; margin: 10px 0; border-radius: 12px; border: 1px solid #ede2ff; }
        .address-box h4 { margin: 0 0 8px 0; color: #6a1b9a; }
        .grid { display: flex; gap: 20px; flex-wrap: wrap; margin: 20px 0; }
        .grid .address-box { flex: 1; min-width: 200px; }
        .thankyou { background: #e8f4fd; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-size: 16px; color: #004085; }
        .tracking-note { margin: 20px 0 10px; text-align: center; font-size: 14px; color: #6a1b9a; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Creations by Athina</h1>
        <p>Handmade with love</p>
    </div>
    <div class="content">
        <div class="thankyou">
            <strong>Thank you for shopping with Creations by Athina!</strong><br>
            We appreciate your business and hope you enjoy your purchase.
        </div>

        <div class="order-details">
            <p><strong>Order Number:</strong> ' . $orderNumber . '</p>
            <p><strong>Order Date:</strong> ' . $orderDate . ' at ' . $orderTime . '</p>
            <p><strong>Payment Method:</strong> ' . $paymentMethod . '</p>
            <p><strong>Transaction ID:</strong> ' . $transactionId . '</p>
            <p><strong>Courier:</strong> ' . $courierLabel . ' (' . $shippingSpeed . ')</p>
            <p><strong>Status:</strong> ' . $orderStatus . '</p>
        </div>

        <div class="grid">
            <div class="address-box">
                <h4>Billing Address</h4>
                <p>' . nl2br(htmlspecialchars($billingAddress)) . '<br>
                Phone: ' . htmlspecialchars($customerPhone) . '<br>
                Email: ' . htmlspecialchars($customerEmailAddr) . '</p>
            </div>
            <div class="address-box">
                <h4>Shipping Address</h4>
                <p>' . nl2br(htmlspecialchars($shippingAddress)) . '</p>
            </div>
        </div>

        <h3 style="color:#6a1b9a;">Order Items</h3>
        <table class="order-summary">
            <thead>
                <tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr>
            </thead>
            <tbody>' . $itemsHtml . '</tbody>
        </table>

        <div class="totals">
            <p>Subtotal: €' . $subtotal . '</p>
            ' . ($discount > 0 ? '<p>Discount: -€' . $discount . '</p>' : '') . '
            <p>Shipping: €' . $shipping . '</p>
            <p><strong>Total: €' . $total . '</strong></p>
        </div>

        <div class="tracking-note">
            <p>You will receive a separate email with tracking information once your order is in transit.</p>
        </div>

        <p>If you have any questions, please <a href="' . $siteUrl . '/contact.php">contact us</a>.</p>
    </div>
    <div class="footer">
        <p>Creations by Athina — Handmade with love</p>
        <p><a href="mailto:admin@festival-web.com">admin@festival-web.com</a> | +30 123 456 7890</p>
        <p><a href="' . $siteUrl . '/contact.php">Contact Us</a></p>
    </div>
</div>
</body>
</html>';

    // Send email
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_USER, 'Creations by Athina');
        $mail->addAddress($customerEmail, $customerName);
        $mail->addBCC('chrisanton1705@gmail.com'); // Backup BCC (replace with your email)
        $mail->isHTML(true);
        $mail->Subject = "Order Confirmation #{$orderNumber}";
        $mail->Body    = $body;
        $mail->AltBody = strip_tags(str_replace(['</p>','<br>'], "\n", $body));

        if ($pdfContent !== null && !empty($pdfContent)) {
            $mail->addStringAttachment($pdfContent, 'Receipt_' . $orderNumber . '.pdf', 'base64', 'application/pdf');
        }

        $mail->send();
        return ['success' => true, 'error' => ''];
    } catch (MailerException $e) {
        $error = $mail->ErrorInfo;
        error_log("Customer email failed for order $orderId: " . $error);
        return ['success' => false, 'error' => $error];
    }
}

/**
 * Send admin notification email with full order details (HTML)
 */
function sendAdminEmail($conn, $orderId, $pdfContent = null, $siteUrl = '') {
    $debugFile = __DIR__ . '/admin_email_debug.log';
    file_put_contents($debugFile, "sendAdminEmail: START for order $orderId\n", FILE_APPEND);
    try {
        if (!function_exists('courierLabelFromCode')) {
            file_put_contents($debugFile, "sendAdminEmail: courierLabelFromCode missing\n", FILE_APPEND);
            return ['success' => false, 'error' => 'Missing courierLabelFromCode'];
        }
        if (!function_exists('cleanCountryName')) {
            file_put_contents($debugFile, "sendAdminEmail: cleanCountryName missing\n", FILE_APPEND);
            return ['success' => false, 'error' => 'Missing cleanCountryName'];
        }

        file_put_contents($debugFile, "sendAdminEmail: about to fetch order\n", FILE_APPEND);
        $order = $conn->query("
            SELECT 
                o.orderNumber, o.createdAt,
                o.subtotal, o.discountTotal, o.shippingCost, o.totalAmount,
                o.payment_method, o.courier, o.shipping_speed,
                o.shipping_address, o.shipping_city, o.shipping_postal_code, o.shipping_country,
                o.email, o.userID, o.status, o.transaction_id,
                u.full_name AS customerName,
                u.phone AS customerPhone,
                u.address AS billing_address,
                u.city AS billing_city,
                u.postcode AS billing_postal_code,
                u.country AS billing_country
            FROM orders o
            LEFT JOIN users u ON u.userID = o.userID
            WHERE o.orderID = $orderId
        ");
        if (!$order) {
            file_put_contents($debugFile, "sendAdminEmail: query failed\n", FILE_APPEND);
            return ['success' => false, 'error' => 'Query failed'];
        }
        $order = $order->fetch_assoc();
        if (!$order) {
            file_put_contents($debugFile, "sendAdminEmail: order not found for $orderId\n", FILE_APPEND);
            return ['success' => false, 'error' => 'Order not found'];
        }
        file_put_contents($debugFile, "sendAdminEmail: order found, orderNumber = " . ($order['orderNumber'] ?? 'N/A') . "\n", FILE_APPEND);

        file_put_contents($debugFile, "sendAdminEmail: about to fetch items\n", FILE_APPEND);
        $items = $conn->query("
            SELECT 
                oi.quantity, oi.unitPrice,
                oi.giftWrapping, oi.giftBagFlag, oi.giftMessage,
                p.nameEN, p.nameGR, p.sku
            FROM order_items oi
            LEFT JOIN products p ON oi.productID = p.productID
            WHERE oi.orderID = $orderId
        ");
        if (!$items) {
            file_put_contents($debugFile, "sendAdminEmail: items query failed\n", FILE_APPEND);
            return ['success' => false, 'error' => 'Items query failed'];
        }
        file_put_contents($debugFile, "sendAdminEmail: items fetched\n", FILE_APPEND);

        // Build data (same as before)
        $orderNumber = $order['orderNumber'] ?? 'ORD-' . $orderId;
        $orderDate = date('F j, Y', strtotime($order['createdAt']));
        $orderTime = date('g:i a', strtotime($order['createdAt']));
        $subtotal = number_format((float)$order['subtotal'], 2);
        $discount = number_format((float)($order['discountTotal'] ?? 0), 2);
        $shipping = number_format((float)$order['shippingCost'], 2);
        $total = number_format((float)$order['totalAmount'], 2);
        $customerName = $order['customerName'] ?: ($order['email'] ?: 'Guest');
        $customerEmail = $order['email'] ?? '—';
        $customerPhone = $order['customerPhone'] ?: 'Not provided';
        $paymentMethod = ucfirst(str_replace('_', ' ', $order['payment_method'] ?? 'N/A'));
        $transactionId = htmlspecialchars($order['transaction_id'] ?? '—');
        $courierLabel = courierLabelFromCode($order['courier']);
        $shippingSpeed = $order['shipping_speed'] ?? 'standard';
        $orderStatus = ucfirst($order['status']);

        // Clean country names
        $billingCountry = cleanCountryName($order['billing_country'] ?? '');
        $shippingCountry = cleanCountryName($order['shipping_country'] ?? '');

        // Addresses
        $shippingParts = array_filter([
            $order['shipping_address'],
            $order['shipping_city'],
            $order['shipping_postal_code'],
            $shippingCountry
        ]);
        $shippingAddress = $shippingParts ? implode(', ', $shippingParts) : 'Not provided';

        $billingParts = array_filter([
            $order['billing_address'] ?: $order['shipping_address'],
            $order['billing_city'] ?: $order['shipping_city'],
            $order['billing_postal_code'] ?: $order['shipping_postal_code'],
            $billingCountry
        ]);
        $billingAddress = $billingParts ? implode(', ', $billingParts) : 'Not provided';

        // Items HTML
        $itemsHtml = '';
        while ($item = $items->fetch_assoc()) {
            $name = htmlspecialchars($item['nameEN'] ?: $item['nameGR'] ?: 'Product');
            $sku = htmlspecialchars($item['sku'] ?? '—');
            $qty = (int)$item['quantity'];
            $price = number_format((float)$item['unitPrice'], 2);
            $lineTotal = number_format($qty * $item['unitPrice'], 2);

            $giftBits = [];
            if (!empty($item['giftWrapping'])) $giftBits[] = 'Gift Wrap (+€2.00)';
            if (!empty($item['giftBagFlag'])) $giftBits[] = 'Gift Bag (+€1.50)';
            if (!empty($item['giftMessage'])) $giftBits[] = 'Message: "' . htmlspecialchars($item['giftMessage']) . '"';
            $giftText = $giftBits ? '<br><small>' . implode(' | ', $giftBits) . '</small>' : '';

            $itemsHtml .= '
              <tr>
                <td style="padding:8px; border-bottom:1px solid #ede2ff;"><strong>' . $name . '</strong><br><small>SKU: ' . $sku . '</small>' . $giftText . '</td>
                <td style="padding:8px; border-bottom:1px solid #ede2ff; text-align:center;">' . $qty . '</td>
                <td style="padding:8px; border-bottom:1px solid #ede2ff; text-align:right;">€' . $price . '</td>
                <td style="padding:8px; border-bottom:1px solid #ede2ff; text-align:right;">€' . $lineTotal . '</td>
              </tr>';
        }

        file_put_contents($debugFile, "sendAdminEmail: building HTML body\n", FILE_APPEND);
        // Build HTML – admin email with reminder (original style)
        $body = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>New Order #' . $orderNumber . '</title>
    <style>
        body { font-family: "Helvetica Neue", Arial, sans-serif; line-height: 1.6; color: #333; background: #faf5ff; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 24px; box-shadow: 0 8px 20px rgba(0,0,0,0.05); overflow: hidden; }
        .header { background: linear-gradient(135deg, #f8e1ff 0%, #e9d4ff 100%); padding: 30px 20px; text-align: center; border-bottom: 2px solid #d9b8ff; }
        .header h1 { margin: 0; font-size: 28px; color: #6a1b9a; letter-spacing: -0.5px; }
        .header p { margin: 5px 0 0; color: #8a6aad; font-size: 14px; }
        .content { padding: 30px 25px; }
        .order-details { background: #fef9ff; border-left: 4px solid #c9a9f5; padding: 15px; margin: 20px 0; border-radius: 12px; }
        .order-details p { margin: 5px 0; }
        .order-summary { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .order-summary th { background: #f5edff; text-align: left; padding: 12px; font-weight: 600; color: #4a2f6e; }
        .order-summary td { padding: 10px; border-bottom: 1px solid #ede2ff; }
        .totals { text-align: right; margin-top: 20px; padding: 10px; background: #faf5ff; border-radius: 12px; }
        .footer { background: #f9f3ff; padding: 20px; text-align: center; font-size: 12px; color: #8a6aad; border-top: 1px solid #e6d6ff; }
        .address-box { background: #fef9ff; padding: 12px; margin: 10px 0; border-radius: 12px; border: 1px solid #ede2ff; }
        .address-box h4 { margin: 0 0 8px 0; color: #6a1b9a; }
        .grid { display: flex; gap: 20px; flex-wrap: wrap; margin: 20px 0; }
        .grid .address-box { flex: 1; min-width: 200px; }
        .admin-note { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 12px; color: #856404; }
        .admin-note strong { color: #b45200; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>New Order Alert</h1>
        <p>Order #' . $orderNumber . '</p>
    </div>
    <div class="content">
        <p>Hello Admin,</p>
        <p>A new order has been placed and payment is confirmed. Details are below.</p>

        <div class="order-details">
            <p><strong>Order Number:</strong> ' . $orderNumber . '</p>
            <p><strong>Order Date:</strong> ' . $orderDate . ' at ' . $orderTime . '</p>
            <p><strong>Payment Method:</strong> ' . $paymentMethod . '</p>
            <p><strong>Transaction ID:</strong> ' . $transactionId . '</p>
            <p><strong>Courier:</strong> ' . $courierLabel . ' (' . $shippingSpeed . ')</p>
            <p><strong>Status:</strong> ' . $orderStatus . '</p>
        </div>

        <div class="grid">
            <div class="address-box">
                <h4>Billing Address</h4>
                <p>' . nl2br(htmlspecialchars($billingAddress)) . '<br>
                Phone: ' . htmlspecialchars($customerPhone) . '<br>
                Email: ' . htmlspecialchars($customerEmail) . '</p>
            </div>
            <div class="address-box">
                <h4>Shipping Address</h4>
                <p>' . nl2br(htmlspecialchars($shippingAddress)) . '</p>
            </div>
        </div>

        <h3 style="color:#6a1b9a;">Order Items</h3>
        <table class="order-summary">
            <thead>
                <tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr>
            </thead>
            <tbody>' . $itemsHtml . '</tbody>
        </table>

        <div class="totals">
            <p>Subtotal: €' . $subtotal . '</p>
            ' . ($discount > 0 ? '<p>Discount: -€' . $discount . '</p>' : '') . '
            <p>Shipping: €' . $shipping . '</p>
            <p><strong>Total: €' . $total . '</strong></p>
        </div>

        <div class="admin-note">
            <strong>📦 Action Required:</strong> After you ship this order, please update its status to <strong>"In Transit"</strong> in the admin panel.<br>
            The customer will automatically receive a separate email with tracking information when you change the status.<br>
            <strong>Admin panel:</strong> /admin/order_management.php?view=' . $orderId . '
        </div>

        <p>If you need to contact the customer, use: ' . htmlspecialchars($customerEmail) . '</p>
    </div>
    <div class="footer">
        <p>Creations by Athina — Handmade with love</p>
        <p>admin@festival-web.com | +30 123 456 7890</p>
    </div>
</div>
</body>
</html>';

        $plainBody = strip_tags(str_replace(['</p>','<br>'], "\n", $body));

        file_put_contents($debugFile, "sendAdminEmail: about to send email\n", FILE_APPEND);
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_USER, 'Creations by Athina');
        $mail->addAddress(ADMIN_EMAIL, 'Admin');
        $mail->addBCC('chrisanton1705@gmail.com'); // Backup BCC (replace with your email)
        $mail->isHTML(true);
        $mail->Subject = "New Order Alert: #{$orderNumber}";
        $mail->Body    = $body;
        $mail->AltBody = $plainBody;

        if ($pdfContent !== null && !empty($pdfContent)) {
            $mail->addStringAttachment($pdfContent, 'Receipt_' . $orderNumber . '.pdf', 'base64', 'application/pdf');
        }

        $mail->send();
        file_put_contents($debugFile, "sendAdminEmail: SUCCESSFULLY SENT\n", FILE_APPEND);
        return ['success' => true, 'error' => ''];
    } catch (Exception $e) {
        $error = $e->getMessage();
        file_put_contents($debugFile, "sendAdminEmail: EXCEPTION - " . $error . "\n");
        file_put_contents($debugFile, "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
        return ['success' => false, 'error' => $error];
    }
}
// ----------------------------------------------------------------------
// Main payment processing
// ----------------------------------------------------------------------

if (!isset($_GET['payment_intent'])) {
    if (isset($_GET['order_id']) && isset($_GET['total'])) {
        $orderId = (int)$_GET['order_id'];
        $total = (float)$_GET['total'];
        header('Location: payment_page.php?order_id=' . $orderId . '&total=' . $total);
        exit;
    } else {
        die('No payment intent and no order parameters.');
    }
}

$paymentIntentId = $_GET['payment_intent'];

\Stripe\Stripe::setApiKey('sk_test_51TAzuZGyWmaADCSMb0S3yFiYoj5N5MTwgSxYOcSXJECO4pQCMEznVsFnHmDNAjQbK0T4GUvb1vzo3BtleEmqfcjK00tREY4Xf7');

try {
    $intent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
    $paymentMethod = $intent->payment_method_types[0];

    if ($intent->status === 'canceled' || (isset($_GET['redirect_status']) && $_GET['redirect_status'] === 'canceled')) {
        $orderId = $intent->metadata->order_id ?? 0;
        if ($orderId) {
            $orderResult = $conn->query("SELECT totalAmount FROM orders WHERE orderID = $orderId");
            $total = ($orderResult && $orderResult->num_rows > 0) ? (float)$orderResult->fetch_assoc()['totalAmount'] : ($intent->amount / 100);
            header('Location: payment_page.php?order_id=' . $orderId . '&total=' . $total);
            exit;
        } else {
            header('Location: cart.php');
            exit;
        }
    }

    if ($intent->status !== 'succeeded') {
        throw new Exception('Payment not successful.');
    }

    $orderId = $intent->metadata->order_id ?? null;
    if (!$orderId) {
        throw new Exception('Order ID missing.');
    }

    $conn->begin_transaction();

    $checkoutData = $_SESSION['checkout_data'] ?? [];
    $updateFields = [];
    $updateParams = [];
    $types = "";
    $fieldMap = [
        'shipping_address'   => 's',
        'shipping_city'      => 's',
        'shipping_postal_code' => 's',
        'shipping_country'   => 's',
        'payment_method'     => 's',
        'courier'            => 's',
        'shipping_speed'     => 's',
    ];
    foreach ($fieldMap as $field => $type) {
        if (isset($checkoutData[$field]) && $checkoutData[$field] !== '') {
            $updateFields[] = "$field = ?";
            $updateParams[] = $checkoutData[$field];
            $types .= $type;
        }
    }
    if (!empty($updateFields)) {
        $updateParams[] = $orderId;
        $types .= "i";
        $updateSql = "UPDATE orders SET " . implode(", ", $updateFields) . " WHERE orderID = ?";
        $stmt = $conn->prepare($updateSql);
        if ($stmt) {
            $stmt->bind_param($types, ...$updateParams);
            $stmt->execute();
            $stmt->close();
        }
    }

    $stmt = $conn->prepare("UPDATE orders SET payment_status = 'paid', status = 'confirmed', transaction_id = ? WHERE orderID = ? AND payment_status != 'paid'");
    $stmt->bind_param("si", $paymentIntentId, $orderId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        deductStockAfterOrderCompletion($orderId, $conn);
    }

    $conn->commit();

    $ord = $conn->query("SELECT orderNumber, email, userID FROM orders WHERE orderID = $orderId")->fetch_assoc();
    if (!$ord) throw new Exception('Order not found after update.');

    $orderNumber = $ord['orderNumber'] ?? 'ORD-' . $orderId;
    $customerEmail = $ord['email'] ?? '';
    $customerName = 'Customer';
    if ($ord['userID']) {
        $user = $conn->query("SELECT full_name FROM users WHERE userID = {$ord['userID']}")->fetch_assoc();
        $customerName = $user['full_name'] ?? 'Customer';
    }

    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($basePath === '' || $basePath === '.') $basePath = '';
    $siteUrl = $protocol . '://' . $host . $basePath;

    $pdfContent = null;
    try {
        $pdfContent = generateReceiptPDF($conn, $orderId, $siteUrl);
    } catch (Exception $e) {
        error_log("PDF generation failed: " . $e->getMessage());
    }

    $customerEmailResult = ['success' => false, 'error' => 'No email provided'];
    if ($customerEmail) {
        $customerEmailResult = sendCustomerEmail($conn, $orderId, $customerEmail, $customerName, $pdfContent, $siteUrl);
    }
    $debugFile = __DIR__ . '/admin_email_debug.log';
    file_put_contents($debugFile, "=== " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);
    file_put_contents($debugFile, "Main: about to call sendAdminEmail for order $orderId\n", FILE_APPEND);
    $adminEmailResult = sendAdminEmail($conn, $orderId, $pdfContent, $siteUrl);
    file_put_contents($debugFile, "Main: sendAdminEmail result = " . json_encode($adminEmailResult) . "\n", FILE_APPEND);

    $_SESSION['checkout_result'] = [
        'order_id'                => $orderId,
        'order_number'            => $orderNumber,
        'confirmation_email_sent' => $customerEmailResult['success'],
        'confirmation_email_error' => $customerEmailResult['error'] ?: '',
        'confirmation_email_to'   => $customerEmail,
        'account_created'         => isset($_SESSION['temp_password']),
    ];

    unset($_SESSION['checkout_data']);

    header('Location: modules/checkout_success.php?order_id=' . $orderId);
    exit;

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    error_log("Payment error: " . $e->getMessage());
    die('Payment failed: ' . $e->getMessage());
}
// Test admin email – visit process_payment.php?test_admin=123 (replace 123 with a real order ID)
if (isset($_GET['test_admin']) && is_numeric($_GET['test_admin'])) {
    $testOrderId = (int)$_GET['test_admin'];
    echo "<h2>Testing admin email for order ID: $testOrderId</h2>";
    $result = sendAdminEmail($conn, $testOrderId, null, $siteUrl ?? '');
    echo "<pre>";
    print_r($result);
    echo "</pre>";
    exit;
}
?>