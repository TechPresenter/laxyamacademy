<?php
/**
 * Public course catalogue API.
 *
 *   GET courses.php?action=list        filters: q, category, type, language, level,
 *                                      price_min, price_max, rating_min (3|3.5|4|4.5), sort, page, per_page
 *   GET courses.php?action=categories
 *   GET courses.php?action=detail&slug=upsc-foundation-2027[&as=student]   (viewer-specific, not cached)
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/catalog.php';

require_method('GET');

match ($_GET['action'] ?? 'list') {
    'list' => course_list(),
    'categories' => api_ok(fetch_course_categories(), 200, true),
    'detail' => course_detail(),
    default => api_error('Unknown action.', 404, 'not_found'),
};

function course_list(): never
{
    $where = ["c.status = 'published'", 'cat.is_active = 1'];
    $params = [];

    $q = query_str('q', 100);
    if ($q !== '') {
        // Title/subtitle/descriptions, the category (or its parent), any published section or
        // lesson title and the syllabus topics - so "Polity" finds the course with a Polity module.
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $syllabus = course_extras_enabled() ? ' OR c.syllabus LIKE ?' : '';
        $where[] = "(c.title LIKE ? OR c.subtitle LIKE ? OR c.short_description LIKE ? OR c.description LIKE ?
            OR cat.name LIKE ? OR cat.short_name LIKE ?
            OR cat.parent_id IN (SELECT pc.id FROM course_categories pc WHERE pc.name LIKE ? OR pc.short_name LIKE ?)
            OR EXISTS (SELECT 1 FROM course_sections s WHERE s.course_id = c.id AND s.title LIKE ?)
            OR EXISTS (SELECT 1 FROM lessons sl WHERE sl.course_id = c.id AND sl.status = 'published' AND sl.title LIKE ?){$syllabus})";
        array_push($params, ...array_fill(0, $syllabus ? 11 : 10, $like));
    }

    $categories = query_slugs('category');
    if ($categories) {
        // A main category also matches courses in its subcategories.
        $ph = db_placeholders(count($categories));
        $where[] = "(cat.slug IN ({$ph}) OR cat.parent_id IN (SELECT id FROM course_categories WHERE slug IN ({$ph})))";
        array_push($params, ...$categories, ...$categories);
    }

    $types = array_values(array_intersect(query_slugs('type'), array_keys(COURSE_TYPE_LABELS)));
    if ($types) {
        $where[] = 'c.course_type IN (' . db_placeholders(count($types)) . ')';
        array_push($params, ...$types);
    }

    $languages = array_values(array_intersect(query_slugs('language'), ['hindi', 'english', 'bilingual']));
    if ($languages) {
        $languages[] = 'bilingual'; // bilingual courses suit both Hindi and English learners
        $languages = array_values(array_unique($languages));
        $where[] = 'c.language IN (' . db_placeholders(count($languages)) . ')';
        array_push($params, ...$languages);
    }

    $levels = array_values(array_intersect(query_slugs('level'), ['beginner', 'intermediate', 'advanced', 'all']));
    if ($levels) {
        $levels[] = 'all'; // "All levels" courses suit every level a student picks (like bilingual above)
        $levels = array_values(array_unique($levels));
        $where[] = 'c.level IN (' . db_placeholders(count($levels)) . ')';
        array_push($params, ...$levels);
    }

    $priceExpr = course_price_sql(); // mirrors course_effective_price()
    $min = query_int('price_min', -1, -1, 10_000_000);
    $max = query_int('price_max', -1, -1, 10_000_000);
    if ($min >= 0) {
        $where[] = "{$priceExpr} >= ?";
        $params[] = $min;
    }
    if ($max >= 0) {
        $where[] = "{$priceExpr} <= ?";
        $params[] = $max;
    }

    $from = 'FROM courses c JOIN course_categories cat ON cat.id = c.category_id';

    // Rating facet: how many courses each "N★ & up" option would return together with every
    // other active filter (the rating filter itself is left out, so the options stay comparable).
    $ratingSteps = ['4.5' => 4.5, '4' => 4.0, '3.5' => 3.5, '3' => 3.0];
    $ratingSql = [];
    foreach ($ratingSteps as $key => $step) {
        $ratingSql[] = "COALESCE(SUM(CASE WHEN c.rating_count > 0 AND c.rating_avg >= {$step} THEN 1 ELSE 0 END), 0) AS `r{$key}`";
    }
    $ratingRow = db_one('SELECT ' . implode(', ', $ratingSql) . " {$from} WHERE " . implode(' AND ', $where), $params) ?? [];
    $ratingCounts = [];
    foreach ($ratingSteps as $key => $step) {
        $ratingCounts[(string) $key] = (int) ($ratingRow["r{$key}"] ?? 0);
    }

    // rating_min: only the four offered steps; anything else is ignored.
    $ratingMin = query_str('rating_min', 4);
    if (is_numeric($ratingMin) && in_array((float) $ratingMin, $ratingSteps, true)) {
        $where[] = 'c.rating_count > 0 AND c.rating_avg >= ?';
        $params[] = (float) $ratingMin;
    }

    $orderBy = match (query_str('sort', 20)) {
        'newest' => 'c.published_at DESC, c.id DESC',
        'price_asc' => "{$priceExpr} ASC, c.id",
        'price_desc' => "{$priceExpr} DESC, c.id",
        'rating' => 'c.rating_avg DESC, c.rating_count DESC, c.id',
        default => 'c.is_featured DESC, c.sort_order, c.id',
    };

    $page = query_int('page', 1, 1, 1000);
    $perPage = query_int('per_page', 12, 1, 48);
    $whereSql = implode(' AND ', $where);

    $total = (int) db_value("SELECT COUNT(*) {$from} WHERE {$whereSql}", $params);
    $rows = db_all(
        'SELECT ' . course_card_columns() . " {$from} WHERE {$whereSql} ORDER BY {$orderBy} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
        $params
    );

    // Facet counts across all published courses
    $published = "c.status = 'published' AND cat.is_active = 1";
    $facet = fn(string $col) => array_column(
        db_all("SELECT {$col} AS k, COUNT(*) AS n {$from} WHERE {$published} GROUP BY {$col}"), 'n', 'k'
    );
    $levelCounts = array_map('intval', $facet('c.level')) + ['beginner' => 0, 'intermediate' => 0, 'advanced' => 0, 'all' => 0];
    $languageCounts = array_map('intval', $facet('c.language')) + ['hindi' => 0, 'english' => 0, 'bilingual' => 0];

    // Price slider: the raw maximum plus a step and a step-aligned slider end (9,999 → 10,000 with step 500),
    // so the slider can always reach the most expensive course. price_max >= max_price means "no limit".
    $maxPrice = (int) ceil((float) db_value("SELECT MAX({$priceExpr}) {$from} WHERE {$published}"));
    $step = match (true) { $maxPrice <= 2000 => 100, $maxPrice <= 25000 => 500, default => 1000 };

    api_ok([
        'items' => array_map('format_course_card', $rows),
        'pagination' => pagination_meta($total, $page, $perPage),
        'facets' => [
            'categories' => fetch_course_categories(),
            'types' => array_map('intval', $facet('c.course_type')),
            'languages' => $languageCounts,
            'levels' => $levelCounts,
            // What ticking a level returns: its own courses plus the "All levels" ones (same for bilingual).
            'levels_inclusive' => [
                'beginner' => $levelCounts['beginner'] + $levelCounts['all'],
                'intermediate' => $levelCounts['intermediate'] + $levelCounts['all'],
                'advanced' => $levelCounts['advanced'] + $levelCounts['all'],
                'all' => $levelCounts['all'],
            ],
            'languages_inclusive' => [
                'hindi' => $languageCounts['hindi'] + $languageCounts['bilingual'],
                'english' => $languageCounts['english'] + $languageCounts['bilingual'],
                'bilingual' => $languageCounts['bilingual'],
            ],
            // {"4.5": n, "4": n, "3.5": n, "3": n} - counted with the other active filters applied.
            'ratings' => $ratingCounts,
            'max_price' => $maxPrice,
            'price_step' => $step,
            'price_slider_max' => (int) (ceil(max(1, $maxPrice) / $step) * $step),
        ],
        'category' => count($categories) === 1 ? list_category_meta($categories[0]) : null,
        'stats' => [
            'courses' => (int) db_value("SELECT COUNT(*) {$from} WHERE {$published}"),
            'lessons' => (int) db_value("SELECT COUNT(*) FROM lessons l JOIN courses c ON c.id = l.course_id WHERE l.status = 'published' AND c.status = 'published'"),
            'categories' => (int) db_value("SELECT COUNT(DISTINCT c.category_id) {$from} WHERE {$published}"),
        ],
    ], 200, true);
}

/** SEO + heading data for /courses?category=<slug> (one category selected). */
function list_category_meta(string $slug): ?array
{
    $cat = db_one(
        'SELECT c.slug, c.name, c.short_name, c.description, c.icon, c.color, c.seo_title, c.seo_description,
                p.slug AS parent_slug, p.name AS parent_name
         FROM course_categories c LEFT JOIN course_categories p ON p.id = c.parent_id
         WHERE c.slug = ? AND c.is_active = 1',
        [$slug]
    );
    if (!$cat) {
        return null;
    }
    $site = setting('site_name', 'Laxyam Academy');
    return [
        'slug' => $cat['slug'],
        'name' => $cat['name'],
        'short_name' => $cat['short_name'],
        'description' => $cat['description'],
        'icon' => $cat['icon'],
        'color' => $cat['color'],
        'parent' => $cat['parent_slug'] ? ['slug' => $cat['parent_slug'], 'name' => $cat['parent_name']] : null,
        'seo_title' => $cat['seo_title'],
        'seo_description' => $cat['seo_description'],
        'seo' => [
            'title' => $cat['seo_title'] ?: $cat['name'] . ' Courses | ' . $site,
            'description' => $cat['seo_description'] ?: ($cat['description'] ? mb_substr(strip_tags($cat['description']), 0, 160) : null),
        ],
    ];
}

