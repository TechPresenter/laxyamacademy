<?php
// Email delivery log: every email the platform sends (templates, sign-up/verification/reset,
// announcements, staff alerts) with its status, retries, a safe preview, resend and CSV export.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/notify.php';

$me = require_staff_page();
require_permission('notifications.send');

/** Emails carrying one-time links: the preview hides the token and they cannot be re-sent (the student requests a new link). */
const EMAIL_SENSITIVE = ['password_reset', 'email_verification', 'login_otp'];
const EMAIL_STATUS_FILTERS = [
    'sent' => 'Sent', 'skipped' => 'Logged only (no SMTP)', 'queued' => 'Queued / sending',
    'retrying' => 'Retry scheduled', 'failed' => 'Failed (gave up)',
];

try {
    db_value('SELECT 1 FROM email_log LIMIT 1');
} catch (Throwable) {
    admin_start('Email Logs', null, 'notifications', ['head' => true]);
    echo '<div class="alert alert-warning">The email log table is missing. Apply the latest update in System → Database Updates (migration 008).</div>';
    admin_end();
    exit;
}

function email_status_badge(array $r): string
{
    return match (true) {
        $r['status'] === 'sent' => '<span class="badge b-green">Sent</span>',
        $r['status'] === 'skipped' => '<span class="badge b-gray" title="' . e(EMAIL_SKIPPED_NOTE) . '">Logged only</span>',
        $r['status'] === 'failed' && $r['next_attempt_at'] !== null => '<span class="badge b-amber">Retry scheduled</span>',
        // The server received the whole message but never confirmed it: usually delivered, so never re-sent automatically.
        $r['status'] === 'failed' && ($r['transport'] ?? '') === 'smtp-unconfirmed' => '<span class="badge b-amber" title="The mail server received the whole message but did not confirm it - it was most likely delivered">Unconfirmed · probably delivered</span>',
        $r['status'] === 'failed' => '<span class="badge b-red">Failed</span>',
        $r['status'] === 'sending' => '<span class="badge b-blue">Sending</span>',
        default => '<span class="badge b-amber">Queued</span>',
    };
}

/** Readable template names, including the keys that are not notification_templates rows. */
function email_template_names(): array
{
    return array_column(db_all('SELECT template_key, name FROM notification_templates'), 'name', 'template_key') + [
        'email_verification' => 'Email verification', 'password_reset' => 'Password reset link',
        'announcement' => 'Announcement', 'staff_alert' => 'Staff alert',
        'invoice' => 'Invoice', 'smtp_test' => 'SMTP test email', 'admin_password_reset' => 'Staff password reset link',
        'staff_invite' => 'Staff invitation', 'staff_forced' => 'Staff password reset (required)', 'staff_reset' => 'Staff password reset link',
        'class_rescheduled' => 'Live class rescheduled', 'class_cancelled' => 'Live class cancelled',
    ];
}

