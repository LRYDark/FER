<?php
require '../src/core/config.php';
require_once __DIR__ . '/../src/security/csrf.php';
requirePage('connexions');
$role = currentRole();
$canWrite     = canDoAction('connexions.write');
$readOnly     = !$canWrite; // pour rétro-compatibilité avec les conditions HTML existantes
$pageReadOnly = !$canWrite;
require __DIR__ . '/../src/partials/navbar-data.php';

// Bloquer toute action POST si pas le droit d'écriture
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canWrite) {
    http_response_code(403);
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Action non autorisée (lecture seule).'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ── Migration checks ────────────────────────────────────────
$logsAvailable = false;
try { $pdo->query("SELECT 1 FROM login_logs LIMIT 0"); $logsAvailable = true; } catch (\Throwable $e) {}
$bansAvailable = false;
try { $pdo->query("SELECT 1 FROM login_banned_ips LIMIT 0"); $bansAvailable = true; } catch (\Throwable $e) {}
$devicesAvailable = false;
try { $pdo->query("SELECT 1 FROM trusted_devices LIMIT 0"); $devicesAvailable = true; } catch (\Throwable $e) {}

// ── Handle POST actions ─────────────────────────────────────
$flash = null;

// ─── CSRF check for all POST actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(403);
    die('Invalid CSRF token');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $bansAvailable) {
    if (isset($_POST['ban_ip'])) {
        $ip = trim($_POST['ip'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        if ($ip !== '') {
            $stmt = $pdo->prepare("INSERT INTO login_banned_ips (ip, reason, banned_at) VALUES (?, ?, NOW())");
            $stmt->execute([$ip, $reason]);
            $flash = ['type' => 'success', 'msg' => "L'adresse IP <strong>" . htmlspecialchars($ip) . "</strong> a été bannie."];
        }
    }
    if (isset($_POST['unban_ip'])) {
        $id = (int)($_POST['ban_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM login_banned_ips WHERE id = ?");
            $stmt->execute([$id]);
            $flash = ['type' => 'success', 'msg' => "L'adresse IP a été debannie."];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $devicesAvailable) {
    if (isset($_POST['revoke_device'])) {
        $id = (int)($_POST['device_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM trusted_devices WHERE id = ?");
            $stmt->execute([$id]);
            $flash = ['type' => 'success', 'msg' => "L'appareil de confiance a été révoqué."];
        }
    }
    if (isset($_POST['revoke_all_devices'])) {
        $pdo->exec("DELETE FROM trusted_devices");
        $flash = ['type' => 'success', 'msg' => "Tous les appareils de confiance ont été révoqués."];
    }
}

// Redirect after POST to avoid resubmission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tab = 'connexions';
    if (isset($_POST['ban_ip']) || isset($_POST['unban_ip'])) $tab = 'bans';
    if (isset($_POST['revoke_device']) || isset($_POST['revoke_all_devices'])) $tab = 'devices';
    // Store flash in session
    if ($flash) { $_SESSION['connexions_flash'] = $flash; }
    header("Location: connexions.php?tab=" . $tab);
    exit;
}

// Retrieve flash from session
if (isset($_SESSION['connexions_flash'])) {
    $flash = $_SESSION['connexions_flash'];
    unset($_SESSION['connexions_flash']);
}

// ── Fetch data ──────────────────────────────────────────────
$logs    = $logsAvailable    ? $pdo->query("SELECT * FROM login_logs ORDER BY created_at DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC) : [];
$bans    = $bansAvailable    ? $pdo->query("SELECT * FROM login_banned_ips ORDER BY banned_at DESC")->fetchAll(PDO::FETCH_ASSOC) : [];
$devices = $devicesAvailable ? $pdo->query("SELECT td.*, u.email FROM trusted_devices td LEFT JOIN users u ON td.user_id = u.id ORDER BY td.created_at DESC")->fetchAll(PDO::FETCH_ASSOC) : [];

// Active tab
$activeTab = $_GET['tab'] ?? 'connexions';
if (!in_array($activeTab, ['connexions', 'bans', 'devices'])) $activeTab = 'connexions';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<title>Connexions</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
<link href="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.css" rel="stylesheet" integrity="sha384-Vxog91rIpStbMsSBAP+6bkpv+SJeVDvusYx9GKzKVQBzh085ohJ4QIgNlO4QbkVz" crossorigin="anonymous">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
</head>
<body>

<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>

<style>
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
  .settings-section { display: none; }
  .settings-section.active { display: block; }

  .badge-success { background-color: #198754 !important; }
  .badge-fail    { background-color: #dc3545 !important; }
  .ua-cell       { max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .btn-rose {
    background-color: var(--primary, #f42182); border-color: var(--primary, #f42182); color: var(--primary-text, #fff);
  }
  .btn-rose:hover {
    background-color: var(--primary, #f42182); border-color: var(--primary, #f42182); color: var(--primary-text, #fff);
  }
</style>

<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show auto-dismiss mx-3 mt-3" role="alert" data-dismiss-delay="5000">
    <?= $flash['msg'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<h1 class="mb-3 fw-bold"><i class="bi bi-box-arrow-in-right me-2"></i>Connexions</h1>

<!-- Navigation Tabs -->
<ul class="nav settings-tabs" id="connexionsTabs">
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'connexions' ? 'active' : '' ?>" href="#" data-tab="connexions">
      <i class="bi bi-box-arrow-in-right me-1"></i>Connexions
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'bans' ? 'active' : '' ?>" href="#" data-tab="bans">
      <i class="bi bi-shield-x me-1"></i>IP Bannies
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'devices' ? 'active' : '' ?>" href="#" data-tab="devices">
      <i class="bi bi-phone me-1"></i>Appareils de confiance
    </a>
  </li>
</ul>

<!-- ═══════ TAB: Connexions ═══════ -->
<div class="settings-section <?= $activeTab === 'connexions' ? 'active' : '' ?>" id="section-connexions">
  <?php if (!$logsAvailable): ?>
    <div class="alert alert-warning">
      <i class="bi bi-exclamation-triangle me-2"></i>
      La table <code>login_logs</code> n'existe pas encore. Veuillez exécuter la migration correspondante.
    </div>
  <?php else: ?>
    <h5 class="mb-3">Dernières 500 connexions</h5>
    <div class="table-responsive">
      <table class="table table-striped table-hover" id="logsTable">
        <thead>
          <tr>
            <th>Date</th>
            <th>Email</th>
            <th>IP</th>
            <th>User Agent</th>
            <th>Statut</th>
            <th>Raison</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
          <tr>
            <td><?= htmlspecialchars($log['created_at'] ?? '') ?></td>
            <td><?= htmlspecialchars($log['email'] ?? '') ?></td>
            <?php $logIp = $log['ip_address'] ?? $log['ip'] ?? ''; ?>
            <td class="ip-cell" data-ip="<?= htmlspecialchars($logIp) ?>"><code><?= htmlspecialchars($logIp) ?></code><span class="ip-geo text-muted small ms-1"></span></td>
            <td class="ua-cell" title="<?= htmlspecialchars($log['user_agent'] ?? '') ?>">
              <?= htmlspecialchars(mb_strimwidth($log['user_agent'] ?? '', 0, 60, '...')) ?>
            </td>
            <td>
              <?php if (($log['success'] ?? 0) == 1): ?>
                <span class="badge bg-success">Succes</span>
              <?php else: ?>
                <span class="badge bg-danger">Echec</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($log['reason'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- ═══════ TAB: IP Bannies ═══════ -->
<div class="settings-section <?= $activeTab === 'bans' ? 'active' : '' ?>" id="section-bans">
  <?php if (!$bansAvailable): ?>
    <div class="alert alert-warning">
      <i class="bi bi-exclamation-triangle me-2"></i>
      La table <code>login_banned_ips</code> n'existe pas encore. Veuillez exécuter la migration correspondante.
    </div>
  <?php else: ?>
    <!-- Add ban form -->
    <div class="card mb-4">
      <?php if (!$readOnly): ?>
      <div class="card-body">
        <h6 class="card-title mb-3"><i class="bi bi-plus-circle me-1"></i>Bannir une adresse IP</h6>
        <form method="post" class="row g-3 align-items-end">
          <?= csrf_field() ?>
          <div class="col-md-4">
            <label for="banIp" class="form-label">Adresse IP</label>
            <input type="text" class="form-control" id="banIp" name="ip" placeholder="192.168.1.1" required>
          </div>
          <div class="col-md-5">
            <label for="banReason" class="form-label">Raison</label>
            <input type="text" class="form-control" id="banReason" name="reason" placeholder="Tentatives de connexion suspectes">
          </div>
          <div class="col-md-3">
            <label class="form-label">&nbsp;</label>
            <button type="submit" name="ban_ip" value="1" class="btn btn-rose w-100" style="height: 37.2px; border: none;">
              <i class="bi bi-shield-x me-1"></i>Bannir
            </button>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- Banned IPs table -->
    <h5 class="mb-3">Adresses IP bannies</h5>
    <?php if (empty($bans)): ?>
      <div class="alert alert-info">Aucune adresse IP bannie.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-striped table-hover">
          <thead>
            <tr>
              <th>IP</th>
              <th>Raison</th>
              <th>Type</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bans as $ban):
              $hasExpiry  = array_key_exists('expires_at', $ban);
              $isAutoban  = $hasExpiry && !empty($ban['expires_at']);
              $isExpired  = $isAutoban && strtotime($ban['expires_at']) <= time();
            ?>
            <tr<?= $isExpired ? ' class="text-muted"' : '' ?>>
              <?php $banIp = $ban['ip'] ?? $ban['ip_address'] ?? ''; ?>
              <td class="ip-cell" data-ip="<?= htmlspecialchars($banIp) ?>"><code><?= htmlspecialchars($banIp) ?></code><span class="ip-geo text-muted small ms-1"></span></td>
              <td><?= htmlspecialchars($ban['reason'] ?? '-') ?></td>
              <td>
                <?php if ($isAutoban): ?>
                  <?php if ($isExpired): ?>
                    <span class="badge bg-secondary">Auto-ban expiré</span>
                    <br><small class="text-muted">Expiré le <?= htmlspecialchars($ban['expires_at']) ?></small>
                  <?php else: ?>
                    <span class="badge bg-warning text-dark">Auto-ban</span>
                    <br><small>Expire le <?= htmlspecialchars($ban['expires_at']) ?></small>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="badge bg-danger">Permanent</span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($ban['banned_at'] ?? '') ?></td>
              <td>
                <?php if (!$readOnly): ?>
                  <?php if (!$isExpired): ?>
                  <form method="post" style="display:inline" data-confirm="Débannir cette IP ?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="ban_id" value="<?= (int)$ban['id'] ?>">
                    <button type="submit" name="unban_ip" value="1" class="btn btn-sm btn-outline-success">
                      <i class="bi bi-unlock me-1"></i>Débannir
                    </button>
                  </form>
                  <?php else: ?>
                  <form method="post" style="display:inline" data-confirm="Supprimer cette entrée ?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="ban_id" value="<?= (int)$ban['id'] ?>">
                    <button type="submit" name="unban_ip" value="1" class="btn btn-sm btn-outline-secondary">
                      <i class="bi bi-trash me-1"></i>Supprimer
                    </button>
                  </form>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-muted small">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ═══════ TAB: Appareils de confiance ═══════ -->
<div class="settings-section <?= $activeTab === 'devices' ? 'active' : '' ?>" id="section-devices">
  <?php if (!$devicesAvailable): ?>
    <div class="alert alert-warning">
      <i class="bi bi-exclamation-triangle me-2"></i>
      La table <code>trusted_devices</code> n'existe pas encore. Veuillez exécuter la migration correspondante.
    </div>
  <?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="mb-0">Appareils de confiance</h5>
      <?php if (!empty($devices) && !$readOnly): ?>
        <form method="post" data-confirm="Révoquer TOUS les appareils de confiance ?">
          <?= csrf_field() ?>
          <button type="submit" name="revoke_all_devices" value="1" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-trash me-1"></i>Révoquer tous les appareils
          </button>
        </form>
      <?php endif; ?>
    </div>

    <?php if (empty($devices)): ?>
      <div class="alert alert-info">Aucun appareil de confiance enregistré.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-striped table-hover" id="devicesTable">
          <thead>
            <tr>
              <th>Email</th>
              <th>IP</th>
              <th>User Agent</th>
              <th>Créé le</th>
              <th>Expire le</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($devices as $device): ?>
            <tr>
              <td><?= htmlspecialchars($device['email'] ?? 'Inconnu') ?></td>
              <?php $devIp = $device['ip_address'] ?? ''; ?>
              <td class="ip-cell" data-ip="<?= htmlspecialchars($devIp) ?>"><code><?= htmlspecialchars($devIp) ?></code><span class="ip-geo text-muted small ms-1"></span></td>
              <td class="ua-cell" title="<?= htmlspecialchars($device['user_agent'] ?? '') ?>">
                <?= htmlspecialchars(mb_strimwidth($device['user_agent'] ?? '', 0, 60, '...')) ?>
              </td>
              <td><?= htmlspecialchars($device['created_at'] ?? '') ?></td>
              <td><?= htmlspecialchars($device['expires_at'] ?? '') ?></td>
              <td>
                <?php if (!$readOnly): ?>
                <form method="post" style="display:inline" data-confirm="Révoquer cet appareil ?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="device_id" value="<?= (int)$device['id'] ?>">
                  <button type="submit" name="revoke_device" value="1" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-x-circle me-1"></i>Révoquer
                  </button>
                </form>
                <?php else: ?>
                  <span class="text-muted small">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../src/partials/admin-footer.php'; ?>

<!-- ═══════ Scripts ═══════ -->
<!-- 🔒 [FIX-09] Ajout SRI sur jQuery (CWE-829) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"
        integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
        crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.js" integrity="sha384-3wB6mhez87GBdPpEqKMU2wAH2Cjcvj8ynU/n7blM/JW4BLpVD0aTrx4ZE7IwFLSH" crossorigin="anonymous"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
document.addEventListener('DOMContentLoaded', function() {
  // ── Auto-dismiss alerts ──
  document.querySelectorAll('.auto-dismiss').forEach(function(alert) {
    var delay = parseInt(alert.dataset.dismissDelay) || 5000;
    setTimeout(function() {
      var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
      bsAlert.close();
    }, delay);
  });

  // ══════════════════════════════════════════════════════════════
  // GÉOLOCALISATION IP — appel serveur + cache fichier (côté serveur)
  // ══════════════════════════════════════════════════════════════
  (function () {
    function applyGeo(ip, geo) {
      const cells = document.querySelectorAll('.ip-cell[data-ip="' + CSS.escape(ip) + '"] .ip-geo');
      let label = '';
      if (geo && geo.country === '__local__') {
        label = ' (Réseau local)';
      } else if (geo && (geo.city || geo.country)) {
        const parts = [];
        if (geo.city)    parts.push(geo.city);
        if (geo.country) parts.push(geo.country);
        label = ' (' + parts.join(', ') + ')';
      }
      cells.forEach(el => { el.textContent = label; });
    }

    // Récupérer toutes les IPs uniques visibles
    const allIps = new Set();
    document.querySelectorAll('.ip-cell[data-ip]').forEach(el => {
      const ip = el.dataset.ip;
      if (ip) allIps.add(ip);
    });
    if (allIps.size === 0) return;

    // Récupérer le token CSRF (nécessaire pour les POST vers api.php)
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    // Appel batch unique vers notre API serveur (qui gère le cache fichier)
    fetch('../admin-api.php?route=ip-geo', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken
      },
      body: JSON.stringify({ ips: Array.from(allIps) })
    })
    .then(r => r.ok ? r.json() : null)
    .then(j => {
      if (j && j.ok && j.geo) {
        Object.keys(j.geo).forEach(ip => applyGeo(ip, j.geo[ip]));
      }
    })
    .catch(() => { /* silent */ });
  })();

  // ── Tab switching ──
  document.querySelectorAll('#connexionsTabs .nav-link').forEach(function(link) {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      var tab = this.getAttribute('data-tab');

      // Update active tab link
      document.querySelectorAll('#connexionsTabs .nav-link').forEach(function(l) { l.classList.remove('active'); });
      this.classList.add('active');

      // Update active section
      document.querySelectorAll('.settings-section').forEach(function(s) { s.classList.remove('active'); });
      var target = document.getElementById('section-' + tab);
      if (target) target.classList.add('active');

      // Update URL without reload
      history.replaceState(null, '', 'connexions.php?tab=' + tab);
    });
  });

  // ── DataTables with French language ──
  var frLang = {
    processing:     "Traitement en cours...",
    search:         "Rechercher :",
    lengthMenu:     "Afficher _MENU_ entrées",
    info:           "Affichage de _START_ à _END_ sur _TOTAL_ entrées",
    infoEmpty:      "Aucune entrée à afficher",
    infoFiltered:   "(filtré à partir de _MAX_ entrées au total)",
    loadingRecords: "Chargement en cours...",
    zeroRecords:    "Aucun résultat trouvé",
    emptyTable:     "Aucune donnée disponible",
    paginate: {
      first:    "Premier",
      previous: "Précédent",
      next:     "Suivant",
      last:     "Dernier"
    }
  };

  // Pagination compacte type "1 ... 4 5 6 ... 12"
  if ($.fn.DataTable) { $.fn.dataTable.ext.pager.numbers_length = 7; }

  if ($.fn.DataTable && document.getElementById('logsTable')) {
    $('#logsTable').DataTable({
      language: frLang,
      order: [[0, 'desc']],
      pageLength: 25,
      lengthMenu: [10, 25, 50, 100, 500]
    });
  }

  if ($.fn.DataTable && document.getElementById('devicesTable')) {
    $('#devicesTable').DataTable({
      language: frLang,
      order: [[3, 'desc']],
      pageLength: 25
    });
  }
});
</script>
</body>
</html>
