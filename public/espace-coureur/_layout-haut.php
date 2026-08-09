<?php
/**
 * _layout-haut.php — Coquille des pages de l'espace coureur : barre latérale
 * à gauche et contenu à droite, EXACTEMENT la structure de l'administration
 * (.jr-shell / .jr-nav / .jr-main de css/admin.css).
 *
 * POURQUOI PAS navbar-admin.php directement : ce partial est conditionné aux
 * permissions d'administration (canAccessPage / canDoAction) et pointe vers les
 * pages d'admin. Un coureur n'a ni les unes ni l'accès aux autres. On reprend
 * donc sa structure et ses classes — donc son rendu — avec les entrées du
 * coureur, sans dupliquer une seule règle de style.
 *
 * Le préfixe underscore signale un fragment : ce n'est pas une page à ouvrir.
 */
require_once dirname(__DIR__, 2) . '/src/content/chrono.php';   // chrono_actif()

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
<?php /* `ec-shell` ne sert QUE de portée : elle permet de faire du panneau
         principal une colonne flex sans toucher à `.jr-main` d'admin.css, qui
         habille aussi toute l'administration. Une règle globale ferait bouger
         des dizaines de pages pour régler le pied d'une seule. */ ?>
<div class="jr-shell ec-shell">

  <aside class="jr-nav" id="ecSidebar">
    <a class="jr-brand" href="../accueil.php" title="Forbach en Rose">
      <?php if (is_file($ecLogo)): ?>
        <img src="../../files/_logos/logo_fer_rose.png" alt="Forbach en Rose">
      <?php else: ?>
        <span class="name">Forbach en Rose</span>
      <?php endif; ?>
    </a>

    <nav>
      <?php if ($ecConnecte): ?>
        <div class="section">Mon espace</div>
        <?php foreach ($ecMenu as $fichier => [$libelle, $icone]): ?>
          <a class="item <?= $ecPage === $fichier ? 'is-active' : '' ?>" href="<?= $fichier ?>">
            <i class="bi <?= $icone ?>"></i><?= htmlspecialchars($libelle) ?>
          </a>
        <?php endforeach; ?>

        <div class="section">Le site</div>
        <a class="item" href="../accueil.php"><i class="bi bi-house"></i>Site public</a>
        <a class="item" href="../faq.php"><i class="bi bi-question-circle"></i>Questions fréquentes</a>
        <?php /* En rouge : c'est la seule entrée du menu qui fait quitter
                 l'espace. La distinguer d'un coup d'œil évite de cliquer
                 dessus en cherchant autre chose.

                 ⚠️ `margin-top:auto` ET NON UNE MARGE FIXE. `.jr-nav nav` est
                 une colonne flex en `flex:1` : la marge automatique absorbe
                 tout l'espace libre et colle donc l'entrée EN BAS de la barre,
                 quel que soit le nombre d'entrées au-dessus — « Mes résultats »
                 disparaît hors période de chronométrage, et avec une marge fixe
                 la déconnexion remontait alors au milieu du vide.

                 Elle se replace toute seule juste après le menu quand la barre
                 est trop courte pour défiler : une marge automatique vaut zéro
                 dès qu'il n'y a plus d'espace à distribuer. */ ?>
        <a class="item is-danger" href="deconnexion.php" style="margin-top:auto">
          <i class="bi bi-box-arrow-right"></i>Se déconnecter
        </a>
      <?php else: ?>
        <a class="item" href="login.php"><i class="bi bi-box-arrow-in-right"></i>Se connecter</a>
        <a class="item" href="../accueil.php"><i class="bi bi-house"></i>Site public</a>
      <?php endif; ?>
    </nav>
  </aside>

  <main class="jr-main">
    <div class="jr-topbar">
      <div style="display:flex;align-items:center;gap:var(--sp-3)">
        <?php /* Burger : la barre latérale sort de l'écran sous 991px, comme en
                 administration. Sans lui, le menu serait inaccessible sur mobile. */ ?>
        <button class="jr-burger" id="ecBurger" type="button" aria-label="Menu"
                aria-controls="ecSidebar" aria-expanded="false">
          <i class="bi bi-list"></i>
        </button>
        <div class="crumbs">
          <?php if ($ecSurtitre !== ''): ?>
            <span class="eyebrow"><?= htmlspecialchars($ecSurtitre) ?></span>
          <?php endif; ?>
          <h1><?= htmlspecialchars($ecTitre) ?></h1>
        </div>
      </div>

      <?php /* Actions de la page, EN FACE du titre : .jr-topbar est déjà en
               `justify-content: space-between` (css/admin.css), un second enfant
               se range donc à droite tout seul, et repasse sous le titre quand
               l'écran devient étroit (flex-wrap). Aucun style à inventer.

               ⚠️ HTML BRUT, VOLONTAIREMENT NON ÉCHAPPÉ. Cette variable est écrite
               par la page elle-même — jamais par une saisie. Toute donnée qu'on y
               place doit être passée par htmlspecialchars() AVANT d'y arriver. */ ?>
      <?php if (($ecTopbarActions ?? '') !== ''): ?>
        <div class="row-actions" style="margin:0"><?= $ecTopbarActions ?></div>
      <?php endif; ?>
    </div>

    <div class="ec-stack">
