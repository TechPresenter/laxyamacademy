<?php
/**
 * Student dashboard API. Every query is scoped to the logged-in user's id;
 * no endpoint accepts a user id from the browser.
 *
 *   GET  students.php?action=dashboard
 *   GET  students.php?action=courses
 *   GET  students.php?action=profile
 *   POST students.php?action=profile                 name, phone, date_of_birth, gender, city, state, exam_preferences[], notify_*
 *   GET  students.php?action=sessions
 *   POST students.php?action=revoke-session          id
 *   GET  students.php?action=notifications&page=[&type=<type>|payments|learning|tests|account|other][&unread=1]
 *   POST students.php?action=notifications-read      id (optional; all when omitted)
 *   POST students.php?action=preferences             notify_email, notify_class_reminders, notify_prefs{}, whatsapp_opt_in (each optional)
 *   GET  students.php?action=purchases
 *   POST students.php?action=delete-request          password
 *   GET  students.php?action=enrolment&course=slug[&order=LXA…]   congratulations page data
 *   POST students.php?action=review                  course (slug), rating 1-5, review (≤ 2000 chars)
 *   GET  students.php?action=referral                my referral code, the friend's offer, friends joined and my reward coupons
 *
 * Public (no login), the only exception to the rule above:
 *   GET  students.php?action=verify-certificate&no=LXA-2026-…   printed on every certificate: a branded HTML
 *        page in a browser; JSON when the request sends Accept: application/json or &format=json
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/catalog.php';
require_once APP_ROOT . '/includes/referrals.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($action === 'verify-certificate') {
    require_method('GET');
    verify_certificate();
}
if ($method === 'POST') {
    require_csrf();
} else {
    require_method('GET');
}
$user = require_student();
$uid = (int) $user['id'];

define('NOTIFY_DEFAULTS', array_merge(
    ['course_updates' => true, 'test_results' => true, 'offers' => false, 'announcements' => true, 'study_tips' => false],
    array_map('boolval', array_intersect_key(json_decode_array(setting('notify_default_prefs')), ['course_updates' => 1, 'test_results' => 1, 'offers' => 1, 'announcements' => 1, 'study_tips' => 1]))
));

match ([$action, $method]) {
    ['summary', 'GET'] => api_ok([
        'unread_notifications' => (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$uid]),
        'wishlist' => array_map('intval', array_column(db_all('SELECT course_id FROM wishlists WHERE user_id = ?', [$uid]), 'course_id')),
        'open_tickets' => (int) db_value("SELECT COUNT(*) FROM support_tickets WHERE user_id = ? AND status IN ('open','in_progress','waiting')", [$uid]),
        // Doubts with a teacher's answer the student has not opened yet (0 until database update 013).
        'unread_doubts' => db_value("SHOW TABLES LIKE 'doubts'") ? (int) db_value('SELECT COUNT(*) FROM doubts WHERE user_id = ? AND student_unread = 1', [$uid]) : 0,
        'referral_enabled' => referral_enabled(),
    ]),
    ['referral', 'GET'] => api_ok(referral_enabled() ? ['enabled' => true] + referral_overview($user) : ['enabled' => false]),
    ['wishlist', 'GET'] => api_ok(array_map('format_course_card', db_all(
        'SELECT ' . course_card_columns() . " FROM wishlists w JOIN courses c ON c.id = w.course_id AND c.status = 'published'
         JOIN course_categories cat ON cat.id = c.category_id WHERE w.user_id = ? ORDER BY w.created_at DESC", [$uid]
    ))),
    ['wishlist', 'POST'] => toggle_wishlist($uid),
    ['tickets', 'GET'] => api_ok(['categories' => lookup_options('ticket_category'), 'items' => db_all("SELECT id, subject, category, status, priority, created_at, updated_at,
        (SELECT COUNT(*) FROM support_ticket_messages m WHERE m.ticket_id = t.id AND m.is_internal = 0) AS messages
        FROM support_tickets t WHERE user_id = ? ORDER BY updated_at DESC", [$uid])]),
    ['tickets', 'POST'] => create_ticket($uid),
    ['ticket', 'GET'] => ticket($uid),
    ['ticket-reply', 'POST'] => ticket_reply($uid),
    ['documents', 'GET'] => api_ok(['types' => lookup_options('document_type'), 'items' => array_map(fn($d) => [
        'id' => (int) $d['id'], 'title' => $d['title'], 'type' => $d['doc_type'], 'status' => $d['status'], 'note' => $d['note'],
        'uploaded_at' => iso_datetime($d['created_at']), 'file_type' => pathinfo($d['file_path'], PATHINFO_EXTENSION),
    ], db_all('SELECT * FROM student_documents WHERE user_id = ? ORDER BY id DESC', [$uid]))]),
    ['documents', 'POST'] => upload_document($uid),
    ['document', 'GET'] => (function () use ($uid) {
        $d = db_one('SELECT file_path, title FROM student_documents WHERE id = ? AND user_id = ?', [query_int('id', 0), $uid]);
        $path = $d ? private_file_path($d['file_path']) : null;
        $path ? stream_file($path, slugify($d['title']) . '.' . pathinfo($path, PATHINFO_EXTENSION), false) : api_error('Document not found.', 404, 'not_found');
    })(),
    ['document-delete', 'POST'] => (function () use ($uid) {
        $d = db_one("SELECT id, file_path FROM student_documents WHERE id = ? AND user_id = ? AND status <> 'verified'", [(int) (request_body()['id'] ?? 0), $uid]);
        if (!$d) {
            api_error('Only documents that are not yet verified can be removed.', 409, 'locked');
        }
        db_query('DELETE FROM student_documents WHERE id = ?', [$d['id']]);
        require_once APP_ROOT . '/includes/storage.php';
        storage_delete($d['file_path']);
        api_ok(['deleted' => true]);
    })(),
    ['certificates', 'GET'] => api_ok(db_all('SELECT ce.certificate_no, ce.issued_at, c.title, c.slug FROM certificates ce JOIN courses c ON c.id = ce.course_id WHERE ce.user_id = ? ORDER BY ce.issued_at DESC', [$uid])),
    ['certificate', 'GET'] => certificate($user),
    ['export', 'GET'] => export_data($user),
    ['dashboard', 'GET'] => dashboard($user),
    ['courses', 'GET'] => api_ok(my_courses($uid)),
    ['profile', 'GET'] => api_ok(profile($user)),
    ['profile', 'POST'] => update_profile($user),
    ['sessions', 'GET'] => sessions($uid),
    ['revoke-session', 'POST'] => revoke_session($uid),
    ['notifications', 'GET'] => notifications($uid),
    ['notifications-read', 'POST'] => notifications_read($uid),
    ['preferences', 'POST'] => update_preferences($user),
    ['purchases', 'GET'] => purchases($uid),
    ['delete-request', 'POST'] => delete_request($user),
    ['enrolment', 'GET'] => enrolment_detail($user),
    ['review', 'POST'] => submit_review($uid),
    default => api_error('Unknown action.', 404, 'not_found'),
};

/**
 * Enrolled courses. Drafts are left out (the player cannot open them); archived courses (finished
 * batches) stay listed while the student's access is still active.
 */
