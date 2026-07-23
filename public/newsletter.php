<?php
/**
 * newsletter.php — Abonnement et désabonnement à la newsletter (côté public).
 *
 *  - POST + champ `subscribe` (AJAX)  → enregistre l'abonnement, renvoie du JSON.
 *    Utilisé par la section « Rester informé » de la page d'accueil.
 *  - GET  ?unsubscribe=1              → affiche la page de désabonnement.
 *  - POST + champ `unsubscribe_do`    → traite le désabonnement.
 *
 * La logique métier vit dans src/mail/newsletter.php (helpers réutilisables).
 */
require __DIR__ . '/../src/core/config.php';
require_once __DIR__ . '/../src/security/csrf.php';
require_once __DIR__ . '/../src/mail/newsletter.php';
require_once __DIR__ . '/../src/security/captcha.php';

/* ───────────────────────────────────────────────────────────────
 * 1) ABONNEMENT — endpoint AJAX appelé par le formulaire de l'accueil
 * ─────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['subscribe']) || isset($_POST['subscribe_newsletter']))) {
    header('Content-Type: application/json');

    // Honeypot anti-bot : le champ caché `website` doit rester vide.
    if (!empty($_POST['website'])) {
        echo json_encode(['ok' => true, 'msg' => 'Merci !']);
        exit;
    }
    // CSRF — vérifié uniquement si un jeton est fourni.
    if (isset($_POST['csrf_token']) && !csrf_verify()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Session expirée, rechargez la page.']);
        exit;
    }
    // Consentement obligatoire (case à cocher du formulaire).
    if (empty($_POST['consent'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'Vous devez accepter de recevoir les e-mails.']);
        exit;
    }
    // 🔒 [SEC-NL] Rate-limit par IP : max 5 abonnements/heure/IP (anti abonnement de
    // masse d'adresses tierces). Le double opt-in (mail de confirmation) reste recommandé.
    $__nlIp   = function_exists('fer_client_ip') ? fer_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $__nlKey  = substr(hash('sha256', 'newsletter_' . $__nlIp), 0, 32);
    $__nlFile = sys_get_temp_dir() . '/fer_' . $__nlKey . '.json';
    $__nlNow  = time();
    $__nlTimes = @file_exists($__nlFile) ? (json_decode(@file_get_contents($__nlFile), true) ?: []) : [];
    $__nlTimes = array_values(array_filter($__nlTimes, fn($t) => $t > $__nlNow - 3600));
    if (count($__nlTimes) >= 5) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'msg' => 'Trop de demandes. Réessayez plus tard.']);
        exit;
    }
    // 🔒 [SEC-NL] Vérification anti-robot : Turnstile en principal (si configuré),
    // captcha maths en secours automatique (même mécanisme que le formulaire de contact).
    if (!verifyPublicCaptcha($_POST, $pdo)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'Vérification anti-robot échouée. Recommencez.']);
        exit;
    }

    $__nlTimes[] = $__nlNow;
    @file_put_contents($__nlFile, json_encode($__nlTimes));

    $res = newsletterSubscribe($pdo, (string)($_POST['email'] ?? ''));
    if (!$res['ok']) http_response_code(400);
    echo json_encode($res);
    exit;
}

/* ───────────────────────────────────────────────────────────────
 * 2) DÉSABONNEMENT — page autonome (lien générique des e-mails)
 * ─────────────────────────────────────────────────────────────── */
