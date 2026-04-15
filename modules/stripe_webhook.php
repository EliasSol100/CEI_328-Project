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

function stripeWebhookRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    stripeWebhookRespond(405, [
        'ok' => false,
        'message' => 'Method not allowed.',
    ]);
}

if (!$conn || $conn->connect_error) {
    stripeWebhookRespond(500, [
        'ok' => false,
        'message' => 'Database connection failed.',
    ]);
}

$payload = file_get_contents('php://input');
$payload = is_string($payload) ? $payload : '';
$signatureHeader = trim((string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));

try {
    $event = payment_gateway_verify_stripe_webhook_event($conn, $payload, $signatureHeader);
} catch (Throwable $e) {
    error_log('Stripe webhook verification failed: ' . $e->getMessage());
    stripeWebhookRespond(400, [
        'ok' => false,
        'message' => 'Webhook verification failed.',
    ]);
}

$eventType = strtolower(trim((string)($event['type'] ?? '')));
if ($eventType !== 'checkout.session.completed') {
    stripeWebhookRespond(200, [
        'ok' => true,
        'ignored' => true,
        'type' => $eventType,
    ]);
}

$session = $event['data']['object'] ?? [];
if (!is_array($session)) {
    stripeWebhookRespond(400, [
        'ok' => false,
        'message' => 'Stripe webhook did not include a valid session object.',
    ]);
}

$checkoutToken = trim((string)($session['client_reference_id'] ?? ($session['metadata']['checkout_token'] ?? '')));
$sessionId = trim((string)($session['id'] ?? ''));
$pending = null;

if ($checkoutToken !== '') {
    $pending = checkout_payment_get_pending($conn, $checkoutToken);
}
if (!$pending && $sessionId !== '') {
    $pending = checkout_payment_get_pending_by_gateway_reference($conn, 'stripe', $sessionId);
}
if (!$pending) {
    stripeWebhookRespond(200, [
        'ok' => true,
        'ignored' => true,
        'reason' => 'pending_checkout_not_found',
        'session_id' => $sessionId,
    ]);
}

$checkoutToken = trim((string)($pending['checkout_token'] ?? $checkoutToken));
$existingResult = checkout_payment_get_finalized_result($pending);
if (is_array($existingResult) && !empty($existingResult['order_id'])) {
    stripeWebhookRespond(200, [
        'ok' => true,
        'duplicate' => true,
        'order_id' => (int)$existingResult['order_id'],
    ]);
}

if ($sessionId !== '') {
    checkout_payment_update_gateway_reference($conn, $checkoutToken, 'stripe', $sessionId);
}

$paymentStatus = strtolower(trim((string)($session['payment_status'] ?? '')));
if ($paymentStatus !== 'paid') {
    stripeWebhookRespond(200, [
        'ok' => true,
        'ignored' => true,
        'reason' => 'payment_not_paid',
        'payment_status' => $paymentStatus,
    ]);
}

try {
    $expectedAmount = payment_gateway_encode_cents((float)($pending['total_amount'] ?? 0));
    $sessionAmount = (int)($session['amount_total'] ?? 0);
    if ($expectedAmount > 0 && $sessionAmount !== $expectedAmount) {
        throw new RuntimeException('Stripe webhook amount does not match the pending checkout total.');
    }

    $transactionId = trim((string)($session['payment_intent'] ?? $sessionId));
    if ($transactionId === '') {
        $transactionId = 'STRIPE_' . strtoupper(bin2hex(random_bytes(5)));
    }

    $result = checkout_payment_finalize_paid_order($conn, $pending, 'stripe', $transactionId);

    stripeWebhookRespond(200, [
        'ok' => true,
        'finalized' => true,
        'order_id' => (int)($result['order_id'] ?? 0),
        'order_number' => (string)($result['order_number'] ?? ''),
    ]);
} catch (Throwable $e) {
    error_log('Stripe webhook processing failed: ' . $e->getMessage());
    checkout_payment_mark_failed($conn, $checkoutToken, 'stripe', $e->getMessage());

    stripeWebhookRespond(500, [
        'ok' => false,
        'message' => 'Stripe webhook processing failed.',
    ]);
}
