<?php
/**
 * Catalogue presenters shared by api/courses.php, api/cms.php and (later)
 * checkout/admin code: one place for the course price rule and for the
 * public shape of courses and live classes.
 *
 * Public presenters never include private media paths or meeting URLs.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** Columns needed by format_course_card(). Expects aliases c (courses) and cat (course_categories). */
const COURSE_CARD_COLUMNS = "
    c.id, c.slug, c.title, c.subtitle, c.short_description, c.thumbnail_path, c.duration_label,
    c.language, c.level, c.course_type, c.price, c.discount_price, c.badge, c.is_featured,
    c.rating_avg, c.rating_count, c.is_demo, c.highlights,
    cat.slug AS category_slug, cat.name AS category_name, cat.color AS category_color, cat.icon AS category_icon,
    (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id AND l.status = 'published') AS lessons_count,
    (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id AND l.status = 'published' AND l.is_preview = 1) AS preview_lessons";

/** Course types (courses.course_type). 'offline' = classroom batches at the centre (migration 017). */
const COURSE_TYPE_LABELS = ['free' => 'Free', 'paid' => 'Paid', 'recorded' => 'Recorded', 'live' => 'Live', 'hybrid' => 'Live + Recorded', 'offline' => 'Offline Classes'];

/**
 * Whether migration 017 (offline courses, offers, fee items, syllabus) is applied. Cached per request,
 * so the catalogue keeps working between uploading new code and pressing Apply in Database Updates.
 */
function course_extras_enabled(): bool
{
    static $on = null;
    return $on ??= (bool) db_value("SHOW COLUMNS FROM courses LIKE 'syllabus'");
}

/** COURSE_CARD_COLUMNS plus the offer window and admission mode (placeholders before migration 017). */
function course_card_columns(): string
{
    return COURSE_CARD_COLUMNS . (course_extras_enabled()
        ? ', c.offer_starts_at, c.offer_ends_at, c.admission_mode'
        : ", NULL AS offer_starts_at, NULL AS offer_ends_at, 'online' AS admission_mode");
}

/**
 * SQL twin of course_effective_price() for filters and sorting (alias c). The sale price applies only
 * inside the optional offer window (offer_starts_at … offer_ends_at).
 */
function course_price_sql(): string
{
    $window = course_extras_enabled()
        ? ' AND (c.offer_starts_at IS NULL OR c.offer_starts_at <= NOW()) AND (c.offer_ends_at IS NULL OR c.offer_ends_at >= NOW())'
        : '';
    return "(CASE WHEN c.course_type = 'free' THEN 0 WHEN c.discount_price IS NOT NULL AND c.discount_price < c.price{$window} THEN c.discount_price ELSE c.price END)";
}

/**
 * Icon names (they exist in the site's <Icon> component, js/components.jsx) that admins may
 * pick for course highlights. Anything else falls back to checkCircle.
 */
const COURSE_HIGHLIGHT_ICONS = [
    'checkCircle' => 'Check mark', 'video' => 'Video', 'playCircle' => 'Play', 'book' => 'Book', 'file' => 'Notes / PDF',
    'clipboard' => 'Practice / tests', 'target' => 'Target', 'trophy' => 'Trophy', 'badge' => 'Certificate',
    'calendar' => 'Calendar', 'clock' => 'Clock', 'infinity' => 'Lifetime / unlimited', 'users' => 'Students',
    'user' => 'Mentor', 'headset' => 'Support', 'messages' => 'Doubt clearing', 'mic' => 'Live audio',
    'monitor' => 'Online / device', 'layers' => 'Modules', 'newspaper' => 'Current affairs', 'globe' => 'Online',
    'languages' => 'Languages', 'globeLang' => 'Bilingual', 'shield' => 'Trusted', 'star' => 'Star',
    'calculator' => 'Maths / aptitude', 'puzzle' => 'Reasoning', 'atom' => 'Science', 'landmark' => 'Polity / UPSC',
    'presentation' => 'Classroom', 'briefcase' => 'Career', 'bell' => 'Alerts', 'lock' => 'Secure',
    'whatsapp' => 'WhatsApp group', 'youtube' => 'YouTube', 'telegram' => 'Telegram',
];

