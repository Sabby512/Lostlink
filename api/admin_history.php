<?php
// History: every claim, lost report and found report in one list, newest first. Admins only.
require __DIR__ . '/../lib/config.php';
$uid = require_login();
$me = load_user($uid);
if (!$me['is_admin']) fail('Admins only.', 403);
$pdo = db();

$rows = [];

// lost + found reports
foreach ($pdo->query("SELECT i.id, i.type, i.name, i.status, i.created_at, i.returned_at, u.full_name AS person
                      FROM items i LEFT JOIN users u ON u.id = i.user_id
                      ORDER BY i.created_at DESC, i.id DESC LIMIT 1000")->fetchAll() as $r) {
  $rows[] = ['kind' => $r['type'], 'code' => ($r['type'] === 'found' ? 'F-' : 'L-') . $r['id'], 'item' => $r['name'], 'person' => (string)$r['person'],
             'status' => $r['status'], 'createdAt' => iso($r['created_at']), 'updatedAt' => iso($r['returned_at']), '_s' => $r['created_at']];
}

// claims (updatedAt = when an admin decided it)
foreach ($pdo->query("SELECT c.claim_code, c.status, c.created_at, c.decided_at, i.name AS item_name, u.full_name AS claimant
                      FROM claims c JOIN items i ON i.id = c.item_id JOIN users u ON u.id = c.user_id
                      ORDER BY c.created_at DESC, c.id DESC LIMIT 1000")->fetchAll() as $c) {
  $rows[] = ['kind' => 'claim', 'code' => $c['claim_code'], 'item' => $c['item_name'], 'person' => $c['claimant'],
             'status' => $c['status'], 'createdAt' => iso($c['created_at']), 'updatedAt' => iso($c['decided_at']), '_s' => $c['created_at']];
}

usort($rows, fn($a, $b) => strcmp((string)$b['_s'], (string)$a['_s']));
foreach ($rows as &$r) unset($r['_s']);
unset($r);
out(array_slice($rows, 0, 2000));
