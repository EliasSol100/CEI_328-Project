<?php
session_start();
require_once "authentication/database.php";

$userId = $_SESSION["user"]["id"] ?? null;
$fullName = $_SESSION["user"]["full_name"] ?? "Guest";
$role = $_SESSION["user"]["role"] ?? "guest";

$GLOBALS['header_user_full_name'] = $fullName;
$GLOBALS['header_user_role'] = $role;

$sessionCatalog = [
    'flame_dragon' => ['name' => 'Flame Dragon Amigurumi Plush', 'price' => 38, 'image' => 'assets/images/products/flame-dragon-plush.jpg'],
    'electric_mouse' => ['name' => 'Electric Mouse Buddy Plush', 'price' => 34, 'image' => 'assets/images/products/electric-mouse-plush.jpg'],
    'lilac_turtle' => ['name' => 'Lilac Sea Turtle Plush', 'price' => 40, 'image' => 'assets/images/products/lilac-sea-turtle-plush.jpg'],
    'daisy_bunny' => ['name' => 'Daisy Dress Bunny Plush', 'price' => 42, 'image' => 'assets/images/products/bunny-pink-hat-plush.jpg'],
];

function getOrCreateWishlistID(mysqli $conn, int $uid): int {
    $res = $conn->query("SELECT wishlistID FROM wishlists WHERE userID = $uid LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        return (int)$row["wishlistID"];
    }
    $conn->query("INSERT INTO wishlists (userID) VALUES ($uid)");
    return (int)$conn->insert_id;
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "remove_wishlist_key") {
        $key = $_POST["product_key"] ?? "";
        if (isset($_SESSION["wishlist"]) && is_array($_SESSION["wishlist"])) {
            $_SESSION["wishlist"] = array_values(array_filter(
                $_SESSION["wishlist"],
                fn($v) => $v !== $key
            ));
            $message = "Item removed from wishlist.";
        }
    }

    if ($action === "remove_wishlist_pid" && $userId) {
        $pid = (int)($_POST["product_id"] ?? 0);
        if ($pid > 0) {
            $wid = getOrCreateWishlistID($conn, (int)$userId);
            $conn->query("DELETE FROM wishlist_items WHERE wishlistID = $wid AND productID = $pid");
            $message = "Item removed from wishlist.";
        }
    }

    // Sync header counter after any change.
    $sessionCount = isset($_SESSION["wishlist"]) && is_array($_SESSION["wishlist"])
        ? count($_SESSION["wishlist"])
        : 0;
    $dbCount = 0;
    if ($userId) {
        $wid = getOrCreateWishlistID($conn, (int)$userId);
        $countRes = $conn->query("SELECT COUNT(*) AS c FROM wishlist_items WHERE wishlistID = $wid");
        if ($countRes && ($cRow = $countRes->fetch_assoc())) {
            $dbCount = (int)$cRow["c"];
        }
    }
    $_SESSION["wishlist_count"] = $sessionCount + $dbCount;

    header("Location: wishlist.php");
    exit();
}

$sessionItems = [];
$sessionKeys = isset($_SESSION["wishlist"]) && is_array($_SESSION["wishlist"]) ? array_unique($_SESSION["wishlist"]) : [];
foreach ($sessionKeys as $key) {
    if (isset($sessionCatalog[$key])) {
        $sessionItems[] = [
            "key" => $key,
            "name" => $sessionCatalog[$key]["name"],
            "price" => $sessionCatalog[$key]["price"],
            "image" => $sessionCatalog[$key]["image"] ?? "",
        ];
    }
}

$dbItems = [];
if ($userId) {
    $wid = getOrCreateWishlistID($conn, (int)$userId);
    $res = $conn->query("
        SELECT p.productID, p.nameEN, p.basePrice, MIN(ph.imageID) AS imageID
        FROM wishlist_items wi
        JOIN products p ON p.productID = wi.productID
        LEFT JOIN photos ph ON ph.productID = p.productID
        WHERE wi.wishlistID = $wid
        GROUP BY p.productID, p.nameEN, p.basePrice
        ORDER BY wi.addedAt DESC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $dbItems[] = [
                "productID" => (int)$row["productID"],
                "name" => $row["nameEN"],
                "price" => (float)$row["basePrice"],
                "imageID" => isset($row["imageID"]) ? (int)$row["imageID"] : 0,
            ];
        }
    }
}

$_SESSION["wishlist_count"] = count($sessionItems) + count($dbItems);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Wishlist</title>
    <link rel="stylesheet" href="assets/styling/styles.css">
    <link rel="stylesheet" href="assets/styling/header.css?v=5">
    <link rel="stylesheet" href="assets/styling/wishlist.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="site-page">
    <?php
    $activePage = 'wishlist';
    include __DIR__ . '/include/header.php';
    ?>

    <main class="wishlist-page">
        <div class="wishlist-wrap">
            <div class="wishlist-head">
                <h1>My Wishlist</h1>
                <p>All your favorites in one place.</p>
            </div>

            <?php if ($message): ?>
                <div class="wishlist-msg"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if (empty($sessionItems) && empty($dbItems)): ?>
                <p class="wishlist-empty">Your wishlist is empty.</p>
            <?php else: ?>
                <ul class="wishlist-list">
                                        <?php foreach ($sessionItems as $item): ?>
                        <li>
                            <div class="wishlist-item-main">
                                <img class="wishlist-thumb"
                                     src="<?= htmlspecialchars($item["image"]) ?>"
                                     alt="<?= htmlspecialchars($item["name"]) ?>">
                                <div class="wishlist-item-info">
                                    <strong><?= htmlspecialchars($item["name"]) ?></strong>
                                    <span>&euro;<?= number_format((float)$item["price"], 0) ?></span>
                                </div>
                            </div>
                            <form method="post" action="wishlist.php">
                                <input type="hidden" name="action" value="remove_wishlist_key">
                                <input type="hidden" name="product_key" value="<?= htmlspecialchars($item["key"]) ?>">
                                <button type="submit" aria-label="Remove item"><i class="fas fa-trash"></i></button>
                            </form>
                        </li>
                    <?php endforeach; ?>

                    <?php foreach ($dbItems as $item): ?>
                        <li>
                            <div class="wishlist-item-main">
                                <?php if (!empty($item["imageID"])): ?>
                                    <img class="wishlist-thumb"
                                         src="modules/admin/ajax/product_image.php?id=<?= (int)$item["imageID"] ?>"
                                         alt="<?= htmlspecialchars($item["name"]) ?>">
                                <?php else: ?>
                                    <div class="wishlist-thumb placeholder"><i class="fas fa-image"></i></div>
                                <?php endif; ?>
                                <div class="wishlist-item-info">
                                    <strong><?= htmlspecialchars($item["name"]) ?></strong>
                                    <span>&euro;<?= number_format((float)$item["price"], 0) ?></span>
                                </div>
                            </div>
                            <form method="post" action="wishlist.php">
                                <input type="hidden" name="action" value="remove_wishlist_pid">
                                <input type="hidden" name="product_id" value="<?= (int)$item["productID"] ?>">
                                <button type="submit" aria-label="Remove item"><i class="fas fa-trash"></i></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </main>

    <?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>

