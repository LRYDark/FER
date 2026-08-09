<?php
/**
 * index.php — « Mes inscriptions ».
 *
 * Toutes les inscriptions rattachées au compte, TOUTES ÉDITIONS CONFONDUES,
 * groupées par édition (la plus récente en premier), puis par `group_id` :
 * c'est le cas classique du parent qui inscrit toute la famille sous sa propre
 * adresse. C'est précisément là que le bouton « Transférer » doit se voir.
 */
define('FER_SESSION_COUREUR', true);
require '../../src/core/config.php';
checkMaintenance();
require_once '../../src/security/csrf.php';
require_once '../../src/auth/participant_auth.php';

pauth_require($pdo, 'index.php');

require_once '../../src/content/course.php';   // course_lire()

$moi    = $_SESSION[PAUTH_SESSION_KEY];
$lignes = pauth_registrations($pdo, pauth_id());

/* ──────────────────── L'inscription en cours, en tête ─────────────────────
 * Elle est sortie de la liste et présentée en grand : c'est la seule qu'on
 * vient regarder, et la liste ne disait ni la date ni le numéro autrement
 * qu'en petits caractères.
 *
 * ⚠️ ON NE PARLE PAS DE « DOSSARD » : cette course n'en distribue pas. Le
 * numéro affiché est celui de l'INSCRIPTION, et c'est lui que le QR encode.
 *
 * ⚠️ MÊME PRÉSENTATION QUE L'APPLICATION, DÉLIBÉRÉMENT. Quelqu'un qui passe
 * du téléphone au navigateur doit reconnaître le même objet — c'est ce qui
 * évite de se demander si l'on regarde bien la même inscription.
 * ───────────────────────────────────────────────────────────────────────── */
$ecCourse   = course_lire($pdo);
$ecAnneeAct = (int) ($ecCourse['annee'] ?? 0);
$ecDossard  = null;
foreach ($lignes as $r) {
    if ((int) $r['annee'] === $ecAnneeAct) { $ecDossard = $r; break; }
}

/** Date de la course : l'heure publiée d'abord, le jour seul en repli. */
$ecQuand = null;
if (!empty($ecCourse['heure_depart'])) {
    $ecQuand = new DateTimeImmutable((string) $ecCourse['heure_depart'], new DateTimeZone('UTC'));
    $ecQuand = $ecQuand->setTimezone(new DateTimeZone(date_default_timezone_get()));
} elseif (!empty($ecCourse['date_course'])) {
    $ecQuand = new DateTimeImmutable((string) $ecCourse['date_course']);
}

