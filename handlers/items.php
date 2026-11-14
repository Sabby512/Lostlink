<?php
require __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/match_lib.php';
$uid = require_login();
$pdo = db();

/** Everyone gets the public photo (black & white when it was converted). The reporter and admins get the colour original. */
function item_photo(array $r, bool $original): string {
  if ($original && !empty($r['photo_bw']) && $r['photo']) return 'api/item_photo.php?id=' . $r['id'] . '&v=' . substr(md5($r['photo']), 0, 8);
  return $original ? photo_src($r['photo']) : public_photo_src($r);
}

/** Everyone sees the public part. Only the owner sees exact location, private notes, owner details, etc. */
function item_json(array $r, int $uid, bool $full = false): array {
  $mine = (int)$r['user_id'] === $uid;
  $o = ['id' => (string)$r['id'], 'type' => $r['type'], 'status' => $r['status'], 'name' => $r['name'],
        'category' => $r['category'], 'area' => $r['area'], 'photo' => item_photo($r, $mine || $full),
        'createdAt' => iso($r['created_at']), 'mine' => $mine, 'tags' => item_tags($r)];
  if ($mine || $full) {          // $full = the viewer is an admin (single-item lookup only)
    $o += ['location' => $r['location'], 'date' => $r['item_date'], 'lostAt' => $r['lost_at'], 'description' => $r['description'],
           'privateDetails' => $r['private_details'], 'details' => jdec($r['details_json']), 'owner' => jdec($r['owner_json']),
           'proof' => jdec($r['proof_json']), 'source' => $r['source'], 'ticket' => $r['ticket'],
           'linkAccount' => (bool)$r['link_account'], 'verified' => (bool)$r['verified'], 'matchedAt' => iso($r['matched_at'])];
  }
  return $o;
}
function find_item(int $id, int $uid, bool $full = false): ?array {
  $st = db()->prepare('SELECT * FROM items WHERE id = ?'); $st->execute([$id]);
  $r = $st->fetch();
  if (!$r) return null;
  $o = item_json($r, $uid, $full);
  if (!empty($o['mine']) || $full) {          // only the reporter and admins get the reporter's name
    $s = db()->prepare('SELECT full_name FROM users WHERE id = ?'); $s->execute([$r['user_id']]);
    $o['reportedBy'] = (string)$s->fetchColumn();
  }
  return $o;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (isset($_GET['id'])) {
    $it = find_item((int)$_GET['id'], $uid, !empty(load_user($uid)['is_admin']));
    $it ? out($it) : fail('Item not found.', 404);
  }
  $rows = $pdo->query('SELECT * FROM items ORDER BY created_at DESC, id DESC LIMIT 500')->fetchAll();
  out(array_map(fn($r) => item_json($r, $uid), $rows));
}

$in = body();
$action = $_GET['action'] ?? 'add';

if ($action === 'ticket') {
  $y = (int)date('Y');
  $tk = $pdo->prepare('INSERT INTO walkin_tickets (year) VALUES (?) RETURNING id'); $tk->execute([$y]);
  $n = (int)$tk->fetchColumn();              // identity column: unique even if two staff submit at once
  out(['ticket' => sprintf('UC-%03d', $n)]);
}

