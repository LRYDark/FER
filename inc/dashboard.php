<?php
require '../config/config.php';
require_once '../config/csrf.php';
requirePage('dashboard');
$role = currentRole();
$canCreateReg  = canDoAction('dashboard.create_registration');
$canBulkCreate = canDoAction('dashboard.bulk_create');
$canEditReg    = canDoAction('dashboard.edit_registration');
$canDeleteReg  = canDoAction('dashboard.delete_registration');
$canArchive    = canDoAction('dashboard.archive');
$canImportXls  = canDoAction('dashboard.import_excel');
$canExportXls  = canDoAction('dashboard.export_excel');
$canScanQr     = canDoAction('dashboard.scan_qr');
// Le mode "Remise T-shirts" est accessible si on peut scanner OU si on peut éditer
$canTshirtMode = $canScanQr || $canEditReg;

// Charger les données pour la navbar
require 'navbar-data.php';

$stmt = $pdo->prepare(
    'SELECT *
       FROM setting
      WHERE id = :id
      LIMIT 1');
$stmt->execute(['id' => 1]);

$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$assoconnectJs      = $data['assoconnect_js']     ?? null;
$assoconnectIframe  = $data['assoconnect_iframe'] ?? null;
$title  = $data['title']   ?? '';
$picture= $data['picture'] ?? '';  

$qrcode_mail_mode = $data['qrcode_mail_mode'] ?? 'none';
$qrcode_mail_limit = (int) ($data['qrcode_mail_limit'] ?? 0);
$highlightLimit = ($qrcode_mail_mode === 'first_x' && $qrcode_mail_limit > 0) ? $qrcode_mail_limit : 0;
$registration_fee = (float) ($data['registration_fee'] ?? 0);

// Champs dynamiques
require_once '../config/form_fields.php';
$adminFields = getActiveFields($pdo, 'admin');
// Tous les champs actifs (pour les colonnes DataTable)
$stmtAllFields = $pdo->prepare('SELECT * FROM forms WHERE active = 1 ORDER BY sort_order ASC');
$stmtAllFields->execute();
$allActiveFields = $stmtAllFields->fetchAll(PDO::FETCH_ASSOC);

// Champs pour le formulaire "Ajout multiple" (saisie en lot).
// Les champs partagés (entreprise/email/paiement_mode) sont gérés à part dans
// l'en-tête du formulaire bulk, on les exclut donc des champs "par personne".
$bulkSharedCols = ['entreprise', 'email', 'paiement_mode'];
$bulkRowFields = [];
if ($canBulkCreate) {
    // Vérifie que la migration a été appliquée (update.php). Si la colonne
    // n'existe pas, on désactive proprement l'onglet "Ajout multiple" plutôt
    // que de provoquer une fatal error au chargement du dashboard.
    $bulkMigrationOk = false;
    try {
        $pdo->query('SELECT visible_saisie_multiple FROM forms LIMIT 0');
        $bulkMigrationOk = true;
    } catch (\PDOException $e) {
        $canBulkCreate = false;
    }

    if ($bulkMigrationOk) {
        $rawBulkFields = getActiveFields($pdo, 'bulk');
        foreach ($rawBulkFields as $bf) {
            if (!in_array($bf['bdd_column'] ?? '', $bulkSharedCols, true)) {
                $bulkRowFields[] = $bf;
            }
        }
        // Garantit que nom + prenom sont toujours présents dans le formulaire bulk,
        // même si l'admin a oublié de les cocher en "Bulk visible". Sans ces 2
        // champs, aucune inscription ne peut être créée (rejet par l'API).
        $bulkRowCols = array_column($bulkRowFields, 'bdd_column');
        foreach (['prenom' => 'Prénom', 'nom' => 'Nom'] as $col => $label) {
            if (!in_array($col, $bulkRowCols, true)) {
                $stmtFb = $pdo->prepare('SELECT * FROM forms WHERE bdd_column = ? LIMIT 1');
                $stmtFb->execute([$col]);
                $f = $stmtFb->fetch(PDO::FETCH_ASSOC);
                if ($f) {
                    $f['required_saisie_multiple'] = 1;
                    array_unshift($bulkRowFields, $f);
                }
            }
        }

        // Cas particulier de montant_du : la colonne est `active=0` par défaut
        // (sa valeur est auto-calculée d'après le paiement dans les autres
        // contextes), donc getActiveFields() ne la renvoie pas même si l'admin
        // a coché "Bulk visible". On l'ajoute manuellement ici si présente
        // avec visible_saisie_multiple=1 en BDD.
        if (!in_array('montant_du', array_column($bulkRowFields, 'bdd_column'), true)) {
            $stmtMd = $pdo->prepare('SELECT * FROM forms WHERE bdd_column = ? AND visible_saisie_multiple = 1 LIMIT 1');
            $stmtMd->execute(['montant_du']);
            $mdField = $stmtMd->fetch(PDO::FETCH_ASSOC);
            if ($mdField) {
                $bulkRowFields[] = $mdField;
            }
        }
    }
}

?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tableau de bord</title>
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">

<!-- ─── CSS ─── -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .card-dashboard{margin-top:1rem;border-radius:1.25rem;box-shadow:0 0 25px rgba(0,0,0,.1)}
  .quick-search{max-width:450px;width:50%;margin:0 auto .75rem;position:sticky;top:0;z-index:1030}
  tr.filters th[class*="sorting"]::before,
  tr.filters th[class*="sorting"]::after{display:none!important}
  .statCard{min-width:180px}
  .hide-stats #stats {display: none !important;}
  .dashboard-actions .btn-rose{
    background:linear-gradient(135deg,#F42182,#db2777)!important;
    color:#fff!important;
    border:none!important;
  }
  .dashboard-actions .btn-rose:hover,
  .dashboard-actions .btn-rose:focus{
    background:linear-gradient(135deg,#db2777,#be185d)!important;
    color:#fff!important;
  }
  .dashboard-actions .btn-success{
    background:#22c55e!important;
    color:#fff!important;
    border-color:#22c55e!important;
  }
  .dashboard-actions .btn-success:hover,
  .dashboard-actions .btn-success:focus{
    background:#16a34a!important;
    color:#fff!important;
    border-color:#16a34a!important;
  }
  .dashboard-actions .btn-secondary{
    background:#64748b!important;
    color:#fff!important;
    border-color:#64748b!important;
  }
  .dashboard-actions .btn-secondary:hover,
  .dashboard-actions .btn-secondary:focus{
    background:#475569!important;
    color:#fff!important;
  }
  .dashboard-actions .btn-info{
    background:#0ea5e9!important;
    color:#fff!important;
    border-color:#0ea5e9!important;
  }
  .dashboard-actions .btn-info:hover,
  .dashboard-actions .btn-info:focus{
    background:#0284c7!important;
    color:#fff!important;
  }
  .dashboard-actions .btn-danger{
    background:#ef4444!important;
    color:#fff!important;
    border-color:#ef4444!important;
  }
  .dashboard-actions .btn-danger:hover,
  .dashboard-actions .btn-danger:focus{
    background:#dc2626!important;
    color:#fff!important;
  }
  .dashboard-actions .btn-warning{
    background:#f59e0b!important;
    color:#16171d!important;
    border-color:#f59e0b!important;
  }
  .dashboard-actions .btn-warning:hover,
  .dashboard-actions .btn-warning:focus{
    background:#d97706!important;
    color:#fff!important;
    border-color:#d97706!important;
  }
  
/* ═══ Tableau dashboard — style OpenCloud Rose ═══ */
#tbl { border-collapse: separate; border-spacing: 0; }

#tbl thead tr:first-child th {
  background: #faf7f8;
  color: #5f4b52;
  font-weight: 600;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  border-bottom: 2px solid #f0e8eb;
  border-top: none;
  padding: 10px 12px;
}

#tbl tbody td {
  padding: 10px 12px;
  vertical-align: middle;
  font-size: 13px;
  color: #1e293b;
  border-bottom: 1px solid #f0e8eb;
  border-left: none !important;
}

#tbl tbody tr:hover td { background: #fdf8f9; }

/* X premières lignes (QR Code) — fond rose pâle */
.first-750 td {
  background: #fdf2f6 !important;
  font-weight: 600;
}
.first-750:hover td {
  background: #fce4ec !important;
}

/* Inscrits non-payés (montant dû = 0) — gris pâle pour distinction visuelle */
.row-unpaid td {
  background: #f8fafc !important;
  color: #64748b;
}
.row-unpaid:hover td {
  background: #f1f5f9 !important;
}

/* Filtres ligne */
tr.filters th { background: #fff !important; padding: 6px 8px !important; }
tr.filters select, tr.filters input {
  font-size: 12px; border: 1px solid #d4c4cb; border-radius: 4px; padding: 4px 6px;
}

/* ═══ Onglets du modal "Nouvel inscrit" (rose theme) ═══ */
#addModalTabs {
  border-bottom: 2px solid #f0e8eb;
  margin: 0 -1rem 0;
  padding: 0 1.25rem;
}
#addModalTabs .nav-link {
  color: #94818a;
  background: transparent;
  border: none;
  border-bottom: 3px solid transparent;
  border-radius: 0;
  padding: 12px 18px;
  margin-bottom: -2px;
  font-weight: 600;
  font-size: 14px;
  transition: all 0.15s ease;
}
#addModalTabs .nav-link:hover {
  color: #db2777;
  border-bottom-color: #fbcfe8;
}
#addModalTabs .nav-link.active {
  color: #db2777;
  border-bottom-color: #db2777;
  background: transparent;
}

/* ═══ Ajout multiple : lignes compactes ═══ */
.bulk-row {
  background: #fff;
  border: 1px solid #f0e8eb;
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 8px;
  transition: border-color 0.15s;
}
.bulk-row:hover { border-color: #fbcfe8; }
.bulk-row-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}
.bulk-row-num {
  font-size: 12px;
  font-weight: 700;
  color: #db2777;
  background: #fdf2f6;
  padding: 2px 8px;
  border-radius: 4px;
  letter-spacing: 0.02em;
}
.bulk-row-remove {
  margin-left: auto;
  padding: 2px 8px !important;
  font-size: 12px !important;
  line-height: 1.2 !important;
}
.bulk-row .form-label {
  font-size: 12px;
  font-weight: 500;
  color: #64748b;
  margin-bottom: 2px;
}
.bulk-row .form-control,
.bulk-row .form-select {
  font-size: 13px;
  padding: 5px 8px;
  height: auto;
}
#bulkRows {
  max-height: 50vh;
  overflow-y: auto;
  padding-right: 4px;
}

