<?php
/**
 * Helpers shared by the Security & staff screens (admin/staff-accounts.php via users.php?type=staff,
 * admin/roles.php, admin/security.php): device labels, effective module access, staff role rules and
 * the staff invite / password-reset email (single-use token from password_reset_tokens).
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** "Chrome on Windows" style label from a User-Agent string. */
function sec_device_label(?string $ua): string
{
    $ua = (string) $ua;
    if ($ua === '') {
        return 'Unknown device';
    }
    $browser = match (true) {
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
        str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
        str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
        str_contains($ua, 'Safari/') => 'Safari',
        (bool) preg_match('/curl|python|node|wget|bot|spider/i', $ua) => 'Script / bot',
        default => 'Browser',
    };
    $os = match (true) {
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Android') => 'Android',
        (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
        str_contains($ua, 'Mac OS') => 'macOS',
        str_contains($ua, 'CrOS') => 'ChromeOS',
        str_contains($ua, 'Linux') => 'Linux',
        default => '',
    };
    return $os !== '' ? "{$browser} on {$os}" : $browser;
}

/** Icon key for a device label (phone vs computer). */
function sec_device_icon(?string $ua): string
{
    return preg_match('/Android|iPhone|iPad|Mobile/i', (string) $ua) ? 'phone' : 'monitor';
}

/**
 * Admin modules a set of permissions opens (from the real sidebar/tab-bar menu).
 * @return array<int,array{0:string,1:string,2:int,3:int}> [label, icon, screens visible, screens total]
 */
function sec_modules_for(array $permSlugs, bool $super = false): array
{
    $out = [];
    foreach (admin_menu() as $m) {
        [, $label, $ico, $items] = $m;
        $n = 0;
        foreach ($items as $c) {
            $p = $c[2] ?? null;
            if ($super || $p === null || $p === '' || in_array($p, $permSlugs, true)) {
                $n++;
            }
        }
        if ($n) {
            $out[] = [$label, $ico, $n, count($items)];
        }
    }
    return $out;
}

/** The menu as [label, icon, [permission slugs used by its screens], always-visible] for the live role preview. */
function sec_menu_permission_map(): array
{
    $out = [];
    foreach (admin_menu() as $m) {
        [, $label, $ico, $items] = $m;
        $perms = [];
        $open = false;
        foreach ($items as $c) {
            $p = $c[2] ?? null;
            if ($p === null || $p === '') {
                $open = true;
            } else {
                $perms[$p] = true;
            }
        }
        $out[] = ['label' => $label, 'icon' => $ico, 'perms' => array_keys($perms), 'open' => $open];
    }
    return $out;
}

/** Permission slugs per role id (super_admin has none stored: it implicitly holds all). */
function sec_role_permissions(): array
{
    $map = [];
    foreach (db_all('SELECT rp.role_id, p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id') as $r) {
        $map[(int) $r['role_id']][] = $r['slug'];
    }
    return $map;
}

/**
 * Can $actor give (or take away) this role? Super Admins: any role. Other staff with roles.manage:
 * never Super Admin, and only roles whose permissions they hold themselves (no privilege escalation).
 */
function sec_role_assignable(array $role, array $actor, array $rolePerms): bool
{
    if (is_super_admin($actor)) {
        return true;
    }
    if ($role['slug'] === 'super_admin') {
        return false;
    }
    return !array_diff($rolePerms[(int) $role['id']] ?? [], user_permission_slugs((int) $actor['id']));
}

function sec_active_super_admins(): int
{
    return (int) db_value("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id JOIN users u ON u.id = ur.user_id
                           WHERE r.slug = 'super_admin' AND u.status = 'active' AND u.user_type = 'staff'");
}

/** Where a staff password link points: the staff reset screen when it exists, else the public reset page (same token table). */
function sec_staff_reset_url(string $token): string
{
    return is_file(APP_ROOT . '/admin/reset-password.php')
        ? admin_url('reset-password.php?token=' . $token)
        : app_url('reset-password?token=' . $token);
}

/**
 * Email a staff member a single-use "set your password" link.
 * $kind: 'invite' (new account, 72 h), 'reset' (link only, 24 h) or 'forced' (old password disabled, 24 h).
 * The token is redacted from the stored email_log copy once the message has left (so staff who can read
 * delivery logs cannot take over the account), which is why it is never retried automatically.
 * Returns ['sent' => bool, 'link' => ?string] - the link is returned only when the email was NOT delivered,
 * so the acting Super Admin can pass it on securely.
 */
function sec_send_staff_link(array $user, string $kind, array $actor, array $roleNames = []): array
{
    require_once APP_ROOT . '/includes/mailer.php';
    $hours = $kind === 'invite' ? 72 : 24;
    $token = create_user_token('password_reset_tokens', (int) $user['id'], $hours * 60);
    $url = sec_staff_reset_url($token);
    $site = (string) setting('site_name', 'Laxyam Academy');
    $login = admin_url('login.php');
    $hello = '<p>Hello ' . e((string) $user['name']) . ',</p>';
    $after = '<p>The link works once and expires in ' . $hours . ' hours. Afterwards, sign in to the admin panel at <a href="' . e($login) . '">' . e($login) . '</a>.</p>';
    [$subject, $title, $body, $button] = match ($kind) {
        'invite' => [
            'Your ' . $site . ' staff account',
            'You’re invited to the admin panel',
            $hello . '<p>' . e((string) $actor['name']) . ' created a staff account for you on ' . e($site) . ($roleNames ? ' with the role' . (count($roleNames) > 1 ? 's' : '') . ' <strong>' . e(implode(', ', $roleNames)) . '</strong>' : '') . '.</p><p>Choose your password to activate it.</p>' . $after,
            'Set my password',
        ],
        'forced' => [
            'Choose a new password for your ' . $site . ' staff account',
            'Please choose a new password',
            $hello . '<p>' . e((string) $actor['name']) . ' asked you to choose a new password. Your old password no longer works and you have been signed out on every device.</p>' . $after,
            'Choose a new password',
        ],
        default => [
            'Reset your ' . $site . ' staff password',
            'Reset your password',
            $hello . '<p>' . e((string) $actor['name']) . ' sent you a link to choose a new password for your staff account. If you did not expect this, you can ignore it - your current password keeps working.</p>' . $after,
            'Reset password',
        ],
    };
    $sent = send_mail((string) $user['email'], $subject, mail_template($title, $body, $button, $url, ['eyebrow' => 'Staff account', 'preheader' => 'This link expires in ' . $hours . ' hours.']),
        ['template_key' => 'staff_' . $kind, 'user_id' => (int) $user['id'], 'to_name' => (string) $user['name'], 'related' => ['user', (int) $user['id']], 'max_attempts' => 1]);
    $logId = (int) ($GLOBALS['__last_email_log_id'] ?? 0);
    if ($logId) {
        try {
            db_query('UPDATE email_log SET body_html = REPLACE(body_html, ?, ?) WHERE id = ?', [$token, 'link-hidden-for-security', $logId]);
        } catch (Throwable) {
            // older schema: nothing to redact
        }
    }
    audit_log('staff_password_link_sent', 'user', (int) $user['id'], ['kind' => $kind, 'delivered' => $sent], 'warning');
    return ['sent' => $sent, 'link' => $sent ? null : $url];
}

/** Friendly names for the rate-limit buckets used in the code. */
const SEC_RATE_LIMIT_LABELS = [
    'register' => 'Student registrations', 'forgot' => 'Password reset requests', 'forgot-email' => 'Password reset requests (same email)',
    'verify-resend' => 'Verification email resends', 'enquiry' => 'Enquiry & callback forms', 'newsletter' => 'Newsletter sign-ups',
    'unsubscribe' => 'Newsletter unsubscribe links', 'job-apply' => 'Job applications', 'order-attempt' => 'Checkout attempts',
    'order-create' => 'Orders created', 'enrol-free' => 'Free course enrolments', 'doc-upload' => 'Student document uploads',
    'ticket' => 'Support tickets', 'certificate-verify' => 'Certificate verification', 'course-review' => 'Course reviews',
    'search' => 'Site search', 'banner-click' => 'Banner click counting', 'popup-' => 'Popup view/click counting',
    'track' => 'Page-view tracking', 'chat_message' => 'Chatbot messages', 'chat_message_day' => 'Chatbot messages (daily)',
    'api-v1' => 'Public API (per key)', 'invoice-resend' => 'Invoice re-sends', '2fa' => 'Admin two-step code tries',
    'settings-test-email' => 'Test emails from Settings', 'totp-used' => 'Two-step code replay guard',
];

/**
 * Every rate_limit('bucket', key, max, seconds) call in the code base, read from the source so this list
 * never drifts from what is enforced. Cached per request.
 * @return array<string,array{bucket:string,max:int,window:int,per:string,where:string}>
 */
function sec_rate_limits(): array
{
    static $found = null;
    if ($found !== null) {
        return $found;
    }
    $found = [];
    foreach (array_merge(glob(APP_ROOT . '/api/*.php') ?: [], glob(APP_ROOT . '/admin/*.php') ?: [], glob(APP_ROOT . '/includes/*.php') ?: []) as $file) {
        $src = (string) @file_get_contents($file);
        if (!str_contains($src, 'rate_limit(\'')) {
            continue;
        }
        preg_match_all("/rate_limit\\('([a-z0-9_-]+)'(?:\\s*\\.\\s*\\\$\\w+)?,\\s*(.+?),\\s*(\\d+),\\s*(\\d+)\\)/", $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $hit) {
            $bucket = $hit[1][0];
            $keyExpr = $hit[2][0];
            $per = match (true) {
                str_contains($keyExpr, 'client_ip') || str_contains($keyExpr, '$ip') => 'IP address',
                str_contains($keyExpr, 'email') => 'email address',
                str_contains($keyExpr, 'keyRow') => 'API key',
                str_contains($keyExpr, 'order') || str_contains($keyExpr, '$inv') => 'order',
                str_contains($keyExpr, '$userId') => 'account + time step',
                default => 'account',
            };
            $line = substr_count(substr($src, 0, $hit[0][1]), "\n") + 1;
            $found[$bucket . '|' . $hit[3][0] . '|' . $hit[4][0]] ??= [
                'bucket' => $bucket, 'max' => (int) $hit[3][0], 'window' => (int) $hit[4][0], 'per' => $per,
                'where' => basename(dirname($file)) . '/' . basename($file) . ':' . $line,
            ];
        }
    }
    uasort($found, fn($a, $b) => strcmp($a['bucket'], $b['bucket']));
    return $found;
}

/** "5 per hour" style text for a limit. */
function sec_limit_text(int $max, int $seconds): string
{
    $span = match (true) {
        $seconds % 86400 === 0 => ($seconds / 86400 === 1 ? 'day' : ($seconds / 86400) . ' days'),
        $seconds % 3600 === 0 => ($seconds / 3600 === 1 ? 'hour' : ($seconds / 3600) . ' hours'),
        $seconds % 60 === 0 => ($seconds / 60) . ' min',
        default => $seconds . ' s',
    };
    return number_format($max) . ' per ' . $span;
}
