<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../../../include/security.php';
require_once __DIR__ . '/../../../include/image_storage.php';

header('Content-Type: application/json; charset=utf-8');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    app_require_csrf(true, 'Invalid CSRF token.');
}

session_write_close();

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$productID = (int)($_POST['productID'] ?? $_GET['productID'] ?? 0);
$colorID = (int)($_POST['colorID'] ?? $_GET['colorID'] ?? 0);
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if ($action === 'upload' && $productID > 0 && $colorID > 0) {
    if (empty($_FILES['photo']['tmp_name']) || (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No file received']);
        exit;
    }

    $file = $_FILES['photo'];
    $tmpName = (string)$file['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)($finfo->file($tmpName) ?: '');
    if (!app_allowed_image_mime($mimeType)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid image type']);
        exit;
    }
    if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'Image is too large']);
        exit;
    }

    $dir = __DIR__ . '/../../../assets/product_color_photos/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        echo json_encode(['ok' => false, 'error' => 'Could not create upload directory']);
        exit;
    }

    $filename = 'pcp_' . $productID . '_' . $colorID . '_' . uniqid('', true) . '.webp';
    $dest = $dir . $filename;
    if (!app_image_convert_file_to_webp($tmpName, $dest, 800, 800, 84)) {
        echo json_encode(['ok' => false, 'error' => 'Could not convert image to WebP']);
        exit;
    }

    $photoPath = 'assets/product_color_photos/' . $filename;

    $sort = 1;
    $sortStmt = $conn->prepare("SELECT COALESCE(MAX(sortOrder), 0) + 1 AS nextSort FROM product_color_photos WHERE productID = ? AND colorID = ?");
    if ($sortStmt) {
        $sortStmt->bind_param('ii', $productID, $colorID);
        $sortStmt->execute();
        $sortRes = $sortStmt->get_result();
        $sortRow = $sortRes ? $sortRes->fetch_assoc() : null;
        $sort = (int)($sortRow['nextSort'] ?? 1);
        $sortStmt->close();
    }

    $stmt = $conn->prepare("INSERT INTO product_color_photos (productID, colorID, photoPath, sortOrder) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        @unlink($dest);
        echo json_encode(['ok' => false, 'error' => 'Could not save photo']);
        exit;
    }
    $stmt->bind_param('iisi', $productID, $colorID, $photoPath, $sort);
    $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();

    echo json_encode(['ok' => true, 'id' => $newId, 'photoPath' => $photoPath, 'sortOrder' => $sort]);
    exit;
}

if ($action === 'delete' && $id > 0) {
    $stmt = $conn->prepare("SELECT photoPath FROM product_color_photos WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            $full = __DIR__ . '/../../../' . ltrim((string)$row['photoPath'], '/');
            if (is_file($full)) {
                @unlink($full);
            }
        }
    }

    $deleteStmt = $conn->prepare("DELETE FROM product_color_photos WHERE id = ?");
    if ($deleteStmt) {
        $deleteStmt->bind_param('i', $id);
        $deleteStmt->execute();
        $deleteStmt->close();
    }

    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'list' && $productID > 0 && $colorID > 0) {
    $rows = [];
    $stmt = $conn->prepare("SELECT id, photoPath, sortOrder FROM product_color_photos WHERE productID = ? AND colorID = ? ORDER BY sortOrder ASC");
    if ($stmt) {
        $stmt->bind_param('ii', $productID, $colorID);
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

echo json_encode(['ok' => false, 'error' => 'Invalid request']);
