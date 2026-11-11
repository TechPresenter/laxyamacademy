<?php
// Curriculum: Course → Sections → Lessons. Media is uploaded to private storage.
// Lock/unlock: a lesson is either a free preview (anyone, even guests) or locked (enrolled students only);
// access is enforced in api/lessons.php - this page only sets the flag.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';
require_once APP_ROOT . '/includes/catalog.php';

$courseId = (int) ($_GET['course'] ?? 0);
$course = $courseId ? db_one('SELECT id, title, slug, status FROM courses WHERE id = ?', [$courseId]) : null;

if (!$course) {
    admin_start('Sections & Lessons', 'lessons.manage', 'lessons', ['head' => true, 'subtitle' => 'Choose a course to manage its curriculum.']);
    $rows = db_all("SELECT c.id, c.title, c.status,
            (SELECT COUNT(*) FROM course_sections s WHERE s.course_id = c.id) AS sections,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lessons,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id AND l.is_preview = 1) AS previews
        FROM courses c ORDER BY c.sort_order, c.title");
    echo '<div class="card">';
    if (!$rows) {
        echo '<div class="empty"><span class="empty-ic">' . icon('book') . '</span><strong>No courses yet</strong><p>Create a course first, then build its sections and lessons here.</p>'
            . (can('courses.manage') ? '<a class="btn btn-primary btn-sm" href="courses.php?action=new">' . icon('plus') . ' New course</a>' : '') . '</div>';
    } else {
        echo '<div class="table-wrap"><table class="t stack"><thead><tr><th scope="col">Course</th><th scope="col">Sections</th><th scope="col">Lessons</th><th scope="col">Free previews</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $empty = (int) $r['lessons'] === 0;
            echo '<tr><td data-label="Course"><div class="d1-main"><a class="d1-t" href="?course=' . (int) $r['id'] . '">' . e($r['title']) . '</a>'
                . ($empty ? '<div class="d1-sub"><span>No lessons yet</span></div>' : '') . '</div></td>'
                . '<td data-label="Sections" class="d1-num">' . (int) $r['sections'] . '</td>'
                . '<td data-label="Lessons" class="d1-num">' . (int) $r['lessons'] . '</td>'
                . '<td data-label="Free previews" class="d1-num">' . ((int) $r['previews'] ? '<span class="badge b-green">' . (int) $r['previews'] . '</span>' : '<span class="muted">0</span>') . '</td>'
                . '<td data-label="Status">' . badge($r['status']) . '</td>'
                . '<td class="actions"><a class="btn btn-light btn-sm" href="?course=' . (int) $r['id'] . '">' . icon($empty ? 'plus' : 'list') . ($empty ? ' Add lessons' : ' Curriculum') . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    admin_end();
    exit;
}

$tabs = ['lessons' => 'Lessons', 'sections' => 'Sections'];
$tab = current_tab($tabs);
$tabNav = admin_tabs([
    'lessons' => ['label' => 'Lessons', 'icon' => 'play', 'count' => (int) db_value('SELECT COUNT(*) FROM lessons WHERE course_id = ?', [$courseId])],
    'sections' => ['label' => 'Sections', 'icon' => 'layers', 'count' => (int) db_value('SELECT COUNT(*) FROM course_sections WHERE course_id = ?', [$courseId])],
], $tab, ['keep' => ['course'], 'label' => 'Curriculum']);
$header = '<a class="btn btn-light" href="lessons.php">' . icon('left') . ' All courses</a> <a class="btn btn-light" href="' . e(app_url('courses/' . $course['slug']) . '?as=student') . '" target="_blank" rel="noopener" title="Course page as a non-enrolled student sees it">' . icon('eye') . ' Preview as student</a> ';
const LESSON_TYPES = ['video' => ['Video', 'video'], 'external_video' => ['YouTube / Vimeo', 'youtube'], 'pdf' => ['PDF', 'file'], 'text' => ['Text', 'type'], 'live' => ['Live class', 'live'], 'quiz' => ['Practice test', 'quiz']];

if ($tab === 'sections') {
    crud([
        'table' => 'course_sections', 'title' => 'Curriculum · ' . $course['title'], 'singular' => 'section', 'permission' => 'lessons.manage', 'active' => 'lessons',
        'tabs' => $tabNav, 'header_actions' => $header,
        'select' => 't.*, (SELECT COUNT(*) FROM lessons l WHERE l.section_id = t.id) AS n',
        'where' => 't.course_id = ?', 'where_params' => [$courseId], 'defaults' => ['course_id' => $courseId],
        'order' => 't.sort_order, t.id',
        'columns' => [
            'sort_order' => ['#', fn($r) => '<span class="d1-num muted">' . (int) $r['sort_order'] . '</span>'],
            'title' => ['Section', fn($r) => '<div class="d1-main"><span class="d1-t">' . e($r['title']) . '</span>' . ($r['description'] ? '<div class="d1-sub"><span>' . e(mb_strimwidth((string) $r['description'], 0, 120, '…')) . '</span></div>' : '') . '</div>'],
            'n' => ['Lessons', fn($r) => (int) $r['n'] ? '<a class="d1-num" href="?course=' . (int) $r['course_id'] . '&tab=lessons&section=' . (int) $r['id'] . '">' . (int) $r['n'] . ' lesson' . ((int) $r['n'] === 1 ? '' : 's') . '</a>' : '<span class="muted">No lessons</span>'],
        ],
        'can_delete' => fn($r) => (int) ($r['n'] ?? db_value('SELECT COUNT(*) FROM lessons WHERE section_id = ?', [$r['id']])) === 0,
        'before_save' => function (array $d, ?array $old) use ($courseId) {
            // crud_find() does not apply 'where', so make sure the edited section belongs to this course.
            if ($old && (int) $old['course_id'] !== $courseId) {
                throw new CrudError([], 'This section belongs to another course. Open it from that course’s curriculum.');
            }
            return $d;
        },
        'fields' => [
            'title' => ['label' => 'Section title', 'required' => true, 'max' => 180],
            'sort_order' => ['label' => 'Order (lowest first)', 'type' => 'number', 'default' => (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM course_sections WHERE course_id = ?', [$courseId])],
            'description' => ['label' => 'Description', 'max' => 500, 'full' => true],
        ],
    ]);
    exit;
}

$sections = fn() => options_from('SELECT id, title AS label FROM course_sections WHERE course_id = ? ORDER BY sort_order, id', [$courseId]);
if (!db_value('SELECT 1 FROM course_sections WHERE course_id = ? LIMIT 1', [$courseId]) && ($_GET['action'] ?? '') === 'new') {
    flash('info', 'Create a section first, then add lessons to it.');
    redirect_to('?course=' . $courseId . '&tab=sections&action=new');
}

/** Free preview / Locked badge with a lock icon - the access a visitor gets on the website. */
function lesson_access_badge(array $r): string
{
    $small = fn(string $name) => str_replace('<svg class="i"', '<svg class="i" style="width:12px;height:12px"', icon($name));
    return $r['is_preview']
        ? '<span class="badge b-green" title="Anyone can open this lesson, even without logging in">' . $small('eye') . ' Free preview</span>'
        : '<span class="badge b-gray" title="Only enrolled students can open this lesson">' . $small('lock') . ' Locked</span>';
}

crud([
    'table' => 'lessons', 'title' => 'Curriculum · ' . $course['title'], 'singular' => 'lesson', 'permission' => 'lessons.manage', 'active' => 'lessons',
    'tabs' => $tabNav, 'header_actions' => $header,
    'subtitle' => 'Lessons are shown by section order, then by each lesson’s order number. Free preview lessons can be opened by anyone; locked lessons need an active enrolment.',
    'from' => 'lessons t JOIN course_sections s ON s.id = t.section_id',
    'select' => 't.*, s.title AS section_title, s.sort_order AS s_order',
    'where' => 't.course_id = ?', 'where_params' => [$courseId], 'defaults' => ['course_id' => $courseId],
    'order' => 's.sort_order, s.id, t.sort_order, t.id', 'per_page' => 100,
    'search' => ['t.title'],
    'filters' => [
        'section' => ['col' => 't.section_id', 'label' => 'Sections', 'options' => $sections],
        'access' => ['col' => 't.is_preview', 'label' => 'Access', 'options' => ['1' => 'Free preview', '0' => 'Locked']],
    ],
    // Bulk lock / unlock. (Bulk delete stays off: deleting lessons wipes students' progress.)
    'bulk_status' => ['field' => 'is_preview', 'label' => 'access', 'options' => ['1' => 'Mark as free preview', '0' => 'Lock (enrolled only)']],
    'columns' => [
        'sort_order' => ['#', fn($r) => '<span class="d1-num"><span class="muted">' . (int) $r['s_order'] . '.</span>' . (int) $r['sort_order'] . '</span>'],
        'title' => ['Lesson', fn($r) => '<div class="d1-main"><a class="d1-t" href="' . e(url_with(['action' => 'edit', 'id' => $r['id'], 'page' => null])) . '">' . e($r['title']) . '</a><div class="d1-sub"><span>' . e($r['section_title']) . '</span></div></div>'],
        'is_preview' => ['Access', 'lesson_access_badge'],
        'lesson_type' => ['Type', fn($r) => '<span class="d1-nowrap" style="display:inline-flex;align-items:center;gap:6px">' . icon(LESSON_TYPES[$r['lesson_type']][1] ?? 'file') . e(LESSON_TYPES[$r['lesson_type']][0] ?? ucfirst((string) $r['lesson_type'])) . '</span>'],
        'media' => ['Media', function ($r) {
            $ok = match ($r['lesson_type']) {
                'video' => (bool) private_file_path($r['video_path']),
                'pdf' => (bool) private_file_path($r['file_path']),
                'external_video' => (bool) ($r['external_url'] && lesson_embed_url((string) $r['external_url'])),
                'quiz' => (bool) $r['exam_id'],
                'live' => (bool) $r['live_class_id'],
                default => trim((string) $r['content']) !== '',
            };
            return $ok ? '<span class="badge b-green">Ready</span>' : '<span class="badge b-amber">Missing</span>';
        }],
        'duration_minutes' => ['Duration', fn($r) => $r['duration_minutes'] ? '<span class="d1-num">' . (int) $r['duration_minutes'] . ' min</span>' : '<span class="muted">—</span>'],
        'status' => ['Status', fn($r) => badge($r['status'])],
    ],
    // Lessons with student progress cannot be deleted (set them to Draft instead).
    'can_delete' => fn($r) => !db_value('SELECT 1 FROM lesson_progress WHERE lesson_id = ? LIMIT 1', [$r['id']]),
    'before_save' => function (array $d, ?array $old) use ($courseId) {
        // crud_find() ignores 'where': refuse to edit a lesson of another course through this course's URL.
        if ($old && (int) $old['course_id'] !== $courseId) {
            throw new CrudError([], 'This lesson belongs to another course. Open it from that course’s curriculum.');
        }
        $d['course_id'] = $courseId;
        if (!db_value('SELECT 1 FROM course_sections WHERE id = ? AND course_id = ?', [$d['section_id'], $courseId])) {
            throw new CrudError(['section_id' => 'Choose a section of this course.']);
        }
        switch ($d['lesson_type']) {
            case 'external_video':
                if (!$d['external_url']) {
                    throw new CrudError(['external_url' => 'Add the YouTube/Vimeo link.']);
                }
                if (!lesson_embed_url((string) $d['external_url'])) {
                    throw new CrudError(['external_url' => 'Only YouTube (watch, youtu.be, shorts, live) and Vimeo links can be played. Upload other videos as a file.']);
                }
                break;
            case 'quiz':
                if ($d['exam_id']) {
                    $exam = db_one('SELECT course_id, access_type FROM exams WHERE id = ?', [$d['exam_id']]);
                    if ($exam && $exam['access_type'] === 'enrolled' && (int) $exam['course_id'] !== $courseId) {
                        throw new CrudError(['exam_id' => 'This test is only open to students of another course, so students of this course could not attempt it.']);
                    }
                }
                break;
        }
        if ($d['lesson_type'] !== 'pdf') {
            $d['is_downloadable'] = 0;
        }
        return $d;
    },
    'fields' => [
        'section_id' => ['label' => 'Section', 'type' => 'select', 'required' => true, 'options' => $sections],
        'title' => ['label' => 'Lesson title', 'required' => true, 'max' => 200],
        'lesson_type' => ['label' => 'Type', 'type' => 'select', 'required' => true, 'default' => 'video', 'options' => ['video' => 'Video (upload)', 'external_video' => 'YouTube / Vimeo link', 'pdf' => 'PDF document', 'text' => 'Text / article', 'live' => 'Live class', 'quiz' => 'Practice test']],
        'duration_minutes' => ['label' => 'Duration (minutes)', 'type' => 'number', 'min' => 0, 'max' => 1000],
        // Type-specific fields: 'show_if' hides them unless the matching type is chosen (assets/admin.js).
        'video_path' => ['label' => 'Video file (MP4/WebM) - private', 'type' => 'private_file', 'folder' => 'lessons', 'types' => ['video/mp4' => 'mp4', 'video/webm' => 'webm'], 'show_if' => 'lesson_type=video'],
        'file_path' => ['label' => 'PDF file - private', 'type' => 'private_file', 'folder' => 'lessons', 'types' => ['application/pdf' => 'pdf'], 'show_if' => 'lesson_type=pdf'],
        'external_url' => ['label' => 'YouTube / Vimeo URL (unlisted recommended)', 'type' => 'url', 'full' => true, 'hint' => 'Shown only to enrolled students (or everyone if Free preview).', 'show_if' => 'lesson_type=external_video'],
        'exam_id' => ['label' => 'Practice test', 'type' => 'select', 'options' => fn() => options_from("SELECT id, CONCAT(title, IF(status = 'published', '', CONCAT(' (', status, ')'))) AS label FROM exams ORDER BY title"), 'show_if' => 'lesson_type=quiz'],
        'live_class_id' => ['label' => 'Live class', 'type' => 'select', 'options' => fn() => options_from("SELECT id, CONCAT(title, ' - ', DATE_FORMAT(starts_at, '%d %b %Y %H:%i')) AS label FROM live_classes ORDER BY starts_at DESC"), 'show_if' => 'lesson_type=live'],
        'content' => ['label' => 'Notes / text content (HTML allowed)', 'type' => 'html', 'rows' => 8],
        'sort_order' => ['label' => 'Order within section (lowest first)', 'type' => 'number', 'min' => 0, 'max' => 32000, 'default' => (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM lessons WHERE course_id = ?', [$courseId])],
        'status' => ['label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'published', 'options' => ['published' => 'Published', 'draft' => 'Draft']],
        'is_preview' => ['label' => 'Free preview (anyone can open it, even without logging in)', 'type' => 'checkbox'],
        'is_downloadable' => ['label' => 'Allow PDF download', 'type' => 'checkbox', 'show_if' => 'lesson_type=pdf'],
    ],
]);
