<?php
/**
 * _styles.php — Styles communs aux pages de l'espace coureur.
 * Fragment inclus dans le <head>. Voir _layout-haut.php pour le reste.
 */
?>
<style>
  .ec-page   { max-width:820px; margin:0 auto; padding:26px 16px 48px; }
  .ec-h1     { font-size:1.35rem; font-weight:700; margin:0 0 4px; }
  .ec-sub    { font-size:.9rem; color:#64748b; margin:0 0 24px; }
  .ec-h2     { font-size:1rem; font-weight:700; color:#0f172a; margin:26px 0 10px;
               display:flex; align-items:center; gap:8px; }
  .ec-card   { background:#fff; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,.06);
               padding:16px 18px; margin-bottom:12px; }
  .ec-row    { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; }
  .ec-no     { font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-weight:700; color:#F42182; }
  .ec-nom    { font-weight:700; font-size:1rem; }
  .ec-meta   { font-size:.82rem; color:#64748b; margin-top:3px; }
  .ec-tag    { display:inline-block; font-size:.72rem; font-weight:700; padding:2px 9px;
               border-radius:999px; background:#f1f5f9; color:#475569; }
  .ec-tag-ok   { background:#ecfdf5; color:#047857; }
  .ec-tag-att  { background:#fffbeb; color:#92400e; }
  .ec-tag-rose { background:#fdf2f8; color:#9d174d; }
  .ec-btn    { display:inline-flex; align-items:center; gap:7px; border:0; cursor:pointer;
               border-radius:.55rem; padding:.55rem 1rem; font-size:.86rem; font-weight:700;
               background:linear-gradient(135deg,#F42182,#db2777); color:#fff; text-decoration:none; }
  .ec-btn:hover { opacity:.92; }
  .ec-btn-sec { background:#f1f5f9; color:#475569; }
  .ec-btn-danger { background:#fef2f2; color:#b91c1c; }
  .ec-btn-danger:hover { background:#fee2e2; }
  .ec-alert  { border-radius:.6rem; padding:.85rem 1rem; font-size:.88rem;
               margin-bottom:16px; line-height:1.6; }
  .ec-info   { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; }
  .ec-ok     { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
  .ec-warn   { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
  .ec-err    { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
  .ec-groupe { border-left:4px solid #F42182; }
  .ec-groupe-tete { font-size:.82rem; font-weight:700; color:#9d174d; margin-bottom:10px;
                    display:flex; align-items:center; gap:7px; }
  .ec-dl     { display:grid; grid-template-columns:auto 1fr; gap:6px 18px; font-size:.88rem; margin:0; }
  .ec-dl dt  { color:#64748b; }
  .ec-dl dd  { margin:0; font-weight:600; }
  .ec-qr     { text-align:center; padding:8px 0 4px; }
  .ec-qr img { width:200px; height:200px; image-rendering:pixelated; }
  .ec-actions{ display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; }
  @media (max-width:520px){ .ec-dl { grid-template-columns:1fr; gap:2px 0; } .ec-dl dd { margin-bottom:8px; } }
</style>
