<?php
// Testimonials & success stories with an approval workflow.
// Success stories are public only when status = approved AND is_verified = 1.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';

// One-click approve / reject from the list
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($_POST['_action'] ?? '', ['approve', 'reject'], true)) {
    require_staff_page();
    require_csrf();
    require_permission('testimonials.manage');
    $approve = $_POST['_action'] === 'approve';
    db_query('UPDATE testimonials SET status = ?, approved_by = ?, approved_at = ? WHERE id = ?',
        [$approve ? 'approved' : 'rejected', $approve ? current_user()['id'] : null, $approve ? date('Y-m-d H:i:s') : null, (int) $_POST['id']]);
    audit_log($approve ? 'testimonial_approved' : 'testimonial_rejected', 'testimonials', (int) $_POST['id']);
    flash('success', $approve ? 'Approved and published.' : 'Rejected.');
    redirect_to(url_with([]));
}

crud([
    'table' => 'testimonials',
    'title' => 'Testimonials & Success Stories',
    'singular' => 'entry',
    'permission' => 'testimonials.manage',
    'active' => 'testimonials',
    'subtitle' => 'Publish only genuine feedback. Success stories (ranks/results) also need "Verified" ticked after checking proof.',
    'order' => "FIELD(t.status, 'pending', 'approved', 'rejected'), t.sort_order, t.id DESC",
    'search' => ['t.name', 't.content', 't.exam_name'],
    'sortable' => ['name' => 't.name', 'status' => 't.status', 'type' => 't.type'],
    'bulk_status' => ['field' => 'status', 'label' => 'status', 'options' => ['pending' => 'Pending', 'rejected' => 'Rejected']],
    'filters' => [
        'status' => ['col' => 't.status', 'label' => 'Statuses', 'options' => ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']],
        'type' => ['col' => 't.type', 'label' => 'Types', 'options' => ['testimonial' => 'Testimonial', 'success_story' => 'Success story']],
    ],
    'columns' => [
        'name' => ['Student', fn($r) => '<div style="min-width:170px"><strong>' . e($r['name']) . '</strong>' . demo_badge($r) . (trim(($r['exam_name'] ?? '') . ($r['result_label'] ?? '')) !== '' ? '<span class="d2-sub">' . e(trim(($r['exam_name'] ?? '') . ' ' . ($r['exam_year'] ?? ''))) . ($r['result_label'] ? ' · ' . e($r['result_label']) : '') . '</span>' : '') . '</div>'],
        'type' => ['Type', fn($r) => $r['type'] === 'success_story' ? '<span class="badge b-purple">Success story</span>' : '<span class="badge b-blue">Testimonial</span>'],
        'content' => ['Content', fn($r) => '<span class="d2-sub d2-clip d2-w200" title="' . e($r['content']) . '">' . e($r['content']) . '</span>'],
        'is_verified' => ['Proof', fn($r) => $r['is_verified'] ? '<span class="badge b-green">Verified</span>' : '<span class="badge b-gray">Not verified</span>'],
        'status' => ['Status', fn($r) => badge($r['status'])],
    ],
    // Approve / Reject as compact icon buttons (tooltip + aria-label), so Edit and Delete stay visible without sideways scrolling.
    'row_actions' => fn($r) => $r['status'] === 'pending'
        ? row_actions([
            ['label' => 'Approve ' . $r['name'], 'post' => ['approve', ['id' => $r['id']]], 'icon' => 'check']
                + ($r['type'] === 'success_story' && !$r['is_verified'] ? ['confirm' => 'This success story is not verified and will stay hidden until verified. Approve anyway?'] : []),
            ['label' => 'Reject ' . $r['name'], 'post' => ['reject', ['id' => $r['id']]], 'icon' => 'close', 'danger' => true],
        ])
        : '',
    'before_save' => function (array $d, ?array $old) {
        if ($d['status'] === 'approved' && ($old['status'] ?? null) !== 'approved') {
            $d['approved_by'] = $GLOBALS['__admin_me']['id'] ?? null;
            $d['approved_at'] = date('Y-m-d H:i:s');
        }
        return $d;
    },
    'fields' => [
        'type' => ['label' => 'Type', 'type' => 'select', 'required' => true, 'default' => 'testimonial', 'options' => ['testimonial' => 'Testimonial', 'success_story' => 'Success story (result/rank)']],
        'name' => ['label' => 'Student name', 'required' => true, 'max' => 120],
        'exam_name' => ['label' => 'Exam', 'max' => 100],
        'exam_year' => ['label' => 'Year', 'type' => 'number', 'min' => 2000, 'max' => 2100],
        'result_label' => ['label' => 'Result (e.g. AIR 45) - only after verification', 'max' => 60],
        'rating' => ['label' => 'Rating (1-5)', 'type' => 'number', 'min' => 1, 'max' => 5],
        'photo_path' => ['label' => 'Photo (with consent)', 'type' => 'image', 'folder' => 'profiles', 'w' => 300, 'h' => 300, 'crop' => true],
        'sort_order' => ['label' => 'Sort order', 'type' => 'number', 'default' => 0],
        'content' => ['label' => 'Testimonial / story', 'type' => 'textarea', 'required' => true],
        'is_verified' => ['label' => 'Verified (result/identity checked)', 'type' => 'checkbox'],
        'status' => ['label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'pending', 'options' => ['pending' => 'Pending', 'approved' => 'Approved (public)', 'rejected' => 'Rejected']],
        'is_demo' => ['label' => 'Demo content', 'type' => 'checkbox'],
    ],
]);
