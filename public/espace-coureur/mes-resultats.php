<?php
/**
 * mes-resultats.php — Mes résultats.
 *
 * ⚠️ EMPLACEMENT PRÉPARÉ, VOLONTAIREMENT VIDE. Le chronométrage sera alimenté
 * par l'application mobile ; la carte de trace GPS, le classement, le profil
 * altimétrique et l'export sont explicitement hors périmètre de ce lot.
 *
 * La page interroge déjà `resultats` : le jour où la table sera alimentée,
 * l'affichage suivra sans changer la structure. Les colonnes `methode` et
 * `precision_s` sont lues dès maintenant — un temps extrapolé au GPS ne devra
 * JAMAIS être présenté comme équivalent à un temps beacon.
 */
define('FER_SESSION_COUREUR', true);
require '../../src/core/config.php';
checkMaintenance();
require_once '../../src/security/csrf.php';
require_once '../../src/auth/participant_auth.php';

pauth_require($pdo, 'mes-resultats.php');

$inscriptions = pauth_registrations($pdo, pauth_id());

/* Résultats éventuels, par clé métier. Aucun aujourd'hui : la requête est là
   pour que la page soit juste dès le premier enregistrement produit. */
$resultats = [];
if ($inscriptions) {
    $conds = [];
    $args  = [];
    foreach ($inscriptions as $r) {
        $conds[] = '(annee = ? AND inscription_no = ?)';
        $args[]  = (int) $r['annee'];
        $args[]  = (string) $r['inscription_no'];
    }
    try {
        $st = $pdo->prepare('SELECT * FROM resultats WHERE ' . implode(' OR ', $conds));
        $st->execute($args);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $res) {
            $resultats[$res['annee'] . '|' . $res['inscription_no']] = $res;
        }
    } catch (\Throwable $e) { /* table absente : rien à afficher */ }
}

/* ── Consentement au suivi GPS ───────────────────────────────────────────────
 * Le retrait vaut pour l'AVENIR : il n'efface pas les traces déjà enregistrées.
 * D'où un second bouton, distinct, pour les supprimer — mélanger les deux
 * laisserait croire qu'un simple retrait suffit à tout effacer. */
/* ⚠️ TOUT CE BLOC TOLÈRE L'ABSENCE DES COLONNES ET TABLES DU CHRONOMÉTRAGE.
   Sur un site dont la migration n'a pas encore été jouée, `traces_consent_at`
   et `traces_gps` n'existent pas. Sans ces gardes, la page meurt en erreur 500 :
   le coureur voit une page blanche et n'a aucun moyen de comprendre. Ici, la
   carte du suivi GPS disparaît simplement, et le reste de la page fonctionne. */
$ecMsg = '';
$ecDispo = true;   // le suivi GPS est-il installé sur ce site ?

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (isset($_POST['consent'])) {
        $accord = $_POST['consent'] === '1';
        try {
            $pdo->prepare('UPDATE participants SET traces_consent_at = ' . ($accord ? 'NOW()' : 'NULL')
                        . ' WHERE id = ?')->execute([pauth_id()]);
            $ecMsg = $accord
                ? 'Suivi GPS autorisé. Vous pouvez le retirer à tout moment.'
                : 'Autorisation retirée. Aucune nouvelle trace ne sera enregistrée.';
        } catch (\Throwable $e) {
            error_log('[EC] consentement GPS : ' . $e->getMessage());
            $ecDispo = false;
        }
    } elseif (isset($_POST['supprimer_traces']) && $inscriptions) {
        $n = 0;
        try {
            foreach ($inscriptions as $r) {
                $st = $pdo->prepare('DELETE FROM traces_gps WHERE annee = ? AND inscription_no = ?');
                $st->execute([(int) $r['annee'], (string) $r['inscription_no']]);
                $n += $st->rowCount();
            }
            $ecMsg = $n > 0 ? "$n trace(s) supprimée(s) définitivement." : 'Aucune trace à supprimer.';
        } catch (\Throwable $e) {
            error_log('[EC] suppression traces : ' . $e->getMessage());
            $ecDispo = false;
        }
    }
}

