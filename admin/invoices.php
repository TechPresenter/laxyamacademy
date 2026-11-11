<?php
// Invoices: every GST-ready invoice issued for a verified payment, with filters, PDF/HTML,
// resend, a GSTR-1 friendly CSV export and a backfill for older paid orders.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/payments.php';

$me = require_staff_page();
require_permission('orders.view');

// ---------------- Filters (shared by list, totals and CSV) ----------------
$fys = array_values(array_unique(array_merge([financial_year()], array_column(db_all('SELECT DISTINCT financial_year FROM invoices ORDER BY financial_year DESC'), 'financial_year'))));
rsort($fys);
$fy = in_array($_GET['fy'] ?? '', $fys, true) ? (string) $_GET['fy'] : '';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : '';
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$where = ['1=1'];
$params = [];
if ($fy !== '') {
    $where[] = 'i.financial_year = ?';
    $params[] = $fy;
}
if ($from !== '') {
    $where[] = 'i.issued_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'i.issued_at <= ?';
    $params[] = $to . ' 23:59:59';
}
if ($q !== '') {
    $where[] = '(i.invoice_number LIKE ? OR o.order_number LIKE ? OR i.billing_name LIKE ? OR i.billing_email LIKE ? OR i.billing_gstin LIKE ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$w = implode(' AND ', $where);
$from_sql = 'FROM invoices i JOIN orders o ON o.id = i.order_id';

if (($_GET['export'] ?? '') === 'csv') {
    require_permission('reports.export');
    $rows = db_all("SELECT i.*, o.order_number, o.status AS order_status,
                           (SELECT COALESCE(SUM(amount), 0) FROM refunds r WHERE r.order_id = o.id AND r.status = 'processed') AS refunded,
                           (SELECT GROUP_CONCAT(title SEPARATOR '; ') FROM order_items WHERE order_id = o.id) AS items
                    {$from_sql} WHERE {$w} ORDER BY i.financial_year, i.sequence_no", $params);
    $out = array_map(function ($r) {
        $place = $r['billing_state'] ?: $r['seller_state'];
        $tax = round((float) $r['cgst'] + (float) $r['sgst'] + (float) $r['igst'], 2);
        return [
            $r['invoice_number'], date('d-m-Y', strtotime($r['issued_at'])), $r['financial_year'], $r['order_number'],
            $r['billing_name'], $r['billing_gstin'] ?: '', $r['billing_email'], $r['billing_phone'],
            (state_code($place) ?? $r['seller_state_code']) . '-' . $place,
            $r['billing_gstin'] ? 'B2B' : 'B2C', (float) $r['igst'] > 0 || ($r['billing_state'] && $r['billing_state'] !== $r['seller_state']) ? 'Inter-state' : 'Intra-state',
            $r['sac_code'], (float) $r['gst_rate'], $r['items'],
            number_format((float) $r['taxable_value'], 2, '.', ''), number_format((float) $r['cgst'], 2, '.', ''), number_format((float) $r['sgst'], 2, '.', ''),
            number_format((float) $r['igst'], 2, '.', ''), number_format($tax, 2, '.', ''), number_format((float) $r['total'], 2, '.', ''),
            $r['payment_reference'], $r['payment_method'], $r['paid_at'], $r['order_status'], number_format((float) $r['refunded'], 2, '.', ''),
        ];
    }, $rows);
    csv_download('invoices-' . ($fy ?: 'all') . '-' . date('Ymd') . '.csv', [
        'Invoice number', 'Invoice date', 'Financial year', 'Order number', 'Customer name', 'Customer GSTIN', 'Customer email', 'Customer phone',
        'Place of supply', 'Supply type', 'Inter/Intra', 'SAC', 'GST rate %', 'Description', 'Taxable value', 'CGST', 'SGST', 'IGST', 'Total tax',
        'Invoice value', 'Payment reference', 'Payment method', 'Paid at', 'Order status', 'Refunded',
    ], $out);
}

// ---------------- Actions ----------------
$missingSql = "SELECT o.id FROM orders o
               WHERE o.status IN ('paid', 'partially_refunded', 'refunded') AND o.total > 0
                 AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.order_id = o.id)
                 AND EXISTS (SELECT 1 FROM payment_transactions t WHERE t.order_id = o.id AND t.verified = 1 AND t.status IN ('captured', 'refunded'))
               ORDER BY o.paid_at, o.id";
if (admin_post()) {
    $do = (string) ($_POST['_action'] ?? '');
    if ($do === 'backfill') {
        $done = 0;
        $failed = 0;
        foreach (array_column(db_all($missingSql . ' LIMIT 200'), 'id') as $oid) {
            try {
                issue_invoice((int) $oid);
                $done++;
            } catch (Throwable $e) {
                $failed++;
                error_log('[laxyam] backfill invoice for order ' . $oid . ': ' . $e->getMessage());
            }
        }
        audit_log('invoices_backfilled', 'invoices', null, ['issued' => $done, 'failed' => $failed], 'warning');
        flash($failed ? 'warning' : 'success', "{$done} invoice" . ($done === 1 ? '' : 's') . ' issued' . ($failed ? ", {$failed} could not be issued (see the error log)." : '.'));
        redirect_to('invoices.php');
    }
    if ($do === 'resend') {
        $inv = db_one('SELECT order_id FROM invoices WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        $inv = $inv ? invoice_load((int) $inv['order_id']) : null;
        if (!$inv) {
            flash('error', 'Invoice not found.');
        } elseif (!rate_limit('invoice-resend', (string) $inv['order_id'], 5, 3600)) {
            flash('error', 'This invoice was emailed several times in the last hour. Please wait before sending it again.');
        } else {
            $sent = invoice_send_email($inv);
            audit_log('invoice_emailed', 'order', $inv['order_number'], ['invoice' => $inv['invoice_number'], 'to' => $inv['billing_email'], 'sent' => $sent]);
            flash($sent ? 'success' : (mail_configured() ? 'error' : 'warning'), $sent
                ? 'Invoice ' . $inv['invoice_number'] . ' emailed to ' . $inv['billing_email'] . '.'
                : (mail_configured() ? 'The email could not be sent. Check the mail settings.' : 'SMTP is not configured, so the email was written to the mail log instead of being sent.'));
        }
        redirect_to(url_with([]));
    }
}

// ---------------- List ----------------
$page = max(1, (int) ($_GET['page'] ?? 1));
$sum = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(i.taxable_value), 0) AS taxable, COALESCE(SUM(i.cgst + i.sgst + i.igst), 0) AS gst, COALESCE(SUM(i.total), 0) AS total {$from_sql} WHERE {$w}", $params);
$rows = db_all("SELECT i.*, o.order_number, o.status AS order_status, (SELECT GROUP_CONCAT(title SEPARATOR ', ') FROM order_items WHERE order_id = o.id) AS items
                {$from_sql} WHERE {$w} ORDER BY i.issued_at DESC, i.id DESC LIMIT 25 OFFSET " . (($page - 1) * 25), $params);
$missing = (int) db_value('SELECT COUNT(*) FROM (' . $missingSql . ') m');
$filtered = $fy !== '' || $from !== '' || $to !== '' || $q !== '';

$actions = (can('reports.export') ? '<a class="btn btn-light" href="' . e(url_with(['export' => 'csv', 'page' => null])) . '">' . icon('download') . ' Export CSV (GSTR-1)</a>' : '')
    . (can('settings.manage') ? '<a class="btn btn-light" href="settings.php#company">' . icon('settings') . ' Invoice settings</a>' : '');
admin_start('Invoices', null, 'orders', ['head' => true,
    'subtitle' => 'Issued automatically for every verified payment · numbered per financial year (April–March).', 'actions' => $actions]);
?>
<?php if ($missing > 0): ?>
<div class="alert alert-warning" style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap">
  <span><?= number_format($missing) ?> paid order<?= $missing === 1 ? '' : 's' ?> from before invoicing was introduced <?= $missing === 1 ? 'has' : 'have' ?> no invoice yet.</span>
  <?= action_form('backfill', [], icon('file') . ' Issue missing invoices', 'btn btn-primary btn-sm', 'Issue invoices for ' . $missing . ' paid order' . ($missing === 1 ? '' : 's') . '? Each gets the next consecutive number.') ?>
</div>
<?php endif; ?>
<section class="grid g-4 d2-stats" aria-label="Invoice totals<?= $filtered ? ' (filtered)' : '' ?>">
  <?php foreach ([
      ['Invoices' . ($filtered ? ' (filtered)' : ''), number_format((int) $sum['n']), 'invoice', 'tone-blue', $fy !== '' ? 'FY ' . $fy : 'All financial years'],
      ['Taxable value', inr($sum['taxable']), 'receipt', 'tone-purple', 'Before GST'],
      ['GST', inr($sum['gst']), 'percent', 'tone-amber', 'CGST + SGST + IGST'],
      ['Invoice value', inr($sum['total']), 'rupee', 'tone-green', 'Including GST'],
  ] as [$label, $value, $ico, $tone, $sub]): ?>
  <div class="card stat"><span class="ico <?= $tone ?>"><?= icon($ico) ?></span><span><span class="label"><?= e($label) ?></span><span class="value"><?= e($value) ?></span><span class="sub"><?= e($sub) ?></span></span></div>
  <?php endforeach; ?>
</section>
<div class="card">
  <form class="toolbar" method="get" role="search">
    <label class="search-in"><span class="sr-only">Search invoices</span><?= icon('search') ?><input class="in" type="search" name="q" value="<?= e($q) ?>" placeholder="Invoice no., order no., name, email or GSTIN…"></label>
    <select class="in" style="width:auto" name="fy" aria-label="Financial year" data-autosubmit><option value="">All financial years</option>
      <?php foreach ($fys as $f): ?><option value="<?= e($f) ?>"<?= $fy === $f ? ' selected' : '' ?>>FY <?= e($f) ?></option><?php endforeach; ?>
    </select>
    <label class="d2-date" for="inv-from">From <input class="in" type="date" id="inv-from" name="from" value="<?= e($from) ?>"></label>
    <label class="d2-date" for="inv-to">To <input class="in" type="date" id="inv-to" name="to" value="<?= e($to) ?>"></label>
    <button class="btn btn-light"><?= icon('search') ?> Filter</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="invoices.php">Reset</a><?php endif; ?>
  </form>
  <?php if (!$rows): ?>
    <div class="empty"><span class="empty-ic"><?= icon($filtered ? 'search' : 'invoice') ?></span>
      <strong><?= $filtered ? 'No invoices match these filters' : 'No invoices yet' ?></strong>
      <p><?= $filtered ? 'Try another financial year, date range or search.' : 'An invoice is issued automatically as soon as a payment is verified.' ?></p>
      <?php if ($filtered): ?><a class="btn btn-light btn-sm" href="invoices.php">Clear filters</a><?php endif; ?>
    </div>
  <?php else: ?>
  <?php // Compact columns (amounts in one cell) + shared icon row actions, so View / PDF / Email stay visible at 1280px without sideways scrolling. ?>
  <div class="table-wrap"><table class="t stack"><thead><tr><th>Invoice</th><th>Date</th><th>Billed to</th><th>Course</th><th>Amount</th><th>Order status</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $gst = (float) $r['cgst'] + (float) $r['sgst'] + (float) $r['igst']; ?>
    <tr>
      <td data-label="Invoice"><div><strong class="d2-nowrap"><?= e($r['invoice_number']) ?></strong><span class="d2-sub"><a href="orders.php?order=<?= e(urlencode($r['order_number'])) ?>"><?= e($r['order_number']) ?></a></span></div></td>
      <td data-label="Date" class="d2-nowrap"><?= e(fmt_dt($r['issued_at'], 'j M Y')) ?></td>
      <td data-label="Billed to"><div><strong><?= e($r['billing_name']) ?></strong><span class="d2-sub" style="word-break:break-all"><?= e($r['billing_email'] ?? '') ?></span><?= $r['billing_gstin'] ? '<span class="d2-sub">GSTIN ' . e($r['billing_gstin']) . '</span>' : '' ?></div></td>
      <td data-label="Course"><span class="d2-clip" title="<?= e((string) $r['items']) ?>"><?= e((string) $r['items']) ?></span></td>
      <td data-label="Amount"><div><strong class="d2-nowrap"><?= e(inr($r['total'])) ?></strong>
        <span class="d2-sub">Taxable <span class="d2-nowrap"><?= e(inr($r['taxable_value'])) ?></span></span>
        <span class="d2-sub"><?= $gst > 0 ? 'GST <span class="d2-nowrap">' . e(inr($gst)) . '</span> · <span class="d2-nowrap">' . ((float) $r['igst'] > 0 ? 'IGST' : 'CGST + SGST') . ' ' . e(rtrim(rtrim(number_format((float) $r['gst_rate'], 2), '0'), '.')) . '%</span>' : 'No GST' ?></span></div></td>
      <td data-label="Order status"><?= badge($r['order_status']) ?></td>
      <td class="actions"><?= row_actions([
          ['label' => 'View invoice ' . $r['invoice_number'], 'href' => invoice_url($r['order_number'], 'html'), 'icon' => 'eye', 'blank' => true],
          ['label' => 'Download ' . $r['invoice_number'] . ' (PDF)', 'href' => invoice_url($r['order_number']), 'icon' => 'download'],
          ['label' => 'Email invoice ' . $r['invoice_number'], 'post' => ['resend', ['id' => $r['id']]], 'icon' => 'mail',
           'confirm' => 'Email invoice ' . $r['invoice_number'] . ' to ' . ($r['billing_email'] ?? 'the student') . '?'],
      ]) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php render_pager((int) $sum['n'], $page, 25); ?>
</div>
<?php admin_end();
