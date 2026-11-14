<?php
// Match Results: lost reports vs available found items, ranked by score. Admins only.
// High (85+) can be confirmed: both items become "matched" so the student can claim the found item. Anything can be discarded.
require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/match_lib.php';
$uid = require_login();
$me = load_user($uid);
if (!$me['is_admin']) fail('Admins only.', 403);
$pdo = db();
ensure_match_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') out(build_matches($pdo));

$in = body();
$action = $_GET['action'] ?? '';
$lostId = (int)($in['lostId'] ?? 0); $foundId = (int)($in['foundId'] ?? 0);
if (!$lostId || !$foundId) fail('Missing item.');

if ($action === 'discard') {
  $pdo->prepare("INSERT INTO match_decisions (lost_id, found_id, decision, decided_by) VALUES (?, ?, 'discarded', ?) ON CONFLICT (lost_id, found_id) DO UPDATE SET decision = EXCLUDED.decision, decided_by = EXCLUDED.decided_by, decided_at = NOW()")->execute([$lostId, $foundId, $uid]);
  out(build_matches($pdo));
}

if ($action === 'confirm') {
  $get = function (int $id, string $type) use ($pdo) { $st = $pdo->prepare('SELECT * FROM items WHERE id = ? AND type = ?'); $st->execute([$id, $type]); return $st->fetch(); };
  $l = $get($lostId, 'lost'); $f = $get($foundId, 'found');
  if (!$l || !$f) fail('Item not found.', 404);
  if ($f['status'] !== 'available' || in_array($l['status'], ['returned', 'matched'], true)) fail('One of these items is no longer available to match.', 409);
  $cfg = get_settings($pdo);
  $sc = score_pair($f, $l, !empty($cfg['zoneMatching']));      // re-scored here, never trusted from the browser
  if ($sc['score'] < max(MATCH_HIGH, (int)$cfg['confirmMin'])) fail('Only High scores (' . MATCH_HIGH . ' and above) can be confirmed.', 409);
  $pdo->beginTransaction();
  $pdo->prepare("UPDATE items SET status = 'matched', matched_at = NOW() WHERE id IN (?, ?)")->execute([$lostId, $foundId]);
  $pdo->prepare("INSERT INTO match_decisions (lost_id, found_id, decision, decided_by) VALUES (?, ?, 'confirmed', ?) ON CONFLICT (lost_id, found_id) DO UPDATE SET decision = EXCLUDED.decision, decided_by = EXCLUDED.decided_by, decided_at = NOW()")->execute([$lostId, $foundId, $uid]);
  $pdo->commit();
  // email the student too (only if admin "Notify student" and their own Email + Match alerts are on)
  email_student($pdo, (int)$l['user_id'], 'match', 'Possible match for your lost report',
    "Good news! Your lost report \"{$l['name']}\" may match an item that was turned in.\nLog in to LostLink, open My Claims, review the item and submit your claim.");
  out(build_matches($pdo));
}
fail('Unknown action.', 404);
