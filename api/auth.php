<?php
/**
 * Student authentication API.
 *
 *   GET  auth.php?action=csrf                 -> { token }
 *   GET  auth.php?action=me                   -> { user | null }
 *   POST auth.php?action=register             name, email, phone, password, exam_preferences[], whatsapp_opt_in (bool, optional)
 *   POST auth.php?action=login                email, password
 *   POST auth.php?action=logout
 *   POST auth.php?action=verify-email         token
 *   POST auth.php?action=resend-verification  (logged in)
 *   POST auth.php?action=forgot-password      email
 *   POST auth.php?action=reset-password       token, password
 *   POST auth.php?action=change-password      current_password, password (logged in)
 *
 *   Password-less sign-in (includes/login-methods.php):
 *   GET  auth.php?action=options              -> { google: {client_id, nonce} | null, otp: {channels[]}, phone: {…} | null }
 *   POST auth.php?action=google               credential (Google ID token)
 *   POST auth.php?action=otp-send             phone, channel = whatsapp|sms, purpose = login|verify (verify: logged in)
 *   POST auth.php?action=otp-verify           phone, code, purpose  -> login | {needs_signup} | {verified}
 *   POST auth.php?action=otp-signup           name, email, accept_terms (after otp-verify said needs_signup)
 *   POST auth.php?action=email-otp-send       email (+ reCAPTCHA)          -> { masked, resend_in, expires_in }
 *   POST auth.php?action=email-otp-verify     email, code                  -> login | {needs_signup}
 *   POST auth.php?action=email-otp-signup     name, accept_terms (after email-otp-verify said needs_signup)
 *
 * Staff sign in through admin/login.php, never through this endpoint.
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/notify.php'; // send_template() + mailer (every email is logged in email_log)

$action = $_GET['action'] ?? '';
if (!in_array($action, ['csrf', 'me', 'options'], true)) {
    require_method('POST');
    require_csrf();
}
if (in_array($action, ['options', 'google', 'otp-send', 'otp-verify', 'otp-signup', 'email-otp-send', 'email-otp-verify', 'email-otp-signup'], true)) {
    require_once APP_ROOT . '/includes/login-methods.php';
}

match ($action) {
    'csrf' => api_ok(['token' => csrf_token()]),
    'me' => api_ok(['user' => ($u = current_user()) ? public_user($u) : null]),
    'options' => login_options(),
    'google' => google_login(),
    'otp-send' => otp_send_action(),
    'otp-verify' => otp_verify_action(),
    'otp-signup' => otp_signup(),
    'email-otp-send' => email_otp_send_action(),
    'email-otp-verify' => email_otp_verify_action(),
    'email-otp-signup' => email_otp_signup(),
    'register' => register(),
    'login' => login(),
    'logout' => logout(),
    'verify-email' => verify_email(),
    'resend-verification' => resend_verification(),
    'forgot-password' => forgot_password(),
    'reset-password' => reset_password(),
    'change-password' => change_password(),
    default => api_error('Unknown action.', 404, 'not_found'),
};

/** Development helper: when SMTP is not configured, return the link so testing can continue. */
function dev_link(bool $sent, string $url): array
{
    return (!$sent && !is_production()) ? ['dev_link' => $url, 'dev_note' => 'SMTP is not configured; this link is only returned in development.'] : [];
}

/** Post-commit emails must never turn a completed action into an error response. */
function send_quietly(callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        error_log('[laxyam] auth email failed: ' . $e->getMessage());
    }
}

/** "Your password was changed" security email (always sent; not affected by preferences). */
function send_password_changed(int $userId): void
{
    send_quietly(fn() => send_template('password_changed', $userId, [
        'when' => date('j M Y, g:i A') . ' (IST)', 'link' => '/forgot-password', 'button_label' => 'Reset password', 'related' => ['user', $userId],
    ]));
}

function send_verification_email(array $user): array
{
    $token = create_user_token('email_verification_tokens', (int) $user['id'], 60 * 24);
    $url = app_url('verify-email?token=' . $token);
    $sent = send_mail($user['email'], 'Verify your email address', mail_template(
        'Verify your email',
        '<p>Hello ' . e($user['name']) . ',</p><p>Please confirm your email address to secure your Laxyam Academy account. This link expires in 24 hours.</p>',
        'Verify email',
        $url,
        ['eyebrow' => 'Account security', 'preheader' => 'Confirm your email address to secure your Laxyam Academy account.']
    ), ['template_key' => 'email_verification', 'user_id' => (int) $user['id'], 'to_name' => $user['name'], 'related' => ['user', (int) $user['id']]]);
    return dev_link($sent, $url);
}

