<?php
/**
 * Course workspace: every admin screen that works on ONE course (its details form, curriculum,
 * study material, students and preview) shows the same course header with a numbered tab bar,
 * so everything about a course is reached from the course itself:
 *
 *   1 Details & page → courses.php?action=edit&id=…   (title, price, enrolment, page text, fees, syllabus…)
 *   2 Curriculum     → lessons.php?course=…            (sections → lessons: videos, PDFs, tests, live classes)
 *   3 Study material → resources.php?scope=course&course=…  (notes / files for this course's students)
 *   4 Students       → enrolments.php?course=…         (who is enrolled, progress, expiry)
 *   5 Preview        → course-preview.php?id=…         (the real course page + publish checklist)
 *
 * Tabs the staff member may not open are hidden; on a course that is not saved yet only Details works.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once APP_ROOT . '/includes/catalog.php';

/** The course with its category and the counts shown on the tab bar, or null. Cached per request. */
function course_workspace_course(int $id): ?array
{
    static $cache = [];
    if ($id <= 0) {
        return null;
    }
    return $cache[$id] ??= db_one(
        "SELECT c.*, cat.name AS category_name, cat.color AS category_color,
            (SELECT COUNT(*) FROM course_sections s WHERE s.course_id = c.id) AS ws_sections,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS ws_lessons,
            (SELECT COUNT(*) FROM learning_resources r WHERE r.course_id = c.id) AS ws_material,
            (SELECT COUNT(*) FROM course_enrolments e WHERE e.course_id = c.id AND e.status = 'active') AS ws_students
         FROM courses c JOIN course_categories cat ON cat.id = c.category_id WHERE c.id = ?",
        [$id]
    ) ?: null;
}

/** The workspace tabs of a course: key => [label, icon, href, permission, count, hint]. */
function course_workspace_tabs(?array $c): array
{
    $id = (int) ($c['id'] ?? 0);
    return [
        'details' => ['Details & page', 'edit', $id ? 'courses.php?action=edit&id=' . $id : 'courses.php?action=new', 'courses.manage', null,
            'Title, price, Enroll Now / Apply Now, course page text, highlights, fees, syllabus, FAQs, SEO and publishing'],
        'curriculum' => ['Curriculum', 'list', 'lessons.php?course=' . $id, 'lessons.manage', $c ? (int) $c['ws_lessons'] : null,
            'Sections and lessons: videos, YouTube links, PDF notes, text, practice tests and live classes'],
        'material' => ['Study material', 'file', 'resources.php?scope=course&course=' . $id, 'resources.manage', $c ? (int) $c['ws_material'] : null,
            'Downloadable notes and files for the students of this course (shown in My Resources)'],
        'students' => ['Students', 'users', 'enrolments.php?course=' . $id, 'enrolments.manage', $c ? (int) $c['ws_students'] : null,
            'Students enrolled in this course, their progress and access expiry'],
        'preview' => ['Preview', 'eye', 'course-preview.php?id=' . $id, 'courses.view', null,
            'The course page as students see it, on desktop and mobile, with a ready-to-publish checklist'],
    ];
}

/**
 * Header + tab bar of one course. $course = a courses row (or id); null = a new course that is not saved yet.
 * $active = one of the course_workspace_tabs() keys.
 */
function course_workspace(array|int|null $course, string $active): string
{
    $c = is_int($course) ? course_workspace_course($course) : (is_array($course) && !empty($course['id']) ? course_workspace_course((int) $course['id']) : null);
    $tabs = course_workspace_tabs($c);

    if ($c) {
        $color = (string) ($c['category_color'] ?: '#0B63F6');
        $thumb = public_file_url($c['thumbnail_path'] ?? null);
        $price = course_effective_price($c);
        $admission = course_admission($c);
        $meta = [
            badge((string) $c['status']),
            '<span><span class="d1-dot" style="background:' . e($color) . '"></span>' . e((string) $c['category_name']) . '</span>',
            '<span>' . e(COURSE_TYPE_LABELS[$c['course_type']] ?? ucfirst((string) $c['course_type'])) . '</span>',
            '<span>' . ($c['course_type'] === 'free' ? 'Free' : e(money($price)) . ($price < (float) $c['price'] ? ' <s class="muted">' . e(money($c['price'])) . '</s>' : '')) . '</span>',
            '<span title="How students join this course">' . e(['online' => 'Enroll Now', 'both' => 'Enroll Now + Apply Now', 'enquiry' => 'Apply Now only'][$admission]) . '</span>',
        ];
        $h = '<section class="cw card" aria-label="Course workspace: ' . e((string) $c['title']) . '">'
            . '<div class="cw-head">'
            . ($thumb ? '<img class="cw-thumb" src="' . e($thumb) . '" alt="">' : '<span class="cw-thumb" style="background:' . e($color) . '">' . icon('book') . '</span>')
            . '<div class="cw-txt"><span class="cw-kicker">Course</span><h2 title="' . e((string) $c['title']) . '">' . e((string) $c['title']) . '</h2>'
            . '<div class="cw-meta">' . implode('', $meta) . '</div></div>'
            . '<div class="cw-acts"><a class="btn btn-light btn-sm" href="courses.php">' . icon('left') . ' All courses</a>'
            . '<a class="btn btn-light btn-sm" href="' . e(app_url('courses/' . $c['slug']) . ($c['status'] === 'published' ? '' : '?as=student')) . '" target="_blank" rel="noopener">' . icon('external') . ' Open on website</a></div>'
            . '</div>';
    } else {
        $h = '<section class="cw card" aria-label="New course">'
            . '<div class="cw-head"><span class="cw-thumb" style="background:#0B63F6">' . icon('plus') . '</span>'
            . '<div class="cw-txt"><span class="cw-kicker">New course</span><h2>Step 1: fill in the details and save</h2>'
            . '<div class="cw-meta"><span>After saving, the Curriculum, Study material, Students and Preview tabs open for this course.</span></div></div></div>';
    }

    $h .= '<nav class="cw-tabs" aria-label="Course workspace"><ol>';
    $n = 0;
    foreach ($tabs as $key => [$label, $ico, $href, $perm, $count, $hint]) {
        if (!can($perm)) {
            continue;
        }
        $n++;
        $inner = '<span class="cw-n" aria-hidden="true">' . $n . '</span>' . icon($ico) . '<span class="cw-l">' . e($label) . '</span>'
            . ($count !== null ? '<span class="tab-count">' . number_format($count) . '</span>' : '');
        if (!$c && $key !== 'details') {
            $h .= '<li><span class="cw-tab off" title="Save the course first">' . $inner . '</span></li>';
        } elseif ($key === $active) {
            $h .= '<li><a class="cw-tab on" aria-current="page" href="' . e($href) . '" title="' . e($hint) . '">' . $inner . '</a></li>';
        } else {
            $h .= '<li><a class="cw-tab" href="' . e($href) . '" title="' . e($hint) . '">' . $inner . '</a></li>';
        }
    }
    $h .= '</ol></nav>';
    $hint = $tabs[$active][5] ?? '';
    return $h . ($hint !== '' ? '<p class="cw-hint">' . icon('info') . '<span>' . e($hint) . '</span></p>' : '') . '</section>';
}
