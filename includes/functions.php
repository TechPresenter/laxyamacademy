<?php
/**
 * Shared helpers: responses, input validation, escaping, URLs,
 * settings, rate limiting and audit logging.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

// ------------------------------------------------------------------
// Request context and error handling
// ------------------------------------------------------------------

function is_api_request(): bool
{
    return str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? ''), '/api/');
}

set_exception_handler(function (Throwable $e): void {
    error_log('[laxyam] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
    $debug = config('APP_DEBUG', false) && !is_production();
    if (is_api_request()) {
        api_error($debug ? $e->getMessage() : 'Something went wrong. Please try again later.', 500, 'server_error');
    }
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><p style="font-family:sans-serif;padding:2rem">Something went wrong. Please try again later.</p>';
    if ($debug) {
        echo '<pre>' . e($e->getMessage()) . '</pre>';
    }
    exit;
});

// ------------------------------------------------------------------
// JSON API responses: { ok: true, data } | { ok: false, error: { code, message, fields } }
// ------------------------------------------------------------------

function json_send(array $payload, int $status = 200, bool $cacheable = false): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    // Googlebot fetches these while rendering pages (robots.txt allows /api/), but the JSON itself must never be a search result.
    header('X-Robots-Tag: noindex');
    header($cacheable ? 'Cache-Control: public, max-age=60' : 'Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function api_ok(mixed $data = null, int $status = 200, bool $cacheable = false): never
{
    json_send(['ok' => true, 'data' => $data], $status, $cacheable);
}

function api_error(string $message, int $status = 400, string $code = 'bad_request', array $fields = []): never
{
    $error = ['code' => $code, 'message' => $message];
    if ($fields) {
        $error['fields'] = $fields;
    }
    json_send(['ok' => false, 'error' => $error], $status);
}

/** Abort with 405 unless the request uses one of the allowed methods. */
function require_method(string ...$methods): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        api_error('Method not allowed.', 405, 'method_not_allowed');
    }
}

/** Request body as an array (JSON or form-encoded). Limited to 1 MB. */
function request_body(): array
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($type, 'application/json')) {
        $raw = file_get_contents('php://input', false, null, 0, 1_048_576);
        $decoded = json_decode($raw ?: '[]', true);
        if (!is_array($decoded)) {
            api_error('Invalid JSON body.', 400, 'invalid_json');
        }
        return $body = $decoded;
    }
    return $body = $_POST;
}

// ------------------------------------------------------------------
// Input validation
// ------------------------------------------------------------------

/**
 * Validate an input array against simple rules.
 *
 *   [$clean, $errors] = validate($input, [
 *       'name'  => 'required|string|max:120',
 *       'email' => 'required|email',
 *       'phone' => 'phone',
 *       'course_id' => 'int|min:1',
 *       'status' => 'in:new,closed',
 *   ]);
 */
function validate(array $input, array $rules): array
{
    $clean = [];
    $errors = [];
    foreach ($rules as $field => $ruleString) {
        $raw = $input[$field] ?? null;
        $value = is_string($raw) ? trim($raw) : $raw;
        $ruleList = explode('|', $ruleString);
        $required = in_array('required', $ruleList, true);

        if ($value === null || $value === '' || $value === []) {
            if ($required) {
                $errors[$field] = 'This field is required.';
            } else {
                $clean[$field] = null;
            }
            continue;
        }

        foreach ($ruleList as $rule) {
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            $error = null;
            switch ($name) {
                case 'string':
                    if (!is_scalar($value)) {
                        $error = 'Must be text.';
                    } else {
                        // Strip control characters except newlines/tabs
                        $value = preg_replace('/[^\P{C}\n\t]/u', '', (string) $value) ?? '';
                    }
                    break;
                case 'max':
                    if (is_string($value) && mb_strlen($value) > (int) $arg) {
                        $error = "Must be at most {$arg} characters.";
                    } elseif (is_int($value) && $value > (int) $arg) {
                        $error = "Must be at most {$arg}.";
                    }
                    break;
                case 'min':
                    if (is_string($value) && mb_strlen($value) < (int) $arg) {
                        $error = "Must be at least {$arg} characters.";
                    } elseif (is_int($value) && $value < (int) $arg) {
                        $error = "Must be at least {$arg}.";
                    }
                    break;
                case 'email':
                    $value = mb_strtolower((string) $value);
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL) || mb_strlen($value) > 190) {
                        $error = 'Enter a valid email address.';
                    }
                    break;
                case 'phone':
                    $digits = preg_replace('/[^\d+]/', '', (string) $value);
                    if (!preg_match('/^\+?\d{10,14}$/', $digits)) {
                        $error = 'Enter a valid phone number.';
                    }
                    $value = $digits;
                    break;
                case 'int':
                    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                        $error = 'Must be a whole number.';
                    } else {
                        $value = (int) $value;
                    }
                    break;
                case 'bool':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'in':
                    if (!in_array((string) $value, explode(',', (string) $arg), true)) {
                        $error = 'Invalid choice.';
                    }
                    break;
                case 'slug':
                    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $value)) {
                        $error = 'Use lowercase letters, numbers and hyphens only.';
                    }
                    break;
            }
            if ($error !== null) {
                $errors[$field] = $error;
                break;
            }
        }
        if (!isset($errors[$field])) {
            $clean[$field] = $value;
        }
    }
    return [$clean, $errors];
}

