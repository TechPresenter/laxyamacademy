<?php
// Question categories (topics) across all mock tests: counts, rename/merge.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';

require_staff_page();
require_permission('exams.manage');

if (admin_post()) {
    $from = trim((string) ($_POST['from'] ?? ''));
    $to = mb_substr(trim((string) ($_POST['to'] ?? '')), 0, 80);
    if ($from === '' || $to === '') {
        flash('error', 'Enter the new topic name.');
    } elseif ($from === $to) {
        flash('info', 'The topic name is unchanged.');
    } else {
        $n = db_query('UPDATE exam_questions SET topic = ? WHERE topic = ?', [$to, $from])->rowCount();
        audit_log('question_topic_renamed', 'exam_questions', null, ['from' => $from, 'to' => $to, 'count' => $n]);
        flash('success', $n . ' question' . ($n === 1 ? '' : 's') . " moved to “{$to}”.");
    }
    redirect_to('topics.php');
}

// Questions are counted once each (the answers join multiplies rows); accuracy uses scored answers only.
$rows = db_all("SELECT q.topic, COUNT(DISTINCT q.id) AS questions, COUNT(DISTINCT q.exam_id) AS tests,
        ROUND(100 * SUM(an.is_correct = 1) / NULLIF(SUM(an.is_correct IS NOT NULL), 0), 1) AS accuracy,
        SUM(an.is_correct IS NOT NULL) AS answered
    FROM exam_questions q LEFT JOIN exam_answers an ON an.question_id = q.id
    WHERE q.topic IS NOT NULL GROUP BY q.topic ORDER BY q.topic");
$untagged = (int) db_value('SELECT COUNT(*) FROM exam_questions WHERE topic IS NULL');

admin_start('Question Categories', null, 'tests', ['head' => true, 'subtitle' => 'Topics are set on each question (or in CSV import). Rename a topic to move all its questions; renaming to an existing topic merges them.']);
?>
<?php if ($untagged && $rows): ?><div class="alert alert-info"><?= icon('info') ?><span><?= $untagged ?> question<?= $untagged === 1 ? ' has' : 's have' ?> no topic yet. Add topics from the <a href="questions.php">Question Bank</a> to see topic-wise results.</span></div><?php endif; ?>
<div class="card">
  <?php if (!$rows): ?>
  <div class="empty"><span class="empty-ic"><?= icon('tag') ?></span><strong>No topics yet</strong>
    <p><?= $untagged ? $untagged . ' question' . ($untagged === 1 ? ' has' : 's have') . ' no topic. ' : '' ?>Set a topic on each question (or a <code>topic</code> column in a CSV import) to group questions and see topic-wise accuracy here.</p>
    <a class="btn btn-light btn-sm" href="questions.php"><?= icon('database') ?> Open the Question Bank</a></div>
  <?php else: ?>
  <div class="table-wrap"><table class="t stack"><thead><tr><th scope="col">Topic</th><th scope="col">Questions</th><th scope="col">Tests</th><th scope="col">Student accuracy</th><th scope="col">Rename / merge</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td data-label="Topic"><div class="d1-main sm"><span class="d1-t"><?= e($r['topic']) ?></span></div></td>
      <td data-label="Questions" class="d1-num"><?= (int) $r['questions'] ?></td>
      <td data-label="Tests" class="d1-num"><?= (int) $r['tests'] ?></td>
      <td data-label="Accuracy"><?= $r['accuracy'] !== null ? '<span class="d1-num"><strong>' . e((string) $r['accuracy']) . '%</strong><small>' . (int) $r['answered'] . ' answer' . ((int) $r['answered'] === 1 ? '' : 's') . '</small></span>' : '<span class="muted">No answers yet</span>' ?></td>
      <td data-label="Rename"><form method="post" class="d1-inline-form" style="width:100%;max-width:320px"><?= csrf_field() ?><input type="hidden" name="from" value="<?= e($r['topic']) ?>"><input class="in" name="to" value="<?= e($r['topic']) ?>" maxlength="80" aria-label="New name for <?= e($r['topic']) ?>" style="flex:1;width:auto"><button class="btn btn-light btn-sm" data-confirm="Rename this topic for all its questions? Renaming to an existing topic merges them."><?= icon('check') ?> Save</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php admin_end();
