<?php
/**
 * Écran de chargement des pages publiques — Forbach en Rose
 *
 * Logo du site + reflet de lumière + fine barre de progression rose.
 * Comportement adaptatif :
 *   - la page charge en arrière-plan ; la barre avance en « trickle » et
 *     saute à 100 % dès que la page est prête (window load) ;
 *   - chargement très rapide (< 250 ms, cache) → le loader est retiré
 *     immédiatement, l'utilisateur ne le voit même pas ;
 *   - s'il s'affiche, il reste au moins 600 ms (anti-clignotement) ;
 *   - filet de sécurité : jamais plus de 4 s, même si un élément traîne.
 *
 * Inclus juste après <body> sur chaque page publique. Autonome (CSS + JS
 * inline avec nonce CSP), aucune requête supplémentaire : le logo est celui
 * de la navbar (déjà en cache navigateur).
 */

// Jamais dans l'iframe de l'éditeur visuel (rechargements fréquents)
if (!empty($isEditorMode) || isset($_GET['editor'])) return;

$plLogo = (isset($navbar_logo) && $navbar_logo) ? $navbar_logo : 'logo_fer_rose.png';
$plLogoOk = file_exists(dirname(__DIR__, 2) . '/files/_logos/' . $plLogo);
$plNonce = $GLOBALS['csp_nonce'] ?? '';
?>
<div id="ferPreloader" aria-hidden="true">
  <div class="fer-load-brand">
    <?php if ($plLogoOk): ?>
    <img src="../files/_logos/<?= rawurlencode($plLogo) ?>" alt="">
    <?php else: ?>
    <strong class="fer-load-name">Forbach en Rose</strong>
    <?php endif; ?>
  </div>
  <div class="fer-load-bar" role="progressbar" aria-label="Chargement de la page"><i></i></div>
</div>
<style nonce="<?= htmlspecialchars($plNonce) ?>">
#ferPreloader {
  position: fixed;
  inset: 0;
  z-index: 100000; /* au-dessus de tout, chatbot compris */
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 34px;
  background: #fff;
  transition: opacity .5s ease;
}
body.dark-theme #ferPreloader { background: #15161d; }
#ferPreloader.done { opacity: 0; pointer-events: none; }
#ferPreloader.fer-fast { transition-duration: .18s; } /* sortie éclair (page rapide) */
#ferPreloader .fer-load-brand {
  position: relative;
  overflow: hidden;
  animation: ferBrandIn .9s cubic-bezier(.22, .8, .3, 1) both;
}
#ferPreloader .fer-load-brand img {
  display: block;
  width: min(250px, 62vw);
  height: auto;
}
#ferPreloader .fer-load-name { font-size: 26px; color: #db2777; letter-spacing: -.02em; }
/* Reflet : bande de lumière inclinée qui balaye le logo en boucle (avec pause) */
#ferPreloader .fer-load-brand::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(105deg, transparent 38%, rgba(255, 255, 255, .85) 50%, transparent 62%);
  transform: translateX(-120%);
  animation: ferShine 2.2s ease-in-out .5s infinite;
}
body.dark-theme #ferPreloader .fer-load-brand::after {
  background: linear-gradient(105deg, transparent 38%, rgba(255, 255, 255, .22) 50%, transparent 62%);
}
@keyframes ferBrandIn {
  from { opacity: 0; transform: translateY(10px) scale(.96); }
  to   { opacity: 1; transform: none; }
}
/* 0→65% : balayage ; 65→100% : pause hors champ */
@keyframes ferShine {
  0%   { transform: translateX(-120%); }
  65%  { transform: translateX(120%); }
  100% { transform: translateX(120%); }
}
#ferPreloader .fer-load-bar {
  width: 190px;
  height: 3px;
  border-radius: 99px;
  background: rgba(219, 39, 119, .14);
  overflow: hidden;
}
#ferPreloader .fer-load-bar i {
  display: block;
  height: 100%;
  width: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, #db2777, #f472b6);
  transform: translateX(-100%);
  transition: transform .22s linear;
}
@media (prefers-reduced-motion: reduce) {
  #ferPreloader .fer-load-brand,
  #ferPreloader .fer-load-brand::after { animation: none; }
  #ferPreloader .fer-load-brand { opacity: 1; }
}
</style>
<script nonce="<?= htmlspecialchars($plNonce) ?>">
(function () {
  'use strict';
  var root = document.getElementById('ferPreloader');
  if (!root) return;
  var bar = root.querySelector('.fer-load-bar i');
  var start = Date.now();
  var INSTANT_MS = 120;   // prêt avant → retiré net, jamais perçu
  var FAST_MS    = 600;   // prêt avant → fondu éclair, sans pause de barre
  var MAX_MS     = 4000;  // filet de sécurité
  var prog = 0, removed = false;

  function setP(p) {
    prog = Math.max(prog, Math.min(p, 100));
    if (bar) bar.style.transform = 'translateX(' + (prog - 100) + '%)';
  }
  // Trickle : avance vite au début puis ralentit en approchant 90 % —
  // la barre saute à 100 % dès que la page est réellement prête.
  var trickle = setInterval(function () { setP(prog + (90 - prog) * 0.16); }, 150);

  /* Trois paliers, du plus rapide au plus lent :
     - instant : suppression sèche (le visiteur n'a rien vu) ;
     - fast    : fondu éclair (.18 s), pas de pause pour la barre ;
     - normal  : la barre touche 100 % (~0.12 s) puis fondu doux. */
  function remove(mode) {
    if (removed) return;
    removed = true;
    clearInterval(trickle);
    setP(100);
    if (mode === 'instant') {
      root.style.display = 'none';
      if (root.parentNode) root.parentNode.removeChild(root);
      return;
    }
    if (mode === 'fast') root.classList.add('fer-fast');
    setTimeout(function () {
      root.classList.add('done');
      setTimeout(function () { if (root.parentNode) root.parentNode.removeChild(root); },
                 mode === 'fast' ? 200 : 520);
    }, mode === 'fast' ? 0 : 120);
  }

  function onReady() {
    var elapsed = Date.now() - start;
    remove(elapsed < INSTANT_MS ? 'instant' : (elapsed < FAST_MS ? 'fast' : 'normal'));
  }
  if (document.readyState === 'complete') onReady();
  else window.addEventListener('load', onReady);
  setTimeout(function () { remove('normal'); }, MAX_MS);
})();
</script>
