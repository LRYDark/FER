<?php
require '../config/config.php';
require_once '../config/tracker.php';
trackPageVisit();
checkMaintenance();
require_once '../config/csrf.php';
require '../inc/navbar-data.php';

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
            'SELECT organisation, description, is_active, onsite_mode, payment_label
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
    if (!csrf_verify()) {
        $error_message = 'Session expirée. Veuillez réessayer.';
    } else {
    $submittedToken = $_POST['qr_token'] ?? '';
    $validToken = false;

    if ($submittedToken) {
        try {
            $stmt = $pdo->prepare(
                'SELECT organisation, description, is_active, onsite_mode, payment_label
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
            // Compteur atomique — évite la race condition (CWE-362)
            $counterExists = false;
            try {
                $pdo->query('SELECT next_no FROM inscription_counter LIMIT 0');
                $counterExists = true;
            } catch (PDOException $e) {}

            if ($counterExists) {
                $pdo->exec('UPDATE inscription_counter SET next_no = LAST_INSERT_ID(next_no + 1) WHERE id = 1');
                $nextInscriptionNo = 'S' . (int)$pdo->lastInsertId();
            } else {
                $stmt2 = $pdo->prepare("SELECT MAX(CAST(REPLACE(REPLACE(inscription_no, 'S', ''), 'E', '') AS UNSIGNED)) as max_no FROM registrations");
                $stmt2->execute();
                $result2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                $nextInscriptionNo = 'S' . (($result2['max_no'] ?? 0) + 1);
            }

            // Construction dynamique des données à insérer
            require_once '../config/form_fields.php';
            $fieldCols = getAllActiveFieldColumns($pdo);

            $formData = ['inscription_no' => $nextInscriptionNo];
            $columns = ['inscription_no'];
            $placeholders = [':inscription_no'];

            foreach ($fieldCols as $col => $meta) {
                $raw = $_POST[$col] ?? '';
                if ($col === 'commentaire') $raw = mb_substr((string) $raw, 0, 2000);
                $formData[$col] = $meta['encrypted'] ? encrypt($raw) : $raw;
                $columns[] = "`{$col}`";
                $placeholders[] = ":{$col}";
            }

            // Mode « inscription sur place » (défini sur le QR) : la prestation est
            // choisie directement et la méthode de paiement est imposée côté serveur
            // (valeur du QR, jamais le champ caché du client → non falsifiable).
            $isOnsite = !empty($tokenData['onsite_mode']);

            // origine : depuis le POST (champ caché QR-<orga>), sinon défaut.
            if (!isset($formData['origine'])) {
                $formData['origine'] = $_POST['origine'] ?? 'en ligne';
                $columns[] = '`origine`';
                $placeholders[] = ':origine';
            }

            // paiement_mode : en mode sur place → valeur serveur du QR (payment_label) ;
            // sinon → valeur du formulaire classique.
            if (!isset($formData['paiement_mode'])) {
                if ($isOnsite) {
                    $pl = mb_substr(trim((string)($tokenData['payment_label'] ?? 'retrait t-shirt')), 0, 50);
                    $formData['paiement_mode'] = ($pl !== '') ? $pl : 'retrait t-shirt';
                } else {
                    $formData['paiement_mode'] = $_POST['paiement_mode'] ?? 'en ligne (CB)';
                }
                $columns[] = '`paiement_mode`';
                $placeholders[] = ':paiement_mode';
            }

            // Montant dû + prestation (toujours calculés côté serveur).
            $stmtFee = $pdo->prepare('SELECT registration_fee FROM setting WHERE id = 1 LIMIT 1');
            $stmtFee->execute();
            $regFee = (float) ($stmtFee->fetchColumn() ?: 0);

            $allowedPresta = ['tarif_unique', 'enfant_gratuit', 'enfant_tshirt'];
            $postedPresta  = strtolower(trim((string) ($_POST['prestation'] ?? '')));
            if ($isOnsite && in_array($postedPresta, $allowedPresta, true)) {
                // Prestation choisie explicitement sur le formulaire sur place.
                $formData['prestation'] = $postedPresta;
                $formData['montant_du'] = ($postedPresta === 'enfant_gratuit') ? 0.0 : $regFee;
            } else {
                // Comportement historique : déduit du mode de paiement.
                $formData['montant_du'] = (strcasecmp((string)($formData['paiement_mode'] ?? ''), 'gratuit') === 0) ? 0.0 : $regFee;
                $pmPub = strtolower(trim((string)($formData['paiement_mode'] ?? '')));
                $formData['prestation'] = ($pmPub === 'gratuit') ? 'enfant_gratuit'
                                        : (($pmPub === 'enfant_tshirt') ? 'enfant_tshirt' : 'tarif_unique');
            }
            $columns[] = '`montant_du`';
            $placeholders[] = ':montant_du';
            $columns[] = '`prestation`';
            $placeholders[] = ':prestation';

            $columns[] = 'created_at';
            $placeholders[] = 'NOW()';

            // Inscription publique / QR : date d'inscription = maintenant (aucun
            // antidatage côté public). Ici created_at (date d'ajout) = même instant.
            $columns[] = 'date_inscription';
            $placeholders[] = 'NOW()';

            $colStr = implode(', ', $columns);
            $phStr  = implode(', ', $placeholders);

            $stmt = $pdo->prepare("INSERT INTO registrations ({$colStr}) VALUES ({$phStr})");
            $stmt->execute($formData);

            $subject = 'Inscription enregistrée - Forbach en Rose';
            if(($_POST['email'] ?? '') != ''){
              try {
                require_once '../config/googleMail.php';
                sendMail($_POST['email'], $subject, null, null, $_POST['nom'] ?? '', $_POST['prenom'] ?? '', 'inscription', $nextInscriptionNo);
              } catch (\Throwable $e) { /* mail failure does not block registration */ }
            }
            $success_message = "👍 Inscription enregistrée avec succès !";

        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            $error_message = "Erreur lors de l'enregistrement. Veuillez réessayer.";
        }
    }
  } // end csrf_verify else
}

