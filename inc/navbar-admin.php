<?php
/**
 * Admin Layout – Sidebar + Topbar (Rose OpenCloud Theme)
 * Outputs: topbar + sidebar + opens #oc-content div
 * Close with: admin-footer.php
 */

$currentPage = basename($_SERVER['PHP_SELF']);

$pageTitles = [
    'dashboard.php'  => 'Tableau de bord',
    'utilisateurs.php' => 'Utilisateurs',
    'setting.php'    => 'Réglages',
    'albums.php'     => 'Albums',
    'partners.php'   => 'Partenaires',
    'news.php'       => 'Actualités',
    'stats.php'      => 'Statistiques',
    'qr_code.php'    => 'QR Code',
    'saisie.php'     => 'Saisie',
    'timeline.php'   => 'Timeline',
    'connexions.php' => 'Connexions',
    'logs.php'       => 'Logs',
    'page_stats.php' => 'Visites',
];

$pageTitle = $pageTitles[$currentPage] ?? 'Administration';

$adminLinks = [
    'dashboard.php' => ['Tableau de bord', '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>', ['admin','user','viewer','saisie']],
    'utilisateurs.php' => ['Utilisateurs', '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>', ['admin']],
    'setting.php'   => ['Réglages',        '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>', ['admin']],
    'albums.php'    => ['Albums',           '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>', ['admin']],
    'partners.php'  => ['Partenaires',      '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>', ['admin']],
    'news.php'      => ['Actualités',       '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/>', ['admin']],
    'timeline.php'  => ['Timeline',         '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>', ['admin']],
    'qr_code.php'   => ['QR Code',          '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>', ['admin']],
    'connexions.php'=> ['Connexions',       '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>', ['admin']],
    'logs.php'      => ['Logs',             '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>', ['admin']],
    'page_stats.php' => ['Visites',          '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8z"/><circle cx="12" cy="12" r="3"/>', ['admin']],
    'stats.php'     => ['Statistiques',     '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>', ['admin','user','viewer']],
];

// User info for avatar
$userEmail = $_SESSION['email'] ?? '';
$userRole  = $role ?? currentRole();
$userInitial = strtoupper(substr($userEmail, 0, 1));
if (!$userInitial) $userInitial = strtoupper(substr($userRole, 0, 1));
?>

<!-- ═══════ ADMIN LAYOUT CSS ═══════ -->
<style>
/* ══════════════════════════════════════════════════════════════
   ROSE OPENCLOUD THEME – Light only
   ══════════════════════════════════════════════════════════════ */
:root {
  --rose-500: #ec4899;
  --oc-frame:        #4a2038;
  --oc-frame-text:   #ffffff;
  --oc-topbar-h:     52px;
  --oc-sidebar-w:    230px;
  --oc-sidebar-bg:   #faf7f8;
  --oc-sidebar-active-bg:  #fce4ec;
  --oc-sidebar-active-text:#880e4f;
  --oc-sidebar-hover-bg:   #f3eaed;
  --oc-sidebar-text:       #5f4b52;
  --oc-sidebar-text-dim:   #9e8a92;
  --oc-sidebar-icon:       #9e8a92;
  --oc-surface:      #ffffff;
  --oc-on-surface:   #191C1D;
  --oc-on-surface-variant: #5f5360;
  --oc-border:       #f0e8eb;
  --oc-outline:      #8e7e85;
  --oc-accent:       #c4577a;
  --oc-accent-soft:  rgba(196,87,122,.08);
  --oc-error:        #BA1A1A;
  --oc-error-container: #FFDAD6;
  --oc-radius:       12px;
  --oc-gap:          6px;
  --font-main:       'Inter', system-ui, -apple-system, sans-serif;
}

/* ══════════════════════════════════════════════════════════════
   BODY RESET
   ══════════════════════════════════════════════════════════════ */
