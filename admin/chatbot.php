<?php
// Website chat assistant: conversations from the chat bubble, callback leads, AI connection and settings.
// The API key (OpenAI or Claude) is set in admin → Keys & Credentials (keys.php) or .env and is never shown in full.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/chatbot.php';

$me = require_staff_page();
require_permission('enquiries.view');

// key => [label, type, public]
const CHATBOT_FIELDS = [
    'chatbot_enabled' => ['Show the chat assistant on the website', 'bool', 1],
    'chatbot_name' => ['Assistant name', 'text', 1],
    'chatbot_greeting' => ['Welcome message (under "How can I help you?")', 'textarea', 1],
    'chatbot_teaser' => ['Teaser bubble next to the chat button a few seconds after a visitor arrives (leave empty to turn off)', 'text', 1],
    'chatbot_ai_enabled' => ['Answer with AI (OpenAI or Claude) when an API key is configured - otherwise built-in answers from your courses, tests and FAQs are used', 'bool', 0],
    'chatbot_ai_daily_limit' => ['Maximum AI answers per day (after that, built-in answers are used until midnight)', 'int', 0],
    'chatbot_knowledge' => ['Extra information for the assistant: batch timings, offers, scholarships, hostel, results, anything visitors often ask. Plain text; the AI only states what is written here and on the website.', 'knowledge', 0],
];

// Approximate list prices (USD per million input / output tokens) for the cost estimate
const CHATBOT_PRICES = [
    'claude-opus-5-5' => [4, 20], 'claude-sonnet-5-5' => [2, 10], 'claude-haiku-5-5' => [0.1, 0.5],
    'gpt-6-luna' => [0.1, 0.5], 'gpt-5.6-terra' => [2, 12], 'gpt-5-mini' => [0.25, 2], 'gpt-4.1-mini' => [0.4, 1.6], 'gpt-4o-mini' => [0.15, 0.6],
];

$errors = [];
if (admin_post()) {
    $act = $_POST['_action'] ?? '';
    if ($act === 'settings') {
        require_permission('settings.manage');
        $values = [];
        foreach (CHATBOT_FIELDS as $k => [$label, $type]) {
            $v = trim((string) ($_POST[$k] ?? ''));
            $err = match ($type) {
                'int' => !ctype_digit($v) || (int) $v > 100000 ? 'Enter a whole number up to 100000.' : null,
                'knowledge' => mb_strlen($v) > 20000 ? 'Please keep this under 20,000 characters.' : null,
                'text' => $k === 'chatbot_name' && $v === '' ? 'Enter a name.' : (mb_strlen($v) > 120 ? 'Too long (120 characters max).' : null),
                default => mb_strlen($v) > 500 ? 'Too long (500 characters max).' : null,
            };
            if ($err) {
                $errors[$k] = $err;
            }
            $values[$k] = $type === 'bool' ? (empty($_POST[$k]) ? '0' : '1') : $v;
        }
        if (!$errors) {
            foreach ($values as $k => $v) {
                db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public, updated_by) VALUES (?, ?, 'chatbot', ?, ?)
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)", [$k, $v, CHATBOT_FIELDS[$k][2], $me['id']]);
            }
            audit_log('chatbot_settings_updated', 'site_settings', null, ['keys' => array_keys($values)]);
            flash('success', 'Chatbot settings saved. The website uses them right away.');
            redirect_to('chatbot.php?tab=settings');
        }
        flash('error', 'Please correct the highlighted fields.');
    }
    if ($act === 'test_ai') {
        require_permission('settings.manage');
        [$ok, $msg] = chatbot_ai_test();
        flash($ok ? 'success' : 'error', $msg);
        redirect_to('chatbot.php?tab=settings#ai');
    }
    if ($act === 'delete') {
        require_permission('enquiries.manage');
        db_query('DELETE FROM chat_conversations WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        audit_log('chat_deleted', 'chat_conversation', (int) ($_POST['id'] ?? 0), [], 'warning');
        flash('success', 'Conversation deleted.');
        redirect_to('chatbot.php');
    }
}

/** Readable name for the page a chat started on ("/" → "Home page", "/courses/ssc-cgl" → "Courses › ssc-cgl"). */
function chatbot_page_label(string $path): string
{
    $path = (string) parse_url($path, PHP_URL_PATH);
    $parts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
    if (!$parts) {
        return 'Home page';
    }
    $first = ucwords(str_replace('-', ' ', $parts[0]));
    return count($parts) > 1 ? $first . ' › ' . implode(' / ', array_slice($parts, 1)) : $first;
}

