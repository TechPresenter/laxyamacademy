<?php
// Admin access policies: IP allowlist, working hours, mandatory two-factor, staff 2FA status.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/admin-access.php';

$me = require_staff_page();
require_permission('roles.manage');

const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$errors = [];

if (admin_post()) {
    $act = $_POST['_action'] ?? 'save';
    if ($act === 'reset_2fa') {
        $uid = (int) ($_POST['id'] ?? 0);
        if ($uid === (int) $me['id']) {
            flash('error', 'Manage your own two-step verification from your profile.');
        } elseif (db_value("SELECT 1 FROM users WHERE id = ? AND user_type = 'staff'", [$uid])) {
            db_query('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, recovery_codes = NULL WHERE id = ?', [$uid]);
            revoke_user_sessions($uid);
            audit_log('2fa_reset_by_admin', 'user', $uid, [], 'warning');
            flash('success', 'Two-step verification reset. They must set it up again at next sign-in' . (setting('admin_require_2fa') === '1' ? ' (required).' : '.'));
        }
        redirect_to('access.php');
    }

    $rules = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($_POST['admin_ip_allowlist'] ?? '')))));
    foreach ($rules as $r) {
        if (!valid_ip_rule($r)) {
            $errors['admin_ip_allowlist'] = "“{$r}” is not a valid IP address or CIDR range (e.g. 203.0.113.10 or 203.0.113.0/24).";
            break;
        }
    }
    if (!$errors && $rules && !array_filter($rules, fn($r) => ip_in_range(client_ip(), $r))) {
        $errors['admin_ip_allowlist'] = 'Your current IP (' . client_ip() . ') is not in the list - saving would lock you out. Add it first.';
    }
    $hours = '';
    if (!empty($_POST['hours_on'])) {
        $days = array_values(array_intersect(array_map('intval', (array) ($_POST['days'] ?? [])), array_keys(DAY_NAMES)));
        $start = (string) ($_POST['start'] ?? '');
        $end = (string) ($_POST['end'] ?? '');
        if (!$days || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $start >= $end) {
            $errors['hours'] = 'Choose at least one day and a start time earlier than the end time.';
        }
        $hours = json_encode(['days' => $days, 'start' => $start, 'end' => $end]);
    }
    $require = empty($_POST['admin_require_2fa']) ? '0' : '1';
    if ($require === '1' && !db_value('SELECT totp_enabled_at FROM users WHERE id = ?', [$me['id']])) {
        $errors['admin_require_2fa'] = 'Turn on two-step verification for your own account first.';
    }
    if (!$errors) {
        foreach (['admin_ip_allowlist' => implode("\n", $rules), 'admin_access_hours' => $hours, 'admin_require_2fa' => $require] as $k => $v) {
            db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public, updated_by) VALUES (?, ?, 'security', 0, ?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)", [$k, $v, $me['id']]);
        }
        audit_log('access_policy_updated', 'site_settings', null, ['ip_rules' => count($rules), 'hours' => $hours !== '', 'require_2fa' => $require], 'critical');
        flash('success', 'Access policies saved.');
        redirect_to('access.php');
    }
    flash('error', 'Please correct the highlighted fields.');
}

