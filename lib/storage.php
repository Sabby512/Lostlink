<?php
// Supabase Storage over its REST API (curl). Buckets: items + avatars are PUBLIC, originals + proofs are PRIVATE (served only through PHP).
// Needs SUPABASE_URL (https://<ref>.supabase.co) and SUPABASE_SERVICE_KEY (service_role key; keep it secret, server side only).

function sb_request(string $method, string $path, ?string $body = null, array $headers = []): array {
  $base = rtrim(env('SUPABASE_URL'), '/'); $key = env('SUPABASE_SERVICE_KEY');
  if ($base === '' || $key === '') throw new RuntimeException('SUPABASE_URL / SUPABASE_SERVICE_KEY are not set in the Vercel environment variables.');
  $ch = curl_init($base . $path);
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer ' . $key, 'apikey: ' . $key], $headers)]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  $res = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return ['code' => $code, 'body' => $res === false ? '' : (string)$res];
}
function sb_object(string $bucket, string $name): string { return rawurlencode($bucket) . '/' . rawurlencode(basename($name)); }

function storage_put(string $bucket, string $name, string $bin, string $mime): bool {
  $r = sb_request('POST', '/storage/v1/object/' . sb_object($bucket, $name), $bin, ['Content-Type: ' . $mime, 'x-upsert: true']);
  if ($r['code'] >= 300) error_log("LostLink storage put $bucket/$name failed: {$r['code']} {$r['body']}");
  return $r['code'] < 300;
}
/** ['data' => bytes] or null when the file does not exist. */
function storage_get(string $bucket, string $name): ?array {
  if (basename($name) === '') return null;
  $r = sb_request('GET', '/storage/v1/object/authenticated/' . sb_object($bucket, $name));
  return $r['code'] === 200 ? ['data' => $r['body']] : null;
}
function storage_delete(string $bucket, string $name): void {
  if (basename($name) === '') return;
  try { sb_request('DELETE', '/storage/v1/object/' . sb_object($bucket, $name)); } catch (Throwable $e) { error_log('LostLink storage delete: ' . $e->getMessage()); }
}
function storage_public_url(string $bucket, string $name): string {
  return rtrim(env('SUPABASE_URL'), '/') . '/storage/v1/object/public/' . rawurlencode($bucket) . '/' . rawurlencode($name);
}
/** Send file bytes to the browser with the right content type. */
function serve_bytes(string $bin, string $cache = 'private, max-age=0, must-revalidate', ?string $filename = null): void {
  header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->buffer($bin));
  header('Content-Length: ' . strlen($bin));
  header('Cache-Control: ' . $cache);
  if ($filename !== null) header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\- ]/', '_', $filename) . '"');
  echo $bin; exit;
}
