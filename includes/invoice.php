<?php
/**
 * GST-ready invoices for paid orders.
 *
 *   issue_invoice($orderId)          one invoice per paid order (idempotent), consecutive number per
 *                                    Indian financial year: <invoice_prefix>/<26-27>/<0001>
 *   invoice_for_order($orderId)      ['invoice_number', 'url'] or null (issues on demand for older paid orders)
 *   invoice_signed_url($orderNumber) link that works without a login (used in emails)
 *   render_invoice_pdf($inv, $items) / render_invoice_html($inv, $items, $opts)
 *
 * Seller, buyer and amounts are snapshotted on the invoices row when it is issued, so later
 * edits to settings or the student's profile never change an issued invoice. Totals always
 * equal orders.total exactly (rounding differences go into the tax split, never the total).
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

const GSTIN_REGEX = '/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';
const CIN_REGEX = '/^[LU]\d{5}[A-Z]{2}\d{4}[A-Z]{3}\d{6}$/';
const PAN_REGEX = '/^[A-Z]{5}\d{4}[A-Z]$/';

// ------------------------------------------------------------------
// Reference data + formatting
// ------------------------------------------------------------------

/** Indian states and union territories => GST state code. */
function indian_states(): array
{
    return [
        'Andaman and Nicobar Islands' => '35', 'Andhra Pradesh' => '37', 'Arunachal Pradesh' => '12', 'Assam' => '18',
        'Bihar' => '10', 'Chandigarh' => '04', 'Chhattisgarh' => '22', 'Dadra and Nagar Haveli and Daman and Diu' => '26',
        'Delhi' => '07', 'Goa' => '30', 'Gujarat' => '24', 'Haryana' => '06', 'Himachal Pradesh' => '02',
        'Jammu and Kashmir' => '01', 'Jharkhand' => '20', 'Karnataka' => '29', 'Kerala' => '32', 'Ladakh' => '38',
        'Lakshadweep' => '31', 'Madhya Pradesh' => '23', 'Maharashtra' => '27', 'Manipur' => '14', 'Meghalaya' => '17',
        'Mizoram' => '15', 'Nagaland' => '13', 'Odisha' => '21', 'Puducherry' => '34', 'Punjab' => '03',
        'Rajasthan' => '08', 'Sikkim' => '11', 'Tamil Nadu' => '33', 'Telangana' => '36', 'Tripura' => '16',
        'Uttar Pradesh' => '09', 'Uttarakhand' => '05', 'West Bengal' => '19',
    ];
}

/** Canonical state name for free text (case-insensitive, common aliases), or null. */
function canonical_state(?string $state): ?string
{
    $s = strtolower(trim((string) $state));
    if ($s === '') {
        return null;
    }
    $aliases = ['new delhi' => 'delhi', 'nct of delhi' => 'delhi', 'orissa' => 'odisha', 'pondicherry' => 'puducherry',
        'uttaranchal' => 'uttarakhand', 'j&k' => 'jammu and kashmir', 'andaman & nicobar islands' => 'andaman and nicobar islands'];
    $s = $aliases[$s] ?? str_replace('&', 'and', $s);
    foreach (array_keys(indian_states()) as $name) {
        if (strtolower($name) === $s) {
            return $name;
        }
    }
    return null;
}

function state_code(?string $state): ?string
{
    $name = canonical_state($state);
    return $name ? indian_states()[$name] : null;
}

/** Indian digit grouping: 1234567.5 => "12,34,567.50". */
function inr_format(float|int|string|null $amount, bool $decimals = true): string
{
    $v = round((float) $amount, 2);
    [$int, $dec] = explode('.', number_format(abs($v), 2, '.', ''));
    if (strlen($int) > 3) {
        $int = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($int, 0, -3)) . ',' . substr($int, -3);
    }
    return ($v < 0 ? '-' : '') . $int . ($decimals ? '.' . $dec : '');
}

/** "₹9,999.00" (HTML / email) */
function inr(float|int|string|null $amount): string
{
    return '₹' . inr_format($amount);
}

/** Whole number in words, Indian system (crore / lakh / thousand). */
function number_words_indian(int $n): string
{
    static $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    static $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    if ($n === 0) {
        return 'Zero';
    }
    $two = fn(int $x): string => $x < 20 ? $ones[$x] : $tens[intdiv($x, 10)] . ($x % 10 ? '-' . $ones[$x % 10] : '');
    $parts = [];
    if ($n >= 10_000_000) {
        $parts[] = number_words_indian(intdiv($n, 10_000_000)) . ' Crore';
        $n %= 10_000_000;
    }
    foreach ([[100_000, 'Lakh'], [1_000, 'Thousand'], [100, 'Hundred']] as [$unit, $word]) {
        if ($n >= $unit) {
            $parts[] = $two(intdiv($n, $unit)) . ' ' . $word;
            $n %= $unit;
        }
    }
    if ($n > 0) {
        $parts[] = $two($n);
    }
    return implode(' ', $parts);
}

/** 9999 => "Rupees Nine Thousand Nine Hundred Ninety-Nine Only" */
function inr_words(float|int|string $amount): string
{
    $paiseTotal = (int) round(abs((float) $amount) * 100);
    $rupees = intdiv($paiseTotal, 100);
    $paise = $paiseTotal % 100;
    return 'Rupees ' . number_words_indian($rupees) . ($paise ? ' and ' . number_words_indian($paise) . ' Paise' : '') . ' Only';
}

/** Indian financial year (April-March) for a date, e.g. "2026-27". */
function financial_year(?DateTimeInterface $date = null): string
{
    $date ??= new DateTimeImmutable();
    $year = (int) $date->format('Y');
    $start = (int) $date->format('n') >= 4 ? $year : $year - 1;
    return $start . '-' . substr((string) ($start + 1), -2);
}

/** Razorpay `method` or Cashfree `payment_group` (credit_card, net_banking, upi…) -> label on invoices and reports. */
function payment_method_label(?string $method): string
{
    return match (strtolower((string) $method)) {
        'card' => 'Card', 'upi' => 'UPI', 'netbanking', 'net_banking' => 'Net banking', 'wallet', 'app' => 'Wallet', 'emi' => 'EMI',
        'cardless_emi' => 'Cardless EMI', 'paylater', 'pay_later' => 'Pay later', 'bank_transfer', 'banktransfer' => 'Bank transfer',
        'credit_card' => 'Credit card', 'debit_card' => 'Debit card', 'prepaid_card' => 'Prepaid card',
        'credit_card_emi' => 'Credit card EMI', 'debit_card_emi' => 'Debit card EMI', 'upi_credit_card' => 'UPI (credit card)',
        'upi_ppi', 'upi_ppi_offline' => 'UPI (wallet)',
        '' => 'Online payment', default => ucwords(str_replace('_', ' ', (string) $method)),
    };
}

// ------------------------------------------------------------------
// GST
// ------------------------------------------------------------------

/** Current GST settings. GST is charged only when enabled AND a GSTIN is set. */
function gst_config(): array
{
    $gstin = strtoupper(trim((string) setting('company_gstin', '')));
    $rate = max(0.0, min(40.0, (float) setting('gst_rate', '18')));
    $state = canonical_state(setting('company_state', 'Delhi')) ?? 'Delhi';
    return [
        'enabled' => setting('gst_enabled', '0') === '1' && $gstin !== '' && $rate > 0,
        'rate' => $rate,
        'inclusive' => setting('prices_include_gst', '1') !== '0',
        'seller_state' => $state,
        'seller_state_code' => preg_match('/^\d{2}$/', (string) setting('company_state_code')) ? (string) setting('company_state_code') : indian_states()[$state],
    ];
}

