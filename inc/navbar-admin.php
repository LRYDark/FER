<?php
/**
 * Navbar Admin - Navigation moderne pour les pages d'administration
 * Desktop : barre horizontale scrollable / Mobile : offcanvas drawer
 * Includes: dark/light theme system + mobile action buttons
 */

$currentPage = basename($_SERVER['PHP_SELF']);

$pageTitles = [
    'dashboard.php' => 'Tableau de bord',
    'setting.php'   => 'Réglages',
    'albums.php'    => 'Albums',
    'partners.php'  => 'Partenaires',
    'news.php'      => 'Actualités',
    'stats.php'     => 'Statistiques',
    'qr_code.php'   => 'QR Code',
    'saisie.php'    => 'Saisie',
    'timeline.php'  => 'Timeline',
    'logs.php'      => 'Logs',
];

$pageTitle = $pageTitles[$currentPage] ?? 'Administration';

// Liens admin (clé => [label, icône SVG, rôles autorisés])
$adminLinks = [
    'dashboard.php' => ['Tableau de bord', '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>', ['admin','user','viewer','saisie']],
    'setting.php'   => ['Réglages',        '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>', ['admin']],
    'albums.php'    => ['Albums',           '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>', ['admin']],
    'partners.php'  => ['Partenaires',      '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>', ['admin']],
    'news.php'      => ['Actualités',       '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/>', ['admin']],
    'timeline.php'  => ['Timeline',         '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>', ['admin']],
    'qr_code.php'   => ['QR Code',          '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>', ['admin']],
    'logs.php'      => ['Logs',             '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>', ['admin']],
    'stats.php'     => ['Statistiques',     '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>', ['admin','user','viewer']],
];
?>

<!-- Anti-flash: appliquer le thème AVANT le rendu CSS -->
<script>
(function(){var t=localStorage.getItem('adm-theme');if(t!=='light')document.documentElement.classList.add('adm-dark');})();
</script>

<!-- ═══════ NAV ADMIN ═══════ -->
<header class="adm-header" id="navRoot">
  <div class="adm-bar">
    <!-- Logo -->
    <a class="adm-brand" href="dashboard.php">
      <img src="../files/_logos/logo_fer_rose.png" alt="Forbach en Rose">
    </a>

    <!-- Liens desktop -->
    <nav class="adm-links-wrap" id="admLinksWrap">
      <ul class="adm-links">
        <?php foreach ($adminLinks as $file => [$label, $icon, $roles]):
          if (!in_array($role, $roles)) continue;
        ?>
          <li>
            <a class="adm-link <?= $currentPage === $file ? 'active' : '' ?>" href="<?= $file ?>">
              <svg class="adm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icon ?></svg>
              <span><?= $label ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </nav>

    <!-- Boutons desktop -->
    <div class="adm-actions">
      <?php if($currentPage === 'dashboard.php'): ?>
        <button id="modeTS" class="adm-btn adm-btn-mode" type="button">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
          <span>Mode standard</span>
        </button>
      <?php endif; ?>
      <button id="adminLogout" class="adm-btn adm-btn-logout" type="button">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        <span>Déconnexion</span>
      </button>
    </div>

    <!-- Burger mobile -->
    <button class="adm-burger" id="admBurger" type="button" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
  </div>
</header>

<!-- ═══════ DRAWER MOBILE ═══════ -->
<div class="adm-overlay" id="admOverlay"></div>
<aside class="adm-drawer" id="admDrawer" aria-hidden="true">
  <div class="adm-drawer-head">
    <h5>Administration</h5>
    <button class="adm-drawer-close" id="admDrawerClose" aria-label="Fermer">&times;</button>
  </div>

  <div class="adm-drawer-info">
    <div class="adm-drawer-page"><?= htmlspecialchars($pageTitle) ?></div>
    <div class="adm-drawer-role">Rôle : <strong><?= htmlspecialchars($role) ?></strong></div>
  </div>

  <!-- Actions rapides (rempli par JS) -->
  <div class="adm-drawer-actions" id="admDrawerActions" style="display:none">
    <div class="adm-drawer-actions-title">Actions rapides</div>
    <div class="adm-drawer-actions-list" id="admDrawerActionsList"></div>
  </div>

  <nav class="adm-drawer-nav">
    <ul>
      <?php foreach ($adminLinks as $file => [$label, $icon, $roles]):
        if (!in_array($role, $roles)) continue;
      ?>
        <li>
          <a class="adm-drawer-link <?= $currentPage === $file ? 'active' : '' ?>" href="<?= $file ?>">
            <svg class="adm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icon ?></svg>
            <span><?= $label ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <div class="adm-drawer-footer">
    <?php if($currentPage === 'dashboard.php'): ?>
      <button id="modeTS_m" class="adm-drawer-btn adm-drawer-btn-mode" type="button">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
        Mode standard
      </button>
    <?php endif; ?>
    <button id="adminLogoutMobile" class="adm-drawer-btn adm-drawer-btn-logout" type="button">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Déconnexion
    </button>
  </div>
