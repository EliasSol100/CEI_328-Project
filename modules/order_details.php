<?php
/**
 * Order Details Module
 *
 * Implements function 3.2.6.15: View Order Details (Including Gift Options & Variations)
 *
 * @package CreationsByAthina
 */

// Prevent direct access
if (!defined('INCLUDE_CHECK') && !defined('ORDER_DETAILS_DIRECT')) {
    die('Direct access not permitted');
}

/**
 * Get full order details including items, variations, gift options, payment, shipping.
 *
 * @param mysqli $conn     Database connection
 * @param int    $orderId  Order ID (order_id column in orders table)
 * @return array           Associative array with order details
 * @throws Exception       If order not found or database error
 */
function getOrderDetails($conn, $orderId) {
    // 1. Fetch order header with customer and guest info
    $stmt = $conn->prepare("
        SELECT 
            o.order_id,
            o.orderNumber,
            o.user_id,
            o.isGuestFlag,
            o.guest_name,
            o.guest_email,
            o.guest_phone,
            o.shipping_address,
            o.shipping_city,
            o.shipping_postal_code,
            o.shipping_country,
            o.courier,
            o.shipping_speed,
            o.free_shipping,
            o.payment_method,
            o.transaction_id,
            o.status as order_status,
            o.payment_status,
            o.subtotal,
            o.discountTotal,
            o.shipping_cost,
            o.total_amount,
            o.created_at,
            u.full_name as customer_name,
            u.email as customer_email,
            u.phone as customer_phone
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.order_id = ?
    ");
    if (!$stmt) {
        throw new Exception("Failed to prepare order header query: " . $conn->error);
    }
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception("Order #$orderId not found.");
    }
    $order = $result->fetch_assoc();
    $stmt->close();

    // 2. Build customer info array
    $customer = [];
    if ($order['isGuestFlag']) {
        $customer = [
            'type'  => 'guest',
            'name'  => $order['guest_name'],
            'email' => $order['guest_email'],
            'phone' => $order['guest_phone']
        ];
    } else {
        $customer = [
            'type'    => 'registered',
            'user_id' => (int)$order['user_id'],
            'name'    => $order['customer_name'],
            'email'   => $order['customer_email'],
            'phone'   => $order['customer_phone']
        ];
    }

    // 3. Shipping info (from orders + shipments)
    $shipping = [
        'address'       => $order['shipping_address'],
        'city'          => $order['shipping_city'],
        'postal_code'   => $order['shipping_postal_code'],
        'country'       => $order['shipping_country'],
        'courier'       => $order['courier'],
        'speed'         => $order['shipping_speed'],
        'free_shipping' => (bool)$order['free_shipping'],
        'cost'          => (float)$order['shipping_cost']
    ];

    // Add tracking info from shipments table if available
    $stmt = $conn->prepare("
        SELECT courierName, totalWeightKG, shippingCost, trackingCode
        FROM shipments
        WHERE orderID = ?
    ");
    if ($stmt) {
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $shipResult = $stmt->get_result();
        if ($row = $shipResult->fetch_assoc()) {
            $shipping['tracking_code'] = $row['trackingCode'];
            $shipping['weight_kg']     = (float)$row['totalWeightKG'];
            // Use shipment's courierName if different? We'll keep order's courier.
        }
        $stmt->close();
    }

    // 4. Payment info from payments table
    $payments = [];
    $stmt = $conn->prepare("
        SELECT 
            paymentID,
            provider,
            transactionID,
            paymentStatus,
            amount,
            currency,
            timestamp as payment_date
        FROM payments
        WHERE orderID = ?
        ORDER BY timestamp DESC
    ");
    if ($stmt) {
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $payResult = $stmt->get_result();
        while ($row = $payResult->fetch_assoc()) {
            $payments[] = [
                'id'            => (int)$row['paymentID'],
                'provider'      => $row['provider'],
                'transaction_id'=> $row['transactionID'],
                'status'        => $row['paymentStatus'],
                'amount'        => (float)$row['amount'],
                'currency'      => $row['currency'],
                'date'          => $row['payment_date']
            ];
        }
        $stmt->close();
    }

    // 5. Order items with variations, gift options, and addons
    $items = [];
    $stmt = $conn->prepare("
        SELECT 
            oi.item_id,
            oi.product_id,
            oi.product_name,
            oi.variation_id,
            oi.variation_details,
            oi.quantity,
            oi.price,
            oi.giftWrapping,
            oi.giftBagFlag,
            oi.giftMessage,
            oi.addons,
            p.nameEN,
            p.nameGR,
            p.sku
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.productID
        WHERE oi.order_id = ?
    ");
    if (!$stmt) {
        throw new Exception("Failed to prepare order items query: " . $conn->error);
    }
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $itemsResult = $stmt->get_result();
    while ($row = $itemsResult->fetch_assoc()) {
        // Decode JSON fields
        $variationDetails = null;
        if (!empty($row['variation_details'])) {
            $variationDetails = json_decode($row['variation_details'], true);
        }
        $addons = null;
        if (!empty($row['addons'])) {
            $addons = json_decode($row['addons'], true);
        }

        $items[] = [
            'item_id'           => (int)$row['item_id'],
            'product_id'        => (int)$row['product_id'],
            'product_name'      => $row['product_name'] ?: ($row['nameEN'] ?: $row['nameGR']),
            'sku'               => $row['sku'],
            'variation_id'      => $row['variation_id'] ? (int)$row['variation_id'] : null,
            'variation_details' => $variationDetails,
            'quantity'          => (int)$row['quantity'],
            'price'             => (float)$row['price'],
            'line_total'        => (float)$row['price'] * (int)$row['quantity'],
            'gift_wrapping'     => (bool)($row['giftWrapping'] ?? false),
            'gift_bag'          => (bool)($row['giftBagFlag'] ?? false),
            'gift_message'      => $row['giftMessage'] ?? null,
            'addons'            => $addons
        ];
    }
    $stmt->close();

    // 6. Order totals
    $totals = [
        'subtotal'      => (float)$order['subtotal'],
        'discount'      => (float)($order['discountTotal'] ?? 0),
        'shipping_cost' => (float)$order['shipping_cost'],
        'total'         => (float)$order['total_amount']
    ];

    // 7. Build final array
    return [
        'order_id'          => (int)$order['order_id'],
        'order_number'      => $order['orderNumber'] ?? null,
        'created_at'        => $order['created_at'],
        'status'            => $order['order_status'],
        'payment_status'    => $order['payment_status'],
        'customer'          => $customer,
        'shipping'          => $shipping,
        'payments'          => $payments,
        'items'             => $items,
        'totals'            => $totals,
        'payment_method'    => $order['payment_method'],
        'transaction_id'    => $order['transaction_id']
    ];
}
?>