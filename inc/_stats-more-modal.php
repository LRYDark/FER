<?php
/**
 * Modal « Autres statistiques » — partagé par le dashboard et la page Statistiques.
 * Le corps (#statsMoreBody) est rempli côté client par js/reg-stats.js
 * (FERRegStats.renderModalBody). Aucune donnée n'est rendue ici : le même modal
 * sert à la saison en cours (dashboard) et à une archive (page stats).
 */
?>
<style nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
  /* Cartes de stats partagées (dashboard + page stats + modal). Style unique. */
  .statCard{min-width:180px}
  .statCard .card-body{display:flex;flex-direction:column;justify-content:center;min-height:104px;padding:1rem .75rem}
  .statCard .stat-label{font-size:.78rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.03em;line-height:1.25;margin:0 0 .4rem;min-height:2.2em;display:flex;align-items:center;justify-content:center}
  .statCard .stat-value{font-size:1.85rem;font-weight:700;line-height:1.1;color:#1e293b;margin:0}
  .statCard .stat-value--sm{font-size:1rem;font-weight:600;line-height:1.3}
  /* Stats secondaires du modal : sections titrées + lignes « libellé → valeur ». */
  .reg-stats-section{margin-bottom:1.25rem}
  .reg-stats-section:last-child{margin-bottom:0}
  .reg-stats-section-title{font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--bs-primary,#db2777);margin:0 0 .45rem}
  .reg-stats-list{border:1px solid #eef2f7;border-radius:.65rem;overflow:hidden}
  .reg-stats-row{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.55rem .85rem;min-height:2.4rem}
  .reg-stats-row:nth-child(even){background:#f8fafc}
  .reg-stats-row-label{font-size:.86rem;color:#475569;font-weight:500}
  .reg-stats-row-value{font-size:.9rem;font-weight:700;color:#1e293b;text-align:right}
  .reg-stats-rank{color:var(--bs-primary,#db2777);font-weight:700}
</style>
<div class="modal fade" id="statsMoreModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-bar-chart-line me-2"></i>Toutes les statistiques</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <div id="statsMoreBody"></div>
      </div>
    </div>
  </div>
</div>
