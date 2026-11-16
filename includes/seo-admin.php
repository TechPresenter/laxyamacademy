<?php
/**
 * admin/seo.php tabs built on includes/seo.php:
 *   health      SEO Health dashboard: every public URL checked with the same rules the website uses
 *               (title, description, duplicates, canonical, robots, H1, structured data, focus keyword),
 *               a diagnostic score per URL and the URL-wise metadata sheet as CSV (&export=csv)
 *   categories  Category SEO: titles / descriptions / introductions of the category pages
 *   notfound    404 Monitor: URLs that answered "page not found", with one-click redirect
 * The score is a checklist of on-page basics - not a prediction of Google rankings.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** Every public URL the site knows about (sitemap candidates + static pages), including noindex ones. */
function seo_admin_all_paths(): array
{
    $paths = SEO_STATIC_PATHS;
    foreach (array_keys(SEO_SITEMAP_PARTS) as $part) {
        array_push($paths, ...seo_sitemap_candidates($part));
    }
    return array_values(array_unique($paths));
}

/** One row of the health sheet: resolved meta + issues. */
function seo_admin_check(array $m, array $dupTitles, array $dupDescs): array
{
    $issues = [];
    $checks = 0;
    $fail = function (bool $bad, string $sev, string $msg) use (&$issues, &$checks) {
        $checks++;
        if ($bad) {
            $issues[] = [$sev, $msg];
        }
    };
    $tl = mb_strlen($m['title']);
    $dl = mb_strlen($m['description']);
    $index = $m['status'] === 200 && $m['robots'] === 'index,follow';
    $fail($m['status'] !== 200, 'serious', 'Answers ' . $m['status']);
    $fail($tl === 0, 'serious', 'Missing title');
    $fail($tl > 0 && ($tl < 25 || $tl > 70), 'minor', 'Title is ' . $tl . ' characters (about 50-65 shows fully)');
    $fail($dl === 0, 'serious', 'Missing meta description');
    $fail($dl > 0 && ($dl < 70 || $dl > 170), 'minor', 'Description is ' . $dl . ' characters (about 120-160 is best)');
    $fail($index && isset($dupTitles[$m['title']]) && $dupTitles[$m['title']] > 1, 'moderate', 'Same title as another page');
    $fail($index && $m['description'] !== '' && isset($dupDescs[$m['description']]) && $dupDescs[$m['description']] > 1, 'moderate', 'Same description as another page');
    $fail($m['status'] === 200 && !$m['h1'], 'moderate', 'No main heading (H1) known for this page');
    $fail($index && $m['canonical'] !== seo_url($m['path']), 'moderate', 'Canonical points to another URL: ' . (string) $m['canonical']);
    $fail($index && in_array($m['kind'], ['course', 'category', 'home', 'post', 'job'], true) && !$m['jsonld'], 'moderate', 'No structured data');
    $fail($index && in_array($m['kind'], ['course', 'post'], true) && str_contains((string) $m['image'], '/assets/'), 'minor', 'Uses the default sharing image (add a course / post image)');
    if ($m['focus_keyword']) {
        $kw = mb_strtolower(trim((string) $m['focus_keyword']));
        $fail(!str_contains(mb_strtolower($m['title']), $kw), 'minor', 'Focus keyword "' . $m['focus_keyword'] . '" is not in the title');
        $fail(!str_contains(mb_strtolower($m['description']), $kw), 'minor', 'Focus keyword "' . $m['focus_keyword'] . '" is not in the description');
    }
    if ($m['robots'] !== 'index,follow' && $m['status'] === 200) {
        $issues[] = ['info', 'Not indexed: ' . ($m['noindex_reason'] ?: $m['robots'])];
    }
    $failed = count(array_filter($issues, fn($i) => $i[0] !== 'info'));
    return ['score' => $checks ? (int) round(100 * ($checks - $failed) / $checks) : 100, 'issues' => $issues, 'indexable' => $index];
}

/** Resolved meta + checks for every public URL. */
function seo_admin_sheet(): array
{
    $metas = array_map(fn($p) => seo_resolve($p), seo_admin_all_paths());
    $idx = array_filter($metas, fn($m) => $m['status'] === 200 && $m['robots'] === 'index,follow');
    $dupT = array_count_values(array_column($idx, 'title'));
    $dupD = array_count_values(array_filter(array_column($idx, 'description')));
    return array_map(fn($m) => $m + ['check' => seo_admin_check($m, $dupT, $dupD)], $metas);
}

function seo_admin_tab(string $tab, array $tabs, string $note): void
{
    match ($tab) {
        'health' => seo_admin_health($tabs, $note),
        'categories' => seo_admin_categories($tabs, $note),
        'notfound' => seo_admin_notfound($tabs),
    };
}

