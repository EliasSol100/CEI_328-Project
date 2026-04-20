<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../../../include/security.php';
require_once __DIR__ . '/../../../include/image_storage.php';

header('Content-Type: application/json; charset=utf-8');
app_require_csrf(true, 'Invalid CSRF token.');

$action = (string)($_POST['action'] ?? '');
$colorID = (int)($_POST['colorID'] ?? 0);
$typeID = (int)($_POST['typeID'] ?? 0);

if ($colorID <= 0 || $typeID <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing colorID or typeID']);
    exit;
}

$chk = mysqli_prepare($conn, "SELECT 1 FROM color_yarn_types WHERE colorID = ? AND typeID = ?");
if (!$chk) {
    echo json_encode(['ok' => false, 'error' => 'Could not validate combination']);
    exit;
}
mysqli_stmt_bind_param($chk, 'ii', $colorID, $typeID);
mysqli_stmt_execute($chk);
mysqli_stmt_store_result($chk);
$exists = mysqli_stmt_num_rows($chk) > 0;
mysqli_stmt_close($chk);

if (!$exists) {
    echo json_encode(['ok' => false, 'error' => 'Combination not found']);
    exit;
}

$rootDir = realpath(__DIR__ . '/../../../');
$uploadDir = realpath(__DIR__ . '/../../../assets/yarn_colors');
if ($rootDir === false || $uploadDir === false) {
    echo json_encode(['ok' => false, 'error' => 'Upload directory is not available']);
    exit;
}
$uploadDir = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

if ($action === 'remove') {
    $sel = mysqli_prepare($conn, "SELECT photoPath FROM color_yarn_types WHERE colorID = ? AND typeID = ?");
    if ($sel) {
        mysqli_stmt_bind_param($sel, 'ii', $colorID, $typeID);
        mysqli_stmt_execute($sel);
        mysqli_stmt_bind_result($sel, $currentPath);
        mysqli_stmt_fetch($sel);
        mysqli_stmt_close($sel);

        if (!empty($currentPath)) {
            $fullPath = $rootDir . DIRECTORY_SEPARATOR . ltrim((string)$currentPath, '/\\');
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    $upd = mysqli_prepare($conn, "UPDATE color_yarn_types SET photoPath = NULL WHERE colorID = ? AND typeID = ?");
    if ($upd) {
        mysqli_stmt_bind_param($upd, 'ii', $colorID, $typeID);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    }

    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'upload') {
    if (empty($_FILES['photo']) || (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No file uploaded or upload error']);
        exit;
    }

    $file = $_FILES['photo'];
    $tmpName = (string)$file['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)($finfo->file($tmpName) ?: '');
    if (!app_allowed_image_mime($mimeType)) {
        echo json_encode(['ok' => false, 'error' => 'Only image uploads are allowed']);
        exit;
    }
    if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'File too large (max 2 MB)']);
        exit;
    }

    $filename = 'type' . $typeID . '_color' . $colorID . '.jpg';
    $destPath = $uploadDir . $filename;

    foreach (['jpg', 'png', 'gif', 'webp', 'jpeg'] as $oldExt) {
        $old = $uploadDir . 'type' . $typeID . '_color' . $colorID . '.' . $oldExt;
        if ($old !== $destPath && is_file($old)) {
            @unlink($old);
        }
    }

    if (!app_image_convert_file_to_jpeg($tmpName, $destPath, 1200, 1200, 84)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to convert file to JPG']);
        exit;
    }

    $dbPath = 'assets/yarn_colors/' . $filename;
    $upd = mysqli_prepare($conn, "UPDATE color_yarn_types SET photoPath = ? WHERE colorID = ? AND typeID = ?");
    if ($upd) {
        mysqli_stmt_bind_param($upd, 'sii', $dbPath, $colorID, $typeID);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    }

    echo json_encode(['ok' => true, 'path' => $dbPath]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
