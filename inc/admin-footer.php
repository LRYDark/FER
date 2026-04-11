
<?php include __DIR__ . '/toast.php'; ?>

  </div><!-- /oc-content -->
</div><!-- /oc-app-container -->

<?php
// Mode lecture seule au niveau de la page courante :
//   chaque page admin peut définir $pageReadOnly = true; pour signaler qu'on n'a
//   pas le droit d'écrire — admin-footer.php ajoute alors la classe sur <body>
//   et le JS plus bas désactive tous les boutons d'envoi de formulaire.
if (!empty($pageReadOnly)):
?>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">document.body.classList.add('app-readonly');</script>
<?php endif; ?>
<!-- ═══════ ADMIN LAYOUT SCRIPTS ═══════ -->
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
document.addEventListener('DOMContentLoaded', function() {
  // ── Sidebar toggle (mobile) ──
  var burger  = document.getElementById('ocBurger');
  var sidebar = document.getElementById('oc-sidebar');
  var overlay = document.getElementById('ocOverlay');

  function openSidebar()  { sidebar.classList.add('open'); overlay.classList.add('show'); }
  function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }

  if (burger)  burger.addEventListener('click', openSidebar);
  if (overlay) overlay.addEventListener('click', closeSidebar);

  // Close sidebar when clicking a link (mobile)
  document.querySelectorAll('.oc-sidebar-link').forEach(function(link) {
    link.addEventListener('click', closeSidebar);
  });

  // ── User dropdown ──
  var avatarBtn = document.getElementById('ocAvatarBtn');
  var dropdown  = document.getElementById('ocDropdown');

  if (avatarBtn && dropdown) {
    avatarBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      dropdown.classList.toggle('show');
    });
    document.addEventListener('click', function(e) {
      if (!dropdown.contains(e.target) && e.target !== avatarBtn) {
        dropdown.classList.remove('show');
      }
    });
  }

  // ── Logout ──
  var logoutLink = document.getElementById('ocLogoutLink');
  if (logoutLink) {
    logoutLink.addEventListener('click', function(e) {
      e.preventDefault();
      fetch('../config/api.php?route=logout').then(function() { location.href = '../login.php'; });
    });
  }

  // ── Mode toggle (dashboard only) ──
  var modeToggle = document.getElementById('ocModeToggle');
  if (modeToggle) {
    modeToggle.addEventListener('click', function(e) {
      e.preventDefault();
      // Toggle the dashboard tshirt mode directly via global variable
      if (typeof tshirtMode !== 'undefined') {
        tshirtMode = !tshirtMode;
        if (typeof refreshButtons === 'function') refreshButtons();
        if (typeof applyTshirtMode === 'function') applyTshirtMode();
        // Update label in dropdown
        var label = modeToggle.querySelector('span');
        if (label) {
          label.textContent = tshirtMode ? 'Mode standard' : 'Remise T-shirts';
        }
      }
      if (dropdown) dropdown.classList.remove('show');
    });
  }
  // ── Confirmation dialogs CSP-compatible (data-confirm) ──
  document.addEventListener('submit', function(e) {
    var form = e.target.closest('form[data-confirm]');
    if (form && !confirm(form.dataset.confirm)) e.preventDefault();
  });
  document.addEventListener('click', function(e) {
    var btn = e.target.closest('button[data-confirm]');
    if (btn && !confirm(btn.dataset.confirm)) e.preventDefault();
  });

  // ── Generic data-action handlers ──
  document.addEventListener('change', function(e) {
    var el = e.target.closest('[data-action]');
    if (!el) return;
    if (el.dataset.action === 'month-navigate') {
      var v = el.value.split('-');
      window.location.href = '?period=month&y=' + v[0] + '&m=' + v[1];
    }
    if (el.dataset.action === 'preview-new-image' && typeof previewNewImage === 'function') {
      previewNewImage(el, el.dataset.positioner, el.dataset.imgpos);
    }
  });

  // ── Mode lecture seule (viewer) : désactiver tous les boutons d'envoi de formulaire ──
  if (document.body.classList.contains('app-readonly')) {
    document.querySelectorAll('form button[type="submit"], form input[type="submit"]').forEach(function(b) {
      b.disabled = true;
      b.title = 'Lecture seule';
      b.style.opacity = '0.4';
      b.style.cursor = 'not-allowed';
    });
    document.querySelectorAll('form').forEach(function(f) {
      f.addEventListener('submit', function(e) { e.preventDefault(); e.stopPropagation(); });
    });
  }
});
</script>
