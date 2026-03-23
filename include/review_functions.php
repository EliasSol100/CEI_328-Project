<?php
if (!defined('INCLUDE_CHECK')) define('INCLUDE_CHECK', true);

require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

// SMTP constants (adjust to your actual credentials)
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'premium245.web-hosting.com');
    define('SMTP_USER', 'admin@festival-web.com');
    define('SMTP_PASS', '!g3$~8tYju*D');
    define('SMTP_PORT', 587);
    define('SMTP_SECURE', 'tls');
    define('ADMIN_EMAIL', 'admin@festival-web.com');
}

define('GUEST_REVIEW_SECRET', 'athina_guest_review_v1');

// ----------------------------------------------------------------------
// Guest review key functions
// ----------------------------------------------------------------------
function createGuestReviewKey(int $orderId, string $orderNumber, string $email): string {
    $payload = $orderId . "|" . strtolower(trim($email)) . "|" . trim($orderNumber);
    return hash_hmac("sha256", $payload, GUEST_REVIEW_SECRET);
}

function verifyGuestReviewKey(?array $order, string $providedKey): bool {
    if (!$order || $providedKey === "") return false;
    $orderId = (int)($order["orderID"] ?? 0);
    $orderNumber = (string)($order["orderNumber"] ?? "");
    $email = (string)($order["email"] ?? "");
    if ($orderId <= 0 || $orderNumber === "" || $email === "") return false;
    $expected = createGuestReviewKey($orderId, $orderNumber, $email);
    return hash_equals($expected, $providedKey);
}

// ----------------------------------------------------------------------
// Guest reviewer user creation
// ----------------------------------------------------------------------
function ensureGuestReviewerUser(mysqli $conn, int $orderId, string $orderEmail): ?array {
    if ($orderId <= 0) return null;

    $username = "guest_review_order_" . $orderId;
    $check = $conn->prepare("SELECT userID, full_name FROM users WHERE username = ? LIMIT 1");
    if ($check) {
        $check->bind_param("s", $username);
        $check->execute();
        $res = $check->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $check->close();
        if ($row) {
            return ["userID" => (int)$row["userID"], "full_name" => (string)($row["full_name"] ?? "Guest")];
        }
    }

    $guestName = "Guest Order #" . $orderId;
    $localPart = trim((string)strstr($orderEmail, "@", true));
    if ($localPart !== "") {
        $safe = preg_replace('/[^a-zA-Z0-9 _.-]/', '', $localPart);
        if (trim($safe) !== "") $guestName = "Guest " . trim($safe);
    }

    $guestEmail = "guest-review-order-" . $orderId . "@guest.local";
    $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

    $insert = $conn->prepare("INSERT INTO users (full_name, email, username, password, role) VALUES (?, ?, ?, ?, 'user')");
    if ($insert) {
        $insert->bind_param("ssss", $guestName, $guestEmail, $username, $passwordHash);
        $ok = $insert->execute();
        $newId = (int)$insert->insert_id;
        $insert->close();
        if ($ok && $newId > 0) {
            return ["userID" => $newId, "full_name" => $guestName];
        }
    }

    $check = $conn->prepare("SELECT userID, full_name FROM users WHERE username = ? LIMIT 1");
    if ($check) {
        $check->bind_param("s", $username);
        $check->execute();
        $res = $check->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $check->close();
        if ($row) return ["userID" => (int)$row["userID"], "full_name" => (string)($row["full_name"] ?? "Guest")];
    }

    return null;
}

