<?php
/**
 * Politique de confidentialité — Forbach en Rose
 * Contenu éditable dans l'admin : Réglages → Pages légales (setting.legal_privacy,
 * HTML nettoyé par sanitizeHtml à l'enregistrement).
 */
require '../src/core/config.php';
require_once '../src/content/tracker.php';
trackPageVisit();
checkMaintenance();
require __DIR__ . '/../src/partials/navbar-data.php';

$legalContent = trim((string)($data['legal_privacy'] ?? ''));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, follow">
  <title>Politique de confidentialité</title>
  <link rel="stylesheet" href="../css/fer-modern.css">
  <link rel="stylesheet" href="../css/legal.css">
<?php include __DIR__ . '/../src/content/theme.php'; ?>
</head>
<body>
  <?php include __DIR__ . '/../src/partials/preloader.php'; ?>
  <?php include __DIR__ . '/../src/partials/navbar-modern.php'; ?>

  <main>
    <section class="legal-hero" aria-label="Titre de la page">
      <h1 class="legal-hero-title">Politique de confidentialité</h1>
    </section>

    <div class="legal-wrap">
      <div class="legal-card">
        <?php if ($legalContent !== ''): ?>
          <?= $legalContent /* HTML nettoyé (sanitizeHtml) à l'enregistrement admin */ ?>
        <?php else: ?>
          <p>La politique de confidentialité sera publiée prochainement.</p>
        <?php endif; ?>
      </div>
    </div>
  </main>

  <?php include __DIR__ . '/../src/partials/footer-modern.php'; ?>

  <script src="../js/fer-modern.js"></script>
</body>
</html>
