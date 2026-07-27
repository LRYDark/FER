<?php
/**
 * inscription.php — Détail d'une inscription, avec son QR code.
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
require_once '../../src/auth/participant_profile.php';
require_once '../../src/core/qrcode.php';
require_once '../../src/content/transfers.php';

pauth_require($pdo, 'index.php');

$annee = (int) ($_GET['annee'] ?? 0);
$no    = trim((string) ($_GET['no'] ?? ''));
$r     = null;

if ($annee <= 0 || $no === '' || !pauth_owns($pdo, pauth_id(), $annee, $no)) {
    http_response_code(403);
    $interdit = true;
} else {
    $interdit = false;
    $r = regres_find($pdo, $annee, $no);
    if ($r === null) http_response_code(404);
}

/* ── Transferts ──────────────────────────────────────────────────────────── */
$xfErreur = '';
$xfSucces = '';

/* ── Correction du sexe et de l'âge ──────────────────────────────────────── */
$edErreur = '';
$edSucces = '';

if (!$interdit && $r !== null) {
    xfer_purge($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            $xfErreur = "Session expirée. Rechargez la page et réessayez.";
        }
        elseif (isset($_POST['maj_detail'])) {
            // Contrôles dans participant_profile.php, partagés avec l'API :
            // édition archivée, course démarrée, valeurs acceptées.
            $res = pprofile_majInscription($pdo, pauth_id(), $annee, $no,
                (string) ($_POST['sexe'] ?? ''), (string) ($_POST['age'] ?? ''));
            if ($res['ok']) {
                $edSucces = $res['message'];
                $r = regres_find($pdo, $annee, $no) ?? $r;   // relecture : on affiche l'état réel
            } else {
                $edErreur = $res['erreur'];
            }
        }
        elseif (isset($_POST['transferer'])) {
            $res = xfer_creer($pdo, pauth_id(), $annee, $no, (string) ($_POST['email_cible'] ?? ''));
            if ($res['ok']) {
                $t = xfer_parToken($pdo, $res['token']);
                // Les deux mails : la demande à la cible, l'information au titulaire.
                xfer_mailCible($pdo, $t, $res['token'], xfer_lienBase());
                xfer_mailSource($pdo, $t);
                $xfSucces = "Demande envoyée. La personne doit confirmer depuis son mail ; "
                          . "vous pouvez annuler tant qu'elle ne l'a pas fait.";
            } else {
                $xfErreur = $res['erreur'];
            }
        }
        elseif (isset($_POST['annuler_transfert'])) {
            $res = xfer_annuler($pdo, (int) $_POST['annuler_transfert'], pauth_id());
            if ($res['ok']) $xfSucces = "Demande de transfert annulée.";
            else            $xfErreur = $res['erreur'];
        }
    }
}

$xfEnAttente = (!$interdit && $r !== null) ? xfer_enAttente($pdo, $annee, $no) : null;
if ($xfEnAttente !== null) $xfEnAttente['email_cible'] = decrypt($xfEnAttente['email_cible']);

$xfEditionActive  = !$interdit && $annee === regres_activeYear($pdo);
$xfDeadline       = $xfEditionActive ? xfer_deadline($pdo, $annee) : null;
$xfDeadlinePassee = $xfEditionActive && xfer_deadlinePassee($pdo, $annee);

/* Le formulaire de correction n'apparaît que s'il peut aboutir : proposer un
   champ qui sera refusé à l'enregistrement est pire que ne rien proposer. */
$edModifiable = !$interdit && $r !== null
             && pprofile_tableModifiable($pdo, $annee) !== null
             && !pprofile_courseDemarree($pdo, $annee);

/* Titre de la barre supérieure : le nom du coureur quand la fiche est
   accessible, un intitulé neutre sinon — inutile de révéler quoi que ce soit
   sur une inscription à laquelle on n'a pas droit. */
