<?php
// Shared by admin_claims.php and admin_match.php: how two reports are scored, the High / Medium / Low bands, and admin settings.
const MATCH_HIGH   = 85;   // 85 and up = High: the only level that can be confirmed (item becomes Matched, the student can claim it)
const MATCH_MEDIUM = 50;   // 50-84 = Medium, below 50 = Low
/** The general areas a student can choose (public). Keep in sync with the Area dropdowns in report-lost / report-found / claim / search.html. */
const CAMPUS_AREAS = [
  'Old Building',
  'Annex 2 Building',
  'Carpentry Area',
  'Basic Ed. Building',
  'Motorcycle Parking Area',
  'Canteen',
  'New Building',
  'NSA Building',
  'GYM',
  'Chapel',
  'Morning Station',
  'Swimming Pool',
  'Machine Shop',
  'ME ROOM',
  'Business Building',
  'Gate',
  'Others'
];
function valid_area(string $a): bool { return in_array($a, CAMPUS_AREAS, true); }
const MATCH_FLOOR  = 20;   // pairs scoring below this are not worth listing at all

/** The score a claim / match needs before an admin may approve or confirm it (High band, or the stricter admin setting). */
function approve_min(array $cfg): int { return max(MATCH_HIGH, (int)($cfg['confirmMin'] ?? MATCH_HIGH)); }
/** Category names compared without case, spaces or punctuation, so "Mini fan" = "minifan". */
function norm_cat(string $s): string { return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s)); }
function same_category(string $a, string $b): bool { $x = norm_cat($a); return $x !== '' && $x === norm_cat($b); }

function match_level(int $score): string { return $score >= MATCH_HIGH ? 'High' : ($score >= MATCH_MEDIUM ? 'Medium' : 'Low'); }

function tokens(string $s): array {
  preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($s), $m);
  return array_values(array_unique(array_filter($m[0], fn($w) => mb_strlen($w) > 1)));
}
function jaccard(array $a, array $b): float {
  if (!$a || !$b) return 0.0;
  return count(array_intersect($a, $b)) / count(array_unique(array_merge($a, $b)));
}
function text_sim(string $a, string $b): float {
  $a = mb_strtolower(trim($a)); $b = mb_strtolower(trim($b));
  if ($a === '' || $b === '') return 0.0;
  similar_text($a, $b, $pct);
  return max($pct / 100, jaccard(tokens($a), tokens($b)));
}
/* ---------- typo tolerance ("walet" still counts as "wallet") ---------- */
/** Edit distance where swapping two neighbouring letters ("wlalet") counts as ONE mistake. Multibyte safe. */
function osa_distance(string $a, string $b): int {
  $x = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY); $y = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
  $n = count($x); $m = count($y);
  if (!$n) return $m; if (!$m) return $n;
  $d = [];
  for ($i = 0; $i <= $n; $i++) $d[$i][0] = $i;
  for ($j = 0; $j <= $m; $j++) $d[0][$j] = $j;
  for ($i = 1; $i <= $n; $i++) for ($j = 1; $j <= $m; $j++) {
    $c = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
    $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $c);
    if ($i > 1 && $j > 1 && $x[$i - 1] === $y[$j - 2] && $x[$i - 2] === $y[$j - 1]) $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
  }
  return $d[$n][$m];
}
/** Same word, allowing small typos. Short words (under 4 letters) and numbers must match exactly; 4 letters need the same first letter;
 *  4-7 letters allow 1 mistake, 8 or more allow 2. */
function fuzzy_eq(string $a, string $b): bool {
  if ($a === $b) return true;
  if (ctype_digit($a) || ctype_digit($b)) return false;
  $la = mb_strlen($a); $lb = mb_strlen($b); $n = min($la, $lb);
  if ($n < 4 || abs($la - $lb) > 2) return false;
  if ($n === 4 && mb_substr($a, 0, 1) !== mb_substr($b, 0, 1)) return false;
  return osa_distance($a, $b) <= ($n >= 8 ? 2 : 1);
}
/** Which of $needles also appear in $hay, typos allowed. Returns the needles (item words), in their original order. */
function fuzzy_hits(array $needles, array $hay): array {
  $out = [];
  foreach ($needles as $w) { foreach ($hay as $h) if (fuzzy_eq((string)$w, (string)$h)) { $out[] = $w; break; } }
  return $out;
}

