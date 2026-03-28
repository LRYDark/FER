<?php
require '../config/config.php';
require '../inc/navbar-data.php';

// Check if status column exists
$hasStatusCol = false;
try { $pdo->query("SELECT status FROM photo_years LIMIT 0"); $hasStatusCol = true; } catch (PDOException $e) {}

// Check preview mode
$isPreview = false;
$previewYearId = isset($_GET['preview_year']) ? (int)$_GET['preview_year'] : 0;
if ($previewYearId > 0) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        header('HTTP/1.0 403 Forbidden'); echo 'Accès refusé'; exit;
    }
    $isPreview = true;
}

// Recuperation des annees disponibles
try {
    if ($isPreview) {
        // Preview: show published + draft, but NOT trashed
        $stmtYears = $pdo->prepare('SELECT * FROM photo_years WHERE deleted_at IS NULL ORDER BY year DESC');
        $stmtYears->execute();
    } else {
        if ($hasStatusCol) {
            $stmtYears = $pdo->prepare("SELECT * FROM photo_years WHERE deleted_at IS NULL AND status = 'published' ORDER BY year DESC");
        } else {
            $stmtYears = $pdo->prepare('SELECT * FROM photo_years ORDER BY year DESC');
        }
        $stmtYears->execute();
    }
    $years = $stmtYears->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $years = [];
}

// Si une annee est selectionnee, recuperer les albums associes
$selectedYearId = $previewYearId ?: (isset($_GET['year_id']) ? (int)$_GET['year_id'] : null);
$albums = [];
$selectedYear = null;

