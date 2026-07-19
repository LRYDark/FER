
<?php include __DIR__ . '/toast.php'; ?>

  </div><!-- /oc-content -->
</div><!-- /jr-shell -->

<?php
// Mode lecture seule au niveau de la page courante :
//   chaque page admin peut définir $pageReadOnly = true; pour signaler qu'on n'a
//   pas le droit d'écrire — admin-footer.php ajoute alors la classe sur <body>
//   et le JS plus bas désactive tous les boutons d'envoi de formulaire.
if (!empty($pageReadOnly)):
?>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">document.body.classList.add('app-readonly');</script>
<?php endif; ?>
<!-- ═══════ ADMIN LAYOUT SCRIPTS ═══════ -->
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
document.addEventListener('DOMContentLoaded', function() {
  // ── Sidebar toggle (mobile) ──
  var burger  = document.getElementById('ocBurger');
  var sidebar = document.getElementById('oc-sidebar');
  var overlay = document.getElementById('ocOverlay');

  function openSidebar()  { sidebar.classList.add('open'); overlay.classList.add('show'); }
  function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }

  if (burger)  burger.addEventListener('click', openSidebar);
  if (overlay) overlay.addEventListener('click', closeSidebar);

  // Close sidebar when clicking a link (mobile)
  document.querySelectorAll('.jr-nav a.item').forEach(function(link) {
    link.addEventListener('click', closeSidebar);
  });

  // ── Accordéon des sections : ouvrir un groupe ferme les autres ──
  document.querySelectorAll('.jr-nav .section-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var group = btn.closest('.nav-group');
      var wasOpen = group.classList.contains('open');
      document.querySelectorAll('.jr-nav .nav-group.open').forEach(function(g) {
        g.classList.remove('open');
      });
      if (!wasOpen) group.classList.add('open');
    });
  });

  // ── User dropdown ──
  var avatarBtn = document.getElementById('ocAvatarBtn');
  var dropdown  = document.getElementById('ocDropdown');

  if (avatarBtn && dropdown) {
    avatarBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      dropdown.classList.toggle('show');
    });
    document.addEventListener('click', function(e) {
      if (!dropdown.contains(e.target) && e.target !== avatarBtn) {
        dropdown.classList.remove('show');
      }
    });
  }

  // ── Logout (entrée du menu + bouton rapide du bloc utilisateur) ──
  function doLogout(e) {
    e.preventDefault();
    e.stopPropagation();
    fetch('../admin-api.php?route=logout').then(function() { location.href = '../login.php'; });
  }
  var logoutLink = document.getElementById('ocLogoutLink');
  if (logoutLink) logoutLink.addEventListener('click', doLogout);
  var logoutQuick = document.getElementById('ocLogoutQuick');
  if (logoutQuick) logoutQuick.addEventListener('click', doLogout);

  // ── Profil ──
  var profileLink = document.getElementById('ocProfileLink');
  if (profileLink) {
    profileLink.addEventListener('click', function(e) {
      e.preventDefault();
      if (dropdown) dropdown.classList.remove('show');
      openProfileModal();
    });
  }

  // ── Mode toggle (dashboard only) ──
  var modeToggle = document.getElementById('ocModeToggle');
  if (modeToggle) {
    modeToggle.addEventListener('click', function(e) {
      e.preventDefault();
      // Toggle the dashboard tshirt mode directly via global variable
      if (typeof tshirtMode !== 'undefined') {
        tshirtMode = !tshirtMode;
        if (typeof refreshButtons === 'function') refreshButtons();
        if (typeof applyTshirtMode === 'function') applyTshirtMode();
        // Mémorise le choix par utilisateur (persiste au rafraîchissement de la page).
        if (typeof saveUiPref === 'function') saveUiPref({ tshirt_mode: tshirtMode });
        // Repli de sécurité si refreshButtons n'a pas mis à jour le libellé.
        var label = modeToggle.querySelector('span');
        if (label) {
          label.textContent = tshirtMode ? 'Mode standard' : 'Remise T-shirts';
        }
      }
      if (dropdown) dropdown.classList.remove('show');
    });
  }
  // ── Confirmation dialogs CSP-compatible (data-confirm) ──
  document.addEventListener('submit', function(e) {
    var form = e.target.closest('form[data-confirm]');
    if (form && !confirm(form.dataset.confirm)) e.preventDefault();
  });
  document.addEventListener('click', function(e) {
    var btn = e.target.closest('button[data-confirm]');
    if (btn && !confirm(btn.dataset.confirm)) e.preventDefault();
  });

  // ── Open profile modal from toast or any data-action="open-profile" ──
  document.addEventListener('click', function(e) {
    var el = e.target.closest('[data-action="open-profile"]');
    if (el) { e.preventDefault(); if (typeof openProfileModal === 'function') openProfileModal(); }
  });

  // ── Generic data-action handlers ──
  document.addEventListener('change', function(e) {
    var el = e.target.closest('[data-action]');
    if (!el) return;
    if (el.dataset.action === 'month-navigate') {
      var v = el.value.split('-');
      window.location.href = '?period=month&y=' + v[0] + '&m=' + v[1];
    }
    if (el.dataset.action === 'preview-new-image' && typeof previewNewImage === 'function') {
      previewNewImage(el, el.dataset.positioner, el.dataset.imgpos);
    }
  });

  // ── Mode lecture seule (viewer) : désactiver tous les boutons d'envoi de formulaire ──
  if (document.body.classList.contains('app-readonly')) {
    document.querySelectorAll('form button[type="submit"], form input[type="submit"]').forEach(function(b) {
      b.disabled = true;
      b.title = 'Lecture seule';
      b.style.opacity = '0.4';
      b.style.cursor = 'not-allowed';
    });
    document.querySelectorAll('form').forEach(function(f) {
      f.addEventListener('submit', function(e) { e.preventDefault(); e.stopPropagation(); });
    });
  }
});
</script>

