<?php
// Curriculum of one course (tab 2 of the course workspace, includes/course-workspace.php):
// Course → Sections → Lessons. Media is uploaded to private storage.
//   ?course=<id>                 Outline: every section with its lessons, "Add lesson" per section
//   ?course=<id>&tab=lessons     all lessons as a table (search, filters, bulk lock/unlock)
//   ?course=<id>&tab=sections    sections as a table
// Add/edit forms opened from the outline keep tab=outline (&what=section for sections), so Save and
// Cancel come back to the outline.
// Lock/unlock: a lesson is either a free preview (anyone, even guests) or locked (enrolled students only);
// access is enforced in api/lessons.php - this page only sets the flag.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';
require_once APP_ROOT . '/includes/catalog.php';
require_once APP_ROOT . '/includes/course-workspace.php';

$courseId = (int) ($_GET['course'] ?? 0);
$course = $courseId ? db_one('SELECT id, title, slug, status, course_type FROM courses WHERE id = ?', [$courseId]) : null;

if (!$course) {
    admin_start('Curriculum & Lessons', 'lessons.manage', 'lessons', ['head' => true, 'subtitle' => 'Choose a course to build its sections and lessons. You can also open any course from All Courses and use its Curriculum tab.']);
    $rows = db_all("SELECT c.id, c.title, c.status,
            (SELECT COUNT(*) FROM course_sections s WHERE s.course_id = c.id) AS sections,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lessons,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id AND l.is_preview = 1) AS previews
        FROM courses c ORDER BY FIELD(c.status, 'published', 'draft', 'archived'), c.sort_order, c.title");
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
                . '<td class="actions"><a class="btn btn-light btn-sm" href="?course=' . (int) $r['id'] . '">' . icon($empty ? 'plus' : 'list') . ($empty ? ' Add lessons' : ' Open curriculum') . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    admin_end();
    exit;
}

const LESSON_TYPES = ['video' => ['Video', 'video'], 'external_video' => ['YouTube / Vimeo', 'youtube'], 'pdf' => ['PDF', 'file'], 'text' => ['Text', 'type'], 'live' => ['Live class', 'live'], 'quiz' => ['Practice test', 'quiz']];

$tabs = ['outline' => 'Outline', 'lessons' => 'All lessons', 'sections' => 'Sections'];
$tab = current_tab($tabs);
// Forms opened from the outline: what=section edits a section, anything else a lesson.
$what = $tab === 'outline' && in_array($_GET['action'] ?? '', ['new', 'edit'], true) ? (($_GET['what'] ?? '') === 'section' ? 'section' : 'lesson') : null;
$nLessons = (int) db_value('SELECT COUNT(*) FROM lessons WHERE course_id = ?', [$courseId]);
$nSections = (int) db_value('SELECT COUNT(*) FROM course_sections WHERE course_id = ?', [$courseId]);
$tabNav = admin_tabs([
    'outline' => ['label' => 'Outline', 'icon' => 'layers'],
    'lessons' => ['label' => 'All lessons', 'icon' => 'play', 'count' => $nLessons],
    'sections' => ['label' => 'Sections', 'icon' => 'list', 'count' => $nSections],
], $tab === 'outline' ? 'outline' : $tab, ['keep' => ['course'], 'label' => 'Curriculum views', 'covered' => false]);
$workspace = course_workspace($courseId, 'curriculum');
$header = '<a class="btn btn-light" href="' . e(app_url('courses/' . $course['slug']) . '?as=student') . '" target="_blank" rel="noopener" title="Course page as a non-enrolled student sees it">' . icon('eye') . ' Preview as student</a> ';
$outlineUrl = 'lessons.php?course=' . $courseId;
// Outline links: add / edit forms that come back to the outline.
$newLesson = fn(?int $sectionId = null) => $outlineUrl . '&tab=outline&action=new' . ($sectionId ? '&section=' . $sectionId : '');
$editLesson = fn(int $id) => $outlineUrl . '&tab=outline&action=edit&id=' . $id;
$newSection = $outlineUrl . '&tab=outline&what=section&action=new';
$editSection = fn(int $id) => $outlineUrl . '&tab=outline&what=section&action=edit&id=' . $id;

