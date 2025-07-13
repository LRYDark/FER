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
$footer= $data['footer'] ?? null;  

// parcours
$titleParcours  = $data['titleParcours']   ?? 'test';
$parcoursDesc = $data['parcoursDesc'] ?? '';  
$picture_parcours= $data['picture_parcours'] ?? ''; 
$picture_gradient= $data['picture_gradient'] ?? ''; 

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

  <section class="main-illustration boxsize my-5">
    <div class="row align-items-center">
      <div class="col-md-6">
        <img src="../files/_pictures/<?= htmlspecialchars($picture_parcours) ?>" alt="Image principale" class="img-fluid main-img lightbox-trigger" loading="lazy">
      </div>
      <div class="col-md-6">
        <h2 class="mb-3"><?= htmlspecialchars($titleParcours) ?></h2>
        <p class="lead"><?= htmlspecialchars($parcoursDesc) ?></p>
      </div>
    </div>
  </section>

  <?php if (!empty($picture_gradient)) : ?>
    <div class="section-divider my-4 text-center boxsize">
      <img src="../files/_pictures/<?= htmlspecialchars($picture_gradient) ?>"
          alt="Illustration intermédiaire"
          class="img-fluid img-separatrice lightbox-trigger"
          loading="lazy">
    </div>
  <?php endif; ?>

<!-- ######################## Albums du parcours ######################## -->

<section class="album-style boxsize my-5">
  <div class="masonry">
    <?php
      $exts = ['jpg','jpeg','png','webp','gif'];
      $photos = [];
      foreach ($exts as $ext) $photos = array_merge($photos, glob('../files/_parcours/*.' . $ext));
      usort($photos, fn($a, $b) => filemtime($b) - filemtime($a));
      foreach (array_slice($photos, 0, 30) as $file) {
        echo '<img src="' . htmlspecialchars($file) . '" alt="photo" class="masonry-img" loading="lazy">';
      }
    ?>
  </div>
</section>

<!-- Lightbox -->
<div id="lightbox" class="lightbox" style="display:none;">
  <span class="lightbox-close">&times;</span>
  <img class="lightbox-content" id="lightbox-img" alt="">
</div>

<footer class="text-center py-3 small text-muted"><?= htmlspecialchars($footer) ?></footer>

<script>
    document.addEventListener("DOMContentLoaded", function () {
      const lightbox = document.getElementById("lightbox");
      const lightboxImg = document.getElementById("lightbox-img");
      const closeBtn = document.querySelector(".lightbox-close");

      document.querySelectorAll(".masonry-img").forEach(img => {
          img.addEventListener("click", () => {
          lightboxImg.src = img.src;
          lightbox.style.display = "flex";
          });
      });

      document.querySelectorAll(".lightbox-trigger").forEach(img => {
        img.addEventListener("click", () => {
          lightboxImg.src = img.src;
          lightbox.style.display = "flex";
        });
      });

      closeBtn.addEventListener("click", () => {
          lightbox.style.display = "none";
      });

      lightbox.addEventListener("click", (e) => {
          if (e.target === lightbox) lightbox.style.display = "none";
      });
    });

</script>
<!-- ######################## Albums du parcours ######################## -->