<!-- ═══════ MODAL PROFIL — JAVASCRIPT ═══════ -->
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function() {
'use strict';

var _pfCsrf = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '';

/* ── Helpers API ── */
function apiPost(route, data) {
  return fetch('../admin-api.php?route=' + route, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _pfCsrf },
    body: JSON.stringify(data)
  }).then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); });
}
function apiGet(route) {
  return fetch('../admin-api.php?route=' + route, { headers: { 'X-CSRF-TOKEN': _pfCsrf } })
    .then(function(r) { return r.json(); });
}
function showMsg(el, msg, type) {
  if (!el) return;
  el.textContent = msg; el.className = 'pf-msg ' + type; el.style.display = '';
  if (type === 'success') setTimeout(function() { el.style.display = 'none'; }, 5000);
}
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function b64urlDecode(s) {
  var pad = s.length % 4; if (pad) s += '==='.slice(0, 4 - pad);
  return atob(s.replace(/-/g,'+').replace(/_/g,'/'));
}
function b64urlEncode(buf) {
  return btoa(String.fromCharCode.apply(null, new Uint8Array(buf))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=/g,'');
}
function strToUint8(s) { return Uint8Array.from(s, function(c) { return c.charCodeAt(0); }); }

/* ════════════════════════════════════════════════════════════════════════
   OUVERTURE / FERMETURE
═════════════════════════════════════════════════════════════════════════ */
var overlay      = document.getElementById('profileModalOverlay');
var _profileData = null;

function openProfileModal() {
  if (!overlay) return;
  overlay.classList.add('open');
  overlay.setAttribute('aria-hidden', 'false');
  switchTab('password');
  loadProfileData();
}
function closeProfileModal() {
  if (!overlay) return;
  overlay.classList.remove('open');
  overlay.setAttribute('aria-hidden', 'true');
  var f = document.getElementById('pfPwdForm');
  if (f) { f.reset(); updatePwdChecks('', ''); }
  showTotpStatusView();
  ['pfPwdMsg','pfTotpMsg','pfTotpSetupMsg','pfPasskeyMsg'].forEach(function(id) {
    var el = document.getElementById(id); if (el) el.style.display = 'none';
  });
}

var pfCloseBtn = document.getElementById('pfClose');
if (pfCloseBtn) pfCloseBtn.addEventListener('click', closeProfileModal);
if (overlay)    overlay.addEventListener('click', function(e) { if (e.target === overlay) closeProfileModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && overlay && overlay.classList.contains('open')) closeProfileModal(); });

/* ════════════════════════════════════════════════════════════════════════
   ONGLETS
═════════════════════════════════════════════════════════════════════════ */
function switchTab(tab) {
  document.querySelectorAll('.pf-tab').forEach(function(btn) {
    var on = btn.dataset.tab === tab;
    btn.classList.toggle('active', on);
    btn.setAttribute('aria-selected', on ? 'true' : 'false');
  });
  document.querySelectorAll('.pf-panel').forEach(function(panel) {
    panel.classList.toggle('active', panel.dataset.panel === tab);
  });
}
document.querySelectorAll('.pf-tab').forEach(function(btn) {
  btn.addEventListener('click', function() { switchTab(btn.dataset.tab); });
});

/* ── Œil mot de passe ── */
document.querySelectorAll('.pf-eye').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var inp = document.getElementById(btn.dataset.target);
    if (inp) inp.type = inp.type === 'password' ? 'text' : 'password';
  });
});

/* ════════════════════════════════════════════════════════════════════════
   CHARGEMENT DONNÉES PROFIL
═════════════════════════════════════════════════════════════════════════ */
function loadProfileData() {
  apiGet('profile-info').then(function(j) {
    if (!j.ok) return;
    _profileData = j;
    updateTotpView(j.totp_enabled);
    renderPasskeys(j.passkeys || []);
    updateDefaultMethodSection(j);
  }).catch(function() {});
}

