<?php
require __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/match_lib.php';
$uid = require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $in = body();
  $ids = array_slice(array_filter((array)($in['ids'] ?? []), 'is_string'), 0, 200);
  $ins = $pdo->prepare('INSERT INTO notif_reads (user_id, notif_key) VALUES (?, ?) ON CONFLICT DO NOTHING');
  foreach ($ids as $k) $ins->execute([$uid, mb_substr($k, 0, 80)]);
  out(['ok' => true]);
}

/* Notifications only exist AFTER an admin has verified something:
   - one of the user's lost reports becomes 'matched'
   - one of the user's claims becomes 'approved' or 'rejected'
   Submitting a report or a claim creates nothing, so a new account starts at 0. */
ensure_match_table($pdo);
$pref = user_settings(load_user($uid));
$out = [];

$st = $pdo->prepare("SELECT i.id, i.name, i.matched_at, i.created_at,
    (SELECT d.found_id FROM match_decisions d WHERE d.lost_id = i.id AND d.decision = 'confirmed' LIMIT 1) AS found_id
    FROM items i WHERE i.user_id = ? AND i.type = 'lost' AND i.status = 'matched'");
$st->execute([$uid]);
foreach ($st->fetchAll() as $i) {
  if (!$pref['notif']['match']) break;
  $out[] = ['id' => 'match-' . $i['id'], 'type' => 'match', 'title' => 'Possible match for your lost report', 'detail' => $i['name'],
            'at' => iso($i['matched_at'] ?: $i['created_at']),
            'link' => $i['found_id'] ? 'item.html?id=' . $i['found_id'] : 'my-claims.html'];   // opens the matched found item
}

// A found item that looks like one of this user's lost reports shows up right away, no admin step needed
if ($pref['notif']['match'])
  foreach (possible_matches_for($pdo, $uid, 10) as $m)
    $out[] = ['id' => 'possible-' . $m['lostId'] . '-' . $m['foundId'], 'type' => 'match', 'title' => 'A found item may match your lost report',
              'detail' => $m['name'] . ' was reported found in the ' . $m['area'] . ' area (your report: ' . $m['lostName'] . ')',
              'at' => $m['createdAt'], 'link' => 'item.html?id=' . $m['foundId']];

$st = $pdo->prepare("SELECT c.*, i.name AS item_name FROM claims c JOIN items i ON i.id = c.item_id WHERE c.user_id = ? AND c.status IN ('approved','rejected')");
$st->execute([$uid]);
foreach ($st->fetchAll() as $c) {
  if (!$pref['notif']['decisions']) break;
  $at = iso($c['decided_at'] ?: $c['created_at']);
  if ($c['status'] === 'approved')
    $out[] = ['id' => 'claim-' . $c['claim_code'] . '-approved', 'type' => 'approved', 'title' => 'Claim approved: ' . $c['item_name'],
              'detail' => 'Ready for pickup', 'at' => $at, 'link' => 'pickup.html?claim=' . rawurlencode($c['claim_code'])];
  else
    $out[] = ['id' => 'claim-' . $c['claim_code'] . '-rejected', 'type' => 'rejected', 'title' => 'Claim rejected: ' . $c['item_name'],
              'detail' => $c['admin_note'] ?: 'Ownership not verified', 'at' => $at, 'link' => 'my-claims.html?claim=' . rawurlencode($c['claim_code'])];
}

$st = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ?'); $st->execute([$uid]);
foreach ($st->fetchAll() as $n)
  $out[] = ['id' => 'n-' . $n['id'], 'type' => $n['type'], 'title' => $n['title'], 'detail' => $n['detail'], 'at' => iso($n['created_at']), 'link' => $n['link']];

$st = $pdo->prepare('SELECT notif_key FROM notif_reads WHERE user_id = ?'); $st->execute([$uid]);
$seen = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
foreach ($out as &$n) $n['read'] = isset($seen[$n['id']]);
unset($n);
usort($out, fn($a, $b) => strcmp($b['at'] ?? '', $a['at'] ?? ''));
out(['inApp' => $pref['notif']['inApp'], 'list' => $out]);
