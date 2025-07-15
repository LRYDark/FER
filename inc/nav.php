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