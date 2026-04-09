<?php
require_once __DIR__ . '/homepage_customization.php';
require_once __DIR__ . '/made_to_order_access.php';
require_once __DIR__ . '/product_option_helpers.php';

if (!function_exists('app_content_sync_supported_scopes')) {
    function app_content_sync_supported_scopes(): array
    {
        return ['homepage', 'shop'];
    }
}

if (!function_exists('app_content_sync_normalize_scopes')) {
    function app_content_sync_normalize_scopes(array $scopes): array
    {
        $supported = array_fill_keys(app_content_sync_supported_scopes(), true);
        $normalized = [];

        foreach ($scopes as $scope) {
            $scope = strtolower(trim((string)$scope));
            if ($scope === '' || !isset($supported[$scope])) {
                continue;
            }
            $normalized[$scope] = true;
        }

        if (empty($normalized)) {
            foreach (app_content_sync_supported_scopes() as $scope) {
                $normalized[$scope] = true;
            }
        }

        return array_keys($normalized);
    }
}

if (!function_exists('app_content_sync_snapshot_relative_path')) {
    function app_content_sync_snapshot_relative_path(): string
    {
        return 'sync/content-sync/latest-content-sync.json';
    }
}

if (!function_exists('app_content_sync_snapshot_absolute_path')) {
    function app_content_sync_snapshot_absolute_path(): string
    {
        return app_homepage_project_root() . DIRECTORY_SEPARATOR . 'sync' . DIRECTORY_SEPARATOR . 'content-sync' . DIRECTORY_SEPARATOR . 'latest-content-sync.json';
    }
}

if (!function_exists('app_content_sync_snapshot_directory')) {
    function app_content_sync_snapshot_directory(): string
    {
        return dirname(app_content_sync_snapshot_absolute_path());
    }
}

if (!function_exists('app_content_sync_ensure_snapshot_directory')) {
    function app_content_sync_ensure_snapshot_directory(): string
    {
        $dir = app_content_sync_snapshot_directory();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the content sync directory.');
        }

        return $dir;
    }
}

if (!function_exists('app_content_sync_supported_homepage_keys')) {
    function app_content_sync_supported_homepage_keys(): array
    {
        $keys = array_keys(app_homepage_default_config_values());
        $keys[] = 'logo_path';
        return array_values(array_unique($keys));
    }
}

if (!function_exists('app_content_sync_homepage_asset_keys')) {
    function app_content_sync_homepage_asset_keys(): array
    {
        $keys = ['homepage_hero_image', 'homepage_header_logo_path', 'logo_path'];

        for ($i = 1; $i <= app_homepage_collection_count(); $i++) {
            $keys[] = 'homepage_collection_' . $i . '_image';
        }

        for ($i = 1; $i <= app_homepage_journey_count(); $i++) {
            $keys[] = 'homepage_journey_' . $i . '_image';
        }

        return $keys;
    }
}

if (!function_exists('app_content_sync_allowed_product_columns')) {
    function app_content_sync_allowed_product_columns(): array
    {
        return [
            'sku',
            'nameGR',
            'nameEN',
            'descriptionGR',
            'descriptionEN',
            'inventory',
            'basePrice',
            'costPrice',
            'cartStatus',
            'hasVariants',
            'metaDescription',
            'category',
            'privateCustomerEmail',
            'privateAccessToken',
            'privateLinkSentAt',
            'isSellingFast',
            'customColorFields',
            'customColorLabel1',
            'customColorLabel2',
            'customColorLabel1GR',
            'customColorLabel2GR',
            'customColorHelpText',
            'customColorHelpTextGR',
        ];
    }
}

if (!function_exists('app_content_sync_int_product_columns')) {
    function app_content_sync_int_product_columns(): array
    {
        return ['inventory', 'hasVariants', 'isSellingFast', 'customColorFields'];
    }
}

if (!function_exists('app_content_sync_float_product_columns')) {
    function app_content_sync_float_product_columns(): array
    {
        return ['basePrice', 'costPrice'];
    }
}

if (!function_exists('app_content_sync_table_exists')) {
    function app_content_sync_table_exists(mysqli $conn, string $table): bool
    {
        $safeTable = mysqli_real_escape_string($conn, $table);
        $result = mysqli_query($conn, "SHOW TABLES LIKE '" . $safeTable . "'");
        if (!$result) {
            return false;
        }

        $exists = mysqli_num_rows($result) > 0;
        mysqli_free_result($result);

        return $exists;
    }
}

if (!function_exists('app_content_sync_columns_for_table')) {
    function app_content_sync_columns_for_table(mysqli $conn, string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $cache[$table] = [];
        if (!app_content_sync_table_exists($conn, $table)) {
            return $cache[$table];
        }

        $safeTable = str_replace('`', '``', $table);
        $result = mysqli_query($conn, "SHOW COLUMNS FROM `" . $safeTable . "`");
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $field = (string)($row['Field'] ?? '');
                if ($field !== '') {
                    $cache[$table][] = $field;
                }
            }
            mysqli_free_result($result);
        }

        return $cache[$table];
    }
}

