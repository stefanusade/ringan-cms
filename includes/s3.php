<?php
/**
 * Klien minimal S3-compatible (Cloudflare R2, AWS S3, MinIO, …) — SigV4,
 * tanpa SDK. Mendukung PUT (upload) & DELETE (hapus) objek.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/settings.php';

function s3_settings(): array
{
    return [
        'endpoint' => trim(media_config('endpoint', '')),
        'region' => trim(media_config('region', 'auto')),
        'bucket' => trim(media_config('bucket', '')),
        'access_key' => trim(media_config('access_key', '')),
        'secret_key' => (string) media_config('secret_key', ''),
    ];
}

/**
 * @return array{ok:bool,error?:string}
 */
function s3_request(string $method, string $key, string $payload, string $content_type = 'application/octet-stream'): array
{
    $cfg = s3_settings();
    $missing = [];
    foreach (['endpoint', 'bucket', 'access_key', 'secret_key'] as $f) {
        if ($cfg[$f] === '') {
            $missing[] = $f;
        }
    }
    if ($missing !== []) {
        return ['ok' => false, 'error' => 'Konfigurasi belum lengkap: ' . implode(', ', $missing)];
    }
    $endpoint = rtrim($cfg['endpoint'], '/');
    $region = $cfg['region'] !== '' ? $cfg['region'] : 'auto';
    $host = (string) parse_url($endpoint, PHP_URL_HOST);
    if ($host === '') {
        return ['ok' => false, 'error' => 'Endpoint tidak valid.'];
    }
    $object_uri = '/' . $cfg['bucket'] . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
    $payload_hash = hash('sha256', $payload);
    $amz_date = gmdate('Ymd\THis\Z');
    $date_stamp = gmdate('Ymd');

    $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
    $canonical_request = $method . "\n" . $object_uri . "\n\n"
        . 'host:' . $host . "\n"
        . 'x-amz-content-sha256:' . $payload_hash . "\n"
        . 'x-amz-date:' . $amz_date . "\n\n"
        . $signed_headers . "\n"
        . $payload_hash;

    $scope = $date_stamp . '/' . $region . '/s3/aws4_request';
    $string_to_sign = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash('sha256', $canonical_request);

    $k_date = hash_hmac('sha256', $date_stamp, 'AWS4' . $cfg['secret_key'], true);
    $k_region = hash_hmac('sha256', $region, $k_date, true);
    $k_service = hash_hmac('sha256', 's3', $k_region, true);
    $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
    $signature = hash_hmac('sha256', $string_to_sign, $k_signing);
    $authorization = 'AWS4-HMAC-SHA256 Credential=' . $cfg['access_key'] . '/' . $scope
        . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;

    $url = $endpoint . $object_uri;
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'cURL tidak tersedia.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Host: ' . $host,
            'Content-Type: ' . $content_type,
            'x-amz-content-sha256: ' . $payload_hash,
            'x-amz-date: ' . $amz_date,
            'Authorization: ' . $authorization,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        return ['ok' => true];
    }
    $detail = trim((string) $body);
    if ($detail === '') {
        $detail = $curl_error !== '' ? $curl_error : ('HTTP ' . $code);
    }
    return ['ok' => false, 'error' => 'HTTP ' . $code . ' — ' . mb_substr($detail, 0, 300)];
}

function s3_put_object(string $key, string $file_path, string $content_type = 'application/octet-stream'): array
{
    if (!is_readable($file_path)) {
        return ['ok' => false, 'error' => 'File lokal tidak dapat dibaca.'];
    }
    $payload = (string) file_get_contents($file_path);
    return s3_request('PUT', $key, $payload, $content_type);
}

function s3_delete_object(string $key): array
{
    return s3_request('DELETE', $key, '');
}
