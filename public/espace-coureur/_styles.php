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

/* Accent : résolu par pauth_accentVars(), la même fonction que celle utilisée
   par les pages d'authentification — une seule définition pour tout l'espace. */
$ecAccentRes = pauth_accentVars($pdo);
$ecVars      = $ecAccentRes['vars'];
$ecDataAcc   = $ecAccentRes['data'];

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
<?php /* admin.css porte la coquille .jr-shell / .jr-nav / .jr-main : c'est elle
         qui donne à l'espace coureur la barre latérale de l'administration,
         y compris son repli sous 991px. */ ?>
<link rel="stylesheet" href="<?= $ecV('css/admin.css') ?>">
<?php if ($ecDataAcc !== null): ?>
<script<?= isset($GLOBALS['csp_nonce']) ? ' nonce="' . htmlspecialchars($GLOBALS['csp_nonce']) . '"' : '' ?>>
document.documentElement.setAttribute('data-accent', <?= json_encode($ecDataAcc) ?>);
</script>
<?php endif; ?>
<style>
<?php if ($ecVars !== null): ?>
  /* Accent dérivé côté serveur : rendu juste dès le premier octet, sans le
     clignotement d'une couleur appliquée après coup en JavaScript. */
  :root{
    --accent-l:<?= $ecVars[0] ?>; --accent-l-strong:<?= $ecVars[1] ?>; --accent-l-ink:<?= $ecVars[2] ?>;
    --accent-d:<?= $ecVars[3] ?>; --accent-d-strong:<?= $ecVars[4] ?>; --accent-d-ink:<?= $ecVars[5] ?>;
  }
<?php endif; ?>
  /* Rien que l'ossature de contenu — la coquille vient d'admin.css, les
     composants de components.css. */
  .ec-stack { display: flex; flex-direction: column; gap: var(--sp-4); }

  /* Choix de la couleur d'accent — calqué sur .accent-option du profil admin. */
  .ec-accents { display: flex; flex-wrap: wrap; gap: var(--sp-2); }
  .ec-accent {
    display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
    padding: 0.45rem 0.8rem; border-radius: var(--radius-m);
    border: 1px solid var(--border-strong); background: var(--surface);
    color: var(--ink-dim); font: inherit; font-size: var(--fs-small); font-weight: 550;
    transition: border-color var(--dur-fast) var(--ease-out), color var(--dur-fast) var(--ease-out);
  }
  .ec-accent:hover { border-color: var(--accent); color: var(--ink); }
  .ec-accent.is-active { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); }
  .ec-accent .dot { width: 14px; height: 14px; border-radius: 50%; flex: none;
                    box-shadow: inset 0 0 0 1px rgba(0,0,0,.12); }
  /* Le sélecteur natif est masqué : c'est la pastille entière qui l'ouvre. */
  .ec-accent input[type="color"] { position: absolute; opacity: 0; width: 0; height: 0; }
  .ec-mono  { font-family: var(--font-mono); font-weight: 600; color: var(--accent); }
  .ec-dl   { display: grid; grid-template-columns: auto 1fr; gap: var(--sp-2) var(--sp-5);
             font-size: var(--fs-small); margin: 0; }
  .ec-dl dt { color: var(--ink-faint); }
  .ec-dl dd { margin: 0; color: var(--ink); font-weight: 550; }

  /* Fond blanc imposé sous le QR : en thème sombre, un QR noir sur fond sombre
     n'est plus lisible par un lecteur. */
  .ec-qr { display: grid; place-items: center; gap: var(--sp-3); }
  .ec-qr img { width: 200px; height: 200px; image-rendering: pixelated;
               background: #fff; padding: 10px; border-radius: var(--radius-m); }

  /* Détail de l'inscription et QR code côte à côte sur ordinateur. La colonne
     du QR est fixe (320 px) : le QR fait 200 px et ne gagne rien à s'élargir,
     alors que la liste des champs, elle, profite de toute la place restante.
     align-items:start empêche la carte du QR de s'étirer inutilement à la
     hauteur de sa voisine. */
  /* Les deux cartes montent à la MÊME hauteur : `align-items: start` les
     laissait chacune à sa taille propre, et la carte du QR s'arrêtait bien
     au-dessus de sa voisine — deux blocs de hauteurs différentes côte à côte,
     ça se voit tout de suite. Le défaut de la grille (stretch) suffit ; il
     restait à faire descendre la carte jusqu'en bas et à recentrer le QR dans
     l'espace ainsi gagné. */
  .ec-duo { display: grid; gap: var(--sp-4); }
  @media (min-width: 900px) {
    .ec-duo { grid-template-columns: minmax(0, 1fr) 320px; }
    .ec-duo > .card { height: 100%; }
    /* Le QR se place au centre du vide plutôt que collé en haut. */
    .ec-duo .ec-qr { flex: 1; align-content: center; }
  }

  /* Deux champs par ligne dès qu'il y a la place (prénom/nom, sexe/âge). */
  .ec-grid2 { display: grid; gap: var(--sp-3); }
  @media (min-width: 560px) { .ec-grid2 { grid-template-columns: 1fr 1fr; } }

  /* Formulaire de correction, replié par défaut. */
  .ec-edit { border-top: 1px solid var(--border); padding-top: var(--sp-4); margin-top: var(--sp-2); }
  .ec-edit > summary {
    cursor: pointer; list-style: none; display: inline-flex; align-items: center; gap: 8px;
    font-size: var(--fs-small); font-weight: 550; color: var(--accent);
  }
  .ec-edit > summary::-webkit-details-marker { display: none; }
  .ec-edit > summary:hover { text-decoration: underline; }
  .ec-edit[open] > summary { margin-bottom: var(--sp-4); }

  @media (max-width: 640px) {
    .ec-dl { grid-template-columns: 1fr; gap: 0; }
    .ec-dl dd { margin-bottom: var(--sp-2); }
  }
</style>
