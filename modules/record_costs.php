<?php

if (!defined('INCLUDE_CHECK') && !defined('RECORD_COSTS_DIRECT')) {
    die('Direct access not permitted');
}

const ALLOWED_COST_CATEGORIES = [
    'materials',
    'packaging',
    'shipping',
    'marketing',
    'equipment',
    'miscellaneous'
];

function recordOperationalCost($conn, $amount, $category, $date = null, $description = null, $receiptNumber = null, $notes = null) {

    if (!is_numeric($amount) || $amount <= 0) {
        throw new InvalidArgumentException("Amount must be a positive number.");
    }
    $amount = (float)$amount;

    $category = strtolower(trim($category));
    if (!in_array($category, ALLOWED_COST_CATEGORIES)) {
        throw new InvalidArgumentException("Invalid category. Allowed: " . implode(', ', ALLOWED_COST_CATEGORIES));
    }

    if ($date === null) {
        $date = date('Y-m-d');
    } else {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Date must be in YYYY-MM-DD format.");
        }

    }

    ensureOperationalCostsTable($conn);

    $stmt = $conn->prepare("
        INSERT INTO operational_costs (amount, category, description, receipt_number, notes, date)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new Exception("Failed to prepare insert: " . $conn->error);
    }

    $stmt->bind_param("dsssss", $amount, $category, $description, $receiptNumber, $notes, $date);
    if (!$stmt->execute()) {
        throw new Exception("Failed to insert cost record: " . $stmt->error);
    }
    $insertId = $stmt->insert_id;
    $stmt->close();

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