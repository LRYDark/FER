<?php
/**
 * messages.php — « Messages de l'organisation », côté web.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * CETTE PAGE MANQUAIT, ET C'ÉTAIT UN ÉCART DIFFICILE À JUSTIFIER.
 *
 * L'application mobile a un onglet « Messages » depuis le début ; l'espace
 * coureur du site, non. Les mêmes annonces — rendez-vous, parking, retrait des
 * dossards — n'étaient donc lisibles que par ceux qui avaient installé
 * l'application. Un coureur sans smartphone, ou qui préfère son navigateur, ne
 * voyait rien du tout.
 *
 * La source est la MÊME fonction que celle de l'API mobile,
 * `notif_pourCoureur()` : il n'y a pas deux versions de la vérité, et un
 * message publié apparaît des deux côtés sans rien avoir à synchroniser.
 *
 * ⚠️ LES ÉPINGLÉS D'ABORD, comme dans l'application. C'est déjà le tri de
 * `notif_pourCoureur()` ; on ne le refait pas ici, sous peine de voir les deux
 * ordres diverger au premier changement.
 */
define('FER_SESSION_COUREUR', true);
require '../../src/core/config.php';
checkMaintenance();
require_once '../../src/security/csrf.php';
require_once '../../src/auth/participant_auth.php';
require_once '../../src/content/notifications.php';
require_once '../../src/content/course.php';

pauth_require($pdo, 'messages.php');

$moi     = $_SESSION[PAUTH_SESSION_KEY];
$annee   = (int) (course_lire($pdo)['annee'] ?? 0);
$messages = notif_pourCoureur($pdo, $annee > 0 ? $annee : null);

$ecTitre    = "Messages de l'organisation";
$ecSurtitre = trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')) ?: ($moi['email'] ?? '');

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/** Icône et couleur par type — mêmes conventions que l'application. */
$ecTypeInfo = static function (?string $type): array {
    return match (strtolower((string) $type)) {
        'alerte', 'urgent'  => ['bi-exclamation-triangle-fill', 'is-danger'],
        'avertissement'     => ['bi-exclamation-circle-fill',    'is-warn'],
        'succes'            => ['bi-check-circle-fill',          'is-ok'],
        default             => ['bi-info-circle-fill',           ''],
    };
};