$ecConsent = null;
try {
    $st = $pdo->prepare('SELECT traces_consent_at FROM participants WHERE id = ?');
    $st->execute([pauth_id()]);
    $ecConsent = $st->fetchColumn() ?: null;
} catch (\Throwable $e) {
    $ecDispo = false;   // colonne absente : on masquera la carte
}

$ecNbTraces = 0;
foreach ($inscriptions as $r) {
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM traces_gps WHERE annee = ? AND inscription_no = ?');
        $st->execute([(int) $r['annee'], (string) $r['inscription_no']]);
        $ecNbTraces += (int) $st->fetchColumn();
    } catch (\Throwable $e) { /* table absente */ }
}
$ecJoursTraces = (int) ($pdo->query('SELECT traces_gps_conservation_jours FROM setting WHERE id = 1')
                            ->fetchColumn() ?: 400);

/* Le chronométrage est « actif » dès qu'un résultat porte un temps : inutile
   d'afficher « pas encore actif » à quelqu'un qui a déjà son chrono sous les yeux. */
$ecChronoActif = false;
foreach ($resultats as $res) {
    if ($res['temps_s'] !== null) { $ecChronoActif = true; break; }
}

/** Libellé honnête de la méthode de chronométrage. */
function ec_methode(?string $m): string
{
    return match ($m) {
        'beacon'        => 'Balise à la ligne — précision maximale',
        'gps_ligne'     => 'GPS au passage de la ligne',
        'gps_extrapole' => 'GPS extrapolé — temps approché',
        'gps_distance'  => 'GPS par la distance parcourue — temps approché',
        'manuel'        => "Saisi par l'organisation",
        'declaratif'    => 'Déclaré par le coureur',
        default         => 'Méthode non précisée',
    };
}

