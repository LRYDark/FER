<?php
require '../config/config.php';
require_once '../config/csrf.php';
require 'navbar-data.php';
requireRole(['saisie']);

$canCreateReg = canDoAction('dashboard.create_registration');
$canViewTable = canAccessPage('dashboard');
$canEditReg   = canDoAction('dashboard.edit_registration');
$canDeleteReg = canDoAction('dashboard.delete_registration');

$stmt = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
$stmt->execute(['id' => 1]);
$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$title        = $data['title']        ?? '';
$title_mobile = $data['title_mobile'] ?? '';

require_once '../config/form_fields.php';
$formFields = getActiveFields($pdo, 'saisie');

// Champs pour le modal d'édition + colonnes du tableau (uniquement si tableau visible)
$adminFields = [];
$allActiveFields = [];
if ($canViewTable) {
    $adminFields = getActiveFields($pdo, 'admin');
    $stmtAllFields = $pdo->prepare('SELECT * FROM forms WHERE active = 1 ORDER BY sort_order ASC');
    $stmtAllFields->execute();
    $allActiveFields = $stmtAllFields->fetchAll(PDO::FETCH_ASSOC);
}

$canTshirtMode = false; // pas de mode T-shirts sur la page saisie

// Onglet actif (sélectionné via le sidebar global) : 'formulaire' (par défaut) ou 'inscriptions'
$activeTab = (($_GET['tab'] ?? '') === 'inscriptions' && $canViewTable) ? 'inscriptions' : 'formulaire';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $activeTab === 'inscriptions' ? 'Mes inscriptions' : 'Saisie inscription' ?></title>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php if ($activeTab === 'inscriptions'): ?>
  <link href="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.css" rel="stylesheet">
<?php endif; ?>
</head>
<body>

<?php include 'navbar-admin.php'; ?>

<style nonce="<?= $GLOBALS['csp_nonce'] ?>">
/* ── Saisie : sidebar globale conservée comme sur les autres pages admin ── */

/* ── Bloc formulaire centré, identique à l'origine ── */
.saisie-form-block {
  width: 100%;
  max-width: 820px;
  margin: 0 auto;
  padding: 8px 0 24px;
}

/* ── Bloc tableau "Mes inscriptions", plus large ── */
.saisie-inscriptions-block {
  width: 100%;
  max-width: 100%;
  margin: 0 auto 40px;
}

/* ── Tableau "Mes inscriptions" ── */
.saisie-table-card {
  margin-top: 24px;
}
.saisie-table-card h2 {
  font-size: 16px;
  font-weight: 700;
  color: #1e293b;
  margin: 0 0 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}