/* ════════════════════════════════════════════════════════════════════════
   MOT DE PASSE
═════════════════════════════════════════════════════════════════════════ */
function updatePwdChecks(pwd, confirm) {
  var checks = {
    'pfck-len':   pwd.length >= 14,
    'pfck-upper': /[A-Z]/.test(pwd),
    'pfck-digit': /[0-9]/.test(pwd),
    'pfck-spec':  /[^a-zA-Z0-9]/.test(pwd),
    'pfck-match': pwd.length > 0 && pwd === confirm
  };
  var allOk = true;
  Object.keys(checks).forEach(function(id) {
    var li = document.getElementById(id); if (!li) return;
    li.classList.toggle('ok', checks[id]);
    if (!checks[id]) allOk = false;
  });
  var btn = document.getElementById('pfPwdBtn'); if (btn) btn.disabled = !allOk;
}

var pfNewPwd     = document.getElementById('pfNewPwd');
var pfConfirmPwd = document.getElementById('pfConfirmPwd');
if (pfNewPwd)     pfNewPwd.addEventListener('input',     function() { updatePwdChecks(pfNewPwd.value, pfConfirmPwd ? pfConfirmPwd.value : ''); });
if (pfConfirmPwd) pfConfirmPwd.addEventListener('input', function() { updatePwdChecks(pfNewPwd ? pfNewPwd.value : '', pfConfirmPwd.value); });

var pfPwdForm = document.getElementById('pfPwdForm');
if (pfPwdForm) {
  pfPwdForm.addEventListener('submit', function(e) {
    e.preventDefault();
    var msg = document.getElementById('pfPwdMsg');
    var btn = document.getElementById('pfPwdBtn');
    btn.disabled = true; btn.textContent = 'Enregistrement…';
    apiPost('profile-change-password', {
      old_password:     document.getElementById('pfOldPwd').value,
      new_password:     pfNewPwd.value,
      confirm_password: pfConfirmPwd.value
    }).then(function(res) {
      btn.textContent = 'Enregistrer le nouveau mot de passe';
      if (!res.ok || !res.json.ok) {
        var errMsg = res.json.err || (res.json.errors ? res.json.errors.join(' ') : '') || 'Erreur.';
        showMsg(msg, errMsg, 'error'); btn.disabled = false; return;
      }
      showMsg(msg, 'Mot de passe modifié avec succès.', 'success');
      pfPwdForm.reset(); updatePwdChecks('', '');
    }).catch(function() {
      btn.textContent = 'Enregistrer le nouveau mot de passe'; btn.disabled = false;
      showMsg(msg, 'Erreur de communication.', 'error');
    });
  });
}

/* ════════════════════════════════════════════════════════════════════════
   TOTP
═════════════════════════════════════════════════════════════════════════ */
function updateTotpView(enabled) {
  var badge      = document.getElementById('pfTotpBadge');
  var setupBtn   = document.getElementById('pfTotpSetupBtn');
  var disableBtn = document.getElementById('pfTotpDisableBtn');
  if (!badge) return;
  badge.textContent = enabled ? 'Activé' : 'Désactivé';
  badge.className   = 'pf-badge ' + (enabled ? 'pf-badge-on' : 'pf-badge-off');
  if (setupBtn)   setupBtn.style.display   = enabled ? 'none' : '';
  if (disableBtn) disableBtn.style.display = enabled ? '' : 'none';
}

function showTotpStatusView() {
  var s = document.getElementById('pfTotpStatus'); if (s) s.style.display = '';
  var u = document.getElementById('pfTotpSetup');  if (u) u.style.display = 'none';
  var c = document.getElementById('pfTotpCode');   if (c) c.value = '';
  var qr = document.getElementById('pfQrCode');    if (qr) qr.innerHTML = '';
}

var pfTotpSetupBtn = document.getElementById('pfTotpSetupBtn');
if (pfTotpSetupBtn) {
  pfTotpSetupBtn.addEventListener('click', function() {
    var self = this;
    self.disabled = true; self.textContent = 'Chargement…';
    loadQRLib(function() {
      apiPost('profile-setup-totp', {}).then(function(res) {
        self.disabled = false; self.textContent = 'Configurer';
        if (!res.ok || !res.json.ok) {
          showMsg(document.getElementById('pfTotpMsg'), res.json.err || 'Erreur.', 'error'); return;
        }
        document.getElementById('pfTotpStatus').style.display = 'none';
        document.getElementById('pfTotpSetup').style.display  = '';
        document.getElementById('pfTotpSecretDisplay').textContent = res.json.secret;
        var qrEl = document.getElementById('pfQrCode');
        if (qrEl) {
          qrEl.innerHTML = '';
          if (typeof QRCode !== 'undefined') {
            new QRCode(qrEl, { text: res.json.qr_uri, width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M });
          } else {
            qrEl.innerHTML = '<p style="font-size:12px;color:#6b7280;padding:8px">Copiez le secret manuellement.</p>';
          }
        }
        document.getElementById('pfTotpCode').focus();
      }).catch(function() {
        self.disabled = false; self.textContent = 'Configurer';
        showMsg(document.getElementById('pfTotpMsg'), 'Erreur de communication.', 'error');
      });
    });
  });
  pfTotpSetupBtn.addEventListener('mouseenter', function() { loadQRLib(function() {}); }, { once: true });
}

