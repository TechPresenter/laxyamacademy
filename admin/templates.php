<?php
// Email & notification templates: grouped editor with placeholder chips, a live preview of the
// branded email, test send with sample values and channel toggles; default student preferences;
// WhatsApp Cloud API mapping (settings.manage only).
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/notify.php';

$me = require_staff_page();
require_permission('notifications.send');
$canWhatsapp = can('settings.manage'); // enabling WhatsApp sending is a settings change
$tabs = ['templates' => 'Templates', 'defaults' => 'Default Preferences'] + ($canWhatsapp ? ['whatsapp' => 'WhatsApp'] : []);
$tab = current_tab($tabs);
$keys = ['course_updates' => 'Course updates', 'test_results' => 'Mock test results', 'offers' => 'Offers & discounts', 'announcements' => 'Important announcements', 'study_tips' => 'Weekly study tips'];

/** Template groups shown as tabs: key => [label, icon, template keys]. */
const TEMPLATE_GROUPS = [
    'account' => ['Account', 'user', ['welcome', 'password_changed', 'ticket_reply']],
    'learning' => ['Learning', 'book', ['enrolment', 'course_completed', 'certificate_issued']],
    'payments' => ['Payments', 'rupee', ['payment_success', 'payment_failed', 'refund_processed']],
    'tests' => ['Tests', 'clipboard', ['test_result']],
    'leads' => ['Leads & Newsletter', 'megaphone', ['enquiry_autoreply', 'newsletter_welcome']],
    'recruitment' => ['Recruitment', 'briefcase', ['application_ack', 'application_shortlisted', 'interview_scheduled', 'application_hired', 'application_rejected']],
    'live' => ['Live classes', 'live', ['class_reminder']],
];

/** When each template is sent (shown above the editor). */
const TEMPLATE_TRIGGERS = [
    'welcome' => 'Right after a student creates an account.',
    'password_changed' => 'After a password reset or a password change from the profile (security alert).',
    'ticket_reply' => 'When the support team replies to a student’s ticket.',
    'enrolment' => 'When a student enrols in a free course or staff grant access to a course.',
    'course_completed' => 'When a student completes 100% of a course for the first time.',
    'certificate_issued' => 'When staff issue a certificate from the Certificates page.',
    'payment_success' => 'After a verified payment. Includes a second button to download the invoice.',
    'payment_failed' => 'When an order is still unpaid 15 minutes after a failed payment attempt.',
    'refund_processed' => 'When staff record a refund on an order.',
    'test_result' => 'When a mock test result becomes visible to the student.',
    'enquiry_autoreply' => 'Auto-reply to website and chatbot enquiries that include an email (when enabled in Settings).',
    'newsletter_welcome' => 'When someone subscribes to the newsletter. {link} is their personal unsubscribe link.',
    'application_ack' => 'When a candidate applies for a job opening.',
    'application_shortlisted' => 'When an application is moved to Shortlisted.',
    'interview_scheduled' => 'When an interview is scheduled from Recruitment.',
    'application_hired' => 'When an application is marked Hired.',
    'application_rejected' => 'When an application is marked Rejected.',
    'class_reminder' => 'Before a live class starts, to students who asked for a reminder (lead time in Live Class Settings).',
];

/** Realistic sample values for previews and test emails. */
function template_samples(string $key, array $me): array
{
    $links = [
        'welcome' => '/courses', 'password_changed' => '/forgot-password', 'certificate_issued' => '/dashboard/certificates',
        'course_completed' => '/dashboard/courses', 'test_result' => '/dashboard/tests', 'enrolment' => '/dashboard/courses',
        'payment_success' => '/dashboard/purchases', 'payment_failed' => '/courses', 'refund_processed' => '/dashboard/purchases',
        'class_reminder' => '/dashboard/live-classes', 'ticket_reply' => '/dashboard/support', 'newsletter_welcome' => '/',
    ];
    $v = [
        'name' => $me['name'], 'email' => $me['email'], 'course' => 'SSC CGL 2026 Complete Course', 'amount' => '₹1,999',
        'order' => 'LXA-260001', 'when' => date('j M Y, g:i A'), 'date' => date('j M Y', strtotime('+3 days')) . ', 11:00 AM',
        'mode' => 'Online (video call)', 'location' => (string) setting('address', 'New Delhi'), 'message' => 'Please keep a government photo ID ready.',
        'score' => '42.5 / 60', 'result' => 'Passed · 70.8%', 'link' => $links[$key] ?? '/dashboard',
    ];
    if ($key === 'payment_success') {
        $v['links'] = [['Download invoice (PDF)', '/dashboard/purchases']];
    }
    if ($key === 'newsletter_welcome') {
        $v['button_label'] = 'Unsubscribe';
    }
    return $v;
}

