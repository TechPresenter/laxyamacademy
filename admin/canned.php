<?php
// Canned responses for support replies ({name} is replaced with the student's first name).
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';

crud([
    'table' => 'canned_responses',
    'title' => 'Canned Responses',
    'singular' => 'response',
    'permission' => 'support.manage',
    'active' => 'support',
    'subtitle' => 'Saved replies you can insert while answering tickets. Use {name} for the student’s first name.',
    'order' => 't.title',
    'search' => ['t.title', 't.body'],
    'columns' => [
        'title' => ['Title', fn($r) => '<strong>' . e($r['title']) . '</strong>'],
        'body' => ['Reply', fn($r) => '<span class="d2-sub d2-clip" style="max-width:640px" title="' . e($r['body']) . '">' . e($r['body']) . '</span>'],
    ],
    'fields' => [
        'title' => ['label' => 'Title', 'required' => true, 'max' => 120, 'full' => true, 'placeholder' => 'e.g. Course access after payment'],
        'body' => ['label' => 'Reply text', 'type' => 'textarea', 'required' => true, 'rows' => 6, 'hint' => '{name} becomes the first name of the student when you insert this reply into a ticket.'],
    ],
]);
