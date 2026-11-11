<?php
/**
 * Template-based notifications: in-app + email + WhatsApp (Interakt / Cloud API, see includes/whatsapp.php).
 *
 *   send_template('payment_success', $userId, [
 *       'course' => '…', 'amount' => '₹9,999', 'order' => 'LXA…',
 *       'link' => '/enrolled/…', 'button_label' => 'Start learning',
 *       'links' => [['Download invoice (PDF)', 'https://…']], 'related' => ['order', 12],
 *   ]);
 *
 * Templates are edited in admin → Email Templates (notification_templates). Placeholders:
 * {name} {email} {course} {amount} {order} {when} {date} {mode} {location} {message} {score}
 * {result} {link}. A body line that is just "{link}" (or "Label: {link}") becomes the email
 * button. Every email is logged in email_log and failures are retried (includes/mailer.php).
 * Students' notify_prefs are respected for non-transactional emails; payment, enrolment,
 * account and security messages always send.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once APP_ROOT . '/includes/mailer.php';

/** Always emailed, whatever the student's preferences (service, payment and security mail). */
const TRANSACTIONAL_TEMPLATES = [
    'payment_success', 'payment_failed', 'refund_processed', 'enrolment', 'welcome', 'password_changed',
    'application_ack', 'application_shortlisted', 'application_rejected', 'application_hired', 'interview_scheduled',
    'enquiry_autoreply', 'ticket_reply',
];

/** Non-transactional templates -> the student_profiles.notify_prefs key that controls their email. */
const TEMPLATE_PREFERENCES = [
    'test_result' => 'test_results',
    'course_completed' => 'course_updates',
    'certificate_issued' => 'course_updates',
    'announcement' => 'announcements',
];

/** Placeholders every template may use (anything else is rejected by the editor). */
const TEMPLATE_PLACEHOLDERS = [
    'name' => 'Recipient name', 'email' => 'Recipient email', 'course' => 'Course / exam / job title',
    'amount' => 'Amount (₹)', 'order' => 'Order / ticket number', 'when' => 'Date and time',
    'date' => 'Interview date', 'mode' => 'Interview mode', 'location' => 'Venue or meeting link',
    'message' => 'Personal note', 'score' => 'Test score', 'result' => 'Result summary', 'link' => 'Button link',
];

/** Default button text when neither the caller nor the body ("Label: {link}") gives one. */
const TEMPLATE_BUTTON_LABELS = [
    'welcome' => 'Explore courses', 'password_changed' => 'Reset password', 'certificate_issued' => 'View certificate',
    'course_completed' => 'Go to my courses', 'test_result' => 'View result', 'enrolment' => 'Start learning',
    'payment_success' => 'Start learning', 'payment_failed' => 'Try again', 'class_reminder' => 'Open live classes',
    'ticket_reply' => 'View reply', 'refund_processed' => 'View purchases', 'doubt_answered' => 'Read the answer',
];

/** Small label above the email title. */
const TEMPLATE_EYEBROWS = [
    'welcome' => 'Welcome aboard', 'password_changed' => 'Account security', 'certificate_issued' => 'Certificate',
    'course_completed' => 'Course completed', 'test_result' => 'Mock test result', 'enrolment' => 'Enrolment confirmed',
    'payment_success' => 'Payment received', 'payment_failed' => 'Payment', 'refund_processed' => 'Refund',
    'class_reminder' => 'Live class', 'ticket_reply' => 'Support', 'enquiry_autoreply' => 'We got your message',
    'newsletter_welcome' => 'Newsletter', 'application_ack' => 'Careers', 'application_shortlisted' => 'Careers',
    'application_rejected' => 'Careers', 'application_hired' => 'Careers', 'interview_scheduled' => 'Interview',
    'doubt_answered' => 'Doubt answered',
];

function render_placeholders(string $text, array $vars): string
{
    return preg_replace_callback('/\{(\w+)\}/', fn($m) => array_key_exists($m[1], $vars) && is_scalar($vars[$m[1]]) ? (string) $vars[$m[1]] : $m[0], $text);
}

