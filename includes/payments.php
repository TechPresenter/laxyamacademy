<?php
/**
 * Pricing, coupons, payment gateways (Razorpay, Cashfree) and order fulfilment.
 * Used by api/orders.php, api/payments.php and api/webhooks.php.
 *
 * The gateway for new checkouts is PAYMENT_PROVIDER in .env (cashfree - default | razorpay). Orders keep the
 * provider they were created with (orders.provider), so older Razorpay orders still verify after a switch.
 *
 * Rules enforced here:
 *  - prices always come from the database, never from the browser
 *  - an order becomes "paid" only after a verified signature / webhook (Razorpay) or after the
 *    gateway's own API confirms it server to server (Cashfree: Get Order + Get Payments)
 *  - fulfilment is idempotent (row lock + status check) and transactional
 *  - every paid order gets exactly one GST-ready invoice (includes/invoice.php)
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once APP_ROOT . '/includes/catalog.php';
require_once APP_ROOT . '/includes/invoice.php';

// ------------------------------------------------------------------
// Provider layer
// ------------------------------------------------------------------

/**
 * Gateway used for new checkouts: 'cashfree' (default - the owner's live gateway) or 'razorpay'.
 * Any other value is never "configured".
 */
function payment_provider(): string
{
    return strtolower(trim((string) config('PAYMENT_PROVIDER', 'cashfree'))) ?: 'cashfree';
}

function payment_provider_label(?string $provider = null): string
{
    $provider ??= payment_provider();
    return match ($provider) {
        'razorpay' => 'Razorpay',
        'cashfree' => 'Cashfree',
        'none' => 'No payment (100% coupon)',
        default => ucfirst($provider),
    };
}

/** PAYMENT_MODE=live collects real money; anything else is the gateway's sandbox / test mode. */
function payment_live(): bool
{
    return config('PAYMENT_MODE', 'sandbox') === 'live';
}

function razorpay_configured(): bool
{
    return (string) config('RAZORPAY_KEY_ID', '') !== '' && (string) config('RAZORPAY_KEY_SECRET', '') !== '';
}

function cashfree_configured(): bool
{
    return (string) config('CASHFREE_APP_ID', '') !== '' && (string) config('CASHFREE_SECRET_KEY', '') !== '';
}

/** True when the active gateway (PAYMENT_PROVIDER) has its keys, i.e. paid courses can be bought. */
function payments_configured(): bool
{
    return match (payment_provider()) {
        'razorpay' => razorpay_configured(),
        'cashfree' => cashfree_configured(),
        default => false,
    };
}

/** Remember the last gateway API problem for the admin Integrations screen (message only, never secrets). */
function payment_gateway_error(string $provider, string $message): void
{
    db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public) VALUES (?, ?, 'integrations', 0)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        ['payment_last_error_' . $provider, json_encode(['at' => date('Y-m-d H:i:s'), 'msg' => mb_substr($message, 0, 240)], JSON_UNESCAPED_UNICODE)]);
}

/**
 * Honest status of one gateway for admin screens and the go-live checklist. Never contains secrets:
 * only which .env keys are missing, the mode, the webhook URL and the latest webhook / API error.
 */
function payment_gateway_status(?string $provider = null): array
{
    $provider ??= payment_provider();
    $env = fn(string $k): bool => trim((string) config($k, '')) !== '';
    $hook = db_one('SELECT received_at, status, event_type, error FROM webhook_events WHERE provider = ? ORDER BY id DESC LIMIT 1', [$provider]);
    $error = json_decode_array((string) db_value('SELECT setting_value FROM site_settings WHERE setting_key = ?', ['payment_last_error_' . $provider]));
    $base = [
        'provider' => $provider, 'label' => payment_provider_label($provider), 'active' => payment_provider() === $provider,
        'mode' => payment_live() ? 'live' : 'sandbox', 'last_webhook' => $hook ?: null, 'last_error' => $error ?: null,
    ];
    if ($provider === 'cashfree') {
        $missing = array_values(array_filter(['CASHFREE_APP_ID', 'CASHFREE_SECRET_KEY'], fn($k) => !$env($k)));
        return $base + [
            'configured' => !$missing, 'missing' => $missing,
            'key_hint' => $env('CASHFREE_APP_ID') ? substr((string) config('CASHFREE_APP_ID'), 0, 6) . '…' : null,
            // Cashfree signs webhooks with the client secret, so signatures verify as soon as the keys are set.
            'webhook_ready' => !$missing,
            'webhook_secret_note' => 'Signed with your Cashfree client secret (CASHFREE_SECRET_KEY)' . ($env('CASHFREE_WEBHOOK_SECRET') ? '; CASHFREE_WEBHOOK_SECRET is also accepted' : ''),
            'webhook_url' => app_url('api/webhooks.php?provider=cashfree'),
            'webhook_events' => ['PAYMENT_SUCCESS_WEBHOOK', 'PAYMENT_FAILED_WEBHOOK', 'PAYMENT_USER_DROPPED_WEBHOOK'],
            'api_base' => cashfree_api_base(), 'api_override' => cashfree_api_base() !== cashfree_default_base(),
            'api_version' => cashfree_api_version(),
        ];
    }
    $missing = array_values(array_filter(['RAZORPAY_KEY_ID', 'RAZORPAY_KEY_SECRET'], fn($k) => !$env($k)));
    return $base + [
        'configured' => !$missing, 'missing' => $missing,
        'key_hint' => $env('RAZORPAY_KEY_ID') ? substr((string) config('RAZORPAY_KEY_ID'), 0, 12) . '…' : null,
        'webhook_ready' => $env('RAZORPAY_WEBHOOK_SECRET'),
        'webhook_secret_note' => 'HMAC-SHA256 of the raw body with RAZORPAY_WEBHOOK_SECRET',
        'webhook_url' => app_url('api/webhooks.php'),
        'webhook_events' => ['payment.captured', 'payment.failed', 'order.paid'],
    ];
}

function to_paise(float $amount): int
{
    return (int) round($amount * 100);
}

// ------------------------------------------------------------------
// Coupons & quotes
// ------------------------------------------------------------------

/** Validate a coupon for a user + course + amount. Returns [coupon|null, discount, error|null]. */
function evaluate_coupon(?string $code, int $userId, int $courseId, float $amount): array
{
    $code = strtoupper(trim((string) $code));
    if ($code === '') {
        return [null, 0.0, null];
    }
    $c = db_one('SELECT * FROM coupons WHERE code = ? AND is_active = 1', [$code]);
    $invalid = [null, 0.0, 'This coupon code is not valid.'];
    if (!$c) {
        return $invalid;
    }
    if (($c['starts_at'] && strtotime($c['starts_at']) > time()) || ($c['ends_at'] && strtotime($c['ends_at']) < time())) {
        return [null, 0.0, 'This coupon has expired or is not active yet.'];
    }
    if ($c['usage_limit'] !== null && (int) $c['used_count'] >= (int) $c['usage_limit']) {
        return [null, 0.0, 'This coupon has reached its usage limit.'];
    }
    // Referral codes and reward coupons have their own rules (includes/referrals.php).
    if (($c['kind'] ?? 'standard') !== 'standard') {
        require_once APP_ROOT . '/includes/referrals.php';
        if ($err = referral_coupon_error($c, $userId)) {
            return [null, 0.0, $err];
        }
    }
    $usedByUser = (int) db_value('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ? AND user_id = ?', [$c['id'], $userId]);
    if ($usedByUser >= (int) $c['per_user_limit']) {
        return [null, 0.0, 'You have already used this coupon.'];
    }
    if ($c['applies_to'] === 'selected' && !db_value('SELECT 1 FROM coupon_courses WHERE coupon_id = ? AND course_id = ?', [$c['id'], $courseId])) {
        return [null, 0.0, 'This coupon does not apply to this course.'];
    }
    if ($amount < (float) $c['min_order_amount']) {
        return [null, 0.0, 'Minimum order amount for this coupon is ₹' . inr_format((float) $c['min_order_amount'], false) . '.'];
    }
    $discount = $c['discount_type'] === 'percent'
        ? $amount * min(100, (float) $c['discount_value']) / 100
        : (float) $c['discount_value'];
    if ($c['max_discount'] !== null) {
        $discount = min($discount, (float) $c['max_discount']);
    }
    return [$c, round(min($discount, $amount), 2), null];
}

