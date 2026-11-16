<?php
/**
 * Search engine metadata for every public website URL - ONE set of rules, used by:
 *   index.php                <title>, meta description, canonical, robots, Open Graph / X card, JSON-LD and the
 *                            <noscript> text version of the page (for crawlers and AI search bots that do not
 *                            run JavaScript; Google renders the React page itself)
 *   api/cms.php?action=seo   the same values for the React app after client-side navigation (useRouteSeo)
 *   api/cms.php sitemaps     which URLs are indexable, with lastmod dates from the database
 *   admin/seo.php            SEO health dashboard, URL-wise metadata sheet and CSV export
 *
 * Every text comes from the site's own data (courses, categories, CMS pages, settings) - nothing is
 * invented: ratings only from approved reviews, no FAQPage markup (Google shows FAQ rich results only for
 * government and health sites), opening hours only when entered in schema.org form (e.g. "Mo-Sa 09:00-19:00").
 * Admin → SEO → Page SEO rows (seo_pages) override the computed values of their path.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once APP_ROOT . '/includes/catalog.php';

/** Account, checkout and exam-room routes: never indexed (robots.txt disallows them as well). */
const SEO_PRIVATE_RE = '#^/(dashboard|learn|checkout|payment|enrolled|login|register|forgot-password|reset-password|verify-email|mock-tests/[^/]+/(attempt|result))(/|$)#';

/** Every route of the React app (js/App.jsx). Anything else gets a real 404 status. */
const SEO_ROUTES_RE = '#^/(|courses(/[a-z0-9-]+)?|categories(/[a-z0-9-]+)?|mock-tests(/[a-z0-9-]+(/(attempt|result)/\d+)?)?|live-classes|resources(/[a-z0-9-]+)?'
    . '|blog(/[a-z0-9-]+)?|about|contact|privacy-policy|terms|refund-policy|cancellation-policy|delivery-policy|disclaimer|cookie-policy'
    . '|jobs(/[a-z0-9-]+)?|careers(/[a-z0-9-]+)?|faq|gallery(/[a-z0-9-]+)?|p/[a-z0-9-]+|login|register|forgot-password|reset-password|verify-email'
    . '|dashboard(/(courses|tests|live-classes|resources|purchases|notifications|profile|certificates|wishlist|settings|support|refer|doubts(/\d+)?))?'
    . '|learn/[a-z0-9-]+(/\d+)?|checkout/[a-z0-9-]+|payment/result|enrolled/[a-z0-9-]+)$#';

/** Policy pages: path => cms_sections key ("page_<key>"). */
const SEO_POLICY_PAGES = [
    '/privacy-policy' => 'privacy', '/terms' => 'terms', '/refund-policy' => 'refund', '/cancellation-policy' => 'cancellation',
    '/delivery-policy' => 'delivery', '/disclaimer' => 'disclaimer', '/cookie-policy' => 'cookies',
];

/** Static public pages in the order the sitemap and the health dashboard list them. */
const SEO_STATIC_PATHS = ['/', '/courses', '/categories', '/about', '/contact', '/mock-tests', '/live-classes', '/resources', '/blog', '/jobs',
    '/careers', '/faq', '/gallery', '/privacy-policy', '/terms', '/refund-policy', '/cancellation-policy', '/delivery-policy', '/disclaimer', '/cookie-policy'];

// ------------------------------------------------------------------
// Small helpers
// ------------------------------------------------------------------

/** Absolute URL of a site path ("/courses/x" → https://…/courses/x). */
function seo_url(string $path): string
{
    return app_url(ltrim($path, '/'));
}

/** Plain-text excerpt for meta descriptions: tags stripped, spaces collapsed, cut at a word boundary. */
function seo_text(?string $text, int $max = 160): string
{
    $t = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/(^|\n)\s*[-*•]\s+/u', '$1', $t)));
    if (mb_strlen($t) <= $max) {
        return $t;
    }
    $cut = mb_substr($t, 0, $max - 1);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space !== false && $space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut, " ,;:-–") . '…';
}

/** "₹7,499" (Indian digit grouping, no decimals unless needed). */
function seo_money(float $amount): string
{
    $int = (int) floor($amount);
    $s = (string) $int;
    if (strlen($s) > 3) {
        $s = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($s, 0, -3)) . ',' . substr($s, -3);
    }
    $paise = (int) round(($amount - $int) * 100);
    return '₹' . $s . ($paise ? '.' . str_pad((string) $paise, 2, '0', STR_PAD_LEFT) : '');
}

/** Site identity and contact details from admin → Settings (cached per request). */
function seo_site(): array
{
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    $social = [];
    foreach (['youtube', 'facebook', 'instagram', 'x', 'linkedin', 'threads', 'telegram', 'whatsapp_channel'] as $n) {
        $u = (string) setting('social_' . $n, '');
        if (preg_match('#^https://[^\s"<>]+$#i', $u)) {
            $social[$n] = $u;
        }
    }
    $handle = preg_match('#^https://(?:www\.)?(?:x|twitter)\.com/([A-Za-z0-9_]{1,15})/?$#i', $social['x'] ?? '', $m) ? '@' . $m[1] : null;
    $address = trim((string) setting('address', ''));
    $parts = array_values(array_filter(array_map('trim', explode(',', $address))));
    return $s = [
        'name' => (string) setting('site_name', 'Laxyam Academy'),
        'url' => app_url(''),
        'logo' => (setting('logo_path') ? public_file_url(setting('logo_path')) : null) ?? app_url('assets/logo.png'),
        'phone' => trim((string) setting('contact_phone', '')),
        'whatsapp' => (string) preg_replace('/\D/', '', (string) setting('whatsapp_number', '')),
        'email' => trim((string) setting('contact_email', '')),
        'address' => $address,
        // "Badarpur, New Delhi" from "Dharmveer Market, Badarpur, New Delhi" (used in descriptions)
        'area' => count($parts) >= 2 ? implode(', ', array_slice($parts, -2)) : $address,
        'map_query' => (string) (setting('map_query') ?: $address),
        'hours' => trim((string) setting('office_hours', '')),
        'legal_name' => trim((string) setting('company_legal_name', '')),
        'social' => $social,
        'twitter' => $handle,
    ];
}

/** Google Maps search link for the centre address (the same query the Contact page map uses). */
function seo_map_url(): ?string
{
    $q = seo_site()['map_query'];
    return $q !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q) : null;
}

/** Default social sharing image: admin upload, else the bundled 1200×630 card, else the app icon. [url, width, height] */
function seo_default_image(): array
{
    if ($custom = public_file_url(setting('default_og_image'))) {
        return [$custom, null, null];
    }
    if (is_file(APP_ROOT . '/assets/images/og-default.jpg')) {
        return [app_url('assets/images/og-default.jpg'), 1200, 630];
    }
    return [app_url('assets/icons/icon-512.png'), 512, 512];
}

/** Published courses (card shape) of a category and its subcategories, or of every category (null). */
function seo_courses(?int $categoryId = null): array
{
    static $cache = [];
    $key = (string) $categoryId;
    if (!isset($cache[$key])) {
        $cache[$key] = $categoryId === null
            ? fetch_course_cards('1=1', [], 'cat.sort_order, c.is_featured DESC, c.sort_order, c.id', 100)
            : fetch_course_cards('(cat.id = ? OR cat.parent_id = ?)', [$categoryId, $categoryId], 'c.is_featured DESC, c.sort_order, c.id', 100);
    }
    return $cache[$key];
}

/** Active top-level categories that have published courses (menus hide the empty ones too). */
function seo_categories(): array
{
    static $cats = null;
    return $cats ??= array_values(array_filter(fetch_course_categories(), fn($c) => $c['parent_id'] === null && $c['course_count'] > 0));
}

/** Public path of a course category landing page. */
function seo_category_path(string $slug): string
{
    return '/categories/' . $slug;
}

/** "6 Months" → "P6M" (schema.org Duration), null when the label is not a simple duration. */
function seo_iso_duration(?string $label): ?string
{
    if (!preg_match('/^\s*(\d{1,3})\s*(year|month|week|day|hour)s?\s*$/i', (string) $label, $m)) {
        return null;
    }
    return match (strtolower($m[2])) {
        'year' => 'P' . (int) $m[1] . 'Y', 'month' => 'P' . (int) $m[1] . 'M', 'week' => 'P' . (int) $m[1] . 'W',
        'day' => 'P' . (int) $m[1] . 'D', default => 'PT' . (int) $m[1] . 'H',
    };
}

/** Items that look like a course price for people: "₹7,499" or "Free", with the regular price when on offer. */
function seo_price_label(array $card): string
{
    if ($card['is_free']) {
        return 'Free';
    }
    return seo_money((float) $card['price']) . ($card['original_price'] ? ' (regular ' . seo_money((float) $card['original_price']) . ')' : '');
}

// ------------------------------------------------------------------
// Structured data (JSON-LD) nodes
// ------------------------------------------------------------------

function seo_org_id(): string
{
    return app_url('') . '#organization';
}

