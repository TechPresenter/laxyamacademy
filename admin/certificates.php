<?php
// Course completion certificates: issue for completed enrolments; students view/print them from their dashboard.
// Issuing sends the "certificate_issued" template (email + in-app; email respects the student's course-updates preference).
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/notify.php';

$me = require_staff_page();
require_permission('enrolments.manage');

if (admin_post()) {
    $ids = array_slice(array_unique(array_map('intval', (array) ($_POST['enrolments'] ?? []))), 0, 500);
    $issued = 0;
    $emailed = 0; // accepted by the mail server
    $logged = 0;  // email_log rows written (sent, saved to mail.log or queued for retry)
    foreach ($ids as $eid) {
        $en = db_one('SELECT e.user_id, e.course_id, c.title FROM course_enrolments e JOIN courses c ON c.id = e.course_id WHERE e.id = ? AND e.completed_at IS NOT NULL', [$eid]);
        if (!$en || db_value('SELECT 1 FROM certificates WHERE user_id = ? AND course_id = ?', [$en['user_id'], $en['course_id']])) {
            continue;
        }
        $no = 'LXA-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
        db_query('INSERT INTO certificates (user_id, course_id, certificate_no, issued_by) VALUES (?, ?, ?, ?)', [$en['user_id'], $en['course_id'], $no, $me['id']]);
        $certId = (int) db()->lastInsertId();
        $issued++;
        try {
            $GLOBALS['__last_email_log_id'] = null;
            $channels = send_template('certificate_issued', (int) $en['user_id'], [
                'course' => $en['title'], 'link' => '/dashboard/certificates', 'button_label' => 'View certificate', 'related' => ['certificate', $certId],
            ]);
            $emailed += in_array('email', $channels, true) ? 1 : 0;
            $logged += last_email_log_id() ? 1 : 0;
        } catch (Throwable $e) {
            error_log('[laxyam] certificate email failed: ' . $e->getMessage());
        }
    }
    audit_log('certificates_issued', 'certificates', null, ['count' => $issued, 'emailed' => $emailed, 'logged' => $logged], 'warning');
    $n = fn(int $count, string $noun) => $count . ' ' . $noun . ($count === 1 ? '' : 's');
    $mailNote = '';
    if ($issued) {
        $mailNote = mail_configured()
            ? ' ' . $n($emailed, 'email') . ' delivered' . ($logged > $emailed ? ', ' . ($logged - $emailed) . ' not confirmed yet (see Email Logs; failures are retried automatically)' : '') . '.'
            : ($logged ? ' ' . $n($logged, 'email') . ' saved to the mail log (SMTP is not configured).' : '');
        $mailNote .= $issued > $logged ? ' ' . $n($issued - $logged, 'student') . ' will not get an email (course-update emails are turned off by the student or on the template).' : '';
    }
    flash($issued ? 'success' : 'info', $issued ? $n($issued, 'certificate') . ' issued.' . $mailNote : 'No new certificates were issued (already issued or not completed).');
    redirect_to('certificates.php');
}

$eligible = db_all("SELECT e.id, e.completed_at, u.name, u.email, c.title FROM course_enrolments e JOIN users u ON u.id = e.user_id JOIN courses c ON c.id = e.course_id
    WHERE e.completed_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM certificates ce WHERE ce.user_id = e.user_id AND ce.course_id = e.course_id) ORDER BY e.completed_at DESC");
$issued = db_all('SELECT ce.id, ce.certificate_no, ce.issued_at, u.name, c.title FROM certificates ce JOIN users u ON u.id = ce.user_id JOIN courses c ON c.id = ce.course_id ORDER BY ce.id DESC LIMIT 50');
try { // email_log arrives with migration 008
    $mailed = $issued ? array_column(db_all("SELECT related_id, status FROM email_log WHERE template_key = 'certificate_issued' AND related_type = 'certificate' AND related_id IN (" . db_placeholders(count($issued)) . ') ORDER BY id', array_column($issued, 'id')), 'status', 'related_id') : [];
} catch (Throwable) {
    $mailed = [];
}

admin_start('Certificates', null, 'certificates', ['head' => true, 'subtitle' => 'Students become eligible when they complete 100% of a course. Issuing emails the student a link to their certificate.']);
?>
<form method="post" class="card" style="margin-bottom:18px" data-bulk>
  <?= csrf_field() ?>
  <div class="card-head"><div><h2><?= icon('award') ?> Ready to issue <span class="tab-count<?= $eligible ? ' tc-amber' : '' ?>"><?= count($eligible) ?></span></h2><p class="muted">Students who completed 100% of a course and have no certificate yet.</p></div><?php if ($eligible): ?><button class="btn btn-primary btn-sm" data-confirm="Issue certificates to the selected students and email them?"><?= icon('award') ?> Issue selected (<span data-bulk-count><?= count($eligible) ?></span>)</button><?php endif; ?></div>
  <?php if (!$eligible): ?>
  <div class="empty"><span class="empty-ic"><?= icon('check-circle') ?></span><strong>No one is waiting for a certificate</strong><p>When a student completes 100% of a course, they appear here so you can issue their certificate.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="t stack"><thead><tr><th style="width:36px"><input type="checkbox" data-check-all checked aria-label="Select all"></th><th scope="col">Student</th><th scope="col">Course</th><th scope="col">Completed</th></tr></thead><tbody>
  <?php foreach ($eligible as $e): ?><tr><td class="sel"><input type="checkbox" name="enrolments[]" value="<?= (int) $e['id'] ?>" checked data-row-check aria-label="Select <?= e($e['name']) ?>"></td>
    <td data-label="Student"><div class="d1-main sm"><span class="d1-t"><?= e($e['name']) ?></span><div class="d1-sub"><span><?= e($e['email']) ?></span></div></div></td>
    <td data-label="Course"><?= e($e['title']) ?></td><td data-label="Completed" class="d1-when"><?= e(fmt_dt($e['completed_at'], 'j M Y')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</form>
<div class="card">
  <div class="card-head"><h2><?= icon('history') ?> Recently issued</h2><?php if (can('notifications.send') && $issued): ?><a class="btn btn-light btn-sm" href="email-logs.php?template=certificate_issued"><?= icon('mail') ?> Email log</a><?php endif; ?></div>
  <?php if (!$issued): ?>
  <div class="empty"><span class="empty-ic"><?= icon('award') ?></span><strong>No certificates issued yet</strong><p>Issued certificates are listed here with their number and email status.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="t stack"><thead><tr><th scope="col">Certificate</th><th scope="col">Student</th><th scope="col">Course</th><th scope="col">Issued</th><th scope="col">Email</th></tr></thead><tbody>
  <?php foreach ($issued as $c): ?><tr><td data-label="Certificate"><code><?= e($c['certificate_no']) ?></code></td><td data-label="Student"><strong><?= e($c['name']) ?></strong></td><td data-label="Course"><?= e($c['title']) ?></td><td data-label="Issued" class="d1-when"><?= e(fmt_dt($c['issued_at'], 'j M Y')) ?></td><td data-label="Email"><?= match ($mailed[$c['id']] ?? null) {
      'sent' => '<span class="badge b-green">Sent</span>',
      'skipped' => '<span class="badge b-gray" title="SMTP not configured - saved to the mail log">Logged only</span>',
      'failed' => '<span class="badge b-red">Failed</span>',
      'queued', 'sending' => '<span class="badge b-amber">Queued</span>',
      default => '<span class="muted" title="No email (student preference or older certificate)">No email</span>',
  } ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php admin_end();