/** Validate or stop with a 422 JSON response. */
function validate_or_fail(array $input, array $rules): array
{
    [$clean, $errors] = validate($input, $rules);
    if ($errors) {
        api_error('Please correct the highlighted fields.', 422, 'validation_failed', $errors);
    }
    return $clean;
}

function query_int(string $key, int $default, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
{
    $value = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        return $default;
    }
    return max($min, min($max, $value));
}

function query_str(string $key, int $maxLength = 100): string
{
    $value = $_GET[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }
    return mb_substr(trim($value), 0, $maxLength);
}

/** Comma-separated or array query value -> list of slugs. */
function query_slugs(string $key, int $limit = 20): array
{
    $raw = $_GET[$key] ?? [];
    $items = is_array($raw) ? $raw : explode(',', (string) $raw);
    $items = array_filter(array_map('trim', $items), fn($s) => is_string($s) && preg_match('/^[a-z0-9-]{1,120}$/', $s));
    return array_slice(array_values(array_unique($items)), 0, $limit);
}

// ------------------------------------------------------------------
// Output helpers
// ------------------------------------------------------------------

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function slugify(string $text): string
{
    $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return $slug !== '' ? substr($slug, 0, 180) : 'item-' . bin2hex(random_bytes(3));
}

/** Absolute URL for a site path, e.g. app_url('courses/x'). */
function app_url(string $path = ''): string
{
    return rtrim((string) config('APP_URL', ''), '/') . '/' . ltrim($path, '/');
}

