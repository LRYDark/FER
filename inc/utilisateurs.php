<?php
require '../config/config.php';
require_once '../config/csrf.php';
requireRole(['admin']);
$role = currentRole();
require 'navbar-data.php';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Utilisateurs</title>
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
      rel="stylesheet"
      integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
      crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
<link href="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.css" rel="stylesheet" integrity="sha384-Vxog91rIpStbMsSBAP+6bkpv+SJeVDvusYx9GKzKVQBzh085ohJ4QIgNlO4QbkVz" crossorigin="anonymous">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>
<!-- TinyMCE pour l'éditeur de texte enrichi -->
<script src="../js/tinymce/tinymce.min.js"></script>
<style>
  /* ═══ Tab styles (same pattern as settings-tabs) ═══ */
  .settings-tabs { border-bottom: 2px solid #f0e8eb; margin-bottom: 24px; gap: 0; }
  .settings-tabs .nav-link {
    color: #1e293b; font-weight: 500; font-size: 14px;
    padding: 10px 18px; border: none; border-bottom: 2px solid transparent;
    margin-bottom: -2px; border-radius: 0; background: transparent;
  }
  .settings-tabs .nav-link:hover { color: #1e293b; border-bottom-color: #d4c4cb; }
  .settings-tabs .nav-link.active {
    color: #1e293b; font-weight: 600;
    border-bottom-color: #ec4899; background: transparent;
  }
  .tab-section { display: none; }
  .tab-section.active { display: block; }

  .card-dashboard{margin-top:1rem;border-radius:1.25rem;box-shadow:0 0 25px rgba(0,0,0,.1)}

  /* ═══ Users table styles ═══ */
  #tblUsers tbody tr.user-inactive td {
    opacity: 0.5;
    text-decoration: line-through;
  }
  #tblUsers tbody tr { cursor: pointer; }
  #tblUsers tbody tr:hover { background-color: #f8f0f4; }

  /* ═══ Responsive users table ═══ */
  @media (max-width: 767.98px) {
    #tblUsers {
      font-size: .78rem;
    }
    #tblUsers th,
    #tblUsers td {
      padding: .35rem .25rem;
      white-space: nowrap;
    }
    #fCreateUser .col-md-6,
    #fEditUser .col-md-6 {
      flex: 0 0 100%;
      max-width: 100%;
    }
  }
  @media (max-width: 575.98px) {
    #tblUsers td:nth-child(5),
    #tblUsers th:nth-child(5),
    #tblUsers td:nth-child(6),
    #tblUsers th:nth-child(6) {
      display: none;
    }
  }

  /* ═══ Top bar for new user button ═══ */
  .users-toolbar { display: flex; justify-content: flex-end; margin-bottom: 1rem; }

  /* ═══ Mail styles ═══ */
  .recipients-counter {
    font-size: 0.875rem;
    color: #6c757d;
    margin-top: 0.25rem;
  }

  .select-all-btn {
    font-size: 0.8rem;
    padding: 0.2rem 0.5rem;
  }

  #mailDescription {
    min-height: 300px;
  }

  #selectedRecipients .badge {
    font-size: 0.8rem !important;
  }

  #selectedRecipients .btn-close {
    padding: 0.2rem;
    font-size: 0.6rem;
  }

  #selectedRecipients:empty::after {
    content: "Aucun destinataire sélectionné";
    color: #6c757d;
    font-size: 0.875rem;
    font-style: italic;
  }

  /* ═══ Email search / suggestions ═══ */
  .email-search-container {
    position: relative;
  }

  .email-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #dee2e6;
    border-top: none;
    border-radius: 0 0 0.375rem 0.375rem;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1050;
    display: none;
  }

  .suggestion-item {
    padding: 0.5rem 0.75rem;
    cursor: pointer;
    border-bottom: 1px solid #eee;
  }

  .suggestion-item:hover {
    background-color: #f8f9fa;
  }

  .suggestion-item:last-child {
    border-bottom: none;
  }

  /* ═══ Btn rose ═══ */
  .btn-rose{
    background:linear-gradient(135deg,#ec4899,#db2777)!important;
    color:#fff!important;
    border:none!important;
  }
  .btn-rose:hover,
  .btn-rose:focus{
    background:linear-gradient(135deg,#db2777,#be185d)!important;
    color:#fff!important;
  }
</style>
</head>
<body>
<?php include 'navbar-admin.php'; ?>

<div class="bg-white p-4 card-dashboard">
  <h2 class="mb-3">Utilisateurs & Envoi de mail</h2>

  <?php if (isset($_SESSION['flash_message'])):
    $flash = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
  ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show auto-dismiss" role="alert" data-dismiss-delay="5000">
      <?= htmlspecialchars($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Tabs -->
  <?php $activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'mail') ? 'mail' : 'users'; ?>
  <ul class="nav settings-tabs" id="userTabs">
    <li class="nav-item"><a class="nav-link <?= $activeTab === 'users' ? 'active' : '' ?>" href="#" data-tab="users">Utilisateurs</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab === 'mail' ? 'active' : '' ?>" href="#" data-tab="mail">Envoi de mail</a></li>
  </ul>

  <!-- ═══ Tab: Users ═══ -->
  <div class="tab-section <?= $activeTab === 'users' ? 'active' : '' ?>" id="tab-users">
    <div class="users-toolbar">
      <button class="btn btn-rose" data-bs-toggle="modal" data-bs-target="#createUserModal">
        <i class="bi bi-plus-lg me-1"></i>Nouvel utilisateur
      </button>
    </div>
    <div class="table-responsive">
      <table id="tblUsers" class="table table-sm w-100"></table>
    </div>
  </div>

  <!-- ═══ Tab: Mail ═══ -->
  <div class="tab-section <?= $activeTab === 'mail' ? 'active' : '' ?>" id="tab-mail">
    <form id="fMail">
      <div class="row g-3">
        <!-- Destinataires -->
        <div class="col-12">
          <label for="mailRecipients" class="form-label">
            <i class="bi bi-people"></i> Destinataires
          </label>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
              <button type="button" class="btn btn-outline-primary select-all-btn" id="selectAllBtn">
                Tout sélectionner
              </button>
              <button type="button" class="btn btn-outline-secondary select-all-btn" id="clearAllBtn">
                Tout désélectionner
              </button>
            </div>
          </div>

          <!-- Zone de recherche unique -->
          <div class="email-search-container">
              <input type="text" id="emailSearchInput" class="form-control" placeholder="Tapez un nom, prénom ou email (ou plusieurs emails séparés par des virgules) puis appuyez sur Entrée">
            <div id="emailSuggestions" class="email-suggestions"></div>
          </div>

          <!-- Zone d'affichage des destinataires sélectionnés -->
          <div id="selectedRecipients" class="border rounded p-2 bg-light mt-3" style="min-height: 120px; max-height: 200px; overflow-y: auto;">
            <small class="text-muted">Aucun destinataire sélectionné</small>
          </div>

          <div class="recipients-counter mt-2" id="recipientsCounter">
            0 destinataire(s) sélectionné(s)
          </div>

          <!-- Input caché pour stocker les emails sélectionnés -->
          <input type="hidden" name="recipients" id="hiddenRecipients">
        </div>

        <!-- Objet du mail -->
        <div class="col-12">
          <label for="mailSubject" class="form-label">
            <i class="bi bi-tag"></i> Objet du mail
          </label>
          <input type="text" name="subject" id="mailSubject" class="form-control"
                 placeholder="Objet de votre mail" required maxlength="255">
        </div>

        <!-- Titre du contenu -->
        <div class="col-12">
          <label for="mailTitle" class="form-label">
            <i class="bi bi-type-h1"></i> Titre du contenu
          </label>
          <input type="text" name="mail_title" id="mailTitle" class="form-control"
                 placeholder="Titre qui apparaîtra dans le mail" maxlength="255">
          <small class="form-text text-muted">
            Ce titre sera affiché en tant que titre principal dans le contenu du mail
          </small>
        </div>

        <!-- Description avec éditeur de texte enrichi -->
        <div class="col-12">
          <label for="mailDescription" class="form-label">
            <i class="bi bi-file-text"></i> Contenu du mail
          </label>

          <textarea name="description" id="mailDescription" class="form-control">
            <!-- Le contenu sera géré par TinyMCE -->
          </textarea>
          <small class="form-text text-muted">
            Utilisez l'éditeur pour formater votre message avec du texte en gras, des couleurs, des listes, etc.
          </small>
        </div>
      </div>

      <div class="mt-3">
        <button type="submit" class="btn btn-success">
          <i class="bi bi-send"></i> Envoyer le mail
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal creation utilisateur -->
<div class="modal fade" id="createUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Nouvel utilisateur</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="fCreateUser" class="row g-3">
          <div class="col-12">
            <label class="form-label">Email</label>
            <input name="email" type="email" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Role</label>
            <select name="role" class="form-select">
              <option>viewer</option><option>user</option><option>saisie</option><option>admin</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Organisation</label>
            <input name="organisation" class="form-control">
          </div>
          <div class="col-12 text-end">
            <button type="submit" class="btn btn-rose">Creer</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Modal modification utilisateur -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Modifier l'utilisateur</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="fEditUser" class="row g-3">
          <input type="hidden" name="id" id="editUserId">
          <div class="col-12">
            <label class="form-label">Email</label>
            <input name="email" type="email" id="editUserEmail" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Role</label>
            <select name="role" id="editUserRole" class="form-select">
              <option>viewer</option><option>user</option><option>saisie</option><option>admin</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Organisation</label>
            <input name="organisation" id="editUserOrg" class="form-control">
          </div>
          <div class="col-12 text-end">
            <button type="submit" class="btn btn-primary">Enregistrer</button>
          </div>
        </form>
        <hr>
        <div class="d-flex flex-wrap gap-2">
          <button id="btnResetPwd" class="btn btn-outline-warning btn-sm"><i class="bi bi-key me-1"></i>Reinitialiser MDP</button>
          <button id="btnToggleActive" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pause-circle me-1"></i><span>Bloquer</span></button>
          <button id="btnDeleteUser" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3 me-1"></i>Supprimer</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal mot de passe temporaire -->
<div class="modal fade" id="tempPasswordModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Mot de passe temporaire</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <p id="tempPwdEmailStatus" class="mb-3"></p>
        <p class="text-muted mb-2">Mot de passe temporaire :</p>
        <div class="input-group mb-3">
          <input type="text" id="tempPwdValue" class="form-control text-center font-monospace fs-5" readonly>
          <button class="btn btn-outline-secondary" type="button" id="copyTempPwd" title="Copier">
            <i class="bi bi-clipboard"></i>
          </button>
        </div>
        <div id="copyConfirm" class="text-success d-none">Copie !</div>
        <p class="text-muted small">L'utilisateur devra changer ce mot de passe a sa prochaine connexion.</p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<?php include 'admin-footer.php'; ?>

<!-- ═════════ JS ═════════ -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.js" integrity="sha384-3wB6mhez87GBdPpEqKMU2wAH2Cjcvj8ynU/n7blM/JW4BLpVD0aTrx4ZE7IwFLSH" crossorigin="anonymous"></script>

<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
const _csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const userRole = '<?= $role ?>';
let availableEmails = [];
let selectedRecipients = [];

/* ══ Auto-dismiss alerts ════ */
document.querySelectorAll('.auto-dismiss').forEach(function(alert) {
  var delay = parseInt(alert.dataset.dismissDelay) || 5000;
  setTimeout(function() {
    var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
    bsAlert.close();
  }, delay);
});

/* ══ Tab switching ════ */
document.querySelectorAll('#userTabs .nav-link').forEach(function(tab) {
  tab.addEventListener('click', function(e) {
    e.preventDefault();
    document.querySelectorAll('#userTabs .nav-link').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.tab-section').forEach(function(s) { s.classList.remove('active'); });
    this.classList.add('active');
    var tabId = 'tab-' + this.dataset.tab;
    document.getElementById(tabId).classList.add('active');

    // Initialize TinyMCE when mail tab is shown
    if (this.dataset.tab === 'mail') {
      initTinyMCE();
    }
  });
});

/* ══ Users DataTable (init immediately) ════ */
let usrTbl = $('#tblUsers').DataTable({
  ajax:{url:'../config/api.php?route=users',dataSrc:''},
  columns: [
    { data: 'id', title: '#' },
    { data: 'email', title: 'Email' },
    { data: 'role', title: 'R\u00f4le' },
    {
      data: 'is_active',
      title: 'Statut',
      className: 'text-center',
      render: function (val) {
        return val == 1
          ? '<span class="badge bg-success">Actif</span>'
          : '<span class="badge bg-secondary">Inactif</span>';
      }
    },
    { data: 'organisation', title: 'Organisation' },
    { data: 'created_at', title: 'Cr\u00e9\u00e9 le' }
  ],
  createdRow: function (row, data) {
    if (data.is_active != 1) {
      $(row).addClass('user-inactive');
    }
  }
});

/* ══ Temp password modal ════ */
function showTempPasswordModal(password, email, emailSent) {
  document.getElementById('tempPwdValue').value = password;
  const statusEl = document.getElementById('tempPwdEmailStatus');
  if (emailSent) {
    statusEl.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Email envoy\u00e9 \u00e0 ' + email + '</span>';
  } else {
    statusEl.innerHTML = '<span class="text-warning"><i class="bi bi-exclamation-triangle"></i> Email non envoy\u00e9 (Gmail non configur\u00e9). Communiquez le mot de passe manuellement.</span>';
  }
  document.getElementById('copyConfirm').classList.add('d-none');
  new bootstrap.Modal('#tempPasswordModal').show();
}

// Bouton copier
document.getElementById('copyTempPwd').addEventListener('click', function() {
  const val = document.getElementById('tempPwdValue').value;
  navigator.clipboard.writeText(val).then(() => {
    const el = document.getElementById('copyConfirm');
    el.classList.remove('d-none');
    setTimeout(() => el.classList.add('d-none'), 2000);
  });
});

/* ══ Current edit user data (for modal actions) ════ */
let currentEditUser = null;

/* ══ Row click -> open edit modal ════ */
$('#tblUsers tbody').on('click', 'tr', function () {
  const data = usrTbl.row(this).data();
  if (!data) return;
  currentEditUser = data;

  // Fill the edit form
  $('#editUserId').val(data.id);
  $('#editUserEmail').val(data.email);
  $('#editUserRole').val(data.role);
  $('#editUserOrg').val(data.organisation);

  // Toggle active button label
  const toggleBtn = document.getElementById('btnToggleActive');
  if (data.is_active == 1) {
    toggleBtn.innerHTML = '<i class="bi bi-pause-circle me-1"></i><span>Bloquer</span>';
  } else {
    toggleBtn.innerHTML = '<i class="bi bi-play-circle me-1"></i><span>Debloquer</span>';
  }

  new bootstrap.Modal('#editUserModal').show();
});

/* ══ Edit user form submit ════ */
$('#fEditUser').on('submit', function (e) {
  e.preventDefault();
  const fd = new FormData(this);

  fetch('../config/api.php?route=users', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': _csrfToken },
    body: new URLSearchParams(fd)
  })
  .then(r => r.json())
  .then(j => {
    if (j.ok) {
      usrTbl.ajax.reload();
      bootstrap.Modal.getInstance(document.getElementById('editUserModal')).hide();
    } else {
      alert('Erreur : ' + (j.err || 'inconnue'));
    }
  });
});

/* ══ Reset password (edit modal) ════ */
document.getElementById('btnResetPwd').addEventListener('click', function () {
  if (!currentEditUser) return;
  if (!confirm('R\u00e9initialiser le mot de passe de "' + currentEditUser.email + '" ?')) return;

  fetch('../config/api.php?route=users', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': _csrfToken },
    body: new URLSearchParams({ action: 'reset-password', id: currentEditUser.id })
  })
  .then(r => r.json())
  .then(j => {
    if (j.ok) {
      bootstrap.Modal.getInstance(document.getElementById('editUserModal')).hide();
      if (j.temp_password) {
        showTempPasswordModal(j.temp_password, currentEditUser.email, j.email_sent);
      } else {
        alert('Mot de passe r\u00e9initialis\u00e9. Un email a \u00e9t\u00e9 envoy\u00e9 \u00e0 ' + currentEditUser.email + '.');
      }
      usrTbl.ajax.reload();
    } else {
      alert('Erreur : ' + (j.err || 'inconnue'));
    }
  });
});

