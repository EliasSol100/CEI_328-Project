<?php

require_once __DIR__ . '/platform_integrations.php';

if (!function_exists('app_shop_filter_slug')) {
    function app_shop_filter_slug(string $value, string $fallback = 'option'): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : $fallback;
    }
}

if (!function_exists('app_shop_filter_product_column_exists')) {
    function app_shop_filter_product_column_exists(mysqli $conn, string $column): bool
    {
        $safeColumn = mysqli_real_escape_string($conn, $column);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE '{$safeColumn}'");
        return (bool)($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('app_shop_filter_admin_products')) {
    function app_shop_filter_admin_products(mysqli $conn): array
    {
        $products = [];
        $materialSelect = app_shop_filter_product_column_exists($conn, 'materialType') ? 'materialType' : "'' AS materialType";
        $sellingSelect = app_shop_filter_product_column_exists($conn, 'isSellingFast') ? 'isSellingFast' : '0 AS isSellingFast';
        $res = mysqli_query(
            $conn,
            "SELECT productID, nameEN, sku, category, {$materialSelect}, {$sellingSelect}
             FROM products
             ORDER BY nameEN ASC"
        );
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $products[(int)$row['productID']] = [
                'productID' => (int)$row['productID'],
                'nameEN' => (string)($row['nameEN'] ?? ('Product #' . (int)$row['productID'])),
                'sku' => (string)($row['sku'] ?? ''),
                'category' => (string)($row['category'] ?? ''),
                'materialType' => (string)($row['materialType'] ?? ''),
                'isSellingFast' => (int)($row['isSellingFast'] ?? 0),
            ];
        }

        return $products;
    }
}

if (!function_exists('app_shop_filter_price_bounds')) {
    function app_shop_filter_price_bounds(mysqli $conn): array
    {
        $minPrice = 0;
        $maxPrice = 100;
        $res = mysqli_query($conn, "
            SELECT
                MIN(CASE
                    WHEN vstats.min_price IS NOT NULL AND sizestats.min_price IS NOT NULL THEN LEAST(vstats.min_price, sizestats.min_price)
                    ELSE COALESCE(vstats.min_price, sizestats.min_price, p.basePrice)
                END) AS min_price,
                MAX(CASE
                    WHEN vstats.max_price IS NOT NULL AND sizestats.max_price IS NOT NULL THEN GREATEST(vstats.max_price, sizestats.max_price)
                    ELSE COALESCE(vstats.max_price, sizestats.max_price, p.basePrice)
                END) AS max_price
            FROM products p
            LEFT JOIN (
                SELECT productID,
                       MIN(CASE WHEN price IS NOT NULL AND price >= 0 THEN price END) AS min_price,
                       MAX(CASE WHEN price IS NOT NULL AND price >= 0 THEN price END) AS max_price
                FROM product_variations
                GROUP BY productID
            ) vstats ON vstats.productID = p.productID
            LEFT JOIN (
                SELECT productID,
                       MIN(CASE WHEN price IS NOT NULL AND price >= 0 THEN price END) AS min_price,
                       MAX(CASE WHEN price IS NOT NULL AND price >= 0 THEN price END) AS max_price
                FROM product_size_prices
                GROUP BY productID
            ) sizestats ON sizestats.productID = p.productID
            WHERE p.cartStatus IN ('active', 'low_stock', 'out_of_stock', 'made_to_order')
        ");

        if ($res && ($row = mysqli_fetch_assoc($res))) {
            if ($row['min_price'] !== null && $row['max_price'] !== null) {
                $minPrice = (int)floor((float)$row['min_price']);
                $maxPrice = (int)ceil((float)$row['max_price']);
            }
        }

        if ($minPrice > $maxPrice) {
            $minPrice = 0;
            $maxPrice = 100;
        }

        return ['min' => $minPrice, 'max' => $maxPrice, 'step' => 1];
    }
}

if (!function_exists('app_shop_filter_default_tag_rows')) {
    function app_shop_filter_default_tag_rows(array $products): array
    {
        $definitions = [
            'velvet-soft' => ['en' => 'Velvet Soft', 'gr' => 'Velvet Soft', 'skus' => ['ATH-REAL-CHICK-HAT', 'ATH-REAL-OCTOPUS', 'ATH-REAL-WHALE', 'ATH-REAL-BEE', 'ATH-REAL-FROG-LEGS']],
            'sea-life' => ['en' => 'Sea Life', 'gr' => 'Sea Life', 'skus' => ['ATH-REAL-OCTOPUS', 'ATH-REAL-WHALE']],
            'character-faves' => ['en' => 'Character Faves', 'gr' => 'Character Faves', 'skus' => ['ATH-REAL-SPONGEBOB', 'ATH-REAL-PATRICK']],
            'nursery-cozy' => ['en' => 'Nursery Cozy', 'gr' => 'Nursery Cozy', 'skus' => ['ATH-REAL-CHICK-HAT', 'ATH-REAL-BLANKETS']],
            'playful-animals' => ['en' => 'Playful Animals', 'gr' => 'Playful Animals', 'skus' => ['ATH-REAL-CHICK-HAT', 'ATH-REAL-BEE', 'ATH-REAL-FROG-LEGS']],
            'best-sellers' => ['en' => 'Best Sellers', 'gr' => 'Best Sellers', 'skus' => []],
        ];

        $rows = [];
        foreach ($definitions as $id => $definition) {
            $assigned = [];
            foreach ($products as $product) {
                $sku = (string)($product['sku'] ?? '');
                if (in_array($sku, $definition['skus'], true) || ($id === 'best-sellers' && !empty($product['isSellingFast']))) {
                    $assigned[] = (int)$product['productID'];
                }
            }
            $rows[] = [
                'id' => $id,
                'label_en' => $definition['en'],
                'label_gr' => $definition['gr'],
                'active' => 1,
                'product_ids' => array_values(array_unique($assigned)),
            ];
        }

        return $rows;
    }
}

if (!function_exists('app_shop_filter_defaults')) {
    function app_shop_filter_defaults(mysqli $conn): array
    {
        $products = app_shop_filter_admin_products($conn);
        $categoryLabels = [
            'Plushies' => ['en' => 'Plushies', 'gr' => 'Plushies'],
            'Characters' => ['en' => 'Characters', 'gr' => 'Characters'],
            'Blankets' => ['en' => 'Blankets', 'gr' => 'Blankets'],
        ];

        $categories = [];
        foreach ($products as $product) {
            $category = trim((string)($product['category'] ?? ''));
            if ($category === '') {
                continue;
            }
            $id = app_shop_filter_slug($category, 'category');
            if (!isset($categories[$id])) {
                $label = $categoryLabels[$category] ?? ['en' => $category, 'gr' => $category];
                $categories[$id] = [
                    'id' => $id,
                    'label_en' => $label['en'],
                    'label_gr' => $label['gr'],
                    'active' => 1,
                    'product_ids' => [],
                ];
            }
            $categories[$id]['product_ids'][] = (int)$product['productID'];
        }

        $materials = [];
        $productMaterialSelect = app_shop_filter_product_column_exists($conn, 'materialType')
            ? "UNION
             SELECT DISTINCT materialType AS material FROM products WHERE materialType IS NOT NULL AND TRIM(materialType) <> ''"
            : "";
        $typeRes = mysqli_query(
            $conn,
            "SELECT typeName AS material FROM yarn_types WHERE typeName IS NOT NULL AND TRIM(typeName) <> ''
             {$productMaterialSelect}
             ORDER BY material ASC"
        );
        while ($typeRes && ($row = mysqli_fetch_assoc($typeRes))) {
            $material = trim((string)($row['material'] ?? ''));
            if ($material === '') {
                continue;
            }
            $id = app_shop_filter_slug($material, 'material');
            $materials[$id] = [
                'id' => $id,
                'label_en' => $material,
                'label_gr' => $material,
                'active' => 1,
                'product_ids' => [],
            ];
        }
        foreach ($products as $product) {
            $material = trim((string)($product['materialType'] ?? ''));
            $id = app_shop_filter_slug($material, 'material');
            if ($material !== '' && isset($materials[$id])) {
                $materials[$id]['product_ids'][] = (int)$product['productID'];
            }
        }

        return [
            'price' => app_shop_filter_price_bounds($conn),
            'categories' => array_values($categories),
            'materials' => array_values($materials),
            'tags' => app_shop_filter_default_tag_rows($products),
        ];
    }
}

if (!function_exists('app_shop_filter_normalize_rows')) {
    function app_shop_filter_normalize_rows(array $rows, array $fallbackRows, array $validProductIds): array
    {
        $normalized = [];
        $usedIds = [];
        $validLookup = array_fill_keys(array_map('intval', $validProductIds), true);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $labelEn = trim((string)($row['label_en'] ?? ''));
            $labelGr = trim((string)($row['label_gr'] ?? ''));
            $rawId = trim((string)($row['id'] ?? ''));
            if ($labelEn === '' && $rawId === '') {
                continue;
            }
            if ($labelEn === '') {
                $labelEn = ucwords(str_replace('-', ' ', $rawId));
            }
            if ($labelGr === '') {
                $labelGr = $labelEn;
            }

            $baseId = app_shop_filter_slug($rawId !== '' ? $rawId : $labelEn, 'option');
            $id = $baseId;
            $suffix = 2;
            while (isset($usedIds[$id])) {
                $id = $baseId . '-' . $suffix;
                $suffix++;
            }
            $usedIds[$id] = true;

            $postedProductIds = is_array($row['product_ids'] ?? null) ? $row['product_ids'] : [];
            $productIds = [];
            foreach ($postedProductIds as $productId) {
                $productId = (int)$productId;
                if ($productId > 0 && isset($validLookup[$productId])) {
                    $productIds[] = $productId;
                }
            }

            $normalized[] = [
                'id' => $id,
                'label_en' => $labelEn,
                'label_gr' => $labelGr,
                'active' => !empty($row['active']) ? 1 : 0,
                'product_ids' => array_values(array_unique($productIds)),
            ];
        }

        return $normalized;
    }
}

if (!function_exists('app_shop_filter_normalize')) {
    function app_shop_filter_normalize(mysqli $conn, array $input, array $defaults): array
    {
        $products = app_shop_filter_admin_products($conn);
        $validProductIds = array_keys($products);

        $priceInput = is_array($input['price'] ?? null) ? $input['price'] : [];
        $fallbackPrice = is_array($defaults['price'] ?? null) ? $defaults['price'] : ['min' => 0, 'max' => 100, 'step' => 1];
        $min = isset($priceInput['min']) && $priceInput['min'] !== '' ? (float)$priceInput['min'] : (float)$fallbackPrice['min'];
        $max = isset($priceInput['max']) && $priceInput['max'] !== '' ? (float)$priceInput['max'] : (float)$fallbackPrice['max'];
        $step = isset($priceInput['step']) && $priceInput['step'] !== '' ? (float)$priceInput['step'] : (float)($fallbackPrice['step'] ?? 1);
        if ($min < 0) {
            $min = 0;
        }
        if ($max < $min) {
            $max = $min;
        }
        if ($step <= 0) {
            $step = 1;
        }

        return [
            'price' => [
                'min' => round($min, 2),
                'max' => round($max, 2),
                'step' => round($step, 2),
            ],
            'categories' => app_shop_filter_normalize_rows(
                array_key_exists('categories', $input)
                    ? (is_array($input['categories'] ?? null) ? $input['categories'] : [])
                    : (is_array($defaults['categories'] ?? null) ? $defaults['categories'] : []),
                [],
                $validProductIds
            ),
            'materials' => app_shop_filter_normalize_rows(
                array_key_exists('materials', $input)
                    ? (is_array($input['materials'] ?? null) ? $input['materials'] : [])
                    : (is_array($defaults['materials'] ?? null) ? $defaults['materials'] : []),
                [],
                $validProductIds
            ),
            'tags' => app_shop_filter_normalize_rows(
                array_key_exists('tags', $input)
                    ? (is_array($input['tags'] ?? null) ? $input['tags'] : [])
                    : (is_array($defaults['tags'] ?? null) ? $defaults['tags'] : []),
                [],
                $validProductIds
            ),
        ];
    }
}

if (!function_exists('app_shop_filter_settings')) {
    function app_shop_filter_settings(mysqli $conn): array
    {
        $defaults = app_shop_filter_defaults($conn);
        app_system_config_seed_defaults($conn, [
            'shop_filter_config_json' => json_encode($defaults, JSON_UNESCAPED_UNICODE),
        ]);

        $raw = app_system_config_get($conn, 'shop_filter_config_json', '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        return app_shop_filter_normalize($conn, is_array($decoded) ? $decoded : [], $defaults);
    }
}

if (!function_exists('app_shop_filter_save')) {
    function app_shop_filter_save(mysqli $conn, array $input): bool
    {
        $settings = app_shop_filter_normalize($conn, $input, app_shop_filter_defaults($conn));
        return app_system_config_set($conn, 'shop_filter_config_json', json_encode($settings, JSON_UNESCAPED_UNICODE));
    }
}

if (!function_exists('app_shop_filter_active_options')) {
    function app_shop_filter_active_options(array $settings, string $type): array
    {
        $rows = is_array($settings[$type] ?? null) ? $settings[$type] : [];
        return array_values(array_filter($rows, static function (array $row): bool {
            return !empty($row['active']);
        }));
    }
}

if (!function_exists('app_shop_filter_valid_ids')) {
    function app_shop_filter_valid_ids(array $settings, string $type): array
    {
        return array_map(
            static fn(array $row): string => (string)$row['id'],
            app_shop_filter_active_options($settings, $type)
        );
    }
}

if (!function_exists('app_shop_filter_product_ids_for')) {
    function app_shop_filter_product_ids_for(array $settings, string $type, array $selectedIds): array
    {
        $selectedLookup = array_fill_keys(array_map('strval', $selectedIds), true);
        $ids = [];
        foreach (app_shop_filter_active_options($settings, $type) as $row) {
            if (!isset($selectedLookup[(string)$row['id']])) {
                continue;
            }
            foreach ((array)($row['product_ids'] ?? []) as $productId) {
                $productId = (int)$productId;
                if ($productId > 0) {
                    $ids[] = $productId;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}

if (!function_exists('app_shop_filter_product_option_ids')) {
    function app_shop_filter_product_option_ids(array $settings, string $type, int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }
        $ids = [];
        foreach (app_shop_filter_active_options($settings, $type) as $row) {
            $productIds = array_map('intval', (array)($row['product_ids'] ?? []));
            if (in_array($productId, $productIds, true)) {
                $ids[] = (string)$row['id'];
            }
        }

        return $ids;
    }
}
