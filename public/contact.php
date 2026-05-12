<?php
require '../config/config.php';
checkMaintenance();
require_once '../config/tracker.php';
require_once '../config/csrf.php';
require_once '../config/captcha.php';
trackPageVisit();
require '../inc/navbar-data.php';

$success = false;
$error = '';

// Récupérer le succès d'un envoi AJAX précédent
if (!empty($_SESSION['contact_success'])) {
    $success = true;
    unset($_SESSION['contact_success']);
}

$isAjax = isAjaxRequest();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Session expirée. Veuillez réessayer.';
    } else {
        // 🔒 [FIX-12] Rate limiting formulaire contact : 3 envois/heure/IP (CWE-770)
        $contactIp  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rlFile = sys_get_temp_dir() . '/fer_' . md5('contact_' . $contactIp) . '.json';
        $rlTimes = @file_exists($rlFile) ? (json_decode(@file_get_contents($rlFile), true) ?: []) : [];
        $now = time();
        $rlTimes = array_values(array_filter($rlTimes, fn($t) => $t > $now - 3600));
        if (count($rlTimes) >= 3) {
            $error = 'Trop de messages envoyés. Réessayez dans une heure.';
        } elseif (!verifyPublicCaptcha($_POST, $pdo)) {
            $error = 'Vérification anti-robot échouée. Recommencez.';
        } else {
            $rlTimes[] = $now;
            @file_put_contents($rlFile, json_encode($rlTimes));
    $nom = trim($_POST['nom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $sujet = trim($_POST['sujet'] ?? '');
    $rawMessage = $_POST['message'] ?? '';
    if ($isAjax) $rawMessage = decodeHtmlField($rawMessage);
    $message = trim($rawMessage);

    if ($nom === '' || $email === '' || $sujet === '' || $message === '') {
        $error = 'Veuillez remplir tous les champs.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Adresse email invalide.';
    } else {
        require_once '../config/googleMail.php';

        // Vérif de la config mail en fonction du provider actif (Google OAuth ou SMTP)
        $provider = $data['mail_provider'] ?? 'google';
        if ($provider === 'smtp') {
            $mailConfigured = !empty($data['smtp_host']) && !empty($data['smtp_user']) && !empty($data['smtp_pass']);
        } else {
            $mailConfigured = !empty($clientID) && !empty($clientSecret) && file_exists(__DIR__ . '/../config/token.json');
        }

        // Destinataires : notify_recipients si toggle activé, sinon fallback mail_email / smtp_from_email
        // pour ne jamais perdre le message du visiteur meme si la notification admin est desactivee
        $recipients = isNotifyEnabled($pdo, 'contact') ? getNotifyRecipients($pdo) : [];
        if (empty($recipients)) {
            $fallback = $data['mail_email'] ?? $data['smtp_from_email'] ?? '';
            if ($fallback) $recipients = [$fallback];
        }

        if (!$mailConfigured || empty($recipients)) {
            $error = "Une erreur est survenue, veuillez réessayer plus tard.";
        } else {
            $body = "Nouveau message depuis le formulaire de contact :<br><br>";
            $body .= "<strong>Nom :</strong> " . htmlspecialchars($nom) . "<br>";
            $body .= "<strong>Email :</strong> " . htmlspecialchars($email) . "<br>";
            $body .= "<strong>Sujet :</strong> " . htmlspecialchars($sujet) . "<br><br>";
            $body .= "<strong>Message :</strong><br>" . nl2br(htmlspecialchars($message));

            $contactEmail = count($recipients) === 1 ? $recipients[0] : $recipients;

            $sent = sendMail(
                $contactEmail,
                "Contact - " . $sujet,
                "Nouveau message de contact",
                $body
            );

            if ($sent) {
                $success = true;
                $_SESSION['contact_success'] = true;
            } else {
                $error = "Une erreur est survenue, veuillez réessayer plus tard.";
            }
        }
    }
        } // end rate limit else
  } // end csrf_verify else

  if ($isAjax) {
      header('Content-Type: application/json');
      echo json_encode(['ok' => $success, 'message' => $error ?: 'Message envoyé avec succès !']);
      exit;
  }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Contact</title>
  <link rel="stylesheet" href="../css/fer-modern.css">
  <link rel="stylesheet" href="../css/contact.css">
<?php include __DIR__ . '/../config/theme.php'; ?>
</head>
<body>

  <?php include '../inc/navbar-modern.php'; ?>

  <section class="contact-section">
    <h1>Contactez-nous</h1>
    <p class="subtitle">Une question, une suggestion ou envie de nous rejoindre ? Envoyez-nous un message !</p>

    <?php if ($success): ?>
      <div class="alert alert-success">
        Votre message a bien été envoyé ! Nous vous répondrons dans les meilleurs délais.
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-error">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form class="contact-form" method="post" action="">
      <?= csrf_field() ?>
      <div>
        <label for="nom">Nom complet</label>
        <input type="text" id="nom" name="nom" value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>" required>
      </div>
      <div>
        <label for="email">Adresse email</label>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
      </div>
      <div>
        <label for="sujet">Sujet</label>
        <input type="text" id="sujet" name="sujet" value="<?= htmlspecialchars($_POST['sujet'] ?? '') ?>" required>
      </div>
      <div>
        <label for="message">Message</label>
        <textarea id="message" name="message" required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
      </div>

      <!-- Vérification anti-robot (Turnstile ou maths selon config) -->
      <div id="contactCaptcha">
        <div id="contactTurnstileBox" style="display:none;"></div>
        <div id="contactMathBox" style="display:none;">
          <div id="contactCaptchaQuestion" class="contact-captcha-question">Chargement…</div>
          <div class="contact-captcha-row">
            <input id="contactCaptchaAnswer" type="text" inputmode="numeric" autocomplete="off" placeholder="Votre réponse" class="contact-captcha-input">
            <button type="button" id="contactCaptchaReload" class="contact-captcha-reload" title="Nouvelle question">↻</button>
          </div>
        </div>
        <div id="contactCaptchaError" class="contact-captcha-error"></div>
        <input type="hidden" name="turnstile_token"  id="contactTurnstileToken">
        <input type="hidden" name="captcha_token"    id="contactCaptchaToken">
        <input type="hidden" name="captcha_answer"   id="contactCaptchaAnswerHidden">
      </div>

      <button type="submit" class="contact-submit" id="contactSubmitBtn" disabled>Envoyer le message</button>
    </form>
    <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
      .contact-captcha-question { font-size:1.05rem; font-weight:700; color:#1e293b; margin-bottom:.5rem; }
      .contact-captcha-row { display:flex; gap:.5rem; align-items:stretch; }
      .contact-captcha-input { flex:1; padding:.55rem .75rem; border:1px solid #cbd5e1; border-radius:.55rem; font-size:1rem; outline:none; background:#fff; }
      .contact-captcha-input:focus { border-color:#db2777; box-shadow:0 0 0 3px rgba(219,39,119,.15); }
      .contact-captcha-reload { background:#e2e8f0; border:none; border-radius:.55rem; padding:0 .9rem; font-size:1rem; font-weight:600; color:#334155; cursor:pointer; }
      .contact-captcha-reload:hover { background:#cbd5e1; }
      .contact-captcha-error { color:#b91c1c; font-size:.82rem; min-height:1.1em; margin-top:.4rem; }
      .contact-submit:disabled { opacity:.55; cursor:not-allowed; filter:grayscale(.4); }
    </style>
    <?php endif; ?>
  </section>

  <?php include '../inc/footer-modern.php'; ?>

  <script src="../js/fer-modern.js"></script>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
  /* Envoi AJAX du formulaire contact pour contourner le WAF + vérification anti-bot */
  (function () {
      var form = document.querySelector('.contact-form');
      if (!form) return;

      var submitBtn   = document.getElementById('contactSubmitBtn');
      var tsBox       = document.getElementById('contactTurnstileBox');
      var mathBox     = document.getElementById('contactMathBox');
      var qEl         = document.getElementById('contactCaptchaQuestion');
      var aEl         = document.getElementById('contactCaptchaAnswer');
      var aHidden     = document.getElementById('contactCaptchaAnswerHidden');
      var tokHidden   = document.getElementById('contactCaptchaToken');
      var tsHidden    = document.getElementById('contactTurnstileToken');
      var errEl       = document.getElementById('contactCaptchaError');
      var reloadBtn   = document.getElementById('contactCaptchaReload');

      var mode = null;
      var tsWidgetId = null;
      var tsLoading = null;
      var didFallback = false;

      function setError(msg) { errEl.textContent = msg || ''; }
      function setValid(ok)  { submitBtn.disabled = !ok; }

      function ensureTurnstileScript() {
        if (window.turnstile) return Promise.resolve();
        if (tsLoading) return tsLoading;
        tsLoading = new Promise(function(resolve, reject) {
          var s = document.createElement('script');
          s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
          s.async = true; s.defer = true;
          s.onload  = function(){ resolve(); };
          s.onerror = function(){ tsLoading = null; reject(new Error('load')); };
          document.head.appendChild(s);
        });
        return tsLoading;
      }

      function switchToMathFallback(reason) {
        if (didFallback) { setError(reason || 'Échec du captcha. Réessayez.'); return; }
        didFallback = true;
        setError('Vérification indisponible — bascule sur un captcha de secours…');
        if (tsWidgetId !== null && window.turnstile) {
          try { window.turnstile.remove(tsWidgetId); } catch(e){}
          tsWidgetId = null;
        }
        fetch('../config/api.php?route=partner-captcha-init&fallback=1', { method: 'GET' })
          .then(function(r){ return r.json(); })
          .then(function(j){
            if (!j || !j.ok || j.mode !== 'math') throw new Error('fallback');
            mode = 'math';
            tsBox.style.display = 'none';
            mathBox.style.display = 'block';
            tokHidden.value = j.token;
            tsHidden.value  = '';
            qEl.textContent = j.question;
            aEl.value = '';
            setError('');
          })
          .catch(function(){
            setError('Impossible d\'afficher le captcha de secours. Réessayez plus tard.');
          });
      }

      function initCaptcha() {
        setError(''); setValid(false);
        didFallback = false;
        tsHidden.value = ''; tokHidden.value = ''; aHidden.value = '';
        fetch('../config/api.php?route=partner-captcha-init', { method: 'GET' })
          .then(function(r){ return r.json(); })
          .then(function(j){
            if (!j || !j.ok) throw new Error('init');
            mode = j.mode;
            if (mode === 'turnstile') {
              tsBox.style.display = 'block';
              mathBox.style.display = 'none';
              ensureTurnstileScript().then(function(){
                if (tsWidgetId !== null) {
                  try { window.turnstile.remove(tsWidgetId); } catch(e){}
                  tsWidgetId = null;
                }
                tsWidgetId = window.turnstile.render(tsBox, {
                  sitekey: j.sitekey,
                  theme: 'light',
                  callback: function(token){ tsHidden.value = token; setValid(true); setError(''); },
                  'error-callback':   function(){ tsHidden.value = ''; setValid(false); switchToMathFallback('Échec du captcha Cloudflare.'); },
                  'expired-callback': function(){ tsHidden.value = ''; setValid(false); setError('Captcha expiré. Réessayez.'); }
                });
              }).catch(function(){
                switchToMathFallback('Impossible de charger Cloudflare.');
              });
            } else {
              tsBox.style.display = 'none';
              mathBox.style.display = 'block';
              tokHidden.value = j.token;
              qEl.textContent = j.question;
              aEl.value = '';
            }
          })
          .catch(function(){
            setError('Impossible d\'initialiser le captcha. Rechargez la page.');
          });
      }

      // Validation maths : bouton devient actif dès qu'une réponse non vide est saisie
      if (aEl) {
        aEl.addEventListener('input', function(){
          if (mode === 'math') {
            aHidden.value = aEl.value.trim();
            setValid(aHidden.value.length > 0);
          }
        });
      }
      reloadBtn.addEventListener('click', initCaptcha);

      form.addEventListener('submit', function (e) {
          e.preventDefault();
          if (submitBtn.disabled) return;
          // Synchronise la réponse math au cas où
          if (mode === 'math') aHidden.value = aEl.value.trim();

          var fd = new FormData(form);
          var msg = fd.get('message') || '';
          if (msg) fd.set('message', btoa(unescape(encodeURIComponent(msg))));
          submitBtn.disabled = true;

          fetch(form.action || window.location.href, {
              method: 'POST',
              body: fd,
              headers: { 'X-Requested-With': 'XMLHttpRequest' }
          })
          .then(function (r) { return r.json(); })
          .then(function (data) {
              if (data.ok) {
                  window.location.reload();
              } else {
                  var alertDiv = document.querySelector('.alert-error');
                  if (!alertDiv) {
                      alertDiv = document.createElement('div');
                      alertDiv.className = 'alert alert-error';
                      form.parentNode.insertBefore(alertDiv, form);
                  }
                  alertDiv.textContent = data.message;
                  // Captcha consommé : on en regénère un
                  initCaptcha();
              }
          })
          .catch(function (err) {
              alert('Erreur : ' + err.message);
              initCaptcha();
          });
      });

      initCaptcha();
  })();
  </script>
</body>
</html>
