<?php
/**
 * Demo content from the installer (rows with is_demo = 1) and demo/test student
 * accounts (example.com addresses). Used by admin/demo-content.php and the go-live checklist.
 *
 * Removal never destroys real records: anything a real student used (orders, enrolments,
 * certificates, attempts, downloads, applications) is archived, closed or kept instead.
 * Staff accounts are never touched here. Archived/closed/blocked rows no longer count as demo content.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

const DEMO_STUDENT_WHERE = "user_type = 'student' AND status IN ('pending','active') AND (is_demo = 1 OR email LIKE '%@example.com' OR email LIKE '%@example.org' OR email LIKE '%@example.net')";

/** key => [label, SQL returning id + label, what happens to rows in use] - in removal order. */
function demo_groups(): array
{
    return [
        'students' => ['Demo & test student accounts', 'SELECT id, email AS label FROM users WHERE ' . DEMO_STUDENT_WHERE, 'Accounts with orders are blocked instead of deleted.'],
        'courses' => ['Demo courses', "SELECT id, title AS label FROM courses WHERE is_demo = 1 AND status <> 'archived'", 'Courses with orders, enrolments or certificates are archived (hidden) instead.'],
        'exams' => ['Demo mock tests', "SELECT id, title AS label FROM exams WHERE is_demo = 1 AND status <> 'archived'", 'Tests with attempts are archived instead.'],
        'live' => ['Demo live classes', 'SELECT id, title AS label FROM live_classes WHERE is_demo = 1', 'Classes with attendance records are kept.'],
        'resources' => ['Demo free resources', "SELECT id, title AS label FROM learning_resources WHERE is_demo = 1 AND status <> 'archived'", 'Resources with downloads are archived instead.'],
        'blog' => ['Demo blog posts', 'SELECT id, title AS label FROM blog_posts WHERE is_demo = 1', ''],
        'testimonials' => ['Demo testimonials', 'SELECT id, name AS label FROM testimonials WHERE is_demo = 1', ''],
        'instructors' => ['Demo instructors', 'SELECT id, name AS label FROM instructors WHERE is_demo = 1', 'Their courses and classes simply show no instructor.'],
        'jobs' => ['Demo job openings & job alerts', "SELECT id, CONCAT(title, ' (opening)') AS label FROM job_openings WHERE is_demo = 1 AND status <> 'closed' UNION ALL SELECT id, CONCAT(title, ' (job alert)') FROM job_alerts WHERE is_demo = 1", 'Openings with applications are closed instead.'],
    ];
}

/** group => number of demo rows */
function demo_counts(): array
{
    $out = [];
    foreach (demo_groups() as $key => [, $sql]) {
        $out[$key] = (int) db_value("SELECT COUNT(*) FROM ({$sql}) d");
    }
    return $out;
}

/**
 * Remove the chosen groups in a single transaction.
 * Returns group => ['deleted' => n, 'archived' => n, 'kept' => n].
 */
function remove_demo_content(array $groups): array
{
    $groups = array_values(array_intersect(array_keys(demo_groups()), $groups)); // fixed safe order
    return db_transaction(function () use ($groups): array {
        $summary = [];
        foreach ($groups as $g) {
            $s = ['deleted' => 0, 'archived' => 0, 'kept' => 0];
            $each = function (string $sql, callable $inUse, string $delete, ?string $archive) use (&$s): void {
                foreach (db_all($sql) as $r) {
                    $id = (int) $r['id'];
                    if (!$inUse($id)) {
                        db_query($delete, [$id]);
                        $s['deleted']++;
                    } elseif ($archive) {
                        db_query($archive, [$id]);
                        $s['archived']++;
                    } else {
                        $s['kept']++;
                    }
                }
            };
            $never = fn(int $id): bool => false;
            match ($g) {
                'students' => $each('SELECT id FROM users WHERE ' . DEMO_STUDENT_WHERE,
                    fn($id) => (bool) db_value('SELECT 1 FROM orders WHERE user_id = ? LIMIT 1', [$id]),
                    'DELETE FROM users WHERE id = ?', "UPDATE users SET status = 'inactive' WHERE id = ?"),
                'courses' => $each("SELECT id FROM courses WHERE is_demo = 1 AND status <> 'archived'",
                    fn($id) => (bool) db_value('SELECT EXISTS(SELECT 1 FROM order_items WHERE course_id = ?) OR EXISTS(SELECT 1 FROM course_enrolments WHERE course_id = ?) OR EXISTS(SELECT 1 FROM certificates WHERE course_id = ?)', [$id, $id, $id]),
                    'DELETE FROM courses WHERE id = ?', "UPDATE courses SET status = 'archived', is_featured = 0 WHERE id = ?"),
                'exams' => $each("SELECT id FROM exams WHERE is_demo = 1 AND status <> 'archived'",
                    fn($id) => (bool) db_value('SELECT 1 FROM exam_attempts WHERE exam_id = ? LIMIT 1', [$id]),
                    'DELETE FROM exams WHERE id = ?', "UPDATE exams SET status = 'archived' WHERE id = ?"),
                'live' => $each('SELECT id FROM live_classes WHERE is_demo = 1',
                    fn($id) => (bool) db_value('SELECT 1 FROM live_class_attendance WHERE live_class_id = ? LIMIT 1', [$id]),
                    'DELETE FROM live_classes WHERE id = ?', null),
                'resources' => $each("SELECT id FROM learning_resources WHERE is_demo = 1 AND status <> 'archived'",
                    fn($id) => (bool) db_value('SELECT 1 FROM resource_downloads WHERE resource_id = ? LIMIT 1', [$id]),
                    'DELETE FROM learning_resources WHERE id = ?', "UPDATE learning_resources SET status = 'archived' WHERE id = ?"),
                'blog' => $each('SELECT id FROM blog_posts WHERE is_demo = 1', $never, 'DELETE FROM blog_posts WHERE id = ?', null),
                'testimonials' => $each('SELECT id FROM testimonials WHERE is_demo = 1', $never, 'DELETE FROM testimonials WHERE id = ?', null),
                'instructors' => $each('SELECT id FROM instructors WHERE is_demo = 1', $never, 'DELETE FROM instructors WHERE id = ?', null),
                'jobs' => (function () use ($each, $never): void {
                    $each("SELECT id FROM job_openings WHERE is_demo = 1 AND status <> 'closed'",
                        fn($id) => (bool) db_value('SELECT 1 FROM job_applications WHERE opening_id = ? LIMIT 1', [$id]),
                        'DELETE FROM job_openings WHERE id = ?', "UPDATE job_openings SET status = 'closed' WHERE id = ?");
                    $each('SELECT id FROM job_alerts WHERE is_demo = 1', $never, 'DELETE FROM job_alerts WHERE id = ?', null);
                })(),
            };
            $summary[$g] = $s;
        }
        return $summary;
    });
}