/**
 * Course page. Staff may also open draft/archived courses (admin "Preview as student"); students
 * still enrolled in an archived course (finished batch) can open it too. With
 * &as=student the lock flags are computed as for a student who is not enrolled.
 * The response is viewer-specific, so it is never publicly cached.
 */
function course_detail(): never
{
    $slug = query_str('slug', 200);
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        api_error('Course not found.', 404, 'not_found');
    }
    $user = current_user();
    $isStaff = course_viewer_is_staff($user);
    $asStudent = $isStaff && query_str('as', 20) === 'student';
    $row = db_one(
        'SELECT ' . course_card_columns() . ",
                c.status, c.description, c.promo_video_url, c.validity_days, c.learning_outcomes, c.includes,
                c.requirements, c.faqs, c.seo_title, c.seo_description, c.category_id, c.instructor_id,
                pre.slug AS prerequisite_slug, pre.title AS prerequisite_title,
                i.name AS instructor_name, i.slug AS instructor_slug, i.designation AS instructor_designation,
                i.bio AS instructor_bio, i.photo_path AS instructor_photo, i.experience_years AS instructor_experience,
                i.highlights AS instructor_highlights, i.is_demo AS instructor_is_demo
         FROM courses c
         JOIN course_categories cat ON cat.id = c.category_id
         LEFT JOIN courses pre ON pre.id = c.prerequisite_course_id
         LEFT JOIN instructors i ON i.id = c.instructor_id AND i.status = 'active'
         WHERE c.slug = ? AND " . ($isStaff ? '1=1' : "((c.status = 'published' AND cat.is_active = 1)
            OR (c.status = 'archived' AND EXISTS (SELECT 1 FROM course_enrolments ae WHERE ae.course_id = c.id AND ae.user_id = ?
                AND ae.status = 'active' AND (ae.expires_at IS NULL OR ae.expires_at > NOW()))))"), // finished batch: its students keep access
        $isStaff ? [$slug] : [$slug, (int) ($user['id'] ?? 0)]
    );
    if (!$row) {
        api_error('Course not found.', 404, 'not_found');
    }
    $courseId = (int) $row['id'];

    // Who is looking: enrolment unlocks everything, staff see everything (unless previewing as a student).
    $isStudent = $user !== null && !$isStaff;
    $enrolment = $isStudent ? active_enrolment((int) $user['id'], $courseId) : null;
    $unlocked = $enrolment !== null || ($isStaff && !$asStudent);

    // Curriculum outline only - media paths and URLs are never included here.
    $sections = db_all('SELECT id, title, description FROM course_sections WHERE course_id = ? ORDER BY sort_order, id', [$courseId]);
    $lessons = db_all(
        "SELECT id, section_id, title, lesson_type, duration_minutes, is_preview
         FROM lessons WHERE course_id = ? AND status = 'published' ORDER BY sort_order, id",
        [$courseId]
    );
    $bySection = [];
    foreach ($lessons as $l) {
        $bySection[(int) $l['section_id']][] = [
            'id' => (int) $l['id'],
            'title' => $l['title'],
            'type' => $l['lesson_type'],
            'duration_minutes' => $l['duration_minutes'] !== null ? (int) $l['duration_minutes'] : null,
            'is_preview' => (bool) $l['is_preview'],
            'locked' => !$unlocked && !$l['is_preview'],
        ];
    }
    $curriculum = array_map(fn($s) => [
        'id' => (int) $s['id'],
        'title' => $s['title'],
        'description' => $s['description'],
        'lessons' => $bySection[(int) $s['id']] ?? [],
    ], $sections);

    $counts = ['lessons' => count($lessons), 'videos' => 0, 'documents' => 0, 'quizzes' => 0, 'minutes' => 0, 'preview' => 0, 'locked' => 0];
    foreach ($lessons as $l) {
        $counts['minutes'] += (int) $l['duration_minutes'];
        $counts['preview'] += $l['is_preview'] ? 1 : 0;
        $counts['locked'] += !$unlocked && !$l['is_preview'] ? 1 : 0;
        match ($l['lesson_type']) {
            'video', 'external_video' => $counts['videos']++,
            'pdf' => $counts['documents']++,
            'quiz' => $counts['quizzes']++,
            default => null,
        };
    }
    $counts['live_classes'] = (int) db_value(
        "SELECT COUNT(*) FROM live_classes WHERE course_id = ? AND is_published = 1 AND status <> 'cancelled'",
        [$courseId]
    );

    $reviews = $row['rating_count'] > 0 ? db_all(
        "SELECT r.rating, r.review, r.created_at, u.name
         FROM course_reviews r JOIN users u ON u.id = r.user_id
         WHERE r.course_id = ? AND r.status = 'approved'
         ORDER BY r.created_at DESC LIMIT 10",
        [$courseId]
    ) : [];

    // Star histogram of APPROVED reviews: {"5": n, "4": n, "3": n, "2": n, "1": n}.
    $ratingBreakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    foreach (db_all("SELECT rating, COUNT(*) AS n FROM course_reviews WHERE course_id = ? AND status = 'approved' GROUP BY rating", [$courseId]) as $r) {
        if (isset($ratingBreakdown[(int) $r['rating']])) {
            $ratingBreakdown[(int) $r['rating']] = (int) $r['n'];
        }
    }

    $related =fetch_course_cards('c.category_id = ? AND c.id <> ?', [(int) $row['category_id'], $courseId], 'c.is_featured DESC, c.sort_order', 3);
    if (count($related) < 3) {
        $exclude = array_merge([$courseId], array_column($related, 'id'));
        $related = array_merge($related, fetch_course_cards(
            'c.id NOT IN (' . db_placeholders(count($exclude)) . ')', $exclude, 'c.is_featured DESC, c.sort_order', 3 - count($related)
        ));
    }

    // Viewer block: own enrolment, progress and review (the public list only shows approved reviews).
    $ownReview = $isStudent ? db_one('SELECT rating, review, status, created_at FROM course_reviews WHERE course_id = ? AND user_id = ?', [$courseId, $user['id']]) : null;
    $enrolStatus = $enrolment ? 'active' : ($isStudent ? db_value('SELECT status FROM course_enrolments WHERE user_id = ? AND course_id = ?', [$user['id'], $courseId]) : null);
    $hasProgress = $enrolment && ((float) $enrolment['progress_percent'] > 0
        || db_value('SELECT 1 FROM lesson_progress WHERE user_id = ? AND course_id = ? LIMIT 1', [$user['id'], $courseId]));
    $viewer = [
        'logged_in' => $user !== null,
        'is_staff' => $isStaff,
        'preview_mode' => $isStaff && ($asStudent || $row['status'] !== 'published'),
        'enrolled' => $enrolment !== null,
        'enrolment_status' => $enrolStatus ?: null, // active | expired | revoked | null
        'enrolment_reference' => $enrolment ? enrolment_reference((int) $enrolment['id']) : null,
        'next_lesson_id' => $enrolment ? course_next_lesson_id((int) $user['id'], $courseId) : null,
        'progress' => $enrolment ? (float) $enrolment['progress_percent'] : null,
        'enrolled_at' => $enrolment ? iso_datetime($enrolment['enrolled_at']) : null,
        'expires_at' => $enrolment ? iso_datetime($enrolment['expires_at']) : null,
        'completed_at' => $enrolment ? iso_datetime($enrolment['completed_at']) : null,
        'prerequisite_unmet' => $isStudent && !$enrolment ? unmet_prerequisite((int) $user['id'], $courseId) : null,
        'can_review' => (bool) $hasProgress,
        'review' => $ownReview ? [
            'rating' => (int) $ownReview['rating'],
            'review' => $ownReview['review'],
            'status' => $ownReview['status'], // pending | approved | rejected
            'date' => iso_datetime($ownReview['created_at']),
        ] : null,
    ];

    // Offline / classroom details (migration 017): fee items, subject-wise syllabus and extra sections.
    $extras = course_extras_enabled() ? db_one('SELECT offer_starts_at, fee_items, syllabus, info_sections FROM courses WHERE id = ?', [$courseId]) : null;
    // An offer runs while its window is open and it has a sale price or dates; then the sale price
    // and the fee items marked "free during the offer" apply.
    $onSale = course_effective_price($row) < (float) $row['price'];
    $offerRunning = course_offer_open($row) && ($onSale || !empty($row['offer_ends_at']) || !empty($extras['offer_starts_at']));

    $course = format_course_card($row) + [
        'status' => $row['status'], // archived = finished batch: closed to new admissions
        'offer' => $offerRunning ? ['starts_at' => iso_datetime($extras['offer_starts_at'] ?? null), 'ends_at' => iso_datetime($row['offer_ends_at'] ?? null)] : null,
        'fee_items' => course_fee_items($extras['fee_items'] ?? null),
        'syllabus' => course_syllabus($extras['syllabus'] ?? null),
        'info_sections' => course_info_sections($extras['info_sections'] ?? null),
        'batch' => course_batches_enabled() ? db_value('SELECT academic_session FROM courses WHERE id = ?', [$courseId]) : null,
        'prerequisite' => $row['prerequisite_slug'] ? ['slug' => $row['prerequisite_slug'], 'title' => $row['prerequisite_title']] : null,
        'description' => $row['description'],
        'promo_video_url' => $row['promo_video_url'],
        'validity_days' => $row['validity_days'] !== null ? (int) $row['validity_days'] : null,
        'learning_outcomes' => json_decode_array($row['learning_outcomes']),
        'includes' => json_decode_array($row['includes']),
        'requirements' => json_decode_array($row['requirements']),
        'faqs' => json_decode_array($row['faqs']),
        'counts' => $counts,
        'curriculum' => $curriculum,
        'instructor' => $row['instructor_name'] ? [
            'name' => $row['instructor_name'],
            'slug' => $row['instructor_slug'],
            'designation' => $row['instructor_designation'],
            'bio' => $row['instructor_bio'],
            'photo_url' => public_file_url($row['instructor_photo']),
            'experience_years' => $row['instructor_experience'] !== null ? (int) $row['instructor_experience'] : null,
            'highlights' => json_decode_array($row['instructor_highlights']),
            'is_demo' => (bool) $row['instructor_is_demo'],
        ] : null,
        'reviews' => array_map(fn($r) => [
            'name' => $r['name'],
            'rating' => (int) $r['rating'],
            'review' => $r['review'],
            'date' => iso_datetime($r['created_at']),
        ], $reviews),
        'rating_breakdown' => $ratingBreakdown,
        'upcoming_live_classes' => fetch_upcoming_live_classes(3, $courseId),
        'seo' => [
            'title' => $row['seo_title'] ?: $row['title'] . ' | ' . setting('site_name', 'Laxyam Academy'),
            'description' => $row['seo_description'] ?: $row['short_description'],
        ],
        'viewer' => $viewer, // same object as data.viewer, kept here for components that only receive `course`
    ];

    api_ok(['course' => $course, 'viewer' => $viewer, 'related' => $related]);
}
