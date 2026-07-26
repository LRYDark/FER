<?php
/**
 * _layout-haut.php — Barre supérieure des pages de l'espace coureur.
 *
 * POURQUOI PAS navbar-admin.php : ce partial est conditionné aux permissions
 * d'administration (canAccessPage / canDoAction) et pointe vers les pages
 * d'administration. Un coureur n'a ni les unes ni l'accès aux autres.
 * On garde donc une barre propre à l'espace, mais bâtie sur les MÊMES jetons de
 * style — elle suit la charte et le thème sans les recopier.
 *
 * Le préfixe underscore signale un fragment : ce n'est pas une page à ouvrir.
 */
$ecLogo     = dirname(__DIR__, 2) . '/files/_logos/logo_fer_rose.png';
$ecConnecte = function_exists('pauth_isLogged') && pauth_isLogged();
$ecPage     = basename($_SERVER['SCRIPT_NAME'] ?? '');

/** Onglets de l'espace, dans l'ordre d'usage. */
$ecMenu = [
    'index.php'         => ['Mes inscriptions', 'bi-list-check'],
    'mes-resultats.php' => ['Mes résultats',    'bi-stopwatch'],
    'appareils.php'     => ['Mes appareils',    'bi-phone'],
    'compte.php'        => ['Mon compte',       'bi-person-gear'],
];
?>
<header class="ec-topbar">
  <a class="ec-brand" href="../accueil.php">
    <?php if (is_file($ecLogo)): ?>
      <img src="../../files/_logos/logo_fer_rose.png" alt="">
    <?php endif; ?>
    <span>Forbach en Rose</span>
  </a>

  <nav class="ec-tabs">
    <?php if ($ecConnecte): ?>
      <?php foreach ($ecMenu as $fichier => [$libelle, $icone]): ?>
        <a href="<?= $fichier ?>" class="<?= $ecPage === $fichier ? 'is-active' : '' ?>"
           title="<?= htmlspecialchars($libelle) ?>">
          <i class="bi <?= $icone ?>"></i><span><?= htmlspecialchars($libelle) ?></span>
        </a>
      <?php endforeach; ?>
      <a href="deconnexion.php" title="Se déconnecter">
        <i class="bi bi-box-arrow-right"></i><span>Quitter</span>
      </a>
    <?php else: ?>
      <a href="../accueil.php"><i class="bi bi-arrow-left"></i><span>Retour au site</span></a>
    <?php endif; ?>
  </nav>
</header>

<div class="ec-shell">
  <div class="ec-stack">
