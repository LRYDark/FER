<?php
require 'src/core/config.php';
require_once 'src/security/csrf.php';

// L'utilisateur doit etre connecte
if (!isset($_SESSION['uid'])) {
    header('Location: login');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
$stmt->execute(['id' => 1]);
$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$picture = $data['picture'] ?? '';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
  <title>Changer le mot de passe — Forbach en Rose</title>
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
          <h1 class="oc-title">Changement de mot de passe</h1>
          <p class="oc-subtitle">Vous devez changer votre mot de passe temporaire</p>
        </div>
    <div class="oc-card">
      <div id="err" class="oc-alert oc-alert-danger"></div>
      <div id="ok" class="oc-alert oc-alert-success"></div>

      <form id="fChange" novalidate>
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
          Changer le mot de passe
        </button>
      </form>
    </div>
      </div><!-- /inner -->
    </div><!-- /auth-pane -->
    <?php include 'src/partials/auth-art.php'; ?>
  </div><!-- /auth-frame -->
</div><!-- /auth -->

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

    document.getElementById('fChange').addEventListener('submit', function(e) {
      e.preventDefault();
      var errEl = document.getElementById('err');
      var okEl  = document.getElementById('ok');
      errEl.classList.remove('show');
      okEl.classList.remove('show');

      var _csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
      fetch('admin-api.php?route=change-password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
        body: JSON.stringify({ password: pass.value })
      })
      .then(function(res) { return res.json(); })
      .then(function(j) {
        if (j.ok) {
          okEl.textContent = 'Mot de passe modifie avec succes ! Redirection...';
          okEl.classList.add('show');
          setTimeout(function() { location = 'login.php'; }, 2000);
        } else {
          errEl.textContent = (j.errors || []).join(' ') || 'Erreur lors du changement.';
          errEl.classList.add('show');
        }
      })
      .catch(function() {
        errEl.textContent = 'Erreur de communication avec le serveur.';
        errEl.classList.add('show');
      });
    });
  </script>
</body>
</html>
