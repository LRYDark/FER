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

/* ─────────────────────────────────────────────────────────────────────────
 * MIGRATION — les instructions de update.php
 * ───────────────────────────────────────────────────────────────────────── */
$SQL_UPDATE = sqlUpdate($srcUpdate);
echo "\n=== MIGRATION de la base B (" . count($SQL_UPDATE) . " instructions) ===\n";
$errM = jouer($B, $SQL_UPDATE, 'update.php', true);
peuplerEditions($B, $srcUpdate, true);   // 1er passage : la table vient d'être créée
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

printf("\n%s\n", $ko === 0 ? "✅ AUDIT PRODUCTION : AUCUNE ANOMALIE" : "❌ AUDIT : $ko ANOMALIE(S)");
exit($ko > 0 ? 1 : 0);
