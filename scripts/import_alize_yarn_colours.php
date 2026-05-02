<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../authentication/database.php';
require_once __DIR__ . '/../include/image_storage.php';
require_once __DIR__ . '/../include/product_option_helpers.php';

app_product_options_ensure_schema($conn);

const ALIZE_BASE_URL = 'https://alize.gen.tr';
const ALIZE_YARN_COLOR_DIR = 'assets/yarn_colors';

$catalogues = [
    [
        'typeName' => 'Baby Anti Pilling',
        'slug' => 'baby-best',
        'baseId' => 100000,
        'defaultStock' => 20,
    ],
    [
        'typeName' => 'Cotton',
        'slug' => 'cotton-gold',
        'baseId' => 200000,
        'defaultStock' => 20,
    ],
    [
        'typeName' => 'Puffy',
        'slug' => 'puffy',
        'baseId' => 300000,
        'defaultStock' => 20,
    ],
    [
        'typeName' => 'Puffy',
        'slug' => 'puffy-color',
        'baseId' => 350000,
        'defaultStock' => 20,
    ],
    [
        'typeName' => 'Velvet',
        'slug' => 'velluto',
        'baseId' => 400000,
        'defaultStock' => 20,
    ],
];

$selectedCodesBySlug = [
    'baby-best' => ['55', '62', '185', '237', '250', '287', '310', '336', '344', '599'],
    'cotton-gold' => ['1', '36', '55', '56', '60', '62', '149', '216', '279', '287'],
    'puffy' => ['55', '62', '310', '340', '428', '599'],
    'puffy-color' => ['5865', '5923', '6395', '6408'],
    'velluto' => ['13', '55', '199', '218', '310', '329', '340', '374', '416', '428'],
];

$colourNamesBySlug = [
    'baby-best' => [
        '55' => 'Snow White',
        '62' => 'Ivory Cream',
        '185' => 'Baby Pink',
        '237' => 'Cornflower Blue',
        '250' => 'Lemon Yellow',
        '287' => 'Aqua Teal',
        '310' => 'Warm Beige',
        '336' => 'Tangerine Orange',
        '344' => 'Silver Grey',
        '599' => 'Taupe Beige',
    ],
    'cotton-gold' => [
        '1' => 'Ivory Cream',
        '36' => 'Rust Brown',
        '55' => 'Snow White',
        '56' => 'Cherry Red',
        '60' => 'Black',
        '62' => 'Soft White',
        '149' => 'Hot Pink',
        '216' => 'Golden Yellow',
        '279' => 'Navy Blue',
        '287' => 'Turquoise Blue',
    ],
    'puffy' => [
        '55' => 'Snow White',
        '62' => 'Vanilla Cream',
        '310' => 'Peach Cream',
        '340' => 'Soft Pink',
        '428' => 'Silver Grey',
        '599' => 'Warm Oatmeal',
    ],
    'puffy-color' => [
        '5865' => 'Sky Blue Mix',
        '5923' => 'Lavender Pink Mix',
        '6395' => 'Stone Beige Mix',
        '6408' => 'Mint Grey Mix',
    ],
    'velluto' => [
        '13' => 'Lemon Cream',
        '55' => 'Snow White',
        '199' => 'Camel Brown',
        '218' => 'Baby Blue',
        '310' => 'Peach Cream',
        '329' => 'Mocha Brown',
        '340' => 'Blush Pink',
        '374' => 'Denim Blue',
        '416' => 'Ice Grey',
        '428' => 'Silver Grey',
    ],
];

$legacyVelvetCodes = [13, 55, 62, 199, 218, 310, 329, 340, 374, 416, 428, 429, 530, 599, 866];
$legacyVelvetStatus = alizeReadLegacyVelvetStatus($conn, $legacyVelvetCodes);

$imported = [];
$downloadedFiles = 0;
$reusedFiles = 0;
$selectedColorIds = [];

