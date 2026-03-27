<?php
/**
 * Navbar Moderne - Forbach en Rose
 * Fichier include pour la navbar moderne réutilisable sur toutes les pages
 *
 * Requires: navbar-data.php doit être inclus AVANT ce fichier
 * Variables requises: $galeries, $actualites, $partenaires, $link_facebook, $link_instagram, $link_cancer
 */
?>

<!-- Theme: apply saved preference immediately to avoid flash -->
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){var t=localStorage.getItem('fer-theme');if(t==='dark')document.body.classList.add('dark-theme');})();
</script>

<!-- NAV -->
<header class="floating-nav" id="navRoot">
  <div class="mega-overlay" id="megaOverlay"></div>
  <div class="nav-pill">
    <a class="brand" href="accueil">
      <img class="brand-logo" src="../files/_logos/logo_fer_rose.png" alt="Forbach en Rose">
    </a>

    <button class="burger" id="burgerBtn" aria-expanded="false" aria-controls="mobileDrawer">
      <span class="sr-only">Ouvrir le menu</span>
      <span class="burger-icon" aria-hidden="true"></span>
    </button>

    <div class="nav-right">
      <div class="nav-card">
        <nav id="nav-links" class="links" aria-label="Navigation principale">
          <ul class="menu nav-secondary">
        <li class="item">
          <a class="link" href="accueil">
            <span class="nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                <polyline points="9,22 9,12 15,12 15,22"></polyline>
              </svg>
            </span>
            <span class="nav-label">Accueil</span>
          </a>
        </li>
        <li class="item">
          <a class="link" href="parcours">
            <span class="nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                <circle cx="12" cy="12" r="10"></circle>
                <polygon points="10,8 16,12 10,16 10,8"></polygon>
              </svg>
            </span>
            <span class="nav-label">Parcours</span>
          </a>
        </li>

        <!-- Menu Actualités -->
        <li class="item" data-menu="actualites">
          <button class="trigger" type="button" aria-haspopup="true" aria-expanded="false">
            <span class="nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                <line x1="7" y1="8" x2="17" y2="8"></line>
                <line x1="7" y1="12" x2="17" y2="12"></line>
                <line x1="7" y1="16" x2="14" y2="16"></line>
              </svg>
            </span>
            <span class="nav-label">Actualités</span>
            <svg class="chev" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M6 9l6 6 6-6" stroke="rgba(15,23,42,.8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>

          <div class="mega<?= $actualites_cols2 ? ' mega--wide' : '' ?>" role="menu" aria-hidden="true">
            <div class="mega-grid">
              <!-- Colonne gauche : liens -->
              <div class="mega-content">
                <div class="mega-section">
                  <div class="mega-title">Dernières actualités</div>
                  <ul class="mega-list<?= $actualites_cols2 ? ' mega-list--2col' : '' ?>">
                    <?php if (!empty($actualites)): ?>
                      <?php foreach ($actualites as $actu): ?>
                        <li>
                          <a class="mega-link" href="news?id=<?= $actu['id'] ?>">
                            <span class="micon">📰</span>
                            <div class="mega-link-content">
                              <div class="mtitle"><?= htmlspecialchars($actu['title']) ?></div>
                            </div>
                          </a>
                        </li>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <li>
                        <a class="mega-link" href="news">
                          <span class="micon">📰</span>
                          <div class="mega-link-content">
                            <div class="mtitle">Voir toutes les actualités</div>
                          </div>
                        </a>
                      </li>
                    <?php endif; ?>
                  </ul>
                </div>
              </div>

              <!-- Colonne droite : image -->
              <div class="mega-featured">
                <div class="mega-featured-img">📰</div>
                <div class="mega-featured-title">Toutes nos actualités</div>
                <div class="mega-featured-desc">Restez informés de tous les événements et nouveautés de Forbach en Rose</div>
                <a href="news" class="mega-featured-link">
                  Voir tout
                  <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M7 14L12 9L7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
              </div>
            </div>
          </div>
        </li>

        <!-- Menu Photos -->
        <li class="item" data-menu="photos">
          <button class="trigger" type="button" aria-haspopup="true" aria-expanded="false">
            <span class="nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                <path d="M4 7h4l2-2h4l2 2h4v12H4z"></path>
                <circle cx="12" cy="13" r="3"></circle>
              </svg>
            </span>
            <span class="nav-label">Photos</span>
            <svg class="chev" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M6 9l6 6 6-6" stroke="rgba(15,23,42,.8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>

          <div class="mega<?= $galeries_cols2 ? ' mega--wide' : '' ?>" role="menu" aria-hidden="true">
            <div class="mega-grid">
              <div class="mega-content">
                <div class="mega-section">
                  <div class="mega-title">Albums photos</div>
                  <ul class="mega-list<?= $galeries_cols2 ? ' mega-list--2col' : '' ?>">
                    <?php if (!empty($galeries)): ?>
                      <?php foreach ($galeries as $galerie): ?>
                        <li>
                          <a class="mega-link" href="photos?year_id=<?= $galerie['id'] ?>">
                            <span class="micon">📸</span>
                            <div class="mega-link-content">
                              <div class="mtitle"><?= htmlspecialchars($galerie['title']) ?> (<?= $galerie['year'] ?>)</div>
                            </div>
                          </a>
                        </li>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <li>
                        <a class="mega-link" href="photos">
                          <span class="micon">📸</span>
                          <div class="mega-link-content">
                            <div class="mtitle">Voir tous les albums</div>
                          </div>
                        </a>
                      </li>
                    <?php endif; ?>
                  </ul>
                </div>
              </div>

              <div class="mega-featured">
                <div class="mega-featured-img">📸</div>
                <div class="mega-featured-title">Nos albums photos</div>
                <div class="mega-featured-desc">Découvrez tous les moments forts de Forbach en Rose en images</div>
                <a href="photos" class="mega-featured-link">
                  Voir tout
                  <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M7 14L12 9L7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
              </div>
            </div>
          </div>
        </li>

        <!-- Menu Partenaires -->
        <li class="item" data-menu="partenaires">
          <button class="trigger" type="button" aria-haspopup="true" aria-expanded="false">
            <span class="nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
              </svg>
            </span>
            <span class="nav-label">Partenaires</span>
            <svg class="chev" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M6 9l6 6 6-6" stroke="rgba(15,23,42,.8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>

          <div class="mega<?= $partenaires_cols2 ? ' mega--wide' : '' ?>" role="menu" aria-hidden="true">
            <div class="mega-grid">
              <div class="mega-content">
                <div class="mega-section">
                  <div class="mega-title">Nos partenaires</div>
                  <ul class="mega-list<?= $partenaires_cols2 ? ' mega-list--2col' : '' ?>">
                    <?php if (!empty($partenaires)): ?>
                      <?php foreach ($partenaires as $part): ?>
                        <li>
                          <a class="mega-link" href="partenaires?year_id=<?= $part['id'] ?>">
                            <span class="micon">🤝</span>
                            <div class="mega-link-content">
                              <div class="mtitle"><?= htmlspecialchars($part['title']) ?> (<?= $part['year'] ?>)</div>
                            </div>
                          </a>
                        </li>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <li>
                        <a class="mega-link" href="partenaires">
                          <span class="micon">🤝</span>
                          <div class="mega-link-content">
                            <div class="mtitle">Voir tous les partenaires</div>
                          </div>
                        </a>
                      </li>
                    <?php endif; ?>
                  </ul>
                </div>
              </div>

              <div class="mega-featured">
                <div class="mega-featured-img">🤝</div>
                <div class="mega-featured-title">Nos partenaires</div>
                <div class="mega-featured-desc">Merci à tous nos partenaires qui soutiennent Forbach en Rose</div>
                <a href="partenaires" class="mega-featured-link">
                  Voir tout
                  <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M7 14L12 9L7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
              </div>
            </div>
          </div>
        </li>
          </ul>
        </nav>
      </div>
      <div class="cta">
        <a class="btn pink nav-cta" href="register">Inscription<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 14L12 9L7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
      </div>
    </div>
  </div>
</header>

<!-- ===== MOBILE HEADER (Vimeo style) ===== -->
<header class="mobile-header" id="mobileHeader">
  <a class="brand" href="accueil">
    <img class="brand-logo" src="../files/_logos/logo_fer_rose.png" alt="Forbach en Rose">
  </a>
</header>

<!-- ===== MOBILE BOTTOM BAR + UNIFIED MENU (Vimeo style) ===== -->
<div class="mobile-bottom-bar" id="mobileBottomBar">
  <div class="mobile-bottom-wrapper" id="mobileWrapper">

    <!-- Main nav block (unified: menu panel + action buttons) -->
    <div class="mobile-bottom-unified" id="mobileUnified">

      <!-- Menu panel (hidden, slides in on open) -->
      <div class="mobile-menu-panel" id="mobileMenuPanel">
        <div class="mobile-menu-header">
          <button class="mobile-menu-back" id="mobileMenuBack" aria-label="Retour">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="18" height="18">
              <path d="M15 18l-6-6 6-6"/>
            </svg>
          </button>
          <span class="mobile-menu-title" id="mobileMenuTitleText">Menu</span>
          <button class="mobile-menu-close" id="mobileMenuClose" aria-label="Fermer">✕</button>
        </div>

        <div class="mobile-menu-slides" id="mobileMenuSlides">
          <!-- MAIN VIEW -->
          <div class="mobile-menu-slide mobile-menu-slide-main" id="slideMain">
            <div class="mobile-menu-body">
              <nav class="mobile-menu-nav">
                <div class="mobile-menu-item" data-sub="actualites">
                  <button class="mobile-menu-trigger">
                    <div class="mobile-menu-trigger-content">
                      <span class="mobile-menu-icon">
<svg viewBox="0 0 24 24" aria-hidden="true">
  <rect x="3" y="4" width="18" height="16" rx="2"></rect>
  <line x1="7" y1="8" x2="17" y2="8"></line>
  <line x1="7" y1="12" x2="17" y2="12"></line>
  <line x1="7" y1="16" x2="14" y2="16"></line>
</svg>
</span>
                      Actualités
                    </div>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                  </button>
                </div>
                <div class="mobile-menu-item" data-sub="photos">
                  <button class="mobile-menu-trigger">
                    <div class="mobile-menu-trigger-content">
                      <span class="mobile-menu-icon">
<svg viewBox="0 0 24 24" aria-hidden="true">
  <path d="M4 7h4l2-2h4l2 2h4v12H4z"></path>
  <circle cx="12" cy="13" r="3"></circle>
</svg>
</span>
                      Photos
                    </div>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                  </button>
                </div>
                <div class="mobile-menu-item" data-sub="partenaires">
                  <button class="mobile-menu-trigger">
                    <div class="mobile-menu-trigger-content">
                      <span class="mobile-menu-icon">
<svg viewBox="0 0 24 24" aria-hidden="true">
  <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
  <circle cx="9" cy="7" r="4"></circle>
  <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
  <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
</svg>
</span>
                      Partenaires
                    </div>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                  </button>
                </div>

              </nav>
            </div>

            <div class="mobile-menu-footer">
              <?php if (!empty($link_facebook)): ?>
              <a class="mobile-menu-footer-btn" href="<?= htmlspecialchars($link_facebook) ?>" target="_blank" rel="noopener">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                  <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                </svg>
                <span>Facebook</span>
              </a>
              <?php endif; ?>
              <?php if (!empty($link_instagram)): ?>
              <a class="mobile-menu-footer-btn" href="<?= htmlspecialchars($link_instagram) ?>" target="_blank" rel="noopener">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                  <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                </svg>
                <span>Instagram</span>
              </a>
              <?php endif; ?>
              <?php if (!empty($link_cancer)): ?>
              <a class="mobile-menu-footer-btn" href="<?= htmlspecialchars($link_cancer) ?>" target="_blank" rel="noopener" aria-label="Ligue contre le cancer">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                  <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                </svg>
                <span>La ligue</span>
              </a>
              <?php endif; ?>
            </div>
          </div>

          <!-- SUB VIEW: Actualités -->
          <div class="mobile-menu-slide mobile-menu-slide-sub" id="slideSub-actualites" data-title="Actualités">
            <div class="mobile-menu-body">
              <?php if (!empty($actualites)): ?>
                <?php foreach ($actualites as $actu): ?>
                  <a class="mobile-menu-sublink" href="news?id=<?= $actu['id'] ?>">
                    <span class="menu-bullet" aria-hidden="true"></span>
                    <?= htmlspecialchars($actu['title']) ?>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
            <a class="mobile-menu-see-all" href="news">
              Voir toutes les actualités
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </a>
          </div>

          <!-- SUB VIEW: Photos -->
          <div class="mobile-menu-slide mobile-menu-slide-sub" id="slideSub-photos" data-title="Photos">
            <div class="mobile-menu-body">
              <?php if (!empty($galeries)): ?>
                <?php foreach ($galeries as $galerie): ?>
                  <a class="mobile-menu-sublink" href="photos?year_id=<?= $galerie['id'] ?>">
                    <span class="menu-bullet" aria-hidden="true"></span>
                    <?= htmlspecialchars($galerie['title']) ?> (<?= $galerie['year'] ?>)
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
            <a class="mobile-menu-see-all" href="photos">
              Voir tous les albums
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </a>
          </div>

          <!-- SUB VIEW: Partenaires -->
          <div class="mobile-menu-slide mobile-menu-slide-sub" id="slideSub-partenaires" data-title="Partenaires">
            <div class="mobile-menu-body">
              <?php if (!empty($partenaires)): ?>
                <?php foreach ($partenaires as $part): ?>
                  <a class="mobile-menu-sublink" href="partenaires?year_id=<?= $part['id'] ?>">
                    <span class="menu-bullet" aria-hidden="true"></span>
                    <?= htmlspecialchars($part['title']) ?> (<?= $part['year'] ?>)
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
            <a class="mobile-menu-see-all" href="partenaires">
              Voir tous les partenaires
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </a>
          </div>
        </div>
      </div>

      <!-- Bottom action buttons (always visible) -->
      <div class="mobile-bottom-actions">
        <button class="mobile-bottom-btn" id="mobileMenuBtn" aria-label="Menu">
          <svg class="menu-icon-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
          </svg>
          <svg class="menu-icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
          </svg>
          <span>Menu</span>
        </button>
        <a class="mobile-bottom-btn" href="accueil">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
            <polyline points="9,22 9,12 15,12 15,22"></polyline>
          </svg>
          <span>Accueil</span>
        </a>
        <a class="mobile-bottom-btn" href="parcours">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <polygon points="10,8 16,12 10,16 10,8"></polygon>
          </svg>
          <span>Parcours</span>
        </a>
        <!-- Inner CTA: visible only when menu is open -->
        <a class="mobile-bottom-cta-inner" href="register">Inscription</a>
      </div>
    </div>

    <!-- Outer CTA: visible only when menu is closed -->
    <a class="mobile-bottom-cta" href="register">Inscription</a>
  </div>
</div>

  <!-- ===== MOBILE MENU BACKDROP ===== -->
<div class="mobile-menu-backdrop" id="mobileMenuBackdrop"></div>
