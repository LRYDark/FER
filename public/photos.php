<?php
require '../src/core/config.php';
require_once '../src/content/tracker.php';
trackPageVisit();
checkMaintenance();
require __DIR__ . '/../src/partials/navbar-data.php';

// Check if status column exists
$hasStatusCol = false;
try { $pdo->query("SELECT status FROM photo_years LIMIT 0"); $hasStatusCol = true; } catch (PDOException $e) {}

// Check preview mode : nécessite session valide + accès page 'albums'
$isPreview = false;
$previewYearId = isset($_GET['preview_year']) ? (int)$_GET['preview_year'] : 0;
if ($previewYearId > 0) {
    if (!isset($_SESSION['uid']) || !canAccessPage('albums')) {
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
  <link rel="stylesheet" href="../css/photos.css">
<?php include __DIR__ . '/../src/content/theme.php'; ?>
</head>
<body>
  <?php include __DIR__ . '/../src/partials/navbar-modern.php'; ?>

  <main>
    <section class="photos-hero" aria-label="Titre de la page">
      <?php if ($selectedYear): ?>
        <a href="photos" title="Retour" class="back-btn">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3.3 11.3l6.8-6.8c.4-.4.4-1 0-1.4s-1-.4-1.4 0l-7.8 7.8c-.4.4-.4 1 0 1.4l7.8 7.8c.2.2.5.3.7.3s.5-.1.7-.3c.4-.4.4-1 0-1.4L3.3 12.7H22c.6 0 1-.4 1-1s-.4-1-1-1H3.3z"/></svg>
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
                <span class="album-card-creator"><?= htmlspecialchars($album['album_title']) ?></span>
              </div>

              <div class="album-card-content">
                <?php if ($creatorName !== 'Auteur inconnu'): ?>
                  <h2 class="album-card-title"><?= htmlspecialchars($creatorName) ?></h2>
                <?php endif; ?>
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
          <?php
            $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
          ?>
          <?php foreach ($years as $year): ?>
            <?php if (empty($year['title']) && empty($year['year'])) continue; ?>
            <a href="?year_id=<?= $year['id'] ?>" class="year-card" data-year="<?= htmlspecialchars($year['year']) ?>">
              <?php if (!empty($year['created_at']) && $year['created_at'] >= $sevenDaysAgo): ?>
                <span class="year-card-badge-new" data-new-type="photos" data-new-id="<?= $year['id'] ?>">NEW</span>
              <?php endif; ?>
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

  <?php include __DIR__ . '/../src/partials/footer-modern.php'; ?>

  <script src="../js/fer-modern.js"></script>
</body>
</html>