function register(): never
{
    if (!rate_limit('register', client_ip(), 10, 3600)) {
        api_error('Too many sign-up attempts. Please try again later.', 429, 'rate_limited');
    }
    require_recaptcha();
    $in = request_body();
    $data = validate_or_fail($in, [
        'name' => 'required|string|min:2|max:120',
        'email' => 'required|email',
        'phone' => 'required|phone',
        'password' => 'required|string|max:128',
    ]);
    if ($err = password_policy_error((string) $in['password'])) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['password' => $err]);
    }
    if (empty($in['accept_terms'])) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['accept_terms' => 'Please accept the terms to continue.']);
    }
    $prefs = array_values(array_filter(
        is_array($in['exam_preferences'] ?? null) ? $in['exam_preferences'] : [],
        fn($s) => is_string($s) && preg_match('/^[a-z0-9-]{1,60}$/', $s)
    ));

    if (db_value('SELECT id FROM users WHERE email = ?', [$data['email']])) {
        api_error('An account with this email may already exist. Try logging in or resetting your password.', 422, 'validation_failed', [
            'email' => 'Try logging in or use “Forgot password”.',
        ]);
    }

    $userId = db_transaction(function () use ($data, $prefs) {
        db_query(
            "INSERT INTO users (name, email, phone, password_hash, user_type, status) VALUES (?, ?, ?, ?, 'student', 'active')",
            [$data['name'], $data['email'], $data['phone'], password_hash((string) request_body()['password'], PASSWORD_DEFAULT)]
        );
        $id = (int) db()->lastInsertId();
        // Default notification preferences: admin → Notification Preferences
        db_query('INSERT INTO student_profiles (user_id, exam_preferences, notify_prefs) VALUES (?, ?, ?)', [$id, json_encode($prefs), setting('notify_default_prefs') ?: null]);
        record_attribution('user', $id, request_body()['attribution'] ?? null);
        return $id;
    });

    $user = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
    login_user($user);
    audit_log('student_registered', 'user', $userId);
    // WhatsApp updates only with the student's own explicit consent (unticked by default on the form);
    // recorded with the number it was given for, before the welcome message below.
    if (filter_var($in['whatsapp_opt_in'] ?? false, FILTER_VALIDATE_BOOLEAN) && is_file(APP_ROOT . '/includes/whatsapp.php')) {
        send_quietly(function () use ($userId) {
            require_once APP_ROOT . '/includes/whatsapp.php';
            whatsapp_set_opt_in($userId, true, 'register');
        });
    }
    $extra = send_verification_email($user);
    // Welcome email + in-app notification (template "welcome"), after the account is committed.
    send_quietly(fn() => send_template('welcome', $userId, ['link' => '/courses', 'button_label' => 'Explore courses', 'related' => ['user', $userId]]));

    api_ok(['user' => public_user($user), 'csrf' => csrf_token()] + $extra, 201);
}

