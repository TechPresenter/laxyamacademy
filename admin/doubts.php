<?php
// Student doubts asked on lessons and mock test questions (includes/doubts.php). A reply notifies the
// student (in-app + email); photos are private files, shown only to staff and to the student who asked.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/doubts.php';

$me = require_staff_page();
require_permission('doubts.manage');
$id = (int) ($_GET['id'] ?? 0);

if (!doubts_ready()) {
    admin_start('Student Doubts', null, 'doubts', ['head' => true]);
    echo '<div class="alert alert-warning">Apply the latest database update first (System → Database Updates).</div>';
    admin_end();
    exit;
}

// A photo attached to a message (private file).
if (isset($_GET['image'])) {
    $m = db_one('SELECT id, image_path FROM doubt_messages WHERE id = ?', [(int) $_GET['image']]);
    $full = $m ? private_file_path($m['image_path']) : null;
    if (!$full) {
        http_response_code(404);
        exit('Photo not found.');
    }
    stream_file($full, 'doubt-photo-' . (int) $m['id'] . '.webp');
}

if ($id && admin_post()) {
    $d = db_one('SELECT * FROM doubts WHERE id = ?', [$id]);
    $act = $_POST['_action'] ?? 'reply';
    if ($d && $act === 'reply') {
        $body = doubt_clean_body($_POST['message'] ?? '');
        $image = null;
        if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $image = doubt_store_image($_FILES['image']);
            } catch (InvalidArgumentException $e) {
                flash('error', $e->getMessage());
                redirect_to('doubts.php?id=' . $id);
            }
        }
        if (mb_strlen($body) < 2 && !$image) {
            flash('error', 'Write the answer (or attach a photo of the solution) first.');
            redirect_to('doubts.php?id=' . $id);
        }
        doubt_add_message($d, (int) $me['id'], true, $body !== '' ? $body : 'Please see the attached photo.', $image);
        if (!empty($_POST['resolve'])) {
            db_query("UPDATE doubts SET status = 'closed' WHERE id = ?", [$id]);
        }
        audit_log('doubt_answered', 'doubt', $id);
        flash('success', 'Answer sent - the student has been notified.');
    } elseif ($d && $act === 'manage') {
        $status = in_array($_POST['status'] ?? '', ['open', 'answered', 'closed'], true) ? $_POST['status'] : $d['status'];
        $assigned = (int) ($_POST['assigned_to'] ?? 0);
        $assigned = $assigned && db_value("SELECT 1 FROM users WHERE id = ? AND user_type = 'staff'", [$assigned]) ? $assigned : null;
        db_query('UPDATE doubts SET status = ?, assigned_to = ? WHERE id = ?', [$status, $assigned, $id]);
        audit_log('doubt_updated', 'doubt', $id, ['status' => $status]);
        flash('success', 'Doubt updated.');
    }
    redirect_to('doubts.php?id=' . $id);
}

$statusBadge = fn(string $s) => '<span class="badge ' . ['open' => 'b-amber', 'answered' => 'b-green', 'closed' => 'b-gray'][$s] . '">' . e(DOUBT_STATUSES[$s] ?? $s) . '</span>';

