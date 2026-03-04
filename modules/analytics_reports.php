<?php
/**
 * Analytics & Reports Module
 *
 * Implements function 3.2.6.20: View Analytics & Reports (Revenue / Costs / Profitability)
 *
 * @package CreationsByAthina
 */

// Prevent direct access
if (!defined('INCLUDE_CHECK') && !defined('ANALYTICS_REPORTS_DIRECT')) {
    die('Direct access not permitted');
}

/**
 * Get analytics data including revenue, costs, profitability, and cost breakdown.
 *
 * @param mysqli   $conn       Database connection
 * @param string   $timeRange  Time range: 'today', '7days', '30days', 'this_month', 'last_month', 'custom'
 * @param string   $startDate  Start date (Y-m-d) for custom range (optional)
 * @param string   $endDate    End date (Y-m-d) for custom range (optional)
 * @return array               Array containing revenue, costs, profit, margin, and cost breakdown
 * @throws Exception On database error
 */
function getAnalyticsReports($conn, $timeRange = '30days', $startDate = null, $endDate = null) {
    // Ensure required tables exist (operational_costs)
    ensureOperationalCostsTable($conn);

    // Build date condition
    $dateCondition = getAnalyticsDateCondition($timeRange, $startDate, $endDate);

    // 1. Revenue: sum of totalAmount from paid orders
    $revenue = 0;
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(totalAmount), 0) as total_revenue
        FROM orders
        WHERE payment_status IN ('paid', 'confirmed') AND status != 'cancelled'
        $dateCondition
    ");
    if (!$stmt) throw new Exception("Failed to prepare revenue query: " . $conn->error);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $revenue = (float)$row['total_revenue'];
    $stmt->close();

    // 2. Costs: sum of amount from operational_costs
    $costs = 0;
    $costBreakdown = [];
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_costs
        FROM operational_costs
        WHERE 1=1 $dateCondition
    ");
    if (!$stmt) throw new Exception("Failed to prepare costs query: " . $conn->error);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $costs = (float)$row['total_costs'];
    $stmt->close();

    // 3. Cost breakdown by category
    $stmt = $conn->prepare("
        SELECT category, COALESCE(SUM(amount), 0) as category_total
        FROM operational_costs
        WHERE 1=1 $dateCondition
        GROUP BY category
        ORDER BY category_total DESC
    ");
    if (!$stmt) throw new Exception("Failed to prepare cost breakdown: " . $conn->error);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $costBreakdown[] = [
            'category' => $row['category'],
            'total'    => (float)$row['category_total']
        ];
    }
    $stmt->close();

    // 4. Profit and margin
    $profit = $revenue - $costs;
    $profitMargin = ($revenue > 0) ? round(($profit / $revenue) * 100, 2) : 0;

    // 5. Revenue trend (daily) for chart
    $revenueTrend = [];
    $stmt = $conn->prepare("
        SELECT DATE(created_at) as sale_date, COALESCE(SUM(totalAmount), 0) as daily_revenue
        FROM orders
        WHERE payment_status IN ('paid', 'confirmed') AND status != 'cancelled'
        $dateCondition
        GROUP BY DATE(created_at)
        ORDER BY sale_date ASC
    ");
    if (!$stmt) throw new Exception("Failed to prepare revenue trend: " . $conn->error);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $revenueTrend[] = [
            'date'    => $row['sale_date'],
            'revenue' => (float)$row['daily_revenue']
        ];
    }
    $stmt->close();

    // 6. Cost trend (daily) for chart (optional)
    $costTrend = [];
    $stmt = $conn->prepare("
        SELECT DATE(date) as cost_date, COALESCE(SUM(amount), 0) as daily_cost
        FROM operational_costs
        WHERE 1=1 $dateCondition
        GROUP BY DATE(date)
        ORDER BY cost_date ASC
    ");
    if (!$stmt) throw new Exception("Failed to prepare cost trend: " . $conn->error);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $costTrend[] = [
            'date' => $row['cost_date'],
            'cost' => (float)$row['daily_cost']
        ];
    }
    $stmt->close();

    // 7. Combine revenue and cost trends into a single series
    $combinedTrend = [];
    $allDates = array_unique(array_merge(array_column($revenueTrend, 'date'), array_column($costTrend, 'date')));
    sort($allDates);
    foreach ($allDates as $date) {
        $revenueVal = 0;
        $costVal = 0;
        foreach ($revenueTrend as $rt) {
            if ($rt['date'] == $date) { $revenueVal = $rt['revenue']; break; }
        }
        foreach ($costTrend as $ct) {
            if ($ct['date'] == $date) { $costVal = $ct['cost']; break; }
        }
        $combinedTrend[] = [
            'date'    => $date,
            'revenue' => $revenueVal,
            'cost'    => $costVal,
            'profit'  => $revenueVal - $costVal
        ];
    }

    return [
        'revenue'        => $revenue,
        'costs'          => $costs,
        'profit'         => $profit,
        'profit_margin'  => $profitMargin,
        'cost_breakdown' => $costBreakdown,
        'trend'          => $combinedTrend,
        'period'         => [
            'timeRange' => $timeRange,
            'startDate' => $startDate,
            'endDate'   => $endDate
        ]
    ];
}

/**
 * Build SQL date condition for analytics.
 *
 * @param string $timeRange
 * @param string $startDate
 * @param string $endDate
 * @return string SQL condition
 */
function getAnalyticsDateCondition($timeRange, $startDate, $endDate) {
    $condition = "";

    switch ($timeRange) {
        case 'today':
            $condition = "AND DATE(created_at) = CURDATE()";
            break;
        case '7days':
            $condition = "AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case '30days':
            $condition = "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'this_month':
            $condition = "AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())";
            break;
        case 'last_month':
            $condition = "AND YEAR(created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH) AND MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH)";
            break;
        case 'custom':
            if ($startDate && $endDate) {
                $start = date('Y-m-d 00:00:00', strtotime($startDate));
                $end   = date('Y-m-d 23:59:59', strtotime($endDate));
                $condition = "AND created_at BETWEEN '$start' AND '$end'";
            }
            break;
    }

    return $condition;
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
            date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_category (category),
            INDEX idx_date (date)
        )
    ");
    $tableChecked = true;
}
?>