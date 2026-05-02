<?php

if (!function_exists('app_product_options_table_exists')) {
    function app_product_options_table_exists(mysqli $conn, string $tableName): bool
    {
        $safeTable = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '" . $safeTable . "'");
        return (bool)($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('app_product_options_column_exists')) {
    function app_product_options_column_exists(mysqli $conn, string $tableName, string $columnName): bool
    {
        if (!app_product_options_table_exists($conn, $tableName)) {
            return false;
        }

        $safeColumn = mysqli_real_escape_string($conn, $columnName);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `" . str_replace('`', '``', $tableName) . "` LIKE '" . $safeColumn . "'");
        return (bool)($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('app_product_status_from_stock')) {
    function app_product_status_from_stock(int $inventory, string $currentStatus = ''): string
    {
        $currentStatus = strtolower(trim($currentStatus));
        if (in_array($currentStatus, ['discontinued', 'made_to_order'], true)) {
            return $currentStatus;
        }
        if ($inventory <= 0) {
            return 'out_of_stock';
        }
        if ($inventory <= 3) {
            return 'low_stock';
        }
        return 'active';
    }
}

if (!function_exists('app_product_sync_stock_statuses')) {
    function app_product_sync_stock_statuses(mysqli $conn): void
    {
        if (!app_product_options_table_exists($conn, 'products')) {
            return;
        }

        mysqli_query(
            $conn,
            "UPDATE products
             SET cartStatus = CASE
                 WHEN inventory <= 0 THEN 'out_of_stock'
                 WHEN inventory <= 3 THEN 'low_stock'
                 ELSE 'active'
             END
             WHERE cartStatus NOT IN ('made_to_order', 'discontinued')"
        );
    }
}

if (!function_exists('app_product_options_ensure_schema')) {
    function app_product_options_ensure_schema(mysqli $conn): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        if (app_product_options_table_exists($conn, 'products')) {
            $productColumns = [
                'materialType' => "ALTER TABLE products ADD COLUMN materialType VARCHAR(100) NULL AFTER category",
                'shippingWeightKg' => "ALTER TABLE products ADD COLUMN shippingWeightKg DECIMAL(6,3) NULL AFTER materialType",
                'shippingSizeCode' => "ALTER TABLE products ADD COLUMN shippingSizeCode VARCHAR(20) NULL AFTER shippingWeightKg",
                'customColorFields' => "ALTER TABLE products ADD COLUMN customColorFields TINYINT(1) NOT NULL DEFAULT 0 AFTER category",
                'customColorLabel1' => "ALTER TABLE products ADD COLUMN customColorLabel1 VARCHAR(120) NULL AFTER customColorFields",
                'customColorLabel2' => "ALTER TABLE products ADD COLUMN customColorLabel2 VARCHAR(120) NULL AFTER customColorLabel1",
                'customColorLabel1GR' => "ALTER TABLE products ADD COLUMN customColorLabel1GR VARCHAR(120) NULL AFTER customColorLabel2",
                'customColorLabel2GR' => "ALTER TABLE products ADD COLUMN customColorLabel2GR VARCHAR(120) NULL AFTER customColorLabel1GR",
                'customColorHelpText' => "ALTER TABLE products ADD COLUMN customColorHelpText VARCHAR(255) NULL AFTER customColorLabel2GR",
                'customColorHelpTextGR' => "ALTER TABLE products ADD COLUMN customColorHelpTextGR VARCHAR(255) NULL AFTER customColorHelpText",
                'availableSizes' => "ALTER TABLE products ADD COLUMN availableSizes TEXT NULL DEFAULT NULL",
                'productWarningEN' => "ALTER TABLE products ADD COLUMN productWarningEN TEXT NULL AFTER availableSizes",
                'productWarningGR' => "ALTER TABLE products ADD COLUMN productWarningGR TEXT NULL AFTER productWarningEN",
            ];

            foreach ($productColumns as $columnName => $sql) {
                if (!app_product_options_column_exists($conn, 'products', $columnName)) {
                    mysqli_query($conn, $sql);
                }
            }

            mysqli_query(
                $conn,
                "UPDATE products
                 SET materialType = CASE
                     WHEN category = 'Blankets' OR sku LIKE '%BLANKET%' THEN 'Puffy'
                     ELSE 'Velvet'
                 END
                 WHERE materialType IS NULL OR TRIM(materialType) = ''"
            );
            mysqli_query(
                $conn,
                "UPDATE products
                 SET shippingWeightKg = CASE
                     WHEN category = 'Blankets' OR sku LIKE '%BLANKET%' THEN 1.200
                     WHEN LOWER(COALESCE(nameEN, '')) LIKE '%large%' THEN 0.800
                     ELSE 0.350
                 END
                 WHERE shippingWeightKg IS NULL"
            );
            mysqli_query(
                $conn,
                "UPDATE products
                 SET shippingSizeCode = CASE
                     WHEN category = 'Blankets' OR sku LIKE '%BLANKET%' THEN 'large'
                     WHEN LOWER(COALESCE(nameEN, '')) LIKE '%large%' THEN 'medium'
                     ELSE 'small'
                 END
                 WHERE shippingSizeCode IS NULL OR TRIM(shippingSizeCode) = ''"
            );
            mysqli_query(
                $conn,
                "UPDATE products SET availableSizes = 'Small,Medium,Large'
                 WHERE availableSizes IS NULL OR TRIM(availableSizes) = ''"
            );
        }

        if (app_product_options_table_exists($conn, 'colors')) {
            $addedColorHexColumn = false;
            if (!app_product_options_column_exists($conn, 'colors', 'displayCode')) {
                mysqli_query($conn, "ALTER TABLE colors ADD COLUMN displayCode VARCHAR(32) NULL AFTER colorName");
            }
            if (!app_product_options_column_exists($conn, 'colors', 'hexCode')) {
                mysqli_query($conn, "ALTER TABLE colors ADD COLUMN hexCode VARCHAR(7) NOT NULL DEFAULT '#ece6f6' AFTER colorName");
                $addedColorHexColumn = true;
            }
            $uniqueColorNameCheck = mysqli_query($conn, "SHOW INDEX FROM colors WHERE Key_name = 'uq_colors_colorName'");
            if ($uniqueColorNameCheck && mysqli_num_rows($uniqueColorNameCheck) > 0) {
                mysqli_query($conn, "ALTER TABLE colors DROP INDEX uq_colors_colorName");
            }

            $seedColorHexSql = "UPDATE colors
                SET hexCode = CASE LOWER(TRIM(colorName))
                    WHEN 'sunshine yellow' THEN '#f8ea75'
                    WHEN 'honey blend' THEN '#dda157'
                    WHEN 'bluebell' THEN '#cad7ff'
                    WHEN 'sugar pink' THEN '#f5dce8'
                    WHEN 'lavender mist' THEN '#d6c9ff'
                    WHEN 'blush pink' THEN '#ffdbe5'
                    WHEN 'lemon yellow' THEN '#f8ea75'
                    WHEN 'deep plum' THEN '#5b2a63'
                    WHEN 'berry plum' THEN '#99566a'
                    WHEN 'soft lilac' THEN '#d9dcfb'
                    WHEN 'forest sage' THEN '#7f9d88'
                    WHEN 'sky mist' THEN '#dce8ff'
                    WHEN 'lavender cloud' THEN '#d7c5ff'
                    WHEN 'mint frost' THEN '#d6f0ea'
                    WHEN 'peach sorbet' THEN '#ffca9a'
                    WHEN 'seafoam' THEN '#bce7da'
                    WHEN 'lavender pop' THEN '#c8aaf8'
                    WHEN 'snow white' THEN '#f4f3fb'
                    WHEN 'warm oatmeal' THEN '#ccb594'
                    ELSE '#ece6f6'
                END";
            mysqli_query(
                $conn,
                $addedColorHexColumn
                    ? $seedColorHexSql
                    : $seedColorHexSql . " WHERE hexCode IS NULL OR TRIM(hexCode) = ''"
            );
        }

        if (app_product_options_table_exists($conn, 'product_variations')) {
            $variationColumns = [
                'price' => "ALTER TABLE product_variations ADD COLUMN price DOUBLE NULL DEFAULT NULL AFTER colorID",
                'shippingWeightKg' => "ALTER TABLE product_variations ADD COLUMN shippingWeightKg DECIMAL(6,3) NULL AFTER price",
                'shippingSizeCode' => "ALTER TABLE product_variations ADD COLUMN shippingSizeCode VARCHAR(20) NULL AFTER shippingWeightKg",
            ];

            foreach ($variationColumns as $columnName => $sql) {
                if (!app_product_options_column_exists($conn, 'product_variations', $columnName)) {
                    mysqli_query($conn, $sql);
                }
            }
        }

        if (app_product_options_table_exists($conn, 'yarn_types')) {
            $duplicateTypes = mysqli_query(
                $conn,
                "SELECT typeName, MIN(typeID) AS keepID
                 FROM yarn_types
                 GROUP BY typeName
                 HAVING COUNT(*) > 1"
            );
            if ($duplicateTypes) {
                while ($typeRow = mysqli_fetch_assoc($duplicateTypes)) {
                    $typeName = (string)($typeRow['typeName'] ?? '');
                    $keepID = (int)($typeRow['keepID'] ?? 0);
                    if ($typeName === '' || $keepID <= 0) {
                        continue;
                    }
                    $safeType = mysqli_real_escape_string($conn, $typeName);
                    $dupRes = mysqli_query(
                        $conn,
                        "SELECT typeID FROM yarn_types
                         WHERE typeName = '{$safeType}' AND typeID <> {$keepID}"
                    );
                    while ($dupRes && ($dupRow = mysqli_fetch_assoc($dupRes))) {
                        $duplicateID = (int)($dupRow['typeID'] ?? 0);
                        if ($duplicateID <= 0) {
                            continue;
                        }

                        $linkRes = mysqli_query(
                            $conn,
                            "SELECT colorID, photoPath FROM color_yarn_types WHERE typeID = {$duplicateID}"
                        );
                        while ($linkRes && ($linkRow = mysqli_fetch_assoc($linkRes))) {
                            $colorID = (int)($linkRow['colorID'] ?? 0);
                            $photoPath = (string)($linkRow['photoPath'] ?? '');
                            if ($colorID <= 0) {
                                continue;
                            }
                            $safePhoto = mysqli_real_escape_string($conn, $photoPath);
                            mysqli_query(
                                $conn,
                                "INSERT INTO color_yarn_types (colorID, typeID, photoPath)
                                 VALUES ({$colorID}, {$keepID}, " . ($photoPath !== '' ? "'{$safePhoto}'" : "NULL") . ")
                                 ON DUPLICATE KEY UPDATE
                                    photoPath = CASE
                                        WHEN (photoPath IS NULL OR TRIM(photoPath) = '') AND VALUES(photoPath) IS NOT NULL
                                        THEN VALUES(photoPath)
                                        ELSE photoPath
                                    END"
                            );
                        }
                        mysqli_query($conn, "DELETE FROM color_yarn_types WHERE typeID = {$duplicateID}");
                        mysqli_query($conn, "DELETE FROM yarn_types WHERE typeID = {$duplicateID}");
                    }
                }
            }

            mysqli_query(
                $conn,
                "INSERT INTO yarn_types (typeName)
                 SELECT 'Puffy'
                 WHERE NOT EXISTS (
                     SELECT 1 FROM yarn_types WHERE typeName = 'Puffy' LIMIT 1
                 )"
            );
            $puffyTypeRes = mysqli_query(
                $conn,
                "SELECT
                    MAX(CASE WHEN typeName = 'Puffy' THEN typeID END) AS puffyID,
                    MAX(CASE WHEN typeName = 'Puffy Color' THEN typeID END) AS puffyColorID
                 FROM yarn_types
                 WHERE typeName IN ('Puffy', 'Puffy Color')"
            );
            $puffyTypeRow = $puffyTypeRes ? mysqli_fetch_assoc($puffyTypeRes) : null;
            $puffyTypeID = (int)($puffyTypeRow['puffyID'] ?? 0);
            $puffyColorTypeID = (int)($puffyTypeRow['puffyColorID'] ?? 0);
            if ($puffyTypeID > 0 && $puffyColorTypeID > 0 && $puffyTypeID !== $puffyColorTypeID) {
                $puffyLinks = mysqli_query(
                    $conn,
                    "SELECT colorID, photoPath FROM color_yarn_types WHERE typeID = {$puffyColorTypeID}"
                );
                while ($puffyLinks && ($linkRow = mysqli_fetch_assoc($puffyLinks))) {
                    $colorID = (int)($linkRow['colorID'] ?? 0);
                    $photoPath = (string)($linkRow['photoPath'] ?? '');
                    if ($colorID <= 0) {
                        continue;
                    }
                    $safePhoto = mysqli_real_escape_string($conn, $photoPath);
                    mysqli_query(
                        $conn,
                        "INSERT INTO color_yarn_types (colorID, typeID, photoPath)
                         VALUES ({$colorID}, {$puffyTypeID}, " . ($photoPath !== '' ? "'{$safePhoto}'" : "NULL") . ")
                         ON DUPLICATE KEY UPDATE
                            photoPath = CASE
                                WHEN (photoPath IS NULL OR TRIM(photoPath) = '') AND VALUES(photoPath) IS NOT NULL
                                THEN VALUES(photoPath)
                                ELSE photoPath
                            END"
                    );
                }
                mysqli_query($conn, "DELETE FROM color_yarn_types WHERE typeID = {$puffyColorTypeID}");
                mysqli_query($conn, "DELETE FROM yarn_types WHERE typeID = {$puffyColorTypeID}");
                mysqli_query($conn, "UPDATE colors SET colorName = REPLACE(colorName, 'Puffy Color ', 'Puffy ') WHERE colorName LIKE 'Puffy Color %'");
            }

            $uniqueTypeCheck = mysqli_query(
                $conn,
                "SHOW INDEX FROM yarn_types WHERE Key_name = 'uq_yarn_types_typeName'"
            );
            if (!$uniqueTypeCheck || mysqli_num_rows($uniqueTypeCheck) === 0) {
                mysqli_query($conn, "ALTER TABLE yarn_types ADD UNIQUE KEY uq_yarn_types_typeName (typeName)");
            }

            foreach (['Baby Anti Pilling', 'Cotton', 'Puffy', 'Velvet'] as $typeName) {
                $safeType = mysqli_real_escape_string($conn, $typeName);
                mysqli_query(
                    $conn,
                    "INSERT INTO yarn_types (typeName)
                     SELECT '{$safeType}'
                     WHERE NOT EXISTS (
                         SELECT 1 FROM yarn_types WHERE typeName = '{$safeType}' LIMIT 1
                     )"
                );
            }
        }

        mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS product_variation_photos (
                variationPhotoID INT AUTO_INCREMENT PRIMARY KEY,
                variationID INT NOT NULL,
                photoPath VARCHAR(255) NOT NULL,
                sortOrder INT NOT NULL DEFAULT 0,
                KEY idx_pvp_variation (variationID),
                CONSTRAINT fk_pvp_variation
                    FOREIGN KEY (variationID) REFERENCES product_variations(variationID)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS product_size_prices (
                productID INT NOT NULL,
                sizeLabel VARCHAR(120) NOT NULL,
                price DOUBLE NULL DEFAULT NULL,
                updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (productID, sizeLabel),
                KEY idx_product_size_prices_product (productID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS product_color_availability (
                productID INT NOT NULL,
                colorID INT NOT NULL,
                isAvailable TINYINT(1) NOT NULL DEFAULT 1,
                updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (productID, colorID),
                KEY idx_pca_color (colorID),
                CONSTRAINT fk_pca_product
                    FOREIGN KEY (productID) REFERENCES products(productID)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_pca_color
                    FOREIGN KEY (colorID) REFERENCES colors(colorID)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        if (app_product_options_table_exists($conn, 'order_items')
            && !app_product_options_column_exists($conn, 'order_items', 'customizationNote')
        ) {
            mysqli_query($conn, "ALTER TABLE order_items ADD COLUMN customizationNote VARCHAR(255) NULL AFTER giftMessage");
        }
    }
}

if (!function_exists('app_product_size_label_normalize')) {
    function app_product_size_label_normalize(string $sizeLabel): string
    {
        $sizeLabel = trim(preg_replace('/\s+/', ' ', $sizeLabel));
        return function_exists('mb_substr') ? mb_substr($sizeLabel, 0, 120, 'UTF-8') : substr($sizeLabel, 0, 120);
    }
}

if (!function_exists('app_product_size_prices_for_product')) {
    function app_product_size_prices_for_product(mysqli $conn, int $productId): array
    {
        app_product_options_ensure_schema($conn);
        if ($productId <= 0 || !app_product_options_table_exists($conn, 'product_size_prices')) {
            return [];
        }

        $stmt = mysqli_prepare($conn, "SELECT sizeLabel, price FROM product_size_prices WHERE productID = ? ORDER BY sizeLabel ASC");
        if (!$stmt) {
            return [];
        }

        mysqli_stmt_bind_param($stmt, 'i', $productId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $prices = [];
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $label = app_product_size_label_normalize((string)($row['sizeLabel'] ?? ''));
            if ($label === '') {
                continue;
            }
            $prices[$label] = (float)($row['price'] ?? 0);
        }
        mysqli_stmt_close($stmt);

        return $prices;
    }
}

if (!function_exists('app_product_size_price_for_product_size')) {
    function app_product_size_price_for_product_size(mysqli $conn, int $productId, string $sizeLabel): ?float
    {
        $sizeLabel = app_product_size_label_normalize($sizeLabel);
        if ($productId <= 0 || $sizeLabel === '') {
            return null;
        }

        foreach (app_product_size_prices_for_product($conn, $productId) as $storedLabel => $price) {
            if (function_exists('mb_strtolower')) {
                $left = mb_strtolower($storedLabel, 'UTF-8');
                $right = mb_strtolower($sizeLabel, 'UTF-8');
            } else {
                $left = strtolower($storedLabel);
                $right = strtolower($sizeLabel);
            }
            if ($left === $right) {
                return (float)$price;
            }
        }

        return null;
    }
}

if (!function_exists('app_color_display_sql')) {
    function app_color_display_sql(string $alias = 'c'): string
    {
        $alias = trim($alias);
        if ($alias === '') {
            $alias = 'colors';
        }
        $safeAlias = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
        if ($safeAlias === '') {
            $safeAlias = 'c';
        }
        $colorName = "TRIM(COALESCE({$safeAlias}.colorName, ''))";
        $displayCode = "TRIM(COALESCE({$safeAlias}.displayCode, ''))";
        $humanName = "TRIM(CASE
            WHEN {$displayCode} <> ''
             AND RIGHT({$colorName}, CHAR_LENGTH({$displayCode}) + 1) = CONCAT(' ', {$displayCode})
            THEN LEFT({$colorName}, CHAR_LENGTH({$colorName}) - CHAR_LENGTH({$displayCode}) - 1)
            ELSE {$colorName}
        END)";

        return "COALESCE(NULLIF({$humanName}, ''), NULLIF({$displayCode}, ''), CONCAT('Colour ', {$safeAlias}.colorID))";
    }
}

if (!function_exists('app_color_display_name')) {
    function app_color_display_name(array $row): string
    {
        $colorName = trim((string)($row['colorName'] ?? ''));
        $displayCode = trim((string)($row['displayCode'] ?? ''));
        if ($colorName !== '') {
            if ($displayCode !== '') {
                $withoutCode = trim((string)preg_replace('/\s+' . preg_quote($displayCode, '/') . '$/u', '', $colorName));
                if ($withoutCode !== '') {
                    return $withoutCode;
                }
            }
            return $colorName;
        }
        return $displayCode;
    }
}

if (!function_exists('app_product_available_sizes_from_string')) {
    function app_product_available_sizes_from_string(string $value): array
    {
        $parts = array_map('app_product_size_label_normalize', explode(',', $value));
        $sizes = [];
        foreach ($parts as $size) {
            if ($size === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($size, 'UTF-8') : strtolower($size);
            if (!isset($sizes[$key])) {
                $sizes[$key] = $size;
            }
        }
        return array_values($sizes);
    }
}

if (!function_exists('app_product_size_prices_save')) {
    function app_product_size_prices_save(mysqli $conn, int $productId, array $sizes, array $postedPrices): void
    {
        app_product_options_ensure_schema($conn);
        if ($productId <= 0) {
            return;
        }

        $delete = mysqli_prepare($conn, "DELETE FROM product_size_prices WHERE productID = ?");
        if ($delete) {
            mysqli_stmt_bind_param($delete, 'i', $productId);
            mysqli_stmt_execute($delete);
            mysqli_stmt_close($delete);
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO product_size_prices (productID, sizeLabel, price) VALUES (?, ?, ?)"
        );
        if (!$stmt) {
            return;
        }

        foreach ($sizes as $size) {
            $size = app_product_size_label_normalize((string)$size);
            if ($size === '') {
                continue;
            }
            $raw = $postedPrices[$size] ?? null;
            if ($raw === null) {
                foreach ($postedPrices as $postedLabel => $postedValue) {
                    $left = function_exists('mb_strtolower') ? mb_strtolower((string)$postedLabel, 'UTF-8') : strtolower((string)$postedLabel);
                    $right = function_exists('mb_strtolower') ? mb_strtolower($size, 'UTF-8') : strtolower($size);
                    if ($left === $right) {
                        $raw = $postedValue;
                        break;
                    }
                }
            }
            if ($raw === null || trim((string)$raw) === '') {
                continue;
            }
            $price = round(max(0.0, (float)$raw), 2);
            mysqli_stmt_bind_param($stmt, 'isd', $productId, $size, $price);
            mysqli_stmt_execute($stmt);
        }

        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('app_product_options_has_custom_color_fields')) {
    function app_product_options_has_custom_color_fields(array $product): bool
    {
        return (int)($product['customColorFields'] ?? 0) > 0;
    }
}

if (!function_exists('app_product_options_pick_color_scheme_value')) {
    function app_product_options_pick_color_scheme_value(array $source, string $slot): string
    {
        $slot = strtoupper(trim($slot));
        $slotNumber = ['A' => '1', 'B' => '2', 'C' => '3'][$slot] ?? '';

        $candidateKeys = [
            'colorScheme' . $slot,
            'color_scheme_' . $slot,
            'colourScheme' . $slot,
            'colour_scheme_' . $slot,
        ];
        if ($slotNumber !== '') {
            $candidateKeys[] = 'colorScheme' . $slotNumber;
            $candidateKeys[] = 'color_scheme_' . $slotNumber;
            $candidateKeys[] = 'colourScheme' . $slotNumber;
            $candidateKeys[] = 'colour_scheme_' . $slotNumber;
        }

        foreach ($candidateKeys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            return trim((string)$source[$key]);
        }

        return '';
    }
}

if (!function_exists('app_product_options_build_customization_summary')) {
    function app_product_options_build_customization_summary(array $product, array $customization): string
    {
        $labels = [
            trim((string)($product['customColorLabel1'] ?? '')),
            trim((string)($product['customColorLabel2'] ?? '')),
        ];
        $values = [
            trim((string)($customization['field1'] ?? '')),
            trim((string)($customization['field2'] ?? '')),
        ];

        $parts = [];
        foreach ($values as $idx => $value) {
            if ($value === '') {
                continue;
            }
            $label = $labels[$idx] !== '' ? $labels[$idx] : ('Custom option ' . ($idx + 1));
            $parts[] = $label . ': ' . $value;
        }

        $csA = app_product_options_pick_color_scheme_value($customization, 'A');
        $csB = app_product_options_pick_color_scheme_value($customization, 'B');
        $csC = app_product_options_pick_color_scheme_value($customization, 'C');
        if ($csA !== '') $parts[] = 'Colour A: ' . $csA;
        if ($csB !== '') $parts[] = 'Colour B: ' . $csB;
        if ($csC !== '') $parts[] = 'Colour C: ' . $csC;

        return implode(' | ', $parts);
    }
}
