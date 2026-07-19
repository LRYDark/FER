<?php
require '../src/core/config.php';
require_once __DIR__ . '/../src/security/csrf.php';
requirePage('logs');
$role = currentRole();
$canWrite     = canDoAction('logs.write');
$pageReadOnly = !$canWrite;
require __DIR__ . '/../src/partials/navbar-data.php';

// Bloquer toute action POST si pas le droit d'écriture
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canWrite) {
    http_response_code(403);
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Action non autorisée (lecture seule).'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ── Fichiers de logs ────────────────────────────────────────
$logFiles = [
    'php_errors' => [
        'name'  => 'Erreurs PHP',
        'key'   => 'php_errors',
        'tab'   => 'php',
        'icon'  => 'bi-bug',
        'path'  => __DIR__ . '/../storage/logs/php-error.log',
    ],
    'google_mails' => [
        'name'  => 'Google Mails',
        'key'   => 'google_mails',
        'tab'   => 'mail',
        'icon'  => 'bi-google',
        'path'  => __DIR__ . '/../storage/logs/logs_google_mails.log',
    ],
    'smtp_mails' => [
        'name'  => 'SMTP Mails',
        'key'   => 'smtp_mails',
        'tab'   => 'smtp',
        'icon'  => 'bi-server',
        'path'  => __DIR__ . '/../storage/logs/logs_smtp_mails.log',
    ],
    'import_errors' => [
        'name'  => "Erreurs d'import",
        'key'   => 'import_errors',
        'tab'   => 'import',
        'icon'  => 'bi-file-earmark-excel',
        'path'  => __DIR__ . '/../storage/logs/import_errors.log',
    ],
    'api' => [
        'name'  => 'API',
        'key'   => 'api',
        'tab'   => 'api',
        'icon'  => 'bi-plug',
        'path'  => __DIR__ . '/../storage/logs/api.log',
    ],
];

// ── Traitement vidage ───────────────────────────────────────
$flash = null;

// ─── CSRF check for all POST actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(403);
    die('Invalid CSRF token');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_log'])) {
    $key = $_POST['clear_log'];
    $redirectTab = 'php';
    foreach ($logFiles as $lf) {
        if ($lf['key'] === $key) {
            $redirectTab = $lf['tab'];
            if (file_exists($lf['path'])) {
                file_put_contents($lf['path'], '');
                addToast('success', 'Le fichier « ' . $lf['name'] . ' » a été vidé.');
            }
            break;
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?log=' . $redirectTab);
    exit;
}

$stmt = $pdo->prepare('SELECT debogage FROM setting WHERE id = 1 LIMIT 1');
$stmt->execute();
$settingRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$debogage = (int) ($settingRow['debogage'] ?? 0);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Logs</title>
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
.log-card {
  border-radius: 1.25rem;
  box-shadow: 0 4px 16px rgba(0,0,0,.08);
  overflow: hidden;
  margin-bottom: 1.5rem;
  border: 1px solid var(--border);
}

.log-card-header {
  background: linear-gradient(135deg, var(--surface-2), var(--surface-2));
  padding: 1rem 1.5rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--border);
}

.log-card-header h5 {
  margin: 0;
  font-weight: 700;
  color: var(--ink);
  font-size: 1rem;
}

.log-meta {
  display: flex;
  align-items: center;
  gap: 1rem;
  font-size: 0.85rem;
  color: var(--ink-dim);
}

.log-tab-section.active { display: flex; flex-direction: column; }
.log-tab-section.active .log-card { display: flex; flex-direction: column; flex: 1; min-height: 0; }

.log-content {
  background: #16171d;
  color: #e2e4ed;
  font-family: 'Courier New', Consolas, monospace;
  font-size: 0.82rem;
  line-height: 1.6;
  padding: 1.25rem;
  flex: 1;
  overflow-y: auto;
  white-space: pre-wrap;
  word-break: break-all;
  margin: 0;
}

.log-content::-webkit-scrollbar {
  width: 8px;
}

.log-content::-webkit-scrollbar-track {
  background: #1e1f28;
  border-radius: 4px;
}

.log-content::-webkit-scrollbar-thumb {
  background: #2e2f3a;
  border-radius: 4px;
}

.log-empty {
  padding: 3rem;
  text-align: center;
  color: var(--ink-faint);
  background: var(--surface-2);
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}

.log-empty i {
  font-size: 2.5rem;
  margin-bottom: 0.75rem;
  display: block;
  color: var(--ink-faint);
}

.btn-clear {
  background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
  border: none;
  border-radius: 10px;
  padding: 0.4rem 1rem;
  font-size: 0.85rem;
  font-weight: 600;
  color: white;
  transition: all 0.2s;
}

.btn-clear:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(239,68,68,0.3);
  color: white;
}

