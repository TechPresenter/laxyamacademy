<?php
// Courses: details, pricing, highlights, SEO and publication. Curriculum is managed in lessons.php.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';
require_once APP_ROOT . '/includes/catalog.php';

$categories = fn() => options_from('SELECT id, name AS label FROM course_categories ORDER BY sort_order, name');
$instructors = fn() => options_from("SELECT id, name AS label FROM instructors WHERE status = 'active' ORDER BY name");
// A course can never be its own prerequisite, so the course being edited is not offered.
$editingId = (int) ($_GET['id'] ?? 0);
$prerequisites = fn() => options_from('SELECT id, title AS label FROM courses WHERE id <> ? ORDER BY title', [$editingId]);

const COURSE_HIGHLIGHT_ROWS_MAX = 12;

/** Highlights rows posted by the editor below → JSON for courses.highlights (or a CrudError). */
function posted_course_highlights(): string
{
    $icons = (array) ($_POST['hl_icon'] ?? []);
    $texts = (array) ($_POST['hl_text'] ?? []);
    $rows = [];
    foreach ($texts as $i => $text) {
        $text = trim(strip_tags(preg_replace('/[^\P{C}]/u', '', (string) $text) ?? '')); // plain text only
        if ($text === '') {
            continue; // blank rows are skipped
        }
        if (mb_strlen($text) > 120) {
            throw new CrudError(['highlights' => 'Too long'], 'Course highlights: each highlight can be at most 120 characters.');
        }
        $icon = (string) ($icons[$i] ?? '');
        $rows[] = ['icon' => isset(COURSE_HIGHLIGHT_ICONS[$icon]) ? $icon : 'checkCircle', 'text' => $text];
    }
    if (count($rows) > COURSE_HIGHLIGHT_ROWS_MAX) {
        throw new CrudError(['highlights' => 'Too many'], 'Course highlights: add at most ' . COURSE_HIGHLIGHT_ROWS_MAX . ' highlights.');
    }
    return json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Repeater for course highlights (icon + text). Row controls use admin.js [data-blocks]. */
function course_highlights_editor(?array $row): string
{
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $items = [];
    if ($isPost) {
        foreach ((array) ($_POST['hl_text'] ?? []) as $i => $t) {
            $items[] = ['icon' => (string) (($_POST['hl_icon'] ?? [])[$i] ?? ''), 'text' => (string) $t];
        }
    } else {
        $items = course_highlights($row['highlights'] ?? null);
    }
    $items = array_values(array_filter($items, fn($h) => trim($h['text']) !== ''));
    $items[] = ['icon' => 'checkCircle', 'text' => '']; // always one empty row to type into / duplicate

    $h = '<span class="lbl" id="hl-label">Course highlights</span>'
        . '<div class="hint" style="margin-bottom:8px">Short points shown in the highlights strip on the course page and course cards, e.g. “Live doubt-clearing every Sunday”. Up to '
        . COURSE_HIGHLIGHT_ROWS_MAX . '. Use the copy button to add a row; empty rows are ignored.</div>'
        . '<div data-blocks role="group" aria-labelledby="hl-label">';
    foreach ($items as $n => $item) {
        $h .= '<div data-block style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap">'
            . '<select class="in" name="hl_icon[]" style="width:auto;min-width:170px" aria-label="Icon for highlight ' . ($n + 1) . '">';
        foreach (COURSE_HIGHLIGHT_ICONS as $key => $label) {
            $h .= '<option value="' . e($key) . '"' . ($key === $item['icon'] ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $h .= '</select>'
            . '<input class="in" type="text" name="hl_text[]" maxlength="120" value="' . e($item['text']) . '" placeholder="e.g. 250+ hours of recorded lectures" style="flex:1;min-width:200px" aria-label="Highlight ' . ($n + 1) . ' text">'
            . course_row_buttons() . '</div>';
    }
    return $h . '</div>' . (course_extras_enabled() ? course_extras_editor($row) : '');
}

/** Move / add (duplicate) / remove buttons of one repeater row (admin.js [data-blocks]). */
function course_row_buttons(): string
{
    return '<span style="display:inline-flex;gap:4px">'
        . '<button type="button" class="btn btn-light btn-sm" data-move="up" aria-label="Move up">' . icon('up') . '</button>'
        . '<button type="button" class="btn btn-light btn-sm" data-move="down" aria-label="Move down">' . icon('down') . '</button>'
        . '<button type="button" class="btn btn-light btn-sm" data-dup aria-label="Add a row (duplicate)">' . icon('copy') . '</button>'
        . '<button type="button" class="btn btn-danger btn-sm" data-del aria-label="Remove row">' . icon('trash') . '</button>'
        . '</span>';
}

/** Posted rows of a repeater: [[field => value, …], …] from parallel arrays $_POST[prefix_field][]. */
function posted_course_rows(string $prefix, array $fields): array
{
    $rows = [];
    foreach ((array) ($_POST[$prefix . '_' . $fields[0]] ?? []) as $i => $_) {
        $row = [];
        foreach ($fields as $f) {
            $row[$f] = (string) (((array) ($_POST[$prefix . '_' . $f] ?? []))[$i] ?? '');
        }
        $rows[] = $row;
    }
    return $rows;
}

/** Plain text from a form input: no tags, no control characters (line breaks kept when $multiline). */
function course_plain(string $text, bool $multiline = false): string
{
    $text = strip_tags($text);
    $text = $multiline ? str_replace("\r\n", "\n", $text) : (string) preg_replace('/\s+/u', ' ', $text);
    return trim((string) preg_replace($multiline ? '/[^\P{C}\n]/u' : '/[^\P{C}]/u', '', $text));
}

/** Fee items posted by the editor → JSON for courses.fee_items (or a CrudError). */
function posted_course_fees(): string
{
    $out = [];
    foreach (posted_course_rows('fee', ['group', 'label', 'amount', 'note', 'free']) as $r) {
        $label = course_plain($r['label']);
        if ($label === '') {
            continue;
        }
        $amount = str_replace([',', '₹', ' '], '', $r['amount']);
        if (!is_numeric($amount) || (float) $amount < 0 || (float) $amount > 10_000_000) {
            throw new CrudError(['fee_items' => 'Invalid amount'], 'Fees: enter an amount in rupees for “' . $label . '” (0 or more).');
        }
        if (mb_strlen($label) > 80 || mb_strlen($r['group']) > 60 || mb_strlen($r['note']) > 200) {
            throw new CrudError(['fee_items' => 'Too long'], 'Fees: keep the group under 60, the fee name under 80 and the note under 200 characters.');
        }
        $out[] = ['group' => course_plain($r['group']) ?: 'Other fees', 'label' => $label, 'amount' => round((float) $amount, 2),
            'note' => course_plain($r['note']), 'offer_free' => $r['free'] === '1'];
    }
    if (count($out) > 20) {
        throw new CrudError(['fee_items' => 'Too many'], 'Fees: add at most 20 fee items.');
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Syllabus subjects posted by the editor (title + topics one per line) → JSON for courses.syllabus. */
function posted_course_syllabus(): string
{
    $out = [];
    foreach (posted_course_rows('syl', ['title', 'topics']) as $r) {
        $title = course_plain($r['title']);
        $topics = array_values(array_filter(array_map(fn($t) => course_plain((string) preg_replace('/^\s*(?:\d+[.)]|[-•*])\s*/u', '', $t)),
            preg_split('/\R/', $r['topics']) ?: [])));
        if ($title === '' && !$topics) {
            continue;
        }
        if ($title === '' || !$topics) {
            throw new CrudError(['syllabus' => 'Incomplete'], 'Syllabus: every subject needs a name and at least one topic (one per line).');
        }
        if (mb_strlen($title) > 100 || count($topics) > 80 || max(array_map('mb_strlen', $topics)) > 120) {
            throw new CrudError(['syllabus' => 'Too long'], 'Syllabus: subject names up to 100 characters, up to 80 topics per subject, each topic up to 120 characters.');
        }
        $out[] = ['title' => $title, 'topics' => $topics];
    }
    if (count($out) > 20) {
        throw new CrudError(['syllabus' => 'Too many'], 'Syllabus: add at most 20 subjects.');
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Extra page sections posted by the editor → JSON for courses.info_sections. */
function posted_course_info(): string
{
    $out = [];
    foreach (posted_course_rows('info', ['title', 'body']) as $r) {
        $title = course_plain($r['title']);
        $body = course_plain($r['body'], true);
        if ($title === '' && $body === '') {
            continue;
        }
        if ($title === '' || $body === '') {
            throw new CrudError(['info_sections' => 'Incomplete'], 'Page sections: every section needs a heading and some text.');
        }
        if (mb_strlen($title) > 100 || mb_strlen($body) > 5000) {
            throw new CrudError(['info_sections' => 'Too long'], 'Page sections: headings up to 100 characters, text up to 5,000 characters.');
        }
        $out[] = ['title' => $title, 'body' => $body];
    }
    if (count($out) > 10) {
        throw new CrudError(['info_sections' => 'Too many'], 'Page sections: add at most 10 sections.');
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Fees, syllabus and page-section editors (migration 017), after the highlights editor. */
function course_extras_editor(?array $row): string
{
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $box = 'style="border:1px solid var(--line);border-radius:12px;padding:12px;margin-bottom:10px;background:var(--surface)"';

    // Fees: group, name, amount, note, free during the offer
    $fees = $isPost ? posted_course_rows('fee', ['group', 'label', 'amount', 'note', 'free'])
        : array_map(fn($f) => ['group' => $f['group'], 'label' => $f['label'], 'amount' => (string) (float) $f['amount'], 'note' => $f['note'], 'free' => $f['offer_free'] ? '1' : '0'],
            course_fee_items($row['fee_items'] ?? null));
    $fees = array_values(array_filter($fees, fn($f) => trim($f['label']) !== ''));
    $fees[] = ['group' => $fees ? end($fees)['group'] : 'Admission & registration', 'label' => '', 'amount' => '', 'note' => '', 'free' => '0'];
    $h = '<div class="form-sec" id="sec-fees"><h3>Fees &amp; admission</h3><p class="hint">Admission, registration and other fee options shown in the “Fees” section of the course page. '
        . 'Each item is listed on its own line - amounts are never added together, so explain in the note when an option applies (e.g. “Pay in two parts”). '
        . '“Free during offer” shows the fee struck through as FREE while the offer below is running. Rows with the same group are shown together.</p></div>'
        . '<div data-blocks role="group" aria-label="Fee items">';
    foreach ($fees as $n => $f) {
        $k = $n + 1;
        $h .= '<div data-block ' . $box . '><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
            . '<input class="in" type="text" name="fee_group[]" maxlength="60" value="' . e($f['group']) . '" placeholder="Group, e.g. Admission & registration" style="flex:1;min-width:180px" aria-label="Fee ' . $k . ' group">'
            . '<input class="in" type="text" name="fee_label[]" maxlength="80" value="' . e($f['label']) . '" placeholder="Fee name, e.g. Admission fee" style="flex:1;min-width:180px" aria-label="Fee ' . $k . ' name">'
            . '<input class="in" type="text" inputmode="decimal" name="fee_amount[]" maxlength="12" value="' . e($f['amount']) . '" placeholder="₹ amount" style="width:120px" aria-label="Fee ' . $k . ' amount in rupees">'
            . '<select class="in" name="fee_free[]" style="width:auto" aria-label="Fee ' . $k . ' during the offer">'
            . '<option value="0"' . ($f['free'] !== '1' ? ' selected' : '') . '>Charged</option><option value="1"' . ($f['free'] === '1' ? ' selected' : '') . '>Free during offer</option></select>'
            . course_row_buttons() . '</div>'
            . '<input class="in" type="text" name="fee_note[]" maxlength="200" value="' . e($f['note']) . '" placeholder="Note: what it covers, who it applies to (optional)" style="margin-top:8px" aria-label="Fee ' . $k . ' note"></div>';
    }
    $h .= '</div>';

    // Syllabus: subject + topics (one per line)
    $syl = $isPost ? posted_course_rows('syl', ['title', 'topics'])
        : array_map(fn($s) => ['title' => $s['title'], 'topics' => implode("\n", $s['topics'])], course_syllabus($row['syllabus'] ?? null));
    $syl = array_values(array_filter($syl, fn($s) => trim($s['title'] . $s['topics']) !== ''));
    $syl[] = ['title' => '', 'topics' => ''];
    $h .= '<div class="form-sec" id="sec-syllabus"><h3>Syllabus (subject-wise)</h3><p class="hint">One block per subject, e.g. “Mathematics / Quantitative Aptitude”, with one topic per line in teaching order. '
        . 'Shown as expandable sections on the course page and searchable on the courses page. Use the arrows to reorder subjects; reorder topics by moving lines. Empty blocks are ignored.</p></div>'
        . '<div data-blocks role="group" aria-label="Syllabus subjects">';
    foreach ($syl as $n => $s) {
        $k = $n + 1;
        $h .= '<div data-block ' . $box . '><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
            . '<input class="in" type="text" name="syl_title[]" maxlength="100" value="' . e($s['title']) . '" placeholder="Subject, e.g. Reasoning" style="flex:1;min-width:200px" aria-label="Subject ' . $k . ' name">'
            . course_row_buttons() . '</div>'
            . '<textarea class="in" name="syl_topics[]" rows="5" placeholder="One topic per line, e.g.&#10;Number System&#10;Simplification" style="margin-top:8px" aria-label="Subject ' . $k . ' topics, one per line">' . e($s['topics']) . '</textarea></div>';
    }
    $h .= '</div>';

    // Extra sections: heading + text
    $info = $isPost ? posted_course_rows('info', ['title', 'body']) : course_info_sections($row['info_sections'] ?? null);
    $info = array_values(array_filter($info, fn($s) => trim($s['title'] . $s['body']) !== ''));
    $info[] = ['title' => '', 'body' => ''];
    $h .= '<div class="form-sec" id="sec-info"><h3>More page sections</h3><p class="hint">Extra sections such as “Examination details”, “Preparation strategy” or “Who should join”. '
        . 'Leave a blank line between paragraphs; start a line with “- ” for a bullet point.</p></div>'
        . '<div data-blocks role="group" aria-label="Page sections">';
    foreach ($info as $n => $s) {
        $k = $n + 1;
        $h .= '<div data-block ' . $box . '><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
            . '<input class="in" type="text" name="info_title[]" maxlength="100" value="' . e($s['title']) . '" placeholder="Heading, e.g. Examination details" style="flex:1;min-width:200px" aria-label="Section ' . $k . ' heading">'
            . course_row_buttons() . '</div>'
            . '<textarea class="in" name="info_body[]" rows="5" maxlength="5000" style="margin-top:8px" aria-label="Section ' . $k . ' text">' . e($s['body']) . '</textarea></div>';
    }
    return $h . '</div>';
}

/** Small 16:9 thumbnail (or a category-coloured placeholder) for the list. */
function course_thumb_cell(array $r): string
{
    $url = public_file_url($r['thumbnail_path']);
    return $url
        ? '<img class="d1-thumb" src="' . e($url) . '" alt="" loading="lazy">'
        : '<span class="d1-thumb" title="No thumbnail uploaded" style="background:' . e($r['category_color'] ?: '#0B63F6') . '">' . icon('image') . '<span class="sr-only">No thumbnail</span></span>';
}

/** Course cell: thumbnail + title (2 lines max) + category / type / badges underneath. */
function course_title_cell(array $r): string
{
    $meta = '<span><span class="d1-dot" style="background:' . e($r['category_color'] ?: '#0B63F6') . '"></span>' . e($r['category_name']) . '</span>'
        . '<span>' . e(COURSE_TYPE_LABELS[$r['course_type']] ?? ucfirst((string) $r['course_type'])) . '</span>'
        . (course_enquiry_only($r) ? '<span title="Admission through the Apply Now form, no online payment">Apply Now</span>' : '')
        . (!empty($r['academic_session']) ? '<span class="badge b-blue" title="Batch for academic session ' . e($r['academic_session']) . '">Batch ' . e($r['academic_session']) . '</span>' : '')
        . ($r['is_featured'] ? '<span class="d1-feat d1-nosep" title="Featured on the homepage">' . icon('star') . '<span class="sr-only">Featured</span></span>' : '')
        . (!empty($r['is_demo']) ? '<span class="badge b-demo">Demo</span>' : '');
    return '<div class="d1-main d1-media">' . course_thumb_cell($r)
        . '<div class="d1-text">' . (can('courses.manage')
            ? '<a class="d1-t" href="' . e(url_with(['action' => 'edit', 'id' => $r['id'], 'page' => null])) . '" title="' . e($r['title']) . '">' . e($r['title']) . '</a>'
            : '<strong class="d1-t" title="' . e($r['title']) . '">' . e($r['title']) . '</strong>')
        . '<div class="d1-sub">' . $meta . '</div></div></div>';
}

// Batch / academic session (migration 010) and offline / offer fields (017): only offered once the columns exist.
$batches = course_batches_enabled();
$extras = course_extras_enabled();
$typeOptions = $extras ? COURSE_TYPE_LABELS : array_diff_key(COURSE_TYPE_LABELS, ['offline' => 1]);
$batchChoices = function () use ($editingId): array {
    $choices = academic_session_choices();
    $current = $editingId ? db_value('SELECT academic_session FROM courses WHERE id = ?', [$editingId]) : null;
    return $current && !isset($choices[$current]) ? [$current => $current] + $choices : $choices; // keep an older tag selectable
};

crud([
    'table' => 'courses',
    'title' => 'Courses',
    'singular' => 'course',
    'permission' => 'courses.view',
    'manage_permission' => 'courses.manage',
    'active' => 'courses',
    'from' => 'courses t JOIN course_categories cat ON cat.id = t.category_id',
    'select' => "t.*, cat.name AS category_name, cat.color AS category_color,
        (SELECT COUNT(*) FROM lessons l WHERE l.course_id = t.id) AS lesson_count,
        (SELECT COUNT(*) FROM lessons l WHERE l.course_id = t.id AND l.is_preview = 1 AND l.status = 'published') AS preview_count,
        (SELECT COUNT(*) FROM course_enrolments e WHERE e.course_id = t.id AND e.status = 'active') AS enrol_count",
    'order' => 't.sort_order, t.id DESC',
    'sortable' => ['title' => 't.title', 'price' => 'COALESCE(t.discount_price, t.price)', 'enrol_count' => 'enrol_count', 'lesson_count' => 'lesson_count', 'status' => 't.status'],
    'bulk_status' => ['field' => 'status', 'label' => 'status', 'options' => ['published' => 'Published', 'draft' => 'Draft', 'archived' => 'Archived']],
    // Bulk "Published" must also stamp published_at (used by the "Newest" sort), like a single save does.
    'after_bulk' => function (array $ids, string $value) {
        if ($value === 'published' && $ids) {
            db_query('UPDATE courses SET published_at = COALESCE(published_at, NOW()) WHERE id IN (' . db_placeholders(count($ids)) . ')', $ids);
        }
    },
    'search' => ['t.title', 't.slug', 't.subtitle'],
    'filters' => [
        'status' => ['col' => 't.status', 'label' => 'Statuses', 'options' => ['published' => 'Published', 'draft' => 'Draft', 'archived' => 'Archived']],
        'category' => ['col' => 't.category_id', 'label' => 'Categories', 'options' => $categories],
        'type' => ['col' => 't.course_type', 'label' => 'Types', 'options' => $typeOptions],
        ...($batches ? ['batch' => ['col' => 't.academic_session', 'label' => 'Batches', 'options' => $batchChoices]] : []),
    ],
    'columns' => [
        'title' => ['Course', 'course_title_cell'],
        'price' => ['Price', fn($r) => '<div class="d1-num">' . ($r['course_type'] === 'free' ? '<span class="badge b-green">Free</span>'
            : '<strong>' . e(money($r['discount_price'] ?? $r['price'])) . '</strong>' . ($r['discount_price'] !== null ? '<small><s>' . e(money($r['price'])) . '</s></small>' : '')
                . (!empty($r['offer_ends_at']) && $r['discount_price'] !== null ? '<small>' . (strtotime($r['offer_ends_at']) < time() ? 'Offer ended ' : 'Offer till ') . e(fmt_dt($r['offer_ends_at'], 'j M Y')) . '</small>' : '')) . '</div>'],
        'lesson_count' => ['Lessons', fn($r) => '<div class="d1-num">' . (int) $r['lesson_count'] . ' lesson' . ((int) $r['lesson_count'] === 1 ? '' : 's')
            . ((int) $r['preview_count'] ? '<small>' . (int) $r['preview_count'] . ' free preview' . ((int) $r['preview_count'] === 1 ? '' : 's') . '</small>' : '') . '</div>'],
        'enrol_count' => ['Students', fn($r) => '<span class="d1-num">' . number_format((int) $r['enrol_count']) . '</span>'],
        'status' => ['Status', fn($r) => badge($r['status'])],
    ],
    // Secondary actions are compact icon buttons (labelled for screen readers and on hover).
    'row_actions' => fn($r) => '<span class="d1-acts"><a class="btn btn-light btn-sm btn-icon" href="lessons.php?course=' . (int) $r['id'] . '" title="Curriculum (sections & lessons)" aria-label="Curriculum of ' . e($r['title']) . '">' . icon('list') . '</a>'
        // Preview inside the admin panel (drafts too): desktop/mobile, visitor/enrolled view, publish checklist.
        . '<a class="btn btn-light btn-sm btn-icon" href="course-preview.php?id=' . (int) $r['id'] . '" title="Preview the course page (works for drafts too)" aria-label="Preview ' . e($r['title']) . '">' . icon('eye') . '</a></span>',
    'can_delete' => fn($r) => (int) ($r['enrol_count'] ?? db_value("SELECT COUNT(*) FROM course_enrolments WHERE course_id = ? AND status = 'active'", [$r['id']])) === 0
        && !db_value('SELECT 1 FROM order_items WHERE course_id = ? LIMIT 1', [$r['id']]),
    'before_save' => function (array $d, ?array $old) {
        if ($d['course_type'] === 'free') {
            $d['price'] = 0;
            $d['discount_price'] = null;
        } elseif ((float) $d['price'] <= 0) {
            throw new CrudError(['price' => 'Paid courses need a price above zero (or choose the Free type).']);
        }
        if ($d['discount_price'] !== null && $d['discount_price'] >= $d['price']) {
            throw new CrudError(['discount_price' => 'Sale price must be lower than the list price.']);
        }
        if (course_extras_enabled()) {
            if ($d['offer_starts_at'] !== null && $d['offer_ends_at'] !== null && $d['offer_ends_at'] <= $d['offer_starts_at']) {
                throw new CrudError(['offer_ends_at' => 'The offer must end after it starts.']);
            }
            $d['fee_items'] = posted_course_fees();
            $d['syllabus'] = posted_course_syllabus();
            $d['info_sections'] = posted_course_info();
        }
        // Prerequisite: never itself, and never a chain that leads back to this course (nobody could enrol).
        if ($d['prerequisite_course_id'] !== null && $old) {
            $seen = [];
            $next = (int) $d['prerequisite_course_id'];
            while ($next && !isset($seen[$next]) && count($seen) < 50) {
                if ($next === (int) $old['id']) {
                    throw new CrudError(['prerequisite_course_id' => 'This creates a loop: the chosen prerequisite (directly or through its own prerequisites) requires this course.']);
                }
                $seen[$next] = true;
                $next = (int) db_value('SELECT prerequisite_course_id FROM courses WHERE id = ?', [$next]);
            }
        }
        if (($d['academic_session'] ?? null) !== null && !academic_session_range((string) $d['academic_session'])) {
            throw new CrudError(['academic_session' => 'Choose a session such as 2027-28.']);
        }
        $d['highlights'] = posted_course_highlights();
        if ($d['status'] === 'published' && (!$old || $old['published_at'] === null)) {
            $d['published_at'] = date('Y-m-d H:i:s');
        }
        if (!$old) {
            $d['created_by'] = $GLOBALS['__admin_me']['id'] ?? current_user()['id'] ?? null;
        }
        return $d;
    },
    // After adding or editing a course, show how its page looks (inside the admin panel).
    'after_save_redirect' => fn($id) => 'course-preview.php?id=' . $id,
    'form_actions' => fn(?array $row) => $row ? '<a class="btn btn-light" href="course-preview.php?id=' . (int) $row['id'] . '">' . icon('eye') . ' Preview</a>' : '',
    'form_extra' => 'course_highlights_editor',
    'fields' => [
        'title' => ['label' => 'Course title', 'required' => true, 'max' => 180],
        'slug' => ['label' => 'Slug (URL)', 'type' => 'slug', 'from' => 'title', 'required' => true, 'unique' => true],
        'subtitle' => ['label' => 'Subtitle', 'max' => 200, 'placeholder' => 'e.g. IBPS | SBI | RBI'],
        'category_id' => ['label' => 'Category', 'type' => 'select', 'options' => $categories, 'required' => true],
        'instructor_id' => ['label' => 'Faculty', 'type' => 'select', 'options' => $instructors],
        'course_type' => ['label' => 'Course type', 'type' => 'select', 'required' => true, 'default' => 'hybrid', 'options' => $typeOptions,
            'hint' => $extras ? 'Offline Classes = classroom batches at the centre.' : null],
        ...($extras ? ['admission_mode' => ['label' => 'Admission', 'type' => 'select', 'required' => true, 'default' => 'online',
            'options' => ['online' => 'Online - students pay and enrol on the website', 'enquiry' => 'Apply Now form - our team calls back (no online payment)'],
            'hint' => 'With the Apply Now form the course page and course cards show “Apply Now” / “Enquire Now” instead of “Enroll Now”, and leads arrive in Enquiries.']] : []),
        'price' => ['label' => 'List price (₹)', 'type' => 'money', 'min' => 0, 'default' => 0, 'required' => true],
        'discount_price' => ['label' => 'Sale price (₹, optional)', 'type' => 'money', 'min' => 0],
        ...($extras ? [
            'offer_starts_at' => ['label' => 'Offer starts (optional)', 'type' => 'datetime', 'hint' => 'Blank = the offer is already running.'],
            'offer_ends_at' => ['label' => 'Offer ends (optional)', 'type' => 'datetime', 'hint' => 'After this the list price applies again and “free during offer” fees are charged. Blank = no end date.'],
        ] : []),
        'duration_label' => ['label' => 'Duration label', 'max' => 60, 'placeholder' => 'e.g. 12 Months'],
        'validity_days' => ['label' => 'Access validity (days, blank = lifetime)', 'type' => 'number', 'min' => 1, 'max' => 3650],
        'language' => ['label' => 'Language', 'type' => 'select', 'required' => true, 'default' => 'bilingual', 'options' => ['hindi' => 'Hindi', 'english' => 'English', 'bilingual' => 'Hindi & English']],
        'level' => ['label' => 'Level', 'type' => 'select', 'required' => true, 'default' => 'all', 'options' => ['beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced', 'all' => 'All levels']],
        'prerequisite_course_id' => ['label' => 'Prerequisite course', 'type' => 'select', 'options' => $prerequisites, 'hint' => 'Students must complete this course before they can enrol.'],
        'badge' => ['label' => 'Badge', 'max' => 40, 'placeholder' => 'Bestseller, Popular, New…'],
        'thumbnail_path' => ['label' => 'Thumbnail (16:9)', 'type' => 'image', 'folder' => 'courses', 'w' => 1280, 'h' => 720],
        'promo_video_url' => ['label' => 'Promo video (YouTube URL)', 'type' => 'url'],
        'short_description' => ['label' => 'Short description', 'type' => 'textarea', 'max' => 500, 'rows' => 2],
        'description' => ['label' => 'Full description (blank line = new paragraph)', 'type' => 'textarea', 'rows' => 6],
        'learning_outcomes' => ['label' => 'Key features / learning outcomes (one per line)', 'type' => 'lines'],
        'includes' => ['label' => '"This course includes" list (one per line)', 'type' => 'lines'],
        'requirements' => ['label' => 'Requirements (one per line)', 'type' => 'lines'],
        'faqs' => ['label' => 'FAQs (one per line: Question :: Answer)', 'type' => 'faqs'],
        'seo_title' => ['label' => 'SEO title (blank = course title)', 'max' => 160, 'counter' => '60,70'],
        'seo_description' => ['label' => 'SEO description (blank = short description)', 'max' => 320, 'counter' => '160,320'],
        'sort_order' => ['label' => 'Sort order', 'type' => 'number', 'default' => 0],
        'status' => ['label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'draft', 'options' => ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'],
            'hint' => 'Archived = finished batch: hidden from the website and closed to new admissions, but enrolled students keep access until their validity ends.'],
        ...($batches ? ['academic_session' => ['label' => 'Batch / academic session', 'type' => 'select', 'options' => $batchChoices,
            'hint' => 'For batch courses (e.g. “UPSC Foundation 2028” → 2027-28): admissions count for that session from day one, even before 1 April, and the session appears early in the top-bar selector. Leave empty for courses not tied to a session.']] : []),
        'is_featured' => ['label' => 'Featured on homepage', 'type' => 'checkbox'],
        'is_demo' => ['label' => 'Demo content (shows a DEMO tag)', 'type' => 'checkbox'],
    ],
]);
