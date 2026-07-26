<?php
/**
 * Test de NON-RÉGRESSION de l'API partenaire (api.php), après les lots 1 à 5.
 *
 * api.php n'a pas été modifié d'un octet, mais il inclut src/core/config.php qui,
 * lui, a changé. « Le fichier n'a pas bougé » ne prouve donc rien : ce test
 * exécute le vrai api.php contre une vraie base et vérifie que chaque endpoint
 * répond toujours comme avant.
 */
const PHP = 'C:/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe';
const CIPHER_KEY_TEST = 'clé de test 32 octets............';
const API_USER  = 'fer_test';
const API_TOKEN = 'token_de_test_1234567890';

function enc(string $d): string { return base64_encode('E:' . $d); }

$ok = 0; $ko = 0;
function verifie(string $titre, bool $cond, string $detail = ''): void {
    global $ok, $ko;
    if ($cond) { $ok++; echo "  OK   $titre\n"; }
    else       { $ko++; echo "  ECHEC $titre" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

/* ── Base neuve, schéma issu de install.php ──────────────────────────────── */
$srv = new PDO('mysql:host=127.0.0.1;port=3399', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$srv->exec('DROP DATABASE IF EXISTS fer_apiclassique');
$srv->exec('CREATE DATABASE fer_apiclassique DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo = new PDO('mysql:host=127.0.0.1;port=3399;dbname=fer_apiclassique', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$install = file_get_contents('W:/FER/install.php');
preg_match('/function getCreateTableStatements\(\): array\s*\{(.*?)\n\}/s', $install, $m);
foreach (eval($m[1]) as $sql) $pdo->exec($sql);

/* Les données d'amorçage de install.php, et pas seulement les tables : la table
   `forms` décrit les champs du formulaire, et c'est elle que consulte
   regcore_createRegistration() pour bâtir son INSERT. Sans elle, la création
   d'un inscrit échoue — mais pour une raison propre au banc de test, pas à
   api.php. Autant partir du même état qu'une installation réelle. */
preg_match('/function getDefaultInserts\(\): array\s*\{(.*?)\n\}/s', $install, $mi);
foreach (eval($mi[1]) as $sql) $pdo->exec($sql);
$pdo->prepare('UPDATE setting SET api_enabled = 1, api_user = ?, api_token = ? WHERE id = 1')
    ->execute([API_USER, enc(API_TOKEN)]);

$ins = $pdo->prepare('INSERT INTO registrations (inscription_no, nom, prenom, email, naissance, sexe,
                                                 ville, tshirt_size, paiement_mode, montant_du)
                      VALUES (?,?,?,?,?,?,?,?,?,?)');
$ins->execute(['FER-2026-001', enc('Durand'), enc('Marie'), enc('marie@exemple.fr'), enc('34'), 'F',
               enc('Forbach'), 'M', 'en ligne (CB)', 12.00]);
$ins->execute(['FER-2026-002', enc('Petit'), enc('Louis'), enc('louis@exemple.fr'), enc('12'), 'H',
               enc('Stiring'), 'S', 'gratuit', 0.00]);

/* ── Appel ───────────────────────────────────────────────────────────────── */
function api(string $methode, string $endpoint, string $query = '', ?array $corps = null,
             array $env = []): array
{
    $env += ['FER_TEST_APIUSER' => API_USER, 'FER_TEST_APITOKEN' => API_TOKEN];
    foreach ($env as $k => $v) putenv("$k=$v");

    $cmd = escapeshellarg(PHP) . ' ' . escapeshellarg(__DIR__ . '/test-api-classique-appel.php')
         . ' ' . escapeshellarg($methode) . ' ' . escapeshellarg($endpoint)
         . ' ' . escapeshellarg($query)
         // base64 : escapeshellarg() supprime les guillemets doubles sous Windows.
         . ' ' . escapeshellarg($corps === null ? '' : base64_encode(json_encode($corps, JSON_UNESCAPED_UNICODE)))
         . ' 2>&1';
    $sortie = shell_exec($cmd) ?? '';
    foreach ($env as $k => $v) putenv($k);

    $http = preg_match('/^HTTP (\d+)$/m', $sortie, $m) ? (int) $m[1] : 0;
    $json = preg_replace('/^HTTP \d+\n/m', '', $sortie);
    return ['http' => $http, 'json' => json_decode(trim($json), true), 'brut' => $sortie];
}

echo "\n=== Authentification ===\n";
$r = api('GET', 'ping');
verifie('ping authentifié → 200', $r['http'] === 200 && ($r['json']['ok'] ?? false), $r['brut']);
verifie('identifiant renvoyé', ($r['json']['api_user'] ?? '') === API_USER);

$r = api('GET', 'ping', '', null, ['FER_TEST_APITOKEN' => 'mauvais_token']);
verifie('mauvais token → 401 unauthorized',
    $r['http'] === 401 && ($r['json']['error'] ?? '') === 'unauthorized', $r['brut']);

$r = api('GET', 'ping', '', null, ['FER_TEST_APITOKEN' => ' ']);
verifie('token absent → 401', $r['http'] === 401, $r['brut']);

$r = api('GET', 'ping', '', null, ['FER_TEST_IP' => '203.0.113.9']);
verifie('HTTP en clair depuis l\'extérieur → 403 https_required',
    $r['http'] === 403 && ($r['json']['error'] ?? '') === 'https_required', $r['brut']);

echo "\n=== Interrupteur ===\n";
$pdo->exec('UPDATE setting SET api_enabled = 0');
$r = api('GET', 'ping');
verifie('API désactivée → refus', ($r['json']['ok'] ?? true) === false, $r['brut']);
$pdo->exec('UPDATE setting SET api_enabled = 1');

echo "\n=== Phase 2 : ajouter un inscrit ===\n";
$r = api('POST', 'registration', '', [
    'nom' => 'Nouveau', 'prenom' => 'Jean', 'email' => 'jean@exemple.fr',
    'sexe' => 'H', 'naissance' => '40', 'ville' => 'Forbach',
    'tshirt_size' => 'L', 'send_mail' => false,
]);
verifie('inscrit créé', ($r['json']['ok'] ?? false) === true, $r['brut']);
$nb = (int) $pdo->query('SELECT COUNT(*) FROM registrations')->fetchColumn();
verifie('3 inscrits en base', $nb === 3, "compte = $nb");

$r = api('GET', 'registration', 'endpoint=registration');
verifie('recherche sans critère → 400', $r['http'] === 400, $r['brut']);

echo "\n=== Phase 3 : consulter ===\n";
/* Les critères acceptés sont nom, prenom et email — PAS le numéro d'inscription.
   Les champs étant chiffrés en base, la recherche se fait après déchiffrement,
   en PHP : c'est la contrainte du chiffrement, elle est assumée. */
$r = api('GET', 'registration', 'nom=Durand');
verifie('recherche par nom → trouvée', ($r['json']['ok'] ?? false) === true, $r['brut']);
verifie('données déchiffrées à la lecture',
    ($r['json']['results'][0]['nom'] ?? '') === 'Durand', json_encode($r['json']));

$r = api('GET', 'registration', 'email=louis@exemple.fr');
verifie('recherche par email → trouvée',
    ($r['json']['results'][0]['prenom'] ?? '') === 'Louis', $r['brut']);

$r = api('GET', 'registration', 'nom=Inexistant');
verifie('recherche sans résultat → 404 not_found',
    $r['http'] === 404 && ($r['json']['error'] ?? '') === 'not_found', $r['brut']);

$r = api('GET', 'registrations');
verifie('liste des inscrits', ($r['json']['ok'] ?? false) === true, $r['brut']);
verifie('3 lignes listées', ($r['json']['count'] ?? 0) === 3, json_encode($r['json']['count'] ?? null));

/* Les filtres facultatifs de la liste : ?sexe=F, ?tshirt_size=M, ?ville=… */
$r = api('GET', 'registrations', 'sexe=F');
verifie('filtre ?sexe=F → 1 seule ligne', ($r['json']['count'] ?? -1) === 1, $r['brut']);
verifie('filtre signalé dans la réponse', !empty($r['json']['filters']));

$r = api('GET', 'registrations', 'year=1999');
verifie('archive inexistante → 404 year_not_found',
    $r['http'] === 404 && ($r['json']['error'] ?? '') === 'year_not_found', $r['brut']);

$r = api('GET', 'stats');
verifie('statistiques', ($r['json']['ok'] ?? false) === true, $r['brut']);

$r = api('GET', 'years');
verifie('années disponibles', ($r['json']['ok'] ?? false) === true, $r['brut']);

echo "\n=== Erreurs ===\n";
$r = api('GET', 'nimportequoi');
verifie('endpoint inconnu → 404',
    $r['http'] === 404 && ($r['json']['error'] ?? '') === 'unknown_endpoint', $r['brut']);
$r = api('GET', '');
verifie('endpoint absent → 400', $r['http'] === 400, $r['brut']);
$r = api('GET', 'import');
verifie('import en GET → 405 method_not_allowed',
    $r['http'] === 405 && ($r['json']['error'] ?? '') === 'method_not_allowed', $r['brut']);

echo "\n=== Isolation vis-à-vis de l'API mobile ===\n";
/* Le point qui compte : les deux APIs ne partagent aucun identifiant. Le token
   partenaire ne doit rien ouvrir côté coureur, et réciproquement. */
$r = api('GET', 'registrations', '', null, ['FER_TEST_APITOKEN' => 'jeton_de_coureur_quelconque']);
verifie('un jeton de coureur n\'ouvre pas l\'API partenaire', $r['http'] === 401, $r['brut']);

echo "\n=== Journal ===\n";
/* api.php journalise dans dirname(__FILE__) . '/storage/logs/api.log'. Sous
   eval(), __DIR__ vaut le dossier du pilote : le journal atterrit donc dans
   docs/storage/. On vérifie qu'il est bien écrit, puis on efface — un banc de
   test ne laisse pas de traces dans le projet. */
$logTest = __DIR__ . '/storage/logs/api.log';
verifie('appels journalisés par api.php',
    is_file($logTest) && str_contains((string) file_get_contents($logTest), 'ping'));

@unlink($logTest);
@rmdir(__DIR__ . '/storage/logs');
@rmdir(__DIR__ . '/storage');

echo "\n" . str_repeat('─', 60) . "\n";
echo ($ko === 0 ? "TOUT EST VERT" : "$ko ÉCHEC(S)") . " — $ok test(s) réussi(s), $ko en échec.\n";
exit($ko === 0 ? 0 : 1);
