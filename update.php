<?php
/**
 * update.php — Migrations de base de données
 * Réservé aux administrateurs connectés. À lancer après une mise à jour ;
 * la page propose ensuite de se supprimer elle-même (bouton « Oui / Non »).
 */
require __DIR__ . '/src/core/config.php';
require_once __DIR__ . '/src/security/csrf.php';
require_once __DIR__ . '/src/content/registrations_core.php';

/* ════════════════════════════════════════════════════════════════════════════
 * SÉCURITÉ : accès strictement réservé à un administrateur connecté.
 * ----------------------------------------------------------------------------
 * Ce fichier exécute des migrations SQL et expose un outil d'import de fichier
 * (repair-dates). Sans ce garde, N'IMPORTE QUI atteignant l'URL pourrait les
 * déclencher. On refuse donc tout accès non authentifié / non-admin AVANT toute
 * autre logique — y compris avant le sous-outil repair-dates et le handler de
 * suppression ci-dessous. (Défense en profondeur : reste valable même si le
 * fichier est censé être supprimé après usage.)
 * ════════════════════════════════════════════════════════════════════════════ */
header('X-Robots-Tag: noindex, nofollow', true);
if (!isset($_SESSION['uid']) || (($_SESSION['role'] ?? null) !== 'admin')) {
    http_response_code(403);
    header('Location: login.php');   // update.php et login.php sont à la racine
    exit;
}

/* ════════════════════════════════════════════════════════════════════════════
 * AUTO-SUPPRESSION : « Voulez-vous supprimer update.php ? »
 * ----------------------------------------------------------------------------
 * Déclenché par le bouton « Oui, supprimer » affiché en bas de la page de
 * résultat. POST protégé par CSRF (admin déjà vérifié ci-dessus). On traite ce
 * cas AVANT de rejouer les migrations : cliquer « Oui » ne relance rien, ça
 * supprime juste le fichier et affiche une confirmation. « Non » = simple lien
 * retour dashboard, aucune action.
 * ════════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_self') {
    $csrfOk  = csrf_verify();
    $deleted = $csrfOk ? @unlink(__FILE__) : false;

    // Même coquille que le rapport de migration et que les sous-outils ?tool=…
    updToolHead(
        $deleted ? 'update.php supprimé' : (!$csrfOk ? 'Session expirée' : 'Suppression impossible'),
        $deleted ? 'Le fichier de migration n\'est plus présent sur le serveur.' : 'L\'opération n\'a pas pu aboutir.',
        $deleted
            ? 'M5 13l4 4L19 7'
            : 'M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z'
    );
    ?>
    <?php if ($deleted): ?>
      <div class="oc-alert oc-alert-success"><i class="bi bi-check-circle me-1"></i>
        Le fichier <code>update.php</code> a bien été supprimé du serveur.
        Le lien « Mise à jour BDD » disparaîtra du menu d'administration.</div>
    <?php elseif (!$csrfOk): ?>
      <div class="oc-alert oc-alert-danger"><i class="bi bi-shield-exclamation me-1"></i>
        Jeton de sécurité invalide. Rechargez la page et réessayez.</div>
      <div class="tool-actions">
        <a href="update.php" class="oc-btn-secondary" style="text-decoration:none"><i class="bi bi-arrow-clockwise"></i> Recharger</a>
      </div>
    <?php else: ?>
      <div class="oc-alert oc-alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>
        Le serveur n'a pas pu supprimer <code>update.php</code> (permissions du fichier).
        Supprimez-le manuellement via FTP ou votre gestionnaire de fichiers.</div>
    <?php endif; ?>
    <?php
    updToolFoot('inc/dashboard.php', 'Retour au tableau de bord');
    exit;
}

/* ════════════════════════════════════════════════════════════════════════════
 * OUTIL : Réparation des dates d'inscription (created_at)
 * ----------------------------------------------------------------------------
 * Sous-page dédiée (update.php?tool=repair-dates) : on ouvre le fichier d'export
 * AssoConnect d'origine, et on recorrige UNIQUEMENT les inscriptions dont la date
 * d'ajout a été inversée jour/mois par l'ancien bug d'import. Aperçu par défaut
 * (n'écrit rien) ; l'écriture nécessite de cocher explicitement « Appliquer ».
 * Cette branche se termine par exit : elle ne déclenche JAMAIS les migrations.
 * ════════════════════════════════════════════════════════════════════════════ */
if (($_GET['tool'] ?? '') === 'repair-dates') {

    $report   = null;
    $errorMsg = null;
    $applied  = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            $errorMsg = 'Jeton de sécurité invalide. Rechargez la page et réessayez.';
        } elseif (empty($_FILES['repair_file']) || ($_FILES['repair_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errorMsg = 'Aucun fichier reçu (ou upload échoué). Sélectionnez un fichier Excel (.xlsx / .xls).';
        } else {
            $apply  = !empty($_POST['apply']);
            $report = regcore_repairCreatedAtDates(
                $pdo,
                $_FILES['repair_file']['tmp_name'],
                (string) $_FILES['repair_file']['name'],
                $apply
            );
            if (empty($report['ok'])) {
                $errorMsg = $report['message'] ?? 'Erreur inconnue lors du traitement du fichier.';
                $report   = null;
            } else {
                $applied = $apply;
            }
        }
    }

    /* Affichage JJ/MM/AAAA d'une valeur 'Y-m-d H:i:s' */
    $fmtDate = static function ($ymdhms): string {
        $p = explode('-', substr((string) $ymdhms, 0, 10));
        return count($p) === 3 ? "{$p[2]}/{$p[1]}/{$p[0]}" : htmlspecialchars((string) $ymdhms);
    };
    // Même coquille que le rapport de migration (thème, accent, clair/sombre).
    updToolHead(
        '<i class="bi bi-calendar-check me-2"></i>Réparer les dates d\'inscription',
        "Recorrige les « dates d'ajout » inversées (jour/mois) à partir du fichier d'export AssoConnect d'origine.",
        'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'
    );
    ?>
    <?php if ($errorMsg !== null): ?>
      <div class="oc-alert oc-alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <?php if ($report !== null): ?>
      <?php
        $nbFix    = count($report['fixes']);
        $nbFuture = count($report['future_unmatched']);
      ?>
      <?php if ($applied): ?>
        <div class="oc-alert oc-alert-success"><i class="bi bi-check-circle me-1"></i>
          <strong><?= (int) $report['applied'] ?></strong> date(s) corrigée(s) en base.</div>
      <?php elseif ($nbFix > 0): ?>
        <div class="oc-alert oc-alert-info"><i class="bi bi-eye me-1"></i>
          <strong>Aperçu</strong> — <?= $nbFix ?> date(s) seraient corrigées. <em>Rien n'a été modifié.</em>
          Pour appliquer : re-sélectionnez le fichier, cochez « Appliquer » puis relancez.</div>
      <?php else: ?>
        <div class="oc-alert oc-alert-success"><i class="bi bi-check-circle me-1"></i>
          Aucune date à corriger : tout est cohérent avec le fichier
          (<?= (int) $report['source_count'] ?> inscription(s) lues).</div>
      <?php endif; ?>

      <?php if ($nbFix > 0): ?>
        <div class="tool-scroll">
          <table class="tool-table">
            <thead><tr><th>N° inscription</th><th>Date actuelle</th><th></th><th>Date corrigée</th></tr></thead>
            <tbody>
            <?php foreach ($report['fixes'] as $f): ?>
              <tr>
                <td class="mono"><?= htmlspecialchars($f['no']) ?></td>
                <td class="tool-old"><?= $fmtDate($f['old']) ?></td>
                <td><i class="bi bi-arrow-right"></i></td>
                <td class="tool-new"><?= $fmtDate($f['new']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($nbFuture > 0): ?>
        <div class="oc-alert oc-alert-warning" style="margin-top:16px;">
          <i class="bi bi-exclamation-triangle me-1"></i>
          <strong><?= $nbFuture ?> inscription(s)</strong> ont une date dans le futur mais ne sont
          <strong>pas</strong> dans ce fichier — à corriger à la main, ou relancez avec le fichier
          d'import qui les contient :
          <div class="tool-scroll" style="margin-top:8px;max-height:160px;">
            <table class="tool-table">
              <thead><tr><th>N° inscription</th><th>Date actuelle</th></tr></thead>
              <tbody>
              <?php foreach ($report['future_unmatched'] as $u): ?>
                <tr><td class="mono"><?= htmlspecialchars($u['no']) ?></td><td><?= $fmtDate($u['created_at']) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <p class="tool-intro">
        Sélectionnez le <strong>fichier d'export AssoConnect</strong> (le même que pour l'import).
        L'outil relit la vraie date de création (valeur brute, non ambiguë) et compare avec la base.
        Par défaut c'est un <strong>aperçu</strong> : rien n'est modifié tant que vous ne cochez pas
        « Appliquer ». <strong>Pensez à sauvegarder la base avant d'appliquer.</strong>
      </p>
    <?php endif; ?>

    <form method="post" action="?tool=repair-dates" enctype="multipart/form-data" style="margin-top:8px;">
      <?= csrf_field() ?>
      <div class="oc-form-group">
        <label class="oc-label" for="repair_file">Fichier Excel d'export (.xlsx / .xls)</label>
        <input type="file" id="repair_file" name="repair_file" accept=".xlsx,.xls" class="tool-file" required>
      </div>
      <label class="tool-check tool-check-apply">
        <input type="checkbox" name="apply" value="1">
        <span><strong>Appliquer réellement</strong> les corrections en base (sinon : aperçu seulement)</span>
      </label>
      <div class="tool-actions">
        <button type="submit" class="oc-btn"><i class="bi bi-play-fill"></i> Analyser / Corriger</button>
        <a href="inc/dashboard.php" class="oc-btn-secondary" style="text-decoration:none"><i class="bi bi-arrow-left"></i> Dashboard</a>
      </div>
    </form>
    <?php
    updToolFoot();
    exit;
}

/* ════════════════════════════════════════════════════════════════════════════
 * OUTILS ?tool=… — coquille HTML commune
 * ----------------------------------------------------------------------------
 * Les trois sous-outils (réparation des dates, consolidation des archives,
 * normalisation des naissances) partagent la coquille du rapport de migration :
 * même thème (jetons css/tokens.css), même accent, même bascule clair/sombre,
 * mêmes classes .oc-*. Une seule mise en page à maintenir pour les quatre pages.
 * ════════════════════════════════════════════════════════════════════════════ */
/**
 * Une table existe-t-elle ?
 *
 * Doublon apparent de la closure `$tableExists` définie plus bas, mais
 * INDISPENSABLE : une closure affectée à une variable n'est pas remontée à la
 * compilation, elle n'existe qu'à partir de sa ligne d'affectation. Les
 * sous-outils ?tool=… s'exécutent AVANT — ils ne peuvent donc pas l'utiliser.
 * Une déclaration de fonction, elle, est disponible dans tout le fichier.
 */
function updTableExists(PDO $pdo, string $name): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$name]);
    return (int) $st->fetchColumn() > 0;
}

function updToolHead(string $title, string $subtitle, string $iconPath): void
{
    global $pdo;   // auth-head.php lit la couleur d'accent et le thème dans la BDD
    ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= strip_tags($title) ?> — Forbach en Rose</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php include __DIR__ . '/src/partials/auth-head.php'; ?>
</head>
<body>
<div class="auth">
  <div class="auth-frame">
    <div class="auth-pane">
      <a class="brand" href="inc/dashboard.php">
        <?php if (file_exists(__DIR__ . '/files/_logos/logo_fer_rose.png')): ?>
          <img src="files/_logos/logo_fer_rose.png" alt="" style="height:32px;width:auto">
        <?php endif; ?>
        <span class="name">Forbach en Rose</span>
      </a>
      <div class="inner is-widest">
        <div class="oc-icon-area">
          <div class="oc-icon-circle">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $iconPath ?>"/></svg>
          </div>
          <h1 class="oc-title"><?= $title ?></h1>
          <p class="oc-subtitle"><?= $subtitle ?></p>
        </div>
        <div class="update-body">
    <?php
}

function updToolFoot(string $backHref = 'update.php', string $backLabel = 'Retour aux migrations'): void
{
    ?>
        </div><!-- /update-body -->
        <div class="update-footer">
          <p style="margin:0;text-align:center">
            <a href="<?= htmlspecialchars($backHref) ?>" class="oc-back">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:15px;height:15px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
              <?= htmlspecialchars($backLabel) ?>
            </a>
          </p>
        </div>
      </div><!-- /inner -->
    </div><!-- /auth-pane -->
    <?php include __DIR__ . '/src/partials/auth-art.php'; ?>
  </div><!-- /auth-frame -->
</div><!-- /auth -->
</body>
</html>
    <?php
}

/* ════════════════════════════════════════════════════════════════════════════
 * OUTIL : Contrôle d'intégrité (update.php?tool=check-integrity)
 * ----------------------------------------------------------------------------
 * Les tables du lot 1 désignent un coureur par sa clé métier (annee,
 * inscription_no) et non par `registrations.id` : MySQL ne peut donc PAS
 * garantir qu'un résultat pointe vers une inscription existante. C'est le prix
 * à payer pour survivre à l'archivage annuel — cet outil est la contrepartie.
 *
 * 100 % LECTURE SEULE : aucune écriture, aucune case « Appliquer ».
 * La branche se termine par exit : elle ne déclenche JAMAIS les migrations.
 * ════════════════════════════════════════════════════════════════════════════ */