</aside>

<!-- ═══════ STYLES ═══════ -->
<style>
/* ══════════════════════════════════════════════════════════════
   THEME SYSTEM – Light (default) / Dark (html.adm-dark)
   ══════════════════════════════════════════════════════════════ */
:root {
  --adm-bg:       #f5f5f7;
  --adm-surface:  #fff;
  --adm-card:     #fff;
  --adm-elevated: #f1f5f9;
  --adm-border:   #e2e8f0;
  --adm-text:     #1e293b;
  --adm-text-dim: #475569;
  --adm-text-muted:#94a3b8;
  --adm-input-bg: #fff;
  --adm-input-border:#dee2e6;
  --adm-hover:    #f8fafc;
  --adm-accent:   #ec4899;
  --adm-accent-soft:rgba(236,72,153,.08);
  --adm-shadow:   rgba(0,0,0,.06);
}
html.adm-dark {
  --adm-bg:       #16171d;
  --adm-surface:  #1e1f28;
  --adm-card:     #1e1f28;
  --adm-elevated: #262730;
  --adm-border:   #2e2f3a;
  --adm-text:     #e2e4ed;
  --adm-text-dim: #9499b0;
  --adm-text-muted:#636880;
  --adm-input-bg: #1e1f28;
  --adm-input-border:#2e2f3a;
  --adm-hover:    #292a34;
  --adm-accent:   #ec4899;
  --adm-accent-soft:rgba(236,72,153,.15);
  --adm-shadow:   rgba(0,0,0,.35);
}

/* ══════════════════════════════════════════════════════════════
   ADMIN RESETS (always applied)
   ══════════════════════════════════════════════════════════════ */
body.d-flex.flex-column > .hero,
body > .hero,
.admin-tabs-bar { display: none !important; }
body {
  background: var(--adm-bg) !important;
  background-image: none !important;
  color: var(--adm-text) !important;
  padding-top: 0 !important;
  padding-bottom: 0 !important;
}
.mobile-header,
.mobile-bottom-bar,
.mobile-menu-popup,
.mobile-menu-backdrop,
.drawer-backdrop {
  display: none !important;
}

/* ══════════════════════════════════════════════════════════════
   NAVBAR (uses variables – works for both themes)
   ══════════════════════════════════════════════════════════════ */
.adm-header {
  background: var(--adm-surface);
  border-bottom: 1px solid var(--adm-border);
  box-shadow: 0 1px 8px var(--adm-shadow);
  position: fixed; top: 0; left: 0; right: 0;
  z-index: 1050;
}
body.d-flex.flex-column > main,
body > main {
  margin-top: 60px !important;
}
.adm-bar {
  display: flex; align-items: center; gap: 0.75rem;
  padding: 0 1rem; height: 60px; max-width: 100%;
}
.adm-brand { flex-shrink: 0; display: flex; align-items: center; }
.adm-brand img { height: 42px; width: auto; }

