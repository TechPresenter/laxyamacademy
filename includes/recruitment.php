<?php
/**
 * Faculty recruitment and govt job alerts: shared option lists, formatting helpers and
 * the applicant activity timeline. Used by api/jobs.php, admin/jobs.php and index.php.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

const LANGUAGE_LEVELS = ['basic' => 'Basic', 'good' => 'Good / comfortable', 'fluent' => 'Fluent'];
const INTERVIEW_MODES = ['in_person' => 'In person (at the academy)', 'video' => 'Video call', 'phone' => 'Phone call'];
const APPLICATION_STATUSES = ['new' => 'New', 'shortlisted' => 'Shortlisted', 'interview' => 'Interview', 'hired' => 'Hired', 'rejected' => 'Not selected'];
/** Email template sent (optionally) when the status changes to … */
const APPLICATION_STATUS_TEMPLATES = ['shortlisted' => 'application_shortlisted', 'rejected' => 'application_rejected', 'hired' => 'application_hired'];

/** Employment types an opening offers, e.g. "full_time,part_time" → ['full_time' => 'Full-time', …]. */
function opening_employment_types(?string $csv): array
{
    $out = [];
    foreach (array_filter(array_map('trim', explode(',', (string) $csv))) as $v) {
        $out[$v] = lookup_label('employment_type', $v);
    }
    return $out;
}

/**
 * Record an entry on an application's timeline.
 * $by: who did it - omitted = the signed-in staff member; null = the candidate (website form),
 * so a visitor who happens to be logged in is never named on the admin timeline.
 */
function application_event(int $applicationId, string $type, string $summary, ?string $body = null, int|false|null $by = false): void
{
    if ($by === false) {
        $by = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }
    db_query('INSERT INTO job_application_events (application_id, type, summary, body, created_by) VALUES (?, ?, ?, ?, ?)',
        [$applicationId, $type, mb_substr($summary, 0, 255), $body, $by]);
}

// ------------------------------------------------------------------
// Govt job / exam alerts (job_alerts)
// ------------------------------------------------------------------

/** Alert types → labels (same wording as JOB_ALERT_TYPES in includes/media.php for the header menu and search). */
const JOB_ALERT_TYPE_LABELS = [
    'notification' => 'Recruitment notification', 'admit_card' => 'Admit card', 'result' => 'Result',
    'answer_key' => 'Answer key', 'syllabus' => 'Syllabus',
];
/** Admin badge colour per type (assets/admin.css b-*). */
const JOB_ALERT_TYPE_TONES = ['notification' => 'blue', 'admit_card' => 'purple', 'result' => 'green', 'answer_key' => 'amber', 'syllabus' => 'gray'];
/** "Duplicate as…" suggestion: the update that usually follows each type. */
const JOB_ALERT_NEXT_TYPE = ['notification' => 'admit_card', 'admit_card' => 'answer_key', 'answer_key' => 'result', 'result' => 'notification', 'syllabus' => 'notification'];
/** An alert is "new" for this many days after publishing and "closing soon" this many days before its last date. */
const JOB_ALERT_NEW_DAYS = 7;
const JOB_ALERT_CLOSING_DAYS = 7;
const JOB_ALERT_MAX_DATES = 20;
const JOB_ALERT_MAX_LINKS = 12;

function job_alert_type_label(string $type): string
{
    return JOB_ALERT_TYPE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

/** Whole days from today until a Y-m-d date (negative when past), or null. */
function job_alert_days_left(?string $date): ?int
{
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    return (int) round((strtotime($date . ' 00:00:00') - strtotime(date('Y-m-d') . ' 00:00:00')) / 86400);
}

/**
 * Stored important_dates → [{label, date, is_date}]. "date" is a Y-m-d date, or a short
 * text such as "To be announced" when the notification gives no exact date (is_date false).
 */
function job_alert_dates(?string $json): array
{
    $out = [];
    foreach (json_decode_array($json) as $d) {
        $label = is_array($d) && is_string($d['label'] ?? null) ? trim($d['label']) : '';
        $date = is_array($d) && is_string($d['date'] ?? null) ? trim($d['date']) : '';
        if ($label !== '' && $date !== '') {
            $out[] = ['label' => $label, 'date' => $date, 'is_date' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)];
        }
    }
    return $out;
}

