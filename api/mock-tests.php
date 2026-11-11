<?php
/**
 * Online examination API: timed mock tests and practice quizzes (mode = practice:
 * untimed, instant per-question feedback via action=check). Answer keys never leave
 * the server until exam_answer_key_state() allows it; all scoring lives in
 * includes/exams.php.
 *
 *   GET  mock-tests.php?action=list&category=&q=&access=&mode=exam|practice&subject=&page=
 *   GET  mock-tests.php?action=detail&slug=
 *   POST mock-tests.php?action=start          slug                                 -> { attempt_id, resumed }
 *   GET  mock-tests.php?action=attempt&id=    questions (no answers) + saved responses + time left
 *   POST mock-tests.php?action=answer         attempt_id, question_id, option_ids[], marked
 *   POST mock-tests.php?action=check          attempt, question_id, option_ids[]   (practice quizzes only)
 *   POST mock-tests.php?action=next_section   attempt_id, from                     (timed sections: finish the running one early)
 *   POST mock-tests.php?action=submit         attempt_id, reason = manual|timeout
 *   GET  mock-tests.php?action=result&id=
 *   GET  mock-tests.php?action=mine
 *   GET  mock-tests.php?action=daily                 today's daily quiz (+ the student's streak when logged in)
 *
 * Questions carry an optional Hindi version (text_hi, options[].text_hi, explanation_hi). A test can
 * group its questions into sections; with sectional timing each section has its own timer, only
 * the running section's questions are sent, and answers are accepted only there.
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/exams.php';

const ANSWER_GRACE_SECONDS = EXAM_ANSWER_GRACE_SECONDS;
const EXAM_LIST_COLUMNS = "e.id, e.title, e.slug, e.mode, e.subject, e.description, e.duration_minutes, e.total_marks, e.negative_marking,
    e.default_negative_marks, e.max_attempts, e.access_type, e.starts_at, e.ends_at, e.course_id, e.is_demo, e.question_pool_count,
    e.result_release, e.results_released_at,
    cat.slug AS category_slug, cat.name AS category_name, cat.color AS category_color,
    c.slug AS course_slug, c.title AS course_title,
    (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = e.id) AS question_count,
    (SELECT COALESCE(SUM(q.marks), 0) FROM exam_questions q WHERE q.exam_id = e.id) AS question_marks_sum,
    (SELECT MIN(q.marks) FROM exam_questions q WHERE q.exam_id = e.id) AS question_marks_min,
    (SELECT MAX(q.marks) FROM exam_questions q WHERE q.exam_id = e.id) AS question_marks_max";

/** List columns plus the bilingual / section counts (zeros until database update 011 is applied). */
function exam_list_columns(): string
{
    return EXAM_LIST_COLUMNS . (exam_v11()
        ? ", e.sectional_timing,
           (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = e.id AND q.question_text_hi IS NOT NULL AND q.question_text_hi <> '') AS hindi_count,
           (SELECT COUNT(*) FROM exam_sections s WHERE s.exam_id = e.id) AS section_count"
        : ', 0 AS sectional_timing, 0 AS hindi_count, 0 AS section_count');
}

$action = $_GET['action'] ?? '';
if (in_array($action, ['start', 'answer', 'check', 'next_section', 'submit'], true)) {
    require_method('POST');
    require_csrf();
} else {
    require_method('GET');
}

match ($action) {
    'list' => exam_list(),
    'detail' => exam_detail(),
    'start' => start_attempt(),
    'attempt' => attempt_view(),
    'answer' => save_answer(),
    'check' => check_answer(),
    'next_section' => next_section(),
    'submit' => submit_attempt(),
    'result' => result_view(),
    'mine' => my_attempts(),
    'daily' => daily_view(),
    default => api_error('Unknown action.', 404, 'not_found'),
};

/**
 * Public card/detail shape. question_count = questions asked per attempt (random pool aware);
 * marks_per_attempt / uniform_marks / marks_vary describe the marks an attempt really carries
 * (see exam_marks_summary) - total_marks stays the test's configured total.
 */
function format_exam(array $e): array
{
    $perAttempt = exam_questions_per_attempt($e, (int) $e['question_count']);
    $marks = exam_marks_summary(
        $e,
        (int) $e['question_count'],
        (float) $e['question_marks_sum'],
        $e['question_marks_min'] !== null ? (float) $e['question_marks_min'] : null,
        $e['question_marks_max'] !== null ? (float) $e['question_marks_max'] : null
    );
    return [
        'id' => (int) $e['id'],
        'title' => $e['title'],
        'slug' => $e['slug'],
        'mode' => $e['mode'],
        'subject' => $e['subject'],
        'description' => $e['description'],
        'duration_minutes' => (int) $e['duration_minutes'],
        'timed' => !exam_is_practice($e),
        'total_marks' => (float) $e['total_marks'],
        'marks_per_attempt' => $marks['marks_per_attempt'],
        'uniform_marks' => $marks['uniform_marks'],
        'marks_vary' => $marks['marks_vary'],
        'question_count' => $perAttempt,
        'questions_count' => $perAttempt,
        'negative_marking' => (bool) $e['negative_marking'],
        'default_negative_marks' => $e['negative_marking'] ? (float) $e['default_negative_marks'] : 0.0,
        'max_attempts' => (int) $e['max_attempts'],
        'access_type' => $e['access_type'],
        'starts_at' => iso_datetime($e['starts_at']),
        'ends_at' => iso_datetime($e['ends_at']),
        'window' => exam_window($e),
        'category' => $e['category_slug'] ? ['slug' => $e['category_slug'], 'name' => $e['category_name'], 'color' => $e['category_color']] : null,
        'course' => $e['course_slug'] ? ['slug' => $e['course_slug'], 'title' => $e['course_title']] : null,
        'is_demo' => (bool) $e['is_demo'],
        'bilingual' => (int) ($e['hindi_count'] ?? 0) > 0, // some or all questions have a Hindi version
        'sections_count' => (int) ($e['section_count'] ?? 0),
        'sectional_timing' => !exam_is_practice($e) && !empty($e['sectional_timing']) && (int) ($e['section_count'] ?? 0) > 0,
    ];
}

