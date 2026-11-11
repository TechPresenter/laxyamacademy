<?php
/**
 * Public enquiry / lead capture and newsletter subscriptions.
 *
 *   POST enquiries.php   JSON: name, email, phone, whatsapp, qualification, subject, message, course (or course_slug),
 *                        source, consent (bool), popup_id, page_url, website (honeypot)
 *        → 201 { received: true, message }
 *        With consent + phone, the WhatsApp "Enquiry confirmation" (admin → WhatsApp) is sent when enabled.
 *   POST enquiries.php?action=newsletter   { email, name?, source?, consent?, website }
 *        → 201 { subscribed: true, message }
 *   GET  enquiries.php?action=unsubscribe&token=…   branded HTML confirmation page (link in every newsletter email)
 *
 * Staff manage enquiries in admin/enquiries.php and subscribers in admin/newsletter.php.
 */
declare(strict_types=1);

require __DIR__ . '/config.php';

/** Where a lead came from. Legacy values (hero_card, careers, other) stay accepted for older pages. */
const ENQUIRY_SOURCES = ['contact_form', 'callback', 'popup', 'course_page', 'newsletter_block', 'chatbot', 'home_cta', 'hero_card', 'careers', 'other'];
/** These forms collect a phone number for a call back, so they must record explicit consent. */
const CONSENT_REQUIRED_SOURCES = ['popup', 'course_page'];
/** Short forms where a message is optional (an automatic summary is stored instead). */
const MESSAGE_OPTIONAL_SOURCES = ['callback', 'popup', 'course_page', 'home_cta'];
const NEWSLETTER_SOURCES = ['footer', 'home', 'page', 'popup', 'blog', 'course_page', 'newsletter_block', 'other'];
/** Education choices of the course admission form (js/components.jsx QUALIFICATIONS). */
const ENQUIRY_QUALIFICATIONS = ['10th pass', '12th pass', 'Graduate', 'Post graduate', 'Pursuing graduation', 'Other'];

$action = $_GET['action'] ?? '';

if ($action === 'unsubscribe') {
    require_method('GET');
    newsletter_unsubscribe_page(query_str('token', 64));
}

require_method('POST');
require_csrf();

$input = request_body();

if ($action === 'newsletter') {
    newsletter_subscribe($input);
}

// Honeypot: real users never see or fill the "website" field.
if (!empty($input['website'])) {
    api_ok(['received' => true, 'message' => 'Thank you! Our team will get back to you shortly.'], 201);
}

require_recaptcha();
if (!rate_limit('enquiry', client_ip(), 5, 3600)) {
    api_error('Too many enquiries from your network. Please try again later or call us.', 429, 'rate_limited');
}

$data = validate_or_fail($input, [
    'name' => 'required|string|min:2|max:120',
    'email' => 'email',
    'phone' => 'phone',
    'whatsapp' => 'phone',
    'qualification' => 'in:' . implode(',', ENQUIRY_QUALIFICATIONS),
    'subject' => 'string|max:160',
    'message' => 'string|max:3000',
    'course' => 'slug|max:200',
    'course_slug' => 'slug|max:200',
    'source' => 'in:' . implode(',', ENQUIRY_SOURCES),
    'consent' => 'bool',
    'popup_id' => 'int|min:1',
    'page_url' => 'string|max:500',
]);
$fail = fn(array $fields) => api_error('Please correct the highlighted fields.', 422, 'validation_failed', $fields);

$courseSlug = $data['course'] ?: $data['course_slug'];
$course = $courseSlug ? db_one("SELECT id, title FROM courses WHERE slug = ? AND status = 'published'", [$courseSlug]) : null;
$source = $data['source'] ?? ($course ? 'course_page' : 'contact_form');
$consent = $data['consent'] === true;

if (in_array($source, CONSENT_REQUIRED_SOURCES, true) && !$consent) {
    $fail(['consent' => 'Please tick the box to agree to be contacted about your enquiry.']);
}

// Enquiry popups (admin → Popups): count the submission and use its thank-you text.
$popup = null;
if ($data['popup_id']) {
    $popup = db_one('SELECT id, title, success_message FROM popups WHERE id = ? AND is_active = 1', [$data['popup_id']]);
}

