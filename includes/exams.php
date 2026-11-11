<?php
/**
 * Mock test + practice quiz engine, shared by api/mock-tests.php, the admin pages
 * (questions, attempts, leaderboard, mock-tests) and the scheduler.
 *
 * Rules kept in one place:
 *   - scoring happens only in exam_compute_score() (finalise, re-score, practice check);
 *   - an attempt's questions are its question_order (random pool + shuffle), options
 *     follow its option_order;
 *   - answer keys leave the server only when exam_answer_key_state() allows it;
 *   - test_result emails are sent once per attempt (exam_attempts.result_notified_at);
 *   - a finished attempt completes the course lessons that link the test (lessons.exam_id).
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once APP_ROOT . '/includes/catalog.php';

const EXAM_ANSWER_GRACE_SECONDS = 20;  // tolerance for network latency at the deadline
const EXAM_PRACTICE_OPEN_HOURS = 168;  // practice quizzes are untimed; an abandoned one closes after 7 days

// ------------------------------------------------------------------
// Exam settings
// ------------------------------------------------------------------

function exam_is_practice(array $exam): bool
{
    return ($exam['mode'] ?? 'exam') === 'practice';
}

/** open | upcoming | closed for the test window. */
function exam_window(array $exam): string
{
    $now = time();
    return match (true) {
        !empty($exam['starts_at']) && strtotime($exam['starts_at']) > $now => 'upcoming',
        !empty($exam['ends_at']) && strtotime($exam['ends_at']) <= $now => 'closed',
        default => 'open',
    };
}

/** Scores visible to students? Practice quizzes always show results; exams follow result_release. */
function exam_scores_visible(array $exam): bool
{
    return exam_is_practice($exam) || exam_results_visible($exam);
}

/** Marks deducted for a wrong answer: the question's own value, else the test default (only when negative marking is on). */
function exam_negative_for(array $exam, array $question): float
{
    if (empty($exam['negative_marking'])) {
        return 0.0;
    }
    $own = (float) ($question['negative_marks'] ?? 0);
    return $own > 0 ? $own : (float) ($exam['default_negative_marks'] ?? 0);
}

/** Questions asked per attempt (pool size when a random pool is set). */
function exam_questions_per_attempt(array $exam, int $bankCount): int
{
    $pool = (int) ($exam['question_pool_count'] ?? 0);
    return $pool > 0 ? min($pool, $bankCount) : $bankCount;
}

/**
 * The marks a student actually faces per attempt, from the question bank's marks
 * (count, sum, min, max). Returns:
 *   marks_per_attempt - the attempt total: the bank sum for a fixed set (or a pool that
 *                       covers the whole bank), pool size x marks when every question carries
 *                       the same marks, else null (the total depends on the questions drawn);
 *   uniform_marks     - the per-question marks when all questions carry the same, else null;
 *   marks_vary        - true when the attempt total differs between attempts (random pool,
 *                       mixed marks).
 * A test with no questions reports nulls.
 */
function exam_marks_summary(array $exam, int $bankCount, float $sum, ?float $min, ?float $max): array
{
    if ($bankCount <= 0 || $min === null || $max === null) {
        return ['marks_per_attempt' => null, 'uniform_marks' => null, 'marks_vary' => false];
    }
    $uniform = abs($max - $min) < 0.005 ? round($min, 2) : null;
    $perAttempt = exam_questions_per_attempt($exam, $bankCount);
    $total = match (true) {
        $perAttempt >= $bankCount => round($sum, 2),           // every attempt gets the whole bank
        $uniform !== null => round($uniform * $perAttempt, 2), // random pool, same marks everywhere
        default => null,                                        // random pool, mixed marks
    };
    return ['marks_per_attempt' => $total, 'uniform_marks' => $uniform, 'marks_vary' => $total === null];
}

// ------------------------------------------------------------------
// Bilingual questions (Hindi versions) and sections
// ------------------------------------------------------------------

/** Database update 011 applied (Hindi columns, exam_sections, attempt section plans)? Until then tests run as before. */
function exam_v11(): bool
{
    static $on = null;
    return $on ??= (bool) db_value("SHOW COLUMNS FROM exam_attempts LIKE 'section_plan'");
}

/** Extra exam_questions columns for a SELECT; $p is the table prefix such as 'q.'. */
function exam_q_extra_cols(string $p = ''): string
{
    return exam_v11() ? "{$p}section_id, {$p}question_text_hi, {$p}explanation_hi" : 'NULL AS section_id, NULL AS question_text_hi, NULL AS explanation_hi';
}

/** Extra exam_options columns for a SELECT. */
function exam_o_extra_cols(string $p = ''): string
{
    return exam_v11() ? "{$p}option_text_hi" : 'NULL AS option_text_hi';
}

/** A Hindi version, or null when it is blank. */
function exam_hi(?string $text): ?string
{
    $text = trim((string) $text);
    return $text === '' ? null : $text;
}

/** Sections of a test in order: id, title, title_hi, duration_minutes. */
function exam_sections_of(int $examId): array
{
    return exam_v11() ? db_all('SELECT id, title, title_hi, duration_minutes FROM exam_sections WHERE exam_id = ? ORDER BY sort_order, id', [$examId]) : [];
}

