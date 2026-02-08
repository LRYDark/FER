<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../config/config.php';
require '../config/googleMail.php';

// Variables d'état
$hasGetParams = !empty($_GET);
$qrData = null;
$qrToken = $_GET['token'] ?? '';
$errorMessage = '';
$success_message = '';
$error_message = '';

// Vérification du token QR si présent dans l'URL
if ($hasGetParams && $qrToken) {
    try {
        $stmt = $pdo->prepare(
            'SELECT organisation, description, is_active
             FROM qrcodes
             WHERE token = ? AND is_active = 1'
        );
        $stmt->execute([$qrToken]);
        $qrData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$qrData) {
            $errorMessage = 'QR Code invalide ou expiré.';
            $hasGetParams = false;
        }
    } catch (Exception $e) {
        $errorMessage = 'Erreur lors de la validation du QR Code.';
        $hasGetParams = false;
    }
} elseif ($hasGetParams && !$qrToken) {
    $errorMessage = 'Paramètres non autorisés. Accès refusé.';
    $hasGetParams = false;
}

// Traitement du formulaire si soumis
if ($_POST) {
    $submittedToken = $_POST['qr_token'] ?? '';
    $validToken = false;

    if ($submittedToken) {
        try {
            $stmt = $pdo->prepare(
                'SELECT organisation, description, is_active
                 FROM qrcodes
                 WHERE token = ? AND is_active = 1'
            );
            $stmt->execute([$submittedToken]);
            $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
            $validToken = (bool)$tokenData;
        } catch (Exception $e) {
            $validToken = false;
        }
    }

    if ($hasGetParams && !$validToken) {
        $error_message = "Token invalide. Inscription refusée.";
    } else {
        try {
            $stmt = $pdo->prepare('SELECT MAX(inscription_no) as max_no FROM registrations');
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $nextInscriptionNo = ($result['max_no'] ?? 0) + 1;

            $formData = [
                'inscription_no' => $nextInscriptionNo,
                'nom' => $_POST['nom'] ?? '',
                'prenom' => $_POST['prenom'] ?? '',
                'tel' => $_POST['tel'] ?? '',
                'email' => $_POST['email'] ?? '',
                'naissance' => $_POST['naissance'] ?? '',
                'sexe' => $_POST['sexe'] ?? '',
                'ville' => $_POST['ville'] ?? '',
                'entreprise' => $_POST['entreprise'] ?? '',
                'tshirt_size' => $_POST['tshirt_size'] ?? '-',
                'origine' => $_POST['origine'] ?? 'en ligne',
                'paiement_mode' => $_POST['paiement_mode'] ?? 'en ligne (CB)'
            ];

            $stmt = $pdo->prepare(
                'INSERT INTO registrations (inscription_no, nom, prenom, tel, email, naissance, sexe, ville, entreprise, tshirt_size, origine, paiement_mode, created_at)
                 VALUES (:inscription_no, :nom, :prenom, :tel, :email, :naissance, :sexe, :ville, :entreprise, :tshirt_size, :origine, :paiement_mode, NOW())'
            );

            $stmt->execute($formData);

            $subject = 'Inscription enregistrée - Forbach en Rose';
            if($_POST['email'] != ''){
              sendMail($_POST['email'], $subject, null, null, $_POST['nom'], $_POST['prenom'], 'inscription');
            }
            $success_message = "👍 Inscription enregistrée avec succès !";

        } catch (PDOException $e) {
            $error_message = "Erreur lors de l'enregistrement : " . $e->getMessage();
        }
    }
}

// Récupération des paramètres de configuration
$stmt = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
$stmt->execute(['id' => 1]);
$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$assoconnectJs      = $data['assoconnect_js']     ?? null;
$assoconnectIframe  = $data['assoconnect_iframe'] ?? null;
$title  = $data['title']   ?? '';
$picture= $data['picture'] ?? '';
$footer= $data['footer'] ?? '';
$titleColor = $data['title_color'] ?? '#ffffff';
$registration_fee = $data['registration_fee'] ?? 0;
$accueil_active = $data['accueil_active'] ? 1 : 0;
$div_reglementation = $data['div_reglementation'] ?? '';

// Formulaire
$stmt = $pdo->prepare('SELECT * FROM forms');
$stmt->execute();

