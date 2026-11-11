<?php
// Reports & analytics: one screen per report (module tab bar → reports.php?tab=…). Every report has the
// shared date-range filter (quick ranges incl. the Academic Session), KPI tiles, a chart, a table and a
// CSV export (reports.export). All figures come from live records.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/admin-reports.php';

require_staff_page();
require_permission('reports.view');

/** Report screens: key => [title, icon, short description] */
const REPORT_TABS = [
    'growth' => ['Student Growth', 'users', 'New registrations, verification and how many became paying learners.'],
    'courses' => ['Course Performance', 'book', 'Enrolments, revenue, progress and completions per course.'],
    'enrolments' => ['Enrolment Reports', 'cap', 'Every enrolment in the period: paid, free and granted by staff.'],
    'revenue' => ['Revenue Reports', 'rupee', 'Verified payments, refunds, discounts and revenue per course.'],
    'tests' => ['Mock Test Analytics', 'clipboard', 'Attempts, scores, pass rates and score distribution per test.'],
    'progress' => ['Student Progress', 'trending', 'How far students have got through their courses.'],
    'attendance' => ['Live Class Attendance', 'live', 'Reminders and joins for live classes in the period.'],
    'downloads' => ['Resource Downloads', 'download', 'Free resource downloads by resource and category.'],
    'conversion' => ['Conversion Reports', 'target', 'Visitors to sign-ups, enquiries and paid orders, by traffic source.'],
    'enquiries' => ['Enquiry Reports', 'inbox', 'Leads by source and status, conversion and follow-ups.'],
    'staff' => ['Staff Activity', 'shield', 'What staff did in the admin panel (from the audit log).'],
    'export' => ['Export Reports', 'download', 'Download any report or raw records as CSV.'],
];
/** Reports that also need one of these permissions (money figures, leads): key => [permission, …] (any one). */
const REPORT_TAB_NEEDS = ['revenue' => ['payments.view', 'orders.view'], 'enquiries' => ['enquiries.view']];
// Old links: reports.php?tab=students
if (($_GET['tab'] ?? '') === 'students') {
    $_GET['tab'] = 'growth';
}
$canMoney = can('payments.view') || can('orders.view'); // revenue amounts are shown only to finance staff
$reportTabs = array_filter(REPORT_TABS, fn($k) => !isset(REPORT_TAB_NEEDS[$k]) || array_filter(REPORT_TAB_NEEDS[$k], 'can'), ARRAY_FILTER_USE_KEY);
$asked = (string) ($_GET['tab'] ?? '');
if (isset(REPORT_TABS[$asked]) && !isset($reportTabs[$asked])) {
    require_permission(REPORT_TAB_NEEDS[$asked][0]); // styled "no access" screen (and an audit entry)
}
$tab = current_tab($reportTabs);
$r = report_range();
$b = report_buckets($r['from'], $r['to']);
[$from, $to, $range, $prev] = [$r['from'], $r['to'], $r['range'], $r['prev']];
$export = ($_GET['export'] ?? '') === 'csv';
$exportUrl = url_with(['export' => 'csv', 'page' => null, 'part' => null]);
$paid = "o.status IN ('paid','partially_refunded','refunded') AND o.total > 0";
$fmtN = fn($v) => number_format((float) $v);
$fmtMoney = fn($v) => e(money((float) $v));
$fmtPct = fn($v) => $v === null ? '<span class="muted">—</span>' : e(number_format((float) $v, 1)) . '%';
$bucket = fn(string $col) => report_bucket_sql($col, $b['unit']);
$csvName = fn(string $what) => "report-{$what}-{$from}-to-{$to}.csv";
$page = max(1, (int) ($_GET['page'] ?? 1));

$kpis = [];
$sections = [];      // HTML blocks rendered in order under the KPIs
$csv = null;         // [headers, rows] for ?export=csv
$filterNote = '';

