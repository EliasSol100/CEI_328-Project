<?php
session_start();
require_once __DIR__ . "/../authentication/database.php";

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo "Database connection error.";
    exit;
}

$sessionUserId = null;
if (isset($_SESSION["user"]) && is_array($_SESSION["user"])) {
    if (isset($_SESSION["user"]["id"])) {
        $sessionUserId = (int)$_SESSION["user"]["id"];
    } elseif (isset($_SESSION["user"]["userID"])) {
        $sessionUserId = (int)$_SESSION["user"]["userID"];
    }
}
if ($sessionUserId === null && isset($_SESSION["user_id"])) {
    $sessionUserId = (int)$_SESSION["user_id"];
}

$role = strtolower((string)($_SESSION["user"]["role"] ?? $_SESSION["role"] ?? "guest"));
$isAdmin = in_array($role, ["admin", "administrator", "superadmin"], true);

$orderId = isset($_GET["order_id"]) ? (int)$_GET["order_id"] : 0;
if ($orderId <= 0) {
    http_response_code(400);
    echo "Invalid order ID.";
    exit;
}

$orderSql = "
    SELECT
        o.orderID,
        o.orderNumber,
        o.userID,
        o.email,
        o.status,
        o.subtotal,
        o.discountTotal,
        o.shippingCost,
        o.totalAmount,
        o.createdAt,
        u.full_name AS customerName,
        u.email AS customerEmail,
        u.phone AS customerPhone
    FROM orders o
    LEFT JOIN users u ON u.userID = o.userID
    WHERE o.orderID = ?
    LIMIT 1
";

$orderStmt = $conn->prepare($orderSql);
if (!$orderStmt) {
    http_response_code(500);
    echo "Failed to prepare order query.";
    exit;
}
$orderStmt->bind_param("i", $orderId);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();
$order = $orderResult->fetch_assoc();
$orderStmt->close();

if (!$order) {
    http_response_code(404);
    echo "Order not found.";
    exit;
}

$ownerUserId = isset($order["userID"]) ? (int)$order["userID"] : 0;
$isOwner = ($sessionUserId !== null && $ownerUserId > 0 && $sessionUserId === $ownerUserId);

if (!$isAdmin && !$isOwner) {
    http_response_code(403);
    echo "You do not have permission to view this receipt.";
    exit;
}

