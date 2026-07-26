<?php
/**
 * inscription.php — Détail d'une inscription, avec son QR code (lot 3).
 *
 * Identifiée par sa CLÉ MÉTIER (annee, inscription_no) et non par un id
 * technique : les identifiants changent de table à chaque archivage annuel.
 *
 * ⚠️ CONTRÔLE D'ACCÈS : on vérifie que l'inscription appartient bien au compte
 * connecté, via participant_registrations. Sans ce test, changer un chiffre
 * dans l'URL afficherait la fiche de n'importe quel autre coureur.
 */
define('FER_SESSION_COUREUR', true);
require '../../src/core/config.php';
checkMaintenance();
require_once '../../src/security/csrf.php';
require_once '../../src/auth/participant_auth.php';
require_once '../../src/core/qrcode.php';

pauth_require($pdo, 'index.php');

$annee = (int) ($_GET['annee'] ?? 0);
$no    = trim((string) ($_GET['no'] ?? ''));

if ($annee <= 0 || $no === '' || !pauth_owns($pdo, pauth_id(), $annee, $no)) {
    http_response_code(403);
    $interdit = true;
} else {
    $interdit = false;
    $r = regres_find($pdo, $annee, $no);
    if ($r === null) { http_response_code(404); }
}

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Inscription <?= $h($no) ?></title>
<link rel="stylesheet" href="../../css/tokens.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php include __DIR__ . '/_styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_layout-haut.php'; ?>

<div class="ec-page">
  <?php if ($interdit): ?>
    <div class="ec-alert ec-err">
      <strong>Accès refusé.</strong> Cette inscription n'est pas rattachée à votre compte.
    </div>
    <a class="ec-btn ec-btn-sec" href="index.php"><i class="bi bi-arrow-left"></i>Mes inscriptions</a>

  <?php elseif ($r === null): ?>
    <div class="ec-alert ec-warn">
      <strong>Inscription introuvable.</strong> Elle est rattachée à votre compte mais
      n'existe plus dans la base. Signalez-le à l'organisation.
    </div>
    <a class="ec-btn ec-btn-sec" href="index.php"><i class="bi bi-arrow-left"></i>Mes inscriptions</a>

  <?php else: ?>
    <h1 class="ec-h1"><?= $h(trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? ''))) ?: 'Inscription' ?></h1>
    <p class="ec-sub">Édition <?= (int) $annee ?> · n° <span class="ec-no"><?= $h($no) ?></span></p>

    <div class="ec-card">
      <dl class="ec-dl">
        <dt>Numéro</dt><dd class="ec-no"><?= $h($r['inscription_no']) ?></dd>
        <dt>Édition</dt><dd><?= (int) $annee ?></dd>
        <?php if (!empty($r['ville'])): ?>
          <dt>Ville</dt><dd><?= $h($r['ville']) ?></dd>
        <?php endif; ?>
        <?php $age = regres_age($r); if ($age !== null): ?>
          <dt>Âge à l'édition</dt><dd><?= (int) $age ?> ans</dd>
        <?php endif; ?>
        <?php if (!empty($r['sexe'])): ?>
          <dt>Catégorie</dt>
          <dd><?= $r['sexe'] === 'H' ? 'Homme' : ($r['sexe'] === 'F' ? 'Femme' : 'Autre') ?></dd>
        <?php endif; ?>
        <dt>T-shirt</dt>
        <dd><?= (!empty($r['tshirt_size']) && $r['tshirt_size'] !== '-')
                ? $h($r['tshirt_size'])
                : '<span class="ec-tag">non attribué</span>' ?></dd>
        <?php if (!empty($r['entreprise'])): ?>
          <dt>Équipe</dt><dd><?= $h($r['entreprise']) ?></dd>
        <?php endif; ?>
        <dt>Paiement</dt>
        <dd><?php
          $montant = (float) ($r['montant_du'] ?? 0);
          $mode    = trim((string) ($r['paiement_mode'] ?? ''));
          echo $montant <= 0 || strtolower($mode) === 'gratuit'
            ? '<span class="ec-tag ec-tag-ok">Gratuit</span>'
            : $h($mode !== '' ? $mode : 'Réglé') . ' — ' . number_format($montant, 2, ',', ' ') . ' €';
        ?></dd>
        <?php if (!empty($r['date_inscription'])): ?>
          <dt>Inscrit le</dt>
          <dd><?= $h(date('d/m/Y', strtotime((string) $r['date_inscription']))) ?></dd>
        <?php endif; ?>
      </dl>
    </div>

    <?php /* Le QR code vient de src/core/qrcode.php — la MÊME fonction que celle
             utilisée pour le mail. Mêmes données encodées, mêmes paramètres :
             ce que le bénévole scanne ici est identique à ce qui a été envoyé. */ ?>
    <h2 class="ec-h2"><i class="bi bi-qr-code"></i>Votre QR code</h2>
    <div class="ec-card">
      <?php $qr = fer_qrCodeDataUri($r['inscription_no']); ?>
      <?php if ($qr !== ''): ?>
        <div class="ec-qr">
          <img src="<?= $qr ?>" alt="QR code de l'inscription <?= $h($no) ?>">
          <div class="ec-meta" style="margin-top:10px">
            Présentez-le au retrait des t-shirts. Il est identique à celui de votre
            mail de confirmation.
          </div>
        </div>
      <?php else: ?>
        <div class="ec-alert ec-warn" style="margin:0">
          Le QR code n'a pas pu être généré. Votre numéro d'inscription
          (<span class="ec-no"><?= $h($no) ?></span>) suffit au retrait.
        </div>
      <?php endif; ?>
    </div>

    <h2 class="ec-h2"><i class="bi bi-arrow-left-right"></i>Transférer cette inscription</h2>
    <div class="ec-card">
      <p class="ec-meta" style="margin:0 0 12px">
        Si cette inscription concerne quelqu'un d'autre — un membre de votre famille
        inscrit sous votre adresse — vous pourrez la basculer sur sa propre adresse
        email, pour qu'il ait son espace et son chronométrage.
      </p>
      <button class="ec-btn ec-btn-sec" type="button" disabled
              title="Disponible prochainement">
        <i class="bi bi-hourglass-split"></i>Transfert — bientôt disponible
      </button>
    </div>

    <div class="ec-actions">
      <a class="ec-btn ec-btn-sec" href="index.php"><i class="bi bi-arrow-left"></i>Mes inscriptions</a>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
