<?php
// Serve product image from database blob
require_once __DIR__ . '/../includes/db.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); exit; }

$r = mysqli_query($conn, "SELECT photo FROM photos WHERE imageID=$id LIMIT 1");
if (!$r || !($row = mysqli_fetch_assoc($r))) { http_response_code(404); exit; }

// Detect image type
$data = $row['photo'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->buffer($data) ?: 'image/jpeg';

header("Content-Type: $mime");
header('Cache-Control: max-age=86400');
echo $data;