$payments = [];
$paymentStmt = $conn->prepare("
    SELECT paymentID, provider, transactionID, paymentStatus, amount, currency, timestamp
    FROM payments
    WHERE orderID = ?
    ORDER BY timestamp DESC
");
if (!$paymentStmt) {
    http_response_code(500);
    echo "Failed to prepare payment query.";
    exit;
}
$paymentStmt->bind_param("i", $orderId);
$paymentStmt->execute();
$paymentRes = $paymentStmt->get_result();
while ($row = $paymentRes->fetch_assoc()) {
    $payments[] = $row;
}
$paymentStmt->close();

$allowedReceiptPaymentStatuses = ["paid", "captured", "completed", "succeeded"];
$paidPayment = null;
foreach ($payments as $payment) {
    $paymentStatus = strtolower((string)($payment["paymentStatus"] ?? ""));
    if (in_array($paymentStatus, $allowedReceiptPaymentStatuses, true)) {
        $paidPayment = $payment;
        break;
    }
}

if ($paidPayment === null) {
    http_response_code(403);
    echo "Receipt is available only for paid orders.";
    exit;
}

$items = [];
$itemsSql = "
    SELECT
        oi.quantity,
        oi.unitPrice,
        oi.giftWrapping,
        oi.giftBagFlag,
        oi.giftMessage,
        p.sku,
        p.nameEN,
        p.nameGR,
        pv.size,
        pv.yarnType,
        c.colorName
    FROM order_items oi
    LEFT JOIN products p ON p.productID = oi.productID
    LEFT JOIN product_variations pv ON pv.variationID = oi.variationID
    LEFT JOIN colors c ON c.colorID = pv.colorID
    WHERE oi.orderID = ?
    ORDER BY oi.orderItemID ASC
";
$itemsStmt = $conn->prepare($itemsSql);
if (!$itemsStmt) {
    http_response_code(500);
    echo "Failed to prepare order items query.";
    exit;
}
$itemsStmt->bind_param("i", $orderId);
$itemsStmt->execute();
$itemsRes = $itemsStmt->get_result();
while ($row = $itemsRes->fetch_assoc()) {
    $items[] = $row;
}
$itemsStmt->close();

$orderNumber = (string)($order["orderNumber"] ?? ("ORD-" . $orderId));
$customerName = trim((string)($order["customerName"] ?? ""));
$customerEmail = trim((string)($order["customerEmail"] ?? ""));
if ($customerEmail === "") {
    $customerEmail = trim((string)($order["email"] ?? ""));
}
if ($customerName === "") {
    $customerName = $customerEmail !== "" ? $customerEmail : "Customer";
}

$generatedAt = date("d/m/Y H:i");
$backLink = $isAdmin ? "admin/order_management.php?view=" . $orderId : "../profile/account.php?tab=orders";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt <?= htmlspecialchars($orderNumber) ?></title>
    <style>
        :root {
            --bg: #f4f6f8;
            --card: #ffffff;
            --text: #111827;
            --muted: #6b7280;
            --line: #e5e7eb;
            --accent: #1f2937;
            --ok: #166534;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 28px 16px;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }
        .receipt-wrap {
            max-width: 900px;
            margin: 0 auto;
        }
        .toolbar {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-bottom: 12px;
        }
        .btn {
            display: inline-block;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--text);
            padding: 8px 12px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
        }
        .btn:hover { border-color: #cbd5e1; }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 24px;
        }
        .head {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--line);
        }
        .brand {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .meta {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }
        .status-paid {
            color: var(--ok);
            font-weight: 700;
            font-size: 13px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 16px;
        }
        .box {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px;
        }
        .box h4 {
            margin: 0 0 8px 0;
            font-size: 13px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .box p {
            margin: 0;
            line-height: 1.55;
            font-size: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        th, td {
            text-align: left;
            border-bottom: 1px solid var(--line);
            padding: 10px 6px;
            font-size: 14px;
            vertical-align: top;
        }
        th { color: var(--muted); font-weight: 600; }
        .t-right { text-align: right; }
        .muted { color: var(--muted); }
        .totals {
            width: 320px;
            margin-left: auto;
            margin-top: 14px;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 14px;
        }
        .totals-total {
            border-top: 1px solid var(--line);
            margin-top: 4px;
            padding-top: 10px;
            font-weight: 700;
            font-size: 16px;
        }
        .foot {
            margin-top: 18px;
            color: var(--muted);
            font-size: 12px;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none; }
            .card { border: none; border-radius: 0; padding: 0; }
        }
    </style>
</head>
<body>
<div class="receipt-wrap">
    <div class="toolbar">
        <a href="<?= htmlspecialchars($backLink) ?>" class="btn">Back</a>
        <button type="button" class="btn" onclick="window.print()">Print</button>
    </div>

    <section class="card">
        <header class="head">
            <div>
                <div class="brand">Athina E-Shop Receipt</div>
                <div class="meta">Receipt generated: <?= htmlspecialchars($generatedAt) ?></div>
                <div class="meta">Order: <?= htmlspecialchars($orderNumber) ?></div>
            </div>
            <div class="meta" style="text-align:right;">
                <div class="status-paid">Payment Verified</div>
                <div>Status: <?= htmlspecialchars((string)$order["status"]) ?></div>
                <div>Date: <?= htmlspecialchars((string)$order["createdAt"]) ?></div>
            </div>
        </header>

        <div class="grid">
            <div class="box">
                <h4>Customer</h4>
                <p>
                    <?= htmlspecialchars($customerName) ?><br>
                    <?= htmlspecialchars($customerEmail) ?><br>
                    <?php if (!empty($order["customerPhone"])): ?>
                        <?= htmlspecialchars((string)$order["customerPhone"]) ?>
                    <?php else: ?>
                        <span class="muted">Phone not available</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="box">
                <h4>Payment</h4>
                <p>
                    Provider: <?= htmlspecialchars((string)$paidPayment["provider"]) ?><br>
                    Status: <?= htmlspecialchars((string)$paidPayment["paymentStatus"]) ?><br>
                    Transaction: <?= htmlspecialchars((string)($paidPayment["transactionID"] ?? "-")) ?><br>
                    Date: <?= htmlspecialchars((string)$paidPayment["timestamp"]) ?>
                </p>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>SKU</th>
                    <th class="t-right">Qty</th>
                    <th class="t-right">Unit</th>
                    <th class="t-right">Line Total</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr>
                    <td colspan="5" class="muted">No order items found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <?php
                    $name = (string)($item["nameEN"] ?: $item["nameGR"] ?: "Product");
                    $qty = (int)($item["quantity"] ?? 0);
                    $unit = (float)($item["unitPrice"] ?? 0);
                    $lineTotal = $unit * $qty;
                    $variantParts = [];
                    if (!empty($item["colorName"])) {
                        $variantParts[] = (string)$item["colorName"];
                    }
                    if (!empty($item["size"])) {
                        $variantParts[] = (string)$item["size"];
                    }
                    if (!empty($item["yarnType"])) {
                        $variantParts[] = (string)$item["yarnType"];
                    }
                    $variantText = empty($variantParts) ? "" : implode(" / ", $variantParts);

                    $addonParts = [];
                    if ((int)($item["giftWrapping"] ?? 0) === 1) {
                        $addonParts[] = "Gift wrap";
                    }
                    if ((int)($item["giftBagFlag"] ?? 0) === 1) {
                        $addonParts[] = "Gift bag";
                    }
                    if (!empty($item["giftMessage"])) {
                        $addonParts[] = "Message: " . (string)$item["giftMessage"];
                    }
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($name) ?></strong>
                            <?php if ($variantText !== ""): ?>
                                <div class="muted"><?= htmlspecialchars($variantText) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($addonParts)): ?>
                                <div class="muted"><?= htmlspecialchars(implode(" | ", $addonParts)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string)($item["sku"] ?? "-")) ?></td>
                        <td class="t-right"><?= $qty ?></td>
                        <td class="t-right">EUR <?= number_format($unit, 2) ?></td>
                        <td class="t-right">EUR <?= number_format($lineTotal, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="totals">
            <div class="totals-row">
                <span>Subtotal</span>
                <span>EUR <?= number_format((float)($order["subtotal"] ?? 0), 2) ?></span>
            </div>
            <div class="totals-row">
                <span>Discount</span>
                <span>EUR <?= number_format((float)($order["discountTotal"] ?? 0), 2) ?></span>
            </div>
            <div class="totals-row">
                <span>Shipping</span>
                <span>EUR <?= number_format((float)($order["shippingCost"] ?? 0), 2) ?></span>
            </div>
            <div class="totals-row totals-total">
                <span>Total Paid</span>
                <span>EUR <?= number_format((float)($order["totalAmount"] ?? 0), 2) ?></span>
            </div>
        </div>

        <div class="foot">
            This receipt is generated from payment-confirmed order data.
        </div>
    </section>
</div>
</body>
</html>
