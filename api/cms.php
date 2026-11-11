<?php
/**
 * Public website content API (read-only; editing happens in admin/cms.php).
 *
 *   GET cms.php?action=layout          site settings, announcement bar, footer, nav categories, highlights, jobs menu
 *   GET cms.php?action=home            all homepage sections in one request (+ 'order' of visible sections)
 *   GET cms.php?action=videos&placement=home|courses|about|course[&course=<slug>]   YouTube + Instagram sections
 *   GET cms.php?action=banners&placement=home|courses|course_detail                 promotional banners
 *   GET cms.php?action=banner-click&id=N                                            click beacon
 *   GET cms.php?action=seo&path=/x     SEO metadata for a static page
 *   GET /sitemap.xml  -> cms.php?action=sitemap
 *   GET /robots.txt   -> cms.php?action=robots
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/catalog.php';
require_once APP_ROOT . '/includes/media.php';
require_once APP_ROOT . '/includes/chatbot.php';

require_method('GET');

/** Homepage blocks (cms_sections keys) in the order the admin can rearrange them. */
const HOME_SECTION_KEYS = [
    'hero', 'features', 'courses', 'categories', 'why_choose', 'stats', 'live_classes', 'job_alerts',
    'resources', 'success_stories', 'testimonials', 'videos_youtube', 'videos_instagram', 'newsletter', 'cta',
];

const BLOG_COLUMNS = "p.id, p.title, p.slug, p.excerpt, p.featured_image_path, p.tags, p.published_at, p.views, p.is_demo,
    bc.name AS category_name, bc.slug AS category_slug, u.name AS author_name";
const BLOG_FROM = "FROM blog_posts p LEFT JOIN blog_categories bc ON bc.id = p.category_id LEFT JOIN users u ON u.id = p.author_id
    WHERE p.status IN ('published','scheduled') AND p.published_at <= NOW()";

match ($_GET['action'] ?? '') {
    'layout' => api_ok(cache_remember('layout', 300, 'layout_data'), 200, true),
    'home' => api_ok(cache_remember('home', 300, 'home_data'), 200, true),
    'videos' => videos_action(),
    'banners' => banners_action(),
    'banner-click' => banner_click(),
    'custom-page' => custom_page(query_str('slug', 160)),
    'popup-event' => popup_event(),
    'seo' => api_ok(seo_data(query_str('path', 255) ?: '/'), 200, true),
    'page' => api_ok(content_page(query_str('key', 40)), 200, true),
    'faqs' => api_ok(db_all('SELECT category, question, answer FROM faqs WHERE is_active = 1 ORDER BY sort_order, id'), 200, true),
    'blog' => api_ok(blog_list(), 200, true),
    'blog-post' => api_ok(blog_post(query_str('slug', 220)), 200, true),
    'sitemap' => sitemap(),
    'robots' => robots(),
    'assetlinks' => assetlinks(),
    default => api_error('Unknown action.', 404, 'not_found'),
};

/** Active CMS blocks keyed by section_key. */
function cms_sections(array $keys): array
{
    $rows = db_all(
        'SELECT section_key, content FROM cms_sections WHERE is_active = 1 AND section_key IN (' . db_placeholders(count($keys)) . ')',
        $keys
    );
    $out = [];
    foreach ($rows as $r) {
        $out[$r['section_key']] = json_decode_array($r['content']);
    }
    return $out;
}

/** Turn a stored asset path (e.g. "assets/images/x.svg") into an absolute <field>_url (null when empty). */
function with_image_url(array $block, string $field = 'image'): array
{
    $block[$field . '_url'] = !empty($block[$field]) ? public_file_url((string) $block[$field]) : null;
    unset($block[$field]);
    return $block;
}

/** Blank out *_url values that are not a site path, https://, tel: or mailto: link (hero / CTA buttons). */
function safe_block_urls(array $block): array
{
    foreach ($block as $k => $v) {
        if (is_string($v) && str_ends_with((string) $k, '_url') && $k !== 'image_url' && $v !== ''
            && !is_site_link($v) && !preg_match('#^(tel:\+?[\d\s-]{6,20}|mailto:\S+@\S+)$#i', $v)) {
            $block[$k] = '';
        }
    }
    return $block;
}

