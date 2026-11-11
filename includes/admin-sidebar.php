<?php
/**
 * Admin navigation: 17 modules, one compact sidebar link each. Every module's screens are shown
 * as the horizontal tab bar under the page title (render_module_tabs()). Every item opens a real
 * screen, a real filtered view or a real client-side panel (page.php#panel, see [data-tabs] in assets/admin.js).
 * Items are shown only when the staff member holds the permission ('' = any staff member).
 *
 * Module: [key, label, icon, items, options]
 *   options: 'href' => '…'  target of the sidebar link (default: the first permitted item)
 * Item:   [label, href, permission, icon, options]
 *   options: 'alias' => true  a shortcut to a screen that belongs to another module: listed in this
 *            module's tab bar, but it never claims the page (the owning module stays active there).
 *            'covers' => ['page.php?tab=x', …]  older links this item stands in for: on those pages the item
 *            is highlighted, and a page tab strip linking there counts as already listed in the tab bar.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

function admin_menu(): array
{
    $me = (int) (current_user()['id'] ?? 0);
    $alias = ['alias' => true];
    // A screen that needs a second permission too (revenue figures: payments or orders access; lead reports:
    // enquiries access). The item carries $perm when the staff member holds one of $also, otherwise the first
    // of $also - so every reader of this menu (sidebar, tab bar, search) hides it with a plain can() check.
    // reports.php enforces the same rule on the server.
    $both = fn(string $perm, string ...$also): string => array_filter($also, 'can') ? $perm : $also[0];
    return [
        ['dashboard', 'Dashboard', 'home', [
            ['Overview', 'dashboard.php', 'dashboard.view', 'home'],
            ['Student Growth', 'reports.php?tab=growth', 'reports.view', 'users'],
            ['Course Performance', 'reports.php?tab=courses', 'reports.view', 'book'],
            ['Enrolment Reports', 'reports.php?tab=enrolments', 'reports.view', 'cap'],
            ['Revenue Reports', 'reports.php?tab=revenue', $both('reports.view', 'payments.view', 'orders.view'), 'rupee'],
            ['Payment Reports', 'payments.php?tab=report', 'payments.view', 'receipt', $alias],
            ['Mock Test Analytics', 'reports.php?tab=tests', 'reports.view', 'clipboard'],
            ['Student Progress', 'reports.php?tab=progress', 'reports.view', 'trending'],
            ['Live Class Attendance', 'reports.php?tab=attendance', 'reports.view', 'live'],
            ['Resource Downloads', 'reports.php?tab=downloads', 'reports.view', 'download'],
            ['Website Traffic', 'traffic.php', 'reports.view', 'activity'],
            ['Conversion Reports', 'reports.php?tab=conversion', 'reports.view', 'target'],
            ['Enquiry Reports', 'reports.php?tab=enquiries', $both('reports.view', 'enquiries.view'), 'inbox'],
            ['Staff Activity', 'reports.php?tab=staff', 'reports.view', 'shield'],
            ['Export Reports', 'reports.php?tab=export', 'reports.view', 'upload'],
        ]],
        // Everything about a course lives here; each course opens as a workspace with the tabs
        // Details · Curriculum · Study material · Students · Preview (includes/course-workspace.php).
        ['courses', 'Courses', 'book', [
            ['All Courses', 'courses.php', 'courses.view', 'book'],
            ['Add Course', 'courses.php?action=new', 'courses.manage', 'plus'],
            ['Drafts', 'courses.php?status=draft', 'courses.view', 'edit'],
            ['Curriculum & Lessons', 'lessons.php', 'lessons.manage', 'list'],
            ['Study Material', 'resources.php?scope=course', 'resources.manage', 'file'],
            ['Categories', 'categories.php', 'courses.manage', 'tag'],
            ['Subcategories', 'categories.php?level=sub', 'courses.manage', 'layers'],
            ['Faculty', 'instructors.php', 'instructors.manage', 'cap'],
            ['Course Reviews', 'reviews.php', 'courses.manage', 'star'],
            ['Pending Reviews', 'reviews.php?status=pending', 'courses.manage', 'clock'],
            ['Access Rules', 'enrolments.php?view=access', 'enrolments.manage', 'lock'],
        ]],
        // Downloads for everyone (current affairs, previous papers…); course-only files are Study Material above.
        ['materials', 'Free Resources', 'layers', [
            ['Free Resources', 'resources.php?tab=resources', 'resources.manage', 'file'],
            ['Resource Categories', 'resources.php?tab=categories', 'resources.manage', 'folder'],
        ]],
        ['live', 'Live Classes', 'live', [
            ['Upcoming Classes', 'live-classes.php', 'live.manage', 'live'],
            ['Past Classes', 'live-classes.php?when=past', 'live.manage', 'history'],
            ['Class Calendar', 'calendar.php', 'live.manage', 'calendar'],
            ['Attendance Report', 'reports.php?tab=attendance', 'reports.view', 'chart', $alias],
        ]],
        ['tests', 'Tests & Quizzes', 'clipboard', [
            ['Mock Tests', 'mock-tests.php?tab=exam', 'exams.manage', 'clipboard'],
            ['Practice Quizzes', 'mock-tests.php?tab=practice', 'exams.manage', 'quiz'],
            ['Schedule', 'mock-tests.php?tab=schedule', 'exams.manage', 'calendar'],
            ['Daily Quiz', 'daily-quiz.php', 'exams.manage', 'sparkles'],
            ['Result Publication', 'mock-tests.php?tab=results', 'exams.manage', 'send'],
            ['Negative Marking', 'mock-tests.php?tab=marking', 'exams.manage', 'percent'],
            ['Instructions', 'mock-tests.php?tab=instructions', 'exams.manage', 'info'],
            ['Question Bank', 'questions.php', 'exams.manage', 'database'],
            ['Question Import', 'questions.php?import=1', 'exams.manage', 'upload'],
            ['Question Topics', 'topics.php', 'exams.manage', 'tag'],
            ['Attempts & Answer Sheets', 'attempts.php', 'exams.manage', 'check-circle'],
            ['Leaderboards', 'leaderboard.php', 'exams.manage', 'trophy'],
        ]],
        ['doubts', 'Doubts', 'chat', [
            ['Waiting for an answer', 'doubts.php', 'doubts.manage', 'chat'],
            ['Answered', 'doubts.php?status=answered', 'doubts.manage', 'check-circle'],
            ['Resolved', 'doubts.php?status=closed', 'doubts.manage', 'check'],
            ['All Doubts', 'doubts.php?status=all', 'doubts.manage', 'list'],
        ]],
        ['students', 'Students', 'users', [
            ['All Students', 'users.php', 'users.view', 'users'],
            ['Add Student', 'users.php?action=new', 'users.manage', 'plus'],
            ['Blocked Students', 'users.php?status=inactive', 'users.view', 'lock'],
            ['Deletion Requests', 'users.php?deletion=1', 'users.view', 'trash'],
            ['Enrolments', 'enrolments.php', 'enrolments.manage', 'cap'],
            ['Certificates', 'certificates.php', 'enrolments.manage', 'award'],
            ['Student Documents', 'documents.php', 'users.view', 'file'],
            ['Document Types', 'lists.php?list=document_type', 'users.manage', 'list'],
        ]],
        ['payments', 'Payments', 'rupee', [
            ['Orders', 'orders.php', 'orders.view', 'cart'],
            ['Invoices', 'invoices.php', 'orders.view', 'invoice'],
            ['Transactions', 'payments.php?tab=transactions', 'payments.view', 'receipt'],
            ['Failed Payments', 'payments.php?tab=transactions&status=failed', 'payments.view', 'alert'],
            ['Refunds', 'payments.php?tab=refunds', 'payments.view', 'refresh'],
            ['Payment Report', 'payments.php?tab=report', 'payments.view', 'chart'],
            ['Webhook Events', 'payments.php?tab=webhooks', 'payments.view', 'zap'],
            ['Gateway Setup', 'payments.php?tab=setup', 'payments.view', 'plug'],
            ['Coupons', 'coupons.php', 'coupons.manage', 'tag'],
            ['Referral Program', 'referrals.php', 'coupons.manage', 'gift'],
            ['Company & GST', 'settings.php#company', 'settings.manage', 'building', $alias],
        ]],
        ['enquiries', 'Leads', 'inbox', [
            ['All Enquiries', 'enquiries.php', 'enquiries.view', 'inbox'],
            ['New Leads', 'enquiries.php?status=new', 'enquiries.view', 'sparkles'],
            ['Follow-ups', 'enquiries.php?status=follow_up', 'enquiries.view', 'clock'],
            ['My Leads', 'enquiries.php?assigned=' . $me, 'enquiries.view', 'user'],
            ['Spam', 'enquiries.php?status=spam', 'enquiries.view', 'alert'],
            ['Chatbot Conversations', 'chatbot.php', 'enquiries.view', 'chat'],
            ['Chatbot Settings', 'chatbot.php?tab=settings', 'settings.manage', 'sliders'],
            ['Support Tickets', 'support.php', 'support.manage', 'headset'],
            ['Canned Responses', 'canned.php', 'support.manage', 'copy'],
            ['Ticket Categories', 'lists.php?list=ticket_category', 'support.manage', 'list'],
            ['Forms & Spam Protection', 'settings.php#features', 'settings.manage', 'shield', $alias],
        ]],
        ['newsletter', 'Newsletter', 'newsletter', [
            ['Subscribers', 'newsletter.php', 'enquiries.view', 'newsletter'],
            ['Unsubscribed', 'newsletter.php?status=unsubscribed', 'enquiries.view', 'close'],
            ['Signup Box (Homepage)', 'cms.php?section=newsletter', 'cms.manage', 'layout'],
            ['Welcome Email', 'settings.php#notifications', 'settings.manage', 'mail', $alias],
        ]],
        ['email', 'Emails', 'mail', [
            ['Email Templates', 'templates.php?tab=templates', 'notifications.send', 'mail'],
            ['Default Preferences', 'templates.php?tab=defaults', 'notifications.send', 'sliders'],
            // whatsapp.php replaces the older WhatsApp tab of templates.php (Meta template mapping).
            ['WhatsApp', 'whatsapp.php', 'notifications.send', 'whatsapp', ['covers' => ['templates.php?tab=whatsapp']]],
            ['Delivery Logs', 'email-logs.php', 'notifications.send', 'log'],
            ['Failed Emails', 'email-logs.php?status=failed', 'notifications.send', 'alert'],
            ['Send Notification', 'notifications.php', 'notifications.send', 'bell'],
            ['Email & SMTP', 'settings.php#email', 'settings.manage', 'server', $alias],
            ['Automatic Emails', 'settings.php#notifications', 'settings.manage', 'zap', $alias],
        ]],
        ['media', 'Media', 'video', [
            ['YouTube Videos', 'videos.php?tab=youtube', 'cms.manage', 'youtube'],
            ['Instagram Reels', 'videos.php?tab=instagram', 'cms.manage', 'instagram'],
            ['Section Headings', 'videos.php?tab=headings', 'cms.manage', 'type'],
            ['Media Library', 'media.php', 'cms.manage', 'image'],
        ]],
        ['jobs', 'Jobs', 'briefcase', [
            ['Govt Job Alerts', 'jobs.php?tab=alerts', 'cms.manage', 'megaphone'],
            ['Careers Openings', 'jobs.php?tab=openings', 'cms.manage', 'briefcase'],
            ['Applications', 'jobs.php?tab=applications', 'cms.manage', 'inbox'],
            ['Shortlisted', 'jobs.php?tab=applications&status=shortlisted', 'cms.manage', 'star'],
            ['Interviews', 'jobs.php?tab=applications&status=interview', 'cms.manage', 'calendar'],
            ['Faculty Subjects', 'lists.php?list=faculty_subject', 'cms.manage', 'cap'],
            ['Departments', 'lists.php?list=department', 'cms.manage', 'building'],
            ['Employment Types', 'lists.php?list=employment_type', 'cms.manage', 'list'],
            ['Careers Page', 'cms.php?section=page_careers', 'cms.manage', 'file'],
        ]],
        ['cms', 'Website', 'globe', [
            ['Homepage Sections', 'cms.php?tab=home', 'cms.manage', 'home'],
            ['Hero Banner', 'cms.php?section=hero', 'cms.manage', 'sparkles'],
            ['Promo Banners', 'banners.php', 'cms.manage', 'banner'],
            ['Highlights Ticker', 'highlights.php', 'cms.manage', 'highlight'],
            ['Popups', 'popups.php', 'cms.manage', 'expand'],
            ['Header, Menus & Footer', 'cms.php?tab=layout', 'cms.manage', 'layout'],
            ['Pages & Policies', 'cms.php?tab=pages', 'cms.manage', 'file'],
            ['Page Builder', 'pages.php', 'cms.manage', 'layers'],
            ['Reusable Sections', 'pages.php?tab=reusable', 'cms.manage', 'copy'],
            ['Blog Posts', 'blog.php', 'blog.manage', 'edit'],
            ['Blog Categories', 'blog.php?tab=categories', 'blog.manage', 'tag'],
            ['Testimonials', 'testimonials.php', 'testimonials.manage', 'star'],
            ['Gallery', 'gallery.php', 'cms.manage', 'image'],
            ['FAQs', 'cms.php?tab=faqs', 'cms.manage', 'help'],
            ['Revision History', 'cms.php?tab=revisions', 'cms.manage', 'history'],
            ['Website Preview', 'preview:', 'cms.manage', 'external'],
        ]],
        ['navigation', 'Menus & Social', 'navigation', [
            ['Main Menu', 'cms.php?section=nav_main', 'cms.manage', 'menu'],
            ['Footer Menu', 'cms.php?section=nav_footer', 'cms.manage', 'list'],
            ['Header', 'cms.php?section=header', 'cms.manage', 'layout'],
            ['Footer', 'cms.php?section=footer', 'cms.manage', 'layout'],
            ['Announcement Bar', 'cms.php?section=announcement', 'cms.manage', 'megaphone'],
            ['Social Media Links', 'settings.php#social', 'settings.manage', 'share', $alias],
            ['Contact Details', 'settings.php#academy', 'settings.manage', 'phone', $alias],
        ]],
        ['seo', 'SEO & Integrations', 'target', [
            ['Integration Dashboard', 'integrations.php?tab=overview', 'settings.manage', 'plug'],
            ['Keys & Credentials', 'keys.php', 'settings.manage', 'lock'],
            ['Payment Gateways', 'integrations.php?tab=payments', 'settings.manage', 'rupee'],
            ['SMTP / Email', 'integrations.php?tab=email', 'settings.manage', 'mail'],
            ['Analytics & Pixels', 'integrations.php?tab=analytics', 'settings.manage', 'chart'],
            ['Search Console', 'indexing.php', 'seo.manage', 'search'],
            // The WhatsApp screen lives under Emails; it replaces the old WhatsApp panel of integrations.php.
            ['WhatsApp', 'whatsapp.php', 'notifications.send', 'whatsapp', ['alias' => true, 'covers' => ['integrations.php?tab=whatsapp']]],
            ['Google Meet & Zoom', 'integrations.php?tab=meetings', 'settings.manage', 'video'],
            ['YouTube', 'integrations.php?tab=youtube', 'settings.manage', 'youtube'],
            ['Cloud Storage', 'storage.php', 'settings.manage', 'server'],
            ['reCAPTCHA', 'integrations.php?tab=recaptcha', 'settings.manage', 'shield'],
            ['Webhooks', 'integrations.php?tab=webhooks', 'settings.manage', 'zap'],
            ['API Keys', 'api-keys.php', 'settings.manage', 'key'],
            ['Integration Logs', 'integrations.php?tab=logs', 'settings.manage', 'log'],
            ['SEO', 'seo.php', 'seo.manage', 'target'],
            ['Broken Links', 'broken-links.php', 'seo.manage', 'link'],
        ]],
        ['security', 'Security', 'shield', [
            ['Security Dashboard', 'security.php?tab=overview', 'logs.view', 'shield'],
            ['Login History', 'security.php?tab=logins', 'logs.view', 'history'],
            ['Failed Logins', 'security.php?tab=failed', 'logs.view', 'alert'],
            ['Audit Trail', 'logs.php', 'logs.view', 'log'],
            ['Sessions & Devices', 'security.php?tab=sessions', 'logs.view', 'monitor'],
            ['Password Policy', 'security.php?tab=password', 'logs.view', 'key'],
            ['Two-Factor Auth', 'two-factor.php', '', 'lock'], // '' = any staff member (a string: search.php calls can())
            ['IP Allowlist & Hours', 'access.php', 'roles.manage', 'globe'],
            ['Rate Limiting', 'security.php?tab=limits', 'logs.view', 'gauge'],
            ['Protections', 'security.php?tab=protections', 'logs.view', 'check-circle'],
            ['API Access Logs', 'api-keys.php?tab=logs', 'logs.view', 'code'],
            ['Webhook Security', 'payments.php?tab=setup', 'payments.view', 'zap', $alias],
            ['Backup Status', 'backup.php', 'settings.manage', 'database', $alias],
            ['Staff Accounts', 'users.php?type=staff', 'roles.manage', 'users'],
            ['Roles', 'roles.php?tab=roles', 'roles.manage', 'key'],
            ['Permission Matrix', 'roles.php?tab=matrix', 'roles.manage', 'grid'],
        ]],
        ['settings', 'Settings', 'settings', [
            ['Go-Live Checklist', 'launch.php', 'settings.manage', 'rocket'],
            ['General', 'settings.php#general', 'settings.manage', 'settings'],
            ['Academy Information', 'settings.php#academy', 'settings.manage', 'home'],
            ['Company & Invoicing', 'settings.php#company', 'settings.manage', 'building'],
            ['Keys & Credentials', 'keys.php', 'settings.manage', 'lock', $alias],
            ['Branding', 'appearance.php#branding', 'settings.manage', 'palette'],
            ['Logo & Favicon', 'appearance.php#logo', 'settings.manage', 'image'],
            ['Theme', 'appearance.php#theme', 'settings.manage', 'sparkles'],
            ['Typography', 'appearance.php#typography', 'settings.manage', 'type'],
            ['Layout', 'appearance.php#layout', 'settings.manage', 'layout'],
            ['Date, Time & Timezone', 'settings.php#datetime', 'settings.manage', 'clock'],
            ['Currency', 'settings.php#currency', 'settings.manage', 'rupee'],
            ['Language', 'settings.php#language', 'settings.manage', 'languages'],
            ['Email & SMTP', 'settings.php#email', 'settings.manage', 'mail'],
            ['Automatic Emails', 'settings.php#notifications', 'settings.manage', 'bell'],
            ['Payments', 'settings.php#payments', 'settings.manage', 'cart'],
            ['File Upload Limits', 'settings.php#uploads', 'settings.manage', 'upload'],
            ['Maintenance Mode', 'settings.php#maintenance', 'settings.manage', 'wrench'],
            ['Cache', 'settings.php#cache', 'settings.manage', 'zap'],
            ['Social Media', 'settings.php#social', 'settings.manage', 'share'],
            ['SEO & Robots', 'settings.php#seo', 'settings.manage', 'search'],
            ['Forms & Features', 'settings.php#features', 'settings.manage', 'clipboard'],
            ['Backup & Restore', 'backup.php', 'settings.manage', 'database'],
            ['Database Updates', 'updates.php', 'settings.manage', 'refresh'],
            ['Demo Content Cleanup', 'demo-content.php', 'settings.manage', 'eraser'],
            ['Cloud Storage', 'storage.php', 'settings.manage', 'server', $alias],
        ], ['href' => 'settings.php#general']],
    ];
}

/** Old admin_start() 'active' keys => module key (used only when no menu item matches the URL). */
const ADMIN_ACTIVE_ALIASES = [
    'reports' => 'dashboard', 'categories' => 'courses', 'instructors' => 'courses', 'lessons' => 'courses', 'resources' => 'materials',
    'live-classes' => 'live', 'users' => 'students', 'certificates' => 'students', 'orders' => 'payments',
    'coupons' => 'payments', 'support' => 'enquiries', 'notifications' => 'email', 'blog' => 'cms', 'testimonials' => 'cms',
    'appearance' => 'settings', 'integrations' => 'seo', 'logs' => 'security', 'roles' => 'security', 'rbac' => 'security',
];