/* ---------- date AND time ---------- */
/** "HH:MM" or null. Accepts "9:05", "09:05:00"; midnight (00:00) means "no time given" (old rows and date-only forms). */
function clock_of($t): ?string {
  if (!is_string($t) || !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::\d\d)?$/', trim($t), $m)) return null;
  $h = sprintf('%02d:%s', (int)$m[1], $m[2]);
  return $h === '00:00' ? null : $h;
}
/** Split "2026-11-13 14:30:00" / "2026-11-13" into [date, clock]. */
function split_when(?string $v): array {
  $v = trim((string)$v);
  return [substr($v, 0, 10), strlen($v) > 10 ? clock_of(substr($v, 11)) : null];
}
function when_label(?string $d, ?string $t): string { $d = trim((string)$d); return $d === '' ? '' : $d . ($t ? ' ' . $t : ''); }
/** Points out of $max for how close two moments are. Uses the hours when BOTH have a time, otherwise whole days. */
function when_points(?string $d1, ?string $t1, ?string $d2, ?string $t2, int $max): int {
  if (!$d1 || !$d2) return 0;
  $days = abs((strtotime($d1) - strtotime($d2)) / 86400);
  if ($t1 && $t2) {
    $hrs = abs((strtotime("$d1 $t1") - strtotime("$d2 $t2")) / 3600);
    if ($hrs <= 3) return $max;
    if ($hrs <= 12) return (int)round($max * .9);
    if ($hrs <= 24) return (int)round($max * .8);
    $days = $hrs / 24;
    $f = $days <= 3 ? .6 : ($days <= 7 ? .33 : ($days <= 14 ? .13 : 0));
    return (int)round($max * $f);
  }
  $f = $days < 1 ? 1 : ($days <= 1 ? .8 : ($days <= 3 ? .6 : ($days <= 7 ? .33 : ($days <= 14 ? .13 : 0))));
  return (int)round($max * $f);
}

/** How well does the claimant's own report ($b) fit the item they claimed ($a)? Total 0-100 with a breakdown. */
function score_pair(array $a, array $b, bool $zone = true): array {
  $parts = [];
  $s = text_sim($a['name'], $b['name']);
  $parts[] = ['key' => 'name', 'label' => 'Item name', 'points' => (int)round(30 * $s), 'max' => 30, 'a' => $a['name'], 'b' => $b['name']];
  $same = same_category((string)$a['category'], (string)$b['category']);
  $parts[] = ['key' => 'category', 'label' => 'Category', 'points' => $same ? 20 : 0, 'max' => 20, 'a' => $a['category'], 'b' => $b['category']];
  $areaSame = $zone && strcasecmp($a['area'], $b['area']) === 0;
  $loc = ($areaSame ? 15 : 0) + (int)round(10 * text_sim($a['location'], $b['location']));
  $parts[] = ['key' => 'location', 'label' => 'Location', 'points' => min(25, $loc), 'max' => 25,
              'a' => trim($a['location'] . ' (' . $a['area'] . ' area)'), 'b' => trim($b['location'] . ' (' . $b['area'] . ' area)')];
  $ta = clock_of($a['lost_at'] ?? null); $tb = clock_of($b['lost_at'] ?? null);      // only lost reports carry a time
  $parts[] = ['key' => 'date', 'label' => 'Date/time', 'points' => when_points($a['item_date'], $ta, $b['item_date'], $tb, 15), 'max' => 15,
              'a' => when_label($a['item_date'], $ta), 'b' => when_label($b['item_date'], $tb)];
  $d = jaccard(tokens((string)$a['description']), tokens((string)$b['description']));
  $parts[] = ['key' => 'details', 'label' => 'Description', 'points' => (int)round(10 * min(1, $d * 2)), 'max' => 10,
              'a' => (string)$a['description'], 'b' => (string)$b['description']];
  $total = min(100, array_sum(array_column($parts, 'points')));
  return ['score' => $total, 'level' => match_level($total), 'parts' => $parts];
}

