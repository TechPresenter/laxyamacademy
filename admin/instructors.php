<?php
// Faculty profiles shown on course pages and live classes.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-crud.php';

crud([
    'table' => 'instructors',
    'title' => 'Faculty',
    'singular' => 'faculty member',
    'permission' => 'instructors.manage',
    'active' => 'instructors',
    'subtitle' => 'Publish only verified qualifications and experience.',
    'select' => 't.*, (SELECT COUNT(*) FROM courses c WHERE c.instructor_id = t.id) AS course_count',
    'order' => 't.name',
    'search' => ['t.name', 't.designation'],
    'filters' => ['status' => ['col' => 't.status', 'label' => 'Statuses', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']]],
    'sortable' => ['name' => 't.name', 'experience_years' => 't.experience_years', 'course_count' => 'course_count'],
    'columns' => [
        'name' => ['Name', function ($r) {
            $photo = $r['photo_path'] ? public_file_url($r['photo_path']) : null;
            return '<div class="d1-main d1-media">' . ($photo ? '<img class="d1-avatar" src="' . e($photo) . '" alt="" loading="lazy">' : '<span class="d1-avatar" aria-hidden="true">' . e(mb_strtoupper(mb_substr((string) $r['name'], 0, 1))) . '</span>')
                . '<div class="d1-text"><span class="d1-t">' . e($r['name']) . '</span><div class="d1-sub">' . ($r['designation'] ? '<span>' . e($r['designation']) . '</span>' : '<span>No designation yet</span>')
                . (!empty($r['is_demo']) ? '<span class="badge b-demo">Demo</span>' : '') . '</div></div></div>';
        }],
        'experience_years' => ['Experience', fn($r) => $r['experience_years'] !== null ? '<span class="d1-num">' . (int) $r['experience_years'] . '+ year' . ((int) $r['experience_years'] === 1 ? '' : 's') . '</span>' : '<span class="muted">Not set</span>'],
        'course_count' => ['Courses', fn($r) => (int) $r['course_count'] ? '<span class="d1-num">' . (int) $r['course_count'] . ' course' . ((int) $r['course_count'] === 1 ? '' : 's') . '</span>' : '<span class="muted">None</span>'],
        'status' => ['Status', fn($r) => badge($r['status'])],
    ],
    'fields' => [
        'name' => ['label' => 'Full name', 'required' => true, 'max' => 120],
        'slug' => ['label' => 'Slug', 'type' => 'slug', 'from' => 'name', 'required' => true, 'unique' => true],
        'designation' => ['label' => 'Designation / subjects', 'max' => 160],
        'experience_years' => ['label' => 'Experience (years)', 'type' => 'number', 'min' => 0, 'max' => 60],
        'photo_path' => ['label' => 'Photo', 'type' => 'image', 'folder' => 'profiles', 'w' => 400, 'h' => 400, 'crop' => true],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'required' => true, 'default' => 'active'],
        'bio' => ['label' => 'Biography', 'type' => 'textarea'],
        'highlights' => ['label' => 'Highlights (one per line)', 'type' => 'lines', 'hint' => 'e.g. "Guided 500+ aspirants" - only verified facts.'],
        'is_demo' => ['label' => 'Demo profile (shows a DEMO tag on the website)', 'type' => 'checkbox'],
    ],
]);
