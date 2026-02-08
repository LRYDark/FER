<?php
require '../config/config.php';
require '../inc/navbar-data.php';

// Récupération des années disponibles pour les partenaires
$stmtYears = $pdo->prepare('SELECT * FROM partners_years ORDER BY year DESC');
$stmtYears->execute();
$years = $stmtYears->fetchAll(PDO::FETCH_ASSOC);

// Si une année est sélectionnée, récupérer les partenaires associés
$selectedYearId = isset($_GET['year_id']) ? (int)$_GET['year_id'] : null;
$partners = [];
$selectedYear = null;

// Récupération de la description générique des partenaires
$stmtSetting = $pdo->prepare('SELECT partners_desc, partners_img FROM setting WHERE id = 1 LIMIT 1');
$stmtSetting->execute();
$settingData = $stmtSetting->fetch(PDO::FETCH_ASSOC);
$partners_desc = $settingData['partners_desc'] ?? '';
$partners_img = $settingData['partners_img'] ?? '';

if ($selectedYearId) {
    $stmtAlbums = $pdo->prepare('SELECT * FROM partners_albums WHERE year_id = :year_id');
    $stmtAlbums->execute(['year_id' => $selectedYearId]);
    $partners = $stmtAlbums->fetchAll(PDO::FETCH_ASSOC);

    $stmtYear = $pdo->prepare('SELECT * FROM partners_years WHERE id = :id LIMIT 1');
    $stmtYear->execute(['id' => $selectedYearId]);
    $selectedYear = $stmtYear->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Partenaires - Forbach en Rose</title>
  <link rel="stylesheet" href="../css/fer-modern.css">
  <style>
    /* Theme sombre */
    body {
      background: #0a0e12;
      color: #ffffff;
    }

    /* Navbar fixe (non scrollée) */
    .floating-nav {
      border-bottom: 1px solid rgba(255,255,255,0.08);
      background: #141a20;
      backdrop-filter: blur(10px);
    }

    /* Pill transparent en version fixe */
    .floating-nav .nav-pill {
      background: transparent !important;
      border: none !important;
    }

    /* En version flottante : navbar transparente, pill avec fond sombre */
    body.nav-scrolled .floating-nav {
      background: transparent !important;
    }

    body.nav-scrolled .floating-nav .nav-pill {
      background: #141a20 !important;
      border: 1px solid rgba(255,255,255,0.1) !important;
    }

    /* Nav-card grise en version fixe - même style que le bouton inscription */
    .floating-nav .nav-card {
      background: rgba(255,255,255,0.05) !important;
      border: 1px solid rgba(255,255,255,0.1) !important;
      border-radius: 12px;
      padding: 0 18px;
      margin-left: 20px;
      min-height: 56px;
      display: flex;
      align-items: center;
    }

    /* Nav-card transparente en version flottante (après scroll) */
    body.nav-scrolled .floating-nav .nav-card {
      background: transparent !important;
      border: none !important;
      border-radius: 0;
      padding: 0;
      margin-left: 0;
    }

    /* Logo en blanc */
    .floating-nav .brand-logo {
      filter: brightness(0) invert(1);
    }

    /* Tous les textes et icônes en blanc */
    .floating-nav .link,
    .floating-nav .nav-icon,
    .floating-nav .item,
    .floating-nav .trigger,
    .floating-nav .menu .link {
      color: #ffffff !important;
    }

    .floating-nav .link:hover,
    .floating-nav .trigger:hover {
      color: rgba(255,255,255,0.7) !important;
    }

    /* Flèches des menus en blanc */
    .floating-nav .chev path {
      stroke: rgba(255,255,255,0.85) !important;
    }

    .floating-nav .burger-icon {
      background: rgba(255,255,255,0.85) !important;
    }

    .floating-nav .burger-icon::before,
    .floating-nav .burger-icon::after {
      background: rgba(255,255,255,0.85) !important;
    }

    /* Bouton inscription */
    .floating-nav .btn-inscription {
      background: #19a1be !important;
      color: #ffffff !important;
    }

    .floating-nav .btn-inscription:hover {
      background: #1589a3 !important;
    }

    /* Mega menu adapté au thème sombre */
    .floating-nav .mega {
      background: #141a20 !important;
      border: 1px solid rgba(255,255,255,0.1);
    }

    .floating-nav .mega .mega-title {
      color: rgba(255,255,255,0.5) !important;
    }

    /* Tous les liens et textes du mega menu en blanc */
    .floating-nav .mega .mega-link,
    .floating-nav .mega .link {
      color: #ffffff !important;
    }

    .floating-nav .mega .mega-link:hover,
    .floating-nav .mega .link:hover {
      color: rgba(255,255,255,0.7) !important;
      background: rgba(255,255,255,0.05) !important;
    }

    /* Titres et descriptions des liens en blanc */
    .floating-nav .mega .mtitle {
      color: #ffffff !important;
    }

    .floating-nav .mega .mdesc {
      color: rgba(255,255,255,0.6) !important;
    }

    .floating-nav .mega .micon {
      color: rgba(255,255,255,0.7) !important;
      background: rgba(255,255,255,0.08) !important;
    }

    .floating-nav .mega .mega-link:hover .micon {
      background: rgba(255,255,255,0.14) !important;
    }

    /* Textes génériques du mega menu */
    .floating-nav .mega a,
    .floating-nav .mega span,
    .floating-nav .mega li,
    .floating-nav .mega p,
    .floating-nav .mega div {
      color: #ffffff !important;
    }

    /* Mega-featured garde toujours son apparence d'origine (rose clair) */
    .floating-nav .mega .mega-featured {
      background: linear-gradient(135deg, #fce7f3 0%, #fbcfe8 100%) !important;
      border-radius: 20px;
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      height: 100%;
      min-height: 320px;
    }

    .floating-nav .mega .mega-featured-img {
      width: 100%;
      height: 200px;
      border-radius: 16px;
      overflow: hidden;
      background: rgba(255,255,255,0.5) !important;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 64px;
    }

    .floating-nav .mega .mega-featured-title {
      font-size: 18px !important;
      font-weight: 700;
      color: #831843 !important;
      margin: 0;
      line-height: 1.3;
    }

    .floating-nav .mega .mega-featured-desc {
      font-size: 14px !important;
      color: rgba(15,23,42,.7) !important;
      line-height: 1.5;
      margin: 0;
    }

    .floating-nav .mega .mega-featured-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: #e91e63 !important;
      font-weight: 600;
      font-size: 14px;
      text-decoration: none;
      margin-top: auto;
      transition: gap .2s ease;
    }

    .floating-nav .mega .mega-featured-link:hover {
      gap: 10px;
      color: #c2185b !important;
    }

    /* Overlay avec flou de fond - version sombre */
    #megaOverlay {
      position: fixed !important;
      left: 0 !important;
      right: 0 !important;
      top: 70px !important;
      bottom: 0 !important;
      z-index: 9997 !important;
      opacity: 0 !important;
      pointer-events: none !important;
      transition: opacity .3s ease !important;
      backdrop-filter: blur(16px) saturate(180%) !important;
      -webkit-backdrop-filter: blur(16px) saturate(180%) !important;
      background: rgba(10,14,18,0.75) !important;
    }

    /* En mode scrolled, overlay couvre tout l'écran */
    body.nav-scrolled #megaOverlay {
      top: 0 !important;
    }

    #megaOverlay.active {
      opacity: 1 !important;
      pointer-events: auto !important;
    }

    /* Hero section */
    .partners-hero {
      width: 100%;
      margin: 140px auto 80px;
      text-align: center;
      padding: 0;
    }

    .partners-hero h1 {
      margin: 0 0 20px;
      font-size: clamp(40px, 5vw, 64px);
      font-weight: 700;
      letter-spacing: -0.02em;
      line-height: 1.1;
      color: #ffffff;
    }

    .partners-hero-row {
      display: flex;
      align-items: center;
      gap: 30px;
      margin: 30px auto 0;
      width: 100%;
      text-align: left;
      padding: 0 16px;
    }

    .partners-hero-img {
      width: 55%;
      max-height: 500px;
      border-radius: 16px;
      object-fit: cover;
      flex-shrink: 0;
    }

    .partners-hero-desc {
      font-size: clamp(16px, 1.8vw, 20px);
      line-height: 1.6;
      color: rgba(255,255,255,0.7);
      flex: 1;
      padding-right: 16px;
    }

    @media (max-width: 768px) {
      .partners-hero-row {
        flex-direction: column;
        gap: 24px;
      }
      .partners-hero-img {
        max-width: 100%;
        width: 100%;
      }
    }

    /* Reg bar */
    .partners-reg-bar {
      width: min(100%, 530px);
      margin: 50px 0 0 16px;
    }

    .partners-reg-card {
      height: 56px;
      min-height: 56px;
      border-radius: 12px;
      overflow: hidden;
      background: rgba(255,255,255,0.06);
      display: flex;
      align-items: center;
    }

    .partners-reg-bevel {
      align-self: stretch;
      flex: 0 0 148px;
      background: #0f172a;
      clip-path: polygon(0 0, 100% 0, 78% 100%, 0 100%);
      margin-right: -6px;
      display: flex;
      align-items: center;
      justify-content: flex-start;
      padding-left: 12px;
    }

    .partners-reg-bevel .back-btn {
      color: #ffffff;
      text-decoration: none;
      display: flex;
      align-items: center;
      line-height: 1;
      transition: opacity .2s ease;
    }

    .partners-reg-bevel .back-btn:hover {
      opacity: 0.7;
    }

    .partners-reg-title,
    .partners-hero .partners-reg-title {
      margin: 0;
      color: #ffffff;
      font-size: clamp(18px, 3.2vw, 20px) !important;
      font-weight: 900;
      letter-spacing: -0.03em;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      padding: 0 20px;
      flex: 1;
      text-align: right;
    }

    @media (max-width: 768px) {
      .partners-reg-bar {
        width: min(100%, 300px);
      }
      .partners-reg-bevel {
        flex-basis: 106px;
        margin-right: -5px;
      }
      .partners-reg-title {
        font-size: clamp(16px, 4.6vw, 19px);
        padding: 0 16px 0 14px;
      }
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
      background: #141a20;
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
      box-shadow: 0 12px 40px rgba(0,0,0,.4);
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
      color: #ffffff;
      line-height: 1.3;
    }

    .partner-card-desc {
      margin: 0;
      font-size: 16px;
      color: rgba(255,255,255,0.65);
      line-height: 1.6;
    }

    /* Year cards grid */
    .years-grid {
      display: flex;
      flex-wrap: wrap;
      justify-content: flex-start;
      gap: 16px;
      max-width: 800px;
      margin: 40px 0 0 16px;
      padding: 0;
    }

    .year-card {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 10px 24px;
      background: transparent;
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 99px;
      color: #ffffff;
      font-size: 15px;
      font-weight: 500;
      text-decoration: none;
      transition: all .25s ease;
      min-width: 0;
    }

    .year-card:hover {
      background: rgba(255,255,255,0.08);
      border-color: rgba(255,255,255,0.3);
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

    /* Message vide */
    .empty-state {
      text-align: center;
      padding: 100px 24px;
      color: rgba(255,255,255,0.5);
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
      .partners-hero {
        margin: 100px auto 60px;
      }

      .partners-hero h1 {
        font-size: clamp(32px, 8vw, 48px);
      }

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
    }
  </style>
</head>
<body>
  <?php include '../inc/navbar-modern.php'; ?>

  <main>
    <?php if ($selectedYearId && $selectedYear): ?>
      <!-- Hero section -->
      <section class="partners-hero" style="text-align:left">
        <div class="partners-reg-bar">
          <div class="partners-reg-card">
            <div class="partners-reg-bevel" aria-hidden="false">
              <a href="partenaires.php" title="Retour" class="back-btn"><svg viewBox="0 0 24 24" width="22" height="22" fill="#ffffff"><path d="M3.3 11.3l6.8-6.8c.4-.4.4-1 0-1.4s-1-.4-1.4 0l-7.8 7.8c-.4.4-.4 1 0 1.4l7.8 7.8c.2.2.5.3.7.3s.5-.1.7-.3c.4-.4.4-1 0-1.4L3.3 12.7H22c.6 0 1-.4 1-1s-.4-1-1-1H3.3z"/></svg></a>
            </div>
            <h1 class="partners-reg-title"><?= htmlspecialchars($selectedYear['title']) ?></h1>
          </div>
        </div>
      </section>

      <!-- Grid des partenaires -->
      <?php if (!empty($partners)): ?>
        <div class="partners-grid">
          <?php foreach ($partners as $partner): ?>
            <div class="partner-card" onclick="showImageModal('../files/_partners/<?= htmlspecialchars($partner['album_img']) ?>')">
              <?php if (!empty($partner['album_img'])): ?>
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
      <section class="partners-hero">
        <?php if (!empty($partners_img) || !empty($partners_desc)): ?>
          <div class="partners-hero-row">
            <?php if (!empty($partners_img)): ?>
              <img src="../files/_partners/<?= htmlspecialchars($partners_img) ?>" class="partners-hero-img" alt="Partenaires">
            <?php endif; ?>
            <?php if (!empty($partners_desc)): ?>
              <div class="partners-hero-desc"><?= $partners_desc ?></div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <!-- Reg bar -->
        <div class="partners-reg-bar">
          <div class="partners-reg-card">
            <div class="partners-reg-bevel" aria-hidden="true"></div>
            <h2 class="partners-reg-title">Éditions :</h2>
          </div>
        </div>

        <?php if (!empty($years)): ?>
          <div class="years-grid">
            <?php foreach ($years as $year): ?>
              <a href="?year_id=<?= $year['id'] ?>" class="year-card">
                <?= htmlspecialchars($year['title']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="partners-hero-desc">Aucune année disponible pour le moment.</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>

  <!-- Modal pour afficher l'image en grand -->
  <div id="imageModal" class="modal">
    <span class="modal-close" onclick="document.getElementById('imageModal').classList.remove('active')">&times;</span>
    <img id="modalImage" src="" class="modal-image" alt="">
  </div>

  <?php include '../inc/footer-modern.php'; ?>

  <script src="../js/fer-modern.js"></script>
  <script>
    function showImageModal(src) {
      document.getElementById('modalImage').src = src;
      document.getElementById('imageModal').classList.add('active');
    }

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        document.getElementById('imageModal').classList.remove('active');
      }
    });

    document.getElementById('imageModal').addEventListener('click', (e) => {
      if (e.target.id === 'imageModal') {
        document.getElementById('imageModal').classList.remove('active');
      }
    });
  </script>
</body>
</html>
