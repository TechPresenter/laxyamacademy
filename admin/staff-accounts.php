<?php
// Staff accounts (Security → Staff): list, add (invitation email or temporary password), edit details,
// roles, deactivate/reactivate, password links, forced password reset, two-step reset and sessions.
// Served as users.php?type=staff - users.php includes this file before its student code. Opening this
// file directly redirects there. Every action is checked here on the server (roles.manage, plus the
// rules in includes/admin-security.php: only Super Admins touch Super Admin access, no self-escalation).
declare(strict_types=1);

if (!function_exists('admin_start')) {
    header('Location: users.php?type=staff', true, 302);
    exit;
}
require_once APP_ROOT . '/includes/admin-security.php';
require_once APP_ROOT . '/includes/mailer.php';

$me = require_staff_page();
require_permission('roles.manage');

const SA_BASE = 'users.php?type=staff';
$sid = (int) ($_GET['id'] ?? 0);
$roles = db_all('SELECT * FROM roles ORDER BY id');
$rolesById = [];
foreach ($roles as $r) {
    $rolesById[(int) $r['id']] = $r;
}
$rolePerms = sec_role_permissions();
$isSuper = is_super_admin($me);
$assignable = fn(array $role): bool => sec_role_assignable($role, $me, $rolePerms);

/** Why $me may not change account $t (null = allowed). */
function sa_manage_block(array $t, array $me, array $rolesById, array $rolePerms): ?string
{
    if ((int) $t['id'] === (int) $me['id']) {
        return 'This is your own account. Change your details, password and sessions in My profile.';
    }
    foreach (db_all('SELECT role_id FROM user_roles WHERE user_id = ?', [(int) $t['id']]) as $r) {
        $role = $rolesById[(int) $r['role_id']] ?? null;
        if ($role && !sec_role_assignable($role, $me, $rolePerms)) {
            return $role['slug'] === 'super_admin'
                ? 'Only a Super Admin can change a Super Admin account.'
                : 'This account has the ' . $role['name'] . ' role, which includes permissions you do not hold. Ask a Super Admin.';
        }
    }
    return null;
}

/** Role ids posted in roles[] that exist. */
function sa_posted_roles(array $rolesById): array
{
    $ids = array_values(array_unique(array_map('intval', (array) ($_POST['roles'] ?? []))));
    return array_values(array_filter($ids, fn($id) => isset($rolesById[$id])));
}

/** Validation for the staff password rule (shared policy + 10 characters for staff). */
function sa_password_error(string $pw, string $email): ?string
{
    if ($e = password_policy_error($pw)) {
        return $e;
    }
    if (strlen($pw) < 10) {
        return 'Staff passwords need at least 10 characters.';
    }
    $local = (string) strtok($email, '@');
    if (strcasecmp($pw, $email) === 0 || (strlen($local) >= 4 && stripos($pw, $local) !== false)) {
        return 'The password must not contain the email address.';
    }
    return null;
}

/**
 * Render the admin shell for a staff screen. While the Security menu (includes/admin-sidebar.php) still links
 * "Staff Accounts" as roles.php?tab=staff instead of users.php?type=staff, the menu is matched as if this were
 * that URL, so the Security tab bar (not the Students one) is shown. Switches itself off once the menu links here.
 */
