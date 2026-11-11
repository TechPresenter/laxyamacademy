<?php
/**
 * Course learning API. Every lesson request is authorised on the server:
 * preview lessons are open to everyone (guests included), everything else needs an
 * active enrolment; staff accounts can open every lesson (content checks / previews).
 *
 *   GET  lessons.php?action=player&course=...   course outline + progress + lock flags (slug= also accepted; guests allowed)
 *   GET  lessons.php?action=lesson&id=...       lesson content (401 guest / 403 not enrolled for locked lessons)
 *   GET  lessons.php?action=media&id=...        streams the private video/PDF (Range supported; &download=1 for downloadable PDFs)
 *   POST lessons.php?action=progress            lesson_id, completed (bool), position | last_position_seconds (seconds)
 *   POST lessons.php?action=enrol-free          slug  (free courses only)
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/catalog.php';

/** Minimum seconds between two resume-position writes for the same lesson. */
const PROGRESS_MIN_INTERVAL = 10;

$action = $_GET['action'] ?? '';
if (in_array($action, ['progress', 'enrol-free'], true)) {
    require_method('POST');
    require_csrf();
} else {
    require_method('GET');
}

match ($action) {
    'player' => player(),
    'lesson' => lesson(),
    'media' => media(),
    'progress' => progress(),
    'enrol-free' => enrol_free(),
    default => api_error('Unknown action.', 404, 'not_found'),
};

/**
 * Course by slug: published for everyone; archived (finished batch) for students still enrolled in it ($user);
 * staff may also open draft/archived courses (admin preview).
 */
function published_course_by_slug(string $slug, bool $allowUnpublished = false, ?array $user = null): array
{
    $course = preg_match('/^[a-z0-9-]{1,200}$/', $slug) ? db_one('SELECT * FROM courses WHERE slug = ?', [$slug]) : null;
    if (!$course || (!$allowUnpublished && !course_open_for_student($course, $user))) {
        api_error('Course not found.', 404, 'not_found');
    }
    return $course;
}

/**
 * Load a published lesson and decide access - the single access rule for lesson, media and progress.
 * Returns [lesson, enrolment|null, access] where access is 'enrolled' | 'staff' | 'preview'.
 * Locked lessons stop with 401 (guest) or 403 (logged in, not enrolled / enrolment expired).
 */
function authorised_lesson(int $lessonId, ?array $user): array
{
    $isStaff = course_viewer_is_staff($user);
    $lesson = db_one(
        "SELECT l.*, c.slug AS course_slug, c.title AS course_title, c.status AS course_status, s.title AS section_title
         FROM lessons l JOIN courses c ON c.id = l.course_id LEFT JOIN course_sections s ON s.id = l.section_id
         WHERE l.id = ? AND l.status = 'published'",
        [$lessonId]
    );
    if (!$lesson) {
        api_error('Lesson not found.', 404, 'not_found');
    }
    $enrolment = $user && !$isStaff ? active_enrolment((int) $user['id'], (int) $lesson['course_id']) : null;
    // Archived batches stay open to their enrolled students; drafts only to staff.
    if ($lesson['course_status'] !== 'published' && !$isStaff && !($enrolment && in_array($lesson['course_status'], COURSE_OPEN_TO_ENROLLED, true))) {
        api_error('Lesson not found.', 404, 'not_found');
    }
    if ($enrolment) {
        return [$lesson, $enrolment, 'enrolled'];
    }
    if ($isStaff) {
        return [$lesson, null, 'staff'];
    }
    if ($lesson['is_preview']) {
        return [$lesson, null, 'preview'];
    }
    if (!$user) {
        api_error('Please log in to continue.', 401, 'unauthenticated');
    }
    $status = db_value('SELECT status FROM course_enrolments WHERE user_id = ? AND course_id = ?', [$user['id'], $lesson['course_id']]);
    if ($status === 'expired') {
        api_error('Your access to this course has expired. Renew it to continue learning.', 403, 'enrolment_expired');
    }
    api_error('Enrol in this course to open this lesson.', 403, 'not_enrolled');
}

