<?php
/**
 * Website chatbot (admission assistant).
 *
 * Answers come from the academy's own data: published courses and fees, mock tests,
 * upcoming live classes, FAQs, contact details and the extra notes in admin → Chatbot.
 *   - With OPENAI_API_KEY (OpenAI) or ANTHROPIC_API_KEY (Claude) in .env and AI switched on, the AI writes the reply
 *     from that data. CHATBOT_AI_PROVIDER=openai|anthropic picks one when both keys are set (default: OpenAI).
 *   - Otherwise, or when the daily AI limit is reached or the API fails, built-in answers are used.
 * Callback requests from the chat become enquiries with source "chatbot".
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once APP_ROOT . '/includes/catalog.php';

const CHATBOT_MESSAGE_MAX = 500;    // characters per visitor message
const CHATBOT_HISTORY_LIMIT = 12;   // earlier messages sent to the AI with each question
const CHATBOT_API_VERSION = '2026-10-21';

function chatbot_enabled(): bool
{
    return setting('chatbot_enabled', '1') === '1';
}

/** 'openai' or 'anthropic': CHATBOT_AI_PROVIDER when set, otherwise whichever key is in .env (OpenAI first). */
function chatbot_ai_provider(): string
{
    $p = strtolower(trim((string) config('CHATBOT_AI_PROVIDER', '')));
    if ($p === 'openai' || $p === 'anthropic') {
        return $p;
    }
    return (string) config('OPENAI_API_KEY', '') !== '' || (string) config('ANTHROPIC_API_KEY', '') === '' ? 'openai' : 'anthropic';
}

/** Name of the .env key the current provider uses. */
function chatbot_ai_key_name(): string
{
    return chatbot_ai_provider() === 'openai' ? 'OPENAI_API_KEY' : 'ANTHROPIC_API_KEY';
}

function chatbot_ai_key(): string
{
    return trim((string) config(chatbot_ai_key_name(), ''));
}

/** An API key is set in .env and AI answers are switched on in admin → Chatbot. */
function chatbot_ai_configured(): bool
{
    return chatbot_ai_key() !== '' && setting('chatbot_ai_enabled', '1') === '1';
}

function chatbot_model(): string
{
    if (chatbot_ai_provider() === 'openai') {
        $model = trim((string) config('OPENAI_MODEL', ''));
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.:_-]{1,100}$/', $model) ? $model : 'gpt-6-luna';
    }
    $model = (string) config('ANTHROPIC_MODEL', '');
    return preg_match('/^claude-[a-z0-9-]{3,60}$/', $model) ? $model : 'claude-opus-5-5';
}

function chatbot_ai_daily_limit(): int
{
    return setting_int('chatbot_ai_daily_limit', 300, 0, 100000);
}

function chatbot_ai_used_today(): int
{
    return (int) db_value("SELECT COUNT(*) FROM chat_messages WHERE engine = 'ai' AND created_at >= CURDATE()");
}

function chatbot_ai_available(): bool
{
    return chatbot_ai_configured() && chatbot_ai_used_today() < chatbot_ai_daily_limit();
}

// ------------------------------------------------------------------
// Knowledge: what the assistant may say about the academy
// ------------------------------------------------------------------

