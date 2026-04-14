<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../authentication/database.php';
require_once __DIR__ . '/../include/product_option_helpers.php';
if (!defined('CUSTOM_ORDERS_DIRECT')) {
    define('CUSTOM_ORDERS_DIRECT', true);
}
require_once __DIR__ . '/../modules/custom_orders.php';

const WEBSITE_SOURCE_ROOT = 'C:/Users/elias/OneDrive - Crystal IT Services/Desktop/website';

function catalogEnsurePhotoStorageSchema(mysqli $conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $tableCheck = $conn->query("SHOW TABLES LIKE 'photos'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        return;
    }

    $columnRes = $conn->query("
        SELECT DATA_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'photos'
          AND COLUMN_NAME = 'photo'
        LIMIT 1
    ");
    $column = $columnRes ? $columnRes->fetch_assoc() : null;
    $type = strtolower((string)($column['DATA_TYPE'] ?? ''));
    if ($type === 'blob' || $type === 'tinyblob') {
        $conn->query("ALTER TABLE photos MODIFY COLUMN photo MEDIUMBLOB NOT NULL");
    }
}
function catalogTableHasColumn(mysqli $conn, string $table, string $column): bool
{
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $tableCheck = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        return false;
    }
    $columnCheck = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return (bool)($columnCheck && $columnCheck->num_rows > 0);
}

function catalogReadPhotoBlob(string $path): string
{
    if (!is_file($path)) {
        throw new RuntimeException("Image not found: {$path}");
    }

    $rawData = file_get_contents($path);
    if (!is_string($rawData) || $rawData === '') {
        throw new RuntimeException("Could not read image bytes: {$path}");
    }

    if (!extension_loaded('gd')) {
        return $rawData;
    }

    $imageInfo = @getimagesize($path);
    if (!$imageInfo || empty($imageInfo[0]) || empty($imageInfo[1])) {
        return $rawData;
    }

    $width = (int)$imageInfo[0];
    $height = (int)$imageInfo[1];
    $imageType = (int)($imageInfo[2] ?? IMAGETYPE_JPEG);

    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $source = @imagecreatefromjpeg($path);
            break;
        case IMAGETYPE_PNG:
            $source = @imagecreatefrompng($path);
            break;
        case IMAGETYPE_WEBP:
            $source = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
            break;
        default:
            $source = false;
            break;
    }

    if (!$source) {
        return $rawData;
    }

    $targets = [
        ['max' => 1600, 'quality' => 82],
        ['max' => 1400, 'quality' => 78],
        ['max' => 1200, 'quality' => 74],
        ['max' => 1000, 'quality' => 70],
    ];

    $finalData = '';
    foreach ($targets as $target) {
        $maxEdge = (int)$target['max'];
        $quality = (int)$target['quality'];
        $scale = min(1, $maxEdge / max($width, $height));
        $targetWidth = max(1, (int)round($width * $scale));
        $targetHeight = max(1, (int)round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($imageType === IMAGETYPE_PNG || $imageType === IMAGETYPE_WEBP) {
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
        }

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, $quality);
        $candidate = (string)ob_get_clean();
        imagedestroy($canvas);

        if ($candidate !== '') {
            $finalData = $candidate;
            if (strlen($candidate) <= 900000) {
                break;
            }
        }
    }

    imagedestroy($source);

    if ($finalData !== '') {
        return $finalData;
    }

    return $rawData;
}

function catalogInsertPhoto(mysqli $conn, int $productId, string $photoData): void
{
    $stmt = $conn->prepare("INSERT INTO photos (photo, productID) VALUES (?, ?)");
    $null = null;
    $stmt->bind_param('bi', $null, $productId);
    $stmt->send_long_data(0, $photoData);
    $stmt->execute();
    $stmt->close();
}

function catalogEnsureDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException("Could not create directory: {$directory}");
    }
}

function catalogSlugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
    $value = trim((string)$value, '-');
    return $value !== '' ? $value : 'asset';
}

function catalogLocalProductsRoot(): string
{
    return dirname(__DIR__) . '/uploads/assets/images/products';
}

function catalogCollectLocalSkuFiles(string $skuSlug): array
{
    static $cache = [];
    if (isset($cache[$skuSlug])) {
        return $cache[$skuSlug];
    }

    $root = catalogLocalProductsRoot() . '/' . $skuSlug;
    if (!is_dir($root)) {
        $cache[$skuSlug] = [];
        return $cache[$skuSlug];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if ($entry->isFile()) {
            $files[] = str_replace('\\', '/', $entry->getPathname());
        }
    }

    sort($files);
    $cache[$skuSlug] = $files;
    return $files;
}

function catalogResolveSourcePath(string $sku, string $folderRoot, string $relativePath): ?string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $directSource = str_replace('\\', '/', rtrim($folderRoot, '/\\') . '/' . $relativePath);
    if (is_file($directSource)) {
        return $directSource;
    }

    $skuSlug = catalogSlugify($sku);
    $localRoot = catalogLocalProductsRoot() . '/' . $skuSlug;
    $directLocal = str_replace('\\', '/', rtrim($localRoot, '/\\') . '/' . $relativePath);
    if (is_file($directLocal)) {
        return $directLocal;
    }

    $files = catalogCollectLocalSkuFiles($skuSlug);
    if (empty($files)) {
        return null;
    }

    $expectedExt = strtolower((string)pathinfo($relativePath, PATHINFO_EXTENSION));
    $expectedSlug = catalogSlugify((string)pathinfo($relativePath, PATHINFO_FILENAME));
    $dirSlug = catalogSlugify(str_replace('\\', '/', dirname($relativePath)));
    $matches = [];

    foreach ($files as $path) {
        $pathExt = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        if ($expectedExt !== '' && $pathExt !== $expectedExt) {
            continue;
        }

        $pathSlug = catalogSlugify((string)pathinfo($path, PATHINFO_FILENAME));
        $score = 0;

        if ($pathSlug === $expectedSlug) {
            $score += 60;
        } elseif (str_ends_with($pathSlug, '-' . $expectedSlug) || str_ends_with($pathSlug, $expectedSlug)) {
            $score += 45;
        } elseif (str_contains($pathSlug, $expectedSlug)) {
            $score += 20;
        }

        if ($dirSlug !== '' && $dirSlug !== '.' && str_contains(strtolower($path), '/' . $dirSlug . '/')) {
            $score += 25;
        }

        if ($score > 0) {
            $matches[] = ['path' => $path, 'score' => $score];
        }
    }

    if (empty($matches)) {
        return null;
    }

    usort($matches, static function (array $left, array $right): int {
        if ($left['score'] === $right['score']) {
            return strcmp((string)$left['path'], (string)$right['path']);
        }
        return $right['score'] <=> $left['score'];
    });

    return (string)$matches[0]['path'];
}

function catalogCopyAsset(string $sourcePath, string $relativeTargetPath): string
{
    if (!is_file($sourcePath)) {
        throw new RuntimeException("Asset not found: {$sourcePath}");
    }

    $relativeTargetPath = ltrim(str_replace('\\', '/', $relativeTargetPath), '/');
    $destinationPath = dirname(__DIR__) . '/' . $relativeTargetPath;
    catalogEnsureDirectory(dirname($destinationPath));

    $sourceRealPath = realpath($sourcePath);
    $destinationRealPath = realpath($destinationPath);
    if (
        $sourceRealPath !== false &&
        $destinationRealPath !== false &&
        strcasecmp($sourceRealPath, $destinationRealPath) === 0
    ) {
        return $relativeTargetPath;
    }

    if (!copy($sourcePath, $destinationPath)) {
        throw new RuntimeException("Could not copy asset to {$relativeTargetPath}");
    }

    return $relativeTargetPath;
}