var pfTotpVerifyBtn = document.getElementById('pfTotpVerifyBtn');
if (pfTotpVerifyBtn) {
  pfTotpVerifyBtn.addEventListener('click', function() {
    var code = (document.getElementById('pfTotpCode').value || '').trim();
    var msg  = document.getElementById('pfTotpSetupMsg');
    if (!/^[0-9]{6}$/.test(code)) { showMsg(msg, 'Code à 6 chiffres requis.', 'error'); return; }
    this.disabled = true; this.textContent = 'Vérification…';
    var self = this;
    apiPost('profile-verify-totp', { code: code }).then(function(res) {
      self.textContent = 'Activer';
      if (!res.ok || !res.json.ok) { showMsg(msg, res.json.err || 'Code invalide.', 'error'); self.disabled = false; return; }
      showTotpStatusView();
      if (_profileData) _profileData.totp_enabled = true;
      updateTotpView(true);
      updateDefaultMethodSection(Object.assign({}, _profileData, { totp_enabled: true }));
      showMsg(document.getElementById('pfTotpMsg'), 'Authentificateur activé avec succès.', 'success');
    }).catch(function() { self.textContent = 'Activer'; self.disabled = false; showMsg(document.getElementById('pfTotpSetupMsg'), 'Erreur de communication.', 'error'); });
  });
}

var pfTotpCancelBtn = document.getElementById('pfTotpCancelBtn');
if (pfTotpCancelBtn) pfTotpCancelBtn.addEventListener('click', showTotpStatusView);

var pfTotpDisableBtn = document.getElementById('pfTotpDisableBtn');
if (pfTotpDisableBtn) {
  pfTotpDisableBtn.addEventListener('click', function() {
    if (!confirm('Désactiver l\'authentificateur TOTP ? Vous devrez le reconfigurer pour l\'utiliser à nouveau.')) return;
    var self = this; self.disabled = true;
    apiPost('profile-disable-totp', {}).then(function(res) {
      self.disabled = false;
      if (!res.ok || !res.json.ok) { showMsg(document.getElementById('pfTotpMsg'), res.json.err || 'Erreur.', 'error'); return; }
      if (_profileData) _profileData.totp_enabled = false;
      updateTotpView(false);
      var updated = Object.assign({}, _profileData, { totp_enabled: false });
      updateDefaultMethodSection(updated);
      showMsg(document.getElementById('pfTotpMsg'), 'Authentificateur désactivé.', 'success');
    }).catch(function() { self.disabled = false; showMsg(document.getElementById('pfTotpMsg'), 'Erreur de communication.', 'error'); });
  });
}

/* ════════════════════════════════════════════════════════════════════════
   PASSKEYS
═════════════════════════════════════════════════════════════════════════ */
function renderPasskeys(list) {
  var container = document.getElementById('pfPasskeyList');
  if (!container) return;
  if (!list || list.length === 0) {
    container.innerHTML = '<p class="pf-pk-empty">Aucune clé d\'accès enregistrée.</p>'; return;
  }
  container.innerHTML = '';
  list.forEach(function(pk) {
    var dateStr  = pk.created_at ? new Date(pk.created_at).toLocaleDateString('fr-FR') : '—';
    var lastUsed = pk.last_used   ? new Date(pk.last_used).toLocaleDateString('fr-FR')   : 'jamais';
    var item = document.createElement('div');
    item.className = 'pf-pk-item';
    item.innerHTML =
      '<div class="pf-pk-icon"><svg viewBox="0 0 24 24"><path d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg></div>'
      + '<div class="pf-pk-item-info">'
      + '<span class="pf-pk-name">' + escHtml(pk.name) + '</span>'
      + '<span class="pf-pk-date">Ajoutée le ' + escHtml(dateStr) + ' · Dernière utilisation : ' + escHtml(lastUsed) + '</span>'
      + '</div>'
      + '<button class="pf-pk-delete" title="Supprimer cette clé" data-id="' + escHtml(String(pk.id)) + '">'
      + '<svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg></button>';
    container.appendChild(item);
  });
  container.querySelectorAll('.pf-pk-delete').forEach(function(btn) {
    btn.addEventListener('click', function() {
      if (!confirm('Supprimer cette clé d\'accès ? Cette action est irréversible.')) return;
      deletePasskey(parseInt(this.dataset.id, 10));
    });
  });
}

