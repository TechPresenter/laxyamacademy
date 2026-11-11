<?php
/**
 * Staff sign-in / sign-out. Throttled, audited, staff accounts only; two-step verification when enabled.
 * Forgotten passwords: forgot-password.php → reset-password.php.
 */
declare(strict_types=1);

require __DIR__ . '/../api/config.php';
require_once APP_ROOT . '/includes/admin-header.php'; // icon() + the sign-in shell

header_remove('X-Frame-Options');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
start_secure_session();

if (isset($_GET['logout'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_valid()) {
        audit_log('admin_logout', 'user', $_SESSION['uid'] ?? null);
        logout_user();
    }
    header('Location: login.php', true, 303);
    exit;
}

$me = current_user();
if ($me && $me['user_type'] === 'staff') {
    header('Location: dashboard.php', true, 303);
    exit;
}

require_once APP_ROOT . '/includes/admin-access.php';

$error = $_SESSION['login_notice'] ?? null;
$notice = $_SESSION['login_success'] ?? null; // e.g. "Password changed" from reset-password.php
unset($_SESSION['login_notice'], $_SESSION['login_success']);
$email = '';
// Step 2 (two-factor) is pending for 5 minutes after a correct password.
$pending = isset($_SESSION['2fa_uid'], $_SESSION['2fa_at']) && $_SESSION['2fa_at'] > time() - 300 ? (int) $_SESSION['2fa_uid'] : null;
if (isset($_GET['cancel'])) {
    unset($_SESSION['2fa_uid'], $_SESSION['2fa_at']);
    $pending = null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $pending && isset($_POST['code'])) {
    require_csrf();
    $user = db_one("SELECT * FROM users WHERE id = ? AND user_type = 'staff' AND status = 'active'", [$pending]);
    $code = trim((string) $_POST['code']);
    if (!$user || !rate_limit('2fa', (string) $pending, 6, 900)) {
        unset($_SESSION['2fa_uid'], $_SESSION['2fa_at']);
        $pending = null;
        $error = 'Too many wrong codes. Sign in again in 15 minutes.';
        audit_log('admin_2fa_throttled', 'user', $user['id'] ?? null, [], 'warning');
    } elseif (totp_verify((string) $user['totp_secret'], $code, (int) $user['id']) || (strlen($code) > 6 && use_recovery_code((int) $user['id'], $code))) {
        unset($_SESSION['2fa_uid'], $_SESSION['2fa_at']);
        login_user($user);
        audit_log(strlen($code) > 6 ? 'admin_login_recovery_code' : 'admin_login', 'user', $user['id'], ['2fa' => true], strlen($code) > 6 ? 'warning' : 'info');
        header('Location: dashboard.php', true, 303);
        exit;
    } else {
        audit_log('admin_2fa_failed', 'user', $user['id'], [], 'warning');
        $error = 'That code is not correct or has already been used. Check the time on your phone.';
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    // One budget with the website login (api/auth.php counts the same two scopes), for every address - so a lock-out
    // here or there never shows which emails are staff accounts.
    if (login_throttled(['admin', 'student'], $email)) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
        audit_log('admin_login_throttled', 'user', null, ['email_hash' => hash('sha256', $email)], 'warning');
    } else {
        // Plain-ASCII addresses only: the users.email collation treats accented / full-width letters as equal, so a
        // look-alike spelling would reach the account under a different throttle key.
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) && $password !== '' ? verify_credentials($email, $password, 'staff') : null;
        record_login_attempt('admin', $email, $user ? (int) $user['id'] : null, (bool) $user);
        if ($user && ($why = admin_access_denied($user))) {
            audit_log('admin_access_blocked', 'user', $user['id'], ['reason' => $why], 'warning');
            $error = $why;
        } elseif ($user && $user['totp_enabled_at'] && $user['totp_secret']) {
            session_regenerate_id(true);
            $_SESSION['2fa_uid'] = (int) $user['id'];
            $_SESSION['2fa_at'] = time();
            $pending = (int) $user['id'];
        } elseif ($user) {
            login_user($user);
            audit_log('admin_login', 'user', $user['id']);
            header('Location: dashboard.php', true, 303);
            exit;
        } else {
            audit_log('admin_login_failed', 'user', null, ['email_hash' => hash('sha256', $email)], 'warning');
            $error = 'Invalid email or password.';
        }
    }
}

admin_auth_open($pending ? 'Two-step verification' : 'Staff sign in');
?>
      <form method="post" class="login-form" novalidate>
        <?php if ($pending): ?>
        <span class="login-ic"><?= icon('lock') ?></span>
        <?php endif; ?>
        <h1><?= $pending ? 'Two-step verification' : 'Welcome back' ?></h1>
        <p class="sub"><?= $pending ? 'Enter the 6-digit code from your authenticator app, or one of your recovery codes.' : 'Sign in to the admin panel with your staff account.' ?></p>
        <?php if ($notice): ?><div class="alert alert-success" role="status"><?= icon('check-circle') ?><span><?= e($notice) ?></span></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
        <?= csrf_field() ?>
        <?php if ($pending): ?>
        <div class="field">
          <label for="code">Verification code</label>
          <input id="code" class="otp-input" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="11" required autofocus<?= $error ? ' aria-invalid="true"' : '' ?>>
        </div>
        <button class="btn btn-primary btn-lg btn-block"><?= icon('check') ?> Verify and continue</button>
        <p class="login-note"><a href="login.php?cancel=1">Use a different account</a></p>
        <?php else: ?>
        <div class="field">
          <label for="email">Email address</label>
          <div class="in-icon"><?= icon('mail') ?><input id="email" name="email" type="email" value="<?= e($email) ?>" autocomplete="username" placeholder="you@example.com" required autofocus<?= $error ? ' aria-invalid="true"' : '' ?>></div>
        </div>
        <div class="field">
          <div class="label-row"><label for="password">Password</label><a href="forgot-password.php">Forgot password?</a></div>
          <div class="pw-wrap in-icon">
            <?= icon('lock') ?>
            <input id="password" name="password" type="password" autocomplete="current-password" required<?= $error ? ' aria-invalid="true"' : '' ?>>
            <button type="button" class="pw-toggle" data-pw-toggle aria-controls="password" aria-pressed="false">Show</button>
          </div>
        </div>
        <button class="btn btn-primary btn-lg btn-block">Sign in <?= icon('arrow') ?></button>
        <p class="login-note">No account? Ask a Super Admin.</p>
        <?php endif; ?>
      </form>
<?php
admin_auth_close();