/** Free preview / Locked badge with a lock icon - the access a visitor gets on the website. */
function lesson_access_badge(array $r): string
{
    $small = fn(string $name) => str_replace('<svg class="i"', '<svg class="i" style="width:12px;height:12px"', icon($name));
    return $r['is_preview']
        ? '<span class="badge b-green" title="Anyone can open this lesson, even without logging in">' . $small('eye') . ' Free preview</span>'
        : '<span class="badge b-gray" title="Only enrolled students can open this lesson">' . $small('lock') . ' Locked</span>';
}

/** Whether a lesson has its media / content (video file, PDF, link, test, live class or text). */
function lesson_media_ready(array $r): bool
{
    return match ($r['lesson_type']) {
        'video' => (bool) private_file_path($r['video_path']),
        'pdf' => (bool) private_file_path($r['file_path']),
        'external_video' => (bool) ($r['external_url'] && lesson_embed_url((string) $r['external_url'])),
        'quiz' => (bool) $r['exam_id'],
        'live' => (bool) $r['live_class_id'],
        default => trim((string) $r['content']) !== '',
    };
}

// ---------------- Outline (default) ----------------
if ($tab === 'outline' && !$what) {
    admin_start('Curriculum', 'lessons.manage', 'lessons', [
        'head' => true,
        'crumbs' => '<a href="courses.php">Courses</a> / Curriculum',
        'actions' => $header . ($nSections ? '<a class="btn btn-light" href="' . e($newSection) . '">' . icon('plus') . ' Add section</a><a class="btn btn-primary" href="' . e($newLesson()) . '">' . icon('plus') . ' Add lesson</a>' : ''),
    ]);
    echo $workspace . $tabNav;
    $sections = db_all('SELECT id, title, description FROM course_sections WHERE course_id = ? ORDER BY sort_order, id', [$courseId]);
    $bySection = [];
    foreach (db_all('SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order, id', [$courseId]) as $l) {
        $bySection[(int) $l['section_id']][] = $l;
    }
    if (!$sections) {
        $offline = $course['course_type'] === 'offline';
        echo '<div class="card"><div class="card-head"><h2>' . icon('layers') . ' Build the curriculum in two steps</h2></div>'
            . ($offline ? '<p class="cw-hint" style="border-top:0">' . icon('info') . '<span>This is an offline classroom course: online lessons are optional. Add them if students should also watch videos or read notes online after they enrol.</span></p>' : '')
            . '<div class="co-steps">'
            . '<div class="co-step"><span class="co-n">1</span><div><strong>Add a section</strong><p>A chapter or subject of the course, e.g. “Mathematics”, “Week 1” or “Previous year papers”.</p><a class="btn btn-primary btn-sm" href="' . e($newSection) . '">' . icon('plus') . ' Add the first section</a></div></div>'
            . '<div class="co-step"><span class="co-n">2</span><div><strong>Add lessons to it</strong><p>Each lesson is a video (upload or YouTube link), a PDF, a text note, a practice test or a live class. Mark 1-2 lessons as free preview.</p></div></div>'
            . '</div></div>';
        admin_end();
        exit;
    }
    echo '<div class="card card-pad">';
    foreach ($sections as $si => $s) {
        $items = $bySection[(int) $s['id']] ?? [];
        $mins = array_sum(array_map(fn($l) => (int) $l['duration_minutes'], $items));
        echo '<section class="co-sec" aria-labelledby="co-s' . (int) $s['id'] . '"><div class="co-head"><span class="co-n">' . ($si + 1) . '</span>'
            . '<div class="co-t"><strong id="co-s' . (int) $s['id'] . '">' . e($s['title']) . '</strong><small>' . e(plural_n(count($items), 'lesson')) . ($mins ? ' · ' . $mins . ' min' : '')
            . ($s['description'] ? ' · ' . e(mb_strimwidth((string) $s['description'], 0, 90, '…')) : '') . '</small></div>'
            . '<div class="co-acts"><a class="btn btn-primary btn-sm" href="' . e($newLesson((int) $s['id'])) . '">' . icon('plus') . ' Add lesson</a>'
            . '<a class="btn btn-light btn-sm" href="' . e($editSection((int) $s['id'])) . '">' . icon('edit') . ' Edit section</a></div></div>';
        if (!$items) {
            echo '<p class="co-empty">No lessons in this section yet. Press <strong>Add lesson</strong> to add a video, PDF, note or test.</p>';
        } else {
            echo '<ol class="co-list">';
            foreach ($items as $l) {
                [$typeLabel, $typeIcon] = LESSON_TYPES[$l['lesson_type']] ?? [ucfirst((string) $l['lesson_type']), 'file'];
                echo '<li><span class="co-ic" aria-hidden="true">' . icon($typeIcon) . '</span>'
                    . '<div class="co-main"><a href="' . e($editLesson((int) $l['id'])) . '">' . e($l['title']) . '</a><small>' . e($typeLabel) . ($l['duration_minutes'] ? ' · ' . (int) $l['duration_minutes'] . ' min' : '') . '</small></div>'
                    . '<div class="co-tags">' . lesson_access_badge($l)
                    . (lesson_media_ready($l) ? '' : '<span class="badge b-amber" title="The video, file, link or content is not added yet">Content missing</span>')
                    . ($l['status'] !== 'published' ? badge($l['status']) : '')
                    . '<a class="btn btn-light btn-sm" href="' . e($editLesson((int) $l['id'])) . '">' . icon('edit') . ' Edit</a></div></li>';
            }
            echo '</ol>';
        }
        echo '</section>';
    }
    echo '<p class="hint" style="margin:14px 2px 0">Order: sections and lessons are shown by their order number (lowest first) - change it in Edit. Delete lessons or sections from the <a href="' . e($outlineUrl . '&tab=lessons') . '">All lessons</a> and <a href="' . e($outlineUrl . '&tab=sections') . '">Sections</a> tabs.</p>';
    echo '</div>';
    admin_end();
    exit;
}