function seo_admin_health(array $tabs, string $note): void
{
    require_staff_page();
    require_permission('seo.manage');
    $rows = seo_admin_sheet();

    if (($_GET['export'] ?? '') === 'csv') {
        audit_log('seo_sheet_exported', 'seo_pages');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="seo-url-sheet-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['URL', 'Type', 'Status', 'Robots', 'Indexed reason', 'Title', 'Title length', 'Meta description', 'Description length', 'Canonical', 'H1', 'Structured data', 'Sharing image', 'Focus keyword', 'Secondary keywords', 'Last modified', 'Score', 'Issues']);
        foreach ($rows as $m) {
            $types = array_map(fn($n) => is_array($n['@type'] ?? null) ? implode('+', $n['@type']) : (string) ($n['@type'] ?? ''), $m['jsonld']);
            fputcsv($out, [seo_url($m['path']), $m['kind'], $m['status'], seo_robots_sent($m) === $m['robots'] ? $m['robots'] : $m['robots'] . ' (site not in production: noindex)', (string) $m['noindex_reason'],
                $m['title'], mb_strlen($m['title']), $m['description'], mb_strlen($m['description']), (string) $m['canonical'], (string) $m['h1'], implode(', ', $types),
                (string) $m['image'], (string) $m['focus_keyword'], (string) $m['secondary_keywords'], (string) $m['lastmod'], $m['check']['score'],
                implode(' | ', array_map(fn($i) => $i[1], $m['check']['issues']))]);
        }
        fclose($out);
        exit;
    }

    admin_start('SEO', 'seo.manage', 'seo', ['head' => true, 'subtitle' => 'Every public URL, checked with the same rules the website uses to print its tags.']);
    echo admin_tabs($tabs, 'health') . $note;
    $indexable = array_filter($rows, fn($m) => $m['check']['indexable']);
    $count = fn(callable $f) => count(array_filter($rows, $f));
    $has = fn(array $m, string $txt) => (bool) array_filter($m['check']['issues'], fn($i) => str_contains($i[1], $txt));
    $avg = $indexable ? (int) round(array_sum(array_map(fn($m) => $m['check']['score'], $indexable)) / count($indexable)) : 0;
    try {
        $broken = (int) db_value('SELECT COUNT(*) FROM broken_links');
    } catch (PDOException $e) {
        $broken = 0;
    }
    try {
        $notFound = (int) db_value('SELECT COUNT(*) FROM seo_404_log WHERE last_seen >= NOW() - INTERVAL 30 DAY');
    } catch (PDOException $e) {
        $notFound = 0;
    }
    $stats = [
        ['Indexable URLs', count($indexable), 'tone-green', 'check'],
        ['Not indexed (on purpose)', $count(fn($m) => !$m['check']['indexable'] && $m['status'] === 200), 'tone-purple', 'eye'],
        ['Missing / long titles', $count(fn($m) => $has($m, 'title') && !$has($m, 'Focus')), 'tone-amber', 'alert'],
        ['Missing / long descriptions', $count(fn($m) => $has($m, 'escription') && !$has($m, 'Focus')), 'tone-amber', 'alert'],
        ['Duplicate titles / descriptions', $count(fn($m) => $has($m, 'Same ')), 'tone-red', 'copy'],
        ['With structured data', $count(fn($m) => $m['check']['indexable'] && $m['jsonld']), 'tone-blue', 'code'],
        ['Broken links (last scan)', $broken, $broken ? 'tone-red' : 'tone-green', 'link'],
        ['404 URLs (30 days)', $notFound, $notFound ? 'tone-amber' : 'tone-green', 'alert'],
    ];
    echo '<section class="grid g-4" style="margin-bottom:14px">';
    foreach ($stats as [$label, $value, $tone, $ico]) {
        echo '<div class="card stat"><span class="ico ' . $tone . '">' . icon($ico) . '</span><span><span class="label">' . e($label) . '</span><span class="value" style="display:block">' . (int) $value . '</span></span></div>';
    }
    echo '</section>';
    echo '<div class="card card-pad" style="margin-bottom:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">'
        . '<p style="margin:0"><strong>On-page checklist score: ' . $avg . '%</strong> <span class="muted">(average of the indexable URLs - a diagnostic of on-page basics, not a Google ranking)</span>'
        . (!is_production() ? '<br><span class="badge b-amber">This copy is not in production: every page is sent as noindex. The sheet shows the live rules.</span>' : '') . '</p>'
        . '<span style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn btn-light btn-sm" href="seo.php?tab=health&amp;export=csv">' . icon('download') . ' Export URL sheet (CSV)</a>'
        . '<a class="btn btn-light btn-sm" href="broken-links.php">' . icon('link') . ' Broken link checker</a>'
        . '<a class="btn btn-light btn-sm" href="' . e(app_url('sitemap.xml')) . '" target="_blank" rel="noopener">' . icon('external') . ' sitemap.xml</a></span></div>';

    echo '<div class="card"><div class="table-wrap"><table class="t stack"><thead><tr><th>URL</th><th>Title / description</th><th>Schema</th><th>Score</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
    usort($rows, fn($a, $b) => [$b['check']['indexable'], $a['check']['score']] <=> [$a['check']['indexable'], $b['check']['score']]);
    foreach ($rows as $m) {
        $types = array_map(fn($n) => is_array($n['@type'] ?? null) ? implode('+', $n['@type']) : (string) ($n['@type'] ?? ''), $m['jsonld']);
        $sev = ['serious' => 'b-red', 'moderate' => 'b-amber', 'minor' => 'b-gray', 'info' => 'b-blue'];
        $issues = implode('', array_map(fn($i) => '<li><span class="badge ' . $sev[$i[0]] . '" style="font-size:10px">' . e($i[0]) . '</span> ' . e($i[1]) . '</li>', $m['check']['issues']));
        $edit = $m['override']
            ? 'seo.php?tab=pages&amp;action=edit&amp;id=' . (int) $m['override']
            : 'seo.php?tab=pages&amp;action=new&amp;path=' . rawurlencode($m['path']);
        echo '<tr><td data-label="URL"><a href="' . e(seo_url($m['path'])) . '" target="_blank" rel="noopener"><code style="font-size:12px">' . e($m['path']) . '</code></a><br>'
            . '<span class="muted" style="font-size:11px">' . e($m['kind']) . ' · ' . ($m['check']['indexable'] ? '<span class="badge b-green">index</span>' : '<span class="badge b-gray">' . e($m['robots']) . '</span>') . '</span></td>'
            . '<td data-label="Title"><strong style="font-size:13px">' . e($m['title']) . '</strong> <span class="muted" style="font-size:11px">(' . mb_strlen($m['title']) . ')</span><br>'
            . '<span class="muted" style="font-size:12px">' . e(mb_strimwidth($m['description'], 0, 170, '…')) . ' (' . mb_strlen($m['description']) . ')</span>'
            . ($issues ? '<ul style="margin:6px 0 0;padding-left:0;list-style:none;font-size:12px">' . $issues . '</ul>' : '') . '</td>'
            . '<td data-label="Schema" style="font-size:12px">' . ($types ? e(implode(', ', $types)) : '<span class="muted">-</span>') . '</td>'
            . '<td data-label="Score"><span class="badge ' . ($m['check']['score'] >= 90 ? 'b-green' : ($m['check']['score'] >= 70 ? 'b-amber' : 'b-red')) . '">' . $m['check']['score'] . '%</span></td>'
            . '<td class="actions"><a class="btn btn-light btn-sm" href="' . $edit . '">' . icon('edit') . ' ' . ($m['override'] ? 'Edit SEO' : 'Override') . '</a></td></tr>';
    }
    echo '</tbody></table></div></div>';
    echo '<p class="muted" style="font-size:12px;margin-top:10px">Courses, categories and blog posts take their title and description from their own edit forms; '
        . '"Override" creates a Page SEO row for that exact URL (title, description, canonical, robots, sharing image, focus keyword, extra JSON-LD). '
        . 'Indexing in Google is shown in Search Console, not here.</p>';
    admin_end();
}

