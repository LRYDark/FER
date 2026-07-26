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
        /* ── Compte de démonstration (lot 7) ─────────────────────────────────
         * Montrer l'espace coureur à un bénévole, à un partenaire ou lors d'une
         * réunion suppose aujourd'hui d'ouvrir le compte de QUELQU'UN — donc
         * d'exposer son nom, son adresse et son inscription à des gens qui n'ont
         * rien à y voir. Ce compte existe pour que ce ne soit jamais nécessaire.
         *
         * Il est rattaché à une adresse que VOUS contrôlez : vous vous y
         * connectez normalement, avec un code reçu par mail. Aucun mécanisme
         * d'usurpation n'est introduit — pouvoir « se connecter en tant que »
         * serait une porte dérobée permanente dans l'espace coureur. */
        elseif (isset($_POST['creer_demo'])) {
            $mailDemo = fer_normalizeEmail((string) ($_POST['email_demo'] ?? ''));
            if ($mailDemo === '' || !filter_var($mailDemo, FILTER_VALIDATE_EMAIL)) {
                $erreur = 'Indiquez une adresse email valide, que vous pouvez consulter.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $annee = regres_activeYear($pdo);
                    $no    = 'DEMO-' . $annee;

                    // Numéro FIXE : relancer la création ne crée pas dix fausses
                    // inscriptions. Nom en capitales et sans ambiguïté : personne
                    // ne doit prendre cette ligne pour un vrai participant.
                    $pdo->prepare(
                        'INSERT INTO registrations (inscription_no, nom, prenom, email, naissance,
                                                    sexe, ville, tshirt_size, paiement_mode, montant_du)
                         VALUES (?,?,?,?,?,?,?,?,?,0)
                         ON DUPLICATE KEY UPDATE email = VALUES(email)'
                    )->execute([
                        $no, encrypt('DÉMONSTRATION'), encrypt('Compte'), encrypt($mailDemo),
                        encrypt('35'), 'Autre', encrypt('Forbach'), 'M', 'gratuit',
                    ]);

                    $participant = pauth_findByEmail($pdo, $mailDemo)
                                ?? pauth_createFromRegistrations($pdo, $mailDemo);
                    if ($participant === null) throw new RuntimeException('Compte non créé');
                    pauth_syncRegistrations($pdo, (int) $participant['id'], $mailDemo);

                    $pdo->commit();
                    $succes = 'Compte de démonstration prêt. Connectez-vous sur l\'espace coureur '
                            . 'avec ' . $mailDemo . ' : vous recevrez un code par mail.';
                    logContentAction($pdo, 'compte_coureur', 'create', (int) $participant['id'],
                        'Compte de démonstration créé', 'participant');
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('[DEMO] ' . $e->getMessage());
                    $erreur = 'Création impossible : ' . $e->getMessage();
                }
            }
        }
        elseif (isset($_POST['supprimer_demo'])) {
            try {
                $annee = regres_activeYear($pdo);
                $no    = 'DEMO-' . $annee;
                // Le rattachement d'abord, l'inscription ensuite : l'inverse
                // laisserait un lien pointant dans le vide.
                $pdo->prepare('DELETE FROM participant_registrations WHERE annee = ? AND inscription_no = ?')
                    ->execute([$annee, $no]);
                $st = $pdo->prepare('DELETE FROM registrations WHERE inscription_no = ?');
                $st->execute([$no]);
                $succes = $st->rowCount() > 0
                    ? 'Inscription de démonstration supprimée. Le compte coureur, lui, reste : '
                    . 'supprimez-le depuis la liste si vous ne vous en servez plus.'
                    : 'Aucune inscription de démonstration à supprimer.';
                logContentAction($pdo, 'compte_coureur', 'delete', null,
                    'Inscription de démonstration supprimée', 'participant');
            } catch (\Throwable $e) {
                $erreur = 'Suppression impossible : ' . $e->getMessage();
            }
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

<?php if ($erreur !== ''): ?>
  <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= $h($erreur) ?></div>
<?php endif; ?>
<?php if ($succes !== ''): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= $h($succes) ?></div>
<?php endif; ?>

<div class="card">
  <header>
    <div class="iconwell"><i class="bi bi-people"></i></div>
    <h2>Comptes coureurs</h2>
    <form method="get" class="d-flex gap-2">
      <input class="form-control form-control-sm" type="email" name="q" style="min-width:240px"
             value="<?= $h($recherche) ?>" placeholder="Adresse email exacte">
      <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
      <?php if ($recherche !== ''): ?>
        <a class="btn btn-sm" href="comptes-coureurs.php">Tout</a>
      <?php endif; ?>
    </form>
  </header>

  <?php if ($recherche !== ''): ?>
    <p class="text-muted small mb-0">
      <i class="bi bi-info-circle me-1"></i>
      Recherche par adresse <strong>exacte</strong> : les adresses sont chiffrées en base,
      une recherche partielle est impossible.
    </p>
  <?php endif; ?>

  <?php if (!$comptes): ?>
    <div class="empty">
      <p><?= $recherche !== '' ? 'Aucun compte pour cette adresse.' : 'Aucun compte coureur pour le moment.' ?></p>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle">
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
                  <span class="pill is-danger">désactivé</span>
                <?php endif; ?>
                <?php if (empty($c['rgpd_consent_at'])): ?>
                  <span class="pill is-warn">RGPD non accepté</span>
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
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn btn-sm" name="envoyer_code" value="1" title="Renvoyer un code de connexion">
                  <i class="bi bi-envelope"></i>
                </button>
              </form>
              <?php if ((int) $c['nb_appareils'] > 0): ?>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Révoquer tous les appareils de ce compte ?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="btn btn-sm" name="revoquer_appareils" value="1" title="Révoquer les appareils">
                    <i class="bi bi-phone-flip"></i>
                  </button>
                </form>
              <?php endif; ?>
              <form method="post" class="d-inline"
                    onsubmit="return confirm(<?= $actif
                        ? "'Désactiver ce compte ? La personne ne pourra plus se connecter.'"
                        : "'Réactiver ce compte ?'" ?>);">
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

