<?php
require '../config/config.php';
require '../inc/navbar-data.php';

// Recuperation des annees disponibles
$stmtYears = $pdo->prepare('SELECT * FROM photo_years ORDER BY year DESC');
$stmtYears->execute();
$years = $stmtYears->fetchAll(PDO::FETCH_ASSOC);

// Si une annee est selectionnee, recuperer les albums associes
$selectedYearId = isset($_GET['year_id']) ? (int)$_GET['year_id'] : null;
$albums = [];
$selectedYear = null;

if ($selectedYearId) {
    $stmtYear = $pdo->prepare('SELECT * FROM photo_years WHERE id = :id LIMIT 1');
    $stmtYear->execute(['id' => $selectedYearId]);
    $selectedYear = $stmtYear->fetch(PDO::FETCH_ASSOC);

    $stmtAlbums = $pdo->prepare('SELECT * FROM photo_albums WHERE year_id = :year_id');
    $stmtAlbums->execute(['year_id' => $selectedYearId]);
    $albums = $stmtAlbums->fetchAll(PDO::FETCH_ASSOC);
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
  <title>Photos - Forbach en Rose</title>
  <link rel="stylesheet" href="../css/fer-modern.css">
  <style>
    /* Trait subtil sous la navbar */
    .floating-nav {
      border-bottom: 1px solid rgba(0,0,0,0.06);
    }

    .photos-hero {
      width: 100%;
      margin: 174px auto 36px;
      display: flex;
      justify-content: flex-start;
    }

    .album-reg-bar {
      width: min(100%, 530px);
    }

    .album-reg-card {
      height: 56px;
      min-height: 56px;
      border-radius: 12px;
      overflow: hidden;
      background: rgba(15,23,42,.04);
      display: flex;
      align-items: center;
    }

    .album-reg-bevel {
      align-self: stretch;
      flex: 0 0 148px;
      background: var(--page-text);
      clip-path: polygon(0 0, 100% 0, 78% 100%, 0 100%);
      margin-right: -6px;
      display: flex;
      align-items: center;
      justify-content: flex-start;
      padding-left: 12px;
    }

    .album-reg-bevel .back-btn {
      color: #ffffff;
      text-decoration: none;
      display: flex;
      align-items: center;
      line-height: 1;
      transition: opacity .2s ease;
    }

    .album-reg-bevel .back-btn:hover {
      opacity: 0.7;
    }

    .album-reg-title {
      margin: 0;
      color: var(--page-text);
      font-size: clamp(18px, 3.2vw, 20px);
      font-weight: 900;
      letter-spacing: -0.03em;
      line-height: 1.1;
      flex: 1 1 auto;
      text-align: right;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      padding: 0 22px 0 20px;
    }

    .albums-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      gap: 18px;
      margin: 40px auto;
    }

    .album-card {
      background: #f6f6f7;
      border-radius: 12px;
      transition: transform .25s ease, box-shadow .25s ease;
      text-decoration: none;
      color: var(--page-text);
      display: flex;
      flex-direction: column;
      padding: 0;
      min-height: 100%;
      overflow: hidden;
    }

    .album-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 3px 9px rgba(2,6,23,.13);
    }

    .album-card-media {
      border-radius: 0 0 12px 12px;
      overflow: hidden;
      background: transparent;
      aspect-ratio: 16 / 9;
      margin: 0;
    }

    .album-card-image {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .album-card-content {
      display: flex;
      flex-direction: column;
      flex: 1;
      padding: 24px 24px 20px;
    }

    .album-card-title {
      margin: 0;
      font-size: clamp(17px, 1.35vw, 22px);
      font-weight: 500;
      letter-spacing: -0.01em;
      line-height: 1.25;
      color: #0f172a;
      margin-bottom: 14px;
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

    .album-card-creator {
      display: inline-flex;
      align-items: center;
      padding: 4px 9px;
      border-radius: 8px;
      border: 1px solid rgba(15,23,42,.36);
      color: #334155;
      font-size: 13px;
      font-weight: 600;
      line-height: 1.1;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 50%;
    }

    @media (max-width: 980px) {
      .photos-hero {
        margin-top: 36px;
      }

      .album-reg-bar {
        width: min(100%, 300px);
      }

      .album-reg-card {
        height: 56px;
        min-height: 56px;
      }

      .album-reg-bevel {
        flex-basis: 106px;
        margin-right: -5px;
      }

      .album-reg-title {
        font-size: clamp(16px, 4.6vw, 19px);
        text-align: right;
        padding: 0 16px 0 14px;
      }

      .albums-grid {
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 16px;
      }

      .album-card {
        border-radius: 12px;
      }

      .album-card-media {
        border-radius: 0 0 14px 14px;
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

      .album-card-creator {
        max-width: 45%;
      }
    }

    /* Year buttons */
    .years-grid {
      display: flex;
      flex-wrap: wrap;
      justify-content: flex-start;
      gap: 16px;
      margin: 40px 0 0 0;
    }

    .year-card {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 10px 24px;
      background: transparent;
      border: 1px solid rgba(15,23,42,0.12);
      border-radius: 99px;
      color: var(--page-text);
      font-size: 15px;
      font-weight: 500;
      text-decoration: none;
      transition: all .25s ease;
      min-width: 0;
    }

    .year-card:hover {
      background: rgba(15,23,42,0.06);
      border-color: rgba(15,23,42,0.3);
      transform: translateY(-2px);
    }

    @media (max-width: 980px) {
      .years-grid {
        gap: 12px;
        margin-top: 30px;
      }
      .year-card {
        padding: 14px 24px;
        font-size: 18px;
        min-width: 100px;
      }
    }
  </style>
</head>
<body>
  <?php include '../inc/navbar-modern.php'; ?>

  <main>
    <section class="photos-hero" aria-label="Titre de la page">
      <div class="album-reg-bar">
        <div class="album-reg-card">
          <div class="album-reg-bevel" aria-hidden="<?= $selectedYear ? 'false' : 'true' ?>"><?php if ($selectedYear): ?><a href="photos.php" title="Retour" class="back-btn"><svg viewBox="0 0 24 24" width="22" height="22" fill="#ffffff"><path d="M3.3 11.3l6.8-6.8c.4-.4.4-1 0-1.4s-1-.4-1.4 0l-7.8 7.8c-.4.4-.4 1 0 1.4l7.8 7.8c.2.2.5.3.7.3s.5-.1.7-.3c.4-.4.4-1 0-1.4L3.3 12.7H22c.6 0 1-.4 1-1s-.4-1-1-1H3.3z"/></svg></a><?php endif; ?></div>
          <h1 class="album-reg-title"><?= $selectedYear ? htmlspecialchars($selectedYear['title']) : 'Éditions :' ?></h1>
        </div>
      </div>
    </section>

    <?php if ($selectedYearId): ?>
      <?php if (!empty($albums)): ?>
        <div class="albums-grid">
          <?php foreach ($albums as $album): ?>
            <?php
              $creatorName = resolveAlbumCreator($album);
              $dateLabel = resolveAlbumDateLabel($album);
            ?>
            <a href="<?= htmlspecialchars($album['album_link']) ?>" target="_blank" rel="noopener noreferrer" class="album-card">
              <div class="album-card-media">
                <?php if (!empty($album['album_img'])): ?>
                  <img src="../files/_albums/<?= htmlspecialchars($album['album_img']) ?>"
                       class="album-card-image"
                       alt="<?= htmlspecialchars($album['album_title']) ?>"
                       loading="lazy">
                <?php endif; ?>
              </div>

              <div class="album-card-content">
                <h2 class="album-card-title"><?= htmlspecialchars($album['album_title']) ?></h2>
                <div class="album-card-footer">
                  <p class="album-card-date"><?= htmlspecialchars($dateLabel) ?></p>
                  <span class="album-card-creator"><?= htmlspecialchars($creatorName) ?></span>
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
            <a href="?year_id=<?= $year['id'] ?>" class="year-card">
              <?= htmlspecialchars($year['title']) ?>
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
