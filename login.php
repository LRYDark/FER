<?php require 'config/config.php'; 
if (currentRole()) header('Location: inc/dashboard.php'); 
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
  <link href="css/forbach-style.css" rel="stylesheet">

  <!-- Ajustements spécifiques à la page de connexion -->
  <style>
    /* rectangle un peu plus large et relevé pour chevaucher la partie rose */
    .card-login{max-width:420px;width:100%;margin-top:-3rem;}
    @media (max-width:575.98px){
      .card-login{max-width:100%;margin-top:-2rem;}
    }
  </style>
</head>
<body class="d-flex flex-column">

  <!-- ───────── Bandeau rose ───────── -->
  <header class="hero">
    <img src="files/_pictures/<?= htmlspecialchars($picture) ?>" alt="Logo Forbach en Rose" class="logo-top">
    <div class="hero-inner">
      <h1>Connexion</h1>
      <p class="mb-0">Accédez à votre espace</p>
    </div>
  </header>

  <!-- ───────── Formulaire ───────── -->
  <main class="container flex-grow-1 d-flex justify-content-center align-items-start">
    <div class="card card-form card-login p-4 bg-white">
      <h2 class="text-center mb-4">Se connecter</h2>

      <div id="err" class="alert alert-danger d-none"></div>

      <form id="fLogin" novalidate>
        <div class="mb-3"><label class="form-label">Utilisateur</label>
          <input name="username" class="form-control" required>
        </div>
        <div class="mb-3"><label class="form-label">Mot de passe</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button class="btn btn-rose btn-lg w-100">Connexion</button>
      </form>
    </div>
  </main>

  <footer class="text-center py-3 small text-muted"><?= htmlspecialchars($footer) ?></footer>

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
