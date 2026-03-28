<?php
require '../config/config.php';
require '../inc/navbar-data.php';

// Check if status column exists
$hasStatusCol = false;
try { $pdo->query("SELECT status FROM partners_years LIMIT 0"); $hasStatusCol = true; } catch (PDOException $e) {}

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

// Récupération des années disponibles pour les partenaires
try {
    if ($isPreview) {
        // Preview: show published + draft, but NOT trashed
        $stmtYears = $pdo->prepare('SELECT * FROM partners_years WHERE deleted_at IS NULL ORDER BY year DESC');
        $stmtYears->execute();
    } else {
        // Public: only published, non-deleted
        if ($hasStatusCol) {
            $stmtYears = $pdo->prepare("SELECT * FROM partners_years WHERE deleted_at IS NULL AND status = 'published' ORDER BY year DESC");
        } else {
            $stmtYears = $pdo->prepare('SELECT * FROM partners_years ORDER BY year DESC');
        }
        $stmtYears->execute();
    }
    $years = $stmtYears->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $years = [];
}

// Si une année est sélectionnée, récupérer les partenaires associés
$selectedYearId = $previewYearId ?: (isset($_GET['year_id']) ? (int)$_GET['year_id'] : null);
$partners = [];
$selectedYear = null;

// Récupération de la description générique des partenaires
try {
    $stmtSetting = $pdo->prepare('SELECT partners_title, partners_desc, partners_img FROM setting WHERE id = 1 LIMIT 1');
    $stmtSetting->execute();
    $settingData = $stmtSetting->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $settingData = [];
}
$partners_title = $settingData['partners_title'] ?? '';
$partners_desc = $settingData['partners_desc'] ?? '';
$partners_img = $settingData['partners_img'] ?? '';

