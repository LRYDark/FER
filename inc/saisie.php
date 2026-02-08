<?php
require '../config/config.php';
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
$titleColor = $data['title_color'] ?? '#ffffff'; 

// Formulaire ---------------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT * FROM forms');
$stmt->execute();

$required_fields = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $required_fields[$row['fields']] = $row['required'] ? 1 : 0;
}

$required_name = $required_fields['required_name'] ?? 0;
$required_firstname = $required_fields['required_firstname'] ?? 0;
$required_phone = $required_fields['required_phone'] ?? 0;
$required_email = $required_fields['required_email'] ?? 0;
$required_date_of_birth = $required_fields['required_date_of_birth'] ?? 0;
$required_sex = $required_fields['required_sex'] ?? 0;
$required_city = $required_fields['required_city'] ?? 0;
$required_company = $required_fields['required_company'] ?? 0;
?>

<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Saisie inscription – Forbach en Rose</title>

  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Charte Forbach en Rose -->
  <link href="../css/fer-modern.css" rel="stylesheet"> <!-- ajuste le chemin si besoin -->
  <!-- Google Fonts (déjà référencée dans le CSS) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
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
  <?php if (!empty($picture)): ?>
  <img src="../files/_pictures/<?= htmlspecialchars($picture) ?>"
       alt="Logo Forbach en Rose"
       class="logo-top">
  <?php endif; ?>

  <!-- Actions en haut à droite -->
  <div class="top-actions">
    <a href="#" id="logout-top" class="btn btn-outline-light btn-sm">Déconnexion</a>
  </div>

  <div class="hero-inner text-center">
    <h1 style="color: <?= htmlspecialchars($titleColor) ?>;"><?= htmlspecialchars($title) ?><small class="d-block fs-6 fw-light">(interface saisie)</small></h1>
    <p class="mb-3">Ajoutez manuellement une inscription</p>
    <span class="badge-donation">Interface réservée : <?= currentOrganisation();?></span>
  </div>
</header>

  <!-- Bloc formulaire -->
  <main class="container flex-grow-1 d-flex justify-content-center">
    <div class="card card-form p-4 bg-white">
      <h2 class="text-center mb-4">Ajouter une inscription</h2>
      <div id="msg" class="alert alert-info d-none"></div>

      <form id="fAdd" class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Nom <?= $required_fields['required_name'] ? '*' : '' ?></label>
          <input name="nom" class="form-control" <?= $required_fields['required_name'] ? 'required' : '' ?>>
        </div>

        <div class="col-md-6">
          <label class="form-label">Prénom <?= $required_fields['required_firstname'] ? '*' : '' ?></label>
          <input name="prenom" class="form-control" <?= $required_fields['required_firstname'] ? 'required' : '' ?>>
        </div>

        <div class="col-md-6">
          <label class="form-label">Téléphone <?= $required_fields['required_phone'] ? '*' : '' ?></label>
          <input name="tel" class="form-control" <?= $required_fields['required_phone'] ? 'required' : '' ?>>
        </div>

        <div class="col-md-6">
          <label class="form-label">Email <?= $required_fields['required_email'] ? '*' : '' ?></label>
          <input name="email" type="email" class="form-control" <?= $required_fields['required_email'] ? 'required' : '' ?>>
        </div>

        <div class="col-md-6">
          <label class="form-label">Date de naissance <?= $required_fields['required_date_of_birth'] ? '*' : '' ?></label>
          <input name="naissance" type="date" class="form-control" <?= $required_fields['required_date_of_birth'] ? 'required' : '' ?>>
        </div>

        <div class="col-md-6">
          <label class="form-label">Sexe <?= $required_fields['required_sex'] ? '*' : '' ?></label>
          <select name="sexe" class="form-select" <?= $required_fields['required_sex'] ? 'required' : '' ?>>
            <option value="H">H</option>
            <option value="F">F</option>
            <option value="Autre">Autre</option>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Ville <?= $required_fields['required_city'] ? '*' : '' ?></label>
          <input name="ville" class="form-control" <?= $required_fields['required_city'] ? 'required' : '' ?>>
        </div>

        <div class="col-md-6">
          <label class="form-label">Entreprise <?= $required_fields['required_company'] ? '*' : '' ?></label>
          <input name="entreprise" class="form-control" <?= $required_fields['required_company'] ? 'required' : '' ?>>
        </div>

        <input type="hidden" name="tshirt_size" value="-">
        <input type="hidden" name="origine" value="<?= currentOrganisation();?>">

        <!-- Paiement obligatoire -->
        <div class="col-md-6">
          <label class="form-label">Paiement</label>
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
  </main>

  <?php require 'footer-modern.php'; ?>

  <!-- JS -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script>
    /* Envoi du formulaire */
    $('#fAdd').on('submit', e => {
      e.preventDefault();
      fetch('../config/api.php?route=registrations', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
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
  <script src="../js/fer-modern.js"></script>
</body>
</html>
