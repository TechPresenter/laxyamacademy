<?php
/**
 * First-party page-view tracking (website traffic report).
 *   POST track.php  JSON: path, referrer, utm_source, utm_medium, utm_campaign, w (viewport width)
 *
 * Privacy: no IP address or cookie is stored. Visitors are counted with a hash of
 * IP + user agent + a secret salt that changes every day, so a visitor cannot be
 * followed across days. Bots and staff are skipped. Disable in admin → Settings.
 * The request also drives the cron fallback (includes/scheduler.php).
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/scheduler.php';

// Cron fallback: after the response is sent (on every exit path), run due jobs if cron is not set up.
register_shutdown_function(function (): void {
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
    maybe_run_scheduler();
});

require_method('POST');
if (setting('analytics_enabled', '1') !== '1' || preg_match('/bot|crawl|spider|slurp|preview|headless/i', user_agent())) {
    api_ok(['tracked' => false]);
}
// Only look up the user when a session already exists - never start one (no cookie for visitors).
if (isset($_COOKIE[(string) config('SESSION_NAME', 'laxyam_sid')]) && (current_user()['user_type'] ?? null) === 'staff') {
    api_ok(['tracked' => false]);
}
$in = request_body();
$path = is_string($in['path'] ?? null) ? '/' . trim(mb_substr(parse_url($in['path'], PHP_URL_PATH) ?: '/', 0, 250), '/') : '/';
if (preg_match('#^/(dashboard|learn|checkout|payment|admin|api)(/|$)#', $path) || !preg_match('#^/[\w\-/]*$#', $path)) {
    api_ok(['tracked' => false]);
}
if (!rate_limit('track', client_ip(), 300, 3600)) {
    api_ok(['tracked' => false]);
}
$ref = is_string($in['referrer'] ?? null) ? strtolower((string) parse_url($in['referrer'], PHP_URL_HOST)) : '';
$ownHost = strtolower((string) parse_url(app_url(), PHP_URL_HOST));
$ref = $ref !== '' && $ref !== $ownHost ? mb_substr(preg_replace('/^www\./', '', $ref), 0, 120) : null;
$utm = fn($k) => is_string($in[$k] ?? null) && preg_match('/^[\w .\-]{1,80}$/u', $in[$k]) ? mb_substr($in[$k], 0, 80) : null;
$w = (int) ($in['w'] ?? 1200);
$device = $w < 640 ? 'mobile' : ($w < 1024 ? 'tablet' : 'desktop');
$salt = hash('sha256', date('Y-m-d') . (string) config('DB_PASS', '') . (string) config('APP_URL', '') . __FILE__);
$visitor = hash('sha256', $salt . client_ip() . user_agent());

db_query('INSERT INTO page_views (day, path, referrer_host, utm_source, utm_medium, utm_campaign, device, visitor_hash) VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?)',
    [$path, $ref, $utm('utm_source'), $utm('utm_medium'), $utm('utm_campaign'), $device, $visitor]);
if (random_int(1, 500) === 1) {
    db_query('DELETE FROM page_views WHERE day < CURDATE() - INTERVAL 400 DAY'); // retention: ~13 months
}
api_ok(['tracked' => true]);