/** Split an amount into taxable value + GST. $inclusive: $amount already contains the tax. */
function gst_split(float $amount, float $rate, bool $inclusive, bool $intraState): array
{
    $amount = round($amount, 2);
    if ($rate <= 0 || $amount <= 0) {
        return ['taxable_value' => $amount, 'tax' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'total' => $amount];
    }
    if ($inclusive) {
        $taxable = round($amount / (1 + $rate / 100), 2);
        $tax = round($amount - $taxable, 2);
        $total = $amount;
    } else {
        $taxable = $amount;
        $tax = round($amount * $rate / 100, 2);
        $total = round($amount + $tax, 2);
    }
    $cgst = $intraState ? round($tax / 2, 2) : 0.0;
    return [
        'taxable_value' => $taxable,
        'tax' => $tax,
        'cgst' => $cgst,
        'sgst' => $intraState ? round($tax - $cgst, 2) : 0.0,
        'igst' => $intraState ? 0.0 : $tax,
        'total' => $total,
    ];
}

/**
 * Tax breakdown for a checkout amount (after coupon). Used by build_quote().
 * Returns the public `tax` object plus 'total' (payable, incl. GST when prices exclude it).
 */
function quote_tax(float $net, ?string $buyerState): array
{
    $cfg = gst_config();
    $buyer = canonical_state($buyerState);
    $intra = $buyer === null || $buyer === $cfg['seller_state'];
    if (!$cfg['enabled']) {
        $split = gst_split($net, 0, true, $intra);
    } else {
        $split = gst_split($net, $cfg['rate'], $cfg['inclusive'], $intra);
    }
    return [
        'enabled' => $cfg['enabled'],
        'rate' => $cfg['enabled'] ? $cfg['rate'] : 0.0,
        'inclusive' => $cfg['inclusive'],
        'taxable_value' => $split['taxable_value'],
        'cgst' => $split['cgst'],
        'sgst' => $split['sgst'],
        'igst' => $split['igst'],
        'amount' => $split['tax'],
        'intra_state' => $intra,
        'place_of_supply' => $buyer ?? $cfg['seller_state'],
        'total' => $split['total'],
    ];
}

// ------------------------------------------------------------------
// Signed links (emails) - HMAC with a private secret created on first use
// ------------------------------------------------------------------

function invoice_secret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    $read = fn() => (string) db_value("SELECT setting_value FROM site_settings WHERE setting_key = 'invoice_link_secret'");
    $value = $read();
    if (strlen($value) < 32) {
        // INSERT IGNORE + re-read: two concurrent first requests end up with the same secret.
        db_query("INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_group, is_public) VALUES ('invoice_link_secret', ?, 'system', 0)", [bin2hex(random_bytes(32))]);
        $value = $read();
        if (strlen($value) < 32) {
            db_query("UPDATE site_settings SET setting_value = ? WHERE setting_key = 'invoice_link_secret'", [bin2hex(random_bytes(32))]);
            $value = $read();
        }
    }
    return $secret = $value;
}

function invoice_signature(string $orderNumber): string
{
    return substr(hash_hmac('sha256', 'invoice:' . $orderNumber, invoice_secret()), 0, 32);
}

function invoice_sig_valid(string $orderNumber, string $sig): bool
{
    return $sig !== '' && hash_equals(invoice_signature($orderNumber), $sig);
}

/** Absolute invoice link that works without logging in (for emails). */
function invoice_signed_url(string $orderNumber, string $format = 'pdf'): string
{
    $q = ['action' => 'invoice', 'order' => $orderNumber] + ($format === 'html' ? ['format' => 'html'] : []) + ['sig' => invoice_signature($orderNumber)];
    return app_url('api/orders.php?' . http_build_query($q));
}

/** Link for the signed-in owner / staff (no signature). */
function invoice_url(string $orderNumber, string $format = 'pdf'): string
{
    return app_url('api/orders.php?' . http_build_query(['action' => 'invoice', 'order' => $orderNumber] + ($format === 'html' ? ['format' => 'html'] : [])));
}

// ------------------------------------------------------------------
// Issuing
// ------------------------------------------------------------------