/* ═══ Ajout multiple : correspondance colonnes Excel ↔ champs ═══ */
#bulkMapView .bulk-map-target {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border: 1px solid #f0e8eb;
  border-radius: 8px;
  margin-bottom: 8px;
  background: #fff;
}
#bulkMapView .bulk-map-target-label {
  flex: 0 0 38%;
  font-size: 13px;
  font-weight: 600;
  color: #334155;
}
.bulk-map-dropzone {
  flex: 1 1 auto;
  min-height: 38px;
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 6px;
  border: 1px dashed #cbd5e1;
  border-radius: 6px;
  background: #f8fafc;
  transition: border-color .15s, background .15s;
}
.bulk-map-pool {
  min-height: 120px;
  display: flex;
  flex-wrap: wrap;
  align-content: flex-start;
  gap: 6px;
  padding: 8px;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  background: #f8fafc;
}
.bulk-map-dropzone.drag-over,
.bulk-map-pool.drag-over {
  border-color: #db2777;
  background: #fdf2f6;
}
.bulk-map-placeholder {
  font-size: 12px;
  color: #94a3b8;
  font-style: italic;
}
.bulk-map-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  font-weight: 500;
  color: #1e293b;
  background: #fff;
  border: 1px solid #fbcfe8;
  border-radius: 6px;
  padding: 4px 8px;
  cursor: grab;
  box-shadow: 0 1px 2px rgba(0,0,0,.06);
}
.bulk-map-chip .bi { color: #db2777; cursor: grab; }
.bulk-map-chip.dragging { opacity: .4; }

/* Commentaire : éditeur de texte libre + boutons d'insertion de colonnes */
#bulkMapView .bulk-map-target-comment { display: block; }
.bulk-map-hint {
  font-size: 11px;
  font-weight: 400;
  color: #94a3b8;
  margin-top: 2px;
}
.bulk-comment-editor { margin-top: 8px; }
.bulk-comment-rich {
  min-height: 92px;
  width: 100%;
  height: auto;
  font-size: 13px;
  line-height: 1.9;
  padding: 8px 10px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.bulk-comment-rich:empty::before {
  content: attr(data-placeholder);
  color: #94a3b8;
  font-style: italic;
}
.bulk-comment-rich.drag-over { border-color: #db2777; background: #fdf2f6; }
/* Card « colonne » intégrée dans le texte (déplaçable) */
.ce-chip {
  display: inline-block;
  font-size: 12px;
  font-weight: 600;
  color: #db2777;
  background: #fdf2f6;
  border: 1px solid #fbcfe8;
  border-radius: 6px;
  padding: 0 7px;
  margin: 0 2px;
  cursor: grab;
  user-select: none;
  white-space: nowrap;
  vertical-align: 1px;
}
.ce-chip::before { content: "⋮⋮"; letter-spacing: -2px; margin-right: 4px; opacity: .5; }
.ce-chip.dragging { opacity: .4; }

/* Colonnes redimensionnables */
#tbl thead th { position: relative; }
#tbl thead th .col-resize {
  position: absolute; right: 0; top: 0; bottom: 0; width: 5px;
  cursor: col-resize; user-select: none; z-index: 1;
}
#tbl thead th .col-resize:hover,
#tbl thead th .col-resize.active { background: #F42182; }

/* Bouton colonnes */
.col-toggle-wrap { position: relative; display: inline-block; }
.col-toggle-btn {
  font-size: 13px; font-weight: 500; padding: 5px 12px;
  border: 1px solid #d4c4cb; border-radius: 6px; background: #fff;
  color: #1e293b; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
}
.col-toggle-btn:hover { background: #fdf8f9; }
.col-toggle-dropdown {
  display: none; position: absolute; top: 100%; right: 0; margin-top: 4px;
  background: #fff; border: 1px solid #f0e8eb; border-radius: 8px;
  box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 100;
  padding: 8px 0; min-width: 200px; max-height: 350px; overflow-y: auto;
}
.col-toggle-dropdown.show { display: block; }
.col-toggle-dropdown label {
  display: flex; align-items: center; gap: 8px; padding: 6px 14px;
  font-size: 13px; color: #1e293b; cursor: pointer; font-weight: 400;
  text-transform: none; letter-spacing: 0; margin: 0;
}
.col-toggle-dropdown label:hover { background: #fdf8f9; }

/* « Show X entries » (DataTables) : garder tout sur une ligne et éviter que la
   flèche du <select> ne chevauche le « 10 » (le select hérite parfois de
   .form-select = block + width:100%, d'où l'empilement Show / 10 / entries). */
#tbl_length label { display: inline-flex; align-items: center; gap: 6px; margin: 0; white-space: nowrap; font-size: 13px; color: #475569; font-weight: 400; }
#tbl_length select, #tbl_length .form-select {
  display: inline-block !important;
  width: auto !important;
  min-width: 64px;
  padding: 5px 30px 5px 10px;   /* place à droite pour la flèche */
  font-size: 13px;
  border: 1px solid #d4c4cb;
  border-radius: 6px;
  background-color: #fff;
  background-position: right 9px center;  /* repositionne la flèche (Bootstrap .form-select) */
}

/* ═══ Petite retouche des filtres sous l'en-tête =========================== */
tr.filters th{
  background:#f2f4f8;
  border-bottom:2px solid #e0e4ec;
  padding:.4rem;
}
tr.filters select{
  font-size:.8rem;
  border-radius:8px;
}

/* ═══ Boutons action dans le tableau ====================================== */
.action-buttons .btn{
  --bs-btn-padding-y: .20rem;
  --bs-btn-padding-x: .45rem;
  --bs-btn-font-size: .75rem;
}
.btn-delete{
  background:#e63946;
  background:linear-gradient(135deg,#e63946 0%,#c5303d 100%);
}
.btn-delete:hover{
  background:linear-gradient(135deg,#c5303d 0%,#a32634 100%);
  box-shadow:0 3px 6px rgba(230,57,70,.35);
}

.xl-modal .modal-dialog {
  max-width: 1300px;
}

</style>
</head>

<body>

<?php include 'navbar-admin.php'; ?>

<!-- ═════════ MAIN ═════════ -->
  <div>

    <div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-3 gap-3">
      <h1 class="mb-0 fw-bold"><i class="bi bi-house me-2"></i>Inscriptions</h1>

      <div class="dashboard-actions d-none d-lg-flex flex-wrap gap-2">
        <?php if($canCreateReg): ?>
          <button class="btn btn-rose"      data-bs-toggle="modal" data-bs-target="#addModal">Nouvel inscrit</button>
        <?php endif; ?>
        <?php if($canImportXls): ?>
          <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#importModal">Import Excel AssoConnect</button>
        <?php endif; ?>
        <?php if($canExportXls): ?>
          <button id="btnExport" class="btn btn-info">Export Excel</button>
            <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
            document.getElementById('btnExport').addEventListener('click', () => {
              // simple redirection => déclenche le téléchargement
              window.location = '../config/api.php?route=export-excel';
            });
            </script>
          <?php endif; ?>
          <?php if($canArchive): ?>
            <button id="btnArchiveNow" class="btn btn-danger">Archiver&nbsp;<?= date('Y') ?></button>

            <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
            document.getElementById('btnArchiveNow').addEventListener('click', async () => {
              if (!confirm('Tout archiver et réinitialiser les inscriptions ?')) return;

              const btn = document.getElementById('btnArchiveNow');
              const originalText = btn.innerHTML;
              btn.disabled = true;
              btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Archivage en cours…';

              try {
                const _ct = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                const res  = await fetch('../config/api.php?route=archive-current', {
                  method: 'POST',
                  credentials: 'same-origin',
                  headers: {'X-CSRF-TOKEN': _ct}
                });
                const json = await res.json();
                if (json.ok) {
                  alert(`${json.archived} inscription(s) archivées (${json.year}).`);
                  location.reload();
                } else {
                  alert('Erreur archivage : ' + JSON.stringify(json));
                  btn.disabled = false;
                  btn.innerHTML = originalText;
                }
              } catch (e) {
                alert('Erreur réseau : ' + e.message);
                btn.disabled = false;
                btn.innerHTML = originalText;
              }
            });
            </script>
        <?php endif; ?>
      </div>
    </div>

    <!-- stats -->
    <div id="stats" class="d-flex flex-wrap gap-3 mb-4"></div>

    <input id="quickSearch" class="form-control quick-search" placeholder="Recherche rapide">
    <div class="table-responsive">
      <table id="tbl" class="table table-striped table-sm w-100"></table>
    </div>
  </div>

<?php include 'admin-footer.php'; ?>

<!-- ═════════ MODALES ═════════ -->

<!-- Autres modales existantes... -->
<div class="modal fade xl-modal" id="addModal" tabindex="-1"><div class="modal-dialog <?= $canBulkCreate ? 'modal-xl' : '' ?>">
  <div class="modal-content"><div class="modal-header pb-0 border-0">
    <h5 class="modal-title">Nouvel inscrit</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>

    <?php if ($canBulkCreate): ?>
    <ul class="nav nav-tabs px-3" id="addModalTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-single-btn" data-bs-toggle="tab" data-bs-target="#tab-single" type="button" role="tab">Inscrit unique</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-bulk-btn" data-bs-toggle="tab" data-bs-target="#tab-bulk" type="button" role="tab">Ajout multiple</button>
      </li>
    </ul>
    <?php endif; ?>

    <div class="tab-content">
      <!-- ───── ONGLET 1 : INSCRIT UNIQUE (formulaire existant) ───── -->
      <div class="tab-pane fade show active" id="tab-single" role="tabpanel">
        <form id="fAdd">
          <div class="modal-body row g-2">
            <input type="hidden" name="origine" value="Admin">
            <?php foreach ($adminFields as $f): ?>
              <?= renderFormField($f) ?>
            <?php endforeach; ?>
            <div class="col-md-6">
              <label class="form-label">Paiement <span style="color:#ef4444">*</span></label>
              <select name="paiement_mode" class="form-select paiement-select" required>
                <option value="" disabled selected hidden>Choisir…</option>
                <option value="CB">CB</option>
                <option value="espece">Espèce</option>
                <option value="cheque">Chèque</option>
                <option value="gratuit">Gratuit / Enfant -12 ans (sans T-shirt)</option>
                <option value="enfant_tshirt">Enfant -12 ans (avec T-shirt)</option>
              </select>
              <div class="montant-du-display mt-2" style="display:none;font-size:14px;font-weight:600;color:#1e293b"></div>
            </div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button class="btn btn-rose">Enregistrer</button></div>
        </form>
      </div>

      <?php if ($canBulkCreate): ?>
      <!-- ───── ONGLET 2 : AJOUT MULTIPLE (saisie en lot) ───── -->
      <div class="tab-pane fade" id="tab-bulk" role="tabpanel">
        <form id="fBulkAdd">
          <input type="hidden" name="origine" value="Admin">
          <div class="modal-body">
            <!-- Champs partagés -->
            <div class="row g-2 mb-3 p-3 rounded" style="background:#fdf2f6;border:1px solid #fbcfe8;">
              <div class="col-12 mb-1"><strong class="text-muted" style="font-size:13px;">Données communes à tous les inscrits</strong></div>
              <div class="col-md-4">
                <label class="form-label">Entreprise <span style="color:#ef4444">*</span></label>
                <input type="text" name="shared_entreprise" class="form-control" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Email (1 seul mail récap envoyé) <span style="color:#ef4444">*</span></label>
                <input type="email" name="shared_email" class="form-control" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Paiement <span style="color:#ef4444">*</span></label>
                <select name="shared_paiement_mode" class="form-select" required>
                  <option value="" disabled selected hidden>Choisir…</option>
                  <option value="CB">CB</option>
                  <option value="espece">Espèce</option>
                  <option value="cheque">Chèque</option>
                  <option value="gratuit">Gratuit / Enfant -12 ans (sans T-shirt)</option>
                  <option value="enfant_tshirt">Enfant -12 ans (avec T-shirt)</option>
                </select>
              </div>
            </div>

            <!-- Liste dynamique des personnes -->
            <input type="file" id="bulkExcelFile" accept=".xlsx,.xls" style="display:none">
            <div id="bulkEditView">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <strong class="text-muted" style="font-size:13px;">Personnes à inscrire</strong>
                <div>
                  <button type="button" id="bulkImportExcelBtn" class="btn btn-outline-success btn-sm me-1"><i class="bi bi-file-earmark-excel"></i> Excel</button>
                  <button type="button" id="bulkDuplicateBtn" class="btn btn-outline-secondary btn-sm me-1"><i class="bi bi-files"></i> Dupliquer la dernière</button>
                  <button type="button" id="bulkAddBtn" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg"></i> Ajouter une personne</button>
                </div>
              </div>
              <div id="bulkRows"></div>
            </div>

            <!-- Vue de correspondance : colonnes du fichier Excel ↔ champs « Personnes à inscrire » -->
            <div id="bulkMapView" style="display:none">
              <div class="alert alert-info py-2 px-3" style="font-size:13px">
                <i class="bi bi-info-circle me-1"></i>
                Glissez chaque colonne de votre fichier sous le champ correspondant. Les champs marqués <span style="color:#ef4444">*</span> sont obligatoires : leur colonne doit être reliée — sauf « Montant dû », facultatif (calculé d'après le paiement ou le tarif de la course).
              </div>
              <div class="row g-3">
                <div class="col-md-7">
                  <strong class="text-muted" style="font-size:13px;">Vos champs</strong>
                  <div id="bulkMapTargets" class="mt-2">
                    <?php foreach ($bulkRowFields as $bf):
                      if (empty($bf['bdd_column']) || ($bf['field_type'] ?? '') === 'guardian') continue;
                      $mreq  = (int) ($bf['required_saisie_multiple'] ?? 0);
                      $mbdd  = htmlspecialchars($bf['bdd_column']);
                      $mlbl  = htmlspecialchars($bf['label']);
                      $mstar = $mreq ? ' <span style="color:#ef4444">*</span>' : '';
                      // Le commentaire est un modèle de texte libre : on écrit ce qu'on
                      // veut et on clique une colonne pour insérer sa valeur (jeton [Colonne]).
                      $mComment = (($bf['bdd_column'] ?? '') === 'commentaire');
                    ?>
                      <?php if ($mComment): ?>
                      <div class="bulk-map-target bulk-map-target-comment" data-bdd="<?= $mbdd ?>" data-required="<?= $mreq ?>">
                        <div class="bulk-map-target-label"><?= $mlbl ?><?= $mstar ?>
                          <div class="bulk-map-hint">Écrivez librement, puis glissez une colonne depuis « Colonnes de votre fichier » dans le texte. Les cards se déplacent ensuite à la souris.</div>
                        </div>
                        <div class="bulk-comment-editor">
                          <div class="bulk-comment-rich form-control" contenteditable="true" data-placeholder="Écrivez ici… puis glissez une colonne du fichier pour insérer sa valeur."></div>
                        </div>
                      </div>
                      <?php else: ?>
                      <div class="bulk-map-target" data-bdd="<?= $mbdd ?>" data-required="<?= $mreq ?>">
                        <div class="bulk-map-target-label"><?= $mlbl ?><?= $mstar ?></div>
                        <div class="bulk-map-dropzone" data-target-drop>
                          <span class="bulk-map-placeholder">Déposez une colonne ici</span>
                        </div>
                      </div>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="col-md-5">
                  <strong class="text-muted" style="font-size:13px;">Colonnes de votre fichier</strong>
                  <div id="bulkMapPool" class="bulk-map-pool mt-2">
                    <span class="bulk-map-placeholder">Les colonnes de votre fichier apparaîtront ici.</span>
                  </div>
                </div>
              </div>
              <div class="d-flex justify-content-between align-items-center mt-3">
                <button type="button" id="bulkMapCancel" class="btn btn-outline-secondary btn-sm">Annuler l'import</button>
                <div>
                  <span id="bulkMapInfo" class="text-muted small me-2"></span>
                  <button type="button" id="bulkMapGenerate" class="btn btn-rose btn-sm"><i class="bi bi-magic me-1"></i>Générer les cards</button>
                </div>
              </div>
            </div>

            <div id="bulkProgress" class="mt-3" style="display:none">
              <div id="bulkProgressLog" style="max-height:200px;overflow-y:auto;font-size:13px;background:#f8f9fa;border-radius:8px;padding:12px;font-family:monospace;"></div>
              <div id="bulkRecap" class="mt-3" style="display:none"></div>
            </div>
          </div>
          <div class="modal-footer justify-content-between">
            <span id="bulkSummary" class="text-muted small"></span>
            <div>
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
              <button type="button" id="btnBulkClose" class="btn btn-primary" style="display:none">Fermer et actualiser</button>
              <button type="submit" id="btnBulkSubmit" class="btn btn-rose">Valider les saisies</button>
            </div>
          </div>
        </form>
      </div>

      <!-- Template caché pour générer une nouvelle ligne d'inscrit (compact) -->
      <template id="bulkRowTemplate">
        <div class="bulk-row">
          <div class="bulk-row-header">
            <span class="bulk-row-num bulk-row-title">#1</span>
            <button type="button" class="btn btn-sm btn-outline-danger bulk-row-remove" title="Retirer cette personne"><i class="bi bi-x-lg"></i></button>
          </div>
          <div class="row g-2">
            <?php foreach ($bulkRowFields as $bf):
              if (empty($bf['bdd_column']) || ($bf['field_type'] ?? '') === 'guardian') continue; // pas de colonne BDD en mode bulk
              $req = (int) ($bf['required_saisie_multiple'] ?? 0);
              $type = $bf['field_type'] ?? 'text';
              $bdd  = htmlspecialchars($bf['bdd_column']);
              $lbl  = htmlspecialchars($bf['label']);
              $star = $req ? ' <span style="color:#ef4444">*</span>' : '';
              $reqAttr = $req ? ' data-required="1"' : '';
            ?>
              <div class="col-md-3">
                <label class="form-label"><?= $lbl ?><?= $star ?></label>
                <?php if ($type === 'select'):
                  $opts = array_map('trim', explode(',', $bf['options_list'] ?? '')); ?>
                  <select data-bdd="<?= $bdd ?>" class="form-select bulk-field"<?= $reqAttr ?>>
                    <option value="">—</option>
                    <?php foreach ($opts as $opt): ?>
                      <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php elseif (($bf['bdd_column'] ?? '') === 'naissance'): ?>
                  <input type="text" inputmode="numeric" autocomplete="off" data-bdd="<?= $bdd ?>" class="form-control bulk-field bulk-birthdate" placeholder="JJ/MM/AAAA, année ou âge"<?= $reqAttr ?>>
                <?php elseif ($type === 'date'): ?>
                  <input type="date" data-bdd="<?= $bdd ?>" class="form-control bulk-field"<?= $reqAttr ?>>
                <?php elseif ($type === 'number'): ?>
                  <input type="number" step="0.01" min="0" data-bdd="<?= $bdd ?>" class="form-control bulk-field<?= $bdd === 'montant_du' ? ' bulk-montant' : '' ?>"<?= $reqAttr ?><?= $bdd === 'montant_du' ? ' value="' . htmlspecialchars((string) $registration_fee) . '"' : '' ?>>
                <?php elseif ($type === 'email'): ?>
                  <input type="email" data-bdd="<?= $bdd ?>" class="form-control bulk-field"<?= $reqAttr ?>>
                <?php elseif (($bf['bdd_column'] ?? '') === 'commentaire' || $type === 'textarea'): ?>
                  <textarea data-bdd="<?= $bdd ?>" class="form-control bulk-field" rows="2"<?= $reqAttr ?>></textarea>
                <?php else: ?>
                  <input type="text" data-bdd="<?= $bdd ?>" class="form-control bulk-field"<?= $reqAttr ?>>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </template>
      <?php endif; ?>
    </div>
  </div>
</div></div>

<div class="modal fade" id="editModal" tabindex="-1"><div class="modal-dialog">
  <div class="modal-content"><div class="modal-header">
    <h5 class="modal-title">Modifier l'inscription</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="fEdit">
      <div class="modal-body row g-2">
        <input type="hidden" name="id">
        <input type="hidden" name="origine" value="Admin">
        <?php foreach ($adminFields as $f): ?>
          <?= renderFormField($f) ?>
        <?php endforeach; ?>
        <div class="col-md-6">
          <label class="form-label">Paiement</label>
          <select name="paiement_mode" class="form-select paiement-select">
            <option value="CB">CB</option>
            <option value="espece">Espèce</option>
            <option value="cheque">Chèque</option>
            <option value="gratuit">Gratuit / Enfant -12 ans (sans T-shirt)</option>
            <option value="enfant_tshirt">Enfant -12 ans (avec T-shirt)</option>
          </select>
          <div class="montant-du-display mt-2" style="display:none;font-size:14px;font-weight:600;color:#1e293b"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button class="btn btn-rose">Sauvegarder</button></div>
    </form>
  </div></div></div>

<div class="modal fade" id="importModal" tabindex="-1"><div class="modal-dialog modal-lg">
 <div class="modal-content"><div class="modal-header">
   <h5 class="modal-title">Import Excel AssoConnect</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="fImport" enctype="multipart/form-data"><div class="modal-body">
    <input type="file" name="file" id="importFileInput" accept=".xlsx,.xls" class="form-control" required>
    <div class="form-check form-switch mt-3">
      <input class="form-check-input" type="checkbox" name="send_mails" id="importSendMails" checked>
      <label class="form-check-label" for="importSendMails">Envoyer les mails d'inscription</label>
    </div>
    <div id="importPreview" class="mt-3" style="display:none">
      <div id="importPreviewLoading" class="text-center text-muted py-2" style="display:none">
        <span class="spinner-border spinner-border-sm me-1"></span>Analyse du fichier…
      </div>
      <div id="importPreviewResult" style="display:none"></div>
    </div>
    <div id="importProgress" class="mt-3" style="display:none">
      <div id="importProgressLog" style="max-height:300px;overflow-y:auto;font-size:13px;background:#f8f9fa;border-radius:8px;padding:12px;font-family:monospace;"></div>
      <div id="importRecap" class="mt-3" style="display:none"></div>
    </div>
  </div><div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
    <button type="button" id="btnImportClose" class="btn btn-primary" style="display:none">Fermer et actualiser</button>
    <button type="submit" id="btnImportSubmit" class="btn btn-rose" disabled>Importer</button>
  </div></form></div></div></div>


<!-- QR Scanner Modal -->
<style>
  #qrScanModal .modal-dialog { max-width: 600px; }
  #qrScanModal .qr-size-btn {
    min-width: 60px; min-height: 52px;
    font-size: 1.1rem; font-weight: 700;
    border-radius: 12px; border-width: 2px;
  }
  #qrScanModal .qr-size-btn.active.btn-primary { transform: scale(1.08); box-shadow: 0 4px 12px rgba(13,110,253,.35); }
  @media (max-width: 576px) {
    #qrScanModal .modal-dialog { margin: 0; max-width: 100%; height: 100%; }
    #qrScanModal .modal-content { border-radius: 0; min-height: 100dvh; }
    #qrScanModal .modal-body { padding: 1rem; }
    #qrScanModal .qr-size-btn { min-width: 52px; min-height: 56px; font-size: 1.15rem; flex: 1 1 0; }
    #qrScanModal #qrManualInput { font-size: 1.1rem; height: 48px; }
    #qrScanModal #qrManualBtn { font-size: 1.1rem; height: 48px; padding: 0 1.2rem; }
    #qrScanModal #qrPersonName { font-size: 1.2rem; }
  }
  @media (min-width: 577px) and (max-width: 992px) {
    #qrScanModal .modal-dialog { max-width: 90%; }
    #qrScanModal .qr-size-btn { min-width: 70px; min-height: 54px; flex: 1 1 0; }
  }
  /* Tablette en portrait : limite la hauteur de la caméra pour laisser la place au champ manuel */
  @media (orientation: portrait) and (min-width: 577px) and (max-width: 1100px) {
    #qrScanModal #qrReader {
      max-width: 320px;
      margin: 0 auto;
      overflow: hidden;
    }
    #qrScanModal #qrReader video {
      width: 100% !important;
      height: auto !important;
      max-height: 45vh !important;
      object-fit: cover;
    }
  }
</style>
<div class="modal fade" id="qrScanModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-qr-code-scan me-2"></i>Remise T-shirt</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Scanner zone -->
        <div id="qrReader" style="width:100%"></div>

        <!-- Manual input -->
        <div class="text-center mt-3" id="qrManualZone">
          <p class="text-muted mb-1" style="font-size:13px">Ou saisir le N° d'inscription :</p>
          <div class="input-group" style="max-width:320px;margin:0 auto">
            <input type="text" id="qrManualInput" class="form-control" placeholder="N° inscription (ex: E21800406)">
            <button id="qrManualBtn" class="btn btn-primary">OK</button>
          </div>
        </div>

        <!-- Result card (hidden by default) -->
        <div id="qrPersonCard" class="mt-3" style="display:none">
          <hr>
          <!-- Eligibility badge -->
          <div id="qrEligibility" class="text-center mb-3"></div>

          <!-- Person info -->
          <div class="text-center mb-3">
            <div class="fw-bold fs-4" id="qrPersonName"></div>
            <span class="text-muted" id="qrPersonNo"></span>
            <span id="qrPersonVille" class="badge bg-secondary ms-2"></span>
          </div>

          <!-- T-shirt selector -->
          <div id="qrTshirtZone">
            <label class="form-label fw-semibold text-center d-block mb-2">Taille T-shirt :</label>
            <div class="d-flex flex-wrap gap-2 justify-content-center" id="qrTshirtBtns">
              <button class="btn btn-outline-dark qr-size-btn" data-size="XS">XS</button>
              <button class="btn btn-outline-dark qr-size-btn" data-size="S">S</button>
              <button class="btn btn-outline-dark qr-size-btn" data-size="M">M</button>
              <button class="btn btn-outline-dark qr-size-btn" data-size="L">L</button>
              <button class="btn btn-outline-dark qr-size-btn" data-size="XL">XL</button>
              <button class="btn btn-outline-dark qr-size-btn" data-size="XXL">XXL</button>
            </div>
            <div id="qrSaveStatus" class="mt-2 text-center" style="display:none"></div>
          </div>
          <hr>
          <div class="text-center">
            <button id="qrNextScan" class="btn btn-primary"><i class="bi bi-qr-code-scan me-2"></i>Scanner suivant</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═════════ JS ═════════ -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="../js/inscription-form.js?v=3" nonce="<?= $GLOBALS['csp_nonce'] ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.js" integrity="sha384-3wB6mhez87GBdPpEqKMU2wAH2Cjcvj8ynU/n7blM/JW4BLpVD0aTrx4ZE7IwFLSH" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
const _csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const userRole = '<?= $role ?>';
const canEditReg     = <?= $canEditReg ? 'true' : 'false' ?>;
const canScanQr      = <?= $canScanQr ? 'true' : 'false' ?>;
const canTshirtMode  = <?= $canTshirtMode ? 'true' : 'false' ?>;
const registrationFee = <?= json_encode($registration_fee) ?>;
let tableData = []; // Pour stocker les données triées par date

/* ══ Affichage dynamique du « Montant dû » sous le select paiement ══ */
function formatMontant(n){
  var v = parseFloat(n);
  if(!isFinite(v)) v = 0;
  return v.toFixed(2).replace(/\.00$/,'') + ' €';
}
function updateMontantDisplay(selectEl){
  var wrap = selectEl.closest('.col-md-6');
  if(!wrap) return;
  var disp = wrap.querySelector('.montant-du-display');
  if(!disp) return;
  var val = selectEl.value;
  if(!val){ disp.style.display='none'; disp.textContent=''; return; }
  // Dans le modal d'édition, le montant représente ce qui a déjà été payé
  // (l'inscription existe déjà), pas une somme à régler.
  var isEdit = !!selectEl.closest('#editModal');
  var labelDu  = isEdit ? 'Montant payé' : 'Montant dû';
  var labelDue = isEdit ? 'Montant payé' : 'Montant total dû';
  if(val === 'gratuit'){
    disp.style.display='block';
    disp.innerHTML = labelDu + ' : <span style="color:#16a34a">'+formatMontant(0)+'</span>';
  } else {
    disp.style.display='block';
    disp.innerHTML = labelDue + ' : <span style="color:#F42182">'+formatMontant(registrationFee)+'</span>';
  }
}
document.addEventListener('change', function(e){
  if(e.target && e.target.matches('.paiement-select')) updateMontantDisplay(e.target);
});
// Réinitialise l'affichage à l'ouverture des modales
['addModal','editModal'].forEach(function(id){
  var m = document.getElementById(id);
  if(!m) return;
  m.addEventListener('shown.bs.modal', function(){
    var sel = m.querySelector('.paiement-select');
    if(sel) updateMontantDisplay(sel);
  });
});

/* ══ Rang « payant » : numéro d'ordre en ignorant les non-payés ══ */
// Renvoie le rang chronologique d'un inscrit en ne comptant que ceux ayant
// effectivement payé (montant_du > 0). Les non-payés sont écartés du décompte.
function computePaidRank(allRows, inscriptionNo){
  var sorted = allRows.slice().sort(function(a,b){
    return new Date(a.created_at) - new Date(b.created_at);
  });
  var paidCount = 0;
  for (var i = 0; i < sorted.length; i++){
    var paid = parseFloat(sorted[i].montant_du) > 0;
    if (paid) paidCount++;
    if (String(sorted[i].inscription_no) === String(inscriptionNo)){
      return paid ? paidCount : -1; // -1 si non payé
    }
  }
  return -1;
}

/* ══ Outils ════ */
// normalizeBirth / ageFromBirth sont fournis par js/inscription-form.js (window.FERInscription).
const normalizeBirth = (fd) => { if (window.FERInscription) FERInscription.normalizeBirth(fd); };
const ageFromBirth   = (b)  => (window.FERInscription ? FERInscription.ageFromBirth(b) : null);

/* ══ DataTable ════ */
let tshirtMode=false;
function refreshButtons(){ $('#modeTS, #modeTS_m').text(tshirtMode?'Remise T-shirts':'Mode standard'); }
refreshButtons();

// Pagination compacte type "1 ... 4 5 6 ... 12"
$.fn.dataTable.ext.pager.numbers_length = 7;

const tbl=$('#tbl').DataTable({
  ajax:{
    url:'../config/api.php?route=registrations',
    dataSrc: function(json) {
      // Trier les données par date d'ajout (du plus ancien au plus récent)
      tableData = json.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
      return tableData;
    }
  },
  columns:[
    {data:'id',visible:false},
    {data: null, title: 'ID', width: '60px', className: 'text-center', orderable: false, defaultContent: ''},
    {data:'inscription_no',title:'N°'},
    <?php foreach ($allActiveFields as $af):
      if (empty($af['bdd_column'])) continue; // pas une colonne BDD (ex. autorisation parentale)
      $col = $af['bdd_column'];
      $lbl = htmlspecialchars($af['label'], ENT_QUOTES);
      if ($col === 'tshirt_size'): ?>
    {data:'tshirt_size',title:'<?= $lbl ?>',render:(v,t,r)=>{
      if(t!=='display') return v??''; if(!tshirtMode) return v??'';
      // Le dropdown est interactif si l'utilisateur peut éditer OU s'il a le droit scanner QR
      if(!canEditReg && !canScanQr) return `<span class="text-muted" style="font-style:italic;opacity:.6">${v||'-'}</span>`;
      const sz=<?= json_encode(array_map('trim', explode(',', $af['options_list'] ?? '-,XS,S,M,L,XL,XXL'))) ?>;
      return `<select class="form-select form-select-sm tshirt-dd" data-id="${r.id}">${sz.map(s=>`<option${s===v?' selected':''}>${s}</option>`).join('')}</select>`;
    }},
      <?php else: ?>
    {data:'<?= $col ?>',title:'<?= $lbl ?>',defaultContent:''},
      <?php endif; ?>
    <?php endforeach; ?>
    {data:'paiement_mode',title:'Paiement',defaultContent:'',render:function(val,type){
      // Display : libellé convivial. Filter/sort/recherche : valeur brute
      // (sinon les filtres et la recherche par regex ^gratuit$ ne fonctionnent plus).
      if(type==='display'){
        if(!val) return '';
        var lc = String(val).toLowerCase();
        if(lc === 'gratuit') return 'Gratuit/-12ans';
        if(lc === 'enfant_tshirt') return 'en ligne (CB)'; // legacy : la catégorie est désormais dans Prestation
        return val;
      }
      return val;
    }},
    {data:'montant_du', title:'Montant', className:'text-end text-nowrap', defaultContent:'0', render:function(val,type){
      if(type!=='display' && type!=='filter') return val;
      var n = parseFloat(val);
      if(!isFinite(n)) n = 0;
      return n.toFixed(2).replace(/\.00$/,'') + ' €';
    }},
    {data:'created_at', title:'Date ajout', render:function(val,type){
      if(type==='display'||type==='filter'){ if(!val) return ''; return new Date(val).toLocaleDateString('fr-FR'); }
      return val;
    }, width:'110px', className:'text-nowrap text-center'},
    {data:'origine',title:'Origine',defaultContent:''},
    {data:'prestation',title:'Prestation',defaultContent:'',render:function(val,type,row){
      if(type==='display'){
        var lc = String(val||'').toLowerCase();
        if(lc === 'enfant_tshirt')  return 'Enfant -12 +T-shirt';
        if(lc === 'enfant_gratuit') return 'Enfant -12 (gratuit sans t-shirt)';
        if(lc === 'tarif_unique')   return 'Tarif unique';
        // Repli (anciens inscrits sans prestation) : déduire du mode de paiement.
        var pm = String((row && row.paiement_mode) || '').toLowerCase();
        if(pm === 'gratuit') return 'Enfant -12 (gratuit)';
        if(pm === 'enfant_tshirt') return 'Enfant -12 +T-shirt';
        return val ? val : 'Tarif unique';
      }
      return val;
    }}
    <?php if($canEditReg || $canDeleteReg): ?>,
    {
      data:null,
      title:'Actions',
      orderable:false,
      className:'text-center',
      width:'120px',
      render: function(data, type, row) {
        let buttons = '';
        <?php if($canEditReg): ?>
        buttons += '<button class="btn btn-sm btn-outline-primary edit me-1" title="Modifier"><i class="bi bi-pencil"></i></button>';
        <?php endif; ?>
        <?php if($canDeleteReg): ?>
        buttons += '<button class="btn btn-sm btn-outline-danger delete-row" title="Supprimer"><i class="bi bi-trash3"></i></button>';
        <?php endif; ?>
        return `<div class="action-buttons">${buttons}</div>`;
      }
    }
    <?php endif; ?>
  ],
  dom:'lrtip',
  autoWidth:false,
  orderCellsTop:true,
  order: [[11, 'asc']], // Trier par date d'ajout par défaut (colonne 11 = created_at)
  rowCallback: function (row, data, _displayNum, displayIndex) {
    // numéro séquentiel affiché (colonne « ID »)
    $('td:eq(0)', row).text(displayIndex + 1);
  },
  drawCallback: function(){
    // Surlignage « X premiers » : on ne compte que les inscrits qui ont payé
    // (montant_du > 0). Les non-payés ne consomment pas un slot T-shirt.
    var api = this.api();
    var hlLimit = <?= (int) $highlightLimit ?>;
    var paidCount = 0;
    $('#tbl tbody tr').each(function(){
      var d = api.row(this).data();
      if(!d){ return; }
      var paid = parseFloat(d.montant_du) > 0;
      if(paid) paidCount++;
      $(this).toggleClass('first-750', paid && hlLimit > 0 && paidCount <= hlLimit);
      $(this).toggleClass('row-unpaid', !paid);
    });
  },
  initComplete:function(){
    buildFilters(this.api());
    updateStats(this.api().data().toArray());
  }
});

tbl.on('xhr.dt',(e,s,json)=>{
  if(json) {
    tableData = json.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
    updateStats(tableData);
  }
});


// Événements pour détecter l'ouverture/fermeture des menus t-shirt
$('#tbl').on('mousedown', '.tshirt-dd', function(e) {
  isDropdownOpen = true;
});

$('#tbl').on('focus', '.tshirt-dd', function() {
  isDropdownOpen = true;
});

$('#tbl').on('blur change', '.tshirt-dd', function() {
  setTimeout(() => {
    isDropdownOpen = false;
  }, 150);
});

$(document).on('click', function(e) {
  if (!$(e.target).hasClass('tshirt-dd')) {
    setTimeout(() => {
      isDropdownOpen = false;
    }, 100);
  }
});

// Variables pour gérer le refresh automatique
let refreshInterval;
let isDropdownOpen = false;

// Fonction pour démarrer le refresh automatique
function startAutoRefresh() {
  refreshInterval = setInterval(() => {
    if (!isDropdownOpen) {
      tbl.ajax.reload(null, false);
    }
  }, 5000);
}

startAutoRefresh();
$('#quickSearch').on('keyup',function(){tbl.search(this.value).draw();});

/* ══ Stats ════ */
function updateStats(data){
  const total=data.length, oldest={H:null,F:null}, byEnt={};
  let tshirtCount=0, enfantTshirtCount=0;
  data.forEach(r=>{
    const a=ageFromBirth(r.naissance);
    if(a!==null&&(r.sexe==='H'||r.sexe==='F')){
      if(!oldest[r.sexe] || a>oldest[r.sexe].age)
        oldest[r.sexe]={nom:`${r.prenom||''} ${r.nom||''}`.trim(),age:a};
    }
    if(r.entreprise) byEnt[r.entreprise]=(byEnt[r.entreprise]||0)+1;
    // T-shirt récupéré = taille renseignée (≠ vide et ≠ "-")
    const sz=(r.tshirt_size||'').toString().trim();
    if(sz && sz!=='-') tshirtCount++;
    // Enfant -12 ans AVEC t-shirt (catégorie payante distincte)
    const presta=(r.prestation||'').toString().toLowerCase();
    const pm=(r.paiement_mode||'').toString().toLowerCase();
    if(presta==='enfant_tshirt' || pm==='enfant_tshirt') enfantTshirtCount++;
  });
  const [eTop,eCnt]=Object.entries(byEnt).sort((a,b)=>b[1]-a[1])[0]||['–',0];
  $('#stats').html(`
    <div class="card statCard flex-fill text-center"><div class="card-body">
      <h5 class="card-title mb-1">Inscriptions</h5>
      <p class="display-6 fw-bold mb-0">${total}</p></div></div>
    <div class="card statCard flex-fill text-center"><div class="card-body">
      <h5 class="card-title mb-1">T-shirts récupérés</h5>
      <p class="display-6 fw-bold mb-0">${tshirtCount}</p></div></div>
    <div class="card statCard flex-fill text-center"><div class="card-body">
      <h6 class="card-title text-muted mb-1">Enfants -12 +T-shirt</h6>
      <p class="display-6 fw-bold mb-0">${enfantTshirtCount}</p></div></div>
    <div class="card statCard flex-fill text-center"><div class="card-body">
      <h6 class="card-title text-muted mb-1">+ Vieux H</h6>
      <p class="fw-semibold mb-0">${oldest.H?oldest.H.nom+' ('+oldest.H.age+' ans)':'–'}</p></div></div>
    <div class="card statCard flex-fill text-center"><div class="card-body">
      <h6 class="card-title text-muted mb-1">+ Vieille F</h6>
      <p class="fw-semibold mb-0">${oldest.F?oldest.F.nom+' ('+oldest.F.age+' ans)':'–'}</p></div></div>
    <div class="card statCard flex-fill text-center"><div class="card-body">
      <h6 class="card-title text-muted mb-1">Entreprise n°1</h6>
      <p class="fw-semibold mb-0">${eTop} — ${eCnt}</p></div></div>
  `);
  if(tshirtMode) $('#stats').hide(); else $('#stats').show();
}

/* ══ Filtres par colonne ════ */
function buildFilters(api){
  const $thead=$('#tbl thead');
  $thead.find('tr.filters').remove();
  const $f=$thead.find('tr').first().clone(false).addClass('filters').appendTo($thead);
  $f.find('th').empty().removeClass('sorting sorting_asc sorting_desc sorting_disabled');
  api.columns().every(function(i){
    const title=$(this.header()).text().trim(), $cell=$f.find('th').eq(i);
    if(!this.visible()){ $cell.hide(); return; }
    if(['T-shirt','Sexe','Paiement','Prestation','Entreprise','Origine'].includes(title)){
      const $sel=$('<select class="form-select form-select-sm"><option value="">Tous</option></select>')
        .appendTo($cell)
        .on('change',function(){
          // Échappe les caractères regex pour les libellés contenant /, -, etc.
          var raw = this.value;
          if(raw){
            var esc = raw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            api.column(i).search('^'+esc+'$', true, false).draw();
          } else {
            api.column(i).search('', true, false).draw();
          }
        });
      this.data().unique().sort().each(function(v){
        if(!v) return;
        // Libellé convivial pour les paiements « catégorie » ; valeur brute conservée
        var label = v;
        if(title === 'Paiement'){
          var lcv = String(v).toLowerCase();
          if(lcv === 'gratuit') label = 'Gratuit/-12ans';
          else if(lcv === 'enfant_tshirt') label = 'en ligne (CB)';
        } else if(title === 'Prestation'){
          var lcp = String(v).toLowerCase();
          if(lcp === 'tarif_unique') label = 'Tarif unique';
          else if(lcp === 'enfant_gratuit') label = 'Enfant -12 (gratuit sans t-shirt)';
          else if(lcp === 'enfant_tshirt') label = 'Enfant -12 +T-shirt';
        }
        var optVal = $('<div/>').text(v).html();   // échappe HTML
        var optLbl = $('<div/>').text(label).html();
        $sel.append('<option value="'+optVal+'">'+optLbl+'</option>');
      });
    }
  });
  if(tshirtMode) $('.filters').hide();
}

/* ══ Bascule Remise T-shirts ════ */
function applyTshirtMode() {
  const hideHeaders = ['Sexe', 'Téléphone', 'Email', 'Naissance', 'Paiement', 'Montant', 'Entreprise', 'Date ajout', 'Origine', 'Actions'];
  // Masquage par clé de données : robuste même si l'admin renomme la colonne
  // (le libellé « Commentaire » est paramétrable, contrairement à son bdd_column).
  const hideData = ['prestation', 'commentaire', 'naissance', 'ville'];
  const aoColumns = tbl.settings()[0].aoColumns;
  tbl.columns().every(function () {
    const h = $(this.header()).text().trim();
    const d = aoColumns[this.index()].data;
    if (hideHeaders.includes(h) || hideData.includes(d)) this.visible(!tshirtMode, false);
  });
  $('.filters').toggle(!tshirtMode);
  if (tshirtMode) {
    $('body').addClass('hide-stats');
    $('#btnExport, #btnArchiveNow').hide();
    $('#colToggleWrap').hide();
  } else {
    $('body').removeClass('hide-stats');
    $('#btnExport, #btnArchiveNow').show();
    $('#colToggleWrap').show();
    updateStats(tbl.data().toArray());
  }
  tbl.rows().invalidate().draw(false);
}
$('#modeTS, #modeTS_m').on('click', function () {
  tshirtMode = !tshirtMode;
  refreshButtons();
  applyTshirtMode();
  if (this.id === 'modeTS_m') {bootstrap.Offcanvas.getInstance('#menuMobile').hide();}
});
applyTshirtMode();

/* ══ MAJ taille T-shirt ════ */
$('#tbl').on('change','.tshirt-dd',function(){
  if(!canEditReg && !canScanQr) {
    alert('Vous n\'avez pas les droits pour modifier les tailles de t-shirts.');
    return;
  }
  fetch('../config/api.php?route=registrations',{method:'PUT',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':_csrfToken},body:new URLSearchParams({id:this.dataset.id,tshirt_size:this.value})});
});

/* ══ SUPPRESSION ════ */
$('#tbl').on('click', '.delete-row', function() {
  const row = tbl.row($(this).closest('tr'));
  const data = row.data();

  if (!confirm(`Êtes-vous sûr de vouloir supprimer l'inscription de ${data.prenom} ${data.nom} ?`)) {
    return;
  }

  fetch('../config/api.php?route=registrations', {
    method: 'DELETE',
    headers: {'Content-Type': 'application/x-www-form-urlencoded','X-CSRF-TOKEN':_csrfToken},
    body: new URLSearchParams({id: data.id})
  })
  .then(response => response.json())
  .then(result => {
    if (result.success || result.ok) {
      // Supprimer la ligne du tableau
      row.remove().draw(false);
      // Mettre à jour les statistiques
      updateStats(tbl.data().toArray());
      alert('Inscription supprimée avec succès');
    } else {
      alert('Erreur lors de la suppression : ' + (result.message || 'Erreur inconnue'));
    }
  })
  .catch(error => {
    console.error('Erreur:', error);
    alert('Erreur de communication avec le serveur');
  });
});

/* ══ AJOUT ════ */
$('#fAdd').on('submit',e=>{
  e.preventDefault();
  if(window.FERInscription && !FERInscription.ensureGuardian(e.target)) return;
  if(window.FERInscription) FERInscription.composeComment(e.target);
  const fd=new FormData(e.target); normalizeBirth(fd);
  fetch('../config/api.php?route=registrations',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':_csrfToken},body:JSON.stringify(Object.fromEntries(fd))})
  .then(r=>r.json()).then(j=>{
    if(j.inscription_no){
      e.target.reset();
      $('#fAdd [name="nom"]').focus();
      // Reset affichage montant
      var _selAdd = document.querySelector('#fAdd .paiement-select');
      if(_selAdd){ var _wA = _selAdd.closest('.col-md-6'); var _dA = _wA && _wA.querySelector('.montant-du-display'); if(_dA){ _dA.style.display='none'; } }
      // Recharge la table puis affiche le toast avec le rang « payant » à jour
      tbl.ajax.reload(function(){ showInscriptionToast(j.inscription_no); }, false);
    }
  });
});

/* Toast d'ajout : n° d'inscription + rang de l'inscrit + éligibilité T-shirt */
function showInscriptionToast(inscriptionNo){
  const hlLimit = <?= (int)$highlightLimit ?>;
  // Numéro brut (toutes inscriptions confondues, payées ou non)
  const seqNo = parseInt(String(inscriptionNo).replace(/[^0-9]/g,'')) || 0;
  // Rang « payant » : on ne compte que les inscrits ayant payé (montant_du > 0).
  // Si l'inscrit vient d'être créé comme « gratuit » → rang = -1 (non éligible).
  const paidRank = computePaidRank(tableData, inscriptionNo);
  const isPaid = paidRank > 0;

  let html = '<div>Inscription <strong>n°'+inscriptionNo+'</strong> enregistrée&nbsp;!</div>'
           + '<div style="font-size:20px;font-weight:800;margin-top:6px;line-height:1.2">'
           +   seqNo+'<sup>e</sup> inscrit</div>';
  if(!isPaid){
    html += '<div style="font-size:12px;margin-top:4px;opacity:.95">Gratuit / Enfant -12 ans — non éligible T-shirt</div>';
  } else if(hlLimit>0){
    html += paidRank<=hlLimit
      ? '<div style="font-size:12px;margin-top:4px;opacity:.95">&#10003; '+paidRank+'ᵉ inscrit payant / '+hlLimit+' — éligible T-shirt</div>'
      : '<div style="font-size:12px;margin-top:4px;opacity:.95">'+paidRank+'ᵉ inscrit payant — au-delà des '+hlLimit+' premiers, non éligible T-shirt</div>';
  }
  showToast(html, 'success', 9000);
}


<?php if ($canBulkCreate): ?>
/* ══ AJOUT MULTIPLE — saisie en lot ════════════════════════════ */
(function() {
  const tmpl       = document.getElementById('bulkRowTemplate');
  const container  = document.getElementById('bulkRows');
  const addBtn     = document.getElementById('bulkAddBtn');
  const dupBtn     = document.getElementById('bulkDuplicateBtn');
  const summary    = document.getElementById('bulkSummary');
  const submitBtn  = document.getElementById('btnBulkSubmit');
  const closeBtn   = document.getElementById('btnBulkClose');
  const progress   = document.getElementById('bulkProgress');
  const logDiv     = document.getElementById('bulkProgressLog');
  const recapDiv   = document.getElementById('bulkRecap');
  const defaultFee = <?= json_encode((float) $registration_fee) ?>;
  const MAX_BULK   = 50; // Limite côté serveur : api.php route bulk-create

  if (!tmpl || !container) return;

  function renumber() {
    const rows = container.querySelectorAll('.bulk-row');
    rows.forEach((r, i) => {
      const t = r.querySelector('.bulk-row-title');
      if (t) t.textContent = '#' + (i + 1);
      // Le bouton "retirer" est désactivé s'il ne reste qu'une personne
      const rm = r.querySelector('.bulk-row-remove');
      if (rm) rm.disabled = (rows.length <= 1);
    });
    updateSummary();
    updateAddButtons();
  }

  function updateAddButtons() {
    const count = container.querySelectorAll('.bulk-row').length;
    const atMax = count >= MAX_BULK;
    addBtn.disabled = atMax;
    dupBtn.disabled = atMax;
    addBtn.title = atMax ? 'Limite de ' + MAX_BULK + ' inscrits atteinte' : '';
    dupBtn.title = atMax ? 'Limite de ' + MAX_BULK + ' inscrits atteinte' : '';
  }

  function updateSummary() {
    const rows = container.querySelectorAll('.bulk-row');
    const sharedSel = document.querySelector('#fBulkAdd [name="shared_paiement_mode"]');
    const isGratuit = sharedSel && sharedSel.value === 'gratuit';
    let total = 0;
    rows.forEach(r => {
      const m = r.querySelector('.bulk-montant');
      let v;
      if (m) {
        v = parseFloat(m.value);
        if (isNaN(v)) v = 0;
      } else {
        // Pas de champ Montant dû dans la ligne (non bulk-visible)
        // → montant calculé serveur-side d'après le paiement partagé
        v = isGratuit ? 0 : defaultFee;
      }
      total += v;
    });
    const max = ' / ' + MAX_BULK + ' max';
    summary.textContent = rows.length + ' personne(s)' + max + ' — Total : ' + total.toFixed(2).replace(/\.00$/, '') + ' €';
  }

  function addRow(sourceRow) {
    // Refuse l'ajout au-delà de la limite
    if (container.querySelectorAll('.bulk-row').length >= MAX_BULK) return;
    const clone = tmpl.content.firstElementChild.cloneNode(true);
    // Si on duplique, recopier les valeurs SAUF nom/prénom (à saisir individuellement)
    if (sourceRow) {
      const skip = ['nom', 'prenom'];
      clone.querySelectorAll('.bulk-field').forEach(f => {
        const bdd = f.dataset.bdd;
        if (skip.includes(bdd)) return;
        const src = sourceRow.querySelector('.bulk-field[data-bdd="' + bdd + '"]');
        if (src) f.value = src.value;
      });
    }
    container.appendChild(clone);
    renumber();
  }

  container.addEventListener('click', e => {
    const btn = e.target.closest('.bulk-row-remove');
    if (!btn) return;
    const row = btn.closest('.bulk-row');
    if (container.querySelectorAll('.bulk-row').length <= 1) return;
    row.remove();
    renumber();
  });

  container.addEventListener('input', e => {
    if (e.target.classList.contains('bulk-montant')) updateSummary();
  });

  // Champ « naissance » intelligent : à la perte de focus, on canonicalise
  // l'âge / l'année / la date saisi (même logique que le formulaire classique).
  container.addEventListener('focusout', e => {
    if (!e.target.classList || !e.target.classList.contains('bulk-birthdate')) return;
    const raw = e.target.value.trim();
    if (!raw || !window.FERInscription) return;
    const n = FERInscription.normalizeBirthValue(raw);
    if (n) e.target.value = n; // reconnu → forme canonique ; sinon on laisse pour correction
  });

  addBtn.addEventListener('click', () => addRow());
  dupBtn.addEventListener('click', () => {
    const rows = container.querySelectorAll('.bulk-row');
    addRow(rows[rows.length - 1] || null);
  });

  /* ══ IMPORT EXCEL → correspondance colonnes ↔ champs → cards ══════════ */
  const excelBtn    = document.getElementById('bulkImportExcelBtn');
  const fileInput   = document.getElementById('bulkExcelFile');
  const editView    = document.getElementById('bulkEditView');
  const mapView     = document.getElementById('bulkMapView');
  const mapTargets  = document.getElementById('bulkMapTargets');
  const mapPool     = document.getElementById('bulkMapPool');
  const mapCancel   = document.getElementById('bulkMapCancel');
  const mapGenerate = document.getElementById('bulkMapGenerate');
  const mapInfo     = document.getElementById('bulkMapInfo');

  let excelColumns = []; // libellés des colonnes du fichier
  let excelRows    = []; // lignes de données (tableaux alignés sur excelColumns)

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = (s == null ? '' : String(s));
    return d.innerHTML;
  }
  // Normalise un libellé pour comparaison (sans accents/casse/ponctuation).
  function normLabel(s) {
    return (s == null ? '' : String(s))
      .normalize("NFD").replace(/[̀-ͯ]/g, "")
      .replace(/[^a-z0-9 ]/gi, ' ').replace(/\s+/g, ' ').trim().toLowerCase();
  }
  // Convertit une date affichée (JJ/MM/AAAA, AAAA-MM-JJ…) en ISO pour <input type=date>.
  function toISODate(v) {
    v = (v == null ? '' : String(v)).trim();
    if (!v) return '';
    if (/^\d{4}-\d{2}-\d{2}/.test(v)) return v.slice(0, 10);
    let m = v.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/);
    if (m) return m[3] + '-' + m[2].padStart(2, '0') + '-' + m[1].padStart(2, '0');
    m = v.match(/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/);
    if (m) return m[1] + '-' + m[2].padStart(2, '0') + '-' + m[3].padStart(2, '0');
    return ''; // format non reconnu : on laisse vide (le champ date refuse le reste)
  }

  function zoneChip(zone) { return zone ? zone.querySelector('.bulk-map-chip') : null; }

  function refreshMap() {
    const zones = [].concat(
      Array.from(mapTargets.querySelectorAll('[data-target-drop]')),
      [mapPool]
    );
    zones.forEach(z => {
      const ph = z.querySelector('.bulk-map-placeholder');
      if (ph) ph.style.display = z.querySelector('.bulk-map-chip') ? 'none' : '';
    });
    const assigned = mapTargets.querySelectorAll('.bulk-map-chip').length;
    mapInfo.textContent = assigned + '/' + excelColumns.length + ' colonne(s) reliée(s)';
  }

  function placeChip(zone, chip) {
    // Une cible ne contient qu'une seule colonne : si occupée, l'ancienne repart au pool.
    if (zone.matches('[data-target-drop]')) {
      const existing = zoneChip(zone);
      if (existing && existing !== chip) mapPool.appendChild(existing);
    }
    zone.appendChild(chip);
    refreshMap();
  }

  /* ─── Éditeur de commentaire : texte libre + cards « colonne » déplaçables ─── */
  // Les cards se glissent depuis « Colonnes de votre fichier » (#bulkMapPool) ;
  // une card déjà dans l'éditeur se déplace par glisser interne.

  // Range (point d'insertion) à partir de coordonnées écran, cross-browser.
  function caretRangeFromPoint(x, y) {
    if (document.caretRangeFromPoint) return document.caretRangeFromPoint(x, y);
    if (document.caretPositionFromPoint) {
      const p = document.caretPositionFromPoint(x, y);
      if (p) { const r = document.createRange(); r.setStart(p.offsetNode, p.offset); r.collapse(true); return r; }
    }
    return null;
  }

  // Crée une card « colonne » insérable dans le texte (atomique + déplaçable).
  function makeEditorChip(index) {
    const chip = document.createElement('span');
    chip.className = 'ce-chip';
    chip.contentEditable = 'false';
    chip.draggable = true;
    chip.dataset.colIndex = index;
    chip.textContent = excelColumns[index];
    chip.addEventListener('dragstart', e => {
      e.dataTransfer.setData('text/plain', String(index));
      e.dataTransfer.effectAllowed = 'move';
      chip.classList.add('dragging'); // marque le déplacement interne
    });
    chip.addEventListener('dragend', () => chip.classList.remove('dragging'));
    return chip;
  }

  // Insère un nœud à un Range donné (ou en fin d'éditeur) et place le curseur après.
  function insertNodeAt(editor, range, node) {
    if (!range || !editor.contains(range.startContainer)) {
      range = document.createRange();
      range.selectNodeContents(editor);
      range.collapse(false); // fin de l'éditeur
    }
    // Ne jamais insérer À L'INTÉRIEUR d'une card : on se place juste après.
    const host = range.startContainer.nodeType === 1 ? range.startContainer : range.startContainer.parentElement;
    const insideChip = host && host.closest ? host.closest('.ce-chip') : null;
    if (insideChip) { range = document.createRange(); range.setStartAfter(insideChip); range.collapse(true); }

    range.insertNode(node); // si `node` existe déjà dans le DOM, il est DÉPLACÉ ici
    const sp = document.createTextNode('​'); // espace nul → permet de taper juste après
    if (node.nextSibling) node.parentNode.insertBefore(sp, node.nextSibling);
    else node.parentNode.appendChild(sp);
    const sel = window.getSelection();
    const after = document.createRange();
    after.setStartAfter(sp); after.collapse(true);
    sel.removeAllRanges(); sel.addRange(after);
  }

  function wireEditor(editor) {
    editor.addEventListener('dragover', e => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      editor.classList.add('drag-over');
    });
    editor.addEventListener('dragleave', () => editor.classList.remove('drag-over'));
    editor.addEventListener('drop', e => {
      e.preventDefault();
      editor.classList.remove('drag-over');
      const idx = e.dataTransfer.getData('text/plain');
      if (idx === '') return;
      const range = caretRangeFromPoint(e.clientX, e.clientY);
      // Une card déjà dans l'éditeur (.ce-chip.dragging) = déplacement interne.
      // Sinon, le glisser vient de « Colonnes de votre fichier » → nouvelle card.
      const moving = editor.querySelector('.ce-chip.dragging');
      if (moving) insertNodeAt(editor, range, moving);
      else        insertNodeAt(editor, range, makeEditorChip(parseInt(idx, 10)));
    });
  }

  // Sérialise l'éditeur : texte + sauts de ligne ; chaque card → jeton index.
  function serializeEditor(editor) {
    let out = '';
    (function walk(node) {
      node.childNodes.forEach(child => {
        if (child.nodeType === 3) {                       // nœud texte
          out += child.nodeValue.replace(/​/g, '');
        } else if (child.nodeType === 1) {                // élément
          if (child.classList && child.classList.contains('ce-chip')) {
            out += '' + child.dataset.colIndex + '';
          } else if (child.tagName === 'BR') {
            out += '\n';
          } else if (/^(DIV|P)$/.test(child.tagName)) {
            if (out && !out.endsWith('\n')) out += '\n';
            walk(child);
          } else {
            walk(child);
          }
        }
      });
    })(editor);
    return out;
  }

  function makeChip(index) {
    const chip = document.createElement('div');
    chip.className = 'bulk-map-chip';
    chip.draggable = true;
    chip.dataset.colIndex = index;
    chip.innerHTML = '<i class="bi bi-grip-vertical"></i> ' + escapeHtml(excelColumns[index]);
    chip.addEventListener('dragstart', e => {
      e.dataTransfer.setData('text/plain', String(index));
      e.dataTransfer.effectAllowed = 'move';
      chip.classList.add('dragging');
    });
    chip.addEventListener('dragend', () => chip.classList.remove('dragging'));
    return chip;
  }

  function wireZone(zone) {
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
      e.preventDefault();
      zone.classList.remove('drag-over');
      const idx = e.dataTransfer.getData('text/plain');
      const chip = mapView.querySelector('.bulk-map-chip[data-col-index="' + idx + '"]');
      if (chip) placeChip(zone, chip);
    });
  }

  // Pré-relie les colonnes dont le nom ressemble à un champ (synonymes courants).
  function autoMap() {
    const synonyms = {
      nom:         ['nom', 'lastname', 'last name', 'name', 'famille'],
      prenom:      ['prenom', 'firstname', 'first name', 'surname'],
      email:       ['email', 'mail', 'courriel', 'e mail', 'adresse mail'],
      tel:         ['tel', 'telephone', 'phone', 'mobile', 'portable', 'gsm'],
      naissance:   ['naissance', 'date de naissance', 'birth', 'birthday', 'ddn', 'age', 'annee'],
      ville:       ['ville', 'city', 'commune', 'localite'],
      sexe:        ['sexe', 'genre', 'gender', 'civilite'],
      tshirt_size: ['taille', 'tshirt', 't shirt', 'size', 'taille tshirt'],
      montant_du:  ['montant', 'montant du', 'prix', 'tarif', 'amount']
    };
    mapTargets.querySelectorAll('.bulk-map-target').forEach(t => {
      if (t.dataset.bdd === 'commentaire') return; // multi-colonnes : choix manuel
      const zone = t.querySelector('[data-target-drop]');
      if (zoneChip(zone)) return;
      const bdd = t.dataset.bdd;
      const tLabel = normLabel(t.querySelector('.bulk-map-target-label').textContent);
      const cands = (synonyms[bdd] || []).concat([normLabel(bdd), tLabel]).filter(Boolean);
      const chips = Array.from(mapPool.querySelectorAll('.bulk-map-chip'));
      const match = chips.find(c => {
        const cl = normLabel(excelColumns[c.dataset.colIndex]);
        return cl && cands.some(x => x === cl || cl.includes(x) || x.includes(cl));
      });
      if (match) placeChip(zone, match);
    });
  }

  function openMapView() {
    // Réinitialise les zones, recrée les puces dans le pool, puis auto-mappe.
    mapTargets.querySelectorAll('.bulk-map-chip').forEach(c => c.remove());
    mapPool.querySelectorAll('.bulk-map-chip').forEach(c => c.remove());
    excelColumns.forEach((_, i) => mapPool.appendChild(makeChip(i)));
    autoMap();
    refreshMap();
    // Commentaire : on repart d'un éditeur vierge à chaque import.
    const editor = mapTargets.querySelector('.bulk-comment-rich');
    if (editor) editor.innerHTML = '';
    editView.style.display = 'none';
    mapView.style.display = '';
    // En vue de correspondance, « Valider les saisies » n'a pas de sens :
    // on l'utilise via « Générer les cards ». On le masque le temps du mapping.
    if (submitBtn) submitBtn.style.display = 'none';
  }

  function closeMapView() {
    mapView.style.display = 'none';
    editView.style.display = '';
    // Retour à l'édition des cards : le bouton de validation réapparaît.
    if (submitBtn) submitBtn.style.display = 'inline-block';
  }

  function setFieldValue(field, value) {
    value = (value == null ? '' : String(value)).trim();
    if (field.tagName === 'SELECT') {
      const opt = Array.from(field.options).find(o =>
        normLabel(o.value) === normLabel(value) || normLabel(o.textContent) === normLabel(value));
      field.value = opt ? opt.value : '';
    } else if (field.type === 'date') {
      field.value = toISODate(value);
    } else {
      field.value = value;
    }
  }

  if (excelBtn && fileInput && mapView) {
    // Câblage des zones de dépôt (une seule fois : le DOM des cibles est fixe).
    mapTargets.querySelectorAll('[data-target-drop]').forEach(wireZone);
    wireZone(mapPool);
    const commentEditor = mapTargets.querySelector('.bulk-comment-rich');
    if (commentEditor) wireEditor(commentEditor);

    excelBtn.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', async () => {
      const file = fileInput.files[0];
      if (!file) return;
      const orig = excelBtn.innerHTML;
      excelBtn.disabled = true;
      excelBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
      try {
        const fd = new FormData();
        fd.append('file', file);
        const res = await fetch('../config/api.php?route=bulk-parse-excel', {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': _csrfToken },
          body: fd,
          credentials: 'same-origin'
        });
        const j = await res.json();
        if (!res.ok || !j.ok) throw new Error(j.error || (res.status + ' ' + res.statusText));
        excelColumns = j.columns || [];
        excelRows    = j.rows || [];
        if (!excelColumns.length || !excelRows.length) throw new Error('Aucune donnée exploitable dans le fichier.');
        openMapView();
      } catch (err) {
        alert('Import Excel : ' + err.message);
      } finally {
        excelBtn.disabled = false;
        excelBtn.innerHTML = orig;
        fileInput.value = ''; // permet de réimporter le même fichier
      }
    });

    mapCancel.addEventListener('click', closeMapView);

    mapGenerate.addEventListener('click', () => {
      // Les champs obligatoires DOIVENT avoir une colonne reliée.
      // Exception : « montant_du » — jamais bloquant, même marqué obligatoire,
      // car il est dérivé du mode de paiement (ou du tarif de la course défini
      // dans les réglages) côté serveur.
      const missing = [];
      mapTargets.querySelectorAll('.bulk-map-target').forEach(t => {
        // « montant_du » dérivé serveur ; « commentaire » = modèle texte libre (pas de dropzone).
        if (t.dataset.bdd === 'montant_du' || t.dataset.bdd === 'commentaire') return;
        if (t.dataset.required === '1' && !zoneChip(t.querySelector('[data-target-drop]'))) {
          missing.push(t.querySelector('.bulk-map-target-label').textContent.replace('*', '').trim());
        }
      });
      if (missing.length) {
        alert('Colonne(s) obligatoire(s) non reliée(s) :\n• ' + missing.join('\n• '));
        return;
      }
      // Construit la correspondance champ → index de colonne.
      // Le commentaire est traité à part : un modèle texte libre avec des jetons [Colonne].
      const map = {};
      mapTargets.querySelectorAll('.bulk-map-target').forEach(t => {
        if (t.dataset.bdd === 'commentaire') return; // géré via le modèle de texte
        const chip = zoneChip(t.querySelector('[data-target-drop]'));
        if (chip) map[t.dataset.bdd] = parseInt(chip.dataset.colIndex, 10);
      });

      const commentEditor = mapTargets.querySelector('.bulk-comment-rich');
      const commentTpl = commentEditor ? serializeEditor(commentEditor) : '';
      // « A du contenu » = il reste du texte une fois les cards retirées, OU il y a au moins une card.
      const commentHasContent = commentTpl.replace(/\d+/g, 'x').trim() !== '';

      // Pour une ligne du fichier : remplace chaque card (jeton index)
      // par sa valeur. Une ligne ne contenant que des cards TOUTES vides est
      // supprimée (évite un « Âge : » orphelin) ; le texte fixe est conservé.
      function buildComment(cells) {
        return commentTpl.split('\n').map(line => {
          let hadToken = false, hadValue = false;
          const out = line.replace(/(\d+)/g, (_, i) => {
            hadToken = true;
            const val = (cells[+i] == null ? '' : String(cells[+i])).trim();
            if (val) hadValue = true;
            return val;
          });
          return (hadToken && !hadValue) ? null : out;
        }).filter(l => l !== null).join('\n');
      }

      // Remplace les cards existantes par une card par ligne du fichier.
      container.innerHTML = '';
      excelRows.forEach(cells => {
        addRow();
        const row = container.lastElementChild;
        if (!row) return;
        Object.keys(map).forEach(bdd => {
          const field = row.querySelector('.bulk-field[data-bdd="' + bdd + '"]');
          if (field) setFieldValue(field, cells[map[bdd]]);
        });
        // Naissance importée : canonicalise âge/année/date pour l'afficher proprement.
        const bField = row.querySelector('.bulk-field.bulk-birthdate');
        if (bField && bField.value.trim() && window.FERInscription) {
          const n = FERInscription.normalizeBirthValue(bField.value.trim());
          if (n) bField.value = n;
        }
        if (commentHasContent) {
          const cField = row.querySelector('.bulk-field[data-bdd="commentaire"]');
          if (cField) cField.value = buildComment(cells);
        }
      });
      renumber();
      closeMapView();
    });
  }

  // Si le paiement partagé passe à "gratuit", on met tous les montants à 0
  const sharedPaiement = document.querySelector('#fBulkAdd [name="shared_paiement_mode"]');
  if (sharedPaiement) {
    sharedPaiement.addEventListener('change', () => {
      const isGratuit = sharedPaiement.value === 'gratuit';
      container.querySelectorAll('.bulk-montant').forEach(m => {
        m.value = isGratuit ? '0' : defaultFee;
      });
      updateSummary();
    });
  }

  // Réinitialise le formulaire à chaque ouverture/fermeture du modal
  document.getElementById('addModal').addEventListener('show.bs.modal', () => {
    // Si le panneau bulk n'a aucune ligne, on en ajoute une
    if (container.querySelectorAll('.bulk-row').length === 0) addRow();
  });
  document.getElementById('addModal').addEventListener('hidden.bs.modal', () => {
    const f = document.getElementById('fBulkAdd');
    if (f) f.reset();
    if (mapView) { excelColumns = []; excelRows = []; closeMapView(); }
    container.innerHTML = '';
    progress.style.display = 'none';
    logDiv.innerHTML = '';
    recapDiv.style.display = 'none';
    submitBtn.style.display = 'inline-block';
    submitBtn.disabled = false;
    submitBtn.innerHTML = 'Valider les saisies';
    closeBtn.style.display = 'none';
    addRow();
  });

  function bulkLog(icon, text, color) {
    const line = document.createElement('div');
    line.style.cssText = 'padding:2px 0;color:' + (color || '#333');
    line.innerHTML = icon + ' ' + text;
    logDiv.appendChild(line);
    logDiv.scrollTop = logDiv.scrollHeight;
  }

  document.getElementById('fBulkAdd').addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.target;

    const shared = {
      entreprise:    form.shared_entreprise.value.trim(),
      email:         form.shared_email.value.trim(),
      paiement_mode: form.shared_paiement_mode.value,
      origine:       form.origine.value,
    };

    const rows = [];
    container.querySelectorAll('.bulk-row').forEach(rowEl => {
      const row = {};
      rowEl.querySelectorAll('.bulk-field').forEach(f => {
        row[f.dataset.bdd] = f.value.trim();
      });
      // Naissance : canonicalise l'âge / l'année / la date avant envoi (comme le
      // formulaire classique). Non reconnu → '' (le serveur valide l'obligatoire).
      if (row.naissance && window.FERInscription) {
        row.naissance = FERInscription.normalizeBirthValue(row.naissance);
      }
      rows.push(row);
    });

    if (rows.length === 0) {
      alert('Ajoutez au moins une personne avant de valider.');
      return;
    }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Validation…';
    progress.style.display = 'block';
    logDiv.innerHTML = '';
    recapDiv.style.display = 'none';
    bulkLog('⏳', 'Envoi de ' + rows.length + ' inscription(s)…', '#666');

    try {
      const res = await fetch('../config/api.php?route=bulk-create', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
        body:    JSON.stringify({ shared, rows }),
        credentials: 'same-origin'
      });

      if (!res.ok) {
        let msg = res.status + ' ' + res.statusText;
        try {
          const j = await res.json();
          if (j && (j.error || j.err)) msg = j.error || j.err;
        } catch (_) {}
        throw new Error(msg);
      }

      const j = await res.json();
      const created = j.created || 0;
      const skipped = (j.errors || []).length;

      bulkLog('✅', '<strong>' + created + '</strong> inscription(s) créée(s)', '#198754');
      (j.errors || []).forEach(err => {
        bulkLog('⚠️', 'Ligne ' + (err.index + 1) + ' ignorée : ' + err.reason, '#e67e22');
      });
      if (j.mail_sent) bulkLog('📧', 'Mail récapitulatif envoyé à ' + shared.email, '#0d6efd');
      else if (j.mail_error) bulkLog('❌', 'Mail récap échoué : ' + j.mail_error, '#dc3545');

      recapDiv.style.display = 'block';
      recapDiv.innerHTML = '<div class="d-flex gap-3 flex-wrap">'
        + '<div class="border rounded px-3 py-2 text-center flex-fill border-success"><div class="text-muted" style="font-size:12px">Créées</div><div class="fw-bold fs-5 text-success">' + created + '</div></div>'
        + '<div class="border rounded px-3 py-2 text-center flex-fill border-warning"><div class="text-muted" style="font-size:12px">Ignorées</div><div class="fw-bold fs-5 text-warning">' + skipped + '</div></div>'
        + '</div>';

      submitBtn.style.display = 'none';
      closeBtn.style.display = 'inline-block';
    } catch (err) {
      bulkLog('❌', 'Erreur : ' + err.message, '#dc3545');
      submitBtn.disabled = false;
      submitBtn.innerHTML = 'Valider les saisies';
    }
  });

  closeBtn.addEventListener('click', () => {
    bootstrap.Modal.getInstance('#addModal').hide();
    tbl.ajax.reload(null, false);
  });
})();
<?php endif; ?>

