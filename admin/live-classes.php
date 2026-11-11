<?php
// Live classes: schedule, meeting links (released to eligible students shortly before start), recordings.
// Rescheduling or cancelling a class tells the students concerned (in-app + email) and re-arms reminders.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';

$joinWindow = setting_int('live_join_window_minutes', 15, 0, 240); // admin → Settings → Live classes

// Sidebar shortcuts map onto real filters so search / paging keep them:
//   ?view=links → upcoming classes still missing a meeting link · ?status=cancelled → every cancelled class
if (($_GET['view'] ?? '') === 'links' && !isset($_GET['link'])) {
    $_GET['link'] = 'missing';
}
$byInstructor = ($_GET['view'] ?? '') === 'instructors';
$cancelledView = ($_GET['status'] ?? '') === 'cancelled';
$past = ($_GET['when'] ?? '') === 'past';

/**
 * Tell students about a reschedule / cancellation: everyone who set a reminder plus, for a class
 * linked to a course, every student with an active enrolment. In-app always; email when the student
 * allows class-reminder emails (profile preference). Returns [students notified, emails sent or queued].
 */
function live_class_change_notice(int $id, array $d, array $old, string $kind): array
{
    require_once APP_ROOT . '/includes/notify.php';
    $params = [$id];
    $enrolled = '';
    if (!empty($d['course_id'])) {
        $enrolled = " UNION ALL SELECT user_id, 0 FROM course_enrolments WHERE course_id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())";
        $params[] = (int) $d['course_id'];
    }
    $people = db_all(
        "SELECT u.id, u.name, u.email, p.notify_email, p.notify_class_reminders, p.notify_prefs, a.reminded
         FROM (SELECT user_id, MAX(reminded) AS reminded FROM (SELECT user_id, 1 AS reminded FROM live_class_reminders WHERE live_class_id = ?{$enrolled}) s GROUP BY user_id) a
         JOIN users u ON u.id = a.user_id AND u.status = 'active' AND u.user_type = 'student'
         LEFT JOIN student_profiles p ON p.user_id = u.id",
        $params
    );
    if (!$people) {
        return [0, 0];
    }
    $fmt = fn(string $dt) => date('D, j M Y, g:i A', strtotime($dt));
    $joinWindow = setting_int('live_join_window_minutes', 15, 0, 240);
    $link = '/dashboard/live-classes';
    if ($kind === 'cancelled') {
        $subject = 'Class cancelled: ' . $d['title'];
        $inapp = 'The class on ' . $fmt($old['starts_at']) . ' (IST) has been cancelled.';
    } else {
        $subject = 'Class rescheduled: ' . $d['title'];
        $inapp = 'New time: ' . $fmt($d['starts_at']) . ' (IST). It was ' . $fmt($old['starts_at']) . '.';
    }
    $sendNow = count($people) <= 25; // larger lists stay queued for the scheduler (email_log)
    $emails = 0;
    foreach ($people as $u) {
        notify((int) $u['id'], 'reminder', $subject, $inapp, $link);
        if (empty($u['email']) || !student_email_allowed('class_reminder', $u)) {
            continue;
        }
        if ($kind === 'cancelled') {
            $text = "Hello {$u['name']},\n\n{$d['title']}, scheduled for " . $fmt($old['starts_at']) . " (IST), has been cancelled."
                . (!empty($d['announcement']) ? "\n\n{$d['announcement']}" : '')
                . "\n\nWe are sorry for the change. New classes appear on your live classes page as soon as they are scheduled.";
        } else {
            $text = "Hello {$u['name']},\n\n{$d['title']} has moved to " . $fmt($d['starts_at']) . ' (IST). It was scheduled for ' . $fmt($old['starts_at']) . '.'
                . "\n\nThe join button opens " . ($joinWindow ? "{$joinWindow} minutes before the class starts" : 'when the class starts') . '.'
                . ($u['reminded'] ? ' Your reminder has moved to the new time as well.' : ' Set a reminder on the live classes page to get an alert before it starts.');
        }
        $html = mail_template($subject, mail_paragraphs($text), 'Open live classes', $link, [
            'eyebrow' => 'Live class', 'transactional' => false, 'manage_url' => '/dashboard/profile',
            'preheader' => mb_strimwidth($inapp, 0, 140, '…'),
        ]);
        queue_email((string) $u['email'], $subject, $html, [
            'template_key' => 'class_' . $kind, 'user_id' => (int) $u['id'], 'to_name' => (string) $u['name'],
            'related' => ['live_class', $id], 'send_now' => $sendNow,
        ]);
        $emails++;
    }
    audit_log('live_class_' . $kind . '_notified', 'live_classes', $id, ['students' => count($people), 'emails' => $emails]);
    return [count($people), $emails];
}