if (!function_exists('app_content_sync_database_readiness')) {
    function app_content_sync_database_readiness(mysqli $conn): array
    {
        $checks = [];
        $missingRequired = [];
        $missingAutoFix = [];

        $requiredTables = [
            'products' => 'Products table',
            'photos' => 'Product photos table',
            'colors' => 'Colors table',
            'product_color_photos' => 'Product color photos table',
            'product_variations' => 'Product variations table',
            'variation_stock' => 'Variation stock table',
        ];
        foreach ($requiredTables as $table => $label) {
            $exists = app_content_sync_table_exists($conn, $table);
            $checks[] = [
                'label' => $label,
                'status' => $exists ? 'ok' : 'error',
                'detail' => $exists
                    ? 'Available in the current local database.'
                    : 'Missing. Import the base project SQL on this machine before using content sync imports.',
                'scope' => 'required',
            ];
            if (!$exists) {
                $missingRequired[] = $table;
            }
        }

        $systemConfigExists = app_content_sync_table_exists($conn, 'system_config');
        $checks[] = [
            'label' => 'Homepage settings table',
            'status' => $systemConfigExists ? 'ok' : 'warning',
            'detail' => $systemConfigExists
                ? 'Ready for homepage setting imports.'
                : 'Missing, but it will be created automatically by the sync tools.',
            'scope' => 'autofix',
        ];
        if (!$systemConfigExists) {
            $missingAutoFix[] = 'system_config';
        }

        if (app_content_sync_table_exists($conn, 'products')) {
            $productColumns = array_fill_keys(app_content_sync_columns_for_table($conn, 'products'), true);
            $requiredProductColumns = [
                'productID',
                'sku',
                'nameGR',
                'nameEN',
                'descriptionGR',
                'descriptionEN',
                'inventory',
                'basePrice',
                'costPrice',
                'cartStatus',
                'hasVariants',
                'category',
            ];
            foreach ($requiredProductColumns as $column) {
                $exists = isset($productColumns[$column]);
                $checks[] = [
                    'label' => 'Products.' . $column,
                    'status' => $exists ? 'ok' : 'error',
                    'detail' => $exists
                        ? 'Column is available.'
                        : 'Missing required column for shop sync imports.',
                    'scope' => 'required',
                ];
                if (!$exists) {
                    $missingRequired[] = 'products.' . $column;
                }
            }

            $autoFixProductColumns = [
                'isSellingFast',
                'privateCustomerEmail',
                'privateAccessToken',
                'privateLinkSentAt',
                'customColorFields',
                'customColorLabel1',
                'customColorLabel2',
                'customColorLabel1GR',
                'customColorLabel2GR',
                'customColorHelpText',
                'customColorHelpTextGR',
            ];
            foreach ($autoFixProductColumns as $column) {
                $exists = isset($productColumns[$column]);
                $checks[] = [
                    'label' => 'Products.' . $column,
                    'status' => $exists ? 'ok' : 'warning',
                    'detail' => $exists
                        ? 'Column is available.'
                        : 'Missing, but it can be added automatically by the sync tools.',
                    'scope' => 'autofix',
                ];
                if (!$exists) {
                    $missingAutoFix[] = 'products.' . $column;
                }
            }
        }

        if (app_content_sync_table_exists($conn, 'photos')) {
            $photoColumns = array_fill_keys(app_content_sync_columns_for_table($conn, 'photos'), true);
            foreach (['imageID', 'photo', 'productID'] as $column) {
                $exists = isset($photoColumns[$column]);
                $checks[] = [
                    'label' => 'Photos.' . $column,
                    'status' => $exists ? 'ok' : 'error',
                    'detail' => $exists
                        ? 'Column is available.'
                        : 'Missing required column for main product photo imports.',
                    'scope' => 'required',
                ];
                if (!$exists) {
                    $missingRequired[] = 'photos.' . $column;
                }
            }
        }

        if (app_content_sync_table_exists($conn, 'colors')) {
            $colorColumns = array_fill_keys(app_content_sync_columns_for_table($conn, 'colors'), true);
            foreach (['colorID', 'colorName', 'globalInventoryAvailable', 'isActive'] as $column) {
                $exists = isset($colorColumns[$column]);
                $checks[] = [
                    'label' => 'Colors.' . $column,
                    'status' => $exists ? 'ok' : 'error',
                    'detail' => $exists
                        ? 'Column is available.'
                        : 'Missing required column for color-aware shop sync imports.',
                    'scope' => 'required',
                ];
                if (!$exists) {
                    $missingRequired[] = 'colors.' . $column;
                }
            }
        }

        if (app_content_sync_table_exists($conn, 'product_color_photos')) {
            $colorPhotoColumns = array_fill_keys(app_content_sync_columns_for_table($conn, 'product_color_photos'), true);
            foreach (['id', 'productID', 'colorID', 'photoPath', 'sortOrder'] as $column) {
                $exists = isset($colorPhotoColumns[$column]);
                $checks[] = [
                    'label' => 'Product color photos.' . $column,
                    'status' => $exists ? 'ok' : 'error',
                    'detail' => $exists
                        ? 'Column is available.'
                        : 'Missing required column for color-specific shop photo imports.',
                    'scope' => 'required',
                ];
                if (!$exists) {
                    $missingRequired[] = 'product_color_photos.' . $column;
                }
            }
        }

        $variationPhotosExists = app_content_sync_table_exists($conn, 'product_variation_photos');
        $checks[] = [
            'label' => 'Product variation photos table',
            'status' => $variationPhotosExists ? 'ok' : 'warning',
            'detail' => $variationPhotosExists
                ? 'Ready for size-specific product image imports.'
                : 'Missing, but it can be created automatically by the sync tools.',
            'scope' => 'autofix',
        ];
        if (!$variationPhotosExists) {
            $missingAutoFix[] = 'product_variation_photos';
        }

        $ready = empty($missingRequired);
        $status = 'ok';
        $summary = 'This local database is ready for homepage and shop content sync imports.';
        if (!$ready) {
            $status = 'error';
            $summary = 'This local database is not ready yet. Import the base project SQL first, then come back and run content sync.';
        } elseif (!empty($missingAutoFix)) {
            $status = 'warning';
            $summary = 'This local database is usable. A few supporting schema pieces are missing, but the sync tools can create them automatically.';
        }

        return [
            'status' => $status,
            'ready' => $ready,
            'summary' => $summary,
            'checks' => $checks,
            'missing_required' => array_values(array_unique($missingRequired)),
            'missing_autofix' => array_values(array_unique($missingAutoFix)),
        ];
    }
}

if (!function_exists('app_content_sync_product_columns')) {
    function app_content_sync_product_columns(mysqli $conn): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $cache = [];
        $result = mysqli_query($conn, "SHOW COLUMNS FROM products");
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $field = (string)($row['Field'] ?? '');
                if ($field !== '') {
                    $cache[] = $field;
                }
            }
            mysqli_free_result($result);
        }

        return $cache;
    }
}

if (!function_exists('app_content_sync_ensure_catalog_schema')) {
    function app_content_sync_ensure_catalog_schema(mysqli $conn): void
    {
        app_homepage_ensure_schema($conn);
        app_product_options_ensure_schema($conn);

        if (function_exists('ensureMadeToOrderProductSchema')) {
            ensureMadeToOrderProductSchema($conn);
        }

        $sellingFastColumn = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'isSellingFast'");
        if ($sellingFastColumn && mysqli_num_rows($sellingFastColumn) === 0) {
            mysqli_query($conn, "ALTER TABLE products ADD COLUMN isSellingFast TINYINT(1) NOT NULL DEFAULT 0");
        }
        if ($sellingFastColumn) {
            mysqli_free_result($sellingFastColumn);
        }
    }
}

if (!function_exists('app_content_sync_absolute_path')) {
    function app_content_sync_absolute_path(string $relativePath): string
    {
        $relativePath = ltrim(trim($relativePath), '/\\');
        if ($relativePath === '') {
            return '';
        }

        return app_homepage_project_root() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    }
}

if (!function_exists('app_content_sync_safe_write_prefixes')) {
    function app_content_sync_safe_write_prefixes(): array
    {
        return [
            'uploads/assets/images/homepage/',
            'uploads/assets/images/products/',
            'assets/product_color_photos/',
        ];
    }
}

