<?php
// Support tickets raised by students from their dashboard. Internal notes are never shown to students.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';

$me = require_staff_page();
require_permission('support.manage');
$id = (int) ($_GET['id'] ?? 0);

if ($id && admin_post()) {
    $t = db_one('SELECT * FROM support_tickets WHERE id = ?', [$id]);
    if ($t) {
        $msg = trim((string) ($_POST['message'] ?? ''));
        $internal = !empty($_POST['internal']);
        if ($msg !== '') {
            db_query('INSERT INTO support_ticket_messages (ticket_id, user_id, message, is_internal) VALUES (?, ?, ?, ?)', [$id, $me['id'], mb_substr($msg, 0, 5000), $internal ? 1 : 0]);
            if (!$internal) {
                require_once APP_ROOT . '/includes/notify.php';
                send_template('ticket_reply', (int) $t['user_id'], ['order' => (string) $id, 'link' => '/dashboard/support?ticket=' . $id]);
            }
        }
        $status = in_array($_POST['status'] ?? '', ['open', 'in_progress', 'waiting', 'resolved', 'closed'], true) ? $_POST['status'] : $t['status'];
        $assigned = (int) ($_POST['assigned_to'] ?? 0) ?: null;
        db_query('UPDATE support_tickets SET status = ?, assigned_to = ?, priority = ? WHERE id = ?', [$status, $assigned, in_array($_POST['priority'] ?? '', ['low', 'normal', 'high'], true) ? $_POST['priority'] : $t['priority'], $id]);
        audit_log('ticket_updated', 'support_tickets', $id, ['status' => $status, 'internal' => $internal]);
        flash('success', 'Ticket updated.');
    }
    redirect_to('support.php?id=' . $id);
}

if ($id) {
    $t = db_one('SELECT t.*, u.name, u.email, u.phone FROM support_tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?', [$id]);
    if (!$t) {
        redirect_to('support.php');
    }
    $msgs = db_all('SELECT m.*, u.name, u.user_type FROM support_ticket_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.ticket_id = ? ORDER BY m.id', [$id]);
    admin_start('Ticket #' . $id . ' · ' . $t['subject'], null, 'support', ['head' => true, 'crumbs' => '<a href="support.php">Support Tickets</a> / #' . $id,
        'subtitle' => $t['name'] . ' · ' . $t['email'] . ' · ' . lookup_label('ticket_category', $t['category'])]);
    echo '<div class="split-2-1"><div class="d2-thread" style="min-width:0">';
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">' . badge($t['status']) . ' ' . badge($t['priority'] === 'high' ? 'critical' : $t['priority']) . '<span class="muted" style="font-size:12.5px">' . count($msgs) . ' message' . (count($msgs) === 1 ? '' : 's') . ' · opened ' . e(fmt_dt($t['created_at'])) . '</span></div>';
    foreach ($msgs as $m) {
        $staff = $m['user_type'] === 'staff';
        echo '<div class="card card-pad' . ($m['is_internal'] ? ' note' : ($staff ? ' staff' : '')) . '"><div class="head"><strong>' . e($m['name'] ?? 'Deleted user') . ($staff ? ' <span class="badge b-blue">Staff</span>' : '') . ($m['is_internal'] ? ' <span class="badge b-amber">Internal note</span>' : '') . '</strong><span class="muted" style="font-size:12.5px">' . e(fmt_dt($m['created_at'])) . '</span></div><p>' . e($m['message']) . '</p></div>';
    }
    if (!$msgs) {
        echo '<div class="card"><div class="empty"><span class="empty-ic">' . icon('chat') . '</span><strong>No messages yet</strong><p>Write the first reply on the right.</p></div></div>';
    }
    echo '</div><form method="post" class="card sticky-aside">' . csrf_field();
    echo '<div class="card-head"><h2>Reply</h2></div><div class="card-pad" style="display:grid;gap:12px">';
    $canned = db_all('SELECT title, body FROM canned_responses ORDER BY title');
    if ($canned) {
        echo '<div class="field"><label for="canned">Insert a canned response</label><select id="canned" class="in" onchange="if(this.value){var m=document.getElementById(\'msg\');m.value=(m.value?m.value+\'\n\n\':\'\')+this.value.replace(/\{name\}/g,' . e(json_encode(explode(' ', $t['name'])[0])) . ');this.value=\'\';m.focus();}"><option value="">Choose…</option>';
        foreach ($canned as $c) {
            echo '<option value="' . e($c['body']) . '">' . e($c['title']) . '</option>';
        }
        echo '</select><div class="hint"><a href="canned.php">Manage canned responses</a></div></div>';
    }
    echo '<div class="field"><label for="msg">Message</label><textarea id="msg" name="message" rows="5"></textarea></div>';
    echo '<label class="check"><input type="checkbox" name="internal" value="1"> Internal note (not visible to student)</label>';
    echo '<div class="field"><label for="st">Status</label><select id="st" name="status">';
    foreach (['open', 'in_progress', 'waiting', 'resolved', 'closed'] as $s) {
        echo '<option value="' . $s . '"' . ($t['status'] === $s ? ' selected' : '') . '>' . e(ucwords(str_replace('_', ' ', $s))) . '</option>';
    }
    echo '</select></div><div class="field"><label for="pr">Priority</label><select id="pr" name="priority">';
    foreach (['low', 'normal', 'high'] as $p) {
        echo '<option value="' . $p . '"' . ($t['priority'] === $p ? ' selected' : '') . '>' . ucfirst($p) . '</option>';
    }
    echo '</select></div><div class="field"><label for="as">Assigned to</label><select id="as" name="assigned_to"><option value="">Unassigned</option>';
    foreach (db_all("SELECT id, name FROM users WHERE user_type = 'staff' AND status = 'active' ORDER BY name") as $s) {
        echo '<option value="' . (int) $s['id'] . '"' . ((int) $t['assigned_to'] === (int) $s['id'] ? ' selected' : '') . '>' . e($s['name']) . '</option>';
    }
    echo '</select></div></div><div class="form-actions"><button class="btn btn-primary">' . icon('check') . ' Save</button></div></form></div>';
    admin_end();
    exit;
}