/** Sidebar dividers (tiny labels): module key => label shown above it. */
const ADMIN_MENU_SECTIONS = ['courses' => 'Learning', 'payments' => 'Business', 'media' => 'Website', 'security' => 'System'];

/** Tile colour per module (apps launcher). */
const ADMIN_MODULE_TONES = [
    'dashboard' => 't-blue', 'courses' => 't-purple', 'materials' => 't-teal', 'live' => 't-red', 'tests' => 't-indigo',
    'students' => 't-green', 'payments' => 't-orange', 'enquiries' => 't-pink', 'newsletter' => 't-amber', 'email' => 't-blue',
    'media' => 't-red', 'jobs' => 't-amber', 'cms' => 't-teal', 'navigation' => 't-indigo', 'seo' => 't-green',
    'security' => 't-navy', 'settings' => 't-navy',
];

/** Resource category id by slug (kept for older deep links). */
function cat_id(string $slug): string
{
    static $ids = null;
    $ids ??= array_column(db_all('SELECT id, slug FROM resource_categories'), 'id', 'slug');
    return (string) ($ids[$slug] ?? '');
}

function menu_href(string $href): string
{
    if ($href === 'preview:') {
        return app_url('');
    }
    return admin_url($href);
}

/** Specificity of a menu href for the current request (-1 = no match). */
function menu_match(string $href): int
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $parts = parse_url(strtok($href, '#'));
    if (($parts['path'] ?? '') !== $script) {
        return -1;
    }
    parse_str($parts['query'] ?? '', $q);
    foreach ($q as $k => $v) {
        if ((string) ($_GET[$k] ?? '') !== (string) $v) {
            return -1;
        }
    }
    return count($q) * 2 + (str_contains($href, '#') ? 0 : 1);
}

