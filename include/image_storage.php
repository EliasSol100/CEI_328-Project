<?php

if (!function_exists('app_image_project_root')) {
    function app_image_project_root(): string
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('app_image_is_remote_asset')) {
    function app_image_is_remote_asset(string $path): bool
    {
        return (bool)preg_match('#^https?://#i', trim($path));
    }
}

if (!function_exists('app_image_is_public_asset_url')) {
    function app_image_is_public_asset_url(string $path): bool
    {
        $path = trim($path);
        return (bool)preg_match('#^(?:https?:)?//#i', $path) || (bool)preg_match('#^(?:data|blob):#i', $path);
    }
}

if (!function_exists('app_image_project_public_path')) {
    function app_image_project_public_path(): string
    {
        static $publicPath = null;
        if ($publicPath !== null) {
            return $publicPath;
        }

        $projectRoot = realpath(app_image_project_root()) ?: app_image_project_root();
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

        $documentRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        $documentRoot = $documentRoot !== '' ? (realpath($documentRoot) ?: $documentRoot) : '';
        $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');

        if ($projectRoot !== '' && $documentRoot !== '') {
            $projectLower = strtolower($projectRoot);
            $documentLower = strtolower($documentRoot);
            if ($projectLower === $documentLower || strpos($projectLower, $documentLower . '/') === 0) {
                $relative = trim(substr($projectRoot, strlen($documentRoot)), '/');
                $publicPath = $relative !== '' ? '/' . $relative : '';
                return $publicPath;
            }
        }

        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $scriptFile = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
        $scriptFile = $scriptFile !== '' ? (realpath($scriptFile) ?: $scriptFile) : '';
        $scriptFile = str_replace('\\', '/', $scriptFile);

        if ($projectRoot !== '' && $scriptName !== '' && $scriptFile !== '') {
            $projectLower = strtolower($projectRoot);
            $scriptLower = strtolower($scriptFile);
            if ($scriptLower === $projectLower || strpos($scriptLower, $projectLower . '/') === 0) {
                $scriptRelative = trim(substr($scriptFile, strlen($projectRoot)), '/');
                $scriptRelativeDir = str_replace('\\', '/', dirname($scriptRelative));
                $scriptRelativeDir = $scriptRelativeDir === '.' ? '' : trim($scriptRelativeDir, '/');
                $scriptUrlDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

                if ($scriptRelativeDir !== '') {
                    $suffix = '/' . $scriptRelativeDir;
                    if (substr($scriptUrlDir, -strlen($suffix)) === $suffix) {
                        $scriptUrlDir = substr($scriptUrlDir, 0, -strlen($suffix));
                    }
                }

                $publicPath = ($scriptUrlDir === '' || $scriptUrlDir === '/' || $scriptUrlDir === '.') ? '' : $scriptUrlDir;
                return $publicPath;
            }
        }

        $publicPath = '';
        return $publicPath;
    }
}

if (!function_exists('app_image_asset_url')) {
    function app_image_asset_url(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || app_image_is_public_asset_url($path)) {
            return $path;
        }

        $projectPath = app_image_project_public_path();
        if ($path[0] === '/') {
            if ($projectPath !== '' && ($path === $projectPath || strpos($path, $projectPath . '/') === 0)) {
                return $path;
            }
            $path = ltrim($path, '/');
        }

        return rtrim($projectPath, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('app_image_relative_asset_path')) {
    function app_image_relative_asset_path(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        return ltrim($normalized, '/');
    }
}

if (!function_exists('app_image_local_asset_path')) {
    function app_image_local_asset_path(string $path): string
    {
        return app_image_project_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, app_image_relative_asset_path($path));
    }
}