function player(): never
{
    $user = current_user();
    $isStaff = course_viewer_is_staff($user);
    $course = published_course_by_slug(query_str('course', 200) ?: query_str('slug', 200), $isStaff, $user);
    $courseId = (int) $course['id'];
    $enrolment = $user && !$isStaff ? active_enrolment((int) $user['id'], $courseId) : null;
    // Staff see everything unlocked; &as=student shows the outline a non-enrolled student gets.
    $unlocked = $enrolment !== null || ($isStaff && query_str('as', 20) !== 'student');

    $sections = db_all('SELECT id, title FROM course_sections WHERE course_id = ? ORDER BY sort_order, id', [$courseId]);
    $lessons = db_all(
        "SELECT l.id, l.section_id, l.title, l.lesson_type, l.duration_minutes, l.is_preview,
                lp.status AS progress_status
         FROM lessons l
         LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = ?
         WHERE l.course_id = ? AND l.status = 'published'
         ORDER BY l.sort_order, l.id",
        [$user['id'] ?? 0, $courseId]
    );
    $grouped = [];
    $counts = ['lessons' => count($lessons), 'preview' => 0, 'locked' => 0, 'completed' => 0];
    foreach ($lessons as $l) {
        $locked = !$unlocked && !$l['is_preview'];
        $counts['preview'] += $l['is_preview'] ? 1 : 0;
        $counts['locked'] += $locked ? 1 : 0;
        $counts['completed'] += $l['progress_status'] === 'completed' ? 1 : 0;
        $grouped[(int) $l['section_id']][] = [
            'id' => (int) $l['id'],
            'title' => $l['title'],
            'type' => $l['lesson_type'],
            'duration_minutes' => $l['duration_minutes'] !== null ? (int) $l['duration_minutes'] : null,
            'is_preview' => (bool) $l['is_preview'],
            'locked' => $locked,
            'completed' => $l['progress_status'] === 'completed',
        ];
    }
    $effective = course_effective_price($course);
    $enrolStatus = $enrolment ? 'active' : ($user && !$isStaff ? db_value('SELECT status FROM course_enrolments WHERE user_id = ? AND course_id = ?', [$user['id'], $courseId]) : null);

    api_ok([
        'course' => [
            'id' => $courseId,
            'slug' => $course['slug'],
            'title' => $course['title'],
            'is_free' => $effective <= 0,
            'price' => $effective,
            'original_price' => (float) $course['price'] > $effective ? (float) $course['price'] : null,
            'thumbnail_url' => public_file_url($course['thumbnail_path']),
            'status' => $course['status'],
            'share_url' => course_share_url($course['slug']),
        ],
        'enrolment' => $enrolment ? [
            'progress' => (float) $enrolment['progress_percent'],
            'expires_at' => iso_datetime($enrolment['expires_at']),
            'completed_at' => iso_datetime($enrolment['completed_at']),
            'reference' => enrolment_reference((int) $enrolment['id']),
        ] : null,
        'viewer' => [
            'logged_in' => $user !== null,
            'is_staff' => $isStaff,
            'enrolled' => $enrolment !== null,
            'enrolment_status' => $enrolStatus ?: null,
            'can_track' => $enrolment !== null, // progress is saved for enrolled students only
            'preview_only' => !$unlocked,
        ],
        'next_lesson_id' => $enrolment ? course_next_lesson_id((int) $user['id'], $courseId) : null,
        'counts' => $counts,
        'sections' => array_map(fn($s) => [
            'id' => (int) $s['id'],
            'title' => $s['title'],
            'lessons' => $grouped[(int) $s['id']] ?? [],
        ], $sections),
    ]);
}

