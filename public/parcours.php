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





