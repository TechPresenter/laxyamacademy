<?php
/**
 * Payment gateway webhook receiver.
 *
 * Razorpay (Razorpay Dashboard -> Webhooks):
 *   URL:    https://your-domain/api/webhooks.php
 *   Events: payment.captured, payment.failed, order.paid
 *   Secret: RAZORPAY_WEBHOOK_SECRET in .env (HMAC-SHA256 of the raw body, X-Razorpay-Signature)
 *
 * Cashfree (Merchant Dashboard -> Payment Gateway -> Developers -> Webhooks; also sent to the order's notify_url):
 *   URL:    https://your-domain/api/webhooks.php?provider=cashfree
 *   Events: Success Payment, Failed Payment, User Dropped Payment
 *           (PAYMENT_SUCCESS_WEBHOOK, PAYMENT_FAILED_WEBHOOK, PAYMENT_USER_DROPPED_WEBHOOK)
 *   Check:  x-webhook-signature = base64(HMAC-SHA256(x-webhook-timestamp . rawBody, client secret)),
 *           timestamp within CASHFREE_WEBHOOK_TOLERANCE seconds (default 10 min). The order is then
 *           re-fetched from the Cashfree API before anything is fulfilled (the body is never trusted alone).
 *
 * Idempotent: each event id is stored once in webhook_events (unique key). Cashfree event ids are built
 * from signed material only (type + order id + cf_payment_id + payment_status, else a hash of the raw body),
 * never from the unsigned x-idempotency-key header. Events already
 * 'processed' or 'ignored' are acknowledged as duplicates; events left 'received' (crashed
 * mid-way) or 'failed' are processed again when the gateway retries them, so nothing is lost.
 * No CSRF/session here - authenticity comes from the HMAC signature.
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/payments.php';

require_method('POST');

$raw = file_get_contents('php://input', false, null, 0, 1_048_576) ?: '';

// Cashfree: explicit ?provider=cashfree, or its signature header without Razorpay's.
if (($_GET['provider'] ?? '') === 'cashfree'
    || (isset($_SERVER['HTTP_X_WEBHOOK_SIGNATURE']) && !isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']))) {
    cashfree_webhook($raw);
}

$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
$eventId = $_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? '';
$event = json_decode($raw, true);

if (!razorpay_webhook_valid($raw, $signature)) {
    audit_log('webhook_signature_invalid', 'webhook', null, ['ip' => client_ip(), 'provider' => 'razorpay'], 'critical');
    api_error('Invalid signature.', 400, 'signature_invalid');
}
if (!is_array($event) || empty($event['event'])) {
    api_error('Invalid payload.', 400, 'invalid_payload');
}
$eventId = $eventId !== '' ? mb_substr($eventId, 0, 120) : hash('sha256', $raw);
if (!webhook_claim('razorpay', $eventId, (string) $event['event'], $raw)) {
    api_ok(['duplicate' => true]);
}

$payment = $event['payload']['payment']['entity'] ?? null;
$orderEntity = $event['payload']['order']['entity'] ?? null;
$providerOrderId = $payment['order_id'] ?? $orderEntity['id'] ?? null;
$order = $providerOrderId ? db_one("SELECT id FROM orders WHERE provider = 'razorpay' AND provider_order_id = ?", [$providerOrderId]) : null;

$status = 'ignored';
$error = null;
try {
    if ($order && $payment) {
        $details = [
            'provider' => 'razorpay',
            'provider_order_id' => $providerOrderId,
            'provider_payment_id' => (string) ($payment['id'] ?? ''),
            'amount_paise' => (int) ($payment['amount'] ?? 0),
            'currency' => (string) ($payment['currency'] ?? 'INR'),
            'method' => isset($payment['method']) ? mb_substr((string) $payment['method'], 0, 40) : null,
            'error_code' => $payment['error_code'] ?? null,
            'error_description' => $payment['error_description'] ?? null,
            'raw' => array_intersect_key($payment, array_flip(['id', 'status', 'method', 'amount', 'currency', 'bank', 'wallet', 'vpa', 'created_at'])),
        ];
        if (in_array($event['event'], ['payment.captured', 'order.paid'], true) && ($payment['status'] ?? '') === 'captured') {
            $result = fulfil_order((int) $order['id'], $details, 'webhook');
            $status = $result === 'amount_mismatch' ? 'failed' : 'processed';
            $error = $result === 'amount_mismatch' ? 'Amount mismatch' : null;
        } elseif ($event['event'] === 'payment.failed') {
            mark_payment_failed((int) $order['id'], $details);
            $status = 'processed';
        }
    }
} catch (Throwable $e) {
    // Mark the event failed (so Razorpay's retry is processed again) and answer 500.
    webhook_finish('razorpay', $eventId, 'failed', mb_substr($e->getMessage(), 0, 255));
    throw $e;
}
webhook_finish('razorpay', $eventId, $status, $error);
api_ok(['status' => $status]);

// ------------------------------------------------------------------

/**
 * Store the event once. Returns false when it was already handled ('processed' / 'ignored');
 * an earlier attempt left 'received' (crashed) or 'failed' is reset and handled again.
 */