/* ---------- admin settings (one JSON row in app_settings) ---------- */
function settings_defaults(): array {
  return ['notifyClaim' => true, 'notifyMatch' => true, 'notifyDaily' => false,
          'zoneMatching' => true, 'confirmMin' => MATCH_HIGH, 'notifyStudent' => true,
          'requireProof' => true, 'allowProofFile' => true, 'photoBwDefault' => true, 'autoExpireDays' => 90, 'sessionTimeout' => 30];
}
function get_settings(PDO $pdo): array {
  $v = $pdo->query("SELECT v FROM app_settings WHERE k = 'admin'")->fetchColumn();
  $saved = $v ? (json_decode($v, true) ?: []) : [];
  return array_merge(settings_defaults(), array_intersect_key($saved, settings_defaults()));
}
function save_settings(PDO $pdo, array $s): void {
  $pdo->prepare("INSERT INTO app_settings (k, v) VALUES ('admin', ?) ON CONFLICT (k) DO UPDATE SET v = EXCLUDED.v")->execute([json_encode($s)]);
}

/* ---------- helpers that admin_claims.php / claims.php / notifications.php already call but were never defined ---------- */
function ensure_match_table(PDO $pdo): void {}      // the table is created by sql/schema.sql
/** Email a student about a decision. Respects Admin "notify student" and the student's own Email + $topic switches. */
function email_student(PDO $pdo, int $userId, string $topic, string $subject, string $body): void {
  if (empty(get_settings($pdo)['notifyStudent'])) return;
  $st = $pdo->prepare('SELECT email, full_name, settings_json FROM users WHERE id = ?'); $st->execute([$userId]);
  $u = $st->fetch(); if (!$u) return;
  $pref = user_settings($u);
  if (empty($pref['notif']['email']) || empty($pref['notif'][$topic])) return;
  require_once __DIR__ . '/mailer.php';
  try { send_mail($u['email'], $subject, "Hi {$u['full_name']},\n\n$body\n\n- UCLM LostLink"); }
  catch (Throwable $e) { error_log('LostLink email_student: ' . $e->getMessage()); }
}

/* ---------- scoring a CLAIM: what the claimant wrote vs the item they claimed ---------- */
const CLAIM_ROW_BASE = 1000000000;     // claim rows in Match Results get an id above this, so they never clash with item ids

function claim_stopwords(): array {
  static $w = null;
  return $w ??= array_flip(['the','a','an','and','or','of','in','on','at','to','for','with','my','is','it','its','was','were','has','have','had',
    'this','that','there','inside','i','me','you','your','are','be','been','from','by','as','but','not','no','so','ko','ang','ng','sa','na','may','mga','at','yung','ito']);
}
/** Words that carry meaning: lower-case, no filler words, plural "s" removed so "wallets" matches "wallet". */
function sig_tokens(string $s): array {
  $out = [];
  foreach (tokens($s) as $w) {
    if (isset(claim_stopwords()[$w])) continue;
    $out[] = (mb_strlen($w) > 3 && str_ends_with($w, 's')) ? mb_substr($w, 0, -1) : $w;
  }
  return array_values(array_unique($out));
}
/** Words that sit right after a negation ("no sling", "without strap", "walang case"), so "no sling" is NOT the same as "has a sling". */
function negated_tokens(string $s): array {
  static $neg = null;
  $neg ??= array_flip(['no','not','without','none','never','wala','walang','hindi','doesn','don','didn','isn','hasn','haven','wasn','weren','aren','cannot']);
  preg_match_all('/[\p{L}\p{N}]+|[.,;:!?\n]/u', mb_strtolower($s), $m);
  $out = []; $left = 0;
  foreach ($m[0] as $w) {
    if (preg_match('/^[.,;:!?\n]$/u', $w) || $w === 'but' || $w === 'pero') { $left = 0; continue; }
    if (isset($neg[$w])) { $left = 2; continue; }
    if (mb_strlen($w) < 2 || isset(claim_stopwords()[$w])) continue;
    if ($left > 0) { $out[] = (mb_strlen($w) > 3 && str_ends_with($w, 's')) ? mb_substr($w, 0, -1) : $w; $left--; }
  }
  return array_values(array_unique($out));
}
function brief(string $s, int $n = 110): string { $s = trim(preg_replace('/\s+/', ' ', $s)); return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s; }

