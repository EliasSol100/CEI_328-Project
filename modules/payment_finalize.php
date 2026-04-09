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

$project = payment_gateway_project_path();
$provider = strtolower(trim((string)($_GET['provider'] ?? '')));
$checkoutToken = trim((string)($_GET['checkout_token'] ?? ''));

function paymentFinalizeFail(string $project, string $message, string $provider = ''): void
{
    $_SESSION['checkout_failed'] = [
        'message' => $message,
        'provider' => $provider,
    ];
    header('Location: ' . $project . '/modules/checkout_failed.php' . ($provider !== '' ? '?provider=' . rawurlencode($provider) : ''));
    exit;
}

if (!$conn || $conn->connect_error) {
    paymentFinalizeFail($project, 'Database connection failed while completing your payment.', $provider);
}

$pending = checkout_payment_get_pending($checkoutToken);
if (!$pending) {
    paymentFinalizeFail($project, 'Your payment session expired or could not be found. Please try checkout again.', $provider);
}

if ($provider === '' || trim((string)($pending['payment_method'] ?? '')) !== $provider) {
    paymentFinalizeFail($project, 'The payment provider returned an unexpected response. Please try again.', $provider);
}

try {
    $transactionId = '';

    if ($provider === 'stripe') {
        $sessionId = trim((string)($_GET['session_id'] ?? ''));
        if ($sessionId === '') {
            throw new RuntimeException('Stripe did not return a checkout session id.');
        }

        $session = payment_gateway_fetch_stripe_checkout_session($conn, $sessionId);
        $sessionReference = trim((string)($session['client_reference_id'] ?? ''));
        if ($sessionReference !== $checkoutToken) {
            throw new RuntimeException('Stripe returned a session that does not match this checkout.');
        }

        $expectedSessionId = trim((string)($pending['gateway_reference'] ?? ''));
        if ($expectedSessionId !== '' && $expectedSessionId !== trim((string)($session['id'] ?? ''))) {
            throw new RuntimeException('Stripe returned an unexpected checkout session.');
        }

        if (strtolower(trim((string)($session['payment_status'] ?? ''))) !== 'paid') {
            throw new RuntimeException('Stripe payment was not completed.');
        }

        $expectedAmount = payment_gateway_encode_cents((float)($pending['total_amount'] ?? 0));
        $sessionAmount = (int)($session['amount_total'] ?? 0);
        if ($expectedAmount > 0 && $sessionAmount !== $expectedAmount) {
            throw new RuntimeException('Stripe returned a different amount than expected for this order.');
        }

        $transactionId = trim((string)($session['payment_intent'] ?? $session['id'] ?? ''));
        if ($transactionId === '') {
            $transactionId = 'STRIPE_' . strtoupper(bin2hex(random_bytes(5)));
        }
    } elseif ($provider === 'paypal') {
        $orderId = trim((string)($_GET['token'] ?? ''));
        if ($orderId === '') {
            throw new RuntimeException('PayPal did not return an order token.');
        }

        $expectedOrderId = trim((string)($pending['gateway_reference'] ?? ''));
        if ($expectedOrderId !== '' && $expectedOrderId !== $orderId) {
            throw new RuntimeException('PayPal returned an unexpected order reference.');
        }

        $capture = payment_gateway_capture_paypal_order($conn, $orderId);
        if (strtoupper(trim((string)($capture['status'] ?? ''))) !== 'COMPLETED') {
            throw new RuntimeException('PayPal payment was not completed.');
        }

        $captureInfo = $capture['purchase_units'][0]['payments']['captures'][0] ?? [];
        $capturedAmount = (float)($captureInfo['amount']['value'] ?? 0);
        $expectedAmount = (float)($pending['total_amount'] ?? 0);
        if ($expectedAmount > 0 && abs($capturedAmount - $expectedAmount) > 0.01) {
            throw new RuntimeException('PayPal returned a different amount than expected for this order.');
        }

        $transactionId = trim((string)($captureInfo['id'] ?? $capture['id'] ?? ''));
        if ($transactionId === '') {
            $transactionId = 'PAYPAL_' . strtoupper(bin2hex(random_bytes(5)));
        }
    } else {
        throw new RuntimeException('Unsupported payment provider.');
    }

    checkout_payment_finalize_paid_order($conn, $pending, $provider, $transactionId);
    header('Location: ' . $project . '/modules/checkout_success.php');
    exit;
} catch (Throwable $e) {
    error_log('Payment finalize error: ' . $e->getMessage());
    paymentFinalizeFail($project, 'Your payment could not be confirmed. Please try again or return to checkout.', $provider);
}
