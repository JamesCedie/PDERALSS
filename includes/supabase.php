<?php
/**
 * includes/supabase.php
 * Minimal Supabase Storage client (REST via cURL) for the private "damage-photos" bucket.
 *
 * ENV (set in Render's dashboard, never commit them):
 *   SUPABASE_SERVICE_KEY  service_role key (Project Settings -> API). Server-side only!
 *   SUPABASE_URL          optional, e.g. https://<project-ref>.supabase.co
 *                         (derived from DB_USER "postgres.<project-ref>" when not set)
 */
require_once __DIR__ . '/db.php';

const SB_BUCKET = 'damage-photos';

function sb_base_url(): string
{
    $url = getenv('SUPABASE_URL');
    if (!$url && preg_match('/^postgres\.([a-z0-9]+)$/', DB_USER, $m)) {
        $url = 'https://' . $m[1] . '.supabase.co';
    }
    return rtrim((string) $url, '/');
}

function sb_key(): string
{
    return (string) (getenv('SUPABASE_SERVICE_KEY') ?: '');
}

function sb_configured(): bool
{
    return sb_base_url() !== '' && sb_key() !== '' && function_exists('curl_init');
}

/** Encodes each segment of "folder/sub/file.jpg" but keeps the slashes. */
function sb_encode_path(string $path): string
{
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function sb_request(string $method, string $path, ?string $body = null, array $headers = []): array
{
    $ch = curl_init(sb_base_url() . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => array_merge(
            ['Authorization: Bearer ' . sb_key(), 'apikey: ' . sb_key()],
            $headers
        ),
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    return ['status' => $status, 'body' => $resp === false ? '' : (string) $resp, 'error' => $error];
}

/** Creates the private bucket if it does not exist yet (safe to call repeatedly). */
function sb_ensure_bucket(): void
{
    sb_request(
        'POST',
        '/storage/v1/bucket',
        json_encode([
            'id'                 => SB_BUCKET,
            'name'               => SB_BUCKET,
            'public'             => false,                 // never readable without the service key
            'file_size_limit'    => 12 * 1024 * 1024,      // same 12 MB cap as DA_MAX_BYTES
            'allowed_mime_types' => ['image/jpeg'],        // the in-app camera only produces JPEG
        ]),
        ['Content-Type: application/json']
    );
}

/** Uploads bytes to bucket/path. Returns true on success. Never overwrites an existing object. */
function sb_upload(string $path, string $bytes, string $mime): bool
{
    $endpoint = '/storage/v1/object/' . SB_BUCKET . '/' . sb_encode_path($path);
    $headers  = ['Content-Type: ' . $mime, 'x-upsert: false'];

    $res = sb_request('POST', $endpoint, $bytes, $headers);
    if (in_array($res['status'], [400, 404], true) && stripos($res['body'], 'bucket not found') !== false) {
        sb_ensure_bucket();
        $res = sb_request('POST', $endpoint, $bytes, $headers);
    }
    return $res['status'] >= 200 && $res['status'] < 300;
}

/** Downloads an object from the private bucket using the service key. */
function sb_download(string $path): array
{
    $res = sb_request('GET', '/storage/v1/object/authenticated/' . SB_BUCKET . '/' . sb_encode_path($path));
    return ['ok' => $res['status'] === 200, 'body' => $res['body']];
}

/** Best-effort cleanup (used when a later step of a submission fails). */
function sb_delete(array $paths): void
{
    if (!$paths) return;
    sb_request(
        'DELETE',
        '/storage/v1/object/' . SB_BUCKET,
        json_encode(['prefixes' => array_values($paths)]),
        ['Content-Type: application/json']
    );
}
