<?php
/**
 * _styles.php — Feuilles de styles et thème des pages de l'espace coureur.
 * Fragment à inclure dans le <head>.
 *
 * ⚠️ AUCUN style inventé ici. On charge le MÊME système de design que
 * l'administration (css/tokens.css, base.css, components.css, app.css) et on
 * n'utilise que ses composants : .card, .rows/.row, .pill, .btn, .field,
 * .input, .seg, .iconwell. Les quelques règles ci-dessous ne font que poser
 * l'ossature de page (en-tête et largeur), toujours à partir des mêmes jetons —
 * couleurs, espacements et rayons viennent de tokens.css, jamais de valeurs
 * écrites à la main. Le thème sombre suit donc automatiquement.
 *
 * Le thème (clair / sombre / système) est propre au coureur : il vient de
 * `participants.theme` une fois connecté, du localStorage avant. Il est posé
 * AVANT tout rendu, sinon la page clignoterait en blanc avant de basculer.
 */
$ecTheme = $_SESSION[PAUTH_SESSION_KEY]['theme'] ?? null;
if (!in_array($ecTheme, PAUTH_THEMES, true)) $ecTheme = null;

$ecV = function (string $rel): string {
    $p = dirname(__DIR__, 2) . '/' . $rel;
    return '../../' . $rel . '?v=' . (@filemtime($p) ?: '1');
};
?>
<script<?= isset($GLOBALS['csp_nonce']) ? ' nonce="' . htmlspecialchars($GLOBALS['csp_nonce']) . '"' : '' ?>>
(function () {
  var t = <?= $ecTheme !== null ? json_encode($ecTheme) : 'null' ?>;
  if (t === null) { try { t = localStorage.getItem('<?= PAUTH_THEME_KEY ?>'); } catch (e) {} }
  if (t !== 'dark' && t !== 'system') t = 'light';
  document.documentElement.setAttribute('data-theme', t);
  var sombre = t === 'dark' || (t === 'system' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.style.backgroundColor = sombre ? '#05070D' : '#E9EDF4';
  /* Mémorisé côté navigateur : la page de connexion, qui n'a pas encore de
     session, s'appuie dessus pour éviter le clignotement. */
  try { localStorage.setItem('<?= PAUTH_THEME_KEY ?>', t); } catch (e) {}
})();
</script>
<link rel="stylesheet" href="<?= $ecV('css/tokens.css') ?>">
<link rel="stylesheet" href="<?= $ecV('css/base.css') ?>">
<link rel="stylesheet" href="<?= $ecV('css/components.css') ?>">
<link rel="stylesheet" href="<?= $ecV('css/app.css') ?>">
<style>
  /* Ossature de page uniquement — tout le reste vient des composants. */
  body { background: var(--canvas); }
  .ec-shell { max-width: 880px; margin: 0 auto; padding: var(--sp-5) var(--sp-4) var(--sp-7); }
  .ec-stack { display: flex; flex-direction: column; gap: var(--sp-4); }

  .ec-topbar {
    display: flex; align-items: center; justify-content: space-between;
    gap: var(--sp-4); flex-wrap: wrap;
    padding: var(--sp-3) var(--sp-4);
    background: var(--surface); border-bottom: 1px solid var(--border);
  }
  .ec-brand { display: inline-flex; align-items: center; gap: 10px;
              color: var(--ink); font-weight: 650; font-size: var(--fs-small); text-decoration: none; }
  .ec-brand img { height: 28px; width: auto; }

  .ec-tabs { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
  .ec-tabs a {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 0.45rem 0.8rem; border-radius: var(--radius-m);
    color: var(--ink-dim); font-size: var(--fs-small); font-weight: 550; text-decoration: none;
  }
  .ec-tabs a:hover { background: var(--surface-2); color: var(--ink); }
  .ec-tabs a.is-active { background: var(--accent-soft); color: var(--accent); }

  .ec-head { display: flex; flex-direction: column; gap: 2px; margin-bottom: var(--sp-2); }
  .ec-head h1 { font-size: var(--fs-h1); font-weight: 650; color: var(--ink); }
  .ec-head p  { font-size: var(--fs-small); color: var(--ink-faint); }

  .ec-mono { font-family: var(--font-mono); font-weight: 600; color: var(--accent); }
  .ec-dl   { display: grid; grid-template-columns: auto 1fr; gap: var(--sp-2) var(--sp-5);
             font-size: var(--fs-small); margin: 0; }
  .ec-dl dt { color: var(--ink-faint); }
  .ec-dl dd { margin: 0; color: var(--ink); font-weight: 550; }

  /* Fond blanc imposé sous le QR : en thème sombre, un QR noir sur fond sombre
     n'est plus lisible par un lecteur. */
  .ec-qr { display: grid; place-items: center; gap: var(--sp-3); }
  .ec-qr img { width: 200px; height: 200px; image-rendering: pixelated;
               background: #fff; padding: 10px; border-radius: var(--radius-m); }

  @media (max-width: 640px) {
    .ec-tabs a span { display: none; }
    .ec-tabs a { padding: 0.5rem 0.65rem; }
    .ec-dl { grid-template-columns: 1fr; gap: 0; }
    .ec-dl dd { margin-bottom: var(--sp-2); }
  }
</style>