/** Sections as students see them before starting: [list, timed]. Questions without a section count in the last one. */
function public_sections(array $exam): array
{
    $sections = exam_sections_of((int) $exam['id']);
    $rows = $sections ? db_all('SELECT id, section_id FROM exam_questions WHERE exam_id = ?', [$exam['id']]) : [];
    if (!$rows) {
        return [[], false];
    }
    [, $plan] = exam_build_section_plan(['shuffle_questions' => 0] + $exam, $sections, $rows);
    return [array_map(fn($s) => ['title' => $s['title'], 'title_hi' => $s['title_hi'], 'minutes' => $s['seconds'] !== null ? intdiv((int) $s['seconds'], 60) : null, 'questions' => $s['count']], $plan['sections']), $plan['timed']];
}

function exam_from(string $where, array $params): ?array
{
    return db_one(
        'SELECT ' . exam_list_columns() . ', e.instructions, e.pass_marks, e.show_explanations, e.shuffle_questions,
                e.shuffle_options, e.allow_multiple_correct, e.answer_key_visibility
         FROM exams e
         LEFT JOIN course_categories cat ON cat.id = e.category_id
         LEFT JOIN courses c ON c.id = e.course_id
         WHERE ' . $where,
        $params
    );
}

function exam_list(): never
{
    $where = ["e.status = 'published'"];
    $params = [];
    if ($cats = query_slugs('category')) {
        $where[] = 'cat.slug IN (' . db_placeholders(count($cats)) . ')';
        array_push($params, ...$cats);
    }
    if (($q = query_str('q', 100)) !== '') {
        $where[] = 'e.title LIKE ?';
        $params[] = '%' . addcslashes($q, '%_\\') . '%';
    }
    $access = query_str('access', 20);
    if (in_array($access, ['free', 'registered', 'enrolled'], true)) {
        $where[] = 'e.access_type = ?';
        $params[] = $access;
    }
    $mode = query_str('mode', 20);
    if (in_array($mode, ['exam', 'practice'], true)) {
        $where[] = 'e.mode = ?';
        $params[] = $mode;
    }
    if (($subject = query_str('subject', 80)) !== '') {
        $where[] = 'e.subject = ?';
        $params[] = $subject;
    }
    $page = query_int('page', 1, 1, 500);
    $per = 12;
    $sqlWhere = implode(' AND ', $where);
    $from = 'FROM exams e LEFT JOIN course_categories cat ON cat.id = e.category_id LEFT JOIN courses c ON c.id = e.course_id';
    $total = (int) db_value("SELECT COUNT(*) {$from} WHERE {$sqlWhere}", $params);
    // Open tests first (newest first - exams have no featured flag), then upcoming (soonest
    // opening first), then closed (most recently closed first). Same window rules as exam_window().
    $upcoming = 'e.starts_at IS NOT NULL AND e.starts_at > NOW()';
    $closed = 'e.ends_at IS NOT NULL AND e.ends_at <= NOW()';
    $rows = db_all('SELECT ' . exam_list_columns() . " {$from} WHERE {$sqlWhere}
        ORDER BY CASE WHEN {$upcoming} THEN 1 WHEN {$closed} THEN 2 ELSE 0 END,
                 CASE WHEN {$upcoming} THEN e.starts_at END,
                 CASE WHEN {$closed} AND NOT ({$upcoming}) THEN e.ends_at END DESC,
                 e.id DESC
        LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);

    $user = current_user();
    $student = $user && $user['user_type'] === 'student' ? $user : null;
    $viewer = $student ? list_viewer_stats($rows, (int) $student['id']) : [];
    $items = [];
    foreach ($rows as $r) {
        $items[] = format_exam($r) + ['viewer' => $student ? $viewer[(int) $r['id']] : null];
    }
    $catCounts = category_test_counts(in_array($mode, ['exam', 'practice'], true) ? $mode : null);
    api_ok([
        'items' => $items,
        'pagination' => pagination_meta($total, $page, $per),
        'categories' => array_map(fn($c) => ['slug' => $c['slug'], 'name' => $c['name'], 'count' => $catCounts[$c['id']] ?? 0], fetch_course_categories()),
        'subjects' => array_column(db_all("SELECT DISTINCT subject FROM exams WHERE status = 'published' AND subject IS NOT NULL AND subject <> '' ORDER BY subject"), 'subject'),
    ], 200, $user === null); // personalised responses are never cached
}

/**
 * Published tests per category id for the category chips. Counts the exact category (the
 * list's category filter matches exams.category_id exactly) and honours the mode tab.
 */
function category_test_counts(?string $mode): array
{
    $rows = db_all(
        "SELECT category_id, COUNT(*) AS n FROM exams
         WHERE status = 'published' AND category_id IS NOT NULL" . ($mode !== null ? ' AND mode = ?' : '') . '
         GROUP BY category_id',
        $mode !== null ? [$mode] : []
    );
    return array_map('intval', array_column($rows, 'n', 'category_id'));
}

/** Per-test progress of the logged-in student for a page of list rows. */
function list_viewer_stats(array $rows, int $userId): array
{
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $out = [];
    if (!$ids) {
        return $out;
    }
    $in = db_placeholders(count($ids));
    $extra = array_column(db_all("SELECT exam_id, extra_attempts FROM exam_attempt_allowances WHERE user_id = ? AND exam_id IN ({$in})", [$userId, ...$ids]), 'extra_attempts', 'exam_id');
    $attempts = db_all("SELECT id, exam_id, status, score, expires_at, question_order FROM exam_attempts WHERE user_id = ? AND exam_id IN ({$in}) ORDER BY id", [$userId, ...$ids]);
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $mine = array_filter($attempts, fn($a) => (int) $a['exam_id'] === $id);
        $finished = array_filter($mine, fn($a) => $a['status'] !== 'in_progress');
        $open = array_filter($mine, fn($a) => $a['status'] === 'in_progress' && strtotime($a['expires_at']) + ANSWER_GRACE_SECONDS > time());
        $limit = (int) $r['max_attempts'] === 0 ? 0 : (int) $r['max_attempts'] + (int) ($extra[$id] ?? 0);
        $best = null; // highest-scoring finished attempt (first one on a tie)
        if ($finished && exam_scores_visible($r)) {
            foreach ($finished as $a) {
                if ($best === null || (float) $a['score'] > (float) $best['score']) {
                    $best = $a;
                }
            }
        }
        $out[$id] = [
            'attempts_used' => count($finished),
            'attempts_left' => $limit > 0 ? max(0, $limit - count($finished)) : null,
            'best_score' => $best ? (float) $best['score'] : null,
            // Out of that attempt's own total (a random pool's drawn questions), like the result page.
            'best_score_out_of' => $best ? exam_attempt_max_marks($r, $best) : null,
            'last_attempt_id' => $mine ? (int) end($mine)['id'] : null,
            'in_progress_attempt_id' => $open ? (int) end($open)['id'] : null,
        ];
    }
    return $out;
}

