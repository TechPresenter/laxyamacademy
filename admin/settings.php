<?php
// System settings (key/value), shown as client-side tab panels: settings.php#general, #academy, #company,
// #datetime, #currency, #language, #email, #notifications, #payments, #uploads, #maintenance, #cache,
// #social, #seo, #features. Each panel saves on its own. Secrets (DB, SMTP, payment keys) live only
// in .env and are shown read-only.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/invoice.php';

$me = require_staff_page();
require_permission('settings.manage');

// Panel id => [tab label, icon, description]
$panels = [
    'general' => ['General', 'settings', 'Academy name, tagline, mobile apps and website analytics.'],
    'academy' => ['Academy Information', 'home', 'Contact details shown in the header, footer, contact page and emails.'],
    'company' => ['Company & Invoicing', 'file', 'Legal identity, GST and invoice numbering. Printed on every invoice issued after a verified payment.'],
    'datetime' => ['Date & Time', 'clock', 'Timezone and the date format used on invoices.'],
    'currency' => ['Currency', 'rupee', 'The currency used for prices, checkout and invoices.'],
    'language' => ['Language', 'globe', 'Language options offered to visitors.'],
    'email' => ['Email & SMTP', 'mail', 'Mail server status, a test email and the staff addresses that receive alerts.'],
    'notifications' => ['Notifications', 'bell', 'Automatic emails sent to students, applicants and subscribers.'],
    'payments' => ['Payments', 'cart', 'Payment gateway status and payment alerts.'],
    'uploads' => ['Upload Limits', 'image', 'Maximum size of files staff and students can upload.'],
    'maintenance' => ['Maintenance', 'alert', 'Temporarily show a maintenance page to visitors.'],
    'cache' => ['Cache', 'history', 'Speed up the homepage and site layout.'],
    'social' => ['Social Media', 'megaphone', 'Profile links shown in the header and footer. Leave empty to hide a network.'],
    'seo' => ['SEO & Robots', 'search', 'Default search-engine title, description and robots.txt rules.'],
    'features' => ['Forms & Features', 'clipboard', 'Enquiry form, recruitment, support desk, live classes and spam protection.'],
];

// Legacy anchors (older sidebar links) => [panel, sub-section id]
$aliases = [
    'contact' => ['academy', null], 'social-media' => ['social', null], 'website' => ['language', null], 'apps' => ['general', 'sec-apps'],
    'server' => ['general', 'sec-system'], 'performance' => ['cache', null], 'company-invoicing' => ['company', null],
    'enquiry-form' => ['features', 'sec-enquiry-form'], 'recruitment' => ['features', 'sec-recruitment'], 'support' => ['features', 'sec-support'],
    'live-classes' => ['features', 'sec-live-classes'], 'spam-protection' => ['features', 'sec-spam-protection'], 'timezone' => ['datetime', null],
    'smtp' => ['email', null], 'gst' => ['company', 'sec-gst'], 'invoicing' => ['company', 'sec-invoice'],
];

$dateFormats = ['d M Y' => '31 Oct 2026', 'j M Y' => '1 Nov 2026 (no leading zero)', 'd/m/Y' => '31/10/2026', 'd-m-Y' => '31-10-2026', 'Y-m-d' => '2026-10-31', 'M j, Y' => 'Oct 31, 2026'];
$stateOptions = array_combine(array_keys(indian_states()), array_map(fn($n, $c) => "{$n} ({$c})", array_keys(indian_states()), indian_states()));

/** Field definition: label, panel, type, public flag, extra options (section, hint, options, min, max, required). */
function sf(string $label, string $panel, string $type = 'text', int $public = 0, array $extra = []): array
{
    return ['label' => $label, 'panel' => $panel, 'type' => $type, 'public' => $public] + $extra;
}

