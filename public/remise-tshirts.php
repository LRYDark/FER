<?php
/**
 * public/remise-tshirts.php — Remise des T-shirts par les bénévoles (sans compte).
 *
 * Flux : le bénévole ouvre cette page (QR ou lien) → saisit son nom → demande
 * l'accès → un admin valide → il scanne un QR / cherche un inscrit → choisit la
 * taille → la remise est enregistrée. La session est gardée sur l'appareil (cookie
 * fer_tshirt) le temps de la campagne (1 semaine), puis auto-off.
 *
 * 🔒 Aucune donnée n'est chargée ici : toute recherche passe par l'API serveur qui
 *    ne renvoie qu'un inscrit à la fois (jamais la base complète).
 */
require '../src/core/config.php';
require_once '../src/security/csrf.php';

// État initial de l'accès (l'ouverture/fermeture est aussi revérifiée côté API).
$open = false;
try {
    $cfg = $pdo->query("SELECT enabled, campaign_token, expires_at FROM tshirt_access WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $open = !empty($cfg['enabled'])
        && !empty($cfg['campaign_token'])
        && (empty($cfg['expires_at']) || strtotime($cfg['expires_at']) >= time());
} catch (\Throwable $e) { $open = false; }

// Un admin connecté disposant du droit « Scanner QR » (dashboard.scan_qr) accède
// directement au scanner (mode centralisé) sans passer par le circuit de validation
// bénévole ni exiger une campagne ouverte.
$isAdminScan = function_exists('canDoAction') && canDoAction('dashboard.scan_qr');
// E-mail du compte admin connecté (affiché en sous-titre à la place d'« Espace bénévoles »).
$adminEmail = '';
if ($isAdminScan) {
    try {
        $uid = function_exists('currentUserId') ? currentUserId() : null;
        if ($uid) {
            $st = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
            $st->execute([$uid]);
            $adminEmail = (string)($st->fetchColumn() ?: '');
        }
    } catch (\Throwable $e) { $adminEmail = ''; }
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Remise T-shirts — Forbach en Rose</title>
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style nonce="<?= $GLOBALS['csp_nonce'] ?>">
  body { background:#faf7f8; }
  .ts-wrap { max-width:560px; margin:0 auto; padding:16px 14px 48px; }
  .ts-head { text-align:center; margin:10px 0 20px; }
  .ts-head h1 { font-size:1.4rem; font-weight:800; color:var(--primary, #f42182); margin:0; }
  .ts-head p { color:#64748b; font-size:.9rem; margin:.25rem 0 0; }
  .ts-card { background:#fff; border:none; border-radius:1rem; box-shadow:0 4px 24px rgba(0,0,0,.07); }
  .qr-size-btn { min-width:64px; font-weight:700; font-size:1.1rem; }
  #qrReader { width:100%; border-radius:.75rem; overflow:hidden; }
  .ts-result-item { cursor:pointer; }
  .ts-hidden { display:none !important; }
</style>
</head>
<body>
<div class="ts-wrap">

  <div class="ts-head">
    <h1><i class="bi bi-tshirt me-2"></i>Remise des T-shirts</h1>
    <p id="tsSubtitle">Espace bénévoles — Forbach en Rose</p>
  </div>

  <!-- ══════ Écran : accès fermé ══════ -->
  <div id="screenClosed" class="ts-card p-4 text-center ts-hidden">
    <i class="bi bi-lock-fill text-secondary" style="font-size:2.4rem"></i>
    <h5 class="mt-3 mb-1">Accès fermé</h5>
    <p class="text-muted mb-0">La remise des T-shirts n'est pas ouverte pour le moment. Rapprochez-vous de l'organisation.</p>
  </div>

  <!-- ══════ Écran : demande d'accès ══════ -->
  <div id="screenRequest" class="ts-card p-4 ts-hidden">
    <div id="reqForm">
      <h5 class="mb-1">Demander l'accès</h5>
      <p class="text-muted small mb-3">Indiquez votre nom : l'organisateur validera votre accès depuis son espace.</p>
      <div id="reqRefused" class="alert alert-warning py-2 small ts-hidden">
        <i class="bi bi-x-circle me-1"></i>Votre demande précédente a été refusée. Vous pouvez en refaire une.
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Nom et prénom du bénévole</label>
        <input type="text" id="volName" class="form-control form-control-lg" placeholder="Ex : Marie Dupont" maxlength="120" autocomplete="name">
      </div>
      <button id="btnRequest" class="btn btn-lg w-100 text-white" style="background:var(--primary, #f42182)">
        <i class="bi bi-send me-1"></i>Demander l'accès
      </button>
    </div>
    <div id="reqWaiting" class="text-center py-3 ts-hidden">
      <div class="spinner-border text-secondary mb-3" role="status"></div>
      <h5 class="mb-1">En attente de validation…</h5>
      <p class="text-muted small mb-0">Votre demande a été envoyée. Cette page s'ouvrira automatiquement dès qu'un organisateur l'aura validée.</p>
    </div>
  </div>

  <!-- ══════ Écran : remise ══════ -->
  <div id="screenHandout" class="ts-hidden">

    <!-- Scanner -->
    <div class="ts-card p-3 mb-3" id="scanCard">
      <div id="qrReader"></div>
      <div class="mt-3">
        <label class="form-label small text-muted mb-1">Ou rechercher (nom, prénom, email ou N° d'inscription) :</label>
        <div class="input-group">
          <button id="camBtn" class="btn btn-outline-secondary" type="button" title="Ouvrir la caméra pour scanner"><i class="bi bi-camera-fill"></i></button>
          <input type="text" id="searchInput" class="form-control" placeholder="Ex : Dupont, marie@…, E21800406" autocomplete="off">
          <button id="searchBtn" class="btn btn-outline-secondary" type="button"><i class="bi bi-search"></i></button>
        </div>
      </div>
      <div id="searchResults" class="list-group mt-2"></div>
    </div>

    <!-- Fiche inscrit -->
    <div id="personCard" class="ts-card p-4 ts-hidden">
      <div id="eligibility" class="text-center mb-3"></div>
      <div class="text-center mb-3">
        <div class="fw-bold fs-4" id="personName"></div>
        <span class="text-muted" id="personNo"></span>
        <span id="personVille" class="badge bg-secondary ms-2"></span>
      </div>
      <div id="alreadyWarn" class="ts-hidden"></div>
      <label class="form-label fw-semibold text-center d-block mb-2">Taille du T-shirt :</label>
      <div class="d-flex flex-wrap gap-2 justify-content-center" id="sizeBtns">
        <button class="btn btn-outline-dark qr-size-btn" data-size="XS">XS</button>
        <button class="btn btn-outline-dark qr-size-btn" data-size="S">S</button>
        <button class="btn btn-outline-dark qr-size-btn" data-size="M">M</button>
        <button class="btn btn-outline-dark qr-size-btn" data-size="L">L</button>
        <button class="btn btn-outline-dark qr-size-btn" data-size="XL">XL</button>
        <button class="btn btn-outline-dark qr-size-btn" data-size="XXL">XXL</button>
      </div>
      <div id="saveStatus" class="mt-3 text-center ts-hidden"></div>
    </div>

    <!-- Carte GROUPE (QR groupé) : tous les inscrits du groupe, une taille chacun -->
    <div id="groupCard" class="ts-card p-4 ts-hidden">
      <div class="fw-bold text-center mb-3" id="groupTitle"></div>
      <div id="groupList"></div>
      <div class="text-center mt-3">
        <button type="button" id="groupDone" class="btn btn-dark"><i class="bi bi-qr-code-scan me-2"></i>Scanner suivant</button>
      </div>
    </div>

    <p class="text-center text-muted small mt-3" id="whoAmI"></p>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  var API = '../admin-api.php';
  var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var INITIAL_OPEN = <?= $open ? 'true' : 'false' ?>;

  var screens = {
    closed:  document.getElementById('screenClosed'),
    request: document.getElementById('screenRequest'),
    handout: document.getElementById('screenHandout')
  };
  function show(name){
    for (var k in screens){ screens[k].classList.toggle('ts-hidden', k !== name); }
  }

  /* ─── Appels API ─── */
  function apiGet(route){
    return fetch(API + '?route=' + route, {headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json());
  }
  function apiSend(route, method, body){
    return fetch(API + '?route=' + route, {
      method: method,
      headers: {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
      body: JSON.stringify(body||{})
    }).then(r=>r.json());
  }

  /* ─── État global ─── */
  var pollTimer = null;
  var scanner = null, scannerRunning = false;
  var currentPerson = null;

  /* ═══ Démarrage : quel écran afficher ? ═══ */
  // Admin connecté (droit dashboard.scan_qr) : accès direct au scanner, sans passer
  // par la demande d'accès bénévole ni l'ouverture de campagne.
  var ADMIN_MODE = <?= $isAdminScan ? 'true' : 'false' ?>;
  var OPERATOR_NAME = <?= json_encode($adminEmail) ?>;
  // Affiche qui opère en sous-titre : e-mail du compte (admin) ou nom du bénévole.
  function setOperator(label){
    var el = document.getElementById('tsSubtitle');
    if (el && label) el.textContent = label;
  }
  function boot(){
    if (ADMIN_MODE){ setOperator(OPERATOR_NAME ? ('Admin — ' + OPERATOR_NAME) : 'Admin connecté'); startHandout(); return; }
    apiGet('tshirt-access-status').then(function(res){
      if (!res || !res.open){ show('closed'); stopPoll(); return; }
      if (res.status === 'approved'){ if (res.operator) setOperator('Bénévole — ' + res.operator); startHandout(); return; }
      if (res.status === 'pending'){ show('request'); enterWaiting(); return; }
      // none / refused / expired → formulaire
      show('request');
      document.getElementById('reqForm').classList.remove('ts-hidden');
      document.getElementById('reqWaiting').classList.add('ts-hidden');
      document.getElementById('reqRefused').classList.toggle('ts-hidden', res.status !== 'refused');
    }).catch(function(){ show(INITIAL_OPEN ? 'request' : 'closed'); });
  }

  /* ═══ Demande d'accès ═══ */
  document.getElementById('btnRequest').addEventListener('click', function(){
    var name = document.getElementById('volName').value.trim();
    if (name.length < 2){ document.getElementById('volName').focus(); return; }
    this.disabled = true;
    apiSend('tshirt-access-request','POST',{name:name}).then(function(res){
      document.getElementById('btnRequest').disabled = false;
      if (res && res.status === 'approved'){ startHandout(); return; }
      if (res && res.status === 'pending'){ enterWaiting(); return; }
      if (res && res.status === 'closed'){ show('closed'); return; }
      alert((res && res.err) ? res.err : 'Erreur, réessayez.');
    }).catch(function(){
      document.getElementById('btnRequest').disabled = false;
      alert('Erreur de communication.');
    });
  });

  function enterWaiting(){
    document.getElementById('reqForm').classList.add('ts-hidden');
    document.getElementById('reqWaiting').classList.remove('ts-hidden');
    startPoll();
  }
  function startPoll(){
    stopPoll();
    pollTimer = setInterval(function(){
      apiGet('tshirt-access-status').then(function(res){
        if (!res || !res.open){ show('closed'); stopPoll(); return; }
        if (res.status === 'approved'){ stopPoll(); if (res.operator) setOperator('Bénévole — ' + res.operator); startHandout(); }
        else if (res.status === 'refused'){
          stopPoll();
          document.getElementById('reqForm').classList.remove('ts-hidden');
          document.getElementById('reqWaiting').classList.add('ts-hidden');
          document.getElementById('reqRefused').classList.remove('ts-hidden');
        }
      });
    }, 4000);
  }
  function stopPoll(){ if (pollTimer){ clearInterval(pollTimer); pollTimer = null; } }

  /* ═══ Interface de remise ═══ */
  var handoutBound = false;   // évite de re-brancher les écouteurs à chaque appel (reprise de veille)
  function startHandout(){
    show('handout');
    showCamera();
    if (handoutBound) return;
    handoutBound = true;
    var si = document.getElementById('searchInput');
    si.addEventListener('input', onSearchInput);
    // Cliquer / entrer dans la barre de recherche ferme la caméra pour libérer la place.
    si.addEventListener('focus', hideCamera);
    document.getElementById('searchBtn').addEventListener('click', function(){ doSearch(document.getElementById('searchInput').value); });
    document.getElementById('camBtn').addEventListener('click', function(){ document.getElementById('searchInput').blur(); showCamera(); });
    document.getElementById('sizeBtns').addEventListener('click', onSizeClick);
    // Groupe : sauvegarde de la taille par membre (changement de select).
    document.getElementById('groupList').addEventListener('change', onGroupSizeChange);
    document.getElementById('groupDone').addEventListener('click', resetToScan);
  }

  function onGroupSizeChange(e){
    var sel = e.target.closest('.ts-grp-size');
    if (!sel) return;
    var row = sel.closest('.ts-grp-row');
    var id  = row && row.dataset.id;
    if (!id) return;
    var st = row.querySelector('.ts-grp-status');
    st.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    apiSend('tshirt-assign','PUT',{id:id, size:sel.value}).then(function(res){
      if (res && res.ok){ st.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>'; }
      else if (res && res.err === 'Accès non validé'){ boot(); }
      else { st.innerHTML = '<i class="bi bi-x-circle-fill text-danger"></i>'; }
    }).catch(function(){ st.innerHTML = '<i class="bi bi-x-circle-fill text-danger"></i>'; });
  }

  var camWrap = document.getElementById('qrReader');

  function startScanner(){
    if (scanner) return;
    try {
      scanner = new Html5Qrcode('qrReader');
      scanner.start({facingMode:'environment'}, {fps:10, qrbox:{width:230,height:230}},
        function(decoded){ var v = (decoded||'').trim(); if (v){ hideCamera(); doSearch(v); } },
        function(){}
      ).then(function(){ scannerRunning = true; })
       .catch(function(){
         scannerRunning = false; scanner = null;
         camWrap.innerHTML =
           '<div class="alert alert-warning text-center py-3 mb-0"><i class="bi bi-camera-video-off d-block mb-2" style="font-size:1.8rem"></i>Caméra indisponible. Utilisez la recherche ci-dessous.</div>';
       });
    } catch(e){ scanner = null; }
  }
  function stopScanner(){
    if (!scanner) return;
    var s = scanner; scanner = null; scannerRunning = false;   // libère la référence tout de suite (évite les courses)
    s.stop().then(function(){ try{ s.clear(); }catch(_){} }).catch(function(){});
  }
  // Redémarre proprement le flux caméra (souvent coupé après une mise en veille du
  // téléphone) : on arrête l'instance en cours PUIS on en relance une neuve. Le garde
  // `restarting` évite une double instance si plusieurs événements de reprise se
  // déclenchent ensemble (visibilitychange + focus + pageshow).
  var restarting = false;
  function restartScanner(){
    if (restarting) return;
    restarting = true;
    var done = function(){ restarting = false; startScanner(); };
    if (scanner){
      var s = scanner; scanner = null; scannerRunning = false;
      s.stop().then(function(){ try{ s.clear(); }catch(_){} done(); }).catch(done);
    } else {
      done();
    }
  }

  // Masque la zone caméra + coupe le flux (libère la place pour la recherche).
  function hideCamera(){ if (camWrap){ camWrap.classList.add('ts-hidden'); } stopScanner(); }
  // Réaffiche la zone caméra + relance le scan.
  function showCamera(){ if (camWrap){ camWrap.classList.remove('ts-hidden'); } startScanner(); }

  var searchDebounce = null;
  function onSearchInput(){
    var q = this.value;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(function(){ if (q.trim().length >= 2) doSearch(q); else clearResults(); }, 350);
  }
  function clearResults(){ document.getElementById('searchResults').innerHTML = ''; }

  function doSearch(q){
    q = (q||'').trim();
    if (q.length < 2) return;
    apiSend('tshirt-lookup','POST',{q:q}).then(function(res){
      if (!res || !res.ok){
        if (res && res.err === 'Accès non validé'){ boot(); return; }
        return;
      }
      var list = res.results || [];
      if (res.group){ clearResults(); showGroup(list); return; } // QR groupé
      if (list.length === 0){ showResults([], q); }
      else if (list.length === 1){ clearResults(); showPerson(list[0]); }
      else { showResults(list, q); }
    });
  }

  var GROUP_SIZES = ['-','XS','S','M','L','XL','XXL'];
  // QR groupé → liste tous les membres, une taille (select) chacun, sauvegarde immédiate.
  function showGroup(members){
    currentPerson = null;
    document.getElementById('personCard').classList.add('ts-hidden');
    var card = document.getElementById('groupCard');
    var listEl = document.getElementById('groupList');
    var title = document.getElementById('groupTitle');
    card.classList.remove('ts-hidden');
    if (!members || !members.length){
      title.innerHTML = '<div class="alert alert-danger py-2 mb-0">Groupe introuvable</div>';
      listEl.innerHTML = '';
      return;
    }
    title.innerHTML = '<i class="bi bi-people-fill me-2"></i>Groupe — ' + members.length + ' inscrit(s)';
    listEl.innerHTML = members.map(function(p){
      var cur = p.tshirt_size || '-';
      var elig = !p.paid ? '<span class="badge bg-danger">Non payé</span>'
               : (p.eligible ? '<span class="badge bg-success">Éligible</span>' : '<span class="badge bg-danger">Non éligible</span>');
      var warn = (cur !== '-') ? '<span class="badge bg-warning text-dark ms-1">déjà : ' + escapeHtml(cur) + '</span>' : '';
      var opts = GROUP_SIZES.map(function(s){ return '<option'+(s===cur?' selected':'')+'>'+s+'</option>'; }).join('');
      return '<div class="ts-grp-row d-flex align-items-center gap-2 border rounded p-2 mb-2" data-id="'+p.id+'">'
           +   '<div class="flex-grow-1" style="min-width:0">'
           +     '<div class="fw-semibold text-truncate">'+escapeHtml((p.prenom||'')+' '+(p.nom||''))+' <span class="text-muted small">N°'+escapeHtml(p.inscription_no)+'</span></div>'
           +     '<div class="small">'+elig+' '+warn+'</div>'
           +   '</div>'
           +   '<select class="form-select form-select-sm ts-grp-size" style="width:90px;flex:0 0 auto">'+opts+'</select>'
           +   '<span class="ts-grp-status" style="width:26px;flex:0 0 auto;text-align:center"></span>'
           + '</div>';
    }).join('');
    card.scrollIntoView({behavior:'smooth', block:'center'});
  }

  function showResults(list, q){
    var box = document.getElementById('searchResults');
    if (list.length === 0){
      box.innerHTML = '<div class="list-group-item text-muted small">Aucun inscrit trouvé pour « ' + escapeHtml(q) + ' »</div>';
      return;
    }
    box.innerHTML = list.map(function(p, i){
      return '<button type="button" class="list-group-item list-group-item-action ts-result-item" data-i="'+i+'">'
        + '<strong>' + escapeHtml(p.prenom + ' ' + p.nom) + '</strong>'
        + ' <span class="text-muted">N°' + escapeHtml(p.inscription_no) + '</span>'
        + (p.ville ? ' <span class="badge bg-light text-dark">' + escapeHtml(p.ville) + '</span>' : '')
        + '</button>';
    }).join('');
    Array.prototype.forEach.call(box.querySelectorAll('.ts-result-item'), function(el){
      el.addEventListener('click', function(){ clearResults(); document.getElementById('searchInput').value=''; showPerson(list[parseInt(this.dataset.i,10)]); });
    });
  }

  function showPerson(p){
    currentPerson = p;
    document.getElementById('personCard').classList.remove('ts-hidden');
    document.getElementById('personName').textContent = (p.prenom||'') + ' ' + (p.nom||'');
    document.getElementById('personNo').textContent = 'N°' + p.inscription_no;
    document.getElementById('personVille').textContent = p.ville || '';
    document.getElementById('personVille').classList.toggle('ts-hidden', !p.ville);

    var elig = document.getElementById('eligibility');
    if (!p.paid){
      elig.innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle-fill me-2"></i><strong>Non éligible T-shirt</strong> — inscription non payée</div>';
    } else if (p.eligible){
      elig.innerHTML = '<div class="alert alert-success py-2 mb-0"><i class="bi bi-check-circle-fill me-2"></i><strong>Éligible T-shirt</strong>' + (p.highlight_limit > 0 ? ' — ' + p.paid_rank + 'ᵉ payant / ' + p.highlight_limit : '') + '</div>';
    } else {
      elig.innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle-fill me-2"></i><strong>Non éligible T-shirt</strong> — ' + p.paid_rank + 'ᵉ payant (limite : ' + p.highlight_limit + ')</div>';
    }

    var warn = document.getElementById('alreadyWarn');
    var cur = p.tshirt_size || '-';
    if (cur !== '-'){
      warn.classList.remove('ts-hidden');
      warn.innerHTML = '<div class="alert alert-warning py-2 mb-3 text-center fw-semibold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Déjà remis (taille ' + escapeHtml(cur) + ')</div>';
    } else {
      warn.classList.add('ts-hidden'); warn.innerHTML = '';
    }

    Array.prototype.forEach.call(document.querySelectorAll('.qr-size-btn'), function(b){
      b.classList.remove('btn-dark','active'); b.classList.add('btn-outline-dark');
      if (b.dataset.size === cur){ b.classList.remove('btn-outline-dark'); b.classList.add('btn-dark','active'); }
    });
    document.getElementById('saveStatus').classList.add('ts-hidden');
    document.getElementById('personCard').scrollIntoView({behavior:'smooth', block:'center'});
  }

  function onSizeClick(e){
    var btn = e.target.closest('.qr-size-btn');
    if (!btn || !currentPerson) return;
    var size = btn.dataset.size;
    Array.prototype.forEach.call(document.querySelectorAll('.qr-size-btn'), function(b){ b.classList.remove('btn-dark','active'); b.classList.add('btn-outline-dark'); });
    btn.classList.remove('btn-outline-dark'); btn.classList.add('btn-dark','active');

    var status = document.getElementById('saveStatus');
    status.classList.remove('ts-hidden');
    status.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Enregistrement…</span>';

    apiSend('tshirt-assign','PUT',{id:currentPerson.id, size:size}).then(function(res){
      if (res && res.ok){
        status.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i><strong>' + size + '</strong> enregistré pour ' + escapeHtml((currentPerson.prenom||'')+' '+(currentPerson.nom||'')) + '</span>';
        setTimeout(resetToScan, 1200);
      } else {
        if (res && res.err === 'Accès non validé'){ boot(); return; }
        status.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Erreur d\'enregistrement</span>';
      }
    }).catch(function(){
      status.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Erreur de communication</span>';
    });
  }

  function resetToScan(){
    currentPerson = null;
    document.getElementById('personCard').classList.add('ts-hidden');
    document.getElementById('groupCard').classList.add('ts-hidden');
    document.getElementById('saveStatus').classList.add('ts-hidden');
    clearResults();
    var si = document.getElementById('searchInput'); si.value = '';
    showCamera();   // prêt à scanner l'inscrit suivant
    window.scrollTo({top:0, behavior:'smooth'});
  }

  function escapeHtml(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  /* ═══ Reprise après veille / retour d'arrière-plan ═══
     Au réveil du téléphone, le flux caméra est souvent coupé et les minuteries gelées.
     Dès que la page redevient visible, on la remet en état opérationnel sans rechargement :
      - écran de remise avec caméra visible → on relance le flux caméra ;
      - autre écran (attente / demande d'accès) → on revérifie l'état d'accès. */
  function onResume(){
    if (document.hidden) return;
    // On relance UNIQUEMENT si l'écran de remise est actif, la caméra visible ET le
    // scanner tournait réellement avant la veille. Sinon on ne touche à rien — sans ce
    // garde, l'ouverture d'un <select> (taille) déclenchait un redémarrage qui le refermait.
    if (screens.handout && !screens.handout.classList.contains('ts-hidden')){
      var camVisible = camWrap && !camWrap.classList.contains('ts-hidden');
      if (camVisible && scannerRunning) restartScanner();
    }
  }
  // Seule la vraie mise en veille (visibilitychange) déclenche la reprise. « focus » se
  // déclenchait aussi à chaque interaction (menu déroulant…) et cassait la saisie.
  document.addEventListener('visibilitychange', onResume);
  window.addEventListener('pageshow', function (e) { if (e.persisted) onResume(); }); // retour bfcache uniquement

  boot();
})();
</script>
</body>
</html>
