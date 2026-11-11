<?php
// Broken link checker: collects links from blog posts, courses, lessons, CMS sections,
// custom pages, reusable sections and popups, requests each one and lists the failures.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';

$me = require_staff_page();
require_permission('seo.manage');

const BL_MAX_URLS = 250;

/** Every link-like string inside HTML or decoded JSON. */
function bl_extract(mixed $value, array &$out): void
{
    if (is_array($value)) {
        foreach ($value as $v) {
            bl_extract($v, $out);
        }
        return;
    }
    if (!is_string($value) || $value === '') {
        return;
    }
    if (preg_match_all('#\b(?:href|src)\s*=\s*["\']([^"\']+)["\']#i', $value, $m)) {
        array_push($out, ...$m[1]);
    } elseif (preg_match('#^(https?://|/)[^\s<>"\']{1,490}$#i', trim($value))) {
        $out[] = trim($value);
    }
}

/** [url => [found_on, ...]] */
function bl_collect(): array
{
    $sources = [
        ['Blog post', "SELECT id, title, content AS c FROM blog_posts WHERE status = 'published'", false],
        ['Course', "SELECT id, title, CONCAT_WS(' ', description, short_description) AS c FROM courses WHERE status = 'published'", false],
        ['Lesson', "SELECT id, title, content AS c FROM lessons WHERE content IS NOT NULL AND content <> ''", false],
        ['Website section', 'SELECT section_key AS id, title, content AS c FROM cms_sections WHERE is_active = 1', true],
        ['Page', "SELECT id, title, blocks AS c FROM pages WHERE status = 'published'", true],
        ['Reusable section', 'SELECT id, name AS title, blocks AS c FROM reusable_sections', true],
        ['Popup', "SELECT id, title, CONCAT_WS(' ', cta_url, image_path) AS c FROM popups WHERE is_active = 1", false],
    ];
    $links = [];
    foreach ($sources as [$label, $sql, $json]) {
        try {
            $rows = db_all($sql);
        } catch (PDOException) {
            continue; // optional column missing on an older schema
        }
        foreach ($rows as $r) {
            $found = [];
            bl_extract($json ? json_decode_array($r['c']) : (string) $r['c'], $found);
            foreach (array_unique($found) as $u) {
                $u = html_entity_decode(trim($u), ENT_QUOTES);
                if ($u === '' || $u[0] === '#' || preg_match('#^(mailto|tel|javascript|data|whatsapp|sms):#i', $u)) {
                    continue;
                }
                if (str_starts_with($u, '//')) {
                    $u = 'https:' . $u;
                } elseif ($u[0] === '/') {
                    $u = app_url(ltrim($u, '/'));
                } elseif (!preg_match('#^https?://#i', $u)) {
                    if (!preg_match('#^uploads/|^assets/#', $u)) {
                        continue;
                    }
                    $u = app_url($u);
                }
                $links[$u][] = mb_substr($label . ': ' . $r['title'], 0, 120);
            }
        }
    }
    return $links;
}

/** Check URLs in parallel. Returns [url => [status|null, error|null]]. */
function bl_check(array $urls): array
{
    $results = [];
    foreach (array_chunk($urls, 10) as $chunk) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $u) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
                CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'LaxyamLinkChecker/1.0 (+' . app_url() . ')',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$u] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $st === CURLM_OK);
        foreach ($handles as $u => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            // Some servers reject HEAD; retry those with a small GET.
            if (in_array($code, [403, 405, 501], true)) {
                curl_setopt_array($ch, [CURLOPT_NOBODY => false, CURLOPT_HTTPGET => true, CURLOPT_RANGE => '0-1023']);
                curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $err = curl_error($ch);
            }
            $results[$u] = [$code ?: null, $code ? null : ($err ?: 'No response')];
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
    }
    return $results;
}

