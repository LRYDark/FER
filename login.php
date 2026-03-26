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
$footer= $data['footer'] ?? '';
$titleColor = $data['title_color'] ?? '#ffffff';
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
      color: #ec4899;
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
      border-color: #ec4899;
      box-shadow: 0 0 0 3px rgba(196,87,122,0.1);
    }

    /* ── Button ── */
    .oc-btn {
      width: 100%;
      height: 36px;
      background: #ec4899;
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
      color: #ec4899;
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
      accent-color: #ec4899;
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
      color: #ec4899;
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
      color: #ec4899;
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
      <a href="public/accueil" class="oc-back">
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

      <!-- Login card -->
      <div class="oc-card">
        <!-- Error message -->
        <div id="err" class="oc-error">
          <span class="oc-error-icon">!</span>
          <span id="errText"></span>
        </div>

        <form id="fLogin" novalidate>
          <div class="oc-form-group">
            <label class="oc-label">Adresse email</label>
            <input name="email" type="email" class="oc-input" placeholder="Entrez votre adresse email" required autofocus>
          </div>

          <div class="oc-form-group">
            <label class="oc-label">Mot de passe</label>
            <input type="password" name="password" class="oc-input" placeholder="Entrez votre mot de passe" required>
          </div>

          <button type="submit" class="oc-btn">Se connecter</button>
        </form>

        <!-- Forgot password link -->
        <a id="forgotLink" class="oc-forgot-link">Mot de passe oubli&eacute; ?</a>

        <!-- Forgot password form -->
        <div id="forgotForm" class="oc-forgot-form">
          <p class="oc-forgot-hint">Entrez votre adresse email pour recevoir un lien de r&eacute;initialisation (valable 10 minutes).</p>
          <div class="oc-forgot-row">
            <input type="email" id="forgotEmail" class="oc-input" placeholder="Votre adresse email">
            <button id="forgotBtn" class="oc-btn">Envoyer</button>
          </div>
          <div id="forgotMsg" class="oc-forgot-msg"></div>
        </div>
      </div>

      <!-- 2FA verification section (hidden by default) -->
      <div id="twofa-section" style="display:none;">

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

      <!-- Footer -->
      <div class="oc-footer">
        <?= htmlspecialchars($footer) ?>
      </div>

    </div>
  </div>

  <!-- Scripts -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script>
    // CSRF token
    var _csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // Store login credentials for potential 2FA resend
    var _loginEmail = '';
    var _loginPassword = '';

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

    function showLoginSection() {
      document.getElementById('twofa-section').style.display = 'none';
      // Show login icon area, card, forgot link
      document.querySelector('.oc-icon-area').style.display = '';
      document.querySelector('.oc-card').style.display = '';
      document.querySelector('.oc-back').style.display = '';
    }

    function showTwofaSection() {
      // Hide login icon area, login card, and back link
      document.querySelector('.oc-icon-area').style.display = 'none';
      document.querySelector('.oc-card').style.display = 'none';
      document.querySelector('.oc-back').style.display = 'none';
      // Show 2FA section
      document.getElementById('twofa-section').style.display = '';
      // Focus the code input
      var codeInput = document.querySelector('#fTwofa input[name="code"]');
      if (codeInput) codeInput.focus();
    }

    $('#fLogin').on('submit', e => {
      e.preventDefault();
      document.getElementById('err').classList.remove('visible');

      var formData = Object.fromEntries(new FormData(e.target));
      _loginEmail = formData.email || '';
      _loginPassword = formData.password || '';

      fetch('config/api.php?route=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
        body: JSON.stringify(formData)
      })
      .then(r => r.json().then(j => ({ ok: r.ok, status: r.status, json: j })))
      .then(({ ok, status, json: j }) => {
          if (!ok || !j.ok) {
            // Check if 2FA is required
            if (j.requires_2fa === true) {
              showTwofaSection();
              return;
            }
            var msg = j.err || 'Identifiants incorrects';
            document.getElementById('errText').textContent = msg;
            document.getElementById('err').classList.add('visible');
            return;
          }
          // Check if 2FA is required (some APIs return ok:true with requires_2fa)
          if (j.requires_2fa === true) {
            showTwofaSection();
            return;
          }
          redirectAfterLogin(j);
      })
      .catch(() => {
        document.getElementById('errText').textContent = 'Identifiants incorrects';
        document.getElementById('err').classList.add('visible');
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
        redirectAfterLogin(j);
      })
      .catch(function() {
        document.getElementById('twofaErrText').textContent = 'Erreur de communication avec le serveur.';
        document.getElementById('twofaErr').classList.add('visible');
        btn.disabled = false;
        btn.textContent = 'V\u00e9rifier le code';
      });
    });

    // Resend 2FA code (re-submits login)
    document.getElementById('resendCode').addEventListener('click', function(e) {
      e.preventDefault();
      document.getElementById('twofaErr').classList.remove('visible');

      if (!_loginEmail || !_loginPassword) {
        showLoginSection();
        return;
      }

      this.style.pointerEvents = 'none';
      this.textContent = 'Envoi en cours...';
      var self = this;

      fetch('config/api.php?route=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
        body: JSON.stringify({ email: _loginEmail, password: _loginPassword })
      })
      .then(r => r.json())
      .then(function(j) {
        self.style.pointerEvents = '';
        self.textContent = 'Renvoyer le code';
        if (j.requires_2fa === true) {
          // Clear previous code input
          document.querySelector('#fTwofa input[name="code"]').value = '';
          document.querySelector('#fTwofa input[name="code"]').focus();
        }
      })
      .catch(function() {
        self.style.pointerEvents = '';
        self.textContent = 'Renvoyer le code';
        document.getElementById('twofaErrText').textContent = 'Erreur lors du renvoi du code.';
        document.getElementById('twofaErr').classList.add('visible');
      });
    });

    // Mot de passe oublie
    document.getElementById('forgotLink').addEventListener('click', function(e) {
      e.preventDefault();
      document.getElementById('forgotForm').classList.toggle('visible');
    });

    document.getElementById('forgotBtn').addEventListener('click', async function() {
      const email = document.getElementById('forgotEmail').value.trim();
      const msgEl = document.getElementById('forgotMsg');
      if (!email) { msgEl.innerHTML = '<span class="text-danger">Veuillez entrer votre email.</span>'; return; }

      this.disabled = true;
      this.textContent = 'Envoi...';

      try {
        const res = await fetch('config/api.php?route=forgot-password', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
          body: JSON.stringify({ email })
        });
        const j = await res.json();
        msgEl.innerHTML = '<span class="text-success">Si un compte existe avec cette adresse, un email de r\u00e9initialisation a \u00e9t\u00e9 envoy\u00e9.</span>';
      } catch {
        msgEl.innerHTML = '<span class="text-danger">Erreur de communication avec le serveur.</span>';
      } finally {
        this.disabled = false;
        this.textContent = 'Envoyer';
      }
    });
  </script>
</body>
</html>
