<?php
// Two-factor authentication (TOTP) for the signed-in staff member.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/admin-access.php';

$me = require_staff_page();
$row = db_one('SELECT password_hash, totp_secret, totp_enabled_at, recovery_codes FROM users WHERE id = ?', [$me['id']]);
$enabled = (bool) $row['totp_enabled_at'];
$codes = null;

if (admin_post()) {
    $act = $_POST['_action'] ?? '';
    $code = (string) ($_POST['code'] ?? '');
    if ($act === 'enable' && !$enabled) {
        $secret = (string) ($_SESSION['totp_setup'] ?? '');
        if ($secret === '' || !totp_verify($secret, $code, (int) $me['id'])) {
            flash('error', 'That code did not match. Make sure your phone time is set automatically and try the newest code.');
            redirect_to('two-factor.php');
        }
        [$codes, $hashes] = make_recovery_codes();
        db_query('UPDATE users SET totp_secret = ?, totp_enabled_at = NOW(), recovery_codes = ? WHERE id = ?', [$secret, json_encode($hashes), $me['id']]);
        unset($_SESSION['totp_setup']);
        revoke_user_sessions((int) $me['id'], true);
        audit_log('2fa_enabled', 'user', $me['id'], [], 'warning');
        $enabled = true;
        // fall through: show recovery codes once
    } elseif ($act === 'disable' && $enabled) {
        if (setting('admin_require_2fa', '0') === '1') {
            flash('error', 'Two-step verification is required for all staff (Access Policies). It cannot be turned off.');
        } elseif (!password_verify((string) ($_POST['password'] ?? ''), $row['password_hash']) || !totp_verify((string) $row['totp_secret'], $code, (int) $me['id'])) {
            flash('error', 'Password or code is not correct.');
        } else {
            db_query('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, recovery_codes = NULL WHERE id = ?', [$me['id']]);
            audit_log('2fa_disabled', 'user', $me['id'], [], 'warning');
            flash('success', 'Two-step verification turned off.');
        }
        redirect_to('two-factor.php');
    } elseif ($act === 'regenerate' && $enabled) {
        if (!totp_verify((string) $row['totp_secret'], $code, (int) $me['id'])) {
            flash('error', 'That code did not match.');
            redirect_to('two-factor.php');
        }
        [$codes, $hashes] = make_recovery_codes();
        db_query('UPDATE users SET recovery_codes = ? WHERE id = ?', [json_encode($hashes), $me['id']]);
        audit_log('2fa_recovery_regenerated', 'user', $me['id'], [], 'warning');
    }
}

if (!$enabled && empty($_SESSION['totp_setup'])) {
    $_SESSION['totp_setup'] = totp_new_secret();
}
$left = count(json_decode_array(db_value('SELECT recovery_codes FROM users WHERE id = ?', [$me['id']])));
admin_start('Two-Factor Authentication', null, 'security', ['head' => true, 'subtitle' => 'Protect your admin account with a code from your phone.']);
if (isset($_GET['required']) && !$enabled) {
    echo '<div class="alert alert-warning">Your academy requires two-step verification for all staff. Set it up to continue using the admin panel.</div>';
}
?>
<?php if ($codes): ?>
<div class="card" style="margin-bottom:18px;border-color:#fde68a">
  <div class="card-head"><h2>Save your recovery codes</h2></div>
  <div class="card-pad">
    <p>Each code works once if you lose your phone. <strong>They will not be shown again.</strong> Store them in a password manager or print them.</p>
    <pre id="rc" style="font-size:16px;line-height:1.8;background:var(--surface,#f5f9ff);padding:14px;border-radius:10px;columns:2"><?= e(implode("\n", $codes)) ?></pre>
    <button type="button" class="btn btn-light" data-copy="<?= e(implode("\n", $codes)) ?>"><?= icon('copy') ?> Copy codes</button>
    <button type="button" class="btn btn-light" onclick="print()"><?= icon('download') ?> Print</button>
  </div>
</div>
<?php endif; ?>

