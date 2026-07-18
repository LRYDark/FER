<?php
// Page dédiée affichée quand l'adresse IP du visiteur est bannie.
//
// On reste en 200 OK volontairement pour éviter qu'Apache intercepte la réponse
// via la directive ErrorDocument 403 et sert errors/403.php à la place.

// Suppression des warnings dans la sortie HTML (ils restent dans error_log).
@ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR);

$type   = $_GET['type']  ?? 'permanent'; // 'permanent' ou 'temp'
$until  = $_GET['until'] ?? '';
$ipAddr = $_GET['ip']    ?? '';
$isTemp = ($type === 'temp');

// ─── Récupération des coordonnées de contact via la connexion PDO globale ───
// On require config.php : ip-banned.php est dans la liste des exclusions de
// l'enforcement IP ban, donc aucune redirection en boucle. On bénéficie du
// chargement .env standard de l'application.
$cMail  = '';
$cPhone = '';
try {
    require_once __DIR__ . '/../src/core/config.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        $row = $pdo->query("SELECT mail_email, mail_phone FROM setting WHERE id = 1 LIMIT 1")
                   ->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $cMail  = trim((string)($row['mail_email'] ?? ''));
            $cPhone = trim((string)($row['mail_phone'] ?? ''));
        }
    }
} catch (\Throwable $e) {
    // Silencieux : si la BDD est inaccessible on n'affiche juste pas les contacts
}

// Validation
if (!filter_var($cMail, FILTER_VALIDATE_EMAIL)) $cMail = '';
// Téléphone : on accepte chiffres, espaces, +, ., (, ), -, / (très permissif)
if ($cPhone !== '' && !preg_match('#^[\d\s+().\-/]{4,40}$#', $cPhone)) $cPhone = '';
$telHref = $cPhone ? preg_replace('/[^\d+]/', '', $cPhone) : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Accès bloqué</title>
  <style>
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
    :root { --pink: #F42182; --dark: #0f172a; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #fff;
      color: var(--dark);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }
    .error-container {
      text-align: center;
      padding: 40px 24px;
      max-width: 560px;
      position: relative;
    }
    .error-code {
      font-size: clamp(110px, 18vw, 180px);
      font-weight: 900;
      letter-spacing: -6px;
      line-height: 1;
      color: rgba(15,23,42,0.06);
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -55%);
      pointer-events: none;
      user-select: none;
      white-space: nowrap;
      z-index: 0;
    }
    .error-icon {
      width: 72px;
      height: 72px;
      border-radius: 50%;
      background: #fce7f3;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 12px;
      position: relative;
      z-index: 1;
    }
    .error-icon svg {
      width: 36px;
      height: 36px;
      color: var(--pink);
    }
    .title-block {
      position: relative;
      padding: 18px 0 16px;   /* espace haut/bas dans le bloc pour que le BAN reste à l'intérieur */
      margin-bottom: 16px;    /* gap entre title-block et le tag IP */
    }
    .error-title {
      font-size: clamp(24px, 4vw, 36px);
      font-weight: 800;
      letter-spacing: -0.03em;
      margin-bottom: 12px;
      position: relative;
      z-index: 1;
    }
    .error-desc {
      font-size: 17px;
      line-height: 1.6;
      color: rgba(15,23,42,0.6);
      margin-bottom: 0;
      position: relative;
      z-index: 1;
    }
    .ip-tag {
      display: inline-block;
      padding: 6px 14px;
      background: rgba(15,23,42,0.06);
      border-radius: 8px;
      font-family: 'SF Mono', Menlo, Monaco, Consolas, monospace;
      font-size: 14px;
      color: var(--dark);
      margin-bottom: 24px;
      position: relative;
      z-index: 1;
    }
    .error-note {
      display: inline-block;
      font-size: 14px;
      color: rgba(15,23,42,0.5);
      position: relative;
      z-index: 1;
    }
    .contact-list {
      list-style: none;
      margin: 16px 0 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 8px;
      align-items: center;
      position: relative;
      z-index: 1;
    }
    .contact-item {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 15px;
      font-weight: 600;
      color: var(--dark);
      text-decoration: none;
      padding: 8px 16px;
      background: rgba(244,33,130,0.06);
      border: 1px solid rgba(244,33,130,0.18);
      border-radius: 10px;
      transition: all .2s ease;
    }
    .contact-item:hover {
      background: rgba(244,33,130,0.12);
      border-color: rgba(244,33,130,0.32);
      transform: translateY(-1px);
    }
    .contact-item svg {
      width: 16px;
      height: 16px;
      color: var(--pink);
      flex-shrink: 0;
    }
  </style>
</head>
<body>
  <div class="error-container">
    <div class="error-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
      </svg>
    </div>

    <div class="title-block">
      <span class="error-code">BAN</span>
      <?php if ($isTemp): ?>
        <h1 class="error-title">Acc&egrave;s temporairement bloqu&eacute;</h1>
        <p class="error-desc">
          Suite &agrave; une <strong>activit&eacute; suspecte</strong> d&eacute;tect&eacute;e
          depuis votre adresse IP, l'acc&egrave;s &agrave; cette plateforme a &eacute;t&eacute;
          temporairement bloqu&eacute;.
          <?php if ($until): ?>
            <br>Le blocage sera lev&eacute; automatiquement le
            <strong><?= htmlspecialchars($until) ?></strong>.
          <?php endif; ?>
        </p>
      <?php else: ?>
        <h1 class="error-title">Acc&egrave;s d&eacute;finitivement bloqu&eacute;</h1>
        <p class="error-desc">
          Suite &agrave; une <strong>activit&eacute; suspecte</strong> d&eacute;tect&eacute;e
          depuis votre adresse IP, celle-ci a &eacute;t&eacute;
          <strong>bannie de fa&ccedil;on permanente</strong> de cette plateforme.
        </p>
      <?php endif; ?>
    </div>

    <?php if ($ipAddr): ?>
      <div class="ip-tag"><?= htmlspecialchars($ipAddr) ?></div>
    <?php endif; ?>

    <p class="error-note">
      Si vous pensez qu'il s'agit d'une erreur,
      contactez un administrateur.
    </p>

    <?php if ($cMail || $cPhone): ?>
    <ul class="contact-list">
      <?php if ($cMail): ?>
      <li>
        <a class="contact-item" href="mailto:<?= htmlspecialchars($cMail) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22,6 12,13 2,6"/>
          </svg>
          <?= htmlspecialchars($cMail) ?>
        </a>
      </li>
      <?php endif; ?>
      <?php if ($cPhone): ?>
      <li>
        <a class="contact-item" href="tel:<?= htmlspecialchars($telHref) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
          </svg>
          <?= htmlspecialchars($cPhone) ?>
        </a>
      </li>
      <?php endif; ?>
    </ul>
    <?php endif; ?>
  </div>
</body>
</html>