// ----------------------------------------------------------------------
// Order eligibility checks
// ----------------------------------------------------------------------
function isOrderReviewEligible(mysqli $conn, int $orderId): bool {
    $stmt = $conn->prepare("
        SELECT 1 FROM orders o
        WHERE o.orderID = ?
          AND LOWER(o.status) IN ('delivered', 'completed')
          AND EXISTS (
              SELECT 1 FROM payments p
              WHERE p.orderID = o.orderID
                AND LOWER(p.paymentStatus) IN ('paid', 'completed', 'captured', 'succeeded', 'confirmed')
              LIMIT 1
          ) LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

function orderContainsProduct(mysqli $conn, int $orderId, int $productId): bool {
    $stmt = $conn->prepare("
        SELECT 1
        FROM order_items oi
        JOIN orders o ON oi.orderID = o.orderID
        WHERE oi.orderID = ? AND oi.productID = ?
          AND LOWER(o.status) IN ('delivered', 'completed')
          AND EXISTS (
              SELECT 1 FROM payments p
              WHERE p.orderID = o.orderID
                AND LOWER(p.paymentStatus) IN ('paid', 'completed', 'captured', 'succeeded', 'confirmed')
          )
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param("ii", $orderId, $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

function userPurchasedProduct(mysqli $conn, int $userId, int $productId): bool {
    $stmt = $conn->prepare("
        SELECT 1
        FROM order_items oi
        JOIN orders o ON oi.orderID = o.orderID
        WHERE o.userID = ? AND oi.productID = ?
          AND LOWER(o.status) IN ('delivered', 'completed')
          AND EXISTS (
              SELECT 1 FROM payments p
              WHERE p.orderID = o.orderID
                AND LOWER(p.paymentStatus) IN ('paid', 'completed', 'captured', 'succeeded', 'confirmed')
          )
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param("ii", $userId, $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

// ----------------------------------------------------------------------
// Review invitation email sending
// ----------------------------------------------------------------------
function sendReviewInvitations($conn, $orderId) {
    $project = '/CEI_328-Project'; // adjust to your actual path
    $stmt = $conn->prepare("
        SELECT o.orderID, o.orderNumber, o.email, o.userID,
               u.email AS user_email, u.full_name
        FROM orders o
        LEFT JOIN users u ON o.userID = u.userID
        WHERE o.orderID = ?
    ");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) return false;

    $customerEmail = $order['user_email'] ?? $order['email'];
    $customerName = $order['full_name'] ?? 'Customer';
    $orderNumber = $order['orderNumber'] ?? 'ORD-' . $orderId;

    $reviewKey = createGuestReviewKey($orderId, $orderNumber, $customerEmail);

    $items = $conn->query("
        SELECT oi.productID, p.nameEN, p.nameGR
        FROM order_items oi
        JOIN products p ON oi.productID = p.productID
        WHERE oi.orderID = $orderId
    ");
    if (!$items || $items->num_rows == 0) return false;

    $body = "
    <html>
    <body>
        <h2>We hope you loved your order #{$orderNumber}!</h2>
        <p>Dear {$customerName},</p>
        <p>Thank you for shopping with Creations by Athina. We'd love to hear your thoughts on the products you received.</p>
        <p>Click the link next to each product to leave a review:</p>
        <ul>
    ";

    while ($item = $items->fetch_assoc()) {
        $productName = htmlspecialchars($item['nameEN'] ?: $item['nameGR']);
        $productId = $item['productID'];
        $reviewUrl = "http://localhost{$project}/submit_product_review.php?order_id={$orderId}&product_id={$productId}&review_key={$reviewKey}";
        $body .= "<li><strong>{$productName}</strong> – <a href='{$reviewUrl}'>Write a Review</a></li>";
    }

    $body .= "
        </ul>
        <p>If you have any questions, please <a href='http://localhost{$project}/contact.php'>contact us</a>.</p>
        <p>Thank you,<br>Creations by Athina Team</p>
    </body>
    </html>";

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_USER, 'Creations by Athina');
        $mail->addAddress($customerEmail, $customerName);
        $mail->isHTML(true);
        $mail->Subject = "Tell us what you think! – Order #{$orderNumber}";
        $mail->Body    = $body;
        $mail->AltBody = strip_tags(str_replace(['</p>','<br>'], "\n", $body));

        $mail->send();
        return true;
    } catch (MailerException $e) {
        error_log("Review invitation email failed for order $orderId: " . $mail->ErrorInfo);
        return false;
    }
}

// ----------------------------------------------------------------------
// Admin notification after review submission
// ----------------------------------------------------------------------
function sendAdminReviewNotification($conn, $reviewId, $productId, $userId, $rating, $reviewText, $photos = []) {
    // Fetch user details
    $user = $conn->query("SELECT full_name, email FROM users WHERE userID = $userId")->fetch_assoc();
    $userName = $user['full_name'] ?? 'Guest';
    $userEmail = $user['email'] ?? '';

    // Fetch product details
    $product = $conn->query("SELECT nameEN, nameGR FROM products WHERE productID = $productId")->fetch_assoc();
    $productName = $product['nameEN'] ?: $product['nameGR'] ?: "Product #$productId";

    // Optionally fetch order number for context (join via order_items)
    $order = $conn->query("
        SELECT o.orderNumber
        FROM orders o
        JOIN order_items oi ON oi.orderID = o.orderID
        WHERE oi.productID = $productId AND o.userID = $userId
        LIMIT 1
    ")->fetch_assoc();
    $orderNumber = $order['orderNumber'] ?? '—';

    $reviewDate = date('F j, Y, g:i a');

    $photosHtml = '';
    if (!empty($photos)) {
        $photosHtml = '<h3 style="margin: 20px 0 10px;">Uploaded Photos</h3>';
        $photosHtml .= '<div style="display: flex; flex-wrap: wrap; gap: 10px;">';
        foreach ($photos as $photo) {
            $photoUrl = "http://localhost/CEI_328-Project/assets/images/reviews/" . $photo;
            $photosHtml .= "<img src='{$photoUrl}' style='width:100px; height:100px; object-fit:cover; border-radius:4px;' />";
        }
        $photosHtml .= '</div>';
    }

    $body = '
    <html>
    <head>
        <style>
            body { font-family: "Helvetica Neue", Arial, sans-serif; line-height: 1.6; color: #333; background: #faf5ff; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 24px; box-shadow: 0 8px 20px rgba(0,0,0,0.05); overflow: hidden; }
            .header { background: linear-gradient(135deg, #f8e1ff 0%, #e9d4ff 100%); padding: 30px 20px; text-align: center; border-bottom: 2px solid #d9b8ff; }
            .header h1 { margin: 0; font-size: 28px; color: #6a1b9a; }
            .content { padding: 30px 25px; }
            .review-details { background: #fef9ff; border-left: 4px solid #c9a9f5; padding: 20px; margin: 20px 0; border-radius: 12px; }
            .footer { background: #f9f3ff; padding: 20px; text-align: center; font-size: 12px; color: #8a6aad; border-top: 1px solid #e6d6ff; }
            .button { display: inline-block; background: #c9a9f5; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 40px; font-weight: bold; }
            .star-rating { color: #ffc107; font-size: 18px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>New Product Review</h1>
            </div>
            <div class="content">
                <p>A new review has been submitted for <strong>' . htmlspecialchars($productName) . '</strong>.</p>
                <div class="review-details">
                    <p><strong>Customer:</strong> ' . htmlspecialchars($userName) . ' (' . htmlspecialchars($userEmail) . ')</p>
                    <p><strong>Order Number:</strong> ' . htmlspecialchars($orderNumber) . '</p>
                    <p><strong>Rating:</strong> <span class="star-rating">' . str_repeat('★', $rating) . str_repeat('☆', 5 - $rating) . '</span> (' . $rating . '/5)</p>
                    <p><strong>Date:</strong> ' . htmlspecialchars($reviewDate) . '</p>
                    <p><strong>Comment:</strong><br>' . nl2br(htmlspecialchars($reviewText)) . '</p>
                    ' . $photosHtml . '
                </div>
                <p style="text-align: center;">
                    <a href="http://localhost/CEI_328-Project/admin/reviews.php?review_id=' . $reviewId . '" class="button">View in Admin Panel</a>
                </p>
                <p><small>This is an automated message. Please do not reply directly to this email.</small></p>
            </div>
            <div class="footer">
                <p>Creations by Athina — Handmade with love</p>
                <p><a href="mailto:admin@festival-web.com">admin@festival-web.com</a> | +30 123 456 7890</p>
            </div>
        </div>
    </body>
    </html>';

    $plainBody = strip_tags(str_replace(['</p>','<br>'], "\n", (string)$body));

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];

        $mail->setFrom(SMTP_USER, 'Creations by Athina');
        $mail->addAddress(ADMIN_EMAIL, 'Admin');
        $mail->isHTML(true);
        $mail->Subject = "New Review for {$productName}";
        $mail->Body    = $body;
        $mail->AltBody = $plainBody;

        $mail->send();
        return true;
    } catch (MailerException $e) {
        error_log("Admin review notification failed: " . $mail->ErrorInfo);
        return false;
    }
}
// ----------------------------------------------------------------------
// Review display functions (used in shop.php)
// ----------------------------------------------------------------------
function displayStars($rating) {
    $html = '';
    $full = floor($rating);
    $half = ($rating - $full) >= 0.5;
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $full) {
            $html .= '<i class="fas fa-star" style="color:#ffc107;"></i>';
        } elseif ($half && $i == $full+1) {
            $html .= '<i class="fas fa-star-half-alt" style="color:#ffc107;"></i>';
        } else {
            $html .= '<i class="far fa-star" style="color:#ffc107;"></i>';
        }
    }
    return $html;
}

function getProductRating(mysqli $conn, $productId) {
    $stmt = $conn->prepare("
        SELECT AVG(rating) AS avg_rating, COUNT(*) AS review_count
        FROM reviews
        WHERE productID = ? AND isVisible = 1
    ");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return [
        'avg' => round((float)$result['avg_rating'], 1),
        'count' => (int)$result['review_count']
    ];
}

function fetchOrderSummary(mysqli $conn, int $orderId): ?array {
    $stmt = $conn->prepare("SELECT orderID, orderNumber, userID, isGuestFlag, email, status FROM orders WHERE orderID = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function fetchProductBasic(mysqli $conn, int $productId): ?array {
    $stmt = $conn->prepare("
        SELECT p.productID, p.nameEN, p.nameGR,
                ROUND(COALESCE(AVG(r.rating), 0), 1) AS avgRating,
                COUNT(r.reviewID) AS reviewCount
         FROM products p
         LEFT JOIN reviews r ON r.productID = p.productID AND r.isVisible = 1
         WHERE p.productID = ?
         GROUP BY p.productID
         LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    return [
        "productID" => (int)$row["productID"],
        "nameEN" => (string)($row["nameEN"] ?? ""),
        "nameGR" => (string)($row["nameGR"] ?? ""),
        "avgRating" => (float)($row["avgRating"] ?? 0),
        "reviewCount" => (int)($row["reviewCount"] ?? 0),
    ];
}