/**
 * Server-side price for a course checkout. $billingState decides CGST+SGST vs IGST.
 * 'total' is the amount charged: it includes GST when prices are set to exclude it.
 */
function build_quote(array $course, int $userId, ?string $couponCode, ?string $billingState = null): array
{
    $subtotal = course_effective_price($course);
    [$coupon, $discount, $couponError] = evaluate_coupon($couponCode, $userId, (int) $course['id'], $subtotal);
    $net = round(max(0, $subtotal - $discount), 2);
    // The gateways' minimum charge (Razorpay and Cashfree) is ₹1: a coupon may make a course free, but never leave a few paise to pay.
    if ($net > 0 && $net < 1) {
        $discount = round($subtotal - 1, 2);
        $net = 1.0;
    }
    $tax = quote_tax($net, $billingState);
    $total = $tax['total'];
    unset($tax['total']);
    return [
        'list_price' => (float) $course['price'],
        'subtotal' => $subtotal,
        'discount' => $discount,
        'net' => $net,
        'total' => $total,
        'tax' => $tax,
        'coupon' => $coupon ? ['code' => $coupon['code'], 'id' => (int) $coupon['id'], 'description' => $coupon['description']] : null,
        'coupon_error' => $couponError,
        'currency' => 'INR',
    ];
}

/**
 * Billing details for the invoice snapshot. $raw = the `billing` object from checkout.
 * Without one (older clients) the account details are used. Returns [billing, field errors].
 */
