<?php
// Manage Items: list lost and found items, edit them, remove them, and control photo privacy. Admins only.
//
// PHOTO PRIVACY (black & white for users, original for admins), all in Supabase Storage:
//   bucket "items"      public   what every page shows (greyscale while "black & white" is on)
//   bucket "originals"  private  the colour original, served only through this file (?action=photo&id=ID) and api/item_photo.php
require __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/photo_lib.php';
$uid = require_login();
$me = load_user($uid);
if (!$me['is_admin']) fail('Admins only.', 403);
$pdo = db();

function item_row(array $r): array {
  $status = $r['status'] === 'returned' ? 'returned' : ($r['claimed'] ? 'claimed' : $r['status']);
  $bw = (int)($r['photo_bw'] ?? 0) === 1;
  $v = $r['photo'] ? '&v=' . (int)$bw : '';                 // changes when the B&W switch changes, so browsers do not show an old copy
  return ['id' => (string)$r['id'], 'type' => $r['type'], 'createdAt' => iso($r['created_at']), 'name' => $r['name'], 'category' => $r['category'], 'location' => $r['location'], 'date' => $r['item_date'], 'lostAt' => $r['lost_at'],
          'description' => (string)$r['description'], 'reportedBy' => (string)$r['reporter'], 'status' => $status,
          'photo' => $r['photo'] ? photo_src($r['photo'], (int)$bw) : null,
          'photoBw' => $bw,
          'source' => $r['source'], 'ticket' => $r['ticket'], 'verified' => (bool)$r['verified'],
          'owner' => jdec($r['owner_json']) ?: null, 'proof' => jdec($r['proof_json']) ?: null,
          'originalPhoto' => $r['photo'] ? 'api/admin_items.php?action=photo&id=' . $r['id'] . $v : null];
}
const ITEM_SELECT = "SELECT i.*, u.full_name AS reporter,
    EXISTS(SELECT 1 FROM claims c WHERE c.item_id = i.id AND c.status IN ('pending','approved')) AS claimed
  FROM items i LEFT JOIN users u ON u.id = i.user_id";
function load_item(int $id): array {
  $st = db()->prepare(ITEM_SELECT . " WHERE i.id = ?"); $st->execute([$id]);
  $r = $st->fetch();
  if (!$r) fail('Item not found.', 404);
  return $r;
}

$action = $_GET['action'] ?? '';

/* ---------- admin-only original photo ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'photo') {
  $it = load_item((int)($_GET['id'] ?? 0));
  $name = basename((string)$it['photo']);
  $f = $name === '' ? null : (storage_get('originals', $name) ?: storage_get('items', $name));
  if (!$f) fail('No photo.', 404);
  serve_bytes($f['data']);
}

/* ---------- old colour photos waiting for a black & white copy (the admin's browser makes it, see db.js) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'bw_pending') {
  if (empty(get_settings($pdo)['photoBwDefault'])) out([]);
  $rows = $pdo->query("SELECT id FROM items WHERE photo <> '' AND photo_bw = 0 LIMIT 10")->fetchAll();
  out(array_map(fn($r) => ['id' => (string)$r['id'], 'url' => 'api/admin_items.php?action=photo&id=' . $r['id']], $rows));
}

/* ---------- list ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $rows = $pdo->query(ITEM_SELECT . " ORDER BY i.created_at DESC, i.id DESC LIMIT 2000")->fetchAll();
  out(array_map('item_row', $rows));
}

/* ---------- edit / remove ---------- */
$in = body();
$it = load_item((int)($in['id'] ?? 0));

if ($action === 'bw_upload') {
  if (!$it['photo'] || (int)$it['photo_bw'] === 1) out(['ok' => true]);
  if (!preg_match('#^data:image/(?:jpeg|png|webp);base64,(.+)$#s', (string)($in['bw'] ?? ''), $m)) fail('Invalid photo.');
  $bin = base64_decode($m[1], true);
  if ($bin === false || $bin === '' || strlen($bin) > 5 * 1024 * 1024 || !isset(IMG_TYPES[(new finfo(FILEINFO_MIME_TYPE))->buffer($bin)])) fail('Invalid photo.');
  $name = basename((string)$it['photo']);
  $pub = storage_get('items', $name);
  if (!$pub) fail('The photo file is missing.', 404);
  if (!storage_get('originals', $name) && !storage_put('originals', $name, $pub['data'], (new finfo(FILEINFO_MIME_TYPE))->buffer($pub['data']))) fail('Could not store the original photo.', 500);
  if (!storage_put('items', $name, $bin, (new finfo(FILEINFO_MIME_TYPE))->buffer($bin))) fail('Could not save the photo.', 500);
  $pdo->prepare('UPDATE items SET photo_bw = 1 WHERE id = ?')->execute([$it['id']]);
  out(['ok' => true]);
}

if ($action === 'update') {
  $name = str($in['name'] ?? '', 120);
  if ($name === '') fail('Item name is required.');
  $date = (string)($in['date'] ?? '');
  if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Please enter a valid date.');
  $lostAt = $it['lost_at'];                                    // time lost (HH:MM), lost reports only; blank clears it
  if ($it['type'] === 'lost' && array_key_exists('lostAt', $in)) {
    $t = trim((string)$in['lostAt']);
    if ($t !== '' && !preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $t)) fail('Please enter a valid time.');
    $lostAt = $t === '' ? null : $t;
  }
  $pdo->prepare('UPDATE items SET name = ?, category = ?, location = ?, item_date = ?, lost_at = ?, description = ? WHERE id = ?')
      ->execute([$name, str($in['category'] ?? '', 60), str($in['location'] ?? '', 120), $date ?: $it['item_date'], $lostAt, str($in['description'] ?? '', 500), $it['id']]);

  if ($it['photo'] && array_key_exists('photoBw', $in)) {
    $on = filter_var($in['photoBw'], FILTER_VALIDATE_BOOLEAN);
    try { set_photo_bw($it, $on); } catch (RuntimeException $e) { fail($e->getMessage(), $e->getCode() ?: 500); }
    $pdo->prepare('UPDATE items SET photo_bw = ? WHERE id = ?')->execute([$on ? 1 : 0, $it['id']]);
  }
  // Walk-in reports: staff tick this after checking the student's ID at the desk
  if ($it['source'] === 'walk-in' && array_key_exists('verified', $in))
    $pdo->prepare('UPDATE items SET verified = ? WHERE id = ?')->execute([filter_var($in['verified'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0, $it['id']]);
  out(item_row(load_item((int)$it['id'])));
}

if ($action === 'delete') {
  $n = $pdo->prepare('SELECT COUNT(*) FROM claims WHERE item_id = ?'); $n->execute([$it['id']]);
  if ((int)$n->fetchColumn() > 0) fail('This item has claims on it, so it can’t be removed. Reject the claims or mark it returned instead.', 409);
  $name = basename((string)$it['photo']);
  $pdo->prepare('DELETE FROM items WHERE id = ?')->execute([$it['id']]);
  if ($name !== '') { storage_delete('items', $name); storage_delete('originals', $name); }
  out(['ok' => true]);
}

fail('Unknown action.', 404);