/* ══ Toggle active (edit modal) ════ */
document.getElementById('btnToggleActive').addEventListener('click', function () {
  if (!currentEditUser) return;
  const action = currentEditUser.is_active == 1 ? 'D\u00e9sactiver' : 'Activer';
  if (!confirm(action + ' le compte "' + currentEditUser.email + '" ?')) return;

  fetch('../config/api.php?route=users', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': _csrfToken },
    body: new URLSearchParams({ action: 'toggle-active', id: currentEditUser.id })
  })
  .then(r => r.json())
  .then(j => {
    if (j.ok) {
      usrTbl.ajax.reload();
      bootstrap.Modal.getInstance(document.getElementById('editUserModal')).hide();
    } else {
      alert('Erreur : ' + (j.err || 'inconnue'));
    }
  });
});

/* ══ Delete user (edit modal) ════ */
document.getElementById('btnDeleteUser').addEventListener('click', function () {
  if (!currentEditUser) return;
  if (!confirm('Supprimer le compte "' + currentEditUser.email + '" ?')) return;

  const deleteUser = (force = false) => {
    const params = new URLSearchParams({ action: 'delete', id: currentEditUser.id });
    if (force) params.append('force', '1');

    fetch('../config/api.php?route=users', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': _csrfToken },
      body: params
    })
    .then(r => r.json())
    .then(j => {
      if (j.ok) {
        usrTbl.ajax.reload();
        bootstrap.Modal.getInstance(document.getElementById('editUserModal')).hide();
      } else if (j.requiresForce) {
        if (confirm(j.warning + "\n\nVoulez-vous continuer et tout supprimer ?")) {
          deleteUser(true);
        }
      } else {
        alert("Erreur : " + (j.err || "inconnue"));
      }
    });
  };

  deleteUser();
});

