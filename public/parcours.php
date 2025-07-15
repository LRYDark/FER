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
$link_instagram  = $data['link_instagram'] ?? null;
$link_facebook = $data['link_facebook'] ?? null; 
$link_cancer = $data['link_cancer'] ?? null;

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

  <?php include '../inc/nav.php'; ?> <!-- si nav séparée -->

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





