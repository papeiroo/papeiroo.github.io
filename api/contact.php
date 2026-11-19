<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Metoda niedozwolona'], 405);

// Honeypot – boty wypełniają ukryte pole
if (!empty($_POST['website'])) json_out(['ok' => true]);

// Limit: 5 zgłoszeń na godzinę z jednego IP
$rl = store_read('ratelimit', []);
$ip = client_ip_hash();
$now = time();
$rl = array_filter($rl, fn($t) => is_array($t) && count(array_filter($t, fn($x) => $x > $now - 3600)) > 0);
$hits = array_values(array_filter($rl[$ip] ?? [], fn($x) => $x > $now - 3600));
if (count($hits) >= 5) json_out(['ok' => false, 'error' => 'Zbyt wiele wiadomości. Spróbuj za godzinę.'], 429);
$hits[] = $now; $rl[$ip] = $hits; store_write('ratelimit', $rl);

$name    = clean($_POST['name'] ?? '', 120);
$email   = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '';
$phone   = clean($_POST['phone'] ?? '', 40);
$company = clean($_POST['company'] ?? '', 120);
$budget  = clean($_POST['budget'] ?? '', 60);
$message = clean($_POST['message'] ?? '', 5000);
$services = array_map(fn($s) => clean((string)$s, 60), (array)($_POST['services'] ?? []));
$consent = ($_POST['consent'] ?? '') === '1';

if ($name === '' || $email === '' || mb_strlen($message) < 5) json_out(['ok' => false, 'error' => 'Uzupełnij imię, poprawny e-mail i treść wiadomości.'], 422);
if (!$consent) json_out(['ok' => false, 'error' => 'Zaznacz zgodę na przetwarzanie danych.'], 422);

$lead = [
    'id' => bin2hex(random_bytes(6)), 'date' => date('c'), 'read' => false,
    'name' => $name, 'email' => $email, 'phone' => $phone, 'company' => $company,
    'budget' => $budget, 'services' => $services, 'message' => $message,
];
$leads = store_read('leads', []);
array_unshift($leads, $lead);
store_write('leads', $leads);

// Powiadomienie e-mail
$subject = '=?UTF-8?B?' . base64_encode("Nowe zapytanie: $name") . '?=';
$body = "Imię: $name\nE-mail: $email\nTelefon: $phone\nFirma: $company\nBudżet: $budget\nUsługi: " . implode(', ', $services) . "\n\n$message\n";
$headers = "From: PatrykLab <" . MAIL_FROM . ">\r\nReply-To: $email\r\nContent-Type: text/plain; charset=UTF-8\r\n";
@mail(NOTIFY_EMAIL, $subject, $body, $headers);

json_out(['ok' => true]);
