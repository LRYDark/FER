<?php
require '../config/config.php';
require_once '../config/tracker.php';
trackPageVisit();

// Charger les données de la navbar
require '../inc/navbar-data.php';

// Récupération du nombre d'inscrits
try {
    $stmtcount = $pdo->prepare('SELECT COUNT(*) AS total FROM registrations');
    $stmtcount->execute();
    $count = $stmtcount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
} catch (PDOException $e) {
    $count = 0;
}

// Récupération des paramètres de la page parcours
try {
    $stmt = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => 1]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $data = [];
}

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
      top: 0; left: 0; right: 0; bottom: 0;
      z-index: 99999;
      background: rgba(0,0,0,0.95);
      align-items: center;
      justify-content: center;
      flex-direction: column;
    }
    .lightbox.active { display: flex; }

    .lightbox-close {
      position: absolute;
      top: 16px;
      right: 16px;
      width: 44px;
      height: 44px;
      border-radius: 50%;
      border: none;
      background: rgba(255,255,255,0.15);
      color: #fff;
      font-size: 24px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background .2s;
      z-index: 10;
    }
    .lightbox-close:hover { background: rgba(255,255,255,0.3); }

    .lightbox-counter {
      position: absolute;
      top: 20px;
      left: 50%;
      transform: translateX(-50%);
      color: rgba(255,255,255,0.7);
      font-size: 14px;
      font-weight: 600;
      z-index: 10;
    }

    .lightbox-img-wrap {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      padding: 60px 20px 20px;
      min-height: 0;
    }

    .lightbox-img-wrap img {
      max-width: 100%;
      max-height: 100%;
      object-fit: contain;
      border-radius: 4px;
      user-select: none;
      -webkit-user-drag: none;
    }

    .lightbox-nav {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      width: 48px;
      height: 48px;
      border-radius: 50%;
      border: none;
      background: rgba(255,255,255,0.15);
      color: #fff;
      font-size: 22px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background .2s;
      z-index: 10;
    }
    .lightbox-nav:hover { background: rgba(255,255,255,0.3); }
    .lightbox-prev { left: 16px; }
    .lightbox-next { right: 16px; }

    .lightbox-strip {
      display: flex;
      gap: 6px;
      padding: 12px 20px;
      overflow-x: auto;
      max-width: 100%;
      scrollbar-width: thin;
      scrollbar-color: rgba(255,255,255,0.3) transparent;
    }
    .lightbox-strip::-webkit-scrollbar { height: 4px; }
    .lightbox-strip::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.3); border-radius: 2px; }

    .lightbox-thumb {
      flex-shrink: 0;
      width: 60px;
      height: 60px;
      border-radius: 6px;
      overflow: hidden;
      cursor: pointer;
      opacity: 0.4;
      transition: opacity .2s, transform .2s;
      border: 2px solid transparent;
    }
    .lightbox-thumb.active {
      opacity: 1;
      border-color: #fff;
    }
    .lightbox-thumb:hover { opacity: 0.8; }
    .lightbox-thumb img {
      width: 100%;
      height: 100%;
      object-fit: cover;
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

      .lightbox-nav { width: 36px; height: 36px; font-size: 18px; }
      .lightbox-prev { left: 8px; }
      .lightbox-next { right: 8px; }
      .lightbox-thumb { width: 48px; height: 48px; }
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
    <?php $hasParcImg = !empty($picture_parcours) && is_file('../files/_pictures/' . $picture_parcours); ?>
    <section class="parcours-hero"<?php if (!$hasParcImg): ?> style="grid-template-columns:1fr;max-width:800px;text-align:center"<?php endif; ?>>
      <div class="parcours-content">
        <h1><?= htmlspecialchars($titleParcours) ?></h1>
        <?php if (!empty($parcoursDesc)): ?>
          <p class="parcours-desc"><?= nl2br(htmlspecialchars($parcoursDesc)) ?></p>
        <?php endif; ?>
      </div>
      <?php if ($hasParcImg): ?>
      <div class="parcours-image">
        <img src="../files/_pictures/<?= htmlspecialchars($picture_parcours) ?>"
             alt="<?= htmlspecialchars($titleParcours) ?>"
             class="lightbox-trigger"
             loading="lazy">
      </div>
      <?php endif; ?>
    </section>

    <!-- Divider Image -->
    <?php if (!empty($picture_gradient) && is_file('../files/_pictures/' . $picture_gradient)): ?>
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
  <div class="lightbox" id="lightbox">
    <button class="lightbox-close" id="lbClose" aria-label="Fermer">&times;</button>
    <span class="lightbox-counter" id="lbCounter"></span>
    <button class="lightbox-nav lightbox-prev" id="lbPrev" aria-label="Precedent">&#8249;</button>
    <button class="lightbox-nav lightbox-next" id="lbNext" aria-label="Suivant">&#8250;</button>
    <div class="lightbox-img-wrap">
      <img id="lbImage" src="" alt="">
    </div>
    <div class="lightbox-strip" id="lbStrip"></div>
  </div>

  <?php include '../inc/footer-modern.php'; ?>

  <script src="../js/fer-modern.js"></script>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
  (function() {
    var imgs = document.querySelectorAll('.masonry-img, .lightbox-trigger');
    if (!imgs.length) return;

    var photos = [];
    imgs.forEach(function(img) { photos.push(img.src); });

    var total = photos.length;
    var current = 0;
    var lightbox = document.getElementById('lightbox');
    var lbImage = document.getElementById('lbImage');
    var lbCounter = document.getElementById('lbCounter');
    var lbStrip = document.getElementById('lbStrip');
    var touchStartX = 0;

    // Build thumbnail strip
    photos.forEach(function(url, i) {
      var thumb = document.createElement('div');
      thumb.className = 'lightbox-thumb';
      thumb.innerHTML = '<img src="' + url + '" alt="" loading="lazy">';
      thumb.addEventListener('click', function() { showPhoto(i); });
      lbStrip.appendChild(thumb);
    });

    function showPhoto(index) {
      current = index;
      lbImage.src = photos[index];
      lbCounter.textContent = (index + 1) + ' / ' + total;
      lbStrip.querySelectorAll('.lightbox-thumb').forEach(function(t, i) {
        t.classList.toggle('active', i === index);
      });
      var activeThumb = lbStrip.children[index];
      if (activeThumb) {
        activeThumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
      }
    }

    function openLightbox(index) {
      showPhoto(index);
      lightbox.classList.add('active');
      document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
      lightbox.classList.remove('active');
      document.body.style.overflow = '';
    }

    function nextPhoto() { showPhoto((current + 1) % total); }
    function prevPhoto() { showPhoto((current - 1 + total) % total); }

    // Click on gallery images
    imgs.forEach(function(img, i) {
      img.addEventListener('click', function() { openLightbox(i); });
    });

    // Controls
    document.getElementById('lbClose').addEventListener('click', closeLightbox);
    document.getElementById('lbPrev').addEventListener('click', prevPhoto);
    document.getElementById('lbNext').addEventListener('click', nextPhoto);

    // Click outside image to close
    document.querySelector('.lightbox-img-wrap').addEventListener('click', function(e) {
      if (e.target === this) closeLightbox();
    });

    // Keyboard
    document.addEventListener('keydown', function(e) {
      if (!lightbox.classList.contains('active')) return;
      if (e.key === 'Escape') closeLightbox();
      if (e.key === 'ArrowRight') nextPhoto();
      if (e.key === 'ArrowLeft') prevPhoto();
    });

    // Touch swipe
    lightbox.addEventListener('touchstart', function(e) {
      touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });
    lightbox.addEventListener('touchend', function(e) {
      var dx = e.changedTouches[0].screenX - touchStartX;
      if (Math.abs(dx) > 50) { dx > 0 ? prevPhoto() : nextPhoto(); }
    }, { passive: true });

    // Preload adjacent
    var origShow = showPhoto;
    showPhoto = function(index) {
      origShow(index);
      if (index + 1 < total) { var img = new Image(); img.src = photos[index + 1]; }
      if (index - 1 >= 0) { var img2 = new Image(); img2.src = photos[index - 1]; }
    };
  })();
  </script>
</body>
</html>