function catalogInsertVariation(mysqli $conn, int $productId, array $variation): int
{
    $stmt = $conn->prepare("
        INSERT INTO product_variations (productID, size, yarnType, colorID, price)
        VALUES (?, ?, ?, NULL, ?)
    ");
    $size = trim((string)($variation['size'] ?? ''));
    $yarnType = trim((string)($variation['yarn_type'] ?? ''));
    $price = (float)($variation['price'] ?? 0);
    $stmt->bind_param('issd', $productId, $size, $yarnType, $price);
    $stmt->execute();
    $variationId = (int)$stmt->insert_id;
    $stmt->close();

    return $variationId;
}

function catalogInsertVariationStock(mysqli $conn, int $variationId, int $quantity, int $lowStockThreshold = 1): void
{
    $stmt = $conn->prepare("
        INSERT INTO variation_stock (variationID, quantityAvailable, lowStockThreshold, lastStockChangeSource)
        VALUES (?, ?, ?, 'catalog-import')
    ");
    $stmt->bind_param('iii', $variationId, $quantity, $lowStockThreshold);
    $stmt->execute();
    $stmt->close();
}

function catalogInsertVariationPhoto(mysqli $conn, int $variationId, string $photoPath, int $sortOrder): void
{
    $stmt = $conn->prepare("
        INSERT INTO product_variation_photos (variationID, photoPath, sortOrder)
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param('isi', $variationId, $photoPath, $sortOrder);
    $stmt->execute();
    $stmt->close();
}

function catalogEnsureColor(mysqli $conn, string $colorName, int $globalInventory = 25): int
{
    static $cache = [];
    static $nextId = null;

    $colorName = trim($colorName);
    if ($colorName === '') {
        throw new InvalidArgumentException('Color name is required.');
    }

    $cacheKey = mb_strtolower($colorName);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $select = $conn->prepare("SELECT colorID FROM colors WHERE LOWER(colorName) = LOWER(?) LIMIT 1");
    $select->bind_param('s', $colorName);
    $select->execute();
    $result = $select->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $select->close();

    if ($row) {
        $colorId = (int)($row['colorID'] ?? 0);
        $cache[$cacheKey] = $colorId;
        return $colorId;
    }

    if ($nextId === null) {
        $nextRes = $conn->query("SELECT COALESCE(MAX(colorID), 0) + 1 AS nextId FROM colors");
        $nextRow = $nextRes ? $nextRes->fetch_assoc() : null;
        $nextId = max(1, (int)($nextRow['nextId'] ?? 1));
    }

    $colorId = $nextId++;
    $insert = $conn->prepare("
        INSERT INTO colors (colorID, colorName, globalInventoryAvailable, isActive)
        VALUES (?, ?, ?, 1)
    ");
    $insert->bind_param('isi', $colorId, $colorName, $globalInventory);
    $insert->execute();
    $insert->close();

    $cache[$cacheKey] = $colorId;
    return $colorId;
}

function catalogInsertProductColorPhoto(mysqli $conn, int $productId, int $colorId, string $photoPath, int $sortOrder): void
{
    $stmt = $conn->prepare("
        INSERT INTO product_color_photos (productID, colorID, photoPath, sortOrder)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param('iisi', $productId, $colorId, $photoPath, $sortOrder);
    $stmt->execute();
    $stmt->close();
}

function catalogDeleteImportedProductData(mysqli $conn, int $productId): void
{
    $variationIds = [];
    $variationStmt = $conn->prepare("SELECT variationID FROM product_variations WHERE productID = ?");
    $variationStmt->bind_param('i', $productId);
    $variationStmt->execute();
    $variationRes = $variationStmt->get_result();
    while ($variationRes && ($variationRow = $variationRes->fetch_assoc())) {
        $variationIds[] = (int)($variationRow['variationID'] ?? 0);
    }
    $variationStmt->close();

    if (!empty($variationIds)) {
        $idList = implode(',', array_map('intval', $variationIds));
        $conn->query("DELETE FROM variation_stock WHERE variationID IN ({$idList})");
        $conn->query("DELETE FROM product_variation_photos WHERE variationID IN ({$idList})");
    }

    $deleteColorPhotos = $conn->prepare("DELETE FROM product_color_photos WHERE productID = ?");
    $deleteColorPhotos->bind_param('i', $productId);
    $deleteColorPhotos->execute();
    $deleteColorPhotos->close();

    $deleteVariations = $conn->prepare("DELETE FROM product_variations WHERE productID = ?");
    $deleteVariations->bind_param('i', $productId);
    $deleteVariations->execute();
    $deleteVariations->close();

    $deletePhotos = $conn->prepare("DELETE FROM photos WHERE productID = ?");
    $deletePhotos->bind_param('i', $productId);
    $deletePhotos->execute();
    $deletePhotos->close();
}

function catalogArchiveOrDeleteLegacyProducts(mysqli $conn, array $keepSkus): array
{
    $keepLookup = array_fill_keys($keepSkus, true);
    $canCheckCustomRefs = catalogTableHasColumn($conn, 'custom_orders', 'sourceProductID');

    $legacy = [];
    $sql = "
        SELECT p.productID, p.sku, p.nameEN, p.cartStatus,
               COALESCE(oi.order_refs, 0) AS orderRefs
        FROM products p
        LEFT JOIN (
            SELECT productID, COUNT(*) AS order_refs
            FROM order_items
            GROUP BY productID
        ) oi ON oi.productID = p.productID
        ORDER BY p.productID ASC
    ";
    $res = $conn->query($sql);
    while ($res && ($row = $res->fetch_assoc())) {
        $sku = trim((string)($row['sku'] ?? ''));
        if ($sku !== '' && isset($keepLookup[$sku])) {
            continue;
        }
        $legacy[] = $row;
    }

    $customRefStmt = null;
    if ($canCheckCustomRefs) {
        $customRefStmt = $conn->prepare("SELECT COUNT(*) AS refCount FROM custom_orders WHERE sourceProductID = ?");
    }

    $deleteProductSales = $conn->prepare("DELETE FROM product_sales_overrides WHERE productID = ?");
    $deleteProduct = $conn->prepare("DELETE FROM products WHERE productID = ?");
    $archiveProduct = $conn->prepare("
        UPDATE products
        SET cartStatus = 'discontinued',
            inventory = 0,
            isSellingFast = 0
        WHERE productID = ?
    ");

    $summary = ['deleted' => 0, 'archived' => 0];

    foreach ($legacy as $row) {
        $productId = (int)$row['productID'];
        $orderRefs = (int)($row['orderRefs'] ?? 0);
        $customRefs = 0;

        if ($customRefStmt) {
            $customRefStmt->bind_param('i', $productId);
            $customRefStmt->execute();
            $customRes = $customRefStmt->get_result();
            $customRow = $customRes ? $customRes->fetch_assoc() : null;
            $customRefs = (int)($customRow['refCount'] ?? 0);
        }

        if ($orderRefs === 0 && $customRefs === 0) {
            $deleteProductSales->bind_param('i', $productId);
            $deleteProductSales->execute();

            $deleteProduct->bind_param('i', $productId);
            $deleteProduct->execute();
            $summary['deleted']++;
            continue;
        }

        $archiveProduct->bind_param('i', $productId);
        $archiveProduct->execute();
        $summary['archived']++;
    }

    if ($customRefStmt) {
        $customRefStmt->close();
    }
    $deleteProductSales->close();
    $deleteProduct->close();
    $archiveProduct->close();

    return $summary;
}

function catalogBuildPlushieWarningsEn(): array
{
    return [
        "Important: Small pieces and safety eyes on this plushie require parent supervision for babies and small children.",
        "Disclaimer: Our listing photos are an approximate representation of the final color. Due to substrate differences, digital screen settings, and production variations, we cannot guarantee the color you see on your screen is the true color of the product.",
    ];
}

function catalogBuildPlushieWarningsGr(): array
{
    return [
        "Î£Î·Î¼Î±Î½Ï„Î¹ÎºÏŒ: Î¤Î± Î¼Î¹ÎºÏÎ¬ ÎºÎ¿Î¼Î¼Î¬Ï„Î¹Î± ÎºÎ±Î¹ Ï„Î± Î¼Î±Ï„Î¬ÎºÎ¹Î± ÏƒÎµ Î±Ï…Ï„ÏŒ Ï„Î¿ Î»Î¿ÏÏ„ÏÎ¹Î½Î¿ Î±Ï€Î±Î¹Ï„Î¿ÏÎ½ ÎµÏ€Î¯Î²Î»ÎµÏˆÎ· Î±Ï€ÏŒ Î³Î¿Î½ÎµÎ¯Ï‚ Î³Î¹Î± Î¼Ï‰ÏÎ¬ ÎºÎ±Î¹ Î¼Î¹ÎºÏÎ¬ Ï€Î±Î¹Î´Î¹Î¬.",
        "Î‘Ï€Î¿Ï€Î¿Î¯Î·ÏƒÎ· ÎµÏ…Î¸ÏÎ½Î·Ï‚: ÎŸÎ¹ Ï†Ï‰Ï„Î¿Î³ÏÎ±Ï†Î¯ÎµÏ‚ Î±Ï€Î¿Ï„ÎµÎ»Î¿ÏÎ½ Î¼Î¹Î± ÎºÎ±Ï„Î¬ Ï€ÏÎ¿ÏƒÎ­Î³Î³Î¹ÏƒÎ· Î±Ï€ÎµÎ¹ÎºÏŒÎ½Î¹ÏƒÎ· Ï„Î¿Ï… Ï„ÎµÎ»Î¹ÎºÎ¿Ï Ï‡ÏÏŽÎ¼Î±Ï„Î¿Ï‚. Î›ÏŒÎ³Ï‰ Î´Î¹Î±Ï†Î¿ÏÏŽÎ½ ÏƒÏ„Î± Ï…Î»Î¹ÎºÎ¬, ÏƒÏ„Î¹Ï‚ ÏÏ…Î¸Î¼Î¯ÏƒÎµÎ¹Ï‚ Ï„Ï‰Î½ ÏˆÎ·Ï†Î¹Î±ÎºÏŽÎ½ Î¿Î¸Î¿Î½ÏŽÎ½ ÎºÎ±Î¹ ÏƒÏ„Î¹Ï‚ Ï€Î±ÏÎ±Î»Î»Î±Î³Î­Ï‚ Ï€Î±ÏÎ±Î³Ï‰Î³Î®Ï‚, Î´ÎµÎ½ Î¼Ï€Î¿ÏÎ¿ÏÎ¼Îµ Î½Î± ÎµÎ³Î³Ï…Î·Î¸Î¿ÏÎ¼Îµ ÏŒÏ„Î¹ Ï„Î¿ Ï‡ÏÏŽÎ¼Î± Ï€Î¿Ï… Î²Î»Î­Ï€ÎµÏ„Îµ ÏƒÏ„Î·Î½ Î¿Î¸ÏŒÎ½Î· ÏƒÎ±Ï‚ ÎµÎ¯Î½Î±Î¹ Î±ÎºÏÎ¹Î²ÏŽÏ‚ Ï„Î¿ Ï€ÏÎ±Î³Î¼Î±Ï„Î¹ÎºÏŒ Ï‡ÏÏŽÎ¼Î± Ï„Î¿Ï… Ï€ÏÎ¿ÏŠÏŒÎ½Ï„Î¿Ï‚.",
    ];
}

function catalogJoinDescription(array $lines): string
{
    $clean = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line !== '') {
            $clean[] = $line;
        }
    }
    return implode("\n\n", $clean);
}

function catalogNormalizeText(string $value): string
{
    return str_replace(
        ['ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬', 'ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬', 'Ã¢â€šÂ¬'],
        ['â‚¬', 'â‚¬', 'â‚¬'],
        $value
    );
}

$plushWarningEn = catalogBuildPlushieWarningsEn();
$plushWarningGr = catalogBuildPlushieWarningsGr();
$blanketDisclaimerEn = $plushWarningEn[1];
$blanketDisclaimerGr = $plushWarningGr[1];

$catalog = [
    [
        'sku' => 'ATH-REAL-CHICK-HAT',
        'folder' => '1.CHICK WITH HAT',
        'images' => [
            '60.DSC_0606.jpg',
            '60.DSC_0593.jpg',
            '60.DSC_0607.jpg',
            '60.DSC_0608.jpg',
        ],
        'name_en' => 'Chick with Hat Plushie',
        'name_gr' => 'ÎšÎ¿Ï„Î¿Ï€Î¿Ï…Î»Î¬ÎºÎ¹ ÎœÎµ ÎšÎ±Ï€Î­Î»Î¿',
        'description_en' => catalogJoinDescription([
            'Meet an adorable handmade chick plushie with a tiny hat, a soft velvet texture, and a cheerful handmade look.',
            'Perfect for gifting, nursery shelves, desk decor, or anyone who loves cute crochet companions.',
            'Finished in the warm yellow-and-burgundy colourway shown in the photos.',
            'Material: Velvet yarn.',
            ...$plushWarningEn,
        ]),
        'description_gr' => catalogJoinDescription([
            'Î“Î½Ï‰ÏÎ¯ÏƒÏ„Îµ Î­Î½Î± Î±Î¾Î¹Î¿Î»Î¬Ï„ÏÎµÏ…Ï„Î¿ Ï‡ÎµÎ¹ÏÎ¿Ï€Î¿Î¯Î·Ï„Î¿ ÎºÎ¿Ï„Î¿Ï€Î¿Ï…Î»Î¬ÎºÎ¹ Î¼Îµ Î¼Î¹ÎºÏÏŒ ÎºÎ±Ï€Î­Î»Î¿, Î²ÎµÎ»Î¿ÏÎ´Î¹Î½Î· Ï…Ï†Î® ÎºÎ±Î¹ Î³Î»Ï…ÎºÎ¹Î¬ Ï‡ÎµÎ¹ÏÎ¿Ï€Î¿Î¯Î·Ï„Î· ÎµÎ¼Ï†Î¬Î½Î¹ÏƒÎ·.',
            'Î™Î´Î±Î½Î¹ÎºÏŒ Î³Î¹Î± Î´ÏŽÏÎ¿, Ï€Î±Î¹Î´Î¹ÎºÏŒ Î´Ï‰Î¼Î¬Ï„Î¹Î¿, Î´Î¹Î±ÎºÏŒÏƒÎ¼Î·ÏƒÎ· Î³ÏÎ±Ï†ÎµÎ¯Î¿Ï… Î® Î³Î¹Î± ÏŒÏ€Î¿Î¹Î¿Î½ Î±Î³Î±Ï€Î¬ Ï„Î± Ï‡Î±ÏÎ¹Ï„Ï‰Î¼Î­Î½Î± crochet Î»Î¿ÏÏ„ÏÎ¹Î½Î±.',
            'Î•Ï€Î¹Î»Î¿Î³Î® Ï€ÏÎ¿ÏƒÎ±ÏÎ¼Î¿Î³Î®Ï‚: Ï„Î¿ Ï‡ÏÏŽÎ¼Î± ÏƒÏ„Î¿ ÎºÎ±Ï€Î­Î»Î¿ Î¼Ï€Î¿ÏÎµÎ¯ Î½Î± Î±Î»Î»Î¬Î¾ÎµÎ¹ ÎºÎ±Ï„ÏŒÏ€Î¹Î½ ÏƒÏ…Î½ÎµÎ½Î½ÏŒÎ·ÏƒÎ·Ï‚.',
            'Î¥Î»Î¹ÎºÏŒ: ÎÎ®Î¼Î± velvet.',
            ...$plushWarningGr,
        ]),
        'price' => 10.00,
        'cost' => 4.00,
        'inventory' => 5,
        'status' => 'active',
        'category' => 'Plushies',
        'custom_color_fields' => 1,
        'custom_color_label_1' => 'Hat colour',
        'custom_color_label_1_gr' => 'Î§ÏÏŽÎ¼Î± Î³Î¹Î± Ï„Î¿ ÎºÎ±Ï€Î­Î»Î¿',
        'custom_color_help' => 'Choose the hat colour you would like and Athina will crochet it to match your request.',
        'custom_color_help_gr' => 'Î”Î¹Î±Î»Î­Î¾Ï„Îµ Ï„Î¿ Ï‡ÏÏŽÎ¼Î± Ï€Î¿Ï… Î¸Î­Î»ÎµÏ„Îµ Î³Î¹Î± Ï„Î¿ ÎºÎ±Ï€Î­Î»Î¿ ÎºÎ±Î¹ Î· Î‘Î¸Î·Î½Î¬ Î¸Î± Ï„Î¿ Ï€Î»Î­Î¾ÎµÎ¹ ÏƒÏÎ¼Ï†Ï‰Î½Î± Î¼Îµ Ï„Î·Î½ Ï€ÏÎ¿Ï„Î¯Î¼Î·ÏƒÎ® ÏƒÎ±Ï‚.',
        'description_en' => catalogJoinDescription([
            'Meet an adorable handmade chick plushie with a tiny hat, a soft velvet texture, and a cheerful handmade look.',
            'Perfect for gifting, nursery shelves, desk decor, or anyone who loves cute crochet companions.',
            'Finished in the warm yellow-and-burgundy colourway shown in the photos.',
            'Material: Velvet yarn.',
            ...$plushWarningEn,
        ]),
        'custom_color_fields' => 0,
        'custom_color_label_1' => '',
        'custom_color_label_1_gr' => '',
        'custom_color_help' => '',
        'custom_color_help_gr' => '',
        'selling_fast' => 0,
        'manual_sales' => 6,
    ],
    [
        'sku' => 'ATH-REAL-OCTOPUS',
        'folder' => '2.OCTOPUS',
        'images' => [
            '95.DSC_0088 (2).jpg',
            '95.DSC_0090 (2).jpg',
            '95.DSC_0092 (2).jpg',
            '95.DSC_0095 (2).jpg',
        ],
        'name_en' => 'Velvet Octopus Plushie',
        'name_gr' => 'Î§Ï„Î±Ï€Î¿Î´Î¬ÎºÎ¹ Velvet',
        'description_en' => catalogJoinDescription([
            'A super soft handmade octopus plushie designed to feel cuddly, playful, and full of character.',
            'A lovely gift for sea-life fans, crochet collectors, or a cozy corner in a child\'s room.',
            'Custom option: your preferred colour can be requested.',
            'Material: Velvet yarn.',
            ...$plushWarningEn,
        ]),
        'description_gr' => catalogJoinDescription([
            'ÎˆÎ½Î± Ï…Ï€ÎµÏ-Î¼Î±Î»Î±ÎºÏŒ Ï‡ÎµÎ¹ÏÎ¿Ï€Î¿Î¯Î·Ï„Î¿ Ï‡Ï„Î±Ï€Î¿Î´Î¬ÎºÎ¹, ÏƒÏ‡ÎµÎ´Î¹Î±ÏƒÎ¼Î­Î½Î¿ Î³Î¹Î± Î±Î³ÎºÎ±Î»Î¹Î­Ï‚, Ï€Î±Î¹Ï‡Î½Î¯Î´Î¹ ÎºÎ±Î¹ Ï€Î¿Î»Î»Î® Ï‡Î±ÏÎ¹Ï„Ï‰Î¼Î­Î½Î· Ï€ÏÎ¿ÏƒÏ‰Ï€Î¹ÎºÏŒÏ„Î·Ï„Î±.',
            'Î¥Ï€Î­ÏÎ¿Ï‡Î· ÎµÏ€Î¹Î»Î¿Î³Î® Î³Î¹Î± Î»Î¬Ï„ÏÎµÎ¹Ï‚ Ï„Î·Ï‚ Î¸Î¬Î»Î±ÏƒÏƒÎ±Ï‚, ÏƒÏ…Î»Î»Î­ÎºÏ„ÎµÏ‚ Ï‡ÎµÎ¹ÏÎ¿Ï€Î¿Î¯Î·Ï„Ï‰Î½ Î® Î³Î¹Î± Î¼Î¹Î± Î¶ÎµÏƒÏ„Î® Î³Ï‰Î½Î¹Î¬ ÏƒÎµ Ï€Î±Î¹Î´Î¹ÎºÏŒ Î´Ï‰Î¼Î¬Ï„Î¹Î¿.',
            'Î•Ï€Î¹Î»Î¿Î³Î® Ï€ÏÎ¿ÏƒÎ±ÏÎ¼Î¿Î³Î®Ï‚: Î¼Ï€Î¿ÏÎµÎ¯Ï„Îµ Î½Î± Î¶Î·Ï„Î®ÏƒÎµÏ„Îµ Ï„Î¿ Ï‡ÏÏŽÎ¼Î± Ï€Î¿Ï… Ï€ÏÎ¿Ï„Î¹Î¼Î¬Ï„Îµ.',
            'Î¥Î»Î¹ÎºÏŒ: ÎÎ®Î¼Î± velvet.',
            ...$plushWarningGr,
        ]),
        'price' => 10.00,
        'cost' => 4.00,
        'inventory' => 4,
        'status' => 'active',
        'category' => 'Plushies',
        'custom_color_fields' => 1,
        'custom_color_label_1' => 'Preferred octopus colour',
        'custom_color_label_1_gr' => 'Î§ÏÏŽÎ¼Î± Î³Î¹Î± Ï„Î¿ Ï‡Ï„Î±Ï€Î¿Î´Î¬ÎºÎ¹',
        'custom_color_help' => 'Tell us the velvet shade you prefer for the octopus body.',
        'custom_color_help_gr' => 'Î ÎµÎ¯Ï„Îµ Î¼Î±Ï‚ Ï€Î¿Î¹Î± Î²ÎµÎ»Î¿ÏÎ´Î¹Î½Î· Î±Ï€ÏŒÏ‡ÏÏ‰ÏƒÎ· Ï€ÏÎ¿Ï„Î¹Î¼Î¬Ï„Îµ Î³Î¹Î± Ï„Î¿ ÏƒÏŽÎ¼Î± Ï„Î¿Ï… Ï‡Ï„Î±Ï€Î¿Î´Î¹Î¿Ï.',
        'description_en' => catalogJoinDescription([
            'A super soft handmade octopus plushie designed to feel cuddly, playful, and full of character.',
            'A lovely gift for sea-life fans, crochet collectors, or a cozy corner in a child\'s room.',
            'Choose from the ready-made velvet colourways shown in the gallery.',
            'Material: Velvet yarn.',
            ...$plushWarningEn,
        ]),
        'custom_color_fields' => 0,
        'custom_color_label_1' => 'Selected colour',
        'custom_color_label_1_gr' => 'ÃŽâ€¢Ãâ‚¬ÃŽÂ¹ÃŽÂ»ÃŽÂµÃŽÂ³ÃŽÂ¼ÃŽÂ­ÃŽÂ½ÃŽÂ¿ Ãâ€¡ÃÂÃÅ½ÃŽÂ¼ÃŽÂ±',
        'custom_color_help' => '',
        'custom_color_help_gr' => '',
        'color_options' => [
            [
                'name' => 'Snow White',
                'images' => ['95.DSC_0090 (2).jpg'],
            ],
            [
                'name' => 'Blush Pink',
                'images' => ['95.DSC_0092 (2).jpg'],
            ],
            [
                'name' => 'Peach Sorbet',
                'images' => ['95.DSC_0095 (2).jpg'],
            ],
            [
                'name' => 'Seafoam',
                'images' => ['93.(2).jpg'],
            ],
            [
                'name' => 'Lavender Pop',
                'images' => ['93.(2).jpg'],
            ],
        ],
        'selling_fast' => 0,
        'manual_sales' => 5,
    ],
    [
        'sku' => 'ATH-REAL-BEE',
        'folder' => '3.BEE',
        'images' => [
            '97.DSC_0059.jpg',
            '97.DSC_0064.jpg',
            '98.DSC_0044.jpg',
            '98.DSC_0049.jpg',
        ],
        'name_en' => 'Bumble Bee Plushie',
        'name_gr' => 'ÎœÎµÎ»Î¹ÏƒÏƒÎ¬ÎºÎ¹ Plushie',
        'description_en' => catalogJoinDescription([
            'This handmade bumble bee plushie brings a bright, happy feel with its soft velvet finish and playful shape.',
            'It makes a sweet handmade gift and a cozy little companion for shelves, beds, or cuddle time.',
            'Custom option: colour 1 and colour 2 can be adjusted on request.',
            'Material: Velvet yarn.',
            ...$plushWarningEn,
        ]),
        'description_gr' => catalogJoinDescription([
            'Î‘Ï…Ï„ÏŒ Ï„Î¿ Ï‡ÎµÎ¹ÏÎ¿Ï€Î¿Î¯Î·Ï„Î¿ Î¼ÎµÎ»Î¹ÏƒÏƒÎ¬ÎºÎ¹ Ï‡Î±ÏÎ¯Î¶ÎµÎ¹ Ï‡Î±ÏÎ¿ÏÎ¼ÎµÎ½Î· Î´Î¹Î¬Î¸ÎµÏƒÎ· Î¼Îµ Ï„Î· Î²ÎµÎ»Î¿ÏÎ´Î¹Î½Î· Ï…Ï†Î® Ï„Î¿Ï… ÎºÎ±Î¹ Ï„Î¿ Ï€Î±Î¹Ï‡Î½Î¹Î´Î¹Î¬ÏÎ¹ÎºÎ¿ ÏƒÏ‡Î®Î¼Î± Ï„Î¿Ï….',
            'Î•Î¯Î½Î±Î¹ Î¼Î¹Î± Î³Î»Ï…ÎºÎ¹Î¬ Ï‡ÎµÎ¹ÏÎ¿Ï€Î¿Î¯Î·Ï„Î· Î¹Î´Î­Î± Î³Î¹Î± Î´ÏŽÏÎ¿ ÎºÎ±Î¹ Î­Î½Î±Ï‚ ÏŒÎ¼Î¿ÏÏ†Î¿Ï‚ Î¼Î¹ÎºÏÏŒÏ‚ ÏƒÏÎ½Ï„ÏÎ¿Ï†Î¿Ï‚ Î³Î¹Î± ÏÎ¬Ï†Î¹Î±, ÎºÏÎµÎ²Î¬Ï„Î¹Î± Î® Î±Î³ÎºÎ±Î»Î¹Î­Ï‚.',
            'Î•Ï€Î¹Î»Î¿Î³Î® Ï€ÏÎ¿ÏƒÎ±ÏÎ¼Î¿Î³Î®Ï‚: Î¼Ï€Î¿ÏÎµÎ¯Ï„Îµ Î½Î± Î±Î»Î»Î¬Î¾ÎµÏ„Îµ Ï„Î¿ Ï‡ÏÏŽÎ¼Î± 1 ÎºÎ±Î¹ Ï„Î¿ Ï‡ÏÏŽÎ¼Î± 2 ÎºÎ±Ï„ÏŒÏ€Î¹Î½ ÏƒÏ…Î½ÎµÎ½Î½ÏŒÎ·ÏƒÎ·Ï‚.',
            'Î¥Î»Î¹ÎºÏŒ: ÎÎ®Î¼Î± velvet.',
            ...$plushWarningGr,
        ]),
        'price' => 20.00,
        'cost' => 8.00,
        'inventory' => 3,
        'status' => 'low_stock',
        'category' => 'Plushies',
        'custom_color_fields' => 2,
        'custom_color_label_1' => 'Primary colour',
        'custom_color_label_2' => 'Secondary colour',
        'custom_color_label_1_gr' => 'Î§ÏÏŽÎ¼Î± 1',
        'custom_color_label_2_gr' => 'Î§ÏÏŽÎ¼Î± 2',
        'custom_color_help' => 'Choose the two velvet colours you want Athina to combine for the bee.',
        'custom_color_help_gr' => 'Î”Î¹Î±Î»Î­Î¾Ï„Îµ Ï„Î± Î´ÏÎ¿ Î²ÎµÎ»Î¿ÏÎ´Î¹Î½Î± Ï‡ÏÏŽÎ¼Î±Ï„Î± Ï€Î¿Ï… Î¸Î­Î»ÎµÏ„Îµ Î½Î± ÏƒÏ…Î½Î´Ï…Î±ÏƒÏ„Î¿ÏÎ½ Î³Î¹Î± Ï„Î¿ Î¼ÎµÎ»Î¹ÏƒÏƒÎ¬ÎºÎ¹.',
        'description_en' => catalogJoinDescription([
            'This handmade bumble bee plushie brings a bright, happy feel with its soft velvet finish and playful shape.',
            'It makes a sweet handmade gift and a cozy little companion for shelves, beds, or cuddle time.',
            'Choose your favourite ready-made bee colourway from the gallery.',
            'Material: Velvet yarn.',
            ...$plushWarningEn,
        ]),
        'custom_color_fields' => 0,
        'custom_color_label_1' => 'Selected colourway',
        'custom_color_label_2' => '',
        'custom_color_label_1_gr' => 'ÃŽâ€¢Ãâ‚¬ÃŽÂ¹ÃŽÂ»ÃŽÂµÃŽÂ³ÃŽÂ¼ÃŽÂ­ÃŽÂ½ÃŽÂ¿Ãâ€š Ãâ€¡ÃÂÃâ€°ÃŽÂ¼ÃŽÂ±Ãâ€žÃŽÂ¹ÃŽÂºÃÅ’Ãâ€š ÃÆ’Ãâ€¦ÃŽÂ½ÃŽÂ´Ãâ€¦ÃŽÂ±ÃÆ’ÃŽÂ¼ÃÅ’Ãâ€š',
        'custom_color_label_2_gr' => '',
        'custom_color_help' => '',
        'custom_color_help_gr' => '',
        'color_options' => [
            [
                'name' => 'Sunshine Yellow',
                'images' => ['97.DSC_0064.jpg'],
            ],
            [
                'name' => 'Honey Blend',
                'images' => ['98.DSC_0044.jpg'],
            ],
            [
                'name' => 'Bluebell',
                'images' => ['97.DSC_0059.jpg'],
            ],
            [
                'name' => 'Sugar Pink',
                'images' => ['98.DSC_0049.jpg'],
            ],
            [
                'name' => 'Lavender Mist',
                'images' => ['97.DSC_0064.jpg'],
            ],
        ],
        'selling_fast' => 1,
        'manual_sales' => 9,
    ],
    [
        'sku' => 'ATH-REAL-WHALE',
        'folder' => '4.WHALE',
        'images' => [
            'MEDIUM/27.DSC_0424.jpg',
            'LARGE/IMG_0490.jpg',
            'MEDIUM/29.DSC_0428.jpg',
            'SMALL/58.DSC_0611.jpg',
        ],
        'name_en' => 'Velvet Whale Plushie',
        'name_gr' => 'Î¦Î±Î»Î±Î¹Î½Î¬ÎºÎ¹ Velvet',
        'description_en' => catalogJoinDescription([
            'A super soft handmade whale plushie with a calming ocean feel and a cuddly velvet texture.',
            'A beautiful handmade gift for sea-life lovers and a charming decor piece for nursery or bedroom shelves.',
            'Available sizes and prices: Small â‚¬5, Medium â‚¬20, Large â‚¬50.',
            'Custom option: choose colour 1 and your preferred size.',
            'Material: Velvet yarn.',
            ...$plushWarningEn,
        ]),
        'description_gr' => catalogJoinDescription([
            'ÎˆÎ½Î± Ï…Ï€ÎµÏ-Î¼Î±Î»Î±ÎºÏŒ Ï‡ÎµÎ¹ÏÎ¿Ï€Î¿Î¯Î·Ï„Î¿ Ï†Î±Î»Î±Î¹Î½Î¬ÎºÎ¹ Î¼Îµ Î®ÏÎµÎ¼Î· Î¸Î±Î»Î±ÏƒÏƒÎ¹Î½Î® Î±Î¹ÏƒÎ¸Î·Ï„Î¹ÎºÎ® ÎºÎ±Î¹ Î²ÎµÎ»Î¿ÏÎ´Î¹Î½Î· Ï…Ï†Î® Î³Î¹Î± Î±Î³ÎºÎ±Î»Î¹Î­Ï‚.',
            'Î¥Ï€Î­ÏÎ¿Ï‡Î¿ Ï‡ÎµÎ¹ÏÎ¿Ï€Î¿Î¯Î·Ï„Î¿ Î´ÏŽÏÎ¿ Î³Î¹Î± Î»Î¬Ï„ÏÎµÎ¹Ï‚ Ï„Î·Ï‚ Î¸Î¬Î»Î±ÏƒÏƒÎ±Ï‚ ÎºÎ±Î¹ ÏŒÎ¼Î¿ÏÏ†Î· Î´Î¹Î±ÎºÎ¿ÏƒÎ¼Î·Ï„Î¹ÎºÎ® Ï€Î¹Î½ÎµÎ»Î¹Î¬ Î³Î¹Î± Ï€Î±Î¹Î´Î¹ÎºÏŒ Î® Ï…Ï€Î½Î¿Î´Ï‰Î¼Î¬Ï„Î¹Î¿.',
            'Î”Î¹Î±Î¸Î­ÏƒÎ¹Î¼Î± Î¼ÎµÎ³Î­Î¸Î· ÎºÎ±Î¹ Ï„Î¹Î¼Î­Ï‚: Small â‚¬5, Medium â‚¬20, Large â‚¬50.',
            'Î•Ï€Î¹Î»Î¿Î³Î® Ï€ÏÎ¿ÏƒÎ±ÏÎ¼Î¿Î³Î®Ï‚: Î¼Ï€Î¿ÏÎµÎ¯Ï„Îµ Î½Î± Î´Î¹Î±Î»Î­Î¾ÎµÏ„Îµ Ï„Î¿ Ï‡ÏÏŽÎ¼Î± 1 ÎºÎ±Î¹ Ï„Î¿ Î¼Î­Î³ÎµÎ¸Î¿Ï‚ Ï€Î¿Ï… Ï€ÏÎ¿Ï„Î¹Î¼Î¬Ï„Îµ.',
            'Î¥Î»Î¹ÎºÏŒ: ÎÎ®Î¼Î± velvet.',
            ...$plushWarningGr,
        ]),
        'price' => 5.00,
        'cost' => 9.00,
        'inventory' => 4,
        'status' => 'active',
        'category' => 'Plushies',
        'custom_color_fields' => 1,
        'custom_color_label_1' => 'Colour 1',
        'custom_color_label_1_gr' => 'Î§ÏÏŽÎ¼Î± 1',
        'custom_color_help' => 'Choose the main velvet colour you want for the whale. The photos update by size.',
        'custom_color_help_gr' => 'Î”Î¹Î±Î»Î­Î¾Ï„Îµ Ï„Î¿ Î²Î±ÏƒÎ¹ÎºÏŒ Î²ÎµÎ»Î¿ÏÎ´Î¹Î½Î¿ Ï‡ÏÏŽÎ¼Î± Ï€Î¿Ï… Î¸Î­Î»ÎµÏ„Îµ Î³Î¹Î± Ï„Î· Ï†Î¬Î»Î±Î¹Î½Î±. ÎŸÎ¹ Ï†Ï‰Ï„Î¿Î³ÏÎ±Ï†Î¯ÎµÏ‚ Î±Î»Î»Î¬Î¶Î¿Ï…Î½ Î±Î½Î¬Î»Î¿Î³Î± Î¼Îµ Ï„Î¿ Î¼Î­Î³ÎµÎ¸Î¿Ï‚.',
        'variations' => [
            [
                'size' => 'Small',
                'price' => 5.00,
                'inventory' => 2,
                'images' => [
                    'SMALL/58.DSC_0611.jpg',
                    'SMALL/58.DSC_0612.jpg',
                    'SMALL/59.DSC_0594.jpg',
                ],
            ],
            [
                'size' => 'Medium',
                'price' => 20.00,
                'inventory' => 1,
                'images' => [
                    'MEDIUM/27.DSC_0424.jpg',
                    'MEDIUM/29.DSC_0428.jpg',
                ],
            ],
            [
                'size' => 'Large',
                'price' => 50.00,
                'inventory' => 1,
                'images' => [
                    'LARGE/IMG_0490.jpg',
                ],
            ],
        ],
        'description_en' => catalogJoinDescription([
            'A super soft handmade whale plushie with a calming ocean feel and a cuddly velvet texture.',
            'A beautiful handmade gift for sea-life lovers and a charming decor piece for nursery or bedroom shelves.',
            'Available sizes and prices: Small Ã¢â€šÂ¬5, Medium Ã¢â€šÂ¬20, Large Ã¢â€šÂ¬50.',
            'Choose your preferred size and pick one of the pictured whale colourways.',
            'Material: Velvet yarn.',
            ...$plushWarningEn,
        ]),
        'custom_color_fields' => 0,
        'custom_color_label_1' => 'Selected colour',
        'custom_color_label_1_gr' => 'ÃŽâ€¢Ãâ‚¬ÃŽÂ¹ÃŽÂ»ÃŽÂµÃŽÂ³ÃŽÂ¼ÃŽÂ­ÃŽÂ½ÃŽÂ¿ Ãâ€¡ÃÂÃÅ½ÃŽÂ¼ÃŽÂ±',
        'custom_color_help' => '',
        'custom_color_help_gr' => '',
        'color_options' => [
            [
                'name' => 'Blush Pink',
                'images' => ['SMALL/58.DSC_0611.jpg'],
            ],
            [
                'name' => 'Lemon Yellow',
                'images' => ['SMALL/58.DSC_0612.jpg'],
            ],
            [
                'name' => 'Deep Plum',
                'images' => ['MEDIUM/29.DSC_0428.jpg'],
            ],
        ],
        'selling_fast' => 0,
        'manual_sales' => 4,
    ],
    [
        'sku' => 'ATH-REAL-SPONGEBOB',
        'folder' => '5.SPONGEBOB',
        'images' => [
            '48.IMG_20240413_114032.jpg',
            '48.IMG_20240413_114058.jpg',
        ],
        'name_en' => 'SpongeBob Crochet Plushie',
        'name_gr' => 'Î›Î¿ÏÏ„ÏÎ¹Î½Î¿ SpongeBob',
        'description_en' => catalogJoinDescription([
            'A handmade SpongeBob-inspired crochet plushie with bold details, soft texture, and lots of character.',
            'Perfect for cartoon fans, themed gifts, or anyone who wants a fun handmade statement piece.',
            'Material: Velvet yarn.',
            ...$plushWarningEn,
        ]),
        'description_gr' => catalogJoinDescription([
            'ÎˆÎ½Î± Ï‡ÎµÎ¹ÏÎ¿Ï€Î¿Î¯Î·Ï„Î¿ Î»Î¿ÏÏ„ÏÎ¹Î½Î¿ ÎµÎ¼Ï€Î½ÎµÏ…ÏƒÎ¼Î­Î½Î¿ Î±Ï€ÏŒ Ï„Î¿Î½ SpongeBob, Î¼Îµ Î­Î½Ï„Î¿Î½ÎµÏ‚ Î»ÎµÏ€Ï„Î¿Î¼Î­ÏÎµÎ¹ÎµÏ‚, Î±Ï€Î±Î»Î® Ï…Ï†Î® ÎºÎ±Î¹ Ï€Î¿Î»Ï Ï‡Î±ÏÎ±ÎºÏ„Î®ÏÎ±.',
            'Î™Î´Î±Î½Î¹ÎºÏŒ Î³Î¹Î± fans ÎºÎ±ÏÏ„Î¿ÏÎ½, Î¸ÎµÎ¼Î±Ï„Î¹ÎºÎ¬ Î´ÏŽÏÎ± Î® Î³Î¹Î± ÏŒÏ€Î¿Î¹Î¿Î½ Î¸Î­Î»ÎµÎ¹ Î­Î½Î± Î¾ÎµÏ‡Ï‰ÏÎ¹ÏƒÏ„ÏŒ Ï‡ÎµÎ¹ÏÎ¿Ï€Î¿Î¯Î·Ï„Î¿ ÎºÎ¿Î¼Î¼Î¬Ï„Î¹.',
            'Î¥Î»Î¹ÎºÏŒ: ÎÎ®Î¼Î± velvet.',
            ...$plushWarningGr,
        ]),
        'price' => 12.00,
        'cost' => 5.00,
        'inventory' => 2,
        'status' => 'low_stock',
        'category' => 'Characters',
        'selling_fast' => 0,
        'manual_sales' => 3,
    ],
    [
        'sku' => 'ATH-REAL-PATRICK',
        'folder' => '6.PATRIC',
        'images' => [
            '22.DSC_0419.jpg',
        ],
        'name_en' => 'Patrick Star Crochet Plushie',
        'name_gr' => 'Î›Î¿ÏÏ„ÏÎ¹Î½Î¿Ï‚ Patrick Star',
        'description_en' => catalogJoinDescription([
            'A soft handmade Patrick-inspired plushie with a playful shape and a cozy crochet finish.',
            'Great for cartoon lovers, themed gifts, or adding a bit of fun personality to a handmade collection.',
            'Material: Velvet yarn.',
            ...$plushWarningEn,
        ]),
        'description_gr' => catalogJoinDescription([
            'ÎˆÎ½Î± Î±Ï€Î±Î»ÏŒ Ï‡ÎµÎ¹ÏÎ¿Ï€Î¿Î¯Î·Ï„Î¿ Î»Î¿ÏÏ„ÏÎ¹Î½Î¿ ÎµÎ¼Ï€Î½ÎµÏ…ÏƒÎ¼Î­Î½Î¿ Î±Ï€ÏŒ Ï„Î¿Î½ Patrick, Î¼Îµ Ï€Î±Î¹Ï‡Î½Î¹Î´Î¹Î¬ÏÎ¹ÎºÎ¿ ÏƒÏ‡Î®Î¼Î± ÎºÎ±Î¹ cozy crochet Ï„ÎµÎ»ÎµÎ¯Ï‰Î¼Î±.',
            'Î¤Î±Î¹ÏÎ¹Î¬Î¶ÎµÎ¹ Ï…Ï€Î­ÏÎ¿Ï‡Î± ÏƒÎµ fans ÎºÎ±ÏÏ„Î¿ÏÎ½, Î¸ÎµÎ¼Î±Ï„Î¹ÎºÎ¬ Î´ÏŽÏÎ± Î® ÏƒÎµ Î¼Î¹Î± Î¾ÎµÏ‡Ï‰ÏÎ¹ÏƒÏ„Î® ÏƒÏ…Î»Î»Î¿Î³Î® Ï‡ÎµÎ¹ÏÎ¿Ï€Î¿Î¯Î·Ï„Ï‰Î½.',
            'Î¥Î»Î¹ÎºÏŒ: ÎÎ®Î¼Î± velvet.',
            ...$plushWarningGr,
        ]),
        'price' => 12.00,
        'cost' => 5.00,
        'inventory' => 2,
        'status' => 'low_stock',
        'category' => 'Characters',
        'selling_fast' => 0,
        'manual_sales' => 2,
    ],
    [
        'sku' => 'ATH-REAL-FROG-LEGS',
        'folder' => '7.FROG WITH LEGS',
        'images' => [
            '61.DSC_0534.jpg',
            '61.DSC_0539.jpg',
            '61.InShot_20240613_101456931.jpg',
        ],
        'name_en' => 'Frog with Dangly Legs Plushie',
        'name_gr' => 'Î’Î¬Ï„ÏÎ±Ï‡Î¿Ï‚ ÎœÎµ Î Î¿Î´Î±ÏÎ¬ÎºÎ¹Î±',
        'description_en' => catalogJoinDescription([
            'A handmade frog plushie with extra-long dangling legs, soft velvet texture, and a playful expression.',
            'Lovely as a cheerful gift, shelf buddy, or cuddle companion for anyone who loves whimsical handmade toys.',
            'Custom option: colour 1 can be adjusted on request.',
            'Material: Velvet yarn.',
            ...$plushWarningEn,
        ]),
        'description_gr' => catalogJoinDescription([
            'ÎˆÎ½Î±Ï‚ Ï‡ÎµÎ¹ÏÎ¿Ï€Î¿Î¯Î·Ï„Î¿Ï‚ Î²Î¬Ï„ÏÎ±Ï‡Î¿Ï‚ Î¼Îµ Î¼Î±ÎºÏÎ¹Î¬ Ï€Î¿Î´Î±ÏÎ¬ÎºÎ¹Î±, Î±Ï€Î±Î»Î® Î²ÎµÎ»Î¿ÏÎ´Î¹Î½Î· Ï…Ï†Î® ÎºÎ±Î¹ Ï€Î±Î¹Ï‡Î½Î¹Î´Î¹Î¬ÏÎ¹ÎºÎ· Î­ÎºÏ†ÏÎ±ÏƒÎ·.',
            'Î™Î´Î±Î½Î¹ÎºÏŒÏ‚ Î³Î¹Î± Ï‡Î±ÏÎ¿ÏÎ¼ÎµÎ½Î¿ Î´ÏŽÏÎ¿, Î³Î¹Î± ÏÎ¬Ï†Î¹ Î® Î³Î¹Î± Î±Î³ÎºÎ±Î»Î¹Î­Ï‚ Î±Ï€ÏŒ ÏŒÏƒÎ¿Ï…Ï‚ Î±Î³Î±Ï€Î¿ÏÎ½ Ï„Î± whimsical Ï‡ÎµÎ¹ÏÎ¿Ï€Î¿Î¯Î·Ï„Î± Î»Î¿ÏÏ„ÏÎ¹Î½Î±.',
            'Î•Ï€Î¹Î»Î¿Î³Î® Ï€ÏÎ¿ÏƒÎ±ÏÎ¼Î¿Î³Î®Ï‚: Ï„Î¿ Ï‡ÏÏŽÎ¼Î± 1 Î¼Ï€Î¿ÏÎµÎ¯ Î½Î± Î±Î»Î»Î¬Î¾ÎµÎ¹ ÎºÎ±Ï„ÏŒÏ€Î¹Î½ ÏƒÏ…Î½ÎµÎ½Î½ÏŒÎ·ÏƒÎ·Ï‚.',
            'Î¥Î»Î¹ÎºÏŒ: ÎÎ®Î¼Î± velvet.',
            ...$plushWarningGr,
        ]),
        'price' => 12.00,
        'cost' => 5.00,
        'inventory' => 4,
        'status' => 'active',
        'category' => 'Plushies',
        'custom_color_fields' => 1,
        'custom_color_label_1' => 'Frog colour',
        'custom_color_label_1_gr' => 'Î§ÏÏŽÎ¼Î± Î³Î¹Î± Ï„Î¿Î½ Î²Î¬Ï„ÏÎ±Ï‡Î¿',
        'custom_color_help' => 'Choose the main velvet colour you would like for the frog.',
        'custom_color_help_gr' => 'Î”Î¹Î±Î»Î­Î¾Ï„Îµ Ï„Î¿ Î²Î±ÏƒÎ¹ÎºÏŒ Î²ÎµÎ»Î¿ÏÎ´Î¹Î½Î¿ Ï‡ÏÏŽÎ¼Î± Ï€Î¿Ï… Î¸Î­Î»ÎµÏ„Îµ Î³Î¹Î± Ï„Î¿Î½ Î²Î¬Ï„ÏÎ±Ï‡Î¿.',
        'description_en' => catalogJoinDescription([
            'A handmade frog plushie with extra-long dangling legs, soft velvet texture, and a playful expression.',
            'Lovely as a cheerful gift, shelf buddy, or cuddle companion for anyone who loves whimsical handmade toys.',
            'Available in the playful frog colourways shown in the gallery.',
            'Material: Velvet yarn.',
            ...$plushWarningEn,
        ]),
        'custom_color_fields' => 0,
        'custom_color_label_1' => 'Selected colour',
        'custom_color_label_1_gr' => 'ÃŽâ€¢Ãâ‚¬ÃŽÂ¹ÃŽÂ»ÃŽÂµÃŽÂ³ÃŽÂ¼ÃŽÂ­ÃŽÂ½ÃŽÂ¿ Ãâ€¡ÃÂÃÅ½ÃŽÂ¼ÃŽÂ±',
        'custom_color_help' => '',
        'custom_color_help_gr' => '',
        'color_options' => [
            [
                'name' => 'Berry Plum',
                'images' => ['61.DSC_0539.jpg'],
            ],
            [
                'name' => 'Soft Lilac',
                'images' => ['61.DSC_0534.jpg'],
            ],
            [
                'name' => 'Forest Sage',
                'images' => ['61.InShot_20240613_101456931.jpg'],
            ],
        ],
        'selling_fast' => 1,
        'manual_sales' => 7,
    ],
    [
        'sku' => 'ATH-REAL-BLANKETS',
        'folder' => '8.BLANKETS',
        'images' => [
            'IMG_20250218_125755.jpg',
            '33.IMG_20240227_143556.jpg',
            'IMG_1586.JPG',
            'IMG_1588.JPG',
        ],
        'name_en' => 'Puffy Kids Blanket',
        'name_gr' => 'Î Î±Î¹Î´Î¹ÎºÎ® Puffy ÎšÎ¿Ï…Î²Î­ÏÏ„Î±',
        'description_en' => catalogJoinDescription([
            'A super-soft handmade kids blanket made with puffy yarn for cozy cuddles, stroller walks, nap time, and sweet dreams.',
            'Available sizes and prices: Newborn Blanket (45x45cm) â‚¬20, Receiving Blanket (110x110cm) â‚¬30, Stroller Blanket (95x75cm) â‚¬50, Crib Blanket (150x110cm) â‚¬70.',
            'Available in beautiful colour combinations and ideal as a thoughtful baby gift.',
            'Material: Puffy yarn.',
            $blanketDisclaimerEn,
        ]),
        'description_gr' => catalogJoinDescription([
            'ÎœÎ¹Î± Ï€Î¿Î»Ï Î¼Î±Î»Î±ÎºÎ® Ï‡ÎµÎ¹ÏÎ¿Ï€Î¿Î¯Î·Ï„Î· Ï€Î±Î¹Î´Î¹ÎºÎ® ÎºÎ¿Ï…Î²Î­ÏÏ„Î± Î±Ï€ÏŒ puffy Î½Î®Î¼Î±, Î¹Î´Î±Î½Î¹ÎºÎ® Î³Î¹Î± Î±Î³ÎºÎ±Î»Î¹Î­Ï‚, Î²ÏŒÎ»Ï„ÎµÏ‚ Î¼Îµ Ï„Î¿ ÎºÎ±ÏÏŒÏ„ÏƒÎ¹, ÏÏ€Î½Î¿ ÎºÎ±Î¹ Î³Î»Ï…ÎºÎ¬ ÏŒÎ½ÎµÎ¹ÏÎ±.',
            'Î”Î¹Î±Î¸Î­ÏƒÎ¹Î¼Î± Î¼ÎµÎ³Î­Î¸Î· ÎºÎ±Î¹ Ï„Î¹Î¼Î­Ï‚: ÎšÎ¿Ï…Î²Î­ÏÏ„Î± ÎÎµÎ¿Î³Î­Î½Î½Î·Ï„Î¿Ï… (45x45ÎµÎº) â‚¬20, ÎšÎ¿Ï…Î²Î­ÏÏ„Î± Î‘Î³ÎºÎ±Î»Î¹Î¬Ï‚ (110x110ÎµÎº) â‚¬30, ÎšÎ¿Ï…Î²Î­ÏÏ„Î± ÎšÎ±ÏÎ¿Ï„ÏƒÎ¹Î¿Ï (95x75ÎµÎº) â‚¬50, ÎšÎ¿Ï…Î²Î­ÏÏ„Î± ÎšÎ¿ÏÎ½Î¹Î±Ï‚ (150x110ÎµÎº) â‚¬70.',
            'Î”Î¹Î±Î¸Î­ÏƒÎ¹Î¼Î· ÏƒÎµ ÏŒÎ¼Î¿ÏÏ†Î¿Ï…Ï‚ Ï‡ÏÏ‰Î¼Î±Ï„Î¹ÎºÎ¿ÏÏ‚ ÏƒÏ…Î½Î´Ï…Î±ÏƒÎ¼Î¿ÏÏ‚ ÎºÎ±Î¹ Î¹Î´Î±Î½Î¹ÎºÎ® Ï‰Ï‚ Ï€ÏÎ¿ÏƒÎµÎ³Î¼Î­Î½Î¿ Î´ÏŽÏÎ¿ Î³Î¹Î± Î¼Ï‰ÏÏŒ.',
            'Î¥Î»Î¹ÎºÏŒ: Puffy Î½Î®Î¼Î±.',
            $blanketDisclaimerGr,
        ]),
        'price' => 20.00,
        'cost' => 14.00,
        'inventory' => 4,
        'status' => 'active',
        'category' => 'Blankets',
        'custom_color_fields' => 1,
        'custom_color_label_1' => 'Preferred colour or colour blend',
        'custom_color_label_1_gr' => 'Î§ÏÏŽÎ¼Î± Î® ÏƒÏ…Î½Î´Ï…Î±ÏƒÎ¼ÏŒÏ‚ Ï‡ÏÏ‰Î¼Î¬Ï„Ï‰Î½',
        'custom_color_help' => 'Tell us the puffy yarn colour or blend you want for your blanket.',
        'custom_color_help_gr' => 'Î ÎµÎ¯Ï„Îµ Î¼Î±Ï‚ Ï„Î¿ Ï‡ÏÏŽÎ¼Î± Î® Ï„Î¿Î½ ÏƒÏ…Î½Î´Ï…Î±ÏƒÎ¼ÏŒ puffy Î½Î·Î¼Î¬Ï„Ï‰Î½ Ï€Î¿Ï… Î¸Î­Î»ÎµÏ„Îµ Î³Î¹Î± Ï„Î·Î½ ÎºÎ¿Ï…Î²Î­ÏÏ„Î± ÏƒÎ±Ï‚.',
        'variations' => [
            [
                'size' => 'Newborn Blanket (45x45cm)',
                'price' => 20.00,
                'inventory' => 1,
                'images' => [
                    'IMG_1586.JPG',
                ],
            ],
            [
                'size' => 'Receiving Blanket (110x110cm)',
                'price' => 30.00,
                'inventory' => 1,
                'images' => [
                    '33.IMG_20240227_143556.jpg',
                ],
            ],
            [
                'size' => 'Stroller Blanket (95x75cm)',
                'price' => 50.00,
                'inventory' => 1,
                'images' => [
                    'IMG_1588.JPG',
                ],
            ],
            [
                'size' => 'Crib Blanket (150x110cm)',
                'price' => 70.00,
                'inventory' => 1,
                'images' => [
                    'IMG_20250218_125755.jpg',
                    '33.IMG_20240227_142515-2.jpg',
                ],
            ],
        ],
        'description_en' => catalogJoinDescription([
            'A super-soft handmade kids blanket made with puffy yarn for cozy cuddles, stroller walks, nap time, and sweet dreams.',
            'Available sizes and prices: Newborn Blanket (45x45cm) Ã¢â€šÂ¬20, Receiving Blanket (110x110cm) Ã¢â€šÂ¬30, Stroller Blanket (95x75cm) Ã¢â€šÂ¬50, Crib Blanket (150x110cm) Ã¢â€šÂ¬70.',
            'Choose from the ready-made colour blends in the gallery and pair them with the blanket size that suits your little one best.',
            'Material: Puffy yarn.',
            $blanketDisclaimerEn,
        ]),
        'custom_color_fields' => 0,
        'custom_color_label_1' => 'Selected colourway',
        'custom_color_label_1_gr' => 'ÃŽâ€¢Ãâ‚¬ÃŽÂ¹ÃŽÂ»ÃŽÂµÃŽÂ³ÃŽÂ¼ÃŽÂ­ÃŽÂ½ÃŽÂ¿Ãâ€š Ãâ€¡ÃÂÃâ€°ÃŽÂ¼ÃŽÂ±Ãâ€žÃŽÂ¹ÃŽÂºÃÅ’Ãâ€š ÃÆ’Ãâ€¦ÃŽÂ½ÃŽÂ´Ãâ€¦ÃŽÂ±ÃÆ’ÃŽÂ¼ÃÅ’Ãâ€š',
        'custom_color_help' => '',
        'custom_color_help_gr' => '',
        'color_options' => [
            [
                'name' => 'Sky Mist',
                'images' => ['IMG_20250218_125755.jpg'],
            ],
            [
                'name' => 'Lavender Cloud',
                'images' => ['33.IMG_20240227_143556.jpg', '33.IMG_20240227_142515-2.jpg'],
            ],
            [
                'name' => 'Mint Frost',
                'images' => ['IMG_1586.JPG'],
            ],
            [
                'name' => 'Warm Oatmeal',
                'images' => ['IMG_1588.JPG'],
            ],
        ],
        'selling_fast' => 1,
        'manual_sales' => 8,
    ],
];

$availableCatalog = [];
$skippedCatalog = [];
foreach ($catalog as $item) {
    $mainImages = is_array($item['images'] ?? null) ? $item['images'] : [];
    if (empty($mainImages)) {
        $skippedCatalog[] = [
            'sku' => (string)($item['sku'] ?? 'unknown'),
            'reason' => 'No main images defined in catalog.',
        ];
        continue;
    }

    $folderRoot = rtrim(WEBSITE_SOURCE_ROOT, '/\\') . '/' . trim((string)($item['folder'] ?? ''), '/\\');
    $hasAnySourceImage = false;
    foreach ($mainImages as $mainImage) {
        $resolved = catalogResolveSourcePath((string)$item['sku'], $folderRoot, (string)$mainImage);
        if ($resolved !== null) {
            $hasAnySourceImage = true;
            break;
        }
    }

    if (!$hasAnySourceImage) {
        $skippedCatalog[] = [
            'sku' => (string)($item['sku'] ?? 'unknown'),
            'reason' => 'No matching local source assets were found.',
        ];
        continue;
    }

    $availableCatalog[] = $item;
}

$catalog = $availableCatalog;

catalogEnsurePhotoStorageSchema($conn);
app_product_options_ensure_schema($conn);
ensureCustomOrdersTable($conn);

$keepSkus = array_map(static fn(array $item): string => (string)$item['sku'], $catalog);

$conn->begin_transaction();

try {
    $legacySummary = catalogArchiveOrDeleteLegacyProducts($conn, $keepSkus);

    $selectExisting = $conn->prepare("SELECT productID FROM products WHERE sku = ? LIMIT 1");
    $insertProduct = $conn->prepare("
        INSERT INTO products
            (sku, nameEN, nameGR, descriptionEN, descriptionGR, basePrice, costPrice, inventory, cartStatus, category, isSellingFast, hasVariants, customColorFields, customColorLabel1, customColorLabel2, customColorLabel1GR, customColorLabel2GR, customColorHelpText, customColorHelpTextGR, privateCustomerEmail, privateAccessToken, privateLinkSentAt)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL)
    ");
    $updateProduct = $conn->prepare("
        UPDATE products
        SET nameEN = ?,
            nameGR = ?,
            descriptionEN = ?,
            descriptionGR = ?,
            basePrice = ?,
            costPrice = ?,
            inventory = ?,
            cartStatus = ?,
            category = ?,
            isSellingFast = ?,
            hasVariants = ?,
            customColorFields = ?,
            customColorLabel1 = ?,
            customColorLabel2 = ?,
            customColorLabel1GR = ?,
            customColorLabel2GR = ?,
            customColorHelpText = ?,
            customColorHelpTextGR = ?,
            privateCustomerEmail = NULL,
            privateAccessToken = NULL,
            privateLinkSentAt = NULL
        WHERE productID = ?
    ");
    $salesUpsert = $conn->prepare("
        INSERT INTO product_sales_overrides (productID, manual_total_sales, auto_sales_baseline)
        VALUES (?, ?, 0)
        ON DUPLICATE KEY UPDATE
            manual_total_sales = VALUES(manual_total_sales),
            auto_sales_baseline = VALUES(auto_sales_baseline)
    ");

    $imported = [];

    foreach ($catalog as $item) {
        $sku = (string)$item['sku'];
        $selectExisting->bind_param('s', $sku);
        $selectExisting->execute();
        $existingRes = $selectExisting->get_result();
        $existingRow = $existingRes ? $existingRes->fetch_assoc() : null;
        $productId = (int)($existingRow['productID'] ?? 0);
        $variationDefinitions = is_array($item['variations'] ?? null) ? $item['variations'] : [];
        $hasVariants = !empty($variationDefinitions) ? 1 : 0;
        $price = (float)$item['price'];
        $inventory = (int)$item['inventory'];
        if ($hasVariants) {
            $variationPrices = array_map(static fn(array $variation): float => (float)($variation['price'] ?? 0), $variationDefinitions);
            $price = !empty($variationPrices) ? min($variationPrices) : $price;
            $inventory = array_sum(array_map(static fn(array $variation): int => (int)($variation['inventory'] ?? 0), $variationDefinitions));
        }
        $customColorFields = (int)($item['custom_color_fields'] ?? 0);
        $customColorLabel1 = (string)($item['custom_color_label_1'] ?? '');
        $customColorLabel2 = (string)($item['custom_color_label_2'] ?? '');
        $customColorLabel1Gr = (string)($item['custom_color_label_1_gr'] ?? '');
        $customColorLabel2Gr = (string)($item['custom_color_label_2_gr'] ?? '');
        $customColorHelp = (string)($item['custom_color_help'] ?? '');
        $customColorHelpGr = (string)($item['custom_color_help_gr'] ?? '');

        if ($productId > 0) {
            $nameEn = catalogNormalizeText((string)$item['name_en']);
            $nameGr = catalogNormalizeText((string)$item['name_gr']);
            $descEn = catalogNormalizeText((string)$item['description_en']);
            $descGr = catalogNormalizeText((string)$item['description_gr']);
            $cost = (float)$item['cost'];
            $status = (string)$item['status'];
            $category = catalogNormalizeText((string)$item['category']);
            $sellingFast = (int)$item['selling_fast'];
            $customColorLabel1 = catalogNormalizeText($customColorLabel1);
            $customColorLabel2 = catalogNormalizeText($customColorLabel2);
            $customColorLabel1Gr = catalogNormalizeText($customColorLabel1Gr);
            $customColorLabel2Gr = catalogNormalizeText($customColorLabel2Gr);
            $customColorHelp = catalogNormalizeText($customColorHelp);
            $customColorHelpGr = catalogNormalizeText($customColorHelpGr);

            $updateProduct->bind_param(
                'ssssddissiiissssssi',
                $nameEn,
                $nameGr,
                $descEn,
                $descGr,
                $price,
                $cost,
                $inventory,
                $status,
                $category,
                $sellingFast,
                $hasVariants,
                $customColorFields,
                $customColorLabel1,
                $customColorLabel2,
                $customColorLabel1Gr,
                $customColorLabel2Gr,
                $customColorHelp,
                $customColorHelpGr,
                $productId
            );
            $updateProduct->execute();
        } else {
            $skuValue = (string)$item['sku'];
            $nameEn = catalogNormalizeText((string)$item['name_en']);
            $nameGr = catalogNormalizeText((string)$item['name_gr']);
            $descEn = catalogNormalizeText((string)$item['description_en']);
            $descGr = catalogNormalizeText((string)$item['description_gr']);
            $cost = (float)$item['cost'];
            $status = (string)$item['status'];
            $category = catalogNormalizeText((string)$item['category']);
            $sellingFast = (int)$item['selling_fast'];
            $customColorLabel1 = catalogNormalizeText($customColorLabel1);
            $customColorLabel2 = catalogNormalizeText($customColorLabel2);
            $customColorLabel1Gr = catalogNormalizeText($customColorLabel1Gr);
            $customColorLabel2Gr = catalogNormalizeText($customColorLabel2Gr);
            $customColorHelp = catalogNormalizeText($customColorHelp);
            $customColorHelpGr = catalogNormalizeText($customColorHelpGr);

            $insertProduct->bind_param(
                'sssssddissiiissssss',
                $skuValue,
                $nameEn,
                $nameGr,
                $descEn,
                $descGr,
                $price,
                $cost,
                $inventory,
                $status,
                $category,
                $sellingFast,
                $hasVariants,
                $customColorFields,
                $customColorLabel1,
                $customColorLabel2,
                $customColorLabel1Gr,
                $customColorLabel2Gr,
                $customColorHelp,
                $customColorHelpGr
            );
            $insertProduct->execute();
            $productId = (int)$conn->insert_id;
        }

        if ($productId <= 0) {
            throw new RuntimeException("Could not resolve product ID for SKU {$sku}");
        }

        catalogDeleteImportedProductData($conn, $productId);

        $folderRoot = rtrim(WEBSITE_SOURCE_ROOT, '/\\') . '/' . trim((string)$item['folder'], '/\\');
        $resolvedMainImages = [];
        foreach ((array)$item['images'] as $imageRelativePath) {
            $fullPath = catalogResolveSourcePath($sku, $folderRoot, (string)$imageRelativePath);
            if ($fullPath === null) {
                continue;
            }
            $resolvedMainImages[] = $fullPath;
        }

        if (empty($resolvedMainImages)) {
            throw new RuntimeException("Could not resolve any storefront images for SKU {$sku}");
        }

        foreach ($resolvedMainImages as $fullPath) {
            $blob = catalogReadPhotoBlob($fullPath);
            catalogInsertPhoto($conn, $productId, $blob);
        }

        $skuSlug = catalogSlugify($sku);
        $colorOptionDefinitions = is_array($item['color_options'] ?? null) ? $item['color_options'] : [];
        foreach ($colorOptionDefinitions as $colorIndex => $colorDefinition) {
            $colorName = trim((string)($colorDefinition['name'] ?? ''));
            if ($colorName === '') {
                continue;
            }

            $colorId = catalogEnsureColor($conn, $colorName, max(1, $inventory));
            foreach ((array)($colorDefinition['images'] ?? []) as $imageIndex => $colorImageRelativePath) {
                $sourcePath = catalogResolveSourcePath($sku, $folderRoot, (string)$colorImageRelativePath);
                if ($sourcePath === null) {
                    continue;
                }
                $extension = strtolower((string)pathinfo($sourcePath, PATHINFO_EXTENSION));
                $fileBaseName = catalogSlugify((string)pathinfo((string)$colorImageRelativePath, PATHINFO_FILENAME));
                $fileName = sprintf('%02d-%s.%s', $imageIndex + 1, $fileBaseName, $extension !== '' ? $extension : 'jpg');
                $relativeTarget = 'uploads/assets/images/products/' . $skuSlug . '/colors/' . catalogSlugify($colorName) . '/' . $fileName;
                $storedPath = catalogCopyAsset($sourcePath, $relativeTarget);
                catalogInsertProductColorPhoto($conn, $productId, $colorId, $storedPath, ($colorIndex * 100) + $imageIndex);
            }
        }

        $variationPhotoCount = 0;
        if ($hasVariants) {
            foreach ($variationDefinitions as $variationIndex => $variationDefinition) {
                $variationId = catalogInsertVariation($conn, $productId, $variationDefinition);
                catalogInsertVariationStock(
                    $conn,
                    $variationId,
                    (int)($variationDefinition['inventory'] ?? 0),
                    1
                );

                $variationSlug = catalogSlugify((string)($variationDefinition['size'] ?? ('variation-' . ($variationIndex + 1))));
                foreach ((array)($variationDefinition['images'] ?? []) as $imageIndex => $variationImageRelativePath) {
                    $sourcePath = catalogResolveSourcePath($sku, $folderRoot, (string)$variationImageRelativePath);
                    if ($sourcePath === null) {
                        continue;
                    }
                    $extension = strtolower((string)pathinfo($sourcePath, PATHINFO_EXTENSION));
                    $fileBaseName = catalogSlugify((string)pathinfo((string)$variationImageRelativePath, PATHINFO_FILENAME));
                    $fileName = sprintf('%02d-%s.%s', $imageIndex + 1, $fileBaseName, $extension !== '' ? $extension : 'jpg');
                    $relativeTarget = 'uploads/assets/images/products/' . $skuSlug . '/' . $variationSlug . '/' . $fileName;
                    $storedPath = catalogCopyAsset($sourcePath, $relativeTarget);
                    catalogInsertVariationPhoto($conn, $variationId, $storedPath, $imageIndex);
                    $variationPhotoCount++;
                }
            }
        }

        $manualSales = (int)$item['manual_sales'];
        $salesUpsert->bind_param('ii', $productId, $manualSales);
        $salesUpsert->execute();

        $imported[] = [
            'productID' => $productId,
            'sku' => $sku,
            'nameEN' => (string)$item['name_en'],
            'photoCount' => count((array)$item['images']),
            'variationCount' => count($variationDefinitions),
            'variationPhotoCount' => $variationPhotoCount,
        ];
    }

    $selectExisting->close();
    $insertProduct->close();
    $updateProduct->close();
    $salesUpsert->close();

    $conn->commit();

    echo "Imported products:\n";
    foreach ($imported as $row) {
        echo "- #{$row['productID']} {$row['sku']} {$row['nameEN']} ({$row['photoCount']} photos, {$row['variationCount']} variations, {$row['variationPhotoCount']} variation photos)\n";
    }
    echo "Legacy products archived: {$legacySummary['archived']}\n";
    echo "Legacy products deleted: {$legacySummary['deleted']}\n";
    if (!empty($skippedCatalog)) {
        echo "Skipped products without local assets:\n";
        foreach ($skippedCatalog as $row) {
            echo "- {$row['sku']}: {$row['reason']}\n";
        }
    }
} catch (Throwable $e) {
    try {
        if ($conn instanceof mysqli && @$conn->ping()) {
            $conn->rollback();
        }
    } catch (Throwable $ignored) {
    }
    fwrite(STDERR, "Import failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
