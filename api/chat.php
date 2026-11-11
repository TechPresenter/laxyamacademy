<?php
/**
 * Website chat assistant (the chat bubble on every public page).
 *
 *   POST chat.php?action=message   JSON: conversation_id?, message, page  -> reply, suggestions, lead_prompt
 *   POST chat.php?action=lead      JSON: conversation_id?, name, phone, email?, course_slug?, consent, website (honeypot)
 *   GET  chat.php?action=history&conversation_id=...                      -> earlier messages of this browser's chat
 *
 * Replies come from includes/chatbot.php (Claude when configured, built-in answers otherwise).
 * Callback requests become enquiries with source "chatbot". Staff see chats in admin/chatbot.php.
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require APP_ROOT . '/includes/chatbot.php';

if (!chatbot_enabled()) {
    api_error('The chat assistant is switched off.', 404, 'chat_disabled');
}

/** A conversation by its public token. Only chats active in the last 7 days can be continued. */
function chat_conversation(mixed $token): ?array
{
    if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    return db_one('SELECT * FROM chat_conversations WHERE token = ? AND last_message_at > (NOW() - INTERVAL 7 DAY)', [$token]);
}

function chat_add_message(int $conversationId, string $role, string $content, ?string $engine = null, ?array $tokens = null): void
{
    db_query('INSERT INTO chat_messages (conversation_id, role, content, engine, input_tokens, output_tokens) VALUES (?, ?, ?, ?, ?, ?)',
        [$conversationId, $role, $content, $engine, $tokens['in'] ?? null, $tokens['out'] ?? null]);
    db_query('UPDATE chat_conversations SET message_count = message_count + 1, last_message_at = NOW() WHERE id = ?', [$conversationId]);
}

/** Recent messages for the AI: oldest first and starting with a visitor message. */
function chat_ai_history(int $conversationId): array
{
    $rows = array_reverse(db_all('SELECT role, content FROM chat_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ' . CHATBOT_HISTORY_LIMIT, [$conversationId]));
    while ($rows && $rows[0]['role'] !== 'user') {
        array_shift($rows);
    }
    return array_map(fn($r) => ['role' => $r['role'], 'content' => $r['content']], $rows);
}

$action = $_GET['action'] ?? '';

if ($action === 'history') {
    require_method('GET');
    $conv = chat_conversation($_GET['conversation_id'] ?? null);
    if (!$conv) {
        api_ok(['messages' => [], 'lead_submitted' => false]);
    }
    $rows = array_reverse(db_all('SELECT role, content, created_at FROM chat_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 60', [$conv['id']]));
    api_ok([
        'messages' => array_map(fn($r) => ['role' => $r['role'], 'content' => $r['content'], 'created_at' => iso_datetime($r['created_at'])], $rows),
        'lead_submitted' => (bool) $conv['enquiry_id'],
    ]);
}

require_method('POST');
require_csrf();
$input = request_body();

if ($action === 'message') {
    $data = validate_or_fail($input, ['message' => 'required|string|max:' . CHATBOT_MESSAGE_MAX, 'page' => 'string|max:255']);
    $ip = client_ip();
    if (!rate_limit('chat_message', $ip, 20, 600) || !rate_limit('chat_message_day', $ip, 150, 86400)) {
        api_error('You are sending messages very quickly. Please wait a few minutes, or call us.', 429, 'rate_limited');
    }
    $message = trim((string) preg_replace('/\s+/u', ' ', $data['message']));

    $conv = chat_conversation($input['conversation_id'] ?? null);
    if (!$conv) {
        $token = bin2hex(random_bytes(16));
        $page = preg_match('#^/[\w\-/]*$#', (string) ($data['page'] ?? '')) ? $data['page'] : null;
        db_query('INSERT INTO chat_conversations (token, user_id, page_path, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)',
            [$token, current_user()['id'] ?? null, $page, $ip, user_agent()]);
        $conv = db_one('SELECT * FROM chat_conversations WHERE token = ?', [$token]);
    } elseif ((int) $conv['message_count'] >= 120) {
        api_error('This chat has become very long. Please start a new chat, or talk to a counsellor.', 429, 'chat_too_long');
    }
    chat_add_message((int) $conv['id'], 'user', $message);

    $knowledge = chatbot_data();
    $reply = null;
    $engine = 'basic';
    if (chatbot_ai_available()) {
        @set_time_limit(90);
        $reply = chatbot_ai_reply(chat_ai_history((int) $conv['id']), $knowledge);
        $engine = $reply ? 'ai' : 'basic';
    }
    $reply ??= chatbot_basic_reply($message, $knowledge);
    chat_add_message((int) $conv['id'], 'assistant', $reply['reply'], $engine, $reply['tokens'] ?? null);

    // Always offer a way to reach a person
    $hindi = chatbot_is_hindi($message);
    $leadPrompt = $reply['lead_prompt'] && !$conv['enquiry_id'];
    $suggestions = $reply['suggestions'];
    $actions = array_column($suggestions, 'action');
    if (!$leadPrompt && !$conv['enquiry_id'] && !in_array('lead', $actions, true)) {
        $suggestions = array_merge($suggestions, chatbot_suggestions(['lead'], $hindi));
    }
    if (!in_array('whatsapp', $actions, true)) {
        $suggestions = array_merge($suggestions, chatbot_suggestions(['whatsapp'], $hindi));
    }

    api_ok([
        'conversation_id' => $conv['token'],
        'reply' => $reply['reply'],
        'suggestions' => $suggestions,
        'lead_prompt' => $leadPrompt,
        'lead_course' => $reply['course_slug'] ?: null,
        'engine' => $engine,
        'created_at' => date(DATE_ATOM),
    ]);
}

