<?php
// Leaderboard per mock test: each student's best submitted attempt, ranked by score, then
// time taken, then the earliest attempt - the same ranking students see on their result page.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/exams.php';

require_staff_page();
require_permission('exams.manage');
$examId = (int) ($_GET['exam'] ?? 0);
$exams = db_all("SELECT e.id, e.title, e.mode, (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id = e.id AND a.status <> 'in_progress') AS n FROM exams e ORDER BY e.id DESC");
$examId = $examId ?: (int) ($exams[0]['id'] ?? 0);
$exam = db_one('SELECT * FROM exams WHERE id = ?', [$examId]);

$board = $exam ? array_slice(exam_ranked_best($examId), 0, 500) : [];
$users = [];
$attemptCounts = [];
if ($board) {
    $ids = array_map(fn($r) => (int) $r['user_id'], $board);
    $users = array_column(db_all('SELECT id, name, email FROM users WHERE id IN (' . db_placeholders(count($ids)) . ')', $ids), null, 'id');
    $attemptCounts = array_column(db_all("SELECT user_id, COUNT(*) AS n FROM exam_attempts WHERE exam_id = ? AND status <> 'in_progress' GROUP BY user_id", [$examId]), 'n', 'user_id');
}
$maxFor = fn(array $r) => exam_attempt_max_marks($exam, $r + ['exam_id' => $examId, 'question_order' => db_value('SELECT question_order FROM exam_attempts WHERE id = ?', [$r['id']])]);
$pooled = $exam && (int) $exam['question_pool_count'] > 0;

if ($exam && ($_GET['export'] ?? '') === 'csv') {
    require_permission('reports.export');
    csv_download('leaderboard-' . $exam['slug'] . '.csv', ['Rank', 'Student', 'Email', 'Score', 'Out of', 'Correct', 'Wrong', 'Time (s)', 'Attempts', 'Submitted'],
        array_map(fn($r, $i) => [$i + 1, $users[(int) $r['user_id']]['name'] ?? '', $users[(int) $r['user_id']]['email'] ?? '', $r['score'], $pooled ? $maxFor($r) : (float) $exam['total_marks'],
            $r['correct_count'], $r['wrong_count'], $r['seconds'], $attemptCounts[$r['user_id']] ?? 1, $r['submitted_at']], $board, array_keys($board)));
}

admin_start('Leaderboard', null, 'tests', ['head' => true, 'subtitle' => 'Best attempt per student, ranked by score, then time taken. Students see their own rank and percentile on the result page (not other students’ names).',
    // Nothing to export until someone is ranked.
    'actions' => $board && can('reports.export') ? '<a class="btn btn-light" href="?exam=' . $examId . '&export=csv">' . icon('download') . ' Export CSV</a>' : '']);
?>
<?php if ($exams): ?>
<form class="card d1-pickbar"><label for="ex" class="lbl">Test</label>
  <select id="ex" class="in" name="exam" data-autosubmit><?php foreach ($exams as $e): ?><option value="<?= (int) $e['id'] ?>"<?= (int) $e['id'] === $examId ? ' selected' : '' ?>><?= e($e['title']) ?><?= $e['mode'] === 'practice' ? ' (quiz)' : '' ?> · <?= (int) $e['n'] ?> submitted attempt<?= (int) $e['n'] === 1 ? '' : 's' ?></option><?php endforeach; ?></select>
  <noscript><button class="btn btn-light btn-sm">Show</button></noscript>
  <?php if ($exam): ?><a class="btn btn-light btn-sm" href="attempts.php?exam=<?= $examId ?>"><?= icon('users') ?> All attempts</a><?php endif; ?>
</form>
<?php endif; ?>
<?php if ($board):
    $scores = array_map(fn($r) => (float) $r['score'], $board);
?>
<div class="grid g-3 d1-kpis" style="margin-bottom:18px">
  <div class="card stat"><span class="ico tone-blue"><?= icon('users') ?></span><span><span class="label">Students ranked</span><span class="value" style="display:block;font-size:20px"><?= count($board) ?></span></span></div>
  <div class="card stat"><span class="ico tone-green"><?= icon('award') ?></span><span><span class="label">Topper’s score</span><span class="value" style="display:block;font-size:20px"><?= e(exam_format_marks(max($scores))) ?></span></span></div>
  <div class="card stat"><span class="ico tone-purple"><?= icon('chart') ?></span><span><span class="label">Average (best attempts)</span><span class="value" style="display:block;font-size:20px"><?= e(exam_format_marks(round(array_sum($scores) / count($scores), 2))) ?></span></span></div>
</div>
<?php endif; ?>
<div class="card">
  <?php if (!$board): ?>
  <div class="empty"><span class="empty-ic"><?= icon('trophy') ?></span><strong><?= $exams ? 'No one is ranked yet' : 'No tests yet' ?></strong>
    <p><?= $exams ? 'The leaderboard fills in as students submit this test. Each student’s best attempt counts.' : 'Create a mock test first; its leaderboard appears here once students submit it.' ?></p>
    <?php if (!$exams): ?><a class="btn btn-primary btn-sm" href="mock-tests.php?action=new"><?= icon('plus') ?> New test</a><?php endif; ?></div>
  <?php else: ?>
  <div class="table-wrap"><table class="t stack"><thead><tr><th scope="col">Rank</th><th scope="col">Student</th><th scope="col">Score</th><th scope="col">Correct / Wrong</th><th scope="col">Time</th><th scope="col">Attempts</th><th scope="col">Submitted</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>
  <?php foreach ($board as $i => $r): $u = $users[(int) $r['user_id']] ?? ['name' => 'Deleted user', 'email' => '']; ?>
    <tr><td data-label="Rank"><span class="badge <?= $i < 3 ? 'b-amber' : 'b-gray' ?>">#<?= $i + 1 ?></span></td>
      <td data-label="Student"><div class="d1-main sm"><a class="d1-t" href="users.php?id=<?= (int) $r['user_id'] ?>"><?= e($u['name']) ?></a><div class="d1-sub"><span><?= e($u['email']) ?></span></div></div></td>
      <td data-label="Score" class="d1-num"><strong><?= e(exam_format_marks((float) $r['score'])) ?></strong> / <?= e(exam_format_marks($pooled ? $maxFor($r) : (float) $exam['total_marks'])) ?></td>
      <td data-label="Correct / wrong" class="d1-num"><span style="color:var(--green)"><?= (int) $r['correct_count'] ?></span> / <span style="color:var(--red)"><?= (int) $r['wrong_count'] ?></span></td>
      <td data-label="Time" class="d1-num"><?= floor((int) $r['seconds'] / 60) ?>m <?= (int) $r['seconds'] % 60 ?>s</td>
      <td data-label="Attempts" class="d1-num"><?= (int) ($attemptCounts[$r['user_id']] ?? 1) ?></td>
      <td data-label="Submitted"><span class="d1-when"><?= e(fmt_dt($r['submitted_at'], 'j M Y')) ?><small><?= e(fmt_dt($r['submitted_at'], 'g:i A')) ?></small></span></td>
      <td class="actions"><a class="btn btn-light btn-sm" href="attempts.php?id=<?= (int) $r['id'] ?>"><?= icon('eye') ?> Answer sheet</a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php admin_end();
