<?php
/**
 * Student sign-in without a password: "Sign in with Google", an email OTP and a mobile OTP (WhatsApp or SMS).
 * Used by api/auth.php; staff always sign in through admin/login.php.
 *
 * Google: the browser gets an ID token from Google Identity Services; the server checks its RS256
 *   signature against Google's published certificates, the audience (our client ID), issuer,
 *   expiry, a one-time nonce issued to this session and a verified email address.
 * OTP: a 6-digit code stored only as an HMAC, valid 10 minutes, 5 tries, one resend every
 *   45 seconds and hourly limits per number and per IP. OTP login opens only an account whose
 *   mobile number was itself confirmed by OTP (phone_verified_at), never one with a typed-in number.
 * Keys: admin → Keys & Credentials → Student login (GOOGLE_CLIENT_ID, MSG91_AUTH_KEY,
 *   MSG91_OTP_TEMPLATE_ID, OTP_WHATSAPP_TEMPLATE). WhatsApp codes use the WhatsApp keys.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

const OTP_TTL_SECONDS = 600;
const OTP_MAX_TRIES = 5;
const OTP_RESEND_SECONDS = 45;
const OTP_SIGNUP_SECONDS = 900;   // a verified number can be used to create an account for 15 minutes
const GOOGLE_NONCE_SECONDS = 600;
const GOOGLE_ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];
const GOOGLE_CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';
const GOOGLE_CERTS_CACHE = PRIVATE_UPLOADS . '/.google-certs.json';

/** Database update 012 applied (google_sub, phone_verified_at, login_otps)? */
function login_methods_ready(): bool
{
    static $on = null;
    return $on ??= (bool) db_value("SHOW COLUMNS FROM users LIKE 'phone_verified_at'");
}

/** The Google OAuth client ID when one is configured (it is public: the browser needs it too). */
function google_client_id(): ?string
{
    $id = trim((string) config('GOOGLE_CLIENT_ID', ''));
    return preg_match('/^\d+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $id) ? $id : null;
}

/** OTP channels that can deliver right now: a subset of ['whatsapp', 'sms']. */
function otp_channels(): array
{
    if (!login_methods_ready()) {
        return [];
    }
    $out = [];
    if (trim((string) config('OTP_WHATSAPP_TEMPLATE', '')) !== '' && is_file(APP_ROOT . '/includes/whatsapp.php')) {
        require_once APP_ROOT . '/includes/whatsapp.php';
        if (whatsapp_credentials_ok()) {
            $out[] = 'whatsapp';
        }
    }
    if (trim((string) config('MSG91_AUTH_KEY', '')) !== '' && trim((string) config('MSG91_OTP_TEMPLATE_ID', '')) !== '') {
        $out[] = 'sms';
    }
    return $out;
}

// ------------------------------------------------------------------
// Mobile OTP
// ------------------------------------------------------------------

/** An Indian mobile number as E.164 (+91 and 10 digits starting 6-9), or null. */
function otp_phone(string $raw): ?string
{
    $digits = preg_replace('/\D/', '', $raw);
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }
    return preg_match('/^[6-9]\d{9}$/', $digits) ? '+91' . $digits : null;
}

/** "+91 98xxxxxx10" for messages. */
function otp_mask(string $e164): string
{
    return '+91 ' . substr($e164, 3, 2) . 'xxxxxx' . substr($e164, -2);
}

/** Server secret for hashing codes (generated once, kept in settings, never shown). */
function otp_secret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    $read = fn() => (string) db_value("SELECT setting_value FROM site_settings WHERE setting_key = 'otp_hash_secret'");
    $value = $read();
    if (strlen($value) < 32) {
        db_query("INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_group, is_public) VALUES ('otp_hash_secret', ?, 'system', 0)", [bin2hex(random_bytes(32))]);
        $value = $read();
    }
    return $secret = $value;
}

function otp_hash(string $phone, string $purpose, string $code): string
{
    return hash_hmac('sha256', $phone . '|' . $purpose . '|' . $code, otp_secret());
}

function otp_fail(int $status, string $code, string $message, array $extra = []): array
{
    return ['ok' => false, 'status' => $status, 'code' => $code, 'error' => $message] + $extra;
}

/**
 * Create a code and send it. Returns ['ok' => true, 'channel', 'resend_in', 'expires_in', 'masked']
 * or a failure ['ok' => false, 'status', 'code', 'error'(, 'resend_in')].
 */