// A callback request needs only a name and phone; short popup / course forms get an automatic summary.
if ($source === 'callback' && empty($data['phone'])) {
    $fail(['phone' => 'Phone number is required for a callback.']);
}
if (in_array($source, MESSAGE_OPTIONAL_SOURCES, true)) {
    if (trim((string) $data['message']) === '') {
        $about = $course ? $course['title'] : ($data['subject'] ?? '');
        $data['message'] = match ($source) {
            'callback' => 'Callback requested',
            'popup' => 'Enquiry from website popup' . ($popup ? ' "' . $popup['title'] . '"' : ''),
            'course_page' => 'Course enquiry',
            default => 'Enquiry from the homepage',
        } . ($about !== '' ? ' - interested in ' . $about : '');
    }
} elseif (mb_strlen((string) $data['message']) < 5) {
    $fail(['message' => 'Please write a short message (at least 5 characters).']);
}
// Admin → Enquiry Form Settings
if (setting('enquiry_require_phone', '0') === '1' && empty($data['phone'])) {
    $fail(['phone' => 'Phone number is required.']);
}
if (empty($data['email']) && empty($data['phone'])) {
    $fail(['email' => 'Email or phone is required.', 'phone' => 'Email or phone is required.']);
}

$pageUrl = clean_page_url($data['page_url']);

// WhatsApp number + qualification (admission form, migration 017); older databases simply skip them.
$extra = db_value("SHOW COLUMNS FROM enquiries LIKE 'whatsapp'") ? ['whatsapp' => $data['whatsapp'], 'qualification' => $data['qualification']] : [];
db_query(
    'INSERT INTO enquiries (name, email, phone, subject, message, course_id, source, consent_at, page_url, ip_address, user_agent'
    . ($extra ? ', ' . implode(', ', array_keys($extra)) : '') . ')
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?' . str_repeat(', ?', count($extra)) . ')',
    [
        $data['name'],
        $data['email'],
        $data['phone'],
        $data['subject'],
        $data['message'],
        $course['id'] ?? null,
        $source,
        $consent ? date('Y-m-d H:i:s') : null,
        $pageUrl,
        client_ip(),
        user_agent(),
        ...array_values($extra),
    ]
);
$enquiryId = (int) db()->lastInsertId();
record_attribution('enquiry', $enquiryId, $input['attribution'] ?? null);
if ($popup) {
    db_query('UPDATE popups SET submissions = submissions + 1 WHERE id = ?', [$popup['id']]);
}

require_once APP_ROOT . '/includes/notify.php';
// Ticking the consent box counts as WhatsApp consent for this enquiry's confirmation only (never for updates).
$lead = ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'], 'whatsapp_consent' => $consent && !empty($data['phone'])];
$leadVars = ['course' => $course['title'] ?? '', 'related' => ['enquiry', $enquiryId]];
if (setting('enquiry_autoreply', '1') === '1' && !empty($data['email'])) {
    send_template('enquiry_autoreply', $lead, $leadVars); // email (+ the WhatsApp confirmation when consented)
} elseif ($lead['whatsapp_consent'] && setting('whatsapp_enabled', '0') === '1' && is_file(APP_ROOT . '/includes/whatsapp.php')) {
    // No email address (callback / phone-only forms) or email auto-reply off: WhatsApp confirmation only.
    require_once APP_ROOT . '/includes/whatsapp.php';
    try {
        whatsapp_notify_lead('enquiry_autoreply', $lead, $leadVars);
    } catch (Throwable $e) {
        error_log('[laxyam] enquiry whatsapp confirmation failed: ' . $e->getMessage());
    }
}
// Staff alert (an empty enquiry_notify_email falls back to the contact email in notify_staff_email)
$sourceLabel = ['contact_form' => 'Contact form', 'callback' => 'Callback request', 'popup' => 'Website popup', 'course_page' => 'Course page',
    'newsletter_block' => 'Newsletter block', 'chatbot' => 'Chatbot', 'home_cta' => 'Homepage CTA', 'hero_card' => 'Hero card', 'careers' => 'Careers', 'other' => 'Website'][$source] ?? $source;
notify_staff_email(setting('enquiry_notify_email'), 'New enquiry from ' . $data['name'], implode("\n", array_filter([
    trim(($data['phone'] ?? '') . ' ' . ($data['email'] ?? '')),
    $data['whatsapp'] && $data['whatsapp'] !== $data['phone'] ? 'WhatsApp: ' . $data['whatsapp'] : null,
    $data['qualification'] ? 'Qualification: ' . $data['qualification'] : null,
    'Source: ' . $sourceLabel . ($popup ? ' (' . $popup['title'] . ')' : ''),
    $course ? 'Course: ' . $course['title'] : null,
    $pageUrl ? 'Page: ' . app_url(ltrim($pageUrl, '/')) : null,
    $consent ? 'Consent to be contacted: yes' : null,
    '',
    $data['message'],
], fn($l) => $l !== null)), 'enquiries.php?action=edit&id=' . $enquiryId);