/** Courses, tests, classes, FAQs and contact details (cached; staff changes clear the cache). */
function chatbot_data(): array
{
    return cache_remember('chatbot_data', 600, function (): array {
        $courses = array_map(fn($c) => [
            'title' => $c['title'], 'slug' => $c['slug'], 'subtitle' => $c['subtitle'],
            'summary' => mb_strimwidth(trim((string) $c['short_description']), 0, 220, '…'),
            'category' => $c['category']['name'], 'category_slug' => $c['category']['slug'],
            'price' => $c['price'], 'original_price' => $c['original_price'], 'is_free' => $c['is_free'],
            'type' => $c['course_type'], 'language' => $c['language'], 'duration' => $c['duration'], 'badge' => $c['badge'],
            'admission' => $c['admission'] ?? 'online', // online | both (Enroll Now + Apply Now) | enquiry (Apply Now form only)
        ], fetch_course_cards('1=1', [], 'cat.sort_order, c.is_featured DESC, c.sort_order, c.id', 100));

        $tests = db_all("SELECT e.title, e.slug, e.duration_minutes, e.total_marks, e.access_type, e.negative_marking, cat.name AS category
            FROM exams e LEFT JOIN course_categories cat ON cat.id = e.category_id
            WHERE e.status = 'published' AND (e.ends_at IS NULL OR e.ends_at > NOW())
            ORDER BY e.id DESC LIMIT 40");

        $classes = array_map(fn($l) => [
            'title' => $l['title'], 'starts_at' => $l['starts_at'], 'minutes' => $l['duration_minutes'],
            'access' => $l['access_type'], 'course' => $l['course']['title'] ?? null, 'instructor' => $l['instructor'],
        ], fetch_upcoming_live_classes(8));

        $faqs = db_all('SELECT question, answer FROM faqs WHERE is_active = 1 ORDER BY sort_order, id LIMIT 60');

        return [
            'site' => [
                'name' => setting('site_name', 'Laxyam Academy'),
                'tagline' => setting('tagline', ''),
                'phone' => setting('contact_phone', ''),
                'whatsapp' => preg_replace('/\D/', '', (string) setting('whatsapp_number', '')),
                'email' => setting('contact_email', ''),
                'address' => setting('address', ''),
                'hours' => setting('office_hours', ''),
                'url' => app_url(''),
            ],
            'courses' => $courses,
            'tests' => $tests,
            'classes' => $classes,
            'faqs' => $faqs,
            'notes' => trim((string) setting('chatbot_knowledge', '')),
        ];
    });
}

function chatbot_price(array $c): string
{
    if ($c['is_free']) {
        return 'Free';
    }
    return '₹' . number_format((float) $c['price']) . ($c['original_price'] ? ' (MRP ₹' . number_format((float) $c['original_price']) . ')' : '');
}

function chatbot_course_type(string $type): string
{
    return ['free' => 'free course', 'paid' => 'online course', 'recorded' => 'recorded video lectures', 'live' => 'live online classes', 'hybrid' => 'live classes + recorded lectures',
        'offline' => 'offline classroom classes at the centre'][$type] ?? $type;
}

/** The academy facts given to the AI, as plain text. */
function chatbot_knowledge_text(array $d): string
{
    $s = $d['site'];
    $out = ["Academy: {$s['name']}" . ($s['tagline'] ? " - {$s['tagline']}" : ''), "Website: {$s['url']}"];
    foreach (['phone' => 'Phone', 'whatsapp' => 'WhatsApp', 'email' => 'Email', 'address' => 'Address', 'hours' => 'Office hours'] as $k => $label) {
        if ($s[$k] !== '' && $s[$k] !== null) {
            $out[] = $label . ': ' . ($k === 'whatsapp' ? '+' . $s[$k] : $s[$k]);
        }
    }
    $out[] = '';
    $out[] = '## Courses (fee = what the student pays now; how to join is stated for each course)';
    foreach ($d['courses'] as $c) {
        $out[] = "- {$c['title']} [{$c['category']}] - fee " . chatbot_price($c) . ' - ' . chatbot_course_type($c['type'])
            . ' - join: ' . match ($c['admission'] ?? 'online') {
                'enquiry' => 'Apply Now form on the course page, a counsellor calls back (no online payment)',
                'both' => 'Enroll Now on the course page (free account, pay online), or the Apply Now form for a counsellor call',
                default => 'Enroll Now on the course page (free account, pay online)',
            }
            . " - language: {$c['language']}" . ($c['duration'] ? " - duration: {$c['duration']}" : '')
            . " - page: /courses/{$c['slug']}" . ($c['summary'] !== '' ? " - {$c['summary']}" : '');
    }
    if (!$d['courses']) {
        $out[] = '- (no courses published yet)';
    }
    $out[] = '';
    $out[] = '## Mock tests (online, timed; take them at /mock-tests)';
    foreach ($d['tests'] as $t) {
        $access = ['free' => 'free for everyone', 'registered' => 'free after registering', 'enrolled' => 'for enrolled students'][$t['access_type']] ?? $t['access_type'];
        $out[] = "- {$t['title']}" . ($t['category'] ? " [{$t['category']}]" : '') . " - {$t['duration_minutes']} min, " . (float) $t['total_marks'] . " marks"
            . ($t['negative_marking'] ? ', negative marking' : '') . " - {$access} - page: /mock-tests/{$t['slug']}";
    }
    if (!$d['tests']) {
        $out[] = '- (no mock tests published right now)';
    }
    $out[] = '';
    $out[] = '## Upcoming live classes (full schedule at /live-classes)';
    foreach ($d['classes'] as $l) {
        $out[] = '- ' . date('D j M Y, g:i A', strtotime($l['starts_at'])) . " - {$l['title']} ({$l['minutes']} min)"
            . ($l['course'] ? " - part of {$l['course']}" : '') . ($l['access'] === 'public' ? ' - open to all' : ($l['access'] === 'registered' ? ' - for registered students' : ' - for enrolled students'));
    }
    if (!$d['classes']) {
        $out[] = '- (none scheduled right now)';
    }
    $out[] = '';
    $out[] = '## Website pages';
    $out[] = '/courses all courses · /categories exam categories · /mock-tests · /live-classes · /resources free study material (current affairs, PYQs, notes)'
        . ' · /blog · /jobs latest government job notifications · /careers teach or work at the academy · /about · /contact · /faq'
        . ' · /register create a free account · /login · /dashboard student dashboard (my courses, tests, certificates, support)';
    if ($d['faqs']) {
        $out[] = '';
        $out[] = '## FAQs';
        foreach ($d['faqs'] as $f) {
            $out[] = 'Q: ' . trim($f['question']) . "\nA: " . trim(strip_tags($f['answer']));
        }
    }
    if ($d['notes'] !== '') {
        $out[] = '';
        $out[] = '## More information from the academy';
        $out[] = $d['notes'];
    }
    return implode("\n", $out);
}

function chatbot_system_prompt(array $d, string $facts): string
{
    $name = setting('chatbot_name', 'Laxyam Admission Assistant');
    $site = $d['site']['name'];
    return <<<PROMPT
You are "{$name}", the chat assistant on the website of {$site}, a coaching institute in New Delhi that prepares students for government competitive exams (UPSC, SSC, Banking, Railway, Defence, State PSC, Teaching, Police and more). You chat with students and parents visiting the website.

Your job is to answer questions about the academy's courses, fees, mock tests, live classes, study material, admission and payments, and to help interested visitors get a callback from a counsellor. You may also give brief general guidance about the government exams themselves (eligibility, pattern, how to prepare), noting that the official notification is final.

How to answer:
- For anything about the academy, use only the facts in <academy_facts>. If something is not there (batch start dates, class timings, discounts, scholarships, results, faculty, hostel and so on), say you are not sure and offer a callback from a counsellor. Never invent fees, dates, offers or selection guarantees.
- Reply in the visitor's language: English, Hindi (Devanagari) or Hinglish (Hindi in Latin letters), matching how they write.
- Keep it short and warm, like a helpful counsellor on chat: usually 2-5 sentences, or a short "- " list when comparing courses. Use **bold** for course names and prices, and write prices like ₹4,999.
- Link pages with markdown links using the relative paths from the facts, for example [SSC CGL 2025](/courses/ssc-cgl-2025).
- For requests unrelated to the academy or exam preparation (coding, solving homework, other topics), politely say you can only help with courses and exam preparation.
- Never ask for passwords, OTPs or card details. Visitors join from the course page as stated for each course: the Apply Now admission form (a counsellor calls back; no online payment) or online checkout where it is offered. A counsellor can always help.

Fill the output fields as follows:
- reply: your message to the visitor.
- suggestions: 2 or 3 short follow-up questions the visitor might tap next, each under 40 characters, in the visitor's language.
- offer_callback: true when the visitor shows interest in joining, asks about fees, admission or batches, wants to talk to a person, or you could not answer; otherwise false.
- course_slug: the slug of the course the visitor is most interested in, or "" if none.

<academy_facts>
{$facts}
</academy_facts>
PROMPT;
}

// ------------------------------------------------------------------
// Suggestions (quick replies under a bot message)
// ------------------------------------------------------------------

function chatbot_suggestions(array $keys, bool $hindi = false): array
{
    $all = [
        'courses' => [$hindi ? 'कोर्स और फीस' : 'Courses & fees', $hindi ? 'आपके कौन-कौन से कोर्स हैं और फीस कितनी है?' : 'Which courses do you offer and what are the fees?'],
        'mock' => [$hindi ? 'मॉक टेस्ट' : 'Mock tests', $hindi ? 'मॉक टेस्ट के बारे में बताइए' : 'Tell me about your mock tests'],
        'live' => [$hindi ? 'लाइव क्लास' : 'Live classes', $hindi ? 'लाइव क्लास कब होती हैं?' : 'When are the live classes?'],
        'admission' => [$hindi ? 'एडमिशन कैसे लें' : 'How to enrol', $hindi ? 'एडमिशन कैसे लें?' : 'How do I take admission?'],
        'contact' => [$hindi ? 'पता और समय' : 'Address & timings', $hindi ? 'आपका पता और समय क्या है?' : 'Where is your institute and what are the timings?'],
        'resources' => [$hindi ? 'फ्री स्टडी मटेरियल' : 'Free study material', $hindi ? 'क्या फ्री स्टडी मटेरियल है?' : 'Do you have free study material?'],
    ];
    $out = [];
    foreach ($keys as $k) {
        if ($k === 'lead') {
            $out[] = ['label' => $hindi ? 'काउंसलर से बात करें' : 'Talk to a counsellor', 'action' => 'lead'];
        } elseif ($k === 'whatsapp') {
            $out[] = ['label' => $hindi ? 'WhatsApp करें' : 'WhatsApp us', 'action' => 'whatsapp'];
        } elseif (isset($all[$k])) {
            $out[] = ['label' => $all[$k][0], 'message' => $all[$k][1]];
        }
    }
    return $out;
}

// ------------------------------------------------------------------
// Built-in answers (no AI): keyword intents + course and FAQ matching
// ------------------------------------------------------------------

function chatbot_norm(string $s): string
{
    $s = mb_strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}\p{M}\s]+/u', ' ', $s) ?? '';
    return ' ' . trim(preg_replace('/\s+/u', ' ', $s) ?? '') . ' ';
}