/* ── Liens desktop ── */
.adm-links-wrap {
  flex: 1; min-width: 0;
  overflow-x: auto; overflow-y: hidden;
  -webkit-overflow-scrolling: touch; scrollbar-width: none;
}
.adm-links-wrap::-webkit-scrollbar { display: none; }
.adm-links {
  display: flex; align-items: center;
  list-style: none; margin: 0; padding: 0; gap: 2px; white-space: nowrap;
}
.adm-link {
  display: flex; align-items: center; gap: 6px;
  padding: 0.5rem 0.85rem; border-radius: 50px;
  font-size: 0.85rem; font-weight: 500;
  color: var(--adm-text-dim); text-decoration: none;
  transition: all 0.15s; white-space: nowrap;
}
.adm-link:hover { background: var(--adm-accent-soft); color: var(--adm-accent); }
.adm-link.active {
  background: linear-gradient(135deg, #ec4899, #db2777);
  color: #fff; box-shadow: 0 2px 8px rgba(236,72,153,.25);
}
.adm-link.active .adm-icon { stroke: #fff; }
.adm-icon { width: 18px; height: 18px; flex-shrink: 0; stroke: var(--adm-text-muted); }
.adm-link:hover .adm-icon { stroke: var(--adm-accent); }

/* ── Boutons actions desktop ── */
.adm-actions { display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; margin-left: auto; }
.adm-btn {
  display: flex; align-items: center; gap: 6px;
  padding: 0.45rem 1rem; border-radius: 50px; border: none;
  font-size: 0.82rem; font-weight: 600; cursor: pointer;
  transition: all 0.2s; white-space: nowrap;
}
.adm-btn-mode {
  background: var(--adm-accent-soft); color: var(--adm-accent);
  border: 1px solid rgba(236,72,153,.25);
}
.adm-btn-mode:hover { background: rgba(236,72,153,.2); box-shadow: 0 3px 10px rgba(236,72,153,.15); }
.adm-btn-logout { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; }
.adm-btn-logout:hover { background: linear-gradient(135deg, #dc2626, #b91c1c); box-shadow: 0 3px 10px rgba(239,68,68,.25); }

/* ── Burger ── */
.adm-burger {
  display: none; flex-direction: column; justify-content: center;
  gap: 5px; background: none; border: none; padding: 8px;
  cursor: pointer; flex-shrink: 0; margin-left: auto;
}
.adm-burger span {
  display: block; width: 24px; height: 2.5px;
  background: var(--adm-text); border-radius: 2px; transition: all 0.2s;
}

/* ── Overlay ── */
.adm-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,.5);
  z-index: 1060; opacity: 0; pointer-events: none; transition: opacity 0.3s;
}
.adm-overlay.show { opacity: 1; pointer-events: auto; }

/* ── Drawer ── */
.adm-drawer {
  position: fixed; top: 0; right: -320px; width: 300px; max-width: 85vw;
  height: 100%; height: 100dvh;
  background: var(--adm-surface); z-index: 1070;
  display: flex; flex-direction: column;
  transition: right 0.3s ease;
  box-shadow: -4px 0 20px var(--adm-shadow); overflow: hidden;
}
.adm-drawer.open { right: 0; }
.adm-drawer-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1rem 1.25rem; border-bottom: 1px solid var(--adm-border); flex-shrink: 0;
}
.adm-drawer-head h5 { margin: 0; font-weight: 700; font-size: 1.1rem; color: var(--adm-text); }
.adm-drawer-close {
  background: none; border: none; font-size: 1.8rem;
  color: var(--adm-text-muted); cursor: pointer; line-height: 1; padding: 0;
}
.adm-drawer-close:hover { color: var(--adm-text); }
.adm-drawer-info {
  background: var(--adm-accent-soft); margin: 1rem;
  padding: 0.875rem 1rem; border-radius: 12px;
  border: 1px solid rgba(236,72,153,.25); flex-shrink: 0;
}
.adm-drawer-page { font-weight: 700; color: var(--adm-accent); font-size: 0.95rem; }
.adm-drawer-role { font-size: 0.8rem; color: var(--adm-accent); opacity: .7; margin-top: 2px; }
.adm-drawer-role strong { font-weight: 700; }

/* ── Drawer : actions rapides ── */
.adm-drawer-actions {
  padding: 0.5rem 1rem 0.75rem; border-bottom: 1px solid var(--adm-border); flex-shrink: 0;
}
.adm-drawer-actions-title {
  font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.5px; color: var(--adm-text-muted); margin-bottom: 0.5rem;
}
.adm-drawer-actions-list { display: flex; flex-wrap: wrap; gap: 6px; }
.adm-drawer-action-btn {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 0.4rem 0.8rem; border-radius: 50px; border: none;
  font-size: 0.78rem; font-weight: 600; cursor: pointer;
  transition: all 0.15s; color: #fff;
}
.adm-drawer-action-btn.btn-rose { background: linear-gradient(135deg, #ec4899, #db2777); }
.adm-drawer-action-btn.btn-success { background: #22c55e; }
.adm-drawer-action-btn.btn-secondary { background: #64748b; }
.adm-drawer-action-btn.btn-info { background: #0ea5e9; }
.adm-drawer-action-btn.btn-danger { background: #ef4444; }
.adm-drawer-action-btn.btn-warning { background: #f59e0b; color: #16171d; }
.adm-drawer-action-btn:active { transform: scale(.95); }

/* ── Drawer : nav links ── */
.adm-drawer-nav {
  flex: 1; min-height: 0; overflow-y: auto;
  -webkit-overflow-scrolling: touch; padding: 0.5rem 0;
}
.adm-drawer-nav ul { list-style: none; margin: 0; padding: 0; }
.adm-drawer-link {
  display: flex; align-items: center; gap: 10px;
  padding: 0.75rem 1.5rem; font-size: 0.92rem; font-weight: 500;
  color: var(--adm-text-dim); text-decoration: none; transition: all 0.15s;
}
.adm-drawer-link:hover { background: var(--adm-accent-soft); color: var(--adm-accent); }
.adm-drawer-link.active {
  background: var(--adm-accent-soft); color: var(--adm-accent);
  font-weight: 700; border-left: 3px solid var(--adm-accent);
}
.adm-drawer-link .adm-icon { width: 20px; height: 20px; }
.adm-drawer-link.active .adm-icon { stroke: var(--adm-accent); }

/* ── Drawer : footer ── */
.adm-drawer-footer {
  border-top: 1px solid var(--adm-border); padding: 1rem;
  display: flex; flex-direction: column; gap: 0.5rem; flex-shrink: 0;
  padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px));
}
.adm-drawer-btn {
  display: flex; align-items: center; gap: 8px;
  padding: 0.7rem 1rem; border-radius: 10px; border: none;
  font-size: 0.9rem; font-weight: 600; cursor: pointer;
  transition: all 0.15s; width: 100%; text-align: left;
}
.adm-drawer-btn-mode { background: var(--adm-accent-soft); color: var(--adm-accent); }
.adm-drawer-btn-mode:hover { background: rgba(236,72,153,.2); }
.adm-drawer-btn-logout { background: rgba(239,68,68,.12); color: #ef4444; }
.adm-drawer-btn-logout:hover { background: rgba(239,68,68,.2); }

/* ══════════════════════════════════════════════════════════════
   DARK THEME – Bootstrap & global overrides (scoped to html.adm-dark)
   ══════════════════════════════════════════════════════════════ */

/* ── Texte ── */
html.adm-dark h1, html.adm-dark h2, html.adm-dark h3, html.adm-dark h4,
html.adm-dark h5, html.adm-dark h6, html.adm-dark label, html.adm-dark legend,
html.adm-dark p, html.adm-dark li, html.adm-dark td, html.adm-dark th,
html.adm-dark dt, html.adm-dark dd { color: var(--adm-text); }
html.adm-dark a { color: var(--adm-accent); }
html.adm-dark a:hover { color: #f472b6; }
html.adm-dark small, html.adm-dark .text-muted { color: var(--adm-text-muted) !important; }
html.adm-dark .text-dark { color: var(--adm-text) !important; }

/* ── Navbar admin: garder les couleurs du theme clair pour les boutons/liens ── */
html.adm-dark .adm-link {
  color: #475569 !important;
}
html.adm-dark .adm-icon {
  stroke: #94a3b8 !important;
}
html.adm-dark .adm-link:hover {
  background: rgba(236,72,153,.08) !important;
  color: #ec4899 !important;
}
html.adm-dark .adm-link:hover .adm-icon {
  stroke: #ec4899 !important;
}
html.adm-dark .adm-link.active {
  background: linear-gradient(135deg, #ec4899, #db2777) !important;
  color: #fff !important;
}
html.adm-dark .adm-link.active .adm-icon {
  stroke: #fff !important;
}
html.adm-dark .adm-btn-mode,
html.adm-dark .adm-drawer-btn-mode {
  background: rgba(236,72,153,.08) !important;
  color: #ec4899 !important;
  border-color: rgba(236,72,153,.25) !important;
}
html.adm-dark .adm-drawer-link {
  color: #475569 !important;
}
html.adm-dark .adm-drawer-link:hover {
  background: rgba(236,72,153,.08) !important;
  color: #ec4899 !important;
}
html.adm-dark .adm-drawer-link.active {
  background: rgba(236,72,153,.08) !important;
  color: #ec4899 !important;
  border-left-color: #ec4899 !important;
}
html.adm-dark .adm-drawer-link.active .adm-icon {
  stroke: #ec4899 !important;
}

/* ── Cards & surfaces ── */
html.adm-dark .bg-white,
html.adm-dark .card,
html.adm-dark .card-dashboard,
html.adm-dark .card-body,
html.adm-dark .modal-content,
html.adm-dark .offcanvas,
html.adm-dark .log-card {
  background: var(--adm-card) !important;
  color: var(--adm-text) !important;
  border-color: transparent !important;
  box-shadow: none !important;
}
html.adm-dark .card-header,
html.adm-dark .log-card-header {
  background: var(--adm-elevated) !important;
  border-color: transparent !important;
  color: var(--adm-text) !important;
}
html.adm-dark .card-header h5,
html.adm-dark .log-card-header h5 { color: var(--adm-text) !important; }
html.adm-dark .card-footer {
  background: var(--adm-elevated) !important; border-color: transparent !important;
}

/* ── Tables ── */
html.adm-dark .table,
html.adm-dark .table > thead > tr > th,
html.adm-dark .table > tbody > tr > td,
html.adm-dark .table > tfoot > tr > td {
  color: var(--adm-text) !important; border-color: var(--adm-border) !important;
}
html.adm-dark .table > thead > tr > th { background: var(--adm-elevated) !important; }
html.adm-dark .table-striped > tbody > tr:nth-of-type(odd) > td {
  background: rgba(255,255,255,.02) !important; color: var(--adm-text) !important;
}
html.adm-dark .table > tbody > tr:hover > td {
  background: var(--adm-hover) !important; color: var(--adm-text) !important;
}
/* DataTables */
html.adm-dark .dataTables_wrapper .dataTables_length,
html.adm-dark .dataTables_wrapper .dataTables_filter,
html.adm-dark .dataTables_wrapper .dataTables_info,
html.adm-dark .dataTables_wrapper .dataTables_paginate { color: var(--adm-text-dim) !important; }
html.adm-dark .dataTables_wrapper .dataTables_filter input,
html.adm-dark .dataTables_wrapper .dataTables_length select {
  background: var(--adm-input-bg) !important; color: var(--adm-text) !important;
  border-color: var(--adm-input-border) !important;
}
html.adm-dark .dataTables_wrapper .dataTables_paginate .paginate_button {
  color: var(--adm-text-dim) !important; background: transparent !important;
  border-color: var(--adm-border) !important;
}
html.adm-dark .dataTables_wrapper .dataTables_paginate .paginate_button.current,
html.adm-dark .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
  background: var(--adm-accent) !important; color: #fff !important;
  border-color: var(--adm-accent) !important;
}
html.adm-dark .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
  background: var(--adm-elevated) !important; color: var(--adm-text) !important;
}
/* Dashboard table */
html.adm-dark #tbl thead tr:first-child th {
  background: var(--adm-elevated) !important; color: var(--adm-accent) !important;
  border-color: var(--adm-accent) !important;
}
html.adm-dark #tbl tbody td { border-color: var(--adm-border) !important; }
html.adm-dark #tbl tbody tr:nth-child(even) { background: rgba(255,255,255,.02) !important; }
html.adm-dark #tbl tbody tr:hover { background: var(--adm-hover) !important; box-shadow: none !important; }
html.adm-dark tr.filters th { background: var(--adm-surface) !important; border-color: var(--adm-border) !important; }
html.adm-dark tr.filters select {
  background: var(--adm-input-bg) !important; color: var(--adm-text) !important;
  border-color: var(--adm-input-border) !important;
}
html.adm-dark .first-750 td { background: rgba(236,72,153,.15) !important; }

/* ── Forms ── */
html.adm-dark .form-control,
html.adm-dark .form-select,
html.adm-dark input[type="text"],
html.adm-dark input[type="email"],
html.adm-dark input[type="password"],
html.adm-dark input[type="number"],
html.adm-dark input[type="tel"],
html.adm-dark input[type="url"],
html.adm-dark input[type="date"],
html.adm-dark input[type="search"],
html.adm-dark textarea,
html.adm-dark select {
  background-color: var(--adm-input-bg) !important;
  color: var(--adm-text) !important;
  border-color: var(--adm-input-border) !important;
}
html.adm-dark .form-control:focus,
html.adm-dark .form-select:focus,
html.adm-dark input:focus,
html.adm-dark textarea:focus,
html.adm-dark select:focus {
  background-color: var(--adm-input-bg) !important; color: var(--adm-text) !important;
  border-color: var(--adm-accent) !important;
  box-shadow: 0 0 0 0.2rem rgba(236,72,153,.25) !important;
}
html.adm-dark .form-control::placeholder,
html.adm-dark input::placeholder,
html.adm-dark textarea::placeholder { color: var(--adm-text-muted) !important; }
html.adm-dark .form-label, html.adm-dark .col-form-label { color: var(--adm-text) !important; }
html.adm-dark .form-text { color: var(--adm-text-muted) !important; }
html.adm-dark .form-check-input { background-color: var(--adm-input-bg) !important; border-color: var(--adm-input-border) !important; }
html.adm-dark .form-check-input:checked { background-color: var(--adm-accent) !important; border-color: var(--adm-accent) !important; }
html.adm-dark .form-switch .form-check-input {
  background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%2394a3b8'/%3e%3c/svg%3e") !important;
}
html.adm-dark .form-switch .form-check-input:checked {
  background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%23fff'/%3e%3c/svg%3e") !important;
}
html.adm-dark .input-group-text {
  background-color: var(--adm-elevated) !important; color: var(--adm-text) !important;
  border-color: var(--adm-input-border) !important;
}

/* ── Modals ── */
html.adm-dark .modal-header {
  background: var(--adm-elevated) !important; border-color: var(--adm-border) !important;
  color: var(--adm-text) !important;
}
html.adm-dark .modal-header .modal-title,
html.adm-dark .modal-header h5 { color: var(--adm-text) !important; }
html.adm-dark .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
html.adm-dark .modal-body { background: var(--adm-card) !important; color: var(--adm-text) !important; }
html.adm-dark .modal-footer { background: var(--adm-elevated) !important; border-color: var(--adm-border) !important; }

/* ── Alerts ── */
html.adm-dark .alert-success { background: rgba(34,197,94,.15) !important; color: #86efac !important; border-color: rgba(34,197,94,.3) !important; }
html.adm-dark .alert-danger  { background: rgba(239,68,68,.15) !important; color: #fca5a5 !important; border-color: rgba(239,68,68,.3) !important; }
html.adm-dark .alert-warning { background: rgba(234,179,8,.15) !important; color: #fde68a !important; border-color: rgba(234,179,8,.3) !important; }
html.adm-dark .alert-info    { background: rgba(59,130,246,.15) !important; color: #93c5fd !important; border-color: rgba(59,130,246,.3) !important; }
html.adm-dark .alert .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }

/* ── Badges ── */
html.adm-dark .badge.bg-success { background: rgba(34,197,94,.2) !important; color: #86efac !important; }
html.adm-dark .badge.bg-danger  { background: rgba(239,68,68,.2) !important; color: #fca5a5 !important; }
html.adm-dark .badge.bg-warning { background: rgba(234,179,8,.2) !important; color: #fde68a !important; }
html.adm-dark .badge.bg-info    { background: rgba(59,130,246,.2) !important; color: #93c5fd !important; }
html.adm-dark .badge.bg-primary { background: rgba(236,72,153,.2) !important; color: #f9a8d4 !important; }
html.adm-dark .badge.bg-secondary { background: var(--adm-elevated) !important; color: var(--adm-text-dim) !important; }

/* ── Buttons (dark) – couleurs vives + hover cohérent ── */
html.adm-dark .btn-rose          { background: linear-gradient(135deg,#ec4899,#db2777) !important; color: #fff !important; border: none !important; }
html.adm-dark .btn-rose:hover    { background: linear-gradient(135deg,#db2777,#be185d) !important; color: #fff !important; }
html.adm-dark .btn-success       { background: #22c55e !important; color: #fff !important; border-color: #22c55e !important; }
html.adm-dark .btn-success:hover { background: #16a34a !important; color: #fff !important; }
html.adm-dark .btn-secondary       { background: #2e2f3a !important; color: #e2e4ed !important; border-color: #383942 !important; }
html.adm-dark .btn-secondary:hover { background: #383942 !important; color: #fff !important; }
html.adm-dark .btn-info       { background: #0ea5e9 !important; color: #fff !important; border-color: #0ea5e9 !important; }
html.adm-dark .btn-info:hover { background: #0284c7 !important; color: #fff !important; }
html.adm-dark .btn-danger       { background: #ef4444 !important; color: #fff !important; border-color: #ef4444 !important; }
html.adm-dark .btn-danger:hover { background: #dc2626 !important; color: #fff !important; }
html.adm-dark .btn-warning       { background: #f59e0b !important; color: #16171d !important; border-color: #f59e0b !important; }
html.adm-dark .btn-warning:hover { background: #d97706 !important; color: #fff !important; }
html.adm-dark .btn-light       { background: var(--adm-elevated) !important; color: var(--adm-text) !important; border-color: var(--adm-border) !important; }
html.adm-dark .btn-outline-secondary { color: var(--adm-text-dim) !important; border-color: var(--adm-border) !important; }
html.adm-dark .btn-outline-secondary:hover { background: var(--adm-elevated) !important; color: var(--adm-text) !important; }

/* ── Dropdowns ── */
html.adm-dark .dropdown-menu { background: var(--adm-surface) !important; border-color: var(--adm-border) !important; }
html.adm-dark .dropdown-item { color: var(--adm-text) !important; }
html.adm-dark .dropdown-item:hover { background: var(--adm-hover) !important; }
html.adm-dark .dropdown-divider { border-color: var(--adm-border) !important; }

/* ── Nav tabs / pills ── */
html.adm-dark .nav-tabs { border-color: var(--adm-border) !important; }
html.adm-dark .nav-tabs .nav-link { color: var(--adm-text-dim) !important; }
html.adm-dark .nav-tabs .nav-link.active {
  background: var(--adm-card) !important; color: var(--adm-accent) !important;
  border-color: var(--adm-border) var(--adm-border) var(--adm-card) !important;
}
html.adm-dark .nav-tabs .nav-link:hover { border-color: var(--adm-border) !important; color: var(--adm-text) !important; }
html.adm-dark .nav-pills .nav-link { color: var(--adm-text-dim) !important; }
html.adm-dark .nav-pills .nav-link.active { background: var(--adm-accent) !important; color: #fff !important; }
html.adm-dark .tab-content { color: var(--adm-text) !important; }

/* ── Accordion ── */
html.adm-dark .accordion-item { background: var(--adm-card) !important; border-color: transparent !important; }
html.adm-dark .accordion-button { background: var(--adm-elevated) !important; color: var(--adm-text) !important; }
html.adm-dark .accordion-button:not(.collapsed) { background: var(--adm-accent-soft) !important; color: var(--adm-accent) !important; }
html.adm-dark .accordion-body { background: var(--adm-card) !important; color: var(--adm-text) !important; }

/* ── Misc ── */
html.adm-dark .progress { background: var(--adm-elevated) !important; }
html.adm-dark .list-group-item { background: var(--adm-card) !important; color: var(--adm-text) !important; border-color: transparent !important; }
html.adm-dark .list-group-item.active { background: var(--adm-accent) !important; border-color: var(--adm-accent) !important; }
html.adm-dark hr { border-color: var(--adm-border) !important; opacity: .5; }

/* ── Scrollbars (dark only) ── */
html.adm-dark ::-webkit-scrollbar { width: 8px; height: 8px; }
html.adm-dark ::-webkit-scrollbar-track { background: var(--adm-bg); }
html.adm-dark ::-webkit-scrollbar-thumb { background: var(--adm-text-muted); border-radius: 4px; }

/* ── Page-specific: Logs ── */
html.adm-dark .log-empty { background: var(--adm-surface) !important; color: var(--adm-text-muted) !important; }
html.adm-dark .log-empty i { color: var(--adm-text-muted) !important; }
html.adm-dark .badge-size { background: rgba(236,72,153,.15) !important; color: #f472b6 !important; }
html.adm-dark .badge-lines { background: rgba(99,102,241,.15) !important; color: #a5b4fc !important; }
html.adm-dark .page-header h2 { color: var(--adm-text) !important; }
html.adm-dark .page-header p { color: var(--adm-text-muted) !important; }

/* ── Page-specific: Stats ── */
html.adm-dark .stat-card { background: var(--adm-card) !important; box-shadow: none !important; color: var(--adm-text) !important; }
html.adm-dark .stat-title { color: var(--adm-text-dim) !important; }

/* ── Page-specific: QR Code ── */
html.adm-dark .token-display { background: var(--adm-elevated) !important; color: var(--adm-text) !important; }
html.adm-dark .qr-preview { border-color: var(--adm-border) !important; background: #fff !important; }

/* ── Page-specific: Email suggestions ── */
html.adm-dark .email-suggestions { background: var(--adm-surface) !important; border-color: var(--adm-border) !important; }
html.adm-dark .suggestion-item { border-color: var(--adm-border) !important; color: var(--adm-text) !important; }
html.adm-dark .suggestion-item:hover { background: var(--adm-hover) !important; }

/* ── Page-specific: Timeline ── */
html.adm-dark .tl-card {
  background: var(--adm-elevated) !important;
  border-color: rgba(148,153,176,.28) !important;
  box-shadow: 0 10px 28px rgba(0,0,0,.28) !important;
}
html.adm-dark .tl-card:hover {
  border-color: rgba(236,72,153,.35) !important;
  box-shadow: 0 14px 34px rgba(0,0,0,.36) !important;
}
html.adm-dark .tl-amount { color: #eef2ff !important; }
html.adm-dark .tl-pill {
  background: rgba(255,255,255,.08) !important;
  border-color: rgba(148,153,176,.35) !important;
  color: #d8deec !important;
}
html.adm-dark .tl-order { color: #b4bdd1 !important; }
html.adm-dark .move-btn { color: #b4bdd1 !important; }
html.adm-dark .move-btn:hover { color: #ec4899 !important; }
html.adm-dark .tl-card .btn-outline-primary,
html.adm-dark .tl-card .btn-outline-danger {
  background: rgba(255,255,255,.08) !important;
  border-color: rgba(148,153,176,.35) !important;
  color: #d8deec !important;
}
html.adm-dark .tl-card .btn-outline-primary:hover {
  background: rgba(236,72,153,.15) !important;
  border-color: rgba(236,72,153,.5) !important;
  color: #f9a8d4 !important;
}
html.adm-dark .tl-card .btn-outline-danger:hover {
  background: rgba(239,68,68,.18) !important;
  border-color: rgba(239,68,68,.45) !important;
  color: #fca5a5 !important;
}
html.adm-dark .img-positioner { background: var(--adm-elevated) !important; }

/* ── Page-specific: News ── */
html.adm-dark .admin-comment-ip { background: var(--adm-elevated) !important; color: var(--adm-text-dim) !important; }

/* ── Footer ── */
html.adm-dark .site-footer { background: var(--adm-surface) !important; color: var(--adm-text-dim) !important; border-top: 1px solid var(--adm-border) !important; }
html.adm-dark .site-footer a { color: var(--adm-text-dim) !important; }
html.adm-dark .site-footer a:hover { color: var(--adm-accent) !important; }
html.adm-dark .footer-brand-name,
html.adm-dark .footer-tagline,
html.adm-dark .footer-title,
html.adm-dark .footer-copyright { color: var(--adm-text-dim) !important; }
html.adm-dark .footer-contact-btn { color: var(--adm-text) !important; border-color: var(--adm-border) !important; }

/* ── TinyMCE (dark only) ── */
html.adm-dark .tox .tox-editor-header,
html.adm-dark .tox .tox-toolbar,
html.adm-dark .tox .tox-toolbar__primary,
html.adm-dark .tox .tox-toolbar__overflow { background: var(--adm-elevated) !important; }
html.adm-dark .tox .tox-edit-area__iframe { background: var(--adm-card) !important; }
html.adm-dark .tox .tox-tbtn { color: var(--adm-text) !important; }
html.adm-dark .tox .tox-tbtn svg { fill: var(--adm-text-dim) !important; }
html.adm-dark .tox .tox-statusbar { background: var(--adm-elevated) !important; color: var(--adm-text-muted) !important; border-color: var(--adm-border) !important; }

/* ══════════════════════════════════════════════════════════════
   RESPONSIVE
   ══════════════════════════════════════════════════════════════ */
@media (max-width: 1280px) {
  .adm-btn span { display: none; }
  .adm-btn { padding: 0.5rem; border-radius: 50%; }
}
@media (max-width: 980px) {
  .adm-links-wrap, .adm-actions { display: none; }
  .adm-burger { display: flex; }
}
</style>

<!-- ═══════ SCRIPT ═══════ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  var burger   = document.getElementById('admBurger');
  var drawer   = document.getElementById('admDrawer');
  var overlay  = document.getElementById('admOverlay');
  var closeBtn = document.getElementById('admDrawerClose');

  function openDrawer()  { drawer.classList.add('open'); overlay.classList.add('show'); document.body.style.overflow = 'hidden'; }
  function closeDrawer() { drawer.classList.remove('open'); overlay.classList.remove('show'); document.body.style.overflow = ''; }

  if (burger)   burger.addEventListener('click', openDrawer);
  if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
  if (overlay)  overlay.addEventListener('click', closeDrawer);

  // ── Déconnexion ──
  function handleLogout(e) {
    e.preventDefault();
    fetch('../config/api.php?route=logout').then(function() { location.href = '../login.php'; });
  }
  var logoutBtn = document.getElementById('adminLogout');
  var logoutMob = document.getElementById('adminLogoutMobile');
  if (logoutBtn) logoutBtn.addEventListener('click', handleLogout);
  if (logoutMob) logoutMob.addEventListener('click', handleLogout);

  // ── Theme toggle (footer button) ──
  var toggle = document.getElementById('themeToggle');
  if (toggle) {
    // Remplacer pour supprimer les event listeners du site public
    var newToggle = toggle.cloneNode(true);
    toggle.parentNode.replaceChild(newToggle, toggle);
    newToggle.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      var isDark = document.documentElement.classList.toggle('adm-dark');
      localStorage.setItem('adm-theme', isDark ? 'dark' : 'light');
    });
  }

  // ── Clone page action buttons into drawer ──
  var mainEl = document.querySelector('main');
  var drawerActions = document.getElementById('admDrawerActions');
  var actionsList  = document.getElementById('admDrawerActionsList');

  if (mainEl && drawerActions && actionsList) {
    var hiddenContainers = mainEl.querySelectorAll('.d-none.d-lg-flex, .d-none.d-lg-block');
    var hasButtons = false;
    var btnClasses = ['btn-rose','btn-success','btn-secondary','btn-info','btn-danger','btn-warning','btn-primary'];

    hiddenContainers.forEach(function(container) {
      var buttons = container.querySelectorAll('.btn');
      buttons.forEach(function(btn) {
        hasButtons = true;
        var newBtn = document.createElement('button');
        newBtn.type = 'button';
        newBtn.className = 'adm-drawer-action-btn';
        newBtn.textContent = btn.textContent.trim();

        // Copier la classe de couleur
        btnClasses.forEach(function(cls) {
          if (btn.classList.contains(cls)) newBtn.classList.add(cls);
        });

        // Si le bouton ouvre un modal
        var modalTarget = btn.getAttribute('data-bs-target');
        if (modalTarget) {
          newBtn.setAttribute('data-bs-toggle', 'modal');
          newBtn.setAttribute('data-bs-target', modalTarget);
          newBtn.addEventListener('click', function() { closeDrawer(); });
        } else {
          // Sinon, simuler le clic sur le bouton original
          newBtn.addEventListener('click', function() {
            closeDrawer();
            setTimeout(function() { btn.click(); }, 300);
          });
        }

        actionsList.appendChild(newBtn);
      });
    });

    if (hasButtons) drawerActions.style.display = '';
  }
});
</script>