/* ══ Create user form submit ════ */
$('#fCreateUser').on('submit', function (e) {
  e.preventDefault();
  const fd = new FormData(this);

  fetch('../config/api.php?route=users', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
    body: JSON.stringify(Object.fromEntries(fd))
  })
  .then(r => r.json())
  .then(j => {
    if (j.ok) {
      usrTbl.ajax.reload();
      if (j.temp_password) {
        bootstrap.Modal.getInstance(document.getElementById('createUserModal')).hide();
        showTempPasswordModal(j.temp_password, fd.get('email'), j.email_sent);
      } else {
        bootstrap.Modal.getInstance(document.getElementById('createUserModal')).hide();
      }
      e.target.reset();
    } else {
      alert('Erreur : ' + (j.err || 'inconnue'));
    }
  });
});

/* ══ Mail: TinyMCE ════ */
let tinymceInitialized = false;

function initTinyMCE() {
  if (tinymceInitialized) return;

  tinymce.init({
    selector: '#mailDescription',
    license_key: 'gpl',
    height: 400,
    menubar: false,
    plugins: [
      'advlist', 'autolink', 'lists', 'link', 'image', 'charmap',
      'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
      'insertdatetime', 'media', 'table', 'preview', 'help', 'wordcount'
    ],
    toolbar: 'undo redo | formatselect | ' +
      'bold italic forecolor backcolor | alignleft aligncenter ' +
      'alignright alignjustify | bullist numlist outdent indent | ' +
      'removeformat | help',
    content_style: 'body { font-family:Arial,sans-serif; font-size:14px }',
    valid_styles: {
        '*': 'text-align,line-height,color,background-color,font-size,font-weight,font-style,text-decoration,padding,padding-left,padding-right,padding-top,padding-bottom,margin,margin-left,margin-right,margin-top,margin-bottom',
        'img': 'width,height,max-width,float,margin,margin-left,margin-right,margin-top,margin-bottom,display',
        'table': 'width,height,border-collapse,border-spacing'
    },
    language: 'fr_FR',

    // Upload images sur le serveur au lieu de base64
    images_upload_handler: (blobInfo) => new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('file', blobInfo.blob(), blobInfo.filename());
        formData.append('csrf_token', '<?= csrf_token() ?>');
        fetch('../inc/tinymce-upload.php', { method: 'POST', body: formData })
            .then(r => { if (!r.ok) throw new Error('Upload failed'); return r.json(); })
            .then(data => { if (data.location) resolve(data.location); else reject(data.error || 'Upload error'); })
            .catch(e => reject(e.message));
    }),
    automatic_uploads: true,
    images_reuse_filename: true,

    // Upload fichiers (PDF, images) via le sélecteur de fichiers
    file_picker_types: 'file image',
    file_picker_callback: (callback, value, meta) => {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = meta.filetype === 'image' ? 'image/*' : 'image/*,.pdf';
        input.addEventListener('change', () => {
            const file = input.files[0];
            if (!file) return;
            const formData = new FormData();
            formData.append('file', file);
            formData.append('csrf_token', '<?= csrf_token() ?>');
            fetch('../inc/tinymce-upload.php', { method: 'POST', body: formData })
                .then(r => { if (!r.ok) throw new Error('Upload failed'); return r.json(); })
                .then(data => { if (data.location) { const n = data.title || file.name.replace(/\.[^.]+$/,''); callback(data.location, { title: n, text: n + '.' + file.name.split('.').pop() }); } })
                .catch(e => alert('Erreur upload: ' + e.message));
        });
        input.click();
    }
  });

  tinymceInitialized = true;
}