/**
 * Why a user cannot start this exam right now, or null when they can.
 * $limit / $used may be passed in when the caller already has them (batched lists).
 */
function start_blocker(array $exam, ?array $user, ?int $limit = null, ?int $used = null): ?array
{
    // The window is reported before "no questions yet" (an upcoming test may still be getting
    // its questions). A closed test is closed for everyone, so it also beats the login /
    // enrolment prompts; an upcoming one keeps them, as they are things the student can do now.
    $window = exam_window($exam);
    if ($window === 'closed') {
        return ['code' => 'closed', 'message' => 'This test is closed.'];
    }
    if (!$user) {
        return ['code' => 'unauthenticated', 'message' => 'Please log in to attempt this test.'];
    }
    if ($user['user_type'] !== 'student') {
        return ['code' => 'forbidden', 'message' => 'Mock tests can be attempted from a student account.'];
    }
    if (!content_access_allowed($user, $exam['access_type'] === 'free' ? 'registered' : $exam['access_type'], $exam['course_id'] ? (int) $exam['course_id'] : null)) {
        return ['code' => 'not_enrolled', 'message' => 'This test is available to students enrolled in the linked course.'];
    }
    if ($window === 'upcoming') {
        return ['code' => 'not_started', 'message' => 'This test has not opened yet. It opens on ' . (new DateTime($exam['starts_at']))->format('j M Y, g:i A') . '.'];
    }
    if ((int) $exam['question_count'] === 0) {
        return ['code' => 'unavailable', 'message' => 'This test has no questions yet.'];
    }
    $limit ??= exam_attempt_limit($exam, (int) $user['id']);
    if ($limit > 0 && ($used ?? exam_attempts_used((int) $exam['id'], (int) $user['id'])) >= $limit) {
        return ['code' => 'attempts_exhausted', 'message' => 'You have used all attempts for this test.'];
    }
    return null;
}

function exam_detail(): never
{
    $slug = query_str('slug', 200);
    $exam = exam_from("e.slug = ? AND e.status = 'published'", [$slug]);
    if (!$exam) {
        api_error('Test not found.', 404, 'not_found');
    }
    $user = current_user();
    $visible = exam_scores_visible($exam);
    $attempts = [];
    $inProgress = null;
    if ($user) {
        $rows = db_all('SELECT id, attempt_no, status, score, started_at, submitted_at, expires_at, question_order FROM exam_attempts WHERE exam_id = ? AND user_id = ? ORDER BY attempt_no DESC', [$exam['id'], $user['id']]);
        foreach ($rows as $a) {
            if ($a['status'] === 'in_progress' && strtotime($a['expires_at']) > time() - ANSWER_GRACE_SECONDS) {
                $inProgress = (int) $a['id'];
                continue;
            }
            if ($a['status'] === 'in_progress') {
                exam_finalize_attempt((int) $a['id'], true); // expired while away
                $a = db_one('SELECT id, attempt_no, status, score, started_at, submitted_at, question_order FROM exam_attempts WHERE id = ?', [$a['id']]);
            }
            $attempts[] = [
                'id' => (int) $a['id'],
                'attempt_no' => (int) $a['attempt_no'],
                'status' => $a['status'],
                'submitted_at' => iso_datetime($a['submitted_at']),
                'score' => $visible ? (float) $a['score'] : null,
                'total_marks' => $visible ? exam_attempt_max_marks($exam, $a) : null, // this attempt's own total (random pools)
            ];
        }
    }
    $blocker = $inProgress ? null : start_blocker($exam, $user);
    $limit = $user ? exam_attempt_limit($exam, (int) $user['id']) : (int) $exam['max_attempts'];
    $used = count($attempts);
    [$sections, $timed] = public_sections($exam);
    $base = format_exam($exam);
    if ($timed) {
        $base['duration_minutes'] = array_sum(array_column($sections, 'minutes')); // the section timers make the test time
    }
    api_ok([
        'sections' => $sections,
        'sectional_timing' => $timed,
    ] + $base + [
        'instructions' => text_to_html($exam['instructions']),
        'pass_marks' => $exam['pass_marks'] !== null ? (float) $exam['pass_marks'] : null,
        'result_release' => exam_is_practice($exam) ? 'immediate' : $exam['result_release'],
        'answer_key_visibility' => $exam['answer_key_visibility'],
        'shuffle_questions' => (bool) $exam['shuffle_questions'],
        'shuffle_options' => (bool) $exam['shuffle_options'],
        'attempts' => $attempts,
        'attempts_used' => $used,
        'attempts_allowed' => $limit, // 0 = unlimited (includes extra attempts granted to this student)
        'attempts_left' => $limit > 0 ? max(0, $limit - $used) : null,
        'in_progress_attempt_id' => $inProgress,
        'can_start' => $inProgress === null && $blocker === null,
        'blocker' => $blocker,
    ]);
}