function login(): never
{
    $in = request_body();
    $email = is_string($in['email'] ?? null) ? strtolower(trim($in['email'])) : '';
    $password = is_string($in['password'] ?? null) ? $in['password'] : '';
    if ($email === '' || $password === '') {
        api_error('Enter your email and password.', 422, 'validation_failed');
    }
    // Plain-ASCII addresses only: the users.email collation treats accented / full-width letters as equal, so a
    // look-alike spelling would reach the account under a different throttle key.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        record_login_attempt('student', $email, null, false);
        api_error('Invalid email or password.', 401, 'invalid_credentials');
    }
    // One count over both scopes for every email, as admin/login.php does: staff guesses made here count as admin
    // failures (below), the two sign-ins share one budget, and a 429 on either one never shows which emails are staff.
    if (login_throttled(['student', 'admin'], $email)) {
        api_error('Too many failed attempts. Please wait 15 minutes or reset your password.', 429, 'rate_limited');
    }
    $user = verify_credentials($email, $password, 'student');
    if (!$user) {
        // Staff sign in on the admin panel (two-step verification and access rules live there). The right staff
        // password gets a pointer to it instead of "invalid" - staff kept getting locked out here. A wrong one is an
        // admin sign-in failure: same throttle and security alerts as admin/login.php. Every failed sign-in runs both
        // checks, each with one password_verify of the same cost, so the time taken does not show staff accounts.
        $staff = verify_credentials($email, $password, 'staff');
        if ($staff) {
            audit_log('admin_login_redirected', 'user', $staff['id'], ['via' => 'website']);
            api_error('This is an admin (staff) account. Please sign in on the admin panel.', 409, 'staff_account');
        }
        if (db_value("SELECT id FROM users WHERE email = ? AND user_type = 'staff'", [$email])) {
            // Exactly one write, like any other failure (no extra audit row), so the reply time stays the same.
            record_login_attempt('admin', $email, null, false);
            api_error('Invalid email or password.', 401, 'invalid_credentials');
        }
    }
    record_login_attempt('student', $email, $user ? (int) $user['id'] : null, (bool) $user);
    if (!$user) {
        api_error('Invalid email or password.', 401, 'invalid_credentials');
    }
    login_user($user);
    api_ok(['user' => public_user($user), 'csrf' => csrf_token()]);
}

function logout(): never
{
    logout_user();
    api_ok(['logged_out' => true]);
}

// ------------------------------------------------------------------
// Password-less sign-in: Google and mobile OTP
// ------------------------------------------------------------------

/** Which sign-in methods are switched on (+ a one-time nonce for the Google button, + the student's mobile status). */
function login_options(): never
{
    $client = login_methods_ready() ? google_client_id() : null;
    $phone = null;
    if (($u = current_user()) && $u['user_type'] === 'student' && login_methods_ready()) {
        $row = db_one('SELECT phone, phone_verified_at FROM users WHERE id = ?', [$u['id']]);
        $phone = ['number' => $row['phone'], 'verified' => $row['phone_verified_at'] !== null];
    }
    api_ok([
        'google' => $client ? ['client_id' => $client, 'nonce' => google_issue_nonce()] : null,
        'otp' => ['channels' => otp_channels()],
        'email_otp' => email_otp_ready(),
        'phone' => $phone,
    ]);
}

// ------------------------------------------------------------------
// Email OTP: a one-time code to the student's email (sign in, or sign up for a new address)
// ------------------------------------------------------------------

function email_otp_address(): string
{
    if (!email_otp_ready()) {
        api_error('Email codes are not available right now. Please log in with your password.', 404, 'not_available');
    }
    $email = strtolower(trim((string) (request_body()['email'] ?? '')));
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('Enter a valid email address.', 422, 'validation_failed', ['email' => 'Enter a valid email address.']);
    }
    return $email;
}

function email_otp_send_action(): never
{
    $email = email_otp_address();
    require_recaptcha(); // the form sends email to any address: keep bots from using it
    $r = email_otp_issue($email);
    if (!$r['ok']) {
        api_error($r['error'], $r['status'], $r['code'], isset($r['resend_in']) ? ['resend_in' => (string) $r['resend_in']] : []);
    }
    unset($r['ok']);
    api_ok($r);
}

function email_otp_verify_action(): never
{
    $email = email_otp_address();
    $r = otp_verify($email, 'login', (string) (request_body()['code'] ?? ''));
    if (!$r['ok']) {
        api_error($r['error'], $r['status'], $r['code'], ['code' => $r['error']]);
    }
    $account = db_one('SELECT * FROM users WHERE email = ?', [$email]);
    if ($account) {
        // A code is only ever sent to active students, so this is a safety net (staff sign in at /admin).
        if ($account['user_type'] !== 'student' || $account['status'] !== 'active') {
            api_error('This account cannot log in here.', 403, 'not_allowed');
        }
        if ($account['email_verified_at'] === null) {
            // The code proves this address, but the account never did: whoever registered it may not own it. As for
            // Google, replace that password, end its sessions and drop a mobile number they confirmed (stops account
            // pre-hijacking), then mark the email verified.
            db_query('UPDATE users SET password_hash = ?, phone_verified_at = NULL, email_verified_at = NOW() WHERE id = ?',
                [password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $account['id']]);
            revoke_user_sessions((int) $account['id']);
            audit_log('email_claimed_by_code', 'user', (int) $account['id'], [], 'warning');
            $account = db_one('SELECT * FROM users WHERE id = ?', [$account['id']]);
        }
        login_user($account);
        record_login_attempt('student', $email, (int) $account['id'], true);
        audit_log('student_login', 'user', (int) $account['id'], ['method' => 'email_otp']);
        api_ok(['user' => public_user($account), 'csrf' => csrf_token()]);
    }
    // No account yet: keep the proof briefly for a short sign-up form (name only - the email is confirmed).
    start_secure_session();
    $_SESSION['otp_email'] = ['email' => $email, 'at' => time()];
    api_ok(['needs_signup' => true, 'email' => $email]);
}