/** Best match of a menu item for the current request: its own href or any link it 'covers' (-1 = no match). */
function menu_item_match(array $item): int
{
    $m = menu_match($item[1]);
    foreach ((array) ($item[4]['covers'] ?? []) as $href) {
        $m = max($m, menu_match((string) $href));
    }
    return $m;
}

/**
 * Comparable form of an admin link ('page.php?a=1&b=2', query sorted; '?tab=x' = this page), or null for
 * in-page panel links (#…) and the website preview.
 */
function menu_href_key(string $href): ?string
{
    if ($href === '' || $href === 'preview:' || str_contains($href, '#')) {
        return null;
    }
    $parts = parse_url($href);
    if ($parts === false) {
        return null;
    }
    $path = basename((string) ($parts['path'] ?? '')) ?: basename($_SERVER['SCRIPT_NAME'] ?? '');
    parse_str($parts['query'] ?? '', $q);
    ksort($q);
    return $path . ($q ? '?' . http_build_query($q) : '');
}

/**
 * Links in this page's module tab bar, as [menu href (incl. 'covers' links) => href of the bar link], or []
 * when the page shows no tab bar. admin_tabs() uses it to hide a page strip that would only repeat the bar.
 */
function module_tab_hrefs(): array
{
    $cur = admin_menu_current();
    if (!$cur['module'] || $cur['index'] === null) {
        return [];
    }
    $visible = array_filter($cur['module'][3], fn($c) => menu_can($c[2] ?? null));
    if (count($visible) < 2) {
        return [];
    }
    $out = [];
    foreach ($visible as $c) {
        $out[$c[1]] ??= $c[1];
        foreach ((array) ($c[4]['covers'] ?? []) as $h) {
            $out[(string) $h] ??= $c[1];
        }
    }
    return $out;
}