/** Published govt job alerts for the jobs menu / homepage block. */
function job_alert_card(array $a): array
{
    $published = $a['published_at'] ?? null;
    return [
        'id' => (int) $a['id'],
        'title' => $a['title'],
        'slug' => $a['slug'],
        'type' => $a['alert_type'],
        'type_label' => JOB_ALERT_TYPES[$a['alert_type']] ?? ucfirst(str_replace('_', ' ', $a['alert_type'])),
        'organization' => $a['organization'],
        'last_date' => $a['last_date'],
        'last_date_label' => $a['last_date'] ? date('j M Y', strtotime($a['last_date'])) : null,
        'published_at' => iso_datetime($published),
        'is_new' => $published !== null && strtotime($published) >= time() - 7 * 86400,
    ];
}

function layout_data(): array
{
    $s = settings_all(true);
    $blocks = cms_sections(['announcement', 'footer', 'nav_main', 'nav_footer', 'header', 'newsletter']);
    $safeUrl = fn($u) => is_string($u) && preg_match('#^(/|https://)#', $u) ? $u : null;
    $navItems = fn(?array $b) => array_values(array_filter(array_map(fn($i) => ($l = trim((string) ($i['label'] ?? ''))) !== '' && $safeUrl($i['url'] ?? null)
        ? ['label' => mb_substr($l, 0, 40), 'url' => $i['url'], 'mega' => !empty($i['mega'])] : null, $b['items'] ?? [])));
    $popups = db_all("SELECT id, kind, title, body, image_path, cta_label, cta_url, form_title, success_message, consent_text, show_on, delay_seconds, frequency, updated_at FROM popups
        WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at > NOW()) ORDER BY id DESC LIMIT 5");
    // Every network is listed; url stays null until an admin sets a real https link.
    $social = [];
    foreach (['youtube', 'facebook', 'instagram', 'x', 'linkedin', 'threads', 'telegram', 'whatsapp_channel'] as $network) {
        $url = $s['social_' . $network] ?? '';
        $social[] = ['network' => $network, 'url' => ($url !== '' && preg_match('#^https://#i', $url)) ? $url : null];
    }

    // Mega menu: each top-level category with up to 4 published courses (subcategory courses roll up to their parent)
    $allCategories = fetch_course_categories();
    $categories = array_values(array_filter($allCategories, fn($c) => $c['parent_id'] === null));
    $slugById = array_column($allCategories, 'slug', 'id');
    $topSlug = [];
    foreach ($allCategories as $c) {
        $topSlug[$c['slug']] = $c['parent_id'] !== null ? ($slugById[$c['parent_id']] ?? $c['slug']) : $c['slug'];
    }
    $byCategory = [];
    $allCourses = fetch_course_cards('1=1', [], 'c.is_featured DESC, c.sort_order, c.id', 100);
    foreach ($allCourses as $c) {
        $byCategory[$topSlug[$c['category']['slug']] ?? $c['category']['slug']][] = $c;
    }
    $mega = array_map(fn($cat) => [
        'name' => $cat['name'],
        'slug' => $cat['slug'],
        'icon' => $cat['icon'],
        'color' => $cat['color'],
        'count' => $cat['course_count'],
        'children' => array_values(array_map(fn($ch) => ['name' => $ch['name'], 'slug' => $ch['slug'], 'count' => $ch['course_count']],
            array_filter($allCategories, fn($ch) => $ch['parent_id'] === $cat['id']))),
        'courses' => array_map(fn($c) => [
            'title' => $c['title'], 'slug' => $c['slug'], 'subtitle' => $c['subtitle'],
            'price' => $c['price'], 'is_free' => $c['is_free'], 'badge' => $c['badge'],
        ], array_slice($byCategory[$cat['slug']] ?? [], 0, 4)),
    ], $categories);

    // Jobs menu: newest published alerts (still open) + counts by type
    $jobWhere = "status = 'published' AND (published_at IS NULL OR published_at <= NOW())";
    $jobCounts = array_fill_keys(array_keys(JOB_ALERT_TYPES), 0);
    foreach (db_all("SELECT alert_type, COUNT(*) AS n FROM job_alerts WHERE {$jobWhere} GROUP BY alert_type") as $r) {
        $jobCounts[$r['alert_type']] = (int) $r['n'];
    }
    $latestJobs = db_all("SELECT id, title, slug, alert_type, organization, last_date, published_at FROM job_alerts
        WHERE {$jobWhere} AND (last_date IS NULL OR last_date >= CURDATE()) ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT 6");

    $newsletter = $blocks['newsletter'] ?? null;
    return [
        'site' => [
            'name' => $s['site_name'] ?? 'Laxyam Academy',
            'tagline' => $s['tagline'] ?? '',
            'email' => $s['contact_email'] ?? '',
            'phone' => $s['contact_phone'] ?? '',
            'whatsapp' => preg_replace('/\D/', '', $s['whatsapp_number'] ?? ''),
            'address' => $s['address'] ?? '',
            'office_hours' => $s['office_hours'] ?? '',
            'helpline_label' => $s['helpline_label'] ?? 'Admission Helpline',
            'map_query' => $s['map_query'] ?? ($s['address'] ?? ''),
            'app_android_url' => preg_match('#^https://#i', $s['app_android_url'] ?? '') ? $s['app_android_url'] : null,
            'app_ios_url' => preg_match('#^https://#i', $s['app_ios_url'] ?? '') ? $s['app_ios_url'] : null,
            'currency' => $s['currency'] ?? 'INR',
            'logo_url' => public_file_url(setting('logo_path') ?: 'assets/logo.png'),
            'custom_logo' => (bool) setting('logo_path'),
            'language_toggle' => setting('language_toggle', '0') === '1',
            'recaptcha_site_key' => setting('recaptcha_site_key') && (string) config('RECAPTCHA_SECRET', '') !== '' ? setting('recaptcha_site_key') : null,
            'analytics' => setting('analytics_enabled', '1') === '1',
            'default_title' => $s['default_seo_title'] ?? '',
            'default_description' => $s['default_seo_description'] ?? '',
        ],
        // Registered company (footer copyright bar, invoices)
        'company' => [
            'legal_name' => $s['company_legal_name'] ?? '',
            'cin' => $s['company_cin'] ?? '',
            'gstin' => $s['company_gstin'] ?? '',
            'address' => ($s['company_registered_address'] ?? '') !== '' ? $s['company_registered_address'] : ($s['address'] ?? ''),
        ],
        'social' => $social,
        'nav' => $navItems($blocks['nav_main'] ?? null),
        'footer_links' => $navItems($blocks['nav_footer'] ?? null),
        'header' => ($blocks['header'] ?? []) + ['show_top_bar' => true, 'show_helpline' => true, 'show_search' => true, 'show_social' => true],
        // Header highlights ticker (admin → Highlights), active and inside their schedule window
        'highlights' => fetch_site_highlights(12),
        'jobs_menu' => [
            'latest' => array_map(fn($a) => array_intersect_key(job_alert_card($a), array_flip(['id', 'title', 'slug', 'type', 'type_label', 'organization', 'last_date', 'last_date_label', 'is_new'])), $latestJobs),
            'counts' => $jobCounts,
        ],
        'newsletter' => $newsletter ? [
            'title' => (string) ($newsletter['title'] ?? ''),
            'text' => (string) ($newsletter['text'] ?? ''),
            'button_label' => (string) (($newsletter['button_label'] ?? '') ?: 'Subscribe'),
        ] : null,
        'popups' => array_map(fn($p) => [
            'id' => (int) $p['id'], 'kind' => $p['kind'], 'title' => $p['title'], 'body' => $p['body'], 'image_url' => public_file_url($p['image_path']),
            'cta_label' => $p['cta_label'], 'cta_url' => $safeUrl($p['cta_url']), 'show_on' => $p['show_on'],
            'delay' => (int) $p['delay_seconds'], 'frequency' => $p['frequency'], 'version' => strtotime($p['updated_at']),
            // Enquiry popups render a lead form (POST enquiries.php with source=popup, popup_id, consent)
            'form_title' => $p['kind'] === 'enquiry' ? ($p['form_title'] ?: $p['title']) : $p['form_title'],
            'success_message' => $p['kind'] === 'enquiry' ? ($p['success_message'] ?: POPUP_SUCCESS_DEFAULT) : $p['success_message'],
            'consent_text' => $p['kind'] === 'enquiry' ? ($p['consent_text'] ?: POPUP_CONSENT_DEFAULT) : $p['consent_text'],
        ], $popups),
        'maintenance' => setting('maintenance_mode', '0') === '1'
            ? ['on' => true, 'message' => setting('maintenance_message') ?: 'We are making some improvements and will be back shortly.']
            : ['on' => false],
        'announcement' => $blocks['announcement'] ?? null,
        'footer' => $blocks['footer'] ?? null,
        // count = published courses incl. subcategories (menus hide 0-course categories; job/registration filters list all)
        'categories' => array_map(fn($c) => ['name' => $c['name'], 'slug' => $c['slug'], 'count' => (int) $c['course_count']], $categories),
        'mega' => $mega,
        'footer_courses' => array_map(
            fn($c) => ['title' => $c['title'], 'slug' => $c['slug']],
            fetch_course_cards("c.course_type <> 'free'", [], 'c.is_featured DESC, c.sort_order', 7)
        ),
        // Website chat assistant (admin → Chatbot); null when switched off
        'chatbot' => setting('chatbot_enabled', '1') === '1' ? [
            'name' => setting('chatbot_name', 'Laxyam Admission Assistant'),
            'greeting' => setting('chatbot_greeting', 'Ask me about courses, fees, mock tests, live classes or admission - in English or Hindi.'),
            'teaser' => setting('chatbot_teaser', ''),
            'ai' => chatbot_ai_configured(),
            'courses' => array_map(fn($c) => ['title' => $c['title'], 'slug' => $c['slug']], $allCourses),
        ] : null,
    ];
}

function home_data(): array
{
    // Only visible (is_active) blocks are returned; a hidden block is omitted from the page entirely.
    $b = cms_sections(HOME_SECTION_KEYS);
    $on = fn(string $key) => isset($b[$key]);
    $order = array_column(db_all(
        'SELECT section_key FROM cms_sections WHERE is_active = 1 AND section_key IN (' . db_placeholders(count(HOME_SECTION_KEYS)) . ') ORDER BY sort_order, section_key',
        HOME_SECTION_KEYS
    ), 'section_key');

    // Only statistics an admin has approved AND filled in are public.
    if (isset($b['stats']['items'])) {
        $b['stats']['items'] = array_values(array_filter(
            $b['stats']['items'],
            fn($i) => !empty($i['approved']) && trim((string) ($i['value'] ?? '')) !== ''
        ));
    }

    $stories = db_all(
        "SELECT type, name, photo_path, exam_name, exam_year, result_label, content, rating, is_demo
         FROM testimonials
         WHERE status = 'approved' AND (type = 'testimonial' OR is_verified = 1)
         ORDER BY sort_order, approved_at DESC LIMIT 16"
    );
    $present = fn($t) => [
        'name' => $t['name'],
        'photo_url' => public_file_url($t['photo_path']),
        'exam' => trim(($t['exam_name'] ?? '') . ' ' . ($t['exam_year'] ?? '')),
        'result' => $t['result_label'],
        'content' => $t['content'],
        'rating' => $t['rating'] !== null ? (int) $t['rating'] : null,
        'is_demo' => (bool) $t['is_demo'],
    ];

    // Resource tiles: only categories with at least one published resource (an empty tile would open "No resources found")
    $resourceCategories = $on('resources') ? array_map(fn($r) => ['count' => (int) $r['count']] + $r, db_all(
        "SELECT name, slug, subtitle, icon, color, count FROM (
            SELECT rc.name, rc.slug, rc.subtitle, rc.icon, rc.color, rc.sort_order,
                   (SELECT COUNT(*) FROM learning_resources r WHERE r.category_id = rc.id AND r.status = 'published'
                       AND (r.published_at IS NULL OR r.published_at <= NOW())) AS count
            FROM resource_categories rc WHERE rc.is_active = 1
         ) t WHERE t.count > 0 ORDER BY sort_order, name LIMIT 12"
    )) : [];
    $videos = media_video_sections('home');
    $jobsHeading = $b['job_alerts'] ?? null;

    return [
        'hero' => $on('hero') ? safe_block_urls(with_image_url($b['hero'])) : null,
        'features' => $b['features'] ?? null,
        'courses' => $on('courses') ? [
            'heading' => $b['courses'],
            'items' => fetch_course_cards('1=1', [], 'c.is_featured DESC, c.sort_order, c.id', 8),
        ] : null,
        // Top-level exam categories only; count = published courses incl. subcategories
        'categories' => $on('categories') ? [
            'heading' => $b['categories'],
            'items' => array_values(array_map(fn($c) => $c + ['count' => $c['course_count']],
                array_filter(fetch_course_categories(), fn($c) => $c['parent_id'] === null))),
        ] : null,
        'why_choose' => $b['why_choose'] ?? null,
        'stats' => $b['stats'] ?? ['items' => []],
        'live_classes' => $on('live_classes') ? [
            'heading' => $b['live_classes'],
            'items' => fetch_upcoming_live_classes(4),
        ] : null,
        'resources' => $on('resources') ? [
            'heading' => $b['resources'],
            'items' => $resourceCategories,
        ] : null,
        'success_stories' => $on('success_stories') ? [
            'heading' => $b['success_stories'],
            'items' => array_values(array_map($present, array_filter($stories, fn($t) => $t['type'] === 'success_story'))),
        ] : null,
        'testimonials' => $on('testimonials') ? [
            'heading' => $b['testimonials'],
            'items' => array_values(array_map($present, array_filter($stories, fn($t) => $t['type'] === 'testimonial'))),
        ] : null,
        'cta' => $on('cta') ? safe_block_urls(with_image_url($b['cta'])) : null,
        // Open, published alerts with an official https link (expired recruitments are not featured)
        'job_alerts' => $on('job_alerts') ? array_map(fn($a) => job_alert_card($a) + [
            'official_url' => $a['official_url'],
            'is_demo' => (bool) $a['is_demo'],
        ], db_all(
            "SELECT id, title, slug, organization, alert_type, last_date, official_url, published_at, is_demo FROM job_alerts
             WHERE status = 'published' AND (published_at IS NULL OR published_at <= NOW()) AND official_url LIKE 'https://%'
               AND (last_date IS NULL OR last_date >= CURDATE())
             ORDER BY is_featured DESC, published_at DESC LIMIT 6"
        )) : [],
        'job_alerts_heading' => $jobsHeading ? [
            'title' => (string) ($jobsHeading['title'] ?? ''),
            'highlight' => (string) ($jobsHeading['highlight'] ?? ''),
            'subtitle' => (string) ($jobsHeading['subtitle'] ?? ''),
        ] : null,
        'videos' => $videos,
        'newsletter' => $on('newsletter') ? [
            'title' => (string) ($b['newsletter']['title'] ?? ''),
            'text' => (string) ($b['newsletter']['text'] ?? ''),
            'button_label' => (string) (($b['newsletter']['button_label'] ?? '') ?: 'Subscribe'),
        ] : null,
        'banners' => fetch_promo_banners('home'),
        // Visible homepage blocks in the admin's sort order (admin → Website & CMS → Homepage sections)
        'order' => $order,
    ];
}

