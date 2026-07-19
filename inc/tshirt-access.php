<?php
/**
 * inc/tshirt-access.php — Administration de l'accès « Remise T-shirts » bénévoles.
 *
 * Permet d'ouvrir/fermer l'accès, de régénérer le token de campagne (kill switch),
 * d'afficher le QR + lien à donner aux bénévoles, de valider/refuser les demandes
 * d'accès et de révoquer un appareil. Protégé par la permission de page `tshirt_access`.
 */
require '../src/core/config.php';
require_once '../src/security/csrf.php';
requirePage('tshirt_access');
$role = currentRole();
require __DIR__ . '/../src/partials/navbar-data.php';

// Droits granulaires de la page (admin = tout). La page seule = lecture (Dernières
// remises) ; chaque bloc supplémentaire dépend de son droit.
$tsCanManage  = canDoAction('tshirt_access.manage');
$tsCanApprove = canDoAction('tshirt_access.approve');
$tsCanRevoke  = canDoAction('tshirt_access.devices_revoke');
// « Révoquer » implique « voir les connectés » (on ne révoque pas à l'aveugle).
$tsCanDevices = canDoAction('tshirt_access.devices_view') || $tsCanRevoke;

// URL publique de la page bénévole (le token n'est PAS dans l'URL : l'accès exige
// toujours la validation admin ; le QR ne fait qu'ouvrir la page).
// On déduit la racine de l'application depuis l'emplacement de CE script, pour
// gérer aussi bien une install en sous-dossier (…/FER/inc/tshirt-access.php →
// racine « /FER ») qu'à la racine du domaine (…/inc/tshirt-access.php → « »).
$protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));   // ex : /FER/inc
$appRoot   = rtrim(str_replace('\\', '/', dirname($scriptDir)), '/');           // ex : /FER  (ou "")
$publicUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . $appRoot . '/public/remise-tshirts.php';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Accès bénévoles — Remise T-shirts</title>
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style nonce="<?= $GLOBALS['csp_nonce'] ?>">
  .card-dashboard{margin-top:1rem;border-radius:1.25rem;box-shadow:0 0 25px rgba(0,0,0,.08)}
  .ts-link-box{font-family:monospace;font-size:.85rem;background:var(--surface-2);padding:8px 12px;border-radius:6px;word-break:break-all}
  #tsQr canvas{border:1px solid var(--border);border-radius:8px;padding:6px;background: var(--surface)}
  .pulse-dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px}
