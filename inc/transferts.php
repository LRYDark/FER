<?php
/**
 * transferts.php — Suivi des transferts d'inscription (administration, lot 4).
 *
 * Un administrateur peut FORCER un transfert en attente (accepter à la place de
 * la cible) ou l'ANNULER. Le forçage existe pour le jour de la course : quelqu'un
 * se présente, son transfert n'a pas été confirmé, et il faut trancher en trente
 * secondes sans accès à sa boîte mail.
 */
require '../src/core/config.php';
require_once __DIR__ . '/../src/security/csrf.php';
require __DIR__ . '/../src/partials/navbar-data.php';
require_once __DIR__ . '/../src/content/transfers.php';

requirePage('dashboard');
if (!canDoAction('dashboard.transfers')) {
    http_response_code(403);
    die('Action non autorisée (droit « Transferts d\'inscription » requis).');
}
$role = currentRole();

xfer_purge($pdo);

$erreur = '';
$succes = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $erreur = 'Session expirée. Rechargez la page et réessayez.';
    } elseif (isset($_POST['annuler'])) {
        // participantId = null : annulation par l'administration, sans condition
        // sur l'auteur de la demande.
        $r = xfer_annuler($pdo, (int) $_POST['annuler'], null);
        if ($r['ok']) $succes = 'Transfert annulé.';
        else          $erreur = $r['erreur'];
    } elseif (isset($_POST['forcer'])) {
        // Le jeton en clair n'existe nulle part : seul son SHA-256 est stocké.
        // Le forçage passe donc par une acceptation directe, sans jeton.
        $r = xfer_accepterParId($pdo, (int) $_POST['forcer']);
        if ($r['ok']) $succes = 'Transfert forcé et appliqué.';
        else          $erreur = $r['erreur'];
    }
}

/* Filtre sur le statut */
$filtre = (string) ($_GET['statut'] ?? 'tous');
if (!in_array($filtre, array_merge(['tous'], XFER_STATUTS), true)) $filtre = 'tous';

$sql = 'SELECT * FROM registration_transfers';
$arg = [];
if ($filtre !== 'tous') { $sql .= ' WHERE statut = ?'; $arg[] = $filtre; }
$sql .= ' ORDER BY id DESC LIMIT 300';

try {
    $st = $pdo->prepare($sql);
    $st->execute($arg);
    $lignes = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $lignes = [];
    $erreur = "Table des transferts absente. Lancez update.php.";
}

/* Compteurs par statut, pour les onglets de filtre. */
$compteurs = ['tous' => 0];
foreach (XFER_STATUTS as $s) $compteurs[$s] = 0;
try {
    foreach ($pdo->query('SELECT statut, COUNT(*) n FROM registration_transfers GROUP BY statut') as $c) {
        $compteurs[$c['statut']] = (int) $c['n'];
        $compteurs['tous'] += (int) $c['n'];
    }
} catch (\Throwable $e) {}

$libStatut = [
    'en_attente' => ['En attente', 'is-warn'],
    'accepte'    => ['Accepté',    'is-ok'],
    'annule'     => ['Annulé',     'no-dot'],
    'expire'     => ['Expiré',     'is-danger'],
];

$pageTitle    = "Transferts d'inscription";
$pageSubtitle = "Transferts d'inscription";
$h    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$date = fn($d) => $d ? date('d/m/Y H:i', strtotime((string) $d)) : '—';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Transferts d'inscription</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>

<?php if ($erreur !== ''): ?>
  <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= $h($erreur) ?></div>
<?php endif; ?>
<?php if ($succes !== ''): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= $h($succes) ?></div>
<?php endif; ?>

<div class="card">
  <header>
    <div class="iconwell"><i class="bi bi-arrow-left-right"></i></div>
    <h2>Transferts</h2>
    <div class="seg">
      <?php foreach (['tous' => 'Tous'] + array_map(fn($v) => $v[0], $libStatut) as $cle => $lib): ?>
        <a class="btn btn-sm <?= $filtre === $cle ? 'btn-primary' : '' ?>"
           href="?statut=<?= $cle ?>"><?= $h($lib) ?> (<?= (int) ($compteurs[$cle] ?? 0) ?>)</a>
      <?php endforeach; ?>
    </div>
  </header>

  <?php if (!$lignes): ?>
    <div class="empty"><p>Aucun transfert pour ce filtre.</p></div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>Inscription</th><th>De</th><th>Vers</th>
            <th>Statut</th><th>Demandé</th><th>Expire</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($lignes as $l): ?>
          <?php [$lib, $cls] = $libStatut[$l['statut']] ?? ['?', 'no-dot']; ?>
          <tr>
            <td>
              <strong><?= $h($l['inscription_no']) ?></strong>
              <div class="text-muted small">édition <?= (int) $l['annee'] ?></div>
            </td>
            <?php /* Les adresses sont chiffrées en base : on les déchiffre ici,
                     à l'affichage, comme partout ailleurs dans l'administration. */ ?>
            <td class="small"><?= $h(decrypt($l['email_source'])) ?></td>
            <td class="small"><?= $h(decrypt($l['email_cible'])) ?></td>
            <td><span class="pill <?= $cls ?>"><?= $h($lib) ?></span></td>
            <td class="small text-muted"><?= $h($date($l['created_at'])) ?></td>
            <td class="small text-muted">
              <?= $l['statut'] === 'en_attente' ? $h($date($l['expires_at'])) : '—' ?>
            </td>
            <td class="text-end">
              <?php if ($l['statut'] === 'en_attente'): ?>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Forcer ce transfert ? L\'inscription changera de titulaire immédiatement, sans confirmation du destinataire.');">
                  <?= csrf_field() ?>
                  <button class="btn btn-sm btn-primary" name="forcer" value="<?= (int) $l['id'] ?>">
                    <i class="bi bi-check2"></i> Forcer
                  </button>
                </form>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Annuler ce transfert ?');">
                  <?= csrf_field() ?>
                  <button class="btn btn-sm btn-outline-danger" name="annuler" value="<?= (int) $l['id'] ?>">
                    <i class="bi bi-x-lg"></i>
                  </button>
                </form>
              <?php else: ?>
                <span class="text-muted small">
                  <?= $l['statut'] === 'accepte' ? 'le ' . $h($date($l['accepte_at'])) : '' ?>
                  <?= $l['statut'] === 'annule'  ? 'le ' . $h($date($l['annule_at']))  : '' ?>
                </span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <p class="text-muted small mb-0">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Forcer</strong> applique le transfert sans la confirmation du destinataire :
    l'inscription change de titulaire et l'ancien perd son accès. À réserver aux cas
    tranchés sur place, le jour de la course.
  </p>
</div>

<?php include __DIR__ . '/../src/partials/admin-footer.php'; ?>
</body>
</html>