html, body {
  margin: 0 !important; padding: 0 !important;
  background: var(--oc-frame) !important;
  background-image: none !important;
  font-family: var(--font-main);
  font-size: 14px;
  height: 100vh !important;
  overflow: hidden !important;
  color: #1e293b !important;
}

/* Hide elements from public layout */
.site-footer { display: none !important; }

/* ══════════════════════════════════════════════════════════════
   TOPBAR
   ══════════════════════════════════════════════════════════════ */
#oc-topbar {
  background: var(--oc-frame);
  color: var(--oc-frame-text);
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 16px;
  height: var(--oc-topbar-h);
  margin: var(--oc-gap) 0;
  z-index: 50;
  flex-shrink: 0;
}
.oc-topbar-left {
  display: flex; align-items: center; gap: 12px;
}
.oc-topbar-left img { height: 34px; width: auto; }
#oc-topbar-appname {
  color: #fff; font-size: 16px; font-weight: 700;
  text-decoration: none;
}
#oc-topbar-appname:hover { text-decoration: none; color: #fff; }

/* Burger (mobile only) */
.oc-burger {
  display: none; flex-direction: column; justify-content: center;
  gap: 5px; background: none; border: none; padding: 8px; cursor: pointer;
}
.oc-burger span {
  display: block; width: 22px; height: 2px; background: #fff;
  border-radius: 2px; transition: all 0.2s;
}

/* Avatar & User dropdown */
.oc-topbar-right { display: flex; align-items: center; gap: 10px; }
.oc-user-wrapper { position: relative; }
.oc-avatar-btn {
  width: 36px; height: 36px; border-radius: 50%; border: 2px solid rgba(255,255,255,.3);
  background: linear-gradient(135deg, #c4577a, #a0405f); color: #fff;
  font-size: 14px; font-weight: 700; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.oc-avatar-btn:hover {
  border-color: rgba(255,255,255,.6);
  box-shadow: 0 0 0 3px rgba(196,87,122,.3);
}
.oc-avatar-lg {
  width: 40px; height: 40px; border-radius: 50%;
  background: linear-gradient(135deg, #c4577a, #a0405f); color: #fff;
  font-size: 16px; font-weight: 700;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}

/* Dropdown */
.oc-user-dropdown {
  display: none;
  position: absolute; top: calc(100% + 8px); right: 0;
  width: 280px; background: #fff;
  border-radius: var(--oc-radius); border: 1px solid #e2e8f0;
  box-shadow: 0 8px 24px rgba(0,0,0,.18); z-index: 10001;
  overflow: hidden;
}
.oc-user-dropdown.show { display: block; }
.oc-user-dropdown-header {
  display: flex; align-items: center; gap: 12px; padding: 16px;
}
.oc-user-dropdown-name {
  font-size: 14px; font-weight: 700; color: #1e293b;
  word-break: break-all;
}
.oc-user-dropdown-role {
  font-size: 12px; color: #475569; margin-top: 2px;
}
.oc-user-dropdown-divider {
  height: 1px; background: #e2e8f0; border: none; margin: 0;
}
.oc-user-dropdown ul { list-style: none; margin: 4px 0; padding: 0; }
.oc-user-dropdown ul li a {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 16px; font-size: 14px; color: #1e293b;
  text-decoration: none; background: transparent; border: none;
  width: 100%; cursor: pointer; font-family: var(--font-main);
}
.oc-user-dropdown ul li a:hover { background: #f8fafc; }
.oc-user-dropdown ul li a svg {
  width: 18px; height: 18px; stroke: #475569; fill: none;
  stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
}
.oc-user-dropdown ul li a.oc-dropdown-danger { color: var(--oc-error); }
.oc-user-dropdown ul li a.oc-dropdown-danger svg { stroke: var(--oc-error); }

/* ══════════════════════════════════════════════════════════════
   APP CONTAINER
   ══════════════════════════════════════════════════════════════ */
#oc-app-container {
  display: flex; flex-direction: row;
  height: calc(100vh - var(--oc-topbar-h) - var(--oc-gap) * 3);
  overflow: hidden;
  border-radius: var(--oc-radius);
  margin: 0 var(--oc-gap) var(--oc-gap) var(--oc-gap);
}

/* ══════════════════════════════════════════════════════════════
   SIDEBAR
   ══════════════════════════════════════════════════════════════ */
#oc-sidebar {
  background: var(--oc-sidebar-bg);
  width: var(--oc-sidebar-w);
  min-width: var(--oc-sidebar-w);
  max-width: var(--oc-sidebar-w);
  display: flex; flex-direction: column;
  border-radius: var(--oc-radius) 0 0 var(--oc-radius);
  overflow-y: auto; overflow-x: hidden;
  border-right: 1px solid var(--oc-border);
}
.oc-sidebar-nav {
  list-style: none; margin: 8px 0 0 0; padding: 0; flex: 1;
}
.oc-sidebar-nav > li { padding: 2px 8px; }
.oc-sidebar-link {
  display: flex; align-items: center; gap: 14px;
  padding: 9px 12px; border-radius: 8px;
  color: var(--oc-sidebar-text); font-size: 14px; font-weight: 500;
  text-decoration: none; background: transparent;
  transition: background 0.15s, color 0.15s;
}
.oc-sidebar-link svg {
  width: 20px; height: 20px; flex-shrink: 0;
  stroke: var(--oc-sidebar-icon); fill: none;
  stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
}
.oc-sidebar-link:hover {
  background: var(--oc-sidebar-hover-bg);
  color: var(--oc-sidebar-active-text);
  text-decoration: none;
}
.oc-sidebar-link:hover svg { stroke: var(--oc-sidebar-active-text); }
.oc-sidebar-link.active {
  background: var(--oc-sidebar-active-bg);
  color: var(--oc-sidebar-active-text);
  font-weight: 600;
}
.oc-sidebar-link.active svg { stroke: var(--oc-sidebar-active-text); }

/* ══════════════════════════════════════════════════════════════
   CONTENT
   ══════════════════════════════════════════════════════════════ */
#oc-content {
  flex: 1;
  background: var(--oc-surface);
  border-radius: 0 var(--oc-radius) var(--oc-radius) 0;
  overflow: auto;
  padding: 28px 32px;
  min-width: 0;
  color: #1e293b;
}

/* Override any Bootstrap container inside content */
#oc-content > .container,
#oc-content > .container-fluid {
  max-width: 100%; padding: 0; margin: 0;
}

