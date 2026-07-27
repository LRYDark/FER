<?php
/**
 * rgpd.php — Conservation et effacement des données (administration, lot 7).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * RÉSERVÉ AUX SUPER-ADMINISTRATEURS. Effacer définitivement des données n'est
 * pas une opération de gestion courante : c'est irréversible, et il faut pouvoir
 * dire QUI l'a déclenchée.
 *
 * L'écran fait délibérément DEUX choses avant de proposer la suppression :
 *   1. il montre ce qui SERAIT effacé, sans rien toucher (simulation) ;
 *   2. il rappelle, en toutes lettres, ce qui n'est JAMAIS purgé.
 * Un bouton « purger » seul, sans ces deux garde-fous, finirait par être cliqué
 * un jour de fatigue par quelqu'un qui ne sait pas ce qu'il déclenche.
 */
require '../src/core/config.php';
require_once __DIR__ . '/../src/security/csrf.php';
require __DIR__ . '/../src/partials/navbar-data.php';
require_once __DIR__ . '/../src/content/purges.php';

requirePage('dashboard');
if (currentRole() !== 'admin') {
    http_response_code(403);
    die('Action non autorisée (réservé aux super-administrateurs).');
}
$role = currentRole();

$erreur    = '';
$succes    = '';
$rapport   = null;
$simulation = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $erreur = 'Session expirée. Rechargez la page et réessayez.';
    } elseif (isset($_POST['simuler'])) {
        $rapport = purge_run($pdo, true);
    } elseif (isset($_POST['purger'])) {
        // Confirmation textuelle : la même exigence que la suppression d'un
        // compte coureur. Un clic ne suffit pas pour une action irréversible.
        if (trim((string) ($_POST['confirmation'] ?? '')) !== 'PURGER') {
            $erreur = 'Saisissez PURGER en majuscules pour confirmer.';
            $rapport = purge_run($pdo, true);
        } else {
            $rapport    = purge_run($pdo, false);
            $simulation = false;
            $succes     = $rapport['total'] . ' ligne(s) effacée(s) définitivement.';
            logContentAction($pdo, 'rgpd', 'delete', null,
                'Purge manuelle : ' . $rapport['total'] . ' ligne(s)', 'purge');
        }
    } elseif (isset($_POST['enregistrer_durees'])) {
        $champs = ['auth_codes_conservation_jours' => [1, 3650],
                   'traces_gps_conservation_jours' => [1, 3650],
                   'devices_revoques_jours'        => [1, 3650],
                   'transferts_clos_jours'         => [1, 3650]];
        $sets = []; $args = [];
        foreach ($champs as $col => [$min, $max]) {
            $v = (int) ($_POST[$col] ?? 0);
            // Bornage strict : une durée à 0 signifierait « effacer tout de
            // suite », ce que personne ne veut saisir volontairement.
            if ($v < $min || $v > $max) { $erreur = "Durée invalide pour « $col » ($min à $max jours)."; break; }
            $sets[] = "`$col` = ?"; $args[] = $v;
        }
        if ($erreur === '' && $sets) {
            $args[] = 1;
            $pdo->prepare('UPDATE setting SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
            $succes = 'Durées de conservation enregistrées.';
            logContentAction($pdo, 'rgpd', 'update', null, 'Durées de conservation modifiées', 'purge');
        }
    }
}

$durees = purge_settings($pdo);
if ($rapport === null) $rapport = purge_run($pdo, true);   // état à l'ouverture

/* Volumes actuels, pour donner l'échelle. */
$volumes = [];
foreach ([
    'participants'            => 'Comptes coureurs',
    'participant_devices'     => 'Appareils',
    'participant_auth_codes'  => 'Codes de connexion',
    'registration_transfers'  => 'Demandes de transfert',
    'traces_gps'              => 'Traces GPS',
    'resultats'               => 'Résultats',
] as $table => $lib) {
    try { $volumes[$lib] = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn(); }
    catch (\Throwable $e) { $volumes[$lib] = null; }
}

$pageTitle    = 'Données personnelles';
$pageSubtitle = 'Données personnelles';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Données personnelles</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>

  <div class="page-header">
    <h1 class="mb-2 fw-bold"><i class="bi bi-shield-lock me-2"></i>Données personnelles</h1>
    <p class="text-muted mb-0">Durées de conservation et effacement des données périmées. Les inscriptions et les archives ne sont jamais touchées.</p>
  </div>

<?php if ($erreur !== ''): ?>
  <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= $h($erreur) ?></div>
<?php endif; ?>
<?php if ($succes !== ''): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= $h($succes) ?></div>
<?php endif; ?>

<div class="card-dashboard">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h2 class="h5 fw-bold mb-0"><i class="bi bi-shield-lock me-2"></i>Ce qui n'est jamais effacé</h2>
  </div>
  <p class="text-muted small mb-0">
    Aucune purge ne touche aux <strong>inscriptions</strong> — ni celles de l'édition en
    cours, ni les archives <code>registrations_AAAA</code>. L'association doit les
    conserver pour sa comptabilité, et les archives sont la mémoire de l'événement.
    Les <strong>comptes coureurs actifs</strong> ne sont pas purgés non plus : un compte
    ne disparaît que si la personne le demande elle-même, et même alors son inscription
    reste valable — c'est l'accès en ligne qui s'arrête, pas la participation.
  </p>
</div>

