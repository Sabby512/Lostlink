<?php
// ONE serverless function for the whole backend (Vercel's free Hobby plan allows only 12 functions, LostLink has 16 endpoints).
// vercel.json rewrites /api/<name>.php to /api/index.php?_f=<name>, so the pages keep calling api/auth.php, api/items.php, ...
$allowed = [
  'admin',
  'admin_claims',
  'admin_history',
  'admin_items',
  'admin_match',
  'admin_settings',
  'admin_users',
  'auth',
  'claims',
  'item_photo',
  'items',
  'news',
  'notifications',
  'possible_matches',
  'profile',
  'proof'
];
$f = (string)($_GET['_f'] ?? '');
if (!in_array($f, $allowed, true)) {
  http_response_code(404); header('Content-Type: application/json; charset=utf-8'); echo '{"error":"Not found."}'; exit;
}
unset($_GET['_f']);
require __DIR__ . '/../handlers/' . $f . '.php';
