<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'order_management';
$flash = '';

if (empty($_SESSION['admin_order_token'])) {
    $_SESSION['admin_order_token'] = bin2hex(random_bytes(32));
}

$statusOptions = [
    'pending'       => 'Pending',
    'accepted'      => 'Accepted',
    'in_production' => 'In Production',
    'shipped'       => 'Shipped',
    'delivered'     => 'Delivered',
    'completed'     => 'Completed',
    'cancelled'     => 'Cancelled',
];
$statusBadge = [
    'pending'       => 'badge-muted',
    'accepted'      => 'badge-green',
    'in_production' => 'badge-orange',
    'shipped'       => 'badge-purple',
    'delivered'     => 'badge-dark',
    'completed'     => 'badge-dark',
    'cancelled'     => 'badge-red',
];

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

    if ($orderID > 0 && isset($statusOptions[$status])) {
        $stmt = mysqli_prepare($conn, 'UPDATE orders SET status = ? WHERE orderID = ?');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'si', $status, $orderID);
            mysqli_stmt_execute($stmt);
            $affected = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);

            if ($affected >= 0) {
                $flash = 'ok:Order status updated.';

                $normalizedStatus = strtolower($status);
                if ($normalizedStatus === 'delivered') {
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
                                    $flash = 'ok:Order status updated. Review notification sent.';
                                } else {
                                    error_log('Review notification email failed for order #' . $orderID . ': ' . (string)$mailResult['error']);
                                    $flash = 'ok:Order status updated. Review notification email failed.';
                                }
                            } else {
                                $flash = 'ok:Order status updated. Review notification already sent.';
                            }
                        } else {
                            $flash = 'ok:Order status updated. No valid customer email for review notification.';
                        }
                    } else {
                        $flash = 'ok:Order status updated. Review notification pending payment confirmation.';
                    }
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
            COALESCE(NULLIF(u.full_name, ""), "Guest") AS customer,
            COALESCE(u.email, o.email, "-") AS customerEmail,
            COALESCE(u.phone, "-") AS phone,
            lp.paymentStatus,
            lp.provider
        FROM orders o
        LEFT JOIN users u ON u.userID = o.userID
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
                $label = $statusOptions[$o['status']] ?? (string)$o['status'];
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
                      <?php foreach ($statusOptions as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $o['status'] === $val ? 'selected' : '' ?>>
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
