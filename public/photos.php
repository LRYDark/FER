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
$link_instagram  = $data['link_instagram'] ?? null;
$link_facebook = $data['link_facebook'] ?? null; 
$link_cancer = $data['link_cancer'] ?? null;

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

  <?php include '../inc/nav.php'; ?> <!-- si nav séparée -->

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

</body>
</html>