/* ══ ÉDITION ════ */
$('#tbl').on('click','button.edit',function(){
  const d=tbl.row($(this).closest('tr')).data();
  Object.entries(d).forEach(([k,v])=>$('#fEdit [name="'+k+'"]').val(v));
  // La catégorie « enfant t-shirt » est stockée avec paiement_mode='en ligne (CB)' :
  // on resélectionne le bon choix du menu d'après la prestation.
  if(String(d.prestation||'').toLowerCase()==='enfant_tshirt'){
    $('#fEdit [name="paiement_mode"]').val('enfant_tshirt');
  }
  if(window.FERInscription) FERInscription.refresh(document.getElementById('fEdit'));
  new bootstrap.Modal('#editModal').show();
});
$('#fEdit').on('submit',e=>{
  e.preventDefault();
  if(window.FERInscription && !FERInscription.ensureGuardian(e.target)) return;
  if(window.FERInscription) FERInscription.composeComment(e.target);
  const fd=new FormData(e.target); normalizeBirth(fd);
  fetch('../config/api.php?route=registrations',{method:'PUT',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':_csrfToken},body:new URLSearchParams(fd)})
  .then(()=>{tbl.ajax.reload(null,false); bootstrap.Modal.getInstance('#editModal').hide();});
});

/* ══ IMPORT EXCEL — Preview on file select ════ */
document.getElementById('importFileInput').addEventListener('change', async function() {
  const file = this.files[0];
  const preview = document.getElementById('importPreview');
  const loading = document.getElementById('importPreviewLoading');
  const result  = document.getElementById('importPreviewResult');
  const btnImport = document.getElementById('btnImportSubmit');

  btnImport.disabled = true;
  result.style.display = 'none';
  result.innerHTML = '';

  if (!file) { preview.style.display = 'none'; return; }

  preview.style.display = 'block';
  loading.style.display = 'block';

  try {
    const data = await file.arrayBuffer();
    const wb   = XLSX.read(data, {type:'array'});
    const ws   = wb.Sheets[wb.SheetNames[0]];

    // Certains exports Excel écrivent une plage (!ref) incorrecte ne couvrant
    // que la 1re ligne (ex: dimension "A1:AB1" alors que la feuille contient
    // plusieurs lignes). SheetJS se fie à cette plage et ignore alors les
    // données -> "fichier semble vide". On recalcule la plage réelle à partir
    // des cellules réellement présentes pour ne perdre aucune ligne.
    (function fixSheetRange(){
      var minR = Infinity, minC = Infinity, maxR = -1, maxC = -1;
      for (var addr in ws) {
        if (!ws.hasOwnProperty(addr) || addr.charAt(0) === '!') continue;
        var cell = XLSX.utils.decode_cell(addr);
        if (cell.r < minR) minR = cell.r;
        if (cell.c < minC) minC = cell.c;
        if (cell.r > maxR) maxR = cell.r;
        if (cell.c > maxC) maxC = cell.c;
      }
      if (maxR >= 0) {
        var realRef = XLSX.utils.encode_range({s:{r:minR,c:minC}, e:{r:maxR,c:maxC}});
        if (ws['!ref'] !== realRef) ws['!ref'] = realRef;
      }
    })();

    const rows = XLSX.utils.sheet_to_json(ws, {header:1});

    if (rows.length < 2) {
      loading.style.display = 'none';
      result.style.display = 'block';
      result.innerHTML = '<div class="alert alert-warning mb-0 py-2"><i class="bi bi-exclamation-triangle me-1"></i>Le fichier semble vide.</div>';
      return;
    }

    const header = rows[0].map(function(h){ return (h||'').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9 ]/g,'').trim(); });

    // Filter out empty rows (rows where all cells are empty/null/undefined)
    var dataRows = [];
    for (var r = 1; r < rows.length; r++) {
      var row = rows[r];
      if (!row || !row.length) continue;
      var hasData = false;
      for (var c = 0; c < row.length; c++) {
        if (row[c] !== null && row[c] !== undefined && row[c].toString().trim() !== '') { hasData = true; break; }
      }
      if (hasData) dataRows.push(row);
    }
    const totalRows = dataRows.length;

    // Find ticket column (numero billet)
    var ticketCol = -1;
    for (var i = 0; i < header.length; i++) {
      if (header[i].indexOf('numero') !== -1 && header[i].indexOf('billet') !== -1) { ticketCol = i; break; }
    }

    var tickets = [];
    if (ticketCol >= 0) {
      for (var r = 0; r < dataRows.length; r++) {
        var v = dataRows[r][ticketCol];
        if (v && !isNaN(v)) tickets.push(parseInt(v));
      }
    }

    // Check duplicates server-side
    var dupCount = 0;
    var dupTickets = [];
    if (tickets.length > 0) {
      try {
        var res = await fetch('../config/api.php?route=check-duplicates', {
          method: 'POST',
          headers: {'Content-Type':'application/json', 'X-CSRF-TOKEN': _csrfToken},
          body: JSON.stringify({tickets: tickets}),
          credentials: 'same-origin'
        });
        var json = await res.json();
        dupTickets = json.duplicates || [];
        dupCount = dupTickets.length;
      } catch(e) { /* ignore, non-blocking */ }
    }

    loading.style.display = 'none';
    result.style.display = 'block';

    var html = '<div class="d-flex gap-3 flex-wrap">';
    html += '<div class="border rounded px-3 py-2 text-center flex-fill" style="min-width:120px">';
    html += '<div class="text-muted" style="font-size:12px">Inscrits</div>';
    html += '<div class="fw-bold fs-5 text-primary">' + totalRows + '</div></div>';

    if (dupCount > 0) {
      html += '<div class="border rounded px-3 py-2 text-center flex-fill border-warning" style="min-width:120px">';
      html += '<div class="text-muted" style="font-size:12px">Doublons</div>';
      html += '<div class="fw-bold fs-5 text-warning">' + dupCount + '</div></div>';
    } else {
      html += '<div class="border rounded px-3 py-2 text-center flex-fill border-success" style="min-width:120px">';
      html += '<div class="text-muted" style="font-size:12px">Doublons</div>';
      html += '<div class="fw-bold fs-5 text-success">0</div></div>';
    }

    var newRows = totalRows - dupCount;
    html += '<div class="border rounded px-3 py-2 text-center flex-fill" style="min-width:120px">';
    html += '<div class="text-muted" style="font-size:12px">Nouveaux</div>';
    html += '<div class="fw-bold fs-5 text-success">' + newRows + '</div></div>';
    html += '</div>';

    if (dupCount > 0) {
      html += '<div class="alert alert-warning mt-2 mb-0 py-2" style="font-size:13px">';
      html += '<i class="bi bi-info-circle me-1"></i>' + dupCount + ' doublon(s) seront ignorés lors de l\'import.';
      html += '</div>';
    }

    result.innerHTML = html;
    btnImport.disabled = false;

  } catch(err) {
    loading.style.display = 'none';
    result.style.display = 'block';
    result.innerHTML = '<div class="alert alert-danger mb-0 py-2">Impossible de lire le fichier : ' + err.message + '</div>';
  }
});

