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

    .login-logo {
      width: 80px;
      height: 80px;
      margin: 0 auto 1rem;
      background: white;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    .login-logo img {
      max-width: 60px;
      max-height: 60px;
      object-fit: contain;
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

      <!-- En-tête avec logo et titre -->
      <div class="login-header">
        <a href="public/accueil.php" class="back-link">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
          </svg>
          Retour
        </a>

        <?php if (!empty($picture)): ?>
        <div class="login-logo">
          <img src="files/_pictures/<?= htmlspecialchars($picture) ?>" alt="Logo Forbach en Rose">
        </div>
        <?php endif; ?>

        <h1>Connexion</h1>
        <p>Accédez à votre espace d'administration</p>
      </div>

      <!-- Formulaire de connexion -->
      <div class="login-body">
        <div id="err" class="alert alert-danger d-none"></div>

        <form id="fLogin" novalidate>
          <div class="mb-3">
            <label class="form-label">Nom d'utilisateur</label>
            <input name="username" class="form-control" placeholder="Entrez votre identifiant" required autofocus>
          </div>

          <div class="mb-4">
            <label class="form-label">Mot de passe</label>
            <input type="password" name="password" class="form-control" placeholder="Entrez votre mot de passe" required>
          </div>

          <button type="submit" class="btn btn-login w-100">
            Se connecter
          </button>
        </form>
      </div>

      <!-- Footer -->
      <div class="login-footer">
        <?= htmlspecialchars($footer) ?>
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
      .then(r => r.ok ? r.json() : Promise.reject())
      .then(j => {
          if (!j.ok) throw 0;
          switch (j.role) {
            case 'admin':
            case 'user':
            case 'viewer':
              location = 'inc/dashboard.php';
              break;
            case 'saisie':
              console.log('Redirection vers saisie.php'); // Debug
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
  </script>
</body>
</html>
