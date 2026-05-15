<?php

if (!function_exists('payment_gateway_has_system_config')) {
    function payment_gateway_has_system_config(mysqli $conn): bool
    {
        static $checked = null;
        if ($checked !== null) {
            return $checked;
        }

        $result = $conn->query("SHOW TABLES LIKE 'system_config'");
        $checked = (bool)($result && $result->num_rows > 0);
        return $checked;
    }
}

if (!function_exists('payment_gateway_system_config_value')) {
    function payment_gateway_system_config_value(mysqli $conn, string $key, string $default = ''): string
    {
        if (!payment_gateway_has_system_config($conn)) {
            return $default;
        }

        $stmt = $conn->prepare("SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1");
        if (!$stmt) {
            return $default;
        }

        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->bind_result($value);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found || $value === null) {
            return $default;
        }

        return trim((string)$value);
    }
}

if (!function_exists('payment_gateway_setting')) {
    function payment_gateway_setting(mysqli $conn, string $envKey, string $configKey, string $default = ''): string
    {
        $missingValue = '__athina_missing_gateway_setting__';
        $value = payment_gateway_system_config_value($conn, $configKey, $missingValue);
        if ($value !== $missingValue && !payment_gateway_is_placeholder_value($value)) {
            return $value;
        }

        $envValue = getenv($envKey);
        if (is_string($envValue) && trim($envValue) !== '') {
            $trimmedEnv = trim($envValue);
            if (!payment_gateway_is_placeholder_value($trimmedEnv)) {
                return $trimmedEnv;
            }
        }

        return $default;
    }
}

if (!function_exists('payment_gateway_is_placeholder_value')) {
    function payment_gateway_is_placeholder_value(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return true;
        }

        $placeholders = [
            'sk_live_or_test_here',
            'your_paypal_client_id',
            'your_paypal_client_secret',
            'your_paypal_webhook_id',
            'your_stripe_secret_key',
            'your_stripe_webhook_secret',
            'changeme',
            'replace_me',
            'replace-with-real-value',
        ];

        return in_array($normalized, $placeholders, true);
    }
}

if (!function_exists('payment_gateway_project_path')) {
    function payment_gateway_project_path(): string
    {
        $project = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        if ($project === '' || $project === '.') {
            return '';
        }

        return $project;
    }
}

if (!function_exists('payment_gateway_absolute_url')) {
    function payment_gateway_absolute_url(string $path): string
    {
        $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwardedProto !== '') {
            $scheme = explode(',', $forwardedProto)[0];
        } else {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        }

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $path = '/' . ltrim($path, '/');

        return $scheme . '://' . $host . $path;
    }
}

if (!function_exists('payment_gateway_encode_cents')) {
    function payment_gateway_encode_cents(float $amount): int
    {
        return max(0, (int)round($amount * 100));
    }
}

if (!function_exists('payment_gateway_format_amount')) {
    function payment_gateway_format_amount(float $amount): string
    {
        return number_format(max(0, $amount), 2, '.', '');
    }
}

if (!function_exists('payment_gateway_http_request')) {
    function payment_gateway_http_request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is not available on this PHP installation.');
        }

        $method = strtoupper(trim($method));
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Payment gateway request failed: ' . $error);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerText = substr($raw, 0, $headerSize);
        $bodyText = substr($raw, $headerSize);
        $json = json_decode($bodyText, true);

        return [
            'status' => $status,
            'headers_raw' => $headerText,
            'body_raw' => $bodyText,
            'json' => is_array($json) ? $json : null,
        ];
    }
}

if (!function_exists('payment_gateway_json_encode')) {
    function payment_gateway_json_encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '{}';
    }
}

if (!function_exists('payment_gateway_server_header')) {
    function payment_gateway_server_header(array $server, string $headerName): string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        return trim((string)($server[$serverKey] ?? ''));
    }
}

