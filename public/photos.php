<?php
require '../config/config.php';
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
    <a href="accueil.php" class="nav-item">Accueil</a>
    <a href="inscription.php" class="nav-item">Inscription</a>
    <a href="parcours.php" class="nav-item menu-cache">Parcours</a>
    <a href="partenaire.php" class="nav-item menu-cache">Partenaires</a>
    <a href="photos.php" class="nav-item menu-cache">Photos</a>
    <a href="news.php" class="nav-item menu-cache">Actualités</a>

    <!-- Bouton burger -->
    <button class="burger-toggle d-md-none" aria-label="Menu"></button>

    <!-- Menu déroulant mobile -->
    <div class="menu-deroulant d-none">
      <a href="parcours.php">Parcours</a>
      <a href="partenaire.php">Partenaires</a>
      <a href="photos.php">Photos</a>
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
        <?php if (!$selectedYearId): ?>
            <h1 class="mb-4">Albums Photos</h1>
            <div class="row">
            <?php foreach ($years as $year): ?>
            <div class="col-md-4 mb-4">
                <a href="photos.php?year_id=<?= $year['id'] ?>" class="text-decoration-none">
                <div class="card h-100 shadow text-center d-flex flex-column justify-content-between"
                    style="border-radius: 2rem; overflow: hidden; transition: transform 0.3s ease; border: none; background: #fff;">
                    
                    <div class="card-body p-3">
                    <h5 class="card-title mb-3" style="color: #111; font-weight: bold;">
                        <?= htmlspecialchars($year['title']) ?>
                    </h5>
                    </div>

                    <?php if (!empty($year['img'])): ?>
                    <img src="../files/_pictures/<?= htmlspecialchars($year['img']) ?>"
                        class="img-fluid"
                        alt="Image année <?= htmlspecialchars($year['title']) ?>"
                        loading="lazy"
                        style="width: 100%; height: 220px; object-fit: cover;">
                    <?php endif; ?>

                </div>
                </a>
            </div>
            <?php endforeach; ?>
            </div>
            <?php else: ?>
            <h1 class="mb-4">Albums : <?= htmlspecialchars($selectedYear['title'] ?? '') ?></h1>
            <a href="photos.php" class="btn btn-secondary mb-4">← Retour</a>
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
                        <img src="../files/_pictures/<?= htmlspecialchars($album['album_img']) ?>"
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


