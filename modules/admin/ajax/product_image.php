<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../../../include/security.php';
require_once __DIR__ . '/../../../include/image_storage.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}

$stmt = $conn->prepare("SELECT photo, productID FROM photos WHERE imageID = ? LIMIT 1");
if (!$stmt) {
    http_response_code(404);
    exit;
}
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    http_response_code(404);
    exit;
}

$data     = (string)($row['photo'] ?? '');
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $data !== '' ? (string)($finfo->buffer($data) ?: '') : '';

if ($data === '' || !app_allowed_image_mime($mimeType)) {
    // BLOB is empty or unrecognised — fall back to the reference photo file on disk
    $productId = (int)($row['productID'] ?? 0);
    $data      = '';
    $mimeType  = '';

    if ($productId > 0) {
        $coStmt = $conn->prepare(
            "SELECT photoReferencePath FROM custom_orders
              WHERE sourceProductID = ?
                AND photoReferencePath IS NOT NULL
                AND TRIM(photoReferencePath) <> ''
              ORDER BY customOrderID DESC LIMIT 1"
        );
        if ($coStmt) {
            $coStmt->bind_param('i', $productId);
            $coStmt->execute();
            $coRes = $coStmt->get_result();
            $coRow = $coRes ? $coRes->fetch_assoc() : null;
            $coStmt->close();

            $relativePath = trim((string)($coRow['photoReferencePath'] ?? ''));
            if ($relativePath !== '') {
                $absolutePath = app_image_project_root()
                    . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, ltrim(str_replace('\\', '/', $relativePath), '/'));

                if (is_file($absolutePath)) {
                    $fileData = file_get_contents($absolutePath);
                    if (is_string($fileData) && $fileData !== '') {
                        $fileMime = (string)($finfo->buffer($fileData) ?: '');
                        if (app_allowed_image_mime($fileMime)) {
                            $data     = $fileData;
                            $mimeType = $fileMime;
                        }
                    }
                }
            }
        }
    }

    if ($data === '') {
        http_response_code(404);
        exit;
    }
}

header('Content-Type: ' . $mimeType);
header('X-Content-Type-Options: nosniff');
$etag = '"' . sha1($data) . '"';
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=604800, immutable');
    http_response_code(304);
    exit;
}

header('Content-Length: ' . strlen($data));
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=604800, immutable');
echo $data;
