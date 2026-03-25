<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../../include/security.php';
require_once __DIR__ . '/../../include/made_to_order_access.php';

$current_page = 'product_management';
$flash = '';

// Backfill the Selling Fast flag on older databases before the page uses it.
$sellingFastColumn = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'isSellingFast'");
if ($sellingFastColumn && mysqli_num_rows($sellingFastColumn) === 0) {
    mysqli_query($conn, "ALTER TABLE products ADD COLUMN isSellingFast TINYINT(1) NOT NULL DEFAULT 0");
}
ensureMadeToOrderProductSchema($conn);

function productMgmtBuildProjectBasePath(): string {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = rtrim(str_replace('\\', '/', dirname(dirname(dirname($script)))), '/');
    if ($base === '/' || $base === '.' || $base === '') {
        return '';
    }
    return $base;
}

function productMgmtBuildPrivateLink(int $productId, string $token): string {
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $path = productMgmtBuildProjectBasePath() . '/shop.php?' . http_build_query([
        'mto_pid' => $productId,
        'mto_token' => $token,
    ]);
    if ($host !== '') {
        return $scheme . '://' . $host . $path;
    }
    return $path;
}

function productMgmtSendPrivateLinkEmail(string $customerEmail, string $productName, string $privateLink): bool {
    require_once __DIR__ . '/../../PHPMailer-master/src/Exception.php';
    require_once __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php';
    require_once __DIR__ . '/../../PHPMailer-master/src/SMTP.php';

    $subject = 'Your private made-to-order product link';
    $body =
        "Hello,\n\n" .
        "Your private made-to-order product is now ready to purchase:\n" .
        $productName . "\n\n" .
        "Private link:\n" . $privateLink . "\n\n" .
        "For security, this link only works when you are signed in with {$customerEmail}.\n\n" .
        "Thank you,\nAthina E-Shop";

    $transports = [
        ['port' => 587, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS],
        ['port' => 465, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS],
    ];

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
            $mail->addAddress($customerEmail, $customerEmail);
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('Made-to-order private-link email failed: ' . $e->getMessage());
        }
    }

    return false;
}

function productMgmtReadUploadedImageBlob(array $files, int $index): ?string
{
    $tmpName = (string)($files['tmp_name'][$index] ?? '');
    $error = (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK || $tmpName === '' || !is_file($tmpName)) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)($finfo->file($tmpName) ?: '');
    if (!app_allowed_image_mime($mimeType)) {
        return null;
    }

    $photoData = file_get_contents($tmpName);
    return is_string($photoData) ? $photoData : null;
}