</style>
</head>
<body>
<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>
<div>

  <div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-3 gap-2">
    <h1 class="mb-0 fw-bold"><i class="bi bi-tshirt me-2"></i>Accès bénévoles — Remise T-shirts</h1>
    <div id="statusBadge"></div>
  </div>

  <div class="row g-3">

    <?php if ($tsCanManage): ?>
    <!-- Colonne gauche : état + QR + lien (droit « gérer l'état de l'accès ») -->
    <div class="col-lg-5">
      <div class="card card-dashboard">
        <div class="card-body">
          <h5 class="fw-bold mb-3">État de l'accès</h5>

          <div class="d-flex align-items-center justify-content-between mb-3">
            <span>Accès ouvert aux bénévoles</span>
            <div class="form-check form-switch m-0">
              <input class="form-check-input" type="checkbox" role="switch" id="toggleAccess" style="width:3rem;height:1.5rem">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label small text-muted mb-1">Durée d'ouverture (auto-fermeture ensuite)</label>
            <select id="daysSelect" class="form-select form-select-sm">
              <option value="1">1 jour</option>
              <option value="3">3 jours</option>
              <option value="7" selected>1 semaine</option>
              <option value="14">2 semaines</option>
            </select>
          </div>

          <p class="small text-muted mb-3" id="expiryInfo"></p>

          <div id="qrZone" class="text-center mb-3">
            <div id="tsQr" class="d-inline-block mb-2"></div>
            <div class="ts-link-box mb-2" id="publicLink"><?= htmlspecialchars($publicUrl) ?></div>
            <div class="d-flex gap-2 justify-content-center flex-wrap">
              <button class="btn btn-sm btn-outline-secondary" id="copyLinkBtn"><i class="bi bi-clipboard"></i> Copier le lien</button>
              <button class="btn btn-sm btn-outline-primary" id="printQrBtn"><i class="bi bi-printer"></i> Imprimer le QR</button>
            </div>
          </div>

          <hr>
          <button class="btn btn-outline-danger btn-sm w-100" id="regenBtn">
            <i class="bi bi-arrow-repeat me-1"></i>Régénérer le token (coupe tous les accès)
          </button>
          <p class="small text-muted mt-2 mb-0">Régénérer invalide immédiatement tous les appareils déjà connectés : à utiliser en cas de perte/vol, ou pour une nouvelle campagne.</p>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Colonne droite : demandes + appareils actifs + remises -->
    <div class="col-lg-<?= $tsCanManage ? '7' : '12' ?>">

      <?php if ($tsCanApprove): ?>
      <div class="card card-dashboard">
        <div class="card-body">
          <h5 class="fw-bold mb-3"><i class="bi bi-hourglass-split me-1"></i>Demandes en attente <span class="badge bg-danger ms-1" id="pendingCount">0</span></h5>
          <div id="pendingList" class="list-group"><p class="text-muted small mb-0">Aucune demande en attente.</p></div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($tsCanDevices): ?>
      <div class="card card-dashboard">
        <div class="card-body">
          <h5 class="fw-bold mb-3"><i class="bi bi-people me-1"></i>Bénévoles connectés</h5>
          <div id="activeList" class="list-group"><p class="text-muted small mb-0">Aucun bénévole connecté.</p></div>
        </div>
      </div>
      <?php endif; ?>

      <div class="card card-dashboard">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h5 class="fw-bold mb-0"><i class="bi bi-clock-history me-1"></i>Dernières remises</h5>
            <div class="d-flex gap-2">
              <a class="btn btn-sm btn-outline-success" href="<?= htmlspecialchars('../admin-api.php?route=tshirt-admin&export=handouts') ?>"><i class="bi bi-file-earmark-excel me-1"></i>Exporter</a>
              <?php if ($tsCanManage): ?>
              <button class="btn btn-sm btn-outline-danger" id="clearHandoutsBtn"><i class="bi bi-trash me-1"></i>Vider la liste</button>
              <?php endif; ?>
            </div>
          </div>
          <div id="handoutList"><p class="text-muted small mb-0">Aucune remise enregistrée.</p></div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php require __DIR__ . '/../src/partials/admin-footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  var API = '../admin-api.php';
  var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var PUBLIC_URL = <?= json_encode($publicUrl) ?>;
  var refreshTimer = null;

  function apiGet(){ return fetch(API+'?route=tshirt-admin', {headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()); }
  function apiPost(body){
    return fetch(API+'?route=tshirt-admin', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF}, body:JSON.stringify(body||{})}).then(r=>r.json());
  }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  function fmt(dt){ if(!dt) return '—'; var d=new Date(dt.replace(' ','T')); return isNaN(d)? dt : d.toLocaleString('fr-FR'); }

  // QR (encode juste l'URL publique — aucun secret). Présent seulement si le bloc
  // « État de l'accès » est affiché (droit « manage »).
  var qr = null;
  var tsQrEl = document.getElementById('tsQr');
  if (tsQrEl) {
    qr = new QRious({element: document.createElement('canvas'), value: PUBLIC_URL, size: 190});
    tsQrEl.appendChild(qr.element);
  }

  function setText(id, t){ var el = document.getElementById(id); if (el) el.textContent = t; }
  function setHtml(id, h){ var el = document.getElementById(id); if (el) el.innerHTML = h; }

  function render(data){
    var perms = data.perms || {};

    // État de l'accès (droit « manage ») — présent seulement si renvoyé par l'API.
    if (data.config) {
      var c = data.config;
      var tg = document.getElementById('toggleAccess'); if (tg) tg.checked = !!c.open;
      setHtml('statusBadge', c.open
        ? '<span class="badge bg-success"><span class="pulse-dot bg-white"></span>Ouvert</span>'
        : '<span class="badge bg-secondary">Fermé</span>');
      setHtml('expiryInfo', c.open && c.expires_at
        ? '<i class="bi bi-clock me-1"></i>Fermeture automatique le <strong>' + esc(fmt(c.expires_at)) + '</strong>'
        : (c.expires_at ? 'Dernière fenêtre expirée le ' + esc(fmt(c.expires_at)) : 'Accès jamais ouvert.'));
      var qz = document.getElementById('qrZone'); if (qz) qz.style.opacity = c.open ? '1' : '.5';
    }

    // Demandes en attente (droit « approve »)
    var pl = document.getElementById('pendingList');
    if (pl) {
      var pend = data.pending || [];
      setText('pendingCount', pend.length);
      pl.innerHTML = pend.length === 0
        ? '<p class="text-muted small mb-0">Aucune demande en attente.</p>'
        : pend.map(function(p){
            return '<div class="list-group-item d-flex justify-content-between align-items-center">'
              + '<div><strong>' + esc(p.volunteer_name) + '</strong><br><span class="text-muted small">' + esc(fmt(p.created_at)) + ' · ' + esc(p.ip||'') + '</span></div>'
              + '<div class="d-flex gap-1">'
              + '<button class="btn btn-sm btn-success act" data-act="approve" data-id="'+p.id+'"><i class="bi bi-check-lg"></i></button>'
              + '<button class="btn btn-sm btn-outline-danger act" data-act="refuse" data-id="'+p.id+'"><i class="bi bi-x-lg"></i></button>'
              + '</div></div>';
          }).join('');
    }

    // Bénévoles connectés (droit « devices_view ») ; bouton Révoquer si « devices_revoke ».
    var al = document.getElementById('activeList');
    if (al) {
      var act = data.active || [];
      al.innerHTML = act.length === 0
        ? '<p class="text-muted small mb-0">Aucun bénévole connecté.</p>'
        : act.map(function(a){
            var rev = perms.devices_revoke
              ? '<button class="btn btn-sm btn-outline-danger act" data-act="revoke" data-id="'+a.id+'"><i class="bi bi-slash-circle"></i> Révoquer</button>'
              : '';
            return '<div class="list-group-item d-flex justify-content-between align-items-center">'
              + '<div><strong>' + esc(a.volunteer_name) + '</strong><br><span class="text-muted small">validé ' + esc(fmt(a.approved_at)) + (a.last_seen ? ' · vu ' + esc(fmt(a.last_seen)) : '') + '</span></div>'
              + rev + '</div>';
          }).join('');
    }

    // Remises récentes (lecture de base — toujours présent)
    var hl = document.getElementById('handoutList');
    if (hl) {
      var ho = data.handouts || [];
      hl.innerHTML = ho.length === 0
        ? '<p class="text-muted small mb-0">Aucune remise enregistrée.</p>'
        : '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>N°</th><th>Nom</th><th>Prénom</th><th>Taille</th><th>Bénévole</th><th>Quand</th></tr></thead><tbody>'
          + ho.map(function(h){ return '<tr><td>'+esc(h.inscription_no)+'</td><td>'+esc(h.nom||'—')+'</td><td>'+esc(h.prenom||'—')+'</td><td><span class="badge bg-dark">'+esc(h.size)+'</span></td><td>'+esc(h.volunteer_name||'—')+'</td><td class="small text-muted">'+esc(fmt(h.created_at))+'</td></tr>'; }).join('')
          + '</tbody></table></div>';
    }

    // Actions (déléguées) — les boutons ne sont rendus que si l'utilisateur y a droit.
    Array.prototype.forEach.call(document.querySelectorAll('.act'), function(b){
      b.addEventListener('click', function(){
        this.disabled = true;
        apiPost({action:this.dataset.act, id:parseInt(this.dataset.id,10)}).then(load);
      });
    });
  }

  function load(){ apiGet().then(function(res){ if (res && res.ok) render(res); }); }

  var toggleEl = document.getElementById('toggleAccess');
  if (toggleEl) toggleEl.addEventListener('change', function(){
    apiPost({action:'toggle', enabled:this.checked, days:parseInt(document.getElementById('daysSelect').value,10)}).then(load);
  });
  var daysEl = document.getElementById('daysSelect');
  if (daysEl) daysEl.addEventListener('change', function(){
    if (document.getElementById('toggleAccess').checked){
      apiPost({action:'set_expiry', days:parseInt(this.value,10)}).then(load);
    }
  });
  var regenEl = document.getElementById('regenBtn');
  if (regenEl) regenEl.addEventListener('click', function(){
    if (!confirm('Régénérer le token ? Tous les bénévoles actuellement connectés devront redemander l\'accès.')) return;
    apiPost({action:'regen', days:parseInt(document.getElementById('daysSelect').value,10)}).then(load);
  });
  var copyEl = document.getElementById('copyLinkBtn');
  if (copyEl) copyEl.addEventListener('click', function(){
    navigator.clipboard.writeText(PUBLIC_URL).then(function(){ var t=copyEl.innerHTML; copyEl.innerHTML='<i class="bi bi-check2"></i> Copié'; setTimeout(function(){copyEl.innerHTML=t;},1500); });
  });
  var clearEl = document.getElementById('clearHandoutsBtn');
  if (clearEl) clearEl.addEventListener('click', function(){
    if (!confirm('Vider la liste des remises ? Cette action est définitive.')) return;
    clearEl.disabled = true;
    apiPost({action:'clear_handouts'}).then(function(res){
      clearEl.disabled = false;
      if (res && res.ok) load();
      else alert('Échec de la suppression.');
    });
  });

  var printEl = document.getElementById('printQrBtn');
  if (printEl) printEl.addEventListener('click', function(){
    if (!qr) return;
    var w = window.open('', '_blank');
    w.document.write('<html><head><title>QR Remise T-shirts</title></head><body style="text-align:center;font-family:sans-serif;padding:40px">'
      + '<h2>Remise des T-shirts</h2><p>Scannez pour accéder à l\'espace bénévoles</p>'
      + '<img src="'+qr.toDataURL()+'" style="width:320px"><p style="font-size:12px;color:#666">'+esc(PUBLIC_URL)+'</p>'
      + '<scr'+'ipt>window.onload=function(){window.print();}</scr'+'ipt></body></html>');
  });

  load();
  refreshTimer = setInterval(load, 15000); // rafraîchissement des demandes en attente
})();
</script>
</body>
</html>
