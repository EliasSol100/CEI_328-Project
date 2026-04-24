<?php
require_once __DIR__ . '/homepage_customization.php';

if (!function_exists('app_auth_logo_url')) {
    function app_auth_logo_url(?mysqli $conn, string $rootPrefix = '../'): string
    {
        $fallbackPath = 'uploads/assets/images/homepage/header-logo.jpg';

        if ($conn instanceof mysqli) {
            $logoPath = app_homepage_get_config_value($conn, 'homepage_header_logo_path', $fallbackPath);
            $logoUrl = app_homepage_asset_url($logoPath, $rootPrefix);
            if ($logoUrl !== '') {
                return $logoUrl;
            }
        }

        return $rootPrefix . $fallbackPath;
    }
}
