<?php
/**
 * Keys & credentials saved from admin → SEO & Integrations → Keys & Credentials.
 *
 * Values live encrypted (AES-256-GCM) in the app_config table. config() reads them first for the keys in
 * APP_CONFIG_FIELDS and falls back to .env, so a key can be set in either place (admin wins).
 * Database, session and app settings (APP_*, DB_*, SESSION_*) stay in .env only.
 *
 * Encryption key: CONFIG_KEY in .env when set, otherwise a random key in uploads/private/.config-key
 * (created on the first save, never deployed or web-readable), so a database backup alone cannot reveal the secrets.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

// group => [title, icon, description, test page]
const APP_CONFIG_GROUPS = [
    'ai' => ['AI chatbot', 'chat', 'The website chat assistant answers free-form questions in English, Hindi and Hinglish. Without a key it uses built-in answers.', 'chatbot.php?tab=settings#ai'],
    'payments' => ['Payment gateway', 'rupee', 'Online course payments. Sandbox and live keys are different - start in sandbox, make one test purchase, then switch to live.', 'integrations.php?tab=payments'],
    'email' => ['Email (SMTP)', 'mail', 'Outgoing emails: welcome, enrolment, invoices, password reset. Use a Hostinger email account (hPanel → Emails).', 'integrations.php?tab=email'],
    'whatsapp' => ['WhatsApp (Interakt)', 'whatsapp', 'Automatic WhatsApp messages. Interakt → Settings → Developer Settings.', 'whatsapp.php'],
    'login' => ['Student login (Google / mobile OTP)', 'key', '“Continue with Google” and login with a one-time code by WhatsApp or SMS. Each option appears on the login page only once its keys are here.', 'integrations.php?tab=login'],
    'recaptcha' => ['reCAPTCHA', 'shield', 'Spam protection for public forms. The site key is under Integrations → reCAPTCHA.', 'integrations.php?tab=recaptcha'],
    'storage' => ['Cloud storage (S3)', 'server', 'Optional: store private files (videos, PDFs, resumes) in an S3-compatible bucket.', 'storage.php'],
];

// key => [group, label, type (secret|text|email|int|select), help or select options]
const APP_CONFIG_FIELDS = [
    'OPENAI_API_KEY' => ['ai', 'OpenAI API key', 'secret', 'platform.openai.com → API keys → Create new secret key. The OpenAI account needs billing credit.'],
    'OPENAI_MODEL' => ['ai', 'OpenAI model', 'text', 'Empty = gpt-6-luna (recommended: fast, lowest cost). Smarter but costlier: gpt-6.1-terra.'],
    'OPENAI_ORG_ID' => ['ai', 'OpenAI organisation ID', 'text', 'Optional - only when the key belongs to several organisations.'],
    'CHATBOT_AI_PROVIDER' => ['ai', 'Answer with', 'select', ['' => 'Automatic (OpenAI when its key is set)', 'openai' => 'OpenAI', 'anthropic' => 'Claude (Anthropic)']],
    'ANTHROPIC_API_KEY' => ['ai', 'Claude API key', 'secret', 'Optional, instead of OpenAI: console.anthropic.com → API keys.'],
    'ANTHROPIC_MODEL' => ['ai', 'Claude model', 'text', 'Empty = claude-opus-5-5. Others: claude-sonnet-5-5, claude-haiku-5-5.'],

    'PAYMENT_PROVIDER' => ['payments', 'Gateway', 'select', ['' => 'Default (Cashfree)', 'cashfree' => 'Cashfree', 'razorpay' => 'Razorpay']],
    'PAYMENT_MODE' => ['payments', 'Mode', 'select', ['' => 'Default (sandbox)', 'sandbox' => 'Sandbox - test payments, no real money', 'live' => 'Live - real payments']],
    'CASHFREE_APP_ID' => ['payments', 'Cashfree App ID', 'text', 'Cashfree Merchant Dashboard → Developers → API Keys.'],
    'CASHFREE_SECRET_KEY' => ['payments', 'Cashfree Secret Key', 'secret', 'Same screen as the App ID. Also signs the webhooks.'],
    'CASHFREE_WEBHOOK_SECRET' => ['payments', 'Cashfree extra webhook key', 'secret', 'Optional - leave empty (Cashfree signs webhooks with the Secret Key).'],
    'RAZORPAY_KEY_ID' => ['payments', 'Razorpay Key ID', 'text', 'Only when the gateway is Razorpay: Dashboard → Settings → API Keys.'],
    'RAZORPAY_KEY_SECRET' => ['payments', 'Razorpay Key Secret', 'secret', ''],
    'RAZORPAY_WEBHOOK_SECRET' => ['payments', 'Razorpay webhook secret', 'secret', 'Dashboard → Settings → Webhooks.'],

    'SMTP_HOST' => ['email', 'SMTP server', 'text', 'Hostinger: smtp.hostinger.com'],
    'SMTP_PORT' => ['email', 'Port', 'int', '465 with SSL or 587 with TLS.'],
    'SMTP_SECURE' => ['email', 'Encryption', 'select', ['' => 'Default (TLS)', 'tls' => 'TLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None']],
    'SMTP_USER' => ['email', 'Username', 'text', 'The full email address, e.g. info@laxyamacademy.com'],
    'SMTP_PASS' => ['email', 'Password', 'secret', 'The password of that email account.'],
    'MAIL_FROM' => ['email', 'From address', 'email', 'Usually the same as the username.'],
    'MAIL_FROM_NAME' => ['email', 'From name', 'text', 'e.g. Laxyam Academy'],

    'INTERAKT_API_KEY' => ['whatsapp', 'Interakt Secret Key', 'secret', 'Paste exactly as Interakt shows it.'],
    'INTERAKT_WEBHOOK_SECRET' => ['whatsapp', 'Interakt webhook secret', 'secret', 'The same secret you enter for the webhook in Interakt.'],
    'WHATSAPP_TOKEN' => ['whatsapp', 'Meta Cloud API token', 'secret', 'Only when sending through the Meta WhatsApp Cloud API instead of Interakt.'],
    'WHATSAPP_PHONE_ID' => ['whatsapp', 'Meta phone number ID', 'text', 'Only for the Meta Cloud API.'],

    'GOOGLE_CLIENT_ID' => ['login', 'Google OAuth client ID', 'text', 'console.cloud.google.com → APIs & Services → Credentials → Create OAuth client ID → Web application. Authorised JavaScript origin: https://laxyamacademy.com'],
    'OTP_WHATSAPP_TEMPLATE' => ['login', 'WhatsApp OTP template name', 'text', 'An approved “Authentication” template in Interakt with the code as {{1}} and a copy-code button. Empty = no WhatsApp codes.'],
    'OTP_WHATSAPP_LANGUAGE' => ['login', 'WhatsApp OTP template language', 'text', 'The template’s language code, e.g. en (default) or hi.'],
    'MSG91_AUTH_KEY' => ['login', 'MSG91 auth key (SMS codes)', 'secret', 'msg91.com → Authkey. Empty = no SMS codes.'],
    'MSG91_OTP_TEMPLATE_ID' => ['login', 'MSG91 OTP template ID', 'text', 'MSG91 → OTP → your DLT-approved template that contains ##OTP##.'],

    'RECAPTCHA_SECRET' => ['recaptcha', 'reCAPTCHA v3 secret key', 'secret', 'google.com/recaptcha/admin → your site → Settings.'],

    'S3_ENDPOINT' => ['storage', 'Endpoint', 'text', 'e.g. https://s3.ap-south-1.amazonaws.com'],
    'S3_REGION' => ['storage', 'Region', 'text', 'e.g. ap-south-1 (Cloudflare R2: auto)'],
    'S3_BUCKET' => ['storage', 'Bucket', 'text', 'A private bucket.'],
    'S3_KEY' => ['storage', 'Access key ID', 'text', ''],
    'S3_SECRET' => ['storage', 'Secret access key', 'secret', ''],
    'S3_PATH_STYLE' => ['storage', 'URL style', 'select', ['' => 'Default (path-style)', '1' => 'Path-style', '0' => 'Virtual-hosted']],
];

const APP_CONFIG_KEY_FILE = PRIVATE_UPLOADS . '/.config-key';

/** 32-byte encryption key, or null when none exists yet (and $create is false). */
function app_config_cipher_key(bool $create = false): ?string
{
    $env = trim((string) (env_values()['CONFIG_KEY'] ?? ''));
    if ($env !== '') {
        return hash('sha256', $env, true);
    }
    if (is_file(APP_CONFIG_KEY_FILE)) {
        $hex = strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) @file_get_contents(APP_CONFIG_KEY_FILE))));
        if (preg_match('/^[0-9a-f]{64}$/', $hex)) {
            return hex2bin($hex);
        }
        if ($create) {
            // Never overwrite an existing key file: it is the only way to read the keys already saved.
            throw new RuntimeException('uploads/private/.config-key exists but is not a valid key. Restore it from your backup (or remove it to start over and re-enter all keys).');
        }
        return null;
    }
    if (!$create) {
        return null;
    }
    if (!is_dir(PRIVATE_UPLOADS) && !@mkdir(PRIVATE_UPLOADS, 0750, true)) {
        throw new RuntimeException('Cannot create uploads/private for the encryption key.');
    }
    $hex = bin2hex(random_bytes(32));
    if (@file_put_contents(APP_CONFIG_KEY_FILE, $hex . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write the encryption key file (uploads/private/.config-key).');
    }
    @chmod(APP_CONFIG_KEY_FILE, 0600);
    return hex2bin($hex);
}

