<?php
/**
 * Jobs: government job / exam notifications and careers at Laxyam Academy.
 *
 *   GET  jobs.php?action=alerts&category=&type=&q=&active=1&page=&per_page=
 *   GET  jobs.php?action=alert&slug=   one published alert (details, dates, fees, links, related alerts, seo)
 *   GET  jobs.php?action=openings
 *   GET  jobs.php?action=opening&slug=
 *   POST jobs.php?action=apply      multipart: opening_slug, name, email, phone, experience_years, subjects, message, resume (PDF/DOC/DOCX)
 *        faculty positions also: subject, employment_type, qualifications, english_level, hindi_level,
 *        expected_salary, accept_terms - resume is required for them
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/recruitment.php';

// Govt job alerts: only published rows whose publish date has come are public.
const ALERT_PUBLIC_SQL = "a.status = 'published' AND (a.published_at IS NULL OR a.published_at <= NOW())";
const ALERT_FROM = 'FROM job_alerts a LEFT JOIN course_categories cat ON cat.id = a.category_id LEFT JOIN course_categories pcat ON pcat.id = cat.parent_id';
const ALERT_SELECT = 'a.*, cat.name AS category_name, cat.slug AS category_slug, cat.color AS category_color, cat.parent_id AS category_parent_id';
// Open alerts first (closed ones sink), then featured, then newest.
const ALERT_ORDER = '(a.last_date IS NOT NULL AND a.last_date < CURDATE()), a.is_featured DESC, COALESCE(a.published_at, a.created_at) DESC, a.id DESC';

$action = $_GET['action'] ?? '';
if ($action === 'apply') {
    require_method('POST');
    require_csrf();
} else {
    require_method('GET');
}

match ($action) {
    'alerts' => alerts(),
    'alert' => alert(),
    'openings' => openings(),
    'opening' => opening(),
    'apply' => apply(),
    default => api_error('Unknown action.', 404, 'not_found'),
};

function alerts(): never
{
    $where = [ALERT_PUBLIC_SQL];
    $params = [];
    if ($cats = query_slugs('category')) {
        // A parent exam category (e.g. "ssc") also matches alerts filed under its sub-categories.
        $where[] = '(cat.slug = ? OR pcat.slug = ?)';
        array_push($params, $cats[0], $cats[0]);
    }
    if (($q = query_str('q', 100)) !== '') {
        $where[] = '(a.title LIKE ? OR a.organization LIKE ? OR a.summary LIKE ?)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        array_push($params, $like, $like, $like);
    }
    if (query_str('active', 1) === '1') {
        $where[] = '(a.last_date IS NULL OR a.last_date >= CURDATE())'; // hide alerts whose last date has passed
    }
    // Per-type counts for the filter chips honour every filter except the type itself.
    $counts = array_fill_keys(array_keys(JOB_ALERT_TYPE_LABELS), 0);
    foreach (db_all('SELECT a.alert_type, COUNT(*) AS n ' . ALERT_FROM . ' WHERE ' . implode(' AND ', $where) . ' GROUP BY a.alert_type', $params) as $r) {
        $counts[$r['alert_type']] = (int) $r['n'];
    }
    $type = query_str('type', 20);
    if (isset(JOB_ALERT_TYPE_LABELS[$type])) {
        $where[] = 'a.alert_type = ?';
        $params[] = $type;
    }
    $page = query_int('page', 1, 1, 500);
    $per = query_int('per_page', 20, 1, 50);
    $sql = implode(' AND ', $where);
    $total = (int) db_value('SELECT COUNT(*) ' . ALERT_FROM . " WHERE {$sql}", $params);
    $rows = db_all('SELECT ' . ALERT_SELECT . ' ' . ALERT_FROM . " WHERE {$sql} ORDER BY " . ALERT_ORDER . " LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);
    api_ok([
        'items' => array_map(fn($a) => format_job_alert($a), $rows),
        'counts' => $counts,
        'pagination' => pagination_meta($total, $page, $per),
    ], 200, true);
}

/** One alert for /jobs/:slug, with up to 6 related alerts (same organisation, then the same exam category). */
function alert(): never
{
    $slug = query_str('slug', 220);
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        api_error('This alert is no longer available.', 404, 'not_found');
    }
    $a = db_one('SELECT ' . ALERT_SELECT . ' ' . ALERT_FROM . ' WHERE a.slug = ? AND ' . ALERT_PUBLIC_SQL, [$slug]);
    $preview = false;
    if (!$a && query_str('preview', 1) === '1' && can('cms.manage')) {
        // Staff preview of a draft / scheduled / archived alert from admin → Jobs (never cached).
        $a = db_one('SELECT ' . ALERT_SELECT . ' ' . ALERT_FROM . ' WHERE a.slug = ?', [$slug]);
        $preview = (bool) $a;
    }
    if (!$a) {
        api_error('This alert is no longer available.', 404, 'not_found');
    }

    $match = ['a.organization = ?'];
    $params = [(int) $a['id'], $a['organization']];
    if ($a['category_id']) {
        // Same category, its parent, its sub-categories and its sibling categories.
        $catIds = array_values(array_unique(array_filter([(int) $a['category_id'], (int) $a['category_parent_id']])));
        $in = db_placeholders(count($catIds));
        $match[] = "a.category_id IN ({$in}) OR cat.parent_id IN ({$in})";
        array_push($params, ...$catIds, ...$catIds);
    }
    $params[] = $a['organization'];
    $related = db_all(
        'SELECT ' . ALERT_SELECT . ' ' . ALERT_FROM . ' WHERE ' . ALERT_PUBLIC_SQL . ' AND a.id <> ? AND (' . implode(' OR ', $match) . ')
         ORDER BY a.organization = ? DESC, ' . ALERT_ORDER . ' LIMIT 6',
        $params
    );
    api_ok([
        'alert' => format_job_alert($a, true) + ['preview' => $preview, 'status' => $preview ? $a['status'] : 'published'],
        'related' => array_map(fn($r) => format_job_alert($r), $related),
    ], 200, !$preview);
}

