<?php
/**
 * _styles.php — Thème et styles communs aux pages de l'espace coureur.
 * Fragment à inclure dans le <head>. Voir _layout-haut.php pour l'en-tête.
 *
 * Le thème (clair / sombre / système) est propre au coureur : il vient de
 * `participants.theme` une fois connecté, et du localStorage avant. Il est posé
 * AVANT tout rendu, sinon la page s'afficherait une fraction de seconde en clair
 * avant de basculer — un clignotement blanc désagréable en pleine nuit.
 */
$ecTheme = $_SESSION[PAUTH_SESSION_KEY]['theme'] ?? null;
if (!in_array($ecTheme, PAUTH_THEMES, true)) $ecTheme = null;
?>
<script<?= isset($GLOBALS['csp_nonce']) ? ' nonce="' . htmlspecialchars($GLOBALS['csp_nonce']) . '"' : '' ?>>
(function () {
  var t = <?= $ecTheme !== null ? json_encode($ecTheme) : 'null' ?>;
  if (t === null) { try { t = localStorage.getItem('<?= PAUTH_THEME_KEY ?>'); } catch (e) {} }
  if (t !== 'dark' && t !== 'system') t = 'light';
  document.documentElement.setAttribute('data-theme', t);
  /* On mémorise aussi côté navigateur : la page de connexion, qui n'a pas encore
     de session, s'appuie dessus pour éviter le clignotement. */
  try { localStorage.setItem('<?= PAUTH_THEME_KEY ?>', t); } catch (e) {}
})();
</script>
<style>
  /* Palette claire par défaut. Le sombre ne fait que redéfinir ces variables :
     une seule feuille de styles, pas deux jeux de règles à tenir accordés. */
  :root{
    --ec-bg:#f8f7f9; --ec-card:#ffffff; --ec-ink:#0f172a; --ec-dim:#64748b;
    --ec-border:#f0e8eb; --ec-soft:#f1f5f9; --ec-soft-ink:#475569;
    --ec-rose:#F42182; --ec-rose-soft:#fdf2f8; --ec-rose-ink:#9d174d;
    --ec-ok:#ecfdf5; --ec-ok-ink:#047857;
    --ec-warn:#fffbeb; --ec-warn-ink:#92400e;
    --ec-err:#fef2f2; --ec-err-ink:#991b1b;
    --ec-info:#eff6ff; --ec-info-ink:#1e40af;
    --ec-ombre:0 2px 12px rgba(0,0,0,.06);
  }
  html[data-theme="dark"]{
    --ec-bg:#0b1020; --ec-card:#141b2e; --ec-ink:#e6ebf5; --ec-dim:#94a3b8;
    --ec-border:#1f2a44; --ec-soft:#1c2540; --ec-soft-ink:#cbd5e1;
    --ec-rose:#f472b6; --ec-rose-soft:#2a1626; --ec-rose-ink:#f9a8d4;
    --ec-ok:#0d2a20; --ec-ok-ink:#6ee7b7;
    --ec-warn:#2b1f0a; --ec-warn-ink:#fcd34d;
    --ec-err:#2a1113; --ec-err-ink:#fca5a5;
    --ec-info:#101f38; --ec-info-ink:#93c5fd;
    --ec-ombre:0 2px 14px rgba(0,0,0,.5);
  }
  @media (prefers-color-scheme: dark){
    html[data-theme="system"]{
      --ec-bg:#0b1020; --ec-card:#141b2e; --ec-ink:#e6ebf5; --ec-dim:#94a3b8;
      --ec-border:#1f2a44; --ec-soft:#1c2540; --ec-soft-ink:#cbd5e1;
      --ec-rose:#f472b6; --ec-rose-soft:#2a1626; --ec-rose-ink:#f9a8d4;
      --ec-ok:#0d2a20; --ec-ok-ink:#6ee7b7;
      --ec-warn:#2b1f0a; --ec-warn-ink:#fcd34d;
      --ec-err:#2a1113; --ec-err-ink:#fca5a5;
      --ec-info:#101f38; --ec-info-ink:#93c5fd;
      --ec-ombre:0 2px 14px rgba(0,0,0,.5);
    }
  }

  html { background:var(--ec-bg); }
  .ec-page   { max-width:820px; margin:0 auto; padding:26px 16px 48px; }
  .ec-h1     { font-size:1.35rem; font-weight:700; margin:0 0 4px; color:var(--ec-ink); }
  .ec-sub    { font-size:.9rem; color:var(--ec-dim); margin:0 0 24px; }
  .ec-h2     { font-size:1rem; font-weight:700; color:var(--ec-ink); margin:26px 0 10px;
               display:flex; align-items:center; gap:8px; }
  .ec-card   { background:var(--ec-card); border-radius:14px; box-shadow:var(--ec-ombre);
               padding:16px 18px; margin-bottom:12px; color:var(--ec-ink); }
  .ec-row    { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; }
  .ec-no     { font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-weight:700; color:var(--ec-rose); }
  .ec-nom    { font-weight:700; font-size:1rem; }
  .ec-meta   { font-size:.82rem; color:var(--ec-dim); margin-top:3px; }
  .ec-tag    { display:inline-block; font-size:.72rem; font-weight:700; padding:2px 9px;
               border-radius:999px; background:var(--ec-soft); color:var(--ec-soft-ink); }
  .ec-tag-ok   { background:var(--ec-ok);   color:var(--ec-ok-ink); }
  .ec-tag-att  { background:var(--ec-warn); color:var(--ec-warn-ink); }
  .ec-tag-rose { background:var(--ec-rose-soft); color:var(--ec-rose-ink); }
  .ec-btn    { display:inline-flex; align-items:center; gap:7px; border:0; cursor:pointer;
               border-radius:.55rem; padding:.55rem 1rem; font-size:.86rem; font-weight:700;
               background:linear-gradient(135deg,#F42182,#db2777); color:#fff; text-decoration:none; }
  .ec-btn:hover { opacity:.92; }
  .ec-btn-sec { background:var(--ec-soft); color:var(--ec-soft-ink); }
  .ec-btn-danger { background:var(--ec-err); color:var(--ec-err-ink); }
  .ec-btn-danger:hover { filter:brightness(1.08); }
  .ec-alert  { border-radius:.6rem; padding:.85rem 1rem; font-size:.88rem;
               margin-bottom:16px; line-height:1.6; }
  .ec-info   { background:var(--ec-info); color:var(--ec-info-ink); border:1px solid transparent; }
  .ec-ok     { background:var(--ec-ok);   color:var(--ec-ok-ink);   border:1px solid transparent; }
  .ec-warn   { background:var(--ec-warn); color:var(--ec-warn-ink); border:1px solid transparent; }
  .ec-err    { background:var(--ec-err);  color:var(--ec-err-ink);  border:1px solid transparent; }
  .ec-groupe { border-left:4px solid var(--ec-rose); }
  .ec-groupe-tete { font-size:.82rem; font-weight:700; color:var(--ec-rose-ink); margin-bottom:10px;
                    display:flex; align-items:center; gap:7px; }
  .ec-dl     { display:grid; grid-template-columns:auto 1fr; gap:6px 18px; font-size:.88rem; margin:0; }
  .ec-dl dt  { color:var(--ec-dim); }
  .ec-dl dd  { margin:0; font-weight:600; }
  .ec-qr     { text-align:center; padding:8px 0 4px; }
  /* Fond blanc imposé sous le QR : en thème sombre, un QR noir sur fond sombre
     n'est plus lisible par un lecteur. */
  .ec-qr img { width:200px; height:200px; image-rendering:pixelated;
               background:#fff; padding:8px; border-radius:8px; }
  .ec-actions{ display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; }
  .ec-input  { padding:.6rem .8rem; border:1px solid var(--ec-border); border-radius:.55rem;
               background:var(--ec-card); color:var(--ec-ink); font-size:.9rem; }

  /* Sélecteur de thème (Mon compte) */
  .ec-theme-seg { display:inline-flex; gap:4px; background:var(--ec-soft); padding:4px; border-radius:.6rem; }
  .ec-theme-seg button { display:inline-flex; align-items:center; gap:6px; border:0; cursor:pointer;
                         background:transparent; color:var(--ec-soft-ink); font:inherit;
                         font-size:.84rem; font-weight:600; padding:.4rem .8rem; border-radius:.45rem; }
  .ec-theme-seg button.is-active { background:var(--ec-card); color:var(--ec-ink); box-shadow:var(--ec-ombre); }

  @media (max-width:520px){ .ec-dl { grid-template-columns:1fr; gap:2px 0; } .ec-dl dd { margin-bottom:8px; } }
</style>