/** The verified, captured payment behind an order (or null). */
function invoice_payment(int $orderId): ?array
{
    return db_one("SELECT provider_payment_id, method, verified_via FROM payment_transactions
                   WHERE order_id = ? AND verified = 1 AND status IN ('captured', 'refunded') ORDER BY id DESC LIMIT 1", [$orderId]);
}

/** Null when an invoice may be issued for the order, otherwise the reason. */
function invoice_ineligible_reason(array $order): ?string
{
    if (!in_array($order['status'], ['paid', 'partially_refunded', 'refunded'], true)) {
        return 'The invoice is available once the payment is confirmed.';
    }
    if ((float) $order['total'] <= 0) {
        return 'No invoice is issued for free enrolments - nothing was charged.';
    }
    if (!invoice_payment((int) $order['id'])) {
        return 'No verified payment was found for this order.';
    }
    return null;
}

function invoice_prefix(): string
{
    $p = strtoupper(trim((string) setting('invoice_prefix', 'LXA')));
    return preg_match('/^[A-Z0-9]{1,5}$/', $p) ? $p : 'LXA';
}

/** Invoice row (+ order_number, order_status, user_id) for an order, or null. */
function invoice_load(int $orderId): ?array
{
    return db_one('SELECT i.*, o.order_number, o.status AS order_status, o.user_id, o.created_at AS order_created_at
                   FROM invoices i JOIN orders o ON o.id = i.order_id WHERE i.order_id = ?', [$orderId]);
}

/**
 * Tax split for an invoice. The total is what was actually paid and is never changed: GST already
 * added at checkout (orders.tax) is used as-is; otherwise, when GST is on, it is back-calculated
 * from the inclusive total. Intra-state (buyer in the seller's state, or unknown): CGST + SGST.
 */
function invoice_tax_split(float $total, float $orderTax, array $cfg, ?string $buyerState): array
{
    $total = round($total, 2);
    $orderTax = round($orderTax, 2);
    $intra = $buyerState === null || $buyerState === $cfg['seller_state'];
    if ($orderTax > 0 && $orderTax < $total) {
        $cgst = $intra ? round($orderTax / 2, 2) : 0.0;
        return ['rate' => (float) $cfg['rate'], 'taxable_value' => round($total - $orderTax, 2), 'cgst' => $cgst,
            'sgst' => $intra ? round($orderTax - $cgst, 2) : 0.0, 'igst' => $intra ? 0.0 : $orderTax];
    }
    if ($cfg['enabled']) {
        return ['rate' => (float) $cfg['rate']] + gst_split($total, (float) $cfg['rate'], true, $intra);
    }
    return ['rate' => 0.0] + gst_split($total, 0, true, $intra);
}

/**
 * Issue the invoice for a paid order - exactly once. Returns the invoice row.
 * Throws RuntimeException when the order is not eligible (unpaid, free, no verified payment).
 */
function issue_invoice(int $orderId): array
{
    if ($existing = invoice_load($orderId)) {
        return $existing;
    }
    $order = db_one('SELECT o.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone, p.state AS profile_state
                     FROM orders o JOIN users u ON u.id = o.user_id LEFT JOIN student_profiles p ON p.user_id = o.user_id WHERE o.id = ?', [$orderId]);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    if ($why = invoice_ineligible_reason($order)) {
        throw new RuntimeException($why);
    }
    $pay = invoice_payment($orderId);

    $issue = function () use ($order, $pay, $orderId): void {
        // Serialise concurrent issuers for the same order, then re-check.
        db_one('SELECT id FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (db_value('SELECT id FROM invoices WHERE order_id = ?', [$orderId])) {
            return;
        }
        $fy = financial_year();
        db_query('INSERT IGNORE INTO invoice_sequences (financial_year, last_no) VALUES (?, 0)', [$fy]);
        $seq = (int) db_value('SELECT last_no FROM invoice_sequences WHERE financial_year = ? FOR UPDATE', [$fy]) + 1;
        db_query('UPDATE invoice_sequences SET last_no = ? WHERE financial_year = ?', [$seq, $fy]);
        $number = invoice_prefix() . '/' . substr($fy, 2) . '/' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

        $cfg = gst_config();
        $total = round((float) $order['total'], 2);
        $buyerState = canonical_state($order['billing_state']) ?? canonical_state($order['profile_state']);
        $split = invoice_tax_split($total, (float) $order['tax'], $cfg, $buyerState);
        $rate = $split['rate'];

        $seller = [
            'name' => setting('company_legal_name') ?: setting('site_name', 'Laxyam Academy'),
            'address' => setting('company_registered_address') ?: setting('address'),
        ];
        db_query(
            'INSERT INTO invoices (order_id, invoice_number, financial_year, sequence_no, issued_at, seller_name, seller_cin, seller_gstin, seller_pan,
                seller_address, seller_state, seller_state_code, billing_name, billing_email, billing_phone, billing_address, billing_state, billing_gstin,
                subtotal, discount, taxable_value, gst_rate, cgst, sgst, igst, total, currency, sac_code, payment_reference, payment_method, paid_at, status)
             VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'issued\')',
            [
                $orderId, $number, $fy, $seq,
                mb_substr((string) $seller['name'], 0, 160),
                strtoupper((string) setting('company_cin', '')) ?: null,
                strtoupper((string) setting('company_gstin', '')) ?: null,
                strtoupper((string) setting('company_pan', '')) ?: null,
                $seller['address'] ? mb_substr((string) $seller['address'], 0, 500) : null,
                $cfg['seller_state'], $cfg['seller_state_code'],
                mb_substr((string) ($order['billing_name'] ?: $order['user_name']), 0, 160),
                $order['billing_email'] ?: $order['user_email'],
                $order['billing_phone'] ?: $order['user_phone'],
                $order['billing_address'] ?: null,
                $buyerState,
                $order['billing_gstin'] ?: null,
                round((float) $order['subtotal'], 2), round((float) $order['discount'], 2), $split['taxable_value'],
                $rate, $split['cgst'], $split['sgst'], $split['igst'], $total, $order['currency'] ?: 'INR',
                preg_match('/^\d{4,8}$/', (string) setting('sac_code')) ? setting('sac_code') : '999293',
                $pay['provider_payment_id'] ?? null,
                mb_substr(payment_method_label($pay['method'] ?? null), 0, 40),
                $order['paid_at'],
            ]
        );
        audit_log('invoice_issued', 'order', $order['order_number'], ['invoice' => $number]);
    };
    db()->inTransaction() ? $issue() : db_transaction($issue);
    $inv = invoice_load($orderId);
    if (!$inv) {
        throw new RuntimeException('The invoice could not be issued.');
    }
    return $inv;
}

/**
 * ['invoice_number', 'url'] for an order, or null. Issues the invoice on demand for an
 * older paid order that has none yet (never for unpaid or free orders).
 */
function invoice_for_order(int $orderId, bool $issueIfMissing = true): ?array
{
    $inv = invoice_load($orderId);
    if (!$inv && $issueIfMissing) {
        $order = db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($order && invoice_ineligible_reason($order) === null) {
            try {
                $inv = issue_invoice($orderId);
            } catch (Throwable $e) {
                error_log('[laxyam] invoice issue failed for order ' . $orderId . ': ' . $e->getMessage());
            }
        }
    }
    return $inv ? ['invoice_number' => $inv['invoice_number'], 'url' => invoice_url($inv['order_number'])] : null;
}

/** "Invoice-LXA-26-27-0001.pdf" */
function invoice_filename(array $inv, string $ext = 'pdf'): string
{
    return 'Invoice-' . preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $inv['invoice_number']) . '.' . $ext;
}

/** Processed refunds for an order: ['amount' => float, 'last_at' => ?string]. */
function invoice_refunds(int $orderId): array
{
    $r = db_one("SELECT COALESCE(SUM(amount), 0) AS amount, MAX(updated_at) AS last_at FROM refunds WHERE order_id = ? AND status = 'processed'", [$orderId]);
    return ['amount' => round((float) ($r['amount'] ?? 0), 2), 'last_at' => $r['last_at'] ?? null];
}

/**
 * Invoice lines from the order items with the invoice's taxable value and tax allocated
 * proportionally; the last line absorbs rounding so the lines add up to the totals exactly.
 */
