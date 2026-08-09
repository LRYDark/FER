<?php
/**
 * appareils.php — Appareils de confiance.
 *
 * Révoquer, c'est renseigner `revoque_at` — jamais supprimer la ligne : on doit
 * pouvoir constater a posteriori qu'un appareil a existé et quand il a été
 * retiré. Une ligne effacée ne se raconte pas.
 *
 * La révocation d'un appareil `app` invalide immédiatement ses jetons d'API :
 * l'API du lot 5 exige `revoque_at IS NULL` à chaque appel, il n'y a donc aucun
 * cache à purger.
 */
define('FER_SESSION_COUREUR', true);
require '../../src/core/config.php';
checkMaintenance();
require_once '../../src/security/csrf.php';
require_once '../../src/auth/participant_auth.php';

pauth_require($pdo, 'appareils.php');

$moiId  = pauth_id();
$erreur = '';
$succes = '';

/* Appareil courant : identifié par le hash de son cookie, jamais par un id d'URL. */
$hashCourant = isset($_COOKIE[PAUTH_COOKIE]) && preg_match('/^[a-f0-9]{64}$/', $_COOKIE[PAUTH_COOKIE])
    ? hash('sha256', $_COOKIE[PAUTH_COOKIE]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $erreur = "Session expirée. Rechargez la page et réessayez.";
    }

    /* Révocation d'un appareil : le WHERE porte AUSSI sur participant_id —
       sans quoi un identifiant deviné révoquerait l'appareil d'un autre. */
    elseif (isset($_POST['revoquer'])) {
        $id = (int) $_POST['revoquer'];
        $st = $pdo->prepare(
            'UPDATE participant_devices SET revoque_at = NOW()
              WHERE id = ? AND participant_id = ? AND revoque_at IS NULL'
        );
        $st->execute([$id, $moiId]);

        if ($st->rowCount() === 0) {
            $erreur = "Appareil introuvable ou déjà révoqué.";
        } else {
            $succes = "Appareil révoqué.";
            // Si c'était celui-ci, la session n'a plus lieu d'être.
            $chk = $pdo->prepare('SELECT token_hash FROM participant_devices WHERE id = ?');
            $chk->execute([$id]);
            if ($hashCourant !== null && $chk->fetchColumn() === $hashCourant) {
                pauth_logout($pdo);
                header('Location: login.php');
                exit;
            }
        }
    }

    /* Tous les autres : l'appareil courant est explicitement épargné. */
    elseif (isset($_POST['revoquer_autres'])) {
        $sql    = 'UPDATE participant_devices SET revoque_at = NOW()
                    WHERE participant_id = ? AND revoque_at IS NULL';
        $params = [$moiId];
        if ($hashCourant !== null) { $sql .= ' AND token_hash <> ?'; $params[] = $hashCourant; }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $succes = $st->rowCount() . " appareil(s) révoqué(s). Cet appareil reste connecté.";
    }
}

$st = $pdo->prepare(
    'SELECT * FROM participant_devices
      WHERE participant_id = ? AND revoque_at IS NULL
      ORDER BY derniere_utilisation DESC, id DESC'
);
$st->execute([$moiId]);
$appareils = $st->fetchAll(PDO::FETCH_ASSOC);

$ecTitre    = 'Mes appareils de confiance';
$ecSurtitre = 'Accès à votre espace sans nouveau code';

$h    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$date = fn($d) => $d ? date('d/m/Y à H:i', strtotime((string) $d)) : '—';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Mes appareils</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php include __DIR__ . '/_styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_layout-haut.php'; ?>

  <?php if ($erreur !== ''): ?>
    <div class="alert is-danger"><i class="bi bi-exclamation-triangle"></i> <?= $h($erreur) ?></div>
  <?php endif; ?>
  <?php if ($succes !== ''): ?>
    <div class="alert is-ok"><i class="bi bi-check-circle"></i> <?= $h($succes) ?></div>
  <?php endif; ?>

  <?php if (!$appareils): ?>
    <section class="card ec-bloc">
      <header>
        <div class="iconwell"><i class="bi bi-phone"></i></div>
        <h2>Aucun appareil mémorisé</h2>
      </header>
      <div class="empty">
        <p>Cochez « se souvenir de moi » à la prochaine connexion pour éviter
           de redemander un code à chaque fois.</p>
      </div>
    </section>
  <?php else: ?>
    <section class="card ec-nu">
      <header>
        <div class="iconwell"><i class="bi bi-shield-check"></i></div>
        <h2>Appareils actifs</h2>
        <span class="pill no-dot"><?= count($appareils) ?></span>
      </header>

      <div class="rows">
        <?php foreach ($appareils as $a): ?>
          <?php $estCourant = $hashCourant !== null && $a['token_hash'] === $hashCourant; ?>
          <div class="row">
            <div class="iconwell">
              <i class="bi <?= $a['type'] === 'app' ? 'bi-phone' : 'bi-window' ?>"></i>
            </div>
            <div class="grow">
              <div class="title">
                <?= $h($a['libelle'] ?: ($a['type'] === 'app' ? 'Application mobile' : 'Navigateur')) ?>
                <?php if ($estCourant): ?>
                  <span class="pill is-ok">appareil actuel</span>
                <?php endif; ?>
              </div>
              <div class="sub">
                <?php if (!empty($a['plateforme'])): ?><?= $h($a['plateforme']) ?> · <?php endif; ?>
                Dernière utilisation : <?= $h($date($a['derniere_utilisation'])) ?>
              </div>
              <div class="sub">
                <?= $a['expires_at'] === null
                      ? "Sans expiration — l'application reste connectée"
                      : 'Expire le ' . $h($date($a['expires_at'])) ?>
              </div>
            </div>
            <form method="post" style="margin:0"
                  data-confirm="<?= $estCourant
                      ? 'Révoquer CET appareil vous déconnectera immédiatement. Continuer ?'
                      : 'Révoquer cet appareil ?' ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="revoquer" value="<?= (int) $a['id'] ?>">
              <button class="btn btn-danger" type="submit">
                <i class="bi bi-x-octagon"></i> Révoquer
              </button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (count($appareils) > 1): ?>
        <form method="post" class="row-actions"
              data-confirm="Révoquer tous les autres appareils ? Cet appareil restera connecté.">
          <?= csrf_field() ?>
          <button class="btn" type="submit" name="revoquer_autres" value="1">
            <i class="bi bi-shield-x"></i> Révoquer tous les autres
          </button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <div class="alert">
    <i class="bi bi-info-circle"></i>
    Révoquer un appareil coupe aussi, immédiatement, l'accès de l'application mobile
    installée dessus. Un appareil révoqué reste connu de l'organisation à des fins de
    traçabilité.
  </div>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