/* ══ Mail: Load available emails from registrations API ════ */
function updateAvailableEmails(data) {
  availableEmails = [];

  data.forEach(person => {
    if (person.email && person.email.trim() !== '') {
      availableEmails.push({
        email: person.email,
        name: `${person.prenom || ''} ${person.nom || ''}`.trim(),
        id: person.id
      });
    }
  });
}

// Load email data from registrations
fetch('../config/api.php?route=registrations')
  .then(r => r.json())
  .then(data => {
    const sorted = data.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
    updateAvailableEmails(sorted);
  })
  .catch(err => console.error('Erreur chargement emails:', err));

/* ══ Mail: Email validation ════ */
function isValidEmail(email) {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return emailRegex.test(email);
}

/* ══ Mail: Email search ════ */
function searchEmails(query) {
  if (!query || query.length < 1) return [];

  query = query.toLowerCase();

  return availableEmails.filter(person => {
    return person.name.toLowerCase().includes(query) ||
           person.email.toLowerCase().includes(query);
  });
}

/* ══ Mail: Show suggestions ════ */
function showEmailSuggestions(suggestions) {
  const suggestionsDiv = document.getElementById('emailSuggestions');

  if (suggestions.length === 0) {
    suggestionsDiv.style.display = 'none';
    return;
  }

  let html = '';
  suggestions.forEach(person => {
    html += `
      <div class="suggestion-item"
           data-email="${person.email}"
           data-name="${person.name}"
           data-id="${person.id}">
        <strong>${person.name}</strong><br>
        <small class="text-muted">${person.email}</small>
      </div>
    `;
  });

  suggestionsDiv.innerHTML = html;
  suggestionsDiv.style.display = 'block';
}