/** AES-256-GCM; the setting name is bound as associated data, so a value copied into another row does not decrypt. */
function app_config_encrypt(string $plain, string $key, string $name): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'laxyam-config|' . $name);
    if ($ct === false) {
        throw new RuntimeException('Encryption failed.');
    }
    return 'v2:' . base64_encode($iv . $tag . $ct);
}

function app_config_decrypt(string $stored, string $key, string $name): ?string
{
    $raw = str_starts_with($stored, 'v2:') ? base64_decode(substr($stored, 3), true) : false;
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'laxyam-config|' . $name);
    return $plain === false ? null : $plain;
}

/** Stored rows: key => ['value' => ?string (null = cannot decrypt), 'updated_at', 'updated_by']. Empty when the table is missing. */
function app_config_rows(bool $reload = false): array
{
    static $rows = null;
    if ($rows !== null && !$reload) {
        return $rows;
    }
    $rows = [];
    if (!function_exists('openssl_decrypt')) {
        return $rows;
    }
    try {
        $list = db_all('SELECT config_key, config_value, updated_at, updated_by FROM app_config');
    } catch (Throwable) {
        return $rows; // table not created yet (Database Updates pending) or no database
    }
    $key = $list ? app_config_cipher_key() : null;
    foreach ($list as $r) {
        if (isset(APP_CONFIG_FIELDS[$r['config_key']])) {
            $rows[$r['config_key']] = [
                'value' => $key ? app_config_decrypt((string) $r['config_value'], $key, $r['config_key']) : null,
                'updated_at' => $r['updated_at'],
                'updated_by' => $r['updated_by'],
            ];
        }
    }
    return $rows;
}

