<?php require 'src/core/config.php';
require_once 'src/security/csrf.php';

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
$picture= $data['picture'] ?? '';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
  <title>Connexion — Forbach en Rose</title>
  <?php include 'src/partials/auth-head.php'; ?>
</head>
<body>

<div class="auth">
  <div class="auth-frame">
    <div class="auth-pane">
      <a class="brand" href="public/accueil">
        <?php if (file_exists(__DIR__ . '/files/_logos/logo_fer_rose.png')): ?>
          <img src="files/_logos/logo_fer_rose.png" alt="Forbach en Rose" style="height:56px;width:auto">
        <?php else: ?>
          <span class="name">Forbach en Rose</span>
        <?php endif; ?>
      </a>

      <div class="inner">

      <!-- Back link -->
      <a href="public/accueil" class="oc-back" id="backToAccueil">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Retour au site
      </a>

      <!-- Icon area -->
      <div class="oc-icon-area">
        <div class="oc-icon-circle">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
          </svg>
        </div>
        <h1 class="oc-title">Connexion</h1>
        <p class="oc-subtitle">Acc&eacute;dez &agrave; votre espace d'administration</p>
      </div>

      <!-- Étape 1 — Email -->
      <div class="oc-card">
        <div id="err" class="oc-error">
          <span class="oc-error-icon">!</span>
          <span id="errText"></span>
        </div>
        <form id="fLoginEmail" novalidate>
          <div class="oc-form-group">
            <label class="oc-label">Adresse email</label>
            <input name="email" id="emailInput" type="email" class="oc-input" placeholder="Entrez votre adresse email" required autofocus autocomplete="username">
          </div>
          <button type="submit" id="emailNextBtn" class="oc-btn">Suivant</button>
        </form>
        <div class="oc-or"><span>ou</span></div>
        <button type="button" id="btnDirectPasskey" class="oc-btn-passkey">
          <svg viewBox="0 0 24 24"><path d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
          Se connecter avec une cl&eacute; d'acc&egrave;s
        </button>
      </div>

      <!-- Étape 2 — Mot de passe (si aucune méthode forte) -->
      <div id="password-section" style="display:none;">
        <a href="#" class="oc-back" id="backFromPassword">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
          </svg>
          Retour
        </a>
        <div class="oc-icon-area">
          <div class="oc-icon-circle">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
          </div>
          <h1 class="oc-title">Mot de passe</h1>
          <p class="oc-subtitle" id="pwdEmailSubtitle"></p>
        </div>
        <div class="oc-card">
          <div id="pwdErr" class="oc-error"><span class="oc-error-icon">!</span><span id="pwdErrText"></span></div>
          <form id="fLoginPassword" novalidate>
            <input type="text" name="username" id="hiddenUsernamePwd" autocomplete="username" style="display:none" aria-hidden="true" tabindex="-1">
            <div class="oc-form-group">
              <label class="oc-label">Mot de passe</label>
              <input type="password" name="password" id="pwdInput" class="oc-input" placeholder="Entrez votre mot de passe" autocomplete="current-password" required>
            </div>
            <button type="submit" class="oc-btn">Se connecter</button>
          </form>
          <a id="forgotLinkPwd" class="oc-forgot-link">Mot de passe oubli&eacute; ?</a>
          <div id="forgotFormPwd" class="oc-forgot-form">
            <p class="oc-forgot-hint">Entrez votre adresse email pour recevoir un lien de r&eacute;initialisation (valable 10 minutes).</p>
            <div class="oc-forgot-row">
              <input type="email" id="forgotEmailPwd" class="oc-input" placeholder="Votre adresse email">
              <button id="forgotBtnPwd" class="oc-btn">Envoyer</button>
            </div>
            <div id="forgotMsgPwd" class="oc-forgot-msg"></div>
          </div>
        </div>
      </div>

      <!-- ── Sélection de méthode 2FA (si plusieurs méthodes dispo) ── -->
      <div id="method-select-section" style="display:none;">
        <a href="#" class="oc-back" id="backFromMethodSelect">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
          </svg>
          Retour &agrave; la connexion
        </a>
        <div class="oc-icon-area">
          <div class="oc-icon-circle">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
          </div>
          <h1 class="oc-title">Choisissez votre m&eacute;thode</h1>
          <p class="oc-subtitle">S&eacute;lectionnez comment vous souhaitez v&eacute;rifier votre identit&eacute;</p>
        </div>
        <div class="oc-card">
          <div id="methodSelectErr" class="oc-error"><span class="oc-error-icon">!</span><span id="methodSelectErrText"></span></div>
          <div id="methodButtons" class="oc-method-list"></div>
        </div>
      </div>

      <!-- ── Section TOTP ── -->
      <div id="totp-section" style="display:none;">
        <a href="#" class="oc-back" id="backFromTotp">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
          </svg>
          Retour &agrave; la connexion
        </a>
        <div class="oc-icon-area">
          <div class="oc-icon-circle">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
            </svg>
          </div>
          <h1 class="oc-title">Code d'authentification</h1>
          <p class="oc-subtitle">Entrez le code &agrave; 6 chiffres de votre application</p>
        </div>
        <div class="oc-card">
          <div id="totpErr" class="oc-error"><span class="oc-error-icon">!</span><span id="totpErrText"></span></div>
          <form id="fTotp" novalidate>
            <div class="oc-form-group">
              <label class="oc-label">Code de v&eacute;rification</label>
              <input name="code" type="text" class="oc-input" placeholder="000000"
                     maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" inputmode="numeric" required>
            </div>
            <button type="submit" class="oc-btn">V&eacute;rifier le code</button>
          </form>
          <a href="#" id="changeMethodFromTotp" class="oc-back-bottom">Changer de m&eacute;thode</a>
        </div>
      </div>

      <!-- ── Section Passkey ── -->
      <div id="passkey-section" style="display:none;">
        <a href="#" class="oc-back" id="backFromPasskey">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
          </svg>
          Retour &agrave; la connexion
        </a>
        <div class="oc-icon-area">
          <div class="oc-icon-circle">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
            </svg>
          </div>
          <h1 class="oc-title">Cl&eacute; d'acc&egrave;s</h1>
          <p class="oc-subtitle">Utilisez votre empreinte, Windows Hello ou cl&eacute; Apple</p>
        </div>
        <div class="oc-card">
          <div id="passkeyErr" class="oc-error"><span class="oc-error-icon">!</span><span id="passkeyErrText"></span></div>
          <div id="passkeyStatus" class="oc-passkey-status">
            <p style="text-align:center;margin-bottom:20px;color:#6b7280;font-size:14px">Votre appareil vous demandera de vous authentifier</p>
          </div>
          <button id="btnPasskeyAuth" class="oc-btn" type="button">Utiliser ma cl&eacute; d'acc&egrave;s</button>
          <a href="#" id="changeMethodFromPasskey" class="oc-back-bottom">Changer de m&eacute;thode</a>
        </div>
      </div>

      <!-- 2FA verification section (hidden by default) -->
      <div id="twofa-section" style="display:none;">

        <!-- Back to login -->
        <a href="#" class="oc-back" id="backToLogin">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
          </svg>
          Retour &agrave; la connexion
        </a>

        <!-- Icon area -->
        <div class="oc-icon-area">
          <div class="oc-icon-circle">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
          </div>
          <h1 class="oc-title">V&eacute;rification en deux &eacute;tapes</h1>
          <p class="oc-subtitle">Un code &agrave; 6 chiffres a &eacute;t&eacute; envoy&eacute; &agrave; votre adresse email</p>
        </div>

        <!-- 2FA card -->
        <div class="oc-card">
          <!-- Error message -->
          <div id="twofaErr" class="oc-error">
            <span class="oc-error-icon">!</span>
            <span id="twofaErrText"></span>
          </div>

          <form id="fTwofa" novalidate>
            <div class="oc-form-group">
              <label class="oc-label">Code de v&eacute;rification</label>
              <input name="code" type="text" class="oc-input" placeholder="Entrez le code &agrave; 6 chiffres"
                     maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" inputmode="numeric" required autofocus>
            </div>

            <div class="oc-checkbox-group">
              <input type="checkbox" id="trustDevice" name="trust_device">
              <label for="trustDevice">Se souvenir de cet appareil pendant 30 jours</label>
            </div>

            <button type="submit" class="oc-btn">V&eacute;rifier le code</button>
          </form>

          <a id="resendCode" class="oc-resend-link">Renvoyer le code</a>
        </div>

      </div>

      </div><!-- /inner -->
    </div><!-- /auth-pane -->

    <?php include 'src/partials/auth-art.php'; ?>
  </div><!-- /auth-frame -->
