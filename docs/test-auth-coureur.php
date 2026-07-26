<?php
/**
 * Test fonctionnel de l'authentification coureur, contre une vraie base MySQL.
 *
 * participant_auth.php dépend de config.php (donc de config.enc, illisible ici) :
 * on extrait ses fonctions et on fournit les quelques primitives dont elles ont
 * besoin. Ce qui est testé reste le VRAI code — requêtes SQL comprises.
 */

const DSN = 'mysql:host=127.0.0.1;port=3399;dbname=fer_auth';
$pdo = null;

/* ── Primitives fournies par config.php ── */
const CIPHER_KEY_TEST = 'clé de test 32 octets............';
function encrypt(?string $d): ?string { return $d === null ? null : base64_encode('E:' . $d); }
function decrypt(?string $d): ?string {
    if ($d === null) return null;
    $r = base64_decode($d, true);
    return ($r !== false && str_starts_with($r, 'E:')) ? substr($r, 2) : $d;
}
function fer_normalizeEmail(?string $e): string { return mb_strtolower(trim((string) $e), 'UTF-8'); }
function fer_emailHmac(?string $e): ?string {
    $n = fer_normalizeEmail($e);
    return $n === '' ? null : hash_hmac('sha256', $n, CIPHER_KEY_TEST);
}
function fer_client_ip(): string { return $GLOBALS['IP_TEST'] ?? '10.0.0.1'; }

/** Inscriptions simulées : adresse => lignes renvoyées par le resolver. */
$GLOBALS['INSCRITS'] = [];
function regres_findByEmail(PDO $pdo, string $emailNorm): array {
    return $GLOBALS['INSCRITS'][$emailNorm] ?? [];
}

/* ── Chargement des fonctions pauth_* ── */
$src = file_get_contents('W:/FER/src/auth/participant_auth.php');
$src = preg_replace('/^<\?php/', '', $src, 1);
$src = preg_replace('#^require_once .*$#m', '', $src);
// Les pages web appellent session_*(), inutile et impossible en CLI ici.
$src = str_replace('session_regenerate_id(true);', '', $src);
eval($src);

/* ── Base de test ── */
$srv = new PDO('mysql:host=127.0.0.1;port=3399', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$srv->exec('DROP DATABASE IF EXISTS fer_auth');
$srv->exec('CREATE DATABASE fer_auth DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo = new PDO(DSN, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// Schéma réel, extrait de install.php
$install = file_get_contents('W:/FER/install.php');
preg_match('/function getCreateTableStatements\(\): array\s*\{(.*?)\n\}/s', $install, $m);
foreach (eval($m[1]) as $sql) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS `(setting|participants|participant_auth_codes|participant_devices|participant_registrations)`/', $sql)) {
        $pdo->exec($sql);
    }
}
$pdo->exec('INSERT INTO setting (id) VALUES (1)');

$_SESSION = [];
$ok = 0; $ko = 0;
function t(string $titre, bool $cond) {
    global $ok, $ko;
    if ($cond) { $ok++; echo "OK   $titre\n"; }
    else       { $ko++; echo "KO   $titre\n"; }
}

$mail = 'Marie.Dupont@Exemple.FR';
$GLOBALS['INSCRITS'][fer_normalizeEmail($mail)] = [
    ['annee' => 2026, 'inscription_no' => 'S1', 'nom' => 'Dupont', 'prenom' => 'Marie'],
    ['annee' => 2024, 'inscription_no' => 'S9', 'nom' => 'Dupont', 'prenom' => 'Marie'],
];

echo "── Réglages ──\n";
$s = pauth_settings($pdo);
t('valeurs par défaut lues dans setting', (int) $s['participant_code_ttl_min'] === 15
    && (int) $s['participant_code_max_tentatives'] === 5);

