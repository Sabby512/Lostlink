<?php
require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/mailer.php';

$action = $_GET['action'] ?? '';
$in = body();

/** Reset links sent in the last hour (each token lives for one hour) */
function recent_resets(int $uid): int {
  $c = db()->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND expires_at > NOW()');
  $c->execute([$uid]); return (int)$c->fetchColumn();
}

const ROLES = ['Student', 'Employee', 'Faculty', 'Parent / Guardian', 'Alumni', 'Visitor', 'Other'];

function start_session_for(array &$u, bool $remember): void {
  if (is_admin_email($u['email']) && empty($u['is_admin'])) {   // emails listed in config.php ADMIN_EMAILS become admins
    db()->prepare('UPDATE users SET is_admin = 1, verified = 1 WHERE id = ?')->execute([$u['id']]);
    $u['is_admin'] = 1; $u['verified'] = 1;
  }
  session_regenerate_id(true);
  $_SESSION['uid'] = (int)$u['id'];
  $_SESSION['sv']  = (int)($u['session_version'] ?? 1);
  if ($remember)   // "Remember me": keep the login cookie for 30 days instead of until the browser closes
    setcookie(session_name(), session_id(), ['expires' => time() + 60 * 60 * 24 * 30, 'path' => '/', 'httponly' => true, 'secure' => is_https(), 'samesite' => 'Lax']);
}

switch ($action) {

  case 'me': {
    $id = require_login();
    out(me_payload(load_user($id)));
  }

  case 'signup': {
    $name  = str($in['name'] ?? '', 120);
    $sid   = str($in['idNumber'] ?? '', 40);
    $role  = str($in['role'] ?? '', 40);
    $email = strtolower(str($in['email'] ?? '', 160));
    $pw    = (string)($in['password'] ?? '');
    if ($name === '' || $sid === '' || $email === '' || $pw === '') fail('Please fill in every field.');
    if (!in_array($role, ROLES, true)) fail('Please select your role.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address.');
    if (strlen($pw) < 8) fail('Password must be at least 8 characters.');
    $st = db()->prepare('SELECT id_number, email FROM users WHERE id_number = ? OR email = ?');
    $st->execute([$sid, $email]);
    foreach ($st->fetchAll() as $r) {
      if (strcasecmp($r['email'], $email) === 0) fail('That email is already registered.', 409);
      fail('That ID number is already registered.', 409);
    }
    try {
      $ins = db()->prepare('INSERT INTO users (full_name,id_number,email,role,password_hash) VALUES (?,?,?,?,?) RETURNING id');
      $ins->execute([$name, $sid, $email, $role, password_hash($pw, PASSWORD_DEFAULT)]);
      $newId = (int)$ins->fetchColumn();
    } catch (PDOException $e) {                       // two people signing up with the same ID at the same moment
      if (str_starts_with((string)$e->getCode(), '23')) fail('That ID number or email is already registered.', 409);
      throw $e;
    }
    $u = load_user($newId);
    start_session_for($u, false);
    out(me_payload($u), 201);
  }

  case 'login': {
    $login = strtolower(str($in['login'] ?? '', 160));
    $pw = (string)($in['password'] ?? '');
    if ($login === '' || $pw === '') fail('Enter your ID number or email and password.');
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $pdo = db();
    $pdo->prepare("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL '1 day')")->execute();
    $c = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE (login = ? OR ip = ?) AND created_at > (NOW() - INTERVAL '15 minutes')");
    $c->execute([$login, $ip]);
    if ((int)$c->fetchColumn() >= 10) fail('Too many attempts. Please wait 15 minutes and try again.', 429);
    $st = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = ? OR LOWER(id_number) = ?');
    $st->execute([$login, $login]);
    $u = $st->fetch();
    if (!$u || !password_verify($pw, $u['password_hash'])) {
      $pdo->prepare('INSERT INTO login_attempts (login, ip) VALUES (?, ?)')->execute([$login, $ip]);
      fail('Incorrect ID number / email or password.', 401);
    }
    if (($u['status'] ?? 'active') !== 'active') fail('This account is suspended. Please contact the SAO / Admin office.', 403);
    $pdo->prepare('DELETE FROM login_attempts WHERE login = ?')->execute([$login]);
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT))
      $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
    start_session_for($u, !empty($in['remember']));
    out(me_payload($u));
  }

  case 'logout': {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $p = session_get_cookie_params();
      setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, true);
    }
    session_destroy();
    out(['ok' => true]);
  }

  case 'change_password': {
    $uid = require_login();
    $cur = (string)($in['current'] ?? ''); $new = (string)($in['next'] ?? '');
    $u = load_user($uid);
    if (!password_verify($cur, $u['password_hash'])) fail('Your current password is not correct.', 403);
    if (strlen($new) < 8) fail('New password must be at least 8 characters.');
    if ($new === $cur) fail('New password must be different from the current one.');
    // session_version + 1 signs out every other device; this one stays signed in
    db()->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
    session_regenerate_id(true);
    $_SESSION['sv'] = (int)load_user($uid)['session_version'];
    out(['ok' => true]);
  }

  case 'forgot': {
    $email = strtolower(str($in['email'] ?? '', 160));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address.');
    $st = db()->prepare('SELECT id, full_name FROM users WHERE LOWER(email) = ?'); $st->execute([$email]);
    if (($u = $st->fetch()) && recent_resets((int)$u['id']) < 3) {      // at most 3 reset emails per hour per account
      $token = bin2hex(random_bytes(32));
      db()->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL '1 hour')")
          ->execute([$u['id'], hash('sha256', $token)]);
      $base = rtrim(env('APP_URL') ?: ((is_https() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']), '/');   // APP_URL = your Vercel address
      $link = $base . '/reset-password.html?token=' . $token;
      $msg = "Hi {$u['full_name']},\n\nUse this link to reset your LostLink password (valid for 1 hour):\n$link\n\nIf you did not ask for this, ignore this email.";
      send_mail($email, 'Reset your LostLink password', $msg);
      if (env('MAIL_DEBUG_LOG') === '1') error_log("LostLink reset link for $email: $link");   // testing only: the link is a password-reset key, do not leave this on
    }
    out(['ok' => true]);   // same answer whether or not the email exists
  }

  case 'reset': {
    $token = (string)($in['token'] ?? ''); $pw = (string)($in['password'] ?? '');
    if (strlen($pw) < 8) fail('Password must be at least 8 characters.');
    $st = db()->prepare('SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > NOW()');
    $st->execute([hash('sha256', $token)]);
    $r = $st->fetch();
    if (!$r) fail('This reset link is invalid or has expired.', 400);
    $pdo = db(); $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $r['user_id']]);
    $pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ?')->execute([$r['user_id']]);
    $pdo->commit();
    out(['ok' => true]);
  }

  default: fail('Unknown action.', 404);
}
