<?php
// Campus news for the dashboard: items recently reported LOST, recently FOUND, and recently RETURNED to their owners.
// Public information only (the same name, category, area and black & white photo that Search Items already shows). No people, no exact places.
require __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/match_lib.php';
$uid = require_login();
$pdo = db();

$row = fn(array $r, string $kind, ?string $at) => ['kind' => $kind, 'itemId' => (string)$r['id'], 'name' => $r['name'], 'category' => $r['category'], 'area' => $r['area'],
  'photo' => public_photo_src(['photo' => (string)$r['photo'], 'photo_bw' => $r['photo_bw'] ?? 0]), 'at' => iso($at), '_s' => (string)$at];

$ev = [];
foreach ($pdo->query("SELECT id, name, category, area, photo, photo_bw, created_at FROM items
                      WHERE type = 'found' ORDER BY created_at DESC, id DESC LIMIT 15")->fetchAll() as $r)
  $ev[] = $row($r, 'found', $r['created_at']);
foreach ($pdo->query("SELECT id, name, category, area, photo, photo_bw, created_at FROM items
                      WHERE type = 'lost' ORDER BY created_at DESC, id DESC LIMIT 15")->fetchAll() as $r)
  $ev[] = $row($r, 'lost', $r['created_at']);
foreach ($pdo->query("SELECT id, name, category, area, photo, photo_bw, created_at, returned_at FROM items
                      WHERE status = 'returned' ORDER BY COALESCE(returned_at, created_at) DESC, id DESC LIMIT 15")->fetchAll() as $r)
  $ev[] = $row($r, 'returned', $r['returned_at'] ?: $r['created_at']);

usort($ev, fn($a, $b) => strcmp($b['_s'], $a['_s']));
$ev = array_slice($ev, 0, 15);
foreach ($ev as &$e) unset($e['_s']);
unset($e);
out($ev);
