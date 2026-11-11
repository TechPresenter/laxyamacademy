<?php
/**
 * Live classes API. Meeting URLs are released only through action=join, to eligible users,
 * from live_join_window_minutes (admin → Settings → Live classes) before start until the class ends.
 *
 *   GET  live-classes.php?action=list&when=upcoming|past&category=&page=
 *   GET  live-classes.php?action=mine          classes the student can attend (+ reminder flags)
 *   POST live-classes.php?action=remind        id, on (bool)
 *   POST live-classes.php?action=join          id   -> { meeting_url }
 *   POST live-classes.php?action=recording     id   -> { recording_url }
 *
 * list / mine also return join_window_minutes, reminder_lead_minutes and viewer {logged_in, can_set_reminders};
 * each class has reminder_set, eligible (null = unknown until the guest logs in) and cta:
 *   join | login_to_join | remind | reminder_set | login_to_remind | enrol | recording | login_to_watch | ended | cancelled | none
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/catalog.php';

define('JOIN_EARLY_MINUTES', setting_int('live_join_window_minutes', 15, 0, 240)); // admin → Live Class Settings

$action = $_GET['action'] ?? '';
if (in_array($action, ['remind', 'join', 'recording'], true)) {
    require_method('POST');
    require_csrf();
} else {
    require_method('GET');
}

match ($action) {
    'list' => list_classes(),
    'mine' => my_classes(),
    'remind' => remind(),
    'join' => join_class(),
    'recording' => recording(),
    default => api_error('Unknown action.', 404, 'not_found'),
};

function with_user_flags(array $classes, ?array $user): array
{
    if (!$classes) {
        return $classes;
    }
    if (!$user) {
        // Guests: public classes are open to them; anything else is decided after login.
        foreach ($classes as &$c) {
            $c['reminder_set'] = false;
            $c['eligible'] = $c['access_type'] === 'public' ? true : null;
        }
        return $classes;
    }
    $ids = array_column($classes, 'id');
    $reminders = array_map('intval', array_column(db_all(
        'SELECT live_class_id FROM live_class_reminders WHERE user_id = ? AND live_class_id IN (' . db_placeholders(count($ids)) . ')',
        [$user['id'], ...$ids]
    ), 'live_class_id'));
    $courseIds = accessible_course_ids((int) $user['id']);
    $staff = $user['user_type'] === 'staff'; // same rule as content_access_allowed()
    foreach ($classes as &$c) {
        $c['reminder_set'] = in_array($c['id'], $reminders, true);
        $c['eligible'] = $staff || $c['access_type'] !== 'enrolled' || ($c['_course_id'] && in_array($c['_course_id'], $courseIds, true));
    }
    return $classes;
}

/** The one action the class card should offer this viewer right now (the UI re-checks join_opens_at on a timer). */
function class_cta(array $c, ?array $user): string
{
    $student = $user !== null && $user['user_type'] === 'student';
    $open = $c['access_type'] === 'public' || ($user !== null && $c['eligible']);
    return match (true) {
        $c['state'] === 'cancelled' => 'cancelled',
        $c['state'] === 'completed' => match (true) {
            !$c['has_recording'] => 'ended',
            $open => 'recording',
            $user === null => 'login_to_watch',
            default => 'enrol',
        },
        time() >= strtotime($c['join_opens_at']) => $open ? 'join' : ($user === null ? 'login_to_join' : 'enrol'),
        $user === null => 'login_to_remind',
        $c['eligible'] === false => 'enrol',
        !$student => 'none', // staff accounts cannot set student reminders
        $c['reminder_set'] => 'reminder_set',
        default => 'remind',
    };
}

function present(array $rows, ?array $user): array
{
    $out = array_map(fn($r) => format_live_class($r) + [
        '_course_id' => $r['course_id'] ? (int) $r['course_id'] : null,
        'has_recording' => !empty($r['recording_url']),
    ], $rows);
    $out = with_user_flags($out, $user);
    return array_map(function ($c) use ($user) {
        unset($c['_course_id']);
        $c['cta'] = class_cta($c, $user);
        return $c;
    }, $out);
}

/** Settings and viewer info the class pages need for their texts and buttons. */
function class_page_meta(?array $user): array
{
    return [
        'join_window_minutes' => JOIN_EARLY_MINUTES,
        'reminder_lead_minutes' => setting_int('live_reminder_lead_minutes', 60, 5, 1440),
        'viewer' => ['logged_in' => $user !== null, 'can_set_reminders' => $user !== null && $user['user_type'] === 'student'],
    ];
}

function list_classes(): never
{
    $past = query_str('when', 10) === 'past';
    $where = ['lc.is_published = 1'];
    $params = [];
    $where[] = $past
        ? "(lc.status = 'completed' OR DATE_ADD(lc.starts_at, INTERVAL lc.duration_minutes MINUTE) <= NOW())"
        : "lc.status <> 'completed' AND DATE_ADD(lc.starts_at, INTERVAL lc.duration_minutes MINUTE) > NOW()";
    if ($cats = query_slugs('category')) {
        $where[] = 'cat.slug IN (' . db_placeholders(count($cats)) . ')';
        array_push($params, ...$cats);
    }
    $page = query_int('page', 1, 1, 500);
    $per = 12;
    $sqlWhere = implode(' AND ', $where);
    $total = (int) db_value('SELECT COUNT(*) FROM live_classes lc ' . LIVE_CLASS_JOINS . " WHERE {$sqlWhere}", $params);
    $rows = db_all(
        'SELECT ' . LIVE_CLASS_COLUMNS . ', lc.course_id, lc.recording_url FROM live_classes lc ' . LIVE_CLASS_JOINS . "
         WHERE {$sqlWhere} ORDER BY lc.starts_at " . ($past ? 'DESC' : 'ASC') . " LIMIT {$per} OFFSET " . (($page - 1) * $per),
        $params
    );
    $user = current_user();
    api_ok([
        'items' => present($rows, $user),
        'pagination' => pagination_meta($total, $page, $per),
        'categories' => array_map(fn($c) => ['slug' => $c['slug'], 'name' => $c['name']], fetch_course_categories()),
    ] + class_page_meta($user));
}