/** GET cms.php?action=videos&placement=home|courses|about|course[&course=<slug>] */
function videos_action(): never
{
    $placement = query_str('placement', 20) ?: 'home';
    if (!array_key_exists($placement, VIDEO_PLACEMENTS)) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['placement' => 'Use home, courses, about or course.']);
    }
    $courseId = null;
    if ($placement === 'course') {
        $slug = query_slugs('course')[0] ?? '';
        if ($slug === '') {
            api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['course' => 'Course slug is required for placement=course.']);
        }
        $courseId = (int) db_value("SELECT id FROM courses WHERE slug = ? AND status = 'published'", [$slug]);
        if (!$courseId) {
            api_error('Course not found.', 404, 'not_found');
        }
    }
    api_ok(cache_remember('videos_' . $placement . '_' . (int) $courseId, 300, fn() => media_video_sections($placement, $courseId)), 200, true);
}

/** GET cms.php?action=banners&placement=home|courses|course_detail */
function banners_action(): never
{
    $placement = query_str('placement', 20) ?: 'home';
    if (!array_key_exists($placement, BANNER_PLACEMENTS)) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['placement' => 'Use home, courses or course_detail.']);
    }
    api_ok(cache_remember('banners_' . $placement, 300, fn() => ['items' => fetch_promo_banners($placement)]), 200, true);
}

