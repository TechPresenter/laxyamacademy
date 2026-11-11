<?php
/**
 * Building blocks shared by the report screens (admin/reports.php, admin/traffic.php,
 * admin/payments.php?tab=report):
 *
 *   $r = report_range();                       // from/to from ?range= / ?from&to / last choice / Academic Session
 *   echo report_filter_bar($r, ['tab' => 'growth'], $exportUrl);
 *   $b = report_buckets($r['from'], $r['to']); // day / week / month buckets for charts
 *   $sql = report_bucket_sql('u.created_at', $b['unit']);
 *   echo report_kpis([...]), report_bars($b, $values, 'Title'), report_table($cols, $rows);
 *
 * Charts are server-rendered SVG (same classes and data-tip tooltips as svg_bar_chart()),
 * drawn twice - wide for desktop, narrow for phones - so axis text stays readable.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** The admin's Academic Session (Apr 1 - Mar 31) from admin_session() when available, else the current one. */
function report_session(): array
{
    if (function_exists('admin_session')) {
        $s = admin_session();
        if (!empty($s['from']) && !empty($s['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $s['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $s['to'])) {
            return ['key' => (string) ($s['key'] ?? substr((string) $s['from'], 0, 4)), 'label' => (string) ($s['label'] ?? $s['key'] ?? ''), 'from' => (string) $s['from'], 'to' => (string) $s['to']];
        }
    }
    $y = (int) date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1;
    $key = $y . '-' . substr((string) ($y + 1), 2);
    return ['key' => $key, 'label' => $key, 'from' => $y . '-04-01', 'to' => ($y + 1) . '-03-31'];
}

/** Quick ranges: key => [label, from, to]. Ranges never end in the future. */
function report_presets(): array
{
    $today = date('Y-m-d');
    $s = report_session();
    $cap = fn(string $d) => $d > $today ? $today : $d;
    $prevFrom = date('Y-m-d', strtotime($s['from'] . ' -1 year'));
    $prevTo = date('Y-m-d', strtotime($s['from'] . ' -1 day'));
    $prevKey = substr($prevFrom, 0, 4) . '-' . substr($s['from'], 2, 2);
    return [
        // A session that has not started yet is shown whole (its figures are simply empty).
        'session' => ['Session ' . $s['label'], $s['from'], $s['from'] > $today ? $s['to'] : $cap($s['to'])],
        'prev_session' => ['Session ' . $prevKey, $prevFrom, $cap($prevTo)],
        '7d' => ['7 days', date('Y-m-d', strtotime('-6 days')), $today],
        '30d' => ['30 days', date('Y-m-d', strtotime('-29 days')), $today],
        '90d' => ['90 days', date('Y-m-d', strtotime('-89 days')), $today],
        'month' => ['This month', date('Y-m-01'), $today],
        'last_month' => ['Last month', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
        '12m' => ['12 months', date('Y-m-d', strtotime('-364 days')), $today],
    ];
}

/**
 * Resolve the report date range. Order: ?range=<preset>, ?from=&to= (custom), the last range this admin
 * chose (kept in the PHP session for the same Academic Session), else the Academic Session.
 * @return array{from:string,to:string,preset:?string,label:string,days:int,range:array,prev:array,prev_from:string,prev_to:string,session:array}
 */
function report_range(int $maxDays = 1100): array
{
    $sess = report_session();
    $presets = report_presets();
    $isDate = fn($v) => is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    $stored = $_SESSION['lx_report_range'] ?? null;
    $preset = null;
    $from = $to = null;
    $q = (string) ($_GET['range'] ?? '');
    if (isset($presets[$q])) {
        $preset = $q;
    } elseif ($isDate($_GET['from'] ?? null) && $isDate($_GET['to'] ?? null)) {
        [$from, $to] = [(string) $_GET['from'], (string) $_GET['to']];
    } elseif (is_array($stored) && ($stored['session'] ?? '') === $sess['key']) {
        if (isset($stored['preset'], $presets[$stored['preset']])) {
            $preset = $stored['preset'];
        } elseif ($isDate($stored['from'] ?? null) && $isDate($stored['to'] ?? null)) {
            [$from, $to] = [$stored['from'], $stored['to']];
        }
    }
    if ($preset === null && $from === null) {
        $preset = 'session';
    }
    if ($preset !== null) {
        [, $from, $to] = $presets[$preset];
    }
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > $maxDays) {
        $from = date('Y-m-d', strtotime($to . ' -' . $maxDays . ' days')); // keep queries bounded
    }
    // Remember an explicit choice so the module tab bar (plain links) keeps it while moving between reports.
    if (isset($_GET['range']) || isset($_GET['from'])) {
        $_SESSION['lx_report_range'] = ['session' => $sess['key']] + ($preset !== null ? ['preset' => $preset] : ['from' => $from, 'to' => $to]);
    }
    $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
    $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));
    $span = date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to));
    return [
        'from' => $from, 'to' => $to, 'preset' => $preset, 'days' => $days, 'session' => $sess,
        'label' => ($preset !== null ? $presets[$preset][0] . ' · ' : '') . $span,
        'range' => [$from . ' 00:00:00', $to . ' 23:59:59'],
        'prev' => [$prevFrom . ' 00:00:00', $prevTo . ' 23:59:59'], 'prev_from' => $prevFrom, 'prev_to' => $prevTo,
    ];
}

