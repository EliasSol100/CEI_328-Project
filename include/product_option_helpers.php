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
                'customColorFields' => "ALTER TABLE products ADD COLUMN customColorFields TINYINT(1) NOT NULL DEFAULT 0 AFTER category",
                'customColorLabel1' => "ALTER TABLE products ADD COLUMN customColorLabel1 VARCHAR(120) NULL AFTER customColorFields",
                'customColorLabel2' => "ALTER TABLE products ADD COLUMN customColorLabel2 VARCHAR(120) NULL AFTER customColorLabel1",
                'customColorLabel1GR' => "ALTER TABLE products ADD COLUMN customColorLabel1GR VARCHAR(120) NULL AFTER customColorLabel2",
                'customColorLabel2GR' => "ALTER TABLE products ADD COLUMN customColorLabel2GR VARCHAR(120) NULL AFTER customColorLabel1GR",
                'customColorHelpText' => "ALTER TABLE products ADD COLUMN customColorHelpText VARCHAR(255) NULL AFTER customColorLabel2GR",
                'customColorHelpTextGR' => "ALTER TABLE products ADD COLUMN customColorHelpTextGR VARCHAR(255) NULL AFTER customColorHelpText",
            ];

            foreach ($productColumns as $columnName => $sql) {
                if (!app_product_options_column_exists($conn, 'products', $columnName)) {
                    mysqli_query($conn, $sql);
                }
            }
        }

        if (app_product_options_table_exists($conn, 'product_variations')
            && !app_product_options_column_exists($conn, 'product_variations', 'price')
        ) {
            mysqli_query($conn, "ALTER TABLE product_variations ADD COLUMN price DOUBLE NULL DEFAULT NULL AFTER colorID");
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

        if (app_product_options_table_exists($conn, 'order_items')
            && !app_product_options_column_exists($conn, 'order_items', 'customizationNote')
        ) {
            mysqli_query($conn, "ALTER TABLE order_items ADD COLUMN customizationNote VARCHAR(255) NULL AFTER giftMessage");
        }
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

        // Multi-colour scheme selections
        $csA = app_product_options_pick_color_scheme_value($customization, 'A');
        $csB = app_product_options_pick_color_scheme_value($customization, 'B');
        $csC = app_product_options_pick_color_scheme_value($customization, 'C');
        if ($csA !== '') $parts[] = 'Colour A: ' . $csA;
        if ($csB !== '') $parts[] = 'Colour B: ' . $csB;
        if ($csC !== '') $parts[] = 'Colour C: ' . $csC;

        return implode(' | ', $parts);
    }
}
