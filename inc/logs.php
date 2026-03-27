<?php
require '../config/config.php';
require_once __DIR__ . '/../config/csrf.php';
requireRole(['admin']);
$role = currentRole();
require 'navbar-data.php';

// ── Fichiers de logs ────────────────────────────────────────
$logFiles = [
    [
        'name'  => 'Erreurs PHP',
        'key'   => 'php_errors',
        'path'  => __DIR__ . '/../config/logs/php-error.log',
    ],
    [
        'name'  => 'Google Mails',
        'key'   => 'google_mails',
        'path'  => __DIR__ . '/../config/logs/logs_google_mails.txt',
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
    foreach ($logFiles as $lf) {
        if ($lf['key'] === $key && file_exists($lf['path'])) {
            file_put_contents($lf['path'], '');
            $flash = ['type' => 'success', 'msg' => 'Le fichier « ' . $lf['name'] . ' » a été vidé.'];
            break;
        }
    }
}

$stmt = $pdo->prepare('SELECT footer FROM setting WHERE id = 1 LIMIT 1');
$stmt->execute();
$footer = ($stmt->fetch(PDO::FETCH_ASSOC))['footer'] ?? '';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Logs</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
.log-card {
  border-radius: 1.25rem;
  box-shadow: 0 4px 16px rgba(0,0,0,.08);
  overflow: hidden;
  margin-bottom: 1.5rem;
  border: 1px solid #e2e8f0;
}

.log-card-header {
  background: linear-gradient(135deg, #f8fafc, #f1f5f9);
  padding: 1rem 1.5rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #e2e8f0;
}

.log-card-header h5 {
  margin: 0;
  font-weight: 700;
  color: #1e293b;
  font-size: 1rem;
}

.log-meta {
  display: flex;
  align-items: center;
  gap: 1rem;
  font-size: 0.85rem;
  color: #64748b;
}

.log-content {
  background: #16171d;
  color: #e2e4ed;
  font-family: 'Courier New', Consolas, monospace;
  font-size: 0.82rem;
  line-height: 1.6;
  padding: 1.25rem;
  max-height: 500px;
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
  color: #94a3b8;
  background: #f8fafc;
}

.log-empty i {
  font-size: 2.5rem;
  margin-bottom: 0.75rem;
  display: block;
  color: #cbd5e1;
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
  background: rgba(236,72,153,0.1);
  color: #db2777;
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
  color: #1e293b;
  margin: 0;
}

.page-header p {
  color: #64748b;
  margin: 0.25rem 0 0;
}
</style>
</head>

<body>
<?php include 'navbar-admin.php'; ?>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show" role="alert">
      <i class="bi bi-check-circle"></i> <?= htmlspecialchars($flash['msg']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="page-header">
    <h2><i class="bi bi-file-earmark-text"></i> Journaux système</h2>
    <p>Consultez et gérez les fichiers de logs du site.</p>
  </div>

  <?php foreach ($logFiles as $lf): ?>
    <?php
      $exists  = file_exists($lf['path']);
      $content = $exists ? file_get_contents($lf['path']) : '';
      $size    = $exists ? filesize($lf['path']) : 0;
      $lines   = ($content !== '') ? substr_count($content, "\n") + 1 : 0;
      $isEmpty = trim($content) === '';

      // Formater la taille
      if ($size >= 1048576) {
          $sizeStr = round($size / 1048576, 2) . ' Mo';
      } elseif ($size >= 1024) {
          $sizeStr = round($size / 1024, 1) . ' Ko';
      } else {
          $sizeStr = $size . ' octets';
      }
    ?>
    <div class="log-card">
      <div class="log-card-header">
        <div>
          <h5><i class="bi bi-journal-text me-2"></i><?= htmlspecialchars($lf['name']) ?></h5>
          <small class="text-muted"><?= htmlspecialchars(basename($lf['path'])) ?></small>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span class="badge-size"><?= $sizeStr ?></span>
          <span class="badge-lines"><?= $lines ?> ligne<?= $lines > 1 ? 's' : '' ?></span>
          <?php if (!$isEmpty): ?>
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
          // Coloration syntaxique simple
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
  <?php endforeach; ?>

<?php include 'admin-footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
</body>
</html>