/**
 * Date-range toolbar: quick ranges (incl. the Academic Session), custom from/to and the CSV button.
 * $keep = query params every link/submit carries (e.g. ['tab' => 'growth']).
 */
function report_filter_bar(array $r, array $keep, ?string $exportUrl = null, string $note = ''): string
{
    $base = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    $h = '<form class="card rp-filter" method="get" action="' . e($base) . '" aria-label="Report date range">';
    foreach ($keep as $k => $v) {
        $h .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
    }
    $h .= '<div class="rp-quick" role="group" aria-label="Quick date ranges">' . icon('calendar');
    foreach (report_presets() as $key => [$label]) {
        $on = $r['preset'] === $key;
        $h .= '<a class="rp-chip' . ($on ? ' on' : '') . ($key === 'session' ? ' rp-session' : '') . '" href="' . e($base . '?' . http_build_query($keep + ['range' => $key])) . '"' . ($on ? ' aria-current="true"' : '') . '>' . e($label) . '</a>';
    }
    $h .= '</div><div class="rp-custom">'
        . '<label class="rp-date"><span>From</span><input class="in" type="date" name="from" value="' . e($r['from']) . '" max="' . e(date('Y-m-d')) . '" required></label>'
        . '<label class="rp-date"><span>To</span><input class="in" type="date" name="to" value="' . e($r['to']) . '" max="' . e(date('Y-m-d')) . '" required></label>'
        . '<button class="btn btn-primary btn-sm">' . icon('filter') . ' Apply</button>'
        . '<span class="rp-span muted">' . e(number_format($r['days'])) . ' day' . ($r['days'] === 1 ? '' : 's') . ($r['preset'] === null ? ' · custom range' : '') . '</span>';
    if ($exportUrl !== null && can('reports.export')) {
        $h .= '<a class="btn btn-light btn-sm rp-export" href="' . e($exportUrl) . '">' . icon('download') . ' Export CSV</a>';
    }
    $h .= '</div>' . ($note !== '' ? '<p class="rp-note muted">' . $note . '</p>' : '') . '</form>';
    return $h;
}

