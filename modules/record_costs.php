<?php
/**
 * Record Operational Costs Module
 *
 * Implements function 3.2.6.21: Record Operational Costs
 *
 * @package CreationsByAthina
 */

// Prevent direct access
if (!defined('INCLUDE_CHECK') && !defined('RECORD_COSTS_DIRECT')) {
    die('Direct access not permitted');
}

/**
 * Allowed cost categories as per the requirements.
 */
const ALLOWED_COST_CATEGORIES = [
    'materials',
    'packaging',
    'shipping',
    'marketing',
    'equipment',
    'miscellaneous'
];

/**
 * Record an operational cost entry.
 *
 * @param mysqli $conn            Database connection
 * @param float  $amount          Cost amount (positive number)
 * @param string $category         Cost category (must be in ALLOWED_COST_CATEGORIES)
 * @param string $date             Date of cost (Y-m-d format). Defaults to today if null.
 * @param string $description      Optional description of the cost
 * @param string $receiptNumber    Optional receipt/invoice number
 * @param string $notes            Optional additional notes
 * @return int                     ID of the inserted cost record
 * @throws InvalidArgumentException If validation fails
 * @throws Exception                On database error
 */
function recordOperationalCost($conn, $amount, $category, $date = null, $description = null, $receiptNumber = null, $notes = null) {
    // 1. Validate amount
    if (!is_numeric($amount) || $amount <= 0) {
        throw new InvalidArgumentException("Amount must be a positive number.");
    }
    $amount = (float)$amount;

    // 2. Validate category
    $category = strtolower(trim($category));
    if (!in_array($category, ALLOWED_COST_CATEGORIES)) {
        throw new InvalidArgumentException("Invalid category. Allowed: " . implode(', ', ALLOWED_COST_CATEGORIES));
    }

    // 3. Validate date
    if ($date === null) {
        $date = date('Y-m-d');
    } else {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Date must be in YYYY-MM-DD format.");
        }
        // Optionally prevent future dates? Not required.
    }

    // 4. Ensure table exists
    ensureOperationalCostsTable($conn);

    // 5. Insert record
    $stmt = $conn->prepare("
        INSERT INTO operational_costs (amount, category, description, receipt_number, notes, date)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new Exception("Failed to prepare insert: " . $conn->error);
    }

    // Bind parameters (description, receiptNumber, notes may be null)
    $stmt->bind_param("dsssss", $amount, $category, $description, $receiptNumber, $notes, $date);
    if (!$stmt->execute()) {
        throw new Exception("Failed to insert cost record: " . $stmt->error);
    }
    $insertId = $stmt->insert_id;
    $stmt->close();

    // 6. Log to audit_logs
    $details = json_encode([
        'cost_id'       => $insertId,
        'amount'        => $amount,
        'category'      => $category,
        'date'          => $date,
        'description'   => $description,
        'receipt_number'=> $receiptNumber
    ]);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $logStmt = $conn->prepare("
        INSERT INTO audit_logs (userID, role, actionType, entityType, entityID, ipAddress, detailsJSON)
        VALUES (NULL, 'system', 'cost_recorded', 'operational_cost', ?, ?, ?)
    ");
    if ($logStmt) {
        $logStmt->bind_param("iss", $insertId, $ip, $details);
        $logStmt->execute();
        $logStmt->close();
    } else {
        error_log("Failed to log cost recording: " . $conn->error);
    }

    return $insertId;
}

/**
 * Ensure the operational_costs table exists.
 * Creates it if not present.
 *
 * @param mysqli $conn
 */
function ensureOperationalCostsTable($conn) {
    static $tableChecked = false;
    if ($tableChecked) return;

    $conn->query("
        CREATE TABLE IF NOT EXISTS operational_costs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            amount DECIMAL(10,2) NOT NULL,
            category VARCHAR(100) NOT NULL,
            description TEXT,
            receipt_number VARCHAR(100),
            notes TEXT,
            date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_category (category),
            INDEX idx_date (date)
        )
    ");
    $tableChecked = true;
}
?>