<!-- Barre HERO en haut -->
<section class="hero">
<?php if (!empty($picture)): ?>
<img src="../files/_pictures/<?= htmlspecialchars($picture) ?>"
    alt="Logo Forbach en Rose" class="logo-top">
<?php endif; ?>
<div class="hero-inner">
    <h1 style="color: <?= htmlspecialchars($titleColor) ?>;"><?= htmlspecialchars($titleAccueil) ?></h1>
    <span class="badge-donation"><?= htmlspecialchars($edition) ?></span>
</div>
</section>

<!-- Navigation -->
<?php
// partenaires
$stmtYears = $pdo->prepare('SELECT id, title FROM partners_years ORDER BY year DESC');
$stmtYears->execute();
$partnersNav = $stmtYears->fetchAll(PDO::FETCH_ASSOC);

// photos
$stmtPhotos = $pdo->prepare('SELECT id, title FROM photo_years ORDER BY year DESC');
$stmtPhotos->execute();
$albumsNav = $stmtPhotos->fetchAll(PDO::FETCH_ASSOC);
?>
<nav class="nav-flottante" aria-label="Navigation principale">
  <a href="accueil.php" class="nav-brand">
    <img src="../files/_logos/logo_fer_rose.png" alt="Forbach en Rose" class="nav-logo">
  </a>

  <div class="nav-links">
    <a href="accueil.php" class="nav-link accueil-style">
      <span class="nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24">
          <path d="M3 11.5l9-7 9 7"></path>
          <path d="M5 10.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10.5"></path>
        </svg>
      </span>
      <span>Accueil</span>
    </a>
    <a href="parcours.php" class="nav-link menu-cache">
      <span class="nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24">
          <path d="M5 3v18"></path>
          <path d="M5 4h11l-2 4 2 4H5"></path>
        </svg>
      </span>
      <span>Parcours</span>
    </a>
    <div class="nav-item dropdown menu-cache">
      <a href="#" class="nav-link partenaires-toggle" onclick="return false;">
        <span class="nav-ico" aria-hidden="true">
          <svg viewBox="0 0 24 24">
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
            <circle cx="9" cy="7" r="4"></circle>
            <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
          </svg>
        </span>
        <span>Partenaires</span>
        <svg class="chevron-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" aria-hidden="true">
          <path d="M1.646 5.646a.5.5 0 0 1 .708 0L8 11.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
        </svg>
      </a>
      <div class="dropdown-content-custom" id="dropdownPartenaires">
        <?php
        if (empty($partnersNav)) {
            echo '<span style="display:block; padding:0.5rem 1rem; color:#999;">Aucun partenaires disponible</span>';
        } else {
            foreach ($partnersNav as $yearNav) {
                echo '<a href="partenaires.php?year_id=' . htmlspecialchars($yearNav['id']) . '">' . htmlspecialchars($yearNav['title']) . '</a>';
            }
        }
        ?>
      </div>
    </div>

    <div class="nav-item dropdown menu-cache">
      <a href="#" class="nav-link photos-toggle" onclick="return false;">
        <span class="nav-ico" aria-hidden="true">
          <svg viewBox="0 0 24 24">
            <path d="M4 7h4l2-2h4l2 2h4v12H4z"></path>
            <circle cx="12" cy="13" r="3"></circle>
          </svg>
        </span>
        <span>Photos</span>
        <svg class="chevron-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" aria-hidden="true">
          <path d="M1.646 5.646a.5.5 0 0 1 .708 0L8 11.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
        </svg>
      </a>
      <div class="dropdown-content-custom" id="dropdownPhotos">
        <?php
        if (empty($albumsNav)) {
            echo '<span style="display:block; padding:0.5rem 1rem; color:#999;">Aucun album disponible</span>';
        } else {
            foreach ($albumsNav as $albumNav) {
                echo '<a href="photos.php?year_id=' . htmlspecialchars($albumNav['id']) . '">' . htmlspecialchars($albumNav['title']) . '</a>';
            }
        }
        ?>
      </div>
    </div>

    <a href="news.php" class="nav-link menu-cache">
      <span class="nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24">
          <rect x="3" y="4" width="18" height="16" rx="2"></rect>
          <line x1="7" y1="8" x2="17" y2="8"></line>
          <line x1="7" y1="12" x2="17" y2="12"></line>
          <line x1="7" y1="16" x2="14" y2="16"></line>
        </svg>
      </span>
      <span>Actualités</span>
    </a>
  </div>

  <a href="register.php" class="nav-cta">
    <span class="nav-ico" aria-hidden="true">
      <svg viewBox="0 0 24 24">
        <path d="M4 7h16v4a2 2 0 0 0 0 2v4H4v-4a2 2 0 0 0 0-2V7z"></path>
        <path d="M9 7v10"></path>
      </svg>
    </span>
    <span>Inscription</span>
    <svg class="cta-arrow" viewBox="0 0 20 20" aria-hidden="true">
      <path d="M7 14l5-5-5-5"></path>
    </svg>
  </a>

  <!-- Bouton burger -->
  <button class="burger-toggle d-md-none" aria-label="Menu"></button>

  <!-- Menu déroulant mobile -->
  <div class="menu-deroulant" id="mobileMenu">
    <a href="accueil.php">
      <span class="nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24">
          <path d="M3 11.5l9-7 9 7"></path>
          <path d="M5 10.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10.5"></path>
        </svg>
      </span>
      Accueil
    </a>
    <a href="register.php" class="menu-cta">
      <span class="nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24">
          <path d="M4 7h16v4a2 2 0 0 0 0 2v4H4v-4a2 2 0 0 0 0-2V7z"></path>
          <path d="M9 7v10"></path>
        </svg>
      </span>
      Inscription
    </a>
    <a href="parcours.php">
      <span class="nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24">
          <path d="M5 3v18"></path>
          <path d="M5 4h11l-2 4 2 4H5"></path>
        </svg>
      </span>
      Parcours
    </a>
    <div class="dropdown-mobile">
      <a href="#" class="partenaires-toggle-mobile" onclick="toggleMobileDropdown(event)">
        <span class="nav-ico" aria-hidden="true">
          <svg viewBox="0 0 24 24">
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
            <circle cx="9" cy="7" r="4"></circle>
            <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
          </svg>
        </span>
        Partenaires
        <svg class="chevron-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" aria-hidden="true">
          <path d="M1.646 5.646a.5.5 0 0 1 .708 0L8 11.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
        </svg>
      </a>
      <div class="dropdown-content-mobile" id="dropdownMobilePartenaires">
        <?php
        if (empty($partnersNav)) {
            echo '<span style="display:block; padding:0.5rem 1rem; color:#999;">Aucun partenaires disponible</span>';
        } else {
            foreach ($partnersNav as $yearNav) {
                echo '<a href="partenaires.php?year_id=' . htmlspecialchars($yearNav['id']) . '">' . htmlspecialchars($yearNav['title']) . '</a>';
            }
        }
        ?>
      </div>
    </div>
    <a href="#" class="photos-toggle-mobile" onclick="toggleMobilePhotosDropdown(event)">
      <span class="nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24">
          <path d="M4 7h4l2-2h4l2 2h4v12H4z"></path>
          <circle cx="12" cy="13" r="3"></circle>
        </svg>
      </span>
      Photos
      <svg class="chevron-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" aria-hidden="true">
        <path d="M1.646 5.646a.5.5 0 0 1 .708 0L8 11.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
      </svg>
    </a>
    <div class="dropdown-content-mobile" id="dropdownMobilePhotos">
      <?php
      if (empty($albumsNav)) {
          echo '<span style="display:block; padding:0.5rem 1rem; color:#999;">Aucun album disponible</span>';
      } else {
          foreach ($albumsNav as $albumNav) {
              echo '<a href="photos.php?year_id=' . htmlspecialchars($albumNav['id']) . '">' . htmlspecialchars($albumNav['title']) . '</a>';
          }
      }
      ?>
    </div>
    <a href="news.php">
      <span class="nav-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24">
          <rect x="3" y="4" width="18" height="16" rx="2"></rect>
          <line x1="7" y1="8" x2="17" y2="8"></line>
          <line x1="7" y1="12" x2="17" y2="12"></line>
          <line x1="7" y1="16" x2="14" y2="16"></line>
        </svg>
      </span>
      Actualités
    </a>
  </div>
</nav>
