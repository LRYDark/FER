<?php
require '../config/config.php';
require_once '../config/tracker.php';
trackPageVisit();

// Charger les données de la navbar
require '../inc/navbar-data.php';

// Récupération du nombre d'inscrits
$stmtcount = $pdo->prepare('SELECT COUNT(*) AS total FROM registrations');
$stmtcount->execute();
$count = $stmtcount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Récupération des paramètres de la page parcours
$stmt = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
$stmt->execute(['id' => 1]);
$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$titleParcours  = $data['titleParcours'] ?? 'Parcours';
$parcoursDesc = $data['parcoursDesc'] ?? '';
$picture_parcours = $data['picture_parcours'] ?? '';
$picture_gradient = $data['picture_gradient'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Parcours</title>
  <link rel="stylesheet" href="../css/fer-modern.css">
  <style>
    /* Trait subtil sous la navbar */
    .floating-nav {
      border-bottom: 1px solid rgba(0,0,0,0.06);
    }

    /* Styles spécifiques à la page parcours */
    .parcours-hero {
      width: 100%;
      max-width: 85%;
      margin: 100px auto 60px;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 60px;
      align-items: center;
      padding-bottom: 40px;
    }

    .parcours-content h1 {
      margin: 0 0 24px;
      font-size: clamp(32px, 4vw, 48px);
      font-weight: 900;
      letter-spacing: -0.02em;
      line-height: 1.1;
    }

    .parcours-desc {
      font-size: 18px;
      line-height: 1.6;
      color: var(--page-muted);
      margin: 0;
    }

    .parcours-image img {
      width: 100%;
      height: auto;
      border-radius: 16px;
      box-shadow: 0 20px 60px rgba(2,6,23,.12);
      cursor: pointer;
      transition: transform .3s ease;
    }

    .parcours-image img:hover {
      transform: scale(1.02);
    }

    .section-divider {
      margin: 60px auto;
      text-align: center;
    }

    .section-divider img {
      width: 72%;
      max-width: 72%;
      height: auto;
      border-radius: 16px;
      cursor: pointer;
      transition: transform .3s ease;
    }

    .section-divider img:hover {
      transform: scale(1.01);
    }

    .album-section {
      margin: 80px auto 20px;
    }

    .album-title {
      text-align: center;
      margin: 0 0 48px;
      font-size: 36px;
      font-weight: 900;
      letter-spacing: -0.02em;
    }

    .masonry {
      columns: 3;
      column-gap: 8px;
      max-width: 75%;
      margin: 0 auto;
    }

    .masonry-img {
      width: 100%;
      height: auto;
      margin-bottom: 8px;
      break-inside: avoid;
      border-radius: 12px;
      cursor: pointer;
      transition: transform .2s ease, box-shadow .2s ease;
    }

    .masonry-img:hover {
      transform: translateY(-4px);
      box-shadow: 0 12px 32px rgba(2,6,23,.15);
    }

    /* Lightbox */
    .lightbox {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.9);
      z-index: 99999;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .lightbox-content {
      max-width: 90%;
      max-height: 90%;
      border-radius: 12px;
      box-shadow: 0 20px 60px rgba(0,0,0,.5);
    }

    .lightbox-close {
      position: absolute;
      top: 30px;
      right: 40px;
      font-size: 48px;
      color: #ffffff;
      cursor: pointer;
      user-select: none;
      transition: transform .2s ease;
    }

    .lightbox-close:hover {
      transform: scale(1.1);
    }

    @media (max-width: 980px) {
      .parcours-hero {
        grid-template-columns: 1fr;
        gap: 40px;
        margin-top: 16px;
        max-width: 94%;
      }

      .parcours-content h1 {
        text-align: center;
      }

      .parcours-desc {
        font-size: 17px;
        text-align: left;
      }

      .parcours-image {
        text-align: center;
      }

      .parcours-image img {
        width: 90%;
        margin: 0 auto;
      }

      .masonry {
        columns: 2;
        column-gap: 12px;
        max-width: 85%;
      }

      .masonry-img {
        margin-bottom: 12px;
      }

      .section-divider img {
        width: 100%;
        max-width: 100%;
      }
    }

    @media (max-width: 600px) {
      .masonry {
        columns: 1;
      }
    }
  </style>
</head>
<body>

  <?php include '../inc/navbar-modern.php'; ?>

  <main>
    <!-- Hero Section -->
    <section class="parcours-hero">
      <div class="parcours-content">
        <h1><?= htmlspecialchars($titleParcours) ?></h1>
        <p class="parcours-desc"><?= nl2br(htmlspecialchars($parcoursDesc)) ?></p>
      </div>
      <div class="parcours-image">
        <?php if (!empty($picture_parcours)): ?>
          <img src="../files/_pictures/<?= htmlspecialchars($picture_parcours) ?>"
               alt="<?= htmlspecialchars($titleParcours) ?>"
               class="lightbox-trigger"
               loading="lazy">
        <?php endif; ?>
      </div>
    </section>

    <!-- Divider Image -->
    <?php if (!empty($picture_gradient)): ?>
      <div class="section-divider">
        <img src="../files/_pictures/<?= htmlspecialchars($picture_gradient) ?>"
             alt="Illustration"
             class="lightbox-trigger"
             loading="lazy">
      </div>
    <?php endif; ?>

    <!-- Album Photos -->
    <section class="album-section">
      <h2 class="album-title">Galerie Photos</h2>
      <div class="masonry">
        <?php
          // Load ordered images from DB (fallback to filesystem if table doesn't exist)
          $orderedPhotos = [];
          try {
              $ordStmt = $pdo->query("SELECT filename FROM parcours_images ORDER BY sort_order ASC LIMIT 30");
              $orderedPhotos = $ordStmt->fetchAll(PDO::FETCH_COLUMN);
          } catch (PDOException $e) {}

          // Fallback: if DB is empty or table missing, scan directory
          if (empty($orderedPhotos)) {
              $exts = ['jpg','jpeg','png','webp','gif'];
              $photos = [];
              foreach ($exts as $ext) {
                  $photos = array_merge($photos, glob('../files/_parcours/*.' . $ext));
              }
              usort($photos, fn($a, $b) => filemtime($b) - filemtime($a));
              foreach (array_slice($photos, 0, 30) as $file) {
                  echo '<img src="' . htmlspecialchars($file) . '" alt="Photo parcours" class="masonry-img" loading="lazy">';
              }
          } else {
              foreach ($orderedPhotos as $filename) {
                  $filePath = '../files/_parcours/' . $filename;
                  if (file_exists($filePath)) {
                      echo '<img src="../files/_parcours/' . htmlspecialchars(rawurlencode($filename)) . '" alt="Photo parcours" class="masonry-img" loading="lazy">';
                  }
              }
          }
        ?>
      </div>
    </section>
  </main>

  <!-- Lightbox -->
  <div id="lightbox" class="lightbox">
    <span class="lightbox-close">&times;</span>
    <img class="lightbox-content" id="lightbox-img" alt="">
  </div>

  <?php include '../inc/footer-modern.php'; ?>

  <script src="../js/fer-modern.js"></script>
  <script>
    // Lightbox
    document.addEventListener("DOMContentLoaded", function () {
      const lightbox = document.getElementById("lightbox");
      const lightboxImg = document.getElementById("lightbox-img");
      const closeBtn = document.querySelector(".lightbox-close");

      document.querySelectorAll(".masonry-img, .lightbox-trigger").forEach(img => {
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

      document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") lightbox.style.display = "none";
      });
    });
  </script>
</body>
</html>