/** The claimant's own report of the opposite kind that fits the claimed item best (null when they have none). */
function claimant_report(PDO $pdo, array $item, int $userId, bool $zone = true): ?array {
  $opp = $item['type'] === 'found' ? 'lost' : 'found';
  $st = $pdo->prepare("SELECT * FROM items WHERE user_id = ? AND type = ? AND status <> 'returned' AND id <> ?");
  $st->execute([$userId, $opp, $item['id']]);
  $best = null; $bs = -1;
  foreach ($st->fetchAll() as $cand) { $sc = score_pair($item, $cand, $zone)['score']; if ($sc > $bs) { $best = $cand; $bs = $sc; } }
  return $best;
}

/** Score 0-100 for a claim. The proof text is the main evidence; the claimant's own lost report (if any) adds to location / date / category.
 *  Same shape as score_pair(), so the review page and Match Results can show it without changes. */
/** What the claimant typed in the claim form (category / where / when). Works on a claims row. */
function claim_extra(array $c): array {
  return ['name' => (string)($c['claim_name'] ?? ''), 'category' => (string)($c['claim_category'] ?? ''), 'color' => (string)($c['claim_color'] ?? ''), 'location' => trim((string)($c['claim_area'] ?? '') . ' ' . (string)($c['claim_location'] ?? '')), 'date' => (string)($c['claim_date'] ?? '')];
}
/** Color words recognised when an item has no Color field filled in (older reports): taken from its name / description. */
function color_words(): array {
  static $w = null;
  return $w ??= array_flip(['black','white','gray','grey','silver','red','blue','navy','green','yellow','orange','pink','purple','violet','brown','beige','gold','maroon','cyan','teal','tan','clear','transparent',
    'itim','puti','pula','asul','berde','dilaw','kayumanggi']);
}
function color_tokens(string $s): array {
  $out = [];
  foreach (sig_tokens($s) as $w) { if (in_array($w, ['color','colour','kulay'], true)) continue; $out[] = $w === 'grey' ? 'gray' : $w; }
  return array_values(array_unique($out));
}
/** The colour of an item: its Color field (details.color, or a walk-in "Color ..." answer), else colour words in its name / description. */
function item_color(array $it): string {
  $d = jdec($it['details_json'] ?? null);
  if (is_array($d)) {
    if (!empty($d['color']) && is_string($d['color'])) return trim($d['color']);
    foreach ($d as $k => $v) if (is_string($v) && trim($v) !== '' && stripos((string)$k, 'colo') !== false) return trim($v);
  }
  $found = array_filter(color_tokens(trim(($it['name'] ?? '') . ' ' . ($it['description'] ?? '') . ' ' . ($it['private_details'] ?? ''))), fn($w) => isset(color_words()[$w]));
  return implode(' ', array_values($found));
}

