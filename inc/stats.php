<?php
require '../config/config.php';
require_once __DIR__ . '/../config/csrf.php'; // jeton CSRF pour l'enregistrement des préférences colonnes
requirePage('stats');
$role = currentRole();
require 'navbar-data.php';

try {
    $stmt = $pdo->prepare(
        'SELECT *
           FROM setting
          WHERE id = :id
          LIMIT 1');
    $stmt->execute(['id' => 1]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    error_log('[STATS] ' . $e->getMessage());
    $data = [];
}
$childAge = (int) ($data['child_age_threshold'] ?? 12); // libellés « -N ans »



// 1. Récupère toutes les stats agrégées par année avec table_name ET les nouvelles colonnes
try {
    $stats = $pdo->query(
        'SELECT year, total_inscrits, tshirt_xs, tshirt_s, tshirt_m, tshirt_l, tshirt_xl, tshirt_xxl,
                age_moyen, table_name, ville_top, entreprise_top, plus_vieux_h, plus_vieille_f
           FROM registrations_stats
           ORDER BY year'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('[STATS] ' . $e->getMessage());
    $stats = [];
}
$years = array_column($stats,'year');
$currentYear = end($years) ?: date('Y');  // dernière année dispo ou année courante

// 2. Calcule les moyennes globales pour les cartes "bilan général"
$nbYr = count($stats);
$sumTotal = $sumAge = 0;
foreach($stats as $s){
  $sumTotal += $s['total_inscrits'];
  if($s['age_moyen']!==null){ $sumAge += $s['age_moyen']; }
}
$avgPerYear = $nbYr ? round($sumTotal / $nbYr,1) : 0;
$avgAgeGlob = $nbYr ? round($sumAge / $nbYr,1) : null;

?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Statistiques</title>

<!-- ─── CSS ─── -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.css" rel="stylesheet" integrity="sha384-Vxog91rIpStbMsSBAP+6bkpv+SJeVDvusYx9GKzKVQBzh085ohJ4QIgNlO4QbkVz" crossorigin="anonymous">
<link href="https://cdn.datatables.net/colreorder/1.7.0/css/colReorder.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" integrity="sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g" crossorigin="anonymous"></script>
<style>
  .card-dashboard{margin-top:1rem;border-radius:1.25rem;box-shadow:0 0 25px rgba(0,0,0,.1)}
  .stat-card{border-radius:1.25rem;background:#fff;box-shadow:0 0 20px rgba(0,0,0,.08);padding:1.25rem}
  .stat-title{font-size:.9rem;color:#6c757d;margin-bottom:0.5rem}
  /* Select « Année » : largeur mini + place pour la flèche (sinon elle chevauche le texte). */
  #selYear{ min-width:7rem; padding-right:2.5rem; }
  /* ─── Harmonisation DataTable (stats.php) ───────────────────────── */

/* ===== En-tête du tableau ===== */
#tbl thead tr:first-child th{
  background:#ffffff;                     /* fond blanc */
  color:#dc267f;                          /* rose identitaire (ou var(--rose-500) ) */
  font-weight:600;
  font-size:.8rem;
  text-transform:uppercase;
  letter-spacing:.45px;
  padding:.9rem .75rem;
  border-top:2px solid var(--rose-500);
  border:0;
  border-bottom:3px solid #dc267f;        /* fine barre d'accent */
}
#tbl thead th.sorting_asc,
#tbl thead th.sorting_desc{
  background:#fafafa;                     /* colonne triée légèrement grisée */
}

#tbl thead tr:first-child th:first-child{border-radius:8px 0 0 0}
#tbl thead tr:first-child th:last-child {border-radius:0 8px 0 0}

/* ===== Corps du tableau ===== */
#tbl tbody td{
  padding:.65rem .8rem;
  vertical-align:middle;
  font-size:.86rem;
}
#tbl tbody tr:nth-child(even){background:#fcfcfd}    /* zébrage soft */

#tbl tbody tr{
  transition:background .2s,box-shadow .2s;
}
#tbl tbody tr:hover{
  background:#f9f9fb;
  box-shadow:0 1px 4px rgba(0,0,0,.06);
}

/* Coins arrondis bas (quand peu de lignes) */
#tbl tbody tr:last-child td:first-child {border-radius:0 0 0 8px}
#tbl tbody tr:last-child td:last-child  {border-radius:0 0 8px 0}

