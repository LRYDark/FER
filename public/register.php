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
                'nom' => encrypt($_POST['nom'] ?? ''),
                'prenom' => encrypt($_POST['prenom'] ?? ''),
                'tel' => encrypt($_POST['tel'] ?? ''),
                'email' => encrypt($_POST['email'] ?? ''),
                'naissance' => encrypt($_POST['naissance'] ?? ''),
                'sexe' => $_POST['sexe'] ?? '',
                'ville' => encrypt($_POST['ville'] ?? ''),
                'entreprise' => encrypt($_POST['entreprise'] ?? ''),
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
<title>Inscription</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{
    --rose-500:#ec4899;
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
  .hero-lead{
    margin:0 0 .45rem;
    font-size:1rem;
    font-weight:500;
  }
  .back-link {
    position: absolute;
    top: .65rem;
    left: 1rem;
    color: white;
    text-decoration: none;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 13px;
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
    margin-top:calc(-.75rem - 20px);
    margin-bottom:12px;
    border:0;
    box-shadow:0 0 25px rgba(0,0,0,.1);
  }

  .reglement-wrap{
    width:min(100%,1100px);
    margin:0 auto 8px;
    padding:0 12px;
    display:flex;
    justify-content:center;
  }

  .reglement-cta{
    display:inline-flex;
    align-items:center;
    gap:10px;
    border:0;
    border-radius:12px;
    background:var(--rose-600);
    color:#fff;
    padding:12px 16px;
    font-size:.96rem;
    font-weight:600;
    box-shadow:0 8px 18px rgba(219,39,119,.25);
    transition:transform .16s ease, box-shadow .16s ease, background .16s ease;
  }
  .reglement-cta:hover{
    transform:translateY(-1px);
    box-shadow:0 12px 24px rgba(219,39,119,.32);
    background:#c2256a;
  }

  .register-page-title{
    color:#111827;
    font-size:clamp(1.42rem,2.6vw,1.98rem);
    font-weight:400;
    letter-spacing:-.02em;
  }

  .register-online-title{
    font-size:clamp(.98rem,1.35vw,1.16rem);
    font-weight:400;
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

  @media (max-width:767.98px){
    .hero{
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:flex-start;
      padding:1rem .75rem calc(1.15rem + 10px);
      min-height:108px;
    }
    .back-link{
      position:relative;
      top:auto;
      left:auto;
      align-self:flex-start;
      margin:0 0 .5rem;
      padding:6px 10px;
      font-size:.84rem;
      gap:6px;
      border-radius:10px;
    }
    .back-link svg{
      width:16px;
      height:16px;
    }
    .hero-inner{
      width:100%;
      margin:0;
      padding:0 4px;
    }
    .hero-lead{
      font-size:.8rem;
      line-height:1.25;
      margin:0 0 .38rem;
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
    .reglement-wrap{
      padding:0 10px;
      margin:0 auto 10px;
    }
    .reglement-cta{
      font-size:.9rem;
      padding:10px 14px;
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
  <a href="accueil" class="back-link">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M19 12H5M12 19l-7-7 7-7"/>
    </svg>
    Retour
  </a>

  <div class="hero-inner">
    <p class="hero-lead">7 km course et marche solidaire contre le cancer du sein</p>
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
    <h1 class="register-page-title text-center mb-3"><?= htmlspecialchars($title) ?></h1>

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

      <h2 class="register-online-title text-center mb-4">Inscription en ligne</h2>

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

<div class="reglement-wrap">
  <button type="button" class="reglement-cta" data-bs-toggle="modal" data-bs-target="#reglementModal">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M9 12h6M12 9l3 3-3 3"/>
      <path d="M5 4h11a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3H5z"/>
    </svg>
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