/** Is a menu item visible to the current staff member? (null / '' permission = any staff) */
function menu_can(?string $perm): bool
{
    return $perm === null || $perm === '' || can($perm);
}

/** A shortcut to another module's screen (see the file header). */
function menu_is_alias(array $item): bool
{
    return !empty($item[4]['alias']);
}

/**
 * The module and item that best match the current URL (cached per request).
 * Alias items only win when nothing else matches.
 * @return array{key:?string, index:?int, module:?array}
 */
/**
 * Make this page belong to a module whatever its URL (e.g. a course's Students tab, enrolments.php?course=…,
 * stays under Courses). $href = the module item to highlight. Call before admin_start().
 */
function admin_force_module(string $module, string $href): void
{
    $GLOBALS['__admin_module_force'] = [$module, $href];
}

function admin_menu_current(string $active = ''): array
{
    $force = $GLOBALS['__admin_module_force'] ?? null;
    if (is_array($force)) {
        foreach (admin_menu() as $m) {
            if ($m[0] === $force[0]) {
                $index = array_search($force[1], array_column($m[3], 1), true);
                return ['key' => $m[0], 'index' => $index === false ? null : (int) $index, 'module' => $m];
            }
        }
    }
    // Cached per 'active' key: a page may ask (e.g. admin_tabs() before admin_start()) without knowing it.
    static $cache = [];
    if (isset($cache[$active])) {
        return $cache[$active];
    }
    $menu = admin_menu();
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $best = [-1, null, null, -1];
    foreach ($menu as [$key, , , $items]) {
        // Tie-break: the module that owns more (non-alias) links to this script is its "home".
        $own = count(array_filter($items, fn($c) => !menu_is_alias($c) && parse_url(strtok($c[1], '#'), PHP_URL_PATH) === $script));
        foreach ($items as $i => $c) {
            if (!menu_can($c[2] ?? null)) {
                continue;
            }
            $m = menu_item_match($c);
            if ($m < 0) {
                continue;
            }
            $score = $m * 2 + (menu_is_alias($c) ? 0 : 1);
            if ($score > $best[0] || ($score === $best[0] && $own > $best[3])) {
                $best = [$score, $key, $i, $own];
            }
        }
    }
    // No item matched the query string (e.g. cms.php without ?tab): use the first item on this script in the
    // module that owns most links to it, then the page's 'active' key.
    if ($best[1] === null) {
        $most = 0;
        foreach ($menu as [$key, , , $items]) {
            $mine = array_filter($items, fn($c) => !menu_is_alias($c) && menu_can($c[2] ?? null) && parse_url(strtok($c[1], '#'), PHP_URL_PATH) === $script);
            if (count($mine) > $most) {
                $most = count($mine);
                $best = [0, $key, array_key_first($mine), $most];
            }
        }
    }
    if ($best[1] === null && $active !== '') {
        $want = ADMIN_ACTIVE_ALIASES[$active] ?? $active;
        foreach ($menu as $m) {
            if ($m[0] === $want) {
                $best = [0, $want, null, 0];
            }
        }
    }
    $module = null;
    foreach ($menu as $m) {
        if ($m[0] === $best[1]) {
            $module = $m;
        }
    }
    return $cache[$active] = ['key' => $best[1], 'index' => $best[2], 'module' => $module];
}

