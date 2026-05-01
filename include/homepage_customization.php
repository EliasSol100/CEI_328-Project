<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/image_storage.php';
require_once __DIR__ . '/../authentication/database.php';

if (!function_exists('app_homepage_project_root')) {
    function app_homepage_project_root(): string
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('app_homepage_local_asset_path')) {
    function app_homepage_local_asset_path(string $path): string
    {
        $relativePath = ltrim(trim($path), '/\\');
        if ($relativePath === '') {
            return '';
        }

        return app_homepage_project_root() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    }
}

if (!function_exists('app_homepage_upload_dir')) {
    function app_homepage_upload_dir(): string
    {
        return app_homepage_project_root() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'homepage';
    }
}

if (!function_exists('app_homepage_collection_count')) {
    function app_homepage_collection_count(): int
    {
        return 4;
    }
}

if (!function_exists('app_homepage_journey_count')) {
    function app_homepage_journey_count(): int
    {
        return 3;
    }
}

if (!function_exists('app_homepage_hero_canvas_width')) {
    function app_homepage_hero_canvas_width(): int
    {
        return 1920;
    }
}

if (!function_exists('app_homepage_hero_canvas_height')) {
    function app_homepage_hero_canvas_height(): int
    {
        return 600;
    }
}

if (!function_exists('app_homepage_hero_safe_width')) {
    function app_homepage_hero_safe_width(): int
    {
        return 1172;
    }
}

if (!function_exists('app_homepage_hero_dimension_message')) {
    function app_homepage_hero_dimension_message(): string
    {
        return sprintf(
            'Hero section background must be exactly %dx%d pixels for a full-width upload, or %dx%d pixels for a centered banner that the system expands automatically. Keep important text inside the centered %dx%d safe area.',
            app_homepage_hero_canvas_width(),
            app_homepage_hero_canvas_height(),
            app_homepage_hero_safe_width(),
            app_homepage_hero_canvas_height(),
            app_homepage_hero_safe_width(),
            app_homepage_hero_canvas_height()
        );
    }
}

if (!function_exists('app_homepage_upload_specs')) {
    function app_homepage_upload_specs(): array
    {
        $specs = [
            'homepage_hero_image' => [
                'label' => 'Hero section background',
                'width' => app_homepage_hero_canvas_width(),
                'height' => app_homepage_hero_canvas_height(),
                'filename' => 'hero-section',
            ],
            'homepage_header_logo_path' => [
                'label' => 'Header logo',
                'width' => 50,
                'height' => 50,
                'filename' => 'header-logo',
            ],
        ];

        for ($i = 1; $i <= app_homepage_collection_count(); $i++) {
            $specs['homepage_collection_' . $i . '_image'] = [
                'label' => 'Shop by Collection image ' . $i,
                'width' => 261,
                'height' => 260,
                'filename' => 'shop-collection-' . $i,
            ];
        }

        for ($i = 1; $i <= app_homepage_journey_count(); $i++) {
            $specs['homepage_journey_' . $i . '_image'] = [
                'label' => 'Follow Our Journey image ' . $i,
                'width' => 361,
                'height' => 260,
                'filename' => 'follow-journey-' . $i,
            ];
        }

        return $specs;
    }
}

if (!function_exists('app_homepage_default_config_values')) {
    function app_homepage_default_config_values(): array
    {
        $defaultImage = 'https://images.unsplash.com/photo-1581833971358-2c8b550f87b3?w=1200&q=80';
        $defaults = [
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
            'homepage_header_logo_path' => '',
        ];

        $defaults['homepage_collection_4_image'] = $defaultImage;
        $defaults['homepage_collection_4_label'] = 'Handmade Dolls';
        $defaults['homepage_collection_4_link'] = 'shop.php?category=Dolls';

        for ($i = 1; $i <= app_homepage_journey_count(); $i++) {
            $defaults['homepage_journey_' . $i . '_image'] = $defaultImage;
        }

        return $defaults;
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

        $absolutePath = app_homepage_local_asset_path($path);
        return is_file($absolutePath);
    }
}

if (!function_exists('app_homepage_resolve_asset')) {
    function app_homepage_resolve_asset(string $path, string $fallback = ''): string
    {
        $path = app_image_prefer_optimized_asset_path($path);
        $fallback = app_image_prefer_optimized_asset_path($fallback);

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
        $path = trim(app_image_prefer_optimized_asset_path($path));
        if ($path === '') {
            return $path;
        }

        if (app_homepage_is_remote_asset($path)) {
            return $path;
        }

        $url = $prefix . ltrim($path, '/\\');
        $absolutePath = app_homepage_local_asset_path($path);
        if (!is_file($absolutePath)) {
            return $url;
        }

        $version = (int)@filemtime($absolutePath);
        if ($version <= 0) {
            return $url;
        }

        return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . $version;
    }
}