function webhook_claim(string $provider, string $eventId, string $type, string $raw): bool
{
    $stmt = db_query(
        'INSERT IGNORE INTO webhook_events (provider, event_id, event_type, signature_valid, payload) VALUES (?, ?, ?, 1, ?)',
        [$provider, $eventId, mb_substr($type, 0, 80), $raw]
    );
    if ($stmt->rowCount() > 0) {
        return true;
    }
    $previous = (string) db_value('SELECT status FROM webhook_events WHERE provider = ? AND event_id = ?', [$provider, $eventId]);
    if (in_array($previous, ['processed', 'ignored'], true)) {
        return false;
    }
    db_query("UPDATE webhook_events SET status = 'received', error = NULL WHERE provider = ? AND event_id = ?", [$provider, $eventId]);
    return true;
}

function webhook_finish(string $provider, string $eventId, string $status, ?string $error): void
{
    db_query('UPDATE webhook_events SET status = ?, error = ?, processed_at = NOW() WHERE provider = ? AND event_id = ?', [$status, $error, $provider, $eventId]);
}

/** Cashfree webhook: verify signature + freshness, store once, then reconcile the order with the Cashfree API. */
function cashfree_webhook(string $raw): never
{
    $problem = cashfree_webhook_error($raw, (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''), (string) ($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? ''));
    if ($problem !== null) {
        audit_log('webhook_signature_invalid', 'webhook', null, ['ip' => client_ip(), 'provider' => 'cashfree', 'reason' => $problem], 'critical');
        if ($problem === 'stale') {
            api_error('Stale webhook timestamp.', 400, 'stale_timestamp');
        }
        api_error('Invalid signature.', 400, 'signature_invalid');
    }
    $event = json_decode($raw, true);
    $type = is_array($event) && is_string($event['type'] ?? null) ? $event['type'] : '';
    if ($type === '') {
        api_error('Invalid payload.', 400, 'invalid_payload');
    }
    // The de-duplication key comes only from SIGNED material (the raw body), never from the unsigned
    // x-idempotency-key header, so a captured webhook cannot be replayed as a "new" event by changing a header:
    // type + order id + cf_payment_id + payment_status (stable across Cashfree's retries), else the body hash.
    $pid = $event['data']['payment']['cf_payment_id'] ?? null;
    $pid = is_int($pid) || is_string($pid) ? (string) $pid : '';
    $pStatus = is_string($event['data']['payment']['payment_status'] ?? null) ? $event['data']['payment']['payment_status'] : '';
    $oid = is_string($event['data']['order']['order_id'] ?? null) ? $event['data']['order']['order_id'] : '';
    $eventId = mb_substr(mb_substr($type, 0, 40) . ':' . (preg_match('/^[A-Za-z0-9_-]{1,45}$/', $oid) && preg_match('/^[A-Za-z0-9]{1,40}$/', $pid)
        && preg_match('/^[A-Z_]{1,30}$/', $pStatus) ? $oid . ':' . $pid . ':' . $pStatus : hash('sha256', $raw)), 0, 120);
    if (!webhook_claim('cashfree', $eventId, $type, $raw)) {
        api_ok(['duplicate' => true]);
    }

    $orderNumber = (string) ($event['data']['order']['order_id'] ?? '');
    $order = preg_match('/^[A-Z0-9]{6,30}$/', $orderNumber)
        ? db_one("SELECT * FROM orders WHERE order_number = ? AND provider = 'cashfree'", [$orderNumber])
        : null;
    $status = 'ignored'; // refunds, settlements, dashboard test events and unknown orders are acknowledged only
    $error = $order ? null : ($orderNumber !== '' ? 'Unknown order ' . mb_substr($orderNumber, 0, 40) : null);
    try {
        if ($order && in_array($type, ['PAYMENT_SUCCESS_WEBHOOK', 'PAYMENT_FAILED_WEBHOOK', 'PAYMENT_USER_DROPPED_WEBHOOK'], true)) {
            // Defence in depth: act on Cashfree's API records (Get Order + Get Payments), not on this body.
            $sync = cashfree_sync_order($order, 'webhook');
            $status = $sync['state'] === 'mismatch' ? 'failed' : 'processed';
            $error = $sync['state'] === 'mismatch' ? 'Amount mismatch' : null;
        }
    } catch (Throwable $e) {
        // Failed: Cashfree retries the webhook and the retry is processed again.
        webhook_finish('cashfree', $eventId, 'failed', mb_substr($e->getMessage(), 0, 255));
        error_log('[laxyam] cashfree webhook ' . $eventId . ' failed: ' . $e->getMessage());
        api_error('Could not process the event right now.', 500, 'server_error');
    }
    webhook_finish('cashfree', $eventId, $status, $error);
    api_ok(['status' => $status]);
}