function validate_billing(mixed $raw, array $user): array
{
    if (!is_array($raw)) {
        $state = canonical_state((string) db_value('SELECT state FROM student_profiles WHERE user_id = ?', [$user['id']]));
        return [['name' => $user['name'], 'email' => $user['email'], 'phone' => $user['phone'] ?: null, 'state' => $state, 'gstin' => null, 'address' => null], []];
    }
    $clean = fn($v) => trim((string) preg_replace('/[^\P{C}\n]/u', '', is_scalar($v) ? (string) $v : ''));
    $errors = [];
    $name = preg_replace('/\s+/u', ' ', $clean($raw['name'] ?? ''));
    if (mb_strlen($name) < 2) {
        $errors['billing.name'] = 'Enter the name to print on the invoice.';
    } elseif (mb_strlen($name) > 160) {
        $errors['billing.name'] = 'Keep the name under 160 characters.';
    }
    $email = mb_strtolower($clean($raw['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $errors['billing.email'] = 'Enter a valid email address.';
    }
    $phone = preg_replace('/[\s\-().]/', '', $clean($raw['phone'] ?? ''));
    $phone = preg_replace('/^(?:\+91|0091|91(?=\d{10}$)|0(?=\d{10}$))/', '', $phone);
    if (!preg_match('/^[6-9]\d{9}$/', $phone)) {
        $errors['billing.phone'] = 'Enter a valid 10-digit Indian mobile number.';
    }
    $state = canonical_state($clean($raw['state'] ?? ''));
    if (!$state) {
        $errors['billing.state'] = 'Choose your state or union territory.';
    }
    $gstin = strtoupper(str_replace(' ', '', $clean($raw['gstin'] ?? '')));
    if ($gstin !== '') {
        if (!preg_match(GSTIN_REGEX, $gstin)) {
            $errors['billing.gstin'] = 'Enter a valid 15-character GSTIN, e.g. 07ABCDE1234F1Z5 (or leave it empty).';
        } elseif ($state && substr($gstin, 0, 2) !== indian_states()[$state]) {
            $errors['billing.gstin'] = 'This GSTIN is registered in a different state than the one selected.';
        }
    }
    $address = $clean($raw['address'] ?? '');
    if (mb_strlen($address) > 500) {
        $errors['billing.address'] = 'Keep the address under 500 characters.';
    }
    return [['name' => $name, 'email' => $email, 'phone' => $phone, 'state' => $state, 'gstin' => $gstin ?: null, 'address' => $address ?: null], $errors];
}

// ------------------------------------------------------------------
// Razorpay
// ------------------------------------------------------------------

/** JSON over HTTP(S). Returns [status, decoded body]; throws RuntimeException when the host cannot be reached. */
function http_json(string $method, string $url, ?array $body, array $headers = [], ?string $basicAuth = null, int $timeout = 20): array
{
    $payload = $body !== null ? json_encode($body) : null;
    $headers[] = 'Content-Type: application/json';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        if ($basicAuth) {
            curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false) {
            throw new RuntimeException('Payment gateway unreachable: ' . curl_error($ch));
        }
    } else {
        if ($basicAuth) {
            $headers[] = 'Authorization: Basic ' . base64_encode($basicAuth);
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $payload ?? '',
            'timeout' => $timeout, 'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException('Payment gateway unreachable');
        }
        preg_match('#HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);
        $status = (int) ($m[1] ?? 0);
    }
    $data = json_decode((string) $raw, true);
    return [$status, is_array($data) ? $data : []];
}

function razorpay_create_order(array $order): string
{
    [$status, $data] = http_json('POST', 'https://api.razorpay.com/v1/orders', [
        'amount' => to_paise((float) $order['total']),
        'currency' => $order['currency'],
        'receipt' => $order['order_number'],
        'notes' => ['order_number' => $order['order_number']],
    ], [], config('RAZORPAY_KEY_ID') . ':' . config('RAZORPAY_KEY_SECRET'));
    if ($status !== 200 || empty($data['id'])) {
        error_log('[laxyam] razorpay order failed: HTTP ' . $status . ' ' . ($data['error']['description'] ?? ''));
        throw new RuntimeException('Could not start the payment. Please try again.');
    }
    return (string) $data['id'];
}

function razorpay_signature_valid(string $orderId, string $paymentId, string $signature): bool
{
    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, (string) config('RAZORPAY_KEY_SECRET', ''));
    return $signature !== '' && hash_equals($expected, $signature);
}

function razorpay_webhook_valid(string $rawBody, string $signature): bool
{
    $secret = (string) config('RAZORPAY_WEBHOOK_SECRET', '');
    return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
}

// ------------------------------------------------------------------
// Cashfree Payment Gateway (PG API)
//   Create Order  POST {base}/orders                  -> payment_session_id for the JS SDK v3 checkout
//   Get Order     GET  {base}/orders/{order_id}        -> order_status ACTIVE | PAID | EXPIRED | TERMINATED…
//   Get Payments  GET  {base}/orders/{order_id}/payments -> payment_status SUCCESS | FAILED | USER_DROPPED | PENDING…
// {base} = https://sandbox.cashfree.com/pg (sandbox) or https://api.cashfree.com/pg (PAYMENT_MODE=live).
// Headers: x-client-id, x-client-secret, x-api-version. Docs: https://www.cashfree.com/docs/api-reference/payments/
// Our order_number (LXA + 12 alphanumerics) is the Cashfree order_id (3-45 chars, alphanumeric / _ / -).
// ------------------------------------------------------------------

const CASHFREE_DEFAULT_API_VERSION = '2025-01-01';
const CASHFREE_FAILED_STATUSES = ['FAILED', 'USER_DROPPED', 'CANCELLED', 'VOID'];

/** Cashfree answered 404 for one of our orders: it does not exist on this account / mode (e.g. a sandbox order after going live). */
final class CashfreeOrderNotFound extends RuntimeException
{
}

function cashfree_default_base(): string
{
    return payment_live() ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg';
}

/**
 * API root. CASHFREE_API_BASE (a local mock server for testing) is honoured only when
 * APP_ENV is not production, so a stray value can never send live credentials elsewhere.
 */
function cashfree_api_base(): string
{
    $override = rtrim(trim((string) config('CASHFREE_API_BASE', '')), '/');
    if ($override !== '' && !is_production() && preg_match('~^https?://[^\s/?#]+(/[^\s?#]*)?$~i', $override)) {
        return $override;
    }
    return cashfree_default_base();
}

/** Value for the browser SDK: Cashfree({ mode }). */
function cashfree_sdk_mode(): string
{
    return payment_live() ? 'production' : 'sandbox';
}

/** x-api-version header (CASHFREE_API_VERSION, YYYY-MM-DD). The fields used here exist in 2023-08-01 … 2026-01-01. */
function cashfree_api_version(): string
{
    $v = (string) config('CASHFREE_API_VERSION', CASHFREE_DEFAULT_API_VERSION);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : CASHFREE_DEFAULT_API_VERSION;
}

/** "+91 98765-43210" -> "9876543210"; null unless it is a valid 10-digit Indian mobile (Cashfree requires one). */
function cashfree_phone(?string $phone): ?string
{
    $p = preg_replace('/[\s\-().]/', '', (string) $phone);
    $p = preg_replace('/^(?:\+91|0091|91(?=\d{10}$)|0(?=\d{10}$))/', '', (string) $p);
    return preg_match('/^[6-9]\d{9}$/', (string) $p) ? $p : null;
}

/**
 * One Cashfree PG API call. Returns [httpStatus, decodedBody]. Throws RuntimeException when
 * Cashfree cannot be reached (network error / timeout). Auth and server errors are remembered
 * for the admin Integrations screen.
 */
function cashfree_request(string $method, string $path, ?array $body = null, int $timeout = 15): array
{
    $headers = [
        'Accept: application/json',
        'x-client-id: ' . config('CASHFREE_APP_ID', ''),
        'x-client-secret: ' . config('CASHFREE_SECRET_KEY', ''),
        'x-api-version: ' . cashfree_api_version(),
        'x-request-id: laxyam-' . bin2hex(random_bytes(8)),
    ];
    try {
        [$status, $data] = http_json($method, cashfree_api_base() . $path, $body, $headers, null, $timeout);
    } catch (RuntimeException $e) {
        payment_gateway_error('cashfree', 'Cashfree could not be reached (' . $method . ' ' . strtok($path, '?') . '): ' . $e->getMessage());
        throw $e;
    }
    if ($status === 0 || $status === 401 || $status === 403 || $status === 429 || $status >= 500) {
        payment_gateway_error('cashfree', "Cashfree answered HTTP {$status} to {$method} " . strtok($path, '?') . (isset($data['message']) ? ': ' . $data['message'] : ''));
    }
    return [$status, $data];
}

/**
 * Create the Cashfree order for one of our pending orders. The amount is the server-side order total;
 * $billing is the validated checkout snapshot. Returns ['cf_order_id', 'payment_session_id', 'order_status'].
 * The return_url brings the student to /payment/result, which asks the server to verify (never the URL).
 */
function cashfree_create_order(array $order, array $billing, string $description): array
{
    $phone = cashfree_phone($billing['phone'] ?? null);
    if ($phone === null) {
        throw new RuntimeException('A valid 10-digit mobile number is needed for the payment.');
    }
    $name = trim((string) ($billing['name'] ?? ''));
    $email = (string) ($billing['email'] ?? '');
    $customer = array_filter([
        'customer_id' => 'LXS' . str_pad((string) $order['user_id'], 6, '0', STR_PAD_LEFT), // 3-50 alphanumerics
        'customer_phone' => $phone,
        'customer_email' => strlen($email) >= 3 && strlen($email) <= 100 ? $email : null,
        'customer_name' => mb_strlen($name) >= 3 ? mb_substr($name, 0, 100) : null,
    ], fn($v) => $v !== null);
    $meta = ['return_url' => app_url('payment/result?order=' . rawurlencode($order['order_number']))];
    $notify = app_url('api/webhooks.php?provider=cashfree');
    if (str_starts_with($notify, 'https://')) {
        $meta['notify_url'] = $notify; // Cashfree only accepts HTTPS notify URLs (a local http:// site relies on the dashboard webhook / return page)
    }
    $note = mb_substr(trim(setting('site_name', 'Laxyam Academy') . ': ' . $description), 0, 200);

    try {
        [$status, $data] = cashfree_request('POST', '/orders', [
            'order_id' => $order['order_number'],
            'order_amount' => round((float) $order['total'], 2),
            'order_currency' => 'INR',
            'customer_details' => $customer,
            'order_meta' => $meta,
            'order_note' => mb_strlen($note) >= 3 ? $note : 'Course purchase',
        ]);
    } catch (RuntimeException $e) {
        // Network error / timeout: the detail (cURL message) is kept for staff (payment_gateway_error() in
        // cashfree_request + the PHP error log) and never shown to the student.
        error_log('[laxyam] cashfree order not created for ' . $order['order_number'] . ': ' . $e->getMessage());
        throw new RuntimeException('Could not start the payment. Please try again.');
    }
    if ($status < 200 || $status >= 300 || empty($data['payment_session_id']) || (string) ($data['order_id'] ?? '') !== $order['order_number']) {
        $why = 'HTTP ' . $status . (isset($data['message']) ? ' ' . mb_substr((string) $data['message'], 0, 160) : '');
        error_log('[laxyam] cashfree order failed for ' . $order['order_number'] . ': ' . $why);
        payment_gateway_error('cashfree', 'Create order ' . $order['order_number'] . ' failed: ' . $why);
        throw new RuntimeException('Could not start the payment. Please try again.');
    }
    return [
        'cf_order_id' => (string) ($data['cf_order_id'] ?? '') !== '' ? (string) $data['cf_order_id'] : $order['order_number'],
        'payment_session_id' => (string) $data['payment_session_id'],
        'order_status' => (string) ($data['order_status'] ?? ''),
    ];
}

/** Cashfree's view of an order: [orderEntity, payments newest first]. Throws RuntimeException on any error. */
function cashfree_fetch_order(string $orderNumber, int $timeout = 15): array
{
    $path = '/orders/' . rawurlencode($orderNumber);
    [$status, $cf] = cashfree_request('GET', $path, null, $timeout);
    if ($status !== 200 || (string) ($cf['order_id'] ?? '') !== $orderNumber) {
        if ($status === 404) {
            throw new CashfreeOrderNotFound('Cashfree does not know order ' . $orderNumber . ' (' . (payment_live() ? 'live' : 'sandbox') . ' account).');
        }
        if ($status >= 400 && $status < 500 && !in_array($status, [401, 403, 429], true)) {
            payment_gateway_error('cashfree', "Get order {$orderNumber} failed: HTTP {$status}" . (isset($cf['message']) ? ' ' . $cf['message'] : ''));
        }
        throw new RuntimeException('Could not confirm the payment with Cashfree right now.');
    }
    [$pStatus, $payments] = cashfree_request('GET', $path . '/payments', null, $timeout);
    if ($pStatus !== 200 || !array_is_list($payments)) {
        throw new RuntimeException('Could not confirm the payment with Cashfree right now.');
    }
    $payments = array_values(array_filter($payments, 'is_array'));
    // Newest attempt first (completion time, else start time, else the numeric payment id).
    usort($payments, fn($a, $b) => [(string) ($b['payment_completion_time'] ?? $b['payment_time'] ?? ''), (string) ($b['cf_payment_id'] ?? '')]
        <=> [(string) ($a['payment_completion_time'] ?? $a['payment_time'] ?? ''), (string) ($a['cf_payment_id'] ?? '')]);
    return [$cf, $payments];
}

/** "upi" | "credit_card" | "net_banking" | "wallet"…: payment_group, else the payment_method key. */
function cashfree_method(array $p): ?string
{
    $m = (string) ($p['payment_group'] ?? '');
    if ($m === '' && is_array($p['payment_method'] ?? null)) {
        $m = (string) array_key_first($p['payment_method']);
    }
    $m = strtolower((string) preg_replace('/[^a-z_]/i', '', $m));
    return $m !== '' ? mb_substr($m, 0, 40) : null;
}

/** A Cashfree payment entity in the shape record_payment_transaction() / fulfil_order() expect. */
function cashfree_payment_details(array $order, array $cf, array $p): array
{
    $ok = ($p['payment_status'] ?? '') === 'SUCCESS';
    $err = is_array($p['error_details'] ?? null) ? $p['error_details'] : [];
    return [
        'provider' => 'cashfree',
        'provider_order_id' => (string) ($cf['cf_order_id'] ?? '') !== '' ? (string) $cf['cf_order_id'] : $order['provider_order_id'],
        'provider_payment_id' => (string) ($p['cf_payment_id'] ?? ''),
        'amount_paise' => to_paise((float) ($p['payment_amount'] ?? 0)),
        'currency' => strtoupper((string) ($p['payment_currency'] ?? '')),
        'method' => cashfree_method($p),
        'error_code' => $ok ? null : ($err['error_code'] ?? $p['payment_status'] ?? null),
        'error_description' => $ok ? null : ($err['error_description'] ?? $p['payment_message'] ?? null),
        'raw' => array_intersect_key($p, array_flip(['cf_payment_id', 'payment_status', 'payment_amount', 'payment_currency', 'payment_group',
            'payment_time', 'payment_completion_time', 'bank_reference', 'payment_message', 'is_captured'])),
    ];
}

/**
 * Reconcile one Cashfree order with Cashfree's own records (server to server) and act on them.
 * Never trusts the browser, the return URL or a webhook body:
 *  - order PAID + a SUCCESS payment of exactly the order total in INR -> fulfil_order() (idempotent)
 *  - newest attempt FAILED / USER_DROPPED / CANCELLED / VOID, or the order EXPIRED -> mark_payment_failed()
 *    (the "payment not completed" email follows from notify_stale_failed_payments(), not here)
 *  - anything else stays pending.
 * Returns ['state' => paid|pending|failed|not_attempted|mismatch, 'order_status', 'payment_status', 'result'].
 * Throws RuntimeException when Cashfree is unreachable or answers with an error.
 */
function cashfree_sync_order(array $order, string $via, int $timeout = 15): array
{
    if (in_array($order['status'], ['paid', 'refunded', 'partially_refunded'], true)) {
        return ['state' => 'paid', 'order_status' => 'PAID', 'payment_status' => 'SUCCESS', 'result' => 'already_paid'];
    }
    [$cf, $payments] = cashfree_fetch_order($order['order_number'], $timeout);
    $cfStatus = (string) ($cf['order_status'] ?? '');
    $success = null;
    foreach ($payments as $p) {
        if (($p['payment_status'] ?? '') === 'SUCCESS') {
            $success = $p;
            break;
        }
    }
    // The attempt that decides the outcome: a successful one if any, else the newest.
    $latest = $success ?? $payments[0] ?? null;
    $out = fn(string $state, ?string $result = null) => ['state' => $state, 'order_status' => $cfStatus,
        'payment_status' => $latest['payment_status'] ?? null, 'result' => $result,
        'payment_time' => $latest['payment_time'] ?? $latest['payment_completion_time'] ?? null];
    // A newer attempt is in progress on an order we had marked failed (the Cashfree order stays payable):
    // it is pending again, so no "payment not completed" email goes out while the bank confirms.
    $reopen = fn() => $order['status'] === 'failed'
        ? db_query("UPDATE orders SET status = 'pending' WHERE id = ? AND status = 'failed'", [$order['id']]) : null;

    if ($success) {
        if ($cfStatus !== 'PAID') {
            $reopen();
            return $out('pending'); // Cashfree has not settled the order status yet - check again shortly
        }
        $details = cashfree_payment_details($order, $cf, $success);
        $expected = to_paise((float) $order['total']);
        if ($details['amount_paise'] !== $expected || $details['currency'] !== 'INR'
            || to_paise((float) ($cf['order_amount'] ?? 0)) !== $expected || strtoupper((string) ($cf['order_currency'] ?? '')) !== 'INR') {
            cashfree_flag_mismatch($order, $details, $expected);
            return $out('mismatch', 'amount_mismatch');
        }
        $result = fulfil_order((int) $order['id'], $details, $via);
        if ($result === 'amount_mismatch') {
            cashfree_flag_mismatch($order, $details, $expected);
        }
        return $out($result === 'amount_mismatch' ? 'mismatch' : 'paid', $result);
    }
    if ($cfStatus === 'PAID') {
        // PAID without a SUCCESS payment in the list: never fulfil on the order flag alone (logged once per order).
        payment_alert_once($order, 'payment_unconfirmed', ['provider' => 'cashfree', 'order_status' => $cfStatus], 'warning');
        return $out('pending');
    }
    if ($latest && in_array($latest['payment_status'] ?? '', CASHFREE_FAILED_STATUSES, true)) {
        mark_payment_failed((int) $order['id'], cashfree_payment_details($order, $cf, $latest));
        return $out('failed');
    }
    if (in_array($cfStatus, ['EXPIRED', 'TERMINATED', 'TERMINATION_REQUESTED'], true)) {
        mark_payment_failed((int) $order['id'], ['provider' => 'cashfree', 'provider_order_id' => $order['provider_order_id'],
            'error_code' => 'order_' . strtolower($cfStatus), 'error_description' => 'The payment window closed without a payment.']);
        return $out('failed');
    }
    if ($latest && ($latest['payment_status'] ?? '') !== 'NOT_ATTEMPTED') {
        $reopen();
        return $out('pending');
    }
    return $out('not_attempted');
}

/**
 * Audit row (and optionally a staff email) written once per order for a payment problem that every poll,
 * verify call and webhook would otherwise report again. Serialised on the order row, so concurrent checks
 * alert once. Must not be called inside a transaction.
 */
function payment_alert_once(array $order, string $action, array $details, string $severity, ?string $staffSubject = null, ?string $staffText = null): bool
{
    $first = db_transaction(function () use ($order, $action, $details, $severity) {
        db_value('SELECT id FROM orders WHERE id = ? FOR UPDATE', [$order['id']]);
        if (db_value("SELECT 1 FROM audit_logs WHERE action = ? AND entity_type = 'order' AND entity_id = ? LIMIT 1", [$action, $order['order_number']])) {
            return false;
        }
        audit_log($action, 'order', $order['order_number'], $details, $severity);
        return true;
    });
    if ($first && $staffSubject !== null) {
        require_once APP_ROOT . '/includes/notify.php';
        notify_staff_email(setting('payment_notify_email'), $staffSubject, (string) $staffText, 'orders.php?order=' . urlencode($order['order_number']));
    }
    return $first;
}

/**
 * A Cashfree payment whose amount / currency does not match the order: never fulfilled automatically.
 * Logged (critical) and staff emailed once per order, so they can activate access or refund it by hand.
 */
function cashfree_flag_mismatch(array $order, array $details, int $expectedPaise): void
{
    $received = inr(((int) $details['amount_paise']) / 100) . ' (' . ($details['currency'] ?: 'currency not reported') . ')';
    payment_alert_once($order, 'payment_amount_mismatch', ['provider' => 'cashfree', 'expected' => $expectedPaise,
        'received' => $details['amount_paise'], 'currency' => $details['currency'], 'payment_id' => $details['provider_payment_id']], 'critical',
        'Payment needs review: ' . $order['order_number'],
        'Cashfree reports a successful payment of ' . $received . ' (payment ID ' . ($details['provider_payment_id'] ?: 'unknown') . ') for order '
        . $order['order_number'] . ', but the order total is ' . inr($expectedPaise / 100) . ' (INR). Access was NOT activated automatically. '
        . 'Check the payment in the Cashfree dashboard, then contact the student to activate the course by hand or refund the payment. '
        . 'A Cashfree convenience fee or surcharge configured in the dashboard causes this for every payment - switch it off.');
}

/**
 * Before a NEW Cashfree order is created for a student + course, make sure no earlier order for the same
 * course can still be paid (a Cashfree order stays payable until it expires, even after a failed or dropped
 * attempt). Each earlier open order (pending / failed, last 31 days) is synced with Cashfree first:
 *  - paid, amount mismatch, or an attempt started within the last hour still PENDING -> that order is
 *    returned and no new order may be created (the student is sent to its result page instead);
 *  - otherwise, if it is still ACTIVE on Cashfree it is terminated (PATCH /orders/{id} {order_status: TERMINATED});
 *    TERMINATED -> cancelled here; TERMINATION_REQUESTED -> left as is, so reconciliation keeps checking it.
 * Returns null (safe to create a new order) or ['order' => row, 'state' => paid|pending|mismatch].
 * Throws RuntimeException when Cashfree cannot be reached or an order could not be closed.
 */
function cashfree_close_open_orders(int $userId, int $courseId): ?array
{
    $rows = db_all("SELECT o.* FROM orders o JOIN order_items oi ON oi.order_id = o.id
                    WHERE o.user_id = ? AND oi.course_id = ? AND o.provider = 'cashfree' AND o.status IN ('pending', 'failed')
                      AND o.provider_order_id IS NOT NULL AND o.created_at > NOW() - INTERVAL 31 DAY
                    ORDER BY o.id DESC LIMIT 10", [$userId, $courseId]);
    foreach ($rows as $order) {
        try {
            $sync = cashfree_sync_order($order, 'api');
        } catch (CashfreeOrderNotFound) {
            // Not on this Cashfree account / mode (e.g. a sandbox test order after PAYMENT_MODE=live): it can
            // never be paid here, so it must not block a new checkout.
            db_query("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status IN ('pending', 'failed')", [$order['id']]);
            audit_log('payment_order_terminated', 'order', $order['order_number'], ['provider' => 'cashfree', 'reason' => 'unknown to the Cashfree account (' . (payment_live() ? 'live' : 'sandbox') . ')']);
            continue;
        }
        $recent = $sync['payment_time'] === null || strtotime((string) $sync['payment_time']) > time() - 3600;
        if (in_array($sync['state'], ['paid', 'mismatch'], true) || ($sync['state'] === 'pending' && $recent)) {
            return ['order' => db_one('SELECT * FROM orders WHERE id = ?', [$order['id']]), 'state' => $sync['state']];
        }
        if (!in_array($sync['order_status'], ['ACTIVE', 'TERMINATION_REQUESTED'], true)) {
            continue; // EXPIRED / TERMINATED: nothing can be paid on it any more (sync already marked it failed)
        }
        [$status, $cf] = cashfree_request('PATCH', '/orders/' . rawurlencode($order['order_number']), ['order_status' => 'TERMINATED']);
        $now = $status === 200 ? (string) ($cf['order_status'] ?? '') : '';
        if ($now === 'TERMINATED') {
            db_query("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status IN ('pending', 'failed')", [$order['id']]);
            audit_log('payment_order_terminated', 'order', $order['order_number'], ['provider' => 'cashfree', 'reason' => 'new checkout for the same course']);
            continue;
        }
        if ($now === 'TERMINATION_REQUESTED') {
            audit_log('payment_order_terminated', 'order', $order['order_number'], ['provider' => 'cashfree', 'reason' => 'new checkout for the same course', 'pending' => true]);
            continue;
        }
        // Not terminated: most likely paid a moment ago. Check again rather than open a second payable order.
        $again = cashfree_sync_order(db_one('SELECT * FROM orders WHERE id = ?', [$order['id']]), 'api');
        if (in_array($again['state'], ['paid', 'mismatch', 'pending'], true)) {
            return ['order' => db_one('SELECT * FROM orders WHERE id = ?', [$order['id']]), 'state' => $again['state']];
        }
        if (!in_array($again['order_status'], ['ACTIVE', 'TERMINATION_REQUESTED'], true)) {
            continue; // it expired meanwhile: nothing left to close
        }
        payment_gateway_error('cashfree', 'Terminate order ' . $order['order_number'] . ' failed: HTTP ' . $status . (isset($cf['message']) ? ' ' . mb_substr((string) $cf['message'], 0, 160) : ''));
        throw new RuntimeException('Could not close the earlier payment for this course.');
    }
    return null;
}

/**
 * Payment session of an existing ACTIVE Cashfree order (re-used when the student retries checkout),
 * or null when it cannot be re-used (paid, expired, amount changed, Cashfree unreachable).
 */
function cashfree_active_session(array $order): ?string
{
    try {
        [$status, $cf] = cashfree_request('GET', '/orders/' . rawurlencode($order['order_number']));
    } catch (RuntimeException) {
        return null;
    }
    if ($status !== 200 || ($cf['order_status'] ?? '') !== 'ACTIVE' || empty($cf['payment_session_id'])
        || to_paise((float) ($cf['order_amount'] ?? 0)) !== to_paise((float) $order['total'])) {
        return null;
    }
    return (string) $cf['payment_session_id'];
}

/**
 * Cashfree webhook check: x-webhook-signature = base64(HMAC-SHA256(x-webhook-timestamp . rawBody)) keyed
 * with the client secret (CASHFREE_SECRET_KEY, per Cashfree's signature-verification docs). An optional
 * CASHFREE_WEBHOOK_SECRET is accepted as a second key. Requests older/newer than CASHFREE_WEBHOOK_TOLERANCE
 * seconds (default 600) are rejected as replays. Returns null when valid, else
 * 'not_configured' | 'missing' | 'mismatch' | 'stale'.
 */
function cashfree_webhook_error(string $rawBody, string $signature, string $timestamp, ?int $now = null): ?string
{
    $keys = array_values(array_unique(array_filter([(string) config('CASHFREE_SECRET_KEY', ''), (string) config('CASHFREE_WEBHOOK_SECRET', '')])));
    if (!$keys) {
        return 'not_configured';
    }
    if ($rawBody === '' || $signature === '' || !preg_match('/^\d{9,14}$/', $timestamp)) {
        return 'missing';
    }
    $valid = false;
    foreach ($keys as $key) {
        $valid = hash_equals(base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $key, true)), $signature) || $valid;
    }
    if (!$valid) {
        return 'mismatch';
    }
    $ts = (int) $timestamp;
    if ($ts > 99_999_999_999) {
        $ts = intdiv($ts, 1000); // Cashfree sends milliseconds
    }
    $tolerance = max(60, min(86400, (int) config('CASHFREE_WEBHOOK_TOLERANCE', 600)));
    return abs(($now ?? time()) - $ts) > $tolerance ? 'stale' : null;
}

