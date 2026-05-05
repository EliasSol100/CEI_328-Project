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

if (!function_exists('app_product_options_index_exists')) {
    function app_product_options_index_exists(mysqli $conn, string $tableName, string $indexName): bool
    {
        if (!app_product_options_table_exists($conn, $tableName)) {
            return false;
        }

        $safeIndex = mysqli_real_escape_string($conn, $indexName);
        $safeTable = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query(
            $conn,
            "SELECT 1
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = '{$safeTable}'
               AND INDEX_NAME = '{$safeIndex}'
             LIMIT 1"
        );
        return (bool)($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('app_product_options_add_index_if_missing')) {
    function app_product_options_add_index_if_missing(mysqli $conn, string $tableName, string $indexName, string $sql): void
    {
        if (app_product_options_index_exists($conn, $tableName, $indexName)) {
            return;
        }

        try {
            mysqli_query($conn, $sql);
        } catch (mysqli_sql_exception $e) {
            if (stripos($e->getMessage(), 'Duplicate key name') === false) {
                throw $e;
            }
        }
    }
}

if (!function_exists('app_product_status_from_stock')) {
    function app_product_status_from_stock(int $inventory, string $currentStatus = ''): string
    {
        $currentStatus = strtolower(trim($currentStatus));
        if (in_array($currentStatus, ['discontinued', 'made_to_order'], true)) {
            return $currentStatus;
        }

        // Public catalog products are made-to-order now, so product stock no longer drives storefront status.
        return 'active';
    }
}

if (!function_exists('app_product_sync_stock_statuses')) {
    function app_product_sync_stock_statuses(mysqli $conn): void
    {
        // Product stock is kept only as legacy/reference data. Do not derive public availability from it.
        return;
    }
}

if (!function_exists('app_product_options_sync_colour_inventory_refs')) {
    function app_product_options_sync_colour_inventory_refs(mysqli $conn): void
    {
        if (!app_product_options_table_exists($conn, 'products')
            || !app_product_options_table_exists($conn, 'color_yarn_types')
        ) {
            return;
        }

        $productTypeJoin = "
            JOIN products p ON p.productID = %s
            LEFT JOIN yarn_types fallback_yt
              ON LOWER(TRIM(fallback_yt.typeName)) = LOWER(TRIM(COALESCE(p.materialType, '')))
            LEFT JOIN color_yarn_types valid_cyt
              ON valid_cyt.colorID = %s
             AND valid_cyt.typeID = COALESCE(NULLIF(p.yarnTypeID, 0), fallback_yt.typeID)
        ";

        if (app_product_options_table_exists($conn, 'product_color_photos')) {
            mysqli_query(
                $conn,
                "DELETE pcp
                 FROM product_color_photos pcp
                 " . sprintf($productTypeJoin, 'pcp.productID', 'pcp.colorID') . "
                 WHERE valid_cyt.colorID IS NULL"
            );
        }

        if (app_product_options_table_exists($conn, 'product_color_availability')) {
            mysqli_query(
                $conn,
                "DELETE pca
                 FROM product_color_availability pca
                 " . sprintf($productTypeJoin, 'pca.productID', 'pca.colorID') . "
                 WHERE valid_cyt.colorID IS NULL"
            );
        }

        if (!app_product_options_table_exists($conn, 'product_variations')) {
            return;
        }

        $variationInvalidJoin = "
            JOIN product_variations pv ON pv.variationID = %s
            JOIN products p ON p.productID = pv.productID
            LEFT JOIN yarn_types fallback_yt
              ON LOWER(TRIM(fallback_yt.typeName)) = LOWER(TRIM(COALESCE(p.materialType, '')))
            LEFT JOIN color_yarn_types valid_cyt
              ON valid_cyt.colorID = pv.colorID
             AND valid_cyt.typeID = COALESCE(NULLIF(p.yarnTypeID, 0), fallback_yt.typeID)
        ";

        if (app_product_options_table_exists($conn, 'product_variation_photos')) {
            mysqli_query(
                $conn,
                "DELETE pvp
                 FROM product_variation_photos pvp
                 " . sprintf($variationInvalidJoin, 'pvp.variationID') . "
                 WHERE pv.colorID IS NOT NULL
                   AND valid_cyt.colorID IS NULL"
            );
        }

        if (app_product_options_table_exists($conn, 'variation_stock')) {
            mysqli_query(
                $conn,
                "DELETE vs
                 FROM variation_stock vs
                 " . sprintf($variationInvalidJoin, 'vs.variationID') . "
                 WHERE pv.colorID IS NOT NULL
                   AND valid_cyt.colorID IS NULL
                   AND (pv.size IS NULL OR TRIM(pv.size) = '')
                   AND (pv.yarnType IS NULL OR TRIM(pv.yarnType) = '')
                   AND pv.price IS NULL"
            );
        }

        mysqli_query(
            $conn,
            "DELETE pv
             FROM product_variations pv
             JOIN products p ON p.productID = pv.productID
             LEFT JOIN yarn_types fallback_yt
               ON LOWER(TRIM(fallback_yt.typeName)) = LOWER(TRIM(COALESCE(p.materialType, '')))
             LEFT JOIN color_yarn_types valid_cyt
               ON valid_cyt.colorID = pv.colorID
              AND valid_cyt.typeID = COALESCE(NULLIF(p.yarnTypeID, 0), fallback_yt.typeID)
             WHERE pv.colorID IS NOT NULL
               AND valid_cyt.colorID IS NULL
               AND (pv.size IS NULL OR TRIM(pv.size) = '')
               AND (pv.yarnType IS NULL OR TRIM(pv.yarnType) = '')
               AND pv.price IS NULL"
        );

        mysqli_query(
            $conn,
            "UPDATE product_variations pv
             JOIN products p ON p.productID = pv.productID
             LEFT JOIN yarn_types fallback_yt
               ON LOWER(TRIM(fallback_yt.typeName)) = LOWER(TRIM(COALESCE(p.materialType, '')))
             LEFT JOIN color_yarn_types valid_cyt
               ON valid_cyt.colorID = pv.colorID
              AND valid_cyt.typeID = COALESCE(NULLIF(p.yarnTypeID, 0), fallback_yt.typeID)
             SET pv.colorID = NULL
             WHERE pv.colorID IS NOT NULL
               AND valid_cyt.colorID IS NULL"
        );
    }
}

if (!function_exists('app_product_options_ensure_colour_inventory_tables')) {
    function app_product_options_ensure_colour_inventory_tables(mysqli $conn): void
    {
        mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS yarn_types (
                typeID INT NOT NULL AUTO_INCREMENT,
                typeName VARCHAR(100) NOT NULL,
                PRIMARY KEY (typeID),
                UNIQUE KEY uq_yarn_types_typeName (typeName)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS colors (
                colorID INT NOT NULL,
                colorName VARCHAR(100) NOT NULL,
                displayCode VARCHAR(32) NULL,
                hexCode VARCHAR(7) NOT NULL DEFAULT '#ece6f6',
                globalInventoryAvailable INT NOT NULL DEFAULT 0,
                isActive TINYINT(1) NOT NULL DEFAULT 1,
                updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (colorID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS color_yarn_types (
                colorID INT NOT NULL,
                typeID INT NOT NULL,
                photoPath VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (colorID, typeID),
                KEY typeID (typeID),
                CONSTRAINT color_yarn_types_ibfk_1
                    FOREIGN KEY (colorID) REFERENCES colors (colorID) ON DELETE CASCADE,
                CONSTRAINT color_yarn_types_ibfk_2
                    FOREIGN KEY (typeID) REFERENCES yarn_types (typeID) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}

if (!function_exists('app_product_options_default_yarn_colour_catalogue')) {
    function app_product_options_default_yarn_colour_catalogue(): array
    {
        return [
            'Baby Anti Pilling' => [
                [100055, 'Snow White', '55', 'assets/yarn_colors/alize_baby-best_55.webp'],
                [100062, 'Ivory Cream', '62', 'assets/yarn_colors/alize_baby-best_62.webp'],
                [100185, 'Baby Pink', '185', 'assets/yarn_colors/alize_baby-best_185.webp'],
                [100237, 'Cornflower Blue', '237', 'assets/yarn_colors/alize_baby-best_237.webp'],
                [100250, 'Lemon Yellow', '250', 'assets/yarn_colors/alize_baby-best_250.webp'],
                [100287, 'Aqua Teal', '287', 'assets/yarn_colors/alize_baby-best_287.webp'],
                [100310, 'Warm Beige', '310', 'assets/yarn_colors/alize_baby-best_310.webp'],
                [100336, 'Tangerine Orange', '336', 'assets/yarn_colors/alize_baby-best_336.webp'],
                [100344, 'Silver Grey', '344', 'assets/yarn_colors/alize_baby-best_344.webp'],
                [100599, 'Taupe Beige', '599', 'assets/yarn_colors/alize_baby-best_599.webp'],
            ],
            'Cotton' => [
                [200001, 'Ivory Cream', '1', 'assets/yarn_colors/alize_cotton-gold_1.webp'],
                [200036, 'Rust Brown', '36', 'assets/yarn_colors/alize_cotton-gold_36.webp'],
                [200055, 'Snow White', '55', 'assets/yarn_colors/alize_cotton-gold_55.webp'],
                [200056, 'Cherry Red', '56', 'assets/yarn_colors/alize_cotton-gold_56.webp'],
                [200060, 'Black', '60', 'assets/yarn_colors/alize_cotton-gold_60.webp'],
                [200062, 'Soft White', '62', 'assets/yarn_colors/alize_cotton-gold_62.webp'],
                [200149, 'Hot Pink', '149', 'assets/yarn_colors/alize_cotton-gold_149.webp'],
                [200216, 'Golden Yellow', '216', 'assets/yarn_colors/alize_cotton-gold_216.webp'],
                [200279, 'Navy Blue', '279', 'assets/yarn_colors/alize_cotton-gold_279.webp'],
                [200287, 'Turquoise Blue', '287', 'assets/yarn_colors/alize_cotton-gold_287.webp'],
            ],
            'Puffy' => [
                [300055, 'Snow White', '55', 'assets/yarn_colors/alize_puffy_55.webp'],
                [300062, 'Vanilla Cream', '62', 'assets/yarn_colors/alize_puffy_62.webp'],
                [300310, 'Peach Cream', '310', 'assets/yarn_colors/alize_puffy_310.webp'],
                [300340, 'Soft Pink', '340', 'assets/yarn_colors/alize_puffy_340.webp'],
                [300428, 'Silver Grey', '428', 'assets/yarn_colors/alize_puffy_428.webp'],
                [300599, 'Warm Oatmeal', '599', 'assets/yarn_colors/alize_puffy_599.webp'],
                [355865, 'Sky Blue Mix', '5865', 'assets/yarn_colors/alize_puffy-color_5865.webp'],
                [355923, 'Lavender Pink Mix', '5923', 'assets/yarn_colors/alize_puffy-color_5923.webp'],
                [356395, 'Stone Beige Mix', '6395', 'assets/yarn_colors/alize_puffy-color_6395.webp'],
                [356408, 'Mint Grey Mix', '6408', 'assets/yarn_colors/alize_puffy-color_6408.webp'],
            ],
            'Velvet' => [
                [400013, 'Lemon Cream', '13', 'assets/yarn_colors/alize_velluto_13.webp'],
                [400055, 'Snow White', '55', 'assets/yarn_colors/alize_velluto_55.webp'],
                [400199, 'Camel Brown', '199', 'assets/yarn_colors/alize_velluto_199.webp'],
                [400218, 'Baby Blue', '218', 'assets/yarn_colors/alize_velluto_218.webp'],
                [400310, 'Peach Cream', '310', 'assets/yarn_colors/alize_velluto_310.webp'],
                [400329, 'Mocha Brown', '329', 'assets/yarn_colors/alize_velluto_329.webp'],
                [400340, 'Blush Pink', '340', 'assets/yarn_colors/alize_velluto_340.webp'],
                [400374, 'Denim Blue', '374', 'assets/yarn_colors/alize_velluto_374.webp'],
                [400416, 'Ice Grey', '416', 'assets/yarn_colors/alize_velluto_416.webp'],
                [400428, 'Silver Grey', '428', 'assets/yarn_colors/alize_velluto_428.webp'],
            ],
        ];
    }
}

if (!function_exists('app_product_options_seed_default_yarn_colours')) {
    function app_product_options_seed_default_yarn_colours(mysqli $conn): void
    {
        if (!app_product_options_table_exists($conn, 'colors')
            || !app_product_options_table_exists($conn, 'yarn_types')
            || !app_product_options_table_exists($conn, 'color_yarn_types')
        ) {
            return;
        }

        $existingLinks = mysqli_query($conn, "SELECT COUNT(*) AS total FROM color_yarn_types");
        $existingRow = $existingLinks ? mysqli_fetch_assoc($existingLinks) : null;
        if ((int)($existingRow['total'] ?? 0) > 0) {
            return;
        }

        $typeStmt = mysqli_prepare(
            $conn,
            "INSERT INTO yarn_types (typeName)
             VALUES (?)
             ON DUPLICATE KEY UPDATE typeID = LAST_INSERT_ID(typeID)"
        );
        $typeLookupStmt = mysqli_prepare($conn, "SELECT typeID FROM yarn_types WHERE typeName = ? LIMIT 1");
        $colorStmt = mysqli_prepare(
            $conn,
            "INSERT INTO colors (colorID, colorName, displayCode, hexCode, globalInventoryAvailable, isActive)
             VALUES (?, ?, ?, '#ece6f6', 20, 1)
             ON DUPLICATE KEY UPDATE
                colorName = VALUES(colorName),
                displayCode = CASE
                    WHEN displayCode IS NULL OR TRIM(displayCode) = ''
                    THEN VALUES(displayCode)
                    ELSE displayCode
                END,
                hexCode = CASE
                    WHEN hexCode IS NULL OR TRIM(hexCode) = ''
                    THEN VALUES(hexCode)
                    ELSE hexCode
                END"
        );
        $linkStmt = mysqli_prepare(
            $conn,
            "INSERT INTO color_yarn_types (colorID, typeID, photoPath)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                photoPath = CASE
                    WHEN photoPath IS NULL OR TRIM(photoPath) = ''
                    THEN VALUES(photoPath)
                    ELSE photoPath
                END"
        );

        if (!$typeStmt || !$typeLookupStmt || !$colorStmt || !$linkStmt) {
            return;
        }

        foreach (app_product_options_default_yarn_colour_catalogue() as $typeName => $colours) {
            mysqli_stmt_bind_param($typeStmt, 's', $typeName);
            mysqli_stmt_execute($typeStmt);
            $typeID = (int)mysqli_insert_id($conn);

            if ($typeID <= 0) {
                mysqli_stmt_bind_param($typeLookupStmt, 's', $typeName);
                mysqli_stmt_execute($typeLookupStmt);
                $lookupRes = mysqli_stmt_get_result($typeLookupStmt);
                $lookupRow = $lookupRes ? mysqli_fetch_assoc($lookupRes) : null;
                $typeID = (int)($lookupRow['typeID'] ?? 0);
            }

            if ($typeID <= 0) {
                continue;
            }

            foreach ($colours as $colour) {
                [$colorID, $baseName, $displayCode, $photoPath] = $colour;
                $colorID = (int)$colorID;
                $displayCode = (string)$displayCode;
                $colorName = trim((string)$baseName . ' ' . $displayCode);

                mysqli_stmt_bind_param($colorStmt, 'iss', $colorID, $colorName, $displayCode);
                mysqli_stmt_execute($colorStmt);

                mysqli_stmt_bind_param($linkStmt, 'iis', $colorID, $typeID, $photoPath);
                mysqli_stmt_execute($linkStmt);
            }
        }

        mysqli_stmt_close($typeStmt);
        mysqli_stmt_close($typeLookupStmt);
        mysqli_stmt_close($colorStmt);
        mysqli_stmt_close($linkStmt);
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

        app_product_options_ensure_colour_inventory_tables($conn);

        if (app_product_options_table_exists($conn, 'products')) {
            $productColumns = [
                'materialType' => "ALTER TABLE products ADD COLUMN materialType VARCHAR(100) NULL AFTER category",
                'yarnTypeID' => "ALTER TABLE products ADD COLUMN yarnTypeID INT NULL AFTER materialType",
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

            if (app_product_options_column_exists($conn, 'products', 'yarnTypeID')
                && !app_product_options_index_exists($conn, 'products', 'idx_products_yarnTypeID')
            ) {
                app_product_options_add_index_if_missing(
                    $conn,
                    'products',
                    'idx_products_yarnTypeID',
                    "ALTER TABLE products ADD KEY idx_products_yarnTypeID (yarnTypeID)"
                );
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

            app_product_options_seed_default_yarn_colours($conn);

            if (app_product_options_table_exists($conn, 'products')
                && app_product_options_column_exists($conn, 'products', 'yarnTypeID')
            ) {
                mysqli_query(
                    $conn,
                    "UPDATE products p
                     JOIN yarn_types yt
                       ON LOWER(TRIM(yt.typeName)) = LOWER(TRIM(COALESCE(p.materialType, '')))
                     SET p.yarnTypeID = yt.typeID
                     WHERE p.yarnTypeID IS NULL OR p.yarnTypeID <= 0"
                );

                $velvetRes = mysqli_query($conn, "SELECT typeID FROM yarn_types WHERE typeName = 'Velvet' LIMIT 1");
                $velvetRow = $velvetRes ? mysqli_fetch_assoc($velvetRes) : null;
                $fallbackTypeID = (int)($velvetRow['typeID'] ?? 0);
                if ($fallbackTypeID <= 0) {
                    $fallbackRes = mysqli_query($conn, "SELECT typeID FROM yarn_types ORDER BY typeName ASC LIMIT 1");
                    $fallbackRow = $fallbackRes ? mysqli_fetch_assoc($fallbackRes) : null;
                    $fallbackTypeID = (int)($fallbackRow['typeID'] ?? 0);
                }
                if ($fallbackTypeID > 0) {
                    mysqli_query(
                        $conn,
                        "UPDATE products
                         SET yarnTypeID = {$fallbackTypeID}
                         WHERE (yarnTypeID IS NULL OR yarnTypeID <= 0)
                           AND cartStatus <> 'discontinued'"
                    );
                }

                mysqli_query(
                    $conn,
                    "UPDATE products p
                     JOIN yarn_types yt ON yt.typeID = p.yarnTypeID
                     SET p.materialType = yt.typeName
                     WHERE p.yarnTypeID IS NOT NULL
                       AND p.yarnTypeID > 0
                       AND (p.materialType IS NULL OR TRIM(p.materialType) = '')"
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

        app_product_options_sync_colour_inventory_refs($conn);
    }
}

if (!function_exists('app_product_is_private_made_to_order_row')) {
    function app_product_is_private_made_to_order_row(array $row): bool
    {
        $status = strtolower(trim((string)($row['cartStatus'] ?? '')));
        if ($status !== 'made_to_order') {
            return false;
        }

        return trim((string)($row['privateCustomerEmail'] ?? '')) !== ''
            && trim((string)($row['privateAccessToken'] ?? '')) !== '';
    }
}

if (!function_exists('app_product_is_stockless_made_to_order_row')) {
    function app_product_is_stockless_made_to_order_row(array $row): bool
    {
        $status = strtolower(trim((string)($row['cartStatus'] ?? '')));
        return in_array($status, ['active', 'low_stock', 'out_of_stock', 'made_to_order'], true);
    }
}

if (!function_exists('app_product_public_status_label')) {
    function app_product_public_status_label(array $row): string
    {
        return app_product_is_stockless_made_to_order_row($row) ? 'Made to Order' : 'Hidden';
    }
}

if (!function_exists('app_yarn_types_all')) {
    function app_yarn_types_all(mysqli $conn): array
    {
        app_product_options_ensure_schema($conn);
        if (!app_product_options_table_exists($conn, 'yarn_types')) {
            return [];
        }

        $rows = [];
        $res = mysqli_query($conn, "SELECT typeID, typeName FROM yarn_types ORDER BY typeName ASC");
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $typeID = (int)($row['typeID'] ?? 0);
            $typeName = trim((string)($row['typeName'] ?? ''));
            if ($typeID <= 0 || $typeName === '') {
                continue;
            }
            $rows[] = ['typeID' => $typeID, 'typeName' => $typeName];
        }
        return $rows;
    }
}

if (!function_exists('app_product_yarn_type_id')) {
    function app_product_yarn_type_id(mysqli $conn, int $productId): int
    {
        app_product_options_ensure_schema($conn);
        if ($productId <= 0 || !app_product_options_table_exists($conn, 'products')) {
            return 0;
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT COALESCE(p.yarnTypeID, yt.typeID, 0) AS typeID
             FROM products p
             LEFT JOIN yarn_types yt ON LOWER(TRIM(yt.typeName)) = LOWER(TRIM(COALESCE(p.materialType, '')))
             WHERE p.productID = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return 0;
        }
        mysqli_stmt_bind_param($stmt, 'i', $productId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        return (int)($row['typeID'] ?? 0);
    }
}

if (!function_exists('app_product_colours_for_product')) {
    function app_product_colours_for_product(mysqli $conn, int $productId, bool $availableOnly = false): array
    {
        app_product_options_ensure_schema($conn);
        if ($productId <= 0) {
            return [];
        }

        $colorDisplaySql = app_color_display_sql('c');
        $availabilitySql = $availableOnly ? " AND c.isActive = 1" : "";
        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                c.colorID,
                {$colorDisplaySql} AS colorName,
                c.displayCode,
                c.hexCode,
                c.globalInventoryAvailable,
                c.isActive,
                yt.typeID,
                yt.typeName,
                MIN(cyt.photoPath) AS yarnPhotoPath,
                MIN(pcp.photoPath) AS productPhotoPath,
                COALESCE(NULLIF(MIN(pcp.photoPath), ''), NULLIF(MIN(cyt.photoPath), '')) AS photoPath
             FROM products p
             LEFT JOIN yarn_types fallback_yt
               ON LOWER(TRIM(fallback_yt.typeName)) = LOWER(TRIM(COALESCE(p.materialType, '')))
             JOIN color_yarn_types cyt
               ON cyt.typeID = COALESCE(NULLIF(p.yarnTypeID, 0), fallback_yt.typeID)
             JOIN yarn_types yt ON yt.typeID = cyt.typeID
             JOIN colors c ON c.colorID = cyt.colorID
             LEFT JOIN product_color_photos pcp
               ON pcp.productID = p.productID AND pcp.colorID = c.colorID
             WHERE p.productID = ?
               {$availabilitySql}
             GROUP BY c.colorID, c.colorName, c.displayCode, c.hexCode, c.globalInventoryAvailable, c.isActive, yt.typeID, yt.typeName
             ORDER BY colorName ASC, c.colorID ASC"
        );
        if (!$stmt) {
            return [];
        }

        mysqli_stmt_bind_param($stmt, 'i', $productId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rows = [];
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $hex = trim((string)($row['hexCode'] ?? ''));
            $photoPathRaw = (string)($row['photoPath'] ?? '');
            $productPhotoPathRaw = (string)($row['productPhotoPath'] ?? '');
            $yarnPhotoPathRaw = (string)($row['yarnPhotoPath'] ?? '');
            $photoPath = function_exists('app_image_prefer_optimized_asset_path') ? app_image_prefer_optimized_asset_path($photoPathRaw) : trim($photoPathRaw);
            $productPhotoPath = function_exists('app_image_prefer_optimized_asset_path') ? app_image_prefer_optimized_asset_path($productPhotoPathRaw) : trim($productPhotoPathRaw);
            $yarnPhotoPath = function_exists('app_image_prefer_optimized_asset_path') ? app_image_prefer_optimized_asset_path($yarnPhotoPathRaw) : trim($yarnPhotoPathRaw);
            $rows[] = [
                'id' => (int)$row['colorID'],
                'colorID' => (int)$row['colorID'],
                'name' => (string)($row['colorName'] ?? ''),
                'colorName' => (string)($row['colorName'] ?? ''),
                'displayCode' => (string)($row['displayCode'] ?? ''),
                'hex' => preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? $hex : '#ece6f6',
                'hexCode' => preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? $hex : '#ece6f6',
                'stock' => (int)($row['globalInventoryAvailable'] ?? 0),
                'globalInventoryAvailable' => (int)($row['globalInventoryAvailable'] ?? 0),
                'isActive' => (int)($row['isActive'] ?? 0),
                'available' => ((int)($row['isActive'] ?? 0) === 1) ? 1 : 0,
                'typeId' => (int)($row['typeID'] ?? 0),
                'typeID' => (int)($row['typeID'] ?? 0),
                'typeName' => (string)($row['typeName'] ?? ''),
                'photoPath' => $photoPath,
                'productPhotoPath' => $productPhotoPath,
                'yarnPhotoPath' => $yarnPhotoPath,
            ];
        }
        mysqli_stmt_close($stmt);
        return $rows;
    }
}

if (!function_exists('app_product_colour_count')) {
    function app_product_colour_count(mysqli $conn, int $productId, bool $availableOnly = false): int
    {
        return count(app_product_colours_for_product($conn, $productId, $availableOnly));
    }
}

if (!function_exists('app_product_colour_is_available')) {
    function app_product_colour_is_available(mysqli $conn, int $productId, int $colorId): bool
    {
        if ($productId <= 0 || $colorId <= 0) {
            return false;
        }
        foreach (app_product_colours_for_product($conn, $productId, true) as $row) {
            if ((int)($row['colorID'] ?? 0) === $colorId) {
                return true;
            }
        }
        return false;
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