$ecTitre    = 'Mes résultats';
$ecSurtitre = 'Vos temps, édition par édition';

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Mes résultats</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php include __DIR__ . '/_styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_layout-haut.php'; ?>

  <?php if (!$inscriptions): ?>
    <section class="card">
      <header>
        <div class="iconwell"><i class="bi bi-stopwatch"></i></div>
        <h2>Rien à afficher</h2>
      </header>
      <div class="empty">
        <p>Aucune inscription n'est rattachée à ce compte, il n'y a donc pas de résultat.</p>
      </div>
    </section>
  <?php else: ?>
    <section class="card">
      <header>
        <div class="iconwell"><i class="bi bi-stopwatch"></i></div>
        <h2>Vos éditions</h2>
      </header>

      <div class="rows">
        <?php foreach ($inscriptions as $r): ?>
          <?php $res = $resultats[$r['annee'] . '|' . $r['inscription_no']] ?? null; ?>
          <div class="row">
            <div class="grow">
              <div class="title">Édition <?= (int) $r['annee'] ?></div>
              <div class="sub">
                <?= $h(trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? ''))) ?>
                · n° <span class="ec-mono"><?= $h($r['inscription_no']) ?></span>
              </div>
            </div>
            <?php if ($res !== null && $res['statut'] === 'invalide'): ?>
              <?php /* Un temps aberrant n'est PAS affiché comme un temps. Le
                       masquer sans rien dire laisserait croire à un oubli ; le
                       publier ferait passer une anomalie pour un résultat. */ ?>
              <span class="pill is-danger" title="<?= $h($res['commentaire'] ?? '') ?>">
                À vérifier
              </span>
            <?php elseif ($res !== null && $res['statut'] === 'abandon'): ?>
              <span class="pill no-dot">Abandon</span>
            <?php elseif ($res !== null && $res['statut'] === 'non_partant'): ?>
              <span class="pill no-dot">Non partant</span>
            <?php elseif ($res !== null && $res['statut'] === 'en_course' && $res['depart_at'] !== null): ?>
              <span class="pill is-ok">En course</span>
            <?php elseif ($res === null || $res['temps_s'] === null): ?>
              <span class="pill is-warn">Chronométrage à venir</span>
            <?php else: ?>
              <?php
                $s = (float) $res['temps_s'];
                $chrono = sprintf('%d:%02d:%02d', (int) ($s / 3600), (int) ($s / 60) % 60, (int) $s % 60);
              ?>
              <div class="stat" style="align-items:flex-end">
                <span class="value"><?= $h($chrono) ?></span>
                <?php /* La méthode et la précision accompagnent TOUJOURS le temps :
                         un temps extrapolé affiché nu passerait pour une mesure. */ ?>
                <span class="delta">
                  <?= $h(ec_methode($res['methode'])) ?>
                  <?php if ($res['precision_s'] !== null): ?> · ±<?= (int) $res['precision_s'] ?> s<?php endif; ?>
                </span>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <?php /* ── Consentement au suivi GPS ────────────────────────────────────
             La trace GPS dit où vous vous trouviez minute par minute. Elle ne
             s'enregistre QUE si vous l'avez explicitement autorisée, et le
             retrait vaut pour l'avenir : les traces déjà enregistrées se
             suppriment ici, en un clic, sans avoir à écrire à personne. */ ?>
    <section class="card">
      <header>
        <div class="iconwell"><i class="bi bi-geo-alt"></i></div>
        <h2>Suivi GPS pendant la course</h2>
        <?php if ($ecConsent !== null): ?>
          <span class="pill is-ok">autorisé</span>
        <?php else: ?>
          <span class="pill no-dot">non autorisé</span>
        <?php endif; ?>
      </header>

      <?php if ($ecMsg !== ''): ?>
        <div class="alert is-ok"><i class="bi bi-check-circle"></i> <?= $h($ecMsg) ?></div>
      <?php endif; ?>

      <p style="font-size:var(--fs-small);color:var(--ink-dim);margin:0">
        Si vous l'autorisez, l'application enregistre votre position pendant la course
        pour établir votre temps même en cas de panne de balise, et vous montrer votre
        parcours. <strong>C'est la donnée la plus sensible du site</strong> : elle dit où
        vous étiez, minute par minute. Sans votre accord, rien n'est enregistré.
      </p>

      <form method="post">
        <?= csrf_field() ?>
        <div class="row-actions" style="margin-top:var(--sp-4)">
          <?php if ($ecConsent !== null): ?>
            <button class="btn" type="submit" name="consent" value="0">
              <i class="bi bi-x-circle"></i> Retirer mon autorisation
            </button>
            <button class="btn btn-danger" type="submit" name="supprimer_traces" value="1"
                    onclick="return confirm('Supprimer définitivement toutes vos traces GPS enregistrées ?');">
              <i class="bi bi-trash3"></i> Supprimer mes traces (<?= (int) $ecNbTraces ?>)
            </button>
          <?php else: ?>
            <button class="btn btn-primary" type="submit" name="consent" value="1">
              <i class="bi bi-check2"></i> Autoriser le suivi GPS
            </button>
          <?php endif; ?>
        </div>
      </form>

      <p style="font-size:var(--fs-micro);color:var(--ink-faint);margin:0">
        <i class="bi bi-hourglass me-1"></i>
        Les traces sont effacées automatiquement au bout de
        <?= (int) $ecJoursTraces ?> jours, même si vous ne faites rien.
      </p>
    </section>

    <?php if (!$ecChronoActif): ?>
      <div class="alert">
        <i class="bi bi-cone-striped"></i>
        <strong>Le chronométrage n'est pas encore actif.</strong>
        Il arrivera avec l'application mobile. Vous retrouverez alors ici votre temps,
        la façon dont il a été mesuré, et le tracé de votre parcours.
      </div>
    <?php endif; ?>
  <?php endif; ?>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