/* ── Handle POST actions ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');
    $action = $_POST['action'] ?? '';
    $photoDeleteId = (int)($_POST['photo_delete'] ?? 0);

    if ($photoDeleteId > 0) {
        $productID = (int)($_POST['productID'] ?? 0);
        if ($productID > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM photos WHERE imageID = ? AND productID = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ii', $photoDeleteId, $productID);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
        $q2 = trim((string)($_POST['q'] ?? ''));
        $sf2 = trim((string)($_POST['status_filter'] ?? ''));
        $qs = http_build_query(array_filter(['edit' => $productID, 'q' => $q2, 'status_filter' => $sf2]));
        header("Location: product_management.php?" . $qs);
        exit;
    }

    if ($action === 'add' || $action === 'edit') {
        $nameEN   = trim($_POST['nameEN']   ?? '');
        $nameGR   = trim($_POST['nameGR']   ?? '');
        $descEN   = trim($_POST['descriptionEN'] ?? '');
        $descGR   = trim($_POST['descriptionGR'] ?? '');
        $price    = (float)($_POST['basePrice']  ?? 0);
        $cost     = (float)($_POST['costPrice']  ?? 0);
        $inv      = (int)($_POST['inventory']    ?? 0);
        $status   = $_POST['cartStatus']  ?? 'active';
        $category = trim($_POST['category'] ?? '');
        $sku      = trim($_POST['sku']      ?? '');
        $isSellingFast = isset($_POST['isSellingFast']) ? 1 : 0;
        $privateCustomerEmail = normalizeCustomerEmail((string)($_POST['privateCustomerEmail'] ?? ''));

        if ($status === 'made_to_order' && ($privateCustomerEmail === '' || !filter_var($privateCustomerEmail, FILTER_VALIDATE_EMAIL))) {
            $flash = 'error:Made to Order products require a valid customer email.';
            header('Location: product_management.php?flash=' . urlencode($flash));
            exit;
        }

        if ($status !== 'made_to_order') {
            $privateCustomerEmail = '';
        }

        if ($action === 'add') {
            if (empty($sku)) {
                $sku = 'SKU-' . strtoupper(substr(md5(microtime()), 0, 6));
            }

            $privateAccessToken = $status === 'made_to_order' ? generateMadeToOrderAccessToken() : '';
            $privateLinkSentAt = $status === 'made_to_order' ? date('Y-m-d H:i:s') : null;

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO products
                 (sku, nameEN, nameGR, descriptionEN, descriptionGR, basePrice, costPrice, inventory, cartStatus, category, isSellingFast, privateCustomerEmail, privateAccessToken, privateLinkSentAt)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            // sku (s), nameEN (s), nameGR (s), descEN (s), descGR (s), basePrice (d), costPrice (d),
            // inventory (i), cartStatus (s), category (s), isSellingFast (i), privateCustomerEmail (s),
            // privateAccessToken (s), privateLinkSentAt (s)
            mysqli_stmt_bind_param(
                $stmt,
                'sssssddississs',
                $sku,
                $nameEN,
                $nameGR,
                $descEN,
                $descGR,
                $price,
                $cost,
                $inv,
                $status,
                $category,
                $isSellingFast,
                $privateCustomerEmail,
                $privateAccessToken,
                $privateLinkSentAt
            );
            mysqli_stmt_execute($stmt);
            $newProductID = mysqli_insert_id($conn);

            if ($newProductID && isset($_FILES['photos']) && is_array($_FILES['photos']['tmp_name'])) {
                $added = 0;
                foreach ($_FILES['photos']['tmp_name'] as $idx => $tmpName) {
                    if ($added >= 4) break;
                    if ($_FILES['photos']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                    $photoData = productMgmtReadUploadedImageBlob($_FILES['photos'], (int)$idx);
                    if ($photoData === null) continue;
                    $stmtPhoto = mysqli_prepare($conn, "INSERT INTO photos (photo, productID) VALUES (?, ?)");
                    mysqli_stmt_bind_param($stmtPhoto, 'si', $photoData, $newProductID);
                    mysqli_stmt_execute($stmtPhoto);
                    $added++;
                }
            }

            if ($status === 'made_to_order' && $newProductID > 0 && $privateCustomerEmail !== '' && $privateAccessToken !== '') {
                $privateLink = productMgmtBuildPrivateLink($newProductID, $privateAccessToken);
                $emailSent = productMgmtSendPrivateLinkEmail($privateCustomerEmail, $nameEN, $privateLink);
                $flash = $emailSent
                    ? 'ok:Made-to-order product added and private link emailed to customer.'
                    : 'warn:Made-to-order product added, but private link email failed to send.';
            } else {
                $flash = 'ok:Product added successfully.';
            }
        } else {
            $id = (int)($_POST['productID'] ?? 0);

            $existingPrivateToken = '';
            $existingPrivateEmail = '';
            $existingRowStmt = mysqli_prepare(
                $conn,
                "SELECT privateAccessToken, privateCustomerEmail
                 FROM products
                 WHERE productID = ?
                 LIMIT 1"
            );
            if ($existingRowStmt) {
                mysqli_stmt_bind_param($existingRowStmt, 'i', $id);
                mysqli_stmt_execute($existingRowStmt);
                $existingRes = mysqli_stmt_get_result($existingRowStmt);
                if ($existingRes && ($existingRow = mysqli_fetch_assoc($existingRes))) {
                    $existingPrivateToken = trim((string)($existingRow['privateAccessToken'] ?? ''));
                    $existingPrivateEmail = normalizeCustomerEmail((string)($existingRow['privateCustomerEmail'] ?? ''));
                }
                mysqli_stmt_close($existingRowStmt);
            }

            $privateAccessToken = '';
            $privateLinkSentAt = null;
            $privateLinkNeedsEmail = false;
            if ($status === 'made_to_order') {
                $privateAccessToken = $existingPrivateToken !== '' ? $existingPrivateToken : generateMadeToOrderAccessToken();
                if ($privateCustomerEmail !== $existingPrivateEmail || $existingPrivateToken === '') {
                    $privateAccessToken = generateMadeToOrderAccessToken();
                    $privateLinkNeedsEmail = true;
                }
                $privateLinkSentAt = $privateLinkNeedsEmail ? date('Y-m-d H:i:s') : null;
            }

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE products
                 SET nameEN=?,
                     nameGR=?,
                     descriptionEN=?,
                     descriptionGR=?,
                     basePrice=?,
                     costPrice=?,
                     inventory=?,
                     cartStatus=?,
                     category=?,
                     isSellingFast=?,
                     privateCustomerEmail=?,
                     privateAccessToken=?,
                     privateLinkSentAt=?
                 WHERE productID=?"
            );
            // nameEN (s), nameGR (s), descEN (s), descGR (s),
            // basePrice (d), costPrice (d), inventory (i), cartStatus (s), category (s), isSellingFast (i),
            // privateCustomerEmail (s), privateAccessToken (s), privateLinkSentAt (s), productID (i)
            mysqli_stmt_bind_param(
                $stmt,
                'ssssddississsi',
                $nameEN,
                $nameGR,
                $descEN,
                $descGR,
                $price,
                $cost,
                $inv,
                $status,
                $category,
                $isSellingFast,
                $privateCustomerEmail,
                $privateAccessToken,
                $privateLinkSentAt,
                $id
            );
            mysqli_stmt_execute($stmt);

            if (isset($_FILES['photos']) && is_array($_FILES['photos']['tmp_name'])) {
                $existing = 0;
                $cntStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM photos WHERE productID = ?");
                if ($cntStmt) {
                    mysqli_stmt_bind_param($cntStmt, 'i', $id);
                    mysqli_stmt_execute($cntStmt);
                    $cntRes = mysqli_stmt_get_result($cntStmt);
                    $cntRow = $cntRes ? mysqli_fetch_assoc($cntRes) : null;
                    $existing = (int)($cntRow['cnt'] ?? 0);
                    mysqli_stmt_close($cntStmt);
                }
                $canAdd   = max(0, 4 - $existing);
                $added    = 0;
                foreach ($_FILES['photos']['tmp_name'] as $idx => $tmpName) {
                    if ($added >= $canAdd) break;
                    if ($_FILES['photos']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                    $photoData = productMgmtReadUploadedImageBlob($_FILES['photos'], (int)$idx);
                    if ($photoData === null) continue;
                    $stmtPhoto = mysqli_prepare($conn, "INSERT INTO photos (photo, productID) VALUES (?,?)");
                    mysqli_stmt_bind_param($stmtPhoto, 'si', $photoData, $id);
                    mysqli_stmt_execute($stmtPhoto);
                    $added++;
                }
            }

            if ($status === 'made_to_order' && $privateCustomerEmail !== '') {
                $shouldSendLink = $privateLinkNeedsEmail || isset($_POST['resendPrivateLink']);
                if ($shouldSendLink && $privateAccessToken !== '') {
                    $privateLink = productMgmtBuildPrivateLink($id, $privateAccessToken);
                    $emailSent = productMgmtSendPrivateLinkEmail($privateCustomerEmail, $nameEN, $privateLink);
                    $flash = $emailSent
                        ? 'ok:Product updated and private link emailed to customer.'
                        : 'warn:Product updated, but private link email failed to send.';
                } else {
                    $flash = 'ok:Product updated successfully.';
                }
            } else {
                $flash = 'ok:Product updated successfully.';
            }
        }
    }

    if ($action === 'delete_photo') {
        $imageID   = (int)($_POST['imageID']   ?? 0);
        $productID = (int)($_POST['productID'] ?? 0);
        if ($imageID > 0 && $productID > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM photos WHERE imageID = ? AND productID = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ii', $imageID, $productID);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
        $q2 = trim((string)($_POST['q'] ?? ''));
        $sf2 = trim((string)($_POST['status_filter'] ?? ''));
        $qs = http_build_query(array_filter(['edit' => $productID, 'q' => $q2, 'status_filter' => $sf2]));
        header("Location: product_management.php?" . $qs);
        exit;
    }

    if ($action === 'toggle_visibility') {
        $id = (int)($_POST['productID'] ?? 0);
        if ($id > 0) {
            $currentStatus = null;
            $productName = 'Product';

            $stCurrent = mysqli_prepare($conn, "SELECT nameEN, cartStatus FROM products WHERE productID = ? LIMIT 1");
            if ($stCurrent) {
                mysqli_stmt_bind_param($stCurrent, 'i', $id);
                mysqli_stmt_execute($stCurrent);
                $resCurrent = mysqli_stmt_get_result($stCurrent);
                if ($resCurrent && ($rowCurrent = mysqli_fetch_assoc($resCurrent))) {
                    $productName = trim((string)($rowCurrent['nameEN'] ?? '')) ?: 'Product';
                    $currentStatus = (string)($rowCurrent['cartStatus'] ?? '');
                }
                mysqli_stmt_close($stCurrent);
            }

            if ($currentStatus !== null) {
                $nextStatus = ($currentStatus === 'discontinued') ? 'active' : 'discontinued';
                $stUpdate = mysqli_prepare($conn, "UPDATE products SET cartStatus = ? WHERE productID = ?");
                if ($stUpdate) {
                    mysqli_stmt_bind_param($stUpdate, 'si', $nextStatus, $id);
                    mysqli_stmt_execute($stUpdate);
                    mysqli_stmt_close($stUpdate);

                    if ($nextStatus === 'discontinued') {
                        $flash = 'warn:' . $productName . ' is now hidden from the shop.';
                    } else {
                        $flash = 'ok:' . $productName . ' is now visible in the shop.';
                    }
                } else {
                    $flash = 'error:Could not update product visibility.';
                }
            } else {
                $flash = 'error:Product not found.';
            }
        } else {
            $flash = 'error:Invalid product.';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['productID'] ?? 0);

        // Check if this product appears in any order (must preserve history)
        $hasOrders = false;
        $chkOrders = mysqli_prepare($conn, "SELECT 1 FROM order_items WHERE productID = ? LIMIT 1");
        if ($chkOrders) {
            mysqli_stmt_bind_param($chkOrders, 'i', $id);
            mysqli_stmt_execute($chkOrders);
            $chkRes = mysqli_stmt_get_result($chkOrders);
            $hasOrders = $chkRes && mysqli_num_rows($chkRes) > 0;
            mysqli_stmt_close($chkOrders);
        }

        if ($hasOrders) {
            // Soft-delete: mark as discontinued so it disappears from shop
            $stmt = mysqli_prepare($conn, "UPDATE products SET cartStatus='discontinued' WHERE productID=?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $flash = 'warn:Product has existing orders and cannot be fully deleted — it has been marked as Discontinued and hidden from the shop.';
        } else {
            // Hard-delete: remove dependent rows first, then the product
            $deleteWishlist = mysqli_prepare($conn, "DELETE FROM wishlist_items WHERE productID = ?");
            if ($deleteWishlist) {
                mysqli_stmt_bind_param($deleteWishlist, 'i', $id);
                mysqli_stmt_execute($deleteWishlist);
                mysqli_stmt_close($deleteWishlist);
            }
            $deleteReviews = mysqli_prepare($conn, "DELETE FROM reviews WHERE productID = ?");
            if ($deleteReviews) {
                mysqli_stmt_bind_param($deleteReviews, 'i', $id);
                mysqli_stmt_execute($deleteReviews);
                mysqli_stmt_close($deleteReviews);
            }
            $deletePhotos = mysqli_prepare($conn, "DELETE FROM photos WHERE productID = ?");
            if ($deletePhotos) {
                mysqli_stmt_bind_param($deletePhotos, 'i', $id);
                mysqli_stmt_execute($deletePhotos);
                mysqli_stmt_close($deletePhotos);
            }
            // variation_stock references product_variations, so delete that first
            $vStmt = mysqli_prepare($conn, "SELECT variationID FROM product_variations WHERE productID = ?");
            $vRes = false;
            if ($vStmt) {
                mysqli_stmt_bind_param($vStmt, 'i', $id);
                mysqli_stmt_execute($vStmt);
                $vRes = mysqli_stmt_get_result($vStmt);
            }
            if ($vRes) {
                while ($vRow = mysqli_fetch_assoc($vRes)) {
                    $vid = (int)$vRow['variationID'];
                    $deleteStock = mysqli_prepare($conn, "DELETE FROM variation_stock WHERE variationID = ?");
                    if ($deleteStock) {
                        mysqli_stmt_bind_param($deleteStock, 'i', $vid);
                        mysqli_stmt_execute($deleteStock);
                        mysqli_stmt_close($deleteStock);
                    }
                }
            }
            if ($vStmt) {
                mysqli_stmt_close($vStmt);
            }
            $deleteVariations = mysqli_prepare($conn, "DELETE FROM product_variations WHERE productID = ?");
            if ($deleteVariations) {
                mysqli_stmt_bind_param($deleteVariations, 'i', $id);
                mysqli_stmt_execute($deleteVariations);
                mysqli_stmt_close($deleteVariations);
            }
            $deleteProduct = mysqli_prepare($conn, "DELETE FROM products WHERE productID = ?");
            if ($deleteProduct) {
                mysqli_stmt_bind_param($deleteProduct, 'i', $id);
                mysqli_stmt_execute($deleteProduct);
                mysqli_stmt_close($deleteProduct);
            }
            $flash = 'ok:Product deleted successfully.';
        }
    }

    $redirect = ['flash' => $flash];
    $returnQ = trim((string)($_POST['q'] ?? ''));
    $returnStatusFilter = trim((string)($_POST['status_filter'] ?? ''));
    if ($returnQ !== '') {
        $redirect['q'] = $returnQ;
    }
    if ($returnStatusFilter !== '') {
        $redirect['status_filter'] = $returnStatusFilter;
    }
    header('Location: product_management.php?' . http_build_query($redirect));
    exit;
}

if (isset($_GET['flash'])) {
    $flash = $_GET['flash'];
}

/* ── Load products ── */
$searchTerm = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status_filter'] ?? ''));
$allowedStatusFilters = ['active', 'low_stock', 'out_of_stock', 'made_to_order', 'discontinued'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = '';
}
$products = [];
$productsSql = "SELECT * FROM products";
if ($searchTerm !== '' && $statusFilter !== '') {
    $productsSql .= " WHERE (
        nameEN LIKE ? OR
        nameGR LIKE ? OR
        sku LIKE ? OR
        category LIKE ? OR
        cartStatus LIKE ? OR
        CAST(productID AS CHAR) LIKE ? OR
        descriptionEN LIKE ? OR
        descriptionGR LIKE ?
    ) AND cartStatus = ?";
} elseif ($searchTerm !== '') {
    $productsSql .= " WHERE
        nameEN LIKE ? OR
        nameGR LIKE ? OR
        sku LIKE ? OR
        category LIKE ? OR
        cartStatus LIKE ? OR
        CAST(productID AS CHAR) LIKE ? OR
        descriptionEN LIKE ? OR
        descriptionGR LIKE ?";
} elseif ($statusFilter !== '') {
    $productsSql .= " WHERE cartStatus = ?";
}
$productsSql .= " ORDER BY nameEN ASC";