// Reset preview when modal closes
document.getElementById('importModal').addEventListener('hidden.bs.modal', function() {
  document.getElementById('fImport').reset();
  document.getElementById('importPreview').style.display = 'none';
  document.getElementById('importPreviewResult').innerHTML = '';
  document.getElementById('importProgress').style.display = 'none';
  document.getElementById('importProgressLog').innerHTML = '';
  document.getElementById('importRecap').style.display = 'none';
  document.getElementById('btnImportSubmit').disabled = true;
  document.getElementById('btnImportSubmit').style.display = 'inline-block';
  document.getElementById('btnImportClose').style.display = 'none';
});

document.getElementById('btnImportClose').addEventListener('click', function() {
  location.reload();
});

/* ══ IMPORT EXCEL — Submit ════ */
document.getElementById('fImport').addEventListener('submit', async (e) => {
  e.preventDefault();

  const form   = e.target;
  const button = document.getElementById('btnImportSubmit');
  const data   = new FormData(form);
  const progressDiv = document.getElementById('importProgress');
  const logDiv = document.getElementById('importProgressLog');
  const recapDiv = document.getElementById('importRecap');
  const closeBtn = document.getElementById('btnImportClose');

  button.disabled = true;
  button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Import en cours…';
  progressDiv.style.display = 'block';
  logDiv.innerHTML = '';
  recapDiv.style.display = 'none';

  function addLog(icon, text, color) {
    const line = document.createElement('div');
    line.style.cssText = 'padding:3px 0;color:' + (color || '#333');
    line.innerHTML = icon + ' ' + text;
    logDiv.appendChild(line);
    logDiv.scrollTop = logDiv.scrollHeight;
  }

  addLog('⏳', 'Import en cours…', '#666');

  try {
    const res = await fetch('../config/api.php?route=import-excel', {
      method:      'POST',
      headers:     {'X-CSRF-TOKEN': _csrfToken},
      body:        data,
      credentials: 'same-origin'
    });

    if (!res.ok) {
      let msg = res.status + ' ' + res.statusText;
      try {
        const j = await res.json();
        if (j && j.error) {
          msg = j.error;
          if (j.missing && j.missing.length) msg += ' — voir logs pour plus d\'infos';
        }
      } catch (e) { /* corps non-JSON, on garde le code HTTP */ }
      throw new Error(msg);
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let finalResult = null;

    while (true) {
      const {done, value} = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, {stream: true});

      const lines = buffer.split('\n');
      buffer = lines.pop();

      for (const line of lines) {
        if (!line.trim()) continue;
        try {
          const evt = JSON.parse(line);
          if (evt.type === 'import_ok') {
            addLog('✅', '<strong>' + evt.count + '</strong> inscription(s) importée(s) en BDD', '#198754');
          } else if (evt.type === 'import_skip') {
            addLog('⚠️', evt.count + ' ligne(s) ignorée(s) — ' + evt.duplicates + ' doublon(s)', '#e67e22');
          } else if (evt.type === 'mail_sent') {
            addLog('📧', 'Mail envoyé pour <strong>' + evt.inscription_no + '</strong>' + (evt.qrcode ? ' (avec QR code)' : ''), '#0d6efd');
          } else if (evt.type === 'mail_error') {
            addLog('❌', 'Échec mail pour ' + evt.inscription_no + ' : ' + evt.error, '#dc3545');
          } else if (evt.type === 'mail_skip') {
            addLog('⏭️', 'Mails non envoyés (désactivé)', '#666');
          } else if (evt.type === 'done') {
            finalResult = evt;
          } else if (evt.type === 'error') {
            addLog('❌', 'Erreur : ' + evt.message, '#dc3545');
          }
        } catch(e) {}
      }
    }

    if (finalResult) {
      recapDiv.style.display = 'block';
      recapDiv.innerHTML = '<div class="d-flex gap-3 flex-wrap">'
        + '<div class="border rounded px-3 py-2 text-center flex-fill border-success"><div class="text-muted" style="font-size:12px">Importées</div><div class="fw-bold fs-5 text-success">' + finalResult.rows_added + '</div></div>'
        + '<div class="border rounded px-3 py-2 text-center flex-fill border-warning"><div class="text-muted" style="font-size:12px">Ignorées</div><div class="fw-bold fs-5 text-warning">' + finalResult.rows_skipped + '</div></div>'
        + '<div class="border rounded px-3 py-2 text-center flex-fill border-primary"><div class="text-muted" style="font-size:12px">Mails envoyés</div><div class="fw-bold fs-5 text-primary">' + finalResult.mails_sent + '</div></div>'
        + '</div>';
    }

    button.style.display = 'none';
    closeBtn.style.display = 'inline-block';

  } catch (err) {
    addLog('❌', 'Erreur : ' + err.message, '#dc3545');
    button.innerHTML = 'Importer';
    button.style.display = 'inline-block';
    button.disabled = false;
  }
});