/**
 * Order of a sectioned attempt: questions grouped by section in section order (questions without
 * a section join the last one), shuffled inside their own section when the test shuffles. Empty
 * sections are left out; a random pool does not apply to sectioned tests. Returns [ids, plan]:
 *   plan = ['timed' => bool, 'sections' => [['id', 'title', 'title_hi', 'seconds', 'count'], ...], 'closed' => []]
 * Sections are timed only for a mock test with sectional timing on and minutes on every section.
 */
function exam_build_section_plan(array $exam, array $sections, array $rows): array
{
    $groups = array_fill_keys(array_map(fn($s) => (int) $s['id'], $sections), []);
    $lastId = (int) $sections[count($sections) - 1]['id'];
    foreach ($rows as $r) {
        $sid = (int) ($r['section_id'] ?? 0);
        $groups[isset($groups[$sid]) ? $sid : $lastId][] = (int) $r['id'];
    }
    $ids = [];
    $plan = ['timed' => false, 'sections' => [], 'closed' => []];
    $allTimed = true;
    foreach ($sections as $s) {
        $group = $groups[(int) $s['id']];
        if (!$group) {
            continue;
        }
        if (!empty($exam['shuffle_questions'])) {
            shuffle($group);
        }
        array_push($ids, ...$group);
        $minutes = (int) ($s['duration_minutes'] ?? 0);
        $allTimed = $allTimed && $minutes > 0;
        $plan['sections'][] = ['id' => (int) $s['id'], 'title' => $s['title'], 'title_hi' => exam_hi($s['title_hi'] ?? null), 'seconds' => $minutes * 60, 'count' => count($group)];
    }
    $plan['timed'] = $allTimed && $plan['sections'] && !exam_is_practice($exam) && !empty($exam['sectional_timing']);
    if (!$plan['timed']) {
        $plan['sections'] = array_map(fn($s) => ['seconds' => null] + $s, $plan['sections']);
    }
    return [$ids, $plan];
}

/** An attempt's section plan, or null when its test had no sections. */
function exam_attempt_plan(array $attempt): ?array
{
    $plan = json_decode_array($attempt['section_plan'] ?? null);
    if (empty($plan['sections']) || !is_array($plan['sections'])) {
        return null;
    }
    $plan['sections'] = array_values($plan['sections']);
    $plan['timed'] = !empty($plan['timed']);
    $plan['closed'] = array_map('intval', (array) ($plan['closed'] ?? []));
    return $plan;
}

/** Section index of every question id, from the plan's counts over the attempt's question order. */
function exam_plan_section_map(array $plan, array $qids): array
{
    $map = [];
    $pos = 0;
    $n = count($qids);
    foreach ($plan['sections'] as $i => $s) {
        for ($k = 0; $k < (int) $s['count'] && $pos < $n; $k++) {
            $map[$qids[$pos++]] = $i;
        }
    }
    while ($pos < $n) {
        $map[$qids[$pos++]] = count($plan['sections']) - 1;
    }
    return $map;
}

/**
 * Timed sections run back to back from the start of the attempt; finishing one early starts the
 * next one at that moment, and closing the browser never pauses the clock. Returns
 * ['rows' => [index => ['start', 'end', 'early']], 'current' => index of the running section, or null once all are over].
 */
function exam_section_schedule(array $attempt, array $plan, ?int $now = null): array
{
    $now ??= time();
    $t = strtotime($attempt['started_at']);
    $rows = [];
    $current = null;
    foreach ($plan['sections'] as $i => $s) {
        $full = $t + (int) $s['seconds'];
        $closed = $plan['closed'][$i] ?? null;
        $end = $closed !== null ? max($t, min($full, $closed)) : $full;
        $rows[$i] = ['start' => $t, 'end' => $end, 'early' => $closed !== null && $closed < $full];
        if ($current === null && $now < $end) {
            $current = $i;
        }
        $t = $end;
    }
    return ['rows' => $rows, 'current' => $current];
}

/** Section-wise result (like the topic breakdown) plus the time used in each timed section. */
function exam_section_breakdown(array $sheet, array $plan, array $attempt): array
{
    $map = exam_plan_section_map($plan, exam_attempt_question_ids($attempt));
    $schedule = $plan['timed'] ? exam_section_schedule($attempt, $plan) : null;
    $submitted = !empty($attempt['submitted_at']) ? strtotime($attempt['submitted_at']) : time();
    $out = [];
    foreach ($plan['sections'] as $i => $s) {
        $row = $schedule['rows'][$i] ?? null;
        $out[$i] = ['title' => $s['title'], 'title_hi' => $s['title_hi'] ?? null, 'total' => 0, 'correct' => 0, 'wrong' => 0, 'skipped' => 0, 'score' => 0.0, 'max_marks' => 0.0,
            'seconds_allowed' => $s['seconds'] ?? null, 'seconds_used' => $row ? max(0, min($row['end'], $submitted) - $row['start']) : null];
    }
    foreach ($sheet as $q) {
        $i = $map[$q['id']] ?? null;
        if ($i === null || !isset($out[$i])) {
            continue;
        }
        $out[$i]['total']++;
        $out[$i]['max_marks'] += $q['marks'];
        $out[$i]['score'] += (float) ($q['marks_awarded'] ?? 0);
        match ($q['status']) {
            'correct' => $out[$i]['correct']++,
            'wrong' => $out[$i]['wrong']++,
            default => $out[$i]['skipped']++,
        };
    }
    return array_values(array_map(function ($s) {
        $s['score'] = round($s['score'], 2);
        $s['accuracy'] = $s['correct'] + $s['wrong'] > 0 ? round(100 * $s['correct'] / ($s['correct'] + $s['wrong']), 1) : null;
        return $s;
    }, $out));
}

