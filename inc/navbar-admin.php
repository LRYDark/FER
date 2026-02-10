<?php
/**
 * Navbar Admin - Navigation moderne pour les pages d'administration
 * Reprend le style de navbar-modern.php avec les onglets d'administration
 */

// Déterminer la page courante pour les onglets actifs
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
];

$pageTitle = $pageTitles[$currentPage] ?? 'Administration';
?>

<!-- NAV ADMIN -->
<header class="floating-nav" id="navRoot">
  <div class="mega-overlay" id="megaOverlay"></div>
  <div class="nav-pill">
    <a class="brand" href="dashboard.php">
      <img class="brand-logo" src="../files/_logos/logo_fer_rose.png" alt="Forbach en Rose">
    </a>

    <button class="burger" id="burgerBtn" aria-expanded="false" aria-controls="mobileDrawer">
      <span class="sr-only">Ouvrir le menu</span>
      <span class="burger-icon" aria-hidden="true"></span>
    </button>

    <div class="nav-right">
      <div class="nav-card">
        <nav id="nav-links" class="links" aria-label="Navigation admin">
          <ul class="menu nav-secondary">
            <!-- Tableau de bord -->
            <li class="item">
              <a class="link <?= $currentPage == 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">
                <span class="nav-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"></path>
                  </svg>
                </span>
                <span class="nav-label">Tableau de bord</span>
              </a>
            </li>

            <?php if($role === 'admin'): ?>
              <!-- Réglages -->
              <li class="item">
                <a class="link <?= $currentPage == 'setting.php' ? 'active' : '' ?>" href="setting.php">
                  <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                      <circle cx="12" cy="12" r="3"></circle>
                      <path d="M12 1v6m0 6v10M1 12h6m6 0h10"></path>
                    </svg>
                  </span>
                  <span class="nav-label">Réglages</span>
                </a>
              </li>

              <!-- Albums -->
              <li class="item">
                <a class="link <?= $currentPage == 'albums.php' ? 'active' : '' ?>" href="albums.php">
                  <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                      <path d="M4 7h4l2-2h4l2 2h4v12H4z"></path>
                      <circle cx="12" cy="13" r="3"></circle>
                    </svg>
                  </span>
                  <span class="nav-label">Albums</span>
                </a>
              </li>

              <!-- Partenaires -->
              <li class="item">
                <a class="link <?= $currentPage == 'partners.php' ? 'active' : '' ?>" href="partners.php">
                  <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                      <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                      <circle cx="9" cy="7" r="4"></circle>
                      <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                      <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                  </span>
                  <span class="nav-label">Partenaires</span>
                </a>
              </li>

              <!-- Actualités -->
              <li class="item">
                <a class="link <?= $currentPage == 'news.php' ? 'active' : '' ?>" href="news.php">
                  <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                      <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                      <line x1="7" y1="8" x2="17" y2="8"></line>
                      <line x1="7" y1="12" x2="17" y2="12"></line>
                      <line x1="7" y1="16" x2="14" y2="16"></line>
                    </svg>
                  </span>
                  <span class="nav-label">Actualités</span>
                </a>
              </li>

              <!-- Timeline -->
              <li class="item">
                <a class="link <?= $currentPage == 'timeline.php' ? 'active' : '' ?>" href="timeline.php">
                  <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                      <circle cx="12" cy="12" r="10"></circle>
                      <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                  </span>
                  <span class="nav-label">Timeline</span>
                </a>
              </li>

              <!-- QR Code -->
              <li class="item">
                <a class="link <?= $currentPage == 'qr_code.php' ? 'active' : '' ?>" href="qr_code.php">
                  <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                      <rect x="3" y="3" width="7" height="7"></rect>
                      <rect x="14" y="3" width="7" height="7"></rect>
                      <rect x="14" y="14" width="7" height="7"></rect>
                      <rect x="3" y="14" width="7" height="7"></rect>
                    </svg>
                  </span>
                  <span class="nav-label">QR Code</span>
                </a>
              </li>
            <?php endif; ?>

            <?php if($role === 'admin' || $role === 'user' || $role === 'viewer'): ?>
              <!-- Statistiques -->
              <li class="item">
                <a class="link <?= $currentPage == 'stats.php' ? 'active' : '' ?>" href="stats.php">
                  <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                      <path d="M3 3v18h18"></path>
                      <path d="M18 17V9M12 17V5M6 17v-3"></path>
                    </svg>
                  </span>
                  <span class="nav-label">Statistiques</span>
                </a>
              </li>
            <?php endif; ?>
          </ul>
        </nav>
      </div>

      <!-- Boutons d'action (Mode T-shirts + Déconnexion) -->
      <div class="nav-cta">
        <?php if($currentPage == 'dashboard.php'): ?>
          <button id="modeTS" class="btn-mode-tshirt" type="button">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
            </svg>
            <span>Mode standard</span>
          </button>
        <?php endif; ?>

        <button id="adminLogout" class="btn-logout" type="button">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
          <span>Déconnexion</span>
        </button>
      </div>
    </div>
  </div>
</header>



