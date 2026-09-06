<?php
/**
 * _layout-haut.php — Coquille des pages de l'espace coureur : barre du haut
 * avec les entrées en onglets et contenu à plat, EXACTEMENT le rendu des
 * pages d'administration (.oc-* de css/admin-shell.css).
 *
 * POURQUOI PAS navbar-admin.php directement : ce partial est conditionné aux
 * permissions d'administration (canAccessPage / canDoAction) et pointe vers les
 * pages d'admin. Un coureur n'a ni les unes ni l'accès aux autres. On reprend
 * donc sa structure et ses classes — donc son rendu — avec les entrées du
 * coureur, sans dupliquer une seule règle de style.
 *
 * Le préfixe underscore signale un fragment : ce n'est pas une page à ouvrir.
 */
require_once dirname(__DIR__, 2) . '/src/content/chrono.php';         // chrono_actif()
require_once dirname(__DIR__, 2) . '/src/content/notifications.php';  // notif_nonLusCount()
require_once dirname(__DIR__, 2) . '/src/content/course.php';         // course_lire()

$ecLogo     = dirname(__DIR__, 2) . '/files/_logos/logo_fer_rose.png';
$ecConnecte = function_exists('pauth_isLogged') && pauth_isLogged();
$ecPage     = basename($_SERVER['SCRIPT_NAME'] ?? '');
$ecTitre    = $ecTitre ?? 'Espace coureur';
$ecSurtitre = $ecSurtitre ?? '';

/* Chronométrage fermé : l'espace coureur ne sert qu'aux inscriptions. */
$ecChronoOuvert = $ecChronoOuvert ?? chrono_actif($pdo);

/** Entrées du menu : fichier => [libellé, icône Bootstrap]. */
$ecMenu = [
    'index.php'         => ['Mes inscriptions', 'bi-list-check'],
    // ⚠️ MÊME RUBRIQUE QUE L'ONGLET DE L'APPLICATION. Elle manquait ici : les
    // annonces de l'organisation n'étaient lisibles que par ceux qui avaient
    // installé l'application, alors que la source est la même fonction.
    'messages.php'      => ["Messages",         'bi-envelope'],
    'mes-resultats.php' => ['Mes résultats',    'bi-stopwatch'],
    'appareils.php'     => ['Mes appareils',    'bi-phone'],
    'compte.php'        => ['Mon compte',       'bi-person-gear'],
];

/* ⚠️ « MES RÉSULTATS » RESTE, MÊME CHRONOMÉTRAGE FERMÉ — ET C'EST UN
   CORRECTIF, PAS UN OUBLI.

   Cette page ne porte pas que des temps : c'est elle qui contient le
   consentement au suivi GPS ET la suppression des tracés enregistrés. La
   retirer du menu onze mois sur douze rendait le DROIT À L'EFFACEMENT
   inatteignable en dehors de la semaine de la course — alors que c'est
   justement hors période qu'on y pense, comme pour « Mes appareils ».
   Le RGPD n'admet pas qu'un droit soit ouvert par intermittence.

   La page se garde d'elle-même : sans chronométrage, elle affiche les éditions
   sans temps, et la carte du suivi GPS reste utile. */

?>
<?php
/* ── Identité du coureur connecté, pour la puce du compte ──────────────────
 * La session est posée par pauth_login() ; on la lit avec prudence : ce
 * fragment est aussi rendu déconnecté (page de connexion). */
$ecSess   = (defined('PAUTH_SESSION_KEY') && isset($_SESSION[PAUTH_SESSION_KEY])) ? $_SESSION[PAUTH_SESSION_KEY] : [];
$ecPrenom = trim((string) ($ecSess['prenom'] ?? ''));
$ecNom    = trim((string) ($ecSess['nom'] ?? ''));
$ecMail   = trim((string) ($ecSess['email'] ?? ''));
if ($ecPrenom === '') $ecPrenom = $ecMail !== '' ? explode('@', $ecMail)[0] : 'coureur';
$ecInitiale = mb_strtoupper(mb_substr($ecPrenom, 0, 1));

/* ── Messages non lus, pour la pastille de l'onglet ───────────────────────────
 * Comptés par la MÊME fonction que celle qui garnit la boîte : si la pastille
 * comptait autre chose, elle annoncerait des messages introuvables dans la
 * page. Le compte exclut donc les masqués, exactement comme la boîte. */
