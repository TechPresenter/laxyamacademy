<?php
// Send in-site notifications (optionally email) to students; trigger live-class reminders.
// Announcement emails are queued in email_log (sent in batches by the scheduler) and respect
// each student's "Important announcements" email preference.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/catalog.php';
require_once APP_ROOT . '/includes/notify.php';

$me = require_staff_page();
require_permission('notifications.send');
$lead = setting_int('live_reminder_lead_minutes', 60, 5, 1440);
$audiences = ['all' => 'All active students', 'course' => 'Students of a course', 'one' => 'One student (by email)'];

if (admin_post()) {
    if (($_POST['_action'] ?? '') === 'reminders') {
        // Same window as the scheduler, so a manual run never sends a reminder earlier than configured.
        $n = send_due_class_reminders();
        audit_log('class_reminders_sent', 'live_classes', null, ['count' => $n, 'lead_minutes' => $lead]);
        flash('success', $n ? "{$n} reminder" . ($n === 1 ? '' : 's') . " sent for classes starting in the next {$lead} minutes." : "No reminders are due for classes starting in the next {$lead} minutes.");
        redirect_to('notifications.php#reminders');
    }
    $title = trim((string) ($_POST['title'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $link = trim((string) ($_POST['link'] ?? ''));
    $button = trim((string) ($_POST['button'] ?? '')) ?: 'Open';
    $audience = array_key_exists((string) ($_POST['audience'] ?? ''), $audiences) ? (string) $_POST['audience'] : 'all';
    $courseId = (int) ($_POST['course_id'] ?? 0);
    $back = 'notifications.php?' . http_build_query(['audience' => $audience, 'course' => $courseId ?: null]) . '#send';
    if ($title === '' || mb_strlen($title) > 160 || mb_strlen($message) > 500 || mb_strlen($button) > 40 || ($link !== '' && !preg_match('#^(/[^/\\\\]|/$|https://)#', $link))) {
        flash('error', 'Enter a title (max 160), a message (max 500), an optional link starting with / or https:// and a button text up to 40 characters.');
        redirect_to($back);
    }
    $cols = 'u.id, u.email, u.name, p.notify_email, p.notify_class_reminders, p.notify_prefs';
    $recipients = match ($audience) {
        'course' => db_all("SELECT {$cols} FROM course_enrolments e JOIN users u ON u.id = e.user_id LEFT JOIN student_profiles p ON p.user_id = u.id WHERE e.course_id = ? AND e.status = 'active' AND u.status = 'active'", [$courseId]),
        'one' => db_all("SELECT {$cols} FROM users u LEFT JOIN student_profiles p ON p.user_id = u.id WHERE u.email = ? AND u.user_type = 'student' AND u.status = 'active'", [strtolower(trim((string) ($_POST['email'] ?? '')))]),
        default => db_all("SELECT {$cols} FROM users u LEFT JOIN student_profiles p ON p.user_id = u.id WHERE u.user_type = 'student' AND u.status = 'active'"),
    };
    if (!$recipients) {
        flash('error', $audience === 'course' ? 'This course has no active students.' : 'No matching active students.');
        redirect_to($back);
    }
    $absLink = email_absolute_url($link);
    [$queued, $optedOut, $direct] = [0, 0, false];
    foreach ($recipients as $r) {
        notify((int) $r['id'], 'announcement', $title, $message ?: null, $link ?: null);
        if (empty($_POST['email_too']) || empty($r['email'])) {
            continue;
        }
        // A message to one named student is a direct message; announcements honour the student's preference.
        if ($audience !== 'one' && !student_email_allowed('announcement', $r)) {
            $optedOut++;
            continue;
        }
        $html = mail_template($title, mail_paragraphs("Hello {$r['name']},\n\n" . ($message !== '' ? $message : $title)), $absLink !== '' ? $button : null, $absLink ?: null, [
            'transactional' => $audience === 'one', 'manage_url' => app_url('dashboard/profile'), 'eyebrow' => 'Announcement',
            'preheader' => mb_strimwidth($message !== '' ? $message : $title, 0, 140, '…'),
        ]);
        $meta = ['template_key' => 'announcement', 'user_id' => (int) $r['id'], 'to_name' => $r['name']];
        if ($audience === 'one') {
            $direct = send_mail((string) $r['email'], $title, $html, $meta);
        } else {
            queue_email((string) $r['email'], $title, $html, $meta);
        }
        $queued++;
    }
    // Deliver a first small batch now; the scheduler sends the rest (50 every 15 minutes).
    $now = $audience !== 'one' && $queued ? process_email_queue(10) : ['processed' => 0];
    audit_log('notification_sent', 'notifications', null, ['audience' => $audience, 'course_id' => $courseId ?: null, 'count' => count($recipients), 'emails' => $queued, 'opted_out' => $optedOut]);
    $msg = 'In-app notification sent to ' . count($recipients) . ' student' . (count($recipients) === 1 ? '' : 's') . '.';
    if (!empty($_POST['email_too'])) {
        if ($audience === 'one') {
            $msg .= $queued ? ($direct ? ' Email delivered.' : (mail_configured() ? ' Email not confirmed as sent - see Email Logs (failures are retried automatically).' : ' Email saved to the mail log (SMTP is not configured).')) : '';
        } else {
            $msg .= " {$queued} email" . ($queued === 1 ? '' : 's') . ' queued' . ($optedOut ? ", {$optedOut} skipped (student turned off announcement emails)" : '') . '.';
            $msg .= $queued ? ' ' . $now['processed'] . ' processed now; the rest go out within 15 minutes - track them in Email Logs.' : '';
        }
    }
    flash('success', $msg);
    redirect_to('notifications.php#history');
}

$selAudience = array_key_exists((string) ($_GET['audience'] ?? ''), $audiences) ? (string) $_GET['audience'] : 'all';
$selCourse = (int) ($_GET['course'] ?? 0);
$courses = db_all("SELECT c.id, c.title, (SELECT COUNT(*) FROM course_enrolments e WHERE e.course_id = c.id AND e.status = 'active') AS students FROM courses c ORDER BY c.title");
$recent = db_all("SELECT title, MAX(created_at) AS sent, COUNT(*) AS recipients, SUM(read_at IS NOT NULL) AS opened FROM notifications WHERE type = 'announcement' GROUP BY title ORDER BY sent DESC LIMIT 15");
try {
    $mailStats = array_column(db_all("SELECT subject, COUNT(*) AS total, SUM(status = 'sent') AS sent, SUM(status = 'skipped') AS logged, SUM(status IN ('queued','sending') OR (status = 'failed' AND next_attempt_at IS NOT NULL)) AS pending
        FROM email_log WHERE template_key = 'announcement' AND created_at > NOW() - INTERVAL 90 DAY GROUP BY subject"), null, 'subject');
} catch (Throwable) {
    $mailStats = []; // email_log arrives with migration 008
}
$pendingReminders = (int) db_value("SELECT COUNT(*) FROM live_class_reminders r JOIN live_classes lc ON lc.id = r.live_class_id WHERE r.notified_at IS NULL AND lc.status = 'scheduled' AND lc.is_published = 1 AND lc.starts_at BETWEEN NOW() AND NOW() + INTERVAL ? MINUTE", [$lead]);
$upcomingReminders = (int) db_value("SELECT COUNT(*) FROM live_class_reminders r JOIN live_classes lc ON lc.id = r.live_class_id WHERE r.notified_at IS NULL AND lc.status = 'scheduled' AND lc.starts_at > NOW()");

admin_start('Notifications', null, 'notifications', ['head' => true, 'subtitle' => 'Messages appear in students’ dashboards; emails are queued and tracked in Email Logs.',
    'actions' => can('notifications.send') ? '<a class="btn btn-light" href="email-logs.php">' . icon('mail') . ' Email logs</a><a class="btn btn-light" href="templates.php">' . icon('file') . ' Email templates</a>' : '']);
?>
<style>
  .nt-grid { display: grid; gap: 18px; grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr); align-items: start; }
  @media (max-width: 960px) { .nt-grid { grid-template-columns: 1fr; } }
  .nt-aud { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
  .nt-aud label { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border: 1.5px solid var(--line); border-radius: 12px; cursor: pointer; font-weight: 600; font-size: 13px; transition: border-color .15s, background .15s; }
  .nt-aud label small { display: block; font-weight: 500; color: var(--muted); margin-top: 2px; }
  .nt-aud input { margin-top: 2px; accent-color: var(--primary); }
  .nt-aud label:has(input:checked) { border-color: var(--primary); background: var(--primary-soft); }
  .nt-aud label:has(input:focus-visible) { outline: 2px solid var(--primary); outline-offset: 2px; }
  @media (max-width: 700px) { .nt-aud { grid-template-columns: 1fr; } }
  .nt-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; }
</style>
<div class="nt-grid">
  <form method="post" class="card" id="send">
    <?= csrf_field() ?><input type="hidden" name="_action" value="send">
    <div class="card-head"><h2>Send a notification</h2><span class="muted" style="font-size:12px">In-app + optional email</span></div>
    <div class="card-pad form">
      <fieldset class="field full" style="border:0;padding:0;margin:0">
        <legend class="lbl">Audience</legend>
        <div class="nt-aud">
          <?php foreach (['all' => 'Every active student account', 'course' => 'Active enrolments of one course', 'one' => 'A direct message to one account'] as $k => $hint): ?>
          <label><input type="radio" name="audience" value="<?= $k ?>"<?= $selAudience === $k ? ' checked' : '' ?> data-audience><span><?= e($audiences[$k]) ?><small><?= e($hint) ?></small></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <div class="field full" data-for-audience="course"><label for="crs">Course</label>
        <select id="crs" name="course_id"><?php foreach ($courses as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $selCourse === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['title']) ?> (<?= (int) $c['students'] ?> active)</option><?php endforeach; ?></select></div>
      <div class="field full" data-for-audience="one"><label for="em">Student email</label><input id="em" type="email" name="email" autocomplete="off" placeholder="student@example.com"></div>
      <div class="field full"><label for="tt">Title</label><input id="tt" type="text" name="title" maxlength="160" required data-counter="90,160"></div>
      <div class="field full"><label for="mm">Message</label><textarea id="mm" name="message" maxlength="500" rows="4" data-counter="400,500"></textarea></div>
      <div class="field"><label for="ln">Link (optional)</label><input id="ln" type="text" name="link" placeholder="/courses or https://…" pattern="(/.*|https://.+)"><div class="hint">Shown as the button in the email and as “Open” in the dashboard.</div></div>
      <div class="field"><label for="bt">Email button text</label><input id="bt" type="text" name="button" maxlength="40" placeholder="Open"></div>
      <div class="field full">
        <label class="switch"><input type="checkbox" name="email_too" value="1"><span class="track"></span> Also send by email</label>
        <div class="hint"><?= mail_configured()
            ? 'Emails are queued and sent in batches; students who turned off “Important announcements” are skipped.'
            : 'SMTP is not configured - emails will be recorded in Email Logs and written to the mail log only.' ?></div>
      </div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" data-confirm="Send this notification now?"><?= icon('megaphone') ?> Send</button></div>
  </form>
  <div>
    <div class="card card-pad" style="margin-bottom:18px" id="reminders">
      <h2 style="font-size:16px;display:flex;gap:8px;align-items:center"><?= icon('bell') ?> Live class reminders</h2>
      <p class="muted"><strong style="color:var(--navy)"><?= $pendingReminders ?></strong> reminder<?= $pendingReminders === 1 ? '' : 's' ?> due now for classes starting in the next <?= $lead ?> minutes · <?= $upcomingReminders ?> waiting for later classes.</p>
      <?= action_form('reminders', [], icon('bell') . ' Send due reminders now', 'btn btn-primary') ?>
      <p class="muted d2-wrap" style="font-size:12px;margin-top:10px">Reminders go out <?= $lead ?> minutes before each class (change it in Live Class Settings). The scheduler sends them automatically every 15 minutes: <code>php database/cron.php</code></p>
    </div>
    <div class="card" id="history">
      <div class="card-head"><h2>Recent announcements</h2></div>
      <?php if ($recent): ?>
      <div class="table-wrap"><table class="t stack"><thead><tr><th>Announcement</th><th>In-app</th><th>Email</th></tr></thead><tbody>
      <?php foreach ($recent as $r): $m = $mailStats[$r['title']] ?? null; ?><tr>
        <td data-label="Announcement"><div><strong><?= e($r['title']) ?></strong><span class="d2-sub"><?= e(fmt_dt($r['sent'])) ?></span></div></td>
        <td data-label="In-app"><div><?= (int) $r['recipients'] ?> sent<span class="d2-sub"><?= (int) $r['opened'] ?> read</span></div></td>
        <td data-label="Email"><?php if ($m): ?><div><a href="email-logs.php?<?= e(http_build_query(['template' => 'announcement', 'q' => $r['title']])) ?>"><?= (int) $m['total'] ?> email<?= (int) $m['total'] === 1 ? '' : 's' ?></a><span class="d2-sub"><?= (int) $m['sent'] ?> sent<?= $m['logged'] ? ' · ' . (int) $m['logged'] . ' logged' : '' ?><?= $m['pending'] ? ' · ' . (int) $m['pending'] . ' pending' : '' ?></span></div><?php else: ?><span class="muted">In-app only</span><?php endif; ?></td>
      </tr><?php endforeach; ?>
      </tbody></table></div>
      <?php else: ?>
      <div class="empty"><span class="empty-ic"><?= icon('megaphone') ?></span><strong>No announcements yet</strong><p>Notifications you send from this screen are listed here with read and email counts.</p></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
(() => {
  const radios = document.querySelectorAll('[data-audience]');
  const sync = () => {
    const v = document.querySelector('[data-audience]:checked')?.value || 'all';
    document.querySelectorAll('[data-for-audience]').forEach((el) => {
      const on = el.dataset.forAudience === v;
      el.hidden = !on;
      el.querySelectorAll('input, select').forEach((i) => { i.disabled = !on; i.required = on && i.type === 'email'; });
    });
  };
  radios.forEach((r) => r.addEventListener('change', sync));
  sync();
})();
</script>
<?php admin_end();