/** Chart buckets for a date range: day (≤ 31 days), week (≤ 120 days) or month. */
function report_buckets(string $from, string $to): array
{
    $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $unit = $days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month');
    $keys = $labels = $tips = [];
    $end = strtotime($to);
    if ($unit === 'day') {
        for ($t = strtotime($from); $t <= $end; $t = strtotime('+1 day', $t)) {
            $keys[] = date('Y-m-d', $t);
            $labels[] = date('j M', $t);
            $tips[] = date('D, j M Y', $t);
        }
    } elseif ($unit === 'week') {
        $t = strtotime($from);
        $t = strtotime('-' . ((int) date('N', $t) - 1) . ' days', $t); // Monday on/before $from
        for (; $t <= $end; $t = strtotime('+7 days', $t)) {
            $keys[] = date('Y-m-d', $t);
            $labels[] = date('j M', $t);
            $tips[] = 'Week of ' . date('j M Y', $t);
        }
    } else {
        $multiYear = substr($from, 0, 4) !== substr($to, 0, 4);
        for ($t = strtotime(date('Y-m-01', strtotime($from))); $t <= $end; $t = strtotime('+1 month', $t)) {
            $keys[] = date('Y-m-01', $t);
            $labels[] = date($multiYear ? 'M y' : 'M', $t);
            $tips[] = date('F Y', $t);
        }
    }
    return ['unit' => $unit, 'keys' => $keys, 'labels' => $labels, 'tips' => $tips, 'days' => $days,
        'title' => ['day' => 'per day', 'week' => 'per week', 'month' => 'per month'][$unit]];
}

/** SQL expression (MySQL 8 + MariaDB) mapping a DATETIME/DATE column to its bucket key (Y-m-d). */
function report_bucket_sql(string $col, string $unit): string
{
    return match ($unit) {
        'day' => "DATE({$col})",
        'week' => "DATE(DATE_SUB({$col}, INTERVAL WEEKDAY({$col}) DAY))",
        default => "DATE_FORMAT({$col}, '%Y-%m-01')",
    };
}

/** Align rows [{b, v}] to the bucket keys (missing buckets = 0). */
function report_fill(array $buckets, array $rows, string $val = 'v', string $key = 'b'): array
{
    $map = [];
    foreach ($rows as $r) {
        $map[(string) $r[$key]] = (float) $r[$val];
    }
    return array_map(fn($k) => $map[$k] ?? 0.0, $buckets['keys']);
}

/** Percentage change chip vs the previous period. */
function report_change(float $now, float $prev): string
{
    if ($now === $prev) {
        return '<span class="chg">→ 0%</span>';
    }
    if ($prev == 0.0) {
        return '<span class="chg up">▲ new</span>';
    }
    $pct = round(($now - $prev) / abs($prev) * 100);
    return '<span class="chg ' . ($pct >= 0 ? 'up' : 'down') . '">' . ($pct >= 0 ? '▲' : '▼') . ' ' . abs($pct) . '%</span>';
}

/**
 * KPI tiles. Each tile: ['label', 'icon', 'tone' (blue|green|purple|orange|pink|teal|red|amber|indigo|navy),
 * 'value' (plain text), 'meta' (HTML, already escaped), 'href' (optional)].
 */
function report_kpis(array $tiles): string
{
    $h = '<section class="db-kpis rp-kpis" aria-label="Key figures">';
    foreach ($tiles as $t) {
        $tag = !empty($t['href']) ? 'a href="' . e($t['href']) . '"' : 'div';
        $h .= '<' . $tag . ' class="card db-kpi"><span class="tile t-' . e($t['tone'] ?? 'blue') . '">' . icon((string) ($t['icon'] ?? 'chart')) . '</span>'
            . '<span class="label">' . e((string) $t['label']) . '</span><span class="value">' . e((string) $t['value']) . '</span>'
            . '<span class="meta">' . ($t['meta'] ?? '') . '</span></' . (!empty($t['href']) ? 'a' : 'div') . '>';
    }
    return $h . '</section>';
}