<style>
/* Correctifs pour l'affichage admin */
/* Masquer les éléments hero et barres d'onglets parasites */
body.d-flex.flex-column > .hero,
body > .hero,
.admin-tabs-bar {
  display: none !important;
}

/* Réinitialiser le background pour les pages admin */
body {
  background: var(--page-bg, #ffffff) !important;
  background-image: none !important;
}

/* S'assurer que la navbar admin est bien visible */
#navRoot {
  position: relative;
  z-index: 1000;
}

/* Ajuster le main pour qu'il commence directement après la navbar */
body.d-flex.flex-column > main {
  margin-top: 0;
  padding-top: 1rem;
}

/* Styles supplémentaires pour les boutons admin */
.nav-cta {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-left: 1rem;
}

.btn-mode-tshirt,
.btn-logout {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  border-radius: 50px;
  border: none;
  font-size: 0.875rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
  white-space: nowrap;
}

.btn-mode-tshirt {
  background: linear-gradient(135deg, rgba(236,72,153,0.1) 0%, rgba(219,39,119,0.1) 100%);
  color: var(--pink);
  border: 1px solid rgba(236,72,153,0.3);
}

.btn-mode-tshirt:hover {
  background: linear-gradient(135deg, rgba(236,72,153,0.15) 0%, rgba(219,39,119,0.15) 100%);
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(236,72,153,0.2);
}

.btn-logout {
  background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
  color: white;
}

.btn-logout:hover {
  background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(239,68,68,0.3);
}

.btn-mode-tshirt svg,
.btn-logout svg {
  flex-shrink: 0;
}

/* Mobile - masquer les boutons sur mobile */
@media (max-width: 980px) {
  .nav-cta {
    display: none;
  }
}

/* Drawer mobile - styles additionnels */
.drawer-info {
  background: linear-gradient(135deg, #fdf2f8 0%, #fce7f3 100%);
  padding: 1rem;
  border-radius: 12px;
  margin-bottom: 1.5rem;
  border: 1px solid #fbcfe8;
}

.drawer-page-title {
  font-size: 1rem;
  font-weight: 700;
  color: #be185d;
  margin-bottom: 0.25rem;
}

.drawer-role {
  font-size: 0.875rem;
  color: #9f1239;
}

.drawer-role strong {
  font-weight: 600;
}

.drawer-divider {
  height: 1px;
  background: linear-gradient(90deg, transparent, rgba(0,0,0,0.1), transparent);
  margin: 1rem 0;
}

.drawer-btn {
  background: transparent;
  border: none;
  width: 100%;
  text-align: left;
  font-size: 0.95rem;
}

.drawer-logout {
  color: #dc2626;
}

.drawer-logout:hover {
  background: #fef2f2;
  color: #dc2626;
}

/* Classe active pour les liens */
.link.active {
  background: rgba(236,72,153,0.1);
  color: var(--pink);
}

.link.active .nav-icon svg {
  stroke: var(--pink);
}

.drawer-link.active {
  background: linear-gradient(135deg, #fdf2f8 0%, #fce7f3 100%);
  color: #be185d;
  font-weight: 600;
}
</style>

<script>
// Gestion de la navbar admin
document.addEventListener('DOMContentLoaded', function() {
  const burgerBtn = document.getElementById('burgerBtn');
  const drawer = document.getElementById('mobileDrawer');
  const drawerClose = document.getElementById('drawerClose');
  const overlay = document.getElementById('megaOverlay');
  const logoutBtn = document.getElementById('adminLogout');
  const logoutMobileBtn = document.getElementById('adminLogoutMobile');
  const modeBtn = document.getElementById('modeTS');
  const modeBtnMobile = document.getElementById('modeTS_m');

  // Toggle drawer mobile
  if (burgerBtn) {
    burgerBtn.addEventListener('click', function() {
      const isOpen = drawer.getAttribute('aria-hidden') === 'false';
      drawer.setAttribute('aria-hidden', !isOpen);
      overlay.classList.toggle('show', !isOpen);
      document.body.style.overflow = !isOpen ? 'hidden' : '';
    });
  }

  // Fermer drawer
  function closeDrawer() {
    drawer.setAttribute('aria-hidden', 'true');
    overlay.classList.remove('show');
    document.body.style.overflow = '';
  }

  if (drawerClose) {
    drawerClose.addEventListener('click', closeDrawer);
  }

  if (overlay) {
    overlay.addEventListener('click', closeDrawer);
  }

  // Déconnexion
  function handleLogout(e) {
    e.preventDefault();
    fetch('../config/api.php?route=logout')
      .then(() => location.href = '../login.php');
  }

  if (logoutBtn) {
    logoutBtn.addEventListener('click', handleLogout);
  }

  if (logoutMobileBtn) {
    logoutMobileBtn.addEventListener('click', handleLogout);
  }

  // Mode T-shirts (si présent)
  if (modeBtn || modeBtnMobile) {
    // Cette fonctionnalité sera gérée par le code existant de dashboard.php
    // Les IDs #modeTS et #modeTS_m sont déjà utilisés dans dashboard.php
  }
});
</script>
