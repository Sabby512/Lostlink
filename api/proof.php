<?php
// Private proof-of-ownership files: only the person who uploaded the claim (or an admin) can open them.
define('RAW_OUTPUT', true);
require __DIR__ . '/../lib/config.php';
$uid = current_user_id();
if (!$uid) { http_response_code(401); exit('Please log in.'); }
$st = db()->prepare('SELECT c.proof_file, c.proof_name, c.user_id FROM claims c WHERE c.claim_code = ?');
$st->execute([$_GET['claim'] ?? '']);
$c = $st->fetch();
$me = load_user($uid);
if (!$c || !$c['proof_file'] || ((int)$c['user_id'] !== $uid && !$me['is_admin'])) { http_response_code(404); exit('Not found.'); }
$f = storage_get('proofs', basename($c['proof_file']));
if (!$f) { http_response_code(404); exit('Not found.'); }
serve_bytes($f['data'], 'private, max-age=0', $c['proof_name'] ?: 'proof');