function format_opening(array $o, bool $full = false): array
{
    $types = opening_employment_types($o['employment_type']);
    $base = [
        'title' => $o['title'],
        'slug' => $o['slug'],
        'department' => $o['department'],
        'subject' => $o['subject'],
        'subject_label' => $o['subject'] ? lookup_label('faculty_subject', $o['subject']) : null,
        'is_faculty' => $o['subject'] !== null,
        'employment_type' => $o['employment_type'],
        'employment_type_label' => implode(' / ', $types),
        'employment_types' => array_map(fn($v, $l) => ['value' => $v, 'label' => $l], array_keys($types), $types),
        'location' => $o['location'],
        'experience' => $o['experience'],
        'salary_range' => $o['salary_range'],
        'closes_at' => $o['closes_at'],
        'is_demo' => (bool) $o['is_demo'],
    ];
    return $full ? $base + [
        'description' => $o['description'],
        'responsibilities' => json_decode_array($o['responsibilities']),
        'requirements' => json_decode_array($o['requirements']),
    ] : $base;
}

/** Option lists for the application forms. */
function form_options(): array
{
    $pairs = fn(array $options) => array_map(fn($v, $l) => ['value' => $v, 'label' => $l], array_keys($options), $options);
    return ['subjects' => $pairs(lookup_options('faculty_subject')), 'language_levels' => $pairs(LANGUAGE_LEVELS)];
}

