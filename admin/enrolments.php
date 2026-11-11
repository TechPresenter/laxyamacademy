<?php
// Student enrolments across all courses: source, expiry, progress. Grant access from a student's profile.
// ?view=access shows the access rules per course (price, validity, prerequisite) with live enrolment counts.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';
require_once APP_ROOT . '/includes/catalog.php';

$views = '<nav class="tabs" aria-label="Enrolment views">'
    . '<a href="enrolments.php"' . (($_GET['view'] ?? '') !== 'access' ? ' class="on" aria-current="page"' : '') . '>All enrolments</a>'
    . '<a href="enrolments.php?view=access"' . (($_GET['view'] ?? '') === 'access' ? ' class="on" aria-current="page"' : '') . '>Course access rules</a></nav>';

if (($_GET['view'] ?? '') === 'access') {
    require_staff_page();
    require_permission('enrolments.manage');
    require_once APP_ROOT . '/includes/catalog.php';
    $courses = db_all(
        "SELECT c.id, c.title, c.slug, c.status, c.course_type, c.price, c.discount_price, c.validity_days, "
                . (course_extras_enabled() ? 'c.offer_starts_at, c.offer_ends_at, ' : '') . "p.title AS prerequisite,
                SUM(e.status = 'active' AND (e.expires_at IS NULL OR e.expires_at > NOW())) AS active_n,
                SUM(e.status = 'active' AND e.expires_at > NOW() AND e.expires_at <= NOW() + INTERVAL 7 DAY) AS expiring_n,
                SUM(e.status = 'expired' OR (e.status = 'active' AND e.expires_at <= NOW())) AS expired_n,
                SUM(e.status = 'revoked') AS revoked_n
         FROM courses c LEFT JOIN courses p ON p.id = c.prerequisite_course_id LEFT JOIN course_enrolments e ON e.course_id = c.id
         WHERE c.status <> 'archived' GROUP BY c.id ORDER BY c.status = 'published' DESC, c.sort_order, c.title"
    );
    admin_start('Course Access Rules', null, 'users', ['head' => true, 'crumbs' => '<a href="enrolments.php">Student Enrolments</a> / Access rules',
        'subtitle' => 'Who can open each course and for how long. Validity starts on the day of enrolment; change it on the course.']);
    echo $views;
    ?>
    <div class="card">
      <?php if (!$courses): ?><div class="empty"><span class="empty-ic"><?= icon('book') ?></span><strong>No courses yet</strong><p>Access rules appear here for every course that is not archived.</p></div><?php else: ?>
      <div class="table-wrap"><table class="t stack"><thead><tr><th scope="col">Course</th><th scope="col">Access</th><th scope="col">Validity</th><th scope="col">Prerequisite</th><th scope="col">Active</th><th scope="col">Expiring ≤ 7 days</th><th scope="col">Expired</th><th scope="col">Revoked</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>
      <?php foreach ($courses as $c):
          $price = course_effective_price($c);
          $link = fn(string $status) => 'enrolments.php?course=' . (int) $c['id'] . '&amp;status=' . $status; ?>
        <tr>
          <td data-label="Course"><div class="d1-main sm"><span class="d1-t"><?= e($c['title']) ?></span><?= $c['status'] !== 'published' ? '<div class="d1-sub">' . badge($c['status']) . '</div>' : '' ?></div></td>
          <td data-label="Access" class="d1-nowrap"><?= $price <= 0 ? '<span class="badge b-green">Free</span>' : 'Paid · ' . e(money($price)) ?></td>
          <td data-label="Validity" class="d1-nowrap"><?= $c['validity_days'] ? (int) $c['validity_days'] . ' days' : 'Lifetime' ?></td>
          <td data-label="Prerequisite" class="muted"><?= $c['prerequisite'] ? 'Complete “' . e($c['prerequisite']) . '” first' : '—' ?></td>
          <td data-label="Active" class="d1-num"><a href="<?= $link('active') ?>"><?= (int) $c['active_n'] ?></a></td>
          <td data-label="Expiring ≤ 7 days" class="d1-num"><?= (int) $c['expiring_n'] ? '<span class="badge b-amber">' . (int) $c['expiring_n'] . '</span>' : '0' ?></td>
          <td data-label="Expired" class="d1-num"><a href="<?= $link('expired') ?>"><?= (int) $c['expired_n'] ?></a></td>
          <td data-label="Revoked" class="d1-num"><a href="<?= $link('revoked') ?>"><?= (int) $c['revoked_n'] ?></a></td>
          <td class="actions"><?php if (can('courses.manage')): ?><a class="btn btn-light btn-sm" href="courses.php?action=edit&amp;id=<?= (int) $c['id'] ?>"><?= icon('edit') ?> Edit rules</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <p class="muted">Paid access is granted only after a verified payment; staff can grant access from a student’s profile. Expired enrolments are switched off automatically by the scheduler.</p>
    <?php
    admin_end();
    exit;
}

