<?php
/**
 * confirm-script.php — Demandes de confirmation, compatibles avec la CSP.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * POURQUOI CE FICHIER EXISTE
 *
 * La politique de sécurité de contenu du site (src/core/config.php) autorise
 *   script-src 'self' 'nonce-…'
 * SANS 'unsafe-inline'. Le navigateur bloque donc TOUS les gestionnaires
 * d'événements écrits dans le HTML : onsubmit="return confirm(…)",
 * onclick="…", onchange="…". Ils ne s'exécutent jamais.
 *
 * Le piège est qu'ils échouent EN SILENCE : aucune erreur visible à l'écran,
 * juste un avertissement dans la console. Un formulaire censé demander
 * « êtes-vous sûr ? » part donc directement — c'est exactement ce qui se
 * passait sur l'envoi d'un code de connexion, et sur toutes les autres
 * confirmations du site.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * COMMENT S'EN SERVIR
 *
 *   <form method="post" data-confirm="Envoyer le code à marie@exemple.fr ?">
 *   <button data-confirm="Supprimer définitivement ?">
 *   <a href="…" data-confirm="Quitter sans enregistrer ?">
 *
 * L'écoute est déléguée au document et posée en phase de CAPTURE : elle passe
 * donc avant tout autre gestionnaire, y compris ceux d'une bibliothèque tierce
 * qui soumettrait le formulaire elle-même.
 *
 * ⚠️ NE JAMAIS revenir à un attribut onsubmit/onclick : il serait bloqué, et le
 * garde-fou disparaîtrait sans que personne s'en aperçoive.
 */
?>
<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
(function () {
  'use strict';

  function demander(el) {
    var message = el.getAttribute('data-confirm');
    return !message || window.confirm(message);
  }

  /* Soumission d'un formulaire porteur de data-confirm. */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form && form.hasAttribute && form.hasAttribute('data-confirm') && !demander(form)) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, true);

  /* Champ qui soumet son formulaire dès qu'il change (sélecteur de couleur,
     liste déroulante de filtre…). Remplace onchange="this.form.submit()", que
     la CSP bloque — et qui échouait donc en silence. */
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (el && el.hasAttribute && el.hasAttribute('data-autosubmit') && el.form) {
      el.form.submit();
    }
  }, true);

  /* Clic sur un bouton ou un lien porteur de data-confirm.
     Le bouton est traité séparément du formulaire : dans une même ligne de
     tableau, deux boutons peuvent demander deux confirmations différentes. */
  document.addEventListener('click', function (e) {
    var el = e.target && e.target.closest
           ? e.target.closest('[data-confirm]')
           : null;
    if (!el) return;
    if (el.tagName !== 'BUTTON' && el.tagName !== 'A' && el.tagName !== 'INPUT') return;
    if (!demander(el)) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, true);
})();
</script>