$fields = [
    // General
    'site_name' => sf('Academy name', 'general', 'text', 1, ['required' => true, 'max' => 120]),
    'tagline' => sf('Tagline', 'general', 'text', 1, ['max' => 160]),
    'analytics_enabled' => sf('Record anonymous page views for Reports → Traffic (no cookies, no IP addresses stored)', 'general', 'bool', 0),
    'app_android_url' => sf('Android app (Play Store link)', 'general', 'url', 1, ['section' => ['apps', 'Mobile apps', 'Store buttons appear in the footer once a link is set.']]),
    'app_ios_url' => sf('iOS app (App Store link)', 'general', 'url', 1),
    // Academy information
    'contact_email' => sf('Contact email', 'academy', 'email', 1),
    'contact_phone' => sf('Contact phone (display)', 'academy', 'text', 1, ['max' => 40]),
    'whatsapp_number' => sf('WhatsApp number (digits with country code, e.g. 918700511018)', 'academy', 'digits', 1),
    'helpline_label' => sf('Helpline label', 'academy', 'text', 1, ['max' => 60]),
    'address' => sf('Address', 'academy', 'text', 1, ['max' => 300]),
    'office_hours' => sf('Office hours (e.g. Mon - Sat: 9:00 AM - 7:00 PM)', 'academy', 'text', 1, ['max' => 120]),
    'map_query' => sf('Google Maps search text', 'academy', 'text', 1, ['max' => 200]),
    // Company & invoicing
    'company_legal_name' => sf('Registered company name', 'company', 'text', 1, ['required' => true, 'max' => 160, 'section' => ['legal', 'Legal identity', 'Exactly as registered with the Ministry of Corporate Affairs.']]),
    'company_cin' => sf('CIN (Corporate Identity Number)', 'company', 'cin', 1, ['hint' => '21 characters, e.g. U85491DL2026PTC470370']),
    'company_pan' => sf('Company PAN', 'company', 'pan', 0, ['hint' => '10 characters, e.g. ABCDE1234F']),
    'company_registered_address' => sf('Registered office address (leave empty to use the academy address)', 'company', 'textarea', 1, ['max' => 500]),
    'company_state' => sf('State of registration', 'company', 'select', 0, ['options' => $stateOptions, 'required' => true]),
    'company_state_code' => sf('GST state code', 'company', 'statecode', 0, ['hint' => 'Filled in from the state, e.g. Delhi = 07.']),
    'gst_enabled' => sf('Charge GST and issue tax invoices (needs a GSTIN)', 'company', 'bool', 0, ['section' => ['gst', 'GST', 'Same state as the company: CGST + SGST. Other states: IGST. Without GST the document is titled “Invoice” and says GST is not applicable.']]),
    'company_gstin' => sf('Company GSTIN', 'company', 'gstin', 1, ['hint' => '15 characters, e.g. 07ABCDE1234F1Z5']),
    'gst_rate' => sf('GST rate (%)', 'company', 'percent', 0, ['hint' => 'Coaching and training services (SAC 999293): 18%.']),
    'prices_include_gst' => sf('Course prices already include GST (the tax is calculated back from the price; untick to add GST on top at checkout)', 'company', 'bool', 0),
    'sac_code' => sf('SAC code', 'company', 'sac', 0, ['hint' => '999293 = commercial training and coaching services.']),
    'invoice_prefix' => sf('Invoice number prefix', 'company', 'prefix', 0, ['section' => ['invoice', 'Invoice', 'Invoices are numbered consecutively per financial year (April–March).'], 'hint' => '1–5 letters or digits. Numbers look like LXA/26-27/0001 (16 characters at most).']),
    'invoice_signatory' => sf('Signatory line', 'company', 'text', 0, ['max' => 100]),
    'invoice_footer_note' => sf('Note printed on every invoice', 'company', 'textarea', 0, ['max' => 500]),
    'iso_show' => sf('Show the ISO certificate on the website (About page section and footer badge)', 'company', 'bool', 1, ['section' => ['iso', 'ISO certification', 'Shown on the About page and as a footer badge. Hidden automatically after the expiry date. The certificate photo shows only while the certificate number is E20261156292.']]),
    'iso_standard' => sf('Standard', 'company', 'text', 1, ['max' => 40, 'hint' => 'e.g. ISO 9001:2015']),
    'iso_system' => sf('Management system', 'company', 'text', 1, ['max' => 80]),
    'iso_certificate_no' => sf('Certificate number', 'company', 'text', 1, ['max' => 40]),
    'iso_issued_on' => sf('Issue date', 'company', 'date', 1),
    'iso_valid_until' => sf('Expiry date', 'company', 'date', 1),
    'iso_surveillance' => sf('Surveillance audits due', 'company', 'text', 1, ['max' => 120]),
    'iso_body' => sf('Certification body', 'company', 'text', 1, ['max' => 120]),
    'iso_accreditation' => sf('Accreditation', 'company', 'text', 1, ['max' => 160]),
    'iso_scope' => sf('Scope of certification', 'company', 'textarea', 1, ['max' => 600]),
    'iso_locations' => sf('Certified locations (one per line)', 'company', 'textarea', 1, ['max' => 600]),
    'iso_verify_url' => sf('Verification link', 'company', 'url', 1, ['hint' => 'Where visitors can check the certificate, e.g. https://www.iafcertsearch.org']),
    // Date & time
    'timezone' => sf('Academy timezone', 'datetime', 'timezone', 0),
    'date_format' => sf('Date format on invoices', 'datetime', 'select', 1, ['options' => $dateFormats]),
    // Currency
    'currency' => sf('Currency', 'currency', 'select', 1, ['options' => ['INR' => 'Indian Rupee (₹, INR)'], 'required' => true]),
    // Language
    'language_toggle' => sf('Show the हिंदी / English switch in the top bar (uses Google website translator; loads only when a visitor picks Hindi)', 'language', 'bool', 1),
    // Email
    'enquiry_notify_email' => sf('New enquiries', 'email', 'email', 0, ['section' => ['alerts', 'Staff alert addresses', 'Leave an address empty to send that alert to the contact email.']]),
    'recruit_notify_email' => sf('New job applications', 'email', 'email', 0),
    'support_notify_email' => sf('New support tickets', 'email', 'email', 0),
    // Notifications
    'enquiry_autoreply' => sf('Send an automatic acknowledgement email to people who enquire', 'notifications', 'bool', 0),
    'recruit_ack_email' => sf('Email job applicants an acknowledgement', 'notifications', 'bool', 0),
    'newsletter_welcome_email' => sf('Send a welcome email to new newsletter subscribers', 'notifications', 'bool', 0),
    'email_max_attempts' => sf('Retry a failed email up to this many times', 'notifications', 'int', 0, ['min' => 1, 'max' => 10]),
    // Payments
    'payment_notify_email' => sf('Email each successful payment to (accounts)', 'payments', 'email', 0, ['hint' => 'Leave empty to use the contact email.']),
    // Uploads
    'upload_max_mb' => sf('Maximum upload size (MB)', 'uploads', 'int', 0, ['min' => 1, 'max' => 512]),
    // Maintenance
    'maintenance_mode' => sf('Maintenance mode (visitors see a maintenance page; signed-in staff can still browse)', 'maintenance', 'bool', 1),
    'maintenance_message' => sf('Maintenance message shown to visitors', 'maintenance', 'textarea', 1, ['max' => 500]),
    // Cache
    'cache_enabled' => sf('Cache the homepage and site layout for 5 minutes (cleared automatically whenever an admin saves a change)', 'cache', 'bool', 0),
    // Social
    'social_youtube' => sf('YouTube', 'social', 'url', 1, ['icon' => 'youtube']),
    'social_facebook' => sf('Facebook', 'social', 'url', 1, ['icon' => 'facebook']),
    'social_instagram' => sf('Instagram', 'social', 'url', 1, ['icon' => 'instagram']),
    'social_x' => sf('X (Twitter)', 'social', 'url', 1, ['icon' => 'x']),
    'social_linkedin' => sf('LinkedIn', 'social', 'url', 1, ['icon' => 'linkedin']),
    'social_threads' => sf('Threads', 'social', 'url', 1, ['icon' => 'threads']),
    'social_telegram' => sf('Telegram', 'social', 'url', 1, ['icon' => 'telegram']),
    'social_whatsapp_channel' => sf('WhatsApp Channel', 'social', 'url', 1, ['icon' => 'whatsapp']),
    // SEO
    'default_seo_title' => sf('Default SEO title', 'seo', 'text', 1, ['max' => 160]),
    'default_seo_description' => sf('Default meta description', 'seo', 'textarea', 1, ['max' => 320]),
    'robots_extra' => sf('Extra robots.txt rules, one per line (e.g. Disallow: /old-page). Admin, API, dashboard and checkout are always blocked.', 'seo', 'robots', 0),
    // Forms & features
    'enquiry_require_phone' => sf('Require a phone number on enquiry forms', 'features', 'bool', 1, ['section' => ['enquiry-form', 'Enquiry form', null]]),
    'recruit_general_applications' => sf('Accept general applications (without choosing a role)', 'features', 'bool', 1, ['section' => ['recruitment', 'Recruitment', null]]),
    'support_default_assignee' => sf('Auto-assign new tickets to', 'features', 'staff', 0, ['section' => ['support', 'Support desk', null]]),
    'live_join_window_minutes' => sf('Join button opens this many minutes before a class', 'features', 'int', 1, ['section' => ['live-classes', 'Live classes', null], 'min' => 0, 'max' => 240]),
    'live_reminder_lead_minutes' => sf('Send reminders this many minutes before a class (cron)', 'features', 'int', 0, ['min' => 0, 'max' => 1440]),
    'recaptcha_site_key' => sf('Google reCAPTCHA v3 site key (leave empty to turn off)', 'features', 'text', 1, ['section' => ['spam-protection', 'Spam protection (reCAPTCHA v3)', 'The check runs only when both the site key and RECAPTCHA_SECRET in .env are set.'], 'max' => 100]),
    'recaptcha_min_score' => sf('Minimum score to accept (0.1 - 0.9; 0.5 recommended)', 'features', 'score', 0),
];