/** Count a promotional banner click (GET beacon, rate-limited per IP and banner). */
function banner_click(): never
{
    $id = query_int('id', 0, 0);
    if ($id && rate_limit('banner-click', client_ip() . ':' . $id, 3, 3600)) {
        db_query('UPDATE promo_banners SET clicks = clicks + 1 WHERE id = ? AND is_active = 1', [$id]);
    }
    api_ok(['ok' => true]);
}

/** Count a popup view/click (GET beacon, rate-limited per IP). */
function popup_event(): never
{
    $id = query_int('id', 0, 0);
    $col = ($_GET['type'] ?? '') === 'click' ? 'clicks' : 'views';
    if ($id && rate_limit('popup-' . $col, client_ip() . ':' . $id, 3, 3600)) {
        db_query("UPDATE popups SET {$col} = {$col} + 1 WHERE id = ?", [$id]);
    }
    api_ok(['ok' => true]);
}

/** Page-builder page. Drafts are visible only to signed-in staff (preview). */
function custom_page(string $slug): never
{
    require_once APP_ROOT . '/includes/blocks.php';
    $staff = (current_user()['user_type'] ?? null) === 'staff';
    $p = preg_match('/^[a-z0-9-]{1,160}$/', $slug)
        ? db_one('SELECT * FROM pages WHERE slug = ? AND ' . ($staff && isset($_GET['preview']) ? "status <> 'archived'" : "status = 'published'"), [$slug])
        : null;
    if (!$p) {
        api_error('Page not found.', 404, 'not_found');
    }
    $data = [
        'title' => $p['title'], 'slug' => $p['slug'], 'status' => $p['status'],
        'seo' => ['title' => $p['seo_title'] ?: $p['title'] . ' | ' . setting('site_name', 'Laxyam Academy'), 'description' => $p['seo_description']],
        'blocks' => resolve_blocks(json_decode_array($p['blocks'])),
    ];
    $p['status'] === 'published' && !isset($_GET['preview']) ? api_ok($data, 200, true) : api_ok($data);
}