$tabs = ['conversations' => 'Conversations', 'settings' => 'Settings & AI'];
$tab = current_tab($tabs);
if ($tab === 'settings') {
    require_permission('settings.manage');
}
$aiKey = chatbot_ai_key();
$aiKeyName = chatbot_ai_key_name();
$aiOpenAi = chatbot_ai_provider() === 'openai';
$aiConfigured = chatbot_ai_configured();

// ------------------------------------------------------------------
// One conversation (transcript)
// ------------------------------------------------------------------
if ($tab === 'conversations' && ($viewId = (int) ($_GET['view'] ?? 0))) {
    $c = db_one('SELECT cc.*, u.name AS user_name, u.email AS user_email, e.name AS lead_name, e.phone AS lead_phone, e.status AS lead_status
                 FROM chat_conversations cc LEFT JOIN users u ON u.id = cc.user_id LEFT JOIN enquiries e ON e.id = cc.enquiry_id WHERE cc.id = ?', [$viewId]);
    if (!$c) {
        flash('error', 'Conversation not found.');
        redirect_to('chatbot.php');
    }
    $msgs = db_all('SELECT role, content, engine, created_at FROM chat_messages WHERE conversation_id = ? ORDER BY id', [$viewId]);
    // Chat replies use light markdown: show **bold**, ~~old price~~ and [link](/path) as formatted text
    $fmt = fn(string $t) => preg_replace(['/\*\*(.+?)\*\*/u', '/~~(.+?)~~/u', '/\[([^\]]+)\]\(([^)\s]+)\)/u'], ['<strong>$1</strong>', '<s>$1</s>', '$1'], e($t));
    admin_start('Chat #' . $viewId, null, 'enquiries', ['head' => true, 'crumbs' => '<a href="chatbot.php">Chatbot</a> / Chat #' . $viewId, 'subtitle' => 'Started ' . fmt_dt($c['created_at']) . ($c['page_path'] ? ' on ' . chatbot_page_label($c['page_path']) : '')]);
    $n = count($msgs);
    ?>
<div class="split-2-1">
  <div class="card">
    <div class="card-head"><h2>Transcript</h2><span class="muted" style="font-size:12px"><?= $n ?> message<?= $n === 1 ? '' : 's' ?></span></div>
    <div class="card-pad d2-chat">
      <?php foreach ($msgs as $m): $mine = $m['role'] === 'user'; ?>
      <div class="d2-msg<?= $mine ? ' me' : '' ?>">
        <div class="bubble"><?= $fmt($m['content']) ?></div>
        <small><?= $mine ? 'Visitor · ' : '' ?><?= e(fmt_dt($m['created_at'], 'j M, g:i A')) ?><?= !$mine && $m['engine'] ? ' · ' . e(['ai' => 'AI answer', 'basic' => 'Built-in answer', 'system' => 'Confirmation'][$m['engine']] ?? ucfirst((string) $m['engine'])) : '' ?></small>
      </div>
      <?php endforeach; ?>
      <?php if (!$msgs): ?><div class="empty"><span class="empty-ic"><?= icon('chat') ?></span><strong>No messages</strong><p>This conversation was opened but nothing was asked.</p></div><?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Visitor</h2></div>
    <div class="card-pad">
      <dl class="kv" style="grid-template-columns:110px 1fr">
        <dt>Callback</dt><dd><?= $c['enquiry_id'] ? '<a href="enquiries.php?action=edit&id=' . (int) $c['enquiry_id'] . '">' . e($c['lead_name']) . '</a> · ' . e((string) $c['lead_phone']) . ' ' . badge($c['lead_status']) : '<span class="muted">Not requested</span>' ?></dd>
        <dt>Account</dt><dd><?= $c['user_id'] ? e($c['user_name']) . '<br><span class="muted">' . e($c['user_email']) . '</span>' : '<span class="muted">Not signed in</span>' ?></dd>
        <dt>Started on</dt><dd><?= $c['page_path'] ? e(chatbot_page_label($c['page_path'])) . ($c['page_path'] !== '/' ? '<span class="d2-sub"><code>' . e($c['page_path']) . '</code></span>' : '') : '—' ?></dd>
        <dt>Last message</dt><dd><?= e(fmt_dt($c['last_message_at'])) ?></dd>
        <dt>Device</dt><dd class="muted" style="font-size:12px;font-weight:400"><?= e(mb_strimwidth((string) $c['user_agent'], 0, 120, '…')) ?><br><?= e((string) $c['ip_address']) ?></dd>
      </dl>
      <?php if (can('enquiries.manage')): ?>
      <div style="margin-top:16px"><?= action_form('delete', ['id' => $viewId], icon('trash') . ' Delete conversation', 'btn btn-danger btn-sm', 'Delete this conversation permanently?') ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>
    <?php
    admin_end();
    exit;
}

admin_start('Chatbot', null, 'enquiries', ['head' => true, 'subtitle' => 'The chat assistant on your website: what visitors ask, callback requests and how it answers.']);
// Only staff who may open the settings get the strip (it is hidden anyway when the module tab bar lists both).
echo can('settings.manage') ? admin_tabs($tabs, $tab) : '';

// ------------------------------------------------------------------
// Conversations
// ------------------------------------------------------------------
if ($tab === 'conversations') {
    $stats = db_one("SELECT
        (SELECT COUNT(*) FROM chat_conversations WHERE created_at >= CURDATE()) AS today,
        (SELECT COUNT(*) FROM chat_conversations WHERE created_at >= (CURDATE() - INTERVAL 29 DAY)) AS month,
        (SELECT COUNT(*) FROM chat_conversations WHERE enquiry_id IS NOT NULL AND created_at >= (CURDATE() - INTERVAL 29 DAY)) AS leads,
        (SELECT COUNT(*) FROM chat_messages WHERE role = 'user' AND created_at >= (CURDATE() - INTERVAL 29 DAY)) AS questions");
    $aiToday = chatbot_ai_used_today();
    $cards = [
        ['Chats today', 'headset', 'tone-blue', (int) $stats['today'], 'Conversations started', 'chatbot.php'],
        ['Chats (30 days)', 'chart', 'tone-purple', (int) $stats['month'], number_format((int) $stats['questions']) . ' questions asked', 'chatbot.php'],
        ['Callback leads (30 days)', 'user', 'tone-green', (int) $stats['leads'], 'Saved in Enquiries & Leads', 'enquiries.php?source=chatbot'],
        ['AI answers today', 'star', 'tone-amber', $aiToday, $aiConfigured ? 'Daily limit ' . number_format(chatbot_ai_daily_limit()) : 'AI not configured - built-in answers', 'chatbot.php?tab=settings#ai'],
    ];
    ?>
<section class="grid g-4 d2-stats" aria-label="Chat figures">
  <?php foreach ($cards as [$label, $ico, $tone, $value, $sub, $href]): ?>
  <a class="card stat" href="<?= e($href) ?>">
    <span class="ico <?= $tone ?>"><?= icon($ico) ?></span>
    <span><span class="label"><?= e($label) ?></span><span class="value"><?= number_format($value) ?></span><span class="sub"><?= e($sub) ?></span></span>
  </a>
  <?php endforeach; ?>
</section>
    <?php
    $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
    $onlyLeads = ($_GET['leads'] ?? '') === '1';
    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM chat_messages s WHERE s.conversation_id = cc.id AND s.content LIKE ?)';
        $params[] = '%' . addcslashes($q, '%_\\') . '%';
    }
    if ($onlyLeads) {
        $where[] = 'cc.enquiry_id IS NOT NULL';
    }
    $w = implode(' AND ', $where);
    $per = 25;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $total = (int) db_value("SELECT COUNT(*) FROM chat_conversations cc WHERE {$w}", $params);
    $rows = db_all("SELECT cc.*, e.name AS lead_name, e.phone AS lead_phone, u.name AS user_name,
            (SELECT content FROM chat_messages f WHERE f.conversation_id = cc.id AND f.role = 'user' ORDER BY f.id LIMIT 1) AS first_question,
            (SELECT COUNT(*) FROM chat_messages a WHERE a.conversation_id = cc.id AND a.engine = 'ai') AS ai_count
        FROM chat_conversations cc LEFT JOIN enquiries e ON e.id = cc.enquiry_id LEFT JOIN users u ON u.id = cc.user_id
        WHERE {$w} ORDER BY cc.last_message_at DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);
    ?>
<div class="card mt">
  <form class="toolbar" method="get" role="search" aria-label="Filter conversations">
    <label class="search-in"><span class="sr-only">Search messages</span><?= icon('search') ?><input class="in" type="search" name="q" value="<?= e($q) ?>" placeholder="Search what visitors asked…"></label>
    <label class="check" style="font-size:13px"><input type="checkbox" name="leads" value="1"<?= $onlyLeads ? ' checked' : '' ?>> Only with a callback request</label>
    <button class="btn btn-light"><?= icon('search') ?> Filter</button>
    <?php if ($q !== '' || $onlyLeads): ?><a class="btn btn-ghost" href="chatbot.php">Reset</a><?php endif; ?>
  </form>
  <?php if ($rows): ?>
  <div class="table-wrap"><table class="t stack">
    <thead><tr><th>Visitor</th><th>First question</th><th>Messages</th><th>Started on</th><th>Last activity</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $mc = (int) $r['message_count']; ?>
      <tr>
        <td data-label="Visitor"><div><?php if ($r['enquiry_id']): ?><strong><?= e($r['lead_name']) ?></strong> <span class="badge b-green">Callback</span><span class="d2-sub"><?= e((string) $r['lead_phone']) ?></span>
            <?php elseif ($r['user_name']): ?><strong><?= e($r['user_name']) ?></strong><span class="d2-sub">Student account</span>
            <?php else: ?><span class="muted">Anonymous visitor</span><?php endif; ?></div></td>
        <td data-label="First question" class="d2-w200"><span class="d2-clip" title="<?= e((string) $r['first_question']) ?>"><?= $r['first_question'] !== null ? e((string) $r['first_question']) : '<span class="muted">No question asked</span>' ?></span></td>
        <td data-label="Messages"><div><?= $mc ?><?= $r['ai_count'] ? '<span class="d2-sub">' . (int) $r['ai_count'] . ' by AI</span>' : '' ?></div></td>
        <td data-label="Started on"><?= $r['page_path'] ? '<span title="' . e($r['page_path']) . '">' . e(chatbot_page_label($r['page_path'])) . '</span>' : '<span class="muted">—</span>' ?></td>
        <td data-label="Last activity" class="d2-nowrap"><div><?= e(fmt_dt($r['last_message_at'], 'j M Y')) ?><span class="d2-sub"><?= e(fmt_dt($r['last_message_at'], 'g:i A')) ?></span></div></td>
        <td class="actions"><a class="btn btn-light btn-sm" href="chatbot.php?view=<?= (int) $r['id'] ?>"><?= icon('eye') ?> View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php render_pager($total, $page, $per); ?>
  <?php else: ?>
  <div class="empty"><span class="empty-ic"><?= icon($q !== '' || $onlyLeads ? 'search' : 'chat') ?></span>
    <strong><?= $q !== '' || $onlyLeads ? 'No conversations match' : 'No conversations yet' ?></strong>
    <p><?= $q !== '' || $onlyLeads ? 'Try another search or show every conversation.' : 'They appear here as soon as visitors use the chat bubble on the website.' ?></p>
    <?php if ($q !== '' || $onlyLeads): ?><a class="btn btn-light btn-sm" href="chatbot.php">Clear filters</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
    <?php
    admin_end();
    exit;
}

