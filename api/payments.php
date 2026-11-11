<?php
/**
 * Client-side payment confirmation.
 *
 * Razorpay (Checkout handler):
 *   POST payments.php?action=verify    razorpay_order_id, razorpay_payment_id, razorpay_signature
 *   POST payments.php?action=failed    razorpay_order_id, error details (for the record only - the
 *                                      "payment not completed" email is sent later by the scheduler,
 *                                      only if the order is still unpaid 15 minutes after the failure)
 *   Access is granted only after the HMAC signature is verified with the secret key.
 *
 * Cashfree (payment result page after the hosted checkout redirects back):
 *   POST payments.php?action=verify    order_number
 *                                      -> {order_number, provider, status, state, payment_status, next_url, invoice}
 *   The server asks Cashfree (Get Order + Get Payments) and fulfils only a PAID order with a SUCCESS
 *   payment of exactly the order total - nothing from the browser or the return URL is trusted.
 *
 * The webhook (webhooks.php) is the backup path if the browser never returns.
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/payments.php';

require_method('POST');
require_csrf();
$user = require_student();
$in = request_body();

$providerOrderId = is_string($in['razorpay_order_id'] ?? null) ? $in['razorpay_order_id'] : '';
$orderNumber = is_string($in['order_number'] ?? null) ? strtoupper(trim($in['order_number'])) : '';
if ($providerOrderId !== '') {
    $order = db_one("SELECT * FROM orders WHERE provider = 'razorpay' AND provider_order_id = ? AND user_id = ?", [$providerOrderId, $user['id']]);
} elseif (preg_match('/^[A-Z0-9]{6,30}$/', $orderNumber)) {
    $order = db_one('SELECT * FROM orders WHERE order_number = ? AND user_id = ?', [$orderNumber, $user['id']]);
} else {
    $order = null;
}
if (!$order) {
    api_error('Order not found.', 404, 'not_found');
}

$action = $_GET['action'] ?? '';
if (!in_array($action, ['verify', 'failed'], true)) {
    api_error('Unknown action.', 404, 'not_found');
}
match (true) {
    $order['provider'] === 'cashfree' => cashfree_verify($order),
    // A Razorpay order looked up by its number (no signature in the request): report the status only.
    $providerOrderId === '' => order_state($order),
    $action === 'verify' => verify($order, $in),
    default => failed($order, $in),
};

function enrolled_url(array $order): string
{
    $slug = db_value('SELECT c.slug FROM order_items oi JOIN courses c ON c.id = oi.course_id WHERE oi.order_id = ? LIMIT 1', [$order['id']]);
    return $slug ? '/enrolled/' . $slug . '?order=' . rawurlencode($order['order_number']) : '/dashboard/courses';
}

function order_state(array $order): never
{
    $paid = in_array($order['status'], ['paid', 'refunded', 'partially_refunded'], true);
    api_ok(['order_number' => $order['order_number'], 'provider' => $order['provider'], 'status' => $order['status'],
        'next_url' => $paid ? enrolled_url($order) : null]);
}

function verify(array $order, array $in): never
{
    $paymentId = is_string($in['razorpay_payment_id'] ?? null) ? $in['razorpay_payment_id'] : '';
    $signature = is_string($in['razorpay_signature'] ?? null) ? $in['razorpay_signature'] : '';
    if (!preg_match('/^pay_\w{6,40}$/', $paymentId) || !razorpay_signature_valid($order['provider_order_id'], $paymentId, $signature)) {
        audit_log('payment_signature_invalid', 'order', $order['order_number'], ['payment_id' => mb_substr($paymentId, 0, 60)], 'critical');
        api_error('We could not verify this payment. If money was deducted, it will be confirmed automatically or refunded by the bank.', 400, 'signature_invalid');
    }
    // A valid signature proves Razorpay received this exact amount for this order.
    $result = fulfil_order((int) $order['id'], [
        'provider' => 'razorpay',
        'provider_order_id' => $order['provider_order_id'],
        'provider_payment_id' => $paymentId,
        'amount_paise' => to_paise((float) $order['total']),
        'currency' => $order['currency'],
    ], 'signature');
    if ($result === 'amount_mismatch') {
        api_error('Payment amount mismatch. Our team has been alerted.', 409, 'amount_mismatch');
    }
    api_ok([
        'order_number' => $order['order_number'],
        'status' => 'paid',
        'next_url' => enrolled_url($order),
        'invoice' => invoice_for_order((int) $order['id']),
    ]);
}

function failed(array $order, array $in): never
{
    if ($order['status'] === 'pending') {
        $err = is_array($in['error'] ?? null) ? $in['error'] : [];
        mark_payment_failed((int) $order['id'], [
            'provider' => 'razorpay',
            'provider_order_id' => $order['provider_order_id'],
            'provider_payment_id' => is_string($err['metadata']['payment_id'] ?? null) ? $err['metadata']['payment_id'] : null,
            'amount_paise' => to_paise((float) $order['total']),
            'error_code' => $err['code'] ?? null,
            'error_description' => $err['description'] ?? null,
        ]);
    }
    api_ok(['order_number' => $order['order_number'], 'status' => db_value('SELECT status FROM orders WHERE id = ?', [$order['id']])]);
}

/** Cashfree: ask Cashfree what happened (server to server) and fulfil / record the failure. Idempotent. */
function cashfree_verify(array $order): never
{
    if (!rate_limit('payment-verify', (string) $order['user_id'], 60, 600)) {
        api_error('Too many checks. Please wait a minute and try again.', 429, 'rate_limited');
    }
    $sync = null;
    if (!in_array($order['status'], ['paid', 'refunded', 'partially_refunded'], true)) {
        if (!cashfree_configured()) {
            api_error('Online payment is not configured right now. Please contact the academy with your order number.', 503, 'payments_not_configured');
        }
        try {
            $sync = cashfree_sync_order($order, 'api');
        } catch (RuntimeException) {
            api_error('We could not confirm this payment with Cashfree right now. Please check again in a moment - if money was deducted, your access is activated automatically once it is confirmed.', 502, 'gateway_error');
        }
        if ($sync['state'] === 'mismatch') {
            api_error('Payment amount mismatch. Our team has been alerted.', 409, 'amount_mismatch');
        }
        $order = db_one('SELECT * FROM orders WHERE id = ?', [$order['id']]);
    }
    $paid = in_array($order['status'], ['paid', 'refunded', 'partially_refunded'], true);
    api_ok([
        'order_number' => $order['order_number'],
        'provider' => 'cashfree',
        'status' => $order['status'],
        'state' => $sync['state'] ?? ($paid ? 'paid' : null),
        'payment_status' => $sync['payment_status'] ?? null,
        'next_url' => $paid ? enrolled_url($order) : null,
        'invoice' => $paid ? invoice_for_order((int) $order['id']) : null,
    ]);
}