function invoice_items(array $inv): array
{
    $rows = db_all('SELECT oi.title, oi.unit_price, oi.discount, oi.total, c.validity_days, cat.name AS category
                    FROM order_items oi LEFT JOIN courses c ON c.id = oi.course_id LEFT JOIN course_categories cat ON cat.id = c.category_id
                    WHERE oi.order_id = ? ORDER BY oi.id', [$inv['order_id']]);
    $tax = round((float) $inv['cgst'] + (float) $inv['sgst'] + (float) $inv['igst'], 2);
    $taxable = round((float) $inv['taxable_value'], 2);
    $weights = array_map(fn($r) => max(0.0, (float) $r['unit_price'] - (float) $r['discount']), $rows);
    $sum = array_sum($weights);
    $n = count($rows);
    $items = [];
    $usedTaxable = 0.0;
    $usedTax = 0.0;
    foreach ($rows as $i => $r) {
        $share = $sum > 0 ? $weights[$i] / $sum : 1 / max(1, $n);
        if ($i === $n - 1) {
            $lineTaxable = round($taxable - $usedTaxable, 2);
            $lineTax = round($tax - $usedTax, 2);
        } else {
            $lineTaxable = round($taxable * $share, 2);
            $lineTax = round($tax * $share, 2);
            $usedTaxable += $lineTaxable;
            $usedTax += $lineTax;
        }
        $validity = $r['validity_days'] ? (int) $r['validity_days'] . ' days access' : 'Lifetime access';
        $items[] = [
            'title' => (string) $r['title'],
            'details' => implode(' · ', array_filter([$r['category'] ? $r['category'] . ' course' : null, $validity])),
            'sac' => (string) ($inv['sac_code'] ?? ''),
            'qty' => 1,
            'rate' => round((float) $r['unit_price'], 2),
            'discount' => round((float) $r['discount'], 2),
            'taxable' => $lineTaxable,
            'tax' => $lineTax,
            'amount' => round($lineTaxable + $lineTax, 2),
        ];
    }
    return $items;
}

/** Everything the renderers need, from settings that are not snapshotted (contact details). */
function invoice_view(array $inv): array
{
    $hasGst = (float) $inv['cgst'] + (float) $inv['sgst'] + (float) $inv['igst'] > 0;
    $intra = (float) $inv['igst'] <= 0;
    $rate = (float) $inv['gst_rate'];
    $taxLines = [];
    if ($hasGst) {
        if ($intra) {
            $taxLines[] = ['CGST @ ' . rtrim(rtrim(number_format($rate / 2, 2), '0'), '.') . '%', (float) $inv['cgst']];
            $taxLines[] = ['SGST @ ' . rtrim(rtrim(number_format($rate / 2, 2), '0'), '.') . '%', (float) $inv['sgst']];
        } else {
            $taxLines[] = ['IGST @ ' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%', (float) $inv['igst']];
        }
    }
    $refunds = invoice_refunds((int) $inv['order_id']);
    $placeState = $inv['billing_state'] ?: $inv['seller_state'];
    $placeCode = state_code($placeState) ?? $inv['seller_state_code'];
    $site = rtrim(preg_replace('#^https?://#', '', (string) config('APP_URL', '')), '/');
    $legal = (string) ($inv['seller_name'] ?: 'LAXYAM INNOVATIVE EDUSKIL PRIVATE LIMITED');
    $footer = implode(' · ', array_filter([
        $legal,
        $inv['seller_cin'] ? 'CIN ' . $inv['seller_cin'] : null,
        $inv['seller_gstin'] ? 'GSTIN ' . $inv['seller_gstin'] : null,
        $inv['seller_address'] ? 'Registered office: ' . preg_replace('/\s*\R\s*/', ', ', (string) $inv['seller_address']) : null,
    ]));
    $dateFormat = in_array(setting('date_format'), ['j M Y', 'd M Y', 'd/m/Y', 'd-m-Y', 'Y-m-d', 'M j, Y'], true) ? setting('date_format') : 'd M Y';
    return [
        'title' => $hasGst ? 'TAX INVOICE' : 'INVOICE',
        'has_gst' => $hasGst,
        'tax_lines' => $taxLines,
        'tax_total' => round((float) $inv['cgst'] + (float) $inv['sgst'] + (float) $inv['igst'], 2),
        'date' => date($dateFormat, strtotime((string) $inv['issued_at'])),
        'paid_on' => $inv['paid_at'] ? date($dateFormat . ', g:i A', strtotime((string) $inv['paid_at'])) : '—',
        'place_of_supply' => $placeState . ($placeCode ? ' (' . $placeCode . ')' : ''),
        'seller_email' => (string) setting('contact_email', ''),
        'seller_phone' => (string) setting('contact_phone', ''),
        'website' => $site,
        'legal_name' => $legal,
        'footer' => $footer,
        'note' => (string) setting('invoice_footer_note', 'This is a computer-generated invoice and does not require a physical signature.'),
        'signatory' => (string) setting('invoice_signatory', 'Authorised Signatory'),
        'stamp' => match ($inv['order_status'] ?? 'paid') { 'refunded' => 'REFUNDED', 'partially_refunded' => 'PART REFUNDED', default => 'PAID' },
        'refunded' => $refunds['amount'],
        'refunded_at' => $refunds['last_at'] ? date($dateFormat, strtotime((string) $refunds['last_at'])) : null,
        'words' => inr_words((float) $inv['total']),
    ];
}

/** Logo bytes for the PDF: the uploaded logo, else assets/logo.png. */
function invoice_logo_bytes(): ?string
{
    $candidates = [];
    $custom = (string) setting('logo_path', '');
    if ($custom !== '' && !preg_match('#^https?://#i', $custom)) {
        $candidates[] = APP_ROOT . '/' . ltrim($custom, '/');
    }
    $candidates[] = APP_ROOT . '/assets/logo.png';
    $root = realpath(APP_ROOT);
    foreach ($candidates as $file) {
        $real = realpath($file);
        if ($real && $root && str_starts_with($real, $root) && is_file($real) && filesize($real) < 5_000_000) {
            return (string) file_get_contents($real);
        }
    }
    return null;
}

// ------------------------------------------------------------------
// PDF
// ------------------------------------------------------------------

function render_invoice_pdf(array $inv, array $items): string
{
    require_once APP_ROOT . '/includes/pdf.php';
    $v = invoice_view($inv);
    $navy = [8, 38, 77];
    $primary = [11, 99, 246];
    $gold = [255, 201, 40];
    $ink = [20, 35, 59];
    $muted = [100, 116, 139];
    $line = [226, 234, 244];
    $surface = [245, 249, 255];
    $L = 40.0;
    $R = PdfDoc::W - 40;
    $CW = $R - $L;

    $pdf = new PdfDoc($v['title'] . ' ' . $inv['invoice_number'], $v['legal_name']);
    $pdf->addPage();

    $footer = function () use ($pdf, $v, $L, $R, $CW, $muted, $line): void {
        $pdf->setStroke(...$line)->setLineWidth(0.6)->line($L, 792, $R, 792);
        $pdf->setFont('Helvetica', 6.8)->setFill(...$muted);
        $pdf->paragraph($L, 803, $CW, $v['footer'], 9, 'center');
    };

    // ---- Header band
    $pdf->setFill(...$navy)->rect(0, 0, PdfDoc::W, 108, 'F');
    $pdf->setFill(...$gold)->rect(0, 108, PdfDoc::W, 3.5, 'F');
    $logo = ($bytes = invoice_logo_bytes()) ? $pdf->addImage($bytes) : null;
    if ($logo) {
        [$pw, $ph] = $pdf->imageSize($logo);
        $maxW = 150;
        $maxH = 46;
        $scale = min($maxW / max(1, $pw), $maxH / max(1, $ph));
        $w = $pw * $scale;
        $h = $ph * $scale;
        $pdf->setFill(255, 255, 255)->roundedRect($L, 26, $w + 22, $h + 18, 10, 'F');
        $pdf->image($logo, $L + 11, 26 + 9, $w, $h);
    } else {
        $pdf->setFont('Helvetica-Bold', 20)->setFill(255, 255, 255)->text($L, 62, (string) setting('site_name', 'Laxyam Academy'));
    }
    $pdf->setFont('Helvetica-Bold', 22)->setFill(255, 255, 255)->text($R, 50, $v['title'], 'right');
    $pdf->setFont('Helvetica-Bold', 10.5)->setFill(...$gold)->text($R, 69, 'No. ' . $inv['invoice_number'], 'right');
    $pdf->setFont('Helvetica', 8.5)->setFill(220, 230, 245)->text($R, 85, 'Date: ' . $v['date'] . '   ·   Order: ' . $inv['order_number'], 'right');

    // ---- Seller (left)
    $y = 134;
    $pdf->setFont('Helvetica-Bold', 7)->setFill(...$muted)->text($L, $y, 'SOLD BY');
    $y += 14;
    $pdf->setFont('Helvetica-Bold', 10)->setFill(...$navy);
    foreach ($pdf->wrap($v['legal_name'], 255) as $ln) {
        $pdf->text($L, $y, $ln);
        $y += 13;
    }
    $pdf->setFont('Helvetica', 8)->setFill(...$ink);
    $sellerLines = array_filter([
        $inv['seller_cin'] ? 'CIN: ' . $inv['seller_cin'] : null,
        $inv['seller_gstin'] ? 'GSTIN: ' . $inv['seller_gstin'] : null,
        $inv['seller_pan'] ? 'PAN: ' . $inv['seller_pan'] : null,
    ]);
    foreach ($sellerLines as $ln) {
        $pdf->text($L, $y, $ln);
        $y += 11.5;
    }
    if ($inv['seller_address']) {
        $pdf->setFill(...$muted);
        $y = $pdf->paragraph($L, $y, 255, (string) $inv['seller_address'] . ($inv['seller_state'] ? ', ' . $inv['seller_state'] . ' (' . $inv['seller_state_code'] . ')' : ''), 11.5);
    }
    $pdf->setFill(...$muted);
    foreach (array_filter([trim($v['seller_email'] . ($v['seller_phone'] ? '  ·  ' . $v['seller_phone'] : '')), $v['website']]) as $ln) {
        $pdf->text($L, $y, $ln);
        $y += 11.5;
    }
    $leftEnd = $y;

    // ---- Invoice meta (right card)
    $meta = [
        ['Invoice No.', $inv['invoice_number']],
        ['Invoice Date', $v['date']],
        ['Order No.', $inv['order_number']],
        ['Payment Ref.', $inv['payment_reference'] ?: '—'],
        ['Payment Method', $inv['payment_method'] ?: '—'],
        ['Paid On', $v['paid_on']],
        ['Place of Supply', $v['place_of_supply']],
    ];
    $mx = 318.0;
    $mw = $R - $mx;
    $mh = 16 + count($meta) * 15.5;
    $pdf->setFill(...$surface)->setStroke(...$line)->setLineWidth(0.8)->roundedRect($mx, 124, $mw, $mh, 9, 'B');
    $my = 124 + 19;
    foreach ($meta as [$label, $value]) {
        $pdf->setFont('Helvetica', 7.8)->setFill(...$muted)->text($mx + 12, $my, $label);
        $maxVal = $mw - 24 - $pdf->width($label) - 12;
        $pdf->setFont('Helvetica-Bold', 8.2)->setFill(...$navy);
        $val = (string) $value;
        if ($pdf->width($val) > $maxVal) {
            while (mb_strlen($val) > 4 && $pdf->width($val . '…') > $maxVal) {
                $val = mb_substr($val, 0, -1);
            }
            $val .= '…';
        }
        $pdf->text($R - 12, $my, $val, 'right');
        $my += 15.5;
    }
    $y = max($leftEnd, 124 + $mh) + 16;

    // ---- Bill to
    $billLeft = array_values(array_filter([
        $inv['billing_email'],
        $inv['billing_phone'] ? 'Phone: ' . $inv['billing_phone'] : null,
        $inv['billing_gstin'] ? 'GSTIN: ' . $inv['billing_gstin'] : null,
    ]));
    $addr = trim((string) $inv['billing_address']);
    $stateLine = $inv['billing_state'] ? 'State: ' . $inv['billing_state'] . (state_code($inv['billing_state']) ? ' (' . state_code($inv['billing_state']) . ')' : '') : null;
    $pdf->setFont('Helvetica', 8);
    $addrLines = $addr !== '' ? $pdf->wrap($addr, 215) : [];
    $rightLines = array_merge($addrLines, $stateLine ? [$stateLine] : []);
    $bh = 30 + max(count($billLeft) + 1, count($rightLines), 1) * 11.5 + 4;
    $pdf->setFill(255, 255, 255)->setStroke(...$line)->roundedRect($L, $y, $CW, $bh, 9, 'B');
    $pdf->setFill(...$primary)->roundedRect($L, $y, 4, $bh, 2, 'F');
    $pdf->setFont('Helvetica-Bold', 7)->setFill(...$muted)->text($L + 16, $y + 15, 'BILL TO');
    $by = $y + 29;
    $pdf->setFont('Helvetica-Bold', 9.5)->setFill(...$navy)->text($L + 16, $by, (string) $inv['billing_name']);
    $pdf->setFont('Helvetica', 8)->setFill(...$ink);
    $ly = $by + 12;
    foreach ($billLeft as $ln) {
        $pdf->text($L + 16, $ly, $ln);
        $ly += 11.5;
    }
    $ry = $by;
    $pdf->setFill(...$muted);
    foreach ($rightLines as $ln) {
        $pdf->text($L + 285, $ry, $ln);
        $ry += 11.5;
    }
    $y += $bh + 18;

    // ---- Items table
    $cols = $v['has_gst']
        ? [['#', 20, 'center'], ['Description', 165, 'left'], ['SAC', 40, 'center'], ['Qty', 25, 'center'], ['Rate', 55, 'right'],
           ['Discount', 50, 'right'], ['Taxable', 55, 'right'], ['GST', 50, 'right'], ['Amount', $CW - 460, 'right']]
        : [['#', 20, 'center'], ['Description', 235, 'left'], ['SAC', 45, 'center'], ['Qty', 30, 'center'], ['Rate', 65, 'right'],
           ['Discount', 60, 'right'], ['Amount', $CW - 455, 'right']];
    $tableHeader = function (float $y) use ($pdf, $cols, $L, $CW, $navy): float {
        $pdf->setFill(...$navy)->roundedRect($L, $y, $CW, 22, 6, 'F');
        $pdf->setFont('Helvetica-Bold', 7.5)->setFill(255, 255, 255);
        $x = $L;
        foreach ($cols as [$label, $w, $align]) {
            $tx = match ($align) { 'right' => $x + $w - 6, 'center' => $x + $w / 2, default => $x + 6 };
            $pdf->text($tx, $y + 14, $label, $align);
            $x += $w;
        }
        return $y + 22;
    };
    $pdf->setFont('Helvetica', 7)->setFill(...$muted)->text($R, $y - 5, 'Amounts in INR', 'right');
    $y = $tableHeader($y);
    foreach ($items as $i => $it) {
        $pdf->setFont('Helvetica-Bold', 8.3);
        $titleLines = $pdf->wrap($it['title'], $cols[1][1] - 12);
        $pdf->setFont('Helvetica', 7.2);
        $detailLines = $it['details'] !== '' ? $pdf->wrap($it['details'], $cols[1][1] - 12) : [];
        $rowH = max(30, 12 + count($titleLines) * 10.5 + count($detailLines) * 9.5 + ($v['has_gst'] ? 2 : 0));
        if ($y + $rowH > 690) {
            $footer();
            $pdf->addPage();
            $y = $tableHeader(50);
        }
        if ($i % 2 === 1) {
            $pdf->setFill(...$surface)->rect($L, $y, $CW, $rowH, 'F');
        }
        $pdf->setStroke(...$line)->setLineWidth(0.6)->line($L, $y + $rowH, $R, $y + $rowH);
        $cells = $v['has_gst']
            ? [(string) ($i + 1), null, $it['sac'], (string) $it['qty'], inr_format($it['rate']), inr_format($it['discount']), inr_format($it['taxable']), inr_format($it['tax']), inr_format($it['amount'])]
            : [(string) ($i + 1), null, $it['sac'], (string) $it['qty'], inr_format($it['rate']), inr_format($it['discount']), inr_format($it['amount'])];
        $x = $L;
        foreach ($cols as $c => [$label, $w, $align]) {
            if ($c === 1) {
                $ty = $y + 14;
                $pdf->setFont('Helvetica-Bold', 8.3)->setFill(...$navy);
                foreach ($titleLines as $ln) {
                    $pdf->text($x + 6, $ty, $ln);
                    $ty += 10.5;
                }
                $pdf->setFont('Helvetica', 7.2)->setFill(...$muted);
                foreach ($detailLines as $ln) {
                    $pdf->text($x + 6, $ty - 1, $ln);
                    $ty += 9.5;
                }
            } else {
                $tx = match ($align) { 'right' => $x + $w - 6, 'center' => $x + $w / 2, default => $x + 6 };
                $pdf->setFont($c === count($cols) - 1 ? 'Helvetica-Bold' : 'Helvetica', 8)->setFill(...$ink)->text($tx, $y + 14, (string) $cells[$c], $align);
                if ($v['has_gst'] && $label === 'GST') {
                    $pdf->setFont('Helvetica', 6.5)->setFill(...$muted)->text($tx, $y + 23, '@ ' . rtrim(rtrim(number_format((float) $inv['gst_rate'], 2), '0'), '.') . '%', 'right');
                }
            }
            $x += $w;
        }
        $y += $rowH;
    }

    // ---- Totals (right) + amount in words (left)
    $totals = [['Subtotal', inr_format($inv['subtotal'])]];
    if ((float) $inv['discount'] > 0) {
        $totals[] = ['Discount', '- ' . inr_format($inv['discount'])];
    }
    if ($v['has_gst']) {
        $totals[] = ['Taxable value', inr_format($inv['taxable_value'])];
        foreach ($v['tax_lines'] as [$label, $amount]) {
            $totals[] = [$label, inr_format($amount)];
        }
    }
    $needed = 24 + count($totals) * 15 + 30 + ($v['refunded'] > 0 ? 15 : 0) + 150;
    if ($y + $needed > 780) {
        $footer();
        $pdf->addPage();
        $y = 50;
    }
    $y += 16;
    $tx = $R - 220;
    $ty = $y;
    foreach ($totals as [$label, $value]) {
        $pdf->setFont('Helvetica', 8.3)->setFill(...$muted)->text($tx + 10, $ty + 10, $label);
        $pdf->setFont('Helvetica', 8.5)->setFill(...$ink)->text($R - 10, $ty + 10, $value, 'right');
        $ty += 15;
    }
    $pdf->setFill(...$navy)->roundedRect($tx, $ty + 2, 220, 26, 7, 'F');
    $pdf->setFont('Helvetica-Bold', 9)->setFill(255, 255, 255)->text($tx + 10, $ty + 19, 'Grand Total');
    $pdf->setFont('Helvetica-Bold', 11)->setFill(...$gold)->text($R - 10, $ty + 19.5, 'INR ' . inr_format($inv['total']), 'right');
    $ty += 34;
    $pdf->setFont('Helvetica', 8.3)->setFill(...$muted)->text($tx + 10, $ty + 8, 'Amount paid');
    $pdf->setFont('Helvetica-Bold', 8.5)->setFill(22, 128, 61)->text($R - 10, $ty + 8, 'INR ' . inr_format($inv['total']), 'right');
    $ty += 15;
    if ($v['refunded'] > 0) {
        $pdf->setFont('Helvetica', 8.3)->setFill(...$muted)->text($tx + 10, $ty + 8, 'Refunded' . ($v['refunded_at'] ? ' (' . $v['refunded_at'] . ')' : ''));
        $pdf->setFont('Helvetica-Bold', 8.5)->setFill(220, 38, 38)->text($R - 10, $ty + 8, '- INR ' . inr_format($v['refunded']), 'right');
        $ty += 15;
    }

    // Amount in words + stamp on the left
    $wx = $L;
    $ww = $tx - $L - 24;
    $pdf->setFont('Helvetica-Bold', 7)->setFill(...$muted)->text($wx, $y + 10, 'AMOUNT IN WORDS');
    $pdf->setFont('Helvetica-Bold', 8.6)->setFill(...$navy);
    $wy = $pdf->paragraph($wx, $y + 24, $ww, $v['words'], 11.5);
    $stampColor = $v['stamp'] === 'PAID' ? [22, 163, 74] : ($v['stamp'] === 'REFUNDED' ? [240, 78, 35] : [217, 119, 6]);
    $pdf->setFont('Helvetica-Bold', $v['stamp'] === 'PAID' ? 20 : 13);
    $sw = $pdf->width($v['stamp']) + 26;
    $scx = $wx + 10 + $sw / 2;
    $scy = $wy + 26;
    $pdf->rotated($scx, $scy, 8, function (PdfDoc $p) use ($scx, $scy, $sw, $stampColor, $v): void {
        $p->setStroke(...$stampColor)->setLineWidth(2)->roundedRect($scx - $sw / 2, $scy - 17, $sw, 34, 7, 'S');
        $p->setLineWidth(0.7)->roundedRect($scx - $sw / 2 + 3, $scy - 14, $sw - 6, 28, 5, 'S');
        $p->setFill(...$stampColor)->text($scx, $scy + ($v['stamp'] === 'PAID' ? 7 : 4.5), $v['stamp'], 'center');
    });
    $y = max($ty, $scy + 30) + 14;

    // ---- Notes
    $notes = [];
    if (!$v['has_gst']) {
        $notes[] = 'GST not applicable: no GST has been charged on this invoice.';
    }
    if ($v['refunded'] > 0) {
        $notes[] = 'A refund of INR ' . inr_format($v['refunded']) . ' has been processed against this invoice' . ($v['refunded_at'] ? ' on ' . $v['refunded_at'] : '') . '.';
    }
    if ($v['note'] !== '') {
        $notes[] = $v['note'];
    }
    $pdf->setStroke(...$line)->setLineWidth(0.6)->line($L, $y, $R, $y);
    $y += 16;
    $pdf->setFont('Helvetica-Bold', 7)->setFill(...$muted)->text($L, $y, 'NOTES');
    $pdf->setFont('Helvetica', 7.8)->setFill(...$ink);
    $ny = $y + 13;
    foreach ($notes as $note) {
        $ny = $pdf->paragraph($L, $ny, 290, '• ' . $note, 10.5);
    }

    // ---- Signatory (right)
    $pdf->setFont('Helvetica-Bold', 8)->setFill(...$navy);
    $sy = $y;
    foreach ($pdf->wrap('For ' . $v['legal_name'], 200) as $ln) {
        $pdf->text($R, $sy, $ln, 'right');
        $sy += 10.5;
    }
    $sy += 30;
    $pdf->setStroke(...$muted)->setLineWidth(0.6)->line($R - 150, $sy, $R, $sy);
    $pdf->setFont('Helvetica', 7.8)->setFill(...$muted)->text($R, $sy + 11, $v['signatory'], 'right');

    $footer();
    return $pdf->output();
}

// ------------------------------------------------------------------
// HTML (A4 print stylesheet; same content as the PDF)
// ------------------------------------------------------------------

/** $opts: nonce (CSP nonce for the print script), pdf_url (download link), toolbar (bool) */
function render_invoice_html(array $inv, array $items, array $opts = []): string
{
    $v = invoice_view($inv);
    $nonce = (string) ($opts['nonce'] ?? '');
    $logo = public_file_url(setting('logo_path') ?: 'assets/logo.png');
    $rows = '';
    foreach ($items as $i => $it) {
        $rows .= '<tr><td class="c">' . ($i + 1) . '</td><td><strong>' . e($it['title']) . '</strong>' . ($it['details'] !== '' ? '<small>' . e($it['details']) . '</small>' : '') . '</td>'
            . '<td class="c">' . e($it['sac']) . '</td><td class="c">' . (int) $it['qty'] . '</td><td class="r">' . inr_format($it['rate']) . '</td><td class="r">' . inr_format($it['discount']) . '</td>'
            . ($v['has_gst'] ? '<td class="r">' . inr_format($it['taxable']) . '</td><td class="r">' . inr_format($it['tax']) . '<small>@ ' . e(rtrim(rtrim(number_format((float) $inv['gst_rate'], 2), '0'), '.')) . '%</small></td>' : '')
            . '<td class="r"><strong>' . inr_format($it['amount']) . '</strong></td></tr>';
    }
    $totals = '<tr><th>Subtotal</th><td>' . inr($inv['subtotal']) . '</td></tr>';
    if ((float) $inv['discount'] > 0) {
        $totals .= '<tr><th>Discount</th><td>- ' . inr($inv['discount']) . '</td></tr>';
    }
    if ($v['has_gst']) {
        $totals .= '<tr><th>Taxable value</th><td>' . inr($inv['taxable_value']) . '</td></tr>';
        foreach ($v['tax_lines'] as [$label, $amount]) {
            $totals .= '<tr><th>' . e($label) . '</th><td>' . inr($amount) . '</td></tr>';
        }
    }
    $totals .= '<tr class="grand"><th>Grand Total</th><td>' . inr($inv['total']) . '</td></tr>'
        . '<tr class="paid"><th>Amount paid</th><td>' . inr($inv['total']) . '</td></tr>'
        . ($v['refunded'] > 0 ? '<tr class="refund"><th>Refunded' . ($v['refunded_at'] ? ' (' . e($v['refunded_at']) . ')' : '') . '</th><td>- ' . inr($v['refunded']) . '</td></tr>' : '');
    $notes = [];
    if (!$v['has_gst']) {
        $notes[] = 'GST not applicable: no GST has been charged on this invoice.';
    }
    if ($v['refunded'] > 0) {
        $notes[] = 'A refund of ' . inr($v['refunded']) . ' has been processed against this invoice' . ($v['refunded_at'] ? ' on ' . $v['refunded_at'] : '') . '.';
    }
    if ($v['note'] !== '') {
        $notes[] = $v['note'];
    }
    $meta = [
        ['Invoice No.', $inv['invoice_number']], ['Invoice Date', $v['date']], ['Order No.', $inv['order_number']],
        ['Payment Ref.', $inv['payment_reference'] ?: '—'], ['Payment Method', $inv['payment_method'] ?: '—'],
        ['Paid On', $v['paid_on']], ['Place of Supply', $v['place_of_supply']],
    ];
    $metaHtml = '';
    foreach ($meta as [$k, $val]) {
        $metaHtml .= '<div><dt>' . e($k) . '</dt><dd>' . e((string) $val) . '</dd></div>';
    }
    $stampClass = $v['stamp'] === 'PAID' ? 'ok' : ($v['stamp'] === 'REFUNDED' ? 'bad' : 'warn');
    $billState = $inv['billing_state'] ? $inv['billing_state'] . (state_code($inv['billing_state']) ? ' (' . state_code($inv['billing_state']) . ')' : '') : '';
    $toolbar = !empty($opts['toolbar'])
        ? '<div class="bar"><span>' . e($v['title']) . ' ' . e($inv['invoice_number']) . '</span><span class="sp"></span>'
          . (!empty($opts['pdf_url']) ? '<a class="btn" href="' . e($opts['pdf_url']) . '">Download PDF</a>' : '')
          . '<button type="button" class="btn pri" id="print-invoice">Print / Save as PDF</button></div>'
          . '<script nonce="' . e($nonce) . '">document.getElementById("print-invoice").addEventListener("click",function(){window.print()});</script>'
        : '';

    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . e($v['title'] . ' ' . $inv['invoice_number']) . '</title><style>'
        . '@page{size:A4;margin:12mm}*{box-sizing:border-box}body{margin:0;background:#EEF3FA;font:13px/1.5 "Segoe UI",Inter,Arial,sans-serif;color:#14233B}'
        . '.bar{position:sticky;top:0;z-index:2;display:flex;gap:10px;align-items:center;padding:10px 16px;background:#08264D;color:#fff;font-weight:600}.bar .sp{flex:1}'
        . '.btn{display:inline-block;padding:8px 14px;border-radius:10px;border:1px solid #fff3;background:#ffffff14;color:#fff;font:600 13px/1.2 inherit;text-decoration:none;cursor:pointer}.btn.pri{background:#FFC928;color:#08264D;border-color:#FFC928}.btn:focus-visible{outline:3px solid #FFC928;outline-offset:2px}'
        . '.sheet{width:210mm;max-width:100%;min-height:297mm;margin:20px auto;background:#fff;box-shadow:0 10px 40px #08264d22;border-radius:14px;overflow:hidden;display:flex;flex-direction:column}'
        . '.head{background:#08264D;color:#fff;padding:24px 28px;display:flex;justify-content:space-between;align-items:center;gap:16px;border-bottom:4px solid #FFC928;flex-wrap:wrap}'
        . '.logo{background:#fff;border-radius:12px;padding:9px 12px;display:inline-flex}.logo img{height:44px;width:auto;display:block}'
        . '.ttl{text-align:right}.ttl h1{margin:0;font-size:26px;letter-spacing:.04em}.ttl .no{color:#FFC928;font-weight:700}.ttl .sub{color:#DCE6F5;font-size:12px}'
        . '.body{padding:22px 28px;flex:1}.cols{display:grid;grid-template-columns:1fr 1fr;gap:20px}.lab{font-size:10px;font-weight:700;letter-spacing:.08em;color:#64748B;text-transform:uppercase;margin:0 0 6px}'
        . '.seller strong{display:block;color:#08264D;font-size:14px}.seller p{margin:2px 0;color:#475569;font-size:12px}'
        . 'dl.meta{margin:0;background:#F5F9FF;border:1px solid #E2EAF4;border-radius:12px;padding:10px 14px}dl.meta div{display:flex;justify-content:space-between;gap:10px;padding:3px 0}dl.meta dt{color:#64748B;font-size:12px}dl.meta dd{margin:0;font-weight:700;color:#08264D;font-size:12px;text-align:right;word-break:break-all}'
        . '.bill{margin:18px 0;border:1px solid #E2EAF4;border-left:4px solid #0B63F6;border-radius:12px;padding:12px 16px;display:grid;grid-template-columns:1fr 1fr;gap:12px}.bill strong{color:#08264D}.bill p{margin:2px 0;font-size:12px;color:#475569}'
        . 'table.items{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}table.items th{background:#08264D;color:#fff;font-size:11px;padding:8px 6px;text-align:left}table.items th:first-child{border-radius:8px 0 0 8px}table.items th:last-child{border-radius:0 8px 8px 0}'
        . 'table.items td{padding:9px 6px;border-bottom:1px solid #E2EAF4;vertical-align:top}table.items tr:nth-child(even) td{background:#F5F9FF}table.items small{display:block;color:#64748B;font-size:11px}.c{text-align:center}.r{text-align:right;white-space:nowrap}table.items th.r{text-align:right}table.items th.c{text-align:center}'
        . '.sum{display:flex;justify-content:space-between;gap:24px;margin-top:18px;flex-wrap:wrap}.words{flex:1;min-width:220px}.words p{margin:0;font-weight:700;color:#08264D}'
        . '.stamp{display:inline-block;margin-top:16px;padding:6px 18px;border:3px double currentColor;border-radius:10px;font-weight:800;font-size:22px;letter-spacing:.12em;transform:rotate(-8deg)}.stamp.ok{color:#16A34A}.stamp.bad{color:#F04E23}.stamp.warn{color:#D97706}'
        . 'table.tot{width:280px;border-collapse:collapse;font-size:12.5px}table.tot th{text-align:left;font-weight:500;color:#64748B;padding:4px 10px}table.tot td{text-align:right;padding:4px 10px;white-space:nowrap}'
        . 'table.tot tr.grand th,table.tot tr.grand td{background:#08264D;color:#fff;font-weight:700;padding:9px 10px}table.tot tr.grand td{color:#FFC928;font-size:15px}table.tot tr.grand th{border-radius:8px 0 0 8px}table.tot tr.grand td{border-radius:0 8px 8px 0}table.tot tr.paid td{color:#15803D;font-weight:700}table.tot tr.refund td{color:#DC2626;font-weight:700}'
        . '.foot{display:flex;justify-content:space-between;gap:24px;margin-top:22px;padding-top:14px;border-top:1px solid #E2EAF4;flex-wrap:wrap}.notes ul{margin:0;padding-left:18px;font-size:12px;color:#334155}.sign{text-align:right;font-size:12px;min-width:200px}.sign strong{color:#08264D;display:block}.sign .ln{margin:34px 0 4px auto;width:160px;border-top:1px solid #94A3B8}'
        . '.legal{padding:12px 28px;font-size:10.5px;color:#64748B;text-align:center;border-top:1px solid #E2EAF4}'
        // Phones: the totals table drops under "Amount in words", so it spans the card (amounts line up with the items' right edge).
        . '@media (max-width:640px){.cols,.bill{grid-template-columns:1fr}.sheet{border-radius:0;margin:0}.body{padding:16px}.head{padding:18px}table.items{font-size:11px}.ttl{text-align:left}.sum{flex-direction:column;gap:16px}.words{min-width:0}table.tot{width:100%}}'
        . '@media print{body{background:#fff}.bar{display:none}.sheet{margin:0;box-shadow:none;border-radius:0;width:auto;min-height:0}.head,table.items th,table.tot tr.grand th,table.tot tr.grand td{-webkit-print-color-adjust:exact;print-color-adjust:exact}}'
        . '@media (prefers-reduced-motion:reduce){*{transition:none!important}}'
        . '</style></head><body>' . $toolbar
        . '<main class="sheet"><header class="head"><span class="logo"><img src="' . e((string) $logo) . '" alt="' . e((string) setting('site_name', 'Laxyam Academy')) . '"></span>'
        . '<div class="ttl"><h1>' . e($v['title']) . '</h1><div class="no">No. ' . e($inv['invoice_number']) . '</div><div class="sub">Date: ' . e($v['date']) . ' · Order: ' . e($inv['order_number']) . '</div></div></header>'
        . '<div class="body"><div class="cols"><div class="seller"><p class="lab">Sold by</p><strong>' . e($v['legal_name']) . '</strong>'
        . ($inv['seller_cin'] ? '<p>CIN: ' . e($inv['seller_cin']) . '</p>' : '') . ($inv['seller_gstin'] ? '<p>GSTIN: ' . e($inv['seller_gstin']) . '</p>' : '') . ($inv['seller_pan'] ? '<p>PAN: ' . e($inv['seller_pan']) . '</p>' : '')
        . ($inv['seller_address'] ? '<p>' . nl2br(e((string) $inv['seller_address'])) . ($inv['seller_state'] ? ', ' . e($inv['seller_state']) . ' (' . e((string) $inv['seller_state_code']) . ')' : '') . '</p>' : '')
        . '<p>' . e(trim($v['seller_email'] . ($v['seller_phone'] ? ' · ' . $v['seller_phone'] : ''))) . '</p>' . ($v['website'] ? '<p>' . e($v['website']) . '</p>' : '') . '</div>'
        . '<dl class="meta">' . $metaHtml . '</dl></div>'
        . '<section class="bill" aria-label="Bill to"><div><p class="lab">Bill to</p><strong>' . e((string) $inv['billing_name']) . '</strong>'
        . ($inv['billing_email'] ? '<p>' . e((string) $inv['billing_email']) . '</p>' : '') . ($inv['billing_phone'] ? '<p>Phone: ' . e((string) $inv['billing_phone']) . '</p>' : '') . ($inv['billing_gstin'] ? '<p>GSTIN: ' . e((string) $inv['billing_gstin']) . '</p>' : '') . '</div>'
        . '<div>' . ($inv['billing_address'] ? '<p>' . nl2br(e((string) $inv['billing_address'])) . '</p>' : '') . ($billState ? '<p>State: ' . e($billState) . '</p>' : '') . '</div></section>'
        . '<div style="text-align:right;font-size:11px;color:#64748B;margin-bottom:4px">Amounts in INR</div>'
        . '<table class="items"><thead><tr><th class="c">#</th><th>Description</th><th class="c">SAC</th><th class="c">Qty</th><th class="r">Rate</th><th class="r">Discount</th>'
        . ($v['has_gst'] ? '<th class="r">Taxable</th><th class="r">GST</th>' : '') . '<th class="r">Amount</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        . '<div class="sum"><div class="words"><p class="lab">Amount in words</p><p>' . e($v['words']) . '</p><span class="stamp ' . $stampClass . '">' . e($v['stamp']) . '</span></div><table class="tot">' . $totals . '</table></div>'
        . '<div class="foot"><div class="notes"><p class="lab">Notes</p><ul>' . implode('', array_map(fn($n) => '<li>' . e($n) . '</li>', $notes)) . '</ul></div>'
        . '<div class="sign"><strong>For ' . e($v['legal_name']) . '</strong><div class="ln"></div>' . e($v['signatory']) . '</div></div></div>'
        . '<footer class="legal">' . e($v['footer']) . '</footer></main></body></html>';
}

// ------------------------------------------------------------------
// Email
// ------------------------------------------------------------------

/** Email the invoice link to the buyer. Returns true when the mail server accepted it (false = only logged, or failed). */
function invoice_send_email(array $inv): bool
{
    require_once APP_ROOT . '/includes/mailer.php';
    $to = (string) ($inv['billing_email'] ?: db_value('SELECT email FROM users WHERE id = ?', [$inv['user_id']]));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $site = (string) setting('site_name', 'Laxyam Academy');
    $subject = 'Your invoice ' . $inv['invoice_number'] . ' - ' . $site;
    $body = '<p>Hello ' . e((string) $inv['billing_name']) . ',</p>'
        . '<p>Thank you for your purchase. Your invoice <strong>' . e((string) $inv['invoice_number']) . '</strong> for order <strong>' . e((string) $inv['order_number']) . '</strong>'
        . ' (amount paid ' . e(inr($inv['total'])) . ') is ready to download.</p>'
        . '<p style="font-size:13px;color:#64748B">Issued by ' . e((string) $inv['seller_name']) . ($inv['seller_cin'] ? ' · CIN ' . e((string) $inv['seller_cin']) : '') . '</p>';
    $html = mail_template($subject, $body, 'Download invoice (PDF)', invoice_signed_url((string) $inv['order_number']));
    // Sent now (staff are waiting for the result); the meta is used by the email delivery log.
    return send_mail($to, $subject, $html, ['template_key' => 'invoice', 'user_id' => (int) $inv['user_id'], 'to_name' => (string) $inv['billing_name'], 'related' => ['order', (int) $inv['order_id']]]);
}
