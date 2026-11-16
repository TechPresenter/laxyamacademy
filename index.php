<?php
/**
 * Front controller for all website (React) routes.
 *  1. One URL per page: 301 from duplicate / trailing slashes, upper case, /index.php and the old
 *     /courses?category=<slug> filter URL (now /categories/<slug>), then the redirects managed in admin → SEO.
 *  2. Serves the compiled app shell (dist/index.html) with the page's <title>, meta description, canonical,
 *     robots, Open Graph / X card and JSON-LD from includes/seo.php, plus a <noscript> text version of the page,
 *     so search engines, AI search bots and link previews see real content without running JavaScript.
 *  3. Sends a real 404 status for unknown routes, courses, posts and tests (the React app still renders its
 *     404 page) and records it for admin → SEO → 404 Monitor.
 */
declare(strict_types=1);

require __DIR__ . '/api/config.php';
require_once APP_ROOT . '/includes/seo.php';

$base = rtrim((string) parse_url(app_url(), PHP_URL_PATH), '/');
$uriPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$path = '/' . trim(substr($uriPath, strlen($base)), '/');
$rawPath = substr($uriPath, strlen($base)) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// 1a. Canonical URL form (GET/HEAD only, so form posts are never redirected)
if (in_array($method, ['GET', 'HEAD'], true) && ($to = seo_normalize_request($rawPath, $_GET))) {
    header('Location: ' . $base . $to, true, 301);
    exit;
}

// 1b. Redirects from admin → SEO → Redirects
$redirect = db_one('SELECT id, to_path, status_code FROM redirects WHERE from_path = ? AND is_active = 1', [$path]);
if ($redirect) {
    db_query('UPDATE redirects SET hits = hits + 1 WHERE id = ?', [$redirect['id']]);
    $to = str_starts_with($redirect['to_path'], '/') ? $base . $redirect['to_path'] : $redirect['to_path'];
    header('Location: ' . $to, true, (int) $redirect['status_code'] === 302 ? 302 : 301);
    exit;
}

$shell = @file_get_contents(__DIR__ . '/dist/index.html');
if ($shell === false) {
    http_response_code(503);
    exit('The website is being updated. Please try again in a minute. (Build missing: run "npm run build".)');
}

// 2. Page metadata (includes/seo.php)
$meta = seo_resolve($path, $_GET);
$status = $meta['status'];
$site = seo_site()['name'];
$isPrivate = $meta['kind'] === 'private';
if ($status === 404) {
    seo_log_404($path);
}

$tags = seo_head_tags($meta);
// The same values for the React app's first render (useRouteSeo), so it does not ask the API again.
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
$tags .= '<script>window.LX_SEO=' . json_encode(['key' => $path . ($query !== '' ? '?' . $query : '')] + seo_client_meta($meta),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';

// Analytics / marketing tags configured in admin/integrations.php (validated public IDs only).
$tagId = fn(string $k, string $re) => preg_match($re, (string) setting($k, '')) ? (string) setting($k) : null;
if ($gsc = $tagId('gsc_verification', '/^[A-Za-z0-9_-]{20,80}$/')) {
    $tags .= '<meta name="google-site-verification" content="' . e($gsc) . '">';
}
if (!$isPrivate) {
    $ga = $tagId('ga_measurement_id', '/^G-[A-Z0-9]{4,20}$/');
    $ads = $tagId('google_ads_id', '/^AW-\d{6,15}$/');
    $label = $tagId('google_ads_label', '/^[A-Za-z0-9_-]{6,40}$/');
    if ($ga || $ads) {
        $tags .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . e($ga ?: $ads) . '"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag("js",new Date());'
            . ($ga ? 'gtag("config","' . $ga . '");' : '') . ($ads ? 'gtag("config","' . $ads . '");' : '')
            . ($ads && $label ? 'window.LX_ADS_SEND_TO="' . $ads . '/' . $label . '";' : '') . '</script>';
    }
    if ($gtm = $tagId('gtm_container_id', '/^GTM-[A-Z0-9]{4,12}$/')) {
        // LX_GTM: js/track.js also pushes its events (generate_lead, phone_click …) to the dataLayer for GTM
        $tags .= '<script>window.LX_GTM=1;(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});var f=d.getElementsByTagName(s)[0],j=d.createElement(s);j.async=true;j.src="https://www.googletagmanager.com/gtm.js?id="+i;f.parentNode.insertBefore(j,f);})(window,document,"script","dataLayer","' . $gtm . '");</script>';
    }
    if ($pixel = $tagId('meta_pixel_id', '/^\d{8,20}$/')) {
        $tags .= '<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");fbq("init","' . $pixel . '");fbq("track","PageView");</script>';
    }
}

// Admin → Appearance: brand colours, font and corners as CSS variables (validated hex only).
$hex = fn(string $k) => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) setting($k, '')) ? strtoupper((string) setting($k)) : null;
$mix = function (string $c, string $with, float $t): string {
    [$a, $b] = [sscanf($c, '#%02x%02x%02x'), sscanf($with, '#%02x%02x%02x')];
    return sprintf('#%02X%02X%02X', ...array_map(fn($i) => (int) round($a[$i] + ($b[$i] - $a[$i]) * $t), [0, 1, 2]));
};
$vars = [];
if (($p = $hex('theme_primary')) && $p !== '#0B63F6') {
    $vars += ['--color-primary' => $p, '--color-primary-dark' => $mix($p, '#000000', 0.18), '--color-primary-soft' => $mix($p, '#FFFFFF', 0.9)];
}
if (($n = $hex('theme_navy')) && $n !== '#08264D') {
    $vars += ['--color-navy' => $n, '--color-navy-dark' => $mix($n, '#000000', 0.25)];
}
if (($g = $hex('theme_gold')) && $g !== '#FFC928') {
    $vars['--color-gold'] = $g;
}
// Inter (body) and Plus Jakarta Sans (headings) are self-hosted in the CSS bundle; Google Fonts is
// only used when the admin picks one of the alternative body fonts below.
$fonts = ['Poppins' => 'Poppins:wght@400;500;600;700', 'Roboto' => 'Roboto:wght@400;500;700', 'Noto Sans' => 'Noto+Sans:wght@400;500;600;700', 'Mukta' => 'Mukta:wght@400;500;600;700'];
$font = (string) setting('theme_font', 'Inter');
$preloadFonts = ['plus-jakarta-sans-latin-wght-normal'];
if (isset($fonts[$font])) {
    $tags .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=' . $fonts[$font] . '&display=swap">';
    $vars['--font-sans'] = '"' . $font . '", "Segoe UI", system-ui, sans-serif';
} elseif ($font === 'system') {
    $vars['--font-sans'] = 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
} else {
    $preloadFonts[] = 'inter-latin-wght-normal';
}
// Preload the Latin subsets of the self-hosted fonts (hashed file names from the Vite build)
foreach ($preloadFonts as $name) {
    $file = glob(__DIR__ . '/dist/static/' . $name . '-*.woff2')[0] ?? null;
    if ($file) {
        $tags .= '<link rel="preload" href="' . e($base . '/dist/static/' . basename($file)) . '" as="font" type="font/woff2" crossorigin>';
    }
}
// Preload the header logo (custom upload from admin → Appearance, else the bundled WebP)
$logoPath = (string) setting('logo_path', '');
$logoUrl = $logoPath !== '' ? public_file_url($logoPath) : $base . '/assets/logo.webp';
if ($logoUrl) {
    $tags .= '<link rel="preload" as="image" href="' . e($logoUrl) . '" fetchpriority="high"' . ($logoPath === '' ? ' type="image/webp"' : '') . '>';
}
// Browser UI colour (mobile address bar) follows the brand navy
$tags .= '<meta name="theme-color" content="' . e($hex('theme_navy') ?: '#08264D') . '">';
$radius = ['sharp' => ['--radius-lg' => '4px', '--radius-xl' => '6px', '--radius-2xl' => '8px', '--radius-3xl' => '10px'],
    'rounded' => ['--radius-lg' => '12px', '--radius-xl' => '16px', '--radius-2xl' => '22px', '--radius-3xl' => '30px']][setting('theme_radius', 'standard')] ?? [];
