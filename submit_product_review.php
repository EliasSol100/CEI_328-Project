<?php
session_start();

require_once __DIR__ . "/authentication/database.php";
require_once __DIR__ . "/authentication/get_config.php";
require_once __DIR__ . "/include/security.php";
require_once __DIR__ . "/include/translation_helpers.php";

const GUEST_REVIEW_SECRET = "athina_guest_review_v1";

$systemTitle = getSystemConfig("site_title") ?: "Creations by Athina";
$sessionUser = $_SESSION["user"] ?? [];
$loggedUserId = (int)($sessionUser["id"] ?? $sessionUser["userID"] ?? ($_SESSION["user_id"] ?? 0));
$fullName = (string)($sessionUser["full_name"] ?? ($_SESSION["full_name"] ?? "Guest"));
$role = strtolower((string)($sessionUser["role"] ?? ($_SESSION["role"] ?? "guest")));
$isAdmin = in_array($role, ["admin", "administrator", "superadmin"], true);

$GLOBALS["header_user_full_name"] = $fullName;
$GLOBALS["header_user_role"] = $role;

if (empty($_SESSION["product_review_token"])) {
    $_SESSION["product_review_token"] = bin2hex(random_bytes(32));
}
$reviewToken = (string)$_SESSION["product_review_token"];

function reviewWordCount(string $text): int {
    $clean = trim(preg_replace('/\s+/u', ' ', $text));
    if ($clean === "") {
        return 0;
    }
    $parts = preg_split('/\s+/u', $clean);
    return is_array($parts) ? count($parts) : 0;
}

function createGuestReviewKey(int $orderId, string $orderNumber, string $email): string {
    $payload = $orderId . "|" . strtolower(trim($email)) . "|" . trim($orderNumber);
    return hash_hmac("sha256", $payload, GUEST_REVIEW_SECRET);
}

