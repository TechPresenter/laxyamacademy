<?php
// Enquiries & leads: contact form, course enquiries, callback requests, popups, chatbot. Follow-up pipeline.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';

/** Lead source labels (api/enquiries.php ENQUIRY_SOURCES + chatbot / integrations). */
const ENQUIRY_SOURCE_LABELS = [
    'contact_form' => 'Contact form', 'course_page' => 'Course page', 'callback' => 'Callback request', 'popup' => 'Website popup',
    'home_cta' => 'Homepage CTA', 'newsletter_block' => 'Newsletter block', 'chatbot' => 'Website chatbot', 'careers' => 'Careers',
    'hero_card' => 'Hero card', 'api' => 'API / integration', 'other' => 'Other',
];
const ENQUIRY_SOURCE_TONES = ['popup' => 'purple', 'course_page' => 'blue', 'callback' => 'amber', 'chatbot' => 'green', 'home_cta' => 'blue'];

// WhatsApp number + qualification come from the course admission form (migration 017).
$admissionCols = (bool) db_value("SHOW COLUMNS FROM enquiries LIKE 'whatsapp'");

if (($_GET['export'] ?? '') === 'csv') {
    require_staff_page();
    require_permission('reports.export');
    $cols = $admissionCols ? 'e.whatsapp, e.qualification' : 'NULL AS whatsapp, NULL AS qualification';
    csv_download('enquiries-' . date('Ymd') . '.csv', ['ID', 'Name', 'Email', 'Phone', 'WhatsApp', 'Qualification', 'Subject', 'Source', 'Status', 'Course', 'Consent given', 'Page', 'Created'],
        array_map(fn($r) => [$r['id'], $r['name'], $r['email'], $r['phone'], $r['whatsapp'], $r['qualification'], $r['subject'], ENQUIRY_SOURCE_LABELS[$r['source']] ?? $r['source'], $r['status'], $r['title'], $r['consent_at'], $r['page_url'], $r['created_at']],
            db_all("SELECT e.id, e.name, e.email, e.phone, {$cols}, e.subject, e.source, e.status, c.title, e.consent_at, e.page_url, e.created_at FROM enquiries e LEFT JOIN courses c ON c.id = e.course_id ORDER BY e.id DESC")));
}

$staff = fn() => options_from("SELECT id, name AS label FROM users WHERE user_type = 'staff' AND status = 'active' ORDER BY name");
$sourceBadge = fn(string $s) => '<span class="badge b-' . (ENQUIRY_SOURCE_TONES[$s] ?? 'gray') . '">' . e(ENQUIRY_SOURCE_LABELS[$s] ?? ucwords(str_replace('_', ' ', $s))) . '</span>';
/** wa.me link: 10-digit Indian numbers get the 91 country code. */
$waLink = function (string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    return 'https://wa.me/' . (strlen($digits) === 10 ? '91' . $digits : $digits);
};
$pageLink = fn(?string $path) => $path ? '<a href="' . e(app_url(ltrim($path, '/'))) . '" target="_blank" rel="noopener">' . e(mb_strimwidth($path, 0, 60, '…')) . '</a>' : '<span class="muted">—</span>';