function deletePasskey(id) {
  var msg = document.getElementById('pfPasskeyMsg');
  apiPost('webauthn-delete-passkey', { id: id }).then(function(res) {
    if (!res.ok || !res.json.ok) { showMsg(msg, res.json.err || 'Erreur suppression.', 'error'); return; }
    loadProfileData();
  }).catch(function() { showMsg(msg, 'Erreur de communication.', 'error'); });
}

var pfAddPasskeyBtn = document.getElementById('pfAddPasskeyBtn');
if (pfAddPasskeyBtn) {
  pfAddPasskeyBtn.addEventListener('click', async function() {
    var msg = document.getElementById('pfPasskeyMsg');
    msg.style.display = 'none';
    if (!window.PublicKeyCredential) { showMsg(msg, 'Votre navigateur ne supporte pas les clés d\'accès.', 'error'); return; }
    this.disabled = true;
    var self = this;
    try {
      var optRes = await apiPost('webauthn-register-options', {});
      if (!optRes.ok || !optRes.json.ok) { showMsg(msg, optRes.json.err || 'Erreur.', 'error'); self.disabled = false; return; }
      var opts = optRes.json.options;
      var credential = await navigator.credentials.create({
        publicKey: {
          challenge:              strToUint8(b64urlDecode(opts.challenge)),
          rp:                     opts.rp,
          user:                   { id: strToUint8(b64urlDecode(opts.user.id)), name: opts.user.name, displayName: opts.user.displayName },
          pubKeyCredParams:       opts.pubKeyCredParams,
          excludeCredentials:     (opts.excludeCredentials || []).map(function(c) { return { type: c.type, id: strToUint8(b64urlDecode(c.id)) }; }),
          authenticatorSelection: opts.authenticatorSelection || {},
          timeout:                opts.timeout || 60000,
          attestation:            opts.attestation || 'none'
        }
      });
      var name = prompt('Nommez cette clé d\'accès (ex : MacBook, iPhone…)', 'Ma clé d\'accès');
      if (name === null) { self.disabled = false; return; }
      name = name.trim() || 'Ma clé d\'accès';
      var verRes = await apiPost('webauthn-register-verify', {
        name: name,
        credential: {
          clientDataJSON:    b64urlEncode(credential.response.clientDataJSON),
          attestationObject: b64urlEncode(credential.response.attestationObject)
        }
      });
      if (!verRes.ok || !verRes.json.ok) { showMsg(msg, verRes.json.err || 'Enregistrement échoué.', 'error'); self.disabled = false; return; }
      showMsg(msg, 'Clé d\'accès ajoutée avec succès.', 'success');
      loadProfileData();
    } catch (err) {
      if (err.name !== 'NotAllowedError') showMsg(msg, err.message || 'Erreur lors de l\'ajout.', 'error');
    }
    self.disabled = false;
  });
}

/* ════════════════════════════════════════════════════════════════════════
   MÉTHODE PAR DÉFAUT
═════════════════════════════════════════════════════════════════════════ */
function updateDefaultMethodSection(data) {
  if (!data) return;
  var section  = document.getElementById('pfDefaultMethodSection');
  var btnsCont = document.getElementById('pfDefaultBtns');
  if (!section || !btnsCont) return;

  var available = [];
  if (data.mail_available)         available.push('email');
  if (data.totp_enabled)           available.push('totp');
  if ((data.passkeys || []).length) available.push('passkey');

  if (available.length < 2) { section.style.display = 'none'; return; }
  section.style.display = '';

  var labels = {
    email:   { label: 'Email',           icon: '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>' },
    totp:    { label: 'Authentificateur', icon: '<path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>' },
    passkey: { label: 'Clé d\'accès',    icon: '<path d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>' }
  };

  btnsCont.innerHTML = '';
  available.forEach(function(m) {
    var btn = document.createElement('button');
    btn.className   = 'pf-default-btn' + (m === data.default_2fa_method ? ' active' : '');
    btn.type        = 'button';
    btn.dataset.method = m;
    btn.innerHTML   = '<svg viewBox="0 0 24 24">' + labels[m].icon + '</svg>' + labels[m].label;
    btn.addEventListener('click', function() {
      apiPost('profile-set-default-2fa', { method: m }).then(function(res) {
        if (!res.ok || !res.json.ok) return;
        if (_profileData) _profileData.default_2fa_method = m;
        btnsCont.querySelectorAll('.pf-default-btn').forEach(function(b) {
          b.classList.toggle('active', b.dataset.method === m);
        });
      });
    });
    btnsCont.appendChild(btn);
  });
}

