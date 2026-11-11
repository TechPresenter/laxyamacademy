<?php
// Orders: list, detail, invoices and refund records.
// There is deliberately NO control to mark an order as paid - only verified gateway events do that.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/payments.php';

$me = require_staff_page();
require_permission('orders.view');
$number = (string) ($_GET['order'] ?? '');

// Old sidebar alias: "Receipts & Invoices" now has its own screen.
if (($_GET['view'] ?? '') === 'receipts') {
    redirect_to('invoices.php' . (isset($_GET['q']) ? '?q=' . urlencode((string) $_GET['q']) : ''));
}

if (($_GET['export'] ?? '') === 'csv') {
    require_permission('reports.export');
    csv_download('orders-' . date('Ymd') . '.csv',
        ['Order', 'Student', 'Email', 'Course(s)', 'Subtotal', 'Discount', 'GST', 'Total', 'Status', 'Billing name', 'Billing state', 'Billing GSTIN', 'Invoice', 'Taxable value', 'CGST', 'SGST', 'IGST', 'Provider order', 'Created', 'Paid'],
        db_all("SELECT o.order_number, u.name, u.email, (SELECT GROUP_CONCAT(title SEPARATOR '; ') FROM order_items WHERE order_id = o.id), o.subtotal, o.discount, o.tax, o.total, o.status,
                       o.billing_name, o.billing_state, o.billing_gstin, i.invoice_number, i.taxable_value, i.cgst, i.sgst, i.igst, o.provider_order_id, o.created_at, o.paid_at
                FROM orders o JOIN users u ON u.id = o.user_id LEFT JOIN invoices i ON i.order_id = o.id ORDER BY o.id DESC"));
}

$order = $number !== '' ? db_one('SELECT o.*, u.name, u.email, u.phone, u.id AS uid, c.code AS coupon_code FROM orders o JOIN users u ON u.id = o.user_id LEFT JOIN coupons c ON c.id = o.coupon_id WHERE o.order_number = ?', [$number]) : null;
$back = fn() => redirect_to('orders.php?order=' . urlencode((string) $order['order_number']));
// Gateway the order was paid with (Cashfree / Razorpay) - used in the refund wording.
$gwName = $order && in_array($order['provider'], ['razorpay', 'cashfree'], true) ? payment_provider_label($order['provider']) : 'payment gateway';

/** Refund sums: processed (money returned) and open (requested / processing). */
function refund_sums(int $orderId, int $excludeId = 0): array
{
    $r = db_one("SELECT COALESCE(SUM(CASE WHEN status = 'processed' THEN amount END), 0) AS processed,
                        COALESCE(SUM(CASE WHEN status IN ('requested', 'processing') THEN amount END), 0) AS open
                 FROM refunds WHERE order_id = ? AND id <> ?", [$orderId, $excludeId]);
    return [round((float) $r['processed'], 2), round((float) $r['open'], 2)];
}

/**
 * Apply a refund that is now 'processed' (inside a transaction): order status, optional access
 * revoke and the coupon use returned on a full refund. Returns true when the order is fully refunded.
 */
function apply_processed_refund(array $order, bool $revoke): bool
{
    $locked = db_one('SELECT id, status, total, coupon_id FROM orders WHERE id = ? FOR UPDATE', [$order['id']]);
    [$processed] = refund_sums((int) $order['id']);
    $full = $processed >= (float) $locked['total'] - 0.009;
    db_query('UPDATE orders SET status = ? WHERE id = ?', [$full ? 'refunded' : 'partially_refunded', $order['id']]);
    // Keep the payment record in step with the order: a fully refunded order's captured payment
    // becomes 'refunded' (Transactions screen + its Refunded filter). payment_transactions.status has
    // no partial value, so after a partial refund the payment stays 'captured' (money is still held).
    if ($full) {
        db_query("UPDATE payment_transactions SET status = 'refunded' WHERE order_id = ? AND verified = 1 AND status = 'captured'", [$order['id']]);
    }
    if ($full && $locked['status'] !== 'refunded') {
        if ($revoke) {
            db_query("UPDATE course_enrolments SET status = 'revoked' WHERE order_id = ? AND status = 'active'", [$order['id']]);
        }
        if ($locked['coupon_id'] && db_query('DELETE FROM coupon_redemptions WHERE order_id = ?', [$order['id']])->rowCount() > 0) {
            db_query('UPDATE coupons SET used_count = GREATEST(used_count, 1) - 1 WHERE id = ?', [$locked['coupon_id']]);
        }
        if ($locked['coupon_id']) {
            require_once APP_ROOT . '/includes/referrals.php';
            referral_on_refund((int) $order['id']); // the referrer's reward goes while it is unused
        }
    }
    return $full;
}

if ($order && admin_post()) {
    $do = (string) ($_POST['_action'] ?? '');
    if ($do === 'issue_invoice') {
        try {
            $inv = issue_invoice((int) $order['id']);
            flash('success', 'Invoice ' . $inv['invoice_number'] . ' is ready.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        $back();
    }
    if ($do === 'resend_invoice') {
        $inv = invoice_load((int) $order['id']);
        if (!$inv) {
            flash('error', 'Issue the invoice first.');
        } elseif (!rate_limit('invoice-resend', (string) $order['id'], 5, 3600)) {
            flash('error', 'This invoice was emailed several times in the last hour. Please wait before sending it again.');
        } else {
            $sent = invoice_send_email($inv);
            audit_log('invoice_emailed', 'order', $order['order_number'], ['invoice' => $inv['invoice_number'], 'to' => $inv['billing_email'], 'sent' => $sent]);
            if ($sent) {
                flash('success', 'Invoice ' . $inv['invoice_number'] . ' emailed to ' . $inv['billing_email'] . '.');
            } elseif (!mail_configured()) {
                flash('warning', 'SMTP is not configured, so the email was written to the mail log instead of being sent.');
            } else {
                flash('error', 'The email could not be sent. Check the mail settings.');
            }
        }
        $back();
    }

    if ($do === 'refund') {
        require_permission('refunds.manage');
        [$processed, $open] = refund_sums((int) $order['id']);
        $remaining = round((float) $order['total'] - $processed - $open, 2);
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $status = in_array($_POST['status'] ?? '', ['requested', 'processing', 'processed'], true) ? $_POST['status'] : 'requested';
        if (!in_array($order['status'], ['paid', 'partially_refunded'], true)) {
            flash('error', 'Only paid orders can be refunded.');
        } elseif ($remaining <= 0) {
            flash('error', $open > 0 ? 'An open refund already covers the remaining amount. Update that refund instead of adding another.' : 'This order has been fully refunded.');
        } elseif ($amount < 1 || $amount > $remaining + 0.001) {
            flash('error', 'Refund amount must be between ₹1 and the refundable ' . inr($remaining) . '.');
        } else {
            $full = db_transaction(function () use ($order, $amount, $status, $me) {
                $tx = db_value("SELECT id FROM payment_transactions WHERE order_id = ? AND verified = 1 AND status = 'captured' ORDER BY id DESC LIMIT 1", [$order['id']]);
                db_query('INSERT INTO refunds (order_id, transaction_id, provider_refund_id, amount, reason, status, processed_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$order['id'], $tx ?: null, mb_substr(trim((string) ($_POST['provider_refund_id'] ?? '')), 0, 100) ?: null, $amount, mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255) ?: null, $status, $me['id']]);
                return $status === 'processed' ? apply_processed_refund($order, !empty($_POST['revoke'])) : null;
            });
            if ($status === 'processed') {
                require_once APP_ROOT . '/includes/notify.php';
                send_template('refund_processed', (int) $order['user_id'], ['amount' => inr($amount), 'order' => $order['order_number'], 'link' => '/dashboard/purchases', 'related' => ['order', (int) $order['id']]]);
            }
            audit_log('refund_recorded', 'order', $order['order_number'], ['amount' => $amount, 'status' => $status, 'full' => $full], 'critical');
            flash('success', $status === 'processed'
                ? 'Refund recorded as processed' . ($full ? ' - the order is now fully refunded.' : '.') . ' The student has been emailed.'
                : 'Refund request recorded. Process it in the ' . $gwName . ' dashboard, then update its status here.');
        }
        $back();
    }

    if ($do === 'update_refund') {
        require_permission('refunds.manage');
        $refund = db_one('SELECT * FROM refunds WHERE id = ? AND order_id = ?', [(int) ($_POST['refund_id'] ?? 0), $order['id']]);
        $next = (string) ($_POST['status'] ?? '');
        $allowed = ['requested' => ['processing', 'processed', 'failed'], 'processing' => ['processed', 'failed']];
        if (!$refund) {
            flash('error', 'Refund not found.');
        } elseif (!in_array($next, $allowed[$refund['status']] ?? [], true)) {
            flash('error', 'A ' . $refund['status'] . ' refund cannot be changed to “' . $next . '”.');
        } else {
            [$processedOthers] = refund_sums((int) $order['id'], (int) $refund['id']);
            if ($next === 'processed' && (float) $refund['amount'] > (float) $order['total'] - $processedOthers + 0.001) {
                flash('error', 'This refund is larger than the amount still refundable (' . inr((float) $order['total'] - $processedOthers) . ').');
                $back();
            }
            $full = db_transaction(function () use ($order, $refund, $next, $me) {
                db_query('UPDATE refunds SET status = ?, provider_refund_id = COALESCE(?, provider_refund_id), processed_by = ? WHERE id = ?',
                    [$next, mb_substr(trim((string) ($_POST['provider_refund_id'] ?? '')), 0, 100) ?: null, $me['id'], $refund['id']]);
                return $next === 'processed' ? apply_processed_refund($order, !empty($_POST['revoke'])) : null;
            });
            if ($next === 'processed') {
                require_once APP_ROOT . '/includes/notify.php';
                send_template('refund_processed', (int) $order['user_id'], ['amount' => inr($refund['amount']), 'order' => $order['order_number'], 'link' => '/dashboard/purchases', 'related' => ['order', (int) $order['id']]]);
            }
            audit_log('refund_updated', 'order', $order['order_number'], ['refund' => (int) $refund['id'], 'from' => $refund['status'], 'to' => $next, 'full' => $full], 'critical');
            flash('success', 'Refund marked ' . $next . ($full ? ' - the order is now fully refunded.' : '.') . ($next === 'processed' ? ' The student has been emailed.' : ''));
        }
        $back();
    }
}

if ($order) {
    $items = db_all('SELECT oi.*, c.slug FROM order_items oi JOIN courses c ON c.id = oi.course_id WHERE oi.order_id = ?', [$order['id']]);
    $txs = db_all('SELECT * FROM payment_transactions WHERE order_id = ? ORDER BY id', [$order['id']]);
    $refunds = db_all('SELECT r.*, u.name AS by_name FROM refunds r LEFT JOIN users u ON u.id = r.processed_by WHERE r.order_id = ? ORDER BY r.id', [$order['id']]);
    $receipt = db_one('SELECT * FROM receipts WHERE order_id = ?', [$order['id']]);
    $invoice = invoice_load((int) $order['id']);
    $whyNoInvoice = $invoice ? null : invoice_ineligible_reason($order);
    [$processed, $open] = refund_sums((int) $order['id']);
    $refundable = round((float) $order['total'] - $processed - $open, 2);

    $actions = '';
    if ($invoice) {
        $actions .= '<a class="btn btn-light" href="' . e(invoice_url($order['order_number'], 'html')) . '" target="_blank" rel="noopener">' . icon('eye') . ' View invoice</a>'
            . '<a class="btn btn-light" href="' . e(invoice_url($order['order_number'])) . '">' . icon('download') . ' Download PDF</a>'
            . action_form('resend_invoice', [], icon('mail') . ' Resend invoice email', 'btn btn-light', 'Email invoice ' . $invoice['invoice_number'] . ' to ' . ($invoice['billing_email'] ?? 'the student') . '?');
    } elseif ($whyNoInvoice === null) {
        $actions .= action_form('issue_invoice', [], icon('file') . ' Issue invoice', 'btn btn-primary', 'Issue the GST invoice for this paid order now? It gets the next invoice number.');
    }
    admin_start('Order ' . $order['order_number'], null, 'orders', ['head' => true, 'crumbs' => '<a href="orders.php">Orders</a> / ' . e($order['order_number']), 'subtitle' => 'Created ' . fmt_dt($order['created_at']), 'actions' => $actions]);
    $gateway = fn(?string $p) => $p ? ucfirst($p) : '—';
    $method = fn(?string $m) => $m ? (strlen($m) <= 4 ? strtoupper($m) : ucwords(str_replace('_', ' ', $m))) : '—';
    // Payment problems flagged automatically (amount mismatch, a second payment for a course already bought).
    $flags = array_column(db_all("SELECT action, MAX(created_at) AS at FROM audit_logs WHERE entity_type = 'order' AND entity_id = ?
                                  AND action IN ('payment_amount_mismatch', 'payment_duplicate_purchase') GROUP BY action", [$order['order_number']]), 'at', 'action');
    if (isset($flags['payment_amount_mismatch']) && !in_array($order['status'], ['paid', 'refunded', 'partially_refunded'], true)) {
        echo '<div class="alert alert-error" style="margin-bottom:18px">' . icon('alert') . ' <span><strong>Payment needs review.</strong> ' . e($gwName)
            . ' reported a successful payment whose amount does not match this order (' . e(fmt_ago((string) $flags['payment_amount_mismatch'])) . '), so access was not activated. Check it in the '
            . e($gwName) . ' dashboard, then enrol the student by hand or refund the payment there.</span></div>';
    }
    if (isset($flags['payment_duplicate_purchase'])) {
        echo '<div class="alert alert-warning" style="margin-bottom:18px">' . icon('alert') . ' <span><strong>Possible double payment.</strong> The student already had this course through another paid order when this one was paid. Refund one of the payments in the '
            . e($gwName) . ' dashboard and record the refund below, with <em>Revoke course access</em> unticked: the student keeps the course through the other order.</span></div>';
    }
    ?>
    <div class="grid g-2">
      <div class="card card-pad">
        <dl class="kv">
          <dt>Status</dt><dd><?= badge($order['status']) ?></dd>
          <dt>Student</dt><dd><a href="users.php?id=<?= (int) $order['uid'] ?>"><?= e($order['name']) ?></a><br><span class="muted"><?= e($order['email']) ?> · <?= e($order['phone'] ?? '') ?></span></dd>
          <dt>Subtotal</dt><dd><?= e(inr($order['subtotal'])) ?></dd>
          <dt>Discount</dt><dd><?= e(inr($order['discount'])) ?><?= $order['coupon_code'] ? ' (' . e($order['coupon_code']) . ')' : '' ?></dd>
          <?php if ((float) $order['tax'] > 0): ?><dt>GST in total</dt><dd><?= e(inr($order['tax'])) ?></dd><?php endif; ?>
          <dt>Total</dt><dd><strong><?= e(inr($order['total'])) ?></strong></dd>
          <dt>Gateway</dt><dd><?= e($gateway($order['provider'])) ?><?= $order['provider_order_id'] ? ' <code>' . e($order['provider_order_id']) . '</code>' : '' ?></dd>
          <dt>Paid at</dt><dd><?= e(fmt_dt($order['paid_at'])) ?></dd>
          <dt>Invoice</dt><dd><?= $invoice ? '<strong>' . e($invoice['invoice_number']) . '</strong> <span class="muted">· ' . e(fmt_dt($invoice['issued_at'], 'j M Y')) . '</span>' : '<span class="muted">' . e($whyNoInvoice ?? 'Not issued yet') . '</span>' ?></dd>
          <dt>Receipt</dt><dd><?= $receipt ? e($receipt['receipt_number']) : '—' ?></dd>
          <dt>IP address</dt><dd><?= e($order['ip_address'] ?? '—') ?></dd>
        </dl>
      </div>
      <div class="card">
        <div class="card-head"><h2>Billing details</h2><span class="muted" style="font-size:12px">Snapshot taken at checkout</span></div>
        <div class="card-pad">
          <dl class="kv">
            <dt>Name</dt><dd><?= e($order['billing_name'] ?: $order['name']) ?></dd>
            <dt>Email</dt><dd><?= e($order['billing_email'] ?: $order['email']) ?></dd>
            <dt>Phone</dt><dd><?= e($order['billing_phone'] ?: ($order['phone'] ?? '—')) ?></dd>
            <dt>State</dt><dd><?= $order['billing_state'] ? e($order['billing_state']) . ' (' . e((string) state_code($order['billing_state'])) . ')' : '—' ?></dd>
            <dt>GSTIN</dt><dd><?= e($order['billing_gstin'] ?: '—') ?></dd>
            <dt>Address</dt><dd><?= $order['billing_address'] ? nl2br(e($order['billing_address'])) : '—' ?></dd>
          </dl>
        </div>
        <div class="table-wrap"><table class="t stack"><thead><tr><th>Course</th><th>Price</th><th>Discount</th><th>Total</th></tr></thead><tbody>
        <?php foreach ($items as $i): ?><tr><td data-label="Course"><strong><?= e($i['title']) ?></strong></td><td data-label="Price"><?= e(inr($i['unit_price'])) ?></td><td data-label="Discount"><?= (float) $i['discount'] > 0 ? e(inr($i['discount'])) : '<span class="muted">—</span>' ?></td><td data-label="Total"><?= e(inr($i['total'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      </div>
    </div>
    <div class="card mt">
      <div class="card-head"><h2>Payment transactions</h2><span class="muted" style="font-size:12px">Verified = signature, signed webhook or gateway API check · “Created” = checkout opened, no payment yet</span></div>
      <?php if ($txs): ?>
      <div class="table-wrap"><table class="t stack"><thead><tr><th>Payment ID</th><th>Amount</th><th>Method</th><th>Status</th><th>Verified</th><th>Error</th><th>Time</th></tr></thead><tbody>
      <?php foreach ($txs as $t): ?><tr>
        <td data-label="Payment ID"><?= $t['provider_payment_id'] ? '<code>' . e($t['provider_payment_id']) . '</code>' : '<span class="muted">Not paid yet</span>' ?></td>
        <td data-label="Amount"><?= e(inr($t['amount'])) ?></td>
        <td data-label="Method"><?= e($method($t['method'])) ?></td>
        <td data-label="Status"><?= badge($t['status']) ?></td>
        <td data-label="Verified"><div><?= $t['verified'] ? '<span class="badge b-green">Verified</span><span class="d2-sub">via ' . e((string) $t['verified_via']) . '</span>' : '<span class="badge b-gray">Not verified</span>' ?></div></td>
        <td data-label="Error" class="muted"><?= $t['error_description'] ? e($t['error_description']) : '—' ?></td>
        <td data-label="Time" class="d2-nowrap"><?= e(fmt_dt($t['updated_at'] ?? $t['created_at'], 'j M Y, g:i A')) ?></td>
      </tr><?php endforeach; ?>
      </tbody></table></div>
      <?php else: ?>
      <div class="empty"><span class="empty-ic"><?= icon('receipt') ?></span><strong><?= (float) $order['total'] <= 0 ? 'No payment needed' : 'No payment attempts yet' ?></strong><p><?= (float) $order['total'] <= 0 ? 'This order was fully covered by a coupon.' : 'Attempts appear here when the student opens the ' . e($gwName) . ' checkout.' ?></p></div>
      <?php endif; ?>
    </div>
    <div class="grid g-2 mt">
      <div class="card">
        <div class="card-head"><h2>Refunds</h2><span class="muted" style="font-size:12px">Refunded <?= e(inr($processed)) ?><?= $open > 0 ? ' · open ' . e(inr($open)) : '' ?></span></div>
        <?php if ($refunds): ?>
        <div class="table-wrap"><table class="t stack"><thead><tr><th>Amount</th><th>Status</th><th>Recorded</th><th><span class="sr-only">Update</span></th></tr></thead><tbody>
        <?php foreach ($refunds as $r): ?>
          <tr>
            <td data-label="Amount"><div><strong><?= e(inr($r['amount'])) ?></strong><?= $r['reason'] ? '<span class="d2-sub">' . e($r['reason']) . '</span>' : '' ?></div></td>
            <td data-label="Status"><div><?= badge($r['status']) ?><?= $r['provider_refund_id'] ? '<span class="d2-sub"><code>' . e($r['provider_refund_id']) . '</code></span>' : '' ?></div></td>
            <td data-label="Recorded"><div><?= e(fmt_dt($r['updated_at'], 'j M Y')) ?><?= $r['by_name'] ? '<span class="d2-sub">by ' . e($r['by_name']) . '</span>' : '' ?></div></td>
            <td data-label="Update">
              <?php if (can('refunds.manage') && in_array($r['status'], ['requested', 'processing'], true)): ?>
              <form method="post" class="form" style="display:grid;gap:6px;min-width:190px"><?= csrf_field() ?>
                <input type="hidden" name="_action" value="update_refund"><input type="hidden" name="refund_id" value="<?= (int) $r['id'] ?>">
                <label class="sr-only" for="rs-<?= (int) $r['id'] ?>">New status</label>
                <select class="in" id="rs-<?= (int) $r['id'] ?>" name="status">
                  <?php if ($r['status'] === 'requested'): ?><option value="processing">Processing</option><?php endif; ?>
                  <option value="processed">Processed (money returned)</option><option value="failed">Failed / cancelled</option>
                </select>
                <label class="sr-only" for="rp-<?= (int) $r['id'] ?>"><?= e($gwName) ?> refund ID</label>
                <input class="in" id="rp-<?= (int) $r['id'] ?>" name="provider_refund_id" placeholder="<?= e($gwName) ?> refund ID<?= $order['provider'] === 'razorpay' ? ' (rfnd_…)' : '' ?>" value="<?= e($r['provider_refund_id'] ?? '') ?>" maxlength="100">
                <label class="check" style="font-size:12px"><input type="checkbox" name="revoke" value="1" checked> Revoke access if this completes a full refund</label>
                <button class="btn btn-light btn-sm" data-confirm="Update this refund? A processed refund emails the student and is logged.">Update refund</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php else: ?>
        <div class="empty"><span class="empty-ic"><?= icon('refresh') ?></span><strong>No refunds</strong><p>Refunds you record for this order are listed here.</p></div>
        <?php endif; ?>
      </div>
      <?php if (can('refunds.manage') && in_array($order['status'], ['paid', 'partially_refunded'], true) && $refundable >= 1): ?>
      <form method="post" class="card">
        <?= csrf_field() ?><input type="hidden" name="_action" value="refund">
        <div class="card-head"><h2>Record a refund</h2><span class="muted" style="font-size:12px">Refundable now: <?= e(inr($refundable)) ?></span></div>
        <div class="card-pad form">
          <div class="field"><label for="ra">Amount (₹)</label><input id="ra" type="number" step="0.01" min="1" max="<?= e((string) $refundable) ?>" name="amount" value="<?= e((string) $refundable) ?>" required></div>
          <div class="field"><label for="rs">Status</label><select id="rs" name="status"><option value="requested">Requested</option><option value="processing">Processing</option><option value="processed">Processed (money returned)</option></select></div>
          <div class="field"><label for="rp"><?= e($gwName) ?> refund ID</label><input id="rp" type="text" name="provider_refund_id" placeholder="<?= $order['provider'] === 'razorpay' ? 'rfnd_…' : 'From the ' . e($gwName) . ' dashboard' ?>" maxlength="100"></div>
          <div class="field"><label for="rr">Reason</label><input id="rr" type="text" name="reason" maxlength="255"></div>
          <div class="field full"><label class="check"><input type="checkbox" name="revoke" value="1" checked> Revoke course access when fully refunded</label></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" data-confirm="Record this refund? This is logged.">Record refund</button></div>
      </form>
      <?php elseif (can('refunds.manage') && $open > 0): ?>
      <div class="card card-pad"><p class="muted" style="margin:0">An open refund covers the remaining amount. Update its status in the Refunds list once <?= e($gwName) ?> has processed it.</p></div>
      <?php endif; ?>
    </div>
    <?php
    admin_end();
    exit;
}

// ---------------- List ----------------
$where = ['1=1'];
$params = [];
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $where[] = '(o.order_number LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR o.provider_order_id LIKE ? OR i.invoice_number LIKE ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$status = (string) ($_GET['status'] ?? '');
if (in_array($status, ['pending', 'paid', 'failed', 'cancelled', 'refunded', 'partially_refunded'], true)) {
    $where[] = 'o.status = ?';
    $params[] = $status;
}
$w = implode(' AND ', $where);
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = (int) db_value("SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id LEFT JOIN invoices i ON i.order_id = o.id WHERE {$w}", $params);
$rows = db_all("SELECT o.*, u.name, u.email, i.invoice_number, (SELECT GROUP_CONCAT(title SEPARATOR ', ') FROM order_items WHERE order_id = o.id) AS items
                FROM orders o JOIN users u ON u.id = o.user_id LEFT JOIN invoices i ON i.order_id = o.id WHERE {$w} ORDER BY o.id DESC LIMIT 25 OFFSET " . (($page - 1) * 25), $params);
$sum = db_one("SELECT COUNT(*) n, COALESCE(SUM(total), 0) s FROM orders WHERE status = 'paid'");
$paidCount = (int) $sum['n'];
$filtered = $q !== '' || $status !== '';

admin_start('Orders', null, 'orders', ['head' => true, 'subtitle' => number_format($paidCount) . ' paid order' . ($paidCount === 1 ? '' : 's') . ' · ' . inr($sum['s']) . ' in verified payments',
    'actions' => '<a class="btn btn-light" href="invoices.php">' . icon('file') . ' Invoices</a>' . (can('reports.export') ? '<a class="btn btn-light" href="?export=csv">' . icon('download') . ' Export CSV</a>' : '')]);
?>
<div class="card">
  <form class="toolbar" method="get" role="search">
    <label class="search-in"><span class="sr-only">Search orders</span><?= icon('search') ?><input class="in" type="search" name="q" value="<?= e($q) ?>" placeholder="Order no., invoice no., student, email or gateway order ID…"></label>
    <select class="in" style="width:auto" name="status" aria-label="Status" data-autosubmit><option value="">All statuses</option>
      <?php foreach (['paid', 'pending', 'failed', 'refunded', 'partially_refunded', 'cancelled'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-light"><?= icon('search') ?> Filter</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="orders.php">Reset</a><?php endif; ?>
  </form>
  <?php if (!$rows): ?>
  <div class="empty"><span class="empty-ic"><?= icon($filtered ? 'search' : 'cart') ?></span>
    <strong><?= $filtered ? 'No orders match these filters' : 'No orders yet' ?></strong>
    <p><?= $filtered ? 'Try a different search or clear the filters.' : 'Orders appear here as soon as a student starts a checkout.' ?></p>
    <?php if ($filtered): ?><a class="btn btn-light btn-sm" href="orders.php">Clear filters</a><?php endif; ?>
  </div>
  <?php else: ?>
  <div class="table-wrap"><table class="t stack"><thead><tr><th>Order</th><th>Student</th><th>Course</th><th>Total</th><th>Status</th><th>Invoice</th><th>Date</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>
  <?php foreach ($rows as $o): $link = '?order=' . urlencode($o['order_number']); ?>
    <tr>
      <td data-label="Order"><a class="d2-nowrap" href="<?= e($link) ?>"><strong><?= e($o['order_number']) ?></strong></a></td>
      <td data-label="Student"><div><strong><?= e($o['billing_name'] ?: $o['name']) ?></strong><span class="d2-sub"><?= e($o['email']) ?></span></div></td>
      <td data-label="Course" class="d2-w200"><span class="d2-clip" title="<?= e((string) $o['items']) ?>"><?= e((string) $o['items']) ?></span></td>
      <td data-label="Total" class="d2-nowrap"><div><strong><?= e(inr($o['total'])) ?></strong><?= (float) $o['discount'] > 0 ? '<span class="d2-sub">−' . e(inr($o['discount'])) . ' discount</span>' : '' ?></div></td>
      <td data-label="Status"><?= badge($o['status']) ?></td>
      <td data-label="Invoice"><?= $o['invoice_number'] ? '<a class="d2-nowrap" href="' . e(invoice_url($o['order_number'])) . '" title="Download PDF">' . icon('download') . ' ' . e($o['invoice_number']) . '</a>' : '<span class="muted">—</span>' ?></td>
      <td data-label="Date" class="d2-nowrap"><div><?= e(fmt_dt($o['created_at'], 'j M Y')) ?><span class="d2-sub"><?= e(fmt_dt($o['created_at'], 'g:i A')) ?></span></div></td>
      <td class="actions"><a class="btn btn-light btn-sm" href="<?= e($link) ?>"><?= icon('eye') ?> View</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php render_pager($total, $page, 25); ?>
</div>
<?php admin_end();