// ---------------- Sections ----------------
if ($tab === 'sections' || $what === 'section') {
    crud([
        'table' => 'course_sections', 'title' => 'Sections', 'singular' => 'section', 'permission' => 'lessons.manage', 'active' => 'lessons',
        'tabs' => $workspace . $tabNav, 'header_actions' => $header, 'form_before' => fn() => $workspace,
        'subtitle' => 'Chapters or subjects of the course. Each lesson belongs to one section.',
        'select' => 't.*, (SELECT COUNT(*) FROM lessons l WHERE l.section_id = t.id) AS n',
        'where' => 't.course_id = ?', 'where_params' => [$courseId], 'defaults' => ['course_id' => $courseId],
        'order' => 't.sort_order, t.id',
        // A new section opened from the outline continues with its first lesson.
        'after_save_redirect' => fn($id) => $what === 'section' ? (($_GET['action'] ?? '') === 'new' ? $newLesson((int) $id) : $outlineUrl) : url_with(['action' => null, 'id' => null]),
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
            'title' => ['label' => 'Section title', 'required' => true, 'max' => 180, 'placeholder' => 'e.g. Mathematics, Week 1, Previous year papers'],
            'sort_order' => ['label' => 'Order (lowest first)', 'type' => 'number', 'default' => (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM course_sections WHERE course_id = ?', [$courseId])],
            'description' => ['label' => 'Description (optional)', 'max' => 500, 'full' => true],
        ],
    ]);
    exit;
}

// ---------------- Lessons ----------------
$sections = fn() => options_from('SELECT id, title AS label FROM course_sections WHERE course_id = ? ORDER BY sort_order, id', [$courseId]);
if (!$nSections && ($_GET['action'] ?? '') === 'new') {
    flash('info', 'Create a section first (e.g. “Mathematics” or “Week 1”), then add lessons to it.');
    redirect_to($newSection);
}
// A lesson added from a section's "Add lesson" button starts in that section, after its last lesson.
$presetSection = (int) ($_GET['section'] ?? 0);
$presetSection = $presetSection && db_value('SELECT 1 FROM course_sections WHERE id = ? AND course_id = ?', [$presetSection, $courseId]) ? $presetSection : null;
$nextOrder = $presetSection
    ? (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM lessons WHERE section_id = ?', [$presetSection])
    : (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM lessons WHERE course_id = ?', [$courseId]);

crud([
    'table' => 'lessons', 'title' => $what ? 'Lesson' : 'All lessons', 'singular' => 'lesson', 'permission' => 'lessons.manage', 'active' => 'lessons',
    'tabs' => $workspace . $tabNav, 'header_actions' => $header, 'form_before' => fn() => $workspace,
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
    // From the outline, Save goes back to the outline.
    'after_save_redirect' => fn($id) => $what ? $outlineUrl . '#co-s' . (int) db_value('SELECT section_id FROM lessons WHERE id = ?', [$id]) : url_with(['action' => null, 'id' => null]),
    // Bulk lock / unlock. (Bulk delete stays off: deleting lessons wipes students' progress.)
    'bulk_status' => ['field' => 'is_preview', 'label' => 'access', 'options' => ['1' => 'Mark as free preview', '0' => 'Lock (enrolled only)']],
    'columns' => [
        'sort_order' => ['#', fn($r) => '<span class="d1-num"><span class="muted">' . (int) $r['s_order'] . '.</span>' . (int) $r['sort_order'] . '</span>'],
        'title' => ['Lesson', fn($r) => '<div class="d1-main"><a class="d1-t" href="' . e(url_with(['action' => 'edit', 'id' => $r['id'], 'page' => null])) . '">' . e($r['title']) . '</a><div class="d1-sub"><span>' . e($r['section_title']) . '</span></div></div>'],
        'is_preview' => ['Access', 'lesson_access_badge'],
        'lesson_type' => ['Type', fn($r) => '<span class="d1-nowrap" style="display:inline-flex;align-items:center;gap:6px">' . icon(LESSON_TYPES[$r['lesson_type']][1] ?? 'file') . e(LESSON_TYPES[$r['lesson_type']][0] ?? ucfirst((string) $r['lesson_type'])) . '</span>'],
        'media' => ['Content', fn($r) => lesson_media_ready($r) ? '<span class="badge b-green">Ready</span>' : '<span class="badge b-amber">Missing</span>'],
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
        'section_id' => ['label' => 'Section', 'type' => 'select', 'required' => true, 'options' => $sections, 'default' => $presetSection],
        'title' => ['label' => 'Lesson title', 'required' => true, 'max' => 200],
        'lesson_type' => ['label' => 'Lesson type', 'type' => 'select', 'required' => true, 'default' => 'video', 'options' => ['video' => 'Video (upload a file)', 'external_video' => 'YouTube / Vimeo link', 'pdf' => 'PDF notes', 'text' => 'Text / article', 'live' => 'Live class', 'quiz' => 'Practice test'],
            'hint' => 'The fields below change with the type you choose.'],
        'duration_minutes' => ['label' => 'Duration (minutes)', 'type' => 'number', 'min' => 0, 'max' => 1000],
        // Type-specific fields: 'show_if' hides them unless the matching type is chosen (assets/admin.js).
        'video_path' => ['label' => 'Video file (MP4/WebM) - private', 'type' => 'private_file', 'folder' => 'lessons', 'types' => ['video/mp4' => 'mp4', 'video/webm' => 'webm'], 'show_if' => 'lesson_type=video'],
        'file_path' => ['label' => 'PDF file - private', 'type' => 'private_file', 'folder' => 'lessons', 'types' => ['application/pdf' => 'pdf'], 'show_if' => 'lesson_type=pdf'],
        'external_url' => ['label' => 'YouTube / Vimeo URL (unlisted recommended)', 'type' => 'url', 'full' => true, 'hint' => 'Shown only to enrolled students (or everyone if Free preview).', 'show_if' => 'lesson_type=external_video'],
        'exam_id' => ['label' => 'Practice test', 'type' => 'select', 'options' => fn() => options_from("SELECT id, CONCAT(title, IF(status = 'published', '', CONCAT(' (', status, ')'))) AS label FROM exams ORDER BY title"), 'show_if' => 'lesson_type=quiz'],
        'live_class_id' => ['label' => 'Live class', 'type' => 'select', 'options' => fn() => options_from("SELECT id, CONCAT(title, ' - ', DATE_FORMAT(starts_at, '%d %b %Y %H:%i')) AS label FROM live_classes ORDER BY starts_at DESC"), 'show_if' => 'lesson_type=live'],
        'content' => ['label' => 'Notes / text content (HTML allowed)', 'type' => 'html', 'rows' => 8],
        'sort_order' => ['label' => 'Order within section (lowest first)', 'type' => 'number', 'min' => 0, 'max' => 32000, 'default' => $nextOrder],
        'status' => ['label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'published', 'options' => ['published' => 'Published', 'draft' => 'Draft']],
        'is_preview' => ['label' => 'Free preview (anyone can open it, even without logging in)', 'type' => 'checkbox'],
        'is_downloadable' => ['label' => 'Allow PDF download', 'type' => 'checkbox', 'show_if' => 'lesson_type=pdf'],
    ],
]);