echo "\n── Émission et vérification du code ──\n";
$c = pauth_issueCode($pdo, $mail, 'web', '10.0.0.1');
t('code à 6 chiffres', (bool) preg_match('/^\d{6}$/', $c['code']));
t('jeton de lien distinct du code', strlen($c['token']) === 64);
$stock = $pdo->query('SELECT code_hash FROM participant_auth_codes')->fetchColumn();
t('code JAMAIS stocké en clair', !str_contains((string) $stock, $c['code']));
t('adresse jamais stockée en clair', !str_contains(
    (string) $pdo->query('SELECT email_hmac FROM participant_auth_codes')->fetchColumn(), 'exemple'));

t('code faux refusé', pauth_verifyCode($pdo, $mail, '000000')['ok'] === false);
t('la casse de l\'adresse est indifférente', pauth_verifyCode($pdo, strtoupper($mail), $c['code'])['ok'] === true);
t('code à USAGE UNIQUE (rejeu refusé)', pauth_verifyCode($pdo, $mail, $c['code'])['ok'] === false);

echo "\n── Lien cliquable ──\n";
$c2 = pauth_issueCode($pdo, $mail, 'web', '10.0.0.1');
t('jeton du lien accepté', pauth_verifyCode($pdo, $mail, null, $c2['token'])['ok'] === true);
t('jeton consommé lui aussi', pauth_verifyCode($pdo, $mail, null, $c2['token'])['ok'] === false);

echo "\n── Une nouvelle demande invalide la précédente ──\n";
$c3 = pauth_issueCode($pdo, $mail, 'web', '10.0.0.1');
$c4 = pauth_issueCode($pdo, $mail, 'web', '10.0.0.1');
t('l\'ancien code ne marche plus', pauth_verifyCode($pdo, $mail, $c3['code'])['ok'] === false);
t('le nouveau code marche', pauth_verifyCode($pdo, $mail, $c4['code'])['ok'] === true);

echo "\n── Compteur de tentatives ──\n";
$c5 = pauth_issueCode($pdo, $mail, 'web', '10.0.0.1');
for ($i = 0; $i < 5; $i++) pauth_verifyCode($pdo, $mail, '111111');
$r = pauth_verifyCode($pdo, $mail, $c5['code']);
t('le BON code est refusé après 5 échecs', $r['ok'] === false && $r['raison'] === 'trop_de_tentatives');
t('le code est invalidé définitivement', pauth_verifyCode($pdo, $mail, $c5['code'])['raison'] === 'aucun');

echo "\n── Expiration ──\n";
$c6 = pauth_issueCode($pdo, $mail, 'web', '10.0.0.1');
$pdo->exec('UPDATE participant_auth_codes SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE consomme_at IS NULL');
t('code expiré refusé', pauth_verifyCode($pdo, $mail, $c6['code'])['raison'] === 'expire');

echo "\n── Limitation de débit ──\n";
$pdo->exec('DELETE FROM participant_auth_codes');
$autre = 'autre@exemple.fr';
for ($i = 0; $i < 3; $i++) {
    t('demande ' . ($i + 1) . '/3 autorisée pour l\'adresse', pauth_rateLimitOk($pdo, $autre, '10.0.0.2'));
    pauth_issueCode($pdo, $autre, 'web', '10.0.0.2');
}
t('4e demande BLOQUÉE pour cette adresse', !pauth_rateLimitOk($pdo, $autre, '10.0.0.2'));
t('une autre adresse depuis la même IP passe encore', pauth_rateLimitOk($pdo, 'tiers@exemple.fr', '10.0.0.2'));
for ($i = 0; $i < 7; $i++) pauth_issueCode($pdo, 'balayage' . $i . '@exemple.fr', 'web', '10.0.0.2');
t('11e demande BLOQUÉE pour cette IP (anti-balayage)', !pauth_rateLimitOk($pdo, 'encore@exemple.fr', '10.0.0.2'));
t('une autre IP n\'est pas affectée', pauth_rateLimitOk($pdo, 'encore@exemple.fr', '10.9.9.9'));

echo "\n── Création du compte et rattachement ──\n";
t('aucun compte tant que le code n\'est pas validé',
    (int) $pdo->query('SELECT COUNT(*) FROM participants')->fetchColumn() === 0);