function sa_start(string $title, array $opts): void
{
    $direct = false;
    $legacy = false;
    foreach (admin_menu() as $m) {
        foreach ($m[3] as $it) {
            $direct = $direct || $it[1] === SA_BASE;
            $legacy = $legacy || $it[1] === 'roles.php?tab=staff';
        }
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (!$direct && $legacy) {
        $_SERVER['SCRIPT_NAME'] = dirname($script) . '/roles.php';
        $_GET['tab'] = 'staff';
    }
    admin_start($title, null, 'security', $opts);
    if (!$direct && $legacy) {
        $_SERVER['SCRIPT_NAME'] = $script;
        unset($_GET['tab']);
    }
}

/** Flash the outcome of an emailed password link. */
function sa_link_flash(array $res, string $okText, string $who): void
{
    if ($res['sent']) {
        flash('success', $okText);
    } else {
        flash('warning', (!mail_configured() ?'Email is not configured on this server (SMTP), so nothing was sent to ' . $who . '.' : 'The email to ' . $who . ' could not be delivered.')
            . ' Share this one-time link with them securely (it is not stored anywhere): ' . $res['link']);
    }
}

$errors = [];
$view = $sid ? 'detail' : ((!empty($_GET['add'])) ? 'new' : 'list');
$v = ['name' => '', 'email' => '', 'phone' => '', 'method' => 'invite', 'roles' => [], 'faculty' => 0];

// ------------------------------------------------------------------ actions
if (admin_post()) {
    $act = (string) ($_POST['_action'] ?? '');

    if ($act === 'create') {
        $view = 'new';
        $v = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
            'phone' => preg_replace('/[^\d+]/', '', (string) ($_POST['phone'] ?? '')),
            'method' => ($_POST['method'] ?? '') === 'password' ? 'password' : 'invite',
            'roles' => sa_posted_roles($rolesById),
            'faculty' => (int) ($_POST['faculty_id'] ?? 0),
        ];
        $pw = (string) ($_POST['password'] ?? '');
        if (mb_strlen($v['name']) < 2 || mb_strlen($v['name']) > 120) {
            $errors['name'] = 'Enter the staff member’s full name (2-120 characters).';
        }
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 190) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (db_value('SELECT 1 FROM users WHERE email = ?', [$v['email']])) {
            $errors['email'] = 'An account (staff or student) already uses this email.';
        }
        if ($v['phone'] !== '' && !preg_match('/^\+?\d{10,14}$/', $v['phone'])) {
            $errors['phone'] = 'Enter a 10-14 digit phone number, or leave it empty.';
        }
        if (!$v['roles']) {
            $errors['roles'] = 'Choose at least one role.';
        } elseif ($bad = array_filter($v['roles'], fn($id) => !$assignable($rolesById[$id]))) {
            $errors['roles'] = 'You cannot give the ' . $rolesById[reset($bad)]['name'] . ' role' . ($rolesById[reset($bad)]['slug'] === 'super_admin' ? ' - only a Super Admin can.' : ' because it includes permissions you do not hold.');
        }
        if ($v['method'] === 'password' && ($e = sa_password_error($pw, $v['email']))) {
            $errors['password'] = $e;
        }
        if ($v['faculty'] && !db_value('SELECT 1 FROM instructors WHERE id = ? AND user_id IS NULL', [$v['faculty']])) {
            $errors['faculty_id'] = 'That faculty profile is already linked to another account.';
        }
        if (!$errors) {
            $hash = password_hash($v['method'] === 'password' ? $pw : bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
            $newId = db_transaction(function () use ($v, $hash, $me) {
                db_query("INSERT INTO users (name, email, phone, password_hash, user_type, status, email_verified_at) VALUES (?, ?, ?, ?, 'staff', 'active', NOW())",
                    [$v['name'], $v['email'], $v['phone'] !== '' ? $v['phone'] : null, $hash]);
                $uid = (int) db()->lastInsertId();
                foreach ($v['roles'] as $rid) {
                    db_query('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)', [$uid, $rid, $me['id']]);
                }
                if ($v['faculty']) {
                    db_query('UPDATE instructors SET user_id = ? WHERE id = ? AND user_id IS NULL', [$uid, $v['faculty']]);
                }
                return $uid;
            });
            $roleNames = array_map(fn($id) => $rolesById[$id]['name'], $v['roles']);
            audit_log('staff_created', 'user', $newId, ['roles' => array_map(fn($id) => $rolesById[$id]['slug'], $v['roles']), 'method' => $v['method']], 'critical');
            if ($v['method'] === 'invite') {
                $res = sec_send_staff_link(['id' => $newId, 'name' => $v['name'], 'email' => $v['email']], 'invite', $me, $roleNames);
                sa_link_flash($res, 'Staff account created and an invitation was emailed to ' . $v['email'] . '. The set-password link expires in 72 hours.', $v['email']);
            } else {
                flash('success', 'Staff account created. Share the temporary password with ' . $v['name'] . ' securely and ask them to change it in My profile.');
            }
            redirect_to(SA_BASE . '&id=' . $newId);
        }
        flash('error', 'Please correct the highlighted fields.');
    } else {
        $uid = (int) ($_POST['user_id'] ?? $sid);
        $t = db_one("SELECT * FROM users WHERE id = ? AND user_type = 'staff'", [$uid]);
        if (!$t) {
            flash('error', 'Staff account not found.');
            redirect_to(SA_BASE);
        }
        $back = SA_BASE . '&id=' . $uid;
        if ($why = sa_manage_block($t, $me, $rolesById, $rolePerms)) {
            flash('error', $why);
            redirect_to($back);
        }
        $tSuper = in_array('super_admin', user_role_slugs($uid), true);
        switch ($act) {
            case 'profile':
                $name = trim((string) ($_POST['name'] ?? ''));
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $phone = preg_replace('/[^\d+]/', '', (string) ($_POST['phone'] ?? ''));
                if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                    flash('error', 'Enter the full name (2-120 characters).');
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
                    flash('error', 'Enter a valid email address.');
                } elseif ($email !== $t['email'] && db_value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $uid])) {
                    flash('error', 'Another account already uses ' . $email . '.');
                } elseif ($phone !== '' && !preg_match('/^\+?\d{10,14}$/', $phone)) {
                    flash('error', 'Enter a 10-14 digit phone number, or leave it empty.');
                } else {
                    db_query('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?', [$name, $email, $phone !== '' ? $phone : null, $uid]);
                    audit_log('staff_profile_updated', 'user', $uid, ['email_changed' => $email !== $t['email']], $email !== $t['email'] ? 'critical' : 'warning');
                    flash('success', 'Details saved.' . ($email !== $t['email'] ? ' They now sign in with ' . $email . '.' : ''));
                }
                break;

            case 'roles':
                $new = sa_posted_roles($rolesById);
                $old = array_map('intval', array_column(db_all('SELECT role_id FROM user_roles WHERE user_id = ?', [$uid]), 'role_id'));
                $changed = array_merge(array_diff($new, $old), array_diff($old, $new));
                $superId = (int) (array_column($roles, 'id', 'slug')['super_admin'] ?? 0);
                if (!$new) {
                    flash('error', 'Choose at least one role (or deactivate the account instead).');
                } elseif ($bad = array_filter($changed, fn($id) => !$assignable($rolesById[$id]))) {
                    flash('error', 'You cannot give or remove the ' . $rolesById[reset($bad)]['name'] . ' role.');
                } elseif ($tSuper && !in_array($superId, $new, true) && $t['status'] === 'active' && sec_active_super_admins() <= 1) {
                    flash('error', 'At least one active Super Admin must remain.');
                } elseif (!$changed) {
                    flash('info', 'No changes to save.');
                } else {
                    db_transaction(function () use ($uid, $new, $old, $me) {
                        foreach (array_diff($old, $new) as $rid) {
                            db_query('DELETE FROM user_roles WHERE user_id = ? AND role_id = ?', [$uid, $rid]);
                        }
                        foreach (array_diff($new, $old) as $rid) {
                            db_query('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)', [$uid, $rid, $me['id']]);
                        }
                    });
                    $slug = fn($ids) => array_values(array_map(fn($id) => $rolesById[$id]['slug'], $ids));
                    audit_log('roles_changed', 'user', $uid, ['added' => $slug(array_diff($new, $old)), 'removed' => $slug(array_diff($old, $new))], 'critical');
                    flash('success', 'Roles updated. The new access applies on their next page load.');
                }
                break;

            case 'status':
                $newStatus = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
                if ($newStatus === 'inactive' && $tSuper && sec_active_super_admins() <= 1) {
                    flash('error', 'This is the only active Super Admin, so it cannot be deactivated.');
                    break;
                }
                db_query("UPDATE users SET status = ? WHERE id = ? AND user_type = 'staff'", [$newStatus, $uid]);
                if ($newStatus === 'inactive') {
                    revoke_user_sessions($uid);
                }
                audit_log('staff_status_changed', 'user', $uid, ['status' => $newStatus], 'critical');
                flash('success', $newStatus === 'active' ? $t['name'] . ' can sign in again.' : $t['name'] . ' was deactivated and signed out everywhere.');
                break;

            case 'send_reset':
            case 'force_reset':
                if ($t['status'] !== 'active') {
                    flash('error', 'Reactivate the account first.');
                    break;
                }
                if (!rate_limit('staff-link', (string) $uid, 5, 3600)) {
                    flash('error', 'Too many password emails for this account in the last hour. Please wait.');
                    break;
                }
                if ($act === 'force_reset') {
                    db_query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $uid]);
                    revoke_user_sessions($uid);
                    audit_log('staff_password_force_reset', 'user', $uid, [], 'critical');
                }
                $kind = $act === 'force_reset' ? 'forced' : ($t['last_login_at'] ? 'reset' : 'invite');
                $names = array_map(fn($r) => $rolesById[(int) $r['role_id']]['name'] ?? '', db_all('SELECT role_id FROM user_roles WHERE user_id = ?', [$uid]));
                $res = sec_send_staff_link($t, $kind, $me, array_filter($names));
                sa_link_flash($res, match ($kind) {
                    'forced' => 'Old password disabled, ' . $t['name'] . ' was signed out, and a link to choose a new password was emailed to ' . $t['email'] . '.',
                    'invite' => 'Invitation re-sent to ' . $t['email'] . ' (link valid for 72 hours).',
                    default => 'Password reset link emailed to ' . $t['email'] . ' (valid for 24 hours).',
                }, $t['email']);
                break;

            case 'revoke_sessions':
                revoke_user_sessions($uid);
                audit_log('staff_sessions_revoked', 'user', $uid, [], 'warning');
                flash('success', $t['name'] . ' was signed out on every device.');
                break;

            case 'revoke_session':
                $n = db_query('UPDATE user_sessions SET revoked_at = NOW() WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [(int) ($_POST['session_id'] ?? 0), $uid])->rowCount();
                if ($n) {
                    audit_log('session_revoked', 'user_sessions', (int) $_POST['session_id'], ['user_id' => $uid], 'warning');
                }
                flash($n ? 'success' : 'info', $n ? 'That device was signed out.' : 'That session had already ended.');
                break;

            case 'reset_2fa':
                db_query('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, recovery_codes = NULL WHERE id = ?', [$uid]);
                revoke_user_sessions($uid);
                audit_log('2fa_reset_by_admin', 'user', $uid, [], 'warning');
                flash('success', 'Two-step verification was reset and ' . $t['name'] . ' was signed out. They set it up again at next sign-in' . (setting('admin_require_2fa', '0') === '1' ? ' (required for all staff).' : '.'));
                break;

            case 'faculty':
                $fid = (int) ($_POST['faculty_id'] ?? 0);
                if ($fid && !db_value('SELECT 1 FROM instructors WHERE id = ? AND (user_id IS NULL OR user_id = ?)', [$fid, $uid])) {
                    flash('error', 'That faculty profile is linked to another account.');
                    break;
                }
                db_transaction(function () use ($uid, $fid) {
                    db_query('UPDATE instructors SET user_id = NULL WHERE user_id = ?', [$uid]);
                    if ($fid) {
                        db_query('UPDATE instructors SET user_id = ? WHERE id = ?', [$uid, $fid]);
                    }
                });
                audit_log('staff_faculty_linked', 'user', $uid, ['instructor_id' => $fid ?: null]);
                flash('success', $fid ? 'Faculty profile linked.' : 'Faculty profile unlinked.');
                break;

            default:
                flash('error', 'Unknown action.');
        }
        redirect_to($back);
    }
}

