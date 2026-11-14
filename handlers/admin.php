<?php
// Admin dashboard numbers + top matches. Answers 200 for admins, 403 for everyone else (login.html / db.js rely on that).
require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/match_lib.php';
$uid = require_login();
$me = load_user($uid);
if (!$me['is_admin']) fail('Admins only.', 403);
$pdo = db();
ensure_match_table($pdo);

$n = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();

$cm = cached_matches($pdo);          // lost reports + pending claims, same rules as Match Results (reused for up to 60 s)
$matches = array_map(fn($r) => ['lost' => $r['lostName'], 'found' => $r['foundName'], 'score' => $r['score'], 'level' => $r['level']], $cm['rows']);
$pendingMatches = count($matches);

out([
  'admin' => ['name' => $me['full_name'], 'email' => $me['email']],
  'stats' => [
    'users'             => $n("SELECT COUNT(*) FROM users"),
    'pendingClaims'     => $n("SELECT COUNT(*) FROM claims WHERE status = 'pending'"),
    'claimsToday'       => $n("SELECT COUNT(*) FROM claims WHERE created_at::date = CURRENT_DATE"),
    'availableItems'    => $n("SELECT COUNT(*) FROM items WHERE type = 'found' AND status = 'available'"),
    'itemsThisWeek'     => $n("SELECT COUNT(*) FROM items WHERE type = 'found' AND created_at >= (NOW() - INTERVAL '7 days')"),
    'matchedItems'      => $n("SELECT COUNT(*) FROM items WHERE type = 'found' AND status = 'matched'"),
    'returnedThisMonth' => $n("SELECT COUNT(*) FROM items WHERE type = 'found' AND status = 'returned' AND returned_at >= date_trunc('month', NOW())"),
    'returnedLastMonth' => $n("SELECT COUNT(*) FROM items WHERE type = 'found' AND status = 'returned' AND returned_at >= date_trunc('month', NOW() - INTERVAL '1 month') AND returned_at < date_trunc('month', NOW())"),
    'pendingMatches'    => $pendingMatches,
    'claimedItems'      => $n("SELECT COUNT(*) FROM items WHERE status = 'pending'"),
    'returnedItems'     => $n("SELECT COUNT(*) FROM items WHERE type = 'found' AND status = 'returned'"),
  ],
  'matches' => array_slice($matches, 0, 5),
]);