.badge-size {
  background: color-mix(in srgb, var(--primary, #f42182) 10%, transparent);
  color: var(--primary, #f42182);
  padding: 0.3rem 0.6rem;
  border-radius: 8px;
  font-weight: 600;
  font-size: 0.8rem;
}

.badge-lines {
  background: rgba(99,102,241,0.1);
  color: #6366f1;
  padding: 0.3rem 0.6rem;
  border-radius: 8px;
  font-weight: 600;
  font-size: 0.8rem;
}

.log-line-error {
  color: #fca5a5;
}

.log-line-success {
  color: #86efac;
}

.log-line-warning {
  color: #fde68a;
}

.page-header {
  margin-bottom: 1.5rem;
}

.page-header h2 {
  font-weight: 700;
  color: var(--ink);
  margin: 0;
}

.page-header p {
  color: var(--ink-dim);
  margin: 0.25rem 0 0;
}

/* ═══ Tab styles ═══ */
.settings-tabs { border-bottom: 2px solid var(--border); margin-bottom: 24px; gap: 0; }
.settings-tabs .nav-link {
  color: var(--ink); font-weight: 500; font-size: 14px;
  padding: 10px 18px; border: none; border-bottom: 2px solid transparent;
  margin-bottom: -2px; border-radius: 0; background: transparent;
}
.settings-tabs .nav-link:hover { color: var(--ink); border-bottom-color: var(--ink-faint); }
.settings-tabs .nav-link.active {
  color: var(--ink); font-weight: 600;
  border-bottom-color: var(--primary, #f42182); background: transparent;
}
.log-tab-section { display: none; height: calc(90vh - 180px); }
.log-tab-section.active { display: flex; flex-direction: column; }
</style>
</head>

<body>
<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>

  <div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-3">
    <div>
      <h1 class="mb-3 fw-bold"><i class="bi bi-file-earmark-text me-2"></i>Journaux système</h1>
      <p>Consultez et gérez les fichiers de logs du site.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
      <label class="form-label mb-0 fw-bold">Mode débogage</label>
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" id="debogageToggle" <?= $debogage ? 'checked' : '' ?>>
      </div>
      <small id="debogageStatus" class="text-muted"></small>
    </div>
  </div>

  <?php
    $activeLogTab = $_GET['log'] ?? 'php';
    if (!in_array($activeLogTab, ['php', 'mail', 'smtp', 'import', 'api'])) $activeLogTab = 'php';
  ?>

  <!-- Onglets -->
  <ul class="nav settings-tabs" id="logTabs">
    <?php foreach ($logFiles as $lf): ?>
    <li class="nav-item">
      <a class="nav-link <?= $activeLogTab === $lf['tab'] ? 'active' : '' ?>" href="#" data-tab="<?= $lf['tab'] ?>">
        <i class="<?= $lf['icon'] ?> me-1"></i><?= htmlspecialchars($lf['name']) ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>

  <?php foreach ($logFiles as $lf):
      $exists  = file_exists($lf['path']);
      $content = $exists ? file_get_contents($lf['path']) : '';
      $size    = $exists ? filesize($lf['path']) : 0;
      $lines   = ($content !== '') ? substr_count($content, "\n") + 1 : 0;
      $isEmpty = trim($content) === '';

      if ($size >= 1048576) {
          $sizeStr = round($size / 1048576, 2) . ' Mo';
      } elseif ($size >= 1024) {
          $sizeStr = round($size / 1024, 1) . ' Ko';
      } else {
          $sizeStr = $size . ' octets';
      }
  ?>
  <div class="log-tab-section <?= $activeLogTab === $lf['tab'] ? 'active' : '' ?>" id="tab-<?= $lf['tab'] ?>">
    <div class="log-card">
      <div class="log-card-header">
        <div>
          <h5><i class="<?= $lf['icon'] ?> me-2"></i><?= htmlspecialchars($lf['name']) ?></h5>
          <small class="text-muted"><?= htmlspecialchars(basename($lf['path'])) ?></small>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span class="badge-size"><?= $sizeStr ?></span>
          <span class="badge-lines"><?= $lines ?> ligne<?= $lines > 1 ? 's' : '' ?></span>
          <?php if (!$isEmpty && $canWrite): ?>
            <form method="post" class="d-inline" data-confirm="Vider le fichier « <?= htmlspecialchars($lf['name']) ?> » ?">
              <?= csrf_field() ?>
              <input type="hidden" name="clear_log" value="<?= htmlspecialchars($lf['key']) ?>">
              <button type="submit" class="btn btn-clear">
                <i class="bi bi-trash3"></i> Vider
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!$exists): ?>
        <div class="log-empty">
          <i class="bi bi-file-earmark-x"></i>
          Fichier introuvable
        </div>
      <?php elseif ($isEmpty): ?>
        <div class="log-empty">
          <i class="bi bi-check-circle"></i>
          Aucune entrée — le fichier est vide
        </div>
      <?php else: ?>
        <pre class="log-content"><?php
          $htmlLines = [];
          foreach (explode("\n", $content) as $line) {
              $escaped = htmlspecialchars($line);
              if (preg_match('/error|fatal|exception|fail/i', $line)) {
                  $htmlLines[] = '<span class="log-line-error">' . $escaped . '</span>';
              } elseif (preg_match('/✅|success|envoyé|généré|sauvegardé/i', $line)) {
                  $htmlLines[] = '<span class="log-line-success">' . $escaped . '</span>';
              } elseif (preg_match('/warning|warn|expiré|rafraîchi/i', $line)) {
                  $htmlLines[] = '<span class="log-line-warning">' . $escaped . '</span>';
              } else {
                  $htmlLines[] = $escaped;
              }
          }
          echo implode("\n", $htmlLines);
        ?></pre>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

<?php include __DIR__ . '/../src/partials/admin-footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
// Tab switching
document.querySelectorAll('#logTabs .nav-link').forEach(function(tab) {
  tab.addEventListener('click', function(e) {
    e.preventDefault();
    document.querySelectorAll('#logTabs .nav-link').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.log-tab-section').forEach(function(s) { s.classList.remove('active'); });
    this.classList.add('active');
    document.getElementById('tab-' + this.dataset.tab).classList.add('active');
  });
});

// Confirm dialogs
document.addEventListener('submit', function(e) {
  var f = e.target.closest('form[data-confirm]');
  if (f && !confirm(f.dataset.confirm)) e.preventDefault();
});

document.getElementById('debogageToggle').addEventListener('change', function(){
  var val = this.checked ? 1 : 0;
  var status = document.getElementById('debogageStatus');
  status.textContent = 'Sauvegarde...';
  fetch('../admin-api.php?route=toggle-debogage', {
    method: 'POST',
    headers: {'Content-Type':'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
    body: JSON.stringify({debogage: val})
  })
  .then(function(r){ return r.json(); })
  .then(function(j){
    if (j.ok) {
      status.textContent = val ? 'Activé' : 'Désactivé';
      // Recharge la page pour appliquer immédiatement (barre de debug affichée / masquée).
      setTimeout(function(){ window.location.reload(); }, 350);
    } else {
      status.textContent = 'Erreur';
      setTimeout(function(){ status.textContent = ''; }, 2000);
    }
  })
  .catch(function(){ status.textContent = 'Erreur réseau'; });
});
</script>
</body>
</html>
