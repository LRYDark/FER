<?php
/**
 * transfert.php — Page d'acceptation d'un transfert d'inscription (lot 4).
 *
 * ⚠️ LE GET N'ACCEPTE RIEN. Il affiche la demande ; c'est le POST qui l'accepte.
 * Les antivirus de messagerie et les proxys d'entreprise visitent les liens des
 * mails entrants : une acceptation déclenchée sur GET le serait par un robot,
 * avant même que le destinataire n'ait ouvert son message.
 *
 * La page est accessible SANS être connecté : la cible n'a pas forcément de
 * compte, et lui imposer d'en créer un avant d'accepter ajouterait une étape
 * pour rien — le jeton reçu par mail prouve déjà la possession de l'adresse.
 */
define('FER_SESSION_COUREUR', true);
require '../../src/core/config.php';
checkMaintenance();
require_once '../../src/security/csrf.php';
require_once '../../src/auth/participant_auth.php';
require_once '../../src/content/transfers.php';

xfer_purge($pdo);

$token   = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$t       = xfer_parToken($pdo, $token);
$erreur  = '';
$accepte = false;

if ($t === null) {
    $erreur = "Ce lien n'est pas valable. Il a peut-être déjà servi.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $erreur = "Session expirée. Rechargez la page et réessayez.";
    } elseif (isset($_POST['accepter'])) {
        $res = xfer_accepter($pdo, $token);
        if ($res['ok']) {
            $accepte = true;
            xfer_mailsConfirmation($pdo, $t);
        } else {
            $erreur = $res['erreur'];
        }
    }
}

/* Inscription concernée — affichée pour que la cible sache ce qu'elle accepte. */
$insc = $t !== null ? regres_find($pdo, (int) $t['annee'], (string) $t['inscription_no']) : null;

$authBase       = '../../';
$authThemeKey   = PAUTH_THEME_KEY;
/* Accent propre au coureur, résolu par la même fonction que les pages
   internes. theme.js aurait sinon appliqué celui de l'administrateur. */
$authAccentRes  = pauth_accentVars($pdo);
$authAccentVars = $authAccentRes['vars'];
$authAccentData = $authAccentRes['data'];
$authArtKicker  = 'Forbach en Rose · Espace coureur';
$authArtTitre   = 'Une inscription<br>vous attend.';

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Forbach en Rose — Transfert d'inscription</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php include __DIR__ . '/../../src/partials/auth-head.php'; ?>
</head>
<body>
<div class="auth">
  <div class="auth-frame">
    <div class="auth-pane">
      <a class="brand" href="../accueil.php">
        <?php if (file_exists(dirname(__DIR__, 2) . '/files/_logos/logo_fer_rose.png')): ?>
          <img src="../../files/_logos/logo_fer_rose.png" alt="" style="height:32px;width:auto">
        <?php endif; ?>
        <span class="name">Forbach en Rose</span>
      </a>

      <div class="inner">
        <div class="oc-icon-area">
          <div class="oc-icon-circle">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M8 7h12m0 0l-4-4m4 4l-4 4M16 17H4m0 0l4 4m-4-4l4-4"/>
            </svg>
          </div>
          <h1 class="oc-title"><?= $accepte ? "C'est fait" : "Transfert d'inscription" ?></h1>
          <p class="oc-subtitle">
            <?= $accepte
                  ? "L'inscription est désormais rattachée à votre adresse."
                  : "Quelqu'un souhaite vous transférer son inscription." ?>
          </p>
        </div>

        <?php if ($erreur !== ''): ?>
          <div class="oc-alert oc-alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= $h($erreur) ?></div>
          <p style="margin-top:var(--sp-4);text-align:center">
            <a class="oc-back" href="login.php">Aller à mon espace coureur</a>
          </p>

        <?php elseif ($accepte): ?>
          <div class="oc-alert oc-alert-success">
            <i class="bi bi-check-circle me-1"></i>
            L'inscription n° <strong><?= $h($t['inscription_no']) ?></strong> vous appartient.
          </div>
          <p class="oc-form-hint" style="line-height:1.6">
            Connectez-vous avec <strong><?= $h($t['email_cible']) ?></strong> pour retrouver
            votre QR code et suivre votre inscription. Un code à 6 chiffres vous sera envoyé,
            il n'y a pas de mot de passe à retenir.
          </p>
          <p style="margin-top:var(--sp-4)">
            <a class="oc-btn" href="login.php"><i class="bi bi-box-arrow-in-right"></i> Me connecter</a>
          </p>

        <?php else: ?>
          <div class="oc-card" style="text-align:left">
            <dl style="display:grid;grid-template-columns:auto 1fr;gap:var(--sp-2) var(--sp-4);
                       font-size:var(--fs-small);margin:0">
              <dt style="color:var(--ink-faint)">Inscription</dt>
              <dd style="margin:0;font-weight:600"><?= $h($t['inscription_no']) ?></dd>
              <?php if ($insc !== null): ?>
                <dt style="color:var(--ink-faint)">Au nom de</dt>
                <dd style="margin:0;font-weight:600">
                  <?= $h(trim(($insc['prenom'] ?? '') . ' ' . ($insc['nom'] ?? ''))) ?: '—' ?>
                </dd>
              <?php endif; ?>
              <dt style="color:var(--ink-faint)">Édition</dt>
              <dd style="margin:0;font-weight:600"><?= (int) $t['annee'] ?></dd>
              <dt style="color:var(--ink-faint)">Proposée par</dt>
              <dd style="margin:0;font-weight:600"><?= $h($t['email_source']) ?></dd>
              <dt style="color:var(--ink-faint)">Pour</dt>
              <dd style="margin:0;font-weight:600"><?= $h($t['email_cible']) ?></dd>
            </dl>
          </div>

          <p class="oc-form-hint" style="line-height:1.6;margin-top:var(--sp-3)">
            En acceptant, cette inscription quitte l'espace de son titulaire actuel
            et rejoint le vôtre. Vous en aurez la charge&nbsp;: QR code, retrait du
            t-shirt, et chronométrage le jour venu.
          </p>

          <form method="post" style="margin-top:var(--sp-4)">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= $h($token) ?>">
            <button class="oc-btn" type="submit" name="accepter" value="1">
              <i class="bi bi-check2"></i> J'accepte ce transfert
            </button>
          </form>

          <p style="margin-top:var(--sp-3);text-align:center">
            <a class="oc-back" href="../accueil.php">Ce n'est pas pour moi</a>
          </p>
        <?php endif; ?>
      </div><!-- /inner -->
    </div><!-- /auth-pane -->
    <?php include __DIR__ . '/../../src/partials/auth-art.php'; ?>
  </div><!-- /auth-frame -->
</div><!-- /auth -->
</body>
</html>
