<?php
/**
 * WhatsApp automation: Interakt WhatsApp Business API (default) or the Meta Cloud API (older setups).
 *
 * Every message follows one path:
 *   verified backend event (payment verified, enrolment saved, result released …)
 *     → whatsapp_notify_event() (called by send_template) / whatsapp_staff_alert() / whatsapp_broadcast()
 *     → whatsapp_dispatch(): event template (whatsapp_templates: enabled + approved template name)
 *        → phone normalised to E.164 → student opt-in / lead consent / preference checks
 *        → whatsapp_messages row (idempotent per event + related record [+ occurrence] + phone)
 *        → sent after the visitor's response has been handed off (web requests; whatsapp_send_deferred), or
 *          straight away from the CLI → 'sent' (provider message id) or 'failed' + retry back-off 2m, 10m, 1h, 6h
 *   Every send and retry re-checks the template switch, opt-in and preferences first (whatsapp_send_blocker).
 *   A provider that does not answer opens a 5-minute circuit breaker: direct sends pause and the scheduler
 *   takes over. process_whatsapp_queue() (scheduler) retries due rows; api/whatsapp-webhook.php moves rows to
 *   delivered / read / failed from Interakt's HMAC-signed status webhooks.
 *
 * Credentials come only from the server's .env, never from the database or the browser:
 *   INTERAKT_API_KEY         Interakt → Settings → Developer Settings → Secret Key. Interakt documents it as the
 *                            already base64-encoded "username:password", so it is sent verbatim as "Authorization: Basic <key>".
 *   INTERAKT_WEBHOOK_SECRET  the Secret Key typed into Interakt's webhook settings (verifies the Interakt-Signature header)
 *   INTERAKT_API_BASE        optional, default https://api.interakt.ai (only for local testing)
 *   WHATSAPP_TOKEN / WHATSAPP_PHONE_ID   Meta Cloud API (provider "meta")
 * Admin → WhatsApp (admin/whatsapp.php) manages templates, previews, logs, opt-ins, broadcasts and settings.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once APP_ROOT . '/includes/notify.php'; // student_pref_enabled(), TEMPLATE_PLACEHOLDERS, mailer

/** Minutes to wait before attempt 2, 3, 4, 5 … (the last value repeats). */
const WHATSAPP_BACKOFF_MINUTES = [2, 10, 60, 360];
/** Seconds to wait for the provider when nobody is waiting (response already handed off, scheduler, admin). */
const WHATSAPP_HTTP_TIMEOUT = 8;
/** Seconds per call, and the total seconds of sending, while a visitor still waits for the page (no early hand-off). */
const WHATSAPP_INLINE_TIMEOUT = 3;
const WHATSAPP_INLINE_BUDGET = 4;
/** At most this many messages are sent at the end of one request; the scheduler sends the rest. */
const WHATSAPP_DEFERRED_MAX = 25;
/** After a timeout / connection failure, direct sends pause this long (circuit breaker). */
const WHATSAPP_CIRCUIT_SECONDS = 300;
/** A timed-out send may still have been accepted: wait this long for its status webhook before retrying. */
const WHATSAPP_TIMEOUT_RETRY_MINUTES = 15;
/** Placeholders carrying one-time secrets: never stored in the log and never retried. */
const WHATSAPP_SENSITIVE = ['reset_url', 'reset_token', 'reset_path'];
const WHATSAPP_STATUS_RANK = ['queued' => 0, 'sending' => 0, 'failed' => 0, 'skipped' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

const WHATSAPP_GROUPS = [
    'account' => ['Account & security', 'shield'],
    'payments' => ['Payments & invoices', 'rupee'],
    'learning' => ['Courses, tests & classes', 'book'],
    'broadcast' => ['Course updates & announcements', 'megaphone'],
    'support' => ['Enquiries & support', 'headset'],
    'careers' => ['Job applications', 'briefcase'],
    'staff' => ['Admin alerts', 'bell'],
];

/** Placeholders a template may use: key => [label, sample value ('' = filled at runtime)]. *_path variants are added automatically. */
const WHATSAPP_PLACEHOLDERS = [
    'student_name' => ['Student / recipient name', 'Aarav Sharma'],
    'first_name' => ['First name', 'Aarav'],
    'site_name' => ['Academy name', ''],
    'site_url' => ['Website address', ''],
    'support_number' => ['Support phone number', ''],
    'course_name' => ['Course name', 'SSC CGL 2026 Complete Course'],
    'course_url' => ['Course link', 'learn/ssc-cgl-2026'],
    'order_id' => ['Order ID', 'LXA2610310012'],
    'amount' => ['Amount paid / refunded', '₹4,999'],
    'invoice_number' => ['Invoice number', 'LXA/26-27/0012'],
    'invoice_url' => ['Invoice PDF link (signed)', 'api/orders.php?action=invoice&order=LXA2610310012&sig=3f9c'],
    'retry_url' => ['Payment retry link', 'checkout/ssc-cgl-2026'],
    'test_name' => ['Quiz / mock test name', 'SSC CGL Tier 1 Full Mock 3'],
    'score' => ['Score', '142.5 / 200'],
    'result' => ['Result', 'Passed · 71.3%'],
    'result_url' => ['Result link', 'mock-tests/ssc-cgl-mock-3/result/1042'],
    'certificate_no' => ['Certificate number', 'LXA-2026-8F3A21C0'],
    'certificate_url' => ['Certificates link', 'dashboard/certificates'],
    'class_title' => ['Live class title', 'Quant Marathon: Algebra'],
    'class_time' => ['Class date & time', '1 Nov 2026, 6:00 PM'],
    'join_url' => ['Live classes link', 'dashboard/live-classes'],
    'update_text' => ['Update / notice text', 'The doubt-clearing session moves to Sunday, 10:00 AM.'],
    'application_role' => ['Job role', 'Faculty - Quantitative Aptitude'],
    'interview_date' => ['Interview date & time', 'Monday, 2 November 2026, 11:00 AM'],
    'interview_mode' => ['Interview mode', 'Video call'],
    'interview_location' => ['Venue / meeting link', 'https://meet.google.com/abc-defg-hij'],
    'interview_note' => ['Note to the candidate', 'Please keep a sample lesson ready.'],
    'reset_url' => ['Password reset link (one-time, never logged)', 'reset-password?token=8c1f'],
    'reset_token' => ['Reset token for a URL button (one-time, never logged)', '8c1f2a7d'],
    'changed_at' => ['Date & time of the change', '31 Oct 2026, 4:15 PM (IST)'],
    'security_url' => ['Forgot-password link', 'forgot-password'],
    'purchases_url' => ['My purchases link', 'dashboard/purchases'],
    'ticket_no' => ['Ticket number', '1287'],
    'ticket_subject' => ['Ticket subject', 'Unable to open lesson 4'],
    'ticket_url' => ['Ticket link', 'dashboard/support?ticket=1287'],
    'lead_name' => ['Lead / candidate name', 'Riya Verma'],
    'lead_phone' => ['Lead / candidate phone', '+91 98765 43210'],
    'lead_email' => ['Lead email', 'riya@example.com'],
    'lead_source' => ['Enquiry source', 'Course page'],
    'admin_url' => ['Admin panel link', 'admin/enquiries.php?action=edit&id=57'],
];

const WHATSAPP_COMMON_VARS = ['student_name', 'first_name', 'site_name', 'site_url', 'support_number'];

/**
 * Every WhatsApp event. 'vars' = the placeholders the event provides (plus WHATSAPP_COMMON_VARS and *_path
 * variants), 'preview' = a professional default text in {{placeholder}} form (its order seeds body_params),
 * 'pref' = the student preference that can switch it off (non-transactional only), 'kind' broadcast = sent
 * by staff from the Broadcasts tab, 'always_opt_in' = opt-in can never be switched off for it.
 */
const WHATSAPP_EVENTS = [
    'welcome' => [
        'label' => 'Welcome (registration)', 'group' => 'account', 'recipient' => 'student', 'category' => 'utility',
        'trigger' => 'A student creates an account (sent after the account is saved, only if they ticked WhatsApp updates).',
        'vars' => [],
        'preview' => "Hi {{student_name}}, welcome to {{site_name}}! Your student account is ready.\n\nExplore courses, mock tests and free study resources anytime at {{site_url}}.\n\nNeed help? Call or WhatsApp us on {{support_number}}. Reply STOP to opt out of WhatsApp updates.",
    ],
    'password_reset' => [
        'label' => 'Password reset link', 'group' => 'account', 'recipient' => 'student', 'category' => 'utility', 'always_opt_in' => true,
        'trigger' => 'A student asks for a password reset. Sent only to the opted-in mobile number of that account; the one-time link goes in a URL button and is never logged.',
        'vars' => ['reset_url', 'reset_token'], 'button' => 'reset_token',
        'preview' => "Hi {{student_name}}, we received a request to reset the password of your {{site_name}} account.\n\nTap the button below to choose a new password. The link expires in 60 minutes and can be used once.\n\nIf you did not request this, please ignore this message. Your password will not change.",
    ],
    'password_changed' => [
        'label' => 'Password changed (security alert)', 'group' => 'account', 'recipient' => 'student', 'category' => 'utility',
        'trigger' => 'The account password is changed in the dashboard or reset with a link.',
        'vars' => ['changed_at', 'security_url'],
        'preview' => "Hi {{student_name}}, the password of your {{site_name}} account was changed on {{changed_at}}.\n\nIf you did not make this change, reset your password now at {{security_url}} or call us on {{support_number}}.",
    ],
    'payment_success' => [
        'label' => 'Payment successful', 'group' => 'payments', 'recipient' => 'student', 'category' => 'utility',
        'trigger' => 'Only after the payment is verified on the server (gateway signature or webhook) and the order is marked paid.',
        'vars' => ['course_name', 'amount', 'order_id', 'course_url', 'invoice_url', 'invoice_number'],
        'preview' => "Hi {{student_name}}, we have received your payment of {{amount}} for {{course_name}} (Order ID: {{order_id}}).\n\nYour course is unlocked: {{course_url}}\nDownload your GST invoice: {{invoice_url}}\n\nFor any help, contact us on {{support_number}}.",
    ],
    'payment_failed' => [
        'label' => 'Payment failed', 'group' => 'payments', 'recipient' => 'student', 'category' => 'utility',
        'trigger' => 'An order is still unpaid 15 minutes after a failed payment attempt. Sent once, never for a first failure while the student is still retrying.',
        'vars' => ['course_name', 'order_id', 'retry_url'],
        'preview' => "Hi {{student_name}}, your payment for {{course_name}} (Order ID: {{order_id}}) could not be completed.\n\nIf money was debited from your account, your bank normally reverses it within 5-7 working days. You can complete your enrolment here: {{retry_url}}\n\nNeed help? Call us on {{support_number}}.",
    ],
    'invoice_issued' => [
        'label' => 'Invoice generated (optional)', 'group' => 'payments', 'recipient' => 'student', 'category' => 'utility',
        'trigger' => 'Right after a verified payment, once the GST invoice is issued. Optional: “Payment successful” already carries the invoice link. Can attach the PDF as a document header (invoice_url).',
        'vars' => ['invoice_number', 'invoice_url', 'order_id', 'amount', 'course_name', 'course_url'],
        'preview' => "Hi {{student_name}}, your invoice {{invoice_number}} for Order ID {{order_id}} ({{amount}}) has been generated.\n\nDownload it here: {{invoice_url}}\n\nThank you for learning with {{site_name}}.",
    ],
    'refund_processed' => [
        'label' => 'Refund processed', 'group' => 'payments', 'recipient' => 'student', 'category' => 'utility',
        'trigger' => 'Staff record a refund on an order (Payments → Orders).',
        'vars' => ['amount', 'order_id', 'purchases_url'],
        'preview' => "Hi {{student_name}}, your refund of {{amount}} for Order ID {{order_id}} has been processed.\n\nIt usually reaches your original payment method within 5-7 working days. Your purchases: {{purchases_url}}\n\nThank you for your patience.",
    ],
    'enrolment' => [
        'label' => 'Enrolment confirmed / course access', 'group' => 'learning', 'recipient' => 'student', 'category' => 'utility',
        'trigger' => 'A free enrolment succeeds, a 100% coupon order completes, or staff grant course access.',
        'vars' => ['course_name', 'course_url', 'order_id'],
        'preview' => "Hi {{student_name}}, your enrolment in {{course_name}} is confirmed and the course is now unlocked in your dashboard.\n\nStart learning: {{course_url}}\n\nFor any help, contact us on {{support_number}}.",
    ],
    'test_result' => [
        'label' => 'Quiz / mock test result', 'group' => 'learning', 'recipient' => 'student', 'category' => 'utility', 'pref' => 'test_results',
        'trigger' => 'A mock test result is released (immediately, after the test window ends, or when staff release it).',
        'vars' => ['test_name', 'course_name', 'score', 'result', 'result_url'],
        'preview' => "Hi {{student_name}}, your result for {{test_name}} is out.\n\nScore: {{score}}\nResult: {{result}}\n\nSee your detailed analysis: {{result_url}}\n\nKeep practising. All the best!",
    ],
    'course_completed' => [
        'label' => 'Course completed', 'group' => 'learning', 'recipient' => 'student', 'category' => 'utility', 'pref' => 'course_updates',
        'trigger' => 'A student first reaches 100% progress in a course.',
        'vars' => ['course_name', 'certificate_url'],
        'preview' => "Congratulations {{student_name}}! You have completed {{course_name}}.\n\nYour certificates will be available here: {{certificate_url}}\n\nThank you for learning with {{site_name}}.",
    ],
    'certificate_issued' => [
        'label' => 'Certificate issued', 'group' => 'learning', 'recipient' => 'student', 'category' => 'utility', 'pref' => 'course_updates',
        'trigger' => 'Staff issue a course completion certificate (Students → Certificates).',
        'vars' => ['course_name', 'certificate_no', 'certificate_url'],
        'preview' => "Hi {{student_name}}, your certificate for {{course_name}} has been issued (Certificate No. {{certificate_no}}).\n\nView and download it: {{certificate_url}}\n\nCongratulations from the {{site_name}} team!",
    ],
    'class_reminder' => [
        'label' => 'Live class reminder', 'group' => 'learning', 'recipient' => 'student', 'category' => 'utility', 'pref' => 'class_reminders',
        'trigger' => 'A live class the student set a reminder for starts within the reminder lead time (Live Class Settings).',
        'vars' => ['class_title', 'class_time', 'join_url'],
        'preview' => "Hi {{student_name}}, reminder: your live class {{class_title}} starts on {{class_time}} (IST).\n\nJoin from your dashboard: {{join_url}}\n\nReply STOP to opt out of WhatsApp reminders.",
    ],
    'course_update' => [
        'label' => 'Important course update', 'group' => 'broadcast', 'recipient' => 'student', 'category' => 'utility', 'pref' => 'course_updates', 'kind' => 'broadcast',
        'trigger' => 'Staff send a course update from the Broadcasts tab to the opted-in students of one course batch.',
        'vars' => ['course_name', 'update_text', 'course_url'],
        'preview' => "Hi {{student_name}}, here is an important update for {{course_name}}:\n\n{{update_text}}\n\nOpen your course: {{course_url}}\n\nReply STOP to opt out of WhatsApp updates.",
    ],
    'announcement' => [
        'label' => 'Announcement / notice', 'group' => 'broadcast', 'recipient' => 'student', 'category' => 'utility', 'pref' => 'announcements', 'kind' => 'broadcast',
        'trigger' => 'Staff send a notice from the Broadcasts tab to all opted-in students or one course batch. Promotional notices need a template approved as Marketing.',
        'vars' => ['update_text', 'course_name', 'course_url'],
        'preview' => "Hi {{student_name}}, an important notice from {{site_name}}:\n\n{{update_text}}\n\nFor questions, contact us on {{support_number}}. Reply STOP to opt out of WhatsApp updates.",
    ],
    'enquiry_autoreply' => [
        'label' => 'Enquiry confirmation', 'group' => 'support', 'recipient' => 'lead', 'category' => 'utility',
        'trigger' => 'A visitor submits an enquiry or callback form with a mobile number and ticks the consent box (consent covers this confirmation only).',
        'vars' => ['course_name'],
        'preview' => "Hi {{student_name}}, thank you for contacting {{site_name}}. We have received your enquiry and a counsellor will get in touch with you shortly.\n\nFor urgent help, call us on {{support_number}}.",
    ],
    'ticket_reply' => [
        'label' => 'Support ticket reply', 'group' => 'support', 'recipient' => 'student', 'category' => 'utility',
        'trigger' => 'Staff reply to a student’s support ticket (internal notes are never sent).',
        'vars' => ['ticket_no', 'ticket_url'],
        'preview' => "Hi {{student_name}}, our support team has replied to your ticket #{{ticket_no}}.\n\nRead the reply: {{ticket_url}}\n\nThank you for contacting {{site_name}}.",
    ],
    'application_ack' => [
        'label' => 'Job application received', 'group' => 'careers', 'recipient' => 'lead', 'category' => 'utility',
        'trigger' => 'A candidate submits a job application on the Careers page and ticks the optional WhatsApp updates box (all careers messages go only to candidates who ticked it).',
        'vars' => ['application_role'],
        'preview' => "Hi {{student_name}}, thank you for applying for {{application_role}} at {{site_name}}.\n\nWe have received your application. Our recruitment team will review it and contact you if your profile is shortlisted.",
    ],
    'application_shortlisted' => [
        'label' => 'Application shortlisted', 'group' => 'careers', 'recipient' => 'lead', 'category' => 'utility',
        'trigger' => 'Staff set an application to Shortlisted with “notify the candidate” ticked (only if the candidate ticked WhatsApp updates when applying).',
        'vars' => ['application_role'],
        'preview' => "Hi {{student_name}}, good news! Your application for {{application_role}} at {{site_name}} has been shortlisted.\n\nOur recruitment team will contact you soon with the next steps.",
    ],
    'interview_scheduled' => [
        'label' => 'Interview scheduled', 'group' => 'careers', 'recipient' => 'lead', 'category' => 'utility',
        'trigger' => 'Staff schedule an interview with “notify the candidate” ticked (only if the candidate ticked WhatsApp updates when applying).',
        'vars' => ['application_role', 'interview_date', 'interview_mode', 'interview_location', 'interview_note'],
        'preview' => "Hi {{student_name}}, your interview for {{application_role}} at {{site_name}} is scheduled.\n\nDate & time: {{interview_date}}\nMode: {{interview_mode}}\nVenue / link: {{interview_location}}\n\nPlease be on time. Contact us on {{support_number}} if you need to reschedule.",
    ],
    'application_hired' => [
        'label' => 'Candidate selected', 'group' => 'careers', 'recipient' => 'lead', 'category' => 'utility',
        'trigger' => 'Staff set an application to Hired with “notify the candidate” ticked (only if the candidate ticked WhatsApp updates when applying).',
        'vars' => ['application_role'],
        'preview' => "Hi {{student_name}}, congratulations! You have been selected for {{application_role}} at {{site_name}}.\n\nOur team will share the joining details with you shortly.",
    ],
    'application_rejected' => [
        'label' => 'Application not selected', 'group' => 'careers', 'recipient' => 'lead', 'category' => 'utility',
        'trigger' => 'Staff set an application to Rejected with “notify the candidate” ticked (only if the candidate ticked WhatsApp updates when applying).',
        'vars' => ['application_role'],
        'preview' => "Hi {{student_name}}, thank you for your interest in {{application_role}} at {{site_name}}.\n\nAfter careful review, we will not be moving forward with your application at this time. We wish you all the best.",
    ],
    'admin_new_enquiry' => [
        'label' => 'Admin alert: new enquiry', 'group' => 'staff', 'recipient' => 'staff', 'category' => 'utility',
        'trigger' => 'A new enquiry, callback request or chatbot lead is saved. Goes to the admin alert numbers (Settings tab).',
        'vars' => ['lead_name', 'lead_phone', 'lead_email', 'lead_source', 'course_name', 'admin_url'],
        'preview' => "New enquiry on {{site_name}}.\n\nName: {{lead_name}}\nPhone: {{lead_phone}}\nSource: {{lead_source}}\nInterested in: {{course_name}}\n\nOpen in admin: {{admin_url}}\n\nThis is an automatic staff alert.",
    ],
    'admin_new_order' => [
        'label' => 'Admin alert: new paid order', 'group' => 'staff', 'recipient' => 'staff', 'category' => 'utility',
        'trigger' => 'A payment is verified and the order is marked paid (not for 100% coupon orders). Goes to the admin alert numbers.',
        'vars' => ['course_name', 'amount', 'order_id', 'admin_url'],
        'preview' => "New payment received on {{site_name}}.\n\nStudent: {{student_name}}\nCourse: {{course_name}}\nAmount: {{amount}}\nOrder ID: {{order_id}}\n\nOpen in admin: {{admin_url}}\n\nThis is an automatic staff alert.",
    ],
    'admin_new_application' => [
        'label' => 'Admin alert: new job application', 'group' => 'staff', 'recipient' => 'staff', 'category' => 'utility',
        'trigger' => 'A candidate submits a job application. Goes to the admin alert numbers.',
        'vars' => ['lead_name', 'lead_phone', 'application_role', 'admin_url'],
        'preview' => "New job application on {{site_name}}.\n\nCandidate: {{lead_name}}\nPhone: {{lead_phone}}\nRole: {{application_role}}\n\nOpen in admin: {{admin_url}}\n\nThis is an automatic staff alert.",
    ],
    'admin_new_ticket' => [
        'label' => 'Admin alert: new support ticket', 'group' => 'staff', 'recipient' => 'staff', 'category' => 'utility',
        'trigger' => 'A student opens a support ticket. Goes to the admin alert numbers.',
        'vars' => ['ticket_no', 'ticket_subject', 'admin_url'],
        'preview' => "New support ticket #{{ticket_no}} on {{site_name}}.\n\nStudent: {{student_name}}\nSubject: {{ticket_subject}}\n\nOpen in admin: {{admin_url}}\n\nThis is an automatic staff alert.",
    ],
];

// ------------------------------------------------------------------
// Settings, status and schema
// ------------------------------------------------------------------

function whatsapp_provider(): string
{
    return setting('whatsapp_provider', 'interakt') === 'meta' ? 'meta' : 'interakt';
}

function whatsapp_enabled(): bool
{
    return setting('whatsapp_enabled', '0') === '1';
}

/** Are the provider credentials present in .env? (values are never shown anywhere) */
function whatsapp_credentials_ok(?string $provider = null): bool
{
    return ($provider ?? whatsapp_provider()) === 'meta'
        ? (string) config('WHATSAPP_TOKEN', '') !== '' && (string) config('WHATSAPP_PHONE_ID', '') !== ''
        : (string) config('INTERAKT_API_KEY', '') !== '';
}

function whatsapp_api_base(): string
{
    $base = rtrim((string) config('INTERAKT_API_BASE', ''), '/');
    return preg_match('#^https?://[^\s/]+(/[^\s]*)?$#i', $base) ? $base : 'https://api.interakt.ai';
}

function whatsapp_max_attempts(): int
{
    return setting_int('whatsapp_max_attempts', 5, 1, 10);
}

function whatsapp_default_country_code(): string
{
    $cc = (string) setting('whatsapp_default_country_code', '+91');
    return preg_match('/^\+[1-9]\d{0,2}$/', $cc) ? $cc : '+91';
}

/** {{support_number}}: the WhatsApp support number setting, else the public contact phone. */
function whatsapp_support_number(): string
{
    foreach ([setting('whatsapp_support_number'), setting('contact_phone'), setting('whatsapp_number')] as $n) {
        if ($n !== null && trim($n) !== '') {
            $p = whatsapp_normalize_phone($n);
            return $p ? $p['display'] : trim($n);
        }
    }
    return '';
}

/** Normalised admin alert numbers (setting whatsapp_admin_numbers, comma separated). */
function whatsapp_admin_numbers(): array
{
    $out = [];
    foreach (preg_split('/[,;\n]+/', (string) setting('whatsapp_admin_numbers', '')) as $raw) {
        if (($p = whatsapp_normalize_phone($raw)) !== null) {
            $out[$p['e164']] = $p['e164'];
        }
    }
    return array_values($out);
}

/** Connection summary for the admin (booleans only - never the secrets themselves). */
function whatsapp_status(): array
{
    $provider = whatsapp_provider();
    return [
        'provider' => $provider,
        'enabled' => whatsapp_enabled(),
        'credentials' => whatsapp_credentials_ok($provider),
        'webhook_secret' => (string) config('INTERAKT_WEBHOOK_SECRET', '') !== '',
        'custom_base' => (string) config('INTERAKT_API_BASE', '') !== '',
        'schema' => whatsapp_schema_ready(),
        'ready' => whatsapp_enabled() && whatsapp_credentials_ok($provider) && whatsapp_schema_ready(),
        'webhook_url' => app_url('api/whatsapp-webhook.php'),
        'background' => whatsapp_background_mode(),
        'paused_until' => whatsapp_circuit_until(),
    ];
}

/**
 * How web requests send: 'finish_request' (PHP-FPM / LiteSpeed hand the response to the visitor first),
 * 'apache' (mod_php: a complete Content-Length response is sent first) or 'inline' (the visitor waits,
 * within WHATSAPP_INLINE_BUDGET seconds; the rest goes to the scheduler).
 */
function whatsapp_background_mode(): string
{
    if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
        return 'finish_request';
    }
    return function_exists('apache_setenv') ? 'apache' : 'inline';
}