function otp_issue(string $phone, string $purpose, string $channel, ?int $userId = null): array
{
    if (!in_array($channel, otp_channels(), true)) {
        return otp_fail(422, 'channel_unavailable', 'This way of sending the code is not available.');
    }
    $last = db_value('SELECT created_at FROM login_otps WHERE phone = ? AND purpose = ? ORDER BY id DESC LIMIT 1', [$phone, $purpose]);
    if ($last && ($wait = OTP_RESEND_SECONDS - (time() - strtotime((string) $last))) > 0) {
        return otp_fail(429, 'otp_wait', 'Please wait ' . $wait . ' seconds before asking for a new code.', ['resend_in' => $wait]);
    }
    if (!rate_limit('otp-phone', $phone, 5, 3600) || !rate_limit('otp-ip', client_ip(), 12, 3600)) {
        return otp_fail(429, 'rate_limited', 'Too many codes requested. Please try again in an hour.');
    }
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $sent = $channel === 'sms' ? otp_send_sms($phone, $code) : otp_send_whatsapp($phone, $code);
    if (empty($sent['ok'])) {
        error_log('[laxyam] login code by ' . $channel . ' not delivered: ' . mb_substr((string) ($sent['error'] ?? 'unknown'), 0, 300)); // never the code
        return otp_fail(502, 'otp_not_sent', 'We could not send the code ' . ($channel === 'whatsapp' ? 'on WhatsApp' : 'by SMS') . '. Please try again'
            . (count(otp_channels()) > 1 ? ' or choose the other option.' : '.'));
    }
    // Only the newest code works.
    db_query('UPDATE login_otps SET consumed_at = NOW() WHERE phone = ? AND purpose = ? AND consumed_at IS NULL', [$phone, $purpose]);
    db_query('INSERT INTO login_otps (phone, purpose, user_id, channel, code_hash, expires_at, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$phone, $purpose, $userId, $channel, otp_hash($phone, $purpose, $code), date('Y-m-d H:i:s', time() + OTP_TTL_SECONDS), client_ip()]);
    return ['ok' => true, 'channel' => $channel, 'resend_in' => OTP_RESEND_SECONDS, 'expires_in' => OTP_TTL_SECONDS, 'masked' => otp_mask($phone)];
}

/** Check a code (each try counts, a correct code works once). Returns ['ok' => true] or a failure like otp_issue(). */
function otp_verify(string $phone, string $purpose, string $code, ?int $userId = null): array
{
    $code = preg_replace('/\D/', '', $code);
    $row = db_one('SELECT * FROM login_otps WHERE phone = ? AND purpose = ? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1', [$phone, $purpose]);
    if (!$row || strtotime($row['expires_at']) <= time() || ($purpose === 'verify' && (int) $row['user_id'] !== (int) $userId)) {
        return otp_fail(400, 'otp_expired', 'This code has expired. Ask for a new one.');
    }
    // Count the try before comparing, atomically, so parallel guesses cannot pass the limit.
    if (db_query('UPDATE login_otps SET attempts = attempts + 1 WHERE id = ? AND attempts < ?', [$row['id'], OTP_MAX_TRIES])->rowCount() !== 1) {
        return otp_fail(429, 'otp_locked', 'Too many wrong codes. Ask for a new one.');
    }
    if (strlen($code) !== 6 || !hash_equals((string) $row['code_hash'], otp_hash($phone, $purpose, $code))) {
        $left = OTP_MAX_TRIES - ((int) $row['attempts'] + 1);
        return otp_fail(422, 'otp_invalid', $left > 0 ? 'That code is not right. ' . $left . ($left === 1 ? ' try' : ' tries') . ' left.' : 'That code is not right. Ask for a new one.');
    }
    if (db_query('UPDATE login_otps SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL', [$row['id']])->rowCount() !== 1) {
        return otp_fail(400, 'otp_expired', 'This code was already used. Ask for a new one.');
    }
    return ['ok' => true];
}

// ------------------------------------------------------------------
// Email OTP (the login_otps "phone" column holds the address; channel 'email')
// ------------------------------------------------------------------

/** Email codes can be sent: database update 018 applied and SMTP set up (local copies write mail.log instead). */
function email_otp_ready(): bool
{
    static $on = null;
    if ($on !== null) {
        return $on;
    }
    if (!login_methods_ready()) {
        return $on = false;
    }
    require_once APP_ROOT . '/includes/mailer.php';
    $col = db_one("SHOW COLUMNS FROM login_otps LIKE 'channel'");
    return $on = $col && str_contains((string) $col['Type'], "'email'") && (mail_configured() || !is_production());
}

/** "pr****@gmail.com" for messages. */
function email_mask(string $email): string
{
    [$local, $domain] = explode('@', $email, 2) + [1 => ''];
    $keep = min(2, mb_strlen($local));
    return mb_substr($local, 0, $keep) . str_repeat('*', max(3, mb_strlen($local) - $keep)) . '@' . $domain;
}

/**
 * Email a 6-digit login code. Codes go to an active student account, or to an address without any account
 * (that person can then sign up). Staff and disabled accounts get no email, but the reply is the same, so the
 * form never reveals which addresses have accounts. Returns like otp_issue().
 */
function email_otp_issue(string $email): array
{
    if (!email_otp_ready()) {
        return otp_fail(404, 'not_available', 'Email codes are not available right now. Please log in with your password.');
    }
    $last = db_value('SELECT created_at FROM login_otps WHERE phone = ? AND purpose = ? ORDER BY id DESC LIMIT 1', [$email, 'login']);
    if ($last && ($wait = OTP_RESEND_SECONDS - (time() - strtotime((string) $last))) > 0) {
        return otp_fail(429, 'otp_wait', 'Please wait ' . $wait . ' seconds before asking for a new code.', ['resend_in' => $wait]);
    }
    if (!rate_limit('otp-email', $email, 5, 3600) || !rate_limit('otp-ip', client_ip(), 12, 3600)) {
        return otp_fail(429, 'rate_limited', 'Too many codes requested. Please try again in an hour.');
    }
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $account = db_one('SELECT id, user_type, status FROM users WHERE email = ?', [$email]);
    if (!$account || ($account['user_type'] === 'student' && $account['status'] === 'active')) {
        $site = (string) setting('site_name', 'Laxyam Academy');
        $message = fn(string $shown) => mail_template('Your login code',
            '<p>Use this code to log in to ' . e($site) . ':</p>'
            . '<p style="font-size:30px;font-weight:700;letter-spacing:8px;margin:18px 0;color:#0b1f4d">' . $shown . '</p>'
            . '<p>The code works for 10 minutes. If you did not ask for it, you can ignore this email - nobody can log in without the code.</p>'
            . '<p>Never share this code with anyone, not even with our staff.</p>',
            null, null, ['eyebrow' => 'Login code', 'preheader' => $shown . ' is your ' . $site . ' login code. It works for 10 minutes.']);
        // Staff can read the delivery log, so it only ever stores a copy without the code (and it is never retried).
        send_mail($email, 'Your ' . $site . ' login code', $message($code),
            ['template_key' => 'login_otp', 'user_id' => $account ? (int) $account['id'] : null, 'max_attempts' => 1, 'log_html' => $message('••••••')]);
        $logId = (int) ($GLOBALS['__last_email_log_id'] ?? 0);
        $log = $logId ? db_one('SELECT status, transport FROM email_log WHERE id = ?', [$logId]) : null;
        // 'smtp-unconfirmed' = the server took the whole message but did not confirm: it has almost certainly arrived.
        $delivered = $log && ($log['status'] === 'sent' || $log['transport'] === 'smtp-unconfirmed' || ($log['status'] === 'skipped' && !is_production()));
        if (!$delivered) {
            return otp_fail(502, 'otp_not_sent', 'We could not send the code to this email. Please try again or log in with your password.');
        }
    } else {
        $code = bin2hex(random_bytes(16)); // staff / disabled account: nothing is sent and no code can ever match
    }
    // Only the newest code works.
    db_query('UPDATE login_otps SET consumed_at = NOW() WHERE phone = ? AND purpose = ? AND consumed_at IS NULL', [$email, 'login']);
    db_query('INSERT INTO login_otps (phone, purpose, user_id, channel, code_hash, expires_at, ip_address) VALUES (?, ?, NULL, ?, ?, ?, ?)',
        [$email, 'login', 'email', otp_hash($email, 'login', $code), date('Y-m-d H:i:s', time() + OTP_TTL_SECONDS), client_ip()]);
    return ['ok' => true, 'channel' => 'email', 'resend_in' => OTP_RESEND_SECONDS, 'expires_in' => OTP_TTL_SECONDS, 'masked' => email_mask($email)];
}

/** Active student whose mobile number was confirmed by OTP. */
function student_by_verified_phone(string $e164): ?array
{
    return db_one("SELECT * FROM users WHERE user_type = 'student' AND status = 'active' AND phone_verified_at IS NOT NULL AND phone = ? ORDER BY id LIMIT 1", [$e164]);
}

/** Minimal HTTP POST: ['status' => int, 'data' => ?array, 'error' => ?string]. */
function login_http_post(string $url, string $body, array $headers, int $timeout = 10): array
{
    $headers = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => false]);
        $raw = curl_exec($ch);
        return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'data' => json_decode((string) $raw, true), 'error' => $raw === false ? (curl_error($ch) ?: 'request failed') : null];
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    return ['status' => $raw === false ? 0 : 200, 'data' => json_decode((string) $raw, true), 'error' => $raw === false ? 'request failed' : null];
}