/* ════════════════════════════════════════════════════════════════════════
   APPARENCE (thème / accent / police par utilisateur — users.ui_prefs)
═════════════════════════════════════════════════════════════════════════ */
var _jrRoseVars = <?= json_encode(jr_accent_vars_from_hex($jrSitePrimary)) ?>;
/* Catalogue complet (même liste que le Thème du site) : nom → {stack, google, custom} */
var _jrFonts = <?= json_encode(array_map(fn($d) => ['stack' => $d[0], 'google' => $d[1], 'custom' => $d[2]], $jrFonts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
var _jrState = {
  accent: <?= json_encode($jrAccent) ?>,
  accentCustom: <?= json_encode($jrPrefs['admin_accent_custom'] ?? '') ?>,
  font: <?= json_encode($jrFont) ?>
};

/* CSS de la police courante : @font-face éventuel (custom) + override body */
function jrFontCss() {
  var name = _jrState.font;
  var f = _jrFonts[name];
  if (!f || name === 'Inter') return '';
  var css = '';
  if (f.custom) {
    var ext = f.custom.split('.').pop().toLowerCase();
    var fmt = { otf: 'opentype', woff2: 'woff2', woff: 'woff', ttf: 'truetype' }[ext] || 'truetype';
    css += '@font-face{font-family:"' + name.replace(/"/g, '') + '";src:url("../' + f.custom + '") format("' + fmt + '");font-display:swap;}\n';
  }
  css += 'body{font-family:' + f.stack + ';}\n';
  return css;
}

/* Dérive les 6 variables d'accent depuis un hex (miroir de jr_accent_vars_from_hex PHP) */
function jrDeriveAccent(hex) {
  if (!/^#[0-9a-fA-F]{6}$/.test(hex)) return null;
  function mix(h, t, to) {
    var r = parseInt(h.substr(1, 2), 16), g = parseInt(h.substr(3, 2), 16), b = parseInt(h.substr(5, 2), 16);
    r = Math.round(r + (to[0] - r) * t); g = Math.round(g + (to[1] - g) * t); b = Math.round(b + (to[2] - b) * t);
    return '#' + [r, g, b].map(function(x) { return x.toString(16).padStart(2, '0'); }).join('');
  }
  function lum(h) {
    var r = parseInt(h.substr(1, 2), 16) / 255, g = parseInt(h.substr(3, 2), 16) / 255, b = parseInt(h.substr(5, 2), 16) / 255;
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  }
  var dark = mix(hex, 0.30, [255, 255, 255]);
  return [hex, mix(hex, 0.14, [0, 0, 0]), lum(hex) > 0.62 ? '#101828' : '#ffffff',
          dark, mix(hex, 0.42, [255, 255, 255]), lum(dark) > 0.62 ? '#0b1030' : '#ffffff'];
}

function jrLiveStyle() {
  var el = document.getElementById('jr-user-vars-live');
  if (!el) { el = document.createElement('style'); el.id = 'jr-user-vars-live'; document.head.appendChild(el); }
  return el;
}

/* Palette des presets (hex de référence, comme tokens.css) → dérivée en JS.
   Mécanisme UNIQUE : on écrit toujours les 6 variables dans le style live,
   plus de bascule data-accent (source du bug « rose affiche bleu »). */
var _jrAccentHex = { blue: '#3D63F0', teal: '#0FA894', violet: '#7C5CF6', emerald: '#10B981' };

function jrAccentVars(name) {
  if (name === 'rose') return _jrRoseVars;
  if (name === 'custom') return jrDeriveAccent(_jrState.accentCustom) || _jrRoseVars;
  return jrDeriveAccent(_jrAccentHex[name] || '#3D63F0');
}

function jrRenderLive() {
  var vars = jrAccentVars(_jrState.accent);
  var css = '';
  if (vars) {
    css += ':root{--accent-l:' + vars[0] + ';--accent-l-strong:' + vars[1] + ';--accent-l-ink:' + vars[2]
        + ';--accent-d:' + vars[3] + ';--accent-d-strong:' + vars[4] + ';--accent-d-ink:' + vars[5] + ';}\n';
  }
  css += jrFontCss();
  jrLiveStyle().textContent = css;
  // Neutraliser le style serveur (sinon il gagnerait sur le style live)
  var srv = document.getElementById('jr-user-vars');
  if (srv) srv.textContent = '';
}

function jrSaveAppearance(patch) {
  // keepalive : la sauvegarde aboutit même si l'utilisateur navigue immédiatement
  // (sinon le POST est annulé par le changement de page et la préférence est perdue).
  fetch('../admin-api.php?route=ui-prefs', {
    method: 'POST',
    keepalive: true,
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _pfCsrf },
    body: JSON.stringify(patch)
  }).then(function(r) { return r.json(); }).then(function(j) {
    if (!j || !j.ok) showMsg(document.getElementById('pfAppearanceMsg'), (j && j.err) || 'Préférence non enregistrée.', 'error');
  }).catch(function() {
    showMsg(document.getElementById('pfAppearanceMsg'), 'Erreur de communication — préférence non enregistrée.', 'error');
  });
}

/* ── Thème clair / sombre / système ── */
var pfThemeSeg = document.getElementById('pfThemeSeg');
if (pfThemeSeg) {
  pfThemeSeg.querySelectorAll('button[data-theme-choice]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var t = btn.dataset.themeChoice;
      document.documentElement.setAttribute('data-theme', t);
      pfThemeSeg.querySelectorAll('button').forEach(function(b) { b.classList.toggle('is-active', b === btn); });
      try { localStorage.setItem('jr-theme', t); } catch (e) {}
      jrSaveAppearance({ admin_theme: t });
    });
  });
}

