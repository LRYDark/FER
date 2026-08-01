<?php
/**
 * mes-resultats.php — Mes résultats.
 *
 * ⚠️ CETTE PAGE N'EXISTE QUE SI LE CHRONOMÉTRAGE EST OUVERT (chrono_actif()).
 * Hors période de course, elle affiche une explication et rien d'autre — pas de
 * chrono vide, pas de demande d'autorisation GPS. Le menu la masque en même
 * temps : les deux lisent le même interrupteur, réglé depuis l'écran Résultats
 * de l'administration.
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
require_once '../../src/content/chrono.php';        // chrono_actif()

pauth_require($pdo, 'mes-resultats.php');

/* ── Chronométrage fermé ─────────────────────────────────────────────────────
 * On sort AVANT toute requête et avant le traitement du POST : tant que le
 * chronométrage est fermé, ni consentement GPS ni suppression de traces ne
 * doivent pouvoir passer par ici, même avec un formulaire rejoué.
 *
 * Une page explicite plutôt qu'une redirection ou un 404 : le lien est peut-être
 * dans un favori ou dans un ancien mail. « Rien à voir ici pour l'instant » se
 * comprend ; « page introuvable » fait croire à une panne.
 * ──────────────────────────────────────────────────────────────────────────── */
if (!chrono_actif($pdo)) {
    $ecChronoOuvert = false;
    $ecTitre    = 'Mes résultats';
    $ecSurtitre = 'Pas encore ouvert';
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
      <section class="card">
        <header>
          <div class="iconwell"><i class="bi bi-stopwatch"></i></div>
          <h2>Le chronométrage n'est pas ouvert</h2>
        </header>
        <div class="empty">
          <p>Les temps et le suivi du parcours ne sont proposés qu'autour de la course.
             En dehors de cette période, votre espace sert à suivre vos inscriptions.</p>
          <?php /* On promet ce qui est vrai : les résultats passés ne sont pas
                   perdus, ils sont seulement masqués tant que la page est fermée. */ ?>
          <p>Si vous avez déjà couru, <strong>vos temps sont conservés</strong> : vous les
             retrouverez ici dès la réouverture.</p>
          <div class="row-actions">
            <a class="btn btn-primary" href="index.php"><i class="bi bi-list-check"></i> Mes inscriptions</a>
            <a class="btn" href="../faq.php"><i class="bi bi-question-circle"></i> Questions fréquentes</a>
          </div>
        </div>
      </section>
    <?php include __DIR__ . '/_layout-bas.php'; ?>
    </body>
    </html>
    <?php
    exit;
}
$ecChronoOuvert = true;

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
/* ⚠️ PAS de `?: 400` ici. L'opérateur ?: teste la FAUSSETÉ, pas l'absence — et
   0 est faux. Le réglage « conservation illimitée » (0) retombait donc sur 400,
   et la page annonçait un effacement qui n'avait pas lieu. C'est exactement le
   genre de fausse déclaration que ce projet s'interdit.
   On lit la valeur telle quelle ; le repli ne joue que si la colonne manque. */
$ecJoursTraces = 400;
try {
    $v = $pdo->query('SELECT traces_gps_conservation_jours FROM setting WHERE id = 1')->fetchColumn();
    if ($v !== false && $v !== null) $ecJoursTraces = (int) $v;
} catch (\Throwable $e) { /* colonne absente : on garde le repli */ }

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

/* ── Le suivi GPS passe dans une fenêtre, ouverte depuis la barre du titre ────
 * Ce n'est pas un résultat : c'est un réglage, et il tenait autant de place que
 * les temps eux-mêmes. La page dit maintenant une seule chose — vos temps — et
 * le réglage s'ouvre quand on le demande.
 *
 * L'état (« autorisé » / « non autorisé ») reste sur le BOUTON : il doit se lire
 * sans avoir à ouvrir quoi que ce soit, sinon on ne sait plus si on est suivi.
 *
 * Deux conditions pour l'afficher : $ecDispo (les colonnes du chronométrage
 * existent) et $inscriptions (sans inscription, il n'y a rien à suivre — et la
 * fenêtre elle-même n'est pas rendue dans ce cas).
 * ──────────────────────────────────────────────────────────────────────────── */
