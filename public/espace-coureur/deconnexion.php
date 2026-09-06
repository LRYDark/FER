<?php
/**
 * deconnexion.php — Ferme la session coureur et révoque l'appareil courant.
 *
 * En POST uniquement (avec CSRF) : une déconnexion déclenchable par un simple
 * GET peut être provoquée par une image distante pointant vers cette URL.
 * C'est bénin, mais gratuit à empêcher.
 */
define('FER_SESSION_COUREUR', true);
require '../../src/core/config.php';
require_once '../../src/security/csrf.php';
require_once '../../src/auth/participant_auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    pauth_logout($pdo);
    header('Location: login.php');
    exit;
}

$authBase       = '../../';
$authThemeKey   = PAUTH_THEME_KEY;
/* Accent propre au coureur, résolu par la même fonction que les pages
   internes. theme.js aurait sinon appliqué celui de l'administrateur. */
$authAccentRes  = pauth_accentVars($pdo);
$authAccentVars = $authAccentRes['vars'];
$authAccentData = $authAccentRes['data'];   // préférence propre au coureur, jamais celle de l'admin
$authArtKicker  = 'Forbach en Rose · Espace coureur';
$authArtTitre   = 'À bientôt.<br>Sur la ligne de départ.';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Déconnexion</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php include __DIR__ . '/../../src/partials/auth-head.php'; ?>
</head>
<body>
<div class="auth">
  <div class="auth-frame">
    <div class="auth-pane">
      <a class="brand" href="../accueil.php">
        <?php if (file_exists(dirname(__DIR__, 2) . '/files/_logos/logo_fer_rose.png')): ?>
          <img src="../../files/_logos/logo_fer_rose.png" alt="Forbach en Rose" style="height:56px;width:auto">
        <?php else: ?>
          <span class="name">Forbach en Rose</span>
        <?php endif; ?>
      </a>

      <div class="inner">
        <div class="oc-icon-area">
          <div class="oc-icon-circle">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
          </div>
          <h1 class="oc-title">Se déconnecter</h1>
          <p class="oc-subtitle">Cet appareil ne sera plus reconnu&nbsp;: il faudra un nouveau
             code pour revenir sur votre espace.</p>
        </div>

        <form method="post">
          <?= csrf_field() ?>
          <button class="oc-btn" type="submit"><i class="bi bi-box-arrow-right"></i> Confirmer</button>
        </form>

        <p style="margin-top:var(--sp-3);text-align:center">
          <a class="oc-back" href="index.php">Annuler</a>
        </p>
      </div><!-- /inner -->
    </div><!-- /auth-pane -->
    <?php include __DIR__ . '/../../src/partials/auth-art.php'; ?>
  </div><!-- /auth-frame -->
</div><!-- /auth -->
</body>
</html>
