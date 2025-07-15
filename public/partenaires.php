<?php
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

// Récupération des années disponibles pour les partenaires
$stmtYears = $pdo->prepare('SELECT * FROM partners_years ORDER BY year DESC');
$stmtYears->execute();
$years = $stmtYears->fetchAll(PDO::FETCH_ASSOC);

// Si une année est sélectionnée, récupérer les albums associés
$selectedYearId = isset($_GET['year_id']) ? (int)$_GET['year_id'] : null;
$partners = [];

if ($selectedYearId) {
    $stmtAlbums = $pdo->prepare('SELECT * FROM partners_albums WHERE year_id = :year_id');
    $stmtAlbums->execute(['year_id' => $selectedYearId]);
    $partners = $stmtAlbums->fetchAll(PDO::FETCH_ASSOC);

    $stmtYear = $pdo->prepare('SELECT * FROM partners_years WHERE id = :id LIMIT 1');
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

<?php if ($selectedYearId): ?>
    <section class="main-illustration boxsize my-5">
        <div class="row align-items-center">
        <div class="col-md-6">
            <img src="../files/_partners/<?= htmlspecialchars($selectedYear['img'] ?? '') ?>" alt="Image principale" class="img-fluid main-img lightbox-trigger" loading="lazy">
        </div>
        <div class="col-md-6">
            <p class="lead"><?= $selectedYear['desc'] ?></p>
        </div>
        </div>
    </section>
<?php endif; ?>

<main style="flex:1;">
  <div class="container my-5">
    <?php if ($selectedYearId): ?>
    <h1 class="mb-4"><?= htmlspecialchars($selectedYear['title'] ?? '') ?></h1>
      <div class="row">
        <?php foreach ($partners as $partner): ?>
          <div class="col-md-3 mb-3">
            <div class="card shadow text-center d-flex flex-column"
                style="border-radius: 2rem; overflow: hidden; transition: transform 0.3s ease; border: none; background: #fff; cursor: pointer;"
                onclick="showImageModal('../files/_partners/<?= htmlspecialchars($partner['album_img']) ?>')">
                
                <div class="card-body p-3">
                <h5 class="card-title mb-3" style="color: #111; font-weight: bold;">
                    <?= htmlspecialchars($partner['album_title']) ?>
                </h5>
                </div>

                <?php if (!empty($partner['album_img'])): ?>
                <img src="../files/_partners/<?= htmlspecialchars($partner['album_img']) ?>"
                    class="img-fluid w-100"
                    alt="Image partenaire <?= htmlspecialchars($partner['album_title']) ?>"
                    loading="lazy"
                    style="object-fit: contain;">
                <?php endif; ?>

                <?php if (!empty($partner['album_desc'])): ?>
                  <div class="card-body px-3 pb-3 pt-2">
                      <p class="text-muted small mb-0"><?= nl2br(htmlspecialchars($partner['album_desc'])) ?></p>
                  </div>
                <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

    <!-- Modal pour afficher l'image en grand -->
  <div id="imageModal" class="modal" tabindex="-1" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(0,0,0,0.8); justify-content:center; align-items:center;">
    <span onclick="document.getElementById('imageModal').style.display='none'" style="position:absolute; top:20px; right:30px; font-size:30px; color:white; cursor:pointer;">&times;</span>
    <img id="modalImage" src="" style="max-width:90%; max-height:90%;">
  </div>

  <script>
    function showImageModal(src) {
      document.getElementById('modalImage').src = src;
      document.getElementById('imageModal').style.display = 'flex';
    }
  </script>

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


