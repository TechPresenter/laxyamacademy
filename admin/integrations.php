<?php
// SEO & Integrations → one screen per integration (integrations.php?tab=…).
// Secrets come from Keys & Credentials (keys.php, encrypted) or .env and are never shown in full. Public IDs (GA4, GTM, Meta Pixel, Google Ads)
// are stored in settings and printed on public pages by index.php. Test buttons make a real call
// (Razorpay / Cashfree API, SMTP via the mailer, Google reCAPTCHA siteverify, WhatsApp Cloud API) and their
// result is kept as int_check_<name> so the dashboard shows the last outcome.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/mailer.php';
require_once APP_ROOT . '/includes/payments.php';
require_once APP_ROOT . '/includes/notify.php';
require_once APP_ROOT . '/includes/storage.php';
require_once APP_ROOT . '/includes/admin-reports.php';
require_once APP_ROOT . '/includes/chatbot.php';

$me = require_staff_page();

const INT_TABS = [
    'overview' => ['Integration Dashboard', 'grid', 'Connection status of every outside service the academy uses.'],
    'payments' => ['Payment Gateways', 'rupee', 'Active gateway (Cashfree or Razorpay), keys, mode, connection test and the webhook URL.'],
    'email' => ['SMTP / Email', 'mail', 'Outgoing email server and recent delivery health.'],
    'analytics' => ['Analytics & Ads Tags', 'chart', 'Google Analytics 4, Google Tag Manager, Meta Pixel and Google Ads conversion tags.'],
    'whatsapp' => ['WhatsApp Business API', 'whatsapp', 'WhatsApp Cloud API credentials, template messages and click-to-chat number.'],
    'meetings' => ['Google Meet & Zoom', 'video', 'How live class meeting links work.'],
    'youtube' => ['YouTube', 'youtube', 'Channel link and the YouTube videos shown on the website.'],
    'login' => ['Student Login', 'key', 'Sign in with Google and login with a mobile code (WhatsApp / SMS): status and setup.'],
    'app' => ['Android App', 'phone', 'Publish the website as an Android app on Google Play (Trusted Web Activity) and link the two.'],
    'recaptcha' => ['reCAPTCHA', 'shield', 'Google reCAPTCHA v3 spam protection for public forms.'],
    'webhooks' => ['Webhooks', 'zap', 'Incoming webhook endpoints and the latest deliveries.'],
    'logs' => ['Integration Logs', 'log', 'Webhook deliveries, API calls, failed emails and connection tests.'],
];
/** Public tracking IDs: key => [label, regex, placeholder, group] */
const TRACKING = [
    'ga_measurement_id' => ['GA4 measurement ID', '/^G-[A-Z0-9]{4,20}$/', 'G-XXXXXXXXXX', 'ga'],
    'gtm_container_id' => ['GTM container ID', '/^GTM-[A-Z0-9]{4,12}$/', 'GTM-XXXXXXX', 'gtm'],
    'meta_pixel_id' => ['Meta Pixel ID', '/^\d{8,20}$/', '1234567890123456', 'pixel'],
    'google_ads_id' => ['Google Ads conversion ID', '/^AW-\d{6,15}$/', 'AW-123456789', 'ads'],
    'google_ads_label' => ['Purchase conversion label', '/^[A-Za-z0-9_-]{6,40}$/', 'AbC-D_efG-h12_34-567', 'ads'],
];

$tab = current_tab(INT_TABS);
// Integration Logs are also open to staff who may read logs; everything else needs settings.manage.
require_permission($tab === 'logs' && can('logs.view') ? 'logs.view' : 'settings.manage');

function int_mask(string $v): string
{
    return $v === '' ? '' : substr($v, 0, 6) . str_repeat('•', 8);
}

function int_set(string $key, string $value, int $by, string $group = 'integrations'): void
{
    db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public, updated_by) VALUES (?, ?, ?, 0, ?)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)", [$key, $value, $group, $by]);
}

/** Store the outcome of a connection test (shown on the dashboard and in the logs). */
function int_check(string $name, bool $ok, string $msg): void
{
    db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public) VALUES (?, ?, 'integrations', 0)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", ['int_check_' . $name, json_encode(['ok' => $ok, 'at' => date('c'), 'msg' => mb_substr($msg, 0, 200)])]);
}

