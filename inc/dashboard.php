<?php
require '../config/config.php';
requireRole(['admin','user','viewer']);
$role = currentRole();

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
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tableau de bord – Forbach en Rose</title>

<!-- ─── CSS ─── -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="../css/forbach-style.css" rel="stylesheet">
<link href="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .first-750 td{background:#ffe5ff!important;font-weight:600}
  .hero{display:flex;align-items:center;justify-content:center;padding:2rem 1rem;background:var(--rose-500);color:#fff;position:relative}
  .hero h1{margin:0;font-size:2.2rem}
  .top-actions{position:absolute;top:1rem;right:1rem;display:flex;gap:.5rem}
  @media (max-width:991.98px){.top-actions{display:none}}
  .card-dashboard{margin-top:1rem;border-radius:2rem;box-shadow:0 0 25px rgba(0,0,0,.1)}
  .quick-search{max-width:450px;width:50%;margin:0 auto .75rem;position:sticky;top:0;z-index:1030}
  tr.filters th[class*="sorting"]::before,
  tr.filters th[class*="sorting"]::after{display:none!important}
  .statCard{min-width:180px}
  .hide-stats #stats {display: none !important;}
</style>
</head>

<body class="d-flex flex-column">

<?php include '../inc/nav-settings.php'; ?>

<!-- ═════════ MAIN ═════════ -->
<main class="container-fluid flex-grow-1">
  <div class="bg-white p-4 card-dashboard">

    <div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-3 gap-3">
      <h2 class="mb-0">Inscriptions</h2>

      <div class="d-none d-lg-flex flex-wrap gap-2">
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

              const res  = await fetch('../config/api.php?route=archive-current', {
                method: 'POST',
                credentials: 'same-origin'
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
          <button class="btn btn-warning"   data-bs-toggle="modal" data-bs-target="#usersModal">Utilisateurs</button>
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
</main>

<footer class="text-center py-3 small text-muted"><?= htmlspecialchars($footer) ?></footer>

<!-- ═════════ MODALES ═════════ -->
<div class="modal fade" id="addModal" tabindex="-1"><div class="modal-dialog">
  <div class="modal-content"><div class="modal-header">
    <h5 class="modal-title">Nouvel inscrit</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="fAdd">
      <div class="modal-body row g-2">
        <div id="addMsg" class="alert alert-success d-none" role="alert"></div>
        <input type="hidden" name="origine" value="Admin">
        <div class="col-md-6"><label class="form-label">Nom</label><input name="nom" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">Prénom</label><input name="prenom" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">Téléphone</label><input name="tel" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Email</label><input name="email" type="email" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Naissance</label><input name="naissance" type="text" class="form-control" placeholder="2000 ou 09/05/2000"></div>
        <div class="col-md-6"><label class="form-label">Sexe</label><select name="sexe" class="form-select"><option>H</option><option>F</option><option>Autre</option></select></div>
        <div class="col-md-4"><label class="form-label">T-shirt</label><select name="tshirt_size" class="form-select"><option>-</option><option>XS</option><option>S</option><option>M</option><option>L</option><option>XL</option><option>XXL</option></select></div>
        <div class="col-md-4"><label class="form-label">Ville</label><input name="ville" class="form-control"></div>
        <div class="col-md-4"><label class="form-label">Entreprise</label><input name="entreprise" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Paiement</label><select name="paiement_mode" class="form-select" required><option value="" disabled selected hidden>Choisir…</option><option>CB</option><option>espece</option><option>cheque</option></select></div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button class="btn btn-rose">Enregistrer</button></div>
    </form>
  </div></div></div>

<div class="modal fade" id="editModal" tabindex="-1"><div class="modal-dialog">
  <div class="modal-content"><div class="modal-header">
    <h5 class="modal-title">Modifier l’inscription</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="fEdit">
      <div class="modal-body row g-2">
        <input type="hidden" name="id">
        <input type="hidden" name="origine" value="Admin">
        <div class="col-md-6"><label class="form-label">Nom</label><input name="nom" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">Prénom</label><input name="prenom" class="form-control" required></div>
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

<?php if($role==='admin'): ?>
<div class="modal fade" id="usersModal" tabindex="-1"><div class="modal-dialog modal-lg">
 <div class="modal-content"><div class="modal-header">
   <h5 class="modal-title">Comptes utilisateurs</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <table id="tblUsers" class="table table-sm w-100"></table>
    <hr><h6>Nouveau compte</h6>
    <form id="fUser" class="row g-2">
      <input type="hidden" name="id">
      <div class="col-md-3"><input name="username" placeholder="login" class="form-control" required></div>
      <div class="col-md-3"><input name="password" placeholder="mot de passe" class="form-control" required></div>
      <div class="col-md-2"><select name="role" class="form-select"><option>viewer</option><option>user</option><option>saisie</option><option>admin</option></select></div>
      <div class="col-md-3"><input name="organisation" placeholder="organisation" class="form-control"></div>
      <div class="col-md-1 d-grid"><button class="btn btn-rose">OK</button></div>
    </form>
  </div></div></div></div>
<?php endif; ?>

<!-- ═════════ JS ═════════ -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.js"></script>

<script>
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
  ajax:{url:'../config/api.php?route=registrations',dataSrc:''},
  columns:[
    {data:'id',visible:false},
    {data:'inscription_no',title:'N°'},
    {data:'nom',title:'Nom'},
    {data:'prenom',title:'Prénom'},
    {data:'tshirt_size',title:'T-shirt',render:(v,t,r)=>{
      if(t!=='display') return v??''; if(!tshirtMode) return v??'';
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
    {data:'created_at',title:'Date ajout'},
    {data:'origine',title:'Origine'}
    <?php if($role==='admin'): ?>,
    {data:null,title:'',orderable:false,render:()=>'<button class="btn btn-sm btn-outline-primary edit">✏️</button>'}
    <?php endif; ?>
  ],
  dom:'lrtip',
  autoWidth:false,
  orderCellsTop:true,
  rowCallback:(row,d)=>{if(+d.inscription_no<=750)$(row).addClass('first-750')},
  initComplete:function(){
    buildFilters(this.api());
    updateStats(this.api().data().toArray());
  }
});
tbl.on('xhr.dt',(e,s,json)=>updateStats(json||[]));

setInterval(()=>tbl.ajax.reload(null,false),5000);
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
        .on('change',function(){ api.column(i).search(this.value? '^'+this.value+'$':'',true,false).draw();});
      this.data().unique().sort().each(v=>{if(v)$sel.append(`<option>${v}</option>`);});
    }
  });
  if(tshirtMode) $('.filters').hide();
}