if (!function_exists('app_homepage_create_gd_image_resource')) {
    function app_homepage_create_gd_image_resource(string $path, string $mimeType)
    {
        switch ($mimeType) {
            case 'image/jpeg':
                return @imagecreatefromjpeg($path);
            case 'image/png':
                return @imagecreatefrompng($path);
            case 'image/gif':
                return @imagecreatefromgif($path);
            case 'image/webp':
                return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
            default:
                return false;
        }
    }
}

if (!function_exists('app_homepage_write_gd_image_resource')) {
    function app_homepage_write_gd_image_resource($image, string $targetPath, string $outputMime = 'image/jpeg'): void
    {
        $ok = false;
        switch ($outputMime) {
            case 'image/jpeg':
                $ok = @imagejpeg($image, $targetPath, 92);
                break;
            case 'image/png':
                $ok = @imagepng($image, $targetPath, 6);
                break;
            case 'image/gif':
                $ok = @imagegif($image, $targetPath);
                break;
            case 'image/webp':
                $ok = function_exists('imagewebp') ? @imagewebp($image, $targetPath, 90) : false;
                break;
        }

        if (!$ok) {
            throw new RuntimeException('Could not write the processed hero image.');
        }
    }
}

if (!function_exists('app_homepage_expand_hero_asset')) {
    function app_homepage_expand_hero_asset(string $sourcePath, string $targetPath, string $mimeType, int $sourceWidth, int $sourceHeight): void
    {
        $targetWidth = app_homepage_hero_canvas_width();
        $targetHeight = app_homepage_hero_canvas_height();
        $sourceImage = app_homepage_create_gd_image_resource($sourcePath, $mimeType);

        if (!$sourceImage) {
            throw new RuntimeException('Could not read the uploaded hero image.');
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$canvas) {
            imagedestroy($sourceImage);
            throw new RuntimeException('Could not prepare the hero image canvas.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);

        if ($sourceWidth === $targetWidth && $sourceHeight === $targetHeight) {
            imagecopyresampled($canvas, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        } else {
            $safeWidth = app_homepage_hero_safe_width();
            if ($sourceWidth !== $safeWidth || $sourceHeight !== $targetHeight) {
                imagedestroy($canvas);
                imagedestroy($sourceImage);
                throw new RuntimeException(app_homepage_hero_dimension_message());
            }

            $sidePadding = (int)floor(($targetWidth - $sourceWidth) / 2);
            $edgeSliceWidth = max(1, min(60, (int)floor($sourceWidth * 0.05)));

            imagecopyresampled(
                $canvas,
                $sourceImage,
                0,
                0,
                0,
                0,
                $sidePadding,
                $targetHeight,
                $edgeSliceWidth,
                $sourceHeight
            );

            imagecopy(
                $canvas,
                $sourceImage,
                $sidePadding,
                0,
                0,
                0,
                $sourceWidth,
                $sourceHeight
            );

            imagecopyresampled(
                $canvas,
                $sourceImage,
                $sidePadding + $sourceWidth,
                0,
                $sourceWidth - $edgeSliceWidth,
                0,
                $sidePadding,
                $targetHeight,
                $edgeSliceWidth,
                $sourceHeight
            );

            $fadeWidth = 56;
            foreach ([$sidePadding, $sidePadding + $sourceWidth] as $seamX) {
                for ($offset = -$fadeWidth; $offset <= $fadeWidth; $offset++) {
                    $x = $seamX + $offset;
                    if ($x < 0 || $x >= $targetWidth) {
                        continue;
                    }

                    $strength = 1 - (abs($offset) / max(1, $fadeWidth));
                    $alpha = 127 - (int)round($strength * 36);
                    $fadeColor = imagecolorallocatealpha($canvas, 255, 255, 255, max(0, min(127, $alpha)));
                    imageline($canvas, $x, 0, $x, $targetHeight, $fadeColor);
                }
            }
        }

        $writePath = $targetPath;
        $sourceRealPath = @realpath($sourcePath);
        $targetRealPath = @realpath($targetPath);
        $replaceOriginal = $sourceRealPath !== false && $targetRealPath !== false && $sourceRealPath === $targetRealPath;
        if ($replaceOriginal) {
            $writePath = $targetPath . '.tmp';
        }

        $targetExtension = strtolower((string)pathinfo($targetPath, PATHINFO_EXTENSION));
        $outputMime = $targetExtension === 'webp' ? 'image/webp' : 'image/jpeg';
        app_homepage_write_gd_image_resource($canvas, $writePath, $outputMime);

        if ($replaceOriginal) {
            @unlink($targetPath);
            if (!@rename($writePath, $targetPath)) {
                @unlink($writePath);
                imagedestroy($canvas);
                imagedestroy($sourceImage);
                throw new RuntimeException('Could not replace the stored hero image.');
            }
        }

        imagedestroy($canvas);
        imagedestroy($sourceImage);
    }
}

if (!function_exists('app_homepage_normalize_local_hero_asset')) {
    function app_homepage_normalize_local_hero_asset(string $path): string
    {
        $path = trim(app_image_prefer_optimized_asset_path($path));
        if ($path === '' || app_homepage_is_remote_asset($path)) {
            return $path;
        }

        $absolutePath = app_homepage_local_asset_path($path);
        if (!is_file($absolutePath)) {
            return $path;
        }

        $imageInfo = @getimagesize($absolutePath);
        if ($imageInfo === false) {
            return $path;
        }

        $width = (int)($imageInfo[0] ?? 0);
        $height = (int)($imageInfo[1] ?? 0);
        if ($width === app_homepage_hero_canvas_width() && $height === app_homepage_hero_canvas_height()) {
            return $path;
        }

        if ($width !== app_homepage_hero_safe_width() || $height !== app_homepage_hero_canvas_height()) {
            return $path;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string)($finfo->file($absolutePath) ?: '');
        if (!app_allowed_image_mime($mimeType)) {
            return $path;
        }

        try {
            app_homepage_expand_hero_asset($absolutePath, $absolutePath, $mimeType, $width, $height);
        } catch (Throwable $e) {
            return $path;
        }

        return $path;
    }
}

if (!function_exists('app_homepage_load_settings')) {
    function app_homepage_load_settings(mysqli $conn): array
    {
        $defaults = app_homepage_default_config_values();
        app_homepage_ensure_schema($conn);

        $heroImagePath = app_homepage_get_config_value($conn, 'homepage_hero_image', $defaults['homepage_hero_image']);
        $heroImagePath = app_homepage_normalize_local_hero_asset($heroImagePath);

        $settings = [
            'hero_image' => app_homepage_resolve_asset(
                $heroImagePath,
                $defaults['homepage_hero_image']
            ),
            'collections' => [],
            'journey_images' => [],
            'header_logo_path' => '',
        ];

        for ($i = 1; $i <= app_homepage_collection_count(); $i++) {
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
        }

        for ($i = 1; $i <= app_homepage_journey_count(); $i++) {
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
        if ($configKey === 'homepage_hero_image') {
            $isFullWidthHero = $width === app_homepage_hero_canvas_width() && $height === app_homepage_hero_canvas_height();
            $isLegacyHero = $width === app_homepage_hero_safe_width() && $height === app_homepage_hero_canvas_height();

            if (!$isFullWidthHero && !$isLegacyHero) {
                throw new InvalidArgumentException(app_homepage_hero_dimension_message() . sprintf(' Uploaded image is %dx%d.', $width, $height));
            }
        } elseif ($width !== (int)$spec['width'] || $height !== (int)$spec['height']) {
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

        $fileName = $baseName . '.webp';
        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

        $maxWidth = (int)$spec['width'];
        $maxHeight = (int)$spec['height'];
        if ($configKey === 'homepage_hero_image') {
            $maxWidth = app_homepage_hero_canvas_width();
            $maxHeight = app_homepage_hero_canvas_height();
        }

        if (!app_image_convert_file_to_webp($tmpName, $targetPath, $maxWidth, $maxHeight, 84)) {
            throw new RuntimeException('Could not convert ' . $spec['label'] . ' to WebP.');
        }

        if ($configKey === 'homepage_hero_image' && $width === app_homepage_hero_safe_width() && $height === app_homepage_hero_canvas_height()) {
            app_homepage_expand_hero_asset($targetPath, $targetPath, 'image/webp', $width, $height);
        }

        return 'uploads/assets/images/homepage/' . $fileName;
    }
}
