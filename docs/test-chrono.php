<?php
/**
 * Test du chronométrage : réception, arbitrage balise/GPS, calcul du temps.
 *
 * CE QUI EST VÉRIFIÉ EN PRIORITÉ, c'est la REDONDANCE demandée : si la balise
 * tombe, le GPS doit donner un temps ; si le GPS tombe, la balise aussi. Un
 * chronométrage qui ne marche que quand tout va bien ne sert à rien le jour J.
 */
const CIPHER_KEY_TEST = 'clé de test 32 octets............';

$ok = 0; $ko = 0;
function verifie(string $titre, bool $cond, string $detail = ''): void {
    global $ok, $ko;
    if ($cond) { $ok++; echo "  OK   $titre\n"; }
    else       { $ko++; echo "  ECHEC $titre" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

define('CIPHER_KEY', CIPHER_KEY_TEST);
function encrypt(?string $d): ?string { return $d === null || $d === '' ? $d : base64_encode('E:' . $d); }
function decrypt(?string $d): ?string {
    if ($d === null || $d === '') return $d;
    $r = base64_decode($d, true);
    return ($r !== false && str_starts_with($r, 'E:')) ? substr($r, 2) : $d;
}
function decryptRow(array $r): array { return $r; }
function fer_client_ip(): string { return '10.0.0.1'; }

$srv = new PDO('mysql:host=127.0.0.1;port=3399', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$srv->exec('DROP DATABASE IF EXISTS fer_chrono');
$srv->exec('CREATE DATABASE fer_chrono DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo = new PDO('mysql:host=127.0.0.1;port=3399;dbname=fer_chrono', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$install = file_get_contents('W:/FER/install.php');
preg_match('/function getCreateTableStatements\(\): array\s*\{(.*?)\n\}/s', $install, $m);
foreach (eval($m[1]) as $sql) $pdo->exec($sql);
$pdo->exec('INSERT INTO setting (id) VALUES (1)');

/* La course a eu lieu AVANT-HIER — en relatif, pour que ce banc ne périme
   jamais. Une date fixe finirait dans le futur et le moteur refuserait toutes
   les détections (garde-fou anti-horodatage futur), pour une raison qui n'a
   rien à voir avec ce qu'on teste. */
$JOUR  = (new DateTimeImmutable('-2 days'))->format('Y-m-d');
$ANNEE = (int) (new DateTimeImmutable('-2 days'))->format('Y');

/* heure_depart est stockée en UTC (contrat du projet). */
$st = $pdo->prepare("INSERT INTO editions (annee, libelle, is_active, heure_depart, temps_min_plausible_s)
                     VALUES (?, ?, 1, ?, 900)");
$st->execute([$ANNEE, 'FER ' . $ANNEE, $JOUR . ' 08:00:00']);

foreach (['chrono.php'] as $f) {
    $s = file_get_contents('W:/FER/src/content/' . $f);
    $s = preg_replace('/^<\?php/', '', $s, 1);
    $s = preg_replace('#^\s*require(_once)? .*$#m', '', $s);
    eval($s);
}

/** Horodatage ISO-8601 avec décalage, à partir d'une heure LOCALE de course. */
function iso(string $hhmmss): string {
    global $JOUR;
    return (new DateTimeImmutable($JOUR . ' ' . $hhmmss, new DateTimeZone('Europe/Paris')))->format('c');
}
/** Le même, mais à partir d'une heure UTC — pour comparer à `heure_depart`. */
function isoUtc(string $hhmmss): string {
    global $JOUR;
    return (new DateTimeImmutable($JOUR . ' ' . $hhmmss, new DateTimeZone('UTC')))->format('c');
}

/* ── 1. Balise seule ─────────────────────────────────────────────────────── */
echo "\n=== 1. Balise seule (cas nominal) ===\n";
chrono_ingestDetection($pdo, $ANNEE, 'B1', ['type' => 'beacon', 'point' => 'depart',
    'detecte_at' => iso('10:00:00'), 'rssi_pic' => -55]);
chrono_ingestDetection($pdo, $ANNEE, 'B1', ['type' => 'beacon', 'point' => 'arrivee',
    'detecte_at' => iso('10:45:30'), 'rssi_pic' => -58]);
$r = chrono_recompute($pdo, $ANNEE, 'B1');
verifie('temps calculé à 45 min 30 s', (int) $r['temps_s'] === 2730, (string) $r['temps_s']);
verifie('méthode « beacon »', $r['methode'] === 'beacon', (string) $r['methode']);
verifie('statut terminé', $r['statut'] === 'termine');
$res = $pdo->query("SELECT * FROM resultats WHERE inscription_no='B1'")->fetch();
verifie('précision annoncée à ±2 s', (int) $res['precision_s'] === 2, (string) $res['precision_s']);

/* ── 2. LA BALISE TOMBE → le GPS prend le relais ─────────────────────────── */
echo "\n=== 2. Balise en panne → le GPS sauve le chrono ===\n";
chrono_ingestDetection($pdo, $ANNEE, 'G1', ['type' => 'geofence', 'point' => 'depart',
    'detecte_at' => iso('10:00:05')]);
chrono_ingestDetection($pdo, $ANNEE, 'G1', ['type' => 'geofence', 'point' => 'arrivee',
    'detecte_at' => iso('10:50:00')]);
$r = chrono_recompute($pdo, $ANNEE, 'G1');
verifie('un temps est quand même produit', $r['statut'] === 'termine', (string) $r['statut']);
verifie('méthode « gps_ligne », pas « beacon »', $r['methode'] === 'gps_ligne', (string) $r['methode']);
$res = $pdo->query("SELECT * FROM resultats WHERE inscription_no='G1'")->fetch();
verifie('précision dégradée annoncée (±15 s)', (int) $res['precision_s'] === 15, (string) $res['precision_s']);

/* ── 3. LE GPS TOMBE → la balise suffit ──────────────────────────────────── */
echo "\n=== 3. GPS en panne → la balise suffit ===\n";
chrono_ingestDetection($pdo, $ANNEE, 'B2', ['type' => 'beacon', 'point' => 'depart',
    'detecte_at' => iso('10:00:00'), 'rssi_pic' => -60]);
chrono_ingestDetection($pdo, $ANNEE, 'B2', ['type' => 'beacon', 'point' => 'arrivee',
    'detecte_at' => iso('11:02:10'), 'rssi_pic' => -62]);
$r = chrono_recompute($pdo, $ANNEE, 'B2');
verifie('temps produit sans aucune donnée GPS', (int) $r['temps_s'] === 3730, (string) $r['temps_s']);

/* ── 4. LES DEUX PRÉSENTES → la balise l'emporte ─────────────────────────── */
echo "\n=== 4. Les deux sources → la balise fait foi ===\n";
chrono_ingestDetection($pdo, $ANNEE, 'D1', ['type' => 'geofence', 'point' => 'depart',
    'detecte_at' => iso('10:00:12')]);
chrono_ingestDetection($pdo, $ANNEE, 'D1', ['type' => 'beacon', 'point' => 'depart',
    'detecte_at' => iso('10:00:01'), 'rssi_pic' => -52]);
chrono_ingestDetection($pdo, $ANNEE, 'D1', ['type' => 'geofence', 'point' => 'arrivee',
    'detecte_at' => iso('10:40:18')]);
chrono_ingestDetection($pdo, $ANNEE, 'D1', ['type' => 'beacon', 'point' => 'arrivee',
    'detecte_at' => iso('10:40:03'), 'rssi_pic' => -54]);
$r = chrono_recompute($pdo, $ANNEE, 'D1');
verifie('la balise l\'emporte sur le GPS', $r['methode'] === 'beacon', (string) $r['methode']);
verifie('temps = celui de la balise (40 min 02 s)', (int) $r['temps_s'] === 2402, (string) $r['temps_s']);

$det = $pdo->query("SELECT type, point, retenue FROM detections
                     WHERE inscription_no='D1' AND retenue=1 ORDER BY point")->fetchAll();
verifie('les 2 détections retenues sont les balises',
    count($det) === 2 && $det[0]['type'] === 'beacon' && $det[1]['type'] === 'beacon',
    json_encode($det));

/* ── 5. Départ en masse : aucune détection de départ ─────────────────────── */
echo "\n=== 5. Départ en masse (heure officielle de l'édition) ===\n";
chrono_ingestDetection($pdo, $ANNEE, 'M1', ['type' => 'beacon', 'point' => 'arrivee',
    'detecte_at' => isoUtc('09:05:00'), 'rssi_pic' => -55]);   // 08:00 UTC + 1 h 05
$r = chrono_recompute($pdo, $ANNEE, 'M1');
verifie('le départ officiel sert de référence', $r['statut'] === 'termine', (string) $r['statut']);
verifie('temps = 1 h 05', (int) $r['temps_s'] === 3900, (string) $r['temps_s']);
$res = $pdo->query("SELECT commentaire FROM resultats WHERE inscription_no='M1'")->fetch();
verifie('le commentaire l\'indique clairement',
    str_contains((string) $res['commentaire'], 'officielle'), (string) $res['commentaire']);

/* ── 6. Garde-fous : ce qui ne doit PAS être publié ──────────────────────── */
echo "\n=== 6. Garde-fous ===\n";
chrono_ingestDetection($pdo, $ANNEE, 'X1', ['type' => 'beacon', 'point' => 'depart',
    'detecte_at' => iso('10:00:00'), 'rssi_pic' => -55]);
chrono_ingestDetection($pdo, $ANNEE, 'X1', ['type' => 'beacon', 'point' => 'arrivee',
    'detecte_at' => iso('10:03:00'), 'rssi_pic' => -55]);   // 3 min < 900 s
$r = chrono_recompute($pdo, $ANNEE, 'X1');
verifie('temps sous le minimum plausible → invalide', $r['statut'] === 'invalide', (string) $r['statut']);

chrono_ingestDetection($pdo, $ANNEE, 'X2', ['type' => 'beacon', 'point' => 'arrivee',
    'detecte_at' => isoUtc('07:00:00'), 'rssi_pic' => -55]);   // avant le départ officiel
$r = chrono_recompute($pdo, $ANNEE, 'X2');
verifie('arrivée avant le départ → invalide', $r['statut'] === 'invalide', (string) $r['statut']);

$r = chrono_ingestDetection($pdo, $ANNEE, 'X3', ['type' => 'beacon', 'point' => 'arrivee',
    'detecte_at' => $JOUR . ' 10:00:00']);   // sans décalage horaire
verifie('horodatage sans fuseau → refusé', $r['ok'] === false, json_encode($r));

$r = chrono_ingestDetection($pdo, $ANNEE, 'X4', ['type' => 'beacon', 'point' => 'arrivee',
    'detecte_at' => (new DateTimeImmutable('+2 days'))->format('c')]);
verifie('détection dans le futur → refusée', $r['ok'] === false);

$r = chrono_ingestDetection($pdo, $ANNEE, 'X5', ['type' => 'inventé', 'point' => 'arrivee',
    'detecte_at' => iso('10:00:00')]);
verifie('type inconnu → refusé', $r['ok'] === false);

/* ── 7. Idempotence : le réseau tombe, l'appli renvoie ───────────────────── */
echo "\n=== 7. Idempotence (le réseau tombera) ===\n";
$avant = (int) $pdo->query("SELECT COUNT(*) FROM detections WHERE inscription_no='B1'")->fetchColumn();
for ($i = 0; $i < 5; $i++) {
    $r = chrono_ingestDetection($pdo, $ANNEE, 'B1', ['type' => 'beacon', 'point' => 'arrivee',
        'detecte_at' => iso('10:45:30'), 'rssi_pic' => -58]);
}
$apres = (int) $pdo->query("SELECT COUNT(*) FROM detections WHERE inscription_no='B1'")->fetchColumn();
verifie('5 renvois de la même détection → aucun doublon', $apres === $avant, "$avant → $apres");
verifie('le renvoi est signalé comme déjà connu', ($r['nouvelle'] ?? true) === false);

$r2 = chrono_recompute($pdo, $ANNEE, 'B1');
verifie('le temps est inchangé après les renvois', (int) $r2['temps_s'] === 2730);

/* ── 8. Un résultat validé n'est pas écrasé ──────────────────────────────── */
echo "\n=== 8. Résultat validé par un officiel ===\n";
$pdo->exec("UPDATE resultats SET valide_par = 1, temps_s = 9999 WHERE inscription_no='B1'");
chrono_ingestDetection($pdo, $ANNEE, 'B1', ['type' => 'geofence', 'point' => 'arrivee',
    'detecte_at' => iso('10:44:00')]);   // détection tardive
$r = chrono_recompute($pdo, $ANNEE, 'B1');
$t = (float) $pdo->query("SELECT temps_s FROM resultats WHERE inscription_no='B1'")->fetchColumn();
verifie('une détection tardive n\'écrase pas la décision humaine', (int) $t === 9999, (string) $t);
verifie('le message le dit', str_contains((string) ($r['message'] ?? ''), 'officiel'));

$r = chrono_recompute($pdo, $ANNEE, 'B1', true);   // forçage explicite
$t = (float) $pdo->query("SELECT temps_s FROM resultats WHERE inscription_no='B1'")->fetchColumn();
verifie('le forçage explicite, lui, recalcule', (int) $t !== 9999, (string) $t);

/* ── 9. Saisie manuelle : elle prime sur tout ────────────────────────────── */
echo "\n=== 9. Saisie manuelle (filet de sécurité du jour J) ===\n";
chrono_ingestDetection($pdo, $ANNEE, 'D1', ['type' => 'manuel', 'point' => 'arrivee',
    'detecte_at' => iso('10:41:00')]);
$r = chrono_recompute($pdo, $ANNEE, 'D1', true);
verifie('la saisie manuelle prime sur la balise', $r['methode'] === 'manuel', (string) $r['methode']);
verifie('temps recalculé sur la saisie', (int) $r['temps_s'] === 2459, (string) $r['temps_s']);

/* ── 10. Traces GPS ──────────────────────────────────────────────────────── */
echo "\n=== 10. Traces GPS ===\n";
$pts = [];
for ($i = 0; $i < 10; $i++) {
    $pts[] = ['lat' => 49.19 + $i * 0.0001, 'lon' => 6.90 + $i * 0.0001,
              'at' => iso(sprintf('10:%02d:00', $i)), 'alt' => 200 + $i];
}
$r = chrono_ingestTrace($pdo, $ANNEE, 'B1', null, $pts);
verifie('10 points enregistrés', $r['ajoutes'] === 10, json_encode($r));

$r = chrono_ingestTrace($pdo, $ANNEE, 'B1', null, $pts);   // le même lot, renvoyé
verifie('renvoi du même lot → 0 ajout (idempotent)', $r['ajoutes'] === 0, json_encode($r));
verifie('les points renvoyés sont comptés comme ignorés', $r['ignores'] === 10);

$suite = [['lat' => 49.20, 'lon' => 6.91, 'at' => iso('10:10:00')]];
$r = chrono_ingestTrace($pdo, $ANNEE, 'B1', null, $suite);
verifie('un point postérieur est bien ajouté', $r['ajoutes'] === 1, json_encode($r));
$tr = $pdo->query("SELECT nb_points, purge_at FROM traces_gps WHERE inscription_no='B1'")->fetch();
verifie('11 points au total', (int) $tr['nb_points'] === 11, (string) $tr['nb_points']);
verifie('la date d\'effacement automatique est posée dès l\'écriture', $tr['purge_at'] !== null);

$r = chrono_ingestTrace($pdo, $ANNEE, 'B1', null, [['lat' => 999, 'lon' => 0, 'at' => iso('11:00:00')]]);
verifie('coordonnée aberrante → ignorée', $r['ajoutes'] === 0 && $r['ignores'] === 1, json_encode($r));

$r = chrono_ingestTrace($pdo, $ANNEE, 'B1', null, array_fill(0, 5001, ['lat' => 49, 'lon' => 6, 'at' => iso('12:00:00')]));
verifie('lot trop volumineux → refusé', $r['ok'] === false);

echo "\n" . str_repeat('─', 60) . "\n";
echo ($ko === 0 ? "TOUT EST VERT" : "$ko ÉCHEC(S)") . " — $ok test(s) réussi(s), $ko en échec.\n";
exit($ko === 0 ? 0 : 1);
