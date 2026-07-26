<?php
/**
 * Pilote d'appel de l'API mobile, pour docs/test-api-v1.php.
 *
 * Une requête = un processus, parce que les points d'entrée se terminent par
 * exit(). C'est le VRAI routeur de api/v1/index.php qui est exécuté ici, pas une
 * réécriture : un test qui reproduirait la logique ne prouverait rien.
 *
 * Usage : php test-api-v1-appel.php <METHODE> <CHEMIN> [<corps JSON en base64>] [<Bearer>]
 *
 * ⚠️ Le corps arrive en base64 : sous Windows, escapeshellarg() REMPLACE les
 * guillemets doubles par des espaces. Un JSON passé tel quel arrive détruit, et
 * l'API répond « invalid_json » sur tous les appels.
 */
const DSN = 'mysql:host=127.0.0.1;port=3399;dbname=fer_api';
const CIPHER_KEY_TEST = 'clé de test 32 octets............';

/* ── Doublures minimales du socle (config.php n'est pas chargé) ──────────── */
define('CIPHER_KEY', CIPHER_KEY_TEST);
function encrypt(?string $d): ?string { return $d === null || $d === '' ? $d : base64_encode('E:' . $d); }
function decrypt(?string $d): ?string {
    if ($d === null || $d === '') return $d;
    $r = base64_decode($d, true);
    return ($r !== false && str_starts_with($r, 'E:')) ? substr($r, 2) : $d;
}
function decryptRow(array $r): array {
    foreach (['nom','prenom','email','naissance','ville','tel','entreprise'] as $c) {
        if (array_key_exists($c, $r)) $r[$c] = decrypt($r[$c]);
    }
    return $r;
}
function fer_normalizeEmail(?string $e): string { return mb_strtolower(trim((string) $e), 'UTF-8'); }
function fer_emailHmac(?string $e): ?string {
    $n = fer_normalizeEmail($e);
    return $n === '' ? null : hash_hmac('sha256', $n, CIPHER_KEY_TEST);
}
function fer_client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '10.0.0.1'; }
function getAppBaseUrl(): string { return 'https://exemple.test'; }
function logContentAction(...$a): void {}
function currentUserId(): ?int { return null; }
/* Les mails partent dans un fichier : le test vérifie QUI a reçu QUOI. */
function sendMail($to, $subject, ...$r) {
    @file_put_contents(__DIR__ . '/../mails-test.jsonl',
        json_encode(['to' => $to, 'subject' => $subject], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    // Le code à 6 chiffres est extrait du corps pour que le test puisse l'utiliser.
    if (preg_match('/>(\d{6})</', (string) ($r[1] ?? ''), $m)) {
        @file_put_contents(__DIR__ . '/../codes-test.txt', $to . ' ' . $m[1] . "\n", FILE_APPEND);
    }
    return true;
}

$_SESSION = [];
$pdo = new PDO(DSN, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

/* ── Code réel du site ───────────────────────────────────────────────────── */
require_once 'W:/FER/vendor/autoload.php';   // endroid/qr-code, pour le vrai QR

function chargerReel(string $f): void {
    $s = file_get_contents($f);
    $s = preg_replace('/^<\?php/', '', $s, 1);
    $s = preg_replace('#^\s*require(_once)? .*$#m', '', $s);
    eval($s);
}
foreach ([
    'W:/FER/src/content/registrations_core.php',
    'W:/FER/src/core/registrations_resolver.php',
    'W:/FER/src/core/qrcode.php',
    'W:/FER/src/auth/participant_auth.php',
    'W:/FER/src/auth/participant_profile.php',
    'W:/FER/src/content/transfers.php',
] as $f) { chargerReel($f); }

/* ── Simulation de la requête HTTP ───────────────────────────────────────── */
$methode = $argv[1] ?? 'GET';
$chemin  = $argv[2] ?? '/';
$corps   = ($argv[3] ?? '') === '' ? '' : (string) base64_decode($argv[3], true);
$bearer  = $argv[4] ?? '';

$_SERVER['REQUEST_METHOD'] = $methode;
$_SERVER['SCRIPT_NAME']    = '/api/v1/index.php';
$_SERVER['REQUEST_URI']    = '/api/v1' . $chemin;
$_SERVER['REMOTE_ADDR']    = '10.0.0.1';
if ($bearer !== '') $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;

/* Le corps de requête arrive normalement par php://input, illisible en CLI :
   on neutralise sa lecture et on injecte la valeur du test. */
$GLOBALS['CORPS_TEST'] = $corps;

$src = file_get_contents('W:/FER/api/v1/index.php');
$src = preg_replace('/^<\?php/', '', $src, 1);
$src = preg_replace('#^\s*(define\(.FER_NO_SESSION.*|require_once .*)$#m', '', $src);
$src = str_replace("file_get_contents('php://input') ?: ''", '$GLOBALS[\'CORPS_TEST\']', $src);
// http_response_code() n'est pas observable en CLI : on capture le code réel.
$src = str_replace('http_response_code($code);', 'fwrite(STDERR, "HTTP " . $code . "\n");', $src);

eval($src);