$productsStmt = mysqli_prepare($conn, $productsSql);
if ($productsStmt) {
    if ($searchTerm !== '' && $statusFilter !== '') {
        $like = '%' . $searchTerm . '%';
        mysqli_stmt_bind_param(
            $productsStmt,
            'sssssssss',
            $like,
            $like,
            $like,
            $like,
            $like,
            $like,
            $like,
            $like,
            $statusFilter
        );
    } elseif ($searchTerm !== '') {
        $like = '%' . $searchTerm . '%';
        mysqli_stmt_bind_param(
            $productsStmt,
            'ssssssss',
            $like,
            $like,
            $like,
            $like,
            $like,
            $like,
            $like,
            $like
        );
    } elseif ($statusFilter !== '') {
        mysqli_stmt_bind_param($productsStmt, 's', $statusFilter);
    }
    mysqli_stmt_execute($productsStmt);
    $productsRes = mysqli_stmt_get_result($productsStmt);
    if ($productsRes) {
        while ($row = mysqli_fetch_assoc($productsRes)) {
            $products[] = $row;
        }
    }
    mysqli_stmt_close($productsStmt);
}

/* ── Load colors per product (for colour photos card) ── */
$pcpColorsByProduct = [];
$r = mysqli_query($conn,
    "SELECT DISTINCT pv.productID, pv.colorID, c.colorName
     FROM product_variations pv
     JOIN colors c ON c.colorID = pv.colorID
     WHERE pv.colorID IS NOT NULL
     ORDER BY c.colorName ASC");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $pcpColorsByProduct[(int)$row['productID']][] = [
            'id'   => (int)$row['colorID'],
            'name' => $row['colorName'],
        ];
    }
}

