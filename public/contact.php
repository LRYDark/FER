<?php
require '../config/config.php';
checkMaintenance();
require_once '../config/tracker.php';
require_once '../config/csrf.php';
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
      <button type="submit" class="contact-submit">Envoyer le message</button>
    </form>
    <?php endif; ?>
  </section>

  <?php include '../inc/footer-modern.php'; ?>

  <script src="../js/fer-modern.js"></script>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
  /* Envoi AJAX du formulaire contact pour contourner le WAF */
  (function () {
      var form = document.querySelector('.contact-form');
      if (!form) return;
      form.addEventListener('submit', function (e) {
          e.preventDefault();
          var fd = new FormData(form);
          var msg = fd.get('message') || '';
          if (msg) fd.set('message', btoa(unescape(encodeURIComponent(msg))));
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
              }
          })
          .catch(function (err) {
              alert('Erreur : ' + err.message);
          });
      });
  })();
  </script>
</body>
</html>