function start_attempt(): never
{
    $user = require_student();
    $slug = (string) (request_body()['slug'] ?? '');
    $exam = exam_from("e.slug = ? AND e.status = 'published'", [$slug]);
    if (!$exam) {
        api_error('Test not found.', 404, 'not_found');
    }
    // Close expired attempts first, in their own transaction, so a blocker below cannot roll them back.
    foreach (db_all("SELECT id FROM exam_attempts WHERE exam_id = ? AND user_id = ? AND status = 'in_progress' AND expires_at <= NOW()", [$exam['id'], $user['id']]) as $old) {
        exam_finalize_attempt((int) $old['id'], true);
    }

    $closed = null; // attempt closed inside the transaction; its follow-ups run after commit
    $result = db_transaction(function () use ($exam, $user, &$closed): array {
        // Serialise concurrent "start" clicks for the same user + exam.
        db_query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user['id']]);
        $open = db_one("SELECT id, expires_at FROM exam_attempts WHERE exam_id = ? AND user_id = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1", [$exam['id'], $user['id']]);
        if ($open && strtotime($open['expires_at']) > time()) {
            return ['id' => (int) $open['id'], 'resumed' => true];
        }
        if ($open && exam_finalize_attempt((int) $open['id'], true, false)) { // expired a moment ago
            $closed = (int) $open['id'];
        }
        if ($blocker = start_blocker($exam, $user)) {
            return ['blocker' => $blocker];
        }
        [$ids, $optionOrder, $plan] = exam_build_attempt_order($exam);
        $now = time(); // started_at is written from the same clock as the deadline (section timers count from it)
        $seconds = match (true) {
            $plan !== null && $plan['timed'] => array_sum(array_column($plan['sections'], 'seconds')),
            exam_is_practice($exam) => EXAM_PRACTICE_OPEN_HOURS * 3600,
            default => (int) $exam['duration_minutes'] * 60,
        };
        $expires = $now + $seconds;
        if ($exam['ends_at']) {
            $expires = min($expires, strtotime($exam['ends_at']));
        }
        $no = (int) db_value('SELECT COALESCE(MAX(attempt_no), 0) + 1 FROM exam_attempts WHERE exam_id = ? AND user_id = ?', [$exam['id'], $user['id']]);
        $withPlan = exam_v11();
        db_query(
            'INSERT INTO exam_attempts (exam_id, user_id, attempt_no, question_order, option_order, ' . ($withPlan ? 'section_plan, ' : '') . 'started_at, expires_at, ip_address)
             VALUES (?, ?, ?, ?, ?, ' . ($withPlan ? '?, ' : '') . '?, ?, ?)',
            [$exam['id'], $user['id'], $no, json_encode($ids), $optionOrder ? json_encode((object) $optionOrder) : null,
                ...($withPlan ? [$plan ? json_encode($plan) : null] : []), date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $expires), client_ip()]
        );
        return ['id' => (int) db()->lastInsertId(), 'resumed' => false];
    });
    if ($closed !== null) {
        exam_after_finalize($closed); // result email + linked course lessons
    }
    if (isset($result['blocker'])) {
        api_error($result['blocker']['message'], $result['blocker']['code'] === 'unauthenticated' ? 401 : 403, $result['blocker']['code']);
    }
    api_ok(['attempt_id' => $result['id'], 'resumed' => $result['resumed']], $result['resumed'] ? 200 : 201);
}

