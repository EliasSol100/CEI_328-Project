<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../authentication/database.php';

if (!function_exists('app_homepage_project_root')) {
    function app_homepage_project_root(): string
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('app_homepage_upload_dir')) {
    function app_homepage_upload_dir(): string
    {
        return app_homepage_project_root() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'homepage';
    }
}

if (!function_exists('app_homepage_upload_specs')) {
    function app_homepage_upload_specs(): array
    {
        return [
            'homepage_hero_image' => [
                'label' => 'Hero section background',
                'width' => 1172,
                'height' => 600,
                'filename' => 'hero-section',
            ],
            'homepage_collection_1_image' => [
                'label' => 'Shop by Collection image 1',
                'width' => 261,
                'height' => 260,
                'filename' => 'shop-collection-1',
            ],
            'homepage_collection_2_image' => [
                'label' => 'Shop by Collection image 2',
                'width' => 261,
                'height' => 260,
                'filename' => 'shop-collection-2',
            ],
            'homepage_collection_3_image' => [
                'label' => 'Shop by Collection image 3',
                'width' => 261,
                'height' => 260,
                'filename' => 'shop-collection-3',
            ],
            'homepage_journey_1_image' => [
                'label' => 'Follow Our Journey image 1',
                'width' => 361,
                'height' => 260,
                'filename' => 'follow-journey-1',
            ],
            'homepage_journey_2_image' => [
                'label' => 'Follow Our Journey image 2',
                'width' => 361,
                'height' => 260,
                'filename' => 'follow-journey-2',
            ],
            'homepage_journey_3_image' => [
                'label' => 'Follow Our Journey image 3',
                'width' => 361,
                'height' => 260,
                'filename' => 'follow-journey-3',
            ],
            'homepage_header_logo_path' => [
                'label' => 'Header logo',
                'width' => 50,
                'height' => 50,
                'filename' => 'header-logo',
            ],
        ];
    }
}

if (!function_exists('app_homepage_default_config_values')) {
    function app_homepage_default_config_values(): array
    {
        $defaultImage = 'https://images.unsplash.com/photo-1581833971358-2c8b550f87b3?w=1200&q=80';

        return [
            'homepage_hero_image' => $defaultImage,
            'homepage_collection_1_image' => $defaultImage,
            'homepage_collection_1_label' => 'Dragon Plushies',
            'homepage_collection_1_link' => 'shop.php?category=dragon',
            'homepage_collection_2_image' => $defaultImage,
            'homepage_collection_2_label' => 'Electric Friends',
            'homepage_collection_2_link' => 'shop.php?category=electric',
            'homepage_collection_3_image' => $defaultImage,
            'homepage_collection_3_label' => 'Sea Creatures',
            'homepage_collection_3_link' => 'shop.php?category=sea',
            'homepage_journey_1_image' => $defaultImage,
            'homepage_journey_2_image' => $defaultImage,
            'homepage_journey_3_image' => $defaultImage,
            'homepage_header_logo_path' => '',
        ];
    }
}

if (!function_exists('app_homepage_ensure_schema')) {
    function app_homepage_ensure_schema(mysqli $conn): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS system_config (
                config_key VARCHAR(100) NOT NULL PRIMARY KEY,
                config_value TEXT DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $defaults = app_homepage_default_config_values();
        $existing = [];
        $result = mysqli_query($conn, "SELECT config_key FROM system_config");
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $existing[(string)$row['config_key']] = true;
            }
            mysqli_free_result($result);
        }

        $insertStmt = mysqli_prepare($conn, "INSERT INTO system_config (config_key, config_value) VALUES (?, ?)");
        if (!$insertStmt) {
            return;
        }

        foreach ($defaults as $key => $value) {
            if (isset($existing[$key])) {
                continue;
            }

            mysqli_stmt_bind_param($insertStmt, 'ss', $key, $value);
            mysqli_stmt_execute($insertStmt);
        }
        mysqli_stmt_close($insertStmt);
    }
}

if (!function_exists('app_homepage_get_config_value')) {
    function app_homepage_get_config_value(mysqli $conn, string $key, string $default = ''): string
    {
        app_homepage_ensure_schema($conn);

        $stmt = mysqli_prepare($conn, "SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1");
        if (!$stmt) {
            return $default;
        }

        mysqli_stmt_bind_param($stmt, 's', $key);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $value);
        $fetched = mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        if (!$fetched || $value === null || $value === '') {
            return $default;
        }

        return (string)$value;
    }
}

if (!function_exists('app_homepage_set_config_value')) {
    function app_homepage_set_config_value(mysqli $conn, string $key, string $value): bool
    {
        app_homepage_ensure_schema($conn);

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO system_config (config_key, config_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
        );
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, 'ss', $key, $value);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return (bool)$ok;
    }
}

if (!function_exists('app_homepage_is_remote_asset')) {
    function app_homepage_is_remote_asset(string $path): bool
    {
        return (bool)preg_match('#^https?://#i', $path);
    }
}