/** SMS through MSG91's OTP API (a DLT-approved template with ##OTP##); our own code is sent and checked here. */
function otp_send_sms(string $phone, string $code): array
{
    $base = 'https://control.msg91.com';
    $custom = rtrim((string) config('MSG91_API_BASE', ''), '/'); // local testing only, ignored in production
    if ($custom !== '' && !is_production() && preg_match('#^https?://[^\s/]+(/[^\s]*)?$#i', $custom)) {
        $base = $custom;
    }
    $query = http_build_query(['template_id' => trim((string) config('MSG91_OTP_TEMPLATE_ID', '')), 'mobile' => substr($phone, 1), 'otp' => $code, 'otp_expiry' => intdiv(OTP_TTL_SECONDS, 60)]);
    $r = login_http_post($base . '/api/v5/otp?' . $query, '{}', ['authkey: ' . trim((string) config('MSG91_AUTH_KEY', ''))]);
    if ($r['error'] !== null) {
        return ['ok' => false, 'error' => 'MSG91 unreachable: ' . $r['error']];
    }
    $ok = $r['status'] >= 200 && $r['status'] < 300 && is_array($r['data']) && ($r['data']['type'] ?? '') === 'success';
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'MSG91 HTTP ' . $r['status'] . ': ' . mb_substr((string) ($r['data']['message'] ?? ''), 0, 200)];
}