$ecGpsAffiche    = $ecDispo && $inscriptions;
$ecTopbarActions = $ecGpsAffiche
    ? '<button class="btn" type="button" id="ecGpsOuvrir"'
    . ' aria-haspopup="dialog" aria-controls="ecGpsModal">'
    . '<i class="bi bi-geo-alt"></i> Suivi GPS '
    . ($ecConsent !== null
        ? '<span class="pill is-ok">autorisé</span>'
        : '<span class="pill no-dot">non autorisé</span>')
    . '</button>'
    : '';
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
             suppriment ici, en un clic, sans avoir à écrire à personne.

             Ouvert par le bouton « Suivi GPS » de la barre du titre.
             Le contenu est celui de l'ancienne carte, à l'identique : mêmes
             textes, mêmes boutons, mêmes garde-fous. */ ?>
    <?php if ($ecGpsAffiche): ?>
    <div class="modal-backdrop ec-modal-haut" id="ecGpsModal" hidden>
      <?php /* .modal ET .card : voir _styles.php — la fenêtre reprend l'en-tête
               et le rythme vertical des cartes de la page, sans style nouveau.
               aria-modal + role=dialog : sans eux, un lecteur d'écran continue
               d'annoncer la page derrière au lieu de la fenêtre. */ ?>
      <div class="modal card" role="dialog" aria-modal="true" aria-labelledby="ecGpsTitre">
        <header>
          <div class="iconwell"><i class="bi bi-geo-alt"></i></div>
          <h2 id="ecGpsTitre">Suivi GPS pendant la course</h2>
          <?php if ($ecConsent !== null): ?>
            <span class="pill is-ok">autorisé</span>
          <?php else: ?>
            <span class="pill no-dot">non autorisé</span>
          <?php endif; ?>
          <?php /* La croix est le SEUL moyen de fermer visible à l'œil : le clic
                   sur le fond et la touche Échap marchent aussi, mais ne se
                   devinent pas. margin-left:auto la pousse au bout de l'en-tête. */ ?>
          <button class="btn" type="button" id="ecGpsFermer" aria-label="Fermer"
                  style="margin-left:auto"><i class="bi bi-x-lg"></i></button>
        </header>

        <?php if ($ecMsg !== ''): ?>
          <div class="alert is-ok"><i class="bi bi-check-circle"></i> <?= $h($ecMsg) ?></div>
        <?php endif; ?>

        <p style="font-size:var(--fs-small);color:var(--ink-dim);margin:0">
          Si vous l'autorisez, l'application enregistre votre position pendant la course.
          Sans votre accord, rien n'est enregistré.
        </p>

        <form method="post">
          <?= csrf_field() ?>
          <div class="row-actions" style="margin-top:var(--sp-4)">
            <?php if ($ecConsent !== null): ?>
              <button class="btn" type="submit" name="consent" value="0">
                <i class="bi bi-x-circle"></i> Retirer mon autorisation
              </button>
              <button class="btn btn-danger" type="submit" name="supprimer_traces" value="1"
                      data-confirm="Supprimer définitivement toutes vos traces GPS enregistrées ?">
                <i class="bi bi-trash3"></i> Supprimer mes traces (<?= (int) $ecNbTraces ?>)
              </button>
            <?php else: ?>
              <button class="btn btn-primary" type="submit" name="consent" value="1">
                <i class="bi bi-check2"></i> Autoriser le suivi GPS
              </button>
            <?php endif; ?>
          </div>
        </form>

        <?php /* ⚠️ CETTE PRÉCISION COMPTE. La phrase disait « les traces sont
                 effacées au bout de N jours » sans distinguer ce qui l'est de ce
                 qui ne l'est pas — on pouvait comprendre que TOUT disparaissait,
                 résultats compris. C'est faux : la purge (src/content/purges.php)
                 ne touche QUE `traces_gps`, le tracé point par point. Les temps,
                 la méthode de mesure et le statut vivent dans `resultats`, que
                 rien n'efface — c'est ce qui permet de revoir ses éditions
                 passées, année après année. */ ?>
        <?php /* « Tracé détaillé » ne veut rien dire pour un coureur. On nomme la
                 chose : le chemin suivi sur la carte, par opposition au temps.
                 C'est la distinction qui compte, et elle doit se comprendre sans
                 effort — sinon on croit que tout disparaît. */ ?>
        <?php /* Le texte suit le RÉGLAGE : annoncer un effacement qui n'a pas lieu
                 (ou l'inverse) est exactement le genre de fausse déclaration que
                 ce projet s'interdit. 0 = conservation illimitée. */ ?>
        <p style="font-size:var(--fs-micro);color:var(--ink-faint);margin:0">
          <i class="bi bi-clock-history me-1"></i>
          <?php if ($ecJoursTraces > 0): ?>
            <strong>Vos temps et vos résultats sont conservés</strong> : vous les
            retrouverez ici chaque année. Seul <strong>le chemin que vous avez suivi
            sur la carte</strong> est effacé au bout de <?= (int) $ecJoursTraces ?> jours.
          <?php else: ?>
            <strong>Tout est conservé d'une année sur l'autre</strong> : vos temps,
            vos résultats et le chemin que vous avez suivi sur la carte. Vous pouvez
            supprimer vos parcours vous-même à tout moment, avec le bouton ci-dessus.
          <?php endif; ?>
        </p>
      </div><!-- /modal -->
    </div><!-- /modal-backdrop -->
    <?php endif; ?>

    <?php if (!$ecChronoActif): ?>
      <div class="alert">
        <i class="bi bi-cone-striped"></i>
        <strong>Le chronométrage n'est pas encore actif.</strong>
        Il arrivera avec l'application mobile. Vous retrouverez alors ici votre temps,
        la façon dont il a été mesuré, et le tracé de votre parcours.
      </div>
    <?php endif; ?>
  <?php endif; ?>