/**
 * True when any keyword starts a word in the normalised text (" ssc" matches "ssc", "sscs").
 * A keyword ending in a space must be a whole word ("hi " does not match "hindi"). Devanagari keywords match anywhere.
 */
function chatbot_has(string $norm, array $keywords): bool
{
    foreach ($keywords as $k) {
        if (preg_match('/\p{Devanagari}/u', $k) ? str_contains($norm, $k) : str_contains($norm, ' ' . $k)) {
            return true;
        }
    }
    return false;
}

function chatbot_is_hindi(string $text): bool
{
    if (preg_match('/\p{Devanagari}/u', $text)) {
        return true;
    }
    return chatbot_has(chatbot_norm($text), ['kya ', 'hai ', 'kaise', 'kitni', 'kitna', 'kab ', 'kaha', 'kahan', 'chahiye', 'batao', 'bataiye', 'mujhe', 'mera ', 'aap ', 'krna', 'karna', 'milega', 'hoga ']);
}

const CHATBOT_STOPWORDS = ['the', 'and', 'for', 'you', 'your', 'are', 'what', 'how', 'can', 'with', 'from', 'this', 'that', 'have', 'does', 'will', 'which',
    'exam', 'exams', 'preparation', 'course', 'courses', 'pack', 'monthly', 'online', 'classes', 'class', 'about', 'there', 'any', 'kya', 'hai', 'ka', 'ki', 'ke', 'me', 'mein'];

function chatbot_tokens(string $text): array
{
    $words = preg_split('/\s+/u', trim(chatbot_norm($text))) ?: [];
    return array_values(array_unique(array_filter($words, fn($w) => (mb_strlen($w) >= 3 && !in_array($w, CHATBOT_STOPWORDS, true)) || in_array($w, ['ssc', 'nda', 'cds', 'psc', 'gk'], true))));
}

