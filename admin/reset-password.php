<?php
/**
 * Staff password reset from the emailed link (forgot-password.php).
 * The token must belong to an active staff account, is single-use and expires. On success every
 * session of the account is signed out, the change is audited and a "password changed" email is sent.
 * Two-step verification (if enabled) still applies at the next sign-in.
 */
declare(strict_types=1);

require __DIR__ . '/../api/config.php';
require_once APP_ROOT . '/includes/admin-header.php';

header_remove('X-Frame-Options');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
start_secure_session();

const STAFF_MIN_PASSWORD = 10; // same rule as My Profile → Change password

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
/** Staff user id for a valid, unused, unexpired token (without using it up), else null. */
function staff_reset_user(string $token): ?int
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $id = db_value("SELECT t.user_id FROM password_reset_tokens t JOIN users u ON u.id = t.user_id
                    WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW() AND u.user_type = 'staff' AND u.status = 'active'", [hash('sha256', $token)]);
    return $id ? (int) $id : null;
}

$userId = staff_reset_user($token);
$errors = [];

if ($userId && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');
    if (!rate_limit('admin-reset', client_ip(), 10, 3600)) {
        $errors['form'] = 'Too many attempts. Please try again later.';
    } elseif (strlen($password) < STAFF_MIN_PASSWORD) {
        $errors['password'] = 'Use at least ' . STAFF_MIN_PASSWORD . ' characters.';
    } elseif ($err = password_policy_error($password)) {
        $errors['password'] = $err;
    } elseif (!hash_equals($password, $confirm)) {
        $errors['confirm'] = 'The two passwords do not match.';
    } elseif (password_verify($password, (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$userId]))) {
        $errors['password'] = 'Choose a password you have not used for this account.';
    }
    if (!$errors) {
        // Use the token up atomically; it must still point at the same staff account.
        if (consume_user_token('password_reset_tokens', $token) !== $userId) {
            $userId = null;
        } else {
            db_query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
            revoke_user_sessions($userId);
            unset($_SESSION['2fa_uid'], $_SESSION['2fa_at']);
            audit_log('admin_password_reset_completed', 'user', $userId, [], 'warning');
            try {
                require_once APP_ROOT . '/includes/notify.php';
                send_template('password_changed', $userId, [
                    'when' => date('j M Y, g:i A') . ' (IST)', 'link' => admin_url('forgot-password.php'), 'button_label' => 'Reset password', 'related' => ['user', $userId],
                ]);
            } catch (Throwable $e) {
                error_log('[laxyam] staff password_changed email failed: ' . $e->getMessage());
            }
            session_regenerate_id(true);
            $_SESSION['login_success'] = 'Your password was changed and every other session was signed out. Sign in with your new password.';
            header('Location: login.php', true, 303);
            exit;
        }
    }
}

$fieldErr = fn(string $k) => isset($errors[$k]) ? '<div class="err">' . icon('alert') . e($errors[$k]) . '</div>' : '';
admin_auth_open('Choose a new password');
if (!$userId): ?>
      <div class="login-form">
        <span class="login-ic warn"><?= icon('alert') ?></span>
        <h1>This link has expired</h1>
        <p class="sub">Password reset links work once and only for 30 minutes. Request a new link and use the latest email you receive.</p>
        <a class="btn btn-primary btn-lg btn-block" href="forgot-password.php"><?= icon('send') ?> Send a new link</a>
        <p class="login-note"><a href="login.php">Back to sign in</a></p>
      </div>
<?php else: ?>
      <form method="post" class="login-form" novalidate>
        <span class="login-ic"><?= icon('lock') ?></span>
        <h1>Choose a new password</h1>
        <p class="sub">Use at least <?= STAFF_MIN_PASSWORD ?> characters with letters and numbers. All other sessions of this account will be signed out.</p>
        <?php if (isset($errors['form'])): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($errors['form']) ?></span></div><?php endif; ?>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field<?= isset($errors['password']) ? ' invalid' : '' ?>">
          <label for="password">New password</label>
          <div class="pw-wrap in-icon">
            <?= icon('lock') ?>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="<?= STAFF_MIN_PASSWORD ?>" maxlength="128" required autofocus<?= isset($errors['password']) ? ' aria-invalid="true" aria-describedby="password-err"' : '' ?>>
            <button type="button" class="pw-toggle" data-pw-toggle aria-controls="password" aria-pressed="false">Show</button>
          </div>
          <?= str_replace('<div class="err">', '<div class="err" id="password-err">', $fieldErr('password')) ?>
        </div>
        <div class="field<?= isset($errors['confirm']) ? ' invalid' : '' ?>">
          <label for="confirm">Confirm new password</label>
          <div class="in-icon"><?= icon('check') ?><input id="confirm" name="confirm" type="password" autocomplete="new-password" maxlength="128" required<?= isset($errors['confirm']) ? ' aria-invalid="true" aria-describedby="confirm-err"' : '' ?>></div>
          <?= str_replace('<div class="err">', '<div class="err" id="confirm-err">', $fieldErr('confirm')) ?>
        </div>
        <button class="btn btn-primary btn-lg btn-block"><?= icon('check') ?> Save new password</button>
        <p class="login-note"><a href="login.php">Back to sign in</a></p>
      </form>
<?php endif;
admin_auth_close();
