<?php
// LostLink on Vercel + Supabase. Every setting comes from Vercel Environment Variables (see .env.example).
// Nothing is written to the local disk: Vercel functions have a read-only, throw-away filesystem.

function env(string $k, string $d = ''): string {
  $v = getenv($k);
  if ($v === false || $v === '') $v = $_SERVER[$k] ?? ($_ENV[$k] ?? '');
  return ($v === false || $v === null || $v === '') ? $d : (string)$v;
}

// ---- Admin accounts: anyone who logs in with one of these emails (comma separated in ADMIN_EMAILS) becomes an admin ----
function is_admin_email(string $email): bool {
  $list = array_filter(array_map(fn($e) => strtolower(trim($e)), explode(',', env('ADMIN_EMAILS'))));
  return in_array(strtolower(trim($email)), $list, true);
}

require_once __DIR__ . '/storage.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');               // never print PHP errors into JSON
ini_set('log_errors', '1');                   // goes to the Vercel function logs

if (!defined('RAW_OUTPUT')) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
}
header('X-Content-Type-Options: nosniff');

// Any unexpected error answers with JSON instead of an empty 500 page. Set DEBUG_ERRORS=1 in Vercel to see the real cause.
set_exception_handler(function (Throwable $e) {
  error_log('LostLink: ' . $e);
  if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
  echo json_encode(['error' => 'Server error. Please try again.' . (env('DEBUG_ERRORS') === '1' ? ' [' . $e->getMessage() . ']' : '')]);
});

date_default_timezone_set('Asia/Manila');

function is_https(): bool {
  return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $url = env('DATABASE_URL');                  // postgresql://postgres.<ref>:<password>@aws-0-<region>.pooler.supabase.com:6543/postgres
  if ($url === '') fail('DATABASE_URL is not set in the Vercel environment variables.', 500);
  $p = parse_url($url);
  if (!$p || empty($p['host'])) fail('DATABASE_URL is not a valid connection string.', 500);
  try {
    $pdo = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require', $p['host'], $p['port'] ?? 5432, ltrim($p['path'] ?? '/postgres', '/')),
      rawurldecode($p['user'] ?? ''), rawurldecode($p['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,    // required by the Supabase transaction pooler (port 6543): no server-side prepared statements
      ]);
    try { $pdo->exec("SET TIME ZONE 'Asia/Manila'"); } catch (Throwable $e) {}   // the database default is also set in sql/schema.sql
  } catch (Throwable $e) {
    error_log('LostLink DB: ' . $e->getMessage());
    fail('Cannot reach the database.', 500);
  }
  return $pdo;
}

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail(string $msg, int $code = 400): void { out(['error' => $msg], $code); }

/* ---------- sessions live in the database (each Vercel function call may run on a different machine) ---------- */
final class DbSessionHandler implements SessionHandlerInterface {
  public function open(string $path, string $name): bool { return true; }
  public function close(): bool { return true; }
  public function read(string $id): string|false {
    $st = db()->prepare('SELECT data FROM php_sessions WHERE id = ?'); $st->execute([$id]);
    $d = $st->fetchColumn(); return $d === false ? '' : (string)$d;
  }
  public function write(string $id, string $data): bool {
    db()->prepare('INSERT INTO php_sessions (id, data, updated_at) VALUES (?, ?, NOW()) ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, updated_at = NOW()')->execute([$id, $data]);
    return true;
  }
  public function destroy(string $id): bool { db()->prepare('DELETE FROM php_sessions WHERE id = ?')->execute([$id]); return true; }
  public function gc(int $max_lifetime): int|false {
    $st = db()->prepare("DELETE FROM php_sessions WHERE updated_at < NOW() - INTERVAL '" . (int)$max_lifetime . " seconds'"); $st->execute(); return $st->rowCount();
  }
}
ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
ini_set('session.gc_probability', '1'); ini_set('session.gc_divisor', '200');
session_set_save_handler(new DbSessionHandler(), true);
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => is_https(), 'samesite' => 'Lax']);
session_name('LOSTLINKSESS');
session_start();

/** Body of a POST as an array (JSON). The custom header also blocks cross-site form posts. */
function body(): array {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') return [];
  if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') fail('Bad request.', 400);
  $j = json_decode(file_get_contents('php://input') ?: '[]', true);
  return is_array($j) ? $j : [];
}

