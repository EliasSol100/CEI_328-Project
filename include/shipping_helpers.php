<?php

require_once __DIR__ . '/product_option_helpers.php';
require_once __DIR__ . '/platform_integrations.php';

if (!function_exists('app_shipping_default_free_threshold')) {
    function app_shipping_default_free_threshold(): float
    {
        return 100.0;
    }
}

if (!function_exists('app_shipping_default_country_couriers')) {
    function app_shipping_default_country_couriers(): array
    {
        return [
            'Cyprus' => [
                'akis_express' => 'Akis Express',
                'boxnow' => 'BoxNow',
                'acs' => 'ACS',
            ],
            'Greece' => [
                'elta_courier' => 'ELTA Courier',
                'speedex' => 'Speedex',
                'geniki' => 'Geniki Taxydromiki',
            ],
        ];
    }
}

if (!function_exists('app_shipping_default_rate_table')) {
    function app_shipping_default_rate_table(): array
    {
        return [
            'Cyprus' => [
                'akis_express' => [
                    'standard' => [['max' => 0.5, 'price' => 2.50], ['max' => 2.0, 'price' => 3.50], ['max' => 5.0, 'price' => 5.00], ['max' => null, 'price' => 7.00]],
                    'express' => [['max' => 0.5, 'price' => 4.00], ['max' => 2.0, 'price' => 5.50], ['max' => 5.0, 'price' => 7.50], ['max' => null, 'price' => 10.00]],
                ],
                'boxnow' => [
                    'standard' => [['max' => 0.5, 'price' => 2.00], ['max' => 2.0, 'price' => 3.00], ['max' => 5.0, 'price' => 4.50], ['max' => null, 'price' => 6.00]],
                    'express' => [['max' => 0.5, 'price' => 3.50], ['max' => 2.0, 'price' => 4.50], ['max' => 5.0, 'price' => 6.00], ['max' => null, 'price' => 8.00]],
                ],
                'acs' => [
                    'standard' => [['max' => 0.5, 'price' => 3.00], ['max' => 2.0, 'price' => 4.00], ['max' => 5.0, 'price' => 6.00], ['max' => null, 'price' => 8.00]],
                    'express' => [['max' => 0.5, 'price' => 4.50], ['max' => 2.0, 'price' => 6.00], ['max' => 5.0, 'price' => 8.00], ['max' => null, 'price' => 11.00]],
                ],
            ],
            'Greece' => [
                'elta_courier' => [
                    'standard' => [['max' => 0.5, 'price' => 4.00], ['max' => 2.0, 'price' => 6.00], ['max' => 5.0, 'price' => 9.00], ['max' => null, 'price' => 13.00]],
                    'express' => [['max' => 0.5, 'price' => 8.00], ['max' => 2.0, 'price' => 11.00], ['max' => 5.0, 'price' => 15.00], ['max' => null, 'price' => 20.00]],
                ],
                'speedex' => [
                    'standard' => [['max' => 0.5, 'price' => 4.50], ['max' => 2.0, 'price' => 6.50], ['max' => 5.0, 'price' => 10.00], ['max' => null, 'price' => 14.00]],
                    'express' => [['max' => 0.5, 'price' => 8.50], ['max' => 2.0, 'price' => 12.00], ['max' => 5.0, 'price' => 16.00], ['max' => null, 'price' => 22.00]],
                ],
                'geniki' => [
                    'standard' => [['max' => 0.5, 'price' => 5.00], ['max' => 2.0, 'price' => 7.00], ['max' => 5.0, 'price' => 10.50], ['max' => null, 'price' => 15.00]],
                    'express' => [['max' => 0.5, 'price' => 9.00], ['max' => 2.0, 'price' => 12.50], ['max' => 5.0, 'price' => 17.00], ['max' => null, 'price' => 23.00]],
                ],
            ],
        ];
    }
}

if (!function_exists('app_shipping_default_size_surcharges')) {
    function app_shipping_default_size_surcharges(): array
    {
        return [
            'small' => 0.0,
            'medium' => 0.75,
            'large' => 1.50,
            'oversized' => 3.00,
        ];
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
            'shipping_rate_table_json' => json_encode(app_shipping_default_rate_table(), JSON_UNESCAPED_UNICODE),
            'shipping_size_surcharges_json' => json_encode(app_shipping_default_size_surcharges(), JSON_UNESCAPED_UNICODE),
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
            'shipping_rate_table_json' => json_encode(app_shipping_default_rate_table(), JSON_UNESCAPED_UNICODE),
            'shipping_size_surcharges_json' => json_encode(app_shipping_default_size_surcharges(), JSON_UNESCAPED_UNICODE),
        ]);
        $value = (float)app_system_config_get($conn, 'shipping_free_threshold', (string)app_shipping_default_free_threshold());
        return max(0.0, $value);
    }
}

if (!function_exists('app_shipping_country_couriers')) {
    function app_shipping_country_couriers(): array
    {
        return app_shipping_default_country_couriers();
    }
}

if (!function_exists('app_shipping_rate_table')) {
    function app_shipping_rate_table(): array
    {
        $default = app_shipping_default_rate_table();
        $stored = app_shipping_config_json('shipping_rate_table_json', $default);
        return is_array($stored) && !empty($stored) ? $stored : $default;
    }
}

if (!function_exists('app_shipping_size_surcharge')) {
    function app_shipping_size_surcharge(string $sizeCode): float
    {
        $sizeCode = strtolower(trim($sizeCode));
        $surcharges = app_shipping_config_json('shipping_size_surcharges_json', app_shipping_default_size_surcharges());
        return (float)($surcharges[$sizeCode] ?? 0.0);
    }
}