/** Create the account for an address just confirmed by email-otp-verify. */
function email_otp_signup(): never
{
    if (!email_otp_ready()) {
        api_error('Email codes are not available right now.', 404, 'not_available');
    }
    start_secure_session();
    $proof = $_SESSION['otp_email'] ?? null;
    if (!is_array($proof) || (int) ($proof['at'] ?? 0) < time() - OTP_SIGNUP_SECONDS) {
        api_error('Your email verification has expired. Please ask for a new code.', 400, 'otp_expired');
    }
    if (!rate_limit('register', client_ip(), 10, 3600)) {
        api_error('Too many sign-up attempts. Please try again later.', 429, 'rate_limited');
    }
    $in = request_body();
    $data = validate_or_fail($in, ['name' => 'required|string|min:2|max:120']);
    if (empty($in['accept_terms'])) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['accept_terms' => 'Please accept the terms to continue.']);
    }
    if (db_value('SELECT id FROM users WHERE email = ?', [$proof['email']])) {
        unset($_SESSION['otp_email']);
        api_error('An account with this email already exists. Log in with the email code.', 409, 'email_taken');
    }
    $prefs = array_values(array_filter(is_array($in['exam_preferences'] ?? null) ? $in['exam_preferences'] : [], fn($s) => is_string($s) && preg_match('/^[a-z0-9-]{1,60}$/', $s)));
    $user = create_student_account($data['name'], $proof['email'], null, ['email_verified' => true], $prefs);
    unset($_SESSION['otp_email']);
    login_user($user);
    audit_log('student_registered', 'user', (int) $user['id'], ['method' => 'email_otp']);
    send_quietly(fn() => send_template('welcome', (int) $user['id'], ['link' => '/courses', 'button_label' => 'Explore courses', 'related' => ['user', (int) $user['id']]]));
    api_ok(['user' => public_user($user), 'csrf' => csrf_token()], 201);
}

/** Sign in (or sign up) with a Google ID token from Google Identity Services. */
function google_login(): never
{
    $client = login_methods_ready() ? google_client_id() : null;
    if (!$client) {
        api_error('Google sign-in is not available.', 404, 'not_available');
    }
    if (!rate_limit('google-login', client_ip(), 30, 3600)) {
        api_error('Too many attempts. Please try again later.', 429, 'rate_limited');
    }
    $claims = google_verify_id_token((string) (request_body()['credential'] ?? ''), $client, null, $why);
    if (!$claims || !google_take_nonce((string) ($claims['nonce'] ?? ''))) {
        api_error('Google sign-in could not be confirmed. Please try again.', 401, 'google_invalid');
    }
    $sub = (string) $claims['sub'];
    $email = strtolower(trim((string) $claims['email']));
    $user = db_one('SELECT * FROM users WHERE google_sub = ?', [$sub]) ?: db_one('SELECT * FROM users WHERE email = ?', [$email]);
    if ($user && $user['user_type'] !== 'student') {
        api_error('Staff accounts sign in through the admin panel.', 403, 'forbidden');
    }
    if ($user && $user['status'] !== 'active') {
        api_error('This account is not active. Please contact the academy.', 403, 'inactive');
    }
    if ($user && $user['google_sub'] !== null && $user['google_sub'] !== $sub) {
        api_error('This email is linked to a different Google account. Log in with your password instead.', 409, 'google_mismatch');
    }
    $created = false;
    if (!$user) {
        $name = trim((string) ($claims['name'] ?? '')) ?: ucfirst((string) strstr($email, '@', true));
        $user = create_student_account($name, $email, null, ['email_verified' => true, 'google_sub' => $sub]);
        $created = true;
    } elseif ($user['google_sub'] === null) {
        // Google has proved this email. If the account never proved it, whoever registered it may not
        // own it: replace that password and end its sessions before linking (stops account pre-hijacking).
        if ($user['email_verified_at'] === null) {
            db_query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $user['id']]);
            revoke_user_sessions((int) $user['id']);
        }
        db_query('UPDATE users SET google_sub = ?, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [$sub, $user['id']]);
        audit_log('google_linked', 'user', (int) $user['id']);
    }
    login_user($user);
    record_login_attempt('student', $email, (int) $user['id'], true);
    audit_log($created ? 'student_registered' : 'student_login', 'user', (int) $user['id'], ['method' => 'google']);
    if ($created) {
        send_quietly(fn() => send_template('welcome', (int) $user['id'], ['link' => '/courses', 'button_label' => 'Explore courses', 'related' => ['user', (int) $user['id']]]));
    }
    api_ok(['user' => public_user(db_one('SELECT * FROM users WHERE id = ?', [$user['id']])), 'csrf' => csrf_token(), 'created' => $created], $created ? 201 : 200);
}

