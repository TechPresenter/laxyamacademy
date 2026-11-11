<?php
// SEO: per-page meta tags, Open Graph, robots directives and 301/302 redirects.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';

$tabs = ['audit' => 'SEO Audit', 'global' => 'Global SEO', 'pages' => 'Page SEO', 'courses' => 'Course SEO', 'blog' => 'Blog SEO', 'redirects' => 'Redirects', 'tools' => 'Sitemap & Robots'];
$tab = current_tab($tabs);
$note = '<p class="muted" style="font-size:12px;margin:0 0 14px">These settings help search engines understand the site; they do not guarantee rankings or indexing.</p>';

if ($tab === 'global') {
    $me = require_staff_page();
    require_permission('seo.manage');
    if (admin_post()) {
        $title = trim((string) $_POST['default_seo_title']);
        $desc = trim((string) $_POST['default_seo_description']);
        $site = trim((string) $_POST['site_name']);
        $img = null;
        try {
            if (uploaded_file_ok($_FILES['default_og_image'] ?? null)) {
                $img = store_image_upload($_FILES['default_og_image'], 'cms', 1200, 630);
            }
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect_to('seo.php?tab=global');
        }
        if ($title === '' || mb_strlen($title) > 160 || mb_strlen($desc) > 320 || $site === '') {
            flash('error', 'Site name and default title are required (title ≤ 160, description ≤ 320 characters).');
        } else {
            foreach (['default_seo_title' => $title, 'default_seo_description' => $desc, 'site_name' => $site] + ($img ? ['default_og_image' => $img] : []) as $k => $v) {
                db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public, updated_by) VALUES (?, ?, 'seo', 1, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)", [$k, $v, $me['id']]);
            }
            audit_log('seo_global_updated', 'site_settings');
            flash('success', 'Global SEO saved.');
        }
        redirect_to('seo.php?tab=global');
    }
    admin_start('SEO', null, 'seo', ['head' => true]);
    echo admin_tabs($tabs, $tab) . $note;
    $s = settings_all();
    ?>
    <form method="post" enctype="multipart/form-data" class="card" data-dirty-check>
      <?= csrf_field() ?>
      <div class="card-head"><h2>Defaults for every page</h2></div>
      <div class="card-pad form">
        <div class="field"><label for="g-site">Site name</label><input id="g-site" type="text" name="site_name" value="<?= e($s['site_name'] ?? '') ?>" required></div>
        <div class="field"><label>Canonical domain</label><input type="text" value="<?= e(app_url('')) ?>" disabled><div class="hint">Set by APP_URL in .env. Use your final https:// domain.</div></div>
        <div class="field full"><label for="g-title">Default title</label><input id="g-title" type="text" name="default_seo_title" value="<?= e($s['default_seo_title'] ?? '') ?>" data-counter="60,70" maxlength="160"></div>
        <div class="field full"><label for="g-desc">Default meta description</label><textarea id="g-desc" name="default_seo_description" rows="2" data-counter="160,320" maxlength="320"><?= e($s['default_seo_description'] ?? '') ?></textarea></div>
        <div class="field full"><span class="lbl">Search result preview</span><div class="serp" data-serp-title="#g-title" data-serp-desc="#g-desc"><div class="u"><?= e(parse_url(app_url(''), PHP_URL_HOST)) ?></div><div class="t"></div><div class="d"></div></div></div>
        <div class="field" id="og-image"><label for="g-og">Default social sharing image (1200×630)</label><input id="g-og" type="file" name="default_og_image" accept="image/*" data-preview="og-pv">
          <img id="og-pv" class="thumb-preview" alt="" <?= !empty($s['default_og_image']) ? 'src="' . e(public_file_url($s['default_og_image'])) . '"' : 'hidden' ?>></div>
        <div class="field" id="og"><span class="lbl">Open Graph & robots</span><p class="muted" style="margin:0">Pages output Open Graph and Twitter card tags automatically from their title, description and image. Indexing is <?= is_production() ? '<strong>enabled</strong>' : '<strong>blocked</strong> because APP_ENV is not "production"' ?>; private areas are always noindex.</p></div>
      </div>
      <div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><button class="btn btn-primary"><?= icon('check') ?> Save</button></div>
    </form>
    <?php
    admin_end();
    exit;
}

