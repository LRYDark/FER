<?php
/**
 * consentement.php — Acceptation RGPD, exigée à la première connexion.
 *
 * pauth_require() redirige ici tant que `rgpd_consent_at` est vide : aucune
 * donnée personnelle n'est affichée avant. La version acceptée est enregistrée
 * (`participant_rgpd_version`), afin qu'une évolution de la politique puisse
 * exiger un nouveau consentement plutôt que de le présumer acquis.
 *
 * Même charte que la connexion : c'est la suite immédiate du même parcours.
 */
define('FER_SESSION_COUREUR', true);
require '../../src/core/config.php';
checkMaintenance();
require_once '../../src/security/csrf.php';
require_once '../../src/auth/participant_auth.php';

// Connexion exigée, mais SANS le contrôle de consentement : c'est la page qui
// le recueille, la renvoyer sur elle-même créerait une boucle.
if (!pauth_isLogged()) pauth_loginFromCookie($pdo);
if (!pauth_isLogged()) { header('Location: login.php'); exit; }

// Refus : on ferme la session sans rien supprimer. L'inscription à la course
// reste évidemment valable, seul l'accès en ligne est abandonné.
if (isset($_GET['refus'])) {
    pauth_logout($pdo);
    header('Location: ../accueil.php');
    exit;
}

$settings = pauth_settings($pdo);
$version  = (string) $settings['participant_rgpd_version'];
$erreur   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $erreur = "Session expirée. Rechargez la page et réessayez.";
    } elseif (empty($_POST['accepte'])) {
        $erreur = "Vous devez accepter pour accéder à votre espace.";
    } else {
        $pdo->prepare('UPDATE participants SET rgpd_consent_at = NOW(), rgpd_consent_version = ? WHERE id = ?')
            ->execute([$version, pauth_id()]);
        $_SESSION[PAUTH_SESSION_KEY]['rgpd'] = true;
        header('Location: index.php');
        exit;
    }
}

$authBase       = '../../';
$authThemeKey   = PAUTH_THEME_KEY;   // préférence propre au coureur, jamais celle de l'admin
$authArtKicker  = 'Forbach en Rose · Espace coureur';
$authArtTitre   = 'Vos données.<br>Vos droits.';

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Vos données</title>
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

      <div class="inner is-wide">
        <div class="oc-icon-area">
          <div class="oc-icon-circle">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
          </div>
          <h1 class="oc-title">Vos données personnelles</h1>
          <p class="oc-subtitle">Une seule fois, avant d'accéder à votre espace.</p>
        </div>

        <?php if ($erreur !== ''): ?>
          <div class="oc-alert oc-alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= $h($erreur) ?></div>
        <?php endif; ?>

        <div class="oc-card" style="text-align:left;font-size:var(--fs-small);line-height:1.65">
          <p style="margin-top:0">Votre espace coureur vous permet de retrouver vos inscriptions,
             votre QR code et, à terme, vos résultats. Voici ce que cela implique.</p>

          <p style="font-weight:700;margin-bottom:4px">Ce que nous utilisons</p>
          <ul style="margin:0 0 12px 1.1rem;padding:0">
            <li>Votre <strong>adresse email</strong>, pour vous envoyer votre code de connexion
                et rattacher vos inscriptions à votre compte.</li>
            <li>Vos <strong>inscriptions</strong>&nbsp;: nom, prénom, édition, taille de t-shirt,
                statut de paiement.</li>
            <li>Vos <strong>appareils de confiance</strong> si vous cochez « se souvenir de moi ».
                Vous pouvez les révoquer à tout moment.</li>
          </ul>

          <p style="font-weight:700;margin-bottom:4px">Ce que nous ne faisons pas</p>
          <ul style="margin:0 0 12px 1.1rem;padding:0">
            <li>Aucune revente, aucun partage à des fins commerciales.</li>
            <li>Aucun mot de passe stocké&nbsp;: connexion par code à usage unique.</li>
            <li>Aucun suivi de localisation aujourd'hui. Le jour où l'application mobile
                proposera le chronométrage, votre accord explicite et distinct sera demandé.</li>
          </ul>

          <p style="font-weight:700;margin-bottom:4px">Vos droits</p>
          <ul style="margin:0 0 12px 1.1rem;padding:0">
            <li>Exporter vos données à tout moment depuis « Mon compte ».</li>
            <li>Supprimer votre compte&nbsp;: le compte en ligne disparaît,
                <strong>votre inscription à la course reste valable</strong> — l'association doit
                la conserver pour sa comptabilité.</li>
          </ul>

          <p style="margin-bottom:0">Détail complet dans la
             <a href="../politique-confidentialite.php" target="_blank" rel="noopener">politique
             de confidentialité</a>.</p>
        </div>

        <form method="post" style="margin-top:var(--sp-4)">
          <?= csrf_field() ?>
          <div class="oc-checkbox-group">
            <input type="checkbox" id="ecAccepte" name="accepte" value="1" required>
            <label for="ecAccepte">J'ai lu et j'accepte l'utilisation de mes données décrite
              ci-dessus (version <?= $h($version) ?>).</label>
          </div>
          <button class="oc-btn" type="submit"><i class="bi bi-check2"></i> Accéder à mon espace</button>
        </form>

        <p style="margin-top:var(--sp-3);text-align:center">
          <a class="oc-back" href="?refus=1">Refuser et me déconnecter</a>
        </p>
      </div><!-- /inner -->
    </div><!-- /auth-pane -->
    <?php include __DIR__ . '/../../src/partials/auth-art.php'; ?>
  </div><!-- /auth-frame -->
</div><!-- /auth -->
</body>
</html>