$ecTitre = $r !== null
    ? (trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')) ?: 'Inscription')
    : 'Inscription';
$ecSurtitre = $r !== null ? 'Édition ' . $annee . ' · n° ' . $no : '';

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Inscription <?= $h($no) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php include __DIR__ . '/_styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_layout-haut.php'; ?>

  <?php if ($interdit): ?>
    <div class="card">
      <header>
        <div class="iconwell" style="background:var(--danger-soft);color:var(--danger)">
          <i class="bi bi-shield-exclamation"></i>
        </div>
        <h2>Accès refusé</h2>
      </header>
      <div class="empty">
        <p>Cette inscription n'est pas rattachée à votre compte.</p>
        <a class="btn" href="index.php"><i class="bi bi-arrow-left"></i> Mes inscriptions</a>
      </div>
    </div>

  <?php elseif ($r === null): ?>
    <div class="card">
      <header>
        <div class="iconwell" style="background:var(--warn-soft);color:var(--warn)">
          <i class="bi bi-question-circle"></i>
        </div>
        <h2>Inscription introuvable</h2>
      </header>
      <div class="empty">
        <p>Elle est rattachée à votre compte mais n'existe plus dans la base.
           Signalez-le à l'organisation.</p>
        <a class="btn" href="index.php"><i class="bi bi-arrow-left"></i> Mes inscriptions</a>
      </div>
    </div>

  <?php else: ?>
    <?php /* Sur ordinateur, le détail et le QR code se lisent d'un seul coup
             d'œil, côte à côte. En dessous de 900 px, la grille repasse à une
             colonne : un QR de 200 px et une liste de champs ne tiennent pas
             l'un à côté de l'autre sur un téléphone. */ ?>
    <div class="ec-duo">
    <section class="card">
      <header>
        <div class="iconwell"><i class="bi bi-card-list"></i></div>
        <h2>Détail de l'inscription</h2>
      </header>

      <?php if ($edErreur !== ''): ?>
        <div class="alert is-danger"><i class="bi bi-exclamation-triangle"></i> <?= $h($edErreur) ?></div>
      <?php endif; ?>
      <?php if ($edSucces !== ''): ?>
        <div class="alert is-ok"><i class="bi bi-check-circle"></i> <?= $h($edSucces) ?></div>
      <?php endif; ?>

      <dl class="ec-dl">
        <dt>Numéro</dt><dd class="ec-mono"><?= $h($r['inscription_no']) ?></dd>
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
                : '<span class="pill no-dot">non attribué</span>' ?></dd>
        <?php if (!empty($r['entreprise'])): ?>
          <dt>Équipe</dt><dd><?= $h($r['entreprise']) ?></dd>
        <?php endif; ?>
        <dt>Paiement</dt>
        <dd><?php
          $montant = (float) ($r['montant_du'] ?? 0);
          $mode    = trim((string) ($r['paiement_mode'] ?? ''));
          echo $montant <= 0 || strtolower($mode) === 'gratuit'
            ? '<span class="pill is-ok">Gratuit</span>'
            : $h($mode !== '' ? $mode : 'Réglé') . ' · ' . number_format($montant, 2, ',', ' ') . ' €';
        ?></dd>
        <?php if (!empty($r['date_inscription'])): ?>
          <dt>Inscrit le</dt>
          <dd><?= $h(date('d/m/Y', strtotime((string) $r['date_inscription']))) ?></dd>
        <?php endif; ?>
      </dl>

      <?php if ($edModifiable): ?>
        <?php /* Replié par défaut : la fiche se lit d'abord, on ne la corrige
                 qu'exceptionnellement. Un formulaire ouvert en permanence
                 donnerait à croire qu'il y a quelque chose à remplir. */ ?>
        <details class="ec-edit">
          <summary><i class="bi bi-pencil"></i> Corriger mon sexe ou mon âge</summary>

          <form method="post">
            <?= csrf_field() ?>
            <div class="ec-grid2">
              <div class="field">
                <label for="ecSexe">Sexe</label>
                <select class="input" id="ecSexe" name="sexe">
                  <?php foreach (['H' => 'Homme', 'F' => 'Femme', 'Autre' => 'Autre'] as $v => $lib): ?>
                    <option value="<?= $v ?>"<?= ($r['sexe'] ?? '') === $v ? ' selected' : '' ?>>
                      <?= $lib ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="ecAge">Âge</label>
                <input class="input" id="ecAge" name="age" type="number" min="3" max="110"
                       inputmode="numeric" value="<?= $h((string) (regres_age($r) ?? '')) ?>">
              </div>
            </div>
            <div class="row-actions" style="margin-top:var(--sp-4)">
              <button class="btn btn-primary" type="submit" name="maj_detail" value="1">
                <i class="bi bi-check2"></i> Enregistrer
              </button>
            </div>
          </form>

          <p style="font-size:var(--fs-micro);color:var(--ink-faint);margin:var(--sp-3) 0 0">
            <i class="bi bi-info-circle"></i>
            Sexe et âge déterminent votre catégorie de classement&nbsp;: ils ne sont
            plus modifiables une fois le départ donné. Seul l'âge est conservé, jamais
            votre date de naissance.
          </p>
        </details>
      <?php endif; ?>
    </section>

    <?php /* Le QR vient de src/core/qrcode.php — la MÊME fonction que pour le
             mail. Mêmes données encodées, mêmes paramètres : ce que le bénévole
             scanne ici est identique à ce qui a été envoyé. */ ?>
    <section class="card">
      <header>
        <div class="iconwell"><i class="bi bi-qr-code"></i></div>
        <h2>Votre QR code</h2>
      </header>
      <?php $qr = fer_qrCodeDataUri($r['inscription_no']); ?>
      <?php if ($qr !== ''): ?>
        <div class="ec-qr">
          <img src="<?= $qr ?>" alt="QR code de l'inscription <?= $h($no) ?>">
          <p style="font-size:var(--fs-micro);color:var(--ink-faint);text-align:center;margin:0">
            Présentez-le au retrait des t-shirts.<br>
            Il est identique à celui de votre mail de confirmation.
          </p>
        </div>
      <?php else: ?>
        <div class="alert">
          Le QR code n'a pas pu être généré. Votre numéro d'inscription
          (<span class="ec-mono"><?= $h($no) ?></span>) suffit au retrait.
        </div>
      <?php endif; ?>
    </section>
    </div><?php /* .ec-duo */ ?>

    <section class="card">
      <header>
        <div class="iconwell"><i class="bi bi-arrow-left-right"></i></div>
        <h2>Transférer cette inscription</h2>
        <?php if ($xfEnAttente !== null): ?>
          <span class="pill is-warn">transfert en attente</span>
        <?php endif; ?>
      </header>

      <?php if ($xfErreur !== ''): ?>
        <div class="alert is-danger"><i class="bi bi-exclamation-triangle"></i> <?= $h($xfErreur) ?></div>
      <?php endif; ?>
      <?php if ($xfSucces !== ''): ?>
        <div class="alert is-ok"><i class="bi bi-check-circle"></i> <?= $h($xfSucces) ?></div>
      <?php endif; ?>

      <?php if ($xfEnAttente !== null): ?>
        <p style="font-size:var(--fs-small);color:var(--ink-dim);margin:0">
          En attente de confirmation par
          <strong><?= $h($xfEnAttente['email_cible']) ?></strong>.
          Sans réponse de sa part, la demande expire le
          <?= $h(date('d/m/Y', strtotime((string) $xfEnAttente['expires_at']))) ?>
          et l'inscription reste la vôtre.
        </p>
        <form method="post"
              data-confirm="Annuler cette demande de transfert ?">
          <?= csrf_field() ?>
          <div class="row-actions">
            <button class="btn btn-danger" type="submit"
                    name="annuler_transfert" value="<?= (int) $xfEnAttente['id'] ?>">
              <i class="bi bi-x-circle"></i> Annuler la demande
            </button>
          </div>
        </form>

      <?php elseif (!$xfEditionActive): ?>
        <div class="alert">
          <i class="bi bi-info-circle"></i>
          Cette édition est terminée&nbsp;: une inscription passée ne se transfère plus.
        </div>

      <?php elseif ($xfDeadlinePassee): ?>
        <div class="alert is-warn">
          <i class="bi bi-hourglass-bottom"></i>
          La date limite de transfert est dépassée
          (<?= $h(date('d/m/Y à H:i', strtotime((string) $xfDeadline))) ?>).
          Contactez l'organisation si c'est un cas particulier.
        </div>

      <?php else: ?>
        <p style="font-size:var(--fs-small);color:var(--ink-dim);margin:0">
          Si cette inscription concerne quelqu'un d'autre — un membre de votre famille
          inscrit sous votre adresse — basculez-la sur sa propre adresse email, pour
          qu'il ait son espace et son chronométrage.
        </p>

        <?php /* La conséquence est annoncée AVANT la demande, pas après : c'est le
                 moment où elle peut encore changer la décision. */ ?>
        <div class="alert is-warn">
          <i class="bi bi-exclamation-triangle"></i>
          <strong>Vous perdrez l'accès à cette inscription.</strong>
          Une fois le transfert accepté, elle n'apparaîtra plus dans votre espace :
          c'est la personne destinataire qui en aura la charge.
        </div>

        <form method="post"
              data-confirm="Envoyer la demande de transfert ? Vous pourrez l'annuler tant qu'elle n'est pas acceptée.">
          <?= csrf_field() ?>
          <div class="field" style="max-width:340px">
            <label for="ecCible">Adresse email du destinataire</label>
            <input class="input" id="ecCible" name="email_cible" type="email" required
                   autocomplete="off" placeholder="personne@exemple.fr">
          </div>
          <div class="row-actions" style="margin-top:var(--sp-4)">
            <button class="btn btn-primary" type="submit" name="transferer" value="1">
              <i class="bi bi-send"></i> Envoyer la demande
            </button>
          </div>
        </form>

        <?php if ($xfDeadline !== null): ?>
          <p style="font-size:var(--fs-micro);color:var(--ink-faint);margin:0">
            Les transferts sont possibles jusqu'au
            <?= $h(date('d/m/Y à H:i', strtotime($xfDeadline))) ?>.
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <div class="row-actions">
      <a class="btn" href="index.php"><i class="bi bi-arrow-left"></i> Mes inscriptions</a>
    </div>
  <?php endif; ?>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