if ($selectedYearId) {
    try {
        if ($isPreview) {
            $stmtYear = $pdo->prepare('SELECT * FROM photo_years WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        } else {
            $stmtYear = $pdo->prepare("SELECT * FROM photo_years WHERE id = :id AND deleted_at IS NULL AND status = 'published' LIMIT 1");
        }
        $stmtYear->execute(['id' => $selectedYearId]);
        $selectedYear = $stmtYear->fetch(PDO::FETCH_ASSOC);

        if (!$selectedYear && !$isPreview) {
            header('Location: photos');
            exit;
        }

        $stmtAlbums = $pdo->prepare('SELECT * FROM photo_albums WHERE year_id = :year_id AND deleted_at IS NULL ORDER BY sort_order');
        $stmtAlbums->execute(['year_id' => $selectedYearId]);
        $albums = $stmtAlbums->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $selectedYear = null;
        $albums = [];
    }
}

function formatAlbumDateLabel(int $timestamp): string
{
    if (class_exists('IntlDateFormatter')) {
        static $formatter = null;
        if ($formatter === null) {
            $formatter = new IntlDateFormatter(
                'fr_FR',
                IntlDateFormatter::LONG,
                IntlDateFormatter::NONE,
                date_default_timezone_get(),
                IntlDateFormatter::GREGORIAN,
                'd MMMM yyyy'
            );
        }
        $formatted = $formatter->format($timestamp);
        if (is_string($formatted) && $formatted !== '') {
            return $formatted;
        }
    }

    $months = [
        1 => 'janvier', 2 => 'fevrier', 3 => 'mars', 4 => 'avril',
        5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'aout',
        9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'decembre',
    ];
    $day = (int)date('j', $timestamp);
    $month = $months[(int)date('n', $timestamp)] ?? date('m', $timestamp);
    $year = date('Y', $timestamp);

    return $day . ' ' . $month . ' ' . $year;
}

function resolveAlbumCreator(array $album): string
{
    $creatorKeys = [
        'creator_name',
        'album_creator',
        'created_by',
        'added_by',
        'author',
        'album_author',
        'owner_name',
        'user_name',
    ];

    foreach ($creatorKeys as $key) {
        $value = trim((string)($album[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    $descFallback = trim((string)($album['album_desc'] ?? ''));
    if ($descFallback !== '') {
        return $descFallback;
    }

    return 'Auteur inconnu';
}

function resolveAlbumDateLabel(array $album): string
{
    $dateKeys = [
        'created_at',
        'date_added',
        'added_at',
        'uploaded_at',
        'created_on',
        'created_date',
        'date_creation',
        'inserted_at',
        'updated_at',
    ];

    foreach ($dateKeys as $key) {
        $raw = trim((string)($album[$key] ?? ''));
        if ($raw === '') {
            continue;
        }
        $timestamp = strtotime($raw);
        if ($timestamp !== false) {
            return formatAlbumDateLabel($timestamp);
        }
    }

    $imgName = trim((string)($album['album_img'] ?? ''));
    if ($imgName !== '') {
        $imgPath = __DIR__ . '/../files/_albums/' . basename($imgName);
        if (is_file($imgPath)) {
            $timestamp = @filemtime($imgPath);
            if ($timestamp !== false) {
                return formatAlbumDateLabel($timestamp);
            }
        }
    }

    return 'Date inconnue';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Photos</title>
  <link rel="stylesheet" href="../css/fer-modern.css">
  <style>
    /* Trait subtil sous la navbar */
    .floating-nav {
      border-bottom: 1px solid rgba(0,0,0,0.06);
    }

    .photos-hero {
      width: 100%;
      max-width: 1200px;
      margin: 174px auto 0;
      padding: 0 24px;
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .photos-hero-title {
      margin: 0;
      color: var(--page-text);
      font-size: clamp(24px, 3.5vw, 32px);
      font-weight: 800;
      letter-spacing: -0.03em;
      line-height: 1.2;
    }


    .photos-hero .back-btn {
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

    .photos-hero .back-btn:hover {
      background: var(--pink);
    }

    .albums-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      gap: 18px;
      max-width: 1200px;
      margin: 30px auto;
      padding: 0 24px;
    }

    .album-card {
      background: transparent;
      border-radius: 12px;
      transition: transform .25s ease;
      text-decoration: none;
      color: var(--page-text);
      display: flex;
      flex-direction: column;
      padding: 0;
      min-height: 100%;
      overflow: visible;
    }

    .album-card:hover {
      transform: translateY(-3px);
    }

    .album-card-media {
      position: relative;
      overflow: visible;
      border-radius: 16px;
      background: transparent;
      height: 200px;
      margin: 0;
    }

    .album-card-media-inner {
      width: 100%;
      height: 100%;
      overflow: hidden;
      border-radius: 16px;
    }

    .album-card-image {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .album-card-creator {
      position: absolute;
      bottom: 0;
      left: 16px;
      transform: translateY(50%);
      z-index: 3;
      display: inline-flex;
      align-items: center;
      padding: 5px 12px;
      border-radius: 100px;
      background: #fce7f3;
      color: var(--pink);
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
      border: 5px solid #fff;
      box-shadow: 0 0 0 1px #fff;
      max-width: none;
      overflow: visible;
      text-overflow: unset;
      line-height: 1.2;
    }

    .album-card-content {
      display: flex;
      flex-direction: column;
      flex: 1;
      padding: 18px 8px 12px;
    }

    .album-card-title {
      margin: 0;
      font-size: clamp(17px, 1.35vw, 22px);
      font-weight: 500;
      letter-spacing: -0.01em;
      line-height: 1.25;
      color: #0f172a;
      margin-bottom: 8px;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
      text-wrap: balance;
    }

    .album-card-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-top: auto;
    }

    .album-card-date {
      font-size: 14px;
      font-weight: 500;
      color: #7c8ca3;
      margin: 0;
    }


    @media (max-width: 980px) {
      .photos-hero {
        margin-top: 16px;
      }

      .albums-grid {
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 16px;
      }

      .album-card {
        border-radius: 12px;
      }

      .album-card-media {
        border-radius: 16px;
      }

      .album-card-media-inner {
        border-radius: 16px;
      }

      .album-card-title {
        font-size: clamp(17px, 4.7vw, 21px);
      }

      .album-card-content {
        padding: 20px 12px 16px;
      }

      .album-card-footer {
        gap: 8px;
      }

    }

    /* Year cards */
    .years-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 20px;
      max-width: 1200px;
      margin: 30px auto 0;
      padding: 0 24px;
    }

    .year-card {
      position: relative;
      display: flex;
      flex-direction: column;
      justify-content: flex-end;
      padding: 28px 24px;
      min-height: 160px;
      background: #0f172a;
      border: none;
      border-radius: 16px;
      color: #fff;
      font-size: 18px;
      font-weight: 600;
      text-decoration: none;
      transition: all .35s cubic-bezier(.4,0,.2,1);
      overflow: hidden;
      box-shadow: 0 4px 16px rgba(15,23,42,0.12);
    }

    .year-card::before {
      content: attr(data-year);
      position: absolute;
      top: -10px;
      right: -8px;
      font-size: 120px;
      font-weight: 900;
      color: rgba(255,255,255,0.12);
      line-height: 1;
      pointer-events: none;
      letter-spacing: -6px;
      z-index: 2;
    }

    .year-card::after {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
      opacity: 0;
      transition: opacity .35s ease;
      border-radius: 16px;
    }

    .year-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 12px 32px rgba(15,23,42,0.2);
    }

    .year-card:hover::after {
      opacity: 1;
    }

    .year-card-year {
      position: relative;
      z-index: 1;
      font-size: 13px;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 2px;
      color: rgba(255,255,255,0.5);
      margin-bottom: 6px;
    }

    .year-card-title {
      position: relative;
      z-index: 1;
      font-size: 20px;
      font-weight: 700;
      color: #fff;
      margin: 0;
      line-height: 1.3;
    }

    .year-card-arrow {
      position: absolute;
      bottom: 20px;
      right: 20px;
      z-index: 1;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: rgba(255,255,255,0.1);
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .35s ease;
    }

    .year-card:hover .year-card-arrow {
      background: rgba(255,255,255,0.25);
      transform: translateX(3px);
    }

    .year-card-arrow svg {
      width: 16px;
      height: 16px;
      fill: #fff;
    }

    @media (max-width: 980px) {
      .years-grid {
        grid-template-columns: 1fr;
        gap: 14px;
      }
      .year-card {
        min-height: 130px;
        padding: 22px 20px;
      }
      .year-card::before {
        font-size: 90px;
        top: -6px;
        right: -4px;
      }
    }
  </style>
</head>
<body>
  <?php include '../inc/navbar-modern.php'; ?>

  <main>
    <section class="photos-hero" aria-label="Titre de la page">
      <?php if ($selectedYear): ?>
        <a href="photos" title="Retour" class="back-btn">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="#ffffff"><path d="M3.3 11.3l6.8-6.8c.4-.4.4-1 0-1.4s-1-.4-1.4 0l-7.8 7.8c-.4.4-.4 1 0 1.4l7.8 7.8c.2.2.5.3.7.3s.5-.1.7-.3c.4-.4.4-1 0-1.4L3.3 12.7H22c.6 0 1-.4 1-1s-.4-1-1-1H3.3z"/></svg>
        </a>
      <?php endif; ?>
      <h1 class="photos-hero-title"><?= $selectedYear ? htmlspecialchars($selectedYear['title']) : 'Nos éditions' ?></h1>
    </section>

    <?php if ($isPreview): ?>
    <div style="background:#fd7e14;color:#fff;text-align:center;padding:10px;font-weight:600;font-size:14px;margin:12px auto;border-radius:8px;max-width:1200px;">
      Aperçu – Cette page n'est pas encore publiée
    </div>
    <?php endif; ?>

    <?php if ($selectedYearId): ?>
      <?php if (!empty($albums)): ?>
        <div class="albums-grid">
          <?php foreach ($albums as $album): ?>
            <?php
              if (empty($album['album_title']) && empty($album['album_link'])) continue;
              $creatorName = resolveAlbumCreator($album);
              $dateLabel = resolveAlbumDateLabel($album);
              $isLocal = (($album['album_type'] ?? 'link') === 'local');
              $albumHref = $isLocal
                ? 'gallery.php?album_id=' . $album['id']
                : htmlspecialchars($album['album_link']);
              $albumTarget = $isLocal ? '_self' : '_blank';

              // Count photos for local albums
              $localPhotoCount = 0;
              if ($isLocal && !empty($album['album_link'])) {
                $localDir = __DIR__ . '/../files/_albums/' . basename($album['album_link']);
                if (is_dir($localDir)) {
                  $exts = ['jpg','jpeg','png','gif','webp'];
                  foreach (scandir($localDir) as $f) {
                    if ($f === '.' || $f === '..') continue;
                    if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts)) $localPhotoCount++;
                  }
                }
              }
            ?>
            <a href="<?= $albumHref ?>" <?= $isLocal ? '' : 'rel="noopener noreferrer"' ?> target="<?= $albumTarget ?>" class="album-card">
              <div class="album-card-media">
                <div class="album-card-media-inner">
                  <?php if (!empty($album['album_img']) && is_file('../files/_albums/' . $album['album_img'])): ?>
                    <img src="../files/_albums/<?= htmlspecialchars($album['album_img']) ?>"
                         class="album-card-image"
                         alt="<?= htmlspecialchars($album['album_title']) ?>"
                         loading="lazy">
                  <?php endif; ?>
                </div>
                <span class="album-card-creator"><?= htmlspecialchars($creatorName) ?></span>
              </div>

              <div class="album-card-content">
                <h2 class="album-card-title"><?= htmlspecialchars($album['album_title']) ?></h2>
                <div class="album-card-footer">
                  <p class="album-card-date"><?= htmlspecialchars($dateLabel) ?></p>
                  <?php if ($isLocal && $localPhotoCount > 0): ?>
                    <span style="font-size:13px;color:#7c3aed;font-weight:600"><?= $localPhotoCount ?> photo<?= $localPhotoCount > 1 ? 's' : '' ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div style="text-align: center; padding: 60px 20px; color: var(--page-muted);">
          <p>Aucun album disponible pour cette annee.</p>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <?php if (!empty($years)): ?>
        <div class="years-grid">
          <?php foreach ($years as $year): ?>
            <?php if (empty($year['title']) && empty($year['year'])) continue; ?>
            <a href="?year_id=<?= $year['id'] ?>" class="year-card" data-year="<?= htmlspecialchars($year['year']) ?>">
              <span class="year-card-arrow"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/><path d="M5 12h14" stroke="#fff" stroke-width="2" fill="none"/><path d="M13 6l6 6-6 6" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
              <span class="year-card-title"><?= htmlspecialchars($year['title']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div style="text-align: center; padding: 60px 20px; color: var(--page-muted);">
          <p>Aucune année disponible pour le moment.</p>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </main>

  <?php include '../inc/footer-modern.php'; ?>

  <script src="../js/fer-modern.js"></script>
</body>
</html>
