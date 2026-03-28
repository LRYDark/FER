<?php
require '../config/config.php';
require '../inc/navbar-data.php';

$albumId = isset($_GET['album_id']) ? (int)$_GET['album_id'] : 0;
if ($albumId <= 0) {
    header('Location: photos');
    exit;
}

// Fetch the album
$stmt = $pdo->prepare("SELECT pa.*, py.year, py.title as year_title, py.id as year_id
                        FROM photo_albums pa
                        JOIN photo_years py ON pa.year_id = py.id
                        WHERE pa.id = :id AND pa.deleted_at IS NULL AND pa.album_type = 'local'");
$stmt->execute(['id' => $albumId]);
$album = $stmt->fetch(PDO::FETCH_ASSOC);

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
  <style>
    .floating-nav { border-bottom: 1px solid rgba(0,0,0,0.06); }

    .gallery-hero {
      width: 100%;
      max-width: 1400px;
      margin: 174px auto 0;
      padding: 0 24px;
      display: flex;
      align-items: center;
      gap: 16px;
      justify-content: space-between;
    }

    .gallery-hero-left {
      display: flex;
      align-items: center;
      gap: 16px;
      min-width: 0;
    }

    .view-toggle {
      display: flex;
      gap: 0;
      border-radius: 10px;
      overflow: hidden;
      border: 1px solid #e2e8f0;
      flex-shrink: 0;
    }

    .view-toggle-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 38px;
      height: 38px;
      border: none;
      background: #fff;
      color: #94a3b8;
      cursor: pointer;
      transition: all .2s;
    }

    .view-toggle-btn:hover { color: #0f172a; background: #f8fafc; }
    .view-toggle-btn.active { background: #0f172a; color: #fff; }
    .view-toggle-btn svg { width: 18px; height: 18px; }

    .gallery-hero .back-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 40px;
      height: 40px;
      border-radius: 10px;
      background: #0f172a;
      color: #fff;
      text-decoration: none;
      transition: all .25s ease;
      flex-shrink: 0;
    }
    .gallery-hero .back-btn:hover { background: var(--pink); }

    .gallery-hero-info {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    .gallery-hero-title {
      margin: 0;
      color: var(--page-text);
      font-size: clamp(22px, 3vw, 30px);
      font-weight: 800;
      letter-spacing: -0.03em;
      line-height: 1.2;
    }
    .gallery-hero-meta {
      font-size: 14px;
      color: #7c8ca3;
      font-weight: 500;
    }

    /* Photo grid */
    .gallery-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap: 6px;
      max-width: 1400px;
      margin: 24px auto;
      padding: 0 24px;
    }

    .gallery-item {
      position: relative;
      overflow: hidden;
      border-radius: 8px;
      cursor: pointer;
      aspect-ratio: 1;
      background: #f1f5f9;
    }

    .gallery-item img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      transition: transform .3s ease;
    }

    .gallery-item:hover img {
      transform: scale(1.05);
    }

    /* Mosaic mode */
    .mosaic-container {
      display: none;
      max-width: 1400px;
      margin: 24px auto;
      padding: 0 24px;
    }

    .mosaic-container.active {
      display: block;
    }

    .mosaic-row {
      display: flex;
      gap: 6px;
      margin-bottom: 6px;
    }

    .mosaic-item {
      position: relative;
      overflow: hidden;
      border-radius: 8px;
      cursor: pointer;
      flex-shrink: 0;
      background: #f1f5f9;
    }

    .mosaic-item img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      transition: transform .3s ease;
    }

    .mosaic-item:hover img {
      transform: scale(1.05);
    }

    /* Lightbox */
    .lightbox {
      display: none;
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      z-index: 9999;
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

    /* Thumbnail strip */
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

    .gallery-empty {
      text-align: center;
      padding: 80px 20px;
      color: #94a3b8;
    }
    .gallery-empty svg {
      width: 64px;
      height: 64px;
      margin-bottom: 16px;
      opacity: 0.5;
    }

    @media (max-width: 980px) {
      .gallery-hero { margin-top: 16px; }
      .gallery-grid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 4px;
        padding: 0 8px;
      }
      .gallery-item { border-radius: 4px; }
      .mosaic-row { gap: 4px; margin-bottom: 4px; }
      .mosaic-item { border-radius: 4px; }
      .lightbox-nav { width: 36px; height: 36px; font-size: 18px; }
      .lightbox-prev { left: 8px; }
      .lightbox-next { right: 8px; }
      .lightbox-thumb { width: 48px; height: 48px; }
    }
  </style>
</head>
<body>
  <?php include '../inc/navbar-modern.php'; ?>

  <main>
    <section class="gallery-hero">
      <div class="gallery-hero-left">
        <a href="photos?year_id=<?= $yearId ?>" title="Retour" class="back-btn">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="#ffffff"><path d="M3.3 11.3l6.8-6.8c.4-.4.4-1 0-1.4s-1-.4-1.4 0l-7.8 7.8c-.4.4-.4 1 0 1.4l7.8 7.8c.2.2.5.3.7.3s.5-.1.7-.3c.4-.4.4-1 0-1.4L3.3 12.7H22c.6 0 1-.4 1-1s-.4-1-1-1H3.3z"/></svg>
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

  <?php include '../inc/footer-modern.php'; ?>

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