$ecNonLus = 0;
/* ⚠️ PAS DE PASTILLE SANS LA TABLE DES LECTURES. Sans elle, aucun message
   n'est « lu » : la pastille aurait annoncé la totalité de la boîte, en
   permanence, sans qu'on puisse jamais la faire descendre. */
if ($ecConnecte && function_exists('notif_nonLusCount') && notif_luesDisponible($pdo)) {
    try {
        $ecAnneeCourse = (int) (course_lire($pdo)['annee'] ?? 0);
        $ecNonLus = notif_nonLusCount($pdo, (int) pauth_id(), $ecAnneeCourse > 0 ? $ecAnneeCourse : null);
    } catch (\Throwable $e) {
        // Une pastille ne vaut pas une page blanche.
        error_log('[EC] compte des non lus : ' . $e->getMessage());
    }
}
?>
<?php /* ── Coquille : la MÊME que l'administration (.oc-* de css/admin-shell.css)
         ─────────────────────────────────────────────────────────────────────
         Barre du haut avec le logo, les entrées en onglets, le compte à
         droite ; contenu à plat sur la page, sans carte ni contour.

         PAS DE SOUS-MENU À GAUCHE, et c'est volontaire : le menu du coureur
         est plat — cinq entrées, aucune sous-rubrique. La carte grise
         flottante n'aurait rien à contenir. `.oc-body.is-wide` donne alors
         toute la largeur au contenu.

         `ec-shell` ne sert que de portée pour les quelques règles de
         _styles.php qui collent le pied de page en bas. */ ?>