<?php
$staffActive = (int) db_value("SELECT COUNT(*) FROM users WHERE user_type = 'staff' AND status = 'active'");
$staffOn = (int) db_value("SELECT COUNT(*) FROM users WHERE user_type = 'staff' AND status = 'active' AND totp_enabled_at IS NOT NULL");
?>
<div class="split-13-1 tf-layout">
<div class="stack-y">
<?php if (!$enabled): $secret = (string) $_SESSION['totp_setup']; ?>
<div class="card">
  <div class="card-head"><h2><?= icon('shield') ?> Set up two-step verification</h2><span class="badge b-gray">Off</span></div>
  <div class="card-pad">
    <ol style="line-height:1.9;padding-left:20px;margin:0 0 16px">
      <li>Install an authenticator app (Google Authenticator, Microsoft Authenticator or Authy).</li>
      <li>In the app tap <strong>+ → Scan a QR code</strong> and point your phone at the code below. Can’t scan? Choose <strong>Enter a setup key</strong> and type the key instead. On this phone you can also <a href="<?= e(totp_uri($secret, $me['email'])) ?>">open it in the app directly</a>.</li>
      <li>Enter the 6-digit code the app shows.</li>
    </ol>
    <div class="tf-qr" data-qr="<?= e(totp_uri($secret, $me['email'])) ?>" data-qr-title="Scan with your authenticator app" hidden></div>
    <div class="field" style="max-width:560px">
      <label for="tf-key">Setup key (if you can’t scan)</label>
      <div style="display:flex;gap:8px"><input id="tf-key" class="tf-key" readonly value="<?= e(trim(chunk_split($secret, 4, ' '))) ?>"><button type="button" class="btn btn-light" data-copy="<?= e($secret) ?>" aria-label="Copy setup key"><?= icon('copy') ?> Copy</button></div>
      <div class="hint">Account: <?= e($me['email']) ?></div>
    </div>
    <form method="post" class="form" style="max-width:420px;margin-top:14px">
      <?= csrf_field() ?><input type="hidden" name="_action" value="enable">
      <div class="field full"><label for="c1">6-digit code</label><input id="c1" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></div>
      <div class="full"><button class="btn btn-primary"><?= icon('lock') ?> Turn on</button></div>
    </form>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="card-head"><h2><?= icon('shield') ?> Two-step verification</h2><?= badge('active') ?></div>
  <div class="card-pad">
    <p style="margin-top:0">On since <?= e(fmt_dt((string) db_value('SELECT totp_enabled_at FROM users WHERE id = ?', [$me['id']]))) ?>. You will be asked for a code after your password. Recovery codes left: <strong><?= $left ?></strong><?= $left <= 3 ? ' <span class="badge b-amber">running low</span>' : '' ?></p>
    <form method="post" class="form" style="max-width:520px">
      <?= csrf_field() ?><input type="hidden" name="_action" value="regenerate">
      <div class="field"><label for="c2">Current code</label><input id="c2" name="code" inputmode="numeric" maxlength="6" required></div>
      <div class="field" style="align-self:end"><button class="btn btn-light"><?= icon('history') ?> New recovery codes</button></div>
    </form>
  </div>
</div>
<?php if (setting('admin_require_2fa', '0') !== '1'): ?>
<div class="card">
  <div class="card-head"><h2>Turn off</h2></div>
  <form method="post" class="card-pad form" style="max-width:620px">
    <?= csrf_field() ?><input type="hidden" name="_action" value="disable">
    <div class="field"><label for="pw">Password</label><input id="pw" type="password" name="password" autocomplete="current-password" required></div>
    <div class="field"><label for="c3">Current code</label><input id="c3" name="code" inputmode="numeric" maxlength="6" required></div>
    <div class="full"><button class="btn btn-danger" data-confirm="Turn off two-step verification for your account?">Turn off</button></div>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
<aside class="stack-y">
  <section class="card card-pad sa-tips">
    <h2><?= icon('lock') ?> Why turn it on?</h2>
    <ul>
      <li>A stolen or guessed password alone can no longer open your admin account.</li>
      <li>Codes change every 30 seconds and each one works once.</li>
      <li>Keep your recovery codes somewhere safe - each opens the account once if you lose your phone.</li>
      <li>Lost both? A Super Admin can reset it from Staff accounts.</li>
    </ul>
  </section>
  <section class="card">
    <div class="card-head"><h2><?= icon('users') ?> Across your team</h2><span class="badge <?= $staffOn === $staffActive ? 'b-green' : 'b-amber' ?>"><?= $staffOn ?> / <?= $staffActive ?></span></div>
    <div class="card-pad">
      <p class="muted" style="margin:0 0 12px"><?= $staffOn ?> of <?= $staffActive ?> active staff use two-step verification<?= setting('admin_require_2fa', '0') === '1' ? ', and it is required for everyone.' : '. It is optional right now.' ?></p>
      <?php if (can('roles.manage')): ?><div class="sa-inline"><a class="btn btn-light btn-sm" href="users.php?type=staff&amp;tfa=off"><?= icon('users') ?> Staff without it</a><a class="btn btn-light btn-sm" href="access.php#2fa"><?= icon('lock') ?> Require for everyone</a></div><?php endif; ?>
    </div>
  </section>
</aside>
</div>
<?php if (!$enabled): ?><script src="<?= e(app_url('assets/vendor/qrcode-generator.js')) ?>?v=<?= (int) @filemtime(APP_ROOT . '/assets/vendor/qrcode-generator.js') ?>"></script><?php endif; ?>
<?php admin_end();
