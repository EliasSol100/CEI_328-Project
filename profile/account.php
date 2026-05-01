<?php
session_start();

require_once "../authentication/database.php";
require_once "../include/security.php";
require_once "../include/image_storage.php";
require_once "../include/loyalty_program.php";
require_once "../include/translation_helpers.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../PHPMailer-master/src/Exception.php';
require '../PHPMailer-master/src/PHPMailer.php';
require '../PHPMailer-master/src/SMTP.php';

$userId = null;

if (isset($_SESSION["user"]) && is_array($_SESSION["user"])) {
    if (isset($_SESSION["user"]["id"])) {
        $userId = (int) $_SESSION["user"]["id"];
    } elseif (isset($_SESSION["user"]["userID"])) {
        $userId = (int) $_SESSION["user"]["userID"];
    }
}

if ($userId === null && isset($_SESSION["user_id"])) {
    $userId = (int) $_SESSION["user_id"];
}

if ($userId === null || $userId <= 0) {
    header("Location: ../authentication/login.php");
    exit();
}

$stmt = $conn->prepare("SELECT *, userID AS id FROM users WHERE userID = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user   = $result->fetch_assoc();
$stmt->close();

if (!$user) {

    session_unset();
    session_destroy();
    echo "User not found.";
    exit();
}

if (!isset($_SESSION["user"])) {
    $_SESSION["user"] = [];
}
$_SESSION["user"]["id"]        = $user["id"];
$_SESSION["user_id"]           = $user["id"];
$_SESSION["user"]["email"]     = $user["email"];
$_SESSION["user"]["full_name"] = $user["full_name"];

$fullName = $user["full_name"] ?? "User";
$role     = $_SESSION["user"]["role"] ?? "user";

$initials = null;
$parts = preg_split('/\s+/', trim($fullName));
if (!empty($parts)) {
    $first = strtoupper(substr($parts[0], 0, 1));
    $last  = (count($parts) > 1) ? strtoupper(substr(end($parts), 0, 1)) : "";
    $initials = $first . $last;
}

$GLOBALS['header_user_full_name'] = $fullName;
$GLOBALS['header_user_role']      = $role;
$GLOBALS['header_user_initials']  = $initials;

$activeTab = $_GET["tab"] ?? "orders";
$allowedTabs = ["orders", "loyalty", "addresses", "settings"];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = "orders";
}

function formatDateTime(?string $value): string {
    if (!$value) return "-";
    $ts = strtotime($value);
    if (!$ts) return $value;
    return date("d/m/Y H:i", $ts);
}

function formatLoyaltyRuleLabel(?string $rule): string {
    $rule = strtolower(trim((string)$rule));
    if ($rule === "earn_purchase") {
        return "Points earned from purchase";
    }
    if ($rule === "redeem_checkout") {
        return "Points redeemed at checkout";
    }
    if ($rule === "") {
        return "Loyalty activity";
    }
    return ucwords(str_replace("_", " ", $rule));
}

function &accountGetOrInitCart(): array {
    if (!isset($_SESSION["cart"]) || !is_array($_SESSION["cart"])) {
        $_SESSION["cart"] = [
            "items" => [],
            "totals" => [
                "items_count"  => 0,
                "subtotal"     => 0.0,
                "addons_total" => 0.0,
                "grand_total"  => 0.0,
            ],
            "created_at" => gmdate("c"),
            "updated_at" => gmdate("c"),
        ];
    }
    return $_SESSION["cart"];
}

function accountFindExistingLineIndex(array $items, int $productId, ?int $variationId, array $addons): ?int {
    foreach ($items as $index => $item) {
        if ((int)($item["product"]["id"] ?? 0) !== $productId) {
            continue;
        }
        $existingVariationId = isset($item["variation"]["variationID"]) ? (int)$item["variation"]["variationID"] : null;
        if ($existingVariationId !== $variationId) {
            continue;
        }

        $lineAddons = $item["addons"] ?? [];
        if ((bool)($lineAddons["giftWrapping"] ?? false) !== (bool)$addons["gift_wrapping"]) {
            continue;
        }
        if ((bool)($lineAddons["giftBagFlag"] ?? false) !== (bool)$addons["gift_bag"]) {
            continue;
        }
        if ((string)($lineAddons["giftMessage"] ?? "") !== (string)$addons["message"]) {
            continue;
        }

        return (int)$index;
    }

    return null;
}

function accountRecalcCartTotals(array $items): array {
    $itemsCount = 0;
    $subtotal = 0.0;
    $addonsTotal = 0.0;

    foreach ($items as $item) {
        $quantity = (int)($item["quantity"] ?? 0);
        $itemsCount += $quantity;
        $subtotal += (float)($item["product"]["basePrice"] ?? 0.0) * $quantity;
        $addonsTotal += (float)($item["addons"]["addonsCost"] ?? 0.0) * $quantity;
    }

    return [
        "items_count"  => $itemsCount,
        "subtotal"     => round($subtotal, 2),
        "addons_total" => round($addonsTotal, 2),
        "grand_total"  => round($subtotal + $addonsTotal, 2),
    ];
}

function accountResetDefaultAddresses(mysqli $conn, int $userId): void {
    $stmt = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
    }
}

$successMessage = "";
$errorMessage   = "";