function my_courses(int $uid): array
{
    $rows = db_all(
        'SELECT ' . course_card_columns() . ",
                e.id AS enrolment_id, e.status AS enrol_status, e.progress_percent, e.enrolled_at, e.expires_at, e.completed_at,
                (SELECT l.id FROM lessons l
                   LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = e.user_id
                  WHERE l.course_id = c.id AND l.status = 'published' AND (lp.status IS NULL OR lp.status <> 'completed')
                  ORDER BY (SELECT s.sort_order FROM course_sections s WHERE s.id = l.section_id), l.sort_order, l.id LIMIT 1) AS next_lesson_id
         FROM course_enrolments e
         JOIN courses c ON c.id = e.course_id
         JOIN course_categories cat ON cat.id = c.category_id
         WHERE e.user_id = ? AND e.status <> 'revoked'
           AND (c.status = 'published'
                OR (c.status = 'archived' AND e.status = 'active' AND (e.expires_at IS NULL OR e.expires_at > NOW())))
         ORDER BY e.enrolled_at DESC",
        [$uid]
    );
    return array_map(function ($r) {
        $expired = $r['enrol_status'] === 'expired' || ($r['expires_at'] && strtotime($r['expires_at']) <= time());
        return format_course_card($r) + [
            'enrolment' => [
                'status' => $expired ? 'expired' : 'active',
                'progress' => (float) $r['progress_percent'],
                'enrolled_at' => iso_datetime($r['enrolled_at']),
                'expires_at' => iso_datetime($r['expires_at']),
                'completed_at' => iso_datetime($r['completed_at']),
                'next_lesson_id' => $r['next_lesson_id'] ? (int) $r['next_lesson_id'] : null,
                'reference' => enrolment_reference((int) $r['enrolment_id']),
            ],
        ];
    }, $rows);
}

function dashboard(array $user): never
{
    $uid = (int) $user['id'];
    $courses = my_courses($uid);
    $courseIds = accessible_course_ids($uid);

    // Live classes the student can attend: public/registered ones + classes of enrolled courses
    $params = [];
    $courseSql = '';
    if ($courseIds) {
        $courseSql = ' OR lc.course_id IN (' . db_placeholders(count($courseIds)) . ')';
        $params = $courseIds;
    }
    $live = array_map('format_live_class', db_all(
        'SELECT ' . LIVE_CLASS_COLUMNS . ' FROM live_classes lc ' . LIVE_CLASS_JOINS . "
         WHERE lc.is_published = 1 AND lc.status = 'scheduled'
           AND DATE_ADD(lc.starts_at, INTERVAL lc.duration_minutes MINUTE) > NOW()
           AND (lc.access_type IN ('public','registered'){$courseSql})
         ORDER BY lc.starts_at LIMIT 4",
        $params
    ));

    $attempts = db_all(
        "SELECT a.id, a.status, a.score, a.correct_count, a.wrong_count, a.submitted_at, e.title, e.slug, e.total_marks,
                e.mode, e.result_release, e.ends_at, e.results_released_at
         FROM exam_attempts a JOIN exams e ON e.id = a.exam_id
         WHERE a.user_id = ? AND a.status <> 'in_progress'
         ORDER BY a.submitted_at DESC LIMIT 5",
        [$uid]
    );
    require_once APP_ROOT . '/includes/exams.php'; // exam_scores_visible(): practice quizzes always, exams per result_release

    api_ok([
        'user' => public_user($user),
        'stats' => [
            'courses' => count(array_filter($courses, fn($c) => $c['enrolment']['status'] === 'active')),
            'completed' => count(array_filter($courses, fn($c) => $c['enrolment']['completed_at'] !== null)),
            'tests_taken' => (int) db_value("SELECT COUNT(*) FROM exam_attempts WHERE user_id = ? AND status <> 'in_progress'", [$uid]),
            'unread_notifications' => (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$uid]),
        ],
        'courses' => array_slice($courses, 0, 4),
        'live_classes' => $live,
        'recent_results' => array_map(function ($a) {
            $visible = exam_scores_visible($a);
            return [
                'attempt_id' => (int) $a['id'],
                'title' => $a['title'],
                'slug' => $a['slug'],
                'mode' => $a['mode'],
                'submitted_at' => iso_datetime($a['submitted_at']),
                // Unreleased results stay hidden: score and answer counts are null until visible.
                'score' => $visible && $a['score'] !== null ? (float) $a['score'] : null,
                'correct_count' => $visible && $a['correct_count'] !== null ? (int) $a['correct_count'] : null,
                'wrong_count' => $visible && $a['wrong_count'] !== null ? (int) $a['wrong_count'] : null,
                'results_visible' => $visible,
                'total_marks' => (float) $a['total_marks'],
            ];
        }, $attempts),
        'notifications' => array_map('format_notification', db_all(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5', [$uid]
        )),
        'certificates' => db_all(
            'SELECT ce.certificate_no, ce.issued_at, c.title FROM certificates ce JOIN courses c ON c.id = ce.course_id WHERE ce.user_id = ? ORDER BY ce.issued_at DESC',
            [$uid]
        ),
    ]);
}

function format_notification(array $n): array
{
    return [
        'id' => (int) $n['id'],
        'type' => $n['type'],
        'title' => $n['title'],
        'message' => $n['message'],
        'link' => $n['link'],
        'read' => $n['read_at'] !== null,
        'created_at' => iso_datetime($n['created_at']),
    ];
}

function upload_document(int $uid): never
{
    if (!rate_limit('doc-upload', (string) $uid, 5, 3600)) {
        api_error('Too many uploads. Please try again later.', 429, 'rate_limited');
    }
    $d = validate_or_fail($_POST, [
        'doc_type' => 'required|in:' . implode(',', array_keys(lookup_options('document_type'))),
        'title' => 'required|string|min:2|max:160',
    ]);
    if ((int) db_value('SELECT COUNT(*) FROM student_documents WHERE user_id = ?', [$uid]) >= 20) {
        api_error('You can store up to 20 documents. Remove an old one first.', 409, 'limit');
    }
    try {
        $stored = store_private_upload($_FILES['file'] ?? [], 'documents', 5 * 1_048_576, ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png']);
    } catch (InvalidArgumentException $e) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['file' => $e->getMessage()]);
    }
    db_query('INSERT INTO student_documents (user_id, doc_type, title, file_path) VALUES (?, ?, ?, ?)', [$uid, $d['doc_type'], $d['title'], $stored['path']]);
    api_ok(['id' => (int) db()->lastInsertId()], 201);
}

function toggle_wishlist(int $uid): never
{
    $in = request_body();
    $courseId = db_value("SELECT id FROM courses WHERE slug = ? AND status = 'published'", [(string) ($in['slug'] ?? '')]);
    if (!$courseId) {
        api_error('Course not found.', 404, 'not_found');
    }
    if (!empty($in['on'])) {
        db_query('INSERT IGNORE INTO wishlists (user_id, course_id) VALUES (?, ?)', [$uid, $courseId]);
    } else {
        db_query('DELETE FROM wishlists WHERE user_id = ? AND course_id = ?', [$uid, $courseId]);
    }
    api_ok(['saved' => !empty($in['on'])]);
}

