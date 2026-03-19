<?php
require_once __DIR__ . '/../includes/db.php';
session_write_close();

header('Content-Type: application/json');

$action    = $_POST['action']    ?? $_GET['action']    ?? '';
$productID = (int)($_POST['productID'] ?? $_GET['productID'] ?? 0);
$colorID   = (int)($_POST['colorID']   ?? $_GET['colorID']   ?? 0);
$id        = (int)($_POST['id']        ?? $_GET['id']        ?? 0);

/* ── Upload ── */
if ($action === 'upload' && $productID && $colorID) {
    if (empty($_FILES['photo']['tmp_name'])) {
        echo json_encode(['ok' => false, 'error' => 'No file received']);
        exit;
    }
    $file    = $_FILES['photo'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp','gif'];
    if (!in_array($ext, $allowed)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid file type']);
        exit;
    }
    $dir = __DIR__ . '/../../../assets/product_color_photos/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename  = 'pcp_' . $productID . '_' . $colorID . '_' . uniqid() . '.jpg';
    $dest      = $dir . $filename;

    /* Resize & save as JPEG max 800px wide */
    $src = imagecreatefromstring(file_get_contents($file['tmp_name']));
    if ($src === false) {
        echo json_encode(['ok' => false, 'error' => 'Invalid image']);
        exit;
    }
    $ow = imagesx($src); $oh = imagesy($src);
    $maxW = 800;
    if ($ow > $maxW) {
        $nw = $maxW; $nh = (int)round($oh * $maxW / $ow);
    } else {
        $nw = $ow;   $nh = $oh;
    }
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
    imagejpeg($dst, $dest, 85);
    imagedestroy($src); imagedestroy($dst);

    $photoPath = 'assets/product_color_photos/' . $filename;

    /* get next sortOrder */
    $r = $conn->query("SELECT COALESCE(MAX(sortOrder),0)+1 FROM product_color_photos WHERE productID=$productID AND colorID=$colorID");
    $sort = (int)$r->fetch_row()[0];

    $stmt = $conn->prepare("INSERT INTO product_color_photos (productID, colorID, photoPath, sortOrder) VALUES (?,?,?,?)");
    $stmt->bind_param('iisi', $productID, $colorID, $photoPath, $sort);
    $stmt->execute();
    $newId = $conn->insert_id;
    $stmt->close();

    echo json_encode(['ok' => true, 'id' => $newId, 'photoPath' => $photoPath, 'sortOrder' => $sort]);
    exit;
}

/* ── Delete ── */
if ($action === 'delete' && $id) {
    $r = $conn->query("SELECT photoPath FROM product_color_photos WHERE id=$id");
    if ($row = $r->fetch_assoc()) {
        $full = __DIR__ . '/../../../' . $row['photoPath'];
        if (file_exists($full)) unlink($full);
        $conn->query("DELETE FROM product_color_photos WHERE id=$id");
    }
    echo json_encode(['ok' => true]);
    exit;
}

/* ── List ── */
if ($action === 'list' && $productID && $colorID) {
    $rows = [];
    $r = $conn->query("SELECT id, photoPath, sortOrder FROM product_color_photos WHERE productID=$productID AND colorID=$colorID ORDER BY sortOrder ASC");
    while ($row = $r->fetch_assoc()) $rows[] = $row;
    echo json_encode(['ok' => true, 'photos' => $rows]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Invalid request']);
