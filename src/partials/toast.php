<?php
/**
 * Système de notifications toast (bottom-right) + bandeau debug
 * Inclus automatiquement par admin-footer.php
 */

/* ⚠️ UNE RÉPONSE XHR NE DOIT RIEN CONSOMMER, ET C'EST TOUT L'OBJET DE CES
 * TROIS LIGNES.
 *
 * La barre d'enregistrement (src/partials/save-bar.php) envoie chaque
 * formulaire modifié en fetch(), puis recharge la page. La réponse de ce
 * fetch est un rendu COMPLET de l'écran — pied de page compris, donc ce
 * fichier — et personne ne la lit : elle est jetée.
 *
 * Or, plus bas, ce fichier VIDE $_SESSION['toasts'] après les avoir affichés.
 * Le message « Configuration enregistrée » partait donc dans le HTML jeté, et
 * au rechargement il ne restait plus rien à montrer : l'enregistrement
 * réussissait sans un mot de confirmation. C'est exactement le « pas de toast
 * après avoir cliqué Enregistrer » constaté sur Emails et Applications.
 *
 * La règle vaut pour TOUT envoi XHR qui retombe sur une page complète — le
 * réordonnancement des albums et des partenaires est dans le même cas, et
 * mangeait lui aussi les messages en attente. Ce qui n'est lu par personne ne
 * doit rien retirer de la session.
 *
 * Réglages, lui, n'était pas touché : il part en envoi de formulaire classique
 * et lit donc la réponse qu'il consomme.
 */
if (function_exists('isAjaxRequest') && isAjaxRequest()) {
    return;
}

// ── Bandeau debug ──
if (!empty($GLOBALS['debogage']) || (!empty($data) && !empty($data['debogage']))) {
    $debugActive = true;
} else {
    // Charger depuis la BDD si pas encore disponible
    $debugActive = false;
    // Lecture partagée : la ligne est déjà en cache à ce stade.
    if (isset($pdo) && function_exists('settingRow')) {
        $debugActive = (bool) (settingRow($pdo)['debogage'] ?? false);
    }
}
?>

<?php if ($debugActive): ?>
<?php // L'ancien bandeau orange « Mode débogage » est remplacé par la barre de debug
      // (src/core/debug.php), rendue en bas de page. On garde juste une marge basse pour
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

/* ── Regroupement des confirmations ───────────────────────────────────────
 *
 * ⚠️ UN ENREGISTREMENT = UN MESSAGE, PAS UN PAR REQUÊTE SQL.
 *
 * Depuis que le bouton « Enregistrer » envoie tous les réglages d'un écran
 * en une fois, chaque gestionnaire PHP pose sa propre confirmation : on se
 * retrouvait avec « Paramètres enregistrés ! » puis « Couleurs du bandeau
 * mises à jour ! » puis les suivantes, empilées dans le coin.
 *
 * On ne fusionne QUE les succès. Les avertissements et les erreurs restent
 * affichés un par un : chacun dit une chose différente, et en perdre un
 * derrière un résumé, c'est laisser passer un échec sans le voir.
 *
 * Le regroupement se fait ICI, au rendu, et pas dans les gestionnaires : ils
 * sont une trentaine, répartis sur toutes les pages d'administration, et
 * chacun garde ainsi son message quand il est déclenché seul. */
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['toasts'])) {
    $tSucces = [];
    $tAutres = [];
    foreach ($_SESSION['toasts'] as $t) {
        if (($t['type'] ?? '') === 'success') $tSucces[] = $t; else $tAutres[] = $t;
    }
    if (count($tSucces) > 1) {
        /* Le pluriel porte sur le nombre de réglages touchés, pas sur le
           nombre de requêtes : « 3 réglages enregistrés » se comprend, pas
           « 3 confirmations ». */
        $tSucces = [[
            'msg'   => count($tSucces) . ' réglages enregistrés',
            'type'  => 'success',
            'delay' => 4000,
        ]];
    }
    /* Les erreurs d'abord dans la file : le conteneur est en
       column-reverse, elles finissent donc EN HAUT de la pile, là où l'œil
       va en premier. */
    $tFile = array_merge($tSucces, $tAutres);

    echo '<script nonce="' . $GLOBALS['csp_nonce'] . '">';
    echo 'document.addEventListener("DOMContentLoaded", function(){';
    foreach ($tFile as $t) {
        echo 'showToast(' . json_encode($t['msg']) . ',' . json_encode($t['type']) . ',' . ($t['delay'] ?? 4000) . ');';
    }
    echo '});';
    echo '</script>';
    $_SESSION['toasts'] = [];
}
?>