/** WhatsApp "Authentication" template: the code fills the body variable and the copy-code button. Not logged. */
function otp_send_whatsapp(string $phone, string $code): array
{
    require_once APP_ROOT . '/includes/whatsapp.php';
    $p = whatsapp_normalize_phone($phone, '+91');
    if (!$p) {
        return ['ok' => false, 'error' => 'invalid number'];
    }
    $name = trim((string) config('OTP_WHATSAPP_TEMPLATE', ''));
    $lang = trim((string) config('OTP_WHATSAPP_LANGUAGE', '')) ?: 'en';
    $values = ['body' => [$code], 'button' => ['0' => [$code]]];
    return whatsapp_provider() === 'meta'
        ? whatsapp_send_meta($p, $name, $lang, $values, 10)
        : whatsapp_send_interakt($p, $name, $lang, $values, 'login-otp', 10);
}

// ------------------------------------------------------------------
// Sign in with Google
// ------------------------------------------------------------------

function login_b64url_decode(string $s): string|false
{
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

/** A fresh one-time nonce for the Google button (a few kept per session for several open tabs). */
function google_issue_nonce(): string
{
    start_secure_session();
    $nonce = bin2hex(random_bytes(16));
    $list = array_filter((array) ($_SESSION['google_nonces'] ?? []), fn($t) => (int) $t > time() - GOOGLE_NONCE_SECONDS);
    $list[$nonce] = time();
    $_SESSION['google_nonces'] = array_slice($list, -5, null, true);
    return $nonce;
}

/** Use up a nonce this session issued (true when it was valid). */
function google_take_nonce(string $nonce): bool
{
    start_secure_session();
    $issued = $_SESSION['google_nonces'][$nonce] ?? null;
    unset($_SESSION['google_nonces'][$nonce]);
    return $nonce !== '' && $issued !== null && (int) $issued > time() - GOOGLE_NONCE_SECONDS;
}

/** Google's signing certificates (kid => PEM), cached until their max-age. $force skips the cache (keys rotate). */
function google_certs(bool $force = false): array
{
    $cached = !$force && is_file(GOOGLE_CERTS_CACHE) ? json_decode((string) @file_get_contents(GOOGLE_CERTS_CACHE), true) : null;
    if (is_array($cached) && (int) ($cached['expires'] ?? 0) > time() && !empty($cached['certs'])) {
        return $cached['certs'];
    }
    $maxAge = 3600;
    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init(GOOGLE_CERTS_URL);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$maxAge) {
                if (preg_match('/^cache-control:.*max-age=(\d+)/i', $line, $m)) {
                    $maxAge = max(300, min(86400, (int) $m[1]));
                }
                return strlen($line);
            }]);
        $raw = curl_exec($ch);
        $raw = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 ? $raw : false;
    }
    $certs = $raw !== false ? json_decode((string) $raw, true) : null;
    if (!is_array($certs) || !$certs) {
        return is_array($cached['certs'] ?? null) ? $cached['certs'] : []; // keep the last good set if Google is unreachable
    }
    @file_put_contents(GOOGLE_CERTS_CACHE, json_encode(['expires' => time() + $maxAge, 'certs' => $certs]), LOCK_EX);
    return $certs;
}

