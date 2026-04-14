<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../../../include/security.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}

$stmt = $conn->prepare("SELECT photo FROM photos WHERE imageID = ? LIMIT 1");
if (!$stmt) {
    http_response_code(404);
    exit;
}
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row || !isset($row['photo'])) {
    http_response_code(404);
    exit;
}

$data = $row['photo'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = (string)($finfo->buffer($data) ?: 'image/jpeg');
if (!app_allowed_image_mime($mimeType)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mimeType);
header('X-Content-Type-Options: nosniff');
// Avoid stale broken thumbnails after DB imports or photo replacements.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
echo $data;
