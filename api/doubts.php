<?php
/**
 * Student doubts API (student accounts; teachers answer in admin/doubts.php - includes/doubts.php).
 *
 *   GET  doubts.php?action=mine                                   -> my doubts (latest activity first) + unread count
 *   GET  doubts.php?action=thread&id=                             -> one doubt with its messages (marks the answer read)
 *   GET  doubts.php?action=for&lesson_id= | &attempt_id=&question_id=  -> my doubts on that lesson / question
 *   POST doubts.php?action=ask      lesson_id | attempt_id + question_id, body, file (optional photo; multipart)
 *   POST doubts.php?action=reply    id, body, file (optional)
 *   POST doubts.php?action=close    id                            -> mark resolved
 *   GET  doubts.php?action=image&m= -> a photo attached to one of my doubts
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once APP_ROOT . '/includes/doubts.php';

$action = $_GET['action'] ?? '';
if (in_array($action, ['ask', 'reply', 'close'], true)) {
    require_method('POST');
    require_csrf();
} else {
    require_method('GET');
}
if (!doubts_ready()) {
    api_error('Doubts are not available yet.', 503, 'not_available');
}
$user = require_student();

match ($action) {
    'mine' => my_doubts($user),
    'thread' => thread($user),
    'for' => doubts_for($user),
    'ask' => ask($user),
    'reply' => reply($user),
    'close' => close_doubt($user),
    'image' => image($user),
    default => api_error('Unknown action.', 404, 'not_found'),
};

function doubt_card(array $d): array
{
    return [
        'id' => (int) $d['id'],
        'subject' => $d['subject'],
        'status' => $d['status'],
        'status_label' => DOUBT_STATUSES[$d['status']] ?? $d['status'],
        'unread' => (bool) $d['student_unread'],
        'kind' => $d['question_id'] || $d['exam_id'] ? 'test' : 'lesson',
        'preview' => isset($d['last_body']) ? mb_strimwidth(preg_replace('/\s+/', ' ', (string) $d['last_body']), 0, 140, '…') : null,
        'messages' => isset($d['message_count']) ? (int) $d['message_count'] : null,
        'created_at' => iso_datetime($d['created_at']),
        'last_reply_at' => iso_datetime($d['last_reply_at']),
    ];
}

function own_doubt(int $id, array $user): array
{
    $d = db_one('SELECT * FROM doubts WHERE id = ? AND user_id = ?', [$id, $user['id']]);
    if (!$d) {
        api_error('Doubt not found.', 404, 'not_found');
    }
    return $d;
}

function my_doubts(array $user): never
{
    $rows = db_all(
        'SELECT d.*, (SELECT m.body FROM doubt_messages m WHERE m.doubt_id = d.id ORDER BY m.id DESC LIMIT 1) AS last_body,
                (SELECT COUNT(*) FROM doubt_messages m WHERE m.doubt_id = d.id) AS message_count
         FROM doubts d WHERE d.user_id = ? ORDER BY d.student_unread DESC, COALESCE(d.last_reply_at, d.created_at) DESC LIMIT 200',
        [$user['id']]
    );
    api_ok(['items' => array_map('doubt_card', $rows), 'unread' => count(array_filter($rows, fn($r) => $r['student_unread']))]);
}

function thread(array $user): never
{
    $d = own_doubt(query_int('id', 0, 0), $user);
    if ($d['student_unread']) {
        db_query('UPDATE doubts SET student_unread = 0 WHERE id = ?', [$d['id']]);
    }
    $context = null;
    if ($d['lesson_id']) {
        $l = db_one('SELECT l.id, l.title, c.slug AS course_slug, c.title AS course_title FROM lessons l JOIN courses c ON c.id = l.course_id WHERE l.id = ?', [$d['lesson_id']]);
        $context = $l ? ['type' => 'lesson', 'title' => $l['title'], 'course' => $l['course_title'], 'url' => '/learn/' . $l['course_slug'] . '/' . (int) $l['id']] : null;
    } elseif ($d['attempt_id']) {
        $e = db_one('SELECT slug, title FROM exams WHERE id = ?', [$d['exam_id']]);
        $context = ['type' => 'test', 'title' => $d['subject'], 'course' => $e['title'] ?? null, 'url' => $e ? '/mock-tests/' . $e['slug'] . '/result/' . (int) $d['attempt_id'] : null,
            'question' => doubt_question_snapshot($d, false)];
    }
    api_ok(doubt_card($d) + ['context' => $context, 'thread' => doubt_messages((int) $d['id'], 'doubts.php?action=image&m=')]);
}

function doubts_for(array $user): never
{
    $lessonId = query_int('lesson_id', 0, 0);
    $questionId = query_int('question_id', 0, 0);
    $rows = match (true) {
        $lessonId > 0 => db_all('SELECT * FROM doubts WHERE user_id = ? AND lesson_id = ? ORDER BY id DESC LIMIT 20', [$user['id'], $lessonId]),
        $questionId > 0 => db_all('SELECT * FROM doubts WHERE user_id = ? AND question_id = ? AND attempt_id = ? ORDER BY id DESC LIMIT 20', [$user['id'], $questionId, query_int('attempt_id', 0, 0)]),
        default => [],
    };
    api_ok(['items' => array_map('doubt_card', $rows)]);
}

/** Body + optional photo from a JSON or multipart request (422 on anything invalid). */
function message_input(): array
{
    $in = request_body();
    $body = doubt_clean_body($in['body'] ?? '');
    if (mb_strlen($body) < 5) {
        api_error('Please describe your doubt (at least a few words).', 422, 'validation_failed', ['body' => 'Write your doubt here.']);
    }
    $image = null;
    if (isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $image = doubt_store_image($_FILES['file']);
        } catch (InvalidArgumentException $e) {
            api_error($e->getMessage(), 422, 'validation_failed', ['file' => $e->getMessage()]);
        }
    }
    return [$in, $body, $image];
}

