<?php
/**
 * Session management and authentication guards.
 * The browser never decides who it is - identity always comes from the PHP session.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    $lifetimeMinutes = (int) config('SESSION_LIFETIME', 120);
    session_name((string) config('SESSION_NAME', 'laxyam_sid'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => (string) config('VITE_APP_BASE', '/'),
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Idle timeout
    $now = time();
    if (isset($_SESSION['last_activity']) && $now - $_SESSION['last_activity'] > $lifetimeMinutes * 60) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = $now;
}

/** The logged-in, active user or null. Cached per request. */
function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    start_secure_session();
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) {
        return null;
    }
    $user = db_one(
        "SELECT id, name, email, phone, user_type, status, avatar_path, email_verified_at, created_at
         FROM users WHERE id = ? AND status = 'active'",
        [(int) $uid]
    );
    $sessionHash = hash('sha256', session_id());
    if ($user) {
        $session = db_one('SELECT id, revoked_at FROM user_sessions WHERE session_hash = ?', [$sessionHash]);
        if (!$session || $session['revoked_at'] !== null) {
            $user = null;
        } elseif (random_int(1, 10) === 1) {
            db_query('UPDATE user_sessions SET last_seen_at = NOW() WHERE id = ?', [$session['id']]);
        }
    }
    if (!$user) {
        // Account disabled/deleted or session revoked: end the session.
        unset($_SESSION['uid']);
    }
    return $user;
}

/** Start an authenticated session (call only after password_verify succeeded). */
function login_user(array $user): void
{
    start_secure_session();
    session_regenerate_id(true);              // prevent session fixation
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['login_at'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    db_query(
        'INSERT INTO user_sessions (user_id, session_hash, ip_address, user_agent) VALUES (?, ?, ?, ?)',
        [(int) $user['id'], hash('sha256', session_id()), client_ip(), user_agent()]
    );
    db_query('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [client_ip(), (int) $user['id']]);
}

function logout_user(): void
{
    start_secure_session();
    if (session_id() !== '') {
        db_query('UPDATE user_sessions SET revoked_at = NOW() WHERE session_hash = ? AND revoked_at IS NULL', [hash('sha256', session_id())]);
    }
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $params['path'],
        'secure' => $params['secure'],
        'httponly' => true,
        'samesite' => $params['samesite'] ?: 'Lax',
    ]);
    session_destroy();
}

/** The only user fields the browser receives. */
function public_user(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'type' => $user['user_type'],
        'avatar_url' => public_file_url($user['avatar_path']),
        'email_verified' => $user['email_verified_at'] !== null,
        'member_since' => iso_datetime($user['created_at']),
    ];
}

/** API guard: returns the current user or stops with 401. */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        api_error('Please log in to continue.', 401, 'unauthenticated');
    }
    return $user;
}

/** API guard for student-only endpoints. */
function require_student(): array
{
    $user = require_login();
    if ($user['user_type'] !== 'student') {
        api_error('This area is for student accounts.', 403, 'forbidden');
    }
    return $user;
}

// ------------------------------------------------------------------
// Login throttling (shared by student API login and admin login)
// ------------------------------------------------------------------

/**
 * True when too many recent failures exist for this identifier or IP. Several scopes share one count: the website
 * and admin sign-ins pass ['student', 'admin'], so every failure on either page adds to the same budget.
 */
function login_throttled(string|array $scopes, string $identifier): bool
{
    $scopes = array_values((array) $scopes);
    $hash = hash('sha256', strtolower(trim($identifier)));
    $perIdentifier = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts WHERE scope IN (' . implode(', ', array_fill(0, count($scopes), '?')) . ')
            AND identifier_hash = ? AND success = 0 AND created_at > (NOW() - INTERVAL 15 MINUTE)',
        [...$scopes, $hash]
    );
    $perIp = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND created_at > (NOW() - INTERVAL 15 MINUTE)',
        [client_ip()]
    );
    return $perIdentifier >= 5 || $perIp >= 20;
}

function record_login_attempt(string $scope, string $identifier, ?int $userId, bool $success): void
{
    db_query(
        'INSERT INTO login_attempts (scope, identifier_hash, user_id, ip_address, user_agent, success) VALUES (?, ?, ?, ?, ?, ?)',
        [$scope, hash('sha256', strtolower(trim($identifier))), $userId, client_ip(), user_agent(), $success ? 1 : 0]
    );
}

