<?php

require_once __DIR__ . '/product_option_helpers.php';
require_once __DIR__ . '/platform_integrations.php';

if (!function_exists('app_shipping_default_free_threshold')) {
    function app_shipping_default_free_threshold(): float
    {
        return 100.0;
    }
}

if (!function_exists('app_shipping_config_conn')) {
    function app_shipping_config_conn(): ?mysqli
    {
        global $conn;
        return (isset($conn) && $conn instanceof mysqli) ? $conn : null;
    }
}

if (!function_exists('app_shipping_config_json')) {
    function app_shipping_config_json(string $key, array $default): array
    {
        $conn = app_shipping_config_conn();
        if (!$conn) {
            return $default;
        }

        app_system_config_seed_defaults($conn, [
            'shipping_free_threshold' => (string)app_shipping_default_free_threshold(),
        ]);

        $raw = app_system_config_get($conn, $key, '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : $default;
    }
}

if (!function_exists('app_shipping_free_threshold')) {
    function app_shipping_free_threshold(): float
    {
        $conn = app_shipping_config_conn();
        if (!$conn) {
            return app_shipping_default_free_threshold();
        }
        app_system_config_seed_defaults($conn, [
            'shipping_free_threshold' => (string)app_shipping_default_free_threshold(),
        ]);
        $value = (float)app_system_config_get($conn, 'shipping_free_threshold', (string)app_shipping_default_free_threshold());
        return max(0.0, $value);
    }
}

if (!function_exists('app_shipping_default_flat_rates')) {
    function app_shipping_default_flat_rates(): array
    {
        return [
            'Cyprus' => [
                'boxnow'       => ['pickup' => 2.00],
                'acs'          => ['home' => 3.50, 'pickup' => 3.00],
                'akis_express' => ['home' => 3.00, 'pickup' => 2.50],
            ],
            'Greece' => [
                'boxnow' => ['pickup' => 3.50],
                'acs'    => ['pickup' => 4.00],
            ],
        ];
    }
}

if (!function_exists('app_shipping_courier_labels')) {
    function app_shipping_courier_labels(): array
    {
        return [
            'Cyprus' => [
                'boxnow'       => 'BoxNow',
                'acs'          => 'ACS',
                'akis_express' => 'Akis Express',
            ],
            'Greece' => [
                'boxnow' => 'BoxNow',
                'acs'    => 'ACS',
            ],
        ];
    }
}

if (!function_exists('app_shipping_flat_rates')) {
    function app_shipping_flat_rates(): array
    {
        $default = app_shipping_default_flat_rates();
        $stored  = app_shipping_config_json('shipping_flat_rates_json', $default);
        return is_array($stored) && !empty($stored) ? $stored : $default;
    }
}

if (!function_exists('app_shipping_calculate_cost')) {
    function app_shipping_calculate_cost(
        string $country,
        string $courier,
        string $mode,
        float $cartTotal,
        float $freeShippingThreshold,
        array $flatRates
    ): float {
        if ($cartTotal >= $freeShippingThreshold) {
            return 0.0;
        }
        return round((float)($flatRates[$country][$courier][$mode] ?? 0.0), 2);
    }
}