$required_fields = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $required_fields[$row['fields']] = $row['required'] ? 1 : 0;
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inscription - Forbach en Rose</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{
    --rose-500:#ec4899;
    --rose-600:#db2777;
    --bg-grad:linear-gradient(135deg,#ffe1f0 0%,#fff 40%,#ffe1f0 100%);
  }
  body{
    background:var(--bg-grad);
    min-height:100vh;
    display:flex;
    flex-direction:column;
  }

  .hero{
    background:var(--rose-500);
    color:#fff;
    padding:4rem 1rem 5rem;
    position:relative;
    text-align:center;
  }
  .hero h1{font-size:2.6rem;font-weight:700;letter-spacing:1px;}
  .badge-donation{
    background:#fff;
    color:var(--rose-600);
    border-radius:1rem;
    padding:.4rem .9rem;
    font-weight:600;
  }
  .hero-inner{max-width:800px;margin:0 auto;}
  .logo-top{
    position:absolute;
    top:1rem;
    right:1rem;
    max-width:220px;
    width:27vw;
    filter:drop-shadow(0 3px 6px rgba(0,0,0,.2));
    cursor: pointer;
  }

  .back-link {
    position: absolute;
    top: 1rem;
    left: 1rem;
    color: white;
    text-decoration: none;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: rgba(255,255,255,.15);
    border-radius: 12px;
    transition: all .2s ease;
  }
  .back-link:hover {
    background: rgba(255,255,255,.25);
    color: white;
    transform: translateX(-4px);
  }

  .card-form{
    max-width:1100px;
    margin-top:-3.2rem;
    border:0;
    box-shadow:0 0 25px rgba(0,0,0,.1);
  }
  .btn-rose{
    background:var(--rose-600);
    border:0;
  }
  .btn-rose:hover{background:#c13778;}
  .form-control,
  .form-select{border-radius:1rem;}

  .iframe-asc-container,
  .iframe-asc-container iframe{
    width:1100px !important;
    max-width:100% !important;
  }
  .iframe-asc-container{margin-bottom:2rem;}

  @media (max-width:575.98px){
    .logo-top{display:none;}
    .back-link{font-size: 0.9rem; padding: 6px 12px;}
    .hero{padding:3rem 1rem 4rem;}
    .hero h1{font-size:1.6rem;}
    .hero p{font-size:.9rem;}
    .card-form{
      max-width:100%;
      margin-top:-2rem;
    }
    #fPub .col-md-6{
      flex:0 0 100%;
      max-width:100%;
    }
    .btn-rose.btn-lg{
      font-size:1rem;
      padding:.65rem 1rem;
    }
  }
</style>
</head>

<body>

<header class="hero">
  <a href="accueil.php" class="back-link">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M19 12H5M12 19l-7-7 7-7"/>
    </svg>
    Retour
  </a>

  <?php if (!empty($picture)): ?>
  <a href="accueil.php">
    <img src="../files/_pictures/<?= htmlspecialchars($picture) ?>"
         alt="Logo Forbach en Rose" class="logo-top">
  </a>
  <?php endif; ?>

  <div class="hero-inner">
    <h1 style="color: <?= htmlspecialchars($titleColor) ?>;"><?= htmlspecialchars($title) ?></h1>
    <p class="mb-3">7 km solidaires contre le cancer du sein</p>
    <span class="badge-donation"><?= htmlspecialchars($registration_fee) ?> € intégralement reversés</span>
  </div>
</header>

<?php if ($accueil_active === 0): ?>
  <main class="container-fluid px-0 flex-grow-1 d-flex justify-content-center">
    <div class="card card-form p-4 bg-white">
      <div class="p-4 w-100" role="alert" style="margin-top:5%; font-size: 1.2rem; background-color: #ffe1f0; color: #e03f8a; border-radius: 1rem;">
        🚫 Les inscriptions sont actuellement fermées. Merci de votre compréhension.
      </div>
    </div>
  </main>
<?php else: ?>

<main class="container-fluid px-0 flex-grow-1 d-flex justify-content-center">
  <div class="card card-form p-4 bg-white">

    <?php if ($errorMessage): ?>
      <div class="alert alert-danger text-center mb-4">
        <?= htmlspecialchars($errorMessage) ?>
      </div>
      <div class="text-center">
        <a href="?" class="btn btn-rose">Retour à l'accueil</a>
      </div>
    <?php elseif ($hasGetParams && $qrData): ?>
      <?php if ($success_message): ?>
        <div class="alert alert-success text-center mb-4">
          <?= htmlspecialchars($success_message) ?>
        </div>
      <?php endif; ?>

      <?php if ($error_message): ?>
        <div class="alert alert-danger text-center mb-4">
          <?= htmlspecialchars($error_message) ?>
        </div>
      <?php endif; ?>

      <h2 class="text-center mb-4">Inscription via QR Code</h2>

      <?php if ($qrData['organisation']): ?>
        <div class="text-center mb-4">
          <strong>Lieu d'inscription :</strong> <?= htmlspecialchars($qrData['organisation']) ?>
          <?php if ($qrData['description']): ?>
            <br><small><?= htmlspecialchars($qrData['description']) ?></small>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form id="fPub" class="row g-3 needs-validation" method="POST" action="" novalidate>
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
        <input type="hidden" name="qr_token" value="<?= htmlspecialchars($qrToken) ?>">
        <input type="hidden" name="origine" value="QR-<?= htmlspecialchars($qrData['organisation']) ?>">
        <input type="hidden" name="paiement_mode" value="En ligne">

        <div class="col-12 d-grid">
          <button class="btn btn-rose btn-lg" type="submit">
            Valider l'inscription
          </button>
        </div>
      </form>
    <?php else: ?>
      <?php if ($success_message): ?>
        <div class="alert alert-success text-center mb-4">
          <?= htmlspecialchars($success_message) ?>
        </div>
      <?php endif; ?>

      <?php if ($error_message): ?>
        <div class="alert alert-danger text-center mb-4">
          <?= htmlspecialchars($error_message) ?>
        </div>
      <?php endif; ?>

      <h2 class="text-center mb-4">Inscription en ligne</h2>

      <?php
      if ($assoconnectIframe && $assoconnectJs) {
          echo $assoconnectIframe, PHP_EOL, $assoconnectJs;
      }
      ?>
    <?php endif; ?>
  </div>
</main>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<div class="col-12 d-grid">
  <button type="button" class="btn btn-link mt-2" data-bs-toggle="modal" data-bs-target="#reglementModal">
    Voir la réglementation de la course
  </button>
</div>

<div class="modal fade" id="reglementModal" tabindex="-1" aria-labelledby="reglementModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="reglementModalLabel">Réglementation de la course</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <?= $div_reglementation ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<footer class="text-center py-3 small text-muted"><?= htmlspecialchars($footer) ?></footer>

</body>
</html>