<div class="oc-shell ec-shell">

  <header class="oc-top">
    <?php /* Sous 861 px, admin-shell.css masque les onglets : ils passent dans
             le tiroir. Déconnecté il n'y a qu'une entrée (« Se connecter »),
             qui reste alors visible — un burger pour une seule ligne, c'est un
             geste de plus pour rien. */ ?>
    <?php if ($ecConnecte): ?>
      <button class="oc-burger" id="ocBurger" type="button" aria-label="Ouvrir le menu">
        <span></span><span></span><span></span>
      </button>
    <?php endif; ?>

    <a class="oc-brand" href="../accueil.php" title="Forbach en Rose">
      <?php if (is_file($ecLogo)): ?>
        <img src="../../files/_logos/logo_fer_rose.png" alt="Forbach en Rose">
      <?php else: ?>
        <span class="name">Forbach en Rose</span>
      <?php endif; ?>
    </a>

    <?php /* `is-always` : les onglets restent affichés sur mobile quand il n'y
             a pas de tiroir pour les recueillir. Sans ça, « Se connecter »
             disparaissait purement et simplement du téléphone. */ ?>
    <nav class="oc-tabs<?= $ecConnecte ? '' : ' is-always' ?>">
      <?php if ($ecConnecte): ?>
        <?php foreach ($ecMenu as $fichier => [$libelle, $icone]): ?>
          <?php $ecPastille = ($fichier === 'messages.php' && $ecNonLus > 0); ?>
          <a class="<?= $ecPage === $fichier ? 'is-active' : '' ?><?= $ecPastille ? ' has-badge' : '' ?>" href="<?= htmlspecialchars($fichier) ?>">
            <?= htmlspecialchars($libelle) ?>
            <?php /* La pastille ne dit QUE le nombre de non lus. Elle disparaît
                     à zéro : une pastille à « 0 » attire l'œil pour annoncer
                     qu'il n'y a rien. */ ?>
            <?php if ($ecPastille): ?>
              <span class="oc-badge" title="<?= (int) $ecNonLus ?> message<?= $ecNonLus > 1 ? 's' : '' ?> non lu<?= $ecNonLus > 1 ? 's' : '' ?>"><?= (int) $ecNonLus ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php else: ?>
        <a class="<?= $ecPage === 'login.php' ? 'is-active' : '' ?>" href="login.php">Se connecter</a>
      <?php endif; ?>
    </nav>

    <div class="oc-topright">
      <?php if ($ecConnecte): ?>
        <button class="oc-user" id="ecAvatarBtn" type="button" title="<?= htmlspecialchars($ecMail) ?>">
          <span class="ava"><?= htmlspecialchars($ecInitiale) ?></span>
          <span class="who">Bonjour <?= htmlspecialchars($ecPrenom) ?></span>
          <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
        </button>

        <?php /* Tout ce qui fait SORTIR de l'espace tient ici, et nulle part
                 ailleurs — comme dans l'administration. « Mon compte » reste
                 un onglet : c'est une page de l'espace, pas une sortie. */ ?>
        <div class="oc-usermenu" id="ecDropdown">
          <div class="head">
            <span class="ava"><?= htmlspecialchars($ecInitiale) ?></span>
            <span class="id">
              <span class="name"><?= htmlspecialchars(trim($ecPrenom . ' ' . $ecNom)) ?></span>
              <span class="mail"><?= htmlspecialchars($ecMail) ?></span>
            </span>
          </div>
          <hr>
          <a href="../accueil.php">
            <svg viewBox="0 0 24 24"><path d="M3 12l9-9 9 9"/><path d="M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10"/></svg>
            <span>Site public</span>
          </a>
          <a href="../faq.php">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 2.5-3 4"/><line x1="12" y1="17" x2="12" y2="17"/></svg>
            <span>Questions fréquentes</span>
          </a>
          <hr>
          <a href="deconnexion.php" class="is-danger">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span>Se déconnecter</span>
          </a>
        </div>
      <?php else: ?>
        <a class="oc-btn" href="../accueil.php">Site public</a>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($ecConnecte): ?>
    <?php
    /* ═══ MENU DES PETITS ÉCRANS ═══
     * Le MÊME tiroir que l'administration (src/partials/mobile-nav.php), avec
     * le menu du coureur : plat, donc uniquement des entrées et aucune
     * section dépliable. Les onglets du haut ne défilent plus dans une barre
     * sans indice de débordement — on les voit tous d'un coup. */
    $mnTitre  = 'Espace coureur';
    $mnGroups = [];
    $mnTop    = [];
    foreach ($ecMenu as $fichier => [$libelle, $icone]) {
        $e = ['href' => $fichier, 'label' => $libelle, 'bi' => $icone, 'active' => ($ecPage === $fichier)];
        // Même compteur que la pastille des onglets : une seule source.
        if ($fichier === 'messages.php') $e['badge'] = (int) $ecNonLus;
        $mnTop[] = $e;
    }
    include dirname(__DIR__, 2) . '/src/partials/mobile-nav.php';
    ?>
    <?php /* Voile de fond du tiroir — stylé par .oc-overlay (css/admin.css).
             L'administration le rend dans navbar-admin.php ; ici il n'existait
             pas, la coquille du coureur n'ayant jamais eu de tiroir. */ ?>
    <div class="oc-overlay" id="ocOverlay"></div>
  <?php endif; ?>

  <div class="oc-body is-wide">
    <?php /* Colonne de lecture bornée par défaut : sur un grand écran, une
             page de formulaires étirée sur 1900 px fatigue à la lecture et
             fait paraître chaque bloc vide. Une page qui a vraiment besoin de
             la largeur — Mon compte et ses deux colonnes — pose
             $ecPleineLargeur = true avant d'inclure ce fragment. */ ?>
    <div class="oc-page<?= !empty($ecPleineLargeur) ? ' ec-large' : '' ?>">

      <header class="oc-pagehead">
        <div>
          <?php if ($ecSurtitre !== ''): ?>
            <p class="sub" style="margin:0 0 2px"><?= htmlspecialchars($ecSurtitre) ?></p>
          <?php endif; ?>
          <h1><?= htmlspecialchars($ecTitre) ?></h1>
        </div>

        <?php /* Actions de la page, en face du titre.
                 ⚠️ HTML BRUT, VOLONTAIREMENT NON ÉCHAPPÉ. Cette variable est
                 écrite par la page elle-même — jamais par une saisie. Toute
                 donnée qu'on y place doit passer par htmlspecialchars() AVANT
                 d'arriver ici. */ ?>
        <?php if (($ecTopbarActions ?? '') !== ''): ?>
          <div class="acts"><?= $ecTopbarActions ?></div>
        <?php endif; ?>
      </header>
    <div class="ec-stack">
