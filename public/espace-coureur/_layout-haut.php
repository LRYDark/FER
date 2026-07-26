<?php
/**
 * _layout-haut.php — En-tête des pages de l'espace coureur.
 *
 * POURQUOI PAS navbar-modern.php : ce partial code ses liens en relatif
 * (href="accueil", ../files/_logos/…) en supposant une page située dans
 * public/. Inclus depuis public/espace-coureur/, tous ses liens tomberaient à
 * côté et le logo ne s'afficherait pas. On garde donc ici un en-tête sobre,
 * cohérent avec la charte, dont les chemins partent bien de ce sous-dossier.
 *
 * Le préfixe underscore signale un fragment : ce n'est pas une page à ouvrir.
 */
$ecLogo = dirname(__DIR__, 2) . '/files/_logos/logo_fer_rose.png';
$ecConnecte = function_exists('pauth_isLogged') && pauth_isLogged();
?>
<header class="ec-nav">
  <a class="ec-brand" href="../accueil.php">
    <?php if (is_file($ecLogo)): ?>
      <img src="../../files/_logos/logo_fer_rose.png" alt="">
    <?php endif; ?>
    <span>Forbach en Rose</span>
  </a>
  <nav class="ec-nav-links">
    <?php if ($ecConnecte): ?>
      <a href="index.php"><i class="bi bi-list-check"></i><span>Mes inscriptions</span></a>
      <a href="deconnexion.php"><i class="bi bi-box-arrow-right"></i><span>Se déconnecter</span></a>
    <?php else: ?>
      <a href="../accueil.php"><i class="bi bi-arrow-left"></i><span>Retour au site</span></a>
    <?php endif; ?>
  </nav>
</header>
<style>
  body { margin:0; background:#f8f7f9; font-family:system-ui,-apple-system,'Segoe UI',sans-serif; }
  .ec-nav { display:flex; align-items:center; justify-content:space-between; gap:16px;
            padding:12px 20px; background:#fff; border-bottom:1px solid #f0e8eb; flex-wrap:wrap; }
  .ec-brand { display:flex; align-items:center; gap:10px; text-decoration:none;
              color:#0f172a; font-weight:700; font-size:.95rem; }
  .ec-brand img { height:30px; width:auto; }
  .ec-nav-links { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
  .ec-nav-links a { display:inline-flex; align-items:center; gap:6px; text-decoration:none;
                    color:#475569; font-size:.85rem; font-weight:600;
                    padding:.45rem .75rem; border-radius:.5rem; }
  .ec-nav-links a:hover { background:#fdf2f8; color:#F42182; }
  @media (max-width:480px) { .ec-nav-links a span { display:none; } }
</style>
