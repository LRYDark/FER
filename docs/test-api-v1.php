<?php
/**
 * Test fonctionnel de l'API mobile (lot 5) contre une vraie base MySQL.
 *
 * Scénario du terrain : Marie s'inscrit, installe l'application, consulte son
 * dossard, corrige son âge, puis change d'adresse email. On vérifie aussi ce qui
 * DOIT échouer — c'est là que se cachent les vraies failles.
 */
const PHP = 'C:/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe';
const CIPHER_KEY_TEST = 'clé de test 32 octets............';

function enc(string $d): string { return base64_encode('E:' . $d); }
function hmac(string $e): string { return hash_hmac('sha256', mb_strtolower(trim($e)), CIPHER_KEY_TEST); }

$ok = 0; $ko = 0;
function verifie(string $titre, bool $condition, string $detail = ''): void {
    global $ok, $ko;
    if ($condition) { $ok++; echo "  OK   $titre\n"; }
    else            { $ko++; echo "  ECHEC $titre" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

/* ── Base neuve ──────────────────────────────────────────────────────────── */
$srv = new PDO('mysql:host=127.0.0.1;port=3399', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$srv->exec('DROP DATABASE IF EXISTS fer_api');
$srv->exec('CREATE DATABASE fer_api DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo = new PDO('mysql:host=127.0.0.1;port=3399;dbname=fer_api', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// Schéma issu de install.php : c'est celui qui sera réellement déployé.
$install = file_get_contents('W:/FER/install.php');
preg_match('/function getCreateTableStatements\(\): array\s*\{(.*?)\n\}/s', $install, $m);
foreach (eval($m[1]) as $sql) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS `(setting|users|editions|participants|participant_auth_codes'
                 . '|participant_devices|participant_registrations|registration_transfers|registrations'
                 . '|resultats|content_logs)`/', $sql)) {
        $pdo->exec($sql);
    }
}
$pdo->exec('INSERT INTO setting (id) VALUES (1)');
$pdo->exec("INSERT INTO editions (annee, libelle, is_active, date_course) VALUES (2026, 'FER 2026', 1, '2026-10-04')");
$pdo->exec("INSERT INTO editions (annee, libelle, is_active) VALUES (2024, 'FER 2024', 0)");

// Archive 2024 : structure identique, LECTURE SEULE.
$pdo->exec('CREATE TABLE registrations_2024 LIKE registrations');

$ins = $pdo->prepare('INSERT INTO registrations (inscription_no, nom, prenom, email, naissance, sexe, ville,
                                                 tshirt_size, paiement_mode, montant_du)
                      VALUES (?,?,?,?,?,?,?,?,?,?)');
$ins->execute(['FER-2026-001', enc('Durand'), enc('Marie'), enc('marie@exemple.fr'), enc('34'), 'F',
               enc('Forbach'), 'M', 'en ligne (CB)', 12.00]);
$ins->execute(['FER-2026-002', enc('Petit'), enc('Louis'), enc('autre@exemple.fr'), enc('12'), 'H',
               enc('Forbach'), 'S', 'gratuit', 0.00]);
$pdo->prepare('INSERT INTO registrations_2024 (inscription_no, nom, prenom, email, naissance, sexe)
               VALUES (?,?,?,?,?,?)')
    ->execute(['FER-2024-050', enc('Durand'), enc('Marie'), enc('marie@exemple.fr'), enc('32'), 'F']);

@unlink(__DIR__ . '/../mails-test.jsonl');
@unlink(__DIR__ . '/../codes-test.txt');

/* ── Appel de l'API ──────────────────────────────────────────────────────── */
function api(string $methode, string $chemin, ?array $corps = null, string $bearer = '',
             array $env = []): array
{
    // Le pilote lit l'IP, le HTTPS et la version d'application dans
    // l'environnement : c'est ce qui permet de tester le contrôle d'entrée.
    // putenv() modifie l'environnement du processus, dont hérite l'enfant.
    foreach ($env as $k => $v) putenv("$k=$v");

    $cmd = escapeshellarg(PHP) . ' ' . escapeshellarg(__DIR__ . '/test-api-v1-appel.php')
         . ' ' . escapeshellarg($methode) . ' ' . escapeshellarg($chemin)
         // base64 : escapeshellarg() supprime les guillemets doubles sous Windows.
         . ' ' . escapeshellarg($corps === null ? '' : base64_encode(json_encode($corps, JSON_UNESCAPED_UNICODE)))
         . ' ' . escapeshellarg($bearer) . ' 2>&1';
    $sortie = shell_exec($cmd) ?? '';
    foreach ($env as $k => $v) putenv($k);          // remise à zéro pour l'appel suivant
    $http   = preg_match('/^HTTP (\d+)$/m', $sortie, $m) ? (int) $m[1] : 0;
    $json   = preg_replace('/^HTTP \d+\n/m', '', $sortie);
    $data   = json_decode(trim($json), true);
    return ['http' => $http, 'json' => $data, 'brut' => $sortie];
}

function dernierCode(string $email): ?string
{
    $f = __DIR__ . '/../codes-test.txt';
    if (!is_file($f)) return null;
    $trouve = null;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        [$to, $code] = explode(' ', $l, 2);
        if (mb_strtolower($to) === mb_strtolower($email)) $trouve = trim($code);
    }
    return $trouve;
}

echo "\n=== 0. Contrôle d'entrée : HTTPS, interrupteur, version ===\n";
/* L'API est FERMÉE par défaut après migration : c'est le comportement voulu. */
$r = api('GET', '/app/config');
verifie('API fermée par défaut → 503 api_disabled',
    $r['http'] === 503 && ($r['json']['error']['code'] ?? '') === 'api_disabled', $r['brut']);

$pdo->exec('UPDATE setting SET api_v1_enabled = 1');

/* HTTPS : refusé hors boucle locale. */
$r = api('GET', '/me', null, '', ['FER_TEST_IP' => '203.0.113.7']);
verifie('HTTP en clair depuis l\'extérieur → 403 https_required',
    $r['http'] === 403 && ($r['json']['error']['code'] ?? '') === 'https_required', $r['brut']);
$r = api('GET', '/app/config', null, '', ['FER_TEST_IP' => '203.0.113.7', 'FER_TEST_HTTPS' => 'on']);
verifie('la même requête en HTTPS passe', $r['http'] === 200, $r['brut']);

/* Version minimale : imposée par le serveur, pas par la bonne volonté du client. */
$pdo->exec("UPDATE setting SET app_version_minimale = '2.0.0'");
$r = api('GET', '/me', null, '', ['FER_TEST_APP_VERSION' => '1.4.9']);
verifie('application périmée → 426 app_outdated',
    $r['http'] === 426 && ($r['json']['error']['code'] ?? '') === 'app_outdated', $r['brut']);
verifie('le refus indique la version exigée',
    ($r['json']['error']['version_minimale'] ?? '') === '2.0.0');
verifie('le refus indique où se renseigner',
    str_contains((string) ($r['json']['error']['config_url'] ?? ''), '/api/v1/app/config'));

$r = api('GET', '/app/config', null, '', ['FER_TEST_APP_VERSION' => '1.4.9']);
verifie('/app/config reste joignable pour une application périmée',
    $r['http'] === 200 && ($r['json']['data']['version_minimale'] ?? '') === '2.0.0', $r['brut']);

$r = api('GET', '/me', null, '', ['FER_TEST_APP_VERSION' => '']);
verifie('version non annoncée → 400 missing_app_version',
    $r['http'] === 400 && ($r['json']['error']['code'] ?? '') === 'missing_app_version', $r['brut']);

$r = api('GET', '/me', null, '', ['FER_TEST_APP_VERSION' => '2.0.0']);
verifie('version suffisante → on repasse à l\'authentification', $r['http'] === 401, $r['brut']);

$pdo->exec("UPDATE setting SET app_version_minimale = '1.0.0'");

echo "\n=== 1. Configuration publique (sans jeton) ===\n";
$r = api('GET', '/app/config');
verifie('/app/config répond 200', $r['http'] === 200, $r['brut']);
verifie('version minimale exposée', ($r['json']['data']['version_minimale'] ?? '') === '1.0.0');

echo "\n=== 2. Accès refusé sans jeton ===\n";
$r = api('GET', '/me');
verifie('/me sans jeton → 401', $r['http'] === 401 && ($r['json']['error']['code'] ?? '') === 'missing_token', $r['brut']);
$r = api('GET', '/me', null, 'nimportequoi.signature');
verifie('jeton bidon → 401 invalid_token', $r['http'] === 401 && ($r['json']['error']['code'] ?? '') === 'invalid_token');

echo "\n=== 3. Connexion ===\n";
$r = api('POST', '/auth/request-code', ['email' => 'marie@exemple.fr']);
verifie('demande de code acceptée', $r['http'] === 200 && ($r['json']['ok'] ?? false), $r['brut']);
$r2 = api('POST', '/auth/request-code', ['email' => 'inconnu@exemple.fr']);
verifie('réponse identique pour une adresse inconnue (anti-énumération)',
    ($r2['json']['data']['message'] ?? '') === ($r['json']['data']['message'] ?? 'x'));
verifie('aucun mail envoyé à l\'adresse inconnue', dernierCode('inconnu@exemple.fr') === null);

$code = dernierCode('marie@exemple.fr');
verifie('code à 6 chiffres reçu', $code !== null && preg_match('/^\d{6}$/', $code) === 1);

$r = api('POST', '/auth/verify-code', ['email' => 'marie@exemple.fr', 'code' => '000000']);
verifie('mauvais code → 401', $r['http'] === 401, $r['brut']);

$code = dernierCode('marie@exemple.fr');   // le mauvais essai n'a pas consommé le code
$r = api('POST', '/auth/verify-code', [
    'email' => 'marie@exemple.fr', 'code' => $code,
    'device_info' => ['libelle' => 'iPhone de Marie', 'plateforme' => 'iOS 18', 'modele' => 'iPhone 14'],
]);
verifie('bon code → jetons émis', ($r['json']['ok'] ?? false) === true, $r['brut']);
$deviceToken = $r['json']['data']['device_token'] ?? '';
$accessToken = $r['json']['data']['access_token'] ?? '';
verifie('device_token de 64 caractères hexadécimaux', (bool) preg_match('/^[a-f0-9]{64}$/', $deviceToken));
verifie('access_token signé (charge.signature)', substr_count($accessToken, '.') === 1);
verifie('expiration en ISO-8601 avec décalage',
    (bool) preg_match('/[+-]\d{2}:\d{2}$/', (string) ($r['json']['data']['expires_at'] ?? '')));

$r = api('POST', '/auth/verify-code', ['email' => 'marie@exemple.fr', 'code' => $code]);
verifie('code à usage unique : rejoué → refusé', ($r['json']['ok'] ?? true) === false);

echo "\n=== 4. Signature du jeton d'accès ===\n";
[$charge, $sig] = explode('.', $accessToken, 2);
$r = api('GET', '/me', null, $charge . '.' . strrev($sig));
verifie('signature altérée → 401', $r['http'] === 401, $r['brut']);
// Charge utile modifiée (autre appareil) sans re-signer : doit tomber.
$forge = rtrim(strtr(base64_encode(json_encode(['d' => 999, 'e' => time() + 3600])), '+/', '-_'), '=');
$r = api('GET', '/me', null, $forge . '.' . $sig);
verifie('charge utile forgée → 401', $r['http'] === 401, $r['brut']);

echo "\n=== 5. Mes données ===\n";
$r = api('GET', '/me', null, $accessToken);
verifie('/me → 200', $r['http'] === 200, $r['brut']);
verifie('adresse email déchiffrée', ($r['json']['data']['email'] ?? '') === 'marie@exemple.fr');

$r = api('GET', '/me/registrations', null, $accessToken);
$insc = $r['json']['data'] ?? [];
verifie('2 inscriptions rattachées (2026 + archive 2024)', count($insc) === 2, json_encode($insc));
$annees = array_column($insc, 'annee');
verifie('archive 2024 visible en lecture', in_array(2024, $annees, true));
verifie('âge exposé, pas la date de naissance',
    isset($insc[0]['age']) && !array_key_exists('naissance', $insc[0]));

$r = api('GET', '/me/registrations/2026/FER-2026-002', null, $accessToken);
verifie('inscription d\'autrui → 403 (et non 404)',
    $r['http'] === 403 && ($r['json']['error']['code'] ?? '') === 'forbidden', $r['brut']);

$r = api('GET', '/me/registrations/2026/FER-2026-001/qrcode', null, $accessToken);
$png = base64_decode((string) ($r['json']['data']['png_base64'] ?? ''), true);
verifie('QR code renvoyé en PNG', is_string($png) && str_starts_with($png, "\x89PNG"), $r['brut']);

echo "\n=== 6. Corriger son âge et son sexe ===\n";
$r = api('PATCH', '/me/registrations/2026/FER-2026-001', ['age' => 35], $accessToken);
verifie('âge modifié', ($r['json']['ok'] ?? false) === true, $r['brut']);
verifie('âge relu à 35', ($r['json']['data']['inscription']['age'] ?? null) === 35);
$brut = $pdo->query("SELECT naissance FROM registrations WHERE inscription_no='FER-2026-001'")->fetchColumn();
verifie('stocké chiffré, et sous forme d\'ÂGE', $brut === enc('35'), (string) $brut);

$r = api('PATCH', '/me/registrations/2026/FER-2026-001', ['age' => 200], $accessToken);
verifie('âge aberrant → 422', $r['http'] === 422, $r['brut']);
$r = api('PATCH', '/me/registrations/2026/FER-2026-001', ['sexe' => 'X'], $accessToken);
verifie('sexe invalide → 422', $r['http'] === 422, $r['brut']);

$r = api('PATCH', '/me/registrations/2024/FER-2024-050', ['age' => 99], $accessToken);
verifie('ARCHIVE non modifiable → 422', $r['http'] === 422, $r['brut']);
$archive = $pdo->query("SELECT naissance FROM registrations_2024 WHERE inscription_no='FER-2024-050'")->fetchColumn();
verifie('archive intacte après la tentative', $archive === enc('32'));

$r = api('PATCH', '/me/registrations/2026/FER-2026-002', ['age' => 40], $accessToken);
verifie('inscription d\'autrui non modifiable', ($r['json']['ok'] ?? true) === false, $r['brut']);

echo "\n=== 7. Nom et prénom ===\n";
$r = api('PATCH', '/me', ['nom' => 'Durand-Martin', 'prenom' => 'Marie'], $accessToken);
verifie('identité enregistrée', ($r['json']['ok'] ?? false) === true, $r['brut']);
$n = $pdo->query("SELECT nom FROM registrations WHERE inscription_no='FER-2026-001'")->fetchColumn();
verifie('répercuté sur l\'inscription en cours', $n === enc('Durand-Martin'), (string) $n);
$a = $pdo->query("SELECT nom FROM registrations_2024 WHERE inscription_no='FER-2024-050'")->fetchColumn();
verifie('archive 2024 NON touchée', $a === enc('Durand'), (string) $a);

$r = api('PATCH', '/me', ['nom' => '<script>', 'prenom' => 'Marie'], $accessToken);
verifie('caractères interdits → 422', $r['http'] === 422, $r['brut']);

echo "\n=== 8. Changement d'adresse email ===\n";
$r = api('POST', '/me/email/request-change', ['email' => 'marie@exemple.fr'], $accessToken);
verifie('adresse identique → refusée', $r['http'] === 422, $r['brut']);

$r = api('POST', '/me/email/request-change', ['email' => 'marie.nouvelle@exemple.fr'], $accessToken);
verifie('code envoyé à la nouvelle adresse', ($r['json']['ok'] ?? false) === true, $r['brut']);
verifie('le code part bien à la NOUVELLE adresse', dernierCode('marie.nouvelle@exemple.fr') !== null);

$r = api('POST', '/me/email/confirm',
    ['email' => 'marie.nouvelle@exemple.fr', 'code' => '000000'], $accessToken);
verifie('mauvais code → refus', ($r['json']['ok'] ?? true) === false, $r['brut']);

$r = api('POST', '/me/email/confirm',
    ['email' => 'marie.nouvelle@exemple.fr', 'code' => dernierCode('marie.nouvelle@exemple.fr')], $accessToken);
verifie('changement appliqué', ($r['json']['ok'] ?? false) === true, $r['brut']);

$h = $pdo->query('SELECT email_hmac FROM participants LIMIT 1')->fetchColumn();
verifie('empreinte du compte mise à jour', $h === hmac('marie.nouvelle@exemple.fr'));
$e = $pdo->query("SELECT email FROM registrations WHERE inscription_no='FER-2026-001'")->fetchColumn();
verifie('inscription en cours mise à jour', $e === enc('marie.nouvelle@exemple.fr'), (string) $e);
$e24 = $pdo->query("SELECT email FROM registrations_2024 WHERE inscription_no='FER-2024-050'")->fetchColumn();
verifie('archive 2024 conserve l\'ancienne adresse', $e24 === enc('marie@exemple.fr'));

$mails = array_map(fn($l) => json_decode($l, true), file(__DIR__ . '/../mails-test.jsonl',
    FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
$prevenue = array_filter($mails, fn($m) => $m['to'] === 'marie@exemple.fr'
    && str_contains($m['subject'], 'modifiée'));
verifie('ancienne adresse prévenue du changement', count($prevenue) === 1, json_encode($mails));

verifie('les appareils restent connectés après le changement',
    (int) $pdo->query('SELECT COUNT(*) FROM participant_devices WHERE revoque_at IS NULL')->fetchColumn() === 1);

echo "\n=== 8 bis. Transfert depuis l'application ===\n";
$r = api('POST', '/me/transfers',
    ['annee' => 2026, 'inscription_no' => 'FER-2026-001', 'email_cible' => 'louis@exemple.fr'], $accessToken);
verifie('demande de transfert créée (201)', $r['http'] === 201 && ($r['json']['ok'] ?? false), $r['brut']);
$idXfer = $r['json']['data']['id'] ?? 0;

$mails = array_map(fn($l) => json_decode($l, true), file(__DIR__ . '/../mails-test.jsonl',
    FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
verifie('la cible a reçu un mail', (bool) array_filter($mails, fn($m) => $m['to'] === 'louis@exemple.fr'));

$r = api('GET', '/me/transfers', null, $accessToken);
verifie('transfert listé en attente',
    ($r['json']['data'][0]['statut'] ?? '') === 'en_attente'
    && ($r['json']['data'][0]['email_cible'] ?? '') === 'louis@exemple.fr', $r['brut']);

$r = api('POST', '/me/transfers',
    ['annee' => 2026, 'inscription_no' => 'FER-2026-002', 'email_cible' => 'x@exemple.fr'], $accessToken);
verifie('transfert d\'une inscription d\'autrui refusé', ($r['json']['ok'] ?? true) === false, $r['brut']);

$r = api('DELETE', '/me/transfers/' . (int) $idXfer, null, $accessToken);
verifie('annulation acceptée', ($r['json']['ok'] ?? false) === true, $r['brut']);
verifie('statut passé à « annule »',
    $pdo->query('SELECT statut FROM registration_transfers WHERE id = ' . (int) $idXfer)->fetchColumn() === 'annule');

echo "\n=== 9. Renouvellement et révocation ===\n";
$r = api('POST', '/auth/refresh', ['device_token' => $deviceToken]);
verifie('renouvellement du jeton d\'accès', ($r['json']['ok'] ?? false) === true, $r['brut']);
$nouveauAcces = $r['json']['data']['access_token'] ?? '';

$r = api('GET', '/me/devices', null, $nouveauAcces);
$dev = $r['json']['data'] ?? [];
verifie('1 appareil listé, marqué « courant »',
    count($dev) === 1 && ($dev[0]['courant'] ?? false) === true, json_encode($dev));
verifie('libellé transmis à la connexion conservé', ($dev[0]['libelle'] ?? '') === 'iPhone de Marie');

/* Le point crucial : après révocation, le jeton d'accès reste
   cryptographiquement valide — c'est la revérification en base qui doit le
   rejeter. Sans elle, l'accès survivrait jusqu'à une heure. */
$pdo->exec('UPDATE participant_devices SET revoque_at = NOW()');
$r = api('GET', '/me', null, $nouveauAcces);
verifie('révocation IMMÉDIATE malgré un jeton non expiré',
    $r['http'] === 401 && ($r['json']['error']['code'] ?? '') === 'device_revoked', $r['brut']);
$r = api('POST', '/auth/refresh', ['device_token' => $deviceToken]);
verifie('renouvellement refusé après révocation', $r['http'] === 401, $r['brut']);

echo "\n=== 10. Compte désactivé par l'administration ===\n";
$pdo->exec('UPDATE participant_devices SET revoque_at = NULL');
$pdo->exec('UPDATE participants SET is_active = 0');
$r = api('POST', '/auth/refresh', ['device_token' => $deviceToken]);
verifie('compte désactivé → 403', $r['http'] === 403 && ($r['json']['error']['code'] ?? '') === 'account_disabled', $r['brut']);

echo "\n=== 11. Méthodes et chemins ===\n";
$pdo->exec('UPDATE participants SET is_active = 1');
$r = api('POST', '/auth/refresh', ['device_token' => $deviceToken]);
$acces = $r['json']['data']['access_token'] ?? '';
$r = api('DELETE', '/me', null, $acces);
verifie('méthode non gérée sur /me → 404 ou 405', in_array($r['http'], [404, 405], true), $r['brut']);
$r = api('GET', '/nimportequoi', null, $acces);
verifie('chemin inconnu → 404', $r['http'] === 404, $r['brut']);
$r = api('GET', '/editions', null, $acces);
verifie('éditions listées', count($r['json']['data'] ?? []) === 2, $r['brut']);

echo "\n=== 12. Journal ===\n";
/* Le code réel écrit dans dirname(__DIR__, 2) . '/storage/logs'. Sous eval(),
   __DIR__ vaut le dossier du pilote : les journaux atterrissent donc à la racine
   du disque de test, pas dans le projet. On vérifie là où ils sont réellement
   allés — et on nettoie derrière soi. */
$racineTest = dirname(realpath(__DIR__ . '/..'), 1);
$logApi     = $racineTest . '/storage/logs/logs_api_mobile.log';
$logEspace  = $racineTest . '/storage/logs/logs_espace_coureur.log';
verifie('journal des appels /auth alimenté',
    is_file($logApi) && str_contains((string) file_get_contents($logApi), 'verify-code OK'));
verifie('journal de l\'espace coureur alimenté',
    is_file($logEspace) && str_contains((string) file_get_contents($logEspace), 'Adresse email modifiée'));

@unlink($logApi); @unlink($logEspace);
@rmdir($racineTest . '/storage/logs'); @rmdir($racineTest . '/storage');
@unlink(__DIR__ . '/../mails-test.jsonl');
@unlink(__DIR__ . '/../codes-test.txt');

echo "\n" . str_repeat('─', 60) . "\n";
echo ($ko === 0 ? "TOUT EST VERT" : "$ko ÉCHEC(S)") . " — $ok test(s) réussi(s), $ko en échec.\n";
exit($ko === 0 ? 0 : 1);