function seo_postal_address(): ?array
{
    $s = seo_site();
    if ($s['address'] === '') {
        return null;
    }
    $street = trim((string) preg_replace('/,?\s*(New\s+)?Delhi\b\s*(-?\s*\d{6})?\s*$/i', '', $s['address']), " ,");
    return array_filter([
        '@type' => 'PostalAddress',
        'streetAddress' => $street !== '' ? $street : $s['address'],
        'addressLocality' => preg_match('/\bNew Delhi\b/i', $s['address']) ? 'New Delhi' : (preg_match('/\bDelhi\b/i', $s['address']) ? 'Delhi' : null),
        'addressRegion' => preg_match('/\bDelhi\b/i', $s['address']) ? 'Delhi' : null,
        'postalCode' => preg_match('/\b(\d{6})\b/', $s['address'], $m) ? $m[1] : null,
        'addressCountry' => 'IN',
    ]);
}

/**
 * The academy as EducationalOrganization + LocalBusiness (a physical coaching centre with an address).
 * $full = false gives a short reference for other nodes (provider, publisher).
 */
function seo_org_node(bool $full = true): array
{
    $s = seo_site();
    $ref = ['@type' => ['EducationalOrganization', 'LocalBusiness'], '@id' => seo_org_id(), 'name' => $s['name'], 'url' => $s['url']];
    if (!$full) {
        return $ref;
    }
    // schema.org openingHours only when the admin typed it in that form, e.g. "Mo-Sa 09:00-19:00"
    $day = '(Mo|Tu|We|Th|Fr|Sa|Su)';
    $hours = array_values(array_filter(array_map('trim', preg_split('/[;\n]/', $s['hours'])), fn($h) => preg_match("/^{$day}(-{$day})?(,{$day}(-{$day})?)* \\d{2}:\\d{2}-\\d{2}:\\d{2}$/", $h)));
    return $ref + array_filter([
        'legalName' => $s['legal_name'] ?: null,
        'logo' => ['@type' => 'ImageObject', 'url' => $s['logo']],
        'image' => $s['logo'],
        'description' => seo_text(setting('default_seo_description', ''), 300) ?: null,
        'telephone' => $s['phone'] ?: null,
        'email' => $s['email'] ?: null,
        'address' => seo_postal_address(),
        'hasMap' => seo_map_url(),
        'openingHours' => $hours ?: null,
        'sameAs' => array_values($s['social']) ?: null,
        'contactPoint' => $s['phone'] ? [array_filter([
            '@type' => 'ContactPoint', 'contactType' => 'admissions', 'telephone' => $s['phone'], 'email' => $s['email'] ?: null,
            'areaServed' => 'IN', 'availableLanguage' => ['English', 'Hindi'],
        ])] : null,
    ]);
}

function seo_website_node(): array
{
    $s = seo_site();
    return ['@type' => 'WebSite', '@id' => $s['url'] . '#website', 'url' => $s['url'], 'name' => $s['name'], 'inLanguage' => 'en-IN', 'publisher' => ['@id' => seo_org_id()]];
}

/** BreadcrumbList from [[name, path], …] (the last item is the current page). */
function seo_breadcrumb_node(array $crumbs): ?array
{
    if (count($crumbs) < 2) {
        return null;
    }
    $items = [];
    foreach (array_values($crumbs) as $i => [$name, $path]) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => seo_url($path)];
    }
    return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

/** ItemList of course pages (Google "Course list" summary page: at least three courses). */
function seo_course_list_node(array $cards, string $name): ?array
{
    if (count($cards) < 3) {
        return null;
    }
    $items = [];
    foreach (array_values($cards) as $i => $c) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => seo_url('/courses/' . $c['slug']), 'name' => $c['title']];
    }
    return ['@type' => 'ItemList', 'name' => $name, 'numberOfItems' => count($items), 'itemListElement' => $items];
}

/** Course node from a full course row (+ card fields). */
function seo_course_node(array $row, array $card, array $syllabus): array
{
    $url = seo_url('/courses/' . $row['slug']);
    $place = array_filter(['@type' => 'Place', 'name' => seo_site()['name'], 'address' => seo_postal_address()]);
    $mode = match ($row['course_type']) {
        'offline' => 'Onsite', 'live', 'recorded' => 'Online', 'hybrid' => 'Blended', default => null,
    };
    $offer = array_filter([
        '@type' => 'Offer',
        'category' => $card['is_free'] ? 'Free' : 'Paid',
        'price' => $card['is_free'] ? 0 : (float) $card['price'],
        'priceCurrency' => 'INR',
        'url' => $url,
        'availability' => 'https://schema.org/InStock',
        // the sale price ends with the offer window
        'priceValidUntil' => $card['original_price'] && !empty($row['offer_ends_at']) ? date('Y-m-d', strtotime((string) $row['offer_ends_at'])) : null,
    ], fn($v) => $v !== null);
    $lang = ['hindi' => 'hi', 'english' => 'en', 'bilingual' => ['hi', 'en']][$row['language']] ?? null;
    return array_filter([
        '@type' => 'Course',
        '@id' => $url . '#course',
        'name' => $row['title'],
        'description' => seo_text((string) ($row['short_description'] ?: $row['description']), 500),
        'url' => $url,
        'image' => $card['thumbnail_url'],
        'provider' => seo_org_node(false),
        'inLanguage' => $lang,
        'educationalLevel' => $row['level'] !== 'all' ? ucfirst((string) $row['level']) : null,
        'timeRequired' => seo_iso_duration($row['duration_label'] ?? null),
        'teaches' => $syllabus ? array_column($syllabus, 'title') : null,
        'offers' => [$offer],
        'hasCourseInstance' => $mode ? [array_filter([
            '@type' => 'CourseInstance', 'courseMode' => $mode, 'location' => $mode !== 'Online' ? $place : null,
        ])] : null,
        // Only from approved student reviews (courses.rating_* is refreshed from course_reviews)
        'aggregateRating' => $card['rating'] ? ['@type' => 'AggregateRating', 'ratingValue' => $card['rating']['average'], 'ratingCount' => $card['rating']['count'], 'bestRating' => 5] : null,
    ], fn($v) => $v !== null && $v !== []);
}

// ------------------------------------------------------------------
// Category landing pages (/categories/<slug>)
// ------------------------------------------------------------------

/** An active course category by slug, with its parent. */
function seo_category(string $slug): ?array
{
    $hasIntro = (bool) db_value("SHOW COLUMNS FROM course_categories LIKE 'intro'");
    return db_one(
        'SELECT c.id, c.slug, c.name, c.short_name, c.description, c.color, c.icon, c.seo_title, c.seo_description, c.updated_at, '
        . ($hasIntro ? 'c.intro' : 'NULL AS intro') . ', p.slug AS parent_slug, p.name AS parent_name
         FROM course_categories c LEFT JOIN course_categories p ON p.id = c.parent_id
         WHERE c.slug = ? AND c.is_active = 1',
        [$slug]
    );
}

/**
 * Questions and answers for a category page, generated from the category's real courses and the
 * contact settings, so they change with the catalogue. (Shown on the page - not marked up as FAQPage.)
 */
function seo_category_faqs(array $cat, array $cards): array
{
    if (!$cards) {
        return [];
    }
    $s = seo_site();
    $name = $cat['name'];
    $faqs = [];
    $list = implode('; ', array_map(fn($c) => $c['title'] . ($c['duration'] ? ' (' . $c['duration'] . ')' : ''), $cards));
    $faqs[] = ['q' => "Which {$name} courses does {$s['name']} offer?",
        'a' => count($cards) === 1 ? "{$s['name']} offers one {$name} course: {$list}." : "{$s['name']} offers " . count($cards) . " {$name} courses: {$list}."];
    $faqs[] = ['q' => "What is the fee for {$name} coaching?",
        'a' => implode('; ', array_map(fn($c) => $c['title'] . ': ' . seo_price_label($c), $cards)) . '. The current fee and any admission or registration fee are shown on each course page.'];

    $offline = array_filter($cards, fn($c) => $c['course_type'] === 'offline');
    if ($offline && $s['address'] !== '') {
        $faqs[] = ['q' => count($offline) === count($cards) ? 'Are the classes offline?' : 'Where are the classroom classes held?',
            'a' => (count($offline) === count($cards) ? 'Yes. These are offline classroom courses. ' : '')
                . "Classes are held at {$s['name']}, {$s['address']}." . ($s['phone'] ? " Call {$s['phone']} for the batch timings." : '')];
    }

    // "Mock tests included" only when every course lists them in "This course includes".
    $ids = array_column($cards, 'id');
    $includes = db_all('SELECT includes FROM courses WHERE id IN (' . db_placeholders(count($ids)) . ')', $ids);
    if ($includes && count(array_filter($includes, fn($r) => preg_match('/mock test/i', (string) $r['includes']))) === count($ids)) {
        $faqs[] = ['q' => 'Are mock tests included?', 'a' => (count($cards) === 1 ? 'Yes. The course includes' : "Yes. Every {$name} course includes") . ' mock tests. Each course page lists the tests, practice questions and study material that come with it.'];
    }

    $modes = array_unique(array_column($cards, 'admission'));
    $how = [];
    if (array_intersect($modes, ['online', 'both'])) {
        $how[] = 'open the course page and choose Enroll Now to create an account and pay online';
    }
    if (array_intersect($modes, ['both', 'enquiry'])) {
        $how[] = 'submit the Apply Now form for a free counselling call';
    }
    $faqs[] = ['q' => 'How do I take admission?',
        'a' => ucfirst(implode(', or ', $how)) . '.' . ($s['phone'] ? " You can also call or WhatsApp {$s['phone']}." : '')];
    return $faqs;
}