// ------------------------------------------------------------------
// Circuit breaker: an unreachable provider must not slow down every request
// ------------------------------------------------------------------

function whatsapp_circuit_path(): string
{
    return cache_dir() . '/whatsapp-circuit.txt';
}

/** Unix time until which direct sends pause after the provider did not answer, or 0 when sending normally. */
function whatsapp_circuit_until(): int
{
    $t = is_file(whatsapp_circuit_path()) ? (int) @file_get_contents(whatsapp_circuit_path()) : 0;
    return $t > time() ? $t : 0;
}

/** The provider timed out or could not be reached: pause direct sends for WHATSAPP_CIRCUIT_SECONDS. */
function whatsapp_circuit_trip(): void
{
    if (!is_dir(cache_dir())) {
        @mkdir(cache_dir(), 0750, true);
    }
    @file_put_contents(whatsapp_circuit_path(), (string) (time() + WHATSAPP_CIRCUIT_SECONDS), LOCK_EX);
}

/** The provider answered: close the breaker again. */
function whatsapp_circuit_reset(): void
{
    if (is_file(whatsapp_circuit_path())) {
        @unlink(whatsapp_circuit_path());
    }
}

/** True once the WhatsApp tables exist (the database update has been applied). */
function whatsapp_schema_ready(): bool
{
    static $ok = null;
    return $ok ??= (int) db_value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('whatsapp_templates', 'whatsapp_messages')") === 2;
}