/**
 * Take the {link} line out of a template body so it can become the email button.
 * "{link}" alone on a line is removed (a lead-in "…here:" just above gets a full stop);
 * "Start learning: {link}" is removed and "Start learning" becomes the button label.
 * A {link} inside a longer sentence stays and is rendered as an inline link.
 * Returns [body, label|null].
 */
function template_split_link(string $body): array
{
    $lines = preg_split('/\r\n|\r|\n/', $body);
    $label = null;
    foreach ($lines as $i => $line) {
        if (!str_contains($line, '{link}')) {
            continue;
        }
        $t = trim($line);
        if ($t === '{link}') {
            unset($lines[$i]);
            for ($j = $i - 1; $j >= 0; $j--) {
                if (!isset($lines[$j])) {
                    continue;
                }
                if (str_ends_with(rtrim($lines[$j]), ':')) {
                    $lines[$j] = substr(rtrim($lines[$j]), 0, -1) . '.';
                }
                break;
            }
        } elseif (preg_match('/^(.{1,40}?)\s*:\s*\{link\}$/u', $t, $m)) {
            unset($lines[$i]);
            $label ??= trim($m[1]);
        }
    }
    return [trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines))), $label];
}

/**
 * Render a template (DB row or an unsaved draft with subject/body) into everything a sender needs:
 * ['subject', 'text' (message without the link line), 'html' (complete email), 'link' (absolute),
 *  'link_path' (site path for in-app links or null), 'button_label', 'transactional'].
 * $opts: user (bool, registered student → manage-preferences link in the footer).
 */
function render_template_message(array $tpl, array $vars, array $opts = []): array
{
    $key = (string) ($tpl['template_key'] ?? '');
    $flat = array_fill_keys(array_keys(TEMPLATE_PLACEHOLDERS), '');
    foreach ($vars as $k => $v) {
        if (is_scalar($v) || $v === null) {
            $flat[$k] = (string) $v;
        }
    }
    $linkRaw = $flat['link'];
    $absLink = email_absolute_url($linkRaw);
    $linkPath = $linkRaw !== '' && str_starts_with($linkRaw, '/') && !str_starts_with($linkRaw, '//') ? $linkRaw : null;

    $subject = trim(preg_replace('/\s+/', ' ', render_placeholders((string) $tpl['subject'], $flat)));
    [$body, $lineLabel] = template_split_link((string) $tpl['body']);
    // Button text: caller's label, else a descriptive "Label: {link}" lead-in ("Start learning"), else the template default.
    $multiWord = $lineLabel !== null && str_contains($lineLabel, ' ');
    $label = trim(is_string($vars['button_label'] ?? null) ? $vars['button_label'] : '')
        ?: ($multiWord ? $lineLabel : (TEMPLATE_BUTTON_LABELS[$key] ?? ($lineLabel ?: 'Open')));

    // Inline {link}: a marker survives escaping and is swapped for an <a> afterwards.
    $marker = "\u{E000}LINK\u{E000}";
    $rendered = render_placeholders($body, ['link' => $marker] + $flat);
    $text = trim(str_replace($marker, $absLink, $rendered));
    $inlineText = is_string($vars['button_label'] ?? null) && trim($vars['button_label']) !== '' ? trim($vars['button_label']) : $absLink;
    $html = str_replace($marker, $absLink !== '' ? '<a href="' . e($absLink) . '" style="color:#0B63F6;font-weight:600">' . e($inlineText) . '</a>' : '', mail_paragraphs($rendered));

    // The newsletter link is the unsubscribe link: keep it inline and in the footer, never as the main call to action.
    $unsubscribe = $key === 'newsletter_welcome' ? $absLink : '';
    [$btnLabel, $btnUrl] = $unsubscribe !== ''
        ? ['Explore ' . setting('site_name', 'Laxyam Academy'), app_url('')]
        : [$absLink !== '' ? $label : null, $absLink !== '' ? $absLink : null];

    $transactional = in_array($key, TRANSACTIONAL_TEMPLATES, true);
    $paras = preg_split('/\n\s*\n/', str_replace($marker, '', $rendered));
    $preheader = '';
    foreach ($paras as $p) {
        $p = trim(preg_replace('/\s+/', ' ', $p));
        if ($p !== '' && !preg_match('/^(hello|hi|dear)\b[^.!?]{0,60},?$/i', $p)) {
            $preheader = mb_strimwidth($p, 0, 140, '…');
            break;
        }
    }

    $emailHtml = mail_template($subject, $html, $btnLabel, $btnUrl, [
        'links' => is_array($vars['links'] ?? null) ? $vars['links'] : [],
        'transactional' => $transactional,
        'manage_url' => !empty($opts['user']) ? app_url('dashboard/profile') : null,
        'unsubscribe_url' => $unsubscribe !== '' ? $unsubscribe : (is_string($vars['unsubscribe_url'] ?? null) ? $vars['unsubscribe_url'] : null),
        'eyebrow' => TEMPLATE_EYEBROWS[$key] ?? (string) setting('site_name', 'Laxyam Academy'),
        'preheader' => $preheader,
    ]);
    return [
        'subject' => $subject, 'text' => $text, 'html' => $emailHtml, 'link' => $absLink,
        'link_path' => $linkPath, 'button_label' => $btnLabel ?? $label, 'transactional' => $transactional,
    ];
}