function fetchOrderSummary(mysqli $conn, int $orderId): ?array {
    if ($orderId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("SELECT orderID, orderNumber, userID, isGuestFlag, email, status FROM orders WHERE orderID = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function isOrderReviewEligible(mysqli $conn, int $orderId): bool {
    if ($orderId <= 0) {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT 1
         FROM orders o
         WHERE o.orderID = ?
           AND LOWER(o.status) IN ('delivered', 'completed')
           AND EXISTS (
               SELECT 1
               FROM payments p
               WHERE p.orderID = o.orderID
                 AND LOWER(p.paymentStatus) IN ('paid', 'completed', 'captured', 'succeeded', 'confirmed')
               LIMIT 1
           )
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

function verifyGuestReviewKey(?array $order, string $providedKey): bool {
    if (!$order || $providedKey === "") {
        return false;
    }
    $orderId = (int)($order["orderID"] ?? 0);
    $orderNumber = (string)($order["orderNumber"] ?? "");
    $email = (string)($order["email"] ?? "");
    if ($orderId <= 0 || $orderNumber === "" || $email === "") {
        return false;
    }
    $expected = createGuestReviewKey($orderId, $orderNumber, $email);
    return hash_equals($expected, $providedKey);
}

function ensureGuestReviewerUser(mysqli $conn, int $orderId, string $orderEmail): ?array {
    if ($orderId <= 0) {
        return null;
    }

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
        if (trim((string)$safe) !== "") {
            $guestName = "Guest " . trim((string)$safe);
        }
    }

    $guestEmail = "guest-review-order-" . $orderId . "@guest.local";
    $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

    $insert = $conn->prepare(
        "INSERT INTO users (full_name, email, username, password, is_verified, role, profile_complete, status)
         VALUES (?, ?, ?, ?, 1, 'user', 1, 'active')"
    );
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
        if ($row) {
            return ["userID" => (int)$row["userID"], "full_name" => (string)($row["full_name"] ?? "Guest")];
        }
    }

    return null;
}

function orderContainsProduct(mysqli $conn, int $orderId, int $productId): bool {
    if ($orderId <= 0 || $productId <= 0) {
        return false;
    }
    $stmt = $conn->prepare(
        "SELECT 1
         FROM orders o
         INNER JOIN order_items oi ON oi.orderID = o.orderID
         WHERE o.orderID = ?
           AND oi.productID = ?
           AND LOWER(o.status) IN ('delivered', 'completed')
           AND EXISTS (
               SELECT 1
               FROM payments p
               WHERE p.orderID = o.orderID
                 AND LOWER(p.paymentStatus) IN ('paid', 'completed', 'captured', 'succeeded', 'confirmed')
               LIMIT 1
           )
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ii", $orderId, $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

function userPurchasedProduct(mysqli $conn, int $userId, int $productId): bool {
    if ($userId <= 0 || $productId <= 0) {
        return false;
    }
    $stmt = $conn->prepare(
        "SELECT 1
         FROM order_items oi
         INNER JOIN orders o ON o.orderID = oi.orderID
         WHERE o.userID = ?
           AND oi.productID = ?
           AND LOWER(o.status) IN ('delivered', 'completed')
           AND EXISTS (
               SELECT 1
               FROM payments p
               WHERE p.orderID = o.orderID
                 AND LOWER(p.paymentStatus) IN ('paid', 'completed', 'captured', 'succeeded', 'confirmed')
               LIMIT 1
           )
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ii", $userId, $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

function fetchProductBasic(mysqli $conn, int $productId): ?array {
    if ($productId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT p.productID, p.nameEN, p.nameGR,
                ROUND(COALESCE(AVG(r.rating), 0), 1) AS avgRating,
                COUNT(r.reviewID) AS reviewCount
         FROM products p
         LEFT JOIN reviews r ON r.productID = p.productID AND r.isVisible = 1
         WHERE p.productID = ?
         GROUP BY p.productID
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        "productID" => (int)$row["productID"],
        "nameEN" => (string)($row["nameEN"] ?? ""),
        "nameGR" => (string)($row["nameGR"] ?? ""),
        "avgRating" => (float)($row["avgRating"] ?? 0),
        "reviewCount" => (int)($row["reviewCount"] ?? 0),
    ];
}

function fetchReviewProducts(mysqli $conn, int $loggedUserId, bool $isAdmin, bool $guestAccess, int $orderId): array {
    $items = [];

    if ($orderId > 0) {
        $stmt = null;
        if ($isAdmin || $guestAccess) {
            $stmt = $conn->prepare(
                "SELECT DISTINCT p.productID, p.nameEN, p.nameGR
                 FROM orders o
                 INNER JOIN order_items oi ON oi.orderID = o.orderID
                 INNER JOIN products p ON p.productID = oi.productID
                 WHERE o.orderID = ?
                   AND LOWER(o.status) IN ('delivered', 'completed')
                   AND EXISTS (
                       SELECT 1
                       FROM payments pay
                       WHERE pay.orderID = o.orderID
                         AND LOWER(pay.paymentStatus) IN ('paid', 'completed', 'captured', 'succeeded', 'confirmed')
                       LIMIT 1
                   )
                 ORDER BY p.nameEN ASC, p.productID ASC"
            );
            if ($stmt) {
                $stmt->bind_param("i", $orderId);
            }
        } elseif ($loggedUserId > 0) {
            $stmt = $conn->prepare(
                "SELECT DISTINCT p.productID, p.nameEN, p.nameGR
                 FROM orders o
                 INNER JOIN order_items oi ON oi.orderID = o.orderID
                 INNER JOIN products p ON p.productID = oi.productID
                 WHERE o.orderID = ?
                   AND o.userID = ?
                   AND LOWER(o.status) IN ('delivered', 'completed')
                   AND EXISTS (
                       SELECT 1
                       FROM payments pay
                       WHERE pay.orderID = o.orderID
                         AND LOWER(pay.paymentStatus) IN ('paid', 'completed', 'captured', 'succeeded', 'confirmed')
                       LIMIT 1
                   )
                 ORDER BY p.nameEN ASC, p.productID ASC"
            );
            if ($stmt) {
                $stmt->bind_param("ii", $orderId, $loggedUserId);
            }
        } else {
            return [];
        }

        if (!empty($stmt)) {
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $items[] = [
                    "productID" => (int)$row["productID"],
                    "nameEN" => (string)($row["nameEN"] ?? ""),
                    "nameGR" => (string)($row["nameGR"] ?? ""),
                ];
            }
            $stmt->close();
        }

        return $items;
    }

    if ($loggedUserId <= 0) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT p.productID, p.nameEN, p.nameGR, MAX(o.createdAt) AS lastPurchased
         FROM orders o
         INNER JOIN order_items oi ON oi.orderID = o.orderID
         INNER JOIN products p ON p.productID = oi.productID
         WHERE o.userID = ?
           AND LOWER(o.status) IN ('delivered', 'completed')
           AND EXISTS (
               SELECT 1
               FROM payments pay
               WHERE pay.orderID = o.orderID
                 AND LOWER(pay.paymentStatus) IN ('paid', 'completed', 'captured', 'succeeded', 'confirmed')
               LIMIT 1
           )
         GROUP BY p.productID, p.nameEN, p.nameGR
         ORDER BY lastPurchased DESC, p.nameEN ASC"
    );
    if ($stmt) {
        $stmt->bind_param("i", $loggedUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $items[] = [
                "productID" => (int)$row["productID"],
                "nameEN" => (string)($row["nameEN"] ?? ""),
                "nameGR" => (string)($row["nameGR"] ?? ""),
            ];
        }
        $stmt->close();
    }

    return $items;
}

function canReviewProduct(mysqli $conn, bool $isAdmin, bool $guestAccess, int $loggedUserId, int $orderId, int $productId): bool {
    if ($productId <= 0) {
        return false;
    }
    if ($isAdmin) {
        return true;
    }
    if ($guestAccess) {
        return orderContainsProduct($conn, $orderId, $productId);
    }
    if ($loggedUserId > 0) {
        return userPurchasedProduct($conn, $loggedUserId, $productId);
    }
    return false;
}

function buildReviewUrl(int $orderId, int $productId, string $status = "", string $reviewKey = ""): string {
    $params = [];
    if ($orderId > 0) {
        $params["order_id"] = (string)$orderId;
    }
    if ($productId > 0) {
        $params["product_id"] = (string)$productId;
    }
    if ($status !== "") {
        $params["status"] = $status;
    }
    if ($reviewKey !== "") {
        $params["review_key"] = $reviewKey;
    }
    $query = http_build_query($params);
    return "submit_product_review.php" . ($query !== "" ? ("?" . $query) : "");
}

function reviewTranslateMessage(string $message): string {
    $map = [
        'Could not initialize guest review access. Please try again.' => 'Δεν ήταν δυνατή η ενεργοποίηση πρόσβασης αξιολόγησης επισκέπτη. Προσπαθήστε ξανά.',
        'Review unlocks only after delivery and confirmed payment.' => 'Η αξιολόγηση ξεκλειδώνει μόνο μετά την παράδοση και την επιβεβαιωμένη πληρωμή.',
        'For guest reviews, use the secure review link that is sent after delivery.' => 'Για αξιολογήσεις επισκέπτη, χρησιμοποιήστε τον ασφαλή σύνδεσμο αξιολόγησης που αποστέλλεται μετά την παράδοση.',
        'You can review only delivered products with confirmed payment.' => 'Μπορείτε να αξιολογήσετε μόνο προϊόντα που έχουν παραδοθεί και έχουν επιβεβαιωμένη πληρωμή.',
        'Invalid request token. Please refresh and try again.' => 'Μη έγκυρο token αιτήματος. Ανανεώστε τη σελίδα και προσπαθήστε ξανά.',
        'Select a product first.' => 'Επιλέξτε πρώτα ένα προϊόν.',
        'Rating is required (1 to 5 stars).' => 'Η βαθμολογία είναι υποχρεωτική (1 έως 5 αστέρια).',
        'Comment must be up to 1000 words.' => 'Το σχόλιο μπορεί να έχει έως 1000 λέξεις.',
        'Could not update your review. Please try again.' => 'Δεν ήταν δυνατή η ενημέρωση της αξιολόγησής σας. Προσπαθήστε ξανά.',
        'Could not save your review. Please try again.' => 'Δεν ήταν δυνατή η αποθήκευση της αξιολόγησής σας. Προσπαθήστε ξανά.',
        'Review not found.' => 'Η αξιολόγηση δεν βρέθηκε.',
        'Could not delete this review.' => 'Δεν ήταν δυνατή η διαγραφή αυτής της αξιολόγησης.',
        'Only the customer who submitted this review (or admin) can delete it.' => 'Μόνο ο πελάτης που υπέβαλε αυτή την αξιολόγηση ή ο διαχειριστής μπορεί να τη διαγράψει.',
        'Your review was saved successfully.' => 'Η αξιολόγησή σας αποθηκεύτηκε με επιτυχία.',
        'Your review was deleted successfully.' => 'Η αξιολόγησή σας διαγράφηκε με επιτυχία.',
        'Access denied for review submission.' => 'Η υποβολή αξιολόγησης δεν επιτρέπεται.',
        'No delivered products with confirmed payment are available for review yet.' => 'Δεν υπάρχουν ακόμη παραδομένα προϊόντα με επιβεβαιωμένη πληρωμή διαθέσιμα για αξιολόγηση.',
    ];

    return $map[$message] ?? $message;
}

$orderId = (int)($_GET["order_id"] ?? $_POST["order_id"] ?? 0);
$selectedProductId = (int)($_GET["product_id"] ?? $_POST["product_id"] ?? 0);
$reviewKey = trim((string)($_GET["review_key"] ?? $_POST["review_key"] ?? ""));
$reviewErrors = [];
$reviewInput = ["rating" => "5", "review_text" => ""];

$orderSummary = fetchOrderSummary($conn, $orderId);
$guestAccess = false;
$guestAccessError = "";
$actorUserId = $loggedUserId;

if ($loggedUserId <= 0 && !$isAdmin) {
    $hasOrderContext = $orderSummary && (int)($orderSummary["orderID"] ?? 0) > 0;
    if ($hasOrderContext && verifyGuestReviewKey($orderSummary, $reviewKey)) {
        if (isOrderReviewEligible($conn, (int)$orderSummary["orderID"])) {
            $guestIdentity = ensureGuestReviewerUser($conn, (int)$orderSummary["orderID"], (string)($orderSummary["email"] ?? ""));
            if ($guestIdentity && (int)$guestIdentity["userID"] > 0) {
                $guestAccess = true;
                $actorUserId = (int)$guestIdentity["userID"];
                $GLOBALS["header_user_full_name"] = (string)$guestIdentity["full_name"];
                $GLOBALS["header_user_role"] = "guest";
            } else {
                $guestAccessError = "Could not initialize guest review access. Please try again.";
            }
        } else {
            $guestAccessError = "Review unlocks only after delivery and confirmed payment.";
        }
    } else {
        $guestAccessError = "For guest reviews, use the secure review link that is sent after delivery.";
    }
}

$availableProducts = fetchReviewProducts($conn, $loggedUserId, $isAdmin, $guestAccess, $orderId);
if ($selectedProductId <= 0 && !empty($availableProducts)) {
    $selectedProductId = (int)$availableProducts[0]["productID"];
}

$availableById = [];
foreach ($availableProducts as $item) {
    $availableById[(int)$item["productID"]] = $item;
}

if ($selectedProductId > 0 && !isset($availableById[$selectedProductId])) {
    if (canReviewProduct($conn, $isAdmin, $guestAccess, $loggedUserId, $orderId, $selectedProductId)) {
        $extra = fetchProductBasic($conn, $selectedProductId);
        if ($extra) {
            $availableById[$selectedProductId] = [
                "productID" => (int)$extra["productID"],
                "nameEN" => (string)$extra["nameEN"],
                "nameGR" => (string)$extra["nameGR"],
            ];
        }
    } elseif ($loggedUserId > 0 || $guestAccess) {
        $reviewErrors[] = "You can review only delivered products with confirmed payment.";
    }
}

$availableProducts = array_values($availableById);
if ($selectedProductId <= 0 && !empty($availableProducts)) {
    $selectedProductId = (int)$availableProducts[0]["productID"];
}

$selectedProduct = $selectedProductId > 0 ? fetchProductBasic($conn, $selectedProductId) : null;
if ($selectedProduct && !isset($availableById[$selectedProductId])) {
    $availableProducts[] = [
        "productID" => (int)$selectedProduct["productID"],
        "nameEN" => (string)$selectedProduct["nameEN"],
        "nameGR" => (string)$selectedProduct["nameGR"],
    ];
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($actorUserId > 0 || $isAdmin)) {
    app_require_csrf(false, "Invalid request token. Please refresh and try again.");
    $postedToken = (string)($_POST["review_token"] ?? "");
    if (!hash_equals($reviewToken, $postedToken)) {
        $reviewErrors[] = "Invalid request token. Please refresh and try again.";
    }

    $action = (string)($_POST["action"] ?? "");
    $selectedProductId = (int)($_POST["product_id"] ?? 0);
    $orderId = (int)($_POST["order_id"] ?? $orderId);
    $reviewKey = trim((string)($_POST["review_key"] ?? $reviewKey));

    if ($action === "save_review") {
        $reviewInput["rating"] = trim((string)($_POST["rating"] ?? ""));
        $reviewInput["review_text"] = trim((string)($_POST["review_text"] ?? ""));
        $rating = (int)$reviewInput["rating"];
        $reviewText = $reviewInput["review_text"];

        if ($selectedProductId <= 0) {
            $reviewErrors[] = "Select a product first.";
        }
        if ($rating < 1 || $rating > 5) {
            $reviewErrors[] = "Rating is required (1 to 5 stars).";
        }
        if (!canReviewProduct($conn, $isAdmin, $guestAccess, $loggedUserId, $orderId, $selectedProductId)) {
            $reviewErrors[] = "You can review only delivered products with confirmed payment.";
        }
        if (reviewWordCount($reviewText) > 1000) {
            $reviewErrors[] = "Comment must be up to 1000 words.";
        }

        if (empty($reviewErrors)) {
            $reviewText = mb_substr($reviewText, 0, 7000);
            $existingReviewId = 0;

            $find = $conn->prepare("SELECT reviewID FROM reviews WHERE userID = ? AND productID = ? ORDER BY reviewID DESC LIMIT 1");
            if ($find) {
                $find->bind_param("ii", $actorUserId, $selectedProductId);
                $find->execute();
                $row = $find->get_result()->fetch_assoc();
                $existingReviewId = (int)($row["reviewID"] ?? 0);
                $find->close();
            }

            if ($existingReviewId > 0) {
                $up = $conn->prepare("UPDATE reviews SET rating = ?, reviewText = ?, timestamp = NOW(), isVisible = 1 WHERE reviewID = ? AND userID = ?");
                if ($up) {
                    $up->bind_param("isii", $rating, $reviewText, $existingReviewId, $actorUserId);
                    $ok = $up->execute();
                    $up->close();
                    if ($ok) {
                        header("Location: " . buildReviewUrl($orderId, $selectedProductId, "saved", $reviewKey));
                        exit;
                    }
                }
                $reviewErrors[] = "Could not update your review. Please try again.";
            } else {
                $ins = $conn->prepare("INSERT INTO reviews (userID, productID, rating, reviewText, isVisible) VALUES (?, ?, ?, ?, 1)");
                if ($ins) {
                    $ins->bind_param("iiis", $actorUserId, $selectedProductId, $rating, $reviewText);
                    $ok = $ins->execute();
                    $ins->close();
                    if ($ok) {
                        header("Location: " . buildReviewUrl($orderId, $selectedProductId, "saved", $reviewKey));
                        exit;
                    }
                }
                $reviewErrors[] = "Could not save your review. Please try again.";
            }
        }
    } elseif ($action === "delete_review") {
        $reviewId = (int)($_POST["review_id"] ?? 0);
        if ($reviewId <= 0) {
            $reviewErrors[] = "Review not found.";
        } else {
            if ($isAdmin) {
                $del = $conn->prepare("UPDATE reviews SET isVisible = 0, timestamp = NOW() WHERE reviewID = ?");
                if ($del) {
                    $del->bind_param("i", $reviewId);
                    $del->execute();
                    $affected = $del->affected_rows;
                    $del->close();
                    if ($affected > 0) {
                        header("Location: " . buildReviewUrl($orderId, $selectedProductId, "deleted", $reviewKey));
                        exit;
                    }
                }
                $reviewErrors[] = "Could not delete this review.";
            } else {
                $del = $conn->prepare("UPDATE reviews SET isVisible = 0, timestamp = NOW() WHERE reviewID = ? AND userID = ? AND productID = ?");
                if ($del) {
                    $del->bind_param("iii", $reviewId, $actorUserId, $selectedProductId);
                    $del->execute();
                    $affected = $del->affected_rows;
                    $del->close();
                    if ($affected > 0) {
                        header("Location: " . buildReviewUrl($orderId, $selectedProductId, "deleted", $reviewKey));
                        exit;
                    }
                }
                $reviewErrors[] = "Only the customer who submitted this review (or admin) can delete it.";
            }
        }
    }

    $selectedProduct = $selectedProductId > 0 ? fetchProductBasic($conn, $selectedProductId) : null;
}

$myReview = null;
if ($actorUserId > 0 && $selectedProductId > 0) {
    $myStmt = $conn->prepare("SELECT reviewID, rating, reviewText, timestamp, isVisible FROM reviews WHERE userID = ? AND productID = ? ORDER BY reviewID DESC LIMIT 1");
    if ($myStmt) {
        $myStmt->bind_param("ii", $actorUserId, $selectedProductId);
        $myStmt->execute();
        $row = $myStmt->get_result()->fetch_assoc();
        $myStmt->close();
        if ($row && (int)$row["isVisible"] === 1) {
            $myReview = [
                "reviewID" => (int)$row["reviewID"],
                "rating" => max(1, min(5, (int)$row["rating"])),
                "reviewText" => trim((string)($row["reviewText"] ?? "")),
                "timestamp" => (string)($row["timestamp"] ?? ""),
            ];
        }
    }
}

$status = (string)($_GET["status"] ?? "");
$statusMessage = "";
if ($status === "saved") {
    $statusMessage = "Your review was saved successfully.";
} elseif ($status === "deleted") {
    $statusMessage = "Your review was deleted successfully.";
}

if ($myReview && ($_SERVER["REQUEST_METHOD"] !== "POST" || empty($reviewErrors))) {
    $reviewInput["rating"] = (string)$myReview["rating"];
    $reviewInput["review_text"] = (string)$myReview["reviewText"];
}

$canSubmitCurrentSelection = false;
if ($selectedProductId > 0) {
    $canSubmitCurrentSelection = canReviewProduct($conn, $isAdmin, $guestAccess, $loggedUserId, $orderId, $selectedProductId);
}

$defaultRating = max(1, min(5, (int)$reviewInput["rating"]));
$openReviewForm = $canSubmitCurrentSelection && (!empty($reviewErrors) || !$myReview);
$canUseReviewModule = $isAdmin || $actorUserId > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Product Review - <?= htmlspecialchars($systemTitle) ?></title>
    <link rel="stylesheet" href="assets/styling/styles.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/styles.css') ?>">
    <link rel="stylesheet" href="assets/styling/header.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/header.css') ?>">
    <link rel="stylesheet" href="assets/styling/submit_product_review.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/submit_product_review.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/translations.js') ?>" defer></script>
</head>
<body class="site-page"<?= app_translate_page_title_attrs('Submit Product Review - ' . $systemTitle, 'Υποβολή Αξιολόγησης Προϊόντος - ' . $systemTitle) ?>>
<?php
$activePage = "shop";
include __DIR__ . "/include/header.php";
?>
<main class="spr-page">
    <section class="spr-shell">
        <div class="spr-head">
            <h1<?= app_translate_text_attrs('Product Review', 'Αξιολόγηση Προϊόντος') ?>>Product Review</h1>
            <?php if ($selectedProductId > 0): ?>
                <a href="product.php?id=<?= (int)$selectedProductId ?>" class="spr-btn spr-btn-secondary"<?= app_translate_text_attrs('Back to Product', 'Επιστροφή στο Προϊόν') ?>>Back to Product</a>
            <?php endif; ?>
        </div>
        <p class="spr-sub"<?= app_translate_text_attrs('Rate your delivered product with stars (required) and add an optional comment (max 1000 words).', 'Βαθμολογήστε το παραδομένο προϊόν σας με αστέρια (υποχρεωτικό) και προσθέστε προαιρετικά ένα σχόλιο (έως 1000 λέξεις).') ?>>Rate your delivered product with stars (required) and add an optional comment (max 1000 words).</p>

        <?php if ($guestAccess && $orderSummary): ?>
            <p class="spr-note"<?= app_translate_text_attrs('Guest review access enabled for delivered order ' . (string)$orderSummary["orderNumber"] . '.', 'Η πρόσβαση αξιολόγησης επισκέπτη ενεργοποιήθηκε για την παραδομένη παραγγελία ' . (string)$orderSummary["orderNumber"] . '.') ?>>Guest review access enabled for delivered order <?= htmlspecialchars((string)$orderSummary["orderNumber"]) ?>.</p>
        <?php endif; ?>

        <?php if ($statusMessage !== ""): ?>
            <div class="spr-alert success"<?= app_translate_text_attrs($statusMessage, reviewTranslateMessage($statusMessage)) ?>><?= htmlspecialchars($statusMessage) ?></div>
        <?php endif; ?>
        <?php if (!empty($reviewErrors)): ?>
            <?php $reviewErrorMessage = implode(" ", $reviewErrors); ?>
            <div class="spr-alert error"<?= app_translate_text_attrs($reviewErrorMessage, reviewTranslateMessage($reviewErrorMessage)) ?>><?= htmlspecialchars($reviewErrorMessage) ?></div>
        <?php endif; ?>

        <?php if (!$canUseReviewModule): ?>
            <?php $accessDeniedMessage = $guestAccessError !== "" ? $guestAccessError : "Access denied for review submission."; ?>
            <div class="spr-alert error"<?= app_translate_text_attrs($accessDeniedMessage, reviewTranslateMessage($accessDeniedMessage)) ?>>
                <?= htmlspecialchars($accessDeniedMessage) ?>
            </div>
            <a href="shop.php" class="spr-btn spr-btn-primary"<?= app_translate_text_attrs('Go to Shop', 'Μετάβαση στο Shop') ?>>Go to Shop</a>
        <?php elseif (empty($availableProducts) && !$selectedProduct): ?>
            <p class="spr-note"<?= app_translate_text_attrs('No delivered products with confirmed payment are available for review yet.', 'Δεν υπάρχουν ακόμη παραδομένα προϊόντα με επιβεβαιωμένη πληρωμή διαθέσιμα για αξιολόγηση.') ?>>No delivered products with confirmed payment are available for review yet.</p>
            <a href="shop.php" class="spr-btn spr-btn-primary"<?= app_translate_text_attrs('Go to Shop', 'Μετάβαση στο Shop') ?>>Go to Shop</a>
        <?php else: ?>
            <?php if (!empty($availableProducts)): ?>
                <div class="spr-products">
                    <?php foreach ($availableProducts as $item): ?>
                        <?php
                        $pid = (int)$item["productID"];
                        $labelEn = trim((string)($item["nameEN"] ?? ""));
                        $labelEl = trim((string)($item["nameGR"] ?? ""));
                        $label = $labelEn;
                        if ($label === "") {
                            $label = $labelEl;
                        }
                        if ($label === "") {
                            $label = "Product #" . $pid;
                        }
                        if ($labelEn === "") {
                            $labelEn = $label;
                        }
                        if ($labelEl === "") {
                            $labelEl = "Προϊόν #" . $pid;
                        }
                        ?>
                        <a
                            class="spr-product-link <?= $pid === $selectedProductId ? "is-active" : "" ?>"
                            href="<?= htmlspecialchars(buildReviewUrl($orderId, $pid, "", $reviewKey)) ?>">
                            <span data-product-name data-name-en="<?= htmlspecialchars($labelEn) ?>" data-name-el="<?= htmlspecialchars($labelEl) ?>"><?= htmlspecialchars($label) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($selectedProduct): ?>
                <?php
                $selectedNameEn = trim((string)($selectedProduct["nameEN"] ?? ""));
                $selectedNameEl = trim((string)($selectedProduct["nameGR"] ?? ""));
                $selectedName = $selectedNameEn;
                if ($selectedName === "") {
                    $selectedName = $selectedNameEl;
                }
                if ($selectedName === "") {
                    $selectedName = "Product #" . (int)$selectedProduct["productID"];
                }
                if ($selectedNameEn === "") {
                    $selectedNameEn = $selectedName;
                }
                if ($selectedNameEl === "") {
                    $selectedNameEl = "Προϊόν #" . (int)$selectedProduct["productID"];
                }
                $avgRounded = (int)round((float)$selectedProduct["avgRating"]);
                ?>
                <article class="spr-summary">
                    <h2 data-product-name data-name-en="<?= htmlspecialchars($selectedNameEn) ?>" data-name-el="<?= htmlspecialchars($selectedNameEl) ?>"><?= htmlspecialchars($selectedName) ?></h2>
                    <div class="spr-summary-meta">
                        <span class="spr-stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="<?= $i <= $avgRounded ? "fas" : "far" ?> fa-star"></i>
                            <?php endfor; ?>
                        </span>
                        <span<?= app_translate_text_attrs(number_format((float)$selectedProduct["avgRating"], 1) . ' average', number_format((float)$selectedProduct["avgRating"], 1) . ' μέσος όρος') ?>><?= number_format((float)$selectedProduct["avgRating"], 1) ?> average</span>
                        <span<?= app_translate_text_attrs((int)$selectedProduct["reviewCount"] . ' total reviews', (int)$selectedProduct["reviewCount"] . ' συνολικές αξιολογήσεις') ?>><?= (int)$selectedProduct["reviewCount"] ?> total reviews</span>
                    </div>
                </article>
            <?php endif; ?>

            <?php if ($myReview): ?>
                <?php
                $reviewTs = strtotime((string)$myReview["timestamp"]);
                $reviewDateAttr = $reviewTs ? date("c", $reviewTs) : "";
                $reviewDateLabel = $reviewTs ? date("M j, Y", $reviewTs) : (string)$myReview["timestamp"];
                ?>
                <article class="spr-my-review">
                    <div class="spr-my-review-head">
                        <strong<?= app_translate_text_attrs('You', 'Εσείς') ?>>You</strong>
                        <time datetime="<?= htmlspecialchars($reviewDateAttr) ?>">
                            <?= htmlspecialchars($reviewTs ? date("d/m/Y", $reviewTs) : $reviewDateLabel) ?>
                        </time>
                    </div>
                    <div class="spr-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="<?= $i <= (int)$myReview["rating"] ? "fas" : "far" ?> fa-star"></i>
                        <?php endfor; ?>
                    </div>
                    <p<?= $myReview["reviewText"] === "" ? app_translate_text_attrs('No comment provided.', 'Δεν δόθηκε σχόλιο.') : '' ?>><?= nl2br(htmlspecialchars($myReview["reviewText"] !== "" ? $myReview["reviewText"] : "No comment provided.")) ?></p>

                    <?php if ($canSubmitCurrentSelection): ?>
                        <div class="spr-review-actions">
                            <form method="post" class="spr-delete-review-form">
                                <?= app_csrf_input() ?>
                                <input type="hidden" name="review_token" value="<?= htmlspecialchars($reviewToken) ?>">
                                <input type="hidden" name="review_key" value="<?= htmlspecialchars($reviewKey) ?>">
                                <input type="hidden" name="action" value="delete_review">
                                <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
                                <input type="hidden" name="product_id" value="<?= (int)$selectedProductId ?>">
                                <input type="hidden" name="review_id" value="<?= (int)$myReview["reviewID"] ?>">
                                <button type="submit" class="spr-delete-btn"<?= app_translate_title_attrs('Delete review', 'Διαγραφή αξιολόγησης') ?><?= app_translate_aria_attrs('Delete review', 'Διαγραφή αξιολόγησης') ?>>
                                    <i class="fas fa-trash-can"></i>
                                </button>
                            </form>
                            <button type="button" class="spr-edit-btn" id="spr-edit-btn"<?= app_translate_text_attrs('Edit', 'Επεξεργασία') ?>>Edit</button>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endif; ?>

            <?php if ($canSubmitCurrentSelection): ?>
                <form method="post" id="spr-form" class="spr-form <?= $openReviewForm ? "" : "hidden" ?>">
                    <?= app_csrf_input() ?>
                    <input type="hidden" name="review_token" value="<?= htmlspecialchars($reviewToken) ?>">
                    <input type="hidden" name="review_key" value="<?= htmlspecialchars($reviewKey) ?>">
                    <input type="hidden" name="action" value="save_review">
                    <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
                    <input type="hidden" name="product_id" value="<?= (int)$selectedProductId ?>">

                    <label class="spr-label"<?= app_translate_text_attrs('Your rating *', 'Η βαθμολογία σας *') ?>>Your rating *</label>
                    <div class="spr-star-input" id="spr-star-input">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <label class="spr-star <?= $i <= $defaultRating ? "is-on" : "" ?>">
                                <input type="radio" name="rating" value="<?= $i ?>" <?= $i === $defaultRating ? "checked" : "" ?> required>
                                <i class="fas fa-star"></i>
                            </label>
                        <?php endfor; ?>
                    </div>

                    <label class="spr-label" for="spr-review-text"<?= app_translate_text_attrs('Your comment (optional)', 'Το σχόλιό σας (προαιρετικό)') ?>>Your comment (optional)</label>
                    <textarea
                        id="spr-review-text"
                        name="review_text"
                        rows="6"
                        maxlength="7000"
                        placeholder="Write your comment here..."<?= app_translate_placeholder_attrs('Write your comment here...', 'Γράψτε το σχόλιό σας εδώ...') ?>><?= htmlspecialchars($reviewInput["review_text"]) ?></textarea>
                    <div class="spr-word-counter" id="spr-word-counter"<?= app_translate_text_attrs('0 / 1000 words', '0 / 1000 λέξεις') ?>>0 / 1000 words</div>

                    <div class="spr-form-actions">
                        <button type="submit" class="spr-btn spr-btn-primary"<?= app_translate_text_attrs($myReview ? 'Save Changes' : 'Submit Review', $myReview ? 'Αποθήκευση Αλλαγών' : 'Υποβολή Αξιολόγησης') ?>><?= $myReview ? "Save Changes" : "Submit Review" ?></button>
                        <?php if ($myReview): ?>
                            <button type="button" class="spr-btn spr-btn-secondary" id="spr-cancel-btn"<?= app_translate_text_attrs('Cancel', 'Ακύρωση') ?>>Cancel</button>
                        <?php endif; ?>
                    </div>
                </form>
            <?php elseif ($selectedProduct): ?>
                <p class="spr-note"<?= app_translate_text_attrs('This page is view-only. Review becomes available after delivery and confirmed payment.', 'Αυτή η σελίδα είναι μόνο για προβολή. Η αξιολόγηση γίνεται διαθέσιμη μετά την παράδοση και την επιβεβαιωμένη πληρωμή.') ?>>This page is view-only. Review becomes available after delivery and confirmed payment.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<?php include __DIR__ . "/include/footer.php"; ?>

<script>
(function () {
    function currentLang() {
        if (typeof window.appCurrentLanguage === "function") {
            return window.appCurrentLanguage();
        }
        return document.documentElement.lang === "el" ? "el" : "en";
    }

    function translate(key) {
        var messages = {
            en: {
                wordCounterSuffix: "words",
                commentLimit: "Comment must be up to 1000 words.",
                deleteConfirm: "Delete this review?"
            },
            el: {
                wordCounterSuffix: "λέξεις",
                commentLimit: "Το σχόλιο μπορεί να έχει έως 1000 λέξεις.",
                deleteConfirm: "Να διαγραφεί αυτή η αξιολόγηση;"
            }
        };
        var lang = currentLang();
        return (messages[lang] && messages[lang][key]) || messages.en[key] || "";
    }

    function countWords(text) {
        var clean = String(text || "").trim();
        if (!clean) {
            return 0;
        }
        var parts = clean.split(/\s+/).filter(Boolean);
        return parts.length;
    }

    var form = document.getElementById("spr-form");
    var deleteForms = Array.prototype.slice.call(document.querySelectorAll(".spr-delete-review-form"));
    var editBtn = document.getElementById("spr-edit-btn");
    var cancelBtn = document.getElementById("spr-cancel-btn");
    if (editBtn && form) {
        editBtn.addEventListener("click", function () {
            form.classList.remove("hidden");
            form.scrollIntoView({ behavior: "smooth", block: "center" });
        });
    }
    if (cancelBtn && form) {
        cancelBtn.addEventListener("click", function () {
            form.classList.add("hidden");
        });
    }

    deleteForms.forEach(function (deleteForm) {
        deleteForm.addEventListener("submit", function (event) {
            if (!window.confirm(translate("deleteConfirm"))) {
                event.preventDefault();
            }
        });
    });

    var ratingInputs = Array.prototype.slice.call(document.querySelectorAll(".spr-star-input input[type='radio']"));
    function paintStars() {
        if (!ratingInputs.length) {
            return;
        }
        var selected = ratingInputs.find(function (input) { return input.checked; });
        var selectedValue = selected ? Number(selected.value) : 0;
        ratingInputs.forEach(function (input) {
            var label = input.closest(".spr-star");
            if (!label) {
                return;
            }
            label.classList.toggle("is-on", Number(input.value) <= selectedValue);
        });
    }
    ratingInputs.forEach(function (input) {
        input.addEventListener("change", paintStars);
    });
    paintStars();

    var textarea = document.getElementById("spr-review-text");
    var counter = document.getElementById("spr-word-counter");
    function updateCounter() {
        if (!textarea || !counter) {
            return;
        }
        var words = countWords(textarea.value);
        counter.textContent = words + " / 1000 " + translate("wordCounterSuffix");
        counter.classList.toggle("over", words > 1000);
    }
    if (textarea) {
        textarea.addEventListener("input", updateCounter);
    }
    updateCounter();

    if (form) {
        form.addEventListener("submit", function (e) {
            var text = textarea ? textarea.value : "";
            var words = countWords(text);
            if (words > 1000) {
                e.preventDefault();
                alert(translate("commentLimit"));
            }
        });
    }

    document.addEventListener("app:languagechange", updateCounter);
})();
</script>
</body>
</html>
