<?php
/**
 * Front controller for all website (React) routes.
 *  1. Applies redirects managed in admin/seo.php (301/302).
 *  2. Serves the compiled app shell (dist/index.html) with page-specific
 *     <title>, meta description, canonical, Open Graph, robots and JSON-LD,
 *     so search engines and link previews see real content without running JS.
 *  3. Sends a 404 status for unknown courses/posts/tests (the React app still renders its 404 page).
 */
declare(strict_types=1);

require __DIR__ . '/api/config.php';

$base = rtrim((string) parse_url(app_url(), PHP_URL_PATH), '/');
$uriPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$path = '/' . trim(substr($uriPath, strlen($base)), '/');

// 1. Redirects
$redirect = db_one('SELECT id, to_path, status_code FROM redirects WHERE from_path = ? AND is_active = 1', [rtrim($path, '/') ?: '/']);
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

// 2. Page metadata
$site = setting('site_name', 'Laxyam Academy');
$meta = [
    'title' => setting('default_seo_title', $site),
    'description' => setting('default_seo_description', ''),
    'canonical' => app_url(ltrim($path, '/')),
    'image' => public_file_url(setting('default_og_image')) ?? app_url('assets/icons/icon-512.png'),
    'robots' => is_production() ? 'index,follow' : 'noindex,nofollow',
    'type' => 'website',
    'jsonld' => null,
];
$status = 200;
$private = '#^/(dashboard|learn|checkout|payment|enrolled|login|register|forgot-password|reset-password|verify-email|mock-tests/[^/]+/(attempt|result))(/|$)#';