$status = (string) ($_GET['status'] ?? '');
$conds = [];
$params = [];
if (in_array($status, ['open', 'in_progress', 'waiting', 'resolved', 'closed'], true)) {
    $conds[] = 't.status = ?';
    $params[] = $status;
} elseif ($status !== 'all') {
    $conds[] = "t.status <> 'closed'";
}
if (($assigned = (int) ($_GET['assigned'] ?? 0)) > 0) {
    $conds[] = 't.assigned_to = ?';
    $params[] = $assigned;
}
if (in_array($_GET['priority'] ?? '', ['low', 'normal', 'high'], true)) {
    $conds[] = 't.priority = ?';
    $params[] = $_GET['priority'];
}
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $conds[] = '(t.subject LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR t.id = ?)';
    array_push($params, $like, $like, $like, (int) ltrim($q, '#'));
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
$rows = db_all("SELECT t.*, u.name, u.email, s.name AS staff, (SELECT COUNT(*) FROM support_ticket_messages m WHERE m.ticket_id = t.id) AS n FROM support_tickets t JOIN users u ON u.id = t.user_id LEFT JOIN users s ON s.id = t.assigned_to {$where} ORDER BY FIELD(t.priority, 'high', 'normal', 'low'), t.updated_at DESC LIMIT 100", $params);
$filtered = $q !== '' || $status !== '' || $assigned > 0 || isset($_GET['priority']);
admin_start('Support Tickets', null, 'support', ['head' => true, 'subtitle' => 'Tickets raised by students from their dashboard. High priority first, then the most recently updated.']);
?>
<div class="card">
  <form class="toolbar" method="get" role="search">
    <label class="search-in"><span class="sr-only">Search tickets</span><?= icon('search') ?><input class="in" type="search" name="q" value="<?= e($q) ?>" placeholder="Subject, student, email or ticket #…"></label>
    <select class="in" style="width:auto" name="status" aria-label="Status" data-autosubmit><option value="">Not closed</option><option value="all"<?= $status === 'all' ? ' selected' : '' ?>>All tickets</option><?php foreach (['open', 'in_progress', 'waiting', 'resolved', 'closed'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?></select>
    <select class="in" style="width:auto" name="priority" aria-label="Priority" data-autosubmit><option value="">All priorities</option><?php foreach (['high' => 'High', 'normal' => 'Normal', 'low' => 'Low'] as $k => $l): ?><option value="<?= $k ?>"<?= ($_GET['priority'] ?? '') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <button class="btn btn-light"><?= icon('search') ?> Filter</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="support.php">Reset</a><?php endif; ?>
  </form>
  <?php if ($rows): ?>
  <div class="table-wrap"><table class="t stack"><thead><tr><th>Ticket</th><th>Subject</th><th>Student</th><th>Priority</th><th>Assigned</th><th>Updated</th><th>Status</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $n = (int) $r['n']; ?><tr>
    <td data-label="Ticket"><a href="?id=<?= (int) $r['id'] ?>"><strong>#<?= (int) $r['id'] ?></strong></a></td>
    <td data-label="Subject"><div class="d2-w200"><strong><?= e($r['subject']) ?></strong><span class="d2-sub"><?= e(lookup_label('ticket_category', $r['category'])) ?> · <?= $n ?> message<?= $n === 1 ? '' : 's' ?></span></div></td>
    <td data-label="Student"><div><?= e($r['name']) ?><span class="d2-sub"><?= e($r['email']) ?></span></div></td>
    <td data-label="Priority"><?= $r['priority'] === 'high' ? '<span class="badge b-red">High</span>' : ($r['priority'] === 'low' ? '<span class="badge b-gray">Low</span>' : '<span class="badge b-blue">Normal</span>') ?></td>
    <td data-label="Assigned"><?= $r['staff'] ? e($r['staff']) : '<span class="muted">Unassigned</span>' ?></td>
    <td data-label="Updated" class="d2-nowrap"><div><?= e(fmt_dt($r['updated_at'], 'j M Y')) ?><span class="d2-sub"><?= e(fmt_dt($r['updated_at'], 'g:i A')) ?></span></div></td>
    <td data-label="Status"><?= badge($r['status']) ?></td>
    <td class="actions"><a class="btn btn-light btn-sm" href="?id=<?= (int) $r['id'] ?>"><?= icon('chat') ?> Reply</a></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?>
  <div class="empty"><span class="empty-ic"><?= icon($filtered ? 'search' : 'headset') ?></span>
    <strong><?= $filtered ? 'No tickets match these filters' : 'No open tickets' ?></strong>
    <p><?= $filtered ? 'Try another status or search.' : 'Students raise tickets from their dashboard; new ones appear here.' ?></p>
    <?php if ($filtered): ?><a class="btn btn-light btn-sm" href="support.php?status=all">Show all tickets</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php admin_end();
