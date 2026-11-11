<?php
// Admin guide, keyboard shortcuts, troubleshooting, backups and system information.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/payments.php';

admin_start('Help & Documentation', 'dashboard.view', 'help', ['head' => true, 'subtitle' => 'How to run Laxyam Academy day to day.']);
$db = db_one('SELECT VERSION() AS v');
// Wording follows the active gateway (PAYMENT_PROVIDER in .env).
$gwCf = payment_provider() === 'cashfree';
$gwName = in_array(payment_provider(), ['razorpay', 'cashfree'], true) ? payment_provider_label() : 'payment gateway';
$sections = [
    'start' => ['Getting started', [
        'Fill in contact details, social links and office hours in <a href="settings.php">System Settings</a>.',
        'Replace demo content (marked DEMO): faculty, courses, prices, mock tests, job alerts and blog posts.',
        'Create staff accounts with the least access they need in <a href="roles.php">Staff & Roles</a>.',
        'Review the draft policy pages in <a href="cms.php?tab=pages">Pages & Policies</a> with a legal adviser.',
        'Add the ' . e($gwName) . ' and SMTP credentials in <a href="keys.php">Keys &amp; Credentials</a>, then test them in <a href="integrations.php">Integrations</a>.',
    ]],
    'features' => ['Feature guide', [
        '<strong>Courses:</strong> create the course, then add sections and lessons in Curriculum. Upload videos/PDFs privately; mark a lesson as Free preview to show it publicly.',
        '<strong>Live classes:</strong> add the Zoom/Meet/YouTube link. Students see the Join button 15 minutes before start; only eligible students receive the link.',
        '<strong>Mock tests:</strong> save the test as Draft, add questions, then publish. Questions lock once students attempt the test.',
        '<strong>Payments:</strong> orders become Paid only after the gateway confirms the payment server to server ('
            . ($gwCf ? 'Cashfree API check, also after a signed webhook' : 'Razorpay signature or signed webhook') . '). Record refunds on the order page after processing them in the ' . e($gwName) . ' dashboard.',
        '<strong>Certificates:</strong> students who complete 100% of a course appear in Certificates, ready to issue.',
        '<strong>Logo & favicon:</strong> replace <code>assets/logo.png</code>, <code>assets/logo.webp</code> and <code>assets/icons/*</code> on the server.',
    ]],
    'shortcuts' => ['Keyboard shortcuts', [
        '<kbd>/</kbd> - focus the global search',
        '<kbd>↑</kbd> <kbd>↓</kbd> then <kbd>Enter</kbd> - choose a search suggestion',
        '<kbd>Esc</kbd> - close menus, suggestions and the mobile navigation',
        '<kbd>Tab</kbd> - move between controls (focus is always visible)',
    ]],
    'troubleshooting' => ['Troubleshooting', [
        '<strong>Website shows "build missing":</strong> run <code>npm run build</code> on the server/development machine and upload the <code>dist</code> folder.',
        '<strong>Emails not arriving:</strong> check SMTP in Integrations → Test connection; check spam folders; until SMTP is set, emails are written to <code>uploads/private/logs/mail.log</code>.',
        '<strong>Paid but no access:</strong> open the order; if it is Pending, check Webhook Events. Never mark orders paid manually - verify in the ' . e($gwName) . ' dashboard.',
        '<strong>Student locked out:</strong> after 5 failed logins they must wait 15 minutes or use Forgot password.',
        '<strong>Reminders not sent:</strong> set up the cron job (<code>php database/cron.php</code> every 15 minutes) or use Notifications → Send due reminders. Without cron, jobs run from website traffic at most every 15 minutes - see the Go-Live Checklist.',
    ]],
    'backup' => ['Backup & restore', [
        'Back up the database daily: <code>mysqldump -u USER -p laxyam_academy &gt; backup-YYYYMMDD.sql</code>',
        'Back up the <code>uploads/</code> folder (including <code>uploads/private</code> - its <code>.config-key</code> file is needed to read the keys saved in Keys &amp; Credentials) and the <code>.env</code> file.',
        'Store backups off the web server (never in the public folder) and test a restore regularly.',
        'Restore: create an empty database, then <code>mysql -u USER -p laxyam_academy &lt; backup.sql</code>, and copy back uploads/.env.',
        'One-click database download: System Settings → <a href="backup.php">Backup &amp; Restore</a> (Super Admins). The Go-Live Checklist warns when the last backup is older than 7 days.',
    ]],
    'integrations' => ['Configuring integrations', [
        'Set API keys and passwords in <a href="keys.php">Keys &amp; Credentials</a> (stored encrypted; a value saved there is used instead of the same name in <code>.env</code>). The names below also work as <code>.env</code> lines.',
        'Gateway: <code>PAYMENT_PROVIDER=cashfree|razorpay</code> (active: ' . e($gwName) . '), <code>PAYMENT_MODE=sandbox|live</code>',
        'Cashfree: <code>CASHFREE_APP_ID</code>, <code>CASHFREE_SECRET_KEY</code> (webhooks are signed with the client secret). Webhook URL: <code>' . e(app_url('api/webhooks.php?provider=cashfree')) . '</code> with Success, Failed and User Dropped payment events',
        'Razorpay: <code>RAZORPAY_KEY_ID</code>, <code>RAZORPAY_KEY_SECRET</code>, <code>RAZORPAY_WEBHOOK_SECRET</code>. Webhook URL: <code>' . e(app_url('api/webhooks.php')) . '</code> with events payment.captured, payment.failed, order.paid',
        'SMTP: <code>SMTP_HOST</code>, <code>SMTP_PORT</code>, <code>SMTP_USER</code>, <code>SMTP_PASS</code>, <code>SMTP_SECURE=tls|ssl</code>, <code>MAIL_FROM</code>',
        'Secrets are never shown in full in this panel. Keys saved in Keys &amp; Credentials are stored encrypted in the database; the encryption key is <code>uploads/private/.config-key</code> (or <code>CONFIG_KEY</code> in .env) - back it up together with the database.',
    ]],
];
?>
<div class="help-grid">
  <nav class="card card-pad" aria-label="Help sections" style="position:sticky;top:86px">
    <?php foreach ($sections as $k => [$t]): ?><a href="#<?= $k ?>" style="display:block;padding:6px 0"><?= e($t) ?></a><?php endforeach; ?>
    <a href="#system" style="display:block;padding:6px 0">System information</a>
    <a href="#contact" style="display:block;padding:6px 0">Support contact</a>
  </nav>
  <div>
    <?php foreach ($sections as $k => [$t, $items]): ?>
    <section class="card card-pad" id="<?= $k ?>" style="margin-bottom:18px;scroll-margin-top:86px">
      <h2 style="font-size:17px;margin-bottom:10px"><?= e($t) ?></h2>
      <ul style="margin:0;padding-left:18px;line-height:1.9"><?php foreach ($items as $i): ?><li><?= $i ?></li><?php endforeach; ?></ul>
    </section>
    <?php endforeach; ?>
    <section class="card card-pad" id="system" style="margin-bottom:18px;scroll-margin-top:86px">
      <h2 style="font-size:17px;margin-bottom:10px">System information</h2>
      <dl class="kv">
        <dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd>
        <dt>Database</dt><dd><?= e($db['v']) ?> · schema v<?= (int) db_value('SELECT MAX(version) FROM schema_migrations') ?></dd>
        <dt>Environment</dt><dd><?= e((string) config('APP_ENV')) ?> · <?= e(date_default_timezone_get()) ?></dd>
        <dt>Site URL</dt><dd><?= e(app_url('')) ?></dd>
        <dt>Upload limit</dt><dd><?= e(ini_get('upload_max_filesize')) ?> (PHP) · <?= (int) setting('upload_max_mb', '20') ?> MB (app)</dd>
        <dt>Front-end build</dt><dd><?= is_file(APP_ROOT . '/dist/index.html') ? 'Built ' . e(date('j M Y, g:i A', filemtime(APP_ROOT . '/dist/index.html'))) : '<span class="badge b-red">Missing - run npm run build</span>' ?></dd>
      </dl>
    </section>
    <section class="card card-pad" id="contact" style="scroll-margin-top:86px">
      <h2 style="font-size:17px;margin-bottom:10px">Support contact</h2>
      <p class="muted" style="margin:0">For technical issues contact your website developer. Include the time of the problem and what you were doing; Security logs show related events.</p>
    </section>
  </div>
</div>
<?php admin_end();