function lesson(): never
{
    $user = current_user();
    [$l, $enrolment, $access] = authorised_lesson(query_int('id', 0, 0), $user);

    $mediaUrl = app_url('api/lessons.php?action=media&id=' . $l['id']);
    $content = ['kind' => $l['lesson_type']];
    switch ($l['lesson_type']) {
        case 'text':
            $content['html'] = sanitize_html($l['content']);
            break;
        case 'video':
            $content['video_url'] = private_file_path($l['video_path']) ? $mediaUrl : null;
            $content['html'] = sanitize_html($l['content']);
            break;
        case 'pdf':
            $hasFile = (bool) private_file_path($l['file_path']);
            $content['file_url'] = $hasFile ? $mediaUrl : null;
            $content['downloadable'] = (bool) $l['is_downloadable'];
            $content['download_url'] = $hasFile && $l['is_downloadable'] ? $mediaUrl . '&download=1' : null;
            $content['html'] = sanitize_html($l['content']);
            break;
        case 'external_video':
            $content['embed_url'] = embed_url((string) $l['external_url']);
            $content['html'] = sanitize_html($l['content']);
            break;
        case 'live':
            $content['live_class_id'] = $l['live_class_id'] ? (int) $l['live_class_id'] : null;
            $content['html'] = sanitize_html($l['content']);
            break;
        case 'quiz':
            $content['exam_slug'] = $l['exam_id'] ? db_value("SELECT slug FROM exams WHERE id = ? AND status = 'published'", [$l['exam_id']]) : null;
            $content['html'] = sanitize_html($l['content']);
            break;
    }

    $progress = $user ? db_one('SELECT status, last_position_seconds FROM lesson_progress WHERE user_id = ? AND lesson_id = ?', [$user['id'], $l['id']]) : null;

    api_ok([
        'id' => (int) $l['id'],
        'title' => $l['title'],
        'type' => $l['lesson_type'],
        'duration_minutes' => $l['duration_minutes'] !== null ? (int) $l['duration_minutes'] : null,
        'is_preview' => (bool) $l['is_preview'],
        'access' => $access, // enrolled | staff | preview
        'section' => $l['section_title'],
        'course' => ['slug' => $l['course_slug'], 'title' => $l['course_title']],
        'content' => $content,
        'completed' => ($progress['status'] ?? null) === 'completed',
        'position' => (int) ($progress['last_position_seconds'] ?? 0),
        'can_track' => (bool) $enrolment,
    ]);
}

/** Only YouTube and Vimeo URLs are converted into embeds; anything else is withheld. */
function embed_url(string $url): ?string
{
    return lesson_embed_url($url);
}

function media(): never
{
    $user = current_user();
    [$l] = authorised_lesson(query_int('id', 0, 0), $user);
    $path = match ($l['lesson_type']) {
        'video' => private_file_path($l['video_path']),
        'pdf' => private_file_path($l['file_path']),
        default => null,
    };
    if (!$path) {
        api_error('File not available.', 404, 'not_found');
    }
    $wantsDownload = isset($_GET['download']);
    if ($wantsDownload && !($l['lesson_type'] === 'pdf' && $l['is_downloadable'])) {
        api_error('Downloading is not allowed for this lesson.', 403, 'download_disabled');
    }
    stream_file($path, slugify($l['title']) . '.' . pathinfo($path, PATHINFO_EXTENSION), !$wantsDownload);
}

/**
 * Save lesson progress. completed=true marks the lesson done (and may complete the course);
 * completed=false is a resume-position save, throttled to one write per lesson every
 * PROGRESS_MIN_INTERVAL seconds (a throttled save still answers 200 with saved=false).
 * Also accepts form posts with an _csrf field, so navigator.sendBeacon can save on page hide.
 */