if ($tab === 'courses' || $tab === 'blog') {
    admin_start('SEO', 'seo.manage', 'seo', ['head' => true]);
    echo admin_tabs($tabs, $tab) . $note;
    $rows = $tab === 'courses'
        ? db_all("SELECT id, title, slug, status, seo_title, seo_description, short_description AS fallback, thumbnail_path AS image FROM courses ORDER BY status = 'published' DESC, title")
        : db_all("SELECT id, title, slug, status, seo_title, seo_description, excerpt AS fallback, featured_image_path AS image FROM blog_posts ORDER BY published_at DESC");
    $editBase = $tab === 'courses' ? 'courses.php' : 'blog.php';
    $public = $tab === 'courses' ? 'courses/' : 'blog/';
    echo '<div class="card"><div class="table-wrap"><table class="t stack"><thead><tr><th>' . ($tab === 'courses' ? 'Course' : 'Post') . ' / URL slug</th><th>SEO title</th><th>Meta description</th><th>Social image</th><th>Status</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $t = $r['seo_title'] ?: $r['title'] . ' | Laxyam Academy';
        $d = $r['seo_description'] ?: (string) $r['fallback'];
        $tl = mb_strlen($t);
        $dl = mb_strlen($d);
        echo '<tr><td data-label="' . ($tab === 'courses' ? 'Course' : 'Post') . '"><strong>' . e($r['title']) . '</strong><br><code class="muted" style="font-size:11px">/' . e($public . $r['slug']) . '</code></td>'
            . '<td data-label="SEO title">' . e(mb_strimwidth($t, 0, 60, '…')) . '<br><span class="' . ($tl > 70 ? 'badge b-amber' : 'muted') . '" style="font-size:11px">' . $tl . ' chars' . ($r['seo_title'] ? '' : ' (auto)') . '</span></td>'
            . '<td data-label="Description" class="muted" style="font-size:12px">' . e(mb_strimwidth($d, 0, 90, '…')) . '<br><span class="' . ($dl === 0 || $dl > 170 ? 'badge b-amber' : '') . '">' . $dl . ' chars' . ($r['seo_description'] ? '' : ' (auto)') . '</span></td>'
            . '<td data-label="Social image">' . ($r['image'] ? '<span class="badge b-green">Yes</span>' : '<span class="badge b-gray">Default</span>') . '</td><td data-label="Status">' . badge($r['status']) . '</td>'
            . '<td class="actions"><a class="btn btn-light btn-sm" href="' . $editBase . '?action=edit&amp;id=' . (int) $r['id'] . '">' . icon('edit') . ' Edit SEO</a></td></tr>';
    }
    echo '</tbody></table></div></div>';
    admin_end();
    exit;
}

