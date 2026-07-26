<?php
/**
 * login.php — Connexion à l'espace coureur (lot 2).
 *
 * Deux étapes : saisie de l'adresse, puis saisie du code à 6 chiffres reçu par
 * mail. Le lien cliquable du mail arrive ici aussi, avec ?email=&token=.
 *
 * ⚠️ FER_SESSION_COUREUR doit être défini AVANT d'inclure config.php : c'est ce
 * qui donne à cette page une session au cookie distinct de l'administration.
 * Sans cette ligne, un coureur et un administrateur partageraient la même
 * session — l'isolation entière du dispositif repose dessus.
 */
define('FER_SESSION_COUREUR', true);
require '../../src/core/config.php';
checkMaintenance();
require_once '../../src/security/csrf.php';
require_once '../../src/security/captcha.php';
require_once '../../src/auth/participant_auth.php';
require __DIR__ . '/../../src/partials/navbar-data.php';

// Déjà connecté : rien à faire ici.
if (!pauth_isLogged()) pauth_loginFromCookie($pdo);
if (pauth_isLogged()) {
    header('Location: index.php');
    exit;
}

$ip       = fer_client_ip();
$etape    = 'email';                 // email | code
$erreur   = '';
$info     = '';
$email    = trim((string) ($_GET['email'] ?? $_SESSION['pauth_email_encours'] ?? ''));
$retour   = (string) ($_GET['retour'] ?? '');
$settings = pauth_settings($pdo);

/* Le message est le MÊME que l'adresse existe ou non — c'est toute la défense
   anti-énumération. Ne jamais le faire varier, même « pour aider l'utilisateur ». */
$MSG_CODE_ENVOYE = "Si un compte correspond à cette adresse, un code vient d'être envoyé. "
                 . "Vérifiez votre boîte mail, et vos indésirables.";

/* ── Lien cliquable du mail : ?email=…&token=… ───────────────────────────── */
$tokenLien = (string) ($_GET['token'] ?? '');
if ($tokenLien !== '' && $email !== '') {
    $v = pauth_verifyCode($pdo, $email, null, $tokenLien);
    if ($v['ok']) {
        $participant = pauth_findByEmail($pdo, $email) ?? pauth_createFromRegistrations($pdo, $email);
        if ($participant && (int) $participant['is_active'] === 1) {
            pauth_syncRegistrations($pdo, (int) $participant['id'], $email);
            pauth_login($pdo, $participant);
            unset($_SESSION['pauth_email_encours']);
            header('Location: ' . (empty($participant['rgpd_consent_at']) ? 'consentement.php' : 'index.php'));
            exit;
        }
        $erreur = "Aucune inscription n'est associée à cette adresse.";
    } else {
        $erreur = match ($v['raison']) {
            'expire'             => "Ce lien a expiré. Demandez un nouveau code.",
            'trop_de_tentatives' => "Trop de tentatives. Demandez un nouveau code.",
            default              => "Ce lien n'est plus valable. Demandez un nouveau code.",
        };
    }
}

