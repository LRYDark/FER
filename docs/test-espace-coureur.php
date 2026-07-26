<?php
/**
 * Contrôle d'accès de l'espace coureur, contre une vraie base.
 * Question centrale : un coureur connecté peut-il lire la fiche d'un autre en
 * changeant l'URL ?
 */
const DSN = 'mysql:host=127.0.0.1;port=3399;dbname=fer_acces';
const CIPHER_KEY_TEST = 'clé de test 32 octets............';
function encrypt(?string $d): ?string { return $d === null ? null : base64_encode('E:' . $d); }
function decrypt(?string $d): ?string {
    if ($d === null) return null;
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
function fer_client_ip(): string { return '10.0.0.1'; }

$srv = new PDO('mysql:host=127.0.0.1;port=3399', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$srv->exec('DROP DATABASE IF EXISTS fer_acces');
$srv->exec('CREATE DATABASE fer_acces DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo = new PDO(DSN, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$install = file_get_contents('W:/FER/install.php');
preg_match('/function getCreateTableStatements\(\): array\s*\{(.*?)\n\}/s', $install, $m);
foreach (eval($m[1]) as $sql) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS `(setting|users|editions|participants|participant_auth_codes|participant_devices|participant_registrations|registrations|resultats)`/', $sql)) {
        $pdo->exec($sql);
    }
}
$pdo->exec('INSERT INTO setting (id) VALUES (1)');
$pdo->exec("INSERT INTO editions (annee, libelle, is_active) VALUES (2026, 'FER 2026', 1)");

// Le resolver, tel qu'il est écrit
$res = file_get_contents('W:/FER/src/core/registrations_resolver.php');
$res = preg_replace('/^<\?php/', '', $res, 1);
$res = preg_replace('#^require_once .*$#m', '', $res);
eval($res);

// Les fonctions pauth_*
$auth = file_get_contents('W:/FER/src/auth/participant_auth.php');
$auth = preg_replace('/^<\?php/', '', $auth, 1);
$auth = preg_replace('#^require_once .*$#m', '', $auth);
$auth = str_replace('session_regenerate_id(true);', '', $auth);
eval($auth);

$ko = 0;
function t(string $titre, bool $c) { global $ko; echo ($c ? "OK   " : "KO   ") . $titre . "\n"; if (!$c) $ko++; }

/* Deux coureurs, chacun son inscription, plus un groupe familial. */
$ins = $pdo->prepare('INSERT INTO registrations (inscription_no, nom, prenom, email, naissance, sexe, ville, group_id, montant_du)
                      VALUES (?,?,?,?,?,?,?,?,?)');
$ins->execute(['S1', encrypt('Dupont'), encrypt('Marie'), encrypt('marie@ex.fr'), encrypt('42'), 'F', encrypt('Forbach'), 'GRP1', 12]);
$ins->execute(['S2', encrypt('Dupont'), encrypt('Louis'), encrypt('marie@ex.fr'), encrypt('9'),  'H', encrypt('Forbach'), 'GRP1', 0]);
$ins->execute(['S3', encrypt('Martin'), encrypt('Paul'),  encrypt('paul@ex.fr'),  encrypt('30'), 'H', encrypt('Behren'),  null,   12]);

$marie = pauth_createFromRegistrations($pdo, 'marie@ex.fr');
$paul  = pauth_createFromRegistrations($pdo, 'paul@ex.fr');
pauth_syncRegistrations($pdo, (int) $marie['id'], 'marie@ex.fr');
pauth_syncRegistrations($pdo, (int) $paul['id'],  'paul@ex.fr');

echo "── Rattachement ──\n";
$rM = pauth_registrations($pdo, (int) $marie['id']);
t('Marie voit ses 2 inscriptions (elle et son fils)', count($rM) === 2);
t('Paul ne voit que la sienne', count(pauth_registrations($pdo, (int) $paul['id'])) === 1);
t('les données sont déchiffrées', ($rM[0]['nom'] ?? '') === 'Dupont');
t('le group_id remonte pour l affichage groupé', ($rM[0]['group_id'] ?? '') === 'GRP1');

echo "\n── Contrôle d'accès (le point critique) ──\n";
$annee = regres_activeYear($pdo);
t('Marie accède à S1', pauth_owns($pdo, (int) $marie['id'], $annee, 'S1'));
t('Marie accède à S2 (son fils)', pauth_owns($pdo, (int) $marie['id'], $annee, 'S2'));
t('Marie N ACCEDE PAS à S3 (Paul)', !pauth_owns($pdo, (int) $marie['id'], $annee, 'S3'));
t('Paul N ACCEDE PAS à S1', !pauth_owns($pdo, (int) $paul['id'], $annee, 'S1'));
t('numéro inexistant refusé', !pauth_owns($pdo, (int) $marie['id'], $annee, 'S999'));
t('bonne inscription mais mauvaise année refusée', !pauth_owns($pdo, (int) $marie['id'], 2024, 'S1'));
t('injection SQL dans le numéro sans effet', !pauth_owns($pdo, (int) $marie['id'], $annee, "S1' OR '1'='1"));

echo "\n── Rattachement fantôme ──\n";
$pdo->prepare('INSERT INTO participant_registrations (participant_id, annee, inscription_no) VALUES (?,?,?)')
    ->execute([(int) $marie['id'], $annee, 'S404']);
t('un rattachement sans inscription est ignoré, pas affiché vide',
    count(pauth_registrations($pdo, (int) $marie['id'])) === 2);

echo "\n── Suppression de compte (anonymisation) ──\n";
$idP = (int) $paul['id'];
$bidon = 'supprime-' . $idP . '@invalid.local';
$pdo->prepare('UPDATE participants SET email_chiffre=?, email_hmac=?, nom=NULL, prenom=NULL, is_active=0 WHERE id=?')
    ->execute([encrypt($bidon), fer_emailHmac($bidon), $idP]);
$pdo->prepare('DELETE FROM participant_registrations WHERE participant_id = ?')->execute([$idP]);

t('le compte ne retrouve plus rien', count(pauth_registrations($pdo, $idP)) === 0);
t("l'INSCRIPTION de Paul existe toujours", regres_find($pdo, $annee, 'S3') !== null);
t("son email d'inscription est intact", regres_find($pdo, $annee, 'S3')['email'] === 'paul@ex.fr');
t('son ancienne adresse ne retrouve plus le compte supprimé',
    pauth_findByEmail($pdo, 'paul@ex.fr') === null);
$recree = pauth_createFromRegistrations($pdo, 'paul@ex.fr');
t('se reconnecter crée un NOUVEAU compte', $recree !== null && (int) $recree['id'] !== $idP);
pauth_syncRegistrations($pdo, (int) $recree['id'], 'paul@ex.fr');
t('qui retrouve son inscription', count(pauth_registrations($pdo, (int) $recree['id'])) === 1);

printf("\n%s\n", $ko === 0 ? 'AUCUNE ANOMALIE' : "$ko ANOMALIE(S)");
exit($ko > 0 ? 1 : 0);
