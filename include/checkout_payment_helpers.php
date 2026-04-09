<?php

if (!function_exists('checkout_payment_store_pending')) {
    function checkout_payment_store_pending(array $payload): array
    {
        $payload['checkout_token'] = trim((string)($payload['checkout_token'] ?? ''));
        if ($payload['checkout_token'] === '') {
            $payload['checkout_token'] = bin2hex(random_bytes(16));
        }

        $payload['created_at'] = (int)time();
        $_SESSION['pending_checkout_payment'] = $payload;
        return $payload;
    }
}

if (!function_exists('checkout_payment_get_pending')) {
    function checkout_payment_get_pending(?string $checkoutToken = null): ?array
    {
        $pending = $_SESSION['pending_checkout_payment'] ?? null;
        if (!is_array($pending)) {
            return null;
        }

        $createdAt = (int)($pending['created_at'] ?? 0);
        if ($createdAt > 0 && (time() - $createdAt) > 7200) {
            unset($_SESSION['pending_checkout_payment']);
            return null;
        }

        if ($checkoutToken !== null && trim($checkoutToken) !== '' && trim((string)($pending['checkout_token'] ?? '')) !== trim($checkoutToken)) {
            return null;
        }

        return $pending;
    }
}

if (!function_exists('checkout_payment_clear_pending')) {
    function checkout_payment_clear_pending(): void
    {
        unset($_SESSION['pending_checkout_payment']);
    }
}

if (!function_exists('checkout_payment_clear_cart_state')) {
    function checkout_payment_clear_cart_state(): void
    {
        unset($_SESSION['cart'], $_SESSION['cart_coupon_code'], $_SESSION['cart_loyalty_points_redeem'], $_SESSION['cart_loyalty_user_id']);
    }
}

if (!function_exists('checkout_payment_finalize_paid_order')) {
    function checkout_payment_finalize_paid_order(mysqli $conn, array $pending, string $paymentProvider, string $transactionId): array
    {
        $items = is_array($pending['items'] ?? null) ? $pending['items'] : [];
        if (empty($items)) {
            throw new RuntimeException('Your cart session expired before payment confirmation.');
        }

        $isLoggedIn = !empty($pending['is_logged_in']);
        $userId = max(0, (int)($pending['user_id'] ?? 0));
        $userEmail = trim((string)($pending['user_email'] ?? ''));
        $userFullName = trim((string)($pending['user_full_name'] ?? 'User'));
        $guestEmail = trim((string)($pending['guest_email'] ?? ''));
        $guestFullName = trim((string)($pending['guest_full_name'] ?? 'Customer'));
        $guestPhone = trim((string)($pending['guest_phone'] ?? ''));
        $createAccount = !empty($pending['create_account']);
        $cartTotal = (float)($pending['subtotal'] ?? 0);
        $couponDiscount = (float)($pending['coupon_discount'] ?? 0);
        $selectedCouponCode = trim((string)($pending['coupon_code'] ?? ''));
        $selectedLoyaltyPoints = max(0, (int)($pending['selected_loyalty_points'] ?? 0));
        $loyaltyEligibleSubtotal = (float)($pending['loyalty_eligible_subtotal'] ?? max(0, $cartTotal - $couponDiscount));
        $shippingCost = (float)($pending['shipping_cost'] ?? 0);
        $shippingCountry = trim((string)($pending['shipping_country'] ?? 'Cyprus'));
        $shippingSpeed = trim((string)($pending['shipping_speed'] ?? 'standard'));
        $fulfillmentMode = trim((string)($pending['fulfillment_mode'] ?? 'delivery'));
        $shippingAddress = trim((string)($pending['shipping_address'] ?? ''));
        $shippingCity = trim((string)($pending['shipping_city'] ?? ''));
        $shippingPostalCode = trim((string)($pending['shipping_postal_code'] ?? ''));
        $shippingLabel = trim((string)($pending['shipping_label'] ?? ''));
        $courier = trim((string)($pending['courier'] ?? ''));
        $freeShippingThreshold = (float)($pending['free_shipping_threshold'] ?? 0);

        $conn->begin_transaction();
        try {
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
            $loyaltyUserId = $isLoggedIn && $userId > 0 ? $userId : 0;

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

            $conn->commit();

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

            checkout_payment_clear_cart_state();
            checkout_payment_clear_pending();

            $_SESSION['checkout_result'] = [
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
            ];

            return $_SESSION['checkout_result'];
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
