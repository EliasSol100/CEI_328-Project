<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../../include/security.php';
if (!defined('INCLUDE_CHECK')) define('INCLUDE_CHECK', true);
require_once __DIR__ . '/../custom_orders.php';
require_once __DIR__ . '/../create_custom_product.php';

$current_page = 'custom_orders';
$flash = '';

function ensureCustomOrderAdminSchema(mysqli $conn): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'custom_orders'");
    if (!$tableCheck || mysqli_num_rows($tableCheck) === 0) return;
    mysqli_query($conn, "ALTER TABLE custom_orders MODIFY COLUMN userID INT(11) NULL");
}

function adminGenerateCustomOrderCode(): string {
    return generateCustomOrderAccessCode();
}

function adminLoadPrivateLinkForOrder(mysqli $conn, array $order): array {
    $customOrderId = (int)($order['customOrderID'] ?? 0);
    $productId = (int)($order['sourceProductID'] ?? 0);
    if ($productId <= 0) return ['product_id' => 0, 'private_link' => '', 'customer_link' => ''];
    $stmt = mysqli_prepare($conn, "SELECT productID, cartStatus, privateAccessToken FROM products WHERE productID=? LIMIT 1");
    if (!$stmt) return ['product_id' => 0, 'private_link' => '', 'customer_link' => ''];
    mysqli_stmt_bind_param($stmt, 'i', $productId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row || trim((string)($row['cartStatus'] ?? '')) !== 'made_to_order') return ['product_id' => 0, 'private_link' => '', 'customer_link' => ''];
    $token = trim((string)($row['privateAccessToken'] ?? ''));
    if ($token === '') return ['product_id' => 0, 'private_link' => '', 'customer_link' => ''];
    $customerProductUrl = function_exists('customOrderFrontendUrl')
        ? customOrderFrontendUrl('product.php?id=' . (int)$row['productID'])
        : ('../../product.php?id=' . (int)$row['productID']);
    return [
        'product_id' => (int)$row['productID'],
        'private_link' => generateAccessLink((int)$row['productID'], 'token', $token, null),
        'customer_link' => $customerProductUrl,
    ];
}

function adminFormatDescriptionForTable(string $description): string {
    $description = trim(preg_replace("/\r\n?/", "\n", $description));
    if ($description === '') return '';

    $lineLabels = [
        'Project title:',
        'Requested quantity:',
        'Preferred budget:',
        'Needed by:',
        'Customer details:',
        'Size:',
        'Colour:',
        'Color:',
        'Notes:',
    ];

    foreach ($lineLabels as $label) {
        $description = preg_replace('/\s*' . preg_quote($label, '/') . '\s*/i', "\n" . $label . ' ', $description);
    }

    $description = preg_replace("/\n{2,}/", "\n", $description);
    return ltrim($description);
}

