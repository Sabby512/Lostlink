<?php
// Manage Claims: list, review detail (item + match score + proof), approve / reject, mark returned. Admins only.
require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/match_lib.php';
$uid = require_login();
$me = load_user($uid);
if (!$me['is_admin']) fail('Admins only.', 403);
$pdo = db();
ensure_match_table($pdo);
get_settings($pdo);      // creates app_settings now, before any transaction starts

/* ---------- helpers ---------- */
/** A claim that is still "pending" on an item that has already been returned can never be approved.
 *  It is closed here with a clear reason (the student sees it as rejected). Runs whenever Manage Claims loads, and right after any return. */
function close_stale_claims(PDO $pdo, int $by): int {
  $st = $pdo->prepare("UPDATE claims c
                       SET status = 'rejected', admin_note = 'This item was already returned to its owner.', decided_at = NOW(), decided_by = ?
                       FROM items i
                       WHERE i.id = c.item_id AND c.status = 'pending' AND i.status = 'returned'");
  $st->execute([$by ?: null]);
  return $st->rowCount();
}
function proof_kind(array $c): string {
  if (!$c['proof_file']) return 'Details only';
  return str_ends_with($c['proof_file'], '.pdf') ? 'Document + details' : 'Photo + details';
}

function item_card(array $r): array {
  return ['id' => (string)$r['id'], 'type' => $r['type'], 'status' => $r['status'], 'name' => $r['name'], 'category' => $r['category'],
          'area' => $r['area'], 'location' => $r['location'], 'date' => $r['item_date'], 'lostAt' => clock_of($r['lost_at'] ?? null), 'color' => item_color($r), 'description' => $r['description'],
          'photo' => !$r['photo'] ? photo_url($r['photo'], 'items') : (!empty($r['photo_bw']) ? 'api/admin_items.php?action=photo&id=' . $r['id'] : photo_url($r['photo'], 'items')), 'createdAt' => iso($r['created_at'])];
}

const CLAIM_SELECT = 'SELECT c.*, i.name AS item_name, i.status AS item_status, u.full_name AS claimant
  FROM claims c JOIN items i ON i.id = c.item_id JOIN users u ON u.id = c.user_id';

/* ---------- list ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && close_stale_claims($pdo, $uid) > 0) matches_cache_clear();     // clean up before showing anything
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['id'])) {
  $rows = $pdo->query(CLAIM_SELECT . ' ORDER BY c.created_at DESC, c.id DESC LIMIT 2000')->fetchAll();
  out(array_map(fn($c) => ['claimId' => $c['claim_code'], 'item' => $c['item_name'], 'claimant' => $c['claimant'],
    'proof' => proof_kind($c), 'status' => $c['status'], 'createdAt' => iso($c['created_at'])], $rows));
}

/* ---------- one claim, full review data ---------- */
function load_claim(string $code): array {
  $st = db()->prepare(CLAIM_SELECT . ' WHERE c.claim_code = ?'); $st->execute([$code]);
  $c = $st->fetch();
  if (!$c) fail('Claim not found.', 404);
  return $c;
}
function claim_detail(array $c): array {
  $pdo = db();
  $st = $pdo->prepare('SELECT * FROM items WHERE id = ?'); $st->execute([$c['item_id']]);
  $item = $st->fetch();
  $st = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$c['user_id']]);
  $u = $st->fetch();
  $st = $pdo->prepare('SELECT full_name, id_number, email, role FROM users WHERE id = ?'); $st->execute([$item['user_id']]);
  $rep = $st->fetch() ?: [];
  $reporter = (string)($rep['full_name'] ?? '');

  // The claimant's own report of the opposite kind (they claim a found item -> look at their lost reports)
  $opp = $item['type'] === 'found' ? 'lost' : 'found';
  $st = $pdo->prepare("SELECT * FROM items WHERE user_id = ? AND type = ? AND status <> 'returned' AND id <> ?");
  $st->execute([$c['user_id'], $opp, $item['id']]);
  // Score = what the claimant wrote in their claim vs the item; their own report (if they have one) adds location / date / category.
  [$bestScore, $best] = claim_scored($pdo, $c, $item);     // the one scoring function, shared with Match Results
  $ext = strtolower(pathinfo($c['proof_file'], PATHINFO_EXTENSION));
  $need = approve_min(get_settings($pdo));
  return [
    'canApprove' => $bestScore['score'] >= $need, 'approveMin' => $need,     // Low / Medium claims can only be rejected
    'claimId' => $c['claim_code'], 'status' => $c['status'], 'createdAt' => iso($c['created_at']), 'decidedAt' => iso($c['decided_at']),
    'claimName' => (string)($c['claim_name'] ?? ''), 'claimCategory' => (string)($c['claim_category'] ?? ''), 'claimColor' => (string)($c['claim_color'] ?? ''),
    'claimArea' => (string)($c['claim_area'] ?? ''), 'claimLocation' => (string)($c['claim_location'] ?? ''), 'claimDate' => split_when($c['claim_date'] ?? '')[0] ?: null, 'claimTime' => split_when($c['claim_date'] ?? '')[1],
    'adminNote' => $c['admin_note'], 'pickup' => jdec($c['pickup_json']),
    'proof' => ['text' => $c['proof'], 'confirmed' => (bool)$c['confirmed'], 'kind' => proof_kind($c),
      'file' => $c['proof_file'] ? ['name' => $c['proof_name'], 'type' => $ext === 'pdf' ? 'pdf' : 'image',
                                    'url' => 'api/proof.php?claim=' . rawurlencode($c['claim_code'])] : null],
    'claimant' => ['name' => $u['full_name'], 'idNumber' => $u['id_number'], 'email' => $u['email'], 'role' => $u['role'],
                   'program' => $u['program'], 'yearLevel' => $u['year_level'], 'section' => $u['section']],
    'item' => item_card($item) + ['reportedBy' => $reporter, 'reporterIdNumber' => (string)($rep['id_number'] ?? ''), 'reporterEmail' => (string)($rep['email'] ?? ''), 'reporterRole' => (string)($rep['role'] ?? ''), 'privateDetails' => $item['private_details']],
    'itemReturned' => $item['status'] === 'returned',
    'theirReport' => $best ? item_card($best) : null,
    'match' => $bestScore,   // null when the claimant has no report to compare with
  ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') out(claim_detail(load_claim((string)$_GET['id'])));

/* ---------- decisions ---------- */
$in = body();
$action = $_GET['action'] ?? '';
$c = load_claim((string)($in['claimId'] ?? ''));

/** A rejected claim from the owner of a confirmed match puts both items back, so the pair can be reviewed again. */
function release_match(PDO $pdo, int $foundId, int $claimantId): void {
  $st = $pdo->prepare("SELECT d.lost_id FROM match_decisions d JOIN items l ON l.id = d.lost_id
                       WHERE d.found_id = ? AND d.decision = 'confirmed' AND l.user_id = ?");
  $st->execute([$foundId, $claimantId]);
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $lostId) {
    $busy = $pdo->prepare("SELECT COUNT(*) FROM claims WHERE item_id = ? AND status IN ('pending','approved')");
    $busy->execute([$foundId]);
    if ((int)$busy->fetchColumn() > 0) return;          // someone else still has a live claim on it
    $pdo->prepare("UPDATE items SET status = 'available', matched_at = NULL WHERE id IN (?, ?) AND status IN ('matched','pending')")->execute([$foundId, $lostId]);
    $pdo->prepare("UPDATE match_decisions SET decision = 'reverted' WHERE lost_id = ? AND found_id = ?")->execute([$lostId, $foundId]);
  }
}

/** Approved = the item is handed back: the found item (and the claimant's matching report) become Returned, so Search and the stats update. */
function finish_return(PDO $pdo, array $c): void {
  $pdo->prepare("UPDATE items SET status = 'returned', returned_at = COALESCE(returned_at, NOW()) WHERE id = ?")->execute([$c['item_id']]);
  $st = $pdo->prepare("SELECT d.lost_id FROM match_decisions d JOIN items l ON l.id = d.lost_id
                       WHERE d.found_id = ? AND d.decision = 'confirmed' AND l.user_id = ?");
  $st->execute([$c['item_id'], $c['user_id']]);
  $lostIds = $st->fetchAll(PDO::FETCH_COLUMN);
  if (!$lostIds) {                       // no confirmed match: use the claimant's own best-fitting report
    $itm = $pdo->prepare('SELECT * FROM items WHERE id = ?'); $itm->execute([$c['item_id']]); $itm = $itm->fetch();
    $rep = $itm ? claimant_report($pdo, $itm, (int)$c['user_id'], !empty(get_settings($pdo)['zoneMatching'])) : null;
    if ($rep) $lostIds = [$rep['id']];
  }
  foreach ($lostIds as $lostId)
    $pdo->prepare("UPDATE items SET status = 'returned', returned_at = COALESCE(returned_at, NOW()) WHERE id = ?")->execute([$lostId]);
  close_stale_claims($pdo, (int)current_user_id());      // anyone else still waiting on a returned item (including the claimant's own report) is closed
}

if ($action === 'decide') {
  $status = $in['status'] ?? '';
  if (!in_array($status, ['approved', 'rejected'], true)) fail('Invalid decision.');
  if ($c['item_status'] === 'returned') fail('This item has already been returned.', 409);
  $note = str($in['note'] ?? '', 400);
  if ($status === 'approved') {                    // enforced here too, so it cannot be bypassed from the browser
    $d = claim_detail($c);
    if (!$d['canApprove']) fail('Only High scores (' . $d['approveMin'] . ' and above) can be approved. This claim scored ' . $d['match']['score'] . ', so it can only be rejected.', 409);
  }
  if ($status === 'rejected' && $note === '') fail('Please add a short reason so the student knows why.');
  $pickup = null;
  if ($status === 'approved') {
    $p = $in['pickup'] ?? [];
    $pickup = json_encode(['place' => str($p['place'] ?? '', 120) ?: 'Admin Office', 'hours' => str($p['hours'] ?? '', 120) ?: 'Mon–Fri, 8:00–5:00',
                           'bring' => str($p['bring'] ?? '', 160) ?: 'A valid ID', 'days' => max(1, min(365, (int)($p['days'] ?? 90)))], JSON_UNESCAPED_UNICODE);
  }
  $pdo->beginTransaction();
  // re-read inside the transaction so two admins can never decide the same claim twice
  $st = $pdo->prepare('SELECT status FROM claims WHERE id = ? FOR UPDATE'); $st->execute([$c['id']]);
  if ($st->fetchColumn() !== 'pending') { $pdo->rollBack(); fail('This claim was already decided.', 409); }
  $pdo->prepare('UPDATE claims SET status = ?, admin_note = ?, pickup_json = ?, decided_at = NOW(), decided_by = ? WHERE id = ?')
      ->execute([$status, $note, $pickup, $uid, $c['id']]);
  if ($status === 'approved') {
    // one owner per item: every other pending claim on it is closed
    $pdo->prepare("UPDATE claims SET status = 'rejected', admin_note = ?, decided_at = NOW(), decided_by = ? WHERE item_id = ? AND id <> ? AND status = 'pending'")
        ->execute(['Another claim for this item was approved.', $uid, $c['item_id'], $c['id']]);
    // the item itself becomes "claimed" so Search / Item pages and the admin counters stop showing it as available
    finish_return($pdo, $c);
  } else {
    release_match($pdo, (int)$c['item_id'], (int)$c['user_id']);
    refresh_item_status($pdo, (int)$c['item_id']);      // back to Available / Matched, or still Pending if another claim waits
  }
  if ($pdo->inTransaction()) $pdo->commit();

  matches_cache_clear();   // dashboard badges / match list update right away
  // email the student (only if they kept Email notifications + Claim decisions switched on)
  $nm = $c['item_name'];
  if ($status === 'approved') {
    $pk = json_decode($pickup, true);
    email_student($pdo, (int)$c['user_id'], 'decisions', "Your claim for $nm was approved",
      "Your claim {$c['claim_code']} for \"$nm\" was approved.\nPick it up at: {$pk['place']}\nOffice hours: {$pk['hours']}\nBring: {$pk['bring']}\nPlease collect it within {$pk['days']} days.");
  } else {
    email_student($pdo, (int)$c['user_id'], 'decisions', "Your claim for $nm was not approved",
      "Your claim {$c['claim_code']} for \"$nm\" was not approved.\nReason: $note");
  }
  out(claim_detail(load_claim($c['claim_code'])));
}

if ($action === 'returned') {        // kept for older approved claims; approving now returns the item automatically
  if ($c['status'] !== 'approved') fail('Only approved claims can be marked as returned.', 409);
  $pdo->beginTransaction();
  finish_return($pdo, $c);
  if ($pdo->inTransaction()) $pdo->commit();
  matches_cache_clear();
  out(claim_detail(load_claim($c['claim_code'])));
}

fail('Unknown action.', 404);
