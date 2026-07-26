<?php
/**
 * Test fonctionnel des transferts d'inscription, contre une vraie base MySQL.
 *
 * Le scénario est celui du terrain : Marie a inscrit son fils Louis sous sa
 * propre adresse ; Louis veut son espace et son chronométrage.
 */
const DSN = 'mysql:host=127.0.0.1;port=3399;dbname=fer_xfer';
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
function jr_accent_vars_from_hex(string $h): ?array { return null; }
function logContentAction(...$a): void {}
function sendMail(...$a) { $GLOBALS['MAILS'][] = $a[0]; return true; }

$srv = new PDO('mysql:host=127.0.0.1;port=3399', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$srv->exec('DROP DATABASE IF EXISTS fer_xfer');
$srv->exec('CREATE DATABASE fer_xfer DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo = new PDO(DSN, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$install = file_get_contents('W:/FER/install.php');
preg_match('/function getCreateTableStatements\(\): array\s*\{(.*?)\n\}/s', $install, $m);
foreach (eval($m[1]) as $sql) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS `(setting|users|editions|participants|participant_auth_codes|participant_devices|participant_registrations|registration_transfers|registrations|resultats)`/', $sql)) {
        $pdo->exec($sql);
    }
}
$pdo->exec('INSERT INTO setting (id) VALUES (1)');
$pdo->exec("INSERT INTO editions (annee, libelle, is_active, date_course) VALUES (2026, 'FER 2026', 1, '2026-10-04')");
$pdo->exec("INSERT INTO editions (annee, libelle, is_active) VALUES (2024, 'FER 2024', 0)");

/* Chargement du code réel */
foreach ([
    'W:/FER/src/core/registrations_resolver.php',
    'W:/FER/src/auth/participant_auth.php',
    'W:/FER/src/content/transfers.php',
] as $f) {
    $s = file_get_contents($f);
    $s = preg_replace('/^<\?php/', '', $s, 1);
    $s = preg_replace('#^require_once .*$#m', '', $s);
    $s = str_replace('session_regenerate_id(true);', '', $s);
    eval($s);
}

$_SESSION = [];
$GLOBALS['MAILS'] = [];
$ok = 0; $ko = 0;
function t(string $titre, bool $c) {
    global $ok, $ko;
    if ($c) { $ok++; echo "OK   $titre\n"; } else { $ko++; echo "KO   $titre\n"; }
}