<?php if ($ecGpsAffiche): ?>
<script<?= isset($GLOBALS['csp_nonce']) ? ' nonce="' . $h($GLOBALS['csp_nonce']) . '"' : '' ?>>
(function () {
  var fenetre = document.getElementById('ecGpsModal');
  var ouvrir  = document.getElementById('ecGpsOuvrir');
  var fermer  = document.getElementById('ecGpsFermer');
  if (!fenetre || !ouvrir) return;

  function afficher(oui) {
    fenetre.hidden = !oui;
    /* Le focus suit la fenêtre, et REVIENT sur le bouton à la fermeture :
       sans ça, on se retrouve en haut de page au clavier, sans savoir où. */
    if (oui) { if (fermer) fermer.focus(); }
    else     { ouvrir.focus(); }
  }

  ouvrir.addEventListener('click', function () { afficher(true); });
  if (fermer) fermer.addEventListener('click', function () { afficher(false); });

  /* Clic sur le fond — et UNIQUEMENT sur le fond : sans ce test, un clic
     n'importe où dans la fenêtre la refermerait, y compris en glissant sur du
     texte qu'on essaie de sélectionner. */
  fenetre.addEventListener('click', function (e) {
    if (e.target === fenetre) afficher(false);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !fenetre.hidden) afficher(false);
  });

<?php /* Après un envoi, la page se recharge : la fenêtre serait refermée et le
         « Suivi GPS autorisé » ne serait jamais lu. On la rouvre là où l'action
         a été faite. */ ?>
<?php if ($ecMsg !== ''): ?>
  afficher(true);
<?php endif; ?>
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