/** Placeholders used in a text that are not supported. */
function template_unknown_placeholders(string ...$texts): array
{
    preg_match_all('/\{(\w+)\}/', implode("\n", $texts), $m);
    return array_values(array_unique(array_diff($m[1], array_keys(TEMPLATE_PLACEHOLDERS))));
}

function template_save_setting(string $k, string $v, int $by): void
{
    db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public, updated_by) VALUES (?, ?, 'notifications', 0, ?)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)", [$k, $v, $by]);
}

/** Validated editor input for $tpl (subject, body, channels) + the list of problems. */
function template_input(array $tpl): array
{
    $d = [
        'subject' => trim(str_replace(["\r", "\n"], ' ', (string) ($_POST['subject'] ?? ''))),
        'body' => trim(str_replace("\r\n", "\n", (string) ($_POST['body'] ?? ''))),
        'send_email' => empty($_POST['send_email']) ? 0 : 1,
        'send_inapp' => empty($_POST['send_inapp']) ? 0 : 1,
        'send_whatsapp' => empty($_POST['send_whatsapp']) ? 0 : 1,
    ];
    $errors = [];
    if ($d['subject'] === '' || mb_strlen($d['subject']) > 200) {
        $errors[] = 'Subject is required (up to 200 characters).';
    }
    if ($d['body'] === '' || mb_strlen($d['body']) > 5000) {
        $errors[] = 'Message is required (up to 5000 characters).';
    }
    if ($bad = template_unknown_placeholders($d['subject'], $d['body'])) {
        $errors[] = (count($bad) === 1 ? 'Unknown placeholder: ' : 'Unknown placeholders: ') . implode(' ', array_map(fn($p) => '{' . $p . '}', $bad)) . '. Use the chips below the editor.';
    }
    return [$d + ['template_key' => $tpl['template_key'], 'name' => $tpl['name']], $errors];
}

