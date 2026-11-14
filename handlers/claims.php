<?php
require __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/match_lib.php';
$uid = require_login();
$pdo = db();

/** Match score shown to the claimant ONLY after an admin approved the claim. Score, level and per-topic percentages only:
 *  the item's private details and the comparison text are never sent to the student. */
function claim_match(array $r): ?array {
  if (($r['status'] ?? '') !== 'approved') return null;
  $pdo = db();
  $st = $pdo->prepare('SELECT * FROM items WHERE id = ?'); $st->execute([$r['item_id']]);
  $item = $st->fetch(); if (!$item) return null;
  [$sc] = claim_scored($pdo, $r, $item);
  return ['score' => $sc['score'], 'level' => $sc['level'],
          'parts' => array_map(fn($p) => ['label' => $p['label'], 'points' => $p['points'], 'max' => $p['max'],
                                          'pct' => (int)round($p['points'] / max(1, $p['max']) * 100)], $sc['parts'])];
}

function claim_json(array $r): array {
  return ['id' => (string)$r['id'], 'claimId' => $r['claim_code'], 'itemId' => (string)$r['item_id'], 'status' => $r['status'],
          'proof' => $r['proof'], 'confirmed' => (bool)$r['confirmed'],
          'proofFile' => $r['proof_file'] ? ['name' => $r['proof_name'], 'url' => 'api/proof.php?claim=' . rawurlencode($r['claim_code'])] : null,
          'adminNote' => $r['admin_note'], 'pickup' => jdec($r['pickup_json']),
          'match' => claim_match($r),
          'createdAt' => iso($r['created_at']), 'decidedAt' => iso($r['decided_at']),
          'item' => isset($r['item_name']) ? ['name' => $r['item_name'], 'category' => $r['item_category'], 'area' => $r['item_area'], 'type' => $r['item_type'],
                                               'status' => $r['item_status'], 'photo' => public_photo_src(['photo' => (string)$r['item_photo'], 'photo_bw' => $r['item_photo_bw'] ?? 0])] : null];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $st = $pdo->prepare('SELECT c.*, i.name AS item_name, i.category AS item_category, i.area AS item_area, i.photo AS item_photo, i.type AS item_type, i.status AS item_status, i.photo_bw AS item_photo_bw
                       FROM claims c JOIN items i ON i.id = c.item_id WHERE c.user_id = ? ORDER BY c.created_at DESC, c.id DESC');
  $st->execute([$uid]);
  out(array_map('claim_json', $st->fetchAll()));
}

$in = body();
$itemId = (int)($in['itemId'] ?? 0);
$proof = str($in['proof'] ?? '', 3000);
$cfg = get_settings($pdo);                                    // admin Settings: require proof / allow files
if ($proof === '' && !empty($cfg['requireProof'])) fail('Please describe your proof of ownership.');
if (empty($in['confirmed'])) fail('Please confirm this item belongs to you.');
$cName = str($in['itemName'] ?? '', 160);
if ($cName === '') fail('Please enter the item name.');
$cCat = str($in['category'] ?? '', 80);
if ($cCat === '') fail('Please choose the item category.');
$cColor = str($in['color'] ?? '', 60);
if ($cColor === '') fail('Please enter the item color.');
$cArea = str($in['area'] ?? '', 80);
if ($cArea === '') fail('Please choose the general area.');
$cLoc = str($in['location'] ?? '', 200);
if ($cLoc === '') fail('Please enter the exact last seen location.');
// Date/time lost: the form sends 2026-11-13T14:30 (or just a date from an older page)
$cDate = null; $rawDate = (string)($in['date'] ?? '');
if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ]([01]\d|2[0-3]):([0-5]\d))?$/', $rawDate, $dm)) $cDate = $dm[1] . ' ' . (isset($dm[2]) ? $dm[2] . ':' . $dm[3] : '00:00') . ':00';
if ($cDate === null || !isset($dm[2])) fail('Please enter the date and time you lost it.');

$st = $pdo->prepare('SELECT user_id, status FROM items WHERE id = ?'); $st->execute([$itemId]);
$item = $st->fetch();
if (!$item) fail('Item not found.', 404);
if ((int)$item['user_id'] === $uid) fail('You cannot claim an item you reported yourself.', 403);
if ($item['status'] === 'returned') fail('This item has already been returned.', 409);
$won = $pdo->prepare("SELECT COUNT(*) FROM claims WHERE item_id = ? AND status = 'approved'"); $won->execute([$itemId]);
if ((int)$won->fetchColumn() > 0) fail('This item has already been claimed by its owner.', 409);
if (in_array($item['status'], ['matched', 'pending'], true)) {                         // a confirmed match is reserved for the person it was matched with
  ensure_match_table($pdo);
  $mine = $pdo->prepare("SELECT COUNT(*) FROM match_decisions d JOIN items l ON l.id = d.lost_id WHERE d.found_id = ? AND d.decision = 'confirmed' AND l.user_id = ?");
  $mine->execute([$itemId, $uid]);
  $any = $pdo->prepare("SELECT COUNT(*) FROM match_decisions WHERE found_id = ? AND decision = 'confirmed'"); $any->execute([$itemId]);
  if ((int)$any->fetchColumn() > 0 && (int)$mine->fetchColumn() === 0) fail('This item is reserved for the person it was matched with.', 409);
}

$dup = $pdo->prepare('SELECT COUNT(*) FROM claims WHERE item_id = ? AND user_id = ?'); $dup->execute([$itemId, $uid]);
if ($dup->fetchColumn() > 0) fail('You already claimed this item.', 409);

// Proof file (image or PDF, max 5 MB) goes to a private folder, never to a public URL
$file = ''; $fileName = '';
$pf = $in['proofFile'] ?? null;
if (is_array($pf) && !empty($pf['data']) && empty($cfg['allowProofFile'])) fail('Proof photos and files are turned off right now.', 403);
if (is_array($pf) && !empty($pf['data'])) {
  $file = save_data_url($pf['data'], 'proofs', PROOF_TYPES, 5 * 1024 * 1024);
  $fileName = str($pf['name'] ?? '', 200);
}

$code = 'CL-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
try {
  $ins = $pdo->prepare('INSERT INTO claims (claim_code,item_id,user_id,proof,proof_name,proof_file,confirmed,claim_name,claim_category,claim_color,claim_area,claim_location,claim_date) VALUES (?,?,?,?,?,?,1,?,?,?,?,?,?) RETURNING id');
  $ins->execute([$code, $itemId, $uid, $proof, $fileName, $file, $cName, $cCat, $cColor, $cArea, $cLoc, $cDate]);
  $newClaimId = (int)$ins->fetchColumn();
} catch (PDOException $e) {                                   // a double-click that slipped past the check above
  if (str_starts_with((string)$e->getCode(), '23')) fail('You already claimed this item.', 409);
  throw $e;
}
refresh_item_status($pdo, $itemId);      // the item now shows as Pending in Search
matches_cache_clear();
$st = $pdo->prepare('SELECT * FROM claims WHERE id = ?'); $st->execute([$newClaimId]);
out(claim_json($st->fetch()), 201);