switch ($tab) {
    // ------------------------------------------------------------------ Student growth
    case 'growth':
        $where = "user_type = 'student' AND status <> 'deleted'";
        $now = db_one("SELECT COUNT(*) n, COALESCE(SUM(email_verified_at IS NOT NULL), 0) v FROM users WHERE {$where} AND created_at BETWEEN ? AND ?", $range);
        $before = (int) db_value("SELECT COUNT(*) FROM users WHERE {$where} AND created_at BETWEEN ? AND ?", $prev);
        $payers = (int) db_value("SELECT COUNT(DISTINCT o.user_id) FROM orders o WHERE {$paid} AND o.paid_at BETWEEN ? AND ?", $range);
        $payersPrev = (int) db_value("SELECT COUNT(DISTINCT o.user_id) FROM orders o WHERE {$paid} AND o.paid_at BETWEEN ? AND ?", $prev);
        $active = (int) db_value('SELECT COUNT(*) FROM (SELECT user_id FROM lesson_progress WHERE updated_at BETWEEN ? AND ? UNION SELECT user_id FROM exam_attempts WHERE started_at BETWEEN ? AND ?) x', [...$range, ...$range]);
        $total = (int) db_value("SELECT COUNT(*) FROM users WHERE {$where} AND created_at <= ?", [$range[1]]);
        $kpis = [
            ['label' => 'New students', 'icon' => 'users', 'tone' => 'purple', 'value' => $fmtN($now['n']), 'meta' => report_change((float) $now['n'], (float) $before) . ' vs previous ' . $r['days'] . ' days'],
            ['label' => 'Email verified', 'icon' => 'check-circle', 'tone' => 'green', 'value' => $fmtN($now['v']), 'meta' => e(report_pct((float) $now['v'], (float) $now['n'], 0)) . ' of new students'],
            ['label' => 'Paying students', 'icon' => 'award', 'tone' => 'orange', 'value' => $fmtN($payers), 'meta' => report_change((float) $payers, (float) $payersPrev) . ' bought a course'],
            ['label' => 'Active learners', 'icon' => 'activity', 'tone' => 'blue', 'value' => $fmtN($active), 'meta' => 'watched a lesson or took a test'],
        ];
        $reg = report_fill($b, db_all("SELECT {$bucket('created_at')} b, COUNT(*) v FROM users WHERE {$where} AND created_at BETWEEN ? AND ? GROUP BY b", $range));
        $ver = report_fill($b, db_all("SELECT {$bucket('created_at')} b, SUM(email_verified_at IS NOT NULL) v FROM users WHERE {$where} AND created_at BETWEEN ? AND ? GROUP BY b", $range));
        $pay = report_fill($b, db_all("SELECT {$bucket('o.paid_at')} b, COUNT(DISTINCT o.user_id) v FROM orders o WHERE {$paid} AND o.paid_at BETWEEN ? AND ? GROUP BY b", $range));
        $sections[] = report_card('Registrations ' . $b['title'], '<div class="card-pad">' . report_lines($b, [['New students', '1', $reg], ['Email verified', '2', $ver]], 'Student registrations ' . $b['title']) . '</div>', 'trending', '<span class="muted">' . $fmtN($total) . ' students in total</span>');
        $rows = [];
        $running = $total - (int) $now['n'];
        foreach ($b['keys'] as $i => $k) {
            $running += (int) $reg[$i];
            $rows[] = ['period' => $b['tips'][$i], 'new' => (int) $reg[$i], 'verified' => (int) $ver[$i], 'paying' => (int) $pay[$i], 'total' => $running];
        }
        $rows = array_reverse($rows);
        $cols = ['period' => 'Period', 'new' => ['New students', 'r', $fmtN], 'verified' => ['Email verified', 'r', $fmtN], 'paying' => ['Paying students', 'r', $fmtN], 'total' => ['Total at end', 'r', $fmtN]];
        // Daily tables list only days with activity; weekly / monthly tables show every period.
        $shown = $b['unit'] === 'day' ? array_values(array_filter($rows, fn($x) => $x['new'] || $x['paying'])) : ((int) $now['n'] || $payers ? $rows : []);
        $sections[] = report_card('Growth by period', report_table($cols, $shown, 'No new students in this period', 'Registrations appear here as students sign up.'), 'list');
        $csv = [['Period', 'New students', 'Email verified', 'Paying students', 'Total students at end'], array_map('array_values', $rows)];
        break;

    // ------------------------------------------------------------------ Course performance
    case 'courses':
        $rows = db_all("SELECT c.id, c.title, c.status, c.rating_avg, c.rating_count,
                (SELECT COUNT(*) FROM course_enrolments e WHERE e.course_id = c.id AND e.enrolled_at BETWEEN ? AND ?) AS new_enrol,
                (SELECT COUNT(*) FROM course_enrolments e WHERE e.course_id = c.id AND e.status = 'active') AS active,
                (SELECT ROUND(AVG(e.progress_percent), 1) FROM course_enrolments e WHERE e.course_id = c.id AND e.status <> 'revoked') AS avg_progress,
                (SELECT COUNT(*) FROM course_enrolments e WHERE e.course_id = c.id AND e.completed_at BETWEEN ? AND ?) AS completed,
                (SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.course_id = c.id AND {$paid} AND o.paid_at BETWEEN ? AND ?) AS sales,
                (SELECT COALESCE(SUM(oi.total), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.course_id = c.id AND {$paid} AND o.paid_at BETWEEN ? AND ?) AS revenue
            FROM courses c WHERE c.status = 'published' OR EXISTS (SELECT 1 FROM course_enrolments e WHERE e.course_id = c.id)
            ORDER BY new_enrol DESC, revenue DESC, c.title", [...$range, ...$range, ...$range, ...$range]);
        $newEnrol = array_sum(array_column($rows, 'new_enrol'));
        $prevEnrol = (int) db_value('SELECT COUNT(*) FROM course_enrolments WHERE enrolled_at BETWEEN ? AND ?', $prev);
        $revenue = array_sum(array_map('floatval', array_column($rows, 'revenue')));
        $activeRows = array_filter($rows, fn($x) => (int) $x['active'] > 0);
        $kpis = [
            ['label' => 'Published courses', 'icon' => 'book', 'tone' => 'indigo', 'value' => $fmtN(db_value("SELECT COUNT(*) FROM courses WHERE status = 'published'")), 'meta' => $fmtN(count($activeRows)) . ' with active learners', 'href' => can('courses.view') ? 'courses.php' : null],
            ['label' => 'New enrolments', 'icon' => 'cap', 'tone' => 'green', 'value' => $fmtN($newEnrol), 'meta' => report_change((float) $newEnrol, (float) $prevEnrol) . ' vs previous period'],
            $canMoney
                ? ['label' => 'Course revenue', 'icon' => 'rupee', 'tone' => 'orange', 'value' => money($revenue), 'meta' => $fmtN(array_sum(array_column($rows, 'sales'))) . ' paid course sales']
                : ['label' => 'Paid course sales', 'icon' => 'cart', 'tone' => 'orange', 'value' => $fmtN(array_sum(array_column($rows, 'sales'))), 'meta' => 'courses bought in the period'],
            ['label' => 'Completions', 'icon' => 'award', 'tone' => 'purple', 'value' => $fmtN(array_sum(array_column($rows, 'completed'))), 'meta' => 'courses finished in the period'],
        ];
        $top = array_slice(array_filter($rows, fn($x) => (int) $x['new_enrol'] > 0), 0, 8);
        $topRev = array_filter($rows, fn($x) => (float) $x['revenue'] > 0);
        usort($topRev, fn($a, $z) => (float) $z['revenue'] <=> (float) $a['revenue']);
        $topDone = array_slice(array_filter($rows, fn($x) => (int) $x['completed'] > 0), 0, 8);
        $sections[] = report_pair(
            report_card('New enrolments by course', $top ? '<div class="card-pad">' . report_hbars(array_column($top, 'new_enrol', 'title')) . '</div>' : report_empty('No enrolments in this period'), 'cap'),
            $canMoney
                ? report_card('Revenue by course', $topRev ? '<div class="card-pad">' . report_hbars(array_column(array_slice($topRev, 0, 8), 'revenue', 'title'), true) . '</div>' : report_empty('No paid sales in this period'), 'rupee')
                : report_card('Completions by course', $topDone ? '<div class="card-pad">' . report_hbars(array_column($topDone, 'completed', 'title')) . '</div>' : report_empty('No courses completed in this period'), 'award')
            );
        $cols = [
            'title' => ['Course', '', fn($v, $x) => (can('courses.view') ? '<a href="courses.php?action=edit&amp;id=' . (int) $x['id'] . '"><strong>' . e($v) . '</strong></a>' : '<strong>' . e($v) . '</strong>') . ($x['status'] !== 'published' ? ' ' . badge($x['status']) : '')],
            'new_enrol' => ['New enrolments', 'r', $fmtN], 'active' => ['Active learners', 'r', $fmtN], 'avg_progress' => ['Avg progress', 'r', $fmtPct],
            'completed' => ['Completed', 'r', $fmtN], 'sales' => ['Sales', 'r', $fmtN], 'revenue' => ['Revenue', 'r', $fmtMoney],
        ];
        if (!$canMoney) {
            unset($cols['revenue']);
        }
        $cols += [
            'rating_avg' => ['Rating', 'r', fn($v, $x) => (int) $x['rating_count'] ? e(number_format((float) $v, 1)) . ' ★ <span class="muted">(' . (int) $x['rating_count'] . ')</span>' : '<span class="muted">—</span>'],
        ];
        $sections[] = report_card('All courses', report_table($cols, $rows, 'No courses yet'), 'list', '<span class="muted">' . $fmtN(count($rows)) . ' courses</span>');
        $csv = [['Course', 'Status', 'New enrolments', 'Active learners', 'Average progress %', 'Completed', 'Paid sales', 'Revenue', 'Rating', 'Ratings'],
            array_map(fn($x) => [$x['title'], $x['status'], $x['new_enrol'], $x['active'], $x['avg_progress'], $x['completed'], $x['sales'], number_format((float) $x['revenue'], 2, '.', ''), $x['rating_avg'], $x['rating_count']], $rows)];
        if (!$canMoney) { // drop the Revenue column
            $csv = [array_values(array_diff_key($csv[0], [7 => 1])), array_map(fn($line) => array_values(array_diff_key($line, [7 => 1])), $csv[1])];
        }
        break;

    // ------------------------------------------------------------------ Enrolments
    case 'enrolments':
        $split = array_column(db_all('SELECT source, COUNT(*) n FROM course_enrolments WHERE enrolled_at BETWEEN ? AND ? GROUP BY source', $range), 'n', 'source');
        $totalNew = array_sum($split);
        $prevNew = (int) db_value('SELECT COUNT(*) FROM course_enrolments WHERE enrolled_at BETWEEN ? AND ?', $prev);
        $expiring = (int) db_value("SELECT COUNT(*) FROM course_enrolments WHERE status = 'active' AND expires_at BETWEEN NOW() AND NOW() + INTERVAL 30 DAY");
        $kpis = [
            ['label' => 'New enrolments', 'icon' => 'cap', 'tone' => 'green', 'value' => $fmtN($totalNew), 'meta' => report_change((float) $totalNew, (float) $prevNew) . ' vs previous period'],
            ['label' => 'Paid', 'icon' => 'rupee', 'tone' => 'orange', 'value' => $fmtN($split['purchase'] ?? 0), 'meta' => e(report_pct((float) ($split['purchase'] ?? 0), (float) $totalNew, 0)) . ' of enrolments'],
            ['label' => 'Free', 'icon' => 'sparkles', 'tone' => 'teal', 'value' => $fmtN($split['free'] ?? 0), 'meta' => $fmtN($split['admin'] ?? 0) . ' granted by staff'],
            ['label' => 'Expiring in 30 days', 'icon' => 'clock', 'tone' => 'amber', 'value' => $fmtN($expiring), 'meta' => 'active access ending soon'],
        ];
        $paidS = report_fill($b, db_all("SELECT {$bucket('enrolled_at')} b, COUNT(*) v FROM course_enrolments WHERE source = 'purchase' AND enrolled_at BETWEEN ? AND ? GROUP BY b", $range));
        $freeS = report_fill($b, db_all("SELECT {$bucket('enrolled_at')} b, COUNT(*) v FROM course_enrolments WHERE source <> 'purchase' AND enrolled_at BETWEEN ? AND ? GROUP BY b", $range));
        $sections[] = report_card('Enrolments ' . $b['title'], '<div class="card-pad">' . report_lines($b, [['Paid', '1', $paidS], ['Free / granted', '2', $freeS]], 'Enrolments ' . $b['title']) . '</div>', 'trending');
        $sql = 'SELECT e.id, e.source, e.status, e.progress_percent, e.enrolled_at, e.expires_at, e.completed_at, u.id AS user_id, u.name, u.email, c.title AS course, o.order_number
                FROM course_enrolments e JOIN users u ON u.id = e.user_id JOIN courses c ON c.id = e.course_id LEFT JOIN orders o ON o.id = e.order_id
                WHERE e.enrolled_at BETWEEN ? AND ? ORDER BY e.enrolled_at DESC';
        if ($export) {
            $all = db_all($sql . ' LIMIT 20000', $range);
            $csv = [['Reference', 'Student', 'Email', 'Course', 'Source', 'Status', 'Progress %', 'Enrolled at', 'Expires at', 'Completed at', 'Order'],
                array_map(fn($x) => [sprintf('ENR-%06d', $x['id']), $x['name'], $x['email'], $x['course'], $x['source'], $x['status'], $x['progress_percent'], $x['enrolled_at'], $x['expires_at'], $x['completed_at'], $x['order_number']], $all)];
            break;
        }
        $list = db_all($sql . ' LIMIT 25 OFFSET ' . (($page - 1) * 25), $range);
        $cols = [
            'name' => ['Student', '', fn($v, $x) => (can('users.view') ? '<a href="users.php?id=' . (int) $x['user_id'] . '"><strong>' . e($v) . '</strong></a>' : '<strong>' . e($v) . '</strong>') . '<br><span class="muted">' . e($x['email']) . '</span>'],
            'course' => ['Course', '', fn($v, $x) => e($v) . '<br><span class="muted">' . e(sprintf('ENR-%06d', $x['id'])) . '</span>'],
            'source' => ['Source', '', fn($v, $x) => e(['purchase' => 'Paid', 'free' => 'Free', 'admin' => 'Granted by staff'][$v] ?? $v) . ($x['order_number'] && can('orders.view') ? '<br><a class="muted" href="orders.php?order=' . e(urlencode($x['order_number'])) . '">' . e($x['order_number']) . '</a>' : '')],
            'status' => ['Status', '', fn($v) => badge($v)],
            'progress_percent' => ['Progress', 'r', $fmtPct],
            'enrolled_at' => ['Enrolled', '', fn($v) => e(fmt_dt($v, 'j M Y'))],
            'expires_at' => ['Access until', '', fn($v) => $v ? e(fmt_dt($v, 'j M Y')) : '<span class="muted">Lifetime</span>'],
        ];
        $sections[] = report_card('Enrolments in the period', report_table($cols, $list, 'No enrolments in this period', 'Enrolments appear when students buy or join a course.') . ($totalNew > 25 ? (function () use ($totalNew, $page) { ob_start(); render_pager($totalNew, $page, 25); return (string) ob_get_clean(); })() : ''), 'list', '<span class="muted">' . $fmtN($totalNew) . ' enrolment' . ($totalNew === 1 ? '' : 's') . '</span>');
        break;

    // ------------------------------------------------------------------ Revenue
    case 'revenue':
        $sum = fn(array $rg) => db_one("SELECT COUNT(*) n, COALESCE(SUM(o.total), 0) s, COALESCE(SUM(o.discount), 0) d, COALESCE(SUM(o.tax), 0) t FROM orders o WHERE {$paid} AND o.paid_at BETWEEN ? AND ?", $rg);
        [$cur, $old] = [$sum($range), $sum($prev)];
        $refunds = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE status = 'processed' AND updated_at BETWEEN ? AND ?", $range);
        $kpis = [
            ['label' => 'Collected', 'icon' => 'rupee', 'tone' => 'orange', 'value' => money($cur['s']), 'meta' => report_change((float) $cur['s'], (float) $old['s']) . ' vs previous period', 'href' => can('payments.view') ? 'payments.php?tab=report' : null],
            ['label' => 'Net after refunds', 'icon' => 'trending', 'tone' => 'green', 'value' => money((float) $cur['s'] - $refunds), 'meta' => $refunds > 0 ? e(money($refunds)) . ' refunded' : 'no refunds processed'],
            ['label' => 'Paid orders', 'icon' => 'cart', 'tone' => 'blue', 'value' => $fmtN($cur['n']), 'meta' => 'average ' . e(money((int) $cur['n'] ? (float) $cur['s'] / (int) $cur['n'] : 0))],
            ['label' => 'Coupon discounts', 'icon' => 'tag', 'tone' => 'pink', 'value' => money($cur['d']), 'meta' => (float) $cur['t'] > 0 ? 'GST collected ' . e(money($cur['t'])) : 'given at checkout'],
        ];
        $gross = report_fill($b, db_all("SELECT {$bucket('o.paid_at')} b, SUM(o.total) v FROM orders o WHERE {$paid} AND o.paid_at BETWEEN ? AND ? GROUP BY b", $range));
        $cnt = report_fill($b, db_all("SELECT {$bucket('o.paid_at')} b, COUNT(*) v FROM orders o WHERE {$paid} AND o.paid_at BETWEEN ? AND ? GROUP BY b", $range));
        $disc = report_fill($b, db_all("SELECT {$bucket('o.paid_at')} b, SUM(o.discount) v FROM orders o WHERE {$paid} AND o.paid_at BETWEEN ? AND ? GROUP BY b", $range));
        $ref = report_fill($b, db_all("SELECT {$bucket('updated_at')} b, SUM(amount) v FROM refunds WHERE status = 'processed' AND updated_at BETWEEN ? AND ? GROUP BY b", $range));
        $sections[] = report_card('Revenue ' . $b['title'], '<div class="card-pad">' . report_bars($b, $gross, 'Verified revenue ' . $b['title'], true) . '</div>', 'chart',
            can('payments.view') ? '<a class="btn btn-light btn-sm" href="payments.php?tab=report">' . icon('receipt') . ' Payment & GST report</a>' : '');
        $byCourse = db_all("SELECT oi.course_id, oi.title, COUNT(*) n, SUM(oi.discount) d, SUM(oi.total) s FROM orders o JOIN order_items oi ON oi.order_id = o.id
                            WHERE {$paid} AND o.paid_at BETWEEN ? AND ? GROUP BY oi.course_id, oi.title ORDER BY s DESC", $range);
        $courseTotal = array_sum(array_map('floatval', array_column($byCourse, 's')));
        if ($export && ($_GET['part'] ?? '') === 'courses') {
            $csv = [['Course', 'Paid orders', 'Discounts', 'Revenue', 'Share %'], array_map(fn($x) => [$x['title'], $x['n'], number_format((float) $x['d'], 2, '.', ''), number_format((float) $x['s'], 2, '.', ''), $courseTotal > 0 ? round((float) $x['s'] / $courseTotal * 100, 1) : 0], $byCourse)];
            $csvFile = $csvName('revenue-by-course');
            break;
        }
        if (!$byCourse && !(int) $cur['n'] && $refunds <= 0) {
            $csv = [['Period', 'Paid orders', 'Collected', 'Discounts', 'Refunded', 'Net'], []];
            break; // nothing else to show: the chart card already says so
        }
        $sections[] = report_card('Revenue by course', report_table([
                'title' => ['Course', '', fn($v) => '<strong>' . e($v) . '</strong>'], 'n' => ['Paid orders', 'r', $fmtN], 'd' => ['Discounts', 'r', $fmtMoney], 's' => ['Revenue', 'r', $fmtMoney],
                'share' => ['Share', 'r', fn($v, $x) => '<span class="rp-meter"><i style="width:' . e((string) ($courseTotal > 0 ? round((float) $x['s'] / $courseTotal * 100, 1) : 0)) . '%"></i></span> ' . e(report_pct((float) $x['s'], $courseTotal, 0))]],
            $byCourse, 'No paid course sales in this period', 'Refunds processed in the period are listed below.'), 'book',
            $byCourse && can('reports.export') ? '<a class="btn btn-light btn-sm" href="' . e(url_with(['export' => 'csv', 'part' => 'courses', 'page' => null])) . '">' . icon('download') . ' CSV</a>' : '');
        $rows = [];
        foreach ($b['keys'] as $i => $k) {
            if ($cnt[$i] || $ref[$i]) {
                $rows[] = ['period' => $b['tips'][$i], 'orders' => (int) $cnt[$i], 'gross' => $gross[$i], 'discount' => $disc[$i], 'refunds' => $ref[$i], 'net' => $gross[$i] - $ref[$i]];
            }
        }
        $rows = array_reverse($rows);
        $sections[] = report_card('Revenue by period', report_table(['period' => 'Period', 'orders' => ['Paid orders', 'r', $fmtN], 'gross' => ['Collected', 'r', $fmtMoney], 'discount' => ['Discounts', 'r', $fmtMoney], 'refunds' => ['Refunded', 'r', $fmtMoney], 'net' => ['Net', 'r', $fmtMoney]], $rows,
            'No payments in this period', 'Paid orders appear here once the payment gateway confirms them.', $rows ? ['orders' => '<strong>' . $fmtN($cur['n']) . '</strong>', 'gross' => '<strong>' . $fmtMoney($cur['s']) . '</strong>', 'discount' => '<strong>' . $fmtMoney($cur['d']) . '</strong>', 'refunds' => '<strong>' . $fmtMoney($refunds) . '</strong>', 'net' => '<strong>' . $fmtMoney((float) $cur['s'] - $refunds) . '</strong>'] : []), 'list');
        $csv = [['Period', 'Paid orders', 'Collected', 'Discounts', 'Refunded', 'Net'], array_map(fn($x) => [$x['period'], $x['orders'], number_format($x['gross'], 2, '.', ''), number_format($x['discount'], 2, '.', ''), number_format($x['refunds'], 2, '.', ''), number_format($x['net'], 2, '.', '')], $rows)];
        break;

    // ------------------------------------------------------------------ Mock tests
    case 'tests':
        $done = "a.status <> 'in_progress' AND a.submitted_at BETWEEN ? AND ?";
        $rows = db_all("SELECT e.id AS exam_id, e.title, e.mode, e.total_marks, e.pass_marks, COUNT(a.id) AS attempts, COUNT(DISTINCT a.user_id) AS students,
                ROUND(AVG(a.score), 2) AS avg_score, MAX(a.score) AS top_score,
                ROUND(AVG(CASE WHEN e.total_marks > 0 THEN GREATEST(a.score, 0) / e.total_marks * 100 END), 1) AS avg_pct,
                ROUND(100 * SUM(e.pass_marks IS NOT NULL AND a.score >= e.pass_marks) / NULLIF(SUM(e.pass_marks IS NOT NULL), 0), 1) AS pass_rate,
                ROUND(AVG(TIMESTAMPDIFF(SECOND, a.started_at, a.submitted_at)) / 60, 1) AS avg_minutes,
                SUM(a.status = 'auto_submitted') AS timed_out
            FROM exam_attempts a JOIN exams e ON e.id = a.exam_id WHERE {$done} GROUP BY e.id ORDER BY attempts DESC", $range);
        $att = array_sum(array_column($rows, 'attempts'));
        $prevAtt = (int) db_value("SELECT COUNT(*) FROM exam_attempts a WHERE {$done}", $prev);
        $agg = db_one("SELECT COUNT(DISTINCT a.user_id) s, ROUND(AVG(CASE WHEN e.total_marks > 0 THEN GREATEST(a.score, 0) / e.total_marks * 100 END), 1) p,
                ROUND(100 * SUM(e.pass_marks IS NOT NULL AND a.score >= e.pass_marks) / NULLIF(SUM(e.pass_marks IS NOT NULL), 0), 1) pr
            FROM exam_attempts a JOIN exams e ON e.id = a.exam_id WHERE {$done}", $range);
        $kpis = [
            ['label' => 'Attempts', 'icon' => 'clipboard', 'tone' => 'pink', 'value' => $fmtN($att), 'meta' => report_change((float) $att, (float) $prevAtt) . ' vs previous period', 'href' => can('exams.manage') ? 'attempts.php' : null],
            ['label' => 'Students attempting', 'icon' => 'users', 'tone' => 'blue', 'value' => $fmtN($agg['s'] ?? 0), 'meta' => $fmtN(db_value("SELECT COUNT(*) FROM exams WHERE status = 'published'")) . ' tests published'],
            ['label' => 'Average score', 'icon' => 'target', 'tone' => 'purple', 'value' => $agg['p'] !== null ? number_format((float) $agg['p'], 1) . '%' : '—', 'meta' => 'of total marks'],
            ['label' => 'Pass rate', 'icon' => 'award', 'tone' => 'green', 'value' => $agg['pr'] !== null ? number_format((float) $agg['pr'], 1) . '%' : '—', 'meta' => $fmtN(array_sum(array_column($rows, 'timed_out'))) . ' submitted at time-out'],
        ];
        // Score distribution for one test (looked up by id - never by title)
        $examId = (int) ($_GET['exam'] ?? 0);
        $exam = $examId ? db_one('SELECT id, title, total_marks, pass_marks FROM exams WHERE id = ?', [$examId]) : null;
        if ($exam) {
            $bands = ['0–20%' => 0.0, '20–40%' => 0.0, '40–60%' => 0.0, '60–80%' => 0.0, '80–100%' => 0.0];
            $scores = db_all("SELECT a.score FROM exam_attempts a WHERE a.exam_id = ? AND {$done}", [$examId, ...$range]);
            foreach ($scores as $s) {
                $pct = (float) $exam['total_marks'] > 0 ? max(0, (float) $s['score']) / (float) $exam['total_marks'] * 100 : 0;
                $bands[array_keys($bands)[min(4, (int) floor($pct / 20))]]++;
            }
            $sections[] = report_card('Score distribution · ' . $exam['title'], $scores ? '<div class="card-pad">' . report_category_bars($bands, 'Score distribution for ' . $exam['title']) . '</div>' : report_empty('No submitted attempts for this test in the period'), 'chart',
                '<span class="muted">' . $fmtN(count($scores)) . ' attempts</span> <a class="btn btn-light btn-sm" href="' . e(url_with(['exam' => null])) . '">' . icon('close') . ' Close</a>');
        } else {
            $series = report_fill($b, db_all("SELECT {$bucket('a.submitted_at')} b, COUNT(*) v FROM exam_attempts a WHERE {$done} GROUP BY b", $range));
            $sections[] = report_card('Attempts ' . $b['title'], '<div class="card-pad">' . report_bars($b, $series, 'Test attempts ' . $b['title']) . '</div>', 'chart', '<span class="muted">Choose “Distribution” on a test to see its score spread</span>');
        }
        $cols = [
            'title' => ['Test', '', fn($v, $x) => '<strong>' . e($v) . '</strong> ' . ($x['mode'] === 'practice' ? '<span class="badge b-blue">Practice</span>' : '')],
            'attempts' => ['Attempts', 'r', $fmtN], 'students' => ['Students', 'r', $fmtN],
            'avg_score' => ['Avg score', 'r', fn($v, $x) => e(rtrim(rtrim(number_format((float) $v, 2), '0'), '.')) . ' <span class="muted">/ ' . e(rtrim(rtrim(number_format((float) $x['total_marks'], 2), '0'), '.')) . '</span>'],
            'avg_pct' => ['Avg %', 'r', $fmtPct], 'pass_rate' => ['Pass rate', 'r', $fmtPct], 'avg_minutes' => ['Avg time', 'r', fn($v) => $v !== null ? e(number_format((float) $v, 1)) . ' min' : '—'],
            'exam_id' => ['', '', fn($v) => '<span class="rp-acts"><a class="btn btn-light btn-sm" href="' . e(url_with(['exam' => (int) $v, 'page' => null])) . '"' . ($examId === (int) $v ? ' aria-current="true"' : '') . '>' . icon('chart') . ' Distribution</a>'
                . (can('exams.manage') ? '<a class="btn btn-ghost btn-sm" href="attempts.php?exam=' . (int) $v . '">' . icon('list') . ' Attempts</a>' : '') . '</span>'],
        ];
        $sections[] = report_card('Tests attempted in the period', report_table($cols, $rows, 'No test attempts in this period', 'Submitted attempts appear here.'), 'list');
        $csv = [['Test', 'Mode', 'Attempts', 'Students', 'Average score', 'Total marks', 'Highest score', 'Average %', 'Pass rate %', 'Average minutes', 'Submitted at time-out'],
            array_map(fn($x) => [$x['title'], $x['mode'], $x['attempts'], $x['students'], $x['avg_score'], $x['total_marks'], $x['top_score'], $x['avg_pct'], $x['pass_rate'], $x['avg_minutes'], $x['timed_out']], $rows)];
        break;

    // ------------------------------------------------------------------ Student progress
    case 'progress':
        $act = db_one("SELECT COUNT(*) n, ROUND(AVG(progress_percent), 1) p, SUM(progress_percent = 0) z FROM course_enrolments WHERE status = 'active'");
        $completed = (int) db_value('SELECT COUNT(*) FROM course_enrolments WHERE completed_at BETWEEN ? AND ?', $range);
        $lessons = (int) db_value("SELECT COUNT(*) FROM lesson_progress WHERE status = 'completed' AND completed_at BETWEEN ? AND ?", $range);
        $lessonsPrev = (int) db_value("SELECT COUNT(*) FROM lesson_progress WHERE status = 'completed' AND completed_at BETWEEN ? AND ?", $prev);
        $kpis = [
            ['label' => 'Active enrolments', 'icon' => 'cap', 'tone' => 'blue', 'value' => $fmtN($act['n']), 'meta' => $fmtN($act['z']) . ' not started yet'],
            ['label' => 'Average progress', 'icon' => 'gauge', 'tone' => 'purple', 'value' => $act['p'] !== null ? number_format((float) $act['p'], 1) . '%' : '—', 'meta' => 'across active enrolments'],
            ['label' => 'Lessons completed', 'icon' => 'check-circle', 'tone' => 'green', 'value' => $fmtN($lessons), 'meta' => report_change((float) $lessons, (float) $lessonsPrev) . ' in the period'],
            ['label' => 'Courses completed', 'icon' => 'award', 'tone' => 'orange', 'value' => $fmtN($completed), 'meta' => 'reached 100% in the period'],
        ];
        $bands = ['0%' => 0.0, '1–25%' => 0.0, '26–50%' => 0.0, '51–75%' => 0.0, '76–99%' => 0.0, '100%' => 0.0];
        foreach (db_all("SELECT CASE WHEN progress_percent <= 0 THEN 0 WHEN progress_percent <= 25 THEN 1 WHEN progress_percent <= 50 THEN 2 WHEN progress_percent <= 75 THEN 3 WHEN progress_percent < 100 THEN 4 ELSE 5 END k, COUNT(*) n FROM course_enrolments WHERE status = 'active' GROUP BY k") as $x) {
            $bands[array_keys($bands)[(int) $x['k']]] = (float) $x['n'];
        }
        $lessonSeries = report_fill($b, db_all("SELECT {$bucket('completed_at')} b, COUNT(*) v FROM lesson_progress WHERE status = 'completed' AND completed_at BETWEEN ? AND ? GROUP BY b", $range));
        $sections[] = report_pair(
            report_card('Where active students are', (int) $act['n'] ? '<div class="card-pad">' . report_category_bars($bands, 'Active enrolments by progress') . '</div>' : report_empty('No active enrolments yet'), 'gauge', '<span class="muted">today</span>'),
            report_card('Lessons completed ' . $b['title'], '<div class="card-pad">' . report_bars($b, $lessonSeries, 'Lessons completed ' . $b['title']) . '</div>', 'chart')
            );
        $rows = db_all("SELECT c.id, c.title, COUNT(e.id) AS enrolled, SUM(e.enrolled_at BETWEEN ? AND ?) AS new_in_period, ROUND(AVG(e.progress_percent), 1) AS avg_progress,
                SUM(e.progress_percent = 0) AS not_started, SUM(e.completed_at IS NOT NULL) AS completed,
                (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.course_id = c.id AND lp.status = 'completed' AND lp.completed_at BETWEEN ? AND ?) AS lessons_done
            FROM courses c JOIN course_enrolments e ON e.course_id = c.id AND e.status <> 'revoked' GROUP BY c.id ORDER BY enrolled DESC", [...$range, ...$range]);
        $cols = ['title' => ['Course', '', fn($v) => '<strong>' . e($v) . '</strong>'], 'enrolled' => ['Enrolled', 'r', $fmtN], 'new_in_period' => ['New in period', 'r', $fmtN],
            'avg_progress' => ['Avg progress', 'r', fn($v) => '<span class="rp-meter" title="' . e(number_format((float) $v, 1)) . '%"><i style="width:' . e((string) min(100, max(0, (float) $v))) . '%"></i></span> ' . e(number_format((float) $v, 1)) . '%'],
            'not_started' => ['Not started', 'r', $fmtN], 'completed' => ['Completed', 'r', $fmtN], 'lessons_done' => ['Lessons done (period)', 'r', $fmtN]];
        $sections[] = report_card('Progress by course', report_table($cols, $rows, 'No enrolments yet', 'Progress appears once students start their courses.'), 'list');
        $csv = [['Course', 'Enrolled', 'New in period', 'Average progress %', 'Not started', 'Completed', 'Lessons completed in period'], array_map(fn($x) => [$x['title'], $x['enrolled'], $x['new_in_period'], $x['avg_progress'], $x['not_started'], $x['completed'], $x['lessons_done']], $rows)];
        break;

    // ------------------------------------------------------------------ Live class attendance
    case 'attendance':
        $rows = db_all("SELECT lc.id, lc.title, lc.starts_at, lc.status, lc.meeting_provider, lc.recording_url, c.title AS course,
                DATE_ADD(lc.starts_at, INTERVAL lc.duration_minutes MINUTE) <= NOW() AS ended,
                (SELECT COUNT(*) FROM live_class_reminders r WHERE r.live_class_id = lc.id) AS reminders,
                (SELECT COUNT(*) FROM live_class_attendance a WHERE a.live_class_id = lc.id) AS joined
            FROM live_classes lc LEFT JOIN courses c ON c.id = lc.course_id WHERE lc.starts_at BETWEEN ? AND ? ORDER BY lc.starts_at DESC", $range);
        $joins = array_sum(array_column($rows, 'joined'));
        $unique = (int) db_value('SELECT COUNT(DISTINCT a.user_id) FROM live_class_attendance a JOIN live_classes lc ON lc.id = a.live_class_id WHERE lc.starts_at BETWEEN ? AND ?', $range);
        $held = count(array_filter($rows, fn($x) => $x['status'] !== 'cancelled'));
        $kpis = [
            ['label' => 'Classes', 'icon' => 'live', 'tone' => 'red', 'value' => $fmtN($held), 'meta' => $fmtN(count($rows) - $held) . ' cancelled', 'href' => can('live.manage') ? 'live-classes.php' : null],
            ['label' => 'Total joins', 'icon' => 'users', 'tone' => 'green', 'value' => $fmtN($joins), 'meta' => $held ? number_format($joins / $held, 1) . ' per class' : 'no classes'],
            ['label' => 'Unique students', 'icon' => 'user', 'tone' => 'blue', 'value' => $fmtN($unique), 'meta' => 'joined at least one class'],
            ['label' => 'Reminders set', 'icon' => 'bell', 'tone' => 'amber', 'value' => $fmtN(array_sum(array_column($rows, 'reminders'))), 'meta' => $fmtN(count(array_filter($rows, fn($x) => $x['recording_url']))) . ' recordings added'],
        ];
        $top = array_slice(array_filter($rows, fn($x) => (int) $x['joined'] > 0), 0, 10);
        $series = report_fill($b, db_all("SELECT {$bucket('lc.starts_at')} b, COUNT(a.user_id) v FROM live_classes lc JOIN live_class_attendance a ON a.live_class_id = lc.id WHERE lc.starts_at BETWEEN ? AND ? GROUP BY b", $range));
        $sections[] = report_pair(
            report_card('Joins ' . $b['title'], '<div class="card-pad">' . report_bars($b, $series, 'Live class joins ' . $b['title']) . '</div>', 'chart'),
            report_card('Best attended classes', $top ? '<div class="card-pad">' . report_hbars(array_combine(array_map(fn($x) => $x['title'] . ' · ' . date('j M', strtotime($x['starts_at'])), $top), array_column($top, 'joined'))) . '</div>' : report_empty('No joins recorded in this period', 'Joins are counted when a student opens the class link from the website.'), 'trophy')
            );
        $prov = ['zoom' => 'Zoom', 'google_meet' => 'Google Meet', 'youtube' => 'YouTube Live', 'other' => 'Other'];
        $cols = [
            'title' => ['Class', '', fn($v, $x) => (can('live.manage') ? '<a href="live-classes.php?' . ((int) $x['ended'] ? 'when=past&amp;' : '') . 'action=edit&amp;id=' . (int) $x['id'] . '"><strong>' . e($v) . '</strong></a>' : '<strong>' . e($v) . '</strong>') . '<br><span class="muted">' . e($x['course'] ?? 'No course') . '</span>'],
            'starts_at' => ['Starts', '', fn($v) => e(fmt_dt($v))], 'status' => ['Status', '', fn($v) => badge($v)],
            'meeting_provider' => ['Platform', '', fn($v) => $v ? e($prov[$v] ?? $v) : '<span class="muted">—</span>'],
            'reminders' => ['Reminders', 'r', $fmtN], 'joined' => ['Joined', 'r', $fmtN],
            'rate' => ['Show-up', 'r', fn($v, $x) => (int) $x['reminders'] ? e(report_pct((float) $x['joined'], (float) $x['reminders'], 0)) : '<span class="muted">—</span>'],
        ];
        $sections[] = report_card('Classes in the period', report_table($cols, $rows, 'No live classes in this period', 'Scheduled classes appear here with their reminders and joins.'), 'list');
        $csv = [['Class', 'Course', 'Starts at', 'Status', 'Platform', 'Reminders', 'Joined', 'Recording'], array_map(fn($x) => [$x['title'], $x['course'], $x['starts_at'], $x['status'], $x['meeting_provider'], $x['reminders'], $x['joined'], $x['recording_url'] ? 'yes' : 'no'], $rows)];
        break;

    // ------------------------------------------------------------------ Resource downloads
    case 'downloads':
        $tot = db_one('SELECT COUNT(*) n, COUNT(DISTINCT user_id) u, SUM(user_id IS NULL) g, COUNT(DISTINCT resource_id) r FROM resource_downloads WHERE downloaded_at BETWEEN ? AND ?', $range);
        $prevN = (int) db_value('SELECT COUNT(*) FROM resource_downloads WHERE downloaded_at BETWEEN ? AND ?', $prev);
        $kpis = [
            ['label' => 'Downloads', 'icon' => 'download', 'tone' => 'blue', 'value' => $fmtN($tot['n']), 'meta' => report_change((float) $tot['n'], (float) $prevN) . ' vs previous period'],
            ['label' => 'Logged-in students', 'icon' => 'users', 'tone' => 'green', 'value' => $fmtN($tot['u']), 'meta' => 'distinct students'],
            ['label' => 'Guest downloads', 'icon' => 'globe', 'tone' => 'teal', 'value' => $fmtN($tot['g'] ?? 0), 'meta' => 'without signing in'],
            ['label' => 'Resources downloaded', 'icon' => 'file', 'tone' => 'purple', 'value' => $fmtN($tot['r']), 'meta' => 'of ' . $fmtN(db_value("SELECT COUNT(*) FROM learning_resources WHERE status = 'published'")) . ' published', 'href' => can('resources.manage') ? 'resources.php' : null],
        ];
        $series = report_fill($b, db_all("SELECT {$bucket('downloaded_at')} b, COUNT(*) v FROM resource_downloads WHERE downloaded_at BETWEEN ? AND ? GROUP BY b", $range));
        $byCat = db_all('SELECT rc.name, COUNT(d.id) n FROM resource_downloads d JOIN learning_resources r ON r.id = d.resource_id JOIN resource_categories rc ON rc.id = r.category_id WHERE d.downloaded_at BETWEEN ? AND ? GROUP BY rc.id ORDER BY n DESC', $range);
        $sections[] = report_pair(
            report_card('Downloads ' . $b['title'], '<div class="card-pad">' . report_bars($b, $series, 'Downloads ' . $b['title']) . '</div>', 'chart'),
            report_card('By category', $byCat ? '<div class="card-pad">' . report_hbars(array_column($byCat, 'n', 'name')) . '</div>' : report_empty('No downloads in this period'), 'folder')
            );
        $rows = db_all('SELECT r.id, r.title, r.file_type, r.access_type, r.download_count, rc.name AS category, COUNT(d.id) AS downloads, COUNT(DISTINCT d.user_id) AS students, SUM(d.user_id IS NULL) AS guests
                        FROM resource_downloads d JOIN learning_resources r ON r.id = d.resource_id JOIN resource_categories rc ON rc.id = r.category_id
                        WHERE d.downloaded_at BETWEEN ? AND ? GROUP BY r.id ORDER BY downloads DESC', $range);
        $cols = ['title' => ['Resource', '', fn($v, $x) => '<strong>' . e($v) . '</strong><br><span class="muted">' . e($x['category']) . ($x['file_type'] ? ' · ' . e(strtoupper($x['file_type'])) : '') . '</span>'],
            'access_type' => ['Access', '', fn($v) => badge($v)], 'downloads' => ['Downloads', 'r', $fmtN], 'students' => ['Students', 'r', $fmtN], 'guests' => ['Guests', 'r', $fmtN], 'download_count' => ['All time', 'r', $fmtN]];
        $sections[] = report_card('Resources downloaded in the period', report_table($cols, $rows, 'No downloads in this period', 'Downloads are counted when a visitor opens a free resource file.'), 'list');
        $csv = [['Resource', 'Category', 'Type', 'Access', 'Downloads', 'Logged-in students', 'Guest downloads', 'All-time downloads'], array_map(fn($x) => [$x['title'], $x['category'], $x['file_type'], $x['access_type'], $x['downloads'], $x['students'], $x['guests'], $x['download_count']], $rows)];
        break;

    // ------------------------------------------------------------------ Conversions
    case 'conversion':
        $visitors = (int) db_value('SELECT COUNT(DISTINCT day, visitor_hash) FROM page_views WHERE day BETWEEN ? AND ?', [$from, $to]);
        $signups = (int) db_value("SELECT COUNT(*) FROM users WHERE user_type = 'student' AND created_at BETWEEN ? AND ?", $range);
        $enq = db_one("SELECT COUNT(*) n, SUM(status = 'converted') c FROM enquiries WHERE status <> 'spam' AND created_at BETWEEN ? AND ?", $range);
        $orders = db_one("SELECT COUNT(*) n, COALESCE(SUM(o.total), 0) s FROM orders o WHERE {$paid} AND o.paid_at BETWEEN ? AND ?", $range);
        $kpis = [
            ['label' => 'Visitors', 'icon' => 'globe', 'tone' => 'blue', 'value' => $fmtN($visitors), 'meta' => 'daily unique, cookie-free', 'href' => 'traffic.php'],
            ['label' => 'Sign-ups', 'icon' => 'users', 'tone' => 'purple', 'value' => $fmtN($signups), 'meta' => e(report_pct((float) $signups, (float) $visitors)) . ' of visitors'],
            ['label' => 'Enquiries', 'icon' => 'inbox', 'tone' => 'teal', 'value' => $fmtN($enq['n']), 'meta' => e(report_pct((float) ($enq['c'] ?? 0), (float) $enq['n'], 0)) . ' converted to students'],
            ['label' => 'Paid orders', 'icon' => 'cart', 'tone' => 'orange', 'value' => $fmtN($orders['n']), 'meta' => ($canMoney ? e(money($orders['s'])) . ' · ' : '') . e(report_pct((float) $orders['n'], (float) $visitors)) . ' of visitors'],
        ];
        $funnel = ['Visitors' => $visitors, 'Sign-ups' => $signups, 'Enquiries' => (int) $enq['n'], 'Paid orders' => (int) $orders['n']];
        $conv = db_all("SELECT COALESCE(NULLIF(a.utm_source, ''), NULLIF(a.referrer_host, ''), '(direct)') AS k,
                SUM(a.entity_type = 'user') AS signups, SUM(a.entity_type = 'enquiry') AS enquiries,
                SUM(a.entity_type = 'order' AND o.status IN ('paid','partially_refunded')) AS sales,
                COALESCE(SUM(CASE WHEN a.entity_type = 'order' AND o.status IN ('paid','partially_refunded') THEN o.total END), 0) AS revenue
            FROM attributions a LEFT JOIN orders o ON a.entity_type = 'order' AND o.id = a.entity_id
            WHERE a.created_at BETWEEN ? AND ? GROUP BY k", $range);
        $conv = array_column($conv, null, 'k');
        $visits = array_column(db_all("SELECT COALESCE(NULLIF(utm_source, ''), NULLIF(referrer_host, ''), '(direct)') k, COUNT(DISTINCT day, visitor_hash) u FROM page_views WHERE day BETWEEN ? AND ? GROUP BY k", [$from, $to]), 'u', 'k');
        $rows = [];
        foreach (array_unique([...array_keys($visits), ...array_keys($conv)]) as $k) {
            $c = $conv[$k] ?? ['signups' => 0, 'enquiries' => 0, 'sales' => 0, 'revenue' => 0];
            $rows[] = ['source' => (string) $k, 'visitors' => (int) ($visits[$k] ?? 0), 'signups' => (int) $c['signups'], 'enquiries' => (int) $c['enquiries'], 'sales' => (int) $c['sales'], 'revenue' => (float) $c['revenue']];
        }
        usort($rows, fn($a, $z) => [$z['revenue'], $z['sales'], $z['signups'], $z['visitors']] <=> [$a['revenue'], $a['sales'], $a['signups'], $a['visitors']]);
        $rows = array_slice($rows, 0, 60);
        $srcConv = [];
        foreach ($rows as $x) {
            if ($x['signups'] + $x['enquiries'] + $x['sales'] > 0) {
                $srcConv[$x['source']] = $x['signups'] + $x['enquiries'] + $x['sales'];
            }
        }
        arsort($srcConv);
        $sections[] = report_pair(
            report_card('Funnel', $visitors || $signups || $enq['n'] || $orders['n'] ? '<div class="card-pad">' . report_hbars($funnel) . '<p class="muted rp-small">Each step counts records created in the period (not only the same visitors).</p></div>' : report_empty('No activity in this period'), 'target'),
            report_card('Conversions by source', $srcConv ? '<div class="card-pad">' . report_hbars(array_slice($srcConv, 0, 8, true)) . '<p class="muted rp-small">Sign-ups + enquiries + paid orders, by the visitor’s first source.</p></div>'
                : report_empty('No attributed conversions yet', 'The source is recorded on sign-up, enquiry and checkout from the visitor’s first UTM tag or referring site (kept 30 days in their browser).'), 'share')
            );
        $cols = ['source' => ['Source', '', fn($v) => '<strong>' . e($v) . '</strong>'], 'visitors' => ['Visitors', 'r', $fmtN], 'signups' => ['Sign-ups', 'r', $fmtN], 'enquiries' => ['Enquiries', 'r', $fmtN], 'sales' => ['Paid orders', 'r', $fmtN],
            'revenue' => ['Revenue', 'r', $fmtMoney], 'rate' => ['Sign-up rate', 'r', fn($v, $x) => $x['visitors'] ? e(report_pct((float) $x['signups'], (float) $x['visitors'])) : '<span class="muted">—</span>']];
        if (!$canMoney) {
            unset($cols['revenue']);
        }
        $sections[] = report_card('Conversions by traffic source', report_table($cols, $rows, 'No traffic recorded in this period', 'Page views are recorded by the website (first-party, no cookies).'), 'list',
            '<a class="btn btn-light btn-sm" href="traffic.php#campaigns">' . icon('megaphone') . ' UTM campaigns</a>');
        $csv = $canMoney
            ? [['Source', 'Visitors', 'Sign-ups', 'Enquiries', 'Paid orders', 'Revenue'], array_map(fn($x) => [$x['source'], $x['visitors'], $x['signups'], $x['enquiries'], $x['sales'], number_format($x['revenue'], 2, '.', '')], $rows)]
            : [['Source', 'Visitors', 'Sign-ups', 'Enquiries', 'Paid orders'], array_map(fn($x) => [$x['source'], $x['visitors'], $x['signups'], $x['enquiries'], $x['sales']], $rows)];
        break;

    // ------------------------------------------------------------------ Enquiries
    case 'enquiries':
        $tot = db_one("SELECT COUNT(*) n, SUM(status = 'new') nw, SUM(status = 'converted') c, SUM(status = 'spam') sp FROM enquiries WHERE created_at BETWEEN ? AND ?", $range);
        $prevN = (int) db_value('SELECT COUNT(*) FROM enquiries WHERE created_at BETWEEN ? AND ?', $prev);
        $due = (int) db_value("SELECT COUNT(*) FROM enquiries WHERE status = 'follow_up' AND follow_up_at IS NOT NULL AND follow_up_at <= NOW()");
        $real = (int) $tot['n'] - (int) $tot['sp'];
        $kpis = [
            ['label' => 'Enquiries', 'icon' => 'inbox', 'tone' => 'teal', 'value' => $fmtN($tot['n']), 'meta' => report_change((float) $tot['n'], (float) $prevN) . ' vs previous period', 'href' => can('enquiries.view') ? 'enquiries.php' : null],
            ['label' => 'Still new', 'icon' => 'sparkles', 'tone' => 'amber', 'value' => $fmtN($tot['nw'] ?? 0), 'meta' => 'not contacted yet', 'href' => can('enquiries.view') ? 'enquiries.php?status=new' : null],
            ['label' => 'Converted', 'icon' => 'check-circle', 'tone' => 'green', 'value' => $fmtN($tot['c'] ?? 0), 'meta' => e(report_pct((float) ($tot['c'] ?? 0), (float) $real, 0)) . ' of genuine leads'],
            ['label' => 'Follow-ups due', 'icon' => 'clock', 'tone' => 'red', 'value' => $fmtN($due), 'meta' => $fmtN($tot['sp'] ?? 0) . ' marked spam in period', 'href' => can('enquiries.view') ? 'enquiries.php?status=follow_up' : null],
        ];
        $series = report_fill($b, db_all("SELECT {$bucket('created_at')} b, COUNT(*) v FROM enquiries WHERE status <> 'spam' AND created_at BETWEEN ? AND ? GROUP BY b", $range));
        $rows = db_all("SELECT COALESCE(NULLIF(source, ''), 'other') AS source, COUNT(*) total, SUM(status = 'new') s_new, SUM(status = 'contacted') s_contacted, SUM(status = 'follow_up') s_follow,
                SUM(status = 'converted') s_converted, SUM(status = 'closed') s_closed, SUM(status = 'spam') s_spam
            FROM enquiries WHERE created_at BETWEEN ? AND ? GROUP BY source ORDER BY total DESC", $range);
        $label = fn($s) => ucwords(str_replace('_', ' ', (string) $s));
        $byCourse = db_all("SELECT c.title, COUNT(*) n FROM enquiries q JOIN courses c ON c.id = q.course_id WHERE q.status <> 'spam' AND q.created_at BETWEEN ? AND ? GROUP BY c.id ORDER BY n DESC LIMIT 8", $range);
        $sections[] = report_card('Enquiries ' . $b['title'], '<div class="card-pad">' . report_bars($b, $series, 'Enquiries ' . $b['title']) . '</div>', 'chart', '<span class="muted">spam excluded</span>');
        $sections[] = report_pair(
            report_card('By source', $rows ? '<div class="card-pad">' . report_hbars(array_combine(array_map($label, array_column($rows, 'source')), array_column($rows, 'total'))) . '</div>' : report_empty('No enquiries in this period'), 'share'),
            report_card('By course', $byCourse ? '<div class="card-pad">' . report_hbars(array_column($byCourse, 'n', 'title')) . '</div>' : report_empty('No course-specific enquiries', 'Enquiries sent from a course page are linked to that course.'), 'book')
            );
        $n = fn($v) => (int) $v ? $fmtN($v) : '<span class="muted">0</span>';
        $cols = ['source' => ['Source', '', fn($v) => '<strong>' . e($label($v)) . '</strong>'], 'total' => ['Total', 'r', $fmtN], 's_new' => ['New', 'r', $n], 's_contacted' => ['Contacted', 'r', $n], 's_follow' => ['Follow-up', 'r', $n],
            's_converted' => ['Converted', 'r', $n], 's_closed' => ['Closed', 'r', $n], 's_spam' => ['Spam', 'r', $n], 'rate' => ['Conversion', 'r', fn($v, $x) => e(report_pct((float) $x['s_converted'], (float) $x['total'] - (float) $x['s_spam'], 0))]];
        $sections[] = report_card('Status by source', report_table($cols, $rows, 'No enquiries in this period', 'Website forms, popups and the chatbot all create enquiries.'), 'list');
        $csv = [['Source', 'Total', 'New', 'Contacted', 'Follow-up', 'Converted', 'Closed', 'Spam'], array_map(fn($x) => [$label($x['source']), $x['total'], $x['s_new'], $x['s_contacted'], $x['s_follow'], $x['s_converted'], $x['s_closed'], $x['s_spam']], $rows)];
        break;

    // ------------------------------------------------------------------ Staff activity (audit log)
    case 'staff':
        $staffJoin = "JOIN users u ON u.id = l.user_id AND u.user_type = 'staff'";
        $tot = db_one("SELECT COUNT(*) n, COUNT(DISTINCT l.user_id) s, SUM(l.action = 'admin_login') li, SUM(l.severity <> 'info') w FROM audit_logs l {$staffJoin} WHERE l.created_at BETWEEN ? AND ?", $range);
        $prevN = (int) db_value("SELECT COUNT(*) FROM audit_logs l {$staffJoin} WHERE l.created_at BETWEEN ? AND ?", $prev);
        $failed = (int) db_value("SELECT COUNT(*) FROM audit_logs WHERE action IN ('admin_login_failed', 'admin_2fa_failed') AND created_at BETWEEN ? AND ?", $range);
        $kpis = [
            ['label' => 'Actions recorded', 'icon' => 'log', 'tone' => 'navy', 'value' => $fmtN($tot['n']), 'meta' => report_change((float) $tot['n'], (float) $prevN) . ' vs previous period', 'href' => can('logs.view') ? 'logs.php?tab=audit' : null],
            ['label' => 'Active staff', 'icon' => 'users', 'tone' => 'blue', 'value' => $fmtN($tot['s']), 'meta' => 'of ' . $fmtN(db_value("SELECT COUNT(*) FROM users WHERE user_type = 'staff' AND status = 'active'")) . ' staff accounts'],
            ['label' => 'Admin sign-ins', 'icon' => 'lock', 'tone' => 'green', 'value' => $fmtN($tot['li'] ?? 0), 'meta' => $failed ? '<span class="chg down">' . $fmtN($failed) . ' failed</span>' : 'no failed attempts'],
            ['label' => 'Warnings & critical', 'icon' => 'alert', 'tone' => 'red', 'value' => $fmtN($tot['w'] ?? 0), 'meta' => 'sensitive changes to review'],
        ];
        $series = report_fill($b, db_all("SELECT {$bucket('l.created_at')} b, COUNT(*) v FROM audit_logs l {$staffJoin} WHERE l.created_at BETWEEN ? AND ? GROUP BY b", $range));
        $actions = db_all("SELECT l.action, l.entity_type, COUNT(*) n FROM audit_logs l {$staffJoin} WHERE l.created_at BETWEEN ? AND ? AND l.action NOT IN ('admin_login', 'admin_logout') GROUP BY l.action, l.entity_type ORDER BY n DESC LIMIT 8", $range);
        // Readable labels from includes/admin-header.php ("created" + media_videos → "Video added").
        $human = fn($a, $entity = null) => audit_action_label((string) $a, $entity === null ? null : (string) $entity);
        $item = fn($x) => match (true) {
            !$x['entity_type'] => '',
            $x['entity_type'] === 'permission' => ' <span class="muted">· needs ' . e((string) $x['entity_id']) . '</span>',
            in_array($x['action'], ['created', 'updated', 'deleted'], true) => $x['entity_id'] ? ' <span class="muted">#' . e((string) $x['entity_id']) . '</span>' : '',
            default => ' <span class="muted">· ' . e(audit_entity_label((string) $x['entity_type'], true)) . ($x['entity_id'] ? ' #' . e((string) $x['entity_id']) : '') . '</span>',
        };
        $sections[] = report_pair(
            report_card('Actions ' . $b['title'], '<div class="card-pad">' . report_bars($b, $series, 'Staff actions ' . $b['title']) . '</div>', 'chart'),
            report_card('Most frequent changes', $actions ? '<div class="card-pad">' . report_hbars(array_combine(array_map(fn($x) => $human($x['action'], $x['entity_type']), $actions), array_column($actions, 'n'))) . '</div>' : report_empty('No changes recorded in this period'), 'edit', '<span class="muted">sign-in/out excluded</span>')
            );
        $rows = db_all("SELECT u.id, u.name, u.email, u.last_login_at, u.totp_enabled_at,
                (SELECT GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles,
                COUNT(l.id) AS actions, SUM(l.action = 'admin_login') AS logins, SUM(l.action NOT IN ('admin_login', 'admin_logout')) AS changes,
                SUM(l.severity <> 'info') AS warnings, MAX(l.created_at) AS last_action
            FROM users u LEFT JOIN audit_logs l ON l.user_id = u.id AND l.created_at BETWEEN ? AND ?
            WHERE u.user_type = 'staff' AND u.status <> 'deleted' GROUP BY u.id ORDER BY actions DESC, u.name", $range);
        $cols = [
            'name' => ['Staff member', '', fn($v, $x) => (can('logs.view') ? '<a href="logs.php?tab=audit&amp;q=' . e(urlencode((string) $x['email'])) . '"><strong>' . e($v) . '</strong></a>' : '<strong>' . e($v) . '</strong>') . '<br><span class="muted">' . e($x['roles'] ?: 'No role') . '</span>'],
            'actions' => ['Actions', 'r', $fmtN], 'logins' => ['Sign-ins', 'r', fn($v) => $fmtN($v ?? 0)], 'changes' => ['Changes', 'r', fn($v) => $fmtN($v ?? 0)],
            'warnings' => ['Warnings', 'r', fn($v) => (int) $v ? '<span class="badge b-amber">' . $fmtN($v) . '</span>' : '<span class="muted">0</span>'],
            'last_action' => ['Last activity', '', fn($v) => $v ? e(fmt_ago($v)) : '<span class="muted">None in period</span>'],
            'totp_enabled_at' => ['2FA', '', fn($v) => $v ? '<span class="badge b-green">On</span>' : '<span class="badge b-gray">Off</span>'],
        ];
        $sections[] = report_card('Activity by staff member', report_table($cols, $rows, 'No staff accounts'), 'users');
        if (can('logs.view')) {
            $recent = db_all("SELECT l.action, l.entity_type, l.entity_id, l.severity, l.created_at, u.name FROM audit_logs l {$staffJoin} WHERE l.created_at BETWEEN ? AND ? ORDER BY l.id DESC LIMIT 12", $range);
            $sections[] = report_card('Latest actions', report_table(['created_at' => ['When', '', fn($v) => e(fmt_dt($v, 'j M, g:i A'))], 'name' => 'Staff member', 'action' => ['Action', '', fn($v, $x) => e($human($v, $x['entity_type'])) . $item($x)], 'severity' => ['Severity', '', fn($v) => badge($v)]], $recent, 'No actions in this period'), 'history',
                '<a class="btn btn-light btn-sm" href="logs.php?tab=audit">' . icon('log') . ' Full audit log</a>');
        }
        $csv = [['Staff member', 'Email', 'Roles', 'Actions', 'Sign-ins', 'Changes', 'Warnings', 'Last activity', 'Last login', '2FA'], array_map(fn($x) => [$x['name'], $x['email'], $x['roles'], $x['actions'], (int) $x['logins'], (int) $x['changes'], (int) $x['warnings'], $x['last_action'], $x['last_login_at'], $x['totp_enabled_at'] ? 'on' : 'off'], $rows)];
        break;

    // ------------------------------------------------------------------ Export hub
    case 'export':
        // Raw record exports. Each needs reports.export plus the permission that guards those records.
        $datasets = [
            'students' => ['Students registered', 'users', 'users.view', 'Name, email, phone, verification and status of students who signed up in the period.'],
            'orders' => ['Orders', 'cart', 'orders.view', 'Every order created in the period with status, amounts, coupon and billing details.'],
            'enquiries' => ['Enquiries', 'inbox', 'enquiries.view', 'Leads received in the period with source, course, status and consent.'],
            'attempts' => ['Test attempts', 'clipboard', 'exams.manage', 'Submitted attempts with score, correct / wrong counts and time taken.'],
        ];
        $ds = (string) ($_GET['dataset'] ?? '');
        if ($export && isset($datasets[$ds])) {
            require_permission('reports.export');
            require_permission($datasets[$ds][2]);
            $file = "export-{$ds}-{$from}-to-{$to}.csv";
            match ($ds) {
                'students' => csv_download($file, ['ID', 'Name', 'Email', 'Phone', 'Status', 'Email verified', 'Registered at', 'Last login'],
                    db_all("SELECT id, name, email, phone, status, IF(email_verified_at IS NULL, 'no', 'yes'), created_at, last_login_at FROM users WHERE user_type = 'student' AND status <> 'deleted' AND created_at BETWEEN ? AND ? ORDER BY id LIMIT 50000", $range)),
                'orders' => csv_download($file, ['Order', 'Created at', 'Paid at', 'Status', 'Student', 'Email', 'Courses', 'Subtotal', 'Discount', 'Tax', 'Total', 'Coupon', 'Billing state', 'GSTIN'],
                    db_all("SELECT o.order_number, o.created_at, o.paid_at, o.status, u.name, u.email, (SELECT GROUP_CONCAT(oi.title SEPARATOR '; ') FROM order_items oi WHERE oi.order_id = o.id),
                            o.subtotal, o.discount, o.tax, o.total, cp.code, o.billing_state, o.billing_gstin
                        FROM orders o JOIN users u ON u.id = o.user_id LEFT JOIN coupons cp ON cp.id = o.coupon_id WHERE o.created_at BETWEEN ? AND ? ORDER BY o.id LIMIT 50000", $range)),
                'enquiries' => csv_download($file, ['ID', 'Received at', 'Name', 'Email', 'Phone', 'Source', 'Course', 'Subject', 'Status', 'Consent at', 'Follow-up at'],
                    db_all('SELECT q.id, q.created_at, q.name, q.email, q.phone, q.source, c.title, q.subject, q.status, q.consent_at, q.follow_up_at FROM enquiries q LEFT JOIN courses c ON c.id = q.course_id WHERE q.created_at BETWEEN ? AND ? ORDER BY q.id LIMIT 50000', $range)),
                'attempts' => csv_download($file, ['Attempt', 'Test', 'Student', 'Email', 'Status', 'Score', 'Total marks', 'Correct', 'Wrong', 'Unanswered', 'Started at', 'Submitted at', 'Minutes'],
                    db_all("SELECT a.id, e.title, u.name, u.email, a.status, a.score, e.total_marks, a.correct_count, a.wrong_count, a.unanswered_count, a.started_at, a.submitted_at, ROUND(TIMESTAMPDIFF(SECOND, a.started_at, a.submitted_at) / 60, 1)
                        FROM exam_attempts a JOIN exams e ON e.id = a.exam_id JOIN users u ON u.id = a.user_id WHERE a.status <> 'in_progress' AND a.submitted_at BETWEEN ? AND ? ORDER BY a.id LIMIT 50000", $range)),
            };
        }
        $canExport = can('reports.export');
        $recent = db_all("SELECT l.entity_id AS file, l.created_at, u.name FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.action = 'csv_export' ORDER BY l.id DESC LIMIT 10");
        $kpis = [];
        $cards = '';
        foreach ($reportTabs as $k => [$title, $ico, $desc]) {
            if ($k === 'export') {
                continue;
            }
            $cards .= '<article class="card rp-exp"><span class="tile t-blue">' . icon($ico) . '</span><div class="rp-exp-body"><h3>' . e($title) . '</h3><p class="muted">' . e($desc) . '</p></div><div class="rp-exp-acts">'
                . '<a class="btn btn-ghost btn-sm" href="?tab=' . e($k) . '">' . icon('eye') . ' View</a>'
                . ($canExport ? '<a class="btn btn-light btn-sm" href="?tab=' . e($k) . '&amp;export=csv">' . icon('download') . ' CSV</a>' : '') . '</div></article>';
        }
        if (can('payments.view')) {
            $cards .= '<article class="card rp-exp"><span class="tile t-orange">' . icon('receipt') . '</span><div class="rp-exp-body"><h3>Payment &amp; GST report</h3><p class="muted">Collected, refunded and GST per day for reconciliation with the payment gateway.</p></div><div class="rp-exp-acts">'
                . '<a class="btn btn-ghost btn-sm" href="payments.php?tab=report">' . icon('eye') . ' View</a>' . ($canExport ? '<a class="btn btn-light btn-sm" href="payments.php?tab=report&amp;export=csv">' . icon('download') . ' CSV</a>' : '') . '</div></article>';
        }
        $cards .= '<article class="card rp-exp"><span class="tile t-teal">' . icon('activity') . '</span><div class="rp-exp-body"><h3>Website traffic &amp; UTM campaigns</h3><p class="muted">Visits, top pages, sources and campaign results.</p></div><div class="rp-exp-acts">'
            . '<a class="btn btn-ghost btn-sm" href="traffic.php">' . icon('eye') . ' View</a>' . ($canExport ? '<a class="btn btn-light btn-sm" href="traffic.php?export=csv">' . icon('download') . ' CSV</a>' : '') . '</div></article>';
        $raw = '';
        foreach ($datasets as $k => [$title, $ico, $perm, $desc]) {
            $ok = $canExport && can($perm);
            $raw .= '<article class="card rp-exp"><span class="tile t-purple">' . icon($ico) . '</span><div class="rp-exp-body"><h3>' . e($title) . '</h3><p class="muted">' . e($desc) . '</p></div><div class="rp-exp-acts">'
                . ($ok ? '<a class="btn btn-primary btn-sm" href="?tab=export&amp;dataset=' . e($k) . '&amp;export=csv">' . icon('download') . ' Download CSV</a>' : '<span class="muted rp-small">' . icon('lock') . ' Needs ' . e($canExport ? $perm : 'reports.export') . '</span>') . '</div></article>';
        }
        $filterNote = 'Every export uses the date range above. Files open in Excel (UTF-8) and every download is recorded in the audit log.';
        $sections[] = (!$canExport ? '<div class="alert alert-warning">' . icon('lock') . ' Your role can view reports but not download them (needs the <code>reports.export</code> permission).</div>' : '')
            . '<div class="db-section"><h2>Reports</h2><span class="muted">summary tables as shown on each report</span></div><div class="rp-exp-grid">' . $cards . '</div>'
            . '<div class="db-section"><h2>Raw records</h2><span class="muted">personal data - share carefully</span></div><div class="rp-exp-grid">' . $raw . '</div>';
        $sections[] = '<div class="mt">' . report_card('Recent exports', report_table(['created_at' => ['When', '', fn($v) => e(fmt_dt($v))], 'name' => ['By', '', fn($v) => e((string) ($v ?? 'Unknown'))], 'file' => ['File', '', fn($v) => '<code>' . e((string) $v) . '</code>']], $recent, 'No exports yet', 'Downloads from any report are listed here.'), 'history') . '</div>';
        break;
}

if ($export && $csv !== null) {
    require_permission('reports.export');
    csv_download($csvFile ?? $csvName($tab), $csv[0], $csv[1]);
}

// Several "no data" cards in a row would say the same thing: keep only the first one.
$seenEmpty = false;
$sections = array_values(array_filter($sections, function (string $h) use (&$seenEmpty): bool {
    if (preg_match('/<svg class="chart"|rp-hbars|<table|rp-exp/', $h)) {
        return true;
    }
    return $seenEmpty ? false : ($seenEmpty = true);
}));

[$title, $tabIcon, $desc] = REPORT_TABS[$tab];
admin_start($title, null, 'reports', ['head' => true, 'subtitle' => $desc]);

// The module tab bar lists these screens; this strip is the fallback (admin.js hides it when the bar covers every tab).
$strip = [];
foreach ($reportTabs as $k => [$t, $ico]) {
    $strip[$k] = ['label' => $t, 'icon' => $ico];
    if ($k === 'revenue' && can('payments.view')) {
        $strip['__payments'] = ['label' => 'Payment Reports', 'icon' => 'receipt', 'href' => 'payments.php?tab=report'];
    }
    if ($k === 'downloads') {
        $strip['__traffic'] = ['label' => 'Website Traffic', 'icon' => 'activity', 'href' => 'traffic.php'];
    }
}
echo admin_tabs($strip, $tab, ['label' => 'Reports']);
echo report_filter_bar($r, ['tab' => $tab] + ($tab === 'tests' && !empty($_GET['exam']) ? ['exam' => (int) $_GET['exam']] : []), $tab === 'export' || $csv === null && $tab !== 'enrolments' ? null : $exportUrl, $filterNote);
if ($kpis) {
    echo report_kpis($kpis);
}
echo '<div class="rp-stack">' . implode('', $sections) . '</div>';
admin_end();
