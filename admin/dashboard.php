<?php
/**
 * Admin dashboard - every figure is calculated from live database records.
 * Hero (greeting, rings, today), overview KPIs for the chosen period (top-bar picker),
 * charts, activity, quick actions, system status and recent lists. Each card is shown
 * only to staff who may open the underlying data.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/catalog.php';

const DASH_COLORS = ['#3b82f6', '#22c55e', '#f97316', '#8b5cf6', '#ec4899', '#14b8a6', '#f59e0b', '#6366f1'];

// Default period = the Academic Session chosen in the top bar (1 Apr - 31 Mar); rolling periods stay available.
// Sign-in check first: it starts the PHP session that holds the staff member's session choice.
require_staff_page();
$session = admin_session();
$periods = ['session' => 'Session ' . $session['label'], '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month'];
$period = array_key_exists($_GET['period'] ?? '', $periods) ? (string) $_GET['period'] : 'session';
$months = match ((string) ($_GET['months'] ?? '')) { '6' => 6, '12' => 12, default => 0 }; // 0 = the session's months
$now = time();
if ($period === 'session') {
    $from = strtotime($session['from'] . ' 00:00:00');
    $until = min($now, strtotime($session['to'] . ' 23:59:59'));
    // Compare with the same stretch of the previous session (1 Apr - same day last year).
    [$pf, $pt] = [date('Y-m-d H:i:s', strtotime('-1 year', $from)), date('Y-m-d H:i:s', strtotime('-1 year', $until))];
    $vsText = 'vs last session';
} else {
    $from = match ($period) {
        '7d' => strtotime('-7 days', $now),
        'month' => strtotime(date('Y-m-01')),
        default => strtotime('-30 days', $now),
    };
    $until = $now;
    [$pf, $pt] = [date('Y-m-d H:i:s', $from - max(3600, $now - $from)), date('Y-m-d H:i:s', $from)];
    $vsText = 'vs previous ' . ($period === 'month' ? 'month' : 'period');
}
$len = max(3600, $until - $from);
[$f, $t] = [date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $until)];
$periodLabel = $periods[$period];
$periodText = $period === 'session' ? 'session ' . $session['label'] : mb_strtolower($periodLabel);

// Overview period picker (lives in the hero card so the top bar never overflows on phones)
$picker = '<form class="db-period" method="get"><label for="period">Period</label><select id="period" name="period" data-autosubmit>';
foreach ($periods as $k => $l) {
    $picker .= '<option value="' . $k . '"' . ($k === $period ? ' selected' : '') . '>' . e($l) . '</option>';
}
$picker .= '</select>' . ($months ? '<input type="hidden" name="months" value="' . $months . '">' : '') . '<noscript><button class="btn-xs">Go</button></noscript></form>';
$me = admin_start('Dashboard', 'dashboard.view', 'dashboard');

$n = fn(string $sql, array $p = []) => (int) db_value($sql, $p);
$sum = fn(string $sql, array $p = []) => (float) db_value($sql, $p);
/** [this period, previous period] for SQL with two placeholders (start, end). */
$pair = fn(string $sql) => [(float) db_value($sql, [$f, $t]), (float) db_value($sql, [$pf, $pt])];
$series = fn(string $table, string $col, string $agg, string $where = '1=1') => dash_series($table, $col, $agg, $where, $from, $len);
[$canOrders, $canEnq, $canSupport, $canJobs, $canUsers] = [can('orders.view'), can('enquiries.view'), can('support.manage'), can('cms.manage'), can('users.view')];

// ---------------- Hero ----------------
$todayStart = date('Y-m-d 00:00:00');
$tomorrow = date('Y-m-d 00:00:00', strtotime('tomorrow'));
$collectedToday = $canOrders ? $sum("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status IN ('paid','partially_refunded') AND paid_at >= ?", [$todayStart]) : 0.0;
$paidToday = $canOrders ? $n("SELECT COUNT(*) FROM orders WHERE status IN ('paid','partially_refunded') AND paid_at >= ?", [$todayStart]) : 0;
$newEnq = $canEnq ? $n("SELECT COUNT(*) FROM enquiries WHERE status = 'new'") : 0;
$followDue = $canEnq ? $n("SELECT COUNT(*) FROM enquiries WHERE status = 'follow_up' AND (follow_up_at IS NULL OR follow_up_at < ?)", [$tomorrow]) : 0;
// Same definition as the top-bar bell and the support.php?status=open link: new tickets nobody has picked up yet.
$openTickets = $canSupport ? $n("SELECT COUNT(*) FROM support_tickets WHERE status = 'open'") : 0;
$progress = db_one("SELECT COUNT(*) AS n, AVG(progress_percent) AS p FROM course_enrolments WHERE status = 'active'");
$checkout = db_one("SELECT COALESCE(SUM(status IN ('paid','partially_refunded','refunded')), 0) AS paid, COUNT(*) AS total FROM orders WHERE status <> 'pending' AND created_at BETWEEN ? AND ?", [$f, $t]);
$leads = db_one("SELECT COALESCE(SUM(status = 'converted'), 0) AS conv, COUNT(*) AS total FROM enquiries WHERE status <> 'spam' AND created_at BETWEEN ? AND ?", [$f, $t]);
$classesToday = db_one("SELECT COUNT(*) AS n, MIN(CASE WHEN starts_at >= NOW() THEN starts_at END) AS next FROM live_classes WHERE status <> 'cancelled' AND starts_at >= ? AND starts_at < ?", [$todayStart, $tomorrow]);
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
$maintenance = setting('maintenance_mode', '0') === '1';
$liveMode = config('PAYMENT_MODE', 'sandbox') === 'live' && payments_ready();
$pct = fn($a, $b) => $b > 0 ? round($a / $b * 100) : null;
$rings = [
    ['Course progress', $progress['n'] ? round((float) $progress['p']) : null, '#22c55e', $progress['n'] ? round((float) $progress['p']) . '%' : '—',
        $progress['n'] ? 'average across ' . plural_n((int) $progress['n'], 'active enrolment') : 'No active enrolments yet'],
];
if ($canOrders) {
    $rings[] = ['Payment success', $pct((int) $checkout['paid'], (int) $checkout['total']), '#60a5fa', $checkout['total'] ? (int) $checkout['paid'] . ' of ' . (int) $checkout['total'] : '—',
        $checkout['total'] ? 'checkouts paid · ' . $periodText : 'No checkouts in this period'];
}
if ($canEnq) {
    $rings[] = ['Lead conversion', $pct((int) $leads['conv'], (int) $leads['total']), '#fbbf24', $leads['total'] ? (int) $leads['conv'] . ' of ' . (int) $leads['total'] : '—',
        $leads['total'] ? 'enquiries converted · ' . $periodText : 'No enquiries in this period'];
}
// Today tiles link only to screens this staff member may open (otherwise they are plain tiles).
$attemptsToday = $n('SELECT COUNT(*) FROM exam_attempts WHERE started_at >= ?', [$todayStart]);
$studentsToday = $n("SELECT COUNT(*) FROM users WHERE user_type = 'student' AND created_at >= ?", [$todayStart]);
$today = [
    ['live', 't-blue', $classesToday['n'] ? plural_n((int) $classesToday['n'], 'live class', 'live classes') : 'No classes today',
        $classesToday['next'] ? 'Next at ' . date('g:i A', strtotime($classesToday['next'])) : ($classesToday['n'] ? 'All done for today' : 'Nothing scheduled'), can('live.manage') ? 'live-classes.php' : null],
    ['clipboard', 't-purple', $attemptsToday ? plural_n($attemptsToday, 'test attempt') : 'No test attempts', $attemptsToday ? 'Mock tests started today' : 'None started yet today',
        can('exams.manage') ? 'attempts.php?from=' . date('Y-m-d') : (can('reports.view') ? 'reports.php?tab=tests' : null)],
    ['users', 't-green', $studentsToday ? plural_n($studentsToday, 'new student') : 'No new students', 'Registered today', $canUsers ? 'users.php' : null],
];
if ($canOrders) {
    $today[] = ['rupee', 't-amber', money($collectedToday) . ' collected', $paidToday ? plural_n($paidToday, 'payment') . ' today' : 'No payments yet today', 'orders.php?status=paid'];
}
if ($canEnq) {
    $enqToday = $n('SELECT COUNT(*) FROM enquiries WHERE created_at >= ?', [$todayStart]);
    $today[] = ['mail', 't-teal', $enqToday ? plural_n($enqToday, 'new enquiry', 'new enquiries') : 'No new enquiries', $followDue ? plural_n($followDue, 'follow-up') . ' due' : 'No follow-ups due', 'enquiries.php?status=new'];
}
if ($canSupport) {
    $today[] = ['headset', 't-pink', $openTickets ? plural_n($openTickets, 'open ticket') : 'No open tickets', $openTickets ? 'Waiting for the support team' : 'Support inbox is clear', 'support.php?status=open'];
}