/* ══════════════════════════════════════════════════════════════
   MOBILE OVERLAY
   ══════════════════════════════════════════════════════════════ */
.oc-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,.5);
  z-index: 1060; opacity: 0; pointer-events: none; transition: opacity 0.3s;
}
.oc-overlay.show { opacity: 1; pointer-events: auto; }

/* ══════════════════════════════════════════════════════════════
   RESPONSIVE
   ══════════════════════════════════════════════════════════════ */
@media (max-width: 980px) {
  .oc-burger { display: flex; }

  #oc-sidebar {
    position: fixed; top: 0; left: -280px; width: 270px;
    min-width: 270px; max-width: 85vw;
    height: 100vh; height: 100dvh;
    border-radius: 0; z-index: 1070;
    transition: left 0.3s ease;
    box-shadow: 4px 0 20px rgba(0,0,0,.15);
  }
  #oc-sidebar.open { left: 0; }

  #oc-app-container {
    margin: 0 var(--oc-gap) var(--oc-gap) var(--oc-gap);
  }

  #oc-content {
    border-radius: var(--oc-radius);
    padding: 20px 16px;
  }
}

@media (max-width: 576px) {
  #oc-content { padding: 16px 12px; }
  #oc-topbar { padding: 0 12px; }
}

/* ══════════════════════════════════════════════════════════════
   OPENCLOUD ROSE – Buttons
   ══════════════════════════════════════════════════════════════ */
