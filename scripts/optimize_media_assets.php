<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_ALLOW_MEDIA_OPTIMIZER_WEB')) {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../authentication/database.php';
require_once __DIR__ . '/../include/image_storage.php';

function mediaOptimizePath(string $relativePath): string
{
    $normalized = app_image_relative_asset_path($relativePath);
    if (!app_image_is_convertible_content_asset($normalized)) {
        return $relativePath;
    }

    $lower = strtolower($normalized);
    $baseName = basename($lower);

    if (strpos($lower, 'uploads/assets/images/homepage/') === 0) {
        if ($baseName === 'hero-section.png' || $baseName === 'hero-section.webp' || $baseName === 'hero-section.gif') {
            return app_image_convert_local_content_asset_to_jpg($normalized, 1920, 600, 84);
        }
        if (strpos($baseName, 'shop-collection-') === 0) {
            return app_image_convert_local_content_asset_to_jpg($normalized, 261, 260, 84);
        }
        if (strpos($baseName, 'follow-journey-') === 0) {
            return app_image_convert_local_content_asset_to_jpg($normalized, 361, 260, 84);
        }
    }

    if (strpos($lower, 'assets/yarn_colors/') === 0) {
        return app_image_convert_local_content_asset_to_jpg($normalized, 1200, 1200, 84);
    }

    if (strpos($lower, 'assets/product_color_scheme_photos/') === 0) {
        return app_image_convert_local_content_asset_to_jpg($normalized, 1200, 1200, 86);
    }

    if (strpos($lower, 'assets/product_color_photos/') === 0) {
        return app_image_convert_local_content_asset_to_jpg($normalized, 800, 800, 84);
    }

    return app_image_convert_local_content_asset_to_jpg($normalized, 1600, 1600, 82);
}

function mediaCollectConvertibleFiles(string $relativeRoot): array
{
    $absoluteRoot = app_image_local_asset_path($relativeRoot);
    if (!is_dir($absoluteRoot)) {
        return [];
    }

    $paths = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if (!$entry->isFile()) {
            continue;
        }

        $relativePath = str_replace('\\', '/', substr($entry->getPathname(), strlen(app_image_project_root()) + 1));
        if (app_image_is_convertible_content_asset($relativePath)) {
            $paths[] = $relativePath;
        }
    }

    sort($paths);
    return $paths;
}

function mediaBuildPathMap(): array
{
    $roots = [
        'uploads/assets/images/homepage',
        'uploads/assets/images/products',
        'assets/product_color_photos',
        'assets/product_color_scheme_photos',
        'assets/yarn_colors',
    ];

    $map = [];
    foreach ($roots as $root) {
        foreach (mediaCollectConvertibleFiles($root) as $relativePath) {
            $optimized = mediaOptimizePath($relativePath);
            if ($optimized !== $relativePath) {
                $map[$relativePath] = $optimized;
            }
        }
    }

    return $map;
}

function mediaApplyPathMap(mysqli $conn, array $map, string $table, string $column): int
{
    if (empty($map)) {
        return 0;
    }

    $select = $conn->query("SELECT `" . $column . "` AS value FROM `" . $table . "`");
    if (!$select) {
        return 0;
    }

    $update = $conn->prepare("UPDATE `" . $table . "` SET `" . $column . "` = ? WHERE `" . $column . "` = ?");
    if (!$update) {
        return 0;
    }

    $count = 0;
    while ($row = $select->fetch_assoc()) {
        $current = trim((string)($row['value'] ?? ''));
        if ($current === '' || !isset($map[$current])) {
            continue;
        }

        $next = $map[$current];
        $update->bind_param('ss', $next, $current);
        $update->execute();
        $count += $update->affected_rows > 0 ? 1 : 0;
    }
    $update->close();

    return $count;
}

function mediaOptimizeBlobPhotos(mysqli $conn): array
{
    $summary = [
        'rows' => 0,
        'bytes_before' => 0,
        'bytes_after' => 0,
    ];

    $select = $conn->query("SELECT imageID, photo FROM photos ORDER BY imageID ASC");
    if (!$select) {
        return $summary;
    }

    $update = $conn->prepare("UPDATE photos SET photo = ? WHERE imageID = ?");
    if (!$update) {
        return $summary;
    }

    while ($row = $select->fetch_assoc()) {
        $imageId = (int)($row['imageID'] ?? 0);
        $photoBinary = $row['photo'] ?? null;
        if ($imageId <= 0 || !is_string($photoBinary) || $photoBinary === '') {
            continue;
        }

        $optimizedBinary = app_image_optimize_photo_blob_for_storage($photoBinary, 1400, 1400, 78);
        $summary['bytes_before'] += strlen($photoBinary);
        $summary['bytes_after'] += strlen($optimizedBinary);

        if ($optimizedBinary === $photoBinary) {
            continue;
        }

        $null = null;
        $update->bind_param('bi', $null, $imageId);
        $update->send_long_data(0, $optimizedBinary);
        $update->execute();
        $summary['rows']++;
    }

    $update->close();
    return $summary;
}

$conn->begin_transaction();

try {
    $pathMap = mediaBuildPathMap();

    $tableUpdates = [
        'system_config' => 'config_value',
        'product_variation_photos' => 'photoPath',
        'product_color_photos' => 'photoPath',
        'product_color_scheme_photos' => 'photoPath',
        'color_yarn_types' => 'photoPath',
    ];

    $pathUpdateCounts = [];
    foreach ($tableUpdates as $table => $column) {
        $pathUpdateCounts[$table] = mediaApplyPathMap($conn, $pathMap, $table, $column);
    }

    $blobSummary = mediaOptimizeBlobPhotos($conn);
    $conn->commit();

    echo "Optimized local content files: " . count($pathMap) . PHP_EOL;
    foreach ($pathUpdateCounts as $table => $count) {
        echo "Updated {$table}: {$count}" . PHP_EOL;
    }
    echo "Optimized photo blobs: {$blobSummary['rows']}" . PHP_EOL;
    echo "Blob bytes before: {$blobSummary['bytes_before']}" . PHP_EOL;
    echo "Blob bytes after: {$blobSummary['bytes_after']}" . PHP_EOL;
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Media optimization failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