function adminFindCustomerByEmail(mysqli $conn, string $email): ?array {
    $email = normalizeCustomerEmail($email);
    if ($email === '') return null;
    $stmt = mysqli_prepare($conn, "
        SELECT userID, email, full_name
        FROM users
        WHERE LOWER(TRIM(email)) = ?
        LIMIT 1
    ");
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function adminPriceInputFromOrder(array $order): string {
    $min = (float)($order['agreedPrice'] ?? 0);
    $max = isset($order['agreedPriceMax']) ? (float)$order['agreedPriceMax'] : 0.0;
    if ($min <= 0) return '';
    if ($max > $min) return number_format($min, 2, '.', '') . ' - ' . number_format($max, 2, '.', '');
    return number_format($min, 2, '.', '');
}

function adminStructuredRequestPreview(array $order): string {
    return customOrderBuildProductDescription($order);
}

function adminPrepareAndEmailCheckout(mysqli $conn, int $customOrderId): array {
    $orderBefore = getCustomOrderById($conn, $customOrderId);
    $existingCode = normalizeCustomOrderAccessCode((string)($orderBefore['accessCode'] ?? ''));
    if ($existingCode === '') {
        $existingCode = adminGenerateCustomOrderCode();
        updateCustomOrder($conn, $customOrderId, ['accessCode' => $existingCode]);
    }
    $result = createCustomProductFromRequest($conn, $customOrderId);
    $order = getCustomOrderById($conn, $customOrderId);
    $accessCode = normalizeCustomOrderAccessCode((string)($order['accessCode'] ?? $result['access_code'] ?? ''));
    $customerLink = trim((string)($result['customer_link'] ?? ''));
    if ($customerLink === '') {
        $customerLink = customOrderCustomerUrl($customOrderId, 'private-checkout');
    }
    $emailSent = sendCustomOrderAcceptedEmail($conn, $customOrderId, $customerLink, $accessCode);
    addCustomOrderMessage(
        $conn,
        $customOrderId,
        'system',
        null,
        $emailSent
            ? 'Custom order accepted. Access-code checkout link was emailed to the customer.'
            : 'Custom order accepted and private product prepared, but the customer email could not be sent.'
    );
    return ['result' => $result, 'email_sent' => $emailSent, 'customer_link' => $customerLink, 'access_code' => $accessCode];
}

ensureCustomOrderAdminSchema($conn);
ensureCustomOrdersTable($conn);
$statusOptions = getCustomOrderStatusLabels();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');
    $action = (string)($_POST['action'] ?? '');
    $redirectViewId = 0;

    if ($action === 'add' || $action === 'edit') {
        $id = (int)($_POST['customOrderID'] ?? 0);
        $ideaTitle = trim((string)($_POST['ideaTitle'] ?? ''));
        $productType = trim((string)($_POST['productType'] ?? ''));
        $preferredSize = trim((string)($_POST['preferredSize'] ?? ''));
        $preferredColours = trim((string)($_POST['preferredColours'] ?? ''));
        $desc = trim((string)($_POST['requestDescription'] ?? ''));
        $priceInput = trim((string)($_POST['agreedPriceInput'] ?? $_POST['agreedPrice'] ?? ''));
        [$price, $priceMax] = customOrderNormalizeMoneyInput($priceInput);
        $sizePriceOptions = trim((string)($_POST['sizePriceOptions'] ?? ''));
        $parsedSizePrices = customOrderParseSizePriceOptions($sizePriceOptions);
        $deadline = trim((string)($_POST['deadline'] ?? ''));
        $code = normalizeCustomOrderAccessCode((string)($_POST['accessCode'] ?? ''));
        $email = normalizeCustomerEmail((string)($_POST['email'] ?? ''));
        $existingPhotoPath = '';
        $photoPathToSave = '';
        $customer = adminFindCustomerByEmail($conn, $email);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash = 'err:A valid customer email is required.';
        } elseif (!$customer) {
            $flash = 'err:This customer email does not belong to a registered account. Ask the customer to register first.';
        } elseif ($ideaTitle === '') {
            $flash = 'err:Add an idea title before saving the custom order.';
        } elseif ($desc === '') {
            $flash = 'err:Add a request description before saving the custom order.';
        } elseif ($price <= 0) {
            $flash = 'err:Add a valid agreed price before creating the private product.';
        } elseif ($priceMax !== null && empty($parsedSizePrices['prices'])) {
            $flash = 'err:Add exact size prices when the agreed price is a range.';
        } elseif ($sizePriceOptions !== '' && empty($parsedSizePrices['prices'])) {
            $flash = 'err:Use one size price per line, for example Small=20.';
        } else {
            if ($code === '') $code = adminGenerateCustomOrderCode();
            if ($action === 'edit') {
                $photoStmt = mysqli_prepare($conn, "SELECT photoReferencePath FROM custom_orders WHERE customOrderID=? LIMIT 1");
                if ($photoStmt) {
                    mysqli_stmt_bind_param($photoStmt, 'i', $id);
                    mysqli_stmt_execute($photoStmt);
                    $photoRes = mysqli_stmt_get_result($photoStmt);
                    $photoRow = $photoRes ? mysqli_fetch_assoc($photoRes) : null;
                    mysqli_stmt_close($photoStmt);
                    $existingPhotoPath = trim((string)($photoRow['photoReferencePath'] ?? ''));
                }
            }
            $photoPathToSave = $existingPhotoPath;
            if (!empty($_FILES['referencePhoto']) && (int)($_FILES['referencePhoto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $photoPathToSave = storeCustomOrderReferencePhoto($_FILES['referencePhoto']);
                } catch (Throwable $e) {
                    $flash = 'err:' . ($e->getMessage() !== '' ? $e->getMessage() : 'Could not upload the reference photo.');
                }
            }
            if ($flash === '') {
                $customerUserId = (int)($customer['userID'] ?? 0);
                $customerName = trim((string)($customer['full_name'] ?? ''));
                $priceMaxValue = $priceMax !== null ? $priceMax : null;
                if ($action === 'add') {
                    $stmt = mysqli_prepare($conn, "
                        INSERT INTO custom_orders (
                            userID, email, requestDescription, status, customerName,
                            ideaTitle, productType, preferredSize, preferredColours,
                            agreedPrice, agreedPriceMax, deadline, accessCode, sizePriceOptions, photoReferencePath
                        ) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    if ($stmt) {
                        mysqli_stmt_bind_param(
                            $stmt,
                            'isssssssddssss',
                            $customerUserId,
                            $email,
                            $desc,
                            $customerName,
                            $ideaTitle,
                            $productType,
                            $preferredSize,
                            $preferredColours,
                            $price,
                            $priceMaxValue,
                            $deadline,
                            $code,
                            $sizePriceOptions,
                            $photoPathToSave
                        );
                        $ok = mysqli_stmt_execute($stmt);
                        $newId = $ok ? (int)mysqli_insert_id($conn) : 0;
                        mysqli_stmt_close($stmt);
                        if ($ok && $newId > 0) {
                            $redirectViewId = $newId;
                            try {
                                $checkout = adminPrepareAndEmailCheckout($conn, $newId);
                                $flash = !empty($checkout['email_sent'])
                                    ? 'ok:Custom order created, private product prepared, and customer emailed with the access code.'
                                    : 'warn:Custom order created and private product prepared, but the customer email could not be sent.';
                            } catch (Throwable $e) {
                                $flash = 'warn:Custom order created, but the private product/email could not be prepared: ' . ($e->getMessage() !== '' ? $e->getMessage() : 'Unknown error.');
                            }
                        } else {
                            $flash = 'err:Could not create the custom order.';
                        }
                    } else {
                        $flash = 'err:Could not prepare the custom order insert.';
                    }
                } else {
                    $redirectViewId = $id;
                    $stmt = mysqli_prepare($conn, "
                        UPDATE custom_orders
                        SET userID=?, customerName=?, requestDescription=?, ideaTitle=?, productType=?,
                            preferredSize=?, preferredColours=?, agreedPrice=?, agreedPriceMax=?,
                            deadline=?, accessCode=?, email=?, sizePriceOptions=?, photoReferencePath=?
                        WHERE customOrderID=?
                    ");
                    if ($stmt) {
                        mysqli_stmt_bind_param(
                            $stmt,
                            'issssssddsssssi',
                            $customerUserId,
                            $customerName,
                            $desc,
                            $ideaTitle,
                            $productType,
                            $preferredSize,
                            $preferredColours,
                            $price,
                            $priceMaxValue,
                            $deadline,
                            $code,
                            $email,
                            $sizePriceOptions,
                            $photoPathToSave,
                            $id
                        );
                        $ok = mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                        $flash = $ok ? 'ok:Custom order updated.' : 'err:Could not update the custom order.';
                    } else {
                        $flash = 'err:Could not prepare the custom order update.';
                    }
                }
                if (strpos($flash, 'err:') === 0 && $photoPathToSave !== '' && $photoPathToSave !== $existingPhotoPath) deleteCustomOrderReferencePhoto($photoPathToSave);
                if (strpos($flash, 'ok:') === 0 && $photoPathToSave !== '' && $existingPhotoPath !== '' && $photoPathToSave !== $existingPhotoPath) deleteCustomOrderReferencePhoto($existingPhotoPath);
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['customOrderID'] ?? 0);
        $photoStmt = mysqli_prepare($conn, "SELECT photoReferencePath FROM custom_orders WHERE customOrderID=? LIMIT 1");
        if ($photoStmt) {
            mysqli_stmt_bind_param($photoStmt, 'i', $id);
            mysqli_stmt_execute($photoStmt);
            $photoRes = mysqli_stmt_get_result($photoStmt);
            $photoRow = $photoRes ? mysqli_fetch_assoc($photoRes) : null;
            mysqli_stmt_close($photoStmt);
            if (!empty($photoRow['photoReferencePath'])) deleteCustomOrderReferencePhoto((string)$photoRow['photoReferencePath']);
        }
        deleteCustomOrderConversation($conn, $id);
        $stmt = mysqli_prepare($conn, "DELETE FROM custom_orders WHERE customOrderID=?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        $flash = 'ok:Custom order deleted.';
    }

    if ($action === 'send_message') {
        $id = (int)($_POST['customOrderID'] ?? 0);
        $message = trim((string)($_POST['messageBody'] ?? ''));
        if ($id <= 0 || $message === '') {
            $flash = 'err:Write a message before sending.';
        } else {
            try {
                addCustomOrderMessage($conn, $id, 'admin', $ADMIN_USER_ID ?? null, $message);
                $emailSent = sendCustomOrderCustomerEmail(
                    $conn,
                    $id,
                    "Reply about your custom order #{$id}",
                    "Hello,\n\nAthina replied to your custom order request.\n\n" .
                    "Message:\n{$message}\n\n" .
                    "Reply here:\n" . customOrderCustomerUrl($id) . "\n\n" .
                    "Thank you,\nAthina E-Shop"
                );
                $flash = $emailSent ? 'ok:Reply sent and emailed to the customer.' : 'warn:Reply saved, but the customer email could not be sent.';
            } catch (Throwable $e) {
                $flash = 'err:' . ($e->getMessage() !== '' ? $e->getMessage() : 'Could not send the reply.');
            }
        }
        header('Location: custom_orders.php?view=' . $id . '&flash=' . urlencode($flash));
        exit;
    }

    if ($action === 'decline_request') {
        $id = (int)($_POST['customOrderID'] ?? 0);
        $message = trim((string)($_POST['declineMessage'] ?? ''));
        if ($message === '') {
            $message = "Thank you for your idea. Unfortunately, Athina cannot accept this custom order request at this time.";
        }
        try {
            updateCustomOrder($conn, $id, ['status' => 'declined']);
            addCustomOrderMessage($conn, $id, 'admin', $ADMIN_USER_ID ?? null, $message);
            $emailSent = sendCustomOrderDeclinedEmail($conn, $id, $message);
            $flash = $emailSent ? 'ok:Request declined and customer notified.' : 'warn:Request declined, but the customer email could not be sent.';
        } catch (Throwable $e) {
            $flash = 'err:' . ($e->getMessage() !== '' ? $e->getMessage() : 'Could not decline the request.');
        }
        header('Location: custom_orders.php?view=' . $id . '&flash=' . urlencode($flash));
        exit;
    }

    if ($action === 'create_checkout_link') {
        $id = (int)($_POST['customOrderID'] ?? 0);
        try {
            $checkout = adminPrepareAndEmailCheckout($conn, $id);
            $emailSent = !empty($checkout['email_sent']);
            $flash = $emailSent
                ? 'ok:Custom order accepted. Private product prepared and customer emailed with the access code.'
                : 'warn:Private product is ready, but the customer email could not be sent. Copy the private product URL manually.';
        } catch (Throwable $e) {
            $flash = 'err:' . ($e->getMessage() !== '' ? $e->getMessage() : 'Could not create the checkout link.');
        }
        header('Location: custom_orders.php?view=' . $id . '&flash=' . urlencode($flash));
        exit;
    }

    $target = $redirectViewId > 0 ? ('custom_orders.php?view=' . $redirectViewId) : 'custom_orders.php';
    $target .= (strpos($target, '?') === false ? '?' : '&') . 'flash=' . urlencode($flash);
    header('Location: ' . $target);
    exit;
}

if (isset($_GET['flash'])) $flash = (string)$_GET['flash'];

$orders = [];
$result = mysqli_query($conn, "SELECT co.*, COALESCE(NULLIF(co.customerName, ''), NULLIF(u.full_name, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), '')) AS displayName FROM custom_orders co LEFT JOIN users u ON u.userID = co.userID ORDER BY co.customOrderID DESC");
if ($result) while ($row = mysqli_fetch_assoc($result)) $orders[] = $row;

$editOrder = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    foreach ($orders as $orderRow) if ((int)$orderRow['customOrderID'] === $editId) { $editOrder = $orderRow; break; }
}

$viewOrder = null;
$viewPrivateCheckout = ['product_id' => 0, 'private_link' => '', 'customer_link' => ''];
$viewMessages = [];
if (isset($_GET['view'])) {
    $viewId = (int)$_GET['view'];
    foreach ($orders as $orderRow) if ((int)$orderRow['customOrderID'] === $viewId) { $viewOrder = $orderRow; break; }
    if ($viewOrder) {
        $viewPrivateCheckout = adminLoadPrivateLinkForOrder($conn, $viewOrder);
        $viewMessages = getCustomOrderMessages($conn, (int)$viewOrder['customOrderID']);
    }
}

$statusBadge = ['pending' => 'badge-muted', 'in_discussion' => 'badge-orange', 'accepted' => 'badge-completed', 'in_production' => 'badge-orange', 'ready_for_checkout' => 'badge-orange', 'in_progress' => 'badge-orange', 'completed' => 'badge-completed', 'declined' => 'badge-red', 'cancelled' => 'badge-red'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Custom Orders - Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/admin.css') ?>">
  <link rel="stylesheet" href="assets/custom_orders.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Custom Orders</h1>
        <p>Manage website and Instagram custom requests, then prepare each customer's private checkout access.</p>
      </div>
      <button class="btn-primary" type="button" onclick="openModal('modalAdd')"><i class="fas fa-plus"></i> New Custom Order</button>
    </div>
    <div class="content-body">
      <?php if ($flash): [$type, $msg] = array_pad(explode(':', $flash, 2), 2, ''); $flashClass = $type === 'ok' ? 'success' : ($type === 'warn' ? 'warning' : 'error'); ?>
        <div class="flash flash-<?= htmlspecialchars($flashClass) ?>"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>
      <?php if ($viewOrder): ?>
        <?php
        $viewSourceOrderNo = trim((string)($viewOrder['sourceOrderNumber'] ?? ''));
        if ($viewSourceOrderNo === '' && !empty($viewOrder['sourceOrderID'])) $viewSourceOrderNo = 'Order #' . (int)$viewOrder['sourceOrderID'];
        $viewCustomerName = trim((string)($viewOrder['displayName'] ?? $viewOrder['customerName'] ?? ''));
        $viewStatus = trim((string)($viewOrder['status'] ?? 'pending'));
        $viewStatusLabel = $statusOptions[$viewStatus] ?? ucwords(str_replace('_', ' ', $viewStatus));
        $viewStatusClass = $statusBadge[$viewStatus] ?? 'badge-muted';
        $viewPhotoPath = trim((string)($viewOrder['photoReferencePath'] ?? ''));
        $viewPhotoUrl = $viewPhotoPath !== '' ? '../../' . ltrim(str_replace('\\', '/', $viewPhotoPath), '/') : '';
        ?>
        <div class="card mb-6">
          <div class="custom-order-detail-header">
            <div class="card-title custom-order-card-title">Custom Order Details</div>
            <div class="custom-order-detail-actions">
              <a href="?edit=<?= (int)$viewOrder['customOrderID'] ?>" class="btn-edit"><i class="fas fa-pen"></i> Edit</a>
              <a href="custom_orders.php" class="btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
          </div>
          <div class="order-detail-grid custom-order-detail-grid">
            <div class="order-detail-block">
              <h4>Customer</h4>
              <p class="text-sm mb-1"><strong>Name:</strong> <?= htmlspecialchars($viewCustomerName !== '' ? $viewCustomerName : '—') ?></p>
              <p class="text-sm mb-1"><strong>Email:</strong> <?= htmlspecialchars(trim((string)($viewOrder['email'] ?? '')) !== '' ? (string)$viewOrder['email'] : '—') ?></p>
              <p class="text-sm mb-1"><strong>Access Code:</strong> <code class="custom-order-access-code"><?= htmlspecialchars((string)($viewOrder['accessCode'] ?? '—')) ?></code></p>
              <p class="text-sm mb-1"><strong>Status:</strong> <span class="badge <?= htmlspecialchars($viewStatusClass) ?>"><?= htmlspecialchars($viewStatusLabel) ?></span></p>
            </div>
            <div class="order-detail-block">
              <h4>Checkout Setup</h4>
              <p class="text-sm mb-1"><strong>Idea Title:</strong> <?= htmlspecialchars(trim((string)($viewOrder['ideaTitle'] ?? '')) !== '' ? (string)$viewOrder['ideaTitle'] : '-') ?></p>
              <p class="text-sm mb-1"><strong>Product Type:</strong> <?= htmlspecialchars(trim((string)($viewOrder['productType'] ?? '')) !== '' ? (string)$viewOrder['productType'] : '-') ?></p>
              <p class="text-sm mb-1"><strong>Preferred Size:</strong> <?= htmlspecialchars(trim((string)($viewOrder['preferredSize'] ?? '')) !== '' ? (string)$viewOrder['preferredSize'] : '-') ?></p>
              <p class="text-sm mb-1"><strong>Preferred Colours:</strong> <?= htmlspecialchars(trim((string)($viewOrder['preferredColours'] ?? '')) !== '' ? (string)$viewOrder['preferredColours'] : '-') ?></p>
              <p class="text-sm mb-1"><strong>Price Range:</strong> <?= htmlspecialchars(customOrderPriceLabel($viewOrder)) ?></p>
              <p class="text-sm mb-1"><strong>Agreed Price:</strong> €<?= number_format((float)($viewOrder['agreedPrice'] ?? 0), 2) ?></p>
              <p class="text-sm mb-1"><strong>Deadline:</strong> <?= !empty($viewOrder['deadline']) ? htmlspecialchars(date('n/j/Y', strtotime((string)$viewOrder['deadline']))) : '—' ?></p>
              <p class="text-sm mb-1"><strong>Linked Product:</strong> <?= htmlspecialchars(trim((string)($viewOrder['linkedProductName'] ?? '')) !== '' ? (string)$viewOrder['linkedProductName'] : '—') ?></p>
              <p class="text-sm mb-1"><strong>Source Order:</strong>
                <?php if ($viewSourceOrderNo !== ''): ?>
                  <?= htmlspecialchars($viewSourceOrderNo) ?>
                  <?php if (!empty($viewOrder['sourceOrderID'])): ?>
                    <a href="order_management.php?view=<?= (int)$viewOrder['sourceOrderID'] ?>" class="btn-edit custom-order-source-link"><i class="fas fa-arrow-up-right-from-square"></i> Open</a>
                  <?php endif; ?>
                <?php else: ?>—<?php endif; ?>
              </p>
            </div>
          </div>
          <div class="custom-order-description-section">
            <h4 class="custom-order-section-title">Request Description</h4>
            <?php $viewRequestPreview = adminStructuredRequestPreview($viewOrder); ?>
            <?php if ($viewRequestPreview !== ''): ?>
              <div class="text-sm text-muted custom-order-description-body"><?= htmlspecialchars($viewRequestPreview) ?></div>
            <?php else: ?>
            <div class="text-sm text-muted custom-order-description-body"><?= htmlspecialchars((string)($viewOrder['requestDescription'] ?? '—')) ?></div>
            <?php endif; ?>
          </div>
          <?php if ($viewPhotoUrl !== ''): ?>
            <div class="custom-order-photo-section">
              <h4 class="custom-order-section-title">Reference Photo</h4>
              <a href="<?= htmlspecialchars($viewPhotoUrl) ?>" target="_blank" rel="noopener noreferrer" class="custom-order-photo-link">
                <img src="<?= htmlspecialchars($viewPhotoUrl) ?>" alt="Custom order reference" class="custom-order-photo-preview">
              </a>
            </div>
          <?php endif; ?>
          <div class="custom-order-admin-grid">
            <div class="card custom-order-admin-panel">
              <div class="card-title custom-order-checkout-title">Reply / Request More Info</div>
              <p class="text-sm text-muted custom-order-checkout-copy">Send a message to the customer. They can reply from their Custom Orders page.</p>
              <form method="POST" class="custom-order-admin-form">
                <?= app_csrf_input() ?>
                <input type="hidden" name="action" value="send_message">
                <input type="hidden" name="customOrderID" value="<?= (int)$viewOrder['customOrderID'] ?>">
                <textarea name="messageBody" class="form-input" rows="4" placeholder="Ask for extra details, photos, colours, or timing..." required></textarea>
                <button type="submit" class="btn-primary"><i class="fas fa-paper-plane"></i> Send Reply</button>
              </form>
            </div>
          </div>
          <div class="custom-order-thread">
            <div class="custom-order-thread-header">
              <h4 class="custom-order-section-title">Customer Discussion</h4>
              <form method="POST" class="custom-orders-inline-form" onsubmit="return confirm('Decline this custom order request and notify the customer?')">
                <?= app_csrf_input() ?>
                <input type="hidden" name="action" value="decline_request">
                <input type="hidden" name="customOrderID" value="<?= (int)$viewOrder['customOrderID'] ?>">
                <input type="hidden" name="declineMessage" value="Thank you for your idea. Unfortunately, Athina cannot accept this custom order request at this time.">
                <button type="submit" class="btn-delete"><i class="fas fa-ban"></i> Decline Request</button>
              </form>
            </div>
            <?php if (empty($viewMessages)): ?>
              <p class="text-muted text-sm">No discussion messages yet.</p>
            <?php else: ?>
              <div class="custom-order-message-list">
                <?php foreach ($viewMessages as $message): ?>
                  <?php $senderRole = strtolower((string)($message['senderRole'] ?? 'system')); ?>
                  <div class="custom-order-message is-<?= htmlspecialchars($senderRole) ?>">
                    <div class="custom-order-message-meta">
                      <strong><?= htmlspecialchars(ucfirst($senderRole)) ?></strong>
                      <span><?= !empty($message['createdAt']) ? htmlspecialchars(date('n/j/Y H:i', strtotime((string)$message['createdAt']))) : '' ?></span>
                    </div>
                    <div class="text-sm custom-order-message-body"><?= nl2br(htmlspecialchars((string)$message['messageBody'])) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="card custom-order-checkout-card">
            <div class="custom-order-checkout-header">
              <div>
                <div class="card-title custom-order-checkout-title">Accept & Private Checkout</div>
                <p class="text-sm text-muted custom-order-checkout-copy">Accept the request, prepare the hidden private product, and email the customer their access code.</p>
              </div>
              <form method="POST">
                <?= app_csrf_input() ?>
                <input type="hidden" name="action" value="create_checkout_link">
                <input type="hidden" name="customOrderID" value="<?= (int)$viewOrder['customOrderID'] ?>">
                <button type="submit" class="btn-primary"><i class="fas fa-link"></i> <?= !empty($viewPrivateCheckout['private_link']) ? 'Refresh & Email Access' : 'Accept & Email Access' ?></button>
              </form>
            </div>
            <?php if (!empty($viewPrivateCheckout['private_link'])): ?>
              <div class="custom-order-checkout-box">
                <p class="text-sm mb-1"><strong>Private Product ID:</strong> <?= (int)$viewPrivateCheckout['product_id'] ?></p>
                <p class="text-sm mb-2"><strong>Customer login:</strong> The product opens only after sign-in with <strong><?= htmlspecialchars(trim((string)($viewOrder['email'] ?? ''))) ?></strong>.</p>
                <label class="form-label">Private Product URL</label>
                <div class="custom-order-checkout-input-row">
                  <input type="text" readonly class="form-input custom-order-checkout-input" value="<?= htmlspecialchars((string)$viewPrivateCheckout['customer_link']) ?>">
                  <button type="button" class="btn-edit" onclick='copyText(<?= json_encode((string)$viewPrivateCheckout["customer_link"]) ?>)'><i class="fas fa-copy"></i> Copy URL</button>
                </div>
                <p class="text-sm text-muted mb-0" style="word-break:break-all;margin-top:8px;">Direct product URL is kept for admin reference. Customers must unlock it with the access code first.</p>
              </div>
            <?php else: ?>
              <div class="custom-order-checkout-warning">
                <p class="text-sm mb-1"><strong>Before creating the link:</strong></p>
                <p class="text-sm text-muted mb-0">Set the correct customer email and agreed price first. The generated product stays hidden from the public shop.</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="card mb-6">
        <div class="card-title">Custom Orders</div>
        <?php if (empty($orders)): ?>
          <p class="text-muted text-sm">No custom orders yet.</p>
        <?php else: ?>
          <div class="custom-orders-table-wrap">
            <table class="data-table custom-orders-table">
              <thead>
                <tr><th class="col-customer">Customer</th><th class="col-email">Email</th><th class="col-description">Description</th><th class="col-price">Price</th><th class="col-status">Status</th><th class="col-checkout">Checkout</th><th class="col-actions">Actions</th></tr>
              </thead>
              <tbody>
              <?php foreach ($orders as $ord): ?>
                <?php $displayEmail = trim((string)($ord['email'] ?? '')); $statusBadgeClass = $statusBadge[$ord['status']] ?? 'badge-muted'; $rowCheckout = adminLoadPrivateLinkForOrder($conn, $ord); $tableDescription = adminFormatDescriptionForTable(adminStructuredRequestPreview($ord)); ?>
                <tr>
                  <td class="font-600 cell-top col-customer"><?= htmlspecialchars((string)($ord['displayName'] ?? '—')) ?></td>
                  <td class="text-muted cell-top col-email"><?= htmlspecialchars($displayEmail !== '' ? $displayEmail : '—') ?></td>
                  <td class="text-muted cell-top custom-orders-description col-description"><?= htmlspecialchars($tableDescription) ?></td>
                  <td class="font-600 cell-top col-price">€<?= number_format((float)($ord['agreedPrice'] ?? 0), 2) ?></td>
                  <td class="cell-top col-status">
                    <div class="custom-orders-status-cell">
                      <span class="badge <?= $statusBadgeClass ?> custom-orders-status-badge"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$ord['status']))) ?></span>
                    </div>
                  </td>
                  <td class="cell-top col-checkout"><?php if ($rowCheckout['customer_link'] !== ''): ?><button type="button" class="btn-edit" onclick='copyText(<?= json_encode((string)$rowCheckout["customer_link"]) ?>)'><i class="fas fa-copy"></i> Copy URL</button><?php else: ?><span class="text-muted text-sm">Not created yet</span><?php endif; ?></td>
                  <td class="cell-top col-actions">
                    <div class="custom-orders-actions">
                      <a href="?view=<?= (int)$ord['customOrderID'] ?>" class="btn-secondary btn-sm"><i class="fas fa-eye"></i> View</a>
                      <a href="?edit=<?= (int)$ord['customOrderID'] ?>" class="btn-edit"><i class="fas fa-pen"></i> Edit</a>
                      <form method="POST" class="custom-orders-inline-form" onsubmit="return confirmDelete('Delete this custom order?')">
                      <?= app_csrf_input() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="customOrderID" value="<?= (int)$ord['customOrderID'] ?>">
                      <button type="submit" class="btn-delete"><i class="fas fa-trash"></i> Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>

<div class="modal-overlay" id="modalAdd">
  <div class="modal-box">
    <h3>New Custom Order</h3>
    <p class="modal-sub">Create the order record after you agree the details with the customer.</p>
    <form method="POST" enctype="multipart/form-data" data-ignore-unsaved-warning>
      <?= app_csrf_input() ?>
      <input type="hidden" name="action" value="add">
      <div class="form-group">
        <label class="form-label">Customer Email</label>
        <input type="email" name="email" class="form-input" placeholder="customer@example.com" required>
        <span class="form-hint">The email must belong to an existing customer account. The customer name is taken from registration info.</span>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Idea Title</label>
          <input type="text" name="ideaTitle" class="form-input" placeholder="e.g. Wedding gift basket" required>
        </div>
        <div class="form-group">
          <label class="form-label">Product Type</label>
          <input type="text" name="productType" class="form-input" placeholder="Plushie, blanket, gift set...">
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Preferred Size</label>
          <input type="text" name="preferredSize" class="form-input" placeholder="Small, Medium, Large or exact cm">
        </div>
        <div class="form-group">
          <label class="form-label">Preferred Colours</label>
          <input type="text" name="preferredColours" class="form-input" placeholder="Pink, cream, lavender...">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Request Description</label>
        <textarea name="requestDescription" class="form-input" rows="4" placeholder="Describe the agreed custom order..." required></textarea>
      </div>
      <div class="form-grid-3">
        <div class="form-group">
          <label class="form-label">Agreed Price (€)</label>
          <input type="text" name="agreedPriceInput" class="form-input" placeholder="120 or 20 - 70" required>
          <span class="form-hint">For multiple sizes, add exact size prices below.</span>
        </div>
        <div class="form-group">
          <label class="form-label">Deadline</label>
          <input type="date" name="deadline" class="form-input">
        </div>
        <div class="form-group">
          <label class="form-label">Access Code</label>
          <input type="text" name="accessCode" class="form-input" placeholder="Auto-generated if empty">
          <span class="form-hint">Sent to the customer for private product verification.</span>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Size Price Options</label>
        <textarea name="sizePriceOptions" class="form-input" rows="3" placeholder="Small=20&#10;Medium=35&#10;Large=50"></textarea>
        <span class="form-hint">Required when the agreed price is a range. One size price per line; this creates the private product price range.</span>
      </div>
      <div class="form-group">
        <label class="form-label">Reference Photo</label>
        <input type="file" name="referencePhoto" class="form-input" accept="image/*">
        <span class="form-hint">Optional. Uploaded images are converted to WebP automatically and can also be used as the first image on the private product.</span>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modalAdd')">Cancel</button>
        <button type="submit" class="btn-save">Create Order</button>
      </div>
    </form>
  </div>
</div>

<?php if ($editOrder): ?>
<div class="modal-overlay show" id="modalEdit">
  <div class="modal-box">
    <h3>Edit Custom Order</h3>
    <p class="modal-sub">Update the order before creating or refreshing the private checkout link.</p>
    <form method="POST" enctype="multipart/form-data" data-ignore-unsaved-warning>
      <?= app_csrf_input() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="customOrderID" value="<?= (int)$editOrder['customOrderID'] ?>">
      <div class="form-group">
        <label class="form-label">Customer Email</label>
        <input type="email" name="email" class="form-input" value="<?= htmlspecialchars((string)($editOrder['email'] ?? '')) ?>" required>
        <span class="form-hint">The customer name is taken from the registered account.</span>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Idea Title</label>
          <input type="text" name="ideaTitle" class="form-input" value="<?= htmlspecialchars((string)($editOrder['ideaTitle'] ?? '')) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Product Type</label>
          <input type="text" name="productType" class="form-input" value="<?= htmlspecialchars((string)($editOrder['productType'] ?? '')) ?>">
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Preferred Size</label>
          <input type="text" name="preferredSize" class="form-input" value="<?= htmlspecialchars((string)($editOrder['preferredSize'] ?? '')) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Preferred Colours</label>
          <input type="text" name="preferredColours" class="form-input" value="<?= htmlspecialchars((string)($editOrder['preferredColours'] ?? '')) ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Request Description</label>
        <textarea name="requestDescription" class="form-input" rows="4" required><?= htmlspecialchars((string)($editOrder['requestDescription'] ?? '')) ?></textarea>
      </div>
      <div class="form-grid-3">
        <div class="form-group">
          <label class="form-label">Agreed Price (€)</label>
          <input type="text" name="agreedPriceInput" class="form-input" value="<?= htmlspecialchars(adminPriceInputFromOrder($editOrder)) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Deadline</label>
          <input type="date" name="deadline" class="form-input" value="<?= !empty($editOrder['deadline']) ? htmlspecialchars(date('Y-m-d', strtotime((string)$editOrder['deadline']))) : '' ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Access Code</label>
          <input type="text" name="accessCode" class="form-input" value="<?= htmlspecialchars((string)($editOrder['accessCode'] ?? '')) ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Size Price Options</label>
        <textarea name="sizePriceOptions" class="form-input" rows="3" placeholder="Small=20&#10;Medium=35&#10;Large=50"><?= htmlspecialchars((string)($editOrder['sizePriceOptions'] ?? '')) ?></textarea>
        <span class="form-hint">Required when the agreed price is a range.</span>
      </div>
      <div class="form-group">
        <label class="form-label">Reference Photo</label>
        <input type="file" name="referencePhoto" class="form-input" accept="image/*">
        <?php if (!empty($editOrder['photoReferencePath'])): ?>
          <?php $editPhotoUrl = '../../' . ltrim(str_replace('\\', '/', (string)$editOrder['photoReferencePath']), '/'); ?>
          <div class="text-sm text-muted custom-order-current-photo-label">Current photo:</div>
          <a href="<?= htmlspecialchars($editPhotoUrl) ?>" target="_blank" rel="noopener noreferrer">
            <img src="<?= htmlspecialchars($editPhotoUrl) ?>" alt="Current custom order reference" class="custom-order-current-photo">
          </a>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <a href="custom_orders.php" class="btn-cancel">Cancel</a>
        <button type="submit" class="btn-save">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script src="assets/admin.js?v=<?= (int)filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var warningMessage = 'You have unsaved changes. Are you sure you want to leave this form?';
  function isEditableField(field) {
    if (!field || field.disabled || !field.name) return false;
    if (field.type === 'hidden' || field.type === 'submit' || field.type === 'button' || field.type === 'reset') return false;
    return true;
  }
  function setupModal(modal, options) {
    if (!modal) return null;
    var form = modal.querySelector('.modal-box > form');
    if (!form) return null;
    var state = { dirty: false, isSubmitting: false };
    form.querySelectorAll('input, select, textarea').forEach(function (field) {
      if (!isEditableField(field)) return;
      field.addEventListener('input', function () { state.dirty = true; });
      field.addEventListener('change', function () { state.dirty = true; });
    });
    form.addEventListener('submit', function () { state.isSubmitting = true; state.dirty = false; });
    function dismissModal() {
      if (state.dirty && !state.isSubmitting && !window.confirm(warningMessage)) return;
      state.dirty = false;
      if (options.mode === 'close') { modal.classList.remove('show'); document.body.style.overflow = ''; return; }
      window.location.href = options.returnUrl;
    }
    modal.addEventListener('click', function (e) { if (e.target === modal) dismissModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('show')) dismissModal(); });
    var cancelBtn = modal.querySelector('.btn-cancel');
    if (cancelBtn) cancelBtn.addEventListener('click', function (e) { e.preventDefault(); dismissModal(); });
    return state;
  }
  var states = [];
  var addState = setupModal(document.getElementById('modalAdd'), { mode: 'close' });
  var editState = setupModal(document.getElementById('modalEdit'), { mode: 'navigate', returnUrl: 'custom_orders.php' });
  if (addState) states.push(addState);
  if (editState) states.push(editState);
  window.addEventListener('beforeunload', function (e) {
    if (!states.some(function (state) { return state.dirty && !state.isSubmitting; })) return;
    e.preventDefault();
    e.returnValue = warningMessage;
  });
});
function copyText(value) {
  if (!value) return;
  if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(value); return; }
  window.prompt('Copy this value:', value);
}
</script>
</body>
</html>