/**
 * Verify credentials. Returns the user row or null - callers show one generic error.
 * Runs password_verify even for unknown emails to keep timing uniform.
 */
function verify_credentials(string $email, string $password, string $userType): ?array
{
    $user = db_one('SELECT * FROM users WHERE email = ? AND user_type = ?', [strtolower(trim($email)), $userType]);
    // The stand-in hash for unknown emails must have the same bcrypt cost as real ones (PASSWORD_DEFAULT is cost 10
    // before PHP 8.4 - live runs 8.3 - and 12 from 8.4; real hashes are rehashed to it below), or a slower reply
    // would show that an email has no account.
    $dummy = '$2y$12$4bwteYvxvq.xAjvEF59hKep7SEz20RVAjhgGFKc9MEWAMlcbTynWW';
    if (password_needs_rehash($dummy, PASSWORD_DEFAULT)) {
        $dummy = '$2y$10$nSCbdL6FZ6V1yizEQoCti.prEoJAVe3S32gZhicXsy/O/7FPNP0a.';
    }
    $hash = $user['password_hash'] ?? $dummy;
    $ok = password_verify($password, $hash);
    if (!$user || !$ok || $user['status'] !== 'active') {
        return null;
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    return $user;
}

/** Password policy shared by registration, reset and change. Returns an error or null. */
function password_policy_error(string $password): ?string
{
    if (strlen($password) < 8 || strlen($password) > 128) {
        return 'Use 8 to 128 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Include at least one letter and one number.';
    }
    return null;
}

/** Revoke every session of a user except (optionally) the current one. */
function revoke_user_sessions(int $userId, bool $keepCurrent = false): void
{
    $sql = 'UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL';
    $params = [$userId];
    if ($keepCurrent && session_status() === PHP_SESSION_ACTIVE) {
        $sql .= ' AND session_hash <> ?';
        $params[] = hash('sha256', session_id());
    }
    db_query($sql, $params);
}

// ------------------------------------------------------------------
// Single-use, expiring tokens (password reset, email verification)
// Only the SHA-256 hash is stored; the raw token goes in the email link.
// ------------------------------------------------------------------

const TOKEN_TABLES = ['password_reset_tokens', 'email_verification_tokens'];

function create_user_token(string $table, int $userId, int $ttlMinutes): string
{
    if (!in_array($table, TOKEN_TABLES, true)) {
        throw new InvalidArgumentException('Unknown token table');
    }
    db_query("UPDATE {$table} SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$userId]);
    $raw = bin2hex(random_bytes(32));
    db_query(
        "INSERT INTO {$table} (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL ? MINUTE)",
        [$userId, hash('sha256', $raw), $ttlMinutes]
    );
    return $raw;
}

/** Atomically consume a token; returns the user id or null when invalid/expired/used. */
function consume_user_token(string $table, string $raw): ?int
{
    if (!in_array($table, TOKEN_TABLES, true) || !preg_match('/^[a-f0-9]{64}$/', $raw)) {
        return null;
    }
    $hash = hash('sha256', $raw);
    $stmt = db_query(
        "UPDATE {$table} SET used_at = NOW() WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()",
        [$hash]
    );
    if ($stmt->rowCount() !== 1) {
        return null;
    }
    return (int) db_value("SELECT user_id FROM {$table} WHERE token_hash = ?", [$hash]);
}

/** Admin page guard: redirects to admin/login.php when there is no staff session. */
function require_staff_page(): array
{
    $user = current_user();
    if (!$user || $user['user_type'] !== 'staff') {
        header('Location: login.php');
        exit;
    }
    // Admin → Access Policies: IP allowlist / hours are re-checked on every request.
    require_once APP_ROOT . '/includes/admin-access.php';
    if ($why = admin_access_denied($user)) {
        audit_log('admin_access_blocked', 'user', $user['id'], ['reason' => $why], 'warning');
        logout_user();
        start_secure_session();
        $_SESSION['login_notice'] = $why;
        header('Location: login.php');
        exit;
    }
    // Mandatory two-factor: staff without it can only reach the setup page.
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'two-factor.php' && staff_2fa_setup_required($user)) {
        header('Location: two-factor.php?required=1');
        exit;
    }
    return $user;
}

/** True when Admin → Security makes 2FA mandatory and this staff member has not set it up yet. */
function staff_2fa_setup_required(array $user): bool
{
    return setting('admin_require_2fa', '0') === '1'
        && !db_value('SELECT totp_enabled_at FROM users WHERE id = ?', [$user['id']]);
}
