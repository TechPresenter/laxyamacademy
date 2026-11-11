<?php
/**
 * Optional S3-compatible storage for private uploads (AWS S3, Cloudflare R2, Wasabi,
 * DigitalOcean Spaces, MinIO). Selected in admin → Integrations → Cloud Storage.
 *
 * Credentials live only in .env: S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_KEY, S3_SECRET,
 * S3_PATH_STYLE (1 = https://endpoint/bucket/key, default). Objects are private; downloads
 * use a 5-minute signed URL issued only after the normal access check has passed.
 * Stored paths look like "s3:resources/2026/10/abc.pdf"; local paths are unchanged, so
 * existing files keep working after switching.
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

function s3_config(): ?array
{
    $c = [];
    foreach (['endpoint' => 'S3_ENDPOINT', 'region' => 'S3_REGION', 'bucket' => 'S3_BUCKET', 'key' => 'S3_KEY', 'secret' => 'S3_SECRET'] as $k => $env) {
        $c[$k] = trim((string) config($env, ''));
        if ($c[$k] === '') {
            return null;
        }
    }
    if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', rtrim($c['endpoint'], '/')) || !preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $c['bucket'])) {
        return null;
    }
    $c['endpoint'] = rtrim($c['endpoint'], '/');
    $c['path_style'] = (string) config('S3_PATH_STYLE', '1') !== '0';
    return $c;
}

function s3_active(): bool
{
    return setting('storage_driver', 'local') === 's3' && s3_config() !== null && function_exists('curl_init');
}

/** [host, canonical URI, full URL] for an object key. */
function s3_target(array $c, string $key): array
{
    $host = (string) parse_url($c['endpoint'], PHP_URL_HOST) . (parse_url($c['endpoint'], PHP_URL_PORT) ? ':' . parse_url($c['endpoint'], PHP_URL_PORT) : '');
    $path = '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
    if ($c['path_style']) {
        $path = '/' . $c['bucket'] . $path;
    } else {
        $host = $c['bucket'] . '.' . $host;
    }
    return [$host, $path, 'https://' . $host . $path];
}

function s3_signing_key(array $c, string $date): string
{
    $k = hash_hmac('sha256', $date, 'AWS4' . $c['secret'], true);
    $k = hash_hmac('sha256', $c['region'], $k, true);
    $k = hash_hmac('sha256', 's3', $k, true);
    return hash_hmac('sha256', 'aws4_request', $k, true);
}

/** Signed request (SigV4). Returns [status, body]. */
function s3_request(string $method, string $key, ?string $file = null, string $contentType = 'application/octet-stream'): array
{
    $c = s3_config() ?? throw new RuntimeException('Cloud storage is not configured in .env.');
    [$host, $uri, $url] = s3_target($c, $key);
    $amzDate = gmdate('Ymd\THis\Z');
    $date = substr($amzDate, 0, 8);
    $payload = $file !== null ? hash_file('sha256', $file) : hash('sha256', '');
    $headers = ['host' => $host, 'x-amz-content-sha256' => $payload, 'x-amz-date' => $amzDate];
    if ($file !== null) {
        $headers['content-type'] = $contentType;
    }
    ksort($headers);
    $signed = implode(';', array_keys($headers));
    $canonical = $method . "\n" . $uri . "\n\n" . implode('', array_map(fn($k, $v) => "{$k}:{$v}\n", array_keys($headers), $headers)) . "\n" . $signed . "\n" . $payload;
    $scope = "{$date}/{$c['region']}/s3/aws4_request";
    $toSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonical);
    $sig = hash_hmac('sha256', $toSign, s3_signing_key($c, $date));
    $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$c['key']}/{$scope}, SignedHeaders={$signed}, Signature={$sig}";

    $ch = curl_init($url);
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "{$k}: {$v}", array_keys(array_diff_key($headers, ['host' => 1])), array_diff_key($headers, ['host' => 1]))];
    if ($file !== null) {
        $fh = fopen($file, 'rb');
        $opts += [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($file)];
    }
    if ($method === 'HEAD') {
        $opts[CURLOPT_NOBODY] = true;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (isset($fh)) {
        fclose($fh);
    }
    if ($body === false) {
        throw new RuntimeException('Cloud storage unreachable: ' . $err);
    }
    return [$code, (string) $body];
}

/** Time-limited download URL (query-string SigV4). */
function s3_presign(string $key, string $downloadName, bool $inline, int $ttl = 300): string
{
    $c = s3_config() ?? throw new RuntimeException('Cloud storage is not configured.');
    [$host, $uri, $url] = s3_target($c, $key);
    $amzDate = gmdate('Ymd\THis\Z');
    $date = substr($amzDate, 0, 8);
    $scope = "{$date}/{$c['region']}/s3/aws4_request";
    $q = [
        'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential' => "{$c['key']}/{$scope}",
        'X-Amz-Date' => $amzDate,
        'X-Amz-Expires' => (string) $ttl,
        'X-Amz-SignedHeaders' => 'host',
        'response-cache-control' => 'private, no-store',
        'response-content-disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $downloadName . '"',
    ];
    ksort($q);
    $qs = implode('&', array_map(fn($k, $v) => rawurlencode($k) . '=' . rawurlencode($v), array_keys($q), $q));
    $canonical = "GET\n{$uri}\n{$qs}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
    $toSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonical);
    return $url . '?' . $qs . '&X-Amz-Signature=' . hash_hmac('sha256', $toSign, s3_signing_key($c, $date));
}

/** Upload a local file to the bucket; returns the stored "s3:" path. */
function s3_put(string $localFile, string $key, string $contentType): string
{
    [$code, $body] = s3_request('PUT', $key, $localFile, $contentType);
    if ($code !== 200) {
        error_log('[laxyam] S3 upload failed: HTTP ' . $code . ' ' . substr(strip_tags($body), 0, 200));
        throw new RuntimeException('Upload to cloud storage failed (HTTP ' . $code . '). Please try again.');
    }
    return 's3:' . $key;
}

/** Delete a stored private file (local or cloud). */
function storage_delete(?string $stored): void
{
    if (!$stored) {
        return;
    }
    if (str_starts_with($stored, 's3:')) {
        try {
            s3_request('DELETE', substr($stored, 3));
        } catch (RuntimeException $e) {
            error_log('[laxyam] S3 delete failed: ' . $e->getMessage());
        }
        return;
    }
    if ($p = private_file_path($stored)) {
        @unlink($p);
    }
}
