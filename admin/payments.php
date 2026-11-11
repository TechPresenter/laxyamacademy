<?php
// Payment transactions, refunds, webhook events and the payments report (reconciliation views).
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/payments.php';

require_staff_page();
require_permission('payments.view');
$tabs = ['transactions' => 'Transactions', 'report' => 'Payment Report', 'refunds' => 'Refunds', 'webhooks' => 'Webhook Events', 'setup' => 'Gateway Setup'];
if (($_GET['view'] ?? '') === 'report' && !isset($_GET['tab'])) {
    $_GET['tab'] = 'report'; // old sidebar link payments.php?view=report
}
$tab = current_tab($tabs);
$page = max(1, (int) ($_GET['page'] ?? 1));

// ---------------- Report data (also used by the CSV export) ----------------
if ($tab === 'report') {
    // Shared report date range: quick ranges incl. the admin's Academic Session, custom from/to (max two years).
    require_once APP_ROOT . '/includes/admin-reports.php';
    $rr = report_range(731);
    [$from, $to, $range] = [$rr['from'], $rr['to'], $rr['range']];
    $paidWhere = "o.status IN ('paid', 'partially_refunded', 'refunded') AND o.total > 0 AND o.paid_at BETWEEN ? AND ?";

    $byDay = [];
    foreach (db_all("SELECT DATE(o.paid_at) AS d, COUNT(*) AS n, SUM(o.total) AS amount FROM orders o WHERE {$paidWhere} GROUP BY DATE(o.paid_at)", $range) as $r) {
        $byDay[$r['d']] = ['orders' => (int) $r['n'], 'gross' => (float) $r['amount'], 'refunds' => 0.0, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0];
    }
    foreach (db_all("SELECT DATE(r.updated_at) AS d, SUM(r.amount) AS amount FROM refunds r WHERE r.status = 'processed' AND r.updated_at BETWEEN ? AND ? GROUP BY DATE(r.updated_at)", $range) as $r) {
        $byDay[$r['d']] = ($byDay[$r['d']] ?? ['orders' => 0, 'gross' => 0.0, 'refunds' => 0.0, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0]);
        $byDay[$r['d']]['refunds'] = (float) $r['amount'];
    }
    foreach (db_all('SELECT DATE(i.issued_at) AS d, SUM(i.taxable_value) AS taxable, SUM(i.cgst) AS cgst, SUM(i.sgst) AS sgst, SUM(i.igst) AS igst FROM invoices i WHERE i.issued_at BETWEEN ? AND ? GROUP BY DATE(i.issued_at)', $range) as $r) {
        $byDay[$r['d']] = ($byDay[$r['d']] ?? ['orders' => 0, 'gross' => 0.0, 'refunds' => 0.0, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0]);
        $byDay[$r['d']] = ['taxable' => (float) $r['taxable'], 'cgst' => (float) $r['cgst'], 'sgst' => (float) $r['sgst'], 'igst' => (float) $r['igst']] + $byDay[$r['d']];
    }
    krsort($byDay);

    if (($_GET['export'] ?? '') === 'csv') {
        require_permission('reports.export');
        $rows = [];
        foreach ($byDay as $d => $r) {
            $rows[] = [$d, $r['orders'], number_format($r['gross'], 2, '.', ''), number_format($r['refunds'], 2, '.', ''), number_format($r['gross'] - $r['refunds'], 2, '.', ''),
                number_format($r['taxable'], 2, '.', ''), number_format($r['cgst'], 2, '.', ''), number_format($r['sgst'], 2, '.', ''), number_format($r['igst'], 2, '.', '')];
        }
        csv_download("payments-{$from}-to-{$to}.csv", ['Date', 'Paid orders', 'Collected', 'Refunded', 'Net', 'Taxable value (invoices)', 'CGST', 'SGST', 'IGST'], $rows);
    }
}

$gwLabel = payment_provider_label();
$titles = [
    'transactions' => ['Transactions', 'Every payment attempt (Cashfree / Razorpay). Reconcile these records against the gateway dashboard.'],
    'report' => ['Payment Reports', 'Money collected, refunded and GST invoiced - by day, method, course and place of supply.'],
    'refunds' => ['Refunds', 'Refunds recorded against orders and their processing status.'],
    'webhooks' => ['Webhook Events', 'Payment notifications received from the gateway and how they were handled.'],
    'setup' => ['Gateway Setup & Webhook Security', $gwLabel . ' keys, mode and how incoming webhooks are verified.'],
];
admin_start($titles[$tab][0], null, 'payments', ['head' => true, 'subtitle' => $titles[$tab][1]]);
echo admin_tabs($tabs, $tab);