/** Label for a template key: its name, or the key made readable for anything not listed above. */
function email_template_label(?string $key, array $names): string
{
    if ($key === null || $key === '') {
        return 'Manual';
    }
    return $names[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

/** Hide one-time tokens and signatures in a stored email before staff see it. */
function email_redact(string $html): string
{
    return preg_replace('/([?&](?:amp;)?(?:token|sig)=)[^"&\s<]+/i', '$1[hidden]', $html);
}

/** WHERE clause + params from the current filters (?status, template, from, to, q). */
function email_filters(): array
{
    $w = [];
    $p = [];
    $status = (string) ($_GET['status'] ?? '');
    $sql = match ($status) {
        'sent' => "l.status = 'sent'",
        'skipped' => "l.status = 'skipped'",
        'queued' => "l.status IN ('queued', 'sending')",
        'retrying' => "l.status = 'failed' AND l.next_attempt_at IS NOT NULL",
        'failed' => "l.status = 'failed' AND l.next_attempt_at IS NULL",
        default => null,
    };
    if ($sql) {
        $w[] = $sql;
    }
    $tpl = (string) ($_GET['template'] ?? '');
    if ($tpl === '_none') {
        $w[] = 'l.template_key IS NULL';
    } elseif (preg_match('/^[a-z0-9_]{1,60}$/', $tpl)) {
        $w[] = 'l.template_key = ?';
        $p[] = $tpl;
    }
    foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
        $d = (string) ($_GET[$k] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d)) {
            $w[] = "l.created_at {$op} ?";
            $p[] = $k === 'to' ? date('Y-m-d', strtotime($d . ' +1 day')) : $d;
        }
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
        $w[] = '(l.to_email LIKE ? OR l.to_name LIKE ? OR l.subject LIKE ?)';
        array_push($p, $like, $like, $like);
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

/** Back to the list with the same filters (only our own page). */
function email_back(): string
{
    $b = (string) ($_POST['back'] ?? '');
    return preg_match('#^email-logs\.php(\?[^\s]*)?$#', $b) ? $b : 'email-logs.php';
}

// ------------------------------------------------------------------
// Actions
// ------------------------------------------------------------------
if (admin_post()) {
    $act = (string) ($_POST['_action'] ?? '');
    if (isset($_POST['retry'])) {
        $id = (int) $_POST['retry'];
        $row = db_one('SELECT id, status FROM email_log WHERE id = ?', [$id]);
        if (!$row || !in_array($row['status'], ['failed', 'queued'], true)) {
            flash('error', 'Only failed or queued emails can be retried.');
        } else {
            $st = email_send_logged($id, true);
            audit_log('email_retried', 'email_log', $id, ['status' => $st]);
            flash($st === 'sent' ? 'success' : ($st === 'skipped' ? 'info' : 'error'), match ($st) {
                'sent' => "Email #{$id} delivered.",
                'skipped' => "SMTP is not configured - email #{$id} was written to the mail log.",
                null => "Email #{$id} is already being sent.",
                default => "Email #{$id} failed again: " . (string) db_value('SELECT last_error FROM email_log WHERE id = ?', [$id]),
            });
        }
        redirect_to(email_back());
    }
    if (isset($_POST['resend'])) {
        $id = (int) $_POST['resend'];
        $row = db_one('SELECT * FROM email_log WHERE id = ?', [$id]);
        if (!$row) {
            flash('error', 'Email not found.');
        } elseif (in_array($row['template_key'], EMAIL_SENSITIVE, true)) {
            flash('error', 'Verification links, password-reset links and login codes expire - ask the student to request a new one from the website.');
        } else {
            $ok = send_mail((string) $row['to_email'], (string) $row['subject'], (string) $row['body_html'], [
                'template_key' => $row['template_key'], 'user_id' => $row['user_id'], 'to_name' => $row['to_name'],
                'related' => $row['related_type'] ? [$row['related_type'], $row['related_id']] : null,
            ]);
            $newId = last_email_log_id();
            audit_log('email_resent', 'email_log', $id, ['new_id' => $newId]);
            $new = $newId ? db_one('SELECT status, transport, next_attempt_at FROM email_log WHERE id = ?', [$newId]) : null;
            $st = $new ? (string) $new['status'] : ($ok ? 'sent' : 'failed');
            flash($ok ? 'success' : ($st === 'skipped' ? 'info' : 'error'), match (true) {
                $st === 'sent' => "Re-sent as email #{$newId}.",
                $st === 'skipped' => "SMTP is not configured - the copy (#{$newId}) was written to the mail log.",
                ($new['transport'] ?? '') === 'smtp-unconfirmed' => "The mail server received the copy (#{$newId}) but did not confirm it - it was probably delivered, so it is not retried automatically.",
                !empty($new['next_attempt_at']) => "The copy (#{$newId}) failed and will be retried automatically.",
                default => "The copy (#{$newId}) failed - see the error below.",
            });
            if ($newId) {
                redirect_to('email-logs.php?id=' . $newId);
            }
        }
        redirect_to(email_back());
    }
    if ($act === 'bulk_retry') {
        $ids = array_slice(array_unique(array_map('intval', (array) ($_POST['ids'] ?? []))), 0, 100);
        $tally = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'ignored' => 0];
        foreach ($ids as $id) {
            // Unconfirmed (probably delivered) emails are re-sent only one at a time with "Retry now", never in bulk.
            $cur = db_one('SELECT status, transport FROM email_log WHERE id = ?', [$id]);
            $st = $cur && in_array($cur['status'], ['failed', 'queued'], true) && $cur['transport'] !== 'smtp-unconfirmed' ? email_send_logged($id, true) : null;
            $tally[$st ?? 'ignored']++;
        }
        audit_log('email_bulk_retry', 'email_log', null, $tally);
        flash($tally['failed'] ? 'warning' : 'success', "Retried: {$tally['sent']} sent, {$tally['failed']} failed" . ($tally['skipped'] ? ", {$tally['skipped']} written to the mail log" : '') . ($tally['ignored'] ? ", {$tally['ignored']} skipped (not failed/queued)" : '') . '.');
        redirect_to(email_back());
    }
    if ($act === 'process') {
        $r = process_email_queue(50);
        audit_log('email_queue_processed', 'email_log', null, $r);
        flash('success', $r['processed'] ? "Processed {$r['processed']} queued email" . ($r['processed'] === 1 ? '' : 's') . ": {$r['sent']} sent, {$r['failed']} failed" . ($r['skipped'] ? ", {$r['skipped']} written to the mail log" : '') . '.' : 'Nothing is due in the queue right now.');
        redirect_to(email_back());
    }
    redirect_to('email-logs.php');
}

[$where, $params] = email_filters();
$names = email_template_names();

// ------------------------------------------------------------------
// CSV export (current filters)
// ------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    $rows = db_all("SELECT l.id, l.created_at, l.template_key, l.to_email, l.to_name, l.subject, l.status, l.attempts, l.last_error, l.transport, l.sent_at, l.next_attempt_at, l.related_type, l.related_id
                    FROM email_log l {$where} ORDER BY l.id DESC LIMIT 20000", $params);
    csv_download('email-log-' . date('Y-m-d') . '.csv',
        ['ID', 'Created', 'Template', 'To', 'Name', 'Subject', 'Status', 'Attempts', 'Last error', 'Transport', 'Sent at', 'Next attempt', 'Related'],
        array_map(fn($r) => [$r['id'], $r['created_at'], email_template_label($r['template_key'], $names), $r['to_email'], $r['to_name'], $r['subject'],
            $r['status'] === 'failed' && $r['next_attempt_at'] ? 'retry scheduled' : $r['status'], $r['attempts'], $r['last_error'], $r['transport'], $r['sent_at'], $r['next_attempt_at'],
            $r['related_type'] ? $r['related_type'] . ' #' . $r['related_id'] : ''], $rows));
}