if ($selectedYearId) {
    try {
        // Vérifier que l'année existe et est publiée (sauf en preview admin)
        if ($isPreview) {
            $stmtYear = $pdo->prepare('SELECT * FROM partners_years WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        } else {
            $stmtYear = $pdo->prepare("SELECT * FROM partners_years WHERE id = :id AND deleted_at IS NULL AND status = 'published' LIMIT 1");
        }
        $stmtYear->execute(['id' => $selectedYearId]);
        $selectedYear = $stmtYear->fetch(PDO::FETCH_ASSOC);

        if (!$selectedYear && !$isPreview) {
            header('Location: partenaires');
            exit;
        }

        $stmtAlbums = $pdo->prepare('SELECT * FROM partners_albums WHERE year_id = :year_id AND deleted_at IS NULL ORDER BY sort_order');
        $stmtAlbums->execute(['year_id' => $selectedYearId]);
        $partners = $stmtAlbums->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $selectedYear = null;
        $partners = [];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Partenaires</title>
  <link rel="stylesheet" href="../css/fer-modern.css">
  <style>
    .floating-nav {
      border-bottom: 1px solid rgba(0,0,0,0.06);
    }

    /* Info card (partenaires presentation) */
    .info-card {
      position: relative;
      display: flex;
      flex-direction: column;
      justify-content: flex-end;
      padding: 28px 24px;
      min-height: 160px;
      background: linear-gradient(135deg, var(--pink) 0%, #f472b6 100%);
      border: none;
      border-radius: 16px;
      color: #fff;
      text-decoration: none;
      cursor: pointer;
      transition: all .35s cubic-bezier(.4,0,.2,1);
      overflow: hidden;
      box-shadow: 0 4px 16px rgba(236,72,153,0.2);
    }

    .info-card::before {
      content: attr(data-label);
      position: absolute;
      top: -10px;
      right: -8px;
      font-size: 120px;
      font-weight: 900;
      color: rgba(255,255,255,0.15);
      line-height: 1;
      pointer-events: none;
      letter-spacing: -6px;
      z-index: 2;
    }

    .info-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 12px 32px rgba(236,72,153,0.3);
    }

    .info-card-title {
      position: relative;
      z-index: 1;
      font-size: 20px;
      font-weight: 700;
      color: #fff;
      margin: 0;
      line-height: 1.3;
    }

    .info-card-arrow {
      position: absolute;
      bottom: 20px;
      right: 20px;
      z-index: 1;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: rgba(255,255,255,0.2);
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .35s ease;
    }

    .info-card:hover .info-card-arrow {
      background: rgba(255,255,255,0.35);
      transform: translateX(3px);
    }

    .info-card-arrow svg {
      width: 16px;
      height: 16px;
      fill: none;
    }

    /* Info modal */
    .info-modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.6);
      z-index: 99998;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .info-modal-overlay.active {
      display: flex;
    }

    .info-modal {
      background: #fff;
      border-radius: 16px;
      max-width: 1100px;
      width: 100%;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 20px 60px rgba(0,0,0,0.2);
      padding: 48px;
      position: relative;
    }

    .info-modal-close {
      position: absolute;
      top: 16px;
      right: 16px;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: rgba(15,23,42,0.06);
      border: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      color: #0f172a;
      transition: background .2s;
    }

    .info-modal-close:hover {
      background: rgba(15,23,42,0.12);
    }

    .info-modal-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 40px;
      align-items: center;
    }

    .info-modal-img {
      width: 100%;
      height: auto;
      border-radius: 12px;
      object-fit: cover;
    }

    .info-modal-title {
      margin: 0 0 20px;
      font-size: clamp(24px, 3vw, 36px);
      font-weight: 900;
      color: #0f172a;
      letter-spacing: -0.02em;
    }

    .info-modal-desc {
      font-size: 17px;
      line-height: 1.7;
      color: rgba(15,23,42,0.65);
    }

    @media (max-width: 768px) {
      .info-modal {
        padding: 28px 20px;
      }
      .info-modal-grid {
        grid-template-columns: 1fr;
        gap: 24px;
      }
    }

    /* Hero title bar */
    .partners-title-bar {
      display: flex;
      align-items: center;
      gap: 16px;
      max-width: 1200px;
      margin: 174px auto 0;
      padding: 0 24px;
    }
    @media (max-width: 980px) {
      .partners-title-bar {
        margin-top: 16px;
      }
    }

    .partners-title-bar .back-btn {
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

    .partners-title-bar .back-btn:hover {
      background: var(--pink);
    }

    .partners-title-bar-title {
      margin: 0;
      color: var(--page-text);
      font-size: clamp(24px, 3.5vw, 32px);
      font-weight: 800;
      letter-spacing: -0.03em;
      line-height: 1.2;
    }

    /* Grid des partenaires */
    .partners-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
      gap: 32px;
      max-width: 1200px;
      margin: 0 auto 100px;
      padding: 0 24px;
    }

    /* Carte partenaire */
    .partner-card {
      background: #ffffff;
      border: 1px solid rgba(15,23,42,0.08);
      box-shadow: 0 4px 16px rgba(0,0,0,.06);
      border-radius: 12px;
      overflow: hidden;
      transition: transform .3s ease, box-shadow .3s ease;
      cursor: pointer;
      display: flex;
      flex-direction: column;
      height: 100%;
    }

    .partner-card:hover {
      transform: translateY(-6px);
      box-shadow: 0 12px 40px rgba(0,0,0,.12);
    }

    .partner-card-image-wrapper {
      width: 100%;
      height: 240px;
      overflow: hidden;
      background: transparent;
      padding: 0;
      margin: 0;
      display: block;
      border-radius: 0 0 12px 12px;
    }

    .partner-card-image {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 0 0 12px 12px;
      display: block;
      margin: 0;
      padding: 0;
    }

    .partner-card-content {
      padding: 28px 28px 32px;
      flex: 1;
      display: flex;
      flex-direction: column;
    }

    .partner-card-title {
      margin: 0 0 16px;
      font-size: 24px;
      font-weight: 600;
      color: #0f172a;
      line-height: 1.3;
    }

    .partner-card-desc {
      margin: 0;
      font-size: 16px;
      color: rgba(15,23,42,0.65);
      line-height: 1.6;
    }

    /* Year cards */
    .years-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 20px;
      margin: 30px 0 0 0;
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
      fill: none;
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
      .info-card {
        min-height: 130px;
        padding: 22px 20px;
      }
      .info-card::before {
        font-size: 90px;
        top: -6px;
        right: -4px;
      }
    }

    /* Message vide */
    .empty-state {
      text-align: center;
      padding: 100px 24px;
      color: var(--page-muted);
    }

    .empty-state p {
      font-size: 18px;
    }

    /* Modal */
    .modal {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.95);
      z-index: 99999;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .modal.active {
      display: flex;
    }

    .modal-close {
      position: absolute;
      top: 30px;
      right: 40px;
      font-size: 48px;
      color: #ffffff;
      cursor: pointer;
      user-select: none;
      transition: transform .2s ease;
    }

    .modal-close:hover {
      transform: scale(1.1);
    }

    .modal-image {
      max-width: 90%;
      max-height: 90%;
      border-radius: 12px;
      box-shadow: 0 20px 60px rgba(0,0,0,.8);
    }

    @media (max-width: 980px) {
      .partners-grid {
        grid-template-columns: 1fr;
        gap: 24px;
        margin-bottom: 60px;
      }

      .partner-card-image-wrapper {
        height: 200px;
        padding: 0;
      }

      .partner-card-content {
        padding: 20px;
      }

      .partner-card-title {
        font-size: 20px;
      }

      .partner-card-desc {
        font-size: 15px;
      }

      .partners-hero-desc,

    }

  </style>
