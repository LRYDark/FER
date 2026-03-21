<?php
require '../config/config.php';
require '../inc/navbar-data.php';

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = trim($_POST['nom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $sujet = trim($_POST['sujet'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($nom === '' || $email === '' || $sujet === '' || $message === '') {
        $error = 'Veuillez remplir tous les champs.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Adresse email invalide.';
    } else {
        require_once '../config/googleMail.php';

        // Destinataire = le compte Google configuré
        $accessToken = getAccessToken(false);
        if ($accessToken) {
            $gmailClient = new Google_Client();
            $gmailClient->setAccessToken(['access_token' => $accessToken]);
            $gmailService = new Google_Service_Gmail($gmailClient);
            $contactEmail = $gmailService->users->getProfile('me')->getEmailAddress();

            $body = "Nouveau message depuis le formulaire de contact :<br><br>";
            $body .= "<strong>Nom :</strong> " . htmlspecialchars($nom) . "<br>";
            $body .= "<strong>Email :</strong> " . htmlspecialchars($email) . "<br>";
            $body .= "<strong>Sujet :</strong> " . htmlspecialchars($sujet) . "<br><br>";
            $body .= "<strong>Message :</strong><br>" . nl2br(htmlspecialchars($message));

            $sent = sendMail(
                $contactEmail,
                "Contact - " . $sujet,
                "Nouveau message de contact",
                $body
            );

            if ($sent) {
                $success = true;
            } else {
                $error = "Une erreur est survenue, veuillez réessayer plus tard.";
            }
        } else {
            $error = "Une erreur est survenue, veuillez réessayer plus tard.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Contact - Forbach en Rose</title>
  <link rel="stylesheet" href="../css/fer-modern.css">
  <style>
    body {
      display: flex;
      flex-direction: column;
      min-height: 100vh;
      margin: 0;
    }
    .contact-section {
      max-width: 720px;
      margin: 120px auto 60px;
      padding: 0 20px;
      flex: 1;
    }
    .contact-section h1 {
      font-size: 2rem;
      margin-bottom: 0.5rem;
      color: var(--fer-pink, #e91e63);
    }
    .contact-section .subtitle {
      color: #666;
      margin-bottom: 2rem;
    }
    .contact-form {
      display: flex;
      flex-direction: column;
      gap: 1.2rem;
    }
    .contact-form label {
      font-weight: 600;
      font-size: 0.9rem;
      margin-bottom: 0.3rem;
      display: block;
    }
    .contact-form input,
    .contact-form textarea {
      width: 100%;
      padding: 12px 16px;
      border: 1px solid #ddd;
      border-radius: 8px;
      font-size: 1rem;
      font-family: inherit;
      transition: border-color 0.2s;
      box-sizing: border-box;
      background: var(--fer-bg, #fff);
      color: var(--fer-text, #333);
    }
    .contact-form input:focus,
    .contact-form textarea:focus {
      outline: none;
      border-color: var(--fer-pink, #e91e63);
    }
    .contact-form textarea {
      min-height: 160px;
      resize: vertical;
    }
    .contact-submit {
      background: var(--fer-pink, #e91e63);
      color: #fff;
      border: none;
      padding: 14px 32px;
      border-radius: 8px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.2s;
      align-self: flex-start;
    }
    .contact-submit:hover {
      background: #c2185b;
    }
    .alert {
      padding: 14px 18px;
      border-radius: 8px;
      margin-bottom: 1.5rem;
      font-weight: 500;
    }
    .alert-success {
      background: #e8f5e9;
      color: #2e7d32;
      border: 1px solid #c8e6c9;
    }
    .alert-error {
      background: #fce4ec;
      color: #c62828;
      border: 1px solid #f8bbd0;
    }
    body.dark-theme .contact-section .subtitle { color: #aaa; }
    body.dark-theme .alert-success { background: #1b5e20; color: #a5d6a7; border-color: #2e7d32; }
    body.dark-theme .alert-error { background: #b71c1c; color: #ef9a9a; border-color: #c62828; }
  </style>
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
</body>
</html>
