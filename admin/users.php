<?php
// Students: list, profile, enrolments (grant / extend / revoke), activity, account status, deletion requests.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/catalog.php';

$me = require_staff_page();
// Staff accounts (Security module) are users.php?type=staff, handled entirely by staff-accounts.php (roles.manage).
if (($_GET['type'] ?? '') === 'staff') {
    require __DIR__ . '/staff-accounts.php';
    exit;
}
require_permission('users.view');
$id = (int) ($_GET['id'] ?? 0);

/** Student account status in the words used across the Students screens ("inactive" is shown as Blocked). */
function student_status_badge(string $status): string
{
    return match ($status) {
        'active' => '<span class="badge b-green">Active</span>',
        'inactive' => '<span class="badge b-red">Blocked</span>',
        'deleted' => '<span class="badge b-gray">Deleted</span>',
        default => badge($status),
    };
}

if (($_GET['export'] ?? '') === 'csv') {
    require_permission('reports.export');
    csv_download('students-' . date('Ymd') . '.csv', ['ID', 'Name', 'Email', 'Phone', 'Status', 'Email verified', 'Registered', 'Last login', 'Active enrolments'],
        db_all("SELECT u.id, u.name, u.email, u.phone, u.status, IF(u.email_verified_at IS NULL, 'No', 'Yes'), u.created_at, u.last_login_at,
                (SELECT COUNT(*) FROM course_enrolments e WHERE e.user_id = u.id AND e.status = 'active')
                FROM users u WHERE u.user_type = 'student' ORDER BY u.id DESC"));
}

// ---------------- Add student ----------------
if (($_GET['action'] ?? '') === 'new') {
    require_permission('users.manage');
    $errors = [];
    $v = ['name' => '', 'email' => '', 'phone' => ''];
    if (admin_post()) {
        $v = ['name' => trim((string) $_POST['name']), 'email' => strtolower(trim((string) $_POST['email'])), 'phone' => preg_replace('/[^\d+]/', '', (string) $_POST['phone'])];
        $pw = (string) $_POST['password'];
        if (mb_strlen($v['name']) < 2) {
            $errors['name'] = 'Enter the student’s name.';
        }
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email.';
        } elseif (db_value('SELECT 1 FROM users WHERE email = ?', [$v['email']])) {
            $errors['email'] = 'An account with this email already exists.';
        }
        if (!preg_match('/^\+?\d{10,14}$/', $v['phone'])) {
            $errors['phone'] = 'Enter a valid phone number.';
        }
        if ($e = password_policy_error($pw)) {
            $errors['password'] = $e;
        }
        if (!$errors) {
            $newId = db_transaction(function () use ($v, $pw) {
                db_query("INSERT INTO users (name, email, phone, password_hash, user_type, status, email_verified_at) VALUES (?, ?, ?, ?, 'student', 'active', ?)",
                    [$v['name'], $v['email'], $v['phone'], password_hash($pw, PASSWORD_DEFAULT), empty($_POST['verified']) ? null : date('Y-m-d H:i:s')]);
                $nid = (int) db()->lastInsertId();
                db_query('INSERT INTO student_profiles (user_id) VALUES (?)', [$nid]);
                return $nid;
            });
            audit_log('student_created_by_staff', 'user', $newId, [], 'warning');
            flash('success', 'Student account created. Share the temporary password securely and ask them to change it.');
            redirect_to('users.php?id=' . $newId);
        }
        flash('error', 'Please correct the highlighted fields.');
    }
    admin_start('Add Student', null, 'users', ['head' => true, 'crumbs' => '<a href="users.php">Students</a> / Add']);
    $fld = function ($name, $label, $type = 'text', $hint = '') use ($v, $errors) {
        $err = $errors[$name] ?? null;
        return '<div class="field' . ($err ? ' invalid' : '') . '"><label for="n-' . $name . '">' . $label . ' <span style="color:var(--red)">*</span></label><input id="n-' . $name . '" type="' . $type . '" name="' . $name . '" value="' . ($type === 'password' ? '' : e($v[$name] ?? '')) . '" required' . ($err ? ' aria-invalid="true"' : '') . '>' . ($hint ? '<div class="hint">' . $hint . '</div>' : '') . ($err ? '<div class="err">' . e($err) . '</div>' : '') . '</div>';
    };
    echo '<form method="post" class="card" data-dirty-check novalidate>' . csrf_field() . '<div class="card-pad form">'
        . $fld('name', 'Full name') . $fld('email', 'Email', 'email') . $fld('phone', 'Mobile number', 'tel') . $fld('password', 'Temporary password', 'password', '8+ characters with a letter and a number.')
        . '<div class="field full"><label class="check"><input type="checkbox" name="verified" value="1"> Email already verified (e.g. student enrolled in person)</label></div></div>'
        . '<div class="form-actions"><span class="dirty-flag">' . icon('alert') . ' Unsaved changes</span><a class="btn btn-light" href="users.php">Cancel</a><button class="btn btn-primary">' . icon('plus') . ' Create student</button></div></form>';
    admin_end();
    exit;
}

if ($id && admin_post()) {
    $student = db_one("SELECT * FROM users WHERE id = ? AND user_type = 'student'", [$id]);
    if (!$student) {
        redirect_to('users.php');
    }
    $act = $_POST['_action'] ?? '';
    require_permission(in_array($act, ['grant', 'revoke', 'extend'], true) ? 'enrolments.manage' : 'users.manage');
    switch ($act) {
        case 'status':
            $new = $_POST['status'] === 'active' ? 'active' : 'inactive';
            db_query('UPDATE users SET status = ? WHERE id = ?', [$new, $id]);
            if ($new === 'inactive') {
                revoke_user_sessions($id);
            }
            audit_log('student_status_changed', 'user', $id, ['status' => $new], 'warning');
            flash('success', $new === 'active' ? 'Account unblocked. The student can sign in again.' : 'Account blocked and signed out everywhere.');
            break;
        case 'verify':
            db_query('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [$id]);
            audit_log('email_verified_by_staff', 'user', $id);
            flash('success', 'Email marked as verified.');
            break;
        case 'grant':
            $courseId = (int) $_POST['course_id'];
            if (db_value("SELECT 1 FROM course_enrolments WHERE user_id = ? AND course_id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())", [$id, $courseId])) {
                flash('warning', 'This student already has access to that course.');
                break;
            }
            if (db_value('SELECT 1 FROM courses WHERE id = ?', [$courseId])) {
                enrol_user($id, $courseId, 'admin', null, (int) $me['id']);
                require_once APP_ROOT . '/includes/notify.php';
                $c = db_one('SELECT title, slug FROM courses WHERE id = ?', [$courseId]);
                send_template('enrolment', $id, ['course' => $c['title'], 'link' => '/learn/' . $c['slug'], 'button_label' => 'Start learning']);
                audit_log('enrolment_granted', 'user', $id, ['course_id' => $courseId], 'warning');
                flash('success', 'Enrolment granted.');
            }
            break;
        case 'extend':
            $days = max(1, min(3650, (int) $_POST['days']));
            db_query("UPDATE course_enrolments SET status = 'active', expires_at = DATE_ADD(GREATEST(COALESCE(expires_at, NOW()), NOW()), INTERVAL ? DAY) WHERE id = ? AND user_id = ? AND expires_at IS NOT NULL", [$days, (int) $_POST['enrolment_id'], $id]);
            audit_log('enrolment_extended', 'user', $id, ['enrolment_id' => (int) $_POST['enrolment_id'], 'days' => $days], 'warning');
            flash('success', "Access extended by {$days} days.");
            break;
        case 'revoke':
            db_query("UPDATE course_enrolments SET status = 'revoked' WHERE id = ? AND user_id = ?", [(int) $_POST['enrolment_id'], $id]);
            audit_log('enrolment_revoked', 'user', $id, ['enrolment_id' => (int) $_POST['enrolment_id']], 'warning');
            flash('success', 'Enrolment revoked.');
            break;
        case 'anonymise':
            // Process a deletion request: remove personal data but keep financial records (required for accounts/tax).
            db_transaction(function () use ($id) {
                db_query("UPDATE users SET name = 'Deleted user', email = CONCAT('deleted-', id, '@invalid.local'), phone = NULL, avatar_path = NULL, status = 'deleted', password_hash = ? WHERE id = ?", [password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $id]);
                db_query('DELETE FROM student_profiles WHERE user_id = ?', [$id]);
                db_query('DELETE FROM notifications WHERE user_id = ?', [$id]);
                db_query("UPDATE course_enrolments SET status = 'revoked' WHERE user_id = ?", [$id]);
                revoke_user_sessions($id);
            });
            audit_log('student_anonymised', 'user', $id, [], 'critical');
            flash('success', 'Personal data removed. Orders and receipts were kept for accounting.');
            break;
    }
    redirect_to('users.php?id=' . $id);
}

if ($id) {
    $u = db_one("SELECT u.*, p.city, p.state, p.exam_preferences, p.date_of_birth FROM users u LEFT JOIN student_profiles p ON p.user_id = u.id WHERE u.id = ? AND u.user_type = 'student'", [$id]);
    if (!$u) {
        flash('error', 'Student not found.');
        redirect_to('users.php');
    }
    $enrolments = db_all('SELECT e.*, c.title FROM course_enrolments e JOIN courses c ON c.id = e.course_id WHERE e.user_id = ? ORDER BY e.enrolled_at DESC', [$id]);
    $orders = db_all('SELECT order_number, status, total, created_at FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$id]);
    $attempts = db_all("SELECT a.status, a.score, a.submitted_at, e.title, e.total_marks FROM exam_attempts a JOIN exams e ON e.id = a.exam_id WHERE a.user_id = ? ORDER BY a.started_at DESC LIMIT 20", [$id]);
    $logins = db_all("SELECT ip_address, success, created_at FROM login_attempts WHERE user_id = ? ORDER BY id DESC LIMIT 10", [$id]);
    $prefs = implode(', ', json_decode_array($u['exam_preferences']));

    admin_start($u['name'], null, 'users', ['head' => true, 'crumbs' => '<a href="users.php">Students</a> / Profile', 'subtitle' => $u['email'] . ' · joined ' . fmt_dt($u['created_at'], 'j M Y')]);
    if ($u['deletion_requested_at'] && $u['status'] !== 'deleted') {
        echo '<div class="alert alert-warning">' . icon('alert') . '<span>This student requested account deletion on ' . e(fmt_dt($u['deletion_requested_at'])) . '. ' . (can('users.manage') ? action_form('anonymise', [], 'Process deletion (anonymise)', 'btn btn-danger btn-sm', 'Remove this student\'s personal data permanently? Orders are kept for accounting.') : '') . '</span></div>';
    }
    $mini = fn(string $text, string $ico) => '<div class="empty" style="padding:22px 16px"><span class="empty-ic" style="width:48px;height:48px">' . icon($ico) . '</span><p style="margin:0">' . e($text) . '</p></div>';
    $sources = ['purchase' => ['Purchase', 'b-green'], 'admin' => ['Granted by staff', 'b-purple'], 'free' => ['Free', 'b-gray']];
    ?>
    <div class="split-1-2">
      <div class="card card-pad">
        <div class="d1-profile-top">
          <?php if ($u['avatar_path'] && ($av = public_file_url($u['avatar_path']))): ?><img class="d1-avatar" src="<?= e($av) ?>" alt=""><?php else: ?><span class="d1-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span><?php endif; ?>
          <div style="min-width:0"><strong style="font-size:16px;color:var(--navy);display:block;overflow-wrap:anywhere"><?= e($u['name']) ?></strong>
            <div class="d1-sub" style="margin-top:6px"><?= student_status_badge((string) $u['status']) ?><?= $u['email_verified_at'] ? '<span class="badge b-green">Email verified</span>' : '<span class="badge b-amber">Email not verified</span>' ?></div></div>
        </div>
        <dl class="kv">
          <dt>Email</dt><dd><a href="mailto:<?= e($u['email']) ?>"><?= e($u['email']) ?></a></dd>
          <dt>Phone</dt><dd><?= $u['phone'] ? '<a href="tel:' . e($u['phone']) . '">' . e($u['phone']) . '</a>' : '<span class="muted">Not added</span>' ?></dd>
          <dt>Location</dt><dd><?= e(trim(($u['city'] ?? '') . ', ' . ($u['state'] ?? ''), ', ') ?: '—') ?></dd>
          <dt>Preparing for</dt><dd><?= e($prefs ?: '—') ?></dd>
          <dt>Joined</dt><dd><?= e(fmt_dt($u['created_at'], 'j M Y')) ?></dd>
          <dt>Last login</dt><dd><?= $u['last_login_at'] ? e(fmt_dt($u['last_login_at'])) : '<span class="muted">Never</span>' ?></dd>
          <dt>Last IP</dt><dd><?= e($u['last_login_ip'] ?? '—') ?></dd>
        </dl>
        <?php if (can('users.manage') && $u['status'] !== 'deleted'): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px;padding-top:16px;border-top:1px solid var(--line)">
          <?= $u['status'] === 'active'
              ? action_form('status', ['status' => 'inactive'], icon('lock') . ' Block account', 'btn btn-danger btn-sm', 'Block this account and sign the student out everywhere?')
              : action_form('status', ['status' => 'active'], icon('unlock') . ' Unblock account', 'btn btn-light btn-sm') ?>
          <?= !$u['email_verified_at'] ? action_form('verify', [], icon('check') . ' Mark email verified', 'btn btn-light btn-sm', 'Mark this student’s email as verified?') : '' ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="card">
        <div class="card-head"><h2><?= icon('cap') ?> Enrolments <span class="tab-count"><?= count($enrolments) ?></span></h2></div>
        <?php if (can('enrolments.manage') && $u['status'] === 'active'):
            // Only courses the student cannot open right now (expired or revoked access can be granted again).
            $hasAccess = array_map('intval', array_column(array_filter($enrolments, fn($en) => $en['status'] === 'active' && (!$en['expires_at'] || strtotime((string) $en['expires_at']) > time())), 'course_id'));
            $grantable = array_filter(db_all("SELECT id, title FROM courses WHERE status <> 'archived' ORDER BY title"), fn($c) => !in_array((int) $c['id'], $hasAccess, true)); ?>
        <?php if ($grantable): ?>
        <form method="post" class="toolbar"><?= csrf_field() ?><input type="hidden" name="_action" value="grant">
          <label class="sr-only" for="grant-course">Course to grant</label>
          <select class="in" id="grant-course" name="course_id" style="flex:1 1 220px;min-width:0"><?php foreach ($grantable as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['title']) ?></option><?php endforeach; ?></select>
          <button class="btn btn-primary" data-confirm="Grant free access to this course? This is recorded in the audit log."><?= icon('plus') ?> Grant access</button>
        </form>
        <?php else: ?>
        <p class="toolbar muted" style="margin:0">This student already has access to every available course.</p>
        <?php endif; ?>
        <?php endif; ?>
        <?php if (!$enrolments): ?><?= $mini('Not enrolled in any course yet.', 'cap') ?><?php else: ?>
        <div class="table-wrap cq-stack"><table class="t stack"><thead><tr><th scope="col">Course</th><th scope="col">Source</th><th scope="col">Progress</th><th scope="col">Expires</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>
        <?php foreach ($enrolments as $en): [$srcLabel, $srcTone] = $sources[$en['source']] ?? [ucfirst((string) $en['source']), 'b-gray']; ?>
          <tr><td data-label="Course"><div class="d1-main sm"><span class="d1-t"><?= e($en['title']) ?></span><div class="d1-sub"><span>Enrolled <?= e(fmt_dt($en['enrolled_at'], 'j M Y')) ?></span></div></div></td>
          <td data-label="Source"><span class="badge <?= $srcTone ?>"><?= e($srcLabel) ?></span></td>
          <td data-label="Progress"><span class="d1-bar"><span><i style="width:<?= max(0, min(100, (float) $en['progress_percent'])) ?>%"></i></span><?= round((float) $en['progress_percent']) ?>%</span></td>
          <td data-label="Expires" class="d1-when"><?= e($en['expires_at'] ? fmt_dt($en['expires_at'], 'j M Y') : 'Lifetime') ?></td>
          <td data-label="Status"><?= badge($en['status'] === 'active' && $en['expires_at'] && strtotime((string) $en['expires_at']) <= time() ? 'expired' : $en['status']) ?></td>
          <td class="actions"><?php if (can('enrolments.manage')): ?><span class="d1-acts">
            <?php if ($en['expires_at']): ?><form method="post" class="d1-inline-form"><?= csrf_field() ?><input type="hidden" name="_action" value="extend"><input type="hidden" name="enrolment_id" value="<?= (int) $en['id'] ?>"><input class="in" type="number" name="days" value="30" min="1" max="3650" aria-label="Days to extend <?= e($en['title']) ?>"><button class="btn btn-light btn-sm" title="Extend access by this many days">Extend</button></form><?php endif; ?>
            <?= $en['status'] === 'active' ? action_form('revoke', ['enrolment_id' => $en['id']], 'Revoke', 'btn btn-danger btn-sm', 'Revoke access to “' . $en['title'] . '”?') : '' ?>
          </span><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="grid g-3 mt">
      <div class="card"><div class="card-head"><h2><?= icon('cart') ?> Orders</h2></div>
        <?php if (!$orders): ?><?= $mini('No orders yet.', 'cart') ?><?php else: ?><div class="table-wrap"><table class="t"><tbody>
        <?php foreach ($orders as $o): ?><tr><td><?= can('orders.view') ? '<a href="orders.php?order=' . e($o['order_number']) . '"><strong>' . e($o['order_number']) . '</strong></a>' : '<strong>' . e($o['order_number']) . '</strong>' ?><br><span class="muted"><?= e(fmt_dt($o['created_at'], 'j M Y')) ?></span></td><td class="d1-num"><?= e(money($o['total'])) ?></td><td><?= badge($o['status']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?></div>
      <div class="card"><div class="card-head"><h2><?= icon('clipboard') ?> Test attempts</h2></div>
        <?php if (!$attempts): ?><?= $mini('No test attempts yet.', 'clipboard') ?><?php else: ?><div class="table-wrap"><table class="t"><tbody>
        <?php foreach ($attempts as $a): ?><tr><td><strong><?= e($a['title']) ?></strong><br><span class="muted"><?= $a['submitted_at'] ? e(fmt_dt($a['submitted_at'], 'j M Y')) : 'Not submitted' ?></span></td><td class="d1-num"><?= $a['score'] !== null ? e(rtrim(rtrim(number_format((float) $a['score'], 2, '.', ''), '0'), '.')) . ' / ' . e(rtrim(rtrim(number_format((float) $a['total_marks'], 2, '.', ''), '0'), '.')) : ($a['status'] === 'in_progress' ? '<span class="badge b-amber">In progress</span>' : badge($a['status'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?></div>
      <div class="card"><div class="card-head"><h2><?= icon('history') ?> Recent logins</h2></div>
        <?php if (!$logins): ?><?= $mini('No login history.', 'history') ?><?php else: ?><div class="table-wrap"><table class="t"><tbody>
        <?php foreach ($logins as $l): ?><tr><td><?= e(fmt_dt($l['created_at'])) ?><br><span class="muted"><?= e($l['ip_address']) ?></span></td><td><?= $l['success'] ? '<span class="badge b-green">Signed in</span>' : '<span class="badge b-red">Failed</span>' ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?></div>
    </div>
    <?php
    admin_end();
    exit;
}

// ---------------- List ----------------
$where = ["u.user_type = 'student'"];
$params = [];
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    array_push($params, $like, $like, $like);
}
$status = (string) ($_GET['status'] ?? '');
if (in_array($status, ['active', 'inactive', 'deleted'], true)) {
    $where[] = 'u.status = ?';
    $params[] = $status;
}
if (!empty($_GET['deletion'])) {
    $where[] = "u.deletion_requested_at IS NOT NULL AND u.status <> 'deleted'";
}
$w = implode(' AND ', $where);
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = (int) db_value("SELECT COUNT(*) FROM users u WHERE {$w}", $params);
$rows = db_all("SELECT u.*, (SELECT COUNT(*) FROM course_enrolments e WHERE e.user_id = u.id AND e.status = 'active') AS enrolled,
    (SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.user_id = u.id AND o.status = 'paid') AS spent
    FROM users u WHERE {$w} ORDER BY u.id DESC LIMIT 25 OFFSET " . (($page - 1) * 25), $params);
$deletionCount = (int) db_value("SELECT COUNT(*) FROM users WHERE deletion_requested_at IS NOT NULL AND status <> 'deleted' AND user_type = 'student'");

$filtered = $q !== '' || $status !== '' || !empty($_GET['deletion']);
admin_start('Students', null, 'users', ['head' => true, 'subtitle' => number_format($total) . ' student' . ($total === 1 ? '' : 's') . ($filtered ? ' match these filters' : ''),
    'actions' => (can('reports.export') ? '<a class="btn btn-light" href="?export=csv">' . icon('download') . ' Export CSV</a>' : '')
        . (can('users.manage') ? '<a class="btn btn-primary" href="?action=new">' . icon('plus') . ' Add student</a>' : '')]);
if ($deletionCount && empty($_GET['deletion'])) {
    echo '<div class="alert alert-warning">' . icon('alert') . '<span>' . ($deletionCount === 1 ? '1 account deletion request is' : $deletionCount . ' account deletion requests are') . ' waiting. <a href="?deletion=1">Review</a></span></div>';
}
?>
<div class="card">
  <form class="toolbar d1-toolbar" method="get" role="search">
    <?php if (!empty($_GET['deletion'])): ?><input type="hidden" name="deletion" value="1"><?php endif; ?>
    <label class="search-in"><span class="sr-only">Search students</span><?= icon('search') ?><input class="in" type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email or phone…"></label>
    <select class="in" style="width:auto" name="status" aria-label="Status" data-autosubmit>
      <option value="">All statuses</option>
      <?php foreach (['active' => 'Active', 'inactive' => 'Blocked', 'deleted' => 'Deleted'] as $k => $l): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-light"><?= icon('filter') ?> Filter</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="users.php">Reset</a><?php endif; ?>
  </form>
  <?php if (!$rows && $total > 0): ?>
  <div class="empty"><span class="empty-ic"><?= icon('list') ?></span><strong>Nothing on this page</strong><p>The list is shorter than this page number.</p><a class="btn btn-light btn-sm" href="<?= e(url_with(['page' => null])) ?>">Go to the first page</a></div>
  <?php elseif (!$rows): ?>
  <div class="empty"><span class="empty-ic"><?= icon($filtered ? 'search' : 'users') ?></span><strong><?= $filtered ? 'No students match these filters' : 'No students yet' ?></strong>
    <p><?= $filtered ? 'Try another name, email or phone number, or clear the filters.' : 'Students appear here when they register on the website or when staff add them.' ?></p>
    <?php if ($filtered): ?><a class="btn btn-light btn-sm" href="users.php">Clear filters</a><?php elseif (can('users.manage')): ?><a class="btn btn-primary btn-sm" href="?action=new"><?= icon('plus') ?> Add student</a><?php endif; ?></div>
  <?php else: ?>
  <div class="table-wrap"><table class="t stack">
    <thead><tr><th scope="col">Student</th><th scope="col">Phone</th><th scope="col">Courses</th><th scope="col">Spent</th><th scope="col">Joined</th><th scope="col">Last login</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td data-label="Student"><div class="d1-main sm d1-media">
          <?php if ($r['avatar_path'] && ($av = public_file_url($r['avatar_path']))): ?><img class="d1-avatar" src="<?= e($av) ?>" alt="" loading="lazy"><?php else: ?><span class="d1-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $r['name'], 0, 1))) ?></span><?php endif; ?>
          <div class="d1-text"><a class="d1-t" href="?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a><div class="d1-sub"><span><?= e($r['email']) ?></span><?= !empty($r['is_demo']) ? '<span class="badge b-demo">Demo</span>' : '' ?></div></div></div></td>
        <td data-label="Phone" class="d1-nowrap"><?= $r['phone'] ? e($r['phone']) : '<span class="muted">—</span>' ?></td>
        <td data-label="Courses" class="d1-num"><?= (int) $r['enrolled'] ?: '<span class="muted">0</span>' ?></td>
        <td data-label="Spent" class="d1-num"><?= (float) $r['spent'] > 0 ? '<strong>' . e(money($r['spent'])) . '</strong>' : '<span class="muted">₹0</span>' ?></td>
        <td data-label="Joined"><span class="d1-when"><?= e(fmt_dt($r['created_at'], 'j M Y')) ?></span></td>
        <td data-label="Last login"><span class="d1-when"><?= $r['last_login_at'] ? e(fmt_dt($r['last_login_at'], 'j M Y')) . '<small>' . e(fmt_dt($r['last_login_at'], 'g:i A')) . '</small>' : '<span class="muted">Never</span>' ?></span></td>
        <td data-label="Status"><div class="d1-stack"><?= student_status_badge((string) $r['status']) ?><?= $r['deletion_requested_at'] && $r['status'] !== 'deleted' ? '<span class="badge b-red">Deletion requested</span>' : '' ?></div></td>
        <td class="actions"><a class="btn btn-light btn-sm" href="?id=<?= (int) $r['id'] ?>"><?= icon('eye') ?> View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php render_pager($total, $page, 25); ?>
  <?php endif; ?>
</div>
<?php admin_end();
