<?php
require __DIR__ . '/../lib/config.php';
$uid = require_login();
$in = body();
$action = $_GET['action'] ?? '';
$pdo = db();

function delete_avatar(string $file): void {
  if ($file && preg_match('/^[a-f0-9]+\.(jpg|png|webp|gif)$/', $file)) storage_delete('avatars', $file);
}

switch ($action) {

  // Settings page: theme, notifications, privacy, and the account fields it shows (name, email, ID number once)
  case 'save_settings': {
    $u = load_user($uid);
    $cur = user_settings($u);
    $theme = in_array($in['theme'] ?? '', ['light', 'dark', 'device'], true) ? $in['theme'] : $cur['theme'];
    $pick = function (array $def, $new) { $o = []; foreach ($def as $k => $v) $o[$k] = isset($new[$k]) ? (bool)$new[$k] : $v; return $o; };
    $settings = ['theme' => $theme, 'notif' => $pick($cur['notif'], $in['notif'] ?? []), 'privacy' => $pick($cur['privacy'], $in['privacy'] ?? [])];

    $p = $in['profile'] ?? [];
    $name = (isset($p['name']) && trim((string)$p['name']) !== '') ? str($p['name'], 120) : $u['full_name'];   // empty = unchanged
    $email = strtolower(trim((string)($p['email'] ?? '')));
    // The email is set by the school account (and password resets are sent to it), so it is not changed from here.
    if ($email !== '' && strcasecmp($email, $u['email']) !== 0) fail('Your email is set by your school. Ask the SAO / Admin office to change it.', 403);
    $email = $u['email'];
    $pdo->prepare('UPDATE users SET full_name = ?, email = ?, settings_json = ? WHERE id = ?')
        ->execute([$name, $email, json_encode($settings), $uid]);
    out(me_payload(load_user($uid)));
  }

  // Profile page: name, program, year level, section, photo
  case 'save_profile': {
    $u = load_user($uid);
    $name = str($in['name'] ?? '', 120);
    if ($name === '') fail('Please enter your full name.');
    $photo = $u['photo'];
    if (array_key_exists('photo', $in)) {
      $new = (string)$in['photo'];
      if ($new === '') { delete_avatar($photo); $photo = ''; }
      elseif (str_starts_with($new, 'data:')) {
        $file = save_data_url($new, 'avatars', ['image/jpeg' => 'jpg', 'image/png' => 'png'], 2 * 1024 * 1024);
        delete_avatar($photo); $photo = $file;
      }                                                   // anything else = unchanged
    }
    $pdo->prepare('UPDATE users SET full_name = ?, program = ?, year_level = ?, section = ?, photo = ? WHERE id = ?')
        ->execute([$name, str($in['program'] ?? '', 120), str($in['yearLevel'] ?? '', 40), str($in['section'] ?? '', 40), $photo, $uid]);
    out(me_payload(load_user($uid)));
  }

  default: fail('Unknown action.', 404);
}
