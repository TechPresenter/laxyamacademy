<?php
/**
 * Email delivery: branded HTML template, a small SMTP client (no external library)
 * and the email_log queue.
 *
 *   send_mail($to, $subject, $html, ['template_key' => 'x', 'user_id' => 5, 'related' => ['order', 12]]): bool
 *   queue_email($to, $subject, $html, $meta): int      (bulk / background mail; the scheduler sends it)
 *   process_email_queue(50): array                      (run by includes/scheduler.php)
 *
 * Every message gets an email_log row (admin → Email Logs):
 *   sent     accepted by the SMTP server
 *   skipped  SMTP_HOST is blank (development): written to uploads/private/logs/mail.log instead
 *   failed   SMTP error. While next_attempt_at is set it is retried with back-off
 *            (5 min, 15 min, 1 h, 4 h, 12 h) until setting email_max_attempts; NULL = gave up
 *   queued   waiting for the scheduler
 *   sending  claimed by a worker (reclaimed after 15 minutes if that worker died before the message
 *            was handed over; transport 'smtp-data' marks a hand-over in progress)
 *
 * Never send twice: once the whole message has been transmitted, a missing or late "250 accepted" reply means
 * the server has probably delivered it, so such a message is not retried automatically (staff can "Retry now").
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** Minutes to wait before retry n (1-based); the last value repeats. */
const EMAIL_RETRY_MINUTES = [5, 15, 60, 240, 720];
/** Messages whose links expire are not retried after this many minutes. */
const EMAIL_RETRY_WINDOW = ['password_reset' => 55, 'email_verification' => 1380];
const EMAIL_SKIPPED_NOTE = 'SMTP not configured - saved to mail log';
/** Seconds to wait for the reply to the end of the message (servers scan it first; RFC 5321 allows 10 minutes). */
const SMTP_DATA_REPLY_TIMEOUT = ['cli' => 120, 'web' => 60];
const EMAIL_UNCONFIRMED_NOTE = 'not retried automatically, because the mail server had already received the whole message and has probably delivered it. Use Retry now only if the recipient did not get it.';

/** The message was fully transmitted but the server never confirmed it: probably delivered, so never re-send automatically. */
final class SmtpUnconfirmedException extends RuntimeException
{
}

/** SMTP settings from .env. CLI scripts (tests) may override them through $GLOBALS['__smtp_override']; the web never can. */
function smtp_settings(): array
{
    $s = [
        'host' => (string) config('SMTP_HOST', ''),
        'port' => (int) config('SMTP_PORT', 587),
        'secure' => strtolower((string) config('SMTP_SECURE', 'tls')),
        'user' => (string) config('SMTP_USER', ''),
        'pass' => (string) config('SMTP_PASS', ''),
        'timeout' => 15,
        'data_timeout' => SMTP_DATA_REPLY_TIMEOUT[PHP_SAPI === 'cli' ? 'cli' : 'web'],
    ];
    if (PHP_SAPI === 'cli' && is_array($GLOBALS['__smtp_override'] ?? null)) {
        $s = array_merge($s, $GLOBALS['__smtp_override']);
    }
    return $s;
}

function mail_configured(): bool
{
    return smtp_settings()['host'] !== '';
}

