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

/* ⚠️ LE RETRAIT PASSE PAR LE SERVEUR, PLUS PAR `localStorage`.
 *
 * Il était retenu par le navigateur : un message écarté sur l'ordinateur
 * réapparaissait sur le téléphone, et l'inverse. Il est désormais porté par le
 * compte, dans `participant_notifications_masquees`, exactement comme dans
 * l'application.
 *
 * ⚠️ UN ÉPINGLÉ NE SE RETIRE PAS : le contrôle est refait ICI et pas seulement
 * dans l'affichage. Une requête forgée ne doit pas pouvoir masquer ce que
 * l'organisation a explicitement désigné comme « à relire ». */
$ecMsgRetire = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()
    && isset($_POST['masquer'])) {
    $idMasque = (int) $_POST['masquer'];
    try {
        $st = $pdo->prepare('SELECT epingle FROM app_notifications WHERE id = ? LIMIT 1');
        $st->execute([$idMasque]);
        $ep = $st->fetchColumn();
        if ($ep === false) {
            $ecMsgRetire = 'Message introuvable.';
        } elseif ((int) $ep === 1) {
            $ecMsgRetire = "Ce message est épinglé par l'organisation : il ne peut pas être retiré.";
        } else {
            $pdo->prepare('INSERT IGNORE INTO participant_notifications_masquees
                             (participant_id, notification_id) VALUES (?, ?)')
                ->execute([pauth_id(), $idMasque]);
            $ecMsgRetire = 'Message retiré de votre boîte.';
        }
    } catch (\Throwable $e) {
        error_log('[EC] masquage message : ' . $e->getMessage());
        $ecMsgRetire = "Le retrait n'a pas pu être enregistré.";
    }
}

$moi   = $_SESSION[PAUTH_SESSION_KEY];
$annee = (int) (course_lire($pdo)['annee'] ?? 0);

/* ── Lu / non lu ──────────────────────────────────────────────────────────────
 * ⚠️ LIRE N'EST PAS MASQUER. Le message lu reste dans la boîte : c'est la
 * pastille du menu qui descend, pas le message qui disparaît. Masquer garde
 * son bouton, à part, et ses propres règles (un épinglé ne se masque pas).
 *
 * Le marquage passe par le serveur, comme le masquage : lu sur le téléphone,
 * lu sur le navigateur. */
$ecLuDispo = notif_luesDisponible($pdo);

if ($ecLuDispo && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (isset($_POST['lu'])) {
        notif_marquerLu($pdo, (int) pauth_id(), [(int) $_POST['lu']]);
    } elseif (isset($_POST['non_lu'])) {
        notif_marquerNonLu($pdo, (int) pauth_id(), (int) $_POST['non_lu']);
    } elseif (isset($_POST['tout_lu'])) {
        notif_marquerLu($pdo, (int) pauth_id(),
            array_map(fn($m) => (int) $m['id'], notif_boiteCoureur($pdo, (int) pauth_id(), $annee > 0 ? $annee : null)));
    }
    /* Redirection après écriture : sans elle, un rafraîchissement rejouait le
       marquage, et le retour arrière du navigateur affichait un avertissement
       de renvoi de formulaire pour une action déjà faite. */
    if (isset($_POST['lu']) || isset($_POST['non_lu']) || isset($_POST['tout_lu'])) {
        $q = isset($_GET['f']) ? '?f=' . urlencode((string) $_GET['f']) : '';
        header('Location: messages.php' . $q);
        exit;
    }
}

/* Une seule définition de « ce que voit le coureur » : la boîte et la pastille
   du menu comptent la même chose (src/content/notifications.php). */
$messages = notif_boiteCoureur($pdo, (int) pauth_id(), $annee > 0 ? $annee : null);
$ecLues   = notif_luesIds($pdo, (int) pauth_id());

$ecNonLusTotal = 0;
foreach ($messages as $m) if (!in_array((int) $m['id'], $ecLues, true)) $ecNonLusTotal++;

/* Filtre d'affichage. Il ne touche PAS aux données : il ne fait que réduire la
   liste, et le compteur de chaque onglet reste calculé sur la boîte entière. */
$ecFiltre = $_GET['f'] ?? 'tous';
if (!in_array($ecFiltre, ['tous', 'non-lus', 'lus'], true)) $ecFiltre = 'tous';
$ecAffiches = array_values(array_filter($messages, function ($m) use ($ecFiltre, $ecLues) {
    $lu = in_array((int) $m['id'], $ecLues, true);
    return $ecFiltre === 'tous' || ($ecFiltre === 'lus' ? $lu : !$lu);
}));

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
    <div class="card ec-bloc">
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
  <?php /* Migration non jouée : on le DIT, au lieu de laisser des boutons qui
           ne font rien. Le reste de la page fonctionne normalement. */ ?>
  <?php if (!$ecLuDispo && $messages): ?>
    <div class="alert is-warn">
      <i class="bi bi-exclamation-triangle"></i>
      Le suivi des messages lus n'est pas encore actif sur ce site&nbsp;: la mise
      à jour de la base de données n'a pas été appliquée.
    </div>
  <?php endif; ?>

  <?php if ($messages && $ecLuDispo): ?>
    <?php /* ── Filtres ──────────────────────────────────────────────────────
             Trois liens, pas un menu déroulant : avec trois choix dont on voit
             le compte, un déroulant demanderait deux gestes pour lire ce qui
             tient sur une ligne. Chaque compteur porte sur la boîte entière,
             jamais sur ce qui est affiché — sinon « Non lus » afficherait
             toujours zéro une fois le filtre appliqué. */ ?>
    <div class="ec-filtres">
      <?php
      $ecOnglets = [
          'tous'    => ['Tous',     count($messages)],
          'non-lus' => ['Non lus',  $ecNonLusTotal],
          'lus'     => ['Lus',      count($messages) - $ecNonLusTotal],
      ];
      foreach ($ecOnglets as $cle => [$libelle, $combien]):
      ?>
        <a class="ec-filtre<?= $ecFiltre === $cle ? ' is-active' : '' ?>"
           href="messages.php<?= $cle === 'tous' ? '' : '?f=' . $cle ?>">
          <?= $h($libelle) ?><span class="n"><?= (int) $combien ?></span>
        </a>
      <?php endforeach; ?>

      <?php if ($ecNonLusTotal > 0): ?>
        <form method="post" class="ec-filtres-action">
          <?= csrf_field() ?>
          <button type="submit" name="tout_lu" value="1" class="ec-filtre-tout">
            <i class="bi bi-check2-all"></i> Tout marquer comme lu
          </button>
        </form>
      <?php endif; ?>
    </div>

    <?php if (!$ecAffiches): ?>
      <p class="ec-vide-filtre">
        <?= $ecFiltre === 'non-lus' ? 'Tous vos messages sont lus.' : "Vous n'avez encore lu aucun message." ?>
      </p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($ecAffiches): ?>
    <?php /* ⚠️ AUCUN CADRE. Les messages se posent directement sur le fond du
             panneau : une carte autour d'une liste ajoute une boîte dans une
             boîte, et la page en portait déjà une. */ ?>
    <ul class="ec-messages" id="ecMessages">
        <?php foreach ($ecAffiches as $m): ?>
          <?php
            [$icone, $classe] = $ecTypeInfo($m['type']);
            $ecLu = in_array((int) $m['id'], $ecLues, true);
          ?>
          <li class="ec-msg <?= $h($classe) ?><?= $ecLu ? '' : ' is-nonlu' ?>" data-id="<?= (int) $m['id'] ?>"
              data-epingle="<?= !empty($m['epingle']) ? '1' : '0' ?>">
            <i class="bi <?= $h($icone) ?> ec-msg-ico"></i>
            <div class="ec-msg-corps">
              <div class="ec-msg-tete">
                <?php if (!empty($m['epingle'])): ?>
                  <i class="bi bi-pin-angle-fill" title="Épinglé"></i>
                <?php endif; ?>
                <strong><?= $h($m['titre']) ?></strong>
                <?php if (!$ecLu): ?><span class="ec-msg-neuf">Non lu</span><?php endif; ?>
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

              <?php /* Bascule lu / non lu, DANS le corps du message et non
                       dans un coin : c'est une action sur ce qu'on vient de
                       lire, pas un réglage de la liste. Un épinglé se marque
                       lu comme les autres — c'est le retrait qui lui est
                       interdit, pas la lecture. */ ?>
              <form method="post" class="ec-msg-lu"<?= $ecLuDispo ? '' : ' hidden' ?>>
                <?= csrf_field() ?>
                <?php if ($ecLu): ?>
                  <button type="submit" name="non_lu" value="<?= (int) $m['id'] ?>">
                    <i class="bi bi-envelope"></i> Marquer comme non lu
                  </button>
                <?php else: ?>
                  <button type="submit" name="lu" value="<?= (int) $m['id'] ?>">
                    <i class="bi bi-check2"></i> Marquer comme lu
                  </button>
                <?php endif; ?>
              </form>
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

  <?php endif; ?>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
