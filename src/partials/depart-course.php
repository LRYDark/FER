<?php
/**
 * depart-course.php — Le bouton qui donne le départ.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * IL N'APPARAÎT QUE QUAND IL SERT.
 *
 * Deux heures avant l'heure prévue, et jusqu'à six heures après. Le reste de
 * l'année, cette carte n'existe pas : un bouton « DONNER LE DÉPART » présent en
 * permanence sur le tableau de bord finit par être cliqué un mardi de février.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * CE QUE FAIT UN APPUI, ET POURQUOI C'EST ANNONCÉ AVANT
 *
 *   1. il enregistre l'instant réel — c'est lui qui fait foi pour les temps ;
 *   2. il recalcule toute l'édition ;
 *   3. il fait sonner tous les téléphones.
 *
 * Les trois sont dans la confirmation. Faire sonner mille téléphones sans
 * l'avoir annoncé serait une mauvaise surprise pour tout le monde.
 *
 * Attendu : $pdo, $departPeutAgir, et le traitement POST déjà fait en amont
 * (inc/dashboard.php), sans quoi un rafraîchissement redonnerait le départ.
 */

$dep = chrono_etatDepart($pdo);

/* La fenêtre d'affichage. Sans heure prévue on ne peut rien calculer : la carte
   n'apparaît que si le départ a DÉJÀ été donné — pour pouvoir l'annuler. */
$afficher = $dep['parti'];
if (!$afficher && !empty($dep['prevu'])) {
    $t = strtotime((string) $dep['prevu'] . ' UTC');
    if ($t !== false) {
        $afficher = time() >= $t - 2 * 3600 && time() <= $t + 6 * 3600;
    }
}
if (!$afficher) return;

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/** UTC → heure locale lisible. */
$local = function (?string $utc, string $format = 'H:i:s'): string {
    if (empty($utc)) return '—';
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Paris'))->format($format);
    } catch (\Throwable $e) {
        return '—';
    }
};

$restant = null;
if (!$dep['parti'] && !empty($dep['prevu'])) {
    $t = strtotime((string) $dep['prevu'] . ' UTC');
    if ($t !== false) $restant = $t - time();
}
?>

<div class="card-dashboard mb-3" id="carteDepart"
     style="border-left:4px solid var(--bs-<?= $dep['parti'] ? 'success' : 'danger' ?>)">

  <?php if (!$dep['parti']): ?>
    <!-- ════════════ Le départ n'a pas encore été donné ════════════════════ -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
      <div>
        <h2 class="h5 fw-bold mb-1">
          <i class="bi bi-flag me-2"></i>Départ prévu <?= $h($local($dep['prevu'], 'H\hi')) ?>
        </h2>
        <p class="text-muted small mb-0">
          <?php if ($restant !== null && $restant > 0): ?>
            Dans <?= (int) floor($restant / 3600) ?> h <?= (int) (($restant % 3600) / 60) ?> min.
          <?php elseif ($dep['filet_actif']): ?>
            <?php /* Le message le plus important de cette carte : des temps
                     sortent déjà, calculés sur la prévision. Si le départ a été
                     donné plus tard, ils sont tous faux — et il faut le savoir
                     maintenant, pas après la remise des prix. */ ?>
            <span class="text-warning">
              <i class="bi bi-exclamation-triangle me-1"></i>
              L'heure prévue est dépassée de plus de <?= (int) $dep['grace_min'] ?> min :
              les temps sont calculés dessus. Donnez le départ si ce n'est pas la bonne heure.
            </span>
          <?php else: ?>
            Le départ n'est pas encore donné : aucun temps n'est publié.
          <?php endif; ?>
        </p>
      </div>

      <?php if ($departPeutAgir): ?>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <button type="submit" name="action_depart" value="donner"
                    class="btn btn-danger btn-lg fw-bold px-4"
                    data-confirm="Donner le départ maintenant ?&#10;&#10;• L'heure de départ sera enregistrée&#10;• Tous les résultats seront recalculés&#10;• Une notification partira sur TOUS les téléphones">
              <i class="bi bi-play-fill me-1"></i>DONNER LE DÉPART
            </button>
          </form>

          <?php /* Le raccourci de retard, à côté du bouton : au coup de sifflet
                   on n'a pas le temps d'aller chercher un onglet de réglages. */ ?>
          <div class="btn-group">
            <?php foreach ([5, 10] as $m): ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="decalage_min" value="<?= $m ?>">
                <button type="submit" name="action_depart" value="decaler"
                        class="btn btn-outline-secondary btn-sm"
                        title="Décaler l'heure prévue de <?= $m ?> minutes">
                  +<?= $m ?> min
                </button>
              </form>
            <?php endforeach; ?>
          </div>
          <a href="setting.php?tab=course" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-pencil"></i>
          </a>
        </div>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <!-- ════════════ La course est partie ══════════════════════════════════ -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
      <div>
        <h2 class="h5 fw-bold mb-1">
          <span class="badge bg-success me-2">●</span>
          Partie à <?= $h($local($dep['reel'])) ?>
        </h2>
        <p class="text-muted small mb-0">
          Prévue <?= $h($local($dep['prevu'], 'H\hi')) ?> ·
          les temps sont calculés depuis le top réel.
        </p>
      </div>

      <?php if ($departPeutAgir): ?>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <?php /* Corriger de quelques secondes : on appuie toujours un peu
                   après le coup de sifflet, et sur un classement serré ces
                   secondes se voient. */ ?>
          <form method="post" class="d-flex gap-2 align-items-center">
            <?= csrf_field() ?>
            <input type="datetime-local" step="1" name="depart_instant"
                   class="form-control form-control-sm" style="width:auto"
                   value="<?= $h($local($dep['reel'], 'Y-m-d\TH:i:s')) ?>">
            <button type="submit" name="action_depart" value="corriger"
                    class="btn btn-outline-primary btn-sm"
                    data-confirm="Corriger l'heure de départ ? Tous les temps seront recalculés.">
              <i class="bi bi-clock me-1"></i>Corriger
            </button>
          </form>

          <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <button type="submit" name="action_depart" value="recalculer"
                    class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-arrow-clockwise me-1"></i>Recalculer
            </button>
          </form>

          <?php /* L'annulation existe parce qu'un appui accidentel quarante
                   minutes trop tôt fausserait toute la course. Elle est là,
                   discrète, et elle prévient de ce qu'elle fait. */ ?>
          <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <button type="submit" name="action_depart" value="annuler"
                    class="btn btn-outline-danger btn-sm"
                    data-confirm="Annuler le départ ?&#10;&#10;Les temps repasseront sur l'heure prévue, ou disparaîtront si le délai de grâce n'est pas écoulé. À n'utiliser qu'en cas d'appui accidentel.">
              <i class="bi bi-x-circle"></i>
            </button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!$departPeutAgir): ?>
    <p class="text-muted small mb-0 mt-2">
      <i class="bi bi-lock me-1"></i>Seule l'organisation peut donner le départ.
    </p>
  <?php endif; ?>
</div>
