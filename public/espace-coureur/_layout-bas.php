<?php
/**
 * _layout-bas.php — Ferme la coquille ouverte par _layout-haut.php
 * (.ec-stack, .jr-main, .jr-shell) et pose le repli mobile de la barre latérale.
 */
?>
    </div><!-- /ec-stack -->

    <?php /* La marge, le filet et le renvoi en bas viennent de `.ec-shell
             .jr-main > footer.auth-links` dans _styles.php — pas d'un style en
             ligne, qui aurait obligé à répéter la même valeur ici et là. */ ?>
    <footer class="auth-links" style="justify-content:center;flex-wrap:wrap;gap:var(--sp-4)">
      <a href="../accueil.php">Site public</a>
      <a href="../faq.php">Questions fréquentes</a>
      <a href="../politique-confidentialite.php">Confidentialité</a>
      <a href="../mentions-legales.php">Mentions légales</a>
    </footer>
  </main>

  <?php /* Voile de fond : sous 991px la barre latérale se superpose au contenu.
           Sans voile cliquable, on ne saurait pas comment la refermer. */ ?>
  <div class="oc-overlay" id="ecOverlay"></div>
</div><!-- /jr-shell -->

<script<?= isset($GLOBALS['csp_nonce']) ? ' nonce="' . htmlspecialchars($GLOBALS['csp_nonce']) . '"' : '' ?>>
(function () {
  var burger  = document.getElementById('ecBurger');
  var barre   = document.getElementById('ecSidebar');
  var voile   = document.getElementById('ecOverlay');
  if (!burger || !barre) return;

  function basculer(ouvrir) {
    barre.classList.toggle('open', ouvrir);
    if (voile) voile.classList.toggle('show', ouvrir);
    burger.setAttribute('aria-expanded', ouvrir ? 'true' : 'false');
  }
  burger.addEventListener('click', function () { basculer(!barre.classList.contains('open')); });
  if (voile) voile.addEventListener('click', function () { basculer(false); });
  // Échap referme : même geste que partout ailleurs.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && barre.classList.contains('open')) basculer(false);
  });
})();
</script>

<?php /* Confirmations et auto-soumission, compatibles CSP.
         La CSP du site interdit les gestionnaires en ligne : sans ce script,
         « Révoquer cet appareil ? » ne demanderait jamais rien. */ ?>
<?php include __DIR__ . '/../../src/partials/confirm-script.php'; ?>