foreach ($catalogues as $catalogue) {
    $typeName = (string)$catalogue['typeName'];
    $slug = (string)$catalogue['slug'];
    $baseId = (int)$catalogue['baseId'];
    $typeId = alizeEnsureYarnType($conn, $typeName);
    $entries = alizeFetchSwatchEntries($slug);

    $seenCodes = [];
    $catalogueCount = 0;
    foreach ($entries as $entry) {
        $code = alizeNormalizeCode((string)$entry['code']);
        if ($code === '' || isset($seenCodes[$code])) {
            continue;
        }
        if (!in_array($code, $selectedCodesBySlug[$slug] ?? [], true)) {
            continue;
        }
        $seenCodes[$code] = true;

        $colorId = alizeColorId($baseId, $code);
        $colourName = $colourNamesBySlug[$slug][$code] ?? ($typeName . ' ' . $code);
        $selectedColorIds[] = $colorId;
        $stock = (int)$catalogue['defaultStock'];
        $isActive = 1;
        if ($slug === 'velluto' && isset($legacyVelvetStatus[(int)$code])) {
            $stock = $legacyVelvetStatus[(int)$code]['stock'];
            $isActive = $legacyVelvetStatus[(int)$code]['isActive'];
        }

        [$photoPath, $wasDownloaded] = alizeDownloadSwatch((string)$entry['imageUrl'], $slug, $code);
        $downloadedFiles += $wasDownloaded ? 1 : 0;
        $reusedFiles += $wasDownloaded ? 0 : 1;

        alizeUpsertColor($conn, $colorId, $colourName, $code, $stock, $isActive);
        alizeUpsertColorYarnType($conn, $colorId, $typeId, $photoPath);
        $catalogueCount++;
    }

    $imported[$typeName] = (int)($imported[$typeName] ?? 0) + $catalogueCount;
}

alizeMigrateLegacyVelvetReferences($conn, $legacyVelvetCodes, 400000);
alizeMigrateKnownBlanketColourReferences($conn);
alizeMergeYarnTypes($conn, 'Puffy Color', 'Puffy');
alizePruneToSelectedColors($conn, array_values(array_unique($selectedColorIds)));

echo "Imported Alize yarn colours:\n";
foreach ($imported as $typeName => $count) {
    echo "- {$typeName}: {$count}\n";
}
echo "Downloaded swatches: {$downloadedFiles}\n";
echo "Reused existing swatches: {$reusedFiles}\n";

function alizeReadLegacyVelvetStatus(mysqli $conn, array $legacyCodes): array
{
    $ids = array_values(array_filter(array_map('intval', $legacyCodes), static fn(int $id): bool => $id > 0));
    if (empty($ids)) {
        return [];
    }

    $sql = 'SELECT colorID, globalInventoryAvailable, isActive FROM colors WHERE colorID IN (' . implode(',', $ids) . ')';
    $res = $conn->query($sql);
    $status = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $status[(int)$row['colorID']] = [
            'stock' => (int)($row['globalInventoryAvailable'] ?? 0),
            'isActive' => (int)($row['isActive'] ?? 1),
        ];
    }
    return $status;
}

function alizeEnsureYarnType(mysqli $conn, string $typeName): int
{
    $stmt = $conn->prepare(
        'INSERT INTO yarn_types (typeName)
         VALUES (?)
         ON DUPLICATE KEY UPDATE typeID = LAST_INSERT_ID(typeID)'
    );
    $stmt->bind_param('s', $typeName);
    $stmt->execute();
    $typeId = (int)$conn->insert_id;
    $stmt->close();

    if ($typeId > 0) {
        return $typeId;
    }

    $stmt = $conn->prepare('SELECT typeID FROM yarn_types WHERE typeName = ? LIMIT 1');
    $stmt->bind_param('s', $typeName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $typeId = (int)($row['typeID'] ?? 0);
    if ($typeId <= 0) {
        throw new RuntimeException("Could not create yarn type: {$typeName}");
    }
    return $typeId;
}

function alizeFetchSwatchEntries(string $slug): array
{
    $endpoint = ALIZE_BASE_URL . '/ymk/' . rawurlencode($slug) . '/kesit/off';
    $html = alizeFetchUrl($endpoint);

    $entries = [];
    if (!preg_match_all('/<a\b[^>]*data-bilgi="([^"]+)"[^>]*>.*?<img\b[^>]*src="([^"]+)"/is', $html, $matches, PREG_SET_ORDER)) {
        throw new RuntimeException("No swatches found in {$endpoint}");
    }

    foreach ($matches as $match) {
        $imageUrl = html_entity_decode((string)$match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($imageUrl !== '' && strpos($imageUrl, '//') === 0) {
            $imageUrl = 'https:' . $imageUrl;
        } elseif ($imageUrl !== '' && !preg_match('#^https?://#i', $imageUrl)) {
            $imageUrl = ALIZE_BASE_URL . '/' . ltrim($imageUrl, '/');
        }
        $entries[] = [
            'code' => html_entity_decode((string)$match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'imageUrl' => $imageUrl,
        ];
    }

    return $entries;
}

function alizeFetchUrl(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: AthinaEshopColourImporter/1.0\r\nAccept: text/html,image/*,*/*\r\n",
            'timeout' => 30,
        ],
    ]);

    $data = @file_get_contents($url, false, $context);
    if (!is_string($data) || $data === '') {
        throw new RuntimeException("Could not fetch {$url}");
    }
    return $data;
}