// ------------------------------------------------------------------
// Settings & AI
// ------------------------------------------------------------------
$s = settings_all();
$status = json_decode_array($s['chatbot_ai_status'] ?? null);
$model = chatbot_model();
$usage = db_one("SELECT COUNT(*) AS answers, COALESCE(SUM(input_tokens), 0) AS tin, COALESCE(SUM(output_tokens), 0) AS tout
                 FROM chat_messages WHERE engine = 'ai' AND created_at >= (CURDATE() - INTERVAL 29 DAY)");
$price = CHATBOT_PRICES[$model] ?? null;
$cost = $price ? ((int) $usage['tin'] * $price[0] + (int) $usage['tout'] * $price[1]) / 1_000_000 : null;
?>
<div class="card" id="ai" style="scroll-margin-top:86px">
  <div class="card-head"><h2>AI answers (<?= $aiOpenAi ? 'OpenAI' : 'Claude' ?>)</h2><?= $aiConfigured ? '<span class="status-dot" style="color:var(--green)">Connected</span>' : '<span class="status-dot" style="color:var(--muted)">' . ($aiKey === '' ? 'No API key' : 'Switched off') . '</span>' ?></div>
  <div class="card-pad">
    <dl class="kv">
      <dt>Provider</dt><dd><?= $aiOpenAi ? 'OpenAI' : 'Anthropic (Claude)' ?></dd>
      <dt>API key</dt><dd><?= $aiKey !== '' ? '<code>' . e(app_config_mask($aiKey, $aiKeyName)) . '</code> <span class="muted" style="font-weight:400">(' . (app_config_source($aiKeyName) === 'admin' ? 'saved in Keys &amp; Credentials' : '.env') . ')</span>' : '<a class="badge b-red" href="keys.php#ai">Not configured - add it</a>' ?></dd>
      <dt>Model</dt><dd><code><?= e($model) ?></code> <span class="muted" style="font-weight:400">(change in <a href="keys.php#ai">Keys &amp; Credentials</a>)</span></dd>
      <dt>Answers today</dt><dd><?= number_format(chatbot_ai_used_today()) ?> of <?= number_format(chatbot_ai_daily_limit()) ?> allowed</dd>
      <dt>Last 30 days</dt><dd><?= number_format((int) $usage['answers']) ?> AI answers · <?= number_format((int) $usage['tin']) ?> input / <?= number_format((int) $usage['tout']) ?> output tokens<?= $cost !== null ? ' · about $' . number_format($cost, 2) . ' or less' : '' ?></dd>
      <dt>Last check</dt><dd><?= $status ? e(fmt_dt($status['at'], 'j M, g:i A')) . ' - ' . ($status['ok'] ? '<span style="color:var(--green)">OK</span>' : '<span style="color:var(--red)">Problem</span>') . ': ' . e($status['msg']) : '<span class="muted">Not tested yet</span>' ?></dd>
    </dl>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px"><?= action_form('test_ai', [], icon('check') . ' Test connection', 'btn btn-light btn-sm') ?></div>
    <div class="alert alert-info" style="margin:16px 0 0">
      <strong>Without an API key</strong> the assistant still works: it answers from your published courses, fees, mock tests, live classes, FAQs and contact details, and collects callback requests.
      <strong>With a key</strong> it understands free-form questions in English, Hindi and Hinglish. To turn it on, create a key at platform.openai.com → API keys (add billing credit there) and paste it in
      <a href="keys.php#ai"><strong>Keys &amp; Credentials → AI chatbot</strong></a>, then press Test connection here. A Claude key (console.anthropic.com) works too.
      Visitors' questions are sent to <?= $aiOpenAi ? 'OpenAI' : 'Anthropic' ?> to generate answers - mention this in your Privacy Policy.
    </div>
  </div>
</div>

<form method="post" class="card mt" novalidate data-dirty-check>
  <?= csrf_field() ?><input type="hidden" name="_action" value="settings">
  <div class="card-head"><h2>Chat assistant settings</h2></div>
  <div class="card-pad form">
    <?php foreach (CHATBOT_FIELDS as $k => [$label, $type]):
        $v = $_POST[$k] ?? ($s[$k] ?? '');
        $err = $errors[$k] ?? null; ?>
    <div class="field<?= in_array($type, ['bool', 'knowledge', 'textarea'], true) ? ' full' : '' ?><?= $err ? ' invalid' : '' ?>">
      <?php if ($type === 'bool'): ?>
        <label class="check"><input type="checkbox" name="<?= $k ?>" value="1"<?= (string) $v === '1' ? ' checked' : '' ?>> <?= e($label) ?></label>
      <?php elseif ($type === 'textarea' || $type === 'knowledge'): ?>
        <label for="c-<?= $k ?>"><?= e($label) ?></label>
        <textarea id="c-<?= $k ?>" name="<?= $k ?>" rows="<?= $type === 'knowledge' ? 8 : 2 ?>"<?= $type === 'knowledge' ? ' placeholder="Example:&#10;New SSC CGL batch starts 15 November, morning 8-10 AM and evening 6-8 PM.&#10;10% scholarship for girls and ex-servicemen wards.&#10;No hostel; PG options nearby."' : '' ?>><?= e((string) $v) ?></textarea>
      <?php else: ?>
        <label for="c-<?= $k ?>"><?= e($label) ?></label>
        <input id="c-<?= $k ?>" type="<?= $type === 'int' ? 'number' : 'text' ?>" name="<?= $k ?>" value="<?= e((string) $v) ?>"<?= $type === 'int' ? ' min="0" max="100000"' : '' ?>>
      <?php endif; ?>
      <?= $err ? '<div class="err">' . e($err) . '</div>' : '' ?>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><button class="btn btn-primary"><?= icon('check') ?> Save settings</button></div>
</form>
<?php admin_end();