/**
 * Safety net run by the scheduler: re-checks a few recent pending AND failed Cashfree orders with the API,
 * so a payment still completes when the student closed the tab and the webhook was missed. Failed orders
 * are included because the Cashfree order stays payable after a failed / dropped attempt (a late UPI
 * approval or a retry on the hosted page can still pay it). Each order is checked at most every 30 minutes;
 * stops at the first network error. Returns the orders whose status changed.
 */
function reconcile_pending_payments(int $limit = 2): int
{
    if (!cashfree_configured()) {
        return 0;
    }
    $rows = db_all("SELECT * FROM orders WHERE provider = 'cashfree' AND status IN ('pending', 'failed') AND total > 0 AND provider_order_id IS NOT NULL
                    AND created_at <= NOW() - INTERVAL 10 MINUTE AND created_at >= NOW() - INTERVAL 48 HOUR ORDER BY id DESC LIMIT 25");
    $changed = 0;
    $checked = 0;
    foreach ($rows as $order) {
        if ($checked >= $limit) {
            break;
        }
        if (!rate_limit('cf-reconcile', $order['order_number'], 1, 1800)) {
            continue;
        }
        $checked++;
        try {
            $r = cashfree_sync_order($order, 'api', 6);
        } catch (CashfreeOrderNotFound) {
            continue; // not on this Cashfree account / mode (e.g. a sandbox order after going live)
        } catch (RuntimeException) {
            break; // Cashfree unreachable: try again next run
        }
        $changed += $r['state'] === 'paid' || ($r['state'] === 'failed' && $order['status'] === 'pending')
            || ($r['state'] === 'pending' && $order['status'] === 'failed') ? 1 : 0;
    }
    return $changed;
}

// ------------------------------------------------------------------
// Transactions
// ------------------------------------------------------------------

/**
 * Record a gateway event on payment_transactions without leaving orphans: the row for this
 * payment id is updated if it exists, otherwise the order's open 'created' row (inserted when
 * checkout started) is completed, otherwise a new row is inserted. A captured row is never
 * downgraded to failed. Call inside the caller's transaction (after locking the order).
 */
function record_payment_transaction(int $orderId, array $p, string $status, bool $verified = false, ?string $via = null): void
{
    $paymentId = ($p['provider_payment_id'] ?? '') !== '' ? (string) $p['provider_payment_id'] : null;
    $raw = isset($p['raw']) ? json_encode($p['raw']) : null;
    $amount = ((int) ($p['amount_paise'] ?? 0)) / 100;
    $errCode = mb_substr((string) ($p['error_code'] ?? ''), 0, 80) ?: null;
    $errText = mb_substr((string) ($p['error_description'] ?? ''), 0, 255) ?: null;
    $method = isset($p['method']) && $p['method'] !== '' ? mb_substr((string) $p['method'], 0, 40) : null;

    $row = $paymentId ? db_one('SELECT id, status FROM payment_transactions WHERE provider = ? AND provider_payment_id = ? FOR UPDATE', [$p['provider'], $paymentId]) : null;
    $row ??= db_one("SELECT id, status FROM payment_transactions WHERE order_id = ? AND provider = ? AND provider_payment_id IS NULL AND status = 'created' ORDER BY id DESC LIMIT 1 FOR UPDATE", [$orderId, $p['provider']]);
    if ($row) {
        if ($row['status'] === 'captured' && $status !== 'captured') {
            return; // never downgrade a captured payment
        }
        if ($row['status'] === 'refunded') {
            return; // refunded by staff (admin/orders.php): a late duplicate gateway event must not mark it captured again
        }
        if ($row['status'] === 'failed' && $status === 'failed' && $paymentId !== null) {
            return; // the same failed attempt seen again (re-checks): keep its time, it dates the "payment not completed" email
        }
        // verified_via keeps the FIRST channel that verified the payment (api / webhook / return):
        // a later duplicate event must not rewrite the audit trail.
        db_query(
            'UPDATE payment_transactions SET provider_payment_id = COALESCE(?, provider_payment_id), provider_order_id = COALESCE(?, provider_order_id),
                status = ?, amount = IF(? > 0, ?, amount), method = COALESCE(?, method), verified = GREATEST(verified, ?), verified_via = COALESCE(verified_via, ?),
                error_code = ?, error_description = ?, raw_payload = COALESCE(?, raw_payload) WHERE id = ?',
            [$paymentId, $p['provider_order_id'] ?? null, $status, $amount, $amount, $method, $verified ? 1 : 0, $via, $errCode, $errText, $raw, $row['id']]
        );
        return;
    }
    db_query(
        'INSERT INTO payment_transactions (order_id, provider, provider_order_id, provider_payment_id, amount, currency, status, method, verified, verified_via, error_code, error_description, raw_payload)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$orderId, $p['provider'], $p['provider_order_id'] ?? null, $paymentId, $amount, strtoupper((string) ($p['currency'] ?? 'INR')), $status, $method,
         $verified ? 1 : 0, $via, $errCode, $errText, $raw]
    );
}

