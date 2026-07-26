<?php
/**
 * index.php — « Mes inscriptions » (lot 3).
 *
 * Toutes les inscriptions rattachées au compte, TOUTES ÉDITIONS CONFONDUES,
 * groupées par édition (la plus récente en premier), puis par `group_id` :
 * c'est le cas classique du parent qui inscrit toute la famille sous sa propre
 * adresse. C'est précisément là que le bouton « Transférer » doit se voir.
 */
define('FER_SESSION_COUREUR', true);
require '../../src/core/config.php';
checkMaintenance();
require_once '../../src/security/csrf.php';
require_once '../../src/auth/participant_auth.php';

pauth_require($pdo, 'index.php');

$moi     = $_SESSION[PAUTH_SESSION_KEY];
$lignes  = pauth_registrations($pdo, pauth_id());

/* Regroupement : édition → group_id (les inscriptions seules ont la clé '') */
$parEdition = [];
foreach ($lignes as $r) {
    $annee = (int) $r['annee'];
    $grp   = trim((string) ($r['group_id'] ?? ''));
    $parEdition[$annee][$grp === '' ? '_seul_' . $r['inscription_no'] : $grp][] = $r;
}
krsort($parEdition);

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/** Badge de paiement, à partir du montant et du mode. */
function ec_badgePaiement(array $r): string
{
    $montant = (float) ($r['montant_du'] ?? 0);
    $mode    = strtolower(trim((string) ($r['paiement_mode'] ?? '')));
    if ($montant <= 0 || $mode === 'gratuit') {
        return '<span class="ec-tag ec-tag-ok">Gratuit</span>';
    }
    return '<span class="ec-tag">' . htmlspecialchars($mode !== '' ? $mode : 'Payé', ENT_QUOTES, 'UTF-8')
         . ' — ' . number_format($montant, 2, ',', ' ') . ' €</span>';
}
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
<?php include __DIR__ . '/_styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_layout-haut.php'; ?>

<div class="ec-page">
  <h1 class="ec-h1">
    Bonjour <?= $h(trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? ''))) ?: 'et bienvenue' ?>
  </h1>
  <p class="ec-sub"><?= $h($moi['email'] ?? '') ?></p>

  <?php if (!$parEdition): ?>
    <div class="ec-alert ec-info">
      <strong>Aucune inscription rattachée à ce compte.</strong><br>
      Si vous vous êtes inscrit avec une autre adresse email, déconnectez-vous et
      reconnectez-vous avec celle-ci — c'est l'adresse qui fait le lien.
      <div class="ec-actions">
        <a class="ec-btn ec-btn-sec" href="../faq.php">Questions fréquentes</a>
        <a class="ec-btn" href="../register.php">S'inscrire à la course</a>
      </div>
    </div>
  <?php endif; ?>

  <?php foreach ($parEdition as $annee => $groupes): ?>
    <h2 class="ec-h2">
      <i class="bi bi-calendar-event"></i>Édition <?= (int) $annee ?>
      <span class="ec-tag"><?= array_sum(array_map('count', $groupes)) ?> inscription(s)</span>
    </h2>

    <?php foreach ($groupes as $cle => $membres): ?>
      <?php $estGroupe = count($membres) > 1; ?>
      <div class="ec-card <?= $estGroupe ? 'ec-groupe' : '' ?>">
        <?php if ($estGroupe): ?>
          <div class="ec-groupe-tete">
            <i class="bi bi-people-fill"></i>
            Inscription groupée — <?= count($membres) ?> personnes
          </div>
        <?php endif; ?>

        <?php foreach ($membres as $i => $r): ?>
          <?php if ($i > 0): ?><hr style="border:0;border-top:1px solid #f1f5f9;margin:12px 0"><?php endif; ?>
          <div class="ec-row">
            <div>
              <div class="ec-nom"><?= $h(trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? ''))) ?: 'Sans nom' ?></div>
              <div class="ec-meta">
                N° <span class="ec-no"><?= $h($r['inscription_no']) ?></span>
                <?php if (!empty($r['tshirt_size']) && $r['tshirt_size'] !== '-'): ?>
                  &nbsp;·&nbsp;T-shirt <?= $h($r['tshirt_size']) ?>
                <?php endif; ?>
                <?php if (!empty($r['ville'])): ?>&nbsp;·&nbsp;<?= $h($r['ville']) ?><?php endif; ?>
              </div>
              <div class="ec-meta" style="margin-top:6px"><?= ec_badgePaiement($r) ?></div>
            </div>
            <div class="ec-actions" style="margin:0">
              <a class="ec-btn ec-btn-sec"
                 href="inscription.php?annee=<?= (int) $r['annee'] ?>&amp;no=<?= urlencode((string) $r['inscription_no']) ?>">
                <i class="bi bi-eye"></i>Voir
              </a>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if ($estGroupe): ?>
          <div class="ec-meta" style="margin-top:12px">
            <i class="bi bi-info-circle me-1"></i>
            Ces personnes partagent votre adresse email. Pour que l'une d'elles ait son
            propre chronométrage, transférez son inscription depuis sa fiche.
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
