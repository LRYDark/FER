<?php
/**
 * Pilote d'appel de l'API partenaire (api.php), pour docs/test-api-classique.php.
 *
 * BUT : prouver que api.php fonctionne TOUJOURS après les lots 1 à 5. Le fichier
 * n'a pas été touché, mais il inclut src/core/config.php, qui a changé (nouvelles
 * fonctions, garde-fou FER_NO_SESSION). Un raisonnement ne remplace pas un appel
 * réel : c'est le VRAI api.php qui est exécuté ici.
 *
 * Une requête = un processus, parce que api.php se termine par exit().
 *
 * Usage : php test-api-classique-appel.php <METHODE> <endpoint> [<query>] [<corps b64>]
 */
const DSN = 'mysql:host=127.0.0.1;port=3399;dbname=fer_apiclassique';
const CIPHER_KEY_TEST = 'clé de test 32 octets............';

/* ── Doublures du socle (config.php n'est pas chargé) ────────────────────── */
define('CIPHER_KEY', CIPHER_KEY_TEST);
define('PII_FIELDS', ['nom', 'prenom', 'tel', 'email', 'naissance', 'ville', 'entreprise']);

function encrypt(?string $d): ?string { return $d === null || $d === '' ? $d : base64_encode('E:' . $d); }
function decrypt(?string $d): ?string {
    if ($d === null || $d === '') return $d;
    $r = base64_decode($d, true);
    return ($r !== false && str_starts_with($r, 'E:')) ? substr($r, 2) : $d;
}
function encryptRow(array &$data): void {
    foreach (PII_FIELDS as $f) if (array_key_exists($f, $data)) $data[$f] = encrypt($data[$f]);
}
function decryptRow(array $r): array {
    foreach (PII_FIELDS as $f) if (array_key_exists($f, $r)) $r[$f] = decrypt($r[$f]);
    return $r;
}
function decryptRows(array $rows): array { return array_map('decryptRow', $rows); }
function fer_normalizeEmail(?string $e): string { return mb_strtolower(trim((string) $e), 'UTF-8'); }
function fer_emailHmac(?string $e): ?string {
    $n = fer_normalizeEmail($e);
    return $n === '' ? null : hash_hmac('sha256', $n, CIPHER_KEY_TEST);
}
function fer_client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'; }
function getAppBaseUrl(): string { return 'https://exemple.test'; }
function logContentAction(...$a): void {}
function currentUserId(): ?int { return null; }
function checkMaintenance(): void {}
function sendMail(...$a) { return true; }
function writeLog(...$a): void {}
/** Capture du code HTTP : http_response_code() n'est pas lisible en CLI. */
function fer_test_code(int $c): void { fwrite(STDERR, "HTTP $c\n"); }

$_SESSION = [];
$pdo = new PDO(DSN, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$data = [];   // api.php lit $data pour certains réglages

/* ── Requête simulée ─────────────────────────────────────────────────────── */
$methode  = $argv[1] ?? 'GET';
$endpoint = $argv[2] ?? '';
$query    = $argv[3] ?? '';
$corps    = ($argv[4] ?? '') === '' ? '' : (string) base64_decode($argv[4], true);

$_SERVER['REQUEST_METHOD'] = $methode;
$_SERVER['SCRIPT_NAME']    = '/api.php';
$_SERVER['REQUEST_URI']    = '/api.php?endpoint=' . $endpoint . ($query !== '' ? '&' . $query : '');
$_SERVER['REMOTE_ADDR']    = getenv('FER_TEST_IP') ?: '127.0.0.1';   // boucle locale : HTTPS non exigé
parse_str($query, $params);
$_GET = array_merge(['endpoint' => $endpoint], $params);

// Identifiants : fournis sauf si le test demande explicitement le contraire.
$u = getenv('FER_TEST_APIUSER');
$t = getenv('FER_TEST_APITOKEN');
if ($u !== false && $u !== '') $_SERVER['HTTP_X_API_USER']  = $u;
if ($t !== false && $t !== '') $_SERVER['HTTP_X_API_TOKEN'] = $t;

$GLOBALS['CORPS_TEST'] = $corps;

/* ── Dépendances réelles de api.php ──────────────────────────────────────────
 * On les charge ICI parce que le pilote retire les `require` de api.php (ils
 * pointeraient vers le vrai config.php). Sans ce chargement, la création d'un
 * inscrit échouerait sur une fonction manquante — et le test conclurait à tort
 * à une régression de api.php. */
require_once 'W:/FER/vendor/autoload.php';
foreach ([
    'W:/FER/src/content/form_fields.php',
    'W:/FER/src/content/registrations_core.php',
] as $dep) {
    $d = file_get_contents($dep);
    $d = preg_replace('/^<\?php/', '', $d, 1);
    $d = preg_replace('#^\s*require(_once)? .*$#m', '', $d);
    eval($d);
}

$src = file_get_contents('W:/FER/api.php');
$src = preg_replace('/^<\?php/', '', $src, 1);
$src = preg_replace('#^\s*require(_once)? .*$#m', '', $src);
$src = str_replace("file_get_contents('php://input')", '$GLOBALS[\'CORPS_TEST\']', $src);
// http_response_code() n'est pas observable en CLI : on la remplace par une
// fonction de capture. Un remplacement par expression régulière sur l'argument
// ne convient pas — il doit accepter aussi bien un littéral (204) qu'une
// variable ($code).
$src = str_replace('http_response_code(', 'fer_test_code(', $src);

eval($src);