// ------------------------------------------------------------------
// Fulfilment
// ------------------------------------------------------------------

/**
 * Mark an order paid and grant access - exactly once.
 * $payment: provider, provider_order_id, provider_payment_id, amount_paise, currency, method, raw
 * Returns 'fulfilled' | 'already_paid' | 'amount_mismatch' | 'not_found' | 'coupon_unavailable'.
 */
function fulfil_order(int $orderId, array $payment, string $via): string
{
    $result = db_transaction(function () use ($orderId, $payment, $via) {
        $order = db_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$order) {
            return 'not_found';
        }
        $free = $payment['provider'] === 'none';
        $paid = $free ? 0 : (int) $payment['amount_paise'];
        if (!$free && ($paid !== to_paise((float) $order['total']) || strtoupper($payment['currency']) !== $order['currency'])) {
            audit_log('payment_amount_mismatch', 'order', $order['order_number'], ['expected' => to_paise((float) $order['total']), 'received' => $paid], 'critical');
            return 'amount_mismatch';
        }
        if ($free && (float) $order['total'] > 0) {
            return 'amount_mismatch'; // a payable order can never be fulfilled without a payment
        }

        if (!$free) {
            record_payment_transaction((int) $order['id'], $payment, 'captured', true, $via);
        }
        // Already fulfilled (also after a refund: a late duplicate event must not re-grant access).
        if (in_array($order['status'], ['paid', 'refunded', 'partially_refunded'], true)) {
            return 'already_paid';
        }

        // Coupon limits are re-checked under a row lock (two pending orders, concurrent buyers).
        $couponIssue = null;
        if ($order['coupon_id']) {
            $coupon = db_one('SELECT * FROM coupons WHERE id = ? FOR UPDATE', [$order['coupon_id']]);
            if ($coupon) {
                $byUser = (int) db_value('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ? AND user_id = ? AND order_id <> ?', [$coupon['id'], $order['user_id'], $order['id']]);
                $overTotal = $coupon['usage_limit'] !== null && (int) $coupon['used_count'] >= (int) $coupon['usage_limit'];
                if ($overTotal || $byUser >= (int) $coupon['per_user_limit']) {
                    $couponIssue = $coupon['code'] . ($overTotal ? ' reached its total usage limit' : ' was already used by this student');
                    if ($free) {
                        // Nothing was charged, so the order can simply be stopped.
                        db_query("UPDATE orders SET status = 'cancelled' WHERE id = ?", [$order['id']]);
                        audit_log('coupon_over_limit', 'order', $order['order_number'], ['coupon' => $coupon['code']], 'warning');
                        return 'coupon_unavailable';
                    }
                    // The money is already captured: honour the order, log it and alert staff.
                    audit_log('coupon_over_limit', 'order', $order['order_number'], ['coupon' => $coupon['code'], 'paid' => true], 'warning');
                }
                $ins = db_query('INSERT IGNORE INTO coupon_redemptions (coupon_id, user_id, order_id, amount) VALUES (?, ?, ?, ?)',
                    [$coupon['id'], $order['user_id'], $order['id'], $order['discount']]);
                if ($ins->rowCount() > 0) {
                    db_query('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?', [$coupon['id']]);
                }
            }
        }

        db_query("UPDATE orders SET status = 'paid', paid_at = NOW() WHERE id = ?", [$order['id']]);
        $items = db_all('SELECT oi.course_id, oi.title, c.slug FROM order_items oi JOIN courses c ON c.id = oi.course_id WHERE oi.order_id = ?', [$order['id']]);
        $duplicates = [];
        foreach ($items as $item) {
            // Paid for a course the student already had through another paid order (e.g. two payment pages
            // paid one after the other): access is simply kept, and staff are asked to refund one payment.
            if (!$free && ($prev = db_value("SELECT o.order_number FROM course_enrolments e JOIN orders o ON o.id = e.order_id
                    WHERE e.user_id = ? AND e.course_id = ? AND e.status = 'active' AND (e.expires_at IS NULL OR e.expires_at > NOW())
                      AND e.order_id <> ? AND o.status IN ('paid', 'partially_refunded') AND o.total > 0 LIMIT 1",
                    [$order['user_id'], $item['course_id'], $order['id']]))) {
                $duplicates[] = $item['title'] . ' (already paid in order ' . $prev . ')';
            }
            enrol_user((int) $order['user_id'], (int) $item['course_id'], 'purchase', (int) $order['id']);
        }
        if ($duplicates) {
            audit_log('payment_duplicate_purchase', 'order', $order['order_number'], ['courses' => $duplicates], 'warning');
        }
        // Legacy receipt number kept for older screens; the GST invoice is issued after commit.
        db_query('INSERT IGNORE INTO receipts (order_id, receipt_number, amount) VALUES (?, ?, ?)',
            [$order['id'], 'RCPT-' . date('Y') . '-' . str_pad((string) $order['id'], 6, '0', STR_PAD_LEFT), $order['total']]);

        audit_log('order_paid', 'order', $order['order_number'], ['via' => $via, 'amount' => $order['total']]);
        $GLOBALS['__fulfilled_order'] = [$order, $items, $couponIssue, $duplicates];
        return 'fulfilled';
    });

    // Invoice + notifications run after the transaction commits (never for a rolled-back payment).
    if ($result === 'fulfilled' && !empty($GLOBALS['__fulfilled_order'])) {
        [$order, $items, $couponIssue, $duplicates] = $GLOBALS['__fulfilled_order'] + [3 => []];
        unset($GLOBALS['__fulfilled_order']);
        $charged = (float) $order['total'] > 0;
        $invoice = null;
        if ($charged) {
            try {
                $invoice = issue_invoice((int) $order['id']);
            } catch (Throwable $e) {
                // The order stays paid; the invoice is issued on demand when it is first opened.
                error_log('[laxyam] invoice not issued for ' . $order['order_number'] . ': ' . $e->getMessage());
                audit_log('invoice_issue_failed', 'order', $order['order_number'], ['error' => mb_substr($e->getMessage(), 0, 200)], 'warning');
            }
        }
        if ($charged && $order['coupon_id']) {
            try {
                require_once APP_ROOT . '/includes/referrals.php';
                referral_reward_for_order($order, $items); // once per order; the order is already paid either way
            } catch (Throwable $e) {
                error_log('[laxyam] referral reward failed for ' . $order['order_number'] . ': ' . $e->getMessage());
            }
        }
        require_once APP_ROOT . '/includes/notify.php';
        $first = $items[0] ?? null;
        $courses = implode(', ', array_column($items, 'title'));
        $link = $first ? '/enrolled/' . $first['slug'] . '?order=' . rawurlencode($order['order_number']) : '/dashboard/courses';
        if ($charged) {
            send_template('payment_success', (int) $order['user_id'], [
                'course' => $courses, 'amount' => inr($order['total']), 'order' => $order['order_number'], 'link' => $link,
                'button_label' => 'Start learning',
                'links' => [['Download invoice (PDF)', invoice_signed_url($order['order_number'])]],
                'related' => ['order', (int) $order['id']],
                // WhatsApp template variables ({{invoice_url}}, {{invoice_number}}, {{order_id}})
                'invoice_url' => invoice_signed_url($order['order_number']), 'invoice_number' => $invoice['invoice_number'] ?? '',
                'order_id' => $order['order_number'],
            ]);
            // Checkout promises the invoice at the billing email. payment_success (above) goes to the
            // account email, so a different billing address (e.g. a parent paying) gets the invoice too.
            if ($invoice) {
                $billTo = strtolower(trim((string) ($order['billing_email'] ?? '')));
                $account = strtolower(trim((string) db_value('SELECT email FROM users WHERE id = ?', [$order['user_id']])));
                if ($billTo !== '' && $billTo !== $account) {
                    try {
                        invoice_send_email($invoice); // logged in email_log (related order), retried by the queue on failure
                    } catch (Throwable $e) {
                        error_log('[laxyam] invoice email to billing address failed for ' . $order['order_number'] . ': ' . $e->getMessage());
                    }
                }
            }
        } else {
            send_template('enrolment', (int) $order['user_id'], [
                'course' => $courses, 'order' => $order['order_number'], 'link' => $link, 'button_label' => 'Start learning',
                'related' => ['order', (int) $order['id']],
            ]);
        }
        notify_staff_email(setting('payment_notify_email'), ($charged ? 'New payment: ' : 'Free enrolment (100% coupon): ') . $order['order_number'],
            'Order ' . $order['order_number'] . ($charged ? ' paid: ' . inr($order['total']) : ' completed with a 100% coupon') . ' for ' . $courses . '.'
            . ($invoice ? ' Invoice ' . $invoice['invoice_number'] . ' issued.' : ''), 'orders.php?order=' . urlencode($order['order_number']));
        if ($duplicates) {
            notify_staff_email(setting('payment_notify_email'), 'Possible double payment: ' . $order['order_number'],
                'Order ' . $order['order_number'] . ' was paid (' . inr($order['total']) . ') for a course the student already had through another paid order: '
                . implode('; ', $duplicates) . '. Their access is unchanged. Check both payments in the ' . payment_provider_label((string) $order['provider'])
                . ' dashboard, refund one of them there, then record the refund on the order page with "Revoke course access" unticked'
                . ' (the student keeps the course through the other order).',
                'orders.php?order=' . urlencode($order['order_number']));
        }
        if ($couponIssue) {
            notify_staff_email(setting('payment_notify_email'), 'Coupon limit exceeded: ' . $order['order_number'],
                'Order ' . $order['order_number'] . ' was paid with coupon ' . $couponIssue . '. The payment was captured, so the order was honoured. Review the coupon limits.',
                'orders.php?order=' . urlencode($order['order_number']));
        }
    }
    return $result;
}