// ------------------------------------------------------------------
// Attempt allowance (admin can grant extra attempts per student)
// ------------------------------------------------------------------

function exam_extra_attempts(int $examId, int $userId): int
{
    return (int) db_value('SELECT extra_attempts FROM exam_attempt_allowances WHERE exam_id = ? AND user_id = ?', [$examId, $userId]);
}

/** Attempts this student may use in total; 0 = unlimited. */
function exam_attempt_limit(array $exam, int $userId): int
{
    $max = (int) $exam['max_attempts'];
    return $max === 0 ? 0 : $max + exam_extra_attempts((int) $exam['id'], $userId);
}

/** Finished (submitted or auto-submitted) attempts. */
function exam_attempts_used(int $examId, int $userId): int
{
    return (int) db_value("SELECT COUNT(*) FROM exam_attempts WHERE exam_id = ? AND user_id = ? AND status <> 'in_progress'", [$examId, $userId]);
}

// ------------------------------------------------------------------
// Answer key visibility
// ------------------------------------------------------------------

/**
 * Whether the student may see the answer key (correct options + explanations) for a
 * finished attempt, and if not, why. Returns [bool $visible, ?string $message].
 *
 *   never        - never shown;
 *   after_submit - as soon as the attempt's result is visible;
 *   after_result - once the result is visible AND the result is final for this student:
 *                  the window has closed, results were released by date/manually, or
 *                  the student has no attempts left (stops retake gaming).
 * Practice quizzes show the key after submission unless set to never.
 */
function exam_answer_key_state(array $exam, array $attempt): array
{
    if ($attempt['status'] === 'in_progress') {
        return [false, 'The answer key is available after you submit.'];
    }
    if (!exam_scores_visible($exam)) {
        return [false, 'The answer key will be available once results are published.'];
    }
    $rule = $exam['answer_key_visibility'] ?? 'after_result';
    if ($rule === 'never') {
        return [false, 'The answer key for this test is not published.'];
    }
    if ($rule === 'after_submit' || exam_is_practice($exam)) {
        return [true, null];
    }
    $closed = !empty($exam['ends_at']) && strtotime($exam['ends_at']) <= time();
    if ($closed || in_array($exam['result_release'], ['after_end', 'manual'], true)) {
        return [true, null];
    }
    $limit = exam_attempt_limit($exam, (int) $attempt['user_id']);
    $used = exam_attempts_used((int) $exam['id'], (int) $attempt['user_id']);
    if ($limit > 0 && $used >= $limit) {
        return [true, null];
    }
    $when = !empty($exam['ends_at']) ? ' or when the test closes on ' . date('j M Y, g:i A', strtotime($exam['ends_at'])) : '';
    $message = $limit > 0
        ? "The answer key unlocks after your last attempt ({$used} of {$limit} used){$when}."
        : ($when !== '' ? 'The answer key unlocks when the test closes on ' . date('j M Y, g:i A', strtotime($exam['ends_at'])) . '.' : 'The answer key is not shown for this test while it is open for more attempts.');
    return [false, $message];
}

// ------------------------------------------------------------------
// Building an attempt: random pool, question shuffle, option shuffle
// ------------------------------------------------------------------

/** [question ids in the order this attempt asks them, option order map or null, section plan or null]. */
function exam_build_attempt_order(array $exam): array
{
    $rows = db_all('SELECT id, ' . exam_q_extra_cols() . ' FROM exam_questions WHERE exam_id = ? ORDER BY sort_order, id', [$exam['id']]);
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $pool = (int) ($exam['question_pool_count'] ?? 0);
    $sections = $rows ? exam_sections_of((int) $exam['id']) : [];
    $plan = null;
    if ($sections) {
        [$ids, $plan] = exam_build_section_plan($exam, $sections, $rows);
    } elseif ($pool > 0 && $pool < count($ids)) {
        $picked = $ids;
        shuffle($picked);
        $picked = array_slice($picked, 0, $pool);
        if (empty($exam['shuffle_questions'])) {
            $pos = array_flip($ids); // keep the bank order for the chosen questions
            usort($picked, fn($a, $b) => $pos[$a] <=> $pos[$b]);
        }
        $ids = $picked;
    } elseif (!empty($exam['shuffle_questions'])) {
        shuffle($ids);
    }

    $optionOrder = null;
    if (!empty($exam['shuffle_options']) && $ids) {
        $optionOrder = [];
        $rows = db_all(
            'SELECT o.id, o.question_id FROM exam_options o JOIN exam_questions q ON q.id = o.question_id
             WHERE q.exam_id = ? AND q.question_type <> ? ORDER BY o.sort_order, o.id',
            [$exam['id'], 'true_false'] // True/False keeps its natural order
        );
        $byQ = [];
        foreach ($rows as $r) {
            $byQ[(int) $r['question_id']][] = (int) $r['id'];
        }
        foreach ($ids as $qid) {
            if (!empty($byQ[$qid])) {
                $opts = $byQ[$qid];
                shuffle($opts);
                $optionOrder[(string) $qid] = $opts;
            }
        }
    }
    return [$ids, $optionOrder, $plan];
}

