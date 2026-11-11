<?php
/**
 * Daily quiz: one published quiz (or test) per calendar day, chosen in admin/daily-quiz.php. A student's
 * streak counts the scheduled days, going back from today, on which they finished that day's quiz on
 * the day itself. Days without a scheduled quiz are skipped (they never break a streak), and today
 * cannot break it until the day is over. Students take the quiz through the normal mock test pages.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** Database update 015 applied? */
function daily_ready(): bool
{
    static $on = null;
    return $on ??= (bool) db_value("SHOW TABLES LIKE 'daily_quizzes'");
}

/** The quiz scheduled for a date (Y-m-d) when it is published, else null. */
function daily_quiz_for(string $date): ?array
{
    return db_one(
        "SELECT d.quiz_date, e.id, e.title, e.slug, e.mode, e.duration_minutes, e.question_pool_count,
                (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = e.id) AS question_count
         FROM daily_quizzes d JOIN exams e ON e.id = d.exam_id
         WHERE d.quiz_date = ? AND e.status = 'published'",
        [$date]
    );
}

/**
 * Streak of a student: ['current', 'best', 'done_today', 'days' => the last 7 days as [date, scheduled, done]].
 * Looks back one year; "done" = an attempt of that day's quiz finished on that day.
 */
function daily_streak(int $userId, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $from = date('Y-m-d', strtotime($today . ' -365 days'));
    $scheduled = array_column(db_all('SELECT quiz_date, exam_id FROM daily_quizzes WHERE quiz_date BETWEEN ? AND ? ORDER BY quiz_date', [$from, $today]), 'exam_id', 'quiz_date');
    $done = [];
    if ($scheduled) {
        $exams = array_values(array_unique(array_map('intval', $scheduled)));
        $rows = db_all(
            "SELECT DISTINCT a.exam_id, DATE(a.submitted_at) AS d FROM exam_attempts a
             WHERE a.user_id = ? AND a.status <> 'in_progress' AND a.submitted_at >= ? AND a.exam_id IN (" . db_placeholders(count($exams)) . ')',
            [$userId, $from, ...$exams]
        );
        foreach ($rows as $r) {
            if ((int) ($scheduled[$r['d']] ?? 0) === (int) $r['exam_id']) {
                $done[$r['d']] = true;
            }
        }
    }
    $current = 0;
    foreach (array_reverse(array_keys($scheduled)) as $date) {
        if (isset($done[$date])) {
            $current++;
        } elseif ($date !== $today) {
            break; // a missed quiz day ends the streak (today is still open)
        }
    }
    $best = $run = 0;
    foreach (array_keys($scheduled) as $date) {
        if (isset($done[$date])) {
            $best = max($best, ++$run);
        } elseif ($date !== $today) {
            $run = 0;
        }
    }
    $days = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime($today . " -{$i} days"));
        $days[] = ['date' => $d, 'scheduled' => isset($scheduled[$d]), 'done' => isset($done[$d])];
    }
    return ['current' => $current, 'best' => max($best, $current), 'done_today' => isset($done[$today]), 'days' => $days];
}

/** Students who finished the quiz of a date on that date. */
function daily_completions(string $date, int $examId): int
{
    return (int) db_value("SELECT COUNT(DISTINCT user_id) FROM exam_attempts WHERE exam_id = ? AND status <> 'in_progress' AND DATE(submitted_at) = ?", [$examId, $date]);
}
