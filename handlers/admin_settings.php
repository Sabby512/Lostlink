<?php
// Admin Settings: load / save preferences. Admins only. (Password changes use your existing api/auth.php?action=change_password.)
require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/match_lib.php';
$uid = require_login();
$me = load_user($uid);
if (!$me['is_admin']) fail('Admins only.', 403);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  out(['settings' => get_settings($pdo), 'admin' => ['name' => $me['full_name'], 'email' => $me['email']]]);
}

$in = body();
$action = $_GET['action'] ?? '';

if ($action === 'save') {
  $s = get_settings($pdo);
  foreach (['notifyClaim', 'notifyMatch', 'notifyDaily', 'zoneMatching', 'notifyStudent', 'requireProof', 'allowProofFile', 'photoBwDefault'] as $k)
    if (array_key_exists($k, $in)) $s[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);
  if (isset($in['confirmMin']))     $s['confirmMin'] = max(MATCH_HIGH, min(100, (int)$in['confirmMin']));
  if (isset($in['autoExpireDays'])) { $v = (int)$in['autoExpireDays']; if (in_array($v, [30, 60, 90, 180, 365], true)) $s['autoExpireDays'] = $v; }
  if (isset($in['sessionTimeout'])) { $v = (int)$in['sessionTimeout']; if (in_array($v, [15, 30, 60, 120], true)) $s['sessionTimeout'] = $v; }
  save_settings($pdo, $s);
  out(['settings' => $s]);
}

fail('Unknown action.', 404);
