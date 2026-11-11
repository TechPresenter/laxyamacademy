<?php
// Daily quiz schedule: one published quiz (or test) per day. Students see "Today's quiz" on their
// dashboard and the Mock Tests page, and keep a streak by finishing it on the day (includes/daily-quiz.php).
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/daily-quiz.php';

$me = require_staff_page();
require_permission('exams.manage');

if (!daily_ready()) {
    admin_start('Daily Quiz', null, 'tests', ['head' => true]);
    echo '<div class="alert alert-warning">Apply the latest database update first (System → Database Updates).</div>';
    admin_end();
    exit;
}

$today = date('Y-m-d');
$validDate = fn(string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d >= $today && $d <= date('Y-m-d', strtotime('+90 days'));
$quizzes = db_all("SELECT e.id, e.title, e.mode, (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = e.id) AS n
                   FROM exams e WHERE e.status = 'published' HAVING n > 0 ORDER BY e.mode = 'practice' DESC, e.title");
$quizIds = array_map(fn($q) => (int) $q['id'], $quizzes);

if (admin_post()) {
    $act = $_POST['_action'] ?? '';
    $date = (string) ($_POST['date'] ?? '');
    if ($act === 'set') {
        $examId = (int) ($_POST['exam_id'] ?? 0);
        if (!$validDate($date) || !in_array($examId, $quizIds, true)) {
            flash('error', 'Choose a published quiz and a date from today onwards (past days stay as they were, so streaks do not change).');
        } else {
            db_query('INSERT INTO daily_quizzes (quiz_date, exam_id, created_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE exam_id = VALUES(exam_id), created_by = VALUES(created_by)', [$date, $examId, $me['id']]);
            audit_log('daily_quiz_set', 'exams', $examId, ['date' => $date]);
            flash('success', 'Daily quiz for ' . date('D, j M', strtotime($date)) . ' saved.');
        }
    } elseif ($act === 'clear' && $validDate($date)) {
        db_query('DELETE FROM daily_quizzes WHERE quiz_date = ?', [$date]);
        flash('success', 'Removed the quiz for ' . date('D, j M', strtotime($date)) . '.');
    } elseif ($act === 'fill') {
        // Empty days in the next 7 get practice quizzes, the least recently scheduled first.
        $pool = db_all("SELECT e.id, MAX(d.quiz_date) AS last_used FROM exams e LEFT JOIN daily_quizzes d ON d.exam_id = e.id
                        WHERE e.status = 'published' AND e.mode = 'practice' AND EXISTS (SELECT 1 FROM exam_questions q WHERE q.exam_id = e.id)
                        GROUP BY e.id ORDER BY MAX(d.quiz_date) IS NOT NULL, MAX(d.quiz_date), e.id");
        $filled = 0;
        if ($pool) {
            $i = 0;
            for ($k = 0; $k < 7; $k++) {
                $d = date('Y-m-d', strtotime($today . " +{$k} days"));
                if (db_query('INSERT IGNORE INTO daily_quizzes (quiz_date, exam_id, created_by) VALUES (?, ?, ?)', [$d, $pool[$i % count($pool)]['id'], $me['id']])->rowCount()) {
                    $filled++;
                    $i++;
                }
            }
        }
        flash($pool ? 'success' : 'error', $pool ? ($filled ? plural_n($filled, 'day') . ' filled with practice quizzes.' : 'The next 7 days already have quizzes.') : 'Publish at least one practice quiz first (Tests & Quizzes → Practice Quizzes).');
    }
    redirect_to('daily-quiz.php');
}

$next = array_column(db_all('SELECT d.quiz_date, d.exam_id FROM daily_quizzes d WHERE d.quiz_date BETWEEN ? AND ?', [$today, date('Y-m-d', strtotime('+13 days'))]), 'exam_id', 'quiz_date');
$past = db_all('SELECT d.quiz_date, d.exam_id, e.title FROM daily_quizzes d JOIN exams e ON e.id = d.exam_id WHERE d.quiz_date BETWEEN ? AND ? ORDER BY d.quiz_date DESC',
    [date('Y-m-d', strtotime('-14 days')), date('Y-m-d', strtotime('-1 day'))]);
$todayDone = isset($next[$today]) ? daily_completions($today, (int) $next[$today]) : 0;
$gaps = 7 - count(array_filter(array_keys($next), fn($d) => $d <= date('Y-m-d', strtotime('+6 days'))));

admin_start('Daily Quiz', null, 'tests', ['head' => true,
    'subtitle' => 'One quiz a day keeps students coming back: they see “Today’s quiz” on their dashboard and build a streak by finishing it on the day.',
    'actions' => action_form('fill', [], icon('sparkles') . ' Fill empty days (next 7)', 'btn btn-light', null)]);
?>
<?php if (!$quizzes): ?><div class="alert alert-warning">There is no published quiz with questions yet. Create a practice quiz first (Tests &amp; Quizzes → Practice Quizzes).</div><?php endif; ?>
<?php if ($gaps > 0 && $quizzes): ?><div class="alert alert-info"><?= plural_n($gaps, 'day') ?> in the next week <?= $gaps === 1 ? 'has' : 'have' ?> no quiz - streaks skip those days, but students have nothing to do. Use “Fill empty days” or pick quizzes below.</div><?php endif; ?>
<div class="split-2-1" style="align-items:start">
  <section class="card">
    <div class="card-head"><h2><?= icon('calendar') ?> Next 14 days</h2><?php if (isset($next[$today])): ?><span class="badge b-green"><?= $todayDone ?> student<?= $todayDone === 1 ? '' : 's' ?> finished today’s quiz</span><?php endif; ?></div>
    <div class="table-wrap"><table class="t stack"><thead><tr><th>Day</th><th>Quiz</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>
    <?php for ($k = 0; $k < 14; $k++): $d = date('Y-m-d', strtotime($today . " +{$k} days")); $sel = (int) ($next[$d] ?? 0); $form = 'dq-' . $k; ?>
      <tr>
        <td data-label="Day" class="d2-nowrap"><strong><?= e(date('D, j M', strtotime($d))) ?></strong><?= $k === 0 ? ' <span class="badge b-blue">Today</span>' : ($k === 1 ? ' <span class="muted">Tomorrow</span>' : '') ?></td>
        <td data-label="Quiz"><select class="in" form="<?= $form ?>" name="exam_id" aria-label="Quiz for <?= e(date('j M', strtotime($d))) ?>"><option value="">— No quiz —</option>
          <?php foreach ($quizzes as $q): ?><option value="<?= (int) $q['id'] ?>"<?= $sel === (int) $q['id'] ? ' selected' : '' ?>><?= e($q['title']) ?> (<?= $q['mode'] === 'practice' ? 'quiz' : 'test' ?>, <?= (int) $q['n'] ?> Qs)</option><?php endforeach; ?>
        </select></td>
        <td class="actions"><form method="post" id="<?= $form ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="_action" value="set"><input type="hidden" name="date" value="<?= $d ?>"><button class="btn btn-light btn-sm"><?= icon('check') ?> Save</button></form>
          <?php if ($sel): ?><?= action_form('clear', ['date' => $d], icon('trash'), 'btn btn-light btn-sm btn-icon', 'Remove the quiz for ' . date('j M', strtotime($d)) . '?', 'Remove') ?><?php endif; ?></td>
      </tr>
    <?php endfor; ?>
    </tbody></table></div>
  </section>
  <section class="card">
    <div class="card-head"><h2><?= icon('history') ?> Last 14 days</h2></div>
    <?php if ($past): ?>
    <ul class="ig-list">
      <?php foreach ($past as $p): $n = daily_completions($p['quiz_date'], (int) $p['exam_id']); ?>
      <li><?= icon('check') ?> <span><strong><?= e(date('D, j M', strtotime($p['quiz_date']))) ?></strong> · <?= e($p['title']) ?> <span class="muted">· <?= $n ?> finished on the day</span></span></li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?><div class="empty"><span class="empty-ic"><?= icon('calendar') ?></span><strong>No daily quizzes yet</strong><p>Pick a quiz for today to start.</p></div><?php endif; ?>
    <div class="card-pad muted" style="padding-top:0;font-size:12.5px">Past days cannot be changed, so students’ streaks stay fair. A day without a quiz never breaks a streak.</div>
  </section>
</div>
<?php
admin_end();
