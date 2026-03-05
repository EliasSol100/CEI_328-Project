<?php
session_start();

header("Content-Type: application/json; charset=utf-8");

$catalogProducts = [
    'flame_dragon' => true,
    'electric_mouse' => true,
    'lilac_turtle' => true,
    'daisy_bunny' => true,
];

$response = [
    "success" => false,
    "message" => "Invalid request.",
    "productKey" => null,
    "inWishlist" => false,
    "wishlistCount" => 0,
];

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode($response);
    exit();
}

$action = $_POST["action"] ?? "";
$productKey = $_POST["product_key"] ?? "";
$response["productKey"] = $productKey;

if (!isset($_SESSION["wishlist"]) || !is_array($_SESSION["wishlist"])) {
    $_SESSION["wishlist"] = [];
}

if (!$productKey || !isset($catalogProducts[$productKey])) {
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