if ($tab === 'setup') {
    $g = payment_gateway_status(in_array(payment_provider(), ['razorpay', 'cashfree'], true) ? payment_provider() : 'razorpay');
    $configured = $g['configured'] && in_array(payment_provider(), ['razorpay', 'cashfree'], true);
    $webhook = $g['webhook_ready'];
    $hookUrl = $g['webhook_url'];
    $wh = db_one("SELECT COUNT(*) n, SUM(signature_valid = 0) bad, SUM(status = 'failed') failed, MAX(received_at) last FROM webhook_events WHERE provider = ? AND received_at > NOW() - INTERVAL 30 DAY", [$g['provider']]);
    $badSig = (int) db_value("SELECT COUNT(*) FROM audit_logs WHERE action = 'webhook_signature_invalid' AND created_at > NOW() - INTERVAL 30 DAY");
    $live = $g['mode'] === 'live';
    $isCf = $g['provider'] === 'cashfree';
    ?>
    <div class="grid g-2">
      <section class="card">
        <div class="card-head"><h2><?= icon('plug') ?> <?= e($g['label']) ?> gateway <span class="badge b-blue">Active at checkout</span></h2><?= $configured ? '<span class="badge b-green">Keys configured</span>' : '<span class="badge b-red">Not configured</span>' ?></div>
        <div class="card-pad">
          <dl class="kv">
            <dt>Mode</dt><dd><?= $live ? '<span class="badge b-green">Live</span> real payments' : '<span class="badge b-amber">Sandbox</span> test payments only' ?></dd>
            <dt><?= $isCf ? 'App ID' : 'Key ID' ?></dt><dd><?= $configured ? '<code>' . e((string) $g['key_hint']) . '</code>' : '<span class="muted">' . e(implode(' / ', $g['missing'] ?: ['PAYMENT_PROVIDER'])) . ' not set - <a href="keys.php#payments">Keys &amp; Credentials</a></span>' ?></dd>
            <?php if ($isCf && $g['api_override']): ?><dt>API</dt><dd><span class="badge b-amber">Local test server</span> <code><?= e($g['api_base']) ?></code></dd><?php endif; ?>
            <?php if ($g['last_error']): ?><dt>Last API error</dt><dd><span class="muted"><?= e(fmt_ago((string) $g['last_error']['at'])) ?>:</span> <?= e((string) $g['last_error']['msg']) ?></dd><?php endif; ?>
            <dt>GST</dt><dd><?= gst_config()['enabled'] ? e(rtrim(rtrim(number_format(gst_config()['rate'], 2), '0'), '.')) . '% · ' . (gst_config()['inclusive'] ? 'included in course prices' : 'added at checkout') : 'Not charged (no GSTIN or switched off)' ?><?= can('settings.manage') ? ' · <a href="settings.php#company">Company &amp; Invoicing</a>' : '' ?></dd>
          </dl>
          <p class="muted" style="margin:14px 0 0">Keys and secrets are set in <a href="keys.php#payments">Keys &amp; Credentials</a> (stored encrypted) or the server's <code>.env</code> file - never sent to the browser.<?= can('settings.manage') ? ' Test the keys on <a href="integrations.php?tab=payments">SEO &amp; Integrations → Payment Gateways</a>.' : '' ?></p>
        </div>
      </section>
      <section class="card">
        <div class="card-head"><h2><?= icon('shield') ?> Webhook security</h2><?= $webhook ? '<span class="badge b-green">Signature check on</span>' : '<span class="badge b-red">' . ($isCf ? 'Keys missing' : 'Secret missing') . '</span>' ?></div>
        <div class="card-pad">
          <dl class="kv">
            <dt>Webhook URL</dt><dd><code style="word-break:break-all"><?= e($hookUrl) ?></code><br><button type="button" class="btn btn-ghost btn-sm" style="margin-top:4px" data-copy="<?= e($hookUrl) ?>"><?= icon('copy') ?> Copy URL</button></dd>
            <dt>Events</dt><dd><?= implode(', ', array_map(fn($ev) => '<code>' . e($ev) . '</code>', $g['webhook_events'])) ?></dd>
            <?php if ($isCf): ?>
            <dt>Verification</dt><dd><code>x-webhook-signature</code> = base64 HMAC-SHA256 of <code>x-webhook-timestamp</code> + raw body, keyed with the Cashfree client secret (<code>CASHFREE_SECRET_KEY</code>). Mismatched, unsigned or stale (older than <?= (int) round(max(60, min(86400, (int) config('CASHFREE_WEBHOOK_TOLERANCE', 600))) / 60) ?> min) requests are rejected and logged. Repeats of the same payment event (same order, payment ID and status - taken from the signed body, not from a header) are processed once, and the order is re-checked with the Cashfree API (Get Order + Get Payments) before access is granted.</dd>
            <?php else: ?>
            <dt>Verification</dt><dd>HMAC-SHA256 of the raw body with <code>RAZORPAY_WEBHOOK_SECRET</code>; unsigned or mismatched requests are rejected and logged. Each event id is processed once.</dd>
            <?php endif; ?>
            <dt>Last 30 days</dt><dd><?= (int) $wh['n'] ? number_format((int) $wh['n']) . ' events · last ' . e(fmt_ago($wh['last'])) : 'No events received' ?><?= (int) $wh['failed'] ? ' · <span class="badge b-red">' . (int) $wh['failed'] . ' failed</span>' : '' ?><?= $badSig ? ' · <span class="badge b-amber">' . $badSig . ' rejected signatures</span>' : '' ?></dd>
          </dl>
          <p style="margin:14px 0 0"><a class="btn btn-light btn-sm" href="payments.php?tab=webhooks"><?= icon('log') ?> Webhook events</a></p>
        </div>
      </section>
    </div>
    <?php
    admin_end();
    exit;
}

