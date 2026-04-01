<?php
session_start();
require_once __DIR__ . "/../authentication/database.php";

// ... (keep all your existing helper functions: ensureOrderShippingSchema, tableExists, fallbackShippingLabelForUser, courierLabelFromCode, inferCourierCode) ...

// At the top of the file after includes, add:
$siteUrl = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$logoUrl = $siteUrl . '/assets/images/logo.png'; // adjust path if needed

// The rest of the receipt data fetching (order, payments, items) remains unchanged
// ... (keep everything up to the HTML generation)

// At the end, replace the HTML with the new styled version:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt <?= htmlspecialchars($orderNumber) ?></title>
    <style>
        body {
            font-family: "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #faf5ff;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 700px;
            margin: 0 auto;
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #f8e1ff 0%, #e9d4ff 100%);
            padding: 30px 20px;
            text-align: center;
            border-bottom: 2px solid #d9b8ff;
        }
        .header img {
            max-height: 60px;
            margin-bottom: 10px;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            color: #6a1b9a;
            letter-spacing: -0.5px;
        }
        .header p {
            margin: 5px 0 0;
            color: #8a6aad;
            font-size: 14px;
        }
        .content {
            padding: 30px 25px;
        }
        .order-details {
            background: #fef9ff;
            border-left: 4px solid #c9a9f5;
            padding: 15px;
            margin: 20px 0;
            border-radius: 12px;
        }
        .order-details p {
            margin: 5px 0;
        }
        .address-box {
            background: #fef9ff;
            padding: 12px;
            margin: 10px 0;
            border-radius: 12px;
            border: 1px solid #ede2ff;
        }
        .address-box h4 {
            margin: 0 0 8px 0;
            color: #6a1b9a;
        }
        .order-summary {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .order-summary th {
            background: #f5edff;
            text-align: left;
            padding: 12px;
            font-weight: 600;
            color: #4a2f6e;
        }
        .order-summary td {
            padding: 10px;
            border-bottom: 1px solid #ede2ff;
        }
        .totals {
            text-align: right;
            margin-top: 20px;
            padding: 10px;
            background: #faf5ff;
            border-radius: 12px;
        }
        .footer {
            background: #f9f3ff;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #8a6aad;
            border-top: 1px solid #e6d6ff;
        }
        .tracking-note {
            margin: 20px 0 10px;
            text-align: center;
            font-size: 13px;
            color: #6a1b9a;
        }
        .toolbar {
            max-width: 700px;
            margin: 0 auto 15px auto;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .btn {
            display: inline-block;
            border: 1px solid #e0d4f0;
            background: #fff;
            color: #6a1b9a;
            padding: 8px 12px;
            border-radius: 40px;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn:hover {
            background: #f5edff;
            border-color: #c9a9f5;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none; }
            .container { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <a href="<?= htmlspecialchars($backLink) ?>" class="btn">Back</a>
    <button type="button" class="btn" onclick="window.print()">Print</button>
</div>

<div class="container">
    <div class="header">
        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Creations by Athina">
        <h1>Creations by Athina</h1>
        <p>Order Receipt</p>
    </div>
    <div class="content">
        <div class="order-details">
            <p><strong>Order Number:</strong> <?= htmlspecialchars($orderNumber) ?></p>
            <p><strong>Order Date:</strong> <?= htmlspecialchars(date('F j, Y', strtotime($order['createdAt']))) ?> at <?= htmlspecialchars(date('g:i a', strtotime($order['createdAt']))) ?></p>
            <p><strong>Payment Method:</strong> <?= htmlspecialchars($paidPayment['provider'] ?? 'N/A') ?></p>
            <p><strong>Transaction ID:</strong> <?= htmlspecialchars($paidPayment['transactionID'] ?? '—') ?></p>
            <p><strong>Courier:</strong> <?= htmlspecialchars($courierLabel) ?> (<?= htmlspecialchars($shippingPriority) ?>)</p>
            <p><strong>Status:</strong> <?= htmlspecialchars($order['status'] ?? 'confirmed') ?></p>
        </div>

        <div class="address-box">
            <h4>Shipping Address</h4>
            <p><?= nl2br(htmlspecialchars($shippingAddressText)) ?><br>
            Phone: <?= htmlspecialchars($order['customerPhone'] ?? 'Not provided') ?><br>
            Email: <?= htmlspecialchars($customerEmail) ?></p>
        </div>

        <h3 style="color:#6a1b9a;">Order Items</h3>
        <table class="order-summary">
            <thead>
                <tr><th>Item</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="4" class="muted">No order items found.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <?php
                    $name = (string)($item['nameEN'] ?: $item['nameGR'] ?: 'Product');
                    $qty = (int)($item['quantity'] ?? 0);
                    $unit = (float)($item['unitPrice'] ?? 0);
                    $lineTotal = $unit * $qty;

                    $variantParts = [];
                    if (!empty($item['colorName'])) $variantParts[] = $item['colorName'];
                    if (!empty($item['size'])) $variantParts[] = $item['size'];
                    if (!empty($item['yarnType'])) $variantParts[] = $item['yarnType'];
                    $variantText = $variantParts ? implode(' / ', $variantParts) : '';

                    $addonParts = [];
                    if ((int)($item['giftWrapping'] ?? 0) === 1) $addonParts[] = 'Gift wrap';
                    if ((int)($item['giftBagFlag'] ?? 0) === 1) $addonParts[] = 'Gift bag';
                    if (!empty($item['giftMessage'])) $addonParts[] = 'Message: "' . htmlspecialchars($item['giftMessage']) . '"';
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($name) ?></strong>
                            <?php if ($variantText): ?><div class="muted" style="color:#8a6aad;"><?= htmlspecialchars($variantText) ?></div><?php endif; ?>
                            <?php if ($addonParts): ?><div class="muted" style="color:#8a6aad;"><?= htmlspecialchars(implode(' | ', $addonParts)) ?></div><?php endif; ?>
                        </td>
                        <td style="text-align:center;"><?= $qty ?></td>
                        <td style="text-align:right;">€<?= number_format($unit, 2) ?></td>
                        <td style="text-align:right;">€<?= number_format($lineTotal, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="totals">
            <p>Subtotal: €<?= number_format((float)($order['subtotal'] ?? 0), 2) ?></p>
            <?php if (($order['discountTotal'] ?? 0) > 0): ?>
                <p>Discount: -€<?= number_format((float)($order['discountTotal'] ?? 0), 2) ?></p>
            <?php endif; ?>
            <p>Shipping: €<?= number_format((float)($order['shippingCost'] ?? 0), 2) ?></p>
            <p><strong>Total: €<?= number_format((float)($order['totalAmount'] ?? 0), 2) ?></strong></p>
        </div>

        <div class="tracking-note">
            <p>You will receive a separate email with tracking information once your order is in transit.</p>
        </div>

        <p>If you have any questions, please <a href="<?= $siteUrl ?>/contact.php">contact us</a>.</p>
    </div>
    <div class="footer">
        <p>Creations by Athina — Handmade with love</p>
        <p><a href="mailto:admin@festival-web.com">admin@festival-web.com</a> | +30 123 456 7890</p>
        <p><a href="<?= $siteUrl ?>/contact.php">Contact Us</a></p>
    </div>
</div>
</body>
</html>
