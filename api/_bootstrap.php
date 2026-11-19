<?php
declare(strict_types=1);
/**
 * Wspólna konfiguracja back-endu PatrykLab.
 * Dane trzymane w plikach JSON w api/_data (katalog zablokowany .htaccess) – działa na każdym hostingu bez bazy danych.
 */
const NOTIFY_EMAIL = 'kontakt@patryklab.pl';   // TODO: adres, na który mają przychodzić zapytania
const MAIL_FROM    = 'no-reply@patryklab.pl';  // TODO: adres w Twojej domenie (wymagany przez większość hostingów)
const DATA_DIR     = __DIR__ . '/_data';
const UPLOAD_DIR   = __DIR__ . '/../uploads';

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function store_read(string $name, $default = []) {
    $f = DATA_DIR . "/$name.json";
    if (!is_file($f)) {
        $seed = DATA_DIR . "/$name.seed.json";
        return is_file($seed) ? json_decode((string)file_get_contents($seed), true) : $default;
    }
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : $default;
}

function store_write(string $name, $data): void {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
    $f = DATA_DIR . "/$name.json";
    $tmp = $f . '.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, $f); // zapis atomowy
}

function client_ip_hash(): string {
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '0') . '|patryklab');
}

function clean(string $s, int $max): string {
    $s = trim(strip_tags($s));
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
    return mb_substr($s, 0, $max);
}
