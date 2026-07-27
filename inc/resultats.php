<?php
/**
 * resultats.php — Chronométrage : consulter, corriger, valider (administration).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CET ÉCRAN EXISTE POUR LE JOUR OÙ QUELQU'UN CONTESTE SON TEMPS.
 *
 * Il ne se contente pas d'afficher un chrono : il montre TOUTES les détections
 * reçues pour une inscription — celle de la balise, celle du GPS — et laquelle
 * a été retenue. Sans ça, on ne peut répondre que « c'est ce que dit la
 * machine », ce qui n'a jamais convaincu personne.
 *
 * Trois actions possibles :
 *   • RECALCULER — rejouer l'arbitrage après l'arrivée de détections tardives.
 *   • SAISIR À LA MAIN — le filet de sécurité du jour J : téléphone à plat,
 *     application pas lancée, balise en panne. Une détection « manuel » prime
 *     sur toutes les autres.
 *   • VALIDER — figer le résultat. Une fois validé, plus aucun recalcul
 *     automatique ne le touche : une détection GPS arrivée pendant la nuit ne
 *     doit pas défaire la décision d'un humain.
 */
require '../src/core/config.php';
require_once __DIR__ . '/../src/security/csrf.php';
require __DIR__ . '/../src/partials/navbar-data.php';
require_once __DIR__ . '/../src/content/content-log.php';   // logContentAction()
require_once __DIR__ . '/../src/content/chrono.php';

requirePage('dashboard');
if (!canDoAction('dashboard.transfers') && currentRole() !== 'admin') {
    http_response_code(403);
    die('Action non autorisée.');
}
$role = currentRole();

$erreur = '';
$succes = '';
$annee  = (int) ($_GET['annee'] ?? regres_activeYear($pdo));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $erreur = 'Session expirée. Rechargez la page et réessayez.';
    } else {
        $no = trim((string) ($_POST['inscription_no'] ?? ''));

        if (isset($_POST['recalculer']) && $no !== '') {
            // forcer = true : on passe outre la garde « résultat validé », parce
            // qu'ici c'est un humain qui le demande explicitement.
            $r = chrono_recompute($pdo, $annee, $no, true);
            $succes = $r['ok'] ? 'Résultat recalculé (' . ($r['statut'] ?? '?') . ').'
                               : ($r['message'] ?? 'Échec du recalcul.');
        }
        elseif (isset($_POST['saisir']) && $no !== '') {
            $point = (string) ($_POST['point'] ?? '');
            $heure = trim((string) ($_POST['heure'] ?? ''));
            if (!in_array($point, ['depart', 'arrivee'], true) || $heure === '') {
                $erreur = 'Indiquez le point et l\'heure.';
            } else {
                /* L'heure est saisie en heure LOCALE (c'est ce que lit l'officiel
                   sur sa montre) et convertie en UTC — le stockage est en UTC.
                   Sans cette conversion, tous les temps seraient décalés de deux
                   heures, sans le moindre message d'erreur. */
                try {
                    $local = new DateTimeImmutable($heure, new DateTimeZone('Europe/Paris'));
                    $iso   = $local->format('c');
                    $r = chrono_ingestDetection($pdo, $annee, $no, [
                        'type' => 'manuel', 'point' => $point, 'detecte_at' => $iso,
                    ]);
                    if ($r['ok']) {
                        chrono_recompute($pdo, $annee, $no, true);
                        $succes = 'Heure saisie et résultat recalculé.';
                        logContentAction($pdo, 'resultats', 'update', null,
                            "Saisie manuelle $point pour $no ($iso)", 'chrono');
                    } else {
                        $erreur = $r['erreur'];
                    }
                } catch (\Throwable $e) {
                    $erreur = 'Heure illisible. Format attendu : 2026-10-04 10:15:30';
                }
            }
        }
        elseif (isset($_POST['valider']) && $no !== '') {
            $pdo->prepare('UPDATE resultats SET valide_par = ?, statut = ?
                            WHERE annee = ? AND inscription_no = ?')
                ->execute([currentUserId(), (string) ($_POST['statut'] ?? 'termine'), $annee, $no]);
            $succes = 'Résultat validé — il ne sera plus recalculé automatiquement.';
            logContentAction($pdo, 'resultats', 'update', null, "Résultat validé pour $no", 'chrono');
        }
        elseif (isset($_POST['devalider']) && $no !== '') {
            $pdo->prepare('UPDATE resultats SET valide_par = NULL WHERE annee = ? AND inscription_no = ?')
                ->execute([$annee, $no]);
            $succes = 'Validation retirée : le résultat redevient recalculable.';
        }
    }
}

/* Éditions disponibles */
try {
    $editions = $pdo->query('SELECT annee FROM editions ORDER BY annee DESC')->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) { $editions = [$annee]; }

