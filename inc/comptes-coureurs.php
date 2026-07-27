<?php
/**
 * comptes-coureurs.php — Comptes de l'espace coureur (administration, lot 4).
 *
 * CE N'EST PAS DU CONFORT. Le jour de la course, quelqu'un dira « je n'arrive
 * pas à me connecter » et il faut trente secondes pour comprendre : son adresse
 * existe-t-elle, a-t-il demandé un code, le mail est-il parti. Sans cet écran,
 * on est aveugle.
 *
 * Les adresses sont chiffrées en base : la recherche se fait donc par EMPREINTE
 * (fer_emailHmac), pas par LIKE. On retrouve une adresse exacte, jamais un
 * fragment — c'est le prix du chiffrement, et il est assumé.
 */
require '../src/core/config.php';
require_once __DIR__ . '/../src/security/csrf.php';
require __DIR__ . '/../src/partials/navbar-data.php';
require_once __DIR__ . '/../src/content/content-log.php';   // logContentAction()
require_once __DIR__ . '/../src/auth/participant_auth.php';

requirePage('dashboard');
if (!canDoAction('dashboard.participants')) {
    http_response_code(403);
    die('Action non autorisée (droit « Comptes coureurs » requis).');
}
$role = currentRole();

$erreur = '';
$succes = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $erreur = 'Session expirée. Rechargez la page et réessayez.';
    } else {
        $id = (int) ($_POST['id'] ?? 0);

        if (isset($_POST['revoquer_appareils'])) {
            $st = $pdo->prepare('UPDATE participant_devices SET revoque_at = NOW()
                                  WHERE participant_id = ? AND revoque_at IS NULL');
            $st->execute([$id]);
            $succes = $st->rowCount() . ' appareil(s) révoqué(s).';
            logContentAction($pdo, 'compte_coureur', 'update', $id, 'Appareils révoqués', 'participant');
        }
        elseif (isset($_POST['basculer_actif'])) {
            $pdo->prepare('UPDATE participants SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            $etat = (int) $pdo->query("SELECT is_active FROM participants WHERE id = $id")->fetchColumn();
            // Un compte désactivé ne peut plus se connecter : la reconnexion par
            // cookie le vérifie, et la validation du code aussi.
            $succes = $etat === 1 ? 'Compte réactivé.' : 'Compte désactivé : plus aucune connexion possible.';
            logContentAction($pdo, 'compte_coureur', 'update', $id,
                $etat === 1 ? 'Compte réactivé' : 'Compte désactivé', 'participant');
        }
        elseif (isset($_POST['envoyer_code'])) {
            $st = $pdo->prepare('SELECT email_chiffre FROM participants WHERE id = ?');
            $st->execute([$id]);
            $adresse = decrypt((string) $st->fetchColumn());
            if ($adresse === '' || $adresse === null) {
                $erreur = 'Adresse introuvable pour ce compte.';
            } else {
                $code = pauth_issueCode($pdo, $adresse, 'web', fer_client_ip());
                $succes = pauth_sendCodeMail($pdo, $adresse, $code)
                    ? 'Code envoyé à ' . $adresse . '.'
                    : "L'envoi a échoué — voyez les journaux de mail.";
                logContentAction($pdo, 'compte_coureur', 'update', $id, 'Code de connexion renvoyé', 'participant');
            }
        }
    }
}

/* Recherche par adresse exacte : les adresses étant chiffrées, seule l'empreinte
   permet de retrouver une ligne. Un LIKE serait sans effet sur du chiffré. */
$recherche = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT p.*,
               (SELECT COUNT(*) FROM participant_registrations pr WHERE pr.participant_id = p.id) AS nb_inscriptions,
               (SELECT COUNT(*) FROM participant_devices d
                 WHERE d.participant_id = p.id AND d.revoque_at IS NULL
                   AND (d.expires_at IS NULL OR d.expires_at > NOW()))                            AS nb_appareils,
               (SELECT MAX(c.created_at) FROM participant_auth_codes c
                 WHERE c.email_hmac = p.email_hmac)                                               AS dernier_code
          FROM participants p';
$arg = [];
if ($recherche !== '') {
    $sql .= ' WHERE p.email_hmac = ?';
    $arg[] = fer_emailHmac($recherche);
}
$sql .= ' ORDER BY p.derniere_connexion IS NULL, p.derniere_connexion DESC, p.id DESC LIMIT 300';

try {
    $st = $pdo->prepare($sql);
    $st->execute($arg);
    $comptes = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $comptes = [];
    $erreur = 'Tables de l\'espace coureur absentes. Lancez update.php.';
}

$pageTitle    = 'Comptes coureurs';
$pageSubtitle = 'Comptes coureurs';
$h    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$date = fn($d) => $d ? date('d/m/Y H:i', strtotime((string) $d)) : '—';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Comptes coureurs</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>

  <div class="page-header">
    <h1 class="mb-2 fw-bold"><i class="bi bi-people me-2"></i>Comptes coureurs</h1>
    <p class="text-muted mb-0">Les comptes de l'espace coureur. Le jour de la course, c'est ici qu'on répond en trente secondes à « je n'arrive pas à me connecter » : l'adresse existe-t-elle, un code a-t-il été demandé, le mail est-il parti.</p>
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
    <h2 class="h5 fw-bold mb-0"><i class="bi bi-search me-2"></i>Rechercher un compte</h2>
    <form method="get" class="d-flex gap-2">
      <input class="form-control form-control-sm" type="email" name="q" style="min-width:240px"
             value="<?= $h($recherche) ?>" placeholder="Adresse email exacte">
      <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
      <?php if ($recherche !== ''): ?>
        <a class="btn btn-sm btn-outline-secondary" href="comptes-coureurs.php">Tout</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if ($recherche !== ''): ?>
    <p class="text-muted small mb-0">
      <i class="bi bi-info-circle me-1"></i>
      Recherche par adresse <strong>exacte</strong> : les adresses sont chiffrées en base,
      une recherche partielle est impossible.
    </p>
  <?php endif; ?>

  <?php if (!$comptes): ?>
    <div class="text-center text-muted py-4"><p><?= $recherche !== '' ? 'Aucun compte pour cette adresse.' : 'Aucun compte coureur pour le moment.' ?></p>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table fer-table table-sm align-middle">
        <thead>
          <tr>
            <th>Compte</th><th>Créé</th><th>Dernière connexion</th>
            <th>Dernier code</th><th class="text-center">Inscr.</th>
            <th class="text-center">Appareils</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($comptes as $c): ?>
          <?php $actif = (int) $c['is_active'] === 1; ?>
          <tr<?= $actif ? '' : ' class="opacity-50"' ?>>
            <td>
              <div><?= $h(decrypt($c['email_chiffre'])) ?></div>
              <div class="text-muted small">
                <?= $h(trim(($c['prenom'] ?? '') . ' ' . ($c['nom'] ?? ''))) ?: '—' ?>
                <?php if (!$actif): ?>
                  <span class="badge bg-danger">désactivé</span>
                <?php endif; ?>
                <?php if (empty($c['rgpd_consent_at'])): ?>
                  <span class="badge bg-warning text-dark">RGPD non accepté</span>
                <?php endif; ?>
              </div>
            </td>
            <td class="small text-muted"><?= $h($date($c['created_at'])) ?></td>
            <td class="small text-muted"><?= $h($date($c['derniere_connexion'])) ?></td>
            <?php /* La colonne qui répond à « le mail est-il parti ? ». */ ?>
            <td class="small text-muted"><?= $h($date($c['dernier_code'])) ?></td>
            <td class="text-center"><?= (int) $c['nb_inscriptions'] ?></td>
            <td class="text-center"><?= (int) $c['nb_appareils'] ?></td>
            <td class="text-end">
              <?php /* Confirmation avec l'adresse EN TOUTES LETTRES : envoyer un
                       code, c'est écrire à quelqu'un. Sans elle, un clic de
                       travers dans une liste de trois cents lignes envoie un mail
                       à la mauvaise personne, et rien ne permet de le rattraper. */ ?>
              <form method="post" class="d-inline"
                    data-confirm="Envoyer un code de connexion à <?= $h(decrypt($c['email_chiffre'])) ?> ?">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary" name="envoyer_code" value="1"
                        title="Envoyer un code de connexion à cette personne">
                  <i class="bi bi-envelope"></i>
                </button>
              </form>
              <?php if ((int) $c['nb_appareils'] > 0): ?>
                <form method="post" class="d-inline"
                      data-confirm="Révoquer tous les appareils de ce compte ?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary" name="revoquer_appareils" value="1" title="Révoquer les appareils">
                    <i class="bi bi-phone-flip"></i>
                  </button>
                </form>
              <?php endif; ?>
              <form method="post" class="d-inline"
                    data-confirm="<?= $actif ? 'Désactiver ce compte ? La personne ne pourra plus se connecter.' : 'Réactiver ce compte ?' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn btn-sm <?= $actif ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                        name="basculer_actif" value="1"
                        title="<?= $actif ? 'Désactiver' : 'Réactiver' ?>">
                  <i class="bi <?= $actif ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <p class="text-muted small mb-0">
    <i class="bi bi-shield-lock me-1"></i>
    Désactiver un compte coupe la connexion et la reconnexion automatique, mais ne
    touche pas à l'inscription à la course : la personne reste attendue au départ.
  </p>
</div>

<?php include __DIR__ . '/../src/partials/admin-footer.php'; ?>
</body>
</html>