$done    = false;
$error   = '';
$prefill = strtolower(trim((string)($_GET['email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unsubscribe_do'])) {
    $email   = strtolower(trim((string)($_POST['unsubscribe_email'] ?? '')));
    $prefill = $email;
    if (!newsletterIsValidEmail($email)) {
        $error = 'Adresse e-mail invalide.';
    } else {
        // On confirme dans tous les cas (pas de divulgation de la présence de l'email).
        newsletterUnsubscribe($pdo, $email);
        $done = true;
    }
}

// Couleur primaire du thème pour rester cohérent visuellement.
$primary = '#db2777';
try {
    $c = $pdo->query("SELECT theme_primary_color FROM setting WHERE id = 1 LIMIT 1")->fetchColumn();
    if ($c && preg_match('/^#[0-9a-fA-F]{6}$/', $c)) $primary = $c;
} catch (\Throwable $e) {}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Désabonnement newsletter — Forbach en Rose</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:#f1f5f9;
       min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;color:#0f172a;}
  .ns-card{background:#fff;border-radius:18px;box-shadow:0 18px 60px rgba(2,6,23,.16);
       max-width:460px;width:100%;overflow:hidden;}
  .ns-head{background:<?= htmlspecialchars($primary) ?>;color:#fff;padding:28px 32px;text-align:center;}
  .ns-head i{font-size:34px;}
  .ns-head h1{font-size:20px;font-weight:800;margin-top:8px;}
  .ns-body{padding:28px 32px 32px;}
  .ns-body p{color:#475569;font-size:14px;line-height:1.6;margin-bottom:16px;}
  .ns-body label{font-size:13px;font-weight:700;display:block;margin-bottom:6px;}
  .ns-body input[type=email]{width:100%;padding:11px 14px;border:1px solid #cbd5e1;
       border-radius:10px;font-size:15px;}
  .ns-body input[type=email]:focus{outline:none;border-color:<?= htmlspecialchars($primary) ?>;
       box-shadow:0 0 0 3px color-mix(in srgb,<?= htmlspecialchars($primary) ?> 18%,transparent);}
  .ns-btn{margin-top:16px;width:100%;padding:12px;border:0;border-radius:10px;
       background:<?= htmlspecialchars($primary) ?>;color:#fff;font-weight:700;font-size:15px;cursor:pointer;}
  .ns-btn:hover{filter:brightness(.93);}
  .ns-alert{padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:16px;}
  .ns-alert-err{background:#fef2f2;color:#991b1b;}
  .ns-ok{text-align:center;}
  .ns-ok i{font-size:48px;color:#16a34a;}
  .ns-link{display:inline-block;margin-top:18px;color:#64748b;font-size:13px;text-decoration:none;}
  .ns-link:hover{text-decoration:underline;}
</style>
</head>
<body>
  <?php include __DIR__ . '/../src/partials/preloader.php'; ?>
<div class="ns-card">
  <div class="ns-head">
    <i class="bi bi-envelope-<?= $done ? 'check' : 'slash' ?>"></i>
    <h1><?= $done ? 'Désabonnement confirmé' : 'Se désabonner de la newsletter' ?></h1>
  </div>
  <div class="ns-body">
    <?php if ($done): ?>
      <div class="ns-ok">
        <i class="bi bi-check-circle-fill"></i>
        <p style="margin-top:12px;">Vous ne recevrez plus nos e-mails. Vous pouvez vous réabonner à tout moment depuis la page d'accueil.</p>
        <a class="ns-link" href="accueil.php"><i class="bi bi-arrow-left"></i> Retour à l'accueil</a>
      </div>
    <?php else: ?>
      <p>Indiquez l'adresse e-mail avec laquelle vous êtes abonné(e) pour ne plus recevoir nos actualités.</p>
      <?php if ($error !== ''): ?>
        <div class="ns-alert ns-alert-err"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post" action="newsletter.php?unsubscribe=1">
        <?= csrf_field() ?>
        <label for="ns-email">Votre adresse e-mail</label>
        <input type="email" id="ns-email" name="unsubscribe_email" required
               value="<?= htmlspecialchars($prefill) ?>" placeholder="vous@exemple.fr">
        <button type="submit" name="unsubscribe_do" value="1" class="ns-btn">Me désabonner</button>
      </form>
      <a class="ns-link" href="accueil.php"><i class="bi bi-arrow-left"></i> Retour à l'accueil</a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
