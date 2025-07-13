<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


require '../config/config.php';
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

// Récupération des années disponibles
$stmtYears = $pdo->prepare('SELECT * FROM photo_years ORDER BY year DESC');
$stmtYears->execute();
$years = $stmtYears->fetchAll(PDO::FETCH_ASSOC);

// Si une année est sélectionnée, récupérer les albums associés
$selectedYearId = isset($_GET['year_id']) ? (int)$_GET['year_id'] : null;
$albums = [];

if ($selectedYearId) {
    // Récupération des albums
    $stmtAlbums = $pdo->prepare('SELECT * FROM photo_albums WHERE year_id = :year_id');
    $stmtAlbums->execute(['year_id' => $selectedYearId]);
    $albums = $stmtAlbums->fetchAll(PDO::FETCH_ASSOC);

    // Récupération des infos de l'année sélectionnée
    $stmtYear = $pdo->prepare('SELECT * FROM photo_years WHERE id = :id LIMIT 1');
    $stmtYear->execute(['id' => $selectedYearId]);
    $selectedYear = $stmtYear->fetch(PDO::FETCH_ASSOC);
}
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
  <nav class="nav-flottante">
    <a href="accueil.php" class="nav-item accueil-style">Accueil</a>
    <a href="inscription.php" class="nav-item">Inscription</a>
    <a href="parcours.php" class="nav-item menu-cache">Parcours</a>
    <div class="nav-item dropdown menu-cache" style="position: relative;">
        <a href="#" class="nav-link partenaires-toggle" onclick="toggleDropdown(event)">
            Partenaires <svg class="chevron-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#e91e63" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M1.646 5.646a.5.5 0 0 1 .708 0L8 11.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/></svg>
        </a>
        <div class="dropdown-content-custom" id="dropdownPartenaires">
            <?php
            $stmtYears = $pdo->prepare('SELECT id, title FROM partners_years ORDER BY year DESC');
            $stmtYears->execute();
            $partners = $stmtYears->fetchAll(PDO::FETCH_ASSOC);
            if (empty($partners)) {
                echo '<span style="display:block; padding:0.5rem 1rem; color:#999;">Aucun partenaires disponible</span>';
            } else {
                foreach ($partners as $year) {
                    echo '<a href="partenaires.php?year_id=' . htmlspecialchars($year['id']) . '">' . htmlspecialchars($year['title']) . '</a>';
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
            $stmtPhotos = $pdo->prepare('SELECT id, title FROM photo_years ORDER BY year DESC');
            $stmtPhotos->execute();
            $albumsNav = $stmtPhotos->fetchAll(PDO::FETCH_ASSOC);
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
            $stmtYears = $pdo->prepare('SELECT id, title FROM partners_years ORDER BY year DESC');
            $stmtYears->execute();
            $partners = $stmtPhotos->fetchAll(PDO::FETCH_ASSOC);
            if (empty($partners)) {
                echo '<span style="display:block; padding:0.5rem 1rem; color:#999;">Aucun partenaires disponible</span>';
            } else {
                foreach ($partners as $year) {
                    echo '<a href="partenaires.php?year_id=' . htmlspecialchars($year['id']) . '">' . htmlspecialchars($year['title']) . '</a>';
                }
            }
        ?>
        </div>
        </div>
        <a href="#" class="partenaires-toggle-mobile" onclick="toggleMobilePhotosDropdown(event)">
            Photos
            <svg class="chevron-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#e91e63" viewBox="0 0 16 16">
            <path fill-rule="evenodd" d="M1.646 5.646a.5.5 0 0 1 .708 0L8 11.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
            </svg>
        </a>
        <div class="dropdown-content-mobile" id="dropdownMobilePhotos">
        <?php
            $stmtPhotos = $pdo->prepare('SELECT id, title FROM photo_years ORDER BY year DESC');
            $stmtPhotos->execute();
            $albumsNav = $stmtYears->fetchAll(PDO::FETCH_ASSOC);
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

  <main style="flex:1;">
    <style>
        .card:hover {
            transform: translateY(-5px);
        }
    </style>

        <div class="container my-5">
        <?php if ($selectedYearId): ?>
            <h1 class="mb-4"><?= htmlspecialchars($selectedYear['title'] ?? '') ?></h1>
            <div class="row">
                <?php foreach ($albums as $album): ?>
                <div class="col-md-4 mb-4">
                    <a href="<?= htmlspecialchars($album['album_link']) ?>" target="_blank" class="text-decoration-none">
                    <div class="card shadow text-center d-flex flex-column"
                        style="border-radius: 2rem; overflow: hidden; transition: transform 0.3s ease; border: none; background: #fff;">

                        <div class="card-body p-3">
                        <h5 class="card-title mb-3" style="color: #111; font-weight: bold;">
                            <?= htmlspecialchars($album['album_title']) ?>
                        </h5>
                        </div>

                        <?php if (!empty($album['album_img'])): ?>
                        <img src="../files/_albums/<?= htmlspecialchars($album['album_img']) ?>"
                            class="img-fluid"
                            alt="Image album <?= htmlspecialchars($album['album_title']) ?>"
                            loading="lazy"
                            style="width: 100%; height: 220px; object-fit: cover;">
                        <?php endif; ?>

                        <?php if (!empty($album['album_desc'])): ?>
                        <div class="card-body px-3 pb-3 pt-2">
                            <p class="text-muted small mb-0"><?= nl2br(htmlspecialchars($album['album_desc'])) ?></p>
                        </div>
                        <?php endif; ?>

                    </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="text-center py-3 small text-muted"><?= htmlspecialchars($footer) ?></footer>

</body>
</html>