if (!function_exists('app_content_sync_is_safe_write_path')) {
    function app_content_sync_is_safe_write_path(string $relativePath): bool
    {
        $relativePath = str_replace('\\', '/', ltrim(trim($relativePath), '/\\'));
        if ($relativePath === '' || strpos($relativePath, '..') !== false) {
            return false;
        }

        foreach (app_content_sync_safe_write_prefixes() as $prefix) {
            if (strpos($relativePath, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('app_content_sync_file_payload')) {
    function app_content_sync_file_payload(string $relativePath): ?array
    {
        if (trim($relativePath) === '' || app_homepage_is_remote_asset($relativePath)) {
            return null;
        }
        if (!app_content_sync_is_safe_write_path($relativePath)) {
            return null;
        }

        $absolutePath = app_content_sync_absolute_path($relativePath);
        if ($absolutePath === '' || !is_file($absolutePath)) {
            return null;
        }

        $contents = file_get_contents($absolutePath);
        if (!is_string($contents)) {
            return null;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string)($finfo->file($absolutePath) ?: 'application/octet-stream');

        return [
            'path' => str_replace('\\', '/', ltrim($relativePath, '/\\')),
            'mime_type' => $mimeType,
            'content_base64' => base64_encode($contents),
        ];
    }
}

if (!function_exists('app_content_sync_restore_file_payload')) {
    function app_content_sync_restore_file_payload(array $filePayload): void
    {
        $path = str_replace('\\', '/', ltrim(trim((string)($filePayload['path'] ?? '')), '/\\'));
        if (!app_content_sync_is_safe_write_path($path)) {
            throw new InvalidArgumentException('Snapshot contains a file path outside the allowed sync folders: ' . $path);
        }

        $encoded = (string)($filePayload['content_base64'] ?? '');
        if ($encoded === '') {
            throw new InvalidArgumentException('Snapshot file payload is empty for ' . $path . '.');
        }

        $contents = base64_decode($encoded, true);
        if (!is_string($contents)) {
            throw new InvalidArgumentException('Snapshot file payload is invalid for ' . $path . '.');
        }

        $absolutePath = app_content_sync_absolute_path($path);
        if ($absolutePath === '') {
            throw new InvalidArgumentException('Snapshot file path could not be resolved.');
        }

        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the directory for ' . $path . '.');
        }

        if (file_put_contents($absolutePath, $contents) === false) {
            throw new RuntimeException('Could not restore the snapshot file ' . $path . '.');
        }
    }
}

if (!function_exists('app_content_sync_snapshot_filename')) {
    function app_content_sync_snapshot_filename(array $scopes): string
    {
        $stamp = gmdate('Ymd-His');
        return 'athina-content-sync-' . implode('-', app_content_sync_normalize_scopes($scopes)) . '-' . $stamp . '.json';
    }
}

if (!function_exists('app_content_sync_memory_limit_bytes')) {
    function app_content_sync_memory_limit_bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float)$value;
        switch ($unit) {
            case 'g':
                return (int)round($number * 1024 * 1024 * 1024);
            case 'm':
                return (int)round($number * 1024 * 1024);
            case 'k':
                return (int)round($number * 1024);
            default:
                return (int)round($number);
        }
    }
}

if (!function_exists('app_content_sync_ensure_memory_limit')) {
    function app_content_sync_ensure_memory_limit(int $targetMb = 1024): void
    {
        $current = app_content_sync_memory_limit_bytes((string)ini_get('memory_limit'));
        $target = $targetMb * 1024 * 1024;
        if ($current !== -1 && $current < $target) {
            @ini_set('memory_limit', $targetMb . 'M');
        }
    }
}

if (!function_exists('app_content_sync_build_snapshot')) {
    function app_content_sync_build_snapshot(mysqli $conn, array $scopes = []): array
    {
        app_content_sync_ensure_memory_limit();
        app_content_sync_ensure_catalog_schema($conn);
        $scopes = app_content_sync_normalize_scopes($scopes);

        $snapshot = [
            'meta' => [
                'format' => 'athina-content-sync',
                'version' => 1,
                'generated_at' => gmdate('c'),
                'scopes' => $scopes,
                'source' => [
                    'host' => (string)($_SERVER['HTTP_HOST'] ?? php_uname('n')),
                    'project' => basename(app_homepage_project_root()),
                ],
                'warnings' => [],
            ],
        ];
        $warnings = [];

        if (in_array('homepage', $scopes, true)) {
            $result = app_content_sync_export_homepage($conn);
            $snapshot['homepage'] = $result['data'];
            $warnings = array_merge($warnings, $result['warnings']);
        }

        if (in_array('shop', $scopes, true)) {
            $result = app_content_sync_export_shop($conn);
            $snapshot['shop'] = $result['data'];
            $warnings = array_merge($warnings, $result['warnings']);
        }

        $snapshot['meta']['warnings'] = $warnings;

        return [
            'snapshot' => $snapshot,
            'warnings' => $warnings,
        ];
    }
}

if (!function_exists('app_content_sync_export_homepage')) {
    function app_content_sync_export_homepage(mysqli $conn): array
    {
        $defaults = app_homepage_default_config_values();
        $assetConfigKeys = array_fill_keys(app_content_sync_homepage_asset_keys(), true);
        $config = [];
        $assets = [];
        $warnings = [];
        $seenAssets = [];

        foreach (app_content_sync_supported_homepage_keys() as $key) {
            $default = $defaults[$key] ?? '';
            $value = app_homepage_get_config_value($conn, $key, $default);
            $config[$key] = $value;

            if (!isset($assetConfigKeys[$key])) {
                continue;
            }
            if ($value === '' || app_homepage_is_remote_asset($value) || isset($seenAssets[$value])) {
                continue;
            }

            $payload = app_content_sync_file_payload($value);
            if ($payload !== null) {
                $assets[] = $payload;
                $seenAssets[$value] = true;
            } elseif (app_homepage_asset_exists($value)) {
                $seenAssets[$value] = true;
            } else {
                $warnings[] = 'Homepage asset missing during export: ' . $value;
            }
        }

        return [
            'data' => [
                'config' => $config,
                'assets' => $assets,
            ],
            'warnings' => $warnings,
        ];
    }
}

if (!function_exists('app_content_sync_export_shop')) {
    function app_content_sync_export_shop(mysqli $conn): array
    {
        $availableColumns = array_values(array_intersect(app_content_sync_allowed_product_columns(), app_content_sync_product_columns($conn)));
        $columnsSql = '`productID`';
        if (!empty($availableColumns)) {
            $quotedColumns = array_map(
                static function (string $column): string {
                    return '`' . str_replace('`', '``', $column) . '`';
                },
                $availableColumns
            );
            $columnsSql .= ', ' . implode(', ', $quotedColumns);
        }

        $products = [];
        $usedColorIds = [];
        $warnings = [];
        $result = mysqli_query($conn, "SELECT {$columnsSql} FROM products ORDER BY productID ASC");
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $productId = (int)($row['productID'] ?? 0);
                unset($row['productID']);

                $sku = trim((string)($row['sku'] ?? ''));
                if ($sku === '') {
                    $warnings[] = 'Skipped a product without SKU during export (productID ' . $productId . ').';
                    continue;
                }

                $photoPayloads = [];
                $photoStmt = mysqli_prepare($conn, "SELECT photo FROM photos WHERE productID = ? ORDER BY imageID ASC");
                if ($photoStmt) {
                    mysqli_stmt_bind_param($photoStmt, 'i', $productId);
                    mysqli_stmt_execute($photoStmt);
                    $photoRes = mysqli_stmt_get_result($photoStmt);
                    while ($photoRes && ($photoRow = mysqli_fetch_assoc($photoRes))) {
                        $photoData = (string)($photoRow['photo'] ?? '');
                        if ($photoData === '') {
                            continue;
                        }

                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mimeType = (string)($finfo->buffer($photoData) ?: 'image/jpeg');
                        $photoPayloads[] = [
                            'mime_type' => $mimeType,
                            'content_base64' => base64_encode($photoData),
                        ];
                    }
                    mysqli_stmt_close($photoStmt);
                }

                $colorPhotoPayloads = [];
                $colorStmt = mysqli_prepare(
                    $conn,
                    "SELECT pcp.colorID, pcp.photoPath, pcp.sortOrder, c.colorName
                     FROM product_color_photos pcp
                     LEFT JOIN colors c ON c.colorID = pcp.colorID
                     WHERE pcp.productID = ?
                     ORDER BY pcp.sortOrder ASC, pcp.id ASC"
                );
                if ($colorStmt) {
                    mysqli_stmt_bind_param($colorStmt, 'i', $productId);
                    mysqli_stmt_execute($colorStmt);
                    $colorRes = mysqli_stmt_get_result($colorStmt);
                    while ($colorRes && ($colorRow = mysqli_fetch_assoc($colorRes))) {
                        $photoPath = (string)($colorRow['photoPath'] ?? '');
                        $filePayload = app_content_sync_file_payload($photoPath);
                        if ($filePayload === null) {
                            $warnings[] = 'Color photo missing during export for SKU ' . $sku . ': ' . $photoPath;
                        }

                        $colorPhotoPayloads[] = [
                            'colorID' => (int)($colorRow['colorID'] ?? 0),
                            'colorName' => trim((string)($colorRow['colorName'] ?? '')),
                            'sortOrder' => (int)($colorRow['sortOrder'] ?? 0),
                            'photoPath' => $photoPath,
                            'mime_type' => $filePayload['mime_type'] ?? '',
                            'content_base64' => $filePayload['content_base64'] ?? '',
                        ];

                        $colorId = (int)($colorRow['colorID'] ?? 0);
                        if ($colorId > 0) {
                            $usedColorIds[$colorId] = true;
                        }
                    }
                    mysqli_stmt_close($colorStmt);
                }

                $variationPayloads = [];
                $variationStmt = mysqli_prepare(
                    $conn,
                    "SELECT pv.variationID, pv.size, pv.yarnType, pv.colorID, pv.price,
                            c.colorName,
                            COALESCE(vs.quantityAvailable, 0) AS stock,
                            COALESCE(vs.lowStockThreshold, 1) AS lowStockThreshold
                     FROM product_variations pv
                     LEFT JOIN colors c ON c.colorID = pv.colorID
                     LEFT JOIN variation_stock vs ON vs.variationID = pv.variationID
                     WHERE pv.productID = ?
                     ORDER BY pv.variationID ASC"
                );
                if ($variationStmt) {
                    mysqli_stmt_bind_param($variationStmt, 'i', $productId);
                    mysqli_stmt_execute($variationStmt);
                    $variationRes = mysqli_stmt_get_result($variationStmt);
                    while ($variationRes && ($variationRow = mysqli_fetch_assoc($variationRes))) {
                        $variationId = (int)($variationRow['variationID'] ?? 0);
                        $variationPhotoPayloads = [];

                        $variationPhotoStmt = mysqli_prepare(
                            $conn,
                            "SELECT photoPath, sortOrder
                             FROM product_variation_photos
                             WHERE variationID = ?
                             ORDER BY sortOrder ASC, variationPhotoID ASC"
                        );
                        if ($variationPhotoStmt) {
                            mysqli_stmt_bind_param($variationPhotoStmt, 'i', $variationId);
                            mysqli_stmt_execute($variationPhotoStmt);
                            $variationPhotoRes = mysqli_stmt_get_result($variationPhotoStmt);
                            while ($variationPhotoRes && ($variationPhotoRow = mysqli_fetch_assoc($variationPhotoRes))) {
                                $photoPath = (string)($variationPhotoRow['photoPath'] ?? '');
                                $filePayload = app_content_sync_file_payload($photoPath);
                                if ($filePayload === null) {
                                    $warnings[] = 'Variation photo missing during export for SKU ' . $sku . ': ' . $photoPath;
                                }

                                $variationPhotoPayloads[] = [
                                    'photoPath' => $photoPath,
                                    'sortOrder' => (int)($variationPhotoRow['sortOrder'] ?? 0),
                                    'mime_type' => $filePayload['mime_type'] ?? '',
                                    'content_base64' => $filePayload['content_base64'] ?? '',
                                ];
                            }
                            mysqli_stmt_close($variationPhotoStmt);
                        }

                        $variationPayloads[] = [
                            'size' => (string)($variationRow['size'] ?? ''),
                            'yarnType' => (string)($variationRow['yarnType'] ?? ''),
                            'colorID' => isset($variationRow['colorID']) ? (int)$variationRow['colorID'] : null,
                            'colorName' => trim((string)($variationRow['colorName'] ?? '')),
                            'price' => isset($variationRow['price']) ? (float)$variationRow['price'] : null,
                            'stock' => (int)($variationRow['stock'] ?? 0),
                            'lowStockThreshold' => (int)($variationRow['lowStockThreshold'] ?? 1),
                            'photos' => $variationPhotoPayloads,
                        ];

                        $colorId = isset($variationRow['colorID']) ? (int)$variationRow['colorID'] : 0;
                        if ($colorId > 0) {
                            $usedColorIds[$colorId] = true;
                        }
                    }
                    mysqli_stmt_close($variationStmt);
                }

                $products[] = [
                    'source_product_id' => $productId,
                    'sku' => $sku,
                    'fields' => $row,
                    'photos' => $photoPayloads,
                    'color_photos' => $colorPhotoPayloads,
                    'variations' => $variationPayloads,
                ];
            }
            mysqli_free_result($result);
        }

        $colorPayloads = [];
        if (!empty($usedColorIds)) {
            $colorIdsSql = implode(',', array_map('intval', array_keys($usedColorIds)));
            $colorRes = mysqli_query(
                $conn,
                "SELECT colorID, colorName, globalInventoryAvailable, isActive
                 FROM colors
                 WHERE colorID IN ({$colorIdsSql})
                 ORDER BY colorName ASC, colorID ASC"
            );
            if ($colorRes) {
                while ($colorRow = mysqli_fetch_assoc($colorRes)) {
                    $colorPayloads[] = [
                        'source_color_id' => (int)($colorRow['colorID'] ?? 0),
                        'colorID' => (int)($colorRow['colorID'] ?? 0),
                        'colorName' => trim((string)($colorRow['colorName'] ?? '')),
                        'globalInventoryAvailable' => (int)($colorRow['globalInventoryAvailable'] ?? 0),
                        'isActive' => (int)($colorRow['isActive'] ?? 1),
                    ];
                }
                mysqli_free_result($colorRes);
            }
        }

        return [
            'data' => [
                'colors' => $colorPayloads,
                'products' => $products,
            ],
            'warnings' => $warnings,
        ];
    }
}