/** Question ids of an attempt in the order the student saw them. */
function exam_attempt_question_ids(array $attempt): array
{
    $ids = array_values(array_unique(array_map('intval', json_decode_array($attempt['question_order'] ?? null))));
    if ($ids) {
        return $ids;
    }
    return array_map('intval', array_column(db_all('SELECT id FROM exam_questions WHERE exam_id = ? ORDER BY sort_order, id', [$attempt['exam_id']]), 'id'));
}

/** Reorder option rows (each with 'id') by the attempt's stored order; unknown/missing ids are appended. */
function exam_order_options(array $options, ?array $order): array
{
    if (!$order) {
        return $options;
    }
    $byId = [];
    foreach ($options as $o) {
        $byId[(int) $o['id']] = $o;
    }
    $out = [];
    foreach ($order as $id) {
        if (isset($byId[(int) $id])) {
            $out[] = $byId[(int) $id];
            unset($byId[(int) $id]);
        }
    }
    return array_merge($out, array_values($byId));
}

function exam_option_order(array $attempt): array
{
    return json_decode_array($attempt['option_order'] ?? null);
}

// ------------------------------------------------------------------
// Scoring
// ------------------------------------------------------------------

/** Correct option ids per question for an exam. */
function exam_correct_map(int $examId): array
{
    $map = [];
    foreach (db_all('SELECT o.question_id, o.id FROM exam_options o JOIN exam_questions q ON q.id = o.question_id WHERE q.exam_id = ? AND o.is_correct = 1', [$examId]) as $o) {
        $map[(int) $o['question_id']][] = (int) $o['id'];
    }
    return $map;
}

/** All-or-nothing comparison (multiple-correct questions need exactly the correct set). */
function exam_selection_correct(array $selected, array $correct): bool
{
    $selected = array_values(array_unique(array_map('intval', $selected)));
    $correct = array_values(array_unique(array_map('intval', $correct)));
    sort($selected);
    sort($correct);
    return $correct !== [] && $selected === $correct;
}

/**
 * Score an attempt against the current answer key without writing anything.
 * rows: question id => [is_correct (bool|null), marks awarded, has answer row].
 */
function exam_compute_score(array $exam, array $attempt): array
{
    $qids = exam_attempt_question_ids($attempt);
    $questions = $qids ? array_column(db_all(
        'SELECT id, question_type, marks, negative_marks FROM exam_questions WHERE exam_id = ? AND id IN (' . db_placeholders(count($qids)) . ')',
        [$attempt['exam_id'], ...$qids]
    ), null, 'id') : [];
    $correctMap = exam_correct_map((int) $attempt['exam_id']);
    $answers = [];
    foreach (db_all('SELECT question_id, selected_option_ids FROM exam_answers WHERE attempt_id = ?', [$attempt['id']]) as $ans) {
        $answers[(int) $ans['question_id']] = array_map('intval', json_decode_array($ans['selected_option_ids']));
    }
    $out = ['score' => 0.0, 'correct' => 0, 'wrong' => 0, 'unanswered' => 0, 'negative_lost' => 0.0, 'max_marks' => 0.0, 'rows' => []];
    foreach ($qids as $qid) {
        if (!isset($questions[$qid])) {
            continue; // question removed from the bank
        }
        $q = $questions[$qid];
        $out['max_marks'] += (float) $q['marks'];
        $sel = $answers[$qid] ?? [];
        if (!$sel) {
            $isCorrect = null;
            $marks = 0.0;
            $out['unanswered']++;
        } else {
            $isCorrect = exam_selection_correct($sel, $correctMap[$qid] ?? []);
            $marks = $isCorrect ? (float) $q['marks'] : -exam_negative_for($exam, $q);
            if ($isCorrect) {
                $out['correct']++;
            } else {
                $out['wrong']++;
                $out['negative_lost'] -= $marks;
            }
        }
        $out['score'] += $marks;
        $out['rows'][$qid] = [$isCorrect, $marks, array_key_exists($qid, $answers)];
    }
    $out['score'] = round($out['score'], 2);
    $out['negative_lost'] = round($out['negative_lost'], 2);
    return $out;
}

function exam_store_answer_scores(int $attemptId, array $calc): void
{
    foreach ($calc['rows'] as $qid => [$isCorrect, $marks, $hasRow]) {
        if ($hasRow) {
            db_query('UPDATE exam_answers SET is_correct = ?, marks_awarded = ? WHERE attempt_id = ? AND question_id = ?',
                [$isCorrect === null ? null : (int) $isCorrect, $marks, $attemptId, $qid]);
        }
    }
}

/**
 * Score and close an attempt exactly once (row lock + status check make repeated or
 * concurrent calls harmless). A passed deadline always makes it an automatic
 * submission, timed at the deadline. Returns true when this call closed the attempt.
 */
