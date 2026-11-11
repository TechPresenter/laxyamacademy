<?php
// Website traffic and UTM campaigns from first-party data (page_views is written by api/track.php;
// attributions by register / enquiry / order). Conversion reports live in reports.php?tab=conversion.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/admin-reports.php';

require_staff_page();
require_permission('reports.view');

// Old tabs: conversions moved to the Conversion Reports screen, UTM campaigns are a section of this page.
$old = (string) ($_GET['tab'] ?? '');
if ($old === 'conversions') {
    redirect_to('reports.php?tab=conversion');
}
if ($old === 'utm' || $old === 'traffic') {
    redirect_to('traffic.php' . ($old === 'utm' ? '#campaigns' : ''));
}

$r = report_range();
$b = report_buckets($r['from'], $r['to']);
[$from, $to] = [$r['from'], $r['to']];
$days = [$from, $to];
$fmtN = fn($v) => number_format((float) $v);
$source = "COALESCE(NULLIF(%s.utm_source, ''), NULLIF(%s.referrer_host, ''), '(direct)')";

$campaigns = function () use ($from, $to, $r): array {
    $rows = db_all("SELECT COALESCE(utm_source, '') s, COALESCE(utm_medium, '') m, COALESCE(utm_campaign, '') c, COUNT(*) views, COUNT(DISTINCT day, visitor_hash) visitors
        FROM page_views WHERE day BETWEEN ? AND ? AND utm_source IS NOT NULL AND utm_source <> '' GROUP BY s, m, c ORDER BY visitors DESC LIMIT 200", [$from, $to]);
    $conv = array_column(db_all("SELECT CONCAT_WS('|', COALESCE(a.utm_source, ''), COALESCE(a.utm_medium, ''), COALESCE(a.utm_campaign, '')) AS k,
            SUM(a.entity_type = 'user') AS signups, SUM(a.entity_type = 'enquiry') AS enquiries,
            SUM(a.entity_type = 'order' AND o.status IN ('paid','partially_refunded')) AS sales,
            COALESCE(SUM(CASE WHEN a.entity_type = 'order' AND o.status IN ('paid','partially_refunded') THEN o.total END), 0) AS revenue
        FROM attributions a LEFT JOIN orders o ON a.entity_type = 'order' AND o.id = a.entity_id
        WHERE a.created_at BETWEEN ? AND ? AND a.utm_source IS NOT NULL AND a.utm_source <> '' GROUP BY k", $r['range']), null, 'k');
    // Campaigns that converted but whose landing visit fell outside the range still get a row.
    $seen = [];
    foreach ($rows as &$x) {
        $k = "{$x['s']}|{$x['m']}|{$x['c']}";
        $seen[$k] = true;
        $x += ($conv[$k] ?? []) + ['signups' => 0, 'enquiries' => 0, 'sales' => 0, 'revenue' => 0];
    }
    unset($x);
    foreach ($conv as $k => $c) {
        if (!isset($seen[$k])) {
            [$s, $m, $cmp] = explode('|', $k) + ['', '', ''];
            $rows[] = ['s' => $s, 'm' => $m, 'c' => $cmp, 'views' => 0, 'visitors' => 0] + $c;
        }
    }
    return $rows;
};

// ---------------- CSV exports ----------------
if (($_GET['export'] ?? '') === 'csv') {
    require_permission('reports.export');
    $part = (string) ($_GET['part'] ?? '');
    if ($part === 'utm') {
        csv_download("utm-campaigns-{$from}-to-{$to}.csv", ['Source', 'Medium', 'Campaign', 'Visitors', 'Page views', 'Sign-ups', 'Enquiries', 'Paid orders', 'Revenue'],
            array_map(fn($x) => [$x['s'], $x['m'], $x['c'], $x['visitors'], $x['views'], $x['signups'], $x['enquiries'], $x['sales'], number_format((float) $x['revenue'], 2, '.', '')], $campaigns()));
    }
    if ($part === 'pages') {
        csv_download("top-pages-{$from}-to-{$to}.csv", ['Page', 'Page views', 'Visitors'],
            db_all('SELECT path, COUNT(*) v, COUNT(DISTINCT day, visitor_hash) u FROM page_views WHERE day BETWEEN ? AND ? GROUP BY path ORDER BY v DESC LIMIT 1000', $days));
    }
    csv_download("traffic-{$from}-to-{$to}.csv", ['Date', 'Visitors', 'Page views', 'Mobile', 'Tablet', 'Desktop'],
        db_all("SELECT day, COUNT(DISTINCT visitor_hash), COUNT(*), SUM(device = 'mobile'), SUM(device = 'tablet'), SUM(device = 'desktop') FROM page_views WHERE day BETWEEN ? AND ? GROUP BY day ORDER BY day", $days));
}

admin_start('Website Traffic', null, 'reports', ['head' => true, 'subtitle' => 'First-party, cookie-free visits recorded by this website - no IP addresses are stored.']);
echo report_filter_bar($r, [], url_with(['export' => 'csv', 'part' => null]));
if (setting('analytics_enabled', '1') !== '1') {
    echo '<div class="alert alert-warning">' . icon('alert') . ' <span>Page-view recording is switched off' . (can('settings.manage') ? ' in <a href="settings.php#general">Settings → General</a>' : '') . ', so no new visits are being counted.</span></div>';
}

$tot = db_one('SELECT COUNT(*) views, COUNT(DISTINCT day, visitor_hash) visitors, SUM(device = \'mobile\') mobile FROM page_views WHERE day BETWEEN ? AND ?', $days);
$prev = (int) db_value('SELECT COUNT(DISTINCT day, visitor_hash) FROM page_views WHERE day BETWEEN ? AND ?', [$r['prev_from'], $r['prev_to']]);
$prevViews = (int) db_value('SELECT COUNT(*) FROM page_views WHERE day BETWEEN ? AND ?', [$r['prev_from'], $r['prev_to']]);
$first = db_value('SELECT MIN(day) FROM page_views');
echo report_kpis([
    ['label' => 'Visitors', 'icon' => 'users', 'tone' => 'blue', 'value' => $fmtN($tot['visitors']), 'meta' => report_change((float) $tot['visitors'], (float) $prev) . ' daily unique'],
    ['label' => 'Page views', 'icon' => 'eye', 'tone' => 'purple', 'value' => $fmtN($tot['views']), 'meta' => report_change((float) $tot['views'], (float) $prevViews) . ' vs previous period'],
    ['label' => 'Pages per visitor', 'icon' => 'layers', 'tone' => 'teal', 'value' => (int) $tot['visitors'] ? number_format($tot['views'] / $tot['visitors'], 1) : '0', 'meta' => 'average per daily visit'],
    ['label' => 'Mobile share', 'icon' => 'phone', 'tone' => 'orange', 'value' => report_pct((float) $tot['mobile'], (float) $tot['views'], 0), 'meta' => $first ? 'recording since ' . e(date('j M Y', strtotime((string) $first))) : 'no visits recorded yet'],
]);

$views = report_fill($b, db_all('SELECT ' . report_bucket_sql('day', $b['unit']) . ' b, COUNT(*) v FROM page_views WHERE day BETWEEN ? AND ? GROUP BY b', $days));
// Daily-unique visitors summed over each bucket
$visitors = report_fill($b, db_all('SELECT ' . report_bucket_sql('day', $b['unit']) . ' b, SUM(u) v FROM (SELECT day, COUNT(DISTINCT visitor_hash) u FROM page_views WHERE day BETWEEN ? AND ? GROUP BY day) x GROUP BY b', $days));
echo '<div class="rp-stack">';
echo report_card('Visitors & page views ' . $b['title'], '<div class="card-pad">' . report_lines($b, [['Page views', '1', $views], ['Visitors', '2', $visitors]], 'Visitors and page views ' . $b['title']) . '</div>', 'activity');

$pages = db_all('SELECT path, COUNT(*) v, COUNT(DISTINCT day, visitor_hash) u FROM page_views WHERE day BETWEEN ? AND ? GROUP BY path ORDER BY v DESC LIMIT 12', $days);
$refs = db_all("SELECT COALESCE(NULLIF(referrer_host, ''), '(direct / none)') r, COUNT(*) v FROM page_views WHERE day BETWEEN ? AND ? GROUP BY r ORDER BY v DESC LIMIT 10", $days);
$dev = db_all('SELECT device, COUNT(*) v FROM page_views WHERE day BETWEEN ? AND ? GROUP BY device ORDER BY v DESC', $days);
$canExport = can('reports.export');
echo '<div class="grid g-2 rp-pair">'
    . report_card('Top pages', report_table([
        'path' => ['Page', '', fn($v) => '<a href="' . e(app_url(ltrim((string) $v, '/'))) . '" target="_blank" rel="noopener">' . e($v) . '</a>'],
        'v' => ['Views', 'r', $fmtN], 'u' => ['Visitors', 'r', $fmtN]], $pages, 'No visits in this period', 'Visits are recorded when the public website is opened.'), 'file',
        $pages && $canExport ? '<a class="btn btn-light btn-sm" href="' . e(url_with(['export' => 'csv', 'part' => 'pages'])) . '">' . icon('download') . ' CSV</a>' : '')
    . '<div class="rp-stack">'
    . report_card('Referring websites', $refs ? '<div class="card-pad">' . report_hbars(array_column($refs, 'v', 'r')) . '</div>' : report_empty('No visits in this period'), 'share')
    . report_card('Devices', $dev ? '<div class="card-pad">' . report_hbars(array_combine(array_map(fn($d) => ucfirst((string) $d), array_column($dev, 'device')), array_column($dev, 'v'))) . '</div>' : report_empty('No visits in this period'), 'monitor')
    . '</div></div>';

// ---------------- UTM campaigns ----------------
$rows = $campaigns();
echo '<div id="campaigns">' . report_card('UTM campaigns', report_table([
        's' => ['Source / medium / campaign', '', fn($v, $x) => '<strong>' . e($v) . '</strong> / ' . e($x['m'] ?: '-') . '<br><span class="muted">' . e($x['c'] ?: '(no campaign)') . '</span>'],
        'visitors' => ['Visitors', 'r', $fmtN], 'views' => ['Page views', 'r', $fmtN], 'signups' => ['Sign-ups', 'r', $fmtN], 'enquiries' => ['Enquiries', 'r', $fmtN], 'sales' => ['Paid orders', 'r', $fmtN],
        'revenue' => ['Revenue', 'r', fn($v) => e(money((float) $v))]], $rows, 'No campaign visits in this period', 'Share links built below (with utm_source) to see each campaign’s visits, sign-ups and sales here.'), 'megaphone',
        ($rows && $canExport ? '<a class="btn btn-light btn-sm" href="' . e(url_with(['export' => 'csv', 'part' => 'utm'])) . '">' . icon('download') . ' CSV</a>' : '')
        . '<a class="btn btn-light btn-sm" href="reports.php?tab=conversion">' . icon('target') . ' Conversion report</a>') . '</div>';
$base = app_url('courses');
?>
<section class="card rp-card" id="utm-builder" aria-labelledby="utm-h">
  <div class="card-head"><h2 id="utm-h"><?= icon('link') ?> Campaign URL builder</h2><span class="muted" style="font-size:12.5px">Tag links you share in ads, WhatsApp groups or emails</span></div>
  <div class="card-pad form">
    <div class="field full"><label for="u-url">Page URL</label><input id="u-url" type="url" value="<?= e($base) ?>"></div>
    <div class="field"><label for="u-s">Source</label><input id="u-s" placeholder="facebook, google, newsletter"></div>
    <div class="field"><label for="u-m">Medium</label><input id="u-m" placeholder="cpc, social, email"></div>
    <div class="field"><label for="u-c">Campaign</label><input id="u-c" placeholder="ssc-cgl-2026"></div>
    <div class="field full"><label for="u-out">Link to share</label><div style="display:flex;gap:8px;flex-wrap:wrap"><input id="u-out" readonly style="flex:1 1 260px;min-width:0"><button type="button" class="btn btn-primary" id="u-copy" data-copy=""><?= icon('copy') ?> Copy</button></div></div>
  </div>
</section>
</div>
<script>
(function () {
  var ids = ['u-url', 'u-s', 'u-m', 'u-c'];
  function build() {
    var v = ids.map(function (i) { return document.getElementById(i).value.trim(); });
    var out = document.getElementById('u-out'), btn = document.getElementById('u-copy');
    try {
      var u = new URL(v[0]);
      if (!/^https?:$/.test(u.protocol)) throw new Error('scheme');
      [['utm_source', v[1]], ['utm_medium', v[2]], ['utm_campaign', v[3]]].forEach(function (p) {
        if (p[1]) u.searchParams.set(p[0], p[1].toLowerCase().replace(/[^\w.\- ]/g, '').replace(/\s+/g, '-'));
      });
      out.value = u.toString(); btn.dataset.copy = u.toString(); btn.disabled = false;
    } catch (e) { out.value = 'Enter a full page URL (https://…)'; btn.disabled = true; }
  }
  ids.forEach(function (i) { document.getElementById(i).addEventListener('input', build); });
  build();
})();
</script>
<?php admin_end();