// ------------------------------------------------------------------
// One doubt: context, conversation, reply
// ------------------------------------------------------------------
if ($id) {
    $d = db_one('SELECT d.*, u.name, u.email, u.phone FROM doubts d JOIN users u ON u.id = d.user_id WHERE d.id = ?', [$id]);
    if (!$d) {
        redirect_to('doubts.php');
    }
    $msgs = doubt_messages($id, 'doubts.php?image=');
    admin_start('Doubt #' . $id, null, 'doubts', ['head' => true, 'crumbs' => '<a href="doubts.php">Doubts</a> / #' . $id,
        'subtitle' => $d['subject'] . ' · ' . $d['name'] . ' · ' . $d['email']]);
    echo '<div class="split-2-1"><div class="d2-thread" style="min-width:0">';
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">' . $statusBadge($d['status']) . '<span class="muted" style="font-size:12.5px">' . count($msgs) . ' message' . (count($msgs) === 1 ? '' : 's') . ' · asked ' . e(fmt_dt($d['created_at'])) . '</span></div>';

    // What the doubt is about
    if ($d['lesson_id'] && ($l = db_one('SELECT l.id, l.title, l.course_id, c.title AS course FROM lessons l JOIN courses c ON c.id = l.course_id WHERE l.id = ?', [$d['lesson_id']]))) {
        echo '<div class="card card-pad dq-context"><div class="head"><strong>' . icon('book') . ' Lesson</strong><a href="lessons.php?course=' . (int) $l['course_id'] . '">Open lessons</a></div><p><b>' . e($l['course']) . '</b> › ' . e($l['title']) . '</p></div>';
    } elseif ($snap = doubt_question_snapshot($d, true)) {
        $chosen = array_map('intval', json_decode_array(db_value('SELECT selected_option_ids FROM exam_answers WHERE attempt_id = ? AND question_id = ?', [$d['attempt_id'], $d['question_id']])));
        $optIds = array_map('intval', array_column(db_all('SELECT id FROM exam_options WHERE question_id = ? ORDER BY sort_order, id', [$d['question_id']]), 'id'));
        echo '<div class="card card-pad dq-context"><div class="head"><strong>' . icon('clipboard') . ' ' . e($d['subject']) . '</strong>'
            . '<a href="questions.php?exam=' . (int) $d['exam_id'] . '&amp;edit=' . (int) $d['question_id'] . '#question-form">Edit question</a></div>'
            . '<p>' . nl2br(e($snap['text'])) . '</p>' . ($snap['text_hi'] ? '<p class="muted" lang="hi">' . nl2br(e($snap['text_hi'])) . '</p>' : '') . '<ul class="d1-q-opts">';
        foreach ($snap['options'] as $i => $o) {
            $picked = in_array($optIds[$i] ?? 0, $chosen, true);
            echo '<li' . ($o['correct'] ? ' class="ok"' : ($picked ? ' class="bad"' : '')) . '><b>' . chr(65 + $i) . '</b><span>' . e($o['text']) . '</span>'
                . ($picked ? '<span class="tag">Student’s answer</span>' : ($o['correct'] ? '<span class="tag">Correct</span>' : '')) . '</li>';
        }
        echo '</ul>' . (!$chosen ? '<p class="muted" style="margin:8px 0 0">The student did not answer this question.</p>' : '') . '</div>';
    }

    foreach ($msgs as $m) {
        echo '<div class="card card-pad' . ($m['staff'] ? ' staff' : '') . '"><div class="head"><strong>' . e($m['author']) . ($m['staff'] ? ' <span class="badge b-blue">Teacher</span>' : '') . '</strong>'
            . '<span class="muted" style="font-size:12.5px">' . e(fmt_dt($m['created_at'])) . '</span></div><p style="white-space:pre-line">' . e($m['body']) . '</p>'
            . ($m['image_url'] ? '<a href="' . e($m['image_url']) . '" target="_blank" rel="noopener"><img class="dq-photo" src="' . e($m['image_url']) . '" alt="Photo attached by ' . e($m['author']) . '" loading="lazy"></a>' : '') . '</div>';
    }
    echo '</div><div style="display:grid;gap:14px;align-content:start">';
    echo '<form method="post" enctype="multipart/form-data" class="card">' . csrf_field() . '<input type="hidden" name="_action" value="reply">'
        . '<div class="card-head"><h2>' . icon('chat') . ' Answer</h2></div><div class="card-pad" style="display:grid;gap:12px">'
        . '<div class="field"><label for="msg">Your answer</label><textarea id="msg" name="message" rows="7" placeholder="Explain step by step…" autofocus></textarea></div>'
        . '<div class="field"><label for="img">Photo of the solution (optional)</label><input id="img" type="file" name="image" accept="image/jpeg,image/png,image/webp"><div class="hint">Handwritten working or a diagram. Visible only to this student.</div></div>'
        . '<label class="check"><input type="checkbox" name="resolve" value="1"> Mark as resolved after sending</label></div>'
        . '<div class="form-actions"><button class="btn btn-primary" data-busy="Sending…">' . icon('send') . ' Send answer</button></div></form>';
    echo '<form method="post" class="card">' . csrf_field() . '<input type="hidden" name="_action" value="manage"><div class="card-pad" style="display:grid;gap:12px">'
        . '<div class="field"><label for="st">Status</label><select id="st" name="status">';
    foreach (DOUBT_STATUSES as $k => $label) {
        echo '<option value="' . $k . '"' . ($d['status'] === $k ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select></div><div class="field"><label for="as">Teacher</label><select id="as" name="assigned_to"><option value="">Not assigned</option>';
    foreach (db_all("SELECT id, name FROM users WHERE user_type = 'staff' AND status = 'active' ORDER BY name") as $s) {
        echo '<option value="' . (int) $s['id'] . '"' . ((int) $d['assigned_to'] === (int) $s['id'] ? ' selected' : '') . '>' . e($s['name']) . '</option>';
    }
    echo '</select></div></div><div class="form-actions"><button class="btn btn-light">' . icon('check') . ' Save</button></div></form></div></div>';
    admin_end();
    exit;
}

// ------------------------------------------------------------------
// List
// ------------------------------------------------------------------
$status = (string) ($_GET['status'] ?? 'open');
$conds = [];
$params = [];
if (isset(DOUBT_STATUSES[$status])) {
    $conds[] = 'd.status = ?';
    $params[] = $status;
}
$kind = (string) ($_GET['kind'] ?? '');
if ($kind === 'lesson') {
    $conds[] = 'd.lesson_id IS NOT NULL';
} elseif ($kind === 'test') {
    $conds[] = 'd.question_id IS NOT NULL';
}
if (!empty($_GET['mine'])) {
    $conds[] = 'd.assigned_to = ?';
    $params[] = (int) $me['id'];
}
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $conds[] = '(d.subject LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR d.id = ?)';
    array_push($params, $like, $like, $like, (int) ltrim($q, '#'));
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
// Waiting doubts: the one waiting longest first. Others: latest activity first.
$order = $status === 'open' ? 'COALESCE(d.last_reply_at, d.created_at) ASC' : 'COALESCE(d.last_reply_at, d.created_at) DESC';
$rows = db_all("SELECT d.*, u.name, u.email, s.name AS staff,
        (SELECT COUNT(*) FROM doubt_messages m WHERE m.doubt_id = d.id) AS n,
        (SELECT m.body FROM doubt_messages m WHERE m.doubt_id = d.id ORDER BY m.id DESC LIMIT 1) AS last_body
    FROM doubts d JOIN users u ON u.id = d.user_id LEFT JOIN users s ON s.id = d.assigned_to {$where} ORDER BY {$order} LIMIT 150", $params);
$waiting = (int) db_value("SELECT COUNT(*) FROM doubts WHERE status = 'open'");
admin_start('Student Doubts', null, 'doubts', ['head' => true,
    'subtitle' => 'Questions students ask on their course lessons and on mock test questions they attempted. ' . ($waiting ? plural_n($waiting, 'doubt') . ' waiting for an answer.' : 'Nothing is waiting.')]);
?>
<div class="card">
  <form class="toolbar" method="get" role="search">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <label class="search-in"><span class="sr-only">Search doubts</span><?= icon('search') ?><input class="in" type="search" name="q" value="<?= e($q) ?>" placeholder="Course, lesson, test, student or #…"></label>
    <select class="in" style="width:auto" name="kind" aria-label="Type" data-autosubmit><option value="">Lessons and tests</option><option value="lesson"<?= $kind === 'lesson' ? ' selected' : '' ?>>Lessons</option><option value="test"<?= $kind === 'test' ? ' selected' : '' ?>>Test questions</option></select>
    <label class="check"><input type="checkbox" name="mine" value="1"<?= !empty($_GET['mine']) ? ' checked' : '' ?> data-autosubmit> Assigned to me</label>
    <button class="btn btn-light"><?= icon('search') ?> Filter</button>
  </form>
  <?php if ($rows): ?>
  <div class="table-wrap"><table class="t stack"><thead><tr><th>Doubt</th><th>Student</th><th>Type</th><th><?= $status === 'open' ? 'Waiting since' : 'Last activity' ?></th><th>Teacher</th><th>Status</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $when = $r['last_reply_at'] ?: $r['created_at']; ?>
  <tr>
    <td data-label="Doubt"><div class="d2-w200"><a href="?id=<?= (int) $r['id'] ?>"><strong><?= e($r['subject']) ?></strong></a><span class="d2-sub"><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', (string) $r['last_body']), 0, 110, '…')) ?></span></div></td>
    <td data-label="Student"><div><?= e($r['name']) ?><span class="d2-sub"><?= e($r['email']) ?></span></div></td>
    <td data-label="Type"><?= $r['question_id'] ? '<span class="badge b-purple">Test question</span>' : '<span class="badge b-blue">Lesson</span>' ?></td>
    <td data-label="When" class="d2-nowrap"><div><?= e(fmt_ago($when)) ?><span class="d2-sub"><?= (int) $r['n'] ?> message<?= (int) $r['n'] === 1 ? '' : 's' ?></span></div></td>
    <td data-label="Teacher"><?= $r['staff'] ? e($r['staff']) : '<span class="muted">—</span>' ?></td>
    <td data-label="Status"><?= $statusBadge($r['status']) ?></td>
    <td class="actions"><a class="btn btn-light btn-sm" href="?id=<?= (int) $r['id'] ?>"><?= icon($r['status'] === 'open' ? 'chat' : 'eye') ?> <?= $r['status'] === 'open' ? 'Answer' : 'Open' ?></a></td>
  </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?>
  <div class="empty"><span class="empty-ic"><?= icon('chat') ?></span><strong><?= $status === 'open' ? 'No doubts waiting' : 'No doubts here' ?></strong><p>Students ask doubts from a lesson (“Ask a doubt”) or from the answer review of a test they finished.</p></div>
  <?php endif; ?>
</div>
<?php
admin_end();
