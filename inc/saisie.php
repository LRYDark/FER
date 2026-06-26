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
$registration_fee = (float) ($data['registration_fee'] ?? 0);
$childAge = (int) ($data['child_age_threshold'] ?? 12); // libellés « -N ans »

// Limite « X premiers inscrits » (éligibilité T-shirt) — même logique que le dashboard
$qrcode_mail_mode  = $data['qrcode_mail_mode']  ?? 'none';
$qrcode_mail_limit = (int) ($data['qrcode_mail_limit'] ?? 0);
$highlightLimit    = ($qrcode_mail_mode === 'first_x' && $qrcode_mail_limit > 0) ? $qrcode_mail_limit : 0;

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
  <link href="https://cdn.datatables.net/colreorder/1.7.0/css/colReorder.bootstrap5.min.css" rel="stylesheet">
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
/* Look du tableau + boutons d'action : fournis par .fer-table et les composants
   globaux (navbar-admin.php). Ici, uniquement le spécifique à la page saisie. */

/* Barre de recherche rapide : centrée et sticky. */
.saisie-table-card .quick-search-saisie {
  max-width: 450px; width: 50%; margin: 0 auto .75rem;
  position: sticky; top: 0; z-index: 1030;
}

/* ColReorder : curseur « déplaçable » (les styles resize / dtcr / bouton Colonnes
   sont fournis globalement par navbar-admin.php). */
#tblSaisie thead tr:first-child th { cursor: grab; }
#tblSaisie thead tr:first-child th:active { cursor: grabbing; }

/* « Afficher X inscriptions » (DataTables) : tout sur une ligne. */
#tblSaisie_length label { display: inline-flex; align-items: center; gap: 6px; margin: 0; white-space: nowrap; font-size: 13px; color: #475569; font-weight: 400; }
#tblSaisie_length select, #tblSaisie_length .form-select {
  display: inline-block !important; width: auto !important; min-width: 64px;
  padding: 5px 30px 5px 10px; font-size: 13px;
  border: 1px solid #d4c4cb; border-radius: 6px; background-color: #fff;
  background-position: right 9px center;
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
/* La règle globale #oc-content .alert force display:flex (alignement en ligne).
   On la neutralise ici pour que les lignes du message s'empilent verticalement. */
#oc-content #msg.alert { display: block; }

/* ── Cartes statistiques (onglet « Mes inscriptions ») ── */
#statsSaisie .statCard { min-width: 180px; }
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
        <select name="paiement_mode" class="form-select paiement-select" required>
          <option value="" selected disabled hidden>Choisir&hellip;</option>
          <option value="CB">CB</option>
          <option value="espece">Esp&egrave;ces</option>
          <option value="cheque">Ch&egrave;que</option>
          <option value="virement">Virement</option>
          <option value="gratuit">Gratuit / Enfant -<?= $childAge ?> ans (sans T-shirt)</option>
          <option value="enfant_tshirt">Enfant -<?= $childAge ?> ans (avec T-shirt)</option>
        </select>
        <div class="montant-du-display mt-2" style="display:none;font-size:14px;font-weight:600;color:#1e293b"></div>
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
    <div id="statsSaisie" class="d-flex flex-wrap gap-3 mb-3"></div>
    <input id="quickSearchSaisie" class="form-control quick-search-saisie" placeholder="Recherche rapide">
    <div class="fer-tbl-wrap is-loading" id="saisieTblWrap">
      <div class="fer-tbl-spinner" aria-hidden="true"></div>
      <div class="table-responsive">
        <table id="tblSaisie" class="table fer-table table-sm w-100"></table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($activeTab === 'inscriptions' && $canEditReg): ?>
<div class="modal fade" id="editModalSaisie" tabindex="-1"><div class="modal-dialog modal-lg">
  <div class="modal-content"><div class="modal-header">
    <h5 class="modal-title">Modifier l'inscription</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="fEditSaisie">
      <div class="modal-body row g-2">
        <input type="hidden" name="id">
        <input type="hidden" name="origine" value="<?= htmlspecialchars(currentOrganisation()) ?>">
        <?php foreach ($adminFields as $f): ?>
          <?php /* « Autorisation parentale (mineur) » : déjà renseignée à l'inscription → masquée ici. */ ?>
          <?php if (($f['field_type'] ?? '') === 'guardian') continue; ?>
          <?= renderFormField($f) ?>
        <?php endforeach; ?>
        <div class="col-md-6">
          <label class="form-label">Paiement</label>
          <select name="paiement_mode" class="form-select paiement-select">
            <option value="CB">CB</option>
            <option value="espece">Espèce</option>
            <option value="cheque">Chèque</option>
            <option value="virement">Virement</option>
            <option value="gratuit">Gratuit / Enfant -<?= $childAge ?> ans (sans T-shirt)</option>
            <option value="enfant_tshirt">Enfant -<?= $childAge ?> ans (avec T-shirt)</option>
          </select>
          <div class="montant-du-display mt-2" style="display:none;font-size:14px;font-weight:600;color:#1e293b"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button class="btn-saisie" style="width:auto;padding:0 18px">Sauvegarder</button></div>
    </form>
  </div></div></div>