function alizeNormalizeCode(string $code): string
{
    $code = trim($code);
    $code = preg_replace('/[^0-9A-Za-z_-]/', '', $code) ?? '';
    return $code;
}

function alizeColorId(int $baseId, string $code): int
{
    $numeric = (int)preg_replace('/\D+/', '', $code);
    if ($numeric <= 0) {
        throw new RuntimeException("Invalid Alize colour code: {$code}");
    }
    return $baseId + $numeric;
}

function alizeDownloadSwatch(string $imageUrl, string $slug, string $code): array
{
    $safeSlug = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($slug)) ?: 'alize';
    $safeCode = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($code)) ?: 'colour';
    $relativePath = ALIZE_YARN_COLOR_DIR . '/alize_' . $safeSlug . '_' . $safeCode . '.webp';
    $targetPath = app_image_local_asset_path($relativePath);

    if (is_file($targetPath)) {
        return [$relativePath, false];
    }

    $binary = alizeFetchUrl($imageUrl);
    $webpBinary = app_image_binary_to_optimized_webp($binary, 700, 700, 84);
    if (!is_string($webpBinary) || $webpBinary === '') {
        throw new RuntimeException("Could not convert swatch to WebP: {$imageUrl}");
    }
    if (!app_image_write_binary_file($targetPath, $webpBinary)) {
        throw new RuntimeException("Could not write swatch: {$targetPath}");
    }

    return [$relativePath, true];
}

function alizeUpsertColor(mysqli $conn, int $colorId, string $colourName, string $displayCode, int $stock, int $isActive): void
{
    $colorName = trim($colourName);
    if ($displayCode !== '' && !preg_match('/\s+' . preg_quote($displayCode, '/') . '$/u', $colorName)) {
        $colorName = trim($colorName . ' ' . $displayCode);
    }
    $hexCode = '#ece6f6';

    $stmt = $conn->prepare(
        'INSERT INTO colors (colorID, colorName, displayCode, hexCode, globalInventoryAvailable, isActive)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            colorName = VALUES(colorName),
            displayCode = VALUES(displayCode),
            hexCode = VALUES(hexCode)'
    );
    $stmt->bind_param('isssii', $colorId, $colorName, $displayCode, $hexCode, $stock, $isActive);
    $stmt->execute();
    $stmt->close();
}

function alizeUpsertColorYarnType(mysqli $conn, int $colorId, int $typeId, string $photoPath): void
{
    $stmt = $conn->prepare(
        'INSERT INTO color_yarn_types (colorID, typeID, photoPath)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE photoPath = VALUES(photoPath)'
    );
    $stmt->bind_param('iis', $colorId, $typeId, $photoPath);
    $stmt->execute();
    $stmt->close();
}