/** Purpose and phone of an OTP request; "verify" (adding a number to the profile) needs a signed-in student. */
function otp_request(): array
{
    if (!login_methods_ready()) {
        api_error('Mobile sign-in is not available yet.', 404, 'not_available');
    }
    $in = request_body();
    $purpose = ($in['purpose'] ?? 'login') === 'verify' ? 'verify' : 'login';
    $user = $purpose === 'verify' ? require_student() : null;
    $phone = otp_phone((string) ($in['phone'] ?? ''));
    if (!$phone) {
        api_error('Enter a valid 10-digit Indian mobile number.', 422, 'validation_failed', ['phone' => 'Enter a valid 10-digit mobile number.']);
    }
    if ($user && ($other = student_by_verified_phone($phone)) && (int) $other['id'] !== (int) $user['id']) {
        api_error('This number is already verified on another account.', 409, 'phone_taken', ['phone' => 'Already verified on another account.']);
    }
    return [$purpose, $phone, $user, $in];
}

function otp_send_action(): never
{
    [$purpose, $phone, $user, $in] = otp_request();
    $r = otp_issue($phone, $purpose, (string) ($in['channel'] ?? ''), $user ? (int) $user['id'] : null);
    if (!$r['ok']) {
        api_error($r['error'], $r['status'], $r['code'], isset($r['resend_in']) ? ['resend_in' => (string) $r['resend_in']] : []);
    }
    unset($r['ok']);
    api_ok($r);
}

