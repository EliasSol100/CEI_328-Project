<?php
session_start();
require_once "authentication/database.php";
require_once "include/security.php";

header("Content-Type: application/json; charset=utf-8");

$response = [
    "success" => false,
    "message" => "Invalid request.",
    "productKey" => null,
    "productId" => null,
    "inWishlist" => false,
    "wishlistCount" => 0,
];

function wishlistToggleGetOrCreateWishlistId(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare("SELECT wishlistID FROM wishlists WHERE userID = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            return (int)$row["wishlistID"];
        }
    }

    $stmt = $conn->prepare("INSERT INTO wishlists (userID) VALUES (?)");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        return $newId;
    }

    return 0;
}

function wishlistToggleCountItems(mysqli $conn, int $wishlistId): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM wishlist_items WHERE wishlistID = ?");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param("i", $wishlistId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return (int)($row["c"] ?? 0);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode($response);
    exit();
}

app_require_csrf(true, "Invalid CSRF token.");

$action = $_POST["action"] ?? "";
$productKey = $_POST["product_key"] ?? "";
$productId = (int)($_POST["product_id"] ?? 0);
$userId = (int)($_SESSION["user"]["id"] ?? 0);

$catalogProducts = [
    "flame_dragon" => true,
    "electric_mouse" => true,
    "lilac_turtle" => true,
    "daisy_bunny" => true,
];

if (!isset($_SESSION["wishlist"]) || !is_array($_SESSION["wishlist"])) {
    $_SESSION["wishlist"] = [];
}

if ($productKey !== "") {
    $response["productKey"] = $productKey;

    if (!isset($catalogProducts[$productKey])) {
        $response["message"] = "Product not found.";
        $response["wishlistCount"] = count($_SESSION["wishlist"]);
        $_SESSION["wishlist_count"] = $response["wishlistCount"];
        echo json_encode($response);
        exit();
    }

    if ($action === "add_wishlist_item") {
        if (!in_array($productKey, $_SESSION["wishlist"], true)) {
            $_SESSION["wishlist"][] = $productKey;
            $response["message"] = "Item added to your wishlist.";
        } else {
            $response["message"] = "This item is already in your wishlist.";
        }
        $response["success"] = true;
        $response["inWishlist"] = true;
    } elseif ($action === "remove_wishlist_item") {
        $_SESSION["wishlist"] = array_values(array_filter(
            $_SESSION["wishlist"],
            fn($key) => $key !== $productKey
        ));
        $response["success"] = true;
        $response["inWishlist"] = false;
        $response["message"] = "Item removed from your wishlist.";
    } else {
        $response["message"] = "Unknown action.";
    }

    $response["wishlistCount"] = count($_SESSION["wishlist"]);
    $_SESSION["wishlist_count"] = $response["wishlistCount"];
    echo json_encode($response);
    exit();
}

if ($productId > 0 && $action === "toggle_wishlist_item") {
    $response["productId"] = $productId;
    $inWishlist = false;
    $wishlistCount = 0;

    if ($userId > 0) {
        $wid = wishlistToggleGetOrCreateWishlistId($conn, $userId);
        if ($wid <= 0) {
            $response["message"] = "Could not update wishlist.";
            echo json_encode($response);
            exit();
        }

        $itemId = 0;
        $check = $conn->prepare("SELECT wishlistItemID FROM wishlist_items WHERE wishlistID = ? AND productID = ? LIMIT 1");
        if ($check) {
            $check->bind_param("ii", $wid, $productId);
            $check->execute();
            $checkRes = $check->get_result();
            $checkRow = $checkRes ? $checkRes->fetch_assoc() : null;
            $itemId = (int)($checkRow["wishlistItemID"] ?? 0);
            $check->close();
        }

        if ($itemId > 0) {
            $deleteStmt = $conn->prepare("DELETE FROM wishlist_items WHERE wishlistItemID = ?");
            if ($deleteStmt) {
                $deleteStmt->bind_param("i", $itemId);
                $deleteStmt->execute();
                $deleteStmt->close();
            }
            $inWishlist = false;
            $response["message"] = "Item removed from your wishlist.";
        } else {
            $insertStmt = $conn->prepare("INSERT INTO wishlist_items (wishlistID, productID) VALUES (?, ?)");
            if ($insertStmt) {
                $insertStmt->bind_param("ii", $wid, $productId);
                $insertStmt->execute();
                $insertStmt->close();
            }
            $inWishlist = true;
            $response["message"] = "Item added to your wishlist.";
        }

        $wishlistCount = wishlistToggleCountItems($conn, $wid);
    } else {
        $idx = array_search($productId, $_SESSION["wishlist"], true);
        if ($idx !== false) {
            array_splice($_SESSION["wishlist"], $idx, 1);
            $inWishlist = false;
            $response["message"] = "Item removed from your wishlist.";
        } else {
            $_SESSION["wishlist"][] = $productId;
            $inWishlist = true;
            $response["message"] = "Item added to your wishlist.";
        }
        $wishlistCount = count($_SESSION["wishlist"]);
    }

    $response["success"] = true;
    $response["inWishlist"] = $inWishlist;
    $response["wishlistCount"] = $wishlistCount;
    $_SESSION["wishlist_count"] = $wishlistCount;
    echo json_encode($response);
    exit();
}

$response["message"] = "Missing product data.";
echo json_encode($response);