/** Category data for the React page (api/courses.php list): intro, generated FAQs, other categories. */
function seo_category_extras(array $cat): array
{
    $cards = seo_courses((int) $cat['id']);
    return [
        'intro' => trim((string) ($cat['intro'] ?? '')),
        'faqs' => seo_category_faqs($cat, $cards),
        'related' => array_values(array_map(fn($c) => ['slug' => $c['slug'], 'name' => $c['name'], 'count' => $c['course_count'], 'color' => $c['color']],
            array_filter(seo_categories(), fn($c) => $c['slug'] !== $cat['slug'] && $c['slug'] !== ($cat['parent_slug'] ?? null)))),
    ];
}

// ------------------------------------------------------------------
// Static page defaults (overridable in admin → SEO → Page SEO)
// ------------------------------------------------------------------

/** Published, non-demo items on a listing page (null = not a listing). An empty listing is kept out of search. */
function seo_listing_count(string $path): ?int
{
    try {
        $sql = match ($path) {
            '/blog' => "SELECT COUNT(*) FROM blog_posts WHERE status IN ('published','scheduled') AND published_at <= NOW() AND is_demo = 0",
            '/mock-tests' => "SELECT COUNT(*) FROM exams WHERE status = 'published' AND is_demo = 0",
            '/live-classes' => "SELECT COUNT(*) FROM live_classes WHERE status <> 'cancelled' AND is_demo = 0 AND starts_at >= NOW() - INTERVAL 30 DAY",
            '/jobs' => "SELECT COUNT(*) FROM job_alerts WHERE status = 'published' AND is_demo = 0 AND (published_at IS NULL OR published_at <= NOW())",
            '/resources' => "SELECT COUNT(*) FROM learning_resources r JOIN resource_categories rc ON rc.id = r.category_id AND rc.is_active = 1
                             WHERE r.status = 'published' AND r.is_demo = 0 AND (r.published_at IS NULL OR r.published_at <= NOW())",
            '/careers' => "SELECT COUNT(*) FROM job_openings WHERE status = 'open' AND is_demo = 0 AND (closes_at IS NULL OR closes_at >= CURDATE())",
            '/gallery' => 'SELECT COUNT(*) FROM gallery_albums a WHERE a.is_active = 1 AND EXISTS (SELECT 1 FROM gallery_items i WHERE i.album_id = a.id AND i.is_active = 1)',
            '/faq' => 'SELECT COUNT(*) FROM faqs WHERE is_active = 1',
            default => null,
        };
        return $sql === null ? null : (int) db_value($sql);
    } catch (PDOException $e) {
        return 0; // table not installed yet = nothing to show
    }
}

/** A CMS content page (about / policies): {title, intro, sections[], updated_at} or null. */
function seo_cms_page(string $key): ?array
{
    $row = db_one('SELECT content, updated_at FROM cms_sections WHERE section_key = ? AND is_active = 1', ['page_' . $key]);
    if (!$row) {
        return null;
    }
    return json_decode_array($row['content']) + ['updated_at' => $row['updated_at']];
}

/** Built-in title / description / H1 of a static page (null for unknown paths). */
function seo_static_defaults(string $path): ?array
{
    $s = seo_site();
    $n = $s['name'];
    $area = $s['area'] ?: 'New Delhi';
    $catNames = array_column(seo_categories(), 'name');
    $catList = $catNames ? implode(', ', array_slice($catNames, 0, -1)) . (count($catNames) > 1 ? ' and ' : '') . end($catNames) : '';
    if (isset(SEO_POLICY_PAGES[$path])) {
        $page = seo_cms_page(SEO_POLICY_PAGES[$path]);
        return $page ? ['title' => ($page['title'] ?? 'Policy') . ' | ' . $n, 'description' => seo_text($page['intro'] ?? '') ?: null, 'h1' => $page['title'] ?? null] : null;
    }
    return match ($path) {
        '/' => ['title' => "{$n} | Railway, SSC, Banking & Government Exam Coaching",
            'description' => "Prepare for Railway NTPC, Group D, SSC, Banking, Police and Government Exams with {$n}. Join expert-guided offline coaching in {$area}.",
            'h1' => (string) setting('home_h1', 'Achieve Your Government Job Dream with ' . $n)],
        '/courses' => ['title' => "Government Exam Coaching Courses in Delhi | {$n}",
            'description' => seo_text(count(seo_courses()) . " courses at {$n}, {$area}" . ($catList ? ": {$catList}" : '') . '. Compare fees, duration and syllabus, then enrol online or apply for counselling.'),
            'h1' => 'Government exam coaching courses'],
        '/categories' => ['title' => "Courses by Exam Category | {$n}",
            'description' => seo_text("Choose your exam: {$catList}. Offline classroom coaching at {$n}, {$area}."), 'h1' => 'Exam categories'],
        '/about' => ['title' => "About {$n} - Coaching Institute in {$area}",
            'description' => seo_text((seo_cms_page('about')['intro'] ?? '') ?: "{$n} is a coaching institute in {$area} for government job exams."), 'h1' => 'About ' . $n],
        '/contact' => ['title' => "Contact {$n} - Coaching Institute in " . ($s['area'] ? explode(',', $s['area'])[0] : 'Delhi'),
            'description' => seo_text("Visit {$n}" . ($s['address'] ? ", {$s['address']}" : '') . ($s['phone'] ? ", or call / WhatsApp {$s['phone']}" : '') . ' for admission, batch timings and fees.'),
            'h1' => 'Contact ' . $n],
        '/mock-tests' => ['title' => "Mock Tests & Practice Quizzes | {$n}",
            'description' => "Timed online mock tests and practice quizzes for government job exams, with instant scores, from {$n}, {$area}.", 'h1' => 'Mock tests'],
        '/live-classes' => ['title' => "Live Classes | {$n}", 'description' => "Upcoming live classes and doubt sessions from {$n}.", 'h1' => 'Live classes'],
        '/resources' => ['title' => "Free Study Resources for Government Exams | {$n}",
            'description' => "Free study material from {$n}: notes, practice sheets and links to official notices, NCERT books and previous-year papers.", 'h1' => 'Free study resources'],
        '/blog' => ['title' => "Exam Preparation Blog | {$n}", 'description' => "Preparation tips, exam updates and study strategy for government job exams from the {$n} team.", 'h1' => 'Blog'],
        '/jobs' => ['title' => "Latest Govt Job Notifications & Exam Alerts | {$n}",
            'description' => 'Government recruitment notifications, admit cards, results, answer keys and syllabus updates, with links to the official websites.', 'h1' => 'Latest government jobs'],
        '/careers' => ['title' => "Careers & Faculty Jobs at {$n}, New Delhi", 'description' => "Open teaching and staff positions at {$n}, {$area}. See the requirements and apply online.", 'h1' => 'Careers'],
        '/faq' => ['title' => "FAQs: Admission, Courses & Fees | {$n}", 'description' => "Answers to common questions about admission, courses, fees, classes and payments at {$n}, {$area}.", 'h1' => 'Frequently asked questions'],
        '/gallery' => ['title' => "Photo & Video Gallery | {$n}", 'description' => "Photos and videos from {$n} - classroom moments, events and campus life.", 'h1' => 'Gallery'],
        default => null,
    };
}

// ------------------------------------------------------------------
// Request normalisation (index.php): one URL per page
// ------------------------------------------------------------------

/**
 * The canonical location of a request, or null when it is already canonical:
 * duplicate and trailing slashes, upper-case letters, /index.php and /index.html, and the old
 * category filter URL /courses?category=<slug> (now /categories/<slug>). The query string is kept.
 */
function seo_normalize_request(string $path, array $query): ?string
{
    $clean = '/' . trim((string) preg_replace('#/{2,}#', '/', $path), '/');
    if (preg_match('#^/index\.(php|html?)$#i', $clean)) {
        $clean = '/';
    }
    if (preg_match('/[A-Z]/', $clean) && preg_match(SEO_ROUTES_RE, strtolower($clean))) {
        $clean = strtolower($clean);
    }
    $q = $query;
    if ($clean === '/courses' && isset($q['category']) && is_string($q['category']) && preg_match('/^[a-z0-9-]{1,120}$/', $q['category'])) {
        $clean = seo_category_path($q['category']); // one category; "a,b" (several) stays a filter on /courses
        unset($q['category']);
    }
    if ($clean === $path && $q === $query) {
        return null;
    }
    $qs = http_build_query($q);
    return $clean . ($qs !== '' ? '?' . $qs : '');
}

// ------------------------------------------------------------------
// The resolver
// ------------------------------------------------------------------

/**
 * Everything search engines and link previews need for one public path ("/courses/x") and its query.
 * Keys: path, kind, status (200|404), title, description, canonical (null = none), robots,
 * noindex_reason, image, image_width, image_height, image_alt, type, og_title, og_description, h1,
 * jsonld (list of nodes), crumbs, lastmod, body (callable → HTML for <noscript>), focus_keyword,
 * secondary_keywords, override (seo_pages row id).
 */
