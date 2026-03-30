<?php
require '../config/config.php';
require_once '../config/csrf.php';
require 'navbar-data.php';
requireRole(['saisie']);           // seul ce rôle a accès
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
$title_mobile = $data['title_mobile'] ?? '';

// Formulaire dynamique
require_once '../config/form_fields.php';
$formFields = getActiveFields($pdo, 'saisie');
?>

<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Saisie inscription</title>

  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
  <!-- Charte Forbach en Rose -->
  <!-- Google Fonts (déjà référencée dans le CSS) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
</head>

<body>
  <style>
    .hero .top-actions {
      position: absolute;
      top: .6rem;
      right: .6rem;
      display: flex;
      gap: .5rem;
      /* pas de margin-top ici ⇒ mobile = 0 */
    }

    @media (min-width: 992px) {   /* ≥ 992 px  ≈ Bootstrap lg */
      .hero .top-actions {
        margin-top: 6%;
      }
    }
  </style>

  <!-- HERO identique à register.php -->
<header class="hero position-relative"><!-- position:relative indispensable -->
  <style>
    .header-mobile{ display: none; }
    .header-pc{ display: block; }
    @media (max-width: 980px){
      .header-pc{ display: none; }
      .header-mobile{ display: block; }
    }
  </style>

  <!-- Actions en haut à droite -->
  <div class="top-actions">
    <a href="#" id="logout-top" class="btn btn-outline-light btn-sm">Déconnexion</a>
  </div>

  <div class="hero-inner text-center">
    <!-- PC -->
    <div class="header-pc">
      <div class="demo-kicker"><?= $title ?></div>
      <small class="d-block fs-6 fw-light" style="color:rgba(255,255,255,.7);">(interface saisie)</small>
    </div>
    <!-- Mobile -->
    <div class="header-mobile">
      <div class="demo-kicker"><?= $title_mobile ?: $title ?></div>
      <small class="d-block fs-6 fw-light" style="color:rgba(255,255,255,.7);">(interface saisie)</small>
    </div>
    <p class="mb-3">Ajoutez manuellement une inscription</p>
    <span class="badge-donation">Interface réservée : <?= currentOrganisation();?></span>
  </div>
</header>

  <!-- Bloc formulaire -->
    <div class="card card-form p-4 bg-white">
      <h2 class="text-center mb-4">Ajouter une inscription</h2>
      <div id="msg" class="alert alert-info d-none"></div>

      <form id="fAdd" class="row g-3">
        <?php foreach ($formFields as $f): ?>
          <?= renderFormField($f) ?>
        <?php endforeach; ?>

        <input type="hidden" name="origine" value="<?= currentOrganisation();?>">

        <!-- Paiement obligatoire -->
        <div class="col-md-6">
          <label class="form-label">Paiement <span style="color:#ef4444">*</span></label>
          <select name="paiement_mode" class="form-select" required>
            <option value="" selected disabled hidden>Choisir…</option>
            <option value="CB">CB</option>
            <option value="espece">espèces</option>
            <option value="cheque">chèque</option>
          </select>
        </div>

        <div class="col-12 d-grid mt-3">
          <button class="btn btn-rose btn-lg">Enregistrer</button>
        </div>
      </form>
    </div>

  <?php require 'admin-footer.php'; ?>

  <!-- JS -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
    var _csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    /* Envoi du formulaire */
    $('#fAdd').on('submit', e => {
      e.preventDefault();
      fetch('../config/api.php?route=registrations', {
        method: 'POST',
        headers: {'Content-Type':'application/json', 'X-CSRF-TOKEN': _csrfToken},
        body: JSON.stringify(Object.fromEntries(new FormData(e.target)))
      })
      .then(r => r.json())
      .then(j => {
        if (j.ok) {
          $('#msg').removeClass('d-none')
                   .text('Inscription n° ' + j.inscription_no + ' enregistrée !');
          e.target.reset();
        }
      });
    });

    /* Déconnexion (footer + header) */
    $('#logout, #logout-top').on('click', e => {
      e.preventDefault();
      fetch('../config/api.php?route=logout')
        .then(() => location = '../login.php');
    });
  </script>
</body>
</html>
