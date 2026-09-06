<?php
/**
 * mobile-nav.php — Menu UNIQUE des petits écrans (< 861 px).
 *
 * POURQUOI CE FICHIER : sous 861 px, la coquille .oc-* montrait DEUX
 * navigations à la fois — les onglets de section qui défilaient
 * horizontalement dans la barre du haut, et le sous-menu de la section
 * courante replié en tiroir derrière le burger. Résultat : pour atteindre
 * « Albums photos » depuis le tableau de bord il fallait deviner qu'il
 * existait un onglet « Site public » caché à droite d'un défilement sans
 * indice, l'atteindre, puis rouvrir le burger. Les sections qu'on ne voit
 * pas n'existent pas.
 *
 * Ici, tout tient dans un seul tiroir : les entrées sans groupe en haut,
 * puis chaque section en accordéon. Un seul geste, toute la navigation.
 *
 * Le tiroir est partagé par l'administration (navbar-admin.php) et par
 * l'espace coureur (public/espace-coureur/_layout-haut.php), dont le menu
 * est plat : il ne pose alors que $mnTop et aucun groupe.
 *
 * Variables à poser AVANT l'include :
 *   $mnTitre  : titre du tiroir (défaut « Menu »).
 *   $mnTop    : entrées hors groupe — [['href','label','svg'|'bi','active','badge'], …]
 *   $mnGroups : sections dépliables — [['label','open','items' => [entrées]], …]
 *   $mnPied   : HTML libre posé au bas du tiroir (déjà échappé par l'appelant).
 *
 * Le comportement (ouverture, accordéon, fermeture) voyage AVEC le markup,
 * plus bas : l'espace coureur ne charge pas admin-footer.php, et un tiroir
 * dont le script vit ailleurs est un tiroir qui ne s'ouvre qu'à moitié des
 * endroits. Habillage : css/admin-shell.css (chargé par les deux).
 */
$mnTop    = $mnTop    ?? [];
$mnGroups = $mnGroups ?? [];

/** Icône d'une entrée : SVG en ligne (admin) ou Bootstrap Icons (coureur). */
$mnIcone = static function (array $e): string {
    if (!empty($e['svg'])) return '<svg viewBox="0 0 24 24">' . $e['svg'] . '</svg>';
    if (!empty($e['bi']))  return '<i class="bi ' . htmlspecialchars((string) $e['bi'], ENT_QUOTES, 'UTF-8') . '"></i>';
    return '';
};

/** Une entrée du tiroir. */
$mnEntree = static function (array $e) use ($mnIcone): string {
    $h  = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $out  = '<a class="mn-item' . (!empty($e['active']) ? ' is-active' : '') . '" href="' . $h($e['href']) . '">';
    $out .= $mnIcone($e);
    $out .= '<span class="lab">' . $h($e['label']) . '</span>';
    if (isset($e['badge'])) {
        // Une pastille à « 0 » attire l'œil pour annoncer qu'il n'y a rien :
        // elle existe toujours pour le rafraîchissement live, mais masquée.
        $n = (int) $e['badge'];
        $cls = 'mn-badge' . ($e['badgeClass'] ?? '') . ($n === 0 ? ' d-none' : '');
        $out .= '<span class="' . $h($cls) . '">' . $n . '</span>';
    }
    return $out . '</a>';
};
?>
<nav class="oc-mobilenav" id="ocMobileNav" aria-label="Menu principal">
  <div class="mn-head">
    <span class="mn-title"><?= htmlspecialchars($mnTitre ?? 'Menu', ENT_QUOTES, 'UTF-8') ?></span>
    <button type="button" class="mn-close" id="ocMobileNavClose" aria-label="Fermer le menu">
      <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>

  <div class="mn-scroll">
    <?php foreach ($mnTop as $e): ?>
      <?= $mnEntree($e) ?>
    <?php endforeach; ?>

    <?php foreach ($mnGroups as $g): ?>
      <?php $ouvert = !empty($g['open']); ?>
      <div class="mn-group<?= $ouvert ? ' is-open' : '' ?>">
        <button type="button" class="mn-grouphead" aria-expanded="<?= $ouvert ? 'true' : 'false' ?>">
          <span class="lab"><?= htmlspecialchars((string) $g['label'], ENT_QUOTES, 'UTF-8') ?></span>
          <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="mn-groupitems">
          <div class="mn-groupinner">
            <?php foreach ($g['items'] as $e): ?>
              <?= $mnEntree($e) ?>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if (!empty($mnPied)): ?>
      <div class="mn-foot"><?= $mnPied ?></div>
    <?php endif; ?>
  </div>
</nav>

<script<?= isset($GLOBALS['csp_nonce']) ? ' nonce="' . htmlspecialchars($GLOBALS['csp_nonce']) . '"' : '' ?>>
(function () {
  /* ⚠️ APRÈS LE CHARGEMENT DU DOCUMENT, ET C'EST INDISPENSABLE. Le voile de
     fond (#ocOverlay) est rendu PLUS BAS que ce fragment — dans le contenu,
     pour l'administration. Exécuté sur-le-champ, getElementById l'aurait
     trouvé absent : le tiroir se serait ouvert sans voile, et un clic à côté
     ne l'aurait jamais refermé. */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', demarrer);
  } else {
    demarrer();
  }

  function demarrer() {
    var nav = document.getElementById('ocMobileNav');
    if (!nav) return;
    var burger = document.getElementById('ocBurger');
    var fermer = document.getElementById('ocMobileNavClose');
    var voile  = document.getElementById('ocOverlay');

    function ouvrir() {
      nav.classList.add('open');
      if (voile) voile.classList.add('show');
      document.body.classList.add('oc-nav-open');
    }
    function fermerNav() {
      nav.classList.remove('open');
      if (voile) voile.classList.remove('show');
      document.body.classList.remove('oc-nav-open');
    }

    if (burger) burger.addEventListener('click', function (e) { e.stopPropagation(); ouvrir(); });
    if (fermer) fermer.addEventListener('click', fermerNav);
    if (voile)  voile.addEventListener('click', fermerNav);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fermerNav(); });

    /* Accordéon : ouvrir une section ferme les autres. Deux sections ouvertes
       sur un écran de téléphone, c'est déjà une liste qu'on parcourt au
       défilement — exactement ce qu'on cherchait à éviter. */
    nav.querySelectorAll('.mn-grouphead').forEach(function (bouton) {
      bouton.addEventListener('click', function () {
        var groupe = bouton.parentElement;
        var etait  = groupe.classList.contains('is-open');
        nav.querySelectorAll('.mn-group.is-open').forEach(function (g) {
          g.classList.remove('is-open');
          var b = g.querySelector('.mn-grouphead');
          if (b) b.setAttribute('aria-expanded', 'false');
        });
        if (!etait) {
          groupe.classList.add('is-open');
          bouton.setAttribute('aria-expanded', 'true');
        }
      });
    });

    /* La section ouverte au chargement peut être hors champ si elle est basse
       dans la liste : on l'amène sous les yeux à la première ouverture. */
    var actif = nav.querySelector('.mn-item.is-active');
    if (actif && burger) {
      burger.addEventListener('click', function () {
        if (actif.getBoundingClientRect().top > window.innerHeight - 40) {
          actif.scrollIntoView({ block: 'center' });
        }
      }, { once: true });
    }
  }
})();
</script>
