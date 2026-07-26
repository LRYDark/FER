<?php
/**
 * _layout-bas.php — Pied des pages de l'espace coureur.
 * Voir _layout-haut.php pour la raison du pied dédié.
 */
?>
<footer class="ec-foot">
  <a href="../accueil.php">Site public</a>
  <a href="../faq.php">Questions fréquentes</a>
  <a href="../politique-confidentialite.php">Confidentialité</a>
  <a href="../mentions-legales.php">Mentions légales</a>
</footer>
<style>
  .ec-foot { display:flex; align-items:center; justify-content:center; gap:18px; flex-wrap:wrap;
             padding:22px 16px 32px; font-size:.8rem; }
  .ec-foot a { color:var(--ec-dim); text-decoration:none; }
  .ec-foot a:hover { color:var(--ec-rose); text-decoration:underline; }
</style>