// ------------------------------------------------------------------ shared bits
$roleBadges = function (array $ids) use ($rolesById): string {
    $h = '';
    foreach ($ids as $id) {
        $r = $rolesById[(int) $id] ?? null;
        if ($r) {
            $h .= '<span class="badge ' . ($r['slug'] === 'super_admin' ? 'b-purple' : 'b-blue') . '">' . e($r['name']) . '</span> ';
        }
    }
    return $h !== '' ? '<span class="sa-roles">' . $h . '</span>' : '<span class="badge b-amber">No role</span>';
};
$avatar = fn(array $u, string $cls = '') => '<span class="sa-avatar' . ($cls ? ' ' . $cls : '') . '" aria-hidden="true">' . e(mb_strtoupper(mb_substr((string) $u['name'], 0, 1))) . '</span>';

/** Role chooser: one card per role, disabled when the actor may not grant it. */
$roleCards = function (array $checkedIds, array $locked = []) use ($roles, $rolePerms, $assignable): string {
    $h = '<div class="sa-role-grid" role="group" aria-label="Roles">';
    foreach ($roles as $r) {
        $id = (int) $r['id'];
        $can = $assignable($r);
        $super = $r['slug'] === 'super_admin';
        $mods = sec_modules_for($rolePerms[$id] ?? [], $super);
        $h .= '<label class="sa-role' . ($can ? '' : ' is-locked') . '">'
            . '<input type="checkbox" name="roles[]" value="' . $id . '"' . (in_array($id, $checkedIds, true) ? ' checked' : '') . ($can ? '' : ' disabled') . '>'
            . '<span class="sa-role-body"><strong>' . e($r['name']) . ($super ? ' <span class="badge b-purple">Full access</span>' : '') . '</strong>'
            . '<small>' . e((string) $r['description']) . '</small>'
            . '<span class="sa-role-meta">' . ($super ? 'Every permission' : count($rolePerms[$id] ?? []) . ' permissions') . ' · ' . count($mods) . ' modules'
            . ($can ? '' : ' · <span class="sa-lock">' . icon('lock') . ($super ? ' Super Admin only' : ' Needs permissions you do not hold') . '</span>') . '</span></span></label>';
        // A disabled checkbox is not posted: keep roles the actor cannot touch.
        if (!$can && in_array($id, $checkedIds, true) && in_array($id, $locked, true)) {
            $h .= '<input type="hidden" name="roles[]" value="' . $id . '">';
        }
    }
    return $h . '</div>';
};

$faculty = db_all('SELECT id, name, designation, user_id, status FROM instructors ORDER BY name');
$links = array_filter([
    can('instructors.manage') ? ['instructors.php', 'cap', 'Faculty profiles', 'Public faculty pages shown on courses.'] : null,
    can('cms.manage') ? ['jobs.php?tab=openings', 'briefcase', 'Recruitment', 'Faculty & staff openings and applications.'] : null,
    ['roles.php', 'key', 'Roles & permissions', 'What each role can open and change.'],
    ['access.php', 'lock', 'Access policies', 'IP allowlist, working hours, required 2FA.'],
]);
$linkCards = function () use ($links): string {
    $h = '<div class="sa-links">';
    foreach ($links as [$href, $ico, $title, $sub]) {
        $h .= '<a class="sa-link" href="' . e($href) . '"><span class="ico tone-blue">' . icon($ico) . '</span><span><strong>' . e($title) . '</strong><small>' . e($sub) . '</small></span>' . icon('right', 'i chev') . '</a>';
    }
    return $h . '</div>';
};

