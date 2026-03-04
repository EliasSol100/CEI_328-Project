<?php
/**
 * Dashboard KPIs Module
 *
 * Implements function 3.2.6.9: View Dashboard KPIs & Sales Trend Chart
 *
 * @package CreationsByAthina
 */

// Prevent direct access
if (!defined('INCLUDE_CHECK') && !defined('DASHBOARD_KPIS_DIRECT')) {
    die('Direct access not permitted');
}

/**
 * Get dashboard KPIs and sales trend data.
 *
 * @param mysqli   $conn       Database connection
 * @param string   $timeRange  Time range: 'today', '7days', '30days', 'this_month', 'last_month', 'custom'
 * @param string   $startDate  Start date (Y-m-d) for custom range (optional)
 * @param string   $endDate    End date (Y-m-d) for custom range (optional)
 * @return array               Array with KPIs and chart data
 * @throws Exception On database error
 */
function getDashboardKPIs($conn, $timeRange = '30days', $startDate = null, $endDate = null) {
    // Calculate date range based on $timeRange
    $dateCondition = getDateCondition($timeRange, $startDate, $endDate);

    // 1. Total sales (sum of totalAmount for paid orders)
    $totalSales = 0;
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(totalAmount), 0) as total_sales
        FROM orders
        WHERE payment_status IN ('paid', 'confirmed') AND status != 'cancelled'
        $dateCondition
    ");
    if (!$stmt) throw new Exception("Failed to prepare total sales: " . $conn->error);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $totalSales = (float)$row['total_sales'];
    $stmt->close();

    // 2. Recent orders count
    $recentOrders = 0;
    $stmt = $conn->prepare("
        SELECT COUNT(*) as order_count
        FROM orders
        WHERE payment_status IN ('paid', 'confirmed') AND status != 'cancelled'
        $dateCondition
    ");
    if (!$stmt) throw new Exception("Failed to prepare order count: " . $conn->error);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $recentOrders = (int)$row['order_count'];
    $stmt->close();

    // 3. Top product (best seller) by quantity sold in the period
    $topProduct = [
        'product_id'   => null,
        'product_name' => 'None',
        'quantity'     => 0
    ];
    $stmt = $conn->prepare("
        SELECT oi.productID, p.nameEN, p.nameGR, SUM(oi.quantity) as total_qty
        FROM order_items oi
        JOIN orders o ON o.orderID = oi.orderID
        LEFT JOIN products p ON p.productID = oi.productID
        WHERE o.payment_status IN ('paid', 'confirmed') AND o.status != 'cancelled'
        $dateCondition
        GROUP BY oi.productID
        ORDER BY total_qty DESC
        LIMIT 1
    ");
    if (!$stmt) throw new Exception("Failed to prepare top product: " . $conn->error);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $topProduct['product_id'] = (int)$row['productID'];
        $topProduct['product_name'] = $row['nameEN'] ?: $row['nameGR'] ?: "Product #{$row['productID']}";
        $topProduct['quantity'] = (int)$row['total_qty'];
    }
    $stmt->close();

    // 4. Low stock alerts count (variations where quantityAvailable <= lowStockThreshold)
    $lowStockCount = 0;
    $stmt = $conn->prepare("
        SELECT COUNT(*) as low_stock_count
        FROM variation_stock
        WHERE quantityAvailable <= lowStockThreshold AND lowStockThreshold > 0
    ");
    if (!$stmt) throw new Exception("Failed to prepare low stock count: " . $conn->error);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $lowStockCount = (int)$row['low_stock_count'];
    $stmt->close();

    // 5. Daily sales for trend chart
    $dailySales = [];
    $stmt = $conn->prepare("
        SELECT DATE(created_at) as sale_date, COALESCE(SUM(totalAmount), 0) as daily_total
        FROM orders
        WHERE payment_status IN ('paid', 'confirmed') AND status != 'cancelled'
        $dateCondition
        GROUP BY DATE(created_at)
        ORDER BY sale_date ASC
    ");
    if (!$stmt) throw new Exception("Failed to prepare daily sales: " . $conn->error);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $dailySales[] = [
            'date'  => $row['sale_date'],
            'total' => (float)$row['daily_total']
        ];
    }
    $stmt->close();

    // 6. Average order value
    $avgOrderValue = $recentOrders > 0 ? $totalSales / $recentOrders : 0;

    // Return all metrics
    return [
        'total_sales'       => $totalSales,
        'order_count'       => $recentOrders,
        'avg_order_value'   => $avgOrderValue,
        'top_product'       => $topProduct,
        'low_stock_count'   => $lowStockCount,
        'daily_sales'       => $dailySales,
        'period'            => [
            'timeRange' => $timeRange,
            'startDate' => $startDate,
            'endDate'   => $endDate
        ]
    ];
}

/**
 * Build SQL date condition based on time range.
 *
 * @param string $timeRange
 * @param string $startDate
 * @param string $endDate
 * @return string SQL condition (e.g., "AND created_at >= ...")
 */
function getDateCondition($timeRange, $startDate, $endDate) {
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
?>