if (!function_exists('app_content_sync_parse_snapshot_json')) {
    function app_content_sync_parse_snapshot_json(string $json): array
    {
        $snapshot = json_decode($json, true);
        if (!is_array($snapshot)) {
            throw new InvalidArgumentException('The snapshot file is not valid JSON.');
        }

        $format = (string)($snapshot['meta']['format'] ?? '');
        if ($format !== 'athina-content-sync') {
            throw new InvalidArgumentException('The snapshot file does not match the Athina content sync format.');
        }

        return $snapshot;
    }
}

if (!function_exists('app_content_sync_load_snapshot_file')) {
    function app_content_sync_load_snapshot_file(string $path): array
    {
        app_content_sync_ensure_memory_limit();
        if (!is_file($path)) {
            throw new RuntimeException('The content sync snapshot file was not found.');
        }

        $json = file_get_contents($path);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('The content sync snapshot file could not be read.');
        }

        $snapshot = app_content_sync_parse_snapshot_json($json);
        $chunkFiles = $snapshot['chunk_files'] ?? null;
        if (!is_array($chunkFiles) || empty($chunkFiles)) {
            return $snapshot;
        }

        $baseDir = dirname($path);
        $assembledJson = '';
        foreach ($chunkFiles as $chunkFile) {
            $chunkFile = basename((string)$chunkFile);
            if ($chunkFile === '') {
                throw new RuntimeException('The content sync snapshot manifest contains an invalid chunk file.');
            }

            $chunkPath = $baseDir . DIRECTORY_SEPARATOR . $chunkFile;
            if (!is_file($chunkPath)) {
                throw new RuntimeException('Missing content sync snapshot chunk file: ' . $chunkFile);
            }

            $chunkContents = file_get_contents($chunkPath);
            if (!is_string($chunkContents) || $chunkContents === '') {
                throw new RuntimeException('The content sync snapshot chunk could not be read: ' . $chunkFile);
            }
            $assembledJson .= $chunkContents;
        }

        return app_content_sync_parse_snapshot_json($assembledJson);
    }
}

