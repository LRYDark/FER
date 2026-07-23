<?php
/**
 * FAQ publique — Forbach en Rose
 *
 * Affiche les questions/réponses actives de la table chatbot_faq (gérées dans
 * l'admin : Contenu → Assistant / FAQ). Les mêmes réponses sont servies par le
 * chatbot via mots-clés ; cette page est le point d'entrée « tout lire ».
 */
require '../src/core/config.php';
require_once '../src/content/tracker.php';
require_once '../src/content/chatbot-engine.php'; // chatbot_linkify (URLs propres)
trackPageVisit();
checkMaintenance();
require __DIR__ . '/../src/partials/navbar-data.php';

$faqs = [];
try {
    $faqs = $pdo->query('SELECT question, answer FROM chatbot_faq WHERE active = 1 ORDER BY position, id')
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $faqs = []; // table absente avant migration
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Questions fréquentes</title>
  <link rel="stylesheet" href="../css/fer-modern.css">
  <link rel="stylesheet" href="../css/faq.css">
<?php include __DIR__ . '/../src/content/theme.php'; ?>
</head>
<body>
  <?php include __DIR__ . '/../src/partials/preloader.php'; ?>
  <?php include __DIR__ . '/../src/partials/navbar-modern.php'; ?>

  <main>
    <section class="faq-hero" aria-label="Titre de la page">
      <h1 class="faq-hero-title">Questions fréquentes</h1>
      <p class="faq-hero-sub">Tout ce qu'il faut savoir avant le jour J 🎀</p>
    </section>

    <div class="faq-wrap">
      <?php if ($faqs): ?>
        <div class="faq-search-bar">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="search" id="faqSearch" placeholder="Rechercher une question…" aria-label="Rechercher dans la FAQ" autocomplete="off">
        </div>

        <?php foreach ($faqs as $f): ?>
        <details class="faq-entry">
          <summary>
            <span class="faq-entry-q"><?= htmlspecialchars($f['question']) ?></span>
            <span class="faq-entry-chevron" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </span>
          </summary>
          <div class="faq-entry-a"><?= chatbot_linkify($f['answer']) ?></div>
        </details>
        <?php endforeach; ?>
        <p class="faq-empty" id="faqNoResult" hidden>Aucune question ne correspond à votre recherche — posez-la à l'assistant ! 👇</p>
      <?php else: ?>
        <p class="faq-empty">La FAQ arrive très bientôt — en attendant, notre assistant répond à vos questions !</p>
      <?php endif; ?>

      <div class="faq-cta">
        <p>Vous n'avez pas trouvé votre réponse ?</p>
        <button type="button" class="faq-cta-btn footer-contact-btn">💬 Poser la question à l'assistant</button>
      </div>
    </div>
  </main>

  <?php include __DIR__ . '/../src/partials/footer-modern.php'; ?>

  <script src="../js/fer-modern.js"></script>
  <script nonce="<?= htmlspecialchars($GLOBALS['csp_nonce'] ?? '') ?>">
  /* Recherche instantanée dans la FAQ (question + réponse, accents ignorés) */
  (function () {
    var input = document.getElementById('faqSearch');
    if (!input) return;
    var entries = Array.prototype.slice.call(document.querySelectorAll('.faq-entry'));
    var noResult = document.getElementById('faqNoResult');
    function norm(s) {
      return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    var index = entries.map(function (el) { return norm(el.textContent || ''); });
    input.addEventListener('input', function () {
      var q = norm(input.value.trim());
      var shown = 0;
      entries.forEach(function (el, i) {
        var match = q === '' || index[i].indexOf(q) !== -1;
        el.hidden = !match;
        if (q === '') {
          el.open = false;           // recherche vidée → tout refermé
        } else if (match) {
          shown++;
          el.open = shown <= 3;      // les 3 premiers résultats s'ouvrent, le reste reste fermé
        } else {
          el.open = false;
        }
      });
      if (noResult) noResult.hidden = q === '' || shown > 0;
    });
  })();
  </script>
</body>
</html>
