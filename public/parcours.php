<?php
require '../src/core/config.php';
checkMaintenance();
require_once '../src/content/tracker.php';
trackPageVisit();

// Charger les données de la navbar
require __DIR__ . '/../src/partials/navbar-data.php';

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
  <link rel="stylesheet" href="../css/parcours.css">
<?php include __DIR__ . '/../src/content/theme.php'; ?>
</head>
<body>

  <?php include __DIR__ . '/../src/partials/navbar-modern.php'; ?>

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

  <?php include __DIR__ . '/../src/partials/footer-modern.php'; ?>

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
