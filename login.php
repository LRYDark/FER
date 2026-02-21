<?php require 'config/config.php'; 

$stmt = $pdo->prepare(
    'SELECT *
       FROM setting
      WHERE id = :id
      LIMIT 1');
$stmt->execute(['id' => 1]);

$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$assoconnectJs      = $data['assoconnect_js']     ?? null;
$assoconnectIframe  = $data['assoconnect_iframe'] ?? null;
$title  = $data['title']   ?? '';
$picture= $data['picture'] ?? '';  
$footer= $data['footer'] ?? '';  
$titleColor = $data['title_color'] ?? '#ffffff';
$picture= $data['picture'] ?? '';  
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forbach en Rose – Connexion</title>

  <!-- Bootstrap + thème principal -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="css/fer-modern.css" rel="stylesheet">

  <!-- Ajustements spécifiques à la page de connexion -->
  <style>
    body {
      min-height: 100vh;
      background: linear-gradient(135deg, #fdf4f8 0%, #ffffff 100%);
    }

    .login-container {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem 1rem;
    }

    .login-card {
      background: white;
      border-radius: 20px;
      box-shadow: 0 20px 60px rgba(236, 72, 153, 0.15);
      max-width: 440px;
      width: 100%;
      overflow: hidden;
    }

    .login-header {
      background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
      padding: 2.5rem 2rem;
      text-align: center;
      color: white;
      position: relative;
    }

    .login-header h1 {
      font-size: 1.75rem;
      font-weight: 700;
      margin: 0 0 0.5rem;
    }

    .login-header p {
      margin: 0;
      opacity: 0.95;
      font-size: 0.95rem;
    }

    .login-body {
      padding: 2.5rem 2rem;
    }

    .form-label {
      font-weight: 600;
      color: #334155;
      font-size: 0.9rem;
      margin-bottom: 0.5rem;
    }

    .form-control {
      border: 2px solid #e2e8f0;
      border-radius: 12px;
      padding: 0.75rem 1rem;
      font-size: 0.95rem;
      transition: all 0.2s;
    }

    .form-control:focus {
      border-color: #ec4899;
      box-shadow: 0 0 0 4px rgba(236, 72, 153, 0.1);
    }

    .btn-login {
      background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
      border: none;
      border-radius: 12px;
      padding: 0.875rem 1.5rem;
      font-weight: 600;
      font-size: 1rem;
      color: white;
      transition: all 0.2s;
      box-shadow: 0 4px 12px rgba(236, 72, 153, 0.3);
    }

    .btn-login:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(236, 72, 153, 0.4);
      background: linear-gradient(135deg, #db2777 0%, #be185d 100%);
    }

    .btn-login:active {
      transform: translateY(0);
    }

    .back-link {
      position: absolute;
      top: 1.5rem;
      left: 1.5rem;
      color: white;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.9rem;
      opacity: 0.9;
      transition: opacity 0.2s;
      z-index: 10;
    }

    .back-link:hover {
      opacity: 1;
      color: white;
    }

    .back-link svg {
      width: 20px;
      height: 20px;
    }

    .alert {
      border-radius: 12px;
      border: none;
      padding: 0.875rem 1rem;
    }

    .login-footer {
      text-align: center;
      padding: 1.5rem;
      color: #64748b;
      font-size: 0.85rem;
      position: relative;
      overflow: hidden;
      isolation: isolate;
    }

    .login-footer .footer-copy {
      position: relative;
      z-index: 1;
    }

    @media (max-width: 575.98px) {
      .login-card {
        border-radius: 0;
        box-shadow: none;
        min-height: 100vh;
      }

      .login-header {
        padding: 3rem 1.5rem 2rem;
      }

      .back-link {
        top: 1rem;
        left: 1rem;
      }
    }
  </style>
</head>
<body>

  <div class="login-container">
    <div class="login-card">

      <!-- En-tête de connexion -->
      <div class="login-header">
        <a href="public/accueil.php" class="back-link">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
          </svg>
          Retour
        </a>

        <h1>Connexion</h1>
        <p>Accédez à votre espace d'administration</p>
      </div>

      <!-- Formulaire de connexion -->
      <div class="login-body">
        <div id="err" class="alert alert-danger d-none"></div>

        <form id="fLogin" novalidate>
          <div class="mb-3">
            <label class="form-label">Adresse email</label>
            <input name="email" type="email" class="form-control" placeholder="Entrez votre adresse email" required autofocus>
          </div>

          <div class="mb-4">
            <label class="form-label">Mot de passe</label>
            <input type="password" name="password" class="form-control" placeholder="Entrez votre mot de passe" required>
          </div>

          <button type="submit" class="btn btn-login w-100">
            Se connecter
          </button>
        </form>

        <!-- Mot de passe oublie -->
        <div class="text-center mt-3">
          <a href="#" id="forgotLink" style="color:#ec4899;font-size:0.9rem;text-decoration:none;">Mot de passe oubli&eacute; ?</a>
        </div>

        <!-- Formulaire mot de passe oublie (cache par defaut) -->
        <div id="forgotForm" class="mt-3" style="display:none;">
          <hr>
          <p class="text-muted mb-2" style="font-size:0.85rem;">Entrez votre adresse email pour recevoir un lien de r&eacute;initialisation (valable 10 minutes).</p>
          <div class="input-group">
            <input type="email" id="forgotEmail" class="form-control" placeholder="Votre adresse email">
            <button id="forgotBtn" class="btn btn-login">Envoyer</button>
          </div>
          <div id="forgotMsg" class="mt-2" style="font-size:0.85rem;"></div>
        </div>
      </div>

      <!-- Footer -->
      <div class="login-footer">
        <span class="footer-copy"><?= htmlspecialchars($footer) ?></span>
      </div>

    </div>
  </div>

  <!-- Scripts -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script>
    $('#fLogin').on('submit', e => {
      e.preventDefault();

      fetch('config/api.php?route=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.fromEntries(new FormData(e.target)))
      })
      .then(r => r.json().then(j => ({ ok: r.ok, status: r.status, json: j })))
      .then(({ ok, status, json: j }) => {
          if (!ok || !j.ok) {
            if (status === 403 && j.err) {
              $('#err').text(j.err).removeClass('d-none');
            } else {
              $('#err').text('Identifiants incorrects').removeClass('d-none');
            }
            return;
          }
          // Changement de mot de passe obligatoire
          if (j.must_change_password) {
            location = 'change-password.php';
            return;
          }
          switch (j.role) {
            case 'admin':
            case 'user':
            case 'viewer':
              location = 'inc/dashboard.php';
              break;
            case 'saisie':
              location = 'inc/saisie.php';
              break;
            default:
              location = 'inc/dashboard.php';
          }
      })
      .catch(() => $('#err')
              .text('Identifiants incorrects')
              .removeClass('d-none'));
    });

    // Mot de passe oublie
    document.getElementById('forgotLink').addEventListener('click', function(e) {
      e.preventDefault();
      const form = document.getElementById('forgotForm');
      form.style.display = form.style.display === 'none' ? 'block' : 'none';
    });

    document.getElementById('forgotBtn').addEventListener('click', async function() {
      const email = document.getElementById('forgotEmail').value.trim();
      const msgEl = document.getElementById('forgotMsg');
      if (!email) { msgEl.innerHTML = '<span class="text-danger">Veuillez entrer votre email.</span>'; return; }

      this.disabled = true;
      this.textContent = 'Envoi...';

      try {
        const res = await fetch('config/api.php?route=forgot-password', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email })
        });
        const j = await res.json();
        msgEl.innerHTML = '<span class="text-success">Si un compte existe avec cette adresse, un email de r\u00e9initialisation a \u00e9t\u00e9 envoy\u00e9.</span>';
      } catch {
        msgEl.innerHTML = '<span class="text-danger">Erreur de communication avec le serveur.</span>';
      } finally {
        this.disabled = false;
        this.textContent = 'Envoyer';
      }
    });
  </script>
</body>
</html>