/**
 * Modules this staff member can open (sidebar + apps launcher):
 * ['key', 'label', 'icon', 'href' (absolute), 'tone', 'active', 'count' (items waiting, same figures as the bell menu)].
 */
function admin_module_links(string $active = ''): array
{
    static $out = null;
    if ($out !== null) {
        return $out;
    }
    $cur = admin_menu_current($active);
    $owner = ['enquiries.php' => 'enquiries', 'support.php' => 'enquiries', 'doubts.php' => 'doubts', 'testimonials.php' => 'cms', 'reviews.php' => 'courses',
        'jobs.php' => 'jobs', 'payments.php' => 'payments', 'email-logs.php' => 'email', 'updates.php' => 'settings'];
    $counts = [];
    if (function_exists('admin_attention_items')) {
        foreach (admin_attention_items() as [, $href, , $n]) {
            $k = $owner[strtok($href, '?')] ?? null;
            if ($k) {
                $counts[$k] = ($counts[$k] ?? 0) + (int) $n;
            }
        }
    }
    $out = [];
    foreach (admin_menu() as $m) {
        [$key, $label, $ico, $items] = $m;
        $opts = $m[4] ?? [];
        $visible = array_filter($items, fn($c) => menu_can($c[2] ?? null) && !menu_is_alias($c));
        if (!$visible) {
            continue;
        }
        $href = reset($visible)[1];
        if (!empty($opts['href']) && in_array($opts['href'], array_column($visible, 1), true)) {
            $href = $opts['href'];
        }
        $out[] = ['key' => $key, 'label' => $label, 'icon' => $ico, 'href' => menu_href($href), 'tone' => ADMIN_MODULE_TONES[$key] ?? 't-blue',
            'active' => $cur['key'] === $key, 'count' => $counts[$key] ?? 0];
    }
    return $out;
}