function score_claim(array $item, string $proof, ?array $report = null, bool $zone = true, ?array $extra = null): array {
  $P = sig_tokens($proof);
  $P = array_values(array_unique(array_merge($P, sig_tokens((string)($extra['name'] ?? '')))));   // the item name they typed counts as evidence too
  $xCat = trim((string)($extra['category'] ?? '')); $xLoc = trim((string)($extra['location'] ?? '')); $xDate = trim((string)($extra['date'] ?? ''));
  $parts = [];

  $N = sig_tokens($item['name']); $hit = fuzzy_hits($N, $P);
  $parts[] = ['key' => 'name', 'label' => 'Item name in the claim', 'max' => 25, 'points' => $N ? (int)round(25 * count($hit) / count($N)) : 0,
              'a' => $item['name'], 'b' => implode(', ', $hit)];

  $dText = trim(($item['description'] ?? '') . ' ' . ($item['private_details'] ?? ''));
  $D = array_values(array_diff(sig_tokens($dText), $N));
  // Same word only counts when both sides agree: "has a sling" vs "no sling" is a CONTRADICTION, not a match.
  $negD = negated_tokens($dText); $negP = negated_tokens($proof);
  $Dpos = array_values(array_diff($D, $negD)); $Dneg = array_values(array_intersect($D, $negD));
  $Ppos = array_values(array_diff($P, $negP)); $Pneg = array_values(array_intersect($P, $negP));
  $hit = array_merge(fuzzy_hits($Dpos, $Ppos), fuzzy_hits($Dneg, $Pneg));
  $bad = array_merge(fuzzy_hits($Dpos, $Pneg), fuzzy_hits($Dneg, $Ppos));
  $parts[] = ['key' => 'details', 'label' => 'Details they described', 'max' => 25,
              'points' => $D ? min(25, max(0, (int)round(25 * (count($hit) - count($bad)) / max(1, min(count($D), 6))))) : 0,
              'a' => brief($dText), 'b' => implode(', ', $hit) . ($bad ? ($hit ? ' · ' : '') . 'contradicts: ' . implode(', ', $bad) : '')];

  $C = sig_tokens((string)$item['category']);
  $catIn = $C && fuzzy_hits($C, $P);
  $catRep = $report && same_category((string)$item['category'], (string)$report['category']);
  $catX = same_category((string)$item['category'], $xCat);
  $parts[] = ['key' => 'category', 'label' => 'Category', 'max' => 15, 'points' => ($catX || $catIn || $catRep) ? 15 : 0,
              'a' => $item['category'], 'b' => $xCat !== '' ? $xCat : ($report ? $report['category'] : ($catIn ? 'mentioned in the claim' : ''))];

  $L = sig_tokens(trim($item['location'] . ' ' . $item['area']));
  $fromProof = $L ? (int)round(15 * count(fuzzy_hits($L, $P)) / max(1, min(count($L), 3))) : 0;
  $X = $xLoc !== '' ? sig_tokens($xLoc) : [];
  $fromForm = ($L && $X) ? (int)round(15 * count(fuzzy_hits($L, $X)) / max(1, min(count($L), 3))) : 0;
  $fromReport = $report ? (($zone && strcasecmp($item['area'], $report['area']) === 0 ? 8 : 0) + (int)round(7 * text_sim($item['location'], $report['location']))) : 0;
  $parts[] = ['key' => 'location', 'label' => 'Location', 'max' => 15, 'points' => min(15, max($fromProof, $fromReport, $fromForm)),
              'a' => trim($item['location'] . ' (' . $item['area'] . ' area)'),
              'b' => $xLoc !== '' ? $xLoc : ($report ? trim($report['location'] . ' (' . $report['area'] . ' area)') : implode(', ', fuzzy_hits($L, $P)))];

  // Date/time lost: what they typed on the claim form (date + time), else their own lost report's date + time
  if ($xDate !== '') [$rd, $rt] = split_when($xDate); else { $rd = (string)($report['item_date'] ?? ''); $rt = clock_of($report['lost_at'] ?? null); }
  $it_t = clock_of($item['lost_at'] ?? null);
  $parts[] = ['key' => 'date', 'label' => 'Date/time', 'max' => 10, 'points' => when_points($item['item_date'] ?? null, $it_t, $rd ?: null, $rt, 10),
              'a' => when_label($item['item_date'] ?? '', $it_t), 'b' => when_label($rd, $rt)];

  // Color: what the claimant typed in the Color field (else colour words in their proof) vs the item's colour
  $IC = color_tokens(item_color($item)); $xCol = trim((string)($extra['color'] ?? ''));
  $CS = $xCol !== '' ? color_tokens($xCol) : array_values(array_filter($P, fn($w) => isset(color_words()[$w])));
  $chit = fuzzy_hits($IC, $CS);
  $parts[] = ['key' => 'color', 'label' => 'Color', 'max' => 10, 'points' => ($IC && $CS) ? (int)round(10 * count($chit) / count($IC)) : 0,
              'a' => item_color($item), 'b' => $xCol !== '' ? $xCol : implode(', ', $chit)];

  $total = min(100, array_sum(array_column($parts, 'points')));
  return ['score' => $total, 'level' => match_level($total), 'parts' => $parts];
}

