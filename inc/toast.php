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
<div id="debugBanner" style="
  position: fixed; bottom: 0; left: 0; right: 0; z-index: 99999;
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  color: #fff; padding: 6px 16px; font-size: 12px; font-weight: 700;
  display: flex; align-items: center; gap: 8px;
  box-shadow: 0 -2px 12px rgba(0,0,0,.15);
  text-transform: uppercase; letter-spacing: 0.05em;
">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01M5.07 19H19a2 2 0 001.75-2.97L13.75 4a2 2 0 00-3.5 0L3.32 16.03A2 2 0 005.07 19z"/></svg>
  Mode debogage actif — Les erreurs PHP sont affichees. <a href="logs" style="color:#fff;text-decoration:underline;margin-left:8px;">Voir les logs</a>
</div>
<style>#oc-content { padding-bottom: 42px !important; }</style>
<?php endif; ?>

<!-- Toast container -->
<div id="toastContainer" style="
  position: fixed; bottom: <?= $debugActive ? '42px' : '12px' ?>; right: 12px;
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
window.showToast = function(message, type, duration) {
  type = type || 'success';
  duration = duration || 4000;
  var icons = {
    success: '&#10003;',
    danger: '&#10007;',
    warning: '&#9888;',
    info: '&#8505;'
  };
  var container = document.getElementById('toastContainer');
  var toast = document.createElement('div');
  toast.className = 'fer-toast fer-toast-' + type;
  toast.style.setProperty('--toast-duration', duration + 'ms');
  toast.innerHTML =
    '<span class="fer-toast-icon">' + (icons[type] || '') + '</span>' +
    '<span class="fer-toast-body">' + message + '</span>' +
    '<button class="fer-toast-close" onclick="this.parentElement.classList.add(\'toast-out\');setTimeout(function(){this.parentElement.remove()}.bind(this),300)">&times;</button>' +
    '<div class="fer-toast-progress"></div>';
  container.appendChild(toast);
  setTimeout(function() {
    toast.classList.add('toast-out');
    setTimeout(function() { toast.remove(); }, 300);
  }, duration);
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