function seo_resolve(string $path, array $query = []): array
{
    $s = seo_site();
    [$img, $imgW, $imgH] = seo_default_image();
    $m = [
        'path' => $path, 'kind' => 'page', 'status' => 200,
        'title' => (string) setting('default_seo_title', $s['name']), 'description' => (string) setting('default_seo_description', ''),
        'canonical' => seo_url($path), 'robots' => 'index,follow', 'noindex_reason' => null,
        'image' => $img, 'image_width' => $imgW, 'image_height' => $imgH, 'image_alt' => $s['name'], 'type' => 'website',
        'og_title' => null, 'og_description' => null, 'h1' => null, 'jsonld' => [], 'crumbs' => [], 'lastmod' => null, 'body' => null,
        'focus_keyword' => null, 'secondary_keywords' => null, 'override' => null,
    ];
    $noindex = function (string $reason, string $robots = 'noindex,follow') use (&$m) {
        $m['robots'] = $robots;
        $m['noindex_reason'] = $reason;
    };

    if (preg_match(SEO_PRIVATE_RE, $path)) {
        $m['kind'] = 'private';
        $m['canonical'] = null;
        $noindex('Account, checkout or exam page', 'noindex,nofollow');
        if (!preg_match(SEO_ROUTES_RE, $path)) {
            $m['status'] = 404;
        }
        return seo_finish($m);
    }

    if (preg_match('#^/courses/([a-z0-9-]+)$#', $path, $mm)) {
        seo_resolve_course($m, $mm[1]);
    } elseif (preg_match('#^/categories/([a-z0-9-]+)$#', $path, $mm)) {
        seo_resolve_category($m, $mm[1], $query);
    } elseif (preg_match('#^/blog/([a-z0-9-]+)$#', $path, $mm)) {
        seo_resolve_post($m, $mm[1]);
    } elseif (preg_match('#^/(p|careers|jobs|gallery|resources|mock-tests)/([a-z0-9-]+)$#', $path, $mm)) {
        seo_resolve_detail($m, $mm[1], $mm[2]);
    } elseif ($d = seo_static_defaults($path)) {
        $m['kind'] = $path === '/' ? 'home' : (isset(SEO_POLICY_PAGES[$path]) ? 'policy' : 'static');
        $m['title'] = $d['title'];
        $m['description'] = $d['description'] ?: $m['description'];
        $m['h1'] = $d['h1'] ?? null;
        seo_resolve_static($m, $query);
    } else {
        $m['status'] = preg_match(SEO_ROUTES_RE, $path) ? 200 : 404;
    }

    // Admin → SEO → Page SEO: a row for this path overrides the computed values.
    if ($m['status'] === 200) {
        try {
            $row = db_one('SELECT * FROM seo_pages WHERE path = ?', [$path]);
        } catch (PDOException $e) {
            $row = null;
        }
        if ($row) {
            $m['override'] = (int) $row['id'];
            $m['title'] = $row['title'] ?: $m['title'];
            $m['description'] = $row['meta_description'] ?: $m['description'];
            $m['og_title'] = $row['og_title'] ?: null;
            $m['og_description'] = $row['og_description'] ?: null;
            $m['canonical'] = $row['canonical_url'] ?: $m['canonical'];
            if ($row['og_image'] && ($u = public_file_url($row['og_image']))) {
                [$m['image'], $m['image_width'], $m['image_height']] = [$u, 1200, 630];
            }
            if ($row['robots'] !== 'index,follow') {
                $noindex('Set to noindex in Page SEO', $row['robots']);
            }
            $m['focus_keyword'] = $row['focus_keyword'] ?? null;
            $m['secondary_keywords'] = $row['secondary_keywords'] ?? null;
            // Custom JSON-LD is added next to the automatic nodes (one object, a list, or an @graph)
            $custom = json_decode_array($row['structured_data']);
            if ($custom) {
                $nodes = isset($custom['@graph']) ? $custom['@graph'] : (array_is_list($custom) ? $custom : [$custom]);
                foreach ($nodes as $node) {
                    if (is_array($node) && isset($node['@type'])) {
                        unset($node['@context']);
                        $m['jsonld'][] = $node;
                    }
                }
            }
            if (($row['updated_at'] ?? null) && (!$m['lastmod'] || $row['updated_at'] > $m['lastmod'])) {
                $m['lastmod'] = $row['updated_at'];
            }
        }
    }
    return seo_finish($m);
}

/** 404 defaults, breadcrumb node, image alt. */
function seo_finish(array $m): array
{
    $site = seo_site()['name'];
    if ($m['status'] === 404) {
        $m['kind'] = 'not_found';
        $m['title'] = 'Page not found | ' . $site;
        $m['description'] = (string) setting('default_seo_description', '');
        $m['robots'] = 'noindex,follow';
        $m['noindex_reason'] = 'Page not found (404)';
        $m['canonical'] = null;
        $m['jsonld'] = [];
        $m['crumbs'] = [];
        $m['h1'] = 'Page not found';
        $m['body'] = fn() => '<h1>Page not found</h1><p>This page does not exist. Browse our <a href="' . e(seo_url('/courses')) . '">courses</a> or go to the <a href="' . e(seo_url('/')) . '">homepage</a>.</p>';
    }
    if ($crumb = seo_breadcrumb_node($m['crumbs'])) {
        $m['jsonld'][] = $crumb;
    }
    $m['title'] = trim((string) $m['title']);
    $m['description'] = trim((string) $m['description']);
    return $m;
}