function progress(): never
{
    $user = require_login();
    $in = request_body();
    $lessonId = filter_var($in['lesson_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    [$l, $enrolment] = authorised_lesson($lessonId, $user);
    if (!$enrolment) {
        api_error('Progress is tracked for enrolled students only.', 403, 'not_enrolled');
    }
    $completed = filter_var($in['completed'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $position = max(0, min(86400, (int) ($in['last_position_seconds'] ?? $in['position'] ?? 0)));
    $uid = (int) $user['id'];
    $courseId = (int) $l['course_id'];
    $existing = db_one(
        'SELECT status, last_position_seconds, updated_at > (NOW() - INTERVAL ? SECOND) AS recent FROM lesson_progress WHERE user_id = ? AND lesson_id = ?',
        [PROGRESS_MIN_INTERVAL, $uid, $l['id']]
    );

    if (!$completed) {
        $saved = false;
        if (!$existing || !$existing['recent']) {
            if ($existing) {
                db_query('UPDATE lesson_progress SET last_position_seconds = ? WHERE user_id = ? AND lesson_id = ?', [$position, $uid, $l['id']]);
            } else {
                db_query(
                    "INSERT INTO lesson_progress (user_id, course_id, lesson_id, status, last_position_seconds) VALUES (?, ?, ?, 'started', ?)
                     ON DUPLICATE KEY UPDATE last_position_seconds = VALUES(last_position_seconds)",
                    [$uid, $courseId, $l['id'], $position]
                );
            }
            $saved = true;
        }
        api_ok([
            'progress' => (float) $enrolment['progress_percent'],
            'completed' => ($existing['status'] ?? null) === 'completed',
            'position' => $saved ? $position : (int) ($existing['last_position_seconds'] ?? 0),
            'saved' => $saved,
            'course_completed' => false,
        ]);
    }

    db_query(
        "INSERT INTO lesson_progress (user_id, course_id, lesson_id, status, last_position_seconds, completed_at)
         VALUES (?, ?, ?, 'completed', ?, NOW())
         ON DUPLICATE KEY UPDATE
            last_position_seconds = VALUES(last_position_seconds),
            status = 'completed',
            completed_at = COALESCE(completed_at, VALUES(completed_at))",
        [$uid, $courseId, $l['id'], $position]
    );
    $state = update_course_progress($uid, $courseId);
    api_ok([
        'progress' => $state['progress'],
        'completed' => true,
        'position' => $position,
        'saved' => true,
        'course_completed' => $state['just_completed'], // true only on the request that finished the course
    ]);
}

function enrol_free(): never
{
    $user = require_student();
    $course = published_course_by_slug((string) (request_body()['slug'] ?? ''));
    if (course_enquiry_only($course)) {
        api_error('Admission to this course is through our admissions desk. Use "Apply Now" on the course page.', 409, 'enquiry_only');
    }
    if (course_effective_price($course) > 0) {
        api_error('This is a paid course. Please complete checkout to enrol.', 402, 'payment_required');
    }
    $uid = (int) $user['id'];
    $courseId = (int) $course['id'];
    $already = active_enrolment($uid, $courseId) !== null;
    if (!$already) {
        // Access removed by staff stays removed: a free course cannot be used to undo a revocation.
        if (db_value('SELECT status FROM course_enrolments WHERE user_id = ? AND course_id = ?', [$uid, $courseId]) === 'revoked') {
            api_error('Your access to this course was removed by the academy. Please contact support if you think this is a mistake.', 403, 'enrolment_revoked');
        }
        if ($pre = unmet_prerequisite($uid, $courseId)) {
            api_error("Please complete “{$pre}” before enrolling in this course.", 409, 'prerequisite');
        }
        if (!rate_limit('enrol-free', (string) $uid, 20, 3600)) {
            api_error('Too many enrolments in a short time. Please try again later.', 429, 'rate_limited');
        }
        enrol_user($uid, $courseId, 'free');
    }
    $enrolment = db_one('SELECT id FROM course_enrolments WHERE user_id = ? AND course_id = ?', [$uid, $courseId]);
    if (!$already) {
        require_once APP_ROOT . '/includes/notify.php';
        send_template('enrolment', $uid, [
            'course' => $course['title'],
            'link' => '/learn/' . $course['slug'],
            'button_label' => 'Start learning',
            'related' => ['enrolment', (int) $enrolment['id']],
        ]);
    }
    api_ok([
        'enrolled' => true,
        'slug' => $course['slug'],
        'already_enrolled' => $already,
        'reference' => enrolment_reference((int) $enrolment['id']),
        'next_lesson_id' => course_next_lesson_id($uid, $courseId),
    ]);
}