</head>
<body>
  <?php include '../inc/navbar-modern.php'; ?>

  <main>
    <?php if ($selectedYearId && $selectedYear): ?>
      <!-- Titre -->
      <div class="partners-title-bar" style="margin-bottom: 30px;">
        <a href="partenaires" title="Retour" class="back-btn">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="#ffffff"><path d="M3.3 11.3l6.8-6.8c.4-.4.4-1 0-1.4s-1-.4-1.4 0l-7.8 7.8c-.4.4-.4 1 0 1.4l7.8 7.8c.2.2.5.3.7.3s.5-.1.7-.3c.4-.4.4-1 0-1.4L3.3 12.7H22c.6 0 1-.4 1-1s-.4-1-1-1H3.3z"/></svg>
        </a>
        <h1 class="partners-title-bar-title"><?= htmlspecialchars($selectedYear['title']) ?></h1>
      </div>

      <?php if ($isPreview): ?>
        <div style="background:#fd7e14;color:#fff;text-align:center;padding:10px;font-weight:600;font-size:14px;margin:12px auto;border-radius:8px;max-width:1200px;">
          Aperçu – Cette page n'est pas encore publiée
        </div>
      <?php endif; ?>

      <!-- Grid des partenaires -->
      <?php if (!empty($partners)): ?>
        <div class="partners-grid">
          <?php foreach ($partners as $partner): ?>
            <?php
              if (empty($partner['album_title']) && empty($partner['album_img']) && empty($partner['album_desc'])) continue;
              $hasPartnerImg = !empty($partner['album_img']) && is_file('../files/_partners/' . $partner['album_img']);
            ?>
            <div class="partner-card"<?php if ($hasPartnerImg): ?> data-img="../files/_partners/<?= htmlspecialchars($partner['album_img']) ?>"<?php endif; ?>>
              <?php if ($hasPartnerImg): ?>
                <div class="partner-card-image-wrapper">
                  <img src="../files/_partners/<?= htmlspecialchars($partner['album_img']) ?>"
                       class="partner-card-image"
                       alt="<?= htmlspecialchars($partner['album_title']) ?>"
                       loading="lazy">
                </div>
              <?php endif; ?>

              <div class="partner-card-content">
                <h2 class="partner-card-title"><?= htmlspecialchars($partner['album_title']) ?></h2>
                <?php if (!empty($partner['album_desc'])): ?>
                  <p class="partner-card-desc"><?= nl2br(htmlspecialchars($partner['album_desc'])) ?></p>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty-state">
          <p>Aucun partenaire pour cette année.</p>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <?php $hasInfo = !empty($partners_img) || !empty($partners_desc) || !empty($partners_title); ?>

      <div class="partners-title-bar">
        <h2 class="partners-title-bar-title">Nos éditions</h2>
      </div>

      <?php if ($hasInfo || !empty($years)): ?>
      <div class="years-grid" style="max-width: 1200px; margin: 30px auto 0; padding: 0 24px;">
        <?php if ($hasInfo): ?>
          <div class="info-card" data-label="Info" id="infoCardTrigger">
            <span class="info-card-arrow"><svg viewBox="0 0 24 24"><path d="M5 12h14" stroke="#fff" stroke-width="2" fill="none"/><path d="M13 6l6 6-6 6" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <span class="info-card-title"><?= htmlspecialchars($partners_title ?: 'Nos partenaires') ?></span>
          </div>
        <?php endif; ?>

        <?php if (!empty($years)): ?>
          <?php foreach ($years as $year): ?>
            <?php if (empty($year['title']) && empty($year['year'])) continue; ?>
            <a href="?year_id=<?= $year['id'] ?>" class="year-card" data-year="<?= htmlspecialchars($year['year']) ?>">
              <span class="year-card-arrow"><svg viewBox="0 0 24 24"><path d="M5 12h14" stroke="#fff" stroke-width="2" fill="none"/><path d="M13 6l6 6-6 6" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
              <span class="year-card-title"><?= htmlspecialchars($year['title']) ?></span>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if (!$hasInfo && empty($years)): ?>
        <div style="text-align: center; padding: 60px 20px; color: var(--page-muted);">
          <p>Aucune année disponible pour le moment.</p>
        </div>
      <?php endif; ?>

      <?php if ($hasInfo): ?>
        <div id="infoModal" class="info-modal-overlay">
          <div class="info-modal">
            <button class="info-modal-close" id="infoModalClose">&times;</button>
            <?php $hasModalImg = !empty($partners_img) && is_file('../files/_partners/' . $partners_img); ?>
            <div class="info-modal-grid"<?php if (!$hasModalImg): ?> style="grid-template-columns:1fr"<?php endif; ?>>
              <?php if ($hasModalImg): ?>
                <img src="../files/_partners/<?= htmlspecialchars($partners_img) ?>" class="info-modal-img" alt="Partenaires">
              <?php endif; ?>
              <div>
                <?php if (!empty($partners_title)): ?>
                  <h2 class="info-modal-title"><?= htmlspecialchars($partners_title) ?></h2>
                <?php endif; ?>
                <?php if (!empty($partners_desc)): ?>
                  <div class="info-modal-desc"><?= sanitizeHtml($partners_desc ?? '') ?></div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </main>

  <!-- Modal pour afficher l'image en grand -->
  <div id="imageModal" class="modal">
    <span class="modal-close" id="imageModalClose">&times;</span>
    <img id="modalImage" src="" class="modal-image" alt="">
  </div>

  <?php include '../inc/footer-modern.php'; ?>

  <script src="../js/fer-modern.js"></script>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
    function showImageModal(src) {
      document.getElementById('modalImage').src = src;
      document.getElementById('imageModal').classList.add('active');
    }

    // Partner cards → ouvrir l'image en grand
    document.querySelectorAll('.partner-card[data-img]').forEach(card => {
      card.addEventListener('click', () => showImageModal(card.dataset.img));
    });

    // Info card → ouvrir le modal info
    const infoTrigger = document.getElementById('infoCardTrigger');
    const infoModal   = document.getElementById('infoModal');
    if (infoTrigger && infoModal) {
      infoTrigger.addEventListener('click', () => infoModal.classList.add('active'));
    }

    // Fermer le modal info (bouton close + clic sur overlay)
    const infoClose = document.getElementById('infoModalClose');
    if (infoClose)  infoClose.addEventListener('click', () => infoModal.classList.remove('active'));
    if (infoModal)  infoModal.addEventListener('click', (e) => { if (e.target === infoModal) infoModal.classList.remove('active'); });

    // Fermer le modal image (bouton close + clic sur overlay + Escape)
    const imageModal = document.getElementById('imageModal');
    document.getElementById('imageModalClose').addEventListener('click', () => imageModal.classList.remove('active'));
    imageModal.addEventListener('click', (e) => { if (e.target === imageModal) imageModal.classList.remove('active'); });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        imageModal.classList.remove('active');
        if (infoModal) infoModal.classList.remove('active');
      }
    });
  </script>
</body>
</html>