/* ══ Mail: Hide suggestions ════ */
function hideEmailSuggestions() {
  setTimeout(() => {
    document.getElementById('emailSuggestions').style.display = 'none';
  }, 200);
}

/* ══ Mail: Add recipient ════ */
function addRecipient(email, name, id) {
  if (selectedRecipients.find(r => r.email === email)) {
    return;
  }

  const recipient = { email, name: name || 'Email externe', id: id || null };
  selectedRecipients.push(recipient);

  updateSelectedRecipientsDisplay();
  updateRecipientsCounter();
  updateHiddenInput();
}

/* ══ Mail: Remove recipient ════ */
function removeRecipient(email) {
  selectedRecipients = selectedRecipients.filter(r => r.email !== email);

  updateSelectedRecipientsDisplay();
  updateRecipientsCounter();
  updateHiddenInput();
}

/* ══ Mail: Update display ════ */
function updateSelectedRecipientsDisplay() {
  const container = document.getElementById('selectedRecipients');
  if (!container) return;

  if (selectedRecipients.length === 0) {
    container.innerHTML = '<small class="text-muted">Aucun destinataire sélectionné</small>';
    return;
  }

  let html = '';
  selectedRecipients.forEach(recipient => {
    html += `
      <span class="badge bg-primary me-2 mb-2 d-inline-flex align-items-center" style="font-size: 0.8rem;">
        <span class="me-2">${recipient.name} (${recipient.email})</span>
        <button type="button" class="btn-close"
                data-action="remove-recipient" data-email="${recipient.email}"
                style="font-size: 0.6rem;"
                title="Supprimer"></button>
      </span>
    `;
  });

  container.innerHTML = html;
}

