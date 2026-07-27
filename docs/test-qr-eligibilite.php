<?php
/**
 * Test de l'éligibilité au QR code — donc au t-shirt.
 *
 * L'ENJEU : les t-shirts sont en nombre limité. Afficher un QR code à quelqu'un
 * qui n'y a pas droit, c'est lui promettre un t-shirt qu'il n'aura pas. Le jour
 * J, il se présente au comptoir avec un code à l'écran, et il n'y a pas de
 * bonne façon de lui expliquer là, dans la file.
 *
 * La règle doit donc être STRICTEMENT la même à l'envoi du mail et à l'affichage
 * dans l'espace coureur. C'est ce que ce banc vérifie.
 */
$ok = 0; $ko = 0;
function verifie(string $titre, bool $cond, string $detail = ''): void {
    global $ok, $ko;
    if ($cond) { $ok++; echo "  OK   $titre\n"; }
    else       { $ko++; echo "  ECHEC $titre" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

$srv = new PDO('mysql:host=127.0.0.1;port=3399', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$srv->exec('DROP DATABASE IF EXISTS fer_qr');
$srv->exec('CREATE DATABASE fer_qr DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo = new PDO('mysql:host=127.0.0.1;port=3399;dbname=fer_qr', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$install = file_get_contents('W:/FER/install.php');
preg_match('/function getCreateTableStatements\(\): array\s*\{(.*?)\n\}/s', $install, $m);
foreach (eval($m[1]) as $sql) $pdo->exec($sql);

/* La fonction sous test — chargée sans le reste du socle. */
$src = file_get_contents('W:/FER/src/core/qrcode.php');
$src = preg_replace('/^<\?php/', '', $src, 1);
$src = preg_replace('#^\s*require(_once)? .*$#m', '', $src);
eval($src);

/* Trois payants inscrits dans cet ordre, plus un gratuit. */
$ins = $pdo->prepare('INSERT INTO registrations (inscription_no, nom, prenom, montant_du, date_inscription)
                      VALUES (?,?,?,?,?)');
$ins->execute(['P1', 'A', 'A', 12.00, '2026-01-01 10:00:00']);
$ins->execute(['P2', 'B', 'B', 12.00, '2026-01-02 10:00:00']);
$ins->execute(['P3', 'C', 'C', 12.00, '2026-01-03 10:00:00']);
$ins->execute(['G1', 'D', 'D',  0.00, '2026-01-01 09:00:00']);   // enfant, gratuit

echo "\n=== 1. Mode « none » : personne n'a de QR ===\n";
$s = ['qrcode_mail_mode' => 'none', 'qrcode_mail_limit' => 100];
foreach (['P1', 'P2', 'G1'] as $no) {
    $r = fer_qrEligibilite($pdo, $s, $no);
    verifie("$no : pas de QR", $r['ok'] === false && $r['raison'] === 'mode_none');
}

echo "\n=== 2. Mode « all » : tout le monde en a un ===\n";
$s = ['qrcode_mail_mode' => 'all', 'qrcode_mail_limit' => 0];
foreach (['P1', 'P3', 'G1'] as $no) {
    verifie("$no : QR accordé", fer_qrEligibilite($pdo, $s, $no)['ok'] === true);
}

echo "\n=== 3. Mode « first_x » — LE CAS QUI COMPTE ===\n";
$s = ['qrcode_mail_mode' => 'first_x', 'qrcode_mail_limit' => 2];
verifie('P1 (1er payant) → QR',  fer_qrEligibilite($pdo, $s, 'P1')['ok'] === true);
verifie('P2 (2e payant) → QR',   fer_qrEligibilite($pdo, $s, 'P2')['ok'] === true);

$r = fer_qrEligibilite($pdo, $s, 'P3');
verifie('P3 (3e payant, hors limite) → PAS de QR', $r['ok'] === false, json_encode($r));
verifie('la raison est « hors_limite »', $r['raison'] === 'hors_limite', $r['raison']);
verifie('la limite est communiquée pour le message', $r['limite'] === 2);

$r = fer_qrEligibilite($pdo, $s, 'G1');
verifie('G1 (gratuit, inscrit en PREMIER) → PAS de QR', $r['ok'] === false, json_encode($r));
verifie('la raison est « non_payant »', $r['raison'] === 'non_payant', $r['raison']);
verifie('les gratuits ne consomment pas de place',
    fer_qrEligibilite($pdo, $s, 'P2')['ok'] === true);

echo "\n=== 4. Inscription absente de l'édition en cours (archive) ===\n";
$r = fer_qrEligibilite($pdo, $s, 'INCONNU');
verifie('→ pas de QR, raison « introuvable »',
    $r['ok'] === false && $r['raison'] === 'introuvable', json_encode($r));

echo "\n=== 5. Le rang suit la date d'INSCRIPTION, pas celle de saisie ===\n";
/* Un inscrit antidaté (inscrit avant, saisi après) doit passer devant : sinon
   une saisie tardive par l'organisation ferait perdre le t-shirt à quelqu'un
   qui s'était inscrit dans les temps. */
$ins->execute(['P0', 'E', 'E', 12.00, '2025-12-31 08:00:00']);   // antidaté
$s = ['qrcode_mail_mode' => 'first_x', 'qrcode_mail_limit' => 1];
verifie('l\'antidaté prend la 1re place', fer_qrEligibilite($pdo, $s, 'P0')['ok'] === true);
verifie('et P1 la perd',                  fer_qrEligibilite($pdo, $s, 'P1')['ok'] === false);

echo "\n=== 6. Une seule source de vérité ===\n";
$mail = file_get_contents('W:/FER/src/mail/googleMail.php');
verifie('googleMail.php délègue à fer_qrEligibilite()',
    str_contains($mail, 'fer_qrEligibilite($pdo, $data ?? [], $inscriptionNo)'));
verifie('il ne contient plus sa propre copie de la règle',
    !str_contains($mail, "WHERE montant_du > 0"), 'copie de la règle encore présente');

$page = file_get_contents('W:/FER/public/espace-coureur/inscription.php');
verifie('l\'espace coureur consulte la même règle',
    str_contains($page, 'fer_qrEligibilite($pdo, $data ?? [], $r[\'inscription_no\'])'));
verifie('il n\'affiche le QR que si elle dit oui',
    str_contains($page, "\$qrElig['ok'] ? fer_qrCodeDataUri"));

echo "\n" . str_repeat('─', 60) . "\n";
echo ($ko === 0 ? "TOUT EST VERT" : "$ko ÉCHEC(S)") . " — $ok test(s) réussi(s), $ko en échec.\n";
exit($ko === 0 ? 0 : 1);