/** Validated course highlights [{icon, text}] from the courses.highlights JSON column. */
function course_highlights(?string $json): array
{
    $out = [];
    foreach (json_decode_array($json) as $h) {
        $text = is_array($h) ? trim((string) ($h['text'] ?? '')) : '';
        if ($text === '') {
            continue;
        }
        $icon = (string) ($h['icon'] ?? '');
        $out[] = ['icon' => isset(COURSE_HIGHLIGHT_ICONS[$icon]) ? $icon : 'checkCircle', 'text' => mb_substr($text, 0, 120)];
        if (count($out) === 12) {
            break;
        }
    }
    return $out;
}

/** Absolute public URL of a course page (used for sharing). */
function course_share_url(string $slug): string
{
    return app_url('courses/' . $slug);
}

/** Human enrolment reference shown on the congratulations page and in emails: ENR-000123. */
function enrolment_reference(int $enrolmentId): string
{
    return sprintf('ENR-%06d', $enrolmentId);
}

/** Staff accounts (any role) can open every lesson and preview unpublished courses on the site. */
function course_viewer_is_staff(?array $user): bool
{
    return $user !== null && ($user['user_type'] ?? '') === 'staff';
}

/**
 * Course statuses an enrolled student can still learn in. "archived" = a finished batch: hidden from
 * the catalogue and closed to new admissions, but open to students whose enrolment is still active
 * (until their validity ends). Drafts are for staff only.
 */
const COURSE_OPEN_TO_ENROLLED = ['published', 'archived'];

/** Whether a student may open this course (row with id + status): published, or archived with an active enrolment. */
function course_open_for_student(array $course, ?array $user): bool
{
    if ($course['status'] === 'published') {
        return true;
    }
    return $user !== null && in_array($course['status'], COURSE_OPEN_TO_ENROLLED, true)
        && active_enrolment((int) $user['id'], (int) $course['id']) !== null;
}

/** Whether courses can carry a batch session (courses.academic_session, migration 010). Cached per request. */
function course_batches_enabled(): bool
{
    static $on = null;
    return $on ??= (bool) db_value("SHOW COLUMNS FROM courses LIKE 'academic_session'");
}

/**
 * The lesson a student should open next: the first published lesson (curriculum order) that is
 * not completed, else the first lesson. Null when the course has no published lessons.
 */
function course_next_lesson_id(int $userId, int $courseId): ?int
{
    $order = 'ORDER BY s.sort_order, s.id, l.sort_order, l.id LIMIT 1';
    $base = "FROM lessons l JOIN course_sections s ON s.id = l.section_id WHERE l.course_id = ? AND l.status = 'published'";
    $id = db_value(
        "SELECT l.id {$base} AND NOT EXISTS (SELECT 1 FROM lesson_progress lp WHERE lp.lesson_id = l.id AND lp.user_id = ? AND lp.status = 'completed') {$order}",
        [$courseId, $userId]
    ) ?: db_value("SELECT l.id {$base} {$order}", [$courseId]);
    return $id ? (int) $id : null;
}

