<?php
require '../config/config.php';
require_once '../config/csrf.php';
require 'navbar-data.php';
requireRole(['saisie']);

$canCreateReg = canDoAction('dashboard.create_registration');

$stmt = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
$stmt->execute(['id' => 1]);
$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$title        = $data['title']        ?? '';
$title_mobile = $data['title_mobile'] ?? '';

require_once '../config/form_fields.php';
$formFields = getActiveFields($pdo, 'saisie');

$canTshirtMode = false; // pas de mode T-shirts sur la page saisie
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Saisie inscription</title>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>

<?php include 'navbar-admin.php'; ?>

<style nonce="<?= $GLOBALS['csp_nonce'] ?>">
/* ── Saisie : pas de sidebar ── */
#oc-sidebar  { display: none !important; }
.oc-burger   { display: none !important; }
.oc-overlay  { display: none !important; }
#oc-content  {
  border-radius: var(--oc-radius) !important;
  display: flex;
  align-items: flex-start;
  justify-content: center;
}

/* ── Wrapper ── */
.saisie-wrapper {
  width: 100%;
  max-width: 820px;
  padding: 8px 0 40px;
}

/* ── En-tête de page ── */
.saisie-page-header {
  margin-bottom: 20px;
}
.saisie-page-header h1 {
  font-size: 20px;
  font-weight: 700;
  color: #1e293b;
  margin: 0 0 4px;
}
.saisie-page-header p {
  font-size: 13px;
  color: #64748b;
  margin: 0;
}

/* ── Card formulaire ── */
.saisie-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
  padding: 28px 32px 32px;
}

/* ── Champs dans la card ── */
.saisie-card .form-label {
  font-size: 13px;
  font-weight: 600;
  color: #374151;
  margin-bottom: 5px;
}
.saisie-card .form-control,
.saisie-card .form-select {
  border-radius: 6px;
  border: 1px solid #d4c4cb;
  font-size: 13px;
  height: 36px;
  padding: 0 10px;
  color: #1a1a2e;
  background: #fff;
  transition: border-color .15s, box-shadow .15s;
}
.saisie-card textarea.form-control {
  height: auto;
  padding: 8px 10px;
}
.saisie-card .form-control:focus,
.saisie-card .form-select:focus {
  border-color: #F42182;
  box-shadow: 0 0 0 3px rgba(196,87,122,.1);
  outline: none;
}
.saisie-card .form-control::placeholder { color: #a1a1aa; }

/* ── Bouton enregistrer ── */
.btn-saisie {
  width: 100%;
  height: 36px;
  background: #F42182;
  color: #fff;
  border: none;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
  transition: background .15s;
}
.btn-saisie:hover  { background: #a8476a; }
.btn-saisie:active { background: #933d5c; }
.btn-saisie:disabled { opacity: .6; cursor: not-allowed; }

/* ── Message retour ── */
#msg { font-size: 13px; border-radius: 6px; padding: 10px 14px; }
</style>

<div class="saisie-wrapper">

  <div class="saisie-page-header">
    <h1>Ajouter une inscription</h1>
    <p>Interface saisie &mdash; <?= htmlspecialchars(currentOrganisation()) ?></p>
  </div>

  <div class="saisie-card">

    <div id="msg" class="alert d-none mb-3"></div>

    <?php if (!$canCreateReg): ?>
      <div class="alert alert-warning">
        <i class="bi bi-shield-exclamation me-1"></i>
        La permission de créer des inscriptions a été retirée à votre rôle.
        Contactez un administrateur pour la rétablir.
      </div>
    <?php else: ?>

    <form id="fAdd" class="row g-3">
      <?php foreach ($formFields as $f): ?>
        <?= renderFormField($f) ?>
      <?php endforeach; ?>

      <input type="hidden" name="origine" value="<?= htmlspecialchars(currentOrganisation()) ?>">

      <div class="col-md-6">
        <label class="form-label">Paiement <span style="color:#ef4444">*</span></label>
        <select name="paiement_mode" class="form-select" required>
          <option value="" selected disabled hidden>Choisir&hellip;</option>
          <option value="CB">CB</option>
          <option value="espece">Esp&egrave;ces</option>
          <option value="cheque">Ch&egrave;que</option>
        </select>
      </div>

      <div class="col-12 mt-2">
        <button type="submit" class="btn-saisie" id="btnSave">Enregistrer</button>
      </div>
    </form>

    <?php endif; ?>
  </div>
</div>

<?php require 'admin-footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
  var _csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

  document.getElementById('fAdd') && document.getElementById('fAdd').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = document.getElementById('btnSave');
    btn.disabled = true; btn.textContent = 'Enregistrement…';
    var msg = document.getElementById('msg');
    msg.className = 'alert d-none';

    fetch('../config/api.php?route=registrations', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
      body: JSON.stringify(Object.fromEntries(new FormData(e.target)))
    })
    .then(function(r) { return r.json(); })
    .then(function(j) {
      btn.disabled = false; btn.textContent = 'Enregistrer';
      if (j.ok) {
        msg.className = 'alert alert-success';
        msg.textContent = 'Inscription n° ' + j.inscription_no + ' enregistrée !';
        e.target.reset();
        setTimeout(function() { msg.className = 'alert d-none'; }, 5000);
      } else {
        msg.className = 'alert alert-danger';
        msg.textContent = j.err || 'Erreur lors de l\'enregistrement.';
      }
    })
    .catch(function() {
      btn.disabled = false; btn.textContent = 'Enregistrer';
      msg.className = 'alert alert-danger';
      msg.textContent = 'Erreur de communication avec le serveur.';
    });
  });
</script>
</body>
</html>
