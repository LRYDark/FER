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

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Déconnexion</title>
<link rel="stylesheet" href="../../css/tokens.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .ec-wrap { min-height:60vh; display:flex; align-items:center; justify-content:center; padding:32px 16px; }
  .ec-card { background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08);
             width:100%; max-width:420px; padding:28px; text-align:center; }
  .ec-card h1 { font-size:1.1rem; font-weight:700; color:#0f172a; margin:0 0 8px; }
  .ec-card p { font-size:.9rem; color:#475569; margin:0 0 20px; line-height:1.6; }
  .ec-btn { border:0; border-radius:.6rem; padding:.75rem 1.3rem; font-size:.95rem; font-weight:700;
            background:linear-gradient(135deg,#F42182,#db2777); color:#fff; cursor:pointer; }
  .ec-lien { display:inline-block; margin-top:14px; color:#64748b; font-size:.85rem; }
</style>
</head>
<body>
<?php include __DIR__ . '/_layout-haut.php'; ?>
<div class="ec-wrap">
  <div class="ec-card">
    <h1>Se déconnecter</h1>
    <p>Cet appareil ne sera plus reconnu&nbsp;: il faudra un nouveau code
       pour revenir sur votre espace.</p>
    <form method="post">
      <?= csrf_field() ?>
      <button class="ec-btn" type="submit"><i class="bi bi-box-arrow-right me-1"></i>Confirmer</button>
    </form>
    <a class="ec-lien" href="index.php">Annuler</a>
  </div>
</div>
<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
