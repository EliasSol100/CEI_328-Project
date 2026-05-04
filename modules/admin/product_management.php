<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../../include/security.php';
require_once __DIR__ . '/../../include/image_storage.php';
require_once __DIR__ . '/../../include/product_option_helpers.php';
require_once __DIR__ . '/../../include/product_warnings.php';
require_once __DIR__ . '/../../include/made_to_order_access.php';

$current_page = 'product_management';
$flash = '';
const PRODUCT_MGMT_MAX_PRODUCT_PHOTOS = 10;
const PRODUCT_MGMT_MAX_UPLOAD_BYTES = 5242880;

$sellingFastColumn = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'isSellingFast'");
if ($sellingFastColumn && mysqli_num_rows($sellingFastColumn) === 0) {
    mysqli_query($conn, "ALTER TABLE products ADD COLUMN isSellingFast TINYINT(1) NOT NULL DEFAULT 0");
}
ensureMadeToOrderProductSchema($conn);
app_product_options_ensure_schema($conn);
app_product_sync_stock_statuses($conn);

$yarnTypes = app_yarn_types_all($conn);
$yarnTypeNamesById = [];
foreach ($yarnTypes as $yt) {
    $yarnTypeNamesById[(int)$yt['typeID']] = (string)$yt['typeName'];
}
$defaultYarnTypeID = !empty($yarnTypes) ? (int)$yarnTypes[0]['typeID'] : 0;

function productMgmtEnsurePhotoStorageSchema(mysqli $conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'photos'");
    if (!$tableCheck || mysqli_num_rows($tableCheck) === 0) {
        return;
    }

    $columnRes = mysqli_query(
        $conn,
        "SELECT DATA_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'photos'
           AND COLUMN_NAME = 'photo'
         LIMIT 1"
    );
    $column = $columnRes ? mysqli_fetch_assoc($columnRes) : null;
    $type = strtolower((string)($column['DATA_TYPE'] ?? ''));
    if ($type === 'blob' || $type === 'tinyblob') {
        mysqli_query($conn, "ALTER TABLE photos MODIFY COLUMN photo MEDIUMBLOB NOT NULL");
    }
}

productMgmtEnsurePhotoStorageSchema($conn);

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
    if ((int)($files['size'][$index] ?? 0) > PRODUCT_MGMT_MAX_UPLOAD_BYTES) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)($finfo->file($tmpName) ?: '');
    if (!app_allowed_image_mime($mimeType)) {
        return null;
    }

    $photoData = file_get_contents($tmpName);
    if (!is_string($photoData) || $photoData === '') {
        return null;
    }

    return app_image_optimize_photo_blob_for_storage($photoData, 1400, 1400, 78);
}

function productMgmtNormalizeProductWarning(string $value): ?string
{
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if ($value === '') {
        return null;
    }

    return mb_substr($value, 0, 2500);
}

function productMgmtSaveProductWarning(mysqli $conn, int $productID, ?string $warningEN, ?string $warningGR): void
{
    if ($productID <= 0) {
        return;
    }

    $stmt = mysqli_prepare($conn, "UPDATE products SET productWarningEN = ?, productWarningGR = ? WHERE productID = ?");
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'ssi', $warningEN, $warningGR, $productID);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function productMgmtPostedSizePriceAmounts(array $postedPrices): array
{
    $amounts = [];
    foreach ($postedPrices as $rawPrice) {
        $rawPrice = trim((string)$rawPrice);
        if ($rawPrice === '') {
            continue;
        }
        $amounts[] = round(max(0.0, (float)$rawPrice), 2);
    }
    return $amounts;
}

