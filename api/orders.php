<?php
/**
 * Checkout orders.
 *
 *   GET  orders.php?action=quote&slug=&coupon=&state=  server-side price breakdown incl. GST split + billing prefill
 *   POST orders.php?action=create                      slug, coupon, billing{name,email,phone,state,gstin?,address?} -> order + gateway parameters
 *                                                      (PAYMENT_PROVIDER=razorpay: Razorpay Checkout options in `gateway`;
 *                                                       PAYMENT_PROVIDER=cashfree: {provider, payment_session_id, mode, order_number};
 *                                                       when an earlier Cashfree order for the course is paid / still being paid /
 *                                                       under review: {order_number, status, gateway: null, existing: true, state, next_url})
 *   GET  orders.php?action=status&order=               order status for the payment result page; a pending Cashfree order is
 *                                                      first re-checked with the Cashfree API (server to server); needs_review = a
 *                                                      payment with the wrong amount arrived and staff were alerted
 *   GET  orders.php?action=receipt&order=              printable HTML receipt (paid orders only, kept for older links)
 *   GET  orders.php?action=invoice&order=[&format=html][&sig=]
 *                                                      GST invoice: PDF download (default) or A4 HTML. Allowed for the
 *                                                      order owner, staff with orders.view, or a valid signed link from emails.
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/payments.php';

$action = $_GET['action'] ?? '';
if ($action === 'create') {
    require_method('POST');
    require_csrf();
} else {
    require_method('GET');
}

match ($action) {
    'quote' => quote(),
    'create' => create(),
    'status' => status(),
    'receipt' => receipt(),
    'invoice' => invoice(),
    default => api_error('Unknown action.', 404, 'not_found'),
};

function checkout_course(string $slug): array
{
    $course = preg_match('/^[a-z0-9-]{1,200}$/', $slug)
        ? db_one("SELECT c.*, cat.name AS category_name, cat.color AS category_color, cat.icon AS category_icon, cat.slug AS category_slug
                  FROM courses c JOIN course_categories cat ON cat.id = c.category_id WHERE c.slug = ? AND c.status = 'published'", [$slug])
        : null;
    if (!$course) {
        api_error('Course not found.', 404, 'not_found');
    }
    if (course_enquiry_only($course)) {
        api_error('Admission to this course is through our admissions desk, not online payment. Use "Apply Now" on the course page and our counsellor will call you.', 409, 'enquiry_only');
    }
    return $course;
}

/** Prefill for the checkout billing form: the last billing snapshot, else the account + profile. */
function billing_prefill(array $user): array
{
    $last = db_one('SELECT billing_name, billing_email, billing_phone, billing_state, billing_gstin, billing_address FROM orders
                    WHERE user_id = ? AND billing_name IS NOT NULL ORDER BY id DESC LIMIT 1', [$user['id']]);
    if ($last) {
        return ['name' => $last['billing_name'], 'email' => $last['billing_email'], 'phone' => $last['billing_phone'],
            'state' => canonical_state($last['billing_state']), 'gstin' => $last['billing_gstin'], 'address' => $last['billing_address']];
    }
    $phone = preg_replace('/^(?:\+91|91(?=\d{10}$))/', '', preg_replace('/\D/', '', (string) $user['phone']) ?? '');
    return [
        'name' => $user['name'], 'email' => $user['email'], 'phone' => $phone ?: null,
        'state' => canonical_state((string) db_value('SELECT state FROM student_profiles WHERE user_id = ?', [$user['id']])),
        'gstin' => null, 'address' => null,
    ];
}

function quote(): never
{
    $user = require_student();
    $course = checkout_course(query_str('slug', 200));
    $card = format_course_card($course + ['lessons_count' => 0]);
    $billing = billing_prefill($user);
    $state = canonical_state(query_str('state', 60)) ?? $billing['state'];
    $quote = build_quote($course, (int) $user['id'], query_str('coupon', 40), $state);
    api_ok([
        'course' => $card + ['validity_days' => $course['validity_days'] !== null ? (int) $course['validity_days'] : null],
        'quote' => $quote,
        'tax' => $quote['tax'],
        'billing' => $billing,
        'states' => array_map(fn($name, $code) => ['name' => $name, 'code' => $code], array_keys(indian_states()), indian_states()),
        'seller' => ['legal_name' => setting('company_legal_name', 'LAXYAM INNOVATIVE EDUSKIL PRIVATE LIMITED'), 'state' => gst_config()['seller_state']],
        'already_enrolled' => active_enrolment((int) $user['id'], (int) $course['id']) !== null,
        'prerequisite' => unmet_prerequisite((int) $user['id'], (int) $course['id']),
        'payments_configured' => payments_configured(),
        'payment_mode' => config('PAYMENT_MODE', 'sandbox'),
        'payment_provider' => payment_provider(),
        'payment_provider_label' => payment_provider_label(),
        'email_verified' => $user['email_verified_at'] !== null,
    ]);
}

function create(): never
{
    $user = require_student();
    $uid = (int) $user['id'];
    // Two limits: a loose one on every call (a student correcting billing typos gets 422s, not a 429),
    // and the strict 'order-create' one below, counted only once the request is valid and an order
    // (and a gateway order) is really about to be created.
    if (!rate_limit('order-attempt', (string) $uid, 60, 3600)) {
        api_error('Too many checkout attempts. Please try again later.', 429, 'rate_limited');
    }
    $in = request_body();
    $course = checkout_course(is_string($in['slug'] ?? null) ? $in['slug'] : '');

    if (active_enrolment($uid, (int) $course['id'])) {
        api_error('You are already enrolled in this course.', 409, 'already_enrolled');
    }
    if ($pre = unmet_prerequisite($uid, (int) $course['id'])) {
        api_error("Please complete “{$pre}” before enrolling in this course.", 409, 'prerequisite');
    }
    [$billing, $billingErrors] = validate_billing($in['billing'] ?? null, $user);
    if ($billingErrors) {
        api_error('Please check your billing details.', 422, 'validation_failed', $billingErrors);
    }
    $quote = build_quote($course, $uid, is_string($in['coupon'] ?? null) ? $in['coupon'] : null, $billing['state']);
    if ($quote['coupon_error']) {
        // Clear field error: the checkout shows it next to the coupon box with a "Remove coupon" action.
        api_error($quote['coupon_error'] . ' Remove the coupon to pay the full price.', 422, 'coupon_invalid', ['coupon' => $quote['coupon_error']]);
    }
    if ($quote['subtotal'] <= 0) {
        api_error('This course is free. Use “Enroll for Free” on the course page.', 409, 'free_course');
    }
    if ($quote['total'] > 0 && !payments_configured()) {
        api_error('Online payment is not configured yet. Please contact the academy to enrol.', 503, 'payments_not_configured');
    }
    $provider = $quote['total'] > 0 ? payment_provider() : 'none';
    if ($provider === 'cashfree') {
        // Cashfree requires the customer's 10-digit mobile (older clients without the billing form use the profile number).
        $phone = cashfree_phone($billing['phone'] ?? null);
        if ($phone === null) {
            api_error('Please check your billing details.', 422, 'validation_failed',
                ['billing.phone' => 'Enter a valid 10-digit Indian mobile number - it is needed to complete the payment.']);
        }
        $billing['phone'] = $phone;
    }
    $tax = $quote['tax']['enabled'] ? $quote['tax']['amount'] : 0.0;
    $billingParams = [$billing['name'], $billing['email'], $billing['phone'], $billing['state'], $billing['gstin'], $billing['address']];

    // Re-use a recent identical pending order of the same gateway instead of creating duplicates (billing snapshot refreshed).
    $existing = db_one(
        "SELECT o.* FROM orders o JOIN order_items oi ON oi.order_id = o.id
         WHERE o.user_id = ? AND oi.course_id = ? AND o.status = 'pending' AND o.total = ? AND o.provider = ?
           AND (o.coupon_id <=> ?) AND o.provider_order_id IS NOT NULL AND o.created_at > NOW() - INTERVAL 30 MINUTE
         ORDER BY o.id DESC LIMIT 1",
        [$uid, $course['id'], $quote['total'], $provider, $quote['coupon']['id'] ?? null]
    );
    $session = null;
    if ($existing && $provider === 'cashfree') {
        // A Cashfree order keeps the phone it was created with, and must still be ACTIVE on Cashfree's side.
        // Not re-usable -> handled with the other earlier orders just below (paid? still being paid? else closed).
        $session = $existing['billing_phone'] === $billing['phone'] ? cashfree_active_session($existing) : null;
        if ($session === null) {
            $existing = null;
        }
    }
    if ($existing) {
        db_query('UPDATE orders SET billing_name = ?, billing_email = ?, billing_phone = ?, billing_state = ?, billing_gstin = ?, billing_address = ?, tax = ? WHERE id = ?',
            [...$billingParams, $tax, $existing['id']]);
        api_ok($provider === 'cashfree' ? cashfree_params($existing, (string) $session) : gateway_params($existing, $billing, $course), 200);
    }

    if ($provider === 'cashfree') {
        // Never leave two payable Cashfree orders for the same course: an earlier order that was paid (or is
        // being paid / needs review) is finished instead, and any other still-open one is terminated first.
        try {
            $earlier = cashfree_close_open_orders($uid, (int) $course['id']);
        } catch (RuntimeException $e) {
            error_log('[laxyam] cashfree checkout blocked for user ' . $uid . ': ' . $e->getMessage());
            api_error('Could not start the payment. Please try again in a minute.', 502, 'gateway_error');
        }
        if ($earlier) {
            $no = $earlier['order']['order_number'];
            api_ok(['order_number' => $no, 'status' => $earlier['state'] === 'paid' ? 'paid' : $earlier['order']['status'], 'provider' => 'cashfree',
                'gateway' => null, 'existing' => true, 'state' => $earlier['state'],
                'next_url' => $earlier['state'] === 'paid' ? '/enrolled/' . $course['slug'] . '?order=' . rawurlencode($no) : '/payment/result?order=' . rawurlencode($no)], 200);
        }
    }

    if (!rate_limit('order-create', (string) $uid, 15, 3600)) {
        api_error('Too many checkout attempts. Please try again later.', 429, 'rate_limited');
    }
    // 15 alphanumerics: also a valid Cashfree order_id (3-45 chars, alphanumeric / _ / -).
    $orderNumber = 'LXA' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
    $orderId = db_transaction(function () use ($uid, $course, $quote, $orderNumber, $billingParams, $tax, $provider) {
        db_query(
            "INSERT INTO orders (order_number, user_id, status, subtotal, discount, tax, total, currency, coupon_id,
                billing_name, billing_email, billing_phone, billing_state, billing_gstin, billing_address, provider, ip_address)
             VALUES (?, ?, 'pending', ?, ?, ?, ?, 'INR', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$orderNumber, $uid, $quote['subtotal'], $quote['discount'], $tax, $quote['total'], $quote['coupon']['id'] ?? null,
             ...$billingParams, $provider, client_ip()]
        );
        $id = (int) db()->lastInsertId();
        db_query('INSERT INTO order_items (order_id, course_id, title, unit_price, discount, total) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $course['id'], $course['title'], $quote['subtotal'], $quote['discount'], $quote['total']]);
        return $id;
    });
    record_attribution('order', $orderId, $in['attribution'] ?? null);

    // 100% discount: nothing to collect - fulfil now (no payment record is faked and no invoice is issued).
    if ($quote['total'] <= 0) {
        $result = fulfil_order($orderId, ['provider' => 'none'], 'api');
        if ($result === 'coupon_unavailable') {
            api_error('This coupon is no longer available. Remove it to continue.', 409, 'coupon_invalid', ['coupon' => 'This coupon is no longer available.']);
        }
        api_ok(['order_number' => $orderNumber, 'status' => 'paid', 'gateway' => null,
            'next_url' => '/enrolled/' . $course['slug'] . '?order=' . rawurlencode($orderNumber)], 201);
    }

    $order = db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    $cf = null;
    try {
        if ($provider === 'cashfree') {
            // The amount sent is the server-side order total from build_quote(), never a client value.
            $cf = cashfree_create_order($order, $billing, $course['title']);
            $providerOrderId = $cf['cf_order_id'];
        } else {
            $providerOrderId = razorpay_create_order($order);
        }
    } catch (RuntimeException $e) {
        db_query("UPDATE orders SET status = 'failed' WHERE id = ?", [$orderId]);
        api_error($e->getMessage(), 502, 'gateway_error');
    }
    db_query('UPDATE orders SET provider_order_id = ? WHERE id = ?', [$providerOrderId, $orderId]);
    // "Checkout opened" record; completed in place when the payment is captured or fails.
    db_query("INSERT INTO payment_transactions (order_id, provider, provider_order_id, amount, currency, status) VALUES (?, ?, ?, ?, 'INR', 'created')",
        [$orderId, $provider, $providerOrderId, $order['total']]);
    $order['provider_order_id'] = $providerOrderId;
    api_ok($cf ? cashfree_params($order, $cf['payment_session_id']) : gateway_params($order, $billing, $course), 201);
}

/**
 * What the Cashfree JS SDK v3 needs: Cashfree({ mode }).checkout({ paymentSessionId }). The session id is
 * not a secret (it only opens the hosted payment page for this order); keys stay in PHP.
 */
function cashfree_params(array $order, string $sessionId): array
{
    $mode = cashfree_sdk_mode();
    return [
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'provider' => 'cashfree',
        'payment_session_id' => $sessionId,
        'mode' => $mode,
        'gateway' => [
            'provider' => 'cashfree',
            'payment_session_id' => $sessionId,
            'mode' => $mode,
            'order_number' => $order['order_number'],
            'result_url' => '/payment/result?order=' . rawurlencode($order['order_number']),
        ],
    ];
}

/** Public parameters for Razorpay Checkout - the key id is public by design; the secret never leaves PHP. */
function gateway_params(array $order, array $billing, array $course): array
{
    return [
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'provider' => 'razorpay',
        'gateway' => [
            'provider' => 'razorpay',
            'key' => (string) config('RAZORPAY_KEY_ID'),
            'order_id' => $order['provider_order_id'],
            'amount' => to_paise((float) $order['total']),
            'currency' => $order['currency'],
            'name' => setting('site_name', 'Laxyam Academy'),
            'description' => $course['title'],
            'image' => public_file_url(setting('logo_path') ?: 'assets/logo.png'),
            'prefill' => ['name' => $billing['name'], 'email' => $billing['email'], 'contact' => $billing['phone']],
            'notes' => ['order_number' => $order['order_number']],
            'theme' => ['color' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) setting('theme_primary')) ? setting('theme_primary') : '#0B63F6'],
        ],
    ];
}

