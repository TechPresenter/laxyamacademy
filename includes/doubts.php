<?php
/**
 * Student doubts: one thread between a student and the teachers about a lesson of an enrolled
 * course or a question of a finished mock test. Students ask from the lesson player or the answer
 * review (api/doubts.php); staff with doubts.manage answer in admin/doubts.php and the student gets
 * an in-app + email notification (template doubt_answered). Photos are re-encoded (hidden data
 * stripped) and stored privately - only the student and staff can open them.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once APP_ROOT . '/includes/exams.php';

const DOUBT_MAX_BODY = 4000;
const DOUBT_STATUSES = ['open' => 'Waiting for an answer', 'answered' => 'Answered', 'closed' => 'Resolved'];
const DOUBT_DAILY_LIMIT = 15;   // new doubts per student per day
const DOUBT_MESSAGE_LIMIT = 60; // messages per student per day

/** Database update 013 applied? */
function doubts_ready(): bool
{
    static $on = null;
    return $on ??= (bool) db_value("SHOW TABLES LIKE 'doubt_messages'");
}

/** Lesson context for a new doubt, or the reason it cannot be asked there. */
function doubt_lesson_context(int $lessonId, array $user): array|string
{
    $l = db_one("SELECT l.id, l.title, l.course_id, c.title AS course_title, c.status AS course_status FROM lessons l JOIN courses c ON c.id = l.course_id
                 WHERE l.id = ? AND l.status = 'published'", [$lessonId]);
    if (!$l || !in_array($l['course_status'], COURSE_OPEN_TO_ENROLLED, true) || !active_enrolment((int) $user['id'], (int) $l['course_id'])) {
        return 'Doubts can be asked on lessons of courses you are enrolled in.';
    }
    return ['course_id' => (int) $l['course_id'], 'lesson_id' => (int) $l['id'], 'subject' => mb_substr($l['course_title'] . ' › ' . $l['title'], 0, 190)];
}

/** Mock test question context: only a question of the student's own finished attempt. */
function doubt_question_context(int $attemptId, int $questionId, array $user): array|string
{
    $a = db_one('SELECT a.*, e.title AS exam_title FROM exam_attempts a JOIN exams e ON e.id = a.exam_id WHERE a.id = ? AND a.user_id = ?', [$attemptId, $user['id']]);
    if (!$a || $a['status'] === 'in_progress') {
        return 'Doubts on a test can be asked after you submit it.';
    }
    $pos = array_search($questionId, exam_attempt_question_ids($a), true);
    if ($pos === false) {
        return 'That question is not part of your attempt.';
    }
    return ['exam_id' => (int) $a['exam_id'], 'question_id' => $questionId, 'attempt_id' => $attemptId, 'subject' => mb_substr($a['exam_title'] . ' › Q' . ($pos + 1), 0, 190)];
}

/** Clean message text: trimmed, at most DOUBT_MAX_BODY characters, no control characters except new lines. */
function doubt_clean_body(mixed $raw): string
{
    $text = preg_replace('/[^\P{C}\n]/u', '', str_replace("\r\n", "\n", is_string($raw) ? $raw : ''));
    return mb_substr(trim((string) $text), 0, DOUBT_MAX_BODY);
}

/** Re-encode an uploaded photo to WebP (strips EXIF / hidden data) and keep it private. Returns its stored path. */
function doubt_store_image(array $file): string
{
    if (!uploaded_file_ok($file)) {
        throw new InvalidArgumentException('Choose a photo to attach.');
    }
    if ($file['size'] > 6 * 1_048_576) {
        throw new InvalidArgumentException('The photo must be smaller than 6 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $src = in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) && @getimagesize($file['tmp_name'])
        ? @imagecreatefromstring((string) file_get_contents($file['tmp_name'])) : false;
    if (!$src) {
        throw new InvalidArgumentException('Attach a JPG, PNG or WebP photo.');
    }
    [$w, $h] = [imagesx($src), imagesy($src)];
    if ($w > 8000 || $h > 8000) {
        throw new InvalidArgumentException('The photo dimensions are too large.');
    }
    $scale = min(1, 1600 / $w, 1600 / $h);
    $dst = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
    $tmp = tempnam(sys_get_temp_dir(), 'dbt');
    if (!imagewebp($dst, $tmp, 80)) {
        throw new RuntimeException('The photo could not be saved.');
    }
    $sub = 'doubts/' . date('Y/m');
    $name = bin2hex(random_bytes(12)) . '.webp';
    require_once APP_ROOT . '/includes/storage.php';
    if (s3_active()) {
        $path = s3_put($tmp, $sub . '/' . $name, 'image/webp');
        @unlink($tmp);
        return $path;
    }
    if (!is_dir(PRIVATE_UPLOADS . '/' . $sub)) {
        mkdir(PRIVATE_UPLOADS . '/' . $sub, 0750, true);
    }
    if (!rename($tmp, PRIVATE_UPLOADS . '/' . $sub . '/' . $name)) {
        @unlink($tmp);
        throw new RuntimeException('The photo could not be saved.');
    }
    return $sub . '/' . $name;
}

/** Start a doubt with its first message. Returns the doubt id. */
function doubt_create(int $userId, array $ctx, string $body, ?string $image): int
{
    return db_transaction(function () use ($userId, $ctx, $body, $image): int {
        db_query('INSERT INTO doubts (user_id, course_id, lesson_id, exam_id, question_id, attempt_id, subject, status, last_reply_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$userId, $ctx['course_id'] ?? null, $ctx['lesson_id'] ?? null, $ctx['exam_id'] ?? null, $ctx['question_id'] ?? null, $ctx['attempt_id'] ?? null, $ctx['subject'], 'open']);
        $id = (int) db()->lastInsertId();
        db_query('INSERT INTO doubt_messages (doubt_id, user_id, is_staff, body, image_path) VALUES (?, ?, 0, ?, ?)', [$id, $userId, $body, $image]);
        return $id;
    });
}

/**
 * Add a message. A teacher's message marks the doubt answered (unread for the student) and notifies
 * the student; a student's message re-opens it for the teachers.
 */
function doubt_add_message(array $doubt, int $authorId, bool $staff, string $body, ?string $image): void
{
    db_transaction(function () use ($doubt, $authorId, $staff, $body, $image) {
        db_query('INSERT INTO doubt_messages (doubt_id, user_id, is_staff, body, image_path) VALUES (?, ?, ?, ?, ?)', [$doubt['id'], $authorId, $staff ? 1 : 0, $body, $image]);
        db_query('UPDATE doubts SET status = ?, student_unread = ?, last_reply_at = NOW()' . ($staff ? ', assigned_to = COALESCE(assigned_to, ?)' : '') . ' WHERE id = ?',
            $staff ? ['answered', 1, $authorId, $doubt['id']] : ['open', 0, $doubt['id']]);
    });
    if ($staff) {
        try {
            require_once APP_ROOT . '/includes/notify.php';
            send_template('doubt_answered', (int) $doubt['user_id'], [
                'course' => $doubt['subject'], 'message' => mb_strimwidth(preg_replace('/\s+/', ' ', $body), 0, 280, '…'),
                'link' => '/dashboard/doubts/' . (int) $doubt['id'], 'button_label' => 'Read the answer', 'related' => ['doubt', (int) $doubt['id']],
            ]);
        } catch (Throwable $e) {
            error_log('[laxyam] doubt_answered notification failed for doubt ' . $doubt['id'] . ': ' . $e->getMessage());
        }
    }
}

/** Messages of a doubt for display (photos as links to the protected image endpoint). */
function doubt_messages(int $doubtId, string $imageBase): array
{
    return array_map(fn($m) => [
        'id' => (int) $m['id'],
        'staff' => (bool) $m['is_staff'],
        'author' => $m['is_staff'] ? ($m['author'] ?: 'Teacher') : ($m['author'] ?: 'Student'),
        'body' => $m['body'],
        'image_url' => $m['image_path'] ? $imageBase . (int) $m['id'] : null,
        'created_at' => iso_datetime($m['created_at']),
    ], db_all('SELECT m.*, u.name AS author FROM doubt_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.doubt_id = ? ORDER BY m.id', [$doubtId]));
}

/** The mock test question a doubt is about (text, options, the correct answer for staff). */
function doubt_question_snapshot(array $doubt, bool $withKey): ?array
{
    if (!$doubt['question_id']) {
        return null;
    }
    $q = db_one('SELECT id, question_text, ' . exam_q_extra_cols() . ' FROM exam_questions WHERE id = ?', [$doubt['question_id']]);
    if (!$q) {
        return null;
    }
    $opts = db_all('SELECT id, option_text, ' . exam_o_extra_cols() . ', is_correct FROM exam_options WHERE question_id = ? ORDER BY sort_order, id', [$q['id']]);
    return [
        'text' => $q['question_text'],
        'text_hi' => exam_hi($q['question_text_hi']),
        'options' => array_map(fn($o) => ['text' => $o['option_text'], 'correct' => $withKey ? (bool) $o['is_correct'] : null], $opts),
    ];
}