$hours = admin_hours();
$hoursOn = isset($_POST['hours_on']) ? !empty($_POST['hours_on']) : (bool) $hours;
$staff = db_all("SELECT u.id, u.name, u.email, u.totp_enabled_at, u.last_login_at, GROUP_CONCAT(r.name SEPARATOR ', ') AS roles
    FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
    WHERE u.user_type = 'staff' AND u.status = 'active' GROUP BY u.id ORDER BY u.name");
$off = (string) config('ADMIN_ACCESS_POLICY_OFF', '0') === '1';
admin_start('Access Policies', null, 'rbac', ['head' => true, 'subtitle' => 'Who can open the admin panel, from where and when. Changes apply on the next page load.']);
if ($off) {
    echo '<div class="alert alert-warning">ADMIN_ACCESS_POLICY_OFF=1 is set in .env, so the IP allowlist and hours are <strong>not enforced</strong>. Remove it once access is restored.</div>';
}
$fe = fn($k) => isset($errors[$k]) ? '<div class="err">' . e($errors[$k]) . '</div>' : '';
?>
<form method="post" novalidate data-dirty-check>
  <?= csrf_field() ?>
  <div class="card" style="margin-bottom:18px" id="ip">
    <div class="card-head"><h2><?= icon('globe') ?> IP allowlist</h2><?= admin_ip_rules() ? '<span class="badge b-green">' . plural_n(count(admin_ip_rules()), 'rule') . '</span>' : '<span class="badge b-gray">Any network</span>' ?></div>
    <div class="card-pad form">
      <div class="field full<?= isset($errors['admin_ip_allowlist']) ? ' invalid' : '' ?>">
        <label for="ips">Allowed IP addresses or ranges (one per line). Leave empty to allow any network.</label>
        <textarea id="ips" name="admin_ip_allowlist" rows="4" style="font-family:monospace" placeholder="203.0.113.10&#10;198.51.100.0/24"><?= e((string) ($_POST['admin_ip_allowlist'] ?? setting('admin_ip_allowlist', ''))) ?></textarea>
        <?= $fe('admin_ip_allowlist') ?>
        <div class="hint">Your current IP: <code><?= e(client_ip()) ?></code> <button type="button" class="btn btn-light btn-sm" onclick="var t=document.getElementById('ips');t.value=(t.value.trim()?t.value.trim()+'\n':'')+'<?= e(client_ip()) ?>';t.dispatchEvent(new Event('input',{bubbles:true}))">Add my IP</button> · Applies to all staff, including Super Admins. Locked out? Set <code>ADMIN_ACCESS_POLICY_OFF=1</code> in .env.</div>
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:18px" id="hours">
    <div class="card-head"><h2><?= icon('clock') ?> Access hours</h2><?= $hours ? '<span class="badge b-green">On</span>' : '<span class="badge b-gray">Any time</span>' ?></div>
    <div class="card-pad form">
      <div class="field full"><label class="check"><input type="checkbox" name="hours_on" value="1"<?= $hoursOn ? ' checked' : '' ?>> Allow staff to use the admin panel only during these hours (Super Admins are always allowed)</label></div>
      <div class="field full<?= isset($errors['hours']) ? ' invalid' : '' ?>">
        <label>Days</label>
        <div style="display:flex;gap:14px;flex-wrap:wrap">
          <?php $sel = array_map('intval', $_POST['days'] ?? ($hours['days'] ?? [1, 2, 3, 4, 5, 6])); foreach (DAY_NAMES as $n => $d): ?>
            <label class="check"><input type="checkbox" name="days[]" value="<?= $n ?>"<?= in_array($n, $sel, true) ? ' checked' : '' ?>> <?= $d ?></label>
          <?php endforeach; ?>
        </div>
        <?= $fe('hours') ?>
      </div>
      <div class="field"><label for="hs">From</label><input id="hs" type="time" name="start" value="<?= e((string) ($_POST['start'] ?? $hours['start'] ?? '09:00')) ?>"></div>
      <div class="field"><label for="he">Until</label><input id="he" type="time" name="end" value="<?= e((string) ($_POST['end'] ?? $hours['end'] ?? '20:00')) ?>"></div>
      <div class="hint full">Server time zone: <?= e(date_default_timezone_get()) ?> (now <?= e(date('D H:i')) ?>).</div>
    </div>
  </div>

  <div class="card" style="margin-bottom:18px" id="2fa">
    <div class="card-head"><h2><?= icon('shield') ?> Two-step verification</h2></div>
    <div class="card-pad form">
      <div class="field full<?= isset($errors['admin_require_2fa']) ? ' invalid' : '' ?>">
        <label class="check"><input type="checkbox" name="admin_require_2fa" value="1"<?= ($_POST['admin_require_2fa'] ?? setting('admin_require_2fa', '0')) === '1' ? ' checked' : '' ?>> Require every staff member to use an authenticator app code at sign-in</label>
        <?= $fe('admin_require_2fa') ?>
        <div class="hint">Staff without it are sent to the setup page until they turn it on. <a href="two-factor.php">Set up your own</a>.</div>
      </div>
    </div>
    <div class="form-actions"><button class="btn btn-primary"><?= icon('check') ?> Save policies</button></div>
  </div>
</form>

<div class="card">
  <div class="card-head"><h2><?= icon('users') ?> Staff two-step status</h2><a href="users.php?type=staff">Staff accounts</a></div>
  <div class="table-wrap"><table class="t stack"><thead><tr><th>Staff</th><th>Roles</th><th>Two-step</th><th>Last sign-in</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>
  <?php foreach ($staff as $s): ?>
    <tr>
      <td data-label="Staff"><strong><?= e($s['name']) ?></strong><br><span class="muted"><?= e($s['email']) ?></span></td>
      <td data-label="Roles"><?= e((string) $s['roles']) ?></td>
      <td data-label="Two-step"><?= $s['totp_enabled_at'] ? '<span class="badge b-green">On</span>' : '<span class="badge b-gray">Off</span>' ?></td>
      <td data-label="Last sign-in"><?= $s['last_login_at'] ? e(fmt_dt($s['last_login_at'])) : '<span class="muted">Never</span>' ?></td>
      <td class="actions"><?php if ($s['totp_enabled_at'] && (int) $s['id'] !== (int) $me['id']): ?><?= action_form('reset_2fa', ['id' => $s['id']], 'Reset 2FA', 'btn btn-light btn-sm', 'Reset two-step verification for ' . $s['name'] . '? Use this when they lost their phone and recovery codes. Their sessions will be signed out.') ?><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php admin_end();
