<?php
require '../config/config.php';
require_once '../config/csrf.php';
require 'navbar-data.php';
requireRole(['saisie']);           // page dédiée au rôle saisie

// Cohérence avec le système de permissions : si l'admin a retiré
// dashboard.create_registration au rôle saisie, on ne montre pas le formulaire
$canCreateReg = canDoAction('dashboard.create_registration');
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
    :root{
      --rose-500:#F42182;
      --rose-600:#db2777;
    }
    body{
      background:#fff;
      min-height:100vh;
      display:flex;
      flex-direction:column;
    }
    .hero{
      background:var(--rose-500);
      color:#fff;
      padding:1.55rem 1rem calc(1.95rem + 16px);
      min-height:120px;
      position:relative;
      text-align:center;
    }
    .badge-donation{
      background:#fff;
      color:var(--rose-600);
      border-radius:1rem;
      padding:.4rem .9rem;
      font-weight:600;
    }
    .hero-inner{max-width:800px;margin:.15rem auto 0;}
    .demo-kicker img{
      max-width:100% !important;
      height:auto !important;
      object-fit:contain;
      display:block;
      margin:0 auto;
    }
    .card-form{
      max-width:1100px;
      margin:calc(-.75rem - 20px) auto 12px;
      border:0;
      box-shadow:0 0 25px rgba(0,0,0,.1);
    }
    .register-page-title img{
      max-width:100% !important;
      height:auto !important;
      object-fit:contain;
    }
    .btn-rose{
      background:var(--rose-600);
      color:#fff;
      border:0;
    }
    .btn-rose:hover{background:#c13778;color:#fff;}
    .form-control,
    .form-select{border-radius:1rem;}

    .hero .top-actions {
      position: absolute;
      top: .6rem;
      right: .6rem;
      display: flex;
      gap: .5rem;
    }

    @media (max-width:767.98px){
      .hero{
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:flex-start;
        padding:1rem .75rem calc(1.15rem + 10px);
        min-height:108px;
      }
      .hero .top-actions{
        position:relative;
        top:auto;
        right:auto;
        align-self:flex-end;
        margin:0 0 .5rem;
      }
      .hero-inner{
        width:100%;
        margin:0;
        padding:0 4px;
      }
      .badge-donation{
        font-size:.94rem;
        padding:.32rem .72rem;
      }
      .hero p{font-size:.9rem;}
      .card-form{
        max-width:100%;
        margin-top:0;
        margin-bottom:4px;
      }
      .btn-rose.btn-lg{
        font-size:1rem;
        padding:.65rem 1rem;
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
    <p class="mb-3">Ajoutez manuellement une inscription</p>
    <span class="badge-donation">Interface réservée : <?= currentOrganisation();?></span>
  </div>
</header>

  <!-- Bloc formulaire -->
    <div class="card card-form p-4 bg-white">
      <!-- Titre TinyMCE (PC / Mobile) -->
      <div class="header-pc">
        <div class="register-page-title text-center mb-3" style="font-size:clamp(24px,4vw,42px);font-weight:900;"><?= $title ?></div>
      </div>
      <div class="header-mobile">
        <div class="register-page-title text-center mb-3" style="font-size:clamp(24px,4vw,42px);font-weight:900;"><?= $title_mobile ?: $title ?></div>
      </div>
      <small class="d-block text-center text-muted mb-2" style="font-size:.85rem;">(interface saisie)</small>
      <h2 class="text-center mb-4">Ajouter une inscription</h2>
      <div id="msg" class="alert alert-info d-none"></div>

      <?php if (!$canCreateReg): ?>
        <div class="alert alert-warning text-center">
          <i class="bi bi-shield-exclamation me-1"></i>
          La permission de créer des inscriptions a été retirée à votre rôle.
          Contactez un administrateur pour la rétablir.
        </div>
      <?php else: ?>
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
      <?php endif; ?>
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
