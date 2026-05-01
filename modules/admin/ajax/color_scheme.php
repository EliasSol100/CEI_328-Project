<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../../../include/security.php';
require_once __DIR__ . '/../../../include/image_storage.php';

header('Content-Type: application/json; charset=utf-8');

$conn->query("
    CREATE TABLE IF NOT EXISTS product_color_scheme (
        id INT AUTO_INCREMENT PRIMARY KEY,
        productID INT NOT NULL,
        num_colors TINYINT NOT NULL DEFAULT 2,
        is_enabled TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY unique_product (productID),
        FOREIGN KEY (productID) REFERENCES products(productID) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$conn->query("
    CREATE TABLE IF NOT EXISTS product_color_scheme_photos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        productID INT NOT NULL,
        photoPath VARCHAR(500) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        FOREIGN KEY (productID) REFERENCES products(productID) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    app_require_csrf(true, 'Invalid CSRF token.');
}

session_write_close();

$action    = (string)($_POST['action']    ?? $_GET['action']    ?? '');
$productID = (int)($_POST['productID']    ?? $_GET['productID'] ?? 0);
$id        = (int)($_POST['id']           ?? $_GET['id']        ?? 0);

if ($action === 'get_config' && $productID > 0) {
    $row = null;
    $stmt = $conn->prepare("SELECT num_colors, is_enabled FROM product_color_scheme WHERE productID = ?");
    if ($stmt) {
        $stmt->bind_param('i', $productID);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
    echo json_encode([
        'ok'         => true,
        'is_enabled' => (bool)(int)($row['is_enabled'] ?? 0),
        'num_colors' => (int)($row['num_colors'] ?? 2),
    ]);
    exit;
}

if ($action === 'save_config' && $productID > 0) {
    $numColors = (int)($_POST['num_colors'] ?? 2);
    $isEnabled = (int)($_POST['is_enabled'] ?? 0);
    if ($numColors < 2 || $numColors > 3) $numColors = 2;
    $isEnabled = $isEnabled ? 1 : 0;

    $stmt = $conn->prepare("
        INSERT INTO product_color_scheme (productID, num_colors, is_enabled)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE num_colors = VALUES(num_colors), is_enabled = VALUES(is_enabled)
    ");
    if ($stmt) {
        $stmt->bind_param('iii', $productID, $numColors, $isEnabled);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'upload_photo' && $productID > 0) {
    if (empty($_FILES['photo']['tmp_name']) || (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No file received']);
        exit;
    }

    $file     = $_FILES['photo'];
    $tmpName  = (string)$file['tmp_name'];
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)($finfo->file($tmpName) ?: '');
    if (!app_allowed_image_mime($mimeType)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid image type']);
        exit;
    }
    if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'Image too large (max 5 MB)']);
        exit;
    }

    $dir = __DIR__ . '/../../../assets/product_color_scheme_photos/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        echo json_encode(['ok' => false, 'error' => 'Could not create upload directory']);
        exit;
    }

    $filename = 'pcs_' . $productID . '_' . uniqid('', true) . '.webp';
    $dest     = $dir . $filename;

    if (!app_image_convert_file_to_webp($tmpName, $dest, 1200, 1200, 86)) {
        echo json_encode(['ok' => false, 'error' => 'Could not convert image to WebP']);
        exit;
    }

    $photoPath = 'assets/product_color_scheme_photos/' . $filename;

    $sort = 1;
    $sortStmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 AS nextSort FROM product_color_scheme_photos WHERE productID = ?");
    if ($sortStmt) {
        $sortStmt->bind_param('i', $productID);
        $sortStmt->execute();
        $sortRes = $sortStmt->get_result();
        $sortRow = $sortRes ? $sortRes->fetch_assoc() : null;
        $sort    = (int)($sortRow['nextSort'] ?? 1);
        $sortStmt->close();
    }

    $stmt = $conn->prepare("INSERT INTO product_color_scheme_photos (productID, photoPath, sort_order) VALUES (?, ?, ?)");
    if (!$stmt) {
        @unlink($dest);
        echo json_encode(['ok' => false, 'error' => 'DB error']);
        exit;
    }
    $stmt->bind_param('isi', $productID, $photoPath, $sort);
    $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();

    echo json_encode(['ok' => true, 'id' => $newId, 'photoPath' => $photoPath]);
    exit;
}

if ($action === 'list_photos' && $productID > 0) {
    $rows = [];
    $stmt = $conn->prepare("SELECT id, photoPath, sort_order FROM product_color_scheme_photos WHERE productID = ? ORDER BY sort_order ASC");
    if ($stmt) {
        $stmt->bind_param('i', $productID);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();
    }
    echo json_encode(['ok' => true, 'photos' => $rows]);
    exit;
}

if ($action === 'delete_photo' && $id > 0) {
    $stmt = $conn->prepare("SELECT photoPath FROM product_color_scheme_photos WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res  = $stmt->get_result();
        $row  = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            $full = __DIR__ . '/../../../' . ltrim((string)$row['photoPath'], '/');
            if (is_file($full)) @unlink($full);
        }
    }
    $del = $conn->prepare("DELETE FROM product_color_scheme_photos WHERE id = ?");
    if ($del) {
        $del->bind_param('i', $id);
        $del->execute();
        $del->close();
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Invalid request']);