if (!function_exists('app_homepage_asset_exists')) {
    function app_homepage_asset_exists(string $path): bool
    {
        $path = trim($path);
        if ($path === '') {
            return false;
        }

        if (app_homepage_is_remote_asset($path)) {
            return true;
        }

        $absolutePath = app_homepage_project_root() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
        return is_file($absolutePath);
    }
}

if (!function_exists('app_homepage_resolve_asset')) {
    function app_homepage_resolve_asset(string $path, string $fallback = ''): string
    {
        if (app_homepage_asset_exists($path)) {
            return $path;
        }

        if ($fallback !== '' && app_homepage_asset_exists($fallback)) {
            return $fallback;
        }

        return app_homepage_is_remote_asset($fallback) ? $fallback : '';
    }
}

if (!function_exists('app_homepage_asset_url')) {
    function app_homepage_asset_url(string $path, string $prefix = ''): string
    {
        $path = trim($path);
        if ($path === '' || app_homepage_is_remote_asset($path)) {
            return $path;
        }

        return $prefix . ltrim($path, '/\\');
    }
}

if (!function_exists('app_homepage_load_settings')) {
    function app_homepage_load_settings(mysqli $conn): array
    {
        $defaults = app_homepage_default_config_values();
        app_homepage_ensure_schema($conn);

        $settings = [
            'hero_image' => app_homepage_resolve_asset(
                app_homepage_get_config_value($conn, 'homepage_hero_image', $defaults['homepage_hero_image']),
                $defaults['homepage_hero_image']
            ),
            'collections' => [],
            'journey_images' => [],
            'header_logo_path' => '',
        ];

        for ($i = 1; $i <= 3; $i++) {
            $settings['collections'][] = [
                'image' => app_homepage_resolve_asset(
                    app_homepage_get_config_value($conn, 'homepage_collection_' . $i . '_image', $defaults['homepage_collection_' . $i . '_image']),
                    $defaults['homepage_collection_' . $i . '_image']
                ),
                'label' => app_homepage_get_config_value(
                    $conn,
                    'homepage_collection_' . $i . '_label',
                    $defaults['homepage_collection_' . $i . '_label']
                ),
                'link' => app_homepage_get_config_value(
                    $conn,
                    'homepage_collection_' . $i . '_link',
                    $defaults['homepage_collection_' . $i . '_link']
                ),
            ];
            $settings['journey_images'][] = app_homepage_resolve_asset(
                app_homepage_get_config_value($conn, 'homepage_journey_' . $i . '_image', $defaults['homepage_journey_' . $i . '_image']),
                $defaults['homepage_journey_' . $i . '_image']
            );
        }

        $headerLogoPath = app_homepage_get_config_value($conn, 'homepage_header_logo_path', '');
        if ($headerLogoPath === '') {
            $headerLogoPath = app_homepage_get_config_value($conn, 'logo_path', 'assets/images/athina-eshop-logo.png');
        }
        $settings['header_logo_path'] = app_homepage_resolve_asset($headerLogoPath, '');

        return $settings;
    }
}

if (!function_exists('app_homepage_save_uploaded_asset')) {
    function app_homepage_save_uploaded_asset(array $file, string $configKey): string
    {
        $specs = app_homepage_upload_specs();
        if (!isset($specs[$configKey])) {
            throw new InvalidArgumentException('Unknown upload target.');
        }

        $spec = $specs[$configKey];
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Please upload a valid image for ' . $spec['label'] . '.');
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('Uploaded file for ' . $spec['label'] . ' could not be verified.');
        }

        if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
            throw new InvalidArgumentException($spec['label'] . ' must be 5MB or smaller.');
        }

        $imageInfo = @getimagesize($tmpName);
        if ($imageInfo === false) {
            throw new InvalidArgumentException($spec['label'] . ' must be a supported image file.');
        }

        $width = (int)($imageInfo[0] ?? 0);
        $height = (int)($imageInfo[1] ?? 0);
        if ($width !== (int)$spec['width'] || $height !== (int)$spec['height']) {
            throw new InvalidArgumentException(
                sprintf(
                    '%s must be exactly %dx%d pixels. Uploaded image is %dx%d.',
                    $spec['label'],
                    (int)$spec['width'],
                    (int)$spec['height'],
                    $width,
                    $height
                )
            );
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string)($finfo->file($tmpName) ?: '');
        if (!app_allowed_image_mime($mimeType)) {
            throw new InvalidArgumentException($spec['label'] . ' must be JPG, PNG, GIF, or WEBP.');
        }

        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $extension = $extensions[$mimeType] ?? null;
        if ($extension === null) {
            throw new InvalidArgumentException($spec['label'] . ' uses an unsupported image format.');
        }

        $uploadDir = app_homepage_upload_dir();
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Could not create the homepage upload directory.');
        }

        $baseName = (string)$spec['filename'];
        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $candidateExt) {
            $existingPath = $uploadDir . DIRECTORY_SEPARATOR . $baseName . '.' . $candidateExt;
            if (is_file($existingPath)) {
                @unlink($existingPath);
            }
        }

        $fileName = $baseName . '.' . $extension;
        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new RuntimeException('Could not save ' . $spec['label'] . '.');
        }

        return 'uploads/assets/images/homepage/' . $fileName;
    }
}