// The Upcoming / Past split only scopes the list. Opening, saving or deleting one class works whatever
// its time, so Edit links from the calendar, reports and search (which mostly show past classes) resolve.
$singleRecord = ($_GET['action'] ?? 'list') !== 'list' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$where = match (true) {
    $singleRecord, $cancelledView => '1=1', // one record, or cancelled classes past and future
    $past => 'DATE_ADD(t.starts_at, INTERVAL t.duration_minutes MINUTE) <= NOW()',
    default => 'DATE_ADD(t.starts_at, INTERVAL t.duration_minutes MINUTE) > NOW()',
};
$listCounts = db_one("SELECT COALESCE(SUM(DATE_ADD(starts_at, INTERVAL duration_minutes MINUTE) > NOW()), 0) AS upcoming,
    COALESCE(SUM(DATE_ADD(starts_at, INTERVAL duration_minutes MINUTE) <= NOW()), 0) AS past, COALESCE(SUM(status = 'cancelled'), 0) AS cancelled FROM live_classes");

crud([
    'table' => 'live_classes',
    'title' => 'Live Classes',
    'singular' => 'live class',
    'permission' => 'live.manage',
    'active' => 'live-classes',
    'subtitle' => match (true) {
        ($_GET['link'] ?? '') === 'missing' => 'Classes without a meeting link yet. Students can only join once a https:// link is added.',
        $byInstructor => 'Upcoming classes grouped by faculty. Classes without faculty are listed first.',
        default => 'Times are in IST. Meeting links are never shown publicly - only eligible students get them, from ' . ($joinWindow ? $joinWindow . ' minutes before start' : 'the start time') . ' (Settings → Live classes).',
    },
    'from' => 'live_classes t LEFT JOIN courses c ON c.id = t.course_id LEFT JOIN instructors i ON i.id = t.instructor_id',
    'select' => 't.*, c.title AS course_title, i.name AS instructor_name,
        (SELECT COUNT(*) FROM live_class_reminders r WHERE r.live_class_id = t.id) AS reminders,
        (SELECT COUNT(*) FROM live_class_attendance a WHERE a.live_class_id = t.id) AS attendees',
    'where' => $where,
    'order' => $byInstructor ? 'i.name IS NOT NULL, i.name, t.starts_at' : ($past || $cancelledView ? 't.starts_at DESC' : 't.starts_at ASC'),
    'search' => ['t.title', 'c.title', 'i.name'],
    'filters' => [
        'status' => ['col' => 't.status', 'label' => 'Statuses', 'options' => ['scheduled' => 'Scheduled', 'cancelled' => 'Cancelled', 'completed' => 'Completed']],
        'link' => ['col' => "IF(t.meeting_url IS NULL OR t.meeting_url = '', 'missing', 'added')", 'label' => 'Meeting links', 'options' => ['missing' => 'Link missing', 'added' => 'Link added']],
        'instructor' => ['col' => 'COALESCE(t.instructor_id, 0)', 'label' => 'Faculty', 'options' => fn() => options_from('SELECT id, name AS label FROM instructors ORDER BY name') + ['0' => 'No faculty assigned']],
    ],
    'sortable' => ['starts_at' => 't.starts_at'],
    'tabs' => admin_tabs([
        'upcoming' => ['label' => 'Upcoming', 'icon' => 'calendar', 'count' => (int) $listCounts['upcoming'], 'href' => 'live-classes.php'],
        'past' => ['label' => 'Past', 'icon' => 'history', 'count' => (int) $listCounts['past'], 'href' => 'live-classes.php?when=past'],
        'cancelled' => ['label' => 'Cancelled', 'icon' => 'alert', 'count' => (int) $listCounts['cancelled'], 'href' => 'live-classes.php?status=cancelled'],
    ], $cancelledView ? 'cancelled' : ($past ? 'past' : 'upcoming'), ['label' => 'Class lists']),
    'columns' => [
        'title' => ['Class', fn($r) => '<div class="d1-main"><a class="d1-t" href="' . e(url_with(['action' => 'edit', 'id' => $r['id'], 'page' => null])) . '" title="' . e($r['title']) . '">' . e($r['title']) . '</a><div class="d1-sub">'
            . '<span>' . e($r['course_title'] ?? 'No course') . '</span><span>' . e($r['instructor_name'] ?? 'No faculty') . '</span>'
            . (!empty($r['is_demo']) ? '<span class="badge b-demo">Demo</span>' : '') . '</div></div>'],
        'starts_at' => ['When', fn($r) => '<span class="d1-when"><strong>' . e(fmt_dt($r['starts_at'], 'D, j M Y')) . '</strong><small>' . e(fmt_dt($r['starts_at'], 'g:i A')) . ' · ' . (int) $r['duration_minutes'] . ' min</small>'
            . ($r['rescheduled_from'] ? '<small title="Rescheduled">Moved from ' . e(fmt_dt($r['rescheduled_from'], 'j M, g:i A')) . '</small>' : '') . '</span>'],
        'access_type' => ['Access', fn($r) => match ($r['access_type']) {
            'public' => '<span class="badge b-green">Public</span>',
            'registered' => '<span class="badge b-blue">Logged-in</span>',
            default => '<span class="badge b-purple">Course students</span>',
        }],
        'meeting_url' => ['Link', fn($r) => $r['meeting_url'] ? '<div class="d1-stack"><span class="badge b-green">Added</span>' . ($r['meeting_provider'] ? '<small>' . e(['zoom' => 'Zoom', 'google_meet' => 'Google Meet', 'youtube' => 'YouTube Live', 'other' => 'Other'][$r['meeting_provider']] ?? $r['meeting_provider']) . '</small>' : '') . '</div>' : '<span class="badge b-amber">Missing</span>'],
        'reminders' => ['Interest', fn($r) => '<div class="d1-num">' . (int) $r['reminders'] . ' reminder' . ((int) $r['reminders'] === 1 ? '' : 's') . '<small>' . (int) $r['attendees'] . ' joined</small></div>'],
        'status' => ['Status', fn($r) => '<div class="d1-stack">' . badge($r['status']) . (!$r['is_published'] ? '<span class="badge b-gray" title="Not shown on the website">Hidden</span>' : '') . '</div>'],
    ],
    'before_save' => function (array $d, ?array $old) {
        if ($old && $old['starts_at'] !== $d['starts_at'] && $old['status'] === 'scheduled') {
            $d['rescheduled_from'] = $old['starts_at'];
        }
        if (!$old) {
            $d['created_by'] = $GLOBALS['__admin_me']['id'] ?? (current_user()['id'] ?? null);
        }
        if ($d['course_id'] && !$d['category_id']) {
            $d['category_id'] = db_value('SELECT category_id FROM courses WHERE id = ?', [$d['course_id']]);
        }
        if ($d['access_type'] === 'enrolled' && !$d['course_id']) {
            throw new CrudError(['course_id' => 'Choose the course whose students may join, or change "Who can join".']);
        }
        return $d;
    },
    'after_save' => function (int $id, array $d, ?array $old) {
        if (!$old) {
            return;
        }
        $cancelled = $d['status'] === 'cancelled' && $old['status'] !== 'cancelled';
        $moved = !$cancelled && $d['status'] === 'scheduled' && $old['starts_at'] !== $d['starts_at'];
        if ($moved) {
            // Re-arm reminders: the scheduler sends them again before the new start time.
            db_query('UPDATE live_class_reminders SET notified_at = NULL WHERE live_class_id = ?', [$id]);
        }
        if (($cancelled || $moved) && (int) $d['is_published'] === 1) {
            [$students, $emails] = live_class_change_notice($id, $d, $old, $cancelled ? 'cancelled' : 'rescheduled');
            flash('info', $students
                ? "{$students} student" . ($students === 1 ? '' : 's') . ' notified in-app' . ($emails ? " and {$emails} by email" . (mail_configured() ? '' : ' (SMTP is not set up yet, so emails are only recorded in the email log)') : '') . '.'
                : 'No students to notify (no reminders or enrolments).');
        }
    },
    'fields' => [
        'title' => ['label' => 'Title', 'required' => true, 'max' => 180],
        'course_id' => ['label' => 'Course', 'type' => 'select', 'options' => fn() => options_from("SELECT id, title AS label FROM courses ORDER BY title")],
        'category_id' => ['label' => 'Exam category', 'type' => 'select', 'options' => fn() => options_from('SELECT id, name AS label FROM course_categories ORDER BY sort_order')],
        'instructor_id' => ['label' => 'Faculty', 'type' => 'select', 'options' => fn() => options_from("SELECT id, name AS label FROM instructors WHERE status = 'active' ORDER BY name")],
        'starts_at' => ['label' => 'Starts at (IST)', 'type' => 'datetime', 'required' => true, 'hint' => 'Changing the time of a scheduled class notifies students with a reminder and, for a course class, its enrolled students.'],
        'duration_minutes' => ['label' => 'Duration (minutes)', 'type' => 'number', 'min' => 10, 'max' => 600, 'default' => 60, 'required' => true],
        'access_type' => ['label' => 'Who can join', 'type' => 'select', 'required' => true, 'default' => 'enrolled', 'options' => ['enrolled' => 'Students enrolled in the course', 'registered' => 'Any logged-in student', 'public' => 'Anyone (public)']],
        'meeting_provider' => ['label' => 'Platform', 'type' => 'select', 'options' => ['zoom' => 'Zoom', 'google_meet' => 'Google Meet', 'youtube' => 'YouTube Live', 'other' => 'Other']],
        'meeting_url' => ['label' => 'Meeting link (https://)', 'type' => 'url', 'full' => true, 'hint' => 'Private - released only to eligible students, from ' . ($joinWindow ? $joinWindow . ' minutes before start' : 'the start time') . '.'],
        'recording_url' => ['label' => 'Recording link (https://)', 'type' => 'url', 'full' => true],
        'announcement' => ['label' => 'Announcement shown on the class card', 'max' => 500, 'full' => true],
        'description' => ['label' => 'Description', 'type' => 'textarea'],
        'status' => ['label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'scheduled', 'options' => ['scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'], 'hint' => 'Cancelling notifies the same students as a reschedule.'],
        'is_published' => ['label' => 'Published (visible on website)', 'type' => 'checkbox', 'default' => 1],
        'is_demo' => ['label' => 'Demo content', 'type' => 'checkbox'],
    ],
]);