/** Privacy-friendly embed URL for a lesson's YouTube / Vimeo link, or null for any other link (never embedded). */
function lesson_embed_url(string $url): ?string
{
    if (preg_match('#^https://(?:www\.|m\.)?(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|live/|shorts/))([\w-]{11})#', $url, $m)) {
        return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0&modestbranding=1';
    }
    if (preg_match('#^https://(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d+)#', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
    }
    return null;
}

/** Recalculate a course's cached rating from approved reviews only. */
function refresh_course_rating(int $courseId): void
{
    db_query("UPDATE courses SET
        rating_avg = (SELECT COALESCE(AVG(rating), 0) FROM course_reviews WHERE course_id = ? AND status = 'approved'),
        rating_count = (SELECT COUNT(*) FROM course_reviews WHERE course_id = ? AND status = 'approved')
        WHERE id = ?", [$courseId, $courseId, $courseId]);
}

/** Whether the course's offer window is open now (no dates = always open). Rows without the columns count as open. */
function course_offer_open(array $course): bool
{
    $now = time();
    $starts = $course['offer_starts_at'] ?? null;
    $ends = $course['offer_ends_at'] ?? null;
    return (!$starts || strtotime((string) $starts) <= $now) && (!$ends || strtotime((string) $ends) >= $now);
}

/** The price a student pays before coupons. The ONLY place this rule lives (course_price_sql() mirrors it). */
function course_effective_price(array $course): float
{
    $price = (float) $course['price'];
    $discount = $course['discount_price'] !== null ? (float) $course['discount_price'] : null;
    if ($course['course_type'] === 'free') {
        return 0.0;
    }
    return ($discount !== null && $discount < $price && course_offer_open($course)) ? $discount : $price;
}

/**
 * How students join a course (courses.admission_mode):
 *   online  = Enroll Now: create an account and pay online (or enrol free)
 *   both    = Enroll Now online, plus the Apply Now form for a counsellor call (migration 019)
 *   enquiry = Apply Now form only, no online checkout
 */
const COURSE_ADMISSION_LABELS = [
    'online' => 'Enroll Now - students create an account and pay online',
    'both' => 'Enroll Now + Apply Now - pay online, or apply for a counsellor call',
    'enquiry' => 'Apply Now only - our team calls back (no online payment)',
];

/** Admission modes the database accepts: 'both' needs migration 019. Cached per request. */
function course_admission_modes(): array
{
    static $modes = null;
    if ($modes === null) {
        $col = course_extras_enabled() ? db_one("SHOW COLUMNS FROM courses LIKE 'admission_mode'") : null;
        $modes = $col ? (str_contains((string) $col['Type'], "'both'") ? ['online', 'both', 'enquiry'] : ['online', 'enquiry']) : ['online'];
    }
    return $modes;
}

/** The course's admission mode: online | both | enquiry (rows without the column count as online). */
function course_admission(array $course): string
{
    $mode = (string) ($course['admission_mode'] ?? 'online');
    return isset(COURSE_ADMISSION_LABELS[$mode]) ? $mode : 'online';
}

/** Admission through the enquiry form only (no online checkout). "both" courses can be bought online. */
function course_enquiry_only(array $course): bool
{
    return course_admission($course) === 'enquiry';
}

/**
 * Validated fee items [{group, label, amount, note, offer_free}] from courses.fee_items. Each item is
 * shown on its own line - amounts are never added together.
 */
function course_fee_items(?string $json): array
{
    $out = [];
    foreach (json_decode_array($json) as $f) {
        $label = is_array($f) ? trim((string) ($f['label'] ?? '')) : '';
        if ($label === '' || !is_numeric($f['amount'] ?? null)) {
            continue;
        }
        $out[] = [
            'group' => mb_substr(trim((string) ($f['group'] ?? '')), 0, 60) ?: 'Other fees',
            'label' => mb_substr($label, 0, 80),
            'amount' => round(max(0, (float) $f['amount']), 2),
            'note' => mb_substr(trim((string) ($f['note'] ?? '')), 0, 200),
            'offer_free' => !empty($f['offer_free']),
        ];
    }
    return array_slice($out, 0, 20);
}

/** Validated syllabus [{title, topics: [..]}] from courses.syllabus (subjects in order, topics in order). */
function course_syllabus(?string $json): array
{
    $out = [];
    foreach (json_decode_array($json) as $s) {
        $title = is_array($s) ? trim((string) ($s['title'] ?? '')) : '';
        $topics = is_array($s) && is_array($s['topics'] ?? null)
            ? array_values(array_filter(array_map(fn($t) => mb_substr(trim((string) $t), 0, 120), $s['topics']), fn($t) => $t !== ''))
            : [];
        if ($title !== '' && $topics) {
            $out[] = ['title' => mb_substr($title, 0, 100), 'topics' => array_slice($topics, 0, 80)];
        }
    }
    return array_slice($out, 0, 20);
}

/** Extra course page sections [{title, body}] (exam details, preparation strategy…) from courses.info_sections. */
function course_info_sections(?string $json): array
{
    $out = [];
    foreach (json_decode_array($json) as $s) {
        $title = is_array($s) ? trim((string) ($s['title'] ?? '')) : '';
        $body = is_array($s) ? trim((string) ($s['body'] ?? '')) : '';
        if ($title !== '' && $body !== '') {
            $out[] = ['title' => mb_substr($title, 0, 100), 'body' => mb_substr($body, 0, 5000)];
        }
    }
    return array_slice($out, 0, 10);
}

function format_course_card(array $row): array
{
    $effective = course_effective_price($row);
    $original = (float) $row['price'];
    $onOffer = $original > $effective;
    return [
        'id' => (int) $row['id'],
        'slug' => $row['slug'],
        'title' => $row['title'],
        'subtitle' => $row['subtitle'],
        'short_description' => $row['short_description'],
        'thumbnail_url' => public_file_url($row['thumbnail_path']),
        'duration' => $row['duration_label'],
        'language' => $row['language'],
        'level' => $row['level'],
        'course_type' => $row['course_type'],
        'is_free' => $effective <= 0,
        'price' => $effective,
        'original_price' => $onOffer ? $original : null,
        // Last moment of the sale price, when the offer has an end date (shown as "Offer till …").
        'offer_ends_at' => $onOffer && !empty($row['offer_ends_at']) ? iso_datetime($row['offer_ends_at']) : null,
        'admission' => course_admission($row), // online | both (Enroll Now + Apply Now) | enquiry (Apply Now only)
        'badge' => $row['badge'],
        'lessons_count' => (int) ($row['lessons_count'] ?? 0),
        // Ratings are only exposed when backed by approved reviews
        'rating' => (int) $row['rating_count'] > 0
            ? ['average' => round((float) $row['rating_avg'], 1), 'count' => (int) $row['rating_count']]
            : null,
        'category' => [
            'slug' => $row['category_slug'],
            'name' => $row['category_name'],
            'color' => $row['category_color'],
            'icon' => $row['category_icon'],
        ],
        'is_demo' => (bool) $row['is_demo'],
        'preview_lessons' => (int) ($row['preview_lessons'] ?? 0),
        'highlights' => course_highlights($row['highlights'] ?? null),
        'share_url' => course_share_url((string) $row['slug']),
    ];
}

/** Published courses for cards. $where/$params are extra AND conditions. */
function fetch_course_cards(string $where = '1=1', array $params = [], string $orderBy = 'c.is_featured DESC, c.sort_order, c.id', int $limit = 8): array
{
    $rows = db_all(
        'SELECT ' . course_card_columns() . "
         FROM courses c
         JOIN course_categories cat ON cat.id = c.category_id AND cat.is_active = 1
         WHERE c.status = 'published' AND ({$where})
         ORDER BY {$orderBy}
         LIMIT " . max(1, min(100, $limit)),
        $params
    );
    return array_map('format_course_card', $rows);
}

/** Columns needed by format_live_class(). Aliases: lc, i (instructors), cat, c (courses). */
const LIVE_CLASS_COLUMNS = "
    lc.id, lc.title, lc.description, lc.starts_at, lc.duration_minutes, lc.access_type, lc.status,
    lc.announcement, lc.is_demo,
    i.name AS instructor_name,
    cat.slug AS category_slug, cat.name AS category_name, cat.color AS category_color,
    c.slug AS course_slug, c.title AS course_title";

const LIVE_CLASS_JOINS = "
    LEFT JOIN instructors i ON i.id = lc.instructor_id
    LEFT JOIN course_categories cat ON cat.id = lc.category_id
    LEFT JOIN courses c ON c.id = lc.course_id";

/** Public live-class shape. The meeting URL is deliberately excluded. */
function format_live_class(array $row): array
{
    $start = new DateTimeImmutable($row['starts_at']);
    $end = $start->modify('+' . (int) $row['duration_minutes'] . ' minutes');
    $now = new DateTimeImmutable();
    $state = match (true) {
        $row['status'] === 'cancelled' => 'cancelled',
        $row['status'] === 'completed' || $now >= $end => 'completed',
        $now >= $start => 'live',
        default => 'upcoming',
    };
    return [
        'id' => (int) $row['id'],
        'title' => $row['title'],
        'description' => $row['description'],
        'starts_at' => $start->format(DATE_ATOM),
        'ends_at' => $end->format(DATE_ATOM),
        'join_opens_at' => $start->modify('-' . setting_int('live_join_window_minutes', 15, 0, 240) . ' minutes')->format(DATE_ATOM),
        'duration_minutes' => (int) $row['duration_minutes'],
        'state' => $state,
        'access_type' => $row['access_type'],
        'announcement' => $row['announcement'],
        'instructor' => $row['instructor_name'],
        'category' => $row['category_slug'] ? ['slug' => $row['category_slug'], 'name' => $row['category_name'], 'color' => $row['category_color']] : null,
        'course' => $row['course_slug'] ? ['slug' => $row['course_slug'], 'title' => $row['course_title']] : null,
        'is_demo' => (bool) $row['is_demo'],
    ];
}

/** Upcoming or currently running published classes. */
function fetch_upcoming_live_classes(int $limit = 4, ?int $courseId = null): array
{
    $params = [];
    $courseSql = '';
    if ($courseId !== null) {
        $courseSql = ' AND lc.course_id = ?';
        $params[] = $courseId;
    }
    $rows = db_all(
        'SELECT ' . LIVE_CLASS_COLUMNS . ' FROM live_classes lc ' . LIVE_CLASS_JOINS . "
         WHERE lc.is_published = 1 AND lc.status = 'scheduled'
           AND DATE_ADD(lc.starts_at, INTERVAL lc.duration_minutes MINUTE) > NOW(){$courseSql}
         ORDER BY lc.starts_at
         LIMIT " . max(1, min(50, $limit)),
        $params
    );
    return array_map('format_live_class', $rows);
}

// ------------------------------------------------------------------
// Enrolment & access rules (single source of truth)
// ------------------------------------------------------------------

/** The user's currently valid enrolment for a course, or null. Expires stale rows lazily. */
function active_enrolment(int $userId, int $courseId): ?array
{
    $row = db_one('SELECT * FROM course_enrolments WHERE user_id = ? AND course_id = ?', [$userId, $courseId]);
    if (!$row || $row['status'] !== 'active') {
        return null;
    }
    if ($row['expires_at'] !== null && strtotime($row['expires_at']) <= time()) {
        db_query("UPDATE course_enrolments SET status = 'expired' WHERE id = ?", [$row['id']]);
        return null;
    }
    return $row;
}

/** Course ids the user can currently access. */
function accessible_course_ids(int $userId): array
{
    return array_map('intval', array_column(db_all(
        "SELECT course_id FROM course_enrolments
         WHERE user_id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())",
        [$userId]
    ), 'course_id'));
}

/**
 * Create or re-activate an enrolment. Must be called inside the caller's transaction
 * when part of a payment. Validity starts now; an existing later expiry is kept.
 */
function enrol_user(int $userId, int $courseId, string $source, ?int $orderId = null, ?int $grantedBy = null): void
{
    $validity = db_value('SELECT validity_days FROM courses WHERE id = ?', [$courseId]);
    $expires = $validity ? (new DateTimeImmutable())->modify('+' . (int) $validity . ' days')->format('Y-m-d H:i:s') : null;
    db_query(
        "INSERT INTO course_enrolments (user_id, course_id, order_id, source, status, expires_at, granted_by)
         VALUES (?, ?, ?, ?, 'active', ?, ?)
         ON DUPLICATE KEY UPDATE
            status = 'active',
            order_id = COALESCE(VALUES(order_id), order_id),
            source = VALUES(source),
            expires_at = CASE
                WHEN VALUES(expires_at) IS NULL THEN NULL
                WHEN expires_at IS NOT NULL AND expires_at > VALUES(expires_at) AND status = 'active' THEN expires_at
                ELSE VALUES(expires_at) END,
            granted_by = VALUES(granted_by)",
        [$userId, $courseId, $orderId, $source, $expires, $grantedBy]
    );
}

/** Prerequisite rule: returns the unmet prerequisite course title, or null. */
function unmet_prerequisite(int $userId, int $courseId): ?string
{
    $pre = db_one(
        'SELECT p.id, p.title FROM courses c JOIN courses p ON p.id = c.prerequisite_course_id WHERE c.id = ?',
        [$courseId]
    );
    if (!$pre) {
        return null;
    }
    $done = db_value('SELECT completed_at FROM course_enrolments WHERE user_id = ? AND course_id = ?', [$userId, $pre['id']]);
    return $done ? null : $pre['title'];
}

/** Recalculate and store course progress (percentage of published lessons completed). */
function recalc_course_progress(int $userId, int $courseId): float
{
    return update_course_progress($userId, $courseId)['progress'];
}

/**
 * Recalculate progress and record completion. completed_at is set once - the first time every
 * published lesson is done - and is then kept (lessons added later do not revoke a completion,
 * which prerequisites and certificates rely on). The course_completed email/in-app notice is
 * sent exactly when completed_at is first set; the conditional UPDATE makes it race-safe.
 *
 * @return array{progress: float, just_completed: bool}
 */
function update_course_progress(int $userId, int $courseId): array
{
    $total = (int) db_value("SELECT COUNT(*) FROM lessons WHERE course_id = ? AND status = 'published'", [$courseId]);
    $done = (int) db_value(
        "SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
         WHERE lp.user_id = ? AND lp.course_id = ? AND lp.status = 'completed' AND l.status = 'published'",
        [$userId, $courseId]
    );
    $pct = $total ? min(100.0, round($done / $total * 100, 2)) : 0.0;
    db_query('UPDATE course_enrolments SET progress_percent = ? WHERE user_id = ? AND course_id = ?', [$pct, $userId, $courseId]);

    $justCompleted = false;
    if ($total > 0 && $pct >= 100) {
        $justCompleted = db_query(
            'UPDATE course_enrolments SET completed_at = NOW() WHERE user_id = ? AND course_id = ? AND completed_at IS NULL',
            [$userId, $courseId]
        )->rowCount() === 1;
    }
    if ($justCompleted) {
        $c = db_one(
            'SELECT c.title, c.slug, e.id AS enrolment_id FROM courses c JOIN course_enrolments e ON e.course_id = c.id AND e.user_id = ? WHERE c.id = ?',
            [$userId, $courseId]
        );
        if ($c) {
            require_once APP_ROOT . '/includes/notify.php';
            try {
                send_template('course_completed', $userId, [
                    'course' => $c['title'],
                    'link' => '/dashboard/certificates',
                    'button_label' => 'View my certificates',
                    'links' => [['Rate this course', course_share_url($c['slug'])]],
                    'related' => ['enrolment', (int) $c['enrolment_id']],
                ]);
            } catch (Throwable $e) {
                // Progress is already saved; a mail failure must not fail the student's request.
                error_log('[laxyam] course_completed notice failed: ' . $e->getMessage());
            }
        }
    }
    return ['progress' => (float) $pct, 'just_completed' => $justCompleted];
}

/**
 * Can this (possibly anonymous) user open a live class link / resource / exam?
 * $accessType: public|free|registered|enrolled ; $courseId used for "enrolled".
 */
function content_access_allowed(?array $user, string $accessType, ?int $courseId): bool
{
    return match ($accessType) {
        'public', 'free' => true,
        'registered' => $user !== null,
        'enrolled' => $user !== null && (
            $user['user_type'] === 'staff'
            || ($courseId !== null && active_enrolment((int) $user['id'], $courseId) !== null)
        ),
        default => false,
    };
}

/** Whether students may see scores for an exam under its result-release setting. */
function exam_results_visible(array $exam): bool
{
    return match ($exam['result_release']) {
        'immediate' => true,
        'after_end' => $exam['ends_at'] === null || strtotime($exam['ends_at']) <= time(),
        'manual' => $exam['results_released_at'] !== null && strtotime($exam['results_released_at']) <= time(),
        default => false,
    };
}

/**
 * Send reminders for classes starting within $withinMinutes to students who set one.
 * Idempotent: each reminder row is marked notified_at once. Used by admin/notifications.php and database/cron.php.
 */
function send_due_class_reminders(?int $withinMinutes = null): int
{
    require_once APP_ROOT . '/includes/notify.php';
    $withinMinutes ??= setting_int('live_reminder_lead_minutes', 60, 5, 1440);
    $due = db_all(
        "SELECT r.user_id, r.live_class_id, lc.title, lc.starts_at, u.email, u.name, COALESCE(p.notify_class_reminders, 1) AS wants_email
         FROM live_class_reminders r
         JOIN live_classes lc ON lc.id = r.live_class_id AND lc.status = 'scheduled' AND lc.is_published = 1
         JOIN users u ON u.id = r.user_id AND u.status = 'active'
         LEFT JOIN student_profiles p ON p.user_id = u.id
         WHERE r.notified_at IS NULL AND lc.starts_at BETWEEN NOW() AND NOW() + INTERVAL ? MINUTE",
        [$withinMinutes]
    );
    foreach ($due as $d) {
        send_template('class_reminder', (int) $d['user_id'], [
            'course' => $d['title'], 'when' => date('j M Y, g:i A', strtotime($d['starts_at'])), 'link' => '/dashboard/live-classes',
            'related' => ['live_class', (int) $d['live_class_id']], // one WhatsApp reminder per class and student
        ]);
        db_query('UPDATE live_class_reminders SET notified_at = NOW() WHERE user_id = ? AND live_class_id = ?', [$d['user_id'], $d['live_class_id']]);
    }
    return count($due);
}

/** Active categories with published course counts. */
function fetch_course_categories(): array
{
    $rows = db_all(
        "SELECT cat.id, cat.parent_id, cat.name, cat.slug, cat.short_name, cat.icon, cat.color, cat.description,
                cat.seo_title, cat.seo_description,
                (SELECT COUNT(*) FROM courses c JOIN course_categories cc ON cc.id = c.category_id
                  WHERE c.status = 'published' AND cc.is_active = 1 AND (cc.id = cat.id OR cc.parent_id = cat.id)) AS course_count
         FROM course_categories cat
         WHERE cat.is_active = 1
         ORDER BY cat.sort_order, cat.name"
    );
    return array_map(fn($r) => [
        'id' => (int) $r['id'],
        'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
        'name' => $r['name'],
        'slug' => $r['slug'],
        'short_name' => $r['short_name'],
        'icon' => $r['icon'],
        'color' => $r['color'],
        'description' => $r['description'],
        'course_count' => (int) $r['course_count'],
        'seo_title' => $r['seo_title'],
        'seo_description' => $r['seo_description'],
    ], $rows);
}