if (!function_exists('app_content_sync_write_snapshot_file')) {
    function app_content_sync_write_snapshot_file(array $snapshot): string
    {
        app_content_sync_ensure_memory_limit();
        app_content_sync_ensure_snapshot_directory();
        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('Could not encode the content sync snapshot.');
        }

        $path = app_content_sync_snapshot_absolute_path();
        $directory = dirname($path);
        $baseName = pathinfo($path, PATHINFO_FILENAME);
        $existingChunks = glob($directory . DIRECTORY_SEPARATOR . $baseName . '.part*.chunk');
        if (!is_array($existingChunks)) {
            $existingChunks = [];
        }

        $maxChunkBytes = 95 * 1024 * 1024;
        if (strlen($json) <= $maxChunkBytes) {
            foreach ($existingChunks as $existingChunk) {
                if (is_file($existingChunk)) {
                    @unlink($existingChunk);
                }
            }

            if (file_put_contents($path, $json) === false) {
                throw new RuntimeException('Could not write the repo content sync snapshot.');
            }

            return app_content_sync_snapshot_relative_path();
        }

        $chunks = str_split($json, $maxChunkBytes);
        $chunkFiles = [];
        foreach ($chunks as $index => $chunkContents) {
            $chunkName = $baseName . '.part' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) . '.chunk';
            $chunkPath = $directory . DIRECTORY_SEPARATOR . $chunkName;
            if (file_put_contents($chunkPath, $chunkContents) === false) {
                throw new RuntimeException('Could not write the repo content sync snapshot chunk ' . $chunkName . '.');
            }
            $chunkFiles[] = $chunkName;
        }

        foreach ($existingChunks as $existingChunk) {
            if (!in_array(basename($existingChunk), $chunkFiles, true) && is_file($existingChunk)) {
                @unlink($existingChunk);
            }
        }

        $manifest = [
            'meta' => $snapshot['meta'] ?? [],
            'chunked_snapshot' => true,
            'assembled_bytes' => strlen($json),
            'chunk_files' => $chunkFiles,
        ];
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($manifestJson) || $manifestJson === '') {
            throw new RuntimeException('Could not encode the content sync snapshot manifest.');
        }

        if (file_put_contents($path, $manifestJson) === false) {
            throw new RuntimeException('Could not write the repo content sync snapshot manifest.');
        }

        return app_content_sync_snapshot_relative_path();
    }
}

if (!function_exists('app_content_sync_import_snapshot')) {
    function app_content_sync_import_snapshot(mysqli $conn, array $snapshot, array $scopes = []): array
    {
        app_content_sync_ensure_catalog_schema($conn);
        $scopes = app_content_sync_normalize_scopes($scopes);

        $messages = [];
        $warnings = [];

        if (in_array('homepage', $scopes, true) && isset($snapshot['homepage']) && is_array($snapshot['homepage'])) {
            $result = app_content_sync_import_homepage($conn, $snapshot['homepage']);
            $messages = array_merge($messages, $result['messages']);
            $warnings = array_merge($warnings, $result['warnings']);
        }

        if (in_array('shop', $scopes, true) && isset($snapshot['shop']) && is_array($snapshot['shop'])) {
            $result = app_content_sync_import_shop($conn, $snapshot['shop']);
            $messages = array_merge($messages, $result['messages']);
            $warnings = array_merge($warnings, $result['warnings']);
        }

        return [
            'messages' => $messages,
            'warnings' => $warnings,
        ];
    }
}

if (!function_exists('app_content_sync_import_homepage')) {
    function app_content_sync_import_homepage(mysqli $conn, array $payload): array
    {
        $messages = [];
        $warnings = [];
        $writtenAssets = 0;
        $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];
        $assets = is_array($payload['assets'] ?? null) ? $payload['assets'] : [];

        foreach ($assets as $asset) {
            try {
                app_content_sync_restore_file_payload((array)$asset);
                $writtenAssets++;
            } catch (Throwable $e) {
                $warnings[] = $e->getMessage();
            }
        }

        $allowedConfig = array_fill_keys(app_content_sync_supported_homepage_keys(), true);
        $assetConfigKeys = array_fill_keys(app_content_sync_homepage_asset_keys(), true);
        $savedConfig = 0;

        foreach ($config as $key => $value) {
            $key = (string)$key;
            if (!isset($allowedConfig[$key])) {
                continue;
            }

            $stringValue = is_string($value) ? $value : ($value === null ? '' : (string)$value);
            if (
                isset($assetConfigKeys[$key]) &&
                $stringValue !== '' &&
                !app_homepage_is_remote_asset($stringValue) &&
                !app_homepage_asset_exists($stringValue)
            ) {
                $warnings[] = 'Skipped homepage config "' . $key . '" because the asset was not restored: ' . $stringValue;
                continue;
            }

            app_homepage_set_config_value($conn, $key, $stringValue);
            $savedConfig++;
        }

        $headerLogoPath = (string)($config['homepage_header_logo_path'] ?? '');
        if ($headerLogoPath !== '') {
            app_homepage_set_config_value($conn, 'logo_path', $headerLogoPath);
        } elseif (array_key_exists('logo_path', $config)) {
            app_homepage_set_config_value($conn, 'logo_path', (string)$config['logo_path']);
        }

        $messages[] = 'Homepage sync imported (' . $savedConfig . ' settings, ' . $writtenAssets . ' file assets).';

        return [
            'messages' => $messages,
            'warnings' => $warnings,
        ];
    }
}

if (!function_exists('app_content_sync_find_product_id_by_sku')) {
    function app_content_sync_find_product_id_by_sku(mysqli $conn, string $sku): int
    {
        $stmt = mysqli_prepare($conn, "SELECT productID FROM products WHERE sku = ? LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('Could not check for an existing product by SKU.');
        }

        mysqli_stmt_bind_param($stmt, 's', $sku);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $productId);
        $found = mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        return $found ? (int)$productId : 0;
    }
}