/** Does this student (row with notify_email, notify_class_reminders, notify_prefs) want email for $key? */
function student_email_allowed(string $key, ?array $u): bool
{
    if ($u === null || in_array($key, TRANSACTIONAL_TEMPLATES, true)) {
        return true;
    }
    if ($key === 'class_reminder') {
        return (int) ($u['notify_class_reminders'] ?? 1) === 1;
    }
    if (array_key_exists('notify_email', $u) && $u['notify_email'] !== null && (int) $u['notify_email'] === 0) {
        return false; // master email switch
    }
    $pref = TEMPLATE_PREFERENCES[$key] ?? null;
    return $pref === null || student_pref_enabled($u['notify_prefs'] ?? null, $pref);
}

/** One notify_prefs flag; students who never saved preferences get the admin defaults (Email Templates → Default Preferences). */
function student_pref_enabled(?string $prefsJson, string $pref): bool
{
    $prefs = json_decode_array($prefsJson);
    if (!array_key_exists($pref, $prefs)) {
        $prefs = json_decode_array(setting('notify_default_prefs'));
    }
    return !array_key_exists($pref, $prefs) || (bool) $prefs[$pref];
}

/**
 * Send a template to a registered user (by id) or to a plain recipient ['name'=>, 'email'=>, 'phone'=>].
 * Optional vars: button_label, links [[label, url], …], related ['order', 12].
 * Returns the channels that were delivered (email only when the mail server accepted it;
 * a failed email stays queued in email_log for retry).
 */