/** About and policy pages stored as cms_sections "page_<key>": { title, intro, sections: [{heading, body}] }. */
function content_page(string $key): array
{
    if (!in_array($key, ['about', 'privacy', 'terms', 'refund', 'cancellation', 'delivery', 'disclaimer', 'cookies', 'careers'], true)) {
        api_error('Page not found.', 404, 'not_found');
    }
    $row = db_one('SELECT content, updated_at FROM cms_sections WHERE section_key = ? AND is_active = 1', ['page_' . $key]);
    if (!$row) {
        api_error('Page not found.', 404, 'not_found');
    }
    $c = json_decode_array($row['content']);
    $c['sections'] = array_map(fn($s) => [
        'heading' => $s['heading'] ?? '',
        'html' => text_to_html($s['body'] ?? ''),
        'icon' => $s['icon'] ?? null,
    ], $c['sections'] ?? []);
    $c['updated_at'] = iso_datetime($row['updated_at']);
    if (isset($c['image'])) {
        $c = with_image_url($c);
    }
    return $c;
}

function format_post(array $p): array
{
    return [
        'id' => (int) $p['id'],
        'title' => $p['title'],
        'slug' => $p['slug'],
        'excerpt' => $p['excerpt'],
        'image_url' => public_file_url($p['featured_image_path']),
        'tags' => array_values(array_filter(array_map('trim', explode(',', (string) $p['tags'])))),
        'published_at' => iso_datetime($p['published_at']),
        'category' => $p['category_slug'] ? ['name' => $p['category_name'], 'slug' => $p['category_slug']] : null,
        'author' => $p['author_name'] ?: 'Laxyam Academy',
        'is_demo' => (bool) $p['is_demo'],
    ];
}