if (preg_match($private, $path)) {
    $meta['robots'] = 'noindex,nofollow';
} elseif (preg_match('#^/courses/([a-z0-9-]+)$#', $path, $m)) {
    $c = db_one("SELECT c.title, c.short_description, c.seo_title, c.seo_description, c.thumbnail_path, c.price, c.discount_price, c.course_type, cat.name AS category
                 FROM courses c JOIN course_categories cat ON cat.id = c.category_id WHERE c.slug = ? AND c.status = 'published'", [$m[1]]);
    if ($c) {
        $meta['title'] = $c['seo_title'] ?: $c['title'] . ' | ' . $site;
        $meta['description'] = $c['seo_description'] ?: (string) $c['short_description'];
        $meta['image'] = public_file_url($c['thumbnail_path']) ?? $meta['image'];
        $meta['jsonld'] = [
            '@context' => 'https://schema.org', '@type' => 'Course',
            'name' => $c['title'], 'description' => mb_substr((string) $c['short_description'], 0, 300),
            'provider' => ['@type' => 'EducationalOrganization', 'name' => $site, 'sameAs' => app_url('')],
        ];
    } else {
        $status = 404;
    }
} elseif (preg_match('#^/blog/([a-z0-9-]+)$#', $path, $m)) {
    $p = db_one("SELECT title, excerpt, seo_title, seo_description, featured_image_path, published_at, updated_at FROM blog_posts WHERE slug = ? AND status IN ('published','scheduled') AND published_at <= NOW()", [$m[1]]);
    if ($p) {
        $meta['title'] = $p['seo_title'] ?: $p['title'] . ' | ' . $site . ' Blog';
        $meta['description'] = $p['seo_description'] ?: (string) $p['excerpt'];
        $meta['image'] = public_file_url($p['featured_image_path']) ?? $meta['image'];
        $meta['type'] = 'article';
    } else {
        $status = 404;
    }
} elseif (preg_match('#^/p/([a-z0-9-]+)$#', $path, $m)) {
    $pg = db_one("SELECT title, seo_title, seo_description, status FROM pages WHERE slug = ? AND status <> 'archived'", [$m[1]]);
    if ($pg && ($pg['status'] === 'published' || (current_user()['user_type'] ?? null) === 'staff')) {
        $meta['title'] = $pg['seo_title'] ?: $pg['title'] . ' | ' . $site;
        $meta['description'] = $pg['seo_description'] ?: $meta['description'];
        if ($pg['status'] !== 'published') {
            $meta['robots'] = 'noindex,nofollow';
        }
    } else {
        $status = 404;
    }
} elseif (preg_match('#^/careers/([a-z0-9-]+)$#', $path, $m)) {
    $job = db_one("SELECT title, description, employment_type, salary_range, closes_at, created_at, responsibilities, requirements FROM job_openings
                   WHERE slug = ? AND status = 'open' AND (closes_at IS NULL OR closes_at >= CURDATE())", [$m[1]]);
    if ($job) {
        $meta['title'] = $job['title'] . ' - Apply Online | ' . $site;
        $meta['description'] = mb_substr((string) ($job['description'] ?: $job['title'] . ' at ' . $site), 0, 300);
        // Google for Jobs (JobPosting structured data)
        $list = fn(?string $json) => implode('', array_map(fn($i) => '<li>' . e((string) $i) . '</li>', json_decode_array($json)));
        $types = ['full_time' => 'FULL_TIME', 'part_time' => 'PART_TIME', 'contract' => 'CONTRACTOR', 'internship' => 'INTERN', 'visiting' => 'OTHER'];
        $meta['jsonld'] = array_filter([
            '@context' => 'https://schema.org', '@type' => 'JobPosting',
            'title' => $job['title'],
            'description' => '<p>' . e((string) $job['description']) . '</p>'
                . ($job['responsibilities'] ? '<p>Responsibilities:</p><ul>' . $list($job['responsibilities']) . '</ul>' : '')
                . ($job['requirements'] ? '<p>Skills and qualifications:</p><ul>' . $list($job['requirements']) . '</ul>' : ''),
            'datePosted' => date('Y-m-d', strtotime($job['created_at'])),
            'validThrough' => $job['closes_at'] ? $job['closes_at'] . 'T23:59:59+05:30' : null,
            'employmentType' => array_values(array_intersect_key($types, array_flip(explode(',', (string) $job['employment_type'])))),
            'hiringOrganization' => ['@type' => 'Organization', 'name' => $site, 'sameAs' => app_url(''), 'logo' => app_url('assets/logo.png')],
            'jobLocation' => ['@type' => 'Place', 'address' => array_filter(['@type' => 'PostalAddress', 'streetAddress' => setting('address'), 'addressLocality' => 'New Delhi', 'addressRegion' => 'Delhi', 'addressCountry' => 'IN'])],
            'directApply' => true,
        ]);
    } else {
        $status = 404;
    }
} elseif (preg_match('#^/jobs/([a-z0-9-]+)$#', $path, $m)) {
    // Govt job / exam alert detail
    $a = db_one("SELECT title, organization, summary FROM job_alerts WHERE slug = ? AND status = 'published' AND (published_at IS NULL OR published_at <= NOW())", [$m[1]]);
    if ($a) {
        $meta['title'] = $a['title'] . ' - ' . $a['organization'] . ' | ' . $site;
        $meta['description'] = mb_substr(trim(strip_tags((string) ($a['summary'] ?: $a['title'] . ' - official notification from ' . $a['organization'] . '.'))), 0, 160);
        $meta['type'] = 'article';
    } else {
        $status = 404;
    }
} elseif (preg_match('#^/gallery/([a-z0-9-]+)$#', $path, $m)) {
    // Photo / video album (admin/gallery.php). Missing tables (update not applied yet) behave like an unknown album.
    try {
        // The chosen cover is ignored when it is the file of a hidden item (same rule as api/gallery.php).
        $al = db_one("SELECT a.title, a.description, a.event_date,
                             CASE WHEN a.cover_image_path IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gallery_items h
                                    WHERE h.album_id = a.id AND h.is_active = 0 AND a.cover_image_path IN (h.image_path, h.thumb_path))
                                  THEN a.cover_image_path END AS cover_image_path,
                             (SELECT COALESCE(i.thumb_path, i.image_path) FROM gallery_items i WHERE i.album_id = a.id AND i.is_active = 1 AND i.type = 'image' ORDER BY i.sort_order, i.id LIMIT 1) AS first_image
                        FROM gallery_albums a WHERE a.slug = ? AND a.is_active = 1", [$m[1]]);
    } catch (PDOException $e) {
        $al = null;
    }
    if ($al) {
        $meta['title'] = $al['title'] . ' - Photo Gallery | ' . $site;
        $meta['description'] = mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) ($al['description']
            ?: 'Photos and videos from ' . $al['title'] . ($al['event_date'] ? ' (' . date('j M Y', strtotime($al['event_date'])) . ')' : '') . ' at ' . $site . '.'))), 0, 160);
        $meta['image'] = public_file_url($al['cover_image_path'] ?: $al['first_image']) ?? $meta['image'];
    } else {
        $status = 404;
    }
} elseif (preg_match('#^/resources/([a-z0-9-]+)$#', $path, $m)) {
    $r = db_one("SELECT title, description, seo_title, seo_description FROM learning_resources WHERE slug = ? AND status = 'published'", [$m[1]]);
    if ($r) {
        $meta['title'] = $r['seo_title'] ?: $r['title'] . ' | ' . $site;
        $meta['description'] = $r['seo_description'] ?: mb_substr(strip_tags((string) $r['description']), 0, 160);
    } else {
        $status = 404;
    }
} else {
    if ($path === '/gallery') {
        // Defaults for the gallery index (admin → SEO can still override them for this path)
        $meta['title'] = 'Photo & Video Gallery | ' . $site;
        $meta['description'] = 'Photos and videos from ' . $site . ' - classroom moments, events, topper felicitations and campus life.';
    }
    // Mock test detail: a real 404 for unknown or unpublished tests (like courses and resources).
    if (preg_match('#^/mock-tests/([a-z0-9-]+)$#', $path, $m)) {
        $ex = db_one("SELECT title, description FROM exams WHERE slug = ? AND status = 'published'", [$m[1]]);
        if ($ex) {
            $meta['title'] = $ex['title'] . ' - Mock Test | ' . $site;
            $desc = mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $ex['description']))), 0, 160);
            $meta['description'] = $desc !== '' ? $desc : $meta['description'];
        } else {
            $status = 404;
        }
    }
    $seo = db_one('SELECT title, meta_description, canonical_url, og_title, og_description, og_image, robots, structured_data FROM seo_pages WHERE path = ?', [$path]);
    if ($seo) {
        $meta['title'] = $seo['title'] ?: $meta['title'];
        $meta['description'] = $seo['meta_description'] ?: $meta['description'];
        // Admin → SEO "Open Graph title/description" are used for link previews only
        $meta['og_title'] = $seo['og_title'] ?: null;
        $meta['og_description'] = $seo['og_description'] ?: null;
        $meta['canonical'] = $seo['canonical_url'] ?: $meta['canonical'];
        $meta['image'] = public_file_url($seo['og_image']) ?? $meta['image'];
        if (is_production()) {
            $meta['robots'] = $seo['robots'];
        }
        $meta['jsonld'] = json_decode_array($seo['structured_data']) ?: null;
    }
    // Real 404 status (not a "soft 404") for paths that are not routes in js/App.jsx.
    $routes = '#^/(|courses|categories|mock-tests(/[a-z0-9-]+(/(attempt|result)/\d+)?)?|live-classes|resources|blog|about|contact|privacy-policy|terms'
        . '|refund-policy|cancellation-policy|disclaimer|cookie-policy|jobs|careers|faq|gallery(/[a-z0-9-]+)?|login|register|forgot-password|reset-password|verify-email'
        . '|dashboard(/(courses|tests|live-classes|resources|purchases|notifications|profile|certificates|wishlist|settings|support))?|learn/[a-z0-9-]+(/\d+)?'
        . '|checkout/[a-z0-9-]+|payment/result|enrolled/[a-z0-9-]+)$#';
    if (!$seo && !preg_match($routes, $path)) {
        $status = 404;
    }
    if ($path === '/') {
        $meta['jsonld'] ??= [
            '@context' => 'https://schema.org', '@type' => 'EducationalOrganization',
            'name' => $site, 'url' => app_url(''), 'logo' => app_url('assets/logo.png'),
            'telephone' => setting('contact_phone'), 'email' => setting('contact_email'),
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => setting('address'), 'addressLocality' => 'New Delhi', 'addressCountry' => 'IN'],
        ];
    }
}