if (!function_exists('app_content_sync_sql_literal')) {
    function app_content_sync_sql_literal(mysqli $conn, string $column, $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (in_array($column, app_content_sync_int_product_columns(), true)) {
            return (string)(int)$value;
        }

        if (in_array($column, app_content_sync_float_product_columns(), true)) {
            return is_numeric($value) ? (string)(float)$value : '0';
        }

        return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
    }
}

if (!function_exists('app_content_sync_upsert_product')) {
    function app_content_sync_upsert_product(mysqli $conn, array $fields, array $availableColumns): int
    {
        $availableMap = array_fill_keys($availableColumns, true);
        $usableFields = [];
        foreach ($fields as $column => $value) {
            $column = (string)$column;
            if (!isset($availableMap[$column])) {
                continue;
            }
            $usableFields[$column] = $value;
        }

        $sku = trim((string)($usableFields['sku'] ?? ''));
        if ($sku === '') {
            throw new InvalidArgumentException('Each synced product must include a SKU.');
        }

        $existingProductId = app_content_sync_find_product_id_by_sku($conn, $sku);
        if ($existingProductId > 0) {
            $assignments = [];
            foreach ($usableFields as $column => $value) {
                if ($column === 'sku') {
                    continue;
                }
                $assignments[] = '`' . str_replace('`', '``', $column) . '` = ' . app_content_sync_sql_literal($conn, $column, $value);
            }

            if (!empty($assignments)) {
                $sql = "UPDATE products SET " . implode(', ', $assignments) . " WHERE productID = " . (int)$existingProductId;
                if (!mysqli_query($conn, $sql)) {
                    throw new RuntimeException('Could not update the synced product with SKU ' . $sku . '.');
                }
            }

            return $existingProductId;
        }

        $columns = [];
        $values = [];
        foreach ($usableFields as $column => $value) {
            $columns[] = '`' . str_replace('`', '``', $column) . '`';
            $values[] = app_content_sync_sql_literal($conn, $column, $value);
        }

        if (empty($columns)) {
            throw new InvalidArgumentException('No synced product fields were available for SKU ' . $sku . '.');
        }

        $sql = "INSERT INTO products (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";
        if (!mysqli_query($conn, $sql)) {
            throw new RuntimeException('Could not create the synced product with SKU ' . $sku . '.');
        }

        return (int)mysqli_insert_id($conn);
    }
}

if (!function_exists('app_content_sync_insert_blob_photo')) {
    function app_content_sync_insert_blob_photo(mysqli $conn, int $productId, string $photoData): void
    {
        $stmt = mysqli_prepare($conn, "INSERT INTO photos (photo, productID) VALUES (?, ?)");
        if (!$stmt) {
            throw new RuntimeException('Could not prepare the synced product photo insert.');
        }

        mysqli_stmt_bind_param($stmt, 'si', $photoData, $productId);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new RuntimeException('Could not insert a synced product photo.');
        }

        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('app_content_sync_find_color_id_by_name')) {
    function app_content_sync_find_color_id_by_name(mysqli $conn, string $colorName): int
    {
        $colorName = trim($colorName);
        if ($colorName === '') {
            return 0;
        }

        $stmt = mysqli_prepare($conn, "SELECT colorID FROM colors WHERE LOWER(colorName) = LOWER(?) LIMIT 1");
        if (!$stmt) {
            return 0;
        }

        mysqli_stmt_bind_param($stmt, 's', $colorName);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        return $row ? (int)($row['colorID'] ?? 0) : 0;
    }
}

if (!function_exists('app_content_sync_color_exists_by_id')) {
    function app_content_sync_color_exists_by_id(mysqli $conn, int $colorId): bool
    {
        if ($colorId <= 0) {
            return false;
        }

        $stmt = mysqli_prepare($conn, "SELECT colorID FROM colors WHERE colorID = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, 'i', $colorId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $exists = $result && mysqli_num_rows($result) > 0;
        mysqli_stmt_close($stmt);

        return $exists;
    }
}

if (!function_exists('app_content_sync_next_color_id')) {
    function app_content_sync_next_color_id(mysqli $conn): int
    {
        $result = mysqli_query($conn, "SELECT COALESCE(MAX(colorID), 0) + 1 AS nextId FROM colors");
        if ($result && ($row = mysqli_fetch_assoc($result))) {
            return max(1, (int)($row['nextId'] ?? 1));
        }

        return 1;
    }
}

if (!function_exists('app_content_sync_ensure_color')) {
    function app_content_sync_ensure_color(mysqli $conn, array $colorPayload, array &$colorIdMap = []): int
    {
        static $resolvedByName = [];

        $sourceColorId = (int)($colorPayload['source_color_id'] ?? ($colorPayload['colorID'] ?? 0));
        $colorName = trim((string)($colorPayload['colorName'] ?? ($colorPayload['name'] ?? '')));
        $globalInventory = max(0, (int)($colorPayload['globalInventoryAvailable'] ?? 0));
        $isActive = (int)($colorPayload['isActive'] ?? 1) > 0 ? 1 : 0;

        if ($sourceColorId > 0 && isset($colorIdMap[$sourceColorId])) {
            return (int)$colorIdMap[$sourceColorId];
        }

        $nameKey = $colorName !== '' ? strtolower($colorName) : '';
        if ($nameKey !== '' && isset($resolvedByName[$nameKey])) {
            $resolvedId = (int)$resolvedByName[$nameKey];
            if ($sourceColorId > 0) {
                $colorIdMap[$sourceColorId] = $resolvedId;
            }
            return $resolvedId;
        }

        if ($colorName !== '') {
            $existingByName = app_content_sync_find_color_id_by_name($conn, $colorName);
            if ($existingByName > 0) {
                $resolvedByName[$nameKey] = $existingByName;
                if ($sourceColorId > 0) {
                    $colorIdMap[$sourceColorId] = $existingByName;
                }
                return $existingByName;
            }
        }

        if ($sourceColorId > 0 && app_content_sync_color_exists_by_id($conn, $sourceColorId)) {
            if ($colorName !== '') {
                $updateStmt = mysqli_prepare(
                    $conn,
                    "UPDATE colors
                     SET colorName = COALESCE(NULLIF(colorName, ''), ?),
                         globalInventoryAvailable = GREATEST(globalInventoryAvailable, ?),
                         isActive = GREATEST(isActive, ?)
                     WHERE colorID = ?"
                );
                if ($updateStmt) {
                    mysqli_stmt_bind_param($updateStmt, 'siii', $colorName, $globalInventory, $isActive, $sourceColorId);
                    mysqli_stmt_execute($updateStmt);
                    mysqli_stmt_close($updateStmt);
                }
            }

            if ($nameKey !== '') {
                $resolvedByName[$nameKey] = $sourceColorId;
            }
            $colorIdMap[$sourceColorId] = $sourceColorId;
            return $sourceColorId;
        }

        $targetColorId = $sourceColorId > 0 ? $sourceColorId : app_content_sync_next_color_id($conn);
        if ($targetColorId > 0 && app_content_sync_color_exists_by_id($conn, $targetColorId)) {
            $targetColorId = app_content_sync_next_color_id($conn);
        }

        if ($colorName === '') {
            $colorName = 'Imported Color ' . $targetColorId;
            $nameKey = strtolower($colorName);
        }

        $insertStmt = mysqli_prepare(
            $conn,
            "INSERT INTO colors (colorID, colorName, globalInventoryAvailable, isActive)
             VALUES (?, ?, ?, ?)"
        );
        if (!$insertStmt) {
            throw new RuntimeException('Could not prepare the synced color insert.');
        }

        mysqli_stmt_bind_param($insertStmt, 'isii', $targetColorId, $colorName, $globalInventory, $isActive);
        if (!mysqli_stmt_execute($insertStmt)) {
            $insertError = mysqli_stmt_error($insertStmt);
            mysqli_stmt_close($insertStmt);

            $existingByName = $colorName !== '' ? app_content_sync_find_color_id_by_name($conn, $colorName) : 0;
            if ($existingByName > 0) {
                $resolvedByName[$nameKey] = $existingByName;
                if ($sourceColorId > 0) {
                    $colorIdMap[$sourceColorId] = $existingByName;
                }
                return $existingByName;
            }

            throw new RuntimeException('Could not insert a synced color: ' . $insertError);
        }
        mysqli_stmt_close($insertStmt);

        if ($nameKey !== '') {
            $resolvedByName[$nameKey] = $targetColorId;
        }
        if ($sourceColorId > 0) {
            $colorIdMap[$sourceColorId] = $targetColorId;
        }

        return $targetColorId;
    }
}

if (!function_exists('app_content_sync_insert_variation')) {
    function app_content_sync_insert_variation(mysqli $conn, int $productId, array $variationPayload): int
    {
        $size = trim((string)($variationPayload['size'] ?? ''));
        $yarnType = trim((string)($variationPayload['yarnType'] ?? ''));
        $colorId = isset($variationPayload['colorID']) && (int)$variationPayload['colorID'] > 0
            ? (int)$variationPayload['colorID']
            : null;
        $price = isset($variationPayload['price']) && is_numeric($variationPayload['price'])
            ? (float)$variationPayload['price']
            : 0.0;

        if ($colorId === null) {
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO product_variations (productID, size, yarnType, colorID, price)
                 VALUES (?, ?, ?, NULL, ?)"
            );
            if (!$stmt) {
                throw new RuntimeException('Could not prepare the synced product variation insert.');
            }
            mysqli_stmt_bind_param($stmt, 'issd', $productId, $size, $yarnType, $price);
        } else {
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO product_variations (productID, size, yarnType, colorID, price)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                throw new RuntimeException('Could not prepare the synced product variation insert.');
            }
            mysqli_stmt_bind_param($stmt, 'issid', $productId, $size, $yarnType, $colorId, $price);
        }

        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new RuntimeException('Could not insert a synced product variation.');
        }

        $variationId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        return $variationId;
    }
}