/* ===== Boutons/éventuels badges (si tu en ajoutes plus tard) ===== */
.action-buttons .btn{
  --bs-btn-padding-y: .20rem;
  --bs-btn-padding-x: .45rem;
  --bs-btn-font-size: .75rem;
}

/* Exemple de badge rose pour les tailles T-shirt si tu les ajoutes :
.badge-size{background:#dc267f1a;color:#dc267f;font-weight:600;font-size:.75rem}
*/

</style>
</head>

<body>

<?php include '../inc/navbar-admin.php'; ?>

<div class="container-fluid pb-4">
  <h1 class="mb-3 fw-bold"><i class="bi bi-bar-chart me-2"></i>Statistiques</h1>

  <!-- ===== CARTES RÉCAP GÉNÉRAL ===== -->
  <div class="row row-cols-1 row-cols-md-3 g-4 mb-4 text-center">
    <div class="col">
      <div class="stat-card">
        <div class="stat-title mb-1">Moyenne inscrits / an</div>
        <div class="display-6 fw-bold" id="cardAvgTotal"><?= $avgPerYear ?></div>
      </div>
    </div>
    <div class="col">
      <div class="stat-card">
        <div class="stat-title mb-1">Âge moyen global</div>
        <div class="display-6 fw-bold" id="cardAvgAge"><?= $avgAgeGlob ? $avgAgeGlob.' ans' : '–' ?></div>
      </div>
    </div>
    <div class="col">
      <div class="stat-card">
        <div class="stat-title mb-1">Années archivées</div>
        <div class="display-6 fw-bold"><?= $nbYr ?></div>
      </div>
    </div>
  </div>

  <!-- ===== GRAPHIQUES GÉNÉRAUX ===== -->
  <div class="row g-4 mb-5">
    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="fw-semibold">Répartition T‑shirt par année</h6>
          <canvas id="chartSizes"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="fw-semibold">Évolution inscriptions et âge moyen</h6>
          <canvas id="chartCombined"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== DÉTAIL PAR ANNÉE ===== -->
  <h4 class="mb-3 fw-bold">Détails par année</h4>
  <div class="d-flex flex-wrap gap-3 align-items-center mb-3" id="statsControls">
    <div>
      <label class="me-2 fw-semibold">Année :</label>
      <select id="selYear" class="form-select d-inline-block w-auto">
        <?php foreach($years as $y): ?>
          <option value="<?= $y ?>"<?= $y==$currentYear?' selected':'' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <input type="text" id="searchInput" class="form-control" placeholder="Rechercher…" style="max-width:320px">
  </div>

  <!-- Cartes de stats année sélectionnée -->
  <div class="row row-cols-2 row-cols-md-3 row-cols-lg-6 g-3 mb-3" id="cardsYear"></div>

  <!-- Tableau des inscriptions -->
  <div class="table-responsive">
    <table id="tbl" class="table fer-table table-sm w-100">
      <thead>
        <tr>
          <th>#</th><th>Nom</th><th>Prénom</th><th>Tél</th><th>Email</th>
          <th>Naissance</th><th>Sexe</th><th>Ville</th><th>T‑shirt</th><th>Prestation</th>
        </tr>
      </thead>
    </table>
  </div>
</div>
<?php include '../inc/admin-footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.js" integrity="sha384-3wB6mhez87GBdPpEqKMU2wAH2Cjcvj8ynU/n7blM/JW4BLpVD0aTrx4ZE7IwFLSH" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/colreorder/1.7.0/js/dataTables.colReorder.min.js"></script>
<script src="../js/admin-table-filters.js?v=1" nonce="<?= $GLOBALS['csp_nonce'] ?>"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
const stats = <?= json_encode($stats) ?>;

/* ─────────── 1. Graphiques généraux ─────────── */
const lblYears = stats.map(s=>s.year);
const sizeKeys = ['tshirt_xs','tshirt_s','tshirt_m','tshirt_l','tshirt_xl','tshirt_xxl'];
const sizeLabels = ['XS','S','M','L','XL','XXL'];
const palette = ['#f9c5d1','#f6a2b4','#f37e98','#ef5b7b','#ec3860','#d60d3a'];

// a) barres empilées tailles
const sizeDatasets = sizeKeys.map((k,i)=>({
  label:sizeLabels[i],
  data:stats.map(s=>s[k] || 0), // Valeur par défaut 0 si undefined
  backgroundColor:palette[i]
}));
new Chart(document.getElementById('chartSizes'),{
  type:'bar',
  data:{labels:lblYears,datasets:sizeDatasets},
  options:{responsive:true,plugins:{legend:{position:'bottom'}},
           scales:{x:{stacked:true},y:{stacked:true}}}
});