// ------------------------------------------------------------------ Actions
if (admin_post()) {
    require_permission('settings.manage');
    $act = (string) ($_POST['_action'] ?? '');
    $back = 'integrations.php?tab=' . (array_key_exists((string) ($_POST['tab'] ?? ''), INT_TABS) ? $_POST['tab'] : 'overview');

    if ($act === 'tag_save' || $act === 'tag_remove') {
        $group = (string) ($_POST['group'] ?? '');
        $keys = array_keys(array_filter(TRACKING, fn($t) => $t[3] === $group));
        if (!$keys) {
            redirect_to($back);
        }
        $errors = [];
        $values = [];
        foreach ($keys as $k) {
            $v = $act === 'tag_remove' ? '' : trim((string) ($_POST[$k] ?? ''));
            if ($v !== '' && !preg_match(TRACKING[$k][1], $v)) {
                $errors[] = TRACKING[$k][0] . ' should look like ' . TRACKING[$k][2] . '.';
            }
            $values[$k] = $v;
        }
        if ($group === 'ads' && $values['google_ads_label'] !== '' && $values['google_ads_id'] === '') {
            $errors[] = 'Add the conversion ID (AW-…) together with the label.';
        }
        if ($errors) {
            flash('error', implode(' ', $errors) . ' Nothing was saved.');
            redirect_to($back . '#' . $group);
        }
        foreach ($values as $k => $v) {
            int_set($k, $v, (int) $me['id']);
        }
        audit_log($act === 'tag_remove' ? 'integration_disconnected' : 'integrations_updated', 'site_settings', $group, ['keys' => $keys], 'warning');
        flash('success', $act === 'tag_remove' ? 'Tag removed from the website.' : 'Saved. The tag now loads on every public page (not in the admin panel).');
        redirect_to($back . '#' . $group);
    }

    if ($act === 'test_cashfree') {
        if (!cashfree_configured()) {
            int_check('cashfree', false, 'CASHFREE_APP_ID / CASHFREE_SECRET_KEY not set (Keys & Credentials)');
            flash('error', 'Cashfree keys are not set yet - add them in Keys & Credentials, then test again.');
        } elseif (!rate_limit('int-cf-test', (string) $me['id'], 10, 600)) {
            flash('error', 'Too many tests - wait a few minutes.');
        } else {
            // Real authenticated call: Get Order for an id that cannot exist. 404 = keys accepted, 401 = rejected.
            try {
                [$status, $data] = cashfree_request('GET', '/orders/LXTEST' . strtoupper(bin2hex(random_bytes(6))), null, 10);
                $ok = $status === 404 || $status === 200;
                $msg = $ok ? 'Credentials accepted by Cashfree (' . (payment_live() ? 'production' : 'sandbox') . ')'
                    : ($status === 401 || $status === 403 ? 'Cashfree rejected the App ID / secret key (HTTP ' . $status . ') - check they are ' . (payment_live() ? 'production' : 'sandbox (test)') . ' keys'
                        : "Unexpected response from Cashfree (HTTP {$status})" . (isset($data['message']) ? ': ' . mb_substr((string) $data['message'], 0, 120) : ''));
            } catch (RuntimeException $e) {
                [$ok, $msg] = [false, 'Could not reach Cashfree: ' . $e->getMessage()];
            }
            int_check('cashfree', $ok, $msg);
            flash($ok ? 'success' : 'error', $msg . '.');
        }
        redirect_to($back);
    }

    if ($act === 'test_razorpay') {
        if (!razorpay_configured()) {
            int_check('razorpay', false, 'RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET not set (Keys & Credentials)');
            flash('error', 'Razorpay keys are not set yet - add them in Keys & Credentials, then test again.');
        } else {
            try {
                [$status] = http_json('GET', 'https://api.razorpay.com/v1/orders?count=1', null, [], config('RAZORPAY_KEY_ID') . ':' . config('RAZORPAY_KEY_SECRET'));
                $ok = $status === 200;
                $msg = $ok ? 'Credentials accepted by Razorpay' : ($status === 401 ? 'Razorpay rejected the key ID/secret (401)' : "Unexpected response from Razorpay (HTTP {$status})");
            } catch (RuntimeException $e) {
                [$ok, $msg] = [false, 'Could not reach Razorpay: ' . $e->getMessage()];
            }
            int_check('razorpay', $ok, $msg);
            flash($ok ? 'success' : 'error', $msg . '.');
        }
        redirect_to($back);
    }

    if ($act === 'test_smtp') {
        if (!rate_limit('int-smtp-test', (string) $me['id'], 5, 600)) {
            flash('error', 'Too many test emails - wait a few minutes.');
            redirect_to($back);
        }
        $html = mail_template('Email delivery test', '<p>This test was sent from <strong>Admin → SEO &amp; Integrations → SMTP / Email</strong> by ' . e($me['name']) . '.</p><p>If you can read it, outgoing email works.</p>');
        $sent = send_mail((string) $me['email'], 'Laxyam Academy - SMTP test', $html, ['template_key' => 'smtp_test', 'user_id' => (int) $me['id'], 'to_name' => $me['name']]);
        $row = ($id = last_email_log_id()) ? db_one('SELECT status, last_error FROM email_log WHERE id = ?', [$id]) : null;
        if (!mail_configured()) {
            int_check('smtp', false, 'SMTP_HOST is not set - the test was written to the mail log, not delivered');
            flash('warning', 'SMTP is not configured (Keys & Credentials → Email), so the test email was written to the server mail log (uploads/private/logs/mail.log) instead of being delivered.');
        } else {
            $msg = $sent ? 'Test email accepted by ' . config('SMTP_HOST') . ' for ' . $me['email'] : 'Sending failed: ' . ($row['last_error'] ?? 'see Email Logs');
            int_check('smtp', $sent, $msg);
            flash($sent ? 'success' : 'error', $sent ? $msg . '. Check the inbox (and spam folder).' : $msg);
        }
        redirect_to($back);
    }

    if ($act === 'test_recaptcha') {
        $secret = (string) config('RECAPTCHA_SECRET', '');
        if ($secret === '') {
            int_check('recaptcha', false, 'RECAPTCHA_SECRET not set (Keys & Credentials)');
            flash('error', 'The reCAPTCHA secret is not set yet - add it in Keys & Credentials.');
            redirect_to($back);
        }
        // Google answers a dummy token with "invalid-input-response" when the secret is valid and
        // "invalid-input-secret" when it is not - a real check of the secret without a browser.
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 8, 'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query(['secret' => $secret, 'response' => 'admin-connection-test'])]]);
        $res = json_decode((string) @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $ctx), true);
        $codes = (array) ($res['error-codes'] ?? []);
        if (!is_array($res)) {
            [$ok, $msg] = [false, 'Could not reach Google reCAPTCHA from this server'];
        } elseif (in_array('invalid-input-secret', $codes, true) || in_array('missing-input-secret', $codes, true)) {
            [$ok, $msg] = [false, 'Google rejected RECAPTCHA_SECRET (invalid secret key)'];
        } else {
            [$ok, $msg] = [true, 'Secret key accepted by Google'];
        }
        int_check('recaptcha', $ok, $msg);
        flash($ok ? 'success' : 'error', $msg . '.');
        redirect_to($back);
    }

    if ($act === 'recaptcha_save') {
        $key = trim((string) ($_POST['recaptcha_site_key'] ?? ''));
        $score = (float) ($_POST['recaptcha_min_score'] ?? 0.5);
        if ($key !== '' && !preg_match('/^[A-Za-z0-9_-]{20,100}$/', $key)) {
            flash('error', 'The site key looks invalid (copy it from Google reCAPTCHA admin → Settings).');
            redirect_to($back);
        }
        int_set('recaptcha_site_key', $key, (int) $me['id']);
        int_set('recaptcha_min_score', number_format(min(0.9, max(0.1, $score)), 1, '.', ''), (int) $me['id']);
        audit_log('recaptcha_updated', 'site_settings', 'recaptcha', [], 'warning');
        flash('success', $key === '' ? 'reCAPTCHA switched off.' : 'reCAPTCHA settings saved.' . ((string) config('RECAPTCHA_SECRET', '') === '' ? ' It stays off until the secret key is added in Keys & Credentials.' : ''));
        redirect_to($back);
    }

    if ($act === 'test_whatsapp') {
        $phone = trim((string) ($_POST['phone'] ?? ''));
        if ((string) config('WHATSAPP_TOKEN', '') === '' || (string) config('WHATSAPP_PHONE_ID', '') === '') {
            flash('error', 'Add the Meta token and phone number ID in Keys & Credentials first.');
        } elseif (!whatsapp_number($phone)) {
            flash('error', 'Enter a valid mobile number (10 digits or with country code).');
        } elseif (!rate_limit('int-wa-test', (string) $me['id'], 5, 600)) {
            flash('error', 'Too many test messages - wait a few minutes.');
        } else {
            // "hello_world" is Meta's pre-approved sample template on every WhatsApp Business account.
            [$ok, $msg] = whatsapp_send($phone, ['type' => 'template', 'template' => ['name' => 'hello_world', 'language' => ['code' => 'en_US']]]);
            int_check('whatsapp', $ok, $ok ? 'Test template accepted by WhatsApp' : 'WhatsApp rejected the message: ' . $msg);
            flash($ok ? 'success' : 'error', $ok ? 'Test message accepted by WhatsApp.' : 'WhatsApp rejected the message: ' . $msg);
        }
        redirect_to($back);
    }

    if ($act === 'app_save') {
        $pkg = strtolower(trim((string) ($_POST['android_package'] ?? '')));
        $prints = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', strtoupper((string) ($_POST['android_sha256'] ?? ''))))));
        $store = trim((string) ($_POST['app_android_url'] ?? ''));
        $error = match (true) {
            $pkg !== '' && !preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $pkg) => 'The package name looks like com.laxyamacademy.app (lowercase, dots between parts).',
            (bool) array_filter($prints, fn($f) => !preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $f)) => 'A SHA-256 fingerprint is 32 pairs like AB:CD:… - copy it from Play Console → App integrity.',
            $store !== '' && !preg_match('#^https://play\.google\.com/store/apps/details\?id=[A-Za-z0-9._]+#', $store) => 'Use the Play Store link, e.g. https://play.google.com/store/apps/details?id=' . ($pkg ?: 'com.laxyamacademy.app'),
            default => null,
        };
        if ($error) {
            flash('error', $error);
            redirect_to($back);
        }
        int_set('android_package', $pkg, (int) $me['id'], 'app');
        int_set('android_sha256', implode(',', $prints), (int) $me['id'], 'app');
        // Same setting as Settings → Mobile apps: the footer shows the Google Play button once it is set.
        db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public, updated_by) VALUES ('app_android_url', ?, 'general', 1, ?)
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)", [$store, $me['id']]);
        cache_clear(); // the footer reads the Play Store link from the cached site layout
        audit_log('android_app_updated', 'site_settings', 'app', ['package' => $pkg, 'fingerprints' => count($prints)]);
        flash('success', 'Android app settings saved.' . ($pkg && $prints ? ' assetlinks.json is live.' : ''));
        redirect_to($back);
    }

    if ($act === 'youtube_save') {
        $url = trim((string) ($_POST['channel_url'] ?? ''));
        if ($url !== '' && !preg_match('#^https://(www\.|m\.)?youtube\.com/(@[\w.\-]{2,100}|channel/[\w-]{10,40}|c/[\w.\-]{2,100}|user/[\w.\-]{2,100})/?$#i', $url)) {
            flash('error', 'Use your channel address, e.g. https://www.youtube.com/@LaxyamAcademy');
            redirect_to($back);
        }
        int_set('social_youtube', $url, (int) $me['id'], 'social');
        $applied = false;
        if (!empty($_POST['apply_home'])) {
            $sec = db_one("SELECT content FROM cms_sections WHERE section_key = 'videos_youtube'");
            if ($sec) {
                $content = json_decode_array($sec['content']);
                $content['channel_url'] = $url;
                db_query("UPDATE cms_sections SET content = ? WHERE section_key = 'videos_youtube'", [json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                $applied = true;
            }
        }
        audit_log('youtube_channel_updated', 'site_settings', 'social_youtube', ['homepage' => $applied]);
        flash('success', 'YouTube channel saved' . ($applied ? ' and used on the homepage videos section.' : ' for the footer and social links.'));
        redirect_to($back);
    }
    redirect_to($back);
}

// ------------------------------------------------------------------ Status of every integration
$s = settings_all();
$check = fn(string $n): array => json_decode_array($s['int_check_' . $n] ?? null);
$env = fn(string $k): bool => trim((string) config($k, '')) !== '';
$tag = fn(string $k): ?string => preg_match(TRACKING[$k][1], (string) ($s[$k] ?? '')) ? (string) $s[$k] : null;

$hook = db_one("SELECT received_at, status, error FROM webhook_events ORDER BY id DESC LIMIT 1");
$hookFails = (int) db_value("SELECT COUNT(*) FROM webhook_events WHERE status = 'failed' AND received_at > NOW() - INTERVAL 30 DAY");
$mail30 = db_one("SELECT COUNT(*) n, SUM(status = 'sent') sent, SUM(status = 'failed') failed, SUM(status = 'skipped') skipped, SUM(status IN ('queued','sending')) queued FROM email_log WHERE created_at > NOW() - INTERVAL 30 DAY");
$lastMailError = db_one("SELECT last_error, updated_at FROM email_log WHERE status = 'failed' AND last_error IS NOT NULL ORDER BY id DESC LIMIT 1");
$waTemplates = (int) db_value("SELECT COUNT(*) FROM site_settings WHERE setting_key LIKE 'wa\\_tpl\\_%' AND setting_value <> ''");
$ytSection = json_decode_array((string) db_value("SELECT content FROM cms_sections WHERE section_key = 'videos_youtube'"));
$ytVideos = (int) db_value("SELECT COUNT(*) FROM media_videos WHERE platform = 'youtube' AND is_active = 1");
$apiKeys = (int) db_value('SELECT COUNT(*) FROM api_keys WHERE revoked_at IS NULL');
$gscReady = (string) ($s['gsc_property'] ?? '') !== '';

/**
 * Status per integration: [state, label] with state ∈ ok | partial | off | error | manual.
 * "error" = the last real test failed.
 */
$state = function (bool $configured, ?array $test = null, bool $partial = false): array {
    if ($test && empty($test['ok']) && $configured) {
        return ['error', 'Last test failed'];
    }
    if ($configured) {
        return ['ok', $test && !empty($test['ok']) ? 'Connected' : 'Configured'];
    }
    return $partial ? ['partial', 'Partly set up'] : ['off', 'Not configured'];
};
// Payment gateways: the active one (PAYMENT_PROVIDER) drives checkout; both are shown on the Payment Gateways tab.
$providerKnown = in_array(payment_provider(), ['razorpay', 'cashfree'], true);
$gw = payment_gateway_status($providerKnown ? payment_provider() : 'razorpay');
$gwOther = payment_gateway_status($gw['provider'] === 'cashfree' ? 'razorpay' : 'cashfree');
$gwHookFails = (int) db_value("SELECT COUNT(*) FROM webhook_events WHERE provider = ? AND status = 'failed' AND received_at > NOW() - INTERVAL 30 DAY", [$gw['provider']]);
$gwState = function (array $g, bool $active) use ($state, $check): array {
    if (!$active) {
        return $g['configured'] ? ['partial', 'Keys set · not in use'] : ['off', 'Not in use'];
    }
    return $state($g['configured'], $check($g['provider']), count($g['missing']) === 1);
};
$waEnv = $env('WHATSAPP_TOKEN') && $env('WHATSAPP_PHONE_ID');
$recaptchaKey = (string) ($s['recaptcha_site_key'] ?? '') !== '';
$cards = [
    'gateway' => [$gw['label'] . ' payments', 'rupee', 't-blue', 'payments',
        $providerKnown ? $gwState($gw, true) : ['error', 'Unknown PAYMENT_PROVIDER'],
        !$providerKnown ? 'PAYMENT_PROVIDER=' . payment_provider() . ' is not supported - use cashfree or razorpay'
            : ($gw['configured'] ? 'Active gateway · ' . ($gw['mode'] === 'live' ? 'live mode' : 'sandbox (test payments)') : 'Add ' . implode(' and ', $gw['missing']) . ' in Keys & Credentials'),
        $check($gw['provider'])],
    'webhook' => [$gw['label'] . ' webhooks', 'zap', 't-green', 'webhooks', $gwHookFails ? ['error', $gwHookFails . ' failed (30 days)'] : $state($gw['webhook_ready']),
        $gw['last_webhook'] ? 'Last event ' . fmt_ago($gw['last_webhook']['received_at']) . ' (' . $gw['last_webhook']['status'] . ')' : 'No webhook received yet', null],
    'smtp' => ['SMTP email', 'mail', 't-purple', 'email', (int) ($mail30['failed'] ?? 0) && mail_configured() ? ['error', (int) $mail30['failed'] . ' failed (30 days)'] : $state(mail_configured(), $check('smtp')),
        mail_configured() ? 'Server ' . config('SMTP_HOST') . ':' . config('SMTP_PORT', 587) : 'Emails are written to the mail log until SMTP_HOST is set', $check('smtp')],
    'ga' => ['Google Analytics 4', 'chart', 't-amber', 'analytics', $state($tag('ga_measurement_id') !== null), $tag('ga_measurement_id') ?? 'No measurement ID', null],
    'gtm' => ['Google Tag Manager', 'code', 't-blue', 'analytics', $state($tag('gtm_container_id') !== null), $tag('gtm_container_id') ?? 'No container ID', null],
    'pixel' => ['Meta Pixel', 'facebook', 't-indigo', 'analytics', $state($tag('meta_pixel_id') !== null), $tag('meta_pixel_id') ?? 'No pixel ID', null],
    'ads' => ['Google Ads conversion', 'target', 't-orange', 'analytics', $state($tag('google_ads_id') !== null && $tag('google_ads_label') !== null, null, $tag('google_ads_id') !== null),
        $tag('google_ads_id') ? $tag('google_ads_id') . ($tag('google_ads_label') ? ' · purchase label set' : ' · add the purchase label') : 'No conversion ID', null],
    'gsc' => ['Google Search Console', 'search', 't-green', 'indexing.php', $state((string) ($s['gsc_verification'] ?? '') !== '' || $gscReady, null),
        ($gscReady ? 'Property ' . $s['gsc_property'] : 'No property') . ((string) ($s['gsc_verification'] ?? '') !== '' ? ' · verification tag on' : ''), null],
    'whatsapp' => ['WhatsApp Business API', 'whatsapp', 't-green', 'whatsapp', $waEnv && ($s['whatsapp_enabled'] ?? '0') === '1' ? $state(true, $check('whatsapp')) : ($waEnv ? ['partial', 'Sending switched off'] : ['off', 'Not configured']),
        $waEnv ? $waTemplates . ' message template' . ($waTemplates === 1 ? '' : 's') . ' mapped' : 'Add the WhatsApp keys in Keys & Credentials', $check('whatsapp')],
    'meetings' => ['Google Meet & Zoom', 'video', 't-red', 'meetings', ['manual', 'Links per class'], 'Meeting links are pasted on each live class', null],
    'youtube' => ['YouTube', 'youtube', 't-red', 'youtube', $state((string) ($s['social_youtube'] ?? '') !== '' || !empty($ytSection['channel_url'])), $ytVideos . ' video' . ($ytVideos === 1 ? '' : 's') . ' on the website', null],
    'recaptcha' => ['reCAPTCHA v3', 'shield', 't-teal', 'recaptcha', $state(recaptcha_enabled(), $check('recaptcha'), $recaptchaKey || $env('RECAPTCHA_SECRET')),
        recaptcha_enabled() ? 'Protecting 4 public forms' : ($recaptchaKey ? 'Secret key missing (Keys & Credentials)' : 'Site key and secret not set'), $check('recaptcha')],
    'storage' => ['Cloud storage (S3)', 'server', 't-navy', 'storage.php', s3_active() ? ['ok', 'Active'] : (s3_config() ? ['partial', 'Keys set, not active'] : ['off', 'Local disk']), s3_active() ? 'Bucket ' . config('S3_BUCKET') : 'Files are stored on this server', null],
    'api' => ['API keys', 'key', 't-purple', 'api-keys.php', $apiKeys ? ['ok', $apiKeys . ' active key' . ($apiKeys === 1 ? '' : 's')] : ['off', 'No keys'], 'Read/write access for other systems', null],
    'ai' => ['AI chatbot (OpenAI / Claude)', 'chat', 't-pink', 'chatbot.php?tab=settings', $state(chatbot_ai_configured()),
        chatbot_ai_configured() ? (chatbot_ai_provider() === 'openai' ? 'OpenAI' : 'Claude') . ' answers free-form questions'
            : (chatbot_ai_key() !== '' ? 'AI answers are switched off in Chatbot settings'
            : (($env('OPENAI_API_KEY') || $env('ANTHROPIC_API_KEY')) ? '"Answer with" is ' . (chatbot_ai_provider() === 'openai' ? 'OpenAI' : 'Claude') . ' but its key is missing' : 'Uses built-in answers without a key')), null],
];
$pill = function (array $st): string {
    $cls = ['ok' => 'b-green', 'partial' => 'b-amber', 'off' => 'b-gray', 'error' => 'b-red', 'manual' => 'b-blue'][$st[0]] ?? 'b-gray';
    return '<span class="badge ' . $cls . ' ig-pill ig-' . e($st[0]) . '">' . e($st[1]) . '</span>';
};
$lastTest = fn(array $c): string => $c ? '<span class="ig-test ' . ($c['ok'] ? 'ok' : 'bad') . '">' . icon($c['ok'] ? 'check-circle' : 'alert') . ' Tested ' . e(fmt_ago(date('Y-m-d H:i:s', strtotime((string) $c['at'])))) . ': ' . e((string) $c['msg']) . '</span>' : '<span class="ig-test muted">' . icon('clock') . ' Not tested yet</span>';
$href = fn(string $target) => str_contains($target, '.php') ? $target : 'integrations.php?tab=' . $target;
$copy = fn(string $value, string $label = 'Copy') => '<span class="ig-copy"><code>' . e($value) . '</code><button type="button" class="btn btn-ghost btn-sm" data-copy="' . e($value) . '">' . icon('copy') . ' ' . e($label) . '</button></span>';
$tabField = '<input type="hidden" name="tab" value="' . e($tab) . '">';

[$title, $tabIcon, $subtitle] = INT_TABS[$tab];
admin_start($title, null, 'integrations', ['head' => true, 'subtitle' => $subtitle]);
// Fallback strip (hidden by admin.js when the module tab bar already lists every screen).
$strip = [];
foreach (INT_TABS as $k => [$t, $ico]) {
    $strip[$k] = ['label' => $t, 'icon' => $ico];
}
echo admin_tabs($strip, $tab, ['label' => 'Integrations']);

switch ($tab) {
    // ------------------------------------------------------------------ Dashboard
    case 'overview':
        $counts = array_count_values(array_map(fn($c) => $c[4][0], $cards));
        echo int_summary($counts);
        echo '<section class="ig-grid" aria-label="Integrations">';
        foreach ($cards as $id => [$name, $ico, $tone, $target, $st, $detail, $test]) {
            echo '<article class="card ig-card" id="ig-' . e($id) . '"><div class="ig-top"><span class="tile ' . e($tone) . '">' . icon($ico) . '</span><div class="ig-name"><strong>' . e($name) . '</strong>' . $pill($st) . '</div></div>'
                . '<p class="ig-detail">' . e($detail) . '</p>' . ($test !== null ? $lastTest($test) : '')
                . '<div class="ig-acts"><a class="btn btn-light btn-sm" href="' . e($href($target)) . '">' . icon('settings') . ' ' . ($st[0] === 'off' ? 'Set up' : 'Manage') . '</a></div></article>';
        }
        echo '</section>';
        // Connection status table
        $rows = [];
        foreach ($cards as $id => [$name, , , $target, $st, $detail, $test]) {
            $rows[] = [$name, $pill($st), e($detail), $test ? $lastTest($test) : '<span class="muted">—</span>', '<a class="btn btn-ghost btn-sm" href="' . e($href($target)) . '">' . icon('right') . ' Open</a>'];
        }
        echo '<section class="card mt"><div class="card-head"><h2>' . icon('activity') . ' Connection status</h2><span class="muted" style="font-size:12.5px">Live from Keys &amp; Credentials, .env and settings · secrets never shown</span></div><div class="table-wrap"><table class="t stack"><thead><tr><th>Service</th><th>Status</th><th>Details</th><th>Last test</th><th><span class="sr-only">Open</span></th></tr></thead><tbody>';
        foreach ($rows as [$n, $p, $d, $t, $a]) {
            echo '<tr><td data-label="Service"><strong>' . e($n) . '</strong></td><td data-label="Status">' . $p . '</td><td data-label="Details" class="muted">' . $d . '</td><td data-label="Last test">' . $t . '</td><td class="actions">' . $a . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
        break;

    // ------------------------------------------------------------------ Payment gateways
    case 'payments':
        $present = fn(string $k, bool $mask = false): string => $env($k)
            ? ($mask ? '<code>' . e(int_mask((string) config($k))) . '</code>' : '<span class="badge b-green">Set</span> (hidden)')
            : '<span class="badge b-gray">Missing</span> <code>' . e($k) . '</code>';
        $ago = fn(?array $x): string => $x ? e(fmt_ago(date('Y-m-d H:i:s', strtotime((string) ($x['at'] ?? $x['received_at']))))) : '';
        // One gateway's keys, mode, activity, last API error and connection test.
        $gatewayCard = function (array $g, bool $active) use ($present, $ago, $pill, $gwState, $lastTest, $check, $tabField): string {
            $p = $g['provider'];
            $tx = db_one("SELECT COUNT(*) n, SUM(status = 'captured') ok, SUM(status = 'failed') bad FROM payment_transactions WHERE provider = ? AND created_at > NOW() - INTERVAL 30 DAY AND status <> 'created'", [$p]);
            $rows = $p === 'cashfree'
                ? '<dt>App ID</dt><dd>' . $present('CASHFREE_APP_ID', true) . '</dd><dt>Secret key</dt><dd>' . $present('CASHFREE_SECRET_KEY') . '</dd>'
                    . '<dt>API</dt><dd>' . ($g['api_override'] ? '<span class="badge b-amber">Local test server</span> <code>' . e($g['api_base']) . '</code> (<code>CASHFREE_API_BASE</code>, ignored in production)' : '<code>' . e($g['api_base']) . '</code>') . ' · version <code>' . e($g['api_version']) . '</code></dd>'
                : '<dt>Key ID</dt><dd>' . $present('RAZORPAY_KEY_ID', true) . '</dd><dt>Key secret</dt><dd>' . $present('RAZORPAY_KEY_SECRET') . '</dd>';
            $rows .= '<dt>Mode</dt><dd>' . ($g['mode'] === 'live' ? '<span class="badge b-green">Live</span> real money' : '<span class="badge b-amber">Sandbox</span> test payments only (<code>PAYMENT_MODE</code>)') . '</dd>'
                . '<dt>Last 30 days</dt><dd>' . ((int) $tx['n'] ? number_format((int) $tx['ok']) . ' captured · ' . number_format((int) $tx['bad']) . ' failed' : 'No payment attempts')
                . (can('payments.view') ? ' · <a href="payments.php?tab=transactions&amp;provider=' . e($p) . '">Transactions</a>' : '') . '</dd>';
            $err = $g['last_error'] ? '<p class="ig-err">' . icon('alert') . ' Last API error ' . $ago($g['last_error']) . ': ' . e((string) ($g['last_error']['msg'] ?? '')) . '</p>' : '';
            $test = $g['configured']
                ? '<form method="post" class="inline-form">' . csrf_field() . $tabField . '<input type="hidden" name="_action" value="test_' . e($p) . '"><button class="btn ' . ($active ? 'btn-primary' : 'btn-light') . '" data-busy="Testing…">' . icon('check') . ' Test connection</button></form>'
                : '<span class="muted ig-why">' . icon('info') . ' <span>Add ' . implode(' and ', array_map(fn($k) => '<code>' . e($k) . '</code>', $g['missing'])) . ' in <a href="keys.php">Keys &amp; Credentials</a>, then test the connection here.</span></span>';
            return '<section class="card" id="gw-' . e($p) . '"><div class="card-head"><h2>' . icon('rupee') . ' ' . e($g['label']) . ($active ? ' <span class="badge b-blue">Active at checkout</span>' : '') . '</h2>' . $pill($gwState($g, $active)) . '</div>'
                . '<div class="card-pad"><dl class="kv">' . $rows . '</dl>' . $err . '<div class="ig-testrow">' . $lastTest($check($p)) . '</div></div>'
                . '<div class="form-actions">' . $test . '</div></section>';
        };
        $hookCard = function (array $g) use ($copy, $present, $ago, $pill, $cards, $gwHookFails): string {
            $p = $g['provider'];
            $how = $p === 'cashfree'
                ? 'In the Cashfree Merchant Dashboard → Payment Gateway → Developers → Webhooks, add this URL (latest webhook version) and tick Success Payment, Failed Payment and User Dropped Payment. Checkout also sends it as the order\'s notify URL when the site runs on HTTPS.'
                : 'In Razorpay Dashboard → Settings → Webhooks, add this URL with the events below and the same secret as <code>RAZORPAY_WEBHOOK_SECRET</code>.';
            $secret = $p === 'cashfree'
                ? '<span class="badge ' . ($g['webhook_ready'] ? 'b-green">Verified with the client secret' : 'b-gray">Needs CASHFREE_SECRET_KEY') . '</span> <span class="muted">base64 HMAC-SHA256 of timestamp + raw body; requests older than ' . (int) max(60, min(86400, (int) config('CASHFREE_WEBHOOK_TOLERANCE', 600))) / 60 . ' min are rejected' . ((string) config('CASHFREE_WEBHOOK_SECRET', '') !== '' ? '; <code>CASHFREE_WEBHOOK_SECRET</code> also accepted' : '') . '</span>'
                : $present('RAZORPAY_WEBHOOK_SECRET');
            $last = $g['last_webhook']
                ? $ago($g['last_webhook']) . ' · <code>' . e($g['last_webhook']['event_type']) . '</code> · ' . badge($g['last_webhook']['status']) . ($g['last_webhook']['error'] ? ' <span class="muted">' . e($g['last_webhook']['error']) . '</span>' : '')
                : '<span class="muted">No webhook received yet</span>';
            return '<section class="card"><div class="card-head"><h2>' . icon('zap') . ' Webhook for ' . e($g['label']) . '</h2>' . $pill($cards['webhook'][4]) . '</div>'
                . '<div class="card-pad"><p class="muted" style="margin-top:0">' . $how . '</p><dl class="kv">'
                . '<dt>Webhook URL</dt><dd>' . $copy($g['webhook_url']) . '</dd>'
                . '<dt>Events</dt><dd>' . implode(' ', array_map(fn($ev) => '<code>' . e($ev) . '</code>', $g['webhook_events'])) . '</dd>'
                . '<dt>Signature</dt><dd>' . $secret . '</dd>'
                . '<dt>Last received</dt><dd>' . $last . '</dd>'
                . ($gwHookFails ? '<dt>Failed (30 days)</dt><dd><span class="badge b-red">' . $gwHookFails . '</span> processed again when the gateway retries</dd>' : '')
                . '</dl></div><div class="form-actions"><a class="btn btn-light" href="integrations.php?tab=webhooks">' . icon('log') . ' Recent deliveries</a>'
                . (can('payments.view') ? '<a class="btn btn-light" href="payments.php?tab=setup">' . icon('shield') . ' Webhook security</a>' : '') . '</div></section>';
        };
        if (!$providerKnown) {
            echo '<div class="alert alert-error">' . icon('alert') . ' <span><code>PAYMENT_PROVIDER=' . e(payment_provider()) . '</code> in .env is not supported, so paid checkout is switched off. Use <code>cashfree</code> or <code>razorpay</code>.</span></div>';
        }
        echo '<div class="alert alert-info">' . icon('info') . ' <span>Checkout uses <strong>' . e($gw['label']) . '</strong> (<code>PAYMENT_PROVIDER=' . e($gw['provider']) . '</code>) in <strong>' . ($gw['mode'] === 'live' ? 'live' : 'sandbox') . '</strong> mode. Orders keep the gateway they were paid with, so older orders still reconcile after a switch.</span></div>';
        echo '<div class="ig-split mt">' . $gatewayCard($gw, true) . $hookCard($gw) . '</div>';
        echo '<div class="ig-split mt">' . $gatewayCard($gwOther, false)
            . '<section class="card"><div class="card-head"><h2>' . icon('settings') . ' Switching gateway</h2></div><div class="card-pad"><ol class="ig-steps">'
            . '<li>Add the new gateway\'s keys in <a href="keys.php">Keys &amp; Credentials</a> (sandbox keys first).</li>'
            . '<li>Set <code>PAYMENT_PROVIDER=cashfree</code> or <code>razorpay</code> and keep <code>PAYMENT_MODE=sandbox</code> until a test purchase works.</li>'
            . '<li>Add the webhook URL shown above in that gateway\'s dashboard, then use <strong>Test connection</strong>.</li>'
            . '<li>Switch to production keys and <code>PAYMENT_MODE=live</code>; do one low-value live purchase and refund it.</li>'
            . '</ol></div></section></div>';
        echo '<div class="alert alert-info mt">' . icon('info') . ' <span>Keys and secrets are set in <a href="keys.php">Keys &amp; Credentials</a> (stored encrypted) or the server\'s <code>.env</code> file - they are never sent to the browser. Refunds are processed in the gateway dashboard and recorded on the order page.</span></div>';
        break;

    // ------------------------------------------------------------------ SMTP / Email
    case 'email':
        $cfg = smtp_settings();
        ?>
        <div class="ig-split">
          <section class="card">
            <div class="card-head"><h2><?= icon('server') ?> SMTP server</h2><?= $pill($cards['smtp'][4]) ?></div>
            <div class="card-pad">
              <dl class="kv">
                <dt>Host</dt><dd><?= $cfg['host'] !== '' ? '<code>' . e($cfg['host']) . '</code>' : '<span class="badge b-gray">Missing</span> <code>SMTP_HOST</code>' ?></dd>
                <dt>Port / security</dt><dd><code><?= (int) $cfg['port'] ?></code> · <?= e(strtoupper($cfg['secure'] ?: 'none')) ?></dd>
                <dt>Username</dt><dd><?= $cfg['user'] !== '' ? '<code>' . e(int_mask($cfg['user'])) . '</code>' : '<span class="muted">Not set</span>' ?></dd>
                <dt>Password</dt><dd><?= $cfg['pass'] !== '' ? '<span class="badge b-green">Set</span> (hidden)' : '<span class="muted">Not set</span>' ?></dd>
                <dt>From</dt><dd><?= e((string) config('MAIL_FROM_NAME', 'Laxyam Academy')) ?> &lt;<?= e((string) config('MAIL_FROM', 'info@laxyamacademy.com')) ?>&gt;</dd>
                <dt>Replies go to</dt><dd><?= e((string) setting('contact_email', '—')) ?></dd>
              </dl>
              <?php if (!mail_configured()): ?><div class="alert alert-warning" style="margin:14px 0 0"><?= icon('alert') ?> <span>Without <code>SMTP_HOST</code> no email leaves the server: every message is saved to the mail log (<code>uploads/private/logs/mail.log</code>) and marked “skipped” in Email Logs.</span></div><?php endif; ?>
              <div class="ig-testrow"><?= $lastTest($check('smtp')) ?></div>
            </div>
            <div class="form-actions">
              <form method="post" class="inline-form"><?= csrf_field() . $tabField ?><input type="hidden" name="_action" value="test_smtp"><button class="btn btn-primary" data-busy="Sending…"><?= icon('send') ?> Send test email to <?= e((string) $me['email']) ?></button></form>
            </div>
          </section>
          <section class="card">
            <div class="card-head"><h2><?= icon('activity') ?> Delivery, last 30 days</h2><?= can('notifications.send') ? '<a class="btn btn-light btn-sm" href="email-logs.php">' . icon('log') . ' Email logs</a>' : '' ?></div>
            <div class="card-pad">
              <div class="ig-mini">
                <div><span>Sent</span><strong><?= number_format((int) ($mail30['sent'] ?? 0)) ?></strong></div>
                <div><span>Failed</span><strong class="<?= (int) ($mail30['failed'] ?? 0) ? 'bad' : '' ?>"><?= number_format((int) ($mail30['failed'] ?? 0)) ?></strong></div>
                <div><span>Mail log only</span><strong><?= number_format((int) ($mail30['skipped'] ?? 0)) ?></strong></div>
                <div><span>Waiting</span><strong><?= number_format((int) ($mail30['queued'] ?? 0)) ?></strong></div>
              </div>
              <?php if ($lastMailError): ?><p class="ig-err"><?= icon('alert') ?> Last error <?= e(fmt_ago($lastMailError['updated_at'])) ?>: <?= e((string) $lastMailError['last_error']) ?></p><?php endif; ?>
              <p class="muted" style="margin:14px 0 0">Failed emails are retried automatically (5 min, 15 min, 1 h, 4 h, 12 h) up to <?= (int) setting_int('email_max_attempts', 5, 1, 10) ?> attempts.</p>
            </div>
          </section>
        </div>
        <?php
        break;

    // ------------------------------------------------------------------ Analytics & ads tags
    case 'analytics':
        $groups = [
            'ga' => ['Google Analytics 4', 'chart', 't-amber', 'Visitor and conversion analytics. Loaded with gtag.js on every public page.', 'analytics.google.com → Admin → Data streams → Web → Measurement ID'],
            'gtm' => ['Google Tag Manager', 'code', 't-blue', 'Lets marketing add or change tags in GTM without code changes.', 'tagmanager.google.com → Workspace → container ID (GTM-…)'],
            'pixel' => ['Meta Pixel', 'facebook', 't-indigo', 'Facebook / Instagram ads measurement. Sends a PageView on every public page.', 'business.facebook.com → Events Manager → Data sources → Pixel ID'],
            'ads' => ['Google Ads conversion', 'target', 't-orange', 'Records a purchase conversion on the payment success page (value + order number).', 'ads.google.com → Goals → Conversions → Tag setup → “Install the tag yourself”'],
        ];
        echo '<div class="alert alert-info">' . icon('info') . ' <span>Tags load only on the public website (never in the admin panel or on private pages opened directly). Mention analytics and ads cookies in your Privacy and Cookie Policy before switching them on. Search Console verification is on the <a href="indexing.php">Google Search Console</a> screen.</span></div>';
        echo '<div class="ig-grid ig-grid-2">';
        foreach ($groups as $g => [$name, $ico, $tone, $what, $where]) {
            $keys = array_keys(array_filter(TRACKING, fn($t) => $t[3] === $g));
            $st = $cards[$g][4];
            $hasAny = (bool) array_filter($keys, fn($k) => ($s[$k] ?? '') !== '');
            echo '<form method="post" class="card ig-form" id="' . e($g) . '" data-dirty-check>' . csrf_field() . $tabField . '<input type="hidden" name="_action" value="tag_save"><input type="hidden" name="group" value="' . e($g) . '">'
                . '<div class="card-head"><h2><span class="tile ' . e($tone) . ' ig-mini-tile">' . icon($ico) . '</span> ' . e($name) . '</h2>' . $pill($st) . '</div><div class="card-pad"><p class="muted" style="margin-top:0">' . e($what) . '</p>';
            foreach ($keys as $k) {
                echo '<div class="field"><label for="t-' . e($k) . '">' . e(TRACKING[$k][0]) . '</label><input id="t-' . e($k) . '" type="text" name="' . e($k) . '" value="' . e((string) ($s[$k] ?? '')) . '" placeholder="' . e(TRACKING[$k][2]) . '" autocomplete="off" spellcheck="false"></div>';
            }
            echo '<p class="hint ig-where">' . icon('help') . ' ' . e($where) . '</p>'
                . ($hasAny ? '<p class="ig-live">' . icon('check-circle') . ' Printed on every public page by the website shell.</p>' : '') . '</div>'
                . '<div class="form-actions"><span class="dirty-flag">' . icon('alert') . ' Unsaved changes</span><button class="btn btn-primary">' . icon('check') . ' Save</button></div></form>';
        }
        echo '</div>';
        $configured = array_filter(array_keys($groups), fn($g) => (bool) array_filter(array_keys(array_filter(TRACKING, fn($t) => $t[3] === $g)), fn($k) => ($s[$k] ?? '') !== ''));
        if ($configured) {
            echo '<section class="card mt"><div class="card-head"><h2>' . icon('trash') . ' Remove a tag</h2></div><div class="card-pad ig-remove">';
            foreach ($configured as $g) {
                echo '<form method="post" class="inline-form">' . csrf_field() . $tabField . '<input type="hidden" name="_action" value="tag_remove"><input type="hidden" name="group" value="' . e($g) . '"><button class="btn btn-danger btn-sm" data-confirm="Remove ' . e($groups[$g][0]) . ' from the website?">' . icon('trash') . ' ' . e($groups[$g][0]) . '</button></form>';
            }
            echo '</div></section>';
        }
        break;

    // ------------------------------------------------------------------ WhatsApp
    case 'whatsapp':
        $enabled = ($s['whatsapp_enabled'] ?? '0') === '1';
        $number = preg_replace('/\D/', '', (string) ($s['whatsapp_number'] ?? ''));
        ?>
        <div class="ig-split">
          <section class="card">
            <div class="card-head"><h2><?= icon('whatsapp') ?> WhatsApp Cloud API</h2><?= $pill($cards['whatsapp'][4]) ?></div>
            <div class="card-pad">
              <dl class="kv">
                <dt>Access token</dt><dd><?= $env('WHATSAPP_TOKEN') ? '<span class="badge b-green">Set</span> (hidden)' : '<span class="badge b-gray">Missing</span> <code>WHATSAPP_TOKEN</code>' ?></dd>
                <dt>Phone number ID</dt><dd><?= $env('WHATSAPP_PHONE_ID') ? '<code>' . e(int_mask((string) config('WHATSAPP_PHONE_ID'))) . '</code>' : '<span class="badge b-gray">Missing</span> <code>WHATSAPP_PHONE_ID</code>' ?></dd>
                <dt>Sending</dt><dd><?= $enabled ? '<span class="badge b-green">On</span>' : '<span class="badge b-gray">Off</span>' ?> · <?= $waTemplates ?> template<?= $waTemplates === 1 ? '' : 's' ?> mapped</dd>
              </dl>
              <p class="muted" style="margin:14px 0 0">Business-initiated WhatsApp messages must use templates approved in Meta Business Manager. Map each automatic message (enrolment, payment, class reminder…) to its approved template on the templates screen.</p>
              <div class="ig-testrow"><?= $lastTest($check('whatsapp')) ?></div>
            </div>
            <div class="form-actions">
              <?= can('notifications.send') ? '<a class="btn btn-light" href="templates.php?tab=whatsapp">' . icon('settings') . ' Template mapping &amp; on/off</a>' : '' ?>
            </div>
          </section>
          <section class="card">
            <div class="card-head"><h2><?= icon('send') ?> Send a test message</h2></div>
            <?php if ($waEnv): ?>
            <form method="post" class="card-pad form"><?= csrf_field() . $tabField ?><input type="hidden" name="_action" value="test_whatsapp">
              <div class="field full"><label for="wa-phone">Mobile number</label><input id="wa-phone" type="tel" name="phone" inputmode="tel" placeholder="98xxxxxxxx" required><div class="hint">Sends Meta's pre-approved “hello_world” template. The number must be allowed in your WhatsApp test settings while the app is in development mode.</div></div>
              <div class="full"><button class="btn btn-primary" data-busy="Sending…"><?= icon('send') ?> Send test</button></div>
            </form>
            <?php else: ?>
            <?= report_empty('Not configured yet', 'Add the Meta token and phone number ID (Meta Business → WhatsApp → API Setup) in <a href="keys.php#whatsapp">Keys &amp; Credentials</a>. A test button appears here once they are set.', 'whatsapp') ?>
            <?php endif; ?>
          </section>
        </div>
        <section class="card mt">
          <div class="card-head"><h2><?= icon('chat') ?> Click-to-chat button</h2><?= $number !== '' ? '<span class="badge b-green">Live on website</span>' : '<span class="badge b-gray">No number</span>' ?></div>
          <div class="card-pad">
            <p class="muted" style="margin-top:0">The website's WhatsApp button and chatbot hand-off open a chat with this number (no API needed).</p>
            <?php if ($number !== ''): ?><p><?= $copy('https://wa.me/' . $number, 'Copy link') ?> <a class="btn btn-ghost btn-sm" href="https://wa.me/<?= e($number) ?>" target="_blank" rel="noopener"><?= icon('external') ?> Open</a></p><?php endif; ?>
            <a class="btn btn-light btn-sm" href="settings.php#academy"><?= icon('edit') ?> Change number in Academy Information</a>
          </div>
        </section>
        <?php
        break;

    // ------------------------------------------------------------------ Meetings
    case 'meetings':
        $byProvider = db_all("SELECT COALESCE(NULLIF(meeting_provider, ''), 'not set') p, COUNT(*) n FROM live_classes WHERE status <> 'cancelled' AND starts_at > NOW() - INTERVAL 180 DAY GROUP BY p ORDER BY n DESC");
        $missing = (int) db_value("SELECT COUNT(*) FROM live_classes WHERE status = 'scheduled' AND starts_at > NOW() AND (meeting_url IS NULL OR meeting_url = '')");
        $upcoming = (int) db_value("SELECT COUNT(*) FROM live_classes WHERE status = 'scheduled' AND starts_at > NOW()");
        $labels = ['zoom' => 'Zoom', 'google_meet' => 'Google Meet', 'youtube' => 'YouTube Live', 'other' => 'Other', 'not set' => 'Platform not set'];
        ?>
        <section class="card ig-explain">
          <div class="card-pad">
            <span class="tile t-red"><?= icon('video') ?></span>
            <div>
              <h2>Meeting links are added per class</h2>
              <p class="muted">There is no Zoom or Google Meet account connection. When you schedule a live class you choose the platform (Zoom, Google Meet, YouTube Live or other) and paste the meeting link. The link stays private: only eligible students see the Join button, from the join window before the start time. Reminders, attendance and recordings work the same for every platform.</p>
              <ol class="ig-steps">
                <li>Create the meeting in Zoom / Google Calendar (Meet) / YouTube Studio.</li>
                <li>Copy its join link (https://…).</li>
                <li>Paste it in <strong>Live Classes → Edit → Meeting link</strong> and pick the platform.</li>
              </ol>
            </div>
          </div>
        </section>
        <div class="grid g-2 mt">
          <section class="card">
            <div class="card-head"><h2><?= icon('calendar') ?> Upcoming classes</h2></div>
            <div class="card-pad">
              <div class="ig-mini"><div><span>Scheduled</span><strong><?= number_format($upcoming) ?></strong></div><div><span>Missing link</span><strong class="<?= $missing ? 'bad' : '' ?>"><?= number_format($missing) ?></strong></div></div>
              <?php if (can('live.manage')): ?><p style="margin:14px 0 0"><a class="btn btn-light btn-sm" href="live-classes.php?link=missing"><?= icon('link') ?> Classes without a link</a> <a class="btn btn-ghost btn-sm" href="live-classes.php"><?= icon('live') ?> All live classes</a></p><?php endif; ?>
            </div>
          </section>
          <section class="card">
            <div class="card-head"><h2><?= icon('chart') ?> Platforms used (last 6 months)</h2></div>
            <?= $byProvider ? '<div class="card-pad">' . report_hbars(array_combine(array_map(fn($p) => $labels[$p] ?? $p, array_column($byProvider, 'p')), array_column($byProvider, 'n'))) . '</div>' : report_empty('No live classes yet', '', 'live') ?>
          </section>
        </div>
        <?php
        break;

    // ------------------------------------------------------------------ YouTube
    case 'youtube':
        $channel = (string) ($s['social_youtube'] ?? '');
        $homeChannel = (string) ($ytSection['channel_url'] ?? '');
        $recent = db_all("SELECT id, title, video_id, placement, is_active FROM media_videos WHERE platform = 'youtube' ORDER BY is_active DESC, sort_order, id DESC LIMIT 6");
        ?>
        <div class="ig-split">
          <form method="post" class="card" data-dirty-check><?= csrf_field() . $tabField ?><input type="hidden" name="_action" value="youtube_save">
            <div class="card-head"><h2><?= icon('youtube') ?> Channel</h2><?= $pill($cards['youtube'][4]) ?></div>
            <div class="card-pad form">
              <div class="field full"><label for="yt-url">YouTube channel URL</label><input id="yt-url" type="url" name="channel_url" value="<?= e($channel !== '' ? $channel : $homeChannel) ?>" placeholder="https://www.youtube.com/@LaxyamAcademy"><div class="hint">Used for the footer / social icons. Shown on the website as a “Visit channel” link.</div></div>
              <div class="field full"><label class="check"><input type="checkbox" name="apply_home" value="1"<?= $homeChannel === '' || $homeChannel === $channel ? ' checked' : '' ?>> Also use it for the “Subscribe” link of the homepage YouTube section</label>
                <?php if ($homeChannel !== '' && $homeChannel !== $channel): ?><div class="hint">The homepage section currently links to <code><?= e($homeChannel) ?></code>.</div><?php endif; ?></div>
            </div>
            <div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><?= $channel !== '' ? '<a class="btn btn-ghost" href="' . e($channel) . '" target="_blank" rel="noopener">' . icon('external') . ' Open channel</a>' : '' ?><button class="btn btn-primary"><?= icon('check') ?> Save</button></div>
          </form>
          <section class="card">
            <div class="card-head"><h2><?= icon('video') ?> Videos on the website</h2><?= can('cms.manage') ? '<a class="btn btn-light btn-sm" href="videos.php?tab=youtube">' . icon('plus') . ' Manage videos</a>' : '' ?></div>
            <?php if ($recent): ?>
              <ul class="ig-videos">
                <?php foreach ($recent as $v): ?>
                <li><img src="https://i.ytimg.com/vi/<?= e(rawurlencode((string) $v['video_id'])) ?>/mqdefault.jpg" alt="" width="96" height="54" loading="lazy"><span><strong><?= e($v['title']) ?></strong><small class="muted"><?= e(ucfirst((string) $v['placement'])) ?><?= $v['is_active'] ? '' : ' · hidden' ?></small></span></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <?= report_empty('No YouTube videos added yet', 'Add videos by link on the Media → YouTube Videos screen; thumbnails come from YouTube automatically.', 'youtube') ?>
            <?php endif; ?>
          </section>
        </div>
        <div class="alert alert-info mt"><?= icon('info') ?> <span>There is no YouTube API connection: videos are added by their link and embedded with YouTube's privacy-enhanced player, so no API key is needed.</span></div>
        <?php
        break;

    // ------------------------------------------------------------------ Student login (Google + mobile OTP)
    case 'login':
        require_once APP_ROOT . '/includes/login-methods.php';
        $ready = login_methods_ready();
        $channels = $ready ? otp_channels() : [];
        $googleOn = $ready && google_client_id() !== null;
        $stats = $ready ? db_one("SELECT SUM(google_sub IS NOT NULL) AS google, SUM(phone_verified_at IS NOT NULL) AS phones FROM users WHERE user_type = 'student' AND status = 'active'") : null;
        $sent = $ready ? array_column(db_all("SELECT channel, COUNT(*) AS n FROM login_otps WHERE created_at > NOW() - INTERVAL 7 DAY GROUP BY channel"), 'n', 'channel') : [];
        $on = fn(bool $x) => $x ? '<span class="badge b-green">On</span>' : '<span class="badge b-gray">Off</span>';
        $origin = rtrim(app_url(''), '/');
        $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', $origin);
        ?>
        <?php if (!$ready): ?><div class="alert alert-warning">Apply the latest database update first (System → Database Updates).</div><?php endif; ?>
        <div class="ig-split">
          <section class="card">
            <div class="card-head"><h2><?= icon('key') ?> Sign-in methods</h2><a class="btn btn-light btn-sm" href="keys.php?group=login#login"><?= icon('key') ?> Keys &amp; Credentials</a></div>
            <ul class="ig-list">
              <li><?= icon($googleOn ? 'check' : 'close') ?> <span><b>Continue with Google</b> <?= $on($googleOn) ?></span></li>
              <li><?= icon(in_array('whatsapp', $channels, true) ? 'check' : 'close') ?> <span><b>Code on WhatsApp</b> <?= $on(in_array('whatsapp', $channels, true)) ?></span></li>
              <li><?= icon(in_array('sms', $channels, true) ? 'check' : 'close') ?> <span><b>Code by SMS (MSG91)</b> <?= $on(in_array('sms', $channels, true)) ?></span></li>
              <li><?= icon('check') ?> <span><b>Email &amp; password</b> <span class="badge b-green">Always on</span></span></li>
            </ul>
            <?php if ($stats): ?>
            <div class="card-pad muted" style="padding-top:0">
              <?= (int) $stats['google'] ?> students use Google · <?= (int) $stats['phones'] ?> have a verified mobile number · codes sent in the last 7 days: <?= (int) ($sent['whatsapp'] ?? 0) ?> on WhatsApp, <?= (int) ($sent['sms'] ?? 0) ?> by SMS.
            </div>
            <?php endif; ?>
            <div class="card-pad" style="padding-top:0"><p class="muted" style="margin:0">Each option appears on the login and sign-up pages only when it is set up. Mobile codes log in only to an account whose number was confirmed with a code (on sign-up or in My Profile); codes are 6 digits, valid 10 minutes, 5 tries, and are never stored or logged.</p></div>
          </section>
          <section class="card">
            <div class="card-head"><h2><?= icon('info') ?> How to switch them on</h2></div>
            <div class="card-pad ig-howto">
              <h3>Continue with Google</h3>
              <ol>
                <li><a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console → Credentials</a>: set up the OAuth consent screen (External, app name “Laxyam Academy”, support email, logo).</li>
                <li>Create credentials → OAuth client ID → <b>Web application</b>. Authorised JavaScript origins: <code><?= e($origin) ?></code> (add the www address too if you use it).</li>
                <li>Copy the client ID (…apps.googleusercontent.com) into Keys &amp; Credentials → Student login.</li>
              </ol>
              <h3>Code on WhatsApp</h3>
              <ol>
                <li>Interakt → Templates → New template, category <b>Authentication</b>, with the code as {{1}} and a “Copy code” button. Wait for approval.</li>
                <li>Type its exact name in Keys &amp; Credentials → “WhatsApp OTP template name”. It uses your WhatsApp keys.</li>
              </ol>
              <h3>Code by SMS (MSG91)</h3>
              <ol>
                <li>SMS in India needs DLT registration: register your sender ID and an OTP template containing the code on the DLT portal (MSG91 helps with this).</li>
                <li>MSG91 → OTP → create a template linked to that DLT template (it must contain <code>##OTP##</code>) and copy its template ID.</li>
                <li>Paste the MSG91 Authkey and the template ID in Keys &amp; Credentials → Student login.</li>
              </ol>
            </div>
          </section>
        </div>
        <?php
        break;

    // ------------------------------------------------------------------ Android app (Trusted Web Activity)
    case 'app':
        $pkg = (string) setting('android_package', '');
        $prints = array_filter(explode(',', (string) setting('android_sha256', '')));
        $store = (string) setting('app_android_url', '');
        $linked = $pkg !== '' && $prints;
        $site = preg_replace('#^(https?://[^/]+).*$#', '$1', rtrim(app_url(''), '/'));
        $checks = [
            ['Website on HTTPS', is_https() || !is_production()],
            ['Installable app (manifest + service worker + icons incl. maskable)', is_file(APP_ROOT . '/assets/manifest.webmanifest') && is_file(APP_ROOT . '/sw.js') && is_file(APP_ROOT . '/assets/icons/icon-maskable-512.png')],
            ['Privacy policy page (Play Store requires it)', true],
            ['App linked to this website (assetlinks.json)', (bool) $linked],
            ['Play Store link shown in the footer', $store !== ''],
        ];
        ?>
        <div class="ig-split">
          <form method="post" class="card" data-dirty-check><?= csrf_field() . $tabField ?><input type="hidden" name="_action" value="app_save">
            <div class="card-head"><h2><?= icon('phone') ?> Android app</h2><?= $linked ? '<span class="badge b-green">Linked</span>' : '<span class="badge b-gray">Not linked yet</span>' ?></div>
            <div class="card-pad form">
              <div class="field full"><label for="ap-pkg">Package name</label><input id="ap-pkg" name="android_package" value="<?= e($pkg) ?>" placeholder="com.laxyamacademy.app" autocomplete="off" spellcheck="false"><div class="hint">The same one you choose when you build the app (it cannot change after publishing).</div></div>
              <div class="field full"><label for="ap-sha">SHA-256 signing certificate fingerprint(s)</label><textarea id="ap-sha" name="android_sha256" rows="3" spellcheck="false" placeholder="AB:CD:EF:…"><?= e(implode("\n", $prints)) ?></textarea><div class="hint">Play Console → your app → Test and release → App integrity → App signing key certificate. Add the upload key fingerprint too (one per line).</div></div>
              <div class="field full"><label for="ap-store">Play Store link (after the app is live)</label><input id="ap-store" type="url" name="app_android_url" value="<?= e($store) ?>" placeholder="https://play.google.com/store/apps/details?id=com.laxyamacademy.app"><div class="hint">Shows a “Get it on Google Play” button in the website footer.</div></div>
              <?php if ($linked): ?><div class="field full"><span class="lbl">Live link file</span><a href="<?= e(app_url('.well-known/assetlinks.json')) ?>" target="_blank" rel="noopener"><?= e(app_url('.well-known/assetlinks.json')) ?></a></div><?php endif; ?>
            </div>
            <div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><button class="btn btn-primary"><?= icon('check') ?> Save</button></div>
          </form>
          <section class="card">
            <div class="card-head"><h2><?= icon('check-circle') ?> Ready for Google Play?</h2></div>
            <ul class="ig-list"><?php foreach ($checks as [$label, $ok]): ?><li><?= icon($ok ? 'check' : 'close') ?> <span><?= e($label) ?></span></li><?php endforeach; ?></ul>
            <div class="card-pad ig-howto" style="padding-top:0">
              <h3>How to publish (about 1 hour of work, then Google's review)</h3>
              <ol>
                <li>Open <a href="https://www.pwabuilder.com" target="_blank" rel="noopener">pwabuilder.com</a>, enter <code><?= e($site) ?></code> and choose <b>Package for stores → Android</b>. Use a package name such as <code>com.laxyamacademy.app</code> and download the package.</li>
                <li>Keep the signing key file from that package safe (with its password) - every future update needs it.</li>
                <li>Create a Google Play developer account (one-time US$25) at <a href="https://play.google.com/console" target="_blank" rel="noopener">play.google.com/console</a>, create the app and upload the <code>.aab</code> file to <b>Internal testing</b> first.</li>
                <li>Copy the SHA-256 fingerprint from <b>App integrity</b> into this form and save - the app then opens full-screen without a browser bar.</li>
                <li>Fill the store listing (screenshots, description, privacy policy <code><?= e($site) ?>/privacy-policy</code>), content rating and data safety, then send it for review. Paste the Play Store link here once it is live.</li>
              </ol>
              <p class="muted" style="margin:10px 0 0">The app shows this website, so courses, tests and payments work the same and every website update reaches the app at once.</p>
            </div>
          </section>
        </div>
        <?php
        break;

    // ------------------------------------------------------------------ reCAPTCHA
    case 'recaptcha':
        $secret = $env('RECAPTCHA_SECRET');
        $forms = ['Student registration' => 'api/auth.php', 'Contact, callback, popup and course enquiry forms' => 'api/enquiries.php', 'Job applications (careers)' => 'api/jobs.php', 'Chatbot lead form' => 'api/chat.php'];
        ?>
        <div class="ig-split">
          <form method="post" class="card" data-dirty-check><?= csrf_field() . $tabField ?><input type="hidden" name="_action" value="recaptcha_save">
            <div class="card-head"><h2><?= icon('shield') ?> Google reCAPTCHA v3</h2><?= $pill($cards['recaptcha'][4]) ?></div>
            <div class="card-pad form">
              <div class="field full"><label for="rc-key">Site key (public)</label><input id="rc-key" type="text" name="recaptcha_site_key" value="<?= e((string) ($s['recaptcha_site_key'] ?? '')) ?>" placeholder="6Lc…" autocomplete="off" spellcheck="false"><div class="hint">Leave empty to switch reCAPTCHA off.</div></div>
              <div class="field"><label for="rc-score">Minimum score (0.1 – 0.9)</label><input id="rc-score" type="number" name="recaptcha_min_score" min="0.1" max="0.9" step="0.1" value="<?= e((string) ($s['recaptcha_min_score'] ?? '0.5')) ?>"><div class="hint">0.5 is Google's recommendation; higher blocks more.</div></div>
              <div class="field"><span class="lbl">Secret key</span><div style="padding-top:8px"><?= $secret ? '<span class="badge b-green">Set</span> (hidden)' : '<span class="badge b-gray">Missing</span> <code>RECAPTCHA_SECRET</code>' ?></div></div>
              <div class="field full ig-testrow"><?= $lastTest($check('recaptcha')) ?></div>
            </div>
            <div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><button class="btn btn-primary"><?= icon('check') ?> Save</button></div>
          </form>
          <section class="card">
            <div class="card-head"><h2><?= icon('check-circle') ?> Protected forms</h2><?= recaptcha_enabled() ? '<span class="badge b-green">Active</span>' : '<span class="badge b-gray">Off</span>' ?></div>
            <ul class="ig-list">
              <?php foreach ($forms as $label => $file): ?><li><?= icon(recaptcha_enabled() ? 'check' : 'close') ?> <span><?= e($label) ?></span></li><?php endforeach; ?>
            </ul>
            <div class="card-pad" style="padding-top:0"><p class="muted" style="margin:0">reCAPTCHA runs only when both the site key and <code>RECAPTCHA_SECRET</code> are set. Every public form also has a hidden honeypot field and rate limits.</p></div>
            <div class="form-actions">
              <?php if ($secret): ?>
                <form method="post" class="inline-form"><?= csrf_field() . $tabField ?><input type="hidden" name="_action" value="test_recaptcha"><button class="btn btn-light" data-busy="Checking…"><?= icon('check') ?> Verify secret with Google</button></form>
              <?php else: ?>
                <span class="muted ig-why"><?= icon('info') ?> Add the secret key in <a href="keys.php#recaptcha">Keys &amp; Credentials</a> to enable the check and the secret test.</span>
              <?php endif; ?>
            </div>
          </section>
        </div>
        <?php
        break;

    // ------------------------------------------------------------------ Webhooks
    case 'webhooks':
        $stats = db_one("SELECT COUNT(*) n, SUM(status = 'processed') ok, SUM(status = 'ignored') ign, SUM(status = 'failed') bad, SUM(status = 'received') pend FROM webhook_events WHERE received_at > NOW() - INTERVAL 30 DAY");
        $rejected = (int) db_value("SELECT COUNT(*) FROM audit_logs WHERE action = 'webhook_signature_invalid' AND created_at > NOW() - INTERVAL 30 DAY");
        $events = db_all('SELECT id, provider, event_id, event_type, signature_valid, status, error, received_at, processed_at FROM webhook_events ORDER BY id DESC LIMIT 25');
        ?>
        <section class="card">
          <div class="card-head"><h2><?= icon('zap') ?> Endpoints</h2></div>
          <div class="table-wrap"><table class="t stack"><thead><tr><th>Service</th><th>URL</th><th>Verification</th><th>Status</th></tr></thead><tbody>
            <?php foreach ([$gw, $gwOther] as $g): $isActive = $g['provider'] === $gw['provider']; ?>
            <tr><td data-label="Service"><strong><?= e($g['label']) ?></strong><?= $isActive ? ' <span class="badge b-blue">Active</span>' : ' <span class="badge b-gray">Not in use</span>' ?><br><span class="muted"><?= e(implode(' · ', $g['webhook_events'])) ?></span></td>
              <td data-label="URL"><?= $copy($g['webhook_url']) ?></td>
              <td data-label="Verification"><?= $g['provider'] === 'cashfree' ? 'base64 HMAC-SHA256 of timestamp + raw body with the client secret (<code>CASHFREE_SECRET_KEY</code>), stale timestamps rejected, order re-checked with the Cashfree API' : 'HMAC-SHA256 signature with <code>RAZORPAY_WEBHOOK_SECRET</code>' ?></td>
              <td data-label="Status"><?= $isActive ? $pill($cards['webhook'][4]) : $pill($g['webhook_ready'] ? ['partial', 'Ready · not in use'] : ['off', 'Not in use']) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </section>
        <div class="ig-mini ig-mini-row mt">
          <div class="card"><span>Received (30 days)</span><strong><?= number_format((int) $stats['n']) ?></strong></div>
          <div class="card"><span>Processed</span><strong><?= number_format((int) $stats['ok']) ?></strong></div>
          <div class="card"><span>Ignored / pending</span><strong><?= number_format((int) $stats['ign'] + (int) $stats['pend']) ?></strong></div>
          <div class="card"><span>Failed</span><strong class="<?= (int) $stats['bad'] ? 'bad' : '' ?>"><?= number_format((int) $stats['bad']) ?></strong></div>
          <div class="card"><span>Rejected signatures</span><strong class="<?= $rejected ? 'bad' : '' ?>"><?= number_format($rejected) ?></strong></div>
        </div>
        <section class="card mt">
          <div class="card-head"><h2><?= icon('log') ?> Latest deliveries</h2><?= can('payments.view') ? '<a class="btn btn-light btn-sm" href="payments.php?tab=webhooks">' . icon('list') . ' All events</a>' : '' ?></div>
          <?php if (!$events): ?>
            <?= report_empty('No webhook received yet', e($gw['label']) . ' starts sending events once the webhook URL is saved in its dashboard.', 'zap') ?>
          <?php else: ?>
          <div class="table-wrap"><table class="t stack"><thead><tr><th>Received</th><th>Event</th><th>Signature</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($events as $ev): ?>
              <tr><td data-label="Received"><?= e(fmt_dt($ev['received_at'])) ?></td><td data-label="Event"><strong><?= e(payment_provider_label((string) $ev['provider'])) ?> · <?= e($ev['event_type']) ?></strong><br><code class="muted"><?= e($ev['event_id']) ?></code></td>
                <td data-label="Signature"><?= $ev['signature_valid'] ? '<span class="badge b-green">Valid</span>' : '<span class="badge b-red">Invalid</span>' ?></td>
                <td data-label="Status"><?= badge($ev['status']) ?><?= $ev['error'] ? '<br><span class="muted">' . e($ev['error']) . '</span>' : '' ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
        </section>
        <?php
        break;

    // ------------------------------------------------------------------ Integration logs
    case 'logs':
        $source = in_array($_GET['source'] ?? '', ['webhooks', 'api', 'email', 'tests'], true) ? (string) $_GET['source'] : '';
        $errorsOnly = !empty($_GET['errors']);
        $items = [];
        if ($source === '' || $source === 'webhooks') {
            foreach (db_all('SELECT received_at, provider, event_type, event_id, signature_valid, status, error FROM webhook_events' . ($errorsOnly ? " WHERE status = 'failed' OR signature_valid = 0" : '') . ' ORDER BY id DESC LIMIT 100') as $x) {
                $items[] = [$x['received_at'], 'Webhook', 'zap', ucfirst((string) $x['provider']) . ' ' . $x['event_type'], $x['event_id'], $x['status'] === 'failed' || !$x['signature_valid'] ? 'failed' : $x['status'], $x['error']];
            }
            foreach (db_all("SELECT l.created_at, l.details FROM audit_logs l WHERE l.action = 'webhook_signature_invalid' ORDER BY l.id DESC LIMIT 50") as $x) {
                $d = json_decode_array($x['details']);
                $why = ($d['provider'] ?? 'razorpay') === 'cashfree'
                    ? match ($d['reason'] ?? '') { 'stale' => 'Cashfree timestamp too old (possible replay)', 'missing' => 'Cashfree signature / timestamp headers missing', 'not_configured' => 'CASHFREE_SECRET_KEY not set', default => 'Signature not matching the Cashfree client secret' }
                    : 'Signature missing or not matching RAZORPAY_WEBHOOK_SECRET';
                $items[] = [$x['created_at'], 'Webhook', 'zap', 'Rejected ' . payment_provider_label((string) ($d['provider'] ?? 'razorpay')) . ' webhook call', 'IP ' . ($d['ip'] ?? '?'), 'failed', $why];
            }
        }
        if ($source === '' || $source === 'api') {
            foreach (db_all('SELECT l.created_at, l.method, l.endpoint, l.status, l.ip_address, k.name FROM api_access_logs l LEFT JOIN api_keys k ON k.id = l.api_key_id' . ($errorsOnly ? ' WHERE l.status >= 400' : '') . ' ORDER BY l.id DESC LIMIT 100') as $x) {
                $items[] = [$x['created_at'], 'API', 'key', $x['method'] . ' ' . $x['endpoint'], ($x['name'] ? 'Key “' . $x['name'] . '”' : 'Invalid / no key') . ' · ' . $x['ip_address'], (int) $x['status'] < 400 ? 'success' : 'failed', 'HTTP ' . (int) $x['status']];
            }
        }
        if ($source === '' || $source === 'email') {
            foreach (db_all("SELECT updated_at, template_key, to_email, attempts, next_attempt_at, last_error FROM email_log WHERE status = 'failed' ORDER BY id DESC LIMIT 100") as $x) {
                $masked = preg_replace('/(?<=.).(?=[^@]*@)/u', '•', (string) $x['to_email']);
                $items[] = [$x['updated_at'], 'Email', 'mail', 'Delivery failed' . ($x['template_key'] ? ' · ' . $x['template_key'] : ''), $masked . ' · ' . (int) $x['attempts'] . ' attempt' . ((int) $x['attempts'] === 1 ? '' : 's') . ($x['next_attempt_at'] ? ' · retry ' . fmt_dt($x['next_attempt_at'], 'j M, g:i A') : ' · gave up'), 'failed', $x['last_error']];
            }
        }
        if ($source === '' || $source === 'tests') {
            foreach (['cashfree' => 'Cashfree', 'razorpay' => 'Razorpay', 'smtp' => 'SMTP email', 'recaptcha' => 'reCAPTCHA', 'whatsapp' => 'WhatsApp'] as $k => $l) {
                $c = $check($k);
                if ($c && (!$errorsOnly || empty($c['ok']))) {
                    $items[] = [date('Y-m-d H:i:s', strtotime((string) $c['at'])), 'Test', 'check-circle', 'Connection test · ' . $l, '', $c['ok'] ? 'success' : 'failed', $c['msg']];
                }
            }
        }
        usort($items, fn($a, $z) => strcmp((string) $z[0], (string) $a[0]));
        $items = array_slice($items, 0, 150);
        $k30 = [
            ['Failed webhooks', $hookFails, 'zap', 'webhooks'],
            ['API errors', (int) db_value('SELECT COUNT(*) FROM api_access_logs WHERE status >= 400 AND created_at > NOW() - INTERVAL 30 DAY'), 'key', 'api'],
            ['Failed emails', (int) db_value("SELECT COUNT(*) FROM email_log WHERE status = 'failed' AND created_at > NOW() - INTERVAL 30 DAY"), 'mail', 'email'],
            ['Failed tests', count(array_filter(['cashfree', 'razorpay', 'smtp', 'recaptcha', 'whatsapp'], fn($k) => ($c = $check($k)) && empty($c['ok']) && strtotime((string) $c['at']) > time() - 30 * 86400)), 'check-circle', 'tests'],
        ];
        echo '<div class="ig-mini ig-mini-row">';
        foreach ($k30 as [$l, $n, $ico, $src]) {
            echo '<a class="card" href="?tab=logs&amp;source=' . e($src) . '&amp;errors=1"><span>' . icon($ico) . ' ' . e($l) . ' <small class="muted">30 days</small></span><strong class="' . ($n ? 'bad' : '') . '">' . number_format($n) . '</strong></a>';
        }
        echo '</div>';
        $base = fn(array $q) => '?' . http_build_query(['tab' => 'logs'] + array_filter($q, fn($v) => $v !== '' && $v !== null));
        echo '<section class="card mt"><div class="toolbar ig-filter" role="group" aria-label="Filter logs">';
        foreach (['' => 'All sources', 'webhooks' => 'Webhooks', 'api' => 'API calls', 'email' => 'Email failures', 'tests' => 'Connection tests'] as $k => $l) {
            echo '<a class="rp-chip' . ($source === $k ? ' on' : '') . '" href="' . e($base(['source' => $k, 'errors' => $errorsOnly ? 1 : null])) . '"' . ($source === $k ? ' aria-current="true"' : '') . '>' . e($l) . '</a>';
        }
        echo '<a class="rp-chip' . ($errorsOnly ? ' on' : '') . ' ig-errs" href="' . e($base(['source' => $source, 'errors' => $errorsOnly ? null : 1])) . '"' . ($errorsOnly ? ' aria-pressed="true"' : ' aria-pressed="false"') . '>' . icon('alert') . ' Errors only</a></div>';
        if (!$items) {
            echo report_empty($errorsOnly ? 'No errors recorded' : 'Nothing logged yet', 'Webhook deliveries, API calls with keys, failed emails and connection tests appear here.', 'log');
        } else {
            echo '<div class="table-wrap"><table class="t stack"><thead><tr><th>When</th><th>Source</th><th>Event</th><th>Result</th></tr></thead><tbody>';
            foreach ($items as [$at, $src, $ico, $what, $detail, $status, $err]) {
                echo '<tr><td data-label="When">' . e(fmt_dt((string) $at, 'j M, g:i A')) . '</td><td data-label="Source"><span class="ig-src">' . icon($ico) . ' ' . e($src) . '</span></td>'
                    . '<td data-label="Event"><strong>' . e($what) . '</strong>' . ($detail !== '' && $detail !== null ? '<br><span class="muted">' . e((string) $detail) . '</span>' : '') . '</td>'
                    . '<td data-label="Result">' . badge($status) . ($err ? '<br><span class="muted ig-errtxt">' . e((string) $err) . '</span>' : '') . '</td></tr>';
            }
            echo '</tbody></table></div><div class="pager"><span>Latest ' . number_format(count($items)) . ' entries' . ($source === '' ? ' across all sources' : '') . '</span></div>';
        }
        echo '</section>';
        break;
}
admin_end();

// ------------------------------------------------------------------ Small renderers
/** Summary line of the dashboard: counts per state. */
function int_summary(array $counts): string
{
    $parts = [['ok', 'Connected / configured', 'check-circle', 't-green'], ['error', 'Need attention', 'alert', 't-red'], ['partial', 'Partly set up', 'clock', 't-amber'], ['off', 'Not configured', 'plug', 't-navy']];
    $h = '<section class="ig-summary" aria-label="Summary">';
    foreach ($parts as [$k, $l, $ico, $tone]) {
        $h .= '<div class="card"><span class="tile ' . $tone . '">' . icon($ico) . '</span><span><strong>' . (int) ($counts[$k] ?? 0) . '</strong><small>' . e($l) . '</small></span></div>';
    }
    return $h . '</section>';
}
