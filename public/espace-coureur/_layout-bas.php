<?php
/**
 * _layout-bas.php — Ferme la coquille ouverte par _layout-haut.php
 * (.ec-stack, .oc-page, .oc-body, .oc-shell) et pose le menu du compte.
 *
 * Depuis le passage au shell v2.1, il n'y a plus de barre latérale à replier :
 * les entrées sont des onglets en haut, qui défilent horizontalement sur les
 * petits écrans. Le burger et le voile de fond ont donc disparu avec elle.
 */
?>
      </div><!-- /ec-stack -->

      <?php /* La marge, le filet et le renvoi en bas viennent de `.ec-shell
               .oc-page > footer.auth-links` dans _styles.php — pas d'un style
               en ligne, qui aurait obligé à répéter la même valeur ici et là. */ ?>
      <footer class="auth-links" style="justify-content:center;flex-wrap:wrap;gap:var(--sp-4)">
        <a href="../accueil.php">Site public</a>
        <a href="../faq.php">Questions fréquentes</a>
        <a href="../politique-confidentialite.php">Confidentialité</a>
        <a href="../mentions-legales.php">Mentions légales</a>
      </footer>

    </div><!-- /.oc-page -->
  </div><!-- /.oc-body -->
</div><!-- /.oc-shell -->

<script<?= isset($GLOBALS['csp_nonce']) ? ' nonce="' . htmlspecialchars($GLOBALS['csp_nonce']) . '"' : '' ?>>
(function () {
  /* Menu du compte — même geste que dans l'administration, mais le script de
     l'admin (admin-footer.php) n'est pas chargé ici : l'espace coureur ne
     partage que les feuilles de style, jamais les partials d'administration. */
  var puce = document.getElementById('ecAvatarBtn');
  var menu = document.getElementById('ecDropdown');
  if (!puce || !menu) return;

  function synchro() { puce.classList.toggle('is-open', menu.classList.contains('show')); }

  puce.addEventListener('click', function (e) {
    e.stopPropagation();
    menu.classList.toggle('show');
    synchro();
  });
  document.addEventListener('click', function (e) {
    if (!menu.contains(e.target) && !puce.contains(e.target)) { menu.classList.remove('show'); synchro(); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { menu.classList.remove('show'); synchro(); }
  });
})();
</script>

<?php /* Confirmations et auto-soumission, compatibles CSP.
         La CSP du site interdit les gestionnaires en ligne : sans ce script,
         « Révoquer cet appareil ? » ne demanderait jamais rien. */ ?>
<?php include __DIR__ . '/../../src/partials/confirm-script.php'; ?>