/**
 * Verify a Google ID token for our client ID. Returns the claims, or null with $error set.
 * $certs (kid => PEM certificate) can be passed for tests; otherwise Google's current set is used.
 */
function google_verify_id_token(string $jwt, string $clientId, ?array $certs = null, ?string &$error = null, ?int $now = null): ?array
{
    $now ??= time();
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        $error = 'malformed';
        return null;
    }
    [$h64, $p64, $s64] = $parts;
    $header = json_decode((string) login_b64url_decode($h64), true);
    $claims = json_decode((string) login_b64url_decode($p64), true);
    $signature = login_b64url_decode($s64);
    if (!is_array($header) || !is_array($claims) || $signature === false || ($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null)) {
        $error = 'malformed';
        return null;
    }
    $injected = $certs !== null;
    $certs ??= google_certs();
    if (!isset($certs[$header['kid']]) && !$injected) {
        $certs = google_certs(true); // Google rotated its keys since the cache was filled
    }
    $key = isset($certs[$header['kid']]) ? openssl_pkey_get_public((string) $certs[$header['kid']]) : false;
    if (!$key || openssl_verify($h64 . '.' . $p64, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
        $error = 'signature';
        return null;
    }
    $aud = $claims['aud'] ?? null;
    $error = match (true) {
        !in_array($claims['iss'] ?? '', GOOGLE_ISSUERS, true) => 'issuer',
        !(is_string($aud) ? hash_equals($clientId, $aud) : (is_array($aud) && in_array($clientId, $aud, true))) => 'audience',
        (int) ($claims['exp'] ?? 0) < $now - 60 => 'expired',
        (int) ($claims['iat'] ?? 0) > $now + 300 => 'issued_in_future',
        !is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 64 => 'subject',
        !filter_var($claims['email'] ?? '', FILTER_VALIDATE_EMAIL) => 'email',
        !in_array($claims['email_verified'] ?? false, [true, 'true'], true) => 'email_unverified',
        default => null,
    };
    return $error === null ? $claims : null;
}

// ------------------------------------------------------------------
// Accounts
// ------------------------------------------------------------------

/**
 * Create a student account for Google / OTP sign-up (no password yet: a random one is stored, and
 * "Forgot password" sets a real one). $flags: email_verified, phone_verified, google_sub.
 */
function create_student_account(string $name, string $email, ?string $phone, array $flags = [], array $prefs = []): array
{
    $id = db_transaction(function () use ($name, $email, $phone, $flags, $prefs): int {
        db_query(
            "INSERT INTO users (name, email, phone, password_hash, user_type, status, email_verified_at, google_sub, phone_verified_at)
             VALUES (?, ?, ?, ?, 'student', 'active', ?, ?, ?)",
            [mb_substr($name, 0, 120), strtolower($email), $phone, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                !empty($flags['email_verified']) ? date('Y-m-d H:i:s') : null, $flags['google_sub'] ?? null, !empty($flags['phone_verified']) ? date('Y-m-d H:i:s') : null]
        );
        $id = (int) db()->lastInsertId();
        db_query('INSERT INTO student_profiles (user_id, exam_preferences, notify_prefs) VALUES (?, ?, ?)', [$id, json_encode(array_values($prefs)), setting('notify_default_prefs') ?: null]);
        record_attribution('user', $id, request_body()['attribution'] ?? null);
        return $id;
    });
    return db_one('SELECT * FROM users WHERE id = ?', [$id]);
}