/* ── Traitements POST ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $erreur = "Session expirée. Rechargez la page et réessayez.";
    }

    /* Étape 1 — demande d'un code */
    elseif (isset($_POST['demander_code'])) {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = "Merci de saisir une adresse email valide.";
        } elseif (!verifyPublicCaptcha($_POST, $pdo)) {
            $erreur = "Vérification anti-robot échouée. Réessayez.";
        } else {
            pauth_purgeCodes($pdo);

            // La limitation de débit renvoie le MÊME message que le succès :
            // sinon elle révélerait qu'une adresse a déjà été sollicitée.
            if (pauth_rateLimitOk($pdo, $email, $ip)) {
                // ⚠️ Le code n'est envoyé QUE si une inscription correspond,
                // mais la réponse est identique dans tous les cas. Aucun compte
                // n'est créé ici : il le sera à la validation du code.
                if (regres_findByEmail($pdo, fer_normalizeEmail($email))
                    || pauth_findByEmail($pdo, $email)) {
                    $c = pauth_issueCode($pdo, $email, 'web', $ip);
                    $base = (function_exists('getAppBaseUrl') ? getAppBaseUrl() : '')
                          . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/')
                          . '/login.php';
                    pauth_sendCodeMail($pdo, $email, $c['code'], $c['token'], $base);
                }
            }

            $_SESSION['pauth_email_encours'] = $email;
            $etape = 'code';
            $info  = $MSG_CODE_ENVOYE;
        }
    }

    /* Étape 2 — validation du code */
    elseif (isset($_POST['valider_code'])) {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? $_SESSION['pauth_email_encours'] ?? '')));
        $code  = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));
        $etape = 'code';

        if ($email === '' || $code === '') {
            $erreur = "Saisissez le code à 6 chiffres reçu par mail.";
        } else {
            $v = pauth_verifyCode($pdo, $email, $code);
            if ($v['ok']) {
                $participant = pauth_findByEmail($pdo, $email) ?? pauth_createFromRegistrations($pdo, $email);
                if ($participant && (int) $participant['is_active'] === 1) {
                    pauth_syncRegistrations($pdo, (int) $participant['id'], $email);
                    pauth_login($pdo, $participant);
                    if (!empty($_POST['se_souvenir'])) {
                        pauth_rememberDevice($pdo, (int) $participant['id'], 'web');
                    }
                    unset($_SESSION['pauth_email_encours']);
                    $suite = empty($participant['rgpd_consent_at']) ? 'consentement.php' : 'index.php';
                    if ($retour !== '' && preg_match('/^[a-z0-9_-]+\.php$/i', $retour)) {
                        // Retour interne uniquement : jamais une URL fournie par
                        // l'utilisateur, sous peine de redirection ouverte.
                        $suite = empty($participant['rgpd_consent_at']) ? 'consentement.php' : $retour;
                    }
                    header('Location: ' . $suite);
                    exit;
                }
                $erreur = "Aucune inscription n'est associée à cette adresse.";
            } else {
                $erreur = match ($v['raison']) {
                    'expire'             => "Ce code a expiré. Demandez-en un nouveau.",
                    'trop_de_tentatives' => "Trop de tentatives. Demandez un nouveau code.",
                    'aucun'              => "Aucun code en attente. Demandez-en un nouveau.",
                    default              => "Code incorrect."
                        . (isset($v['restantes']) && $v['restantes'] > 0
                            ? " Il vous reste {$v['restantes']} essai(s)." : ''),
                };
            }
        }
    }
}

