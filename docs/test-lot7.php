<?php
/**
 * Test du lot 7 : purges de conservation et revue de sécurité.
 *
 * LE POINT SENSIBLE : une purge est irréversible. Un test qui se contenterait de
 * vérifier « ça supprime bien » passerait à côté de l'essentiel — ce qu'elle ne
 * doit SURTOUT PAS supprimer. La moitié de ce banc vérifie donc que les
 * inscriptions, les archives, les comptes actifs et les transferts en attente
 * sortent intacts.
 */
const CIPHER_KEY_TEST = 'clé de test 32 octets............';

function enc(string $d): string { return base64_encode('E:' . $d); }

$ok = 0; $ko = 0;
function verifie(string $titre, bool $cond, string $detail = ''): void {
    global $ok, $ko;
    if ($cond) { $ok++; echo "  OK   $titre\n"; }
    else       { $ko++; echo "  ECHEC $titre" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

/* ── Doublures du socle ──────────────────────────────────────────────────── */
define('CIPHER_KEY', CIPHER_KEY_TEST);
function encrypt(?string $d): ?string { return $d === null || $d === '' ? $d : enc($d); }
function decrypt(?string $d): ?string {
    if ($d === null || $d === '') return $d;
    $r = base64_decode($d, true);
    return ($r !== false && str_starts_with($r, 'E:')) ? substr($r, 2) : $d;
}
function fer_client_ip(): string { return '10.0.0.1'; }

/* ── Base neuve ──────────────────────────────────────────────────────────── */
$srv = new PDO('mysql:host=127.0.0.1;port=3399', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$srv->exec('DROP DATABASE IF EXISTS fer_lot7');
$srv->exec('CREATE DATABASE fer_lot7 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo = new PDO('mysql:host=127.0.0.1;port=3399;dbname=fer_lot7', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$install = file_get_contents('W:/FER/install.php');
preg_match('/function getCreateTableStatements\(\): array\s*\{(.*?)\n\}/s', $install, $m);
foreach (eval($m[1]) as $sql) $pdo->exec($sql);
$pdo->exec('INSERT INTO setting (id) VALUES (1)');
$pdo->exec('CREATE TABLE registrations_2024 LIKE registrations');

/* Chargement du VRAI module de purge. */
$src = file_get_contents('W:/FER/src/content/purges.php');
$src = preg_replace('/^<\?php/', '', $src, 1);
$src = preg_replace('#^\s*require(_once)? .*$#m', '', $src);
eval($src);

/* ── Jeu de données : moitié périmé, moitié à conserver ──────────────────── */
$pdo->exec("INSERT INTO registrations (inscription_no, nom, prenom, email, naissance, sexe)
            VALUES ('R1','" . enc('Dupont') . "','" . enc('Marie') . "','" . enc('m@ex.fr') . "','" . enc('42') . "','F')");
$pdo->exec("INSERT INTO registrations_2024 (inscription_no, nom, prenom, email, naissance, sexe)
            VALUES ('A1','" . enc('Ancien') . "','" . enc('Paul') . "','" . enc('p@ex.fr') . "','" . enc('50') . "','H')");

$pdo->exec("INSERT INTO participants (email_chiffre, email_hmac, is_active)
            VALUES ('" . enc('m@ex.fr') . "', REPEAT('a',64), 1)");
$pid = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO participant_registrations (participant_id, annee, inscription_no)
            VALUES ($pid, " . (int) date('Y') . ", 'R1')");

// Codes : un vieux (à purger), un récent (à garder).
$pdo->exec("INSERT INTO participant_auth_codes (email_hmac, code_hash, expires_at, created_at)
            VALUES (REPEAT('a',64), 'h', NOW(), DATE_SUB(NOW(), INTERVAL 60 DAY))");
$pdo->exec("INSERT INTO participant_auth_codes (email_hmac, code_hash, expires_at, created_at)
            VALUES (REPEAT('a',64), 'h', NOW(), NOW())");

// Appareils : un révoqué depuis longtemps, un révoqué hier, un actif.
$pdo->exec("INSERT INTO participant_devices (participant_id, token_hash, type, revoque_at)
            VALUES ($pid, 't1', 'app', DATE_SUB(NOW(), INTERVAL 200 DAY))");
$pdo->exec("INSERT INTO participant_devices (participant_id, token_hash, type, revoque_at)
            VALUES ($pid, 't2', 'app', DATE_SUB(NOW(), INTERVAL 1 DAY))");
$pdo->exec("INSERT INTO participant_devices (participant_id, token_hash, type)
            VALUES ($pid, 't3', 'app')");

// Traces GPS : une échue par purge_at, une ancienne, une récente.
$pdo->exec("INSERT INTO traces_gps (annee, inscription_no, purge_at)
            VALUES (2024, 'A1', DATE_SUB(CURDATE(), INTERVAL 1 DAY))");
$pdo->exec("INSERT INTO traces_gps (annee, inscription_no, created_at)
            VALUES (2024, 'A1', DATE_SUB(NOW(), INTERVAL 500 DAY))");
$pdo->exec("INSERT INTO traces_gps (annee, inscription_no, created_at) VALUES (2026, 'R1', NOW())");

// Transferts : un ancien accepté, un ancien EN ATTENTE (à ne jamais toucher).
$pdo->exec("INSERT INTO registration_transfers (annee, inscription_no, email_source, email_cible,
                                                token_hash, statut, expires_at, created_at)
            VALUES (2024,'A1','a','b','h1','accepte', NOW(), DATE_SUB(NOW(), INTERVAL 400 DAY))");
$pdo->exec("INSERT INTO registration_transfers (annee, inscription_no, email_source, email_cible,
                                                token_hash, statut, expires_at, created_at)
            VALUES (2024,'A1','a','b','h2','en_attente', NOW(), DATE_SUB(NOW(), INTERVAL 400 DAY))");

/* ── 1. Simulation : elle ne doit RIEN toucher ───────────────────────────── */
echo "\n=== 1. Simulation ===\n";
$avant = [
    'codes'      => (int) $pdo->query('SELECT COUNT(*) FROM participant_auth_codes')->fetchColumn(),
    'appareils'  => (int) $pdo->query('SELECT COUNT(*) FROM participant_devices')->fetchColumn(),
    'traces'     => (int) $pdo->query('SELECT COUNT(*) FROM traces_gps')->fetchColumn(),
    'transferts' => (int) $pdo->query('SELECT COUNT(*) FROM registration_transfers')->fetchColumn(),
];
$sim = purge_run($pdo, true);
verifie('la simulation annonce des lignes à purger', $sim['total'] === 5, 'total = ' . $sim['total']);
verifie('elle n\'a supprimé aucun code',
    (int) $pdo->query('SELECT COUNT(*) FROM participant_auth_codes')->fetchColumn() === $avant['codes']);
verifie('elle n\'a supprimé aucune trace',
    (int) $pdo->query('SELECT COUNT(*) FROM traces_gps')->fetchColumn() === $avant['traces']);

/* ── 2. Purge réelle ─────────────────────────────────────────────────────── */
echo "\n=== 2. Purge réelle ===\n";
$rap = purge_run($pdo, false);
verifie('5 lignes effacées', $rap['total'] === 5, 'total = ' . $rap['total']);
verifie('aucune erreur', empty($rap['erreurs']), implode(' | ', $rap['erreurs']));

verifie('le code périmé est parti, le récent est resté',
    (int) $pdo->query('SELECT COUNT(*) FROM participant_auth_codes')->fetchColumn() === 1);
verifie('l\'appareil révoqué depuis 200 j est parti',
    (int) $pdo->query("SELECT COUNT(*) FROM participant_devices WHERE token_hash = 't1'")->fetchColumn() === 0);
verifie('l\'appareil révoqué hier est CONSERVÉ',
    (int) $pdo->query("SELECT COUNT(*) FROM participant_devices WHERE token_hash = 't2'")->fetchColumn() === 1);
verifie('l\'appareil ACTIF est conservé',
    (int) $pdo->query("SELECT COUNT(*) FROM participant_devices WHERE token_hash = 't3'")->fetchColumn() === 1);
verifie('les 2 traces périmées sont parties, la récente est restée',
    (int) $pdo->query('SELECT COUNT(*) FROM traces_gps')->fetchColumn() === 1);

/* ── 3. CE QUI NE DOIT JAMAIS ÊTRE PURGÉ ─────────────────────────────────── */
echo "\n=== 3. Ce qui ne doit JAMAIS être purgé ===\n";
verifie('l\'inscription de l\'édition en cours est intacte',
    (int) $pdo->query('SELECT COUNT(*) FROM registrations')->fetchColumn() === 1);
verifie('l\'ARCHIVE 2024 est intacte',
    (int) $pdo->query('SELECT COUNT(*) FROM registrations_2024')->fetchColumn() === 1);
verifie('le compte coureur actif est intact',
    (int) $pdo->query('SELECT COUNT(*) FROM participants')->fetchColumn() === 1);
verifie('le rattachement inscription ↔ compte est intact',
    (int) $pdo->query('SELECT COUNT(*) FROM participant_registrations')->fetchColumn() === 1);
verifie('le transfert EN ATTENTE est conservé, même vieux de 400 jours',
    (int) $pdo->query("SELECT COUNT(*) FROM registration_transfers WHERE statut = 'en_attente'")->fetchColumn() === 1);
verifie('le transfert clos de 400 jours est parti',
    (int) $pdo->query("SELECT COUNT(*) FROM registration_transfers WHERE statut = 'accepte'")->fetchColumn() === 0);

/* ── 4. Rejeu : rien de plus à effacer ───────────────────────────────────── */
echo "\n=== 4. Rejeu ===\n";
$rap2 = purge_run($pdo, false);
verifie('un second passage n\'efface plus rien', $rap2['total'] === 0, 'total = ' . $rap2['total']);

/* ── 5. Garde-fou : jamais de durée nulle ────────────────────────────────── */
echo "\n=== 5. Garde-fous des durées ===\n";
$pdo->exec('UPDATE setting SET auth_codes_conservation_jours = 0');
$d = purge_settings($pdo);
verifie('une durée à 0 en base retombe sur le défaut, pas sur « tout effacer »',
    $d['auth_codes_conservation_jours'] === 30, (string) $d['auth_codes_conservation_jours']);
$pdo->exec('UPDATE setting SET auth_codes_conservation_jours = 30');

$pdo->exec('DROP TABLE traces_gps');
$rap3 = purge_run($pdo, true);
verifie('une table absente n\'interrompt pas les autres purges',
    count($rap3['details']) === 4 && count($rap3['erreurs']) === 1, json_encode($rap3['erreurs']));

/* ── 6. Revue de sécurité : les invariants du projet ─────────────────────── */
echo "\n=== 6. Revue de sécurité ===\n";
$lire = fn(string $f) => (string) @file_get_contents('W:/FER/' . $f);

verifie('api.php reste inchangé (aucun commit ne l\'a touché depuis le lot 1)',
    trim((string) shell_exec('cd /d W:\FER && git diff --stat 0f50e0ce..HEAD -- api.php 2>nul')) === '');

// Isolation des sessions : c'est l'invariant central du projet.
$cfg = $lire('src/core/config.php');
verifie('la session coureur porte un nom distinct', str_contains($cfg, "session_name('FERCOUREUR')"));
verifie('l\'API n\'ouvre aucune session', str_contains($cfg, "defined('FER_NO_SESSION')"));

$pagesCoureur = glob('W:/FER/public/espace-coureur/*.php') ?: [];
$sansIsolation = [];
foreach ($pagesCoureur as $p) {
    $s = (string) file_get_contents($p);
    if (str_starts_with(basename($p), '_')) continue;          // fragments inclus
    if (!str_contains($s, "define('FER_SESSION_COUREUR', true)")) $sansIsolation[] = basename($p);
}
verifie('toutes les pages coureur déclarent leur session isolée',
    empty($sansIsolation), implode(', ', $sansIsolation));

// Aucune page coureur ne doit pouvoir consulter la table des administrateurs.
$fuite = [];
foreach ($pagesCoureur as $p) {
    if (preg_match('/\bFROM\s+`?users`?\b/i', (string) file_get_contents($p))) $fuite[] = basename($p);
}
verifie('aucune page coureur ne lit la table `users`', empty($fuite), implode(', ', $fuite));

// Le fichier des purges ne doit jamais viser les inscriptions.
$pur = $lire('src/content/purges.php');
verifie('le module de purge ne cible jamais `registrations`',
    !preg_match('/DELETE FROM\s+`?registrations/i', $pur));

// L'API mobile : contrôle d'entrée avant le routage.
$api = $lire('api/v1/index.php');
verifie('l\'API mobile impose HTTPS', str_contains($api, "'https_required'"));
// Guillemets SIMPLES : en guillemets doubles, PHP interpole $route[0] et la
// chaîne cherchée n'existe nulle part dans le fichier.
verifie('le contrôle d\'entrée précède le routage',
    strpos($api, 'api_gate($pdo, $route);') < strpos($api, '=== \'auth\') {'));
verifie('aucune en-tête CORS permissive',
    !preg_match('/Access-Control-Allow-Origin:\s*\*/i', $api));

// Les fichiers que la consigne interdit de modifier.
foreach (['login.php', 'change-password.php', 'reset-password.php',
          'src/security/totp.php', 'src/security/webauthn.php'] as $intouchable) {
    $diff = trim((string) shell_exec('cd /d W:\FER && git diff --stat 0f50e0ce..HEAD -- '
                                     . escapeshellarg($intouchable) . ' 2>nul'));
    verifie("$intouchable est resté intact", $diff === '', $diff);
}

echo "\n" . str_repeat('─', 60) . "\n";
echo ($ko === 0 ? "TOUT EST VERT" : "$ko ÉCHEC(S)") . " — $ok test(s) réussi(s), $ko en échec.\n";
exit($ko === 0 ? 0 : 1);