/**
 * Value saved in admin for $key: '' when none is saved, null when one is saved but cannot be decrypted
 * (lost key file). config() then treats the setting as unset instead of silently using an old .env value.
 */
function app_config_value(string $key): ?string
{
    static $busy = false;
    if ($busy || !isset(APP_CONFIG_FIELDS[$key])) {
        return '';
    }
    $busy = true;
    try {
        $rows = app_config_rows();
        return isset($rows[$key]) ? $rows[$key]['value'] : '';
    } finally {
        $busy = false;
    }
}

/** Names of saved settings that cannot be decrypted. */
function app_config_broken(): array
{
    return array_keys(array_filter(app_config_rows(), fn($r) => $r['value'] === null));
}

function app_config_table_ready(): bool
{
    try {
        db_value('SELECT COUNT(*) FROM app_config');
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** Save ($value !== '') or remove ($value === '') the admin value of one key. */
function app_config_save(string $key, string $value, ?int $userId): void
{
    if (!isset(APP_CONFIG_FIELDS[$key])) {
        throw new InvalidArgumentException('Unknown key');
    }
    if ($value === '') {
        db_query('DELETE FROM app_config WHERE config_key = ?', [$key]);
    } else {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('The PHP OpenSSL extension is required to store keys.');
        }
        db_query('INSERT INTO app_config (config_key, config_value, updated_by) VALUES (?, ?, ?)
                  ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP',
            [$key, app_config_encrypt($value, (string) app_config_cipher_key(true), $key), $userId]);
    }
    app_config_rows(true);
}

/** Where the effective value of $key comes from: 'admin', 'broken' (saved, unreadable), 'env' or ''. */
function app_config_source(string $key): string
{
    $saved = app_config_value($key);
    if ($saved === null) {
        return 'broken';
    }
    if ($saved !== '') {
        return 'admin';
    }
    $env = env_values()[$key] ?? getenv($key);
    return $env !== false && $env !== null && $env !== '' ? 'env' : '';
}

/**
 * Masked hint for a secret: only the last 4 characters of long generated API keys; nothing for passwords and
 * webhook secrets people choose themselves (those are short enough to guess from fragments).
 */
function app_config_mask(string $v, string $name = ''): string
{
    $chosen = $name === 'SMTP_PASS' || str_ends_with($name, '_WEBHOOK_SECRET');
    return !$chosen && strlen($v) >= 20 ? str_repeat('•', 8) . substr($v, -4) : str_repeat('•', 8);
}
