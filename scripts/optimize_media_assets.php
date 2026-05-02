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
        if ($baseName === 'header-logo.png' || $baseName === 'header-logo.gif' || $baseName === 'header-logo.webp' || $baseName === 'header-logo.jpeg') {
            return app_image_convert_local_content_asset_to_webp($normalized, 50, 50, 88);
        }
        if ($baseName === 'hero-section.png' || $baseName === 'hero-section.webp' || $baseName === 'hero-section.gif') {
            return app_image_convert_local_content_asset_to_webp($normalized, 1920, 600, 84);
        }
        if (strpos($baseName, 'shop-collection-') === 0) {
            return app_image_convert_local_content_asset_to_webp($normalized, 261, 260, 84);
        }
        if (strpos($baseName, 'follow-journey-') === 0) {
            return app_image_convert_local_content_asset_to_webp($normalized, 361, 260, 84);
        }
    }

    if (strpos($lower, 'assets/yarn_colors/') === 0) {
        return app_image_convert_local_content_asset_to_webp($normalized, 1200, 1200, 84);
    }

    if (strpos($lower, 'assets/product_color_scheme_photos/') === 0) {
        return app_image_convert_local_content_asset_to_webp($normalized, 1200, 1200, 86);
    }

    if (strpos($lower, 'assets/product_color_photos/') === 0) {
        return app_image_convert_local_content_asset_to_webp($normalized, 800, 800, 84);
    }

    return app_image_convert_local_content_asset_to_webp($normalized, 1600, 1600, 82);
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

function mediaResolveOptimizedReference(string $current): ?string
{
    $normalized = app_image_relative_asset_path($current);
    if ($normalized === '' || app_image_is_remote_asset($normalized)) {
        return null;
    }

    if (!app_image_is_convertible_content_asset($normalized)) {
        return null;
    }

    $candidate = preg_replace('/\.(png|gif|webp|jpe?g)$/i', '.webp', $normalized);
    if (!is_string($candidate) || $candidate === '' || strcasecmp($candidate, $normalized) === 0) {
        return null;
    }

    return is_file(app_image_local_asset_path($candidate)) ? $candidate : null;
}

function mediaNormalizeHomepageSystemConfig(mysqli $conn): int
{
    $keys = [
        'homepage_hero_image',
        'homepage_collection_1_image',
        'homepage_collection_2_image',
        'homepage_collection_3_image',
        'homepage_collection_4_image',
        'homepage_journey_1_image',
        'homepage_journey_2_image',
        'homepage_journey_3_image',
        'homepage_header_logo_path',
        'logo_path',
    ];

    $select = $conn->prepare("SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1");
    $update = $conn->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ?");
    if (!$select || !$update) {
        if ($select) {
            $select->close();
        }
        if ($update) {
            $update->close();
        }
        return 0;
    }

    $count = 0;
    foreach ($keys as $key) {
        $select->bind_param('s', $key);
        $select->execute();
        $result = $select->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $current = trim((string)($row['config_value'] ?? ''));
        if ($current === '') {
            continue;
        }

        $next = mediaResolveOptimizedReference($current);
        if (!is_string($next) || $next === '' || $next === $current) {
            continue;
        }

        $update->bind_param('ss', $next, $key);
        $update->execute();
        $count += $update->affected_rows > 0 ? 1 : 0;
    }

    $select->close();
    $update->close();
    return $count;
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
        if ($current === '') {
            continue;
        }

        $next = $map[$current] ?? mediaResolveOptimizedReference($current);
        if (!is_string($next) || $next === '' || $next === $current) {
            continue;
        }

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
        'product_variation_photos' => 'photoPath',
        'product_color_photos' => 'photoPath',
        'product_color_scheme_photos' => 'photoPath',
        'color_yarn_types' => 'photoPath',
    ];

    $pathUpdateCounts = [
        'system_config' => mediaNormalizeHomepageSystemConfig($conn),
    ];
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