/* ── Accent ── */
var pfAccentOptions = document.getElementById('pfAccentOptions');
function jrMarkAccent(name) {
  if (!pfAccentOptions) return;
  pfAccentOptions.querySelectorAll('.accent-option').forEach(function(o) {
    o.classList.toggle('is-active', (o.dataset.accentSet || 'custom') === name);
  });
}
function jrApplyAccentChoice(name, customHex) {
  _jrState.accent = name;
  if (name === 'custom' && customHex) _jrState.accentCustom = customHex;
  // Mécanisme unique : data-accent retiré, tout passe par le style live.
  document.documentElement.removeAttribute('data-accent');
  jrRenderLive();
  jrMarkAccent(name);
  // Miroir localStorage pour les pages pré-auth (login) : couleur résolue.
  try {
    var v = jrAccentVars(name);
    if (v) { localStorage.setItem('jr-accent', 'custom'); localStorage.setItem('jr-accent-custom', v[0]); }
  } catch (e) {}
  jrSaveAppearance({ admin_accent: name, admin_accent_custom: _jrState.accentCustom || '' });
}
if (pfAccentOptions) {
  pfAccentOptions.querySelectorAll('button.accent-option').forEach(function(btn) {
    btn.addEventListener('click', function() { jrApplyAccentChoice(btn.dataset.accentSet); });
  });
  var customInput = document.getElementById('pfAccentCustomInput');
  if (customInput) {
    customInput.addEventListener('input',  function() { jrApplyAccentChoice('custom', customInput.value); });
    customInput.addEventListener('change', function() { jrApplyAccentChoice('custom', customInput.value); });
  }
}

/* ── Police : picker avec aperçu (comme Réglages → Thème du site) ── */
function jrEnsureFontLoaded(name) {
  var f = _jrFonts[name];
  if (!f) return;
  var slug = name.replace(/[^a-z0-9]+/gi, '-').toLowerCase();
  if (f.google && !document.getElementById('jr-font-' + slug)) {
    var l = document.createElement('link');
    l.rel = 'stylesheet'; l.id = 'jr-font-' + slug;
    l.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(name).replace(/%20/g, '+') + ':wght@400;500;600;700&display=swap';
    document.head.appendChild(l);
  }
  if (f.custom && !document.getElementById('jr-fontface-' + slug)) {
    var ext = f.custom.split('.').pop().toLowerCase();
    var fmt = { otf: 'opentype', woff2: 'woff2', woff: 'woff', ttf: 'truetype' }[ext] || 'truetype';
    var s = document.createElement('style');
    s.id = 'jr-fontface-' + slug;
    s.textContent = '@font-face{font-family:"' + name.replace(/"/g, '') + '";src:url("../' + f.custom + '") format("' + fmt + '");font-display:swap;}';
    document.head.appendChild(s);
  }
}

function jrSetFont(name) {
  if (!_jrFonts[name]) return;
  _jrState.font = name;
  jrEnsureFontLoaded(name);
  if (_jrState.accent === 'rose' || _jrState.accent === 'custom') {
    jrRenderLive();
  } else {
    jrLiveStyle().textContent = jrFontCss();
    var srv = document.getElementById('jr-user-vars'); if (srv) srv.textContent = '';
  }
  jrSaveAppearance({ admin_font: name });
}