/** THE score of a claim. Match Results, Manage Claims (review page + Approve lock) and My Claims all call this, so the numbers can never differ.
 *  Returns [score array, the claimant's own best-fitting report or null]. $c is a claims row, $item the claimed item. */
function claim_scored(PDO $pdo, array $c, array $item): array {
  $zone = !empty(get_settings($pdo)['zoneMatching']);
  $rep = claimant_report($pdo, $item, (int)$c['user_id'], $zone);
  return [score_claim($item, (string)$c['proof'], $rep, $zone, claim_extra($c)), $rep];
}

/** Found items reported by OTHER people that look like this user's open lost reports (Medium score or better, same category).
 *  Works for every account, admins included. Used by the dashboard "Possible match" card and the bell. */
function possible_matches_for(PDO $pdo, int $userId, int $limit = 5): array {
  ensure_match_table($pdo);
  $zone = !empty(get_settings($pdo)['zoneMatching']);
  $st = $pdo->prepare("SELECT * FROM items WHERE type = 'lost' AND user_id = ? AND status NOT IN ('returned','matched')");
  $st->execute([$userId]);
  $lost = $st->fetchAll();
  if (!$lost) return [];
  $found = $pdo->query("SELECT * FROM items WHERE type = 'found' AND status = 'available'")->fetchAll();
  $skip = [];
  foreach ($pdo->query('SELECT lost_id, found_id FROM match_decisions')->fetchAll() as $d) $skip[$d['lost_id'] . '-' . $d['found_id']] = true;
  $out = [];
  foreach ($lost as $l) foreach ($found as $f) {
    if ((int)$f['user_id'] === $userId || isset($skip[$l['id'] . '-' . $f['id']])) continue;
    $sc = score_pair($f, $l, $zone);
    if (part_pct($sc, 'category') === 0 || $sc['score'] < MATCH_MEDIUM) continue;
    $out[] = ['lostId' => (string)$l['id'], 'lostName' => $l['name'], 'foundId' => (string)$f['id'], 'name' => $f['name'], 'category' => $f['category'],
              'area' => $f['area'], 'photo' => public_photo_src($f), 'level' => $sc['level'], 'score' => $sc['score'], 'createdAt' => iso($f['created_at'])];
  }
  usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
  return array_slice($out, 0, $limit);
}

/* ---------- matching (shared by Match Results and the dashboard) ---------- */
/** The dashboard/sidebar badges reuse the last Match Results calculation for up to $ttl seconds. It is kept in the database
 *  (app_settings, key 'matches_cache') because every Vercel function call can run on a different machine. Match Results itself always recalculates. */
function matches_cache_clear(): void { try { db()->exec("DELETE FROM app_settings WHERE k = 'matches_cache'"); } catch (Throwable $e) {} }
function cached_matches(PDO $pdo, int $ttl = 60): array {
  $v = $pdo->query("SELECT v FROM app_settings WHERE k = 'matches_cache'")->fetchColumn();
  if ($v) { $j = json_decode((string)$v, true); if (is_array($j) && isset($j['at'], $j['res']) && time() - (int)$j['at'] < $ttl) return $j['res']; }
  return build_matches($pdo);
}
function part_pct(array $sc, string $key): int {
  foreach ($sc['parts'] as $p) if ($p['key'] === $key) return (int)round($p['points'] / max(1, $p['max']) * 100);
  return 0;
}