function send_template(string $key, int|array $to, array $vars = []): array
{
    $tpl = db_one('SELECT * FROM notification_templates WHERE template_key = ?', [$key]);
    if (!$tpl) {
        return [];
    }
    $userId = is_int($to) ? $to : null;
    if ($userId) {
        $u = db_one('SELECT u.name, u.email, u.phone, p.notify_email, p.notify_class_reminders, p.notify_prefs
                     FROM users u LEFT JOIN student_profiles p ON p.user_id = u.id WHERE u.id = ? AND u.status = ?', [$userId, 'active']);
        if (!$u) {
            return [];
        }
    } else {
        $u = $to + ['name' => '', 'email' => null, 'phone' => null];
    }
    $vars += ['name' => (string) $u['name'], 'email' => (string) ($u['email'] ?? '')];
    $msg = render_template_message($tpl, $vars, ['user' => (bool) $userId]);
    $sent = [];

    if ($tpl['send_inapp'] && $userId) {
        $plain = trim(preg_replace('/\s+/', ' ', $msg['link'] !== '' ? str_replace($msg['link'], '', $msg['text']) : $msg['text']));
        notify($userId, $key, $msg['subject'], mb_strimwidth($plain, 0, 480, '…'), $msg['link_path']);
        $sent[] = 'in-app';
    }
    $mayEmail = student_email_allowed($key, $userId ? $u : null);
    if ($tpl['send_email'] && $mayEmail && !empty($u['email'])) {
        $meta = ['template_key' => $key, 'user_id' => $userId, 'to_name' => (string) $u['name'], 'related' => $vars['related'] ?? null];
        if (send_mail((string) $u['email'], $msg['subject'], $msg['html'], $meta)) {
            $sent[] = 'email'; // only report channels that actually delivered
        }
    }
    // WhatsApp (Interakt by default, Meta Cloud API for older setups): includes/whatsapp.php picks the event's
    // approved template, checks the phone, opt-in / consent and preferences, logs the attempt in
    // whatsapp_messages and retries failures. Nothing happens while WhatsApp is switched off.
    // In web requests the message is sent after the response ('queued' = accepted for sending now).
    if (setting('whatsapp_enabled', '0') === '1' && is_file(APP_ROOT . '/includes/whatsapp.php')) {
        require_once APP_ROOT . '/includes/whatsapp.php';
        try {
            if (in_array(whatsapp_notify_event($key, $userId, $u, $vars, (bool) $tpl['send_whatsapp']), ['sent', 'queued'], true)) {
                $sent[] = 'whatsapp';
            }
        } catch (Throwable $e) {
            // A WhatsApp problem must never break the email / in-app message or the caller's request.
            error_log('[laxyam] whatsapp ' . $key . ' failed: ' . $e->getMessage());
        }
    }
    return $sent;
}

/**
 * Email a staff alert (new enquiry, payment, application …). $email may hold several
 * comma-separated addresses; when it is empty the contact_email setting is used.
 */
function notify_staff_email(?string $email, string $subject, string $text, ?string $adminPath = null): void
{
    $list = trim((string) $email) !== '' ? (string) $email : (string) setting('contact_email', '');
    $url = $adminPath ? app_url('admin/' . ltrim($adminPath, '/')) : null;
    $html = mail_template($subject, mail_paragraphs($text), $url ? 'Open in admin' : null, $url, ['eyebrow' => 'Staff alert', 'preheader' => mb_strimwidth(trim(preg_replace('/\s+/', ' ', $text)), 0, 140, '…')]);
    foreach (array_unique(array_map('trim', explode(',', $list))) as $addr) {
        if (email_address_ok($addr)) {
            send_mail($addr, $subject, $html, ['template_key' => 'staff_alert']);
        }
    }
    // The same alert on WhatsApp to the admin alert numbers (new enquiry, paid order, job application, support
    // ticket). The admin path names the saved record; its details are read from the database.
    if ($adminPath && setting('whatsapp_enabled', '0') === '1' && is_file(APP_ROOT . '/includes/whatsapp.php')) {
        require_once APP_ROOT . '/includes/whatsapp.php';
        try {
            whatsapp_staff_alert_from_path($subject, $adminPath);
        } catch (Throwable $e) {
            error_log('[laxyam] whatsapp staff alert failed: ' . $e->getMessage());
        }
    }
}

/** Staff reset links live this long (admin/forgot-password.php and the website's "Forgot password?"). */
const STAFF_RESET_MINUTES = 30;

/** Email a single-use admin reset link (admin/reset-password.php) to an active staff account. Never throws. */
function send_staff_password_reset(array $user): void
{
    $token = create_user_token('password_reset_tokens', (int) $user['id'], STAFF_RESET_MINUTES);
    $url = app_url('admin/reset-password.php?token=' . $token);
    $siteName = (string) setting('site_name', 'Laxyam Academy');
    try {
        send_mail($user['email'], 'Reset your ' . $siteName . ' admin password', mail_template(
            'Reset your admin password',
            '<p>Hello ' . e($user['name']) . ',</p><p>We received a request to reset the password of your staff account. '
            . 'This link works once and expires in ' . STAFF_RESET_MINUTES . ' minutes. If you did not ask for this, you can ignore this email - your password stays the same.</p>'
            . '<p style="color:#64748B;font-size:13px">Requested from IP ' . e(client_ip()) . ' on ' . e(date('j M Y, g:i A')) . '.</p>',
            'Choose a new password',
            $url,
            ['eyebrow' => 'Staff account security', 'preheader' => 'Use this link within ' . STAFF_RESET_MINUTES . ' minutes to choose a new admin password.']
        ), ['template_key' => 'admin_password_reset', 'user_id' => (int) $user['id'], 'to_name' => $user['name'], 'related' => ['user', (int) $user['id']]]);
    } catch (Throwable $e) {
        error_log('[laxyam] staff reset email failed: ' . $e->getMessage());
    }
    audit_log('admin_password_reset_requested', 'user', $user['id'], [], 'warning');
}

// ------------------------------------------------------------------
// WhatsApp. Automatic messages go through includes/whatsapp.php (admin → WhatsApp): Interakt by default
// (.env INTERAKT_API_KEY), or the Meta Cloud API when setting whatsapp_provider = meta (.env WHATSAPP_TOKEN,
// WHATSAPP_PHONE_ID). The helpers below are the older Meta-only API, kept for the integration test buttons;
// a legacy mapping wa_tpl_<template_key> = "approved_template_name|name,course,when" still works with "meta".
// ------------------------------------------------------------------
/** WhatsApp switched on and the selected provider's credentials present in .env. */
function whatsapp_configured(): bool
{
    if (setting('whatsapp_enabled', '0') !== '1') {
        return false;
    }
    return setting('whatsapp_provider', 'interakt') === 'meta'
        ? (string) config('WHATSAPP_TOKEN', '') !== '' && (string) config('WHATSAPP_PHONE_ID', '') !== ''
        : (string) config('INTERAKT_API_KEY', '') !== '';
}

function whatsapp_number(string $phone): ?string
{
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) === 10) {
        $digits = '91' . $digits;
    }
    return preg_match('/^\d{11,15}$/', $digits) ? $digits : null;
}

