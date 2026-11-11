<?php
/**
 * Staff sign-in hardening: TOTP two-factor authentication (RFC 6238, works with
 * Google Authenticator / Microsoft Authenticator / Authy), recovery codes, and
 * admin access policies (IP allowlist, access hours).
 *
 * Emergency: if an allowlist locks everyone out, set ADMIN_ACCESS_POLICY_OFF=1 in .env.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

// ------------------------------------------------------------------
// TOTP
// ------------------------------------------------------------------

function base32_encode(string $bin): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode(string $b32): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32))) as $c) {
        $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_new_secret(): string
{
    return base32_encode(random_bytes(20));
}

function totp_code(string $secret, int $step): string
{
    $h = hash_hmac('sha1', pack('J', $step), base32_decode($secret), true);
    $o = ord($h[19]) & 0x0F;
    $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($n % 1_000_000), 6, '0', STR_PAD_LEFT);
}

/** Accepts the current code ±1 step (30 s clock drift). Each step can be used only once per user. */
function totp_verify(string $secret, string $code, int $userId): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) {
        return false;
    }
    $now = intdiv(time(), 30);
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(totp_code($secret, $now + $i), $code)) {
            return rate_limit('totp-used', $userId . ':' . ($now + $i), 1, 120); // replay protection
        }
    }
    return false;
}

function totp_uri(string $secret, string $email): string
{
    $issuer = setting('site_name', 'Laxyam Academy') . ' Admin';
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}

/** 10 one-time recovery codes: returns [plain codes to show once, hashes to store]. */
function make_recovery_codes(): array
{
    $plain = [];
    for ($i = 0; $i < 10; $i++) {
        $c = strtoupper(bin2hex(random_bytes(5)));
        $plain[] = substr($c, 0, 5) . '-' . substr($c, 5);
    }
    return [$plain, array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $plain)];
}

/** Use (and burn) a recovery code. */
function use_recovery_code(int $userId, string $code): bool
{
    $code = strtoupper(trim($code));
    $hashes = json_decode_array(db_value('SELECT recovery_codes FROM users WHERE id = ?', [$userId]));
    foreach ($hashes as $i => $h) {
        if (password_verify($code, $h)) {
            unset($hashes[$i]);
            db_query('UPDATE users SET recovery_codes = ? WHERE id = ?', [json_encode(array_values($hashes)), $userId]);
            return true;
        }
    }
    return false;
}

// ------------------------------------------------------------------
// Access policies
// ------------------------------------------------------------------

/** True when $ip matches an IPv4/IPv6 address or CIDR range. */
function ip_in_range(string $ip, string $rule): bool
{
    [$net, $bits] = array_pad(explode('/', trim($rule), 2), 2, null);
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton((string) $net);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;
    }
    $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
    $bytes = intdiv($bits, 8);
    if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
        return false;
    }
    $rem = $bits % 8;
    return $rem === 0 || ((ord($ipBin[$bytes]) ^ ord($netBin[$bytes])) & (0xFF << (8 - $rem)) & 0xFF) === 0;
}

function valid_ip_rule(string $rule): bool
{
    [$net, $bits] = array_pad(explode('/', trim($rule), 2), 2, null);
    $bin = @inet_pton((string) $net);
    return $bin !== false && ($bits === null || (ctype_digit($bits) && (int) $bits <= strlen($bin) * 8));
}

function admin_ip_rules(): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) setting('admin_ip_allowlist', '')))));
}

/** ['days' => [1..7 ISO], 'start' => 'HH:MM', 'end' => 'HH:MM'] or null when unrestricted. */
function admin_hours(): ?array
{
    $h = json_decode_array(setting('admin_access_hours', ''));
    return !empty($h['start']) && !empty($h['end']) && !empty($h['days']) ? $h : null;
}

/** Reason the request may not use the admin panel, or null when allowed. Super Admins are exempt from hours (never locked out by a schedule). */
function admin_access_denied(array $user): ?string
{
    if ((string) config('ADMIN_ACCESS_POLICY_OFF', '0') === '1') {
        return null;
    }
    $rules = admin_ip_rules();
    if ($rules && !array_filter($rules, fn($r) => ip_in_range(client_ip(), $r))) {
        return 'Admin access is not allowed from this network (' . client_ip() . ').';
    }
    $hours = admin_hours();
    if ($hours && !is_super_admin($user)) {
        $day = (int) date('N');
        $t = date('H:i');
        if (!in_array($day, array_map('intval', $hours['days']), true) || $t < $hours['start'] || $t >= $hours['end']) {
            return 'Admin access is allowed only during working hours (' . $hours['start'] . ' - ' . $hours['end'] . ').';
        }
    }
    return null;
}