#oc-content .btn {
  font-family: var(--font-main);
  font-size: 13px; font-weight: 600; border-radius: 6px;
  padding: 7px 14px; cursor: pointer;
  display: inline-flex; align-items: center; gap: 6px;
  box-shadow: none; transition: all 0.15s;
  text-decoration: none; line-height: 1.4;
}
#oc-content .btn:focus { box-shadow: 0 0 0 3px rgba(196,87,122,.2); }

/* Primary = rose filled */
#oc-content .btn-primary,
#oc-content .btn-rose {
  background: #c4577a; color: #fff; border: none;
}
#oc-content .btn-primary:hover,
#oc-content .btn-rose:hover { background: #a84565; color: #fff; }

/* Success = soft green */
#oc-content .btn-success {
  background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0;
}
#oc-content .btn-success:hover { background: #d1fae5; color: #065f46; }

/* Danger = soft red */
#oc-content .btn-danger {
  background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
}
#oc-content .btn-danger:hover { background: #fee2e2; color: #991b1b; }

/* Warning = soft amber */
#oc-content .btn-warning {
  background: #fffbeb; color: #92400e; border: 1px solid #fde68a;
}
#oc-content .btn-warning:hover { background: #fef3c7; color: #92400e; }

/* Info = soft blue */
#oc-content .btn-info {
  background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe;
}
#oc-content .btn-info:hover { background: #dbeafe; color: #1e40af; }

/* Secondary = neutral outline */
#oc-content .btn-secondary {
  background: transparent; color: #5f4b52; border: 1px solid #d4c4cb;
}
#oc-content .btn-secondary:hover { background: #faf7f8; color: #4a2038; }

/* Outline variants */
#oc-content .btn-outline-primary {
  background: transparent; color: #c4577a; border: 1px solid #c4577a;
}
#oc-content .btn-outline-primary:hover { background: rgba(196,87,122,.08); }

#oc-content .btn-outline-secondary {
  background: transparent; color: #5f4b52; border: 1px solid #d4c4cb;
}
#oc-content .btn-outline-secondary:hover { background: #faf7f8; }

#oc-content .btn-outline-danger {
  background: transparent; color: #991b1b; border: 1px solid #fecaca;
}
#oc-content .btn-outline-danger:hover { background: #fef2f2; }

#oc-content .btn-outline-success {
  background: transparent; color: #065f46; border: 1px solid #a7f3d0;
}
#oc-content .btn-outline-success:hover { background: #ecfdf5; }

#oc-content .btn-outline-warning {
  background: transparent; color: #92400e; border: 1px solid #fde68a;
}
#oc-content .btn-outline-warning:hover { background: #fffbeb; }

#oc-content .btn-outline-info {
  background: transparent; color: #1e40af; border: 1px solid #bfdbfe;
}
#oc-content .btn-outline-info:hover { background: #eff6ff; }

/* Small buttons */
#oc-content .btn-sm { font-size: 12px; padding: 4px 10px; border-radius: 5px; }

/* Light button */
#oc-content .btn-light {
  background: #faf7f8; color: #5f4b52; border: 1px solid #f0e8eb;
}
#oc-content .btn-light:hover { background: #f3eaed; }

/* ══════════════════════════════════════════════════════════════
   OPENCLOUD ROSE – Alerts
   ══════════════════════════════════════════════════════════════ */
#oc-content .alert {
  border-radius: 8px; font-size: 13px; font-weight: 500;
  padding: 12px 16px; border-width: 1px; border-style: solid;
  display: flex; align-items: center; gap: 10px;
}
#oc-content .alert-success {
  background: #ecfdf5; color: #065f46; border-color: #a7f3d0;
}
#oc-content .alert-danger {
  background: #fef2f2; color: #991b1b; border-color: #fecaca;
}
#oc-content .alert-warning {
  background: #fffbeb; color: #92400e; border-color: #fde68a;
}
#oc-content .alert-info {
  background: #eff6ff; color: #1e40af; border-color: #bfdbfe;
}
#oc-content .alert .btn-close { filter: none; opacity: 0.5; }
#oc-content .alert .btn-close:hover { opacity: 1; }