// ------------------------------------------------------------------ new staff member
if ($view === 'new') {
    sa_start('Add Staff Member', ['head' => true, 'crumbs' => '<a href="' . SA_BASE . '">Staff accounts</a> / Add',
        'subtitle' => 'Give a faculty member or employee their own admin sign-in with only the access their role needs.']);
    $fe = fn(string $k) => isset($errors[$k]) ? '<div class="err" id="err-' . $k . '">' . icon('alert') . ' ' . e($errors[$k]) . '</div>' : '';
    $inv = fn(string $k) => isset($errors[$k]) ? ' invalid' : '';
    $aria = fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="err-' . $k . '"' : '';
    $freeFaculty = array_filter($faculty, fn($f) => $f['user_id'] === null);
    ?>
<form method="post" novalidate data-dirty-check class="split-2-1">
  <?= csrf_field() ?><input type="hidden" name="_action" value="create">
  <div class="stack-y">
    <section class="card">
      <div class="card-head"><div><h2><?= icon('user') ?> Account</h2><p class="muted">They sign in at <?= e(admin_url('login.php')) ?> with this email.</p></div></div>
      <div class="card-pad form">
        <div class="field<?= $inv('name') ?>"><label for="sa-name">Full name <span class="req">*</span></label><input id="sa-name" name="name" value="<?= e($v['name']) ?>" maxlength="120" required autocomplete="off"<?= $aria('name') ?>><?= $fe('name') ?></div>
        <div class="field<?= $inv('email') ?>"><label for="sa-email">Work email <span class="req">*</span></label><input id="sa-email" type="email" name="email" value="<?= e($v['email']) ?>" maxlength="190" required autocomplete="off"<?= $aria('email') ?>><?= $fe('email') ?></div>
        <div class="field<?= $inv('phone') ?>"><label for="sa-phone">Mobile number</label><input id="sa-phone" type="tel" name="phone" value="<?= e($v['phone']) ?>" maxlength="16" inputmode="tel" placeholder="Optional"<?= $aria('phone') ?>><?= $fe('phone') ?></div>
        <div class="field<?= $inv('faculty_id') ?>"><label for="sa-fac">Faculty profile</label>
          <select id="sa-fac" name="faculty_id"<?= $aria('faculty_id') ?>><option value="0">Not a faculty member / link later</option>
            <?php foreach ($freeFaculty as $f): ?><option value="<?= (int) $f['id'] ?>"<?= $v['faculty'] === (int) $f['id'] ? ' selected' : '' ?>><?= e($f['name']) ?><?= $f['designation'] ? ' - ' . e($f['designation']) : '' ?></option><?php endforeach; ?>
          </select>
          <div class="hint">Links this sign-in to a public faculty profile<?= can('instructors.manage') ? ' (<a href="instructors.php">manage profiles</a>)' : '' ?>.</div><?= $fe('faculty_id') ?></div>
      </div>
    </section>

    <section class="card<?= isset($errors['roles']) ? ' sa-invalid' : '' ?>" aria-labelledby="sa-roles-h">
      <div class="card-head"><div><h2 id="sa-roles-h"><?= icon('key') ?> Roles <span class="req">*</span></h2><p class="muted">Pick the smallest set that covers their job. Permissions of every role are combined.</p></div><a class="btn btn-light btn-sm" href="roles.php" target="_blank" rel="noopener"><?= icon('external') ?> Compare roles</a></div>
      <div class="card-pad"><?= $roleCards($v['roles']) ?><?= $fe('roles') ?></div>
    </section>

    <section class="card">
      <div class="card-head"><div><h2><?= icon('lock') ?> How will they get their password?</h2></div></div>
      <div class="card-pad">
        <div class="sa-choice" role="radiogroup" aria-label="Password">
          <label class="sa-opt"><input type="radio" name="method" value="invite"<?= $v['method'] === 'invite' ? ' checked' : '' ?>><span><strong><?= icon('mail') ?> Email an invitation (recommended)</strong><small>They get a single-use link (valid 72 hours) to choose their own password. You never see it.</small></span></label>
          <label class="sa-opt"><input type="radio" name="method" value="password"<?= $v['method'] === 'password' ? ' checked' : '' ?>><span><strong><?= icon('key') ?> Set a temporary password</strong><small>For staff without email access right now. Share it in person and ask them to change it.</small></span></label>
        </div>
        <div class="field<?= $inv('password') ?>" data-show-if="method=password" style="margin-top:14px;max-width:440px">
          <label for="sa-pw">Temporary password</label>
          <div class="sa-pw-row"><input id="sa-pw" type="password" name="password" autocomplete="new-password" minlength="10" maxlength="128"<?= $aria('password') ?>>
            <button type="button" class="btn btn-light" data-sa-gen="sa-pw"><?= icon('refresh') ?> Generate</button></div>
          <div class="hint">At least 10 characters with a letter and a number. Generated passwords are shown so you can copy them.</div><?= $fe('password') ?>
        </div>
        <?php if (!mail_configured()): ?><div class="alert alert-warning" data-show-if="method=invite" style="margin:14px 0 0"><?= icon('alert') ?><span>SMTP email is not configured on this server, so the invitation cannot be emailed. You will be shown the one-time link to pass on instead.</span></div><?php endif; ?>
      </div>
      <div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><a class="btn btn-light" href="<?= SA_BASE ?>">Cancel</a><button class="btn btn-primary"><?= icon('plus') ?> Create staff account</button></div>
    </section>
  </div>
  <aside class="stack-y">
    <div class="card card-pad sa-tips">
      <h2><?= icon('shield') ?> Good practice</h2>
      <ul>
        <li>One person, one account - never share a sign-in.</li>
        <li>Faculty usually need only <strong>Academic Manager</strong>. Create a custom role in <a href="roles.php">Roles</a> for narrower access.</li>
        <li>Keep Super Admins to 1-3 people.</li>
        <li>Ask everyone to turn on <a href="two-factor.php">two-step verification</a><?= setting('admin_require_2fa', '0') === '1' ? ' (required here)' : '' ?>.</li>
        <li>Deactivate accounts the day someone leaves - history is kept.</li>
      </ul>
    </div>
    <?= $linkCards() ?>
  </aside>
</form>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-sa-gen]');
  if (!b) { return; }
  var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789', out = '', a = new Uint32Array(14);
  crypto.getRandomValues(a);
  for (var i = 0; i < a.length; i++) { out += chars[a[i] % chars.length]; if (i === 4 || i === 9) { out += '-'; } }
  if (!/\d/.test(out)) { out += '7'; }
  var f = document.getElementById(b.dataset.saGen);
  f.type = 'text'; f.value = out; f.dispatchEvent(new Event('input', { bubbles: true })); f.select();
});
</script>
    <?php
    admin_end();
    exit;
}