// Le captcha n'est nécessaire qu'à l'étape « email ».
$captcha = $etape === 'email' ? issuePublicCaptcha($pdo) : ['mode' => 'none'];
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Connexion</title>
<link rel="stylesheet" href="../../css/tokens.css">
<link rel="stylesheet" href="../../css/fer-modern.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .ec-wrap { min-height:70vh; display:flex; align-items:center; justify-content:center; padding:32px 16px; }
  .ec-card { background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08); width:100%; max-width:440px; overflow:hidden; }
  .ec-hd { background:linear-gradient(135deg,#F42182,#db2777); color:#fff; padding:26px 28px; }
  .ec-hd h1 { font-size:1.25rem; font-weight:700; margin:0 0 4px; }
  .ec-hd p  { font-size:.85rem; opacity:.92; margin:0; }
  .ec-bd { padding:24px 28px 28px; }
  .ec-lbl { display:block; font-size:.85rem; font-weight:600; color:#1e293b; margin-bottom:6px; }
  .ec-input { width:100%; padding:.7rem .85rem; border:1px solid #cbd5e1; border-radius:.6rem; font-size:1rem; background:#fff; }
  .ec-input:focus { outline:none; border-color:#F42182; box-shadow:0 0 0 3px rgba(244,33,130,.15); }
  .ec-code { letter-spacing:.6em; text-align:center; font-size:1.6rem; font-weight:700; font-family:monospace; }
  .ec-btn { width:100%; border:0; border-radius:.6rem; padding:.8rem 1rem; font-size:1rem; font-weight:700;
            background:linear-gradient(135deg,#F42182,#db2777); color:#fff; cursor:pointer; margin-top:16px; }
  .ec-btn:hover { opacity:.93; }
  .ec-alert { border-radius:.6rem; padding:.8rem .9rem; font-size:.88rem; margin-bottom:16px; line-height:1.5; }
  .ec-err  { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
  .ec-info { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; }
  .ec-help { font-size:.8rem; color:#64748b; margin-top:14px; line-height:1.6; }
  .ec-help a { color:#F42182; }
  .ec-check { display:flex; align-items:center; gap:8px; margin-top:14px; font-size:.88rem; color:#334155; }
  .ec-cap-q { font-size:1rem; font-weight:700; color:#1e293b; margin-bottom:.5rem; }
</style>
</head>
<body>
<?php include __DIR__ . '/_layout-haut.php'; ?>

<div class="ec-wrap">
  <div class="ec-card">
    <div class="ec-hd">
      <h1><i class="bi bi-person-badge me-2"></i>Espace coureur</h1>
      <p><?= $etape === 'email'
            ? "Connectez-vous avec l'adresse utilisée lors de votre inscription."
            : "Saisissez le code reçu par mail." ?></p>
    </div>
    <div class="ec-bd">

      <?php if ($erreur !== ''): ?>
        <div class="ec-alert ec-err"><i class="bi bi-exclamation-triangle me-1"></i><?= $h($erreur) ?></div>
      <?php endif; ?>
      <?php if ($info !== ''): ?>
        <div class="ec-alert ec-info"><i class="bi bi-envelope-check me-1"></i><?= $h($info) ?></div>
      <?php endif; ?>

      <?php if ($etape === 'email'): ?>
        <form method="post" autocomplete="on">
          <?= csrf_field() ?>
          <label class="ec-lbl" for="ecEmail">Votre adresse email</label>
          <input class="ec-input" type="email" id="ecEmail" name="email" required
                 autocomplete="email" inputmode="email" placeholder="vous@exemple.fr"
                 value="<?= $h($email) ?>">

          <?php if (($captcha['mode'] ?? '') === 'turnstile'): ?>
            <div style="margin-top:16px">
              <div class="cf-turnstile" data-sitekey="<?= $h($captcha['sitekey']) ?>" data-theme="light"></div>
              <input type="hidden" name="turnstile_token" id="ecTsToken">
            </div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
            <script>
              /* Turnstile renseigne un champ nommé cf-turnstile-response ; on le
                 recopie dans le nom attendu par verifyPublicCaptcha(). */
              document.addEventListener('submit', function (e) {
                var r = document.querySelector('[name="cf-turnstile-response"]');
                if (r) document.getElementById('ecTsToken').value = r.value;
              }, true);
            </script>
          <?php elseif (($captcha['mode'] ?? '') === 'math'): ?>
            <div style="margin-top:16px">
              <div class="ec-cap-q"><?= $h($captcha['question']) ?></div>
              <input class="ec-input" type="text" name="captcha_answer" inputmode="numeric"
                     autocomplete="off" required placeholder="Votre réponse">
              <input type="hidden" name="captcha_token" value="<?= $h($captcha['token']) ?>">
            </div>
          <?php endif; ?>

          <button class="ec-btn" type="submit" name="demander_code" value="1">
            <i class="bi bi-envelope me-1"></i>Recevoir mon code
          </button>
        </form>

        <p class="ec-help">
          Pas de mot de passe&nbsp;: vous recevez un code à 6 chiffres, valable
          <?= (int) $settings['participant_code_ttl_min'] ?> minutes.<br>
          Vous ne retrouvez pas votre inscription&nbsp;? Consultez la
          <a href="../faq.php">foire aux questions</a>.
        </p>

      <?php else: ?>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="email" value="<?= $h($email) ?>">
          <label class="ec-lbl" for="ecCode">Code à 6 chiffres</label>
          <input class="ec-input ec-code" type="text" id="ecCode" name="code" required
                 inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                 autofocus placeholder="••••••">

          <label class="ec-check">
            <input type="checkbox" name="se_souvenir" value="1">
            <span>Se souvenir de moi sur cet appareil
              (<?= (int) $settings['participant_web_remember_jours'] ?> jours)</span>
          </label>

          <button class="ec-btn" type="submit" name="valider_code" value="1">
            <i class="bi bi-box-arrow-in-right me-1"></i>Me connecter
          </button>
        </form>

        <p class="ec-help">
          Le mail contient aussi un lien direct&nbsp;: un clic suffit.<br>
          Rien reçu&nbsp;? Vérifiez vos indésirables, puis
          <a href="login.php">demandez un nouveau code</a>.
        </p>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
