<?php
require '../config/config.php';
require_once '../config/tracker.php';
trackPageVisit();
checkMaintenance();
require '../inc/navbar-data.php';

// Check if status column exists
$hasStatusCol = false;
try { $pdo->query("SELECT status FROM partners_years LIMIT 0"); $hasStatusCol = true; } catch (PDOException $e) {}

// Check preview mode : nécessite session valide + accès page 'partners'
$isPreview = false;
$previewYearId = isset($_GET['preview_year']) ? (int)$_GET['preview_year'] : 0;
if ($previewYearId > 0) {
    if (!isset($_SESSION['uid']) || !canAccessPage('partners')) {
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
  <link rel="stylesheet" href="../css/partenaires.css">
<?php include __DIR__ . '/../config/theme.php'; ?>
</head>
<body>
  <?php include '../inc/navbar-modern.php'; ?>

  <main>
    <?php if ($selectedYearId && $selectedYear): ?>
      <!-- Titre -->
      <div class="partners-title-bar" style="margin-bottom: 30px;">
        <a href="partenaires" title="Retour" class="back-btn">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3.3 11.3l6.8-6.8c.4-.4.4-1 0-1.4s-1-.4-1.4 0l-7.8 7.8c-.4.4-.4 1 0 1.4l7.8 7.8c.2.2.5.3.7.3s.5-.1.7-.3c.4-.4.4-1 0-1.4L3.3 12.7H22c.6 0 1-.4 1-1s-.4-1-1-1H3.3z"/></svg>
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
          <?php
            $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
          ?>
          <?php foreach ($years as $year): ?>
            <?php if (empty($year['title']) && empty($year['year'])) continue; ?>
            <a href="?year_id=<?= $year['id'] ?>" class="year-card" data-year="<?= htmlspecialchars($year['year']) ?>">
              <?php if (!empty($year['created_at']) && $year['created_at'] >= $sevenDaysAgo): ?>
                <span class="year-card-badge-new" data-new-type="partners" data-new-id="<?= $year['id'] ?>">NEW</span>
              <?php endif; ?>
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

    // Images TinyMCE dans la description → ouvrir en grand
    document.querySelectorAll('.info-modal-desc img').forEach(img => {
      img.addEventListener('click', (e) => { e.stopPropagation(); showImageModal(img.src); });
    });

    // Transformer les liens PDF en jolis boutons (dédupliqués)
    const seenPdf = new Set();
    document.querySelectorAll('.info-modal-desc a[href$=".pdf"]').forEach(a => {
      const href = a.getAttribute('href');
      if (seenPdf.has(href)) { a.remove(); return; }
      seenPdf.add(href);
      const raw = (a.title || href.split('/').pop()).replace(/\.[^.]+$/, '');
      const name = /^tiny_[a-f0-9.]+$/.test(raw) ? 'Document' : raw;
      a.className = 'pdf-link';
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.innerHTML = '<span class="pdf-link-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15l3 3 3-3"/></svg></span><span class="pdf-link-info"><span class="pdf-link-name">' + name + '.pdf</span><span class="pdf-link-hint">Cliquer pour ouvrir</span></span>';
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        imageModal.classList.remove('active');
        if (infoModal) infoModal.classList.remove('active');
      }
    });
  </script>
</body>
</html>