/* ══ Bascule Remise T-shirts ════ */
function applyTshirtMode() {
  const hideHeaders = ['Sexe', 'Téléphone', 'Email', 'Naissance', 'Paiement', 'Entreprise', 'Date ajout', 'Origine', ''];
  // Masquer certaines colonnes
  tbl.columns().every(function () {
    const h = $(this.header()).text().trim();
    if (hideHeaders.includes(h)) this.visible(!tshirtMode, false);
  });
  // Masquer les filtres
  $('.filters').toggle(!tshirtMode);
  // Masquer ou afficher les stats via une classe CSS
  if (tshirtMode) {
    $('body').addClass('hide-stats');
  } else {
    $('body').removeClass('hide-stats');
    updateStats(tbl.data().toArray()); // on remet à jour les stats si on revient au mode normal
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
  fetch('../config/api.php?route=registrations',{method:'PUT',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({id:this.dataset.id,tshirt_size:this.value})});
});

/* ══ AJOUT ════ */
$('#fAdd').on('submit',e=>{
  e.preventDefault();
  const fd=new FormData(e.target); normalizeBirth(fd);
  fetch('../config/api.php?route=registrations',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.fromEntries(fd))})
  .then(r=>r.json()).then(j=>{
    if(j.inscription_no){
      tbl.ajax.reload(); e.target.reset();
      $('#addMsg').text('Inscription OK').removeClass('d-none').fadeIn(200).delay(3000).fadeOut(400);
      $('#fAdd [name="nom"]').focus();
    }
  });
});