function seo_resolve_course(array &$m, string $slug): void
{
    $extras = course_extras_enabled();
    $row = db_one('SELECT ' . course_card_columns() . ', c.description, c.learning_outcomes, c.includes, c.faqs, c.seo_title, c.seo_description, c.updated_at, c.is_demo AS demo'
        . ($extras ? ', c.fee_items, c.syllabus, c.info_sections' : ', NULL AS fee_items, NULL AS syllabus, NULL AS info_sections')
        . ", c.category_id, cat.parent_id AS category_parent_id
         FROM courses c JOIN course_categories cat ON cat.id = c.category_id AND cat.is_active = 1
         WHERE c.slug = ? AND c.status = 'published'", [$slug]);
    if (!$row) {
        $m['status'] = 404;
        return;
    }
    $s = seo_site();
    $card = format_course_card($row);
    $syllabus = course_syllabus($row['syllabus']);
    $m['kind'] = 'course';
    $m['title'] = $row['seo_title'] ?: $row['title'] . ' | ' . $s['name'];
    $m['description'] = $row['seo_description'] ?: seo_text((string) ($row['short_description'] ?: $row['description']));
    $m['h1'] = $row['title'];
    $m['lastmod'] = $row['updated_at'];
    if ($card['thumbnail_url']) {
        [$m['image'], $m['image_width'], $m['image_height']] = [$card['thumbnail_url'], null, null];
        $m['image_alt'] = $row['title'] . ' - ' . $s['name'];
    }
    if ($row['demo']) {
        $m['robots'] = 'noindex,follow';
        $m['noindex_reason'] = 'Demo content';
    }
    $m['crumbs'] = [['Home', '/'], ['Courses', '/courses'], [$row['category_name'], seo_category_path($row['category_slug'])], [$row['title'], '/courses/' . $slug]];
    $m['jsonld'][] = seo_course_node($row, $card, $syllabus);
    $m['body'] = fn() => seo_body_course($row, $card, $syllabus);
}

function seo_resolve_category(array &$m, string $slug, array $query): void
{
    $cat = seo_category($slug);
    if (!$cat) {
        $m['status'] = 404;
        return;
    }
    $s = seo_site();
    $cards = seo_courses((int) $cat['id']);
    $m['kind'] = 'category';
    $m['title'] = $cat['seo_title'] ?: $cat['name'] . ' Courses | ' . $s['name'];
    $m['description'] = $cat['seo_description'] ?: seo_text(($cat['intro'] ?? '') ?: ($cat['description'] ?: $cat['name'] . ' courses at ' . $s['name'] . ', ' . $s['area'] . '.'));
    $m['h1'] = $cat['name'] . ' courses';
    $m['lastmod'] = $cat['updated_at'];
    $latest = $cards ? db_value('SELECT MAX(c.updated_at) FROM courses c JOIN course_categories cat ON cat.id = c.category_id WHERE c.status = \'published\' AND (cat.id = ? OR cat.parent_id = ?)', [$cat['id'], $cat['id']]) : null;
    if ($latest && $latest > $m['lastmod']) {
        $m['lastmod'] = $latest;
    }
    if (!$cards) {
        $m['robots'] = 'noindex,follow';
        $m['noindex_reason'] = 'No published courses in this category';
    }
    $page = max(1, (int) ($query['page'] ?? 1));
    if ($page > 1) {
        $m['canonical'] = seo_url(seo_category_path($slug)) . '?page=' . $page;
    }
    $m['crumbs'] = array_values(array_filter([['Home', '/'], ['Courses', '/courses'],
        $cat['parent_slug'] ? [$cat['parent_name'], seo_category_path($cat['parent_slug'])] : null, [$cat['name'], seo_category_path($slug)]]));
    $m['jsonld'][] = array_filter([
        '@type' => 'CollectionPage', '@id' => seo_url(seo_category_path($slug)), 'url' => seo_url(seo_category_path($slug)), 'name' => $m['title'],
        'description' => $m['description'], 'isPartOf' => ['@id' => $s['url'] . '#website'], 'about' => ['@id' => seo_org_id()],
        'mainEntity' => seo_course_list_node($cards, $cat['name'] . ' courses'),
    ]);
    $m['body'] = fn() => seo_body_category($cat, $cards);
}

function seo_resolve_post(array &$m, string $slug): void
{
    $p = db_one("SELECT p.title, p.excerpt, p.content, p.seo_title, p.seo_description, p.featured_image_path, p.published_at, p.updated_at, p.is_demo, p.tags,
                        u.name AS author_name, bc.name AS category_name
                 FROM blog_posts p LEFT JOIN users u ON u.id = p.author_id LEFT JOIN blog_categories bc ON bc.id = p.category_id
                 WHERE p.slug = ? AND p.status IN ('published','scheduled') AND p.published_at <= NOW()", [$slug]);
    if (!$p) {
        $m['status'] = 404;
        return;
    }
    $s = seo_site();
    $url = seo_url('/blog/' . $slug);
    $m['kind'] = 'post';
    $m['type'] = 'article';
    $m['title'] = $p['seo_title'] ?: $p['title'] . ' | ' . $s['name'] . ' Blog';
    $m['description'] = $p['seo_description'] ?: seo_text((string) ($p['excerpt'] ?: $p['content']));
    $m['h1'] = $p['title'];
    $m['lastmod'] = $p['updated_at'];
    if ($u = public_file_url($p['featured_image_path'])) {
        [$m['image'], $m['image_width'], $m['image_height'], $m['image_alt']] = [$u, null, null, $p['title']];
    }
    if ($p['is_demo']) {
        $m['robots'] = 'noindex,follow';
        $m['noindex_reason'] = 'Demo content';
    }
    $m['crumbs'] = [['Home', '/'], ['Blog', '/blog'], [$p['title'], '/blog/' . $slug]];
    $m['jsonld'][] = array_filter([
        '@type' => 'BlogPosting', '@id' => $url . '#article', 'mainEntityOfPage' => $url, 'headline' => mb_substr((string) $p['title'], 0, 110),
        'description' => $m['description'], 'image' => public_file_url($p['featured_image_path']),
        'datePublished' => date(DATE_ATOM, strtotime((string) $p['published_at'])),
        // dateModified only when the post was edited after it went live
        'dateModified' => strtotime((string) $p['updated_at']) > strtotime((string) $p['published_at']) ? date(DATE_ATOM, strtotime((string) $p['updated_at'])) : null,
        'author' => $p['author_name'] ? ['@type' => 'Person', 'name' => $p['author_name']] : seo_org_node(false),
        'publisher' => seo_org_node(false) + ['logo' => ['@type' => 'ImageObject', 'url' => $s['logo']]],
        'articleSection' => $p['category_name'] ?: null,
        'keywords' => $p['tags'] ?: null,
        'inLanguage' => 'en-IN',
    ]);
    $m['body'] = fn() => '<article><h1>' . e($p['title']) . '</h1><p>By ' . e($p['author_name'] ?: $s['name']) . ' · ' . e(date('j F Y', strtotime((string) $p['published_at']))) . '</p>'
        . sanitize_html((string) $p['content']) . '</article>';
}

/** Custom pages, careers, job alerts, gallery albums, resources and mock tests. */
function seo_resolve_detail(array &$m, string $type, string $slug): void
{
    $s = seo_site();
    $site = $s['name'];
    switch ($type) {
        case 'p':
            $pg = db_one("SELECT title, seo_title, seo_description, status, updated_at FROM pages WHERE slug = ? AND status <> 'archived'", [$slug]);
            $staff = (current_user()['user_type'] ?? null) === 'staff';
            if (!$pg || ($pg['status'] !== 'published' && !$staff)) {
                $m['status'] = 404;
                return;
            }
            $m['title'] = $pg['seo_title'] ?: $pg['title'] . ' | ' . $site;
            $m['description'] = $pg['seo_description'] ?: $m['description'];
            $m['h1'] = $pg['title'];
            $m['lastmod'] = $pg['updated_at'];
            $m['crumbs'] = [['Home', '/'], [$pg['title'], '/p/' . $slug]];
            if ($pg['status'] !== 'published') {
                $m['robots'] = 'noindex,nofollow';
                $m['noindex_reason'] = 'Draft page (staff preview)';
            }
            return;

        case 'careers':
            $job = db_one("SELECT title, description, employment_type, salary_range, closes_at, created_at, updated_at, responsibilities, requirements, is_demo FROM job_openings
                           WHERE slug = ? AND status = 'open' AND (closes_at IS NULL OR closes_at >= CURDATE())", [$slug]);
            if (!$job) {
                $m['status'] = 404;
                return;
            }
            $m['kind'] = 'job';
            $m['title'] = $job['title'] . ' - Apply Online | ' . $site;
            $m['description'] = seo_text((string) ($job['description'] ?: $job['title'] . ' at ' . $site));
            $m['h1'] = $job['title'];
            $m['lastmod'] = $job['updated_at'];
            $m['crumbs'] = [['Home', '/'], ['Careers', '/careers'], [$job['title'], '/careers/' . $slug]];
            if ($job['is_demo']) {
                $m['robots'] = 'noindex,follow';
                $m['noindex_reason'] = 'Demo content';
                return; // never JobPosting markup for a sample opening
            }
            // Google for Jobs (JobPosting structured data)
            $list = fn(?string $json) => implode('', array_map(fn($i) => '<li>' . e((string) $i) . '</li>', json_decode_array($json)));
            $types = ['full_time' => 'FULL_TIME', 'part_time' => 'PART_TIME', 'contract' => 'CONTRACTOR', 'internship' => 'INTERN', 'visiting' => 'OTHER'];
            $m['jsonld'][] = array_filter([
                '@type' => 'JobPosting',
                'title' => $job['title'],
                'description' => '<p>' . e((string) $job['description']) . '</p>'
                    . ($job['responsibilities'] ? '<p>Responsibilities:</p><ul>' . $list($job['responsibilities']) . '</ul>' : '')
                    . ($job['requirements'] ? '<p>Skills and qualifications:</p><ul>' . $list($job['requirements']) . '</ul>' : ''),
                'datePosted' => date('Y-m-d', strtotime((string) $job['created_at'])),
                'validThrough' => $job['closes_at'] ? $job['closes_at'] . 'T23:59:59+05:30' : null,
                'employmentType' => array_values(array_intersect_key($types, array_flip(explode(',', (string) $job['employment_type'])))) ?: null,
                'hiringOrganization' => ['@type' => 'Organization', 'name' => $site, 'sameAs' => $s['url'], 'logo' => $s['logo']],
                'jobLocation' => ['@type' => 'Place', 'address' => seo_postal_address() ?? ['@type' => 'PostalAddress', 'addressLocality' => 'New Delhi', 'addressCountry' => 'IN']],
                'directApply' => true,
            ]);
            return;

        case 'jobs':
            $a = db_one("SELECT title, organization, summary, updated_at, is_demo FROM job_alerts WHERE slug = ? AND status = 'published' AND (published_at IS NULL OR published_at <= NOW())", [$slug]);
            if (!$a) {
                $m['status'] = 404;
                return;
            }
            $m['type'] = 'article';
            $m['title'] = $a['title'] . ' - ' . $a['organization'] . ' | ' . $site;
            $m['description'] = seo_text((string) ($a['summary'] ?: $a['title'] . ' - official notification from ' . $a['organization'] . '.'));
            $m['h1'] = $a['title'];
            $m['lastmod'] = $a['updated_at'];
            $m['crumbs'] = [['Home', '/'], ['Govt jobs', '/jobs'], [$a['title'], '/jobs/' . $slug]];
            if ($a['is_demo']) {
                $m['robots'] = 'noindex,follow';
                $m['noindex_reason'] = 'Demo content';
            }
            return;

        case 'gallery':
            try {
                // The chosen cover is ignored when it is the file of a hidden item (same rule as api/gallery.php).
                $al = db_one("SELECT a.title, a.description, a.event_date, a.updated_at,
                                     CASE WHEN a.cover_image_path IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gallery_items h
                                            WHERE h.album_id = a.id AND h.is_active = 0 AND a.cover_image_path IN (h.image_path, h.thumb_path))
                                          THEN a.cover_image_path END AS cover_image_path,
                                     (SELECT COALESCE(i.thumb_path, i.image_path) FROM gallery_items i WHERE i.album_id = a.id AND i.is_active = 1 AND i.type = 'image' ORDER BY i.sort_order, i.id LIMIT 1) AS first_image
                              FROM gallery_albums a WHERE a.slug = ? AND a.is_active = 1", [$slug]);
            } catch (PDOException $e) {
                $al = null;
            }
            if (!$al) {
                $m['status'] = 404;
                return;
            }
            $m['title'] = $al['title'] . ' - Photo Gallery | ' . $site;
            $m['description'] = seo_text((string) ($al['description'] ?: 'Photos and videos from ' . $al['title']
                . ($al['event_date'] ? ' (' . date('j M Y', strtotime((string) $al['event_date'])) . ')' : '') . ' at ' . $site . '.'));
            $m['h1'] = $al['title'];
            $m['lastmod'] = $al['updated_at'];
            if ($u = public_file_url($al['cover_image_path'] ?: $al['first_image'])) {
                [$m['image'], $m['image_width'], $m['image_height'], $m['image_alt']] = [$u, null, null, $al['title']];
            }
            $m['crumbs'] = [['Home', '/'], ['Gallery', '/gallery'], [$al['title'], '/gallery/' . $slug]];
            return;

        case 'resources':
            $r = db_one("SELECT title, description, seo_title, seo_description, updated_at, is_demo FROM learning_resources WHERE slug = ? AND status = 'published'", [$slug]);
            if (!$r) {
                $m['status'] = 404;
                return;
            }
            $m['title'] = $r['seo_title'] ?: $r['title'] . ' | ' . $site;
            $m['description'] = $r['seo_description'] ?: seo_text((string) $r['description']);
            $m['h1'] = $r['title'];
            $m['lastmod'] = $r['updated_at'];
            $m['crumbs'] = [['Home', '/'], ['Resources', '/resources'], [$r['title'], '/resources/' . $slug]];
            if ($r['is_demo']) {
                $m['robots'] = 'noindex,follow';
                $m['noindex_reason'] = 'Demo content';
            }
            return;

        case 'mock-tests':
            $ex = db_one("SELECT title, description, updated_at, is_demo FROM exams WHERE slug = ? AND status = 'published'", [$slug]);
            if (!$ex) {
                $m['status'] = 404;
                return;
            }
            $m['title'] = $ex['title'] . ' - Mock Test | ' . $site;
            $m['description'] = seo_text((string) $ex['description']) ?: $m['description'];
            $m['h1'] = $ex['title'];
            $m['lastmod'] = $ex['updated_at'];
            $m['crumbs'] = [['Home', '/'], ['Mock tests', '/mock-tests'], [$ex['title'], '/mock-tests/' . $slug]];
            if ($ex['is_demo']) {
                $m['robots'] = 'noindex,follow';
                $m['noindex_reason'] = 'Demo content';
            }
            return;
    }
}

/** Static pages: empty listings, search/filter URLs, home/contact structured data, breadcrumbs, text versions. */
function seo_resolve_static(array &$m, array $query): void
{
    $path = $m['path'];
    $s = seo_site();
    $count = seo_listing_count($path);
    if ($count === 0) {
        $m['robots'] = 'noindex,follow';
        $m['noindex_reason'] = 'Nothing published on this page yet (indexed again automatically once content is published)';
    }
    if ($path === '/courses') {
        if (trim((string) ($query['q'] ?? '')) !== '') {
            // Internal search results are kept out of search engines
            $m['robots'] = 'noindex,follow';
            $m['noindex_reason'] = 'Search results';
            $m['canonical'] = null;
        } elseif (max(1, (int) ($query['page'] ?? 1)) > 1) {
            $m['canonical'] = seo_url('/courses') . '?page=' . (int) $query['page'];
        }
        $cards = seo_courses();
        $m['lastmod'] = $cards ? db_value("SELECT MAX(updated_at) FROM courses WHERE status = 'published'") : null;
        $list = seo_course_list_node($cards, 'Courses at ' . $s['name']);
        $m['jsonld'][] = array_filter(['@type' => 'CollectionPage', '@id' => seo_url('/courses'), 'url' => seo_url('/courses'), 'name' => $m['title'],
            'description' => $m['description'], 'isPartOf' => ['@id' => $s['url'] . '#website'], 'mainEntity' => $list]);
    }
    if (isset(SEO_POLICY_PAGES[$path]) || $path === '/about') {
        $m['lastmod'] = seo_cms_page(SEO_POLICY_PAGES[$path] ?? 'about')['updated_at'] ?? null;
    }
    if ($path === '/') {
        $m['jsonld'][] = seo_org_node();
        $m['jsonld'][] = seo_website_node();
        $m['lastmod'] = db_value("SELECT MAX(updated_at) FROM courses WHERE status = 'published'") ?: null;
    } elseif (in_array($path, ['/contact', '/about'], true)) {
        $m['jsonld'][] = seo_org_node();
        $m['jsonld'][] = ['@type' => $path === '/contact' ? 'ContactPage' : 'AboutPage', 'url' => seo_url($path), 'name' => $m['title'], 'about' => ['@id' => seo_org_id()]];
    }
    if ($path !== '/') {
        $m['crumbs'] = [['Home', '/'], [trim((string) preg_replace('/\s*\|.*$/', '', $m['h1'] ?: $m['title'])), $path]];
    }
    $m['body'] = fn() => seo_body_static($path, $m);
}

// ------------------------------------------------------------------
// <noscript> text versions (the same facts the React page shows)
// ------------------------------------------------------------------

function seo_a(string $path, string $label): string
{
    return '<a href="' . e(seo_url($path)) . '">' . e($label) . '</a>';
}

/** Site header links + breadcrumb + page HTML + contact footer. */
function seo_body_wrap(string $main, array $crumbs): string
{
    $s = seo_site();
    $nav = [seo_a('/', 'Home'), seo_a('/courses', 'Courses')];
    foreach (seo_categories() as $c) {
        $nav[] = seo_a(seo_category_path($c['slug']), $c['name']);
    }
    foreach (['/mock-tests' => 'Mock Tests', '/resources' => 'Free Resources', '/jobs' => 'Govt Jobs', '/blog' => 'Blog'] as $p => $l) {
        if (seo_listing_count($p) > 0) {
            $nav[] = seo_a($p, $l);
        }
    }
    $nav[] = seo_a('/about', 'About Us');
    $nav[] = seo_a('/contact', 'Contact Us');
    $bc = '';
    if (count($crumbs) > 1) {
        $bc = '<nav aria-label="Breadcrumb"><ol>' . implode('', array_map(fn($c) => '<li>' . seo_a($c[1], $c[0]) . '</li>', $crumbs)) . '</ol></nav>';
    }
    $contact = array_filter([
        $s['address'] ? e($s['address']) : null,
        $s['phone'] ? 'Phone / WhatsApp: <a href="tel:' . e(preg_replace('/[^\d+]/', '', $s['phone'])) . '">' . e($s['phone']) . '</a>' : null,
        $s['email'] ? 'Email: <a href="mailto:' . e($s['email']) . '">' . e($s['email']) . '</a>' : null,
        $s['hours'] ? 'Hours: ' . e($s['hours']) : null,
    ]);
    $policies = [];
    foreach (SEO_POLICY_PAGES as $p => $key) {
        if ($page = seo_cms_page($key)) {
            $policies[] = seo_a($p, (string) ($page['title'] ?? $p));
        }
    }
    return '<div class="lx-ns"><p><em>Text version of ' . e($s['name']) . '. Turn on JavaScript for the full website.</em></p>'
        . '<header><p><strong>' . seo_a('/', $s['name']) . '</strong></p><nav aria-label="Main">' . implode(' · ', $nav) . '</nav></header>'
        . '<main>' . $bc . $main . '</main>'
        . '<footer><p><strong>' . e($s['name']) . '</strong>' . ($contact ? '<br>' . implode('<br>', $contact) : '') . '</p>'
        . ($policies ? '<p>' . implode(' · ', $policies) . '</p>' : '')
        . '<p>© ' . date('Y') . ' ' . e($s['legal_name'] ?: $s['name']) . '. All rights reserved.</p></footer></div>';
}

/** <ul> of course cards: title link, subtitle, duration and fee. */
function seo_body_course_list(array $cards): string
{
    if (!$cards) {
        return '<p>No courses are published in this category yet.</p>';
    }
    $li = array_map(fn($c) => '<li>' . seo_a('/courses/' . $c['slug'], $c['title'])
        . ($c['subtitle'] ? ' - ' . e($c['subtitle']) : '')
        . '<br>' . implode(' · ', array_filter([$c['duration'] ? 'Duration: ' . e($c['duration']) : null, 'Fee: ' . e(seo_price_label($c)), e(COURSE_TYPE_LABELS[$c['course_type']] ?? '')]))
        . ($c['short_description'] ? '<br>' . e($c['short_description']) : '') . '</li>', $cards);
    return '<ul>' . implode('', $li) . '</ul>';
}

function seo_body_faqs(array $faqs, string $heading = 'Frequently asked questions'): string
{
    if (!$faqs) {
        return '';
    }
    return '<h2>' . e($heading) . '</h2>' . implode('', array_map(fn($f) => '<h3>' . e((string) ($f['q'] ?? '')) . '</h3><p>' . nl2br(e((string) ($f['a'] ?? ''))) . '</p>', $faqs));
}

function seo_body_course(array $row, array $card, array $syllabus): string
{
    $s = seo_site();
    $h = '<article><h1>' . e($row['title']) . '</h1>'
        . ($row['subtitle'] ? '<p><strong>' . e($row['subtitle']) . '</strong></p>' : '')
        . '<p>' . implode(' · ', array_filter([
            'Category: ' . seo_a(seo_category_path($row['category_slug']), $row['category_name']),
            $row['duration_label'] ? 'Duration: ' . e($row['duration_label']) : null,
            'Fee: ' . e(seo_price_label($card)),
            e(COURSE_TYPE_LABELS[$row['course_type']] ?? ''),
            'Language: ' . e(ucfirst((string) $row['language'])),
        ])) . '</p>'
        . text_to_html((string) ($row['description'] ?: $row['short_description']));
    $list = fn(string $title, array $items) => $items ? '<h2>' . e($title) . '</h2><ul>' . implode('', array_map(fn($i) => '<li>' . e(is_array($i) ? (string) ($i['text'] ?? '') : (string) $i) . '</li>', $items)) . '</ul>' : '';
    $h .= $list('What you will learn', json_decode_array($row['learning_outcomes']));
    $h .= $list('This course includes', json_decode_array($row['includes']));
    if ($fees = course_fee_items($row['fee_items'])) {
        $h .= '<h2>Fees</h2><ul><li>Course fee: ' . e(seo_price_label($card)) . '</li>'
            . implode('', array_map(fn($f) => '<li>' . e($f['label']) . ': ' . e(seo_money($f['amount'])) . ($f['note'] ? ' - ' . e($f['note']) : '') . '</li>', $fees)) . '</ul>';
    }
    if ($syllabus) {
        $h .= '<h2>Syllabus</h2>' . implode('', array_map(fn($sec) => '<h3>' . e($sec['title']) . '</h3><p>' . e(implode(', ', $sec['topics'])) . '</p>', $syllabus));
    }
    foreach (course_info_sections($row['info_sections']) as $sec) {
        $h .= '<h2>' . e($sec['title']) . '</h2>' . text_to_html($sec['body']);
    }
    $h .= seo_body_faqs(json_decode_array($row['faqs']));
    $how = course_admission($row) === 'enquiry' ? 'Apply Now on the course page' : 'Enroll Now on the course page' . (course_admission($row) === 'both' ? ', or Apply Now for a free counselling call' : '');
    $h .= '<h2>Admission</h2><p>' . e($how) . '.' . ($s['phone'] ? ' Call or WhatsApp <a href="tel:' . e(preg_replace('/[^\d+]/', '', $s['phone'])) . '">' . e($s['phone']) . '</a>.' : '') . '</p></article>';
    $related = array_filter(seo_courses((int) ($row['category_parent_id'] ?: $row['category_id'])), fn($c) => $c['slug'] !== $row['slug']);
    if ($related) {
        $h .= '<h2>Related courses</h2>' . seo_body_course_list(array_slice(array_values($related), 0, 6));
    }
    return seo_body_wrap($h, [['Home', '/'], ['Courses', '/courses'], [$row['category_name'], seo_category_path($row['category_slug'])], [$row['title'], '/courses/' . $row['slug']]]);
}

function seo_body_category(array $cat, array $cards): string
{
    $ex = seo_category_extras($cat);
    $h = '<h1>' . e($cat['name']) . ' courses</h1>'
        . ($cat['description'] ? '<p><strong>' . e($cat['description']) . '</strong></p>' : '')
        . text_to_html($ex['intro'])
        . '<h2>' . e($cat['name']) . ' courses at ' . e(seo_site()['name']) . '</h2>' . seo_body_course_list($cards)
        . seo_body_faqs($ex['faqs']);
    if ($ex['related']) {
        $h .= '<h2>Other exam categories</h2><ul>' . implode('', array_map(fn($r) => '<li>' . seo_a(seo_category_path($r['slug']), $r['name']) . '</li>', $ex['related'])) . '</ul>';
    }
    $crumbs = array_values(array_filter([['Home', '/'], ['Courses', '/courses'], $cat['parent_slug'] ? [$cat['parent_name'], seo_category_path($cat['parent_slug'])] : null, [$cat['name'], seo_category_path($cat['slug'])]]));
    return seo_body_wrap($h, $crumbs);
}

function seo_body_static(string $path, array $m): string
{
    $s = seo_site();
    $h = '<h1>' . e((string) $m['h1']) . '</h1>';
    $intro = fn(string $text) => $text !== '' ? '<p>' . e($text) . '</p>' : '';
    switch (true) {
        case $path === '/':
            $h .= $intro((string) setting('home_intro', ''));
            $h .= '<h2>Exam categories</h2><ul>' . implode('', array_map(fn($c) => '<li>' . seo_a(seo_category_path($c['slug']), $c['name'])
                . ' (' . $c['course_count'] . ' ' . ($c['course_count'] === 1 ? 'course' : 'courses') . ')' . ($c['description'] ? ' - ' . e($c['description']) : '') . '</li>', seo_categories())) . '</ul>';
            $h .= '<h2>Courses</h2>' . seo_body_course_list(seo_courses());
            break;
        case $path === '/courses':
            $h .= $intro($m['description']);
            foreach (seo_categories() as $c) {
                $h .= '<h2>' . seo_a(seo_category_path($c['slug']), $c['name']) . '</h2>' . seo_body_course_list(seo_courses((int) $c['id']));
            }
            break;
        case $path === '/categories':
            $h .= '<ul>' . implode('', array_map(fn($c) => '<li>' . seo_a(seo_category_path($c['slug']), $c['name']) . ($c['description'] ? ' - ' . e($c['description']) : '') . '</li>', seo_categories())) . '</ul>';
            break;
        case $path === '/about' || isset(SEO_POLICY_PAGES[$path]):
            $page = seo_cms_page(SEO_POLICY_PAGES[$path] ?? 'about') ?? [];
            $h .= text_to_html((string) ($page['intro'] ?? ''));
            foreach ($page['sections'] ?? [] as $sec) {
                $h .= (!empty($sec['heading']) ? '<h2>' . e((string) $sec['heading']) . '</h2>' : '') . text_to_html((string) ($sec['body'] ?? ''));
            }
            break;
        case $path === '/contact':
            $h .= '<ul>' . implode('', array_filter([
                $s['address'] ? '<li>Address: ' . e($s['address']) . (seo_map_url() ? ' (<a href="' . e(seo_map_url()) . '">Get directions on Google Maps</a>)' : '') . '</li>' : null,
                $s['phone'] ? '<li>Phone: <a href="tel:' . e(preg_replace('/[^\d+]/', '', $s['phone'])) . '">' . e($s['phone']) . '</a></li>' : null,
                $s['whatsapp'] ? '<li>WhatsApp: <a href="https://wa.me/' . e($s['whatsapp']) . '">Chat on WhatsApp</a></li>' : null,
                $s['email'] ? '<li>Email: <a href="mailto:' . e($s['email']) . '">' . e($s['email']) . '</a></li>' : null,
                $s['hours'] ? '<li>Office hours: ' . e($s['hours']) . '</li>' : null,
            ])) . '</ul>';
            break;
        case $path === '/faq':
            $h .= seo_body_faqs(array_map(fn($f) => ['q' => $f['question'], 'a' => seo_text((string) $f['answer'], 2000)],
                db_all('SELECT question, answer FROM faqs WHERE is_active = 1 ORDER BY sort_order, id LIMIT 100')), 'Questions and answers');
            break;
        default:
            $h .= $intro($m['description']);
            $items = seo_listing_links($path);
            if ($items) {
                $h .= '<ul>' . implode('', array_map(fn($i) => '<li>' . seo_a($i[0], $i[1]) . '</li>', $items)) . '</ul>';
            }
    }
    return seo_body_wrap($h, $m['crumbs']);
}

/** [path, label] links of a listing page's items (newest first, at most 50). */
function seo_listing_links(string $path): array
{
    try {
        $rows = match ($path) {
            '/blog' => db_all("SELECT CONCAT('/blog/', slug) AS p, title AS l FROM blog_posts WHERE status IN ('published','scheduled') AND published_at <= NOW() AND is_demo = 0 ORDER BY published_at DESC LIMIT 50"),
            '/mock-tests' => db_all("SELECT CONCAT('/mock-tests/', slug) AS p, title AS l FROM exams WHERE status = 'published' AND is_demo = 0 ORDER BY id DESC LIMIT 50"),
            '/jobs' => db_all("SELECT CONCAT('/jobs/', slug) AS p, title AS l FROM job_alerts WHERE status = 'published' AND is_demo = 0 AND (published_at IS NULL OR published_at <= NOW()) ORDER BY COALESCE(published_at, created_at) DESC LIMIT 50"),
            '/resources' => db_all("SELECT CONCAT('/resources/', r.slug) AS p, r.title AS l FROM learning_resources r JOIN resource_categories rc ON rc.id = r.category_id AND rc.is_active = 1
                                    WHERE r.status = 'published' AND r.is_demo = 0 AND (r.published_at IS NULL OR r.published_at <= NOW()) ORDER BY r.id DESC LIMIT 50"),
            '/careers' => db_all("SELECT CONCAT('/careers/', slug) AS p, title AS l FROM job_openings WHERE status = 'open' AND is_demo = 0 AND (closes_at IS NULL OR closes_at >= CURDATE()) ORDER BY id DESC LIMIT 50"),
            '/gallery' => db_all('SELECT CONCAT(\'/gallery/\', a.slug) AS p, a.title AS l FROM gallery_albums a WHERE a.is_active = 1 AND EXISTS (SELECT 1 FROM gallery_items i WHERE i.album_id = a.id AND i.is_active = 1) ORDER BY a.sort_order, a.id DESC LIMIT 50'),
            default => [],
        };
    } catch (PDOException $e) {
        $rows = [];
    }
    return array_map(fn($r) => [$r['p'], $r['l']], $rows);
}

// ------------------------------------------------------------------
// Output
// ------------------------------------------------------------------

/** robots value actually sent: development / staging copies are never indexed. */
function seo_robots_sent(array $m): string
{
    return is_production() ? $m['robots'] : 'noindex,nofollow';
}

/** The values the React app applies after client-side navigation (api/cms.php?action=seo). */
function seo_client_meta(array $m): array
{
    return [
        'path' => $m['path'], 'status' => $m['status'], 'kind' => $m['kind'],
        'title' => $m['title'], 'description' => $m['description'], 'meta_description' => $m['description'],
        'canonical' => $m['canonical'], 'robots' => seo_robots_sent($m),
        'og_title' => $m['og_title'], 'og_description' => $m['og_description'], 'og_image' => $m['image'], 'image_alt' => $m['image_alt'],
        'type' => $m['type'],
    ];
}

/** <head> tags for index.php (title excluded). */
function seo_head_tags(array $m): string
{
    $s = seo_site();
    $title = $m['og_title'] ?? $m['title'];
    $desc = $m['og_description'] ?? $m['description'];
    $t = '<meta name="description" content="' . e($m['description']) . '">'
        . ($m['canonical'] ? '<link rel="canonical" href="' . e($m['canonical']) . '">' : '')
        . '<meta name="robots" content="' . e(seo_robots_sent($m)) . ($m['robots'] === 'index,follow' && is_production() ? ',max-image-preview:large' : '') . '">'
        . '<meta property="og:site_name" content="' . e($s['name']) . '">'
        . '<meta property="og:locale" content="en_IN">'
        . '<meta property="og:type" content="' . e($m['type']) . '">'
        . '<meta property="og:title" content="' . e($title) . '">'
        . '<meta property="og:description" content="' . e($desc) . '">'
        . ($m['canonical'] ? '<meta property="og:url" content="' . e($m['canonical']) . '">' : '')
        . '<meta property="og:image" content="' . e((string) $m['image']) . '">'
        . '<meta property="og:image:alt" content="' . e($m['image_alt']) . '">'
        . ($m['image_width'] ? '<meta property="og:image:width" content="' . (int) $m['image_width'] . '"><meta property="og:image:height" content="' . (int) $m['image_height'] . '">' : '')
        . '<meta name="twitter:card" content="summary_large_image">'
        . ($s['twitter'] ? '<meta name="twitter:site" content="' . e($s['twitter']) . '">' : '')
        . '<meta name="twitter:title" content="' . e($title) . '">'
        . '<meta name="twitter:description" content="' . e($desc) . '">'
        . '<meta name="twitter:image" content="' . e((string) $m['image']) . '">'
        . '<meta name="twitter:image:alt" content="' . e($m['image_alt']) . '">';
    if ($m['type'] === 'article' && $m['lastmod']) {
        $t .= '<meta property="article:modified_time" content="' . e(date(DATE_ATOM, strtotime((string) $m['lastmod']))) . '">';
    }
    if ($m['jsonld'] && $m['status'] === 200) {
        $t .= '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@graph' => array_values($m['jsonld'])],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
    }
    return $t;
}

/** <noscript> block for the page body. */
function seo_noscript(array $m): string
{
    try {
        $html = $m['body'] ? ($m['body'])() : seo_body_wrap('<h1>' . e((string) ($m['h1'] ?: preg_replace('/\s*\|.*$/', '', $m['title']))) . '</h1><p>' . e($m['description']) . '</p>', $m['crumbs']);
    } catch (Throwable $e) {
        error_log('seo_noscript: ' . $e->getMessage());
        $html = '<p>' . e(seo_site()['name']) . ' needs JavaScript enabled. Call ' . e(seo_site()['phone']) . '.</p>';
    }
    return '<noscript><style>.lx-ns{max-width:72rem;margin:0 auto;padding:16px;font:16px/1.6 system-ui,sans-serif;color:#0f172a}.lx-ns h1{font-size:28px;font-weight:800;margin:12px 0}'
        . '.lx-ns h2{font-size:21px;font-weight:700;margin:20px 0 8px}.lx-ns h3{font-size:17px;font-weight:700;margin:14px 0 4px}.lx-ns p{margin:8px 0}.lx-ns ul,.lx-ns ol{padding-left:22px;margin:8px 0;list-style:disc}'
        . '.lx-ns nav ol{list-style:none;padding:0;display:flex;flex-wrap:wrap;gap:6px}.lx-ns nav ol li+li:before{content:"› "}.lx-ns a{color:#0B63F6;text-decoration:underline}.lx-ns footer{margin-top:28px;border-top:1px solid #e2e8f0;padding-top:12px;font-size:14px}</style>'
        . $html . '</noscript>';
}

// ------------------------------------------------------------------
// XML sitemaps (/sitemap.xml = index of the parts below)
// ------------------------------------------------------------------

const SEO_SITEMAP_PARTS = ['pages' => 'Pages', 'categories' => 'Course categories', 'courses' => 'Courses', 'blog' => 'Blog posts', 'resources' => 'Resources, tests and job alerts'];

/** Candidate paths of one sitemap part (before the indexability check). */
function seo_sitemap_candidates(string $part): array
{
    $paths = [];
    $col = fn(string $sql) => array_column(db_all($sql), 'p');
    try {
        switch ($part) {
            case 'pages':
                $paths = SEO_STATIC_PATHS;
                array_push($paths, ...$col("SELECT CONCAT('/p/', slug) AS p FROM pages WHERE status = 'published'"),
                    ...$col("SELECT CONCAT('/careers/', slug) AS p FROM job_openings WHERE status = 'open' AND is_demo = 0 AND (closes_at IS NULL OR closes_at >= CURDATE())"));
                try {
                    array_push($paths, ...$col('SELECT CONCAT(\'/gallery/\', a.slug) AS p FROM gallery_albums a WHERE a.is_active = 1 AND EXISTS (SELECT 1 FROM gallery_items i WHERE i.album_id = a.id AND i.is_active = 1) ORDER BY a.sort_order, a.id DESC'));
                } catch (PDOException $e) {
                    // gallery tables not installed
                }
                break;
            case 'categories':
                $paths = array_map(fn($c) => seo_category_path($c['slug']), array_filter(fetch_course_categories(), fn($c) => $c['course_count'] > 0));
                break;
            case 'courses':
                $paths = $col("SELECT CONCAT('/courses/', c.slug) AS p FROM courses c JOIN course_categories cat ON cat.id = c.category_id AND cat.is_active = 1 WHERE c.status = 'published' AND c.is_demo = 0 ORDER BY cat.sort_order, c.sort_order, c.id");
                break;
            case 'blog':
                $paths = $col("SELECT CONCAT('/blog/', slug) AS p FROM blog_posts WHERE status IN ('published','scheduled') AND published_at <= NOW() AND is_demo = 0 ORDER BY published_at DESC");
                break;
            case 'resources':
                $paths = [...$col("SELECT CONCAT('/resources/', r.slug) AS p FROM learning_resources r JOIN resource_categories rc ON rc.id = r.category_id AND rc.is_active = 1
                                   WHERE r.status = 'published' AND r.is_demo = 0 AND (r.published_at IS NULL OR r.published_at <= NOW())"),
                    ...$col("SELECT CONCAT('/mock-tests/', slug) AS p FROM exams WHERE status = 'published' AND is_demo = 0"),
                    ...$col("SELECT CONCAT('/jobs/', slug) AS p FROM job_alerts WHERE status = 'published' AND is_demo = 0 AND (published_at IS NULL OR published_at <= NOW())")];
                break;
        }
    } catch (PDOException $e) {
        error_log('seo_sitemap_candidates(' . $part . '): ' . $e->getMessage());
    }
    return array_values(array_unique($paths));
}

/**
 * Indexable URLs of one sitemap part: [loc, lastmod|null, image|null]. Only URLs that answer 200, are
 * index,follow and are their own canonical are listed (so noindex, demo and empty pages never appear).
 */
function seo_sitemap_urls(string $part): array
{
    $out = [];
    foreach (seo_sitemap_candidates($part) as $path) {
        $m = seo_resolve($path);
        if ($m['status'] !== 200 || $m['robots'] !== 'index,follow' || $m['canonical'] !== seo_url($path)) {
            continue;
        }
        $image = in_array($m['kind'], ['course', 'post'], true) && !str_contains((string) $m['image'], '/assets/icons/') ? $m['image'] : null;
        $out[] = [$m['canonical'], $m['lastmod'], $image];
    }
    return $out;
}

/** Records a 404 hit for admin → SEO → 404 Monitor (table from migration 021; silently skipped before). */
function seo_log_404(string $path): void
{
    if (strlen($path) > 255 || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }
    $ref = mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 255) ?: null;
    try {
        db_query('INSERT INTO seo_404_log (path, hits, last_referrer, first_seen, last_seen) VALUES (?, 1, ?, NOW(), NOW())
                  ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = NOW(), last_referrer = COALESCE(VALUES(last_referrer), last_referrer)', [$path, $ref]);
    } catch (PDOException $e) {
        // migration 021 not applied yet
    }
}
