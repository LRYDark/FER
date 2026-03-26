<?php
require 'config/config.php';
require_once 'config/csrf.php';

// L'utilisateur doit etre connecte
if (!isset($_SESSION['uid'])) {
    header('Location: login');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
$stmt->execute(['id' => 1]);
$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$picture = $data['picture'] ?? '';
$footer  = $data['footer']  ?? '';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
  <title>Changer le mot de passe</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100vh; overflow: hidden; background: #4a2038; font-family: 'Inter', system-ui, -apple-system, sans-serif; font-size: 14px; color: #191C1D; }
    .oc-topbar { height: 52px; margin: 6px 0; padding: 0 16px; display: flex; align-items: center; background: #4a2038; }
    .oc-topbar h1 { color: #fff; font-size: 16px; font-weight: 700; }
    .oc-body { background: #fff; border-radius: 12px; margin: 0 6px 6px 6px; height: calc(100vh - 70px); display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 20px; overflow: auto; }
    .oc-logo { text-align: center; margin-bottom: 24px; }
    .oc-logo .logo-icon { width: 56px; height: 56px; border-radius: 50%; background: #fdf2f8; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; color: #c4577a; font-size: 24px; }
    .oc-logo h2 { font-size: 22px; font-weight: 700; color: #191C1D; }
    .oc-logo p { color: #5f4b52; font-size: 14px; margin-top: 4px; }
    .oc-card { background: #fff; border: 1px solid #f0e8eb; border-radius: 12px; box-shadow: 0 8px 24px rgba(74,32,56,.08); padding: 32px; width: 100%; max-width: 440px; }
    .form-group { margin-bottom: 14px; }
    .form-group label { display: block; font-size: 13px; font-weight: 600; color: #5f4b52; margin-bottom: 4px; }
    .form-group input { width: 100%; height: 40px; padding: 8px 12px; border: 1px solid #d4c4cb; border-radius: 6px; font-family: 'Inter', system-ui, sans-serif; font-size: 14px; color: #191C1D; background: #fff; outline: none; }
    .form-group input:focus { border-color: #c4577a; outline: none; box-shadow: 0 0 0 3px rgba(196,87,122,.12); }
    .oc-btn { width: 100%; padding: 10px; background: #c4577a; color: #fff; border: none; border-radius: 6px; font-size: 14px; font-weight: 700; font-family: 'Inter', system-ui, sans-serif; cursor: pointer; margin-top: 8px; transition: background 0.15s; }
    .oc-btn:hover { background: #a84565; }
    .oc-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .pw-checks { margin-top: 8px; }
    .pw-check { padding: 2px 0; color: #9e8a92; font-size: 13px; transition: color 0.2s; }
    .pw-check.valid { color: #059669; }
    .pw-check .pw-icon { margin-right: 6px; }
    .oc-alert { padding: 10px 14px; border-radius: 6px; font-size: 13px; font-weight: 500; margin-bottom: 14px; display: none; }
    .oc-alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .oc-alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
    .oc-alert.show { display: block; }
    .oc-footer { text-align: center; margin-top: 16px; font-size: 12px; color: #9e8a92; }
  </style>
</head>
<body>
  <header class="oc-topbar"><h1>Forbach en Rose</h1></header>
  <main class="oc-body">
    <div class="oc-logo">
      <?php if (!empty($picture)): ?>
        <div class="logo-icon">
          <img src="files/_pictures/<?= htmlspecialchars($picture) ?>" alt="Logo" style="max-width:32px;max-height:32px;object-fit:contain;">
        </div>
      <?php else: ?>
        <div class="logo-icon">&#128274;</div>
      <?php endif; ?>
      <h2>Changement de mot de passe</h2>
      <p>Vous devez changer votre mot de passe temporaire</p>
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
    <div class="oc-footer"><?= htmlspecialchars($footer) ?></div>
  </main>

  <script>
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
      fetch('config/api.php?route=change-password', {
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