if (!function_exists('payment_gateway_available_methods')) {
    function payment_gateway_available_methods(mysqli $conn): array
    {
        $stripeConfig = payment_gateway_stripe_config($conn);
        $paypalClientId = payment_gateway_setting($conn, 'PAYPAL_CLIENT_ID', 'paypal_client_id', '');
        $paypalClientSecret = payment_gateway_setting($conn, 'PAYPAL_CLIENT_SECRET', 'paypal_client_secret', '');

        return [
            'stripe' => trim((string)($stripeConfig['secret_key'] ?? '')) !== '',
            'paypal' => $paypalClientId !== '' && $paypalClientSecret !== '',
        ];
    }
}

if (!function_exists('payment_gateway_stripe_config')) {
    function payment_gateway_stripe_config(mysqli $conn): array
    {
        $mode = strtolower(payment_gateway_setting($conn, 'STRIPE_MODE', 'stripe_mode', 'live'));
        if (!in_array($mode, ['live', 'sandbox'], true)) {
            $mode = 'live';
        }

        return [
            'publishable_key' => payment_gateway_setting($conn, 'STRIPE_PUBLISHABLE_KEY', 'stripe_publishable_key', ''),
            'secret_key' => payment_gateway_setting($conn, 'STRIPE_SECRET_KEY', 'stripe_secret_key', ''),
            'webhook_secret' => payment_gateway_setting($conn, 'STRIPE_WEBHOOK_SECRET', 'stripe_webhook_secret', ''),
            'mode' => $mode,
        ];
    }
}

if (!function_exists('payment_gateway_assert_stripe_mode_matches_keys')) {
    function payment_gateway_assert_stripe_mode_matches_keys(array $config): void
    {
        $mode = strtolower(trim((string)($config['mode'] ?? 'live')));
        if (!in_array($mode, ['live', 'sandbox'], true)) {
            $mode = 'live';
        }

        $expectedKeyFlavor = $mode === 'sandbox' ? 'test' : 'live';
        $modeLabel = $mode === 'sandbox' ? 'sandbox' : 'live';
        $oppositeModeLabel = $mode === 'sandbox' ? 'live' : 'sandbox';

        $secretKey = trim((string)($config['secret_key'] ?? ''));
        if ($secretKey === '') {
            return;
        }

        if (preg_match('/^sk_(test|live)_/', $secretKey, $matches) === 1) {
            $actualFlavor = strtolower((string)($matches[1] ?? ''));
            if ($actualFlavor !== $expectedKeyFlavor) {
                throw new RuntimeException(
                    'Stripe mode is set to ' . $modeLabel . ', but the saved secret key looks like a '
                    . $oppositeModeLabel . ' key. Switch Stripe mode or update the saved secret key.'
                );
            }
        }
    }
}

if (!function_exists('payment_gateway_stripe_webhook_secret')) {
    function payment_gateway_stripe_webhook_secret(mysqli $conn): string
    {
        $config = payment_gateway_stripe_config($conn);
        return trim((string)($config['webhook_secret'] ?? ''));
    }
}

if (!function_exists('payment_gateway_stripe_webhook_is_configured')) {
    function payment_gateway_stripe_webhook_is_configured(mysqli $conn): bool
    {
        return payment_gateway_stripe_webhook_secret($conn) !== '';
    }
}

if (!function_exists('payment_gateway_paypal_webhook_is_configured')) {
    function payment_gateway_paypal_webhook_is_configured(mysqli $conn): bool
    {
        $config = payment_gateway_paypal_config($conn);
        return trim((string)($config['webhook_id'] ?? '')) !== '';
    }
}