if ($action === 'lead') {
    // Honeypot: real visitors never see or fill the "website" field.
    if (!empty($input['website'])) {
        api_ok(['received' => true], 201);
    }
    require_recaptcha();
    if (!rate_limit('enquiry', client_ip(), 5, 3600)) {
        api_error('Too many requests from your network. Please try again later or call us.', 429, 'rate_limited');
    }
    $data = validate_or_fail($input, [
        'name' => 'required|string|min:2|max:120',
        'phone' => 'required|phone',
        'email' => 'email',
        'course_slug' => 'slug|max:200',
    ]);
    if (empty($input['consent'])) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', ['consent' => 'Please allow us to contact you.']);
    }

    $conv = chat_conversation($input['conversation_id'] ?? null);
    $course = !empty($data['course_slug']) ? db_one("SELECT id, title FROM courses WHERE slug = ? AND status = 'published'", [$data['course_slug']]) : null;
    $questions = $conv ? array_reverse(array_column(db_all("SELECT content FROM chat_messages WHERE conversation_id = ? AND role = 'user' ORDER BY id DESC LIMIT 6", [$conv['id']]), 'content')) : [];
    $message = 'Callback requested from the website chat assistant.'
        . ($questions ? "\n\nQuestions asked in the chat:\n- " . implode("\n- ", array_map(fn($q) => mb_strimwidth($q, 0, 300, '…'), $questions)) : '');

    db_query(
        'INSERT INTO enquiries (name, email, phone, subject, message, course_id, source, consent_at, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)',
        [$data['name'], $data['email'], $data['phone'], 'Chat callback' . ($course ? ' - ' . $course['title'] : ''), $message, $course['id'] ?? null, 'chatbot', client_ip(), user_agent()]
    );
    $enquiryId = (int) db()->lastInsertId();
    record_attribution('enquiry', $enquiryId, $input['attribution'] ?? null);

    $first = explode(' ', trim($data['name']))[0];
    $hours = setting('office_hours', '');
    $thanks = $questions && chatbot_is_hindi((string) end($questions))
        ? "धन्यवाद, {$first}! हमारे काउंसलर जल्द ही आपको {$data['phone']} पर कॉल करेंगे" . ($hours ? " ({$hours})" : '') . '।'
        : "Thank you, {$first}! Our counsellor will call you on {$data['phone']} shortly" . ($hours ? " ({$hours})" : '') . '.';
    if ($conv) {
        db_query('UPDATE chat_conversations SET enquiry_id = ? WHERE id = ?', [$enquiryId, $conv['id']]);
        chat_add_message((int) $conv['id'], 'assistant', $thanks, 'system');
    }

    require_once APP_ROOT . '/includes/notify.php';
    // The consent box above is required and its text names WhatsApp, so the confirmation may also go there
    // (this one message only). Leads without an email get the WhatsApp confirmation alone, as in api/enquiries.php.
    $lead = ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'], 'whatsapp_consent' => true];
    $leadVars = ['course' => $course['title'] ?? '', 'related' => ['enquiry', $enquiryId]];
    if (setting('enquiry_autoreply', '1') === '1' && !empty($data['email'])) {
        send_template('enquiry_autoreply', $lead, $leadVars);
    } elseif (setting('whatsapp_enabled', '0') === '1' && is_file(APP_ROOT . '/includes/whatsapp.php')) {
        require_once APP_ROOT . '/includes/whatsapp.php';
        try {
            whatsapp_notify_lead('enquiry_autoreply', $lead, $leadVars);
        } catch (Throwable $e) {
            error_log('[laxyam] chat lead whatsapp confirmation failed: ' . $e->getMessage());
        }
    }
    notify_staff_email(setting('enquiry_notify_email'), 'New chat callback request from ' . $data['name'],
        trim($data['phone'] . ' ' . ($data['email'] ?? '')) . "\nSource: chatbot" . ($course ? "\nCourse: {$course['title']}" : '') . "\n\n" . $message, 'enquiries.php?action=edit&id=' . $enquiryId);

    api_ok(['received' => true, 'reply' => $thanks], 201);
}

api_error('Unknown action.', 404, 'not_found');