// ---------------- Overview KPIs ----------------
$kpis = [];
if ($canUsers) {
    $reg = $pair("SELECT COUNT(*) FROM users WHERE user_type = 'student' AND created_at BETWEEN ? AND ?");
    $kpis[] = ['Total Students', 'users', 't-purple', '#8b5cf6', number_format($n("SELECT COUNT(*) FROM users WHERE user_type = 'student' AND status <> 'deleted'")), $reg, '+' . number_format($reg[0]) . ' new', $series('users', 'created_at', 'COUNT(*)', "user_type = 'student'"), 'users.php'];
}
$enr = $pair('SELECT COUNT(*) FROM course_enrolments WHERE enrolled_at BETWEEN ? AND ?');
$kpis[] = ['New Enrolments', 'cap', 't-green', '#22c55e', number_format($enr[0]), $enr, number_format($n("SELECT COUNT(*) FROM course_enrolments WHERE status = 'active'")) . ' active', $series('course_enrolments', 'enrolled_at', 'COUNT(*)'), can('enrolments.manage') ? 'enrolments.php' : null];
if ($canOrders) {
    $rev = $pair("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status IN ('paid','partially_refunded') AND paid_at BETWEEN ? AND ?");
    $kpis[] = ['Revenue', 'rupee', 't-orange', '#f97316', money($rev[0]), $rev, 'verified payments', $series('orders', 'paid_at', 'SUM(total)', "status IN ('paid','partially_refunded')"), 'orders.php?status=paid'];
    $payers = $pair("SELECT COUNT(DISTINCT user_id) FROM orders WHERE status IN ('paid','partially_refunded') AND paid_at BETWEEN ? AND ?");
    $kpis[] = ['Paying Students', 'award', 't-blue', '#3b82f6', number_format($payers[0]), $payers, 'bought a course', $series('orders', 'paid_at', 'COUNT(DISTINCT user_id)', "status IN ('paid','partially_refunded')"), 'orders.php?status=paid'];
}
$att = $pair('SELECT COUNT(*) FROM exam_attempts WHERE started_at BETWEEN ? AND ?');
$kpis[] = ['Test Attempts', 'clipboard', 't-pink', '#ec4899', number_format($att[0]), $att, plural_n($n("SELECT COUNT(*) FROM exams WHERE status = 'published'"), 'test') . ' live', $series('exam_attempts', 'started_at', 'COUNT(*)'), can('reports.view') ? 'reports.php?tab=tests' : null];
if ($canEnq) {
    $enq = $pair("SELECT COUNT(*) FROM enquiries WHERE status <> 'spam' AND created_at BETWEEN ? AND ?");
    $kpis[] = ['Open Enquiries', 'mail', 't-teal', '#14b8a6', number_format($n("SELECT COUNT(*) FROM enquiries WHERE status IN ('new','contacted','follow_up')")), $enq, '+' . number_format($enq[0]) . ' new', $series('enquiries', 'created_at', 'COUNT(*)', "status <> 'spam'"), 'enquiries.php'];
}
if (can('courses.view')) {
    $pub = $n("SELECT COUNT(*) FROM courses WHERE status = 'published'");
    $draft = $n("SELECT COUNT(*) FROM courses WHERE status = 'draft'");
    $kpis[] = ['Published Courses', 'book', 't-indigo', '#6366f1', number_format($pub), null, $draft . ' in draft', $pub + $draft ? round($pub / ($pub + $draft) * 100) : 0, 'courses.php'];
}
if ($canJobs) {
    $apps = $pair('SELECT COUNT(*) FROM job_applications WHERE created_at BETWEEN ? AND ?');
    $kpis[] = ['Job Applications', 'briefcase', 't-amber', '#f59e0b', number_format($apps[0]), $apps, $n("SELECT COUNT(*) FROM job_applications WHERE status IN ('shortlisted','interview')") . ' shortlisted', $series('job_applications', 'created_at', 'COUNT(*)'), 'jobs.php?tab=applications'];
}

$mini = [
    ['Staff', 'shield', 't-indigo', $n("SELECT COUNT(*) FROM users WHERE user_type = 'staff' AND status = 'active'"), 'active accounts', can('roles.manage') ? 'roles.php' : null],
    ['Instructors', 'cap', 't-teal', $n("SELECT COUNT(*) FROM instructors WHERE status = 'active'"), 'faculty profiles', can('instructors.manage') ? 'instructors.php' : null],
    ['Upcoming Classes', 'live', 't-red', $n("SELECT COUNT(*) FROM live_classes WHERE status = 'scheduled' AND starts_at >= NOW()"), (int) $classesToday['n'] . ' today', can('live.manage') ? 'live-classes.php?status=scheduled' : null],
    ['Mock Tests', 'clipboard', 't-purple', $n("SELECT COUNT(*) FROM exams WHERE status = 'published'"), $n("SELECT COUNT(*) FROM exams WHERE status = 'draft'") . ' in draft', can('exams.manage') ? 'mock-tests.php' : null],
    ['Free Resources', 'file', 't-green', $n("SELECT COUNT(*) FROM learning_resources WHERE status = 'published'"), 'published', can('resources.manage') ? 'resources.php' : null],
    ['Blog Posts', 'log', 't-blue', $n("SELECT COUNT(*) FROM blog_posts WHERE status = 'published'"), $n("SELECT COUNT(*) FROM blog_posts WHERE status = 'scheduled'") . ' scheduled', can('blog.manage') ? 'blog.php' : null],
    ['Open Positions', 'briefcase', 't-amber', $n("SELECT COUNT(*) FROM job_openings WHERE status = 'open'"), 'on the careers page', $canJobs ? 'jobs.php?tab=openings' : null],
    ['Support Tickets', 'headset', 't-pink', $canSupport ? $openTickets : $n("SELECT COUNT(*) FROM support_tickets WHERE status = 'open'"), 'open', $canSupport ? 'support.php?status=open' : null],
];

