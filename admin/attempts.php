<?php
// Mock test / quiz attempts: filterable list, answer-sheet drill-down (student's answer vs
// the correct one) and corrective actions - grant an extra attempt, reset (void) an attempt,
// re-score after a key correction, submit a stuck attempt. Every action is audit-logged.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/exams.php';

$me = require_staff_page();
require_permission('exams.manage');

// ------------------------------------------------------------------
// Actions
// ------------------------------------------------------------------
if (admin_post()) {
    $act = (string) ($_POST['_action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $a = $id ? db_one('SELECT * FROM exam_attempts WHERE id = ?', [$id]) : null;
    if (in_array($act, ['grant', 'revoke_grant'], true)) {
        $examId = (int) ($_POST['exam_id'] ?? 0);
        $userId = (int) ($_POST['user_id'] ?? 0);
        $exam = db_one('SELECT id, title, max_attempts FROM exams WHERE id = ?', [$examId]);
        $student = db_one("SELECT id, name FROM users WHERE id = ? AND user_type = 'student'", [$userId]);
        $back = $a ? 'attempts.php?id=' . $id : 'attempts.php?exam=' . $examId;
        if (!$exam || !$student) {
            flash('error', 'Test or student not found.');
            redirect_to($back);
        }
        if ($act === 'grant') {
            if ((int) $exam['max_attempts'] === 0) {
                flash('info', 'This test already allows unlimited attempts.');
                redirect_to($back);
            }
            $n = max(1, min(10, (int) ($_POST['extra'] ?? 1)));
            $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255) ?: null;
            db_query(
                'INSERT INTO exam_attempt_allowances (exam_id, user_id, extra_attempts, granted_by, note) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE extra_attempts = LEAST(250, extra_attempts + VALUES(extra_attempts)), granted_by = VALUES(granted_by), note = COALESCE(VALUES(note), note)',
                [$examId, $userId, $n, $me['id'], $note]
            );
            audit_log('exam_extra_attempt_granted', 'exams', $examId, ['user_id' => $userId, 'extra' => $n, 'note' => $note]);
            flash('success', "{$student['name']} can now take “{$exam['title']}” " . ($n === 1 ? 'one more time.' : "{$n} more times."));
        } else {
            db_query('DELETE FROM exam_attempt_allowances WHERE exam_id = ? AND user_id = ?', [$examId, $userId]);
            audit_log('exam_extra_attempts_removed', 'exams', $examId, ['user_id' => $userId], 'warning');
            flash('success', 'Extra attempts removed. The test’s normal attempt limit applies again.');
        }
        redirect_to($back);
    }
    if (!$a) {
        flash('error', 'Attempt not found.');
        redirect_to('attempts.php');
    }
    if ($act === 'reset') {
        $snapshot = [
            'exam_id' => (int) $a['exam_id'], 'user_id' => (int) $a['user_id'], 'attempt_no' => (int) $a['attempt_no'], 'status' => $a['status'],
            'score' => $a['score'], 'started_at' => $a['started_at'], 'submitted_at' => $a['submitted_at'],
            'answers' => (int) db_value('SELECT COUNT(*) FROM exam_answers WHERE attempt_id = ?', [$id]),
        ];
        db_query('DELETE FROM exam_attempts WHERE id = ?', [$id]); // answers go with it (FK cascade)
        audit_log('exam_attempt_reset', 'exam_attempts', $id, $snapshot, 'warning');
        flash('success', 'Attempt reset. It no longer counts towards the student’s attempts, rank or results.');
        redirect_to('attempts.php?exam=' . (int) $a['exam_id']);
    }
    if ($act === 'rescore') {
        $res = exam_rescore_attempt($id);
        if ($res) {
            audit_log('exam_attempt_rescored', 'exam_attempts', $id, $res, 'warning');
            flash('success', 'Re-scored with the current answer key: ' . exam_format_marks($res['old']) . ' → ' . exam_format_marks($res['new']) . '.');
        } else {
            flash('error', 'Only submitted attempts can be re-scored.');
        }
        redirect_to('attempts.php?id=' . $id);
    }
    if ($act === 'finalize') {
        if (exam_finalize_attempt($id, true)) {
            audit_log('exam_attempt_force_submitted', 'exam_attempts', $id, [], 'warning');
            flash('success', 'Attempt submitted and scored.');
        } else {
            flash('info', 'This attempt was already submitted.');
        }
        redirect_to('attempts.php?id=' . $id);
    }
    flash('error', 'Unknown action.');
    redirect_to('attempts.php');
}

function att_status_badge(array $a): string
{
    if ($a['status'] === 'in_progress' && strtotime($a['expires_at']) <= time()) {
        return '<span class="badge b-purple">Expired (not scored)</span>';
    }
    return match ($a['status']) {
        'auto_submitted' => '<span class="badge b-blue" title="Submitted automatically when the time ran out">Auto-submitted</span>',
        'in_progress' => '<span class="badge b-amber">In progress</span>',
        default => badge($a['status']),
    };
}

function att_duration(int $s): string
{
    return floor($s / 60) . 'm ' . ($s % 60) . 's';
}

/** Human label for an attempt status (never the raw value, e.g. "auto_submitted"). */
function att_status_label(string $status): string
{
    return ['in_progress' => 'In progress', 'submitted' => 'Submitted', 'auto_submitted' => 'Auto-submitted (time ran out)'][$status] ?? ucfirst(str_replace('_', ' ', $status));
}

// ------------------------------------------------------------------
// Answer sheet
// ------------------------------------------------------------------
if (isset($_GET['id'])) {
    $a = db_one('SELECT a.*, u.name, u.email, u.phone FROM exam_attempts a JOIN users u ON u.id = a.user_id WHERE a.id = ?', [(int) $_GET['id']]);
    if (!$a) {
        flash('error', 'Attempt not found.');
        redirect_to('attempts.php');
    }
    $exam = db_one('SELECT * FROM exams WHERE id = ?', [$a['exam_id']]);
    $sheet = exam_attempt_sheet($exam, $a);
    $finishedAttempt = $a['status'] !== 'in_progress';
    $max = exam_attempt_max_marks($exam, $a);
    $live = exam_compute_score($exam, $a);
    $score = $finishedAttempt ? (float) $a['score'] : $live['score'];
    $stale = $finishedAttempt && abs($live['score'] - (float) $a['score']) > 0.001;
    $neg = 0.0;
    foreach ($sheet as $row) {
        $neg += $row['status'] === 'wrong' && (float) $row['marks_awarded'] < 0 ? -(float) $row['marks_awarded'] : 0;
    }
    $stats = exam_rank_stats((int) $exam['id'], (int) $a['user_id']);
    $extra = exam_extra_attempts((int) $exam['id'], (int) $a['user_id']);
    $limit = exam_attempt_limit($exam, (int) $a['user_id']);
    $used = exam_attempts_used((int) $exam['id'], (int) $a['user_id']);
    [$keyVisible, $keyWhy] = exam_answer_key_state($exam, $a);
    $show = in_array($_GET['show'] ?? '', ['correct', 'wrong', 'unanswered', 'marked'], true) ? $_GET['show'] : '';
    $counts = ['correct' => 0, 'wrong' => 0, 'unanswered' => 0, 'marked' => 0];
    foreach ($sheet as $row) {
        $counts[$row['status']]++;
        $counts['marked'] += $row['marked'] ? 1 : 0;
    }

    $actions = '';
    if ($finishedAttempt) {
        $actions .= action_form('rescore', ['id' => $a['id']], icon('history') . ' Re-score', 'btn btn-light', 'Re-score this attempt with the current answer key?');
    } else {
        $actions .= action_form('finalize', ['id' => $a['id']], icon('check') . ' Submit now', 'btn btn-light', 'Submit and score this attempt now? The student will not be able to continue it.');
    }
    $actions .= ' ' . action_form('reset', ['id' => $a['id']], icon('trash') . ' Reset attempt', 'btn btn-danger', 'Reset this attempt? Its answers and score are deleted permanently and it stops counting towards the student’s attempts and rank. This cannot be undone.');
    admin_start('Answer sheet', null, 'tests', [
        'head' => true,
        'crumbs' => '<a href="attempts.php">Attempts</a> / <a href="attempts.php?exam=' . (int) $exam['id'] . '">' . e($exam['title']) . '</a> / #' . (int) $a['id'],
        'subtitle' => $a['name'] . ' · ' . $exam['title'] . ' · attempt ' . (int) $a['attempt_no'],
        'actions' => $actions,
    ]);
    if ($stale) {
        echo '<div class="alert alert-warning" role="status">This attempt was scored with an older answer key (stored ' . e(exam_format_marks((float) $a['score'])) . ', current key gives ' . e(exam_format_marks($live['score'])) . '). Use Re-score to update it.</div>';
    }
    $kpi = function (string $label, string $value, string $sub, string $tone, string $ico): string {
        return '<div class="card stat"><span class="ico ' . $tone . '">' . icon($ico) . '</span><span><span class="label">' . e($label) . '</span><span class="value" style="display:block;font-size:20px">' . e($value) . '</span>' . ($sub !== '' ? '<span class="sub">' . e($sub) . '</span>' : '') . '</span></div>';
    };
    echo '<div class="grid g-4 d1-kpis" style="margin-bottom:18px">'
        . $kpi('Score', exam_format_marks($score) . ' / ' . exam_format_marks($max), ($max > 0 ? exam_format_marks(round($score / $max * 100, 1)) . '%' : '') . ($exam['pass_marks'] !== null ? ' · ' . ($score >= (float) $exam['pass_marks'] ? 'Passed' : 'Not passed') : '') . ($finishedAttempt ? '' : ' · live, not final'), 'tone-blue', 'award')
        . $kpi('Correct / Wrong / Skipped', $counts['correct'] . ' / ' . $counts['wrong'] . ' / ' . $counts['unanswered'], $counts['correct'] + $counts['wrong'] ? 'Accuracy ' . round(100 * $counts['correct'] / ($counts['correct'] + $counts['wrong']), 1) . '%' : 'Nothing answered', 'tone-green', 'check')
        . $kpi('Negative marks lost', $neg > 0 ? '−' . exam_format_marks($neg) : '0', $exam['negative_marking'] ? 'Negative marking on' : 'Negative marking off', 'tone-red', 'alert')
        . $kpi($finishedAttempt ? 'Time taken' : 'Time left', $finishedAttempt ? att_duration(exam_time_taken($a)) : (exam_is_practice($exam) ? 'Untimed' : att_duration(max(0, strtotime($a['expires_at']) - time()))),
            $stats['position'] ? 'Rank ' . $stats['position'] . ' of ' . $stats['participants'] . ' (best attempt)' : '', 'tone-purple', 'clock')
        . '</div>';
    ?>
<div class="grid g-2" style="margin-bottom:18px;align-items:start">
  <div class="card">
    <div class="card-head"><h2>Attempt</h2><?= att_status_badge($a) ?></div>
    <div class="card-pad"><dl class="kv">
      <dt>Student</dt><dd><a href="users.php?id=<?= (int) $a['user_id'] ?>"><?= e($a['name']) ?></a><br><span class="muted"><?= e($a['email']) ?></span></dd>
      <dt>Test</dt><dd><a href="questions.php?exam=<?= (int) $exam['id'] ?>"><?= e($exam['title']) ?></a> <span class="muted">(<?= $exam['mode'] === 'practice' ? 'practice quiz' : 'mock test' ?>)</span></dd>
      <dt>Started</dt><dd><?= e(fmt_dt($a['started_at'])) ?></dd>
      <dt>Submitted</dt><dd><?= e(fmt_dt($a['submitted_at'])) ?><?= $a['status'] === 'auto_submitted' ? ' <span class="muted">(time ran out)</span>' : '' ?></dd>
      <dt>Questions</dt><dd><?= count($sheet) ?><?= $exam['question_pool_count'] ? ' <span class="muted">(random pool)</span>' : '' ?><?= $exam['shuffle_options'] ? ' <span class="muted">· options shuffled</span>' : '' ?></dd>
      <dt>Student sees</dt><dd><?= exam_scores_visible($exam) ? 'Score' : 'No score yet (results not released)' ?> · <?= $keyVisible ? 'answer key' : 'no answer key' . ($keyWhy ? ' <span class="muted">- ' . e($keyWhy) . '</span>' : '') ?></dd>
      <dt>Result email</dt><dd><?= $a['result_notified_at'] ? 'Sent ' . e(fmt_dt($a['result_notified_at'])) : ($exam['mode'] === 'practice' ? '<span class="muted">Not sent for practice quizzes</span>' : '<span class="muted">Not sent yet</span>') ?></dd>
      <dt>IP address</dt><dd><?= e($a['ip_address'] ?? '—') ?></dd>
    </dl></div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Attempts allowance</h2></div>
    <div class="card-pad">
      <p style="margin:0 0 12px">Used <strong><?= $used ?></strong> of <strong><?= $limit === 0 ? 'unlimited' : $limit ?></strong><?= $extra ? ' <span class="muted">(' . (int) $exam['max_attempts'] . ' + ' . $extra . ' extra granted)</span>' : '' ?>.</p>
      <?php if ((int) $exam['max_attempts'] > 0): ?>
      <form method="post" class="form" style="grid-template-columns:120px 1fr;align-items:end">
        <?= csrf_field() ?><input type="hidden" name="_action" value="grant"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>"><input type="hidden" name="user_id" value="<?= (int) $a['user_id'] ?>">
        <div class="field"><label for="extra">Extra attempts</label><input id="extra" type="number" name="extra" min="1" max="10" value="1"></div>
        <div class="field"><label for="note">Reason (optional)</label><input id="note" type="text" name="note" maxlength="255" placeholder="e.g. Network failure during the test"></div>
        <div class="field full"><button class="btn btn-primary" data-confirm="Allow this student more attempts at this test?"><?= icon('plus') ?> Grant extra attempt</button>
          <?php if ($extra): ?> <button class="btn btn-light" name="_action" value="revoke_grant" data-confirm="Remove the extra attempts granted to this student?">Remove extra attempts</button><?php endif; ?></div>
      </form>
      <?php else: ?><p class="muted" style="margin:0">This test allows unlimited attempts.</p><?php endif; ?>
    </div>
  </div>
</div>
<nav class="tabs" aria-label="Filter questions">
  <?php foreach (['' => 'All (' . count($sheet) . ')', 'correct' => 'Correct (' . $counts['correct'] . ')', 'wrong' => 'Wrong (' . $counts['wrong'] . ')', 'unanswered' => 'Not answered (' . $counts['unanswered'] . ')', 'marked' => 'Marked for review (' . $counts['marked'] . ')'] as $k => $label): ?>
  <a href="<?= e(url_with(['show' => $k ?: null])) ?>"<?= $show === $k ? ' class="on" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
    <?php
    $tone = ['correct' => ['b-green', 'Correct'], 'wrong' => ['b-red', 'Wrong'], 'unanswered' => ['b-gray', 'Not answered']];
    $qTypes = ['single' => 'Single answer', 'true_false' => 'True / False', 'multiple' => 'Multiple correct'];
    $shown = 0;
    foreach ($sheet as $row) {
        if ($show !== '' && ($show === 'marked' ? !$row['marked'] : $row['status'] !== $show)) {
            continue;
        }
        $shown++;
        [$badgeTone, $label] = $tone[$row['status']];
        echo '<article class="card card-pad d1-q st-' . e($row['status']) . '">';
        echo '<div class="d1-q-head"><div><span class="d1-q-no">Q' . $row['number'] . '</span>' . nl2br(e($row['text']))
            . '<div class="d1-sub" style="margin-top:6px"><span>' . e($qTypes[$row['type']] ?? $row['type']) . '</span>' . ($row['topic'] ? '<span>' . e($row['topic']) . '</span>' : '') . '<span>+' . e(exam_format_marks($row['marks'])) . ($row['negative_marks'] > 0 ? ' / −' . e(exam_format_marks($row['negative_marks'])) : '') . '</span></div></div>'
            . '<div class="d1-stack" style="align-items:flex-end"><span class="badge ' . $badgeTone . '">' . $label . '</span>'
            . ($row['marked'] ? '<span class="badge b-purple">Marked for review</span>' : '')
            . ($row['checked'] ? '<span class="badge b-blue">Checked</span>' : '')
            . ($row['status'] !== $row['live_status'] && $finishedAttempt ? '<span class="badge b-amber" title="The answer key changed after scoring">Key changed: now ' . e(['correct' => 'correct', 'wrong' => 'wrong', 'unanswered' => 'not answered'][$row['live_status']] ?? $row['live_status']) . '</span>' : '')
            . '<strong class="d1-num">' . ($row['marks_awarded'] === null ? '<span class="muted">Not scored yet</span>' : (($row['marks_awarded'] > 0 ? '+' : '') . e(exam_format_marks((float) $row['marks_awarded'])) . ' marks')) . '</strong></div></div>';
        if ($row['image_url']) {
            echo '<img class="d1-q-img" src="' . e($row['image_url']) . '" alt="Image for question ' . $row['number'] . '">';
        }
        echo '<ul class="d1-q-opts">';
        foreach ($row['options'] as $i => $o) {
            $picked = in_array($o['id'], $row['selected'], true);
            $tags = [];
            if ($picked) {
                $tags[] = 'Student’s answer';
            }
            if ($o['correct']) {
                $tags[] = 'Correct answer';
            }
            echo '<li class="' . ($o['correct'] ? 'ok' : ($picked ? 'bad' : '')) . '"><b>' . chr(65 + $i) . '</b><span>' . e($o['text']) . '</span>'
                . ($tags ? '<span class="tag">' . ($o['correct'] ? '✓ ' : '✗ ') . e(implode(' · ', $tags)) . '</span>' : '') . '</li>';
        }
        echo '</ul>' . ($row['explanation'] ? '<p class="d1-q-expl"><strong>Explanation:</strong> ' . e($row['explanation']) . '</p>' : '') . '</article>';
    }
    if (!$shown) {
        echo '<div class="card empty"><span class="empty-ic">' . icon('filter') . '</span><strong>No questions match this filter</strong><p><a href="' . e(url_with(['show' => null])) . '">Show all questions</a></p></div>';
    }
    admin_end();
    exit;
}

// ------------------------------------------------------------------
// List
// ------------------------------------------------------------------
$examId = (int) ($_GET['exam'] ?? 0);
$status = in_array($_GET['status'] ?? '', ['in_progress', 'submitted', 'auto_submitted'], true) ? $_GET['status'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$fromDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : '';
$toDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : '';
$where = ['1=1'];
$params = [];
if ($examId) {
    $where[] = 'a.exam_id = ?';
    $params[] = $examId;
}
if ($status !== '') {
    $where[] = 'a.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
    array_push($params, $like, $like, $like);
}
if ($fromDate !== '') {
    $where[] = 'a.started_at >= ?';
    $params[] = $fromDate . ' 00:00:00';
}
if ($toDate !== '') {
    $where[] = 'a.started_at <= ?';
    $params[] = $toDate . ' 23:59:59';
}
$sqlWhere = implode(' AND ', $where);
$from = 'FROM exam_attempts a JOIN users u ON u.id = a.user_id JOIN exams e ON e.id = a.exam_id';
$select = "a.*, u.name, u.email, e.title, e.mode, e.total_marks, e.question_pool_count, e.pass_marks,
    GREATEST(0, LEAST(TIMESTAMPDIFF(SECOND, a.started_at, a.submitted_at), TIMESTAMPDIFF(SECOND, a.started_at, a.expires_at))) AS seconds";

if (($_GET['export'] ?? '') === 'csv') {
    require_permission('reports.export');
    $rows = db_all("SELECT {$select} {$from} WHERE {$sqlWhere} ORDER BY a.id DESC LIMIT 5000", $params);
    csv_download('attempts.csv', ['Attempt', 'Student', 'Email', 'Test', 'Attempt no', 'Status', 'Score', 'Correct', 'Wrong', 'Unanswered', 'Time (s)', 'Started', 'Submitted'],
        array_map(fn($r) => [$r['id'], $r['name'], $r['email'], $r['title'], $r['attempt_no'], att_status_label((string) $r['status']), $r['score'], $r['correct_count'], $r['wrong_count'], $r['unanswered_count'], $r['submitted_at'] ? $r['seconds'] : '', $r['started_at'], $r['submitted_at']], $rows));
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 25;
$total = (int) db_value("SELECT COUNT(*) {$from} WHERE {$sqlWhere}", $params);
$rows = db_all("SELECT {$select} {$from} WHERE {$sqlWhere} ORDER BY a.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);
$exams = db_all("SELECT e.id, e.title, e.mode, (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id = e.id) AS n FROM exams e ORDER BY e.id DESC");
$examRow = $examId ? db_one('SELECT * FROM exams WHERE id = ?', [$examId]) : null;
$finishedForExam = $examRow ? (int) db_value("SELECT COUNT(*) FROM exam_attempts WHERE exam_id = ? AND status <> 'in_progress'", [$examId]) : 0;

admin_start('Attempts', null, 'tests', [
    'head' => true,
    'subtitle' => 'Every attempt at a mock test or practice quiz. Open one to see the student’s answer sheet, grant another attempt, reset it or re-score it.',
    'actions' => can('reports.export') && $total > 0 ? '<a class="btn btn-light" href="' . e(url_with(['export' => 'csv', 'page' => null])) . '">' . icon('download') . ' Export CSV</a>' : '',
]);
if ($examRow && $finishedForExam) {
    echo '<div class="alert alert-info" role="status">Corrected the answer key of “' . e($examRow['title']) . '”? '
        . '<form method="post" action="questions.php?exam=' . (int) $examId . '" style="display:inline">' . csrf_field() . '<input type="hidden" name="_action" value="rescore"><button class="btn btn-light btn-sm" data-confirm="Re-score every submitted attempt of this test with the current answer key? Scores, ranks and results may change.">' . icon('history') . ' Re-score ' . ($finishedForExam === 1 ? 'the submitted attempt' : 'all ' . $finishedForExam . ' attempts') . '</button></form>'
        . ' <a class="btn btn-light btn-sm" href="leaderboard.php?exam=' . (int) $examId . '">' . icon('trophy') . ' Leaderboard</a></div>';
}
$filtered = $q !== '' || $examId || $status !== '' || $fromDate !== '' || $toDate !== '';
?>
<div class="card">
  <form class="toolbar d1-toolbar" method="get" role="search">
    <label class="search-in"><span class="sr-only">Search students</span><?= icon('search') ?><input class="in" type="search" name="q" value="<?= e($q) ?>" placeholder="Student name, email or phone…"></label>
    <select class="in" style="width:auto;max-width:260px" name="exam" aria-label="Test" data-autosubmit>
      <option value="">All tests</option>
      <?php foreach ($exams as $ex): ?><option value="<?= (int) $ex['id'] ?>"<?= (int) $ex['id'] === $examId ? ' selected' : '' ?>><?= e($ex['title']) ?> (<?= (int) $ex['n'] ?>)</option><?php endforeach; ?>
    </select>
    <select class="in" style="width:auto" name="status" aria-label="Status" data-autosubmit>
      <option value="">All statuses</option>
      <?php foreach (['in_progress', 'submitted', 'auto_submitted'] as $k): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= e(att_status_label($k)) ?></option><?php endforeach; ?>
    </select>
    <span class="d1-range"><label for="f-from">Started</label><input id="f-from" class="in" type="date" name="from" value="<?= e($fromDate) ?>" aria-label="Started from"><label for="f-to">to</label><input id="f-to" class="in" type="date" name="to" value="<?= e($toDate) ?>" aria-label="Started until"></span>
    <button class="btn btn-light"><?= icon('filter') ?> Filter</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="attempts.php">Reset</a><?php endif; ?>
  </form>
  <?php if (!$rows): ?>
  <div class="empty"><span class="empty-ic"><?= icon($filtered ? 'search' : 'clipboard') ?></span>
    <strong><?= $filtered ? 'No attempts match these filters' : 'No attempts yet' ?></strong>
    <p><?= $filtered ? 'Try a different student, test or date range.' : 'Attempts appear here as soon as a student starts a mock test or practice quiz.' ?></p>
    <?php if ($filtered): ?><a class="btn btn-light btn-sm" href="attempts.php">Clear filters</a><?php endif; ?></div>
  <?php else: ?>
  <div class="table-wrap"><table class="t stack"><thead><tr><th scope="col">Student</th><th scope="col">Test</th><th scope="col">Status</th><th scope="col">Score</th><th scope="col"><abbr title="Correct / Wrong / Skipped">C / W / S</abbr></th><th scope="col">Time</th><th scope="col">Started</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>
  <?php foreach ($rows as $r):
      $done = $r['status'] !== 'in_progress';
      $max = $done ? exam_attempt_max_marks(['id' => $r['exam_id'], 'total_marks' => $r['total_marks'], 'question_pool_count' => $r['question_pool_count']], $r) : (float) $r['total_marks'];
  ?>
    <tr>
      <td data-label="Student"><div class="d1-main sm d1-media"><span class="d1-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $r['name'], 0, 1))) ?></span><div class="d1-text"><a class="d1-t" href="users.php?id=<?= (int) $r['user_id'] ?>"><?= e($r['name']) ?></a><div class="d1-sub"><span><?= e($r['email']) ?></span></div></div></div></td>
      <td data-label="Test"><div class="d1-clip" style="min-width:170px;max-width:260px;color:var(--ink)" title="<?= e($r['title']) ?>"><?= e($r['title']) ?></div><span class="muted" style="font-size:12px"><?= $r['mode'] === 'practice' ? 'Practice quiz' : 'Mock test' ?> · attempt <?= (int) $r['attempt_no'] ?></span></td>
      <td data-label="Status"><?= att_status_badge($r) ?></td>
      <td data-label="Score"><?= $done ? '<span class="d1-num"><strong>' . e(exam_format_marks((float) $r['score'])) . '</strong> / ' . e(exam_format_marks($max)) . ($max > 0 ? '<small>' . e(exam_format_marks(round((float) $r['score'] / $max * 100, 1))) . '%</small>' : '') . '</span>' : '<span class="muted">—</span>' ?></td>
      <td data-label="Correct / wrong / skipped" class="d1-num"><?= $done ? '<span style="color:var(--green)">' . (int) $r['correct_count'] . '</span> / <span style="color:var(--red)">' . (int) $r['wrong_count'] . '</span> / <span class="muted">' . (int) $r['unanswered_count'] . '</span>' : '—' ?></td>
      <td data-label="Time" class="d1-num"><?= $done ? e(att_duration((int) $r['seconds'])) : '—' ?></td>
      <td data-label="Started"><span class="d1-when"><?= e(fmt_dt($r['started_at'], 'j M Y')) ?><small><?= e(fmt_dt($r['started_at'], 'g:i A')) ?></small></span></td>
      <td class="actions"><a class="btn btn-light btn-sm" href="attempts.php?id=<?= (int) $r['id'] ?>"><?= icon('eye') ?> Answer sheet</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php if ($total > 0) { render_pager($total, $page, $per); } ?>
</div>
<?php admin_end();
