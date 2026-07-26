<?php
/**
 * AUDIT PRODUCTION — exécute réellement les deux chemins sur une instance MySQL
 * jetable et compare les schémas obtenus.
 *
 *   Base A « install »  : install.php actuel, sur une base vierge.
 *   Base B « update »   : install.php DE RÉFÉRENCE (avant le lot 1), garni de
 *                         fausses inscriptions, puis migré par update.php actuel.
 *                         C'est la simulation d'un site de production.
 *
 * Contrôles :
 *   1. Les inscriptions existantes survivent-elles à la migration ?
 *   2. `registrations` est-elle structurellement inchangée ?
 *   3. Les deux chemins produisent-ils le MÊME schéma ?
 *   4. La migration est-elle rejouable sans effet de bord (idempotence) ?
 */

const DSN_SRV = 'mysql:host=127.0.0.1;port=3399';
const USER    = 'root';
const PASS    = '';

function srv(): PDO {
    static $p = null;
    if ($p === null) {
        $p = new PDO(DSN_SRV, USER, PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    return $p;
}
function db(string $name): PDO {
    return new PDO(DSN_SRV . ';dbname=' . $name, USER, PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
function recreer(string $name): void {
    srv()->exec("DROP DATABASE IF EXISTS `$name`");
    srv()->exec("CREATE DATABASE `$name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
}

/** Extrait et évalue une fonction « return array » d'un fichier source. */
function tableauDe(string $source, string $fn): array {
    if (!preg_match('/function ' . $fn . '\(\): array\s*\{(.*?)\n\}/s', $source, $m)) {
        throw new RuntimeException("Fonction $fn introuvable");
    }
    return eval($m[1]);
}

/** Extrait et évalue un littéral de tableau « $nom = [ … ]; » du source. */
function litteralDe(string $source, string $nom): array {
    if (!preg_match('/\$' . $nom . ' = (\[.*?\n\]);/s', $source, $m)) {
        throw new RuntimeException("Tableau \$$nom introuvable");
    }
    return eval('return ' . $m[1] . ';');
}

/**
 * Toutes les instructions SQL que update.php applique au schéma, dans l'ordre :
 * les migrations historiques, puis les 9 tables du lot 1, puis les 14 réglages.
 * Le peuplement de `editions` est du code PHP : il est rejoué séparément.
 */
function sqlUpdate(string $src): array {
    $out = litteralDe($src, 'migrations');
    foreach (litteralDe($src, 'lot1Tables') as $ddl) $out[] = $ddl;
    foreach (litteralDe($src, 'lot1Settings') as $col => $ddl) {
        $out[] = "ALTER TABLE `setting` ADD COLUMN `$col` $ddl";
    }
    return $out;
}

/**
 * Rejoue le peuplement de `editions` de update.php — le VRAI code, extrait du
 * fichier, pas une réécriture : c'est justement son idempotence qu'on teste.
 */
function peuplerEditions(PDO $pdo, string $src, bool $tableVientDetreCreee): void {
    if (!$tableVientDetreCreee) return;   // même garde que update.php
    if (!preg_match('/\$anneeCourante = \(int\) date\(\'Y\'\);(.*?)\n\s*sort\(\$creees\);/s', $src, $m)) {
        throw new RuntimeException('Bloc de peuplement introuvable');
    }
    $anneeCourante = (int) date('Y');
    eval($m[1]);
}

/**
 * Rejoue les migrations du LOT 6, qui sont du PHP et non du SQL : elles
 * manipulent un JSON existant (gabarit d'email) et extraient un INSERT depuis
 * install.php. sqlUpdate() ne les voit donc pas, exactement comme pour le
 * peuplement des éditions ci-dessus.
 *
 * On extrait les deux blocs de update.php et on les évalue tels quels : c'est
 * le VRAI code de migration qui s'exécute, pas une réécriture qui ne prouverait
 * rien.
 */
function jouerLot6(PDO $pdo, string $src): void {
    $results = [];   // les blocs y écrivent leur compte rendu

    // Les blocs extraits appellent updTableExists() — définie dans update.php,
    // absente ici. On la fournit à l'identique.
    if (!function_exists('updTableExists')) {
        eval('function updTableExists(PDO $pdo, string $t): bool {
                try { return (int) $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t))
                        ->fetchColumn() > 0; }
                catch (\\Throwable $e) { return false; }
              }');
    }
    // ⚠️ L'ancrage de fin est le DERNIER catch du bloc, pas la première
    // accolade fermante : celle-ci ferme un `if` interne et couperait le `try`
    // de son `catch` — le code extrait ne compilerait même pas.
    foreach ([
        // Chronométrage : index d'unicité des détections, puis colonne de
        // consentement GPS. Le premier est une boucle, d'où le double `}`.
        '/\$chronoAlters = \[.*?\'sql\' => \$desc, \'msg\' => \$e->getMessage\(\)\];\s*\}\s*\}/s',
        '/\$descConsent = \'Ajouter.*?\$descConsent, \'msg\' => \$e->getMessage\(\)\];\s*\}/s',
        '/\$descMtc = "Ajouter la section.*?\$descMtc, \'msg\' => \$e->getMessage\(\)\];\s*\}/s',
        '/\$descFaq = \'Ajouter les questions.*?\$descFaq, \'msg\' => \$e->getMessage\(\)\];\s*\}/s',
        '/\$descRgpd = \'Proposer un texte.*?\$descRgpd, \'msg\' => \$e->getMessage\(\)\];\s*\}/s',
    ] as $motif) {
        if (!preg_match($motif, $src, $m)) {
            throw new RuntimeException('Bloc de migration du lot 6 introuvable');
        }
        // __DIR__ vaut docs/ sous eval() : le bloc FAQ lit install.php par ce
        // chemin, on le corrige pour qu'il pointe sur la racine du projet.
        eval(str_replace("__DIR__ . '/install.php'", "'W:/FER/install.php'", $m[0]));
    }
}

/** Exécute une liste d'instructions, en tolérant celles qui échouent (comme update.php). */
function jouer(PDO $pdo, array $sqls, string $label, bool $verbose = false): array {
    $ok = $ko = 0; $erreurs = [];
    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); $ok++; }
        catch (\Throwable $e) {
            $ko++;
            $msg = $e->getMessage();
            // Erreurs attendues et bénignes lors d'un rejeu : colonne/index/table déjà là,
            // ou colonne à supprimer déjà absente. update.php les classe en « skip ».
            $benigne = str_contains($msg, 'Duplicate column')
                    || str_contains($msg, 'Duplicate key name')
                    || str_contains($msg, "check that column/key exists")
                    || str_contains($msg, "Can't DROP")
                    || str_contains($msg, 'already exists');
            if (!$benigne) $erreurs[] = substr($msg, 0, 160) . "\n     → " . substr(preg_replace('/\s+/', ' ', $sql), 0, 120);
        }
    }
    if ($verbose) printf("   %s : %d exécutée(s), %d ignorée(s)\n", $label, $ok, $ko);
    return $erreurs;
}