/** Card wrapper: title (+ optional icon, right-side HTML) and body HTML. */
function report_card(string $title, string $body, string $ico = 'chart', string $side = '', string $class = ''): string
{
    return '<section class="card rp-card' . ($class !== '' ? ' ' . e($class) : '') . '"><div class="card-head"><h2>' . icon($ico) . ' ' . e($title) . '</h2>' . ($side !== '' ? '<div class="rp-side">' . $side . '</div>' : '') . '</div>' . $body . '</section>';
}

/** Two half-width cards side by side; when both only hold an empty state, the first one is shown alone. */
function report_pair(string $left, string $right): string
{
    $hasData = fn(string $h) => str_contains($h, '<svg class="chart"') || str_contains($h, 'rp-hbars') || str_contains($h, '<table');
    if (!$hasData($left) && !$hasData($right)) {
        return $left;
    }
    return '<div class="grid g-2 rp-pair">' . $left . $right . '</div>';
}

function report_empty(string $title, string $text = '', string $ico = 'chart'): string
{
    return '<div class="empty"><span class="empty-ic">' . icon($ico) . '</span><strong>' . e($title) . '</strong>' . ($text !== '' ? '<p>' . $text . '</p>' : '') . '</div>';
}

/**
 * Table. $cols: key => label | [label, 'r' (right-align) or '', formatter fn($value, $row): string HTML].
 * Without a formatter the value is escaped (null => —).
 */
function report_table(array $cols, array $rows, string $emptyTitle = 'No data for this period', string $emptyText = 'Try a longer date range.', array $foot = []): string
{
    if (!$rows) {
        return report_empty($emptyTitle, $emptyText);
    }
    $norm = [];
    foreach ($cols as $k => $c) {
        $c = is_array($c) ? $c : [$c];
        $norm[$k] = [(string) $c[0], (string) ($c[1] ?? ''), $c[2] ?? null];
    }
    $h = '<div class="table-wrap"><table class="t stack rp-table"><thead><tr>';
    foreach ($norm as [$label, $align]) {
        $h .= '<th scope="col"' . ($align === 'r' ? ' class="num"' : '') . '>' . e($label) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $h .= '<tr>';
        foreach ($norm as $k => [$label, $align, $fn]) {
            $v = $row[$k] ?? null;
            $cell = $fn ? $fn($v, $row) : ($v === null || $v === '' ? '<span class="muted">—</span>' : e((string) $v));
            $h .= '<td data-label="' . e($label) . '"' . ($align === 'r' ? ' class="num"' : '') . '>' . $cell . '</td>';
        }
        $h .= '</tr>';
    }
    $h .= '</tbody>';
    if ($foot) {
        $h .= '<tfoot><tr>';
        foreach (array_keys($norm) as $i => $k) {
            $h .= '<td data-label="' . e($norm[$k][0]) . '"' . ($norm[$k][1] === 'r' ? ' class="num"' : '') . '>' . ($foot[$k] ?? ($i === 0 ? '<strong>Total</strong>' : '')) . '</td>';
        }
        $h .= '</tr></tfoot>';
    }
    return $h . '</table></div>';
}

/** Horizontal bar list for categories (top courses, sources…): [label => value]. */
function report_hbars(array $items, bool $rupee = false, string $unit = ''): string
{
    if (!$items) {
        return '';
    }
    $max = max(1.0, ...array_map('floatval', array_values($items)));
    $h = '<ul class="rp-hbars">';
    foreach ($items as $label => $v) {
        $v = (float) $v;
        $w = $v > 0 ? max(1.5, round($v / $max * 100, 1)) : 0;
        $val = $rupee ? money($v) : number_format($v, $v == floor($v) ? 0 : 1) . $unit;
        $h .= '<li data-tip="' . e($label . ': ' . $val) . '"><span class="hb-label">' . e((string) $label) . '</span><span class="hb-track"><i style="width:' . $w . '%"></i></span><span class="hb-val">' . e($val) . '</span></li>';
    }
    return $h . '</ul>';
}

/** Axis label stride so about $max labels show. */
function report_stride(int $n, int $max): int
{
    return max(1, (int) ceil($n / max(1, $max)));
}