if ($tab === 'audit') {
    admin_start('SEO', 'seo.manage', 'seo', ['head' => true, 'subtitle' => 'Automatic checks of on-site SEO basics.']);
    echo admin_tabs($tabs, $tab) . $note;
    $issues = [];
    if (!is_production()) {
        $issues[] = ['critical', 'Indexing blocked', 'APP_ENV is not "production", so robots.txt and every page tell search engines not to index the site.', 'settings.php#server'];
    }
    if (!str_starts_with(app_url(''), 'https://')) {
        $issues[] = ['serious', 'Site URL is not HTTPS', 'APP_URL should be your final https:// domain; canonical URLs and the sitemap use it.', 'help.php#integrations'];
    }
    foreach (db_all("SELECT id, title FROM courses WHERE status = 'published' AND (seo_description IS NULL OR seo_description = '') AND (short_description IS NULL OR short_description = '')") as $r) {
        $issues[] = ['serious', 'Missing description: ' . $r['title'], 'Course has neither an SEO description nor a short description.', 'courses.php?action=edit&id=' . $r['id']];
    }
    foreach (db_all("SELECT id, title FROM courses WHERE status = 'published' AND thumbnail_path IS NULL") as $r) {
        $issues[] = ['minor', 'No social image: ' . $r['title'], 'Links shared on WhatsApp/Facebook will use the default image.', 'courses.php?action=edit&id=' . $r['id']];
    }
    foreach (db_all("SELECT id, title FROM blog_posts WHERE status = 'published' AND featured_image_path IS NULL") as $r) {
        $issues[] = ['minor', 'No featured image: ' . $r['title'], 'Add an image for better sharing previews.', 'blog.php?action=edit&id=' . $r['id']];
    }
    foreach (db_all("SELECT path FROM seo_pages WHERE meta_description IS NULL OR meta_description = ''") as $r) {
        $issues[] = ['moderate', 'Missing meta description: ' . $r['path'], 'The default description will be used.', 'seo.php?tab=pages'];
    }
    foreach (db_all("SELECT t, GROUP_CONCAT(src SEPARATOR ', ') AS srcs, COUNT(*) n FROM (
            SELECT COALESCE(NULLIF(seo_title, ''), title) AS t, CONCAT('course: ', slug) AS src FROM courses WHERE status = 'published'
            UNION ALL SELECT COALESCE(NULLIF(seo_title, ''), title), CONCAT('blog: ', slug) FROM blog_posts WHERE status = 'published'
            UNION ALL SELECT title, CONCAT('page: ', path) FROM seo_pages WHERE title IS NOT NULL) x GROUP BY t HAVING n > 1") as $r) {
        $issues[] = ['moderate', 'Duplicate title', '“' . $r['t'] . '” is used by ' . $r['srcs'] . '.', 'seo.php?tab=courses'];
    }
    foreach (db_all("SELECT a.from_path, a.to_path FROM redirects a JOIN redirects b ON b.from_path = a.to_path AND b.is_active = 1 WHERE a.is_active = 1") as $r) {
        $issues[] = ['moderate', 'Redirect chain', $r['from_path'] . ' → ' . $r['to_path'] . ' redirects again. Point it at the final URL.', 'seo.php?tab=redirects'];
    }
    foreach (db_all("SELECT path FROM seo_pages WHERE robots <> 'index,follow'") as $r) {
        $issues[] = ['minor', 'Noindex page: ' . $r['path'], 'This page is excluded from search results on purpose - check that is intended.', 'seo.php?tab=pages'];
    }
    // Results of the last broken-link scan (broken-links.php)
    $scan = json_decode_array(setting('broken_links_last_scan'));
    foreach (db_all('SELECT url, found_on, status_code FROM broken_links ORDER BY status_code IS NULL DESC, status_code DESC LIMIT 50') as $r) {
        $issues[] = [(int) $r['status_code'] === 404 || !$r['status_code'] ? 'serious' : 'moderate', 'Broken link: ' . mb_strimwidth((string) $r['url'], 0, 90, '…'),
            ($r['status_code'] ? 'HTTP ' . (int) $r['status_code'] : 'Unreachable') . ' · found on ' . $r['found_on'], 'broken-links.php'];
    }
    if (!$scan) {
        $issues[] = ['minor', 'Links not checked yet', 'Run the broken link checker to find links that lead to missing pages.', 'broken-links.php'];
    }
    if ((string) setting('gsc_verification', '') === '' && (string) setting('gsc_property', '') === '') {
        $issues[] = ['minor', 'Google Search Console not set up', 'Verify the site and submit sitemap.xml to see how Google indexes it.', 'indexing.php'];
    }
    $order = ['critical' => 0, 'serious' => 1, 'moderate' => 2, 'minor' => 3];
    usort($issues, fn($a, $b) => $order[$a[0]] <=> $order[$b[0]]);
    $counts = array_count_values(array_column($issues, 0));
    $tone = ['critical' => 'b-red', 'serious' => 'b-amber', 'moderate' => 'b-blue', 'minor' => 'b-gray'];
    echo '<section class="grid g-4" style="margin-bottom:18px">';
    foreach (['critical' => 'Critical', 'serious' => 'Serious', 'moderate' => 'Moderate', 'minor' => 'Minor'] as $k => $l) {
        echo '<div class="card stat"><span class="ico ' . ['critical' => 'tone-red', 'serious' => 'tone-amber', 'moderate' => 'tone-blue', 'minor' => 'tone-purple'][$k] . '">' . icon('alert') . '</span><span><span class="label">' . $l . '</span><span class="value" style="display:block">' . (int) ($counts[$k] ?? 0) . '</span></span></div>';
    }
    echo '</section><div class="card"><div class="card-head"><h2>Issues (' . count($issues) . ')</h2><span class="muted" style="font-size:12px">'
        . ($scan ? 'Includes the broken-link scan of ' . e(fmt_dt((string) $scan['at'])) . ' · <a href="broken-links.php">Scan again</a>' : 'Broken links: <a href="broken-links.php">run a scan</a>') . '</span></div>';
    if (!$issues) {
        echo '<div class="empty"><span class="empty-ic">' . icon('check') . '</span><strong>No issues found</strong><p>Every automatic check passed.</p></div>';
    } else {
        echo '<div class="table-wrap"><table class="t"><thead><tr><th>Severity</th><th>Issue</th><th></th></tr></thead><tbody>';
        foreach ($issues as [$sev, $title, $detail, $href]) {
            echo '<tr><td><span class="badge ' . $tone[$sev] . '">' . ucfirst($sev) . '</span></td><td><strong>' . e($title) . '</strong><br><span class="muted">' . e($detail) . '</span></td><td class="actions"><a class="btn btn-light btn-sm" href="' . e($href) . '">Fix</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    admin_end();
    exit;
}

if ($tab === 'tools') {
    admin_start('SEO', 'seo.manage', 'seo', ['head' => true]);
    echo admin_tabs($tabs, $tab);
    $prod = is_production();
    ?>
    <div class="grid g-2">
      <div class="card card-pad">
        <h2 style="font-size:16px">XML Sitemap</h2>
        <p class="muted">Generated automatically from published courses, blog posts and pages. Submit it in Google Search Console.</p>
        <p><a class="btn btn-light" href="<?= e(app_url('sitemap.xml')) ?>" target="_blank" rel="noopener"><?= icon('external') ?> <?= e(app_url('sitemap.xml')) ?></a></p>
      </div>
      <div class="card card-pad">
        <h2 style="font-size:16px">robots.txt</h2>
        <p class="muted">Private areas (dashboard, checkout, admin, API) are always disallowed.
        <?= $prod ? 'Indexing is enabled (APP_ENV=production).' : '<strong>Indexing is blocked because APP_ENV is not "production".</strong>' ?></p>
        <p><a class="btn btn-light" href="<?= e(app_url('robots.txt')) ?>" target="_blank" rel="noopener"><?= icon('external') ?> View robots.txt</a></p>
      </div>
    </div>
    <?php
    admin_end();
    exit;
}

if ($tab === 'redirects') {
    crud([
        'table' => 'redirects', 'title' => 'SEO', 'singular' => 'redirect', 'permission' => 'seo.manage', 'active' => 'seo',
        'tabs' => admin_tabs($tabs, $tab), 'search' => ['t.from_path', 't.to_path'],
        'subtitle' => 'Redirects are applied to website URLs that would otherwise show a 404.',
        'columns' => [
            'from_path' => ['From', fn($r) => '<code>' . e($r['from_path']) . '</code>'],
            'to_path' => ['To', fn($r) => '<code>' . e($r['to_path']) . '</code>'],
            'status_code' => 'Code', 'hits' => 'Hits',
            'is_active' => ['Active', fn($r) => badge($r['is_active'] ? 'active' : 'inactive')],
        ],
        'before_save' => function (array $d) {
            if (!str_starts_with($d['from_path'], '/')) {
                throw new CrudError(['from_path' => 'Start with "/" (path only, e.g. /old-course).']);
            }
            if (!str_starts_with($d['to_path'], '/') && !preg_match('#^https://#', $d['to_path'])) {
                throw new CrudError(['to_path' => 'Use a path starting with "/" or a full https:// URL.']);
            }
            if (rtrim($d['from_path'], '/') === rtrim($d['to_path'], '/')) {
                throw new CrudError(['to_path' => 'A redirect cannot point to itself.']);
            }
            return $d;
        },
        'fields' => [
            'from_path' => ['label' => 'From path', 'required' => true, 'max' => 255, 'unique' => true, 'placeholder' => '/old-page'],
            'to_path' => ['label' => 'To (path or https URL)', 'required' => true, 'max' => 500, 'placeholder' => '/courses/new-page'],
            'status_code' => ['label' => 'Type', 'type' => 'select', 'required' => true, 'default' => '301', 'options' => ['301' => '301 Permanent', '302' => '302 Temporary']],
            'is_active' => ['label' => 'Active', 'type' => 'checkbox', 'default' => 1],
        ],
    ]);
    exit;
}

crud([
    'table' => 'seo_pages', 'title' => 'SEO', 'singular' => 'page meta', 'permission' => 'seo.manage', 'active' => 'seo',
    'tabs' => admin_tabs($tabs, $tab) . $note, 'order' => 't.path', 'search' => ['t.path', 't.title'],
    'filters' => ['robots' => ['col' => "IF(t.robots = 'index,follow', 'index', 'noindex')", 'label' => 'Indexing', 'options' => ['index' => 'Indexed', 'noindex' => 'Noindex']]],
    'form_extra' => fn($row) => '<span class="lbl">Search result preview</span><div class="serp" data-serp-title="#f-title" data-serp-desc="#f-meta_description"><div class="u">' . e(parse_url(app_url(''), PHP_URL_HOST)) . ' › ' . e(trim((string) ($row['path'] ?? ''), '/')) . '</div><div class="t"></div><div class="d"></div></div>',
    'subtitle' => 'Meta for static pages. Courses, blog posts and resources have their own SEO fields.',
    'columns' => [
        'path' => ['Path', fn($r) => '<code>' . e($r['path']) . '</code>'],
        'title' => ['Title', fn($r) => e($r['title'] ?? '') . '<br><span class="muted">' . mb_strlen((string) $r['title']) . ' chars</span>'],
        'meta_description' => ['Description', fn($r) => '<span class="muted">' . e(mb_strimwidth((string) $r['meta_description'], 0, 80, '…')) . '</span>'],
        'robots' => ['Robots', fn($r) => badge($r['robots'] === 'index,follow' ? 'active' : 'inactive')],
    ],
    'fields' => [
        'path' => ['label' => 'Path (e.g. /courses)', 'required' => true, 'max' => 255, 'unique' => true],
        'title' => ['label' => 'Title tag', 'max' => 160, 'counter' => '60,70', 'hint' => 'About 50-60 characters usually display fully in search results.'],
        'meta_description' => ['label' => 'Meta description', 'type' => 'textarea', 'max' => 320, 'rows' => 2, 'counter' => '160,320', 'hint' => 'About 140-160 characters. Search engines may rewrite it.'],
        'canonical_url' => ['label' => 'Canonical URL (optional)', 'type' => 'url'],
        'robots' => ['label' => 'Robots', 'type' => 'select', 'required' => true, 'default' => 'index,follow', 'options' => ['index,follow' => 'Index, follow', 'noindex,follow' => 'No index, follow', 'noindex,nofollow' => 'No index, no follow']],
        'og_title' => ['label' => 'Open Graph title', 'max' => 160],
        'og_description' => ['label' => 'Open Graph description', 'max' => 320],
        'og_image' => ['label' => 'Open Graph image (1200×630)', 'type' => 'image', 'folder' => 'cms', 'w' => 1200, 'h' => 630],
        'structured_data' => ['label' => 'Structured data (JSON-LD, optional)', 'type' => 'json'],
    ],
]);