/* ══════════════════════════════════════════════════════════════
   OPENCLOUD ROSE – Cards
   ══════════════════════════════════════════════════════════════ */
#oc-content .card,
#oc-content .card-dashboard {
  background: #fff; border: 1px solid #f0e8eb; border-radius: 12px;
  box-shadow: none; overflow: hidden;
}
#oc-content .card-header {
  background: #faf7f8; border-bottom: 1px solid #f0e8eb;
  font-weight: 600; color: #4a2038; padding: 12px 16px;
}
#oc-content .card-body { padding: 20px; }

/* ══════════════════════════════════════════════════════════════
   OPENCLOUD ROSE – Form controls
   ══════════════════════════════════════════════════════════════ */
#oc-content .form-control:not([type="color"]),
#oc-content .form-select {
  border: 1px solid #d4c4cb; border-radius: 6px;
  font-size: 14px; font-family: var(--font-main);
  color: #1e293b; padding: 7px 12px; height: auto;
  transition: border-color 0.15s, box-shadow 0.15s;
}
#oc-content input[type="color"] {
  width: 50px; height: 36px; padding: 2px; border: 1px solid #d4c4cb;
  border-radius: 6px; cursor: pointer;
}
#oc-content .form-control:focus,
#oc-content .form-select:focus {
  border-color: #c4577a;
  box-shadow: 0 0 0 3px rgba(196,87,122,.12);
}
#oc-content .form-label {
  font-size: 13px; font-weight: 600; color: #5f4b52;
  margin-bottom: 4px;
}
#oc-content .form-check-input:checked {
  background-color: #c4577a; border-color: #c4577a;
}
#oc-content .form-check-input:focus {
  box-shadow: 0 0 0 3px rgba(196,87,122,.15);
  border-color: #c4577a;
}

/* ══════════════════════════════════════════════════════════════
   OPENCLOUD ROSE – Modals
   ══════════════════════════════════════════════════════════════ */
