<?php
/**
 * _layout-haut.php — En-tête et navigation des pages de l'espace coureur.
 *
 * POURQUOI PAS navbar-modern.php : ce partial code ses liens en relatif
 * (href="accueil", ../files/_logos/…) en supposant une page située dans
 * public/. Inclus depuis public/espace-coureur/, tous ses liens tomberaient à
 * côté et le logo ne s'afficherait pas. On garde donc ici un en-tête sobre,
 * cohérent avec la charte, dont les chemins partent bien de ce sous-dossier.
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
<header class="ec-nav">
  <a class="ec-brand" href="../accueil.php">
    <?php if (is_file($ecLogo)): ?>
      <img src="../../files/_logos/logo_fer_rose.png" alt="">
    <?php endif; ?>
    <span>Forbach en Rose</span>
  </a>

  <?php if ($ecConnecte): ?>
    <nav class="ec-tabs">
      <?php foreach ($ecMenu as $fichier => [$libelle, $icone]): ?>
        <a href="<?= $fichier ?>" class="<?= $ecPage === $fichier ? 'actif' : '' ?>">
          <i class="bi <?= $icone ?>"></i><span><?= htmlspecialchars($libelle) ?></span>
        </a>
      <?php endforeach; ?>
      <a href="deconnexion.php" class="ec-sortie">
        <i class="bi bi-box-arrow-right"></i><span>Quitter</span>
      </a>
    </nav>
  <?php else: ?>
    <nav class="ec-tabs">
      <a href="../accueil.php"><i class="bi bi-arrow-left"></i><span>Retour au site</span></a>
    </nav>
  <?php endif; ?>
</header>
<style>
  /* Couleurs prises dans les variables de _styles.php : clair et sombre suivent
     automatiquement, il n'y a qu'un seul jeu de règles à maintenir. */
  body { margin:0; background:var(--ec-bg); color:var(--ec-ink);
         font-family:system-ui,-apple-system,'Segoe UI',sans-serif; }
  .ec-nav { display:flex; align-items:center; justify-content:space-between; gap:16px;
            padding:12px 20px; background:var(--ec-card);
            border-bottom:1px solid var(--ec-border); flex-wrap:wrap; }
  .ec-brand { display:flex; align-items:center; gap:10px; text-decoration:none;
              color:var(--ec-ink); font-weight:700; font-size:.95rem; }
  .ec-brand img { height:30px; width:auto; }
  .ec-tabs { display:flex; align-items:center; gap:4px; flex-wrap:wrap; }
  .ec-tabs a { display:inline-flex; align-items:center; gap:6px; text-decoration:none;
               color:var(--ec-soft-ink); font-size:.85rem; font-weight:600;
               padding:.45rem .7rem; border-radius:.5rem; }
  .ec-tabs a:hover { background:var(--ec-rose-soft); color:var(--ec-rose); }
  .ec-tabs a.actif { background:var(--ec-rose-soft); color:var(--ec-rose); }
  .ec-tabs .ec-sortie { color:var(--ec-dim); }
  /* Sous 620px, les libellés disparaissent : quatre onglets plus la sortie ne
     tiennent pas sur une ligne, et un menu qui passe à la ligne pousse le
     contenu hors de l'écran. Les icônes restent explicites. */
  @media (max-width:620px) { .ec-tabs a span { display:none; } .ec-tabs a { padding:.5rem .6rem; } }
</style>
