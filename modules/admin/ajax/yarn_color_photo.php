<?php
/**
 * AJAX handler — upload or remove a photo for a color+yarn type combination.
 * Stores the file in /assets/yarn_colors/ and saves the path in color_yarn_types.
 *
 * POST actions:
 *   upload  — expects: colorID, typeID, photo (file)
 *   remove  — expects: colorID, typeID
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$action  = $_POST['action']  ?? '';
$colorID = (int)($_POST['colorID'] ?? 0);
$typeID  = (int)($_POST['typeID']  ?? 0);

if (!$colorID || !$typeID) {
    echo json_encode(['ok' => false, 'error' => 'Missing colorID or typeID']);
    exit;
}

// Verify the row exists
$chk = mysqli_prepare($conn, "SELECT 1 FROM color_yarn_types WHERE colorID=? AND typeID=?");
mysqli_stmt_bind_param($chk, 'ii', $colorID, $typeID);
mysqli_stmt_execute($chk);
if (!mysqli_stmt_fetch($chk)) {
    echo json_encode(['ok' => false, 'error' => 'Combination not found']);
    exit;
}
mysqli_stmt_close($chk);

$uploadDir = realpath(__DIR__ . '/../../../assets/yarn_colors') . '/';

// ── REMOVE ────────────────────────────────────────────────────────────────────
if ($action === 'remove') {
    // Fetch current path to delete the file
    $sel = mysqli_prepare($conn, "SELECT photoPath FROM color_yarn_types WHERE colorID=? AND typeID=?");
    mysqli_stmt_bind_param($sel, 'ii', $colorID, $typeID);
    mysqli_stmt_execute($sel);
    mysqli_stmt_bind_result($sel, $currentPath);
    mysqli_stmt_fetch($sel);
    mysqli_stmt_close($sel);

    if ($currentPath) {
        $fullPath = realpath(__DIR__ . '/../../../') . '/' . ltrim($currentPath, '/');
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    $upd = mysqli_prepare($conn, "UPDATE color_yarn_types SET photoPath=NULL WHERE colorID=? AND typeID=?");
    mysqli_stmt_bind_param($upd, 'ii', $colorID, $typeID);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    echo json_encode(['ok' => true]);
    exit;
}

// ── UPLOAD ────────────────────────────────────────────────────────────────────
if ($action === 'upload') {
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No file uploaded or upload error']);
        exit;
    }

    $file     = $_FILES['photo'];
    $mimeType = mime_content_type($file['tmp_name']);
    $allowed  = ['image/jpeg', 'image/png', 'image/webp'];

    if (!in_array($mimeType, $allowed, true)) {
        echo json_encode(['ok' => false, 'error' => 'Only JPG, PNG, WebP images are allowed']);
        exit;
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'File too large (max 2 MB)']);
        exit;
    }

    $ext      = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mimeType];
    $filename = 'type' . $typeID . '_color' . $colorID . '.' . $ext;
    $destPath = $uploadDir . $filename;

    // Delete old file if it exists with a different extension
    foreach (['jpg','png','webp'] as $e) {
        $old = $uploadDir . 'type' . $typeID . '_color' . $colorID . '.' . $e;
        if ($old !== $destPath && file_exists($old)) {
            unlink($old);
        }
    }

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
        exit;
    }

    $dbPath = 'assets/yarn_colors/' . $filename;

    $upd = mysqli_prepare($conn, "UPDATE color_yarn_types SET photoPath=? WHERE colorID=? AND typeID=?");
    mysqli_stmt_bind_param($upd, 'sii', $dbPath, $colorID, $typeID);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    echo json_encode(['ok' => true, 'path' => $dbPath]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
