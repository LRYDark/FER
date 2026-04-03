<?php
require '../config/config.php';
require_once '../config/csrf.php';
requireRole(['admin','user','viewer']);
$role = currentRole();

// Charger les données pour la navbar
require 'navbar-data.php';

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

$qrcode_mail_mode = $data['qrcode_mail_mode'] ?? 'none';
$qrcode_mail_limit = (int) ($data['qrcode_mail_limit'] ?? 0);
$highlightLimit = ($qrcode_mail_mode === 'first_x' && $qrcode_mail_limit > 0) ? $qrcode_mail_limit : 0;

// Champs dynamiques
require_once '../config/form_fields.php';
$adminFields = getActiveFields($pdo, 'admin');
// Tous les champs actifs (pour les colonnes DataTable)
$stmtAllFields = $pdo->prepare('SELECT * FROM forms WHERE active = 1 ORDER BY sort_order ASC');
$stmtAllFields->execute();
$allActiveFields = $stmtAllFields->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tableau de bord</title>
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">

<!-- ─── CSS ─── -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .card-dashboard{margin-top:1rem;border-radius:1.25rem;box-shadow:0 0 25px rgba(0,0,0,.1)}
  .quick-search{max-width:450px;width:50%;margin:0 auto .75rem;position:sticky;top:0;z-index:1030}
  tr.filters th[class*="sorting"]::before,
  tr.filters th[class*="sorting"]::after{display:none!important}
  .statCard{min-width:180px}
  .hide-stats #stats {display: none !important;}
  .dashboard-actions .btn-rose{
    background:linear-gradient(135deg,#F42182,#db2777)!important;
    color:#fff!important;
    border:none!important;
  }
  .dashboard-actions .btn-rose:hover,
  .dashboard-actions .btn-rose:focus{
    background:linear-gradient(135deg,#db2777,#be185d)!important;
    color:#fff!important;
  }
  .dashboard-actions .btn-success{
    background:#22c55e!important;
    color:#fff!important;
    border-color:#22c55e!important;
  }
  .dashboard-actions .btn-success:hover,
  .dashboard-actions .btn-success:focus{
    background:#16a34a!important;
    color:#fff!important;
    border-color:#16a34a!important;
  }
  .dashboard-actions .btn-secondary{
    background:#64748b!important;
    color:#fff!important;
    border-color:#64748b!important;
  }
  .dashboard-actions .btn-secondary:hover,
  .dashboard-actions .btn-secondary:focus{
    background:#475569!important;
    color:#fff!important;
  }
  .dashboard-actions .btn-info{
    background:#0ea5e9!important;
    color:#fff!important;
    border-color:#0ea5e9!important;
  }
  .dashboard-actions .btn-info:hover,
  .dashboard-actions .btn-info:focus{
    background:#0284c7!important;
    color:#fff!important;
  }
  .dashboard-actions .btn-danger{
    background:#ef4444!important;
    color:#fff!important;
    border-color:#ef4444!important;
  }
  .dashboard-actions .btn-danger:hover,
  .dashboard-actions .btn-danger:focus{
    background:#dc2626!important;
    color:#fff!important;
  }
  .dashboard-actions .btn-warning{
    background:#f59e0b!important;
    color:#16171d!important;
    border-color:#f59e0b!important;
  }
  .dashboard-actions .btn-warning:hover,
  .dashboard-actions .btn-warning:focus{
    background:#d97706!important;
    color:#fff!important;
    border-color:#d97706!important;
  }
  
/* ═══ Tableau dashboard — style OpenCloud Rose ═══ */
#tbl { border-collapse: separate; border-spacing: 0; }

#tbl thead tr:first-child th {
  background: #faf7f8;
  color: #5f4b52;
  font-weight: 600;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  border-bottom: 2px solid #f0e8eb;
  border-top: none;
  padding: 10px 12px;
}

#tbl tbody td {
  padding: 10px 12px;
  vertical-align: middle;
  font-size: 13px;
  color: #1e293b;
  border-bottom: 1px solid #f0e8eb;
  border-left: none !important;
}

#tbl tbody tr:hover td { background: #fdf8f9; }

/* X premières lignes (QR Code) — fond rose pâle */
.first-750 td {
  background: #fdf2f6 !important;
  font-weight: 600;
}
.first-750:hover td {
  background: #fce4ec !important;
}

/* Filtres ligne */
tr.filters th { background: #fff !important; padding: 6px 8px !important; }
tr.filters select, tr.filters input {
  font-size: 12px; border: 1px solid #d4c4cb; border-radius: 4px; padding: 4px 6px;
}

/* Colonnes redimensionnables */
#tbl thead th { position: relative; }
#tbl thead th .col-resize {
  position: absolute; right: 0; top: 0; bottom: 0; width: 5px;
  cursor: col-resize; user-select: none; z-index: 1;
}
#tbl thead th .col-resize:hover,
#tbl thead th .col-resize.active { background: #F42182; }

