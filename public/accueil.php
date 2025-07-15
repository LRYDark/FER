<?php require '../config/config.php';
$stmtcount = $pdo->prepare('SELECT COUNT(*) AS total FROM registrations');
$stmtcount->execute();
$count = $stmtcount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$stmt = $pdo->prepare(
    'SELECT *
       FROM setting
      WHERE id = :id
      LIMIT 1');
$stmt->execute(['id' => 1]);

$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$titleAccueil  = $data['titleAccueil']   ?? '';
$picture= $data['picture'] ?? '';  
$titleColor = $data['title_color'] ?? '#ffffff';
$edition = $data['edition'] ?? '';  
$link_instagram  = $data['link_instagram'] ?? null;
$link_facebook = $data['link_facebook'] ?? null; 
$accueil_active = $data['accueil_active'] ? 1 : 0;
$date_course = $data['date_course'] ?? null;
$date_formatted = $date_course ? date('Y-m-d', strtotime($date_course)) : '';
$picture_partner= $data['picture_partner'] ?? ''; 
$picture_accueil= $data['picture_accueil'] ?? ''; 
$footer= $data['footer'] ?? null;  
$social_networks = $data['social_networks'] ?? 0;
$link_cancer = $data['link_cancer'] ?? null;

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Accueil - Forbach en Rose</title>
  <link rel="stylesheet" href="../css/forbach-style.css">
  <link rel="stylesheet" href="../css/accueil.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <script src="../js/nav-flottante.js"></script>
</head>
<body>

  <!-- Barre HERO en haut -->
  <section class="hero">
    <img src="../files/_pictures/<?= htmlspecialchars($picture) ?>"
        alt="Logo Forbach en Rose" class="logo-top">
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
  <nav class="nav-flottante">
    <a href="accueil.php" class="nav-item accueil-style">Accueil</a>
    <a href="register.php" class="nav-item">Inscription</a>
    <a href="parcours.php" class="nav-item menu-cache">Parcours</a>
    <div class="nav-item dropdown menu-cache" style="position: relative;">
        <a href="#" class="nav-link partenaires-toggle" onclick="toggleDropdown(event)">
            Partenaires <svg class="chevron-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#e91e63" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M1.646 5.646a.5.5 0 0 1 .708 0L8 11.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/></svg>
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

    <div class="nav-item dropdown menu-cache" style="position: relative;">
        <a href="#" class="nav-link photos-toggle" onclick="togglePhotosDropdown(event)">
            Photos
            <svg class="chevron-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#e91e63" viewBox="0 0 16 16">
            <path fill-rule="evenodd" d="M1.646 5.646a.5.5 0 0 1 .708 0L8 11.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
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
    
    <a href="news.php" class="nav-item menu-cache">Actualités</a>

    <!-- Bouton burger -->
    <button class="burger-toggle d-md-none" aria-label="Menu"></button>

    <!-- Menu déroulant mobile -->
    <div class="menu-deroulant" id="mobileMenu">
    <a href="parcours.php">Parcours</a>
    <div class="dropdown-mobile">
        <a href="#" class="partenaires-toggle-mobile" onclick="toggleMobileDropdown(event)">
        Partenaires
        <svg class="chevron-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#e91e63" viewBox="0 0 16 16">
            <path fill-rule="evenodd" d="M1.646 5.646a.5.5 0 0 1 .708 0L8 11.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
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
            Photos
            <svg class="chevron-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#e91e63" viewBox="0 0 16 16">
            <path fill-rule="evenodd" d="M1.646 5.646a.5.5 0 0 1 .708 0L8 11.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
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
        <a href="news.php">Actualités</a>
    </div>
  </nav>

  <?php
  // Si désactivé (0), on ne génère rien
  if ($social_networks != 0):
    // Détermine la classe de position
    $positionClass = match($social_networks) {
      1 => 'left',
      2 => 'right',
      3 => 'center',
      default => 'center'
    };
  ?>
  <style>
    /* ─────── TOP LOGOS POSITION ─────── */
    .top-logos {
      display: flex;
      gap: 1rem;
      padding: 1rem 2rem 0;
      align-items: center;
      z-index: 10;
    }

    .top-logos.left     { justify-content: flex-start; }
    .top-logos.right    { justify-content: flex-end; }
    .top-logos.center   { justify-content: center; }

    .top-logos img {
      height: 62px;
      filter: drop-shadow(0 1px 2px rgba(0,0,0,0.2));
      transition: transform 0.2s ease;
    }

    .top-logos img:hover {
      transform: scale(1.1);
    }

    @media (max-width: 575.98px) {
      .top-logos {
        justify-content: center !important; /* ✅ toujours centré en mobile */
        padding: 0.5rem;
      }
      .top-logos img {
        height: 40px;
      }
    }
  </style>

  <!-- Logos réseaux sociaux + Ligue -->
  <div class="top-logos <?= $positionClass ?>">
    <a href="<?= htmlspecialchars($link_cancer, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" aria-label="Ligue contre le Cancer">
      <img src="../files/_logos/ligue-cancer.png" alt="Ligue contre le cancer">
    </a>
    <a href="<?= htmlspecialchars($link_instagram, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" aria-label="Instagram">
      <img src="../files/_logos/instagram.png" alt="Instagram">
    </a>
    <a href="<?= htmlspecialchars($link_facebook, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" aria-label="Facebook">
      <img src="../files/_logos/facebook.png" alt="Facebook">
    </a>
  </div>
  <?php endif; ?>

  <!-- Image + Compteur -->
  <section class="hero-accueil">
    <div class="image-container">
      <img src="../files/_pictures/<?= htmlspecialchars($picture_accueil) ?>" alt="Forbach en rose">
      <div class="countdown-group" id="countdown"></div>
    </div>
  </section>

  <!-- Nombre d'inscrits -->
  <div class="inscrits">
    Déjà <strong><span id="nb-inscrits" class="txt-rose"><?= $count ?></span></strong> inscrits !
  </div>

  <!-- Inscription -->
  <section class="section-inscription">
    <div>
      <h2>Rejoignez-nous !</h2>
      <p>Inscrivez-vous dès maintenant pour participer à l'événement</p>
      <a href="register.php" class="btn btn-inscription">Je m'inscris</a>
    </div>
  </section>

  <!-- Partenaires -->
  <section class="partenaires" id="partenaire">
    <h2>Nos Partenaires</h2>
    <p>Merci à tous nos sponsors et soutiens</p>
    <img src="../files/_pictures/<?= htmlspecialchars($picture_partner) ?>" alt="Logos des partenaires">
  </section>

  <!-- Footer -->
  <?php if (!empty($link_facebook) || !empty($link_instagram)) : ?>
    <footer>
      <div class="top-logos-footer">
        <a href="<?= htmlspecialchars($link_cancer, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" aria-label="Ligue contre le Cancer">
          <img src="../files/_logos/ligue-cancer-blanc.png" alt="Ligue contre le cancer">
        </a>  
        <?php if (!empty($link_instagram)) : ?>
          <a href="<?= htmlspecialchars($link_instagram, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" aria-label="Instagram">
            <img src="../files/_logos/instagram.png" alt="Instagram">
          </a>
        <?php endif; ?>
        <?php if (!empty($link_facebook)) : ?>
          <a href="<?= htmlspecialchars($link_facebook, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" aria-label="Facebook">
            <img src="../files/_logos/facebook.png" alt="Facebook">
          </a>
        <?php endif; ?>
      </div>
      <?php if (!empty($footer)) : ?>
        <?= htmlspecialchars($footer) ?>
      <?php endif; ?>
    </footer>
  <?php endif; ?>

  <!-- JS Compte à rebours -->
  <script>
    const countdown = document.getElementById('countdown');
    //const targetDate = new Date("2026-07-05T00:00:00").getTime();
    const targetDate = new Date("<?= $date_formatted; ?>").getTime();

    function updateCountdown() {
      const now = new Date().getTime();
      const distance = targetDate - now;

      if (distance <= 0) {
        countdown.innerHTML = `<div class="bloc"><div class="valeur">C'est</div><div class="label">le jour J !</div></div>`;
        return;
      }

      const days = Math.floor(distance / (1000 * 60 * 60 * 24));
      const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
      const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
      const seconds = Math.floor((distance % (1000 * 60)) / 1000);

      countdown.innerHTML = `
        <div class="bloc"><div class="valeur">${days}</div><div class="label">Jours</div></div>
        <div class="bloc"><div class="valeur">${hours}</div><div class="label">Heures</div></div>
        <div class="bloc"><div class="valeur">${minutes}</div><div class="label">Minutes</div></div>
        <div class="bloc"><div class="valeur">${seconds}</div><div class="label">Secondes</div></div>
      `;
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);


  </script>
</body>
</html>
