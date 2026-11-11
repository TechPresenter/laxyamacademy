<?php
// Course reviews moderation. Students submit reviews from the website (students.php?action=review, status pending);
// only approved reviews are shown publicly and count towards the cached course rating.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';
require_once APP_ROOT . '/includes/catalog.php'; // refresh_course_rating()

/** Set the status of some reviews, remember who moderated them and refresh the affected course ratings. */
function moderate_reviews(array $ids, string $status, int $staffId): int
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || !in_array($status, ['approved', 'rejected', 'pending'], true)) {
        return 0;
    }
    $ph = db_placeholders(count($ids));
    $courses = array_column(db_all("SELECT DISTINCT course_id FROM course_reviews WHERE id IN ({$ph})", $ids), 'course_id');
    // approved_by records the moderator of the latest decision (cleared when a review goes back to pending).
    db_query("UPDATE course_reviews SET status = ?, approved_by = ? WHERE id IN ({$ph})", [$status, $status === 'pending' ? null : $staffId, ...$ids]);
    foreach ($courses as $courseId) {
        refresh_course_rating((int) $courseId);
    }
    return count($ids);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($_POST['_action'] ?? '', ['approve', 'reject'], true)) {
    $me = require_staff_page();
    require_csrf();
    require_permission('courses.manage');
    $id = (int) ($_POST['id'] ?? 0);
    if (db_value('SELECT 1 FROM course_reviews WHERE id = ?', [$id])) {
        $approve = $_POST['_action'] === 'approve';
        moderate_reviews([$id], $approve ? 'approved' : 'rejected', (int) $me['id']);
        audit_log('review_' . $_POST['_action'] . 'd', 'course_reviews', $id);
        flash('success', 'Review ' . ($approve ? 'approved and published.' : 'rejected.'));
    }
    redirect_to(url_with([]));
}

crud([
    'table' => 'course_reviews',
    'title' => 'Course Reviews',
    'singular' => 'review',
    'permission' => 'courses.manage',
    'active' => 'courses',
    'can_create' => false,
    'can_edit' => false,
    'subtitle' => 'Students review courses they are enrolled in. Only approved reviews are shown on the website and count towards the star rating.',
    'from' => 'course_reviews t JOIN users u ON u.id = t.user_id JOIN courses c ON c.id = t.course_id LEFT JOIN users m ON m.id = t.approved_by',
    'select' => 't.*, u.name, c.title, m.name AS moderator',
    'order' => "FIELD(t.status, 'pending', 'approved', 'rejected'), t.created_at DESC",
    'sortable' => ['rating' => 't.rating', 'created_at' => 't.created_at'],
    'search' => ['u.name', 'c.title', 't.review'],
    'filters' => [
        'status' => ['col' => 't.status', 'label' => 'Statuses', 'options' => ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']],
        'for_course' => ['col' => 't.course_id', 'label' => 'Courses', 'options' => fn() => options_from('SELECT id, title AS label FROM courses ORDER BY title')],
    ],
    'bulk_status' => ['field' => 'status', 'label' => 'status', 'options' => ['approved' => 'Approved', 'rejected' => 'Rejected', 'pending' => 'Pending']],
    // crud_bulk has already set the status; record the moderator and refresh the cached ratings.
    'after_bulk' => function (array $ids, string $value) {
        moderate_reviews($ids, $value, (int) ($GLOBALS['__admin_me']['id'] ?? current_user()['id'] ?? 0));
    },
    'columns' => [
        'name' => ['Student', fn($r) => '<div class="d1-main sm d1-media"><span class="d1-avatar" aria-hidden="true">' . e(mb_strtoupper(mb_substr((string) $r['name'], 0, 1))) . '</span>'
            . '<div class="d1-text">' . (can('users.view') ? '<a class="d1-t" href="users.php?id=' . (int) $r['user_id'] . '">' . e($r['name']) . '</a>' : '<span class="d1-t">' . e($r['name']) . '</span>') . '<div class="d1-sub"><span title="' . e($r['title']) . '">' . e(mb_strimwidth((string) $r['title'], 0, 48, '…')) . '</span></div></div></div>'],
        'rating' => ['Rating', fn($r) => '<span class="d1-stars" role="img" aria-label="' . (int) $r['rating'] . ' out of 5 stars">' . str_repeat('★', (int) $r['rating']) . '<span>' . str_repeat('★', 5 - (int) $r['rating']) . '</span></span>'],
        'review' => ['Review', fn($r) => $r['review'] !== null && $r['review'] !== '' ? '<span class="d1-clip" title="' . e($r['review']) . '">' . e((string) $r['review']) . '</span>' : '<span class="muted">Rating only, no text</span>'],
        'created_at' => ['Date', fn($r) => '<span class="d1-when">' . e(fmt_dt($r['created_at'], 'j M Y')) . '<small>' . e(fmt_dt($r['created_at'], 'g:i A')) . '</small></span>'],
        'status' => ['Status', fn($r) => '<div class="d1-stack">' . badge($r['status']) . ($r['moderator'] && $r['status'] !== 'pending' ? '<small>by ' . e($r['moderator']) . '</small>' : '') . '</div>'],
    ],
    'row_actions' => fn($r) => '<span class="d1-acts">' . ($r['status'] !== 'approved' ? action_form('approve', ['id' => $r['id']], icon('check') . ' Approve', 'btn btn-light btn-sm') : '')
        . ($r['status'] !== 'rejected' ? action_form('reject', ['id' => $r['id']], icon('close') . ' Reject', 'btn btn-danger btn-sm', 'Reject this review? It will not be shown on the website.') : '') . '</span>',
    'can_delete' => false,
    'fields' => [],
]);