if (admin_post() && ($_POST['_action'] ?? '') === 'scan') {
    if (!function_exists('curl_multi_init')) {
        flash('error', 'The PHP cURL extension is required for the link checker. Enable it in php.ini.');
        redirect_to('broken-links.php');
    }
    @set_time_limit(300);
    session_write_close(); // do not block other admin tabs while scanning
    $links = bl_collect();
    $urls = array_slice(array_keys($links), 0, BL_MAX_URLS);
    $res = bl_check($urls);
    $broken = array_filter($res, fn($r) => $r[0] === null || $r[0] >= 400);
    db_transaction(function () use ($broken, $links) {
        db_query('DELETE FROM broken_links');
        foreach ($broken as $u => [$code, $err]) {
            db_query('INSERT INTO broken_links (url, found_on, status_code, error) VALUES (?, ?, ?, ?)',
                [mb_substr($u, 0, 500), mb_substr(implode(' · ', array_unique($links[$u])), 0, 255), $code, $err ? mb_substr($err, 0, 255) : null]);
        }
    });
    $summary = ['at' => date('Y-m-d H:i:s'), 'found' => count($links), 'checked' => count($urls), 'broken' => count($broken)];
    db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public) VALUES ('broken_links_last_scan', ?, 'seo', 0)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [json_encode($summary)]);
    session_start();
    audit_log('broken_links_scanned', 'broken_links', null, $summary);
    flash(count($broken) ? 'error' : 'success', 'Checked ' . count($urls) . ' links: ' . (count($broken) ? count($broken) . ' broken.' : 'none broken.'));
    redirect_to('broken-links.php');
}

$last = json_decode_array(setting('broken_links_last_scan'));
$rows = db_all('SELECT * FROM broken_links ORDER BY status_code IS NULL DESC, status_code DESC, url');
admin_start('Broken Links', null, 'seo', [
    'head' => true,
    'subtitle' => $last ? 'Last scan ' . fmt_dt($last['at']) . ' · ' . (int) $last['checked'] . ' of ' . (int) $last['found'] . ' links checked' : 'No scan yet. A scan checks up to ' . BL_MAX_URLS . ' links and takes up to a minute.',
    'actions' => '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="_action" value="scan"><button class="btn btn-primary" data-busy="Scanning…">' . icon('search') . ' Scan now</button></form>',
]);
if (!is_production()) {
    echo '<div class="alert alert-info">Internal links are checked against ' . e(app_url()) . '. On a local copy, links to the live domain are checked on the internet.</div>';
}
echo '<div class="card"><div class="card-head"><h2>' . ($rows ? count($rows) . ' broken link' . (count($rows) === 1 ? '' : 's') : 'Broken links') . '</h2></div>';
if (!$rows) {
    echo '<div class="empty"><span class="empty-ic">' . icon($last ? 'check' : 'link') . '</span><strong>' . ($last ? 'No broken links found in the last scan' : 'No scan yet') . '</strong>'
        . '<p>' . ($last ? 'All ' . (int) $last['checked'] . ' checked links answered normally.' : 'Scan now to check links in blog posts, courses, lessons, pages, popups and website sections.') . '</p></div>';
} else {
    echo '<div class="table-wrap"><table class="t"><thead><tr><th>Link</th><th>Problem</th><th>Found on</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $problem = $r['status_code'] ? match (true) {
            (int) $r['status_code'] === 404 => '<span class="badge b-red">404 Not found</span>',
            (int) $r['status_code'] >= 500 => '<span class="badge b-red">' . (int) $r['status_code'] . ' Server error</span>',
            default => '<span class="badge b-amber">HTTP ' . (int) $r['status_code'] . '</span>',
        } : '<span class="badge b-red">Unreachable</span><br><span class="muted" style="font-size:12px">' . e((string) $r['error']) . '</span>';
        echo '<tr><td style="max-width:420px;word-break:break-all"><a href="' . e($r['url']) . '" target="_blank" rel="noopener noreferrer">' . e($r['url']) . '</a></td><td>' . $problem . '</td><td>' . e($r['found_on']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div>';
admin_end();