function create_ticket(int $uid): never
{
    if (!rate_limit('ticket', (string) $uid, 5, 3600)) {
        api_error('You have opened several tickets recently. Please wait for a reply.', 429, 'rate_limited');
    }
    $d = validate_or_fail(request_body(), [
        'subject' => 'required|string|min:4|max:180',
        'category' => 'required|in:' . implode(',', array_keys(lookup_options('ticket_category'))),
        'message' => 'required|string|min:10|max:5000',
    ]);
    // Admin → Support Settings: default assignee (must be an active staff member)
    $assignee = (int) setting('support_default_assignee', '0');
    $assignee = $assignee && db_value("SELECT 1 FROM users WHERE id = ? AND user_type = 'staff' AND status = 'active'", [$assignee]) ? $assignee : null;
    $id = db_transaction(function () use ($uid, $d, $assignee) {
        db_query('INSERT INTO support_tickets (user_id, subject, category, assigned_to) VALUES (?, ?, ?, ?)', [$uid, $d['subject'], $d['category'], $assignee]);
        $id = (int) db()->lastInsertId();
        db_query('INSERT INTO support_ticket_messages (ticket_id, user_id, message) VALUES (?, ?, ?)', [$id, $uid, $d['message']]);
        return $id;
    });
    require_once APP_ROOT . '/includes/notify.php';
    notify_staff_email(setting('support_notify_email'), "New support ticket #{$id}: {$d['subject']}", $d['message'], 'support.php?id=' . $id);
    api_ok(['id' => $id], 201);
}

function own_ticket(int $uid, int $id): array
{
    $t = db_one('SELECT * FROM support_tickets WHERE id = ? AND user_id = ?', [$id, $uid]);
    if (!$t) {
        api_error('Ticket not found.', 404, 'not_found');
    }
    return $t;
}