function exam_finalize_attempt(int $attemptId, bool $auto, bool $ownTransaction = true): bool
{
    $work = function () use ($attemptId, $auto): bool {
        $a = db_one('SELECT * FROM exam_attempts WHERE id = ? FOR UPDATE', [$attemptId]);
        if (!$a || $a['status'] !== 'in_progress') {
            return false;
        }
        $exam = db_one('SELECT * FROM exams WHERE id = ?', [$a['exam_id']]);
        $auto = $auto || strtotime($a['expires_at']) <= time();
        $calc = exam_compute_score($exam, $a);
        exam_store_answer_scores($attemptId, $calc);
        db_query(
            'UPDATE exam_attempts SET status = ?, submitted_at = ' . ($auto ? 'LEAST(NOW(), expires_at)' : 'NOW()') . ',
                    score = ?, correct_count = ?, wrong_count = ?, unanswered_count = ? WHERE id = ?',
            [$auto ? 'auto_submitted' : 'submitted', $calc['score'], $calc['correct'], $calc['wrong'], $calc['unanswered'], $attemptId]
        );
        return true;
    };
    $done = $ownTransaction ? db_transaction($work) : $work();
    if ($done && $ownTransaction) {
        exam_after_finalize($attemptId);
    }
    return $done;
}

/**
 * Follow-ups once an attempt is closed and committed: the test_result email (only when
 * results are already visible) and completing course lessons that link this test.
 * Callers that finalise inside their own transaction (ownTransaction = false) call this
 * after their commit. Both steps are idempotent; failures never fail the student's request.
 */
function exam_after_finalize(int $attemptId): void
{
    try {
        exam_send_result_email($attemptId);
    } catch (Throwable $e) {
        error_log('[laxyam] test_result email failed for attempt ' . $attemptId . ': ' . $e->getMessage());
    }
    try {
        exam_complete_linked_lessons($attemptId);
    } catch (Throwable $e) {
        error_log('[laxyam] linked lesson completion failed for attempt ' . $attemptId . ': ' . $e->getMessage());
    }
}

/**
 * A finished attempt (any mode, any score) completes every published lesson that links the
 * test (lessons.exam_id) in courses the student is actively enrolled in, then recalculates
 * course progress - update_course_progress() sends course_completed exactly once.
 * Lessons already completed are left untouched, so repeated calls change nothing.
 * Returns the number of lessons newly marked completed.
 */
function exam_complete_linked_lessons(int $attemptId): int
{
    $a = db_one('SELECT exam_id, user_id, status FROM exam_attempts WHERE id = ?', [$attemptId]);
    if (!$a || $a['status'] === 'in_progress') {
        return 0;
    }
    $userId = (int) $a['user_id'];
    $lessons = db_all(
        "SELECT l.id, l.course_id, lp.status AS progress_status
         FROM lessons l LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = ?
         WHERE l.exam_id = ? AND l.status = 'published'",
        [$userId, $a['exam_id']]
    );
    $enrolled = [];
    $touched = [];
    $newly = 0;
    foreach ($lessons as $l) {
        $courseId = (int) $l['course_id'];
        $enrolled[$courseId] ??= active_enrolment($userId, $courseId) !== null;
        if (!$enrolled[$courseId] || $l['progress_status'] === 'completed') {
            continue;
        }
        db_query(
            "INSERT INTO lesson_progress (user_id, course_id, lesson_id, status, completed_at) VALUES (?, ?, ?, 'completed', NOW())
             ON DUPLICATE KEY UPDATE status = 'completed', completed_at = COALESCE(completed_at, VALUES(completed_at))",
            [$userId, $courseId, $l['id']]
        );
        $touched[$courseId] = true;
        $newly++;
    }
    if (function_exists('update_course_progress')) {
        foreach (array_keys($touched) as $courseId) {
            update_course_progress($userId, $courseId);
        }
    }
    return $newly;
}

/** Re-score a finished attempt with the current answer key (after a key correction). Returns [old, new] or null. */
function exam_rescore_attempt(int $attemptId): ?array
{
    return db_transaction(function () use ($attemptId): ?array {
        $a = db_one('SELECT * FROM exam_attempts WHERE id = ? FOR UPDATE', [$attemptId]);
        if (!$a || $a['status'] === 'in_progress') {
            return null;
        }
        $exam = db_one('SELECT * FROM exams WHERE id = ?', [$a['exam_id']]);
        $calc = exam_compute_score($exam, $a);
        exam_store_answer_scores($attemptId, $calc);
        db_query('UPDATE exam_attempts SET score = ?, correct_count = ?, wrong_count = ?, unanswered_count = ? WHERE id = ?',
            [$calc['score'], $calc['correct'], $calc['wrong'], $calc['unanswered'], $attemptId]);
        return ['old' => (float) $a['score'], 'new' => $calc['score']];
    });
}

/** Re-score every finished attempt of a test. Returns [attempts, changed]. */
function exam_rescore_exam(int $examId): array
{
    $n = $changed = 0;
    foreach (db_all("SELECT id FROM exam_attempts WHERE exam_id = ? AND status <> 'in_progress' ORDER BY id", [$examId]) as $r) {
        $res = exam_rescore_attempt((int) $r['id']);
        if ($res) {
            $n++;
            $changed += abs($res['old'] - $res['new']) > 0.001 ? 1 : 0;
        }
    }
    return [$n, $changed];
}

