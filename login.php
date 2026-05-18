<?php require 'config/config.php';
require_once 'config/csrf.php';

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
  <title>Connexion</title>

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      background: #0f172a;
      overflow: hidden;
      height: 100vh;
    }

    /* ── Topbar ── */
    .oc-topbar {
      height: 52px;
      background: #0f172a;
      margin: 6px 0;
      display: flex;
      align-items: center;
      padding: 0 20px;
    }

    .oc-topbar-title {
      color: #fff;
      font-size: 15px;
      font-weight: 700;
      letter-spacing: 0.3px;
    }

    /* ── Main area ── */
    .oc-main {
      background: #fff;
      border-radius: 12px;
      margin: 0 6px 6px 6px;
      height: calc(100vh - 70px);
      display: flex;
      align-items: center;
      justify-content: center;
      overflow-y: auto;
    }

    /* ── Login wrapper ── */
    .oc-login-wrapper {
      width: 100%;
      max-width: 400px;
      padding: 32px 24px;
    }

    /* ── Icon area ── */
    .oc-icon-area {
      text-align: center;
      margin-bottom: 24px;
    }

    .oc-icon-circle {
      width: 56px;
      height: 56px;
      border-radius: 50%;
      background: #fdf2f8;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 16px;
    }

    .oc-icon-circle svg {
      width: 28px;
      height: 28px;
      color: #F42182;
    }

    .oc-title {
      font-size: 20px;
      font-weight: 700;
      color: #1a1a2e;
      margin-bottom: 4px;
    }

    .oc-subtitle {
      font-size: 13px;
      color: #71717a;
    }

    /* ── Login card ── */
    .oc-card {
      background: #fff;
      border: 1px solid #f0e8eb;
      border-radius: 12px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
      padding: 32px;
    }

    /* ── Form elements ── */
    .oc-form-group {
      margin-bottom: 16px;
    }

    .oc-form-group:last-of-type {
      margin-bottom: 20px;
    }

    .oc-label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      color: #374151;
      margin-bottom: 6px;
    }

    .oc-input {
      width: 100%;
      height: 36px;
      border: 1px solid #d4c4cb;
      border-radius: 4px;
      padding: 0 10px;
      font-size: 13px;
      font-family: inherit;
      color: #1a1a2e;
      background: #fff;
      transition: border-color 0.15s, box-shadow 0.15s;
      outline: none;
    }

    .oc-input::placeholder {
      color: #a1a1aa;
    }

    .oc-input:focus {
      border-color: #F42182;
      box-shadow: 0 0 0 3px rgba(196,87,122,0.1);
    }

    /* ── Button ── */
    .oc-btn {
      width: 100%;
      height: 36px;
      background: #F42182;
      color: #fff;
      border: none;
      border-radius: 4px;
      font-size: 13px;
      font-weight: 700;
      font-family: inherit;
      cursor: pointer;
      transition: background 0.15s;
    }

    .oc-btn:hover {
      background: #a8476a;
    }

    .oc-btn:active {
      background: #933d5c;
    }

    /* ── Error message ── */
    .oc-error {
      border: 1px solid #BA1A1A;
      background: transparent;
      border-radius: 4px;
      padding: 10px 12px;
      margin-bottom: 16px;
      display: none;
      align-items: flex-start;
      gap: 8px;
      font-size: 13px;
      color: #BA1A1A;
    }

    .oc-error.visible {
      display: flex;
    }

    .oc-error-icon {
      flex-shrink: 0;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      background: #BA1A1A;
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      font-weight: 700;
      line-height: 1;
    }

    /* ── Forgot password link ── */
    .oc-forgot-link {
      display: block;
      text-align: center;
      margin-top: 16px;
      color: #F42182;
      font-size: 13px;
      text-decoration: none;
      cursor: pointer;
    }

    .oc-forgot-link:hover {
      text-decoration: underline;
    }

    /* ── Forgot form ── */
    .oc-forgot-form {
      display: none;
      margin-top: 16px;
      padding-top: 16px;
      border-top: 1px solid #f0e8eb;
    }

    .oc-forgot-form.visible {
      display: block;
    }

    .oc-forgot-hint {
      font-size: 12px;
      color: #71717a;
      margin-bottom: 10px;
    }

    .oc-forgot-row {
      display: flex;
      gap: 8px;
    }

    .oc-forgot-row .oc-input {
      flex: 1;
    }

    .oc-forgot-row .oc-btn {
      width: auto;
      padding: 0 16px;
      white-space: nowrap;
    }

    .oc-forgot-msg {
      margin-top: 8px;
      font-size: 12px;
    }

    .oc-forgot-msg .text-success { color: #16a34a; }
    .oc-forgot-msg .text-danger { color: #BA1A1A; }

    /* ── 2FA checkbox ── */
    .oc-checkbox-group {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 20px;
    }

    .oc-checkbox-group input[type="checkbox"] {
      width: 16px;
      height: 16px;
      accent-color: #F42182;
      cursor: pointer;
    }

    .oc-checkbox-group label {
      font-size: 13px;
      color: #374151;
      cursor: pointer;
    }

    /* ── 2FA resend link ── */
    .oc-resend-link {
      display: block;
      text-align: center;
      margin-top: 12px;
      color: #F42182;
      font-size: 13px;
      text-decoration: none;
      cursor: pointer;
    }

    .oc-resend-link:hover {
      text-decoration: underline;
    }

    /* ── Footer ── */
    .oc-footer {
      text-align: center;
      margin-top: 20px;
      font-size: 12px;
      color: #a1a1aa;
    }

    /* ── Back link ── */
    .oc-back {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      color: #F42182;
      text-decoration: none;
      font-size: 13px;
      margin-bottom: 20px;
    }

    .oc-back:hover {
      text-decoration: underline;
    }

    .oc-back svg {
      width: 16px;
      height: 16px;
    }

    /* ── Back link (bottom of card) ── */
    .oc-back-bottom {
      display: block;
      text-align: center;
      margin-top: 16px;
      color: #F42182;
      font-size: 13px;
      text-decoration: none;
      cursor: pointer;
    }

    .oc-back-bottom:hover {
      text-decoration: underline;
    }

    /* ── Sélection de méthode ── */
    .oc-method-list { display: flex; flex-direction: column; gap: 10px; }
    .oc-method-btn {
      display: flex; align-items: center; gap: 14px;
      padding: 14px 16px; border-radius: 10px;
      background: #f8fafc; border: 1.5px solid #e2e8f0;
      cursor: pointer; transition: all .15s; text-align: left;
    }
    .oc-method-btn:hover { border-color: #db2777; background: rgba(219,39,119,.05); }
    .oc-method-btn-icon {
      width: 40px; height: 40px; border-radius: 10px;
      background: rgba(219,39,119,.1); display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .oc-method-btn-icon svg { width: 20px; height: 20px; stroke: #db2777; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .oc-method-btn-label { font-size: 15px; font-weight: 600; color: #111827; }
    .oc-method-btn-sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
    .oc-method-default-badge { margin-left: auto; font-size: 11px; font-weight: 600; background: #db2777; color: #fff; padding: 2px 8px; border-radius: 20px; }

    /* ── Responsive ── */
    @media (max-width: 480px) {
      .oc-topbar { padding: 0 12px; }
      .oc-main { margin: 0 4px 4px 4px; border-radius: 10px; height: calc(100vh - 66px); }
      .oc-login-wrapper { padding: 24px 16px; }
      .oc-card { padding: 24px 20px; }
    }
  </style>
</head>
<body>

  <!-- Topbar -->
  <div class="oc-topbar">
    <span class="oc-topbar-title">Forbach en Rose</span>
  </div>

  <!-- Main area -->
  <div class="oc-main">
    <div class="oc-login-wrapper">

      <!-- Back link -->
      <a href="public/accueil" class="oc-back" id="backToAccueil">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Retour
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


    </div>
  </div>

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
      fetch('config/api.php?route=switch-2fa-method', {
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
      fetch('config/api.php?route=login-check-email', {
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
      fetch('config/api.php?route=login', {
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

      fetch('config/api.php?route=validate-2fa', {
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

      fetch('config/api.php?route=resend-2fa', {
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
      fetch('config/api.php?route=validate-totp', {
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
        var r = await fetch('config/api.php?route=webauthn-auth-verify', {
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
        await fetch('config/api.php?route=forgot-password', {
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