function productMgmtFormatPriceRange(float $minPrice, float $maxPrice): string
{
    if (abs($maxPrice - $minPrice) > 0.009) {
        return '€' . number_format($minPrice, 2) . ' - €' . number_format($maxPrice, 2);
    }
    return '€' . number_format($minPrice, 2);
}

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

    if ($action === 'update_warning_message') {
        $warningTarget = trim((string)($_POST['warningTarget'] ?? ''));
        $productID = $warningTarget === 'global'
            ? 0
            : (int)($warningTarget !== '' ? $warningTarget : ($_POST['productID'] ?? 0));
        $warningEN = productMgmtNormalizeProductWarning((string)($_POST['productWarningEN'] ?? ''));
        $warningGR = productMgmtNormalizeProductWarning((string)($_POST['productWarningGR'] ?? ''));

        if ($warningTarget === 'global') {
            $saved = app_product_global_warning_save($conn, $warningEN, $warningGR);
            $flash = $saved
                ? 'ok:Global product warning message updated.'
                : 'error:Global product warning message could not be saved.';
        } elseif ($productID > 0) {
            productMgmtSaveProductWarning($conn, $productID, $warningEN, $warningGR);
            $flash = 'ok:Product warning message updated.';
        } else {
            $flash = 'error:Choose all products or one product before saving a warning message.';
        }

        $q2 = trim((string)($_POST['q'] ?? ''));
        $sf2 = trim((string)($_POST['status_filter'] ?? ''));
        $qs = http_build_query(array_filter(['q' => $q2, 'status_filter' => $sf2, 'flash' => $flash]));
        header("Location: product_management.php" . ($qs !== '' ? '?' . $qs : ''));
        exit;
    }

    if ($action === 'add' || $action === 'edit') {
        $id       = $action === 'edit' ? (int)($_POST['productID'] ?? 0) : 0;
        $nameEN   = trim($_POST['nameEN']   ?? '');
        $nameGR   = trim($_POST['nameGR']   ?? '');
        $descEN   = trim($_POST['descriptionEN'] ?? '');
        $descGR   = trim($_POST['descriptionGR'] ?? '');
        $priceRaw = trim((string)($_POST['basePrice'] ?? ''));
        $price    = $priceRaw === '' ? null : round(max(0.0, (float)$priceRaw), 2);
        $cost     = (float)($_POST['costPrice']  ?? 0);
        $inv      = (int)($_POST['inventory']    ?? 0);
        $category = trim($_POST['category'] ?? '');
        $yarnTypeID = (int)($_POST['yarnTypeID'] ?? 0);
        $sku      = trim($_POST['sku']      ?? '');
        $isSellingFast = isset($_POST['isSellingFast']) ? 1 : 0;
        $privateCustomerEmail = normalizeCustomerEmail((string)($_POST['privateCustomerEmail'] ?? ''));
        $hasWarningPost = array_key_exists('productWarningEN', $_POST) || array_key_exists('productWarningGR', $_POST);
        $productWarningEN = productMgmtNormalizeProductWarning((string)($_POST['productWarningEN'] ?? ''));
        $productWarningGR = productMgmtNormalizeProductWarning((string)($_POST['productWarningGR'] ?? ''));
        $rawSizesPost = app_product_available_sizes_from_string((string)($_POST['availableSizes'] ?? ''));
        $availableSizesSave = !empty($rawSizesPost)
            ? implode(',', $rawSizesPost)
            : 'Small,Medium,Large';
        $sizePricesPost = is_array($_POST['sizePrices'] ?? null) ? $_POST['sizePrices'] : [];
        $sizePriceAmounts = productMgmtPostedSizePriceAmounts($sizePricesPost);
        $existingPrivateToken = '';
        $existingPrivateEmail = '';
        $existingStatus = '';
        $existingInventory = 0;
        $existingYarnTypeID = 0;

        if ($action === 'edit' && $id > 0) {
            $existingRowStmt = mysqli_prepare(
                $conn,
                "SELECT cartStatus, inventory, yarnTypeID, privateAccessToken, privateCustomerEmail
                 FROM products
                 WHERE productID = ?
                 LIMIT 1"
            );
            if ($existingRowStmt) {
                mysqli_stmt_bind_param($existingRowStmt, 'i', $id);
                mysqli_stmt_execute($existingRowStmt);
                $existingRes = mysqli_stmt_get_result($existingRowStmt);
                if ($existingRes && ($existingRow = mysqli_fetch_assoc($existingRes))) {
                    $existingStatus = (string)($existingRow['cartStatus'] ?? '');
                    $existingInventory = (int)($existingRow['inventory'] ?? 0);
                    $existingYarnTypeID = (int)($existingRow['yarnTypeID'] ?? 0);
                    $existingPrivateToken = trim((string)($existingRow['privateAccessToken'] ?? ''));
                    $existingPrivateEmail = normalizeCustomerEmail((string)($existingRow['privateCustomerEmail'] ?? ''));
                }
                mysqli_stmt_close($existingRowStmt);
            }
        }

        if ($action === 'edit' && !array_key_exists('inventory', $_POST)) {
            $inv = $existingInventory;
        }
        if ($yarnTypeID <= 0 && $existingYarnTypeID > 0) {
            $yarnTypeID = $existingYarnTypeID;
        }
        if ($yarnTypeID <= 0 && $defaultYarnTypeID > 0) {
            $yarnTypeID = $defaultYarnTypeID;
        }
        if ($yarnTypeID <= 0 || !isset($yarnTypeNamesById[$yarnTypeID])) {
            $flash = 'error:Please select a valid yarn type.';
            header('Location: product_management.php?flash=' . urlencode($flash));
            exit;
        }
        $materialType = $yarnTypeNamesById[$yarnTypeID];

        $status = in_array($existingStatus, ['made_to_order', 'discontinued'], true)
            ? $existingStatus
            : 'active';

        if ($price === null) {
            if (!empty($sizePriceAmounts)) {
                $price = min($sizePriceAmounts);
            } else {
                $flash = 'error:Add a fallback selling price or at least one size price.';
                header('Location: product_management.php?flash=' . urlencode($flash));
                exit;
            }
        }

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
                 (sku, nameEN, nameGR, descriptionEN, descriptionGR, basePrice, costPrice, inventory, cartStatus, category, materialType, yarnTypeID, isSellingFast, privateCustomerEmail, privateAccessToken, privateLinkSentAt, availableSizes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );

            mysqli_stmt_bind_param(
                $stmt,
                'sssssddisssiissss',
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
                $materialType,
                $yarnTypeID,
                $isSellingFast,
                $privateCustomerEmail,
                $privateAccessToken,
                $privateLinkSentAt,
                $availableSizesSave
            );
            mysqli_stmt_execute($stmt);
            $newProductID = mysqli_insert_id($conn);
            app_product_size_prices_save($conn, (int)$newProductID, $rawSizesPost, $sizePricesPost);
            if ($hasWarningPost) {
                productMgmtSaveProductWarning($conn, (int)$newProductID, $productWarningEN, $productWarningGR);
            }

            if ($newProductID && isset($_FILES['photos']) && is_array($_FILES['photos']['tmp_name'])) {
                $added = 0;
                foreach ($_FILES['photos']['tmp_name'] as $idx => $tmpName) {
                    if ($added >= PRODUCT_MGMT_MAX_PRODUCT_PHOTOS) break;
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
                     materialType=?,
                     yarnTypeID=?,
                     isSellingFast=?,
                     privateCustomerEmail=?,
                     privateAccessToken=?,
                     privateLinkSentAt=?
                 WHERE productID=?"
            );

            mysqli_stmt_bind_param(
                $stmt,
                'ssssddisssiisssi',
                $nameEN,
                $nameGR,
                $descEN,
                $descGR,
                $price,
                $cost,
                $inv,
                $status,
                $category,
                $materialType,
                $yarnTypeID,
                $isSellingFast,
                $privateCustomerEmail,
                $privateAccessToken,
                $privateLinkSentAt,
                $id
            );
            mysqli_stmt_execute($stmt);
            if ($hasWarningPost) {
                productMgmtSaveProductWarning($conn, $id, $productWarningEN, $productWarningGR);
            }

            $szSaveStmt = mysqli_prepare($conn, "UPDATE products SET availableSizes = ? WHERE productID = ?");
            if ($szSaveStmt) {
                mysqli_stmt_bind_param($szSaveStmt, 'si', $availableSizesSave, $id);
                mysqli_stmt_execute($szSaveStmt);
                mysqli_stmt_close($szSaveStmt);
            }
            app_product_size_prices_save($conn, $id, $rawSizesPost, $sizePricesPost);

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
                $canAdd   = max(0, PRODUCT_MGMT_MAX_PRODUCT_PHOTOS - $existing);
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

            $stmt = mysqli_prepare($conn, "UPDATE products SET cartStatus='discontinued' WHERE productID=?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $flash = 'warn:Product has existing orders and cannot be fully deleted — it has been marked as Discontinued and hidden from the shop.';
        } else {

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

$searchTerm = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status_filter'] ?? ''));
$allowedStatusFilters = ['active', 'low_stock', 'out_of_stock', 'made_to_order', 'discontinued'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = '';
}
$products = [];
$productsSql = "
    SELECT
        p.*,
        CASE
            WHEN vstats.min_price IS NOT NULL AND sizestats.min_price IS NOT NULL THEN LEAST(vstats.min_price, sizestats.min_price)
            ELSE COALESCE(vstats.min_price, sizestats.min_price, p.basePrice)
        END AS displayMinPrice,
        CASE
            WHEN vstats.max_price IS NOT NULL AND sizestats.max_price IS NOT NULL THEN GREATEST(vstats.max_price, sizestats.max_price)
            ELSE COALESCE(vstats.max_price, sizestats.max_price, p.basePrice)
        END AS displayMaxPrice,
        COALESCE(sizestats.price_count, 0) AS sizePriceCount
    FROM products p
    LEFT JOIN (
        SELECT
            productID,
            MIN(CASE WHEN price IS NOT NULL AND price >= 0 THEN price END) AS min_price,
            MAX(CASE WHEN price IS NOT NULL AND price >= 0 THEN price END) AS max_price
        FROM product_variations
        GROUP BY productID
    ) vstats ON vstats.productID = p.productID
    LEFT JOIN (
        SELECT
            productID,
            MIN(CASE WHEN price IS NOT NULL AND price >= 0 THEN price END) AS min_price,
            MAX(CASE WHEN price IS NOT NULL AND price >= 0 THEN price END) AS max_price,
            COUNT(CASE WHEN price IS NOT NULL AND price >= 0 THEN 1 END) AS price_count
        FROM product_size_prices
        GROUP BY productID
    ) sizestats ON sizestats.productID = p.productID
";
if ($searchTerm !== '' && $statusFilter !== '') {
    $productsSql .= " WHERE (
        p.nameEN LIKE ? OR
        p.nameGR LIKE ? OR
        p.sku LIKE ? OR
        p.category LIKE ? OR
        p.cartStatus LIKE ? OR
        CAST(p.productID AS CHAR) LIKE ? OR
        p.descriptionEN LIKE ? OR
        p.descriptionGR LIKE ?
    ) AND p.cartStatus = ?";
} elseif ($searchTerm !== '') {
    $productsSql .= " WHERE
        p.nameEN LIKE ? OR
        p.nameGR LIKE ? OR
        p.sku LIKE ? OR
        p.category LIKE ? OR
        p.cartStatus LIKE ? OR
        CAST(p.productID AS CHAR) LIKE ? OR
        p.descriptionEN LIKE ? OR
        p.descriptionGR LIKE ?";
} elseif ($statusFilter !== '') {
    $productsSql .= " WHERE p.cartStatus = ?";
}
$productsSql .= " ORDER BY p.nameEN ASC";

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

$editProduct = null;
$editSizePrices = [];
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
    if ($editProduct) {
        $resolvedEditYarnTypeID = app_product_yarn_type_id($conn, (int)$editProduct['productID']);
        if ($resolvedEditYarnTypeID > 0) {
            $editProduct['yarnTypeID'] = $resolvedEditYarnTypeID;
        }
        $editSizePrices = app_product_size_prices_for_product($conn, (int)$editProduct['productID']);
    }
}

$availStatus = [
    'active'        => ['label' => 'made to order', 'badge' => 'badge-muted'],
    'low_stock'     => ['label' => 'made to order', 'badge' => 'badge-muted'],
    'out_of_stock'  => ['label' => 'made to order', 'badge' => 'badge-muted'],
    'made_to_order' => ['label' => 'made to order', 'badge' => 'badge-muted'],
    'discontinued'  => ['label' => 'hidden',        'badge' => 'badge-muted'],
];

$images = [];
$r = mysqli_query($conn, "SELECT productID, imageID FROM photos ORDER BY imageID ASC");
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $images[$row['productID']][] = (int)$row['imageID'];
    }
}