/* ══ ÉDITION ════ */
$('#tbl').on('click','button.edit',function(){
  const d=tbl.row($(this).closest('tr')).data();
  Object.entries(d).forEach(([k,v])=>$('#fEdit [name="'+k+'"]').val(v));
  new bootstrap.Modal('#editModal').show();
});
$('#fEdit').on('submit',e=>{
  e.preventDefault();
  const fd=new FormData(e.target); normalizeBirth(fd);
  fetch('../config/api.php?route=registrations',{method:'PUT',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(fd)})
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

/* ══ COMPTES UTILISATEURS (ADMIN) ════ */
<?php if($role==='admin'): ?>
let usrTbl;
$('#usersModal').on('shown.bs.modal',()=>{
  if(usrTbl) return;
  usrTbl=$('#tblUsers').DataTable({
    ajax:{url:'../config/api.php?route=users',dataSrc:''},
    columns: [
      { data: 'id', title: '#' },
      { data: 'username', title: 'Login' },
      { data: 'role', title: 'Rôle' },
      { data: 'organisation', title: 'Organisation' },
      { data: 'created_at', title: 'Créé le' },
      {
        data: null,
        title: '',
        orderable: false,
        render: function () {
          return `
            <button class="btn btn-sm btn-outline-primary edit-user">✏️</button>
            <button class="btn btn-sm btn-outline-danger delete-user">🗑️</button>
          `;
        }
      }
    ]
  });
});

$('#tblUsers').on('click', '.edit-user', function () {
  const data = usrTbl.row($(this).closest('tr')).data();
  $('#fUser [name="id"]').val(data.id);
  $('#fUser [name="username"]').val(data.username);
  $('#fUser [name="role"]').val(data.role);
  $('#fUser [name="organisation"]').val(data.organisation);
  $('#fUser [name="password"]').val(''); // vide pour ne pas écraser si non modifié
});

$('#tblUsers').on('click', '.delete-user', function () {
  const data = usrTbl.row($(this).closest('tr')).data();
  if (!confirm(`Supprimer le compte "${data.username}" ?`)) return;

  const deleteUser = (force = false) => {
    const params = new URLSearchParams({ action: 'delete', id: data.id });
    if (force) params.append('force', '1');

    fetch('../config/api.php?route=users', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params
    })
    .then(r => r.json())
    .then(j => {
      if (j.ok) {
        usrTbl.ajax.reload();
      } else if (j.requiresForce) {
        if (confirm(j.warning + "\n\nVoulez-vous continuer et tout supprimer ?")) {
          deleteUser(true);
        }
      } else {
        alert("Erreur : " + (j.err || "inconnue"));
      }
    });
  };

  deleteUser();
});

$('#fUser').on('submit', e => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const id = fd.get('id');
  const method = id ? 'PUT' : 'POST';
  const body = id ? new URLSearchParams(fd) : JSON.stringify(Object.fromEntries(fd));

  fetch('../config/api.php?route=users', {
    method,
    headers: {
      'Content-Type': id ? 'application/x-www-form-urlencoded' : 'application/json'
    },
    body
  }).then(() => {
    usrTbl.ajax.reload();
    e.target.reset();
    $('#fUser [name="id"]').val('');
    $('#fUser [name="password"]').val('');
  });
});
<?php endif; ?>

/* ══ LOGOUT ════ */
$('#logout, #logout_m').on('click',e=>{
  e.preventDefault();
  fetch('../config/api.php?route=logout').then(()=>location='../login.php');
});
</script>
</body>
</html>
