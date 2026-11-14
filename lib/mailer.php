<?php
// Gmail SMTP settings come from Vercel Environment Variables: MAIL_USER (the Gmail address), MAIL_PASS (16-character App Password), MAIL_FROM_NAME.
require_once __DIR__ . '/config.php';
if (!defined('MAIL_USER')) define('MAIL_USER', env('MAIL_USER'));
if (!defined('MAIL_PASS')) define('MAIL_PASS', env('MAIL_PASS'));
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'UCLM LostLink'));

/** Send a plain-text email through Gmail SMTP (STARTTLS, port 587). Returns true on success. No libraries needed. */
function send_mail(string $to, string $subject, string $body): bool {
  if (MAIL_USER === '' || MAIL_PASS === '') return false;   // not configured yet
  $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
  $fp = @stream_socket_client('tcp://smtp.gmail.com:587', $en, $es, 15, STREAM_CLIENT_CONNECT, $ctx);
  if (!$fp) { error_log("LostLink mail: connect failed $es"); return false; }
  stream_set_timeout($fp, 15);
  $read = function () use ($fp) { $r = ''; while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
  $send = function (string $c, string $ok) use ($fp, $read) { fwrite($fp, $c . "\r\n"); $r = $read(); return str_starts_with($r, $ok); };
  try {
    if (!str_starts_with($read(), '220')) throw new Exception('greeting');
    if (!$send('EHLO localhost', '250')) throw new Exception('ehlo');
    if (!$send('STARTTLS', '220')) throw new Exception('starttls');
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new Exception('tls');
    if (!$send('EHLO localhost', '250')) throw new Exception('ehlo2');
    if (!$send('AUTH LOGIN', '334')) throw new Exception('auth');
    if (!$send(base64_encode(MAIL_USER), '334')) throw new Exception('user');
    if (!$send(base64_encode(MAIL_PASS), '235')) throw new Exception('password rejected');
    if (!$send('MAIL FROM:<' . MAIL_USER . '>', '250')) throw new Exception('from');
    if (!$send('RCPT TO:<' . $to . '>', '250')) throw new Exception('rcpt');
    if (!$send('DATA', '354')) throw new Exception('data');
    $headers = 'From: =?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?= <' . MAIL_USER . ">\r\n"
             . "To: <$to>\r\n"
             . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
             . 'Date: ' . date('r') . "\r\n"
             . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
    $msg = $headers . "\r\n" . chunk_split(base64_encode($body));
    if (!$send($msg . "\r\n.", '250')) throw new Exception('send');
    $send('QUIT', '221');
    fclose($fp);
    return true;
  } catch (Throwable $e) {
    error_log('LostLink mail: ' . $e->getMessage());
    @fclose($fp);
    return false;
  }
}