/** Public URL for a stored public upload/asset path, or null. Never use for private files. */
function public_file_url(?string $path): ?string
{
    if (!$path) {
        return null;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if (str_starts_with(ltrim($path, '/'), 'uploads/private')) {
        return null;
    }
    return app_url($path);
}

/** MySQL DATETIME (app timezone) -> ISO 8601 string. */
function iso_datetime(?string $value): ?string
{
    return $value ? (new DateTimeImmutable($value))->format(DATE_ATOM) : null;
}

function json_decode_array(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function client_ip(): string
{
    // REMOTE_ADDR only - forwarded headers are spoofable unless a trusted proxy is configured.
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

// ------------------------------------------------------------------
// Site settings (key/value)
// ------------------------------------------------------------------

function settings_all(bool $publicOnly = false): array
{
    static $cache = [];
    $k = $publicOnly ? 'public' : 'all';
    if (!isset($cache[$k])) {
        $sql = 'SELECT setting_key, setting_value FROM site_settings' . ($publicOnly ? ' WHERE is_public = 1' : '');
        $cache[$k] = array_column(db_all($sql), 'setting_value', 'setting_key');
    }
    return $cache[$k];
}

function setting(string $key, ?string $default = null): ?string
{
    $all = settings_all();
    return isset($all[$key]) && $all[$key] !== '' ? $all[$key] : $default;
}

/**
 * The ISO certificate on file (certificate E20261156292, issued 2 Nov 2026). Used until admin → Settings →
 * Company & Invoicing → ISO certification is saved; a field saved empty stays hidden on the website.
 */
const ISO_CERT_DEFAULTS = [
    'iso_show' => '1',
    'iso_standard' => 'ISO 9001:2015',
    'iso_system' => 'Quality Management System',
    'iso_certificate_no' => 'E20261156292',
    'iso_issued_on' => '2026-11-02',
    'iso_valid_until' => '2029-11-01',
    'iso_surveillance' => '1 Nov 2027 and 1 Nov 2028',
    'iso_body' => 'Audittech Certification Pvt. Ltd. (ACPL)',
    'iso_accreditation' => 'EGAC accredited (QMS CAB# 119012), IAF MLA member',
    'iso_scope' => 'Academic tutoring, education, teaching, training, coaching, mentoring, counselling, academic guidance, remedial education, test preparation and personalised learning services through physical and online platforms.',
    'iso_locations' => "Unit 1: RZ 6C/322, Street No. 8/9, Madanpuri, West Sagarpur, South West Delhi - 110046\nUnit 2: Shop in H.No. 22, Ground Floor, Badarpur, New Delhi - 110044",
    'iso_verify_url' => 'https://www.iafcertsearch.org',
];

/** Public ISO certificate block for the website, or null when it is switched off, incomplete or expired. */
function iso_certificate(): ?array
{
    $all = settings_all();
    $v = fn(string $k): string => trim((string) ($all[$k] ?? ISO_CERT_DEFAULTS[$k]));
    $until = $v('iso_valid_until');
    if ($v('iso_show') !== '1' || $v('iso_standard') === '' || ($until !== '' && $until < date('Y-m-d'))) {
        return null;
    }
    // The photo shows the certificate on file; a renewed certificate number hides it until a new photo is added.
    $photo = $v('iso_certificate_no') === ISO_CERT_DEFAULTS['iso_certificate_no'];
    return [
        'standard' => $v('iso_standard'),
        'system' => $v('iso_system'),
        'number' => $v('iso_certificate_no'),
        'issued_on' => $v('iso_issued_on') ?: null,
        'valid_until' => $until ?: null,
        'surveillance' => $v('iso_surveillance'),
        'body' => $v('iso_body'),
        'accreditation' => $v('iso_accreditation'),
        'scope' => $v('iso_scope'),
        'locations' => array_values(array_filter(array_map('trim', preg_split('/\R/', $v('iso_locations'))))),
        'verify_url' => preg_match('#^https://#i', $v('iso_verify_url')) ? $v('iso_verify_url') : null,
        'image_url' => $photo ? app_url('assets/images/iso-9001-certificate.webp') : null,
        'thumb_url' => $photo ? app_url('assets/images/iso-9001-certificate-thumb.webp') : null,
    ];
}

// ------------------------------------------------------------------
// Rate limiting (sliding window stored in MySQL)
// ------------------------------------------------------------------

/**
 * Record a hit and return true when the caller is still within $max hits per $windowSeconds.
 */
function rate_limit(string $bucket, string $key, int $max, int $windowSeconds): bool
{
    $hash = hash('sha256', $key);
    $count = (int) db_value(
        'SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ? AND key_hash = ? AND created_at > (NOW() - INTERVAL ? SECOND)',
        [$bucket, $hash, $windowSeconds]
    );
    if ($count >= $max) {
        return false;
    }
    db_query('INSERT INTO rate_limit_hits (bucket, key_hash) VALUES (?, ?)', [$bucket, $hash]);
    if (random_int(1, 100) === 1) {
        db_query('DELETE FROM rate_limit_hits WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    }
    return true;
}

// ------------------------------------------------------------------
// Response cache for public, user-independent API data (uploads/private/cache).
// Cleared automatically whenever staff change anything (see audit_log).
// ------------------------------------------------------------------

function cache_dir(): string
{
    return PRIVATE_UPLOADS . '/cache';
}

function cache_remember(string $key, int $ttl, callable $build): mixed
{
    if (setting('cache_enabled', '1') !== '1') {
        return $build();
    }
    $file = cache_dir() . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $key) . '.json';
    if (is_file($file) && filemtime($file) > time() - $ttl) {
        $data = json_decode((string) file_get_contents($file), true);
        if ($data !== null) {
            return $data;
        }
    }
    $data = $build();
    if (!is_dir(cache_dir())) {
        mkdir(cache_dir(), 0750, true);
    }
    file_put_contents($file . '.tmp', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($file . '.tmp', $file);
    return $data;
}

function cache_clear(): int
{
    $n = 0;
    foreach (glob(cache_dir() . '/*.json') ?: [] as $f) {
        $n += (int) @unlink($f);
    }
    return $n;
}

// ------------------------------------------------------------------
// Audit log - never pass passwords, tokens or payment secrets in $details
// ------------------------------------------------------------------

function audit_log(string $action, ?string $entityType = null, int|string|null $entityId = null, array $details = [], string $severity = 'info'): void
{
    $userId = $_SESSION['uid'] ?? null;
    db_query(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, severity, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $userId,
            $action,
            $entityType,
            $entityId === null ? null : (string) $entityId,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            $severity,
            client_ip(),
            user_agent(),
        ]
    );
    // Any staff change may affect public pages: drop cached API responses.
    if (str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? ''), '/admin/')) {
        cache_clear();
    }
}

// ------------------------------------------------------------------
// Managed option lists (lookup_values): departments, employment types, ...
// ------------------------------------------------------------------

/** [value => label] for an option list, active entries only. */
function lookup_options(string $list): array
{
    static $cache = [];
    return $cache[$list] ??= array_column(
        db_all('SELECT value, label FROM lookup_values WHERE list_key = ? AND is_active = 1 ORDER BY sort_order, label', [$list]),
        'label', 'value'
    );
}

function lookup_label(string $list, ?string $value): ?string
{
    return $value === null ? null : (lookup_options($list)[$value] ?? $value);
}

/** Integer setting with bounds. */
function setting_int(string $key, int $default, int $min, int $max): int
{
    $v = filter_var(setting($key), FILTER_VALIDATE_INT);
    return $v === false ? $default : max($min, min($max, $v));
}

// ------------------------------------------------------------------
// Campaign attribution (first-touch UTM data captured by the website)
// ------------------------------------------------------------------

/** Clean an attribution object sent by the browser; returns null when empty. */
function clean_attribution(mixed $a): ?array
{
    if (!is_array($a)) {
        return null;
    }
    $txt = fn($k, $max) => is_string($a[$k] ?? null) && preg_match('/^[\w .\-\/%+:@]{1,' . $max . '}$/u', $a[$k]) ? mb_substr($a[$k], 0, $max) : null;
    $out = [
        'utm_source' => $txt('utm_source', 80), 'utm_medium' => $txt('utm_medium', 80), 'utm_campaign' => $txt('utm_campaign', 120),
        'referrer_host' => $txt('referrer_host', 120), 'landing_path' => $txt('landing_path', 255),
    ];
    return array_filter($out) ? $out : null;
}

function record_attribution(string $entityType, int $entityId, mixed $raw): void
{
    $a = clean_attribution($raw);
    if (!$a || !in_array($entityType, ['user', 'enquiry', 'order'], true)) {
        return;
    }
    db_query('INSERT IGNORE INTO attributions (entity_type, entity_id, utm_source, utm_medium, utm_campaign, referrer_host, landing_path) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$entityType, $entityId, $a['utm_source'], $a['utm_medium'], $a['utm_campaign'], $a['referrer_host'], $a['landing_path']]);
}

// ------------------------------------------------------------------
// reCAPTCHA v3 (active only when the site key is set in admin and RECAPTCHA_SECRET in .env)
// ------------------------------------------------------------------

function recaptcha_enabled(): bool
{
    return (string) setting('recaptcha_site_key', '') !== '' && (string) config('RECAPTCHA_SECRET', '') !== '';
}

/** Stop with 422 when reCAPTCHA is enabled and the token is missing, invalid or scores too low. */
function require_recaptcha(): void
{
    if (!recaptcha_enabled()) {
        return;
    }
    $token = (string) (request_body()['recaptcha'] ?? '');
    $ok = false;
    if ($token !== '') {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 8, 'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query(['secret' => config('RECAPTCHA_SECRET'), 'response' => $token, 'remoteip' => client_ip()])]]);
        $res = json_decode((string) @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $ctx), true);
        $ok = !empty($res['success']) && (float) ($res['score'] ?? 0) >= (float) setting('recaptcha_min_score', '0.5');
        if ($res === null) {
            $ok = true; // Google unreachable: fail open (rate limiting still applies) rather than block real students
            error_log('[laxyam] reCAPTCHA verification unreachable');
        }
    }
    if (!$ok) {
        api_error('Please refresh the page and try again (spam check failed).', 422, 'recaptcha_failed');
    }
}

// ------------------------------------------------------------------
// In-site notifications
// ------------------------------------------------------------------

function notify(int $userId, string $type, string $title, ?string $message = null, ?string $link = null): void
{
    db_query(
        'INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)',
        [$userId, $type, mb_substr($title, 0, 160), $message !== null ? mb_substr($message, 0, 500) : null, $link]
    );
}

// ------------------------------------------------------------------
// HTML sanitising for staff-authored rich text (lessons, blog, pages).
// Allow-list of tags; every attribute is dropped except safe href/src/alt.
// ------------------------------------------------------------------

function sanitize_html(?string $html): string
{
    if ($html === null || trim($html) === '') {
        return '';
    }
    $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'a', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'code', 'pre', 'span'];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    $root = $doc->getElementById('__root');
    if (!$root) {
        return e(strip_tags($html));
    }
    $walk = function (DOMNode $node) use (&$walk, $allowed): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'svg', 'math'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                $walk($child);
                if (!in_array($tag, $allowed, true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                $keep = [];
                foreach (['href' => 'a', 'src' => 'img', 'alt' => 'img'] as $attr => $onTag) {
                    if ($tag === $onTag && $child->hasAttribute($attr)) {
                        $keep[$attr] = trim($child->getAttribute($attr));
                    }
                }
                foreach (iterator_to_array($child->attributes) as $a) {
                    $child->removeAttribute($a->name);
                }
                foreach ($keep as $attr => $value) {
                    if (($attr === 'href' || $attr === 'src') && !preg_match('#^(https?://|/|mailto:|tel:)#i', $value)) {
                        continue;
                    }
                    $child->setAttribute($attr, $value);
                }
                if ($tag === 'a' && $child->hasAttribute('href') && preg_match('#^https?://#i', $child->getAttribute('href'))) {
                    $child->setAttribute('rel', 'noopener noreferrer nofollow');
                    $child->setAttribute('target', '_blank');
                }
            } elseif ($child instanceof DOMComment) {
                $node->removeChild($child);
            }
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

/** Plain text (one paragraph per blank line) -> safe HTML paragraphs. */
function text_to_html(?string $text): string
{
    if ($text === null || trim($text) === '') {
        return '';
    }
    if (preg_match('/<(p|h2|h3|ul|ol|br)\b/i', $text)) {
        return sanitize_html($text);
    }
    $paras = preg_split('/\n\s*\n/', trim($text));
    return implode('', array_map(fn($p) => '<p>' . nl2br(e(trim($p))) . '</p>', $paras));
}

// ------------------------------------------------------------------
// Private file streaming (authorisation must be checked by the caller)
// ------------------------------------------------------------------

/** Resolve a stored relative path inside uploads/private; null if missing or outside. */
function private_file_path(?string $relative): ?string
{
    if (!$relative) {
        return null;
    }
    // Cloud storage object (includes/storage.php): returned as-is; stream_file() signs a URL.
    if (str_starts_with($relative, 's3:')) {
        return preg_match('#^s3:[a-z0-9-]+/\d{4}/\d{2}/[a-f0-9]{24}\.[a-z0-9]{2,5}$#', $relative) ? $relative : null;
    }
    $base = realpath(PRIVATE_UPLOADS);
    $full = realpath(PRIVATE_UPLOADS . '/' . ltrim(str_replace('\\', '/', $relative), '/'));
    if (!$base || !$full || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) {
        return null;
    }
    return $full;
}

/** Stream a file with HTTP Range support (video seeking) and no caching by proxies. */
function stream_file(string $fullPath, string $downloadName, bool $inline = true): never
{
    if (str_starts_with($fullPath, 's3:')) {
        require_once APP_ROOT . '/includes/storage.php';
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $downloadName) ?: 'file';
        header('Cache-Control: private, no-store');
        header('Location: ' . s3_presign(substr($fullPath, 3), $safe, $inline), true, 302);
        exit;
    }
    $size = filesize($fullPath);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($fullPath) ?: 'application/octet-stream';
    $start = 0;
    $end = $size - 1;

    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') {
            $start = max(0, $size - (int) $m[2]);
        } else {
            $start = (int) $m[1];
            $end = $m[2] !== '' ? min((int) $m[2], $size - 1) : $end;
        }
        if ($start > $end || $start >= $size) {
            header("Content-Range: bytes */{$size}");
            http_response_code(416);
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $downloadName) ?: 'file';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . ($end - $start + 1));
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // don't block other requests from this user while streaming
    }
    $fh = fopen($fullPath, 'rb');
    fseek($fh, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($fh) && !connection_aborted()) {
        $chunk = fread($fh, (int) min(1_048_576, $remaining));
        echo $chunk;
        flush();
        $remaining -= strlen($chunk);
    }
    fclose($fh);
    exit;
}