<?php
  /* Le compte de démonstration est-il déjà en place ? */
  $noDemo = 'DEMO-' . regres_activeYear($pdo);
  try {
      $st = $pdo->prepare('SELECT email FROM registrations WHERE inscription_no = ? LIMIT 1');
      $st->execute([$noDemo]);
      $ligneDemo = $st->fetchColumn();
      $mailDemoActuel = $ligneDemo === false ? null : decrypt((string) $ligneDemo);
  } catch (\Throwable $e) { $mailDemoActuel = null; }
?>
<div class="card">
  <header>
    <div class="iconwell"><i class="bi bi-easel"></i></div>
    <h2>Compte de démonstration</h2>
    <?php if ($mailDemoActuel !== null): ?>
      <span class="pill is-ok">en place</span>
    <?php endif; ?>
  </header>

  <p class="text-muted small mb-0">
    Pour montrer l'espace coureur — à un bénévole, à un partenaire, en réunion —
    sans ouvrir le compte d'un vrai participant et exposer son nom, son adresse et
    son inscription à des gens qui n'ont rien à y voir.
  </p>

  <?php if ($mailDemoActuel !== null): ?>
    <div class="alert alert-info">
      <i class="bi bi-info-circle me-2"></i>
      Rattaché à <strong><?= $h($mailDemoActuel) ?></strong>.
      Connectez-vous sur l'espace coureur avec cette adresse : vous recevrez un code
      par mail, comme n'importe quel participant.
    </div>
    <div class="alert alert-warning">
      <i class="bi bi-exclamation-triangle me-2"></i>
      <strong>Cette inscription factice apparaît dans le tableau de bord et compte
      dans les totaux</strong> (numéro <code><?= $h($noDemo) ?></code>, nom
      « DÉMONSTRATION »). Supprimez-la avant de communiquer des chiffres.
    </div>
    <form method="post" onsubmit="return confirm('Supprimer l\'inscription de démonstration ?');">
      <?= csrf_field() ?>
      <div class="row-actions">
        <button class="btn btn-sm btn-outline-danger" name="supprimer_demo" value="1">
          <i class="bi bi-trash3"></i> Supprimer l'inscription de démonstration
        </button>
      </div>
    </form>
  <?php else: ?>
    <form method="post" class="d-flex gap-2 flex-wrap align-items-end">
      <?= csrf_field() ?>
      <div class="field" style="max-width:320px">
        <label for="mailDemo">Adresse email que <strong>vous</strong> pouvez consulter</label>
        <input class="form-control form-control-sm" id="mailDemo" name="email_demo" type="email"
               required autocomplete="off" placeholder="vous@exemple.fr">
      </div>
      <button class="btn btn-sm btn-primary" name="creer_demo" value="1">
        <i class="bi bi-plus-lg"></i> Créer le compte de démonstration
      </button>
    </form>
    <p class="text-muted small mb-0">
      <i class="bi bi-shield-check me-1"></i>
      Aucun mécanisme de « connexion en tant que » n'est ajouté : ce serait une porte
      dérobée permanente dans l'espace coureur. Vous vous connectez normalement, avec
      un code reçu sur cette adresse.
    </p>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../src/partials/admin-footer.php'; ?>
</body>
</html>
