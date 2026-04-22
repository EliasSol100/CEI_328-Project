<?php

require_once __DIR__ . '/security.php';

if (!function_exists('app_platform_integrations_default_values')) {
    function app_platform_integrations_default_values(): array
    {
        return [
            'stripe_publishable_key' => '',
            'stripe_secret_key' => 'your_stripe_secret_key',
            'stripe_mode' => 'live',
            'stripe_webhook_secret' => '',
            'paypal_client_id' => 'your_paypal_client_id',
            'paypal_client_secret' => 'your_paypal_client_secret',
            'paypal_webhook_id' => '',
            'paypal_mode' => 'live',
            'google_client_id' => '',
            'google_client_secret' => '',
            'facebook_app_id' => '',
            'facebook_app_secret' => '',
        ];
    }
}

if (!function_exists('app_system_config_ensure_schema')) {
    function app_system_config_ensure_schema(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS system_config (
                config_key VARCHAR(100) NOT NULL PRIMARY KEY,
                config_value TEXT DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $ensured = true;
    }
}

if (!function_exists('app_system_config_seed_defaults')) {
    function app_system_config_seed_defaults(mysqli $conn, array $defaults): void
    {
        static $seeded = [];
        $seedKey = md5(json_encode(array_keys($defaults)));
        if (isset($seeded[$seedKey])) {
            return;
        }

        app_system_config_ensure_schema($conn);

        $existing = [];
        $result = mysqli_query($conn, "SELECT config_key FROM system_config");
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $existing[(string)($row['config_key'] ?? '')] = true;
            }
            mysqli_free_result($result);
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO system_config (config_key, config_value) VALUES (?, ?)"
        );
        if (!$stmt) {
            return;
        }

        foreach ($defaults as $key => $value) {
            if (isset($existing[$key])) {
                continue;
            }

            mysqli_stmt_bind_param($stmt, 'ss', $key, $value);
            mysqli_stmt_execute($stmt);
        }

        mysqli_stmt_close($stmt);
        $seeded[$seedKey] = true;
    }
}

if (!function_exists('app_system_config_get')) {
    function app_system_config_get(mysqli $conn, string $key, string $default = ''): string
    {
        app_system_config_ensure_schema($conn);

        $stmt = mysqli_prepare($conn, "SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1");
        if (!$stmt) {
            return $default;
        }

        mysqli_stmt_bind_param($stmt, 's', $key);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $value);
        $found = mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        if (!$found || $value === null) {
            return $default;
        }

        return trim((string)$value);
    }
}