/** Stored extra_links → [{label, url}], https links only. */
function job_alert_links(?string $json): array
{
    $out = [];
    foreach (json_decode_array($json) as $l) {
        $url = is_array($l) && is_string($l['url'] ?? null) ? trim($l['url']) : '';
        $label = is_array($l) && is_string($l['label'] ?? null) ? trim($l['label']) : '';
        if (preg_match('#^https://[^\s<>"]+$#i', $url)) {
            $out[] = ['label' => $label !== '' ? $label : 'Official link', 'url' => $url];
        }
    }
    return $out;
}

/** Page title + meta description (index.php renders the same for crawlers). */
function job_alert_seo(array $a): array
{
    $site = (string) setting('site_name', 'Laxyam Academy');
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($a['summary'] ?: ''))));
    if ($text === '') {
        $text = job_alert_type_label($a['alert_type']) . ': ' . $a['title'] . ' - official update from ' . $a['organization']
            . '. Important dates, eligibility, fees and official links.';
    }
    return [
        'title' => $a['title'] . ' - ' . $a['organization'] . ' | ' . $site,
        'description' => mb_strimwidth($text, 0, 160, '…'),
    ];
}

/**
 * Public shape of a job_alerts row (joined with the category: category_name, category_slug,
 * category_color). $full adds the detail-page fields.
 */
function format_job_alert(array $a, bool $full = false): array
{
    $daysLeft = job_alert_days_left($a['last_date']);
    $closed = $daysLeft !== null && $daysLeft < 0;
    $published = $a['published_at'] ?? null;
    $card = [
        'id' => (int) $a['id'],
        'title' => $a['title'],
        'slug' => $a['slug'],
        'organization' => $a['organization'],
        'type' => $a['alert_type'],
        'type_label' => job_alert_type_label($a['alert_type']),
        'summary' => $a['summary'],
        'vacancies' => $a['vacancies'],
        'qualification' => $a['qualification'],
        'apply_start' => $a['apply_start'],
        'last_date' => $a['last_date'],
        'days_left' => $closed ? null : $daysLeft,
        'closed' => $closed,
        'closing_soon' => !$closed && $daysLeft !== null && $daysLeft <= JOB_ALERT_CLOSING_DAYS,
        'official_url' => preg_match('#^https://[^\s<>"]+$#i', (string) $a['official_url']) ? $a['official_url'] : null,
        'category' => !empty($a['category_slug']) ? ['name' => $a['category_name'], 'slug' => $a['category_slug'], 'color' => $a['category_color'] ?? null] : null,
        'is_featured' => (bool) $a['is_featured'],
        'published_at' => iso_datetime($published),
        'is_new' => $published !== null && strtotime($published) >= time() - JOB_ALERT_NEW_DAYS * 86400,
        'is_demo' => (bool) $a['is_demo'],
    ];
    if (!$full) {
        return $card;
    }
    return $card + [
        'details' => ($a['details'] ?? '') !== '' ? sanitize_html($a['details']) : null,
        'important_dates' => job_alert_dates($a['important_dates'] ?? null),
        'application_fee' => ($a['application_fee'] ?? '') !== '' ? $a['application_fee'] : null,
        'age_limit' => ($a['age_limit'] ?? '') !== '' ? $a['age_limit'] : null,
        'selection_process' => ($a['selection_process'] ?? '') !== '' ? $a['selection_process'] : null,
        'extra_links' => job_alert_links($a['extra_links'] ?? null),
        'updated_at' => iso_datetime($a['updated_at'] ?? null),
        'share_url' => app_url('jobs/' . $a['slug']),
        'seo' => job_alert_seo($a),
    ];
}

/** Indian mobile numbers without a country code get +91 for WhatsApp links. */
function whatsapp_link(string $phone, string $text = ''): string
{
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) === 10) {
        $digits = '91' . $digits;
    }
    return 'https://wa.me/' . $digits . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}