$productWarningsByProduct = [];
foreach ($products as $productRow) {
    $productWarningsByProduct[(int)$productRow['productID']] = [
        'en' => (string)($productRow['productWarningEN'] ?? ''),
        'gr' => (string)($productRow['productWarningGR'] ?? ''),
    ];
}
$globalProductWarnings = app_product_global_warning_texts($conn);

$categories = ['Animals','Blankets','Bags','Decor','Dolls'];

$statuses = [
    'active'        => 'Made to Order',
    'low_stock'     => 'Made to Order (Legacy Low Stock)',
    'out_of_stock'  => 'Made to Order (Legacy Out of Stock)',
    'made_to_order' => 'Made to Order',
    'discontinued'  => 'Discontinued',
];
$statusFilterOptions = [
    ''              => 'All Statuses',
    'active'        => 'Made to Order',
    'low_stock'     => 'Legacy Low Stock',
    'out_of_stock'  => 'Legacy Out of Stock',
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
  <link rel="stylesheet" href="assets/admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/admin.css') ?>">
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
              <th>Yarn Type</th>
              <th>Promotions</th>
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
                  <?php
                    $displayMinPrice = (float)($p['displayMinPrice'] ?? $p['basePrice'] ?? 0);
                    $displayMaxPrice = (float)($p['displayMaxPrice'] ?? $p['basePrice'] ?? 0);
                  ?>
                  <span class="price-new"><?= htmlspecialchars(productMgmtFormatPriceRange($displayMinPrice, $displayMaxPrice)) ?></span>
                  <?php if (abs($displayMaxPrice - $displayMinPrice) > 0.009): ?>
                    <div class="text-sm text-muted">Base: €<?= number_format((float)$p['basePrice'], 2) ?></div>
                  <?php endif; ?>
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
                <td class="text-muted"><?= htmlspecialchars($yarnTypeNamesById[(int)($p['yarnTypeID'] ?? 0)] ?? ($p['materialType'] ?? '-')) ?></td>
                <td>
                  <?php if (!empty($p['isSellingFast'])): ?>
                    <span class="badge badge-orange">Homepage</span>
                  <?php else: ?>
                    <span class="text-muted">&mdash;</span>
                  <?php endif; ?>
                </td>
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

      <div class="card" style="margin-top:24px">
        <div class="card-title">Edit Product Warning Box</div>
        <p class="text-sm text-muted" style="margin-bottom:20px">
          Edit the default warning shown on all product pages, or choose one product to create a product-specific override. Clear both product-specific messages to use the global warning again.
        </p>
        <form method="POST" data-ignore-unsaved-warning>
          <input type="hidden" name="action" value="update_warning_message">
          <input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm) ?>">
          <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
          <div class="form-group" style="max-width:420px">
            <label class="form-label">Warning Applies To</label>
            <select id="warning-target" name="warningTarget" class="form-input" onchange="pmWarningLoad()" required>
              <option value="global">All products default warning</option>
              <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p['productID'] ?>"><?= htmlspecialchars($p['nameEN']) ?></option>
              <?php endforeach; ?>
            </select>
            <div id="warning-scope-help" class="text-sm text-muted" style="margin-top:6px">
              Products with empty custom warnings use this global message.
            </div>
          </div>
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Warning Message (EN)</label>
              <textarea id="warning-en" name="productWarningEN" class="form-input" rows="5" maxlength="2500" placeholder="One warning per line"><?= htmlspecialchars($globalProductWarnings['en'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
              <label class="form-label">Warning Message (GR)</label>
              <textarea id="warning-gr" name="productWarningGR" class="form-input" rows="5" maxlength="2500" placeholder="One warning per line"><?= htmlspecialchars($globalProductWarnings['gr'] ?? '') ?></textarea>
            </div>
          </div>
          <div style="display:flex;justify-content:flex-end">
            <button type="submit" class="btn-save">
              <i class="fas fa-save"></i> Save Warning Message
            </button>
          </div>
        </form>
      </div>

    </div>
  </main>
</div>

<div class="modal-overlay" id="modalAdd">
  <div class="modal-box">
    <h3>Add Product</h3>
    <p class="modal-sub">Fill in the product details below.</p>
    <form method="POST" enctype="multipart/form-data" data-ignore-unsaved-warning>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm) ?>">
      <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
      <div class="form-group">
        <label class="form-label">Product Photos <span class="text-muted">(up to <?= PRODUCT_MGMT_MAX_PRODUCT_PHOTOS ?>)</span></label>
        <input type="file" name="photos[]" class="form-input" accept="image/*" multiple data-product-photo-input data-photo-slots="<?= PRODUCT_MGMT_MAX_PRODUCT_PHOTOS ?>" data-max-file-size="<?= PRODUCT_MGMT_MAX_UPLOAD_BYTES ?>">
        <span class="form-hint">Hold Ctrl/Cmd to select multiple photos. Uploaded product photos are optimized and stored as WebP automatically.</span>
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
          <label class="form-label">Base / Fallback Selling Price (€)</label>
          <input name="basePrice" type="number" step="0.01" min="0" class="form-input" placeholder="Auto from size prices">
          <span class="form-hint">Used when a size has no custom price. If every size has a price, you can leave this blank.</span>
        </div>
        <div class="form-group">
          <label class="form-label">Material Cost (€) <span class="text-muted">(internal)</span></label>
          <input name="costPrice" type="number" step="0.01" min="0" class="form-input" placeholder="0.00">
          <span class="form-hint">Used for profitability tracking. Customers only see selling price.</span>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Category</label>
        <select name="category" class="form-input">
          <option value="">— Select —</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars((string)$cat) ?>"><?= htmlspecialchars((string)$cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Yarn Type *</label>
          <select name="yarnTypeID" class="form-input" required>
            <option value="">-- Select yarn type --</option>
            <?php foreach ($yarnTypes as $yt): ?>
              <option value="<?= (int)$yt['typeID'] ?>" <?= (int)$yt['typeID'] === $defaultYarnTypeID ? 'selected' : '' ?>><?= htmlspecialchars((string)$yt['typeName']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="form-hint">All colours from this yarn type appear automatically on the product page.</span>
          <input name="inventory" type="hidden" value="0">
        </div>
        <div class="form-group">
          <label class="form-label">SKU</label>
          <input name="sku" class="form-input" placeholder="Auto-generated if blank">
        </div>
      </div>
      <div class="form-group" data-size-editor data-size-prices='{}'>
        <label class="form-label">Available Sizes</label>
        <div data-size-chips style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;min-height:28px;"></div>
        <div style="display:flex;gap:8px;">
          <input type="text" data-size-input class="form-input" placeholder="e.g. Small, Medium, Large" style="flex:1;">
          <button type="button" data-size-add class="btn-save" style="padding:8px 14px;white-space:nowrap;">Add</button>
        </div>
        <input type="hidden" name="availableSizes" data-size-hidden value="Small,Medium,Large">
        <div data-size-price-rows style="margin-top:10px;"></div>
        <p class="text-sm text-muted" style="margin-top:6px;">Add a selling price per size to show a price range in Product Management and Shop. Blank size prices use the fallback price.</p>
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
        <label class="form-label">Product Photos <span class="text-muted">(up to <?= PRODUCT_MGMT_MAX_PRODUCT_PHOTOS ?>)</span></label>
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
          <span class="form-hint"><?= count($productPhotos) ?>/<?= PRODUCT_MGMT_MAX_PRODUCT_PHOTOS ?> photos — click &times; to remove</span>
        <?php endif; ?>
        <?php if (count($productPhotos) < PRODUCT_MGMT_MAX_PRODUCT_PHOTOS): ?>
          <input type="file" name="photos[]" class="form-input" accept="image/*" multiple style="margin-top:8px;" data-product-photo-input data-photo-slots="<?= PRODUCT_MGMT_MAX_PRODUCT_PHOTOS - count($productPhotos) ?>" data-max-file-size="<?= PRODUCT_MGMT_MAX_UPLOAD_BYTES ?>">
          <span class="form-hint">Add up to <?= PRODUCT_MGMT_MAX_PRODUCT_PHOTOS - count($productPhotos) ?> more photo(s) — hold Ctrl/Cmd to select multiple. Uploaded product photos are optimized and stored as WebP automatically.</span>
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
          <label class="form-label">Base / Fallback Selling Price (€)</label>
          <input name="basePrice" type="number" step="0.01" min="0" class="form-input" value="<?= $editProduct['basePrice'] ?>">
          <span class="form-hint">Used when a size has no custom price. If every size has a price, you can leave this blank.</span>
        </div>
        <div class="form-group">
          <label class="form-label">Material Cost (€) <span class="text-muted">(internal)</span></label>
          <input name="costPrice" type="number" step="0.01" class="form-input" value="<?= $editProduct['costPrice'] ?>">
        </div>
      </div>
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
        <label class="form-label">Yarn Type *</label>
        <select name="yarnTypeID" class="form-input" required>
          <option value="">-- Select yarn type --</option>
          <?php foreach ($yarnTypes as $yt): ?>
            <option value="<?= (int)$yt['typeID'] ?>" <?= (int)($editProduct['yarnTypeID'] ?? 0) === (int)$yt['typeID'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$yt['typeName']) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="form-hint">All colours from this yarn type appear automatically on the product page.</span>
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
          <?= (string)($editProduct['cartStatus'] ?? '') === 'made_to_order' ? 'required' : '' ?>
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
      <input name="inventory" type="hidden" value="<?= (int)$editProduct['inventory'] ?>">
      <div class="form-group" data-size-editor data-size-prices='<?= htmlspecialchars(json_encode($editSizePrices, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>'>
        <label class="form-label">Available Sizes</label>
        <div id="pm-size-chips" data-size-chips style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;min-height:28px;"></div>
        <div style="display:flex;gap:8px;">
          <input type="text" id="pm-size-input" data-size-input class="form-input" placeholder="e.g. Small, Medium, Large" style="flex:1;">
          <button type="button" id="pm-size-add-btn" data-size-add class="btn-save" style="padding:8px 14px;white-space:nowrap;">Add</button>
        </div>
        <input type="hidden" name="availableSizes" id="pm-sizes-hidden" data-size-hidden value="<?= htmlspecialchars($editProduct['availableSizes'] ?? '') ?>">
        <div data-size-price-rows style="margin-top:10px;"></div>
        <p class="text-sm text-muted" style="margin-top:6px;">Type a size and press Add or Enter. Fill different size prices to show a price range in Product Management and Shop.</p>
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

var pmWarningMap = <?= json_encode($productWarningsByProduct, JSON_UNESCAPED_UNICODE) ?>;
var pmGlobalWarning = <?= json_encode([
  'en' => (string)($globalProductWarnings['en'] ?? ''),
  'gr' => (string)($globalProductWarnings['gr'] ?? ''),
], JSON_UNESCAPED_UNICODE) ?>;

function pmWarningLoad() {
  var targetEl = document.getElementById('warning-target');
  var helpEl = document.getElementById('warning-scope-help');
  var target = targetEl ? targetEl.value : 'global';
  var data = target === 'global'
    ? pmGlobalWarning
    : (pmWarningMap[parseInt(target || '0', 10) || 0] || { en: '', gr: '' });
  document.getElementById('warning-en').value = data.en || '';
  document.getElementById('warning-gr').value = data.gr || '';
  if (helpEl) {
    helpEl.textContent = target === 'global'
      ? 'Products with empty custom warnings use this global message.'
      : 'This overrides the global warning only for the selected product. Clear both fields to return it to the global warning.';
  }
}

document.querySelectorAll('[data-product-photo-input]').forEach(function(input) {
  input.addEventListener('change', function() {
    var slots = parseInt(input.getAttribute('data-photo-slots') || '0', 10) || 0;
    if (slots > 0 && input.files && input.files.length > slots) {
      alert('You can add only ' + slots + ' more product photo(s). The limit is <?= PRODUCT_MGMT_MAX_PRODUCT_PHOTOS ?> photos per product.');
      input.value = '';
      return;
    }
    var maxSize = parseInt(input.getAttribute('data-max-file-size') || '0', 10) || 0;
    if (maxSize > 0 && input.files) {
      for (var i = 0; i < input.files.length; i++) {
        if (input.files[i].size > maxSize) {
          alert('Each product photo must be 5 MB or smaller. Please choose a smaller image.');
          input.value = '';
          return;
        }
      }
    }
  });
});

(function () {
    var chipsWrap  = document.getElementById('pm-size-chips');
    var hiddenInput = document.getElementById('pm-sizes-hidden');
    var textInput  = document.getElementById('pm-size-input');
    var addBtn     = document.getElementById('pm-size-add-btn');
    if (!chipsWrap || !hiddenInput || !textInput) return;
    if (chipsWrap.closest('[data-size-editor]')) return;

    function getSizes() {
        return hiddenInput.value.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
    }

    function setSizes(arr) {
        hiddenInput.value = arr.filter(Boolean).join(',');
    }

    function renderChips() {
        chipsWrap.innerHTML = '';
        getSizes().forEach(function(size) {
            var chip = document.createElement('span');
            chip.style.cssText = 'display:inline-flex;align-items:center;gap:4px;background:#f3eeff;border:1px solid #ddd2f5;border-radius:20px;padding:3px 10px;font-size:13px;color:#6b5b8a;font-weight:500;';
            chip.textContent = size;
            var del = document.createElement('button');
            del.type = 'button';
            del.textContent = '×';
            del.style.cssText = 'background:none;border:none;cursor:pointer;font-size:15px;color:#9b7fc7;padding:0 0 0 4px;line-height:1;';
            del.addEventListener('click', function() {
                var remaining = getSizes().filter(function(s){ return s !== size; });
                if (remaining.length === 0) return;
                setSizes(remaining);
                renderChips();
            });
            chip.appendChild(del);
            chipsWrap.appendChild(chip);
        });
    }

    function addSize() {
        var raw = textInput.value.trim();
        if (!raw) return;
        var parts = raw.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
        var current = getSizes();
        parts.forEach(function(s) {
            if (s && !current.includes(s)) current.push(s);
        });
        setSizes(current);
        renderChips();
        textInput.value = '';
        textInput.focus();
    }

    addBtn.addEventListener('click', addSize);
    textInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); addSize(); }
    });

    renderChips();
})();

