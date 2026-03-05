<?php
session_start();
require_once "authentication/database.php";

header("Content-Type: application/json; charset=utf-8");

$response = [
    "success" => false,
    "message" => "Invalid request.",
    "productKey" => null,
    "productId" => null,
    "inWishlist" => false,
    "wishlistCount" => 0,
];

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode($response);
    exit();
}

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

// Key-based wishlist (homepage cards)
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

// ID-based wishlist (shop cards)
if ($productId > 0 && $action === "toggle_wishlist_item") {
    $response["productId"] = $productId;
    $inWishlist = false;
    $wishlistCount = 0;

    if ($userId > 0) {
        $wid = 0;
        $res = $conn->query("SELECT wishlistID FROM wishlists WHERE userID = $userId LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            $wid = (int)$row["wishlistID"];
        } else {
            $conn->query("INSERT INTO wishlists (userID) VALUES ($userId)");
            $wid = (int)$conn->insert_id;
        }

        $check = $conn->query("SELECT wishlistItemID FROM wishlist_items WHERE wishlistID = $wid AND productID = $productId LIMIT 1");
        if ($check && $check->num_rows > 0) {
            $itemId = (int)$check->fetch_assoc()["wishlistItemID"];
            $conn->query("DELETE FROM wishlist_items WHERE wishlistItemID = $itemId");
            $inWishlist = false;
            $response["message"] = "Item removed from your wishlist.";
        } else {
            $conn->query("INSERT INTO wishlist_items (wishlistID, productID) VALUES ($wid, $productId)");
            $inWishlist = true;
            $response["message"] = "Item added to your wishlist.";
        }

        $countRes = $conn->query("SELECT COUNT(*) AS c FROM wishlist_items WHERE wishlistID = $wid");
        $wishlistCount = ($countRes && ($cRow = $countRes->fetch_assoc())) ? (int)$cRow["c"] : 0;
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
