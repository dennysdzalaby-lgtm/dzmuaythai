<?php
// Booking-form email endpoint for shared hosting (cPanel / public_html), sending through Resend.
// 1. Create an API key at https://resend.com and paste it below (or set RESEND_API_KEY in the host's env).
// 2. Verify the sending domain in Resend and set FROM_EMAIL to an address on it, e.g. web@dzmuaythai.es.
// Until the key is set, it sends with the server's own mail() (works on cPanel out of the box), so the
// form is functional from the first upload. Resend takes over automatically once the key is present.
const RESEND_API_KEY = '';                       // TODO paste key: re_xxxxxxxx
const FROM_EMAIL     = 'DZ Muay Thai <web@dzmuaythai.es>';
const TO_EMAIL       = 'dennysdzalaby@gmail.com';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
$key = RESEND_API_KEY ?: getenv('RESEND_API_KEY');

$d = json_decode(file_get_contents('php://input'), true) ?: [];
if (!empty($d['company'])) { echo '{"ok":true}'; exit; }          // honeypot
$name  = trim(substr((string)($d['name'] ?? ''), 0, 120));
$email = trim(substr((string)($d['email'] ?? ''), 0, 200));
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo '{"ok":false,"error":"name and valid email required"}'; exit; }

$rows = [
  ['Nombre', $name], ['Email', $email], ['Teléfono', $d['phone'] ?? ''], ['Objetivo', $d['goal'] ?? ''],
  ['Nota', $d['note'] ?? ''], ['Consejos por email (opt-in)', !empty($d['marketing']) ? 'sí' : 'no'],
  ['Página', $d['page'] ?? ''], ['Idioma', $d['lang'] ?? ''], ['Fecha', gmdate('c')],
];
$rows = array_filter($rows, fn($r) => $r[1] !== '' && $r[1] !== null);
$text = implode("\n", array_map(fn($r) => "$r[0]: $r[1]", $rows));
$esc  = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$html = '<h2>Nueva solicitud desde la web</h2><table>' . implode('', array_map(fn($r) => '<tr><td><b>' . $esc($r[0]) . '</b></td><td>' . $esc($r[1]) . '</td></tr>', $rows)) . '</table>'
      . '<p style="color:#888">Responde a este correo y le llega directamente al alumno.</p>';

$payload = json_encode([
  'from' => FROM_EMAIL, 'to' => [TO_EMAIL], 'reply_to' => $email,
  'subject' => 'Solicitud de primera sesión: ' . $name . ' (' . ($d['goal'] ?? '') . ')',
  'text' => $text, 'html' => $html,
], JSON_UNESCAPED_UNICODE);

if (!$key) {  // fallback: the host's own mail()
  $subject = 'Solicitud de primera sesión: ' . $name . ' (' . ($d['goal'] ?? '') . ')';
  $headers = "From: " . FROM_EMAIL . "\r\nReply-To: " . $email . "\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: dzmuaythai.es";
  $sent = @mail(TO_EMAIL, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $headers);
  if ($sent) { echo '{"ok":true,"via":"mail"}'; } else { http_response_code(502); echo '{"ok":false,"error":"mail() failed"}'; }
  exit;
}

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
  CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
  CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
  CURLOPT_POSTFIELDS => $payload,
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code >= 200 && $code < 300) { echo '{"ok":true}'; }
else { http_response_code(502); echo json_encode(['ok' => false, 'error' => $res ?: 'resend error']); }
