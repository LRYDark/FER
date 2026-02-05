<?php
require '../config/config.php';
requireRole(['admin']);
$role = currentRole();

// Récupération des organisations existantes
$stmt = $pdo->prepare('SELECT DISTINCT organisation FROM users WHERE organisation IS NOT NULL AND organisation != ""');
$stmt->execute();
$organisations = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Récupération des données pour l'affichage
$stmt = $pdo->prepare(
    'SELECT *
       FROM setting
      WHERE id = :id
      LIMIT 1');
$stmt->execute(['id' => 1]);
$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$title  = $data['title']   ?? '';
$picture= $data['picture'] ?? '';  
$footer = $data['footer']  ?? '';
$titleColor = $data['title_color'] ?? '#ffffff';

// Correction : Construction de l'URL de base correcte
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gestion des QR Codes – Forbach en Rose</title>

<!-- CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="../css/forbach-style.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.css" rel="stylesheet">

<style>
.card-dashboard{margin-top:1rem;border-radius:1.25rem;box-shadow:0 0 25px rgba(0,0,0,.1)}
.qr-preview{max-width:200px;max-height:200px;border:2px solid #dee2e6;border-radius:6px;padding:10px;margin:10px 0}
.qr-actions .btn{margin:2px}
.token-display{font-family:monospace;font-size:0.85rem;background:#f8f9fa;padding:5px 10px;border-radius:4px;word-break:break-all}
  .first-750 td{background:#ffe5ff!important;font-weight:600}
  .hero{display:flex;align-items:center;justify-content:center;padding:2rem 1rem;background:var(--rose-500);color:#fff;position:relative}
  .hero h1{margin:0;font-size:2.2rem}
  .top-actions{position:absolute;top:1rem;right:1rem;display:flex;gap:.5rem}
  @media (max-width:991.98px){.top-actions{display:none}}
  .card-dashboard{margin-top:1rem;border-radius:1.25rem;box-shadow:0 0 25px rgba(0,0,0,.1)}
  .quick-search{max-width:450px;width:50%;margin:0 auto .75rem;position:sticky;top:0;z-index:1030}
  tr.filters th[class*="sorting"]::before,
  tr.filters th[class*="sorting"]::after{display:none!important}
  .statCard{min-width:180px}
  .hide-stats #stats {display: none !important;}
</style>
</head>

<body class="d-flex flex-column">

<?php include '../inc/nav-settings.php'; ?>

<main class="container-fluid flex-grow-1">
  <div class="bg-white p-4 card-dashboard">
    
    <div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-3 gap-3">
      <h2 class="mb-0">Gestion des QR Codes</h2>
      <div class="d-flex gap-2">
        <button class="btn btn-rose" data-bs-toggle="modal" data-bs-target="#createQrModal">
          <i class="bi bi-qr-code"></i> Nouveau QR Code
        </button>
      </div>
    </div>

    <div class="table-responsive">
      <table id="qrTable" class="table table-striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Organisation</th>
            <th>Token</th>
            <th>URL</th>
            <th>Description</th>
            <th>Statut</th>
            <th>Créé le</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</main>

<footer class="text-center py-3 small text-muted"><?= htmlspecialchars($footer) ?></footer>

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
              <label class="form-label">Organisation *</label>
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
              <label class="form-label">URL de base du formulaire *</label>
              <input type="url" name="base_url" class="form-control" value="<?= htmlspecialchars($baseUrl) ?>" required>
              <small class="text-muted">Le token sera automatiquement ajouté en paramètre</small>
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

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>

<script>
let qrTable;
let currentQrData = null;

$(document).ready(function() {
    // Initialisation du DataTable
    qrTable = $('#qrTable').DataTable({
        ajax: {
            url: '../config/api.php?route=qrcodes',
            dataSrc: '',
            error: function(xhr, error, thrown) {
                console.error('Erreur lors du chargement des données:', error);
                console.error('Réponse serveur:', xhr.responseText);
            }
        },
        columns: [
            { data: 'id', width: '60px' },
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
                render: function(data) {
                    return data == 1 ? '<span class="badge bg-success">Actif</span>' : '<span class="badge bg-secondary">Inactif</span>';
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

    // Prévisualisation du QR Code
    $('#previewBtn').click(function() {
        const organisation = $('[name="organisation"]').val();
        const baseUrl = $('[name="base_url"]').val();
        
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
        
        const formData = {
            organisation: $('[name="organisation"]').val(),
            description: $('[name="description"]').val(),
            base_url: $('[name="base_url"]').val()
        };

        console.log('Données envoyées:', formData);

        $.ajax({
            url: '../config/api.php?route=qrcodes',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(formData),
            success: function(response) {
                console.log('Réponse serveur:', response);
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
            url: '../config/api.php?route=qrcodes',
            method: 'PUT',
            data: 'id=' + data.id + '&is_active=' + newStatus,
            contentType: 'application/x-www-form-urlencoded',
            success: function() {
                qrTable.ajax.reload(null, false);
            }
        });
    });

    <?php if($role === 'admin'): ?>
    $('#qrTable').on('click', '.delete-qr', function() {
        const data = qrTable.row($(this).closest('tr')).data();
        if (confirm('Êtes-vous sûr de vouloir supprimer ce QR Code ?')) {
            $.ajax({
                url: '../config/api.php?route=qrcodes',
                method: 'DELETE',
                data: 'id=' + data.id,
                contentType: 'application/x-www-form-urlencoded',
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
                    <script>window.onload = function(){ window.print(); }<\/script>
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