// b) Graphique combiné : Inscriptions + Âge moyen
new Chart(document.getElementById('chartCombined'),{
  type:'line',
  data:{
    labels:lblYears,
    datasets:[
      {
        label:'Inscriptions',
        data:stats.map(s=>s.total_inscrits || 0),
        borderColor:'#ef5b7b',
        backgroundColor:'rgba(239,91,123,.2)',
        tension:.3,
        yAxisID:'y'
      },
      {
        label:'Âge moyen',
        data:stats.map(s=>s.age_moyen || null),
        borderColor:'#6c757d',
        backgroundColor:'rgba(108,117,125,.2)',
        tension:.3,
        yAxisID:'y1'
      }
    ]
  },
  options:{
    responsive:true,
    interaction:{mode:'index',intersect:false},
    plugins:{legend:{position:'bottom'}},
    scales:{
      y:{type:'linear',display:true,position:'left',title:{display:true,text:'Nombre d\'inscriptions'}},
      y1:{type:'linear',display:true,position:'right',title:{display:true,text:'Âge moyen'},
          grid:{drawOnChartArea:false}}
    }
  }
});

/* ─────────── 2. Stat cards année sélectionnée ─────────── */
function fillYearCards(year){
  const s = stats.find(x=>x.year==year);
  if(!s) {
    console.error('Statistiques non trouvées pour l\'année:', year);
    return;
  }

  const wrap = document.getElementById('cardsYear');
  wrap.innerHTML = `
  <div class="col"><div class="stat-card text-center"><div class="stat-title">Total</div><div class="h5 ">${s.total_inscrits || 0}</div></div></div>
  <div class="col"><div class="stat-card text-center"><div class="stat-title">Âge moyen</div><div class="h5 ">${s.age_moyen?Number(s.age_moyen).toFixed(1)+' ans':'–'}</div></div></div>
  <div class="col"><div class="stat-card text-center"><div class="stat-title">Ville top</div><div class="h5 ">${s.ville_top || '–'}</div></div></div>
  <div class="col"><div class="stat-card text-center"><div class="stat-title">Entreprise top</div><div class="h5 ">${s.entreprise_top || '–'}</div></div></div>
  <div class="col"><div class="stat-card text-center"><div class="stat-title">+ Vieux H</div><div class="h5 ">${s.plus_vieux_h || '–'}</div></div></div>
  <div class="col"><div class="stat-card text-center"><div class="stat-title">+ Vieille F</div><div class="h5 ">${s.plus_vieille_f || '–'}</div></div></div>`;
}

// Initialiser avec l'année courante
fillYearCards(<?= $currentYear ?>);

/* ─────────── 3. DataTable inscriptions ─────────── */
// Pagination compacte type "1 ... 4 5 6 ... 12"
$.fn.dataTable.ext.pager.numbers_length = 7;

let tbl = $('#tbl').DataTable({
  ajax:{
    url:'../config/api.php',
    data:function(){
      return { route:'registrations-archive', year: document.getElementById('selYear').value };
    },
    dataSrc:'',
    error:function(xhr, error, thrown){
      console.error('Erreur DataTable:', error, thrown, xhr.responseText);
    }
  },
  columns:[
    {data:'inscription_no'},
    {data:'nom'},
    {data:'prenom'},
    {data:'tel'},
    {data:'email'},
    {data:'naissance'},
    {data:'sexe'},
    {data:'ville'},
    {data:'tshirt_size'},
    {data:'prestation',defaultContent:'',render:function(val,type){
      if(type!=='display') return val;
      var lc = String(val||'').toLowerCase();
      if(lc === 'enfant_tshirt')  return 'Enfant -<?= $childAge ?> +T-shirt';
      if(lc === 'enfant_gratuit') return 'Enfant -<?= $childAge ?> (gratuit sans t-shirt)';
      if(lc === 'tarif_unique')   return 'Tarif unique';
      return '–';
    }}
  ],
  order:[[0,'desc']],
  pageLength:25,
  language:{loadingRecords:'Chargement…'},
  colReorder:true,   // déplacement des colonnes par glisser-déposer des en-têtes
  dom:'tpr'
});