/** True once student_profiles has the WhatsApp opt-in columns. */
function whatsapp_optin_ready(): bool
{
    static $ok = null;
    return $ok ??= (int) db_value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_profiles' AND COLUMN_NAME = 'whatsapp_opt_in'") === 1;
}

// ------------------------------------------------------------------
// Phone numbers
// ------------------------------------------------------------------

/**
 * Normalise a phone number to E.164 and split it the way Interakt wants it.
 * 10-digit numbers (and 0-prefixed / 91-prefixed Indian numbers) get the default country code;
 * "+<cc>…" / "00<cc>…" numbers keep theirs. Returns ['cc' => '+91', 'number' => '9876543210',
 * 'e164' => '+919876543210', 'display' => '+91 98765 43210'] or null when invalid.
 */
function whatsapp_normalize_phone(?string $raw, ?string $defaultCc = null): ?array
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $defaultCc ??= whatsapp_default_country_code();
    $digits = preg_replace('/\D/', '', $raw);
    $international = str_starts_with($raw, '+') || str_starts_with($digits, '00');
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    $ccDigits = substr($defaultCc, 1);
    if (!$international) {
        $digits = ltrim($digits, '0');
        if ($ccDigits === '91') {
            if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
                $digits = substr($digits, 2);
            }
            if (strlen($digits) !== 10) {
                return null;
            }
        } elseif (str_starts_with($digits, $ccDigits) && strlen($digits) > strlen($ccDigits) + 6) {
            $digits = substr($digits, strlen($ccDigits));
        }
        $digits = $ccDigits . $digits;
    }
    if (!preg_match('/^[1-9]\d{7,14}$/', $digits)) {
        return null;
    }
    // ITU country calling codes are prefix-free: 1 and 7 are one digit, a fixed set has two, the rest three.
    static $two = ['20', '27', '30', '31', '32', '33', '34', '36', '39', '40', '41', '43', '44', '45', '46', '47', '48', '49', '51', '52', '53', '54', '55', '56', '57', '58',
        '60', '61', '62', '63', '64', '65', '66', '81', '82', '84', '86', '90', '91', '92', '93', '94', '95', '98'];
    $len = in_array($digits[0], ['1', '7'], true) ? 1 : (in_array(substr($digits, 0, 2), $two, true) ? 2 : 3);
    $cc = substr($digits, 0, $len);
    $number = substr($digits, $len);
    if (strlen($number) < 6 || ($cc === '91' && !preg_match('/^[6-9]\d{9}$/', $number))) {
        return null;
    }
    $display = '+' . $cc . ' ' . ($cc === '91' ? substr($number, 0, 5) . ' ' . substr($number, 5) : $number);
    return ['cc' => '+' . $cc, 'number' => $number, 'e164' => '+' . $digits, 'display' => $display];
}

/** Mask a number for lists shown to staff without users.view: +91 98•••••210. */
function whatsapp_mask_phone(?string $e164): string
{
    $d = (string) $e164;
    return strlen($d) > 7 ? substr($d, 0, 5) . str_repeat('•', max(0, strlen($d) - 8)) . substr($d, -3) : $d;
}

// ------------------------------------------------------------------
// Placeholders and templates
// ------------------------------------------------------------------

/** All placeholders with labels and sample values: key => ['label', 'sample']. Includes *_path variants. */
function whatsapp_placeholders(): array
{
    static $all = null;
    if ($all !== null) {
        return $all;
    }
    $runtime = [
        'site_name' => (string) setting('site_name', 'Laxyam Academy'),
        'site_url' => rtrim(app_url(''), '/'),
        'support_number' => whatsapp_support_number() ?: '+91 87005 11018',
    ];
    $all = [];
    foreach (WHATSAPP_PLACEHOLDERS as $k => [$label, $sample]) {
        $value = $runtime[$k] ?? $sample;
        if (str_ends_with($k, '_url') && $k !== 'site_url' && !preg_match('#^https?://#', $value)) {
            $value = app_url($value);
        }
        $all[$k] = ['label' => $label, 'sample' => $value];
        if (str_ends_with($k, '_url') && $k !== 'site_url') {
            $all[substr($k, 0, -4) . '_path'] = ['label' => $label . ' - part after your domain (for URL buttons)', 'sample' => whatsapp_url_path($value)];
        }
    }
    return $all;
}

/** Placeholders an event can fill (common + its own + *_path of its links). */
function whatsapp_event_placeholders(string $event): array
{
    $keys = array_merge(WHATSAPP_COMMON_VARS, WHATSAPP_EVENTS[$event]['vars'] ?? []);
    foreach ($keys as $k) {
        if (str_ends_with($k, '_url') && $k !== 'site_url') {
            $keys[] = substr($k, 0, -4) . '_path';
        }
    }
    return array_values(array_unique($keys));
}

/** Sample values for the admin preview and test sends. */
function whatsapp_sample_vars(): array
{
    return array_map(fn($p) => $p['sample'], whatsapp_placeholders());
}

/** {{placeholder}} names in a text, in order of first appearance. */
function whatsapp_text_placeholders(string $text): array
{
    preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $m);
    return array_values(array_unique($m[1]));
}

/** Absolute URL for a site path ("/learn/x") or an absolute http(s) URL; '' otherwise. */
function whatsapp_abs_url(string $link): string
{
    $link = trim($link);
    if ($link === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $link)) {
        return $link;
    }
    return str_starts_with($link, '/') && !str_starts_with($link, '//') ? app_url(ltrim($link, '/')) : '';
}

/** "https://site/learn/x?y=1" → "learn/x?y=1" (dynamic URL button suffix); '' for other sites. */
function whatsapp_url_path(string $url): string
{
    $base = rtrim(app_url(''), '/') . '/';
    return $url !== '' && str_starts_with($url, $base) ? substr($url, strlen($base)) : '';
}

/** WhatsApp parameter rules: no new lines / tabs, no more than 4 consecutive spaces, not empty. */
function whatsapp_clean_param(mixed $value, int $max = 500): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $v = preg_replace('/[\r\n\t]+/u', ' ', (string) $value);
    $v = trim((string) preg_replace('/ {2,}/', ' ', (string) $v));
    return mb_substr($v, 0, $max);
}

/**
 * Event mapping: build the WhatsApp variable set from the vars an existing send_template() caller passes
 * (name, course, amount, order, link, when, date, mode, location, message, score, result, related, links …),
 * so no caller has to change. Explicit WhatsApp keys (invoice_url, order_id …) win. The raw legacy keys stay
 * available for older Meta mappings ("template|name,course").
 */
function whatsapp_event_vars(string $event, array $vars, string $name = ''): array
{
    $s = static fn(string $k): string => is_scalar($vars[$k] ?? null) ? trim((string) $vars[$k]) : '';
    $name = trim($name !== '' ? $name : $s('name'));
    $link = whatsapp_abs_url($s('link'));
    $course = $s('course');
    $related = whatsapp_related($vars['related'] ?? null);
    $out = [
        'student_name' => $name,
        'first_name' => $name !== '' ? (string) preg_split('/\s+/', $name)[0] : '',
        'site_name' => (string) setting('site_name', 'Laxyam Academy'),
        'site_url' => rtrim(app_url(''), '/'),
        'support_number' => whatsapp_support_number(),
    ];
    $invoiceLink = '';
    foreach ((array) ($vars['links'] ?? []) as $l) {
        if (is_array($l) && isset($l[0], $l[1]) && is_string($l[1]) && stripos((string) $l[0], 'invoice') !== false) {
            $invoiceLink = $l[1];
        }
    }
    switch ($event) {
        case 'enrolment':
            $out += ['course_name' => $course, 'course_url' => $link, 'order_id' => $s('order')];
            break;
        case 'payment_success':
        case 'invoice_issued':
            $out += ['course_name' => $course, 'amount' => $s('amount'), 'order_id' => $s('order'), 'course_url' => $link, 'invoice_url' => $invoiceLink];
            if ($s('invoice_number') === '' && $related && $related[0] === 'order') {
                $out['invoice_number'] = (string) (db_value('SELECT invoice_number FROM invoices WHERE order_id = ?', [$related[1]]) ?? '');
            }
            break;
        case 'payment_failed':
            $out += ['course_name' => $course, 'order_id' => $s('order'), 'retry_url' => $link];
            break;
        case 'refund_processed':
            $out += ['amount' => $s('amount'), 'order_id' => $s('order'), 'purchases_url' => $link];
            break;
        case 'test_result':
            $out += ['test_name' => $course, 'course_name' => $course, 'score' => $s('score'), 'result' => $s('result'), 'result_url' => $link];
            break;
        case 'course_completed':
            $out += ['course_name' => $course, 'certificate_url' => $link];
            break;
        case 'certificate_issued':
            $out += ['course_name' => $course, 'certificate_url' => $link];
            if ($related && $related[0] === 'certificate') {
                $out['certificate_no'] = (string) (db_value('SELECT certificate_no FROM certificates WHERE id = ?', [$related[1]]) ?? '');
            }
            break;
        case 'class_reminder':
            $out += ['class_title' => $course, 'course_name' => $course, 'class_time' => $s('when'), 'join_url' => $link];
            break;
        case 'course_update':
        case 'announcement':
            $out += ['course_name' => $course, 'course_url' => $link, 'update_text' => $s('message')];
            break;
        case 'enquiry_autoreply':
            $out += ['course_name' => $course];
            break;
        case 'ticket_reply':
            $out += ['ticket_no' => $s('order'), 'ticket_url' => $link];
            break;
        case 'password_changed':
            $out += ['changed_at' => $s('when'), 'security_url' => $link];
            break;
        case 'application_ack':
        case 'application_shortlisted':
        case 'application_rejected':
        case 'application_hired':
            $out += ['application_role' => $course];
            break;
        case 'interview_scheduled':
            $out += ['application_role' => $course, 'interview_date' => $s('date'), 'interview_mode' => $s('mode'),
                'interview_location' => $s('location'), 'interview_note' => $s('message')];
            break;
    }
    foreach (WHATSAPP_PLACEHOLDERS as $k => $_) {
        if ($s($k) !== '' && $k !== 'student_name') {
            $out[$k] = $s($k);
        }
    }
    foreach (array_diff(array_keys(TEMPLATE_PLACEHOLDERS), ['email']) as $k) {
        $out[$k] ??= $k === 'name' ? $name : ($k === 'link' ? $link : $s($k));
    }
    foreach ($out as $k => $v) {
        if (str_ends_with($k, '_url') && $k !== 'site_url') {
            $out[substr($k, 0, -4) . '_path'] = whatsapp_url_path((string) $v);
        }
    }
    return $out;
}

/** ['order', 12] → ['order', 12] (validated) or null. */
function whatsapp_related(mixed $r): ?array
{
    return is_array($r) && isset($r[0], $r[1]) && is_string($r[0]) && preg_match('/^[a-z_]{1,40}$/', $r[0]) && (int) $r[1] > 0 ? [$r[0], (int) $r[1]] : null;
}

/** Template row with body_params decoded to a clean list. */
function whatsapp_template_row(array $r): array
{
    $params = is_array($r['body_params'] ?? null) ? $r['body_params'] : json_decode_array(is_string($r['body_params'] ?? null) ? $r['body_params'] : null);
    $r['body_params'] = array_values(array_filter($params, fn($p) => is_string($p) && preg_match('/^[a-z_]{1,40}$/', $p)));
    return $r;
}

