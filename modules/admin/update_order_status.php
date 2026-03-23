<?php
session_start();
require_once __DIR__ . '/../authentication/database.php';
require_once __DIR__ . '/../include/review_functions.php';

// Simple admin check – adjust to your authentication method
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    http_response_code(403);
    die('Unauthorized');
}

$orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$newStatus = isset($_POST['status']) ? trim($_POST['status']) : '';

if (!$orderId || !in_array($newStatus, ['delivered', 'pending', 'processing', 'shipped', 'cancelled'])) {
    die('Invalid request');
}

$conn->begin_transaction();

// Get current status
$stmt = $conn->prepare("SELECT status FROM orders WHERE orderID = ?");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$oldStatus = $stmt->get_result()->fetch_assoc()['status'] ?? null;
$stmt->close();

// Update order status and delivered_at
$stmt = $conn->prepare("UPDATE orders SET status = ?, delivered_at = IF(? = 'delivered', NOW(), delivered_at) WHERE orderID = ?");
$stmt->bind_param("ssi", $newStatus, $newStatus, $orderId);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected) {
    $conn->commit();

    // If new status is delivered and it wasn't delivered before, send review invitations
    if ($newStatus === 'delivered' && $oldStatus !== 'delivered') {
        $emailSent = sendReviewInvitations($conn, $orderId);
        notifyAdminReviewInvite($conn, $orderId, $emailSent);
    }

    // Redirect back (adjust to your admin order list page)
    header('Location: order_management.php?msg=status_updated');
    exit;
} else {
    $conn->rollback();
    header('Location: order_management.php?error=no_change');
    exit;
}
?>