/* Bouton colonnes */
.col-toggle-wrap { position: relative; display: inline-block; }
.col-toggle-btn {
  font-size: 13px; font-weight: 500; padding: 5px 12px;
  border: 1px solid #d4c4cb; border-radius: 6px; background: #fff;
  color: #1e293b; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
}
.col-toggle-btn:hover { background: #fdf8f9; }
.col-toggle-dropdown {
  display: none; position: absolute; top: 100%; right: 0; margin-top: 4px;
  background: #fff; border: 1px solid #f0e8eb; border-radius: 8px;
  box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 100;
  padding: 8px 0; min-width: 200px; max-height: 350px; overflow-y: auto;
}
.col-toggle-dropdown.show { display: block; }
.col-toggle-dropdown label {
  display: flex; align-items: center; gap: 8px; padding: 6px 14px;
  font-size: 13px; color: #1e293b; cursor: pointer; font-weight: 400;
  text-transform: none; letter-spacing: 0; margin: 0;
}
.col-toggle-dropdown label:hover { background: #fdf8f9; }

/* ═══ Petite retouche des filtres sous l'en-tête =========================== */
tr.filters th{
  background:#f2f4f8;
  border-bottom:2px solid #e0e4ec;
  padding:.4rem;
}
tr.filters select{
  font-size:.8rem;
  border-radius:8px;
}

/* ═══ Boutons action dans le tableau ====================================== */
.action-buttons .btn{
  --bs-btn-padding-y: .20rem;
  --bs-btn-padding-x: .45rem;
  --bs-btn-font-size: .75rem;
}
.btn-delete{
  background:#e63946;
  background:linear-gradient(135deg,#e63946 0%,#c5303d 100%);
}
.btn-delete:hover{
  background:linear-gradient(135deg,#c5303d 0%,#a32634 100%);
  box-shadow:0 3px 6px rgba(230,57,70,.35);
}

.xl-modal .modal-dialog {
  max-width: 1300px;
}

</style>
</head>

<body>

<?php include 'navbar-admin.php'; ?>

<!-- ═════════ MAIN ═════════ -->
  <div>

    <div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-3 gap-3">
      <h1 class="mb-0 fw-bold"><i class="bi bi-house me-2"></i>Inscriptions</h1>

      <div class="dashboard-actions d-none d-lg-flex flex-wrap gap-2">
        <?php if($role!=='viewer'): ?>
          <button class="btn btn-rose"      data-bs-toggle="modal" data-bs-target="#addModal">Nouvel inscrit</button>
        <?php endif; ?>
        <?php if($role==='admin'): ?>
          <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#importModal">Import Excel</button>
        <?php endif; ?>
        <?php if($role==='admin' || $role==='user'): ?>
          <button id="btnExport" class="btn btn-info">Export Excel</button>
            <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
            document.getElementById('btnExport').addEventListener('click', () => {
              // simple redirection => déclenche le téléchargement
              window.location = '../config/api.php?route=export-excel';
            });
            </script>
          <?php endif; ?>
          <?php if($role==='admin'): ?>
            <button id="btnArchiveNow" class="btn btn-danger">Archiver&nbsp;<?= date('Y') ?></button>

            <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
            document.getElementById('btnArchiveNow').addEventListener('click', async () => {
              if (!confirm('Tout archiver et réinitialiser les inscriptions ?')) return;

              const btn = document.getElementById('btnArchiveNow');
              const originalText = btn.innerHTML;
              btn.disabled = true;
              btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Archivage en cours…';

              try {
                const _ct = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                const res  = await fetch('../config/api.php?route=archive-current', {
                  method: 'POST',
                  credentials: 'same-origin',
                  headers: {'X-CSRF-TOKEN': _ct}
                });
                const json = await res.json();
                if (json.ok) {
                  alert(`${json.archived} inscription(s) archivées (${json.year}).`);
                  location.reload();
                } else {
                  alert('Erreur archivage : ' + JSON.stringify(json));
                  btn.disabled = false;
                  btn.innerHTML = originalText;
                }
              } catch (e) {
                alert('Erreur réseau : ' + e.message);
                btn.disabled = false;
                btn.innerHTML = originalText;
              }
            });
            </script>
        <?php endif; ?>
      </div>
    </div>

    <!-- stats -->
    <div id="stats" class="d-flex flex-wrap gap-3 mb-4"></div>

    <input id="quickSearch" class="form-control quick-search" placeholder="Recherche rapide">
    <div class="table-responsive">
      <table id="tbl" class="table table-striped table-sm w-100"></table>
    </div>
  </div>

<?php include 'admin-footer.php'; ?>

<!-- ═════════ MODALES ═════════ -->

<!-- Autres modales existantes... -->
<div class="modal fade xl-modal" id="addModal" tabindex="-1"><div class="modal-dialog">
  <div class="modal-content"><div class="modal-header">
    <h5 class="modal-title">Nouvel inscrit</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="fAdd">
      <div class="modal-body row g-2">
        <input type="hidden" name="origine" value="Admin">
        <?php foreach ($adminFields as $f): ?>
          <?= renderFormField($f) ?>
        <?php endforeach; ?>
        <div class="col-md-6"><label class="form-label">Paiement <span style="color:#ef4444">*</span></label><select name="paiement_mode" class="form-select" required><option value="" disabled selected hidden>Choisir…</option><option>CB</option><option>espece</option><option>cheque</option></select></div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button class="btn btn-rose">Enregistrer</button></div>
    </form>
  </div></div></div>

<div class="modal fade" id="editModal" tabindex="-1"><div class="modal-dialog">
  <div class="modal-content"><div class="modal-header">
    <h5 class="modal-title">Modifier l'inscription</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="fEdit">
      <div class="modal-body row g-2">
        <input type="hidden" name="id">
        <input type="hidden" name="origine" value="Admin">
        <?php foreach ($adminFields as $f): ?>
          <?= renderFormField($f) ?>
        <?php endforeach; ?>
        <div class="col-md-6"><label class="form-label">Paiement</label><select name="paiement_mode" class="form-select"><option>CB</option><option>espece</option><option>cheque</option></select></div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button class="btn btn-rose">Sauvegarder</button></div>
    </form>
  </div></div></div>

<div class="modal fade" id="importModal" tabindex="-1"><div class="modal-dialog modal-lg">
 <div class="modal-content"><div class="modal-header">
   <h5 class="modal-title">Import Excel</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="fImport" enctype="multipart/form-data"><div class="modal-body">
    <input type="file" name="file" id="importFileInput" accept=".xlsx,.xls" class="form-control" required>
    <div class="form-check form-switch mt-3">
      <input class="form-check-input" type="checkbox" name="send_mails" id="importSendMails" checked>
      <label class="form-check-label" for="importSendMails">Envoyer les mails d'inscription</label>
    </div>
    <div id="importPreview" class="mt-3" style="display:none">
      <div id="importPreviewLoading" class="text-center text-muted py-2" style="display:none">
        <span class="spinner-border spinner-border-sm me-1"></span>Analyse du fichier…
      </div>
      <div id="importPreviewResult" style="display:none"></div>
    </div>
    <div id="importProgress" class="mt-3" style="display:none">
      <div id="importProgressLog" style="max-height:300px;overflow-y:auto;font-size:13px;background:#f8f9fa;border-radius:8px;padding:12px;font-family:monospace;"></div>
      <div id="importRecap" class="mt-3" style="display:none"></div>
    </div>
  </div><div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
    <button type="button" id="btnImportClose" class="btn btn-primary" style="display:none">Fermer et actualiser</button>
    <button type="submit" id="btnImportSubmit" class="btn btn-rose" disabled>Importer</button>
  </div></form></div></div></div>


<!-- QR Scanner Modal -->
<style>
  #qrScanModal .modal-dialog { max-width: 600px; }
  #qrScanModal .qr-size-btn {
    min-width: 60px; min-height: 52px;
    font-size: 1.1rem; font-weight: 700;
    border-radius: 12px; border-width: 2px;
  }
  #qrScanModal .qr-size-btn.active.btn-primary { transform: scale(1.08); box-shadow: 0 4px 12px rgba(13,110,253,.35); }
  @media (max-width: 576px) {
    #qrScanModal .modal-dialog { margin: 0; max-width: 100%; height: 100%; }
    #qrScanModal .modal-content { border-radius: 0; min-height: 100dvh; }
    #qrScanModal .modal-body { padding: 1rem; }
    #qrScanModal .qr-size-btn { min-width: 52px; min-height: 56px; font-size: 1.15rem; flex: 1 1 0; }
    #qrScanModal #qrManualInput { font-size: 1.1rem; height: 48px; }
    #qrScanModal #qrManualBtn { font-size: 1.1rem; height: 48px; padding: 0 1.2rem; }
    #qrScanModal #qrPersonName { font-size: 1.2rem; }
  }
  @media (min-width: 577px) and (max-width: 992px) {
    #qrScanModal .modal-dialog { max-width: 90%; }
    #qrScanModal .qr-size-btn { min-width: 70px; min-height: 54px; flex: 1 1 0; }
  }
</style>
<div class="modal fade" id="qrScanModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-qr-code-scan me-2"></i>Remise T-shirt</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Scanner zone -->
        <div id="qrReader" style="width:100%"></div>

        <!-- Manual input -->
        <div class="text-center mt-3" id="qrManualZone">
          <p class="text-muted mb-1" style="font-size:13px">Ou saisir le N° d'inscription :</p>
          <div class="input-group" style="max-width:320px;margin:0 auto">
            <input type="text" id="qrManualInput" class="form-control" placeholder="N° inscription (ex: E21800406)">
            <button id="qrManualBtn" class="btn btn-primary">OK</button>
          </div>
        </div>

        <!-- Result card (hidden by default) -->
        <div id="qrPersonCard" class="mt-3" style="display:none">
          <hr>
          <!-- Eligibility badge -->
          <div id="qrEligibility" class="text-center mb-3"></div>

          <!-- Person info -->
          <div class="text-center mb-3">
            <div class="fw-bold fs-4" id="qrPersonName"></div>
            <span class="text-muted" id="qrPersonNo"></span>
            <span id="qrPersonVille" class="badge bg-secondary ms-2"></span>
          </div>

          <!-- T-shirt selector -->
          <div id="qrTshirtZone">
            <label class="form-label fw-semibold text-center d-block mb-2">Taille T-shirt :</label>
            <div class="d-flex flex-wrap gap-2 justify-content-center" id="qrTshirtBtns">
              <button class="btn btn-outline-dark qr-size-btn" data-size="XS">XS</button>
              <button class="btn btn-outline-dark qr-size-btn" data-size="S">S</button>
              <button class="btn btn-outline-dark qr-size-btn" data-size="M">M</button>
              <button class="btn btn-outline-dark qr-size-btn" data-size="L">L</button>
              <button class="btn btn-outline-dark qr-size-btn" data-size="XL">XL</button>
              <button class="btn btn-outline-dark qr-size-btn" data-size="XXL">XXL</button>
            </div>
            <div id="qrSaveStatus" class="mt-2 text-center" style="display:none"></div>
          </div>
          <hr>
          <div class="text-center">
            <button id="qrNextScan" class="btn btn-primary"><i class="bi bi-qr-code-scan me-2"></i>Scanner suivant</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═════════ JS ═════════ -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.js" integrity="sha384-3wB6mhez87GBdPpEqKMU2wAH2Cjcvj8ynU/n7blM/JW4BLpVD0aTrx4ZE7IwFLSH" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
const _csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const userRole = '<?= $role ?>';
let tableData = []; // Pour stocker les données triées par date

/* ══ Outils ════ */
function normalizeBirth(fd){
  let v=(fd.get('naissance')||'').trim();
  if(!v) return;
  if(/^\d{4}$/.test(v)){fd.set('naissance',v);return;}
  v=v.replace(/-/g,'/').replace(/\s+/g,'');
  const p=v.split('/');
  if(p.length!==3){fd.delete('naissance');return;}
  let [d,m,y]=p.map(s=>s.padStart(2,'0')); if(/^\d{4}$/.test(d)) [d,m,y]=[y,m,d];
  if(d<1||d>31||m<1||m>12||y.length!==4){fd.delete('naissance');return;}
  fd.set('naissance',`${d}/${m}/${y}`);
}
function ageFromBirth(b){
  if(!b) return null;
  let y,m=1,d=1;
  if(/^\d{4}$/.test(b)){y=+b;}
  else if(/^\d{4}-\d{2}-\d{2}$/.test(b)){[y,m,d]=b.split('-').map(Number);}
  else if(/^\d{2}\/\d{2}\/\d{4}$/.test(b)){[d,m,y]=b.split('/').map(Number);}
  else return null;
  const t=new Date(), bd=new Date(y,m-1,d);
  let a=t.getFullYear()-bd.getFullYear();
  if(t<new Date(t.getFullYear(),m-1,d)) a--;
  return a;
}

/* ══ DataTable ════ */
let tshirtMode=false;
function refreshButtons(){ $('#modeTS, #modeTS_m').text(tshirtMode?'Remise T-shirts':'Mode standard'); }
refreshButtons();

const tbl=$('#tbl').DataTable({
  ajax:{
    url:'../config/api.php?route=registrations',
    dataSrc: function(json) {
      // Trier les données par date d'ajout (du plus ancien au plus récent)
      tableData = json.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
      return tableData;
    }
  },
  columns:[
    {data:'id',visible:false},
    {data: null, title: 'ID', width: '60px', className: 'text-center', orderable: false, defaultContent: ''},
    {data:'inscription_no',title:'N°'},
    <?php foreach ($allActiveFields as $af):
      $col = $af['bdd_column'];
      $lbl = htmlspecialchars($af['label'], ENT_QUOTES);
      if ($col === 'tshirt_size'): ?>
    {data:'tshirt_size',title:'<?= $lbl ?>',render:(v,t,r)=>{
      if(t!=='display') return v??''; if(!tshirtMode) return v??'';
      if(userRole === 'viewer') return `<span class="text-muted" style="font-style:italic;opacity:.6">${v||'-'}</span>`;
      const sz=<?= json_encode(array_map('trim', explode(',', $af['options_list'] ?? '-,XS,S,M,L,XL,XXL'))) ?>;
      return `<select class="form-select form-select-sm tshirt-dd" data-id="${r.id}">${sz.map(s=>`<option${s===v?' selected':''}>${s}</option>`).join('')}</select>`;
    }},
      <?php else: ?>
    {data:'<?= $col ?>',title:'<?= $lbl ?>',defaultContent:''},
      <?php endif; ?>
    <?php endforeach; ?>
    {data:'paiement_mode',title:'Paiement',defaultContent:''},
    {data:'created_at', title:'Date ajout', render:function(val,type){
      if(type==='display'||type==='filter'){ if(!val) return ''; return new Date(val).toLocaleDateString('fr-FR'); }
      return val;
    }, width:'110px', className:'text-nowrap text-center'},
    {data:'origine',title:'Origine',defaultContent:''}
    <?php if($role !== 'viewer'): ?>,
    {
      data:null,
      title:'Actions',
      orderable:false,
      className:'text-center',
      width:'120px',
      render: function(data, type, row) {
        let buttons = '';
        <?php if($role==='admin'): ?>
        buttons += '<button class="btn btn-sm btn-outline-primary edit me-1" title="Modifier"><i class="bi bi-pencil"></i></button>';
        buttons += '<button class="btn btn-sm btn-outline-danger delete-row" title="Supprimer"><i class="bi bi-trash3"></i></button>';
        <?php endif; ?>
        return `<div class="action-buttons">${buttons}</div>`;
      }
    }
    <?php endif; ?>
  ],
  dom:'lrtip',
  autoWidth:false,
  orderCellsTop:true,
  order: [[11, 'asc']], // Trier par date d'ajout par défaut (colonne 11 = created_at)
  rowCallback: function (row, data, _displayNum, displayIndex) {
    // 1) numéro séquentiel : displayIndex (0-based) + 1
    $('td:eq(0)', row).text(displayIndex + 1);   // 2 = 3ᵉ colonne (0,1,2)
    // displayIndex = rang global après tri & recherche
    var hlLimit = <?= (int) $highlightLimit ?>;
    $(row).toggleClass('first-750', hlLimit > 0 && displayIndex < hlLimit);
  },
  initComplete:function(){
    buildFilters(this.api());
    updateStats(this.api().data().toArray());
  }
});

tbl.on('xhr.dt',(e,s,json)=>{
  if(json) {
    tableData = json.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
    updateStats(tableData);
  }
});


// Événements pour détecter l'ouverture/fermeture des menus t-shirt
$('#tbl').on('mousedown', '.tshirt-dd', function(e) {
  isDropdownOpen = true;
});

$('#tbl').on('focus', '.tshirt-dd', function() {
  isDropdownOpen = true;
});

$('#tbl').on('blur change', '.tshirt-dd', function() {
  setTimeout(() => {
    isDropdownOpen = false;
  }, 150);
});

$(document).on('click', function(e) {
  if (!$(e.target).hasClass('tshirt-dd')) {
    setTimeout(() => {
      isDropdownOpen = false;
    }, 100);
  }
});

// Variables pour gérer le refresh automatique
let refreshInterval;
let isDropdownOpen = false;

// Fonction pour démarrer le refresh automatique
function startAutoRefresh() {
  refreshInterval = setInterval(() => {
    if (!isDropdownOpen) {
      tbl.ajax.reload(null, false);
    }
  }, 5000);
}

startAutoRefresh();
$('#quickSearch').on('keyup',function(){tbl.search(this.value).draw();});

/* ══ Stats ════ */
function updateStats(data){
  const total=data.length, oldest={H:null,F:null}, byEnt={};
  data.forEach(r=>{
    const a=ageFromBirth(r.naissance);
    if(a!==null&&(r.sexe==='H'||r.sexe==='F')){
      if(!oldest[r.sexe] || a>oldest[r.sexe].age)
        oldest[r.sexe]={nom:`${r.prenom||''} ${r.nom||''}`.trim(),age:a};
    }
    if(r.entreprise) byEnt[r.entreprise]=(byEnt[r.entreprise]||0)+1;
  });
  const [eTop,eCnt]=Object.entries(byEnt).sort((a,b)=>b[1]-a[1])[0]||['–',0];
  $('#stats').html(`
    <div class="card statCard flex-fill text-center"><div class="card-body">
      <h5 class="card-title mb-1">Inscriptions</h5>
      <p class="display-6 fw-bold mb-0">${total}</p></div></div>
    <div class="card statCard flex-fill text-center"><div class="card-body">
      <h6 class="card-title text-muted mb-1">+ Vieux H</h6>
      <p class="fw-semibold mb-0">${oldest.H?oldest.H.nom+' ('+oldest.H.age+' ans)':'–'}</p></div></div>
    <div class="card statCard flex-fill text-center"><div class="card-body">
      <h6 class="card-title text-muted mb-1">+ Vieille F</h6>
      <p class="fw-semibold mb-0">${oldest.F?oldest.F.nom+' ('+oldest.F.age+' ans)':'–'}</p></div></div>
    <div class="card statCard flex-fill text-center"><div class="card-body">
      <h6 class="card-title text-muted mb-1">Entreprise n°1</h6>
      <p class="fw-semibold mb-0">${eTop} — ${eCnt}</p></div></div>
  `);
  if(tshirtMode) $('#stats').hide(); else $('#stats').show();
}

/* ══ Filtres par colonne ════ */
function buildFilters(api){
  const $thead=$('#tbl thead');
  $thead.find('tr.filters').remove();
  const $f=$thead.find('tr').first().clone(false).addClass('filters').appendTo($thead);
  $f.find('th').empty().removeClass('sorting sorting_asc sorting_desc sorting_disabled');
  api.columns().every(function(i){
    const title=$(this.header()).text().trim(), $cell=$f.find('th').eq(i);
    if(!this.visible()){ $cell.hide(); return; }
    if(['T-shirt','Sexe','Paiement','Entreprise','Origine'].includes(title)){
      const $sel=$('<select class="form-select form-select-sm"><option value="">Tous</option></select>')
        .appendTo($cell)
        .on('change',function(){ api.column(i).search(this.value ? '^'+this.value+'$' : '', true, false).draw();});
      this.data().unique().sort().each(v=>{if(v)$sel.append(`<option>${v}</option>`);});
    }
  });
  if(tshirtMode) $('.filters').hide();
}

/* ══ Bascule Remise T-shirts ════ */
function applyTshirtMode() {
  const hideHeaders = ['Sexe', 'Téléphone', 'Email', 'Naissance', 'Paiement', 'Entreprise', 'Date ajout', 'Origine', 'Actions'];
  tbl.columns().every(function () {
    const h = $(this.header()).text().trim();
    if (hideHeaders.includes(h)) this.visible(!tshirtMode, false);
  });
  $('.filters').toggle(!tshirtMode);
  if (tshirtMode) {
    $('body').addClass('hide-stats');
    $('#btnExport, #btnArchiveNow').hide();
    $('#colToggleWrap').hide();
  } else {
    $('body').removeClass('hide-stats');
    $('#btnExport, #btnArchiveNow').show();
    $('#colToggleWrap').show();
    updateStats(tbl.data().toArray());
  }
  tbl.rows().invalidate().draw(false);
}
$('#modeTS, #modeTS_m').on('click', function () {
  tshirtMode = !tshirtMode;
  refreshButtons();
  applyTshirtMode();
  if (this.id === 'modeTS_m') {bootstrap.Offcanvas.getInstance('#menuMobile').hide();}
});
applyTshirtMode();

/* ══ MAJ taille T-shirt ════ */
$('#tbl').on('change','.tshirt-dd',function(){
  if(userRole === 'viewer') {
    alert('Vous n\'avez pas les droits pour modifier les tailles de t-shirts.');
    return;
  }
  fetch('../config/api.php?route=registrations',{method:'PUT',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':_csrfToken},body:new URLSearchParams({id:this.dataset.id,tshirt_size:this.value})});
});

/* ══ SUPPRESSION ════ */
$('#tbl').on('click', '.delete-row', function() {
  const row = tbl.row($(this).closest('tr'));
  const data = row.data();

  if (!confirm(`Êtes-vous sûr de vouloir supprimer l'inscription de ${data.prenom} ${data.nom} ?`)) {
    return;
  }

  fetch('../config/api.php?route=registrations', {
    method: 'DELETE',
    headers: {'Content-Type': 'application/x-www-form-urlencoded','X-CSRF-TOKEN':_csrfToken},
    body: new URLSearchParams({id: data.id})
  })
  .then(response => response.json())
  .then(result => {
    if (result.success || result.ok) {
      // Supprimer la ligne du tableau
      row.remove().draw(false);
      // Mettre à jour les statistiques
      updateStats(tbl.data().toArray());
      alert('Inscription supprimée avec succès');
    } else {
      alert('Erreur lors de la suppression : ' + (result.message || 'Erreur inconnue'));
    }
  })
  .catch(error => {
    console.error('Erreur:', error);
    alert('Erreur de communication avec le serveur');
  });
});

/* ══ AJOUT ════ */
$('#fAdd').on('submit',e=>{
  e.preventDefault();
  const fd=new FormData(e.target); normalizeBirth(fd);
  fetch('../config/api.php?route=registrations',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':_csrfToken},body:JSON.stringify(Object.fromEntries(fd))})
  .then(r=>r.json()).then(j=>{
    if(j.inscription_no){
      tbl.ajax.reload(); e.target.reset();
      showToast('Inscription n°' + j.inscription_no + ' enregistrée !');
      $('#fAdd [name="nom"]').focus();
    }
  });
});

/* ══ TOAST ════ */
function showToast(msg) {
  let t = document.getElementById('ocToast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'ocToast';
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#0f172a;color:#fff;padding:14px 24px;border-radius:10px;font-size:14px;font-weight:600;z-index:99999;box-shadow:0 8px 24px rgba(0,0,0,.2);opacity:0;transition:opacity .3s;display:flex;align-items:center;gap:10px;';
    document.body.appendChild(t);
  }
  t.innerHTML = '<span style="color:#22c55e;font-size:18px;">&#10003;</span> ' + msg;
  t.style.opacity = '1';
  setTimeout(() => { t.style.opacity = '0'; }, 3500);
}

/* ══ ÉDITION ════ */
$('#tbl').on('click','button.edit',function(){
  const d=tbl.row($(this).closest('tr')).data();
  Object.entries(d).forEach(([k,v])=>$('#fEdit [name="'+k+'"]').val(v));
  new bootstrap.Modal('#editModal').show();
});
$('#fEdit').on('submit',e=>{
  e.preventDefault();
  const fd=new FormData(e.target); normalizeBirth(fd);
  fetch('../config/api.php?route=registrations',{method:'PUT',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':_csrfToken},body:new URLSearchParams(fd)})
  .then(()=>{tbl.ajax.reload(null,false); bootstrap.Modal.getInstance('#editModal').hide();});
});

/* ══ IMPORT EXCEL — Preview on file select ════ */
document.getElementById('importFileInput').addEventListener('change', async function() {
  const file = this.files[0];
  const preview = document.getElementById('importPreview');
  const loading = document.getElementById('importPreviewLoading');
  const result  = document.getElementById('importPreviewResult');
  const btnImport = document.getElementById('btnImportSubmit');

  btnImport.disabled = true;
  result.style.display = 'none';
  result.innerHTML = '';

  if (!file) { preview.style.display = 'none'; return; }

  preview.style.display = 'block';
  loading.style.display = 'block';

  try {
    const data = await file.arrayBuffer();
    const wb   = XLSX.read(data, {type:'array'});
    const ws   = wb.Sheets[wb.SheetNames[0]];
    const rows = XLSX.utils.sheet_to_json(ws, {header:1});

    if (rows.length < 2) {
      loading.style.display = 'none';
      result.style.display = 'block';
      result.innerHTML = '<div class="alert alert-warning mb-0 py-2"><i class="bi bi-exclamation-triangle me-1"></i>Le fichier semble vide.</div>';
      return;
    }

    const header = rows[0].map(function(h){ return (h||'').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9 ]/g,'').trim(); });

    // Filter out empty rows (rows where all cells are empty/null/undefined)
    var dataRows = [];
    for (var r = 1; r < rows.length; r++) {
      var row = rows[r];
      if (!row || !row.length) continue;
      var hasData = false;
      for (var c = 0; c < row.length; c++) {
        if (row[c] !== null && row[c] !== undefined && row[c].toString().trim() !== '') { hasData = true; break; }
      }
      if (hasData) dataRows.push(row);
    }
    const totalRows = dataRows.length;

    // Find ticket column (numero billet)
    var ticketCol = -1;
    for (var i = 0; i < header.length; i++) {
      if (header[i].indexOf('numero') !== -1 && header[i].indexOf('billet') !== -1) { ticketCol = i; break; }
    }

    var tickets = [];
    if (ticketCol >= 0) {
      for (var r = 0; r < dataRows.length; r++) {
        var v = dataRows[r][ticketCol];
        if (v && !isNaN(v)) tickets.push(parseInt(v));
      }
    }

    // Check duplicates server-side
    var dupCount = 0;
    var dupTickets = [];
    if (tickets.length > 0) {
      try {
        var res = await fetch('../config/api.php?route=check-duplicates', {
          method: 'POST',
          headers: {'Content-Type':'application/json', 'X-CSRF-TOKEN': _csrfToken},
          body: JSON.stringify({tickets: tickets}),
          credentials: 'same-origin'
        });
        var json = await res.json();
        dupTickets = json.duplicates || [];
        dupCount = dupTickets.length;
      } catch(e) { /* ignore, non-blocking */ }
    }

    loading.style.display = 'none';
    result.style.display = 'block';

    var html = '<div class="d-flex gap-3 flex-wrap">';
    html += '<div class="border rounded px-3 py-2 text-center flex-fill" style="min-width:120px">';
    html += '<div class="text-muted" style="font-size:12px">Inscrits</div>';
    html += '<div class="fw-bold fs-5 text-primary">' + totalRows + '</div></div>';

    if (dupCount > 0) {
      html += '<div class="border rounded px-3 py-2 text-center flex-fill border-warning" style="min-width:120px">';
      html += '<div class="text-muted" style="font-size:12px">Doublons</div>';
      html += '<div class="fw-bold fs-5 text-warning">' + dupCount + '</div></div>';
    } else {
      html += '<div class="border rounded px-3 py-2 text-center flex-fill border-success" style="min-width:120px">';
      html += '<div class="text-muted" style="font-size:12px">Doublons</div>';
      html += '<div class="fw-bold fs-5 text-success">0</div></div>';
    }

    var newRows = totalRows - dupCount;
    html += '<div class="border rounded px-3 py-2 text-center flex-fill" style="min-width:120px">';
    html += '<div class="text-muted" style="font-size:12px">Nouveaux</div>';
    html += '<div class="fw-bold fs-5 text-success">' + newRows + '</div></div>';
    html += '</div>';

    if (dupCount > 0) {
      html += '<div class="alert alert-warning mt-2 mb-0 py-2" style="font-size:13px">';
      html += '<i class="bi bi-info-circle me-1"></i>' + dupCount + ' doublon(s) seront ignorés lors de l\'import.';
      html += '</div>';
    }

    result.innerHTML = html;
    btnImport.disabled = false;

  } catch(err) {
    loading.style.display = 'none';
    result.style.display = 'block';
    result.innerHTML = '<div class="alert alert-danger mb-0 py-2">Impossible de lire le fichier : ' + err.message + '</div>';
  }
});

// Reset preview when modal closes
document.getElementById('importModal').addEventListener('hidden.bs.modal', function() {
  document.getElementById('fImport').reset();
  document.getElementById('importPreview').style.display = 'none';
  document.getElementById('importPreviewResult').innerHTML = '';
  document.getElementById('importProgress').style.display = 'none';
  document.getElementById('importProgressLog').innerHTML = '';
  document.getElementById('importRecap').style.display = 'none';
  document.getElementById('btnImportSubmit').disabled = true;
  document.getElementById('btnImportSubmit').style.display = 'inline-block';
  document.getElementById('btnImportClose').style.display = 'none';
});

document.getElementById('btnImportClose').addEventListener('click', function() {
  location.reload();
});

/* ══ IMPORT EXCEL — Submit ════ */
document.getElementById('fImport').addEventListener('submit', async (e) => {
  e.preventDefault();

  const form   = e.target;
  const button = document.getElementById('btnImportSubmit');
  const data   = new FormData(form);
  const progressDiv = document.getElementById('importProgress');
  const logDiv = document.getElementById('importProgressLog');
  const recapDiv = document.getElementById('importRecap');
  const closeBtn = document.getElementById('btnImportClose');

  button.disabled = true;
  button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Import en cours…';
  progressDiv.style.display = 'block';
  logDiv.innerHTML = '';
  recapDiv.style.display = 'none';

  function addLog(icon, text, color) {
    const line = document.createElement('div');
    line.style.cssText = 'padding:3px 0;color:' + (color || '#333');
    line.innerHTML = icon + ' ' + text;
    logDiv.appendChild(line);
    logDiv.scrollTop = logDiv.scrollHeight;
  }

  addLog('⏳', 'Import en cours…', '#666');

  try {
    const res = await fetch('../config/api.php?route=import-excel', {
      method:      'POST',
      headers:     {'X-CSRF-TOKEN': _csrfToken},
      body:        data,
      credentials: 'same-origin'
    });

    if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let finalResult = null;

    while (true) {
      const {done, value} = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, {stream: true});

      const lines = buffer.split('\n');
      buffer = lines.pop();

      for (const line of lines) {
        if (!line.trim()) continue;
        try {
          const evt = JSON.parse(line);
          if (evt.type === 'import_ok') {
            addLog('✅', '<strong>' + evt.count + '</strong> inscription(s) importée(s) en BDD', '#198754');
          } else if (evt.type === 'import_skip') {
            addLog('⚠️', evt.count + ' ligne(s) ignorée(s) — ' + evt.duplicates + ' doublon(s)', '#e67e22');
          } else if (evt.type === 'mail_sent') {
            addLog('📧', 'Mail envoyé pour <strong>' + evt.inscription_no + '</strong>' + (evt.qrcode ? ' (avec QR code)' : ''), '#0d6efd');
          } else if (evt.type === 'mail_error') {
            addLog('❌', 'Échec mail pour ' + evt.inscription_no + ' : ' + evt.error, '#dc3545');
          } else if (evt.type === 'mail_skip') {
            addLog('⏭️', 'Mails non envoyés (désactivé)', '#666');
          } else if (evt.type === 'done') {
            finalResult = evt;
          } else if (evt.type === 'error') {
            addLog('❌', 'Erreur : ' + evt.message, '#dc3545');
          }
        } catch(e) {}
      }
    }

    if (finalResult) {
      recapDiv.style.display = 'block';
      recapDiv.innerHTML = '<div class="d-flex gap-3 flex-wrap">'
        + '<div class="border rounded px-3 py-2 text-center flex-fill border-success"><div class="text-muted" style="font-size:12px">Importées</div><div class="fw-bold fs-5 text-success">' + finalResult.rows_added + '</div></div>'
        + '<div class="border rounded px-3 py-2 text-center flex-fill border-warning"><div class="text-muted" style="font-size:12px">Ignorées</div><div class="fw-bold fs-5 text-warning">' + finalResult.rows_skipped + '</div></div>'
        + '<div class="border rounded px-3 py-2 text-center flex-fill border-primary"><div class="text-muted" style="font-size:12px">Mails envoyés</div><div class="fw-bold fs-5 text-primary">' + finalResult.mails_sent + '</div></div>'
        + '</div>';
    }

    button.style.display = 'none';
    closeBtn.style.display = 'inline-block';

  } catch (err) {
    addLog('❌', 'Erreur réseau/serveur : ' + err.message, '#dc3545');
    button.style.display = 'inline-block';
    button.disabled = false;
  }
});


/* ══ Colonnes redimensionnables + toggle visibilité ════ */
(function() {
  var table = document.getElementById('tbl');
  if (!table) return;
  var uid = <?= json_encode($_SESSION['uid'] ?? 0) ?>;
  var storageKeyVis = 'fer_col_vis_' + uid;
  var storageKeyW = 'fer_col_w_' + uid;

  // Column names for toggle — built dynamically from actual DataTable headers (skip hidden col 0)
  var colNames = [];
  tbl.columns().every(function(idx) {
    if (idx === 0) return; // skip hidden id column
    colNames.push($(this.header()).text().trim() || 'Col ' + idx);
  });

  // ── Restore column visibility ──
  function restoreVisibility() {
    try {
      var saved = JSON.parse(localStorage.getItem(storageKeyVis));
      if (saved && typeof tbl !== 'undefined') {
        for (var i in saved) {
          var colIdx = parseInt(i) + 1;
          tbl.column(colIdx).visible(saved[i]);
        }
        // Sync filter row
        setTimeout(function() {
          var filterCells = table.querySelectorAll('thead tr.filters th');
          if (filterCells.length) {
            filterCells.forEach(function(cell, idx) {
              cell.style.display = tbl.column(idx).visible() ? '' : 'none';
            });
          }
        }, 100);
      }
    } catch(e) {}
  }

  function saveVisibility() {
    try {
      var vis = {};
      for (var i = 0; i < colNames.length; i++) {
        vis[i] = tbl.column(i + 1).visible();
      }
      localStorage.setItem(storageKeyVis, JSON.stringify(vis));
    } catch(e) {}
  }

  // ── Restore column widths ──
  function restoreWidths() {
    try {
      var saved = JSON.parse(localStorage.getItem(storageKeyW));
      if (!saved) return;
      var ths = table.querySelectorAll('thead tr:first-child th');
      ths.forEach(function(th, i) {
        if (saved[i]) { th.style.width = saved[i]; th.style.minWidth = saved[i]; }
      });
    } catch(e) {}
  }

  function saveWidths() {
    try {
      var widths = {};
      var ths = table.querySelectorAll('thead tr:first-child th');
      ths.forEach(function(th, i) {
        if (th.style.width) widths[i] = th.style.width;
      });
      localStorage.setItem(storageKeyW, JSON.stringify(widths));
    } catch(e) {}
  }

  // ── Column resize handles ──
  function initResize() {
    var ths = table.querySelectorAll('thead tr:first-child th');
    ths.forEach(function(th) {
      if (th.querySelector('.col-resize')) return;
      var handle = document.createElement('div');
      handle.className = 'col-resize';
      th.appendChild(handle);

      handle.addEventListener('mousedown', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var startX = e.pageX, startW = th.offsetWidth;
        handle.classList.add('active');

        function onMove(e2) {
          th.style.width = Math.max(40, startW + e2.pageX - startX) + 'px';
          th.style.minWidth = th.style.width;
        }
        function onUp() {
          handle.classList.remove('active');
          document.removeEventListener('mousemove', onMove);
          document.removeEventListener('mouseup', onUp);
          saveWidths();
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
      });
    });
    restoreWidths();
  }

  // ── Column toggle button ──
  function buildColToggle() {
    var lengthEl = document.querySelector('#tbl_length');
    if (!lengthEl || document.getElementById('colToggleWrap')) return;

    // Create a bar above the table: Show X entries (left) ... Colonnes (right)
    var bar = document.createElement('div');
    bar.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;';

    // Move lengthEl into the bar
    var lengthParent = lengthEl.parentElement;
    bar.appendChild(lengthEl);

    var wrap = document.createElement('div');
    wrap.className = 'col-toggle-wrap';
    wrap.id = 'colToggleWrap';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'col-toggle-btn';
    btn.innerHTML = '<i class="bi bi-layout-three-columns"></i> Colonnes';

    var dropdown = document.createElement('div');
    dropdown.className = 'col-toggle-dropdown';

    colNames.forEach(function(name, i) {
      var colIdx = i + 1;
      var label = document.createElement('label');
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.checked = tbl.column(colIdx).visible();
      cb.style.accentColor = '#F42182';
      cb.addEventListener('change', function() {
        tbl.column(colIdx).visible(this.checked);
        saveVisibility();
        // Sync filter row visibility
        var filterCells = table.querySelectorAll('thead tr.filters th');
        if (filterCells.length) {
          filterCells.forEach(function(cell, idx) {
            cell.style.display = tbl.column(idx).visible() ? '' : 'none';
          });
        }
      });
      label.appendChild(cb);
      label.appendChild(document.createTextNode(' ' + name));
      dropdown.appendChild(label);
    });

    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      dropdown.classList.toggle('show');
    });
    document.addEventListener('click', function(e) {
      if (!wrap.contains(e.target)) dropdown.classList.remove('show');
    });

    wrap.appendChild(btn);
    wrap.appendChild(dropdown);
    bar.appendChild(wrap);

    // Insert bar before the table
    var tableEl = document.getElementById('tbl');
    var dtScroll = tableEl.closest('.dataTables_scrollBody') || tableEl.closest('.dataTables_wrapper table') || tableEl;
    dtScroll.parentElement.insertBefore(bar, dtScroll);
  }

  // ── Init ──
  if (typeof $ !== 'undefined' && $.fn.dataTable) {
    $('#tbl').on('init.dt', function() {
      restoreVisibility();
      buildColToggle();
      initResize();
    });
    $('#tbl').on('draw.dt', initResize);
  }
})();

/* ══ QR CODE SCANNER — Remise T-shirt ════ */
(function(){
  var html5QrCode = null;
  var qrModal = document.getElementById('qrScanModal');
  var highlightLimit = <?= (int)$highlightLimit ?>;
  var scannerRunning = false;
  var lastScannedNo = null;

  function hideScanner() {
    document.getElementById('qrReader').style.display = 'none';
    document.getElementById('qrManualZone').style.display = 'none';
  }

  function showScanner() {
    document.getElementById('qrReader').style.display = '';
    document.getElementById('qrManualZone').style.display = '';
  }

  function resetPersonCard() {
    document.getElementById('qrPersonCard').style.display = 'none';
    document.getElementById('qrSaveStatus').style.display = 'none';
    document.querySelectorAll('.qr-size-btn').forEach(function(b){ b.classList.remove('btn-primary','active'); b.classList.add('btn-outline-dark'); });
    lastScannedNo = null;
    showScanner();
  }

  function lookupPerson(no) {
    no = String(no).trim();
    if (!no || no === lastScannedNo) return;
    lastScannedNo = no;

    // Find in DataTable data
    var allData = tbl.data().toArray();
    var person = null;
    var rank = -1;

    // Sort by inscription_no ASC to determine rank
    var sorted = allData.slice().sort(function(a,b){
      var numA = parseInt(String(a.inscription_no).replace(/[ES]/g,'')) || 0;
      var numB = parseInt(String(b.inscription_no).replace(/[ES]/g,'')) || 0;
      return numA - numB;
    });
    for (var i = 0; i < sorted.length; i++) {
      var ino = String(sorted[i].inscription_no);
      // Correspondance exacte ou sans préfixe (compatibilité anciens QR codes)
      if (ino === no || ino === 'E' + no || ino === 'S' + no) {
        person = sorted[i];
        rank = i + 1;
        lastScannedNo = ino;
        break;
      }
    }

    if (!person) {
      hideScanner();
      document.getElementById('qrPersonCard').style.display = 'block';
      document.getElementById('qrEligibility').innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle me-1"></i>Inscription N°' + no + ' introuvable</div>';
      document.getElementById('qrPersonName').textContent = '';
      document.getElementById('qrPersonNo').textContent = '';
      document.getElementById('qrPersonVille').textContent = '';
      document.getElementById('qrTshirtZone').style.display = 'none';
      return;
    }

    // Hide scanner, show person card
    hideScanner();
    document.getElementById('qrPersonCard').style.display = 'block';
    document.getElementById('qrTshirtZone').style.display = 'block';
    document.getElementById('qrSaveStatus').style.display = 'none';
    document.getElementById('qrPersonName').textContent = (person.prenom || '') + ' ' + (person.nom || '');
    document.getElementById('qrPersonNo').textContent = 'N°' + person.inscription_no;
    document.getElementById('qrPersonVille').textContent = person.ville || '';

    // Eligibility
    var eligible = (highlightLimit === 0) || (rank <= highlightLimit);
    var eligDiv = document.getElementById('qrEligibility');
    if (eligible) {
      eligDiv.innerHTML = '<div class="alert alert-success py-2 mb-0"><i class="bi bi-check-circle-fill me-2"></i><strong>Éligible T-shirt</strong>' + (highlightLimit > 0 ? ' — inscrit N°' + rank + ' / ' + highlightLimit : '') + '</div>';
    } else {
      eligDiv.innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle-fill me-2"></i><strong>Non éligible T-shirt</strong> — inscrit N°' + rank + ' (limite : ' + highlightLimit + ')</div>';
    }

    // Avertissement si déjà scanné (taille déjà renseignée)
    var currentSize = person.tshirt_size || '-';
    if (currentSize !== '-') {
      var warnDiv = document.getElementById('qrSaveStatus');
      warnDiv.style.display = 'block';
      warnDiv.innerHTML = '<div class="alert alert-warning py-2 mb-0" style="font-size:15px;font-weight:600"><i class="bi bi-exclamation-triangle-fill me-2"></i>Attention : déjà scanné (taille ' + currentSize + ')</div>';
    }

    // Highlight current size
    document.querySelectorAll('.qr-size-btn').forEach(function(b){
      b.classList.remove('btn-primary','active');
      b.classList.add('btn-outline-dark');
      if (b.dataset.size === currentSize) {
        b.classList.remove('btn-outline-dark');
        b.classList.add('btn-primary','active');
      }
    });

    // Also highlight in main table
    document.getElementById('quickSearch').value = String(no);
    tbl.search(String(no)).draw();
  }

  // T-shirt size buttons
  document.getElementById('qrTshirtBtns').addEventListener('click', function(e){
    var btn = e.target.closest('.qr-size-btn');
    if (!btn || !lastScannedNo) return;

    var size = btn.dataset.size;

    // Find the person's DB id
    var allData = tbl.data().toArray();
    var person = allData.find(function(p){ return String(p.inscription_no) === String(lastScannedNo); });
    if (!person) return;

    // Highlight button
    document.querySelectorAll('.qr-size-btn').forEach(function(b){ b.classList.remove('btn-primary','active'); b.classList.add('btn-outline-dark'); });
    btn.classList.remove('btn-outline-dark');
    btn.classList.add('btn-primary','active');

    // Save to DB
    var status = document.getElementById('qrSaveStatus');
    status.style.display = 'block';
    status.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Sauvegarde…</span>';

    fetch('../config/api.php?route=registrations', {
      method: 'PUT',
      headers: {'Content-Type':'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': _csrfToken},
      body: new URLSearchParams({id: person.id, tshirt_size: size})
    }).then(function(){
      status.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i><strong>' + size + '</strong> enregistré pour ' + (person.prenom||'') + ' ' + (person.nom||'') + '</span>';
      tbl.ajax.reload(null, false);
      // Auto-reset after 1s for next scan
      setTimeout(function(){
        resetPersonCard();
        document.getElementById('qrManualInput').value = '';
        document.getElementById('qrManualInput').focus();
      }, 1000);
    }).catch(function(){
      status.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Erreur de sauvegarde</span>';
    });
  });

  // Open modal
  document.getElementById('btnScanQR').addEventListener('click', function(e){
    e.preventDefault();
    var dd = document.getElementById('ocDropdown');
    if (dd) dd.classList.remove('show');
    new bootstrap.Modal(qrScanModal).show();
  });

  // Start scanner on modal open
  qrModal.addEventListener('shown.bs.modal', function(){
    resetPersonCard();
    document.getElementById('qrManualInput').value = '';
    html5QrCode = new Html5Qrcode('qrReader');
    html5QrCode.start(
      { facingMode: 'environment' },
      { fps: 10, qrbox: { width: 250, height: 250 } },
      function onScanSuccess(decodedText) {
        var val = decodedText.trim();
        if (val) lookupPerson(val);
      },
      function onScanFailure() {}
    ).then(function(){ scannerRunning = true; })
    .catch(function(){
      scannerRunning = false;
      document.getElementById('qrReader').innerHTML =
        '<div class="alert alert-warning text-center py-3">' +
        '<i class="bi bi-camera-video-off d-block mb-2" style="font-size:2rem"></i>' +
        'Caméra non disponible. Utilisez la saisie manuelle.</div>';
    });
  });

  // Stop scanner on modal close
  qrModal.addEventListener('hidden.bs.modal', function(){
    if (html5QrCode && scannerRunning) {
      html5QrCode.stop().catch(function(){});
      scannerRunning = false;
    }
    if (html5QrCode) { html5QrCode.clear(); html5QrCode = null; }
    resetPersonCard();
    // Clear search
    document.getElementById('quickSearch').value = '';
    tbl.search('').draw();
  });

  // Next scan button
  document.getElementById('qrNextScan').addEventListener('click', function(){
    resetPersonCard();
    document.getElementById('qrManualInput').value = '';
    showScanner();
  });

  // Manual input
  document.getElementById('qrManualBtn').addEventListener('click', function(){
    var val = document.getElementById('qrManualInput').value;
    if (val) lookupPerson(val);
  });
  document.getElementById('qrManualInput').addEventListener('keydown', function(e){
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('qrManualBtn').click(); }
  });
})();
</script>
</body>
</html>
