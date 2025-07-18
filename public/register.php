<?php require '../config/config.php';
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
$registration_fee = $data['registration_fee'] ?? 0;  
$accueil_active = $data['accueil_active'] ? 1 : 0;


// reglementation
$div_reglementation = $data['div_reglementation'] ?? ''; 
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forbach en Rose – Inscription</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
  /* ───────── Palette & background ───────── */
  :root{
    --rose-500:#ff4f9c;
    --rose-600:#e03f8a;
    --bg-grad:linear-gradient(135deg,#ffe1f0 0%,#fff 40%,#ffe1f0 100%);
  }
  body{
    background:var(--bg-grad);
    min-height:100vh;
    display:flex;
    flex-direction:column;
  }

  /* ───────── HERO ───────── */
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
  }

  /* ───────── STEPPER ───────── */
  .stepper{
    margin:-.5rem 0 2rem;          /* ← compacte + remonte légèrement */
    padding:0;
    list-style:none;
  }
  .stepper li{
    position:relative;
    text-align:center;
    flex:1 1 0;
    color:#999;
    font-size:.9rem;
  }
  .stepper li .circle{
    display:inline-flex;
    width:32px;
    height:32px;
    align-items:center;
    justify-content:center;
    border-radius:50%;
    border:3px solid #999;
    font-weight:600;
  }
  .stepper li::after{
    content:'';
    position:absolute;
    top:15px;
    left:50%;
    width:100%;
    height:3px;
    background:#999;
    z-index:-1;
  }
  .stepper li:last-child::after{display:none;}
  .stepper li.active .circle,
  .stepper li.done  .circle{
    border-color:var(--rose-600);
    background:var(--rose-600);
    color:#fff;
  }
  .stepper li.active,
  .stepper li.done{color:var(--rose-600);}
  .stepper li.done::after{background:var(--rose-600);}

  /* ───────── FORMULAIRE & carte ───────── */
  .card-form{
    max-width:1100px;     /* ← plus large qu’avant (760px) */
    margin-top:-3.2rem;  /* ← chevauche le HERO pour l’effet “posé” */
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
  .step-section.d-none{display:none!important;}

  /* Pleine largeur pour le widget paiement */
  #step-2 .iframe-asc-container,
  #step-2 .iframe-asc-container iframe{
    width:1100px !important;
    max-width:100% !important;
  }
  #step-2 .iframe-asc-container{margin-bottom:2rem;}

  /* ───────── MOBILE (<576 px) ───────── */
  @media (max-width:575.98px){
    .logo-top{display:none;}
    .hero{padding:3rem 1rem 4rem;}
    .hero h1{font-size:1.6rem;}
    .hero p{font-size:.9rem;}
    .card-form{
      max-width:100%;
      margin-top:-2rem;       /* chevauchement un peu moins prononcé sur mobile */
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

<!-- ───────── HERO ───────── -->
<header class="hero">
  <img src="../files/_pictures/<?= htmlspecialchars($picture) ?>"
       alt="Logo Forbach en Rose" class="logo-top">
  <div class="hero-inner">
    <h1 style="color: <?= htmlspecialchars($titleColor) ?>;"><?= htmlspecialchars($title) ?></h1>
    <p class="mb-3">7 km solidaires contre le cancer du sein</p>
    <span class="badge-donation"><?= htmlspecialchars($registration_fee) ?> € intégralement reversés</span>
  </div>
</header>

<!-- ───────── MAIN ───────── -->
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

    <!-- STEPPER -->
    <ul id="stepper" class="stepper d-flex justify-content-center">
      <li data-step="1" class="active"><span class="circle">1</span><span class="label ms-2">Infos</span></li>
      <li data-step="2"><span class="circle">2</span><span class="label ms-2">Paiement</span></li>
      <li data-step="3"><span class="circle">3</span><span class="label ms-2">Confirmation</span></li>
    </ul>

    <!-- ÉTAPE 1 : formulaire -->
    <div id="step-1" class="step-section">
      <h2 class="text-center mb-4">Inscrivez-vous en ligne</h2>

      <form id="fPub" class="row g-3 needs-validation" novalidate>
        <div class="col-md-6"><label class="form-label">Nom</label>
          <input name="nom" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">Prénom</label>
          <input name="prenom" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">Téléphone</label>
          <input name="tel" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Email</label>
          <input name="email" type="email" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Date de naissance</label>
          <input name="naissance" type="date" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Sexe</label>
          <select name="sexe" class="form-select"><option>H</option><option>F</option><option>Autre</option></select></div>
        <div class="col-md-6"><label class="form-label">Ville</label>
          <input name="ville" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Entreprise</label>
          <input name="entreprise" class="form-control"></div>

        <!-- champs masqués ajoutés en fin de process -->
        <input type="hidden" name="tshirt_size" value="-">
        <input type="hidden" name="origine"     value="en ligne">
        <input type="hidden" name="paiement_mode" value="en ligne (CB)">

        <div class="col-12 d-grid">
          <button id="btn-to-pay" class="btn btn-rose btn-lg" type="button">
            Continuer vers le paiement
          </button>
        </div>
      </form>
    </div><!-- /étape 1 -->

    <!-- ÉTAPE 2 : widget AssoConnect -->
    <div id="step-2" class="step-section d-none">
      <h2 class="text-center mb-4">Règlement sécurisé</h2>

      <?php
        if ($assoconnectIframe && $assoconnectJs) {
            echo $assoconnectIframe, PHP_EOL, $assoconnectJs;
        }
      ?>

      <p class="mt-3 text-center"><em>Une fois le paiement validé, vous passez automatiquement à la confirmation.</em></p>
    </div><!-- /étape 2 -->

    <!-- ÉTAPE 3 : confirmation -->
    <div id="step-3" class="step-section d-none text-center">
      <div class="alert alert-success mb-4">
        <h2 class="h4 mb-1">✅ Paiement accepté !</h2>
        <p class="mb-0">Cliquez pour finaliser votre inscription.</p>
      </div>
      <button id="btn-final" class="btn btn-rose btn-lg">Valider l’inscription</button>
    </div><!-- /étape 3 -->

  </div><!-- /card -->
</main>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-KE9wPQ6…(clé-cdn)…" crossorigin="anonymous"></script>

<div class="col-12 d-grid">
  <button type="button" class="btn btn-link mt-2" data-bs-toggle="modal" data-bs-target="#reglementModal">
    Voir la réglementation de la course
  </button>
</div>

<!-- Modal -->
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

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
/* ───────── Gestion des étapes ───────── */
const stepper   = $('#stepper li');
const section   = n => $('#step-'+n);
function gotoStep(n){
  stepper.removeClass('active').slice(0,n-1).addClass('done');
  stepper.eq(n-1).addClass('active');
  $('.step-section').addClass('d-none');
  section(n).removeClass('d-none');
}

/* Étape 1 → 2 */
let storedData = null;
$('#btn-to-pay').on('click', ()=>{
  const form = $('#fPub')[0];
  if(!form.checkValidity()){ form.reportValidity(); return; }
  storedData = Object.fromEntries(new FormData(form));
  gotoStep(2);
});

/* Écoute postMessage du widget AssoConnect */
window.addEventListener('message', ({origin,data})=>{
  if(!/\.assoconnect\.com$/.test(new URL(origin).hostname)) return;

  /* redimensionnement auto */
  if(data.action === 'iframe.height'){
    document.querySelector('.iframe-asc-container iframe')
            ?.style.setProperty('height', data.height+'px');
  }

  /* succès paiement */
  if(data.event === 'payment:success'     ||
     data.action=== 'collect.completed'   ||
     data.event === 'collect:completed'){
       gotoStep(3);
  }
});

/* Étape 3 → inscription en base */
$('#btn-final').on('click', ()=>{
  if(!storedData){ alert("Données manquantes."); return; }

  fetch('../config/api.php?route=registrations',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify(storedData)
  })
  .then(r=>r.json())
  .then(j=>{
    if(!j.ok){ alert("Erreur d’enregistrement."); return; }
    alert("👍 Inscription terminée, merci !");
    location.replace(location.pathname);   // recharge propre
  });
});
</script>
</body>
</html>
