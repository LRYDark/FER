<?php
require 'src/core/config.php';
require_once 'src/security/csrf.php';

$token = $_GET['token'] ?? '';
$tokenValid = false;

if ($token) {
    // 🔒 [SEC-RESET] Le token est stocké HACHÉ (SHA-256) en base ; on compare le haché.
    $stmt = $pdo->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()');
    $stmt->execute([hash('sha256', $token)]);
    $tokenValid = (bool) $stmt->fetch();
}

$stmt2 = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
$stmt2->execute(['id' => 1]);
$data = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
  <title>Réinitialiser le mot de passe — Forbach en Rose</title>
  <?php include 'src/partials/auth-head.php'; ?>
</head>
<body>
<div class="auth">
  <div class="auth-frame">
    <div class="auth-pane">
      <a class="brand" href="public/accueil">
        <?php if (file_exists(__DIR__ . '/files/_logos/logo_fer_rose.png')): ?>
          <img src="files/_logos/logo_fer_rose.png" alt="" style="height:32px;width:auto">
        <?php endif; ?>
        <span class="name">Forbach en Rose</span>
      </a>
      <div class="inner">
        <div class="oc-icon-area">
          <div class="oc-icon-circle">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
          </div>
          <h1 class="oc-title">R&eacute;initialiser le mot de passe</h1>
          <p class="oc-subtitle">D&eacute;finissez votre nouveau mot de passe</p>
        </div>
    <div class="oc-card">
      <?php if (!$tokenValid): ?>
        <div class="oc-alert oc-alert-danger show">
          Ce lien de reinitialisation est invalide ou a expire (30 minutes).
        </div>
        <a href="login" class="oc-btn">Retour a la connexion</a>
      <?php else: ?>
        <div id="err" class="oc-alert oc-alert-danger"></div>
        <div id="ok" class="oc-alert oc-alert-success"></div>

        <form id="fReset" novalidate>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

          <div class="form-group">
            <label>Nouveau mot de passe</label>
            <input type="password" name="password" id="newPass" placeholder="Min. 14 car., majuscule, chiffre, special" required>
            <div class="pw-checks">
              <div class="pw-check" id="ck-length"><span class="pw-icon">&#9675;</span> 14 caracteres minimum</div>
              <div class="pw-check" id="ck-upper"><span class="pw-icon">&#9675;</span> Une majuscule</div>
              <div class="pw-check" id="ck-digit"><span class="pw-icon">&#9675;</span> Un chiffre</div>
              <div class="pw-check" id="ck-special"><span class="pw-icon">&#9675;</span> Un caractere special</div>
            </div>
          </div>

          <div class="form-group">
            <label>Confirmer le mot de passe</label>
            <input type="password" name="password_confirm" id="confirmPass" placeholder="Confirmez votre mot de passe" required>
            <div class="pw-checks">
              <div class="pw-check" id="ck-match"><span class="pw-icon">&#9675;</span> Les mots de passe correspondent</div>
            </div>
          </div>

          <button type="submit" id="btnSubmit" class="oc-btn" disabled>
            Reinitialiser le mot de passe
          </button>
        </form>
      <?php endif; ?>
    </div>
      </div><!-- /inner -->
    </div><!-- /auth-pane -->
    <?php include 'src/partials/auth-art.php'; ?>
  </div><!-- /auth-frame -->
</div><!-- /auth -->

  <?php if ($tokenValid): ?>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
    var pass = document.getElementById('newPass');
    var conf = document.getElementById('confirmPass');
    var btn  = document.getElementById('btnSubmit');

    function check(id, ok) {
      var el = document.getElementById(id);
      el.classList.toggle('valid', ok);
      el.querySelector('.pw-icon').innerHTML = ok ? '&#10003;' : '&#9675;';
    }

    function validate() {
      var v = pass.value;
      var c = conf.value;
      var ok = {
        length:  v.length >= 14,
        upper:   /[A-Z]/.test(v),
        digit:   /[0-9]/.test(v),
        special: /[^a-zA-Z0-9]/.test(v),
        match:   v.length > 0 && v === c
      };
      check('ck-length',  ok.length);
      check('ck-upper',   ok.upper);
      check('ck-digit',   ok.digit);
      check('ck-special', ok.special);
      check('ck-match',   ok.match);
      btn.disabled = !(ok.length && ok.upper && ok.digit && ok.special && ok.match);
    }

    pass.addEventListener('input', validate);
    conf.addEventListener('input', validate);

    document.getElementById('fReset').addEventListener('submit', function(e) {
      e.preventDefault();
      var errEl = document.getElementById('err');
      var okEl  = document.getElementById('ok');
      errEl.classList.remove('show');
      okEl.classList.remove('show');

      var token = document.querySelector('[name="token"]').value;

      var _csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
      fetch('admin-api.php?route=reset-password-confirm', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
        body: JSON.stringify({ token: token, password: pass.value })
      })
      .then(function(res) { return res.json(); })
      .then(function(j) {
        if (j.ok) {
          okEl.textContent = 'Mot de passe modifie avec succes ! Redirection...';
          okEl.classList.add('show');
          document.getElementById('fReset').style.display = 'none';
          setTimeout(function() { location = 'login.php'; }, 2000);
        } else {
          errEl.textContent = j.err || (j.errors || []).join(' ') || 'Erreur lors de la reinitialisation.';
          errEl.classList.add('show');
        }
      })
      .catch(function() {
        errEl.textContent = 'Erreur de communication avec le serveur.';
        errEl.classList.add('show');
      });
    });
  </script>
  <?php endif; ?>
</body>
</html>
