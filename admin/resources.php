<?php
// Learning resources + resource categories. Files are stored privately and served via api/resources.php?action=download.
// ?scope=course = Study Material: files linked to a course (Courses module); with &course=<id> it is the
// Study material tab of that course's workspace (includes/course-workspace.php).
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';
require_once APP_ROOT . '/includes/course-workspace.php';

$scopeCourse = ($_GET['scope'] ?? '') === 'course';
$wsCourse = $scopeCourse && !empty($_GET['course']) ? course_workspace_course((int) $_GET['course']) : null;

$tabs = ['resources' => 'Resources', 'categories' => 'Categories'];
$tab = current_tab($tabs);

if ($tab === 'categories' && !$scopeCourse) {
    crud([
        'table' => 'resource_categories',
        'title' => 'Free Resources',
        'singular' => 'category',
        'permission' => 'resources.manage',
        'active' => 'resources',
        'tabs' => admin_tabs($tabs, $tab),
        'select' => 't.*, (SELECT COUNT(*) FROM learning_resources r WHERE r.category_id = t.id) AS n',
        'order' => 't.sort_order, t.name',
        'columns' => [
            'name' => ['Name', fn($r) => '<div class="d1-main sm"><span class="d1-t"><span class="d1-dot" style="border-radius:3px;background:' . e($r['color'] ?: '#cbd5e1') . '"></span>' . e($r['name']) . '</span>'
                . ($r['subtitle'] ? '<div class="d1-sub"><span>' . e($r['subtitle']) . '</span></div>' : '') . '</div>'],
            'n' => ['Resources', fn($r) => (int) $r['n'] ? '<a href="resources.php?category=' . (int) $r['id'] . '">' . (int) $r['n'] . '</a>' : '0'],
            'is_active' => ['Status', fn($r) => badge($r['is_active'] ? 'active' : 'inactive')],
        ],
        'can_delete' => fn($r) => (int) $r['n'] === 0,
        'fields' => [
            'name' => ['label' => 'Name', 'required' => true, 'max' => 100],
            'slug' => ['label' => 'Slug', 'type' => 'slug', 'from' => 'name', 'required' => true, 'unique' => true],
            'subtitle' => ['label' => 'Subtitle', 'max' => 120],
            'icon' => ['label' => 'Icon', 'type' => 'select', 'options' => ['newspaper' => 'Newspaper', 'file' => 'File', 'book' => 'Book', 'globe' => 'Globe / Map', 'help' => 'Question', 'target' => 'Target', 'clipboard' => 'Clipboard', 'video' => 'Video']],
            'color' => ['label' => 'Colour', 'type' => 'color'],
            'sort_order' => ['label' => 'Sort order', 'type' => 'number', 'default' => 0],
            'description' => ['label' => 'Description', 'type' => 'textarea'],
            'is_active' => ['label' => 'Active', 'type' => 'checkbox', 'default' => 1],
        ],
    ]);
    exit;
}