if ($status === 404) {
    $meta['title'] = 'Page not found | ' . $site;
    $meta['robots'] = 'noindex,follow';
}

$tags = '<meta name="description" content="' . e($meta['description']) . '">'
    . '<link rel="canonical" href="' . e($meta['canonical']) . '">'
    . '<meta name="robots" content="' . e($meta['robots']) . '">'
    . '<meta property="og:site_name" content="' . e($site) . '">'
    . '<meta property="og:locale" content="en_IN">'
    . '<meta property="og:type" content="' . e($meta['type']) . '">'
    . '<meta property="og:title" content="' . e($meta['og_title'] ?? $meta['title']) . '">'
    . '<meta property="og:description" content="' . e($meta['og_description'] ?? $meta['description']) . '">'
    . '<meta property="og:url" content="' . e($meta['canonical']) . '">'
    . '<meta property="og:image" content="' . e((string) $meta['image']) . '">'
    . '<meta name="twitter:card" content="summary_large_image">'
    . ($meta['jsonld'] ? '<script type="application/ld+json">' . json_encode($meta['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>' : '');

// Analytics / marketing tags configured in admin/integrations.php (validated public IDs only).
$isPrivate = (bool) preg_match($private, $path);
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
        $tags .= '<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});var f=d.getElementsByTagName(s)[0],j=d.createElement(s);j.async=true;j.src="https://www.googletagmanager.com/gtm.js?id="+i;f.parentNode.insertBefore(j,f);})(window,document,"script","dataLayer","' . $gtm . '");</script>';
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

// Admin → Settings → Spam protection: reCAPTCHA v3 (site key is public; the secret stays in .env)
if (recaptcha_enabled() && preg_match('/^[\w-]{20,60}$/', (string) setting('recaptcha_site_key'))) {
    $key = (string) setting('recaptcha_site_key');
    $tags .= '<script>window.LX_RECAPTCHA_KEY="' . $key . '"</script><script async src="https://www.google.com/recaptcha/api.js?render=' . $key . '"></script>';
}

// Replace the shell's default title/description/og tags with page-specific ones.
// A callback, so "$1" / "\1" in an admin-entered title stay literal text (not regex backreferences).
$title = '<title>' . e((string) $meta['title']) . '</title>';
$html = preg_replace_callback('#<title>.*?</title>#s', fn() => $title, $shell, 1);
$html = preg_replace('#\s*<meta (name="(description|theme-color)"|property="og:(type|title|description|site_name)")[^>]*>#', '', $html);
$html = str_replace('</head>', $tags . '</head>', $html);

// Maintenance mode: tell crawlers it is temporary (staff still get a normal response).
if (setting('maintenance_mode', '0') === '1' && (current_user()['user_type'] ?? null) !== 'staff') {
    $status = 503;
    header('Retry-After: 3600');
}

http_response_code($status);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
