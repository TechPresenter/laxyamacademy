<?php
// Student documents: review, verify or reject (with a note shown to the student), download privately.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';

if (isset($_GET['download'])) {
    require_staff_page();
    require_permission('users.view');
    $d = db_one('SELECT file_path, title FROM student_documents WHERE id = ?', [(int) $_GET['download']]);
    $path = $d ? private_file_path($d['file_path']) : null;
    if (!$path) {
        http_response_code(404);
        exit('Document not found.');
    }
    audit_log('student_document_viewed', 'student_documents', (int) $_GET['download']);
    stream_file($path, slugify($d['title']) . '.' . pathinfo($path, PATHINFO_EXTENSION), !isset($_GET['save']));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($_POST['_action'] ?? '', ['verify', 'reject'], true)) {
    $me = require_staff_page();
    require_csrf();
    require_permission('users.manage');
    $doc = db_one('SELECT * FROM student_documents WHERE id = ?', [(int) $_POST['id']]);
    if ($doc) {
        $status = $_POST['_action'] === 'verify' ? 'verified' : 'rejected';
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255) ?: null;
        db_query('UPDATE student_documents SET status = ?, note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?', [$status, $note, $me['id'], $doc['id']]);
        notify((int) $doc['user_id'], 'document', 'Document ' . $status . ': ' . $doc['title'], $note, '/dashboard/settings#documents');
        audit_log('student_document_' . $status, 'student_documents', $doc['id']);
        flash('success', 'Document ' . $status . '.');
    }
    redirect_to(url_with([]));
}

crud([
    'table' => 'student_documents',
    'title' => 'Student Documents',
    'singular' => 'document',
    'permission' => 'users.view',
    'manage_permission' => 'users.manage',
    'active' => 'users',
    'can_create' => false,
    'can_edit' => false,
    'can_delete' => false,
    'subtitle' => 'Uploaded by students from their dashboard. Files are private and opened only through this page.',
    'from' => 'student_documents t JOIN users u ON u.id = t.user_id',
    'select' => 't.*, u.name, u.email',
    'order' => "FIELD(t.status, 'pending', 'rejected', 'verified'), t.id DESC",
    'sortable' => ['created_at' => 't.created_at', 'name' => 'u.name'],
    'search' => ['u.name', 'u.email', 't.title'],
    'filters' => [
        'status' => ['col' => 't.status', 'label' => 'Statuses', 'options' => ['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected']],
        'type' => ['col' => 't.doc_type', 'label' => 'Types', 'options' => fn() => lookup_options('document_type')],
    ],
    'columns' => [
        'name' => ['Student', fn($r) => '<div class="d1-main sm"><a class="d1-t" href="users.php?id=' . (int) $r['user_id'] . '">' . e($r['name']) . '</a><div class="d1-sub"><span>' . e($r['email']) . '</span></div></div>'],
        'title' => ['Document', fn($r) => '<div class="d1-main sm"><span class="d1-t">' . e($r['title']) . '</span><div class="d1-sub"><span>' . e(lookup_label('document_type', $r['doc_type'])) . '</span><span>' . e(strtoupper(pathinfo($r['file_path'], PATHINFO_EXTENSION))) . '</span></div></div>'],
        'created_at' => ['Uploaded', fn($r) => '<span class="d1-when">' . e(fmt_dt($r['created_at'], 'j M Y')) . '<small>' . e(fmt_dt($r['created_at'], 'g:i A')) . '</small></span>'],
        'status' => ['Status', fn($r) => '<div class="d1-stack">' . badge($r['status']) . ($r['note'] ? '<small class="d1-clip" style="min-width:0;max-width:220px;white-space:normal" title="' . e($r['note']) . '">' . e($r['note']) . '</small>' : '') . '</div>'],
    ],
    'row_actions' => function ($r) {
        $h = '<a class="btn btn-light btn-sm" href="?download=' . (int) $r['id'] . '" target="_blank" rel="noopener">' . icon('eye') . ' View</a>';
        if (can('users.manage') && $r['status'] !== 'verified') {
            $h .= action_form('verify', ['id' => $r['id']], icon('check') . ' Verify', 'btn btn-light btn-sm', 'Mark this document as verified? The student is notified.');
        }
        if (can('users.manage') && $r['status'] === 'pending') {
            $h .= '<form method="post" class="d1-inline-form">' . csrf_field() . '<input type="hidden" name="_action" value="reject"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><input class="in" name="note" placeholder="Reason" style="width:140px" maxlength="255" aria-label="Rejection reason (shown to the student)" required><button class="btn btn-danger btn-sm">' . icon('close') . ' Reject</button></form>';
        }
        return '<span class="d1-acts">' . $h . '</span>';
    },
    'fields' => [],
]);