/* ══ Mail: Update counter ════ */
function updateRecipientsCounter() {
  const counter = document.getElementById('recipientsCounter');
  if (!counter) return;

  counter.textContent = `${selectedRecipients.length} destinataire(s) sélectionné(s)`;
}

/* ══ Mail: Update hidden input ════ */
function updateHiddenInput() {
  const hiddenInput = document.getElementById('hiddenRecipients');
  if (!hiddenInput) return;

  hiddenInput.value = JSON.stringify(selectedRecipients);
}

/* ══ Mail: Event listeners ════ */
document.addEventListener('DOMContentLoaded', function() {
  // Gestion de la recherche d'emails
  const emailSearchInput = document.getElementById('emailSearchInput');
  if (emailSearchInput) {
    emailSearchInput.addEventListener('input', function() {
      const query = this.value.trim();

      if (query.length === 0) {
        hideEmailSuggestions();
        return;
      }

      const suggestions = searchEmails(query);
      showEmailSuggestions(suggestions);
    });

    emailSearchInput.addEventListener('blur', hideEmailSuggestions);

    // Gestion de la sélection par clavier
    emailSearchInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        const query = this.value.trim();

        if (!query) return;

        // Vérifier s'il y a plusieurs emails séparés par des virgules
        if (query.includes(',')) {
          // Traitement de plusieurs emails
          const emails = query.split(',');
          let addedCount = 0;
          let invalidEmails = [];
          let duplicateEmails = [];

          emails.forEach(email => {
            const cleanEmail = email.trim();
            if (!cleanEmail) return;

            if (isValidEmail(cleanEmail)) {
              if (selectedRecipients.find(r => r.email === cleanEmail)) {
                duplicateEmails.push(cleanEmail);
              } else {
                addRecipient(cleanEmail, 'Email externe', null);
                addedCount++;
              }
            } else {
              invalidEmails.push(cleanEmail);
            }
          });

          // Feedback à l'utilisateur
          let message = '';
          if (invalidEmails.length > 0) {
            message += `\n❌ ${invalidEmails.length} email(s) invalide(s): ${invalidEmails.join(', ')}`;
          }
          if (duplicateEmails.length > 0) {
            message += `\n⚠️ ${duplicateEmails.length} email(s) déjà sélectionné(s): ${duplicateEmails.join(', ')}`;
          }

          if (message) {
            alert(message);
          }

          this.value = '';
          hideEmailSuggestions();

        } else {
          // Traitement d'un seul email
          if (isValidEmail(query)) {
            if (selectedRecipients.find(r => r.email === query)) {
              alert('Cet email est déjà sélectionné.');
            } else {
              addRecipient(query, 'Email externe', null);
              this.value = '';
              hideEmailSuggestions();
            }
          } else {
            // Sinon, sélectionner la première suggestion si elle existe
            const firstSuggestion = document.querySelector('.suggestion-item');
            if (firstSuggestion) {
              firstSuggestion.click();
            } else {
              alert('Email invalide et aucune suggestion trouvée.');
            }
          }
        }
      }
    });
  }

  // Gestion des clics sur les suggestions
  document.addEventListener('click', function(e) {
    if (e.target.closest('.suggestion-item')) {
      const suggestionItem = e.target.closest('.suggestion-item');
      const email = suggestionItem.dataset.email;
      const name = suggestionItem.dataset.name;
      const id = suggestionItem.dataset.id;

      addRecipient(email, name, id);

      document.getElementById('emailSearchInput').value = '';
      hideEmailSuggestions();
    }
  });

  // Sélectionner/désélectionner tous les destinataires
  const selectAllBtn = document.getElementById('selectAllBtn');
  const clearAllBtn = document.getElementById('clearAllBtn');

  if (selectAllBtn) {
    selectAllBtn.addEventListener('click', function() {
      availableEmails.forEach(person => {
        addRecipient(person.email, person.name, person.id);
      });
    });
  }

  if (clearAllBtn) {
    clearAllBtn.addEventListener('click', function() {
      selectedRecipients = [];
      updateSelectedRecipientsDisplay();
      updateRecipientsCounter();
      updateHiddenInput();
    });
  }

  // Gestion de la soumission du formulaire de mail
  const mailForm = document.getElementById('fMail');
  if (mailForm) {
    mailForm.addEventListener('submit', function(e) {
      e.preventDefault();

      if (selectedRecipients.length === 0) {
        alert('Veuillez sélectionner au moins un destinataire.');
        return;
      }

      // Récupérer le contenu de TinyMCE
      const description = tinymce.get('mailDescription') ? tinymce.get('mailDescription').getContent() : '';

      // Préparer les données à envoyer
      const mailData = {
        recipients: selectedRecipients,
        subject: document.getElementById('mailSubject').value,
        mail_title: document.getElementById('mailTitle').value,
        description: description
      };

      // Créer un formulaire caché pour transmettre les données
      const hiddenForm = document.createElement('form');
      hiddenForm.method = 'POST';
      hiddenForm.action = 'send-mail.php';
      hiddenForm.style.display = 'none';

      // Ajouter les données au formulaire
      const dataInput = document.createElement('input');
      dataInput.type = 'hidden';
      dataInput.name = 'mail_data';
      dataInput.value = JSON.stringify(mailData);
      hiddenForm.appendChild(dataInput);

      document.body.appendChild(hiddenForm);
      hiddenForm.submit();
    });
  }
});

// ─── Event delegation (CSP-compatible) ───
document.addEventListener('click', function(e) {
  var el = e.target.closest('[data-action="remove-recipient"]');
  if (el && typeof removeRecipient === 'function') removeRecipient(el.dataset.email);
});
</script>
</body>
</html>