/** Low-level send. $payload is the message object (template/text). Returns [ok, message]. */
function whatsapp_send(string $phone, array $payload): array
{
    $to = whatsapp_number($phone);
    if (!$to) {
        return [false, 'Invalid phone number'];
    }
    require_once APP_ROOT . '/includes/payments.php'; // http_json()
    try {
        [$status, $data] = http_json('POST', 'https://graph.facebook.com/v20.0/' . rawurlencode((string) config('WHATSAPP_PHONE_ID')) . '/messages',
            ['messaging_product' => 'whatsapp', 'to' => $to] + $payload, ['Authorization: Bearer ' . config('WHATSAPP_TOKEN')]);
    } catch (RuntimeException $e) {
        return [false, $e->getMessage()];
    }
    $ok = $status >= 200 && $status < 300 && !empty($data['messages']);
    if (!$ok) {
        error_log('[laxyam] whatsapp send failed: HTTP ' . $status . ' ' . ($data['error']['message'] ?? ''));
    }
    return [$ok, $ok ? 'Sent' : ($data['error']['message'] ?? "HTTP {$status}")];
}

function whatsapp_send_template(string $key, string $phone, array $vars): bool
{
    $map = (string) setting('wa_tpl_' . $key, '');
    if ($map === '') {
        return false;
    }
    [$name, $params] = array_pad(explode('|', $map, 2), 2, '');
    $parameters = array_map(fn($p) => ['type' => 'text', 'text' => mb_substr(is_scalar($vars[trim($p)] ?? null) ? (string) $vars[trim($p)] : '', 0, 200)], array_filter(explode(',', $params)));
    [$ok] = whatsapp_send($phone, ['type' => 'template', 'template' => [
        'name' => trim($name), 'language' => ['code' => (string) setting('whatsapp_language', 'en')],
        'components' => $parameters ? [['type' => 'body', 'parameters' => $parameters]] : [],
    ]]);
    return $ok;
}
