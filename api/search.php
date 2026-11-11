<?php
/**
 * Global website search (header search box / type-ahead).
 *
 *   GET search.php?q=<2-100 chars>
 *   → { q, courses: [...], tests: [...], resources: [...], jobs: [...], blog: [...] }   (max 5 each, published only)
 *
 * Read-only, public, rate limited per IP. Titles that start with the query rank first.
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/catalog.php';
require_once APP_ROOT . '/includes/exams.php';
require_once APP_ROOT . '/includes/media.php';

require_method('GET');

const SEARCH_LIMIT = 5;

$q = trim((string) preg_replace('/\s+/u', ' ', query_str('q', 100)));
if (mb_strlen($q) < 2) {
    api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['q' => 'Type at least 2 characters.']);
}
if (!rate_limit('search', client_ip(), 120, 60)) {
    api_error('Too many searches. Please wait a moment and try again.', 429, 'rate_limited');
}

$like = '%' . addcslashes($q, '%_\\') . '%';
$prefix = addcslashes($q, '%_\\') . '%';
$limit = SEARCH_LIMIT;

// Courses (published, active category) - same card presenter as the catalogue
$courses = array_map(fn($c) => [
    'title' => $c['title'],
    'slug' => $c['slug'],
    'subtitle' => $c['subtitle'],
    'price' => $c['price'],
    'is_free' => $c['is_free'],
    'thumbnail_url' => $c['thumbnail_url'],
    'category' => ['name' => $c['category']['name'], 'slug' => $c['category']['slug'], 'color' => $c['category']['color'], 'icon' => $c['category']['icon']],
], fetch_course_cards(
    '(c.title LIKE ? OR c.subtitle LIKE ? OR c.short_description LIKE ? OR cat.name LIKE ?)',
    [$like, $like, $like, $like, $prefix],
    '(c.title LIKE ?) DESC, c.is_featured DESC, c.sort_order, c.id',
    $limit
));

// Mock tests and practice quizzes (published)
$tests = array_map(fn($t) => [
    'title' => $t['title'],
    'slug' => $t['slug'],
    'mode' => $t['mode'],
    // Questions asked per attempt: a random pool asks fewer than the bank holds
    'questions' => exam_questions_per_attempt($t, (int) $t['bank']),
    'duration_minutes' => (int) $t['duration_minutes'],
], db_all(
    "SELECT e.title, e.slug, e.mode, e.duration_minutes, e.question_pool_count,
            (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = e.id) AS bank
     FROM exams e
     WHERE e.status = 'published' AND (e.title LIKE ? OR e.subject LIKE ? OR e.description LIKE ?)
     ORDER BY (e.title LIKE ?) DESC, e.id DESC LIMIT {$limit}",
    [$like, $like, $like, $prefix]
));

// Free resources (published, active category)
$resources = db_all(
    "SELECT r.title, r.slug, r.file_type
     FROM learning_resources r JOIN resource_categories rc ON rc.id = r.category_id AND rc.is_active = 1
     WHERE r.status = 'published' AND (r.published_at IS NULL OR r.published_at <= NOW()) AND (r.title LIKE ? OR r.description LIKE ?)
     ORDER BY (r.title LIKE ?) DESC, r.published_at DESC, r.id DESC LIMIT {$limit}",
    [$like, $like, $prefix]
);

// Govt job alerts (published)
$jobs = array_map(fn($a) => [
    'title' => $a['title'],
    'slug' => $a['slug'],
    'type' => $a['alert_type'],
    'type_label' => JOB_ALERT_TYPES[$a['alert_type']] ?? $a['alert_type'],
    'organization' => $a['organization'],
    'last_date' => $a['last_date'],
], db_all(
    "SELECT title, slug, alert_type, organization, last_date FROM job_alerts
     WHERE status = 'published' AND (published_at IS NULL OR published_at <= NOW()) AND (title LIKE ? OR organization LIKE ?)
     ORDER BY (title LIKE ?) DESC, (last_date IS NOT NULL AND last_date < CURDATE()), published_at DESC LIMIT {$limit}",
    [$like, $like, $prefix]
));

// Blog articles (published or scheduled and already live)
$blog = db_all(
    "SELECT title, slug FROM blog_posts
     WHERE status IN ('published','scheduled') AND published_at <= NOW() AND (title LIKE ? OR excerpt LIKE ? OR tags LIKE ?)
     ORDER BY (title LIKE ?) DESC, published_at DESC LIMIT {$limit}",
    [$like, $like, $like, $prefix]
);

api_ok([
    'q' => $q,
    'courses' => $courses,
    'tests' => $tests,
    'resources' => $resources,
    'jobs' => $jobs,
    'blog' => $blog,
], 200, true);