function current_user_id(): ?int { return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null; }
function require_login(): int {
  $id = current_user_id();
  if (!$id) fail('Please log in.', 401);
  // A suspended account, or a session older than the last password change / reset, is signed out here.
  $st = db()->prepare('SELECT status, session_version FROM users WHERE id = ?'); $st->execute([$id]);
  $r = $st->fetch();
  if (!$r || $r['status'] !== 'active' || (int)$r['session_version'] !== (int)($_SESSION['sv'] ?? 0)) {
    $_SESSION = []; session_destroy();
    fail('Please log in.', 401);
  }
  return $id;
}
function str($v, int $max): string { return mb_substr(trim((string)($v ?? '')), 0, $max); }
function iso(?string $d): ?string { return $d ? date('c', strtotime($d)) : null; }
function jdec(?string $s) { if (!$s) return null; return json_decode($s, true); }

const DEFAULT_SETTINGS = [
  'theme'   => 'light',
  'notif'   => ['inApp' => true, 'email' => true, 'match' => true, 'decisions' => true],
  'privacy' => ['hideContact' => true, 'twoStep' => false],
];
function user_settings(array $row): array {
  $s = jdec($row['settings_json'] ?? null) ?: [];
  $theme = in_array($s['theme'] ?? '', ['light', 'dark', 'device'], true) ? $s['theme'] : DEFAULT_SETTINGS['theme'];
  $merge = function (array $def, $in) { $o = []; foreach ($def as $k => $v) $o[$k] = isset($in[$k]) ? (bool)$in[$k] : $v; return $o; };
  return ['theme' => $theme, 'notif' => $merge(DEFAULT_SETTINGS['notif'], $s['notif'] ?? []), 'privacy' => $merge(DEFAULT_SETTINGS['privacy'], $s['privacy'] ?? [])];
}
/** Public URL of a file in a public Supabase Storage bucket ('items' or 'avatars'). */
function photo_url(string $file, string $folder): string { return $file ? storage_public_url($folder, $file) : ''; }
/** Item photo URL with a version number, so a changed (black & white) photo is never served from the browser cache. */
function photo_src(string $file, $ver = ''): string {
  if (!$file) return '';
  return storage_public_url('items', $file) . ($ver !== '' ? '?v=' . $ver : '');
}
function user_public(array $u): array {
  return ['id' => (string)$u['id'], 'name' => $u['full_name'], 'idNumber' => $u['id_number'], 'email' => $u['email'],
    'role' => $u['role'], 'program' => $u['program'], 'yearLevel' => $u['year_level'], 'section' => $u['section'],
    'photo' => photo_url($u['photo'], 'avatars'), 'since' => date('M Y', strtotime($u['created_at'])),
    'verified' => (bool)($u['verified'] ?? 0), 'isAdmin' => (bool)($u['is_admin'] ?? 0)];
}
function me_payload(array $u): array { return ['user' => user_public($u), 'settings' => user_settings($u)]; }
function load_user(int $id): array {
  $st = db()->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$id]);
  $u = $st->fetch();
  if (!$u) { session_destroy(); fail('Please log in.', 401); }
  return $u;
}

const IMG_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
const PROOF_TYPES = IMG_TYPES + ['application/pdf' => 'pdf'];

/** Save a base64 data URL into a Supabase Storage bucket. Returns the stored file name, or '' if nothing was sent. */
function save_data_url(?string $dataUrl, string $bucket, array $allowed, int $maxBytes): string {
  if (!$dataUrl) return '';
  if (!preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $dataUrl, $m)) fail('Invalid file upload.');
  $bin = base64_decode($m[2], true);
  if ($bin === false || $bin === '') fail('Invalid file upload.');
  if (strlen($bin) > $maxBytes) fail('File is too large (max ' . round($maxBytes / 1048576) . ' MB).');
  $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bin);      // trust the bytes, not what the browser claimed
  if (!isset($allowed[$mime])) fail('That file type is not allowed.');
  $name = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
  if (!storage_put($bucket, $name, $bin, $mime)) fail('Cannot save the file on the server.', 500);
  return $name;
}