/* ── Jeu de données ── */
$ins = $pdo->prepare('INSERT INTO registrations (inscription_no, nom, prenom, email, naissance, sexe, ville, group_id, montant_du)
                      VALUES (?,?,?,?,?,?,?,?,?)');
$ins->execute(['S1', encrypt('Dupont'), encrypt('Marie'), encrypt('marie@ex.fr'), encrypt('42'), 'F', encrypt('Forbach'), 'G1', 12]);
$ins->execute(['S2', encrypt('Dupont'), encrypt('Louis'), encrypt('marie@ex.fr'), encrypt('16'), 'H', encrypt('Forbach'), 'G1', 12]);
$ins->execute(['S9', encrypt('Tiers'),  encrypt('Paul'),  encrypt('paul@ex.fr'),  encrypt('30'), 'H', encrypt('Behren'),  null, 12]);

$marie = pauth_createFromRegistrations($pdo, 'marie@ex.fr');
pauth_syncRegistrations($pdo, (int) $marie['id'], 'marie@ex.fr');
$paul  = pauth_createFromRegistrations($pdo, 'paul@ex.fr');
pauth_syncRegistrations($pdo, (int) $paul['id'], 'paul@ex.fr');
$annee = regres_activeYear($pdo);

echo "── Garde-fous de la demande ──\n";
t('Marie ne peut pas transférer l\'inscription de Paul',
    xfer_creer($pdo, (int) $marie['id'], $annee, 'S9', 'x@ex.fr')['ok'] === false);
t('adresse invalide refusée',
    xfer_creer($pdo, (int) $marie['id'], $annee, 'S2', 'pas-un-email')['ok'] === false);
t('transfert vers sa propre adresse refusé',
    xfer_creer($pdo, (int) $marie['id'], $annee, 'S2', 'marie@ex.fr')['ok'] === false);
t('édition passée non transférable',
    xfer_creer($pdo, (int) $marie['id'], 2024, 'S2', 'louis@ex.fr')['ok'] === false);

echo "\n── Demande ──\n";
$r = xfer_creer($pdo, (int) $marie['id'], $annee, 'S2', 'Louis@Ex.FR');
t('demande créée', $r['ok'] === true && strlen($r['token']) === 64);
$t1 = xfer_parToken($pdo, $r['token']);
t('adresse cible normalisée en minuscules', $t1['email_cible'] === 'louis@ex.fr');
t('adresses chiffrées en base', !str_contains(
    (string) $pdo->query('SELECT email_cible FROM registration_transfers LIMIT 1')->fetchColumn(), '@'));
t('jeton jamais stocké en clair',
    (string) $pdo->query('SELECT token_hash FROM registration_transfers LIMIT 1')->fetchColumn() === hash('sha256', $r['token']));

echo "\n── Un seul transfert en attente ──\n";
$r2 = xfer_creer($pdo, (int) $marie['id'], $annee, 'S2', 'autre@ex.fr');
t('seconde demande refusée tant que la première est en attente', $r2['ok'] === false);

echo "\n── Acceptation ──\n";
$avant = regres_find($pdo, $annee, 'S2');
t('avant : l\'inscription porte l\'adresse de Marie', $avant['email'] === 'marie@ex.fr');
$acc = xfer_accepter($pdo, $r['token']);
t('acceptation réussie', $acc['ok'] === true);

$apres = regres_find($pdo, $annee, 'S2');
t('1. l\'adresse de l\'inscription est passée à Louis', $apres['email'] === 'louis@ex.fr');
$louis = pauth_findByEmail($pdo, 'louis@ex.fr');
t('2. le compte de Louis a été créé', $louis !== null);
$prop = (int) $pdo->query("SELECT participant_id FROM participant_registrations WHERE annee=$annee AND inscription_no='S2'")->fetchColumn();
t('3. le RATTACHEMENT a basculé sur Louis', $prop === (int) $louis['id']);
$orig = (string) $pdo->query("SELECT origine FROM participant_registrations WHERE annee=$annee AND inscription_no='S2'")->fetchColumn();
t('origine marquée « transfert »', $orig === 'transfert');

echo "\n── Le titulaire perd bien l'accès ──\n";
t('Marie ne voit plus S2', !pauth_owns($pdo, (int) $marie['id'], $annee, 'S2'));
t('Marie garde S1', pauth_owns($pdo, (int) $marie['id'], $annee, 'S1'));
t('Louis voit S2', pauth_owns($pdo, (int) $louis['id'], $annee, 'S2'));
t('Louis ne voit que S2', count(pauth_registrations($pdo, (int) $louis['id'])) === 1);

echo "\n── Usage unique ──\n";
t('le même lien ne marche plus', xfer_accepter($pdo, $r['token'])['ok'] === false);
t('un jeton inventé est refusé', xfer_accepter($pdo, str_repeat('a', 64))['ok'] === false);
t('un jeton mal formé est refusé', xfer_accepter($pdo, 'court')['ok'] === false);

echo "\n── Annulation ──\n";
$r3 = xfer_creer($pdo, (int) $marie['id'], $annee, 'S1', 'ami@ex.fr');
t('nouvelle demande possible sur une autre inscription', $r3['ok'] === true);
t('un tiers ne peut pas annuler', xfer_annuler($pdo, $r3['id'], (int) $paul['id'])['ok'] === false);
t('le titulaire annule', xfer_annuler($pdo, $r3['id'], (int) $marie['id'])['ok'] === true);
t('un lien annulé ne s\'accepte plus', xfer_accepter($pdo, $r3['token'])['ok'] === false);
t('après annulation, une nouvelle demande passe',
    xfer_creer($pdo, (int) $marie['id'], $annee, 'S1', 'ami2@ex.fr')['ok'] === true);

echo "\n── Expiration ──\n";
$pdo->exec("UPDATE registration_transfers SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE statut = 'en_attente'");
xfer_purge($pdo);
$n = (int) $pdo->query("SELECT COUNT(*) FROM registration_transfers WHERE statut = 'expire'")->fetchColumn();
t("les demandes périmées passent à « expire » ($n)", $n > 0);

echo "\n── Compte cible déjà existant ──\n";
$r4 = xfer_creer($pdo, (int) $marie['id'], $annee, 'S1', 'paul@ex.fr');
t('demande vers un compte existant', $r4['ok'] === true);
t('acceptée', xfer_accepter($pdo, $r4['token'])['ok'] === true);
$prop = (int) $pdo->query("SELECT participant_id FROM participant_registrations WHERE annee=$annee AND inscription_no='S1'")->fetchColumn();
t('rattachée au compte EXISTANT, sans doublon', $prop === (int) $paul['id']);
t('Paul a maintenant 2 inscriptions', count(pauth_registrations($pdo, (int) $paul['id'])) === 2);
t('un seul compte pour paul@ex.fr',
    (int) $pdo->query("SELECT COUNT(*) FROM participants WHERE email_hmac = " . $pdo->quote(fer_emailHmac('paul@ex.fr')))->fetchColumn() === 1);

echo "\n── Date limite ──\n";
$pdo->exec("UPDATE editions SET transferts_deadline = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE annee = $annee");
t('deadline dépassée détectée', xfer_deadlinePassee($pdo, $annee) === true);
t('plus aucune demande possible',
    xfer_creer($pdo, (int) $paul['id'], $annee, 'S1', 'zz@ex.fr')['ok'] === false);

printf("\n%d test(s) OK, %d échec(s)\n", $ok, $ko);
exit($ko > 0 ? 1 : 0);
