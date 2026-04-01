<?php
/**
 * Loyalty Program Functions
 * Handles points earning and redemption for registered users
 */

if (!defined('INCLUDE_CHECK')) {
    define('INCLUDE_CHECK', true);
}

/**
 * Ensure loyalty program database schema exists
 */
function ensureLoyaltyProgramSchema(mysqli $conn): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    
    try {
        // Check if loyalty_points table exists
        $check = $conn->query("SHOW TABLES LIKE 'loyalty_points'");
        if ($check && $check->num_rows === 0) {
            $sql = "
            CREATE TABLE IF NOT EXISTS `loyalty_points` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `user_id` INT NOT NULL,
                `points` INT NOT NULL DEFAULT 0,
                `earned` INT NOT NULL DEFAULT 0,
                `redeemed` INT NOT NULL DEFAULT 0,
                `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";
            $conn->query($sql);
        }
        
        // Check if loyalty_transactions table exists
        $check = $conn->query("SHOW TABLES LIKE 'loyalty_transactions'");
        if ($check && $check->num_rows === 0) {
            $sql = "
            CREATE TABLE IF NOT EXISTS `loyalty_transactions` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `user_id` INT NOT NULL,
                `order_id` INT DEFAULT NULL,
                `points` INT NOT NULL,
                `type` ENUM('earn', 'redeem', 'adjust') NOT NULL,
                `description` VARCHAR(255) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `user_id` (`user_id`),
                KEY `order_id` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";
            $conn->query($sql);
        }
    } catch (Exception $e) {
        error_log("Failed to create loyalty tables: " . $e->getMessage());
    }
}

/**
 * Get current loyalty points balance for a user
 */
function loyaltyGetCurrentBalance(mysqli $conn, int $userId, bool $lockForUpdate = false): int {
    if ($userId <= 0) return 0;
    
    try {
        $query = "SELECT points FROM loyalty_points WHERE user_id = ?";
        if ($lockForUpdate) {
            $query .= " FOR UPDATE";
        }
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return 0;
        }
        
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row ? (int)$row['points'] : 0;
    } catch (Exception $e) {
        error_log("Loyalty balance error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get loyalty point value in euros
 */
function loyaltyPointValueEuro(): float {
    return 0.01;
}

/**
 * Get points earned per euro spent
 */
function loyaltyPointsEarnedPerEuro(): int {
    return 10;
}

/**
 * Get points needed to redeem €1.00
 */
function loyaltyPointsRedeemPerEuro(): int {
    return 100;
}

/**
 * Calculate points earned from a purchase amount
 */
function loyaltyCalculateEarnedPoints(float $amount): int {
    return (int)floor($amount * loyaltyPointsEarnedPerEuro());
}

/**
 * Calculate discount amount from points redeemed
 */
function loyaltyCalculateDiscountFromPoints(int $points): float {
    return floor($points / loyaltyPointsRedeemPerEuro()) * loyaltyPointValueEuro() * loyaltyPointsRedeemPerEuro();
}

/**
 * Build redemption preview for the checkout page
 */
function loyaltyBuildRedemptionPreview(int $requestedPoints, int $availableBalance, float $cartSubtotal): array {
    if ($requestedPoints <= 0 || $availableBalance <= 0 || $cartSubtotal <= 0) {
        return [
            'points_to_redeem' => 0,
            'discount_amount' => 0.0,
            'max_points_allowed' => 0,
            'error' => ''
        ];
    }
    
    $pointsPerEuro = loyaltyPointsRedeemPerEuro();
    $maxDiscountFromCart = $cartSubtotal;
    $maxPointsFromCart = (int)floor($maxDiscountFromCart * $pointsPerEuro);
    $maxPointsAllowed = min($availableBalance, $maxPointsFromCart);
    
    if ($maxPointsAllowed <= 0) {
        return [
            'points_to_redeem' => 0,
            'discount_amount' => 0.0,
            'max_points_allowed' => 0,
            'error' => 'Cart total is too low to redeem points.'
        ];
    }
    
    $pointsToRedeem = floor($requestedPoints / $pointsPerEuro) * $pointsPerEuro;
    $pointsToRedeem = min($pointsToRedeem, $maxPointsAllowed);
    
    if ($pointsToRedeem <= 0) {
        return [
            'points_to_redeem' => 0,
            'discount_amount' => 0.0,
            'max_points_allowed' => $maxPointsAllowed,
            'error' => 'Points must be in increments of ' . $pointsPerEuro . '.'
        ];
    }
    
    $discountAmount = ($pointsToRedeem / $pointsPerEuro) * loyaltyPointValueEuro() * $pointsPerEuro;
    
    return [
        'points_to_redeem' => $pointsToRedeem,
        'discount_amount' => round($discountAmount, 2),
        'max_points_allowed' => $maxPointsAllowed,
        'error' => ''
    ];
}

/**
 * Apply loyalty transactions for an order
 */
function loyaltyApplyOrderTransactions(
    mysqli $conn, 
    int $userId, 
    int $orderId, 
    int $pointsRedeemed, 
    float $discountAmount, 
    int $pointsEarned
): array {
    // If tables don't exist, just return success without doing anything
    try {
        $check = $conn->query("SHOW TABLES LIKE 'loyalty_points'");
        if (!$check || $check->num_rows === 0) {
            return ['success' => true, 'message' => 'Loyalty program not available'];
        }
    } catch (Exception $e) {
        return ['success' => true, 'message' => 'Loyalty program not available'];
    }
    
    if ($userId <= 0) {
        return ['success' => false, 'message' => 'Invalid user ID'];
    }
    
    $result = ['success' => true, 'message' => ''];
    $messages = [];
    
    try {
        $conn->begin_transaction();
        
        // Get current balance with lock
        $stmt = $conn->prepare("SELECT points, earned, redeemed FROM loyalty_points WHERE user_id = ? FOR UPDATE");
        if (!$stmt) {
            $conn->rollback();
            return ['success' => true, 'message' => 'Loyalty program not available'];
        }
        
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$current) {
            // Initialize user's loyalty record
            $stmt = $conn->prepare("INSERT INTO loyalty_points (user_id, points, earned, redeemed) VALUES (?, 0, 0, 0)");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->close();
            $current = ['points' => 0, 'earned' => 0, 'redeemed' => 0];
        }
        
        $newPoints = $current['points'];
        $newEarned = $current['earned'];
        $newRedeemed = $current['redeemed'];
        
        // Handle redemption
        if ($pointsRedeemed > 0) {
            if ($current['points'] < $pointsRedeemed) {
                throw new Exception("Insufficient points balance");
            }
            $newPoints -= $pointsRedeemed;
            $newRedeemed += $pointsRedeemed;
            
            $description = "Redeemed $pointsRedeemed points for €" . number_format($discountAmount, 2) . " off order #$orderId";
            $stmt = $conn->prepare("INSERT INTO loyalty_transactions (user_id, order_id, points, type, description) VALUES (?, ?, ?, 'redeem', ?)");
            $stmt->bind_param("iiis", $userId, $orderId, $pointsRedeemed, $description);
            $stmt->execute();
            $stmt->close();
            
            $messages[] = "Redeemed $pointsRedeemed points";
        }
        
        // Handle earnings
        if ($pointsEarned > 0) {
            $newPoints += $pointsEarned;
            $newEarned += $pointsEarned;
            
            $description = "Earned $pointsEarned points from order #$orderId";
            $stmt = $conn->prepare("INSERT INTO loyalty_transactions (user_id, order_id, points, type, description) VALUES (?, ?, ?, 'earn', ?)");
            $stmt->bind_param("iiis", $userId, $orderId, $pointsEarned, $description);
            $stmt->execute();
            $stmt->close();
            
            $messages[] = "Earned $pointsEarned points";
        }
        
        // Update user's loyalty balance
        if ($pointsRedeemed > 0 || $pointsEarned > 0) {
            $stmt = $conn->prepare("UPDATE loyalty_points SET points = ?, earned = ?, redeemed = ?, last_updated = NOW() WHERE user_id = ?");
            $stmt->bind_param("iiii", $newPoints, $newEarned, $newRedeemed, $userId);
            $stmt->execute();
            $stmt->close();
        }
        
        $conn->commit();
        $result['message'] = implode(', ', $messages);
        
    } catch (Exception $e) {
        $conn->rollback();
        $result['success'] = false;
        $result['message'] = $e->getMessage();
    }
    
    return $result;
}

/**
 * Get loyalty transaction history for a user
 */
function loyaltyGetTransactionHistory(mysqli $conn, int $userId, int $limit = 50): array {
    if ($userId <= 0) return [];
    
    try {
        $stmt = $conn->prepare("SELECT id, order_id, points, type, description, created_at FROM loyalty_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        if (!$stmt) return [];
        
        $stmt->bind_param("ii", $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $transactions = [];
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
        $stmt->close();
        
        return $transactions;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get loyalty program summary for a user
 */
function loyaltyGetUserSummary(mysqli $conn, int $userId): array {
    if ($userId <= 0) {
        return ['points' => 0, 'earned' => 0, 'redeemed' => 0];
    }
    
    try {
        $stmt = $conn->prepare("SELECT points, earned, redeemed FROM loyalty_points WHERE user_id = ?");
        if (!$stmt) return ['points' => 0, 'earned' => 0, 'redeemed' => 0];
        
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        return $data ?: ['points' => 0, 'earned' => 0, 'redeemed' => 0];
    } catch (Exception $e) {
        return ['points' => 0, 'earned' => 0, 'redeemed' => 0];
    }
}
?>