/** For every open lost report, the best-scoring available found item (same person's own items and discarded pairs skipped). */
function build_matches(PDO $pdo): array {
  $cfg = get_settings($pdo); $zone = !empty($cfg['zoneMatching']); $need = max(MATCH_HIGH, (int)$cfg['confirmMin']);
  $lost  = $pdo->query("SELECT * FROM items WHERE type = 'lost'  AND status NOT IN ('returned','matched')")->fetchAll();
  $found = $pdo->query("SELECT * FROM items WHERE type = 'found' AND status = 'available'")->fetchAll();
  $skip = [];
  foreach ($pdo->query('SELECT lost_id, found_id FROM match_decisions')->fetchAll() as $d) $skip[$d['lost_id'] . '-' . $d['found_id']] = true;
  $rows = []; $stats = ['high' => 0, 'medium' => 0, 'low' => 0];
  foreach ($lost as $l) {
    $best = null; $bestSc = null;
    foreach ($found as $f) {
      if ((int)$f['user_id'] === (int)$l['user_id'] || isset($skip[$l['id'] . '-' . $f['id']])) continue;
      $sc = score_pair($f, $l, $zone);
      if (part_pct($sc, 'category') === 0) continue;          // a wallet is never a match for a bottle, whatever else lines up
      if (!$bestSc || $sc['score'] > $bestSc['score']) { $best = $f; $bestSc = $sc; }
    }
    if (!$best || $bestSc['score'] < MATCH_FLOOR) continue;
    $stats[strtolower($bestSc['level'])]++;
    $rows[] = ['lostId' => (string)$l['id'], 'lostCode' => 'L-' . $l['id'], 'lostName' => $l['name'],
               'foundId' => (string)$best['id'], 'foundCode' => 'F-' . $best['id'], 'foundName' => $best['name'],
               'location' => part_pct($bestSc, 'location'), 'date' => part_pct($bestSc, 'date'), 'category' => part_pct($bestSc, 'category'),
               'claimant' => null,
               'score' => $bestSc['score'], 'level' => $bestSc['level'], 'canConfirm' => $bestSc['score'] >= $need];
  }

  // Pending CLAIMS are scored too: what the claimant typed in their proof is compared with the item they claimed,
  // so a claim shows up here even when the student never filed a lost report.
  $claims = $pdo->query("SELECT c.*, u.full_name
                         FROM claims c JOIN users u ON u.id = c.user_id WHERE c.status = 'pending'")->fetchAll();
  $getItem = $pdo->prepare('SELECT * FROM items WHERE id = ?');
  foreach ($claims as $c) {
    $getItem->execute([$c['item_id']]); $it = $getItem->fetch();
    if (!$it || $it['status'] === 'returned') continue;
    $rowId = CLAIM_ROW_BASE + (int)$c['id'];
    if (isset($skip[$rowId . '-' . $it['id']])) continue;               // dismissed with "Discard"
    [$sc, $rep] = claim_scored($pdo, $c, $it);          // same function as the Manage Claims review page
    $stats[strtolower($sc['level'])]++;
    // "Lost item" = the item name the claimant typed on the claim form (else their own lost report, else the category).
    // Who made the claim goes in its own column ('claimant'), not in the item name.
    $rows[] = ['lostId' => (string)$rowId, 'lostCode' => $c['claim_code'], 'lostName' => (string)$c['claim_name'] ?: ($rep['name'] ?? ((string)$c['claim_category'] ?: 'Claim')),
               'claimant' => $c['full_name'],
               'foundId' => (string)$it['id'], 'foundCode' => ($it['type'] === 'found' ? 'F-' : 'L-') . $it['id'], 'foundName' => $it['name'],
               'location' => part_pct($sc, 'location'), 'date' => part_pct($sc, 'date'), 'category' => part_pct($sc, 'category'),
               'score' => $sc['score'], 'level' => $sc['level'], 'canConfirm' => false, 'isClaim' => true];   // claims are decided in Manage Claims
  }

  usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
  $res = ['rows' => $rows, 'stats' => $stats, 'confirmMin' => $need];
  try {                                                      // keeps the badge numbers in step with Match Results
    $pdo->prepare("INSERT INTO app_settings (k, v) VALUES ('matches_cache', ?) ON CONFLICT (k) DO UPDATE SET v = EXCLUDED.v")->execute([json_encode(['at' => time(), 'res' => $res])]);
  } catch (Throwable $e) { error_log('LostLink matches cache: ' . $e->getMessage()); }
  return $res;
}


/* ---------- item status flow: available -> pending (a claim is waiting) -> returned (admin approved the claim) ---------- */
function refresh_item_status(PDO $pdo, int $id): void {
  $st = $pdo->prepare('SELECT status FROM items WHERE id = ?'); $st->execute([$id]);
  $cur = $st->fetchColumn();
  if ($cur === false || $cur === 'returned') return;
  $q = function (string $sql) use ($pdo, $id) { $x = $pdo->prepare($sql); $x->execute(array_fill(0, substr_count($sql, '?'), $id)); return (int)$x->fetchColumn() > 0; };
  if ($q("SELECT COUNT(*) FROM claims WHERE item_id = ? AND status = 'pending'")) $new = 'pending';
  else {
    ensure_match_table($pdo);
    $new = $q("SELECT COUNT(*) FROM match_decisions WHERE (found_id = ? OR lost_id = ?) AND decision = 'confirmed'") ? 'matched' : 'available';
  }
  if ($new !== $cur) $pdo->prepare('UPDATE items SET status = ? WHERE id = ?')->execute([$new, $id]);
}
/** The photo URL that anyone but the reporter/admins may see. A photo that is not black & white yet is hidden, never shown in colour. */
function public_photo_src(array $r): string {
  if (empty($r['photo'])) return '';
  static $bw = null;
  if ($bw === null) $bw = !empty(get_settings(db())['photoBwDefault']);
  if ($bw && empty($r['photo_bw'])) return '';
  return photo_src((string)$r['photo'], (int)($r['photo_bw'] ?? 0));
}

/** Item-type words (watch, umbrella, fan...) found in an item's name, category and description. Only these whitelisted words are
 *  shown publicly, so Search can find "watch" without exposing the description or any private detail. */
function item_tags(array $r): array {
  static $vocab = null;
  if ($vocab === null) $vocab = array_flip(['watch','umbrella','fan','charger','powerbank','cable','earphones','earbuds','airpods','headphones','headset',
    'laptop','tablet','ipad','calculator','glasses','eyeglasses','jacket','hoodie','uniform','shirt','shoes','slippers','cap','pen','pencil','notebook',
    'lanyard','tumbler','lunchbox','mouse','keyboard','flashdrive','usb','camera','ring','bracelet','necklace','speaker','scissors','ruler','folder']);
  $alias = ['smartwatch' => 'watch', 'wristwatch' => 'watch', 'minifan' => 'fan', 'handfan' => 'fan', 'spectacles' => 'glasses', 'earpods' => 'earphones'];
  preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower(($r['name'] ?? '') . ' ' . ($r['category'] ?? '') . ' ' . ($r['description'] ?? '')), $m);
  $w = $m[0]; $cand = [];
  foreach ($w as $i => $x) { $cand[] = $x; if (isset($w[$i + 1])) $cand[] = $x . $w[$i + 1]; }     // "mini fan" also counts as "minifan"
  $tags = [];
  foreach ($cand as $x) { $x = $alias[$x] ?? $x; if (isset($vocab[$x])) $tags[$x] = true; }
  if (isset($tags['fan'])) $tags['minifan'] = true;
  if (isset($tags['watch'])) $tags['smartwatch'] = true;
  return array_keys($tags);
}
