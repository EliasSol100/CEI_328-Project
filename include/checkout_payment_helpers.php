<?php

if (!function_exists('checkout_payment_pending_ttl_seconds')) {
    function checkout_payment_pending_ttl_seconds(): int
    {
        return 86400;
    }
}

if (!function_exists('checkout_payment_json_encode')) {
    function checkout_payment_json_encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '{}';
    }
}

if (!function_exists('checkout_payment_json_decode')) {
    function checkout_payment_json_decode(?string $payload): array
    {
        if (!is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('checkout_payment_customer_email')) {
    function checkout_payment_customer_email(array $payload): string
    {
        $userEmail = trim((string)($payload['user_email'] ?? ''));
        if ($userEmail !== '') {
            return $userEmail;
        }

        return trim((string)($payload['guest_email'] ?? ''));
    }
}

if (!function_exists('checkout_payment_ensure_schema')) {
    function checkout_payment_ensure_schema(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $conn->query(
            "CREATE TABLE IF NOT EXISTS pending_checkout_payments (
                checkoutToken VARCHAR(64) NOT NULL,
                paymentProvider VARCHAR(40) NOT NULL,
                paymentStatus VARCHAR(40) NOT NULL DEFAULT 'pending',
                gatewayReference VARCHAR(191) DEFAULT NULL,
                transactionID VARCHAR(191) DEFAULT NULL,
                customerEmail VARCHAR(255) DEFAULT NULL,
                totalAmount DOUBLE NOT NULL DEFAULT 0,
                currency CHAR(3) NOT NULL DEFAULT 'EUR',
                payloadJson MEDIUMTEXT NOT NULL,
                resultJson MEDIUMTEXT DEFAULT NULL,
                orderID INT(11) DEFAULT NULL,
                failureMessage TEXT DEFAULT NULL,
                expiresAt DATETIME DEFAULT NULL,
                createdAt DATETIME NOT NULL DEFAULT current_timestamp(),
                updatedAt DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                finalizedAt DATETIME DEFAULT NULL,
                PRIMARY KEY (checkoutToken),
                KEY idx_pending_checkout_gateway (paymentProvider, gatewayReference),
                KEY idx_pending_checkout_order (orderID),
                KEY idx_pending_checkout_status (paymentStatus),
                KEY idx_pending_checkout_expires (expiresAt)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $ensured = true;
    }
}

if (!function_exists('checkout_payment_fetch_record_by_token')) {
    function checkout_payment_fetch_record_by_token(mysqli $conn, string $checkoutToken, bool $forUpdate = false): ?array
    {
        checkout_payment_ensure_schema($conn);

        $sql = "
            SELECT checkoutToken, paymentProvider, paymentStatus, gatewayReference, transactionID,
                   customerEmail, totalAmount, currency, payloadJson, resultJson, orderID,
                   failureMessage, expiresAt, createdAt, updatedAt, finalizedAt
            FROM pending_checkout_payments
            WHERE checkoutToken = ?
            LIMIT 1
        ";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $checkoutToken);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('checkout_payment_fetch_record_by_gateway_reference')) {
    function checkout_payment_fetch_record_by_gateway_reference(mysqli $conn, string $paymentProvider, string $gatewayReference): ?array
    {
        checkout_payment_ensure_schema($conn);

        $stmt = $conn->prepare("
            SELECT checkoutToken, paymentProvider, paymentStatus, gatewayReference, transactionID,
                   customerEmail, totalAmount, currency, payloadJson, resultJson, orderID,
                   failureMessage, expiresAt, createdAt, updatedAt, finalizedAt
            FROM pending_checkout_payments
            WHERE paymentProvider = ? AND gatewayReference = ?
            ORDER BY updatedAt DESC, createdAt DESC
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('ss', $paymentProvider, $gatewayReference);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('checkout_payment_record_is_expired')) {
    function checkout_payment_record_is_expired(array $record): bool
    {
        $status = strtolower(trim((string)($record['paymentStatus'] ?? 'pending')));
        if ($status === 'finalized') {
            return false;
        }

        $expiresAt = trim((string)($record['expiresAt'] ?? ''));
        if ($expiresAt !== '') {
            $timestamp = strtotime($expiresAt);
            if ($timestamp !== false) {
                return $timestamp < time();
            }
        }

        $createdAt = trim((string)($record['createdAt'] ?? ''));
        if ($createdAt !== '') {
            $timestamp = strtotime($createdAt);
            if ($timestamp !== false) {
                return $timestamp < (time() - checkout_payment_pending_ttl_seconds());
            }
        }

        return false;
    }
}

if (!function_exists('checkout_payment_pending_from_record')) {
    function checkout_payment_pending_from_record(array $record): array
    {
        $pending = checkout_payment_json_decode((string)($record['payloadJson'] ?? ''));
        $pending['checkout_token'] = trim((string)($record['checkoutToken'] ?? ($pending['checkout_token'] ?? '')));
        $pending['payment_method'] = trim((string)($pending['payment_method'] ?? $record['paymentProvider'] ?? ''));
        $pending['gateway_reference'] = trim((string)($record['gatewayReference'] ?? ($pending['gateway_reference'] ?? '')));
        $pending['payment_status'] = trim((string)($record['paymentStatus'] ?? ($pending['payment_status'] ?? 'pending')));
        $pending['transaction_id'] = trim((string)($record['transactionID'] ?? ($pending['transaction_id'] ?? '')));
        $pending['customer_email'] = trim((string)($record['customerEmail'] ?? checkout_payment_customer_email($pending)));
        $pending['total_amount'] = (float)($pending['total_amount'] ?? $record['totalAmount'] ?? 0);
        $pending['expires_at'] = trim((string)($record['expiresAt'] ?? ''));
        $pending['order_id'] = (int)($record['orderID'] ?? ($pending['order_id'] ?? 0));
        $pending['failure_message'] = trim((string)($record['failureMessage'] ?? ''));

        $finalizedResult = checkout_payment_json_decode((string)($record['resultJson'] ?? ''));
        if ($finalizedResult !== []) {
            $pending['finalized_result'] = $finalizedResult;
        }

        return $pending;
    }
}

if (!function_exists('checkout_payment_store_pending')) {
    function checkout_payment_store_pending($connOrPayload, ?array $maybePayload = null): array
    {
        $conn = ($connOrPayload instanceof mysqli) ? $connOrPayload : null;
        $payload = is_array($maybePayload)
            ? $maybePayload
            : (is_array($connOrPayload) ? $connOrPayload : []);

        $payload['checkout_token'] = trim((string)($payload['checkout_token'] ?? ''));
        if ($payload['checkout_token'] === '') {
            $payload['checkout_token'] = bin2hex(random_bytes(16));
        }

        $payload['created_at'] = (int)time();
        $_SESSION['pending_checkout_payment'] = $payload;

        if (!$conn instanceof mysqli) {
            return $payload;
        }

        checkout_payment_ensure_schema($conn);

        $checkoutToken = (string)$payload['checkout_token'];
        $paymentProvider = trim((string)($payload['payment_method'] ?? ''));
        $gatewayReference = trim((string)($payload['gateway_reference'] ?? ''));
        $customerEmail = checkout_payment_customer_email($payload);
        $totalAmount = (float)($payload['total_amount'] ?? 0);
        $payloadJson = checkout_payment_json_encode($payload);
        $expiresAt = date('Y-m-d H:i:s', time() + checkout_payment_pending_ttl_seconds());

        $stmt = $conn->prepare("
            INSERT INTO pending_checkout_payments (
                checkoutToken, paymentProvider, paymentStatus, gatewayReference,
                customerEmail, totalAmount, currency, payloadJson, expiresAt
            ) VALUES (?, ?, 'pending', ?, ?, ?, 'EUR', ?, ?)
            ON DUPLICATE KEY UPDATE
                paymentProvider = VALUES(paymentProvider),
                gatewayReference = CASE
                    WHEN VALUES(gatewayReference) <> '' THEN VALUES(gatewayReference)
                    ELSE gatewayReference
                END,
                customerEmail = VALUES(customerEmail),
                totalAmount = VALUES(totalAmount),
                payloadJson = VALUES(payloadJson),
                expiresAt = VALUES(expiresAt),
                failureMessage = NULL
        ");
        if ($stmt) {
            $stmt->bind_param(
                'ssssdss',
                $checkoutToken,
                $paymentProvider,
                $gatewayReference,
                $customerEmail,
                $totalAmount,
                $payloadJson,
                $expiresAt
            );
            $stmt->execute();
            $stmt->close();
        }

        return $payload;
    }
}

if (!function_exists('checkout_payment_get_pending')) {
    function checkout_payment_get_pending($connOrCheckoutToken = null, ?string $maybeCheckoutToken = null): ?array
    {
        $conn = ($connOrCheckoutToken instanceof mysqli) ? $connOrCheckoutToken : null;
        $checkoutToken = $conn instanceof mysqli
            ? trim((string)$maybeCheckoutToken)
            : trim((string)($connOrCheckoutToken ?? ''));

        $sessionPending = $_SESSION['pending_checkout_payment'] ?? null;
        if ($checkoutToken === '' && is_array($sessionPending)) {
            $checkoutToken = trim((string)($sessionPending['checkout_token'] ?? ''));
        }

        if ($conn instanceof mysqli && $checkoutToken !== '') {
            $record = checkout_payment_fetch_record_by_token($conn, $checkoutToken);
            if (is_array($record)) {
                if (checkout_payment_record_is_expired($record)) {
                    checkout_payment_clear_pending($checkoutToken);
                    return null;
                }

                $pending = checkout_payment_pending_from_record($record);
                $_SESSION['pending_checkout_payment'] = $pending;
                return $pending;
            }
        }

        if (!is_array($sessionPending)) {
            return null;
        }

        $createdAt = (int)($sessionPending['created_at'] ?? 0);
        if ($createdAt > 0 && (time() - $createdAt) > checkout_payment_pending_ttl_seconds()) {
            unset($_SESSION['pending_checkout_payment']);
            return null;
        }

        if ($checkoutToken !== '' && trim((string)($sessionPending['checkout_token'] ?? '')) !== $checkoutToken) {
            return null;
        }

        return $sessionPending;
    }
}

if (!function_exists('checkout_payment_get_pending_by_gateway_reference')) {
    function checkout_payment_get_pending_by_gateway_reference(mysqli $conn, string $paymentProvider, string $gatewayReference): ?array
    {
        $paymentProvider = strtolower(trim($paymentProvider));
        $gatewayReference = trim($gatewayReference);
        if ($paymentProvider === '' || $gatewayReference === '') {
            return null;
        }

        $record = checkout_payment_fetch_record_by_gateway_reference($conn, $paymentProvider, $gatewayReference);
        if (!is_array($record) || checkout_payment_record_is_expired($record)) {
            return null;
        }

        return checkout_payment_pending_from_record($record);
    }
}

if (!function_exists('checkout_payment_get_finalized_result')) {
    function checkout_payment_get_finalized_result(array $pending): ?array
    {
        $result = $pending['finalized_result'] ?? null;
        return is_array($result) ? $result : null;
    }
}

if (!function_exists('checkout_payment_update_gateway_reference')) {
    function checkout_payment_update_gateway_reference(mysqli $conn, string $checkoutToken, string $paymentProvider, string $gatewayReference): bool
    {
        checkout_payment_ensure_schema($conn);

        $checkoutToken = trim($checkoutToken);
        $paymentProvider = strtolower(trim($paymentProvider));
        $gatewayReference = trim($gatewayReference);
        if ($checkoutToken === '' || $paymentProvider === '' || $gatewayReference === '') {
            return false;
        }

        $stmt = $conn->prepare("
            UPDATE pending_checkout_payments
            SET paymentProvider = ?, gatewayReference = ?
            WHERE checkoutToken = ?
        ");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('sss', $paymentProvider, $gatewayReference, $checkoutToken);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok && isset($_SESSION['pending_checkout_payment']) && trim((string)($_SESSION['pending_checkout_payment']['checkout_token'] ?? '')) === $checkoutToken) {
            $_SESSION['pending_checkout_payment']['gateway_reference'] = $gatewayReference;
            $_SESSION['pending_checkout_payment']['payment_method'] = $paymentProvider;
        }

        return (bool)$ok;
    }
}

if (!function_exists('checkout_payment_mark_failed')) {
    function checkout_payment_mark_failed(mysqli $conn, string $checkoutToken, string $paymentProvider, string $message, string $status = 'failed'): void
    {
        checkout_payment_ensure_schema($conn);

        $checkoutToken = trim($checkoutToken);
        if ($checkoutToken === '') {
            return;
        }

        $paymentProvider = strtolower(trim($paymentProvider));
        $message = trim($message);
        $status = strtolower(trim($status));
        if ($status === '') {
            $status = 'failed';
        }

        $stmt = $conn->prepare("
            UPDATE pending_checkout_payments
            SET paymentProvider = CASE WHEN ? <> '' THEN ? ELSE paymentProvider END,
                paymentStatus = ?,
                failureMessage = ?,
                updatedAt = CURRENT_TIMESTAMP
            WHERE checkoutToken = ?
        ");
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('sssss', $paymentProvider, $paymentProvider, $status, $message, $checkoutToken);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('checkout_payment_clear_pending')) {
    function checkout_payment_clear_pending(?string $checkoutToken = null): void
    {
        $sessionPending = $_SESSION['pending_checkout_payment'] ?? null;
        if (!is_array($sessionPending)) {
            return;
        }

        if ($checkoutToken !== null && trim($checkoutToken) !== '') {
            if (trim((string)($sessionPending['checkout_token'] ?? '')) !== trim($checkoutToken)) {
                return;
            }
        }

        unset($_SESSION['pending_checkout_payment']);
    }
}

if (!function_exists('checkout_payment_clear_cart_state')) {
    function checkout_payment_clear_cart_state(): void
    {
        unset($_SESSION['cart'], $_SESSION['cart_coupon_code'], $_SESSION['cart_loyalty_points_redeem'], $_SESSION['cart_loyalty_user_id']);
    }
}

if (!function_exists('checkout_payment_result_from_existing_order')) {
    function checkout_payment_result_from_existing_order(mysqli $conn, int $orderId, array $pending, string $paymentProvider, string $transactionId): array
    {
        $stmt = $conn->prepare("
            SELECT orderID, orderNumber, totalAmount, shippingCost, discountTotal
            FROM orders
            WHERE orderID = ?
            LIMIT 1
        ");
        if (!$stmt) {
            throw new RuntimeException('Could not load the finalized order details.');
        }

        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $order = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$order) {
            throw new RuntimeException('The finalized order could not be found.');
        }

        return [
            'order_id' => (int)$order['orderID'],
            'order_number' => (string)$order['orderNumber'],
            'total' => (float)$order['totalAmount'],
            'shipping_message' => (float)$order['shippingCost'] <= 0 ? 'Free Shipping Applied!' : 'Shipping has been calculated for your order.',
            'free_shipping' => (float)$order['shippingCost'] <= 0,
            'account_created' => false,
            'discount_total' => (float)$order['discountTotal'],
            'coupon_discount' => (float)($pending['coupon_discount'] ?? 0),
            'loyalty_redeemed_points' => 0,
            'loyalty_redeem_discount' => 0.0,
            'loyalty_earned_points' => 0,
            'loyalty_balance_after' => 0,
            'loyalty_account_available' => !empty($pending['user_id']),
            'coupon_code' => trim((string)($pending['coupon_code'] ?? '')),
            'confirmation_email_to' => checkout_payment_customer_email($pending),
            'confirmation_email_sent' => false,
            'confirmation_email_error' => '',
            'payment_provider' => $paymentProvider,
            'payment_transaction_id' => $transactionId,
            'temp_password' => null,
        ];
    }
}

if (!function_exists('checkout_payment_finalize_paid_order')) {
    function checkout_payment_finalize_paid_order(mysqli $conn, array $pending, string $paymentProvider, string $transactionId): array
    {
        checkout_payment_ensure_schema($conn);

        $checkoutToken = trim((string)($pending['checkout_token'] ?? ''));
        if ($checkoutToken === '') {
            throw new RuntimeException('Missing checkout token for payment finalization.');
        }

        $paymentProvider = strtolower(trim($paymentProvider));
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            throw new RuntimeException('Missing transaction id for payment finalization.');
        }

        if (!checkout_payment_fetch_record_by_token($conn, $checkoutToken)) {
            checkout_payment_store_pending($conn, $pending);
        }

        $conn->begin_transaction();
        try {
            $record = checkout_payment_fetch_record_by_token($conn, $checkoutToken, true);
            if (!$record) {
                throw new RuntimeException('Payment session could not be found while finalizing the order.');
            }

            $lockedPending = checkout_payment_pending_from_record($record);
            $currentStatus = strtolower(trim((string)($record['paymentStatus'] ?? 'pending')));
            $existingOrderId = (int)($record['orderID'] ?? 0);
            $existingResult = checkout_payment_json_decode((string)($record['resultJson'] ?? ''));

            if ($currentStatus === 'finalized' && $existingOrderId > 0) {
                $result = $existingResult !== []
                    ? $existingResult
                    : checkout_payment_result_from_existing_order(
                        $conn,
                        $existingOrderId,
                        $lockedPending,
                        $paymentProvider !== '' ? $paymentProvider : trim((string)($record['paymentProvider'] ?? '')),
                        $transactionId !== '' ? $transactionId : trim((string)($record['transactionID'] ?? ''))
                    );

                $conn->commit();
                $_SESSION['checkout_result'] = $result;
                if (!empty($result['temp_password'])) {
                    $_SESSION['temp_password'] = (string)$result['temp_password'];
                }
                checkout_payment_clear_cart_state();
                checkout_payment_clear_pending($checkoutToken);
                return $result;
            }

            $items = is_array($lockedPending['items'] ?? null) ? $lockedPending['items'] : [];
            if (empty($items)) {
                throw new RuntimeException('Your cart session expired before payment confirmation.');
            }

            $isLoggedIn = !empty($lockedPending['is_logged_in']);
            $userId = max(0, (int)($lockedPending['user_id'] ?? 0));
            $userEmail = trim((string)($lockedPending['user_email'] ?? ''));
            $userFullName = trim((string)($lockedPending['user_full_name'] ?? 'User'));
            $guestEmail = trim((string)($lockedPending['guest_email'] ?? ''));
            $guestFullName = trim((string)($lockedPending['guest_full_name'] ?? 'Customer'));
            $guestPhone = trim((string)($lockedPending['guest_phone'] ?? ''));
            $createAccount = !empty($lockedPending['create_account']);
            $cartTotal = (float)($lockedPending['subtotal'] ?? 0);
            $couponDiscount = (float)($lockedPending['coupon_discount'] ?? 0);
            $selectedCouponCode = trim((string)($lockedPending['coupon_code'] ?? ''));
            $selectedLoyaltyPoints = max(0, (int)($lockedPending['selected_loyalty_points'] ?? 0));
            $loyaltyEligibleSubtotal = (float)($lockedPending['loyalty_eligible_subtotal'] ?? max(0, $cartTotal - $couponDiscount));
            $shippingCost = (float)($lockedPending['shipping_cost'] ?? 0);
            $shippingCountry = trim((string)($lockedPending['shipping_country'] ?? 'Cyprus'));
            $shippingSpeed = trim((string)($lockedPending['shipping_speed'] ?? 'standard'));
            $fulfillmentMode = trim((string)($lockedPending['fulfillment_mode'] ?? 'delivery'));
            $shippingAddress = trim((string)($lockedPending['shipping_address'] ?? ''));
            $shippingCity = trim((string)($lockedPending['shipping_city'] ?? ''));
            $shippingPostalCode = trim((string)($lockedPending['shipping_postal_code'] ?? ''));
            $shippingLabel = trim((string)($lockedPending['shipping_label'] ?? ''));
            $courier = trim((string)($lockedPending['courier'] ?? ''));
            $freeShippingThreshold = (float)($lockedPending['free_shipping_threshold'] ?? 0);

            $lockedLoyaltyBalance = ($isLoggedIn && $userId > 0)
                ? loyaltyGetCurrentBalance($conn, $userId, true)
                : 0;
            $finalLoyaltyRedemption = loyaltyBuildRedemptionPreview(
                $selectedLoyaltyPoints,
                $lockedLoyaltyBalance,
                $loyaltyEligibleSubtotal
            );
            if ($selectedLoyaltyPoints > 0 && $finalLoyaltyRedemption['error'] !== '') {
                throw new RuntimeException((string)$finalLoyaltyRedemption['error']);
            }

            $loyaltyDiscount = (float)($finalLoyaltyRedemption['discount_amount'] ?? 0);
            $combinedDiscountTotal = round($couponDiscount + $loyaltyDiscount, 2);
            $loyaltyEarnEligibleAmount = max(0, round($loyaltyEligibleSubtotal - $loyaltyDiscount, 2));
            $earnedPoints = loyaltyCalculateEarnedPoints($loyaltyEarnEligibleAmount);
            $totalAmount = max(0, ($cartTotal - $combinedDiscountTotal) + $shippingCost);

            $placed = placeOrder($conn, [
                'payment_confirmed' => true,
                'items' => $items,
                'user_id' => $userId > 0 ? $userId : null,
                'is_guest' => $isLoggedIn ? 0 : 1,
                'email' => $isLoggedIn ? $userEmail : $guestEmail,
                'customer_name' => $isLoggedIn ? $userFullName : $guestFullName,
                'order_status' => 'pending',
                'payment_status' => 'paid',
                'payment_provider' => $paymentProvider,
                'transaction_id' => $transactionId,
                'subtotal' => $cartTotal,
                'discount_total' => $combinedDiscountTotal,
                'shipping_cost' => $shippingCost,
                'total_amount' => $totalAmount,
                'shipping_address' => $shippingAddress,
                'shipping_city' => $shippingCity,
                'shipping_postal_code' => $shippingPostalCode,
                'shipping_country' => $shippingCountry,
                'shipping_label' => $shippingLabel,
                'courier' => $courier,
                'shipping_priority' => $shippingSpeed,
                'fulfillment_mode' => $fulfillmentMode,
            ]);

            $orderId = (int)$placed['order_id'];
            $orderNumber = (string)$placed['order_number'];

            $accountCreated = false;
            $tempPassword = null;
            $loyaltyUserId = ($isLoggedIn && $userId > 0) ? $userId : 0;

            if (!$isLoggedIn && $createAccount && $guestEmail !== '') {
                $tempPassword = bin2hex(random_bytes(5));
                $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
                $nameParts = explode(' ', trim($guestFullName), 2);
                $first = trim((string)($nameParts[0] ?? ''));
                $last = trim((string)($nameParts[1] ?? ''));

                $check = $conn->prepare("SELECT userID FROM users WHERE email = ?");
                if ($check) {
                    $check->bind_param('s', $guestEmail);
                    $check->execute();
                    $check->store_result();
                    if ($check->num_rows === 0) {
                        $usernameBase = strstr($guestEmail, '@', true);
                        if ($usernameBase === false || $usernameBase === '') {
                            $usernameBase = 'user';
                        }
                        $usernameBase = strtolower((string)preg_replace('/[^a-z0-9]/', '', $usernameBase));
                        if ($usernameBase === '') {
                            $usernameBase = 'user';
                        }
                        $username = $usernameBase . rand(100, 999);
                        $fullName = trim($first . ' ' . $last);
                        if ($fullName === '') {
                            $fullName = $guestFullName !== '' ? $guestFullName : 'Customer';
                        }

                        $insert = $conn->prepare("INSERT INTO users (full_name, email, username, password, phone, role) VALUES (?,?,?,?,?,'user')");
                        if ($insert) {
                            $insert->bind_param('sssss', $fullName, $guestEmail, $username, $hash, $guestPhone);
                            if ($insert->execute()) {
                                $newUserId = (int)$insert->insert_id;
                                $updateOrder = $conn->prepare("UPDATE orders SET userID = ?, isGuestFlag = 0 WHERE orderID = ?");
                                if ($updateOrder) {
                                    $updateOrder->bind_param('ii', $newUserId, $orderId);
                                    $updateOrder->execute();
                                    $updateOrder->close();
                                }
                                $_SESSION['temp_password'] = $tempPassword;
                                $accountCreated = true;
                                $loyaltyUserId = $newUserId;
                            }
                            $insert->close();
                        }
                    }
                    $check->close();
                }
            }

            $loyaltyOutcome = loyaltyApplyOrderTransactions(
                $conn,
                $loyaltyUserId,
                $orderId,
                (int)($finalLoyaltyRedemption['points_to_redeem'] ?? 0),
                $loyaltyDiscount,
                $earnedPoints
            );

            $confirmationEmailTo = $isLoggedIn ? $userEmail : $guestEmail;
            $confirmationName = $isLoggedIn ? $userFullName : $guestFullName;
            $emailResult = sendOrderConfirmationEmail([
                'to_email' => $confirmationEmailTo,
                'customer_name' => $confirmationName !== '' ? $confirmationName : 'Customer',
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'total' => $totalAmount,
                'shipping_cost' => $shippingCost,
                'shipping_address' => $shippingAddress,
                'shipping_city' => $shippingCity,
                'shipping_postal_code' => $shippingPostalCode,
                'shipping_country' => $shippingCountry,
                'shipping_label' => $shippingLabel,
                'courier' => $courier,
                'shipping_priority' => $shippingSpeed,
                'fulfillment_mode' => $fulfillmentMode,
                'items' => $items,
            ]);

            $result = [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'total' => $totalAmount,
                'shipping_message' => $shippingCost <= 0 ? 'Free Shipping Applied!' : 'Shipping has been calculated for your order.',
                'free_shipping' => $cartTotal >= $freeShippingThreshold || $shippingCost <= 0,
                'account_created' => $accountCreated,
                'discount_total' => $combinedDiscountTotal,
                'coupon_discount' => $couponDiscount,
                'loyalty_redeemed_points' => (int)($loyaltyOutcome['redeemed_points'] ?? 0),
                'loyalty_redeem_discount' => (float)($loyaltyOutcome['redeem_discount'] ?? 0),
                'loyalty_earned_points' => (int)($loyaltyOutcome['earned_points'] ?? 0),
                'loyalty_balance_after' => (int)($loyaltyOutcome['balance_after'] ?? 0),
                'loyalty_account_available' => $loyaltyUserId > 0,
                'coupon_code' => $selectedCouponCode,
                'confirmation_email_to' => $confirmationEmailTo,
                'confirmation_email_sent' => (bool)($emailResult['sent'] ?? false),
                'confirmation_email_error' => (string)($emailResult['error'] ?? ''),
                'payment_provider' => $paymentProvider,
                'payment_transaction_id' => $transactionId,
                'temp_password' => $tempPassword,
            ];

            $resultJson = checkout_payment_json_encode($result);
            $finalizeStmt = $conn->prepare("
                UPDATE pending_checkout_payments
                SET paymentProvider = ?,
                    paymentStatus = 'finalized',
                    gatewayReference = CASE
                        WHEN ? <> '' THEN ?
                        ELSE gatewayReference
                    END,
                    transactionID = ?,
                    resultJson = ?,
                    orderID = ?,
                    failureMessage = NULL,
                    finalizedAt = NOW()
                WHERE checkoutToken = ?
            ");
            if (!$finalizeStmt) {
                throw new RuntimeException('Could not store the finalized payment state.');
            }

            $gatewayReference = trim((string)($lockedPending['gateway_reference'] ?? ''));
            $finalizeStmt->bind_param(
                'sssssds',
                $paymentProvider,
                $gatewayReference,
                $gatewayReference,
                $transactionId,
                $resultJson,
                $orderId,
                $checkoutToken
            );
            if (!$finalizeStmt->execute()) {
                $finalizeStmt->close();
                throw new RuntimeException('Could not store the finalized payment state.');
            }
            $finalizeStmt->close();

            $conn->commit();

            checkout_payment_clear_cart_state();
            checkout_payment_clear_pending($checkoutToken);

            $_SESSION['checkout_result'] = $result;
            if ($tempPassword !== null) {
                $_SESSION['temp_password'] = $tempPassword;
            }

            return $result;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