if (empty($_SESSION["account_reorder_token"])) {
    $_SESSION["account_reorder_token"] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    app_require_csrf(false, "Invalid request token. Please refresh the page and try again.");
    $action = $_POST["action"] ?? "";

    if ($action === "reorder_order") {
        $activeTab = "orders";
        $orderId = (int)($_POST["order_id"] ?? 0);
        $token = $_POST["reorder_token"] ?? "";

        if (!hash_equals($_SESSION["account_reorder_token"], (string)$token)) {
            $errorMessage = "Invalid request token. Please refresh the page and try again.";
        } elseif ($orderId <= 0) {
            $errorMessage = "Invalid order selected for reorder.";
        } else {
            $ownerStmt = $conn->prepare("SELECT orderID FROM orders WHERE orderID = ? AND userID = ? LIMIT 1");
            if (!$ownerStmt) {
                $errorMessage = "Could not validate the selected order.";
            } else {
                $ownerStmt->bind_param("ii", $orderId, $userId);
                $ownerStmt->execute();
                $orderExists = $ownerStmt->get_result()->fetch_assoc();
                $ownerStmt->close();

                if (!$orderExists) {
                    $errorMessage = "Order not found or access denied.";
                } else {
                    $itemsSql = "
                        SELECT
                            oi.productID,
                            oi.variationID,
                            oi.quantity,
                            oi.unitPrice,
                            oi.giftWrapping,
                            oi.giftBagFlag,
                            oi.giftMessage,
                            p.sku,
                            p.nameGR,
                            p.nameEN,
                            p.basePrice,
                            p.cartStatus,
                            p.hasVariants,
                            p.inventory,
                            pv.size,
                            pv.yarnType,
                            pv.colorID,
                            c.colorName,
                            vs.quantityAvailable
                        FROM order_items oi
                        INNER JOIN orders o ON o.orderID = oi.orderID
                        LEFT JOIN products p ON p.productID = oi.productID
                        LEFT JOIN product_variations pv ON pv.variationID = oi.variationID
                        LEFT JOIN colors c ON c.colorID = pv.colorID
                        LEFT JOIN variation_stock vs ON vs.variationID = oi.variationID
                        WHERE o.orderID = ? AND o.userID = ?
                        ORDER BY oi.orderItemID ASC
                    ";

                    $itemsStmt = $conn->prepare($itemsSql);
                    if (!$itemsStmt) {
                        $errorMessage = "Could not load order items for reorder.";
                    } else {
                        $itemsStmt->bind_param("ii", $orderId, $userId);
                        $itemsStmt->execute();
                        $itemsRes = $itemsStmt->get_result();

                        $rows = [];
                        while ($row = $itemsRes->fetch_assoc()) {
                            $rows[] = $row;
                        }
                        $itemsStmt->close();

                        if (empty($rows)) {
                            $errorMessage = "This order has no items to reorder.";
                        } else {
                            $cart = &accountGetOrInitCart();
                            $addedLineCount = 0;
                            $skippedCount = 0;

                            foreach ($rows as $row) {
                                $productId = (int)($row["productID"] ?? 0);
                                $variationId = isset($row["variationID"]) ? (int)$row["variationID"] : null;
                                if ($variationId !== null && $variationId <= 0) {
                                    $variationId = null;
                                }

                                $cartStatus = (string)($row["cartStatus"] ?? "");
                                if (
                                    $productId <= 0
                                    || ($cartStatus !== "active" && $cartStatus !== "made_to_order" && $cartStatus !== "low_stock")
                                ) {
                                    $skippedCount++;
                                    continue;
                                }

                                $requestedQty = max(1, (int)($row["quantity"] ?? 1));
                                $hasVariants = ((int)($row["hasVariants"] ?? 0) === 1);

                                $addonsPayload = [
                                    "gift_wrapping" => ((int)($row["giftWrapping"] ?? 0) === 1),
                                    "gift_bag"      => ((int)($row["giftBagFlag"] ?? 0) === 1),
                                    "message"       => trim((string)($row["giftMessage"] ?? "")),
                                ];

                                $existingIndex = accountFindExistingLineIndex(
                                    $cart["items"],
                                    $productId,
                                    $variationId,
                                    $addonsPayload
                                );
                                $existingQty = $existingIndex === null
                                    ? 0
                                    : (int)($cart["items"][$existingIndex]["quantity"] ?? 0);

                                $targetQty = $existingQty + $requestedQty;
                                if ($cartStatus !== "made_to_order") {
                                    if ($hasVariants && $variationId !== null) {
                                        $availableStock = (int)($row["quantityAvailable"] ?? 0);
                                    } else {
                                        $availableStock = (int)($row["inventory"] ?? 0);
                                    }

                                    if ($availableStock <= 0) {
                                        $skippedCount++;
                                        continue;
                                    }

                                    if ($targetQty > $availableStock) {
                                        $targetQty = $availableStock;
                                    }
                                }

                                if ($targetQty <= $existingQty) {
                                    $skippedCount++;
                                    continue;
                                }

                                $unitPrice = (float)($row["basePrice"] ?? 0);
                                if ($unitPrice <= 0) {
                                    $unitPrice = (float)($row["unitPrice"] ?? 0);
                                }

                                $lineItem = [
                                    "product" => [
                                        "id"         => $productId,
                                        "sku"        => (string)($row["sku"] ?? ""),
                                        "nameGR"     => (string)($row["nameGR"] ?? ""),
                                        "nameEN"     => (string)($row["nameEN"] ?? ""),
                                        "basePrice"  => round($unitPrice, 2),
                                        "cartStatus" => $cartStatus,
                                        "hasVariants"=> $hasVariants,
                                    ],
                                    "variation" => $variationId === null ? null : [
                                        "variationID" => $variationId,
                                        "size"        => (string)($row["size"] ?? ""),
                                        "yarnType"    => (string)($row["yarnType"] ?? ""),
                                        "colorID"     => (int)($row["colorID"] ?? 0),
                                        "colorName"   => (string)($row["colorName"] ?? ""),
                                    ],
                                    "quantity" => $targetQty,
                                    "addons" => [
                                        "giftWrapping" => $addonsPayload["gift_wrapping"],
                                        "giftBagFlag"  => $addonsPayload["gift_bag"],
                                        "giftMessage"  => $addonsPayload["message"],
                                        "addonsCost"   => 0.0,
                                    ],
                                    "pricing" => [
                                        "unitTotal" => round($unitPrice, 2),
                                        "lineTotal" => round($unitPrice * $targetQty, 2),
                                    ],
                                    "updated_at" => gmdate("c"),
                                ];

                                if ($existingIndex === null) {
                                    $cart["items"][] = $lineItem;
                                } else {
                                    $cart["items"][$existingIndex] = $lineItem;
                                }

                                $addedLineCount++;
                            }

                            if ($addedLineCount > 0) {
                                $cart["totals"] = accountRecalcCartTotals($cart["items"]);
                                $cart["updated_at"] = gmdate("c");
                                $successMessage = "Reorder added to your cart.";
                                if ($skippedCount > 0) {
                                    $successMessage .= " {$skippedCount} item(s) were skipped due to availability changes.";
                                }
                            } else {
                                $errorMessage = "No items could be reordered. Items may no longer be available.";
                            }
                        }
                    }
                }
            }
        }
    }

    if ($action === "update_avatar") {
        if (!empty($_FILES["profile_image"]["name"])) {
            $file     = $_FILES["profile_image"];
            $maxSize  = 2 * 1024 * 1024;

            if ($file["error"] !== UPLOAD_ERR_OK) {
                $errorMessage = "There was a problem uploading your image.";
            } elseif ($file["size"] > $maxSize) {
                $errorMessage = "Image must be smaller than 2MB.";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = (string)($finfo->file((string)$file["tmp_name"]) ?: "");
                if (!app_allowed_image_mime($mimeType)) {
                    $errorMessage = "Only JPG, JPEG, PNG, GIF or WEBP files are allowed.";
                } else {
                    $uploadDir = __DIR__ . "/../uploads/avatars";
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }

                    $filename   = "user_" . $userId . "_" . time() . ".webp";
                    $targetPath = $uploadDir . "/" . $filename;

                    if (app_image_convert_file_to_webp((string)$file["tmp_name"], $targetPath, 800, 800, 82)) {

                        if (!empty($user["profile_image"])) {
                            $oldPath = $uploadDir . "/" . $user["profile_image"];
                            if (is_file($oldPath)) {
                                @unlink($oldPath);
                            }
                        }

                        $stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE userID = ?");
                        $stmt->bind_param("si", $filename, $userId);
                        $stmt->execute();
                        $stmt->close();

                        $successMessage = "Profile picture updated.";
                        $user["profile_image"] = $filename;
                    } else {
                        $errorMessage = "Could not convert and save uploaded file.";
                    }
                }
            }
        } else {
            $errorMessage = "Please choose an image to upload.";
        }
    }

    if ($action === "add_address") {
        $label       = trim($_POST["address_label"] ?? "");
        $country     = trim($_POST["country"]  ?? "");
        $city        = trim($_POST["city"]     ?? "");
        $address     = trim($_POST["address"]  ?? "");
        $postcode    = trim($_POST["postcode"] ?? "");
        $makeDefault = isset($_POST["make_default"]) ? 1 : 0;

        if ($country && $city && $address && $postcode) {
            if ($makeDefault) {
                accountResetDefaultAddresses($conn, $userId);
            }

            $stmt = $conn->prepare("
                INSERT INTO user_addresses (user_id, label, country, city, address, postcode, is_default)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isssssi", $userId, $label, $country, $city, $address, $postcode, $makeDefault);
            $stmt->execute();
            $stmt->close();

            if ($makeDefault) {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET country = ?, city = ?, address = ?, postcode = ?
                    WHERE userID = ?
                ");
                $stmt->bind_param("ssssi", $country, $city, $address, $postcode, $userId);
                $stmt->execute();
                $stmt->close();

                $user["country"]  = $country;
                $user["city"]     = $city;
                $user["address"]  = $address;
                $user["postcode"] = $postcode;
            }

            $successMessage = "Address added successfully.";
        } else {
            $errorMessage = "Please fill in all address fields.";
        }

        $activeTab = "addresses";
    }

    if ($action === "set_default_address") {
        $addrId = (int) ($_POST["address_id"] ?? 0);

        $stmt = $conn->prepare("SELECT * FROM user_addresses WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $addrId, $userId);
        $stmt->execute();
        $addrRes = $stmt->get_result();
        $addressRow = $addrRes->fetch_assoc();
        $stmt->close();

        if ($addressRow) {
            accountResetDefaultAddresses($conn, $userId);

            $stmt = $conn->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ?");
            $stmt->bind_param("i", $addrId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("
                UPDATE users
                SET country = ?, city = ?, address = ?, postcode = ?
                WHERE userID = ?
            ");
            $stmt->bind_param(
                "ssssi",
                $addressRow["country"],
                $addressRow["city"],
                $addressRow["address"],
                $addressRow["postcode"],
                $userId
            );
            $stmt->execute();
            $stmt->close();

            $user["country"]  = $addressRow["country"];
            $user["city"]     = $addressRow["city"];
            $user["address"]  = $addressRow["address"];
            $user["postcode"] = $addressRow["postcode"];

            $successMessage = "Default address updated.";
        } else {
            $errorMessage = "Address not found.";
        }

        $activeTab = "addresses";
    }

    if ($action === "edit_address") {
        $addrId = (int) ($_POST["address_id"] ?? 0);

        $stmt = $conn->prepare("SELECT * FROM user_addresses WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $addrId, $userId);
        $stmt->execute();
        $addrRes = $stmt->get_result();
        $addressRow = $addrRes->fetch_assoc();
        $stmt->close();

        if ($addressRow) {
            $label       = trim($_POST["address_label"] ?? "");
            $country     = trim($_POST["country"]  ?? "");
            $city        = trim($_POST["city"]     ?? "");
            $address     = trim($_POST["address"]  ?? "");
            $postcode    = trim($_POST["postcode"] ?? "");
            $makeDefault = isset($_POST["make_default"]) ? 1 : 0;

            if ($country && $city && $address && $postcode) {
                $existingDefault = (int)$addressRow["is_default"];
                $isDefaultNew    = $makeDefault ? 1 : $existingDefault;

                if ($isDefaultNew) {
                    accountResetDefaultAddresses($conn, $userId);
                }

                $stmt = $conn->prepare("
                    UPDATE user_addresses
                    SET label = ?, country = ?, city = ?, address = ?, postcode = ?, is_default = ?
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->bind_param(
                    "sssssiii",
                    $label,
                    $country,
                    $city,
                    $address,
                    $postcode,
                    $isDefaultNew,
                    $addrId,
                    $userId
                );
                $stmt->execute();
                $stmt->close();

                if ($isDefaultNew) {
                    $stmt = $conn->prepare("
                        UPDATE users
                        SET country = ?, city = ?, address = ?, postcode = ?
                        WHERE userID = ?
                    ");
                    $stmt->bind_param("ssssi", $country, $city, $address, $postcode, $userId);
                    $stmt->execute();
                    $stmt->close();

                    $user["country"]  = $country;
                    $user["city"]     = $city;
                    $user["address"]  = $address;
                    $user["postcode"] = $postcode;
                }

                $successMessage = "Address updated successfully.";
            } else {
                $errorMessage = "Please fill in all address fields.";
            }
        } else {
            $errorMessage = "Address not found.";
        }

        $activeTab = "addresses";
    }

    if ($action === "delete_address") {
        $addrId = (int) ($_POST["address_id"] ?? 0);

        $stmt = $conn->prepare("SELECT * FROM user_addresses WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $addrId, $userId);
        $stmt->execute();
        $addrRes = $stmt->get_result();
        $addrRow = $addrRes->fetch_assoc();
        $stmt->close();

        if ($addrRow) {
            $wasDefault = (int)$addrRow["is_default"] === 1;

            $stmt = $conn->prepare("DELETE FROM user_addresses WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $addrId, $userId);
            $stmt->execute();
            $stmt->close();

            if ($wasDefault) {
                $stmt = $conn->prepare("
                    SELECT * FROM user_addresses
                    WHERE user_id = ?
                    ORDER BY created_at ASC
                    LIMIT 1
                ");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $nextRes = $stmt->get_result();
                $next = $nextRes->fetch_assoc();
                $stmt->close();

                if ($next) {
                    accountResetDefaultAddresses($conn, $userId);

                    $stmt = $conn->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ?");
                    $stmt->bind_param("i", $next["id"]);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("
                        UPDATE users
                        SET country = ?, city = ?, address = ?, postcode = ?
                        WHERE userID = ?
                    ");
                    $stmt->bind_param(
                        "ssssi",
                        $next["country"],
                        $next["city"],
                        $next["address"],
                        $next["postcode"],
                        $userId
                    );
                    $stmt->execute();
                    $stmt->close();

                    $user["country"]  = $next["country"];
                    $user["city"]     = $next["city"];
                    $user["address"]  = $next["address"];
                    $user["postcode"] = $next["postcode"];
                }
            }

            $successMessage = "Address removed successfully.";
        } else {
            $errorMessage = "Address not found.";
        }

        $activeTab = "addresses";
    }

    if ($action === "delete_home_address") {
        $stmt = $conn->prepare("
            UPDATE users
            SET country = NULL, city = NULL, address = NULL, postcode = NULL
            WHERE userID = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();

        $user["country"]  = null;
        $user["city"]     = null;
        $user["address"]  = null;
        $user["postcode"] = null;

        $successMessage = "Home address removed.";
        $activeTab = "addresses";
    }

    if ($action === "update_settings") {
        $firstName = trim($_POST["first_name"] ?? "");
        $lastName  = trim($_POST["last_name"]  ?? "");
        $username  = trim($_POST["username"]   ?? "");
        $emailNew  = trim($_POST["email"]      ?? "");
        $phoneNew  = trim($_POST["phone"]      ?? "");

        if (!$firstName || !$lastName || !$username || !$emailNew || !$phoneNew) {
            $errorMessage = "All fields are required.";
            $activeTab = "settings";
        } elseif (!app_is_valid_username($username)) {
            $errorMessage = "Username must be 3-32 characters and use only letters, numbers, dots, underscores, or hyphens.";
            $activeTab = "settings";
        } else {
            $fullNameNew = $firstName . " " . $lastName;

            $emailChanged = (strcasecmp($emailNew, $user["email"]) !== 0);
            $phoneChanged = ($phoneNew !== ($user["phone"] ?? ""));

            if ($username !== $user["username"]) {
                $stmt = $conn->prepare("SELECT userID FROM users WHERE username = ? AND userID != ?");
                $stmt->bind_param("si", $username, $userId);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows > 0) {
                    $errorMessage = "This username is already taken.";
                }
                $stmt->close();
            }

            if (!$errorMessage && !$emailChanged && !$phoneChanged) {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET first_name = ?, last_name = ?, full_name = ?, username = ?
                    WHERE userID = ?
                ");
                $stmt->bind_param("ssssi", $firstName, $lastName, $fullNameNew, $username, $userId);
                $stmt->execute();
                $stmt->close();

                $successMessage  = "Profile updated successfully.";
                $user["first_name"] = $firstName;
                $user["last_name"]  = $lastName;
                $user["full_name"]  = $fullNameNew;
                $user["username"]   = $username;

                $_SESSION["user"]["full_name"] = $fullNameNew;
                $_SESSION["user"]["email"]     = $user["email"];
            }

            if (!$errorMessage && ($emailChanged || $phoneChanged)) {
                $code    = random_int(100000, 999999);
                $expires = date("Y-m-d H:i:s", time() + 10 * 60);

                $stmt = $conn->prepare("
                    UPDATE users
                    SET verification_code = ?, verification_expires_at = ?
                    WHERE userID = ?
                ");
                $stmt->bind_param("ssi", $code, $expires, $userId);
                $stmt->execute();
                $stmt->close();

                $_SESSION["contact_change_pending"] = [
                    "user_id"      => $userId,
                    "new_email"    => $emailChanged ? $emailNew : $user["email"],
                    "new_phone"    => $phoneChanged ? $phoneNew : $user["phone"],
                    "old_email"    => $user["email"],
                    "code_sent_to" => $user["email"],
                ];

                try {
                    $mail = new PHPMailer(true);
                    $mail->SMTPDebug = 0;
                    $mail->isSMTP();
                    $mail->Host       = 'premium245.web-hosting.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'admin@festival-web.com';
                    $mail->Password   = '!g3$~8tYju*D';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    $mail->Timeout    = 20;
                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                        ],
                    ];

                    $mail->setFrom('admin@festival-web.com', 'Athina E-Shop');
                    $mail->addAddress($user["email"], $user["full_name"]);

                    $mail->isHTML(true);
                    $mail->Subject = "Verify your contact change";
                    $mail->Body    = "
                        <p>Hello {$user['full_name']},</p>
                        <p>We received a request to change your email and/or phone number on <strong>Athina E-Shop</strong>.</p>
                        <p>Please use the following verification code to confirm this change:</p>
                        <h2 style='letter-spacing:3px;'>{$code}</h2>
                        <p>This code expires in 10 minutes.</p>
                    ";

                    $mail->send();

                    header("Location: verify_contact_change.php");
                    exit();
                } catch (Exception $e) {
                    $errorMessage = "We couldn't send the verification email. Please try again.";
                }
            }

            $activeTab = "settings";
        }
    }
}

$addresses = [];
$addrStmt = $conn->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, created_at ASC");
$addrStmt->bind_param("i", $userId);
$addrStmt->execute();
$addrRes = $addrStmt->get_result();
while ($row = $addrRes->fetch_assoc()) {
    $addresses[] = $row;
}
$addrStmt->close();

$orderHistory = [];
$orderItemPreviews = [];

$ordersSql = "
    SELECT
        o.orderID,
        o.orderNumber,
        o.status,
        o.totalAmount,
        o.createdAt,
        COUNT(oi.orderItemID) AS itemCount,
        lp.paymentStatus,
        lp.provider,
        lp.transactionID,
        lp.timestamp AS paymentTimestamp
    FROM orders o
    LEFT JOIN order_items oi ON oi.orderID = o.orderID
    LEFT JOIN (
        SELECT
            p.orderID,
            p.paymentStatus,
            p.provider,
            p.transactionID,
            p.timestamp
        FROM payments p
        INNER JOIN (
            SELECT orderID, MAX(timestamp) AS maxTimestamp
            FROM payments
            GROUP BY orderID
        ) latest
            ON latest.orderID = p.orderID
            AND latest.maxTimestamp = p.timestamp
    ) lp ON lp.orderID = o.orderID
    WHERE o.userID = ?
    GROUP BY
        o.orderID, o.orderNumber, o.status, o.totalAmount, o.createdAt,
        lp.paymentStatus, lp.provider, lp.transactionID, lp.timestamp
    ORDER BY o.createdAt DESC
";

$ordersStmt = $conn->prepare($ordersSql);
if ($ordersStmt) {
    $ordersStmt->bind_param("i", $userId);
    $ordersStmt->execute();
    $ordersRes = $ordersStmt->get_result();
    while ($row = $ordersRes->fetch_assoc()) {
        $orderHistory[] = $row;
    }
    $ordersStmt->close();
}

$previewSql = "
    SELECT
        oi.orderID,
        oi.quantity,
        COALESCE(NULLIF(p.nameEN, ''), NULLIF(p.nameGR, ''), CONCAT('Product #', oi.productID)) AS productName
    FROM order_items oi
    INNER JOIN orders o ON o.orderID = oi.orderID
    LEFT JOIN products p ON p.productID = oi.productID
    WHERE o.userID = ?
    ORDER BY oi.orderID DESC, oi.orderItemID ASC
";

$previewStmt = $conn->prepare($previewSql);
if ($previewStmt) {
    $previewStmt->bind_param("i", $userId);
    $previewStmt->execute();
    $previewRes = $previewStmt->get_result();
    while ($row = $previewRes->fetch_assoc()) {
        $oid = (int)$row["orderID"];
        if (!isset($orderItemPreviews[$oid])) {
            $orderItemPreviews[$oid] = [];
        }
        $orderItemPreviews[$oid][] = ((int)$row["quantity"]) . " x " . (string)$row["productName"];
    }
    $previewStmt->close();
}

$loyaltyBalance = loyaltyGetCurrentBalance($conn, $userId);
$loyaltyHistory = loyaltyFetchHistory($conn, $userId, 100);
$loyaltyEarnRate = loyaltyPointsEarnedPerEuro();
$loyaltyRedeemRate = loyaltyPointsRedeemPerEuro();
$loyaltyBalanceValue = $loyaltyBalance * loyaltyPointValueEuro();

$orderStatusLabels = [
    "pending" => "Pending",
    "accepted" => "Accepted",
    "in_production" => "In production",
    "shipped" => "Shipped",
    "completed" => "Completed",
    "cancelled" => "Cancelled",
];
$orderStatusClasses = [
    "pending" => "bg-secondary",
    "accepted" => "bg-success",
    "in_production" => "bg-warning text-dark",
    "shipped" => "bg-info text-dark",
    "completed" => "bg-dark",
    "cancelled" => "bg-danger",
];

$paymentStatusClasses = [
    "paid" => "bg-success",
    "pending" => "bg-warning text-dark",
    "failed" => "bg-danger",
    "refunded" => "bg-secondary",
    "unpaid" => "bg-secondary",
];
$receiptPaymentStatuses = ["paid", "completed", "captured", "succeeded"];

$avatarFilename = !empty($user["profile_image"]) ? $user["profile_image"] : null;
$avatarUrl = $avatarFilename
    ? "../uploads/avatars/" . htmlspecialchars($avatarFilename)
    : null;

$lastLogin  = formatDateTime($user["last_login"] ?? null);
$updatedAt  = formatDateTime($user["updated_at"] ?? null);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Account - Athina E-Shop</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
          integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
          crossorigin="anonymous" referrerpolicy="no-referrer" />

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/country-select-js/2.1.0/css/countrySelect.min.css" />

    <link rel="stylesheet" href="../assets/styling/styles.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/styling/styles.css') ?>">
    <link rel="stylesheet" href="../assets/styling/header.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/styling/header.css') ?>">
    <link rel="stylesheet" href="../assets/styling/account.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/styling/account.css') ?>">

    <script src="../assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/translations.js') ?>" defer></script>
</head>

<body class="account-page"<?= app_translate_page_title_attrs('My Account - Athina E-Shop', 'Ο Λογαριασμός μου - Athina E-Shop') ?>>

<?php include "../include/header.php"; ?>

<main class="account-main">
    <div class="container my-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-0" data-translate="accountTitle">My Account</h2>
            </div>
            <div class="text-end small text-muted">
                <div>
                    <span data-translate="lastLoginLabel">Last login:</span>
                    <strong><?= htmlspecialchars($lastLogin) ?></strong>
                </div>
                <div>
                    <span data-translate="lastUpdatedLabel">Last updated:</span>
                    <strong><?= htmlspecialchars($updatedAt) ?></strong>
                </div>
            </div>
        </div>

        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <div class="row">

            <div class="col-md-3 mb-4">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-body text-center">
                        <div class="position-relative d-inline-block mb-3 account-avatar-wrapper">
                            <?php if ($avatarUrl): ?>
                                <img src="<?= $avatarUrl ?>"
                                     alt="Profile picture"
                                     class="rounded-circle"
                                     style="width: 80px; height: 80px; object-fit: cover;">
                            <?php else: ?>
                                <div class="rounded-circle account-avatar-placeholder">
                                    <i class="bi bi-person"></i>
                                </div>
                            <?php endif; ?>

                            <button class="btn btn-sm btn-light border rounded-circle position-absolute"
                                    style="right: -4px; bottom: -4px;"
                                    data-bs-toggle="modal"
                                    data-bs-target="#avatarModal"
                                    aria-label="Change profile picture"<?= app_translate_aria_attrs('Change profile picture', 'Αλλαγή εικόνας προφίλ') ?>>
                                <i class="bi bi-pencil"></i>
                            </button>
                        </div>
                        <h5 class="mb-0"><?= htmlspecialchars($user["full_name"]) ?></h5>
                        <p class="text-muted mb-0 small"><?= htmlspecialchars($user["email"]) ?></p>
                    </div>

                    <div class="list-group list-group-flush border-top">
                        <a href="account.php?tab=orders"
                           class="list-group-item list-group-item-action <?= $activeTab === 'orders' ? 'active' : '' ?>">
                            <i class="bi bi-box-seam me-2"></i>
                            <span data-translate="sidebarOrders">Orders</span>
                        </a>
                        <a href="account.php?tab=loyalty"
                           class="list-group-item list-group-item-action <?= $activeTab === 'loyalty' ? 'active' : '' ?>">
                            <i class="bi bi-stars me-2"></i>
                            <span data-translate="sidebarLoyalty">Loyalty</span>
                        </a>
                        <a href="account.php?tab=addresses"
                           class="list-group-item list-group-item-action <?= $activeTab === 'addresses' ? 'active' : '' ?>">
                            <i class="bi bi-geo-alt me-2"></i>
                            <span data-translate="sidebarAddresses">Addresses</span>
                        </a>
                        <a href="account.php?tab=settings"
                           class="list-group-item list-group-item-action <?= $activeTab === 'settings' ? 'active' : '' ?>">
                            <i class="bi bi-gear me-2"></i>
                            <span data-translate="sidebarSettings">Settings</span>
                        </a>
                        <form method="post" action="../authentication/logout.php">
                            <?= app_csrf_input() ?>
                            <button type="submit" class="list-group-item list-group-item-action text-danger w-100 text-start border-0 bg-transparent">
                            <i class="bi bi-box-arrow-right me-2"></i>
                            <span data-translate="sidebarLogout">Logout</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-9">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-body">
                        <?php if ($activeTab === "orders"): ?>

                            <h4 class="mb-4" data-translate="ordersTitle">Order History</h4>
                            <?php if (empty($orderHistory)): ?>
                                <p class="text-muted mb-0" data-translate="ordersEmpty">
                                    You haven't placed any orders yet.
                                    Once you purchase something from the shop, it will appear here.
                                </p>
                            <?php else: ?>
                                <div class="d-grid gap-3">
                                    <?php foreach ($orderHistory as $order): ?>
                                        <?php
                                        $orderId = (int)$order["orderID"];
                                        $statusKey = strtolower((string)($order["status"] ?? "pending"));
                                        $paymentKey = strtolower((string)($order["paymentStatus"] ?? "unpaid"));
                                        $statusLabel = $orderStatusLabels[$statusKey] ?? ucfirst($statusKey);
                                        $statusClass = $orderStatusClasses[$statusKey] ?? "bg-secondary";
                                        $paymentClass = $paymentStatusClasses[$paymentKey] ?? "bg-secondary";
                                        $canGenerateReceipt = in_array($paymentKey, $receiptPaymentStatuses, true);
                                        $itemsPreview = $orderItemPreviews[$orderId] ?? [];
                                        ?>
                                        <div class="card border-0 shadow-sm rounded-4">
                                            <div class="card-body">
                                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                                                    <div>
                                                        <h5 class="mb-1">
                                                            <?= htmlspecialchars($order["orderNumber"] ?: ("ORD-" . $orderId)) ?>
                                                        </h5>
                                                        <div class="text-muted small"<?= app_translate_text_attrs('Placed on ' . formatDateTime($order["createdAt"] ?? null), 'Ημερομηνία παραγγελίας ' . formatDateTime($order["createdAt"] ?? null)) ?>>
                                                            Placed on <?= htmlspecialchars(formatDateTime($order["createdAt"] ?? null)) ?>
                                                        </div>
                                                        <div class="mt-2 d-flex gap-2 flex-wrap">
                                                            <span class="badge <?= $statusClass ?>">
                                                                <?= htmlspecialchars($statusLabel) ?>
                                                            </span>
                                                            <span class="badge <?= $paymentClass ?>"<?= app_translate_text_attrs('Payment: ' . $paymentKey, 'Πληρωμή: ' . $paymentKey) ?>>
                                                                Payment: <?= htmlspecialchars($paymentKey) ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="text-end">
                                                        <div class="text-muted small" data-translate="accountTotalLabel">Total</div>
                                                        <div class="fw-semibold fs-5">
                                                            EUR <?= number_format((float)$order["totalAmount"], 2) ?>
                                                        </div>
                                                        <div class="text-muted small"<?= app_translate_text_attrs((int)$order["itemCount"] . ' item(s)', (int)$order["itemCount"] . ' προϊόν(τα)') ?>>
                                                            <?= (int)$order["itemCount"] ?> item(s)
                                                        </div>
                                                    </div>
                                                </div>

                                                <?php if (!empty($itemsPreview)): ?>
                                                    <div class="mt-3">
                                                        <div class="small text-muted mb-1" data-translate="accountItemsLabel">Items</div>
                                                        <div class="small">
                                                            <?php
                                                            $previewList = array_slice($itemsPreview, 0, 3);
                                                            echo htmlspecialchars(implode(" | ", $previewList));
                                                            if (count($itemsPreview) > 3) {
                                                                echo " ...";
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="mt-3 d-flex flex-wrap gap-2">
                                                    <?php if ($canGenerateReceipt): ?>
                                                        <a href="../modules/receipt.php?order_id=<?= $orderId ?>"
                                                           class="btn btn-outline-secondary btn-sm"
                                                           target="_blank" rel="noopener">
                                                            <i class="bi bi-receipt"></i> <span data-translate="accountGenerateReceipt">Generate Receipt</span>
                                                        </a>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" disabled>
                                                            <i class="bi bi-receipt"></i> <span data-translate="accountReceiptUnavailable">Receipt Unavailable</span>
                                                        </button>
                                                    <?php endif; ?>

                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="action" value="reorder_order">
                                                        <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                                        <input type="hidden" name="reorder_token" value="<?= htmlspecialchars($_SESSION["account_reorder_token"]) ?>">
                                                        <button type="submit" class="btn btn-primary btn-sm">
                                                            <i class="bi bi-cart-plus"></i> <span data-translate="accountReorder">Reorder</span>
                                                        </button>
                                                    </form>

                                                    <a href="../cart.php" class="btn btn-outline-primary btn-sm">
                                                        <i class="bi bi-bag"></i> <span data-translate="accountViewCart">View Cart</span>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($activeTab === "loyalty"): ?>
                            <h4 class="mb-4" data-translate="loyaltyProgramTitle">Loyalty Program</h4>

                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <div class="card border-0 shadow-sm rounded-4 h-100">
                                        <div class="card-body">
                                            <div class="text-muted small text-uppercase" data-translate="loyaltyCurrentBalance">Current Balance</div>
                                            <div class="fs-3 fw-semibold"><?= number_format($loyaltyBalance) ?> pts</div>
                                            <div class="small text-muted"<?= app_translate_text_attrs('Approx. €' . number_format($loyaltyBalanceValue, 2) . ' in redeemable value', 'Περίπου €' . number_format($loyaltyBalanceValue, 2) . ' σε αξία εξαργύρωσης') ?>>Approx. €<?= number_format($loyaltyBalanceValue, 2) ?> in redeemable value</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card border-0 shadow-sm rounded-4 h-100">
                                        <div class="card-body">
                                            <div class="text-muted small text-uppercase" data-translate="loyaltyEarnRule">Earn Rule</div>
                                            <div class="fs-5 fw-semibold"<?= app_translate_text_attrs(number_format($loyaltyEarnRate) . ' point per €1', number_format($loyaltyEarnRate) . ' πόντος ανά €1') ?>><?= number_format($loyaltyEarnRate) ?> point per €1</div>
                                            <div class="small text-muted" data-translate="loyaltyEarnHelp">Points are awarded after successful orders.</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card border-0 shadow-sm rounded-4 h-100">
                                        <div class="card-body">
                                            <div class="text-muted small text-uppercase" data-translate="loyaltyRedeemRule">Redeem Rule</div>
                                            <div class="fs-5 fw-semibold"<?= app_translate_text_attrs(number_format($loyaltyRedeemRate) . ' points = €1 off', number_format($loyaltyRedeemRate) . ' πόντοι = €1 έκπτωση') ?>><?= number_format($loyaltyRedeemRate) ?> points = €1 off</div>
                                            <div class="small text-muted" data-translate="loyaltyRedeemHelp">Redemption applies at checkout before shipping.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if (empty($loyaltyHistory)): ?>
                                <p class="text-muted mb-0" data-translate="loyaltyNoActivity">
                                    You do not have any loyalty activity yet. Once you complete an eligible purchase, your points history will appear here.
                                </p>
                            <?php else: ?>
                                <div class="card border-0 shadow-sm rounded-4">
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table align-middle mb-0">
                                                <thead>
                                                    <tr>
                                                        <th data-translate="loyaltyDate">Date</th>
                                                        <th data-translate="loyaltyActivity">Activity</th>
                                                        <th data-translate="loyaltyOrder">Order</th>
                                                        <th data-translate="loyaltyPoints">Points</th>
                                                        <th data-translate="loyaltyBalance">Balance</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($loyaltyHistory as $entry): ?>
                                                        <?php
                                                        $pointsDelta = (int)($entry["pointsDelta"] ?? 0);
                                                        $balanceAfterRow = (int)($entry["pointsBalanceAfter"] ?? 0);
                                                        $voucherValue = (float)($entry["voucherValue"] ?? 0);
                                                        $orderNumber = trim((string)($entry["orderNumber"] ?? ""));
                                                        $orderLabel = $orderNumber !== ""
                                                            ? $orderNumber
                                                            : (((int)($entry["referenceOrderID"] ?? 0) > 0) ? ("Order #" . (int)$entry["referenceOrderID"]) : "-");
                                                        $pointsClass = $pointsDelta >= 0 ? "text-success" : "text-danger";
                                                        $pointsPrefix = $pointsDelta > 0 ? "+" : "";
                                                        ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars(formatDateTime($entry["createdAt"] ?? null)) ?></td>
                                                            <td>
                                                                <?php
                                                                $ruleApplied = strtolower(trim((string)($entry["ruleApplied"] ?? "")));
                                                                $ruleEn = formatLoyaltyRuleLabel($entry["ruleApplied"] ?? "");
                                                                $ruleEl = $ruleApplied === 'earn_purchase'
                                                                    ? 'Πόντοι από αγορά'
                                                                    : ($ruleApplied === 'redeem_checkout'
                                                                        ? 'Πόντοι που εξαργυρώθηκαν στο checkout'
                                                                        : ($ruleApplied === '' ? 'Δραστηριότητα επιβράβευσης' : $ruleEn));
                                                                ?>
                                                                <div class="fw-medium"<?= app_translate_text_attrs($ruleEn, $ruleEl) ?>><?= htmlspecialchars($ruleEn) ?></div>
                                                                <?php if ($voucherValue > 0): ?>
                                                                    <div class="small text-muted"<?= app_translate_text_attrs('Discount used: €' . number_format($voucherValue, 2), 'Έκπτωση που χρησιμοποιήθηκε: €' . number_format($voucherValue, 2)) ?>>Discount used: €<?= number_format($voucherValue, 2) ?></div>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td<?= str_starts_with($orderLabel, 'Order #') ? app_translate_text_attrs($orderLabel, 'Παραγγελία #' . preg_replace('/\D+/', '', $orderLabel)) : '' ?>><?= htmlspecialchars($orderLabel) ?></td>
                                                            <td class="<?= $pointsClass ?> fw-semibold"><?= $pointsPrefix . number_format($pointsDelta) ?></td>
                                                            <td<?= app_translate_text_attrs(number_format($balanceAfterRow) . ' pts', number_format($balanceAfterRow) . ' πόντοι') ?>><?= number_format($balanceAfterRow) ?> pts</td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($activeTab === "addresses"): ?>

                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h4 class="mb-0" data-translate="addressesTitle">Saved Addresses</h4>
                                <button class="btn btn-primary rounded-pill px-4"
                                        data-bs-toggle="modal"
                                        data-bs-target="#addressModal"
                                        data-translate="addressesAddNew">
                                    Add New
                                </button>
                            </div>

                            <div class="row g-3">

                                <?php if (!empty($user["address"])): ?>
                                    <div class="col-md-6">
                                        <div class="card border-0 shadow-sm rounded-4 h-100">
                                            <div class="card-body d-flex flex-column">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <h5 class="mb-0" data-translate="addressHomeLabel">Home</h5>
                                                    <span class="badge bg-gradient rounded-pill"
                                                          data-translate="addressDefaultBadge">Default</span>
                                                </div>
                                                <p class="mb-1 small">
                                                    <?= htmlspecialchars($user["address"]) ?><br>
                                                    <?= htmlspecialchars($user["city"]) ?>,
                                                    <?= htmlspecialchars($user["postcode"]) ?><br>
                                                    <?= htmlspecialchars($user["country"]) ?>
                                                </p>
                                                <p class="text-muted small mb-2" data-translate="addressRegistrationNote">
                                                    This is the address you provided during registration.
                                                </p>
                                                <form method="post" class="mt-auto">
                                                    <input type="hidden" name="action" value="delete_home_address">
                                                    <button type="submit"
                                                            class="btn btn-outline-danger btn-sm">
                                                        <i class="bi bi-trash me-1"></i>
                                                        <span data-translate="addressDeleteHome">Delete</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (count($addresses) > 0): ?>
                                    <?php foreach ($addresses as $addr): ?>
                                        <div class="col-md-6">
                                            <div class="card border-0 shadow-sm rounded-4 h-100">
                                                <div class="card-body d-flex flex-column">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <h5 class="mb-0">
                                                            <?= $addr["label"] !== ""
                                                                ? htmlspecialchars($addr["label"])
                                                                : "" ?>
                                                        </h5>
                                                        <?php if ($addr["is_default"]): ?>
                                                            <span class="badge bg-gradient rounded-pill"
                                                                  data-translate="addressDefaultBadge">Default</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <p class="mb-1 small">
                                                        <?= htmlspecialchars($addr["address"]) ?><br>
                                                        <?= htmlspecialchars($addr["city"]) ?>,
                                                        <?= htmlspecialchars($addr["postcode"]) ?><br>
                                                        <?= htmlspecialchars($addr["country"]) ?>
                                                    </p>

                                                    <div class="d-flex gap-2 mt-auto">

                                                        <button type="button"
                                                                class="btn btn-outline-secondary btn-sm edit-address-btn"
                                                                title="Edit address"<?= app_translate_title_attrs('Edit address', 'Επεξεργασία διεύθυνσης') ?>
                                                                data-id="<?= (int)$addr["id"] ?>"
                                                                data-label="<?= htmlspecialchars($addr["label"] ?? "", ENT_QUOTES) ?>"
                                                                data-country="<?= htmlspecialchars($addr["country"] ?? "", ENT_QUOTES) ?>"
                                                                data-city="<?= htmlspecialchars($addr["city"] ?? "", ENT_QUOTES) ?>"
                                                                data-address="<?= htmlspecialchars($addr["address"] ?? "", ENT_QUOTES) ?>"
                                                                data-postcode="<?= htmlspecialchars($addr["postcode"] ?? "", ENT_QUOTES) ?>"
                                                                data-is-default="<?= (int)$addr["is_default"] ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>

                                                        <form method="post">
                                                            <input type="hidden" name="action" value="set_default_address">
                                                            <input type="hidden" name="address_id" value="<?= (int)$addr["id"] ?>">
                                                            <?php if (!$addr["is_default"]): ?>
                                                                <button type="submit"
                                                                        class="btn btn-outline-primary btn-sm"
                                                                        data-translate="addressSetDefault">
                                                                    Set as Default
                                                                </button>
                                                            <?php else: ?>
                                                                <button type="button"
                                                                        class="btn btn-outline-secondary btn-sm"
                                                                        disabled
                                                                        data-translate="addressCurrentDefault">
                                                                    Current Default
                                                                </button>
                                                            <?php endif; ?>
                                                        </form>

                                                        <form method="post">
                                                            <input type="hidden" name="action" value="delete_address">
                                                            <input type="hidden" name="address_id" value="<?= (int)$addr["id"] ?>">
                                                            <button type="submit"
                                                                    class="btn btn-outline-danger btn-sm">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if (empty($user["address"]) && count($addresses) === 0): ?>
                                    <div class="col-12">
                                        <p class="text-muted mb-0" data-translate="addressesNoneText">
                                            You don't have any saved addresses yet. Click "Add New" to create one.
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </div>

                        <?php elseif ($activeTab === "settings"): ?>

                            <h4 class="mb-4" data-translate="settingsTitle">Account Settings</h4>

                            <form method="post" class="row g-3">
                                <input type="hidden" name="action" value="update_settings">

                                <div class="col-md-6">
                                    <label class="form-label" data-translate="settingsFirstName">First Name</label>
                                    <input type="text" name="first_name" class="form-control"
                                           value="<?= htmlspecialchars($user["first_name"] ?? "") ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" data-translate="settingsLastName">Last Name</label>
                                    <input type="text" name="last_name" class="form-control"
                                           value="<?= htmlspecialchars($user["last_name"] ?? "") ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" data-translate="settingsUsername">Username</label>
                                    <input type="text" name="username" class="form-control"
                                           value="<?= htmlspecialchars($user["username"] ?? "") ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" data-translate="settingsPhone">Phone Number</label>
                                    <input type="text" name="phone" class="form-control"
                                           value="<?= htmlspecialchars($user["phone"] ?? "") ?>" required>
                                    <small class="text-muted" data-translate="settingsPhoneNote">
                                        Changing your phone will require a verification code sent to your current email.
                                    </small>
                                </div>

                                <div class="col-md-12">
                                    <label class="form-label" data-translate="settingsEmail">Email</label>
                                    <input type="email" name="email" class="form-control"
                                           value="<?= htmlspecialchars($user["email"] ?? "") ?>" required>
                                    <small class="text-muted" data-translate="settingsEmailNote">
                                        Changing your email will require a verification code sent to your current email.
                                    </small>
                                </div>

                                <div class="col-12 mt-3">
                                    <button type="submit"
                                            class="btn btn-primary px-4"
                                            data-translate="settingsSaveChanges">
                                        Save Changes
                                    </button>
                                </div>
                            </form>

                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<div class="modal fade" id="avatarModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_avatar">
            <div class="modal-header">
                <h5 class="modal-title" data-translate="avatarModalTitle">Change Profile Picture</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="Close"<?= app_translate_aria_attrs('Close', 'Κλείσιμο') ?>></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="profile_image" class="form-label" data-translate="avatarModalLabel">
                        Upload a new image
                    </label>
                    <input type="file" name="profile_image" id="profile_image"
                           class="form-control" accept="image/*" required>
                    <small class="text-muted" data-translate="avatarModalNote">
                        JPG, PNG, GIF or WEBP. Uploaded images are converted to WebP automatically. Max size 2MB.
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal"
                        data-translate="btnCancel">
                    Cancel
                </button>
                <button type="submit"
                        class="btn btn-primary"
                        data-translate="btnSavePicture">
                    Save Picture
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="addressModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post">
            <input type="hidden" name="action" value="add_address">
            <div class="modal-header">
                <h5 class="modal-title" data-translate="addressModalTitle">Add New Address</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="Close"<?= app_translate_aria_attrs('Close', 'Κλείσιμο') ?>></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 d-flex flex-column align-items-start">
                    <label class="form-label mb-1"
                           for="address_country"
                           data-translate="addressCountryLabel">
                        Country
                    </label>
                    <input type="text"
                           name="country"
                           id="address_country"
                           class="form-control country_input"
                           autocomplete="off"
                           required>
                </div>
                <div class="mb-3">
                    <label class="form-label" data-translate="addressCityLabel">City</label>
                    <input type="text" name="city" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" data-translate="addressAddressLabel">Address</label>
                    <input type="text" name="address" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" data-translate="addressPostcodeLabel">Postal Code</label>
                    <input type="text" name="postcode" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" data-translate="accountAddressLabelOptional">Address label (optional)</label>
                    <input type="text" name="address_label" class="form-control"
                           data-translate-placeholder="accountAddressLabelPlaceholder"
                           placeholder="Apartment, Home, Business, etc.">
                    <small class="text-muted" data-translate="accountAddressLabelHelp">
                        Optional - leave blank if you don't want a label.
                    </small>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="make_default"
                           id="make_default">
                    <label class="form-check-label"
                           for="make_default"
                           data-translate="addressMakeDefault">
                        Set as default shipping address
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal"
                        data-translate="btnCancel">
                    Cancel
                </button>
                <button type="submit"
                        class="btn btn-primary"
                        data-translate="addressSaveAddress">
                    Save Address
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editAddressModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post">
            <input type="hidden" name="action" value="edit_address">
            <input type="hidden" name="address_id" id="edit_address_id">
            <div class="modal-header">
                <h5 class="modal-title" data-translate="accountEditAddressTitle">Edit Address</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="Close"<?= app_translate_aria_attrs('Close', 'Κλείσιμο') ?>></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="edit_address_country" data-translate="addressCountryLabel">Country</label>
                    <input type="text"
                           name="country"
                           id="edit_address_country"
                           class="form-control"
                           required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="edit_address_city" data-translate="addressCityLabel">City</label>
                    <input type="text" name="city" id="edit_address_city" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="edit_address_address" data-translate="addressAddressLabel">Address</label>
                    <input type="text" name="address" id="edit_address_address" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="edit_address_postcode" data-translate="addressPostcodeLabel">Postal Code</label>
                    <input type="text" name="postcode" id="edit_address_postcode" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="edit_address_label" data-translate="accountAddressLabelOptional">Address label (optional)</label>
                    <input type="text" name="address_label" id="edit_address_label"
                           class="form-control"
                           data-translate-placeholder="accountAddressLabelPlaceholder"
                           placeholder="Apartment, Home, Business, etc.">
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="make_default"
                           id="edit_make_default">
                    <label class="form-check-label" for="edit_make_default" data-translate="addressMakeDefault">
                        Set as default shipping address
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal"
                        data-translate="btnCancel">
                    Cancel
                </button>
                <button type="submit"
                        class="btn btn-primary"
                        data-translate="settingsSaveChanges">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<?php include "../include/footer.php"; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/country-select-js/2.1.0/js/countrySelect.min.js"></script>

<script>
    $(document).ready(function () {
        const $country = $("#address_country");
        if ($country.length) {
            $country.countrySelect({ defaultCountry: "cy" });
            $country.attr("readonly", true);
            $country.on("keydown", function (e) { e.preventDefault(); });
        }
    });
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const editButtons = document.querySelectorAll('.edit-address-btn');
    const editModalEl = document.getElementById('editAddressModal');

    if (editModalEl && editButtons.length) {
        editButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                document.getElementById('edit_address_id').value       = this.dataset.id || '';
                document.getElementById('edit_address_country').value  = this.dataset.country || '';
                document.getElementById('edit_address_city').value     = this.dataset.city || '';
                document.getElementById('edit_address_address').value  = this.dataset.address || '';
                document.getElementById('edit_address_postcode').value = this.dataset.postcode || '';
                document.getElementById('edit_address_label').value    = this.dataset.label || '';
                document.getElementById('edit_make_default').checked   = this.dataset.isDefault === '1';

                const modal = new bootstrap.Modal(editModalEl);
                modal.show();
            });
        });
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