if (!function_exists('app_shipping_infer_line_profile')) {
    function app_shipping_infer_line_profile(array $item): array
    {
        $qty = max(1, (int)($item['quantity'] ?? 1));
        $variationSize = strtolower(trim((string)($item['variation']['size'] ?? '')));
        $productName = strtolower(trim((string)($item['product']['nameEN'] ?? $item['name'] ?? '')));
        $category = strtolower(trim((string)($item['product']['category'] ?? '')));

        $weight = 0.35;
        $sizeCode = 'small';

        if (strpos($category, 'blanket') !== false || strpos($productName, 'blanket') !== false) {
            $weight = 1.2;
            $sizeCode = 'large';
        } elseif (strpos($variationSize, 'large') !== false || strpos($variationSize, 'xl') !== false) {
            $weight = 0.8;
            $sizeCode = 'medium';
        } elseif (strpos($variationSize, 'medium') !== false) {
            $weight = 0.6;
            $sizeCode = 'medium';
        } elseif (strpos($variationSize, 'small') !== false || strpos($variationSize, 'newborn') !== false) {
            $weight = 0.25;
            $sizeCode = 'small';
        }

        return [
            'weight_kg' => $weight * $qty,
            'size_code' => $sizeCode,
        ];
    }
}

if (!function_exists('app_shipping_cart_profile')) {
    function app_shipping_cart_profile(mysqli $conn, array $items): array
    {
        app_product_options_ensure_schema($conn);

        $productIds = [];
        $variationIds = [];
        foreach ($items as $item) {
            $pid = (int)($item['product']['id'] ?? $item['productID'] ?? 0);
            $vid = (int)($item['variation']['variationID'] ?? $item['variation_id'] ?? 0);
            if ($pid > 0) $productIds[$pid] = true;
            if ($vid > 0) $variationIds[$vid] = true;
        }

        $productProfiles = [];
        if (!empty($productIds)) {
            $ids = implode(',', array_map('intval', array_keys($productIds)));
            $res = mysqli_query($conn, "SELECT productID, category, shippingWeightKg, shippingSizeCode FROM products WHERE productID IN ({$ids})");
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $productProfiles[(int)$row['productID']] = $row;
                }
            }
        }

        $variationProfiles = [];
        if (!empty($variationIds) && app_product_options_column_exists($conn, 'product_variations', 'shippingWeightKg')) {
            $ids = implode(',', array_map('intval', array_keys($variationIds)));
            $res = mysqli_query($conn, "SELECT variationID, shippingWeightKg, shippingSizeCode FROM product_variations WHERE variationID IN ({$ids})");
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $variationProfiles[(int)$row['variationID']] = $row;
                }
            }
        }

        $weight = 0.0;
        $largestRank = 0;
        $rankBySize = ['small' => 1, 'medium' => 2, 'large' => 3, 'oversized' => 4];
        $largestSize = 'small';
        $itemCount = 0;

        foreach ($items as $item) {
            $qty = max(1, (int)($item['quantity'] ?? 1));
            $itemCount += $qty;
            $pid = (int)($item['product']['id'] ?? $item['productID'] ?? 0);
            $vid = (int)($item['variation']['variationID'] ?? $item['variation_id'] ?? 0);
            $profile = app_shipping_infer_line_profile($item);
            $lineWeight = (float)$profile['weight_kg'];
            $sizeCode = (string)$profile['size_code'];

            if ($vid > 0 && isset($variationProfiles[$vid])) {
                $variation = $variationProfiles[$vid];
                if ((float)($variation['shippingWeightKg'] ?? 0) > 0) {
                    $lineWeight = (float)$variation['shippingWeightKg'] * $qty;
                }
                if (trim((string)($variation['shippingSizeCode'] ?? '')) !== '') {
                    $sizeCode = strtolower(trim((string)$variation['shippingSizeCode']));
                }
            } elseif ($pid > 0 && isset($productProfiles[$pid])) {
                $product = $productProfiles[$pid];
                if ((float)($product['shippingWeightKg'] ?? 0) > 0) {
                    $lineWeight = (float)$product['shippingWeightKg'] * $qty;
                }
                if (trim((string)($product['shippingSizeCode'] ?? '')) !== '') {
                    $sizeCode = strtolower(trim((string)$product['shippingSizeCode']));
                }
            }

            $weight += max(0.0, $lineWeight);
            $rank = $rankBySize[$sizeCode] ?? 1;
            if ($rank > $largestRank) {
                $largestRank = $rank;
                $largestSize = $sizeCode;
            }
        }

        return [
            'weight_kg' => round(max(0.1, $weight), 3),
            'size_code' => $largestSize,
            'item_count' => $itemCount,
        ];
    }
}

if (!function_exists('app_shipping_calculate_cost')) {
    function app_shipping_calculate_cost(
        string $country,
        string $courier,
        string $speed,
        float $cartTotal,
        float $freeShippingThreshold,
        array $cartProfile,
        array $rateTable
    ): float {
        if ($cartTotal >= $freeShippingThreshold) {
            return 0.0;
        }

        $tiers = $rateTable[$country][$courier][$speed] ?? [];
        if (empty($tiers)) {
            return 0.0;
        }

        $weight = max(0.1, (float)($cartProfile['weight_kg'] ?? 0.1));
        $base = 0.0;
        foreach ($tiers as $tier) {
            $max = $tier['max'];
            if ($max === null || $weight <= (float)$max) {
                $base = (float)$tier['price'];
                break;
            }
        }

        return round($base + app_shipping_size_surcharge((string)($cartProfile['size_code'] ?? 'small')), 2);
    }
}