if (!function_exists('payment_gateway_create_stripe_checkout_session')) {
    function payment_gateway_create_stripe_checkout_session(mysqli $conn, array $payload): array
    {
        $stripeConfig = payment_gateway_stripe_config($conn);
        $secretKey = trim((string)($stripeConfig['secret_key'] ?? ''));
        if ($secretKey === '') {
            throw new RuntimeException('Stripe is not configured yet. Add your Stripe secret key first.');
        }
        payment_gateway_assert_stripe_mode_matches_keys($stripeConfig);

        $checkoutToken = trim((string)($payload['checkout_token'] ?? ''));
        if ($checkoutToken === '') {
            throw new RuntimeException('Missing checkout token for Stripe payment.');
        }

        $project = payment_gateway_project_path();
        $successUrl = payment_gateway_absolute_url(
            $project . '/modules/payment_finalize.php?provider=stripe&checkout_token=' . rawurlencode($checkoutToken) . '&session_id={CHECKOUT_SESSION_ID}'
        );
        $cancelUrl = payment_gateway_absolute_url(
            $project . '/modules/checkout_failed.php?provider=stripe&checkout_token=' . rawurlencode($checkoutToken) . '&reason=cancelled'
        );

        $lineItemName = trim((string)($payload['line_item_name'] ?? 'Creations by Athina Order'));
        $lineItemDescription = trim((string)($payload['line_item_description'] ?? 'Secure checkout payment'));
        $totalAmount = (float)($payload['total_amount'] ?? 0);
        $customerEmail = trim((string)($payload['customer_email'] ?? ''));

        $form = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $checkoutToken,
            'payment_method_types[0]' => 'card',
            'line_items[0][price_data][currency]' => 'eur',
            'line_items[0][price_data][unit_amount]' => payment_gateway_encode_cents($totalAmount),
            'line_items[0][price_data][product_data][name]' => $lineItemName,
            'line_items[0][price_data][product_data][description]' => $lineItemDescription,
            'line_items[0][quantity]' => 1,
            'metadata[checkout_token]' => $checkoutToken,
        ];

        if ($customerEmail !== '') {
            $form['customer_email'] = $customerEmail;
        }

        $response = payment_gateway_http_request(
            'POST',
            'https://api.stripe.com/v1/checkout/sessions',
            [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            http_build_query($form, '', '&')
        );

        $json = $response['json'] ?? [];
        if ($response['status'] < 200 || $response['status'] >= 300 || empty($json['id']) || empty($json['url'])) {
            $message = trim((string)($json['error']['message'] ?? $response['body_raw'] ?? 'Could not start Stripe checkout.'));
            throw new RuntimeException($message !== '' ? $message : 'Could not start Stripe checkout.');
        }

        return [
            'gateway_reference' => (string)$json['id'],
            'redirect_url' => (string)$json['url'],
        ];
    }
}

if (!function_exists('payment_gateway_fetch_stripe_checkout_session')) {
    function payment_gateway_fetch_stripe_checkout_session(mysqli $conn, string $sessionId): array
    {
        $stripeConfig = payment_gateway_stripe_config($conn);
        $secretKey = trim((string)($stripeConfig['secret_key'] ?? ''));
        if ($secretKey === '') {
            throw new RuntimeException('Stripe is not configured yet. Add your Stripe secret key first.');
        }
        payment_gateway_assert_stripe_mode_matches_keys($stripeConfig);

        $response = payment_gateway_http_request(
            'GET',
            'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId),
            [
                'Authorization: Bearer ' . $secretKey,
            ]
        );

        $json = $response['json'] ?? [];
        if ($response['status'] < 200 || $response['status'] >= 300 || empty($json['id'])) {
            $message = trim((string)($json['error']['message'] ?? $response['body_raw'] ?? 'Could not verify Stripe checkout.'));
            throw new RuntimeException($message !== '' ? $message : 'Could not verify Stripe checkout.');
        }

        return $json;
    }
}

if (!function_exists('payment_gateway_paypal_config')) {
    function payment_gateway_paypal_config(mysqli $conn): array
    {
        $mode = strtolower(payment_gateway_setting($conn, 'PAYPAL_MODE', 'paypal_mode', 'live'));
        if (!in_array($mode, ['live', 'sandbox'], true)) {
            $mode = 'live';
        }

        return [
            'client_id' => payment_gateway_setting($conn, 'PAYPAL_CLIENT_ID', 'paypal_client_id', ''),
            'client_secret' => payment_gateway_setting($conn, 'PAYPAL_CLIENT_SECRET', 'paypal_client_secret', ''),
            'webhook_id' => payment_gateway_setting($conn, 'PAYPAL_WEBHOOK_ID', 'paypal_webhook_id', ''),
            'mode' => $mode,
            'base_url' => $mode === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com',
        ];
    }
}