function render_admin_sidebar(string $active): void
{
    echo '<nav class="sb-nav" aria-label="Admin">';
    foreach (admin_module_links($active) as $m) {
        if (isset(ADMIN_MENU_SECTIONS[$m['key']])) {
            echo '<div class="nav-label"><span>' . e(ADMIN_MENU_SECTIONS[$m['key']]) . '</span></div>';
        }
        // Owner's choice: one clean link per module; its screens are the on-page tab bar (render_module_tabs).
        $count = $m['count'] > 0 ? '<span class="nl-count" title="' . e(plural_n($m['count'], 'item') . ' waiting') . '">' . ($m['count'] > 99 ? '99+' : $m['count']) . '<span class="sr-only"> waiting</span></span>' : '';
        echo '<a class="nav-link' . ($m['active'] ? ' active' : '') . '" href="' . e($m['href']) . '"' . ($m['active'] ? ' aria-current="page"' : '')
            . ' data-tip-side="' . e($m['label']) . '">' . icon($m['icon']) . '<span class="nl-text">' . e($m['label']) . '</span>' . $count . '</a>';
    }
    echo '</nav>';
}

/**
 * Horizontal pill tab bar listing the current module's screens (permission-filtered). It wraps onto
 * extra rows (owner's choice: every tab visible). Same-page links with #panel open client-side panels;
 * admin.js keeps the highlight in sync.
 */