function email_address_ok(string $email): bool
{
    return strlen($email) <= 190 && !preg_match('/[\r\n<>]/', $email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Site path ('/courses') or absolute http(s) URL -> absolute URL; anything else -> ''. */
function email_absolute_url(?string $link): string
{
    $link = trim((string) $link);
    if ($link === '') {
        return '';
    }
    if (str_starts_with($link, '/') && !str_starts_with($link, '//')) {
        return app_url(ltrim($link, '/'));
    }
    return preg_match('#^https?://#i', $link) ? $link : '';
}

// ------------------------------------------------------------------
// HTML template
// ------------------------------------------------------------------

/** Plain text -> escaped paragraphs (blank line = new paragraph) for mail_template(). */
function mail_paragraphs(string $text): string
{
    $paras = preg_split('/\n\s*\n/', trim(str_replace(["\r\n", "\r"], "\n", $text)));
    return implode('', array_map(fn($p) => '<p>' . nl2br(e(trim($p)), false) . '</p>', array_filter($paras, fn($p) => trim($p) !== '')));
}

/**
 * Branded, responsive HTML email (table layout + inline styles, works in Gmail/Outlook/Apple Mail).
 * $bodyHtml must already be escaped. Options:
 *   links           [[label, url], …] extra buttons under the main one
 *   transactional   true (default) = account/service mail; false adds the manage-preferences line
 *   manage_url      where a registered student manages email preferences
 *   unsubscribe_url one-click unsubscribe (newsletter); also sent as the List-Unsubscribe header
 *   eyebrow         small label above the title (e.g. "Payment")
 *   preheader       inbox preview text
 */
function mail_template(string $title, string $bodyHtml, ?string $buttonLabel = null, ?string $buttonUrl = null, array $opts = []): string
{
    $font = "'Inter','Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $display = "'Plus Jakarta Sans','Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $site = (string) setting('site_name', 'Laxyam Academy');
    $legal = (string) setting('company_legal_name', 'LAXYAM INNOVATIVE EDUSKIL PRIVATE LIMITED');
    $cin = (string) setting('company_cin', 'U85491DL2026PTC470370');
    $address = (string) (setting('company_registered_address') ?? setting('address', ''));
    $phone = (string) setting('contact_phone', '');
    $email = (string) setting('contact_email', '');
    $home = app_url('');
    $host = (string) (parse_url($home, PHP_URL_HOST) ?: $home);
    $buttonUrl = email_absolute_url($buttonUrl);
    $eyebrow = (string) ($opts['eyebrow'] ?? $site);
    $preheader = (string) ($opts['preheader'] ?? '');
    $unsubscribe = email_absolute_url($opts['unsubscribe_url'] ?? null);
    $manage = email_absolute_url($opts['manage_url'] ?? null);
    $transactional = (bool) ($opts['transactional'] ?? true);

    // Plain <p> from callers get the brand paragraph style
    $bodyHtml = preg_replace('/<p>/i', '<p style="margin:0 0 16px">', $bodyHtml);

    $button = '';
    if ($buttonLabel && $buttonUrl) {
        $button = '<tr><td class="lx-pad" style="padding:10px 40px 6px">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="lx-btn"><tr>'
            . '<td align="center" bgcolor="#0B63F6" style="border-radius:12px;background:#0B63F6;mso-padding-alt:14px 30px">'
            . '<a href="' . e($buttonUrl) . '" target="_blank" style="display:inline-block;padding:14px 30px;font-family:' . $display . ';font-size:15px;font-weight:700;line-height:20px;color:#FFFFFF;text-decoration:none;border-radius:12px;border:1px solid #0B63F6">' . e($buttonLabel) . '</a>'
            . '</td></tr></table></td></tr>';
    }
    $extra = '';
    foreach ((array) ($opts['links'] ?? []) as $l) {
        $url = email_absolute_url(is_array($l) ? (string) ($l[1] ?? '') : '');
        $label = is_array($l) ? trim((string) ($l[0] ?? '')) : '';
        if ($url !== '' && $label !== '') {
            $extra .= '<a href="' . e($url) . '" target="_blank" style="display:inline-block;margin:0 10px 10px 0;padding:11px 20px;font-family:' . $display . ';font-size:14px;font-weight:700;line-height:18px;color:#0B63F6;text-decoration:none;border:1.5px solid #BFD5FD;border-radius:12px;background:#FFFFFF">' . e($label) . '</a>';
        }
    }
    if ($extra !== '') {
        $extra = '<tr><td class="lx-pad" style="padding:12px 40px 0;font-size:0">' . $extra . '</td></tr>';
    }
    $fallback = $button !== ''
        ? '<tr><td class="lx-pad" style="padding:14px 40px 4px;font-family:' . $font . ';font-size:12px;line-height:18px;color:#64748B">If the button does not work, copy this link into your browser:<br><a href="' . e($buttonUrl) . '" style="color:#0B63F6;word-break:break-all">' . e($buttonUrl) . '</a></td></tr>'
        : '';

    $help = [];
    if ($phone !== '') {
        $help[] = 'call <a href="tel:' . e(preg_replace('/[^\d+]/', '', $phone)) . '" style="color:#0B63F6;text-decoration:none;font-weight:600">' . e($phone) . '</a>';
    }
    if ($email !== '') {
        $help[] = 'write to <a href="mailto:' . e($email) . '" style="color:#0B63F6;text-decoration:none;font-weight:600">' . e($email) . '</a>';
    }
    $support = $help
        ? '<tr><td class="lx-pad" style="padding:22px 40px 34px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F5F9FF;border:1px solid #E2EAF4;border-radius:12px"><tr><td style="padding:14px 18px;font-family:' . $font . ';font-size:13px;line-height:20px;color:#475569"><strong style="color:#08264D">Need help?</strong> ' . ucfirst(implode(' or ', $help)) . '.</td></tr></table></td></tr>'
        : '<tr><td style="padding:0 0 30px;font-size:0;line-height:0">&nbsp;</td></tr>';

    $social = [];
    foreach (['social_youtube' => 'YouTube', 'social_instagram' => 'Instagram', 'social_facebook' => 'Facebook', 'social_telegram' => 'Telegram', 'social_x' => 'X', 'social_linkedin' => 'LinkedIn'] as $k => $label) {
        $u = (string) setting($k, '');
        if (preg_match('#^https://#i', $u)) {
            $social[] = '<a href="' . e($u) . '" style="color:#0B63F6;text-decoration:none;font-weight:600">' . $label . '</a>';
        }
    }
    $contact = array_filter([
        $phone !== '' ? e($phone) : '',
        $email !== '' ? '<a href="mailto:' . e($email) . '" style="color:#64748B">' . e($email) . '</a>' : '',
        '<a href="' . e($home) . '" style="color:#64748B">' . e($host) . '</a>',
    ]);
    if ($unsubscribe !== '') {
        $why = 'You are receiving this because you subscribed to ' . e($site) . ' updates. <a href="' . e($unsubscribe) . '" style="color:#0B63F6">Unsubscribe</a>';
    } elseif (!$transactional) {
        $why = 'You are receiving this because of your email preferences at ' . e($site) . '.'
            . ($manage !== '' ? ' <a href="' . e($manage) . '" style="color:#0B63F6">Manage email preferences</a>' : '');
    } else {
        $why = 'This is a service message about your ' . e($site) . ' account or request.';
    }

    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="x-apple-disable-message-reformatting"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
        . '<title>' . e($title) . '</title>'
        . ($unsubscribe !== '' ? '<!--list-unsubscribe:' . e($unsubscribe) . '-->' : '')
        . '<style>body{margin:0;padding:0;width:100%!important;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}table{border-collapse:collapse}img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic}a{color:#0B63F6}'
        . '@media only screen and (max-width:620px){.lx-wrap{padding:16px 8px!important}.lx-pad{padding-left:22px!important;padding-right:22px!important}.lx-h1{font-size:22px!important;line-height:30px!important}.lx-btn{width:100%!important}.lx-btn a{display:block!important}}</style>'
        . '</head><body style="margin:0;padding:0;background:#EEF3FA">'
        . ($preheader !== '' ? '<!--pre--><div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;font-size:1px;line-height:1px;color:#EEF3FA">' . e($preheader) . str_repeat('&#8203;&nbsp;', 40) . '</div><!--/pre-->' : '')
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#EEF3FA"><tr><td align="center" class="lx-wrap" style="padding:32px 12px">'
        . '<!--[if mso]><table role="presentation" width="600" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%">'
        // Logo
        . '<tr><td style="padding:0 6px 18px"><a href="' . e($home) . '" target="_blank"><img src="' . e(app_url('assets/logo.png')) . '" width="165" alt="' . e($site) . '" style="display:block;width:165px;max-width:165px;height:auto;border:0"></a></td></tr>'
        // Card
        . '<tr><td style="background:#FFFFFF;border:1px solid #E2EAF4;border-radius:18px;overflow:hidden">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
        . '<tr><td height="6" bgcolor="#0B63F6" style="height:6px;line-height:6px;font-size:0;background:#0B63F6;background-image:linear-gradient(90deg,#08264D 0%,#0B63F6 55%,#FFC928 100%);border-radius:18px 18px 0 0">&nbsp;</td></tr>'
        . '<tr><td class="lx-pad" style="padding:32px 40px 6px;font-family:' . $display . '">'
        . '<div style="font-size:12px;line-height:16px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#0B63F6">' . e($eyebrow) . '</div>'
        . '<h1 class="lx-h1" style="margin:8px 0 0;font-family:' . $display . ';font-size:25px;line-height:33px;font-weight:800;color:#08264D">' . e($title) . '</h1>'
        . '<div style="width:44px;height:4px;margin-top:14px;border-radius:4px;background:#FFC928;font-size:0;line-height:0">&nbsp;</div>'
        . '</td></tr>'
        . '<tr><td class="lx-pad" style="padding:20px 40px 4px;font-family:' . $font . ';font-size:15px;line-height:25px;color:#334155">' . $bodyHtml . '</td></tr>'
        . $button . $extra . $fallback . $support
        . '</table></td></tr>'
        // Footer
        . '<tr><td align="center" style="padding:22px 18px 6px;font-family:' . $font . ';font-size:12px;line-height:19px;color:#64748B">'
        . '<strong style="color:#08264D">' . e($site) . '</strong> &middot; ' . e($legal) . ' &middot; CIN ' . e($cin) . '<br>'
        . ($address !== '' ? e($address) . '<br>' : '')
        . implode(' &middot; ', $contact)
        . ($social ? '<br>' . implode(' &nbsp;&middot;&nbsp; ', $social) : '')
        . '</td></tr>'
        . '<tr><td align="center" style="padding:6px 18px 0;font-family:' . $font . ';font-size:11px;line-height:17px;color:#94A3B8">' . $why . '</td></tr>'
        . '</table>'
        . '<!--[if mso]></td></tr></table><![endif]-->'
        . '</td></tr></table></body></html>';
}

/** Plain-text alternative of an HTML email (links kept as "label (url)"). */
function mail_html_to_text(string $html): string
{
    $html = preg_replace(['#<!--pre-->.*?<!--/pre-->#s', '#<head\b.*?</head>#si', '#<style\b.*?</style>#si', '#<!--.*?-->#s'], '', $html);
    $html = preg_replace_callback('#<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>#si', function ($m) {
        $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($label === '') {
            return ''; // image-only link (logo)
        }
        if (preg_match('#^(mailto|tel):#i', $url) || str_contains($url, $label)) {
            return preg_match('#^(mailto|tel):#i', $url) ? $label : $url;
        }
        return "{$label}: {$url}";
    }, $html);
    $html = preg_replace(['#<br\s*/?>[ \t]*\n?#i', '#</(p|h1|h2|h3|tr|div|li)>#i'], ["\n", "\n\n"], $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\u{200B}", "\u{00A0}"], ['', ' '], $text);
    $lines = array_map(fn($l) => trim(preg_replace('/[ \t]+/', ' ', $l)), explode("\n", $text));
    return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
}

// ------------------------------------------------------------------
// Sending + delivery log
// ------------------------------------------------------------------

/**
 * Send an email now (logged in email_log). Returns true only when the SMTP server accepted it.
 * $meta: template_key, user_id, to_name, related => ['order', 12], max_attempts (1 = never retry).
 * A failure is retried later by process_email_queue(); without SMTP the message goes to mail.log.
 */
function send_mail(string $to, string $subject, string $html, array $meta = []): bool
{
    $to = trim($to);
    // meta log_html: the copy kept in email_log when the real message holds a secret (e.g. a login code).
    $id = email_log_insert($to, $subject, (string) ($meta['log_html'] ?? $html), $meta, 'sending');
    $GLOBALS['__last_email_log_id'] = $id ?: null;
    if ($id === 0) {
        // No log table (migration not applied yet): still deliver, just without the log.
        return email_address_ok($to) && mail_transport($to, $meta['to_name'] ?? null, $subject, $html)[0] === 'sent';
    }
    if (!email_address_ok($to)) {
        return false; // logged as failed by email_log_insert()
    }
    $result = mail_transport($to, $meta['to_name'] ?? null, $subject, $html, $id);
    return email_log_finish($id, 1, (string) ($meta['template_key'] ?? ''), date('Y-m-d H:i:s'), $result, (int) ($meta['max_attempts'] ?? 0)) === 'sent';
}

/**
 * Add an email to the queue without sending it in this request (bulk mail); the scheduler
 * delivers it. $meta as send_mail(), plus send_now => true to attempt delivery immediately.
 * Returns the email_log id (0 when the log table is missing - the email is then sent directly).
 */
function queue_email(string $to, string $subject, string $html, array $meta = []): int
{
    $to = trim($to);
    $id = email_log_insert($to, $subject, $html, $meta, 'queued');
    if ($id === 0) {
        if (email_address_ok($to)) {
            mail_transport($to, $meta['to_name'] ?? null, $subject, $html);
        }
        return 0;
    }
    if (!empty($meta['send_now'])) {
        email_send_logged($id, true);
    }
    return $id;
}

/** email_log id of the last send_mail() call in this request (null when not logged). */
function last_email_log_id(): ?int
{
    return $GLOBALS['__last_email_log_id'] ?? null;
}

/** Insert a log row; returns its id, or 0 when the table is unavailable. Invalid recipients are stored as failed. */
function email_log_insert(string $to, string $subject, string $html, array $meta, string $status): int
{
    $valid = email_address_ok($to);
    $related = $meta['related'] ?? null;
    $relType = is_array($related) && isset($related[0]) && preg_match('/^[a-z_]{1,40}$/', (string) $related[0]) ? (string) $related[0] : null;
    $relId = $relType !== null && isset($related[1]) && (int) $related[1] > 0 ? (int) $related[1] : null;
    $userId = isset($meta['user_id']) && (int) $meta['user_id'] > 0 ? (int) $meta['user_id'] : null;
    $key = isset($meta['template_key']) && preg_match('/^[a-z0-9_]{1,60}$/', (string) $meta['template_key']) ? (string) $meta['template_key'] : null;
    try {
        db_query(
            'INSERT INTO email_log (template_key, user_id, to_email, to_name, subject, body_html, status, last_error, related_type, related_id, next_attempt_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$key, $userId, mb_substr($to, 0, 190), isset($meta['to_name']) ? mb_substr((string) $meta['to_name'], 0, 160) : null, mb_substr($subject, 0, 255), $html,
             $valid ? $status : 'failed', $valid ? null : 'Invalid recipient address', $relType, $relId,
             $valid && $status === 'queued' ? (string) ($meta['send_at'] ?? date('Y-m-d H:i:s')) : null]
        );
        return (int) db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('[laxyam] email_log insert failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Record the outcome of attempt number $attempts and schedule the next retry.
 * $result = [status, transport, error, final?]; final = true never schedules a retry. Returns the status.
 */
function email_log_finish(int $id, int $attempts, string $templateKey, string $createdAt, array $result, int $maxAttempts = 0): string
{
    [$status, $transport, $error] = $result;
    $next = null;
    if ($status === 'failed' && empty($result[3])) {
        $max = $maxAttempts > 0 ? $maxAttempts : setting_int('email_max_attempts', 5, 1, 10);
        $delay = EMAIL_RETRY_MINUTES[min(max(0, $attempts - 1), count(EMAIL_RETRY_MINUTES) - 1)];
        $nextTs = time() + $delay * 60;
        $window = EMAIL_RETRY_WINDOW[$templateKey] ?? null;
        if ($attempts < $max && (!$window || $nextTs < strtotime($createdAt) + $window * 60)) {
            $next = date('Y-m-d H:i:s', $nextTs);
        }
    }
    try {
        db_query(
            "UPDATE email_log SET status = ?, attempts = ?, transport = ?, last_error = ?, next_attempt_at = ?,
                    sent_at = " . ($status === 'sent' ? 'NOW()' : 'sent_at') . " WHERE id = ?",
            // No "? = 'sent'" in SQL: MySQL 8 rejects comparing a parameter with a text literal (illegal mix of collations),
            // which left delivered emails stuck in 'sending'.
            [$status, min(255, $attempts), $transport, $error !== null ? mb_substr($error, 0, 500) : null, $next, $id]
        );
    } catch (Throwable $e) {
        error_log('[laxyam] email_log update failed: ' . $e->getMessage());
    }
    return $status;
}

/**
 * Deliver one logged email now. Claims the row first so two workers never send it twice.
 * $force (admin "Retry now") ignores next_attempt_at and the attempt limit.
 * Returns the new status, or null when the row could not be claimed.
 */
function email_send_logged(int $id, bool $force = false): ?string
{
    // A worker that died while the message was being handed over (transport 'smtp-data') is not re-sent:
    // the server may already have delivered it. email_queue_settle_interrupted() closes those rows.
    $claimed = db_query(
        "UPDATE email_log SET status = 'sending', transport = NULL, updated_at = NOW()
         WHERE id = ? AND ((status IN ('queued', 'failed') AND " . ($force ? '1 = 1' : 'next_attempt_at IS NOT NULL AND next_attempt_at <= NOW()') . ")
                OR (status = 'sending' AND updated_at < NOW() - INTERVAL 15 MINUTE AND (transport IS NULL OR transport <> 'smtp-data')))",
        [$id]
    )->rowCount();
    if (!$claimed) {
        return null;
    }
    $row = db_one('SELECT * FROM email_log WHERE id = ?', [$id]);
    $attempts = (int) $row['attempts'] + 1;
    if (!email_address_ok((string) $row['to_email'])) {
        return email_log_finish($id, $attempts, '', (string) $row['created_at'], ['failed', null, 'Invalid recipient address'], 1);
    }
    $result = mail_transport((string) $row['to_email'], $row['to_name'], (string) $row['subject'], (string) $row['body_html'], $id);
    // Further automatic retries respect the attempt limit and the link-expiry window.
    return email_log_finish($id, $attempts, (string) $row['template_key'], (string) $row['created_at'], $result);
}

/**
 * Close rows that must not be re-sent automatically: a worker died after handing the whole message to the server,
 * or (written by the code before 2026-11-03) a retry was scheduled after the server's final reply never came -
 * that exact error text, without a step name, is what the old code logged for messages that were probably delivered.
 */
function email_queue_settle_interrupted(): int
{
    $n = db_query(
        "UPDATE email_log SET status = 'failed', transport = 'smtp-unconfirmed', next_attempt_at = NULL,
                last_error = ?, attempts = LEAST(255, attempts + 1)
         WHERE status = 'sending' AND transport = 'smtp-data' AND updated_at < NOW() - INTERVAL 15 MINUTE",
        ['Sending was interrupted after the message was handed to the mail server - ' . EMAIL_UNCONFIRMED_NOTE]
    )->rowCount();
    return $n + db_query(
        "UPDATE email_log SET next_attempt_at = NULL, transport = 'smtp-unconfirmed', last_error = ?
         WHERE status = 'failed' AND next_attempt_at IS NOT NULL AND last_error = 'SMTP error: no reply from server'",
        ['No reply from the mail server - ' . EMAIL_UNCONFIRMED_NOTE]
    )->rowCount();
}

/** Send queued emails and due retries. Run by the scheduler (cron or web fallback). $maxSeconds > 0 stops early. */
function process_email_queue(int $limit = 50, int $maxSeconds = 0): array
{
    $out = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    $start = time();
    try {
        email_queue_settle_interrupted();
        $rows = db_all(
            "SELECT id FROM email_log
             WHERE (status IN ('queued', 'failed') AND next_attempt_at IS NOT NULL AND next_attempt_at <= NOW())
                OR (status = 'sending' AND updated_at < NOW() - INTERVAL 15 MINUTE AND (transport IS NULL OR transport <> 'smtp-data'))
             ORDER BY next_attempt_at, id LIMIT " . max(1, min(500, $limit))
        );
    } catch (Throwable $e) {
        error_log('[laxyam] email queue unavailable: ' . $e->getMessage());
        return $out;
    }
    foreach ($rows as $r) {
        if ($maxSeconds > 0 && time() - $start >= $maxSeconds) {
            break; // the remaining rows stay due and go out in the next run
        }
        try {
            $status = email_send_logged((int) $r['id']);
        } catch (Throwable $e) {
            // One bad row (or a dropped database connection) must not stop the rest of the queue or the other jobs.
            error_log('[laxyam] email queue row ' . (int) $r['id'] . ': ' . $e->getMessage());
            continue;
        }
        if ($status !== null) {
            $out['processed']++;
            $out[$status] = ($out[$status] ?? 0) + 1;
        }
    }
    return $out;
}

/**
 * Hand one message to the transport. Returns [status, transport, error] (+ final = true: never retry):
 * ['sent', 'smtp', null] | ['failed', 'smtp', 'reason'] | ['failed', 'smtp', 'reason', true] | ['skipped', 'log', EMAIL_SKIPPED_NOTE].
 * $logId (email_log row) is marked transport 'smtp-data' while the message itself is being handed over.
 */
function mail_transport(string $to, ?string $toName, string $subject, string $html, ?int $logId = null): array
{
    $from = (string) config('MAIL_FROM', 'info@laxyamacademy.com');
    $fromName = (string) config('MAIL_FROM_NAME', 'Laxyam Academy');
    $text = mail_html_to_text($html);

    if (!mail_configured()) {
        try {
            $dir = PRIVATE_UPLOADS . '/logs';
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            $file = $dir . '/mail.log';
            if (is_file($file) && filesize($file) > 5 * 1024 * 1024) {
                @rename($file, $file . '.1'); // keep one old file; the log holds live links
            }
            file_put_contents($file, sprintf("[%s] To: %s | %s\n%s\n\n", date('c'), $to, $subject, $text), FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            error_log('[laxyam] mail.log write failed: ' . $e->getMessage());
        }
        return ['skipped', 'log', EMAIL_SKIPPED_NOTE];
    }

    try {
        preg_match('/<!--list-unsubscribe:(.*?)-->/', $html, $m);
        $replyTo = (string) setting('contact_email', '');
        smtp_send($to, $subject, $html, $from, $fromName, [
            'text' => $text,
            'to_name' => $toName,
            'reply_to' => email_address_ok($replyTo) && strcasecmp($replyTo, $from) !== 0 ? $replyTo : null,
            'list_unsubscribe' => isset($m[1]) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null,
            'on_data' => $logId ? function () use ($logId): void {
                try {
                    db_query("UPDATE email_log SET transport = 'smtp-data' WHERE id = ? AND status = 'sending'", [$logId]);
                } catch (Throwable) {
                    // Logging only: never stop a send because of it.
                }
            } : null,
        ]);
        return ['sent', 'smtp', null];
    } catch (SmtpUnconfirmedException $e) {
        error_log('[laxyam] mail unconfirmed: ' . $e->getMessage());
        return ['failed', 'smtp-unconfirmed', $e->getMessage() . ' - ' . EMAIL_UNCONFIRMED_NOTE, true];
    } catch (Throwable $e) {
        error_log('[laxyam] mail failed: ' . $e->getMessage());
        return ['failed', 'smtp', $e->getMessage()];
    }
}

/** RFC 2047 encoded display name. */
function mail_encode_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], '', $value)) . '?=';
}

/**
 * Talk SMTP (EHLO, STARTTLS or ssl://, AUTH LOGIN) and send one multipart/alternative message.
 * $opts: text (plain-text part), to_name, reply_to, list_unsubscribe. Throws on any error.
 */
function smtp_send(string $to, string $subject, string $html, string $from, string $fromName, array $opts = []): void
{
    if (!email_address_ok($to) || !email_address_ok($from)) {
        throw new RuntimeException('Invalid sender or recipient address');
    }
    $cfg = smtp_settings();
    $remote = ($cfg['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . (int) $cfg['port'];

    $socket = @stream_socket_client($remote, $errno, $errstr, (float) $cfg['timeout']);
    if (!$socket) {
        throw new RuntimeException("SMTP connect failed ({$cfg['host']}:{$cfg['port']}): " . ($errstr ?: "error {$errno}"));
    }
    stream_set_timeout($socket, (int) $cfg['timeout']);

    $read = function () use ($socket): string {
        $data = '';
        while (($line = fgets($socket, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = function (string $command, array $expect) use ($socket, $read): string {
        fwrite($socket, $command . "\r\n");
        $reply = $read();
        if (!in_array((int) substr($reply, 0, 3), $expect, true)) {
            // Name the step (never the argument: AUTH lines carry credentials). Before the message is sent a
            // missing reply means nothing was delivered, so these errors are retried normally.
            $step = preg_match('/^(EHLO|STARTTLS|AUTH|MAIL|RCPT|DATA|QUIT)\b/', $command, $m) ? $m[1] : 'AUTH';
            throw new RuntimeException('SMTP error: ' . (trim($reply) !== '' ? trim($reply) : "no reply from server to {$step}"));
        }
        return $reply;
    };

    try {
        $greeting = $read();
        if ((int) substr($greeting, 0, 3) !== 220) {
            throw new RuntimeException('SMTP error: ' . (trim($greeting) !== '' ? trim($greeting) : 'no greeting from server'));
        }
        $ehloHost = parse_url(app_url(), PHP_URL_HOST) ?: 'localhost';
        $cmd("EHLO {$ehloHost}", [250]);
        if ($cfg['secure'] === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('STARTTLS failed');
            }
            $cmd("EHLO {$ehloHost}", [250]);
        }
        if ($cfg['user'] !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($cfg['user']), [334]);
            $cmd(base64_encode($cfg['pass']), [235]);
        }
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);

        $boundary = 'lx_' . bin2hex(random_bytes(12));
        $toName = trim((string) ($opts['to_name'] ?? ''));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . mail_encode_header($fromName) . ' <' . $from . '>',
            'To: ' . ($toName !== '' ? mail_encode_header($toName) . ' ' : '') . '<' . $to . '>',
            'Subject: ' . mail_encode_header($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $ehloHost . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        if (!empty($opts['reply_to']) && email_address_ok((string) $opts['reply_to'])) {
            $headers[] = 'Reply-To: <' . $opts['reply_to'] . '>';
        }
        if (!empty($opts['list_unsubscribe']) && preg_match('#^https?://[^\s<>]+$#i', (string) $opts['list_unsubscribe'])) {
            $headers[] = 'List-Unsubscribe: <' . $opts['list_unsubscribe'] . '>';
        }
        $text = (string) ($opts['text'] ?? mail_html_to_text($html));
        $body = implode("\r\n", $headers) . "\r\n\r\n"
            . "This is a multi-part message in MIME format.\r\n\r\n"
            . '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
            . '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
            . '--' . $boundary . '--';

        // Write everything; a short write means the server never got the whole message (nothing delivered: retry).
        $writeAll = function (string $data) use ($socket): void {
            for ($done = 0, $len = strlen($data); $done < $len; $done += $n) {
                $n = @fwrite($socket, substr($data, $done, 65536));
                if ($n === false || $n === 0) {
                    throw new RuntimeException('SMTP error: the connection broke while sending the message (it was not delivered)');
                }
            }
        };
        $writeAll($body);
        // From the end-of-message marker on, the server may deliver the message even if its reply never reaches
        // us, so a missing reply must not lead to an automatic re-send (that is how one enquiry email arrived again and again).
        if (is_callable($opts['on_data'] ?? null)) {
            ($opts['on_data'])();
        }
        $wait = max(1, (int) $cfg['data_timeout']);
        if (PHP_SAPI !== 'cli' && function_exists('set_time_limit')) {
            @set_time_limit($wait + 30); // keep the request alive while the server scans the message
        }
        stream_set_timeout($socket, $wait);
        $writeAll("\r\n.\r\n");
        $reply = $read();
        if (trim($reply) === '') {
            $why = !empty(stream_get_meta_data($socket)['timed_out']) ? "no reply within {$wait} seconds" : 'the connection closed without a reply';
            throw new SmtpUnconfirmedException("The mail server received the message but did not confirm it ({$why})");
        }
        if ((int) substr($reply, 0, 3) !== 250) {
            throw new RuntimeException('SMTP error: ' . trim($reply)); // explicit rejection: not delivered, safe to retry
        }
        stream_set_timeout($socket, (int) $cfg['timeout']);
        try {
            $cmd('QUIT', [221]);
        } catch (Throwable) {
            // The message was accepted; a sloppy QUIT reply does not matter.
        }
    } finally {
        fclose($socket);
    }
}