if (!function_exists('app_content_sync_insert_variation_stock')) {
    function app_content_sync_insert_variation_stock(mysqli $conn, int $variationId, array $variationPayload): void
    {
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO variation_stock (variationID, quantityAvailable, lowStockThreshold, lastStockChangeSource)
             VALUES (?, ?, ?, 'content-sync')"
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare the synced variation stock insert.');
        }

        $quantityAvailable = max(0, (int)($variationPayload['stock'] ?? 0));
        $lowStockThreshold = max(1, (int)($variationPayload['lowStockThreshold'] ?? 1));
        mysqli_stmt_bind_param($stmt, 'iii', $variationId, $quantityAvailable, $lowStockThreshold);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new RuntimeException('Could not insert synced variation stock.');
        }

        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('app_content_sync_insert_variation_photo')) {
    function app_content_sync_insert_variation_photo(mysqli $conn, int $variationId, string $photoPath, int $sortOrder): void
    {
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO product_variation_photos (variationID, photoPath, sortOrder)
             VALUES (?, ?, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare the synced variation photo insert.');
        }

        mysqli_stmt_bind_param($stmt, 'isi', $variationId, $photoPath, $sortOrder);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new RuntimeException('Could not insert a synced variation photo.');
        }

        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('app_content_sync_import_shop')) {
    function app_content_sync_import_shop(mysqli $conn, array $payload): array
    {
        $messages = [];
        $warnings = [];
        $colors = is_array($payload['colors'] ?? null) ? $payload['colors'] : [];
        $products = is_array($payload['products'] ?? null) ? $payload['products'] : [];
        $availableColumns = array_values(array_intersect(app_content_sync_allowed_product_columns(), app_content_sync_product_columns($conn)));

        mysqli_begin_transaction($conn);

        try {
            $colorIdMap = [];
            $productCount = 0;
            $mainPhotoCount = 0;
            $colorPhotoCount = 0;
            $variationCount = 0;
            $variationPhotoCount = 0;

            foreach ($colors as $colorPayload) {
                try {
                    app_content_sync_ensure_color($conn, (array)$colorPayload, $colorIdMap);
                } catch (Throwable $e) {
                    $warnings[] = 'Skipped a synced color definition: ' . $e->getMessage();
                }
            }

            foreach ($products as $productPayload) {
                $fields = is_array($productPayload['fields'] ?? null) ? $productPayload['fields'] : [];
                $sku = trim((string)($productPayload['sku'] ?? ($fields['sku'] ?? '')));
                if ($sku === '') {
                    $warnings[] = 'Skipped a synced product without SKU.';
                    continue;
                }
                $fields['sku'] = $sku;

                $productId = app_content_sync_upsert_product($conn, $fields, $availableColumns);
                $productCount++;

                if (!mysqli_query($conn, "DELETE FROM photos WHERE productID = " . (int)$productId)) {
                    throw new RuntimeException('Could not clear existing product photos for SKU ' . $sku . '.');
                }

                $photos = is_array($productPayload['photos'] ?? null) ? $productPayload['photos'] : [];
                foreach ($photos as $photoPayload) {
                    $encoded = (string)($photoPayload['content_base64'] ?? '');
                    if ($encoded === '') {
                        $warnings[] = 'Skipped an empty product photo for SKU ' . $sku . '.';
                        continue;
                    }

                    $photoData = base64_decode($encoded, true);
                    if (!is_string($photoData)) {
                        $warnings[] = 'Skipped an invalid product photo for SKU ' . $sku . '.';
                        continue;
                    }

                    app_content_sync_insert_blob_photo($conn, $productId, $photoData);
                    $mainPhotoCount++;
                }

                $existingColorPaths = [];
                $existingColorStmt = mysqli_prepare($conn, "SELECT photoPath FROM product_color_photos WHERE productID = ?");
                if ($existingColorStmt) {
                    mysqli_stmt_bind_param($existingColorStmt, 'i', $productId);
                    mysqli_stmt_execute($existingColorStmt);
                    $existingColorRes = mysqli_stmt_get_result($existingColorStmt);
                    while ($existingColorRes && ($existingRow = mysqli_fetch_assoc($existingColorRes))) {
                        $existingColorPaths[] = (string)($existingRow['photoPath'] ?? '');
                    }
                    mysqli_stmt_close($existingColorStmt);
                }

                if (!mysqli_query($conn, "DELETE FROM product_color_photos WHERE productID = " . (int)$productId)) {
                    throw new RuntimeException('Could not clear existing colour photos for SKU ' . $sku . '.');
                }

                foreach ($existingColorPaths as $oldPath) {
                    if (!app_content_sync_is_safe_write_path($oldPath)) {
                        continue;
                    }

                    $absoluteOldPath = app_content_sync_absolute_path($oldPath);
                    if ($absoluteOldPath !== '' && is_file($absoluteOldPath)) {
                        @unlink($absoluteOldPath);
                    }
                }

                $existingVariationIds = [];
                $existingVariationPaths = [];
                $existingVariationStmt = mysqli_prepare(
                    $conn,
                    "SELECT pv.variationID, pvp.photoPath
                     FROM product_variations pv
                     LEFT JOIN product_variation_photos pvp ON pvp.variationID = pv.variationID
                     WHERE pv.productID = ?"
                );
                if ($existingVariationStmt) {
                    mysqli_stmt_bind_param($existingVariationStmt, 'i', $productId);
                    mysqli_stmt_execute($existingVariationStmt);
                    $existingVariationRes = mysqli_stmt_get_result($existingVariationStmt);
                    while ($existingVariationRes && ($existingVariationRow = mysqli_fetch_assoc($existingVariationRes))) {
                        $variationId = (int)($existingVariationRow['variationID'] ?? 0);
                        if ($variationId > 0) {
                            $existingVariationIds[$variationId] = true;
                        }
                        $oldVariationPath = trim((string)($existingVariationRow['photoPath'] ?? ''));
                        if ($oldVariationPath !== '') {
                            $existingVariationPaths[$oldVariationPath] = true;
                        }
                    }
                    mysqli_stmt_close($existingVariationStmt);
                }

                if (!empty($existingVariationIds)) {
                    $variationIdList = implode(',', array_map('intval', array_keys($existingVariationIds)));
                    if (!mysqli_query($conn, "DELETE FROM variation_stock WHERE variationID IN ({$variationIdList})")) {
                        throw new RuntimeException('Could not clear existing variation stock for SKU ' . $sku . '.');
                    }
                    if (!mysqli_query($conn, "DELETE FROM product_variation_photos WHERE variationID IN ({$variationIdList})")) {
                        throw new RuntimeException('Could not clear existing variation photos for SKU ' . $sku . '.');
                    }
                }
                if (!mysqli_query($conn, "DELETE FROM product_variations WHERE productID = " . (int)$productId)) {
                    throw new RuntimeException('Could not clear existing product variations for SKU ' . $sku . '.');
                }

                foreach (array_keys($existingVariationPaths) as $oldVariationPath) {
                    if (!app_content_sync_is_safe_write_path($oldVariationPath)) {
                        continue;
                    }

                    $absoluteOldVariationPath = app_content_sync_absolute_path($oldVariationPath);
                    if ($absoluteOldVariationPath !== '' && is_file($absoluteOldVariationPath)) {
                        @unlink($absoluteOldVariationPath);
                    }
                }

                $colorPhotos = is_array($productPayload['color_photos'] ?? null) ? $productPayload['color_photos'] : [];
                foreach ($colorPhotos as $colorPhotoPayload) {
                    $colorId = app_content_sync_ensure_color($conn, (array)$colorPhotoPayload, $colorIdMap);
                    $photoPath = str_replace('\\', '/', ltrim(trim((string)($colorPhotoPayload['photoPath'] ?? '')), '/\\'));
                    $sortOrder = (int)($colorPhotoPayload['sortOrder'] ?? 0);
                    $encoded = (string)($colorPhotoPayload['content_base64'] ?? '');

                    if ($colorId <= 0) {
                        $warnings[] = 'Skipped a colour photo with invalid colour ID for SKU ' . $sku . '.';
                        continue;
                    }
                    if (!app_content_sync_is_safe_write_path($photoPath)) {
                        $warnings[] = 'Skipped an unsafe colour photo path for SKU ' . $sku . ': ' . $photoPath;
                        continue;
                    }
                    if ($encoded === '') {
                        $warnings[] = 'Skipped a missing colour photo payload for SKU ' . $sku . ': ' . $photoPath;
                        continue;
                    }

                    app_content_sync_restore_file_payload([
                        'path' => $photoPath,
                        'content_base64' => $encoded,
                    ]);

                    $stmt = mysqli_prepare(
                        $conn,
                        "INSERT INTO product_color_photos (productID, colorID, photoPath, sortOrder)
                         VALUES (?, ?, ?, ?)"
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Could not prepare the synced colour photo insert.');
                    }

                    mysqli_stmt_bind_param($stmt, 'iisi', $productId, $colorId, $photoPath, $sortOrder);
                    if (!mysqli_stmt_execute($stmt)) {
                        mysqli_stmt_close($stmt);
                        throw new RuntimeException('Could not insert a synced colour photo for SKU ' . $sku . '.');
                    }
                    mysqli_stmt_close($stmt);
                    $colorPhotoCount++;
                }

                $variations = is_array($productPayload['variations'] ?? null) ? $productPayload['variations'] : [];
                foreach ($variations as $variationPayload) {
                    $resolvedVariationPayload = (array)$variationPayload;
                    if (isset($resolvedVariationPayload['colorID']) && (int)$resolvedVariationPayload['colorID'] > 0) {
                        $resolvedVariationPayload['colorID'] = app_content_sync_ensure_color($conn, $resolvedVariationPayload, $colorIdMap);
                    }

                    $variationId = app_content_sync_insert_variation($conn, $productId, $resolvedVariationPayload);
                    app_content_sync_insert_variation_stock($conn, $variationId, (array)$variationPayload);
                    $variationCount++;

                    $variationPhotos = is_array($resolvedVariationPayload['photos'] ?? null) ? $resolvedVariationPayload['photos'] : [];
                    foreach ($variationPhotos as $variationPhotoPayload) {
                        $photoPath = str_replace('\\', '/', ltrim(trim((string)($variationPhotoPayload['photoPath'] ?? '')), '/\\'));
                        $sortOrder = (int)($variationPhotoPayload['sortOrder'] ?? 0);
                        $encoded = (string)($variationPhotoPayload['content_base64'] ?? '');

                        if (!app_content_sync_is_safe_write_path($photoPath)) {
                            $warnings[] = 'Skipped an unsafe variation photo path for SKU ' . $sku . ': ' . $photoPath;
                            continue;
                        }
                        if ($encoded === '') {
                            $warnings[] = 'Skipped a missing variation photo payload for SKU ' . $sku . ': ' . $photoPath;
                            continue;
                        }

                        app_content_sync_restore_file_payload([
                            'path' => $photoPath,
                            'content_base64' => $encoded,
                        ]);
                        app_content_sync_insert_variation_photo($conn, $variationId, $photoPath, $sortOrder);
                        $variationPhotoCount++;
                    }
                }
            }

            mysqli_commit($conn);
            $messages[] = 'Shop sync imported (' . $productCount . ' products, ' . $mainPhotoCount . ' main photos, ' . $colorPhotoCount . ' colour photos, ' . $variationCount . ' variations, ' . $variationPhotoCount . ' variation photos).';
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            throw $e;
        }

        return [
            'messages' => $messages,
            'warnings' => $warnings,
        ];
    }
}