/** Validate + normalise one posted value. Returns [value, error|null]. */
function setting_validate(array $f, string $v): array
{
    $max = $f['max'] ?? 1000;
    $upper = in_array($f['type'], ['cin', 'gstin', 'pan', 'prefix'], true);
    if ($upper) {
        $v = strtoupper(str_replace(' ', '', $v));
    }
    if ($v === '' && !empty($f['required'])) {
        return [$v, 'This field is required.'];
    }
    $err = match ($f['type']) {
        'bool' => null,
        'url' => $v !== '' && !preg_match('#^https://\S+$#i', $v) ? 'Use a full https:// link.' : null,
        'email' => $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL) ? 'Enter a valid email.' : null,
        'digits' => $v !== '' && !preg_match('/^\d{10,15}$/', $v) ? 'Digits only, including country code.' : null,
        'int' => $v !== '' && (!ctype_digit($v) || (int) $v < ($f['min'] ?? 0) || (int) $v > ($f['max'] ?? 1440))
            ? 'Enter a whole number from ' . ($f['min'] ?? 0) . ' to ' . ($f['max'] ?? 1440) . '.' : null,
        'staff' => $v !== '' && !db_value("SELECT 1 FROM users WHERE id = ? AND user_type = 'staff' AND status = 'active'", [(int) $v]) ? 'Choose an active staff member.' : null,
        'score' => $v !== '' && (!is_numeric($v) || (float) $v < 0.1 || (float) $v > 0.9) ? 'Enter a number between 0.1 and 0.9.' : null,
        'robots' => ($bad = array_filter(preg_split('/\R/', $v), fn($l) => trim($l) !== '' && !preg_match('/^(User-agent|Disallow|Allow|Crawl-delay):\s*[\w\-\/*.$?=&%]*$/i', trim($l))))
            ? 'Not a valid rule: “' . mb_strimwidth(trim((string) reset($bad)), 0, 60, '…') . '”. Use User-agent:, Disallow:, Allow: or Crawl-delay:.' : null,
        'select' => $v !== '' && !array_key_exists($v, $f['options']) ? 'Choose one of the options.' : null,
        'timezone' => $v !== '' && !in_array($v, DateTimeZone::listIdentifiers(), true) ? 'Choose a timezone from the list.' : null,
        'cin' => $v !== '' && !preg_match(CIN_REGEX, $v) ? 'A CIN has 21 characters, e.g. U85491DL2026PTC470370.' : null,
        'gstin' => $v !== '' && !preg_match(GSTIN_REGEX, $v) ? 'A GSTIN has 15 characters, e.g. 07ABCDE1234F1Z5.' : null,
        'pan' => $v !== '' && !preg_match(PAN_REGEX, $v) ? 'A PAN has 10 characters, e.g. ABCDE1234F.' : null,
        'statecode' => $v !== '' && (!preg_match('/^\d{2}$/', $v) || !in_array($v, indian_states(), true)) ? 'Enter a valid 2-digit GST state code.' : null,
        'percent' => $v !== '' && (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $v) || (float) $v > 40) ? 'Enter a rate from 0 to 40 (up to 2 decimals).' : null,
        'sac' => $v !== '' && !preg_match('/^\d{4,8}$/', $v) ? 'A SAC code has 4 to 8 digits.' : null,
        'prefix' => $v !== '' && !preg_match('/^[A-Z0-9]{1,5}$/', $v) ? 'Use 1 to 5 letters or digits.' : null,
        'date' => $v !== '' && !(preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) ? 'Choose a valid date.' : null,
        default => mb_strlen($v) > $max ? "Keep this under {$max} characters." : null,
    };
    if (!$err && in_array($f['type'], ['text', 'textarea'], true) && mb_strlen($v) > $max) {
        $err = "Keep this under {$max} characters.";
    }
    return [$v, $err];
}

$errors = [];
$postedPanel = null;