function alizeMigrateLegacyVelvetReferences(mysqli $conn, array $legacyCodes, int $baseId): void
{
    foreach ($legacyCodes as $legacyCode) {
        $oldColorId = (int)$legacyCode;
        $newColorId = alizeColorId($baseId, (string)$legacyCode);

        $stmt = $conn->prepare('UPDATE product_color_photos SET colorID = ? WHERE colorID = ?');
        $stmt->bind_param('ii', $newColorId, $oldColorId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('UPDATE product_variations SET colorID = ? WHERE colorID = ?');
        $stmt->bind_param('ii', $newColorId, $oldColorId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('SELECT COUNT(*) AS refs FROM product_color_photos WHERE colorID = ?');
        $stmt->bind_param('i', $oldColorId);
        $stmt->execute();
        $photoRefs = (int)($stmt->get_result()->fetch_assoc()['refs'] ?? 0);
        $stmt->close();

        $stmt = $conn->prepare('SELECT COUNT(*) AS refs FROM product_variations WHERE colorID = ?');
        $stmt->bind_param('i', $oldColorId);
        $stmt->execute();
        $variationRefs = (int)($stmt->get_result()->fetch_assoc()['refs'] ?? 0);
        $stmt->close();

        if ($photoRefs === 0 && $variationRefs === 0) {
            $stmt = $conn->prepare('DELETE FROM colors WHERE colorID = ?');
            $stmt->bind_param('i', $oldColorId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function alizeMigrateKnownBlanketColourReferences(mysqli $conn): void
{
    $knownMappings = [
        12 => 355865,
        1013 => 355923,
        14 => 356408,
        15 => 356395,
    ];

    foreach ($knownMappings as $oldColorId => $newColorId) {
        $oldColorId = (int)$oldColorId;
        $newColorId = (int)$newColorId;

        $stmt = $conn->prepare('UPDATE product_color_photos SET colorID = ? WHERE colorID = ?');
        $stmt->bind_param('ii', $newColorId, $oldColorId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('UPDATE product_variations SET colorID = ? WHERE colorID = ?');
        $stmt->bind_param('ii', $newColorId, $oldColorId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('DELETE FROM category_colors WHERE colorID = ?');
        $stmt->bind_param('i', $oldColorId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('SELECT COUNT(*) AS refs FROM product_color_photos WHERE colorID = ?');
        $stmt->bind_param('i', $oldColorId);
        $stmt->execute();
        $photoRefs = (int)($stmt->get_result()->fetch_assoc()['refs'] ?? 0);
        $stmt->close();

        $stmt = $conn->prepare('SELECT COUNT(*) AS refs FROM product_variations WHERE colorID = ?');
        $stmt->bind_param('i', $oldColorId);
        $stmt->execute();
        $variationRefs = (int)($stmt->get_result()->fetch_assoc()['refs'] ?? 0);
        $stmt->close();

        if ($photoRefs === 0 && $variationRefs === 0) {
            $stmt = $conn->prepare('DELETE FROM colors WHERE colorID = ?');
            $stmt->bind_param('i', $oldColorId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function alizeMergeYarnTypes(mysqli $conn, string $sourceTypeName, string $targetTypeName): void
{
    $targetTypeId = alizeEnsureYarnType($conn, $targetTypeName);

    $stmt = $conn->prepare('SELECT typeID FROM yarn_types WHERE typeName = ? LIMIT 1');
    $stmt->bind_param('s', $sourceTypeName);
    $stmt->execute();
    $sourceRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $sourceTypeId = (int)($sourceRow['typeID'] ?? 0);
    if ($sourceTypeId <= 0 || $sourceTypeId === $targetTypeId) {
        return;
    }

    $stmt = $conn->prepare('SELECT colorID, photoPath FROM color_yarn_types WHERE typeID = ?');
    $stmt->bind_param('i', $sourceTypeId);
    $stmt->execute();
    $links = $stmt->get_result();
    while ($links && ($link = $links->fetch_assoc())) {
        $colorId = (int)($link['colorID'] ?? 0);
        $photoPath = (string)($link['photoPath'] ?? '');
        if ($colorId <= 0) {
            continue;
        }
        alizeUpsertColorYarnType($conn, $colorId, $targetTypeId, $photoPath);
    }
    $stmt->close();

    $stmt = $conn->prepare('DELETE FROM color_yarn_types WHERE typeID = ?');
    $stmt->bind_param('i', $sourceTypeId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare('DELETE FROM yarn_types WHERE typeID = ?');
    $stmt->bind_param('i', $sourceTypeId);
    $stmt->execute();
    $stmt->close();

    $fromPrefix = $sourceTypeName . ' ';
    $toPrefix = $targetTypeName . ' ';
    $stmt = $conn->prepare('UPDATE colors SET colorName = REPLACE(colorName, ?, ?) WHERE colorName LIKE ?');
    $like = $fromPrefix . '%';
    $stmt->bind_param('sss', $fromPrefix, $toPrefix, $like);
    $stmt->execute();
    $stmt->close();
}

function alizePruneToSelectedColors(mysqli $conn, array $selectedColorIds): void
{
    $selectedColorIds = array_values(array_unique(array_filter(
        array_map('intval', $selectedColorIds),
        static fn(int $colorId): bool => $colorId > 0
    )));
    if (empty($selectedColorIds)) {
        throw new RuntimeException('Refusing to prune yarn colours because the selected colour list is empty.');
    }

    $placeholders = implode(',', array_fill(0, count($selectedColorIds), '?'));
    $types = str_repeat('i', count($selectedColorIds));

    $tables = [
        'category_colors',
        'product_color_photos',
        'color_yarn_types',
    ];
    foreach ($tables as $tableName) {
        $stmt = $conn->prepare("DELETE FROM {$tableName} WHERE colorID NOT IN ({$placeholders})");
        $stmt->bind_param($types, ...$selectedColorIds);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("UPDATE product_variations SET colorID = NULL WHERE colorID IS NOT NULL AND colorID NOT IN ({$placeholders})");
    $stmt->bind_param($types, ...$selectedColorIds);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM colors WHERE colorID NOT IN ({$placeholders})");
    $stmt->bind_param($types, ...$selectedColorIds);
    $stmt->execute();
    $stmt->close();
}