if (!function_exists('payment_gateway_fetch_paypal_access_token')) {
    function payment_gateway_fetch_paypal_access_token(mysqli $conn): string
    {
        $config = payment_gateway_paypal_config($conn);
        if ($config['client_id'] === '' || $config['client_secret'] === '') {
            throw new RuntimeException('PayPal is not configured yet. Add your PayPal client ID and secret first.');
        }

        $basicAuth = base64_encode($config['client_id'] . ':' . $config['client_secret']);
        $response = payment_gateway_http_request(
            'POST',
            $config['base_url'] . '/v1/oauth2/token',
            [
                'Authorization: Basic ' . $basicAuth,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            'grant_type=client_credentials'
        );

        $json = $response['json'] ?? [];
        $token = trim((string)($json['access_token'] ?? ''));
        if ($response['status'] < 200 || $response['status'] >= 300 || $token === '') {
            $message = trim((string)($json['error_description'] ?? $json['error'] ?? $response['body_raw'] ?? 'Could not authenticate with PayPal.'));
            throw new RuntimeException($message !== '' ? $message : 'Could not authenticate with PayPal.');
        }

        return $token;
    }
}

if (!function_exists('payment_gateway_create_paypal_order')) {
    function payment_gateway_create_paypal_order(mysqli $conn, array $payload): array
    {
        $config = payment_gateway_paypal_config($conn);
        $accessToken = payment_gateway_fetch_paypal_access_token($conn);

        $checkoutToken = trim((string)($payload['checkout_token'] ?? ''));
        if ($checkoutToken === '') {
            throw new RuntimeException('Missing checkout token for PayPal payment.');
        }

        $project = payment_gateway_project_path();
        $returnUrl = payment_gateway_absolute_url(
            $project . '/modules/payment_finalize.php?provider=paypal&checkout_token=' . rawurlencode($checkoutToken)
        );
        $cancelUrl = payment_gateway_absolute_url(
            $project . '/modules/checkout_failed.php?provider=paypal&checkout_token=' . rawurlencode($checkoutToken) . '&reason=cancelled'
        );

        $lineItemName = trim((string)($payload['line_item_name'] ?? 'Creations by Athina Order'));
        $totalAmount = payment_gateway_format_amount((float)($payload['total_amount'] ?? 0));
        $siteTitle = trim((string)($payload['site_title'] ?? 'Creations by Athina'));
        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $checkoutToken,
                'custom_id' => $checkoutToken,
                'description' => $lineItemName,
                'amount' => [
                    'currency_code' => 'EUR',
                    'value' => $totalAmount,
                ],
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'brand_name' => $siteTitle,
                        'user_action' => 'PAY_NOW',
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl,
                    ],
                ],
            ],
        ];

        $response = payment_gateway_http_request(
            'POST',
            $config['base_url'] . '/v2/checkout/orders',
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            json_encode($body, JSON_UNESCAPED_SLASHES)
        );

        $json = $response['json'] ?? [];
        $redirectUrl = '';
        foreach (($json['links'] ?? []) as $link) {
            $rel = trim((string)($link['rel'] ?? ''));
            if ($rel === 'payer-action' || $rel === 'approve') {
                $redirectUrl = trim((string)($link['href'] ?? ''));
                break;
            }
        }

        if ($response['status'] < 200 || $response['status'] >= 300 || empty($json['id']) || $redirectUrl === '') {
            $message = trim((string)($json['message'] ?? $json['details'][0]['description'] ?? $response['body_raw'] ?? 'Could not start PayPal checkout.'));
            throw new RuntimeException($message !== '' ? $message : 'Could not start PayPal checkout.');
        }

        return [
            'gateway_reference' => (string)$json['id'],
            'redirect_url' => $redirectUrl,
        ];
    }
}