/** Marks this attempt is out of: the attempt's own questions for a random pool, else the test total. */
function exam_attempt_max_marks(array $exam, array $attempt): float
{
    $total = (float) $exam['total_marks'];
    if ((int) ($exam['question_pool_count'] ?? 0) > 0 || $total <= 0) {
        $qids = exam_attempt_question_ids($attempt);
        if ($qids) {
            return (float) db_value('SELECT COALESCE(SUM(marks), 0) FROM exam_questions WHERE exam_id = ? AND id IN (' . db_placeholders(count($qids)) . ')', [$exam['id'], ...$qids]);
        }
    }
    return $total;
}

/** Seconds spent, capped at the deadline (lazily finalised attempts never show duration + grace). */
function exam_time_taken(array $attempt): int
{
    if (empty($attempt['submitted_at'])) {
        return 0;
    }
    $end = min(strtotime($attempt['submitted_at']), strtotime($attempt['expires_at']));
    return max(0, $end - strtotime($attempt['started_at']));
}

// ------------------------------------------------------------------
// Answer sheet (full, with key) - callers decide what a student may see
// ------------------------------------------------------------------

/**
 * Every question of an attempt in the student's order, with options in the attempt's
 * option order, the student's selection, the correct ids and the stored result.
 * For in-progress attempts the status is computed live (staff view / practice check).
 */
function exam_attempt_sheet(array $exam, array $attempt): array
{
    $qids = exam_attempt_question_ids($attempt);
    if (!$qids) {
        return [];
    }
    $in = db_placeholders(count($qids));
    $qs = array_column(db_all(
        "SELECT id, question_type, topic, question_text, image_path, explanation, marks, negative_marks, " . exam_q_extra_cols() . " FROM exam_questions WHERE exam_id = ? AND id IN ({$in})",
        [$exam['id'], ...$qids]
    ), null, 'id');
    $opts = [];
    foreach (db_all("SELECT id, question_id, option_text, " . exam_o_extra_cols() . ", is_correct FROM exam_options WHERE question_id IN ({$in}) ORDER BY sort_order, id", $qids) as $o) {
        $opts[(int) $o['question_id']][] = ['id' => (int) $o['id'], 'text' => $o['option_text'], 'text_hi' => exam_hi($o['option_text_hi']), 'correct' => (bool) $o['is_correct']];
    }
    $answers = array_column(db_all('SELECT question_id, selected_option_ids, is_marked_review, is_correct, marks_awarded FROM exam_answers WHERE attempt_id = ?', [$attempt['id']]), null, 'question_id');
    $optOrder = exam_option_order($attempt);
    $finished = $attempt['status'] !== 'in_progress';
    $plan = exam_attempt_plan($attempt);
    $sectionOf = $plan ? exam_plan_section_map($plan, $qids) : [];
    $sheet = [];
    foreach ($qids as $qid) {
        if (!isset($qs[$qid])) {
            continue;
        }
        $q = $qs[$qid];
        $options = exam_order_options($opts[$qid] ?? [], $optOrder[(string) $qid] ?? null);
        $correctIds = array_values(array_map(fn($o) => $o['id'], array_filter($options, fn($o) => $o['correct'])));
        $an = $answers[$qid] ?? null;
        $sel = $an ? array_map('intval', json_decode_array($an['selected_option_ids'])) : [];
        if (!$sel) {
            $status = 'unanswered';
        } elseif ($an['is_correct'] !== null) {
            $status = $an['is_correct'] ? 'correct' : 'wrong'; // as scored
        } else {
            $status = exam_selection_correct($sel, $correctIds) ? 'correct' : 'wrong'; // live (not yet scored)
        }
        $live = $sel ? (exam_selection_correct($sel, $correctIds) ? 'correct' : 'wrong') : 'unanswered';
        $sheet[] = [
            'id' => (int) $q['id'],
            'number' => count($sheet) + 1,
            'type' => $q['question_type'],
            'topic' => $q['topic'],
            'text' => $q['question_text'],
            'text_hi' => exam_hi($q['question_text_hi']),
            'section' => $sectionOf[$qid] ?? null,
            'image_url' => public_file_url($q['image_path']),
            'explanation' => $q['explanation'],
            'explanation_hi' => exam_hi($q['explanation_hi']),
            'marks' => (float) $q['marks'],
            'negative_marks' => exam_negative_for($exam, $q),
            'options' => $options,
            'selected' => $sel,
            'correct_ids' => $correctIds,
            'status' => $status,
            'live_status' => $live, // differs from status when the key changed after scoring
            'marks_awarded' => $an && $an['marks_awarded'] !== null ? (float) $an['marks_awarded'] : ($finished ? 0.0 : null),
            'marked' => $an ? (bool) $an['is_marked_review'] : false,
            'checked' => !$finished && $an && $an['is_correct'] !== null, // practice: answer locked by "check"
        ];
    }
    return $sheet;
}

