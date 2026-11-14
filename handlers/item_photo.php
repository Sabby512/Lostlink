<?php
// The colour ORIGINAL of an item photo. Only the person who reported the item and admins can open it.
// Everyone else only ever gets the black & white copy in the public "items" bucket (see lib/photo_lib.php).
define('RAW_OUTPUT', true);
require __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/photo_lib.php';
$uid = require_login();
$st = db()->prepare('SELECT user_id, photo FROM items WHERE id = ?'); $st->execute([(int)($_GET['id'] ?? 0)]);
$it = $st->fetch();
$me = load_user($uid);
if (!$it || !$it['photo'] || ((int)$it['user_id'] !== $uid && empty($me['is_admin']))) { http_response_code(404); exit('Not found.'); }
$name = basename($it['photo']);
$f = storage_get('originals', $name) ?: storage_get('items', $name);
if (!$f) { http_response_code(404); exit('Not found.'); }
serve_bytes($f['data'], 'private, max-age=3600');
