<?php
require '../src/core/config.php';
require_once '../src/security/csrf.php';
requireRole(['admin']);
$role = currentRole();
require __DIR__ . '/../src/partials/navbar-data.php';

// Permissions par défaut actuelles (chargées depuis setting.role_permissions ou hardcodées)
$rolePermsDefault = [
    'user'   => defaultPermissions('user'),
    'viewer' => defaultPermissions('viewer'),
    'saisie' => defaultPermissions('saisie'),
];
$hasRolePermsCol = false;
try { $pdo->query('SELECT role_permissions FROM setting LIMIT 0'); $hasRolePermsCol = true; }
catch (PDOException $e) {}

/**
 * Rend une cellule de la matrice des droits par rôle.
 * - admin : toujours verrouillé (✓ + cadenas)
 * - user / viewer / saisie : toujours cliquable
 *
 * - $role: 'admin' | 'user' | 'viewer' | 'saisie'
 * - $type: 'pages' | 'actions'
 * - $key:  ex 'timeline', 'dashboard.create_registration', ...
 */
function permCell(string $role, string $type, string $key, array $rolePermsDefault, bool $hasCol): string {
    // Admin : toujours verrouillé avec cadenas
    if ($role === 'admin') {
        return '<td class="text-center text-success" title="Admin a toujours tout accès">'
             . '✓ <i class="bi bi-lock-fill text-muted small"></i></td>';
    }

    // Vérifier l'état actuel
    $list   = $rolePermsDefault[$role][$type] ?? [];
    $active = in_array($key, $list, true);

    $cls      = $active ? 'perm-cell perm-on' : 'perm-cell perm-off';
    $icon     = $active ? '✓' : '○';
    $title    = $hasCol ? 'Cliquer pour basculer' : "Lancez update.php pour activer l'édition";
    $disabled = $hasCol ? '' : ' perm-disabled';
    return '<td class="text-center ' . $cls . $disabled
        . '" data-role="' . htmlspecialchars($role) . '" data-type="' . $type
        . '" data-key="' . htmlspecialchars($key) . '" data-active="' . ($active ? '1' : '0')
        . '" title="' . $title . '">' . $icon . '</td>';
}
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
  #tblUsers tbody tr:hover { background: var(--accent-soft); }

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
    #tblUsers td:nth-child(6),
    #tblUsers th:nth-child(6),
    #tblUsers td:nth-child(7),
    #tblUsers th:nth-child(7) {
      display: none;
    }
  }

  /* ═══ Top bar for new user button ═══ */
  .users-toolbar { display: flex; justify-content: flex-end; margin-bottom: 1rem; }

  /* ═══ Btn rose ═══ */
  .btn-rose{
    background:linear-gradient(135deg,var(--primary, #f42182),var(--primary-hover, #db2777))!important;
    color:var(--primary-text, #fff)!important;
    border:none!important;
  }
  .btn-rose:hover,
  .btn-rose:focus{
    background:linear-gradient(135deg,var(--primary, #f42182),var(--primary-hover, #be185d))!important;
    color:var(--primary-text, #fff)!important;
  }

  /* ═══ Cellules de permission cliquables ═══ */
  .perm-cell {
    cursor: pointer;
    user-select: none;
    transition: background-color .15s, transform .1s;
    font-size: 1.05rem;
    font-weight: 600;
    position: relative;
  }
  .perm-cell:hover:not(.perm-disabled) {
    background-color: var(--accent-soft);
    transform: scale(1.05);
  }
  .perm-cell.perm-on  { color: #16a34a; background: color-mix(in srgb, var(--ok) 12%, var(--surface)); }
  .perm-cell.perm-off { color: var(--ink-faint); background-color: var(--surface-2); }
  .perm-cell.perm-disabled {
    cursor: not-allowed;
    opacity: .5;
  }
  .perm-cell.perm-saving {
    pointer-events: none;
    opacity: .5;
  }
  .perm-cell.perm-flash {
    animation: permFlash .6s ease;
  }
  @keyframes permFlash {
    0%   { background: color-mix(in srgb, var(--warn) 15%, var(--surface)); }
    100% { background-color: inherit; }
  }
</style>
</head>
<body>
<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>

<div>
  <h1 class="mb-3 fw-bold"><i class="bi bi-people me-2"></i>Utilisateurs & Droits</h1>

  <div class="users-toolbar">
    <button class="btn btn-rose" data-bs-toggle="modal" data-bs-target="#createUserModal">
      <i class="bi bi-plus-lg me-1"></i>Nouvel utilisateur
    </button>
  </div>
  <div class="table-responsive">
    <table id="tblUsers" class="table fer-table table-sm w-100"></table>
  </div>

  <!-- ═══ Tableau interactif des droits par rôle ═══ -->
  <div class="card-dashboard p-3 mt-4">
    <h2 class="h5 fw-bold mb-3"><i class="bi bi-shield-lock me-2"></i>Droits par rôle</h2>
    <p class="text-muted small mb-2">
      <strong>Cliquez sur n'importe quelle cellule</strong> des colonnes <span class="badge bg-primary">User</span>,
      <span class="badge bg-info text-dark">Viewer</span> et <span class="badge bg-secondary">Saisie</span>
      pour activer ou désactiver une permission. Seule la colonne <span class="badge bg-danger">Admin</span>
      est verrouillée (admin a toujours tout accès).
      <br>L'accès à une <strong>page</strong> donne uniquement la lecture. Les <strong>actions</strong>
      (boutons supprimer, créer, vider, etc.) sont contrôlées séparément.
    </p>
    <?php if (!$hasRolePermsCol): ?>
      <div class="alert alert-warning small py-2 mb-2">
        <i class="bi bi-exclamation-triangle me-1"></i>
        La colonne <code>setting.role_permissions</code> n'existe pas. Lancez <a href="../update.php"><strong>update.php</strong></a>
        pour activer la modification des droits par défaut depuis ce tableau.
      </div>
    <?php endif; ?>
    <div id="rolePermsFlash" class="alert alert-success small py-2 mb-2" style="display:none"></div>
    <div class="table-responsive">
      <table class="table table-bordered table-sm align-middle mb-0" id="rolePermsTable">
        <thead class="table-light">
          <tr>
            <th>Fonctionnalité</th>
            <th class="text-center"><span class="badge bg-danger">Admin</span></th>
            <th class="text-center"><span class="badge bg-primary">User</span></th>
            <th class="text-center"><span class="badge bg-info text-dark">Viewer</span></th>
            <th class="text-center"><span class="badge bg-secondary">Saisie</span></th>
          </tr>
        </thead>
        <tbody>

          <tr><td colspan="5" class="bg-light fw-bold small text-uppercase">Accès aux pages (lecture seule)</td></tr>

          <?php
          $allPages = [
            'dashboard'     => 'Tableau de bord (inscriptions)',
            'timeline'      => 'Timeline',
            'news'          => 'Actualités',
            'partners'      => 'Partenaires',
            'albums'        => 'Albums',
            'stats'         => 'Statistiques',
            'setting'       => 'Réglages',
            'mail-settings' => 'Paramètres mail',
            'qr_code'       => 'QR Code',
            'connexions'    => 'Connexions',
            'logs'          => 'Logs',
            'page_stats'    => 'Visites',
            'assistant'     => 'Assistant virtuel / FAQ',
          ];
          foreach ($allPages as $k => $label): ?>
          <tr>
            <td><?= $label ?></td>
            <?= permCell('admin',  'pages', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('user',   'pages', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('viewer', 'pages', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('saisie', 'pages', $k, $rolePermsDefault, $hasRolePermsCol) ?>
          </tr>
          <?php endforeach; ?>

          <tr><td colspan="5" class="bg-light fw-bold small text-uppercase">Actions sur les inscrits (Dashboard)</td></tr>

          <?php
          $dashActions = [
            'dashboard.create_registration' => 'Créer un inscrit',
            'dashboard.bulk_create'         => 'Ajout multiple (saisie en lot, ex. entreprise)',
            'dashboard.edit_registration'   => 'Modifier un inscrit',
            'dashboard.delete_registration' => 'Supprimer un inscrit',
            'dashboard.archive'             => 'Archiver l\'année en cours',
            // dashboard.import_excel : déplacé dans le groupe « Réglages » (l'import
            // manuel vit désormais dans l'onglet Réglages → Import AssoConnect).
            'dashboard.export_excel'        => 'Exporter en Excel',
            'dashboard.scan_qr'             => 'Scanner QR + remise des T-shirts (mode bénévole)',
            // Espace coureur — écrans livrés au lot 4. Décochés par défaut pour
            // user / viewer / saisie : ces écrans exposent les adresses et les
            // appareils de confiance des coureurs.
            'dashboard.participants'        => 'Comptes coureurs (voir, désactiver, révoquer les appareils)',
            'dashboard.transfers'           => 'Transferts d\'inscription (forcer ou annuler)',
            'dashboard.depart'              => 'Donner le départ de la course (recalcule tous les temps, notifie les coureurs)',
          ];
          foreach ($dashActions as $k => $label): ?>
          <tr>
            <td><?= $label ?></td>
            <?= permCell('admin',  'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('user',   'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('viewer', 'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('saisie', 'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
          </tr>
          <?php endforeach; ?>

          <?php
          // Actions de contenu — granularité par page (création / modification / suppression)
          $contentBlocks = [
            'Actions sur la Timeline'    => ['timeline.create','timeline.edit','timeline.trash','timeline.delete'],
            'Actions sur les Actualités' => ['news.create','news.edit','news.trash','news.delete'],
            'Actions sur les Partenaires'=> ['partners.create','partners.edit','partners.trash','partners.delete'],
            'Actions sur les Albums'     => ['albums.create','albums.edit','albums.trash','albums.delete'],
          ];
          $contentLabels = [
            'create' => 'Création',
            'edit'   => 'Modification',
            'trash'  => 'Corbeille',
            'delete' => 'Suppression',
          ];
          foreach ($contentBlocks as $sectionTitle => $actions): ?>
          <tr><td colspan="5" class="bg-light fw-bold small text-uppercase"><?= $sectionTitle ?></td></tr>
          <?php foreach ($actions as $k):
            $verb = explode('.', $k)[1];
            $label = $contentLabels[$verb] ?? $verb;
          ?>
          <tr>
            <td><?= $label ?></td>
            <?= permCell('admin',  'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('user',   'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('viewer', 'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('saisie', 'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
          </tr>
          <?php endforeach; ?>
          <?php endforeach; ?>

          <tr><td colspan="5" class="bg-light fw-bold small text-uppercase">Journal d'activité des contenus</td></tr>
          <tr>
            <td>Voir les logs (Timeline, Actualités, Partenaires, Albums)</td>
            <?= permCell('admin',  'actions', 'content.logs.view', $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('user',   'actions', 'content.logs.view', $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('viewer', 'actions', 'content.logs.view', $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('saisie', 'actions', 'content.logs.view', $rolePermsDefault, $hasRolePermsCol) ?>
          </tr>

          <tr><td colspan="5" class="bg-light fw-bold small text-uppercase">Actions sur les pages d'administration</td></tr>

          <?php
          $adminActions = [
            'settings.write'    => 'Modifier les Réglages',
            'mail.write'        => 'Modifier les paramètres mail (Template / Google / Notifications)',
            'mail.send'         => 'Envoyer des mails (onglet « Envoi de mail »)',
            'mail.newsletter'   => 'Gérer les abonnés newsletter (voir / supprimer)',
            'qrcode.write'      => 'Créer / supprimer des QR Codes',
            'connexions.write'  => 'Bannir / débannir IP, révoquer appareils (Connexions)',
            'logs.write'        => 'Vider les fichiers de logs',
            'assistant.write'   => 'Modifier l\'Assistant virtuel / FAQ',
          ];
          foreach ($adminActions as $k => $label): ?>
          <tr>
            <td><?= $label ?></td>
            <?= permCell('admin',  'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('user',   'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('viewer', 'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('saisie', 'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
          </tr>
          <?php endforeach; ?>

          <tr><td colspan="5" class="bg-light fw-bold small text-uppercase">
            Accès bénévoles — Remise T-shirts
            <span class="text-muted fw-normal text-lowercase" style="font-size:11px;">
              — « Voir la page » = lecture (Dernières remises) ; les autres droits débloquent chaque bloc
            </span>
          </td></tr>

          <?php
          // [type, clé, libellé] : « Voir la page » est une permission de PAGE (lecture),
          // les autres sont des ACTIONS qui débloquent chaque bloc de la page.
          $tshirtPerms = [
            ['pages',   'tshirt_access',                'Voir la page (uniquement « Dernières remises »)'],
            ['actions', 'tshirt_access.manage',         'Gérer l\'état de l\'accès (ON/OFF, token, expiration, QR)'],
            ['actions', 'tshirt_access.approve',        'Valider / refuser les demandes en attente'],
            ['actions', 'tshirt_access.devices_view',   'Voir les bénévoles connectés'],
            ['actions', 'tshirt_access.devices_revoke', 'Révoquer un bénévole connecté'],
          ];
          foreach ($tshirtPerms as [$ptype, $pkey, $plabel]): ?>
          <tr>
            <td><?= $plabel ?></td>
            <?= permCell('admin',  $ptype, $pkey, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('user',   $ptype, $pkey, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('viewer', $ptype, $pkey, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('saisie', $ptype, $pkey, $rolePermsDefault, $hasRolePermsCol) ?>
          </tr>
          <?php endforeach; ?>

          <tr><td colspan="5" class="bg-light fw-bold small text-uppercase">
            Onglets des Réglages (sous-permissions)
            <span class="text-muted fw-normal text-lowercase" style="font-size:11px;">
              — nécessite <strong>« Modifier les Réglages »</strong> coché ci-dessus
            </span>
          </td></tr>

          <?php
          $settingsTabActions = [
            'settings.tab.personnalisation' => 'Personnalisation',
            'settings.tab.accueil'          => 'Accueil',
            // ⚠️ TOUT ONGLET AJOUTÉ DOIT FIGURER DANS LES DEUX LISTES DE CE
            // FICHIER : celle-ci et celle du script, plus bas. Absent, il reste
            // invisible dans l'éditeur de droits — donc impossible à accorder à
            // un rôle autre qu'administrateur, sans que rien ne l'explique.
            'settings.tab.course'           => 'Course (date, lieu, horaires, chronométrage)',
            'settings.tab.inscription'      => 'Inscription',
            'settings.tab.parcours'         => 'Parcours',
            'settings.tab.reglementation'   => 'Reglementation',
            'settings.tab.legal'            => 'Pages légales',
            'settings.tab.formulaire'       => 'Formulaire',
            'settings.tab.import'           => 'Import Excel',
            'settings.tab.import_auto'      => 'Import AssoConnect (onglet + automatisation)',
            'dashboard.import_excel'        => 'Import AssoConnect : import manuel (bouton)',
            'settings.tab.maintenance'      => 'Maintenance',
            'settings.tab.api'              => 'API et application mobile',
          ];
          foreach ($settingsTabActions as $k => $label): ?>
          <tr>
            <td><?= $label ?></td>
            <?= permCell('admin',  'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('user',   'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('viewer', 'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('saisie', 'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
          </tr>
          <?php endforeach; ?>

          <tr><td colspan="5" class="bg-light fw-bold small text-uppercase">
            Cartes de l'onglet Accueil
            <span class="text-muted fw-normal text-lowercase" style="font-size:11px;">
              — nécessite <strong>« Modifier les Réglages »</strong> + <strong>« Accueil »</strong> cochés
            </span>
          </td></tr>

          <?php
          $accueilCardActions = [
            'settings.accueil.params' => 'Paramètres page accueil (liens, date, Flash Info)',
            'settings.accueil.custom' => 'Mise en page de l\'accueil (éditeur visuel)',
          ];
          foreach ($accueilCardActions as $k => $label): ?>
          <tr>
            <td><?= $label ?></td>
            <?= permCell('admin',  'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('user',   'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('viewer', 'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('saisie', 'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
          </tr>
          <?php endforeach; ?>

          <tr><td colspan="5" class="bg-light fw-bold small text-uppercase">
            Cartes de l'onglet Inscription
            <span class="text-muted fw-normal text-lowercase" style="font-size:11px;">
              — nécessite <strong>« Modifier les Réglages »</strong> + <strong>« Inscription »</strong> cochés
            </span>
          </td></tr>

          <?php
          $inscriptionCardActions = [
            'settings.inscription.header'      => 'En-tête du site d\'inscription',
            'settings.inscription.params'      => 'Paramètres d\'inscription',
            'settings.inscription.assoconnect' => 'Liaison AssoConnect',
            'settings.inscription.cspdomains'  => 'Domaines autorisés AssoConnect (CSP)',
          ];
          foreach ($inscriptionCardActions as $k => $label): ?>
          <tr>
            <td><?= $label ?></td>
            <?= permCell('admin',  'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('user',   'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('viewer', 'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
            <?= permCell('saisie', 'actions', $k, $rolePermsDefault, $hasRolePermsCol) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="text-muted small mt-2 mb-0">
        <strong>Légende :</strong> ✓ = autorisé · ○ = désactivé · <i class="bi bi-lock-fill"></i> = verrouillé (admin uniquement).<br>
        Donner accès à une page sans cocher l'action correspondante = lecture seule (les boutons d'action sont masqués).
        <br>Les modifications faites ici changent les défauts ; elles s'appliquent immédiatement à tous les utilisateurs sans permissions personnalisées.
      </p>
    </div>
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
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Modifier l'utilisateur</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="fEditUser" class="row g-3">
          <input type="hidden" name="id" id="editUserId">
          <input type="hidden" name="permissions" id="editUserPermissions">
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

          <!-- ═══ Section Permissions ═══ -->
          <div class="col-12" id="permsSection" style="display:none">
            <hr>
            <h6 class="fw-bold mb-2"><i class="bi bi-shield-check me-1"></i>Permissions</h6>

            <!-- ─── État "Admin" : message indiquant l'accès total, sans bouton ─── -->
            <div id="permsAdminMode" style="display:none">
              <div class="alert alert-light border d-flex align-items-center py-2 mb-0">
                <i class="bi bi-shield-fill-check text-success me-2 fs-5"></i>
                <div class="small">
                  Ce compte est <strong>administrateur</strong> :
                  il a <strong>tous les accès</strong> à toutes les pages et toutes les actions.
                  Aucune permission n'est modifiable.
                </div>
              </div>
            </div>

            <!-- ─── État "Défaut du rôle" : pas de panneau, juste un message + bouton ─── -->
            <div id="permsDefaultMode">
              <div class="alert alert-light border d-flex justify-content-between align-items-center py-2 mb-0">
                <div class="small">
                  <i class="bi bi-shield-check text-success me-1"></i>
                  Cet utilisateur utilise les <strong>défauts du rôle</strong>
                  (modifiables dans le tableau « Droits par rôle » plus haut).
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary ms-2" id="btnEnableCustom">
                  <i class="bi bi-pencil-square me-1"></i>Personnaliser
                </button>
              </div>
            </div>

            <!-- ─── État "Personnalisé" : panneau de cases à cocher ─── -->
            <div id="permsCustomMode" style="display:none">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="badge bg-primary"><i class="bi bi-person-gear me-1"></i>Permissions personnalisées</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnResetPerms">
                  <i class="bi bi-arrow-counterclockwise me-1"></i>Revenir aux défauts du rôle
                </button>
              </div>
              <p class="text-muted small mb-2" id="permsHelpText"></p>

              <div class="mb-3">
                <label class="form-label fw-semibold small text-uppercase">Pages accessibles</label>
                <div class="row g-2" id="permsPagesContainer"></div>
              </div>

              <div class="mb-2" id="permsActionsWrapper">
                <label class="form-label fw-semibold small text-uppercase">Actions autorisées</label>
                <div class="row g-2" id="permsActionsContainer"></div>
              </div>
            </div>
          </div>

          <div class="col-12 text-end">
            <button type="submit" class="btn btn-primary">Enregistrer</button>
          </div>
        </form>
        <hr>
        <div class="d-flex flex-wrap gap-2">
          <button id="btnResetPwd" class="btn btn-outline-warning btn-sm"><i class="bi bi-key me-1"></i>Reinitialiser MDP</button>
          <button id="btnClear2fa" class="btn btn-outline-warning btn-sm" style="display:none"><i class="bi bi-shield-slash me-1"></i>Supprimer l'authentification forte</button>
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

<?php include __DIR__ . '/../src/partials/admin-footer.php'; ?>

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

/* ══ Sections repliables du tableau des droits (repliées par défaut) ════ */
(function () {
  var table = document.getElementById('rolePermsTable');
  if (!table) return;
  var rows = Array.prototype.slice.call(table.querySelectorAll('tbody > tr'));
  var sections = [];
  rows.forEach(function (tr) {
    var head = tr.querySelector('td[colspan]');
    if (head) {
      tr.style.cursor = 'pointer';
      tr.style.userSelect = 'none';
      var arrow = document.createElement('i');
      arrow.className = 'bi bi-chevron-down perm-arrow me-2';
      arrow.style.transition = 'transform .2s';
      arrow.style.fontSize = '11px';
      arrow.style.display = 'inline-block'; // requis pour que rotate() s'applique (inline = ignoré)
      head.insertBefore(arrow, head.firstChild);
      sections.push({ tr: tr, arrow: arrow, members: [], open: false });
    } else if (sections.length) {
      sections[sections.length - 1].members.push(tr);
    }
  });
  function apply(s) {
    s.members.forEach(function (m) { m.style.display = s.open ? '' : 'none'; });
    s.arrow.style.transform = s.open ? '' : 'rotate(-90deg)';
  }
  sections.forEach(function (s) {
    apply(s); // replié par défaut
    s.tr.addEventListener('click', function () { s.open = !s.open; apply(s); });
  });
})();

/* ══ Tableau interactif des droits par rôle ════ */
(function () {
  const flashEl = document.getElementById('rolePermsFlash');
  function showFlash(msg, isError) {
    // Toast en bas à droite (cohérent avec le reste des enregistrements).
    if (window.showToast) { window.showToast(msg, isError ? 'danger' : 'success'); return; }
    // Repli si le système de toast n'est pas chargé.
    if (!flashEl) return;
    flashEl.textContent = msg;
    flashEl.className = 'alert small py-2 mb-2 ' + (isError ? 'alert-danger' : 'alert-success');
    flashEl.style.display = '';
    clearTimeout(flashEl._t);
    flashEl._t = setTimeout(() => { flashEl.style.display = 'none'; }, 2500);
  }

  document.querySelectorAll('#rolePermsTable .perm-cell').forEach(function (cell) {
    if (cell.classList.contains('perm-disabled')) return;
    cell.addEventListener('click', function () {
      if (cell.classList.contains('perm-saving')) return;
      const role  = cell.dataset.role;
      const type  = cell.dataset.type;
      const key   = cell.dataset.key;
      const cur   = cell.dataset.active === '1';
      const next  = !cur;

      cell.classList.add('perm-saving');

      fetch('../admin-api.php?route=role-permissions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
        body: JSON.stringify({ role, type, key, value: next })
      })
      .then(r => r.json())
      .then(j => {
        cell.classList.remove('perm-saving');
        if (j.ok) {
          cell.dataset.active = next ? '1' : '0';
          cell.classList.toggle('perm-on', next);
          cell.classList.toggle('perm-off', !next);
          cell.textContent = next ? '✓' : '○';
          cell.classList.add('perm-flash');
          setTimeout(() => cell.classList.remove('perm-flash'), 600);

          // ▶︎ Synchroniser DEFAULT_PERMS pour que le modal d'édition utilisateur
          //   utilise les nouveaux défauts plutôt que des valeurs périmées
          if (typeof DEFAULT_PERMS !== 'undefined' && DEFAULT_PERMS[role]) {
            const list = DEFAULT_PERMS[role][type] || [];
            const idx  = list.indexOf(key);
            if (next && idx === -1) list.push(key);
            if (!next && idx !== -1) list.splice(idx, 1);
            DEFAULT_PERMS[role][type] = list;
          }

          showFlash('Permission mise à jour pour le rôle « ' + role + ' »', false);
        } else {
          showFlash('Erreur : ' + (j.err || 'inconnue'), true);
        }
      })
      .catch(err => {
        cell.classList.remove('perm-saving');
        showFlash('Erreur réseau : ' + err.message, true);
      });
    });
  });
})();

/* ══ Permissions catalogue (must match config.php) ════ */
const ALL_PAGES = [
  { key: 'dashboard',     label: 'Tableau de bord' },
  { key: 'timeline',      label: 'Timeline' },
  { key: 'news',          label: 'Actualit\u00e9s' },
  { key: 'partners',      label: 'Partenaires' },
  { key: 'albums',        label: 'Albums' },
  { key: 'stats',         label: 'Statistiques' },
  { key: 'setting',       label: 'R\u00e9glages' },
  { key: 'mail-settings', label: 'Param\u00e8tres mail' },
  { key: 'qr_code',       label: 'QR Code' },
  { key: 'tshirt_access', label: 'Accès bénévoles (Remise T-shirts)' },
  { key: 'connexions',    label: 'Connexions' },
  { key: 'logs',          label: 'Logs' },
  { key: 'page_stats',    label: 'Visites' },
  { key: 'assistant',     label: 'Assistant virtuel / FAQ' },
];
const ALL_ACTIONS = [
  { key: 'dashboard.create_registration', label: 'Cr\u00e9er un inscrit',     group: 'dashboard' },
  { key: 'dashboard.bulk_create',         label: 'Ajout multiple',            group: 'dashboard' },
  { key: 'dashboard.edit_registration',   label: 'Modifier un inscrit',       group: 'dashboard' },
  { key: 'dashboard.delete_registration', label: 'Supprimer un inscrit',      group: 'dashboard' },
  { key: 'dashboard.archive',             label: 'Archiver l\u0027ann\u00e9e',group: 'dashboard' },
  { key: 'dashboard.export_excel',        label: 'Exporter Excel',            group: 'dashboard' },
  { key: 'dashboard.scan_qr',             label: 'Scanner QR + remise T-shirts', group: 'dashboard' },
  { key: 'dashboard.participants',        label: 'Comptes coureurs',          group: 'dashboard' },
  { key: 'dashboard.transfers',           label: 'Transferts d\u0027inscription', group: 'dashboard' },
  { key: 'dashboard.depart',              label: 'Donner le départ de la course', group: 'dashboard' },
  // Timeline
  { key: 'timeline.create',               label: 'Timeline : cr\u00e9er',     group: 'timeline' },
  { key: 'timeline.edit',                 label: 'Timeline : modifier',       group: 'timeline' },
  { key: 'timeline.trash',                label: 'Timeline : corbeille',      group: 'timeline' },
  { key: 'timeline.delete',               label: 'Timeline : supprimer',      group: 'timeline' },
  // Actualités
  { key: 'news.create',                   label: 'Actualit\u00e9s : cr\u00e9er',  group: 'news' },
  { key: 'news.edit',                     label: 'Actualit\u00e9s : modifier',    group: 'news' },
  { key: 'news.trash',                    label: 'Actualit\u00e9s : corbeille',   group: 'news' },
  { key: 'news.delete',                   label: 'Actualit\u00e9s : supprimer',   group: 'news' },
  // Partenaires
  { key: 'partners.create',               label: 'Partenaires : cr\u00e9er',  group: 'partners' },
  { key: 'partners.edit',                 label: 'Partenaires : modifier',    group: 'partners' },
  { key: 'partners.trash',                label: 'Partenaires : corbeille',   group: 'partners' },
  { key: 'partners.delete',               label: 'Partenaires : supprimer',   group: 'partners' },
  // Albums
  { key: 'albums.create',                 label: 'Albums : cr\u00e9er',       group: 'albums' },
  { key: 'albums.edit',                   label: 'Albums : modifier',         group: 'albums' },
  { key: 'albums.trash',                  label: 'Albums : corbeille',        group: 'albums' },
  { key: 'albums.delete',                 label: 'Albums : supprimer',        group: 'albums' },
  // Journal d'activité des contenus
  { key: 'content.logs.view',             label: 'Voir les logs des contenus', group: 'content' },
  // Pages d'admin
  { key: 'settings.write',                label: 'Modifier les R\u00e9glages',     group: 'admin' },
  { key: 'mail.write',                    label: 'Modifier les param\u00e8tres mail',group: 'admin' },
  { key: 'mail.send',                     label: 'Envoyer des mails',              group: 'admin' },
  { key: 'mail.newsletter',               label: 'G\u00e9rer abonn\u00e9s newsletter', group: 'admin' },
  { key: 'qrcode.write',                  label: 'Cr\u00e9er/supprimer QR',        group: 'admin' },
  { key: 'connexions.write',              label: 'G\u00e9rer connexions',          group: 'admin' },
  { key: 'logs.write',                    label: 'Vider les logs',                 group: 'admin' },
  { key: 'assistant.write',               label: 'Modifier l\'Assistant / FAQ',   group: 'admin' },
  // Acc\u00e8s b\u00e9n\u00e9voles (Remise T-shirts)
  { key: 'tshirt_access.manage',          label: 'B\u00e9n\u00e9voles : g\u00e9rer acc\u00e8s (ON/OFF, token, expiration)', group: 'tshirt' },
  { key: 'tshirt_access.approve',         label: 'B\u00e9n\u00e9voles : valider / refuser les demandes',     group: 'tshirt' },
  { key: 'tshirt_access.devices_view',    label: 'B\u00e9n\u00e9voles : voir les connect\u00e9s',                 group: 'tshirt' },
  { key: 'tshirt_access.devices_revoke',  label: 'B\u00e9n\u00e9voles : r\u00e9voquer un connect\u00e9',               group: 'tshirt' },
  // Sous-onglets des Réglages
  { key: 'settings.tab.personnalisation', label: 'Réglages : Personnalisation', group: 'settings' },
  { key: 'settings.tab.accueil',          label: 'Réglages : Accueil',           group: 'settings' },
  { key: 'settings.tab.course',           label: 'Réglages : Course',            group: 'settings' },
  { key: 'settings.tab.inscription',      label: 'Réglages : Inscription',       group: 'settings' },
  { key: 'settings.tab.parcours',         label: 'Réglages : Parcours',          group: 'settings' },
  { key: 'settings.tab.reglementation',   label: 'Réglages : Reglementation',    group: 'settings' },
  { key: 'settings.tab.legal',            label: 'Réglages : Pages légales',     group: 'settings' },
  { key: 'settings.tab.formulaire',       label: 'Réglages : Formulaire',        group: 'settings' },
  { key: 'settings.tab.import',           label: 'Réglages : Import Excel',      group: 'settings' },
  { key: 'settings.tab.import_auto',      label: 'Réglages : Import AssoConnect (onglet + auto)', group: 'settings' },
  { key: 'dashboard.import_excel',        label: 'Réglages : Import AssoConnect — bouton manuel', group: 'settings' },
  { key: 'settings.tab.maintenance',      label: 'Réglages : Maintenance',       group: 'settings' },
  { key: 'settings.tab.api',              label: 'Réglages : API et application mobile', group: 'settings' },
  // Cartes de l'onglet Accueil
  { key: 'settings.accueil.params',       label: 'Accueil : Paramètres page',    group: 'settings' },
  { key: 'settings.accueil.custom',       label: 'Accueil : Mise en page (éditeur visuel)', group: 'settings' },
  // Cartes de l'onglet Inscription
  { key: 'settings.inscription.header',      label: 'Inscription : En-tête du site', group: 'settings' },
  { key: 'settings.inscription.params',      label: 'Inscription : Paramètres d\'inscription', group: 'settings' },
  { key: 'settings.inscription.assoconnect', label: 'Inscription : Liaison AssoConnect',   group: 'settings' },
  { key: 'settings.inscription.cspdomains',  label: 'Inscription : Domaines autorisés AssoConnect', group: 'settings' },
];
// Initialisé depuis PHP avec les vrais défauts (BDD ou hardcodés) — mis à jour en live
// quand l'admin modifie le tableau "Droits par rôle".
const DEFAULT_PERMS = <?= json_encode([
  'user'   => $rolePermsDefault['user'],
  'viewer' => $rolePermsDefault['viewer'],
  'saisie' => $rolePermsDefault['saisie'],
]) ?>;

/* ══ Users DataTable (init immediately) ════ */
// Pagination compacte type "1 ... 4 5 6 ... 12"
$.fn.dataTable.ext.pager.numbers_length = 7;

let usrTbl = $('#tblUsers').DataTable({
  ajax:{url:'../admin-api.php?route=users',dataSrc:''},
  columns: [
    { data: 'id', title: '#' },
    // \ud83d\udd12 [SEC-XSS] render.text() : \u00e9chappe l'email au rendu (d\u00e9fense en profondeur).
    { data: 'email', title: 'Email', render: $.fn.dataTable.render.text() },
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
    {
      data: null,
      title: 'MFA',
      className: 'text-center',
      orderable: false,
      render: function (row) {
        const totp = row.totp_enabled == 1;
        const pk   = (row.passkey_count || 0) > 0;
        if (!totp && !pk) {
          return '<span class="badge bg-light text-muted border">Aucune</span>';
        }
        let html = '';
        if (pk) {
          html += '<span class="badge bg-success" title="Cl\u00e9 d\'acc\u00e8s">'
                + '<i class="bi bi-fingerprint me-1"></i>Cl\u00e9 d\'acc\u00e8s</span>';
        }
        if (totp) {
          html += (html ? ' ' : '')
                + '<span class="badge bg-primary" title="Application d\'authentification">'
                + '<i class="bi bi-shield-lock me-1"></i>Authenticator</span>';
        }
        return html;
      }
    },
    { data: 'organisation', title: 'Organisation' },
    { data: 'created_at', title: 'Cr\u00e9\u00e9 le' }
  ],
  language: {
    emptyTable:    'Aucun utilisateur pour le moment.',
    zeroRecords:   'Aucun résultat.',
    loadingRecords:'Chargement…',
    search:        'Rechercher :',
    lengthMenu:    'Afficher _MENU_ utilisateurs',
    info:          '_START_ à _END_ sur _TOTAL_',
    infoEmpty:     '0 sur 0',
    infoFiltered:  '(filtré sur _MAX_)',
    paginate:      { first: '«', previous: '‹', next: '›', last: '»' }
  },
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

/* ══ Permissions UI helpers ════ */
function parseStoredPerms(raw) {
  if (!raw) return null;
  try {
    const p = typeof raw === 'string' ? JSON.parse(raw) : raw;
    if (p && Array.isArray(p.pages) && Array.isArray(p.actions)) return p;
  } catch (e) {}
  return null;
}

// État courant du modal
let _modalCustomMode = false; // true = panneau visible (perms perso) / false = panneau caché (défauts du rôle)
let _modalDirty      = false; // true si admin a fait une action qui change l'état (toggle perso, reset, perso → custom)

/**
 * Affiche le mode "admin" : message d'info, pas de bouton, pas de cases
 */
function showAdminMode() {
  document.getElementById('permsAdminMode').style.display   = '';
  document.getElementById('permsDefaultMode').style.display = 'none';
  document.getElementById('permsCustomMode').style.display  = 'none';
  _modalCustomMode = false;
}

/**
 * Affiche le mode "défaut du rôle" : panneau caché, juste un message + bouton "Personnaliser"
 */
function showDefaultMode() {
  document.getElementById('permsAdminMode').style.display   = 'none';
  document.getElementById('permsDefaultMode').style.display = '';
  document.getElementById('permsCustomMode').style.display  = 'none';
  _modalCustomMode = false;
}

/**
 * Affiche le mode "personnalisé" : panneau visible, cases à cocher pré-remplies
 */
function showCustomMode(role, currentPerms) {
  document.getElementById('permsAdminMode').style.display   = 'none';
  document.getElementById('permsDefaultMode').style.display = 'none';
  document.getElementById('permsCustomMode').style.display  = '';
  _modalCustomMode = true;

  const help = document.getElementById('permsHelpText');
  help.innerHTML = 'Cochez les <strong>pages accessibles</strong> (lecture) et les <strong>actions autoris\u00e9es</strong> (\u00e9criture). '
                 + 'L\u0027acc\u00e8s \u00e0 une page sans son action correspondante = lecture seule.';

  // Pages
  const pagesC = document.getElementById('permsPagesContainer');
  pagesC.innerHTML = '';
  ALL_PAGES.forEach(p => {
    const checked = currentPerms.pages.includes(p.key);
    pagesC.insertAdjacentHTML('beforeend', `
      <div class="col-md-4 col-sm-6">
        <div class="form-check">
          <input class="form-check-input perm-page" type="checkbox" id="perm_page_${p.key}"
            value="${p.key}" ${checked ? 'checked' : ''}>
          <label class="form-check-label" for="perm_page_${p.key}">${p.label}</label>
        </div>
      </div>`);
  });

  // Actions
  const actionsWrap = document.getElementById('permsActionsWrapper');
  const actionsC = document.getElementById('permsActionsContainer');
  actionsWrap.style.display = '';
  actionsC.innerHTML = '';
  ALL_ACTIONS.forEach(a => {
    const checked = currentPerms.actions.includes(a.key);
    actionsC.insertAdjacentHTML('beforeend', `
      <div class="col-md-4 col-sm-6">
        <div class="form-check">
          <input class="form-check-input perm-action" type="checkbox" id="perm_act_${a.key.replace(/\./g,'_')}"
            value="${a.key}" ${checked ? 'checked' : ''}>
          <label class="form-check-label" for="perm_act_${a.key.replace(/\./g,'_')}">${a.label}</label>
        </div>
      </div>`);
  });

  // Sync hidden field on any change
  const syncHidden = () => {
    const pages = Array.from(document.querySelectorAll('.perm-page:checked')).map(el => el.value);
    const actions = Array.from(document.querySelectorAll('.perm-action:checked')).map(el => el.value);
    document.getElementById('editUserPermissions').value = JSON.stringify({ pages, actions });
  };
  const onUserChange = () => {
    _modalDirty = true;
    syncHidden();
  };
  document.querySelectorAll('.perm-page, .perm-action').forEach(el => el.addEventListener('change', onUserChange));
  syncHidden();
}

/**
 * Point d'entrée appelé à l'ouverture du modal et au changement de rôle.
 * Décide s'il faut afficher le mode défaut (cas 1) ou le mode personnalisé (cas 2).
 *
 * @param {string} role
 * @param {object|null} storedPerms - permissions stockées en BDD (ou null si défauts)
 */
function renderPermsUI(role, storedPerms) {
  const section = document.getElementById('permsSection');
  // La carte Permissions est toujours visible (admin compris)
  section.style.display = '';

  if (role === 'admin') {
    // Admin : info "tous les accès", pas de bouton, pas de cases
    document.getElementById('editUserPermissions').value = '';
    showAdminMode();
    return;
  }

  if (storedPerms) {
    // L'utilisateur a déjà des permissions personnalisées : afficher le panneau
    showCustomMode(role, storedPerms);
  } else {
    // L'utilisateur utilise les défauts du rôle : cacher le panneau
    showDefaultMode();
  }
}

/* ── Changement de rôle ── */
document.getElementById('editUserRole').addEventListener('change', function () {
  const role = this.value;
  // Changement de rôle = on retombe sur les défauts du nouveau rôle (mode défaut)
  _modalDirty = true; // car le rôle a changé, il faut sauvegarder
  // On envoie "null" pour permissions afin de garantir qu'on hérite du nouveau rôle
  document.getElementById('editUserPermissions').value = 'null';
  renderPermsUI(role, null);
});

/* ── Bouton "Personnaliser" : passer du mode défaut au mode personnalisé ── */
document.getElementById('btnEnableCustom').addEventListener('click', function () {
  const role = document.getElementById('editUserRole').value;
  const defaults = DEFAULT_PERMS[role] || { pages: [], actions: [] };
  // Cloner pour ne pas muter DEFAULT_PERMS quand l'admin coche/décoche
  const seed = {
    pages:   Array.from(defaults.pages),
    actions: Array.from(defaults.actions),
  };
  _modalDirty = true; // dès qu'on personnalise, il faudra sauvegarder
  showCustomMode(role, seed);
  // Initialiser le hidden avec l'état actuel
  document.getElementById('editUserPermissions').value = JSON.stringify(seed);
});

/* ── Bouton "Revenir aux défauts du rôle" : repasser en mode défaut + reset BDD ── */
document.getElementById('btnResetPerms').addEventListener('click', function () {
  const role = document.getElementById('editUserRole').value;
  _modalDirty = true; // on doit dire au serveur de remettre à NULL
  document.getElementById('editUserPermissions').value = 'null';
  renderPermsUI(role, null);
});

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

  // Reset état dirty + hidden field à chaque ouverture
  _modalDirty = false;
  _modalCustomMode = false;
  document.getElementById('editUserPermissions').value = '';

  // Permissions stockées en BDD ? Si oui → mode custom. Sinon → mode défaut.
  const stored = parseStoredPerms(data.permissions);
  renderPermsUI(data.role, stored);

  // Toggle active button label
  const toggleBtn = document.getElementById('btnToggleActive');
  if (data.is_active == 1) {
    toggleBtn.innerHTML = '<i class="bi bi-pause-circle me-1"></i><span>Bloquer</span>';
  } else {
    toggleBtn.innerHTML = '<i class="bi bi-play-circle me-1"></i><span>Debloquer</span>';
  }

  // Bouton "Supprimer l'authentification forte" — visible seulement si MFA actif
  const hasMfa = (data.totp_enabled == 1) || ((data.passkey_count || 0) > 0);
  document.getElementById('btnClear2fa').style.display = hasMfa ? '' : 'none';

  new bootstrap.Modal('#editUserModal').show();
});

/* ══ Edit user form submit ════ */
$('#fEditUser').on('submit', function (e) {
  e.preventDefault();
  const fd = new FormData(this);

  // Si l'admin n'a rien modifié dans la section permissions, on n'envoie pas le champ
  // → l'utilisateur conserve son état actuel (NULL = défauts du rôle, ou ses perms perso)
  if (!_modalDirty) {
    fd.delete('permissions');
  }

  fetch('../admin-api.php?route=users', {
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

  fetch('../admin-api.php?route=users', {
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
        alert('Un lien de r\u00e9initialisation de mot de passe a \u00e9t\u00e9 envoy\u00e9 \u00e0 ' + currentEditUser.email + ' (valable 30 minutes).');
      }
      usrTbl.ajax.reload();
    } else {
      alert('Erreur : ' + (j.err || 'inconnue'));
    }
  });
});

/* ══ Clear strong authentication (edit modal) ════ */
document.getElementById('btnClear2fa').addEventListener('click', function () {
  if (!currentEditUser) return;
  if (!confirm('Supprimer toutes les authentifications fortes (clé d\'accès et application d\'authentification) de "'
      + currentEditUser.email + '" ?\n\nL\'utilisateur devra se reconnecter avec son mot de passe.')) return;

  fetch('../admin-api.php?route=users', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': _csrfToken },
    body: new URLSearchParams({ action: 'clear-2fa', id: currentEditUser.id })
  })
  .then(r => r.json())
  .then(j => {
    if (j.ok) {
      usrTbl.ajax.reload();
      bootstrap.Modal.getInstance(document.getElementById('editUserModal')).hide();
      alert('Authentification forte supprimée. Le compte se reconnecte désormais par mot de passe.');
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

  fetch('../admin-api.php?route=users', {
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

    fetch('../admin-api.php?route=users', {
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

  fetch('../admin-api.php?route=users', {
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