function ticket(int $uid): never
{
    $t = own_ticket($uid, query_int('id', 0));
    $msgs = db_all("SELECT m.message, m.created_at, u.user_type, u.name FROM support_ticket_messages m LEFT JOIN users u ON u.id = m.user_id
        WHERE m.ticket_id = ? AND m.is_internal = 0 ORDER BY m.id", [$t['id']]);
    api_ok(['ticket' => array_intersect_key($t, array_flip(['id', 'subject', 'category', 'status', 'created_at'])), 'messages' => array_map(fn($m) => [
        'message' => $m['message'], 'created_at' => iso_datetime($m['created_at']), 'from_staff' => $m['user_type'] === 'staff', 'name' => $m['user_type'] === 'staff' ? 'Laxyam Support' : $m['name'],
    ], $msgs)]);
}

function ticket_reply(int $uid): never
{
    $in = request_body();
    $t = own_ticket($uid, (int) ($in['id'] ?? 0));
    if ($t['status'] === 'closed') {
        api_error('This ticket is closed. Please open a new one.', 409, 'closed');
    }
    $d = validate_or_fail($in, ['message' => 'required|string|min:2|max:5000']);
    db_query('INSERT INTO support_ticket_messages (ticket_id, user_id, message) VALUES (?, ?, ?)', [$t['id'], $uid, $d['message']]);
    db_query("UPDATE support_tickets SET status = 'open', updated_at = NOW() WHERE id = ?", [$t['id']]);
    api_ok(['sent' => true]);
}

/** Printable certificate (HTML) for the owner only. */
function certificate(array $user): never
{
    $c = db_one('SELECT ce.certificate_no, ce.issued_at, c.title FROM certificates ce JOIN courses c ON c.id = ce.course_id WHERE ce.certificate_no = ? AND ce.user_id = ?', [query_str('no', 40), $user['id']]);
    if (!$c) {
        api_error('Certificate not found.', 404, 'not_found');
    }
    header('Content-Type: text/html; charset=utf-8');
    // Static page: no scripts, frames, forms or plugins; images only from this site.
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src " . rtrim(app_url(), '/') . "; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    $site = e(setting('site_name', 'Laxyam Academy'));
    $issuer = $site . ' · ' . e(setting('company_legal_name', 'LAXYAM INNOVATIVE EDUSKIL PRIVATE LIMITED'))
        . ' · CIN ' . e(setting('company_cin', 'U85491DL2026PTC470370'));
    $verifyUrl = certificate_verify_url((string) $c['certificate_no']);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Certificate ' . e($c['certificate_no']) . '</title>
<style>@page{size:A4 landscape;margin:0}body{margin:0;font-family:Georgia,serif;background:#eef3fb}.c{width:1000px;max-width:96vw;margin:30px auto;background:#fff;border:14px solid #08264D;outline:3px solid #FFC928;outline-offset:-26px;padding:60px;text-align:center;box-sizing:border-box}
h1{font-size:44px;color:#08264D;margin:10px 0}.n{font-size:36px;color:#0B63F6;margin:18px 0;font-style:italic}p{color:#14233B;font-size:18px}.m{display:flex;justify-content:space-between;gap:12px;margin-top:50px;font-size:14px;color:#64748B}
.i{margin:28px 0 0;font-size:13px;letter-spacing:.02em;color:#08264D}.v{margin:6px 0 0;font-size:12px;color:#64748B;word-break:break-all}.v a{color:#0B63F6;text-decoration:none}
img{height:70px}@media print{body{background:#fff}.c{margin:0 auto}}</style>
<div class="c"><img src="' . e(app_url('assets/logo.png')) . '" alt="' . $site . '"><h1>Certificate of Completion</h1><p>This is to certify that</p><div class="n">' . e($user['name']) . '</div>
<p>has successfully completed the course</p><h2 style="color:#08264D">' . e($c['title']) . '</h2>
<div class="m"><span>Certificate No: ' . e($c['certificate_no']) . '</span><span>Issued: ' . e(date('j F Y', strtotime($c['issued_at']))) . '</span><span>' . $site . '</span></div>
<p class="i">Issued by ' . $issuer . '</p>
<p class="v">Verify certificate ' . e($c['certificate_no']) . ' at <a href="' . e($verifyUrl) . '">' . e($verifyUrl) . '</a></p></div></html>';
    exit;
}

/** Absolute public verification URL printed on a certificate. */
function certificate_verify_url(string $certificateNo): string
{
    return app_url('api/students.php?action=verify-certificate&no=' . rawurlencode($certificateNo));
}

/**
 * Public certificate check (no login). Discloses only what the printed certificate already shows,
 * with the holder's name reduced to first name + last initial; nothing else about the student.
 * Rate limited per IP so certificate numbers cannot be enumerated.
 */
function verify_certificate(): never
{
    // The printed URL is opened in browsers (HTML page); the SPA's fetch asks for JSON.
    $json = verify_wants_json();
    header('Vary: Accept');
    $no = query_str('no', 40);
    if (!rate_limit('certificate-verify', client_ip(), 30, 600)) {
        $json
            ? api_error('Too many verification requests. Please try again in a few minutes.', 429, 'rate_limited')
            : verify_certificate_page(429, 'limited', $no);
    }
    if (!preg_match('/^[A-Za-z0-9-]{4,40}$/', $no)) {
        if (!$json) {
            verify_certificate_page($no === '' ? 200 : 422, $no === '' ? 'prompt' : 'invalid', $no);
        }
        api_error('Please enter a valid certificate number.', 422, 'validation_failed', ['no' => 'Enter the certificate number printed on the certificate.']);
    }
    $c = db_one(
        'SELECT ce.certificate_no, ce.issued_at, c.title, u.name
         FROM certificates ce JOIN courses c ON c.id = ce.course_id JOIN users u ON u.id = ce.user_id
         WHERE ce.certificate_no = ?',
        [$no]
    );
    $result = [
        'valid' => $c !== null,
        'certificate_no' => $c['certificate_no'] ?? $no,
        'student_name' => $c ? short_person_name((string) $c['name']) : null,
        'course' => $c['title'] ?? null,
        'issued_at' => $c ? iso_datetime($c['issued_at']) : null,
        'issued_by' => setting('site_name', 'Laxyam Academy'),
    ];
    $json ? api_ok($result) : verify_certificate_page($c ? 200 : 404, $c ? 'valid' : 'not_found', $no, $result);
}

/** JSON for API callers (Accept: application/json or &format=json); HTML for everything else. */
function verify_wants_json(): bool
{
    $format = strtolower(query_str('format', 10));
    if ($format !== '') {
        return $format === 'json';
    }
    return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

/**
 * Self-contained, branded verification page: inline CSS and SVG only, no scripts (strict CSP),
 * noindex. Every dynamic value is escaped. $state: valid | not_found | invalid | prompt | limited.
 */
function verify_certificate_page(int $status, string $state, string $no, ?array $r = null): never
{
    $site = (string) setting('site_name', 'Laxyam Academy');
    $legal = (string) setting('company_legal_name', 'LAXYAM INNOVATIVE EDUSKIL PRIVATE LIMITED');
    $cin = (string) setting('company_cin', 'U85491DL2026PTC470370');
    $logo = (string) public_file_url(setting('logo_path') ?: 'assets/logo.png');
    $home = app_url();
    $action = app_url('api/students.php');
    // Explicit app origin next to 'self', so the logo and the form also work behind another host name.
    $origin = preg_match('#^(https?://[^/]+)#i', $home, $m) ? ' ' . $m[1] : '';
    $logoOrigin = preg_match('#^(https?://[^/]+)#i', $logo, $m) ? ' ' . $m[1] : '';

    $ok = '<svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true"><path d="M20 6 9 17l-5-5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    $bad = '<svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg>';
    $info = '<svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2.2"/><path d="M12 11v5M12 7.5v.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>';

    [$tone, $icon, $title, $lead] = match ($state) {
        'valid' => ['ok', $ok, 'Certificate verified', 'This is a genuine certificate issued by ' . e($site) . '.'],
        'not_found' => ['bad', $bad, 'Certificate not found', 'No certificate with the number <strong>' . e($no) . '</strong> has been issued by ' . e($site) . '. Check the number printed on the certificate and try again.'],
        'invalid' => ['warn', $info, 'Check the certificate number', 'Certificate numbers use letters, digits and hyphens only (4-40 characters), for example LXA-2026-1A2B3C4D.'],
        'limited' => ['warn', $info, 'Please wait a moment', 'Too many verification requests came from your network. Please try again in a few minutes.'],
        default => ['info', $info, 'Verify a certificate', 'Enter the certificate number printed at the bottom of a ' . e($site) . ' certificate.'],
    };

    $facts = '';
    if ($state === 'valid' && $r) {
        $issued = $r['issued_at'] ? date('j F Y', strtotime((string) $r['issued_at'])) : '';
        foreach (['Certificate No.' => $r['certificate_no'], 'Awarded to' => $r['student_name'], 'Course' => $r['course'], 'Issued on' => $issued, 'Issued by' => $r['issued_by']] as $label => $value) {
            $facts .= '<div><dt>' . e($label) . '</dt><dd>' . e((string) $value) . '</dd></div>';
        }
        $facts = '<dl class="facts">' . $facts . '</dl><p class="note">For privacy, only the holder&rsquo;s first name and last initial are shown.</p>';
    }
    $formValue = $state === 'valid' ? '' : $no;

    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'{$logoOrigin}; form-action 'self'{$origin}; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    if ($status === 429) {
        header('Retry-After: 600');
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . e($title) . ' · ' . e($site) . '</title><style>'
        . '*{box-sizing:border-box}body{margin:0;min-height:100vh;background:#F5F9FF;background-image:radial-gradient(circle at 15% 0,rgba(11,99,246,.10),transparent 45%),radial-gradient(circle at 90% 10%,rgba(255,201,40,.14),transparent 40%);font-family:Inter,"Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;color:#14233B;line-height:1.5}'
        . '.wrap{max-width:560px;margin:0 auto;padding:32px 16px 40px}.brand{display:flex;justify-content:center;margin-bottom:20px}.brand img{display:block;height:52px;max-width:100%;width:auto}'
        . '.card{background:#fff;border:1px solid #E2EAF4;border-radius:20px;box-shadow:0 18px 40px -24px rgba(8,38,77,.35);overflow:hidden}.top{height:6px;background:linear-gradient(90deg,#08264D,#0B63F6 55%,#FFC928)}'
        . '.body{padding:28px 24px 24px;text-align:center}.badge{display:inline-grid;place-items:center;width:64px;height:64px;border-radius:50%;margin-bottom:14px}'
        . '.ok .badge{background:#E7F8EF;color:#0F9D58;box-shadow:0 0 0 8px #F3FCF7}.bad .badge{background:#FEECE7;color:#F04E23;box-shadow:0 0 0 8px #FFF6F3}.warn .badge{background:#FFF7DB;color:#B7791F;box-shadow:0 0 0 8px #FFFCF0}.info .badge{background:#E8F0FE;color:#0B63F6;box-shadow:0 0 0 8px #F4F8FF}'
        . '.eyebrow{margin:0;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#64748B}h1{margin:6px 0 8px;font-family:"Plus Jakarta Sans",Inter,"Segoe UI",system-ui,sans-serif;font-size:26px;line-height:1.2;color:#08264D}'
        . '.lead{margin:0 auto;max-width:420px;color:#475569;font-size:15px}.lead strong{color:#08264D;overflow-wrap:anywhere}'
        . '.facts{margin:22px 0 0;padding:0;text-align:left;border:1px solid #E2EAF4;border-radius:14px;overflow:hidden}.facts div{display:flex;gap:12px;justify-content:space-between;padding:12px 16px;border-top:1px solid #E2EAF4}.facts div:first-child{border-top:0}'
        . '.facts dt{color:#64748B;font-size:14px;flex:none}.facts dd{margin:0;font-weight:600;color:#08264D;text-align:right;word-break:break-word;min-width:0}.note{margin:12px 0 0;font-size:12px;color:#64748B}'
        . 'form{margin-top:18px;background:#fff;border:1px solid #E2EAF4;border-radius:16px;padding:18px}label{display:block;font-weight:600;font-size:14px;color:#08264D;margin-bottom:8px}.row{display:flex;gap:8px}'
        . 'input{flex:1;min-width:0;font:inherit;padding:11px 14px;border:1px solid #CBD5E1;border-radius:12px;color:#14233B;background:#fff}input:focus{outline:3px solid rgba(11,99,246,.25);border-color:#0B63F6}'
        . 'button{font:inherit;font-weight:700;padding:11px 18px;border:0;border-radius:12px;background:#0B63F6;color:#fff;cursor:pointer}button:hover{background:#0A55D4}button:focus-visible,a:focus-visible{outline:3px solid rgba(11,99,246,.35);outline-offset:2px}'
        . 'footer{margin-top:22px;text-align:center;font-size:12px;color:#64748B}footer a{color:#0B63F6;font-weight:600;text-decoration:none}footer a:hover{text-decoration:underline}'
        . '@media (max-width:420px){.body{padding:24px 18px 20px}h1{font-size:22px}.row{flex-direction:column}.facts div{flex-direction:column;gap:2px}.facts dd{text-align:left}}'
        . '</style></head><body><main class="wrap">'
        . '<div class="brand"><a href="' . e($home) . '"><img src="' . e($logo) . '" alt="' . e($site) . '"></a></div>'
        . '<section class="card ' . $tone . '" aria-labelledby="t"><div class="top"></div><div class="body">'
        . '<div class="badge">' . $icon . '</div><p class="eyebrow">Certificate verification</p><h1 id="t">' . e($title) . '</h1>'
        . '<p class="lead">' . $lead . '</p>' . $facts . '</div></section>'
        . '<form method="get" action="' . e($action) . '"><input type="hidden" name="action" value="verify-certificate">'
        . '<label for="no">' . ($state === 'valid' ? 'Verify another certificate' : 'Certificate number') . '</label><div class="row">'
        . '<input id="no" name="no" value="' . e($formValue) . '" required maxlength="40" pattern="[A-Za-z0-9\-]{4,40}" placeholder="LXA-2026-XXXXXXXX" autocomplete="off" spellcheck="false">'
        . '<button type="submit">Verify</button></div></form>'
        . '<footer><p>Certificates are issued by ' . e($site) . ' · ' . e($legal) . ($cin !== '' ? ' · CIN ' . e($cin) : '') . '</p>'
        . '<p><a href="' . e($home) . '">Visit ' . e($site) . '</a></p></footer>'
        . '</main></body></html>';
    exit;
}

/** "Priya Kumari Sharma" → "Priya S." (single names are returned as they are). */
function short_person_name(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) {
        return '';
    }
    $first = $parts[0];
    return count($parts) > 1 ? $first . ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.' : $first;
}

/** Download a copy of the student's personal data (JSON). */
function export_data(array $user): never
{
    $uid = (int) $user['id'];
    require_once APP_ROOT . '/includes/exams.php'; // exam_scores_visible()
    $data = [
        'exported_at' => date(DATE_ATOM),
        'account' => public_user($user),
        'profile' => db_one('SELECT date_of_birth, gender, city, state, exam_preferences, preferred_language, study_goal FROM student_profiles WHERE user_id = ?', [$uid]),
        'enrolments' => db_all('SELECT c.title, e.status, e.progress_percent, e.enrolled_at, e.expires_at FROM course_enrolments e JOIN courses c ON c.id = e.course_id WHERE e.user_id = ?', [$uid]),
        'orders' => db_all('SELECT order_number, status, total, created_at, paid_at FROM orders WHERE user_id = ?', [$uid]),
        // Scores and answer counts of tests whose results are not released yet stay hidden
        // (same rule as the dashboard; practice quizzes are always visible).
        'test_attempts' => array_map(function ($a) {
            $visible = exam_scores_visible($a);
            return [
                'title' => $a['title'],
                'mode' => $a['mode'],
                'status' => $a['status'],
                'score' => $visible && $a['score'] !== null ? (float) $a['score'] : null,
                'correct_count' => $visible && $a['correct_count'] !== null ? (int) $a['correct_count'] : null,
                'wrong_count' => $visible && $a['wrong_count'] !== null ? (int) $a['wrong_count'] : null,
                'result' => $visible ? 'released' : 'not_released',
                'submitted_at' => $a['submitted_at'],
            ];
        }, db_all(
            'SELECT e.title, e.mode, e.result_release, e.ends_at, e.results_released_at,
                    a.status, a.score, a.correct_count, a.wrong_count, a.submitted_at
             FROM exam_attempts a JOIN exams e ON e.id = a.exam_id WHERE a.user_id = ?',
            [$uid]
        )),
        'support_tickets' => db_all('SELECT subject, status, created_at FROM support_tickets WHERE user_id = ?', [$uid]),
    ];
    audit_log('personal_data_exported', 'user', $uid);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="laxyam-my-data-' . date('Ymd') . '.json"');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function profile(array $user): array
{
    $p = db_one('SELECT * FROM student_profiles WHERE user_id = ?', [$user['id']]) ?? [];
    return [
        'user' => public_user($user),
        'profile' => [
            'date_of_birth' => $p['date_of_birth'] ?? null,
            'gender' => $p['gender'] ?? null,
            'city' => $p['city'] ?? null,
            'state' => $p['state'] ?? null,
            'exam_preferences' => json_decode_array($p['exam_preferences'] ?? null),
            'notify_email' => (bool) ($p['notify_email'] ?? 1),
            'notify_sms' => (bool) ($p['notify_sms'] ?? 0),
            'notify_class_reminders' => (bool) ($p['notify_class_reminders'] ?? 1),
            'preferred_language' => $p['preferred_language'] ?? 'english',
            'study_goal' => $p['study_goal'] ?? null,
            'notify_prefs' => array_merge(NOTIFY_DEFAULTS, array_map('boolval', json_decode_array($p['notify_prefs'] ?? null))),
        ] + student_whatsapp_profile((int) $user['id']),
        'stats' => [
            'courses' => (int) db_value("SELECT COUNT(*) FROM course_enrolments WHERE user_id = ? AND status = 'active'", [$user['id']]),
            'certificates' => (int) db_value('SELECT COUNT(*) FROM certificates WHERE user_id = ?', [$user['id']]),
            'tests' => (int) db_value("SELECT COUNT(*) FROM exam_attempts WHERE user_id = ? AND status <> 'in_progress'", [$user['id']]),
        ],
        'deletion_requested_at' => iso_datetime(db_value('SELECT deletion_requested_at FROM users WHERE id = ?', [$user['id']])),
        'categories' => array_map(fn($c) => ['slug' => $c['slug'], 'name' => $c['name']], fetch_course_categories()),
    ];
}

/**
 * WhatsApp opt-in for the profile payload: whatsapp_opt_in (for the CURRENT mobile number),
 * whatsapp_opt_in_at (ISO), whatsapp_number_changed (opted in for an older number - must confirm again).
 */
function student_whatsapp_profile(int $uid): array
{
    $out = ['whatsapp_opt_in' => false, 'whatsapp_opt_in_at' => null, 'whatsapp_number_changed' => false];
    if (!is_file(APP_ROOT . '/includes/whatsapp.php')) {
        return $out;
    }
    require_once APP_ROOT . '/includes/whatsapp.php';
    $w = whatsapp_user_opt_in($uid);
    return ['whatsapp_opt_in' => $w['opt_in'], 'whatsapp_opt_in_at' => $w['opt_in'] ? iso_datetime($w['at']) : null, 'whatsapp_number_changed' => $w['number_changed']];
}

function update_profile(array $user): never
{
    $in = request_body();
    // Phone is optional (accounts from admin imports or social sign-in may have none): validated only
    // when sent non-empty, cleared when sent empty, and left as stored when the key is omitted.
    $d = validate_or_fail($in, [
        'name' => 'required|string|min:2|max:120',
        'phone' => 'phone',
        'date_of_birth' => 'string|max:10',
        'gender' => 'in:male,female,other,undisclosed',
        'city' => 'string|max:100',
        'state' => 'string|max:100',
        'preferred_language' => 'in:english,hindi',
        'study_goal' => 'string|max:60',
    ]);
    // Notification settings are only changed when their keys are sent: a partial update (e.g. just
    // name/phone from another client) must never silently unsubscribe the student. Omitted
    // notify_* columns and omitted notify_prefs keys keep their stored values (or the defaults).
    $stored = db_one('SELECT notify_email, notify_sms, notify_class_reminders, notify_prefs FROM student_profiles WHERE user_id = ?', [$user['id']]) ?: [];
    $notify = [];
    foreach (['notify_email' => 1, 'notify_sms' => 0, 'notify_class_reminders' => 1] as $col => $default) {
        $notify[$col] = array_key_exists($col, $in)
            ? (is_scalar($in[$col]) && filter_var($in[$col], FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
            : (int) ($stored[$col] ?? $default);
    }
    $prefsIn = is_array($in['notify_prefs'] ?? null) ? $in['notify_prefs'] : [];
    $storedPrefs = array_map('boolval', array_intersect_key(json_decode_array($stored['notify_prefs'] ?? null), NOTIFY_DEFAULTS));
    $notifyPrefs = [];
    foreach (NOTIFY_DEFAULTS as $k => $default) {
        $notifyPrefs[$k] = array_key_exists($k, $prefsIn) ? (bool) $prefsIn[$k] : ($storedPrefs[$k] ?? $default);
    }
    if ($d['date_of_birth'] && !DateTime::createFromFormat('Y-m-d', $d['date_of_birth'])) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['date_of_birth' => 'Use a valid date.']);
    }
    $valid = array_column(fetch_course_categories(), 'slug');
    $prefs = array_values(array_intersect(is_array($in['exam_preferences'] ?? null) ? $in['exam_preferences'] : [], $valid));

    $phone = array_key_exists('phone', $in) ? $d['phone'] : db_value('SELECT phone FROM users WHERE id = ?', [$user['id']]);

    db_transaction(function () use ($user, $d, $phone, $prefs, $notify, $notifyPrefs) {
        $last10 = fn($p) => substr(preg_replace('/\D/', '', (string) $p), -10);
        $oldPhone = db_value('SELECT phone FROM users WHERE id = ?', [$user['id']]);
        db_query('UPDATE users SET name = ?, phone = ? WHERE id = ?', [$d['name'], $phone, $user['id']]);
        if ($last10($oldPhone) !== $last10($phone) && db_value("SHOW COLUMNS FROM users LIKE 'phone_verified_at'")) {
            // A different number must be confirmed by OTP again before it can be used to log in.
            db_query('UPDATE users SET phone_verified_at = NULL WHERE id = ?', [$user['id']]);
        }
        db_query(
            'INSERT INTO student_profiles (user_id, date_of_birth, gender, city, state, exam_preferences, preferred_language, study_goal, notify_email, notify_sms, notify_class_reminders, notify_prefs)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE date_of_birth = VALUES(date_of_birth), gender = VALUES(gender), city = VALUES(city),
                state = VALUES(state), exam_preferences = VALUES(exam_preferences), preferred_language = VALUES(preferred_language),
                study_goal = VALUES(study_goal), notify_email = VALUES(notify_email), notify_sms = VALUES(notify_sms),
                notify_class_reminders = VALUES(notify_class_reminders), notify_prefs = VALUES(notify_prefs)',
            [
                $user['id'], $d['date_of_birth'], $d['gender'], $d['city'], $d['state'], json_encode($prefs),
                $d['preferred_language'] ?? 'english', $d['study_goal'],
                $notify['notify_email'], $notify['notify_sms'], $notify['notify_class_reminders'],
                json_encode($notifyPrefs),
            ]
        );
    });
    $fresh = db_one('SELECT id, name, email, phone, user_type, status, avatar_path, email_verified_at, created_at FROM users WHERE id = ?', [$user['id']]);
    api_ok(profile($fresh));
}

function sessions(int $uid): never
{
    $current = hash('sha256', session_id());
    $rows = db_all(
        'SELECT id, session_hash, ip_address, user_agent, created_at, last_seen_at FROM user_sessions
         WHERE user_id = ? AND revoked_at IS NULL AND last_seen_at > (NOW() - INTERVAL 30 DAY)
         ORDER BY last_seen_at DESC LIMIT 20',
        [$uid]
    );
    api_ok(array_map(fn($s) => [
        'id' => (int) $s['id'],
        'current' => hash_equals($s['session_hash'], $current),
        'ip_address' => $s['ip_address'],
        'device' => $s['user_agent'],
        'created_at' => iso_datetime($s['created_at']),
        'last_seen_at' => iso_datetime($s['last_seen_at']),
    ], $rows));
}

function revoke_session(int $uid): never
{
    $id = filter_var(request_body()['id'] ?? null, FILTER_VALIDATE_INT);
    db_query('UPDATE user_sessions SET revoked_at = NOW() WHERE id = ? AND user_id = ? AND revoked_at IS NULL AND session_hash <> ?',
        [$id ?: 0, $uid, hash('sha256', session_id())]);
    api_ok(['revoked' => true]);
}

/**
 * Notification type groups for the inbox filter; "other" is every type not listed here.
 * 'reminder' is what live classes write (reminder set / rescheduled / cancelled - api/live-classes.php,
 * admin/live-classes.php), so it sits with the template-based 'class_reminder' under learning.
 * Announcements, document reviews and support replies stay in "other": the inbox chips are an
 * allowlist in js/account/ui.jsx (NOTIFICATION_GROUPS), so a new group key here would hide those
 * types from every chip but "All" until the frontend lists it too.
 */
function notification_groups(): array
{
    return [
        'payments' => ['payment_success', 'payment_failed', 'refund_processed'],
        'learning' => ['enrolment', 'course_completed', 'certificate_issued', 'class_reminder', 'reminder'],
        'tests' => ['test_result'],
        'account' => ['welcome', 'password_changed'],
    ];
}

/** Group key ("other" when unlisted) of one notification type. */
function notification_group_of(string $type): string
{
    foreach (notification_groups() as $group => $types) {
        if (in_array($type, $types, true)) {
            return $group;
        }
    }
    return 'other';
}

function notifications(int $uid): never
{
    $page = query_int('page', 1, 1, 500);
    $where = 'user_id = ?';
    $params = [$uid];

    // Optional unread-only filter (&unread=1), combinable with the type filter.
    $unreadOnly = filter_var($_GET['unread'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($unreadOnly) {
        $where .= ' AND read_at IS NULL';
    }

    // Optional type filter: a group name, "other", or one exact notification type.
    $type = query_str('type', 40);
    $type = $type === 'all' ? '' : $type;
    if ($type !== '') {
        $groups = notification_groups();
        if ($type === 'other') {
            $known = array_merge(...array_values($groups));
            $where .= ' AND type NOT IN (' . db_placeholders(count($known)) . ')';
            array_push($params, ...$known);
        } elseif (isset($groups[$type])) {
            $where .= ' AND type IN (' . db_placeholders(count($groups[$type])) . ')';
            array_push($params, ...$groups[$type]);
        } elseif (preg_match('/^[a-z0-9_]{2,40}$/', $type)) {
            $where .= ' AND type = ?';
            $params[] = $type;
        } else {
            api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['type' => 'Unknown notification type.']);
        }
    }

    $total = (int) db_value("SELECT COUNT(*) FROM notifications WHERE {$where}", $params);
    $rows = db_all("SELECT * FROM notifications WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET " . (($page - 1) * 20), $params);

    // Per-group totals for the filter chips (independent of the type/unread filters above).
    $groups = notification_groups();
    $counts = array_fill_keys([...array_keys($groups), 'other'], 0);
    $unreadCounts = $counts;
    foreach (db_all('SELECT type, COUNT(*) AS total, SUM(read_at IS NULL) AS unread FROM notifications WHERE user_id = ? GROUP BY type', [$uid]) as $r) {
        $g = notification_group_of((string) $r['type']);
        $counts[$g] += (int) $r['total'];
        $unreadCounts[$g] += (int) $r['unread'];
    }

    api_ok([
        'items' => array_map('format_notification', $rows),
        'pagination' => pagination_meta($total, $page, 20),
        'type' => $type !== '' ? $type : null, // the filter that was applied
        'unread' => $unreadOnly,                 // true when only unread notifications were listed
        'groups' => $groups,
        'counts' => $counts,                     // {payments, learning, tests, account, other}: all notifications
        'unread_counts' => $unreadCounts,        // same keys, unread only
    ]);
}

/**
 * Lightweight save of the notification settings only: notify_email, notify_class_reminders and
 * notify_prefs (course_updates, test_results, offers, announcements, study_tips). Each field is
 * optional; omitted ones (and omitted notify_prefs keys) keep their stored values, and no other
 * profile column is touched. Returns the same payload as GET action=profile.
 */
function update_preferences(array $user): never
{
    $in = request_body();
    $uid = (int) $user['id'];
    $errors = [];
    $set = [];
    $toBool = static fn($v): ?bool => is_scalar($v) ? filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;

    foreach (['notify_email', 'notify_class_reminders'] as $col) {
        if (!array_key_exists($col, $in)) {
            continue;
        }
        $v = $toBool($in[$col]);
        if ($v === null) {
            $errors[$col] = 'Must be true or false.';
        } else {
            $set[$col] = $v ? 1 : 0;
        }
    }

    if (array_key_exists('notify_prefs', $in)) {
        $prefsIn = $in['notify_prefs'];
        if (!is_array($prefsIn) || ($prefsIn && array_is_list($prefsIn))) {
            $errors['notify_prefs'] = 'Send an object of preference: true/false pairs.';
        } else {
            $stored = json_decode_array(db_value('SELECT notify_prefs FROM student_profiles WHERE user_id = ?', [$uid]));
            $prefs = array_merge(NOTIFY_DEFAULTS, array_map('boolval', array_intersect_key($stored, NOTIFY_DEFAULTS)));
            foreach ($prefsIn as $k => $v) {
                $b = $toBool($v);
                if (!array_key_exists((string) $k, NOTIFY_DEFAULTS)) {
                    $errors['notify_prefs'] = 'Unknown preference "' . mb_substr((string) $k, 0, 40) . '".';
                } elseif ($b === null) {
                    $errors['notify_prefs'] = 'Preference "' . $k . '" must be true or false.';
                } else {
                    $prefs[(string) $k] = $b;
                }
            }
            $set['notify_prefs'] = json_encode($prefs);
        }
    }

    // WhatsApp updates: the student's own consent, stored with the number it was given for.
    $whatsapp = null;
    if (array_key_exists('whatsapp_opt_in', $in)) {
        $whatsapp = $toBool($in['whatsapp_opt_in']);
        if ($whatsapp === null) {
            $errors['whatsapp_opt_in'] = 'Must be true or false.';
        } elseif (!is_file(APP_ROOT . '/includes/whatsapp.php')) {
            $errors['whatsapp_opt_in'] = 'WhatsApp updates are not available yet.';
        } else {
            require_once APP_ROOT . '/includes/whatsapp.php';
            if (!whatsapp_optin_ready()) {
                $errors['whatsapp_opt_in'] = 'WhatsApp updates are not available yet.';
            } elseif ($whatsapp && !whatsapp_normalize_phone((string) db_value('SELECT phone FROM users WHERE id = ?', [$uid]))) {
                $errors['whatsapp_opt_in'] = 'Add a valid mobile number to your profile first.';
            }
        }
    }

    if ($errors) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', $errors);
    }
    if ($whatsapp !== null) {
        whatsapp_set_opt_in($uid, $whatsapp, 'profile');
        audit_log($whatsapp ? 'whatsapp_opt_in' : 'whatsapp_opt_out', 'user', $uid);
        if (!$set) {
            api_ok(profile($user));
        }
    }
    if (!$set) {
        api_error('Nothing to update.', 422, 'validation_failed', ['notify_prefs' => 'Send notify_email, notify_class_reminders, notify_prefs or whatsapp_opt_in.']);
    }

    // Create the profile row on first save (column defaults for everything else), then
    // update only the columns that were sent.
    db_query('INSERT IGNORE INTO student_profiles (user_id) VALUES (?)', [$uid]);
    $cols = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($set)));
    db_query("UPDATE student_profiles SET {$cols} WHERE user_id = ?", [...array_values($set), $uid]);
    api_ok(profile($user));
}

function notifications_read(int $uid): never
{
    $id = filter_var(request_body()['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id) {
        db_query('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL', [$id, $uid]);
    } else {
        db_query('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$uid]);
    }
    api_ok(['ok' => true]);
}

function purchases(int $uid): never
{
    $orders = db_all(
        "SELECT o.id, o.order_number, o.status, o.subtotal, o.discount, o.total, o.currency, o.created_at, o.paid_at,
                r.receipt_number
         FROM orders o LEFT JOIN receipts r ON r.order_id = o.id
         WHERE o.user_id = ? AND (o.status <> 'pending' OR o.created_at > NOW() - INTERVAL 1 DAY)
         ORDER BY o.created_at DESC",
        [$uid]
    );
    $items = $orders ? db_all(
        'SELECT oi.order_id, oi.title, oi.total, c.slug FROM order_items oi JOIN courses c ON c.id = oi.course_id WHERE oi.order_id IN (' . db_placeholders(count($orders)) . ')',
        array_column($orders, 'id')
    ) : [];
    $byOrder = [];
    foreach ($items as $i) {
        $byOrder[(int) $i['order_id']][] = ['title' => $i['title'], 'slug' => $i['slug'], 'total' => (float) $i['total']];
    }
    api_ok(array_map(fn($o) => [
        'order_number' => $o['order_number'],
        'status' => $o['status'],
        'subtotal' => (float) $o['subtotal'],
        'discount' => (float) $o['discount'],
        'total' => (float) $o['total'],
        'currency' => $o['currency'],
        'created_at' => iso_datetime($o['created_at']),
        'paid_at' => iso_datetime($o['paid_at']),
        'receipt_number' => $o['receipt_number'],
        'invoice' => order_invoice((int) $o['id'], (string) $o['status']),
        'refunded_amount' => order_refunded_amount((int) $o['id'], (string) $o['status']),
        'items' => $byOrder[(int) $o['id']] ?? [],
    ], $orders));
}

/**
 * Sum of PROCESSED refunds for an order (0.0 when none). Uses commerce's invoice_refunds()
 * from includes/invoice.php when available, otherwise reads the refunds table directly.
 */
function order_refunded_amount(int $orderId, string $status): float
{
    static $helper = null;
    if ($helper === null) {
        $file = APP_ROOT . '/includes/invoice.php';
        if (is_file($file)) {
            require_once $file;
        }
        $helper = function_exists('invoice_refunds');
    }
    if (in_array($status, ['pending', 'failed', 'cancelled'], true)) {
        return 0.0; // nothing was charged, so nothing can have been refunded
    }
    if ($helper) {
        return round((float) (invoice_refunds($orderId)['amount'] ?? 0), 2);
    }
    return round((float) db_value("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE order_id = ? AND status = 'processed'", [$orderId]), 2);
}

/**
 * {invoice_number, url} for a paid order via commerce's invoice_for_order() (includes/invoice.php),
 * or null when that helper is not installed / the order has no invoice.
 */
function order_invoice(int $orderId, string $status): ?array
{
    static $available = null;
    if ($available === null) {
        $file = APP_ROOT . '/includes/invoice.php';
        if (is_file($file)) {
            require_once $file;
        }
        $available = function_exists('invoice_for_order');
    }
    if (!$available || in_array($status, ['pending', 'failed', 'cancelled'], true)) {
        return null;
    }
    $inv = invoice_for_order($orderId);
    return is_array($inv) && !empty($inv['invoice_number'])
        ? ['invoice_number' => (string) $inv['invoice_number'], 'url' => (string) ($inv['url'] ?? '')]
        : null;
}

/** Congratulations-page data for one of the student's enrolments (free or paid). */
function enrolment_detail(array $user): never
{
    $uid = (int) $user['id'];
    $slug = query_str('course', 200);
    $row = preg_match('/^[a-z0-9-]{1,200}$/', $slug) ? db_one(
        "SELECT e.id, e.status, e.source, e.order_id, e.progress_percent, e.enrolled_at, e.expires_at, e.completed_at,
                c.id AS course_id, c.title, c.slug, c.thumbnail_path, c.validity_days, c.status AS course_status,
                cat.slug AS category_slug, cat.name AS category_name, cat.color AS category_color, cat.icon AS category_icon
         FROM course_enrolments e
         JOIN courses c ON c.id = e.course_id
         JOIN course_categories cat ON cat.id = c.category_id
         WHERE e.user_id = ? AND c.slug = ? AND e.status <> 'revoked'",
        [$uid, $slug]
    ) : null;
    if (!$row) {
        api_error('You are not enrolled in this course.', 404, 'not_enrolled');
    }
    $active = active_enrolment($uid, (int) $row['course_id']) !== null;
    // Archived = finished batch: still open while this student's access lasts.
    $open = $row['course_status'] === 'published' || ($active && in_array($row['course_status'], COURSE_OPEN_TO_ENROLLED, true));

    // The order that granted access: the one named in the URL (payment_success link), else the
    // enrolment's own order, else the latest paid order of this student containing the course.
    $orderNumber = query_str('order', 40);
    $order = null;
    if ($orderNumber !== '' && preg_match('/^[A-Za-z0-9-]{4,40}$/', $orderNumber)) {
        $order = db_one(
            'SELECT o.id, o.order_number, o.status, o.total, o.currency, o.paid_at FROM orders o
             JOIN order_items oi ON oi.order_id = o.id AND oi.course_id = ?
             WHERE o.order_number = ? AND o.user_id = ?',
            [$row['course_id'], $orderNumber, $uid]
        );
    }
    if (!$order) {
        $order = db_one(
            "SELECT o.id, o.order_number, o.status, o.total, o.currency, o.paid_at FROM orders o
             JOIN order_items oi ON oi.order_id = o.id AND oi.course_id = ?
             WHERE o.user_id = ? AND (o.id = ? OR o.status IN ('paid', 'partially_refunded'))
             ORDER BY (o.id = ?) DESC, o.paid_at DESC LIMIT 1",
            [$row['course_id'], $uid, (int) $row['order_id'], (int) $row['order_id']]
        );
    }
    $invoice = $order ? order_invoice((int) $order['id'], (string) $order['status']) : null;

    // Delivery state of the confirmation email (email_log is written by the mail queue).
    $mail = db_one(
        "SELECT status FROM email_log WHERE user_id = ? AND template_key IN ('enrolment', 'payment_success')
           AND created_at >= (? - INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 1",
        [$uid, $row['enrolled_at']]
    );

    api_ok([
        'course' => [
            'title' => $row['title'],
            'slug' => $row['slug'],
            'thumbnail_url' => public_file_url($row['thumbnail_path']),
            'category' => ['slug' => $row['category_slug'], 'name' => $row['category_name'], 'color' => $row['category_color'], 'icon' => $row['category_icon']],
            'validity_days' => $row['validity_days'] !== null ? (int) $row['validity_days'] : null,
            'share_url' => course_share_url($row['slug']),
            'available' => $open,
        ],
        'reference' => enrolment_reference((int) $row['id']),
        'status' => $active ? 'active' : 'expired',
        'source' => $row['source'], // free | purchase | admin
        'enrolled_at' => iso_datetime($row['enrolled_at']),
        'expires_at' => iso_datetime($row['expires_at']),
        'completed_at' => iso_datetime($row['completed_at']),
        'next_lesson_id' => $active && $open ? course_next_lesson_id($uid, (int) $row['course_id']) : null,
        'progress' => (float) $row['progress_percent'],
        'order' => $order ? [
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'total' => (float) $order['total'],
            'currency' => $order['currency'],
            'paid_at' => iso_datetime($order['paid_at']),
            'invoice_number' => $invoice['invoice_number'] ?? null,
            'invoice_url' => $invoice['url'] ?? null,
        ] : null,
        'confirmation_email' => [
            'to' => $user['email'],
            'status' => $mail['status'] ?? null, // queued | sending | sent | failed | skipped | null (not logged)
        ],
    ]);
}

/**
 * Create or update the student's review of a course (one per course - uq_review). Needs an
 * active enrolment and some progress. Every submission goes back to "pending" moderation;
 * editing an approved review takes it off the public list until it is approved again.
 */
function submit_review(int $uid): never
{
    $in = request_body();
    $d = validate_or_fail($in, [
        'course' => 'required|slug',
        'rating' => 'required|int|min:1|max:5',
        'review' => 'string|max:2000',
    ]);
    // Archived batches included: the active-enrolment check below still applies.
    $course = db_one("SELECT id, title FROM courses WHERE slug = ? AND status IN ('published', 'archived')", [$d['course']]);
    if (!$course) {
        api_error('Course not found.', 404, 'not_found');
    }
    $courseId = (int) $course['id'];
    $enrolment = active_enrolment($uid, $courseId);
    if (!$enrolment) {
        api_error('Only students enrolled in this course can review it.', 403, 'not_enrolled');
    }
    $started = (float) $enrolment['progress_percent'] > 0
        || db_value('SELECT 1 FROM lesson_progress WHERE user_id = ? AND course_id = ? LIMIT 1', [$uid, $courseId]);
    if (!$started) {
        api_error('Start the course before reviewing it.', 409, 'not_started');
    }
    if (!rate_limit('course-review', (string) $uid, 5, 3600)) {
        api_error('You have submitted several reviews recently. Please try again later.', 429, 'rate_limited');
    }
    $text = $d['review'] !== null ? trim(strip_tags((string) $d['review'])) : null;
    $previous = db_value('SELECT status FROM course_reviews WHERE course_id = ? AND user_id = ?', [$courseId, $uid]);
    $wasApproved = $previous === 'approved';
    db_query(
        "INSERT INTO course_reviews (course_id, user_id, rating, review, status) VALUES (?, ?, ?, ?, 'pending')
         ON DUPLICATE KEY UPDATE rating = VALUES(rating), review = VALUES(review), status = 'pending', approved_by = NULL",
        [$courseId, $uid, $d['rating'], $text !== '' ? $text : null]
    );
    if ($wasApproved) {
        refresh_course_rating($courseId);
    }
    $r = db_one('SELECT rating, review, status, created_at FROM course_reviews WHERE course_id = ? AND user_id = ?', [$courseId, $uid]);
    api_ok([
        'message' => 'Thank you! Your review will appear on the course page once it is approved.',
        'review' => ['rating' => (int) $r['rating'], 'review' => $r['review'], 'status' => $r['status'], 'date' => iso_datetime($r['created_at'])],
    ], $previous ? 200 : 201);
}

function delete_request(array $user): never
{
    $row = db_one('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
    if (!password_verify((string) (request_body()['password'] ?? ''), $row['password_hash'])) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['password' => 'Password is incorrect.']);
    }
    db_query('UPDATE users SET deletion_requested_at = COALESCE(deletion_requested_at, NOW()) WHERE id = ?', [$user['id']]);
    audit_log('account_deletion_requested', 'user', $user['id'], [], 'warning');
    api_ok(['requested' => true]);
}