if (!function_exists('app_image_is_convertible_content_asset')) {
    function app_image_is_convertible_content_asset(string $path): bool
    {
        $relativePath = strtolower(app_image_relative_asset_path($path));
        if ($relativePath === '' || app_image_is_remote_asset($relativePath)) {
            return false;
        }

        if (!preg_match('/\.(png|gif|webp|jpe?g)$/i', $relativePath)) {
            return false;
        }

        $baseName = basename($relativePath);
        if (in_array($baseName, ['athina-eshop-logo.png', 'icon-192.png', 'icon-512.png'], true)) {
            return false;
        }

        $contentRoots = [
            'uploads/assets/images/homepage/',
            'uploads/assets/images/products/',
            'assets/product_color_photos/',
            'assets/product_color_scheme_photos/',
            'assets/yarn_colors/',
        ];

        foreach ($contentRoots as $root) {
            if (strpos($relativePath, $root) === 0) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('app_image_prefer_optimized_asset_path')) {
    function app_image_prefer_optimized_asset_path(string $path): string
    {
        $path = trim($path);
        if ($path === '' || app_image_is_remote_asset($path) || !app_image_is_convertible_content_asset($path)) {
            return $path;
        }

        $normalized = app_image_relative_asset_path($path);

        $webpCandidate = preg_replace('/\.(png|gif|webp|jpe?g)$/i', '.webp', $normalized);
        if (is_string($webpCandidate) && $webpCandidate !== '' && is_file(app_image_local_asset_path($webpCandidate))) {
            return $webpCandidate;
        }

        $jpgCandidate = preg_replace('/\.(png|gif|webp|jpe?g)$/i', '.jpg', $normalized);
        if (is_string($jpgCandidate) && $jpgCandidate !== '' && is_file(app_image_local_asset_path($jpgCandidate))) {
            return $jpgCandidate;
        }

        return $path;
    }
}

if (!function_exists('app_image_create_resource_from_binary')) {
    function app_image_create_resource_from_binary(string $binary)
    {
        if ($binary === '') {
            return false;
        }

        return @imagecreatefromstring($binary);
    }
}

if (!function_exists('app_image_calculate_target_size')) {
    function app_image_calculate_target_size(int $sourceWidth, int $sourceHeight, int $maxWidth, int $maxHeight): array
    {
        $sourceWidth = max(1, $sourceWidth);
        $sourceHeight = max(1, $sourceHeight);
        $maxWidth = max(1, $maxWidth);
        $maxHeight = max(1, $maxHeight);

        $scale = min(1, $maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        return [
            max(1, (int)round($sourceWidth * $scale)),
            max(1, (int)round($sourceHeight * $scale)),
        ];
    }
}

if (!function_exists('app_image_render_resampled_canvas')) {
    function app_image_render_resampled_canvas($sourceImage, int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight)
    {
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$canvas) {
            return false;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
        imagecopyresampled($canvas, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        return $canvas;
    }
}

if (!function_exists('app_image_binary_to_optimized_jpeg')) {
    function app_image_binary_to_optimized_jpeg(string $binary, int $maxWidth = 1600, int $maxHeight = 1600, int $quality = 82): ?string
    {
        $sourceImage = app_image_create_resource_from_binary($binary);
        if (!$sourceImage) {
            return null;
        }

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        [$targetWidth, $targetHeight] = app_image_calculate_target_size($sourceWidth, $sourceHeight, $maxWidth, $maxHeight);
        $canvas = app_image_render_resampled_canvas($sourceImage, $sourceWidth, $sourceHeight, $targetWidth, $targetHeight);
        imagedestroy($sourceImage);

        if (!$canvas) {
            return null;
        }

        ob_start();
        $ok = imagejpeg($canvas, null, max(40, min(92, $quality)));
        $jpegBinary = $ok ? (string)ob_get_clean() : '';
        if (!$ok) {
            ob_end_clean();
        }
        imagedestroy($canvas);

        return $jpegBinary !== '' ? $jpegBinary : null;
    }
}

if (!function_exists('app_image_write_binary_file')) {
    function app_image_write_binary_file(string $targetPath, string $binary): bool
    {
        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }

        return file_put_contents($targetPath, $binary) !== false;
    }
}

if (!function_exists('app_image_optimize_photo_blob_for_storage')) {
    function app_image_optimize_photo_blob_for_storage(string $binary, int $maxWidth = 1400, int $maxHeight = 1400, int $quality = 78): string
    {
        $webpBinary = app_image_binary_to_optimized_webp($binary, $maxWidth, $maxHeight, $quality);
        if (is_string($webpBinary) && $webpBinary !== '') {
            return $webpBinary;
        }

        $jpegBinary = app_image_binary_to_optimized_jpeg($binary, $maxWidth, $maxHeight, $quality);
        if (!is_string($jpegBinary) || $jpegBinary === '') {
            return $binary;
        }

        return $jpegBinary;
    }
}

if (!function_exists('app_image_binary_to_optimized_webp')) {
    function app_image_binary_to_optimized_webp(string $binary, int $maxWidth = 1600, int $maxHeight = 1600, int $quality = 82): ?string
    {
        if (!(imagetypes() & IMG_WEBP)) {
            return null;
        }

        $sourceImage = app_image_create_resource_from_binary($binary);
        if (!$sourceImage) {
            return null;
        }

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        [$targetWidth, $targetHeight] = app_image_calculate_target_size($sourceWidth, $sourceHeight, $maxWidth, $maxHeight);
        $canvas = app_image_render_resampled_canvas($sourceImage, $sourceWidth, $sourceHeight, $targetWidth, $targetHeight);
        imagedestroy($sourceImage);

        if (!$canvas) {
            return null;
        }

        ob_start();
        $ok = imagewebp($canvas, null, max(40, min(100, $quality)));
        $webpBinary = $ok ? (string)ob_get_clean() : '';
        if (!$ok) {
            ob_end_clean();
        }
        imagedestroy($canvas);

        return $webpBinary !== '' ? $webpBinary : null;
    }
}

if (!function_exists('app_image_convert_file_to_webp')) {
    function app_image_convert_file_to_webp(string $sourcePath, string $targetPath, int $maxWidth = 1600, int $maxHeight = 1600, int $quality = 82): bool
    {
        if (!is_file($sourcePath)) {
            return false;
        }

        $binary = file_get_contents($sourcePath);
        if (!is_string($binary) || $binary === '') {
            return false;
        }

        $webpBinary = app_image_binary_to_optimized_webp($binary, $maxWidth, $maxHeight, $quality);
        if (!is_string($webpBinary) || $webpBinary === '') {
            return false;
        }

        return app_image_write_binary_file($targetPath, $webpBinary);
    }
}

if (!function_exists('app_image_convert_local_content_asset_to_webp')) {
    function app_image_convert_local_content_asset_to_webp(string $path, int $maxWidth = 1600, int $maxHeight = 1600, int $quality = 82): string
    {
        $normalizedPath = app_image_relative_asset_path($path);
        if (!app_image_is_convertible_content_asset($normalizedPath)) {
            return $path;
        }

        $sourcePath = app_image_local_asset_path($normalizedPath);
        if (!is_file($sourcePath)) {
            return $path;
        }

        $targetRelative = preg_replace('/\.(png|gif|webp|jpe?g)$/i', '.webp', $normalizedPath);
        if (!is_string($targetRelative) || $targetRelative === '') {
            return $path;
        }

        $targetPath = app_image_local_asset_path($targetRelative);
        if (!is_file($targetPath)) {
            $converted = app_image_convert_file_to_webp($sourcePath, $targetPath, $maxWidth, $maxHeight, $quality);
            if (!$converted) {
                return $path;
            }
        }

        if (strcasecmp($sourcePath, $targetPath) !== 0 && is_file($sourcePath)) {
            @unlink($sourcePath);
        }

        return $targetRelative;
    }
}