if (!function_exists('payment_gateway_capture_paypal_order')) {
    function payment_gateway_capture_paypal_order(mysqli $conn, string $orderId): array
    {
        $config = payment_gateway_paypal_config($conn);
        $accessToken = payment_gateway_fetch_paypal_access_token($conn);

        $response = payment_gateway_http_request(
            'POST',
            $config['base_url'] . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture',
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
                'Prefer: return=representation',
            ],
            '{}'
        );

        $json = $response['json'] ?? [];
        if ($response['status'] < 200 || $response['status'] >= 300 || empty($json['id'])) {
            $message = trim((string)($json['message'] ?? $json['details'][0]['description'] ?? $response['body_raw'] ?? 'Could not capture PayPal payment.'));
            throw new RuntimeException($message !== '' ? $message : 'Could not capture PayPal payment.');
        }

        return $json;
    }
}

if (!function_exists('payment_gateway_verify_stripe_webhook_event')) {
    function payment_gateway_verify_stripe_webhook_event(mysqli $conn, string $payload, string $signatureHeader): array
    {
        $secret = payment_gateway_stripe_webhook_secret($conn);
        if ($secret === '') {
            throw new RuntimeException('Stripe webhook secret has not been configured.');
        }

        $timestamp = 0;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = (int)$value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp <= 0 || $signatures === []) {
            throw new RuntimeException('Stripe webhook signature header is invalid.');
        }

        if (abs(time() - $timestamp) > 300) {
            throw new RuntimeException('Stripe webhook timestamp is outside the accepted window.');
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);
        $matched = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expectedSignature, $signature)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            throw new RuntimeException('Stripe webhook signature verification failed.');
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new RuntimeException('Stripe webhook payload is not valid JSON.');
        }

        return $event;
    }
}

if (!function_exists('payment_gateway_verify_paypal_webhook_event')) {
    function payment_gateway_verify_paypal_webhook_event(mysqli $conn, array $server, string $payload): array
    {
        $config = payment_gateway_paypal_config($conn);
        $webhookId = trim((string)($config['webhook_id'] ?? ''));
        if ($webhookId === '') {
            throw new RuntimeException('PayPal webhook ID has not been configured.');
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new RuntimeException('PayPal webhook payload is not valid JSON.');
        }

        $headers = [
            'transmission_id' => payment_gateway_server_header($server, 'PAYPAL-TRANSMISSION-ID'),
            'transmission_time' => payment_gateway_server_header($server, 'PAYPAL-TRANSMISSION-TIME'),
            'transmission_sig' => payment_gateway_server_header($server, 'PAYPAL-TRANSMISSION-SIG'),
            'cert_url' => payment_gateway_server_header($server, 'PAYPAL-CERT-URL'),
            'auth_algo' => payment_gateway_server_header($server, 'PAYPAL-AUTH-ALGO'),
        ];

        foreach ($headers as $value) {
            if ($value === '') {
                throw new RuntimeException('PayPal webhook verification headers are missing.');
            }
        }

        $accessToken = payment_gateway_fetch_paypal_access_token($conn);
        $response = payment_gateway_http_request(
            'POST',
            $config['base_url'] . '/v1/notifications/verify-webhook-signature',
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            payment_gateway_json_encode([
                'transmission_id' => $headers['transmission_id'],
                'transmission_time' => $headers['transmission_time'],
                'cert_url' => $headers['cert_url'],
                'auth_algo' => $headers['auth_algo'],
                'transmission_sig' => $headers['transmission_sig'],
                'webhook_id' => $webhookId,
                'webhook_event' => $event,
            ])
        );

        $json = $response['json'] ?? [];
        $verificationStatus = strtoupper(trim((string)($json['verification_status'] ?? '')));
        if ($response['status'] < 200 || $response['status'] >= 300 || $verificationStatus !== 'SUCCESS') {
            $message = trim((string)($json['message'] ?? $json['verification_status'] ?? $response['body_raw'] ?? 'PayPal webhook verification failed.'));
            throw new RuntimeException($message !== '' ? $message : 'PayPal webhook verification failed.');
        }

        return $event;
    }
}