/* Résultats de l'édition, enrichis du nom (chiffré en base, déchiffré ici). */
$lignes = [];
try {
    $st = $pdo->prepare('SELECT * FROM resultats WHERE annee = ? ORDER BY
                           CASE statut WHEN \'invalide\' THEN 0 ELSE 1 END, temps_s IS NULL, temps_s');
    $st->execute([$annee]);
    $lignes = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $erreur = 'Table des résultats absente. Lancez update.php.';
}

/* Détail des détections d'une inscription (ouvert à la demande). */
$detail = trim((string) ($_GET['detail'] ?? ''));
$detections = [];
if ($detail !== '') {
    try {
        $st = $pdo->prepare('SELECT * FROM detections WHERE annee = ? AND inscription_no = ?
                              ORDER BY point, detecte_at');
        $st->execute([$annee, $detail]);
        $detections = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $detections = []; }
}

$nbInvalides = count(array_filter($lignes, fn($l) => $l['statut'] === 'invalide'));

$pageTitle    = 'Résultats';
$pageSubtitle = 'Résultats';
$h    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$date = fn($d) => $d ? date('d/m/Y H:i:s', strtotime((string) $d . ' UTC')) : '—';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Résultats</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>

  <div class="page-header">
    <h1 class="mb-2 fw-bold"><i class="bi bi-stopwatch me-2"></i>Résultats</h1>
    <p class="text-muted mb-0">Chronométrage : consulter, corriger et valider les temps. Chaque résultat peut être ouvert pour voir TOUTES les détections reçues — balise et GPS — et laquelle a servi au calcul. C'est cette page qu'on montre si quelqu'un conteste son temps.</p>
  </div>

<?php
  /* Retours en TOAST, comme partout ailleurs dans l'administration. Le rendu se
     fait par src/partials/toast.php, inclus par admin-footer.php — donc APRÈS
     ces appels, ce qui suffit. */
  if ($erreur !== '') addToast('danger', $erreur);
  if ($succes !== '') addToast('success', $succes);
?>

<div class="card-dashboard">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h2 class="h5 fw-bold mb-0"><i class="bi bi-stopwatch me-2"></i>Résultats <?= (int) $annee ?></h2>
    <div class="btn-group btn-group-sm flex-wrap" role="group">
      <?php foreach ($editions as $e): ?>
        <a class="btn btn-sm <?= (int) $e === $annee ? 'btn-primary' : 'btn-outline-secondary' ?>"
           href="?annee=<?= (int) $e ?>"><?= (int) $e ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($nbInvalides > 0): ?>
    <div class="alert alert-warning">
      <i class="bi bi-exclamation-triangle me-2"></i>
      <strong><?= $nbInvalides ?> résultat(s) à vérifier</strong> — arrivée sans départ, temps
      sous le minimum plausible, ou horodatages incohérents. Ils sont en tête de liste et
      ne sont PAS présentés comme des temps au coureur.
    </div>
  <?php endif; ?>

  <?php if (!$lignes): ?>
    <div class="text-center text-muted py-4"><p>Aucun résultat pour cette édition.</p>
      <p class="text-muted small">
        Les résultats se créent tout seuls à la réception des détections envoyées par
        l'application (balise et GPS). Il n'y a rien à faire ici tant que la course
        n'a pas eu lieu.
      </p>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table fer-table table-sm align-middle">
        <thead>
          <tr>
            <th>Inscription</th><th>Départ</th><th>Arrivée</th><th>Temps</th>
            <th>Mesure</th><th>Statut</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($lignes as $l): ?>
          <tr<?= $l['statut'] === 'invalide' ? ' class="table-warning"' : '' ?>>
            <td>
              <strong><?= $h($l['inscription_no']) ?></strong>
              <?php if ($l['valide_par'] !== null): ?>
                <span class="badge bg-success" title="Ne sera plus recalculé automatiquement">validé</span>
              <?php endif; ?>
              <?php if (!empty($l['commentaire'])): ?>
                <div class="text-muted small"><?= $h($l['commentaire']) ?></div>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= $h($date($l['depart_at'])) ?></td>
            <td class="small text-muted"><?= $h($date($l['arrivee_at'])) ?></td>
            <td><strong><?= $h(chrono_formatTemps($l['temps_s'] === null ? null : (float) $l['temps_s'])) ?></strong></td>
            <td class="small">
              <?php /* La méthode et la précision accompagnent TOUJOURS le temps.
                       Un temps GPS affiché nu passerait pour une mesure à la seconde. */ ?>
              <?= $h(chrono_libelleMethode($l['methode'])) ?>
              <?php if ($l['precision_s'] !== null): ?>
                <span class="text-muted">±<?= (int) $l['precision_s'] ?> s</span>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= $l['statut'] === 'termine' ? 'bg-success'
                                     : ($l['statut'] === 'invalide' ? 'bg-danger' : 'bg-secondary') ?>">
              <?= $h($l['statut']) ?></span></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-secondary" href="?annee=<?= (int) $annee ?>&detail=<?= urlencode($l['inscription_no']) ?>"
                 title="Voir toutes les détections reçues">
                <i class="bi bi-list-columns"></i>
              </a>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="inscription_no" value="<?= $h($l['inscription_no']) ?>">
                <button class="btn btn-sm btn-outline-secondary" name="recalculer" value="1" title="Recalculer">
                  <i class="bi bi-arrow-clockwise"></i>
                </button>
              </form>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="inscription_no" value="<?= $h($l['inscription_no']) ?>">
                <?php if ($l['valide_par'] === null): ?>
                  <button class="btn btn-sm btn-primary" name="valider" value="1"
                          title="Figer ce résultat">
                    <i class="bi bi-check2-circle"></i>
                  </button>
                <?php else: ?>
                  <button class="btn btn-sm btn-outline-secondary" name="devalider" value="1"
                          title="Rendre recalculable">
                    <i class="bi bi-unlock"></i>
                  </button>
                <?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($detail !== ''): ?>
<div class="card-dashboard">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h2 class="h5 fw-bold mb-0"><i class="bi bi-list-columns me-2"></i>Détections reçues — <?= $h($detail) ?></h2>
    <a class="btn btn-sm btn-outline-secondary" href="?annee=<?= (int) $annee ?>">Fermer</a>
  </div>

  <p class="text-muted small mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Toutes les détections, balise <strong>et</strong> GPS. La ligne
    <strong>retenue</strong> est celle qui a servi au calcul. C'est cette page qu'il
    faut montrer si quelqu'un conteste son temps.
  </p>

  <?php if (!$detections): ?>
    <div class="text-center text-muted py-4"><p>Aucune détection reçue pour cette inscription.</p></div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table fer-table table-sm align-middle">
        <thead>
          <tr><th>Point</th><th>Source</th><th>Vu à (UTC)</th><th>Reçu à</th>
              <th>Signal</th><th>Confiance</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($detections as $d): ?>
          <tr<?= (int) $d['retenue'] === 1 ? ' class="table-success"' : '' ?>>
            <td><?= $d['point'] === 'depart' ? 'Départ' : 'Arrivée' ?></td>
            <td>
              <?= match ($d['type']) {
                    'beacon'    => '📡 Balise',
                    'geofence'  => '📍 Zone GPS',
                    'gps_ligne' => '📍 GPS (ligne)',
                    'manuel'    => '✍️ Saisie manuelle',
                    default     => $h($d['type']),
                  } ?>
            </td>
            <td class="small"><?= $h(substr((string) $d['detecte_at'], 0, 23)) ?></td>
            <td class="small text-muted"><?= $h(substr((string) $d['recu_at'], 0, 19)) ?></td>
            <td class="small text-muted">
              <?= $d['rssi_pic'] !== null ? (int) $d['rssi_pic'] . ' dBm' : '—' ?>
            </td>
            <td class="small"><?= $d['confiance'] !== null ? (int) $d['confiance'] . '%' : '—' ?></td>
            <td><?= (int) $d['retenue'] === 1 ? '<span class="badge bg-success">retenue</span>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <hr>
  <h3 style="font-size:1rem">Saisir une heure à la main</h3>
  <p class="text-muted small mb-0">
    Le filet de sécurité du jour J : téléphone à plat, application pas lancée, balise
    en panne. Une saisie manuelle <strong>prime sur toutes les autres détections</strong>.
    Indiquez l'heure locale, telle que vous l'avez lue.
  </p>
  <form method="post" class="d-flex gap-2 flex-wrap align-items-end mt-3">
    <?= csrf_field() ?>
    <input type="hidden" name="inscription_no" value="<?= $h($detail) ?>">
    <div class="mb-2">
      <label for="pt">Point</label>
      <select class="form-select form-select-sm" id="pt" name="point">
        <option value="depart">Départ</option>
        <option value="arrivee" selected>Arrivée</option>
      </select>
    </div>
    <div class="mb-2">
      <label for="hr">Heure locale</label>
      <input class="form-control form-control-sm" id="hr" name="heure" type="text"
             placeholder="<?= date('Y-m-d') ?> 10:15:30" style="min-width:200px">
    </div>
    <button class="btn btn-sm btn-primary" name="saisir" value="1">
      <i class="bi bi-pencil"></i> Enregistrer et recalculer
    </button>
  </form>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../src/partials/admin-footer.php'; ?>
</body>
</html>