$p = pauth_createFromRegistrations($pdo, $mail);
t('compte créé depuis les inscriptions', $p !== null && (int) $p['id'] > 0);
t('nom et prénom repris de l\'inscription la plus récente',
    $p['nom'] === 'Dupont' && $p['prenom'] === 'Marie');
t('adresse chiffrée en base', !str_contains(
    (string) $pdo->query('SELECT email_chiffre FROM participants')->fetchColumn(), 'exemple.fr'));
t('adresse relisible en clair', fer_normalizeEmail($p['email']) === fer_normalizeEmail($mail));

$n = pauth_syncRegistrations($pdo, (int) $p['id'], $mail);
t('2 inscriptions rattachées (2026 et 2024)', $n === 2);
t('rejeu sans doublon', pauth_syncRegistrations($pdo, (int) $p['id'], $mail) === 0);

// Une inscription revendiquée ne peut pas basculer sur un autre compte.
$pdo->exec("INSERT INTO participants (email_chiffre, email_hmac) VALUES ('x', REPEAT('b',64))");
$autreId = (int) $pdo->lastInsertId();
$GLOBALS['INSCRITS']['voleur@exemple.fr'] = [['annee' => 2026, 'inscription_no' => 'S1', 'nom' => 'X', 'prenom' => 'Y']];
pauth_syncRegistrations($pdo, $autreId, 'voleur@exemple.fr');
$prop = (int) $pdo->query("SELECT participant_id FROM participant_registrations WHERE annee=2026 AND inscription_no='S1'")->fetchColumn();
t('une inscription déjà revendiquée n\'est pas volée', $prop === (int) $p['id']);

echo "\n── Adresse sans inscription ──\n";
t('aucun compte créé', pauth_createFromRegistrations($pdo, 'inconnu@exemple.fr') === null);

echo "\n── Appareils de confiance ──\n";
$_COOKIE = [];
$tok = pauth_rememberDevice($pdo, (int) $p['id'], 'web');
$d = $pdo->query('SELECT * FROM participant_devices ORDER BY id DESC LIMIT 1')->fetch();
t('token jamais stocké en clair', $d['token_hash'] === hash('sha256', $tok) && $d['token_hash'] !== $tok);
t('type web → expiration posée', $d['type'] === 'web' && $d['expires_at'] !== null);
$tokApp = pauth_rememberDevice($pdo, (int) $p['id'], 'app');
$da = $pdo->query('SELECT * FROM participant_devices ORDER BY id DESC LIMIT 1')->fetch();
t('type app → sans expiration', $da['type'] === 'app' && $da['expires_at'] === null);

$_COOKIE[PAUTH_COOKIE] = $tok;
t('reconnexion par cookie', pauth_loginFromCookie($pdo) === true && pauth_id() === (int) $p['id']);
$pdo->prepare('UPDATE participant_devices SET revoque_at = NOW() WHERE token_hash = ?')
    ->execute([hash('sha256', $tok)]);
$_SESSION = [];
t('appareil révoqué → reconnexion refusée', pauth_loginFromCookie($pdo) === false);

$pdo->prepare('UPDATE participant_devices SET revoque_at = NULL WHERE token_hash = ?')
    ->execute([hash('sha256', $tok)]);
$pdo->exec('UPDATE participants SET is_active = 0');
$_SESSION = [];
t('compte désactivé → reconnexion refusée', pauth_loginFromCookie($pdo) === false);
$pdo->exec('UPDATE participants SET is_active = 1');

echo "\n── Purge ──\n";
$pdo->exec('UPDATE participant_auth_codes SET created_at = DATE_SUB(NOW(), INTERVAL 60 DAY)');
$avant = (int) $pdo->query('SELECT COUNT(*) FROM participant_auth_codes')->fetchColumn();
pauth_purgeCodes($pdo);
$apres = (int) $pdo->query('SELECT COUNT(*) FROM participant_auth_codes')->fetchColumn();
t("codes de plus de 30 jours purgés ($avant → $apres)", $apres === 0 && $avant > 0);

printf("\n%d test(s) OK, %d échec(s)\n", $ok, $ko);
exit($ko > 0 ? 1 : 0);