if (!function_exists('app_system_config_set')) {
    function app_system_config_set(mysqli $conn, string $key, string $value): bool
    {
        app_system_config_ensure_schema($conn);

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

if (!function_exists('app_system_config_set_many')) {
    function app_system_config_set_many(mysqli $conn, array $pairs): bool
    {
        app_system_config_ensure_schema($conn);

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO system_config (config_key, config_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
        );
        if (!$stmt) {
            return false;
        }

        $ok = true;
        foreach ($pairs as $key => $value) {
            $stringKey = (string)$key;
            $stringValue = (string)$value;
            mysqli_stmt_bind_param($stmt, 'ss', $stringKey, $stringValue);
            if (!mysqli_stmt_execute($stmt)) {
                $ok = false;
                break;
            }
        }

        mysqli_stmt_close($stmt);
        return $ok;
    }
}

if (!function_exists('app_integration_placeholder_values')) {
    function app_integration_placeholder_values(): array
    {
        return [
            '',
            'changeme',
            'replace_me',
            'replace-with-real-value',
            'your_paypal_client_id',
            'your_paypal_client_secret',
            'your_stripe_secret_key',
            'your_stripe_publishable_key',
            'your_stripe_webhook_secret',
            'your_paypal_webhook_id',
            'your_google_client_id',
            'your_google_client_secret',
            'your_facebook_app_id',
            'your_facebook_app_secret',
        ];
    }
}

if (!function_exists('app_integration_is_placeholder_value')) {
    function app_integration_is_placeholder_value(string $value): bool
    {
        return in_array(strtolower(trim($value)), app_integration_placeholder_values(), true);
    }
}

if (!function_exists('app_integration_setting')) {
    function app_integration_setting(mysqli $conn, string $envKey, string $configKey, string $default = ''): string
    {
        $envValue = trim((string)app_env_value($envKey, ''));
        if ($envValue !== '' && !app_integration_is_placeholder_value($envValue)) {
            return $envValue;
        }

        $storedValue = app_system_config_get($conn, $configKey, $default);
        return app_integration_is_placeholder_value($storedValue) ? $default : $storedValue;
    }
}

if (!function_exists('app_social_auth_provider_map')) {
    function app_social_auth_provider_map(): array
    {
        return [
            'google' => [
                'label' => 'Google',
                'client_id_env' => 'GOOGLE_CLIENT_ID',
                'client_secret_env' => 'GOOGLE_CLIENT_SECRET',
                'client_id_key' => 'google_client_id',
                'client_secret_key' => 'google_client_secret',
                'user_column' => 'google_id',
            ],
            'facebook' => [
                'label' => 'Facebook',
                'client_id_env' => 'FACEBOOK_APP_ID',
                'client_secret_env' => 'FACEBOOK_APP_SECRET',
                'client_id_key' => 'facebook_app_id',
                'client_secret_key' => 'facebook_app_secret',
                'user_column' => 'facebook_id',
            ],
        ];
    }
}

if (!function_exists('app_social_auth_provider_meta')) {
    function app_social_auth_provider_meta(string $provider): array
    {
        $provider = strtolower(trim($provider));
        $map = app_social_auth_provider_map();
        return $map[$provider] ?? [];
    }
}

if (!function_exists('app_social_auth_user_column')) {
    function app_social_auth_user_column(string $provider): string
    {
        $meta = app_social_auth_provider_meta($provider);
        return (string)($meta['user_column'] ?? '');
    }
}

if (!function_exists('app_social_auth_config')) {
    function app_social_auth_config(mysqli $conn, string $provider): array
    {
        app_system_config_seed_defaults($conn, app_platform_integrations_default_values());

        $meta = app_social_auth_provider_meta($provider);
        if ($meta === []) {
            return [
                'provider' => strtolower(trim($provider)),
                'label' => ucfirst(strtolower(trim($provider))),
                'client_id' => '',
                'client_secret' => '',
                'enabled' => false,
            ];
        }

        $clientId = app_integration_setting($conn, (string)$meta['client_id_env'], (string)$meta['client_id_key'], '');
        $clientSecret = app_integration_setting($conn, (string)$meta['client_secret_env'], (string)$meta['client_secret_key'], '');

        return [
            'provider' => strtolower(trim($provider)),
            'label' => (string)$meta['label'],
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'enabled' => $clientId !== '' && $clientSecret !== '',
        ];
    }
}

if (!function_exists('app_social_auth_enabled')) {
    function app_social_auth_enabled(mysqli $conn, string $provider): bool
    {
        $config = app_social_auth_config($conn, $provider);
        return !empty($config['enabled']);
    }
}

if (!function_exists('app_social_auth_ensure_user_schema')) {
    function app_social_auth_ensure_user_schema(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $columns = [
            'google_id' => "ALTER TABLE users ADD COLUMN google_id VARCHAR(191) DEFAULT NULL AFTER email",
            'facebook_id' => "ALTER TABLE users ADD COLUMN facebook_id VARCHAR(191) DEFAULT NULL AFTER google_id",
        ];

        foreach ($columns as $column => $alterSql) {
            $check = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
            $exists = $check && mysqli_num_rows($check) > 0;
            if ($check) {
                mysqli_free_result($check);
            }

            if (!$exists) {
                mysqli_query($conn, $alterSql);
            }
        }

        $indexes = [
            'uq_users_google_id' => 'google_id',
            'uq_users_facebook_id' => 'facebook_id',
        ];

        foreach ($indexes as $indexName => $columnName) {
            $check = mysqli_query($conn, "SHOW INDEX FROM users WHERE Key_name = '" . mysqli_real_escape_string($conn, $indexName) . "'");
            $exists = $check && mysqli_num_rows($check) > 0;
            if ($check) {
                mysqli_free_result($check);
            }

            if (!$exists) {
                mysqli_query($conn, "ALTER TABLE users ADD UNIQUE KEY {$indexName} ({$columnName})");
            }
        }

        $ensured = true;
    }
}

if (!function_exists('app_social_auth_generate_username')) {
    function app_social_auth_generate_username(mysqli $conn, string $provider, string $providerId): string
    {
        $provider = preg_replace('/[^a-z0-9]/', '', strtolower($provider));
        if ($provider === '') {
            $provider = 'social';
        }

        $seed = preg_replace('/[^a-z0-9]/', '', strtolower($providerId));
        if ($seed === '') {
            $seed = bin2hex(random_bytes(6));
        }

        $base = substr($provider . '_' . $seed, 0, 32);
        if (strlen($base) < 3) {
            $base = substr($provider . '_' . bin2hex(random_bytes(4)), 0, 32);
        }

        $candidate = $base;
        $counter = 1;

        while (true) {
            $stmt = mysqli_prepare($conn, "SELECT userID FROM users WHERE username = ? LIMIT 1");
            if (!$stmt) {
                return $candidate;
            }

            mysqli_stmt_bind_param($stmt, 's', $candidate);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            $exists = mysqli_stmt_num_rows($stmt) > 0;
            mysqli_stmt_close($stmt);

            if (!$exists) {
                return $candidate;
            }

            $suffix = '_' . $counter;
            $candidate = substr($base, 0, max(3, 32 - strlen($suffix))) . $suffix;
            $counter++;
        }
    }
}

if (!function_exists('app_mask_secret_value')) {
    function app_mask_secret_value(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);
        if ($length <= 8) {
            return $length > 0 ? str_repeat('•', $length) : 'Not set';
        }

        return substr($value, 0, 4) . str_repeat('•', max(4, $length - 8)) . substr($value, -4);
    }
}