// ---------------- Charts ----------------
// Months of the academic session (April onwards, up to this month), or the last 6 / 12 months.
if ($months) {
    $monthKeys = last_months($months);
} else {
    $monthKeys = [];
    $endMonth = date('Y-m', min($now, strtotime($session['to'])));
    for ($m = strtotime($session['from']); date('Y-m', $m) <= $endMonth; $m = strtotime('+1 month', $m)) {
        $monthKeys[date('Y-m', $m)] = date('M', $m);
    }
    if (!$monthKeys) { // upcoming session (admissions open early): it has no months yet
        $monthKeys[date('Y-m', strtotime($session['from']))] = date('M', strtotime($session['from']));
    }
}
$monthsText = $months ? 'last ' . $months . ' months' : 'session ' . $session['label'];
$firstMonth = array_key_first($monthKeys) . '-01';
$byMonth = fn(string $sql) => array_column(db_all($sql, [$firstMonth]), 'v', 'm');
$newStudents = $byMonth("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COUNT(*) AS v FROM users WHERE user_type = 'student' AND created_at >= ? GROUP BY m");
$newEnrol = $byMonth("SELECT DATE_FORMAT(enrolled_at, '%Y-%m') AS m, COUNT(*) AS v FROM course_enrolments WHERE enrolled_at >= ? GROUP BY m");
$revMonth = $canOrders ? $byMonth("SELECT DATE_FORMAT(paid_at, '%Y-%m') AS m, SUM(total) AS v FROM orders WHERE status IN ('paid','partially_refunded') AND paid_at >= ? GROUP BY m") : [];
$trendStudents = array_map(fn($k) => (float) ($newStudents[$k] ?? 0), array_keys($monthKeys));
$trendEnrol = array_map(fn($k) => (float) ($newEnrol[$k] ?? 0), array_keys($monthKeys));
$revenue = [];
foreach ($monthKeys as $k => $label) {
    $revenue[$label] = (float) ($revMonth[$k] ?? 0);
}
// Top-level category (subcategories roll up to their parent). Counted per top-level id in a
// derived table, then the name/colour are joined: valid under MySQL 8's ONLY_FULL_GROUP_BY.
$catEnrol = db_all("SELECT tc.name AS label, tc.color AS color, x.n
    FROM (SELECT COALESCE(p.id, cat.id) AS top_id, COUNT(*) AS n
          FROM course_enrolments e JOIN courses c ON c.id = e.course_id JOIN course_categories cat ON cat.id = c.category_id LEFT JOIN course_categories p ON p.id = cat.parent_id
          WHERE e.status = 'active' GROUP BY COALESCE(p.id, cat.id)) x
    JOIN course_categories tc ON tc.id = x.top_id
    ORDER BY x.n DESC, tc.sort_order, tc.name");

// ---------------- Lists ----------------
$activity = can('logs.view') ? db_all('SELECT a.action, a.entity_type, a.entity_id, a.created_at, u.name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 7') : [];
$until = date('Y-m-d H:i:s', strtotime('+60 days'));
$events = [];
foreach (db_all("SELECT title, starts_at AS at FROM live_classes WHERE status = 'scheduled' AND starts_at BETWEEN NOW() AND ?", [$until]) as $r) {
    $events[] = [$r['at'], $r['title'], 'Live class', 'b-blue', 'live-classes.php?status=scheduled'];
}
foreach (db_all("SELECT title, starts_at AS at FROM exams WHERE status = 'published' AND starts_at BETWEEN NOW() AND ?", [$until]) as $r) {
    $events[] = [$r['at'], $r['title'] . ' opens', 'Mock test', 'b-purple', 'mock-tests.php'];
}
foreach (db_all("SELECT title, ends_at AS at FROM exams WHERE status = 'published' AND ends_at BETWEEN NOW() AND ?", [$until]) as $r) {
    $events[] = [$r['at'], $r['title'] . ' closes', 'Mock test', 'b-purple', 'mock-tests.php'];
}
foreach (db_all('SELECT code, ends_at AS at FROM coupons WHERE is_active = 1 AND ends_at BETWEEN NOW() AND ?', [$until]) as $r) {
    $events[] = [$r['at'], 'Coupon ' . $r['code'] . ' expires', 'Offer', 'b-amber', 'coupons.php'];
}
foreach (db_all("SELECT title, published_at AS at FROM blog_posts WHERE status = 'scheduled' AND published_at BETWEEN NOW() AND ?", [$until]) as $r) {
    $events[] = [$r['at'], $r['title'], 'Blog post', 'b-blue', 'blog.php'];
}
if ($canJobs) {
    foreach (db_all("SELECT title, closes_at AS at FROM job_openings WHERE status = 'open' AND closes_at BETWEEN CURDATE() AND ?", [$until]) as $r) {
        $events[] = [$r['at'] . ' 23:59:00', 'Applications close: ' . $r['title'], 'Careers', 'b-gray', 'jobs.php?tab=openings'];
    }
    foreach (db_all("SELECT id, name, interview_at AS at FROM job_applications WHERE status = 'interview' AND interview_at BETWEEN NOW() AND ?", [$until]) as $r) {
        $events[] = [$r['at'], 'Interview: ' . $r['name'], 'Interview', 'b-green', 'jobs.php?tab=applications&application=' . (int) $r['id']];
    }
}
usort($events, fn($a, $b) => strcmp($a[0], $b[0]));
$events = array_slice($events, 0, 6);
$topCourses = db_all("SELECT c.title, COALESCE(p.color, cat.color) AS color, COUNT(*) AS n FROM course_enrolments e JOIN courses c ON c.id = e.course_id
    JOIN course_categories cat ON cat.id = c.category_id LEFT JOIN course_categories p ON p.id = cat.parent_id
    WHERE e.enrolled_at BETWEEN ? AND ? GROUP BY c.id ORDER BY n DESC LIMIT 5", [$f, $t]);
$growthMonths = last_months(12);
$gFirst = array_key_first($growthMonths) . '-01';
$growth = [];
if ($canUsers) {
    $running = $n("SELECT COUNT(*) FROM users WHERE user_type = 'student' AND created_at < ?", [$gFirst]);
    $gNew = array_column(db_all("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COUNT(*) AS v FROM users WHERE user_type = 'student' AND created_at >= ? GROUP BY m", [$gFirst]), 'v', 'm');
    foreach (array_keys($growthMonths) as $k) {
        $running += (int) ($gNew[$k] ?? 0);
        $growth[] = (float) $running;
    }
}
$sources = $canEnq ? db_all("SELECT source, COUNT(*) AS n FROM enquiries WHERE status <> 'spam' AND created_at BETWEEN ? AND ? GROUP BY source ORDER BY n DESC", [$f, $t]) : [];
$sourceLabels = ['contact_form' => 'Contact form', 'course_page' => 'Course page', 'callback' => 'Callback request', 'careers' => 'Careers page', 'hero_card' => 'Homepage form', 'api' => 'API / integration', 'other' => 'Other'];
// Same ONLY_FULL_GROUP_BY-safe shape as $catEnrol; ties keep the admin category order.
$catCourses = db_all("SELECT tc.name AS label, tc.color AS color, x.n
    FROM (SELECT COALESCE(p.id, cat.id) AS top_id, COUNT(*) AS n
          FROM courses c JOIN course_categories cat ON cat.id = c.category_id LEFT JOIN course_categories p ON p.id = cat.parent_id
          WHERE c.status = 'published' GROUP BY COALESCE(p.id, cat.id)) x
    JOIN course_categories tc ON tc.id = x.top_id
    ORDER BY x.n DESC, tc.sort_order, tc.name LIMIT 6");
$perf = db_all("SELECT e.title, COUNT(*) AS n, AVG(a.score / NULLIF(e.total_marks, 0) * 100) AS pct FROM exam_attempts a JOIN exams e ON e.id = a.exam_id
    WHERE a.status <> 'in_progress' AND a.submitted_at BETWEEN ? AND ? GROUP BY e.id ORDER BY n DESC LIMIT 5", [$f, $t]);
$pipeline = $canJobs ? array_column(db_all('SELECT status, COUNT(*) AS n FROM job_applications GROUP BY status'), 'n', 'status') : [];
$recentEnrol = db_all('SELECT e.enrolled_at, e.source, u.name, c.title FROM course_enrolments e JOIN users u ON u.id = e.user_id JOIN courses c ON c.id = e.course_id ORDER BY e.id DESC LIMIT 6');
$recentEnq = $canEnq ? db_all('SELECT id, name, source, status, created_at FROM enquiries ORDER BY id DESC LIMIT 6') : [];
$recentPay = $canOrders ? db_all("SELECT o.order_number, o.total, o.paid_at, u.name FROM orders o JOIN users u ON u.id = o.user_id WHERE o.status IN ('paid','partially_refunded','refunded') ORDER BY o.paid_at DESC LIMIT 6") : [];
$recentApps = $canJobs ? db_all('SELECT a.id, a.name, a.status, a.subject, a.created_at, o.title FROM job_applications a LEFT JOIN job_openings o ON o.id = a.opening_id ORDER BY a.id DESC LIMIT 6') : [];
$quick = array_filter([
    ['users.php?action=new', 'Add Student', 'users', 't-green', 'users.manage'],
    ['courses.php?action=new', 'New Course', 'book', 't-purple', 'courses.manage'],
    ['live-classes.php?action=new', 'Schedule Class', 'live', 't-red', 'live.manage'],
    ['mock-tests.php?action=new', 'Create Test', 'clipboard', 't-blue', 'exams.manage'],
    ['notifications.php#send', 'Send Notice', 'megaphone', 't-pink', 'notifications.send'],
    ['certificates.php', 'Issue Certificate', 'award', 't-amber', 'enrolments.manage'],
    ['coupons.php?action=new', 'New Coupon', 'tag', 't-teal', 'coupons.manage'],
    ['jobs.php?tab=applications', 'Review Applicants', 'briefcase', 't-orange', 'cms.manage'],
], fn($q) => can($q[4]));
// Pending approvals: testimonials and course reviews are moderated on different screens.
$pendingTestimonials = can('testimonials.manage') ? $n("SELECT COUNT(*) FROM testimonials WHERE status = 'pending'") : 0;
$pendingReviews = can('courses.manage') ? $n("SELECT COUNT(*) FROM course_reviews WHERE status = 'pending'") : 0;
$pendingApprovals = $pendingTestimonials + $pendingReviews;
// Recent mock-test attempts (submitted) for staff who can open the answer sheets or the reports
$recentAttempts = can('exams.manage') || can('reports.view') ? db_all("SELECT a.id, a.status, a.score, a.submitted_at, a.started_at, u.name, e.title, e.total_marks
    FROM exam_attempts a JOIN users u ON u.id = a.user_id JOIN exams e ON e.id = a.exam_id ORDER BY a.id DESC LIMIT 6") : [];
// Batch admissions: courses tagged with the selected academic session, counted from the first admission
// (even before 1 April when admissions open early). Needs migration 010.
$batchCourses = course_batches_enabled() ? db_all(
    "SELECT c.id, c.title, c.status,
            (SELECT COUNT(*) FROM course_enrolments e WHERE e.course_id = c.id AND e.status <> 'revoked') AS admissions,
            (SELECT COUNT(*) FROM course_enrolments e WHERE e.course_id = c.id AND e.status <> 'revoked' AND e.enrolled_at >= NOW() - INTERVAL 7 DAY) AS week,
            (SELECT COALESCE(SUM(oi.total), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE oi.course_id = c.id AND o.status IN ('paid','partially_refunded')) AS revenue
     FROM courses c WHERE c.academic_session = ? ORDER BY admissions DESC, c.title",
    [$session['key']]
) : [];
// Things waiting for staff (same list as the bell menu) + follow-ups due today
$tasks = admin_attention_items();
if ($canEnq && $followDue) {
    $tasks[] = ['Enquiry follow-ups due', 'enquiries.php?status=follow_up', 'clock', $followDue, 'amber'];
}
$qs = fn(array $extra = []) => '?' . http_build_query(array_filter(['period' => $period === 'session' ? null : $period, 'months' => $months ?: null] + $extra));
// "2 testimonials and 1 course review awaiting approval" (links only for staff who can moderate them)
$approvalParts = array_filter([
    $pendingTestimonials ? '<a href="testimonials.php?status=pending">' . e(plural_n($pendingTestimonials, 'testimonial')) . '</a>' : null,
    $pendingReviews ? '<a href="reviews.php?status=pending">' . e(plural_n($pendingReviews, 'course review')) . '</a>' : null,
]);
?>
<div class="db-alerts">
<?php if ($approvalParts): ?>
  <div class="alert alert-warning"><?= icon('star') ?><span><?= implode(' and ', $approvalParts) ?> awaiting approval before <?= $pendingApprovals === 1 ? 'it shows' : 'they show' ?> on the website.</span></div>
<?php endif; ?>
<?php
$adminFails = $n("SELECT COUNT(*) FROM login_attempts WHERE scope = 'admin' AND success = 0 AND created_at > NOW() - INTERVAL 1 DAY");
if (config('APP_DEBUG', false)): ?>
  <div class="alert alert-error"><strong>Security:</strong> APP_DEBUG is enabled - turn it off in .env before launch. <a href="security.php">Security dashboard</a></div>
<?php elseif (is_production() && !is_https()): ?>
  <div class="alert alert-error"><strong>Security:</strong> the site is live without HTTPS. Install an SSL certificate. <a href="security.php">Details</a></div>
<?php endif; ?>
<?php if ($adminFails >= 10): ?>
  <div class="alert alert-warning"><strong><?= $adminFails ?> failed admin sign-ins</strong> in the last 24 hours. <a href="logs.php?tab=logins&amp;scope=admin&amp;result=failed">Review login history</a></div>
<?php endif; ?>
<?php
if (can('settings.manage')) {
    require_once APP_ROOT . '/includes/launch.php';
    $launch = launch_checks();
    [$launchDone, $launchTotal] = launch_progress($launch);
    if (pending_migrations() || obsolete_files()) {
        echo '<div class="alert alert-warning"><strong>Update needed:</strong> the latest code came with database changes. <a href="updates.php">Apply now</a></div>';
    }
    if (array_filter($launch['Security'], fn($i) => $i['key'] === 'demo_admin' && $i['state'] !== 'ok')) {
        echo '<div class="alert alert-error"><strong>Security:</strong> the demo admin login is still active. <a href="profile.php#email">Change the sign-in email and password now</a>.</div>';
    }
    if ($launchDone < $launchTotal) {
        echo '<div class="alert alert-info"><strong>Go-live checklist:</strong> ' . $launchDone . ' of ' . $launchTotal . ' done. <a href="launch.php">See what is left</a></div>';
    }
}
?>
</div>

<section class="db-hero" aria-label="Today">
  <svg class="art" viewBox="0 0 340 190" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true">
    <path d="M20 182h300M36 182v-10h268v10M48 172V92m34 80V92m34 80V92m34 80V92m40 80V92m34 80V92m34 80V92m34 80V92M40 92h260L170 34 40 92Z"/>
    <path d="M150 172v-46h40v46M100 92v-8h140v8"/><circle cx="170" cy="66" r="12"/><path d="M170 58v8l5 4"/>
    <path d="M250 22l40-16 40 16-40 16-40-16Zm14 6v14c8 8 44 8 52 0V28m14-6v18"/>
  </svg>
  <div class="db-hero-top">
    <div class="db-pills">
      <span class="db-pill"><?= icon('calendar') ?> <?= e(date('l, j F Y')) ?></span>
      <span class="db-pill hide-xs"><?= icon('clock') ?> <time data-clock><?= e(date('g:i A')) ?></time></span>
      <span class="db-pill"><?= icon('cap') ?> Session <?= e($session['label'] . ($session['current'] ? '' : (!empty($session['upcoming']) ? ' (upcoming)' : ' (past)'))) ?></span>
    </div>
    <div class="db-pills">
      <span class="db-pill"><i class="dot<?= $maintenance ? ' amber' : '' ?>"></i> <?= $maintenance ? 'Maintenance mode' : 'Website live' ?> · <?= $liveMode ? 'Payments live' : (payments_ready() ? 'Payments in test mode' : 'Payments not set up') ?></span>
      <?= $picker ?>
      <a class="btn btn-white" href="<?= e(app_url('')) ?>" target="_blank" rel="noopener">View Website <?= icon('external') ?></a>
    </div>
  </div>
  <div class="db-greet">
    <span class="tile"><?= icon('cap') ?></span>
    <div>
      <small><?= e($greeting) ?>,</small>
      <h1><?= e($me['name']) ?></h1>
      <p><?= icon($canOrders ? 'rupee' : 'sparkles') ?> <?= $canOrders ? ($collectedToday > 0 ? e(money($collectedToday)) . ' collected today from ' . $paidToday . ' payment' . ($paidToday === 1 ? '' : 's') : 'No payments received yet today') : 'Here is what is happening at ' . e(setting('site_name', 'Laxyam Academy')) . ' today' ?></p>
    </div>
  </div>
  <div class="db-chips">
    <?php if ($canEnq): ?>
      <a class="db-chip" href="enquiries.php?status=new"><b><?= $newEnq ?></b> new enquir<?= $newEnq === 1 ? 'y' : 'ies' ?></a>
      <a class="db-chip" href="enquiries.php?status=follow_up"><b><?= $followDue ?></b> follow-up<?= $followDue === 1 ? '' : 's' ?> due</a>
    <?php endif; ?>
    <?php if ($canSupport): ?><a class="db-chip" href="support.php?status=open"><b><?= $openTickets ?></b> open ticket<?= $openTickets === 1 ? '' : 's' ?></a><?php endif; ?>
  </div>
  <div class="db-rings">
    <?php foreach ($rings as [$title, $value, $color, $big, $sub]): ?>
    <div class="db-ring">
      <?= ring_svg($value, $color) ?>
      <div><div class="t"><?= e($title) ?></div><div class="v"><?= e($big) ?></div><div class="s"><?= e($sub) ?></div></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="db-today-title"><?= icon('star') ?> Today at <?= e(setting('site_name', 'Laxyam Academy')) ?></div>
  <div class="db-today">
    <?php foreach ($today as [$ico, $tone, $title, $sub, $href]): ?>
    <<?= $href ? 'a href="' . e($href) . '"' : 'div' ?>><span class="tile <?= $tone ?>"><?= icon($ico) ?></span><span style="min-width:0"><strong><?= e($title) ?></strong><small><?= e($sub) ?></small></span></<?= $href ? 'a' : 'div' ?>>
    <?php endforeach; ?>
  </div>
</section>

<div class="db-section">
  <h2>Overview <span>· <?= e($period === 'session' ? 'Academic session ' . $session['label'] . ' (' . academic_session_span($session) . ')' : $periodLabel) ?></span></h2>
  <span class="muted">Updated <?= e(date('g:i A')) ?> · <a href="<?= e($qs()) ?>"><?= icon('history') ?> Refresh</a></span>
</div>
<section class="db-kpis" aria-label="Key figures">
  <?php foreach ($kpis as [$label, $ico, $tone, $color, $value, $cmp, $note, $spark, $href]): ?>
  <<?= $href ? 'a href="' . e($href) . '"' : 'div' ?> class="card db-kpi">
    <span class="tile <?= $tone ?>"><?= icon($ico) ?></span>
    <span class="label"><?= e($label) ?></span>
    <span class="value"><?= e($value) ?></span>
    <span class="meta"><?= $cmp ? change_chip($cmp) . ' ' . e($vsText) : '' ?><?= $note !== '' ? ($cmp ? ' · ' : '') . e($note) : '' ?></span>
    <?php if (is_array($spark)): ?>
      <?= sparkline($spark, $color, $label . ' - ' . $periodText) ?>
    <?php else: ?>
      <span class="kbar" title="<?= (int) $spark ?>% of courses published"><span><i style="width:<?= (int) $spark ?>%;--c:<?= e($color) ?>"></i></span></span>
    <?php endif; ?>
  </<?= $href ? 'a' : 'div' ?>>
  <?php endforeach; ?>
</section>

<section class="card db-mini" aria-label="More figures">
  <?php foreach ($mini as [$label, $ico, $tone, $value, $sub, $href]): ?>
  <<?= $href ? 'a href="' . e($href) . '"' : 'div' ?>><span class="tile <?= $tone ?>"><?= icon($ico) ?></span><span style="min-width:0"><small><?= e($label) ?></small><strong><?= number_format((int) $value) ?></strong><em><?= e($sub) ?></em></span></<?= $href ? 'a' : 'div' ?>>
  <?php endforeach; ?>
</section>

<?php if ($batchCourses || !empty($session['upcoming'])):
    $batchTotal = array_sum(array_map('intval', array_column($batchCourses, 'admissions')));
    $batchWeek = array_sum(array_map('intval', array_column($batchCourses, 'week')));
    $batchRevenue = array_sum(array_map('floatval', array_column($batchCourses, 'revenue')));
    $batchMax = max(1, ...array_map('intval', array_column($batchCourses, 'admissions') ?: [0])); ?>
<div class="db-row">
  <div class="card db-card acc-indigo" id="batch-admissions">
    <?= card_head('Batch Admissions · Session ' . $session['label'], 'Courses tagged with this session, counted from their first admission' . (!empty($session['upcoming']) ? ' · session starts ' . date('j M Y', strtotime($session['from'])) : ''), 'cap', 't-indigo',
        $batchCourses && can('enrolments.manage') ? '<a class="btn-xs" href="enrolments.php?batch=' . e(rawurlencode($session['key'])) . '">Admissions ' . icon('right') . '</a>' : '') ?>
    <div class="body">
      <?php if ($batchCourses): ?>
        <div class="db-status" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin-bottom:16px">
          <div><small><?= icon('users') ?> Admissions</small><strong><?= number_format($batchTotal) ?></strong><em>all <?= count($batchCourses) ?> batch course<?= count($batchCourses) === 1 ? '' : 's' ?></em></div>
          <div><small><?= icon('clock') ?> Last 7 days</small><strong><?= number_format($batchWeek) ?></strong><em>new admissions</em></div>
          <?php if ($canOrders): ?><div><small><?= icon('rupee') ?> Fees collected</small><strong><?= e(money($batchRevenue)) ?></strong><em>verified payments</em></div><?php endif; ?>
        </div>
        <ul class="db-bars">
          <?php foreach ($batchCourses as $i => $b): ?>
          <li><div class="row"><span><?= can('courses.manage') ? '<a href="courses.php?action=edit&amp;id=' . (int) $b['id'] . '">' . e($b['title']) . '</a>' : e($b['title']) ?> <?= $b['status'] !== 'published' ? badge($b['status']) : '' ?></span>
            <b><?= (int) $b['admissions'] ?><?= $canOrders && (float) $b['revenue'] > 0 ? ' · ' . e(money($b['revenue'])) : '' ?></b></div>
            <div class="bar"><i style="width:<?= round((int) $b['admissions'] / $batchMax * 100) ?>%;--c:<?= DASH_COLORS[$i % count(DASH_COLORS)] ?>"></i></div></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <?= dash_empty('cap', 'No batch courses for ' . $session['label'] . ' yet', 'Tag a course with this session (Courses → edit → “Batch / academic session”) to count its admissions here from day one.', can('courses.manage') ? 'courses.php?action=new' : null, 'Add a batch course') ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="db-row <?= $quick ? 'rqa' : '' ?>">
  <div class="card db-card acc-gold" id="tasks">
    <?= card_head('Needs Your Attention', $tasks ? array_sum(array_column($tasks, 3)) . ' item' . (array_sum(array_column($tasks, 3)) === 1 ? '' : 's') . ' waiting · only what you can act on' : 'Pending work across the academy', 'bell', 't-amber') ?>
    <div class="body">
      <?php if ($tasks): ?><ul class="db-tasks">
        <?php foreach ($tasks as [$label, $href, $ico, $count, $tone]): ?>
        <li><a href="<?= e($href) ?>"><span class="mi-ic tone-<?= e($tone) ?>"><?= icon($ico) ?></span><span class="txt"><?= e($label) ?><small><?= e(task_hint($href)) ?></small></span><b><?= number_format($count) ?></b><?= icon('right', 'i chev') ?></a></li>
        <?php endforeach; ?>
      </ul><?php else: ?>
        <?= dash_empty('check-circle', 'You are all caught up', 'New enquiries, reviews to approve, failed emails, refunds and database updates will show up here.') ?>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($quick): ?>
  <div class="card db-card acc-teal" id="quick-actions">
    <?= card_head('Quick Actions', 'Frequent tasks, one click away', 'zap', 't-teal') ?>
    <div class="body"><div class="db-qa">
      <?php foreach ($quick as [$href, $label, $ico, $tone]): ?>
      <a href="<?= e($href) ?>"><span class="tile <?= $tone ?>"><?= icon($ico) ?></span><?= e($label) ?></a>
      <?php endforeach; ?>
    </div></div>
  </div>
  <?php endif; ?>
</div>

<?php $monthsPicker = fn(string $anchor) => '<form method="get" action="#' . $anchor . '">' . ($period !== 'session' ? '<input type="hidden" name="period" value="' . e($period) . '">' : '')
    . '<select class="btn-xs" name="months" data-autosubmit aria-label="Chart range"><option value=""' . (!$months ? ' selected' : '') . '>Session ' . e($session['label']) . '</option><option value="6"' . ($months === 6 ? ' selected' : '') . '>Last 6 months</option><option value="12"' . ($months === 12 ? ' selected' : '') . '>Last 12 months</option></select></form>'; ?>
<div class="db-row <?= $canOrders ? 'r2' : '' ?>">
  <div class="card db-card acc-purple" id="registrations">
    <?= card_head('Enrolment Trend', 'New students and enrolments · ' . $monthsText, 'users', 't-purple', $monthsPicker('registrations')) ?>
    <div class="body">
      <?php if (array_sum($trendStudents) + array_sum($trendEnrol) > 0): ?>
        <div class="legend" style="padding:0 0 6px"><span><i style="background:#0b63f6"></i>New students</span><span><i style="background:#16a34a"></i>Enrolments</span></div>
        <?= svg_line_chart(array_values($monthKeys), [['New students', '1', $trendStudents], ['Enrolments', '2', $trendEnrol]], 'Monthly new students and enrolments', 640, 230) ?>
      <?php else: ?>
        <?= dash_empty('users', 'No registrations in this period', 'New sign-ups and course enrolments will be charted here month by month.', can('users.manage') ? 'users.php?action=new' : null, 'Add student') ?>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($canOrders): ?>
  <div class="card db-card acc-green" id="revenue">
    <?= card_head('Revenue', 'Verified payments · ' . $monthsText, 'rupee', 't-green', $monthsPicker('revenue')) ?>
    <div class="body">
      <?php if (array_sum($revenue) > 0): ?>
        <?= svg_bar_chart($revenue, 'Monthly verified revenue', true, 640, 230) ?>
      <?php else: ?>
        <?= dash_empty('rupee', 'No payments yet', 'Verified course payments will appear here once students start buying.', 'orders.php', 'View orders') ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="db-row <?= $canEnq ? 'r2' : '' ?>">
  <div class="card db-card acc-indigo">
    <?= card_head('Enrolments by Category', 'Active students by exam category', 'chart', 't-indigo') ?>
    <div class="body">
      <?php if ($catEnrol): ?>
        <?= donut_chart(array_map(fn($r, $i) => [$r['label'], (int) $r['n'], $r['color'] ?: DASH_COLORS[$i % count(DASH_COLORS)]], $catEnrol, array_keys($catEnrol)), 'Active enrolments by category', 'enrolled') ?>
      <?php else: ?>
        <?= dash_empty('chart', 'No active students yet', 'When students enrol, you will see how they split across UPSC, SSC, Banking and other categories.') ?>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($canEnq): ?>
  <div class="card db-card acc-pink">
    <?= card_head('Lead Sources', 'Where enquiries came from · ' . $periodText, 'megaphone', 't-pink') ?>
    <div class="body">
      <?php if ($sources): ?>
        <?= donut_chart(array_map(fn($r, $i) => [$sourceLabels[$r['source']] ?? ucfirst(str_replace('_', ' ', $r['source'])), (int) $r['n'], DASH_COLORS[$i % count(DASH_COLORS)]], $sources, array_keys($sources)), 'Enquiries by source', 'enquiries') ?>
      <?php else: ?>
        <?= dash_empty('megaphone', 'No enquiries in this period', 'Contact-form, course-page and callback leads will be split by source here.') ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="db-row r3">
  <?php if ($activity): ?>
  <div class="card db-card acc-blue">
    <?= card_head('Recent Activity', 'Latest changes by staff and the system', 'history', 't-blue', '<a class="btn-xs" href="logs.php">View all ' . icon('right') . '</a>') ?>
    <div class="body"><ul class="db-list">
      <?php foreach ($activity as $a): ?>
      <li><span class="av"><?= icon(activity_icon($a['action'])) ?></span>
        <span class="txt"><strong><?= e(ucfirst(str_replace('_', ' ', $a['action']))) ?></strong><span><?= e(($a['name'] ?? 'System') . ($a['entity_type'] ? ' · ' . str_replace('_', ' ', $a['entity_type']) . ($a['entity_id'] ? ' #' . $a['entity_id'] : '') : '')) ?></span></span>
        <span class="end"><?= e(fmt_ago($a['created_at'])) ?></span></li>
      <?php endforeach; ?>
    </ul></div>
  </div>
  <?php endif; ?>
  <div class="card db-card acc-red" id="upcoming-live">
    <?= card_head('Upcoming & Deadlines', 'Next 60 days', 'calendar', 't-red', can('live.manage') ? '<a class="btn-xs" href="calendar.php">Calendar ' . icon('right') . '</a>' : '') ?>
    <div class="body">
      <?php if ($events): ?><ul class="db-list">
        <?php foreach ($events as [$at, $title, $type, $badge, $href]): ?>
        <li><span class="db-date"><b><?= e(date('j', strtotime($at))) ?></b><i><?= e(date('M', strtotime($at))) ?></i></span>
          <span class="txt"><a href="<?= e($href) ?>"><?= e($title) ?></a><span><?= e(date('D, g:i A', strtotime($at))) ?></span></span>
          <span class="badge <?= $badge ?>"><?= e($type) ?></span></li>
        <?php endforeach; ?>
      </ul><?php else: ?>
        <?= dash_empty('calendar', 'Nothing scheduled', 'Live classes, test windows, offers ending and interviews in the next 60 days show here.', can('live.manage') ? 'live-classes.php?action=new' : null, 'Schedule a class') ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="card db-card acc-amber" id="top-courses">
    <?= card_head('Top Courses', 'By new enrolments · ' . $periodText, 'award', 't-amber') ?>
    <div class="body">
      <?php if ($topCourses): $max = max(array_column($topCourses, 'n')); ?><ul class="db-bars">
        <?php foreach ($topCourses as $c): ?>
        <li><div class="row"><span><?= e($c['title']) ?></span><b><?= (int) $c['n'] ?></b></div><div class="bar"><i style="width:<?= round($c['n'] / $max * 100) ?>%;--c:<?= e($c['color'] ?: '#3b82f6') ?>"></i></div></li>
        <?php endforeach; ?>
      </ul><?php else: ?>
        <?= dash_empty('award', 'No enrolments in this period', 'Your most popular courses will be ranked here.', can('courses.view') ? 'courses.php' : null, 'View courses') ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="db-row <?= $recentAttempts && can('settings.manage') ? 'r2' : '' ?>">
  <?php if ($recentAttempts): ?>
  <div class="card db-card acc-purple" id="recent-attempts">
    <?= card_head('Recent Test Attempts', 'Latest mock tests and practice quizzes', 'clipboard', 't-purple', can('exams.manage') ? '<a class="btn-xs" href="attempts.php">All attempts ' . icon('right') . '</a>' : '') ?>
    <div class="body"><ul class="db-list">
      <?php foreach ($recentAttempts as $a):
          $done = $a['status'] !== 'in_progress';
          $pctScore = $done && (float) $a['total_marks'] > 0 ? round((float) $a['score'] / (float) $a['total_marks'] * 100) : null; ?>
      <li><span class="av"><?= e(mb_strtoupper(mb_substr($a['name'], 0, 1))) ?></span>
        <span class="txt"><?= can('exams.manage') ? '<a href="attempts.php?id=' . (int) $a['id'] . '">' . e($a['name']) . '</a>' : '<strong>' . e($a['name']) . '</strong>' ?><span><?= e($a['title']) ?></span></span>
        <span class="end"><?= $done ? '<b>' . e(rtrim(rtrim(number_format((float) $a['score'], 2), '0'), '.')) . ' / ' . e(rtrim(rtrim(number_format((float) $a['total_marks'], 2), '0'), '.')) . '</b>' . ($pctScore !== null ? $pctScore . '% · ' : '') : badge('in_progress') . '<br>' ?><?= e(fmt_ago($a['submitted_at'] ?? $a['started_at'])) ?></span></li>
      <?php endforeach; ?>
    </ul></div>
  </div>
  <?php endif; ?>
  <?php if (can('settings.manage')):
      $dbInfo = db_one('SELECT COUNT(*) AS tables, COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.TABLES WHERE table_schema = DATABASE()');
      $backup = json_decode_array(setting('backup_last_at'));
      $cronRun = json_decode_array(setting('cron_last_run'));
      $webRun = json_decode_array(setting('scheduler_last_run'));
      $backupOk = !empty($backup['at']) && strtotime($backup['at']) > time() - 7 * 86400;
      $cronOk = !empty($cronRun['at']) && strtotime($cronRun['at']) > time() - 3600;
      $webOk = !empty($webRun['at']) && strtotime($webRun['at']) > time() - 7200;
      $pendingDb = count(pending_migrations());
      $schemaVersion = (int) db_value('SELECT COALESCE(MAX(version), 0) FROM schema_migrations');
      try {
          $mailFailed = (int) db_value("SELECT COUNT(*) FROM email_log WHERE status = 'failed' AND created_at > NOW() - INTERVAL 7 DAY");
          $mailSent = (int) db_value("SELECT COUNT(*) FROM email_log WHERE status IN ('sent','skipped') AND created_at > NOW() - INTERVAL 7 DAY");
      } catch (PDOException) {
          [$mailFailed, $mailSent] = [0, 0];
      }
      $smtpOn = (string) config('SMTP_HOST', '') !== ''; ?>
  <div class="card db-card acc-indigo" id="system-health">
    <?= card_head('System Health', 'Checked just now · ' . date_default_timezone_get(), 'shield', 't-indigo', '<a class="btn-xs" href="backup.php">Backups ' . icon('right') . '</a>') ?>
    <div class="body"><div class="db-status">
      <div><small><?= icon('globe') ?> Server</small><strong><i class="st-dot"></i> Online</strong><em>PHP <?= e(PHP_VERSION) ?> · <?= is_production() ? 'production' : 'development' ?></em></div>
      <a href="updates.php"><small><?= icon('database') ?> Database</small><strong><i class="st-dot <?= $pendingDb ? 'bad' : '' ?>"></i> <?= $pendingDb ? 'Database update needed' : 'Up to date' ?></strong><em><?= $pendingDb ? $pendingDb . ' update' . ($pendingDb === 1 ? '' : 's') . ' waiting · apply now' : 'Schema v' . $schemaVersion . ' · ' . number_format($dbInfo['bytes'] / 1048576, 1) . ' MB · ' . (int) $dbInfo['tables'] . ' tables' ?></em></a>
      <a href="backup.php"><small><?= icon('download') ?> Backup</small><strong><i class="st-dot <?= $backupOk ? '' : 'warn' ?>"></i> <?= !empty($backup['at']) ? e(fmt_dt($backup['at'], 'j M Y')) : 'Not yet' ?></strong><em><?= !empty($backup['at']) ? e(fmt_ago($backup['at'])) : 'Download one now' ?></em></a>
      <div><small><?= icon('clock') ?> Scheduled jobs</small><strong><i class="st-dot <?= $cronOk ? '' : ($webOk ? 'warn' : 'bad') ?>"></i> <?= $cronOk ? 'Cron running' : ($webOk ? 'Via site traffic' : 'Not running') ?></strong><em>Last run <?= e(fmt_ago($cronOk ? $cronRun['at'] : ($webRun['at'] ?? null))) ?></em></div>
      <<?= can('notifications.send') ? 'a href="email-logs.php' . ($mailFailed ? '?status=failed' : '') . '"' : 'div' ?>><small><?= icon('mail') ?> Email</small><strong><i class="st-dot <?= $mailFailed ? 'bad' : ($smtpOn ? '' : 'warn') ?>"></i> <?= $mailFailed ? $mailFailed . ' failed this week' : ($smtpOn ? 'Sending' : 'Log only (no SMTP)') ?></strong><em><?= number_format($mailSent) ?> delivered or logged in 7 days</em></<?= can('notifications.send') ? 'a' : 'div' ?>>
      <div><small><?= icon('shield') ?> Security</small><strong><i class="st-dot <?= config('APP_DEBUG', false) || (is_production() && !is_https()) ? 'bad' : ($adminFails >= 10 ? 'warn' : '') ?>"></i> <?= config('APP_DEBUG', false) ? 'Debug mode on' : ((is_production() && !is_https()) ? 'No HTTPS' : 'Protected') ?></strong><em><?= $adminFails ?> failed admin sign-in<?= $adminFails === 1 ? '' : 's' ?> in 24 h</em></div>
    </div></div>
  </div>
  <?php endif; ?>
</div>

<?php if ($canUsers): ?>
<div class="db-row">
  <div class="card db-card acc-blue">
    <?= card_head('Student Growth', 'Total registered students · last 12 months', 'chart', 't-blue', can('reports.view') ? '<a class="btn-xs" href="reports.php?tab=growth">Reports ' . icon('right') . '</a>' : '') ?>
    <div class="body">
      <?php if (max($growth ?: [0]) > 0): ?>
        <?= svg_line_chart(array_values($growthMonths), [['Total students', '1', $growth]], 'Total registered students by month', 1200, 250) ?>
      <?php else: ?>
        <?= dash_empty('chart', 'No students yet', 'Growth will be charted here as students register.') ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="db-row r3">
  <div class="card db-card acc-teal">
    <?= card_head('Courses by Category', 'Published courses', 'book', 't-teal', can('courses.view') ? '<a class="btn-xs" href="categories.php">Categories ' . icon('right') . '</a>' : '') ?>
    <div class="body">
      <?php if ($catCourses): $max = max(array_column($catCourses, 'n')); ?><ul class="db-bars">
        <?php foreach ($catCourses as $i => $c): ?>
        <li><div class="row"><span><?= e($c['label']) ?></span><b><?= (int) $c['n'] ?></b></div><div class="bar"><i style="width:<?= round($c['n'] / $max * 100) ?>%;--c:<?= e($c['color'] ?: DASH_COLORS[$i % count(DASH_COLORS)]) ?>"></i></div></li>
        <?php endforeach; ?>
      </ul><?php else: ?>
        <?= dash_empty('book', 'No published courses', 'Publish a course to see the category split.', can('courses.manage') ? 'courses.php?action=new' : null, 'Add a course') ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="card db-card acc-purple">
    <?= card_head('Mock Test Performance', 'Average score · ' . $periodText, 'clipboard', 't-purple', can('reports.view') ? '<a class="btn-xs" href="reports.php?tab=tests">Results ' . icon('right') . '</a>' : '') ?>
    <div class="body">
      <?php if ($perf): ?><ul class="db-bars">
        <?php foreach ($perf as $p): $score = max(0, min(100, (float) $p['pct'])); ?>
        <li><div class="row"><span><?= e($p['title']) ?></span><b><?= round($score) ?>%</b></div><div class="bar"><i style="width:<?= round($score) ?>%;--c:<?= $score >= 60 ? '#22c55e' : ($score >= 40 ? '#f59e0b' : '#ef4444') ?>"></i></div>
          <span class="muted" style="font-size:11.5px"><?= (int) $p['n'] ?> attempt<?= (int) $p['n'] === 1 ? '' : 's' ?></span></li>
        <?php endforeach; ?>
      </ul><?php else: ?>
        <?= dash_empty('clipboard', 'No results this period', 'Average scores per test appear once students submit attempts.', can('exams.manage') ? 'mock-tests.php' : null, 'View tests') ?>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($canJobs):
      $stages = ['new' => ['New', '#3b82f6'], 'shortlisted' => ['Shortlisted', '#8b5cf6'], 'interview' => ['Interview', '#f59e0b'], 'hired' => ['Hired', '#22c55e']];
      $pipeTotal = array_sum(array_map('intval', $pipeline)); ?>
  <div class="card db-card acc-orange">
    <?= card_head('Faculty Recruitment', 'Applications by stage', 'briefcase', 't-orange', '<a class="btn-xs" href="jobs.php?tab=applications">Applicants ' . icon('right') . '</a>') ?>
    <div class="body">
      <?php if ($pipeTotal): $max = max(1, ...array_map(fn($s) => (int) ($pipeline[$s] ?? 0), array_keys($stages))); ?><ul class="db-bars">
        <?php foreach ($stages as $s => [$label, $color]): $v = (int) ($pipeline[$s] ?? 0); ?>
        <li><div class="row"><a href="jobs.php?tab=applications&amp;status=<?= $s ?>"><?= e($label) ?></a><b><?= $v ?></b></div><div class="bar"><i style="width:<?= round($v / $max * 100) ?>%;--c:<?= $color ?>"></i></div></li>
        <?php endforeach; ?>
        <li class="muted" style="font-size:12px"><?= (int) ($pipeline['rejected'] ?? 0) ?> not selected · <?= e(plural_n($n("SELECT COUNT(*) FROM job_applications WHERE status = 'interview' AND interview_at >= NOW()"), 'interview')) ?> upcoming</li>
      </ul><?php else: ?>
        <?= dash_empty('briefcase', 'No applications yet', 'Faculty applications from the careers page will be tracked here by stage.', app_url('careers'), 'View careers page') ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="db-row <?= $canEnq ? 'r2' : '' ?>">
  <div class="card db-card acc-green">
    <?= card_head('Recent Enrolments', 'Latest students joining courses', 'cap', 't-green', can('enrolments.manage') ? '<a class="btn-xs" href="enrolments.php">View all ' . icon('right') . '</a>' : '') ?>
    <div class="body">
      <?php if ($recentEnrol): ?><ul class="db-list">
        <?php foreach ($recentEnrol as $r): ?>
        <li><span class="av"><?= e(mb_strtoupper(mb_substr($r['name'], 0, 1))) ?></span><span class="txt"><strong><?= e($r['name']) ?></strong><span><?= e($r['title']) ?></span></span>
          <span class="end"><span class="badge <?= $r['source'] === 'purchase' ? 'b-green' : 'b-blue' ?>"><?= e(ucfirst($r['source'])) ?></span><br><?= e(fmt_ago($r['enrolled_at'])) ?></span></li>
        <?php endforeach; ?>
      </ul><?php else: ?>
        <?= dash_empty('cap', 'No enrolments yet', 'Students who join a course appear here.', can('enrolments.manage') ? 'enrolments.php' : null, 'Manage enrolments') ?>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($canEnq): ?>
  <div class="card db-card acc-blue" id="pending-enquiries">
    <?= card_head('Recent Enquiries', 'Counselling and website enquiries', 'mail', 't-blue', '<a class="btn-xs" href="enquiries.php">View all ' . icon('right') . '</a>') ?>
    <div class="body">
      <?php if ($recentEnq): ?><ul class="db-list">
        <?php foreach ($recentEnq as $q): ?>
        <li><span class="av"><?= e(mb_strtoupper(mb_substr($q['name'], 0, 1))) ?></span><span class="txt"><a href="enquiries.php?action=edit&amp;id=<?= (int) $q['id'] ?>"><?= e($q['name']) ?></a><span><?= e($sourceLabels[$q['source']] ?? ucfirst(str_replace('_', ' ', $q['source']))) ?></span></span>
          <span class="end"><?= badge($q['status']) ?><br><?= e(fmt_ago($q['created_at'])) ?></span></li>
        <?php endforeach; ?>
      </ul><?php else: ?>
        <?= dash_empty('mail', 'No enquiries yet', 'Website and callback enquiries will be listed here.') ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="db-row <?= $canOrders && $canJobs ? 'r2' : '' ?>">
  <?php if ($canOrders): ?>
  <div class="card db-card acc-orange" id="recent-orders">
    <?= card_head('Recent Payments', 'Latest verified course payments', 'cart', 't-orange', '<a class="btn-xs" href="orders.php?status=paid">View all ' . icon('right') . '</a>') ?>
    <div class="body">
      <?php if ($recentPay): ?><ul class="db-list">
        <?php foreach ($recentPay as $p): ?>
        <li><span class="av"><?= icon('rupee') ?></span><span class="txt"><strong><?= e($p['name']) ?></strong><span><?= e($p['order_number']) ?></span></span>
          <span class="end"><b><?= e(money($p['total'])) ?></b><?= e(fmt_ago($p['paid_at'])) ?></span></li>
        <?php endforeach; ?>
      </ul><?php else: ?>
        <?= dash_empty('cart', 'No payments yet', 'Verified payments appear here as soon as the payment gateway confirms them.', 'payments.php?tab=setup', 'Payment setup') ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($canJobs): ?>
  <div class="card db-card acc-amber">
    <?= card_head('Recent Job Applications', 'Faculty and staff applicants', 'briefcase', 't-amber', '<a class="btn-xs" href="jobs.php?tab=applications">View all ' . icon('right') . '</a>') ?>
    <div class="body">
      <?php if ($recentApps): ?><ul class="db-list">
        <?php foreach ($recentApps as $a): ?>
        <li><span class="av"><?= e(mb_strtoupper(mb_substr($a['name'], 0, 1))) ?></span><span class="txt"><a href="jobs.php?tab=applications&amp;application=<?= (int) $a['id'] ?>"><?= e($a['name']) ?></a><span><?= e($a['subject'] ? lookup_label('faculty_subject', $a['subject']) : ($a['title'] ?? 'General application')) ?></span></span>
          <span class="end"><?= badge($a['status']) ?><br><?= e(fmt_ago($a['created_at'])) ?></span></li>
        <?php endforeach; ?>
      </ul><?php else: ?>
        <?= dash_empty('briefcase', 'No applications yet', 'New applicants from the careers page land here.', 'jobs.php?tab=openings', 'View positions') ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<footer class="db-foot"><span>© <?= date('Y') ?> <?= e(setting('site_name', 'Laxyam Academy')) ?> · Admin panel</span><span><a href="help.php">Admin guide</a><?= can('settings.manage') ? ' · <a href="launch.php">Go-live checklist</a>' : '' ?></span></footer>
<script>
// Keep the hero clock current (academy time zone)
(function () {
  var el = document.querySelector('[data-clock]');
  if (!el) return;
  var fmt = new Intl.DateTimeFormat('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: <?= json_encode(date_default_timezone_get()) ?> });
  setInterval(function () { el.textContent = fmt.format(new Date()).toUpperCase(); }, 20000);
})();
</script>
<?php admin_end();

/** Keys of the ACTIVE gateway (PAYMENT_PROVIDER: Cashfree or Razorpay) are set. */
function payments_ready(): bool
{
    require_once APP_ROOT . '/includes/payments.php';
    return payments_configured();
}

/** Values in $n equal time buckets from $from (unix) over $len seconds. Table/column names come from this file only. */
function dash_series(string $table, string $col, string $agg, string $where, int $from, int $len, int $n = 8): array
{
    $step = max(1, intdiv($len, $n));
    $start = date('Y-m-d H:i:s', $from);
    $rows = db_all("SELECT LEAST(FLOOR(TIMESTAMPDIFF(SECOND, ?, {$col}) / ?), ?) AS b, {$agg} AS v FROM {$table}
                    WHERE {$where} AND {$col} >= ? AND {$col} <= ? GROUP BY b", [$start, $step, $n - 1, $start, date('Y-m-d H:i:s', $from + $len)]);
    $out = array_fill(0, $n, 0.0);
    foreach ($rows as $r) {
        $out[max(0, (int) $r['b'])] = (float) $r['v'];
    }
    return $out;
}

function change_chip(array $pair): string
{
    [$now, $prev] = $pair;
    if ((float) $now === (float) $prev) {
        return '<span class="chg">→ 0%</span>';
    }
    if ((float) $prev === 0.0) {
        return '<span class="chg up">▲ new</span>';
    }
    $pct = round(($now - $prev) / $prev * 100);
    return '<span class="chg ' . ($pct >= 0 ? 'up' : 'down') . '">' . ($pct >= 0 ? '▲' : '▼') . ' ' . abs($pct) . '%</span>';
}

/** Small area sparkline (decorative trend; the exact numbers are in the card). */
function sparkline(array $values, string $color, string $label): string
{
    static $id = 0;
    $id++;
    $count = count($values);
    $max = max($values) ?: 1;
    $pts = [];
    foreach (array_values($values) as $i => $v) {
        $pts[] = round($i * 120 / max(1, $count - 1), 1) . ',' . round(36 - ($v / $max) * 30, 1);
    }
    $line = 'M' . implode(' L', $pts);
    return '<svg class="spark" viewBox="0 0 120 40" preserveAspectRatio="none" role="img" aria-label="' . e($label . ' trend') . '">'
        . '<defs><linearGradient id="spk' . $id . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $color . '" stop-opacity=".28"/><stop offset="1" stop-color="' . $color . '" stop-opacity="0"/></linearGradient></defs>'
        . '<path d="' . $line . ' L120,40 L0,40 Z" fill="url(#spk' . $id . ')"/>'
        . '<path d="' . $line . '" fill="none" stroke="' . $color . '" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

function ring_svg(?float $pct, string $color): string
{
    $c = 2 * M_PI * 26;
    $p = $pct === null ? 0 : max(0, min(100, $pct));
    return '<svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="26" fill="none" stroke="rgb(255 255 255 / .15)" stroke-width="6"/>'
        . ($p > 0 ? '<circle cx="32" cy="32" r="26" fill="none" stroke="' . $color . '" stroke-width="6" stroke-linecap="round" stroke-dasharray="' . round($c * $p / 100, 2) . ' ' . round($c, 2) . '" transform="rotate(-90 32 32)"/>' : '')
        . '<text x="32" y="36.5" text-anchor="middle" fill="#fff" font-size="13" font-weight="700">' . ($pct === null ? '—' : round($p) . '%') . '</text></svg>';
}

/** Donut with legend, hover tooltips and a table view. $slices: [[label, value, color], ...] */
function donut_chart(array $slices, string $title, string $unit): string
{
    if (count($slices) > 7) {
        $rest = array_slice($slices, 6);
        $slices = array_slice($slices, 0, 6);
        $slices[] = ['Other', array_sum(array_column($rest, 1)), '#94a3b8'];
    }
    $total = array_sum(array_column($slices, 1)) ?: 1;
    $c = 2 * M_PI * 60;
    $svg = '<svg class="donut" viewBox="0 0 160 160" role="img" aria-label="' . e($title) . '"><circle class="donut-track" cx="80" cy="80" r="60" fill="none" stroke-width="22"/>';
    $offset = 0.0;
    $legend = '';
    foreach ($slices as [$label, $value, $color]) {
        $share = $value / $total;
        $len = $share * $c;
        $gap = count($slices) > 1 ? min(2.0, $len / 2) : 0.0; // 2px surface gap between segments
        $tip = $label . ': ' . number_format($value) . ' (' . round($share * 100) . '%)';
        $svg .= '<circle cx="80" cy="80" r="60" fill="none" stroke="' . e($color) . '" stroke-width="22" stroke-dasharray="' . round(max(0.5, $len - $gap), 2) . ' ' . round($c, 2) . '" stroke-dashoffset="' . round(-$offset, 2) . '" transform="rotate(-90 80 80)" data-tip="' . e($tip) . '"/>';
        $offset += $len;
        $legend .= '<li><i style="background:' . e($color) . '"></i><span>' . e($label) . '</span><b>' . number_format($value) . '</b><em>' . round($share * 100) . '%</em></li>';
    }
    $svg .= '<text x="80" y="82" text-anchor="middle" class="dv">' . number_format(array_sum(array_column($slices, 1))) . '</text><text x="80" y="100" text-anchor="middle" class="dl">' . e($unit) . '</text></svg>';
    return '<div class="donut-wrap">' . $svg . '<ul class="donut-legend">' . $legend . '</ul></div>'
        . chart_table($title, array_column($slices, 0), [[ucfirst($unit), array_column($slices, 1)]], false);
}

function card_head(string $title, string $sub, string $icon, string $tone, string $right = ''): string
{
    return '<div class="head"><div class="ttl"><span class="tile ' . $tone . '">' . icon($icon) . '</span><div><h2>' . e($title) . '</h2>' . ($sub !== '' ? '<p>' . e($sub) . '</p>' : '') . '</div></div>' . $right . '</div>';
}

function dash_empty(string $icon, string $title, string $text, ?string $href = null, ?string $label = null): string
{
    return '<div class="db-empty"><span class="ic">' . icon($icon) . '</span><strong>' . e($title) . '</strong><p>' . e($text) . '</p>'
        . ($href && $label ? '<a class="btn btn-navy" href="' . e($href) . '">' . e($label) . '</a>' : '') . '</div>';
}

/** One-line explanation under each "Needs your attention" item. */
function task_hint(string $href): string
{
    return match (strtok($href, '?')) {
        'enquiries.php' => str_contains($href, 'follow_up') ? 'Call back the students you promised to follow up' : 'Reply within a day to convert more leads',
        'testimonials.php' => 'Approve or reject before they show on the website',
        'reviews.php' => 'Student course reviews waiting for moderation',
        'jobs.php' => 'Applications from the careers page',
        'support.php' => 'Students waiting for a reply',
        'payments.php' => 'Refunds requested or still processing',
        'email-logs.php' => 'Emails that could not be delivered after retries',
        'updates.php' => 'Apply the latest database changes',
        default => 'Open to review',
    };
}

function activity_icon(string $action): string
{
    return match (true) {
        str_contains($action, 'login') || str_contains($action, 'logout') || str_contains($action, '2fa') => 'user',
        str_contains($action, 'payment') || str_contains($action, 'order') || str_contains($action, 'refund') => 'rupee',
        str_contains($action, 'deleted') || str_contains($action, 'removed') => 'trash',
        str_contains($action, 'created') || str_contains($action, 'registered') => 'plus',
        str_contains($action, 'backup') || str_contains($action, 'migration') => 'database',
        str_contains($action, 'settings') || str_contains($action, 'policy') => 'settings',
        str_contains($action, 'updated') || str_contains($action, 'changed') => 'edit',
        default => 'log',
    };
}
