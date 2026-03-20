<?php

if (!function_exists('ensureLoyaltyProgramSchema')) {
    function ensureLoyaltyProgramSchema(mysqli $conn): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $conn->query("
            CREATE TABLE IF NOT EXISTS loyalty_points (
                loyaltyTxID INT(11) NOT NULL AUTO_INCREMENT,
                userID INT(11) NOT NULL,
                pointsDelta INT(11) NOT NULL,
                pointsBalanceAfter INT(11) NOT NULL,
                voucherValue DOUBLE DEFAULT NULL,
                referenceOrderID INT(11) DEFAULT NULL,
                ruleApplied VARCHAR(120) DEFAULT NULL,
                createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (loyaltyTxID),
                KEY idx_loyalty_points_userID (userID),
                KEY idx_loyalty_points_referenceOrderID (referenceOrderID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
}

if (!function_exists('loyaltyPointsEarnedPerEuro')) {
    function loyaltyPointsEarnedPerEuro(): int
    {
        return 1;
    }
}

if (!function_exists('loyaltyPointsRedeemPerEuro')) {
    function loyaltyPointsRedeemPerEuro(): int
    {
        return 20;
    }
}

if (!function_exists('loyaltyPointValueEuro')) {
    function loyaltyPointValueEuro(): float
    {
        return 1 / loyaltyPointsRedeemPerEuro();
    }
}

if (!function_exists('loyaltyGetCurrentBalance')) {
    function loyaltyGetCurrentBalance(mysqli $conn, int $userId, bool $lockForUpdate = false): int
    {
        ensureLoyaltyProgramSchema($conn);

        if ($userId <= 0) {
            return 0;
        }

        $sql = "
            SELECT pointsBalanceAfter
            FROM loyalty_points
            WHERE userID = ?
            ORDER BY loyaltyTxID DESC
            LIMIT 1
        ";
        if ($lockForUpdate) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return max(0, (int)($row['pointsBalanceAfter'] ?? 0));
    }
}

if (!function_exists('loyaltyCalculateEarnedPoints')) {
    function loyaltyCalculateEarnedPoints(float $eligibleSpend): int
    {
        if ($eligibleSpend <= 0) {
            return 0;
        }

        return max(0, (int)floor($eligibleSpend * loyaltyPointsEarnedPerEuro()));
    }
}

if (!function_exists('loyaltyFormatPointsMoney')) {
    function loyaltyFormatPointsMoney(int $points): string
    {
        return number_format(max(0, $points) * loyaltyPointValueEuro(), 2);
    }
}

if (!function_exists('loyaltyBuildRedemptionPreview')) {
    function loyaltyBuildRedemptionPreview(int $requestedPoints, int $availableBalance, float $eligibleAmount): array
    {
        $requestedPoints = max(0, $requestedPoints);
        $availableBalance = max(0, $availableBalance);
        $eligibleAmount = max(0, round($eligibleAmount, 2));

        $maxPointsByOrder = max(0, (int)floor($eligibleAmount * loyaltyPointsRedeemPerEuro()));
        $maxPointsAllowed = min($availableBalance, $maxPointsByOrder);

        $error = '';
        $pointsToRedeem = 0;
        $discountAmount = 0.0;

        if ($requestedPoints > 0) {
            if ($availableBalance <= 0) {
                $error = 'You do not have loyalty points available yet.';
            } elseif ($maxPointsByOrder <= 0) {
                $error = 'There is no eligible amount available for loyalty redemption.';
            } elseif ($requestedPoints > $availableBalance) {
                $error = 'You cannot redeem more loyalty points than your available balance.';
            } elseif ($requestedPoints > $maxPointsByOrder) {
                $error = 'You cannot redeem more loyalty points than the current order allows.';
            } else {
                $pointsToRedeem = $requestedPoints;
                $discountAmount = round($pointsToRedeem * loyaltyPointValueEuro(), 2);
            }
        }

        return [
            'requested_points' => $requestedPoints,
            'available_balance' => $availableBalance,
            'eligible_amount' => $eligibleAmount,
            'max_points_by_order' => $maxPointsByOrder,
            'max_points_allowed' => $maxPointsAllowed,
            'points_to_redeem' => $pointsToRedeem,
            'discount_amount' => $discountAmount,
            'error' => $error,
            'has_points' => $availableBalance > 0,
        ];
    }
}

if (!function_exists('loyaltyOrderRuleAlreadyApplied')) {
    function loyaltyOrderRuleAlreadyApplied(mysqli $conn, int $userId, int $orderId, string $ruleApplied): bool
    {
        ensureLoyaltyProgramSchema($conn);

        if ($userId <= 0 || $orderId <= 0 || trim($ruleApplied) === '') {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT loyaltyTxID
            FROM loyalty_points
            WHERE userID = ?
              AND referenceOrderID = ?
              AND ruleApplied = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('iis', $userId, $orderId, $ruleApplied);
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = $res && $res->num_rows > 0;
        $stmt->close();

        return $exists;
    }
}

if (!function_exists('loyaltyRecordTransaction')) {
    function loyaltyRecordTransaction(
        mysqli $conn,
        int $userId,
        int $pointsDelta,
        ?float $voucherValue = null,
        ?int $referenceOrderID = null,
        ?string $ruleApplied = null
    ): array {
        ensureLoyaltyProgramSchema($conn);

        if ($userId <= 0) {
            throw new InvalidArgumentException('Valid user ID is required for loyalty transactions.');
        }
        if ($pointsDelta === 0) {
            throw new InvalidArgumentException('Loyalty transaction must change the balance.');
        }

        $balanceBefore = loyaltyGetCurrentBalance($conn, $userId, true);
        $balanceAfter = $balanceBefore + $pointsDelta;
        if ($balanceAfter < 0) {
            throw new InvalidArgumentException('Loyalty balance cannot go below zero.');
        }

        $stmt = $conn->prepare("
            INSERT INTO loyalty_points (
                userID,
                pointsDelta,
                pointsBalanceAfter,
                voucherValue,
                referenceOrderID,
                ruleApplied
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            throw new Exception('Failed to prepare loyalty transaction insert: ' . $conn->error);
        }

        $voucher = $voucherValue !== null ? round($voucherValue, 2) : null;
        $rule = $ruleApplied !== null ? trim($ruleApplied) : null;
        $stmt->bind_param('iiidis', $userId, $pointsDelta, $balanceAfter, $voucher, $referenceOrderID, $rule);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception('Failed to insert loyalty transaction: ' . $error);
        }
        $txId = (int)$stmt->insert_id;
        $stmt->close();

        return [
            'transaction_id' => $txId,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'points_delta' => $pointsDelta,
            'voucher_value' => $voucher,
        ];
    }
}

if (!function_exists('loyaltyApplyOrderTransactions')) {
    function loyaltyApplyOrderTransactions(
        mysqli $conn,
        int $userId,
        int $orderId,
        int $redeemedPoints,
        float $redeemDiscount,
        int $earnedPoints
    ): array {
        ensureLoyaltyProgramSchema($conn);

        $result = [
            'redeemed_points' => 0,
            'earned_points' => 0,
            'redeem_discount' => 0.0,
            'balance_after' => $userId > 0 ? loyaltyGetCurrentBalance($conn, $userId, false) : 0,
        ];

        if ($userId <= 0 || $orderId <= 0) {
            return $result;
        }

        if ($redeemedPoints > 0 && !loyaltyOrderRuleAlreadyApplied($conn, $userId, $orderId, 'redeem_checkout')) {
            $tx = loyaltyRecordTransaction(
                $conn,
                $userId,
                -abs($redeemedPoints),
                max(0, round($redeemDiscount, 2)),
                $orderId,
                'redeem_checkout'
            );
            $result['redeemed_points'] = abs($redeemedPoints);
            $result['redeem_discount'] = max(0, round($redeemDiscount, 2));
            $result['balance_after'] = (int)$tx['balance_after'];
        }

        if ($earnedPoints > 0 && !loyaltyOrderRuleAlreadyApplied($conn, $userId, $orderId, 'earn_purchase')) {
            $tx = loyaltyRecordTransaction(
                $conn,
                $userId,
                abs($earnedPoints),
                null,
                $orderId,
                'earn_purchase'
            );
            $result['earned_points'] = abs($earnedPoints);
            $result['balance_after'] = (int)$tx['balance_after'];
        }

        if ($result['earned_points'] === 0 && $result['redeemed_points'] === 0) {
            $result['balance_after'] = loyaltyGetCurrentBalance($conn, $userId, false);
        }

        return $result;
    }
}

if (!function_exists('loyaltyFetchHistory')) {
    function loyaltyFetchHistory(mysqli $conn, int $userId, int $limit = 50): array
    {
        ensureLoyaltyProgramSchema($conn);

        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $sql = "
            SELECT
                lp.loyaltyTxID,
                lp.pointsDelta,
                lp.pointsBalanceAfter,
                lp.voucherValue,
                lp.referenceOrderID,
                lp.ruleApplied,
                lp.createdAt,
                o.orderNumber
            FROM loyalty_points lp
            LEFT JOIN orders o ON o.orderID = lp.referenceOrderID
            WHERE lp.userID = ?
            ORDER BY lp.createdAt DESC, lp.loyaltyTxID DESC
            LIMIT {$limit}
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}