/* ══ Colonnes redimensionnables + toggle visibilité ════ */
(function() {
  var table = document.getElementById('tbl');
  if (!table) return;
  var uid = <?= json_encode($_SESSION['uid'] ?? 0) ?>;
  var storageKeyVis = 'fer_col_vis_' + uid;
  var storageKeyW = 'fer_col_w_' + uid;

  // Column names for toggle — built dynamically from actual DataTable headers (skip hidden col 0)
  var colNames = [];
  tbl.columns().every(function(idx) {
    if (idx === 0) return; // skip hidden id column
    colNames.push($(this.header()).text().trim() || 'Col ' + idx);
  });

  // ── Restore column visibility ──
  function restoreVisibility() {
    try {
      var saved = JSON.parse(localStorage.getItem(storageKeyVis));
      if (saved && typeof tbl !== 'undefined') {
        for (var i in saved) {
          var colIdx = parseInt(i) + 1;
          tbl.column(colIdx).visible(saved[i]);
        }
        // Sync filter row
        setTimeout(function() {
          var filterCells = table.querySelectorAll('thead tr.filters th');
          if (filterCells.length) {
            filterCells.forEach(function(cell, idx) {
              cell.style.display = tbl.column(idx).visible() ? '' : 'none';
            });
          }
        }, 100);
      }
    } catch(e) {}
  }

  function saveVisibility() {
    try {
      var vis = {};
      for (var i = 0; i < colNames.length; i++) {
        vis[i] = tbl.column(i + 1).visible();
      }
      localStorage.setItem(storageKeyVis, JSON.stringify(vis));
    } catch(e) {}
  }

  // ── Restore column widths ──
  function restoreWidths() {
    try {
      var saved = JSON.parse(localStorage.getItem(storageKeyW));
      if (!saved) return;
      var ths = table.querySelectorAll('thead tr:first-child th');
      ths.forEach(function(th, i) {
        if (saved[i]) { th.style.width = saved[i]; th.style.minWidth = saved[i]; }
      });
    } catch(e) {}
  }

  function saveWidths() {
    try {
      var widths = {};
      var ths = table.querySelectorAll('thead tr:first-child th');
      ths.forEach(function(th, i) {
        if (th.style.width) widths[i] = th.style.width;
      });
      localStorage.setItem(storageKeyW, JSON.stringify(widths));
    } catch(e) {}
  }

  // ── Column resize handles ──
  function initResize() {
    var ths = table.querySelectorAll('thead tr:first-child th');
    ths.forEach(function(th) {
      if (th.querySelector('.col-resize')) return;
      var handle = document.createElement('div');
      handle.className = 'col-resize';
      th.appendChild(handle);

      handle.addEventListener('mousedown', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var startX = e.pageX, startW = th.offsetWidth;
        handle.classList.add('active');

        function onMove(e2) {
          th.style.width = Math.max(40, startW + e2.pageX - startX) + 'px';
          th.style.minWidth = th.style.width;
        }
        function onUp() {
          handle.classList.remove('active');
          document.removeEventListener('mousemove', onMove);
          document.removeEventListener('mouseup', onUp);
          saveWidths();
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
      });
    });
    restoreWidths();
  }

  // ── Column toggle button ──
  function buildColToggle() {
    var lengthEl = document.querySelector('#tbl_length');
    if (!lengthEl || document.getElementById('colToggleWrap')) return;

    // Create a bar above the table: Show X entries (left) ... Colonnes (right)
    var bar = document.createElement('div');
    bar.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;';

    // Move lengthEl into the bar
    var lengthParent = lengthEl.parentElement;
    bar.appendChild(lengthEl);

    var wrap = document.createElement('div');
    wrap.className = 'col-toggle-wrap';
    wrap.id = 'colToggleWrap';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'col-toggle-btn';
    btn.innerHTML = '<i class="bi bi-layout-three-columns"></i> Colonnes';

    var dropdown = document.createElement('div');
    dropdown.className = 'col-toggle-dropdown';

    colNames.forEach(function(name, i) {
      var colIdx = i + 1;
      var label = document.createElement('label');
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.checked = tbl.column(colIdx).visible();
      cb.style.accentColor = '#F42182';
      cb.addEventListener('change', function() {
        tbl.column(colIdx).visible(this.checked);
        saveVisibility();
        // Sync filter row visibility
        var filterCells = table.querySelectorAll('thead tr.filters th');
        if (filterCells.length) {
          filterCells.forEach(function(cell, idx) {
            cell.style.display = tbl.column(idx).visible() ? '' : 'none';
          });
        }
      });
      label.appendChild(cb);
      label.appendChild(document.createTextNode(' ' + name));
      dropdown.appendChild(label);
    });

    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      dropdown.classList.toggle('show');
    });
    document.addEventListener('click', function(e) {
      if (!wrap.contains(e.target)) dropdown.classList.remove('show');
    });

    wrap.appendChild(btn);
    wrap.appendChild(dropdown);
    bar.appendChild(wrap);

    // Insère la barre AU-DESSUS du tableau, mais HORS du conteneur à défilement
    // horizontal (.table-responsive). Sinon la barre vit dans la zone qui défile :
    // elle s'étire à la largeur (large) du tableau, donc le bouton « Colonnes »
    // collé à droite part au milieu de la page quand on scrolle horizontalement.
    // En la plaçant avant .table-responsive, elle reste à la largeur de la page
    // (bouton toujours à droite) tout en gardant l'ordre : recherche → barre → tableau.
    var tableEl = document.getElementById('tbl');
    var scrollBox = tableEl.closest('.table-responsive')
                 || tableEl.closest('.dataTables_scrollBody')
                 || tableEl.closest('.dataTables_wrapper')
                 || tableEl.parentElement;
    scrollBox.parentElement.insertBefore(bar, scrollBox);
  }

  // ── Sort le pied de tableau (info "Showing X to Y" + pagination) HORS du
  //    conteneur à défilement horizontal (.table-responsive), pour qu'il reste
  //    à la largeur de la page. Seul le tableau défile, pas l'info/pagination. ──
  function moveTableFooterOut() {
    var tableEl = document.getElementById('tbl');
    if (!tableEl) return;
    var scrollBox = tableEl.closest('.table-responsive');
    if (!scrollBox) return;
    var info     = document.getElementById('tbl_info');
    var paginate = document.getElementById('tbl_paginate');
    if (!info && !paginate) return;

    var footer = document.getElementById('tblFooterBar');
    if (!footer) {
      footer = document.createElement('div');
      footer.id = 'tblFooterBar';
      footer.style.cssText = 'display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-top:8px;';
      scrollBox.parentElement.insertBefore(footer, scrollBox.nextSibling); // juste après le tableau scrollable
    }
    if (info     && info.parentElement     !== footer) footer.appendChild(info);     // gauche
    if (paginate && paginate.parentElement !== footer) footer.appendChild(paginate); // droite
  }

  // ── Init ──
  if (typeof $ !== 'undefined' && $.fn.dataTable) {
    $('#tbl').on('init.dt', function() {
      restoreVisibility();
      buildColToggle();
      moveTableFooterOut();
      initResize();
    });
    $('#tbl').on('draw.dt', function() { moveTableFooterOut(); initResize(); });
  }
})();