if ($action === 'add') {
  require_once __DIR__ . '/../lib/photo_lib.php';
  require_once __DIR__ . '/../lib/match_lib.php';
  $type = $in['type'] ?? '';
  if (!in_array($type, ['lost', 'found'], true)) fail('Invalid report type.');
  $name = str($in['name'] ?? '', 160); $cat = str($in['category'] ?? '', 80);
  $area = str($in['area'] ?? '', 80) ?: 'Others';
  $loc  = str($in['location'] ?? '', 200) ?: $area;          // a walk-in report's "Exact spot" is optional
  if ($name === '' || $cat === '') fail('Please fill in every field marked with *.');
  $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['date'] ?? '')) ? $in['date'] : date('Y-m-d');

  // The browser (db.js) sends 'photo' = black & white copy (public) and 'photoOriginal' = colour copy (private).
  // If admin Settings has "Black & white photos" switched off, the colour copy becomes the public one.
  $photoData = (string)($in['photo'] ?? '');
  $origData  = (string)($in['photoOriginal'] ?? '');
  if ($origData !== '' && empty(get_settings($pdo)['photoBwDefault'])) { $photoData = $origData; $origData = ''; }
  $photo = save_data_url($photoData, 'items', IMG_TYPES, 5 * 1024 * 1024);

  $json = function ($v) { $s = $v ? json_encode($v, JSON_UNESCAPED_UNICODE) : null; return ($s && strlen($s) <= 20000) ? $s : null; };
  $source = ($in['source'] ?? '') === 'walk-in' ? 'walk-in' : 'online';
  $ticket = null;
  if ($source === 'walk-in') {                                // the server hands out the number, never the browser
    $y = (int)date('Y');
    $tk = $pdo->prepare('INSERT INTO walkin_tickets (year) VALUES (?) RETURNING id'); $tk->execute([$y]);
    $ticket = sprintf('UC-%03d', (int)$tk->fetchColumn());
  }

  $ins = $pdo->prepare('INSERT INTO items (user_id,type,name,category,area,location,item_date,lost_at,description,private_details,details_json,owner_json,proof_json,photo,source,ticket,link_account)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
  $ins->execute([$uid, $type, $name, $cat, $area, $loc, $date, str($in['lostAt'] ?? '', 40) ?: null,
                 str($in['description'] ?? '', 2000), str($in['privateDetails'] ?? '', 2000),
                 $json($in['details'] ?? null), $json($in['owner'] ?? null), $json($in['proof'] ?? null),
                 $photo, $source, $ticket, !empty($in['linkAccount']) ? 1 : 0]);
  $newId = (int)$ins->fetchColumn();

  if ($photo !== '') {
    if ($origData !== '' && store_original_upload($origData, $photo)) {
      // Public file is the browser-made black & white copy, the colour original is in uploads/originals (private)
      $pdo->prepare('UPDATE items SET photo_bw = 1 WHERE id = ?')->execute([$newId]);
    } else {
      // No colour original came with it (old page / older browser): try the server-side conversion.
      // This only logs an error if GD is off, it never crashes the request.
      apply_default_bw($pdo, $newId);
      $ok = $pdo->prepare('SELECT photo_bw FROM items WHERE id = ?'); $ok->execute([$newId]);
      if (!empty(get_settings($pdo)['photoBwDefault']) && !(int)$ok->fetchColumn()) {
        // never leave a colour photo public: undo this upload instead
        $pdo->prepare('DELETE FROM items WHERE id = ?')->execute([$newId]);
        storage_delete('items', $photo); storage_delete('originals', $photo);
        fail('Could not make the photo black & white, so it was not posted. Please try another photo.', 500);
      }
    }
  }
  out(find_item($newId, $uid), 201);
}

if ($action === 'delete') {
  // A user can delete their OWN report, as long as nobody has claimed it and it was not returned yet.
  $id = (int)($in['id'] ?? 0);
  $st = $pdo->prepare('SELECT * FROM items WHERE id = ?'); $st->execute([$id]);
  $it = $st->fetch();
  if (!$it) fail('Report not found.', 404);
  if ((int)$it['user_id'] !== $uid) fail('You can only delete your own reports.', 403);
  if ($it['status'] === 'returned') fail('This item was already returned, so the report stays on record.', 409);
  $n = $pdo->prepare('SELECT COUNT(*) FROM claims WHERE item_id = ?'); $n->execute([$id]);
  if ((int)$n->fetchColumn() > 0) fail('This report already has a claim on it, so it can’t be deleted. Contact the Admin office if it is a mistake.', 409);

  require_once __DIR__ . '/../lib/match_lib.php';
  require_once __DIR__ . '/../lib/photo_lib.php';
  ensure_match_table($pdo);
  $pdo->beginTransaction();
  // If the admin had confirmed a match with this report, the other item goes back to "available" (unless someone else has a live claim on it)
  $st = $pdo->prepare("SELECT lost_id, found_id FROM match_decisions WHERE (lost_id = ? OR found_id = ?) AND decision = 'confirmed'");
  $st->execute([$id, $id]);
  foreach ($st->fetchAll() as $d) {
    $other = (int)$d['lost_id'] === $id ? (int)$d['found_id'] : (int)$d['lost_id'];
    $busy = $pdo->prepare("SELECT COUNT(*) FROM claims WHERE item_id = ? AND status IN ('pending','approved')"); $busy->execute([$other]);
    if ((int)$busy->fetchColumn() === 0)
      $pdo->prepare("UPDATE items SET status = 'available', matched_at = NULL WHERE id = ? AND status = 'matched'")->execute([$other]);
  }
  $pdo->prepare('DELETE FROM match_decisions WHERE lost_id = ? OR found_id = ?')->execute([$id, $id]);
  $pdo->prepare('DELETE FROM items WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
  $pdo->commit();

  $name = basename((string)$it['photo']);                 // remove the photo files too (public copy + admin-only original)
  if ($name !== '') { storage_delete('items', $name); storage_delete('originals', $name); }
  out(['ok' => true]);
}

fail('Unknown action.', 404);