/** Courses the message refers to by name (e.g. "ssc", "upsc", "bank"). */
function chatbot_match_courses(string $message, array $courses): array
{
    $msg = chatbot_tokens($message);
    $msg = array_merge($msg, array_map(fn($w) => rtrim($w, 's'), $msg));
    $scored = [];
    foreach ($courses as $c) {
        $score = 0;
        foreach (chatbot_tokens($c['title'] . ' ' . $c['category']) as $t) {
            if (in_array($t, $msg, true) || in_array(rtrim($t, 's'), $msg, true) || ($t === 'banking' && in_array('bank', $msg, true))) {
                $score += in_array($t, chatbot_tokens($c['title']), true) ? 2 : 1;
            }
        }
        if ($score > 0) {
            $scored[] = [$score, $c];
        }
    }
    if (!$scored) {
        return [];
    }
    $best = max(array_column($scored, 0));
    return array_values(array_map(fn($x) => $x[1], array_filter($scored, fn($x) => $x[0] === $best)));
}

/** Best FAQ for the message: [score 0..1, overlap, faq] or null. */
function chatbot_match_faq(string $message, array $faqs): ?array
{
    $msg = chatbot_tokens($message);
    $best = null;
    foreach ($faqs as $f) {
        $q = chatbot_tokens($f['question']);
        if (!$q) {
            continue;
        }
        $overlap = count(array_intersect($q, $msg));
        $score = $overlap / count($q);
        if ($overlap > 0 && (!$best || $score > $best[0])) {
            $best = [$score, $overlap, $f];
        }
    }
    return $best;
}

function chatbot_course_line(array $c): string
{
    return "- [{$c['title']}](/courses/{$c['slug']}) - " . ($c['is_free'] ? '**Free**' : '**₹' . number_format((float) $c['price']) . '**' . ($c['original_price'] ? ' ~~₹' . number_format((float) $c['original_price']) . '~~' : ''));
}