/** Saved template for an event (null when missing). Cached per request; $fresh re-reads it. */
function whatsapp_template(string $event, bool $fresh = false): ?array
{
    static $cache = [];
    if ($fresh) {
        unset($cache[$event]);
    }
    if (!array_key_exists($event, $cache)) {
        $row = whatsapp_schema_ready() ? db_one('SELECT * FROM whatsapp_templates WHERE event_key = ?', [$event]) : null;
        $cache[$event] = $row ? whatsapp_template_row($row) : null;
    }
    return $cache[$event];
}

/** Insert the default (disabled) row for every event that has none yet. Never overwrites. Returns rows added. */
function whatsapp_seed_templates(): int
{
    if (!whatsapp_schema_ready()) {
        return 0;
    }
    $n = 0;
    foreach (WHATSAPP_EVENTS as $key => $ev) {
        $n += db_query('INSERT IGNORE INTO whatsapp_templates (event_key, label, description, language_code, category, body_preview, body_params, header_type, button_url_param, recipient, is_enabled, requires_opt_in)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)', [
            $key, $ev['label'], mb_substr($ev['trigger'], 0, 255), 'en', $ev['category'], $ev['preview'],
            json_encode(whatsapp_text_placeholders($ev['preview'])), 'none', $ev['button'] ?? null, $ev['recipient'],
            $ev['recipient'] === 'staff' ? 0 : 1,
        ])->rowCount();
    }
    return $n;
}

/** Old Meta mapping "approved_name|name,course" (settings wa_tpl_<key>) as a template row, or null. */
function whatsapp_legacy_template(string $key): ?array
{
    $map = (string) setting('wa_tpl_' . $key, '');
    if ($map === '') {
        return null;
    }
    [$name, $params] = array_pad(explode('|', $map, 2), 2, '');
    return whatsapp_template_row([
        'event_key' => $key, 'interakt_template_name' => trim($name), 'language_code' => (string) setting('whatsapp_language', 'en'),
        'category' => 'utility', 'body_params' => array_map('trim', explode(',', $params)), 'header_type' => 'none', 'header_param' => null,
        'button_url_param' => null, 'recipient' => 'student', 'is_enabled' => 1, 'requires_opt_in' => 1, 'legacy' => true,
    ]);
}

/**
 * The values sent to the provider for a template + variable set:
 * [['body' => [...], 'header' => [...], 'button' => ['0' => [...]]|[], 'file_name' => ?string], null] or [null, error].
 */
function whatsapp_build_values(array $tpl, array $vars): array
{
    if (trim((string) ($tpl['interakt_template_name'] ?? '')) === '') {
        return [null, 'No approved template name is set for this message (WhatsApp → Templates).'];
    }
    $get = static fn(string $k): string => whatsapp_clean_param($vars[$k] ?? '', $k === 'update_text' ? 900 : 500);
    $body = [];
    foreach ($tpl['body_params'] as $i => $k) {
        $v = $get($k);
        if ($v === '') {
            return [null, 'Missing value for {{' . ($i + 1) . '}} (' . $k . '): this event does not provide it or it is empty.'];
        }
        $body[] = $v;
    }
    $header = [];
    $fileName = null;
    $htype = (string) ($tpl['header_type'] ?? 'none');
    $hparam = trim((string) ($tpl['header_param'] ?? ''));
    if ($htype !== 'none' && $htype !== '') {
        $hv = preg_match('#^https://#i', $hparam) ? $hparam : $get($hparam);
        if ($hv === '') {
            return [null, 'Missing header value (' . ($hparam ?: 'not set') . ').'];
        }
        if ($htype !== 'text' && !preg_match('#^https?://#i', $hv)) {
            return [null, 'The ' . $htype . ' header needs a link (https://…), got "' . mb_strimwidth($hv, 0, 40, '…') . '".'];
        }
        $header = [$hv];
        if ($htype === 'document') {
            $inv = $get('invoice_number');
            $fileName = $inv !== '' ? 'Invoice-' . preg_replace('/[^A-Za-z0-9-]+/', '-', $inv) . '.pdf'
                : (preg_replace('/[^A-Za-z0-9._-]+/', '-', basename((string) parse_url($hv, PHP_URL_PATH))) ?: 'document.pdf');
        }
    }
    $button = [];
    $bparam = trim((string) ($tpl['button_url_param'] ?? ''));
    if ($bparam !== '') {
        $bv = $get($bparam);
        if ($bv === '') {
            return [null, 'Missing value for the URL button (' . $bparam . ').'];
        }
        $button = ['0' => [$bv]];
    }
    return [['body' => $body, 'header' => $header, 'button' => $button, 'file_name' => $fileName], null];
}

/** The JSON body POSTed to Interakt (also shown in the admin preview). */
function whatsapp_interakt_body(array $phone, string $name, string $lang, array $values, string $callback): array
{
    $tpl = ['name' => $name, 'languageCode' => $lang];
    if (!empty($values['header'])) {
        $tpl['headerValues'] = array_values($values['header']);
    }
    if (!empty($values['file_name'])) {
        $tpl['fileName'] = $values['file_name'];
    }
    if (!empty($values['body'])) {
        $tpl['bodyValues'] = array_values($values['body']);
    }
    if (!empty($values['button'])) {
        $buttons = [];
        foreach ($values['button'] as $idx => $vals) {
            $buttons[(string) $idx] = array_values((array) $vals);
        }
        $tpl['buttonValues'] = (object) $buttons; // {"0": ["…"]} - a JSON object keyed by button index
    }
    return ['countryCode' => $phone['cc'], 'phoneNumber' => $phone['number'], 'callbackData' => $callback, 'type' => 'Template', 'template' => $tpl];
}

// ------------------------------------------------------------------
// Opt-in
// ------------------------------------------------------------------

/**
 * Record a student's WhatsApp choice. Opting in stores the number it was given for (E.164), so a later
 * change of phone number needs a fresh opt-in. Returns false when the schema is missing or (opt-in)
 * the account has no valid mobile number.
 */
function whatsapp_set_opt_in(int $userId, bool $on, string $source): bool
{
    if (!whatsapp_optin_ready()) {
        return false;
    }
    $source = preg_match('/^[a-z_]{1,30}$/', $source) ? $source : 'other';
    db_query('INSERT IGNORE INTO student_profiles (user_id) VALUES (?)', [$userId]);
    if ($on) {
        $phone = whatsapp_normalize_phone((string) db_value('SELECT phone FROM users WHERE id = ?', [$userId]));
        if (!$phone) {
            return false;
        }
        db_query('UPDATE student_profiles SET whatsapp_opt_in = 1, whatsapp_opt_in_at = NOW(), whatsapp_opt_in_phone = ?, whatsapp_opt_in_source = ?, whatsapp_opt_out_at = NULL WHERE user_id = ?',
            [$phone['e164'], $source, $userId]);
    } else {
        db_query('UPDATE student_profiles SET whatsapp_opt_in = 0, whatsapp_opt_out_at = NOW(), whatsapp_opt_in_source = ? WHERE user_id = ? AND (whatsapp_opt_in = 1 OR whatsapp_opt_out_at IS NULL)',
            [$source, $userId]);
    }
    return true;
}

/** The student's effective opt-in: ['opt_in' => bool (for the current number), 'at' => ?string, 'number_changed' => bool]. */
function whatsapp_user_opt_in(int $userId): array
{
    $out = ['opt_in' => false, 'at' => null, 'number_changed' => false];
    if (!whatsapp_optin_ready()) {
        return $out;
    }
    $r = db_one('SELECT u.phone, p.whatsapp_opt_in, p.whatsapp_opt_in_at, p.whatsapp_opt_in_phone FROM users u LEFT JOIN student_profiles p ON p.user_id = u.id WHERE u.id = ?', [$userId]);
    if (!$r || (int) $r['whatsapp_opt_in'] !== 1) {
        return $out;
    }
    $now = whatsapp_normalize_phone((string) $r['phone']);
    $changed = $r['whatsapp_opt_in_phone'] !== null && (!$now || $now['e164'] !== $r['whatsapp_opt_in_phone']);
    return ['opt_in' => !$changed && $now !== null, 'at' => $r['whatsapp_opt_in_at'], 'number_changed' => $changed];
}

/** Why a student / lead may not receive this message (opt-in rules), or null when allowed. */
function whatsapp_consent_problem(array $to, array $phone): ?string
{
    if (($to['type'] ?? '') === 'lead') {
        return !empty($to['consent']) ? null : 'No WhatsApp consent was given on the form';
    }
    if (empty($to['user_id'])) {
        return 'Not a registered student';
    }
    if (!whatsapp_optin_ready()) {
        return 'WhatsApp opt-in is not set up yet (database update pending)';
    }
    $p = db_one('SELECT whatsapp_opt_in, whatsapp_opt_in_phone FROM student_profiles WHERE user_id = ?', [(int) $to['user_id']]);
    if (!$p || (int) $p['whatsapp_opt_in'] !== 1) {
        return 'Student has not opted in to WhatsApp updates';
    }
    if ($p['whatsapp_opt_in_phone'] !== null && $p['whatsapp_opt_in_phone'] !== $phone['e164']) {
        return 'Opt-in was given for a different mobile number';
    }
    return null;
}

/** Student preference that switches a non-transactional event off, or null when allowed. */
function whatsapp_pref_problem(string $event, array $to): ?string
{
    $pref = WHATSAPP_EVENTS[$event]['pref'] ?? null;
    if ($pref === null || empty($to['user_id'])) {
        return null;
    }
    if ($pref === 'class_reminders') {
        return (int) ($to['notify_class_reminders'] ?? 1) === 1 ? null : 'Student turned off live class reminders';
    }
    return student_pref_enabled($to['notify_prefs'] ?? null, $pref) ? null : 'Student turned off “' . str_replace('_', ' ', $pref) . '” notifications';
}

/** Recipient array for a registered student (row from send_template or loaded here). */
function whatsapp_student_recipient(int $userId, ?array $u = null): ?array
{
    $u ??= db_one("SELECT u.name, u.phone, p.notify_class_reminders, p.notify_prefs FROM users u LEFT JOIN student_profiles p ON p.user_id = u.id WHERE u.id = ? AND u.status = 'active'", [$userId]);
    if (!$u) {
        return null;
    }
    return ['type' => 'student', 'user_id' => $userId, 'name' => (string) ($u['name'] ?? ''), 'phone' => $u['phone'] ?? null,
        'notify_prefs' => $u['notify_prefs'] ?? null, 'notify_class_reminders' => $u['notify_class_reminders'] ?? 1, 'consent' => false];
}

// ------------------------------------------------------------------
// Dispatch, send, retry
// ------------------------------------------------------------------

/**
 * Send one event message to one recipient (the single entry point for every WhatsApp message).
 * $to   ['type' => student|lead|staff, 'user_id', 'name', 'phone', 'consent' (lead), 'notify_prefs', 'notify_class_reminders']
 * $vars placeholder values (whatsapp_event_vars() or sample values)
 * $opts related [type, id] (idempotency), occurrence (string: one occurrence of an event that can repeat for
 *       the same record, e.g. a refund id), test (bool: admin test, works while disabled, sent at once, never
 *       retried), template (row override: unsaved test form / legacy mapping), queue_only (broadcasts), no_retry
 * In a web request the message is logged as 'queued' and sent once the response has been handed off
 * (whatsapp_send_deferred); from the CLI (cron, scripts) it is sent straight away.
 * Returns ['status' => sent|queued|failed|skipped|duplicate|disabled, 'id' => ?int, 'error' => ?string].
 */
