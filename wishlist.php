<?php
session_start();
require_once "authentication/database.php";
require_once "include/security.php";
require_once "include/translation_helpers.php";

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
    $stmt = $conn->prepare("SELECT wishlistID FROM wishlists WHERE userID = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $uid);
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
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        return $newId;
    }

    return 0;
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    app_require_csrf(false, "Invalid request token. Please refresh and try again.");
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
            $stmt = $conn->prepare("DELETE FROM wishlist_items WHERE wishlistID = ? AND productID = ?");
            if ($stmt) {
                $stmt->bind_param("ii", $wid, $pid);
                $stmt->execute();
                $stmt->close();
            }
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
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM wishlist_items WHERE wishlistID = ?");
        if ($stmt) {
            $stmt->bind_param("i", $wid);
            $stmt->execute();
            $countRes = $stmt->get_result();
            $cRow = $countRes ? $countRes->fetch_assoc() : null;
            $dbCount = (int)($cRow["c"] ?? 0);
            $stmt->close();
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
    $stmt = $conn->prepare("
        SELECT p.productID, p.nameEN, p.basePrice, MIN(ph.imageID) AS imageID
        FROM wishlist_items wi
        JOIN products p ON p.productID = wi.productID
        LEFT JOIN photos ph ON ph.productID = p.productID
        WHERE wi.wishlistID = ?
        GROUP BY p.productID, p.nameEN, p.basePrice
        ORDER BY wi.addedAt DESC
    ");
    if ($stmt) {
        $stmt->bind_param("i", $wid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $dbItems[] = [
                "productID" => (int)$row["productID"],
                "name" => $row["nameEN"],
                "price" => (float)$row["basePrice"],
                "imageID" => isset($row["imageID"]) ? (int)$row["imageID"] : 0,
            ];
        }
        $stmt->close();
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
    <script src="assets/js/translations.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="site-page"<?= app_translate_page_title_attrs('My Wishlist - Athina E-Shop', 'Η Λίστα Επιθυμιών μου - Athina E-Shop') ?>>
    <?php
    $activePage = 'wishlist';
    include __DIR__ . '/include/header.php';
    ?>

    <main class="wishlist-page">
        <div class="wishlist-wrap">
            <div class="wishlist-head">
                <h1 data-translate="wishlistTitle">My Wishlist</h1>
                <p data-translate="wishlistSubtitle">All your favorites in one place.</p>
            </div>

            <?php if ($message): ?>
                <div class="wishlist-msg"<?= $message === 'Item removed from wishlist.' ? app_translate_text_attrs('Item removed from wishlist.', 'Το προϊόν αφαιρέθηκε από τη λίστα επιθυμιών.') : '' ?>><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if (empty($sessionItems) && empty($dbItems)): ?>
                <p class="wishlist-empty" data-translate="wishlistPageEmpty">Your wishlist is empty.</p>
            <?php else: ?>
                <ul class="wishlist-list">
                                        <?php foreach ($sessionItems as $item): ?>
                        <li>
                            <div class="wishlist-item-main">
                                <img class="wishlist-thumb"
                                     src="<?= htmlspecialchars($item["image"]) ?>"
                                     alt="<?= htmlspecialchars($item["name"]) ?>">
                                <div class="wishlist-item-info">
                                    <strong<?= app_translate_text_attrs((string)$item["name"], (string)$item["name"]) ?>><?= htmlspecialchars($item["name"]) ?></strong>
                                    <span>&euro;<?= number_format((float)$item["price"], 0) ?></span>
                                </div>
                            </div>
                            <form method="post" action="wishlist.php">
                                <?= app_csrf_input() ?>
                                <input type="hidden" name="action" value="remove_wishlist_key">
                                <input type="hidden" name="product_key" value="<?= htmlspecialchars($item["key"]) ?>">
                                <button type="submit" aria-label="Remove item"<?= app_translate_aria_attrs('Remove item', 'Αφαίρεση προϊόντος') ?>><i class="fas fa-trash"></i></button>
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
                                    <strong<?= app_translate_text_attrs((string)$item["name"], (string)$item["name"]) ?>><?= htmlspecialchars($item["name"]) ?></strong>
                                    <span>&euro;<?= number_format((float)$item["price"], 0) ?></span>
                                </div>
                            </div>
                            <form method="post" action="wishlist.php">
                                <?= app_csrf_input() ?>
                                <input type="hidden" name="action" value="remove_wishlist_pid">
                                <input type="hidden" name="product_id" value="<?= (int)$item["productID"] ?>">
                                <button type="submit" aria-label="Remove item"<?= app_translate_aria_attrs('Remove item', 'Αφαίρεση προϊόντος') ?>><i class="fas fa-trash"></i></button>
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

