<?php
require '../src/core/config.php';
require_once '../src/security/csrf.php';
requirePage('qr_code');
$role = currentRole();
$canWrite     = canDoAction('qrcode.write');
$pageReadOnly = !$canWrite;
require __DIR__ . '/../src/partials/navbar-data.php';

// Bloquer toute action POST si pas le droit d'écriture
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canWrite) {
    http_response_code(403);
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Action non autorisée (lecture seule).'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Récupération des organisations existantes
try {
    $stmt = $pdo->prepare('SELECT DISTINCT organisation FROM users WHERE organisation IS NOT NULL AND organisation != ""');
    $stmt->execute();
    $organisations = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log('[QR_CODE] ' . $e->getMessage());
    $organisations = [];
}

// Récupération des données pour l'affichage
try {
    $stmt = $pdo->prepare(
        'SELECT *
           FROM setting
          WHERE id = :id
          LIMIT 1');
    $stmt->execute(['id' => 1]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    error_log('[QR_CODE] ' . $e->getMessage());
    $data = [];
}

$title  = $data['title']   ?? '';
$picture= $data['picture'] ?? '';  


// Construction de l'URL de base par défaut : elle pointe directement sur le
// formulaire public d'inscription (…/public/register), pas seulement le domaine.
// Le préfixe applicatif est calculé comme dans config.php (SCRIPT_NAME sans le
// nom de fichier, remonté d'un cran si on est dans inc/). Reste modifiable.
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$__dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$__lastSeg = basename($__dir);
$__appRoot = in_array($__lastSeg, ['inc', 'public', 'config', 'errors'], true) ? dirname($__dir) : $__dir;
$__appRoot = rtrim(str_replace('\\', '/', $__appRoot), '/');
if ($__appRoot === '/' || $__appRoot === '.' || $__appRoot === '') $__appRoot = '';
$baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . $__appRoot . '/public/register';

// Méthodes de paiement proposées par défaut dans le menu déroulant (identiques à
// la création et à l'édition). L'option « Autre… » révèle un champ libre.
$paymentMethods = ['retrait t-shirt', 'sur place', 'espèces', 'CB', 'chèque', 'CB/espèces jour J'];
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>QR Codes</title>
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">

<!-- CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.css" rel="stylesheet">

<style>
.card-dashboard{margin-top:1rem;border-radius:1.25rem;box-shadow:0 0 25px rgba(0,0,0,.1)}
.qr-preview{max-width:200px;max-height:200px;border:2px solid var(--border);border-radius:6px;padding:10px;margin:10px 0}
.qr-actions .btn{margin:2px}
.token-display{font-family:monospace;font-size:0.85rem;background:var(--surface-2);padding:5px 10px;border-radius:4px;word-break:break-all}
#qrTable { width: 100% !important; }
#qrTable td, #qrTable th { white-space: nowrap; }
#qrTable td:nth-child(4) { max-width: 180px; overflow: hidden; text-overflow: ellipsis; }
#qrTable td:nth-child(5) { max-width: 120px; overflow: hidden; text-overflow: ellipsis; }
.qr-actions { display: flex; gap: 2px; flex-wrap: nowrap; }
</style>
</head>

<body>

<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>
  <div>
    
    <div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-3 gap-3">
      <h1 class="mb-0 fw-bold"><i class="bi bi-qr-code me-2"></i>Gestion des QR Codes</h1>
      <div class="d-flex gap-2">
        <button class="btn btn-rose" data-bs-toggle="modal" data-bs-target="#createQrModal">
          <i class="bi bi-qr-code"></i> Nouveau QR Code
        </button>
      </div>
    </div>

      <div class="table-responsive">
        <table id="qrTable" class="table table-striped" style="width:100%">
          <thead>
            <tr>
              <th>ID</th>
              <th>Organisation</th>
              <th>Token</th>
              <th>URL</th>
              <th>Description</th>
              <th>Statut</th>
              <th>Cree le</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
  </div>

<!-- Modal Création QR Code -->
<div class="modal fade" id="createQrModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Créer un nouveau QR Code</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="createQrForm">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Organisation <span class="text-danger">*</span></label>
              <select name="organisation" class="form-select" required>
                <option value="">Sélectionner une organisation...</option>
                <?php foreach($organisations as $org): ?>
                  <option value="<?= htmlspecialchars($org) ?>"><?= htmlspecialchars($org) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Description</label>
              <input type="text" name="description" class="form-control" placeholder="Ex: QR Code pour événement X">
            </div>
            <div class="col-12">
              <label class="form-label">URL de base du formulaire <span class="text-danger">*</span></label>
              <input type="url" name="base_url" class="form-control" value="<?= htmlspecialchars($baseUrl) ?>" required>
              <small class="text-muted">Token ajouté automatiquement.</small>
            </div>

            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="onsiteMode" name="onsite_mode" checked>
                <label class="form-check-label fw-semibold" for="onsiteMode">Inscription sur place (choix de la prestation)</label>
              </div>
              <small class="text-muted d-block">Choix de prestation au lieu du champ Paiement.</small>
            </div>
            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="sendQrcode" name="send_qrcode" checked>
                <label class="form-check-label fw-semibold" for="sendQrcode">Envoyer le QR code dans l'e-mail</label>
              </div>
              <small class="text-muted d-block">Coché : selon la config du site. Décoché : e-mail sans QR code.</small>
            </div>
            <div class="col-md-6">
              <label class="form-label">Date de fermeture du lien <span class="text-danger">*</span></label>
              <input type="datetime-local" name="expires_at" id="createExpiresAt" class="form-control" required>
              <small class="text-muted">Passé cette date, le lien devient inactif.</small>
            </div>
            <div class="col-md-6" id="paymentLabelWrap">
              <label class="form-label">Méthode de paiement enregistrée <span class="text-danger">*</span></label>
              <select class="form-select payment-label-select" id="createPaymentSelect">
                <?php foreach ($paymentMethods as $pm): ?>
                  <option value="<?= htmlspecialchars($pm) ?>"><?= htmlspecialchars($pm) ?></option>
                <?php endforeach; ?>
                <option value="__custom__">Autre (saisir manuellement)…</option>
              </select>
              <input type="text" class="form-control mt-2 payment-label-custom" id="createPaymentCustom" placeholder="Saisir la méthode…" maxlength="50" autocomplete="off" style="display:none">
              <small class="text-muted">Masquée pour le client.</small>
            </div>
          </div>
          
          <div id="qrPreview" class="text-center mt-3" style="display:none;">
            <h6>Aperçu du QR Code :</h6>
            <div id="qrCodeContainer"></div>
            <div class="mt-2">
              <strong>Token généré :</strong>
              <div id="tokenDisplay" class="token-display mt-1"></div>
            </div>
            <div class="mt-2">
              <strong>URL finale :</strong>
              <div id="finalUrlDisplay" class="token-display mt-1"></div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="button" class="btn btn-info" id="previewBtn">Prévisualiser</button>
          <button type="submit" class="btn btn-rose">Créer le QR Code</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Affichage QR Code -->
<div class="modal fade" id="viewQrModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">QR Code</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <div id="viewQrContainer"></div>
        <div class="mt-3">
          <button class="btn btn-primary" id="printQrBtn">
            <i class="bi bi-printer"></i> Imprimer
          </button>
          <button class="btn btn-success" id="downloadQrBtn">
            <i class="bi bi-download"></i> Télécharger PNG
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Édition QR Code -->
<div class="modal fade" id="editQrModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Modifier le QR Code</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="editQrForm">
        <input type="hidden" name="id" id="editId">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Organisation <span class="text-danger">*</span></label>
              <select name="organisation" id="editOrganisation" class="form-select" required>
                <?php foreach($organisations as $org): ?>
                  <option value="<?= htmlspecialchars($org) ?>"><?= htmlspecialchars($org) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Description</label>
              <input type="text" name="description" id="editDescription" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Date de fermeture du lien <span class="text-danger">*</span></label>
              <input type="datetime-local" name="expires_at" id="editExpiresAt" class="form-control" required>
              <small class="text-muted">Passé cette date, le lien devient inactif.</small>
            </div>
            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="editOnsiteMode">
                <label class="form-check-label fw-semibold" for="editOnsiteMode">Inscription sur place (choix de la prestation)</label>
              </div>
            </div>
            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="editSendQrcode">
                <label class="form-check-label fw-semibold" for="editSendQrcode">Envoyer le QR code dans l'e-mail</label>
              </div>
              <small class="text-muted d-block">Coché : selon la config du site. Décoché : e-mail sans QR code.</small>
            </div>
            <div class="col-md-6" id="editPaymentLabelWrap">
              <label class="form-label">Méthode de paiement enregistrée <span class="text-danger">*</span></label>
              <select class="form-select payment-label-select" id="editPaymentSelect">
                <?php foreach ($paymentMethods as $pm): ?>
                  <option value="<?= htmlspecialchars($pm) ?>"><?= htmlspecialchars($pm) ?></option>
                <?php endforeach; ?>
                <option value="__custom__">Autre (saisir manuellement)…</option>
              </select>
              <input type="text" class="form-control mt-2 payment-label-custom" id="editPaymentCustom" placeholder="Saisir la méthode…" maxlength="50" autocomplete="off" style="display:none">
              <small class="text-muted">Choix par défaut ou saisie libre via « Autre… ». Masquée pour le client.</small>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-rose">Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../src/partials/admin-footer.php'; ?>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.js" integrity="sha384-3wB6mhez87GBdPpEqKMU2wAH2Cjcvj8ynU/n7blM/JW4BLpVD0aTrx4ZE7IwFLSH" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>

<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
let qrTable;
let currentQrData = null;
const _csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const PAYMENT_METHODS = <?= json_encode($paymentMethods, JSON_UNESCAPED_UNICODE) ?>;

// Convertit un DATETIME MySQL (YYYY-MM-DD HH:MM:SS) en valeur input datetime-local.
function toLocalInput(mysqlDatetime) {
    if (!mysqlDatetime) return '';
    return String(mysqlDatetime).replace(' ', 'T').substring(0, 16);
}

// Relie un <select> de méthode de paiement à son champ « Autre… » libre.
function bindPaymentSelect(selectId, customId) {
    const sel = document.getElementById(selectId);
    const custom = document.getElementById(customId);
    if (!sel || !custom) return;
    function sync() {
        const isCustom = sel.value === '__custom__';
        custom.style.display = isCustom ? '' : 'none';
        if (!isCustom) custom.value = '';
    }
    sel.addEventListener('change', sync);
    sync();
}

// Valeur finale de la méthode de paiement (choix par défaut ou saisie libre).
function readPaymentValue(selectId, customId) {
    const sel = document.getElementById(selectId);
    const custom = document.getElementById(customId);
    if (!sel) return '';
    return sel.value === '__custom__' ? (custom ? custom.value.trim() : '') : sel.value;
}

// Pré-remplit le couple select/« Autre… » à partir d'une valeur enregistrée.
function writePaymentValue(selectId, customId, value) {
    const sel = document.getElementById(selectId);
    const custom = document.getElementById(customId);
    if (!sel) return;
    if (value && PAYMENT_METHODS.indexOf(value) === -1) {
        sel.value = '__custom__';
        if (custom) { custom.value = value; custom.style.display = ''; }
    } else {
        sel.value = value || PAYMENT_METHODS[0];
        if (custom) { custom.value = ''; custom.style.display = 'none'; }
    }
}

$(document).ready(function() {
    // Pagination compacte type "1 ... 4 5 6 ... 12"
    $.fn.dataTable.ext.pager.numbers_length = 7;

    // Initialisation du DataTable
    qrTable = $('#qrTable').DataTable({
        ajax: {
            url: '../admin-api.php?route=qrcodes',
            dataSrc: '',
            error: function(xhr, error, thrown) {
                console.error('Erreur lors du chargement des données:', error);
                console.error('Réponse serveur:', xhr.responseText);
            }
        },
        columns: [
            { data: 'id' },
            { data: 'organisation' },
            { 
                data: 'token',
                render: function(data) {
                    return '<code class="small">' + data.substring(0, 16) + '...</code>';
                }
            },
            { 
                data: 'qr_url',
                render: function(data) {
                    return '<a href="' + data + '" target="_blank" class="text-truncate d-block" style="max-width:200px">' + data + '</a>';
                }
            },
            { 
                data: 'description',
                render: function(data) {
                    return data || '-';
                }
            },
            {
                data: 'is_active',
                render: function(data, type, row) {
                    if (data != 1) {
                        return '<span class="badge bg-secondary">Inactif</span>';
                    }
                    if (row.expires_at && new Date(row.expires_at.replace(' ', 'T')) <= new Date()) {
                        return '<span class="badge bg-dark">Expiré</span>';
                    }
                    return '<span class="badge bg-success">Actif</span>';
                }
            },
            { 
                data: 'created_at',
                render: function(data) {
                    return new Date(data).toLocaleDateString('fr-FR');
                }
            },
            {
                data: null,
                orderable: false,
                render: function(data) {
                    return `
                        <div class="qr-actions">
                            <button class="btn btn-sm btn-outline-primary view-qr" title="Voir QR Code">
                                <i class="bi bi-qr-code"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-info copy-url" title="Copier URL">
                                <i class="bi bi-clipboard"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary edit-qr" title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-warning toggle-status" title="Activer/Désactiver">
                                <i class="bi bi-toggle-${data.is_active ? 'on' : 'off'}"></i>
                            </button>
                            <?php if($role === 'admin'): ?>
                            <button class="btn btn-sm btn-outline-danger delete-qr" title="Supprimer">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    `;
                }
            }
        ],
        order: [[0, 'desc']],
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.10/i18n/fr-FR.json'
        }
    });

    // Affiche le champ « méthode de paiement » seulement en mode inscription sur place
    function togglePaymentLabel(){
        $('#paymentLabelWrap').toggle($('#onsiteMode').is(':checked'));
    }
    $('#onsiteMode').on('change', togglePaymentLabel);
    togglePaymentLabel();

    // Menus déroulants « méthode de paiement » + option « Autre… » (création + édition)
    bindPaymentSelect('createPaymentSelect', 'createPaymentCustom');
    bindPaymentSelect('editPaymentSelect', 'editPaymentCustom');

    // Édition : masque le paiement hors mode « sur place » (comme à la création)
    function toggleEditPaymentLabel(){
        $('#editPaymentLabelWrap').toggle($('#editOnsiteMode').is(':checked'));
    }
    $('#editOnsiteMode').on('change', toggleEditPaymentLabel);

    // Prévisualisation du QR Code
    $('#previewBtn').click(function() {
        const organisation = $('#createQrForm [name="organisation"]').val();
        const baseUrl = $('#createQrForm [name="base_url"]').val();

        if (!organisation || !baseUrl) {
            alert('Veuillez remplir l\'organisation et l\'URL de base');
            return;
        }

        // Génération d'un token temporaire pour la prévisualisation
        const tempToken = generateToken();
        const finalUrl = baseUrl + (baseUrl.includes('?') ? '&' : '?') + 'token=' + tempToken;

        // Affichage du QR Code
        const qr = new QRious({
            element: document.createElement('canvas'),
            value: finalUrl,
            size: 200
        });

        $('#qrCodeContainer').html(qr.element);
        $('#tokenDisplay').text(tempToken);
        $('#finalUrlDisplay').text(finalUrl);
        $('#qrPreview').show();
    });

    // Création du QR Code - CORRECTION IMPORTANTE
    $('#createQrForm').submit(function(e) {
        e.preventDefault();
        
        const expiresAt = $('#createExpiresAt').val();
        if (!expiresAt) {
            alert('Veuillez renseigner la date de fermeture du lien.');
            return;
        }

        const paymentLabel = readPaymentValue('createPaymentSelect', 'createPaymentCustom');
        if (!paymentLabel) {
            alert('Veuillez renseigner la méthode de paiement enregistrée.');
            document.getElementById('createPaymentCustom').focus();
            return;
        }

        const formData = {
            organisation: $('#createQrForm [name="organisation"]').val(),
            description: $('#createQrForm [name="description"]').val(),
            base_url: $('#createQrForm [name="base_url"]').val(),
            onsite_mode: $('#onsiteMode').is(':checked') ? 1 : 0,
            send_qrcode: $('#sendQrcode').is(':checked') ? 1 : 0,
            payment_label: paymentLabel,
            expires_at: expiresAt
        };

        $.ajax({
            url: '../admin-api.php?route=qrcodes',
            method: 'POST',
            contentType: 'application/json',
            headers: { 'X-CSRF-TOKEN': _csrfToken },
            data: JSON.stringify(formData),
            success: function(response) {
                if (response.success) {
                    alert('QR Code créé avec succès !');
                    $('#createQrModal').modal('hide');
                    $('#createQrForm')[0].reset();
                    $('#qrPreview').hide();
                    location.reload();
                } else {
                    alert('Erreur : ' + (response.message || 'Erreur inconnue'));
                }
            },
            error: function(xhr, status, error) {
                console.error('Erreur AJAX:', error);
                console.error('Réponse serveur:', xhr.responseText);
                alert('Erreur de communication avec le serveur: ' + error);
            }
        });
    });

    // Actions sur les QR Codes
    $('#qrTable').on('click', '.view-qr', function() {
        const data = qrTable.row($(this).closest('tr')).data();
        currentQrData = data;
        
        const qr = new QRious({
            element: document.createElement('canvas'),
            value: data.qr_url,
            size: 300
        });

        $('#viewQrContainer').html(qr.element);
        $('#viewQrModal').modal('show');
    });

    $('#qrTable').on('click', '.copy-url', function() {
        const data = qrTable.row($(this).closest('tr')).data();
        navigator.clipboard.writeText(data.qr_url).then(function() {
            alert('URL copiée dans le presse-papier !');
        });
    });

    $('#qrTable').on('click', '.toggle-status', function() {
        const data = qrTable.row($(this).closest('tr')).data();
        const newStatus = data.is_active == 1 ? 0 : 1;
        
        $.ajax({
            url: '../admin-api.php?route=qrcodes',
            method: 'PUT',
            data: 'id=' + data.id + '&is_active=' + newStatus,
            contentType: 'application/x-www-form-urlencoded',
            headers: { 'X-CSRF-TOKEN': _csrfToken },
            success: function() {
                qrTable.ajax.reload(null, false);
            }
        });
    });

    // Ouverture du modal d'édition pré-rempli
    $('#qrTable').on('click', '.edit-qr', function() {
        const data = qrTable.row($(this).closest('tr')).data();
        $('#editId').val(data.id);
        $('#editOrganisation').val(data.organisation);
        $('#editDescription').val(data.description || '');
        $('#editExpiresAt').val(toLocalInput(data.expires_at));
        $('#editOnsiteMode').prop('checked', data.onsite_mode == 1);
        $('#editSendQrcode').prop('checked', data.send_qrcode == 1);
        writePaymentValue('editPaymentSelect', 'editPaymentCustom', data.payment_label);
        toggleEditPaymentLabel();
        $('#editQrModal').modal('show');
    });

    // Enregistrement des modifications
    $('#editQrForm').submit(function(e) {
        e.preventDefault();

        const expiresAt = $('#editExpiresAt').val();
        if (!expiresAt) {
            alert('Veuillez renseigner la date de fermeture du lien.');
            return;
        }

        const paymentLabel = readPaymentValue('editPaymentSelect', 'editPaymentCustom');
        if (!paymentLabel) {
            alert('Veuillez renseigner la méthode de paiement enregistrée.');
            document.getElementById('editPaymentCustom').focus();
            return;
        }

        const params = {
            id: $('#editId').val(),
            organisation: $('#editOrganisation').val(),
            description: $('#editDescription').val(),
            onsite_mode: $('#editOnsiteMode').is(':checked') ? 1 : 0,
            send_qrcode: $('#editSendQrcode').is(':checked') ? 1 : 0,
            payment_label: paymentLabel,
            expires_at: expiresAt
        };

        $.ajax({
            url: '../admin-api.php?route=qrcodes',
            method: 'PUT',
            data: $.param(params),
            contentType: 'application/x-www-form-urlencoded',
            headers: { 'X-CSRF-TOKEN': _csrfToken },
            success: function(response) {
                if (response && response.success === false) {
                    alert('Erreur : ' + (response.message || 'Erreur inconnue'));
                    return;
                }
                $('#editQrModal').modal('hide');
                qrTable.ajax.reload(null, false);
            },
            error: function(xhr, status, error) {
                alert('Erreur de communication avec le serveur: ' + error);
            }
        });
    });

    <?php if($role === 'admin'): ?>
    $('#qrTable').on('click', '.delete-qr', function() {
        const data = qrTable.row($(this).closest('tr')).data();
        if (confirm('Êtes-vous sûr de vouloir supprimer ce QR Code ?')) {
            $.ajax({
                url: '../admin-api.php?route=qrcodes',
                method: 'DELETE',
                data: 'id=' + data.id,
                contentType: 'application/x-www-form-urlencoded',
                headers: { 'X-CSRF-TOKEN': _csrfToken },
                success: function() {
                    qrTable.ajax.reload();
                    alert('QR Code supprimé avec succès');
                }
            });
        }
    });
    <?php endif; ?>

    // Impression du QR Code
    $('#printQrBtn').click(function() {
        const canvas = $('#viewQrContainer canvas')[0];
        const win = window.open('', '_blank');
        win.document.write(`
            <html>
                <head><title>QR Code - ${currentQrData.organisation}</title></head>
                <body style="text-align:center; padding:20px;">
                    <h2>${currentQrData.organisation}</h2>
                    <p>${currentQrData.description || ''}</p>
                    <img src="${canvas.toDataURL()}" style="max-width:400px;">
                    <p style="font-size:12px; margin-top:20px;">Token: ${currentQrData.token}</p>
                    <script nonce="<?= $GLOBALS['csp_nonce'] ?>">window.onload = function(){ window.print(); }<\/script>
                </body>
            </html>
        `);
    });

    // Téléchargement du QR Code
    $('#downloadQrBtn').click(function() {
        const canvas = $('#viewQrContainer canvas')[0];
        const link = document.createElement('a');
        link.download = `qrcode_${currentQrData.organisation.replace(/[^a-zA-Z0-9]/g, '_')}.png`;
        link.href = canvas.toDataURL();
        link.click();
    });
});

function generateToken() {
    return Array.from(crypto.getRandomValues(new Uint8Array(32)), b => b.toString(16).padStart(2, '0')).join('');
}
</script>

</body>
</html>
