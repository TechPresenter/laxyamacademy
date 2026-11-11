<?php
// Signed-in staff member's own profile, password, sessions and layout preferences.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';

$me = require_staff_page();

if (admin_post()) {
    $act = $_POST['_action'] ?? '';
    // Top-bar "Academic Session" selector: remember the choice, then return to the page it was made on.
    if ($act === 'academic_session') {
        $key = (string) ($_POST['session'] ?? '');
        if (admin_set_session($key)) {
            flash('success', 'Academic session ' . $key . ' selected: the dashboard and reports now default to ' . academic_session_span(academic_session_range($key)) . '.');
        } else {
            flash('error', 'That academic session is not available.');
        }
        $back = (string) ($_POST['back'] ?? '');
        // Only a page of this admin panel (no scheme, host or path traversal)
        redirect_to(preg_match('#^[a-z0-9-]+\.php(\?[^\s<>"\'\\\\]*)?$#i', $back) ? $back : 'dashboard.php');
    }
    if ($act === 'account') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            flash('error', 'Enter your name (2-120 characters).');
        } else {
            db_query('UPDATE users SET name = ?, phone = ? WHERE id = ?', [$name, preg_replace('/[^\d+]/', '', (string) ($_POST['phone'] ?? '')) ?: null, $me['id']]);
            audit_log('staff_profile_updated', 'user', $me['id']);
            flash('success', 'Profile updated.');
        }
    }
    if ($act === 'password') {
        $row = db_one('SELECT password_hash FROM users WHERE id = ?', [$me['id']]);
        $new = (string) ($_POST['password'] ?? '');
        if (!password_verify((string) ($_POST['current'] ?? ''), $row['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 10 || password_policy_error($new)) {
            flash('error', 'New password needs at least 10 characters including a letter and a number.');
        } elseif ($new !== (string) ($_POST['confirm'] ?? '')) {
            flash('error', 'New passwords do not match.');
        } else {
            db_query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            revoke_user_sessions((int) $me['id'], true);
            audit_log('staff_password_changed', 'user', $me['id'], [], 'warning');
            flash('success', 'Password changed. Your other sessions were signed out.');
        }
    }
    // Super Admins may change their own sign-in email (e.g. replacing the demo login before launch).
    if ($act === 'email' && is_super_admin($me)) {
        $row = db_one('SELECT password_hash FROM users WHERE id = ?', [$me['id']]);
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (!password_verify((string) ($_POST['current'] ?? ''), $row['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            flash('error', 'Enter a valid email address.');
        } elseif (preg_match('/@example\.(com|org|net)$/', $email)) {
            flash('error', 'Use a real mailbox you can receive email at.');
        } elseif (db_value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $me['id']])) {
            flash('error', 'Another account already uses this email.');
        } else {
            db_query('UPDATE users SET email = ?, email_verified_at = NOW() WHERE id = ?', [$email, $me['id']]);
            revoke_user_sessions((int) $me['id'], true);
            audit_log('staff_email_changed', 'user', $me['id'], ['from' => $me['email'], 'to' => $email], 'warning');
            flash('success', 'Sign-in email changed. Use ' . $email . ' next time you sign in.');
        }
    }
    if ($act === 'revoke') {
        db_query('UPDATE user_sessions SET revoked_at = NOW() WHERE id = ? AND user_id = ? AND session_hash <> ?', [(int) $_POST['id'], $me['id'], hash('sha256', session_id())]);
        flash('success', 'Session signed out.');
    }
    redirect_to('profile.php');
}

$u = db_one('SELECT name, email, phone, created_at, last_login_at, last_login_ip FROM users WHERE id = ?', [$me['id']]);
$roles = array_map(fn($r) => ucwords(str_replace('_', ' ', $r)), user_role_slugs((int) $me['id']));
$sessions = db_all('SELECT id, session_hash, ip_address, user_agent, last_seen_at FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_seen_at DESC LIMIT 20', [$me['id']]);
$current = hash('sha256', session_id());

admin_start('My Profile', null, '', ['head' => true, 'subtitle' => 'Your staff account, password and sessions.']);
?>
<div class="grid g-2">
  <form method="post" class="card" id="account" data-dirty-check>
    <?= csrf_field() ?><input type="hidden" name="_action" value="account">
    <div class="card-head"><h2>Account</h2></div>
    <div class="card-pad form">
      <div class="field"><label for="pn">Name</label><input id="pn" type="text" name="name" value="<?= e($u['name']) ?>" required></div>
      <div class="field"><label for="pp">Phone</label><input id="pp" type="text" name="phone" value="<?= e($u['phone'] ?? '') ?>"></div>
      <div class="field"><label for="pe">Email</label><input id="pe" type="email" value="<?= e($u['email']) ?>" disabled><div class="hint"><?= is_super_admin($me) ? 'Change it below under “Sign-in email”.' : 'Ask a Super Admin to change your sign-in email.' ?></div></div>
      <div class="field"><span class="lbl">Roles</span><?= implode(' ', array_map(fn($r) => '<span class="badge b-blue">' . e($r) . '</span>', $roles)) ?></div>
      <div class="field full muted" style="font-size:12px">Member since <?= e(fmt_dt($u['created_at'], 'j M Y')) ?> · last login <?= e(fmt_dt($u['last_login_at'])) ?> from <?= e($u['last_login_ip'] ?? '—') ?></div>
    </div>
    <div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><button class="btn btn-primary"><?= icon('check') ?> Save</button></div>
  </form>
  <form method="post" class="card" id="password" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="_action" value="password">
    <div class="card-head"><h2>Change password</h2></div>
    <div class="card-pad" style="display:grid;gap:14px">
      <div class="field"><label for="cp">Current password</label><input id="cp" type="password" name="current" autocomplete="current-password" required></div>
      <div class="field"><label for="np">New password</label><input id="np" type="password" name="password" autocomplete="new-password" minlength="10" required><div class="hint">At least 10 characters with letters and numbers.</div></div>
      <div class="field"><label for="np2">Confirm new password</label><input id="np2" type="password" name="confirm" autocomplete="new-password" required></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" data-confirm="Change your password? Other sessions will be signed out.">Update password</button></div>
  </form>
<?php if (is_super_admin($me)): ?>
  <form method="post" class="card" id="email" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="_action" value="email">
    <div class="card-head"><h2>Sign-in email</h2><?= preg_match('/@example\.(com|org|net)$/', $u['email']) ? '<span class="badge b-red">Demo login - change before launch</span>' : '' ?></div>
    <div class="card-pad" style="display:grid;gap:14px">
      <div class="field"><label for="ne">New email</label><input id="ne" type="email" name="email" maxlength="190" autocomplete="email" required></div>
      <div class="field"><label for="cp2">Current password</label><input id="cp2" type="password" name="current" autocomplete="current-password" required></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" data-confirm="Change your sign-in email? Your other sessions will be signed out.">Change email</button></div>
  </form>
<?php endif; ?>
</div>

<div class="grid g-2 mt">
  <div class="card" id="sessions">
    <div class="card-head"><h2>Your sessions</h2></div>
    <div class="table-wrap"><table class="t"><tbody>
    <?php foreach ($sessions as $s): $mine = hash_equals($s['session_hash'], $current); ?>
      <tr><td><span class="muted" style="font-size:12px"><?= e(mb_strimwidth((string) $s['user_agent'], 0, 70, '…')) ?></span><br><code><?= e($s['ip_address']) ?></code> · <?= e(fmt_dt($s['last_seen_at'])) ?><?= $mine ? ' <span class="badge b-blue">This session</span>' : '' ?></td>
        <td class="actions"><?= $mine ? '' : action_form('revoke', ['id' => $s['id']], 'Sign out', 'btn btn-light btn-sm') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </div>
  <div class="card" id="layout">
    <div class="card-head"><h2>Layout preferences</h2><span class="muted" style="font-size:12px">Saved on this device</span></div>
    <div class="card-pad" style="display:grid;gap:16px">
      <label class="switch"><input type="checkbox" value="1" data-pref="collapsed"><span class="track"></span> Collapse the sidebar to icons</label>
      <div class="field"><label for="den">Table density</label><select id="den" class="in" data-pref="density"><option value="comfortable">Comfortable</option><option value="compact">Compact</option></select></div>
      <div class="field"><label for="thm">Colour theme</label><select id="thm" class="in" data-pref="theme"><option value="">Match my device</option><option value="light">Light</option><option value="dark">Dark</option></select><div class="hint">The moon / sun button in the top bar switches it too.</div></div>
      <div class="field"><span class="lbl">Academic session</span><span class="badge b-blue"><?= e(admin_session()['label']) ?></span> <span class="muted" style="font-size:12px"><?= e(academic_session_span(admin_session())) ?> · change it from the top bar</span></div>
    </div>
  </div>
</div>
<?php admin_end();