function otp_verify_action(): never
{
    [$purpose, $phone, $user, $in] = otp_request();
    $r = otp_verify($phone, $purpose, (string) ($in['code'] ?? ''), $user ? (int) $user['id'] : null);
    if (!$r['ok']) {
        api_error($r['error'], $r['status'], $r['code'], ['code' => $r['error']]);
    }
    if ($user) {
        if (($other = student_by_verified_phone($phone)) && (int) $other['id'] !== (int) $user['id']) {
            api_error('This number was just verified on another account.', 409, 'phone_taken');
        }
        db_query('UPDATE users SET phone = ?, phone_verified_at = NOW() WHERE id = ?', [$phone, $user['id']]);
        audit_log('phone_verified', 'user', (int) $user['id']);
        api_ok(['verified' => true, 'phone' => $phone]);
    }
    $account = student_by_verified_phone($phone);
    if ($account) {
        login_user($account);
        record_login_attempt('student', $phone, (int) $account['id'], true);
        audit_log('student_login', 'user', (int) $account['id'], ['method' => 'otp']);
        api_ok(['user' => public_user($account), 'csrf' => csrf_token()]);
    }
    // No account has confirmed this number yet: keep the proof briefly for a short sign-up form.
    start_secure_session();
    $_SESSION['otp_phone'] = ['phone' => $phone, 'at' => time()];
    $unconfirmed = (bool) db_value("SELECT 1 FROM users WHERE user_type = 'student' AND status = 'active' AND phone_verified_at IS NULL
        AND RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', ''), 10) = ? LIMIT 1", [substr($phone, 3)]);
    api_ok(['needs_signup' => true, 'phone' => $phone, 'masked' => otp_mask($phone), 'existing_unconfirmed' => $unconfirmed]);
}

/** Create the account for a number just confirmed by otp-verify (name + email; no password needed). */
function otp_signup(): never
{
    if (!login_methods_ready()) {
        api_error('Mobile sign-in is not available yet.', 404, 'not_available');
    }
    start_secure_session();
    $proof = $_SESSION['otp_phone'] ?? null;
    if (!is_array($proof) || (int) ($proof['at'] ?? 0) < time() - OTP_SIGNUP_SECONDS) {
        api_error('Your mobile verification has expired. Please ask for a new code.', 400, 'otp_expired');
    }
    if (!rate_limit('register', client_ip(), 10, 3600)) {
        api_error('Too many sign-up attempts. Please try again later.', 429, 'rate_limited');
    }
    $in = request_body();
    $data = validate_or_fail($in, ['name' => 'required|string|min:2|max:120', 'email' => 'required|email']);
    if (empty($in['accept_terms'])) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['accept_terms' => 'Please accept the terms to continue.']);
    }
    if (student_by_verified_phone($proof['phone'])) {
        unset($_SESSION['otp_phone']);
        api_error('An account was just created with this number. Log in with the mobile code.', 409, 'phone_taken');
    }
    if (db_value('SELECT id FROM users WHERE email = ?', [$data['email']])) {
        api_error('An account with this email already exists.', 422, 'validation_failed', [
            'email' => 'This email already has an account. Log in with it (password or Google), then verify your mobile number in My Profile.',
        ]);
    }
    $prefs = array_values(array_filter(is_array($in['exam_preferences'] ?? null) ? $in['exam_preferences'] : [], fn($s) => is_string($s) && preg_match('/^[a-z0-9-]{1,60}$/', $s)));
    $user = create_student_account($data['name'], $data['email'], $proof['phone'], ['phone_verified' => true], $prefs);
    unset($_SESSION['otp_phone']);
    login_user($user);
    audit_log('student_registered', 'user', (int) $user['id'], ['method' => 'otp']);
    if (filter_var($in['whatsapp_opt_in'] ?? false, FILTER_VALIDATE_BOOLEAN) && is_file(APP_ROOT . '/includes/whatsapp.php')) {
        send_quietly(function () use ($user) {
            require_once APP_ROOT . '/includes/whatsapp.php';
            whatsapp_set_opt_in((int) $user['id'], true, 'register');
        });
    }
    $extra = send_verification_email($user);
    send_quietly(fn() => send_template('welcome', (int) $user['id'], ['link' => '/courses', 'button_label' => 'Explore courses', 'related' => ['user', (int) $user['id']]]));
    api_ok(['user' => public_user($user), 'csrf' => csrf_token()] + $extra, 201);
}