// ------------------------------------------------------------------
// Upload storage (shared by api/upload.php and admin forms).
// Throw InvalidArgumentException with a user-facing message on bad input.
// ------------------------------------------------------------------

const PRIVATE_UPLOAD_TYPES = [
    'application/pdf' => 'pdf',
    'video/mp4' => 'mp4',
    'video/webm' => 'webm',
    'audio/mpeg' => 'mp3',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'application/zip' => 'zip',
];

function uploaded_file_ok(?array $file): bool
{
    return $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name']);
}

/** Decode, resize and re-encode an image to WebP under uploads/<folder>/. Returns the relative path. */
function store_image_upload(array $file, string $folder, int $maxW = 1600, int $maxH = 1600, bool $crop = false, int $maxBytes = 8_388_608): string
{
    if (!uploaded_file_ok($file)) {
        throw new InvalidArgumentException('Please choose an image to upload.');
    }
    if ($file['size'] > $maxBytes) {
        throw new InvalidArgumentException('Image must be smaller than ' . round($maxBytes / 1048576) . ' MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) || !getimagesize($file['tmp_name'])) {
        throw new InvalidArgumentException('Please upload a JPG, PNG, WebP or GIF image.');
    }
    $src = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
    if (!$src) {
        throw new InvalidArgumentException('This image could not be read.');
    }
    [$w, $h] = [imagesx($src), imagesy($src)];
    if ($w > 8000 || $h > 8000) {
        throw new InvalidArgumentException('Image dimensions are too large.');
    }
    if ($crop) {
        $side = min($w, $h);
        $dst = imagecreatetruecolor($maxW, $maxH);
        imagecopyresampled($dst, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $maxW, $maxH, $side, $side);
    } else {
        $scale = min(1, $maxW / $w, $maxH / $h);
        $dst = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
    }
    $folder = preg_replace('/[^a-z0-9-]/', '', $folder);
    $dir = APP_ROOT . '/uploads/' . $folder;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.webp';
    if (!imagewebp($dst, $dir . '/' . $name, 82)) {
        throw new RuntimeException('Image could not be saved.');
    }
    return 'uploads/' . $folder . '/' . $name;
}

/** Store a private file (PDF, video, …) under uploads/private/<folder>/Y/m. Returns [path, size, type]. */
function store_private_upload(array $file, string $folder, int $maxBytes, array $types = PRIVATE_UPLOAD_TYPES): array
{
    if (!uploaded_file_ok($file)) {
        throw new InvalidArgumentException('Please choose a file to upload.');
    }
    if ($file['size'] > $maxBytes) {
        throw new InvalidArgumentException('File is larger than the ' . round($maxBytes / 1048576) . ' MB limit.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext = $types[$mime] ?? null;
    if (!$ext) {
        throw new InvalidArgumentException('This file type is not allowed (' . implode(', ', array_unique(array_map('strtoupper', $types))) . ' only).');
    }
    $folder = preg_replace('/[^a-z0-9-]/', '', $folder);
    $sub = $folder . '/' . date('Y/m');
    require_once APP_ROOT . '/includes/storage.php';
    if (s3_active()) {
        $key = $sub . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        return ['path' => s3_put($file['tmp_name'], $key, $mime), 'size' => (int) $file['size'], 'type' => $ext];
    }
    if (!is_dir(PRIVATE_UPLOADS . '/' . $sub)) {
        mkdir(PRIVATE_UPLOADS . '/' . $sub, 0750, true);
    }
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], PRIVATE_UPLOADS . '/' . $sub . '/' . $name)) {
        throw new RuntimeException('Upload failed. Please try again.');
    }
    return ['path' => $sub . '/' . $name, 'size' => (int) $file['size'], 'type' => $ext];
}

function upload_limit_bytes(): int
{
    return (int) setting('upload_max_mb', (string) config('UPLOAD_MAX_MB', 20)) * 1_048_576;
}

// ------------------------------------------------------------------
// Pagination
// ------------------------------------------------------------------

function pagination_meta(int $total, int $page, int $perPage): array
{
    return [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => max(1, (int) ceil($total / max(1, $perPage))),
    ];
}

// ------------------------------------------------------------------
// Integration API (api/v1.php) scopes - granted per key in admin/api-keys.php
// ------------------------------------------------------------------

const API_SCOPES = [
    'courses.read' => 'Read published courses',
    'enquiries.read' => 'Read enquiries / leads',
    'enquiries.write' => 'Create enquiries (e.g. from an external form)',
    'orders.read' => 'Read paid orders',
    'students.read' => 'Read student registrations (name, email, phone)',
];