function blog_list(): array
{
    $where = '';
    $params = [];
    if ($cat = query_slugs('category')) {
        $where .= ' AND bc.slug = ?';
        $params[] = $cat[0];
    }
    if (($tag = query_str('tag', 40)) !== '') {
        $where .= ' AND FIND_IN_SET(?, REPLACE(p.tags, ", ", ","))';
        $params[] = $tag;
    }
    if (($q = query_str('q', 100)) !== '') {
        $where .= ' AND (p.title LIKE ? OR p.excerpt LIKE ?)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        array_push($params, $like, $like);
    }
    $page = query_int('page', 1, 1, 500);
    $per = 9;
    $total = (int) db_value('SELECT COUNT(*) ' . BLOG_FROM . $where, $params);
    $rows = db_all('SELECT ' . BLOG_COLUMNS . ' ' . BLOG_FROM . $where . " ORDER BY p.published_at DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);
    return [
        'items' => array_map('format_post', $rows),
        'pagination' => pagination_meta($total, $page, $per),
        'categories' => db_all("SELECT bc.name, bc.slug, COUNT(p.id) AS count FROM blog_categories bc
            LEFT JOIN blog_posts p ON p.category_id = bc.id AND p.status IN ('published','scheduled') AND p.published_at <= NOW()
            GROUP BY bc.id ORDER BY bc.sort_order, bc.name"),
    ];
}

function blog_post(string $slug): array
{
    $p = preg_match('/^[a-z0-9-]{1,220}$/', $slug)
        ? db_one('SELECT ' . BLOG_COLUMNS . ', p.content, p.seo_title, p.seo_description, p.category_id, p.updated_at ' . BLOG_FROM . ' AND p.slug = ?', [$slug])
        : null;
    if (!$p) {
        api_error('Article not found.', 404, 'not_found');
    }
    db_query('UPDATE blog_posts SET views = views + 1 WHERE id = ?', [$p['id']]);
    $related = db_all('SELECT ' . BLOG_COLUMNS . ' ' . BLOG_FROM . ' AND p.id <> ? ORDER BY (p.category_id <=> ?) DESC, p.published_at DESC LIMIT 3', [$p['id'], $p['category_id']]);
    return format_post($p) + [
        'html' => sanitize_html($p['content']),
        'updated_at' => iso_datetime($p['updated_at']),
        'seo' => ['title' => $p['seo_title'] ?: $p['title'] . ' | Laxyam Academy Blog', 'description' => $p['seo_description'] ?: $p['excerpt']],
        'related' => array_map('format_post', $related),
    ];
}

function seo_data(string $path): array
{
    $path = '/' . trim(parse_url($path, PHP_URL_PATH) ?: '', '/');
    $row = db_one('SELECT title, meta_description, canonical_url, og_title, og_description, og_image, robots FROM seo_pages WHERE path = ?', [$path]);
    return [
        'title' => $row['title'] ?? setting('default_seo_title'),
        'description' => $row['meta_description'] ?? setting('default_seo_description'),
        'canonical' => $row['canonical_url'] ?? app_url(ltrim($path, '/')),
        'og_title' => $row['og_title'] ?? null,
        'og_description' => $row['og_description'] ?? null,
        'og_image' => public_file_url($row['og_image'] ?? null),
        'robots' => $row['robots'] ?? 'index,follow',
    ];
}

function sitemap(): never
{
    $urls = [];
    foreach (['', 'courses', 'categories', 'mock-tests', 'live-classes', 'resources', 'jobs', 'careers', 'blog', 'faq', 'about', 'contact',
              'privacy-policy', 'terms', 'refund-policy', 'cancellation-policy', 'disclaimer', 'cookie-policy'] as $p) {
        $urls[] = [app_url($p), null];
    }
    // Category pages with published courses only (empty ones are hidden from the menus too).
    foreach (db_all("SELECT cat.slug FROM course_categories cat WHERE cat.is_active = 1
        AND EXISTS (SELECT 1 FROM courses c JOIN course_categories cc ON cc.id = c.category_id WHERE c.status = 'published' AND (cc.id = cat.id OR cc.parent_id = cat.id))") as $c) {
        $urls[] = [app_url('courses?category=' . $c['slug']), null];
    }
    foreach (db_all("SELECT slug, updated_at FROM courses WHERE status = 'published'") as $c) {
        $urls[] = [app_url('courses/' . $c['slug']), $c['updated_at']];
    }
    foreach (db_all("SELECT slug, updated_at FROM pages WHERE status = 'published'") as $p) {
        $urls[] = [app_url('p/' . $p['slug']), $p['updated_at']];
    }
    foreach (db_all("SELECT slug, updated_at FROM job_openings WHERE status = 'open' AND (closes_at IS NULL OR closes_at >= CURDATE())") as $p) {
        $urls[] = [app_url('careers/' . $p['slug']), $p['updated_at']];
    }
    foreach (db_all("SELECT slug, updated_at FROM blog_posts WHERE status = 'published' AND published_at <= NOW()") as $p) {
        $urls[] = [app_url('blog/' . $p['slug']), $p['updated_at']];
    }
    // Published job alerts (same visibility rule as index.php / jobs.php?action=alert)
    foreach (db_all("SELECT slug, updated_at FROM job_alerts WHERE status = 'published' AND (published_at IS NULL OR published_at <= NOW())") as $p) {
        $urls[] = [app_url('jobs/' . $p['slug']), $p['updated_at']];
    }
    // Published resources whose category is live (api/resources.php RESOURCE_FROM + RESOURCE_VISIBLE)
    foreach (db_all("SELECT r.slug, r.updated_at FROM learning_resources r JOIN resource_categories rc ON rc.id = r.category_id AND rc.is_active = 1
                     WHERE r.status = 'published' AND (r.published_at IS NULL OR r.published_at <= NOW())") as $p) {
        $urls[] = [app_url('resources/' . $p['slug']), $p['updated_at']];
    }
    // Published mock tests / quizzes (api/mock-tests.php detail)
    foreach (db_all("SELECT slug, updated_at FROM exams WHERE status = 'published'") as $p) {
        $urls[] = [app_url('mock-tests/' . $p['slug']), $p['updated_at']];
    }
    // Photo gallery: the index (always served by index.php) + active albums that have something to show (admin/gallery.php)
    $urls[] = [app_url('gallery'), null];
    try {
        $albums = db_all("SELECT a.slug, GREATEST(a.updated_at, COALESCE((SELECT MAX(n.created_at) FROM gallery_items n WHERE n.album_id = a.id), a.updated_at)) AS updated_at
                          FROM gallery_albums a WHERE a.is_active = 1
                          AND EXISTS (SELECT 1 FROM gallery_items i WHERE i.album_id = a.id AND i.is_active = 1) ORDER BY a.sort_order, a.id DESC");
        foreach ($albums as $p) {
            $urls[] = [app_url('gallery/' . $p['slug']), $p['updated_at']];
        }
    } catch (PDOException $e) {
        // gallery tables not installed on this database yet
    }
    $noindex = array_column(db_all("SELECT path FROM seo_pages WHERE robots <> 'index,follow'"), 'path');

    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $basePath = rtrim((string) parse_url(app_url(), PHP_URL_PATH), '/');
    foreach ($urls as [$loc, $updated]) {
        $path = '/' . trim(substr((string) parse_url($loc, PHP_URL_PATH), strlen($basePath)), '/');
        if (in_array($path, $noindex, true)) {
            continue;
        }
        echo '  <url><loc>' . e($loc) . '</loc>';
        if ($updated) {
            echo '<lastmod>' . (new DateTimeImmutable($updated))->format('Y-m-d') . '</lastmod>';
        }
        echo "</url>\n";
    }
    echo '</urlset>';
    exit;
}

function robots(): never
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    $base = rtrim((string) parse_url(app_url(), PHP_URL_PATH), '/');
    if (!is_production()) {
        // Never let search engines index development or staging copies.
        echo "User-agent: *\nDisallow: /\n";
        exit;
    }
    // api/ is deliberately NOT disallowed: pages are rendered from /api/ data, so blocking it makes Google see
    // empty pages ("Soft 404"). API responses send X-Robots-Tag: noindex (json_send) so the JSON is never indexed.
    $private = ['admin/', 'dashboard', 'learn/', 'checkout', 'payment', 'login', 'register', 'forgot-password', 'reset-password', 'verify-email', 'uploads/private/'];
    echo "User-agent: *\n";
    foreach ($private as $p) {
        echo "Disallow: {$base}/{$p}\n";
    }
    // Admin → Settings → SEO → extra robots.txt rules (validated on save)
    foreach (preg_split('/\R/', (string) setting('robots_extra', '')) as $line) {
        if (preg_match('/^(User-agent|Disallow|Allow|Crawl-delay):\s*[\w\-\/*.$?=&%]*$/i', trim($line))) {
            echo trim($line) . "\n";
        }
    }
    echo "\nSitemap: " . app_url('sitemap.xml') . "\n";
    exit;
}

/**
 * /.well-known/assetlinks.json (Digital Asset Links): tells Android that the Play Store app (a Trusted
 * Web Activity of this site) belongs to this website, so it opens full-screen without a browser bar.
 * Package name + signing certificate fingerprints come from admin → Integrations → Android App.
 */
function assetlinks(): never
{
    $package = (string) setting('android_package', '');
    $prints = array_values(array_filter(explode(',', (string) setting('android_sha256', '')), fn($f) => preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $f)));
    if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $package) || !$prints) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo '[]';
        exit;
    }
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=3600');
    echo json_encode([[
        'relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => ['namespace' => 'android_app', 'package_name' => $package, 'sha256_cert_fingerprints' => $prints],
    ]], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