function openings(): never
{
    // Faculty positions first, in the subject list's order
    $rows = db_all("SELECT o.* FROM job_openings o LEFT JOIN lookup_values s ON s.list_key = 'faculty_subject' AND s.value = o.subject
                    WHERE o.status = 'open' AND (o.closes_at IS NULL OR o.closes_at >= CURDATE())
                    ORDER BY o.subject IS NULL, s.sort_order, o.id DESC");
    // Careers page text (admin → Careers Page Editor) + recruitment settings
    $page = json_decode_array(db_value("SELECT content FROM cms_sections WHERE section_key = 'page_careers' AND is_active = 1"));
    api_ok([
        'items' => array_map(fn($o) => format_opening($o, true), $rows),
        'page' => $page ? [
            'title' => $page['title'] ?? 'Careers', 'intro' => $page['intro'] ?? '',
            'sections' => array_map(fn($s) => ['heading' => $s['heading'] ?? '', 'html' => text_to_html($s['body'] ?? ''), 'icon' => $s['icon'] ?? null], $page['sections'] ?? []),
        ] : null,
        'general_applications' => setting('recruit_general_applications', '1') === '1',
    ] + form_options(), 200, true);
}

function opening(): never
{
    $o = db_one("SELECT * FROM job_openings WHERE slug = ? AND status = 'open' AND (closes_at IS NULL OR closes_at >= CURDATE())", [query_str('slug', 180)]);
    if (!$o) {
        api_error('This opening is no longer available.', 404, 'not_found');
    }
    api_ok(format_opening($o, true) + form_options(), 200, true);
}

function apply(): never
{
    if (!rate_limit('job-apply', client_ip(), 5, 3600)) {
        api_error('Too many applications from your network. Please try again later.', 429, 'rate_limited');
    }
    if (!empty($_POST['website'])) {
        api_ok(['received' => true], 201); // honeypot
    }
    require_recaptcha();
    $slug = is_string($_POST['opening_slug'] ?? null) ? trim($_POST['opening_slug']) : '';
    $opening = preg_match('/^[a-z0-9-]{1,180}$/', $slug)
        ? db_one("SELECT id, title, subject, employment_type FROM job_openings WHERE slug = ? AND status = 'open' AND (closes_at IS NULL OR closes_at >= CURDATE())", [$slug])
        : null;
    if ($slug !== '' && !$opening) {
        api_error('This position is no longer open. Please choose another one.', 422, 'validation_failed', ['opening_slug' => 'This position is closed.']);
    }
    $openingId = $opening['id'] ?? null;
    $faculty = $opening && $opening['subject'] !== null;

    $rules = [
        'name' => 'required|string|min:2|max:120',
        'email' => 'required|email',
        'phone' => 'required|phone',
        'experience_years' => 'int|min:0|max:60',
        'subjects' => 'string|max:200',
        'message' => 'string|max:3000',
    ];
    if ($faculty) {
        // Faculty positions: everything the hiring team needs to shortlist without a call.
        $rules = [
            'subject' => 'required|in:' . implode(',', array_keys(lookup_options('faculty_subject'))),
            'employment_type' => 'required|in:' . implode(',', array_keys(opening_employment_types($opening['employment_type']))),
            'qualifications' => 'required|string|min:2|max:500',
            'experience_years' => 'required|int|min:0|max:60',
            'english_level' => 'required|in:' . implode(',', array_keys(LANGUAGE_LEVELS)),
            'hindi_level' => 'required|in:' . implode(',', array_keys(LANGUAGE_LEVELS)),
            'expected_salary' => 'required|string|max:60',
        ] + $rules;
    }
    $file = $_FILES['resume'] ?? null;
    $hasResume = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    [$d, $errors] = validate($_POST, $rules);
    if ($faculty && !$hasResume) {
        $errors['resume'] = 'Please upload your resume (PDF, DOC or DOCX).';
    }
    if ($faculty && empty($_POST['accept_terms'])) {
        $errors['accept_terms'] = 'Please confirm to continue.';
    }
    if ($errors) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', $errors);
    }
    if ($openingId && ($prev = db_value("SELECT created_at FROM job_applications WHERE email = ? AND opening_id = ? AND status <> 'rejected' AND created_at > NOW() - INTERVAL 30 DAY ORDER BY id DESC LIMIT 1", [$d['email'], $openingId]))) {
        api_error('We already have your application for this position (sent on ' . date('j M Y', strtotime($prev)) . '). Our team will contact you.', 409, 'already_applied');
    }
    if (!$openingId && setting('recruit_general_applications', '1') !== '1') {
        api_error('Please choose an open position to apply for.', 422, 'validation_failed', ['opening_slug' => 'Choose a position.']);
    }

    // Resume: PDF/DOC/DOCX up to 5 MB, verified by content, stored privately.
    $resume = null;
    if ($hasResume) {
        try {
            $resume = store_private_upload($file, 'resumes', 5 * 1_048_576, [
                'application/pdf' => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            ])['path'];
        } catch (InvalidArgumentException $e) {
            api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['resume' => str_contains($e->getMessage(), 'type') ? 'Upload a PDF, DOC or DOCX file.' : $e->getMessage()]);
        }
    }

    db_query(
        'INSERT INTO job_applications (opening_id, subject, employment_type, name, email, phone, qualifications, experience_years, english_level, hindi_level, expected_salary, subjects, message, resume_path, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$openingId, $d['subject'] ?? null, $d['employment_type'] ?? null, $d['name'], $d['email'], $d['phone'], $d['qualifications'] ?? null, $d['experience_years'],
         $d['english_level'] ?? null, $d['hindi_level'] ?? null, $d['expected_salary'] ?? null, $d['subjects'], $d['message'], $resume, client_ip()]
    );
    $appId = (int) db()->lastInsertId();
    // Optional, unticked "Send me updates about my application on WhatsApp" box: the only WhatsApp consent for careers messages.
    $waConsent = in_array((string) ($_POST['whatsapp_consent'] ?? ''), ['1', 'true', 'on'], true);
    if ($waConsent) {
        try {
            db_query('UPDATE job_applications SET whatsapp_consent_at = NOW() WHERE id = ?', [$appId]);
        } catch (PDOException $e) {
            $waConsent = false; // database update not applied yet: no consent on record, so no WhatsApp
        }
    }
    $role = $opening['title'] ?? 'a role at Laxyam Academy';
    $detail = $faculty ? lookup_label('faculty_subject', $d['subject']) . ' · ' . lookup_label('employment_type', $d['employment_type']) : null;
    application_event($appId, 'status', 'Application received' . ($detail ? " ({$detail})" : ''), null, null); // by the candidate, never the visitor's session

    // Admin → Recruitment Settings
    require_once APP_ROOT . '/includes/notify.php';
    if (setting('recruit_ack_email', '1') === '1') {
        send_template('application_ack', ['name' => $d['name'], 'email' => $d['email'], 'phone' => $d['phone'], 'whatsapp_consent' => $waConsent], ['course' => $role]);
    }
    notify_staff_email(setting('recruit_notify_email'), 'New job application: ' . $d['name'],
        "Role: {$role}" . ($detail ? "\nSubject / type: {$detail}\nQualifications: {$d['qualifications']}\nExperience: {$d['experience_years']} years\nExpected salary: {$d['expected_salary']}" : '')
        . "\nEmail: {$d['email']}\nPhone: {$d['phone']}", 'jobs.php?tab=applications&application=' . $appId);
    api_ok(['received' => true, 'reference' => 'APP-' . str_pad((string) $appId, 5, '0', STR_PAD_LEFT)], 201);
}