/** Load an attempt that belongs to the current student (IDOR protection). */
function own_attempt(int $attemptId, array $user, bool $lock = false): array
{
    $a = db_one('SELECT * FROM exam_attempts WHERE id = ? AND user_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$attemptId, $user['id']]);
    if (!$a) {
        api_error('Attempt not found.', 404, 'not_found');
    }
    return $a;
}

/** Close an expired attempt (after the grace period) and report it; returns the fresh row. */
function expire_if_due(array $a, array $user): array
{
    if ($a['status'] === 'in_progress' && strtotime($a['expires_at']) + ANSWER_GRACE_SECONDS <= time()) {
        exam_finalize_attempt((int) $a['id'], true);
        $a = own_attempt((int) $a['id'], $user);
    }
    return $a;
}

/** Feedback for a checked practice answer; the key is withheld when the quiz is set to never show it. */
function practice_feedback(array $exam, ?string $explanation, array $correctIds, bool $correct, float $marks, ?string $explanationHi = null): array
{
    $showKey = ($exam['answer_key_visibility'] ?? '') !== 'never';
    return [
        'correct' => $correct,
        'correct_option_ids' => $showKey ? $correctIds : null,
        'explanation' => $showKey && $exam['show_explanations'] ? $explanation : null,
        'explanation_hi' => $showKey && $exam['show_explanations'] ? exam_hi($explanationHi) : null,
        'marks_awarded' => $marks == 0.0 ? 0.0 : $marks, // normalise -0.0 to 0.0
    ];
}

/**
 * Where an attempt stands with its sections: [schedule, index of the running section, plan].
 * Schedule and index are null unless the sections are timed; the plan is null without sections.
 */
function timed_state(array $a): array
{
    $plan = exam_attempt_plan($a);
    if (!$plan || !$plan['timed']) {
        return [null, null, $plan];
    }
    $schedule = exam_section_schedule($a, $plan);
    return [$schedule, $schedule['current'], $plan];
}

function attempt_view(): never
{
    $user = require_student();
    $a = expire_if_due(own_attempt(query_int('id', 0, 0), $user), $user);
    $exam = exam_from('e.id = ?', [$a['exam_id']]);
    if ($a['status'] !== 'in_progress') {
        api_ok(['status' => $a['status'], 'attempt_id' => (int) $a['id'], 'exam' => ['slug' => $exam['slug'], 'title' => $exam['title'], 'mode' => $exam['mode']]]);
    }
    $practice = exam_is_practice($exam);
    $order = exam_attempt_question_ids($a);
    [$schedule, $running, $plan] = timed_state($a);
    $sectionOf = $plan ? exam_plan_section_map($plan, $order) : [];
    $in = db_placeholders(count($order));
    $byId = $order ? array_column(db_all("SELECT id, question_type, topic, question_text, image_path, marks, negative_marks, " . exam_q_extra_cols() . " FROM exam_questions WHERE exam_id = ? AND id IN ({$in})", [$a['exam_id'], ...$order]), null, 'id') : [];
    $optsByQ = [];
    if ($order) {
        // Never select is_correct here: the key is not part of an attempt payload.
        foreach (db_all("SELECT id, question_id, option_text, " . exam_o_extra_cols() . " FROM exam_options WHERE question_id IN ({$in}) ORDER BY sort_order, id", $order) as $o) {
            $optsByQ[(int) $o['question_id']][] = ['id' => (int) $o['id'], 'text' => $o['option_text'], 'text_hi' => exam_hi($o['option_text_hi'])];
        }
    }
    $optOrder = exam_option_order($a);

    $answers = [];
    $checked = [];
    foreach (db_all('SELECT question_id, selected_option_ids, is_marked_review, is_correct, marks_awarded FROM exam_answers WHERE attempt_id = ?', [$a['id']]) as $ans) {
        $qid = (int) $ans['question_id'];
        $isChecked = $practice && $ans['is_correct'] !== null;
        $answers[$qid] = [
            'option_ids' => array_map('intval', json_decode_array($ans['selected_option_ids'])),
            'marked' => (bool) $ans['is_marked_review'],
            'checked' => $isChecked,
            'feedback' => null,
        ];
        if ($isChecked) {
            $checked[$qid] = [(bool) $ans['is_correct'], (float) $ans['marks_awarded']];
        }
    }
    if ($checked) {
        // Practice: feedback only for questions the student has already checked (and locked).
        $ids = array_keys($checked);
        $expl = array_column(db_all('SELECT id, explanation, ' . exam_q_extra_cols() . ' FROM exam_questions WHERE id IN (' . db_placeholders(count($ids)) . ')', $ids), null, 'id');
        $keys = [];
        foreach (db_all('SELECT question_id, id FROM exam_options WHERE is_correct = 1 AND question_id IN (' . db_placeholders(count($ids)) . ') ORDER BY sort_order, id', $ids) as $o) {
            $keys[(int) $o['question_id']][] = (int) $o['id'];
        }
        foreach ($checked as $qid => [$ok, $marks]) {
            $answers[$qid]['feedback'] = practice_feedback($exam, $expl[$qid]['explanation'] ?? null, $keys[$qid] ?? [], $ok, $marks, $expl[$qid]['explanation_hi'] ?? null);
        }
    }

    $list = [];
    $hindi = false;
    $first = []; // section index => number of its first question
    foreach ($order as $qid) {
        if (!isset($byId[$qid])) {
            continue;
        }
        $q = $byId[$qid];
        $section = $sectionOf[$qid] ?? null;
        $number = count($list) + 1;
        if ($section !== null) {
            $first[$section] ??= $number;
        }
        if ($schedule !== null && $section !== $running) {
            // Timed sections: only the running section's questions are sent (no peeking ahead, no going back).
            $list[] = ['id' => (int) $q['id'], 'number' => $number, 'section' => $section, 'locked' => true, 'type' => $q['question_type'],
                'marks' => (float) $q['marks'], 'negative_marks' => exam_negative_for($exam, $q), 'options' => []];
            continue;
        }
        $options = exam_order_options($optsByQ[$qid] ?? [], $optOrder[(string) $qid] ?? null);
        $textHi = exam_hi($q['question_text_hi']);
        $hindi = $hindi || $textHi !== null;
        $list[] = [
            'id' => (int) $q['id'],
            'number' => $number,
            'section' => $section,
            'type' => $q['question_type'],
            'topic' => $q['topic'],
            'text' => $q['question_text'],
            'text_hi' => $textHi,
            'image_url' => public_file_url($q['image_path']),
            'marks' => (float) $q['marks'],
            'negative_marks' => exam_negative_for($exam, $q),
            'options' => $options,
        ];
    }
    if (!$hindi && $schedule !== null && exam_v11()) {
        // Hindi in a later section still shows the language switch from the start.
        $hindi = (bool) db_value("SELECT 1 FROM exam_questions WHERE exam_id = ? AND question_text_hi IS NOT NULL AND question_text_hi <> '' LIMIT 1", [$a['exam_id']]);
    }
    $now = time();
    $sections = null;
    if ($plan) {
        $sections = [];
        foreach ($plan['sections'] as $i => $s) {
            $row = $schedule['rows'][$i] ?? null;
            $sections[] = [
                'index' => $i,
                'title' => $s['title'],
                'title_hi' => $s['title_hi'] ?? null,
                'seconds' => $s['seconds'] ?? null,
                'count' => (int) $s['count'],
                'first' => $first[$i] ?? null,
                'state' => $schedule === null ? 'open' : ($running === null || $i < $running ? 'done' : ($i === $running ? 'current' : 'upcoming')),
                'ends_at' => $row && $i === $running ? iso_datetime(date('Y-m-d H:i:s', $row['end'])) : null,
            ];
        }
    }
    api_ok([
        'status' => 'in_progress',
        'attempt_id' => (int) $a['id'],
        'attempt_no' => (int) $a['attempt_no'],
        'mode' => $exam['mode'],
        'timed' => !$practice,
        'exam' => ['slug' => $exam['slug'], 'title' => $exam['title'], 'total_marks' => (float) $exam['total_marks'], 'mode' => $exam['mode'], 'duration_minutes' => (int) $exam['duration_minutes'],
            'marks_per_attempt' => round(array_sum(array_column($list, 'marks')), 2)], // marks of the questions in THIS attempt
        'seconds_left' => $practice ? null : max(0, strtotime($a['expires_at']) - $now),
        'started_at' => iso_datetime($a['started_at']),
        'expires_at' => $practice ? null : iso_datetime($a['expires_at']), // untimed practice: null
        'languages' => $hindi ? ['en', 'hi'] : ['en'],
        'sections' => $sections,
        'section_timed' => $schedule !== null,
        'current_section' => $schedule !== null ? $running : null,
        'section_seconds_left' => $schedule !== null && $running !== null ? max(0, $schedule['rows'][$running]['end'] - $now) : null,
        'questions' => $list,
        'answers' => (object) $answers,
    ]);
}

/** Validated, de-duplicated option ids for a question (422 on anything invalid). */
function selection_from(mixed $raw, array $question): array
{
    $selected = array_values(array_unique(array_map('intval', is_array($raw) ? $raw : [])));
    if ($selected) {
        $valid = (int) db_value('SELECT COUNT(*) FROM exam_options WHERE question_id = ? AND id IN (' . db_placeholders(count($selected)) . ')', [$question['id'], ...$selected]);
        if ($valid !== count($selected) || ($question['question_type'] !== 'multiple' && count($selected) > 1)) {
            api_error('Invalid option selection.', 422, 'validation_failed');
        }
    }
    return $selected;
}

/** An in-progress attempt that has not run out of time, plus the question asked in it. */
function answerable(array $in, array $user): array
{
    $a = own_attempt((int) ($in['attempt_id'] ?? $in['attempt'] ?? 0), $user);
    if ($a['status'] !== 'in_progress') {
        api_error('This attempt has already been submitted.', 409, 'already_submitted');
    }
    if (strtotime($a['expires_at']) + ANSWER_GRACE_SECONDS <= time()) {
        exam_finalize_attempt((int) $a['id'], true);
        api_error('Time is up. Your test was submitted automatically.', 409, 'time_up');
    }
    $qid = (int) ($in['question_id'] ?? 0);
    $qids = exam_attempt_question_ids($a);
    $q = in_array($qid, $qids, true)
        ? db_one('SELECT id, question_type, explanation, marks, negative_marks, ' . exam_q_extra_cols() . ' FROM exam_questions WHERE id = ? AND exam_id = ?', [$qid, $a['exam_id']])
        : null; // a question outside this attempt's random pool is not answerable
    if (!$q) {
        api_error('Invalid question.', 422, 'validation_failed');
    }
    [$schedule, , $plan] = timed_state($a);
    if ($schedule !== null) {
        // Timed sections: answers count only in the running section (a section that ran out of
        // time keeps the usual grace for answers already on their way; one finished early does not).
        $row = $schedule['rows'][exam_plan_section_map($plan, $qids)[$qid] ?? -1] ?? null;
        $now = time();
        if ($row === null || $now < $row['start']) {
            api_error('This section has not started yet.', 409, 'section_locked');
        }
        if ($now >= $row['end'] + ($row['early'] ? 0 : ANSWER_GRACE_SECONDS)) {
            api_error('Time for this section is over. Your answers in it are saved.', 409, 'section_closed');
        }
    }
    $exam = db_one('SELECT * FROM exams WHERE id = ?', [$a['exam_id']]);
    return [$a, $exam, $q];
}

/** Seconds left in the running timed section (null without timed sections). */
function section_seconds_left(array $a): ?int
{
    [$schedule, $running] = timed_state($a);
    return $schedule !== null && $running !== null ? max(0, $schedule['rows'][$running]['end'] - time()) : null;
}

function save_answer(): never
{
    $user = require_student();
    $in = request_body();
    [$a, $exam, $q] = answerable($in, $user);
    $selected = selection_from($in['option_ids'] ?? null, $q);
    if (exam_is_practice($exam)) {
        $prev = db_one('SELECT selected_option_ids, is_correct FROM exam_answers WHERE attempt_id = ? AND question_id = ?', [$a['id'], $q['id']]);
        if ($prev && $prev['is_correct'] !== null) {
            $was = array_map('intval', json_decode_array($prev['selected_option_ids']));
            sort($was);
            $now = $selected;
            sort($now);
            if ($was !== $now) {
                api_error('This answer has been checked and can no longer be changed.', 409, 'locked');
            }
        }
    }
    // A checked (practice) answer keeps its selection even if a stale request races the check.
    db_query(
        'INSERT INTO exam_answers (attempt_id, question_id, selected_option_ids, is_marked_review) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE selected_option_ids = IF(is_correct IS NULL, VALUES(selected_option_ids), selected_option_ids),
                                 is_marked_review = VALUES(is_marked_review)',
        [$a['id'], $q['id'], $selected ? json_encode($selected) : null, empty($in['marked']) ? 0 : 1]
    );
    api_ok(['saved' => true, 'seconds_left' => exam_is_practice($exam) ? null : max(0, strtotime($a['expires_at']) - time()), 'section_seconds_left' => section_seconds_left($a)]);
}

/**
 * Timed sections: finish the running section before its time is up and start the next one
 * straight away (like the real exam, a finished section cannot be opened again). "from" is the
 * section the student is finishing, so a double click or a timer that already moved on is harmless.
 */
function next_section(): never
{
    $user = require_student();
    $in = request_body();
    $id = (int) ($in['attempt_id'] ?? 0);
    $from = (int) ($in['from'] ?? -1);
    own_attempt($id, $user); // 404 for someone else's attempt
    $out = db_transaction(function () use ($id, $from): array {
        $a = db_one('SELECT * FROM exam_attempts WHERE id = ? FOR UPDATE', [$id]);
        $plan = exam_attempt_plan($a);
        if ($a['status'] !== 'in_progress') {
            return ['error' => ['This attempt has already been submitted.', 409, 'already_submitted']];
        }
        if (!$plan || !$plan['timed']) {
            return ['error' => ['This test has no timed sections.', 422, 'not_sectional']];
        }
        $running = exam_section_schedule($a, $plan)['current'];
        if ($running === null) {
            return ['time_up' => true];
        }
        if ($running !== $from) {
            return ['moved' => false]; // already moved on (section timer or another tab)
        }
        if ($running >= count($plan['sections']) - 1) {
            return ['error' => ['This is the last section - submit the test to finish.', 422, 'last_section']];
        }
        $plan['closed'][$running] = time();
        $rows = exam_section_schedule($a, $plan)['rows'];
        $end = $rows[count($rows) - 1]['end'];
        $endsAt = db_value('SELECT ends_at FROM exams WHERE id = ?', [$a['exam_id']]);
        if ($endsAt) {
            $end = min($end, strtotime((string) $endsAt));
        }
        db_query('UPDATE exam_attempts SET section_plan = ?, expires_at = ? WHERE id = ?', [json_encode($plan), date('Y-m-d H:i:s', $end), $id]);
        return ['moved' => true];
    });
    if (!empty($out['time_up'])) {
        exam_finalize_attempt($id, true);
        api_error('Time is up. Your test was submitted automatically.', 409, 'time_up');
    }
    if (isset($out['error'])) {
        api_error(...$out['error']);
    }
    api_ok(['moved' => $out['moved'], 'attempt_id' => $id]);
}

/** Practice quizzes: lock the answer to one question and reveal whether it was right. */
function check_answer(): never
{
    $user = require_student();
    $in = request_body();
    $a = own_attempt((int) ($in['attempt'] ?? $in['attempt_id'] ?? 0), $user);
    $examRow = db_one('SELECT mode FROM exams WHERE id = ?', [$a['exam_id']]);
    if (!exam_is_practice($examRow ?? [])) {
        api_error('Instant answer checking is available only in practice quizzes.', 403, 'practice_only');
    }
    [$a, $exam, $q] = answerable($in + ['attempt_id' => $a['id']], $user);
    $selected = selection_from($in['option_ids'] ?? null, $q);
    if (!$selected) {
        api_error('Choose an answer before checking.', 422, 'validation_failed', ['option_ids' => 'Choose an answer.']);
    }
    $correctIds = array_map('intval', array_column(db_all('SELECT id FROM exam_options WHERE question_id = ? AND is_correct = 1 ORDER BY sort_order, id', [$q['id']]), 'id'));
    $marked = !empty($in['marked']);

    $result = db_transaction(function () use ($a, $exam, $q, $selected, $correctIds, $marked): ?array {
        $locked = db_one('SELECT status FROM exam_attempts WHERE id = ? FOR UPDATE', [$a['id']]);
        if (!$locked || $locked['status'] !== 'in_progress') {
            return null;
        }
        $prev = db_one('SELECT selected_option_ids, is_correct, marks_awarded, is_marked_review FROM exam_answers WHERE attempt_id = ? AND question_id = ?', [$a['id'], $q['id']]);
        if ($prev && $prev['is_correct'] !== null) {
            // Already checked: answer with the locked result, never re-grade a new choice.
            return ['already' => true, 'selected' => array_map('intval', json_decode_array($prev['selected_option_ids'])), 'correct' => (bool) $prev['is_correct'], 'marks' => (float) $prev['marks_awarded']];
        }
        $ok = exam_selection_correct($selected, $correctIds);
        // No negative marking → a plain 0.0 (negating 0.0 gives -0.0, which the UI showed as "-0 marks").
        $neg = (float) exam_negative_for($exam, $q);
        $marks = $ok ? (float) $q['marks'] : ($neg > 0 ? -$neg : 0.0);
        db_query(
            'INSERT INTO exam_answers (attempt_id, question_id, selected_option_ids, is_marked_review, is_correct, marks_awarded) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE selected_option_ids = VALUES(selected_option_ids), is_marked_review = VALUES(is_marked_review),
                                     is_correct = VALUES(is_correct), marks_awarded = VALUES(marks_awarded)',
            [$a['id'], $q['id'], json_encode($selected), ($marked || ($prev && $prev['is_marked_review'])) ? 1 : 0, (int) $ok, $marks]
        );
        return ['already' => false, 'selected' => $selected, 'correct' => $ok, 'marks' => $marks];
    });
    if ($result === null) {
        api_error('This attempt has already been submitted.', 409, 'already_submitted');
    }
    api_ok(['question_id' => (int) $q['id'], 'already_checked' => $result['already'], 'your_option_ids' => $result['selected']]
        + practice_feedback($exam, $q['explanation'], $correctIds, $result['correct'], $result['marks'], $q['explanation_hi']));
}

function submit_attempt(): never
{
    $user = require_student();
    $in = request_body();
    $id = (int) ($in['attempt_id'] ?? $in['attempt'] ?? 0);
    $a = own_attempt($id, $user);
    // The client cannot choose the status: a "timeout" claim counts only near the real
    // deadline, and a passed deadline always makes it automatic (decided in finalise).
    $auto = ($in['reason'] ?? 'manual') === 'timeout' && time() >= strtotime($a['expires_at']) - ANSWER_GRACE_SECONDS;
    exam_finalize_attempt($id, $auto);
    $row = db_one('SELECT status FROM exam_attempts WHERE id = ?', [$id]);
    $exam = db_one('SELECT slug, mode, result_release, ends_at, results_released_at FROM exams WHERE id = ?', [$a['exam_id']]);
    api_ok([
        'attempt_id' => $id,
        'status' => $row['status'],
        'auto_submitted' => $row['status'] === 'auto_submitted',
        'released' => exam_scores_visible($exam),
        'result_url' => '/mock-tests/' . $exam['slug'] . '/result/' . $id,
    ]);
}

/** Student-facing review row: correctness, key and explanation only when the key is visible. */
function public_review_item(array $row, bool $keyVisible, bool $withExplanations): array
{
    $status = $keyVisible ? $row['status'] : ($row['selected'] ? 'answered' : 'unanswered');
    return [
        'id' => $row['id'],
        'number' => $row['number'],
        'section' => $row['section'],
        'type' => $row['type'],
        'topic' => $row['topic'],
        'text' => $row['text'],
        'text_hi' => $row['text_hi'],
        'image_url' => $row['image_url'],
        'marks' => $row['marks'],
        'negative_marks' => $row['negative_marks'],
        'options' => $keyVisible ? $row['options'] : array_map(fn($o) => ['id' => $o['id'], 'text' => $o['text'], 'text_hi' => $o['text_hi']], $row['options']),
        'selected' => $row['selected'],
        'your_option_ids' => $row['selected'],
        'correct_option_ids' => $keyVisible ? $row['correct_ids'] : null,
        'status' => $status,
        'result' => $status,
        'marks_awarded' => $keyVisible ? $row['marks_awarded'] : null,
        'explanation' => $keyVisible && $withExplanations ? $row['explanation'] : null,
        'explanation_hi' => $keyVisible && $withExplanations ? $row['explanation_hi'] : null,
        'marked' => $row['marked'],
    ];
}

function result_view(): never
{
    $user = require_student();
    $a = expire_if_due(own_attempt(query_int('id', 0, 0), $user), $user);
    if ($a['status'] === 'in_progress') {
        api_error('This attempt is still in progress.', 409, 'in_progress');
    }
    $exam = exam_from('e.id = ?', [$a['exam_id']]);
    $visible = exam_scores_visible($exam);
    $base = [
        'attempt_id' => (int) $a['id'],
        'attempt_no' => (int) $a['attempt_no'],
        'exam' => format_exam($exam),
        'mode' => $exam['mode'],
        'status' => $a['status'],
        'auto_submitted' => $a['status'] === 'auto_submitted',
        'submitted_at' => iso_datetime($a['submitted_at']),
        'released' => $visible,
    ];
    if (!$visible) {
        api_ok($base + [
            'message' => $exam['result_release'] === 'after_end' && $exam['ends_at']
                ? 'Results will be available after the test closes on ' . (new DateTime($exam['ends_at']))->format('j M Y, g:i A') . '.'
                : 'Results will be published by the academy soon.',
            'answer_key_visible' => false,
            'answer_key_message' => 'The answer key will be available once results are published.',
        ]);
    }

    $sheet = exam_attempt_sheet($exam, $a);
    [$keyVisible, $keyMessage] = exam_answer_key_state($exam, $a);
    $max = exam_attempt_max_marks($exam, $a);
    $score = (float) $a['score'];
    $correct = (int) $a['correct_count'];
    $wrong = (int) $a['wrong_count'];
    $negLost = 0.0;
    foreach ($sheet as $row) {
        if ($row['status'] === 'wrong' && (float) $row['marks_awarded'] < 0) {
            $negLost -= (float) $row['marks_awarded'];
        }
    }
    $stats = exam_rank_stats((int) $exam['id'], (int) $user['id']);
    $limit = exam_attempt_limit($exam, (int) $user['id']);
    $used = exam_attempts_used((int) $exam['id'], (int) $user['id']);
    $passMarks = $exam['pass_marks'] !== null ? (float) $exam['pass_marks'] : null;
    $topics = exam_topic_breakdown($sheet);
    $plan = exam_attempt_plan($a);
    $sections = $plan ? exam_section_breakdown($sheet, $plan, $a) : [];
    if (!$keyVisible) {
        // Per-topic / per-section correctness would reveal which answers were right before the key is out.
        $topics = array_map(fn($t) => ['topic' => $t['topic'], 'total' => $t['total'], 'correct' => null, 'wrong' => null, 'skipped' => $t['skipped'], 'score' => null, 'max_marks' => $t['max_marks'], 'accuracy' => null], $topics);
        $sections = array_map(fn($s) => ['correct' => null, 'wrong' => null, 'score' => null, 'accuracy' => null] + $s, $sections);
    }
    api_ok($base + [
        'score' => $score,
        'total_marks' => $max,
        'percentage' => $max > 0 ? round($score / $max * 100, 1) : null,
        'accuracy' => $correct + $wrong > 0 ? round(100 * $correct / ($correct + $wrong), 1) : null,
        'passed' => $passMarks !== null ? $score >= $passMarks : null,
        'pass_marks' => $passMarks,
        'negative_marks_lost' => round($negLost, 2),
        'attempted' => $correct + $wrong,
        'correct' => $correct,
        'wrong' => $wrong,
        'unanswered' => (int) $a['unanswered_count'],
        'time_taken_seconds' => exam_time_taken($a),
        'rank' => ['position' => $stats['position'], 'participants' => $stats['participants'], 'best_score' => $stats['best_score'], 'percentile' => $stats['percentile']],
        'total_attempts' => $stats['participants'],
        'percentile' => $stats['percentile'],
        'topper_score' => $stats['topper_score'],
        'average_score' => $stats['average_score'],
        'attempts_used' => $used,
        'attempts_left' => $limit > 0 ? max(0, $limit - $used) : null,
        'topics' => $topics,
        'sections' => $sections,
        'sectional_timing' => $plan !== null && $plan['timed'],
        'answer_key_visible' => $keyVisible,
        'answer_key_message' => $keyMessage,
        'review' => array_map(fn($row) => public_review_item($row, $keyVisible, (bool) $exam['show_explanations']), $sheet),
    ]);
}

/**
 * Whether the student may start a new attempt right now, per exam id - the same rules as
 * start_attempt (published, access, questions, window, attempts incl. granted extras;
 * practice quizzes obey max_attempts too). False while an attempt is still running.
 */
function retake_map(array $examIds, array $user): array
{
    $examIds = array_values(array_unique(array_map('intval', $examIds)));
    if (!$examIds) {
        return [];
    }
    $uid = (int) $user['id'];
    $in = db_placeholders(count($examIds));
    $exams = db_all(
        'SELECT ' . exam_list_columns() . " FROM exams e
         LEFT JOIN course_categories cat ON cat.id = e.category_id LEFT JOIN courses c ON c.id = e.course_id
         WHERE e.status = 'published' AND e.id IN ({$in})",
        $examIds
    );
    $extra = array_column(db_all("SELECT exam_id, extra_attempts FROM exam_attempt_allowances WHERE user_id = ? AND exam_id IN ({$in})", [$uid, ...$examIds]), 'extra_attempts', 'exam_id');
    // An expired in-progress attempt is closed (and counted) the moment the student starts again.
    $state = array_column(db_all(
        "SELECT exam_id,
                SUM(status <> 'in_progress' OR expires_at <= NOW()) AS used,
                SUM(status = 'in_progress' AND expires_at > NOW()) AS running
         FROM exam_attempts WHERE user_id = ? AND exam_id IN ({$in}) GROUP BY exam_id",
        [$uid, ...$examIds]
    ), null, 'exam_id');
    $out = array_fill_keys($examIds, false);
    foreach ($exams as $e) {
        $id = (int) $e['id'];
        $limit = (int) $e['max_attempts'] === 0 ? 0 : (int) $e['max_attempts'] + (int) ($extra[$id] ?? 0);
        $out[$id] = (int) ($state[$id]['running'] ?? 0) === 0
            && start_blocker($e, $user, $limit, (int) ($state[$id]['used'] ?? 0)) === null;
    }
    return $out;
}

/** Today's daily quiz and, for a logged-in student, their streak (includes/daily-quiz.php). */
function daily_view(): never
{
    require_once APP_ROOT . '/includes/daily-quiz.php';
    $user = current_user();
    $student = $user && $user['user_type'] === 'student' ? $user : null;
    if (!daily_ready()) {
        api_ok(['date' => date('Y-m-d'), 'quiz' => null, 'streak' => null, 'tomorrow' => false], 200, $student === null);
    }
    $today = date('Y-m-d');
    $q = daily_quiz_for($today);
    api_ok([
        'date' => $today,
        'quiz' => $q ? [
            'title' => $q['title'], 'slug' => $q['slug'], 'mode' => $q['mode'],
            'questions' => exam_questions_per_attempt($q, (int) $q['question_count']),
            'minutes' => $q['mode'] === 'practice' ? null : (int) $q['duration_minutes'],
        ] : null,
        'streak' => $student ? daily_streak((int) $student['id'], $today) : null,
        'tomorrow' => (bool) db_value('SELECT 1 FROM daily_quizzes WHERE quiz_date = ?', [date('Y-m-d', strtotime('+1 day'))]),
    ], 200, $student === null); // personalised responses are never cached
}

function my_attempts(): never
{
    $user = require_student();
    $rows = db_all(
        "SELECT a.id, a.attempt_no, a.status, a.score, a.correct_count, a.wrong_count, a.started_at, a.submitted_at, a.expires_at, a.question_order,
                e.id AS exam_id, e.title, e.slug, e.mode, e.total_marks, e.pass_marks, e.question_pool_count, e.result_release, e.ends_at, e.results_released_at
         FROM exam_attempts a JOIN exams e ON e.id = a.exam_id
         WHERE a.user_id = ? ORDER BY a.started_at DESC LIMIT 100",
        [$user['id']]
    );
    $retake = retake_map(array_column($rows, 'exam_id'), $user);
    api_ok(array_map(function ($r) use ($retake) {
        $show = $r['status'] !== 'in_progress' && exam_scores_visible($r);
        $max = $show ? exam_attempt_max_marks(['id' => $r['exam_id']] + $r, $r) : (float) $r['total_marks'];
        $passMarks = $r['pass_marks'] !== null ? (float) $r['pass_marks'] : null;
        $judged = (int) $r['correct_count'] + (int) $r['wrong_count'];
        return [
            'attempt_id' => (int) $r['id'],
            'attempt_no' => (int) $r['attempt_no'],
            'title' => $r['title'],
            'slug' => $r['slug'],
            'mode' => $r['mode'],
            'status' => ($r['status'] === 'in_progress' && strtotime($r['expires_at']) <= time()) ? 'expired' : $r['status'],
            'started_at' => iso_datetime($r['started_at']),
            'submitted_at' => iso_datetime($r['submitted_at']),
            'score' => $show ? (float) $r['score'] : null,
            'total_marks' => $max,
            'percentage' => $show && $max > 0 ? round((float) $r['score'] / $max * 100, 1) : null,
            // Pass/fail and accuracy are part of the result: hidden until the score is visible.
            'passed' => $show && $passMarks !== null ? (float) $r['score'] >= $passMarks : null,
            'pass_marks' => $passMarks,
            'accuracy' => $show && $judged > 0 ? round(100 * (int) $r['correct_count'] / $judged, 1) : null,
            'can_retake' => $retake[(int) $r['exam_id']] ?? false,
        ];
    }, $rows));
}