#oc-content .modal-content,
.modal-content {
  border-radius: 12px; border: 1px solid #f0e8eb;
  box-shadow: 0 20px 60px rgba(74,32,56,.15);
}
@media (min-width: 1200px) {
  .modal-dialog.modal-xl { max-width: 70vw; }
}
.modal-header {
  background: #faf7f8; border-bottom: 1px solid #f0e8eb;
  padding: 14px 20px;
}
.modal-header .modal-title { font-size: 16px; font-weight: 700; color: #4a2038; }
.modal-body { padding: 20px; }
.modal-footer { background: #faf7f8; border-top: 1px solid #f0e8eb; padding: 12px 20px; }

/* Buttons inside modals (modals are outside #oc-content in the DOM) */
.modal .btn {
  font-family: var(--font-main); font-size: 13px; font-weight: 600;
  border-radius: 6px; padding: 7px 14px;
  display: inline-flex; align-items: center; gap: 6px;
}
.modal .btn-primary, .modal .btn-rose { background: #c4577a; color: #fff; border: none; }
.modal .btn-primary:hover, .modal .btn-rose:hover { background: #a84565; color: #fff; }
.modal .btn-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.modal .btn-success:hover { background: #d1fae5; }
.modal .btn-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.modal .btn-danger:hover { background: #fee2e2; }
.modal .btn-secondary { background: transparent; color: #5f4b52; border: 1px solid #d4c4cb; }
.modal .btn-secondary:hover { background: #faf7f8; }
.modal .btn-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
.modal .btn-info:hover { background: #dbeafe; }
.modal .btn-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.modal .btn-warning:hover { background: #fef3c7; }
.modal .btn-outline-primary { background: transparent; color: #c4577a; border: 1px solid #c4577a; }
.modal .btn-outline-primary:hover { background: rgba(196,87,122,.08); }
.modal .btn-outline-secondary { background: transparent; color: #64748b; border: 1px solid #94a3b8; }
.modal .btn-outline-secondary:hover { background: #e2e8f0; color: #475569; }
.modal .btn-outline-danger { background: transparent; color: #991b1b; border: 1px solid #fecaca; }
.modal .btn-outline-danger:hover { background: #fef2f2; color: #991b1b; }
.modal .btn-outline-success { background: transparent; color: #065f46; border: 1px solid #a7f3d0; }
.modal .btn-outline-success:hover { background: #ecfdf5; color: #065f46; }
.modal .btn-outline-warning { background: transparent; color: #92400e; border: 1px solid #fde68a; }
.modal .btn-outline-warning:hover { background: #fffbeb; color: #92400e; }
.modal .btn-outline-info { background: transparent; color: #1e40af; border: 1px solid #bfdbfe; }
.modal .btn-outline-info:hover { background: #eff6ff; color: #1e40af; }
.modal .btn-sm { font-size: 12px; padding: 4px 10px; }

/* Form controls inside modals */
.modal .form-control, .modal .form-select {
  border: 1px solid #d4c4cb; border-radius: 6px; font-size: 14px; color: #1e293b;
}
.modal .form-control:focus, .modal .form-select:focus {
  border-color: #c4577a; box-shadow: 0 0 0 3px rgba(196,87,122,.12);
}
.modal .form-label { font-size: 13px; font-weight: 600; color: #5f4b52; }
.modal .form-check-input:checked { background-color: #c4577a; border-color: #c4577a; }

/* ══════════════════════════════════════════════════════════════
   OPENCLOUD ROSE – Tables
   ══════════════════════════════════════════════════════════════ */
#oc-content .table { font-size: 14px; }
#oc-content .table > thead > tr > th {
  background: #faf7f8; color: #5f4b52; font-weight: 600;
  font-size: 12px; text-transform: uppercase; letter-spacing: 0.03em;
  border-bottom: 2px solid #f0e8eb; padding: 10px 12px;
}
#oc-content .table > tbody > tr > td {
  padding: 10px 12px; border-bottom: 1px solid #f0e8eb; color: #1e293b;
  vertical-align: middle;
}
#oc-content .table > tbody > tr:hover > td { background: #fdf8f9; }

/* Table responsive — no horizontal scrollbar unless truly needed */
#oc-content .table-responsive { overflow-x: auto; }
#oc-content .table { min-width: 0; }

/* ══════════════════════════════════════════════════════════════
   OPENCLOUD ROSE – DataTables overrides
   ══════════════════════════════════════════════════════════════ */
/* Select "Afficher X entrées" */
#oc-content .dataTables_wrapper .dataTables_length select,
#oc-content .dataTables_wrapper select {
  border: 1px solid #d4c4cb; border-radius: 6px;
  padding: 4px 28px 4px 8px; font-size: 13px; color: #1e293b;
  background: #fff url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%235f4b52' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") no-repeat right 8px center/12px;
  -webkit-appearance: none; -moz-appearance: none; appearance: none;
  cursor: pointer;
}
#oc-content .dataTables_wrapper .dataTables_length label,
#oc-content .dataTables_wrapper .dataTables_filter label,
#oc-content .dataTables_wrapper .dataTables_info,
.modal .dataTables_wrapper .dataTables_length label,
.modal .dataTables_wrapper .dataTables_filter label,
.modal .dataTables_wrapper .dataTables_info {
  font-size: 13px; color: #1e293b;
}
/* Search input */
#oc-content .dataTables_wrapper .dataTables_filter input {
  border: 1px solid #d4c4cb; border-radius: 6px;
  padding: 5px 10px; font-size: 13px; color: #1e293b;
}
#oc-content .dataTables_wrapper .dataTables_filter input:focus {
  border-color: #c4577a; box-shadow: 0 0 0 3px rgba(196,87,122,.12); outline: none;
}
/* Pagination — Bootstrap style, rose instead of blue */
#oc-content .dataTables_wrapper .dataTables_paginate .paginate_button,
.modal .dataTables_wrapper .dataTables_paginate .paginate_button,
#oc-content .page-link,
.modal .page-link {
  color: #1e293b !important;
}
#oc-content .dataTables_wrapper .dataTables_paginate .paginate_button:hover,
.modal .dataTables_wrapper .dataTables_paginate .paginate_button:hover,
#oc-content .page-link:hover,
.modal .page-link:hover {
  color: #1e293b !important;
}
#oc-content .dataTables_wrapper .dataTables_paginate .paginate_button.current,
#oc-content .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover,
.modal .dataTables_wrapper .dataTables_paginate .paginate_button.current,
.modal .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover,
#oc-content .page-item.active .page-link,
.modal .page-item.active .page-link {
  background-color: #c4577a !important; color: #fff !important;
  border-color: #c4577a !important;
}

/* ══════════════════════════════════════════════════════════════
   OPENCLOUD ROSE – Badges
   ══════════════════════════════════════════════════════════════ */
#oc-content .badge {
  font-size: 11px; font-weight: 600; padding: 3px 8px;
  border-radius: 4px; letter-spacing: 0.02em;
}
#oc-content .badge.bg-primary { background: #fce4ec !important; color: #880e4f !important; }
#oc-content .badge.bg-success { background: #ecfdf5 !important; color: #065f46 !important; }
#oc-content .badge.bg-danger { background: #fef2f2 !important; color: #991b1b !important; }
#oc-content .badge.bg-warning { background: #fffbeb !important; color: #92400e !important; }
#oc-content .badge.bg-info { background: #eff6ff !important; color: #1e40af !important; }
#oc-content .badge.bg-secondary { background: #f3f4f6 !important; color: #4b5563 !important; }

/* ══════════════════════════════════════════════════════════════
   OPENCLOUD ROSE – Nav tabs
   ══════════════════════════════════════════════════════════════ */
#oc-content .nav-tabs {
  border-bottom: 2px solid #f0e8eb;
}
#oc-content .nav-tabs .nav-link,
#oc-content .nav .nav-link,
#oc-content .settings-tabs .nav-link,
#oc-content .settings-tabs a,
#oc-content .filter-tabs .nav-link,
#oc-content .filter-tabs a {
  color: #1e293b !important; font-weight: 500; font-size: 14px;
  border: none; border-bottom: 2px solid transparent;
  margin-bottom: -2px; border-radius: 0; padding: 10px 16px;
  text-decoration: none;
}
#oc-content .nav-tabs .nav-link:hover,
#oc-content .nav .nav-link:hover,
#oc-content .settings-tabs .nav-link:hover,
#oc-content .settings-tabs a:hover,
#oc-content .filter-tabs .nav-link:hover,
#oc-content .filter-tabs a:hover { color: #1e293b !important; border-bottom-color: #d4c4cb; }
#oc-content .nav-tabs .nav-link.active,
#oc-content .nav .nav-link.active,
#oc-content .settings-tabs .nav-link.active,
#oc-content .settings-tabs a.active,
#oc-content .filter-tabs .nav-link.active,
#oc-content .filter-tabs a.active {
  color: #1e293b !important; font-weight: 600;
  border-bottom-color: #c4577a; background: transparent;
}

/* ══════════════════════════════════════════════════════════════
   OPENCLOUD ROSE – List groups
   ══════════════════════════════════════════════════════════════ */
#oc-content .list-group-item {
  border-color: #f0e8eb; font-size: 14px; padding: 12px 16px;
}
#oc-content .list-group-item:hover { background: #fdf8f9; }

/* ══════════════════════════════════════════════════════════════
   OPENCLOUD ROSE – Headings inside content
   ══════════════════════════════════════════════════════════════ */
#oc-content h1 { font-size: 22px; font-weight: 700; color: #1e293b; margin-bottom: 20px; }
#oc-content h2 { font-size: 18px; font-weight: 700; color: #1e293b; }
#oc-content h3 { font-size: 16px; font-weight: 700; color: #4a2038; }
#oc-content h5 { font-size: 15px; font-weight: 700; color: #4a2038; }
#oc-content a { color: #c4577a; }
#oc-content a:hover { color: #a84565; }

/* ══════════════════════════════════════════════════════════════
   OPENCLOUD ROSE – Scrollbar (content area)
   ══════════════════════════════════════════════════════════════ */
#oc-content::-webkit-scrollbar { width: 8px; }
#oc-content::-webkit-scrollbar-track { background: transparent; }
#oc-content::-webkit-scrollbar-thumb { background: #d4c4cb; border-radius: 4px; }
#oc-content::-webkit-scrollbar-thumb:hover { background: #9e8a92; }
</style>

<!-- ═══════ TOPBAR ═══════ -->
<header id="oc-topbar">
  <div class="oc-topbar-left">
    <button class="oc-burger" id="ocBurger" type="button" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
    <a href="dashboard.php" style="display:flex;align-items:center">
      <img src="../files/_logos/logo_fer_rose.png" alt="Forbach en Rose">
    </a>
    <a href="dashboard.php" id="oc-topbar-appname">Administration</a>
  </div>
  <div class="oc-topbar-right">
    <div class="oc-user-wrapper">
      <button class="oc-avatar-btn" id="ocAvatarBtn" type="button" title="<?= htmlspecialchars($userEmail) ?>">
        <?= $userInitial ?>
      </button>
      <div class="oc-user-dropdown" id="ocDropdown">
        <div class="oc-user-dropdown-header">
          <div class="oc-avatar-lg"><?= $userInitial ?></div>
          <div>
            <div class="oc-user-dropdown-name"><?= htmlspecialchars($userEmail ?: ucfirst($userRole)) ?></div>
            <div class="oc-user-dropdown-role">Role : <?= htmlspecialchars(ucfirst($userRole)) ?></div>
          </div>
        </div>
        <hr class="oc-user-dropdown-divider">
        <ul>
          <?php if($currentPage === 'dashboard.php'): ?>
          <li>
            <a href="#" id="ocModeToggle">
              <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
              <span>Mode standard</span>
            </a>
          </li>
          <?php endif; ?>
          <li>
            <a href="#" id="ocLogoutLink" class="oc-dropdown-danger">
              <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
              <span>Deconnexion</span>
            </a>
          </li>
        </ul>
      </div>
    </div>
  </div>
</header>

<!-- ═══════ APP CONTAINER ═══════ -->
<div id="oc-app-container">

  <!-- ═══════ SIDEBAR ═══════ -->
  <aside id="oc-sidebar">
    <ul class="oc-sidebar-nav">
      <?php foreach ($adminLinks as $file => [$label, $icon, $roles]):
        if (!in_array($userRole, $roles)) continue;
        $isActive = ($currentPage === $file);
      ?>
        <li>
          <a class="oc-sidebar-link <?= $isActive ? 'active' : '' ?>" href="<?= $file ?>">
            <svg viewBox="0 0 24 24"><?= $icon ?></svg>
            <span><?= $label ?></span>
          </a>
        </li>
      <?php endforeach; ?>
      <?php if ($userRole === 'admin' && file_exists(__DIR__ . '/../update.php')): ?>
        <li style="padding:8px 8px 2px">
          <a class="oc-sidebar-link" href="../update.php" style="background:#fff3cd;color:#856404;border:1px solid #ffc107;font-weight:600">
            <svg viewBox="0 0 24 24" style="stroke:#856404"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span>Mise a jour BDD</span>
          </a>
        </li>
      <?php endif; ?>
    </ul>
  </aside>

  <!-- ═══════ CONTENT (opened here, closed in admin-footer.php) ═══════ -->
  <div id="oc-content">

<!-- Mobile overlay -->
<div class="oc-overlay" id="ocOverlay"></div>
