<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
session_start();
define('INCLUDE_CHECK', true);

require_once __DIR__ . '/../authentication/database.php';
require_once __DIR__ . '/../include/loyalty_program.php';
require_once __DIR__ . '/../include/payment_gateway.php';
require_once __DIR__ . '/../include/checkout_payment_helpers.php';
require_once __DIR__ . '/place_order.php';

header('Content-Type: application/json; charset=UTF-8');

function paypalWebhookRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function paypalWebhookLoadPending(mysqli $conn, string $checkoutToken, string $gatewayReference): ?array
{
    if ($checkoutToken !== '') {
        $pending = checkout_payment_get_pending($conn, $checkoutToken);
        if ($pending) {
            return $pending;
        }
    }

    if ($gatewayReference !== '') {
        return checkout_payment_get_pending_by_gateway_reference($conn, 'paypal', $gatewayReference);
    }

    return null;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    paypalWebhookRespond(405, [
        'ok' => false,
        'message' => 'Method not allowed.',
    ]);
}

if (!$conn || $conn->connect_error) {
    paypalWebhookRespond(500, [
        'ok' => false,
        'message' => 'Database connection failed.',
    ]);
}

$payload = file_get_contents('php://input');
$payload = is_string($payload) ? $payload : '';

try {
    $event = payment_gateway_verify_paypal_webhook_event($conn, $_SERVER, $payload);
} catch (Throwable $e) {
    error_log('PayPal webhook verification failed: ' . $e->getMessage());
    paypalWebhookRespond(400, [
        'ok' => false,
        'message' => 'Webhook verification failed.',
    ]);
}

$eventType = strtoupper(trim((string)($event['event_type'] ?? '')));
$resource = $event['resource'] ?? [];
if (!is_array($resource)) {
    paypalWebhookRespond(400, [
        'ok' => false,
        'message' => 'PayPal webhook did not include a valid resource.',
    ]);
}

$purchaseUnit = $resource['purchase_units'][0] ?? [];
$checkoutToken = trim((string)($purchaseUnit['custom_id'] ?? $purchaseUnit['reference_id'] ?? ($resource['custom_id'] ?? '')));
$orderId = '';

if (str_starts_with($eventType, 'CHECKOUT.ORDER.')) {
    $orderId = trim((string)($resource['id'] ?? ''));
} else {
    $orderId = trim((string)($resource['supplementary_data']['related_ids']['order_id'] ?? ''));
}

$pending = paypalWebhookLoadPending($conn, $checkoutToken, $orderId);
if (!$pending) {
    paypalWebhookRespond(200, [
        'ok' => true,
        'ignored' => true,
        'reason' => 'pending_checkout_not_found',
        'event_type' => $eventType,
        'order_id' => $orderId,
    ]);
}

$checkoutToken = trim((string)($pending['checkout_token'] ?? $checkoutToken));
$existingResult = checkout_payment_get_finalized_result($pending);
if (is_array($existingResult) && !empty($existingResult['order_id'])) {
    paypalWebhookRespond(200, [
        'ok' => true,
        'duplicate' => true,
        'order_id' => (int)$existingResult['order_id'],
    ]);
}

if ($orderId !== '') {
    checkout_payment_update_gateway_reference($conn, $checkoutToken, 'paypal', $orderId);
}

try {
    if ($eventType === 'CHECKOUT.ORDER.APPROVED') {
        if ($orderId === '') {
            throw new RuntimeException('PayPal did not provide an approved order id.');
        }

        $capture = payment_gateway_capture_paypal_order($conn, $orderId);
        $captureStatus = strtoupper(trim((string)($capture['status'] ?? '')));
        if ($captureStatus !== 'COMPLETED') {
            throw new RuntimeException('PayPal order capture did not complete successfully.');
        }

        $captureInfo = $capture['purchase_units'][0]['payments']['captures'][0] ?? [];
        $capturedAmount = (float)($captureInfo['amount']['value'] ?? 0);
        $expectedAmount = (float)($pending['total_amount'] ?? 0);
        if ($expectedAmount > 0 && abs($capturedAmount - $expectedAmount) > 0.01) {
            throw new RuntimeException('PayPal webhook amount does not match the pending checkout total.');
        }

        $transactionId = trim((string)($captureInfo['id'] ?? $capture['id'] ?? $orderId));
        if ($transactionId === '') {
            $transactionId = 'PAYPAL_' . strtoupper(bin2hex(random_bytes(5)));
        }

        $result = checkout_payment_finalize_paid_order($conn, $pending, 'paypal', $transactionId);

        paypalWebhookRespond(200, [
            'ok' => true,
            'finalized' => true,
            'event_type' => $eventType,
            'order_id' => (int)($result['order_id'] ?? 0),
            'order_number' => (string)($result['order_number'] ?? ''),
        ]);
    }

    if ($eventType === 'CHECKOUT.ORDER.COMPLETED' || $eventType === 'PAYMENT.CAPTURE.COMPLETED') {
        $captureInfo = $eventType === 'CHECKOUT.ORDER.COMPLETED'
            ? ($resource['purchase_units'][0]['payments']['captures'][0] ?? [])
            : $resource;

        $capturedAmount = (float)($captureInfo['amount']['value'] ?? 0);
        $expectedAmount = (float)($pending['total_amount'] ?? 0);
        if ($expectedAmount > 0 && $capturedAmount > 0 && abs($capturedAmount - $expectedAmount) > 0.01) {
            throw new RuntimeException('PayPal webhook amount does not match the pending checkout total.');
        }

        $transactionId = trim((string)($captureInfo['id'] ?? $orderId));
        if ($transactionId === '') {
            $transactionId = 'PAYPAL_' . strtoupper(bin2hex(random_bytes(5)));
        }

        $result = checkout_payment_finalize_paid_order($conn, $pending, 'paypal', $transactionId);

        paypalWebhookRespond(200, [
            'ok' => true,
            'finalized' => true,
            'event_type' => $eventType,
            'order_id' => (int)($result['order_id'] ?? 0),
            'order_number' => (string)($result['order_number'] ?? ''),
        ]);
    }

    if ($eventType === 'CHECKOUT.PAYMENT-APPROVAL.REVERSED' || $eventType === 'CHECKOUT.ORDER.DECLINED' || $eventType === 'PAYMENT.CAPTURE.DENIED') {
        $reason = trim((string)($resource['status_details']['reason'] ?? ($resource['status'] ?? 'Payment failed')));
        checkout_payment_mark_failed($conn, $checkoutToken, 'paypal', $reason, 'failed');

        paypalWebhookRespond(200, [
            'ok' => true,
            'updated' => true,
            'event_type' => $eventType,
            'status' => 'failed',
        ]);
    }

    if ($eventType === 'PAYMENT.CAPTURE.PENDING') {
        paypalWebhookRespond(200, [
            'ok' => true,
            'ignored' => true,
            'event_type' => $eventType,
            'status' => 'pending',
        ]);
    }

    paypalWebhookRespond(200, [
        'ok' => true,
        'ignored' => true,
        'event_type' => $eventType,
    ]);
} catch (Throwable $e) {
    error_log('PayPal webhook processing failed: ' . $e->getMessage());
    checkout_payment_mark_failed($conn, $checkoutToken, 'paypal', $e->getMessage());

    paypalWebhookRespond(500, [
        'ok' => false,
        'message' => 'PayPal webhook processing failed.',
    ]);
}