/* ── Load one product for edit modal ── */
$editProduct = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $stmt = mysqli_prepare($conn, "SELECT * FROM products WHERE productID = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $eid);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        $editProduct = $r ? mysqli_fetch_assoc($r) : null;
        mysqli_stmt_close($stmt);
    }
}

$availStatus = [
    'active'        => ['label' => 'in stock',      'badge' => 'badge-green'],
    'low_stock'     => ['label' => 'low stock',     'badge' => 'badge-warning'],
    'out_of_stock'  => ['label' => 'out of stock',  'badge' => 'badge-red'],
    'made_to_order' => ['label' => 'made to order', 'badge' => 'badge-muted'],
    'discontinued'  => ['label' => 'hidden',        'badge' => 'badge-muted'],
];

/* ── Images keyed by productID (all photos, up to 4) ── */
$images = [];
$r = mysqli_query($conn, "SELECT productID, imageID FROM photos ORDER BY imageID ASC");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $images[$row['productID']][] = (int)$row['imageID'];
    }
}

$categories = ['Animals','Blankets','Bags','Decor','Dolls'];

$statuses = [
    'active'        => 'In Stock',
    'low_stock'     => 'Low Stock',
    'out_of_stock'  => 'Out of Stock',
    'made_to_order' => 'Made to Order',
    'discontinued'  => 'Discontinued',
];
$statusFilterOptions = [
    ''              => 'All Statuses',
    'active'        => 'In Stock',
    'low_stock'     => 'Low Stock',
    'out_of_stock'  => 'Out of Stock',
    'made_to_order' => 'Made to Order',
    'discontinued'  => 'Discontinued',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Product Management – Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Product Management</h1>
        <p>Add, edit, and manage your product catalog with images and pricing.</p>
      </div>
      <button class="btn-primary" onclick="openModal('modalAdd')">
        <i class="fas fa-plus"></i> Add Product
      </button>
    </div>

    <div class="content-body">

      <?php if ($flash): ?>
        <?php [$type,$msg] = explode(':', $flash, 2); ?>
        <?php $flashClass = $type === 'ok' ? 'success' : ($type === 'warn' ? 'warning' : 'error'); ?>
        <div class="flash flash-<?= $flashClass ?>"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <form method="GET" class="search-form mb-4">
        <div class="search-input-wrap">
          <i class="fas fa-search"></i>
          <input
            type="text"
            name="q"
            class="form-input search-input"
            placeholder="Search products by name, SKU, category, status..."
            value="<?= htmlspecialchars($searchTerm) ?>">
        </div>
        <select name="status_filter" class="form-input search-status-select">
          <?php foreach ($statusFilterOptions as $value => $label): ?>
            <option value="<?= htmlspecialchars($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-secondary">
          <i class="fas fa-magnifying-glass"></i> Search
        </button>
        <?php if ($searchTerm !== '' || $statusFilter !== ''): ?>
          <a href="product_management.php" class="btn-secondary">
            <i class="fas fa-xmark"></i> Clear
          </a>
        <?php endif; ?>
      </form>

      <?php if ($searchTerm !== '' || $statusFilter !== ''): ?>
        <p class="text-sm text-muted mb-4">
          Found <?= (int)count($products) ?> product(s)
          <?php if ($searchTerm !== ''): ?>
            for "<strong><?= htmlspecialchars($searchTerm) ?></strong>"
          <?php endif; ?>
          <?php if ($statusFilter !== ''): ?>
            with status "<strong><?= htmlspecialchars($statusFilterOptions[$statusFilter] ?? $statusFilter) ?></strong>"
          <?php endif; ?>.
        </p>
      <?php endif; ?>

      <div class="card">
        <div class="card-title">All Products</div>
        <table class="data-table">
          <thead>
            <tr>
              <th style="width:60px">Image</th>
              <th>Product Name</th>
              <th>Category</th>
              <th>Price</th>
              <th>Availability</th>
              <th>Promotions</th>
              <th>Stock</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): ?>
              <?php $st = $availStatus[$p['cartStatus']] ?? ['label'=>$p['cartStatus'],'badge'=>'badge-muted']; ?>
              <?php
                $isHidden = ((string)$p['cartStatus'] === 'discontinued');
                $togglePrompt = $isHidden
                    ? ('Show ' . $p['nameEN'] . ' in shop?')
                    : ('Hide ' . $p['nameEN'] . ' from shop?');
              ?>
              <tr>
                <td>
                  <div class="prod-thumb">
                    <?php if (!empty($images[$p['productID']])): ?>
                      <img src="ajax/product_image.php?id=<?= $images[$p['productID']][0] ?>" alt="">
                    <?php else: ?>
                      <i class="fas fa-image"></i>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div class="font-600"><?= htmlspecialchars($p['nameEN']) ?></div>
                  <?php if (!empty($p['isSellingFast'])): ?>
                    <div style="margin-top:6px;">
                      <span class="badge badge-orange">Selling Fast</span>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="text-muted"><?= htmlspecialchars($p['category'] ?? '—') ?></td>
                <td>
                  <span class="price-new">€<?= number_format($p['basePrice'],2) ?></span>
                  <?php if ((float)$p['costPrice'] > 0): ?>
                    <div class="text-sm text-muted">Cost: €<?= number_format((float)$p['costPrice'], 2) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge <?= $st['badge'] ?>"><?= $st['label'] ?></span>
                  <?php if ((string)$p['cartStatus'] === 'made_to_order' && trim((string)($p['privateCustomerEmail'] ?? '')) !== ''): ?>
                    <div class="text-sm text-muted" style="margin-top:6px;">
                      <?= htmlspecialchars((string)$p['privateCustomerEmail']) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($p['isSellingFast'])): ?>
                    <span class="badge badge-orange">Homepage</span>
                  <?php else: ?>
                    <span class="text-muted">&mdash;</span>
                  <?php endif; ?>
                </td>
                <td><?= $p['cartStatus'] === 'made_to_order' ? 'N/A' : (int)$p['inventory'] ?></td>
                <td style="text-align:right">
                  <a href="?edit=<?= $p['productID'] ?><?= ($searchTerm !== '' || $statusFilter !== '') ? '&' . http_build_query(['q' => $searchTerm, 'status_filter' => $statusFilter]) : '' ?>" class="btn-edit">
                    <i class="fas fa-pen"></i> Edit
                  </a>
                  <form method="POST" style="display:inline"
                        onsubmit="return confirmDelete('<?= htmlspecialchars(addslashes($togglePrompt), ENT_QUOTES) ?>')">
                    <input type="hidden" name="action" value="toggle_visibility">
                    <input type="hidden" name="productID" value="<?= $p['productID'] ?>">
                    <input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm) ?>">
                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
                    <button type="submit" class="btn-secondary">
                      <i class="fas <?= $isHidden ? 'fa-eye' : 'fa-eye-slash' ?>"></i> <?= $isHidden ? 'Show' : 'Hide' ?>
                    </button>
                  </form>
                  <form method="POST" style="display:inline"
                        onsubmit="return confirmDelete('Delete <?= htmlspecialchars(addslashes($p['nameEN'])) ?>?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="productID" value="<?= $p['productID'] ?>">
                    <input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm) ?>">
                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
                    <button type="submit" class="btn-delete">
                      <i class="fas fa-trash"></i> Delete
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($products)): ?>
              <tr>
                <td colspan="8" class="text-muted" style="text-align:center;padding:32px 0;">
                  <?= ($searchTerm !== '' || $statusFilter !== '')
                      ? 'No matching products found for your search.'
                      : 'No products yet. Click "Add Product" to get started.' ?>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- ── Product Colour Photos ── -->
      <div class="card" style="margin-top:24px">
        <div class="card-title">Product Colour Photos</div>
        <p class="text-sm text-muted" style="margin-bottom:20px">Upload product photos per colour. These appear on the storefront when the customer selects a colour.</p>

        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px">
          <div class="form-group" style="flex:1;min-width:200px">
            <label class="form-label">Product</label>
            <select id="pcp-product" class="form-input" onchange="pcpLoadColors()">
              <option value="">— Select product —</option>
              <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p['productID'] ?>"><?= htmlspecialchars($p['nameEN']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1;min-width:200px">
            <label class="form-label">Colour</label>
            <select id="pcp-color" class="form-input" disabled onchange="pcpLoadPhotos()">
              <option value="">— Select colour —</option>
            </select>
          </div>
        </div>

        <div id="pcp-upload-area" style="display:none;margin-bottom:20px">
          <label class="form-label">Upload Photo(s)</label>
          <div style="display:flex;align-items:center;gap:12px">
            <input type="file" id="pcp-file" class="form-input" accept="image/*" multiple style="flex:1">
            <button class="btn btn-primary" onclick="pcpUpload()" style="white-space:nowrap">
              <i class="fas fa-upload"></i> Upload
            </button>
          </div>
          <div id="pcp-upload-progress" style="margin-top:8px;font-size:13px;color:#6b7280"></div>
        </div>

        <div id="pcp-photos-grid" style="display:flex;flex-wrap:wrap;gap:12px"></div>
        <div id="pcp-empty" style="display:none;font-size:13px;color:#9ca3af;padding:12px 0">No photos yet for this product &amp; colour combination.</div>
      </div>

    </div>
  </main>
