<?php
/**
 * index.php — Accueil de l'espace coureur.
 *
 * ⚠️ VERSION MINIMALE DU LOT 2 : elle ne sert qu'à constater que la connexion
 * fonctionne et que les inscriptions ont bien été rattachées au compte.
 * Le lot 3 la remplace par la vraie page « Mes inscriptions » (cartes par
 * édition, inscriptions groupées, QR code, transferts).
 */
define('FER_SESSION_COUREUR', true);
require '../../src/core/config.php';
checkMaintenance();
require_once '../../src/security/csrf.php';
require_once '../../src/auth/participant_auth.php';

pauth_require($pdo, 'index.php');

// Rattachements du compte, toutes éditions confondues.
$st = $pdo->prepare(
    'SELECT annee, inscription_no, origine, revendique_at
       FROM participant_registrations
      WHERE participant_id = ?
      ORDER BY annee DESC, inscription_no ASC'
);
$st->execute([pauth_id()]);
$liens = $st->fetchAll(PDO::FETCH_ASSOC);

$moi = $_SESSION[PAUTH_SESSION_KEY];
$h   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Mes inscriptions</title>
<link rel="stylesheet" href="../../css/tokens.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .ec-page { max-width:760px; margin:0 auto; padding:28px 16px 40px; }
  .ec-title { font-size:1.35rem; font-weight:700; color:#0f172a; margin:0 0 4px; }
  .ec-sub { font-size:.9rem; color:#64748b; margin:0 0 22px; }
  .ec-card { background:#fff; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,.06);
             padding:16px 18px; margin-bottom:12px; }
  .ec-no { font-family:monospace; font-weight:700; color:#F42182; }
  .ec-meta { font-size:.82rem; color:#64748b; margin-top:4px; }
  .ec-empty { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe;
              border-radius:.6rem; padding:14px 16px; font-size:.9rem; line-height:1.6; }
  .ec-note { background:#fffbeb; color:#92400e; border:1px solid #fde68a;
             border-radius:.6rem; padding:12px 14px; font-size:.82rem; margin-top:22px; }
</style>
</head>
<body>
<?php include __DIR__ . '/_layout-haut.php'; ?>

<div class="ec-page">
  <h1 class="ec-title">Bonjour <?= $h(trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? ''))) ?: 'et bienvenue' ?></h1>
  <p class="ec-sub"><?= $h($moi['email'] ?? '') ?></p>

  <?php if (!$liens): ?>
    <div class="ec-empty">
      Aucune inscription n'est rattachée à ce compte pour le moment.
      Si vous vous êtes inscrit avec une autre adresse, reconnectez-vous avec celle-ci.
    </div>
  <?php else: ?>
    <?php foreach ($liens as $l): ?>
      <div class="ec-card">
        <span class="ec-no"><?= $h($l['inscription_no']) ?></span>
        &nbsp;—&nbsp;édition <?= (int) $l['annee'] ?>
        <div class="ec-meta">
          Rattachée le <?= $h(date('d/m/Y', strtotime((string) $l['revendique_at']))) ?>
          (<?= $h($l['origine']) ?>)
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="ec-note">
    <i class="bi bi-cone-striped me-1"></i>
    Page provisoire du lot 2&nbsp;: elle confirme que la connexion et le rattachement
    fonctionnent. Le détail des inscriptions, le QR code et les transferts arrivent au lot 3.
  </div>
</div>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