/** Topic-wise breakdown from an answer sheet. */
function exam_topic_breakdown(array $sheet): array
{
    $topics = [];
    foreach ($sheet as $row) {
        $t = $row['topic'] ?: 'General';
        $topics[$t] ??= ['topic' => $t, 'total' => 0, 'correct' => 0, 'wrong' => 0, 'skipped' => 0, 'score' => 0.0, 'max_marks' => 0.0];
        $topics[$t]['total']++;
        $topics[$t]['max_marks'] += $row['marks'];
        $topics[$t]['score'] += (float) ($row['marks_awarded'] ?? 0);
        match ($row['status']) {
            'correct' => $topics[$t]['correct']++,
            'wrong' => $topics[$t]['wrong']++,
            default => $topics[$t]['skipped']++,
        };
    }
    ksort($topics, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values(array_map(function ($t) {
        $t['score'] = round($t['score'], 2);
        $t['accuracy'] = $t['correct'] + $t['wrong'] > 0 ? round(100 * $t['correct'] / ($t['correct'] + $t['wrong']), 1) : null;
        return $t;
    }, $topics));
}

// ------------------------------------------------------------------
// Ranking (best attempt per student; ties broken by time taken, then earliest attempt)
// ------------------------------------------------------------------

/** Best finished attempt per student, in rank order. Shared by the result page and the admin leaderboard. */
function exam_ranked_best(int $examId): array
{
    $rows = db_all(
        "SELECT a.id, a.user_id, a.attempt_no, a.status, a.score, a.correct_count, a.wrong_count, a.unanswered_count,
                a.started_at, a.submitted_at, a.expires_at,
                GREATEST(0, LEAST(TIMESTAMPDIFF(SECOND, a.started_at, a.submitted_at), TIMESTAMPDIFF(SECOND, a.started_at, a.expires_at))) AS seconds
         FROM exam_attempts a
         WHERE a.exam_id = ? AND a.status <> 'in_progress'
         ORDER BY a.score DESC, seconds ASC, a.id ASC",
        [$examId]
    );
    $best = [];
    foreach ($rows as $r) {
        $best[(int) $r['user_id']] ??= $r; // first row per student is their best
    }
    return array_values($best);
}

/** Rank, percentile and comparison figures for one student on a test. */
function exam_rank_stats(int $examId, int $userId): array
{
    $board = exam_ranked_best($examId);
    $n = count($board);
    $position = null;
    $mine = null;
    foreach ($board as $i => $r) {
        if ((int) $r['user_id'] === $userId) {
            $position = $i + 1;
            $mine = $r;
            break;
        }
    }
    $scores = array_map(fn($r) => (float) $r['score'], $board);
    $percentile = null;
    if ($mine && $n > 0) {
        $percentile = round(100 * count(array_filter($scores, fn($s) => $s <= (float) $mine['score'])) / $n, 2);
    }
    return [
        'position' => $position,
        'participants' => $n,
        'best_score' => $mine ? (float) $mine['score'] : null,
        'best_attempt_id' => $mine ? (int) $mine['id'] : null,
        'percentile' => $percentile,
        'topper_score' => $n ? max($scores) : null,
        'average_score' => $n ? round(array_sum($scores) / $n, 2) : null,
    ];
}

// ------------------------------------------------------------------
// Result emails (test_result template)
// ------------------------------------------------------------------

function exam_format_marks(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

/** Template variables for test_result (contract: course, score "42.5 / 60", result "Passed · 70.8%", link, related). */
function exam_result_email_vars(array $exam, array $attempt): array
{
    $max = exam_attempt_max_marks($exam, $attempt);
    $score = (float) $attempt['score'];
    $pct = $max > 0 ? round($score / $max * 100, 1) : null;
    $passed = $exam['pass_marks'] !== null ? $score >= (float) $exam['pass_marks'] : null;
    $result = trim(($passed === null ? '' : ($passed ? 'Passed' : 'Not passed')) . ($passed !== null && $pct !== null ? ' · ' : '') . ($pct !== null ? exam_format_marks($pct) . '%' : ''));
    return [
        'course' => $exam['title'],
        'score' => exam_format_marks($score) . ' / ' . exam_format_marks($max),
        'result' => $result !== '' ? $result : 'Submitted',
        'link' => '/mock-tests/' . $exam['slug'] . '/result/' . (int) $attempt['id'],
        'button_label' => 'View result',
        'related' => ['attempt', (int) $attempt['id']],
    ];
}

/**
 * Send test_result for one finished exam attempt once its result is visible.
 * The attempt is claimed first (result_notified_at) so parallel runs never double-send.
 */
function exam_send_result_email(int $attemptId): bool
{
    $a = db_one('SELECT * FROM exam_attempts WHERE id = ?', [$attemptId]);
    if (!$a || $a['status'] === 'in_progress' || $a['result_notified_at'] !== null) {
        return false;
    }
    $exam = db_one('SELECT * FROM exams WHERE id = ?', [$a['exam_id']]);
    if (!$exam || exam_is_practice($exam) || !exam_results_visible($exam)) {
        return false; // practice quizzes give instant feedback instead of emails
    }
    if (db_query('UPDATE exam_attempts SET result_notified_at = NOW() WHERE id = ? AND result_notified_at IS NULL', [$attemptId])->rowCount() !== 1) {
        return false;
    }
    require_once APP_ROOT . '/includes/notify.php';
    send_template('test_result', (int) $a['user_id'], exam_result_email_vars($exam, $a));
    return true;
}

/**
 * Scheduler job (and "Release results now"): email results that became visible by time
 * (after_end) or manual release since the last run. Each attempt is emailed once.
 * Without $examId only releases from the last 7 days are considered, so old tests are
 * never mass-mailed; with $examId every un-notified visible attempt of that test is sent.
 * Returns the number of emails sent.
 */
function exam_results_notify_due(?int $examId = null, int $limit = 200): int
{
    $limit = max(1, min(1000, $limit));
    if ($examId !== null) {
        $ids = db_all(
            "SELECT a.id FROM exam_attempts a JOIN exams e ON e.id = a.exam_id
             WHERE a.exam_id = ? AND e.mode = 'exam' AND a.status <> 'in_progress' AND a.result_notified_at IS NULL
             ORDER BY a.id LIMIT " . $limit,
            [$examId]
        );
    } else {
        $ids = db_all(
            "SELECT a.id FROM exam_attempts a JOIN exams e ON e.id = a.exam_id
             WHERE e.mode = 'exam' AND a.status <> 'in_progress' AND a.result_notified_at IS NULL
               AND (
                    (e.result_release = 'after_end' AND e.ends_at IS NOT NULL AND e.ends_at <= NOW() AND e.ends_at > NOW() - INTERVAL 7 DAY)
                 OR (e.result_release = 'manual' AND e.results_released_at IS NOT NULL AND e.results_released_at <= NOW() AND e.results_released_at > NOW() - INTERVAL 7 DAY)
                 OR (e.result_release IN ('immediate', 'after_end') AND (e.result_release = 'immediate' OR e.ends_at IS NULL) AND a.submitted_at > NOW() - INTERVAL 2 HOUR)
               )
             ORDER BY a.id LIMIT " . $limit
        );
    }
    $sent = 0;
    foreach ($ids as $r) {
        try {
            $sent += exam_send_result_email((int) $r['id']) ? 1 : 0;
        } catch (Throwable $e) {
            error_log('[laxyam] test_result email failed for attempt ' . $r['id'] . ': ' . $e->getMessage());
        }
    }
    return $sent;
}

// ------------------------------------------------------------------
// Admin helpers
// ------------------------------------------------------------------

/**
 * Copy questions (with options, images and Hindi versions) into another test, appended after its
 * last question. Multiple-correct questions are skipped when the target does not allow them.
 * $sectionMap (source section id => target section id) keeps sections when a whole test is duplicated.
 * Returns [copied, skipped]. Pass $ownTransaction = false when already inside one.
 */
function exam_copy_questions(array $questionIds, int $targetExamId, bool $allowMultiple, bool $ownTransaction = true, array $sectionMap = []): array
{
    $questionIds = array_values(array_unique(array_map('intval', $questionIds)));
    if (!$questionIds) {
        return [0, 0];
    }
    $work = function () use ($questionIds, $targetExamId, $allowMultiple, $sectionMap): array {
        $rows = db_all('SELECT * FROM exam_questions WHERE id IN (' . db_placeholders(count($questionIds)) . ') ORDER BY exam_id, sort_order, id', $questionIds);
        $order = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) FROM exam_questions WHERE exam_id = ?', [$targetExamId]);
        $copied = $skipped = 0;
        $hi = exam_v11(); // Hindi versions travel with the copy; sections belong to the source test
        foreach ($rows as $q) {
            if ($q['question_type'] === 'multiple' && !$allowMultiple) {
                $skipped++;
                continue;
            }
            db_query(
                'INSERT INTO exam_questions (exam_id, question_type, topic, question_text, image_path, explanation, marks, negative_marks, sort_order' . ($hi ? ', question_text_hi, explanation_hi, section_id' : '') . ')
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?' . ($hi ? ', ?, ?, ?' : '') . ')',
                [$targetExamId, $q['question_type'], $q['topic'], $q['question_text'], $q['image_path'], $q['explanation'], $q['marks'], $q['negative_marks'], ++$order,
                    ...($hi ? [$q['question_text_hi'], $q['explanation_hi'], $sectionMap[(int) $q['section_id']] ?? null] : [])]
            );
            $newId = (int) db()->lastInsertId();
            db_query('INSERT INTO exam_options (question_id, option_text, ' . ($hi ? 'option_text_hi, ' : '') . 'is_correct, sort_order)
                      SELECT ?, option_text, ' . ($hi ? 'option_text_hi, ' : '') . 'is_correct, sort_order FROM exam_options WHERE question_id = ? ORDER BY sort_order, id', [$newId, $q['id']]);
            $copied++;
        }
        return [$copied, $skipped];
    };
    return $ownTransaction ? db_transaction($work) : $work();
}

/** Human summary of when students see results, with a badge tone: [label, tone]. */
function exam_release_state(array $exam): array
{
    if (exam_is_practice($exam) || $exam['result_release'] === 'immediate') {
        return ['Shown immediately', 'green'];
    }
    if ($exam['result_release'] === 'after_end') {
        if (empty($exam['ends_at'])) {
            return ['Shown immediately (no closing time)', 'green'];
        }
        return strtotime($exam['ends_at']) <= time()
            ? ['Published ' . date('j M, g:i A', strtotime($exam['ends_at'])), 'green']
            : ['Hidden until ' . date('j M, g:i A', strtotime($exam['ends_at'])), 'amber'];
    }
    if (empty($exam['results_released_at'])) {
        return ['Awaiting release', 'amber'];
    }
    return strtotime($exam['results_released_at']) <= time()
        ? ['Released ' . date('j M, g:i A', strtotime($exam['results_released_at'])), 'green']
        : ['Scheduled for ' . date('j M, g:i A', strtotime($exam['results_released_at'])), 'blue'];
}