if (admin_post()) {
    $act = (string) ($_POST['_action'] ?? '');
    if (in_array($act, ['template', 'test', 'preview'], true)) {
        $tpl = db_one('SELECT * FROM notification_templates WHERE template_key = ?', [(string) ($_POST['key'] ?? '')]);
        if (!$tpl) {
            flash('error', 'Template not found.');
            redirect_to('templates.php');
        }
        [$draft, $errors] = template_input($tpl);
        $key = $tpl['template_key'];

        if ($act === 'preview') {
            // Live preview (fetch from the editor): the unsaved draft rendered exactly like a real email.
            $msg = render_template_message($draft, template_samples($key, $me), ['user' => true]);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'subject' => $msg['subject'], 'button' => $msg['link'] !== '' ? $msg['button_label'] : null,
                'html' => preg_replace('/<head>/i', '<head><base target="_blank">', $msg['html'], 1),
                'unknown' => template_unknown_placeholders($draft['subject'], $draft['body']),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if ($errors) {
            $_SESSION['tpl_draft'] = ['key' => $key, 'data' => $draft];
            flash('error', implode(' ', $errors));
            redirect_to('templates.php?edit=' . urlencode($key));
        }
        if ($act === 'template') {
            db_query('UPDATE notification_templates SET subject = ?, body = ?, send_email = ?, send_inapp = ?, send_whatsapp = ? WHERE template_key = ?',
                [$draft['subject'], $draft['body'], $draft['send_email'], $draft['send_inapp'], $canWhatsapp ? $draft['send_whatsapp'] : (int) $tpl['send_whatsapp'], $key]);
            audit_log('template_updated', 'notification_templates', $key);
            flash('success', 'Template “' . $tpl['name'] . '” saved.');
            redirect_to('templates.php?edit=' . urlencode($key));
        }
        // Test: the editor's current text (saved or not) with sample values, to the signed-in staff member.
        $msg = render_template_message($draft, template_samples($key, $me), ['user' => true]);
        $ok = send_mail((string) $me['email'], '[TEST] ' . $msg['subject'], $msg['html'], ['template_key' => $key, 'user_id' => (int) $me['id'], 'to_name' => $me['name'], 'max_attempts' => 1]);
        $status = last_email_log_id() ? (string) db_value('SELECT status FROM email_log WHERE id = ?', [last_email_log_id()]) : ($ok ? 'sent' : 'failed');
        audit_log('template_test_sent', 'notification_templates', $key, ['status' => $status]);
        if ($draft['subject'] !== $tpl['subject'] || $draft['body'] !== $tpl['body']) {
            $_SESSION['tpl_draft'] = ['key' => $key, 'data' => $draft]; // keep unsaved edits in the editor
        }
        flash($ok ? 'success' : ($status === 'skipped' ? 'warning' : 'error'), match (true) {
            $ok => 'Test email sent to ' . $me['email'] . '.',
            $status === 'skipped' => 'SMTP is not configured - the test email was written to the mail log (see Email Logs).',
            default => 'The test email failed: ' . (string) db_value('SELECT last_error FROM email_log WHERE id = ?', [(int) last_email_log_id()]),
        });
        redirect_to('templates.php?edit=' . urlencode($key));
    }
    if ($act === 'defaults') {
        $prefs = [];
        foreach ($keys as $k => $_) {
            $prefs[$k] = !empty($_POST['p'][$k]);
        }
        template_save_setting('notify_default_prefs', json_encode($prefs), (int) $me['id']);
        audit_log('notification_defaults_updated', 'site_settings');
        flash('success', 'Defaults saved. They apply to new students and to students who never changed their own preferences.');
        redirect_to('templates.php?tab=defaults');
    }
    if (in_array($act, ['whatsapp', 'wa_test'], true) && !$canWhatsapp) {
        http_response_code(403);
        exit('WhatsApp settings require the settings.manage permission.');
    }
    if ($act === 'whatsapp') {
        template_save_setting('whatsapp_enabled', empty($_POST['enabled']) ? '0' : '1', (int) $me['id']);
        template_save_setting('whatsapp_language', preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', (string) ($_POST['language'] ?? '')) ? (string) $_POST['language'] : 'en', (int) $me['id']);
        $bad = [];
        foreach ((array) ($_POST['map'] ?? []) as $k => $v) {
            $v = trim((string) $v);
            if ($v !== '' && !preg_match('/^[a-z0-9_]{1,512}\|?[a-z_,]*$/', $v)) {
                $bad[] = $k;
                continue;
            }
            if (db_value('SELECT 1 FROM notification_templates WHERE template_key = ?', [(string) $k])) {
                template_save_setting('wa_tpl_' . $k, $v, (int) $me['id']);
            }
        }
        audit_log('whatsapp_settings_updated', 'site_settings', null, [], 'warning');
        flash($bad ? 'error' : 'success', $bad ? 'Invalid mapping for: ' . implode(', ', $bad) . '. Use template_name|name,course,when' : 'WhatsApp settings saved.');
        redirect_to('templates.php?tab=whatsapp');
    }
    if ($act === 'wa_test') {
        $to = (string) ($_POST['phone'] ?? '');
        if (!(string) config('WHATSAPP_TOKEN', '') || !(string) config('WHATSAPP_PHONE_ID', '')) {
            flash('error', 'Add the Meta token and phone number ID in Keys & Credentials first.');
        } else {
            // "hello_world" is Meta's pre-approved sample template available on every WhatsApp Business account.
            [$ok, $msg] = whatsapp_send($to, ['type' => 'template', 'template' => ['name' => 'hello_world', 'language' => ['code' => 'en_US']]]);
            flash($ok ? 'success' : 'error', $ok ? 'Test message accepted by WhatsApp for ' . $to . '.' : 'WhatsApp rejected the message: ' . $msg);
        }
        redirect_to('templates.php?tab=whatsapp');
    }
    redirect_to('templates.php');
}

admin_start('Email Templates', null, 'notifications', ['head' => true,
    'subtitle' => 'Emails and notifications sent automatically by the platform. Edit the wording, preview the branded email and send yourself a test.',
    'actions' => '<a class="btn btn-light" href="email-logs.php">' . icon('mail') . ' Email logs</a>']);
echo admin_tabs($tabs, $tab);

if ($tab === 'defaults') {
    $cur = json_decode_array(setting('notify_default_prefs'));
    $uses = [];
    foreach (TEMPLATE_PREFERENCES as $tk => $pk) {
        $uses[$pk][] = $tk === 'announcement' ? 'Announcements' : (string) db_value('SELECT name FROM notification_templates WHERE template_key = ?', [$tk]);
    }
    echo '<form method="post" class="card" data-dirty-check style="max-width:760px">' . csrf_field() . '<input type="hidden" name="_action" value="defaults"><div class="card-head"><h2>Email preferences for new students</h2></div><div class="card-pad" style="display:grid;gap:16px">';
    foreach ($keys as $k => $l) {
        $hint = !empty($uses[$k]) ? 'Controls: ' . implode(', ', array_filter($uses[$k])) : 'Not used by an automatic email yet';
        echo '<label class="switch"><input type="checkbox" name="p[' . $k . ']" value="1"' . (!empty($cur[$k]) ? ' checked' : '') . '><span class="track"></span><span><strong>' . e($l) . '</strong><br><span class="muted" style="font-size:12px">' . e($hint) . '</span></span></label>';
    }
    echo '<p class="muted" style="margin:0">Payment, enrolment, account and security emails are always sent. Students can change these choices in their profile.</p></div><div class="form-actions"><span class="dirty-flag">' . icon('alert') . ' Unsaved changes</span><button class="btn btn-primary">Save defaults</button></div></form>';
    admin_end();
    exit;
}

if ($tab === 'whatsapp') {
    $configured = (string) config('WHATSAPP_TOKEN', '') !== '' && (string) config('WHATSAPP_PHONE_ID', '') !== '';
    $tpls = db_all('SELECT template_key, name FROM notification_templates ORDER BY name');
    ?>
    <div class="alert <?= $configured ? 'alert-success' : 'alert-warning' ?>"><?= $configured ? 'WhatsApp Cloud API credentials found (phone number ID ' . e(substr((string) config('WHATSAPP_PHONE_ID'), 0, 4)) . '••••).' : 'Add WHATSAPP_TOKEN and WHATSAPP_PHONE_ID (from Meta Business → WhatsApp → API Setup) in Keys & Credentials to enable sending.' ?></div>
    <form method="post" class="card" data-dirty-check>
      <?= csrf_field() ?><input type="hidden" name="_action" value="whatsapp">
      <div class="card-head"><h2>Template mapping</h2></div>
      <div class="card-pad form">
        <div class="field"><label class="switch"><input type="checkbox" name="enabled" value="1"<?= setting('whatsapp_enabled') === '1' ? ' checked' : '' ?>><span class="track"></span> Send WhatsApp messages</label></div>
        <div class="field"><label for="wl">Template language code</label><input id="wl" type="text" name="language" value="<?= e(setting('whatsapp_language', 'en')) ?>" placeholder="en or en_US or hi"></div>
        <div class="field full"><div class="hint">WhatsApp only allows business-initiated messages using templates approved in Meta Business Manager. For each message, enter <code>approved_template_name|param,param</code> - the parameters fill the template's {{1}}, {{2}}… in order (allowed: <?= e(implode(', ', array_keys(TEMPLATE_PLACEHOLDERS))) ?>). Also switch on “WhatsApp” on the template.</div></div>
        <?php foreach ($tpls as $t): ?>
        <div class="field"><label for="wa-<?= e($t['template_key']) ?>"><?= e($t['name']) ?></label><input id="wa-<?= e($t['template_key']) ?>" type="text" name="map[<?= e($t['template_key']) ?>]" value="<?= e(setting('wa_tpl_' . $t['template_key'], '')) ?>" placeholder="e.g. payment_confirmation|name,course,amount"></div>
        <?php endforeach; ?>
      </div>
      <div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><button class="btn btn-primary">Save</button></div>
    </form>
    <form method="post" class="card card-pad mt" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <?= csrf_field() ?><input type="hidden" name="_action" value="wa_test">
      <div class="field"><label for="wp">Send Meta's “hello_world” test template to</label><input id="wp" type="text" name="phone" placeholder="98xxxxxxxx" required></div>
      <button class="btn btn-light"><?= icon('check') ?> Send test</button>
    </form>
    <?php
    admin_end();
    exit;
}

// ------------------------------------------------------------------
// Templates tab
// ------------------------------------------------------------------
$all = array_column(db_all('SELECT * FROM notification_templates ORDER BY name'), null, 'template_key');
$groups = TEMPLATE_GROUPS;
$grouped = array_merge(...array_map(fn($g) => $g[2], array_values($groups)));
if ($others = array_values(array_diff(array_keys($all), $grouped))) {
    $groups['other'] = ['Other', 'file', $others];
}
foreach ($groups as $gk => $g) {
    $groups[$gk][2] = array_values(array_filter($g[2], fn($k) => isset($all[$k])));
}
$groups = array_filter($groups, fn($g) => $g[2]);
$edit = (string) ($_GET['edit'] ?? '');
$group = (string) ($_GET['group'] ?? '');
if (isset($all[$edit])) {
    foreach ($groups as $gk => $g) {
        if (in_array($edit, $g[2], true)) {
            $group = $gk;
        }
    }
}
if (!isset($groups[$group])) {
    $group = (string) array_key_first($groups);
}
if (!isset($all[$edit]) || !in_array($edit, $groups[$group][2] ?? [], true)) {
    $edit = (string) ($groups[$group][2][0] ?? '');
}
try {
    $stats = array_column(db_all("SELECT template_key, SUM(status = 'sent') AS sent, SUM(status = 'skipped') AS logged, SUM(status = 'failed') AS failed
        FROM email_log WHERE created_at > NOW() - INTERVAL 30 DAY AND template_key IS NOT NULL GROUP BY template_key"), null, 'template_key');
} catch (Throwable) {
    $stats = [];
}
$current = $all[$edit] ?? null;
if ($current && ($_SESSION['tpl_draft']['key'] ?? '') === $edit) {
    $current = array_merge($current, $_SESSION['tpl_draft']['data']); // unsaved edits after a failed save / test
}
unset($_SESSION['tpl_draft']);
$waReady = whatsapp_configured();
?>
<style>
  .tp-groups { display: flex; gap: 8px; overflow-x: auto; padding: 2px 2px 10px; margin-bottom: 8px; scrollbar-width: none; scroll-snap-type: x proximity; -webkit-mask-image: linear-gradient(90deg, #000 92%, transparent); mask-image: linear-gradient(90deg, #000 92%, transparent); }
  .tp-groups::-webkit-scrollbar { display: none; }
  @media (min-width: 1200px) { .tp-groups { flex-wrap: wrap; -webkit-mask-image: none; mask-image: none; } }
  .tp-groups a { scroll-snap-align: start; display: inline-flex; align-items: center; gap: 8px; padding: 9px 14px; border-radius: 99px; border: 1px solid var(--line); background: #fff; color: var(--ink); font-weight: 600; font-size: 13px; white-space: nowrap; transition: border-color .15s, background .15s, color .15s; }
  .tp-groups a:hover { border-color: var(--primary); color: var(--primary); text-decoration: none; }
  .tp-groups a.on { background: var(--navy); border-color: var(--navy); color: #fff; box-shadow: 0 6px 16px -8px rgb(8 38 77 / .6); }
  .tp-groups a svg.i { width: 16px; height: 16px; }
  .tp-groups .n { min-width: 20px; padding: 0 6px; border-radius: 99px; background: rgb(11 99 246 / .1); color: var(--primary); font-size: 11px; line-height: 18px; text-align: center; }
  .tp-groups a.on .n { background: rgb(255 255 255 / .18); color: #fff; }
  .tp-layout { display: grid; gap: 18px; grid-template-columns: minmax(230px, 280px) minmax(0, 1fr); align-items: start; }
  .tp-list { display: grid; gap: 10px; position: sticky; top: 80px; }
  .tp-item { display: block; padding: 14px 16px; border-radius: 14px; border: 1px solid var(--line); background: #fff; color: var(--ink); transition: border-color .15s, box-shadow .15s, transform .15s; }
  .tp-item:hover { border-color: var(--primary); text-decoration: none; transform: translateY(-1px); }
  .tp-item.on { border-color: var(--primary); box-shadow: 0 0 0 3px rgb(11 99 246 / .12); }
  .tp-item strong { display: block; font-size: 14px; color: var(--navy); }
  .tp-item .sub { display: block; font-size: 12px; color: var(--muted); margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .tp-ch { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 8px; }
  .tp-ch span { font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 99px; background: #eef2f7; color: var(--muted); }
  .tp-ch span.on { background: var(--green-soft); color: #15803d; }
  .tp-main { display: grid; gap: 18px; grid-template-columns: minmax(0, 1fr); }
  @media (min-width: 1500px) { .tp-main { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); } }
  @media (max-width: 1050px) { .tp-layout { grid-template-columns: 1fr; } .tp-list { position: static; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); } }
  .tp-meta { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .tp-key { display: inline-flex; align-items: center; gap: 5px; font-family: Consolas, monospace; font-size: 12px; padding: 3px 8px; border-radius: 8px; background: #eef2f7; color: var(--muted); }
  .tp-key svg.i { width: 13px; height: 13px; }
  .tp-trigger { margin: 0; padding: 12px 14px; border-radius: 12px; background: var(--primary-soft); color: var(--navy); font-size: 13px; display: flex; gap: 10px; align-items: flex-start; }
  .tp-chips { display: flex; gap: 6px; flex-wrap: wrap; }
  .tp-chips button { font: 600 12px/1 Consolas, monospace; padding: 6px 9px; border-radius: 8px; border: 1px dashed #c7d6ee; background: #fff; color: var(--muted); cursor: pointer; transition: all .15s; }
  .tp-chips button.used { border-style: solid; border-color: #bfd5fd; background: var(--primary-soft); color: var(--primary); }
  .tp-chips button:hover, .tp-chips button:focus-visible { border-color: var(--primary); color: var(--primary); outline: none; }
  .tp-switches { display: flex; gap: 20px; flex-wrap: wrap; }
  .tp-note { font-size: 12px; color: var(--muted); margin-top: 6px; }
  .tp-frame-wrap { display: flex; justify-content: center; background: #EEF3FA; border-radius: 0 0 var(--radius) var(--radius); }
  .tp-frame { width: 100%; height: 820px; border: 0; display: block; transition: width .2s; }
  .tp-view { display: inline-flex; gap: 4px; padding: 3px; background: #eef2f7; border-radius: 10px; }
  .tp-view button { border: 0; background: transparent; padding: 5px 12px; border-radius: 8px; font-weight: 600; font-size: 12px; color: var(--muted); cursor: pointer; }
  .tp-view button[aria-pressed="true"] { background: #fff; color: var(--primary); box-shadow: 0 1px 2px rgb(0 0 0 / .08); }
  .tp-inbox { padding: 12px 20px; border-bottom: 1px solid var(--line); font-size: 13px; display: grid; gap: 3px; }
  .tp-inbox b { color: var(--navy); }
  @media (prefers-reduced-motion: reduce) { .tp-item, .tp-frame, .tp-groups a { transition: none; } .tp-item:hover { transform: none; } }
</style>
<nav class="tp-groups" aria-label="Template groups">
  <?php foreach ($groups as $gk => [$label, $ico, $list]): ?>
  <a href="?group=<?= e($gk) ?>"<?= $gk === $group ? ' class="on" aria-current="page"' : '' ?>><?= icon($ico) ?> <?= e($label) ?> <span class="n"><?= count($list) ?></span></a>
  <?php endforeach; ?>
</nav>
<?php if (!$current): ?>
  <div class="card empty">No templates found. Apply the latest Database Updates to install the default templates.</div>
<?php else:
    $key = $current['template_key'];
    $samples = template_samples($key, $me);
    $preview = render_template_message($current, $samples, ['user' => true]);
    $used = [];
    preg_match_all('/\{(\w+)\}/', $current['subject'] . "\n" . $current['body'], $m);
    $used = array_unique($m[1]);
    $prefKey = TEMPLATE_PREFERENCES[$key] ?? null;
    ?>
<div class="tp-layout">
  <div class="tp-list" role="list" aria-label="<?= e($groups[$group][0]) ?> templates">
    <?php foreach ($groups[$group][2] as $k): $t = $all[$k]; $s = $stats[$k] ?? null; ?>
    <a role="listitem" class="tp-item<?= $k === $key ? ' on' : '' ?>" href="?edit=<?= e(urlencode($k)) ?>"<?= $k === $key ? ' aria-current="true"' : '' ?>>
      <strong><?= e($t['name']) ?></strong>
      <span class="sub"><?= e($t['subject']) ?></span>
      <span class="tp-ch"><span class="<?= $t['send_email'] ? 'on' : '' ?>">Email</span><span class="<?= $t['send_inapp'] ? 'on' : '' ?>">In-app</span><span class="<?= $t['send_whatsapp'] ? 'on' : '' ?>">WhatsApp</span>
        <?php if ($s): ?><span title="Last 30 days"><?= (int) $s['sent'] ?> sent<?= $s['logged'] ? ' · ' . (int) $s['logged'] . ' logged' : '' ?><?= $s['failed'] ? ' · ' . (int) $s['failed'] . ' failed' : '' ?></span><?php endif; ?></span>
    </a>
    <?php endforeach; ?>
    <?php if ($group === 'account'): ?>
    <div class="muted" style="font-size:12px;padding:4px 6px">Email verification and password-reset emails use fixed, secure wording and are recorded in <a href="email-logs.php?template=password_reset">Email Logs</a>.</div>
    <?php endif; ?>
  </div>
  <div class="tp-main">
    <form method="post" class="card" id="tpl-form" data-dirty-check>
      <?= csrf_field() ?><input type="hidden" name="key" value="<?= e($key) ?>">
      <div class="card-head" style="flex-wrap:wrap">
        <div><h2><?= e($current['name']) ?></h2>
          <div class="tp-meta" style="margin-top:6px">
            <span class="tp-key" title="<?= $current['is_system'] ? 'System template - the key is used by the code and cannot be changed' : 'Template key' ?>"><?= $current['is_system'] ? icon('lock') : '' ?><?= e($key) ?></span>
            <?= in_array($key, TRANSACTIONAL_TEMPLATES, true) ? '<span class="badge b-blue">Always sent</span>' : ($prefKey ? '<span class="badge b-purple">Respects preference: ' . e($keys[$prefKey] ?? $prefKey) . '</span>' : ($key === 'class_reminder' ? '<span class="badge b-purple">Respects class-reminder preference</span>' : '')) ?>
          </div>
        </div>
      </div>
      <div class="card-pad" style="display:grid;gap:16px">
        <?php if (isset(TEMPLATE_TRIGGERS[$key])): ?><p class="tp-trigger"><?= icon('clock') ?><span><strong>Sent:</strong> <?= e(TEMPLATE_TRIGGERS[$key]) ?></span></p><?php endif; ?>
        <div class="field"><label for="ts">Subject / notification title</label><input id="ts" type="text" name="subject" value="<?= e($current['subject']) ?>" maxlength="200" required data-counter="90,200"></div>
        <div class="field"><label for="tb">Message</label><textarea id="tb" name="body" rows="11" maxlength="5000" required data-counter="1500,5000"><?= e($current['body']) ?></textarea>
          <div class="hint">Plain text - a blank line starts a new paragraph. Put <code>{link}</code> on its own line (or <code>Button text: {link}</code>) to show it as the email button.</div></div>
        <div>
          <div class="lbl" id="ph-label">Placeholders <span class="muted" style="font-weight:500">- click to insert at the cursor</span></div>
          <div class="tp-chips" role="group" aria-labelledby="ph-label">
            <?php foreach (TEMPLATE_PLACEHOLDERS as $ph => $desc): ?><button type="button" class="<?= in_array($ph, $used, true) ? 'used' : '' ?>" data-ph="{<?= e($ph) ?>}" title="<?= e($desc . ' - e.g. ' . (is_scalar($samples[$ph] ?? null) ? $samples[$ph] : '')) ?>">{<?= e($ph) ?>}</button><?php endforeach; ?>
          </div>
        </div>
        <div>
          <div class="lbl">Channels</div>
          <div class="tp-switches">
            <label class="switch"><input type="checkbox" name="send_email" value="1"<?= $current['send_email'] ? ' checked' : '' ?>><span class="track"></span> Email</label>
            <label class="switch"><input type="checkbox" name="send_inapp" value="1"<?= $current['send_inapp'] ? ' checked' : '' ?>><span class="track"></span> In-app</label>
            <label class="switch"><input type="checkbox" name="send_whatsapp" value="1"<?= $current['send_whatsapp'] ? ' checked' : '' ?><?= $canWhatsapp ? '' : ' disabled' ?>><span class="track"></span> WhatsApp</label>
          </div>
          <div class="tp-note"><?= $waReady ? 'WhatsApp also needs an approved template mapped in the WhatsApp tab.' : 'WhatsApp is not set up yet' . ($canWhatsapp ? ' (WhatsApp tab).' : '.') ?> In-app messages appear only for registered students.</div>
        </div>
      </div>
      <div class="form-actions" style="flex-wrap:wrap">
        <span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span>
        <button class="btn btn-light" name="_action" value="test" formnovalidate title="Uses the text in the editor, saved or not"><?= icon('mail') ?> Send test to <?= e($me['email']) ?></button>
        <button class="btn btn-primary" name="_action" value="template"><?= icon('check') ?> Save template</button>
      </div>
    </form>
    <div class="card">
      <div class="card-head"><h2>Live preview</h2>
        <div class="tp-view" role="group" aria-label="Preview width"><button type="button" aria-pressed="true" data-w="100%">Desktop</button><button type="button" aria-pressed="false" data-w="390px">Mobile</button></div>
      </div>
      <div class="tp-inbox" aria-live="polite">
        <span><b>Subject:</b> <span id="tpl-subject-out"><?= e($preview['subject']) ?></span></span>
        <span class="muted"><b>Button:</b> <span id="tpl-button-out"><?= e($preview['link'] !== '' ? $preview['button_label'] : 'none') ?></span> · sample values, sent to <?= e($me['email']) ?> in tests</span>
        <span id="tpl-warn" class="alert alert-warning" style="margin:6px 0 0" hidden></span>
      </div>
      <div class="tp-frame-wrap"><iframe class="tp-frame" id="tpl-frame" title="Email preview" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" srcdoc="<?= e(preg_replace('/<head>/i', '<head><base target="_blank">', $preview['html'], 1)) ?>"></iframe></div>
    </div>
  </div>
</div>
<script>
(() => {
  const form = document.getElementById('tpl-form');
  if (!form) return;
  const frame = document.getElementById('tpl-frame');
  const subjOut = document.getElementById('tpl-subject-out');
  const btnOut = document.getElementById('tpl-button-out');
  const warn = document.getElementById('tpl-warn');
  const fields = form.querySelectorAll('[name=subject], [name=body]');
  let target = form.querySelector('[name=body]');
  fields.forEach((f) => f.addEventListener('focus', () => { target = f; }));

  // Placeholder chips insert at the cursor of the last focused field.
  document.querySelectorAll('[data-ph]').forEach((chip) => chip.addEventListener('click', () => {
    const start = target.selectionStart ?? target.value.length;
    const end = target.selectionEnd ?? target.value.length;
    target.setRangeText(chip.dataset.ph, start, end, 'end');
    target.focus();
    target.dispatchEvent(new Event('input', { bubbles: true }));
  }));

  const markUsed = () => {
    const text = Array.from(fields).map((f) => f.value).join('\n');
    document.querySelectorAll('[data-ph]').forEach((c) => c.classList.toggle('used', text.includes(c.dataset.ph)));
  };

  // Live preview: the server renders the draft with the real email template.
  let timer;
  let ctrl;
  const refresh = async () => {
    ctrl?.abort();
    ctrl = new AbortController();
    const fd = new FormData(form);
    fd.set('_action', 'preview');
    try {
      const res = await fetch(form.action, { method: 'POST', body: fd, signal: ctrl.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const d = await res.json();
      frame.srcdoc = d.html;
      subjOut.textContent = d.subject || '(empty subject)';
      btnOut.textContent = d.button || 'none';
      warn.hidden = !d.unknown.length;
      warn.textContent = d.unknown.length ? (d.unknown.length === 1 ? 'Unknown placeholder: ' : 'Unknown placeholders: ') + d.unknown.map((x) => '{' + x + '}').join(' ') + ' - saving is blocked until they are fixed.' : '';
    } catch (e) {
      if (e.name === 'AbortError') return;
      warn.hidden = false;
      warn.textContent = 'The preview could not be refreshed - reload the page if your session expired.';
    }
  };
  form.addEventListener('input', (e) => {
    if (!e.target.matches('[name=subject], [name=body]')) return;
    markUsed();
    clearTimeout(timer);
    timer = setTimeout(refresh, 350);
  });

  document.querySelectorAll('.tp-view button').forEach((b) => b.addEventListener('click', () => {
    document.querySelectorAll('.tp-view button').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    frame.style.width = b.dataset.w;
  }));
})();
</script>
<?php endif; ?>
<?php admin_end();