function whatsapp_dispatch(string $event, array $to, array $vars, array $opts = []): array
{
    $test = !empty($opts['test']);
    if (!whatsapp_schema_ready() || (!$test && !whatsapp_enabled())) {
        return ['status' => 'disabled', 'id' => null, 'error' => null];
    }
    $tpl = isset($opts['template']) ? whatsapp_template_row($opts['template']) : whatsapp_template($event);
    if (!$tpl || (!$test && empty($tpl['is_enabled']))) {
        return ['status' => 'disabled', 'id' => null, 'error' => null];
    }
    $provider = whatsapp_provider();
    $phone = whatsapp_normalize_phone(isset($to['phone']) ? (string) $to['phone'] : null);
    $related = whatsapp_related($opts['related'] ?? null);
    $phoneKey = $phone['e164'] ?? preg_replace('/\D/', '', (string) ($to['phone'] ?? ''));
    $occurrence = $related ? whatsapp_occurrence($event, $related, $opts) : '';
    $dedupe = !$test && $related ? sha1($event . '|' . $related[0] . ':' . $related[1] . ($occurrence !== '' ? '#' . $occurrence : '') . '|' . $phoneKey) : null;
    if ($dedupe && ($existing = db_value('SELECT id FROM whatsapp_messages WHERE dedupe_key = ?', [$dedupe]))) {
        return ['status' => 'duplicate', 'id' => (int) $existing, 'error' => null];
    }

    // Sensitive values (one-time links) are sent but never stored.
    $sensitive = false;
    $stored = $vars;
    foreach (WHATSAPP_SENSITIVE as $k) {
        if (isset($stored[$k]) && $stored[$k] !== '') {
            $stored[$k] = '[hidden]';
            $sensitive = true;
        }
    }
    $row = [
        'event_key' => $event, 'user_id' => !empty($to['user_id']) ? (int) $to['user_id'] : null,
        'recipient_type' => in_array($to['type'] ?? '', ['student', 'lead', 'staff'], true) ? $to['type'] : 'student',
        'recipient_name' => mb_substr((string) ($to['name'] ?? ''), 0, 160) ?: null,
        'phone' => $phone['number'] ?? (mb_substr(preg_replace('/[^\d+]/', '', (string) ($to['phone'] ?? '')), 0, 20) ?: null),
        'country_code' => $phone['cc'] ?? null, 'template_name' => trim((string) ($tpl['interakt_template_name'] ?? '')) ?: null,
        'language_code' => (string) ($tpl['language_code'] ?? 'en'), 'provider' => $provider,
        'related_type' => $related[0] ?? null, 'related_id' => $related[1] ?? null, 'dedupe_key' => $dedupe, 'is_test' => $test ? 1 : 0,
    ];
    $tplInfo = ['name' => $row['template_name'], 'language' => $row['language_code'], 'category' => $tpl['category'] ?? 'utility',
        'body_params' => $tpl['body_params'], 'header_type' => $tpl['header_type'] ?? 'none', 'header_param' => $tpl['header_param'] ?? null,
        'button_url_param' => $tpl['button_url_param'] ?? null, 'legacy' => !empty($tpl['legacy'])];

    // Eligibility: phone, opt-in / consent, preferences.
    $skip = null;
    if (!$phone) {
        $skip = trim((string) ($to['phone'] ?? '')) === '' ? 'No mobile number on record' : 'Invalid mobile number';
    } elseif (!$test && ($to['type'] ?? '') !== 'staff') {
        if (!empty($tpl['requires_opt_in']) || !empty(WHATSAPP_EVENTS[$event]['always_opt_in'])) {
            $skip = whatsapp_consent_problem($to, $phone);
        }
        $skip ??= whatsapp_pref_problem($event, $to);
    }
    if ($skip !== null) {
        return whatsapp_log_insert($row + ['status' => 'skipped', 'error' => $skip, 'params' => ['vars' => $stored, 'template' => $tplInfo]]);
    }

    [$values, $err] = whatsapp_build_values($tpl, $vars);
    if ($err !== null) {
        return whatsapp_log_insert($row + ['status' => 'failed', 'error' => $err, 'params' => ['vars' => $stored, 'template' => $tplInfo]]);
    }
    $storedValues = $values;
    if ($sensitive) {
        $secrets = array_filter(array_map(fn($k) => (string) ($vars[$k] ?? ''), WHATSAPP_SENSITIVE));
        array_walk_recursive($storedValues, function (&$v) use ($secrets) {
            if (is_string($v) && in_array($v, $secrets, true)) {
                $v = '[hidden]';
            }
        });
    }
    $queueOnly = !empty($opts['queue_only']);
    $ins = whatsapp_log_insert($row + [
        'status' => 'queued', 'error' => null, 'next_attempt_at' => $queueOnly ? date('Y-m-d H:i:s') : null,
        'params' => ['vars' => $stored, 'template' => $tplInfo, 'values' => $storedValues, 'redacted' => $sensitive, 'no_retry' => $test || $sensitive || !empty($opts['no_retry'])],
    ]);
    if ($ins['status'] !== 'queued' || $queueOnly) {
        return $ins;
    }
    if (!$test && whatsapp_is_web_request()) {
        // The visitor never waits for the provider: send once the response has been handed off.
        whatsapp_defer((int) $ins['id'], $values, (string) $row['recipient_type']);
        return $ins;
    }
    if (!$test && whatsapp_circuit_until() > 0) {
        // CLI (cron jobs): the provider did not answer a moment ago, so do not wait on it for every message.
        whatsapp_leave_for_scheduler((int) $ins['id'], true);
        return ['status' => (string) (db_value('SELECT status FROM whatsapp_messages WHERE id = ?', [$ins['id']]) ?: 'queued'), 'id' => $ins['id'], 'error' => null];
    }
    $status = whatsapp_attempt((int) $ins['id'], false, $values);
    $error = in_array($status, ['failed', 'skipped'], true) ? (string) db_value('SELECT error FROM whatsapp_messages WHERE id = ?', [$ins['id']]) : null;
    return ['status' => $status ?? 'queued', 'id' => $ins['id'], 'error' => $error];
}

/**
 * The per-occurrence part of the idempotency key, for events that can happen more than once for the same
 * record: every processed (partial) refund of an order, every password change of an account. A repeated
 * call for the same occurrence still gives the same key, so it is never sent twice.
 */
function whatsapp_occurrence(string $event, array $related, array $opts = []): string
{
    if (isset($opts['occurrence']) && is_scalar($opts['occurrence']) && trim((string) $opts['occurrence']) !== '') {
        return mb_substr(trim((string) $opts['occurrence']), 0, 80);
    }
    if ($event === 'refund_processed' && $related[0] === 'order') {
        // Refunds never leave "processed", so the count names this refund: 1st, 2nd … partial refund.
        return 'refund-' . (int) db_value("SELECT COUNT(*) FROM refunds WHERE order_id = ? AND status = 'processed'", [$related[1]]);
    }
    if ($event === 'password_changed' && $related[0] === 'user') {
        // A one-way fingerprint of the stored password hash: it changes with every new password.
        return 'pw-' . substr((string) db_value('SELECT SHA1(password_hash) FROM users WHERE id = ?', [$related[1]]), 0, 16);
    }
    return '';
}

// ------------------------------------------------------------------
// Sending after the response (web requests)
// ------------------------------------------------------------------

function whatsapp_is_web_request(): bool
{
    return PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg';
}

/** Remember a logged message for whatsapp_send_deferred() (registered once per request). */
function whatsapp_defer(int $id, array $values, string $recipientType): void
{
    static $registered = false;
    $GLOBALS['__whatsapp_deferred'][] = ['id' => $id, 'values' => $values, 'staff' => $recipientType === 'staff'];
    if (!$registered) {
        $registered = true;
        register_shutdown_function('whatsapp_send_deferred');
    }
}

/**
 * Shutdown function: hand the finished response to the visitor, then send this request's messages
 * (students and leads first, staff alerts last). When the response cannot be completed early, sending stops
 * after WHATSAPP_INLINE_BUDGET seconds with short timeouts; while the circuit breaker is open nothing is
 * sent directly. Whatever is left stays queued for the scheduler.
 */
function whatsapp_send_deferred(): void
{
    $jobs = $GLOBALS['__whatsapp_deferred'] ?? [];
    $GLOBALS['__whatsapp_deferred'] = [];
    if (!$jobs) {
        return;
    }
    try {
        usort($jobs, fn($a, $b) => $a['staff'] <=> $b['staff']);
        $released = whatsapp_release_visitor();
        if ($released) {
            @set_time_limit(60 + 10 * min(count($jobs), WHATSAPP_DEFERRED_MAX));
        }
        $start = microtime(true);
        foreach ($jobs as $i => $job) {
            $paused = whatsapp_circuit_until() > 0;
            if ($paused || $i >= WHATSAPP_DEFERRED_MAX || (!$released && microtime(true) - $start >= WHATSAPP_INLINE_BUDGET)) {
                whatsapp_leave_for_scheduler((int) $job['id'], $paused);
                continue;
            }
            whatsapp_attempt((int) $job['id'], false, $job['values'], $released ? WHATSAPP_HTTP_TIMEOUT : WHATSAPP_INLINE_TIMEOUT);
        }
    } catch (Throwable $e) {
        error_log('[laxyam] deferred whatsapp send failed: ' . $e->getMessage());
    }
}

/**
 * Complete the visitor's response before the provider is called. Returns true when the visitor no longer
 * waits: PHP-FPM / LiteSpeed finish the request; on Apache mod_php the buffered output is sent as a complete
 * response (Content-Length, no compression, Connection: close). Otherwise false (output is flushed only).
 */
function whatsapp_release_visitor(): bool
{
    if (!whatsapp_is_web_request()) {
        return true;
    }
    ignore_user_abort(true);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // the visitor's next request must not wait for this session lock
    }
    // These return false only when the response was already finished (e.g. by api/track.php): released either way.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return true;
    }
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
        return true;
    }
    if (!headers_sent() && function_exists('apache_setenv')) {
        $out = '';
        while (($level = ob_get_level()) > 0) {
            $chunk = @ob_get_clean();
            if ($chunk === false || ob_get_level() >= $level) {
                break; // a buffer that cannot be removed: the length would be wrong
            }
            $out = $chunk . $out; // outer buffers hold the earlier output
        }
        if (ob_get_level() === 0) {
            @apache_setenv('no-gzip', '1'); // mod_deflate would drop the Content-Length
            header('Content-Length: ' . strlen($out));
            header('Connection: close');
            echo $out;
            flush();
            return true;
        }
        echo $out;
    }
    while (($level = ob_get_level()) > 0 && @ob_end_flush() && ob_get_level() < $level) {
        // flush every buffer that can be flushed
    }
    flush();
    return false;
}

/**
 * A deferred message that is not sent now (breaker open, time budget used up, too many in one request) waits
 * for the scheduler. A one-time link is never stored, so such a message is closed instead.
 */
function whatsapp_leave_for_scheduler(int $id, bool $paused): void
{
    $row = db_one("SELECT params FROM whatsapp_messages WHERE id = ? AND status = 'queued'", [$id]);
    if (!$row) {
        return;
    }
    if (!empty(json_decode_array($row['params'])['redacted'])) {
        db_query("UPDATE whatsapp_messages SET status = 'failed', next_attempt_at = NULL, error = ? WHERE id = ? AND status = 'queued'",
            ['Not sent: the WhatsApp provider was not reachable just now, and a one-time link is never stored for a later retry. The student can request a new link.', $id]);
        return;
    }
    $until = whatsapp_circuit_until();
    db_query("UPDATE whatsapp_messages SET next_attempt_at = ?, error = ? WHERE id = ? AND status = 'queued'", [
        date('Y-m-d H:i:s', $paused && $until ? $until : time()),
        $paused ? 'Waiting: the provider did not answer a moment ago, so the scheduler sends this from ' . date('g:i A', $until ?: time()) . '.' : 'Waiting for the scheduler (sent within 15 minutes).',
        $id,
    ]);
}