$courseOptions = fn() => options_from('SELECT id, title AS label FROM courses ORDER BY title');
crud([
    'table' => 'learning_resources',
    'title' => $scopeCourse ? 'Study Material' : 'Free Resources',
    'singular' => $scopeCourse ? 'file' : 'resource',
    'permission' => 'resources.manage',
    'active' => 'resources',
    'subtitle' => $scopeCourse ? 'Notes, PDFs and other files for the students of a course. Students find them on the course in My Resources. To place a PDF inside the lesson order, add it as a PDF lesson in Curriculum instead.' : null,
    'tabs' => $scopeCourse ? ($wsCourse ? course_workspace($wsCourse, 'material') : '') : admin_tabs($tabs, $tab),
    'form_before' => $wsCourse ? fn() => course_workspace($wsCourse, 'material') : null,
    // One course's workspace: a fixed scope (kept in every link), not a filter the toolbar could clear.
    'where' => $wsCourse ? 't.course_id = ?' : ($scopeCourse ? 't.course_id IS NOT NULL' : '1=1'),
    'where_params' => $wsCourse ? [(int) $wsCourse['id']] : [],
    'empty' => $wsCourse ? ['title' => 'No study material for this course yet', 'text' => 'Add notes, PDFs or other files for the students of this course.', 'icon' => 'file'] : [],
    'from' => 'learning_resources t JOIN resource_categories rc ON rc.id = t.category_id LEFT JOIN courses c ON c.id = t.course_id',
    'select' => 't.*, rc.name AS category_name, c.title AS course_title',
    'order' => 't.id DESC',
    'search' => ['t.title', 't.description'],
    'sortable' => ['title' => 't.title', 'download_count' => 't.download_count', 'status' => 't.status'],
    'bulk_status' => ['field' => 'status', 'label' => 'status', 'options' => ['published' => 'Published', 'draft' => 'Draft', 'archived' => 'Archived']],
    'filters' => [
        'category' => ['col' => 't.category_id', 'label' => 'Categories', 'options' => fn() => options_from('SELECT id, name AS label FROM resource_categories ORDER BY sort_order')],
        'status' => ['col' => 't.status', 'label' => 'Statuses', 'options' => ['published' => 'Published', 'draft' => 'Draft', 'archived' => 'Archived']],
        'access' => ['col' => 't.access_type', 'label' => 'Access', 'options' => ['free' => 'Free for everyone', 'registered' => 'Logged-in students', 'enrolled' => 'Course students']],
        ...($scopeCourse && !$wsCourse ? ['course' => ['col' => 't.course_id', 'label' => 'Courses', 'options' => $courseOptions]] : []),
    ],
    'columns' => [
        'title' => ['Resource', fn($r) => '<div class="d1-main"><span class="d1-t" title="' . e($r['title']) . '">' . e($r['title']) . '</span><div class="d1-sub"><span>' . e($r['category_name']) . '</span>'
            . ($r['published_at'] && strtotime((string) $r['published_at']) > time() && $r['status'] === 'published' ? '<span class="badge b-amber">Scheduled ' . e(fmt_dt($r['published_at'], 'j M')) . '</span>' : '')
            . (!empty($r['is_demo']) ? '<span class="badge b-demo">Demo</span>' : '') . '</div></div>'],
        'file_type' => ['File', fn($r) => $r['file_path']
            ? (private_file_path($r['file_path']) ? '<span class="badge b-green">' . e(strtoupper((string) $r['file_type'])) . '</span>' . ($r['file_size'] ? '<small class="muted" style="display:block;font-size:12px">' . e(number_format((int) $r['file_size'] / 1048576, 1)) . ' MB</small>' : '') : '<span class="badge b-red" title="The stored file is not on disk">File missing</span>')
            : ($r['external_url'] ? '<span class="badge b-blue" title="Opens an external link">Link</span>' : '<span class="badge b-amber" title="Upload a file or add a link">Nothing attached</span>')],
        'access_type' => ['Access', fn($r) => '<div class="d1-stack">' . match ($r['access_type']) {
            'free' => '<span class="badge b-green">Free</span>',
            'registered' => '<span class="badge b-blue">Logged-in</span>',
            default => '<span class="badge b-purple">Course students</span>',
        } . ($r['course_title'] ? '<small class="d1-clip" style="min-width:0;max-width:200px" title="' . e($r['course_title']) . '">' . e($r['course_title']) . '</small>' : '') . '</div>'],
        'download_count' => ['Downloads', fn($r) => '<span class="d1-num">' . number_format((int) $r['download_count']) . '</span>'],
        'status' => ['Status', fn($r) => badge($r['status'])],
    ],
    // Bulk "Published" stamps published_at like a single save (a future date keeps it scheduled).
    'after_bulk' => function (array $ids, string $value) {
        if ($value === 'published' && $ids) {
            db_query('UPDATE learning_resources SET published_at = COALESCE(published_at, NOW()) WHERE id IN (' . db_placeholders(count($ids)) . ')', $ids);
        }
    },
    'before_save' => function (array $d, ?array $old) {
        if (isset($_POST['__stored']['file_path'])) {
            $d['file_size'] = $_POST['__stored']['file_path']['size'];
            $d['file_type'] = $_POST['__stored']['file_path']['type'];
        } elseif (array_key_exists('file_path', $d) && $d['file_path'] === null) {
            $d['file_size'] = null; // file removed (or never uploaded): its type goes too
            $d['file_type'] = null;
        }
        // A resource needs something to open: a stored file (new or kept) or an external link.
        $hasFile = array_key_exists('file_path', $d) ? $d['file_path'] !== null : !empty($old['file_path']);
        if (!$hasFile && !$d['external_url']) {
            throw new CrudError(['file_path' => 'Upload a file or add an external link below.', 'external_url' => 'Add a link or upload a file above.']);
        }
        if (($d['access_type'] === 'enrolled' || ($_GET['scope'] ?? '') === 'course') && !$d['course_id']) {
            throw new CrudError(['course_id' => 'Choose the course whose students may access this resource.']);
        }
        if ($d['status'] === 'published' && empty($old['published_at'])) {
            $d['published_at'] ??= date('Y-m-d H:i:s');
        }
        if (!$old) {
            $d['created_by'] = $GLOBALS['__admin_me']['id'] ?? null;
        }
        return $d;
    },
    'fields' => [
        'title' => ['label' => 'Title', 'required' => true, 'max' => 200],
        'slug' => ['label' => 'Slug', 'type' => 'slug', 'from' => 'title', 'required' => true, 'unique' => true],
        'category_id' => ['label' => 'Category', 'type' => 'select', 'required' => true, 'hint' => $scopeCourse ? 'The label shown on the file card, e.g. NCERT Notes or Previous Year Papers (add more in Free Resources → Resource Categories).' : null, 'options' => fn() => options_from('SELECT id, name AS label FROM resource_categories ORDER BY sort_order')],
        'access_type' => ['label' => 'Access', 'type' => 'select', 'required' => true, 'default' => $scopeCourse ? 'enrolled' : 'free', 'options' => ['free' => 'Free for everyone', 'registered' => 'Logged-in students', 'enrolled' => 'Students of the linked course'], 'hint' => 'Enforced on download: guests are asked to log in, other students see a locked card.'],
        'course_id' => ['label' => 'Linked course', 'type' => 'select', 'options' => $courseOptions, 'default' => $wsCourse ? (int) $wsCourse['id'] : null, 'required' => $scopeCourse, 'hint' => 'Required for “Students of the linked course”; also lists the resource under that course in My Resources.'],
        'file_path' => ['label' => 'File (PDF, MP4, MP3, ZIP, JPG, PNG) - stored privately', 'type' => 'private_file', 'folder' => 'resources'],
        'external_url' => ['label' => 'Or external link (official source, https://)', 'type' => 'url'],
        'thumbnail_path' => ['label' => 'Thumbnail', 'type' => 'image', 'folder' => 'resources', 'w' => 800, 'h' => 600],
        'description' => ['label' => 'Description', 'type' => 'textarea'],
        'seo_title' => ['label' => 'SEO title', 'max' => 160],
        'seo_description' => ['label' => 'SEO description', 'max' => 320],
        'published_at' => ['label' => 'Publish date', 'type' => 'datetime'],
        'status' => ['label' => 'Status', 'type' => 'select', 'required' => true, 'default' => $scopeCourse ? 'published' : 'draft', 'options' => ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived']],
        'is_demo' => ['label' => 'Demo content', 'type' => 'checkbox'],
    ],
]);