api_ok([
    'received' => true,
    'message' => $popup && $popup['success_message'] ? $popup['success_message'] : 'Thank you! Our team will get back to you shortly.',
], 201);

/** Keep only a path on this website ("/courses/x?ref=…"); absolute links to this site are made relative. */
function clean_page_url(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }
    $base = rtrim(app_url(), '/');
    $basePath = rtrim((string) parse_url($base, PHP_URL_PATH), '/');
    if (preg_match('#^https?://#i', $url)) {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host !== strtolower((string) parse_url($base, PHP_URL_HOST))) {
            return null;
        }
        $url = (string) preg_replace('#^https?://[^/]+#i', '', $url) ?: '/';
    }
    if ($basePath !== '' && ($url === $basePath || str_starts_with($url, $basePath . '/'))) {
        $url = substr($url, strlen($basePath)) ?: '/';
    }
    $url = (string) preg_replace('/#.*$/', '', $url);
    return preg_match('#^/(?!/)[^\s<>"\'\\\\]*$#', $url) ? mb_substr($url, 0, 255) : null;
}

/** POST enquiries.php?action=newsletter */
function newsletter_subscribe(array $input): never
{
    if (!empty($input['website'])) {
        api_ok(['subscribed' => true, 'message' => 'Thank you for subscribing!'], 201);
    }
    if (!rate_limit('newsletter', client_ip(), 10, 3600)) {
        api_error('Too many requests. Please try again later.', 429, 'rate_limited');
    }
    $d = validate_or_fail($input, [
        'email' => 'required|email',
        'name' => 'string|max:120',
        'source' => 'in:' . implode(',', NEWSLETTER_SOURCES),
        'consent' => 'bool',
    ]);
    $source = $d['source'] ?? 'footer';
    $name = $d['name'] !== null && trim($d['name']) !== '' ? trim($d['name']) : null;
    $existing = db_one('SELECT id, name, status, unsubscribe_token FROM newsletter_subscribers WHERE email = ?', [$d['email']]);

    if ($existing && $existing['status'] === 'subscribed') {
        // Already on the list: fill a missing name / token, but never send another welcome email.
        db_query('UPDATE newsletter_subscribers SET name = COALESCE(name, ?), unsubscribe_token = COALESCE(unsubscribe_token, ?) WHERE id = ?',
            [$name, bin2hex(random_bytes(16)), $existing['id']]);
        api_ok(['subscribed' => true, 'already' => true, 'message' => 'You are already subscribed. Watch your inbox for our next update.'], 200);
    }
    if ($existing && $d['consent'] !== true) {
        // People who unsubscribed are never re-added silently - they must confirm again.
        api_error('You unsubscribed from our emails earlier. Please tick the consent box to subscribe again.', 422, 'consent_required', [
            'consent' => 'Please confirm you want to receive our emails again.',
        ]);
    }

    $token = ($existing['unsubscribe_token'] ?? '') ?: bin2hex(random_bytes(16));
    if ($existing) {
        db_query("UPDATE newsletter_subscribers SET status = 'subscribed', name = COALESCE(?, name), source = ?, unsubscribe_token = ?, consent_at = NOW(), unsubscribed_at = NULL, ip_address = ? WHERE id = ?",
            [$name, $source, $token, client_ip(), $existing['id']]);
    } else {
        try {
            // Submitting the sign-up form is the subscriber's consent; its time is recorded.
            db_query('INSERT INTO newsletter_subscribers (email, name, source, unsubscribe_token, consent_at, ip_address) VALUES (?, ?, ?, ?, NOW(), ?)',
                [$d['email'], $name, $source, $token, client_ip()]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            api_ok(['subscribed' => true, 'already' => true, 'message' => 'You are already subscribed. Watch your inbox for our next update.'], 200);
        }
    }

    if (setting('newsletter_welcome_email', '1') === '1') {
        require_once APP_ROOT . '/includes/notify.php';
        send_template('newsletter_welcome', ['name' => $name ?? '', 'email' => $d['email']], [
            'link' => app_url('api/enquiries.php?action=unsubscribe&token=' . $token),
            'button_label' => 'Unsubscribe',
        ]);
    }
    api_ok(['subscribed' => true, 'message' => 'Thank you for subscribing! Please check your inbox for a welcome email.'], 201);
}

/** GET enquiries.php?action=unsubscribe&token=… - one-click unsubscribe from the newsletter email. */
function newsletter_unsubscribe_page(string $token): never
{
    $row = null;
    if (preg_match('/^[a-f0-9]{32}$/', $token) && rate_limit('unsubscribe', client_ip(), 30, 3600)) {
        $row = db_one('SELECT id, email, status FROM newsletter_subscribers WHERE unsubscribe_token = ?', [$token]);
    }
    if ($row && $row['status'] === 'subscribed') {
        db_query("UPDATE newsletter_subscribers SET status = 'unsubscribed', unsubscribed_at = NOW() WHERE id = ?", [$row['id']]);
    }
    $site = setting('site_name', 'Laxyam Academy');
    $masked = $row ? (string) preg_replace_callback('/^(.)(.*)(@.*)$/u', fn($m) => $m[1] . str_repeat('•', max(2, min(6, mb_strlen($m[2])))) . $m[3], $row['email']) : '';
    [$title, $text] = !$row
        ? ['This link is not valid', 'We could not find a subscription for this link. It may have been copied incompletely. If you keep receiving emails you did not ask for, reply to any of them or contact us.']
        : ($row['status'] === 'subscribed'
            ? ['You have been unsubscribed', $masked . ' will no longer receive newsletter emails from ' . $site . '. Account and payment emails (such as receipts) are not affected.']
            : ['You are already unsubscribed', $masked . ' is not on our newsletter list, so you will not receive newsletter emails from ' . $site . '.']);

    http_response_code($row ? 200 : 404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    // The trailing slash matters: a CSP source path without it matches that exact path only.
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src " . rtrim(app_url(), '/') . "/; base-uri 'none'; form-action 'none'");
    $logo = public_file_url(setting('logo_path') ?: 'assets/logo.png');
    $home = app_url('');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e($site) ?></title>
<style>
  :root { --navy: #08264D; --primary: #0B63F6; --gold: #FFC928; --line: #E2EAF4; --muted: #5b6b82; }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; font: 16px/1.6 Inter, "Segoe UI", Roboto, Arial, sans-serif; color: var(--navy);
         background: radial-gradient(1200px 600px at 10% -10%, #dbe8ff 0%, transparent 60%), radial-gradient(900px 500px at 110% 110%, #fff3c4 0%, transparent 55%), #F5F9FF; }
  .card { width: 100%; max-width: 480px; background: #fff; border: 1px solid var(--line); border-radius: 20px; box-shadow: 0 20px 50px rgb(8 38 77 / .10); overflow: hidden; }
  .top { background: linear-gradient(135deg, var(--navy), var(--primary)); padding: 22px 28px; }
  .top img { height: 40px; width: auto; display: block; background: #fff; border-radius: 10px; padding: 6px 10px; }
  .body { padding: 28px; }
  .ico { width: 52px; height: 52px; border-radius: 16px; display: grid; place-items: center; background: <?= $row ? '#e8f7ef' : '#fdecec' ?>; color: <?= $row ? '#15803d' : '#b91c1c' ?>; margin-bottom: 14px; }
  h1 { font: 800 22px/1.25 "Plus Jakarta Sans", Inter, "Segoe UI", Arial, sans-serif; margin: 0 0 8px; letter-spacing: -.01em; }
  p { margin: 0 0 14px; color: var(--muted); }
  .note { background: #F5F9FF; border: 1px dashed #c7d7f2; border-radius: 12px; padding: 12px 14px; font-size: 14px; }
  a.btn { display: inline-flex; align-items: center; gap: 8px; margin-top: 8px; padding: 11px 20px; border-radius: 12px; background: var(--primary); color: #fff; font-weight: 700; text-decoration: none; }
  a.btn:hover { background: #0950c7; }
  a.btn:focus-visible { outline: 3px solid var(--gold); outline-offset: 2px; }
</style>
</head>
<body>
<main class="card">
  <div class="top"><img src="<?= e($logo) ?>" alt="<?= e($site) ?>"></div>
  <div class="body">
    <div class="ico" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><?= $row ? '<path d="M20 6 9 17l-5-5"/>' : '<path d="M18 6 6 18M6 6l12 12"/>' ?></svg></div>
    <h1><?= e($title) ?></h1>
    <p><?= e($text) ?></p>
    <?php if ($row): ?>
    <p class="note">Unsubscribed by mistake? You can subscribe again at any time with the newsletter form on our website - just tick the consent box.</p>
    <?php endif; ?>
    <a class="btn" href="<?= e($home) ?>">Go to <?= e($site) ?> &rarr;</a>
  </div>
</main>
</body>
</html>
<?php
    exit;
}
