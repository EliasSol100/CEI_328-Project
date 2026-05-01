<?php

if (!function_exists('app_order_tracking_table_exists')) {
    function app_order_tracking_table_exists(mysqli $conn, string $tableName): bool
    {
        $safeTable = mysqli_real_escape_string($conn, $tableName);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '" . $safeTable . "'");
        return (bool)($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('app_order_tracking_column_exists')) {
    function app_order_tracking_column_exists(mysqli $conn, string $tableName, string $columnName): bool
    {
        if (!app_order_tracking_table_exists($conn, $tableName)) {
            return false;
        }

        $safeColumn = mysqli_real_escape_string($conn, $columnName);
        $safeTable = str_replace('`', '``', $tableName);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '" . $safeColumn . "'");
        return (bool)($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('app_order_tracking_ensure_schema')) {
    function app_order_tracking_ensure_schema(mysqli $conn): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS shipments (
                shipmentID INT AUTO_INCREMENT PRIMARY KEY,
                orderID INT NOT NULL,
                courierName VARCHAR(100) DEFAULT NULL,
                totalWeightKG DOUBLE DEFAULT NULL,
                shippingCost DECIMAL(10,2) DEFAULT 0.00,
                trackingCode VARCHAR(100) DEFAULT NULL,
                tracking_number VARCHAR(100) DEFAULT NULL,
                KEY idx_shipments_order (orderID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        if (!app_order_tracking_column_exists($conn, 'shipments', 'trackingCode')) {
            mysqli_query($conn, "ALTER TABLE shipments ADD COLUMN trackingCode VARCHAR(100) NULL DEFAULT NULL");
        }
        if (!app_order_tracking_column_exists($conn, 'shipments', 'totalWeightKG')) {
            mysqli_query($conn, "ALTER TABLE shipments ADD COLUMN totalWeightKG DOUBLE NULL DEFAULT NULL AFTER courierName");
        }
        if (!app_order_tracking_column_exists($conn, 'shipments', 'tracking_number')) {
            $after = app_order_tracking_column_exists($conn, 'shipments', 'trackingCode') ? ' AFTER trackingCode' : '';
            mysqli_query($conn, "ALTER TABLE shipments ADD COLUMN tracking_number VARCHAR(100) NULL DEFAULT NULL{$after}");
        }
    }
}

if (!function_exists('app_order_tracking_value_sql')) {
    function app_order_tracking_value_sql(string $alias = 's'): string
    {
        $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 's';
        return "COALESCE({$alias}.tracking_number, {$alias}.trackingCode, '')";
    }
}

if (!function_exists('app_order_tracking_save')) {
    function app_order_tracking_save(mysqli $conn, int $orderId, string $trackingNumber, string $courierLabel = ''): bool
    {
        app_order_tracking_ensure_schema($conn);

        if ($orderId <= 0) {
            return false;
        }

        $trackingNumber = trim($trackingNumber);
        $trackingValue = $trackingNumber !== '' ? $trackingNumber : null;
        $resolvedCourier = trim($courierLabel);

        $shipmentId = 0;
        $sel = mysqli_prepare($conn, "SELECT shipmentID FROM shipments WHERE orderID = ? LIMIT 1");
        if ($sel) {
            mysqli_stmt_bind_param($sel, 'i', $orderId);
            mysqli_stmt_execute($sel);
            $res = mysqli_stmt_get_result($sel);
            if ($res && ($row = mysqli_fetch_assoc($res))) {
                $shipmentId = (int)($row['shipmentID'] ?? 0);
            }
            mysqli_stmt_close($sel);
        }

        if ($shipmentId > 0) {
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE shipments
                 SET tracking_number = ?, trackingCode = ?, courierName = COALESCE(NULLIF(courierName, ''), NULLIF(?, ''))
                 WHERE shipmentID = ?"
            );
            if (!$stmt) {
                return false;
            }
            mysqli_stmt_bind_param($stmt, 'sssi', $trackingValue, $trackingValue, $resolvedCourier, $shipmentId);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            return (bool)$ok;
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO shipments (orderID, courierName, shippingCost, trackingCode, tracking_number)
             VALUES (?, NULLIF(?, ''), 0, ?, ?)"
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'isss', $orderId, $resolvedCourier, $trackingValue, $trackingValue);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return (bool)$ok;
    }
}

if (!function_exists('app_order_tracking_mark_shipped')) {
    function app_order_tracking_mark_shipped(mysqli $conn, int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        $stmt = mysqli_prepare($conn, "UPDATE orders SET status = 'shipped' WHERE orderID = ?");
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'i', $orderId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return (bool)$ok;
    }
}

if (!function_exists('app_order_tracking_receipt_url')) {
    function app_order_tracking_receipt_url(int $orderId): string
    {
        $query = 'order_id=' . rawurlencode((string)$orderId);
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return 'modules/receipt.php?' . $query;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
        $scheme = $isHttps ? 'https' : 'http';
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = '';
        $modulesPos = strpos($script, '/modules/');
        if ($modulesPos !== false) {
            $basePath = substr($script, 0, $modulesPos);
        } else {
            $basePath = rtrim(str_replace('\\', '/', dirname($script)), '/');
        }

        return $scheme . '://' . $host . rtrim($basePath, '/') . '/modules/receipt.php?' . $query;
    }
}

if (!function_exists('app_order_tracking_customer_context')) {
    function app_order_tracking_customer_context(mysqli $conn, int $orderId): ?array
    {
        app_order_tracking_ensure_schema($conn);

        if ($orderId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                o.orderID,
                o.orderNumber,
                o.status,
                o.email AS orderEmail,
                o.userID,
                o.courierCode,
                o.shippingPriority,
                COALESCE(NULLIF(u.full_name, ''), 'Customer') AS customerName,
                COALESCE(NULLIF(u.email, ''), NULLIF(o.email, ''), '') AS customerEmail,
                COALESCE(s.courierName, '') AS shipmentCourierName,
                " . app_order_tracking_value_sql('s') . " AS trackingCode
            FROM orders o
            LEFT JOIN users u ON u.userID = o.userID
            LEFT JOIN shipments s ON s.orderID = o.orderID
            WHERE o.orderID = ?
            LIMIT 1
        ";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return null;
        }

        mysqli_stmt_bind_param($stmt, 'i', $orderId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        return $row ?: null;
    }
}

if (!function_exists('app_order_tracking_send_shipped_receipt_email')) {
    function app_order_tracking_send_shipped_receipt_email(mysqli $conn, int $orderId): array
    {
        $context = app_order_tracking_customer_context($conn, $orderId);
        if (!$context) {
            return [
                'success' => false,
                'message' => 'Order not found.',
            ];
        }

        $trackingCode = trim((string)($context['trackingCode'] ?? ''));
        if ($trackingCode === '') {
            return [
                'success' => false,
                'message' => 'Tracking number is empty.',
            ];
        }

        $customerEmail = trim((string)($context['customerEmail'] ?? ''));
        if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Customer email is missing or invalid.',
            ];
        }

        require_once __DIR__ . '/../authentication/auth_mailer.php';

        $customerName = trim((string)($context['customerName'] ?? 'Customer'));
        $orderNumber = trim((string)($context['orderNumber'] ?? ('#' . $orderId)));
        $courierName = trim((string)($context['shipmentCourierName'] ?? ''));
        if ($courierName === '') {
            $courierName = 'Courier';
        }
        $receiptUrl = app_order_tracking_receipt_url($orderId);

        $subject = 'Your order ' . $orderNumber . ' has shipped';
        $body =
            "Hello {$customerName},\n\n" .
            "Good news - your order {$orderNumber} has been shipped.\n\n" .
            "Tracking number: {$trackingCode}\n" .
            "Courier: {$courierName}\n\n" .
            "Your receipt has also been updated with this tracking number:\n" .
            "{$receiptUrl}\n\n" .
            "Thank you,\n" .
            "Athina E-Shop";

        return app_auth_send_plaintext_email($customerEmail, $customerName, $subject, $body);
    }
}