function verify_email(): never
{
    $userId = consume_user_token('email_verification_tokens', (string) (request_body()['token'] ?? ''));
    if (!$userId) {
        api_error('This verification link is invalid or has expired.', 400, 'invalid_token');
    }
    db_query('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [$userId]);
    audit_log('email_verified', 'user', $userId);
    api_ok(['verified' => true]);
}

function resend_verification(): never
{
    $user = require_login();
    if ($user['email_verified_at']) {
        api_ok(['already_verified' => true]);
    }
    if (!rate_limit('verify-resend', (string) $user['id'], 3, 3600)) {
        api_error('Please wait before requesting another email.', 429, 'rate_limited');
    }
    api_ok(['sent' => true] + send_verification_email($user));
}

function forgot_password(): never
{
    $email = strtolower(trim((string) (request_body()['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('Enter a valid email address.', 422, 'validation_failed', ['email' => 'Enter a valid email address.']);
    }
    if (!rate_limit('forgot', client_ip(), 5, 3600) || !rate_limit('forgot-email', $email, 3, 3600)) {
        api_error('Too many requests. Please try again later.', 429, 'rate_limited');
    }
    // Every request here also uses the admin form's per-address budget (admin/forgot-password.php), whatever the
    // account type: staff get at most 3 reset emails an hour from both forms together, and the admin form's
    // "too many requests" can never show which addresses are staff.
    $adminBudget = rate_limit('admin-forgot-email', $email, 3, 3600);
    $extra = [];
    $user = db_one("SELECT id, name, email FROM users WHERE email = ? AND user_type = 'student' AND status = 'active'", [$email]);
    if ($user) {
        $token = create_user_token('password_reset_tokens', (int) $user['id'], 60);
        $url = app_url('reset-password?token=' . $token);
        $sent = send_mail($user['email'], 'Reset your password', mail_template(
            'Reset your password',
            '<p>Hello ' . e($user['name']) . ',</p><p>We received a request to reset your password. This link expires in 60 minutes and can be used once. If you did not ask for this, you can ignore this email.</p>',
            'Reset password',
            $url,
            ['eyebrow' => 'Account security', 'preheader' => 'Use this link within 60 minutes to choose a new password.']
        ), ['template_key' => 'password_reset', 'user_id' => (int) $user['id'], 'to_name' => $user['name'], 'related' => ['user', (int) $user['id']]]);
        $extra = dev_link($sent, $url);
        audit_log('password_reset_requested', 'user', $user['id']);
        // Optional WhatsApp copy of the same one-time link (template "Password reset link", off by default):
        // only to the opted-in number of this account, as a URL button; the token is never logged.
        if (is_file(APP_ROOT . '/includes/whatsapp.php')) {
            send_quietly(function () use ($user, $token) {
                require_once APP_ROOT . '/includes/whatsapp.php';
                whatsapp_password_reset((int) $user['id'], $token);
            });
        }
    } elseif ($staff = db_one("SELECT id, name, email FROM users WHERE email = ? AND user_type = 'staff' AND status = 'active'", [$email])) {
        // Staff who try the website's form get the admin reset link (the same email as admin/forgot-password.php),
        // unless the shared budget above is used up. The reply below stays the same either way.
        if ($adminBudget) {
            send_staff_password_reset($staff);
        } else {
            audit_log('admin_password_reset_throttled', 'user', null, ['email_hash' => hash('sha256', $email), 'via' => 'website'], 'warning');
        }
    }
    // Same response whether or not the account exists.
    api_ok(['message' => 'If an account exists for that email, a reset link has been sent.'] + $extra);
}

function reset_password(): never
{
    $in = request_body();
    $password = (string) ($in['password'] ?? '');
    if ($err = password_policy_error($password)) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['password' => $err]);
    }
    $token = (string) ($in['token'] ?? '');
    // Website reset links are for student accounts. A staff link only works on admin/reset-password.php, which
    // applies the longer staff password rule; here it is "invalid" and is not used up.
    $owner = preg_match('/^[a-f0-9]{64}$/', $token)
        ? db_one('SELECT u.user_type FROM password_reset_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ?', [hash('sha256', $token)])
        : null;
    $userId = $owner && $owner['user_type'] === 'student' ? consume_user_token('password_reset_tokens', $token) : null;
    if (!$userId) {
        api_error('This reset link is invalid or has expired. Please request a new one.', 400, 'invalid_token');
    }
    db_query('UPDATE users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
    revoke_user_sessions($userId);
    audit_log('password_reset_completed', 'user', $userId, [], 'warning');
    send_password_changed($userId);
    api_ok(['reset' => true]);
}

function change_password(): never
{
    $user = require_login();
    $in = request_body();
    $row = db_one('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
    if (!password_verify((string) ($in['current_password'] ?? ''), $row['password_hash'])) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['current_password' => 'Current password is incorrect.']);
    }
    $password = (string) ($in['password'] ?? '');
    if ($err = password_policy_error($password)) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['password' => $err]);
    }
    db_query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    revoke_user_sessions((int) $user['id']);
    session_regenerate_id(true);
    db_query('INSERT INTO user_sessions (user_id, session_hash, ip_address, user_agent) VALUES (?, ?, ?, ?)',
        [$user['id'], hash('sha256', session_id()), client_ip(), user_agent()]);
    audit_log('password_changed', 'user', $user['id'], [], 'warning');
    send_password_changed((int) $user['id']);
    api_ok(['changed' => true]);
}