crud([
    'table' => 'enquiries',
    'title' => 'Enquiries & Leads',
    'singular' => 'enquiry',
    'permission' => 'enquiries.view',
    'manage_permission' => 'enquiries.manage',
    'active' => 'enquiries',
    'can_create' => false,
    'header_actions' => can('reports.export') ? '<a class="btn btn-light" href="?export=csv">' . icon('download') . ' Export CSV</a>' : '',
    'from' => 'enquiries t LEFT JOIN courses c ON c.id = t.course_id LEFT JOIN users s ON s.id = t.assigned_to',
    'select' => 't.*, c.title AS course_title, c.slug AS course_slug, s.name AS staff_name',
    'order' => "FIELD(t.status, 'new', 'follow_up', 'contacted', 'converted', 'closed', 'spam'), t.id DESC",
    'search' => ['t.name', 't.email', 't.phone', 't.message'],
    'sortable' => ['name' => 't.name', 'created_at' => 't.created_at', 'status' => 't.status', 'source' => 't.source'],
    'bulk_status' => ['field' => 'status', 'label' => 'status', 'options' => ['contacted' => 'Contacted', 'follow_up' => 'Follow-up', 'converted' => 'Converted', 'closed' => 'Closed', 'spam' => 'Spam']],
    'filters' => [
        'assigned' => ['col' => 't.assigned_to', 'label' => 'Assignees', 'options' => $staff],
        'status' => ['col' => 't.status', 'label' => 'Statuses', 'options' => ['new' => 'New', 'contacted' => 'Contacted', 'follow_up' => 'Follow-up', 'converted' => 'Converted', 'closed' => 'Closed', 'spam' => 'Spam']],
        'source' => ['col' => 't.source', 'label' => 'Sources', 'options' => ENQUIRY_SOURCE_LABELS],
    ],
    'columns' => [
        'name' => ['Lead', fn($r) => '<div class="d2-minw" style="min-width:180px"><strong>' . e($r['name']) . '</strong>'
            . ($r['phone'] ? '<span class="d2-sub"><a href="tel:' . e($r['phone']) . '">' . e($r['phone']) . '</a></span>' : '')
            . (!empty($r['whatsapp']) && $r['whatsapp'] !== $r['phone'] ? '<span class="d2-sub">WhatsApp ' . e($r['whatsapp']) . '</span>' : '')
            . ($r['email'] ? '<span class="d2-sub"><a href="mailto:' . e($r['email']) . '">' . e($r['email']) . '</a></span>' : '')
            . (!empty($r['qualification']) ? '<span class="d2-sub">' . e($r['qualification']) . '</span>' : '') . '</div>'],
        'message' => ['Enquiry', fn($r) => '<div class="d2-w200">' . ($r['course_title'] ? '<span class="badge b-blue">' . e($r['course_title']) . '</span>' : '')
            . '<span class="d2-sub d2-clip" title="' . e($r['message']) . '">' . e($r['message']) . '</span></div>'],
        'source' => ['Source', fn($r) => '<div>' . $sourceBadge($r['source'])
            . ($r['consent_at'] ? '<span class="d2-sub" title="Consent to be contacted given ' . e(fmt_dt($r['consent_at'])) . '">Consent given</span>' : '') . '</div>'],
        'created_at' => ['Received', fn($r) => '<div class="d2-nowrap">' . e(fmt_dt($r['created_at'], 'j M Y')) . '<span class="d2-sub">' . e(fmt_dt($r['created_at'], 'g:i A')) . '</span></div>'],
        // Status + owner in one column keeps the row actions visible without sideways scrolling.
        'status' => ['Status', fn($r) => '<div>' . badge($r['status'])
            . '<span class="d2-sub">' . ($r['staff_name'] ? e($r['staff_name']) : 'Unassigned') . '</span>'
            . ($r['follow_up_at'] ? '<span class="d2-sub">Follow up ' . e(fmt_dt($r['follow_up_at'], 'j M, g:i A')) . '</span>' : '') . '</div>'],
    ],
    'row_actions' => fn($r) => (($r['whatsapp'] ?? null) ?: $r['phone'])
        ? '<a class="btn btn-light btn-sm" href="' . e($waLink(($r['whatsapp'] ?? null) ?: $r['phone'])) . '" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp ' . e($r['name']) . '" title="Chat on WhatsApp" style="color:#128C7E;border-color:#bfe9d6">' . icon('whatsapp') . '<span class="sr-only">WhatsApp</span></a>'
        : '',
    'fields' => [
        'name' => ['label' => 'Name', 'readonly' => true],
        'phone' => ['label' => 'Phone', 'readonly' => true],
        ...($admissionCols ? ['whatsapp' => ['label' => 'WhatsApp number', 'readonly' => true], 'qualification' => ['label' => 'Education qualification', 'readonly' => true]] : []),
        'email' => ['label' => 'Email', 'readonly' => true],
        'subject' => ['label' => 'Subject', 'readonly' => true],
        'message' => ['label' => 'Message', 'type' => 'textarea', 'readonly' => true],
        'status' => ['label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['new' => 'New', 'contacted' => 'Contacted', 'follow_up' => 'Follow-up', 'converted' => 'Converted', 'closed' => 'Closed', 'spam' => 'Spam']],
        'assigned_to' => ['label' => 'Assigned to', 'type' => 'select', 'options' => $staff],
        'follow_up_at' => ['label' => 'Next follow-up', 'type' => 'datetime'],
        'notes' => ['label' => 'Internal notes', 'type' => 'textarea'],
    ],
    // Read-only lead details: where it came from and the recorded consent
    'form_extra' => function (?array $row) use ($sourceBadge, $pageLink, $waLink) {
        if (!$row) {
            return '';
        }
        $course = $row['course_id'] ? db_one('SELECT title, slug FROM courses WHERE id = ?', [$row['course_id']]) : null;
        return '<div class="card" style="box-shadow:none;background:var(--surface)"><div class="card-pad"><dl class="kv">'
            . '<dt>Source</dt><dd>' . $sourceBadge($row['source']) . '</dd>'
            . '<dt>Course</dt><dd>' . ($course ? '<a href="' . e(app_url('courses/' . $course['slug'])) . '" target="_blank" rel="noopener">' . e($course['title']) . '</a>' : '<span class="muted">—</span>') . '</dd>'
            . '<dt>Submitted from</dt><dd>' . $pageLink($row['page_url']) . '</dd>'
            . '<dt>Consent to contact</dt><dd>' . ($row['consent_at'] ? icon('check') . ' Given on ' . e(fmt_dt($row['consent_at'])) : '<span class="muted">Not recorded (form without a consent box)</span>') . '</dd>'
            . '<dt>Received</dt><dd>' . e(fmt_dt($row['created_at'])) . '</dd>'
            . ($row['phone'] ? '<dt>Quick contact</dt><dd><a class="btn btn-light btn-sm" href="tel:' . e($row['phone']) . '">Call</a> <a class="btn btn-light btn-sm" href="' . e($waLink(($row['whatsapp'] ?? null) ?: $row['phone'])) . '" target="_blank" rel="noopener noreferrer" style="color:#128C7E;border-color:#bfe9d6">' . icon('whatsapp') . ' WhatsApp</a></dd>' : '')
            . '</dl></div></div>';
    },
]);