/** Reply without AI. Returns ['reply', 'suggestions', 'lead_prompt', 'course_slug']. */
function chatbot_basic_reply(string $message, array $d): array
{
    $hi = chatbot_is_hindi($message);
    $t = fn(string $en, string $hindi) => $hi ? $hindi : $en;
    $n = chatbot_norm($message);
    $s = $d['site'];
    $contact = $s['phone'] ? $t("call us on **{$s['phone']}**", "हमें **{$s['phone']}** पर कॉल करें") : $t('use the contact page', 'संपर्क पेज देखें');
    $out = fn(string $reply, array $sugg, bool $lead = false, string $slug = '') => ['reply' => $reply, 'suggestions' => chatbot_suggestions($sugg, $hi), 'lead_prompt' => $lead, 'course_slug' => $slug];

    // Wants a person / callback
    if (chatbot_has($n, ['counsel', 'callback', 'call back', 'call me', 'talk to', 'speak to', 'human', 'expert', 'baat kar', 'baat karni', 'contact me', 'काउंसलर', 'बात कर', 'कॉल करें', 'कॉल बैक'])) {
        return $out($t('Sure! Share your name and mobile number below and our counsellor will call you back' . ($s['hours'] ? " ({$s['hours']})" : '') . '.',
            'ज़रूर! नीचे अपना नाम और मोबाइल नंबर दें, हमारे काउंसलर आपको कॉल करेंगे' . ($s['hours'] ? " ({$s['hours']})" : '') . '।'), ['courses', 'whatsapp'], true);
    }
    $words = count(array_filter(explode(' ', trim($n))));
    if ($words <= 4 && chatbot_has($n, ['hi ', 'hii ', 'hello', 'hey ', 'namaste', 'namaskar', 'good morning', 'good evening', 'नमस्ते', 'नमस्कार'])) {
        return $out($t("Hello! 👋 Welcome to {$s['name']}. I can help you with courses, fees, mock tests, live classes and admission. What are you preparing for?",
            "नमस्ते! 👋 {$s['name']} में आपका स्वागत है। मैं कोर्स, फीस, मॉक टेस्ट, लाइव क्लास और एडमिशन में आपकी मदद कर सकता हूँ। आप किस परीक्षा की तैयारी कर रहे हैं?"), ['courses', 'mock', 'admission']);
    }
    if ($words <= 5 && chatbot_has($n, ['thank', 'thanks', 'thx', 'dhanyavad', 'shukriya', 'ok thanks', 'धन्यवाद', 'शुक्रिया'])) {
        return $out($t("You're welcome! All the best for your preparation. 😊 Ask me anything else anytime.", 'आपका स्वागत है! तैयारी के लिए शुभकामनाएँ। 😊 कुछ और पूछना हो तो बताइए।'), ['courses', 'lead']);
    }

    $faq = chatbot_match_faq($message, $d['faqs']);
    if ($faq && $faq[1] >= 2 && $faq[0] >= 0.6) {
        return $out(trim(strip_tags($faq[2]['answer'])), ['courses', 'admission', 'lead']);
    }

    $fees = chatbot_has($n, ['fee', 'fees', 'price', 'cost', 'charges', 'kitna', 'kitni', 'paisa', 'paise', 'rupee', 'discount', 'offer', 'फीस', 'शुल्क', 'कीमत', 'कितनी', 'कितना']);
    $mock = chatbot_has($n, ['mock', 'test series', 'practice test', 'quiz', 'test', 'टेस्ट', 'मॉक']);
    $live = chatbot_has($n, ['live', 'class timing', 'batch timing', 'schedule', 'doubt', 'लाइव']);
    $matched = chatbot_match_courses($message, $d['courses']);
    if ($matched && ($fees || !($mock || $live))) {
        if (count($matched) === 1) {
            $c = $matched[0];
            $reply = "**{$c['title']}**" . ($c['subtitle'] ? " - {$c['subtitle']}" : '') . "\n"
                . '- ' . $t('Fee', 'फीस') . ': **' . chatbot_price($c) . "**\n"
                . '- ' . $t('Mode', 'माध्यम') . ': ' . chatbot_course_type($c['type']) . "\n"
                . '- ' . $t('Language', 'भाषा') . ': ' . ucfirst($c['language']) . ($c['duration'] ? "\n- " . $t('Duration', 'अवधि') . ": {$c['duration']}" : '')
                . ($c['summary'] !== '' ? "\n\n{$c['summary']}" : '')
                . "\n\n" . $t("See full details and enrol here: [{$c['title']}](/courses/{$c['slug']})", "पूरी जानकारी और एडमिशन: [{$c['title']}](/courses/{$c['slug']})");
            return $out($reply, ['admission', 'mock', 'lead'], true, $c['slug']);
        }
        $reply = $t('Here are our matching courses:', 'ये कोर्स आपके लिए हैं:') . "\n" . implode("\n", array_map('chatbot_course_line', $matched))
            . "\n\n" . $t('Tap a course to see the syllabus, mode and how to enrol.', 'सिलेबस, माध्यम और एडमिशन की जानकारी के लिए कोर्स पर क्लिक करें।');
        return $out($reply, ['admission', 'lead'], true);
    }
    if ($fees || (!$mock && !$live && chatbot_has($n, ['course', 'courses', 'batch', 'program', 'syllabus', 'coaching', 'कोर्स', 'बैच', 'कोचिंग']))) {
        if (!$d['courses']) {
            return $out($t("Our new courses will be listed soon. Please {$contact} for the latest batches and fees.", "नए कोर्स जल्द ही जुड़ेंगे। ताज़ा बैच और फीस के लिए {$contact}।"), ['lead', 'whatsapp'], true);
        }
        $byCat = [];
        foreach (array_slice($d['courses'], 0, 14) as $c) {
            $byCat[$c['category']][] = chatbot_course_line($c);
        }
        $reply = $t('Here are our courses and current fees:', 'हमारे कोर्स और वर्तमान फीस:') . "\n" . implode("\n", array_merge(...array_values($byCat)))
            . (count($d['courses']) > 14 ? "\n\n" . $t('See all courses at [/courses](/courses).', 'सभी कोर्स यहाँ देखें: [/courses](/courses)') : '')
            . "\n\n" . $t('Which exam are you preparing for? I can tell you more about that course.', 'आप किस परीक्षा की तैयारी कर रहे हैं? मैं उस कोर्स के बारे में और बता सकता हूँ।');
        return $out($reply, ['admission', 'mock', 'lead'], true);
    }
    if ($mock) {
        if (!$d['tests']) {
            return $out($t('New mock tests are added regularly - see [/mock-tests](/mock-tests). Enrolled students also get course tests in their dashboard.', 'नए मॉक टेस्ट नियमित रूप से जुड़ते हैं - [/mock-tests](/mock-tests) देखें।'), ['courses', 'lead']);
        }
        $lines = array_map(fn($x) => "- [{$x['title']}](/mock-tests/{$x['slug']}) - {$x['duration_minutes']} min, " . (float) $x['total_marks'] . ' ' . $t('marks', 'अंक')
            . ($x['access_type'] === 'free' ? ' · **' . $t('Free', 'फ्री') . '**' : ($x['access_type'] === 'registered' ? ' · ' . $t('free after sign-up', 'रजिस्टर करके फ्री') : '')), array_slice($d['tests'], 0, 8));
        return $out($t('Practise with our timed online mock tests:', 'हमारे ऑनलाइन मॉक टेस्ट से अभ्यास करें:') . "\n" . implode("\n", $lines)
            . "\n\n" . $t('You get your score, rank and answer explanations right after the test. All tests: [/mock-tests](/mock-tests)', 'टेस्ट के बाद स्कोर, रैंक और उत्तरों की व्याख्या मिलती है। सभी टेस्ट: [/mock-tests](/mock-tests)'), ['courses', 'live']);
    }
    if ($live) {
        if (!$d['classes']) {
            return $out($t("No live class is scheduled right now. Enrolled students see their class schedule in the dashboard. For batch timings, {$contact}.", "अभी कोई लाइव क्लास तय नहीं है। बैच टाइमिंग के लिए {$contact}।"), ['courses', 'lead'], true);
        }
        $lines = array_map(fn($l) => '- **' . date('D j M, g:i A', strtotime($l['starts_at'])) . "** - {$l['title']}" . ($l['access'] === 'public' ? ' · ' . $t('open to all', 'सभी के लिए') : ''), array_slice($d['classes'], 0, 5));
        return $out($t('Upcoming live classes:', 'आने वाली लाइव क्लास:') . "\n" . implode("\n", $lines) . "\n\n" . $t('Full schedule: [/live-classes](/live-classes)', 'पूरा शेड्यूल: [/live-classes](/live-classes)'), ['courses', 'lead']);
    }
    if (chatbot_has($n, ['admission', 'admisson', 'enrol', 'enroll', 'join', 'apply', 'register', 'registration', 'sign up', 'signup', 'buy', 'purchase', 'dakhila', 'एडमिशन', 'दाखिला', 'रजिस्टर', 'जुड़'])) {
        // Classroom courses take admission through the Apply Now form (no online checkout).
        if ($d['courses'] && !array_filter($d['courses'], fn($c) => ($c['admission'] ?? 'online') !== 'enquiry')) {
            return $out($t("Taking admission is simple:\n1. Choose your course at [/courses](/courses)\n2. Press **Apply Now** on the course page and fill the short form\n3. Our admission counsellor calls you with batch timings and the fee details\n\nOr leave your number here and a counsellor will call you.",
                "एडमिशन लेना आसान है:\n1. [/courses](/courses) से अपना कोर्स चुनें\n2. कोर्स पेज पर **Apply Now** दबाकर छोटा फॉर्म भरें\n3. हमारे एडमिशन काउंसलर आपको बैच टाइमिंग और फीस की जानकारी के लिए कॉल करेंगे\n\nया यहाँ अपना नंबर दें, काउंसलर कॉल करेंगे।"), ['courses', 'whatsapp'], true);
        }
        return $out($t("Taking admission is simple:\n1. Create a free account at [/register](/register)\n2. Choose your course at [/courses](/courses)\n3. Pay online (UPI, card or net banking) - access starts instantly in your [dashboard](/dashboard)\n\nNeed help choosing? Leave your number and a counsellor will call you.",
            "एडमिशन लेना आसान है:\n1. [/register](/register) पर फ्री अकाउंट बनाएँ\n2. [/courses](/courses) से अपना कोर्स चुनें\n3. ऑनलाइन भुगतान करें (UPI, कार्ड या नेट बैंकिंग) - [डैशबोर्ड](/dashboard) में तुरंत एक्सेस मिलेगा\n\nकोर्स चुनने में मदद चाहिए? अपना नंबर दें, काउंसलर कॉल करेंगे।"), ['courses', 'whatsapp'], true);
    }
    if (chatbot_has($n, ['free', 'notes', 'pdf', 'material', 'resource', 'current affair', 'pyq', 'previous year', 'ncert', 'नोट्स', 'मटेरियल', 'करंट'])) {
        return $out($t('Yes! Free current affairs, previous year papers, NCERT notes, maps and quizzes are on [/resources](/resources). Some courses and mock tests are free too.',
            'हाँ! फ्री करंट अफेयर्स, पिछले वर्ष के प्रश्न पत्र, NCERT नोट्स, मैप्स और क्विज़ [/resources](/resources) पर हैं। कुछ कोर्स और मॉक टेस्ट भी फ्री हैं।'), ['mock', 'courses']);
    }
    if (chatbot_has($n, ['job', 'jobs', 'vacancy', 'vacancies', 'notification', 'naukri', 'recruitment', 'career', 'teacher', 'faculty', 'नौकरी', 'भर्ती', 'वैकेंसी'])) {
        return $out($t('Latest government job notifications, admit cards and results are on [/jobs](/jobs). Want to teach with us? See open faculty positions at [/careers](/careers).',
            'सरकारी नौकरी की ताज़ा सूचनाएँ, एडमिट कार्ड और रिज़ल्ट [/jobs](/jobs) पर देखें। हमारे साथ पढ़ाना चाहते हैं? [/careers](/careers) देखें।'), ['courses', 'mock']);
    }
    if (chatbot_has($n, ['address', 'location', 'located', 'where', 'kaha', 'kahan', 'office', 'centre', 'center', 'branch', 'timing', 'time', 'open', 'phone', 'number', 'mobile', 'email', 'contact', 'reach', 'पता', 'संपर्क', 'कहाँ', 'कहां', 'नंबर', 'समय'])) {
        $lines = array_filter([
            $s['address'] ? '📍 ' . $s['address'] : null,
            $s['phone'] ? '📞 ' . $s['phone'] : null,
            $s['whatsapp'] ? '💬 WhatsApp: +' . $s['whatsapp'] : null,
            $s['email'] ? '✉️ ' . $s['email'] : null,
            $s['hours'] ? '🕘 ' . $s['hours'] : null,
        ]);
        return $out($t("You can reach {$s['name']} here:", "{$s['name']} से संपर्क करें:") . "\n" . implode("\n", $lines) . "\n\n" . $t('Directions and map: [/contact](/contact)', 'नक्शा और रास्ता: [/contact](/contact)'), ['courses', 'lead', 'whatsapp']);
    }
    if ($faq && $faq[0] >= 0.34) {
        return $out(trim(strip_tags($faq[2]['answer'])), ['courses', 'lead']);
    }
    return $out($t("I'm not sure about that one. I can help with courses, fees, mock tests, live classes and admission - or our counsellor can call you back with exact details.",
        'इस बारे में मुझे पक्की जानकारी नहीं है। मैं कोर्स, फीस, मॉक टेस्ट, लाइव क्लास और एडमिशन में मदद कर सकता हूँ - या काउंसलर आपको कॉल करके पूरी जानकारी देंगे।'), ['courses', 'mock', 'admission', 'lead'], true);
}