<?php endif; ?>

<?php require 'admin-footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="../js/inscription-form.js?v=3" nonce="<?= $GLOBALS['csp_nonce'] ?>"></script>
<?php if ($activeTab === 'inscriptions'): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.js" integrity="sha384-3wB6mhez87GBdPpEqKMU2wAH2Cjcvj8ynU/n7blM/JW4BLpVD0aTrx4ZE7IwFLSH" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/colreorder/1.7.0/js/dataTables.colReorder.min.js"></script>
<script src="../js/admin-table-filters.js?v=1" nonce="<?= $GLOBALS['csp_nonce'] ?>"></script>
<?php endif; ?>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
  var _csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var tblSaisie = null;
  var registrationFee = <?= json_encode($registration_fee) ?>;
  var childAge = <?= json_encode($childAge) ?>; // âge seuil « enfant » pour les libellés

  /* Affichage dynamique du « Montant dû » sous le select paiement */
  function formatMontant(n){
    var v = parseFloat(n);
    if(!isFinite(v)) v = 0;
    return v.toFixed(2).replace(/\.00$/,'') + ' €';
  }
  function updateMontantDisplay(selectEl){
    var wrap = selectEl.closest('.col-md-6');
    if(!wrap) return;
    var disp = wrap.querySelector('.montant-du-display');
    if(!disp) return;
    var val = selectEl.value;
    if(!val){ disp.style.display='none'; disp.textContent=''; return; }
    // Dans le modal d'édition, l'inscription existe déjà → on parle de montant payé.
    var isEdit = !!selectEl.closest('#editModalSaisie');
    var labelDu  = isEdit ? 'Montant payé' : 'Montant dû';
    var labelDue = isEdit ? 'Montant payé' : 'Montant total dû';
    if(val === 'gratuit'){
      disp.style.display='block';
      disp.innerHTML = labelDu + ' : <span style="color:#16a34a">'+formatMontant(0)+'</span>';
    } else {
      disp.style.display='block';
      disp.innerHTML = labelDue + ' : <span style="color:#F42182">'+formatMontant(registrationFee)+'</span>';
    }
  }
  document.addEventListener('change', function(e){
    if(e.target && e.target.matches('.paiement-select')) updateMontantDisplay(e.target);
  });
  var _editModalSaisie = document.getElementById('editModalSaisie');
  if (_editModalSaisie) {
    _editModalSaisie.addEventListener('shown.bs.modal', function(){
      var sel = _editModalSaisie.querySelector('.paiement-select');
      if(sel) updateMontantDisplay(sel);
    });
  }

  document.getElementById('fAdd') && document.getElementById('fAdd').addEventListener('submit', function(e) {
    e.preventDefault();
    if (window.FERInscription && !FERInscription.ensureGuardian(e.target)) return;
    var btn = document.getElementById('btnSave');
    btn.disabled = true; btn.textContent = 'Enregistrement…';
    var msg = document.getElementById('msg');
    msg.className = 'alert d-none';

    if (window.FERInscription) FERInscription.composeComment(e.target);
    var fd = new FormData(e.target);
    if (window.FERInscription) FERInscription.normalizeBirth(fd);

    fetch('../config/api.php?route=registrations', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
      body: JSON.stringify(Object.fromEntries(fd))
    })
    .then(function(r) { return r.json(); })
    .then(function(j) {
      btn.disabled = false; btn.textContent = 'Enregistrer';
      if (j.ok) {
        var hlLimit = <?= (int)$highlightLimit ?>;
        var seqNo = parseInt(String(j.inscription_no).replace(/[^0-9]/g, '')) || 0;
        // Le formulaire saisie n'a pas la liste complète des inscrits (vue limitée
        // à l'organisation connectée). On se base sur le paiement saisi :
        // « gratuit » → non éligible. L'admin verra le rang payant exact côté dashboard.
        var paiement = (new FormData(e.target)).get('paiement_mode') || '';
        var isPaid = paiement && paiement !== 'gratuit';
        var html = '<div>Inscription <strong>n° ' + j.inscription_no + '</strong> enregistrée !</div>'
                 + '<div style="display:block;font-size:20px;font-weight:800;margin-top:6px;line-height:1.2">'
                 +   seqNo + '<sup>e</sup> inscrit</div>';
        if (!isPaid) {
          html += '<div style="font-size:12px;margin-top:4px">Gratuit / Enfant -' + childAge + ' ans — non éligible T-shirt</div>';
        } else if (hlLimit > 0) {
          html += seqNo <= hlLimit
            ? '<div style="font-size:12px;margin-top:4px">✓ Dans les ' + hlLimit + ' premiers — éligible T-shirt</div>'
            : '<div style="font-size:12px;margin-top:4px">Au-delà des ' + hlLimit + ' premiers — non éligible T-shirt</div>';
        }
        msg.className = 'alert alert-success';
        msg.innerHTML = html;
        e.target.reset();
        var _sel = e.target.querySelector('.paiement-select');
        if(_sel){ var _wrap = _sel.closest('.col-md-6'); var _disp = _wrap && _wrap.querySelector('.montant-du-display'); if(_disp) _disp.style.display='none'; }
        if (tblSaisie) tblSaisie.ajax.reload(null, false);
        setTimeout(function() { msg.className = 'alert d-none'; }, 9000);
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

  var columns = [
    { data: 'id', visible: false },
    { data: 'inscription_no', title: 'N°' },
    <?php foreach ($allActiveFields as $af):
      if (empty($af['bdd_column'])) continue; // pas une colonne BDD (ex. autorisation parentale)
      // created_at (« Date ajout ») et date_inscription (« Date d'inscription ») sont rendus
      // plus bas en colonnes dédiées, formatées sans heure : on saute leur colonne dynamique
      // générique qui afficherait sinon le timestamp brut (avec l'heure) en doublon.
      if ($af['bdd_column'] === 'created_at' || $af['bdd_column'] === 'date_inscription') continue;
      $col = htmlspecialchars($af['bdd_column'], ENT_QUOTES);
      $lbl = htmlspecialchars($af['label'], ENT_QUOTES);
    ?>
    { data: '<?= $col ?>', title: '<?= $lbl ?>', defaultContent: '' },
    <?php endforeach; ?>
    { data: 'paiement_mode', title: 'Paiement', defaultContent: '',
      render: function(val, type){
        // Display : libellé convivial. Filter/sort/recherche : valeur brute,
        // pour que la recherche rapide « gratuit » trouve bien les lignes.
        if (type === 'display') {
          if (!val) return '';
          var lc = String(val).toLowerCase();
          if (lc === 'gratuit') return 'Gratuit/-' + childAge + 'ans';
          if (lc === 'enfant_tshirt') return 'en ligne (CB)'; // legacy : catégorie déplacée dans Prestation
          return val;
        }
        return val;
      }
    },
    { data: 'montant_du', title: 'Montant', className: 'text-start text-nowrap', defaultContent: '0',
      render: function(val, type){
        // Affichage « 12 € » ; tri/filtre/recherche sur la valeur brute (pour que
        // le filtre par colonne « ^12$ » corresponde, comme sur le dashboard).
        if (type !== 'display') return val;
        var n = parseFloat(val);
        if (!isFinite(n)) n = 0;
        return n.toFixed(2).replace(/\.00$/,'') + ' €';
      }
    },
    { data: 'created_at', title: 'Date ajout', render: function(val, type){
        if (type === 'display' || type === 'filter') { if (!val) return ''; return new Date(val).toLocaleDateString('fr-FR'); }
        return val;
      }, width: '110px', className: 'text-nowrap text-center'
    },
    { data: 'date_inscription', title: 'Date d\'inscription', defaultContent: '', render: function(val, type){
        if (type === 'display' || type === 'filter') { if (!val) return ''; return new Date(val).toLocaleDateString('fr-FR'); }
        return val;
      }, width: '120px', className: 'text-nowrap text-center'
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
    orderCellsTop: true,
    // Réordonnancement des colonnes par glisser-déposer des en-têtes (ordre sauvegardé
    // par utilisateur via la route ui-prefs, clés distinctes du dashboard).
    colReorder: true,
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

  /* Filtres d'en-tête (entonnoir) — module partagé (mêmes colonnes que le dashboard). */
  $('#tblSaisie').on('init.dt', function(){
    if (window.FERTableFilters) {
      FERTableFilters.attach(tblSaisie, {
        filterableTitles: ['T-shirt','Taille T-shirt','Sexe','Paiement','Prestation','Entreprise','Origine','Ville','Montant'],
        filterableData:   ['tshirt_size','sexe','paiement_mode','prestation','entreprise','origine','ville','montant_du'],
        childAge: childAge
      });
    }
  });

  /* ══ Préférences d'interface (serveur, par utilisateur) — chargeur mutualisé ══
   * Ordre / visibilité / largeurs des colonnes stockés dans users.ui_prefs via la
   * route `ui-prefs`. Clés PROPRES à la page saisie (saisie_col_*) pour ne pas
   * entrer en conflit avec celles du dashboard (colonnes différentes). */
  var _uiPrefs = null, _uiPrefsPromise = null;
  function loadUiPrefs(){
    if(_uiPrefsPromise) return _uiPrefsPromise;
    _uiPrefsPromise = fetch('../config/api.php?route=ui-prefs')
      .then(function(r){ return r.json(); })
      .then(function(p){ _uiPrefs = (p && typeof p==='object') ? p : {}; return _uiPrefs; })
      .catch(function(){ _uiPrefs = {}; return _uiPrefs; });
    return _uiPrefsPromise;
  }
  function saveUiPref(patch){
    if(_uiPrefs && typeof _uiPrefs==='object') Object.assign(_uiPrefs, patch);
    return fetch('../config/api.php?route=ui-prefs',{
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-TOKEN':_csrfToken},
      body: JSON.stringify(patch)
    }).catch(function(){});
  }

  /* ══ Ordre des colonnes mémorisé (ColReorder + route ui-prefs) ══ */
  var _applyingColOrder = false; // évite de re-sauvegarder pendant l'application
  loadUiPrefs().then(function(p){
    var ord = p && p.saisie_col_order;
    if(Array.isArray(ord) && ord.length === tblSaisie.columns().count()){
      _applyingColOrder = true;
      try { tblSaisie.colReorder.order(ord, true); } catch(_){}
      _applyingColOrder = false;
    }
  });
  tblSaisie.on('column-reorder', function(){
    if(_applyingColOrder) return;
    saveUiPref({ saisie_col_order: tblSaisie.colReorder.order() });
  });

  // ── Cartes statistiques : Inscriptions + T-shirts récupérés ──
  // Compteurs GLOBAUX (toutes organisations) : le tableau ci-dessous n'affiche
  // que les inscriptions de l'organisation connectée, donc on interroge l'API.
  function updateSaisieStats(){
    fetch('../config/api.php?route=registrations-stats', { credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(s){
        if (!s || !s.ok) return;
        $('#statsSaisie').html(
          '<div class="card statCard flex-fill text-center"><div class="card-body">'
          + '<h5 class="card-title mb-1">Inscriptions</h5>'
          + '<p class="display-6 fw-bold mb-0">' + s.total + '</p>'
          + '<div class="text-muted" style="font-size:11px">Total — toutes organisations</div></div></div>'
          + '<div class="card statCard flex-fill text-center"><div class="card-body">'
          + '<h5 class="card-title mb-1">T-shirts récupérés</h5>'
          + '<p class="display-6 fw-bold mb-0">' + s.tshirt_recovered + '</p>'
          + '<div class="text-muted" style="font-size:11px">Total — toutes organisations</div></div></div>'
        );
      })
      .catch(function(){});
  }
  updateSaisieStats();

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
      if (j.ok) { row.remove().draw(false); updateSaisieStats(); }
      else { alert('Erreur : ' + (j.err || 'Suppression impossible.')); }
    })
    .catch(function(){ alert('Erreur de communication avec le serveur.'); });
  });

  // Édition — préremplissage et ouverture du modal (réutilisé par le bouton et le double-clic).
  function openEditSaisieModal(d){
    if(!d) return;
    Object.entries(d).forEach(function(kv){
      $('#fEditSaisie [name="' + kv[0] + '"]').val(kv[1]);
    });
    // Catégorie « enfant t-shirt » : paiement_mode stocké = 'en ligne (CB)', on
    // resélectionne le bon choix du menu d'après la prestation.
    if(String(d.prestation||'').toLowerCase()==='enfant_tshirt'){
      $('#fEditSaisie [name="paiement_mode"]').val('enfant_tshirt');
    }
    if (window.FERInscription) FERInscription.refresh(document.getElementById('fEditSaisie'));
    new bootstrap.Modal('#editModalSaisie').show();
  }

  if (canEditReg) {
    $('#tblSaisie').on('click', '.edit-saisie', function() {
      openEditSaisieModal(tblSaisie.row($(this).closest('tr')).data());
    });
    // Double-clic sur une ligne = ouvrir le modal de modification. On ignore le
    // double-clic déclenché sur un contrôle interactif (boutons, menus, liens).
    $('#tblSaisie tbody').on('dblclick', 'tr', function(e){
      if($(e.target).closest('button, a, select, input, .action-buttons').length) return;
      var row = tblSaisie.row(this);
      if(row && row.data()) openEditSaisieModal(row.data());
    });
  }

  $('#fEditSaisie').on('submit', function(e){
    e.preventDefault();
    if (window.FERInscription && !FERInscription.ensureGuardian(e.target)) return;
    if (window.FERInscription) FERInscription.composeComment(e.target);
    var fd = new FormData(e.target);
    if (window.FERInscription) FERInscription.normalizeBirth(fd);
    fetch('../config/api.php?route=registrations', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': _csrfToken },
      body: new URLSearchParams(fd)
    })
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (j.ok) {
        tblSaisie.ajax.reload(null, false);
        updateSaisieStats();
        bootstrap.Modal.getInstance(document.getElementById('editModalSaisie')).hide();
      } else {
        alert('Erreur : ' + (j.err || 'Modification impossible.'));
      }
    })
    .catch(function(){ alert('Erreur de communication avec le serveur.'); });
  });

  /* ══ Colonnes redimensionnables + bouton visibilité « Colonnes » + barre ══
   * Identique au dashboard, adapté au tableau saisie (#tblSaisie). Pas de colonne
   * de cases à cocher ici → CB = 0 (on saute uniquement la colonne id masquée). */
  (function(){
    var table = document.getElementById('tblSaisie');
    if (!table) return;
    var uid = <?= json_encode($_SESSION['uid'] ?? 0) ?>;
    var storageKeyVis = 'fer_saisie_col_vis_' + uid;   // cache de repli (localStorage)
    var storageKeyW   = 'fer_saisie_col_w_' + uid;

    var CB = 0;                       // pas de colonne de cases à cocher sur saisie
    var FIRST_TOGGLE_COL = CB + 1;    // saute la colonne id masquée

    // ── Identification STABLE des colonnes (robuste au déplacement ColReorder) ──
    // On n'utilise plus la POSITION (qui change quand on déplace les en-têtes) mais
    // une CLÉ stable : la source de données (`data`) ou, à défaut (« Actions »),
    // le libellé d'en-tête. Visibilité/largeurs sont mémorisées/appliquées par clé.
    function colKey(idx){
      var src = tblSaisie.column(idx).dataSrc();
      if (src) return 'd:' + src;
      var th = tblSaisie.column(idx).header();
      return 'h:' + ($(th).text().trim() || ('col' + idx));
    }
    // Colonnes structurelles jamais togglables : la colonne id technique masquée.
    function isStructuralCol(idx){ return tblSaisie.column(idx).dataSrc() === 'id'; }
    // Index COURANT d'une colonne d'après sa clé stable (-1 si introuvable).
    function colIdxByKey(key){
      var found = -1;
      tblSaisie.columns().every(function(idx){
        if (found === -1 && colKey(idx) === key) found = idx;
      });
      return found;
    }
    // Colonnes togglables, dans l'ordre du tableau, avec leur clé stable + libellé.
    var toggleCols = [];
    tblSaisie.columns().every(function(idx){
      if (isStructuralCol(idx)) return;
      toggleCols.push({ key: colKey(idx), label: $(this.header()).text().trim() || 'Col ' + idx });
    });

    function readLocalJSON(key){
      try { var v = JSON.parse(localStorage.getItem(key)); return (v && typeof v==='object') ? v : null; }
      catch(e){ return null; }
    }
    // Migration unique localStorage → serveur (si le serveur n'a pas encore la pref).
    function migrateColPrefs(){
      if(!_uiPrefs || typeof _uiPrefs!=='object') return;
      var patch = {};
      if(_uiPrefs.saisie_col_vis == null){ var lv = readLocalJSON(storageKeyVis); if(lv){ _uiPrefs.saisie_col_vis = lv; patch.saisie_col_vis = lv; } }
      if(_uiPrefs.saisie_col_widths == null){ var lw = readLocalJSON(storageKeyW); if(lw){ _uiPrefs.saisie_col_widths = lw; patch.saisie_col_widths = lw; } }
      if(Object.keys(patch).length) saveUiPref(patch);
    }
    function restoreVisibility(){
      try {
        var saved = (_uiPrefs && _uiPrefs.saisie_col_vis) || readLocalJSON(storageKeyVis);
        if(!saved) return;
        var keys = Object.keys(saved);
        // Rétrocompat : ancien format indexé par numéro → conversion vers clé stable.
        var legacy = keys.length > 0 && keys.every(function(k){ return /^\d+$/.test(k); });
        keys.forEach(function(k){
          var key = legacy ? (toggleCols[parseInt(k,10)] && toggleCols[parseInt(k,10)].key) : k;
          if(!key) return;
          var ci = colIdxByKey(key);
          if(ci !== -1) tblSaisie.column(ci).visible(saved[k]);
        });
        if(legacy) saveVisibility(); // ré-enregistre au format par clé (migration unique)
      } catch(e){}
    }
    function saveVisibility(){
      try {
        var vis = {};
        toggleCols.forEach(function(c){
          var ci = colIdxByKey(c.key);
          if(ci !== -1) vis[c.key] = tblSaisie.column(ci).visible();
        });
        localStorage.setItem(storageKeyVis, JSON.stringify(vis));
        saveUiPref({ saisie_col_vis: vis });
      } catch(e){}
    }
    function restoreWidths(){
      try {
        var saved = (_uiPrefs && _uiPrefs.saisie_col_widths) || readLocalJSON(storageKeyW);
        if(!saved) return;
        var keys = Object.keys(saved);
        var legacy = keys.length > 0 && keys.every(function(k){ return /^\d+$/.test(k); });
        if(legacy){
          var j = 0;
          tblSaisie.columns().every(function(idx){
            if(isStructuralCol(idx) || !tblSaisie.column(idx).visible()) return;
            var w = saved[j++]; var th = tblSaisie.column(idx).header();
            if(w && th){ th.style.width = w; th.style.minWidth = w; }
          });
        } else {
          tblSaisie.columns().every(function(idx){
            var w = saved[colKey(idx)]; var th = tblSaisie.column(idx).header();
            if(w && th){ th.style.width = w; th.style.minWidth = w; }
          });
        }
      } catch(e){}
    }
    function saveWidths(){
      try {
        var widths = {};
        tblSaisie.columns().every(function(idx){
          if(isStructuralCol(idx)) return;
          var th = tblSaisie.column(idx).header();
          if(th && th.style.width) widths[colKey(idx)] = th.style.width;
        });
        localStorage.setItem(storageKeyW, JSON.stringify(widths));
        saveUiPref({ saisie_col_widths: widths });
      } catch(e){}
    }
    // ── Poignées de redimensionnement ──
    // ── Largeur "fixe" : chaque colonne respecte SA largeur ──
    // Sans ça (table-layout:auto + .w-100), agrandir une colonne quand il n'y a pas
    // encore de scroll horizontal force le navigateur à redistribuer la largeur sur
    // les voisines (effet accordéon). On fige une largeur explicite par colonne, on
    // passe en table-layout:fixed et on laisse le tableau dépasser (scroll géré par
    // .table-responsive) au lieu de comprimer les autres colonnes.
    function syncTableWidth(){
      var totalReal = 0;
      table.querySelectorAll('thead tr:first-child th').forEach(function(th){
        if(th.classList.contains('fer-spacer-cell')) return;   // la cellule tampon ne compte pas
        totalReal += parseFloat(th.style.width) || th.offsetWidth;
      });
      // Largeur = somme EXACTE des vraies colonnes (table-layout:fixed) : aucune
      // redistribution, donc on peut réduire une colonne librement. On force au
      // minimum la largeur de la page → la cellule « tampon » (largeur auto) absorbe
      // le reste pour atteindre le bord droit. Si les colonnes dépassent la page, le
      // tampon tombe à 0 et la scroll-barre revient.
      var box = table.parentElement;                 // .table-responsive
      var pageW = box ? box.clientWidth : totalReal;
      table.style.minWidth = '';
      table.style.width = Math.max(pageW, totalReal) + 'px';
    }
    // Cellule « tampon » en fin de ligne : remplit l'espace à droite (largeur auto)
    // pour que l'en-tête sombre et les lignes atteignent le bord de la page. Ni
    // triable, ni redimensionnable ; ignorée par DataTables (hors de ses colonnes).
    function ensureSpacer(){
      var headRow = table.querySelector('thead tr:first-child');
      if(headRow){
        var th = headRow.querySelector('.fer-spacer-cell');
        if(!th){ th = document.createElement('th'); th.className = 'fer-spacer-cell'; th.setAttribute('aria-hidden','true'); }
        headRow.appendChild(th);                     // (re)place toujours en dernier
      }
      table.querySelectorAll('tbody tr').forEach(function(tr){
        if(tr.querySelector('td[colspan]')) return;  // ligne « aucune donnée » : on laisse
        var td = tr.querySelector('.fer-spacer-cell');
        if(!td){ td = document.createElement('td'); td.className = 'fer-spacer-cell'; }
        tr.appendChild(td);
      });
    }
    // Largeur minimale d'une colonne = largeur EXACTE de son en-tête (titre + flèche
    // de tri + entonnoir de filtre + paddings réels). On clone le <th> hors écran et
    // on le laisse se réduire à son contenu : pas d'estimation, donc aucune marge
    // inutile réservée. En dessous, on bloque (jamais de chevauchement sur le voisin).
    function minColWidth(th){
      var cs = getComputedStyle(th);
      var clone = th.cloneNode(true);
      clone.querySelectorAll('.col-resize').forEach(function(el){ el.remove(); });
      clone.removeAttribute('class');            // pas de .sorting/.dataTable → aucune contrainte de grille
      // Mesuré HORS du <table> (sinon table-layout:fixed empêche le clone de se
      // réduire à son contenu → mesure faussée, trop large). On reproduit police,
      // casse et paddings réels ; la place de la flèche de tri est déjà dans le padding.
      clone.style.cssText =
        'position:absolute;left:-9999px;top:0;visibility:hidden;display:inline-block;' +
        'width:auto;min-width:0;max-width:none;white-space:nowrap;box-sizing:content-box;' +
        'font-weight:' + cs.fontWeight + ';font-size:' + cs.fontSize + ';font-family:' + cs.fontFamily + ';' +
        'letter-spacing:' + cs.letterSpacing + ';text-transform:' + cs.textTransform + ';' +
        'padding-left:' + cs.paddingLeft + ';padding-right:' + cs.paddingRight + ';';
      var host = th.closest('#oc-content') || document.body;
      host.appendChild(clone);
      var w = clone.getBoundingClientRect().width;
      clone.remove();
      return Math.max(30, Math.ceil(w) + 2);
    }
    function freezeLayout(){
      table.querySelectorAll('thead tr:first-child th').forEach(function(th){
        if(th.classList.contains('fer-spacer-cell')) return;   // tampon : largeur auto, on n'y touche pas
        if(!th.style.width){ var w = th.offsetWidth + 'px'; th.style.width = w; th.style.minWidth = w; }
      });
      table.classList.remove('w-100');
      table.style.tableLayout = 'fixed';
      syncTableWidth();
    }
    function initResize(){
      ensureSpacer();
      var ths = table.querySelectorAll('thead tr:first-child th');
      ths.forEach(function(th,i){
        if(th.classList.contains('fer-spacer-cell')) return;   // pas de poignée sur le tampon
        if(i < CB) return;
        if(th.querySelector('.col-resize')) return;
        var handle = document.createElement('div');
        handle.className = 'col-resize';
        th.appendChild(handle);
        handle.addEventListener('mousedown', function(e){
          e.preventDefault(); e.stopPropagation();
          var startX = e.pageX, startW = th.offsetWidth, moved = false;
          // Redimensionnement simple et fluide : on ne touche QU'À cette colonne.
          // Le tableau s'élargit/rétrécit en conséquence (scroll-barre si besoin) et
          // ne descend jamais sous la largeur de la page (cf. syncTableWidth). La
          // colonne ne passe pas sous la largeur de son en-tête (thMin).
          var thMin = minColWidth(th);
          handle.classList.add('active');
          function onMove(e2){
            moved = true;
            var newW = Math.max(thMin, startW + e2.pageX - startX);
            th.style.width = newW + 'px'; th.style.minWidth = newW + 'px';
            syncTableWidth();
          }
          function onUp(){
            handle.classList.remove('active'); document.removeEventListener('mousemove',onMove); document.removeEventListener('mouseup',onUp); saveWidths();
            // Évite le « click » de tri émis quand on relâche hors de la poignée.
            if(moved){ var swallow = function(ev){ ev.stopPropagation(); ev.preventDefault(); }; th.addEventListener('click', swallow, true); setTimeout(function(){ th.removeEventListener('click', swallow, true); }, 0); }
          }
          document.addEventListener('mousemove', onMove);
          document.addEventListener('mouseup', onUp);
        });
      });
      restoreWidths();
      freezeLayout();
    }
    // ── Bouton « Colonnes » (afficher/masquer) + barre « Afficher X … Colonnes » ──
    function buildColToggle(){
      var lengthEl = document.getElementById('tblSaisie_length');
      if(!lengthEl || document.getElementById('colToggleWrapSaisie')) return;
      var bar = document.createElement('div');
      bar.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;';
      bar.appendChild(lengthEl);
      var wrap = document.createElement('div');
      wrap.className = 'col-toggle-wrap';
      wrap.id = 'colToggleWrapSaisie';
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'col-toggle-btn';
      btn.innerHTML = '<i class="bi bi-layout-three-columns"></i> Colonnes';
      var dropdown = document.createElement('div');
      dropdown.className = 'col-toggle-dropdown';
      // En-tête « titre » identique aux popovers de filtre (cohérence visuelle).
      var head = document.createElement('div');
      head.className = 'cfp-head';
      head.textContent = 'Afficher / masquer';
      dropdown.appendChild(head);
      toggleCols.forEach(function(c){
        var label = document.createElement('label');
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        var ci = colIdxByKey(c.key);
        cb.checked = ci === -1 ? true : tblSaisie.column(ci).visible();
        cb.style.accentColor = '#F42182';
        cb.addEventListener('change', function(){
          // Index résolu à chaque clic via la clé stable → robuste au déplacement.
          var idxNow = colIdxByKey(c.key);
          if(idxNow !== -1) tblSaisie.column(idxNow).visible(this.checked);
          saveVisibility();
        });
        label.appendChild(cb);
        label.appendChild(document.createTextNode(' ' + c.label));
        dropdown.appendChild(label);
      });
      btn.addEventListener('click', function(e){ e.stopPropagation(); dropdown.classList.toggle('show'); });
      document.addEventListener('click', function(e){ if(!wrap.contains(e.target)) dropdown.classList.remove('show'); });
      wrap.appendChild(btn);
      wrap.appendChild(dropdown);
      bar.appendChild(wrap);
      // Barre placée AU-DESSUS du tableau, HORS du conteneur à défilement horizontal.
      var scrollBox = table.closest('.table-responsive') || table.parentElement;
      scrollBox.parentElement.insertBefore(bar, scrollBox);
    }
    // ── Pied de tableau (info + pagination) sorti du conteneur à défilement ──
    function moveTableFooterOut(){
      var scrollBox = table.closest('.table-responsive');
      if(!scrollBox) return;
      var info = document.getElementById('tblSaisie_info');
      var paginate = document.getElementById('tblSaisie_paginate');
      if(!info && !paginate) return;
      var footer = document.getElementById('tblSaisieFooterBar');
      if(!footer){
        footer = document.createElement('div');
        footer.id = 'tblSaisieFooterBar';
        footer.style.cssText = 'display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-top:8px;';
        scrollBox.parentElement.insertBefore(footer, scrollBox.nextSibling);
      }
      if(info && info.parentElement !== footer) footer.appendChild(info);
      if(paginate && paginate.parentElement !== footer) footer.appendChild(paginate);
    }
    // ── Voile de chargement : on dévoile le tableau une fois tout en place ──
    function revealTbl(){
      var w = document.getElementById('saisieTblWrap');
      if(w) requestAnimationFrame(function(){ w.classList.remove('is-loading'); });
    }
    setTimeout(revealTbl, 6000);   // filet de sécurité si init.dt n'émet jamais

    // ── Init : attend les prefs serveur, migre le localStorage, applique ──
    $('#tblSaisie').on('init.dt', function(){
      loadUiPrefs().then(function(){
        migrateColPrefs();
        restoreVisibility();
        buildColToggle();
        moveTableFooterOut();
        initResize();
        revealTbl();
      });
    });
    $('#tblSaisie').on('draw.dt', function(){ moveTableFooterOut(); initResize(); });
  })();
})();
</script>
<?php endif; ?>

</body>
</html>