/** « dimanche 5 juillet 2026 à 09 h 30 ». */
$ecQuand = static function (?string $iso): string {
    if (!$iso) return '';
    $d = new DateTimeImmutable($iso);
    $jours = ['lundi','mardi','mercredi','jeudi','vendredi','samedi','dimanche'];
    $mois  = ['janvier','février','mars','avril','mai','juin',
              'juillet','août','septembre','octobre','novembre','décembre'];
    return $jours[(int) $d->format('N') - 1] . ' ' . (int) $d->format('j') . ' '
         . $mois[(int) $d->format('n') - 1] . ' ' . $d->format('Y')
         . ' à ' . $d->format('H\\ \h i');
};
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Messages</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php include __DIR__ . '/_styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_layout-haut.php'; ?>

  <?php if (!$messages): ?>
    <div class="card">
      <header>
        <div class="iconwell"><i class="bi bi-mailbox"></i></div>
        <h2>Aucun message</h2>
      </header>
      <div class="empty">
        <p>L'organisation n'a rien publié pour le moment. Les informations
           pratiques — rendez-vous, parking, retrait des dossards — arriveront
           ici, et sur l'application si vous l'avez installée.</p>
      </div>
    </div>
  <?php endif; ?>

  <?php /* ⚠️ PAS UNE CARTE PAR MESSAGE.
           Une carte encadre un OBJET ; une boîte de réception est une LISTE.
           Un cadre par message donnait des blocs de 200 px pour deux lignes de
           texte, et deux messages remplissaient l'écran — sur une page dont le
           seul intérêt est de voir d'un coup d'œil ce qu'on a reçu.

           Liste plate, séparateurs fins : le même parti que l'application. */ ?>
  <?php if ($messages): ?>
    <?php /* ⚠️ AUCUN CADRE. Les messages se posent directement sur le fond du
             panneau : une carte autour d'une liste ajoute une boîte dans une
             boîte, et la page en portait déjà une. */ ?>
    <?php /* Le vide affiché quand TOUT a été supprimé : le même que celui
             d'une boîte qui n'a jamais rien reçu. Il n'y a pas lieu de
             distinguer les deux — dans les deux cas, il n'y a rien à lire. */ ?>
    <div class="card ec-nu" id="ecMsgVide" hidden>
      <header>
        <div class="iconwell"><i class="bi bi-mailbox"></i></div>
        <h2>Aucun message</h2>
      </header>
      <div class="empty">
        <p>Votre boîte est vide.</p>
      </div>
    </div>

    <ul class="ec-messages" id="ecMessages">
        <?php foreach ($messages as $m): ?>
          <?php [$icone, $classe] = $ecTypeInfo($m['type']); ?>
          <li class="ec-msg <?= $h($classe) ?>" data-id="<?= (int) $m['id'] ?>"
              data-epingle="<?= !empty($m['epingle']) ? '1' : '0' ?>">
            <i class="bi <?= $h($icone) ?> ec-msg-ico"></i>
            <div class="ec-msg-corps">
              <div class="ec-msg-tete">
                <?php if (!empty($m['epingle'])): ?>
                  <i class="bi bi-pin-angle-fill" title="Épinglé"></i>
                <?php endif; ?>
                <strong><?= $h($m['titre']) ?></strong>
                <?php if (!empty($m['publie_at'])): ?>
                  <span class="ec-msg-date"><?= $h($ecQuand($m['publie_at'])) ?></span>
                <?php endif; ?>
              </div>
              <p><?= $h($m['message']) ?></p>
              <?php if (!empty($m['expire_at'])): ?>
                <p class="ec-msg-exp">
                  <i class="bi bi-clock-history"></i>
                  Ne sera plus affiché après le <?= $h($ecQuand($m['expire_at'])) ?>.
                </p>
              <?php endif; ?>
            </div>

            <?php /* ⚠️ LES ÉPINGLÉS NE SE RETIRENT PAS, comme dans
                     l'application : ce sont les informations qu'on relit la
                     veille, et les masquer viderait la page où l'on vient
                     justement les rechercher. */ ?>
            <?php if (empty($m['epingle'])): ?>
              <button type="button" class="ec-msg-x" title="Supprimer ce message"
                      aria-label="Supprimer ce message">
                <i class="bi bi-x-lg"></i>
              </button>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
    </ul>

    <?php /* ═══════════════════════════════════════════════════════════════
             MASQUÉ DANS CE NAVIGATEUR, PAS SUPPRIMÉ SUR LE SERVEUR.

             L'organisation publie pour tout le monde : un coureur retire une
             annonce de SA boîte, il ne l'efface pour personne. Le serveur
             n'expose d'ailleurs aucune suppression — une consigne de sécurité
             effaçable par son destinataire n'en serait plus une.

             ⚠️ C'EST DONC PROPRE À CE NAVIGATEUR, exactement comme la
             suppression de l'application est propre à ce téléphone. Un coureur
             qui supprime un message sur son ordinateur le reverra sur son
             mobile. Faire mieux demanderait une table de masquage par compte
             côté serveur ; ce n'est pas fait.

             ⚠️ IL N'Y A PLUS DE « TOUT RÉAFFICHER ». Supprimer est définitif,
             c'est ce qui a été demandé — et c'est cohérent avec le mot employé
             sur le bouton. Le garde-fou est donc UNIQUEMENT la confirmation :
             elle est la seule chose qui sépare un clic d'une perte, et elle ne
             doit jamais être retirée.
             ═══════════════════════════════════════════════════════════════ */ ?>
    <script<?= isset($GLOBALS['csp_nonce']) ? ' nonce="' . htmlspecialchars($GLOBALS['csp_nonce']) . '"' : '' ?>>
    (function () {
      var CLE   = 'fer_messages_masques';
      var liste = document.getElementById('ecMessages');
      var vide  = document.getElementById('ecMsgVide');
      if (!liste) return;

      function lus() {
        try { return JSON.parse(localStorage.getItem(CLE) || '[]'); }
        catch (e) { return []; }
      }
      function ecrire(v) {
        try { localStorage.setItem(CLE, JSON.stringify(v)); } catch (e) {}
      }
      function majVide() {
        var reste = liste.querySelectorAll('.ec-msg:not([hidden])').length;
        if (vide) vide.hidden = reste > 0;
        liste.hidden = reste === 0;
      }

      var masques = lus();
      Array.prototype.forEach.call(liste.querySelectorAll('.ec-msg'), function (li) {
        if (masques.indexOf(parseInt(li.dataset.id, 10)) !== -1) li.hidden = true;
      });
      majVide();

      /* ⚠️ CONFIRMATION AVANT DE RETIRER.
         La croix est petite et voisine du texte : un clic de trop faisait
         disparaître un message sans le moindre recours visible. Sur mobile, le
         balayage a son bandeau « Annuler » ; ici, il fallait l'équivalent.

         La question dit CE QUI SE PASSE — retiré de VOTRE boîte, pas supprimé —
         parce que c'est la seule chose qui compte pour décider. */
      liste.addEventListener('click', function (e) {
        var bouton = e.target.closest('.ec-msg-x');
        if (!bouton) return;
        var li = bouton.closest('.ec-msg');
        var titre = (li.querySelector('.ec-msg-tete strong') || {}).textContent || '';

        var ok = window.confirm(
          'Supprimer « ' + titre.trim() + ' » ?\n\n'
          + "Ce message disparaîtra définitivement de votre boîte. Il n'est pas "
          + 'supprimé pour les autres coureurs.'
        );
        if (!ok) return;

        var id = parseInt(li.dataset.id, 10);
        var v  = lus();
        if (v.indexOf(id) === -1) v.push(id);
        ecrire(v);
        li.hidden = true;
        majVide();
      });

    })();
    </script>
  <?php endif; ?>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