function render_module_tabs(string $active = '', bool $highlight = true): string
{
    $cur = admin_menu_current($active);
    if (!$cur['module'] || $cur['index'] === null) {
        return '';
    }
    [, $label, , $items] = $cur['module'];
    $visible = array_filter($items, fn($c) => menu_can($c[2] ?? null));
    if (count($visible) < 2) {
        return '';
    }
    $h = '<nav class="modtabs" aria-label="' . e($label) . ' screens" data-modtabs><div class="tabs-track">';
    foreach ($visible as $i => $c) {
        $on = $highlight && $cur['index'] === $i;
        $ext = $c[1] === 'preview:';
        $hash = str_contains($c[1], '#') ? substr($c[1], strpos($c[1], '#') + 1) : '';
        $h .= '<a href="' . e(menu_href($c[1])) . '"' . ($on ? ' class="on" aria-current="page"' : '') . ($hash !== '' ? ' data-hash="' . e($hash) . '"' : '')
            . ($ext ? ' target="_blank" rel="noopener"' : '') . '>' . icon($c[3] ?? 'right') . '<span>' . e($c[0]) . '</span>' . ($ext ? icon('external', 'i ext') : '') . '</a>';
    }
    return $h . '</div></nav>';
}

/** Breadcrumb trail for the current page: Dashboard / Module / … */
function admin_breadcrumb_module(string $active = ''): ?array
{
    $cur = admin_menu_current($active);
    if (!$cur['module'] || ($cur['key'] === 'dashboard' && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'dashboard.php')) {
        return null;
    }
    foreach (admin_module_links($active) as $m) {
        if ($m['key'] === $cur['key']) {
            return [$m['label'], $m['href']];
        }
    }
    return null;
}
