<?php
/**
 * login.php — Connexion à l'espace coureur (lot 2, restylé lot 3).
 *
 * Deux étapes : saisie de l'adresse, puis saisie du code à 6 chiffres reçu par
 * mail. Le lien cliquable du mail arrive ici aussi, avec ?email=&token=.
 *
 * La page reprend EXACTEMENT la charte des pages d'authentification du site
 * (src/partials/auth-head.php + auth-art.php, classes .oc-*) : c'est la même
 * nature de page que la connexion administrateur, elle doit s'y ressembler.
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

if (isset($_GET['supprime'])) {
    $info = "Votre compte en ligne a été supprimé. Votre inscription à la course reste valable.";
}

/* Le message est le MÊME que l'adresse existe ou non — c'est toute la défense
   anti-énumération. Ne jamais le faire varier, même « pour aider l'utilisateur ». */
$MSG_CODE_ENVOYE = "Si un compte correspond à cette adresse, un code vient d'être envoyé. "
                 . "Vérifiez votre boîte mail, et vos indésirables.";

/* Le mail ne contient QUE le code : aucun lien de connexion. Un lien
   transporterait le secret dans une URL — journalisée, conservée dans
   l'historique, transmise en Referer, transférable d'un « faire suivre ». */

/* ── Traitements POST ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* Turnstile en rendu implicite poste son jeton sous le nom
       « cf-turnstile-response » ; verifyPublicCaptcha() attend
       « turnstile_token ». On fait la correspondance ICI, côté serveur : la
       faire en JavaScript au moment du submit dépendait du moment où le widget
       avait fini de se rendre, et échouait alors que la case était bien cochée. */
    if (empty($_POST['turnstile_token']) && !empty($_POST['cf-turnstile-response'])) {
        $_POST['turnstile_token'] = $_POST['cf-turnstile-response'];
    }

    if (!csrf_verify()) {
        $erreur = "Session expirée. Rechargez la page et réessayez.";
    }

    /* Étape 1 — demande d'un code */
    elseif (isset($_POST['demander_code'])) {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = "Merci de saisir une adresse email valide.";
        } elseif (!verifyPublicCaptcha($_POST, $pdo)) {
            $erreur = "Vérification anti-robot échouée. Rechargez la page et réessayez.";
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
                    pauth_sendCodeMail($pdo, $email, pauth_issueCode($pdo, $email, 'web', $ip));
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
$captcha  = $etape === 'email' ? issuePublicCaptcha($pdo) : ['mode' => 'none'];
$authBase = '../../';   // lu par auth-head.php pour retrouver css/ et js/

/* L'espace coureur est un espace PUBLIC. Il reste en clair quoi qu'ait choisi
   l'administrateur pour lui-même : la préférence de thème vit dans le
   localStorage du domaine et ne distingue pas les deux espaces — sans cela, un
   visiteur qui n'a jamais rien réglé se verrait servir le goût de l'admin. */
$authThemeKey   = PAUTH_THEME_KEY;
/* Accent propre au coureur, résolu par la même fonction que les pages
   internes. theme.js aurait sinon appliqué celui de l'administrateur. */
$authAccentRes  = pauth_accentVars($pdo);
$authAccentVars = $authAccentRes['vars'];
$authAccentData = $authAccentRes['data'];   // préférence propre au coureur, jamais celle de l'admin
$authArtKicker  = 'Forbach en Rose · Espace coureur';
$authArtTitre   = 'Votre course.<br>Votre espace.';

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Connexion</title>
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
                    d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
          </div>
          <h1 class="oc-title">Espace coureur</h1>
          <p class="oc-subtitle">
            <?= $etape === 'email'
                  ? "Connectez-vous avec l'adresse utilisée lors de votre inscription."
                  : "Saisissez le code reçu par mail." ?>
          </p>
        </div>

        <?php if ($erreur !== ''): ?>
          <div class="oc-alert oc-alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= $h($erreur) ?></div>
        <?php endif; ?>
        <?php if ($info !== ''): ?>
          <div class="oc-alert oc-alert-info"><i class="bi bi-envelope-check me-1"></i><?= $h($info) ?></div>
        <?php endif; ?>

        <?php if ($etape === 'email'): ?>
          <form method="post" autocomplete="on">
            <?= csrf_field() ?>
            <div class="oc-form-group">
              <label class="oc-label" for="ecEmail">Votre adresse email</label>
              <input class="oc-input" type="email" id="ecEmail" name="email" required
                     autocomplete="email" inputmode="email" placeholder="vous@exemple.fr"
                     value="<?= $h($email) ?>">
            </div>

            <?php if (($captcha['mode'] ?? '') === 'turnstile'): ?>
              <div class="oc-form-group">
                <?php /* Rendu implicite : Turnstile crée lui-même le champ
                         « cf-turnstile-response », que le serveur sait lire. */ ?>
                <div class="cf-turnstile" data-sitekey="<?= $h($captcha['sitekey']) ?>"></div>
              </div>
              <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
            <?php elseif (($captcha['mode'] ?? '') === 'math'): ?>
              <div class="oc-form-group">
                <label class="oc-label" for="ecCap"><?= $h($captcha['question']) ?></label>
                <input class="oc-input" type="text" id="ecCap" name="captcha_answer"
                       inputmode="numeric" autocomplete="off" required placeholder="Votre réponse">
                <input type="hidden" name="captcha_token" value="<?= $h($captcha['token']) ?>">
              </div>
            <?php endif; ?>

            <button class="oc-btn" type="submit" name="demander_code" value="1">
              <i class="bi bi-envelope"></i> Recevoir mon code
            </button>
          </form>

          <p class="oc-form-hint" style="margin-top:var(--sp-3);line-height:1.6">
            Pas de mot de passe&nbsp;: vous recevez un code à 6 chiffres, valable
            <?= (int) $settings['participant_code_ttl_min'] ?> minutes.<br>
            Vous ne retrouvez pas votre inscription&nbsp;?
            <a href="../faq.php">Questions fréquentes</a>.
          </p>

        <?php else: ?>
          <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="email" value="<?= $h($email) ?>">
            <div class="oc-form-group">
              <label class="oc-label" for="ecCode">Code à 6 chiffres</label>
              <input class="oc-input" type="text" id="ecCode" name="code" required
                     inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                     autofocus placeholder="••••••"
                     style="letter-spacing:.6em;text-align:center;font-size:1.5rem;font-weight:700;font-family:var(--font-mono,monospace)">
            </div>

            <div class="oc-checkbox-group">
              <input type="checkbox" id="ecSouvenir" name="se_souvenir" value="1">
              <label for="ecSouvenir">Se souvenir de moi sur cet appareil
                (<?= (int) $settings['participant_web_remember_jours'] ?> jours)</label>
            </div>

            <button class="oc-btn" type="submit" name="valider_code" value="1">
              <i class="bi bi-box-arrow-in-right"></i> Me connecter
            </button>
          </form>

          <p class="oc-form-hint" style="margin-top:var(--sp-3);line-height:1.6">
            Rien reçu&nbsp;? Vérifiez vos indésirables, puis
            <a href="login.php">demandez un nouveau code</a>.
          </p>
        <?php endif; ?>

        <p style="margin-top:var(--sp-4)">
          <a href="../accueil.php" class="oc-back">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:15px;height:15px">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Retour au site
          </a>
        </p>
      </div><!-- /inner -->
    </div><!-- /auth-pane -->
    <?php include __DIR__ . '/../../src/partials/auth-art.php'; ?>
  </div><!-- /auth-frame -->
</div><!-- /auth -->
</body>
</html>
