<?php
/**
 * Système de notifications toast (bottom-right) + bandeau debug
 * Inclus automatiquement par admin-footer.php
 */

// ── Bandeau debug ──
if (!empty($GLOBALS['debogage']) || (!empty($data) && !empty($data['debogage']))) {
    $debugActive = true;
} else {
    // Charger depuis la BDD si pas encore disponible
    $debugActive = false;
    if (isset($pdo)) {
        try {
            $stmtDbg = $pdo->query('SELECT debogage FROM setting WHERE id = 1 LIMIT 1');
            $debugActive = (bool) ($stmtDbg->fetchColumn());
        } catch (\Throwable $e) {}
    }
}
?>

<?php if ($debugActive): ?>
<?php // L'ancien bandeau orange « Mode débogage » est remplacé par la barre de debug
      // (config/debug.php), rendue en bas de page. On garde juste une marge basse pour
      // que le contenu ne soit pas masqué par la barre, et on remonte les toasts. ?>
<style>#oc-content { padding-bottom: 44px !important; }</style>
<?php endif; ?>

<!-- Toast container -->
<div id="toastContainer" style="
  position: fixed; bottom: <?= $debugActive ? '48px' : '12px' ?>; right: 12px;
  z-index: 99998; display: flex; flex-direction: column-reverse; gap: 8px;
  max-width: 400px; width: 100%;
"></div>

<style>
.fer-toast {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 14px 18px; border-radius: 12px;
  font-size: 13px; font-weight: 500; line-height: 1.4;
  box-shadow: 0 8px 30px rgba(0,0,0,.15);
  animation: toastIn .35s ease;
  position: relative; overflow: hidden;
  border: 1px solid rgba(255,255,255,.15);
}
.fer-toast.toast-out { animation: toastOut .3s ease forwards; }

.fer-toast-success { background: #065f46; color: #d1fae5; }
.fer-toast-danger  { background: #991b1b; color: #fecaca; }
.fer-toast-warning { background: #92400e; color: #fef3c7; }
.fer-toast-info    { background: #1e3a5f; color: #bfdbfe; }

.fer-toast-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
.fer-toast-body { flex: 1; }
.fer-toast-close {
  background: none; border: none; color: inherit; opacity: .6;
  cursor: pointer; font-size: 16px; padding: 0; line-height: 1;
}
.fer-toast-close:hover { opacity: 1; }

.fer-toast-progress {
  position: absolute; bottom: 0; left: 0; height: 3px;
  background: rgba(255,255,255,.3); border-radius: 0 0 12px 12px;
  animation: toastProgress var(--toast-duration, 4s) linear forwards;
}

@keyframes toastIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
@keyframes toastOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
@keyframes toastProgress { from { width: 100%; } to { width: 0%; } }

@media (max-width: 480px) {
  #toastContainer { left: 12px; right: 12px; max-width: none; }
}
</style>

<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
function _ferToastDismiss(toast) {
  toast.classList.add('toast-out');
  setTimeout(function() { toast.remove(); }, 300);
}
window.showToast = function(message, type, duration) {
  type = type || 'success';
  duration = duration || 4000;
  var icons = { success: '&#10003;', danger: '&#10007;', warning: '&#9888;', info: '&#8505;' };
  var container = document.getElementById('toastContainer');
  var toast = document.createElement('div');
  toast.className = 'fer-toast fer-toast-' + type;
  toast.style.setProperty('--toast-duration', duration + 'ms');

  var icon  = document.createElement('span');
  icon.className = 'fer-toast-icon';
  icon.innerHTML = icons[type] || '';

  var body  = document.createElement('span');
  body.className = 'fer-toast-body';
  body.innerHTML = message;

  var close = document.createElement('button');
  close.className = 'fer-toast-close';
  close.innerHTML = '&times;';
  close.setAttribute('aria-label', 'Fermer');
  close.addEventListener('click', function() { _ferToastDismiss(toast); });

  var progress = document.createElement('div');
  progress.className = 'fer-toast-progress';

  toast.appendChild(icon);
  toast.appendChild(body);
  toast.appendChild(close);
  toast.appendChild(progress);
  container.appendChild(toast);

  setTimeout(function() { _ferToastDismiss(toast); }, duration);
};
</script>

<?php
// ── Convertir flash_message legacy en toast ──
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['flash_message'])) {
    $fm = $_SESSION['flash_message'];
    $fmMsg = $fm['message'] ?? $fm['msg'] ?? '';
    $fmType = $fm['type'] ?? 'success';
    if ($fmType === 'error') $fmType = 'danger';
    if ($fmMsg) {
        $_SESSION['toasts'][] = ['msg' => $fmMsg, 'type' => $fmType, 'delay' => $fmType === 'success' ? 4000 : 10000];
    }
    unset($_SESSION['flash_message']);
}

// ── Convertir connexions_flash legacy en toast ──
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['connexions_flash'])) {
    $cf = $_SESSION['connexions_flash'];
    $cfMsg = $cf['msg'] ?? $cf['message'] ?? '';
    $cfType = $cf['type'] ?? 'success';
    if ($cfMsg) {
        $_SESSION['toasts'][] = ['msg' => $cfMsg, 'type' => $cfType, 'delay' => 4000];
    }
    unset($_SESSION['connexions_flash']);
}

// ── Afficher les toasts en attente ──
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['toasts'])) {
    echo '<script nonce="' . $GLOBALS['csp_nonce'] . '">';
    echo 'document.addEventListener("DOMContentLoaded", function(){';
    foreach ($_SESSION['toasts'] as $t) {
        echo 'showToast(' . json_encode($t['msg']) . ',' . json_encode($t['type']) . ',' . ($t['delay'] ?? 4000) . ');';
    }
    echo '});';
    echo '</script>';
    $_SESSION['toasts'] = [];
}
?>
