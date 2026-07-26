<?php
/**
 * api-doc-styles.php — Habillage commun aux deux documentations d'API.
 *
 * Les deux pages (inc/api-doc.php pour l'API partenaire, inc/api-doc-mobile.php
 * pour l'API des coureurs) partagent la même présentation. Ce fichier existe
 * pour qu'elles ne divergent pas : deux copies du même CSS finissent toujours
 * par se ressembler « à peu près », et la page la moins souvent ouverte est
 * celle qui prend du retard.
 */
?>
<style>
  body { background:var(--surface-2); }
  .api-doc h2 { color:#880e4f; font-weight:700; margin-top:2.5rem; }
  .api-doc h3 { color:#c4577a; font-weight:600; margin-top:1.75rem; font-size:1.2rem; }
  .api-code {
    background:#1e1e2e; color:#e4e4ef; padding:1rem 1.15rem; border-radius:10px;
    font-size:.85rem; overflow-x:auto; margin:.6rem 0 1rem;
  }
  .api-code code { color:inherit; background:none; white-space:pre; }
  .api-card {
    background: var(--surface); border:1px solid var(--border); border-radius:14px;
    padding:1.5rem; margin-bottom:1.5rem;
  }
  .endpoint-badge {
    display:inline-block; font-weight:700; font-size:.78rem; padding:.2rem .6rem;
    border-radius:6px; margin-right:.5rem; font-family:monospace;
  }
  .m-get    { background:#d1fae5; color: var(--ok); }
  .m-post   { background: color-mix(in srgb, var(--info) 15%, var(--surface));   color: var(--info); }
  .m-patch  { background: color-mix(in srgb, var(--warn) 18%, var(--surface));   color: var(--warn); }
  .m-delete { background: color-mix(in srgb, var(--danger) 15%, var(--surface)); color: var(--danger); }
  .api-toc a { color:#880e4f; text-decoration:none; }
  .api-toc a:hover { text-decoration:underline; }
  table.api-params td, table.api-params th { font-size:.88rem; vertical-align:top; }
  .url-pill { background: var(--accent-soft); color:#880e4f; padding:.15rem .5rem; border-radius:6px; font-family:monospace; font-size:.85rem; }
  /* navbar-admin.php applique « display:flex » à toutes les alertes
     (#oc-content .alert) : ici les alertes contiennent du texte riche
     multi-lignes, on rétablit donc un affichage en flux normal. */
  #oc-content .api-doc .alert { display:block; }
  /* Les <code> dans les alertes : puce blanche lisible sur tout fond
     (y compris le fond rouge de alert-danger). */
  #oc-content .api-doc .alert code {
    background: var(--surface); color: var(--ink);
    padding:.05rem .35rem; border-radius:4px;
    font-size:.85em; word-break:break-word;
  }
  #oc-content .api-doc .alert a { color:inherit; font-weight:600; }
  /* Les tableaux restent lisibles sur mobile (défilement horizontal si besoin) */
  .api-card .table-responsive { margin-bottom:0; }
  table.api-params { width:100%; }
  /* Schéma de flux : police à chasse fixe, défilement horizontal si l'écran
     est trop étroit — un diagramme qui déborde vaut mieux qu'un diagramme
     recomposé n'importe comment. */
  .api-flow {
    background: var(--surface-2); border:1px solid var(--border); border-radius:10px;
    padding:1rem; overflow-x:auto; font-family:var(--font-mono, monospace);
    font-size:.8rem; line-height:1.55; white-space:pre; margin:.6rem 0 1rem;
  }
  /* Adaptation mobile */
  @media (max-width: 576px) {
    .api-card { padding:1.1rem; }
    .api-doc h1 { font-size:1.5rem; }
    .api-doc h2 { font-size:1.25rem; }
    .api-doc h3 { font-size:1.05rem; }
    .api-code, .api-flow { font-size:.76rem; padding:.8rem .9rem; }
    .api-card table { display:block; overflow-x:auto; white-space:normal; }
  }
</style>