function my_classes(): never
{
    $user = require_student();
    $courseIds = accessible_course_ids((int) $user['id']);
    $params = [];
    $courseSql = '';
    if ($courseIds) {
        $courseSql = ' OR lc.course_id IN (' . db_placeholders(count($courseIds)) . ')';
        $params = $courseIds;
    }
    $base = 'SELECT ' . LIVE_CLASS_COLUMNS . ', lc.course_id, lc.recording_url FROM live_classes lc ' . LIVE_CLASS_JOINS . "
        WHERE lc.is_published = 1 AND (lc.access_type IN ('public','registered'){$courseSql})";
    $upcoming = db_all($base . " AND lc.status = 'scheduled' AND DATE_ADD(lc.starts_at, INTERVAL lc.duration_minutes MINUTE) > NOW() ORDER BY lc.starts_at LIMIT 30", $params);
    $past = db_all($base . " AND lc.status <> 'cancelled' AND DATE_ADD(lc.starts_at, INTERVAL lc.duration_minutes MINUTE) <= NOW() ORDER BY lc.starts_at DESC LIMIT 30", $params);
    api_ok(['upcoming' => present($upcoming, $user), 'past' => present($past, $user)] + class_page_meta($user));
}

function load_class(int $id): array
{
    $c = db_one('SELECT * FROM live_classes WHERE id = ? AND is_published = 1', [$id]);
    if (!$c) {
        api_error('Live class not found.', 404, 'not_found');
    }
    return $c;
}

function remind(): never
{
    $user = require_student();
    $in = request_body();
    $c = load_class((int) ($in['id'] ?? 0));
    if (!empty($in['on'])) {
        if ($c['status'] !== 'scheduled' || strtotime($c['starts_at']) <= time()) {
            api_error('Reminders can only be set for upcoming classes.', 409, 'not_upcoming');
        }
        if (!content_access_allowed($user, $c['access_type'], $c['course_id'] ? (int) $c['course_id'] : null)) {
            api_error('This class is for students enrolled in the linked course.', 403, 'not_enrolled');
        }
        db_query('INSERT IGNORE INTO live_class_reminders (user_id, live_class_id) VALUES (?, ?)', [$user['id'], $c['id']]);
        notify((int) $user['id'], 'reminder', 'Reminder set: ' . $c['title'],
            'Starts ' . (new DateTime($c['starts_at']))->format('j M Y, g:i A') . ' (IST).', '/dashboard/live-classes');
    } else {
        db_query('DELETE FROM live_class_reminders WHERE user_id = ? AND live_class_id = ?', [$user['id'], $c['id']]);
    }
    api_ok(['reminder_set' => !empty($in['on'])]);
}

function join_class(): never
{
    $c = load_class((int) (request_body()['id'] ?? 0));
    $user = $c['access_type'] === 'public' ? current_user() : require_login();
    if (!content_access_allowed($user, $c['access_type'], $c['course_id'] ? (int) $c['course_id'] : null)) {
        api_error('This class is for students enrolled in the linked course.', 403, 'not_enrolled');
    }
    if ($c['status'] === 'cancelled') {
        api_error('This class has been cancelled.', 409, 'cancelled');
    }
    $start = strtotime($c['starts_at']);
    $end = $start + (int) $c['duration_minutes'] * 60;
    if (time() < $start - JOIN_EARLY_MINUTES * 60) {
        api_error('The join link opens ' . JOIN_EARLY_MINUTES . ' minutes before the class starts.', 409, 'too_early');
    }
    if (time() > $end) {
        api_error('This class has ended.', 409, 'ended');
    }
    if (!$c['meeting_url'] || !preg_match('#^https://#i', $c['meeting_url'])) {
        api_error('The meeting link has not been published yet. Please check again shortly.', 409, 'no_link');
    }
    if ($user) {
        db_query('INSERT IGNORE INTO live_class_attendance (user_id, live_class_id) VALUES (?, ?)', [$user['id'], $c['id']]);
    }
    api_ok(['meeting_url' => $c['meeting_url']]);
}

function recording(): never
{
    $c = load_class((int) (request_body()['id'] ?? 0));
    $user = $c['access_type'] === 'public' ? current_user() : require_login();
    if (!content_access_allowed($user, $c['access_type'], $c['course_id'] ? (int) $c['course_id'] : null)) {
        api_error('Recordings are available to eligible students only.', 403, 'not_enrolled');
    }
    if (!$c['recording_url'] || !preg_match('#^https://#i', $c['recording_url'])) {
        api_error('No recording is available for this class.', 404, 'not_found');
    }
    api_ok(['recording_url' => $c['recording_url']]);
}
