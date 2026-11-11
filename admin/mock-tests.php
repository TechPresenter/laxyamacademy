<?php
// Mock tests and practice quizzes. Questions live in questions.php, attempts in attempts.php.
// Tabs: Mock Tests / Practice Quizzes (CRUD lists) + Schedule, Result Publication,
// Negative Marking and Instructions overviews (also reachable as ?view=…).
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';
require_once APP_ROOT . '/includes/exams.php';

$me = require_staff_page();
require_permission('exams.manage');

// ------------------------------------------------------------------
// Row actions that are not plain CRUD: duplicate, release / withdraw results, send result emails
// ------------------------------------------------------------------
if (admin_post() && in_array($_POST['_action'] ?? '', ['duplicate', 'release', 'withdraw', 'notify'], true)) {
    $act = $_POST['_action'];
    $exam = db_one('SELECT * FROM exams WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
    $back = url_with([]);
    if (!$exam) {
        flash('error', 'Test not found.');
        redirect_to($back);
    }
    $id = (int) $exam['id'];
    if ($act === 'duplicate') {
        $newId = db_transaction(function () use ($exam, $me): int {
            $base = substr($exam['slug'], 0, 185) . '-copy';
            $slug = $base;
            for ($i = 2; db_value('SELECT 1 FROM exams WHERE slug = ?', [$slug]); $i++) {
                $slug = $base . '-' . $i;
            }
            $row = $exam;
            unset($row['id'], $row['created_at'], $row['updated_at']);
            $row = ['title' => mb_substr($exam['title'] . ' (copy)', 0, 180), 'slug' => $slug, 'status' => 'draft', 'results_released_at' => null,
                'created_by' => (int) $me['id'], 'is_demo' => 0] + $row;
            // Column names come from the exams row itself; every value is bound.
            db_query('INSERT INTO exams (' . implode(', ', array_keys($row)) . ') VALUES (' . db_placeholders(count($row)) . ')', array_values($row));
            $newId = (int) db()->lastInsertId();
            $sectionMap = []; // sections (with their timers) travel with the copy
            foreach (exam_sections_of((int) $exam['id']) as $n => $s) {
                db_query('INSERT INTO exam_sections (exam_id, title, title_hi, duration_minutes, sort_order) VALUES (?, ?, ?, ?, ?)', [$newId, $s['title'], $s['title_hi'], $s['duration_minutes'], $n + 1]);
                $sectionMap[(int) $s['id']] = (int) db()->lastInsertId();
            }
            $qids = array_column(db_all('SELECT id FROM exam_questions WHERE exam_id = ? ORDER BY sort_order, id', [$exam['id']]), 'id');
            exam_copy_questions($qids, $newId, true, false, $sectionMap);
            return $newId;
        });
        audit_log('exam_duplicated', 'exams', $newId, ['from' => $id]);
        flash('success', 'Copied as a draft with all its questions. Review the settings, then publish.');
        redirect_to('mock-tests.php?tab=' . ($exam['mode'] === 'practice' ? 'practice' : 'exam') . '&action=edit&id=' . $newId);
    }
    if (exam_is_practice($exam)) {
        flash('info', 'Practice quiz results are always shown straight away.');
        redirect_to($back);
    }
    if ($act === 'release') {
        db_query("UPDATE exams SET result_release = 'manual', results_released_at = NOW() WHERE id = ?", [$id]);
        audit_log('exam_results_released', 'exams', $id, ['previous_mode' => $exam['result_release']]);
        $sent = exam_results_notify_due($id, 300);
        $pending = (int) db_value("SELECT COUNT(*) FROM exam_attempts WHERE exam_id = ? AND status <> 'in_progress' AND result_notified_at IS NULL", [$id]);
        flash('success', 'Results released. ' . $sent . ' student' . ($sent === 1 ? '' : 's') . ' emailed' . ($pending ? "; {$pending} more will be emailed by the scheduler." : '.'));
    } elseif ($act === 'withdraw') {
        db_query("UPDATE exams SET result_release = 'manual', results_released_at = NULL WHERE id = ?", [$id]);
        audit_log('exam_results_withdrawn', 'exams', $id, [], 'warning');
        flash('success', 'Results hidden from students until you release them again.');
    } else {
        if (!exam_results_visible($exam)) {
            flash('error', 'Results are not visible to students yet. Release them first.');
            redirect_to($back);
        }
        $sent = exam_results_notify_due($id, 300);
        audit_log('exam_results_emailed', 'exams', $id, ['sent' => $sent]);
        flash('success', $sent ? $sent . ' result email' . ($sent === 1 ? '' : 's') . ' sent.' : 'Every student has already been emailed.');
    }
    redirect_to($back);
}

// ------------------------------------------------------------------
// Tabs
// ------------------------------------------------------------------
$tab = (string) ($_GET['tab'] ?? $_GET['view'] ?? 'exam');
$views = ['schedule', 'results', 'marking', 'instructions'];
if (!in_array($tab, ['exam', 'practice', ...$views], true)) {
    $tab = 'exam';
}
$modeCounts = array_column(db_all('SELECT mode, COUNT(*) AS n FROM exams GROUP BY mode'), 'n', 'mode');
$tabHtml = admin_tabs([
    'exam' => 'Mock Tests (' . (int) ($modeCounts['exam'] ?? 0) . ')',
    'practice' => 'Practice Quizzes (' . (int) ($modeCounts['practice'] ?? 0) . ')',
    'schedule' => 'Schedule',
    'results' => 'Result Publication',
    'marking' => 'Negative Marking',
    'instructions' => 'Instructions',
], $tab);

/** Small POST button with an accessible name (icon-only buttons need aria-label). */
function mt_post_button(string $action, int $id, string $html, string $aria, string $class = 'btn btn-light btn-sm', ?string $confirm = null): string
{
    return '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="_action" value="' . e($action) . '"><input type="hidden" name="id" value="' . $id . '">'
        . '<button class="' . $class . '" type="submit" title="' . e($aria) . '" aria-label="' . e($aria) . '"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . $html . '</button></form>';
}

/** POST action as a menu item inside the row's overflow menu. */
function mt_menu_post(string $action, int $id, string $html, ?string $confirm = null): string
{
    return '<form method="post">' . csrf_field() . '<input type="hidden" name="_action" value="' . e($action) . '"><input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . $html . '</button></form>';
}

/** "⋯" button + native popover menu (top layer, so the table never clips it; Esc / outside click close it). */
function mt_menu(string $id, string $label, string $items): string
{
    return '<button type="button" class="btn btn-light btn-sm btn-icon" popovertarget="' . e($id) . '" style="anchor-name:--' . e($id) . '" title="More actions" aria-label="' . e($label) . '">' . icon('more') . '</button>'
        . '<div class="d1-menu" id="' . e($id) . '" popover style="position-anchor:--' . e($id) . '">' . $items . '</div>';
}

const MT_ACCESS = ['free' => 'Free', 'registered' => 'Logged-in students', 'enrolled' => 'Course students'];

/** Test name cell used by every tab: title (2 lines max) + type / subject / category underneath. */
function mt_name_cell(array $r, ?string $href = null): string
{
    $meta = array_filter([$r['mode'] === 'practice' ? 'Practice quiz' : 'Mock test', $r['subject'] ?? null, $r['category_name'] ?? null, $r['course_title'] ?? null]);
    $title = $href ? '<a class="d1-t" href="' . e($href) . '" title="' . e($r['title']) . '">' . e($r['title']) . '</a>' : '<span class="d1-t" title="' . e($r['title']) . '">' . e($r['title']) . '</span>';
    return '<div class="d1-main">' . $title . '<div class="d1-sub">' . implode('', array_map(fn($m) => '<span>' . e((string) $m) . '</span>', $meta))
        . (!empty($r['is_demo']) ? '<span class="badge b-demo">Demo</span>' : '') . '</div></div>';
}

function mt_window_badge(array $r): string
{
    $w = exam_window($r);
    $label = ['open' => 'Open', 'upcoming' => 'Upcoming', 'closed' => 'Closed'][$w];
    $tone = ['open' => 'b-green', 'upcoming' => 'b-blue', 'closed' => 'b-gray'][$w];
    return '<span class="badge ' . $tone . '">' . $label . '</span>';
}

function mt_release_badge(array $r): string
{
    [$label, $tone] = exam_release_state($r);
    return '<span class="badge b-' . $tone . '">' . e($label) . '</span>';
}

// ------------------------------------------------------------------
// Overview tabs (read-only tables with real actions)
// ------------------------------------------------------------------
if (in_array($tab, $views, true) && !isset($_GET['action'])) {
    $titles = [
        'schedule' => ['Test Schedule', 'When each test opens and closes. Attempts in progress end at the closing time.'],
        'results' => ['Result Publication', 'Choose when students see scores and the answer key. Releasing results emails every student who took the test.'],
        'marking' => ['Negative Marking', 'Marking scheme per test. A question’s own negative marks win; otherwise the test default applies when negative marking is on.'],
        'instructions' => ['Exam Instructions', 'Instructions shown on each test’s start page.'],
    ];
    admin_start($titles[$tab][0], null, 'tests', ['head' => true, 'subtitle' => $titles[$tab][1],
        'actions' => '<a class="btn btn-primary" href="mock-tests.php?action=new">' . icon('plus') . ' New test</a>']);
    echo $tabHtml;
    $rows = db_all("SELECT e.*, (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = e.id) AS q_count,
            (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = e.id AND q.negative_marks > 0) AS q_neg,
            (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id = e.id AND a.status <> 'in_progress') AS finished,
            (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id = e.id AND a.status <> 'in_progress' AND a.result_notified_at IS NOT NULL) AS emailed
        FROM exams e WHERE e.status <> 'archived'" . ($tab === 'results' ? " AND e.mode = 'exam'" : '') . '
        ORDER BY ' . ($tab === 'schedule' ? '(e.ends_at IS NOT NULL AND e.ends_at < NOW()), e.starts_at IS NULL, e.starts_at, e.id DESC' : 'e.id DESC'));
    $edit = fn($r) => 'mock-tests.php?tab=' . ($r['mode'] === 'practice' ? 'practice' : 'exam') . '&action=edit&id=' . (int) $r['id'];
    $name = fn($r) => str_replace('</div></div>', ($r['status'] !== 'published' ? ' ' . badge($r['status']) : '') . '</div></div>', mt_name_cell($r, $edit($r)));
    $when = fn(?string $dt, string $none) => $dt ? '<span class="d1-when"><strong>' . e(fmt_dt($dt, 'j M Y')) . '</strong><small>' . e(fmt_dt($dt, 'D, g:i A')) . '</small></span>' : '<span class="muted">' . $none . '</span>';
    echo '<div class="card">';
    if (!$rows) {
        echo '<div class="empty"><span class="empty-ic">' . icon('clipboard') . '</span><strong>' . ($tab === 'results' ? 'No mock tests yet' : 'No tests yet') . '</strong><p>' . ($tab === 'results' ? 'Result publication applies to timed mock tests. Practice quizzes always show results straight away.' : 'Create a mock test or practice quiz to get started.') . '</p>'
            . '<a class="btn btn-primary btn-sm" href="mock-tests.php?action=new">' . icon('plus') . ' New test</a></div>';
    } else {
        echo '<div class="table-wrap"><table class="t stack"><thead><tr>';
        $heads = match ($tab) {
            'schedule' => ['Test', 'Opens', 'Closes', 'Window', 'Duration', ''],
            'results' => ['Test', 'Results', 'Answer key', 'Submitted', 'Result emails', ''],
            'marking' => ['Test', 'Negative marking', 'Per wrong answer', 'Own negative marks', 'Marks', ''],
            default => ['Test', 'Duration', 'Instructions', ''],
        };
        foreach ($heads as $h) {
            echo '<th scope="col">' . ($h === '' ? '<span class="sr-only">Actions</span>' : e($h)) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $cell = function (int $i, string $html) use ($heads): string {
            return '<td data-label="' . e($heads[$i]) . '">' . $html . '</td>';
        };
        foreach ($rows as $r) {
            echo '<tr><td data-label="Test">' . $name($r) . '</td>';
            if ($tab === 'schedule') {
                echo $cell(1, $when($r['starts_at'], 'When published'))
                    . $cell(2, $when($r['ends_at'], 'Stays open'))
                    . $cell(3, mt_window_badge($r))
                    . $cell(4, '<span class="d1-num">' . ($r['mode'] === 'practice' ? 'Untimed' : (int) $r['duration_minutes'] . ' min') . '</span>')
                    . '<td class="actions"><a class="btn btn-light btn-sm" href="' . e($edit($r)) . '">' . icon('calendar') . ' Edit schedule</a></td>';
            } elseif ($tab === 'results') {
                $visible = exam_results_visible($r);
                $pending = (int) $r['finished'] - (int) $r['emailed'];
                $acts = '';
                if (!$visible) {
                    $acts .= mt_post_button('release', (int) $r['id'], icon('megaphone') . ' Release results', 'Release results now', 'btn btn-primary btn-sm', 'Release results now? Students will see their scores and get a result email.');
                } else {
                    if ($r['result_release'] === 'manual') {
                        $acts .= mt_post_button('withdraw', (int) $r['id'], icon('eye') . ' Hide', 'Hide results from students', 'btn btn-light btn-sm', 'Hide results from students again?');
                    }
                    if ($pending > 0) {
                        $acts .= ' ' . mt_post_button('notify', (int) $r['id'], icon('mail') . ' Email ' . $pending, 'Email ' . $pending . ' pending result email' . ($pending === 1 ? '' : 's'), 'btn btn-light btn-sm');
                    }
                }
                $acts .= ' <a class="btn btn-light btn-sm btn-icon" href="attempts.php?exam=' . (int) $r['id'] . '" title="Attempts" aria-label="Attempts at ' . e($r['title']) . '">' . icon('users') . '</a>';
                echo $cell(1, '<div class="d1-stack">' . mt_release_badge($r) . '<small>' . e(['immediate' => 'Right after submission', 'after_end' => 'After the window closes', 'manual' => 'Manual release'][$r['result_release']]) . '</small></div>')
                    . $cell(2, e(['after_submit' => 'After submission', 'after_result' => 'When the result is final', 'never' => 'Never shown'][$r['answer_key_visibility']]))
                    . $cell(3, '<span class="d1-num">' . (int) $r['finished'] . '</span>')
                    . $cell(4, '<span class="d1-num">' . (int) $r['emailed'] . ' sent</span>' . ($pending > 0 && $visible ? ' <span class="badge b-amber">' . $pending . ' pending</span>' : ''))
                    . '<td class="actions"><span class="d1-acts">' . $acts . '</span></td>';
            } elseif ($tab === 'marking') {
                echo $cell(1, $r['negative_marking'] ? '<span class="badge b-red">On</span>' : '<span class="badge b-gray">Off</span>')
                    . $cell(2, '<span class="d1-num">' . ($r['negative_marking'] ? '−' . e(exam_format_marks((float) $r['default_negative_marks'])) : '—') . '</span>')
                    . $cell(3, '<span class="d1-num">' . (int) $r['q_neg'] . ' of ' . (int) $r['q_count'] . '</span>')
                    . $cell(4, '<span class="d1-num"><strong>' . e(exam_format_marks((float) $r['total_marks'])) . '</strong>' . ($r['pass_marks'] !== null ? '<small>pass ' . e(exam_format_marks((float) $r['pass_marks'])) . '</small>' : '') . '</span>')
                    . '<td class="actions"><span class="d1-acts"><a class="btn btn-light btn-sm btn-icon" href="questions.php?exam=' . (int) $r['id'] . '" title="Questions" aria-label="Questions of ' . e($r['title']) . '">' . icon('clipboard') . '</a><a class="btn btn-light btn-sm" href="' . e($edit($r)) . '">' . icon('edit') . ' Edit</a></span></td>';
            } else {
                $text = trim((string) $r['instructions']);
                echo $cell(1, '<span class="d1-num">' . ($r['mode'] === 'practice' ? 'Untimed' : (int) $r['duration_minutes'] . ' min') . '</span>')
                    . $cell(2, $text !== '' ? '<span class="d1-clip" style="max-width:560px">' . e(preg_replace('/\s+/', ' ', strip_tags($text))) . '</span>' : '<span class="badge b-amber">Not written yet</span>')
                    . '<td class="actions"><a class="btn btn-light btn-sm" href="' . e($edit($r)) . '#f-instructions">' . icon('edit') . ' Edit</a></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    admin_end();
    exit;
}

// ------------------------------------------------------------------
// Mock Tests / Practice Quizzes (generic CRUD, filtered by mode)
// ------------------------------------------------------------------
$mode = $tab === 'practice' ? 'practice' : 'exam';
$isList = !isset($_GET['action']);

crud([
    'table' => 'exams',
    'title' => $mode === 'practice' ? 'Practice Quizzes' : 'Mock Tests',
    'singular' => $mode === 'practice' ? 'quiz' : 'test',
    'permission' => 'exams.manage',
    'active' => 'tests',
    'subtitle' => $mode === 'practice'
        ? 'Untimed quizzes: students check each answer and see the explanation instantly. Unlimited attempts by default.'
        : 'Timed tests scored at the end, with result release rules, ranks and answer keys.',
    'tabs' => $tabHtml,
    'header_actions' => '<a class="btn btn-light" href="attempts.php">' . icon('users') . ' Attempts</a> <a class="btn btn-light" href="questions.php?import=1">' . icon('download') . ' Import questions</a> ',
    'from' => 'exams t LEFT JOIN course_categories cat ON cat.id = t.category_id LEFT JOIN courses c ON c.id = t.course_id',
    'select' => "t.*, cat.name AS category_name, c.title AS course_title,
        (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = t.id) AS q_count,
        (SELECT COALESCE(SUM(q.marks), 0) FROM exam_questions q WHERE q.exam_id = t.id) AS q_marks,
        (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id = t.id AND a.status <> 'in_progress') AS attempts,
        (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id = t.id) AS all_attempts",
    // The mode filter applies to the lists only, so edit links from any tab keep working.
    'where' => $isList ? 't.mode = ?' : '1=1',
    'where_params' => $isList ? [$mode] : [],
    'search' => ['t.title', 't.slug', 't.subject'],
    'filters' => [
        'status' => ['col' => 't.status', 'label' => 'Statuses', 'options' => ['published' => 'Published', 'draft' => 'Draft', 'archived' => 'Archived']],
        'category' => ['col' => 't.category_id', 'label' => 'Categories', 'options' => fn() => options_from('SELECT id, name AS label FROM course_categories ORDER BY sort_order, name')],
    ],
    'columns' => [
        'title' => ['Test', fn($r) => mt_name_cell($r, 'questions.php?exam=' . (int) $r['id'])],
        'q_count' => ['Questions', fn($r) => '<div class="d1-num">' . ((int) $r['q_count'] ? '<a href="questions.php?exam=' . (int) $r['id'] . '">' . (int) $r['q_count'] . ' question' . ((int) $r['q_count'] === 1 ? '' : 's') . '</a>' : '<a class="badge b-amber" href="questions.php?exam=' . (int) $r['id'] . '" title="Add questions before publishing">Add questions</a>')
            . ($r['question_pool_count'] ? '<small title="Random questions per attempt">' . min((int) $r['question_pool_count'], (int) $r['q_count']) . ' random per attempt</small>' : '')
            . ((float) $r['q_marks'] !== (float) $r['total_marks'] && $r['q_count'] && !$r['question_pool_count'] ? '<small style="color:#b45309" title="Sum of question marks differs from the test total">' . e(exam_format_marks((float) $r['q_marks'])) . ' of ' . e(exam_format_marks((float) $r['total_marks'])) . ' marks</small>' : '') . '</div>'],
        'duration_minutes' => ['Time & access', fn($r) => '<div class="d1-num">' . ($r['mode'] === 'practice' ? 'Untimed' : (int) $r['duration_minutes'] . ' min') . '<small>' . e(MT_ACCESS[$r['access_type']] ?? ucfirst((string) $r['access_type'])) . '</small></div>'],
        'window' => ['Window · Results', fn($r) => '<div class="d1-stack">' . mt_window_badge($r) . mt_release_badge($r) . '</div>'],
        'attempts' => ['Attempts', fn($r) => (int) $r['attempts'] ? '<a class="d1-num" href="attempts.php?exam=' . (int) $r['id'] . '">' . (int) $r['attempts'] . '</a>' : '<span class="muted">0</span>'],
        'status' => ['Status', fn($r) => badge($r['status'])],
    ],
    'sortable' => ['title' => 't.title', 'attempts' => 'attempts', 'status' => 't.status'],
    // Primary action (Questions) stays visible; the rest live in a compact "⋯" menu so the row never overflows.
    'row_actions' => function ($r) {
        $id = (int) $r['id'];
        $items = '<a href="questions.php?exam=' . $id . '">' . icon('clipboard') . ' Questions &amp; answer key</a>'
            . '<a href="attempts.php?exam=' . $id . '">' . icon('users') . ' Attempts &amp; answer sheets</a>'
            . '<a href="leaderboard.php?exam=' . $id . '">' . icon('trophy') . ' Leaderboard</a>'
            . '<a href="questions.php?exam=' . $id . '&amp;answer_key=print" target="_blank" rel="noopener">' . icon('key') . ' Answer key (printable)</a>';
        if ($r['mode'] === 'exam' && $r['status'] === 'published' && !exam_results_visible($r)) {
            $items .= mt_menu_post('release', $id, icon('megaphone') . ' Release results now', 'Release results now? Students will see their scores and get a result email.');
        }
        $items .= '<hr>' . mt_menu_post('duplicate', $id, icon('copy') . ' Duplicate as a draft', 'Duplicate this test with all its questions as a new draft?');
        // The title and the question count already open the question bank, so only the menu sits next to Edit.
        return '<div class="d1-acts">' . mt_menu('mt-menu-' . $id, 'More actions for ' . $r['title'], $items) . '</div>';
    },
    // The list query selects all_attempts; single-record screens (edit form) load the plain row, so count there.
    'can_delete' => fn($r) => (int) ($r['all_attempts'] ?? db_value('SELECT COUNT(*) FROM exam_attempts WHERE exam_id = ?', [(int) $r['id']])) === 0,
    'before_save' => function (array $d, ?array $old) use ($me) {
        $qCount = $old ? (int) db_value('SELECT COUNT(*) FROM exam_questions WHERE exam_id = ?', [$old['id']]) : 0;
        $attempted = $old && db_value('SELECT 1 FROM exam_attempts WHERE exam_id = ? LIMIT 1', [$old['id']]);
        $err = [];
        if ($d['status'] === 'published' && !$old) {
            $err['status'] = 'Save as draft first, add questions, then publish.';
        } elseif ($d['status'] === 'published' && $qCount === 0) {
            $err['status'] = 'Add at least one question before publishing.';
        }
        if ($d['question_pool_count'] !== null && $old && $d['status'] === 'published' && $d['question_pool_count'] > $qCount) {
            $err['question_pool_count'] = 'The question bank has only ' . $qCount . ' question' . ($qCount === 1 ? '' : 's') . '. Add more or lower the pool size.';
        }
        if ($d['starts_at'] && $d['ends_at'] && $d['ends_at'] <= $d['starts_at']) {
            $err['ends_at'] = 'The closing time must be after the opening time.';
        }
        if ($d['access_type'] === 'enrolled' && !$d['course_id']) {
            $err['course_id'] = 'Choose the course whose students may take this test.';
        }
        if ($d['pass_marks'] !== null && $d['total_marks'] !== null && $d['pass_marks'] > $d['total_marks']) {
            $err['pass_marks'] = 'Pass marks cannot be more than the total marks.';
        }
        if ($attempted && $old['mode'] !== $d['mode']) {
            $err['mode'] = 'The type cannot change after students have attempted. Duplicate the test instead.';
        }
        if ($err) {
            throw new CrudError($err);
        }
        $d['default_negative_marks'] = $d['default_negative_marks'] ?? 0;
        if ($d['mode'] === 'practice') {
            $d['result_release'] = 'immediate'; // practice feedback is instant by design
        }
        $sections = $old ? exam_sections_of((int) $old['id']) : [];
        if ($old && !empty($old['sectional_timing']) && $d['mode'] === 'exam' && $sections && !array_filter($sections, fn($s) => (int) $s['duration_minutes'] <= 0)) {
            $d['duration_minutes'] = min(600, array_sum(array_column($sections, 'duration_minutes'))); // sectional timing: the sections set the time
        }
        if (!$old) {
            $d['created_by'] = (int) $me['id'];
        }
        return $d;
    },
    'after_save_redirect' => fn($id) => 'questions.php?exam=' . $id,
    'fields' => [
        'title' => ['label' => 'Title', 'required' => true, 'max' => 180],
        'slug' => ['label' => 'Slug', 'type' => 'slug', 'from' => 'title', 'required' => true, 'unique' => true],
        'mode' => ['label' => 'Type', 'type' => 'select', 'required' => true, 'default' => $mode, 'options' => [
            'exam' => 'Mock test - timed, scored at the end',
            'practice' => 'Practice quiz - untimed, instant feedback per question',
        ]],
        'subject' => ['label' => 'Subject (optional)', 'max' => 80, 'placeholder' => 'e.g. Polity, Quantitative Aptitude'],
        'category_id' => ['label' => 'Exam category', 'type' => 'select', 'options' => fn() => options_from('SELECT id, name AS label FROM course_categories ORDER BY sort_order')],
        'course_id' => ['label' => 'Linked course', 'type' => 'select', 'options' => fn() => options_from('SELECT id, title AS label FROM courses ORDER BY title')],
        'access_type' => ['label' => 'Who can attempt', 'type' => 'select', 'required' => true, 'default' => 'registered', 'options' => [
            'free' => 'Free - any logged-in student (shown as Free)',
            'registered' => 'Any logged-in student',
            'enrolled' => 'Students enrolled in the linked course',
        ]],
        'duration_minutes' => ['label' => 'Duration (minutes)', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 600, 'default' => $mode === 'practice' ? 10 : 30,
            'hint' => 'Practice quizzes are untimed; the duration is shown to students as a guide. With sectional timing (Questions → Sections) this is the total of the section timers.'],
        'total_marks' => ['label' => 'Total marks', 'type' => 'money', 'required' => true, 'min' => 0,
            'hint' => 'With a random pool, each result is out of the marks of the questions that student got.'],
        'pass_marks' => ['label' => 'Pass marks (optional)', 'type' => 'money', 'min' => 0],
        'max_attempts' => ['label' => 'Attempts allowed (0 = unlimited)', 'type' => 'number', 'min' => 0, 'max' => 50, 'default' => $mode === 'practice' ? 0 : 1, 'required' => true,
            'hint' => 'Extra attempts for one student can be granted from Attempts.'],
        'question_pool_count' => ['label' => 'Random pool: questions per attempt (optional)', 'type' => 'number', 'min' => 1, 'max' => 1000,
            'hint' => 'Blank = every question. E.g. 20 gives each student 20 random questions from the bank. Not used when the test is divided into sections.'],
        'starts_at' => ['label' => 'Opens at', 'type' => 'datetime', 'hint' => 'Blank = opens as soon as it is published.'],
        'ends_at' => ['label' => 'Closes at', 'type' => 'datetime', 'hint' => 'Blank = stays open. Attempts still running end at this time.'],
        'result_release' => ['label' => 'Show results (mock tests)', 'type' => 'select', 'required' => true, 'default' => 'immediate', 'options' => [
            'immediate' => 'Immediately after submission',
            'after_end' => 'After the test window closes',
            'manual' => 'When released manually',
        ], 'hint' => 'Practice quizzes always show results immediately.'],
        'results_released_at' => ['label' => 'Release results at (manual mode)', 'type' => 'datetime', 'show_if' => 'result_release=manual',
            'hint' => 'Or use “Release results now” on the Result Publication tab.'],
        'answer_key_visibility' => ['label' => 'Answer key', 'type' => 'select', 'required' => true, 'default' => 'after_result', 'options' => [
            'after_submit' => 'After submission (once results are visible)',
            'after_result' => 'When the result is final (window closed, released, or no attempts left)',
            'never' => 'Never show the answer key',
        ], 'hint' => 'For unlimited-attempt tests with no closing time, “When the result is final” keeps the key hidden.'],
        'show_explanations' => ['label' => 'Include explanations with the answer key', 'type' => 'checkbox', 'default' => 1],
        'negative_marking' => ['label' => 'Negative marking', 'type' => 'checkbox'],
        'default_negative_marks' => ['label' => 'Default negative marks per wrong answer', 'type' => 'money', 'min' => 0, 'max' => 100, 'default' => 0, 'show_if' => 'negative_marking=1',
            'hint' => 'Used for questions without their own negative marks, e.g. 0.25 or 0.33.'],
        'allow_multiple_correct' => ['label' => 'Allow multiple-correct questions', 'type' => 'checkbox'],
        'shuffle_questions' => ['label' => 'Shuffle question order per student', 'type' => 'checkbox'],
        'shuffle_options' => ['label' => 'Shuffle answer options per student (True/False keeps its order)', 'type' => 'checkbox'],
        'description' => ['label' => 'Short description', 'type' => 'textarea', 'rows' => 2],
        'instructions' => ['label' => 'Instructions (blank line = new paragraph)', 'type' => 'textarea', 'rows' => 6],
        'status' => ['label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'draft', 'options' => ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived']],
        'is_demo' => ['label' => 'Demo content', 'type' => 'checkbox'],
    ],
]);
