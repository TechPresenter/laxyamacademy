<?php
/**
 * Google Search Console - URL Inspection API with a service account (read-only scope).
 *
 * The service-account JSON key is stored in uploads/private/keys/ (blocked from the web)
 * or at the path in .env GSC_SERVICE_ACCOUNT_FILE. The private key is never displayed.
 * Add the service account's email as a user on the Search Console property first.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

const GSC_KEY_FILE = PRIVATE_UPLOADS . '/keys/gsc-service-account.json';

function gsc_key_path(): ?string
{
    $env = (string) config('GSC_SERVICE_ACCOUNT_FILE', '');
    foreach ([$env, GSC_KEY_FILE] as $p) {
        if ($p !== '' && is_file($p) && is_readable($p)) {
            return $p;
        }
    }
    return null;
}

/** ['client_email' => ..., 'private_key' => ...] or null. */
function gsc_credentials(): ?array
{
    $p = gsc_key_path();
    $j = $p ? json_decode((string) file_get_contents($p), true) : null;
    return is_array($j) && !empty($j['client_email']) && !empty($j['private_key']) ? $j : null;
}

/** Validate an uploaded key file's JSON. Returns an error message or null. */
function gsc_validate_key(string $json): ?string
{
    $j = json_decode($json, true);
    if (!is_array($j) || ($j['type'] ?? '') !== 'service_account' || empty($j['client_email']) || empty($j['private_key'])) {
        return 'This is not a Google service-account JSON key (type "service_account" with client_email and private_key).';
    }
    if (!openssl_pkey_get_private($j['private_key'])) {
        return 'The private key in this file could not be read.';
    }
    return null;
}

function gsc_http(string $url, array $headers, string $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        throw new RuntimeException('Could not reach Google: ' . $err);
    }
    return [$code, json_decode((string) $res, true) ?: []];
}

function gsc_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** OAuth access token via a signed JWT (RS256), cached for ~55 minutes. */
function gsc_access_token(): string
{
    $cred = gsc_credentials();
    if (!$cred) {
        throw new RuntimeException('Upload a service-account key first.');
    }
    $cacheFile = cache_dir() . '/gsc-token-' . substr(hash('sha256', $cred['client_email']), 0, 16) . '.php';
    if (is_file($cacheFile) && filemtime($cacheFile) > time() - 3300) {
        $tok = (string) include $cacheFile;
        if ($tok !== '') {
            return $tok;
        }
    }
    $now = time();
    $head = gsc_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = gsc_b64url(json_encode(['iss' => $cred['client_email'], 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
        'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    if (!openssl_sign("{$head}.{$claims}", $sig, $cred['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign the token request with this key.');
    }
    [$code, $j] = gsc_http('https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "{$head}.{$claims}." . gsc_b64url($sig)]));
    if ($code !== 200 || empty($j['access_token'])) {
        throw new RuntimeException('Google refused the key: ' . ($j['error_description'] ?? $j['error'] ?? 'HTTP ' . $code));
    }
    if (!is_dir(cache_dir())) {
        mkdir(cache_dir(), 0750, true);
    }
    // Stored as a PHP file returning the string, inside uploads/private (not web-readable).
    file_put_contents($cacheFile, '<?php return ' . var_export($j['access_token'], true) . ';', LOCK_EX);
    return $j['access_token'];
}

/** Inspect one URL and store the result in indexing_status. Returns the stored row. */
function gsc_inspect(string $url, string $property): array
{
    $row = ['verdict' => null, 'coverage' => null, 'last_crawl' => null, 'error' => null];
    try {
        [$code, $j] = gsc_http('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
            ['Content-Type: application/json', 'Authorization: Bearer ' . gsc_access_token()],
            json_encode(['inspectionUrl' => $url, 'siteUrl' => $property, 'languageCode' => 'en-IN']));
        if ($code !== 200) {
            $row['error'] = mb_substr((string) ($j['error']['message'] ?? 'HTTP ' . $code), 0, 255);
        } else {
            $r = $j['inspectionResult']['indexStatusResult'] ?? [];
            $row['verdict'] = $r['verdict'] ?? null;
            $row['coverage'] = isset($r['coverageState']) ? mb_substr($r['coverageState'], 0, 160) : null;
            $row['last_crawl'] = !empty($r['lastCrawlTime']) ? date('Y-m-d H:i:s', strtotime($r['lastCrawlTime'])) : null;
        }
    } catch (RuntimeException $e) {
        $row['error'] = mb_substr($e->getMessage(), 0, 255);
    }
    db_query('INSERT INTO indexing_status (url, url_hash, verdict, coverage, last_crawl, checked_at, error) VALUES (?, ?, ?, ?, ?, NOW(), ?)
              ON DUPLICATE KEY UPDATE verdict = VALUES(verdict), coverage = VALUES(coverage), last_crawl = VALUES(last_crawl), checked_at = NOW(), error = VALUES(error)',
        [$url, hash('sha256', $url), $row['verdict'], $row['coverage'], $row['last_crawl'], $row['error']]);
    return $row;
}