function seo_admin_categories(array $tabs, string $note): void
{
    admin_start('SEO', 'seo.manage', 'seo', ['head' => true]);
    echo admin_tabs($tabs, 'categories') . $note;
    $hasIntro = (bool) db_value("SHOW COLUMNS FROM course_categories LIKE 'intro'");
    $rows = db_all('SELECT c.id, c.name, c.slug, c.seo_title, c.seo_description, c.description, ' . ($hasIntro ? 'c.intro' : 'NULL AS intro') . ', c.is_active,
        (SELECT COUNT(*) FROM courses x JOIN course_categories xc ON xc.id = x.category_id WHERE x.status = \'published\' AND (xc.id = c.id OR xc.parent_id = c.id)) AS n
        FROM course_categories c ORDER BY c.is_active DESC, n > 0 DESC, c.sort_order, c.name');
    echo '<div class="card"><div class="table-wrap"><table class="t stack"><thead><tr><th>Category page</th><th>SEO title</th><th>Meta description</th><th>Intro</th><th>Courses</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $m = seo_resolve(seo_category_path($r['slug']));
        echo '<tr><td data-label="Category"><strong>' . e($r['name']) . '</strong><br><code class="muted" style="font-size:11px">' . e(seo_category_path($r['slug'])) . '</code></td>'
            . '<td data-label="SEO title">' . e(mb_strimwidth($m['title'], 0, 70, '…')) . '<br><span class="' . (mb_strlen($m['title']) > 70 ? 'badge b-amber' : 'muted') . '" style="font-size:11px">' . mb_strlen($m['title']) . ' chars' . ($r['seo_title'] ? '' : ' (auto)') . '</span></td>'
            . '<td data-label="Description" class="muted" style="font-size:12px">' . e(mb_strimwidth($m['description'], 0, 110, '…')) . '<br>' . mb_strlen($m['description']) . ' chars' . ($r['seo_description'] ? '' : ' (auto)') . '</td>'
            . '<td data-label="Intro">' . (trim((string) $r['intro']) !== '' ? '<span class="badge b-green">Yes</span>' : '<span class="badge b-gray">No</span>') . '</td>'
            . '<td data-label="Courses">' . (int) $r['n'] . ($r['n'] ? '' : ' <span class="muted" style="font-size:11px">(page not indexed)</span>') . '</td>'
            . '<td class="actions"><a class="btn btn-light btn-sm" href="categories.php?action=edit&amp;id=' . (int) $r['id'] . '">' . icon('edit') . ' Edit</a></td></tr>';
    }
    echo '</tbody></table></div></div>';
    admin_end();
}

function seo_admin_notfound(array $tabs): void
{
    $me = require_staff_page();
    require_permission('seo.manage');
    if (admin_post()) {
        try {
            if (($_POST['_action'] ?? '') === 'clear') {
                db_query('DELETE FROM seo_404_log');
                flash('success', '404 log cleared.');
            } elseif (($_POST['_action'] ?? '') === 'delete') {
                db_query('DELETE FROM seo_404_log WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
                flash('success', 'Removed from the list.');
            }
            audit_log('seo_404_log_' . ($_POST['_action'] ?? ''), 'seo_404_log');
        } catch (PDOException $e) {
            flash('error', 'Apply the latest Database Update first (migration 021).');
        }
        redirect_to('seo.php?tab=notfound');
    }
    admin_start('SEO', 'seo.manage', 'seo', ['head' => true, 'subtitle' => 'Website URLs that answered "page not found". Point the real ones to the right page with a 301 redirect.']);
    echo admin_tabs($tabs, 'notfound');
    try {
        $rows = db_all('SELECT id, path, hits, last_referrer, first_seen, last_seen FROM seo_404_log ORDER BY last_seen >= NOW() - INTERVAL 30 DAY DESC, hits DESC, last_seen DESC LIMIT 300');
    } catch (PDOException $e) {
        echo '<div class="card card-pad"><p>Apply the latest Database Update (migration 021) to start recording 404 pages.</p><p><a class="btn btn-primary" href="updates.php">Database Updates</a></p></div>';
        admin_end();
        return;
    }
    $redirected = array_flip(array_column(db_all('SELECT from_path FROM redirects WHERE is_active = 1'), 'from_path'));
    if (!$rows) {
        echo '<div class="card"><div class="empty"><span class="empty-ic">' . icon('check') . '</span><strong>No 404 pages recorded</strong><p>Every visited website URL was found.</p></div></div>';
        admin_end();
        return;
    }
    echo '<div class="card"><div class="card-head"><h2>Not found (' . count($rows) . ')</h2>' . action_form('clear', [], icon('trash') . ' Clear all', 'btn btn-light btn-sm', 'Clear the whole 404 list?') . '</div>'
        . '<div class="table-wrap"><table class="t stack"><thead><tr><th>URL</th><th>Hits</th><th>Last seen</th><th>Came from</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $ref = (string) $r['last_referrer'];
        echo '<tr><td data-label="URL"><code style="font-size:12px">' . e($r['path']) . '</code>' . (isset($redirected[$r['path']]) ? ' <span class="badge b-green">redirected</span>' : '') . '</td>'
            . '<td data-label="Hits">' . (int) $r['hits'] . '</td><td data-label="Last seen">' . e(fmt_dt($r['last_seen'])) . '</td>'
            . '<td data-label="Came from" class="muted" style="font-size:12px">' . ($ref !== '' ? e(mb_strimwidth($ref, 0, 60, '…')) : '-') . '</td>'
            . '<td class="actions" style="white-space:nowrap"><a class="btn btn-light btn-sm" href="seo.php?tab=redirects&amp;action=new&amp;from_path=' . rawurlencode($r['path']) . '">' . icon('right') . ' Redirect</a> '
            . action_form('delete', ['id' => (int) $r['id']], icon('x'), 'btn btn-light btn-sm', null, 'Remove from list') . '</td></tr>';
    }
    echo '</tbody></table></div></div><p class="muted" style="font-size:12px;margin-top:10px">Old entries are removed automatically after 90 days. Ignore obvious bot probes (e.g. /wp-login.php) - they are not real pages.</p>';
    admin_end();
}