function own_order(string $number, array $user): array
{
    $order = preg_match('/^[A-Z0-9]{6,30}$/', $number)
        ? db_one('SELECT * FROM orders WHERE order_number = ? AND user_id = ?', [$number, $user['id']])
        : null;
    if (!$order) {
        api_error('Order not found.', 404, 'not_found');
    }
    return $order;
}

function status(): never
{
    $user = require_student();
    $order = own_order(query_str('order', 30), $user);
    // Cashfree: an unpaid order is re-checked with the Cashfree API on every poll (throttled per order), so the
    // result page never depends on the return URL or on the webhook arriving first.
    $sync = null;
    if ($order['provider'] === 'cashfree' && cashfree_configured()
        && ($order['status'] === 'pending' || ($order['status'] === 'failed' && strtotime((string) $order['created_at']) > time() - 86400))
        && rate_limit('cf-status-sync', $order['order_number'], 40, 600)) {
        try {
            $sync = cashfree_sync_order($order, 'api');
        } catch (RuntimeException) {
            $sync = null; // Cashfree unreachable: report our last known status, the page polls again
        }
        $order = db_one('SELECT * FROM orders WHERE id = ?', [$order['id']]);
    }
    $attempt = db_one("SELECT status, method, error_description FROM payment_transactions WHERE order_id = ? AND status <> 'created' ORDER BY id DESC LIMIT 1", [$order['id']]);
    $item = db_one('SELECT oi.title, c.slug, c.id AS course_id, c.thumbnail_path, c.validity_days,
                           cat.name AS category_name, cat.slug AS category_slug, cat.color AS category_color, cat.icon AS category_icon
                    FROM order_items oi JOIN courses c ON c.id = oi.course_id LEFT JOIN course_categories cat ON cat.id = c.category_id
                    WHERE oi.order_id = ? LIMIT 1', [$order['id']]);
    $enrolment = $item ? db_one('SELECT status, expires_at FROM course_enrolments WHERE user_id = ? AND course_id = ?', [$user['id'], $item['course_id']]) : null;
    api_ok([
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'total' => (float) $order['total'],
        'paid_at' => iso_datetime($order['paid_at']),
        'course' => $item ? [
            'title' => $item['title'], 'slug' => $item['slug'], 'thumbnail_url' => public_file_url($item['thumbnail_path']),
            // Same shape as students.php?action=enrolment; category_name kept for older clients.
            'category' => $item['category_slug'] !== null ? ['name' => $item['category_name'], 'slug' => $item['category_slug'],
                'color' => $item['category_color'], 'icon' => $item['category_icon']] : null,
            'category_name' => $item['category_name'],
            'validity_days' => $item['validity_days'] !== null ? (int) $item['validity_days'] : null,
        ] : null,
        'access' => $enrolment ? ['status' => $enrolment['status'], 'expires_at' => iso_datetime($enrolment['expires_at'])] : null,
        'receipt_number' => db_value('SELECT receipt_number FROM receipts WHERE order_id = ?', [$order['id']]),
        'invoice' => invoice_for_order((int) $order['id']),
        'refunded_amount' => invoice_refunds((int) $order['id'])['amount'],
        'provider' => $order['provider'],
        'provider_label' => $order['provider'] ? payment_provider_label($order['provider']) : null,
        // Cashfree only: what the gateway reported on this poll (state: paid | pending | failed | not_attempted | mismatch).
        'gateway_state' => $sync['state'] ?? null,
        'payment_status' => $sync['payment_status'] ?? null,
        // A payment arrived whose amount did not match (staff alerted): shown as "being checked", never as "try again".
        'needs_review' => !in_array($order['status'], ['paid', 'refunded', 'partially_refunded'], true)
            && (($sync['state'] ?? '') === 'mismatch' || (bool) db_value("SELECT 1 FROM audit_logs WHERE action = 'payment_amount_mismatch' AND entity_type = 'order' AND entity_id = ? LIMIT 1", [$order['order_number']])),
        'last_attempt' => $attempt ? ['status' => $attempt['status'], 'method' => $attempt['method'] ? payment_method_label($attempt['method']) : null,
            'message' => $attempt['status'] === 'failed' ? $attempt['error_description'] : null] : null,
    ]);
}

function receipt(): never
{
    $user = require_student();
    $order = own_order(query_str('order', 30), $user);
    $r = db_one('SELECT * FROM receipts WHERE order_id = ?', [$order['id']]);
    if (!$r || $order['status'] !== 'paid' && $order['status'] !== 'refunded' && $order['status'] !== 'partially_refunded') {
        api_error('A receipt is available once the payment is confirmed.', 404, 'not_found');
    }
    $items = db_all('SELECT title, unit_price, discount, total FROM order_items WHERE order_id = ?', [$order['id']]);
    $tx = db_one("SELECT provider_payment_id, method FROM payment_transactions WHERE order_id = ? AND verified = 1 ORDER BY id DESC LIMIT 1", [$order['id']]);
    $invoice = invoice_for_order((int) $order['id']);
    $nonce = base64_encode(random_bytes(16));

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'");
    $rows = '';
    foreach ($items as $i) {
        $rows .= '<tr><td>' . e($i['title']) . '</td><td>' . e(inr($i['unit_price'])) . '</td><td>' . e(inr($i['discount'])) . '</td><td>' . e(inr($i['total'])) . '</td></tr>';
    }
    // "Billed to" comes from the snapshot taken at checkout, not the current profile.
    $billedName = $order['billing_name'] ?: $user['name'];
    $billedEmail = $order['billing_email'] ?: $user['email'];
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Receipt ' . e($r['receipt_number']) . '</title>
<style>body{font-family:Segoe UI,Arial,sans-serif;color:#14233B;max-width:720px;margin:24px auto;padding:0 16px}h1{color:#08264D;margin:0}
table{width:100%;border-collapse:collapse;margin:20px 0}td,th{border-bottom:1px solid #E2EAF4;padding:10px;text-align:left}th{background:#F5F9FF}
.muted{color:#64748B;font-size:14px}.right{text-align:right}.tools{float:right;display:flex;gap:8px}.tools a,.tools button{padding:8px 16px;border:1px solid #0B63F6;border-radius:8px;background:#fff;color:#0B63F6;font:inherit;cursor:pointer;text-decoration:none}@media print{.tools{display:none}}</style>
<div class="tools">' . ($invoice ? '<a href="' . e($invoice['url']) . '">Download invoice (PDF)</a>' : '') . '<button type="button" id="print">Print</button></div>
<script nonce="' . e($nonce) . '">document.getElementById("print").addEventListener("click",function(){window.print()});</script>
<h1>' . e(setting('site_name', 'Laxyam Academy')) . '</h1><p class="muted">' . e(setting('company_legal_name', '')) . (setting('company_cin') ? ' · CIN ' . e(setting('company_cin')) : '') . '<br>' . e(setting('company_registered_address') ?: setting('address', '')) . '<br>' . e(setting('contact_email', '')) . ' · ' . e(setting('contact_phone', '')) . '</p>
<h2>Payment Receipt</h2>
<p><strong>Receipt:</strong> ' . e($r['receipt_number']) . ($invoice ? '<br><strong>Invoice:</strong> ' . e($invoice['invoice_number']) : '') . '<br><strong>Order:</strong> ' . e($order['order_number']) . '<br><strong>Date:</strong> ' . e(date('j M Y, g:i A', strtotime((string) $order['paid_at']))) . '<br>
<strong>Billed to:</strong> ' . e($billedName) . ' (' . e($billedEmail) . ')' . ($tx ? '<br><strong>Payment ID:</strong> ' . e($tx['provider_payment_id']) . ($tx['method'] ? ' (' . e(payment_method_label($tx['method'])) . ')' : '') : '') . '</p>
<table><tr><th>Course</th><th>Price</th><th>Discount</th><th>Total</th></tr>' . $rows . '
<tr><td colspan="3" class="right"><strong>Amount paid</strong></td><td><strong>' . e(inr($order['total'])) . '</strong></td></tr></table>
<p class="muted">Status: ' . e(ucwords(str_replace('_', ' ', $order['status']))) . '. This is a computer-generated receipt.</p></html>';
    exit;
}

/** Friendly error for invoice links opened in a browser tab (JSON for API clients). */
function invoice_fail(string $message, int $status): never
{
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        api_error($message, $status, $status === 401 ? 'unauthenticated' : 'not_found');
    }
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Invoice</title>'
        . '<body style="margin:0;background:#F5F9FF;font-family:Segoe UI,Arial,sans-serif;color:#14233B;display:grid;place-items:center;min-height:100vh">'
        . '<div style="background:#fff;border:1px solid #E2EAF4;border-radius:16px;padding:28px;max-width:420px;margin:16px;text-align:center">'
        . '<h1 style="font-size:20px;color:#08264D;margin:0 0 8px">Invoice unavailable</h1><p style="color:#475569">' . e($message) . '</p>'
        . '<p><a style="color:#0B63F6" href="' . e(app_url($status === 401 ? 'login' : 'dashboard/purchases')) . '">' . ($status === 401 ? 'Log in' : 'Go to My Purchases') . '</a></p></div></body></html>';
    exit;
}

function invoice(): never
{
    $number = query_str('order', 30);
    $sig = query_str('sig', 64);
    $order = preg_match('/^[A-Z0-9]{6,30}$/', $number) ? db_one('SELECT * FROM orders WHERE order_number = ?', [$number]) : null;
    $user = current_user();
    $signed = $order && $sig !== '' && invoice_sig_valid($order['order_number'], $sig);
    $owner = $order && $user && $user['user_type'] === 'student' && (int) $order['user_id'] === (int) $user['id'];
    $staff = $order && $user && can('orders.view', $user);
    if (!$signed && !$owner && !$staff) {
        if (!$user && $sig === '') {
            invoice_fail('Please log in to download your invoice.', 401);
        }
        // Same answer for "no such order" and "not yours", so order numbers cannot be probed.
        invoice_fail('This invoice could not be found.', 404);
    }
    try {
        $inv = issue_invoice((int) $order['id']);
    } catch (RuntimeException $e) {
        invoice_fail($e->getMessage(), 404);
    }
    if ($staff && !$owner) {
        audit_log('invoice_viewed', 'order', $order['order_number'], ['invoice' => $inv['invoice_number']]);
    }
    $items = invoice_items($inv);

    if (($_GET['format'] ?? '') === 'html') {
        $nonce = base64_encode(random_bytes(16));
        $logo = (string) public_file_url(setting('logo_path') ?: 'assets/logo.png');
        $logoOrigin = preg_match('#^(https?://[^/]+)#i', $logo, $m) ? ' ' . $m[1] : '';
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:{$logoOrigin}; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
        $pdfUrl = app_url('api/orders.php?' . http_build_query(['action' => 'invoice', 'order' => $order['order_number']] + ($signed ? ['sig' => $sig] : [])));
        echo render_invoice_html($inv, $items, ['nonce' => $nonce, 'toolbar' => true, 'pdf_url' => $pdfUrl]);
        exit;
    }

    $pdf = render_invoice_pdf($inv, $items);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . invoice_filename($inv) . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}