if (($_GET['tool'] ?? '') === 'check-integrity') {

    require_once __DIR__ . '/src/core/registrations_resolver.php';

    $errorMsg   = null;
    $orphelines = [];   // par table : lignes dont (annee, inscription_no) ne résout pas
    $sansNo     = [];   // inscriptions sans inscription_no : ni revendicables ni chronométrables
    $derive     = [];   // dérive de schéma entre tables d'inscriptions
    $tables     = [];
    $structureInattendue = false;   // table du lot 1 sans sa clé métier (annee, inscription_no)

    // Tables du lot 1 portant une clé métier, et libellé lisible.
    $aControler = [
        'participant_registrations' => 'Rattachements compte ↔ inscription',
        'registration_transfers'    => 'Transferts d\'inscription',
        'resultats'                 => 'Résultats',
        'traces_gps'                => 'Traces GPS',
        'detections'                => 'Détections',
    ];

    try {
        $tables = regres_listTables($pdo);
        $derive = regres_schemaDrift($pdo);

        // ── Lignes sans inscription_no dans les tables d'inscriptions ──
        foreach ($tables as $t) {
            $cols = regres_tableColumns($pdo, $t['table']);
            if (!in_array('inscription_no', $cols, true)) {
                $sansNo[] = ['table' => $t['table'], 'annee' => $t['annee'], 'n' => null];
                continue;
            }
            $n = (int) $pdo->query(
                "SELECT COUNT(*) FROM `{$t['table']}` WHERE inscription_no IS NULL OR TRIM(inscription_no) = ''"
            )->fetchColumn();
            if ($n > 0) $sansNo[] = ['table' => $t['table'], 'annee' => $t['annee'], 'n' => $n];
        }

        // ── Références orphelines ──
        // On construit l'ensemble des couples (annee, inscription_no) réellement
        // existants, puis on compare en PHP : une jointure SQL est impossible,
        // les inscriptions étant réparties sur plusieurs tables.
        $connus = [];
        foreach ($tables as $t) {
            $cols = regres_tableColumns($pdo, $t['table']);
            if (!in_array('inscription_no', $cols, true)) continue;
            foreach ($pdo->query("SELECT inscription_no FROM `{$t['table']}`")->fetchAll(PDO::FETCH_COLUMN) as $no) {
                $no = trim((string) $no);
                if ($no !== '') $connus[$t['annee'] . '|' . $no] = true;
            }
        }

        // Colonnes réelles d'une table du lot 1 (les tables d'inscriptions passent,
        // elles, par regres_tableColumns qui n'accepte que leurs noms).
        $colonnesDe = function (string $t) use ($pdo): array {
            $st = $pdo->prepare(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $st->execute([$t]);
            return $st->fetchAll(PDO::FETCH_COLUMN);
        };

        foreach ($aControler as $table => $libelle) {
            if (!updTableExists($pdo, $table)) {
                $orphelines[$table] = ['libelle' => $libelle, 'total' => null, 'lignes' => [], 'ancienne' => false];
                continue;
            }

            // Garde de robustesse : une table du lot 1 sans sa clé métier est
            // forcément anormale (structure étrangère, migration partielle). On le
            // signale au lieu de laisser remonter un « Unknown column 'annee' ».
            $cols = $colonnesDe($table);
            if (!in_array('annee', $cols, true) || !in_array('inscription_no', $cols, true)) {
                $orphelines[$table] = ['libelle' => $libelle, 'total' => null, 'lignes' => [], 'structure_ko' => true];
                $structureInattendue = true;
                continue;
            }

            $rows = $pdo->query("SELECT id, annee, inscription_no FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            $ko = [];
            foreach ($rows as $r) {
                $cle = (int) $r['annee'] . '|' . trim((string) $r['inscription_no']);
                if (!isset($connus[$cle])) $ko[] = $r;
            }
            $orphelines[$table] = ['libelle' => $libelle, 'total' => count($rows), 'lignes' => $ko, 'ancienne' => false];
        }
    } catch (\Throwable $e) {
        $errorMsg = 'Contrôle interrompu : ' . $e->getMessage();
    }

    $nbOrphelines = 0;
    foreach ($orphelines as $o) $nbOrphelines += count($o['lignes']);
    $nbSansNo = 0;
    foreach ($sansNo as $s) $nbSansNo += (int) $s['n'];

    updToolHead(
        '<i class="bi bi-shield-check me-2"></i>Contrôle d\'intégrité',
        "Vérifie que chaque référence <code>(année, n° d'inscription)</code> désigne bien une inscription existante. Lecture seule.",
        'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'
    );
    ?>
    <?php if ($errorMsg !== null): ?>
      <div class="oc-alert oc-alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($errorMsg) ?></div>
    <?php else: ?>

      <?php if ($structureInattendue): ?>
        <div class="oc-alert oc-alert-danger">
          <i class="bi bi-exclamation-octagon me-1"></i>
          <strong>Structure inattendue.</strong>
          <p style="margin:8px 0 0">
            Une ou plusieurs tables du lot 1 n'ont pas leur clé métier
            <code>(annee, inscription_no)</code> — voir la colonne « Orphelines » ci-dessous.
            Le contrôle ne peut pas s'y appliquer. Relancez <code>update.php</code> ; si le
            problème persiste, comparez la structure avec <code>SHOW CREATE TABLE</code>.
          </p>
        </div>
      <?php elseif ($nbOrphelines === 0 && $nbSansNo === 0): ?>
        <div class="oc-alert oc-alert-success"><i class="bi bi-check-circle me-1"></i>
          <strong>Aucune anomalie.</strong> Toutes les références pointent vers une inscription existante.</div>
      <?php else: ?>
        <div class="oc-alert oc-alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>
          <strong><?= (int) $nbOrphelines ?> référence(s) orpheline(s)</strong> et
          <strong><?= (int) $nbSansNo ?> inscription(s) sans numéro</strong>. Détail ci-dessous.</div>
      <?php endif; ?>

      <h2 class="tool-section-title">Tables d'inscriptions détectées</h2>
      <div class="tool-scroll">
        <table class="tool-table">
          <thead><tr><th>Table</th><th class="num">Année</th><th>Rôle</th><th>Colonnes manquantes</th></tr></thead>
          <tbody>
          <?php foreach ($derive as $d): ?>
            <tr>
              <td class="mono"><?= htmlspecialchars($d['table']) ?></td>
              <td class="num"><?= (int) $d['annee'] ?></td>
              <td><?= $d['table'] === 'registrations'
                    ? '<span class="tool-tag tool-tag-date">édition en cours — écriture</span>'
                    : '<span class="tool-tag">archive — lecture seule</span>' ?></td>
              <td><?= $d['manquantes']
                    ? '<span class="tool-tag tool-tag-note">' . htmlspecialchars(implode(', ', $d['manquantes'])) . '</span>'
                    : '<span class="tool-tag tool-tag-date">aucune</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="tool-intro" style="margin-top:8px">
        Les archives ont été créées par <code>CREATE TABLE … LIKE registrations</code> à des dates
        différentes : leurs colonnes divergent. L'aiguilleur renvoie <code>null</code> pour les
        colonnes absentes plutôt que d'échouer — aucune action n'est requise ici, c'est un constat.
      </p>

      <h2 class="tool-section-title">Références orphelines</h2>
      <div class="tool-scroll">
        <table class="tool-table">
          <thead><tr><th>Table</th><th>Rôle</th><th class="num">Lignes</th><th class="num">Orphelines</th><th>Exemples</th></tr></thead>
          <tbody>
          <?php foreach ($orphelines as $table => $o): ?>
            <tr>
              <td class="mono"><?= htmlspecialchars($table) ?></td>
              <td><?= htmlspecialchars($o['libelle']) ?></td>
              <td class="num"><?= $o['total'] === null ? '—' : (int) $o['total'] ?></td>
              <td class="num"><?= !empty($o['structure_ko'])
                    ? '<span class="tool-tag tool-tag-inconnu">structure inattendue</span>'
                    : ($o['total'] === null
                        ? '<span class="tool-tag">table absente</span>'
                        : (count($o['lignes']) > 0
                            ? '<span class="tool-tag tool-tag-inconnu">' . count($o['lignes']) . '</span>'
                            : '<span class="tool-tag tool-tag-date">0</span>')) ?></td>
              <td class="mono"><?php
                $ex = array_slice($o['lignes'], 0, 5);
                $lbl = array_map(fn($r) => '#' . (int) $r['id'] . ' → ' . (int) $r['annee'] . '/' . htmlspecialchars((string) $r['inscription_no']), $ex);
                echo $lbl ? implode('<br>', $lbl) . (count($o['lignes']) > 5 ? '<br>…' : '') : '—';
              ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="tool-intro" style="margin-top:8px">
        Une orpheline signifie qu'un résultat, un transfert ou un rattachement désigne une
        inscription qui n'existe dans aucune table. Cause la plus probable&nbsp;: un
        <code>inscription_no</code> modifié après coup dans l'administration.
      </p>

      <h2 class="tool-section-title">Inscriptions sans numéro</h2>
      <?php if (empty($sansNo)): ?>
        <div class="oc-alert oc-alert-success"><i class="bi bi-check-circle me-1"></i>
          Toutes les inscriptions ont un numéro.</div>
      <?php else: ?>
        <div class="tool-scroll">
          <table class="tool-table">
            <thead><tr><th>Table</th><th class="num">Année</th><th class="num">Sans numéro</th></tr></thead>
            <tbody>
            <?php foreach ($sansNo as $s): ?>
              <tr>
                <td class="mono"><?= htmlspecialchars($s['table']) ?></td>
                <td class="num"><?= (int) $s['annee'] ?></td>
                <td class="num"><?= $s['n'] === null ? 'colonne absente' : (int) $s['n'] ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="tool-intro" style="margin-top:8px">
          Une inscription sans numéro ne peut être <strong>ni revendiquée dans l'espace coureur,
          ni chronométrée</strong> : la clé métier lui manque. À corriger dans l'administration.
        </p>
      <?php endif; ?>
    <?php endif; ?>
    <?php
    updToolFoot();
    exit;
}

$migrations = [
    "ALTER TABLE `setting` ADD COLUMN `mail_template_config` TEXT DEFAULT NULL",
    "ALTER TABLE `timeline_items` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL",
    "ALTER TABLE `setting` DROP COLUMN `footer`",
    "ALTER TABLE `setting` ADD COLUMN `theme_primary_color` VARCHAR(7) DEFAULT '#db2777'",
    "ALTER TABLE `setting` ADD COLUMN `theme_secondary_color` VARCHAR(7) DEFAULT '#0f172a'",
    "ALTER TABLE `setting` ADD COLUMN `theme_border_radius` INT DEFAULT 12",
    "ALTER TABLE `setting` ADD COLUMN `theme_font_family` VARCHAR(100) DEFAULT 'Inter'",
    // Couleur propre à chaque grand aplat (bandeau actualités, pied de page,
    // carte « Rester informé »). NULL = la couleur du thème s applique.
    "ALTER TABLE `setting` ADD COLUMN `color_news_band` VARCHAR(7) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `color_partners` VARCHAR(7) DEFAULT NULL",
    // Hauteur du logo du pied de page, réglée dans la carte « Footer ».
    "ALTER TABLE `setting` ADD COLUMN `footer_logo_height` INT DEFAULT 56",
    "ALTER TABLE `setting` ADD COLUMN `color_footer` VARCHAR(7) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `color_newsletter` VARCHAR(7) DEFAULT NULL",
    // Couleur du ruban de la carte « Rester informé » (SVG en currentColor).
    "ALTER TABLE `setting` ADD COLUMN `color_newsletter_deco` VARCHAR(7) DEFAULT NULL",
    // `theme_dark_enabled` : la ligne d'ajout a été retirée — jamais écrit,
    // jamais lu. Le retrait des bases qui l'ont se fait plus bas, sous
    // condition : un DROP inconditionnel échouerait à chaque passage sur une
    // base neuve, et polluerait le rapport de migration d'une erreur permanente.
    "ALTER TABLE `setting` ADD COLUMN `flash_bg_color` VARCHAR(7) DEFAULT '#db2777'",
    "ALTER TABLE `setting` ADD COLUMN `flash_text_color` VARCHAR(7) DEFAULT '#ffffff'",
    "ALTER TABLE `setting` ADD COLUMN `theme_dark_primary_color` VARCHAR(7) DEFAULT '#f472b6'",
    "ALTER TABLE `setting` ADD COLUMN `theme_dark_secondary_color` VARCHAR(7) DEFAULT '#e2e8f0'",
    "ALTER TABLE `setting` ADD COLUMN `footer_logo` VARCHAR(255) DEFAULT 'logo_blanc.png'",
    "ALTER TABLE `setting` ADD COLUMN `registration_auto_open` DATETIME DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `registration_auto_close` DATETIME DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `mail_provider` ENUM('google','smtp') NOT NULL DEFAULT 'google'",
    "ALTER TABLE `setting` ADD COLUMN `smtp_host` VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `smtp_port` INT DEFAULT 465",
    "ALTER TABLE `setting` ADD COLUMN `smtp_user` VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `smtp_pass` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `smtp_encryption` ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl'",
    "ALTER TABLE `setting` ADD COLUMN `smtp_from_email` VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `smtp_from_name` VARCHAR(255) DEFAULT 'Forbach en Rose'",
    "ALTER TABLE `photo_years` ADD COLUMN `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP",
    "ALTER TABLE `partners_years` ADD COLUMN `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP",
    "ALTER TABLE `setting` ADD COLUMN `course_km` INT(10) DEFAULT 7",
    "ALTER TABLE `setting` ADD COLUMN `notify_recipients` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `notify_toggles` TEXT DEFAULT NULL",
    // Note : accueil_custom_content / accueil_custom_position / accueil_news_before_partners
    // ont été remplacées par accueil_layout (JSON). La migration des données + le DROP de
    // ces 3 colonnes obsolètes sont effectués plus bas (bloc dédié).
    "ALTER TABLE `setting` ADD COLUMN `accueil_layout` MEDIUMTEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `accueil_styles` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `accueil_texts` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `accueil_geometry` TEXT DEFAULT NULL",
    // Système brouillon : chaque réglage de l'accueil a maintenant une version
    // "draft" (modifications en cours dans l'éditeur) et une version "published"
    // (visible sur la vraie page). Le bouton "Publier" copie draft → published.
    "ALTER TABLE `setting` ADD COLUMN `accueil_layout_draft` MEDIUMTEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `accueil_styles_draft` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `accueil_texts_draft` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `accueil_geometry_draft` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `accueil_draft_updated_at` DATETIME DEFAULT NULL",
    // Section "Retrouver le départ" : point de départ de la course (adresse OU coordonnées)
    "ALTER TABLE `setting` ADD COLUMN `start_point_address` VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `start_point_coords` VARCHAR(64) DEFAULT NULL",
    // Newsletter : abonnés + horodatage d'envoi de la notif "nouvel article"
    "CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `email` varchar(255) NOT NULL,
      `status` enum('subscribed','unsubscribed') NOT NULL DEFAULT 'subscribed',
      `created_at` timestamp NULL DEFAULT current_timestamp(),
      `unsubscribed_at` timestamp NULL DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `email_idx` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "ALTER TABLE `news` ADD COLUMN `newsletter_sent_at` TIMESTAMP NULL DEFAULT NULL",
    "ALTER TABLE `users` ADD COLUMN `permissions` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `role_permissions` TEXT DEFAULT NULL",
    // Cloudflare Turnstile : protection anti-bot du formulaire partenaire (et autres)
    "ALTER TABLE `setting` ADD COLUMN `turnstile_sitekey` VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `turnstile_secret` TEXT DEFAULT NULL",
    "ALTER TABLE `users` ADD COLUMN `totp_secret` VARCHAR(64) DEFAULT NULL",
    "ALTER TABLE `users` ADD COLUMN `totp_pending_secret` VARCHAR(64) DEFAULT NULL",
    "ALTER TABLE `users` ADD COLUMN `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `users` ADD COLUMN `default_2fa_method` ENUM('email','totp','passkey') NOT NULL DEFAULT 'email'",
    "CREATE TABLE IF NOT EXISTS `user_passkeys` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NOT NULL,
      `credential_id` VARCHAR(1024) NOT NULL,
      `public_key` TEXT NOT NULL,
      `sign_count` INT UNSIGNED NOT NULL DEFAULT 0,
      `name` VARCHAR(100) NOT NULL DEFAULT 'Ma clé d\'accès',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `last_used` DATETIME DEFAULT NULL,
      UNIQUE KEY `idx_cred` (credential_id(255)),
      INDEX `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    // API externe : permet à des applications tierces de se connecter au site
    // (import Excel, ajout d'inscrit, consultation des statistiques) via un
    // identifiant + token. Le token est stocké chiffré (AES-256-GCM).
    "ALTER TABLE `setting` ADD COLUMN `api_enabled` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `setting` ADD COLUMN `api_user` VARCHAR(64) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `api_token` TEXT DEFAULT NULL",
    // Les appels à l'API sont désormais journalisés dans le fichier
    // storage/logs/api.log (et non en BDD) : on supprime l'ancienne table si
    // elle a été créée par une version précédente de cette mise à jour.
    "DROP TABLE IF EXISTS `api_logs`",

    // Suivi du paiement : montant dû par inscrit (0 = non payé / gratuit / enfant -12 ans).
    "ALTER TABLE `registrations` ADD COLUMN `montant_du` DECIMAL(10,2) NOT NULL DEFAULT 0",

    // Catégorie d'inscrit (« prestation » AssoConnect) : tarif_unique / enfant_gratuit / enfant_tshirt.
    // Permet de distinguer un enfant -12 ans AVEC t-shirt (payant, compté pour le QR/t-shirt)
    // d'un adulte « tarif unique » alors qu'ils ont le même montant. NULL = ancien inscrit (= tarif unique).
    "ALTER TABLE `registrations` ADD COLUMN `prestation` VARCHAR(30) DEFAULT NULL",

    // Mode "Ajout multiple" (saisie en lot, ex. entreprise avec N inscrits) :
    //   - visible_saisie_multiple : champ affiché dans le formulaire bulk ?
    //   - required_saisie_multiple : champ obligatoire en mode bulk ?
    // Désactivés par défaut (0) — l'admin choisit explicitement les champs à inclure
    // depuis "Gestion des champs du formulaire".
    "ALTER TABLE `forms` ADD COLUMN `visible_saisie_multiple` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `forms` ADD COLUMN `required_saisie_multiple` TINYINT(1) NOT NULL DEFAULT 0",

    // AssoConnect : lien direct (bouton de repli affiché sous le formulaire intégré).
    "ALTER TABLE `setting` ADD COLUMN `assoconnect_url` VARCHAR(512) DEFAULT NULL",
    // AssoConnect : domaines autorisés dans la CSP (gérables depuis les Réglages).
    "ALTER TABLE `setting` ADD COLUMN `assoconnect_csp_domains` TEXT DEFAULT NULL",

    // Journal d'activité des contenus (albums, partenaires, actualités, timeline) :
    // trace création / modification / corbeille / restauration / suppression définitive
    // et l'auteur de chaque action. Affiché via l'onglet « Logs » (droit content.logs.view).
    "CREATE TABLE IF NOT EXISTS `content_logs` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `content_type` VARCHAR(20) NOT NULL,
      `entity_type` VARCHAR(40) DEFAULT NULL,
      `entity_id` INT DEFAULT NULL,
      `entity_title` VARCHAR(255) DEFAULT NULL,
      `action` VARCHAR(20) NOT NULL,
      `user_id` INT DEFAULT NULL,
      `user_email` VARCHAR(255) DEFAULT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_content` (`content_type`, `created_at`),
      INDEX `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // Import automatique AssoConnect : table de configuration à ligne unique (id=1).
    // Le mot de passe AssoConnect est chiffré (AES-256-GCM, ENCRYPTION_KEY du site) dans ac_password_enc.
    // Le QR n'est PAS une option : il suit le réglage global qrcode_mail_mode, comme l'import manuel.
    // Le token partagé des endpoints (worker_token) est AUTO-GÉNÉRÉ et géré depuis l'UI.
    "CREATE TABLE IF NOT EXISTS `sync_assoconnect` (
      `id` TINYINT(1) NOT NULL DEFAULT 1,
      `enabled` TINYINT(1) NOT NULL DEFAULT 0,
      `ac_login_url` VARCHAR(500) DEFAULT NULL,
      `ac_registrants_url` VARCHAR(500) DEFAULT NULL,
      `ac_email` VARCHAR(190) DEFAULT NULL,
      `ac_password_enc` BLOB DEFAULT NULL,
      `worker_token` VARCHAR(64) DEFAULT NULL,
      `import_send_mail` TINYINT(1) NOT NULL DEFAULT 1,
      `interval_min` INT NOT NULL DEFAULT 30,
      `run_requested` TINYINT(1) NOT NULL DEFAULT 0,
      `test_requested` TINYINT(1) NOT NULL DEFAULT 0,
      `last_run_at` DATETIME DEFAULT NULL,
      `last_status` ENUM('ok','error','running','idle') NOT NULL DEFAULT 'idle',
      `last_message` TEXT DEFAULT NULL,
      `last_rows` INT NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "INSERT IGNORE INTO `sync_assoconnect` (`id`) VALUES (1)",
    // Tables déjà créées par une version antérieure : on ajoute la colonne du token.
    "ALTER TABLE `sync_assoconnect` ADD COLUMN `worker_token` VARCHAR(64) DEFAULT NULL",

    // Champ libre « Commentaire » sur chaque inscription. Sert aussi à stocker
    // l'autorisation du représentant légal pour les inscrits mineurs (nom/prénom).
    // Chiffré (encrypted=1 dans `forms`) car il peut contenir des données personnelles.
    "ALTER TABLE `registrations` ADD COLUMN `commentaire` TEXT DEFAULT NULL",

    // Déverrouillage du champ « Email » : il était figé (is_locked=1) et donc non
    // modifiable depuis « Gestion des champs du formulaire ». On le déverrouille pour
    // permettre à l'admin de gérer son caractère obligatoire et sa visibilité.
    "UPDATE `forms` SET `is_locked` = 0 WHERE `bdd_column` = 'email'",

    // Préférences d'interface par utilisateur (JSON) : ordre des colonnes du tableau
    // du dashboard, et toute future préférence d'affichage propre à chaque compte.
    "ALTER TABLE `users` ADD COLUMN `ui_prefs` TEXT DEFAULT NULL",

    // Tarif enfant automatique selon l'âge (import Excel / ajout multiple) :
    //   - child_pricing_enabled : active la surcharge du montant pour les < seuil
    //   - child_age_threshold   : âge seuil (12 par défaut, sert aussi aux libellés « -N ans »)
    //   - child_amount          : montant appliqué aux enfants sous le seuil (0 = gratuit)
    "ALTER TABLE `setting` ADD COLUMN `child_pricing_enabled` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `setting` ADD COLUMN `child_age_threshold` INT(10) NOT NULL DEFAULT 12",
    "ALTER TABLE `setting` ADD COLUMN `child_amount` INT(10) NOT NULL DEFAULT 0",

    // ─────────────────────────────────────────────────────────────────────
    // Accès « Remise T-shirts » pour bénévoles (sans compte).
    //   tshirt_access            : config à ligne unique (id=1) — interrupteur
    //                              ON/OFF, token de campagne (régénérer = tout
    //                              invalider), ouverture + expiration (auto-off).
    //   tshirt_access_sessions   : une ligne par appareil bénévole. Le bénévole
    //                              saisit son nom → demande (status=pending) →
    //                              l'admin valide (approved) ou refuse. La session
    //                              est liée au campaign_token courant : régénérer
    //                              le token invalide toutes les sessions d'un coup.
    //   tshirt_handout_log       : journal des remises (qui/quelle taille/quand)
    //                              pour la traçabilité, faute d'authentification forte.
    // ─────────────────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS `tshirt_access` (
      `id` TINYINT(1) NOT NULL DEFAULT 1,
      `enabled` TINYINT(1) NOT NULL DEFAULT 0,
      `campaign_token` VARCHAR(64) DEFAULT NULL,
      `opened_at` DATETIME DEFAULT NULL,
      `expires_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "INSERT IGNORE INTO `tshirt_access` (`id`) VALUES (1)",
    "CREATE TABLE IF NOT EXISTS `tshirt_access_sessions` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `campaign_token` VARCHAR(64) NOT NULL,
      `device_id` VARCHAR(64) NOT NULL,
      `volunteer_name` VARCHAR(120) DEFAULT NULL,
      `status` ENUM('pending','approved','refused') NOT NULL DEFAULT 'pending',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `approved_at` DATETIME DEFAULT NULL,
      `approved_by` INT DEFAULT NULL,
      `expires_at` DATETIME DEFAULT NULL,
      `last_seen` DATETIME DEFAULT NULL,
      `ip` VARCHAR(45) DEFAULT NULL,
      `user_agent` VARCHAR(255) DEFAULT NULL,
      UNIQUE KEY `idx_device_campaign` (`device_id`, `campaign_token`),
      INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `tshirt_handout_log` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `registration_id` INT DEFAULT NULL,
      `inscription_no` VARCHAR(50) DEFAULT NULL,
      `size` VARCHAR(5) DEFAULT NULL,
      `volunteer_name` VARCHAR(120) DEFAULT NULL,
      `device_id` VARCHAR(64) DEFAULT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_reg` (`registration_id`),
      INDEX `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // Inscription « sur place » via QR : chaque QR peut afficher un choix de
    // prestation (au lieu du champ Paiement) et enregistrer une méthode de paiement
    // masquée personnalisée (ex. « retrait t-shirt »), définie à la création du QR.
    "ALTER TABLE `qrcodes` ADD COLUMN `onsite_mode` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `qrcodes` ADD COLUMN `payment_label` VARCHAR(50) DEFAULT 'retrait t-shirt'",

    // Date de fermeture propre au QR code : indépendante de la fermeture des
    // inscriptions en ligne (setting.registration_auto_close). Passé cette date,
    // le lien avec token devient inactif ; un QR valide non expiré reste utilisable
    // même quand les inscriptions en ligne sont fermées. NULL = pas d'expiration.
    "ALTER TABLE `qrcodes` ADD COLUMN `expires_at` DATETIME DEFAULT NULL",

    // Par QR : décider si le mail de confirmation d'une inscription issue de CE QR
    // inclut le QR code (1 = suit la config globale qrcode_mail_mode) ou jamais
    // (0 = mail envoyé sans QR code, quel que soit le réglage du site).
    "ALTER TABLE `qrcodes` ADD COLUMN `send_qrcode` TINYINT(1) NOT NULL DEFAULT 1",

    // Texte d'aide / consentement affiché sous un champ (notamment le bloc
    // « Autorisation parentale (mineur) » : mention de consentement du responsable légal).
    "ALTER TABLE `forms` ADD COLUMN `help_text` TEXT DEFAULT NULL",
    "UPDATE `forms` SET `help_text` = 'En renseignant le nom et le prénom du responsable légal ci-dessus, je certifie être le représentant légal de l''enfant mineur inscrit, j''autorise sa participation à l''événement et je consens au traitement de ces informations.' WHERE `field_type` = 'guardian' AND (`help_text` IS NULL OR `help_text` = '')",

    // Champ personnalisé rattaché au bloc « Autorisation parentale (mineur) » : il
    // n'a pas de colonne BDD (bdd_column NULL) et sa valeur est injectée dans le
    // commentaire (comme le nom/prénom du responsable). guardian_section = 1 le marque.
    "ALTER TABLE `forms` ADD COLUMN `guardian_section` TINYINT(1) NOT NULL DEFAULT 0",

    // Message d'information complémentaire affiché sous « Les inscriptions sont
    // actuellement fermées » sur la page publique d'inscription. Permet à l'admin
    // d'indiquer, par ex., où et quand s'inscrire / récupérer son t-shirt sur place.
    "ALTER TABLE `setting` ADD COLUMN `registration_closed_message` TEXT DEFAULT NULL",

    // Le champ « naissance » ne stocke plus une date : on ne conserve que l'ÂGE
    // (âge saisi tel quel, année ou date convertie en âge). On renomme le libellé
    // « Date de naissance » → « Âge » UNIQUEMENT s'il porte encore la valeur d'origine
    // (personnalisation admin préservée). Idempotent : 0 ligne au 2ᵉ passage.
    "UPDATE `forms` SET `label` = 'Âge' WHERE `bdd_column` = 'naissance' AND `label` = 'Date de naissance'",

    // Le champ « Entreprise » peut aussi désigner un groupe / une famille / une
    // association : libellé élargi (mêmes conditions que ci-dessus).
    "UPDATE `forms` SET `label` = 'Entreprise / Groupe' WHERE `bdd_column` = 'entreprise' AND `label` = 'Entreprise'",

    // Inscriptions groupées (formulaire QR multi-personnes + ajout multiple récap) :
    // un identifiant de groupe partagé relie les inscrits d'un même lot. Sert au QR
    // « groupé » (un seul QR encode « G:<group_id> ») qui, au scan, affiche TOUS les
    // membres du groupe pour valider les tailles d'un coup.
    "ALTER TABLE `registrations` ADD COLUMN `group_id` VARCHAR(40) DEFAULT NULL, ADD INDEX `group_id` (`group_id`)",

    // Le champ « Ville » est désactivable dans l'admin (is_locked=0) : l'INSERT
    // dynamique (registrations_core / admin-api) l'omet alors, et MySQL en mode
    // strict refuse (erreur 1364 « Field 'ville' doesn't have a default value »).
    // Un défaut '' rend la colonne omissible sans changer le comportement existant.
    "ALTER TABLE `registrations` MODIFY COLUMN `ville` VARCHAR(255) NOT NULL DEFAULT ''",

    // Mode maintenance (Réglages → Maintenance) : bloque les pages publiques via
    // checkMaintenance() (src/core/config.php). Colonnes présentes dans install.php
    // mais jamais migrées ici → sur une base mise à jour, la vérification échouait
    // silencieusement et le mode maintenance était sans effet.
    "ALTER TABLE `setting` ADD COLUMN `maintenance_mode` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `setting` ADD COLUMN `maintenance_message` VARCHAR(500) DEFAULT NULL",

    // 🔒 [SEC-SESSION] Timeout de session par inactivité (minutes ; 0 = jamais).
    // Configurable dans Réglages → Personnalisation. Enforcé dans src/core/config.php.
    "ALTER TABLE `setting` ADD COLUMN `session_lifetime` INT NOT NULL DEFAULT 0",

    // 🔒 [SEC-SESSION] Durée de vie ABSOLUE de session (minutes ; 0 = jamais) : déconnexion
    // X minutes après la connexion, même si l'utilisateur est actif. Complémentaire de
    // session_lifetime (inactivité). Enforcé dans src/core/config.php.
    "ALTER TABLE `setting` ADD COLUMN `session_absolute_lifetime` INT NOT NULL DEFAULT 0",

    // Bandeau flash : mode on/off/auto + fenêtre de programmation (début/fin).
    "ALTER TABLE `setting` ADD COLUMN `flash_info_mode` ENUM('on','off','auto') NOT NULL DEFAULT 'off'",
    "ALTER TABLE `setting` ADD COLUMN `flash_info_start` DATETIME DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `flash_info_end` DATETIME DEFAULT NULL",
    // Report de l'état existant : un bandeau actuellement activé reste en mode « on ».
    "UPDATE `setting` SET `flash_info_mode` = 'on' WHERE `flash_info_active` = 1 AND `flash_info_mode` = 'off'",

    // ── Assistant virtuel (chatbot) : infos pratiques + activation ──
    // Horaires de la course (texte libre), point de rendez-vous, modalités de
    // retrait des t-shirts — réponses servies par le chatbot du site public.
    "ALTER TABLE `setting` ADD COLUMN `course_horaires` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `course_rdv` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `tshirt_retrait_info` TEXT DEFAULT NULL",
    // Inscription sur place (lieu + horaires) : proposée par le chatbot quand un
    // visiteur signale un problème d'inscription en ligne (réglable dans
    // l'admin Assistant / FAQ, vide = non proposée).
    "ALTER TABLE `setting` ADD COLUMN `registration_onsite_info` TEXT DEFAULT NULL",
    // Pages légales éditables (Réglages → Pages légales) : mentions légales +
    // politique de confidentialité, affichées sur /mentions-legales et
    // /politique-confidentialite (liens du footer).
    "ALTER TABLE `setting` ADD COLUMN `legal_mentions` LONGTEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `legal_privacy` LONGTEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `chatbot_enabled` TINYINT(1) NOT NULL DEFAULT 1",
    // Questions incomprises par le chatbot (journal anonyme, consultable dans
    // Réglages pour enrichir les réponses au fil du temps).
    "CREATE TABLE IF NOT EXISTS `chatbot_unmatched` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `question` varchar(500) NOT NULL,
      `created_at` timestamp NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // ── FAQ de l'assistant virtuel : questions/réponses gérées depuis l'admin
    // (page Assistant / FAQ), servies par le chatbot via mots-clés et par la
    // page publique faq.php.
    "CREATE TABLE IF NOT EXISTS `chatbot_faq` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `question` varchar(255) NOT NULL,
      `answer` text NOT NULL,
      `keywords` varchar(500) DEFAULT NULL,
      `position` int(11) NOT NULL DEFAULT 0,
      `active` tinyint(1) NOT NULL DEFAULT 1,
      `created_at` timestamp NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // ── Questions par défaut de la FAQ : insérées UNIQUEMENT si la FAQ est
    // vide (jamais réinsérées après modification/suppression dans l'admin).
    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT s.q, s.a, s.k, s.p, 1 FROM (
       SELECT 'Les enfants peuvent-ils participer ?' AS q,
              'Bien sûr ! Les enfants sont les bienvenus, accompagnés d''un adulte. Un tarif réduit s''applique aux plus jeunes — tous les détails sur la page d''inscription : /register' AS a,
              'enfant, enfants, age, ado, ados, mineur, mineurs, jeune, jeunes, famille, fils, fille, bebe' AS k, 1 AS p
       UNION ALL SELECT 'La marche est-elle réservée aux femmes ?',
              'Non ! La marche est ouverte à toutes et à tous — femmes, hommes, enfants. L''important, c''est de se mobiliser ensemble contre le cancer du sein.',
              'femme, femmes, homme, hommes, mari, garcon, garcons, monsieur, messieurs, masculin, mixte, reservee', 2
       UNION ALL SELECT 'Faut-il un certificat médical ?',
              'Non : il s''agit d''une marche ouverte à tous, à allure libre — aucun certificat médical ni licence n''est demandé. Le règlement complet est consultable sur la page d''inscription (bouton « Règlement ») : /register',
              'certificat, medical, licence, sante, medecin, attestation, justificatif', 3
       UNION ALL SELECT 'Comment modifier ou annuler mon inscription ?',
              'Écrivez-nous via le formulaire de contact (bouton « Nous écrire » de l''assistant) en précisant l''e-mail utilisé lors de l''inscription : nous nous en occupons rapidement.',
              'modifier, modification, changer, changement, corriger, annuler, annulation, desinscrire, desinscription, remboursement, rembourse, trompe, faute', 4
       UNION ALL SELECT 'Et s''il pleut, la marche est-elle annulée ?',
              'La marche a lieu même en cas de petite pluie — prévoyez simplement une tenue adaptée ! En cas de conditions exceptionnelles, l''information serait publiée sur le site et nos réseaux sociaux.',
              'pluie, pleut, meteo, intemperies, orage, tempete, neige, vent, canicule, mauvais temps, reporte, reportee, report, annule, annulee', 5
       UNION ALL SELECT 'Puis-je venir avec mon chien ou une poussette ?',
              'Les poussettes sont les bienvenues sur le parcours. Les chiens tenus en laisse sont acceptés, sous la responsabilité de leur maître. En cas de doute, écrivez-nous !',
              'chien, chiens, chat, chats, toutou, animal, animaux, poussette, poussettes, landau, laisse', 6
       UNION ALL SELECT 'Le parcours est-il accessible aux personnes à mobilité réduite ?',
              'Nous faisons notre possible pour que le parcours soit accessible au plus grand nombre. Pour une situation particulière (fauteuil roulant, mobilité réduite), écrivez-nous : nous vous conseillerons au mieux.',
              'pmr, fauteuil, roulant, handicap, handicape, handicapee, mobilite, accessible, accessibilite, bequilles', 7
       UNION ALL SELECT 'Y a-t-il une buvette ou des animations sur place ?',
              'Oui, un village d''accueil vous attend le jour J (buvette, stands, animations). Le programme détaillé est annoncé à l''approche de l''événement sur le site et nos réseaux.',
              'buvette, restauration, manger, boire, boisson, boissons, nourriture, sandwich, cafe, eau, ravitaillement, animation, animations, stand, stands, village, musique, concert, toilettes, wc, sanitaires, vestiaire, vestiaires, consigne', 8
       UNION ALL SELECT 'Comment devenir bénévole ?',
              'Merci pour votre élan ! Écrivez-nous via le formulaire de contact en indiquant vos disponibilités : l''équipe organisatrice reviendra vers vous.',
              'benevole, benevoles, benevolat, volontaire, volontaires, aider, coup de main', 9
       UNION ALL SELECT 'Comment devenir partenaire ou sponsor ?',
              'Découvrez nos partenaires actuels : /partenaires — pour rejoindre l''aventure (don, lot, mécénat, visibilité), écrivez-nous via le formulaire de contact : nous vous enverrons les modalités.',
              'partenaire, partenaires, partenariat, sponsor, sponsors, sponsoriser, sponsoring, entreprise, societe, mecenat, mecene', 10
       UNION ALL SELECT 'Je n''ai pas reçu mon mail de confirmation / QR code',
              'Vérifiez d''abord votre dossier spam / indésirables. Vous pouvez demander un renvoi automatique directement à l''assistant du site (tapez « je n''ai pas reçu mon QR code ») : le mail est renvoyé à l''adresse utilisée lors de l''inscription.',
              'confirmation, spam, indesirable, indesirables, courrier', 11
     ) AS s
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` LIMIT 1)",

    // ── FAQ : problème d'inscription en ligne — insérée même dans une FAQ déjà
    // remplie (garde-fou sur la question elle-même, jamais de doublon).
    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'L''inscription en ligne ne fonctionne pas, que faire ?',
            'Pas de panique ! Réessayez d''abord un peu plus tard, idéalement depuis un autre navigateur — il s''agit le plus souvent d''un souci passager. Si le problème persiste, écrivez-nous via l''assistant du site (bouton « Nous écrire ») en décrivant l''erreur rencontrée : nous vous aiderons rapidement. Selon les modalités annoncées, une inscription sur place peut aussi être possible — demandez à l''assistant.',
            'inscription en ligne, probleme d inscription, probleme inscription, erreur inscription, erreur d inscription, inscription impossible, inscription bloquee, paiement refuse, paiement impossible', 12, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'L''inscription en ligne ne fonctionne pas%')",


    // ── FAQ : nouvelles questions (paiement, date limite, groupe, dossard,
    // chrono) — insérées même dans une FAQ remplie, garde-fou par question.
    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Comment payer mon inscription ?',
            'Le paiement se fait en ligne, de façon sécurisée, à la fin du formulaire d''inscription : /register — aucune avance, aucun frais caché. Pour toute autre modalité (chèque, espèces, inscription sur place), écrivez-nous via l''assistant du site.',
            'payer, paiement, carte, cb, cheque, especes, liquide, virement, paypal', 13, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Comment payer mon inscription%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Jusqu''à quand peut-on s''inscrire ?',
            'Les inscriptions en ligne restent ouvertes tant que la page d''inscription est active : /register — ne tardez pas ! Selon les modalités annoncées, une inscription sur place peut aussi être possible : demandez à l''assistant.',
            'date limite, jusqu a quand, cloture, dernier jour, encore possible', 14, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Jusqu''à quand peut-on s''inscrire%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Peut-on s''inscrire en groupe (entreprise, association) ?',
            'Oui ! Le formulaire d''inscription permet d''inscrire plusieurs personnes en une seule fois : /register — idéal en famille ou entre collègues. Pour un grand groupe, une entreprise ou une association, écrivez-nous via l''assistant : nous vous faciliterons les choses.',
            'groupe, groupes, equipe, equipes, entreprise, association, collegues, plusieurs', 15, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Peut-on s''inscrire en groupe%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Y a-t-il un dossard ? Comment présenter mon billet ?',
            'Pas de dossard papier : le QR code reçu par e-mail après votre inscription fait office de billet le jour J. Gardez-le sur votre téléphone ou imprimez-le. Vous ne l''avez pas reçu ? Demandez à l''assistant « je n''ai pas reçu mon QR code » : il vous le renvoie automatiquement.',
            'dossard, dossards, billet, billets, qr, qr code, imprimer', 16, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Y a-t-il un dossard%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'La marche est-elle chronométrée ? Y a-t-il un classement ?',
            'L''événement est avant tout solidaire et à allure libre : chacun avance à son rythme, l''essentiel est de participer et de soutenir la cause. Pour toute précision sur le chronométrage, consultez la page /parcours ou écrivez-nous.',
            'chrono, chronometre, chronometree, chronometrage, classement, resultats', 17, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'La marche est-elle chronométrée%')",


    // ── FAQ : dress code, allure, transfert de place, spectateurs, RGPD,
    // secours, bénéfices, objets perdus — garde-fou par question.
    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Faut-il venir habillé en rose ?',
            'Le rose est à l''honneur et fortement encouragé — mais rien d''obligatoire : venez comme vous êtes ! Et selon les modalités d''inscription, un t-shirt de l''événement est prévu.',
            'rose, tenue, habille, habiller, vetement, vetements, dress code, deguisement', 18, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Faut-il venir habillé en rose%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Peut-on courir ou faut-il marcher ?',
            'Allure totalement libre : marche tranquille, marche rapide ou course — chacun avance à son rythme, l''essentiel est de participer ! Le tracé complet est sur /parcours.',
            'courir, course a pied, jogging, footing, allure, rythme', 19, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Peut-on courir ou faut-il marcher%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Je ne peux plus venir : puis-je céder ma place ?',
            'Écrivez-nous via l''assistant (bouton « Nous écrire ») avec l''e-mail utilisé lors de l''inscription et les coordonnées de la personne à qui vous souhaitez céder votre place : nous verrons ensemble ce qui est possible.',
            'ceder, cede, transferer, transfert, revendre, donner ma place, remplacer, peux plus venir', 20, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Je ne peux plus venir%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Peut-on venir accompagner sans participer ?',
            'Bien sûr ! Le village, les animations et l''ambiance sont ouverts à toutes et à tous — seule la participation à la marche nécessite une inscription. Venez encourager les participants !',
            'accompagner, accompagnant, accompagnants, spectateur, spectateurs, encourager, assister', 21, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Peut-on venir accompagner%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Que faites-vous de mes données personnelles ?',
            'Vos données servent uniquement à la gestion de votre inscription et de l''événement — elles ne sont jamais revendues. Tout est détaillé dans notre politique de confidentialité : /politique-confidentialite — pour toute demande (accès, suppression), écrivez-nous.',
            'donnees, rgpd, confidentialite, vie privee', 22, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Que faites-vous de mes données%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Y a-t-il des secours sur le parcours ?',
            'Un dispositif de sécurité et de premiers secours est prévu le jour de l''événement. En cas de besoin, signalez-vous aux bénévoles ou à l''accueil du village. Pour toute question particulière (condition médicale…), écrivez-nous.',
            'secours, securite, secouriste, secouristes, urgence, malaise, blessure, ambulance', 23, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Y a-t-il des secours%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'À qui sont reversés les bénéfices ?',
            'L''intégralité des bénéfices de l''événement est reversée à la lutte contre le cancer. Pour en savoir plus sur la cause et nos actions, consultez nos actualités ou écrivez-nous.',
            'benefices, reverses, reverse, recolte, argent', 24, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'À qui sont reversés%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'J''ai perdu un objet pendant l''événement, que faire ?',
            'Écrivez-nous via l''assistant en décrivant l''objet (et l''endroit où vous pensez l''avoir laissé) : nous vérifions les objets retrouvés et revenons vers vous.',
            'perdu, objet, objets trouves, egare, oublie', 25, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'J''ai perdu un objet%')",


    // ── Enrichissement des mots-clés des questions FAQ par défaut :
    // appliqué UNIQUEMENT si les mots-clés sont encore ceux d'origine
    // (une personnalisation admin n'est jamais écrasée).
    "UPDATE `chatbot_faq` SET keywords = 'enfant, enfants, age, ado, ados, mineur, mineurs, jeune, jeunes, famille, fils, fille, bebe' WHERE keywords = 'enfant, enfants, age, ado, mineur, famille'",
    "UPDATE `chatbot_faq` SET keywords = 'femme, femmes, homme, hommes, mari, garcon, garcons, monsieur, messieurs, masculin, mixte, reservee' WHERE keywords = 'femme, femmes, homme, hommes, mixte, reservee'",
    "UPDATE `chatbot_faq` SET keywords = 'certificat, medical, licence, sante, medecin, attestation, justificatif' WHERE keywords = 'certificat, medical, licence, sante'",
    "UPDATE `chatbot_faq` SET keywords = 'modifier, modification, changer, changement, corriger, annuler, annulation, desinscrire, desinscription, remboursement, rembourse, trompe, faute' WHERE keywords = 'modifier, changer, annuler, annulation, remboursement, rembourse, trompe'",
    "UPDATE `chatbot_faq` SET keywords = 'pluie, pleut, meteo, intemperies, orage, tempete, neige, vent, canicule, mauvais temps, reporte, reportee, report, annule, annulee' WHERE keywords = 'pluie, meteo, intemperies, orage, reporte, mauvais temps'",
    "UPDATE `chatbot_faq` SET keywords = 'chien, chiens, chat, chats, toutou, animal, animaux, poussette, poussettes, landau, laisse' WHERE keywords = 'chien, chiens, animal, animaux, poussette, laisse'",
    "UPDATE `chatbot_faq` SET keywords = 'pmr, fauteuil, roulant, handicap, handicape, handicapee, mobilite, accessible, accessibilite, bequilles' WHERE keywords = 'pmr, fauteuil, handicap, mobilite, accessible, accessibilite'",
    "UPDATE `chatbot_faq` SET keywords = 'buvette, restauration, manger, boire, boisson, boissons, nourriture, sandwich, cafe, eau, ravitaillement, animation, animations, stand, stands, village, musique, concert' WHERE keywords = 'buvette, restauration, manger, boire, animations, stands, village, musique'",
    "UPDATE `chatbot_faq` SET keywords = 'buvette, restauration, manger, boire, boisson, boissons, nourriture, sandwich, cafe, eau, ravitaillement, animation, animations, stand, stands, village, musique, concert, toilettes, wc, sanitaires, vestiaire, vestiaires, consigne' WHERE keywords = 'buvette, restauration, manger, boire, boisson, boissons, nourriture, sandwich, cafe, eau, ravitaillement, animation, animations, stand, stands, village, musique, concert'",
    "UPDATE `chatbot_faq` SET keywords = 'benevole, benevoles, benevolat, volontaire, volontaires, aider, coup de main' WHERE keywords = 'benevole, benevoles, volontaire, aider, coup de main'",
    "UPDATE `chatbot_faq` SET keywords = 'partenaire, partenaires, partenariat, sponsor, sponsors, sponsoriser, sponsoring, entreprise, societe, mecenat, mecene' WHERE keywords = 'partenaire, partenaires, sponsor, sponsoring, entreprise, mecenat'",
    "UPDATE `chatbot_faq` SET keywords = 'confirmation, spam, indesirable, indesirables, courrier' WHERE keywords = 'confirmation, spam, indesirables'",
    "UPDATE `chatbot_faq` SET keywords = 'inscription en ligne, probleme d inscription, probleme inscription, erreur inscription, erreur d inscription, inscription impossible, inscription bloquee, paiement refuse, paiement impossible' WHERE keywords = 'probleme, erreur, bug, impossible, bloque, fonctionne pas, marche pas'",
    "UPDATE `chatbot_faq` SET keywords = 'inscription en ligne, probleme d inscription, probleme inscription, erreur inscription, erreur d inscription, inscription impossible, inscription bloquee, paiement refuse, paiement impossible' WHERE keywords = 'probleme, souci, erreur, bug, impossible, bloque, plante, echec, fonctionne pas, marche pas, passe pas'",

    // ── Contenu par défaut des pages légales : semé UNIQUEMENT si vide
    // (les éditions faites dans Réglages → Pages légales ne sont jamais écrasées).
    "UPDATE `setting` SET legal_mentions = '<h2>Éditeur du site</h2> <p>Ce site est réalisé, édité et maintenu <strong>à titre bénévole et non professionnel</strong>, au profit de l''événement solidaire « Forbach en Rose ».</p> <p>Conformément à l''article 6, III-2 de la loi n° 2004-575 du 21 juin 2004 pour la confiance dans l''économie numérique (LCEN), l''éditeur non professionnel de ce site a choisi de préserver son anonymat ; l''identité de l''hébergeur, qui assure le stockage du site, figure ci-dessous.</p> <p>Contact : via le <a href=''accueil?chat=contact''>formulaire de contact</a> du site.</p> <h2>L''événement</h2> <p>« Forbach en Rose » est organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach — téléphone : 03 87 84 56 95), en partenariat avec la Ligue contre le cancer. L''intégralité des bénéfices est reversée à la lutte contre le cancer.</p> <h2>Hébergement</h2> <p>Le site est hébergé par <strong>PlanetHoster</strong>, société canadienne — siège : 4416 Louis-B.-Mayer, Laval, Québec, H7P 0G1, Canada — dont les centres de données sont situés en France (Paris), en Suisse et au Canada — téléphone (France) : +33 (0)1 76 60 41 43 — <a href=''https://www.planethoster.com'' target=''_blank'' rel=''noopener''>www.planethoster.com</a>.</p> <h2>Propriété intellectuelle</h2> <p>L''ensemble des contenus du site (textes, visuels, logos, photographies, vidéos) est protégé par le droit de la propriété intellectuelle. Toute reproduction ou réutilisation, totale ou partielle, sans autorisation écrite préalable est interdite. Les photographies des éditions peuvent représenter des participants ; toute personne souhaitant le retrait d''une image la concernant peut en faire la demande via le formulaire de contact.</p> <h2>Responsabilité</h2> <p>Les informations publiées (horaires, parcours, tarifs…) sont données à titre indicatif et peuvent évoluer. Le site peut contenir des liens vers des sites tiers (partenaires, réseaux sociaux, plateforme d''inscription) dont l''éditeur ne maîtrise pas le contenu.</p> <h2>Données personnelles</h2> <p>Le traitement des données personnelles collectées sur ce site est détaillé dans la <a href=''politique-confidentialite''>politique de confidentialité</a>.</p>' WHERE id = 1 AND legal_mentions IS NULL",
    "UPDATE `setting` SET legal_mentions = '<h2>Éditeur du site</h2> <p>Ce site est réalisé, édité et maintenu <strong>à titre bénévole et non professionnel</strong>, au profit de l''événement solidaire « Forbach en Rose ».</p> <p>Conformément à l''article 6, III-2 de la loi n° 2004-575 du 21 juin 2004 pour la confiance dans l''économie numérique (LCEN), l''éditeur non professionnel de ce site a choisi de préserver son anonymat ; l''identité de l''hébergeur, qui assure le stockage du site, figure ci-dessous.</p> <p>Contact : via le <a href=''accueil?chat=contact''>formulaire de contact</a> du site.</p> <h2>L''événement</h2> <p>« Forbach en Rose » est organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach — téléphone : 03 87 84 56 95), en partenariat avec la Ligue contre le cancer. L''intégralité des bénéfices est reversée à la lutte contre le cancer.</p> <h2>Hébergement</h2> <p>Le site est hébergé par <strong>PlanetHoster</strong>, société canadienne — siège : 4416 Louis-B.-Mayer, Laval, Québec, H7P 0G1, Canada — dont les centres de données sont situés en France (Paris), en Suisse et au Canada — téléphone (France) : +33 (0)1 76 60 41 43 — <a href=''https://www.planethoster.com'' target=''_blank'' rel=''noopener''>www.planethoster.com</a>.</p> <h2>Propriété intellectuelle</h2> <p>L''ensemble des contenus du site (textes, visuels, logos, photographies, vidéos) est protégé par le droit de la propriété intellectuelle. Toute reproduction ou réutilisation, totale ou partielle, sans autorisation écrite préalable est interdite. Les photographies des éditions peuvent représenter des participants ; toute personne souhaitant le retrait d''une image la concernant peut en faire la demande via le formulaire de contact.</p> <h2>Responsabilité</h2> <p>Les informations publiées (horaires, parcours, tarifs…) sont données à titre indicatif et peuvent évoluer. Le site peut contenir des liens vers des sites tiers (partenaires, réseaux sociaux, plateforme d''inscription) dont l''éditeur ne maîtrise pas le contenu.</p> <h2>Données personnelles</h2> <p>Le traitement des données personnelles collectées sur ce site est détaillé dans la <a href=''politique-confidentialite''>politique de confidentialité</a>.</p>' WHERE id = 1 AND legal_mentions = '<h2>Éditeur du site</h2> <p>Ce site est réalisé, édité et maintenu <strong>à titre bénévole et non professionnel</strong>, au profit de l''événement solidaire « Forbach en Rose ».</p> <p>Conformément à l''article 6, III-2 de la loi n° 2004-575 du 21 juin 2004 pour la confiance dans l''économie numérique (LCEN), l''éditeur non professionnel de ce site a choisi de préserver son anonymat ; l''identité de l''hébergeur, qui assure le stockage du site, figure ci-dessous.</p> <p>Contact : via le <a href=''accueil?chat=contact''>formulaire de contact</a> du site.</p> <h2>L''événement</h2> <p>« Forbach en Rose » est organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach — téléphone : 03 87 84 56 95), en partenariat avec la Ligue contre le cancer. L''intégralité des bénéfices est reversée à la lutte contre le cancer.</p> <h2>Hébergement</h2> <p>Le site est hébergé par <strong>PlanetHoster</strong> — 4416 Louis-B.-Mayer, Laval, Québec, H7P 0G1, Canada — téléphone (France) : +33 (0)1 76 60 41 43 — <a href=''https://www.planethoster.com'' target=''_blank'' rel=''noopener''>www.planethoster.com</a>.</p> <h2>Propriété intellectuelle</h2> <p>L''ensemble des contenus du site (textes, visuels, logos, photographies, vidéos) est protégé par le droit de la propriété intellectuelle. Toute reproduction ou réutilisation, totale ou partielle, sans autorisation écrite préalable est interdite. Les photographies des éditions peuvent représenter des participants ; toute personne souhaitant le retrait d''une image la concernant peut en faire la demande via le formulaire de contact.</p> <h2>Responsabilité</h2> <p>Les informations publiées (horaires, parcours, tarifs…) sont données à titre indicatif et peuvent évoluer. Le site peut contenir des liens vers des sites tiers (partenaires, réseaux sociaux, plateforme d''inscription) dont l''éditeur ne maîtrise pas le contenu.</p> <h2>Données personnelles</h2> <p>Le traitement des données personnelles collectées sur ce site est détaillé dans la <a href=''politique-confidentialite''>politique de confidentialité</a>.</p>'",
    "UPDATE `setting` SET legal_mentions = '<h2>Éditeur du site</h2> <p>Ce site est réalisé, édité et maintenu <strong>à titre bénévole et non professionnel</strong>, au profit de l''événement solidaire « Forbach en Rose ».</p> <p>Conformément à l''article 6, III-2 de la loi n° 2004-575 du 21 juin 2004 pour la confiance dans l''économie numérique (LCEN), l''éditeur non professionnel de ce site a choisi de préserver son anonymat ; l''identité de l''hébergeur, qui assure le stockage du site, figure ci-dessous.</p> <p>Contact : via le <a href=''accueil?chat=contact''>formulaire de contact</a> du site.</p> <h2>L''événement</h2> <p>« Forbach en Rose » est organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach — téléphone : 03 87 84 56 95), en partenariat avec la Ligue contre le cancer. L''intégralité des bénéfices est reversée à la lutte contre le cancer.</p> <h2>Hébergement</h2> <p>Le site est hébergé par <strong>PlanetHoster</strong> — 4416 Louis-B.-Mayer, Laval, Québec, H7P 0G1, Canada — téléphone (France) : +33 (0)1 76 60 41 43 — <a href=''https://www.planethoster.com'' target=''_blank'' rel=''noopener''>www.planethoster.com</a>.</p> <h2>Propriété intellectuelle</h2> <p>L''ensemble des contenus du site (textes, visuels, logos, photographies, vidéos) est protégé par le droit de la propriété intellectuelle. Toute reproduction ou réutilisation, totale ou partielle, sans autorisation écrite préalable est interdite. Les photographies des éditions peuvent représenter des participants ; toute personne souhaitant le retrait d''une image la concernant peut en faire la demande via le formulaire de contact.</p> <h2>Responsabilité</h2> <p>Les informations publiées (horaires, parcours, tarifs…) sont données à titre indicatif et peuvent évoluer. Le site peut contenir des liens vers des sites tiers (partenaires, réseaux sociaux, plateforme d''inscription) dont l''éditeur ne maîtrise pas le contenu.</p> <h2>Données personnelles</h2> <p>Le traitement des données personnelles collectées sur ce site est détaillé dans la <a href=''politique-confidentialite''>politique de confidentialité</a>.</p>' WHERE id = 1 AND legal_mentions = '<h2>Éditeur du site</h2> <p>Le site <strong>forbachenrose.com</strong> est édité par l''association <strong>US Forbach Athlétisme</strong>, association organisatrice de l''événement solidaire « Forbach en Rose », en partenariat avec la Ligue contre le cancer.</p> <ul> <li>Siège social : Stade du Schlossberg, rue du Parc, 57600 Forbach — France</li> <li>SIREN : 384 589 073 — SIRET (siège) : 384 589 073 00020</li> <li>Téléphone : 03 87 84 56 95</li> <li>Contact : via le <a href=''accueil?chat=contact''>formulaire de contact</a> du site</li> </ul> <p><strong>Directeur·rice de la publication :</strong> [À compléter : nom du président ou de la présidente de l''association].</p> <h2>Hébergement</h2> <p>Le site est hébergé par <strong>LWS (Ligne Web Services)</strong>, SAS au capital de 500 000 €, 10 rue Penthièvre, 75008 Paris — France, RCS Paris 851 993 683 — <a href=''https://www.lws.fr'' target=''_blank'' rel=''noopener''>www.lws.fr</a>.</p> <h2>Propriété intellectuelle</h2> <p>L''ensemble des contenus du site (textes, visuels, logos, photographies, vidéos) est protégé par le droit de la propriété intellectuelle. Toute reproduction ou réutilisation, totale ou partielle, sans autorisation écrite préalable de l''association est interdite. Les photographies des éditions peuvent représenter des participants ; toute personne souhaitant le retrait d''une image la concernant peut en faire la demande via le formulaire de contact.</p> <h2>Responsabilité</h2> <p>Les informations publiées (horaires, parcours, tarifs…) sont données à titre indicatif et peuvent évoluer ; l''association s''efforce d''en assurer l''exactitude. Le site peut contenir des liens vers des sites tiers (partenaires, réseaux sociaux, plateforme d''inscription) dont l''association ne maîtrise pas le contenu.</p> <h2>Données personnelles</h2> <p>Le traitement des données personnelles collectées sur ce site est détaillé dans la <a href=''politique-confidentialite''>politique de confidentialité</a>.</p> <h2>Crédits</h2> <p>« Forbach en Rose » est un événement caritatif : l''intégralité des bénéfices est reversée à la lutte contre le cancer.</p>'",
    "UPDATE `setting` SET legal_privacy = '<p>La protection de vos données personnelles nous tient à cœur. La présente politique explique, en toute transparence, quelles données sont collectées sur le site forbachenrose.com, pourquoi, et quels sont vos droits.</p> <h2>Responsable du traitement</h2> <p>Les données sont traitées pour les besoins de l''organisation de l''événement « Forbach en Rose », organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach). Le site est administré à titre bénévole pour le compte de l''organisation. Contact : via le <a href=''accueil?chat=contact''>formulaire du site</a> ou par courrier à l''adresse ci-dessus.</p> <h2>Données collectées et finalités</h2> <h3>Inscription à l''événement</h3> <p>Nom, prénom, e-mail, téléphone, âge, sexe, taille de t-shirt, ville, entreprise (facultatif) et commentaire libre ; pour les mineurs, l''identité du responsable légal (autorisation parentale). Ces données servent exclusivement à la gestion de votre participation : enregistrement, envoi de la confirmation et du QR code d''accès, remise des t-shirts, organisation le jour J. Base légale : exécution du contrat d''inscription.</p> <h3>Paiement</h3> <p>Le paiement s''effectue via la plateforme <strong>AssoConnect</strong> et son prestataire de paiement sécurisé. <strong>Aucune donnée bancaire n''est collectée ni conservée sur ce site.</strong></p> <h3>Formulaire de contact (assistant)</h3> <p>Nom, e-mail, sujet, message et pièces jointes éventuelles : transmis par e-mail à l''équipe pour vous répondre, non conservés en base de données sur le site. Base légale : intérêt légitime à répondre à vos demandes.</p> <h3>Newsletter</h3> <p>Adresse e-mail uniquement, avec votre consentement explicite (case à cocher). Vous pouvez vous désinscrire à tout moment via le lien présent dans chaque envoi ou la page newsletter. Base légale : consentement.</p> <h3>Commentaires des actualités</h3> <p>Pseudo, contenu du commentaire et adresse IP (utilisée uniquement pour prévenir les abus). Base légale : intérêt légitime.</p> <h3>Assistant virtuel</h3> <p>Les questions que l''assistant ne comprend pas sont journalisées de façon anonyme afin d''améliorer ses réponses — merci de ne pas y saisir de données personnelles. Les vérifications par e-mail (inscription, t-shirt, renvoi du QR code) n''affichent jamais de données personnelles dans la conversation : le mail est renvoyé uniquement à l''adresse de l''inscrit.</p> <h3>Statistiques de visite</h3> <p>Mesure d''audience interne et respectueuse : page consultée, type de navigateur, site de provenance et adresse IP <strong>anonymisée</strong>. Aucun profilage, aucun outil d''analyse tiers (pas de Google Analytics, pas de pixel publicitaire).</p> <h2>Sécurité</h2> <p>Des mesures techniques et organisationnelles appropriées protègent vos données : données personnelles chiffrées, connexion sécurisée (HTTPS) et accès strictement limité aux personnes habilitées.</p> <h2>Destinataires et sous-traitants</h2> <ul> <li><strong>Équipe organisatrice</strong> et, le jour J, bénévoles habilités (remise des t-shirts) ;</li> <li><strong>PlanetHoster</strong> — hébergement du site et acheminement des e-mails, sur des centres de données situés en France, en Suisse ou au Canada ;</li> <li><strong>AssoConnect</strong> et son prestataire de paiement — plateforme d''inscription et de paiement ;</li> <li><strong>Google</strong> — polices de caractères et carte interactive (Google Maps) affichées sur la page d''accueil ;</li> <li><strong>Cloudflare</strong> — vérification anti-robots sur les formulaires, qui reçoit l''adresse IP lors de la vérification ;</li> <li><strong>CDN techniques</strong> (jsDelivr, jQuery) — chargement de fichiers techniques.</li> </ul> <p>Vos données ne sont <strong>jamais vendues ni cédées</strong>. Certains prestataires peuvent traiter des données en dehors de l''Union européenne, dans le cadre des garanties prévues par le RGPD (décision d''adéquation ou clauses contractuelles types).</p> <h2>Cookies et stockage local</h2> <p>Le site utilise uniquement un <strong>cookie de session technique</strong>, nécessaire au fonctionnement et à la sécurité (protection des formulaires) — exempté de consentement. Aucun cookie publicitaire ni traceur tiers. Le stockage local de votre navigateur peut mémoriser des préférences fonctionnelles : thème clair/sombre, préférences de l''assistant (position, message d''accueil) et votre confirmation d''inscription sur votre propre appareil.</p> <h2>Durées de conservation</h2> <ul> <li>Données d''inscription : le temps de l''organisation de l''édition concernée, puis au maximum [3 ans] après votre dernière participation ;</li> <li>Newsletter : jusqu''à votre désinscription ;</li> <li>Journaux techniques et de sécurité : durée limitée nécessaire à la protection du site ;</li> <li>Statistiques de visite : données anonymisées dès la collecte.</li> </ul> <h2>Vos droits</h2> <p>Conformément au RGPD, vous disposez des droits d''accès, de rectification, d''effacement, de limitation, d''opposition et de portabilité sur vos données. Pour les exercer : le <a href=''accueil?chat=contact''>formulaire de contact</a> du site ou un courrier à l''adresse indiquée plus haut. Vous pouvez également introduire une réclamation auprès de la CNIL (<a href=''https://www.cnil.fr'' target=''_blank'' rel=''noopener''>www.cnil.fr</a>).</p> <h2>Mineurs</h2> <p>La participation des mineurs nécessite l''autorisation d''un responsable légal, recueillie lors de l''inscription.</p> <p><em>Dernière mise à jour : juillet 2026.</em></p>' WHERE id = 1 AND legal_privacy IS NULL",
    "UPDATE `setting` SET legal_privacy = '<p>La protection de vos données personnelles nous tient à cœur. La présente politique explique, en toute transparence, quelles données sont collectées sur le site forbachenrose.com, pourquoi, et quels sont vos droits.</p> <h2>Responsable du traitement</h2> <p>Les données sont traitées pour les besoins de l''organisation de l''événement « Forbach en Rose », organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach). Le site est administré à titre bénévole pour le compte de l''organisation. Contact : via le <a href=''accueil?chat=contact''>formulaire du site</a> ou par courrier à l''adresse ci-dessus.</p> <h2>Données collectées et finalités</h2> <h3>Inscription à l''événement</h3> <p>Nom, prénom, e-mail, téléphone, âge, sexe, taille de t-shirt, ville, entreprise (facultatif) et commentaire libre ; pour les mineurs, l''identité du responsable légal (autorisation parentale). Ces données servent exclusivement à la gestion de votre participation : enregistrement, envoi de la confirmation et du QR code d''accès, remise des t-shirts, organisation le jour J. Base légale : exécution du contrat d''inscription.</p> <h3>Paiement</h3> <p>Le paiement s''effectue via la plateforme <strong>AssoConnect</strong> et son prestataire de paiement sécurisé. <strong>Aucune donnée bancaire n''est collectée ni conservée sur ce site.</strong></p> <h3>Formulaire de contact (assistant)</h3> <p>Nom, e-mail, sujet, message et pièces jointes éventuelles : transmis par e-mail à l''équipe pour vous répondre, non conservés en base de données sur le site. Base légale : intérêt légitime à répondre à vos demandes.</p> <h3>Newsletter</h3> <p>Adresse e-mail uniquement, avec votre consentement explicite (case à cocher). Vous pouvez vous désinscrire à tout moment via le lien présent dans chaque envoi ou la page newsletter. Base légale : consentement.</p> <h3>Commentaires des actualités</h3> <p>Pseudo, contenu du commentaire et adresse IP (utilisée uniquement pour prévenir les abus). Base légale : intérêt légitime.</p> <h3>Assistant virtuel</h3> <p>Les questions que l''assistant ne comprend pas sont journalisées de façon anonyme afin d''améliorer ses réponses — merci de ne pas y saisir de données personnelles. Les vérifications par e-mail (inscription, t-shirt, renvoi du QR code) n''affichent jamais de données personnelles dans la conversation : le mail est renvoyé uniquement à l''adresse de l''inscrit.</p> <h3>Statistiques de visite</h3> <p>Mesure d''audience interne et respectueuse : page consultée, type de navigateur, site de provenance et adresse IP <strong>anonymisée</strong>. Aucun profilage, aucun outil d''analyse tiers (pas de Google Analytics, pas de pixel publicitaire).</p> <h2>Sécurité</h2> <p>Des mesures techniques et organisationnelles appropriées protègent vos données : données personnelles chiffrées, connexion sécurisée (HTTPS) et accès strictement limité aux personnes habilitées.</p> <h2>Destinataires et sous-traitants</h2> <ul> <li><strong>Équipe organisatrice</strong> et, le jour J, bénévoles habilités (remise des t-shirts) ;</li> <li><strong>PlanetHoster</strong> — hébergement du site et acheminement des e-mails, sur des centres de données situés en France, en Suisse ou au Canada ;</li> <li><strong>AssoConnect</strong> et son prestataire de paiement — plateforme d''inscription et de paiement ;</li> <li><strong>Google</strong> — polices de caractères et carte interactive (Google Maps) affichées sur la page d''accueil ;</li> <li><strong>Cloudflare</strong> — vérification anti-robots sur les formulaires, qui reçoit l''adresse IP lors de la vérification ;</li> <li><strong>CDN techniques</strong> (jsDelivr, jQuery) — chargement de fichiers techniques.</li> </ul> <p>Vos données ne sont <strong>jamais vendues ni cédées</strong>. Certains prestataires peuvent traiter des données en dehors de l''Union européenne, dans le cadre des garanties prévues par le RGPD (décision d''adéquation ou clauses contractuelles types).</p> <h2>Cookies et stockage local</h2> <p>Le site utilise uniquement un <strong>cookie de session technique</strong>, nécessaire au fonctionnement et à la sécurité (protection des formulaires) — exempté de consentement. Aucun cookie publicitaire ni traceur tiers. Le stockage local de votre navigateur peut mémoriser des préférences fonctionnelles : thème clair/sombre, préférences de l''assistant (position, message d''accueil) et votre confirmation d''inscription sur votre propre appareil.</p> <h2>Durées de conservation</h2> <ul> <li>Données d''inscription : le temps de l''organisation de l''édition concernée, puis au maximum [3 ans] après votre dernière participation ;</li> <li>Newsletter : jusqu''à votre désinscription ;</li> <li>Journaux techniques et de sécurité : durée limitée nécessaire à la protection du site ;</li> <li>Statistiques de visite : données anonymisées dès la collecte.</li> </ul> <h2>Vos droits</h2> <p>Conformément au RGPD, vous disposez des droits d''accès, de rectification, d''effacement, de limitation, d''opposition et de portabilité sur vos données. Pour les exercer : le <a href=''accueil?chat=contact''>formulaire de contact</a> du site ou un courrier à l''adresse indiquée plus haut. Vous pouvez également introduire une réclamation auprès de la CNIL (<a href=''https://www.cnil.fr'' target=''_blank'' rel=''noopener''>www.cnil.fr</a>).</p> <h2>Mineurs</h2> <p>La participation des mineurs nécessite l''autorisation d''un responsable légal, recueillie lors de l''inscription.</p> <p><em>Dernière mise à jour : juillet 2026.</em></p>' WHERE id = 1 AND legal_privacy = '<p>La protection de vos données personnelles nous tient à cœur. La présente politique explique, en toute transparence, quelles données sont collectées sur le site forbachenrose.com, pourquoi, et quels sont vos droits.</p> <h2>Responsable du traitement</h2> <p>Les données sont traitées pour les besoins de l''organisation de l''événement « Forbach en Rose », organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach). Le site est administré à titre bénévole pour le compte de l''organisation. Contact : via le <a href=''accueil?chat=contact''>formulaire du site</a> ou par courrier à l''adresse ci-dessus.</p> <h2>Données collectées et finalités</h2> <h3>Inscription à l''événement</h3> <p>Nom, prénom, e-mail, téléphone, âge, sexe, taille de t-shirt, ville, entreprise (facultatif) et commentaire libre ; pour les mineurs, l''identité du responsable légal (autorisation parentale). Ces données servent exclusivement à la gestion de votre participation : enregistrement, envoi de la confirmation et du QR code d''accès, remise des t-shirts, organisation le jour J. Base légale : exécution du contrat d''inscription.</p> <h3>Paiement</h3> <p>Le paiement s''effectue via la plateforme <strong>AssoConnect</strong> et son prestataire de paiement sécurisé. <strong>Aucune donnée bancaire n''est collectée ni conservée sur ce site.</strong></p> <h3>Formulaire de contact (assistant)</h3> <p>Nom, e-mail, sujet, message et pièces jointes éventuelles : transmis par e-mail à l''équipe pour vous répondre, non conservés en base de données sur le site. Base légale : intérêt légitime à répondre à vos demandes.</p> <h3>Newsletter</h3> <p>Adresse e-mail uniquement, avec votre consentement explicite (case à cocher). Vous pouvez vous désinscrire à tout moment via le lien présent dans chaque envoi ou la page newsletter. Base légale : consentement.</p> <h3>Commentaires des actualités</h3> <p>Pseudo, contenu du commentaire et adresse IP (utilisée uniquement pour prévenir les abus). Base légale : intérêt légitime.</p> <h3>Assistant virtuel</h3> <p>Les questions que l''assistant ne comprend pas sont journalisées de façon anonyme afin d''améliorer ses réponses — merci de ne pas y saisir de données personnelles. Les vérifications par e-mail (inscription, t-shirt, renvoi du QR code) n''affichent jamais de données personnelles dans la conversation : le mail est renvoyé uniquement à l''adresse de l''inscrit.</p> <h3>Statistiques de visite</h3> <p>Mesure d''audience interne et respectueuse : page consultée, type de navigateur, site de provenance et adresse IP <strong>anonymisée</strong>. Aucun profilage, aucun outil d''analyse tiers (pas de Google Analytics, pas de pixel publicitaire).</p> <h2>Sécurité</h2> <p>Des mesures techniques et organisationnelles appropriées protègent vos données : données personnelles chiffrées, connexion sécurisée (HTTPS) et accès strictement limité aux personnes habilitées.</p> <h2>Destinataires et sous-traitants</h2> <ul> <li><strong>Équipe organisatrice</strong> et, le jour J, bénévoles habilités (remise des t-shirts) ;</li> <li><strong>PlanetHoster</strong> — hébergement du site et acheminement des e-mails (serveur de messagerie de l''hébergeur) ;</li> <li><strong>AssoConnect</strong> et son prestataire de paiement — plateforme d''inscription et de paiement ;</li> <li><strong>Google</strong> — polices de caractères et carte interactive (Google Maps) affichées sur la page d''accueil ;</li> <li><strong>Cloudflare</strong> — vérification anti-robots sur les formulaires, qui reçoit l''adresse IP lors de la vérification ;</li> <li><strong>CDN techniques</strong> (jsDelivr, jQuery) — chargement de fichiers techniques.</li> </ul> <p>Vos données ne sont <strong>jamais vendues ni cédées</strong>. Certains prestataires peuvent traiter des données en dehors de l''Union européenne, dans le cadre des garanties prévues par le RGPD (décision d''adéquation ou clauses contractuelles types).</p> <h2>Cookies et stockage local</h2> <p>Le site utilise uniquement un <strong>cookie de session technique</strong>, nécessaire au fonctionnement et à la sécurité (protection des formulaires) — exempté de consentement. Aucun cookie publicitaire ni traceur tiers. Le stockage local de votre navigateur peut mémoriser des préférences fonctionnelles : thème clair/sombre, préférences de l''assistant (position, message d''accueil) et votre confirmation d''inscription sur votre propre appareil.</p> <h2>Durées de conservation</h2> <ul> <li>Données d''inscription : le temps de l''organisation de l''édition concernée, puis au maximum [3 ans] après votre dernière participation ;</li> <li>Newsletter : jusqu''à votre désinscription ;</li> <li>Journaux techniques et de sécurité : durée limitée nécessaire à la protection du site ;</li> <li>Statistiques de visite : données anonymisées dès la collecte.</li> </ul> <h2>Vos droits</h2> <p>Conformément au RGPD, vous disposez des droits d''accès, de rectification, d''effacement, de limitation, d''opposition et de portabilité sur vos données. Pour les exercer : le <a href=''accueil?chat=contact''>formulaire de contact</a> du site ou un courrier à l''adresse indiquée plus haut. Vous pouvez également introduire une réclamation auprès de la CNIL (<a href=''https://www.cnil.fr'' target=''_blank'' rel=''noopener''>www.cnil.fr</a>).</p> <h2>Mineurs</h2> <p>La participation des mineurs nécessite l''autorisation d''un responsable légal, recueillie lors de l''inscription.</p> <p><em>Dernière mise à jour : juillet 2026.</em></p>'",
    "UPDATE `setting` SET legal_privacy = '<p>La protection de vos données personnelles nous tient à cœur. La présente politique explique, en toute transparence, quelles données sont collectées sur le site forbachenrose.com, pourquoi, et quels sont vos droits.</p> <h2>Responsable du traitement</h2> <p>Les données sont traitées pour les besoins de l''organisation de l''événement « Forbach en Rose », organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach). Le site est administré à titre bénévole pour le compte de l''organisation. Contact : via le <a href=''accueil?chat=contact''>formulaire du site</a> ou par courrier à l''adresse ci-dessus.</p> <h2>Données collectées et finalités</h2> <h3>Inscription à l''événement</h3> <p>Nom, prénom, e-mail, téléphone, âge, sexe, taille de t-shirt, ville, entreprise (facultatif) et commentaire libre ; pour les mineurs, l''identité du responsable légal (autorisation parentale). Ces données servent exclusivement à la gestion de votre participation : enregistrement, envoi de la confirmation et du QR code d''accès, remise des t-shirts, organisation le jour J. Base légale : exécution du contrat d''inscription.</p> <h3>Paiement</h3> <p>Le paiement s''effectue via la plateforme <strong>AssoConnect</strong> et son prestataire de paiement sécurisé. <strong>Aucune donnée bancaire n''est collectée ni conservée sur ce site.</strong></p> <h3>Formulaire de contact (assistant)</h3> <p>Nom, e-mail, sujet, message et pièces jointes éventuelles : transmis par e-mail à l''équipe pour vous répondre, non conservés en base de données sur le site. Base légale : intérêt légitime à répondre à vos demandes.</p> <h3>Newsletter</h3> <p>Adresse e-mail uniquement, avec votre consentement explicite (case à cocher). Vous pouvez vous désinscrire à tout moment via le lien présent dans chaque envoi ou la page newsletter. Base légale : consentement.</p> <h3>Commentaires des actualités</h3> <p>Pseudo, contenu du commentaire et adresse IP (utilisée uniquement pour prévenir les abus). Base légale : intérêt légitime.</p> <h3>Assistant virtuel</h3> <p>Les questions que l''assistant ne comprend pas sont journalisées de façon anonyme afin d''améliorer ses réponses — merci de ne pas y saisir de données personnelles. Les vérifications par e-mail (inscription, t-shirt, renvoi du QR code) n''affichent jamais de données personnelles dans la conversation : le mail est renvoyé uniquement à l''adresse de l''inscrit.</p> <h3>Statistiques de visite</h3> <p>Mesure d''audience interne et respectueuse : page consultée, type de navigateur, site de provenance et adresse IP <strong>anonymisée</strong>. Aucun profilage, aucun outil d''analyse tiers (pas de Google Analytics, pas de pixel publicitaire).</p> <h2>Sécurité</h2> <p>Des mesures techniques et organisationnelles appropriées protègent vos données : données personnelles chiffrées, connexion sécurisée (HTTPS) et accès strictement limité aux personnes habilitées.</p> <h2>Destinataires et sous-traitants</h2> <ul> <li><strong>Équipe organisatrice</strong> et, le jour J, bénévoles habilités (remise des t-shirts) ;</li> <li><strong>PlanetHoster</strong> — hébergement du site et acheminement des e-mails (serveur de messagerie de l''hébergeur) ;</li> <li><strong>AssoConnect</strong> et son prestataire de paiement — plateforme d''inscription et de paiement ;</li> <li><strong>Google</strong> — polices de caractères et carte interactive (Google Maps) affichées sur la page d''accueil ;</li> <li><strong>Cloudflare</strong> — vérification anti-robots sur les formulaires, qui reçoit l''adresse IP lors de la vérification ;</li> <li><strong>CDN techniques</strong> (jsDelivr, jQuery) — chargement de fichiers techniques.</li> </ul> <p>Vos données ne sont <strong>jamais vendues ni cédées</strong>. Certains prestataires peuvent traiter des données en dehors de l''Union européenne, dans le cadre des garanties prévues par le RGPD (décision d''adéquation ou clauses contractuelles types).</p> <h2>Cookies et stockage local</h2> <p>Le site utilise uniquement un <strong>cookie de session technique</strong>, nécessaire au fonctionnement et à la sécurité (protection des formulaires) — exempté de consentement. Aucun cookie publicitaire ni traceur tiers. Le stockage local de votre navigateur peut mémoriser des préférences fonctionnelles : thème clair/sombre, préférences de l''assistant (position, message d''accueil) et votre confirmation d''inscription sur votre propre appareil.</p> <h2>Durées de conservation</h2> <ul> <li>Données d''inscription : le temps de l''organisation de l''édition concernée, puis au maximum [3 ans] après votre dernière participation ;</li> <li>Newsletter : jusqu''à votre désinscription ;</li> <li>Journaux techniques et de sécurité : durée limitée nécessaire à la protection du site ;</li> <li>Statistiques de visite : données anonymisées dès la collecte.</li> </ul> <h2>Vos droits</h2> <p>Conformément au RGPD, vous disposez des droits d''accès, de rectification, d''effacement, de limitation, d''opposition et de portabilité sur vos données. Pour les exercer : le <a href=''accueil?chat=contact''>formulaire de contact</a> du site ou un courrier à l''adresse indiquée plus haut. Vous pouvez également introduire une réclamation auprès de la CNIL (<a href=''https://www.cnil.fr'' target=''_blank'' rel=''noopener''>www.cnil.fr</a>).</p> <h2>Mineurs</h2> <p>La participation des mineurs nécessite l''autorisation d''un responsable légal, recueillie lors de l''inscription.</p> <p><em>Dernière mise à jour : juillet 2026.</em></p>' WHERE id = 1 AND legal_privacy = '<p>La protection de vos données personnelles nous tient à cœur. La présente politique explique, en toute transparence, quelles données sont collectées sur le site forbachenrose.com, pourquoi, et quels sont vos droits.</p> <h2>Responsable du traitement</h2> <p>L''association <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach — SIREN 384 589 073), organisatrice de « Forbach en Rose ». Contact : via le <a href=''accueil?chat=contact''>formulaire du site</a> ou par courrier au siège.</p> <h2>Données collectées et finalités</h2> <h3>Inscription à l''événement</h3> <p>Nom, prénom, e-mail, téléphone, âge, sexe, taille de t-shirt, ville, entreprise (facultatif) et commentaire libre ; pour les mineurs, l''identité du responsable légal (autorisation parentale). Ces données servent exclusivement à la gestion de votre participation : enregistrement, envoi de la confirmation et du QR code d''accès, remise des t-shirts, organisation le jour J. Base légale : exécution du contrat d''inscription.</p> <h3>Paiement</h3> <p>Le paiement s''effectue via la plateforme <strong>AssoConnect</strong> et son prestataire de paiement sécurisé (Adyen). <strong>Aucune donnée bancaire n''est collectée ni conservée sur ce site.</strong></p> <h3>Formulaire de contact (assistant)</h3> <p>Nom, e-mail, sujet, message et pièces jointes éventuelles : transmis par e-mail à l''équipe organisatrice pour vous répondre, non conservés en base de données sur le site. Base légale : intérêt légitime à répondre à vos demandes.</p> <h3>Newsletter</h3> <p>Adresse e-mail uniquement, avec votre consentement explicite (case à cocher). Vous pouvez vous désinscrire à tout moment via le lien présent dans chaque envoi ou la page newsletter. Base légale : consentement.</p> <h3>Commentaires des actualités</h3> <p>Pseudo, contenu du commentaire et adresse IP (utilisée uniquement pour prévenir les abus). Base légale : intérêt légitime.</p> <h3>Assistant virtuel</h3> <p>Les questions que l''assistant ne comprend pas sont journalisées de façon anonyme afin d''améliorer ses réponses — merci de ne pas y saisir de données personnelles. Les vérifications par e-mail (inscription, t-shirt, renvoi du QR code) n''affichent jamais de données personnelles dans la conversation : le mail est renvoyé uniquement à l''adresse de l''inscrit.</p> <h3>Statistiques de visite</h3> <p>Mesure d''audience interne et respectueuse : page consultée, type de navigateur, site de provenance et adresse IP <strong>anonymisée</strong> (dernier octet supprimé). Aucun profilage, aucun outil d''analyse tiers (pas de Google Analytics, pas de pixel publicitaire).</p> <h2>Sécurité</h2> <p>Les données personnelles d''inscription (nom, prénom, e-mail, téléphone, âge, ville, entreprise) sont <strong>chiffrées en base de données (AES-256-GCM)</strong>. Le site est servi en HTTPS, les secrets de configuration sont chiffrés, l''accès à l''administration est restreint (authentification forte) et les formulaires sont protégés contre les robots.</p> <h2>Destinataires et sous-traitants</h2> <ul> <li><strong>Équipe organisatrice</strong> et, le jour J, bénévoles habilités (remise des t-shirts) ;</li> <li><strong>LWS</strong> — hébergement du site (France) ;</li> <li><strong>AssoConnect / Adyen</strong> — plateforme d''inscription et de paiement ;</li> <li><strong>Google</strong> — acheminement des e-mails du site ; polices de caractères et carte interactive (Google Maps) sur la page d''accueil ;</li> <li><strong>Cloudflare</strong> — vérification anti-robots (Turnstile) sur les formulaires, qui reçoit l''adresse IP lors de la vérification ;</li> <li><strong>CDN techniques</strong> (jsDelivr, jQuery) — chargement de fichiers techniques.</li> </ul> <p>Vos données ne sont <strong>jamais vendues ni cédées</strong>. Certains prestataires (Google, Cloudflare) peuvent traiter des données en dehors de l''Union européenne, dans le cadre des garanties prévues par le RGPD (clauses contractuelles types).</p> <h2>Cookies et stockage local</h2> <p>Le site utilise uniquement un <strong>cookie de session technique</strong>, nécessaire au fonctionnement et à la sécurité (protection des formulaires) — exempté de consentement. Aucun cookie publicitaire ni traceur tiers. Le stockage local de votre navigateur peut mémoriser des préférences fonctionnelles : thème clair/sombre, préférences de l''assistant (position, message d''accueil) et votre confirmation d''inscription sur votre propre appareil.</p> <h2>Durées de conservation</h2> <ul> <li>Données d''inscription : le temps de l''organisation de l''édition concernée, puis au maximum [3 ans] après votre dernière participation ;</li> <li>Newsletter : jusqu''à votre désinscription ;</li> <li>Journaux techniques et de sécurité : durée limitée nécessaire à la protection du site ;</li> <li>Statistiques de visite : données anonymisées dès la collecte.</li> </ul> <h2>Vos droits</h2> <p>Conformément au RGPD, vous disposez des droits d''accès, de rectification, d''effacement, de limitation, d''opposition et de portabilité sur vos données. Pour les exercer : le <a href=''accueil?chat=contact''>formulaire de contact</a> du site ou un courrier au siège de l''association. Vous pouvez également introduire une réclamation auprès de la CNIL (<a href=''https://www.cnil.fr'' target=''_blank'' rel=''noopener''>www.cnil.fr</a>).</p> <h2>Mineurs</h2> <p>La participation des mineurs nécessite l''autorisation d''un responsable légal, recueillie lors de l''inscription.</p> <p><em>Dernière mise à jour : juillet 2026.</em></p>'",
];

$results = [];

/* ─────────────────────────────────────────────────────────────────────────
 * v2.0.0 — Configuration chiffrée : .env → config.enc + master.key
 * -------------------------------------------------------------------------
 * src/core/config.php (chargé en tête de ce fichier) a déjà migré automatiquement
 * l'ancien .env vers config.enc au premier chargement. Ici on vérifie que la
 * config chiffrée est bien fonctionnelle, puis on supprime les fichiers
 * devenus inutiles : config/.env (secrets en clair !) et config/.env.example.
 * ───────────────────────────────────────────────────────────────────────── */
$cfgMigrateSql = 'MIGRATE config/.env → config.enc + master.key (v2.0.0)';
$cfgOk = false;
try {
    if (FerSecureConfig::exists()) {
        $cfgData = FerSecureConfig::load();
        if (FerSecureConfig::isComplete($cfgData)) {
            $cfgOk = true;
            // « Appliquée » uniquement lors de la vraie migration (un .env est
            // encore présent) ; ensuite l'étape est simplement ignorée.
            $results[] = file_exists(__DIR__ . '/config/.env')
                ? ['status' => 'success', 'sql' => $cfgMigrateSql, 'msg' => 'Configuration chiffrée vérifiée — le .env va être supprimé']
                : ['status' => 'skip',    'sql' => $cfgMigrateSql, 'msg' => 'Déjà migré'];
        } else {
            $results[] = ['status' => 'error', 'sql' => $cfgMigrateSql, 'msg' => 'config.enc incomplet (clés manquantes)'];
        }
    } else {
        $results[] = ['status' => 'error', 'sql' => $cfgMigrateSql, 'msg' => 'config.enc absent — migration non effectuée'];
    }
} catch (\Throwable $e) {
    $results[] = ['status' => 'error', 'sql' => $cfgMigrateSql, 'msg' => $e->getMessage()];
}

// Suppression de config/.env — UNIQUEMENT si config.enc est vérifié fonctionnel
$envDeleteSql = 'DELETE config/.env (secrets en clair, remplacé par config.enc)';
$oldEnv = __DIR__ . '/config/.env';
if (!file_exists($oldEnv)) {
    $results[] = ['status' => 'skip', 'sql' => $envDeleteSql, 'msg' => 'Déjà supprimé'];
} elseif (!$cfgOk) {
    $results[] = ['status' => 'skip', 'sql' => $envDeleteSql, 'msg' => 'Conservé : config.enc non vérifié'];
} elseif (@unlink($oldEnv)) {
    $results[] = ['status' => 'success', 'sql' => $envDeleteSql, 'msg' => 'Fichier supprimé'];
} else {
    $results[] = ['status' => 'error', 'sql' => $envDeleteSql, 'msg' => 'Suppression impossible (permissions) — supprimez-le via FTP'];
}

// Suppression de config/.env.example (obsolète : install.php génère config.enc)
$exampleDeleteSql = 'DELETE config/.env.example (obsolète en v2.0.0)';
$oldExample = __DIR__ . '/config/.env.example';
if (!file_exists($oldExample)) {
    $results[] = ['status' => 'skip', 'sql' => $exampleDeleteSql, 'msg' => 'Déjà supprimé'];
} elseif (@unlink($oldExample)) {
    $results[] = ['status' => 'success', 'sql' => $exampleDeleteSql, 'msg' => 'Fichier supprimé'];
} else {
    $results[] = ['status' => 'error', 'sql' => $exampleDeleteSql, 'msg' => 'Suppression impossible (permissions)'];
}

/* ─────────────────────────────────────────────────────────────────────────
 * v2.0.0 — Nouvelle arborescence : les librairies PHP de config/ ont été
 * déplacées vers src/ (core / security / mail / content) et config/api.php
 * est devenu admin-api.php à la racine. Sur une installation existante mise
 * à jour par écrasement des fichiers, les anciens exemplaires restent dans
 * config/ : on les supprime UNIQUEMENT si leur remplaçant existe bien.
 * config/ ne contient plus que : config.enc, master.key, token.json, logs/.
 * ───────────────────────────────────────────────────────────────────────── */
$movedLibs = [
    'config/config.php'             => 'src/core/config.php',
    'config/secure.php'             => 'src/core/secure.php',
    'config/debug.php'              => 'src/core/debug.php',
    'config/csrf.php'               => 'src/security/csrf.php',
    'config/captcha.php'            => 'src/security/captcha.php',
    'config/totp.php'               => 'src/security/totp.php',
    'config/webauthn.php'           => 'src/security/webauthn.php',
    'config/googleMail.php'         => 'src/mail/googleMail.php',
    'config/mail_template.php'      => 'src/mail/mail_template.php',
    'config/newsletter.php'         => 'src/mail/newsletter.php',
    'config/theme.php'              => 'src/content/theme.php',
    'config/tracker.php'            => 'src/content/tracker.php',
    'config/content-log.php'        => 'src/content/content-log.php',
    'config/accueil_layout.php'     => 'src/content/accueil_layout.php',
    'config/accueil_sections.php'   => 'src/content/accueil_sections.php',
    'config/form_fields.php'        => 'src/content/form_fields.php',
    'config/registrations_core.php' => 'src/content/registrations_core.php',
    'config/assoconnect_client.php' => 'src/content/assoconnect_client.php',
    'config/sync_assoconnect.php'   => 'src/content/sync_assoconnect.php',
    'config/api.php'                => 'admin-api.php',
    // Fragments d'interface (includes purs, jamais des URLs) : inc/ → src/partials/
    'inc/navbar-admin.php'          => 'src/partials/navbar-admin.php',
    'inc/navbar-data.php'           => 'src/partials/navbar-data.php',
    'inc/navbar-modern.php'         => 'src/partials/navbar-modern.php',
    'inc/footer-modern.php'         => 'src/partials/footer-modern.php',
    'inc/admin-footer.php'          => 'src/partials/admin-footer.php',
    'inc/toast.php'                 => 'src/partials/toast.php',
    'inc/profile-modal.php'         => 'src/partials/profile-modal.php',
    'inc/_stats-more-modal.php'     => 'src/partials/_stats-more-modal.php',
];
$movedSql = 'DELETE anciennes librairies config/*.php + fragments inc/*.php (déplacés vers src/, v2.0.0)';
$movedDeleted = 0; $movedKept = 0; $movedErrors = [];
foreach ($movedLibs as $old => $new) {
    $oldPath = __DIR__ . '/' . $old;
    if (!file_exists($oldPath)) continue;
    if (!file_exists(__DIR__ . '/' . $new)) {
        $movedKept++;
        $movedErrors[] = "$old conservé ($new introuvable)";
        continue;
    }
    if (@unlink($oldPath)) { $movedDeleted++; }
    else { $movedKept++; $movedErrors[] = "$old : suppression impossible (permissions)"; }
}
if ($movedDeleted === 0 && $movedKept === 0) {
    $results[] = ['status' => 'skip', 'sql' => $movedSql, 'msg' => 'Déjà nettoyé'];
} elseif ($movedKept === 0) {
    $results[] = ['status' => 'success', 'sql' => $movedSql, 'msg' => "$movedDeleted fichier(s) supprimé(s)"];
} else {
    $results[] = ['status' => 'error', 'sql' => $movedSql, 'msg' => "$movedDeleted supprimé(s), $movedKept conservé(s) : " . implode(' ; ', $movedErrors)];
}

/* ── L'ancien api.php, resté à la racine du serveur ─────────────────────────
 *
 * ⚠️⚠️ CE N'EST PAS UN SIMPLE MÉNAGE. `api.php` a été déplacé en
 * `api/v1.php`, mais un déploiement qui ne fait qu'envoyer des fichiers ne
 * supprime rien : l'ancien reste sur le serveur, ET IL CONTINUE DE FONCTIONNER
 * — il est à la racine, ses chemins `__DIR__` résolvent toujours.
 *
 * On se retrouverait alors avec DEUX API vivantes servant le même secret : la
 * nouvelle, corrigée à chaque version, et une copie figée que plus personne ne
 * regarde. Le jour où une faille d'authentification est corrigée, elle ne l'est
 * que d'un côté — et c'est l'autre qui reste ouvert, en silence.
 *
 * On ne supprime qu'après avoir vérifié que le remplaçant est bien là : mieux
 * vaut deux copies qu'aucune API du tout.
 * ──────────────────────────────────────────────────────────────────────────── */
/* Écrit en fonction, et non en ligne, POUR QU'UN BANC PUISSE L'ÉPROUVER : ce
   code ne s'exécute qu'une fois, sur le vrai serveur, le jour de la mise à jour.
   S'il se trompe, personne n'est là pour le rattraper. docs/test-integrite.php
   (§ 20) l'extrait et le rejoue sur un dossier jetable. */
function updSupprimerAncienApi(string $racine): array
{
    $sql       = 'DELETE api.php (déplacé vers api/v1.php)';
    $vieille   = $racine . '/api.php';
    $nouvelle  = $racine . '/api/v1.php';
    if (!is_file($vieille)) {
        return ['status' => 'skip', 'sql' => $sql, 'msg' => 'Déjà supprimé'];
    }
    if (!is_file($nouvelle)) {
        return ['status' => 'error', 'sql' => $sql,
            'msg' => 'api/v1.php est absent : api.php est CONSERVÉ pour ne pas couper '
                   . "l'API. Renvoyez le dossier api/ puis relancez cette mise à jour."];
    }
    if (@unlink($vieille)) {
        return ['status' => 'success', 'sql' => $sql,
            'msg' => "Ancienne API supprimée — l'adresse …/api.php ne répond plus, "
                   . 'prévenez vos partenaires si vous en avez.'];
    }
    return ['status' => 'error', 'sql' => $sql,
        'msg' => 'Suppression impossible (permissions). DEUX API vivent côte à côte : '
               . 'supprimez api.php à la main, sans quoi une correction de sécurité '
               . "ne s'appliquera qu'à l'une des deux."];
}
$results[] = updSupprimerAncienApi(__DIR__);

/* ── L'ancien dossier api/v1/, qui masquerait la nouvelle API ───────────────
 *
 * ⚠️⚠️ LE PLUS SOURNOIS DES DEUX. `api/v1/` était l'API mobile ; elle s'appelle
 * maintenant `api/mobile/`, et le nom `api/v1` désigne l'API des logiciels
 * tiers — un FICHIER, `api/v1.php`.
 *
 * Or la réécriture « /x » → « /x.php » de la racine ne s'applique QUE si le
 * chemin n'est ni un fichier ni un dossier. Si l'ancien dossier `api/v1/`
 * survit au déploiement, il gagne : « /api/v1 » tombe dessus, pas sur
 * `v1.php`. L'API des logiciels tiers devient injoignable — sans message
 * d'erreur, juste un 403 de listing interdit qui n'explique rien.
 *
 * On ne supprime que ce qu'on a écrit soi-même, et seulement si le remplaçant
 * est en place. Tout fichier étranger trouvé là fait renoncer : on préfère un
 * avertissement clair à une suppression aveugle.
 * ──────────────────────────────────────────────────────────────────────────── */
/* Même raison qu'au-dessus : en fonction, pour être éprouvable hors production. */
function updSupprimerAncienDossierV1(string $racine): array
{
    $sql     = 'DELETE api/v1/ (l\'API mobile a déménagé en api/mobile/)';
    $vieux   = $racine . '/api/v1';
    if (!is_dir($vieux)) {
        return ['status' => 'skip', 'sql' => $sql, 'msg' => 'Déjà supprimé'];
    }
    if (!is_file($racine . '/api/mobile/index.php')) {
        return ['status' => 'error', 'sql' => $sql,
            'msg' => 'api/mobile/index.php est absent : api/v1/ est CONSERVÉ pour ne pas '
                   . "couper l'API mobile. Renvoyez le dossier api/ puis relancez."];
    }
    /* On ne supprime que ce qu'on a écrit soi-même. Tout fichier étranger fait
       renoncer : mieux vaut un avertissement clair qu'un effacement aveugle. */
    $connus    = ['index.php', '.htaccess'];
    $restant   = array_values(array_diff(scandir($vieux) ?: [], ['.', '..']));
    $etrangers = array_diff($restant, $connus);
    if ($etrangers) {
        return ['status' => 'error', 'sql' => $sql,
            'msg' => 'Fichiers inattendus dans api/v1/ (' . implode(', ', $etrangers)
                   . ') : rien n\'a été supprimé. Videz le dossier à la main — tant '
                   . "qu'il existe, l'adresse /api/v1 ne répond pas."];
    }
    foreach ($connus as $c) { if (is_file($vieux . '/' . $c)) @unlink($vieux . '/' . $c); }
    if (@rmdir($vieux)) {
        return ['status' => 'success', 'sql' => $sql,
            'msg' => 'Ancien dossier supprimé — /api/v1 sert désormais l\'API '
                   . 'des logiciels tiers, /api/mobile celle des coureurs.'];
    }
    return ['status' => 'error', 'sql' => $sql,
        'msg' => 'Suppression impossible (permissions). TANT QUE api/v1/ existe, '
               . "l'adresse /api/v1 ne répond pas : supprimez le dossier à la main."];
}
$results[] = updSupprimerAncienDossierV1(__DIR__);

// Divers fichiers/dossiers obsolètes en v2.0.0
$obsoleteSql = 'DELETE fichiers obsolètes divers (fonts/Version-1.0.3.md, config/sessions/)';
$obsoleteDone = [];
$oldNotes = __DIR__ . '/fonts/Version-1.0.3.md';
if (is_file($oldNotes) && @unlink($oldNotes)) { $obsoleteDone[] = 'fonts/Version-1.0.3.md'; }
$oldSessions = __DIR__ . '/config/sessions';
if (is_dir($oldSessions)) {
    foreach (glob($oldSessions . '/{,.}*', GLOB_BRACE) ?: [] as $sf) {
        if (is_file($sf)) { @unlink($sf); }
    }
    if (@rmdir($oldSessions)) { $obsoleteDone[] = 'config/sessions/'; }
}
$results[] = empty($obsoleteDone)
    ? ['status' => 'skip', 'sql' => $obsoleteSql, 'msg' => 'Déjà nettoyé']
    : ['status' => 'success', 'sql' => $obsoleteSql, 'msg' => 'Supprimé : ' . implode(', ', $obsoleteDone)];

/* ─────────────────────────────────────────────────────────────────────────
 * v2.0.0 — Les logs quittent config/ pour storage/logs/ (config/ = config
 * pure uniquement : config.enc, master.key, token.json). On déplace les
 * fichiers existants puis on supprime l'ancien dossier config/logs.
 * ───────────────────────────────────────────────────────────────────────── */
$logsMoveSql = 'MOVE config/logs/* → storage/logs/ (v2.0.0)';
$oldLogsDir = __DIR__ . '/config/logs';
$newLogsDir = __DIR__ . '/storage/logs';
if (!is_dir($oldLogsDir)) {
    $results[] = ['status' => 'skip', 'sql' => $logsMoveSql, 'msg' => 'Déjà déplacé'];
} else {
    if (!is_dir($newLogsDir)) { @mkdir($newLogsDir, 0755, true); }
    $logsMoved = 0; $logsFailed = [];
    foreach (glob($oldLogsDir . '/*') ?: [] as $f) {
        $dest = $newLogsDir . '/' . basename($f);
        // Si un fichier du même nom existe déjà côté storage (log recréé entre
        // la mise à jour des fichiers et l'exécution d'update.php), on fusionne.
        if (is_file($dest) && is_file($f)) {
            if (@file_put_contents($dest, (string) @file_get_contents($f), FILE_APPEND) !== false && @unlink($f)) {
                $logsMoved++;
            } else {
                $logsFailed[] = basename($f);
            }
        } elseif (@rename($f, $dest)) {
            $logsMoved++;
        } else {
            $logsFailed[] = basename($f);
        }
    }
    @unlink($oldLogsDir . '/.gitkeep');
    if (empty($logsFailed) && @rmdir($oldLogsDir)) {
        $results[] = ['status' => 'success', 'sql' => $logsMoveSql, 'msg' => "$logsMoved fichier(s) déplacé(s), config/logs supprimé"];
    } elseif (empty($logsFailed)) {
        $results[] = ['status' => 'success', 'sql' => $logsMoveSql, 'msg' => "$logsMoved fichier(s) déplacé(s) — supprimez config/logs manuellement"];
    } else {
        $results[] = ['status' => 'error', 'sql' => $logsMoveSql, 'msg' => "$logsMoved déplacé(s), échec : " . implode(', ', $logsFailed)];
    }
}

// Renommer le fichier de logs Google Mails .txt -> .log
$oldLog = __DIR__ . '/storage/logs/logs_google_mails.txt';
$newLog = __DIR__ . '/storage/logs/logs_google_mails.log';
if (file_exists($oldLog) && !file_exists($newLog)) {
    rename($oldLog, $newLog);
    $results[] = ['status' => 'success', 'sql' => 'RENAME logs_google_mails.txt → logs_google_mails.log', 'msg' => 'Fichier renommé'];
} elseif (file_exists($newLog)) {
    $results[] = ['status' => 'skip', 'sql' => 'RENAME logs_google_mails.txt → logs_google_mails.log', 'msg' => 'Déjà renommé'];
} else {
    $results[] = ['status' => 'skip', 'sql' => 'RENAME logs_google_mails.txt → logs_google_mails.log', 'msg' => 'Fichier source introuvable'];
}
// Migration inscription_no INT → VARCHAR(50) (vérifier avant d'exécuter)
try {
    $colType = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registrations' AND COLUMN_NAME = 'inscription_no'")->fetchColumn();
    if ($colType && stripos($colType, 'varchar') === false) {
        $pdo->exec("ALTER TABLE `registrations` MODIFY COLUMN `inscription_no` VARCHAR(50) NOT NULL");
        $results[] = ['status' => 'success', 'sql' => 'MODIFY inscription_no INT → VARCHAR(50)', 'msg' => 'OK'];
    } else {
        $results[] = ['status' => 'skip', 'sql' => 'MODIFY inscription_no INT → VARCHAR(50)', 'msg' => 'Déjà en VARCHAR'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => 'MODIFY inscription_no INT → VARCHAR(50)', 'msg' => $e->getMessage()];
}

/**
 * Vérifie l'existence d'une table dans la base courante.
 */
// Conservée pour ne pas toucher aux dizaines d'appels existants ; elle délègue à
// updTableExists() pour qu'il n'y ait qu'une seule implémentation.
$tableExists = fn (string $name): bool => updTableExists($pdo, $name);

foreach ($migrations as $sql) {
    // CREATE TABLE IF NOT EXISTS et DROP TABLE IF EXISTS ne lèvent jamais
    // d'exception en cas de no-op : on inspecte la BDD avant d'exécuter pour
    // afficher un statut « Existe déjà » / « N'existe pas » plutôt qu'« OK ».
    if (preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?(\w+)`?/i', $sql, $m)) {
        if ($tableExists($m[1])) {
            $results[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'Table « ' . $m[1] . ' » existe déjà'];
            continue;
        }
    } elseif (preg_match('/^\s*DROP\s+TABLE\s+IF\s+EXISTS\s+`?(\w+)`?/i', $sql, $m)) {
        if (!$tableExists($m[1])) {
            $results[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'Table « ' . $m[1] . ' » déjà absente'];
            continue;
        }
    }

    // ALTER … MODIFY / CHANGE : ces requêtes sont rejouées à chaque lancement et
    // affichaient « OK » même quand la colonne avait déjà exactement la bonne
    // définition — impossible de distinguer une vraie migration d'un no-op.
    // On compare le SHOW CREATE TABLE avant/après : c'est MySQL lui-même qui
    // tranche, sans avoir à analyser la définition SQL (types, DEFAULT, COLLATE,
    // largeurs d'entier affichées différemment selon les versions…).
    $alterBefore = null;
    if (preg_match('/^\s*ALTER\s+TABLE\s+`?(\w+)`?\s+(?:MODIFY|CHANGE)\b/i', $sql, $mAlter) && $tableExists($mAlter[1])) {
        try {
            $row = $pdo->query("SHOW CREATE TABLE `{$mAlter[1]}`")->fetch(PDO::FETCH_NUM);
            $alterBefore = $row[1] ?? null;
        } catch (\Throwable $e) { $alterBefore = null; }
    }

    try {
        $affected = $pdo->exec($sql);

        // Structure inchangée après un MODIFY/CHANGE → la colonne était déjà
        // conforme : « Déjà appliqué » plutôt qu'« OK ».
        if ($alterBefore !== null) {
            try {
                $row   = $pdo->query("SHOW CREATE TABLE `{$mAlter[1]}`")->fetch(PDO::FETCH_NUM);
                $after = $row[1] ?? null;
                if ($after !== null && $after === $alterBefore) {
                    $results[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'Déjà appliqué (structure inchangée)'];
                    continue;
                }
                $results[] = ['status' => 'success', 'sql' => $sql, 'msg' => 'Colonne modifiée'];
                continue;
            } catch (\Throwable $e) { /* comparaison impossible : on retombe sur le cas général */ }
        }

        // 0 ligne affectée = rien à faire :
        //   - INSERT IGNORE → la ligne existe déjà ;
        //   - UPDATE        → la valeur cible est déjà en place (ex. is_locked déjà à 0).
        // On affiche « Déjà appliqué » plutôt que « OK » pour éviter de croire qu'une
        // action a lieu à chaque passage.
        if ($affected === 0 && preg_match('/^\s*INSERT\b/i', $sql)) {
            $results[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'Déjà présent'];
        } elseif ($affected === 0 && preg_match('/^\s*UPDATE\s/i', $sql)) {
            $results[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'Déjà appliqué'];
        } else {
            $results[] = ['status' => 'success', 'sql' => $sql, 'msg' => 'OK'];
        }
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Duplicate column') || str_contains($msg, 'check that column/key exists') || str_contains($msg, "Can't DROP")) {
            $results[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'Existe déjà ou déjà appliqué'];
        } else {
            $results[] = ['status' => 'error', 'sql' => $sql, 'msg' => $msg];
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// jr-theme : le dossier n'existe plus, ses fichiers vivent dans css/, js/ et
// fonts/ (chemins standard du site). Si une ancienne copie jr-theme/ traîne
// encore sur le serveur, on déplace ce qui manque puis on supprime le dossier.
// ─────────────────────────────────────────────────────────────────────────
$jrMoveSql = 'Déplacer jr-theme/ vers css/, js/ et fonts/ puis supprimer le dossier';
$jrDir = __DIR__ . '/jr-theme';
if (is_dir($jrDir)) {
    $jrMoved = 0; $jrErrs = [];
    $jrFiles = [
        'css/tokens.css', 'css/base.css', 'css/components.css', 'css/app.css',
        'js/theme.js', 'js/ui.js',
        'fonts/Inter-var.woff2', 'fonts/JetBrainsMono-var.woff2',
    ];
    foreach ($jrFiles as $rel) {
        $src = $jrDir . '/' . $rel;
        $dst = __DIR__ . '/' . $rel;
        if (!file_exists($src)) continue;
        if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0755, true);
        if (file_exists($dst)) { @unlink($src); continue; } // nouvelle version déjà déployée : on garde
        if (@rename($src, $dst)) $jrMoved++; else $jrErrs[] = $rel;
    }
    // rmdir ne supprime que des dossiers vides : un fichier inattendu est préservé
    foreach (['css', 'js', 'fonts'] as $sub) { @rmdir($jrDir . '/' . $sub); }
    @rmdir($jrDir);
    $results[] = $jrErrs
        ? ['status' => 'error', 'sql' => $jrMoveSql, 'msg' => 'Échec sur : ' . implode(', ', $jrErrs) . ' (droits d\'écriture ?)']
        : ['status' => 'success', 'sql' => $jrMoveSql, 'msg' => $jrMoved . ' fichier(s) déplacé(s), dossier jr-theme/ supprimé'];
} else {
    $results[] = ['status' => 'skip', 'sql' => $jrMoveSql, 'msg' => 'Dossier jr-theme/ absent (déjà migré)'];
}

// ─────────────────────────────────────────────────────────────────────────
// Colonne `required_admin` : caractère obligatoire SPÉCIFIQUE au formulaire
// « Nouvel inscrit » (admin), indépendant de `required` (public / saisie / QR)
// — même principe que `required_saisie_multiple` pour l'« Ajout multiple ».
// Ajout + initialisation en UN passage : à la création de la colonne, on la
// pré-remplit avec la valeur de `required` (comportement identique à l'existant).
// Une fois la colonne créée, on n'y touche PLUS JAMAIS → les choix de l'admin
// dans « Gestion des champs » sont préservés à chaque nouveau lancement d'update.php.
// ─────────────────────────────────────────────────────────────────────────
$requiredAdminSql = "Ajouter la colonne `required_admin` (obligatoire admin) dans `forms`";
try {
    $colExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forms' AND COLUMN_NAME = 'required_admin'"
    )->fetchColumn();
    if ($colExists > 0) {
        $results[] = ['status' => 'skip', 'sql' => $requiredAdminSql, 'msg' => 'Existe déjà'];
    } else {
        $pdo->exec("ALTER TABLE `forms` ADD COLUMN `required_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `required`");
        // Initialisation UNIQUE (à la création) : reprend la valeur de `required`.
        $pdo->exec("UPDATE `forms` SET `required_admin` = `required`");
        $results[] = ['status' => 'success', 'sql' => $requiredAdminSql, 'msg' => 'Colonne ajoutée et initialisée'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $requiredAdminSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Pré-remplissage de `registrations.montant_du` pour les inscrits existants.
// Idempotent : si toutes les lignes ont déjà un montant > 0, on n'agit pas.
// IMPORTANT : on EXCLUT les inscrits « gratuit » (enfant -12 ans sans t-shirt),
// qui doivent rester à 0 € — sinon ils seraient à tort facturés au tarif.
// ─────────────────────────────────────────────────────────────────────────
$initMontantSql = "Pré-remplir registrations.montant_du = registration_fee pour les inscrits existants";
try {
    $remaining = (int) $pdo->query("SELECT COUNT(*) FROM `registrations` WHERE `montant_du` = 0 AND (`paiement_mode` IS NULL OR `paiement_mode` <> 'gratuit')")->fetchColumn();
    if ($remaining === 0) {
        $results[] = ['status' => 'skip', 'sql' => $initMontantSql, 'msg' => 'Déjà appliqué'];
    } else {
        $stmt = $pdo->prepare(
            "UPDATE `registrations` r JOIN `setting` s ON s.id = 1
             SET r.montant_du = COALESCE(s.registration_fee, 0)
             WHERE r.montant_du = 0 AND (r.paiement_mode IS NULL OR r.paiement_mode <> 'gratuit')"
        );
        $stmt->execute();
        $results[] = ['status' => 'success', 'sql' => $initMontantSql, 'msg' => $stmt->rowCount() . ' ligne(s) mises à jour'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $initMontantSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Classement des inscrits existants dans `registrations.prestation`.
// Idempotent : ne touche que les lignes encore NULL/vides. Un ancien enfant
// -12 ans AVEC t-shirt est indissociable d'un adulte (même montant) faute de
// donnée source — il est donc classé « tarif_unique » comme les adultes.
// ─────────────────────────────────────────────────────────────────────────
$initPrestationSql = "Classer registrations.prestation pour les inscrits existants";
try {
    $colExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registrations' AND COLUMN_NAME = 'prestation'"
    )->fetchColumn();
    if ($colExists === 0) {
        $results[] = ['status' => 'skip', 'sql' => $initPrestationSql, 'msg' => 'Colonne absente (migration non appliquée)'];
    } else {
        $remaining = (int) $pdo->query("SELECT COUNT(*) FROM `registrations` WHERE `prestation` IS NULL OR `prestation` = ''")->fetchColumn();
        if ($remaining === 0) {
            $results[] = ['status' => 'skip', 'sql' => $initPrestationSql, 'msg' => 'Déjà appliqué'];
        } else {
            $stmt = $pdo->prepare(
                "UPDATE `registrations`
                 SET `prestation` = CASE
                     WHEN `paiement_mode` = 'enfant_tshirt' THEN 'enfant_tshirt'
                     WHEN `paiement_mode` = 'gratuit' OR `montant_du` <= 0 THEN 'enfant_gratuit'
                     ELSE 'tarif_unique'
                 END
                 WHERE `prestation` IS NULL OR `prestation` = ''"
            );
            $stmt->execute();
            $results[] = ['status' => 'success', 'sql' => $initPrestationSql, 'msg' => $stmt->rowCount() . ' ligne(s) classées'];
        }
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $initPrestationSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Correction : un enfant -12 ans GRATUIT (sans t-shirt) doit avoir un montant
// de 0 €. Les versions antérieures du pré-remplissage ci-dessus avaient pu les
// passer au tarif (12 €) ; on les remet à 0 € (sinon comptés à tort pour le QR).
// ─────────────────────────────────────────────────────────────────────────
$fixGratuitMontantSql = "Remettre à 0 € le montant des enfants -12 ans gratuits";
try {
    $stmt = $pdo->prepare(
        "UPDATE `registrations` SET `montant_du` = 0
         WHERE (`prestation` = 'enfant_gratuit' OR `paiement_mode` = 'gratuit') AND `montant_du` <> 0"
    );
    $stmt->execute();
    $n = $stmt->rowCount();
    $results[] = ['status' => $n > 0 ? 'success' : 'skip', 'sql' => $fixGratuitMontantSql,
                  'msg' => $n > 0 ? ($n . ' ligne(s) corrigées') : 'Rien à corriger'];
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $fixGratuitMontantSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Normalisation du mode de paiement : « enfant_tshirt » n'est pas un moyen de
// paiement (l'enfant -12 ans AVEC t-shirt a payé). On le remplace par
// « en ligne (CB) » — la catégorie est déjà conservée dans `prestation`
// (classée juste au-dessus). « gratuit » (vraiment gratuit) est conservé tel quel.
// ─────────────────────────────────────────────────────────────────────────
$normPaiementSql = "Normaliser paiement_mode « enfant_tshirt » → « en ligne (CB) »";
try {
    $stmt = $pdo->prepare("UPDATE `registrations` SET `paiement_mode` = 'en ligne (CB)' WHERE `paiement_mode` = 'enfant_tshirt'");
    $stmt->execute();
    $n = $stmt->rowCount();
    $results[] = ['status' => $n > 0 ? 'success' : 'skip', 'sql' => $normPaiementSql,
                  'msg' => $n > 0 ? ($n . ' ligne(s) mises à jour') : 'Rien à normaliser'];
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $normPaiementSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Mapping de la colonne Excel « Montant dû » dans la table `import`.
// ─────────────────────────────────────────────────────────────────────────
$importMapSql = "Ajouter le mapping import « Montant dû » (id 13)";
try {
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM `import` WHERE `fields_bdd` = 'montant_du'")->fetchColumn();
    if ($exists > 0) {
        $results[] = ['status' => 'skip', 'sql' => $importMapSql, 'msg' => 'Existe déjà'];
    } else {
        $pdo->prepare("INSERT INTO `import` (`id`, `fields_bdd`, `fields_excel`) VALUES (13, 'montant_du', 'Montant du')")
            ->execute();
        $results[] = ['status' => 'success', 'sql' => $importMapSql, 'msg' => 'Mapping ajouté'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $importMapSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Mapping de la colonne Excel « Prestations » dans la table `import`.
// Permet de configurer/renommer la colonne depuis Réglages → Import Excel.
// ─────────────────────────────────────────────────────────────────────────
$importPrestationSql = "Ajouter le mapping import « Prestations » (id 14)";
try {
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM `import` WHERE `fields_bdd` = 'prestation'")->fetchColumn();
    if ($exists > 0) {
        $results[] = ['status' => 'skip', 'sql' => $importPrestationSql, 'msg' => 'Existe déjà'];
    } else {
        $pdo->prepare("INSERT INTO `import` (`id`, `fields_bdd`, `fields_excel`) VALUES (14, 'prestation', 'Prestations')")
            ->execute();
        $results[] = ['status' => 'success', 'sql' => $importPrestationSql, 'msg' => 'Mapping ajouté'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $importPrestationSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Colonne `date_inscription` (registrations) : date réelle d'inscription, distincte
// de `created_at` (= date d'AJOUT dans le logiciel). Antidatable, elle pilote le
// classement QR. Backfill UNE SEULE FOIS (à la création de la colonne) avec
// `created_at` → les inscrits existants gardent exactement leur classement actuel.
// Idempotent : un re-run saute le backfill, donc les dates antidatées sont préservées.
// ─────────────────────────────────────────────────────────────────────────
$dateInscriptionColSql = "Ajouter la colonne `date_inscription` (registrations) + backfill = created_at";
try {
    $colExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registrations' AND COLUMN_NAME = 'date_inscription'"
    )->fetchColumn();
    if ($colExists > 0) {
        $results[] = ['status' => 'skip', 'sql' => $dateInscriptionColSql, 'msg' => 'Existe déjà (backfill non rejoué)'];
    } else {
        $pdo->exec("ALTER TABLE `registrations` ADD COLUMN `date_inscription` DATETIME DEFAULT CURRENT_TIMESTAMP AFTER `created_at`");
        // Backfill initial : les inscrits existants prennent leur date d'ajout.
        $pdo->exec("UPDATE `registrations` SET `date_inscription` = `created_at`");
        $results[] = ['status' => 'success', 'sql' => $dateInscriptionColSql, 'msg' => 'Colonne ajoutée + backfill = created_at'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $dateInscriptionColSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Référence de `montant_du` dans la gestion des champs (table `forms`).
// Verrouillée — auto-calculée d'après le paiement, non éditable côté UI.
// ─────────────────────────────────────────────────────────────────────────
$formsMontantSql = "Ajouter la référence montant_du dans `forms` (verrouillée)";
try {
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM `forms` WHERE `bdd_column` = 'montant_du'")->fetchColumn();
    if ($exists > 0) {
        $results[] = ['status' => 'skip', 'sql' => $formsMontantSql, 'msg' => 'Existe déjà'];
    } else {
        $pdo->prepare(
            "INSERT INTO `forms`
              (`fields`, `label`, `field_type`, `bdd_column`, `active`, `required`,
               `is_locked`, `is_default`, `visible_public`, `visible_admin`, `visible_saisie`, `visible_qr`,
               `sort_order`, `options_list`, `encrypted`)
             VALUES ('required_montant', 'Montant dû', 'number', 'montant_du', 0, 0,
                     1, 1, 0, 1, 1, 0, 10, NULL, 0)"
        )->execute();
        $results[] = ['status' => 'success', 'sql' => $formsMontantSql, 'msg' => 'Champ ajouté'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $formsMontantSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Champ « Commentaire » dans la gestion des champs (table `forms`).
// Zone de texte libre, visible partout (admin / saisie / QR), chiffrée.
// Stocke aussi l'autorisation du représentant légal des inscrits mineurs.
// ─────────────────────────────────────────────────────────────────────────
$formsCommentaireSql = "Ajouter le champ commentaire dans `forms` (zone de texte)";
try {
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM `forms` WHERE `bdd_column` = 'commentaire'")->fetchColumn();
    if ($exists > 0) {
        $results[] = ['status' => 'skip', 'sql' => $formsCommentaireSql, 'msg' => 'Existe déjà'];
    } else {
        $pdo->prepare(
            "INSERT INTO `forms`
              (`fields`, `label`, `field_type`, `bdd_column`, `active`, `required`,
               `is_locked`, `is_default`, `visible_public`, `visible_admin`, `visible_saisie`, `visible_qr`,
               `sort_order`, `options_list`, `encrypted`)
             VALUES ('custom_commentaire', 'Commentaire', 'textarea', 'commentaire', 1, 0,
                     0, 1, 1, 1, 1, 1, 11, NULL, 1)"
        )->execute();
        $results[] = ['status' => 'success', 'sql' => $formsCommentaireSql, 'msg' => 'Champ ajouté'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $formsCommentaireSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Ligne « Autorisation parentale (mineur) » dans la gestion des champs (`forms`).
// Champ spécial (field_type='guardian', sans colonne BDD) : affiche un bloc
// « responsable légal » quand l'âge saisi est inférieur au seuil (options_list).
// Paramétrable depuis « Gestion des champs » : actif / requis / âge / visibilité.
// ─────────────────────────────────────────────────────────────────────────
$formsGuardianSql = "Ajouter le champ 'guardian' (autorisation parentale) dans `forms`";
try {
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM `forms` WHERE `field_type` = 'guardian' OR `fields` = 'guardian_authorization'")->fetchColumn();
    if ($exists > 0) {
        $results[] = ['status' => 'skip', 'sql' => $formsGuardianSql, 'msg' => 'Existe déjà'];
    } else {
        $pdo->prepare(
            "INSERT INTO `forms`
              (`fields`, `label`, `field_type`, `bdd_column`, `active`, `required`,
               `is_locked`, `is_default`, `visible_public`, `visible_admin`, `visible_saisie`, `visible_qr`,
               `sort_order`, `options_list`, `encrypted`)
             VALUES ('guardian_authorization', 'Autorisation parentale (mineur)', 'guardian', NULL, 1, 1,
                     0, 1, 1, 1, 1, 1, 12, '18', 0)"
        )->execute();
        $results[] = ['status' => 'success', 'sql' => $formsGuardianSql, 'msg' => 'Champ ajouté'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $formsGuardianSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Champ « Date d'inscription » dans la gestion des champs (table `forms`).
// Pointe vers la colonne `date_inscription` (date réelle d'inscription, distincte de
// `created_at` = date d'ajout). Antidatable en Ajout multiple / Inscrit unique (admin)
// et mappable depuis un Excel. Vide → DEFAULT du jour. Jamais exposé hors admin/bulk.
// Géré explicitement à l'insertion (date_inscription est dans la liste réservée de
// getAllActiveFieldColumns) → pas de double insertion.
// NB : une version antérieure de cette migration créait ce champ sur `created_at` ;
// on le REPOINTE ici vers `date_inscription` (identifié par fields='inscription_date').
// ─────────────────────────────────────────────────────────────────────────
$formsDateInscSql = "Ajouter / repointer le champ 'Date d'inscription' (date_inscription) dans `forms`";
try {
    $existsField = (int) $pdo->query("SELECT COUNT(*) FROM `forms` WHERE `fields` = 'inscription_date'")->fetchColumn();
    if ($existsField > 0) {
        // Repointe l'ancien champ (qui pouvait viser created_at) vers date_inscription,
        // remet le bon libellé et force les visibilités sûres (admin/bulk uniquement).
        $st = $pdo->prepare("UPDATE `forms`
                          SET `bdd_column` = 'date_inscription', `label` = 'Date d''inscription',
                              `field_type` = 'date', `is_locked` = 0,
                              `visible_public` = 0, `visible_saisie` = 0, `visible_qr` = 0
                        WHERE `fields` = 'inscription_date'
                          AND NOT (`bdd_column` = 'date_inscription' AND `field_type` = 'date'
                                   AND `is_locked` = 0 AND `visible_public` = 0
                                   AND `visible_saisie` = 0 AND `visible_qr` = 0)");
        $st->execute();
        $n = $st->rowCount();
        $results[] = ['status' => $n > 0 ? 'success' : 'skip', 'sql' => $formsDateInscSql,
                      'msg' => $n > 0 ? 'Champ repointé vers date_inscription' : 'Déjà repointé'];
    } else {
        // is_locked=0 → l'admin gère actif/obligatoire/visible admin/bulk. public/saisie/QR=0
        // → jamais exposé hors admin. is_default=1 → pas de bouton « supprimer ».
        $pdo->prepare(
            "INSERT INTO `forms`
              (`fields`, `label`, `field_type`, `bdd_column`, `active`, `required`,
               `is_locked`, `is_default`, `visible_public`, `visible_admin`, `visible_saisie`, `visible_qr`,
               `visible_saisie_multiple`, `required_saisie_multiple`, `sort_order`, `options_list`, `encrypted`)
             VALUES ('inscription_date', 'Date d''inscription', 'date', 'date_inscription', 1, 0,
                     0, 1, 0, 1, 0, 0, 1, 0, 13, NULL, 0)"
        )->execute();
        $results[] = ['status' => 'success', 'sql' => $formsDateInscSql, 'msg' => 'Champ ajouté'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $formsDateInscSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Alias d'import AssoConnect : la colonne Excel « date de creation » alimente
// désormais `date_inscription` (date réelle d'inscription) et non plus `created_at`.
// ─────────────────────────────────────────────────────────────────────────
$importAliasSql = "Repointer l'alias d'import 'created_at' → 'date_inscription'";
try {
    $st = $pdo->prepare("UPDATE `import` SET `fields_bdd` = 'date_inscription' WHERE `fields_bdd` = 'created_at'");
    $st->execute();
    $n = $st->rowCount();
    $results[] = ['status' => $n > 0 ? 'success' : 'skip', 'sql' => $importAliasSql,
                  'msg' => $n > 0 ? 'OK' : 'Déjà repointé'];
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $importAliasSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Pré-cocher "Bulk visible" + "Bulk requis" pour les 5 champs essentiels du
// mode "Ajout multiple" : nom, prenom, email, entreprise, montant_du.
//   - nom, prenom, montant_du : affichés dans chaque carte "Personne #N"
//   - email, entreprise       : champs partagés dans l'en-tête bulk
//
// Deux blocs :
//   A) First-time : aucun champ encore bulk-visible → coche les 5 essentiels
//   B) Catch-up   : ancienne migration trop stricte → rattrape email,
//                   entreprise, montant_du si encore à 0. Idempotent au
//                   niveau SQL (WHERE visible_saisie_multiple = 0).
// ─────────────────────────────────────────────────────────────────────────
$bulkAutoCheckSql = "Pré-cocher Bulk visible/requis pour les 5 champs essentiels (nom, prenom, email, entreprise, montant_du)";
try {
    // Vérifie que la colonne existe (ALTER a réussi)
    $colCheck = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forms'
            AND COLUMN_NAME = 'visible_saisie_multiple'"
    )->fetchColumn();
    if (!$colCheck) {
        $results[] = ['status' => 'skip', 'sql' => $bulkAutoCheckSql, 'msg' => 'Colonne absente — ALTER non appliqué'];
    } else {
        // Visibilité bulk des 5 champs essentiels (idempotent : ne touche que ceux à 0).
        $visStmt = $pdo->prepare(
            "UPDATE `forms` SET `visible_saisie_multiple` = 1
              WHERE `bdd_column` IN ('nom', 'prenom', 'email', 'entreprise', 'montant_du')
                AND `visible_saisie_multiple` = 0"
        );
        $visStmt->execute();

        // Requis bulk : UNIQUEMENT nom + prénom. email / entreprise / montant_du sont
        // FACULTATIFS (particulier sans entreprise, inscrit sans email, montant auto-calculé).
        // → corrige aussi les installs où l'ancienne migration les avait rendus requis.
        // L'admin peut toujours rendre un champ requis via « Gestion des champs » (Bulk requis) ;
        // update.php étant supprimé après la mise à jour, ce réglage ne sera pas réécrasé.
        $reqStmt = $pdo->prepare("UPDATE `forms` SET `required_saisie_multiple` = 1
                        WHERE `bdd_column` IN ('nom', 'prenom') AND `required_saisie_multiple` = 0");
        $reqStmt->execute();
        $unreq = $pdo->prepare("UPDATE `forms` SET `required_saisie_multiple` = 0
                        WHERE `bdd_column` IN ('email', 'entreprise', 'montant_du') AND `required_saisie_multiple` = 1");
        $unreq->execute();
        $changed = $visStmt->rowCount() + $reqStmt->rowCount() + $unreq->rowCount();
        $results[] = ['status' => $changed > 0 ? 'success' : 'skip', 'sql' => $bulkAutoCheckSql,
                      'msg' => $changed > 0
                          ? ('Bulk : ' . $changed . ' champ(s) mis à jour (nom/prénom requis ; email/entreprise/montant facultatifs)')
                          : 'Déjà appliqué'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $bulkAutoCheckSql, 'msg' => $e->getMessage()];
}

/* ─────────────────────────────────────────────────────────────────────────
 * Migration des permissions content.* vers la granularité par page
 * (news/timeline/partners/albums).create/edit/delete
 *
 * Convertit chaque entrée content.create / content.edit / content.delete
 * trouvée en BDD en 4 entrées granulaires (une par page de contenu).
 * S'applique à :
 *   - users.permissions   (permissions personnalisées par utilisateur)
 *   - setting.role_permissions  (défauts par rôle)
 * ───────────────────────────────────────────────────────────────────────── */
function migrateContentPermissions(array $perms): array
{
    if (!isset($perms['actions']) || !is_array($perms['actions'])) return $perms;
    $map = [
        'content.create' => ['news.create','timeline.create','partners.create','albums.create'],
        'content.edit'   => ['news.edit','timeline.edit','partners.edit','albums.edit'],
        'content.delete' => ['news.delete','timeline.delete','partners.delete','albums.delete'],
    ];
    $newActions = [];
    foreach ($perms['actions'] as $a) {
        if (isset($map[$a])) {
            foreach ($map[$a] as $granular) $newActions[] = $granular;
        } else {
            $newActions[] = $a;
        }
    }
    $perms['actions'] = array_values(array_unique($newActions));
    return $perms;
}

// ─────────────────────────────────────────────────────────────────────────
// Migration : accueil_layout (nouveau format JSON)
// Convertit les anciennes colonnes accueil_custom_content / accueil_custom_position
// + accueil_news_before_partners en un layout JSON unique. Une fois la migration
// faite (accueil_layout != NULL), les colonnes legacy sont supprimées.
// ─────────────────────────────────────────────────────────────────────────
try {
    require_once __DIR__ . '/src/content/accueil_layout.php';
    $row = $pdo->query('SELECT * FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($row && empty($row['accueil_layout'])) {
        $layout = loadAccueilLayout($row); // gère la migration depuis legacy
        saveAccueilLayout($pdo, $layout);
        $results[] = ['status' => 'success', 'sql' => 'MIGRATE accueil_layout (depuis legacy custom_content)', 'msg' => 'Layout initialisé'];
    } else {
        $results[] = ['status' => 'skip', 'sql' => 'MIGRATE accueil_layout (depuis legacy custom_content)', 'msg' => 'Déjà initialisé'];
    }
} catch (\Throwable $e) {
    $results[] = ['status' => 'error', 'sql' => 'MIGRATE accueil_layout', 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Migration : persiste les sections pré-définies manquantes (start_point,
// newsletter, …) dans le layout. Sans ça, normalizeAccueilLayout() les ajoute
// "à la volée" à chaque chargement → elles restent des lignes transitoires et
// ne se comportent pas comme les autres sections dans l'éditeur. On les persiste
// ici (avec leur id déterministe row_predef_<type>) → vraies lignes de layout.
// ─────────────────────────────────────────────────────────────────────────
try {
    require_once __DIR__ . '/src/content/accueil_layout.php';
    $row = $pdo->query("SELECT accueil_layout, accueil_layout_draft FROM setting WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $spDone = 0;
    foreach (['accueil_layout', 'accueil_layout_draft'] as $col) {
        $raw = $row[$col] ?? null;
        if (empty($raw)) continue;                       // colonne vide → rien à migrer
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded)) continue;
        // normalizeAccueilLayout() ajoute toute section pré-définie manquante.
        // Si le nombre de lignes change, c'est qu'il en manquait → on persiste.
        $normalized = normalizeAccueilLayout($decoded);
        if (count($normalized) === count($decoded)) continue; // rien à ajouter
        $pdo->prepare("UPDATE setting SET `$col` = :l WHERE id = 1")
            ->execute(['l' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $spDone++;
    }
    $results[] = ['status' => $spDone > 0 ? 'success' : 'skip',
                  'sql' => 'MIGRATE layout : sections pré-définies manquantes',
                  'msg' => $spDone > 0 ? "$spDone colonne(s) mise(s) à jour" : 'Déjà à jour'];
} catch (\Throwable $e) {
    $results[] = ['status' => 'error', 'sql' => 'MIGRATE layout sections', 'msg' => $e->getMessage()];
}

// Suppression des colonnes obsolètes (après migration)
$dropLegacyAccueil = [
    "ALTER TABLE `setting` DROP COLUMN `accueil_custom_content`",
    "ALTER TABLE `setting` DROP COLUMN `accueil_custom_position`",
    "ALTER TABLE `setting` DROP COLUMN `accueil_news_before_partners`",
];
foreach ($dropLegacyAccueil as $sql) {
    try {
        $pdo->exec($sql);
        $results[] = ['status' => 'success', 'sql' => $sql, 'msg' => 'OK'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, "Can't DROP") || str_contains($msg, 'check that column/key exists')) {
            $results[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'Déjà supprimée'];
        } else {
            $results[] = ['status' => 'error', 'sql' => $sql, 'msg' => $msg];
        }
    }
}

// Migration users.permissions
try {
    $stmt = $pdo->query("SELECT id, permissions FROM users WHERE permissions IS NOT NULL AND permissions != ''");
    $migratedUsers = 0;
    foreach ($stmt as $row) {
        $perms = json_decode($row['permissions'], true);
        if (!is_array($perms)) continue;
        $had = json_encode($perms);
        $perms = migrateContentPermissions($perms);
        $now = json_encode($perms);
        if ($had !== $now) {
            $pdo->prepare("UPDATE users SET permissions = ? WHERE id = ?")->execute([$now, $row['id']]);
            $migratedUsers++;
        }
    }
    $results[] = ['status' => $migratedUsers > 0 ? 'success' : 'skip', 'sql' => 'MIGRATE users.permissions content.* → granular', 'msg' => "$migratedUsers utilisateur(s) migré(s)"];
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => 'MIGRATE users.permissions', 'msg' => $e->getMessage()];
}

// Migration login_banned_ips : la colonne d'IP doit s'appeler `ip`
//   Anciens schémas peuvent avoir `ip_address` → on rename
//   Si la table existe sans aucune colonne IP → on ajoute
try {
    $cols = $pdo->query("SHOW COLUMNS FROM login_banned_ips")->fetchAll(PDO::FETCH_COLUMN);
    $hasIp        = in_array('ip', $cols, true);
    $hasIpAddress = in_array('ip_address', $cols, true);
    if (!$hasIp && $hasIpAddress) {
        $pdo->exec("ALTER TABLE `login_banned_ips` CHANGE `ip_address` `ip` VARCHAR(45) NOT NULL");
        $results[] = ['status' => 'success', 'sql' => 'RENAME login_banned_ips.ip_address → ip', 'msg' => 'Colonne renommée'];
    } elseif (!$hasIp && !$hasIpAddress) {
        $pdo->exec("ALTER TABLE `login_banned_ips` ADD COLUMN `ip` VARCHAR(45) NOT NULL");
        $results[] = ['status' => 'success', 'sql' => 'ADD COLUMN login_banned_ips.ip', 'msg' => 'Colonne ajoutée'];
    } else {
        $results[] = ['status' => 'skip', 'sql' => 'login_banned_ips.ip', 'msg' => 'Déjà présente'];
    }

    // Vérifier aussi que la colonne expires_at existe
    $cols2 = $pdo->query("SHOW COLUMNS FROM login_banned_ips")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('expires_at', $cols2, true)) {
        $pdo->exec("ALTER TABLE `login_banned_ips` ADD COLUMN `expires_at` DATETIME NULL DEFAULT NULL");
        $results[] = ['status' => 'success', 'sql' => 'ADD COLUMN login_banned_ips.expires_at', 'msg' => 'Colonne ajoutée'];
    } else {
        $results[] = ['status' => 'skip', 'sql' => 'login_banned_ips.expires_at', 'msg' => 'Déjà présente'];
    }

    // Vérifier la UNIQUE KEY sur ip (évite les doublons)
    $idx = $pdo->query("SHOW INDEX FROM login_banned_ips WHERE Column_name = 'ip'")->fetchAll(PDO::FETCH_ASSOC);
    $hasUnique = false;
    foreach ($idx as $i) { if ((int)$i['Non_unique'] === 0) { $hasUnique = true; break; } }
    if (!$hasUnique) {
        // Avant d'ajouter UNIQUE, on déduplique
        $pdo->exec("DELETE t1 FROM login_banned_ips t1
                    INNER JOIN login_banned_ips t2
                    WHERE t1.id < t2.id AND t1.ip = t2.ip");
        $pdo->exec("ALTER TABLE `login_banned_ips` ADD UNIQUE KEY `idx_ip` (`ip`)");
        $results[] = ['status' => 'success', 'sql' => 'ADD UNIQUE KEY login_banned_ips.idx_ip', 'msg' => 'Index unique ajouté'];
    } else {
        $results[] = ['status' => 'skip', 'sql' => 'login_banned_ips.idx_ip', 'msg' => 'Déjà unique'];
    }
} catch (\Throwable $e) {
    $results[] = ['status' => 'error', 'sql' => 'login_banned_ips schema fix', 'msg' => $e->getMessage()];
}

// Migration setting.role_permissions
try {
    $row = $pdo->query("SELECT role_permissions FROM setting WHERE id = 1")->fetchColumn();
    if ($row) {
        $data = json_decode($row, true);
        if (is_array($data)) {
            $had = json_encode($data);
            foreach (['user','viewer','saisie'] as $r) {
                if (isset($data[$r])) {
                    $data[$r] = migrateContentPermissions($data[$r]);
                }
            }
            $now = json_encode($data);
            if ($had !== $now) {
                $pdo->prepare("UPDATE setting SET role_permissions = ? WHERE id = 1")->execute([$now]);
                $results[] = ['status' => 'success', 'sql' => 'MIGRATE setting.role_permissions content.* → granular', 'msg' => 'OK'];
            } else {
                $results[] = ['status' => 'skip', 'sql' => 'MIGRATE setting.role_permissions', 'msg' => 'Rien à migrer'];
            }
        }
    } else {
        $results[] = ['status' => 'skip', 'sql' => 'MIGRATE setting.role_permissions', 'msg' => 'Aucune conf à migrer'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => 'MIGRATE setting.role_permissions', 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Import auto : le token partagé passe de config/.env vers la base (géré depuis
// l'UI, plus aucune édition de fichier). On reprend l'éventuel SYNC_WORKER_TOKEN
// déjà présent dans .env pour ne PAS casser un worker déjà configuré ; sinon il
// sera auto-généré au premier affichage de l'onglet « Import auto ».
// ─────────────────────────────────────────────────────────────────────────
$syncTokenSql = "Reprendre SYNC_WORKER_TOKEN (.env) → sync_assoconnect.worker_token";
try {
    $colExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sync_assoconnect'
            AND COLUMN_NAME = 'worker_token'"
    )->fetchColumn();
    if ($colExists === 0) {
        $results[] = ['status' => 'skip', 'sql' => $syncTokenSql, 'msg' => 'Colonne absente — ALTER non appliqué'];
    } else {
        $cur    = $pdo->query("SELECT worker_token FROM sync_assoconnect WHERE id = 1")->fetchColumn();
        $envTok = trim((string) ($_ENV['SYNC_WORKER_TOKEN'] ?? getenv('SYNC_WORKER_TOKEN') ?: ''));
        if (!empty($cur)) {
            $results[] = ['status' => 'skip', 'sql' => $syncTokenSql, 'msg' => 'Token déjà en base'];
        } elseif ($envTok !== '') {
            $pdo->prepare("UPDATE sync_assoconnect SET worker_token = ? WHERE id = 1")->execute([$envTok]);
            $results[] = ['status' => 'success', 'sql' => $syncTokenSql, 'msg' => 'Token repris depuis .env'];
        } else {
            $results[] = ['status' => 'skip', 'sql' => $syncTokenSql, 'msg' => "Aucun token .env — auto-généré dans l'UI"];
        }
    }
} catch (\Throwable $e) {
    $results[] = ['status' => 'error', 'sql' => $syncTokenSql, 'msg' => $e->getMessage()];
}

/* ════════════════════════════════════════════════════════════════════════════
 * LOT 1 — ESPACE COUREUR : neuf tables indexées sur la CLÉ MÉTIER
 * ----------------------------------------------------------------------------
 * PRINCIPE : la table `registrations` n'est PAS modifiée, et l'archivage annuel
 * n'est pas touché. Les nouvelles tables désignent un coureur par le couple
 * `(annee, inscription_no)`.
 *
 * POURQUOI : la route `archive-current` crée `registrations_<année>`, y recopie
 * les lignes puis vide `registrations`. Les `id` techniques changent donc de
 * table tous les ans — une clé étrangère vers `registrations.id` casserait à
 * chaque archivage. « L'inscrit n°142 de l'édition 2026 » survit, lui.
 *
 * CONTREPARTIE ASSUMÉE : MySQL ne peut plus garantir qu'un résultat pointe vers
 * une inscription existante. C'est le rôle de update.php?tool=check-integrity.
 *
 * ORDRE IMPOSÉ : `participants` avant `participant_registrations` et
 * `participant_devices`, qui portent une clé étrangère vers elle. `editions`
 * en premier (son peuplement lit les tables d'archive). Le reste est indépendant.
 * ════════════════════════════════════════════════════════════════════════════ */


// ─────────────────────────────────────────────────────────────────────
// Les neuf tables. Commentaires par table :
//
// `editions` — configuration par année (date, distance, géo, horaires).
//   ⏱️ `heure_depart` est stockée EN UTC : c'est l'heure du coup de feu, donc
//   la référence de tous les temps calculés. En heure locale face à des
//   arrivées en UTC, tous les chronos seraient faux de deux heures.
//   Elle ne remplace pas `registrations_stats` : elle ajoute la configuration
//   que celle-ci ne porte pas. `registrations_stats` n'est pas modifiée.
//
// `participant_registrations` — LE LIEN, cœur du dispositif. Il remplace la
//   colonne `registrations.participant_id` que l'on ne crée pas.
//   L'index UNIQUE (annee, inscription_no) garantit qu'une inscription
//   appartient à UN compte au maximum : deux comptes ne pourront jamais
//   revendiquer le même coureur le jour de la course.
//   La clé étrangère vers `participants` est légitime : c'est une table que
//   l'on maîtrise et qui n'est jamais archivée.
//
// ⚠️ Deux hachages différents, volontairement — ne pas « harmoniser » :
//   • participant_auth_codes.code_hash → password_hash() : un code à 6
//     chiffres n'a que 10^6 combinaisons, il faut un hachage LENT. Recherche
//     par email_normalise (indexé), puis password_verify() sur la ligne.
//   • participant_devices.token_hash → SHA-256 : un token serveur porte 256
//     bits d'entropie, rien à forcer. Il faut un hachage RAPIDE et
//     déterministe, car la recherche se fait PAR LE HASH à chaque appel
//     d'API — avec password_hash(), l'index serait inutilisable.
//   Lent pour un secret faible, rapide pour un secret fort.
//
// `resultats.valide_par` référence `users.id` : l'administrateur qui a validé
//   ou corrigé un temps. Seule référence vers `users` de tout le lot, et elle
//   désigne un admin, jamais un coureur.
//
// `traces_gps.points` est un LONGBLOB : JSON compressé par gzencode. À 1000
//   coureurs × ~3600 points, le non-compressé pèserait plusieurs centaines de
//   Mo. Format documenté dans inc/api-doc.php.
//
// `detections` conserve TOUTES les détections brutes ; `retenue` marque celle
//   qui a produit le résultat. On n'en supprime jamais.
//
// ⏱️ Toutes les colonnes DATETIME(3) sont stockées EN UTC, sans exception.
// ─────────────────────────────────────────────────────────────────────
$lot1Tables = [
    // Notifications poussées vers l'application mobile.
    // Aucun destinataire nommé : une notification s'adresse à une ÉDITION, donc
    // à ses inscrits. Une liste de participants ferait de cette table un fichier
    // de ciblage — une donnée personnelle de plus à protéger et à purger, pour
    // un besoin que « tous les inscrits de l'année » couvre déjà.
    'app_notifications' =>
        "CREATE TABLE IF NOT EXISTS `app_notifications` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `annee` SMALLINT DEFAULT NULL,
          `type` ENUM('info','course','urgent') NOT NULL DEFAULT 'info',
          -- ⚠️ Le push est une ACTION, pas une propriété du message : voir
          -- install.php. `afficher_dans_app` dit si le message vit dans la
          -- boîte du coureur ; `envoye_at`/`envoye_a` tracent un envoi effectué.
          `afficher_dans_app` TINYINT(1) NOT NULL DEFAULT 1,
          `envoye_at` DATETIME DEFAULT NULL,
          `envoye_a` INT DEFAULT NULL,
          `titre` VARCHAR(120) NOT NULL,
          `message` TEXT NOT NULL,
          `publie_at` DATETIME DEFAULT NULL,
          `expire_at` DATETIME DEFAULT NULL,
          `epingle` TINYINT(1) NOT NULL DEFAULT 0,
          `active` TINYINT(1) NOT NULL DEFAULT 1,
          `cree_par` INT DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_diffusion` (`active`, `publie_at`, `expire_at`),
          INDEX `idx_annee` (`annee`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    /* ⚠️ `participant_notifications_masquees` et `participant_notifications_lues`
       ÉTAIENT ICI, ET C'ÉTAIT UN BUG. Leurs clés étrangères pointent vers
       `participants`, qui est créée PLUS BAS dans ce même tableau : MySQL
       refusait les deux CREATE avec « errno 150, Foreign key constraint is
       incorrectly formed », et les tables n'existaient sur AUCUN site migré.
       Personne ne le voyait — la migration signalait deux erreurs parmi cent
       soixante-dix-neuf lignes, et l'espace coureur ne savait simplement plus
       quels messages avaient été lus. Elles sont désormais en fin de tableau,
       après `participants`. Une installation neuve, elle, n'a jamais eu le
       problème : install.php crée `participants` en premier. */

    'editions' =>
        "CREATE TABLE IF NOT EXISTS `editions` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `annee` SMALLINT NOT NULL,
          `libelle` VARCHAR(120) NOT NULL,
          `date_course` DATE DEFAULT NULL,
          `distance_km` DECIMAL(5,2) DEFAULT NULL,
          `heure_depart` DATETIME DEFAULT NULL,
          `lat_depart` DECIMAL(10,7) DEFAULT NULL,
          `lon_depart` DECIMAL(10,7) DEFAULT NULL,
          `lat_arrivee` DECIMAL(10,7) DEFAULT NULL,
          `lon_arrivee` DECIMAL(10,7) DEFAULT NULL,
          `temps_min_plausible_s` INT DEFAULT NULL,
          `transferts_deadline` DATETIME DEFAULT NULL,
          -- ⏱️ Le top de départ RÉEL, en UTC. Vide tant que personne n'a appuyé.
          -- `heure_depart` est l'heure prévue ; celle-ci fait foi.
          `depart_reel_at` DATETIME(3) DEFAULT NULL,
          `is_active` TINYINT(1) NOT NULL DEFAULT 0,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `idx_annee` (`annee`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // Deux colonnes pour une seule adresse, et c'est nécessaire :
    //   • `email_hmac`    : HMAC-SHA256 de l'adresse en minuscules. Déterministe,
    //     donc INDEXABLE et UNIQUE — c'est par lui qu'on retrouve un compte à la
    //     connexion. Un HMAC seul ne suffirait pas : irréversible, on ne pourrait
    //     plus envoyer le code à 6 chiffres.
    //   • `email_chiffre` : chiffré par le MÊME mécanisme que registrations.email
    //     (AES-256-GCM, IV aléatoire). Nécessaire pour récupérer l'adresse en clair
    //     au moment de l'envoi. Un chiffrement seul ne suffirait pas : l'IV aléatoire
    //     rend toute recherche par égalité impossible.
    // La clé HMAC vit dans config/config.enc, JAMAIS en base — sinon un dump
    // compromis livre à la fois les empreintes et le moyen de les recalculer.
    'participants' =>
        "CREATE TABLE IF NOT EXISTS `participants` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `email_chiffre` TEXT NOT NULL,
          `email_hmac` CHAR(64) NOT NULL,
          `nom` VARCHAR(255) DEFAULT NULL,
          `prenom` VARCHAR(255) DEFAULT NULL,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `rgpd_consent_at` DATETIME DEFAULT NULL,
          `rgpd_consent_version` VARCHAR(20) DEFAULT NULL,
          `derniere_connexion` DATETIME DEFAULT NULL,
          `theme` ENUM('light','dark','system') NOT NULL DEFAULT 'light',
          `accent` VARCHAR(20) NOT NULL DEFAULT 'rose',
          `accent_custom` VARCHAR(7) DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `idx_email_hmac` (`email_hmac`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    'participant_registrations' =>
        "CREATE TABLE IF NOT EXISTS `participant_registrations` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `participant_id` INT NOT NULL,
          `annee` SMALLINT NOT NULL,
          `inscription_no` VARCHAR(50) NOT NULL,
          `revendique_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `origine` ENUM('email','transfert','admin') NOT NULL DEFAULT 'email',
          UNIQUE KEY `idx_inscription` (`annee`, `inscription_no`),
          INDEX `idx_participant` (`participant_id`),
          CONSTRAINT `fk_pr_participant` FOREIGN KEY (`participant_id`)
            REFERENCES `participants`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // `email_hmac` et non l'adresse : cette table journalise les tentatives
    // d'authentification, y compris pour des adresses qui ne correspondent à
    // aucun compte (anti-énumération du lot 2). Elle ne doit contenir aucune
    // adresse lisible.
    'participant_auth_codes' =>
        "CREATE TABLE IF NOT EXISTS `participant_auth_codes` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `email_hmac` CHAR(64) NOT NULL,
          `code_hash` VARCHAR(255) NOT NULL,
          `canal` ENUM('web','app') NOT NULL DEFAULT 'web',
          `tentatives` TINYINT NOT NULL DEFAULT 0,
          `consomme_at` DATETIME DEFAULT NULL,
          `expires_at` DATETIME NOT NULL,
          `ip` VARCHAR(45) DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_email_hmac` (`email_hmac`),
          INDEX `idx_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    'participant_devices' =>
        "CREATE TABLE IF NOT EXISTS `participant_devices` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `participant_id` INT NOT NULL,
          `token_hash` VARCHAR(255) NOT NULL,
          `type` ENUM('web','app') NOT NULL,
          `libelle` VARCHAR(120) DEFAULT NULL,
          `plateforme` VARCHAR(60) DEFAULT NULL,
          `modele` VARCHAR(120) DEFAULT NULL,
          `ip_creation` VARCHAR(45) DEFAULT NULL,
          `user_agent` VARCHAR(500) DEFAULT NULL,
          `derniere_utilisation` DATETIME DEFAULT NULL,
          `expires_at` DATETIME DEFAULT NULL,
          `revoque_at` DATETIME DEFAULT NULL,
          -- Jeton de notification poussée. Rangé ICI et non dans une table à
          -- part : révoquer un appareil coupe ses notifications sans une ligne
          -- de code de plus, l'envoi ne lisant que `revoque_at IS NULL`.
          `push_token` VARCHAR(255) DEFAULT NULL,
          `push_maj_at` DATETIME DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_participant` (`participant_id`),
          UNIQUE KEY `idx_token` (`token_hash`),
          INDEX `idx_expires` (`expires_at`),
          CONSTRAINT `fk_pd_participant` FOREIGN KEY (`participant_id`)
            REFERENCES `participants`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    'registration_transfers' =>
        "CREATE TABLE IF NOT EXISTS `registration_transfers` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `annee` SMALLINT NOT NULL,
          `inscription_no` VARCHAR(50) NOT NULL,
          `email_source` VARCHAR(255) NOT NULL,
          `email_cible` VARCHAR(255) NOT NULL,
          `token_hash` VARCHAR(255) NOT NULL,
          `statut` ENUM('en_attente','accepte','annule','expire') NOT NULL DEFAULT 'en_attente',
          `demande_par` INT DEFAULT NULL,
          `expires_at` DATETIME NOT NULL,
          `accepte_at` DATETIME DEFAULT NULL,
          `annule_at` DATETIME DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_inscription` (`annee`, `inscription_no`),
          INDEX `idx_statut` (`statut`),
          UNIQUE KEY `idx_token` (`token_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    'resultats' =>
        "CREATE TABLE IF NOT EXISTS `resultats` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `annee` SMALLINT NOT NULL,
          `inscription_no` VARCHAR(50) NOT NULL,
          `depart_at` DATETIME(3) DEFAULT NULL,
          `arrivee_at` DATETIME(3) DEFAULT NULL,
          `temps_s` DECIMAL(10,3) DEFAULT NULL,
          `methode` ENUM('beacon','gps_ligne','gps_extrapole','gps_distance','manuel','declaratif') DEFAULT NULL,
          `precision_s` INT DEFAULT NULL,
          `distance_m` INT DEFAULT NULL,
          `denivele_positif_m` INT DEFAULT NULL,
          `statut` ENUM('en_course','termine','abandon','non_partant','invalide') NOT NULL DEFAULT 'en_course',
          `valide_par` INT DEFAULT NULL,
          `commentaire` VARCHAR(255) DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY `idx_inscription` (`annee`, `inscription_no`),
          INDEX `idx_classement` (`annee`, `temps_s`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    'traces_gps' =>
        "CREATE TABLE IF NOT EXISTS `traces_gps` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `annee` SMALLINT NOT NULL,
          `inscription_no` VARCHAR(50) NOT NULL,
          `device_id` INT DEFAULT NULL,
          `source` ENUM('app','gpx_import') NOT NULL DEFAULT 'app',
          `points` LONGBLOB DEFAULT NULL,
          `nb_points` INT DEFAULT 0,
          `debut_at` DATETIME(3) DEFAULT NULL,
          `fin_at` DATETIME(3) DEFAULT NULL,
          `purge_at` DATE DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_inscription` (`annee`, `inscription_no`),
          INDEX `idx_purge` (`purge_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    'detections' =>
        "CREATE TABLE IF NOT EXISTS `detections` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `annee` SMALLINT NOT NULL,
          `inscription_no` VARCHAR(50) NOT NULL,
          `device_id` INT DEFAULT NULL,
          `type` ENUM('beacon','geofence','gps_ligne','manuel') NOT NULL,
          `point` ENUM('depart','arrivee') NOT NULL,
          `detecte_at` DATETIME(3) NOT NULL,
          `recu_at` DATETIME(3) DEFAULT NULL,
          `rssi_pic` SMALLINT DEFAULT NULL,
          `beacon_minor` SMALLINT DEFAULT NULL,
          `confiance` TINYINT DEFAULT NULL,
          `retenue` TINYINT(1) NOT NULL DEFAULT 0,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_inscription` (`annee`, `inscription_no`, `point`),
          -- ⚠️ CETTE CLÉ EST CE QUI REND LA RÉCEPTION IDEMPOTENTE.
          -- Le réseau tombera pendant la course : l'application renverra ses
          -- détections, parfois plusieurs fois. Sans cet index, un même passage
          -- devant la balise créerait dix lignes, et l'arbitrage porterait sur
          -- des doublons. Un SELECT préalable ne suffirait pas : deux envois
          -- simultanés passeraient tous les deux.
          UNIQUE KEY `idx_unicite` (`annee`, `inscription_no`, `type`, `point`, `detecte_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    /* ⚠️ EN DERNIER, ET L'ORDRE EST LA SEULE CHOSE QUI COMPTE ICI.
       Ces deux tables référencent `participants` et `app_notifications` par
       clé étrangère. MySQL exige que les deux existent DÉJÀ : placées plus
       haut — là où elles étaient — le CREATE échouait sur « errno 150 » et la
       table n'était jamais créée sur les sites migrés. Elles doivent donc venir
       après `participants`. Ne pas les remonter pour la lisibilité. */

    /* Messages écartés par un coureur de SA boîte — voir install.php pour
       le détail. Table ajoutée après coup : sans elle, une suppression
       faite sur le téléphone ne suivait pas sur le navigateur. */
    'participant_notifications_masquees' =>
        "CREATE TABLE IF NOT EXISTS `participant_notifications_masquees` (
          `participant_id` INT NOT NULL,
          `notification_id` INT NOT NULL,
          `masque_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`participant_id`, `notification_id`),
          CONSTRAINT `fk_pnm_participant` FOREIGN KEY (`participant_id`)
            REFERENCES `participants`(`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_pnm_notification` FOREIGN KEY (`notification_id`)
            REFERENCES `app_notifications`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    /* Messages LUS par un coureur — voir install.php pour le détail.
       Sans cette table, la pastille « non lus » de l'espace coureur ne peut
       rien compter : « masqué » et « lu » sont deux choses différentes, on
       écarte un message qu'on a lu comme un message qu'on n'a jamais ouvert. */
    'participant_notifications_lues' =>
        "CREATE TABLE IF NOT EXISTS `participant_notifications_lues` (
          `participant_id` INT NOT NULL,
          `notification_id` INT NOT NULL,
          `lu_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`participant_id`, `notification_id`),
          CONSTRAINT `fk_pnl_participant` FOREIGN KEY (`participant_id`)
            REFERENCES `participants`(`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_pnl_notification` FOREIGN KEY (`notification_id`)
            REFERENCES `app_notifications`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
];

$editionsCreee = false;
foreach ($lot1Tables as $table => $ddl) {
    $desc = "Créer la table `$table`";
    try {
        if ($tableExists($table)) {
            $results[] = ['status' => 'skip', 'sql' => $desc, 'msg' => 'Existe déjà'];
        } else {
            $pdo->exec($ddl);
            if ($table === 'editions') $editionsCreee = true;
            $results[] = ['status' => 'success', 'sql' => $desc, 'msg' => 'Table créée'];
        }
    } catch (PDOException $e) {
        $results[] = ['status' => 'error', 'sql' => $desc, 'msg' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────
// `participants.theme` — préférence d'affichage du coureur.
// Nécessaire EN PLUS du CREATE TABLE : sur une base où `participants` existe
// déjà, CREATE TABLE IF NOT EXISTS ne fait rien et la colonne manquerait.
// Aucun backfill : le DEFAULT 'light' remplit les lignes existantes.
// ─────────────────────────────────────────────────────────────────────
// Préférences d'affichage du coureur, attachées à SON compte : elles le suivent
// d'un appareil à l'autre, contrairement à un simple réglage de navigateur.
// L'accent par défaut est `rose`, celui du site.
foreach ([
    'theme'         => ["ENUM('light','dark','system') NOT NULL DEFAULT 'light'", 'derniere_connexion'],
    'accent'        => ["VARCHAR(20) NOT NULL DEFAULT 'rose'",                     'theme'],
    'accent_custom' => ['VARCHAR(7) DEFAULT NULL',                                 'accent'],
] as $col => [$ddl, $apres]) {
    $desc = "Ajouter la colonne `$col` dans `participants`";
    try {
        if (!$tableExists('participants')) {
            $results[] = ['status' => 'skip', 'sql' => $desc, 'msg' => 'Table absente'];
            continue;
        }
        $exists = (int) $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participants'
               AND COLUMN_NAME = " . $pdo->quote($col)
        )->fetchColumn();
        if ($exists > 0) {
            $results[] = ['status' => 'skip', 'sql' => $desc, 'msg' => 'Existe déjà'];
        } else {
            $pdo->exec("ALTER TABLE `participants` ADD COLUMN `$col` $ddl AFTER `$apres`");
            $results[] = ['status' => 'success', 'sql' => $desc, 'msg' => 'Colonne ajoutée'];
        }
    } catch (PDOException $e) {
        $results[] = ['status' => 'error', 'sql' => $desc, 'msg' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────
// Peuplement de `editions` : l'année en cours (is_active = 1) + une ligne
// par table d'archive détectée.
// ⚠️ UNIQUEMENT À LA CRÉATION DE LA TABLE. update.php est rejoué
// régulièrement ; réexécuter ce peuplement écraserait la date de course,
// la distance, les coordonnées et les horaires saisis à la main.
// ─────────────────────────────────────────────────────────────────────
$desc = "Peupler `editions` (année en cours + une ligne par archive)";
try {
    if (!$editionsCreee) {
        $results[] = ['status' => 'skip', 'sql' => $desc, 'msg' => 'Table déjà présente — peuplement non rejoué'];
    } else {
        $anneeCourante = (int) date('Y');

        // date_course reprise de `setting` seulement si elle tombe bien sur
        // l'année en cours : une date de l'an dernier ne décrit pas l'édition.
        $dateCourse = null;
        try {
            $dc = $pdo->query('SELECT date_course FROM setting WHERE id = 1 LIMIT 1')->fetchColumn();
            if (!empty($dc) && (int) substr((string) $dc, 0, 4) === $anneeCourante) {
                $dateCourse = substr((string) $dc, 0, 10);
            }
        } catch (\Throwable $e) { /* colonne absente : NULL */ }

        $ins = $pdo->prepare(
            'INSERT IGNORE INTO editions (annee, libelle, date_course, is_active) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$anneeCourante, 'Forbach en Rose ' . $anneeCourante, $dateCourse, 1]);
        $creees = [$anneeCourante];

        // Une édition par table d'archive registrations_AAAA (jamais active).
        $archives = $pdo->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME REGEXP '^registrations_[0-9]{4}$'"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($archives as $t) {
            $a = (int) substr($t, -4);
            if ($a < 1900 || $a > 2200 || $a === $anneeCourante) continue;
            $ins->execute([$a, 'Forbach en Rose ' . $a, null, 0]);
            $creees[] = $a;
        }

        sort($creees);
        $results[] = ['status' => 'success', 'sql' => $desc,
                      'msg' => count($creees) . ' édition(s) : ' . implode(', ', $creees)];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $desc, 'msg' => $e->getMessage()];
}


// ─────────────────────────────────────────────────────────────────────────
// LOT 1 — Clé HMAC des adresses des comptes coureurs (§ 1.2)
// Vit dans config/config.enc, JAMAIS en base : un dump compromis livrerait
// sinon à la fois les empreintes et le moyen de les recalculer.
// Créée UNE SEULE FOIS, si absente. La régénérer invaliderait toutes les
// recherches par email et couperait l'accès à tous les comptes existants.
// ─────────────────────────────────────────────────────────────────────────
$desc = "Générer la clé HMAC des emails (EMAIL_HMAC_KEY dans config.enc)";
try {
    $cfgHmac = FerSecureConfig::load();
    if (!empty($cfgHmac['EMAIL_HMAC_KEY'])) {
        $results[] = ['status' => 'skip', 'sql' => $desc, 'msg' => 'Clé déjà présente — jamais régénérée'];
    } else {
        $cfgHmac['EMAIL_HMAC_KEY'] = bin2hex(random_bytes(32));
        FerSecureConfig::write($cfgHmac);
        FerSecureConfig::exportToEnv($cfgHmac);   // prise d'effet immédiate
        $results[] = ['status' => 'success', 'sql' => $desc,
                      'msg' => 'Clé générée (32 octets). À sauvegarder avec config.enc : sa perte rend les comptes coureurs introuvables.'];
    }
} catch (\Throwable $e) {
    $results[] = ['status' => 'error', 'sql' => $desc, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// LOT 1 § 3 bis.1 — Réglages : colonnes de la table `setting`
//
// `setting` est une table à ligne unique, une colonne par réglage. La valeur
// par défaut est portée par le DEFAULT de la colonne, PAS par un UPDATE après
// création : sur une table déjà peuplée, ADD COLUMN … DEFAULT x remplit la
// ligne existante. C'est le comportement voulu, et il est idempotent par nature.
//
// ⚠️ Une colonne n'est créée que si elle est absente. update.php est rejoué à
// chaque mise à jour : un ALTER ou un UPDATE inconditionnel écraserait les
// valeurs modifiées par l'administrateur.
//
// Aucun écran de réglage dans ce lot : ces colonnes sont lues par les lots
// suivants, leur interface viendra avec les fonctionnalités qui les utilisent.
// ─────────────────────────────────────────────────────────────────────────
$lot1Settings = [
    // Lot 2 — authentification coureur par code à 6 chiffres
    'participant_code_ttl_min'             => "SMALLINT NOT NULL DEFAULT 15",
    'participant_code_max_tentatives'      => "TINYINT NOT NULL DEFAULT 5",
    'participant_code_max_par_email_15min' => "TINYINT NOT NULL DEFAULT 3",
    'participant_code_max_par_ip_heure'    => "TINYINT NOT NULL DEFAULT 10",
    'participant_web_remember_jours'       => "SMALLINT NOT NULL DEFAULT 30",
    'participant_rgpd_version'             => "VARCHAR(20) NOT NULL DEFAULT '1.0'",
    // Lot 4 — transferts d'inscription
    'transferts_deadline_defaut_h'         => "SMALLINT NOT NULL DEFAULT 24",
    'transferts_expiration_jours'          => "SMALLINT NOT NULL DEFAULT 7",
    // Lot 5 — API mobile
    'app_version_minimale'                 => "VARCHAR(20) NOT NULL DEFAULT '1.0.0'",
    'app_access_token_ttl_min'             => "SMALLINT NOT NULL DEFAULT 60",
    // Interrupteur de l'API mobile /api/mobile, distinct de celui de api/v1 : les
    // deux API n'ont ni le même public ni les mêmes risques, couper l'une ne
    // doit pas couper l'autre.
    // DÉFAUT 0 : après mise à jour, l'API mobile est FERMÉE tant qu'on ne l'a pas
    // activée dans Réglages → API. Un service qui s'ouvre tout seul lors d'une
    // migration est un service que personne n'a décidé d'ouvrir.
    //
    // ⚠️ Pas de « clé d'application » ici, et c'est délibéré : elle serait livrée
    // dans l'application installée sur chaque téléphone, donc lisible par
    // quiconque décompile le fichier. Publier un secret ne protège rien et donne
    // l'illusion du contraire. Ce qui protège les données, c'est le jeton
    // PERSONNEL de chaque coureur ; ce qui protège les envois de mail, c'est la
    // limitation de débit (participant_code_max_par_*).
    'api_v1_enabled'                       => "TINYINT(1) NOT NULL DEFAULT 0",
    // Lot 6 — page de téléchargement
    'app_store_url_ios'                    => "VARCHAR(255) NULL DEFAULT NULL",
    'app_store_url_android'                => "VARCHAR(255) NULL DEFAULT NULL",
    // Lot 7 — purges RGPD.
    // 0 = CONSERVATION ILLIMITÉE (et non « effacer tout de suite » : le sens
    // choisi va toujours dans celui de la préservation). Le but est de pouvoir
    // revoir son parcours d'une année sur l'autre. C'est tenable parce que le
    // suivi GPS exige un consentement EXPLICITE et que le coureur peut supprimer
    // ses traces lui-même à tout moment — ce sont ces deux garanties, et non une
    // durée courte, qui rendent la conservation acceptable. Elles doivent donc
    // figurer telles quelles dans la politique de confidentialité.
    'traces_gps_conservation_jours'        => "SMALLINT NOT NULL DEFAULT 0",
    'auth_codes_conservation_jours'        => "SMALLINT NOT NULL DEFAULT 30",
    // Un appareil révoqué n'a plus de jeton valide, mais sa ligne garde le
    // modèle du téléphone et l'IP de création — des données personnelles.
    'devices_revoques_jours'               => "SMALLINT NOT NULL DEFAULT 90",
    // Les transferts EN ATTENTE ne sont jamais purgés, quel que soit ce délai :
    // effacer une demande en cours la ferait disparaître sous le nez des deux
    // personnes concernées.
    'transferts_clos_jours'                => "SMALLINT NOT NULL DEFAULT 365",
    // Chronométrage — interrupteur unique, lu par chrono_actif().
    //
    // DÉFAUT 0, MÊME SUR UN SITE OÙ LE CHRONOMÉTRAGE TOURNAIT DÉJÀ. Avant cette
    // colonne, il n'y avait rien à activer : les écrans étaient toujours là,
    // vides le reste de l'année. Une migration ne peut pas deviner qu'on est en
    // période de course, et ouvrir la collecte de positions GPS « au cas où »
    // serait exactement l'inverse de ce que ce projet s'impose. On l'active
    // depuis l'écran Résultats, en un clic, quand on en a besoin.
    //
    // ⚠️ Désactivé ne veut PAS dire effacé : les temps (`resultats`) et les
    // traces (`traces_gps`) restent en base et réapparaissent à l'identique dès
    // la réactivation. Seules les purges effacent.
    'chrono_enabled'                       => "TINYINT(1) NOT NULL DEFAULT 0",
    // Notifications poussées vers l'application mobile. Actives par défaut :
    // contrairement au chronométrage, une notification ne collecte rien et ne
    // part que si quelqu'un en écrit une.
    'app_notifications_actives'            => "TINYINT(1) NOT NULL DEFAULT 1",
    // Réveil de l'application avant la course, en minutes (0 = aucun).
    'app_reveil_avant_min'                 => "SMALLINT NOT NULL DEFAULT 120",
    // Firebase — la seule voie pour faire sonner un téléphone.
    // ⚠️ `fcm_service_account` est une CLÉ PRIVÉE : stockée chiffrée, jamais
    // réaffichée en clair, jamais journalisée.
    'fcm_project_id'                       => "VARCHAR(120) DEFAULT NULL",
    'fcm_service_account'                  => "TEXT DEFAULT NULL",
    // Délai de grâce après l'heure prévue, avant que le calcul n'y retombe.
    'depart_grace_min'                     => "SMALLINT NOT NULL DEFAULT 10",
    // Espace coureur — interrupteur unique, lu par espace_coureur_actif().
    //
    // DÉFAUT 1, ET C'EST L'INVERSE DE `chrono_enabled`. Le chronométrage ouvre
    // une collecte de positions GPS : on ne l'active jamais à la place de
    // quelqu'un. L'espace coureur, lui, EXISTAIT DÉJÀ avant cette colonne — une
    // migration qui le fermerait couperait, sans que personne ne l'ait demandé,
    // l'accès des coureurs à leur QR code la veille de la course.
    //
    // ⚠️ Désactivé ne veut PAS dire effacé : comptes, inscriptions, appareils et
    // transferts restent en base et l'espace revient à l'identique dès la
    // réactivation. Seules les purges effacent.
    'espace_coureur_actif'                 => "TINYINT(1) NOT NULL DEFAULT 1",
];

foreach ($lot1Settings as $col => $ddl) {
    $desc = "Ajouter le réglage `setting`.`$col`";
    try {
        $exists = (int) $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'setting'
               AND COLUMN_NAME = " . $pdo->quote($col)
        )->fetchColumn();
        if ($exists > 0) {
            $results[] = ['status' => 'skip', 'sql' => $desc, 'msg' => 'Existe déjà'];
        } else {
            $pdo->exec("ALTER TABLE `setting` ADD COLUMN `$col` $ddl");
            $results[] = ['status' => 'success', 'sql' => $desc, 'msg' => 'Colonne ajoutée avec sa valeur par défaut'];
        }
    } catch (PDOException $e) {
        $results[] = ['status' => 'error', 'sql' => $desc, 'msg' => $e->getMessage()];
    }
}

/* ═══════════════════ CHRONOMÉTRAGE — réception des données de course ════
 *
 * Deux ajouts sur des tables du lot 1 déjà créées chez les sites migrés :
 * `CREATE TABLE IF NOT EXISTS` ne les aurait pas touchées.
 * ───────────────────────────────────────────────────────────────────────── */
$chronoAlters = [
    // Sans cette clé, un même passage devant la balise renvoyé trois fois par
    // une application qui a perdu le réseau créerait trois lignes, et
    // l'arbitrage porterait sur des doublons.
    ['detections', 'idx_unicite',
     'ALTER TABLE `detections` ADD UNIQUE KEY `idx_unicite`
        (`annee`, `inscription_no`, `type`, `point`, `detecte_at`)',
     'Index d\'unicité des détections (réception idempotente)'],
];

/* ═══════ Colonnes ajoutées à des tables qui existent peut-être déjà ═══════
 *
 * `CREATE TABLE IF NOT EXISTS` ne touche pas une table présente : ces colonnes
 * doivent donc être ajoutées séparément pour les bases déjà migrées.
 *
 * ⚠️ LA POSITION (`AFTER`) N'EST PAS COSMÉTIQUE. Les deux chemins d'installation
 * doivent produire le MÊME schéma, ordre des colonnes compris — c'est
 * exactement ce que compare docs/audit-bdd.php, et il a déjà refusé une colonne
 * posée au mauvais endroit.
 * ───────────────────────────────────────────────────────────────────────── */
$colonnesTardives = [
    // [table, colonne, définition, après, description]
    ['app_notifications', 'afficher_dans_app',
     "TINYINT(1) NOT NULL DEFAULT 1", 'type',
     'Notifications : affichage dans l\'application'],
    ['app_notifications', 'envoye_at',
     "DATETIME DEFAULT NULL", 'afficher_dans_app',
     'Notifications : date d\'envoi sur les téléphones'],
    ['app_notifications', 'envoye_a',
     "INT DEFAULT NULL", 'envoye_at',
     'Notifications : nombre d\'appareils touchés'],

    // Le top de départ réel — la pièce maîtresse du bouton START.
    ['editions', 'depart_reel_at',
     "DATETIME(3) DEFAULT NULL", 'transferts_deadline',
     'Éditions : instant réel du départ (UTC)'],

    // Jeton de notification poussée, porté par l'appareil : une révocation
    // coupe les notifications sans code supplémentaire.
    ['participant_devices', 'push_token',
     "VARCHAR(255) DEFAULT NULL", 'revoque_at',
     'Appareils : jeton de notification poussée'],
    ['participant_devices', 'push_maj_at',
     "DATETIME DEFAULT NULL", 'push_token',
     'Appareils : date du jeton de notification'],
];

foreach ($colonnesTardives as [$table, $colonne, $def, $apres, $desc]) {
    try {
        if (!updTableExists($pdo, $table)) {
            $results[] = ['status' => 'skip', 'sql' => $desc, 'msg' => 'Table absente'];
            continue;
        }
        $existe = (int) $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = " . $pdo->quote($table) . "
                AND COLUMN_NAME = " . $pdo->quote($colonne)
        )->fetchColumn();

        if ($existe > 0) {
            $results[] = ['status' => 'skip', 'sql' => $desc, 'msg' => 'Existe déjà'];
            continue;
        }
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$colonne` $def AFTER `$apres`");
        $results[] = ['status' => 'success', 'sql' => $desc, 'msg' => 'Colonne ajoutée'];
    } catch (PDOException $e) {
        $results[] = ['status' => 'error', 'sql' => $desc, 'msg' => $e->getMessage()];
    }
}

/* Colonnes retirées du schéma : on les supprime des bases qui les portent
 * encore, sinon les deux chemins d'installation divergent et l'audit refuse.
 * Sous condition d'existence — un DROP inconditionnel échouerait à chaque
 * passage sur une base neuve. */
foreach ([
    // Interrupteur de thème sombre jamais branché : ni écrit, ni lu. Le thème
    // sombre est piloté par ses couleurs dédiées et la préférence du navigateur.
    ['setting', 'theme_dark_enabled', 'Réglages : interrupteur de thème sombre inutilisé'],
] as [$tbl, $col, $desc]) {
    try {
        if (!updTableExists($pdo, $tbl)) continue;
        $existe = (int) $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = " . $pdo->quote($tbl) . "
                AND COLUMN_NAME = " . $pdo->quote($col)
        )->fetchColumn();
        if ($existe > 0) {
            $pdo->exec("ALTER TABLE `$tbl` DROP COLUMN `$col`");
            $results[] = ['status' => 'success', 'sql' => $desc, 'msg' => 'Colonne retirée'];
        }
    } catch (PDOException $e) {
        $results[] = ['status' => 'error', 'sql' => $desc, 'msg' => $e->getMessage()];
    }
}

/* `canal` a existé brièvement, puis a été remplacé par `afficher_dans_app` et
 * la trace d'envoi — le push est une action, pas une propriété du message. On
 * retire la colonne si elle traîne : la laisser ferait diverger les deux
 * chemins d'installation, ce que l'audit refuse. */
try {
    if (updTableExists($pdo, 'app_notifications')) {
        $vieux = (int) $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_notifications'
                AND COLUMN_NAME = 'canal'"
        )->fetchColumn();
        if ($vieux > 0) {
            $pdo->exec("ALTER TABLE `app_notifications` DROP COLUMN `canal`");
            $results[] = ['status' => 'success', 'sql' => 'Notifications : ancien « canal » retiré',
                          'msg' => 'Colonne supprimée'];
        }
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => 'Notifications : ancien « canal »',
                  'msg' => $e->getMessage()];
}
foreach ($chronoAlters as [$table, $index, $ddl, $desc]) {
    try {
        if (!updTableExists($pdo, $table)) {
            $results[] = ['status' => 'skip', 'sql' => $desc, 'msg' => 'Table absente'];
            continue;
        }
        $existe = (int) $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table) . "
                AND INDEX_NAME = " . $pdo->quote($index)
        )->fetchColumn();
        if ($existe > 0) {
            $results[] = ['status' => 'skip', 'sql' => $desc, 'msg' => 'Existe déjà'];
        } else {
            // ⚠️ Des doublons préexistants feraient échouer l'ajout de la clé.
            // On les retire d'abord, en gardant la ligne la plus ancienne (la
            // première reçue) : c'est celle qui a servi à tout arbitrage passé.
            $pdo->exec('DELETE d1 FROM `detections` d1
                        INNER JOIN `detections` d2
                        WHERE d1.id > d2.id
                          AND d1.annee = d2.annee AND d1.inscription_no = d2.inscription_no
                          AND d1.type = d2.type AND d1.point = d2.point
                          AND d1.detecte_at = d2.detecte_at');
            $pdo->exec($ddl);
            $results[] = ['status' => 'success', 'sql' => $desc, 'msg' => 'Index ajouté'];
        }
    } catch (PDOException $e) {
        $results[] = ['status' => 'error', 'sql' => $desc, 'msg' => $e->getMessage()];
    }
}

/* Consentement explicite au suivi GPS. Une trace dit où une personne se
   trouvait minute par minute : c'est la donnée la plus sensible du site, et
   elle ne s'enregistre pas parce que l'application l'a décidé. NULL = pas de
   consentement, donc pas de trace — le défaut le plus protecteur. */
$descConsent = 'Ajouter `participants`.`traces_consent_at` (consentement au suivi GPS)';
try {
    if (!updTableExists($pdo, 'participants')) {
        $results[] = ['status' => 'skip', 'sql' => $descConsent, 'msg' => 'Table absente'];
    } else {
        $existe = (int) $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participants'
                AND COLUMN_NAME = 'traces_consent_at'"
        )->fetchColumn();
        if ($existe > 0) {
            $results[] = ['status' => 'skip', 'sql' => $descConsent, 'msg' => 'Existe déjà'];
        } else {
            // AFTER `accent_custom` : c'est la position qu'a la colonne dans
            // install.php. Sans cette précision, une base installée à neuf et
            // une base migrée n'auraient pas le même ordre de colonnes — l'audit
            // le détecte, et c'est le genre d'écart qui finit par mordre lors
            // d'un INSERT sans liste de colonnes.
            $pdo->exec('ALTER TABLE `participants`
                        ADD COLUMN `traces_consent_at` DATETIME DEFAULT NULL AFTER `accent_custom`');
            $results[] = ['status' => 'success', 'sql' => $descConsent,
                          'msg' => 'Colonne ajoutée (NULL = aucun suivi GPS enregistré)'];
        }
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $descConsent, 'msg' => $e->getMessage()];
}

/* ═══════════════════ LOT 6 — section « app » du gabarit d'email ═════════
 *
 * Le gabarit d'email est stocké en JSON dans `setting`.`mail_template_config`.
 * Un site déjà en service a donc son propre `section_order` enregistré, où la
 * nouvelle section « app » n'existe pas — elle ne s'afficherait jamais.
 *
 * On l'insère JUSTE APRÈS le QR code : c'est l'endroit qui a du sens, le
 * destinataire vient de voir son billet. Et on ne touche à rien d'autre :
 * l'ordre choisi par l'administrateur pour les autres sections est le sien.
 * ───────────────────────────────────────────────────────────────────────── */
$descMtc = "Ajouter la section « espace coureur » au gabarit d'email";
try {
    $mtcJson = $pdo->query("SELECT mail_template_config FROM setting WHERE id = 1")->fetchColumn();
    $mtc     = ($mtcJson !== false && $mtcJson !== null && $mtcJson !== '')
                 ? json_decode((string) $mtcJson, true) : null;

    if (!is_array($mtc) || empty($mtc['section_order'])) {
        // Aucun gabarit personnalisé : le défaut du code contient déjà « app ».
        $results[] = ['status' => 'skip', 'sql' => $descMtc,
                      'msg' => 'Gabarit par défaut — la section y figure déjà'];
    } elseif (in_array('app', $mtc['section_order'], true)) {
        $results[] = ['status' => 'skip', 'sql' => $descMtc, 'msg' => 'Déjà présente'];
    } else {
        $ordre = $mtc['section_order'];
        $pos   = array_search('qrcode', $ordre, true);
        if ($pos === false) $ordre[] = 'app';                    // pas de QR : à la fin
        else                array_splice($ordre, $pos + 1, 0, 'app');
        $mtc['section_order'] = array_values($ordre);

        // Textes par défaut, seulement s'ils n'ont jamais été saisis.
        $mtc['texts'] = ($mtc['texts'] ?? []) + [
            'app_title' => 'Votre espace coureur',
            'app_text'  => "Retrouvez votre inscription, votre QR code et vos informations "
                         . "à tout moment. Connexion par simple code envoyé par email.",
        ];

        $pdo->prepare('UPDATE setting SET mail_template_config = ? WHERE id = 1')
            ->execute([json_encode($mtc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $results[] = ['status' => 'success', 'sql' => $descMtc,
                      'msg' => 'Insérée après le QR code — l\'ordre de vos autres sections est inchangé'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $descMtc, 'msg' => $e->getMessage()];
}

/* ═══════════════════ LOT 6 — questions de FAQ sur l'espace coureur ══════
 *
 * Identifiants FIXES à partir de 901 et INSERT IGNORE : rejouer la migration ne
 * crée pas de doublon. La plage 901+ n'entre pas en conflit avec les questions
 * créées par l'administration, numérotées à partir de 1.
 *
 * ⚠️ Une question supprimée par l'administration réapparaîtra ici au prochain
 * update.php. La DÉSACTIVER (active = 0) plutôt que la supprimer la fait
 * disparaître du site pour de bon — c'est dit dans le message de résultat.
 *
 * Les mêmes textes sont dans install.php (getDefaultInserts) pour une
 * installation neuve.
 * ───────────────────────────────────────────────────────────────────────── */
$descFaq = 'Ajouter les questions de FAQ sur l\'espace coureur';
try {
    $install = @file_get_contents(__DIR__ . '/install.php');
    // On extrait l'INSERT depuis install.php plutôt que de le recopier : deux
    // copies d'un même texte finissent toujours par diverger, et c'est la
    // version la moins relue qui part en production.
    if ($install !== false
        && preg_match('/("INSERT IGNORE INTO `chatbot_faq`.*?\(909,.*?909, 1\)")/s', $install, $mFaq)) {
        $sqlFaq = eval('return ' . $mFaq[1] . ';');
        $avant  = (int) $pdo->query('SELECT COUNT(*) FROM chatbot_faq WHERE id BETWEEN 901 AND 999')->fetchColumn();
        $pdo->exec($sqlFaq);
        $apres  = (int) $pdo->query('SELECT COUNT(*) FROM chatbot_faq WHERE id BETWEEN 901 AND 999')->fetchColumn();

        if ($apres > $avant) {
            $results[] = ['status' => 'success', 'sql' => $descFaq,
                          'msg' => ($apres - $avant) . ' question(s) ajoutée(s). Pour en retirer une '
                                 . 'définitivement, désactivez-la au lieu de la supprimer.'];
        } else {
            $results[] = ['status' => 'skip', 'sql' => $descFaq, 'msg' => 'Déjà présentes'];
        }
    } else {
        $results[] = ['status' => 'error', 'sql' => $descFaq,
                      'msg' => 'Textes introuvables dans install.php'];
    }
} catch (\Throwable $e) {
    $results[] = ['status' => 'error', 'sql' => $descFaq, 'msg' => $e->getMessage()];
}

/* ═══════════════════ LOT 7 — politique de confidentialité ═══════════════
 *
 * ⚠️ N'ÉCRASE JAMAIS UN TEXTE EXISTANT. Si l'association a déjà rédigé sa
 * politique — peut-être avec un juriste — la remplacer par un texte générique
 * serait une perte sèche, et personne ne s'en apercevrait avant qu'un visiteur
 * le signale. On ne remplit QUE si le champ est vide.
 *
 * Le texte proposé décrit les traitements RÉELLEMENT effectués par le code de
 * ce site, durées comprises. Il reste à faire relire : ce n'est pas un avis
 * juridique, c'est une description technique honnête, à compléter par
 * l'association (identité du responsable, adresse de contact, DPO éventuel).
 * ───────────────────────────────────────────────────────────────────────── */
$descRgpd = 'Proposer un texte de politique de confidentialité (si vide)';
try {
    $actuel = (string) $pdo->query("SELECT COALESCE(legal_privacy, '') FROM setting WHERE id = 1")->fetchColumn();

    if (trim(strip_tags($actuel)) !== '') {
        $results[] = ['status' => 'skip', 'sql' => $descRgpd,
                      'msg' => 'Un texte existe déjà — il n\'a pas été touché'];
    } else {
        $texte = <<<'HTML'
<h2>Qui traite vos données</h2>
<p>Les données collectées sur ce site le sont par l'association organisatrice de
Forbach en Rose, pour l'organisation de la course et le suivi de votre inscription.
<em>À compléter : identité complète de l'association et adresse de contact.</em></p>

<h2>Quelles données, et pourquoi</h2>
<table>
  <tr><th>Données</th><th>Pourquoi</th><th>Conservation</th></tr>
  <tr>
    <td>Nom, prénom, âge, sexe, ville, adresse email, téléphone, équipe</td>
    <td>Votre inscription à la course, la remise de votre t-shirt, et la
        communication liée à l'événement.</td>
    <td>Conservées avec les archives de l'association (obligations comptables).</td>
  </tr>
  <tr>
    <td>Compte de l'espace coureur (adresse email chiffrée, préférences d'affichage)</td>
    <td>Vous permettre de consulter et corriger votre inscription vous-même.</td>
    <td>Tant que vous conservez le compte. Vous pouvez le supprimer à tout moment.</td>
  </tr>
  <tr>
    <td>Codes de connexion à 6 chiffres</td>
    <td>Vous identifier sans mot de passe. Seule une empreinte est stockée,
        jamais votre adresse en clair.</td>
    <td>30 jours.</td>
  </tr>
  <tr>
    <td>Appareils de confiance (modèle, plateforme, adresse IP de connexion)</td>
    <td>Vous éviter de ressaisir un code à chaque visite, et vous permettre de
        déconnecter un appareil perdu.</td>
    <td>Jusqu'à révocation, puis 90 jours.</td>
  </tr>
  <tr>
    <td>Traces GPS et résultats de course</td>
    <td>Établir votre temps, si vous utilisez l'application mobile et que vous
        y consentez explicitement.</td>
    <td>Conservées d'une édition à l'autre, pour vous permettre de revoir vos
        parcours passés. <strong>Vous pouvez les supprimer vous-même à tout
        moment</strong> depuis « Mes résultats », et retirer votre autorisation
        quand vous le souhaitez.</td>
  </tr>
</table>

<h2>Comment vos données sont protégées</h2>
<ul>
  <li>Vos données personnelles sont <strong>chiffrées en base</strong> : une copie
      de la base sans la clé de chiffrement ne révèle rien.</li>
  <li>Les échanges avec le site se font uniquement en <strong>HTTPS</strong>.</li>
  <li>L'espace coureur et l'espace d'administration sont <strong>strictement
      séparés</strong> : un accès à l'un ne donne aucun droit sur l'autre.</li>
  <li>Aucun mot de passe n'est stocké pour l'espace coureur : la connexion se fait
      par un code temporaire envoyé à votre adresse.</li>
</ul>

<h2>Vos droits</h2>
<p>Vous pouvez à tout moment, depuis votre espace coureur :</p>
<ul>
  <li><strong>Consulter</strong> vos données et les <strong>exporter</strong>
      dans un fichier lisible ;</li>
  <li><strong>Corriger</strong> votre nom, votre prénom, votre âge, votre sexe et
      votre adresse email ;</li>
  <li><strong>Supprimer</strong> votre compte en ligne. Votre inscription à la
      course reste alors valable : c'est l'accès en ligne qui disparaît, pas
      votre participation.</li>
</ul>
<p>Pour toute autre demande, écrivez-nous. Vous pouvez également introduire une
réclamation auprès de la CNIL.</p>

<h2>Participants mineurs</h2>
<p>L'inscription d'un mineur est effectuée par un parent ou un représentant légal,
qui fournit son autorisation. L'espace coureur est alors rattaché à l'adresse
email du parent, qui garde le contrôle des données de l'enfant. Si l'inscription
doit être rattachée à une autre adresse, la fonction de transfert le permet.</p>

<h2>Sous-traitants</h2>
<p>Les emails sont expédiés via un fournisseur d'envoi. Le site est hébergé chez
un prestataire d'hébergement. <em>À compléter : noms des prestataires.</em></p>

<h2>Cookies</h2>
<p>Ce site n'utilise que des cookies nécessaires à son fonctionnement : maintien
de votre session, et mémorisation de votre appareil si vous demandez à rester
connecté. Aucun cookie publicitaire, aucun traceur tiers.</p>
HTML;
        $pdo->prepare('UPDATE setting SET legal_privacy = ? WHERE id = 1')->execute([$texte]);
        $results[] = ['status' => 'success', 'sql' => $descRgpd,
                      'msg' => 'Texte proposé — À RELIRE et compléter dans Réglages → Pages légales '
                             . '(identité de l\'association, prestataires)'];
    }
} catch (\Throwable $e) {
    $results[] = ['status' => 'error', 'sql' => $descRgpd, 'msg' => $e->getMessage()];
}

/* ═══════════════════ fin LOT 1 ══════════════════════════════════════════ */

$countOk   = count(array_filter($results, fn($r) => $r['status'] === 'success'));
$countSkip = count(array_filter($results, fn($r) => $r['status'] === 'skip'));
$countErr  = count(array_filter($results, fn($r) => $r['status'] === 'error'));
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mise à jour BDD — Forbach en Rose</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php include __DIR__ . '/src/partials/auth-head.php'; ?>
</head>
<body>
<div class="auth">
  <div class="auth-frame">
    <div class="auth-pane">
      <a class="brand" href="inc/dashboard.php">
        <?php if (file_exists(__DIR__ . '/files/_logos/logo_fer_rose.png')): ?>
          <img src="files/_logos/logo_fer_rose.png" alt="" style="height:32px;width:auto">
        <?php endif; ?>
        <span class="name">Forbach en Rose</span>
      </a>
      <div class="inner is-wide">
        <div class="oc-icon-area">
          <div class="oc-icon-circle">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
          </div>
          <h1 class="oc-title">Mise à jour de la base de données</h1>
          <p class="oc-subtitle"><?= count($migrations) ?> migration(s) traitée(s)</p>
        </div>

  <div class="update-body">
    <div class="summary">
      <div class="summary-item summary-ok">
        <span class="num"><?= $countOk ?></span> Appliquée(s)
      </div>
      <div class="summary-item summary-skip">
        <span class="num"><?= $countSkip ?></span> Ignorée(s)
      </div>
      <div class="summary-item summary-err">
        <span class="num"><?= $countErr ?></span> Erreur(s)
      </div>
    </div>

    <?php
      // Détail replié par défaut (la liste est devenue très longue) —
      // déplié automatiquement s'il y a au moins une erreur.
      $updShowDetails = $countErr > 0;
    ?>
    <!-- Même rangée flex que .summary (3 colonnes égales, même gap) : le bouton
         occupe TOUTE la colonne du milieu → mêmes bords gauche/droite que la
         tuile « Ignorées » -->
    <div style="display:flex;gap:var(--sp-3);margin:14px 0 6px;">
      <div style="flex:1"></div>
      <button type="button" id="updToggleDetails" class="oc-btn-secondary" style="flex:1;min-width:0;white-space:nowrap;box-sizing:border-box;">
        <i class="bi bi-list-ul"></i> <span><?= $updShowDetails ? 'Masquer' : 'Détails (' . count($results) . ')' ?></span>
      </button>
      <div style="flex:1"></div>
    </div>

    <ul class="migration-list" id="updMigrationList"<?= $updShowDetails ? '' : ' hidden' ?>>
      <?php foreach ($results as $r): ?>
      <li class="migration-item">
        <div class="migration-icon <?= $r['status'] === 'success' ? 'icon-success' : ($r['status'] === 'skip' ? 'icon-skip' : 'icon-error') ?>">
          <i class="bi <?= $r['status'] === 'success' ? 'bi-check-lg' : ($r['status'] === 'skip' ? 'bi-dash-lg' : 'bi-x-lg') ?>"></i>
        </div>
        <div>
          <div class="migration-msg"><?= htmlspecialchars($r['msg']) ?></div>
          <div class="migration-sql"><?= htmlspecialchars($r['sql']) ?></div>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>

    <script nonce="<?= htmlspecialchars($GLOBALS['csp_nonce'] ?? '') ?>">
    (function () {
      var btn = document.getElementById('updToggleDetails');
      var list = document.getElementById('updMigrationList');
      if (!btn || !list) return;
      var lbl = btn.querySelector('span');
      btn.addEventListener('click', function () {
        list.hidden = !list.hidden;
        lbl.textContent = list.hidden ? 'Détails (<?= count($results) ?>)' : 'Masquer';
        if (!list.hidden) list.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    })();
    </script>
  </div>

  <div class="update-footer">
    <p style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">
      <a href="update.php?tool=repair-dates" class="oc-btn-secondary" style="width:auto;text-decoration:none">
        <i class="bi bi-calendar-check"></i> Réparer les dates d'inscription (jour/mois inversés)
      </a>
      <!-- Contrepartie de l'absence de clés étrangères vers `registrations` :
           MySQL ne peut pas garantir l'intégrité des clés métier, cet outil si. -->
      <a href="update.php?tool=check-integrity" class="oc-btn-secondary" style="width:auto;text-decoration:none">
        <i class="bi bi-shield-check"></i> Contrôle d'intégrité (clés métier)
      </a>
    </p>

    <!-- Auto-suppression : proposée maintenant que la mise à jour est terminée.
         « Oui » supprime le fichier (POST + CSRF) ; « Non » retourne au dashboard
         sans rien faire. -->
    <div class="upd-danger-box">
      <div class="upd-danger-title">
        <i class="bi bi-shield-lock"></i>
        Voulez-vous supprimer <code>update.php</code> ?
      </div>
      <div class="upd-danger-text">
        La mise à jour est terminée. Par sécurité, il est recommandé de supprimer ce
        fichier du serveur. Vous pourrez le réinstaller lors de la prochaine mise à jour.
      </div>
      <div class="upd-actions">
        <?php /* ⚠️ PAS D'onsubmit="return confirm(…)" ICI. La CSP du site
                 (src/core/config.php) autorise script-src 'self' 'nonce-…'
                 SANS 'unsafe-inline' : l'attribut est bloqué par le
                 navigateur, sans erreur visible. La confirmation ne
                 s'affichait donc jamais et le fichier partait au premier
                 clic. confirm-script.php, qui gère data-confirm partout
                 ailleurs, n'est inclus que par admin-footer.php — cette page
                 ne l'a pas. D'où l'écouteur porteur du nonce, juste en
                 dessous, qui est la seule forme que la CSP laisse passer. */ ?>
        <form method="post" action="update.php" style="margin:0;" id="updDeleteSelf">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_self">
          <button type="submit" class="upd-btn-danger">
            <i class="bi bi-trash3"></i> Oui, supprimer update.php
          </button>
        </form>
        <script nonce="<?= htmlspecialchars($GLOBALS['csp_nonce'] ?? '') ?>">
        (function () {
          var f = document.getElementById('updDeleteSelf');
          if (!f) return;
          f.addEventListener('submit', function (e) {
            if (!window.confirm('Supprimer définitivement update.php du serveur ?')) e.preventDefault();
          });
        })();
        </script>
        <a href="inc/dashboard.php" class="oc-btn-secondary" style="width:auto;text-decoration:none">
          <i class="bi bi-x-lg"></i> Non, garder le fichier
        </a>
      </div>
    </div>

    <p style="margin-top:16px"><a href="inc/dashboard.php" class="oc-back"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:15px;height:15px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> Retour au tableau de bord</a></p>
  </div>

      </div><!-- /inner -->
    </div><!-- /auth-pane -->
    <?php include __DIR__ . '/src/partials/auth-art.php'; ?>
  </div><!-- /auth-frame -->
</div><!-- /auth -->
</body>
</html>
