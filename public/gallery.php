<?php
require '../src/core/config.php';
require_once '../src/content/tracker.php';
trackPageVisit();
checkMaintenance();
require __DIR__ . '/../src/partials/navbar-data.php';

$albumId = (int)($_GET['album_id'] ?? 0);
if ($albumId <= 0) {
    header('Location: photos');
    exit;
}

// Fetch the album
try {
    $stmt = $pdo->prepare("SELECT pa.*, py.year, py.title as year_title, py.id as year_id
                            FROM photo_albums pa
                            JOIN photo_years py ON pa.year_id = py.id
                            WHERE pa.id = :id AND pa.deleted_at IS NULL AND pa.album_type = 'local'
                              AND py.status = 'published'");
    $stmt->execute(['id' => $albumId]);
    $album = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[GALLERY] ' . $e->getMessage());
    $album = null;
}

if (!$album) {
    header('Location: photos');
    exit;
}

// Scan photos from the folder
$folderName = basename($album['album_link']);
$folderPath = __DIR__ . '/../files/_albums/' . $folderName;
$photos = [];
$allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

if (is_dir($folderPath)) {
    $files = scandir($folderPath);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) continue;
        $fullPath = $folderPath . DIRECTORY_SEPARATOR . $f;
        $imgSize = @getimagesize($fullPath);
        $w = $imgSize ? $imgSize[0] : 400;
        $h = $imgSize ? $imgSize[1] : 300;
        $photos[] = [
            'filename' => $f,
            'url' => '../files/_albums/' . $folderName . '/' . rawurlencode($f),
            'size' => filesize($fullPath),
            'modified' => filemtime($fullPath),
            'w' => $w,
            'h' => $h
        ];
    }
    usort($photos, function ($a, $b) {
        return $b['modified'] - $a['modified'];
    });

    // Pre-calculate justified rows for mosaic mode
    // Each row fills the container width with varied image heights
    $mosaicRows = [];
    $targetHeight = 280;
    $containerWidth = 1352; // 1400 - 48px padding
    $gap = 6;
    $row = [];
    $rowRatioSum = 0;

    foreach ($photos as $p) {
        $ratio = $p['w'] / max($p['h'], 1);
        $row[] = $p;
        $rowRatioSum += $ratio;
        $rowWidth = $rowRatioSum * $targetHeight + ($gap * (count($row) - 1));

        if ($rowWidth >= $containerWidth && count($row) >= 2) {
            $rowHeight = ($containerWidth - $gap * (count($row) - 1)) / $rowRatioSum;
            $mosaicRows[] = ['photos' => $row, 'height' => round($rowHeight)];
            $row = [];
            $rowRatioSum = 0;
        }
    }
    // Last incomplete row
    if (!empty($row)) {
        $rowHeight = min($targetHeight, ($containerWidth - $gap * (count($row) - 1)) / max($rowRatioSum, 0.1));
        $mosaicRows[] = ['photos' => $row, 'height' => round($rowHeight)];
    }
}

$albumTitle = $album['album_title'];
$yearTitle = $album['year_title'];
$yearId = $album['year_id'];
$photoCount = count($photos);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($albumTitle) ?> - Photos</title>
  <link rel="stylesheet" href="../css/fer-modern.css">
  <link rel="stylesheet" href="../css/gallery.css">
