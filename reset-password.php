<?php
require 'config/config.php';

$token = $_GET['token'] ?? '';
$tokenValid = false;

if ($token) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()');
    $stmt->execute([$token]);
    $tokenValid = (bool) $stmt->fetch();
}

$stmt2 = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
$stmt2->execute(['id' => 1]);
$data = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];
$footer  = $data['footer']  ?? '';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reinitialiser le mot de passe – Forbach en Rose</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="css/fer-modern.css" rel="stylesheet">

  <style>
    body {
      min-height: 100vh;
      background: linear-gradient(135deg, #fdf4f8 0%, #ffffff 100%);
    }
    .login-container {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem 1rem;
    }
    .login-card {
      background: white;
      border-radius: 20px;
      box-shadow: 0 20px 60px rgba(236, 72, 153, 0.15);
      max-width: 480px;
      width: 100%;
      overflow: hidden;
    }
    .login-header {
      background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
      padding: 2.5rem 2rem;
      text-align: center;
      color: white;
    }
    .login-header h1 { font-size: 1.5rem; font-weight: 700; margin: 0 0 0.5rem; }
    .login-header p { margin: 0; opacity: 0.95; font-size: 0.9rem; }
    .login-body { padding: 2rem; }
    .form-label { font-weight: 600; color: #334155; font-size: 0.9rem; }
    .form-control {
      border: 2px solid #e2e8f0;
      border-radius: 12px;
      padding: 0.75rem 1rem;
      font-size: 0.95rem;
    }
    .form-control:focus {
      border-color: #ec4899;
      box-shadow: 0 0 0 4px rgba(236, 72, 153, 0.1);
    }
    .btn-login {
      background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
      border: none; border-radius: 12px; padding: 0.875rem 1.5rem;
      font-weight: 600; font-size: 1rem; color: white;
      box-shadow: 0 4px 12px rgba(236, 72, 153, 0.3);
    }
    .btn-login:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(236, 72, 153, 0.4);
      background: linear-gradient(135deg, #db2777 0%, #be185d 100%);
    }
    .btn-login:disabled { opacity: 0.5; transform: none; cursor: not-allowed; }
    .pw-checks { font-size: 0.85rem; }
    .pw-check { padding: 2px 0; color: #94a3b8; transition: color 0.2s; }
    .pw-check.valid { color: #22c55e; }
    .pw-check .pw-icon { margin-right: 6px; }
    .alert { border-radius: 12px; border: none; }
    .login-footer {
      text-align: center;
      padding: 1.5rem;
      color: #64748b;
      font-size: 0.85rem;
      position: relative;
      overflow: hidden;
      isolation: isolate;
    }
    .login-footer .footer-copy {
      position: relative;
      z-index: 1;
    }
    @media (max-width: 575.98px) {
      .login-card { border-radius: 0; box-shadow: none; min-height: 100vh; }
    }
  </style>
</head>
<body>
  <div class="login-container">
    <div class="login-card">

      <div class="login-header">
        <h1>Reinitialiser le mot de passe</h1>
        <p>Definissez votre nouveau mot de passe</p>
      </div>

      <div class="login-body">
        <?php if (!$tokenValid): ?>
          <div class="alert alert-danger">
            Ce lien de reinitialisation est invalide ou a expire (10 minutes).
          </div>
          <a href="login.php" class="btn btn-login w-100 mt-3 text-center text-white text-decoration-none">Retour a la connexion</a>
        <?php else: ?>
          <div id="err" class="alert alert-danger d-none"></div>
          <div id="ok" class="alert alert-success d-none"></div>

          <form id="fReset" novalidate>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <div class="mb-3">
              <label class="form-label">Nouveau mot de passe</label>
              <input type="password" name="password" id="newPass" class="form-control" placeholder="Min. 14 car., majuscule, chiffre, special" required>
              <div class="pw-checks mt-2">
                <div class="pw-check" id="ck-length"><span class="pw-icon">&#9675;</span> 14 caracteres minimum</div>
                <div class="pw-check" id="ck-upper"><span class="pw-icon">&#9675;</span> Une majuscule</div>
                <div class="pw-check" id="ck-digit"><span class="pw-icon">&#9675;</span> Un chiffre</div>
                <div class="pw-check" id="ck-special"><span class="pw-icon">&#9675;</span> Un caractere special</div>
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label">Confirmer le mot de passe</label>
              <input type="password" name="password_confirm" id="confirmPass" class="form-control" placeholder="Confirmez votre mot de passe" required>
              <div class="pw-checks mt-2">
                <div class="pw-check" id="ck-match"><span class="pw-icon">&#9675;</span> Les mots de passe correspondent</div>
              </div>
            </div>

            <button type="submit" id="btnSubmit" class="btn btn-login w-100" disabled>
              Reinitialiser le mot de passe
            </button>
          </form>
        <?php endif; ?>
      </div>

      <div class="login-footer">
        <span class="footer-copy"><?= htmlspecialchars($footer) ?></span>
      </div>
    </div>
  </div>

  <?php if ($tokenValid): ?>
  <script>
    const pass  = document.getElementById('newPass');
    const conf  = document.getElementById('confirmPass');
    const btn   = document.getElementById('btnSubmit');

    function check(id, ok) {
      const el = document.getElementById(id);
      el.classList.toggle('valid', ok);
      el.querySelector('.pw-icon').innerHTML = ok ? '&#10003;' : '&#9675;';
    }

    function validate() {
      const v = pass.value;
      const c = conf.value;
      const ok = {
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

    document.getElementById('fReset').addEventListener('submit', async (e) => {
      e.preventDefault();
      const errEl = document.getElementById('err');
      const okEl  = document.getElementById('ok');
      errEl.classList.add('d-none');
      okEl.classList.add('d-none');

      const token = document.querySelector('[name="token"]').value;

      try {
        const res = await fetch('config/api.php?route=reset-password-confirm', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ token, password: pass.value })
        });
        const j = await res.json();

        if (j.ok) {
          okEl.textContent = 'Mot de passe modifie avec succes ! Redirection...';
          okEl.classList.remove('d-none');
          document.getElementById('fReset').style.display = 'none';
          setTimeout(() => { location = 'login.php'; }, 2000);
        } else {
          errEl.textContent = j.err || (j.errors || []).join(' ') || 'Erreur lors de la reinitialisation.';
          errEl.classList.remove('d-none');
        }
      } catch {
        errEl.textContent = 'Erreur de communication avec le serveur.';
        errEl.classList.remove('d-none');
      }
    });
  </script>
  <?php endif; ?>
</body>
</html>
