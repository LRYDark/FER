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
$ecLogo     = dirname(__DIR__, 2) . '/files/_logos/logo_fer_rose.png';
$ecConnecte = function_exists('pauth_isLogged') && pauth_isLogged();
$ecPage     = basename($_SERVER['SCRIPT_NAME'] ?? '');
$ecTitre    = $ecTitre ?? 'Espace coureur';
$ecSurtitre = $ecSurtitre ?? '';

/** Entrées du menu : fichier => [libellé, icône Bootstrap]. */
$ecMenu = [
    'index.php'         => ['Mes inscriptions', 'bi-list-check'],
    'mes-resultats.php' => ['Mes résultats',    'bi-stopwatch'],
    'appareils.php'     => ['Mes appareils',    'bi-phone'],
    'compte.php'        => ['Mon compte',       'bi-person-gear'],
];
?>
<div class="jr-shell">

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
        <a class="item" href="deconnexion.php" style="margin-top:var(--sp-4)">
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
    </div>

    <div class="ec-stack">