/** One SVG bar chart (internal). */
function report_svg_bars(array $labels, array $tips, array $values, string $title, bool $rupee, int $W, int $H, int $every): string
{
    [$L, $B, $T] = [56, 30, 14];
    $max = nice_max(max([0, ...$values]));
    $n = max(1, count($values));
    $slot = ($W - $L - 8) / $n;
    $bw = max(2, min(30, $slot * 0.68));
    $plotH = $H - $B - $T;
    $svg = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($title) . '">';
    for ($i = 0; $i <= 4; $i++) {
        $y = $T + $plotH - $plotH * $i / 4;
        $svg .= '<line class="grid-line" x1="' . $L . '" x2="' . $W . '" y1="' . $y . '" y2="' . $y . '"/>';
        $svg .= '<text class="axis-text" x="' . ($L - 8) . '" y="' . ($y + 4) . '" text-anchor="end">' . e(short_num($max * $i / 4, $rupee)) . '</text>';
    }
    foreach (array_values($values) as $i => $value) {
        $x = $L + $slot * $i + ($slot - $bw) / 2;
        $h = $max > 0 ? $plotH * $value / $max : 0;
        $y = $T + $plotH - $h;
        $tip = ($tips[$i] ?? $labels[$i] ?? '') . ': ' . ($rupee ? money($value) : number_format($value, $value == floor($value) ? 0 : 1));
        $svg .= '<rect class="hit" data-tip="' . e($tip) . '" x="' . round($L + $slot * $i, 1) . '" y="' . $T . '" width="' . round($slot, 1) . '" height="' . $plotH . '"/>';
        if ($h > 0.5) {
            $r = min(4, $h, $bw / 2);
            $svg .= '<path class="bar" data-tip="' . e($tip) . '" d="M' . round($x, 1) . ',' . round($T + $plotH, 1) . 'V' . round($y + $r, 1) . 'Q' . round($x, 1) . ',' . round($y, 1) . ' ' . round($x + $r, 1) . ',' . round($y, 1) . 'H' . round($x + $bw - $r, 1) . 'Q' . round($x + $bw, 1) . ',' . round($y, 1) . ' ' . round($x + $bw, 1) . ',' . round($y + $r, 1) . 'V' . round($T + $plotH, 1) . 'Z"/>';
        }
        if ($i % $every === 0) {
            $svg .= '<text class="axis-text" x="' . round($x + $bw / 2, 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . e((string) ($labels[$i] ?? '')) . '</text>';
        }
    }
    return $svg . '</svg>';
}

/** One SVG line chart (internal). $series = [[name, '1'|'2', values], …] */
function report_svg_lines(array $labels, array $tips, array $series, string $title, int $W, int $H, int $every): string
{
    [$L, $B, $T, $R] = [56, 30, 14, 34];
    $all = array_merge(...array_map(fn($s) => array_values($s[2]), $series));
    $max = nice_max(max([0, ...$all]));
    $n = max(2, count($labels));
    $plotW = $W - $L - $R;
    $plotH = $H - $B - $T;
    $px = fn($i) => count($labels) === 1 ? $L + $plotW / 2 : $L + $plotW * $i / ($n - 1);
    $py = fn($v) => $T + $plotH - ($max > 0 ? $plotH * $v / $max : 0);
    $svg = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($title) . '">';
    for ($i = 0; $i <= 4; $i++) {
        $y = $T + $plotH - $plotH * $i / 4;
        $svg .= '<line class="grid-line" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $y . '" y2="' . $y . '"/>';
        $svg .= '<text class="axis-text" x="' . ($L - 8) . '" y="' . ($y + 4) . '" text-anchor="end">' . e(short_num($max * $i / 4)) . '</text>';
    }
    foreach ($labels as $i => $label) {
        if ($i % $every === 0) {
            $svg .= '<text class="axis-text" x="' . round($px($i), 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . e((string) $label) . '</text>';
        }
    }
    $dots = count($labels) <= 40;
    foreach ($series as [$name, $cls, $values]) {
        $values = array_values($values);
        $d = '';
        foreach ($values as $i => $v) {
            $d .= ($i ? 'L' : 'M') . round($px($i), 1) . ',' . round($py($v), 1);
        }
        $svg .= '<path class="series s' . e($cls) . '" d="' . $d . '"/>';
        foreach ($values as $i => $v) {
            if ($dots) {
                $svg .= '<circle class="pt p' . e($cls) . '" cx="' . round($px($i), 1) . '" cy="' . round($py($v), 1) . '" r="3.5"/>';
            }
            $svg .= '<circle class="hit" data-tip="' . e(($tips[$i] ?? $labels[$i] ?? '') . ' · ' . $name . ': ' . number_format($v)) . '" cx="' . round($px($i), 1) . '" cy="' . round($py($v), 1) . '" r="10"/>';
        }
    }
    return $svg . '</svg>';
}

/** Time-series bar chart over report_buckets() (wide + narrow drawing, table fallback). */
function report_bars(array $buckets, array $values, string $title, bool $rupee = false): string
{
    if (!array_filter(array_map('floatval', $values))) {
        return report_empty('Nothing recorded in this period', 'Choose a longer range or another Academic Session.');
    }
    $n = count($values);
    $wide = report_svg_bars($buckets['labels'], $buckets['tips'], $values, $title, $rupee, 920, 270, report_stride($n, 16));
    $narrow = report_svg_bars($buckets['labels'], $buckets['tips'], $values, $title, $rupee, 420, 250, report_stride($n, 6));
    return '<div class="rp-chart"><div class="rp-wide">' . $wide . '</div><div class="rp-narrow">' . $narrow . '</div></div>'
        . chart_table($title, $buckets['tips'], [[$title, array_values($values)]], $rupee);
}

/** Time-series line chart with up to two series. $series = [[name, '1'|'2', values], …] */
function report_lines(array $buckets, array $series, string $title): string
{
    if (!array_filter(array_merge(...array_map(fn($s) => array_map('floatval', array_values($s[2])), $series)))) {
        return report_empty('Nothing recorded in this period', 'Choose a longer range or another Academic Session.');
    }
    $n = count($buckets['labels']);
    $legend = '<div class="legend rp-legend">';
    foreach ($series as [$name, $cls]) {
        $legend .= '<span><i class="lg-' . e($cls) . '"></i>' . e($name) . '</span>';
    }
    $legend .= '</div>';
    return $legend . '<div class="rp-chart"><div class="rp-wide">' . report_svg_lines($buckets['labels'], $buckets['tips'], $series, $title, 920, 270, report_stride($n, 16)) . '</div>'
        . '<div class="rp-narrow">' . report_svg_lines($buckets['labels'], $buckets['tips'], $series, $title, 420, 250, report_stride($n, 6)) . '</div></div>'
        . chart_table($title, $buckets['tips'], array_map(fn($s) => [$s[0], array_values($s[2])], $series), false);
}

/** Categorical vertical bars (few short labels, e.g. score bands). */
function report_category_bars(array $points, string $title, bool $rupee = false): string
{
    $labels = array_map('strval', array_keys($points));
    $values = array_map('floatval', array_values($points));
    return '<div class="rp-chart"><div class="rp-wide">' . report_svg_bars($labels, $labels, $values, $title, $rupee, 920, 260, 1) . '</div>'
        . '<div class="rp-narrow">' . report_svg_bars($labels, $labels, $values, $title, $rupee, 420, 240, 1) . '</div></div>'
        . chart_table($title, $labels, [[$title, $values]], $rupee);
}

/** "12.5%" or "—" when the denominator is zero. */
function report_pct(float $part, float $whole, int $dec = 1): string
{
    return $whole > 0 ? number_format($part / $whole * 100, $dec) . '%' : '—';
}
