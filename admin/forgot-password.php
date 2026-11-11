<?php
/**
 * Staff "Forgot password?": emails a single-use reset link (30 minutes) to an active staff account.
 * The page always shows the same neutral message, so it never reveals whether an email is registered.
 * Rate limited per IP and per email; a hidden "website" field catches bots.
 */
declare(strict_types=1);

require __DIR__ . '/../api/config.php';
require_once APP_ROOT . '/includes/admin-header.php';
require_once APP_ROOT . '/includes/notify.php'; // send_staff_password_reset() + mailer

header_remove('X-Frame-Options');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
start_secure_session();

$me = current_user();
if ($me && $me['user_type'] === 'staff') {
    header('Location: dashboard.php', true, 303);
    exit;
}

$error = null;
$sent = false;
$email = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        $sent = true; // honeypot filled: behave exactly like a normal request, send nothing
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $error = 'Enter a valid email address.';
    } elseif (!rate_limit('admin-forgot', client_ip(), 5, 3600) || !rate_limit('admin-forgot-email', $email, 3, 3600)) {
        $error = 'Too many reset requests. Please try again in an hour.';
        audit_log('admin_password_reset_throttled', 'user', null, ['email_hash' => hash('sha256', $email)], 'warning');
    } else {
        $user = db_one("SELECT id, name, email FROM users WHERE email = ? AND user_type = 'staff' AND status = 'active'", [$email]);
        if ($user) {
            send_staff_password_reset($user);
        } else {
            audit_log('admin_password_reset_unknown', 'user', null, ['email_hash' => hash('sha256', $email)]);
        }
        $sent = true; // same response whether or not the account exists
    }
}

admin_auth_open('Forgot password');
?>
      <?php if ($sent): ?>
      <div class="login-form">
        <span class="login-ic ok"><?= icon('mail') ?></span>
        <h1>Check your inbox</h1>
        <p class="sub">If a staff account exists for <strong><?= e($email) ?></strong>, we have emailed a link to choose a new password. The link works once and expires in <?= STAFF_RESET_MINUTES ?> minutes.</p>
        <div class="alert alert-info"><?= icon('info') ?><span>No email after a few minutes? Check the spam folder, or ask a Super Admin to reset your password from Security → Staff Accounts.</span></div>
        <a class="btn btn-primary btn-lg btn-block" href="login.php"><?= icon('left') ?> Back to sign in</a>
      </div>
      <?php else: ?>
      <form method="post" class="login-form" novalidate>
        <span class="login-ic"><?= icon('key') ?></span>
        <h1>Forgot your password?</h1>
        <p class="sub">Enter the email address of your staff account and we will send you a link to choose a new password.</p>
        <?php if ($error): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
        <?= csrf_field() ?>
        <div class="hp-field" aria-hidden="true"><label for="website">Leave this empty</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
        <div class="field">
          <label for="email">Email address</label>
          <div class="in-icon"><?= icon('mail') ?><input id="email" name="email" type="email" value="<?= e($email) ?>" autocomplete="username" placeholder="you@example.com" maxlength="190" required autofocus<?= $error ? ' aria-invalid="true"' : '' ?>></div>
        </div>
        <button class="btn btn-primary btn-lg btn-block"><?= icon('send') ?> Email me a reset link</button>
        <p class="login-note"><a href="login.php">Back to sign in</a></p>
      </form>
      <?php endif; ?>
<?php
admin_auth_close();