#tblSaisie thead tr:first-child th {
  background: #faf7f8;
  color: #5f4b52;
  font-weight: 600;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  border-bottom: 2px solid #f0e8eb;
  border-top: none;
  padding: 10px 12px;
  white-space: nowrap;
}
#tblSaisie tbody td {
  padding: 10px 12px;
  vertical-align: middle;
  font-size: 13px;
  color: #1e293b;
  border-bottom: 1px solid #f0e8eb;
}
#tblSaisie tbody tr:hover td { background: #fdf8f9; }
.saisie-table-card .action-buttons .btn {
  --bs-btn-padding-y: .20rem;
  --bs-btn-padding-x: .45rem;
  --bs-btn-font-size: .75rem;
}
.saisie-table-card .quick-search-saisie {
  max-width: 320px;
  margin-bottom: 12px;
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

<?php if ($activeTab === 'formulaire'): ?>
<div class="saisie-form-block">
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
<?php endif; ?>

<?php if ($canViewTable && $activeTab === 'inscriptions'): ?>
<div class="saisie-inscriptions-block">
  <div class="saisie-page-header">
    <h1>Mes inscriptions</h1>
    <p>Inscriptions enregistrées via votre compte.</p>
  </div>
  <div class="saisie-table-card">
    <input id="quickSearchSaisie" class="form-control quick-search-saisie" placeholder="Recherche rapide">
    <div class="table-responsive">
      <table id="tblSaisie" class="table table-striped table-sm w-100"></table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($activeTab === 'inscriptions' && $canEditReg): ?>
<div class="modal fade" id="editModalSaisie" tabindex="-1"><div class="modal-dialog">
  <div class="modal-content"><div class="modal-header">
    <h5 class="modal-title">Modifier l'inscription</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="fEditSaisie">
      <div class="modal-body row g-2">
        <input type="hidden" name="id">
        <input type="hidden" name="origine" value="<?= htmlspecialchars(currentOrganisation()) ?>">
        <?php foreach ($adminFields as $f): ?>
          <?= renderFormField($f) ?>
        <?php endforeach; ?>
        <div class="col-md-6"><label class="form-label">Paiement</label><select name="paiement_mode" class="form-select"><option>CB</option><option>espece</option><option>cheque</option></select></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button class="btn-saisie" style="width:auto;padding:0 18px">Sauvegarder</button></div>
    </form>
  </div></div></div>
<?php endif; ?>

<?php require 'admin-footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<?php if ($activeTab === 'inscriptions'): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.js" integrity="sha384-3wB6mhez87GBdPpEqKMU2wAH2Cjcvj8ynU/n7blM/JW4BLpVD0aTrx4ZE7IwFLSH" crossorigin="anonymous"></script>
<?php endif; ?>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
  var _csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var tblSaisie = null;

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
        if (tblSaisie) tblSaisie.ajax.reload(null, false);
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

<?php if ($activeTab === 'inscriptions'): ?>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  var canEditReg   = <?= $canEditReg ? 'true' : 'false' ?>;
  var canDeleteReg = <?= $canDeleteReg ? 'true' : 'false' ?>;

  function normalizeBirth(fd){
    var v = (fd.get('naissance') || '').trim();
    if (!v) return;
    if (/^\d{4}$/.test(v)) { fd.set('naissance', v); return; }
    v = v.replace(/-/g, '/').replace(/\s+/g, '');
    var p = v.split('/');
    if (p.length !== 3) { fd.delete('naissance'); return; }
    var d = p[0].padStart(2, '0'), m = p[1].padStart(2, '0'), y = p[2].padStart(2, '0');
    if (/^\d{4}$/.test(d)) { var t = d; d = y; y = t; }
    if (d < 1 || d > 31 || m < 1 || m > 12 || y.length !== 4) { fd.delete('naissance'); return; }
    fd.set('naissance', d + '/' + m + '/' + y);
  }

  var columns = [
    { data: 'id', visible: false },
    { data: 'inscription_no', title: 'N°' },
    <?php foreach ($allActiveFields as $af):
      $col = htmlspecialchars($af['bdd_column'], ENT_QUOTES);
      $lbl = htmlspecialchars($af['label'], ENT_QUOTES);
    ?>
    { data: '<?= $col ?>', title: '<?= $lbl ?>', defaultContent: '' },
    <?php endforeach; ?>
    { data: 'paiement_mode', title: 'Paiement', defaultContent: '' },
    { data: 'created_at', title: 'Date ajout', render: function(val, type){
        if (type === 'display' || type === 'filter') { if (!val) return ''; return new Date(val).toLocaleDateString('fr-FR'); }
        return val;
      }, width: '110px', className: 'text-nowrap text-center'
    }
  ];

  if (canEditReg || canDeleteReg) {
    columns.push({
      data: null,
      title: 'Actions',
      orderable: false,
      className: 'text-center',
      width: '110px',
      render: function() {
        var html = '';
        if (canEditReg)   html += '<button class="btn btn-sm btn-outline-primary edit-saisie me-1" title="Modifier"><i class="bi bi-pencil"></i></button>';
        if (canDeleteReg) html += '<button class="btn btn-sm btn-outline-danger delete-saisie" title="Supprimer"><i class="bi bi-trash3"></i></button>';
        return '<div class="action-buttons">' + html + '</div>';
      }
    });
  }

  // Pagination compacte type "1 ... 4 5 6 ... 12" (au lieu de toutes les pages numérotées)
  $.fn.dataTable.ext.pager.numbers_length = 7;

  tblSaisie = $('#tblSaisie').DataTable({
    ajax: { url: '../config/api.php?route=registrations', dataSrc: '' },
    columns: columns,
    dom: 'lrtip',
    autoWidth: false,
    order: [[0, 'desc']],
    language: {
      emptyTable:    'Aucune inscription enregistrée pour le moment.',
      zeroRecords:   'Aucun résultat.',
      lengthMenu:    'Afficher _MENU_ inscriptions',
      info:          '_START_ à _END_ sur _TOTAL_',
      infoEmpty:     '0 sur 0',
      infoFiltered:  '(filtré sur _MAX_)',
      paginate:      { first: '«', previous: '‹', next: '›', last: '»' }
    }
  });

  $('#quickSearchSaisie').on('keyup', function(){ tblSaisie.search(this.value).draw(); });

  // Suppression
  $('#tblSaisie').on('click', '.delete-saisie', function() {
    var row  = tblSaisie.row($(this).closest('tr'));
    var data = row.data();
    if (!confirm('Êtes-vous sûr de vouloir supprimer l\'inscription de ' + (data.prenom || '') + ' ' + (data.nom || '') + ' ?')) return;

    fetch('../config/api.php?route=registrations', {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': _csrfToken },
      body: new URLSearchParams({ id: data.id })
    })
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (j.ok) { row.remove().draw(false); }
      else { alert('Erreur : ' + (j.err || 'Suppression impossible.')); }
    })
    .catch(function(){ alert('Erreur de communication avec le serveur.'); });
  });

  // Édition — préremplissage du modal
  $('#tblSaisie').on('click', '.edit-saisie', function() {
    var d = tblSaisie.row($(this).closest('tr')).data();
    Object.entries(d).forEach(function(kv){
      $('#fEditSaisie [name="' + kv[0] + '"]').val(kv[1]);
    });
    new bootstrap.Modal('#editModalSaisie').show();
  });

  $('#fEditSaisie').on('submit', function(e){
    e.preventDefault();
    var fd = new FormData(e.target);
    normalizeBirth(fd);
    fetch('../config/api.php?route=registrations', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': _csrfToken },
      body: new URLSearchParams(fd)
    })
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (j.ok) {
        tblSaisie.ajax.reload(null, false);
        bootstrap.Modal.getInstance(document.getElementById('editModalSaisie')).hide();
      } else {
        alert('Erreur : ' + (j.err || 'Modification impossible.'));
      }
    })
    .catch(function(){ alert('Erreur de communication avec le serveur.'); });
  });
})();
</script>
<?php endif; ?>

</body>
</html>
