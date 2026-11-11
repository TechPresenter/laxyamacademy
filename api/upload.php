<?php
/**
 * File uploads (multipart/form-data, field name "file", CSRF header required).
 *
 *   POST upload.php?type=avatar          student profile photo (re-encoded to WebP, 256x256)
 *   POST upload.php?type=image&folder=courses|blog|profiles|cms|resources   staff only
 *   POST upload.php?type=private&folder=lessons|resources                  staff only, stored outside public access
 *
 * Validation and storage live in includes/functions.php (store_image_upload / store_private_upload):
 * images are always re-encoded with GD and files are checked by content, not extension.
 */
declare(strict_types=1);

require __DIR__ . '/config.php';

require_method('POST');
require_csrf();

$type = $_GET['type'] ?? '';
$folder = $_GET['folder'] ?? '';
$file = $_FILES['file'] ?? [];

try {
    switch ($type) {
        case 'avatar':
            $user = require_student();
            $path = store_image_upload($file, 'profiles', 256, 256, true, 3 * 1_048_576);
            $old = db_value('SELECT avatar_path FROM users WHERE id = ?', [$user['id']]);
            db_query('UPDATE users SET avatar_path = ? WHERE id = ?', [$path, $user['id']]);
            if ($old && str_starts_with($old, 'uploads/profiles/') && is_file(APP_ROOT . '/' . $old)) {
                unlink(APP_ROOT . '/' . $old);
            }
            api_ok(['path' => $path, 'url' => public_file_url($path)], 201);

        case 'image':
            $perm = ['courses' => 'courses.manage', 'blog' => 'blog.manage', 'profiles' => 'instructors.manage', 'cms' => 'cms.manage', 'resources' => 'resources.manage'][$folder] ?? null;
            if (!$perm) {
                api_error('Unknown upload folder.', 422, 'bad_folder');
            }
            require_permission($perm);
            $path = store_image_upload($file, $folder, 1600, 1600, false, min(upload_limit_bytes(), 8 * 1_048_576));
            audit_log('file_uploaded', 'upload', $path);
            api_ok(['path' => $path, 'url' => public_file_url($path)], 201);

        case 'private':
            $perm = ['lessons' => 'lessons.manage', 'resources' => 'resources.manage'][$folder] ?? null;
            if (!$perm) {
                api_error('Unknown upload folder.', 422, 'bad_folder');
            }
            require_permission($perm);
            $stored = store_private_upload($file, $folder, upload_limit_bytes());
            audit_log('private_file_uploaded', 'upload', $stored['path'], ['size' => $stored['size'], 'type' => $stored['type']]);
            api_ok($stored + ['original_name' => mb_substr((string) ($file['name'] ?? ''), 0, 120)], 201);

        default:
            api_error('Unknown upload type.', 422, 'bad_type');
    }
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 422, 'invalid_upload');
}
