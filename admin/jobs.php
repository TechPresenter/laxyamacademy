<?php
// Govt job alerts, careers openings (incl. subject-wise faculty positions) and applications:
// review, shortlist, interviews, contact and an activity timeline. Resumes are private downloads.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';
require_once APP_ROOT . '/includes/recruitment.php';

$me = require_staff_page();
require_permission('cms.manage');

// The three screens are reached from the Jobs & Recruitment module tab bar (admin_start), so no second tab strip here.
$tab = current_tab(['alerts' => 'Govt Job Alerts', 'openings' => 'Careers Openings', 'applications' => 'Applications']);

// Authorised resume download (?resume=ID) or in-browser view of a PDF resume (&inline=1)
if (isset($_GET['resume'])) {
    $rid = (int) $_GET['resume'];
    $app = db_one('SELECT name, resume_path FROM job_applications WHERE id = ?', [$rid]);
    $path = $app ? private_file_path($app['resume_path']) : null;
    if (!$path) {
        http_response_code(404);
        exit('Resume not found.');
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $inline = !empty($_GET['inline']) && $ext === 'pdf'; // the stored extension comes from the verified file type
    audit_log($inline ? 'resume_viewed' : 'resume_downloaded', 'job_applications', $rid);
    stream_file($path, 'resume-' . slugify($app['name']) . '.' . $ext, $inline);
}

$common = ['permission' => 'cms.manage', 'active' => 'jobs', 'tabs' => '', 'title' => [
    'alerts' => 'Govt Job Alerts', 'openings' => 'Careers Openings', 'applications' => 'Job Applications',
][$tab]];

if ($tab === 'openings') {
    crud($common + [
        'table' => 'job_openings', 'singular' => 'opening',
        'select' => 't.*, (SELECT COUNT(*) FROM job_applications a WHERE a.opening_id = t.id) AS apps',
        'order' => 't.subject IS NULL, t.id DESC',
        'search' => ['t.title', 't.department'],
        'filters' => [
            'kind' => ['col' => "IF(t.subject IS NULL, 'other', 'faculty')", 'label' => 'Types', 'options' => ['faculty' => 'Faculty positions', 'other' => 'Other roles']],
            'status' => ['col' => 't.status', 'label' => 'Statuses', 'options' => ['open' => 'Open', 'draft' => 'Draft', 'closed' => 'Closed']],
        ],
        // The Careers page text (intro, sections) is edited in the CMS.
        'header_actions' => '<a class="btn btn-light" href="cms.php?tab=pages&amp;section=page_careers">' . icon('edit') . ' Careers page text</a>',
        'columns' => [
            'title' => ['Role', fn($r) => '<div class="d2-minw"><strong>' . e($r['title']) . '</strong>' . demo_badge($r) . '<span class="d2-sub">' . e(implode(' · ', array_filter([$r['subject'] ? 'Faculty · ' . lookup_label('faculty_subject', $r['subject']) : ($r['department'] ? lookup_label('department', $r['department']) : ''), $r['location']]))) . '</span></div>'],
            'employment_type' => ['Type', fn($r) => '<div class="d2-chips">' . implode('', array_map(fn($l) => '<span class="badge b-blue">' . e($l) . '</span>', opening_employment_types($r['employment_type']))) . '</div>'],
            'apps' => ['Applications', fn($r) => (int) $r['apps'] ? '<a href="?tab=applications&amp;opening=' . (int) $r['id'] . '">' . (int) $r['apps'] . ' application' . ((int) $r['apps'] === 1 ? '' : 's') . '</a>' : '<span class="muted">None yet</span>'],
            'status' => ['Status', fn($r) => '<div>' . badge($r['status']) . ($r['closes_at'] ? '<span class="d2-sub">closes ' . e(fmt_dt($r['closes_at'], 'j M Y')) . '</span>' : '') . '</div>'],
        ],
        'row_actions' => fn($r) => $r['status'] === 'open' ? '<a class="btn btn-light btn-sm" href="' . e(app_url('careers/' . $r['slug'])) . '" target="_blank" rel="noopener" aria-label="View ' . e($r['title']) . ' on the careers page (new tab)" title="View on the careers page">' . icon('eye') . '</a>' : '',
        'before_save' => function (array $d) {
            $types = array_values(array_intersect(array_keys(lookup_options('employment_type')), (array) ($_POST['employment_type'] ?? [])));
            if (!$types) {
                throw new CrudError(['employment_type' => 'Choose at least one employment type.']);
            }
            $d['employment_type'] = implode(',', $types);
            return $d;
        },
        'fields' => [
            'title' => ['label' => 'Job title', 'required' => true, 'max' => 160],
            'slug' => ['label' => 'Slug (page address: /careers/slug)', 'type' => 'slug', 'from' => 'title', 'required' => true, 'unique' => true],
            'subject' => ['label' => 'Faculty subject', 'type' => 'select', 'options' => fn() => lookup_options('faculty_subject'), 'hint' => 'Choose a subject to make this a faculty position with the detailed faculty application form. Manage subjects in Jobs → Faculty Subjects.'],
            'department' => ['label' => 'Department', 'type' => 'select', 'options' => fn() => lookup_options('department'), 'hint' => 'Manage the list in Jobs → Departments.'],
            'employment_type' => ['label' => 'Employment types offered (applicants choose one)', 'type' => 'checkboxes', 'virtual' => true, 'options' => fn() => lookup_options('employment_type'), 'current' => fn($row) => explode(',', (string) $row['employment_type'])],
            'location' => ['label' => 'Location', 'required' => true, 'max' => 120, 'default' => 'Badarpur, New Delhi'],
            'experience' => ['label' => 'Experience', 'max' => 80],
            'salary_range' => ['label' => 'Salary range (optional)', 'max' => 80],
            'closes_at' => ['label' => 'Closes on', 'type' => 'date'],
            'description' => ['label' => 'Description', 'type' => 'textarea'],
            'responsibilities' => ['label' => 'Responsibilities (one per line)', 'type' => 'lines'],
            'requirements' => ['label' => 'Requirements / skills (one per line)', 'type' => 'lines'],
            'status' => ['label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'draft', 'options' => ['draft' => 'Draft', 'open' => 'Open', 'closed' => 'Closed']],
            'is_demo' => ['label' => 'Demo content', 'type' => 'checkbox'],
        ],
    ]);
    exit;
}

/** One application: details, status, interview, contact, timeline. */
function application_page(int $id): never
{
    $me = require_staff_page();
    require_permission('cms.manage');
    $a = db_one('SELECT a.*, o.title AS opening_title FROM job_applications a LEFT JOIN job_openings o ON o.id = a.opening_id WHERE a.id = ?', [$id]);
    if (!$a) {
        flash('error', 'Application not found.');
        redirect_to('jobs.php?tab=applications');
    }
    $role = $a['opening_title'] ?? 'a role at Laxyam Academy';
    $self = 'jobs.php?tab=applications&application=' . $id;
    // WhatsApp only when the candidate ticked WhatsApp updates on the application (job_applications.whatsapp_consent_at).
    $candidate = ['name' => $a['name'], 'email' => $a['email'], 'phone' => $a['phone'], 'whatsapp_consent' => !empty($a['whatsapp_consent_at'])];
    require_once APP_ROOT . '/includes/mailer.php';

    if (admin_post()) {
        require_once APP_ROOT . '/includes/notify.php';
        $act = $_POST['_action'] ?? '';
        if ($act === 'status') {
            $new = (string) ($_POST['status'] ?? '');
            if (!isset(APPLICATION_STATUSES[$new])) {
                flash('error', 'Choose a valid status.');
            } else {
                db_query('UPDATE job_applications SET status = ? WHERE id = ?', [$new, $id]);
                $note = '';
                if (!empty($_POST['notify']) && isset(APPLICATION_STATUS_TEMPLATES[$new])) {
                    $note = in_array('email', send_template(APPLICATION_STATUS_TEMPLATES[$new], $candidate, ['course' => $role]), true)
                        ? ' The candidate was emailed.' : ' The email could not be sent (check SMTP in Integrations) - contact the candidate by phone or WhatsApp.';
                }
                application_event($id, 'status', 'Status changed to ' . APPLICATION_STATUSES[$new] . ($note && str_contains($note, 'was emailed') ? ' (candidate emailed)' : ''));
                audit_log('application_status_changed', 'job_applications', $id, ['status' => $new]);
                flash(str_contains($note, 'could not') ? 'warning' : 'success', 'Status updated.' . $note);
            }
        } elseif ($act === 'interview') {
            $at = DateTime::createFromFormat('Y-m-d\TH:i', (string) ($_POST['interview_at'] ?? ''));
            $mode = (string) ($_POST['interview_mode'] ?? '');
            $loc = trim((string) ($_POST['interview_location'] ?? ''));
            $msg = trim((string) ($_POST['interview_message'] ?? ''));
            if ($mode === 'in_person' && $loc === '') {
                $loc = (string) setting('address', '');
            }
            $error = match (true) {
                !$at || $at < new DateTime('-1 hour') => 'Choose the interview date and time (not in the past).',
                !isset(INTERVIEW_MODES[$mode]) => 'Choose how the interview will happen.',
                $mode === 'video' && !preg_match('#^https://\S+$#i', $loc) => 'Add the video meeting link (https://…).',
                mb_strlen($loc) > 255 || mb_strlen($msg) > 1000 => 'The venue or message is too long.',
                default => null,
            };
            if ($error) {
                flash('error', $error);
            } else {
                db_query("UPDATE job_applications SET status = 'interview', interview_at = ?, interview_mode = ?, interview_location = ? WHERE id = ?",
                    [$at->format('Y-m-d H:i:s'), $mode, $loc ?: null, $id]);
                $when = $at->format('l, j F Y, g:i A');
                $note = '';
                if (!empty($_POST['notify'])) {
                    $note = in_array('email', send_template('interview_scheduled', $candidate, ['course' => $role, 'date' => $when, 'mode' => INTERVIEW_MODES[$mode], 'location' => $loc ?: '-', 'message' => $msg]), true)
                        ? ' Invitation emailed to the candidate.' : ' The invitation email could not be sent (check SMTP) - share the details by phone or WhatsApp.';
                }
                application_event($id, 'interview', "Interview scheduled: {$when} · " . INTERVIEW_MODES[$mode] . (str_contains($note, 'emailed to') ? ' (invitation emailed)' : ''),
                    trim(($loc !== '' ? "Venue / link: {$loc}\n" : '') . $msg) ?: null);
                audit_log('application_interview_scheduled', 'job_applications', $id, ['at' => $at->format('Y-m-d H:i'), 'mode' => $mode]);
                flash(str_contains($note, 'could not') ? 'warning' : 'success', 'Interview scheduled.' . $note);
            }
        } elseif ($act === 'email') {
            $subject = trim((string) ($_POST['subject'] ?? ''));
            $body = trim((string) ($_POST['body'] ?? ''));
            if ($subject === '' || $body === '' || mb_strlen($subject) > 200 || mb_strlen($body) > 5000) {
                flash('error', 'Write a subject (up to 200 characters) and a message.');
            } elseif (!mail_configured()) {
                flash('error', 'Email is not set up yet (SMTP). Call or WhatsApp the candidate, or configure SMTP in Integrations.');
            } elseif (send_mail($a['email'], $subject, mail_template($subject, '<p>' . nl2br(e($body)) . '</p>'))) {
                application_event($id, 'email', 'Email sent: ' . $subject, $body);
                flash('success', 'Email sent to ' . $a['email'] . '.');
            } else {
                flash('error', 'The mail server did not accept the email. Check the SMTP settings and try again.');
            }
        } elseif ($act === 'log') {
            $type = ($_POST['type'] ?? '') === 'call' ? 'call' : 'note';
            $text = trim((string) ($_POST['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > 2000) {
                flash('error', 'Write the call outcome or note (up to 2000 characters).');
            } else {
                application_event($id, $type, $type === 'call' ? 'Call logged' : 'Note added', $text);
                flash('success', $type === 'call' ? 'Call logged.' : 'Note added.');
            }
        } elseif ($act === 'delete') {
            require_once APP_ROOT . '/includes/storage.php';
            storage_delete($a['resume_path']);
            db_query('DELETE FROM job_applications WHERE id = ?', [$id]);
            audit_log('application_deleted', 'job_applications', $id, [], 'warning');
            flash('success', 'Application and resume deleted.');
            redirect_to('jobs.php?tab=applications');
        }
        redirect_to($self);
    }

    $events = db_all('SELECT e.*, u.name AS by_name FROM job_application_events e LEFT JOIN users u ON u.id = e.created_by WHERE e.application_id = ? ORDER BY e.id DESC', [$id]);
    $ref = 'APP-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    $level = fn(?string $v) => $v ? e(LANGUAGE_LEVELS[$v] ?? $v) : '<span class="muted">-</span>';
    $mailReady = mail_configured();
    $greeting = 'Hello ' . $a['name'] . ', this is ' . setting('site_name', 'Laxyam Academy') . ' regarding your application for ' . $role . '.';
    $isPdf = $a['resume_path'] && strtolower(pathinfo((string) $a['resume_path'], PATHINFO_EXTENSION)) === 'pdf';
    $contact = '<a class="btn btn-light btn-sm" href="tel:' . e($a['phone']) . '">' . icon('headset') . ' Call</a> '
        . '<a class="btn btn-light btn-sm" href="' . e(whatsapp_link($a['phone'], $greeting)) . '" target="_blank" rel="noopener">WhatsApp</a> '
        . '<a class="btn btn-light btn-sm" href="mailto:' . e($a['email']) . '">' . icon('mail') . ' Email app</a>'
        . ($isPdf ? ' <a class="btn btn-light btn-sm" href="?tab=applications&amp;resume=' . $id . '&amp;inline=1" target="_blank" rel="noopener">' . icon('eye') . ' View resume</a>' : '')
        . ($a['resume_path'] ? ' <a class="btn btn-primary btn-sm" href="?tab=applications&amp;resume=' . $id . '">' . icon('download') . ' Resume</a>' : '');
    admin_start($a['name'], null, 'jobs', [
        'head' => true,
        'crumbs' => '<a href="jobs.php?tab=applications">Applications</a> / ' . e($ref),
        'subtitle' => ($a['opening_title'] ?? 'General application') . ' · applied ' . fmt_dt($a['created_at'], 'j M Y, g:i A'),
        'actions' => $contact,
    ]);
    $icons = ['status' => 'check', 'interview' => 'clock', 'email' => 'mail', 'call' => 'headset', 'note' => 'edit'];
    ?>
    <div class="grid g-2">
      <div>
        <div class="card" style="margin-bottom:18px">
          <div class="card-head"><h2>Candidate</h2><?= badge($a['status']) ?></div>
          <div class="card-pad">
            <dl class="kv">
              <dt>Reference</dt><dd><?= e($ref) ?></dd>
              <dt>Position</dt><dd><?= e($a['opening_title'] ?? 'General application') ?></dd>
              <?php if ($a['subject']): ?><dt>Preferred subject</dt><dd><strong><?= e(lookup_label('faculty_subject', $a['subject'])) ?></strong></dd><?php endif; ?>
              <?php if ($a['employment_type']): ?><dt>Employment type</dt><dd><span class="badge b-blue"><?= e(lookup_label('employment_type', $a['employment_type'])) ?></span></dd><?php endif; ?>
              <dt>Mobile</dt><dd><a href="tel:<?= e($a['phone']) ?>"><?= e($a['phone']) ?></a></dd>
              <dt>Email</dt><dd><a href="mailto:<?= e($a['email']) ?>"><?= e($a['email']) ?></a></dd>
              <?php if ($a['qualifications']): ?><dt>Qualifications</dt><dd><?= e($a['qualifications']) ?></dd><?php endif; ?>
              <dt>Teaching experience</dt><dd><?= $a['experience_years'] === null ? '<span class="muted">-</span>' : ((int) $a['experience_years'] === 0 ? 'Fresher' : plural_n((int) $a['experience_years'], 'year')) ?></dd>
              <?php if ($a['english_level'] || $a['hindi_level']): ?><dt>English</dt><dd><?= $level($a['english_level']) ?></dd><dt>Hindi</dt><dd><?= $level($a['hindi_level']) ?></dd><?php endif; ?>
              <?php if ($a['expected_salary']): ?><dt>Expected salary</dt><dd><?= e($a['expected_salary']) ?></dd><?php endif; ?>
              <?php if ($a['subjects']): ?><dt>Subjects</dt><dd><?= e($a['subjects']) ?></dd><?php endif; ?>
              <?php if ($a['interview_at']): ?><dt>Interview</dt><dd><strong><?= e(fmt_dt($a['interview_at'], 'D, j M Y, g:i A')) ?></strong> · <?= e(INTERVIEW_MODES[(string) $a['interview_mode']] ?? '') ?><?= $a['interview_location'] ? '<br><span class="muted">' . e($a['interview_location']) . '</span>' : '' ?></dd><?php endif; ?>
              <dt>Resume</dt><dd><?= $a['resume_path'] ? ($isPdf ? '<a href="?tab=applications&amp;resume=' . $id . '&amp;inline=1" target="_blank" rel="noopener">' . icon('eye') . ' View</a> · ' : '') . '<a href="?tab=applications&amp;resume=' . $id . '">' . icon('download') . ' Download</a>' : '<span class="muted">Not uploaded</span>' ?></dd>
            </dl>
            <?php if ($a['message']): ?><h3 style="font-size:14px;margin:16px 0 6px">Experience details / message</h3><p style="white-space:pre-line;margin:0"><?= e($a['message']) ?></p><?php endif; ?>
          </div>
        </div>
        <div class="card">
          <div class="card-head"><h2>Activity</h2><span class="muted"><?= count($events) ?></span></div>
          <?php if (!$events): ?><div class="empty">No activity yet.</div><?php else: ?>
          <ul style="list-style:none;margin:0;padding:6px 20px 16px">
            <?php foreach ($events as $ev): ?>
              <li style="display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--line)">
                <span class="ico" style="flex:none;color:var(--primary)"><?= icon($icons[$ev['type']] ?? 'log') ?></span>
                <div style="min-width:0">
                  <strong><?= e($ev['summary']) ?></strong>
                  <?= $ev['body'] ? '<div style="white-space:pre-line;font-size:13px;margin-top:2px">' . e($ev['body']) . '</div>' : '' ?>
                  <div class="muted" style="font-size:12px"><?= e($ev['by_name'] ?? 'Candidate (website)') ?> · <?= e(fmt_dt($ev['created_at'])) ?></div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
      </div>
      <div>
        <form method="post" class="card" style="margin-bottom:18px">
          <?= csrf_field() ?><input type="hidden" name="_action" value="status">
          <div class="card-head"><h2>Status</h2></div>
          <div class="card-pad form">
            <div class="field"><label for="st">Application status</label>
              <select id="st" name="status"><?php foreach (APPLICATION_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $a['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            </div>
            <div class="field" style="align-self:end"><label class="check"><input type="checkbox" name="notify" value="1"<?= $mailReady ? '' : ' disabled' ?>> Email the candidate</label>
              <div class="hint"><?= $mailReady ? 'For Shortlisted, Not selected and Hired (Notifications → Templates).' : 'Email is not set up (SMTP).' ?></div></div>
          </div>
          <div class="form-actions"><button class="btn btn-primary"><?= icon('check') ?> Update status</button></div>
        </form>

        <form method="post" class="card" style="margin-bottom:18px">
          <?= csrf_field() ?><input type="hidden" name="_action" value="interview">
          <div class="card-head"><h2><?= $a['interview_at'] ? 'Reschedule interview' : 'Schedule interview' ?></h2></div>
          <div class="card-pad form">
            <div class="field"><label for="ia">Date &amp; time</label><input id="ia" type="datetime-local" name="interview_at" required value="<?= e($a['interview_at'] ? date('Y-m-d\TH:i', strtotime($a['interview_at'])) : '') ?>"></div>
            <div class="field"><label for="im">Mode</label><select id="im" name="interview_mode"><?php foreach (INTERVIEW_MODES as $k => $l): ?><option value="<?= e($k) ?>"<?= $a['interview_mode'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field full"><label for="il">Venue or meeting link</label><input id="il" name="interview_location" maxlength="255" value="<?= e((string) $a['interview_location']) ?>" placeholder="In person: leave empty to use the academy address · Video: https://meet.google.com/…"></div>
            <div class="field full"><label for="imsg">Message for the candidate (optional)</label><textarea id="imsg" name="interview_message" rows="2" maxlength="1000" placeholder="e.g. Please prepare a 10-minute demo class on a topic of your choice and bring your certificates."></textarea></div>
            <div class="field full"><label class="check"><input type="checkbox" name="notify" value="1"<?= $mailReady ? ' checked' : ' disabled' ?>> Email the invitation to <?= e($a['email']) ?></label></div>
          </div>
          <div class="form-actions"><button class="btn btn-primary"><?= icon('clock') ?> Save interview</button></div>
        </form>

        <form method="post" class="card" style="margin-bottom:18px">
          <?= csrf_field() ?><input type="hidden" name="_action" value="email">
          <div class="card-head"><h2>Send an email</h2><?= $mailReady ? '' : '<span class="badge b-amber">SMTP not set up</span>' ?></div>
          <div class="card-pad form">
            <div class="field full"><label for="es">Subject</label><input id="es" name="subject" maxlength="200" value="<?= e('Your application for ' . $role) ?>"<?= $mailReady ? '' : ' disabled' ?>></div>
            <div class="field full"><label for="eb">Message</label><textarea id="eb" name="body" rows="4" maxlength="5000"<?= $mailReady ? '' : ' disabled' ?>><?= e('Hello ' . $a['name'] . ",\n\n") ?></textarea></div>
          </div>
          <div class="form-actions"><button class="btn btn-primary"<?= $mailReady ? '' : ' disabled' ?>><?= icon('mail') ?> Send email</button></div>
        </form>

        <form method="post" class="card" style="margin-bottom:18px">
          <?= csrf_field() ?><input type="hidden" name="_action" value="log">
          <div class="card-head"><h2>Log a call or note</h2></div>
          <div class="card-pad form">
            <div class="field"><label for="lt">Type</label><select id="lt" name="type"><option value="call">Phone / WhatsApp call</option><option value="note">Internal note</option></select></div>
            <div class="field full"><label for="lx">Details</label><textarea id="lx" name="text" rows="3" maxlength="2000" required placeholder="e.g. Spoke to the candidate - available from 1 December, prefers part-time evenings."></textarea></div>
          </div>
          <div class="form-actions"><button class="btn btn-light"><?= icon('check') ?> Save</button></div>
        </form>

        <form method="post" class="card card-pad">
          <?= csrf_field() ?><input type="hidden" name="_action" value="delete">
          <button class="btn btn-danger btn-sm" data-confirm="Delete this application and the resume permanently? Use this when a candidate asks for their data to be removed."><?= icon('trash') ?> Delete application &amp; resume</button>
        </form>
      </div>
    </div>
    <?php
    admin_end();
    exit;
}

/** "PDF · 214 KB" for a stored resume (size only for local files). */
function resume_meta(?string $stored): string
{
    if (!$stored) {
        return '';
    }
    $ext = strtoupper(pathinfo($stored, PATHINFO_EXTENSION));
    $full = private_file_path($stored);
    if ($full === null) {
        return '<span class="badge b-red">File missing</span>';
    }
    $size = !str_starts_with($full, 's3:') ? (int) @filesize($full) : 0;
    return '<span class="badge b-gray">' . e($ext) . '</span>' . ($size ? ' <span class="muted" style="font-size:12px">' . e(number_format($size / 1024, 0)) . ' KB</span>' : '');
}

if ($tab === 'applications') {
    if (isset($_GET['application'])) {
        application_page((int) $_GET['application']);
    }
    if (isset($_GET['export'])) {
        require_permission('reports.export');
        $rows = db_all('SELECT a.*, o.title AS opening_title FROM job_applications a LEFT JOIN job_openings o ON o.id = a.opening_id ORDER BY a.id DESC');
        csv_download('job-applications-' . date('Ymd') . '.csv',
            ['Reference', 'Position', 'Subject', 'Employment type', 'Name', 'Mobile', 'Email', 'Qualifications', 'Experience (years)', 'English', 'Hindi', 'Expected salary', 'Status', 'Interview', 'Applied'],
            array_map(fn($r) => ['APP-' . str_pad((string) $r['id'], 5, '0', STR_PAD_LEFT), $r['opening_title'] ?? 'General', lookup_label('faculty_subject', $r['subject']), lookup_label('employment_type', $r['employment_type']),
                $r['name'], $r['phone'], $r['email'], $r['qualifications'], $r['experience_years'], LANGUAGE_LEVELS[(string) $r['english_level']] ?? '', LANGUAGE_LEVELS[(string) $r['hindi_level']] ?? '',
                $r['expected_salary'], APPLICATION_STATUSES[$r['status']] ?? $r['status'], $r['interview_at'], $r['created_at']], $rows));
    }
    // Resume viewer (sidebar "Resume Viewer" links here with view=resumes): applications that have a resume.
    if (($_GET['view'] ?? '') === 'resumes' && !isset($_GET['has_resume'])) {
        $_GET['has_resume'] = 'yes';
    }
    $resumeView = ($_GET['has_resume'] ?? '') === 'yes';
    $upcoming = db_all("SELECT a.id, a.name, a.interview_at, a.interview_mode, COALESCE(o.title, 'General application') AS role
        FROM job_applications a LEFT JOIN job_openings o ON o.id = a.opening_id
        WHERE a.status = 'interview' AND a.interview_at >= NOW() - INTERVAL 2 HOUR ORDER BY a.interview_at LIMIT 6");
    $upcomingHtml = $upcoming && !$resumeView ? '<div class="card" style="margin-bottom:16px"><div class="card-head"><h2>' . icon('clock') . ' Upcoming interviews</h2></div><div class="table-wrap"><table class="t"><tbody>'
        . implode('', array_map(fn($u) => '<tr><td><strong>' . e(fmt_dt($u['interview_at'], 'D, j M · g:i A')) . '</strong></td><td><a href="?tab=applications&amp;application=' . (int) $u['id'] . '">' . e($u['name']) . '</a><br><span class="muted">' . e($u['role']) . '</span></td><td>' . e(INTERVIEW_MODES[(string) $u['interview_mode']] ?? '') . '</td></tr>', $upcoming))
        . '</tbody></table></div></div>' : '';
    $viewCounts = db_one("SELECT COUNT(*) AS total, COALESCE(SUM(resume_path IS NOT NULL AND resume_path <> ''), 0) AS resumes FROM job_applications");
    $viewNav = admin_tabs([
        'all' => ['label' => 'All applications', 'icon' => 'users', 'count' => (int) $viewCounts['total'], 'href' => '?tab=applications'],
        'resumes' => ['label' => 'Resume viewer', 'icon' => 'file', 'count' => (int) $viewCounts['resumes'], 'href' => '?tab=applications&view=resumes'],
    ], $resumeView ? 'resumes' : 'all', ['label' => 'Application views']);
    $resumeLinks = fn($r) => $r['resume_path']
        ? (strtolower(pathinfo((string) $r['resume_path'], PATHINFO_EXTENSION)) === 'pdf'
            ? '<a class="btn btn-light btn-sm" href="?tab=applications&amp;resume=' . (int) $r['id'] . '&amp;inline=1" target="_blank" rel="noopener" aria-label="View resume of ' . e($r['name']) . ' (opens in a new tab)">' . icon('eye') . ' View</a> ' : '')
          . '<a class="btn btn-light btn-sm" href="?tab=applications&amp;resume=' . (int) $r['id'] . '" aria-label="Download resume of ' . e($r['name']) . '">' . icon('download') . ($resumeView ? ' Download' : '') . '</a>'
        : '';

    $columns = $resumeView ? [
        'name' => ['Applicant', fn($r) => '<a href="?tab=applications&amp;application=' . (int) $r['id'] . '"><strong>' . e($r['name']) . '</strong></a><br><span class="muted">' . e($r['email']) . '</span>'],
        'opening_title' => ['Position', fn($r) => e($r['opening_title'] ?? 'General application') . ($r['subject'] ? '<br><span class="muted" style="font-size:12px">' . e(lookup_label('faculty_subject', $r['subject'])) . '</span>' : '')],
        'experience_years' => ['Experience', fn($r) => $r['experience_years'] === null ? '-' : ((int) $r['experience_years'] === 0 ? 'Fresher' : (int) $r['experience_years'] . ' yrs')],
        'resume' => ['Resume', fn($r) => resume_meta($r['resume_path'])],
        'created_at' => ['Applied', fn($r) => e(fmt_dt($r['created_at'], 'j M Y'))],
        'status' => ['Status', fn($r) => badge($r['status'])],
    ] : [
        'name' => ['Applicant', fn($r) => '<a href="?tab=applications&amp;application=' . (int) $r['id'] . '"><strong>' . e($r['name']) . '</strong></a><br><span class="muted"><a href="tel:' . e($r['phone']) . '">' . e($r['phone']) . '</a> · <a href="' . e(whatsapp_link($r['phone'])) . '" target="_blank" rel="noopener">WhatsApp</a></span>'],
        'opening_title' => ['Position', fn($r) => e($r['opening_title'] ?? 'General application') . ($r['employment_type'] ? '<br><span class="badge b-blue">' . e(lookup_label('employment_type', $r['employment_type'])) . '</span>' : '')],
        'experience_years' => ['Experience', fn($r) => ($r['experience_years'] === null ? '-' : ((int) $r['experience_years'] === 0 ? 'Fresher' : (int) $r['experience_years'] . ' yrs')) . ($r['qualifications'] ? '<br><span class="muted" style="font-size:12px">' . e(mb_strimwidth($r['qualifications'], 0, 48, '…')) . '</span>' : '')],
        'languages' => ['English / Hindi', fn($r) => $r['english_level'] ? e(LANGUAGE_LEVELS[(string) $r['english_level']] ?? '') . ' / ' . e(LANGUAGE_LEVELS[(string) $r['hindi_level']] ?? '') : '<span class="muted">-</span>'],
        'expected_salary' => ['Expected salary', fn($r) => e($r['expected_salary'] ?? '-')],
        'created_at' => ['Applied', fn($r) => e(fmt_dt($r['created_at'], 'j M Y'))],
        'status' => ['Status', fn($r) => badge($r['status']) . ($r['status'] === 'interview' && $r['interview_at'] ? '<br><span class="muted" style="font-size:12px">' . e(fmt_dt($r['interview_at'], 'j M, g:i A')) . '</span>' : '')],
    ];

    crud(['tabs' => $common['tabs'] . $viewNav . $upcomingHtml] + $common + [ // left-hand keys win in "+", so the panel goes first
        'table' => 'job_applications', 'singular' => 'application', 'can_create' => false, 'can_edit' => false, 'can_delete' => false,
        'from' => 'job_applications t LEFT JOIN job_openings o ON o.id = t.opening_id',
        'select' => 't.*, o.title AS opening_title',
        'subtitle' => $resumeView ? 'Applications with a resume. PDF resumes open in a new tab; Word files download.' : null,
        'search' => ['t.name', 't.email', 't.phone', 't.subjects', 't.qualifications'],
        // "opening" is a toolbar filter (not a fixed WHERE) so searching keeps the position chosen from Careers Openings.
        'filters' => [
            'opening' => ['col' => 'COALESCE(t.opening_id, 0)', 'label' => 'Positions', 'options' => fn() => options_from("SELECT id, CONCAT(title, IF(status = 'open', '', CONCAT(' (', status, ')'))) AS label FROM job_openings ORDER BY status = 'open' DESC, title") + ['0' => 'General applications']],
            'status' => ['col' => 't.status', 'label' => 'Statuses', 'options' => APPLICATION_STATUSES],
            'subject' => ['col' => 't.subject', 'label' => 'Subjects', 'options' => lookup_options('faculty_subject')],
            'type' => ['col' => 't.employment_type', 'label' => 'Employment types', 'options' => lookup_options('employment_type')],
            'has_resume' => ['col' => "IF(t.resume_path IS NULL OR t.resume_path = '', 'no', 'yes')", 'label' => 'Applications', 'options' => ['yes' => 'With resume', 'no' => 'Without resume']],
        ],
        'sortable' => ['created_at' => 't.created_at', 'experience_years' => 't.experience_years', 'interview_at' => 't.interview_at'],
        'bulk_status' => ['field' => 'status', 'label' => 'status', 'options' => APPLICATION_STATUSES],
        'after_bulk' => function (array $ids, string $status): void {
            foreach ($ids as $id) {
                application_event($id, 'status', 'Status changed to ' . APPLICATION_STATUSES[$status] . ' (bulk update)');
            }
        },
        'header_actions' => can('reports.export') ? '<a class="btn btn-light" href="?tab=applications&amp;export=csv">' . icon('download') . ' Export CSV</a>' : '',
        'columns' => $columns,
        'row_actions' => fn($r) => '<a class="btn btn-primary btn-sm" href="?tab=applications&amp;application=' . (int) $r['id'] . '">Open</a> ' . $resumeLinks($r),
        'fields' => [],
    ]);
    exit;
}

// ======================================================================
// Govt job alerts
// ======================================================================

function alert_type_badge(string $type): string
{
    return '<span class="badge b-' . (JOB_ALERT_TYPE_TONES[$type] ?? 'gray') . '">' . e(job_alert_type_label($type)) . '</span>';
}

/** Is the alert visible on the website right now? */
function alert_is_live(array $r): bool
{
    return $r['status'] === 'published' && (!$r['published_at'] || strtotime($r['published_at']) <= time());
}

function alert_deadline_cell(array $r): string
{
    if (!$r['last_date']) {
        return '<span class="muted">No last date</span>';
    }
    $days = (int) job_alert_days_left($r['last_date']);
    $badge = match (true) {
        $days < 0 => '<span class="badge b-purple">Expired</span>',
        $days === 0 => '<span class="badge b-red">Closes today</span>',
        $days <= JOB_ALERT_CLOSING_DAYS => '<span class="badge b-amber">' . $days . ' day' . ($days === 1 ? '' : 's') . ' left</span>',
        default => '<span class="badge b-green">Open</span>',
    };
    return e(fmt_dt($r['last_date'], 'j M Y')) . '<br>' . $badge;
}

/** Editor fields (converted + validated by crud_save()). important_dates / extra_links are the repeaters below. */
function alert_fields(): array
{
    return [
        'title' => ['label' => 'Title', 'required' => true, 'max' => 200, 'full' => true, 'placeholder' => 'e.g. SSC CGL 2026 Notification - 14,582 vacancies'],
        'slug' => ['label' => 'Slug (page address: /jobs/slug)', 'type' => 'slug', 'from' => 'title', 'required' => true, 'unique' => true],
        'alert_type' => ['label' => 'Type', 'type' => 'select', 'required' => true, 'default' => 'notification', 'options' => JOB_ALERT_TYPE_LABELS],
        'organization' => ['label' => 'Recruiting organisation', 'required' => true, 'max' => 160, 'placeholder' => 'e.g. Staff Selection Commission (SSC)'],
        'category_id' => ['label' => 'Exam category', 'type' => 'select', 'options' => fn() => options_from('SELECT id, name AS label FROM course_categories ORDER BY sort_order, name'), 'hint' => 'Used for the exam filter on /jobs, related alerts and the "Prepare with us" courses.'],
        'official_url' => ['label' => 'Official notification link (https://)', 'type' => 'url', 'required' => true, 'full' => true, 'hint' => 'Always the official website or notification PDF - never a third-party site.'],
        'vacancies' => ['label' => 'Vacancies (as per notification)', 'max' => 60, 'placeholder' => 'e.g. 14,582'],
        'qualification' => ['label' => 'Eligibility / qualification', 'max' => 200, 'placeholder' => 'e.g. Graduation from a recognised university'],
        'apply_start' => ['label' => 'Apply from', 'type' => 'date'],
        'last_date' => ['label' => 'Last date to apply', 'type' => 'date', 'hint' => 'After this date the alert shows as closed and moves below open alerts.'],
        'application_fee' => ['label' => 'Application fee', 'max' => 255, 'placeholder' => 'e.g. ₹100 · No fee for SC/ST, PwBD and women'],
        'age_limit' => ['label' => 'Age limit', 'max' => 160, 'placeholder' => 'e.g. 18-32 years as on 01-08-2026 (relaxation as per rules)'],
        'selection_process' => ['label' => 'Selection process', 'type' => 'textarea', 'rows' => 2, 'max' => 500, 'full' => true, 'placeholder' => 'Tier 1 → Tier 2 → Document verification', 'hint' => 'Separate the stages with → or new lines; the website shows them as steps.'],
        'summary' => ['label' => 'Summary (shown on the alert card)', 'type' => 'textarea', 'rows' => 3, 'max' => 600, 'counter' => '300,600'],
        'details' => ['label' => 'Full details', 'type' => 'html', 'hint' => 'Headings, lists and tables (e.g. post-wise vacancies). Formatting is cleaned on save.'],
        'status' => ['label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'draft', 'options' => ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived']],
        'published_at' => ['label' => 'Publish date', 'type' => 'datetime', 'hint' => 'Leave empty to publish now. A future date schedules the alert.'],
        'is_featured' => ['label' => 'Featured (homepage and top of the list)', 'type' => 'checkbox'],
        'is_demo' => ['label' => 'Demo content', 'type' => 'checkbox'],
    ];
}

/**
 * Read and validate the two repeaters from the POSTed editor.
 * Returns [['important_dates' => json|null, 'extra_links' => json|null], field errors, row errors].
 */
function alert_repeaters_from_post(): array
{
    // One trimmed cell of a parallel POST array, without control characters.
    $str = fn($list, int $i) => is_array($list) && is_string($list[$i] ?? null) ? trim((string) preg_replace('/[^\P{C}]/u', '', $list[$i])) : '';
    $errors = [];
    $rowErrors = ['dates' => [], 'links' => []];

    $dates = [];
    $n = max(count((array) ($_POST['date_label'] ?? [])), count((array) ($_POST['date_value'] ?? [])));
    for ($i = 0; $i < min($n, 100); $i++) {
        $label = $str($_POST['date_label'] ?? [], $i);
        $date = $str($_POST['date_value'] ?? [], $i);
        $note = $str($_POST['date_note'] ?? [], $i);
        if ($label === '' && $date === '' && $note === '') {
            continue; // empty row
        }
        $d = $date !== '' ? DateTime::createFromFormat('!Y-m-d', $date) : null;
        $rowErrors['dates'][$i] = match (true) {
            $label === '' => 'Name the event, e.g. "Last date to apply".',
            mb_strlen($label) > 80 => 'Keep the event name under 80 characters.',
            $date !== '' && (!$d || $d->format('Y-m-d') !== $date) => 'Choose a valid date.',
            $date === '' && $note === '' => 'Choose a date, or write e.g. "To be announced".',
            mb_strlen($note) > 40 => 'Keep the text under 40 characters.',
            default => null,
        };
        if ($rowErrors['dates'][$i] === null) {
            unset($rowErrors['dates'][$i]);
            $dates[] = ['label' => $label, 'date' => $date !== '' ? $date : $note];
        }
    }
    if ($rowErrors['dates']) {
        $errors['important_dates'] = 'Check the highlighted dates.';
    } elseif (count($dates) > JOB_ALERT_MAX_DATES) {
        $errors['important_dates'] = 'Add up to ' . JOB_ALERT_MAX_DATES . ' dates.';
    }

    $links = [];
    $n = max(count((array) ($_POST['link_label'] ?? [])), count((array) ($_POST['link_url'] ?? [])));
    for ($i = 0; $i < min($n, 100); $i++) {
        $label = $str($_POST['link_label'] ?? [], $i);
        $url = $str($_POST['link_url'] ?? [], $i);
        if ($label === '' && $url === '') {
            continue;
        }
        $rowErrors['links'][$i] = match (true) {
            $label === '' => 'Name the link, e.g. "Download admit card".',
            mb_strlen($label) > 80 => 'Keep the link name under 80 characters.',
            !preg_match('#^https://[^\s<>"]+$#i', $url) || strlen($url) > 500 => 'Enter a full https:// link (official websites only).',
            default => null,
        };
        if ($rowErrors['links'][$i] === null) {
            unset($rowErrors['links'][$i]);
            $links[] = ['label' => $label, 'url' => $url];
        }
    }
    if ($rowErrors['links']) {
        $errors['extra_links'] = 'Check the highlighted links.';
    } elseif (count($links) > JOB_ALERT_MAX_LINKS) {
        $errors['extra_links'] = 'Add up to ' . JOB_ALERT_MAX_LINKS . ' links.';
    }

    $json = fn(array $rows) => $rows ? json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    return [['important_dates' => $json($dates), 'extra_links' => $json($links)], $errors, $rowErrors];
}

function alert_row_tools(string $what): string
{
    return '<span class="ja-tools">'
        . '<button type="button" class="btn btn-light btn-sm" data-rep-up aria-label="Move ' . $what . ' up" title="Move up">' . icon('up') . '</button>'
        . '<button type="button" class="btn btn-light btn-sm" data-rep-down aria-label="Move ' . $what . ' down" title="Move down">' . icon('down') . '</button>'
        . '<button type="button" class="btn btn-light btn-sm" data-rep-remove aria-label="Remove ' . $what . '" title="Remove">' . icon('trash') . '</button></span>';
}

function alert_date_row(string $label = '', string $date = '', string $note = '', ?string $error = null): string
{
    $inv = $error ? ' aria-invalid="true"' : '';
    return '<div class="ja-row ja-row-dates' . ($error ? ' invalid' : '') . '" data-rep-row>'
        . '<input type="text" name="date_label[]" value="' . e($label) . '" maxlength="80" list="ja-date-labels" placeholder="Event, e.g. Last date to apply" aria-label="Event"' . $inv . '>'
        . '<input type="date" name="date_value[]" value="' . e($date) . '" aria-label="Date"' . $inv . '>'
        . '<input type="text" name="date_note[]" value="' . e($note) . '" maxlength="40" placeholder="or text, e.g. To be announced" aria-label="Text instead of a date">'
        . alert_row_tools('date')
        . ($error ? '<div class="err ja-row-err" role="alert">' . e($error) . '</div>' : '')
        . '</div>';
}

function alert_link_row(string $label = '', string $url = '', ?string $error = null): string
{
    $inv = $error ? ' aria-invalid="true"' : '';
    return '<div class="ja-row ja-row-links' . ($error ? ' invalid' : '') . '" data-rep-row>'
        . '<input type="text" name="link_label[]" value="' . e($label) . '" maxlength="80" list="ja-link-labels" placeholder="Label, e.g. Apply online" aria-label="Link label"' . $inv . '>'
        . '<input type="url" name="link_url[]" value="' . e($url) . '" maxlength="500" placeholder="https://" aria-label="Link address (https://)"' . $inv . '>'
        . alert_row_tools('link')
        . ($error ? '<div class="err ja-row-err" role="alert">' . e($error) . '</div>' : '')
        . '</div>';
}

/** New / edit / duplicate a job alert: crud field widgets + date and link repeaters, saved through crud_save(). */
function alert_editor(array $me): never
{
    $fields = alert_fields();
    $cfg = ['table' => 'job_alerts', 'pk' => 'id', 'singular' => 'job alert', 'fields' => $fields, 'defaults' => []];
    $id = ($_GET['action'] ?? '') === 'edit' ? (int) ($_GET['id'] ?? 0) : 0;
    $old = $id ? db_one('SELECT * FROM job_alerts WHERE id = ?', [$id]) : null;
    if ($id && !$old) {
        flash('error', 'Job alert not found.');
        redirect_to('jobs.php?tab=alerts');
    }
    $row = $old;
    $copyOf = null;
    if (!$old && isset($_GET['copy']) && ($src = db_one('SELECT * FROM job_alerts WHERE id = ?', [(int) $_GET['copy']]))) {
        // "Duplicate as…": the admit card → answer key → result workflow re-uses most details
        // (the application window belongs to the original notification, so it is cleared).
        $copyOf = $src;
        $type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
        $row = array_merge($src, [
            'id' => null, 'slug' => '', 'status' => 'draft', 'published_at' => null, 'is_featured' => 0, 'is_demo' => 0,
            'apply_start' => null, 'last_date' => null,
            'alert_type' => isset(JOB_ALERT_TYPE_LABELS[$type]) ? $type : (JOB_ALERT_NEXT_TYPE[$src['alert_type']] ?? 'notification'),
        ]);
    }

    $errors = [];
    $rowErrors = ['dates' => [], 'links' => []];
    $posted = null;
    if (admin_post() && ($_POST['_action'] ?? 'save') === 'save') {
        [$repeat, $repeatErrors, $rowErrors] = alert_repeaters_from_post();
        $cfg['before_save'] = function (array $d, ?array $old) use ($repeat, $repeatErrors, $me): array {
            $errors = $repeatErrors;
            if ($d['apply_start'] && $d['last_date'] && $d['last_date'] < $d['apply_start']) {
                $errors['last_date'] = 'The last date cannot be before the "Apply from" date.';
            }
            if ($errors) {
                throw new CrudError($errors);
            }
            if ($d['status'] === 'published' && !$d['published_at']) {
                $d['published_at'] = date('Y-m-d H:i:s');
            }
            if (!$old) {
                $d['created_by'] = (int) $me['id'];
            }
            return $d + $repeat;
        };
        try {
            $saved = crud_save($cfg, $old);
            $now = db_one('SELECT * FROM job_alerts WHERE id = ?', [$saved]);
            flash('success', 'Job alert ' . ($old ? 'updated' : 'created') . '.' . ($now && alert_is_live($now) ? ' It is live at /jobs/' . $now['slug'] . '.' : ($now && $now['status'] === 'published' ? ' It goes live on ' . fmt_dt($now['published_at']) . '.' : ' It is not on the website until you publish it.')));
            redirect_to('jobs.php?tab=alerts');
        } catch (CrudError $e) {
            $errors = $e->fields + $repeatErrors;
            flash('error', $e->getMessage());
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            flash('error', 'This conflicts with an existing record (duplicate slug or a removed category).');
        }
        $posted = $_POST;
    }

    // Repeater rows: what was just posted, else the saved JSON.
    if ($posted !== null) {
        $dateRows = [];
        foreach ((array) ($posted['date_label'] ?? []) as $i => $l) {
            $dateRows[] = [(string) $l, (string) ($posted['date_value'][$i] ?? ''), (string) ($posted['date_note'][$i] ?? ''), $rowErrors['dates'][$i] ?? null];
        }
        $linkRows = [];
        foreach ((array) ($posted['link_label'] ?? []) as $i => $l) {
            $linkRows[] = [(string) $l, (string) ($posted['link_url'][$i] ?? ''), $rowErrors['links'][$i] ?? null];
        }
    } else {
        $dateRows = array_map(fn($d) => [$d['label'], $d['is_date'] ? $d['date'] : '', $d['is_date'] ? '' : $d['date'], null], job_alert_dates($row['important_dates'] ?? null));
        $linkRows = array_map(fn($l) => [$l['label'], $l['url'], null], job_alert_links($row['extra_links'] ?? null));
    }

    $title = $old ? 'Edit job alert' : ($copyOf ? 'Duplicate job alert' : 'New job alert');
    admin_start($title, null, 'jobs', [
        'head' => true,
        'crumbs' => '<a href="jobs.php?tab=alerts">Govt Job Alerts</a> / ' . ($old ? 'Edit' : 'New'),
        'subtitle' => $copyOf
            ? 'Copied from "' . $copyOf['title'] . '". Update the title, type, dates and links, then save - it starts as a draft.'
            : 'Copy details only from the official notification and always link to the official website.',
        'actions' => $old && alert_is_live($old) ? '<a class="btn btn-light" href="' . e(app_url('jobs/' . $old['slug'])) . '" target="_blank" rel="noopener">' . icon('external') . ' View on website</a>' : '',
    ]);
    $field = function (string $name) use ($fields, $posted, $row, $errors): void {
        $f = $fields[$name] + ['type' => 'text', 'label' => $name, 'required' => false];
        if ($posted !== null) {
            $value = $f['type'] === 'checkbox' ? (empty($posted[$name]) ? 0 : 1) : ($posted[$name] ?? null);
        } else {
            $value = $row[$name] ?? ($f['default'] ?? null);
        }
        crud_field($name, $f, $value, $errors[$name] ?? null, $row);
    };
    $section = function (string $heading, string $sub, array $names) use ($field): void {
        echo '<section class="card" style="margin-bottom:18px"><div class="card-head"><div><h2>' . e($heading) . '</h2>' . ($sub !== '' ? '<p class="muted" style="margin:2px 0 0;font-size:13px">' . e($sub) . '</p>' : '') . '</div></div><div class="card-pad form">';
        foreach ($names as $n) {
            $field($n);
        }
        echo '</div></section>';
    };
    ?>
    <style>
      .ja-rows { display: grid; gap: 10px; }
      .ja-row { display: grid; gap: 8px; align-items: center; padding: 10px; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); }
      .ja-row.invalid { border-color: var(--red); }
      .ja-row-dates { grid-template-columns: minmax(0, 2fr) minmax(0, 1.1fr) minmax(0, 1.3fr) auto; }
      .ja-row-links { grid-template-columns: minmax(0, 1.3fr) minmax(0, 2.5fr) auto; }
      .ja-tools { display: flex; gap: 4px; justify-content: flex-end; }
      .ja-tools .btn { padding: 6px 8px; }
      .ja-row-err { grid-column: 1 / -1; margin: 0 !important; }
      .ja-cols { display: grid; gap: 8px; padding: 0 10px 6px; font-size: 12px; font-weight: 600; color: var(--muted); }
      .ja-empty { padding: 14px; border: 1px dashed var(--line); border-radius: 12px; color: var(--muted); font-size: 13px; text-align: center; }
      @media (max-width: 760px) { .ja-row-dates, .ja-row-links { grid-template-columns: 1fr; } .ja-cols { display: none; } }
    </style>
    <form method="post" novalidate data-dirty-check>
      <?= csrf_field() ?><input type="hidden" name="_action" value="save">
      <?php if ($errors): ?><div class="alert alert-error" role="alert" style="margin-bottom:16px">Please fix <?= count($errors) ?> highlighted field<?= count($errors) > 1 ? 's' : '' ?> below.</div><?php endif; ?>
      <?php
      $section('Alert', 'What it is and where it comes from.', ['title', 'slug', 'alert_type', 'organization', 'category_id', 'official_url']);
      $section('Key facts', 'Shown as quick facts at the top of the alert page.', ['vacancies', 'qualification', 'apply_start', 'last_date', 'application_fee', 'age_limit', 'selection_process']);
      ?>
      <section class="card" style="margin-bottom:18px" aria-labelledby="ja-dates-h">
        <div class="card-head"><div><h2 id="ja-dates-h">Important dates</h2><p class="muted" style="margin:2px 0 0;font-size:13px">Shown as a table on the alert page, in this order. Without rows the page shows "Apply from" and "Last date".</p></div></div>
        <div class="card-pad">
          <div class="field full<?= isset($errors['important_dates']) ? ' invalid' : '' ?>" data-repeater data-max="<?= JOB_ALERT_MAX_DATES ?>">
            <div class="ja-cols ja-row-dates" aria-hidden="true"><span>Event</span><span>Date</span><span>Or text (no exact date yet)</span><span></span></div>
            <div class="ja-rows" data-rep-rows><?php foreach ($dateRows as [$l, $d, $n, $err]) { echo alert_date_row($l, $d, $n, $err); } ?></div>
            <p class="ja-empty" data-rep-empty<?= $dateRows ? ' hidden' : '' ?>>No dates added yet.</p>
            <template data-rep-template><?= alert_date_row() ?></template>
            <?php if (isset($errors['important_dates'])): ?><div class="err" role="alert"><?= e($errors['important_dates']) ?></div><?php endif; ?>
            <p style="margin:12px 0 0"><button type="button" class="btn btn-light btn-sm" data-rep-add><?= icon('plus') ?> Add date</button></p>
            <datalist id="ja-date-labels">
              <?php foreach (['Notification released', 'Application starts', 'Last date to apply', 'Last date for fee payment', 'Correction window', 'Admit card release', 'Exam date', 'Answer key release', 'Result declared', 'Interview / document verification'] as $o): ?><option value="<?= e($o) ?>"><?php endforeach; ?>
            </datalist>
          </div>
        </div>
      </section>
      <?php $section('Description', 'Card summary and the full details from the notification.', ['summary', 'details']); ?>
      <section class="card" style="margin-bottom:18px" aria-labelledby="ja-links-h">
        <div class="card-head"><div><h2 id="ja-links-h">Important links</h2><p class="muted" style="margin:2px 0 0;font-size:13px">Extra official links (apply online, admit card, result, answer key PDF…). The official notification link above is always shown first.</p></div></div>
        <div class="card-pad">
          <div class="field full<?= isset($errors['extra_links']) ? ' invalid' : '' ?>" data-repeater data-max="<?= JOB_ALERT_MAX_LINKS ?>">
            <div class="ja-cols ja-row-links" aria-hidden="true"><span>Label</span><span>Link (https://)</span><span></span></div>
            <div class="ja-rows" data-rep-rows><?php foreach ($linkRows as [$l, $u, $err]) { echo alert_link_row($l, $u, $err); } ?></div>
            <p class="ja-empty" data-rep-empty<?= $linkRows ? ' hidden' : '' ?>>No extra links yet.</p>
            <template data-rep-template><?= alert_link_row() ?></template>
            <?php if (isset($errors['extra_links'])): ?><div class="err" role="alert"><?= e($errors['extra_links']) ?></div><?php endif; ?>
            <p style="margin:12px 0 0"><button type="button" class="btn btn-light btn-sm" data-rep-add><?= icon('plus') ?> Add link</button></p>
            <datalist id="ja-link-labels">
              <?php foreach (['Apply online', 'Download notification (PDF)', 'Download admit card', 'Check result', 'Answer key (PDF)', 'Syllabus (PDF)', 'Official website'] as $o): ?><option value="<?= e($o) ?>"><?php endforeach; ?>
            </datalist>
          </div>
        </div>
      </section>
      <?php $section('Publishing', '', ['status', 'published_at', 'is_featured', 'is_demo']); ?>
      <div class="card"><div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><a class="btn btn-light" href="jobs.php?tab=alerts">Cancel</a><button class="btn btn-primary"><?= icon('check') ?> Save job alert</button></div></div>
    </form>
    <script>
    // Date / link repeaters: add, remove and reorder rows (each row posts one value per column, in DOM order).
    document.querySelectorAll('[data-repeater]').forEach(function (rep) {
      var rows = rep.querySelector('[data-rep-rows]');
      var tpl = rep.querySelector('template[data-rep-template]');
      var add = rep.querySelector('[data-rep-add]');
      var empty = rep.querySelector('[data-rep-empty]');
      var max = parseInt(rep.getAttribute('data-max'), 10) || 20;
      var changed = function () { var f = rep.closest('form'); if (f) f.dispatchEvent(new Event('input')); };
      var sync = function () {
        var n = rows.querySelectorAll('[data-rep-row]').length;
        add.disabled = n >= max;
        if (empty) empty.hidden = n > 0;
      };
      add.addEventListener('click', function () {
        rows.appendChild(tpl.content.cloneNode(true));
        var input = rows.lastElementChild && rows.lastElementChild.querySelector('input');
        if (input) input.focus();
        sync(); changed();
      });
      rows.addEventListener('click', function (e) {
        var b = e.target.closest('button');
        var row = b && b.closest('[data-rep-row]');
        if (!row) return;
        if (b.hasAttribute('data-rep-remove')) {
          var next = row.nextElementSibling || row.previousElementSibling;
          row.remove();
          (next ? next.querySelector('input') : add).focus();
        } else if (b.hasAttribute('data-rep-up') && row.previousElementSibling) {
          rows.insertBefore(row, row.previousElementSibling); b.focus();
        } else if (b.hasAttribute('data-rep-down') && row.nextElementSibling) {
          rows.insertBefore(row.nextElementSibling, row); b.focus();
        } else {
          return;
        }
        sync(); changed();
      });
      sync();
    });
    </script>
    <?php
    admin_end();
    exit;
}

// tab === 'alerts'
if (in_array($_GET['action'] ?? '', ['new', 'edit'], true)) {
    alert_editor($me);
}

if (admin_post()) {
    $act = (string) ($_POST['_action'] ?? '');
    if ($act === 'feature') {
        // Star toggle in the list: featured alerts lead the homepage widget and the /jobs list.
        $row = db_one('SELECT id, title, is_featured FROM job_alerts WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        if ($row) {
            db_query('UPDATE job_alerts SET is_featured = 1 - is_featured WHERE id = ?', [$row['id']]);
            audit_log('updated', 'job_alerts', (int) $row['id'], ['is_featured' => $row['is_featured'] ? 0 : 1]);
            flash('success', '"' . $row['title'] . '" ' . ($row['is_featured'] ? 'is no longer featured.' : 'is now featured.'));
        }
        redirect_to(url_with([]));
    }
    if ($act === 'archive_expired') {
        $n = db_query("UPDATE job_alerts SET status = 'archived' WHERE status = 'published' AND last_date IS NOT NULL AND last_date < CURDATE()")->rowCount();
        audit_log('bulk_updated', 'job_alerts', null, ['archived_expired' => $n]);
        flash('success', $n . ' expired alert' . ($n === 1 ? '' : 's') . ' archived (hidden from the website).');
        redirect_to('jobs.php?tab=alerts');
    }
    // anything else (delete, bulk) is handled by crud() below
}

// Type summary: coloured chips with counts that filter the list.
$typeCounts = array_fill_keys(array_keys(JOB_ALERT_TYPE_LABELS), 0);
foreach (db_all('SELECT alert_type, COUNT(*) AS n FROM job_alerts GROUP BY alert_type') as $r) {
    $typeCounts[$r['alert_type']] = (int) $r['n'];
}
$stats = db_one("SELECT COUNT(*) AS total, COALESCE(SUM(is_featured), 0) AS featured,
    COALESCE(SUM(status = 'published' AND last_date IS NOT NULL AND last_date < CURDATE()), 0) AS expired_live,
    COALESCE(SUM(last_date IS NOT NULL AND last_date < CURDATE()), 0) AS expired,
    COALESCE(SUM(last_date IS NOT NULL AND last_date BETWEEN CURDATE() AND CURDATE() + INTERVAL " . JOB_ALERT_CLOSING_DAYS . ' DAY), 0) AS closing FROM job_alerts');
$curType = is_string($_GET['alert_type'] ?? null) ? $_GET['alert_type'] : '';
$curState = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
$chip = fn(string $href, string $label, int $n, bool $on) => '<a class="ja-chip' . ($on ? ' on' : '') . '" href="' . e($href) . '"' . ($on ? ' aria-current="true"' : '') . '>' . $label . ' <b>' . $n . '</b></a>';
$summary = '<style>.ja-chips{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px}.ja-chip{display:inline-flex;align-items:center;gap:8px;padding:6px 8px 6px 6px;border:1px solid var(--line);border-radius:999px;background:var(--white);color:var(--ink);font-size:13px;font-weight:600}.ja-chip:hover{border-color:var(--primary);text-decoration:none}.ja-chip.on{border-color:var(--primary);box-shadow:0 0 0 3px rgb(11 99 246 / .15)}.ja-chip b{min-width:22px;padding:1px 7px;border-radius:999px;background:var(--surface);text-align:center}.ja-chip .plain{padding:2px 6px}.ja-star.on{color:var(--amber)}.ja-star.on svg{fill:currentColor}.ja-title{display:flex;gap:10px;align-items:flex-start;min-width:280px}.ja-title .ja-star{padding:6px}@media (max-width:700px){.ja-title{min-width:0}}</style>'
    . '<div class="ja-chips" role="navigation" aria-label="Alert types">'
    . $chip('?tab=alerts', '<span class="plain">All alerts</span>', (int) $stats['total'], $curType === '' && $curState === '')
    . implode('', array_map(fn($t) => $chip('?tab=alerts&alert_type=' . $t, alert_type_badge($t), $typeCounts[$t], $curType === $t), array_keys(JOB_ALERT_TYPE_LABELS)))
    . $chip('?tab=alerts&state=closing', '<span class="badge b-amber">Closing within ' . JOB_ALERT_CLOSING_DAYS . ' days</span>', (int) $stats['closing'], $curState === 'closing')
    . $chip('?tab=alerts&state=expired', '<span class="badge b-purple">Expired</span>', (int) $stats['expired'], $curState === 'expired')
    . '</div>';

crud(['tabs' => $common['tabs'] . $summary] + $common + [
    'table' => 'job_alerts', 'singular' => 'job alert',
    'subtitle' => 'Copy details only from the official notification and always link to the official website.',
    'from' => 'job_alerts t LEFT JOIN course_categories cat ON cat.id = t.category_id',
    'select' => 't.*, cat.name AS category_name',
    'order' => "(t.last_date IS NOT NULL AND t.last_date < CURDATE()), t.is_featured DESC, COALESCE(t.published_at, t.created_at) DESC, t.id DESC",
    'search' => ['t.title', 't.organization'],
    'filters' => [
        'alert_type' => ['col' => 't.alert_type', 'label' => 'Types', 'options' => JOB_ALERT_TYPE_LABELS],
        'category' => ['col' => 't.category_id', 'label' => 'Exam categories', 'options' => fn() => options_from('SELECT id, name AS label FROM course_categories ORDER BY sort_order, name')],
        'state' => ['col' => "IF(t.last_date IS NULL OR t.last_date > CURDATE() + INTERVAL " . JOB_ALERT_CLOSING_DAYS . " DAY, 'open', IF(t.last_date < CURDATE(), 'expired', 'closing'))", 'label' => 'Deadlines', 'options' => ['open' => 'Open / no last date', 'closing' => 'Closing within ' . JOB_ALERT_CLOSING_DAYS . ' days', 'expired' => 'Expired']],
        'status' => ['col' => 't.status', 'label' => 'Statuses', 'options' => ['published' => 'Published', 'draft' => 'Draft', 'archived' => 'Archived']],
    ],
    'sortable' => ['last_date' => 't.last_date', 'status' => 't.published_at'],
    'header_actions' => (int) $stats['expired_live']
        ? action_form('archive_expired', [], icon('history') . ' Archive ' . (int) $stats['expired_live'] . ' expired', 'btn btn-light', 'Archive every published alert whose last date has passed? They are hidden from the website (you can publish them again).')
        : '',
    // Featured star sits next to the title and the publish date under the status, so the
    // alert title gets the room it needs and the row actions stay visible on laptop screens.
    'columns' => [
        'title' => ['Alert', fn($r) => '<div class="ja-title"><form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="_action" value="feature"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
            . '<button class="btn btn-light btn-sm btn-icon ja-star' . ($r['is_featured'] ? ' on' : '') . '" aria-pressed="' . ($r['is_featured'] ? 'true' : 'false') . '" aria-label="Featured: ' . e($r['title']) . '" title="' . ($r['is_featured'] ? 'Featured - click to remove' : 'Click to feature on the homepage') . '">' . icon('star') . '</button></form>'
            . '<div style="min-width:0"><strong>' . e($r['title']) . '</strong>' . demo_badge($r) . '<span class="d2-sub">' . e($r['organization']) . ($r['category_name'] ? ' · ' . e($r['category_name']) : '') . '</span></div></div>'],
        'alert_type' => ['Type', fn($r) => alert_type_badge($r['alert_type'])],
        'last_date' => ['Last date', fn($r) => '<div class="d2-nowrap">' . alert_deadline_cell($r) . '</div>'],
        'status' => ['Status', fn($r) => '<div class="d2-nowrap">' . badge($r['status'])
            . ($r['published_at']
                ? ($r['status'] === 'published' && strtotime($r['published_at']) > time() ? ' <span class="badge b-blue">Scheduled</span>'
                    : ($r['status'] === 'published' && strtotime($r['published_at']) >= time() - JOB_ALERT_NEW_DAYS * 86400 ? ' <span class="badge b-green">New</span>' : ''))
                  . '<span class="d2-sub">' . e(fmt_dt($r['published_at'], 'j M Y')) . '</span>'
                : '<span class="d2-sub">Not published yet</span>') . '</div>'],
    ],
    'row_actions' => fn($r) => (alert_is_live($r) ? '<a class="btn btn-light btn-sm" href="' . e(app_url('jobs/' . $r['slug'])) . '" target="_blank" rel="noopener" aria-label="View on website" title="View on website">' . icon('eye') . '</a> ' : '')
        . (preg_match('#^https://[^\s<>"]+$#i', (string) $r['official_url']) ? '<a class="btn btn-light btn-sm" href="' . e($r['official_url']) . '" target="_blank" rel="noopener noreferrer" aria-label="Official link" title="Official link">' . icon('external') . '</a> ' : '')
        . '<a class="btn btn-light btn-sm" href="?tab=alerts&amp;action=new&amp;copy=' . (int) $r['id'] . '" aria-label="Duplicate as ' . e(job_alert_type_label(JOB_ALERT_NEXT_TYPE[$r['alert_type']] ?? 'notification')) . '" title="Duplicate as ' . e(job_alert_type_label(JOB_ALERT_NEXT_TYPE[$r['alert_type']] ?? 'notification')) . '">' . icon('copy') . '</a>',
    'bulk_status' => ['field' => 'status', 'label' => 'status', 'options' => ['published' => 'Published', 'draft' => 'Draft', 'archived' => 'Archived']],
    'after_bulk' => function (array $ids, string $status): void {
        if ($status === 'published') {
            db_query('UPDATE job_alerts SET published_at = NOW() WHERE published_at IS NULL AND id IN (' . db_placeholders(count($ids)) . ')', $ids);
        }
    },
    'fields' => alert_fields(), // the list never renders the crud form: new/edit go to alert_editor()
]);