/** « dimanche 5 juillet 2026 » — sans dépendre de la locale du serveur. */
$ecDateLongue = static function (DateTimeImmutable $d): string {
    $jours = ['lundi','mardi','mercredi','jeudi','vendredi','samedi','dimanche'];
    $mois  = ['janvier','février','mars','avril','mai','juin',
              'juillet','août','septembre','octobre','novembre','décembre'];
    return $jours[(int) $d->format('N') - 1] . ' ' . (int) $d->format('j') . ' '
         . $mois[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
};

/** Jours restants, ou null si la date est passée. */
$ecJoursRestants = null;
if ($ecQuand !== null) {
    $ecDelta = (new DateTimeImmutable('today'))->diff(
        new DateTimeImmutable($ecQuand->format('Y-m-d')));
    if ($ecDelta->invert === 0 && (int) $ecDelta->days >= 0) {
        $ecJoursRestants = (int) $ecDelta->days;
    }
}

/* ⚠️ L'INSCRIPTION MISE EN DOSSARD SORT DE LA LISTE — SAUF SI ELLE EST EN
   GROUPE. Affichée aux deux endroits, on lisait deux fois le même prénom et le
   même numéro à trois centimètres d'écart, ce qui donne l'impression d'être
   inscrit deux fois.

   Le cas du groupe est différent : la retirer masquerait ses proches, ou la
   ferait disparaître d'une carte qui compte « 3 inscriptions » et n'en montre
   que deux. On la garde donc dès qu'elle partage un `group_id`. */
$ecGroupeDuDossard = $ecDossard === null
    ? ''
    : trim((string) ($ecDossard['group_id'] ?? ''));
$ecDossardSeul = $ecDossard !== null && $ecGroupeDuDossard === '';

/* ⚠️ LES ÉDITIONS PASSÉES NE SONT PLUS AFFICHÉES ICI — comme dans
   l'application.

   Cette page sert à UNE chose : la course qui vient. Empiler dessous les années
   précédentes noyait le dossard du moment sous un historique qu'on ne consulte
   que rarement.

   Rien n'est perdu : dossard, montant payé, taille de T-shirt et ville ont
   rejoint « Mes résultats », où chaque édition passée a son bloc complet — le
   temps ET le reçu au même endroit.

   ⚠️ C'EST POURQUOI « MES RÉSULTATS » RESTE AU MENU même chronométrage fermé
   (voir _layout-haut.php) : sinon les éditions passées deviendraient
   introuvables onze mois sur douze. */
$parEdition = [];
foreach ($lignes as $r) {
    if ((int) $r['annee'] !== $ecAnneeAct) continue;
    if ($ecDossardSeul
        && (int) $r['annee'] === (int) $ecDossard['annee']
        && (string) $r['inscription_no'] === (string) $ecDossard['inscription_no']) {
        continue;
    }
    $annee = (int) $r['annee'];
    $grp   = trim((string) ($r['group_id'] ?? ''));
    $parEdition[$annee][$grp === '' ? '_seul_' . $r['inscription_no'] : $grp][] = $r;
}
krsort($parEdition);

/* Titre et surtitre de la barre supérieure de la coquille (cf. _layout-haut.php). */
$ecTitre    = 'Mes inscriptions';
$ecSurtitre = trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')) ?: ($moi['email'] ?? '');

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/** Pastille de paiement, à partir du montant et du mode. */
function ec_pillPaiement(array $r): string
{
    $montant = (float) ($r['montant_du'] ?? 0);
    $mode    = strtolower(trim((string) ($r['paiement_mode'] ?? '')));
    if ($montant <= 0 || $mode === 'gratuit') {
        return '<span class="pill is-ok">Gratuit</span>';
    }
    return '<span class="pill no-dot">' . htmlspecialchars($mode !== '' ? $mode : 'Réglé', ENT_QUOTES, 'UTF-8')
         . ' · ' . number_format($montant, 2, ',', ' ') . ' €</span>';
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Mes inscriptions</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php include __DIR__ . '/_styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_layout-haut.php'; ?>

  <?php /* ⚠️ SUR `$lignes`, PAS SUR `$parEdition`. Depuis que le dossard sort de
           la liste, quelqu'un qui n'a QUE l'inscription de l'édition en cours
           laisse `$parEdition` vide — et voyait donc « aucune inscription
           rattachée » juste sous son propre dossard. */ ?>
  <?php if (!$lignes): ?>
    <div class="card">
      <header>
        <div class="iconwell"><i class="bi bi-inbox"></i></div>
        <h2>Aucune inscription rattachée</h2>
      </header>
      <div class="empty">
        <p>Si vous vous êtes inscrit avec une autre adresse email, déconnectez-vous et
           reconnectez-vous avec celle-ci — c'est l'adresse qui fait le lien.</p>
        <div class="row-actions">
          <a class="btn" href="../faq.php"><i class="bi bi-question-circle"></i> Questions fréquentes</a>
          <a class="btn btn-primary" href="../register.php"><i class="bi bi-plus-lg"></i> S'inscrire</a>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($ecDossard !== null): ?>
    <section class="ec-dossard">
      <?php /* Le libellé et l'action sur la même ligne : l'action appartient au
               dossard, elle n'a pas à flotter sous le bloc suivant. */ ?>
      <div class="ec-dossard-tete">
        <span class="ec-dossard-edition">
          <?= $h(mb_strtoupper($ecCourse['libelle'] ?: "Forbach en Rose $ecAnneeAct", 'UTF-8')) ?>
        </span>
        <a class="ec-dossard-voir"
           href="inscription.php?annee=<?= (int) $ecDossard['annee'] ?>&amp;no=<?= urlencode((string) $ecDossard['inscription_no']) ?>">
          <i class="bi bi-person-vcard"></i> Voir mon inscription
        </a>
      </div>

      <div class="ec-dossard-no">
        <span class="prefixe">N°</span><?= $h($ecDossard['inscription_no']) ?>
      </div>
      <div class="ec-dossard-nom">
        <?= $h(trim(($ecDossard['prenom'] ?? '') . ' ' . ($ecDossard['nom'] ?? ''))) ?: 'Sans nom' ?>
      </div>

      <?php if ($ecQuand !== null): ?>
        <?php /* La date EST le bouton : elle télécharge l'événement iCalendar,
                 que le système propose d'ajouter au calendrier. Pas
                 d'intégration Google ou Apple à maintenir, et ça marche
                 partout — y compris sur les appareils qu'on n'a pas prévus. */ ?>
        <a class="ec-dossard-date"
           href="agenda.php?annee=<?= (int) $ecDossard['annee'] ?>&amp;no=<?= urlencode((string) $ecDossard['inscription_no']) ?>">
          <i class="bi bi-calendar-event"></i>
          <span class="txt">
            <strong><?= $h($ecDateLongue($ecQuand)) ?></strong>
            <small>Ajouter à l'agenda</small>
          </span>
          <?php if ($ecJoursRestants !== null): ?>
            <span class="jrs"><?= $ecJoursRestants === 0 ? "AUJOURD'HUI" : 'J-' . $ecJoursRestants ?></span>
          <?php endif; ?>
          <i class="bi bi-chevron-right chev"></i>
        </a>
      <?php endif; ?>
    </section>

    <?php /* Les mêmes informations pratiques que dans l'application, dans le
             même ordre. Quelqu'un qui passe du téléphone au navigateur ne doit
             pas avoir à réapprendre où regarder. */ ?>
    <section class="ec-pratique">
      <div class="rows">
        <?php if (!empty($ecCourse['lieu_rdv']) || !empty($ecCourse['lieu_adresse'])): ?>
          <div class="row"><div class="grow">
            <div class="sub">Rendez-vous</div>
            <div class="title"><?= $h($ecCourse['lieu_rdv'] ?: $ecCourse['lieu_adresse']) ?></div>
          </div></div>
        <?php endif; ?>

        <?php if (!empty($ecCourse['distance_km'])): ?>
          <div class="row"><div class="grow">
            <div class="sub">Distance</div>
            <div class="title"><?= $h(number_format((float) $ecCourse['distance_km'], 2, ',', ' ')) ?> km</div>
          </div></div>
        <?php endif; ?>

        <?php if (!empty($ecCourse['horaires'])): ?>
          <div class="row"><div class="grow">
            <div class="sub">Horaires</div>
            <div class="title"><?= $h($ecCourse['horaires']) ?></div>
          </div></div>
        <?php endif; ?>

        <?php if (!empty($ecCourse['retrait_tshirt'])): ?>
          <div class="row"><div class="grow">
            <div class="sub">Dossards et T-shirts</div>
            <div class="title"><?= $h($ecCourse['retrait_tshirt']) ?></div>
          </div></div>
        <?php endif; ?>
      </div>

    </section>
  <?php endif; ?>

  <?php foreach ($parEdition as $annee => $groupes): ?>
    <?php foreach ($groupes as $membres): ?>
      <?php $estGroupe = count($membres) > 1; ?>
      <section class="card">
        <header>
          <div style="display:flex;align-items:center;gap:var(--sp-3);min-width:0">
            <div class="iconwell"><i class="bi <?= $estGroupe ? 'bi-people' : 'bi-person' ?>"></i></div>
            <div>
              <h2>Aussi inscrits sous votre adresse</h2>
              <?php if ($estGroupe): ?>
                <div class="sub" style="font-size:var(--fs-small);color:var(--ink-faint)">
                  Inscription groupée — <?= count($membres) ?> personnes
                </div>
              <?php endif; ?>
            </div>
          </div>
          <span class="pill no-dot"><?= count($membres) ?> inscription<?= $estGroupe ? 's' : '' ?></span>
        </header>

        <div class="rows">
          <?php foreach ($membres as $r): ?>
            <div class="row">
              <div class="grow">
                <div class="title"><?= $h(trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? ''))) ?: 'Sans nom' ?></div>
                <div class="sub">
                  N° <span class="ec-mono"><?= $h($r['inscription_no']) ?></span>
                  <?php if (!empty($r['tshirt_size']) && $r['tshirt_size'] !== '-'): ?>
                    · T-shirt <?= $h($r['tshirt_size']) ?>
                  <?php endif; ?>
                  <?php if (!empty($r['ville'])): ?> · <?= $h($r['ville']) ?><?php endif; ?>
                </div>
              </div>
              <?= ec_pillPaiement($r) ?>
              <a class="btn"
                 href="inscription.php?annee=<?= (int) $r['annee'] ?>&amp;no=<?= urlencode((string) $r['inscription_no']) ?>">
                <i class="bi bi-eye"></i> Voir
              </a>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($estGroupe): ?>
          <p style="font-size:var(--fs-micro);color:var(--ink-faint);margin:0">
            <i class="bi bi-info-circle"></i>
            Ces personnes partagent votre adresse email. Pour que l'une d'elles ait son
            <?= !empty($ecChronoOuvert) ? 'propre chronométrage' : 'propre espace' ?>,
            transférez son inscription depuis sa fiche.
          </p>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  <?php endforeach; ?>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