// Récupération des paramètres de configuration
try {
    $stmt = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => 1]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $data = [];
}

$assoconnectJs      = $data['assoconnect_js']     ?? null;
$assoconnectIframe  = $data['assoconnect_iframe'] ?? null;
$assoconnectUrl     = $data['assoconnect_url']    ?? '';
$title  = $data['title']   ?? '';
$registration_fee = $data['registration_fee'] ?? 0;
$childAge = (int) ($data['child_age_threshold'] ?? 12); // libellé « -N ans »
$course_km = $data['course_km'] ?? 7;
$accueil_active = !empty($data['accueil_active']) ? 1 : 0;

// Ouverture / fermeture automatique des inscriptions
$tz = new DateTimeZone('Europe/Paris');
$now = new DateTime('now', $tz);
$autoOpen  = !empty($data['registration_auto_open'])  ? new DateTime($data['registration_auto_open'], $tz)  : null;
$autoClose = !empty($data['registration_auto_close']) ? new DateTime($data['registration_auto_close'], $tz) : null;

if ($autoOpen && $now >= $autoOpen) {
    $accueil_active = 1;
}
if ($autoClose && $now >= $autoClose) {
    $accueil_active = 0;
}
$div_reglementation = $data['div_reglementation'] ?? '';

// Formulaire dynamique
require_once '../config/form_fields.php';
try {
    $formFields = getActiveFields($pdo, 'qr');
} catch (PDOException $e) {
    error_log('[REGISTER] ' . $e->getMessage());
    $formFields = [];
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inscription</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
      rel="stylesheet"
      integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
      crossorigin="anonymous">
<link rel="stylesheet" href="../css/fer-modern.css">
<link rel="stylesheet" href="../css/register.css?v=<?= @filemtime(__DIR__ . '/../css/register.css') ?: time() ?>">
<?php include __DIR__ . '/../config/theme.php'; ?>
</head>

<body>

<div class="register-title-bar">
  <a href="accueil" title="Retour" class="back-btn">
    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3.3 11.3l6.8-6.8c.4-.4.4-1 0-1.4s-1-.4-1.4 0l-7.8 7.8c-.4.4-.4 1 0 1.4l7.8 7.8c.2.2.5.3.7.3s.5-.1.7-.3c.4-.4.4-1 0-1.4L3.3 12.7H22c.6 0 1-.4 1-1s-.4-1-1-1H3.3z"/></svg>
  </a>
  <div class="register-title-info">
    <h1>Inscription</h1>
    <span class="register-subtitle"><?= (int)$course_km ?> km course et marche solidaire contre le cancer du sein</span>
  </div>
  <?php if (!empty($registration_fee)): ?>
    <span class="register-donation-badge"><?= htmlspecialchars($registration_fee) ?> € intégralement reversés</span>
  <?php endif; ?>
</div>

<?php if ($accueil_active === 0): ?>
  <main class="container-fluid px-0 flex-grow-1 d-flex justify-content-center">
    <div class="card card-form p-4 bg-white">
      <div class="p-4 w-100" role="alert" style="margin-top:5%; font-size: 1.2rem; background-color: #ffe1f0; color: #e03f8a; border-radius: var(--radius-lg);">
        🚫 Les inscriptions sont actuellement fermées. Merci de votre compréhension.
      </div>
      <?php if ($autoOpen && $now < $autoOpen): ?>
        <p style="text-align:center; margin-top:20px; font-size:1rem; color:#b5366b;">
          📅 Les inscriptions ouvriront le <strong><?= $autoOpen->format('d/m/Y') ?></strong> à <strong><?= $autoOpen->format('H\hi') ?></strong>.
        </p>
      <?php endif; ?>
    </div>
  </main>
<?php else: ?>

<main class="container-fluid px-0 flex-grow-1 d-flex justify-content-center">
  <div class="card card-form p-4 bg-white">
    <div class="register-page-title text-center mb-3" style="font-size:clamp(24px,4vw,42px);font-weight:900;"><?= $title ?></div>

    <?php if ($errorMessage): ?>
      <div class="alert alert-danger text-center mb-4">
        <?= htmlspecialchars($errorMessage) ?>
      </div>
      <div class="text-center">
        <a href="?" class="btn-action-primary">Retour à l'accueil</a>
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
        <?= csrf_field() ?>
        <?php foreach ($formFields as $f): ?>
          <?= renderFormField($f, '', false, 'qr') ?>
        <?php endforeach; ?>

        <input type="hidden" name="qr_token" value="<?= htmlspecialchars($qrToken) ?>">
        <input type="hidden" name="origine" value="QR-<?= htmlspecialchars($qrData['organisation']) ?>">

        <?php
          // Montant formaté (ex. « 12 € ») réutilisé par l'affichage et le script.
          $onFee      = (float) $registration_fee;
          $onFeeLabel = rtrim(rtrim(number_format($onFee, 2, '.', ''), '0'), '.') . ' €';
        ?>
        <?php if (!empty($qrData['onsite_mode'])): ?>
          <?php // Mode « inscription sur place » : choix de la prestation (paiement masqué, imposé côté serveur). ?>
          <div class="col-md-12">
            <label class="form-label">Prestation <span style="color:#ef4444">*</span></label>
            <select name="prestation" id="prestation_public" class="form-select" required>
              <option value="tarif_unique">Tarif unique — <?= htmlspecialchars($onFeeLabel) ?></option>
              <option value="enfant_gratuit">Enfant −<?= (int) $childAge ?> ans — Gratuit</option>
              <option value="enfant_tshirt">Enfant −<?= (int) $childAge ?> ans avec T-shirt — <?= htmlspecialchars($onFeeLabel) ?></option>
            </select>
            <div id="montantDuPublic" class="mt-2" style="font-size:14px;font-weight:600;color:#1e293b">
              Montant total dû : <span style="color:#F42182"><?= htmlspecialchars($onFeeLabel) ?></span>
            </div>
          </div>
        <?php else: ?>
          <div class="col-md-12">
            <label class="form-label">Paiement <span style="color:#ef4444">*</span></label>
            <select name="paiement_mode" id="paiement_mode_public" class="form-select" required>
              <option value="En ligne" selected>En ligne (CB)</option>
              <option value="gratuit">Gratuit / Enfant -<?= $childAge ?> ans (sans T-shirt)</option>
            </select>
            <div id="montantDuPublic" class="mt-2" style="font-size:14px;font-weight:600;color:#1e293b">
              Montant total dû : <span style="color:#F42182"><?= htmlspecialchars((string) $registration_fee) ?> €</span>
            </div>
          </div>
        <?php endif; ?>

        <div class="col-12 d-grid">
          <button class="btn-action-primary btn-action-lg" type="submit">
            Valider l'inscription
          </button>
        </div>
      </form>
      <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
      (function(){
        var sel = document.getElementById('paiement_mode_public');
        var disp = document.getElementById('montantDuPublic');
        if(!sel || !disp) return;
        var fee = <?= json_encode((float) $registration_fee) ?>;
        function fmt(n){ var v=parseFloat(n); if(!isFinite(v)) v=0; return v.toFixed(2).replace(/\.00$/,'') + ' €'; }
        function update(){
          if(sel.value === 'gratuit'){
            disp.innerHTML = 'Montant dû : <span style="color:#16a34a">' + fmt(0) + '</span>';
          } else {
            disp.innerHTML = 'Montant total dû : <span style="color:#F42182">' + fmt(fee) + '</span>';
          }
        }
        sel.addEventListener('change', update);
        update();
      })();
      </script>
      <script src="../js/inscription-form.js?v=5" nonce="<?= $GLOBALS['csp_nonce'] ?>"></script>
      <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
      (function(){
        var form = document.getElementById('fPub');
        if(!form || !window.FERInscription) return;
        FERInscription.initForm(form);
        form.addEventListener('submit', function(e){
          // Inscrit mineur : responsable légal obligatoire (formulaire en novalidate).
          if (!FERInscription.ensureGuardian(form)) { e.preventDefault(); return; }
          // Compose le commentaire (autorisation responsable) puis normalise la naissance.
          FERInscription.composeComment(form);
          var b = form.querySelector('[name="naissance"]');
          if (b) b.value = FERInscription.normalizeBirthValue(b.value);
        });
      })();
      </script>

      <?php if (!empty($qrData['onsite_mode'])): ?>
      <?php // Mode sur place : filtre les prestations selon l'âge saisi + relie le choix
            // « enfant » à l'affichage de l'autorisation parentale (comme si la date de
            // naissance d'un mineur était renseignée). ?>
      <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
      (function(){
        var form   = document.getElementById('fPub');
        if(!form) return;
        var presta = document.getElementById('prestation_public');
        var disp   = document.getElementById('montantDuPublic');
        var birth  = form.querySelector('[name="naissance"]');
        var block  = form.querySelector('[data-guardian-block]');
        if(!presta) return;

        var childAge = <?= (int) $childAge ?>;
        var feeLabel = <?= json_encode($onFeeLabel) ?>;
        var LABELS = {
          tarif_unique:   'Tarif unique — ' + feeLabel,
          enfant_gratuit: 'Enfant −' + childAge + ' ans — Gratuit',
          enfant_tshirt:  'Enfant −' + childAge + ' ans avec T-shirt — ' + feeLabel
        };

        function currentAge(){
          if (!birth || !window.FERInscription) return null;
          return FERInscription.ageFromBirth(FERInscription.normalizeBirthValue(birth.value));
        }

        // Reconstruit la liste des prestations selon l'âge :
        //   âge ≥ seuil          → Tarif unique seulement
        //   âge < seuil          → les 2 prestations enfant
        //   âge non renseigné    → les 3
        function rebuild(){
          var age = currentAge();
          var allow;
          if (age == null)            allow = ['tarif_unique','enfant_gratuit','enfant_tshirt'];
          else if (age >= childAge)   allow = ['tarif_unique'];
          else                        allow = ['enfant_gratuit','enfant_tshirt'];
          var cur = presta.value;
          presta.innerHTML = allow.map(function(v){
            return '<option value="'+v+'"'+((v===cur)?' selected':'')+'>'+LABELS[v]+'</option>';
          }).join('');
          if (allow.indexOf(cur) < 0) presta.selectedIndex = 0;
          updateMontant();
          updateGuardian();
        }

        function updateMontant(){
          if (!disp) return;
          disp.innerHTML = (presta.value === 'enfant_gratuit')
            ? 'Montant dû : <span style="color:#16a34a">0 €</span>'
            : 'Montant total dû : <span style="color:#F42182">' + feeLabel + '</span>';
        }

        // Un enfant −childAge ans est forcément mineur → on affiche l'autorisation
        // parentale dès qu'une prestation « enfant » est choisie (même sans date de
        // naissance). Sinon, on laisse la date de naissance piloter l'affichage.
        function updateGuardian(){
          var isChild = (presta.value === 'enfant_gratuit' || presta.value === 'enfant_tshirt');
          if (!block) return;
          if (isChild){
            // Un enfant −childAge ans est mineur → on force l'affichage du bloc (les
            // champs personnalisés sont dedans, ils apparaissent avec lui) même sans
            // date de naissance. Chaque champ suit son propre « requis ».
            block.style.display = '';
            var reqd = block.getAttribute('data-guardian-required') !== '0';
            block.querySelectorAll('[data-guardian]').forEach(function(el){
              var isCustom = el.getAttribute('data-guardian') === 'custom';
              var elReq = isCustom ? (el.getAttribute('data-guardian-req') === '1') : reqd;
              if (elReq) el.setAttribute('required',''); else el.removeAttribute('required');
            });
          } else {
            block.querySelectorAll('[data-guardian]').forEach(function(el){ el.removeAttribute('required'); });
            if (window.FERInscription) FERInscription.refresh(form); // rebascule selon la naissance
          }
        }

        presta.addEventListener('change', function(){ updateMontant(); updateGuardian(); });
        if (birth){
          ['input','change','blur'].forEach(function(ev){ birth.addEventListener(ev, rebuild); });
        }

        // Sécurité : si une prestation « enfant » est choisie, on exige le responsable
        // légal même sans date de naissance (le validateur standard se base sur la date),
        // et on injecte l'autorisation dans le commentaire s'il n'y est pas déjà.
        form.addEventListener('submit', function(e){
          if (presta.value !== 'enfant_gratuit' && presta.value !== 'enfant_tshirt') return;
          if (!block || block.getAttribute('data-guardian-required') === '0') return;
          var nom = block.querySelector('[data-guardian="nom"]');
          var pre = block.querySelector('[data-guardian="prenom"]');
          var nv  = nom ? nom.value.trim() : '';
          var pv  = pre ? pre.value.trim() : '';
          if (!nv || !pv){
            block.style.display = '';
            alert("Inscription d'un mineur : merci d'indiquer le nom et le prénom du responsable légal.");
            e.preventDefault();
            return;
          }
          // Champs personnalisés du bloc (ex. téléphone parent), désormais à l'intérieur.
          var customEls = block.querySelectorAll('[data-guardian="custom"]');
          var extraLines = [];
          for (var x = 0; x < customEls.length; x++){
            if (customEls[x].getAttribute('data-guardian-req') === '1' && !customEls[x].value.trim()){
              customEls[x].focus();
              alert("Inscription d'un mineur : merci de compléter les informations demandées pour le responsable légal.");
              e.preventDefault();
              return;
            }
            var v = (customEls[x].value||'').trim();
            if (v) extraLines.push((customEls[x].getAttribute('data-guardian-key') || 'Info') + ' : ' + v);
          }
          // Injecte l'autorisation dans le commentaire si le validateur standard ne l'a
          // pas déjà fait (prestation enfant choisie sans date de naissance renseignée).
          var com  = form.querySelector('[name="commentaire"]');
          var MARK = 'Autorisation du représentant légal';
          if (com && com.value.indexOf(MARK) < 0){
            var txt = MARK + ' (mineur) : Validé\nNom : ' + nv + '\nPrénom : ' + pv;
            if (extraLines.length) txt += '\n' + extraLines.join('\n');
            com.value = com.value ? (com.value + '\n\n' + txt) : txt;
          }
        });

        rebuild();
      })();
      </script>
      <?php endif; ?>
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
      // 🔒 [SEC-09] Validation domaine AssoConnect avant echo (CWE-79)
      // Méthode DIV + Script (la seule supportée pour un site externe).
      if ($assoconnectIframe && preg_match('#^<div[^>]+data-collect-id=["\'][A-Z0-9]{26}["\']#i', trim($assoconnectIframe))) {
          echo $assoconnectIframe, PHP_EOL;
      }
      if ($assoconnectJs && preg_match('#^<script[^>]+src=["\']https://[a-z0-9.-]*\.assoconnect\.com/#i', trim($assoconnectJs))) {
          echo $assoconnectJs, PHP_EOL;
      }

      // Bouton de repli : lien direct AssoConnect (si le formulaire ne se charge pas)
      $acUrlSafe = ($assoconnectUrl && filter_var($assoconnectUrl, FILTER_VALIDATE_URL) && preg_match('#^https://#i', $assoconnectUrl)) ? $assoconnectUrl : '';
      ?>
      <?php if ($acUrlSafe): ?>
        <div class="text-center mt-4">
          <p class="text-muted small mb-2">Un problème avec le formulaire d'inscription&nbsp;? Ouvrez-le directement sur le site d'AssoConnect.</p>
          <a href="<?= htmlspecialchars($acUrlSafe, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:6px">
              <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
              <path d="M15 3h6v6M10 14 21 3"/>
            </svg>S'inscrire directement sur AssoConnect
          </a>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>

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
        <?php
        // 🔒 [FIX-01] Sanitisation HTML via DOMDocument whitelist (CWE-79)
        echo sanitizeHtml($div_reglementation ?? '');
        ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<!-- Lightbox pour images TinyMCE -->
<div class="tiny-lightbox" id="tinyLightbox">
  <span class="tiny-lightbox-close">&times;</span>
  <img class="tiny-lightbox-img" id="tinyLightboxImg" alt="">
</div>


<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  const lb = document.getElementById('tinyLightbox');
  const lbImg = document.getElementById('tinyLightboxImg');
  if (!lb) return;
  document.querySelectorAll('.modal-body img').forEach(img => {
    img.addEventListener('click', () => { lbImg.src = img.src; lb.classList.add('active'); });
  });

  // Transformer les liens PDF en jolis boutons (dédupliqués)
  const seenPdf = new Set();
  document.querySelectorAll('.modal-body a[href$=".pdf"]').forEach(a => {
    const href = a.getAttribute('href');
    if (seenPdf.has(href)) { a.remove(); return; }
    seenPdf.add(href);
    const raw = (a.title || href.split('/').pop()).replace(/\.[^.]+$/, '');
    const name = /^tiny_[a-f0-9.]+$/.test(raw) ? 'Document' : raw;
    a.className = 'pdf-link';
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    a.innerHTML = '<span class="pdf-link-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15l3 3 3-3"/></svg></span><span class="pdf-link-info"><span class="pdf-link-name">' + name + '.pdf</span><span class="pdf-link-hint">Cliquer pour ouvrir</span></span>';
  });
  lb.querySelector('.tiny-lightbox-close').addEventListener('click', () => lb.classList.remove('active'));
  lb.addEventListener('click', e => { if (e.target === lb) lb.classList.remove('active'); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') lb.classList.remove('active'); });
})();
</script>

<?php include '../inc/footer-modern.php'; ?>

<script src="../js/fer-modern.js"></script>

</body>
</html>
