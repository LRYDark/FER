<?php
/**
 * mes-resultats.php — Mes résultats.
 *
 * ⚠️ EMPLACEMENT PRÉPARÉ, VOLONTAIREMENT VIDE. Le chronométrage sera alimenté
 * par l'application mobile ; la carte de trace GPS, le classement, le profil
 * altimétrique et l'export sont explicitement hors périmètre de ce lot.
 *
 * La page interroge déjà `resultats` : le jour où la table sera alimentée,
 * l'affichage suivra sans changer la structure. Les colonnes `methode` et
 * `precision_s` sont lues dès maintenant — un temps extrapolé au GPS ne devra
 * JAMAIS être présenté comme équivalent à un temps beacon.
 */
define('FER_SESSION_COUREUR', true);
require '../../src/core/config.php';
checkMaintenance();
require_once '../../src/security/csrf.php';
require_once '../../src/auth/participant_auth.php';

pauth_require($pdo, 'mes-resultats.php');

$inscriptions = pauth_registrations($pdo, pauth_id());

/* Résultats éventuels, par clé métier. Aucun aujourd'hui : la requête est là
   pour que la page soit juste dès le premier enregistrement produit. */
$resultats = [];
if ($inscriptions) {
    $conds = [];
    $args  = [];
    foreach ($inscriptions as $r) {
        $conds[] = '(annee = ? AND inscription_no = ?)';
        $args[]  = (int) $r['annee'];
        $args[]  = (string) $r['inscription_no'];
    }
    try {
        $st = $pdo->prepare('SELECT * FROM resultats WHERE ' . implode(' OR ', $conds));
        $st->execute($args);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $res) {
            $resultats[$res['annee'] . '|' . $res['inscription_no']] = $res;
        }
    } catch (\Throwable $e) { /* table absente : rien à afficher */ }
}

/** Libellé honnête de la méthode de chronométrage. */
function ec_methode(?string $m): string
{
    return match ($m) {
        'beacon'        => 'Balise à la ligne — précision maximale',
        'gps_ligne'     => 'GPS au passage de la ligne',
        'gps_extrapole' => 'GPS extrapolé — temps approché',
        'gps_distance'  => 'GPS par la distance parcourue — temps approché',
        'manuel'        => "Saisi par l'organisation",
        'declaratif'    => 'Déclaré par le coureur',
        default         => 'Méthode non précisée',
    };
}

$ecTitre    = 'Mes résultats';
$ecSurtitre = 'Vos temps, édition par édition';

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Mes résultats</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php include __DIR__ . '/_styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_layout-haut.php'; ?>

  <?php if (!$inscriptions): ?>
    <section class="card">
      <header>
        <div class="iconwell"><i class="bi bi-stopwatch"></i></div>
        <h2>Rien à afficher</h2>
      </header>
      <div class="empty">
        <p>Aucune inscription n'est rattachée à ce compte, il n'y a donc pas de résultat.</p>
      </div>
    </section>
  <?php else: ?>
    <section class="card">
      <header>
        <div class="iconwell"><i class="bi bi-stopwatch"></i></div>
        <h2>Vos éditions</h2>
      </header>

      <div class="rows">
        <?php foreach ($inscriptions as $r): ?>
          <?php $res = $resultats[$r['annee'] . '|' . $r['inscription_no']] ?? null; ?>
          <div class="row">
            <div class="grow">
              <div class="title">Édition <?= (int) $r['annee'] ?></div>
              <div class="sub">
                <?= $h(trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? ''))) ?>
                · n° <span class="ec-mono"><?= $h($r['inscription_no']) ?></span>
              </div>
            </div>
            <?php if ($res === null || $res['temps_s'] === null): ?>
              <span class="pill is-warn">Chronométrage à venir</span>
            <?php else: ?>
              <?php
                $s = (float) $res['temps_s'];
                $chrono = sprintf('%d:%02d:%02d', (int) ($s / 3600), (int) ($s / 60) % 60, (int) $s % 60);
              ?>
              <div class="stat" style="align-items:flex-end">
                <span class="value"><?= $h($chrono) ?></span>
                <?php /* La méthode et la précision accompagnent TOUJOURS le temps :
                         un temps extrapolé affiché nu passerait pour une mesure. */ ?>
                <span class="delta">
                  <?= $h(ec_methode($res['methode'])) ?>
                  <?php if ($res['precision_s'] !== null): ?> · ±<?= (int) $res['precision_s'] ?> s<?php endif; ?>
                </span>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <div class="alert">
      <i class="bi bi-cone-striped"></i>
      <strong>Le chronométrage n'est pas encore actif.</strong>
      Il arrivera avec l'application mobile. Vous retrouverez alors ici votre temps,
      la façon dont il a été mesuré, et le tracé de votre parcours.
    </div>
  <?php endif; ?>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
