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
$footer= $data['footer'] ?? '';  
$titleColor = $data['title_color'] ?? '#ffffff';

// Gestion des messages flash
$flashMessage = null;
if (isset($_SESSION['flash_message'])) {
    $flashMessage = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']); // Supprimer après récupération
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tableau de bord</title>
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">

<!-- ─── CSS ─── -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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
    background:linear-gradient(135deg,#ec4899,#db2777)!important;
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

/* 750 premières lignes — fond rose pâle */
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
#tbl thead th .col-resize.active { background: #ec4899; }

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
  <div class="bg-white p-4 card-dashboard">
    <!-- Message flash de confirmation -->
    <?php if ($flashMessage): ?>
    <div class="container-fluid">
      <div class="alert alert-<?= $flashMessage['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show mt-3" role="alert">
        <i class="bi bi-<?= $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
        <?= htmlspecialchars($flashMessage['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    </div>
    <?php endif; ?>
    <script>
      // Auto-masquage des messages flash au bout de 5 secondes
      document.addEventListener('DOMContentLoaded', function() {
        const flashAlert = document.querySelector('.alert');
        if (flashAlert && flashAlert.classList.contains('alert-success')) {
          setTimeout(function() {
            const bsAlert = new bootstrap.Alert(flashAlert);
            bsAlert.close();
          }, 5000); // 5 secondes
        }
      });
    </script>

    <div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-3 gap-3">
      <h2 class="mb-0">Inscriptions</h2>

      <div class="dashboard-actions d-none d-lg-flex flex-wrap gap-2">
        <?php if($role!=='viewer'): ?>
          <button class="btn btn-rose"      data-bs-toggle="modal" data-bs-target="#addModal">Nouvel inscrit</button>
        <?php endif; ?>
        <?php if($role==='admin'): ?>
          <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#importModal">Import Excel</button>
        <?php endif; ?>
        <?php if($role==='admin' || $role==='user'): ?>
          <button id="btnExport" class="btn btn-info">Export Excel</button>
            <script>
            document.getElementById('btnExport').addEventListener('click', () => {
              // simple redirection => déclenche le téléchargement
              window.location = '../config/api.php?route=export-excel';
            });
            </script>
          <?php endif; ?>
          <?php if($role==='admin'): ?>
            <button id="btnArchiveNow" class="btn btn-danger">Archiver&nbsp;<?= date('Y') ?></button>

            <script>
            document.getElementById('btnArchiveNow').addEventListener('click', async () => {
              if (!confirm('Tout archiver et réinitialiser les inscriptions ?')) return;

              const _ct = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
              const res  = await fetch('../config/api.php?route=archive-current', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'X-CSRF-TOKEN': _ct}
              });
              const json = await res.json();
              if (json.ok) {
                alert(`✅ ${json.archived} inscription(s) archivées (${json.year}).`);
                location.reload();                 // tableau vide prêt pour la nouvelle année
              } else {
                alert('Erreur archivage : ' + JSON.stringify(json));
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
        <div class="col-md-6"><label class="form-label">Nom <span style="color:#ef4444">*</span></label><input name="nom" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">Prénom <span style="color:#ef4444">*</span></label><input name="prenom" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">Téléphone</label><input name="tel" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Email</label><input name="email" type="email" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Naissance</label><input name="naissance" type="text" class="form-control" placeholder="2000 ou 09/05/2000"></div>
        <div class="col-md-6"><label class="form-label">Sexe</label><select name="sexe" class="form-select"><option>H</option><option>F</option><option>Autre</option></select></div>
        <div class="col-md-4"><label class="form-label">T-shirt</label><select name="tshirt_size" class="form-select"><option>-</option><option>XS</option><option>S</option><option>M</option><option>L</option><option>XL</option><option>XXL</option></select></div>
        <div class="col-md-4"><label class="form-label">Ville</label><input name="ville" class="form-control"></div>
        <div class="col-md-4"><label class="form-label">Entreprise</label><input name="entreprise" class="form-control"></div>
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
        <div class="col-md-6"><label class="form-label">Nom <span style="color:#ef4444">*</span></label><input name="nom" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">Prénom <span style="color:#ef4444">*</span></label><input name="prenom" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">Téléphone</label><input name="tel" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Email</label><input name="email" type="email" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Naissance</label><input name="naissance" type="text" class="form-control" placeholder="2000 ou 09/05/2000"></div>
        <div class="col-md-6"><label class="form-label">Sexe</label><select name="sexe" class="form-select"><option>H</option><option>F</option><option>Autre</option></select></div>
        <div class="col-md-4"><label class="form-label">T-shirt</label><select name="tshirt_size" class="form-select"><option></option><option>XS</option><option>S</option><option>M</option><option>L</option><option>XL</option><option>XXL</option></select></div>
        <div class="col-md-4"><label class="form-label">Ville</label><input name="ville" class="form-control"></div>
        <div class="col-md-4"><label class="form-label">Entreprise</label><input name="entreprise" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Paiement</label><select name="paiement_mode" class="form-select"><option>CB</option><option>espece</option><option>cheque</option></select></div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button class="btn btn-rose">Sauvegarder</button></div>
    </form>
  </div></div></div>

<div class="modal fade" id="importModal" tabindex="-1"><div class="modal-dialog">
 <div class="modal-content"><div class="modal-header">
   <h5 class="modal-title">Import Excel</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="fImport" enctype="multipart/form-data"><div class="modal-body">
    <input type="file" name="file" accept=".xlsx,.xls" class="form-control" required>
  </div><div class="modal-footer">
    <button class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
    <button type="submit" class="btn btn-rose">Importer</button>
  </div></form></div></div></div>



<!-- ═════════ JS ═════════ -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.js"></script>

<script>
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
    {// colonne ID juste après « id de la bdd invisible »
      data: null,
      title: 'ID',
      width: '60px',
      className: 'text-center',
      orderable: false,     // pas de tri sur cette colonne
      defaultContent: ''    // on remplira la cellule dans rowCallback
    },
    {data:'inscription_no',title:'N°'},
    {data:'nom',title:'Nom'},
    {data:'prenom',title:'Prénom'},
    {data:'tshirt_size',title:'T-shirt',render:(v,t,r)=>{
      if(t!=='display') return v??''; if(!tshirtMode) return v??'';
      // Si c'est un viewer, on affiche juste le texte grisé
      if(userRole === 'viewer') {
        return `<span class="text-muted" style="font-style: italic; opacity: 0.6;">${v || '-'}</span>`;
      }
      const sz=['-','XS','S','M','L','XL','XXL'];
      return `<select class="form-select form-select-sm tshirt-dd" data-id="${r.id}">
              ${sz.map(s=>`<option${s===v?' selected':''}>${s}</option>`).join('')}</select>`;
    }},
    {data:'sexe',title:'Sexe',className:'text-center',width:'50px'},
    {data:'tel',title:'Téléphone'},
    {data:'email',title:'Email'},
    {data:'naissance',title:'Naissance'},
    {data:'paiement_mode',title:'Paiement'},
    {data:'entreprise',title:'Entreprise'},
    {data   : 'created_at',
      title  : 'Date ajout',
      render : function (val, type){
        // le type "display" (cellule visible) et "filter" (recherche) → JJ/MM/AAAA
        if(type === 'display' || type === 'filter'){
          if(!val) return '';
          const d = new Date(val);
          return d.toLocaleDateString('fr-FR');      // 15/05/2025
        }
        // pour le tri ("sort") on renvoie la valeur brute ISO
        return val;
      },
      width  : '110px',
      className : 'text-nowrap text-center'
    },
    {data:'origine',title:'Origine'}
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
    $(row).toggleClass('first-750', displayIndex < 750);
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
  } else {
    $('body').removeClass('hide-stats');
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

/* ══ IMPORT EXCEL ════ */
document.getElementById('fImport').addEventListener('submit', async (e) => {
  e.preventDefault();

  const form   = e.target;
  const button = form.querySelector('.btn-rose');
  const data   = new FormData(form);

  button.disabled   = true;
  button.textContent = 'Import...';

  try {
    const res = await fetch('../config/api.php?route=import-excel', {
      method:      'POST',
      headers:     {'X-CSRF-TOKEN': _csrfToken},
      body:        data,
      credentials: 'same-origin'   // garde la session PHP
    });

    if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);

    const json = await res.json();

    if (json.ok) {
      alert(`✅ ${json.rows_added} ligne(s) importée(s).`);
      bootstrap.Modal.getOrCreateInstance('#importModal').hide();

      /* ---- RAFRAÎCHIT LA PAGE ---- */
      location.reload();
    }
  } catch (err) {
    alert('Erreur réseau/serveur : ' + err.message);
  } finally {
    button.disabled   = false;
    button.textContent = 'Importer';
    form.reset();
  }
});


/* ══ Colonnes redimensionnables + toggle visibilité ════ */
(function() {
  var table = document.getElementById('tbl');
  if (!table) return;
  var uid = <?= json_encode($_SESSION['uid'] ?? 0) ?>;
  var storageKeyVis = 'fer_col_vis_' + uid;
  var storageKeyW = 'fer_col_w_' + uid;

  // Column names for toggle (match DataTable columns order, skip hidden id col 0)
  var colNames = ['ID', 'N°', 'Nom', 'Prénom', 'T-shirt', 'Sexe', 'Téléphone', 'Email', 'Naissance', 'Paiement', 'Entreprise', 'Date ajout', 'Origine'<?php if($role !== 'viewer'): ?>, 'Actions'<?php endif; ?>];

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
      cb.style.accentColor = '#ec4899';
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
</script>
</body>
</html>