if (admin_post()) {
    $do = (string) ($_POST['do'] ?? 'save');
    if ($do === 'clear_cache') {
        $n = cache_clear();
        audit_log('cache_cleared', 'cache', null, ['files' => $n]);
        flash('success', "Cache cleared ({$n} file" . ($n === 1 ? '' : 's') . ').');
        redirect_to('settings.php#cache');
    }
    if ($do === 'test_email') {
        $to = trim((string) ($_POST['test_to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email address for the test.');
        } elseif (!rate_limit('settings-test-email', (string) $me['id'], 5, 600)) {
            flash('error', 'Too many test emails. Please wait a few minutes.');
        } else {
            require_once APP_ROOT . '/includes/mailer.php';
            $site = (string) setting('site_name', 'Laxyam Academy');
            $ok = send_mail($to, 'Test email from ' . $site, mail_template('Test email', '<p>This is a test email sent from ' . e($site) . ' admin → System Settings by ' . e($me['name']) . ' at ' . e(date('j M Y, g:i A')) . '.</p><p>If you can read this, outgoing email works.</p>'));
            audit_log('test_email_sent', 'settings', null, ['to' => $to, 'delivered' => $ok]);
            if ($ok) {
                flash('success', "Test email sent to {$to}. Check the inbox (and the spam folder).");
            } elseif (!mail_configured()) {
                flash('warning', 'SMTP is not configured (Keys & Credentials → Email), so the test email was written to uploads/private/logs/mail.log instead of being sent.');
            } else {
                flash('error', 'The mail server rejected the test email. Check the SMTP server, port, username and password in Keys & Credentials and the PHP error log.');
            }
        }
        redirect_to('settings.php#email');
    }

    $panel = (string) ($_POST['_panel'] ?? '');
    if (!isset($panels[$panel])) {
        flash('error', 'Unknown settings section.');
        redirect_to('settings.php');
    }
    $postedPanel = $panel;
    $values = [];
    foreach ($fields as $k => $f) {
        if ($f['panel'] !== $panel) {
            continue;
        }
        if ($f['type'] === 'bool') {
            $values[$k] = empty($_POST[$k]) ? '0' : '1';
            continue;
        }
        [$v, $err] = setting_validate($f, trim((string) ($_POST[$k] ?? '')));
        if ($err) {
            $errors[$k] = $err;
        }
        $values[$k] = $v;
    }

    // Cross-field rules for Company & Invoicing
    if ($panel === 'company') {
        $state = $values['company_state'] ?? '';
        $code = $values['company_state_code'] ?? '';
        if ($state !== '' && $code === '' && empty($errors['company_state'])) {
            $values['company_state_code'] = $code = indian_states()[$state];
        }
        if ($state !== '' && $code !== '' && empty($errors['company_state_code']) && indian_states()[$state] !== $code) {
            $errors['company_state_code'] = "The GST state code for {$state} is " . indian_states()[$state] . '.';
        }
        $gstin = $values['company_gstin'] ?? '';
        if ($values['gst_enabled'] === '1' && $gstin === '' && empty($errors['company_gstin'])) {
            $errors['company_gstin'] = 'Enter the company GSTIN to charge GST, or untick “Charge GST”.';
        }
        if ($values['gst_enabled'] === '1' && (float) ($values['gst_rate'] ?? 0) <= 0 && empty($errors['gst_rate'])) {
            $errors['gst_rate'] = 'Enter the GST rate (18% for coaching services).';
        }
        if ($gstin !== '' && empty($errors['company_gstin'])) {
            if ($code !== '' && substr($gstin, 0, 2) !== $code) {
                $errors['company_gstin'] = 'This GSTIN starts with ' . substr($gstin, 0, 2) . ', but the state code is ' . $code . '.';
            } elseif (($values['company_pan'] ?? '') !== '' && empty($errors['company_pan']) && substr($gstin, 2, 10) !== $values['company_pan']) {
                $errors['company_pan'] = 'The PAN does not match the PAN inside the GSTIN (' . substr($gstin, 2, 10) . ').';
            }
        }
    }

    if (!$errors) {
        foreach ($values as $k => $v) {
            // Upsert value AND metadata (group/public flag), so older rows are corrected too.
            db_query('INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public, updated_by) VALUES (?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group),
                                              is_public = VALUES(is_public), updated_by = VALUES(updated_by)',
                [$k, $v, $panel, $fields[$k]['public'], $me['id']]);
        }
        audit_log('settings_updated', 'site_settings', $panel, ['keys' => array_keys($values)], 'warning');
        flash('success', $panels[$panel][0] . ' settings saved.');
        redirect_to('settings.php#' . $panel);
    }
    flash('error', 'Please correct the highlighted fields in ' . $panels[$panel][0] . '.');
}

$current = settings_all();
$value = function (string $k) use ($current, $postedPanel, $fields): string {
    if ($postedPanel !== null && $fields[$k]['panel'] === $postedPanel) {
        return $fields[$k]['type'] === 'bool' ? (empty($_POST[$k]) ? '0' : '1') : (string) ($_POST[$k] ?? '');
    }
    return (string) ($current[$k] ?? ISO_CERT_DEFAULTS[$k] ?? ''); // ISO fields start with the certificate on file
};

/** One form field. */
function render_setting_field(string $k, array $f, string $v, ?string $err): string
{
    $wide = in_array($f['type'], ['textarea', 'bool', 'robots'], true);
    $h = '<div class="field' . ($wide ? ' full' : '') . ($err ? ' invalid' : '') . '">';
    $id = 's-' . $k;
    $described = ($f['hint'] ?? null) || $err ? ' aria-describedby="' . $id . '-d"' : '';
    $req = !empty($f['required']) ? ' required aria-required="true"' : '';
    switch ($f['type']) {
        case 'bool':
            $h .= '<label class="check"><input type="checkbox" id="' . $id . '" name="' . e($k) . '" value="1"' . ($v === '1' ? ' checked' : '') . $described . '> ' . e($f['label']) . '</label>';
            break;
        case 'staff':
            $h .= '<label for="' . $id . '">' . e($f['label']) . '</label><select id="' . $id . '" name="' . e($k) . '"' . $described . '><option value="">Nobody (unassigned)</option>';
            foreach (db_all("SELECT id, name FROM users WHERE user_type = 'staff' AND status = 'active' ORDER BY name") as $st) {
                $h .= '<option value="' . (int) $st['id'] . '"' . ($v === (string) $st['id'] ? ' selected' : '') . '>' . e($st['name']) . '</option>';
            }
            $h .= '</select>';
            break;
        case 'select':
            $h .= '<label for="' . $id . '">' . e($f['label']) . '</label><select id="' . $id . '" name="' . e($k) . '"' . $req . $described . '>';
            if (empty($f['required'])) {
                $h .= '<option value="">Default</option>';
            }
            foreach ($f['options'] as $ov => $ol) {
                $h .= '<option value="' . e((string) $ov) . '"' . ($v === (string) $ov ? ' selected' : '') . '>' . e($ol) . '</option>';
            }
            $h .= '</select>';
            break;
        case 'timezone':
            $h .= '<label for="' . $id . '">' . e($f['label']) . '</label><select id="' . $id . '" name="' . e($k) . '"' . $described . '>';
            $h .= '<option value="Asia/Kolkata"' . ($v === 'Asia/Kolkata' || $v === '' ? ' selected' : '') . '>Asia/Kolkata (India Standard Time, UTC+05:30)</option>';
            $groups = [];
            foreach (DateTimeZone::listIdentifiers() as $tz) {
                if ($tz !== 'Asia/Kolkata') {
                    $groups[strtok($tz, '/')][] = $tz;
                }
            }
            foreach ($groups as $region => $zones) {
                $h .= '<optgroup label="' . e($region) . '">';
                foreach ($zones as $tz) {
                    $h .= '<option value="' . e($tz) . '"' . ($v === $tz ? ' selected' : '') . '>' . e(str_replace('_', ' ', $tz)) . '</option>';
                }
                $h .= '</optgroup>';
            }
            $h .= '</select>';
            break;
        case 'textarea':
        case 'robots':
            $h .= '<label for="' . $id . '">' . e($f['label']) . '</label><textarea id="' . $id . '" name="' . e($k) . '" rows="' . ($f['type'] === 'robots' ? 4 : 3) . '"'
                . ($f['type'] === 'robots' ? ' class="code" placeholder="Disallow: /old-page"' : ' maxlength="' . (int) ($f['max'] ?? 1000) . '"') . $described . '>' . e($v) . '</textarea>';
            if ($f['type'] === 'robots') {
                $h .= '<div class="hint"><a href="' . e(app_url('robots.txt')) . '" target="_blank" rel="noopener">View live robots.txt</a>' . (is_production() ? '' : ' · In development, robots.txt blocks everything so test copies are never indexed.') . '</div>';
            }
            break;
        default:
            $type = match ($f['type']) { 'url' => 'url', 'email' => 'email', 'int' => 'number', 'date' => 'date', default => 'text' };
            $attrs = match ($f['type']) {
                'int' => ' min="' . (int) ($f['min'] ?? 0) . '" max="' . (int) ($f['max'] ?? 1440) . '" step="1" inputmode="numeric"',
                'percent' => ' inputmode="decimal" maxlength="5"',
                'cin' => ' maxlength="21" autocapitalize="characters" spellcheck="false"',
                'gstin' => ' maxlength="15" autocapitalize="characters" spellcheck="false"',
                'pan' => ' maxlength="10" autocapitalize="characters" spellcheck="false"',
                'prefix' => ' maxlength="5" autocapitalize="characters" spellcheck="false"',
                'statecode' => ' maxlength="2" inputmode="numeric"',
                'sac' => ' maxlength="8" inputmode="numeric"',
                'digits' => ' inputmode="numeric" maxlength="15"',
                default => isset($f['max']) ? ' maxlength="' . (int) $f['max'] . '"' : '',
            };
            $h .= '<label for="' . $id . '">' . (!empty($f['icon']) ? icon((string) $f['icon']) . ' ' : '') . e($f['label']) . '</label><input id="' . $id . '" type="' . $type . '" name="' . e($k) . '" value="' . e($v) . '"' . $attrs . $req . $described . '>';
    }
    if ($err || !empty($f['hint'])) {
        $h .= '<div id="' . $id . '-d">' . ($err ? '<div class="err">' . e($err) . '</div>' : '') . (!empty($f['hint']) ? '<div class="hint">' . e($f['hint']) . '</div>' : '') . '</div>';
    }
    return $h . '</div>';
}

/** Read-only status pill. */
$ok = fn(bool $b, string $yes = 'Configured', string $no = 'Not configured') => $b ? '<span class="badge b-green">' . e($yes) . '</span>' : '<span class="badge b-red">' . e($no) . '</span>';

// ---------------------------------------------------------------- page
admin_start('System Settings', null, 'settings', ['head' => true, 'subtitle' => 'Changes apply to the website immediately. Each tab saves on its own.']);
$errorPanels = $postedPanel && $errors ? [$postedPanel => count($errors)] : [];
?>
<style>
  /* Fallback look for the in-page tab buttons (admin.js drives [data-tabs]; its own styles take precedence). */
  .st-tablist{display:flex;gap:6px;overflow-x:auto;scroll-snap-type:x proximity;padding:2px 2px 10px;margin:0 0 12px;scrollbar-width:thin}
  .st-tablist button{flex:0 0 auto;scroll-snap-align:start;display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:999px;border:1px solid var(--line,#E2EAF4);background:#fff;color:#334155;font:600 13px/1.2 inherit;cursor:pointer;white-space:nowrap}
  .st-tablist button:hover{border-color:#0B63F6;color:#0B63F6}
  .st-tablist button[aria-selected="true"],.st-tablist button.on,.st-tablist button.active,.st-tablist button.is-active{background:#08264D;border-color:#08264D;color:#fff}
  .st-tablist button:focus-visible{outline:3px solid #FFC928;outline-offset:2px}
  .st-tablist svg.i{width:16px;height:16px}
  .st-panel .form-sec{grid-column:1/-1;margin:10px 0 0;padding-top:14px;border-top:1px solid var(--line,#E2EAF4);scroll-margin-top:96px}
  .st-panel .form-sec:first-child{margin-top:0;padding-top:0;border-top:0}
  .st-panel .form-sec h3{margin:0;font-size:14px;color:var(--navy,#08264D)}
  .st-panel .form-sec p{margin:3px 0 0;font-size:12.5px}
  .st-panel .card + .card{margin-top:18px}
  .st-panel .card-head p{margin:2px 0 0;font-size:12.5px}
  .st-panel .info-grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
  .st-panel .info{border:1px solid var(--line,#E2EAF4);border-radius:12px;padding:12px 14px;background:var(--surface,#F5F9FF)}
  .st-panel .info small{display:block;color:var(--muted,#64748B);font-size:12px}
  .st-panel .info strong{display:block;margin-top:2px;color:var(--navy,#08264D);word-break:break-word}
  .st-tab-err{display:inline-grid;place-items:center;min-width:18px;height:18px;padding:0 5px;margin-left:6px;border-radius:9px;background:#DC2626;color:#fff;font-size:11px;font-weight:700}
  .st-inline{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
  .st-inline .field{flex:1;min-width:220px;margin:0}
</style>
<?php if ($postedPanel && $errors): ?>
<script>try{history.replaceState(null,'',location.pathname+location.search+'#<?= e($postedPanel) ?>')}catch(e){}</script>
<?php endif; ?>
<script>
// Older links (settings.php#contact, #performance, #live-classes …) open the matching tab and section.
(function(){
  var alias = <?= json_encode($aliases, JSON_UNESCAPED_SLASHES) ?>;
  function fix(){
    var h = location.hash.slice(1), t = alias[h];
    if (!t) { return false; }
    history.replaceState(null, '', location.pathname + location.search + '#' + t[0]);
    if (t[1]) { setTimeout(function(){ var el = document.getElementById(t[1]); if (el) { el.scrollIntoView({block: 'start'}); } }, 80); }
    return true;
  }
  fix();
  window.addEventListener('hashchange', function(){ if (fix()) { window.dispatchEvent(new HashChangeEvent('hashchange')); } });
  // Fallback only: if admin.js has not initialised [data-tabs] (older admin.js), run a minimal
  // hash-synced tab switcher so the panels still show one at a time.
  window.addEventListener('load', function(){
    var root = document.querySelector('.st-tabs[data-tabs]');
    if (!root) { return; }
    var btns = [].slice.call(root.querySelectorAll('[data-tab]')), panels = [].slice.call(root.querySelectorAll('[data-tab-panel]'));
    var handled = btns.some(function(b){ return b.hasAttribute('aria-selected') || /\b(on|active|is-active)\b/.test(b.className); })
      || panels.some(function(p){ return p.hidden || getComputedStyle(p).display === 'none'; });
    if (handled) { return; }
    root.querySelector('.st-tablist').setAttribute('role', 'tablist');
    function show(id, focus){
      if (!panels.some(function(p){ return p.getAttribute('data-tab-panel') === id; })) { id = btns[0].getAttribute('data-tab'); }
      btns.forEach(function(b){ var on = b.getAttribute('data-tab') === id; b.setAttribute('role', 'tab'); b.setAttribute('aria-selected', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1; if (on && focus) { b.focus(); } if (on) { b.scrollIntoView({block: 'nearest', inline: 'nearest'}); } });
      panels.forEach(function(p){ p.setAttribute('role', 'tabpanel'); p.hidden = p.getAttribute('data-tab-panel') !== id; });
    }
    btns.forEach(function(b, i){
      b.addEventListener('click', function(){ var id = b.getAttribute('data-tab'); history.replaceState(null, '', location.pathname + location.search + '#' + id); show(id); });
      b.addEventListener('keydown', function(ev){
        var k = ev.key === 'ArrowRight' ? 1 : ev.key === 'ArrowLeft' ? -1 : 0;
        if (k) { ev.preventDefault(); var n = btns[(i + k + btns.length) % btns.length]; n.click(); n.focus(); }
      });
    });
    window.addEventListener('hashchange', function(){ show(location.hash.slice(1)); });
    show(location.hash.slice(1));
  });
  // State -> GST state code on the Company tab.
  document.addEventListener('change', function(ev){
    if (ev.target && ev.target.id === 's-company_state') {
      var codes = <?= json_encode(indian_states(), JSON_UNESCAPED_SLASHES) ?>, box = document.getElementById('s-company_state_code');
      if (box && codes[ev.target.value]) { box.value = codes[ev.target.value]; }
    }
  });
})();
</script>
<div data-tabs class="st-tabs">
  <div class="st-tablist" aria-label="Settings sections">
    <?php foreach ($panels as $pid => [$label, $ico]): ?>
      <button type="button" data-tab="<?= e($pid) ?>"><?= icon($ico) ?> <span><?= e($label) ?></span><?= isset($errorPanels[$pid]) ? '<span class="st-tab-err" title="Needs attention">' . (int) $errorPanels[$pid] . '</span>' : '' ?></button>
    <?php endforeach; ?>
  </div>
<?php
foreach ($panels as $pid => [$label, $ico, $desc]) {
    echo '<section data-tab-panel="' . e($pid) . '" class="st-panel" aria-label="' . e($label) . '">';

    // ---- Read-only blocks shown above the form
    if ($pid === 'email') {
        $smtpHost = (string) config('SMTP_HOST', '');
        echo '<div class="card" style="margin-bottom:18px"><div class="card-head"><div><h2>Mail server</h2><p class="muted">SMTP credentials are set in <a href="keys.php#email">Keys &amp; Credentials → Email</a>.</p></div>' . $ok($smtpHost !== '') . '</div><div class="card-pad"><div class="info-grid">'
            . '<div class="info"><small>SMTP host</small><strong>' . e($smtpHost !== '' ? $smtpHost : 'Not set - emails are written to uploads/private/logs/mail.log') . '</strong></div>'
            . '<div class="info"><small>Port · security</small><strong>' . e((string) config('SMTP_PORT', '587')) . ' · ' . e(strtoupper((string) config('SMTP_SECURE', 'tls'))) . '</strong></div>'
            . '<div class="info"><small>Authentication</small><strong>' . ((string) config('SMTP_USER', '') !== '' ? 'Username + password set' : 'No username') . '</strong></div>'
            . '<div class="info"><small>From</small><strong>' . e((string) config('MAIL_FROM_NAME', 'Laxyam Academy')) . ' &lt;' . e((string) config('MAIL_FROM', 'info@laxyamacademy.com')) . '&gt;</strong></div>'
            . '</div>'
            . '<form method="post" class="st-inline" style="margin-top:16px">' . csrf_field() . '<input type="hidden" name="do" value="test_email">'
            . '<div class="field"><label for="test-to">Send a test email to</label><input id="test-to" type="email" name="test_to" value="' . e($me['email']) . '" required></div>'
            . '<button class="btn btn-light">' . icon('mail') . ' Send test email</button></form></div></div>';
    }
    if ($pid === 'payments') {
        require_once APP_ROOT . '/includes/payments.php';
        $known = in_array(payment_provider(), ['razorpay', 'cashfree'], true);
        $g = payment_gateway_status($known ? payment_provider() : 'razorpay');
        $keys = $known && $g['configured'];
        $isCf = $g['provider'] === 'cashfree';
        echo '<div class="card" style="margin-bottom:18px"><div class="card-head"><div><h2>Payment gateway: ' . e($known ? $g['label'] : payment_provider()) . '</h2><p class="muted">The gateway, mode and keys' . ($isCf ? '' : ' and the webhook secret') . ' are set in <a href="keys.php#payments">Keys &amp; Credentials → Payment gateway</a>.</p></div>' . $ok($keys) . '</div><div class="card-pad"><div class="info-grid">'
            . '<div class="info"><small>Mode</small><strong>' . e(ucfirst($g['mode'])) . ($g['mode'] === 'live' ? '' : ' (test payments only)') . '</strong></div>'
            . '<div class="info"><small>API keys</small><strong>' . (!$known ? 'PAYMENT_PROVIDER not supported - paid checkout is disabled' : ($keys ? ($isCf ? 'App ID ' : 'Key ID ') . e((string) $g['key_hint']) : e(implode(' / ', $g['missing'])) . ' not set - paid checkout is disabled')) . '</strong></div>'
            . '<div class="info"><small>Webhook</small><strong>' . ($isCf ? ($keys ? 'Signed with the client secret' : 'Needs the keys') : ((string) config('RAZORPAY_WEBHOOK_SECRET', '') !== '' ? 'Secret set' : 'Secret not set'))
                . ($g['last_webhook'] ? ' · last ' . e(fmt_ago((string) $g['last_webhook']['received_at'])) : ' · none received yet') . '</strong></div>'
            . '<div class="info"><small>GST on checkout</small><strong>' . (gst_config()['enabled'] ? e(rtrim(rtrim(number_format(gst_config()['rate'], 2), '0'), '.')) . '% · ' . (gst_config()['inclusive'] ? 'included in prices' : 'added at checkout') : 'Not charged') . '</strong></div>'
            . '</div><p style="margin:14px 0 0;display:flex;gap:10px;flex-wrap:wrap">'
            . (can('payments.view') ? '<a class="btn btn-light btn-sm" href="payments.php?tab=setup">' . icon('plug') . ' Gateway setup</a>' : '')
            . (can('orders.view') ? '<a class="btn btn-light btn-sm" href="invoices.php">' . icon('file') . ' Invoices</a>' : '')
            . '<a class="btn btn-light btn-sm" href="#company">' . icon('settings') . ' Company &amp; GST settings</a></p></div></div>';
    }
    if ($pid === 'company') {
        $fy = financial_year();
        $nextNo = (int) db_value('SELECT last_no FROM invoice_sequences WHERE financial_year = ?', [$fy]) + 1;
        $cfg = gst_config();
        echo '<div class="card" style="margin-bottom:18px"><div class="card-pad"><div class="info-grid">'
            . '<div class="info"><small>Next invoice number</small><strong>' . e(invoice_prefix() . '/' . substr($fy, 2) . '/' . str_pad((string) $nextNo, 4, '0', STR_PAD_LEFT)) . '</strong></div>'
            . '<div class="info"><small>Document title</small><strong>' . ($cfg['enabled'] ? 'Tax Invoice' : 'Invoice (GST not applicable)') . '</strong></div>'
            . '<div class="info"><small>GST</small><strong>' . ($cfg['enabled'] ? e(rtrim(rtrim(number_format($cfg['rate'], 2), '0'), '.')) . '% · CGST + SGST within ' . e($cfg['seller_state']) . ', IGST elsewhere' : 'Not charged') . '</strong></div>'
            . (can('orders.view') ? '<div class="info"><small>Issued this financial year</small><strong><a href="invoices.php?fy=' . e($fy) . '">' . number_format((int) db_value('SELECT COUNT(*) FROM invoices WHERE financial_year = ?', [$fy])) . ' invoices →</a></strong></div>' : '')
            . '</div></div></div>';
    }
    if ($pid === 'datetime') {
        $saved = (string) ($current['timezone'] ?? '');
        $effective = date_default_timezone_get();
        echo '<div class="card" style="margin-bottom:18px"><div class="card-pad"><div class="info-grid">'
            . '<div class="info"><small>Server clock now</small><strong>' . e(date('D, j M Y, g:i A')) . '</strong></div>'
            . '<div class="info"><small>Server timezone (APP_TIMEZONE in .env)</small><strong>' . e($effective) . ' (UTC' . e(date('P')) . ')</strong></div>'
            . '<div class="info"><small>Database session offset</small><strong>' . e((string) db_value('SELECT @@session.time_zone')) . '</strong></div>'
            . '</div>' . ($saved !== '' && $saved !== $effective ? '<div class="alert alert-warning" style="margin:14px 0 0">The saved academy timezone (' . e($saved) . ') differs from the server timezone (' . e($effective) . '). Set <code>APP_TIMEZONE=' . e($saved) . '</code> in .env so stored times and the server clock agree.</div>' : '')
            . '</div></div>';
    }
    if ($pid === 'uploads') {
        $toBytes = function (string $v): int {
            $n = (int) $v;
            return match (strtolower(substr(trim($v), -1))) { 'g' => $n * 1_073_741_824, 'm' => $n * 1_048_576, 'k' => $n * 1024, default => $n };
        };
        $php = min($toBytes((string) ini_get('upload_max_filesize')), $toBytes((string) ini_get('post_max_size')));
        $app = upload_limit_bytes();
        echo '<div class="card" style="margin-bottom:18px"><div class="card-pad"><div class="info-grid">'
            . '<div class="info"><small>PHP upload_max_filesize</small><strong>' . e((string) ini_get('upload_max_filesize')) . '</strong></div>'
            . '<div class="info"><small>PHP post_max_size</small><strong>' . e((string) ini_get('post_max_size')) . '</strong></div>'
            . '<div class="info"><small>Effective limit</small><strong>' . e(number_format(min($php, $app) / 1_048_576, 0)) . ' MB</strong></div>'
            . '</div>' . ($app > $php ? '<div class="alert alert-warning" style="margin:14px 0 0">The server (PHP) only accepts ' . e(number_format($php / 1_048_576, 0)) . ' MB per upload. Ask your host to raise upload_max_filesize and post_max_size, or lower the limit below.</div>' : '')
            . '</div></div>';
    }
    if ($pid === 'currency') {
        echo '<div class="alert alert-info" style="margin-bottom:18px">Online checkout, GST and invoices are in Indian Rupees only. Amounts use Indian digit grouping (₹1,00,000) on the website, in emails and on invoices.</div>';
    }

    // ---- The panel form
    $panelFields = array_filter($fields, fn($f) => $f['panel'] === $pid);
    if ($panelFields) {
        echo '<form method="post" class="card" novalidate data-dirty-check>' . csrf_field() . '<input type="hidden" name="_panel" value="' . e($pid) . '">'
            . '<div class="card-head"><div><h2>' . icon($ico) . ' ' . e($label) . '</h2><p class="muted">' . e($desc) . '</p></div></div><div class="card-pad form">';
        foreach ($panelFields as $k => $f) {
            if (!empty($f['section'])) {
                [$sid, $stitle, $sdesc] = $f['section'];
                echo '<div class="form-sec" id="sec-' . e($sid) . '"><h3>' . e($stitle) . '</h3>' . ($sdesc ? '<p class="muted">' . e($sdesc) . '</p>' : '') . '</div>';
            }
            echo render_setting_field($k, $f, $value($k), $errors[$k] ?? null);
            if ($k === 'recaptcha_site_key') {
                echo '<div class="field"><label>reCAPTCHA secret key (<a href="keys.php#recaptcha">Keys &amp; Credentials</a>)</label><div style="padding-top:6px">' . $ok((string) config('RECAPTCHA_SECRET', '') !== '', 'Set', 'Not set') . '</div></div>';
            }
        }
        echo '</div><div class="form-actions"><button class="btn btn-primary">' . icon('check') . ' Save ' . e($label) . '</button></div></form>';
    }

    // ---- Blocks shown below the form
    if ($pid === 'cache') {
        $cacheFiles = glob(APP_ROOT . '/uploads/private/cache/*.json') ?: [];
        echo '<form method="post" class="card"><div class="card-pad" style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap">' . csrf_field()
            . '<div><strong>Cached items</strong><div class="muted">' . count($cacheFiles) . ' cached item' . (count($cacheFiles) === 1 ? '' : 's') . '. Clear the cache if a change is not showing on the website.</div></div>'
            . '<button class="btn btn-light" name="do" value="clear_cache">' . icon('history') . ' Clear cache</button></div></form>';
    }
    if ($pid === 'notifications' && (can('notifications.send') || can('settings.manage'))) {
        echo '<div class="card"><div class="card-pad" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between"><span class="muted">Edit the wording of each automatic email in Email Templates.</span>'
            . '<a class="btn btn-light btn-sm" href="templates.php">' . icon('mail') . ' Email templates</a></div></div>';
    }
    if ($pid === 'general') {
        // Active gateway (PAYMENT_PROVIDER), not a fixed one: Cashfree or Razorpay keys + mode.
        require_once APP_ROOT . '/includes/payments.php';
        $gwKnown = in_array(payment_provider(), ['razorpay', 'cashfree'], true);
        $gwSys = payment_gateway_status($gwKnown ? payment_provider() : 'razorpay');
        echo '<div class="card" id="sec-system" style="scroll-margin-top:96px"><div class="card-head"><div><h2>' . icon('database') . ' System information (.env - read only)</h2><p class="muted">API keys and passwords: <a href="keys.php">Keys &amp; Credentials</a>. Backups: System → Backup &amp; Restore.</p></div></div><div class="card-pad"><dl class="kv">'
            . '<dt>Environment</dt><dd>' . e((string) config('APP_ENV', 'production')) . ' ' . (is_production() ? '' : '<span class="badge b-amber">search engines blocked</span>') . '</dd>'
            . '<dt>Site URL</dt><dd>' . e((string) config('APP_URL', '')) . '</dd>'
            . '<dt>Email (SMTP)</dt><dd>' . $ok((string) config('SMTP_HOST', '') !== '') . '</dd>'
            . '<dt>Payments (' . e($gwKnown ? $gwSys['label'] : payment_provider() . ' - not supported') . ')</dt><dd>' . $ok($gwKnown && $gwSys['configured']) . ' · ' . e($gwSys['mode']) . ' mode</dd>'
            . '<dt>WhatsApp Cloud API</dt><dd>' . $ok((string) config('WHATSAPP_TOKEN', '') !== '' && (string) config('WHATSAPP_PHONE_ID', '') !== '') . '</dd>'
            . '<dt>PHP</dt><dd>' . e(PHP_VERSION) . ' · GD ' . (function_exists('imagecreatefromstring') ? 'available' : 'missing (invoice logo shows as text)') . '</dd>'
            . '<dt>Database schema</dt><dd>version ' . (int) db_value('SELECT MAX(version) FROM schema_migrations') . '</dd>'
            . '</dl></div></div>';
    }
    echo '</section>';
}
?>
</div>
<?php admin_end();
