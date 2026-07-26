<?php
/**
 * consentement.php — Acceptation RGPD, exigée à la première connexion (lot 2).
 *
 * pauth_require() redirige ici tant que `rgpd_consent_at` est vide : aucune
 * donnée personnelle n'est affichée avant. La version acceptée est enregistrée
 * (`participant_rgpd_version`), afin qu'une évolution de la politique puisse
 * exiger un nouveau consentement plutôt que de le présumer acquis.
 */
define('FER_SESSION_COUREUR', true);
require '../../src/core/config.php';
checkMaintenance();
require_once '../../src/security/csrf.php';
require_once '../../src/auth/participant_auth.php';
require __DIR__ . '/../../src/partials/navbar-data.php';

// Connexion exigée, mais SANS le contrôle de consentement : c'est la page qui
// le recueille, la renvoyer sur elle-même créerait une boucle.
if (!pauth_isLogged()) pauth_loginFromCookie($pdo);
if (!pauth_isLogged()) { header('Location: login.php'); exit; }

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

// Refus : on ferme la session sans rien supprimer. L'inscription à la course
// reste évidemment valable, seul l'accès en ligne est abandonné.
if (isset($_GET['refus'])) {
    pauth_logout($pdo);
    header('Location: ../accueil.php');
    exit;
}

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Vos données</title>
<link rel="stylesheet" href="../../css/tokens.css">
<link rel="stylesheet" href="../../css/fer-modern.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .ec-wrap { min-height:70vh; display:flex; align-items:center; justify-content:center; padding:32px 16px; }
  .ec-card { background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08); width:100%; max-width:620px; overflow:hidden; }
  .ec-hd { background:linear-gradient(135deg,#F42182,#db2777); color:#fff; padding:26px 28px; }
  .ec-hd h1 { font-size:1.25rem; font-weight:700; margin:0 0 4px; }
  .ec-hd p  { font-size:.85rem; opacity:.92; margin:0; }
  .ec-bd { padding:24px 28px 28px; font-size:.92rem; color:#334155; line-height:1.65; }
  .ec-bd h2 { font-size:.95rem; font-weight:700; color:#1e293b; margin:18px 0 6px; }
  .ec-bd ul { margin:0 0 0 1.1rem; padding:0; }
  .ec-btn { border:0; border-radius:.6rem; padding:.8rem 1.4rem; font-size:1rem; font-weight:700;
            background:linear-gradient(135deg,#F42182,#db2777); color:#fff; cursor:pointer; }
  .ec-btn:hover { opacity:.93; }
  .ec-alert { border-radius:.6rem; padding:.8rem .9rem; font-size:.88rem; margin-bottom:16px;
              background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
  .ec-check { display:flex; align-items:flex-start; gap:10px; margin:18px 0; }
  .ec-actions { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
  .ec-refus { color:#64748b; font-size:.85rem; text-decoration:underline; }
</style>
</head>
<body>
<?php include __DIR__ . '/_layout-haut.php'; ?>

<div class="ec-wrap">
  <div class="ec-card">
    <div class="ec-hd">
      <h1><i class="bi bi-shield-check me-2"></i>Vos données personnelles</h1>
      <p>Une seule fois, avant d'accéder à votre espace.</p>
    </div>
    <div class="ec-bd">

      <?php if ($erreur !== ''): ?>
        <div class="ec-alert"><i class="bi bi-exclamation-triangle me-1"></i><?= $h($erreur) ?></div>
      <?php endif; ?>

      <p>Votre espace coureur vous permet de retrouver vos inscriptions, votre QR code
         et, à terme, vos résultats. Voici ce que cela implique.</p>

      <h2>Ce que nous utilisons</h2>
      <ul>
        <li>Votre <strong>adresse email</strong>, pour vous envoyer votre code de connexion
            et rattacher vos inscriptions à votre compte.</li>
        <li>Vos <strong>inscriptions</strong> déjà enregistrées&nbsp;: nom, prénom, édition,
            taille de t-shirt, statut de paiement.</li>
        <li>Vos <strong>appareils de confiance</strong>, si vous cochez « se souvenir de moi »&nbsp;:
            navigateur, plateforme, date de dernière utilisation. Vous pouvez les révoquer
            à tout moment.</li>
      </ul>

      <h2>Ce que nous ne faisons pas</h2>
      <ul>
        <li>Aucune revente, aucun partage à des fins commerciales.</li>
        <li>Aucun mot de passe stocké&nbsp;: la connexion se fait par code à usage unique.</li>
        <li>Aucun suivi de localisation aujourd'hui. Le jour où l'application mobile
            proposera le chronométrage, votre accord explicite et distinct sera demandé.</li>
      </ul>

      <h2>Vos droits</h2>
      <ul>
        <li>Exporter vos données à tout moment depuis « Mon compte ».</li>
        <li>Supprimer votre compte&nbsp;: le compte en ligne disparaît,
            <strong>votre inscription à la course reste valable</strong> — l'association doit
            la conserver pour sa comptabilité.</li>
      </ul>

      <p style="margin-top:14px">
        Détail complet dans notre <a href="../politique-confidentialite.php" target="_blank"
        rel="noopener">politique de confidentialité</a>.
      </p>

      <form method="post">
        <?= csrf_field() ?>
        <label class="ec-check">
          <input type="checkbox" name="accepte" value="1" required>
          <span>J'ai lu et j'accepte l'utilisation de mes données décrite ci-dessus
                (version <?= $h($version) ?>).</span>
        </label>
        <div class="ec-actions">
          <button class="ec-btn" type="submit"><i class="bi bi-check2 me-1"></i>Accéder à mon espace</button>
          <a class="ec-refus" href="?refus=1">Refuser et me déconnecter</a>
        </div>
      </form>

    </div>
  </div>
</div>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