$vars += $radius;
if ($vars) {
    $tags .= '<style id="lx-theme">:root{' . implode('', array_map(fn($k, $v) => $k . ':' . $v . ';', array_keys($vars), $vars)) . '}</style>';
}

// Admin → Appearance → favicon (generated PNG set)
if (preg_match('#^uploads/branding/favicon-[a-f0-9]{8}$#', (string) setting('favicon_path', ''))) {
    $fav = (string) setting('favicon_path');
    $shell = preg_replace('#\s*<link rel="(icon|apple-touch-icon)"[^>]*>#', '', $shell);
    $tags .= '<link rel="icon" type="image/png" sizes="32x32" href="' . e(app_url($fav . '-32.png')) . '">'
        . '<link rel="icon" type="image/png" sizes="192x192" href="' . e(app_url($fav . '-192.png')) . '">'
        . '<link rel="apple-touch-icon" href="' . e(app_url($fav . '-180.png')) . '">';
}

// Admin → Settings → Spam protection: reCAPTCHA v3 (site key is public; the secret stays in .env).
// Only the key is printed: js/api.js loads Google's script when a visitor starts filling in a form,
// so the ~300 KB of reCAPTCHA code no longer slows down every page view.
if (recaptcha_enabled() && preg_match('/^[\w-]{20,60}$/', (string) setting('recaptcha_site_key'))) {
    $tags .= '<script>window.LX_RECAPTCHA_KEY="' . (string) setting('recaptcha_site_key') . '"</script><link rel="dns-prefetch" href="https://www.google.com">';
}

// Replace the shell's default title/description/og tags with page-specific ones.
// A callback, so "$1" / "\1" in an admin-entered title stay literal text (not regex backreferences).
$title = '<title>' . e((string) $meta['title']) . '</title>';
$html = preg_replace_callback('#<title>.*?</title>#s', fn() => $title, $shell, 1);
$html = preg_replace('#\s*<meta (name="(description|theme-color)"|property="og:(type|title|description|site_name)")[^>]*>#', '', $html);
$html = str_replace('</head>', $tags . '</head>', $html);
// Text version of the page for visitors and crawlers without JavaScript (replaces the shell's notice)
$noscript = seo_noscript($meta);
$html = preg_replace_callback('#<noscript>.*?</noscript>#s', fn() => $noscript, $html, 1);

// Maintenance mode: tell crawlers it is temporary (staff still get a normal response).
if (setting('maintenance_mode', '0') === '1' && (current_user()['user_type'] ?? null) !== 'staff') {
    $status = 503;
    header('Retry-After: 3600');
}

http_response_code($status);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