$back = 'email-logs.php' . (($qs = http_build_query(array_diff_key($_GET, ['id' => 1, 'export' => 1]))) !== '' ? '?' . $qs : '');
$actions = action_form('process', ['back' => $back], icon('history') . ' Process queue now', 'btn btn-light')
    . '<a class="btn btn-light" href="' . e(url_with(['export' => 'csv', 'page' => null, 'id' => null])) . '">' . icon('download') . ' Export CSV</a>'
    . '<a class="btn btn-light" href="templates.php">' . icon('file') . ' Templates</a>';

// ------------------------------------------------------------------
// Detail view
// ------------------------------------------------------------------
$id = (int) ($_GET['id'] ?? 0);
if ($id) {
    $r = db_one('SELECT l.*, u.name AS user_name FROM email_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.id = ?', [$id]);
    if (!$r) {
        flash('error', 'Email not found.');
        redirect_to('email-logs.php');
    }
    $sensitive = in_array($r['template_key'], EMAIL_SENSITIVE, true);
    $doc = email_redact((string) $r['body_html']);
    // Shown in an iframe sandbox WITHOUT allow-scripts (the stored HTML can never run code);
    // allow-same-origin only lets the logo load, and links open in a new tab.
    $doc = preg_replace('/<head>/i', '<head><base target="_blank">', $doc, 1);
    admin_start('Email #' . $id, null, 'notifications', ['head' => true, 'crumbs' => '<a href="email-logs.php">Email Logs</a> / #' . $id, 'subtitle' => $r['subject'], 'actions' => $actions]);
    ?>
    <style>
      .el-detail { display: grid; gap: 18px; grid-template-columns: minmax(0, 360px) minmax(0, 1fr); align-items: start; }
      @media (max-width: 1000px) { .el-detail { grid-template-columns: 1fr; } }
      .el-detail dl.kv dd { word-break: break-word; }
      .el-frame { width: 100%; height: 760px; border: 0; display: block; background: #EEF3FA; border-radius: 0 0 var(--radius) var(--radius); }
      .el-view { display: inline-flex; gap: 4px; padding: 3px; background: #eef2f7; border-radius: 10px; }
      .el-view button { border: 0; background: transparent; padding: 5px 12px; border-radius: 8px; font-weight: 600; font-size: 12px; color: var(--muted); cursor: pointer; }
      .el-view button[aria-pressed="true"] { background: #fff; color: var(--primary); box-shadow: 0 1px 2px rgb(0 0 0 / .08); }
    </style>
    <form id="rowact" method="post"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e('email-logs.php?id=' . $id) ?>"></form>
    <div class="el-detail">
      <div class="card">
        <div class="card-head"><h2>Delivery</h2><?= email_status_badge($r) ?></div>
        <div class="card-pad">
          <dl class="kv">
            <dt>To</dt><dd><?= e($r['to_name'] ? $r['to_name'] . ' <' . $r['to_email'] . '>' : $r['to_email']) ?></dd>
            <?php if ($r['user_id']): ?><dt>Account</dt><dd><?= can('users.view') ? '<a href="users.php?id=' . (int) $r['user_id'] . '">' . e($r['user_name'] ?? ('#' . $r['user_id'])) . '</a>' : e($r['user_name'] ?? '') ?></dd><?php endif; ?>
            <dt>Template</dt><dd><?= $r['template_key'] ? e(email_template_label($r['template_key'], $names)) . ' <code class="muted">' . e($r['template_key']) . '</code>' : 'Manual email' ?></dd>
            <dt>Subject</dt><dd><?= e($r['subject']) ?></dd>
            <dt>Attempts</dt><dd><?= (int) $r['attempts'] ?> of <?= setting_int('email_max_attempts', 5, 1, 10) ?><?= $r['transport'] ? ' · via ' . e(['log' => 'mail log', 'smtp-unconfirmed' => 'SMTP (not confirmed by the server)', 'smtp-data' => 'SMTP (handing over)'][$r['transport']] ?? strtoupper($r['transport'])) : '' ?></dd>
            <?php if ($r['last_error']): ?><dt>Last error</dt><dd style="color:<?= $r['status'] === 'skipped' ? 'var(--muted)' : 'var(--red)' ?>"><?= e($r['last_error']) ?></dd><?php endif; ?>
            <?php if ($r['next_attempt_at']): ?><dt>Next attempt</dt><dd><?= e(fmt_dt($r['next_attempt_at'])) ?></dd><?php endif; ?>
            <?php if ($r['related_type']): ?><dt>Related</dt><dd><?= e(ucfirst($r['related_type'])) ?> #<?= (int) $r['related_id'] ?></dd><?php endif; ?>
            <dt>Created</dt><dd><?= e(fmt_dt($r['created_at'])) ?></dd>
            <dt>Sent</dt><dd><?= e(fmt_dt($r['sent_at'])) ?></dd>
          </dl>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px">
            <?php if (in_array($r['status'], ['failed', 'queued'], true)): ?><button class="btn btn-primary btn-sm" form="rowact" name="retry" value="<?= $id ?>"><?= icon('history') ?> Retry now</button><?php endif; ?>
            <?php if (!$sensitive): ?><button class="btn btn-light btn-sm" form="rowact" name="resend" value="<?= $id ?>" data-confirm="Send a new copy of this email to <?= e($r['to_email']) ?>?"><?= icon('mail') ?> Resend</button><?php endif; ?>
            <a class="btn btn-light btn-sm" href="email-logs.php"><?= icon('arrow') ?> All emails</a>
          </div>
          <?php if ($sensitive): ?><p class="muted" style="font-size:12px;margin-top:12px">One-time links are hidden in this preview and this email cannot be re-sent; the student can request a fresh link from the website.</p><?php endif; ?>
        </div>
      </div>
      <div class="card">
        <div class="card-head"><h2>Preview</h2>
          <div class="el-view" role="group" aria-label="Preview width"><button type="button" aria-pressed="true" data-w="100%">Desktop</button><button type="button" aria-pressed="false" data-w="390px">Mobile</button></div>
        </div>
        <div style="display:flex;justify-content:center;background:#EEF3FA;border-radius:0 0 var(--radius) var(--radius)">
          <iframe class="el-frame" id="el-frame" title="Email preview" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" srcdoc="<?= e($doc) ?>"></iframe>
        </div>
      </div>
    </div>
    <script>
    document.querySelectorAll('.el-view button').forEach((b) => b.addEventListener('click', () => {
      document.querySelectorAll('.el-view button').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
      document.getElementById('el-frame').style.width = b.dataset.w;
    }));
    </script>
    <?php
    admin_end();
    exit;
}

// ------------------------------------------------------------------
// List view
// ------------------------------------------------------------------
$stats = db_one("SELECT SUM(status = 'sent') AS sent, SUM(status = 'skipped') AS skipped,
                        SUM(status = 'failed' AND next_attempt_at IS NULL) AS failed,
                        SUM(status IN ('queued', 'sending') OR (status = 'failed' AND next_attempt_at IS NOT NULL)) AS pending,
                        COUNT(*) AS total
                 FROM email_log WHERE created_at >= NOW() - INTERVAL 7 DAY");
$stats = array_map('intval', $stats ?: []);
$dueNow = (int) db_value("SELECT COUNT(*) FROM email_log WHERE status IN ('queued', 'failed') AND next_attempt_at IS NOT NULL AND next_attempt_at <= NOW()");
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 25;
$total = (int) db_value("SELECT COUNT(*) FROM email_log l {$where}", $params);
$rows = db_all("SELECT l.id, l.template_key, l.user_id, l.to_email, l.to_name, l.subject, l.status, l.attempts, l.last_error, l.transport, l.next_attempt_at, l.sent_at, l.created_at
                FROM email_log l {$where} ORDER BY l.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);
$usedKeys = db_all('SELECT DISTINCT template_key FROM email_log WHERE template_key IS NOT NULL ORDER BY template_key');
$tplOptions = [];
foreach (array_unique(array_merge(array_keys($names), array_column($usedKeys, 'template_key'))) as $k) {
    $tplOptions[$k] = email_template_label($k, $names);
}
asort($tplOptions);
$filtered = $where !== '';

admin_start('Email Logs', null, 'notifications', ['head' => true, 'subtitle' => 'Every email the platform sends, with delivery status and automatic retries.', 'actions' => $actions]);
?>
<style>
  .el-filters { display: grid; gap: 10px; grid-template-columns: minmax(180px, 1.6fr) repeat(2, minmax(140px, 1fr)) repeat(2, minmax(130px, .8fr)) auto; align-items: end; padding: 16px 20px; border-bottom: 1px solid var(--line); }
  .el-filters label { font-size: 12px; font-weight: 600; color: var(--muted); display: block; margin-bottom: 4px; }
  @media (max-width: 1100px) { .el-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  @media (max-width: 560px) { .el-filters { grid-template-columns: 1fr; } }
  .el-subj { min-width: 220px; max-width: 380px; }
  .el-subj a { color: var(--ink); font-weight: 600; }
  .el-err { display: block; max-width: 260px; font-size: 12px; color: var(--red); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .el-chip { display: inline-block; margin-top: 3px; padding: 1px 8px; border-radius: 99px; background: #eef2f7; color: var(--muted); font-size: 11px; font-weight: 600; }
</style>
<?php if (!mail_configured()): ?>
<div class="alert alert-info d2-wrap"><?= icon('info') ?><span>SMTP is not configured, so emails are written to <code>uploads/private/logs/mail.log</code> and marked “Logged only”. Add the SMTP details in <a href="keys.php#email">Keys &amp; Credentials → Email</a> to deliver them.</span></div>
<?php endif; ?>
<section class="grid g-4 d2-stats" aria-label="Last 7 days">
  <?php foreach ([
      ['Sent', $stats['sent'] ?? 0, 'check', 'tone-green', 'sent', 'Accepted by the mail server'],
      ['Logged only', $stats['skipped'] ?? 0, 'file', 'tone-purple', 'skipped', 'Written to mail.log (no SMTP)'],
      ['Failed', $stats['failed'] ?? 0, 'alert', 'tone-red', 'failed', 'Gave up after all retries'],
      ['Queued', $stats['pending'] ?? 0, 'clock', 'tone-amber', 'queued', $dueNow ? "{$dueNow} due now" : 'Waiting or retry scheduled'],
  ] as [$label, $value, $ico, $tone, $st, $sub]): ?>
  <a class="card stat" href="<?= e('email-logs.php?' . http_build_query(['status' => $st, 'from' => date('Y-m-d', strtotime('-6 days'))])) ?>">
    <span class="ico <?= $tone ?>"><?= icon($ico) ?></span>
    <span><span class="label"><?= e($label) ?> · 7 days</span><span class="value"><?= number_format($value) ?></span><span class="sub"><?= e($sub) ?></span></span>
  </a>
  <?php endforeach; ?>
</section>
<div class="card" data-bulk>
  <form class="el-filters" method="get" role="search" aria-label="Filter emails">
    <div><label for="f-q">Search</label><input class="in" id="f-q" type="search" name="q" value="<?= e((string) ($_GET['q'] ?? '')) ?>" placeholder="Email, name or subject"></div>
    <div><label for="f-st">Status</label><select class="in" id="f-st" name="status"><option value="">All statuses</option><?php foreach (EMAIL_STATUS_FILTERS as $k => $l): ?><option value="<?= $k ?>"<?= ($_GET['status'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label for="f-t">Template</label><select class="in" id="f-t" name="template"><option value="">All templates</option><?php foreach ($tplOptions as $k => $l): ?><option value="<?= e($k) ?>"<?= ($_GET['template'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?><option value="_none"<?= ($_GET['template'] ?? '') === '_none' ? ' selected' : '' ?>>Manual / other</option></select></div>
    <div><label for="f-from">From</label><input class="in" id="f-from" type="date" name="from" value="<?= e((string) ($_GET['from'] ?? '')) ?>"></div>
    <div><label for="f-to">To</label><input class="in" id="f-to" type="date" name="to" value="<?= e((string) ($_GET['to'] ?? '')) ?>"></div>
    <div style="display:flex;gap:8px"><button class="btn btn-primary"><?= icon('search') ?> Filter</button><?php if ($filtered): ?><a class="btn btn-ghost" href="email-logs.php">Reset</a><?php endif; ?></div>
  </form>
  <form id="rowact" method="post"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"></form>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="bulkbar"><strong><span data-bulk-count>0</span> selected</strong><button class="btn btn-primary btn-sm" name="_action" value="bulk_retry" data-confirm="Retry the selected failed or queued emails now?"><?= icon('history') ?> Retry selected</button><span class="muted" style="font-size:12px">Only failed or queued emails are retried.</span></div>
    <?php if ($rows): ?>
    <div class="table-wrap"><table class="t stack">
      <thead><tr><th style="width:34px"><input type="checkbox" data-check-all aria-label="Select all on this page"></th><th>Recipient</th><th>Subject</th><th>Status</th><th>Tries</th><th>Created</th><th><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $retryable = in_array($r['status'], ['failed', 'queued'], true) && $r['last_error'] !== 'Invalid recipient address'; ?>
        <tr>
          <td class="sel"><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" data-row-check aria-label="Select email #<?= (int) $r['id'] ?>"></td>
          <td data-label="Recipient"><div><strong><?= e($r['to_name'] ?: $r['to_email']) ?></strong><?php if ($r['to_name']): ?><span class="d2-sub"><?= e($r['to_email']) ?></span><?php endif; ?></div></td>
          <td data-label="Subject" class="el-subj"><div><a href="?id=<?= (int) $r['id'] ?>"><?= e($r['subject']) ?></a><br><span class="el-chip"><?= e(email_template_label($r['template_key'], $names)) ?></span></div></td>
          <td data-label="Status"><div><?= email_status_badge($r) ?>
            <?php if ($r['status'] === 'failed' && $r['last_error']): ?><span class="el-err" title="<?= e($r['last_error']) ?>"><?= e($r['last_error']) ?></span><?php endif; ?>
            <?php if ($r['next_attempt_at'] && $r['status'] !== 'sent'): ?><span class="muted" style="font-size:12px;display:block">next <?= e(fmt_dt($r['next_attempt_at'], 'j M, g:i A')) ?></span><?php endif; ?></div></td>
          <td data-label="Tries"><?= (int) $r['attempts'] ?></td>
          <td data-label="Created" class="d2-nowrap"><div><?= e(fmt_dt($r['created_at'], 'j M Y, g:i A')) ?><?php if ($r['sent_at']): ?><span class="d2-sub">sent <?= e(fmt_dt($r['sent_at'], 'g:i A')) ?></span><?php endif; ?></div></td>
          <td class="actions">
            <a class="btn btn-light btn-xs" href="?id=<?= (int) $r['id'] ?>" aria-label="View email #<?= (int) $r['id'] ?>"><?= icon('eye') ?> View</a>
            <?php if ($retryable): ?><button class="btn btn-primary btn-xs" form="rowact" name="retry" value="<?= (int) $r['id'] ?>" aria-label="Retry email #<?= (int) $r['id'] ?>"><?= icon('history') ?> Retry</button><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?>
    <div class="empty"><span class="empty-ic"><?= icon($filtered ? 'search' : 'mail') ?></span>
      <strong><?= $filtered ? 'No emails match these filters' : 'No emails yet' ?></strong>
      <p><?= $filtered ? 'Try another status, template or date range.' : 'Every email the platform sends is listed here, with its delivery status.' ?></p>
      <?php if ($filtered): ?><a class="btn btn-light btn-sm" href="email-logs.php">Clear filters</a><?php endif; ?>
    </div>
    <?php endif; ?>
  </form>
  <?php render_pager($total, $page, $per); ?>
</div>
<?php admin_end();