/**
 * Record a failed attempt. No email here: the student often retries in the same Razorpay / Cashfree
 * window and succeeds; notify_stale_failed_payments() (scheduler) tells them later if not.
 * A paid order is never set back to failed (only 'pending' orders change status).
 */
function mark_payment_failed(int $orderId, array $payment): void
{
    db_transaction(function () use ($orderId, $payment) {
        $order = db_one('SELECT id, status FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$order) {
            return;
        }
        record_payment_transaction($orderId, $payment, 'failed');
        db_query("UPDATE orders SET status = 'failed' WHERE id = ? AND status = 'pending'", [$orderId]);
    });
}

/**
 * Scheduler job: send payment_failed once for orders that are still unpaid 15+ minutes after a
 * failed attempt (not when the student has since bought the course another way). Returns the count.
 * First re-checks a few pending / failed Cashfree orders with the gateway (reconcile_pending_payments()),
 * and every Cashfree order is asked about once more right before its email: no email when Cashfree now
 * reports it paid, being paid or under review, and no email (and nothing marked) when Cashfree cannot be
 * reached - the next run tries again.
 */
function notify_stale_failed_payments(): int
{
    try {
        reconcile_pending_payments();
    } catch (Throwable $e) {
        error_log('[laxyam] payment reconciliation failed: ' . $e->getMessage());
    }
    $rows = db_all(
        "SELECT o.id, o.user_id, o.order_number, o.provider, MAX(t.updated_at) AS failed_at
         FROM orders o JOIN payment_transactions t ON t.order_id = o.id AND t.status = 'failed'
         WHERE o.status = 'failed'
           AND NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.action = 'payment_failed_notified' AND a.entity_type = 'order' AND a.entity_id = o.order_number)
         GROUP BY o.id, o.user_id, o.order_number, o.provider
         HAVING failed_at <= NOW() - INTERVAL 15 MINUTE AND failed_at >= NOW() - INTERVAL 2 DAY
         ORDER BY o.id LIMIT 50"
    );
    if (!$rows) {
        return 0;
    }
    require_once APP_ROOT . '/includes/notify.php';
    $sent = 0;
    $cfChecks = 0;
    $cfReachable = true;
    foreach ($rows as $o) {
        if ($o['provider'] === 'cashfree') {
            // Last word from Cashfree before telling the student the payment failed (at most 5 checks a run,
            // each order at most every 10 minutes; skipped orders stay unmarked and are tried next run).
            if (!$cfReachable || !cashfree_configured() || $cfChecks >= 5 || !rate_limit('cf-failed-recheck', $o['order_number'], 1, 600)) {
                continue;
            }
            $cfChecks++;
            $full = db_one('SELECT * FROM orders WHERE id = ?', [$o['id']]);
            try {
                $state = $full ? cashfree_sync_order($full, 'api', 8)['state'] : 'failed';
            } catch (CashfreeOrderNotFound) {
                // Not on this Cashfree account / mode (a sandbox test order after going live): nothing to tell anyone.
                audit_log('payment_failed_notified', 'order', $o['order_number'], ['skipped' => 'unknown_to_cashfree']);
                continue;
            } catch (RuntimeException) {
                $cfReachable = false;
                continue;
            }
            if ($state === 'paid' || $state === 'pending') {
                continue; // paid after all, or a newer attempt is still being confirmed (order is pending again)
            }
            if ($state === 'mismatch') {
                audit_log('payment_failed_notified', 'order', $o['order_number'], ['skipped' => 'amount_mismatch']);
                continue; // money arrived but needs a staff check (staff were alerted): never say "try again"
            }
        }
        $item = db_one('SELECT oi.course_id, oi.title, c.slug FROM order_items oi JOIN courses c ON c.id = oi.course_id WHERE oi.order_id = ? LIMIT 1', [$o['id']]);
        // Mark first so a crash mid-send can never cause a second email.
        audit_log('payment_failed_notified', 'order', $o['order_number']);
        if (!$item || active_enrolment((int) $o['user_id'], (int) $item['course_id'])
            || db_value("SELECT 1 FROM orders x JOIN order_items xi ON xi.order_id = x.id WHERE x.user_id = ? AND xi.course_id = ? AND x.status = 'paid' AND x.id <> ? LIMIT 1",
                [$o['user_id'], $item['course_id'], $o['id']])) {
            continue; // bought it after all
        }
        send_template('payment_failed', (int) $o['user_id'], [
            'course' => $item['title'], 'order' => $o['order_number'], 'link' => '/checkout/' . $item['slug'],
            'button_label' => 'Try again', 'related' => ['order', (int) $o['id']],
        ]);
        $sent++;
    }
    return $sent;
}