/* ══ QR CODE SCANNER — Remise T-shirt ════ */
(function(){
  var html5QrCode = null;
  var qrModal = document.getElementById('qrScanModal');
  var highlightLimit = <?= (int)$highlightLimit ?>;
  var scannerRunning = false;
  var lastScannedNo = null;

  function hideScanner() {
    document.getElementById('qrReader').style.display = 'none';
    document.getElementById('qrManualZone').style.display = 'none';
  }

  function showScanner() {
    document.getElementById('qrReader').style.display = '';
    document.getElementById('qrManualZone').style.display = '';
  }

  function resetPersonCard() {
    document.getElementById('qrPersonCard').style.display = 'none';
    document.getElementById('qrSaveStatus').style.display = 'none';
    document.querySelectorAll('.qr-size-btn').forEach(function(b){ b.classList.remove('btn-primary','active'); b.classList.add('btn-outline-dark'); });
    lastScannedNo = null;
    showScanner();
  }

  function lookupPerson(no) {
    no = String(no).trim();
    if (!no || no === lastScannedNo) return;
    lastScannedNo = no;

    // Find in DataTable data
    var allData = tbl.data().toArray();
    var person = null;
    var paidRank = -1;

    // Tri chronologique pour calculer le rang « payant » (cohérent avec
    // l'éligibilité T-shirt : on ignore les inscrits non-payés).
    var sorted = allData.slice().sort(function(a,b){
      return new Date(a.created_at) - new Date(b.created_at);
    });
    var paidCount = 0;
    for (var i = 0; i < sorted.length; i++) {
      var ino = String(sorted[i].inscription_no);
      var paidHere = parseFloat(sorted[i].montant_du) > 0;
      if (paidHere) paidCount++;
      // Correspondance exacte ou sans préfixe (compatibilité anciens QR codes)
      if (ino === no || ino === 'E' + no || ino === 'S' + no) {
        person = sorted[i];
        paidRank = paidHere ? paidCount : -1;
        lastScannedNo = ino;
        break;
      }
    }

    if (!person) {
      hideScanner();
      document.getElementById('qrPersonCard').style.display = 'block';
      document.getElementById('qrEligibility').innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle me-1"></i>Inscription N°' + no + ' introuvable</div>';
      document.getElementById('qrPersonName').textContent = '';
      document.getElementById('qrPersonNo').textContent = '';
      document.getElementById('qrPersonVille').textContent = '';
      document.getElementById('qrTshirtZone').style.display = 'none';
      return;
    }

    // Hide scanner, show person card
    hideScanner();
    document.getElementById('qrPersonCard').style.display = 'block';
    document.getElementById('qrTshirtZone').style.display = 'block';
    document.getElementById('qrSaveStatus').style.display = 'none';
    document.getElementById('qrPersonName').textContent = (person.prenom || '') + ' ' + (person.nom || '');
    document.getElementById('qrPersonNo').textContent = 'N°' + person.inscription_no;
    document.getElementById('qrPersonVille').textContent = person.ville || '';

    // Eligibility :
    //   - non-payé (montant_du = 0) → jamais éligible
    //   - payé : éligible si dans les X premiers PAYANTS (ou si pas de limite)
    var isPaid = paidRank > 0;
    var eligible = isPaid && ((highlightLimit === 0) || (paidRank <= highlightLimit));
    var eligDiv = document.getElementById('qrEligibility');
    if (!isPaid) {
      eligDiv.innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle-fill me-2"></i><strong>Non éligible T-shirt</strong> — inscription non payée (Gratuit / Enfant -12 ans)</div>';
    } else if (eligible) {
      eligDiv.innerHTML = '<div class="alert alert-success py-2 mb-0"><i class="bi bi-check-circle-fill me-2"></i><strong>Éligible T-shirt</strong>' + (highlightLimit > 0 ? ' — '+paidRank+'ᵉ inscrit payant / ' + highlightLimit : '') + '</div>';
    } else {
      eligDiv.innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle-fill me-2"></i><strong>Non éligible T-shirt</strong> — '+paidRank+'ᵉ inscrit payant (limite : ' + highlightLimit + ')</div>';
    }

    // Avertissement si déjà scanné (taille déjà renseignée)
    var currentSize = person.tshirt_size || '-';
    if (currentSize !== '-') {
      var warnDiv = document.getElementById('qrSaveStatus');
      warnDiv.style.display = 'block';
      warnDiv.innerHTML = '<div class="alert alert-warning py-2 mb-0" style="font-size:15px;font-weight:600"><i class="bi bi-exclamation-triangle-fill me-2"></i>Attention : déjà scanné (taille ' + currentSize + ')</div>';
    }

    // Highlight current size
    document.querySelectorAll('.qr-size-btn').forEach(function(b){
      b.classList.remove('btn-primary','active');
      b.classList.add('btn-outline-dark');
      if (b.dataset.size === currentSize) {
        b.classList.remove('btn-outline-dark');
        b.classList.add('btn-primary','active');
      }
    });

    // Also highlight in main table
    document.getElementById('quickSearch').value = String(no);
    tbl.search(String(no)).draw();
  }

  // T-shirt size buttons
  document.getElementById('qrTshirtBtns').addEventListener('click', function(e){
    var btn = e.target.closest('.qr-size-btn');
    if (!btn || !lastScannedNo) return;

    var size = btn.dataset.size;

    // Find the person's DB id
    var allData = tbl.data().toArray();
    var person = allData.find(function(p){ return String(p.inscription_no) === String(lastScannedNo); });
    if (!person) return;

    // Highlight button
    document.querySelectorAll('.qr-size-btn').forEach(function(b){ b.classList.remove('btn-primary','active'); b.classList.add('btn-outline-dark'); });
    btn.classList.remove('btn-outline-dark');
    btn.classList.add('btn-primary','active');

    // Save to DB
    var status = document.getElementById('qrSaveStatus');
    status.style.display = 'block';
    status.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Sauvegarde…</span>';

    fetch('../config/api.php?route=registrations', {
      method: 'PUT',
      headers: {'Content-Type':'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': _csrfToken},
      body: new URLSearchParams({id: person.id, tshirt_size: size})
    }).then(function(){
      status.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i><strong>' + size + '</strong> enregistré pour ' + (person.prenom||'') + ' ' + (person.nom||'') + '</span>';
      tbl.ajax.reload(null, false);
      // Auto-reset after 1s for next scan
      setTimeout(function(){
        resetPersonCard();
        document.getElementById('qrManualInput').value = '';
        document.getElementById('qrManualInput').focus();
      }, 1000);
    }).catch(function(){
      status.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Erreur de sauvegarde</span>';
    });
  });

  // Open modal
  document.getElementById('btnScanQR').addEventListener('click', function(e){
    e.preventDefault();
    var dd = document.getElementById('ocDropdown');
    if (dd) dd.classList.remove('show');
    new bootstrap.Modal(qrScanModal).show();
  });

  // Start scanner on modal open
  qrModal.addEventListener('shown.bs.modal', function(){
    resetPersonCard();
    document.getElementById('qrManualInput').value = '';
    html5QrCode = new Html5Qrcode('qrReader');
    html5QrCode.start(
      { facingMode: 'environment' },
      { fps: 10, qrbox: { width: 250, height: 250 } },
      function onScanSuccess(decodedText) {
        var val = decodedText.trim();
        if (val) lookupPerson(val);
      },
      function onScanFailure() {}
    ).then(function(){ scannerRunning = true; })
    .catch(function(){
      scannerRunning = false;
      document.getElementById('qrReader').innerHTML =
        '<div class="alert alert-warning text-center py-3">' +
        '<i class="bi bi-camera-video-off d-block mb-2" style="font-size:2rem"></i>' +
        'Caméra non disponible. Utilisez la saisie manuelle.</div>';
    });
  });

  // Stop scanner on modal close
  qrModal.addEventListener('hidden.bs.modal', function(){
    if (html5QrCode && scannerRunning) {
      html5QrCode.stop().catch(function(){});
      scannerRunning = false;
    }
    if (html5QrCode) { html5QrCode.clear(); html5QrCode = null; }
    resetPersonCard();
    // Clear search
    document.getElementById('quickSearch').value = '';
    tbl.search('').draw();
  });

  // Next scan button
  document.getElementById('qrNextScan').addEventListener('click', function(){
    resetPersonCard();
    document.getElementById('qrManualInput').value = '';
    showScanner();
  });

  // Manual input
  document.getElementById('qrManualBtn').addEventListener('click', function(){
    var val = document.getElementById('qrManualInput').value;
    if (val) lookupPerson(val);
  });
  document.getElementById('qrManualInput').addEventListener('keydown', function(e){
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('qrManualBtn').click(); }
  });
})();
</script>
</body>
</html>