</div>

<!-- ── Add Product Modal ── -->
<div class="modal-overlay" id="modalAdd">
  <div class="modal-box">
    <h3>Add Product</h3>
    <p class="modal-sub">Fill in the product details below.</p>
    <form method="POST" enctype="multipart/form-data" data-ignore-unsaved-warning>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm) ?>">
      <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
      <div class="form-group">
        <label class="form-label">Product Photos <span class="text-muted">(up to 4)</span></label>
        <input type="file" name="photos[]" class="form-input" accept="image/*" multiple>
        <span class="form-hint">Hold Ctrl/Cmd to select multiple photos</span>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Product Name (EN) *</label>
          <input name="nameEN" class="form-input" required placeholder="e.g. Crochet Bunny">
        </div>
        <div class="form-group">
          <label class="form-label">Product Name (GR)</label>
          <input name="nameGR" class="form-input" placeholder="π.χ. Κουνελάκι Κροσέ">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Description (EN)</label>
        <textarea name="descriptionEN" class="form-input"></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Description (GR)</label>
        <textarea name="descriptionGR" class="form-input"></textarea>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Selling Price (€) *</label>
          <input name="basePrice" type="number" step="0.01" min="0" class="form-input" required placeholder="0.00">
        </div>
        <div class="form-group">
          <label class="form-label">Material Cost (€) <span class="text-muted">(internal)</span></label>
          <input name="costPrice" type="number" step="0.01" min="0" class="form-input" placeholder="0.00">
          <span class="form-hint">Used for profitability tracking. Customers only see selling price.</span>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Category</label>
          <select name="category" class="form-input">
            <option value="">— Select —</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= htmlspecialchars((string)$cat) ?>"><?= htmlspecialchars((string)$cat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Availability</label>
          <select name="cartStatus" class="form-input">
            <?php foreach ($statuses as $val=>$lbl): ?>
              <option value="<?= htmlspecialchars((string)$val) ?>"><?= htmlspecialchars((string)$lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group mto-private-email-field" data-private-email-field style="display:none;">
        <label class="form-label">Private Customer Email (Made to Order)</label>
        <input name="privateCustomerEmail" type="email" class="form-input" placeholder="customer@example.com">
        <span class="form-hint">Only this email can use the private product link in shop.</span>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Stock Quantity</label>
          <input name="inventory" type="number" min="0" class="form-input" value="0">
        </div>
        <div class="form-group">
          <label class="form-label">SKU</label>
          <input name="sku" class="form-input" placeholder="Auto-generated if blank">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Homepage Promotion</label>
        <label style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:10px;background:#fafafa;">
          <div>
            <div style="font-weight:600;color:#111827;">Mark as Selling Fast</div>
            <div class="text-sm text-muted">Highlighted products appear in the homepage Selling Fast section with a visual badge.</div>
          </div>
          <span class="toggle-wrap">
            <input type="checkbox" name="isSellingFast" value="1">
            <span class="toggle-slider"></span>
          </span>
        </label>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modalAdd')">Cancel</button>
        <button type="submit" class="btn-save">Add Product</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Product Modal (pre-filled via URL ?edit=ID) ── -->
<?php if ($editProduct): ?>
<div class="modal-overlay show" id="modalEdit">
  <div class="modal-box">
    <h3>Edit Product</h3>
    <p class="modal-sub">Update the details for "<?= htmlspecialchars($editProduct['nameEN']) ?>".</p>
    <form method="POST" enctype="multipart/form-data" data-ignore-unsaved-warning>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="productID" value="<?= $editProduct['productID'] ?>">
      <input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm) ?>">
      <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
      <div class="form-group">
        <label class="form-label">Product Photos <span class="text-muted">(up to 4)</span></label>
        <?php $productPhotos = $images[$editProduct['productID']] ?? []; ?>
        <?php if (!empty($productPhotos)): ?>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
            <?php foreach ($productPhotos as $imgID): ?>
              <div style="position:relative;display:inline-block;">
                <img src="ajax/product_image.php?id=<?= $imgID ?>"
                     style="height:72px;width:72px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;" alt="">
                <button
                  type="submit"
                  name="photo_delete"
                  value="<?= $imgID ?>"
                  formnovalidate
                  onclick="return confirm('Delete this photo?')"
                  title="Delete photo"
                  style="position:absolute;top:-7px;right:-7px;margin:0;background:#e74c3c;color:#fff;border:none;border-radius:50%;width:22px;height:22px;cursor:pointer;font-size:13px;line-height:1;padding:0;display:flex;align-items:center;justify-content:center;">&times;</button>
              </div>
            <?php endforeach; ?>
          </div>
          <span class="form-hint"><?= count($productPhotos) ?>/4 photos — click &times; to remove</span>
        <?php endif; ?>
        <?php if (count($productPhotos) < 4): ?>
          <input type="file" name="photos[]" class="form-input" accept="image/*" multiple style="margin-top:8px;">
          <span class="form-hint">Add up to <?= 4 - count($productPhotos) ?> more photo(s) — hold Ctrl/Cmd to select multiple</span>
        <?php endif; ?>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Product Name (EN) *</label>
          <input name="nameEN" class="form-input" required value="<?= htmlspecialchars($editProduct['nameEN']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Product Name (GR)</label>
          <input name="nameGR" class="form-input" value="<?= htmlspecialchars($editProduct['nameGR']) ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Description (EN)</label>
        <textarea name="descriptionEN" class="form-input"><?= htmlspecialchars($editProduct['descriptionEN'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Description (GR)</label>
        <textarea name="descriptionGR" class="form-input"><?= htmlspecialchars($editProduct['descriptionGR'] ?? '') ?></textarea>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Selling Price (€) *</label>
          <input name="basePrice" type="number" step="0.01" class="form-input" required value="<?= $editProduct['basePrice'] ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Material Cost (€) <span class="text-muted">(internal)</span></label>
          <input name="costPrice" type="number" step="0.01" class="form-input" value="<?= $editProduct['costPrice'] ?>">
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Category</label>
          <select name="category" class="form-input">
            <option value="">— Select —</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= htmlspecialchars((string)$cat) ?>" <?= $editProduct['category']===$cat?'selected':'' ?>><?= htmlspecialchars((string)$cat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Availability</label>
          <select name="cartStatus" class="form-input">
            <?php foreach ($statuses as $val=>$lbl): ?>
              <option value="<?= htmlspecialchars((string)$val) ?>" <?= $editProduct['cartStatus']===$val?'selected':'' ?>><?= htmlspecialchars((string)$lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div
        class="form-group mto-private-email-field"
        data-private-email-field
        style="<?= (string)($editProduct['cartStatus'] ?? '') === 'made_to_order' ? '' : 'display:none;' ?>"
      >
        <label class="form-label">Private Customer Email (Made to Order)</label>
        <input
          name="privateCustomerEmail"
          type="email"
          class="form-input"
          placeholder="customer@example.com"
          value="<?= htmlspecialchars((string)($editProduct['privateCustomerEmail'] ?? '')) ?>"
        >
        <span class="form-hint">Only this email can use the private product link in shop.</span>
        <?php if ((string)($editProduct['cartStatus'] ?? '') === 'made_to_order' && trim((string)($editProduct['privateAccessToken'] ?? '')) !== ''): ?>
          <?php $privatePreview = productMgmtBuildPrivateLink((int)$editProduct['productID'], (string)$editProduct['privateAccessToken']); ?>
          <div class="text-sm" style="margin-top:8px;word-break:break-all;">
            Current link: <a href="<?= htmlspecialchars($privatePreview) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($privatePreview) ?></a>
          </div>
          <label class="text-sm text-muted" style="display:flex;align-items:center;gap:8px;margin-top:8px;">
            <input type="checkbox" name="resendPrivateLink" value="1">
            Resend private link email on save
          </label>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label class="form-label">Stock Quantity</label>
        <input name="inventory" type="number" min="0" class="form-input" value="<?= (int)$editProduct['inventory'] ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Homepage Promotion</label>
        <label style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:10px;background:#fafafa;">
          <div>
            <div style="font-weight:600;color:#111827;">Mark as Selling Fast</div>
            <div class="text-sm text-muted">Highlighted products appear in the homepage Selling Fast section with a visual badge.</div>
          </div>
          <span class="toggle-wrap">
            <input type="checkbox" name="isSellingFast" value="1" <?= !empty($editProduct['isSellingFast']) ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
          </span>
        </label>
      </div>
      <div class="modal-footer">
        <a href="product_management.php<?= ($searchTerm !== '' || $statusFilter !== '') ? '?' . http_build_query(['q' => $searchTerm, 'status_filter' => $statusFilter]) : '' ?>" class="btn-cancel">Cancel</a>
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
  var listUrl = <?= json_encode('product_management.php' . (($searchTerm !== '' || $statusFilter !== '') ? '?' . http_build_query(['q' => $searchTerm, 'status_filter' => $statusFilter]) : '')) ?>;

  function bindMadeToOrderField(form) {
    if (!form) return;
    var statusSelect = form.querySelector('select[name="cartStatus"]');
    var fieldWrap = form.querySelector('[data-private-email-field]');
    var emailInput = fieldWrap ? fieldWrap.querySelector('input[name="privateCustomerEmail"]') : null;
    if (!statusSelect || !fieldWrap || !emailInput) return;

    function refreshFieldState() {
      var show = statusSelect.value === 'made_to_order';
      fieldWrap.style.display = show ? '' : 'none';
      emailInput.required = show;
    }

    statusSelect.addEventListener('change', refreshFieldState);
    statusSelect.addEventListener('input', refreshFieldState);
    refreshFieldState();
  }

  function isEditableField(field) {
    if (!field || field.disabled || !field.name) return false;
    if (field.type === 'hidden' || field.type === 'submit' || field.type === 'button' || field.type === 'reset') return false;
    return true;
  }

  function setupModal(modal, options) {
    if (!modal) return null;
    var form = modal.querySelector('.modal-box > form');
    if (!form) return null;
    bindMadeToOrderField(form);

    var state = {
      dirty: false,
      isSubmitting: false
    };

    form.querySelectorAll('input, select, textarea').forEach(function (field) {
      if (!isEditableField(field)) return;
      field.addEventListener('input', function () { state.dirty = true; });
      field.addEventListener('change', function () { state.dirty = true; });
    });

    function dismissModal() {
      if (state.dirty && !state.isSubmitting && !window.confirm(warningMessage)) return;
      state.dirty = false;
      if (options.mode === 'close') {
        modal.classList.remove('show');
        document.body.style.overflow = '';
        return;
      }
      window.location.href = options.returnUrl;
    }

    modal.addEventListener('click', function (e) {
      if (e.target !== modal) return;
      e.preventDefault();
      e.stopPropagation();
      dismissModal();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (!modal.classList.contains('show')) return;
      e.preventDefault();
      e.stopPropagation();
      dismissModal();
    });

    var cancelBtn = modal.querySelector('.btn-cancel');
    if (cancelBtn) {
      cancelBtn.addEventListener('click', function (e) {
        e.preventDefault();
        dismissModal();
      });
    }

    form.addEventListener('submit', function () {
      state.isSubmitting = true;
      state.dirty = false;
    });

    return state;
  }

  var modalStates = [];
  var addState = setupModal(document.getElementById('modalAdd'), { mode: 'close' });
  var editState = setupModal(document.getElementById('modalEdit'), { mode: 'navigate', returnUrl: listUrl });
  if (addState) modalStates.push(addState);
  if (editState) modalStates.push(editState);

  window.addEventListener('beforeunload', function (e) {
    var hasDirtyModal = modalStates.some(function (state) {
      return state.dirty && !state.isSubmitting;
    });
    if (!hasDirtyModal) return;
    e.preventDefault();
    e.returnValue = warningMessage;
  });
});
</script>

<script>
/* ── Product Colour Photos ── */
var pcpColorMap = <?= json_encode($pcpColorsByProduct, JSON_UNESCAPED_UNICODE) ?>;
var pcpAjax     = 'ajax/product_color_photo.php';

function pcpLoadColors() {
  var pid      = parseInt(document.getElementById('pcp-product').value) || 0;
  var colorSel = document.getElementById('pcp-color');
  colorSel.innerHTML = '<option value="">— Select colour —</option>';
  colorSel.disabled  = true;
  document.getElementById('pcp-upload-area').style.display = 'none';
  document.getElementById('pcp-photos-grid').innerHTML     = '';
  document.getElementById('pcp-empty').style.display       = 'none';
  if (!pid || !pcpColorMap[pid]) return;
  pcpColorMap[pid].forEach(function(c) {
    var opt = document.createElement('option');
    opt.value       = c.id;
    opt.textContent = c.id + ' — ' + c.name;
    colorSel.appendChild(opt);
  });
  colorSel.disabled = false;
}

function pcpLoadPhotos() {
  var pid = parseInt(document.getElementById('pcp-product').value) || 0;
  var cid = parseInt(document.getElementById('pcp-color').value)   || 0;
  var grid    = document.getElementById('pcp-photos-grid');
  var empty   = document.getElementById('pcp-empty');
  var upload  = document.getElementById('pcp-upload-area');
  grid.innerHTML = '';
  empty.style.display  = 'none';
  upload.style.display = 'none';
  if (!pid || !cid) return;
  upload.style.display = 'block';
  fetch(pcpAjax + '?action=list&productID=' + pid + '&colorID=' + cid)
    .then(function(r){ return r.json(); })
    .then(function(data) {
      if (!data.ok || !data.photos.length) {
        empty.style.display = 'block';
        return;
      }
      data.photos.forEach(function(ph) { pcpAddThumb(ph); });
    });
}

function pcpAddThumb(ph) {
  var grid = document.getElementById('pcp-photos-grid');
  var base = '<?= htmlspecialchars(productMgmtBuildProjectBasePath(), ENT_QUOTES) ?>/';
  var wrap = document.createElement('div');
  wrap.style.cssText = 'position:relative;width:100px;height:100px';
  wrap.innerHTML =
    '<img src="' + base + ph.photoPath + '" style="width:100px;height:100px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb">' +
    '<button onclick="pcpDelete(' + ph.id + ',this)" style="position:absolute;top:4px;right:4px;background:#dc2626;color:#fff;border:none;border-radius:50%;width:22px;height:22px;cursor:pointer;font-size:12px;line-height:1" title="Delete"><i class="fas fa-times"></i></button>';
  grid.appendChild(wrap);
  document.getElementById('pcp-empty').style.display = 'none';
}

function pcpUpload() {
  var pid   = parseInt(document.getElementById('pcp-product').value) || 0;
  var cid   = parseInt(document.getElementById('pcp-color').value)   || 0;
  var files = document.getElementById('pcp-file').files;
  var prog  = document.getElementById('pcp-upload-progress');
  if (!pid || !cid || !files.length) return;
  prog.textContent = 'Uploading...';
  var remaining = files.length;
  Array.from(files).forEach(function(file) {
    var fd = new FormData();
    fd.append('action',    'upload');
    fd.append('productID', pid);
    fd.append('colorID',   cid);
    fd.append('photo',     file);
    fd.append('csrf_token', window.APP_CSRF_TOKEN || '');
    fetch(pcpAjax, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(data) {
        if (data.ok) pcpAddThumb(data);
        remaining--;
        if (remaining === 0) {
          prog.textContent = 'Done.';
          document.getElementById('pcp-file').value = '';
          setTimeout(function(){ prog.textContent = ''; }, 2000);
        }
      });
  });
}

function pcpDelete(id, btn) {
  if (!confirm('Delete this photo?')) return;
  var fd = new FormData();
  fd.append('action', 'delete');
  fd.append('id', id);
  fd.append('csrf_token', window.APP_CSRF_TOKEN || '');
  fetch(pcpAjax, { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data) {
      if (data.ok) {
        btn.closest('div').remove();
        var grid = document.getElementById('pcp-photos-grid');
        if (!grid.children.length) document.getElementById('pcp-empty').style.display = 'block';
      }
    });
}
</script>
</body>
</html>