function ask(array $user): never
{
    if (!rate_limit('doubt-ask', (string) $user['id'], DOUBT_DAILY_LIMIT, 86400)) {
        api_error('You have asked many doubts today. Please continue tomorrow, or add to an existing doubt.', 429, 'rate_limited');
    }
    [$in, $body, $image] = message_input();
    $ctx = !empty($in['lesson_id'])
        ? doubt_lesson_context((int) $in['lesson_id'], $user)
        : doubt_question_context((int) ($in['attempt_id'] ?? 0), (int) ($in['question_id'] ?? 0), $user);
    if (is_string($ctx)) {
        api_error($ctx, 403, 'not_allowed');
    }
    $id = doubt_create((int) $user['id'], $ctx, $body, $image);
    audit_log('doubt_asked', 'doubt', $id);
    api_ok(['id' => $id, 'url' => '/dashboard/doubts/' . $id], 201);
}

function reply(array $user): never
{
    $d = own_doubt((int) (request_body()['id'] ?? 0), $user);
    if (!rate_limit('doubt-msg', (string) $user['id'], DOUBT_MESSAGE_LIMIT, 86400)) {
        api_error('Too many messages today. Please continue tomorrow.', 429, 'rate_limited');
    }
    [, $body, $image] = message_input();
    doubt_add_message($d, (int) $user['id'], false, $body, $image);
    api_ok(['id' => (int) $d['id']]);
}

function close_doubt(array $user): never
{
    $d = own_doubt((int) (request_body()['id'] ?? 0), $user);
    db_query("UPDATE doubts SET status = 'closed', student_unread = 0 WHERE id = ?", [$d['id']]);
    api_ok(['id' => (int) $d['id'], 'status' => 'closed']);
}

function image(array $user): never
{
    $m = db_one('SELECT m.id, m.image_path FROM doubt_messages m JOIN doubts d ON d.id = m.doubt_id WHERE m.id = ? AND d.user_id = ?', [query_int('m', 0, 0), $user['id']]);
    $full = $m ? private_file_path($m['image_path']) : null;
    if (!$full) {
        api_error('Photo not found.', 404, 'not_found');
    }
    stream_file($full, 'doubt-photo-' . (int) $m['id'] . '.webp');
}
