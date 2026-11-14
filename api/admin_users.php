<?php
// Manage Users: list, suspend / activate, add an account. Admins only.
require __DIR__ . '/../lib/config.php';
$uid = require_login();
$me = load_user($uid);
if (!$me['is_admin']) fail('Admins only.', 403);
$pdo = db();

const USER_ROLES = ['Student', 'Employee', 'Faculty', 'Parent / Guardian', 'Alumni', 'Visitor', 'Other'];

function user_row(array $u, int $meId): array {
  $pdo = db();
  $c = $pdo->prepare('SELECT COUNT(*) FROM claims WHERE user_id = ?'); $c->execute([$u['id']]);
  $i = $pdo->prepare('SELECT COUNT(*) FROM items WHERE user_id = ?');  $i->execute([$u['id']]);
  return ['id' => (string)$u['id'], 'name' => $u['full_name'], 'idNumber' => $u['id_number'], 'email' => $u['email'], 'role' => $u['role'],
          'program' => $u['program'], 'yearLevel' => $u['year_level'], 'section' => $u['section'],
          'status' => ($u['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active',
          'joinedAt' => iso($u['created_at']), 'claims' => (int)$c->fetchColumn(), 'items' => (int)$i->fetchColumn(),
          'isMe' => (int)$u['id'] === $meId, 'isAdmin' => (bool)$u['is_admin']];
}
function load_row(int $id): array {
  $st = db()->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$id]);
  $u = $st->fetch(); if (!$u) fail('User not found.', 404);
  return $u;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $rows = $pdo->query('SELECT * FROM users ORDER BY created_at DESC, id DESC LIMIT 2000')->fetchAll();
  out(array_map(fn($u) => user_row($u, $uid), $rows));
}

$in = body();
$action = $_GET['action'] ?? '';

if ($action === 'set_status') {
  $u = load_row((int)($in['id'] ?? 0));
  $status = $in['status'] ?? '';
  if (!in_array($status, ['active', 'suspended'], true)) fail('Invalid status.');
  if ((int)$u['id'] === $uid) fail('You cannot suspend your own account.', 409);
  if ($u['is_admin'] && $status === 'suspended') fail('Admin accounts cannot be suspended here.', 409);
  $pdo->prepare('UPDATE users SET status = ?, session_version = session_version + 1 WHERE id = ?')->execute([$status, $u['id']]);
  out(user_row(load_row((int)$u['id']), $uid));
}

if ($action === 'add') {
  $name = str($in['name'] ?? '', 120); $sid = str($in['idNumber'] ?? '', 40); $role = str($in['role'] ?? '', 40);
  $email = strtolower(str($in['email'] ?? '', 160)); $pw = (string)($in['password'] ?? '');
  if ($name === '' || $sid === '' || $email === '' || $pw === '') fail('Please fill in every field.');
  if (!in_array($role, USER_ROLES, true)) fail('Please select a role.');
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address.');
  if (strlen($pw) < 8) fail('Password must be at least 8 characters.');
  $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id_number = ? OR email = ?'); $st->execute([$sid, $email]);
  if ((int)$st->fetchColumn() > 0) fail('That ID number or email is already registered.', 409);
  try {
    $ins = $pdo->prepare('INSERT INTO users (full_name,id_number,email,role,password_hash) VALUES (?,?,?,?,?) RETURNING id');
    $ins->execute([$name, $sid, $email, $role, password_hash($pw, PASSWORD_DEFAULT)]);
    $newId = (int)$ins->fetchColumn();
  } catch (PDOException $e) { if (str_starts_with((string)$e->getCode(), '23')) fail('That ID number or email is already registered.', 409); throw $e; }
  out(user_row(load_row($newId), $uid), 201);
}

fail('Unknown action.', 404);