/** SHOW CREATE TABLE normalisé (AUTO_INCREMENT et ordre des index neutralisés). */
function schema(PDO $pdo, string $table): ?string {
    try { $r = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM); }
    catch (\Throwable $e) { return null; }
    $s = $r[1] ?? null;
    if ($s === null) return null;
    $s = preg_replace('/AUTO_INCREMENT=\d+\s*/', '', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

$srcInstall = file_get_contents('W:/FER/install.php');
$srcUpdate  = file_get_contents('W:/FER/update.php');
$srcRef     = shell_exec('cd /d W:\FER && git show f260914b:install.php 2>nul');

$ko = 0;

/* ─────────────────────────────────────────────────────────────────────────
 * BASE A — installation neuve avec install.php actuel
 * ───────────────────────────────────────────────────────────────────────── */
echo "=== BASE A : install.php actuel sur base vierge ===\n";
recreer('fer_install');
$A = db('fer_install');
$errA = array_merge(
    jouer($A, tableauDe($srcInstall, 'getCreateTableStatements'), 'tables', true),
    jouer($A, tableauDe($srcInstall, 'getDefaultInserts'), 'données de départ', true)
);
if ($errA) { echo "❌ Erreurs :\n   - " . implode("\n   - ", $errA) . "\n"; $ko += count($errA); }
else echo "✅ Installation neuve sans erreur\n";

/* ─────────────────────────────────────────────────────────────────────────
 * BASE B — simulation d'un site de production
 * ───────────────────────────────────────────────────────────────────────── */
echo "\n=== BASE B : site de production simulé ===\n";
recreer('fer_update');
$B = db('fer_update');
$errB = array_merge(
    jouer($B, tableauDe($srcRef, 'getCreateTableStatements'), 'tables (version de référence)', true),
    jouer($B, tableauDe($srcRef, 'getDefaultInserts'), 'données de départ', true)
);
if ($errB) { echo "⚠️  " . count($errB) . " erreur(s) sur la base de référence :\n   - " . implode("\n   - ", $errB) . "\n"; }

// Des inscriptions « officielles », et une table d'archive, comme en production.
$B->exec("INSERT INTO registrations (inscription_no, nom, prenom, email, naissance, sexe, ville)
          VALUES ('S1','Dupont','Marie','m@ex.fr','42','F','Forbach'),
                 ('S2','Martin','Paul','p@ex.fr','1985','H','Stiring'),
                 ('S3','Sans','Email',NULL,'30','F','Behren')");
$B->exec("CREATE TABLE `registrations_2024` LIKE `registrations`");
$B->exec("INSERT INTO registrations_2024 (inscription_no, nom, prenom, email, naissance, sexe, ville)
          VALUES ('S1','Ancien','Inscrit','a@ex.fr','50','H','Forbach')");
$avant = [
    'registrations'      => (int) $B->query('SELECT COUNT(*) FROM registrations')->fetchColumn(),
    'registrations_2024' => (int) $B->query('SELECT COUNT(*) FROM registrations_2024')->fetchColumn(),
];
$schemaRegAvant = schema($B, 'registrations');
printf("   %d inscription(s) en cours + %d archivée(s) créées\n", $avant['registrations'], $avant['registrations_2024']);

/* Un gabarit d'email PERSONNALISÉ, comme en a forcément un site en service :
   ordre des sections remanié, une section supprimée, un texte réécrit. C'est le
   cas qui compte — la migration du lot 6 doit y insérer « app » SANS toucher au
   reste. Un site de test au gabarit par défaut ne prouverait rien. */
$gabaritAvant = [
    'section_order' => ['qrcode', 'details', 'contact'],   // ordre remanié, 'tips' et 'banner' retirés
    'texts'         => ['banner_title' => 'Texte choisi par l\'association'],
    'colors'        => ['accent' => '#123456'],
];
$B->prepare('UPDATE setting SET mail_template_config = ? WHERE id = 1')
  ->execute([json_encode($gabaritAvant, JSON_UNESCAPED_UNICODE)]);

/* Une question de FAQ créée par l'administration, numérotée 1 : la migration du
   lot 6 ne doit ni l'écraser, ni entrer en conflit avec elle. */
$B->exec("INSERT INTO chatbot_faq (id, question, answer, position, active)
          VALUES (1, 'Question de l''association', 'Réponse maison.', 1, 1)");

/* ─────────────────────────────────────────────────────────────────────────
 * MIGRATION — les instructions de update.php
 * ───────────────────────────────────────────────────────────────────────── */
$SQL_UPDATE = sqlUpdate($srcUpdate);
echo "\n=== MIGRATION de la base B (" . count($SQL_UPDATE) . " instructions) ===\n";
$errM = jouer($B, $SQL_UPDATE, 'update.php', true);
peuplerEditions($B, $srcUpdate, true);   // 1er passage : la table vient d'être créée
jouerLot6($B, $srcUpdate);               // migrations PHP du lot 6 (gabarit d'email, FAQ)
if ($errM) { echo "❌ Erreurs de migration :\n   - " . implode("\n   - ", $errM) . "\n"; $ko += count($errM); }
else echo "✅ Migration sans erreur\n";

/* ─────────────────────────────────────────────────────────────────────────
 * CONTRÔLES
 * ───────────────────────────────────────────────────────────────────────── */
echo "\n=== 1. Les inscriptions ont-elles survécu ? ===\n";
$apres = [
    'registrations'      => (int) $B->query('SELECT COUNT(*) FROM registrations')->fetchColumn(),
    'registrations_2024' => (int) $B->query('SELECT COUNT(*) FROM registrations_2024')->fetchColumn(),
];
foreach ($avant as $t => $n) {
    if ($apres[$t] === $n) echo "✅ $t : $n ligne(s) avant, $n après\n";
    else { echo "❌ $t : $n avant → {$apres[$t]} après — PERTE DE DONNÉES\n"; $ko++; }
}

echo "\n=== 2. `registrations` structurellement inchangée ? ===\n";
$schemaRegApres = schema($B, 'registrations');
if ($schemaRegAvant === $schemaRegApres) {
    echo "✅ Structure identique avant / après migration\n";
} else {
    echo "❌ Structure MODIFIÉE :\n";
    $a = explode(', ', $schemaRegAvant); $b = explode(', ', $schemaRegApres);
    foreach (array_diff($b, $a) as $d) echo "   + $d\n";
    foreach (array_diff($a, $b) as $d) echo "   - $d\n";
    $ko++;
}

echo "\n=== 3. Convergence install ↔ update ===\n";
$TABLES = ['editions','participants','participant_registrations','participant_auth_codes',
           'participant_devices','registration_transfers','resultats','traces_gps','detections','setting'];
foreach ($TABLES as $t) {
    $sa = schema($A, $t); $sb = schema($B, $t);
    if ($sa === null) { echo "❌ $t : absente de la base install\n"; $ko++; continue; }
    if ($sb === null) { echo "❌ $t : absente de la base update\n";  $ko++; continue; }
    if ($sa === $sb) { echo "✅ $t\n"; continue; }
    echo "❌ $t : schémas DIFFÉRENTS\n";
    $la = preg_split('/,\s*(?=`)/', $sa); $lb = preg_split('/,\s*(?=`)/', $sb);
    foreach (array_diff($la, $lb) as $d) echo "   install : $d\n";
    foreach (array_diff($lb, $la) as $d) echo "   update  : $d\n";
    $ko++;
}

echo "\n=== 4. Idempotence : seconde exécution de la migration ===\n";
$schemasAvant2 = [];
foreach ($TABLES as $t) $schemasAvant2[$t] = schema($B, $t);
$compteAvant2 = (int) $B->query('SELECT COUNT(*) FROM registrations')->fetchColumn();
$editionsAvant = $B->query('SELECT annee, is_active FROM editions ORDER BY annee')->fetchAll();

$errM2 = jouer($B, $SQL_UPDATE, 'update.php (2e passage)', true);
peuplerEditions($B, $srcUpdate, false);   // 2e passage : table déjà là → aucun peuplement
if ($errM2) { echo "❌ Erreurs au rejeu :\n   - " . implode("\n   - ", $errM2) . "\n"; $ko += count($errM2); }

$diff = 0;
foreach ($TABLES as $t) if ($schemasAvant2[$t] !== schema($B, $t)) { echo "❌ $t : schéma modifié au 2e passage\n"; $diff++; }
$compteApres2 = (int) $B->query('SELECT COUNT(*) FROM registrations')->fetchColumn();
if ($compteApres2 !== $compteAvant2) { echo "❌ registrations : $compteAvant2 → $compteApres2 au 2e passage\n"; $diff++; }
$editionsApres = $B->query('SELECT annee, is_active FROM editions ORDER BY annee')->fetchAll();
if ($editionsAvant !== $editionsApres) { echo "❌ editions modifiées au 2e passage\n"; $diff++; }
$ko += $diff;
if ($diff === 0) echo "✅ Aucun changement de schéma, de données ni d'éditions au rejeu\n";

echo "\n=== 5. Contenu de `editions` après migration ===\n";
foreach ($editionsApres as $e) printf("   %d — active=%d\n", $e['annee'], $e['is_active']);
$attendues = [(int) date('Y'), 2024];
sort($attendues);
$obtenues = array_map(fn($e) => (int) $e['annee'], $editionsApres);
sort($obtenues);
if ($obtenues === $attendues) echo "✅ Une édition pour l'année en cours (active) + une par archive\n";
else { echo "❌ Éditions attendues " . implode(',', $attendues) . " — obtenues " . implode(',', $obtenues) . "\n"; $ko++; }

echo "\n=== 6. Réglages : valeurs par défaut appliquées à la ligne existante ===\n";
$row = $B->query("SELECT participant_code_ttl_min, app_version_minimale, traces_gps_conservation_jours,
                         auth_codes_conservation_jours, api_v1_enabled FROM setting WHERE id = 1")->fetch();
// api_v1_enabled = 0 : l'API mobile doit rester FERMÉE après une migration.
// Un service qui s'ouvre tout seul est un service que personne n'a décidé
// d'ouvrir — c'est la valeur la plus importante de cette liste.
$attendu = ['participant_code_ttl_min' => 15, 'app_version_minimale' => '1.0.0',
            'traces_gps_conservation_jours' => 400, 'auth_codes_conservation_jours' => 30,
            'api_v1_enabled' => 0];
foreach ($attendu as $k => $v) {
    if ((string) $row[$k] === (string) $v) echo "✅ $k = $v\n";
    else { echo "❌ $k = " . var_export($row[$k], true) . " (attendu $v)\n"; $ko++; }
}

echo "\n=== 7. Compatibilité de collation : jointure clé métier ↔ inscriptions ===\n";
// C'est l'usage réel des lots 2 à 5 : retrouver l'inscription d'un rattachement.
// Une collation différente entre les deux tables déclencherait
// « Illegal mix of collations » — erreur de production, invisible au schéma.
try {
    $B->exec("INSERT INTO participants (email_chiffre, email_hmac) VALUES ('x', REPEAT('a',64))");
    $pid = (int) $B->lastInsertId();
    $B->exec("INSERT INTO participant_registrations (participant_id, annee, inscription_no)
              VALUES ($pid, " . (int) date('Y') . ", 'S1')");
    $n = (int) $B->query(
        "SELECT COUNT(*) FROM participant_registrations pr
           JOIN registrations r ON r.inscription_no = pr.inscription_no"
    )->fetchColumn();
    if ($n === 1) echo "✅ Jointure participant_registrations ↔ registrations : OK ($n ligne)\n";
    else { echo "❌ Jointure : $n ligne(s), attendu 1\n"; $ko++; }

    $m = (int) $B->query(
        "SELECT COUNT(*) FROM participant_registrations pr
           JOIN registrations_2024 a ON a.inscription_no = pr.inscription_no"
    )->fetchColumn();
    echo "✅ Jointure avec une table d'archive : OK ($m ligne)\n";
} catch (\Throwable $e) {
    echo "❌ Jointure impossible : " . $e->getMessage() . "\n"; $ko++;
}

/* ─────────────────────────────────────────────────────────────────────────
 * 8. LOT 6 — gabarit d'email et FAQ
 * Les deux migrations les plus délicates : elles ne créent pas des colonnes,
 * elles MODIFIENT du contenu déjà saisi par l'administration.
 * ───────────────────────────────────────────────────────────────────────── */
echo "\n=== 8. Lot 6 : gabarit d'email et FAQ ===\n";

$gabaritApres = json_decode((string) $B->query('SELECT mail_template_config FROM setting WHERE id = 1')
                                        ->fetchColumn(), true);
$ordre = $gabaritApres['section_order'] ?? [];

if (in_array('app', $ordre, true)) echo "✅ Section « app » ajoutée au gabarit\n";
else { echo "❌ Section « app » absente du gabarit : " . implode(',', $ordre) . "\n"; $ko++; }

$posQr  = array_search('qrcode', $ordre, true);
$posApp = array_search('app', $ordre, true);
if ($posQr !== false && $posApp === $posQr + 1) echo "✅ Placée juste après le QR code\n";
else { echo "❌ Mal placée (qrcode=$posQr, app=$posApp)\n"; $ko++; }

// Le point qui compte vraiment : on n'a pas écrasé le travail de l'admin.
$sansApp = array_values(array_diff($ordre, ['app']));
if ($sansApp === $gabaritAvant['section_order']) echo "✅ L'ordre choisi par l'administration est intact\n";
else { echo "❌ Ordre modifié : " . implode(',', $sansApp) . " au lieu de "
          . implode(',', $gabaritAvant['section_order']) . "\n"; $ko++; }

if (($gabaritApres['texts']['banner_title'] ?? '') === 'Texte choisi par l\'association'
    && ($gabaritApres['colors']['accent'] ?? '') === '#123456') {
    echo "✅ Textes et couleurs personnalisés préservés\n";
} else { echo "❌ Personnalisation perdue\n"; $ko++; }

if (!empty($gabaritApres['texts']['app_title'])) echo "✅ Textes par défaut de la section ajoutés\n";
else { echo "❌ Textes de la section « app » manquants\n"; $ko++; }

$nbFaq = (int) $B->query('SELECT COUNT(*) FROM chatbot_faq WHERE id BETWEEN 901 AND 999')->fetchColumn();
if ($nbFaq === 9) echo "✅ 9 questions de FAQ ajoutées\n";
else { echo "❌ $nbFaq question(s) de FAQ, attendu 9\n"; $ko++; }

$maison = $B->query("SELECT question FROM chatbot_faq WHERE id = 1")->fetchColumn();
if ($maison === 'Question de l\'association') echo "✅ La question créée par l'administration est intacte\n";
else { echo "❌ Question de l'administration altérée\n"; $ko++; }

/* Rejeu : c'est là que se voient les doublons et les écrasements. */
jouer($B, sqlUpdate($srcUpdate), 'update.php', true);
jouerLot6($B, $srcUpdate);
$nbFaq2 = (int) $B->query('SELECT COUNT(*) FROM chatbot_faq WHERE id BETWEEN 901 AND 999')->fetchColumn();
$ordre2 = json_decode((string) $B->query('SELECT mail_template_config FROM setting WHERE id = 1')
                                 ->fetchColumn(), true)['section_order'] ?? [];
if ($nbFaq2 === 9) echo "✅ Rejeu : aucun doublon de FAQ\n";
else { echo "❌ Rejeu : $nbFaq2 questions (doublons créés)\n"; $ko++; }

if (count(array_keys($ordre2, 'app', true)) === 1) echo "✅ Rejeu : la section « app » n'est pas ajoutée deux fois\n";
else { echo "❌ Rejeu : section « app » en double\n"; $ko++; }

/* ─────────────────────────────────────────────────────────────────────────
 * 9. LOT 7 — politique de confidentialité et durées de conservation
 * ───────────────────────────────────────────────────────────────────────── */
echo "\n=== 9. Lot 7 : politique de confidentialité et conservation ===\n";

$privacy = (string) $B->query("SELECT COALESCE(legal_privacy,'') FROM setting WHERE id = 1")->fetchColumn();
if (str_contains($privacy, 'Vos droits')) echo "✅ Texte de politique de confidentialité proposé\n";
else { echo "❌ Politique de confidentialité vide après migration\n"; $ko++; }

// Le point qui compte : un texte déjà rédigé ne doit JAMAIS être écrasé.
$B->exec("UPDATE setting SET legal_privacy = '<p>Texte rédigé par notre juriste.</p>'");
jouerLot6($B, $srcUpdate);
$apres = (string) $B->query("SELECT legal_privacy FROM setting WHERE id = 1")->fetchColumn();
if ($apres === '<p>Texte rédigé par notre juriste.</p>') {
    echo "✅ Un texte déjà rédigé n'est pas écrasé au rejeu\n";
} else { echo "❌ Le texte de l'association a été écrasé !\n"; $ko++; }

$dur = $B->query('SELECT auth_codes_conservation_jours, traces_gps_conservation_jours,
                         devices_revoques_jours, transferts_clos_jours
                    FROM setting WHERE id = 1')->fetch();
$attenduDur = ['auth_codes_conservation_jours' => 30, 'traces_gps_conservation_jours' => 400,
               'devices_revoques_jours' => 90, 'transferts_clos_jours' => 365];
foreach ($attenduDur as $k => $v) {
    if ((int) ($dur[$k] ?? 0) === $v) echo "✅ $k = $v jours\n";
    else { echo "❌ $k = " . var_export($dur[$k] ?? null, true) . " (attendu $v)\n"; $ko++; }
}

/* ─────────────────────────────────────────────────────────────────────────
 * 10. CHRONOMÉTRAGE — l'index qui rend la réception idempotente
 * C'est la pièce qui empêche un même passage devant la balise, renvoyé par une
 * application ayant perdu le réseau, de créer dix lignes.
 * ───────────────────────────────────────────────────────────────────────── */
echo "\n=== 10. Chronométrage : index d'unicité des détections ===\n";
$idx = (int) $B->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'detections'
                           AND INDEX_NAME = 'idx_unicite'")->fetchColumn();
if ($idx > 0) echo "✅ Index d'unicité posé sur la base migrée\n";
else { echo "❌ Index d'unicité absent — la réception ne serait pas idempotente\n"; $ko++; }

$col = (int) $B->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participants'
                           AND COLUMN_NAME = 'traces_consent_at'")->fetchColumn();
if ($col > 0) echo "✅ Consentement GPS présent (NULL par défaut = aucun suivi)\n";
else { echo "❌ Colonne de consentement GPS absente\n"; $ko++; }

// Le doublon doit être matériellement impossible.
$jour = (new DateTimeImmutable('-2 days'))->format('Y-m-d');
$B->exec("INSERT IGNORE INTO detections (annee, inscription_no, type, point, detecte_at)
          VALUES (" . (int) date('Y') . ", 'S1', 'beacon', 'arrivee', '$jour 10:00:00.000')");
$B->exec("INSERT IGNORE INTO detections (annee, inscription_no, type, point, detecte_at)
          VALUES (" . (int) date('Y') . ", 'S1', 'beacon', 'arrivee', '$jour 10:00:00.000')");
$n = (int) $B->query("SELECT COUNT(*) FROM detections WHERE inscription_no = 'S1'")->fetchColumn();
if ($n === 1) echo "✅ Une détection envoyée deux fois ne crée qu'une ligne\n";
else { echo "❌ $n ligne(s) : le doublon est passé\n"; $ko++; }

printf("\n%s\n", $ko === 0 ? "✅ AUDIT PRODUCTION : AUCUNE ANOMALIE" : "❌ AUDIT : $ko ANOMALIE(S)");
exit($ko > 0 ? 1 : 0);