<div class="card-dashboard">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h2 class="h5 fw-bold mb-0"><i class="bi bi-hourglass-split me-2"></i>Durées de conservation</h2>
  </div>
  <p class="text-muted small mb-0">
    Ces durées doivent correspondre à ce qu'annonce votre
    <a href="setting.php?tab=legal">politique de confidentialité</a>. Annoncer 30 jours
    et en conserver 400 est une déclaration inexacte, et elle est vérifiable.
  </p>

  <form method="post">
    <?= csrf_field() ?>
    <div class="list-group list-group-flush">
      <?php foreach ([
            'auth_codes_conservation_jours' => ['Codes de connexion',
                'Codes à 6 chiffres, consommés ou périmés. Ils ne contiennent aucune adresse en clair, seulement une empreinte.'],
            'devices_revoques_jours'        => ['Appareils révoqués',
                'Le jeton est déjà inutilisable ; ce qui reste, c\'est le modèle du téléphone et l\'IP de création.'],
            'traces_gps_conservation_jours' => ['Traces GPS',
                'La donnée la plus sensible du site : elle dit où une personne se trouvait, minute par minute.'],
            'transferts_clos_jours'         => ['Demandes de transfert closes',
                'Acceptées, annulées ou expirées. Celles EN ATTENTE ne sont jamais purgées.'],
          ] as $col => [$lib, $aide]): ?>
        <div class="list-group-item d-flex justify-content-between align-items-center gap-3 px-0 bg-transparent">
          <div>
            <strong><?= $h($lib) ?></strong>
            <div class="text-muted small"><?= $h($aide) ?></div>
          </div>
          <div class="d-flex align-items-center gap-2">
            <input class="form-control form-control-sm" type="number" min="1" max="3650"
                   style="width:110px" name="<?= $col ?>" value="<?= (int) $durees[$col] ?>">
            <span class="text-muted small">jours</span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="d-flex gap-2 flex-wrap mt-3">
      <button class="btn btn-primary" name="enregistrer_durees" value="1">
        <i class="bi bi-check2"></i> Enregistrer les durées
      </button>
    </div>
  </form>
</div>

<div class="card-dashboard">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h2 class="h5 fw-bold mb-0"><i class="bi bi-trash3 me-2"></i><?= $simulation ? 'Ce qui serait effacé' : 'Ce qui vient d\'être effacé' ?></h2>
    <?php if ($simulation): ?><span class="badge bg-secondary">simulation</span><?php endif; ?>
  </div>

  <?php if ($rapport['erreurs']): ?>
    <div class="alert alert-warning">
      <i class="bi bi-exclamation-triangle me-2"></i>
      Certaines tables sont absentes — lancez <code>update.php</code>.
      <div class="small mt-1"><?= $h(implode(' · ', $rapport['erreurs'])) ?></div>
    </div>
  <?php endif; ?>

  <table class="table fer-table table-sm align-middle">
    <thead><tr><th>Donnée</th><th>Au-delà de</th><th class="text-end">Lignes</th></tr></thead>
    <tbody>
      <?php foreach ($rapport['details'] as $d): ?>
        <tr>
          <td><?= $h($d['libelle']) ?></td>
          <td class="text-muted small"><?= (int) $d['jours'] ?> jours</td>
          <td class="text-end">
            <?php if ($d['nombre'] > 0): ?>
              <strong><?= (int) $d['nombre'] ?></strong>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($rapport['total'] === 0): ?>
    <div class="text-center text-muted py-4"><p>Rien à effacer : tout est dans les délais.</p></div>
  <?php else: ?>
    <form method="post" class="d-flex gap-2 flex-wrap align-items-end">
      <?= csrf_field() ?>
      <div class="mb-2" style="max-width:220px">
        <label for="conf">Saisissez <strong>PURGER</strong> pour confirmer</label>
        <input class="form-control form-control-sm" id="conf" name="confirmation" type="text"
               autocomplete="off" placeholder="PURGER">
      </div>
      <button class="btn btn-sm btn-danger" name="purger" value="1"
              onclick="return confirm('Effacer définitivement <?= (int) $rapport['total'] ?> ligne(s) ? Cette action est irréversible.');">
        <i class="bi bi-trash3"></i> Purger maintenant
      </button>
      <button class="btn btn-sm btn-outline-secondary" name="simuler" value="1">
        <i class="bi bi-arrow-clockwise"></i> Recalculer
      </button>
    </form>
  <?php endif; ?>

  <p class="text-muted small mb-0">
    <i class="bi bi-info-circle me-1"></i>
    La purge s'exécute aussi <strong>automatiquement, une fois par jour</strong>, déclenchée
    par la première visite. Ce bouton sert à la déclencher tout de suite, ou à vérifier
    qu'elle fonctionne. Chaque exécution est tracée dans
    <a href="logs.php">Journaux système</a>.
  </p>
</div>

<div class="card-dashboard">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h2 class="h5 fw-bold mb-0"><i class="bi bi-database me-2"></i>Volumes actuels</h2>
  </div>
  <div class="list-group list-group-flush">
    <?php foreach ($volumes as $lib => $n): ?>
      <div class="list-group-item d-flex justify-content-between align-items-center gap-3 px-0 bg-transparent">
        <div><?= $h($lib) ?></div>
        <div><?= $n === null ? '<span class="text-muted">table absente</span>' : '<strong>' . (int) $n . '</strong>' ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php include __DIR__ . '/../src/partials/admin-footer.php'; ?>
</body>
</html>