// ------------------------------------------------------------------
// AI answers (OpenAI Chat Completions or Claude Messages API over HTTPS)
// ------------------------------------------------------------------

function chatbot_http_post(string $url, array $body, array $headers, int $timeout = 45): array
{
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false) {
            throw new RuntimeException('AI service unreachable: ' . curl_error($ch));
        }
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $payload, 'timeout' => $timeout, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException('AI service unreachable');
        }
        preg_match('#HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);
        $status = (int) ($m[1] ?? 0);
    }
    $data = json_decode((string) $raw, true);
    return [$status, is_array($data) ? $data : []];
}

/** Last AI problem, shown in admin → Chatbot. */
function chatbot_record_ai_status(bool $ok, string $message): void
{
    db_query("INSERT INTO site_settings (setting_key, setting_value, setting_group, is_public) VALUES ('chatbot_ai_status', ?, 'chatbot', 0)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [json_encode(['ok' => $ok, 'at' => date('c'), 'msg' => mb_substr($message, 0, 300)])]);
}

/**
 * Send one Messages API request. $messages alternate user/assistant and start with a user turn.
 * Returns ['ok' => bool, 'status' => int, 'json' => decoded body, 'error' => ?string].
 */
function chatbot_claude_request(string $system, array $messages, ?array $schema, int $maxTokens = 8000): array
{
    $model = chatbot_model();
    $body = [
        'model' => $model,
        'max_tokens' => $maxTokens,
        'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
        'messages' => $messages,
        // Chat answers from given facts: low effort keeps replies fast and inexpensive.
        'output_config' => ['effort' => 'low'] + ($schema ? ['format' => ['type' => 'json_schema', 'schema' => $schema]] : []),
    ];
    $headers = ['Content-Type: application/json', 'x-api-key: ' . config('ANTHROPIC_API_KEY'), 'anthropic-version: ' . CHATBOT_API_VERSION];
    // If the model's safety classifiers decline a (usually harmless) question, retry on Anthropic's recommended fallback model.
    if (preg_match('/^claude-(opus-5|fable-5|sonnet-5-5)/', $model)) {
        $body['fallbacks'] = 'default';
        $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
    }
    try {
        [$status, $json] = chatbot_http_post('https://api.anthropic.com/v1/messages', $body, $headers);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'status' => 0, 'json' => [], 'error' => $e->getMessage()];
    }
    if ($status !== 200) {
        $err = $json['error']['message'] ?? "HTTP {$status}";
        return ['ok' => false, 'status' => $status, 'json' => $json, 'error' => match (true) {
            $status === 401 => 'The API key was rejected (401). Check the Claude API key in Keys & Credentials.',
            $status === 429 => 'Rate limited by the AI service (429). ' . $err,
            $status === 529 || $status >= 500 => "The AI service is busy or unavailable ({$status}).",
            default => "Request failed ({$status}): {$err}",
        }];
    }
    return ['ok' => true, 'status' => 200, 'json' => $json, 'error' => null];
}

