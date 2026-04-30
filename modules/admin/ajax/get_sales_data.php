<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$autoSalesMap = [];
$salesRes = mysqli_query($conn,
    "SELECT productID, COALESCE(SUM(quantity), 0) AS total_qty FROM order_items GROUP BY productID"
);
if ($salesRes) {
    while ($row = mysqli_fetch_assoc($salesRes)) {
        $autoSalesMap[(int)$row['productID']] = (int)$row['total_qty'];
    }
}

$manualSalesMap = [];
$msRes = mysqli_query($conn, "SELECT productID, manual_total_sales FROM product_sales_overrides");
if ($msRes) {
    while ($row = mysqli_fetch_assoc($msRes)) {
        $manualSalesMap[(int)$row['productID']] = (int)$row['manual_total_sales'];
    }
}

$result = [];
$productsRes = mysqli_query($conn, "SELECT productID FROM products");
if ($productsRes) {
    while ($row = mysqli_fetch_assoc($productsRes)) {
        $pid = (int)$row['productID'];
        $result[$pid] = array_key_exists($pid, $manualSalesMap)
            ? $manualSalesMap[$pid]
            : ($autoSalesMap[$pid] ?? 0);
    }
}

echo json_encode($result);