<?php include __DIR__ . '/../src/content/theme.php'; ?>
</head>
<body>
  <?php include __DIR__ . '/../src/partials/preloader.php'; ?>
  <?php include __DIR__ . '/../src/partials/navbar-modern.php'; ?>

  <main>
    <section class="gallery-hero">
      <div class="gallery-hero-left">
        <a href="photos?year_id=<?= $yearId ?>" title="Retour" class="back-btn">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3.3 11.3l6.8-6.8c.4-.4.4-1 0-1.4s-1-.4-1.4 0l-7.8 7.8c-.4.4-.4 1 0 1.4l7.8 7.8c.2.2.5.3.7.3s.5-.1.7-.3c.4-.4.4-1 0-1.4L3.3 12.7H22c.6 0 1-.4 1-1s-.4-1-1-1H3.3z"/></svg>
        </a>
        <div class="gallery-hero-info">
          <h1 class="gallery-hero-title"><?= htmlspecialchars(!empty($album['album_desc']) ? $album['album_desc'] : $albumTitle) ?></h1>
          <span class="gallery-hero-meta"><?= htmlspecialchars($yearTitle) ?> &middot; <?= $photoCount ?> photo<?= $photoCount > 1 ? 's' : '' ?></span>
        </div>
      </div>
      <?php if ($photoCount > 0): ?>
      <div class="view-toggle">
        <button class="view-toggle-btn" id="viewGrid" title="Grille">
          <svg viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        </button>
        <button class="view-toggle-btn active" id="viewMosaic" title="Mosaique">
          <svg viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="3" width="10" height="8" rx="1.2"/><rect x="15" y="3" width="6" height="5" rx="1.2"/><rect x="15" y="10" width="6" height="11" rx="1.2"/><rect x="3" y="13" width="5" height="8" rx="1.2"/><rect x="10" y="13" width="3" height="8" rx="1.2"/></svg>
        </button>
      </div>
      <?php endif; ?>
    </section>

    <?php if ($photoCount > 0): ?>
      <div class="gallery-grid" style="display:none">
        <?php foreach ($photos as $index => $photo): ?>
          <div class="gallery-item" data-index="<?= $index ?>" data-w="<?= $photo['w'] ?>" data-h="<?= $photo['h'] ?>">
            <img src="<?= htmlspecialchars($photo['url']) ?>"
                 alt="Photo <?= $index + 1 ?>"
                 loading="lazy">
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Mosaic view (justified rows, pre-calculated server-side) -->
      <?php $mosaicIdx = 0; ?>
      <div class="mosaic-container active" id="mosaicContainer">
        <?php foreach ($mosaicRows as $row): ?>
        <div class="mosaic-row">
          <?php foreach ($row['photos'] as $p):
            $ratio = $p['w'] / max($p['h'], 1);
            $itemWidth = round($row['height'] * $ratio);
          ?>
          <div class="mosaic-item" data-index="<?= $mosaicIdx ?>" style="width:<?= $itemWidth ?>px;height:<?= $row['height'] ?>px;flex:<?= round($ratio, 4) ?> 0 0">
            <img src="<?= htmlspecialchars($p['url']) ?>" alt="Photo <?= $mosaicIdx + 1 ?>" loading="lazy">
          </div>
          <?php $mosaicIdx++; endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="gallery-empty">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
        <p>Aucune photo dans cet album.</p>
      </div>
    <?php endif; ?>
  </main>

  <?php if ($photoCount > 0): ?>
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
  <?php endif; ?>

  <?php include __DIR__ . '/../src/partials/footer-modern.php'; ?>

  <script src="../js/fer-modern.js"></script>
  <?php if ($photoCount > 0): ?>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
  (function() {
    var photos = <?= json_encode(array_map(function($p) { return $p['url']; }, $photos), JSON_UNESCAPED_SLASHES) ?>;
    var total = photos.length;
    var current = 0;
    var lightbox = document.getElementById('lightbox');
    var lbImage = document.getElementById('lbImage');
    var lbCounter = document.getElementById('lbCounter');
    var lbStrip = document.getElementById('lbStrip');
    var touchStartX = 0;
    var touchStartY = 0;

    // Build thumbnail strip
    photos.forEach(function(url, i) {
      var thumb = document.createElement('div');
      thumb.className = 'lightbox-thumb';
      thumb.dataset.index = i;
      thumb.innerHTML = '<img src="' + url + '" alt="" loading="lazy">';
      thumb.addEventListener('click', function() { showPhoto(i); });
      lbStrip.appendChild(thumb);
    });

    function showPhoto(index) {
      current = index;
      lbImage.src = photos[index];
      lbCounter.textContent = (index + 1) + ' / ' + total;
      // Update active thumb
      lbStrip.querySelectorAll('.lightbox-thumb').forEach(function(t, i) {
        t.classList.toggle('active', i === index);
      });
      // Scroll thumb into view
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

    function nextPhoto() {
      showPhoto((current + 1) % total);
    }

    function prevPhoto() {
      showPhoto((current - 1 + total) % total);
    }

    // Click on grid items
    document.querySelectorAll('.gallery-item').forEach(function(item) {
      item.addEventListener('click', function() {
        openLightbox(parseInt(this.dataset.index));
      });
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
      touchStartY = e.changedTouches[0].screenY;
    }, { passive: true });

    lightbox.addEventListener('touchend', function(e) {
      var dx = e.changedTouches[0].screenX - touchStartX;
      var dy = e.changedTouches[0].screenY - touchStartY;
      if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 50) {
        if (dx > 0) prevPhoto();
        else nextPhoto();
      }
    }, { passive: true });

    // Preload adjacent images
    function preload(index) {
      if (index >= 0 && index < total) {
        var img = new Image();
        img.src = photos[index];
      }
    }

    var origShowPhoto = showPhoto;
    showPhoto = function(index) {
      origShowPhoto(index);
      preload(index + 1);
      preload(index - 1);
    };
  // ─── View toggle ───
  var grid = document.querySelector('.gallery-grid');
  var btnGrid = document.getElementById('viewGrid');
  var btnMosaic = document.getElementById('viewMosaic');

  // ─── View toggle ───
  var btnGrid = document.getElementById('viewGrid');
  var btnMosaic = document.getElementById('viewMosaic');
  var gridEl = document.querySelector('.gallery-grid');
  var mosaicEl = document.getElementById('mosaicContainer');

  if (btnGrid && btnMosaic && gridEl && mosaicEl) {
    // Mosaic items also open lightbox
    mosaicEl.querySelectorAll('.mosaic-item').forEach(function(item) {
      item.addEventListener('click', function() {
        openLightbox(parseInt(this.dataset.index));
      });
    });

    btnGrid.addEventListener('click', function() {
      gridEl.style.display = '';
      mosaicEl.classList.remove('active');
      btnGrid.classList.add('active');
      btnMosaic.classList.remove('active');
    });

    btnMosaic.addEventListener('click', function() {
      gridEl.style.display = 'none';
      mosaicEl.classList.add('active');
      btnMosaic.classList.add('active');
      btnGrid.classList.remove('active');
    });
  }

  })();
  </script>
  <?php endif; ?>
</body>
</html>
