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
<style>
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

  /* ═══ Btn rose ═══ */
  .btn-rose{
    background:linear-gradient(135deg,#F42182,#db2777)!important;
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

<div>
  <h1 class="mb-3 fw-bold"><i class="bi bi-people me-2"></i>Utilisateurs & Droits</h1>

  <div class="users-toolbar">
    <button class="btn btn-rose" data-bs-toggle="modal" data-bs-target="#createUserModal">
      <i class="bi bi-plus-lg me-1"></i>Nouvel utilisateur
    </button>
  </div>
  <div class="table-responsive">
    <table id="tblUsers" class="table table-sm w-100"></table>
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

/* ══ Auto-dismiss alerts ════ */
document.querySelectorAll('.auto-dismiss').forEach(function(alert) {
  var delay = parseInt(alert.dataset.dismissDelay) || 5000;
  setTimeout(function() {
    var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
    bsAlert.close();
  }, delay);
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

</script>
</body>
</html>