if ($tab === 'report') {
    $totals = ['orders' => 0, 'gross' => 0.0, 'refunds' => 0.0, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0];
    foreach ($byDay as $r) {
        foreach ($totals as $k => $_) {
            $totals[$k] += $r[$k];
        }
    }
    $byMethod = db_all("SELECT COALESCE(NULLIF(t.method, ''), 'not reported') AS method, COUNT(DISTINCT o.id) AS n, SUM(t.amount) AS amount
                        FROM orders o JOIN payment_transactions t ON t.order_id = o.id AND t.verified = 1 AND t.status IN ('captured', 'refunded')
                        WHERE {$paidWhere} GROUP BY method ORDER BY amount DESC", $range);
    $byCourse = db_all("SELECT oi.title, COUNT(*) AS n, SUM(oi.total) AS amount FROM orders o JOIN order_items oi ON oi.order_id = o.id
                        WHERE {$paidWhere} GROUP BY oi.course_id, oi.title ORDER BY amount DESC LIMIT 20", $range);
    $byState = db_all('SELECT COALESCE(i.billing_state, i.seller_state) AS state, COUNT(*) AS n, SUM(i.taxable_value) AS taxable, SUM(i.cgst + i.sgst) AS local_tax, SUM(i.igst) AS igst, SUM(i.total) AS total
                       FROM invoices i WHERE i.issued_at BETWEEN ? AND ? GROUP BY state ORDER BY total DESC', $range);
    // Chart: collected per day / week / month over the whole range
    $bk = report_buckets($from, $to);
    $chartVals = report_fill($bk, db_all('SELECT ' . report_bucket_sql('o.paid_at', $bk['unit']) . " b, SUM(o.total) v FROM orders o WHERE {$paidWhere} GROUP BY b", $range));
    $prevGross = (float) db_value("SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE {$paidWhere}", $rr['prev']);
    $failedN = (int) db_value("SELECT COUNT(*) FROM payment_transactions WHERE status = 'failed' AND updated_at BETWEEN ? AND ?", $range);
    echo report_filter_bar($rr, ['tab' => 'report'], url_with(['export' => 'csv']), 'Reconcile these totals against the ' . $gwLabel . ' dashboard (Settlements) for the same dates.');
    echo report_kpis([
        ['label' => 'Collected', 'icon' => 'rupee', 'tone' => 'orange', 'value' => inr($totals['gross']), 'meta' => report_change($totals['gross'], $prevGross) . ' · ' . number_format($totals['orders']) . ' paid orders'],
        ['label' => 'Refunded', 'icon' => 'refresh', 'tone' => 'red', 'value' => inr($totals['refunds']), 'meta' => 'processed in this period', 'href' => 'payments.php?tab=refunds'],
        ['label' => 'Net', 'icon' => 'trending', 'tone' => 'green', 'value' => inr($totals['gross'] - $totals['refunds']), 'meta' => $failedN ? '<a href="payments.php?tab=transactions&amp;status=failed">' . number_format($failedN) . ' failed payment' . ($failedN === 1 ? '' : 's') . '</a>' : 'no failed payments'],
        ['label' => 'GST on invoices', 'icon' => 'invoice', 'tone' => 'purple', 'value' => inr($totals['cgst'] + $totals['sgst'] + $totals['igst']), 'meta' => 'taxable ' . e(inr($totals['taxable']))],
    ]);
    ?>
    <div class="card" style="margin-bottom:18px"><div class="card-head"><h2><?= icon('chart') ?> Collected <?= e($bk['title']) ?></h2></div><div class="card-pad"><?= report_bars($bk, $chartVals, 'Collected ' . $bk['title'], true) ?></div></div>
    <?php
    if (!$byDay) {
        admin_end(); // no payments, refunds or invoices in the range: the breakdown tables would all be empty
        exit;
    }
    ?>
    <div class="grid g-2" style="margin-bottom:18px">
      <div class="card"><div class="card-head"><h2>By payment method</h2></div><div class="table-wrap"><table class="t"><thead><tr><th>Method</th><th>Orders</th><th>Amount</th></tr></thead><tbody>
        <?php foreach ($byMethod as $m): ?><tr><td><?= e(payment_method_label($m['method'] === 'not reported' ? null : $m['method'])) ?></td><td><?= (int) $m['n'] ?></td><td><?= e(inr($m['amount'])) ?></td></tr><?php endforeach; ?>
        <?php if (!$byMethod): ?><tr><td colspan="3" class="muted">No verified payments in this period.</td></tr><?php endif; ?>
      </tbody></table></div></div>
      <div class="card"><div class="card-head"><h2>By course</h2></div><div class="table-wrap"><table class="t"><thead><tr><th>Course</th><th>Orders</th><th>Amount</th></tr></thead><tbody>
        <?php foreach ($byCourse as $c): ?><tr><td><?= e($c['title']) ?></td><td><?= (int) $c['n'] ?></td><td><?= e(inr($c['amount'])) ?></td></tr><?php endforeach; ?>
        <?php if (!$byCourse): ?><tr><td colspan="3" class="muted">No paid orders in this period.</td></tr><?php endif; ?>
      </tbody></table></div></div>
    </div>
    <div class="card" style="margin-bottom:18px"><div class="card-head"><h2>GST summary (invoices issued in the period)</h2><?= can('orders.view') ? '<a class="btn btn-light btn-sm" href="invoices.php?from=' . e($from) . '&amp;to=' . e($to) . '">' . icon('file') . ' Invoices</a>' : '' ?></div>
      <div class="table-wrap"><table class="t"><thead><tr><th>Place of supply</th><th>Invoices</th><th>Taxable value</th><th>CGST + SGST</th><th>IGST</th><th>Invoice value</th></tr></thead><tbody>
        <?php foreach ($byState as $s): ?><tr><td><?= e((string) $s['state']) ?> <span class="muted">(<?= e((string) state_code($s['state'])) ?>)</span></td><td><?= (int) $s['n'] ?></td><td><?= e(inr($s['taxable'])) ?></td><td><?= e(inr($s['local_tax'])) ?></td><td><?= e(inr($s['igst'])) ?></td><td><?= e(inr($s['total'])) ?></td></tr><?php endforeach; ?>
        <?php if ($byState): ?><tr><th>Total</th><th><?= (int) array_sum(array_column($byState, 'n')) ?></th><th><?= e(inr($totals['taxable'])) ?></th><th><?= e(inr($totals['cgst'] + $totals['sgst'])) ?></th><th><?= e(inr($totals['igst'])) ?></th><th><?= e(inr(array_sum(array_column($byState, 'total')))) ?></th></tr><?php endif; ?>
        <?php if (!$byState): ?><tr><td colspan="6" class="muted">No invoices issued in this period.</td></tr><?php endif; ?>
      </tbody></table></div></div>
    <div class="card"><div class="card-head"><h2>Daily breakdown</h2></div><div class="table-wrap"><table class="t"><thead><tr><th>Date</th><th>Paid orders</th><th>Collected</th><th>Refunded</th><th>Net</th><th>GST</th></tr></thead><tbody>
      <?php foreach ($byDay as $d => $r): ?><tr><td><?= e(date('D, j M Y', strtotime($d))) ?></td><td><?= (int) $r['orders'] ?></td><td><?= e(inr($r['gross'])) ?></td><td><?= $r['refunds'] > 0 ? e(inr($r['refunds'])) : '—' ?></td><td><?= e(inr($r['gross'] - $r['refunds'])) ?></td><td><?= e(inr($r['cgst'] + $r['sgst'] + $r['igst'])) ?></td></tr><?php endforeach; ?>
      <?php if (!$byDay): ?><tr><td colspan="6" class="muted">No payments or refunds in this period.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <?php
    admin_end();
    exit;
}

if ($tab === 'refunds') {
    $rows = db_all('SELECT r.*, o.order_number, u.name, s.name AS staff FROM refunds r JOIN orders o ON o.id = r.order_id JOIN users u ON u.id = o.user_id LEFT JOIN users s ON s.id = r.processed_by ORDER BY r.id DESC LIMIT 100');
    echo '<p class="muted">Refunds are recorded and updated (requested → processing → processed / failed) from the order page after processing them in the payment gateway dashboard (' . e($gwLabel) . ').</p><div class="card"><div class="table-wrap"><table class="t stack"><thead><tr><th>Order</th><th>Student</th><th>Amount</th><th>Gateway refund ID</th><th>Reason</th><th>Status</th><th>Updated</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td data-label="Order"><a href="orders.php?order=' . e(urlencode($r['order_number'])) . '">' . e($r['order_number']) . '</a></td><td data-label="Student">' . e($r['name']) . '</td><td data-label="Amount">' . e(inr($r['amount'])) . '</td><td data-label="Refund ID"><code>' . e($r['provider_refund_id'] ?? '—') . '</code></td><td data-label="Reason" class="muted">' . e($r['reason'] ?? '') . '</td><td data-label="Status">' . badge($r['status']) . '</td><td data-label="Updated">' . e(fmt_dt($r['updated_at'], 'j M Y')) . '<br><span class="muted">' . e($r['staff'] ?? '') . '</span></td>'
            . '<td class="actions">' . (in_array($r['status'], ['requested', 'processing'], true) && can('refunds.manage') ? '<a class="btn btn-light btn-sm" href="orders.php?order=' . e(urlencode($r['order_number'])) . '">' . icon('edit') . ' Update</a>' : '') . '</td></tr>';
    }
    echo $rows ? '' : '<tr><td colspan="8" class="muted">No refunds recorded.</td></tr>';
    echo '</tbody></table></div></div>';
    admin_end();
    exit;
}

if ($tab === 'webhooks') {
    $total = (int) db_value('SELECT COUNT(*) FROM webhook_events');
    $rows = db_all('SELECT id, provider, event_id, event_type, signature_valid, status, error, received_at, processed_at FROM webhook_events ORDER BY id DESC LIMIT 25 OFFSET ' . (($page - 1) * 25));
    echo '<div class="card"><div class="table-wrap"><table class="t stack"><thead><tr><th>Event</th><th>Gateway</th><th>Type</th><th>Signature</th><th>Status</th><th>Received</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td data-label="Event"><code>' . e($r['event_id']) . '</code></td><td data-label="Gateway">' . e(payment_provider_label((string) $r['provider'])) . '</td><td data-label="Type">' . e($r['event_type']) . '</td><td data-label="Signature">' . ($r['signature_valid'] ? badge('approved') : badge('failed')) . '</td><td data-label="Status">' . badge($r['status']) . ($r['error'] ? '<br><span class="muted">' . e($r['error']) . '</span>' : '') . '</td><td data-label="Received">' . e(fmt_dt($r['received_at'])) . '</td></tr>';
    }
    echo $rows ? '' : '<tr><td colspan="6" class="muted">No webhook events received yet.</td></tr>';
    echo '</tbody></table></div>';
    render_pager($total, $page, 25);
    echo '</div><p class="muted">Failed events are processed again automatically when the gateway retries them. Rejected (unsigned, tampered or stale) calls are not stored here - see SEO &amp; Integrations → Integration Logs.</p>';
    admin_end();
    exit;
}

// ---------------- Transactions ----------------
// "created" rows only record that checkout was opened; they are hidden unless asked for.
$status = (string) ($_GET['status'] ?? '');
$provider = in_array($_GET['provider'] ?? '', ['razorpay', 'cashfree'], true) ? (string) $_GET['provider'] : '';
$where = in_array($status, ['created', 'authorized', 'captured', 'failed', 'refunded'], true) ? 't.status = ?' : "t.status <> 'created'";
$params = $where === 't.status = ?' ? [$status] : [];
if ($provider !== '') {
    $where .= ' AND t.provider = ?';
    $params[] = $provider;
}
$total = (int) db_value("SELECT COUNT(*) FROM payment_transactions t WHERE {$where}", $params);
$opened = $status === '' ? (int) db_value("SELECT COUNT(*) FROM payment_transactions WHERE status = 'created'" . ($provider !== '' ? ' AND provider = ?' : ''), $provider !== '' ? [$provider] : []) : 0;
$rows = db_all("SELECT t.*, o.order_number, o.status AS order_status, u.name FROM payment_transactions t JOIN orders o ON o.id = t.order_id JOIN users u ON u.id = o.user_id WHERE {$where} ORDER BY t.id DESC LIMIT 25 OFFSET " . (($page - 1) * 25), $params);
?>
<div class="card">
  <form class="toolbar" method="get"><input type="hidden" name="tab" value="transactions">
    <select class="in" style="width:auto" name="status" aria-label="Status" data-autosubmit><option value="">Payments (captured, failed, refunded)</option>
      <?php foreach (['captured' => 'Captured', 'failed' => 'Failed', 'refunded' => 'Refunded', 'authorized' => 'Authorized', 'created' => 'Checkout opened, not paid'] as $s => $l): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <select class="in" style="width:auto" name="provider" aria-label="Gateway" data-autosubmit><option value="">All gateways</option>
      <?php foreach (['cashfree' => 'Cashfree', 'razorpay' => 'Razorpay'] as $p => $l): ?><option value="<?= $p ?>"<?= $provider === $p ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <?php if ($opened > 0): ?><a class="muted" href="?tab=transactions&amp;status=created<?= $provider !== '' ? '&amp;provider=' . e($provider) : '' ?>"><?= number_format($opened) ?> checkout<?= $opened === 1 ? '' : 's' ?> opened without a payment</a><?php endif; ?>
  </form>
  <div class="table-wrap"><table class="t stack"><thead><tr><th>Payment</th><th>Order</th><th>Amount</th><th>Method</th><th>Status</th><th>Verified</th><th>Time</th></tr></thead><tbody>
  <?php foreach ($rows as $t): ?>
    <tr><td data-label="Payment"><span class="muted" style="font-size:12px"><?= e(payment_provider_label($t['provider'])) ?> <?= $t['provider_payment_id'] ? 'payment ID' : 'order' ?></span><br><code><?= e($t['provider_payment_id'] ?? $t['provider_order_id'] ?? '—') ?></code></td><td data-label="Order"><a href="orders.php?order=<?= e(urlencode($t['order_number'])) ?>"><?= e($t['order_number']) ?></a><br><span class="muted"><?= e($t['name']) ?> · order <?= e(str_replace('_', ' ', $t['order_status'])) ?></span></td><td data-label="Amount"><?= e(inr($t['amount'])) ?></td><td data-label="Method"><?= e($t['method'] ? payment_method_label($t['method']) : '—') ?></td><td data-label="Status"><?= $t['status'] === 'created' ? '<span class="badge b-gray">Checkout opened</span>' : badge($t['status']) ?><?= $t['error_description'] ? '<br><span class="muted">' . e($t['error_description']) . '</span>' : '' ?></td><td data-label="Verified"><?= $t['verified'] ? badge('approved') . ' <span class="muted">' . e($t['verified_via']) . '</span>' : '<span class="badge b-gray">No</span>' ?></td><td data-label="Time"><?= e(fmt_dt($t['updated_at'] ?? $t['created_at'])) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">No transactions yet.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php render_pager($total, $page, 25); ?>
</div>
<?php admin_end();