</div><!-- /auth -->

  <!-- Scripts -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"
          integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
          crossorigin="anonymous"></script>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
    // CSRF token
    var _csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    function redirectAfterLogin(j) {
      if (j.must_change_password) {
        location = 'change-password.php';
        return;
      }
      switch (j.role) {
        case 'admin':
        case 'user':
        case 'viewer':
          location = 'inc/dashboard.php';
          break;
        case 'saisie':
          location = 'inc/saisie.php';
          break;
        default:
          location = 'inc/dashboard.php';
      }
    }

    // État global 2FA
    var _2faMethods = [];
    var _2faDefault = 'email';
    var _passkeyOptions = null;
    var _loginEmail = '';

    function hideAllSections() {
      ['password-section', 'twofa-section', 'method-select-section', 'totp-section', 'passkey-section'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
      });
    }

    function showLoginSection() {
      hideAllSections();
      document.querySelector('.oc-icon-area').style.display = '';
      document.querySelector('.oc-card').style.display = '';
      document.getElementById('backToAccueil').style.display = '';
      document.getElementById('err').classList.remove('visible');
      var emailInput = document.getElementById('emailInput');
      if (emailInput) { if (_loginEmail) emailInput.value = _loginEmail; emailInput.focus(); }
      var btn = document.getElementById('emailNextBtn');
      if (btn) btn.disabled = false;
    }

    function showPasswordSection(email) {
      hideAllSections();
      document.querySelector('.oc-icon-area').style.display = 'none';
      document.querySelector('.oc-card').style.display = 'none';
      document.getElementById('backToAccueil').style.display = 'none';
      document.getElementById('password-section').style.display = '';
      var sub = document.getElementById('pwdEmailSubtitle');
      if (sub) sub.textContent = email || '';
      var hid = document.getElementById('hiddenUsernamePwd');
      if (hid) hid.value = email || '';
      document.getElementById('pwdErr').classList.remove('visible');
      document.getElementById('pwdInput').value = '';
      document.getElementById('pwdInput').focus();
    }

    function showTwofaSection() {
      hideAllSections();
      document.querySelector('.oc-icon-area').style.display = 'none';
      document.querySelector('.oc-card').style.display = 'none';
      document.getElementById('backToAccueil').style.display = 'none';
      document.getElementById('twofa-section').style.display = '';
      var codeInput = document.querySelector('#fTwofa input[name="code"]');
      if (codeInput) codeInput.focus();
    }

    function showTotpSection() {
      hideAllSections();
      document.querySelector('.oc-icon-area').style.display = 'none';
      document.querySelector('.oc-card').style.display = 'none';
      document.getElementById('backToAccueil').style.display = 'none';
      document.getElementById('totp-section').style.display = '';
      // Toujours proposer le changement de méthode (au minimum le mot de passe est dispo)
      var cm = document.getElementById('changeMethodFromTotp');
      if (cm) cm.style.display = '';
      document.querySelector('#fTotp input[name="code"]').focus();
    }

    function showPasskeySection(options) {
      hideAllSections();
      document.querySelector('.oc-icon-area').style.display = 'none';
      document.querySelector('.oc-card').style.display = 'none';
      document.getElementById('backToAccueil').style.display = 'none';
      document.getElementById('passkey-section').style.display = '';
      // Toujours proposer le changement de méthode (au minimum le mot de passe est dispo)
      var cm = document.getElementById('changeMethodFromPasskey');
      if (cm) cm.style.display = '';
      if (options) _passkeyOptions = options;
    }

    function showMethodSelectSection() {
      hideAllSections();
      document.querySelector('.oc-icon-area').style.display = 'none';
      document.querySelector('.oc-card').style.display = 'none';
      document.getElementById('backToAccueil').style.display = 'none';
      document.getElementById('method-select-section').style.display = '';
      buildMethodButtons();
    }

    function buildMethodButtons() {
      var container = document.getElementById('methodButtons');
      if (!container) return;
      container.innerHTML = '';
      var labels = {
        email:   { label: 'Code par email',       sub: 'Un code à 6 chiffres envoyé à votre adresse email', icon: '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>' },
        totp:    { label: 'Application d\'authentification', sub: 'Code depuis Google Authenticator, Authy, etc.', icon: '<path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>' },
        passkey: { label: 'Clé d\'accès', sub: 'Empreinte digitale, Windows Hello ou clé Apple', icon: '<path d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>' },
      };
      _2faMethods.forEach(function(m) {
        var info = labels[m] || { label: m, sub: '', icon: '' };
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'oc-method-btn';
        btn.dataset.method = m;
        btn.innerHTML =
          '<div class="oc-method-btn-icon"><svg viewBox="0 0 24 24">' + info.icon + '</svg></div>'
          + '<div><div class="oc-method-btn-label">' + info.label + '</div><div class="oc-method-btn-sub">' + info.sub + '</div></div>'
          + (m === _2faDefault ? '<span class="oc-method-default-badge">Défaut</span>' : '');
        btn.addEventListener('click', function() { switchTo2faMethod(m); });
        container.appendChild(btn);
      });
      // Always add password option at end
      var pwdSub = 'Connexion par mot de passe';
      var pwdBtn = document.createElement('button');
      pwdBtn.type = 'button';
      pwdBtn.className = 'oc-method-btn';
      pwdBtn.dataset.method = 'password';
      pwdBtn.innerHTML =
        '<div class="oc-method-btn-icon"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg></div>'
        + '<div><div class="oc-method-btn-label">Mot de passe</div><div class="oc-method-btn-sub">' + pwdSub + '</div></div>';
      pwdBtn.addEventListener('click', function() { switchTo2faMethod('password'); });
      container.appendChild(pwdBtn);
    }

    function switchTo2faMethod(method) {
      document.getElementById('methodSelectErr').classList.remove('visible');
      if (method === 'password') { showPasswordSection(_loginEmail); return; }
      fetch('admin-api.php?route=switch-2fa-method', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
        body: JSON.stringify({ method: method })
      })
      .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); })
      .then(function(res) {
        if (!res.ok || !res.json.ok) {
          document.getElementById('methodSelectErrText').textContent = res.json.err || 'Erreur.';
          document.getElementById('methodSelectErr').classList.add('visible');
          return;
        }
        var j = res.json;
        _2faDefault = method;
        if (j.method === 'email')   { showTwofaSection(); return; }
        if (j.method === 'totp')    { showTotpSection(); return; }
        if (j.method === 'passkey') { showPasskeySection(j.options); return; }
      })
      .catch(function() {
        document.getElementById('methodSelectErrText').textContent = 'Erreur de communication.';
        document.getElementById('methodSelectErr').classList.add('visible');
      });
    }

    function handle2faResponse(j) {
      if (j.method === 'email')   { showTwofaSection(); return; }
      if (j.method === 'totp')    {
        // Méthode forte unique : mémoriser pour permettre le changement de méthode
        if (!_2faMethods.length) _2faMethods = ['totp'];
        showTotpSection(); return;
      }
      if (j.method === 'passkey') {
        if (!_2faMethods.length) _2faMethods = ['passkey'];
        showPasskeySection(j.options); return;
      }
      if (j.method === 'select') {
        _2faMethods = j.methods || [];
        _2faDefault = j.default  || (_2faMethods[0] || 'email');
        if (j.passkey_options) _passkeyOptions = j.passkey_options;
        // Afficher directement la méthode par défaut (la sélection n'est accessible que via "changer")
        switchTo2faMethod(_2faDefault);
        return;
      }
    }

    // ── Étape 1 : email → vérification de la méthode ──
    document.getElementById('fLoginEmail').addEventListener('submit', function(e) {
      e.preventDefault();
      document.getElementById('err').classList.remove('visible');
      var email = document.getElementById('emailInput').value.trim();
      if (!email) return;
      _loginEmail = email;
      var btn = document.getElementById('emailNextBtn');
      btn.disabled = true; btn.textContent = 'Vérification…';
      fetch('admin-api.php?route=login-check-email', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
        body: JSON.stringify({ email: email })
      })
      .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); })
      .then(function(res) {
        btn.disabled = false; btn.textContent = 'Suivant';
        if (!res.ok || !res.json.ok) {
          document.getElementById('errText').textContent = res.json.err || 'Erreur.';
          document.getElementById('err').classList.add('visible');
          return;
        }
        var j = res.json;
        if (j.needs_password) { showPasswordSection(email); return; }
        if (j.requires_2fa)   { handle2faResponse(j); }
      })
      .catch(function() {
        btn.disabled = false; btn.textContent = 'Suivant';
        document.getElementById('errText').textContent = 'Erreur de communication.';
        document.getElementById('err').classList.add('visible');
      });
    });

    // ── Étape 2 : mot de passe ──
    document.getElementById('fLoginPassword').addEventListener('submit', function(e) {
      e.preventDefault();
      document.getElementById('pwdErr').classList.remove('visible');
      var password = document.getElementById('pwdInput').value;
      var btn = this.querySelector('button[type="submit"]');
      btn.disabled = true; btn.textContent = 'Connexion…';
      fetch('admin-api.php?route=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
        body: JSON.stringify({ email: _loginEmail, password: password })
      })
      .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); })
      .then(function(res) {
        btn.textContent = 'Se connecter';
        if (res.json.requires_2fa === true) { handle2faResponse(res.json); return; }
        if (!res.ok || !res.json.ok) {
          document.getElementById('pwdErrText').textContent = res.json.err || 'Identifiants incorrects.';
          document.getElementById('pwdErr').classList.add('visible');
          btn.disabled = false; return;
        }
        redirectAfterLogin(res.json);
      })
      .catch(function() {
        btn.textContent = 'Se connecter'; btn.disabled = false;
        document.getElementById('pwdErrText').textContent = 'Erreur de communication.';
        document.getElementById('pwdErr').classList.add('visible');
      });
    });

    // 2FA form submission
    document.getElementById('fTwofa').addEventListener('submit', function(e) {
      e.preventDefault();
      document.getElementById('twofaErr').classList.remove('visible');

      var code = this.querySelector('input[name="code"]').value.trim();
      var trustDevice = document.getElementById('trustDevice').checked;

      if (!code || code.length !== 6 || !/^[0-9]{6}$/.test(code)) {
        document.getElementById('twofaErrText').textContent = 'Veuillez entrer un code valide \u00e0 6 chiffres.';
        document.getElementById('twofaErr').classList.add('visible');
        return;
      }

      var btn = this.querySelector('button[type="submit"]');
      btn.disabled = true;
      btn.textContent = 'V\u00e9rification...';

      fetch('admin-api.php?route=validate-2fa', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
        body: JSON.stringify({ code: code, trust_device: trustDevice })
      })
      .then(r => r.json().then(j => ({ ok: r.ok, json: j })))
      .then(({ ok, json: j }) => {
        if (!ok || !j.ok) {
          var msg = j.err || 'Code invalide ou expir\u00e9.';
          document.getElementById('twofaErrText').textContent = msg;
          document.getElementById('twofaErr').classList.add('visible');
          btn.disabled = false;
          btn.textContent = 'V\u00e9rifier le code';
          return;
        }
        // Update CSRF token after session_regenerate_id
        if (j.csrf_token) _csrfToken = j.csrf_token;
        redirectAfterLogin(j);
      })
      .catch(function() {
        document.getElementById('twofaErrText').textContent = 'Erreur de communication avec le serveur.';
        document.getElementById('twofaErr').classList.add('visible');
        btn.disabled = false;
        btn.textContent = 'V\u00e9rifier le code';
      });
    });

    // Resend 2FA code (route dédiée, sans mot de passe)
    document.getElementById('resendCode').addEventListener('click', function(e) {
      e.preventDefault();
      document.getElementById('twofaErr').classList.remove('visible');

      this.style.pointerEvents = 'none';
      this.textContent = 'Envoi en cours...';
      var self = this;

      fetch('admin-api.php?route=resend-2fa', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
        body: JSON.stringify({})
      })
      .then(r => r.json())
      .then(function(j) {
        self.style.pointerEvents = '';
        self.textContent = 'Renvoyer le code';
        if (j.ok) {
          document.querySelector('#fTwofa input[name="code"]').value = '';
          document.querySelector('#fTwofa input[name="code"]').focus();
        } else {
          document.getElementById('twofaErrText').textContent = j.err || 'Erreur lors du renvoi.';
          document.getElementById('twofaErr').classList.add('visible');
        }
      })
      .catch(function() {
        self.style.pointerEvents = '';
        self.textContent = 'Renvoyer le code';
        document.getElementById('twofaErrText').textContent = 'Erreur lors du renvoi du code.';
        document.getElementById('twofaErr').classList.add('visible');
      });
    });

    // ── Boutons retour des sections ──
    document.getElementById('backFromPassword').addEventListener('click', function(e) {
      e.preventDefault(); showLoginSection();
    });
    document.getElementById('backFromMethodSelect').addEventListener('click', function(e) {
      e.preventDefault(); showLoginSection();
    });
    document.getElementById('backFromTotp').addEventListener('click', function(e) {
      e.preventDefault(); showLoginSection();
    });
    document.getElementById('changeMethodFromTotp').addEventListener('click', function(e) {
      e.preventDefault();
      showMethodSelectSection();
    });
    document.getElementById('backFromPasskey').addEventListener('click', function(e) {
      e.preventDefault(); showLoginSection();
    });
    document.getElementById('changeMethodFromPasskey').addEventListener('click', function(e) {
      e.preventDefault();
      showMethodSelectSection();
    });
    document.getElementById('backToLogin').addEventListener('click', function(e) {
      e.preventDefault();
      if (_2faMethods.length > 1) showMethodSelectSection(); else showLoginSection();
    });

    // ── TOTP form submission ──
    document.getElementById('fTotp').addEventListener('submit', function(e) {
      e.preventDefault();
      document.getElementById('totpErr').classList.remove('visible');
      var code = this.querySelector('input[name="code"]').value.trim();
      if (!code || code.length !== 6 || !/^[0-9]{6}$/.test(code)) {
        document.getElementById('totpErrText').textContent = 'Code à 6 chiffres requis.';
        document.getElementById('totpErr').classList.add('visible');
        return;
      }
      var btn = this.querySelector('button[type="submit"]');
      btn.disabled = true; btn.textContent = 'Vérification…';
      fetch('admin-api.php?route=validate-totp', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
        body: JSON.stringify({ code: code })
      })
      .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); })
      .then(function(res) {
        if (!res.ok || !res.json.ok) {
          document.getElementById('totpErrText').textContent = res.json.err || 'Code invalide.';
          document.getElementById('totpErr').classList.add('visible');
          btn.disabled = false; btn.textContent = 'Vérifier le code';
          return;
        }
        if (res.json.csrf_token) _csrfToken = res.json.csrf_token;
        redirectAfterLogin(res.json);
      })
      .catch(function() {
        document.getElementById('totpErrText').textContent = 'Erreur de communication.';
        document.getElementById('totpErr').classList.add('visible');
        btn.disabled = false; btn.textContent = 'Vérifier le code';
      });
    });

    // ── Passkey authentication ──
    document.getElementById('btnPasskeyAuth').addEventListener('click', async function() {
      var btn = this;
      document.getElementById('passkeyErr').classList.remove('visible');
      if (!_passkeyOptions) {
        document.getElementById('passkeyErrText').textContent = 'Options de clé d\'accès manquantes. Rechargez la page.';
        document.getElementById('passkeyErr').classList.add('visible');
        return;
      }
      if (!window.PublicKeyCredential) {
        document.getElementById('passkeyErrText').textContent = 'Votre navigateur ne supporte pas les clés d\'accès.';
        document.getElementById('passkeyErr').classList.add('visible');
        return;
      }
      btn.disabled = true; btn.textContent = 'Authentification…';
      try {
        var opts = _passkeyOptions;
        var challenge = Uint8Array.from(atob(opts.challenge.replace(/-/g,'+').replace(/_/g,'/')), c => c.charCodeAt(0));
        var allowCreds = (opts.allowCredentials || []).map(function(c) {
          return { type: c.type, id: Uint8Array.from(atob(c.id.replace(/-/g,'+').replace(/_/g,'/')), x => x.charCodeAt(0)) };
        });
        var credential = await navigator.credentials.get({
          publicKey: {
            challenge: challenge,
            rpId: opts.rpId,
            allowCredentials: allowCreds,
            userVerification: opts.userVerification || 'preferred',
            timeout: opts.timeout || 60000
          }
        });
        function toBase64url(buf) {
          return btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=/g,'');
        }
        var body = {
          credential_id:     toBase64url(credential.rawId),
          clientDataJSON:    toBase64url(credential.response.clientDataJSON),
          authenticatorData: toBase64url(credential.response.authenticatorData),
          signature:         toBase64url(credential.response.signature)
        };
        var r = await fetch('admin-api.php?route=webauthn-auth-verify', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
          body: JSON.stringify(body)
        });
        var j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.err || 'Vérification échouée.');
        if (j.csrf_token) _csrfToken = j.csrf_token;
        redirectAfterLogin(j);
      } catch (err) {
        if (err.name !== 'NotAllowedError') {
          document.getElementById('passkeyErrText').textContent = err.message || 'Authentification par clé d\'accès échouée.';
          document.getElementById('passkeyErr').classList.add('visible');
        }
        btn.disabled = false; btn.textContent = 'Utiliser ma clé d\'accès';
      }
    });

    // ── Passkey DIRECTE (premier écran, sans email — comme MERIDIAN) ──
    document.getElementById('btnDirectPasskey').addEventListener('click', async function() {
      var btn = this;
      var errBox = document.getElementById('err');
      var errText = document.getElementById('errText');
      errBox.classList.remove('visible');
      if (!window.PublicKeyCredential) {
        errText.textContent = 'Votre navigateur ne supporte pas les clés d\'accès.';
        errBox.classList.add('visible');
        return;
      }
      var oldHtml = btn.innerHTML;
      btn.disabled = true; btn.textContent = 'Authentification…';
      function toBase64url(buf) {
        return btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=/g,'');
      }
      try {
        var r0 = await fetch('admin-api.php?route=webauthn-direct-options', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
          body: JSON.stringify({})
        });
        var j0 = await r0.json();
        if (!r0.ok || !j0.ok) throw new Error(j0.err || 'Erreur serveur.');
        var opts = j0.options;
        var challenge = Uint8Array.from(atob(opts.challenge.replace(/-/g,'+').replace(/_/g,'/')), c => c.charCodeAt(0));
        var credential = await navigator.credentials.get({
          publicKey: {
            challenge: challenge,
            rpId: opts.rpId,
            allowCredentials: [],   // clé découvrable : pas besoin d'email
            userVerification: opts.userVerification || 'preferred',
            timeout: opts.timeout || 60000
          }
        });
        var body = {
          credential_id:     toBase64url(credential.rawId),
          clientDataJSON:    toBase64url(credential.response.clientDataJSON),
          authenticatorData: toBase64url(credential.response.authenticatorData),
          signature:         toBase64url(credential.response.signature)
        };
        var r = await fetch('admin-api.php?route=webauthn-direct-verify', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
          body: JSON.stringify(body)
        });
        var j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.err || 'Connexion par clé d\'accès refusée.');
        if (j.csrf_token) _csrfToken = j.csrf_token;
        redirectAfterLogin(j);
        return;
      } catch (err) {
        // NotAllowedError = l'utilisateur a annulé la fenêtre : pas un message d'erreur
        if (err.name !== 'NotAllowedError') {
          errText.textContent = err.message || 'Authentification par clé d\'accès échouée.';
          errBox.classList.add('visible');
        }
        btn.disabled = false; btn.innerHTML = oldHtml;
      }
    });

    // Mot de passe oublie
    document.getElementById('forgotLinkPwd').addEventListener('click', function(e) {
      e.preventDefault();
      var ff = document.getElementById('forgotFormPwd');
      ff.classList.toggle('visible');
      if (ff.classList.contains('visible')) {
        var fe = document.getElementById('forgotEmailPwd');
        if (fe && _loginEmail) fe.value = _loginEmail;
      }
    });

    document.getElementById('forgotBtnPwd').addEventListener('click', async function() {
      const email = document.getElementById('forgotEmailPwd').value.trim();
      const msgEl = document.getElementById('forgotMsgPwd');
      if (!email) { msgEl.innerHTML = '<span class="text-danger">Veuillez entrer votre email.</span>'; return; }
      this.disabled = true; this.textContent = 'Envoi...';
      try {
        await fetch('admin-api.php?route=forgot-password', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
          body: JSON.stringify({ email })
        });
        msgEl.innerHTML = '<span class="text-success">Si un compte existe avec cette adresse, un email de réinitialisation a été envoyé.</span>';
      } catch {
        msgEl.innerHTML = '<span class="text-danger">Erreur de communication avec le serveur.</span>';
      } finally {
        this.disabled = false; this.textContent = 'Envoyer';
      }
    });
  </script>
</body>
</html>
