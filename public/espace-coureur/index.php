<?php
/**
 * index.php — « Mes inscriptions ».
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

$moi    = $_SESSION[PAUTH_SESSION_KEY];
$lignes = pauth_registrations($pdo, pauth_id());

/* Regroupement : édition → group_id (les inscriptions seules ont une clé propre) */
$parEdition = [];
foreach ($lignes as $r) {
    $annee = (int) $r['annee'];
    $grp   = trim((string) ($r['group_id'] ?? ''));
    $parEdition[$annee][$grp === '' ? '_seul_' . $r['inscription_no'] : $grp][] = $r;
}
krsort($parEdition);

/* Titre et surtitre de la barre supérieure de la coquille (cf. _layout-haut.php). */
$ecTitre    = 'Mes inscriptions';
$ecSurtitre = trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')) ?: ($moi['email'] ?? '');

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/** Pastille de paiement, à partir du montant et du mode. */
function ec_pillPaiement(array $r): string
{
    $montant = (float) ($r['montant_du'] ?? 0);
    $mode    = strtolower(trim((string) ($r['paiement_mode'] ?? '')));
    if ($montant <= 0 || $mode === 'gratuit') {
        return '<span class="pill is-ok">Gratuit</span>';
    }
    return '<span class="pill no-dot">' . htmlspecialchars($mode !== '' ? $mode : 'Réglé', ENT_QUOTES, 'UTF-8')
         . ' · ' . number_format($montant, 2, ',', ' ') . ' €</span>';
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Mes inscriptions</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php include __DIR__ . '/_styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_layout-haut.php'; ?>

  <?php if (!$parEdition): ?>
    <div class="card">
      <header>
        <div class="iconwell"><i class="bi bi-inbox"></i></div>
        <h2>Aucune inscription rattachée</h2>
      </header>
      <div class="empty">
        <p>Si vous vous êtes inscrit avec une autre adresse email, déconnectez-vous et
           reconnectez-vous avec celle-ci — c'est l'adresse qui fait le lien.</p>
        <div class="row-actions">
          <a class="btn" href="../faq.php"><i class="bi bi-question-circle"></i> Questions fréquentes</a>
          <a class="btn btn-primary" href="../register.php"><i class="bi bi-plus-lg"></i> S'inscrire</a>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php foreach ($parEdition as $annee => $groupes): ?>
    <?php foreach ($groupes as $membres): ?>
      <?php $estGroupe = count($membres) > 1; ?>
      <section class="card">
        <header>
          <div style="display:flex;align-items:center;gap:var(--sp-3);min-width:0">
            <div class="iconwell"><i class="bi <?= $estGroupe ? 'bi-people' : 'bi-person' ?>"></i></div>
            <div>
              <h2>Édition <?= (int) $annee ?></h2>
              <?php if ($estGroupe): ?>
                <div class="sub" style="font-size:var(--fs-small);color:var(--ink-faint)">
                  Inscription groupée — <?= count($membres) ?> personnes
                </div>
              <?php endif; ?>
            </div>
          </div>
          <span class="pill no-dot"><?= count($membres) ?> inscription<?= $estGroupe ? 's' : '' ?></span>
        </header>

        <div class="rows">
          <?php foreach ($membres as $r): ?>
            <div class="row">
              <div class="grow">
                <div class="title"><?= $h(trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? ''))) ?: 'Sans nom' ?></div>
                <div class="sub">
                  N° <span class="ec-mono"><?= $h($r['inscription_no']) ?></span>
                  <?php if (!empty($r['tshirt_size']) && $r['tshirt_size'] !== '-'): ?>
                    · T-shirt <?= $h($r['tshirt_size']) ?>
                  <?php endif; ?>
                  <?php if (!empty($r['ville'])): ?> · <?= $h($r['ville']) ?><?php endif; ?>
                </div>
              </div>
              <?= ec_pillPaiement($r) ?>
              <a class="btn"
                 href="inscription.php?annee=<?= (int) $r['annee'] ?>&amp;no=<?= urlencode((string) $r['inscription_no']) ?>">
                <i class="bi bi-eye"></i> Voir
              </a>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($estGroupe): ?>
          <p style="font-size:var(--fs-micro);color:var(--ink-faint);margin:0">
            <i class="bi bi-info-circle"></i>
            Ces personnes partagent votre adresse email. Pour que l'une d'elles ait son
            propre chronométrage, transférez son inscription depuis sa fiche.
          </p>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  <?php endforeach; ?>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