/** Insert a log row. A concurrent duplicate (same dedupe key) returns the existing row as 'duplicate'. */
function whatsapp_log_insert(array $r): array
{
    $cols = ['event_key', 'user_id', 'recipient_type', 'recipient_name', 'phone', 'country_code', 'template_name', 'language_code', 'params', 'status',
        'provider', 'error', 'next_attempt_at', 'related_type', 'related_id', 'dedupe_key', 'is_test'];
    $r['params'] = json_encode($r['params'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $r['error'] = isset($r['error']) ? mb_substr((string) $r['error'], 0, 500) : null;
    try {
        db_query('INSERT INTO whatsapp_messages (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
            array_map(fn($c) => $r[$c] ?? null, $cols));
    } catch (PDOException $e) {
        if ($e->getCode() === '23000' && !empty($r['dedupe_key'])) {
            return ['status' => 'duplicate', 'id' => (int) db_value('SELECT id FROM whatsapp_messages WHERE dedupe_key = ?', [$r['dedupe_key']]), 'error' => null];
        }
        throw $e;
    }
    return ['status' => (string) $r['status'], 'id' => (int) db()->lastInsertId(), 'error' => $r['error']];
}

/**
 * Send (or retry) one logged message. Claims the row first ('sending') so two workers never send it twice.
 * Real (non-test) messages are checked again first (whatsapp_send_blocker): a student who opted out, a
 * switched-off template or a removed admin number turns the row into 'skipped' instead of sending it.
 * $force: admin "Retry now" - ignores the schedule and the attempt limit and rebuilds the values from the
 * current template (so a corrected template name or mapping applies). $live: in-memory values (one-time links).
 * Returns the new status ('disabled' while WhatsApp sending is switched off), or null when not claimable.
 */
function whatsapp_attempt(int $id, bool $force = false, ?array $live = null, int $timeout = WHATSAPP_HTTP_TIMEOUT): ?string
{
    $isTest = db_value('SELECT is_test FROM whatsapp_messages WHERE id = ?', [$id]);
    if ($isTest === null || $isTest === false) {
        return null;
    }
    if (!(int) $isTest && !whatsapp_enabled()) {
        return 'disabled'; // sending is switched off: the row keeps waiting
    }
    $cond = $force ? "status IN ('queued', 'failed')"
        : "(status = 'queued' OR (status = 'failed' AND next_attempt_at IS NOT NULL AND next_attempt_at <= NOW()))";
    if (db_query("UPDATE whatsapp_messages SET status = 'sending' WHERE id = ? AND {$cond}", [$id])->rowCount() !== 1) {
        return null;
    }
    $row = db_one('SELECT * FROM whatsapp_messages WHERE id = ?', [$id]);
    $p = json_decode_array($row['params']);
    $values = $live ?? ($p['values'] ?? null);
    $finish = static fn(array $result) => whatsapp_finish($row, $result, !empty($p['no_retry']));

    if (empty($row['is_test']) && ($why = whatsapp_send_blocker($row, $p)) !== null) {
        db_query("UPDATE whatsapp_messages SET status = 'skipped', next_attempt_at = NULL, error = ? WHERE id = ?",
            [mb_substr('Not sent: ' . $why . ' (checked again just before sending).', 0, 500), $id]);
        return 'skipped';
    }
    if (!empty($p['provider_failed'])) {
        // A new attempt after WhatsApp rejected the earlier one is a new message: track its own id and ticks.
        unset($p['provider_failed']);
        db_query('UPDATE whatsapp_messages SET provider_message_id = NULL, delivered_at = NULL, read_at = NULL, params = ? WHERE id = ?',
            [json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
        $row['params'] = json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($live === null && !empty($p['redacted'])) {
        return $finish(['ok' => false, 'retry' => false, 'error' => 'This message carried a one-time link that is never stored, so it cannot be re-sent. The student can request a new link.']);
    }
    if ($force && empty($row['is_test']) && empty($p['template']['legacy']) && ($tpl = whatsapp_template((string) $row['event_key'], true))) {
        [$fresh, $err] = whatsapp_build_values($tpl, (array) ($p['vars'] ?? []));
        if ($err !== null) {
            return $finish(['ok' => false, 'retry' => false, 'error' => $err]);
        }
        $values = $fresh;
        $p['values'] = $fresh;
        $row['template_name'] = trim((string) $tpl['interakt_template_name']);
        $row['language_code'] = (string) $tpl['language_code'];
        db_query('UPDATE whatsapp_messages SET template_name = ?, language_code = ?, params = ? WHERE id = ?',
            [$row['template_name'], $row['language_code'], json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    }
    $phone = $row['country_code'] ? whatsapp_normalize_phone($row['country_code'] . $row['phone']) : null;
    if (!$phone || !is_array($values) || empty($row['template_name'])) {
        return $finish(['ok' => false, 'retry' => false, 'error' => 'Nothing to send: phone number or template details are missing.']);
    }
    $result = $row['provider'] === 'meta'
        ? whatsapp_send_meta($phone, (string) $row['template_name'], (string) $row['language_code'], $values, $timeout)
        : whatsapp_send_interakt($phone, (string) $row['template_name'], (string) $row['language_code'], $values, 'lx:' . $id, $timeout);
    if (!empty($result['unreachable'])) {
        whatsapp_circuit_trip();
    } elseif (!empty($result['ok'])) {
        whatsapp_circuit_reset();
    }
    return $finish($result);
}

/**
 * Why a logged (non-test) message may no longer be sent, or null when it still may: the event's template
 * (or older Meta mapping) must still be switched on; a student must still be active, on the same mobile
 * number, opted in for it and not have switched the preference off; an admin alert must still go to a
 * listed admin number. Lead rows keep the consent given on their form for this one message.
 */
function whatsapp_send_blocker(array $row, array $p): ?string
{
    $event = (string) $row['event_key'];
    $label = WHATSAPP_EVENTS[$event]['label'] ?? $event;
    if (!empty($p['template']['legacy'])) {
        $on = whatsapp_provider() === 'meta' && whatsapp_legacy_template($event) !== null
            && (int) db_value('SELECT send_whatsapp FROM notification_templates WHERE template_key = ?', [$event]) === 1;
        if (!$on) {
            return 'WhatsApp was switched off for “' . $label . '”';
        }
        $tpl = ['requires_opt_in' => 1];
    } else {
        $tpl = whatsapp_template($event, true);
        if (!$tpl || empty($tpl['is_enabled'])) {
            return 'the “' . $label . '” WhatsApp message was switched off';
        }
    }
    $phone = $row['country_code'] ? whatsapp_normalize_phone($row['country_code'] . $row['phone']) : null;
    if (!$phone) {
        return null; // reported as "nothing to send" by the attempt
    }
    if ($row['recipient_type'] === 'staff') {
        return in_array($phone['e164'], whatsapp_admin_numbers(), true) ? null : 'this number is no longer an admin alert number';
    }
    if ($row['recipient_type'] === 'lead') {
        return null;
    }
    $to = !empty($row['user_id']) ? whatsapp_student_recipient((int) $row['user_id']) : null;
    if (!$to) {
        return 'the student account is no longer active';
    }
    $current = whatsapp_normalize_phone((string) ($to['phone'] ?? ''));
    if (!$current || $current['e164'] !== $phone['e164']) {
        return 'the student’s mobile number has changed since this message was created';
    }
    if (!empty($tpl['requires_opt_in']) || !empty(WHATSAPP_EVENTS[$event]['always_opt_in'])) {
        if (($why = whatsapp_consent_problem($to, $phone)) !== null) {
            return lcfirst($why);
        }
    }
    $why = whatsapp_pref_problem($event, $to);
    return $why !== null ? lcfirst($why) : null;
}

/**
 * Store the outcome of an attempt: sent, or failed with the next retry time (back-off) while attempts remain.
 * A timed-out call may still have been accepted, so its retry waits for the status webhook first. Status
 * webhooks that arrived during the call win: delivered / read stay, and a failure WhatsApp reported stays.
 */
function whatsapp_finish(array $row, array $result, bool $noRetry = false): string
{
    $id = (int) $row['id'];
    $attempts = (int) $row['attempts'] + 1;
    $response = isset($result['response']) ? mb_substr((string) $result['response'], 0, 2000) : null;
    if (!empty($result['ok'])) {
        // Assignments run left to right, so "status" comes last and the others still see the old value.
        $providerFailed = "status = 'failed' AND JSON_EXTRACT(params, '$.provider_failed') IS NOT NULL";
        try {
            db_query("UPDATE whatsapp_messages SET error = IF({$providerFailed}, error, NULL), provider_message_id = COALESCE(provider_message_id, ?),
                      attempts = ?, next_attempt_at = NULL, sent_at = COALESCE(sent_at, NOW()), provider_response = ?,
                      status = CASE WHEN status IN ('delivered', 'read') THEN status WHEN {$providerFailed} THEN 'failed' ELSE 'sent' END WHERE id = ?",
                [mb_substr((string) $result['id'], 0, 100) ?: null, $attempts, $response, $id]);
        } catch (PDOException $e) {
            // Provider id already stored on another row (should not happen): keep the row, note it.
            db_query("UPDATE whatsapp_messages SET status = 'sent', attempts = ?, error = ?, next_attempt_at = NULL, sent_at = NOW(), provider_response = ? WHERE id = ?",
                [$attempts, 'Accepted, but the provider message id was a duplicate.', $response, $id]);
        }
        return (string) db_value('SELECT status FROM whatsapp_messages WHERE id = ?', [$id]);
    }
    $retry = !empty($result['retry']) && !$noRetry && $attempts < whatsapp_max_attempts();
    $minutes = WHATSAPP_BACKOFF_MINUTES[min($attempts - 1, count(WHATSAPP_BACKOFF_MINUTES) - 1)];
    if (!empty($result['timeout'])) {
        $minutes = max($minutes, WHATSAPP_TIMEOUT_RETRY_MINUTES);
    }
    $next = $retry ? date('Y-m-d H:i:s', time() + 60 * $minutes) : null;
    $accepted = "status IN ('sent', 'delivered', 'read')"; // a webhook confirmed it during the call
    $providerFailed = "(status = 'failed' AND JSON_EXTRACT(params, '$.provider_failed') IS NOT NULL)"; // WhatsApp's own reason is kept
    db_query("UPDATE whatsapp_messages SET error = IF({$accepted} OR {$providerFailed}, error, ?), next_attempt_at = IF({$accepted} OR {$providerFailed}, NULL, ?),
              attempts = ?, provider_response = ?, status = IF({$accepted}, status, 'failed') WHERE id = ?",
        [mb_substr((string) ($result['error'] ?? 'Unknown error'), 0, 500), $next, $attempts, $response, $id]);
    return (string) db_value('SELECT status FROM whatsapp_messages WHERE id = ?', [$id]);
}

/** Minimal JSON HTTP client: ['status' => int, 'raw' => string, 'data' => ?array, 'error' => ?string, 'timeout' => bool]. */
function whatsapp_http(string $url, array $body, array $headers, int $timeout = WHATSAPP_HTTP_TIMEOUT): array
{
    $timeout = max(1, $timeout);
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_TIMEOUT => $timeout, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $error = $raw === false ? (curl_error($ch) ?: 'request failed') : null;
        return ['status' => $status, 'raw' => (string) $raw, 'data' => json_decode((string) $raw, true), 'error' => $error, 'timeout' => $errno === CURLE_OPERATION_TIMEDOUT];
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $payload, 'timeout' => $timeout, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    $first = function_exists('http_get_last_response_headers') ? (string) ((http_get_last_response_headers() ?? [])[0] ?? '') : '';
    $status = preg_match('#\s(\d{3})\s#', $first . ' ', $m) ? (int) $m[1] : ($raw !== false ? 200 : 0);
    return ['status' => $status, 'raw' => (string) $raw, 'data' => json_decode((string) $raw, true), 'error' => $raw === false ? 'request failed or timed out' : null, 'timeout' => $raw === false];
}

/**
 * Interakt Send Template API: POST {base}/v1/public/message/ with "Authorization: Basic <API key>".
 * Success: HTTP 2xx and {"result": true, "message": "…", "id": "<message id>"}. 429, 408, 5xx, timeouts and
 * network errors are retried; other 4xx and result:false are permanent (fix the template or number first).
 * 'unreachable' (timeout / no connection) opens the circuit breaker; 'timeout' delays the retry.
 */
function whatsapp_send_interakt(array $phone, string $name, string $lang, array $values, string $callback, int $timeout = WHATSAPP_HTTP_TIMEOUT): array
{
    $key = trim((string) config('INTERAKT_API_KEY', ''));
    if ($key === '') {
        return ['ok' => false, 'retry' => true, 'error' => 'INTERAKT_API_KEY is not set in the server .env file.'];
    }
    $r = whatsapp_http(whatsapp_api_base() . '/v1/public/message/', whatsapp_interakt_body($phone, $name, $lang, $values, $callback), ['Authorization: Basic ' . $key], $timeout);
    $msg = trim((string) (is_array($r['data']) ? ($r['data']['message'] ?? '') : ''));
    $msg = $msg !== '' ? ': ' . mb_substr($msg, 0, 300) : '';
    $base = ['response' => $r['raw'] !== '' ? $r['raw'] : null, 'http' => $r['status']];
    if ($r['error'] !== null) {
        return $base + ['ok' => false, 'retry' => true, 'unreachable' => true, 'timeout' => $r['timeout'], 'error' => $r['timeout']
            ? 'No response from Interakt within ' . $timeout . ' seconds. It may still have been accepted: its status webhook confirms it, otherwise it is retried later.'
            : 'Could not reach Interakt: ' . mb_substr($r['error'], 0, 200)];
    }
    $s = $r['status'];
    if ($s >= 200 && $s < 300) {
        if (is_array($r['data']) && ($r['data']['result'] ?? null) === true && !empty($r['data']['id'])) {
            return $base + ['ok' => true, 'id' => (string) $r['data']['id']];
        }
        return $base + ['ok' => false, 'retry' => false, 'error' => 'Interakt did not accept the message' . ($msg ?: ' (no message id returned)')];
    }
    if ($s === 429 || $s === 408 || $s >= 500 || $s === 0) {
        return $base + ['ok' => false, 'retry' => true, 'error' => ($s === 429 ? 'Interakt rate limit reached (HTTP 429)' : 'Interakt error HTTP ' . $s) . $msg];
    }
    if ($s === 401 || $s === 403) {
        return $base + ['ok' => false, 'retry' => false, 'error' => 'Interakt rejected the API key (HTTP ' . $s . ') - check INTERAKT_API_KEY' . $msg];
    }
    return $base + ['ok' => false, 'retry' => false, 'error' => 'Interakt rejected the message (HTTP ' . $s . ')' . $msg];
}

/** Meta WhatsApp Cloud API (provider "meta"): same values, Graph API template message. */
function whatsapp_send_meta(array $phone, string $name, string $lang, array $values, int $timeout = WHATSAPP_HTTP_TIMEOUT): array
{
    $token = (string) config('WHATSAPP_TOKEN', '');
    $phoneId = (string) config('WHATSAPP_PHONE_ID', '');
    if ($token === '' || $phoneId === '') {
        return ['ok' => false, 'retry' => true, 'error' => 'WHATSAPP_TOKEN / WHATSAPP_PHONE_ID are not set in the server .env file.'];
    }
    $components = [];
    if (!empty($values['header'])) {
        $h = $values['header'][0];
        $components[] = ['type' => 'header', 'parameters' => [preg_match('#^https?://#i', $h) && !empty($values['file_name'])
            ? ['type' => 'document', 'document' => ['link' => $h, 'filename' => $values['file_name']]]
            : (preg_match('#^https?://#i', $h) ? ['type' => 'image', 'image' => ['link' => $h]] : ['type' => 'text', 'text' => $h])]];
    }
    if (!empty($values['body'])) {
        $components[] = ['type' => 'body', 'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => $v], array_values($values['body']))];
    }
    foreach ((array) ($values['button'] ?? []) as $idx => $vals) {
        $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => (string) $idx, 'parameters' => [['type' => 'text', 'text' => (string) ((array) $vals)[0]]]];
    }
    $r = whatsapp_http('https://graph.facebook.com/v20.0/' . rawurlencode($phoneId) . '/messages', [
        'messaging_product' => 'whatsapp', 'to' => ltrim($phone['e164'], '+'), 'type' => 'template',
        'template' => ['name' => $name, 'language' => ['code' => $lang], 'components' => $components],
    ], ['Authorization: Bearer ' . $token], $timeout);
    $base = ['response' => $r['raw'] !== '' ? $r['raw'] : null, 'http' => $r['status']];
    if ($r['error'] !== null) {
        return $base + ['ok' => false, 'retry' => true, 'unreachable' => true, 'timeout' => $r['timeout'], 'error' => $r['timeout']
            ? 'No response from WhatsApp within ' . $timeout . ' seconds. It may still have been accepted, so the retry waits a while.'
            : 'Could not reach WhatsApp: ' . mb_substr($r['error'], 0, 200)];
    }
    $id = $r['data']['messages'][0]['id'] ?? null;
    if ($r['status'] >= 200 && $r['status'] < 300 && $id) {
        return $base + ['ok' => true, 'id' => (string) $id];
    }
    $msg = (string) ($r['data']['error']['message'] ?? ('HTTP ' . $r['status']));
    return $base + ['ok' => false, 'retry' => $r['status'] === 429 || $r['status'] >= 500, 'error' => 'WhatsApp rejected the message: ' . mb_substr($msg, 0, 300)];
}

/**
 * Scheduler job: retry due messages, send queued broadcast messages, close interrupted and expired rows.
 * Every row is re-checked before sending (whatsapp_attempt → whatsapp_send_blocker). While the circuit
 * breaker is open the batch waits ($probe: admin "Process queue now" still tries); a timeout during the
 * batch stops it, so a hanging provider never costs more than one timeout per run.
 * Returns ['processed', 'sent', 'failed', 'skipped', 'expired', 'interrupted', 'paused'].
 */
function process_whatsapp_queue(int $limit = 50, bool $probe = false): array
{
    $out = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'expired' => 0, 'interrupted' => 0, 'paused' => 0];
    if (!whatsapp_schema_ready()) {
        return $out;
    }
    // A request that died mid-send leaves 'sending': delivery is unknown, so it is never re-sent automatically.
    $out['interrupted'] = db_query("UPDATE whatsapp_messages SET status = 'failed', next_attempt_at = NULL,
        error = 'Interrupted while sending - delivery unknown. Check Interakt before retrying.'
        WHERE status = 'sending' AND updated_at < NOW() - INTERVAL 15 MINUTE")->rowCount();
    if (!whatsapp_enabled()) {
        return $out; // sending is switched off: queued messages wait
    }
    // Never deliver days late (class reminders, payment notes …).
    $out['expired'] = db_query("UPDATE whatsapp_messages SET status = 'failed', next_attempt_at = NULL,
        error = 'Expired: could not be sent within 48 hours, so it was not sent late.'
        WHERE status IN ('queued', 'failed') AND (next_attempt_at IS NOT NULL OR status = 'queued') AND created_at < NOW() - INTERVAL 2 DAY")->rowCount();
    $ids = array_column(db_all("SELECT id FROM whatsapp_messages
        WHERE (status = 'queued' AND (next_attempt_at <= NOW() OR (next_attempt_at IS NULL AND created_at < NOW() - INTERVAL 2 MINUTE)))
           OR (status = 'failed' AND next_attempt_at IS NOT NULL AND next_attempt_at <= NOW())
        ORDER BY COALESCE(next_attempt_at, created_at), id LIMIT " . max(1, min(500, $limit))), 'id');
    if ($ids && !$probe && whatsapp_circuit_until() > 0) {
        $out['paused'] = 1; // the provider did not answer a moment ago: the next run tries again
        return $out;
    }
    foreach ($ids as $id) {
        $st = whatsapp_attempt((int) $id);
        if ($st === null || $st === 'disabled') {
            continue;
        }
        $out['processed']++;
        $out[match ($st) { 'failed' => 'failed', 'skipped' => 'skipped', default => 'sent' }]++;
        if ($st === 'failed' && whatsapp_circuit_until() > 0) {
            $out['paused'] = 1; // timed out / unreachable: leave the rest for the next run
            break;
        }
    }
    return $out;
}

/** Wrapper for includes/scheduler.php (returns the number of messages sent). */
function whatsapp_scheduled_job(): int
{
    return process_whatsapp_queue(50)['sent'];
}

// ------------------------------------------------------------------
// Event entry points
// ------------------------------------------------------------------

/**
 * Called by send_template() for every notification template (the email/in-app event is the trigger).
 * Uses the event's WhatsApp template; with provider "meta" an older wa_tpl_<key> mapping still works
 * when the notification template has "WhatsApp" switched on. Returns the dispatch status.
 */
function whatsapp_notify_event(string $key, ?int $userId, array $u, array $vars, bool $legacyFlag = false): string
{
    if (!whatsapp_enabled() || !whatsapp_schema_ready()) {
        return 'disabled';
    }
    $to = $userId ? whatsapp_student_recipient($userId, $u) : [
        'type' => 'lead', 'user_id' => null, 'name' => (string) ($u['name'] ?? ''), 'phone' => $u['phone'] ?? null, 'consent' => !empty($u['whatsapp_consent']),
    ];
    if (!$to) {
        return 'disabled';
    }
    $related = whatsapp_related($vars['related'] ?? null);
    // Optional 'whatsapp_occurrence' var: names one occurrence of an event that can repeat for the same record.
    $opts = ['related' => $related, 'occurrence' => is_scalar($vars['whatsapp_occurrence'] ?? null) ? (string) $vars['whatsapp_occurrence'] : null];
    $status = 'disabled';
    $tpl = whatsapp_template($key);
    if ($tpl && !empty($tpl['is_enabled'])) {
        $status = whatsapp_dispatch($key, $to, whatsapp_event_vars($key, $vars, $to['name']), $opts)['status'];
    } elseif ($legacyFlag && whatsapp_provider() === 'meta' && ($legacy = whatsapp_legacy_template($key))) {
        $status = whatsapp_dispatch($key, $to, whatsapp_event_vars($key, $vars, $to['name']), $opts + ['template' => $legacy])['status'];
    }
    // A verified payment may also send the optional separate invoice message.
    if ($key === 'payment_success' && $userId && ($inv = whatsapp_template('invoice_issued')) && !empty($inv['is_enabled'])) {
        whatsapp_dispatch('invoice_issued', $to, whatsapp_event_vars('invoice_issued', $vars, $to['name']), ['related' => $related]);
    }
    return $status;
}

/** WhatsApp-only send to a lead (no email address on the form): ['name', 'phone', 'whatsapp_consent']. */
function whatsapp_notify_lead(string $key, array $lead, array $vars): string
{
    return whatsapp_notify_event($key, null, $lead, $vars);
}

/**
 * Password reset link on WhatsApp: only when the template is enabled and the account opted in for its
 * current number. The token goes in the URL button value (and {{reset_url}} if mapped), never the password.
 */
function whatsapp_password_reset(int $userId, string $token): string
{
    $tpl = whatsapp_template('password_reset');
    if (!whatsapp_enabled() || !$tpl || empty($tpl['is_enabled']) || !($to = whatsapp_student_recipient($userId))) {
        return 'disabled';
    }
    $vars = whatsapp_event_vars('password_reset', [
        'name' => $to['name'], 'reset_token' => $token, 'reset_url' => app_url('reset-password?token=' . $token),
    ], $to['name']);
    return whatsapp_dispatch('password_reset', $to, $vars, ['no_retry' => true])['status'];
}

/** Send a staff alert to every admin alert number (no opt-in needed: staff configured these numbers). */
function whatsapp_staff_alert(string $event, array $vars, ?array $related): int
{
    $tpl = whatsapp_template($event);
    if (!whatsapp_enabled() || !$tpl || empty($tpl['is_enabled'])) {
        return 0;
    }
    $vars = whatsapp_event_vars($event, $vars, (string) ($vars['student_name'] ?? $vars['lead_name'] ?? ''));
    $n = 0;
    foreach (whatsapp_admin_numbers() as $num) {
        $r = whatsapp_dispatch($event, ['type' => 'staff', 'user_id' => null, 'name' => 'Admin alert', 'phone' => $num], $vars, ['related' => $related]);
        $n += in_array($r['status'], ['sent', 'queued'], true) ? 1 : 0;
    }
    return $n;
}

/**
 * Staff alerts from notify_staff_email() (the email alert is the trigger): the admin path tells which
 * record it is about, and the details are read from the database, so every alert matches a saved record.
 */
function whatsapp_staff_alert_from_path(string $subject, string $adminPath): int
{
    if (!whatsapp_enabled() || !whatsapp_schema_ready()) {
        return 0;
    }
    $page = basename((string) parse_url($adminPath, PHP_URL_PATH));
    parse_str((string) parse_url($adminPath, PHP_URL_QUERY), $q);
    $admin = ['admin_url' => app_url('admin/' . ltrim($adminPath, '/'))];
    if ($page === 'enquiries.php' && !empty($q['id'])) {
        $e = db_one('SELECT e.id, e.name, e.email, e.phone, e.source, c.title FROM enquiries e LEFT JOIN courses c ON c.id = e.course_id WHERE e.id = ?', [(int) $q['id']]);
        if (!$e) {
            return 0;
        }
        $sources = ['contact_form' => 'Contact form', 'callback' => 'Callback request', 'popup' => 'Website popup', 'course_page' => 'Course page',
            'newsletter_block' => 'Newsletter block', 'chatbot' => 'Chatbot', 'home_cta' => 'Homepage', 'hero_card' => 'Hero card', 'careers' => 'Careers'];
        $phone = whatsapp_normalize_phone((string) $e['phone']);
        return whatsapp_staff_alert('admin_new_enquiry', $admin + [
            'student_name' => $e['name'], 'lead_name' => $e['name'], 'lead_phone' => $phone['display'] ?? ((string) $e['phone'] ?: 'Not given'),
            'lead_email' => (string) $e['email'] ?: 'Not given', 'lead_source' => $sources[$e['source']] ?? 'Website',
            'course_name' => (string) $e['title'] ?: 'General enquiry',
        ], ['enquiry', (int) $e['id']]);
    }
    if ($page === 'orders.php' && !empty($q['order']) && str_starts_with($subject, 'New payment')) {
        $o = db_one("SELECT o.id, o.order_number, o.total, u.name FROM orders o JOIN users u ON u.id = o.user_id WHERE o.order_number = ? AND o.status = 'paid' AND o.total > 0", [(string) $q['order']]);
        if (!$o) {
            return 0;
        }
        $courses = implode(', ', array_column(db_all('SELECT title FROM order_items WHERE order_id = ?', [$o['id']]), 'title'));
        if (!function_exists('inr') && is_file(APP_ROOT . '/includes/invoice.php')) {
            require_once APP_ROOT . '/includes/invoice.php'; // inr()
        }
        return whatsapp_staff_alert('admin_new_order', $admin + [
            'student_name' => $o['name'], 'course_name' => $courses ?: 'Course', 'amount' => function_exists('inr') ? inr($o['total']) : '₹' . number_format((float) $o['total'], 2),
            'order_id' => $o['order_number'],
        ], ['order', (int) $o['id']]);
    }
    if ($page === 'jobs.php' && !empty($q['application'])) {
        $a = db_one('SELECT a.id, a.name, a.phone, o.title FROM job_applications a LEFT JOIN job_openings o ON o.id = a.opening_id WHERE a.id = ?', [(int) $q['application']]);
        if (!$a) {
            return 0;
        }
        $phone = whatsapp_normalize_phone((string) $a['phone']);
        return whatsapp_staff_alert('admin_new_application', $admin + [
            'student_name' => $a['name'], 'lead_name' => $a['name'], 'lead_phone' => $phone['display'] ?? (string) $a['phone'],
            'application_role' => (string) $a['title'] ?: 'General application',
        ], ['application', (int) $a['id']]);
    }
    if ($page === 'support.php' && !empty($q['id']) && str_starts_with($subject, 'New support ticket')) {
        $t = db_one('SELECT t.id, t.subject, u.name FROM support_tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?', [(int) $q['id']]);
        if (!$t) {
            return 0;
        }
        return whatsapp_staff_alert('admin_new_ticket', $admin + [
            'student_name' => $t['name'], 'ticket_no' => (string) $t['id'], 'ticket_subject' => $t['subject'],
        ], ['ticket', (int) $t['id']]);
    }
    return 0;
}

// ------------------------------------------------------------------
// Broadcasts (course updates / announcements)
// ------------------------------------------------------------------

/** Opted-in, active students for a broadcast (optionally only one course's active batch). */
function whatsapp_broadcast_audience(?int $courseId, int $limit = 5000): array
{
    if (!whatsapp_optin_ready()) {
        return [];
    }
    $sql = "SELECT u.id, u.name, u.phone, p.notify_prefs, p.notify_class_reminders FROM users u JOIN student_profiles p ON p.user_id = u.id AND p.whatsapp_opt_in = 1"
        . ($courseId ? " JOIN course_enrolments e ON e.user_id = u.id AND e.course_id = ? AND e.status = 'active'" : '')
        . " WHERE u.status = 'active' AND u.user_type = 'student' AND u.phone IS NOT NULL AND u.phone <> '' ORDER BY u.id LIMIT " . max(1, $limit);
    return db_all($sql, $courseId ? [$courseId] : []);
}

/**
 * Queue a broadcast and start sending it. Each student goes through whatsapp_dispatch() (opt-in for the
 * current number, preference, idempotency per broadcast). Returns ['id', 'queued', 'skipped', 'sent', 'failed'].
 */
function whatsapp_broadcast(string $event, ?int $courseId, string $text, int $staffId): array
{
    $course = $courseId ? db_one('SELECT id, title, slug FROM courses WHERE id = ?', [$courseId]) : null;
    db_query('INSERT INTO whatsapp_broadcasts (event_key, course_id, message, created_by) VALUES (?, ?, ?, ?)', [$event, $course['id'] ?? null, $text, $staffId]);
    $bid = (int) db()->lastInsertId();
    $tally = ['id' => $bid, 'queued' => 0, 'skipped' => 0, 'sent' => 0, 'failed' => 0];
    foreach (whatsapp_broadcast_audience($course ? (int) $course['id'] : null) as $u) {
        $to = ['type' => 'student', 'user_id' => (int) $u['id'], 'name' => (string) $u['name'], 'phone' => $u['phone'],
            'notify_prefs' => $u['notify_prefs'], 'notify_class_reminders' => $u['notify_class_reminders']];
        $vars = whatsapp_event_vars($event, ['name' => $u['name'], 'course' => $course['title'] ?? '', 'message' => $text,
            'link' => $course ? '/learn/' . $course['slug'] : ''], (string) $u['name']);
        $r = whatsapp_dispatch($event, $to, $vars, ['related' => ['broadcast', $bid], 'queue_only' => true]);
        $r['status'] === 'queued' ? $tally['queued']++ : ($r['status'] === 'skipped' ? $tally['skipped']++ : ($r['status'] === 'failed' ? $tally['failed']++ : null));
    }
    db_query('UPDATE whatsapp_broadcasts SET recipients = ?, skipped = ? WHERE id = ?', [$tally['queued'], $tally['skipped'] + $tally['failed'], $bid]);
    // Start right away; the scheduler sends the rest in batches.
    $first = process_whatsapp_queue(25);
    $tally['sent'] = $first['sent'];
    return $tally;
}

// ------------------------------------------------------------------
// Webhook (Interakt message status + incoming STOP)
// ------------------------------------------------------------------

/**
 * Interakt signs each webhook with the shared secret: header "Interakt-Signature: sha256=<hex HMAC-SHA256>".
 * Interakt's PHP sample hashes the raw request body; its Node sample hashes the JSON string, so the compact
 * re-encoded body is accepted too (both still need the secret). Constant-time comparison.
 */
function whatsapp_webhook_signature_ok(string $raw, string $header, string $secret): bool
{
    $sig = strtolower(trim($header));
    if (str_starts_with($sig, 'sha256=')) {
        $sig = substr($sig, 7);
    }
    if ($secret === '' || $raw === '' || !preg_match('/^[a-f0-9]{64}$/', $sig)) {
        return false;
    }
    $candidates = [$raw];
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $candidates[] = (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    foreach ($candidates as $c) {
        if (hash_equals(hash_hmac('sha256', $c, $secret), $sig)) {
            return true;
        }
    }
    return false;
}

/** Interakt UTC timestamp ("2026-10-31T10:36:03.170000") → local DATETIME, or null. */
function whatsapp_utc_to_local(mixed $ts): ?string
{
    if (!is_string($ts) || trim($ts) === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($ts, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

/**
 * Apply a verified webhook event. Message status types: message_api_{sent,delivered,read,failed}
 * (and message_campaign_* for messages that match one of ours). The row is found by the Interakt message
 * id or, when the send call timed out before we saw the id, by our callbackData "lx:<row id>".
 * Status only moves forward (sent → delivered → read); a late "failed" never overrides delivered / read,
 * a late "sent" never hides a failure WhatsApp reported, and a positive status stops pending retries of a
 * local failure (no double send after a timeout).
 * message_received "STOP" opts the sender out. Returns ['result' => updated|ignored|opted_out, 'id' => ?int].
 */
function whatsapp_handle_webhook(array $e): array
{
    $type = (string) ($e['type'] ?? '');
    $msg = is_array($e['data']['message'] ?? null) ? $e['data']['message'] : [];
    if (preg_match('/^message_(api|campaign)_(sent|delivered|read|failed)$/', $type, $m)) {
        $pid = mb_substr((string) ($msg['id'] ?? ''), 0, 100);
        $cb = (string) ($msg['meta_data']['source_data']['callback_data'] ?? $msg['callback_data'] ?? '');
        $row = $pid !== '' ? db_one('SELECT * FROM whatsapp_messages WHERE provider_message_id = ?', [$pid]) : null;
        if (!$row && preg_match('/^lx:(\d{1,18})$/', $cb, $mm)) {
            $row = db_one("SELECT * FROM whatsapp_messages WHERE id = ? AND provider = 'interakt' AND (provider_message_id IS NULL OR provider_message_id = ?)", [(int) $mm[1], $pid]);
        }
        if (!$row) {
            return ['result' => 'ignored', 'id' => null];
        }
        $new = $m[2];
        $cur = (string) $row['status'];
        $id = (int) $row['id'];
        $setPid = $pid !== '' && $row['provider_message_id'] === null ? $pid : null;
        $p = json_decode_array($row['params']);
        if ($new === 'failed') {
            if (in_array($cur, ['delivered', 'read'], true)) {
                return ['result' => 'ignored', 'id' => $id];
            }
            $reason = trim((string) ($msg['channel_failure_reason'] ?? '')) ?: 'Delivery failed';
            $code = trim((string) ($msg['channel_error_code'] ?? ''));
            // Marked as reported by WhatsApp: a late "sent" webhook can no longer hide this failure.
            $p['provider_failed'] = ['reason' => mb_substr($reason, 0, 200), 'code' => $code !== '' ? mb_substr($code, 0, 20) : null, 'at' => date('Y-m-d H:i:s')];
            db_query("UPDATE whatsapp_messages SET status = 'failed', next_attempt_at = NULL, error = ?, params = ?, provider_message_id = COALESCE(provider_message_id, ?) WHERE id = ?",
                [mb_substr('WhatsApp: ' . $reason . ($code !== '' ? ' (code ' . $code . ')' : ''), 0, 500), json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $setPid, $id]);
            return ['result' => 'updated', 'id' => $id];
        }
        // Webhooks can arrive out of order. A failure WhatsApp reported is replaced only by proof of delivery
        // (delivered / read), never by a late "sent"; a local failure (timed-out call Interakt did accept)
        // is cleared by any positive status, which also cancels its pending retry (no second send).
        $providerFailed = $cur === 'failed' && !empty($p['provider_failed']);
        if ($providerFailed && $new === 'sent') {
            return ['result' => 'ignored', 'id' => $id];
        }
        if ($providerFailed) {
            $p['provider_failure_superseded'] = $p['provider_failed'] + ['by' => $new];
            unset($p['provider_failed']);
        }
        $rank = WHATSAPP_STATUS_RANK[$new];
        $sentAt = whatsapp_utc_to_local($msg['received_at_utc'] ?? null) ?? date('Y-m-d H:i:s');
        $deliveredAt = $new !== 'sent' ? (whatsapp_utc_to_local($msg['delivered_at_utc'] ?? null) ?? date('Y-m-d H:i:s')) : null;
        $readAt = $new === 'read' ? (whatsapp_utc_to_local($msg['seen_at_utc'] ?? null) ?? date('Y-m-d H:i:s')) : null;
        db_query('UPDATE whatsapp_messages SET error = ?, next_attempt_at = NULL, status = ?, params = ?,
                  provider_message_id = COALESCE(provider_message_id, ?), sent_at = COALESCE(sent_at, ?),
                  delivered_at = COALESCE(delivered_at, ?), read_at = COALESCE(read_at, ?) WHERE id = ?', [
            $cur === 'failed' ? null : $row['error'],
            ($rank > (WHATSAPP_STATUS_RANK[$cur] ?? 0) || $cur === 'failed' || $cur === 'sending') ? $new : $cur,
            json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $setPid, $sentAt, $deliveredAt, $readAt, $id,
        ]);
        return ['result' => 'updated', 'id' => $id];
    }
    if ($type === 'message_received') {
        $text = is_string($msg['message'] ?? null) ? strtoupper(trim($msg['message'])) : '';
        if (!in_array($text, ['STOP', 'UNSUBSCRIBE', 'OPT OUT', 'OPTOUT', 'STOP ALL'], true)) {
            return ['result' => 'ignored', 'id' => null];
        }
        $c = is_array($e['data']['customer'] ?? null) ? $e['data']['customer'] : [];
        $raw = (string) ($c['channel_phone_number'] ?? (($c['country_code'] ?? '') . ($c['phone_number'] ?? '')));
        $phone = whatsapp_normalize_phone(str_starts_with($raw, '+') ? $raw : '+' . preg_replace('/\D/', '', $raw));
        if (!$phone || !whatsapp_optin_ready()) {
            return ['result' => 'ignored', 'id' => null];
        }
        $n = 0;
        foreach (db_all('SELECT u.id, u.phone FROM users u JOIN student_profiles p ON p.user_id = u.id WHERE p.whatsapp_opt_in = 1 AND u.phone LIKE ?', ['%' . substr($phone['number'], -6)]) as $u) {
            $p = whatsapp_normalize_phone((string) $u['phone']);
            if ($p && $p['e164'] === $phone['e164'] && whatsapp_set_opt_in((int) $u['id'], false, 'whatsapp_stop')) {
                $n++;
            }
        }
        return ['result' => $n ? 'opted_out' : 'ignored', 'id' => null];
    }
    return ['result' => 'ignored', 'id' => null];
}

// ------------------------------------------------------------------
// Admin helpers
// ------------------------------------------------------------------

/** Counts for the last $days days (test sends excluded). */
function whatsapp_stats(int $days = 7): array
{
    $r = db_one("SELECT COUNT(*) AS total, SUM(status IN ('sent', 'delivered', 'read')) AS accepted, SUM(status IN ('delivered', 'read')) AS delivered,
                        SUM(status = 'read') AS seen, SUM(status = 'failed' AND next_attempt_at IS NULL) AS failed,
                        SUM(status = 'failed' AND next_attempt_at IS NOT NULL) AS retrying, SUM(status IN ('queued', 'sending')) AS queued,
                        SUM(status = 'skipped') AS skipped
                 FROM whatsapp_messages WHERE is_test = 0 AND created_at >= NOW() - INTERVAL ? DAY", [$days]) ?: [];
    return array_map('intval', $r);
}

/** Human status label + tone for the admin. */
function whatsapp_status_badge(array $r): string
{
    [$label, $tone, $tip] = match (true) {
        $r['status'] === 'read' => ['Read', 'b-green', 'Opened by the recipient (blue ticks)'],
        $r['status'] === 'delivered' => ['Delivered', 'b-green', 'Delivered to the phone (two ticks)'],
        $r['status'] === 'sent' => ['Sent', 'b-blue', 'Accepted by the provider (one tick)'],
        $r['status'] === 'failed' && !empty($r['next_attempt_at']) => ['Retry scheduled', 'b-amber', 'Will be retried automatically'],
        $r['status'] === 'failed' => ['Failed', 'b-red', 'Not delivered'],
        $r['status'] === 'skipped' => ['Skipped', 'b-gray', 'Not sent (rules)'],
        $r['status'] === 'sending' => ['Sending', 'b-blue', 'Being sent now'],
        default => ['Queued', 'b-amber', 'Waiting to be sent'],
    };
    return '<span class="badge ' . $tone . '" title="' . e($tip) . '">' . e($label) . '</span>';
}