crud([
    'table' => 'course_enrolments',
    'title' => 'Student Enrolments',
    'singular' => 'enrolment',
    'permission' => 'enrolments.manage',
    'active' => 'users',
    'can_create' => false,
    'can_delete' => false,
    'tabs' => $views,
    'subtitle' => 'Grant new access from a student’s profile. Paid enrolments are created automatically after verified payment.',
    'from' => 'course_enrolments t JOIN users u ON u.id = t.user_id JOIN courses c ON c.id = t.course_id',
    'select' => 't.*, u.name, u.email, c.title',
    'order' => 't.enrolled_at DESC',
    'sortable' => ['name' => 'u.name', 'title' => 'c.title', 'progress_percent' => 't.progress_percent', 'enrolled_at' => 't.enrolled_at', 'expires_at' => 't.expires_at'],
    'bulk_status' => ['field' => 'status', 'label' => 'status', 'options' => ['active' => 'Active', 'revoked' => 'Revoked']],
    'search' => ['u.name', 'u.email', 'c.title'],
    'filters' => [
        'status' => ['col' => 't.status', 'label' => 'Statuses', 'options' => ['active' => 'Active', 'expired' => 'Expired', 'revoked' => 'Revoked']],
        'source' => ['col' => 't.source', 'label' => 'Sources', 'options' => ['purchase' => 'Purchase', 'free' => 'Free', 'admin' => 'Granted by staff']],
        'course' => ['col' => 't.course_id', 'label' => 'Courses', 'options' => fn() => options_from('SELECT id, title AS label FROM courses ORDER BY title')],
        // Admissions per batch (courses tagged with an academic session, migration 010)
        ...(course_batches_enabled() ? ['batch' => ['col' => 'c.academic_session', 'label' => 'Batches', 'options' => fn() => array_column(
            db_all("SELECT DISTINCT academic_session AS v, CONCAT('Batch ', academic_session) AS l FROM courses WHERE academic_session IS NOT NULL ORDER BY academic_session DESC"), 'l', 'v')]] : []),
    ],
    'columns' => [
        'name' => ['Student', fn($r) => '<div class="d1-main sm d1-media"><span class="d1-avatar" aria-hidden="true">' . e(mb_strtoupper(mb_substr((string) $r['name'], 0, 1))) . '</span><div class="d1-text">'
            . (can('users.view') ? '<a class="d1-t" href="users.php?id=' . (int) $r['user_id'] . '">' . e($r['name']) . '</a>' : '<span class="d1-t">' . e($r['name']) . '</span>')
            . '<div class="d1-sub"><span>' . e($r['email']) . '</span></div></div></div>'],
        'title' => ['Course', fn($r) => '<span class="d1-clip" style="min-width:160px;max-width:260px" title="' . e($r['title']) . '">' . e($r['title']) . '</span>'],
        'source' => ['Source', fn($r) => match ($r['source']) {
            'purchase' => '<span class="badge b-green">Purchase</span>',
            'admin' => '<span class="badge b-purple">Granted by staff</span>',
            default => '<span class="badge b-gray">' . e(ucfirst((string) $r['source'])) . '</span>',
        }],
        'progress_percent' => ['Progress', fn($r) => '<span class="d1-bar" role="img" aria-label="' . round((float) $r['progress_percent']) . '% complete"><span><i style="width:' . max(0, min(100, (float) $r['progress_percent'])) . '%"></i></span>' . round((float) $r['progress_percent']) . '%</span>'],
        'enrolled_at' => ['Enrolled', fn($r) => '<span class="d1-when">' . e(fmt_dt($r['enrolled_at'], 'j M Y')) . '</span>'],
        'expires_at' => ['Expires', fn($r) => '<span class="d1-when">' . e($r['expires_at'] ? fmt_dt($r['expires_at'], 'j M Y') : 'Lifetime') . '</span>'],
        'status' => ['Status', fn($r) => badge($r['status'] === 'active' && $r['expires_at'] && strtotime($r['expires_at']) <= time() ? 'expired' : $r['status'])],
    ],
    'fields' => [
        'status' => ['label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'expired' => 'Expired', 'revoked' => 'Revoked']],
        'expires_at' => ['label' => 'Access expires (blank = lifetime)', 'type' => 'datetime'],
    ],
    // An "active" enrolment with a past expiry would silently flip back to expired: require a future date.
    'before_save' => function (array $d, ?array $old) {
        if (($d['status'] ?? '') === 'active' && !empty($d['expires_at']) && strtotime($d['expires_at']) <= time()) {
            throw new CrudError(['expires_at' => 'To make this enrolment active, set a future expiry date or leave it empty for lifetime access.']);
        }
        return $d;
    },
    'after_save' => fn($id, $d) => audit_log('enrolment_edited', 'course_enrolments', $id, $d, 'warning'),
    // Bulk "Active" cannot revive enrolments whose access period is over.
    'after_bulk' => function (array $ids, string $value) {
        if ($value !== 'active' || !$ids) {
            return;
        }
        $n = db_query("UPDATE course_enrolments SET status = 'expired' WHERE id IN (" . db_placeholders(count($ids)) . ') AND expires_at IS NOT NULL AND expires_at <= NOW()', $ids)->rowCount();
        if ($n > 0) {
            flash('warning', "{$n} enrolment" . ($n === 1 ? '' : 's') . ' stayed expired because the access period is over. Edit them to set a new expiry date.');
        }
    },
]);