// ------------------------------------------------------------------ one staff member
if ($view === 'detail') {
    $u = db_one("SELECT * FROM users WHERE id = ? AND user_type = 'staff'", [$sid]);
    if (!$u) {
        flash('error', 'Staff account not found.');
        redirect_to(SA_BASE);
    }
    $uid = (int) $u['id'];
    $mine = array_map('intval', array_column(db_all('SELECT role_id FROM user_roles WHERE user_id = ? ORDER BY role_id', [$uid]), 'role_id'));
    $uSuper = in_array('super_admin', user_role_slugs($uid), true);
    $block = sa_manage_block($u, $me, $rolesById, $rolePerms);
    $self = $uid === (int) $me['id'];
    $perms = $uSuper ? [] : array_values(array_unique(array_merge(...array_map(fn($id) => $rolePerms[$id] ?? [], $mine ?: [0]))));
    $mods = sec_modules_for($perms, $uSuper);
    $sessions = db_all('SELECT id, session_hash, ip_address, user_agent, created_at, last_seen_at FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL AND last_seen_at > NOW() - INTERVAL 7 DAY ORDER BY last_seen_at DESC LIMIT 20', [$uid]);
    $logins = db_all('SELECT ip_address, user_agent, success, created_at FROM login_attempts WHERE user_id = ? ORDER BY id DESC LIMIT 8', [$uid]);
    $activity = db_all('SELECT action, entity_type, entity_id, severity, created_at FROM audit_logs WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$uid]);
    $actions30 = (int) db_value('SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND created_at > NOW() - INTERVAL 30 DAY', [$uid]);
    $fail7 = (int) db_value('SELECT COUNT(*) FROM login_attempts WHERE user_id = ? AND success = 0 AND created_at > NOW() - INTERVAL 7 DAY', [$uid]);
    $linked = array_values(array_filter($faculty, fn($f) => (int) $f['user_id'] === $uid));
    $current = hash('sha256', session_id());
    $invited = $u['last_login_at'] === null;
    $h = fn(string $act, array $extra = []) => csrf_field() . '<input type="hidden" name="_action" value="' . $act . '"><input type="hidden" name="user_id" value="' . $uid . '">'
        . implode('', array_map(fn($k, $val) => '<input type="hidden" name="' . e($k) . '" value="' . e((string) $val) . '">', array_keys($extra), $extra));
    $btn = fn(string $act, string $label, string $cls, ?string $confirm = null, array $extra = []) => '<form method="post" class="inline-form">' . $h($act, $extra)
        . '<button class="' . $cls . '"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . $label . '</button></form>';

    sa_start($u['name'], ['head' => true, 'crumbs' => '<a href="' . SA_BASE . '">Staff accounts</a> / ' . e($u['name']),
        'subtitle' => $u['email'] . ' · staff since ' . fmt_dt($u['created_at'], 'j M Y'),
        'actions' => '<a class="btn btn-light" href="' . SA_BASE . '">' . icon('left') . ' All staff</a>'
            . (can('logs.view') ? '<a class="btn btn-light" href="logs.php?user=' . $uid . '">' . icon('log') . ' Activity log</a>' : '')]);
    if ($block) {
        echo '<div class="alert alert-info">' . icon('info') . '<span>' . e($block) . ($self ? ' <a href="profile.php">Open My profile</a>' : '') . '</span></div>';
    }
    ?>
<section class="card sa-hero">
  <?= $avatar($u, 'lg') ?>
  <div class="sa-hero-main">
    <h2><?= e($u['name']) ?><?= $self ? ' <span class="badge b-blue">You</span>' : '' ?></h2>
    <div class="sa-hero-badges">
      <?= badge($u['status']) ?>
      <?= $u['totp_enabled_at'] ? '<span class="badge b-green">' . icon('shield') . ' 2FA on</span>' : '<span class="badge b-amber">2FA off</span>' ?>
      <?= $invited ? '<span class="badge b-amber">Invited · never signed in</span>' : '' ?>
      <?= $roleBadges($mine) ?>
    </div>
  </div>
  <dl class="sa-hero-stats">
    <div><dt>Last sign-in</dt><dd><?= $invited ? 'Never' : e(fmt_ago($u['last_login_at'])) ?></dd></div>
    <div><dt>Open sessions</dt><dd><?= count($sessions) ?></dd></div>
    <div><dt>Actions (30 days)</dt><dd><?= number_format($actions30) ?></dd></div>
    <div><dt>Failed sign-ins (7 days)</dt><dd<?= $fail7 >= 5 ? ' class="sa-warn"' : '' ?>><?= $fail7 ?></dd></div>
  </dl>
</section>

<div class="split-1-14 mt">
  <div class="stack-y">
    <form method="post" class="card" novalidate data-dirty-check>
      <?= $h('profile') ?>
      <div class="card-head"><h2><?= icon('user') ?> Details</h2></div>
      <div class="card-pad form sa-one-col">
        <div class="field"><label for="d-name">Full name</label><input id="d-name" name="name" value="<?= e($u['name']) ?>" maxlength="120" required<?= $block ? ' disabled' : '' ?>></div>
        <div class="field"><label for="d-email">Sign-in email</label><input id="d-email" type="email" name="email" value="<?= e($u['email']) ?>" maxlength="190" required<?= $block ? ' disabled' : '' ?>></div>
        <div class="field"><label for="d-phone">Mobile number</label><input id="d-phone" type="tel" name="phone" value="<?= e((string) $u['phone']) ?>" maxlength="16"<?= $block ? ' disabled' : '' ?>></div>
        <dl class="kv sa-kv">
          <dt>Last sign-in</dt><dd><?= $invited ? 'Never' : e(fmt_dt($u['last_login_at'])) . '<br><span class="muted">' . e((string) $u['last_login_ip']) . '</span>' ?></dd>
          <dt>Account created</dt><dd><?= e(fmt_dt($u['created_at'])) ?></dd>
        </dl>
      </div>
      <?php if (!$block): ?><div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><button class="btn btn-primary"><?= icon('check') ?> Save details</button></div><?php endif; ?>
    </form>

    <section class="card">
      <div class="card-head"><div><h2><?= icon('lock') ?> Sign-in &amp; security</h2></div></div>
      <div class="sa-sec-list">
        <div class="sa-sec-row">
          <span class="ico <?= $u['totp_enabled_at'] ? 'tone-green' : 'tone-amber' ?>"><?= icon('shield') ?></span>
          <div><strong>Two-step verification</strong><small><?= $u['totp_enabled_at'] ? 'On since ' . e(fmt_dt($u['totp_enabled_at'], 'j M Y')) . '.' : 'Off - ' . ($self ? '<a href="two-factor.php">turn it on</a>.' : 'ask them to turn it on from their profile menu.') ?></small></div>
          <?= !$block && $u['totp_enabled_at'] ? $btn('reset_2fa', 'Reset', 'btn btn-light btn-sm', 'Reset two-step verification for ' . $u['name'] . '? Use this when they lost their phone and recovery codes. They will be signed out.') : '' ?>
        </div>
        <div class="sa-sec-row">
          <span class="ico tone-blue"><?= icon('mail') ?></span>
          <div><strong><?= $invited ? 'Invitation' : 'Password reset link' ?></strong><small><?= $invited ? 'They have not signed in yet. Re-send the set-password link (72 hours).' : 'Email a single-use link to choose a new password (24 hours). The current password keeps working.' ?></small></div>
          <?= !$block && $u['status'] === 'active' ? $btn('send_reset', $invited ? 'Re-send' : 'Send link', 'btn btn-light btn-sm', $invited ? null : 'Email a password reset link to ' . $u['email'] . '?') : '' ?>
        </div>
        <div class="sa-sec-row">
          <span class="ico tone-red"><?= icon('key') ?></span>
          <div><strong>Force password reset</strong><small>Disables the current password, signs them out everywhere and emails a link to choose a new one. Use it if a password may be known to someone else.</small></div>
          <?= !$block && $u['status'] === 'active' ? $btn('force_reset', 'Force reset', 'btn btn-danger btn-sm', 'Disable ' . $u['name'] . '’s current password and sign them out everywhere? They will get an email to choose a new one.') : '' ?>
        </div>
        <div class="sa-sec-row">
          <span class="ico <?= $u['status'] === 'active' ? 'tone-navy' : 'tone-green' ?>"><?= icon($u['status'] === 'active' ? 'logout' : 'unlock') ?></span>
          <div><strong><?= $u['status'] === 'active' ? 'Deactivate account' : 'Reactivate account' ?></strong><small><?= $u['status'] === 'active' ? 'Blocks sign-in immediately and ends every session. Their history, courses and logs are kept.' : 'This account is deactivated and cannot sign in.' ?></small></div>
          <?= !$block ? ($u['status'] === 'active'
              ? $btn('status', 'Deactivate', 'btn btn-danger btn-sm', 'Deactivate ' . $u['name'] . '? They are signed out immediately.', ['status' => 'inactive'])
              : $btn('status', 'Reactivate', 'btn btn-primary btn-sm', null, ['status' => 'active'])) : '' ?>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><div><h2><?= icon('cap') ?> Faculty profile</h2><p class="muted">Public profile on course pages.</p></div><?= can('instructors.manage') ? '<a class="btn btn-light btn-sm" href="instructors.php">' . icon('external') . ' Faculty</a>' : '' ?></div>
      <div class="card-pad">
        <?php if ($linked): ?>
          <p style="margin:0 0 12px">Linked to <strong><?= e($linked[0]['name']) ?></strong><?= $linked[0]['designation'] ? ' · ' . e($linked[0]['designation']) : '' ?> <?= badge($linked[0]['status']) ?></p>
        <?php else: ?>
          <p class="muted" style="margin:0 0 12px">Not linked to a faculty profile.</p>
        <?php endif; ?>
        <?php $options = array_filter($faculty, fn($f) => $f['user_id'] === null || (int) $f['user_id'] === $uid); ?>
        <?php if (!$block && $options): ?>
        <form method="post" class="sa-inline"><?= $h('faculty') ?>
          <label class="sr-only" for="d-fac">Faculty profile</label>
          <select id="d-fac" class="in" name="faculty_id"><option value="0">Not linked</option>
            <?php foreach ($options as $f): ?><option value="<?= (int) $f['id'] ?>"<?= (int) $f['user_id'] === $uid ? ' selected' : '' ?>><?= e($f['name']) ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-light">Save</button>
        </form>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <div class="stack-y">
    <form method="post" class="card" novalidate data-dirty-check>
      <?= $h('roles') ?>
      <div class="card-head"><div><h2><?= icon('key') ?> Roles</h2><p class="muted">Access is checked on the server on every page and action.</p></div><a class="btn btn-light btn-sm" href="roles.php"><?= icon('grid') ?> Roles &amp; permissions</a></div>
      <div class="card-pad">
        <?php if ($block): ?>
          <?= $roleBadges($mine) ?>
        <?php else: ?>
          <?= $roleCards($mine, $mine) ?>
        <?php endif; ?>
        <div class="sa-mods">
          <span class="lbl"><?= icon('eye') ?> What they can open now</span>
          <div class="sa-mod-chips">
            <?php foreach ($mods as [$label, $ico, $n, $all]): ?><span class="sa-chip" title="<?= $n ?> of <?= $all ?> screens"><?= icon($ico) ?> <?= e($label) ?> <small><?= $n ?>/<?= $all ?></small></span><?php endforeach; ?>
          </div>
          <?php if (!$mine): ?><p class="muted" style="margin:6px 0 0">No role yet - they can only open their own profile and two-step verification.</p><?php endif; ?>
        </div>
      </div>
      <?php if (!$block): ?><div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><button class="btn btn-primary" data-confirm="Change <?= e($u['name']) ?>’s roles? The new access applies immediately."><?= icon('check') ?> Save roles</button></div><?php endif; ?>
    </form>

    <section class="card">
      <div class="card-head"><div><h2><?= icon('monitor') ?> Devices &amp; sessions</h2><p class="muted">Signed in and seen in the last 7 days.</p></div>
        <?= !$block && $sessions ? $btn('revoke_sessions', icon('logout') . ' Sign out everywhere', 'btn btn-light btn-sm', 'Sign ' . $u['name'] . ' out on every device?') : '' ?></div>
      <?php if (!$sessions): ?>
        <div class="empty"><span class="empty-ic"><?= icon('monitor') ?></span><strong>No open sessions</strong><p>They are not signed in anywhere right now.</p></div>
      <?php else: ?>
      <div class="table-wrap"><table class="t stack"><thead><tr><th>Device</th><th>IP address</th><th>Signed in</th><th>Last seen</th><th></th></tr></thead><tbody>
        <?php foreach ($sessions as $s): $here = hash_equals($s['session_hash'], $current); ?>
        <tr>
          <td data-label="Device"><span class="sa-dev"><?= icon(sec_device_icon($s['user_agent'])) ?> <?= e(sec_device_label($s['user_agent'])) ?></span><?= $here ? ' <span class="badge b-blue">This session</span>' : '' ?></td>
          <td data-label="IP"><code><?= e((string) $s['ip_address']) ?></code></td>
          <td data-label="Signed in"><?= e(fmt_dt($s['created_at'], 'j M, g:i A')) ?></td>
          <td data-label="Last seen"><?= e(fmt_ago($s['last_seen_at'])) ?></td>
          <td class="actions" data-label=""><?= !$block && !$here ? $btn('revoke_session', 'Sign out', 'btn btn-light btn-sm', 'Sign out this device?', ['session_id' => $s['id']]) : '' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>

    <div class="grid g-2 sa-activity">
      <section class="card">
        <div class="card-head"><h2><?= icon('history') ?> Recent sign-ins</h2><?= can('logs.view') ? '<a href="security.php?tab=logins&amp;q=' . urlencode($u['email']) . '">All</a>' : '' ?></div>
        <?php if (!$logins): ?><div class="empty"><p>No sign-in attempts yet.</p></div><?php else: ?>
        <ul class="sa-feed">
          <?php foreach ($logins as $l): ?><li><span class="sa-dot <?= $l['success'] ? 'ok' : 'bad' ?>" aria-hidden="true"></span><span><strong><?= $l['success'] ? 'Signed in' : 'Failed sign-in' ?></strong><small><?= e(fmt_dt($l['created_at'], 'j M, g:i A')) ?> · <?= e(sec_device_label($l['user_agent'])) ?> · <?= e($l['ip_address']) ?></small></span></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </section>
      <section class="card">
        <div class="card-head"><h2><?= icon('log') ?> Recent actions</h2><?= can('logs.view') ? '<a href="logs.php?user=' . $uid . '">All</a>' : '' ?></div>
        <?php if (!$activity): ?><div class="empty"><p>No admin actions recorded.</p></div><?php else: ?>
        <ul class="sa-feed">
          <?php foreach ($activity as $a): ?><li><span class="sa-dot <?= $a['severity'] === 'critical' ? 'bad' : ($a['severity'] === 'warning' ? 'warn' : 'ok') ?>" aria-hidden="true"></span><span><strong><?= e(ucfirst(str_replace('_', ' ', $a['action']))) ?></strong><small><?= e(fmt_dt($a['created_at'], 'j M, g:i A')) ?><?= $a['entity_type'] ? ' · ' . e($a['entity_type'] . ($a['entity_id'] !== null ? ' #' . $a['entity_id'] : '')) : '' ?></small></span></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </section>
    </div>
  </div>
</div>
    <?php
    admin_end();
    exit;
}

// ------------------------------------------------------------------ list
$q = trim((string) ($_GET['q'] ?? ''));
$roleF = (string) ($_GET['role'] ?? '');
$statusF = (string) ($_GET['state'] ?? ''); // not "status": users.php?status=… belongs to the Students tab bar
$tfaF = (string) ($_GET['tfa'] ?? '');
$where = ["u.user_type = 'staff'"];
$params = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($roleF !== '' && in_array($roleF, array_column($roles, 'slug'), true)) {
    $where[] = 'EXISTS (SELECT 1 FROM user_roles x JOIN roles xr ON xr.id = x.role_id WHERE x.user_id = u.id AND xr.slug = ?)';
    $params[] = $roleF;
}
if (in_array($statusF, ['active', 'inactive'], true)) {
    $where[] = 'u.status = ?';
    $params[] = $statusF;
}
if ($tfaF === 'on' || $tfaF === 'off') {
    $where[] = $tfaF === 'on' ? 'u.totp_enabled_at IS NOT NULL' : 'u.totp_enabled_at IS NULL';
}
$w = implode(' AND ', $where);
$listSql = "SELECT u.id, u.name, u.email, u.phone, u.status, u.totp_enabled_at, u.last_login_at, u.created_at,
        (SELECT GROUP_CONCAT(ur.role_id ORDER BY ur.role_id) FROM user_roles ur WHERE ur.user_id = u.id) AS role_ids,
        (SELECT COUNT(*) FROM user_sessions s WHERE s.user_id = u.id AND s.revoked_at IS NULL AND s.last_seen_at > NOW() - INTERVAL 1 DAY) AS open_sessions,
        (SELECT i.name FROM instructors i WHERE i.user_id = u.id LIMIT 1) AS faculty_name
    FROM users u WHERE {$w} ORDER BY u.status = 'active' DESC, u.name";

if (($_GET['export'] ?? '') === 'csv') {
    require_permission('reports.export');
    csv_download('staff-accounts-' . date('Ymd') . '.csv', ['ID', 'Name', 'Email', 'Phone', 'Roles', 'Status', 'Two-step', 'Last sign-in', 'Created'],
        array_map(fn($r) => [$r['id'], $r['name'], $r['email'], $r['phone'], implode(', ', array_map(fn($id) => $rolesById[(int) $id]['name'] ?? '', array_filter(explode(',', (string) $r['role_ids'])))),
            $r['status'], $r['totp_enabled_at'] ? 'On' : 'Off', $r['last_login_at'] ?? 'Never', $r['created_at']], db_all($listSql, $params)));
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$total = (int) db_value("SELECT COUNT(*) FROM users u WHERE {$w}", $params);
$rows = db_all($listSql . ' LIMIT 50 OFFSET ' . (($page - 1) * 50), $params);
$k = db_one("SELECT SUM(status = 'active') AS active, SUM(status <> 'active') AS inactive,
        SUM(status = 'active' AND totp_enabled_at IS NOT NULL) AS tfa, SUM(status = 'active' AND last_login_at > NOW() - INTERVAL 7 DAY) AS recent,
        SUM(status = 'active' AND last_login_at IS NULL) AS invited
    FROM users WHERE user_type = 'staff'");
$supers = sec_active_super_admins();
$filtered = $q !== '' || $roleF !== '' || $statusF !== '' || $tfaF !== '';

sa_start('Staff Accounts', ['head' => true,
    'subtitle' => 'Faculty and employees who can sign in to this admin panel, and what each of them can open.',
    'actions' => (can('reports.export') ? '<a class="btn btn-light" href="' . e(url_with(['export' => 'csv'])) . '">' . icon('download') . ' Export CSV</a>' : '')
        . '<a class="btn btn-light" href="roles.php">' . icon('key') . ' Roles</a>'
        . '<a class="btn btn-primary" href="' . SA_BASE . '&amp;add=1">' . icon('plus') . ' Add staff member</a>']);
?>
<section class="grid g-4 sa-kpis" aria-label="Staff summary">
  <div class="card stat"><span class="ico tone-blue"><?= icon('users') ?></span><span><span class="label">Active staff</span><span class="value" style="display:block"><?= (int) $k['active'] ?></span><span class="sub"><?= (int) $k['inactive'] ?> deactivated<?= (int) $k['invited'] ? ' · ' . (int) $k['invited'] . ' never signed in' : '' ?></span></span></div>
  <div class="card stat"><span class="ico tone-purple"><?= icon('shield') ?></span><span><span class="label">Super Admins</span><span class="value" style="display:block"><?= $supers ?></span><span class="sub"><?= $supers > 3 ? 'Consider fewer (1-3)' : 'Full access' ?></span></span></div>
  <div class="card stat"><span class="ico <?= (int) $k['tfa'] === (int) $k['active'] ? 'tone-green' : 'tone-amber' ?>"><?= icon('lock') ?></span><span><span class="label">Two-step verification</span><span class="value" style="display:block"><?= (int) $k['tfa'] ?> / <?= (int) $k['active'] ?></span><span class="sub"><?= setting('admin_require_2fa', '0') === '1' ? 'Required for all staff' : '<a href="access.php#2fa">Require it for everyone</a>' ?></span></span></div>
  <div class="card stat"><span class="ico tone-teal"><?= icon('activity') ?></span><span><span class="label">Signed in this week</span><span class="value" style="display:block"><?= (int) $k['recent'] ?></span><span class="sub">of <?= (int) $k['active'] ?> active accounts</span></span></div>
</section>

<div class="mt sa-list-layout">
  <section class="card">
    <form class="toolbar" method="get" role="search" aria-label="Filter staff">
      <input type="hidden" name="type" value="staff">
      <input class="in search-in" type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email or phone…" aria-label="Search staff">
      <select class="in" style="width:auto" name="role" aria-label="Role" data-autosubmit><option value="">All roles</option>
        <?php foreach ($roles as $r): ?><option value="<?= e($r['slug']) ?>"<?= $roleF === $r['slug'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
      </select>
      <select class="in" style="width:auto" name="state" aria-label="Status" data-autosubmit>
        <option value="">Any status</option><option value="active"<?= $statusF === 'active' ? ' selected' : '' ?>>Active</option><option value="inactive"<?= $statusF === 'inactive' ? ' selected' : '' ?>>Deactivated</option>
      </select>
      <select class="in" style="width:auto" name="tfa" aria-label="Two-step verification" data-autosubmit>
        <option value="">2FA: any</option><option value="on"<?= $tfaF === 'on' ? ' selected' : '' ?>>2FA on</option><option value="off"<?= $tfaF === 'off' ? ' selected' : '' ?>>2FA off</option>
      </select>
      <button class="btn btn-light"><?= icon('search') ?> Filter</button>
      <?php if ($filtered): ?><a class="btn btn-ghost" href="<?= SA_BASE ?>">Clear</a><?php endif; ?>
    </form>
    <?php if (!$rows): ?>
      <div class="empty"><span class="empty-ic"><?= icon('users') ?></span><strong><?= $filtered ? 'No staff match these filters' : 'No staff accounts yet' ?></strong><p><?= $filtered ? 'Try a different search or clear the filters.' : 'Add your first faculty member or employee.' ?></p>
        <?= $filtered ? '<a class="btn btn-light" href="' . SA_BASE . '">Clear filters</a>' : '<a class="btn btn-primary" href="' . SA_BASE . '&amp;add=1">' . icon('plus') . ' Add staff member</a>' ?></div>
    <?php else: ?>
    <div class="table-wrap"><table class="t stack sa-table">
      <thead><tr><th>Staff member</th><th>Roles</th><th>2FA</th><th>Last sign-in</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $ids = array_filter(explode(',', (string) $r['role_ids'])); ?>
        <tr>
          <td data-label=""><a class="sa-person" href="<?= SA_BASE ?>&amp;id=<?= (int) $r['id'] ?>"><?= $avatar($r) ?><span class="sa-person-t"><strong><?= e($r['name']) ?><?= (int) $r['id'] === (int) $me['id'] ? ' <span class="badge b-blue">You</span>' : '' ?></strong><small><?= e($r['email']) ?><?= $r['faculty_name'] ? ' · ' . icon('cap') . ' ' . e($r['faculty_name']) : '' ?></small></span></a></td>
          <td data-label="Roles"><?= $roleBadges($ids) ?></td>
          <td data-label="2FA"><?= $r['totp_enabled_at'] ? '<span class="badge b-green">On</span>' : '<span class="badge b-gray">Off</span>' ?></td>
          <td data-label="Last sign-in" class="sa-when"><?= $r['last_login_at'] ? e(fmt_ago($r['last_login_at'])) . '<small>' . e(fmt_dt($r['last_login_at'], 'j M Y, g:i A')) . '</small>' : '<span class="badge b-amber">Invited</span>' ?><?= (int) $r['open_sessions'] ? '<small>' . (int) $r['open_sessions'] . ' open session' . ((int) $r['open_sessions'] === 1 ? '' : 's') . '</small>' : '' ?></td>
          <td data-label="Status"><?= $r['status'] === 'active' ? badge('active') : '<span class="badge b-red">Deactivated</span>' ?></td>
          <td class="actions" data-label=""><a class="btn btn-light btn-sm" href="<?= SA_BASE ?>&amp;id=<?= (int) $r['id'] ?>"><?= icon((int) $r['id'] === (int) $me['id'] ? 'eye' : 'edit') ?> <?= (int) $r['id'] === (int) $me['id'] ? 'View' : 'Manage' ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php render_pager($total, $page, 50); ?>
    <?php endif; ?>
  </section>
  <aside class="split-1-14 mt">
    <section class="card">
      <div class="card-head"><h2><?= icon('key') ?> Staff by role</h2><a href="roles.php">Edit roles</a></div>
      <ul class="sa-rolecount">
        <?php foreach (db_all("SELECT r.slug, r.name, (SELECT COUNT(*) FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE ur.role_id = r.id AND u.status = 'active' AND u.user_type = 'staff') AS n FROM roles r ORDER BY r.id") as $rc): ?>
          <li><a href="<?= SA_BASE ?>&amp;role=<?= e($rc['slug']) ?>"><span><?= e($rc['name']) ?></span><b><?= (int) $rc['n'] ?></b></a></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?= $linkCards() ?>
  </aside>
</div>
<?php admin_end();