var pfFontToggle   = document.getElementById('pfFontToggle');
var pfFontDropdown = document.getElementById('pfFontDropdown');
var pfFontLabel    = document.getElementById('pfFontLabel');
var _pfFontsPreviewed = false;
if (pfFontToggle && pfFontDropdown) {
  function jrPositionFontDropdown() {
    // position:fixed → on ancre le menu sous (ou sur) le bouton, quel que soit
    // l'overflow du modal. Recalcule largeur/position à chaque ouverture.
    var r = pfFontToggle.getBoundingClientRect();
    var spaceBelow = window.innerHeight - r.bottom;
    pfFontDropdown.style.left = r.left + 'px';
    pfFontDropdown.style.width = r.width + 'px';
    if (spaceBelow < 260 && r.top > 260) {
      // ouvrir vers le haut
      pfFontDropdown.style.top = 'auto';
      pfFontDropdown.style.bottom = (window.innerHeight - r.top + 4) + 'px';
    } else {
      pfFontDropdown.style.bottom = 'auto';
      pfFontDropdown.style.top = (r.bottom + 4) + 'px';
    }
  }
  pfFontToggle.addEventListener('click', function(e) {
    e.stopPropagation();
    var opening = !pfFontDropdown.classList.contains('show');
    if (opening) jrPositionFontDropdown();
    pfFontDropdown.classList.toggle('show');
    if (opening && !_pfFontsPreviewed) {
      // Charger TOUTES les polices une seule fois pour que chaque ligne
      // s'affiche dans sa propre police (aperçu réel, comme le Thème du site)
      _pfFontsPreviewed = true;
      Object.keys(_jrFonts).forEach(jrEnsureFontLoaded);
    }
  });
  document.addEventListener('click', function(e) {
    if (!pfFontDropdown.contains(e.target) && e.target !== pfFontToggle) pfFontDropdown.classList.remove('show');
  });
  pfFontDropdown.querySelectorAll('.pf-font-item').forEach(function(item) {
    item.addEventListener('click', function() {
      var name = item.dataset.value;
      pfFontDropdown.querySelectorAll('.pf-font-item').forEach(function(i) { i.classList.toggle('active', i === item); });
      pfFontDropdown.classList.remove('show');
      if (pfFontLabel) {
        pfFontLabel.textContent = item.textContent;
        pfFontLabel.style.fontFamily = item.style.fontFamily;
      }
      jrSetFont(name);
    });
  });
}

/* ── QRCode lib (lazy) ── */
function loadQRLib(cb) {
  if (typeof QRCode !== 'undefined') { cb(); return; }
  var s = document.createElement('script');
  s.src = 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js';
  s.onload = cb;
  s.onerror = cb;
  document.head.appendChild(s);
}

window.openProfileModal = openProfileModal;
})();
</script>

<?php
/* ── Auto-déconnexion + keep-alive côté client (miroir de l'expiration de session) ──
 * But : quand la session expire, la page se « verrouille » toute seule (redirection
 * vers login) au lieu de laisser l'utilisateur cliquer « Sauvegarder » sur une session
 * déjà morte (perte de saisie). Tant que l'utilisateur est ACTIF, un keep-alive garde
 * la session vivante → pas de déconnexion en pleine saisie. */
$__idleMin = 0; $__absMin = 0;
try {
    $__sr = $pdo->query('SELECT session_lifetime, session_absolute_lifetime FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    $__idleMin = (int) ($__sr['session_lifetime'] ?? 0);
    $__absMin  = (int) ($__sr['session_absolute_lifetime'] ?? 0);
} catch (\Throwable $e) {
    try { $__idleMin = (int) ($pdo->query('SELECT session_lifetime FROM setting WHERE id = 1 LIMIT 1')->fetchColumn() ?: 0); } catch (\Throwable $e2) {}
}
$__loginTime = (int) ($_SESSION['login_time'] ?? time());
if (($__idleMin > 0 || $__absMin > 0) && isset($_SESSION['uid'])):
?>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  var idleMs   = <?= (int) $__idleMin * 60000 ?>;
  var absEndMs = <?= $__absMin > 0 ? ((int) $__loginTime + (int) $__absMin * 60) * 1000 : 0 ?>;
  var loginUrl = '../login.php?msg=expired';
  var idleTimer = null, lastPing = 0;

  function goLogin(){ try { window.location.href = loginUrl; } catch(e){} }

  // Keep-alive : rafraîchit la session serveur tant que l'utilisateur est actif.
  function heartbeat(){
    var now = Date.now();
    var minGap = Math.max(15000, Math.min(60000, idleMs ? idleMs/2 : 60000));
    if (now - lastPing < minGap) return;
    lastPing = now;
    fetch('../admin-api.php?route=heartbeat', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function(r){ return r.json(); })
      .then(function(j){ if (!j || !j.ok) goLogin(); })
      .catch(function(){});
  }

  function onActivity(){
    if (idleMs) {
      if (idleTimer) clearTimeout(idleTimer);
      idleTimer = setTimeout(goLogin, idleMs); // inactif trop longtemps → verrouillage auto
    }
    heartbeat();
  }

  if (idleMs) {
    ['mousemove','mousedown','keydown','scroll','touchstart','click'].forEach(function(ev){
      document.addEventListener(ev, onActivity, { passive: true });
    });
    idleTimer = setTimeout(goLogin, idleMs);
  }

  if (absEndMs) {
    var rem = absEndMs - Date.now();
    if (rem <= 0) goLogin(); else setTimeout(goLogin, rem);
  }
})();
</script>
<?php endif; ?>