(function () {
    function parsePrices(editor) {
        try {
            var parsed = JSON.parse(editor.getAttribute('data-size-prices') || '{}');
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (err) {
            return {};
        }
    }

    function setupSizeEditor(editor) {
        var chipsWrap = editor.querySelector('[data-size-chips]');
        var hiddenInput = editor.querySelector('[data-size-hidden]');
        var textInput = editor.querySelector('[data-size-input]');
        var addBtn = editor.querySelector('[data-size-add]');
        var priceRows = editor.querySelector('[data-size-price-rows]');
        if (!chipsWrap || !hiddenInput || !textInput || !addBtn || !priceRows) return;

        var prices = parsePrices(editor);
        function getSizes() {
            return hiddenInput.value.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
        }
        function setSizes(arr) {
            var seen = {};
            hiddenInput.value = arr.filter(function(size) {
                var key = String(size || '').trim().toLowerCase();
                if (!key || seen[key]) return false;
                seen[key] = true;
                return true;
            }).join(',');
        }
        function priceForSize(size) {
            if (Object.prototype.hasOwnProperty.call(prices, size)) return prices[size];
            var target = String(size || '').toLowerCase();
            var found = '';
            Object.keys(prices).forEach(function(key) {
                if (String(key).toLowerCase() === target) found = prices[key];
            });
            return found;
        }
        function renderPrices() {
            priceRows.innerHTML = '';
            getSizes().forEach(function(size) {
                var row = document.createElement('div');
                row.style.cssText = 'display:grid;grid-template-columns:minmax(110px,1fr) minmax(120px,160px);gap:8px;align-items:center;margin-bottom:8px;';
                var label = document.createElement('label');
                label.className = 'form-label';
                label.style.margin = '0';
                label.textContent = size + ' price';
                var input = document.createElement('input');
                input.type = 'number';
                input.step = '0.01';
                input.min = '0';
                input.name = 'sizePrices[' + size + ']';
                input.className = 'form-input';
                input.placeholder = 'Fallback price';
                var existing = priceForSize(size);
                if (existing !== '' && existing !== null && typeof existing !== 'undefined') {
                    input.value = Number(existing).toFixed(2);
                }
                input.addEventListener('input', function() { prices[size] = input.value; });
                row.appendChild(label);
                row.appendChild(input);
                priceRows.appendChild(row);
            });
        }
        function renderChips() {
            chipsWrap.innerHTML = '';
            getSizes().forEach(function(size) {
                var chip = document.createElement('span');
                chip.style.cssText = 'display:inline-flex;align-items:center;gap:4px;background:#f3eeff;border:1px solid #ddd2f5;border-radius:20px;padding:3px 10px;font-size:13px;color:#6b5b8a;font-weight:500;';
                chip.textContent = size;
                var del = document.createElement('button');
                del.type = 'button';
                del.textContent = 'x';
                del.style.cssText = 'background:none;border:none;cursor:pointer;font-size:15px;color:#9b7fc7;padding:0 0 0 4px;line-height:1;';
                del.addEventListener('click', function() {
                    var remaining = getSizes().filter(function(s){ return s !== size; });
                    if (remaining.length === 0) return;
                    setSizes(remaining);
                    renderChips();
                });
                chip.appendChild(del);
                chipsWrap.appendChild(chip);
            });
            renderPrices();
        }
        function addSize() {
            var raw = textInput.value.trim();
            if (!raw) return;
            var parts = raw.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
            var current = getSizes();
            parts.forEach(function(s) {
                if (s && !current.some(function(existing) { return existing.toLowerCase() === s.toLowerCase(); })) current.push(s);
            });
            setSizes(current);
            renderChips();
            textInput.value = '';
            textInput.focus();
        }
        addBtn.addEventListener('click', addSize);
        textInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); addSize(); }
        });
        renderChips();
    }

    document.querySelectorAll('[data-size-editor]').forEach(setupSizeEditor);
})();
</script>
</body>
</html>