/** Text of all text blocks in a response. */
function chatbot_response_text(array $json): string
{
    return implode('', array_map(fn($b) => ($b['type'] ?? '') === 'text' ? (string) $b['text'] : '', $json['content'] ?? []));
}

/**
 * Send one Chat Completions request with a strict JSON schema answer.
 * Returns ['ok' => bool, 'status' => int, 'json' => decoded body, 'error' => ?string].
 */
function chatbot_openai_request(string $system, array $messages, ?array $schema, int $maxTokens = 8000): array
{
    $model = chatbot_model();
    $body = [
        'model' => $model,
        'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
        'max_completion_tokens' => $maxTokens,
    ];
    if ($schema) {
        $body['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'chat_reply', 'strict' => true, 'schema' => $schema]];
    }
    // Reasoning models (gpt-5 and later, o-series): low effort keeps chat replies fast and inexpensive.
    if (preg_match('/^(gpt-([5-9]|\d\d)|o\d)/', $model)) {
        $body['reasoning_effort'] = 'low';
    }
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . chatbot_ai_key()];
    if (($org = trim((string) config('OPENAI_ORG_ID', ''))) !== '') {
        $headers[] = 'OpenAI-Organization: ' . $org;
    }
    // Local testing only: OPENAI_API_BASE points at a mock server; ignored in production.
    $base = !is_production() && config('OPENAI_API_BASE') ? rtrim((string) config('OPENAI_API_BASE'), '/') : 'https://api.openai.com/v1';
    try {
        [$status, $json] = chatbot_http_post($base . '/chat/completions', $body, $headers);
        // A model without reasoning support rejects reasoning_effort: ask again without it.
        if ($status === 400 && isset($body['reasoning_effort']) && ($json['error']['param'] ?? '') === 'reasoning_effort') {
            unset($body['reasoning_effort']);
            [$status, $json] = chatbot_http_post($base . '/chat/completions', $body, $headers);
        }
    } catch (RuntimeException $e) {
        return ['ok' => false, 'status' => 0, 'json' => [], 'error' => $e->getMessage()];
    }
    if ($status !== 200) {
        $err = $json['error']['message'] ?? "HTTP {$status}";
        $code = (string) ($json['error']['code'] ?? '');
        $billing = ($json['error']['type'] ?? '') === 'insufficient_quota'
            || in_array($code, ['insufficient_quota', 'credit_balance_exhausted', 'organization_spend_limit_exceeded', 'project_spend_limit_exceeded', 'organization_usage_limit_exceeded'], true);
        return ['ok' => false, 'status' => $status, 'json' => $json, 'error' => match (true) {
            $status === 401 => 'OpenAI rejected the request (401): ' . $err . ' Check the API key (and the Organisation ID, if set) in Keys & Credentials.',
            $billing => 'OpenAI billing limit reached (' . ($code ?: 'insufficient_quota') . '): ' . $err . ' Add credit or raise the spend limit at platform.openai.com → Billing.',
            $status === 429 => 'Rate limited by OpenAI (429). ' . $err,
            $status === 404 || $code === 'model_not_found' => "The model \"{$model}\" is not available for this key. Change the OpenAI model in Keys & Credentials (leave it empty for gpt-6-luna).",
            $status >= 500 => "OpenAI is busy or unavailable ({$status}).",
            default => "Request failed ({$status}): {$err}",
        }];
    }
    return ['ok' => true, 'status' => 200, 'json' => $json, 'error' => null];
}

/**
 * One AI request through the configured provider, in a common shape:
 * ['ok' => bool, 'error' => ?string, 'text' => string, 'stop' => 'end'|'refusal'|<provider reason>, 'tokens' => ['in' => int, 'out' => int]].
 */