/* Filtres d'en-tête (entonnoir) — module partagé. Sexe / Ville / T-shirt / Prestation. */
$('#tbl').on('init.dt', function(){
  if (window.FERTableFilters) {
    FERTableFilters.attach(tbl, {
      filterableTitles: ['Sexe','Ville','T‑shirt','T-shirt','Prestation'],
      filterableData:   ['sexe','ville','tshirt_size','prestation'],
      childAge: <?= (int)($childAge ?? 12) ?>
    });
  }
});

/* ─────────── 3b. Colonnes : ordre (ColReorder) + visibilité + largeurs ───────────
   Préférences PAR UTILISATEUR via la route ui-prefs (clés stats_col_*, distinctes
   du dashboard/saisie). Identification PAR CLÉ STABLE (data) → reste juste même
   après un déplacement de colonnes. Repli localStorage si le serveur ne répond pas. */
(function(){
  var table = document.getElementById('tbl');
  if(!table) return;
  var _csrfToken = <?= json_encode(csrf_token()) ?>;
  var uid = <?= json_encode($_SESSION['uid'] ?? 0) ?>;
  var storageKeyVis = 'fer_stats_col_vis_' + uid;
  var storageKeyW   = 'fer_stats_col_w_' + uid;

  // ── Préférences serveur ──
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
  function readLocalJSON(key){
    try { var v = JSON.parse(localStorage.getItem(key)); return (v && typeof v==='object') ? v : null; }
    catch(e){ return null; }
  }

  // ── Identification stable des colonnes (toutes togglables sur stats) ──
  function colKey(idx){
    var src = tbl.column(idx).dataSrc();
    if(src) return 'd:' + src;
    var th = tbl.column(idx).header();
    return 'h:' + ($(th).text().trim() || ('col'+idx));
  }
  function colIdxByKey(key){
    var found = -1;
    tbl.columns().every(function(idx){ if(found===-1 && colKey(idx)===key) found = idx; });
    return found;
  }
  var toggleCols = [];
  tbl.columns().every(function(idx){
    toggleCols.push({ key: colKey(idx), label: $(this.header()).text().trim() || 'Col '+idx });
  });

  // ── Visibilité ──
  function restoreVisibility(){
    try {
      var saved = (_uiPrefs && _uiPrefs.stats_col_vis) || readLocalJSON(storageKeyVis);
      if(!saved) return;
      var keys = Object.keys(saved);
      var legacy = keys.length>0 && keys.every(function(k){ return /^\d+$/.test(k); });
      keys.forEach(function(k){
        var key = legacy ? (toggleCols[parseInt(k,10)] && toggleCols[parseInt(k,10)].key) : k;
        if(!key) return;
        var ci = colIdxByKey(key);
        if(ci!==-1) tbl.column(ci).visible(saved[k]);
      });
      if(legacy) saveVisibility();
    } catch(e){}
  }
  function saveVisibility(){
    try {
      var vis = {};
      toggleCols.forEach(function(c){ var ci = colIdxByKey(c.key); if(ci!==-1) vis[c.key] = tbl.column(ci).visible(); });
      localStorage.setItem(storageKeyVis, JSON.stringify(vis));
      saveUiPref({ stats_col_vis: vis });
    } catch(e){}
  }

  // ── Largeurs ──
  function restoreWidths(){
    try {
      var saved = (_uiPrefs && _uiPrefs.stats_col_widths) || readLocalJSON(storageKeyW);
      if(!saved) return;
      var keys = Object.keys(saved);
      var legacy = keys.length>0 && keys.every(function(k){ return /^\d+$/.test(k); });
      if(legacy){
        var j = 0;
        tbl.columns().every(function(idx){
          if(!tbl.column(idx).visible()) return;
          var w = saved[j++]; var th = tbl.column(idx).header();
          if(w && th){ th.style.width = w; th.style.minWidth = w; }
        });
      } else {
        tbl.columns().every(function(idx){
          var w = saved[colKey(idx)]; var th = tbl.column(idx).header();
          if(w && th){ th.style.width = w; th.style.minWidth = w; }
        });
      }
    } catch(e){}
  }
  function saveWidths(){
    try {
      var widths = {};
      tbl.columns().every(function(idx){
        var th = tbl.column(idx).header();
        if(th && th.style.width) widths[colKey(idx)] = th.style.width;
      });
      localStorage.setItem(storageKeyW, JSON.stringify(widths));
      saveUiPref({ stats_col_widths: widths });
    } catch(e){}
  }

  // ── Poignées de redimensionnement (+ anti-tri quand on relâche hors de la poignée) ──
  function initResize(){
    var ths = table.querySelectorAll('thead tr:first-child th');
    ths.forEach(function(th){
      if(th.querySelector('.col-resize')) return;
      var handle = document.createElement('div');
      handle.className = 'col-resize';
      th.appendChild(handle);
      handle.addEventListener('mousedown', function(e){
        e.preventDefault(); e.stopPropagation();
        var startX = e.pageX, startW = th.offsetWidth, moved = false;
        handle.classList.add('active');
        function onMove(e2){ moved = true; th.style.width = Math.max(40, startW + e2.pageX - startX) + 'px'; th.style.minWidth = th.style.width; }
        function onUp(){
          handle.classList.remove('active'); document.removeEventListener('mousemove',onMove); document.removeEventListener('mouseup',onUp); saveWidths();
          if(moved){ var swallow = function(ev){ ev.stopPropagation(); ev.preventDefault(); }; th.addEventListener('click', swallow, true); setTimeout(function(){ th.removeEventListener('click', swallow, true); }, 0); }
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
      });
    });
    restoreWidths();
  }

  // ── Bouton « Colonnes » (placé à droite de la barre Année/Recherche) ──
  function buildColToggle(){
    var host = document.getElementById('statsControls');
    if(!host || document.getElementById('colToggleWrapStats')) return;
    var wrap = document.createElement('div');
    wrap.className = 'col-toggle-wrap';
    wrap.id = 'colToggleWrapStats';
    wrap.style.marginLeft = 'auto';
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'col-toggle-btn';
    btn.innerHTML = '<i class="bi bi-layout-three-columns"></i> Colonnes';
    var dropdown = document.createElement('div');
    dropdown.className = 'col-toggle-dropdown';
    var head = document.createElement('div'); head.className = 'cfp-head'; head.textContent = 'Afficher / masquer';
    dropdown.appendChild(head);
    toggleCols.forEach(function(c){
      var label = document.createElement('label');
      var cb = document.createElement('input'); cb.type = 'checkbox';
      var ci = colIdxByKey(c.key);
      cb.checked = ci===-1 ? true : tbl.column(ci).visible();
      cb.style.accentColor = '#F42182';
      cb.addEventListener('change', function(){
        var idxNow = colIdxByKey(c.key);
        if(idxNow!==-1) tbl.column(idxNow).visible(this.checked);
        saveVisibility();
      });
      label.appendChild(cb); label.appendChild(document.createTextNode(' ' + c.label));
      dropdown.appendChild(label);
    });
    btn.addEventListener('click', function(e){ e.stopPropagation(); dropdown.classList.toggle('show'); });
    document.addEventListener('click', function(e){ if(!wrap.contains(e.target)) dropdown.classList.remove('show'); });
    wrap.appendChild(btn); wrap.appendChild(dropdown);
    host.appendChild(wrap);
  }

  // ── Ordre des colonnes (ColReorder) mémorisé ──
  var _applyingColOrder = false;
  function setupColOrder(){
    var ord = _uiPrefs && _uiPrefs.stats_col_order;
    if(Array.isArray(ord) && ord.length === tbl.columns().count()){
      _applyingColOrder = true;
      try { tbl.colReorder.order(ord, true); } catch(_){}
      _applyingColOrder = false;
    }
    tbl.on('column-reorder', function(){
      if(_applyingColOrder) return;
      saveUiPref({ stats_col_order: tbl.colReorder.order() });
    });
  }

  // ── Init : attend les prefs serveur, applique, construit ──
  $('#tbl').on('init.dt', function(){
    loadUiPrefs().then(function(){
      setupColOrder();
      restoreVisibility();
      buildColToggle();
      initResize();
    });
  });
  // Réinjecte les poignées après chaque redraw (ex. changement d'année).
  $('#tbl').on('draw.dt', function(){ initResize(); });
})();

/* ─────────── 4. Interaction : année + recherche ─────────── */
document.getElementById('selYear').addEventListener('change',()=>{
  const selectedYear = document.getElementById('selYear').value;
  tbl.ajax.reload();
  fillYearCards(selectedYear);
});

document.getElementById('searchInput').addEventListener('input',e=>{
  tbl.search(e.target.value).draw();
});
</script>
</body>
</html>