function chatbot_ai_request(string $system, array $messages, ?array $schema): array
{
    if (chatbot_ai_provider() === 'openai') {
        $res = chatbot_openai_request($system, $messages, $schema);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'], 'text' => '', 'stop' => '', 'tokens' => ['in' => 0, 'out' => 0]];
        }
        $choice = $res['json']['choices'][0] ?? [];
        $finish = (string) ($choice['finish_reason'] ?? '');
        $refused = trim((string) ($choice['message']['refusal'] ?? '')) !== '' || $finish === 'content_filter';
        $usage = $res['json']['usage'] ?? [];
        return [
            'ok' => true, 'error' => null,
            'text' => (string) ($choice['message']['content'] ?? ''),
            'stop' => $refused ? 'refusal' : ($finish === 'stop' ? 'end' : ($finish ?: 'unknown')),
            'tokens' => ['in' => (int) ($usage['prompt_tokens'] ?? 0), 'out' => (int) ($usage['completion_tokens'] ?? 0)],
        ];
    }
    $res = chatbot_claude_request($system, $messages, $schema);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'], 'text' => '', 'stop' => '', 'tokens' => ['in' => 0, 'out' => 0]];
    }
    $json = $res['json'];
    $usage = $json['usage'] ?? [];
    $stop = (string) ($json['stop_reason'] ?? '');
    return [
        'ok' => true, 'error' => null,
        'text' => chatbot_response_text($json),
        'stop' => $stop === 'end_turn' ? 'end' : ($stop ?: 'unknown'),
        'tokens' => [
            'in' => (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0),
            'out' => (int) ($usage['output_tokens'] ?? 0),
        ],
    ];
}

/**
 * AI reply for the conversation. $history: [['role' => 'user'|'assistant', 'content' => string], ...] ending with the new question.
 * Returns null when the AI is unavailable or the answer could not be used (the caller falls back to built-in answers).
 */
function chatbot_ai_reply(array $history, array $d): ?array
{
    $facts = chatbot_knowledge_text($d) . "\n\nToday's date: " . date('l, j F Y') . ' (India time).';
    $schema = [
        'type' => 'object',
        'properties' => [
            'reply' => ['type' => 'string'],
            'suggestions' => ['type' => 'array', 'items' => ['type' => 'string']],
            'offer_callback' => ['type' => 'boolean'],
            'course_slug' => ['type' => 'string'],
        ],
        'required' => ['reply', 'suggestions', 'offer_callback', 'course_slug'],
        'additionalProperties' => false,
    ];
    $res = chatbot_ai_request(chatbot_system_prompt($d, $facts), $history, $schema);
    if (!$res['ok']) {
        chatbot_record_ai_status(false, (string) $res['error']);
        error_log('Chatbot AI: ' . $res['error']);
        return null;
    }
    $tokens = $res['tokens'];
    $hi = chatbot_is_hindi((string) end($history)['content']);
    if ($res['stop'] === 'refusal') {
        return ['reply' => $hi ? 'माफ़ कीजिए, इसमें मैं मदद नहीं कर सकता। कोर्स, फीस या एडमिशन से जुड़ा कोई भी सवाल पूछें।' : "Sorry, I can't help with that. Ask me anything about our courses, fees or admission.",
            'suggestions' => chatbot_suggestions(['courses', 'admission'], $hi), 'lead_prompt' => false, 'course_slug' => '', 'tokens' => $tokens];
    }
    $out = json_decode($res['text'], true);
    if ($res['stop'] !== 'end' || !is_array($out) || trim((string) ($out['reply'] ?? '')) === '') {
        chatbot_record_ai_status(false, 'Unusable AI answer (stop reason: ' . $res['stop'] . ').');
        return null;
    }
    $sugg = array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 60), (array) ($out['suggestions'] ?? [])))), 0, 3);
    $slug = preg_match('/^[a-z0-9-]{1,200}$/', (string) $out['course_slug']) && in_array($out['course_slug'], array_column($d['courses'], 'slug'), true) ? $out['course_slug'] : '';
    return [
        'reply' => mb_substr(trim((string) $out['reply']), 0, 4000),
        'suggestions' => array_map(fn($x) => ['label' => $x, 'message' => $x], $sugg),
        'lead_prompt' => (bool) $out['offer_callback'],
        'course_slug' => $slug,
        'tokens' => $tokens,
    ];
}

/** Admin "Test connection": asks one sample question through the same path visitors use. */
function chatbot_ai_test(): array
{
    if (chatbot_ai_key() === '') {
        $name = chatbot_ai_provider() === 'openai' ? 'OpenAI' : 'Claude';
        return [false, "No {$name} API key yet - add it in Keys & Credentials" . (config('CHATBOT_AI_PROVIDER') ? " (\"Answer with\" is set to {$name})." : '.')];
    }
    $reply = chatbot_ai_reply([['role' => 'user', 'content' => 'Which courses do you offer?']], chatbot_data());
    if (!$reply) {
        $status = json_decode_array(db_value("SELECT setting_value FROM site_settings WHERE setting_key = 'chatbot_ai_status'"));
        return [false, (string) ($status['msg'] ?? 'The AI did not return a usable answer.')];
    }
    $msg = 'Connected (' . chatbot_model() . '). Sample answer: ' . mb_strimwidth(preg_replace('/\s+/u', ' ', $reply['reply']) ?? '', 0, 140, '…');
    chatbot_record_ai_status(true, $msg);
    return [true, $msg];
}
