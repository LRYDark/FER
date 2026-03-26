<?php
require '../config/config.php';
require_once __DIR__ . '/../config/csrf.php';
requireRole(['admin']);
$role = currentRole();
require 'navbar-data.php';

// ─── Ensure _TimeLine directory exists ───
$timelineDir = '../files/_TimeLine/';
if (file_exists($timelineDir) && !is_dir($timelineDir)) {
    @unlink($timelineDir);
}
if (!is_dir($timelineDir)) {
    @mkdir($timelineDir, 0755, true);
}

$hasStatusCol = false;
try {
    $pdo->query("SELECT status FROM timeline_items LIMIT 0");
    $hasStatusCol = true;
} catch (PDOException $e) {}

// ─── CSRF check for all POST actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(403);
    die('Invalid CSRF token');
}

// ─── Reorder via AJAX ───
if (isset($_POST['reorder_items'])) {
    $ids = json_decode($_POST['ids'], true);
    if (is_array($ids)) {
        $stmt = $pdo->prepare("UPDATE timeline_items SET sort_order = ? WHERE id = ?");
        foreach ($ids as $i => $id) {
            $stmt->execute([$i + 1, (int)$id]);
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ─── CRUD Handlers ───

// Add item
if (isset($_POST['add_item'])) {
    $title   = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $imgName = null;

    if (!empty($_FILES['image']['name'])) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($ext, $allowed)) {
            $imgName = uniqid('img_', true) . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], $timelineDir . $imgName);
        }
    }

    $imgPos = trim($_POST['image_position'] ?? '50% 50% 1');

    $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM timeline_items")->fetchColumn();
    if ($hasStatusCol) {
        $status = isset($_POST['status']) && in_array($_POST['status'], ['published', 'draft']) ? $_POST['status'] : 'draft';
        $stmt = $pdo->prepare("INSERT INTO timeline_items (title, content, image, image_position, sort_order, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $content, $imgName, $imgPos, $maxOrder + 1, $status]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO timeline_items (title, content, image, image_position, sort_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$title, $content, $imgName, $imgPos, $maxOrder + 1]);
    }
    $lastId = $pdo->lastInsertId();

    $elements = trim($_POST['elements'] ?? '');
    if ($elements !== '') {
        $tags = array_filter(array_map('trim', explode(',', $elements)));
        $stmtEl = $pdo->prepare("INSERT INTO timeline_elements (item_id, label, sort_order) VALUES (?, ?, ?)");
        foreach ($tags as $i => $tag) {
            $stmtEl->execute([$lastId, $tag, $i + 1]);
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Update item
if (isset($_POST['update_item'])) {
    $itemId  = (int)$_POST['item_id'];
    $title   = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $imgPos  = trim($_POST['image_position'] ?? '50% 50% 1');

    $status = $hasStatusCol ? (isset($_POST['status']) && in_array($_POST['status'], ['published', 'draft']) ? $_POST['status'] : 'draft') : null;

    if (!empty($_FILES['image']['name'])) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($ext, $allowed)) {
            // Delete old image
            $oldImg = $pdo->prepare("SELECT image FROM timeline_items WHERE id = ?");
            $oldImg->execute([$itemId]);
            $old = $oldImg->fetchColumn();
            if ($old && file_exists($timelineDir . $old)) {
                unlink($timelineDir . $old);
            }

            $imgName = uniqid('img_', true) . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], $timelineDir . $imgName);
            if ($hasStatusCol) {
                $stmt = $pdo->prepare("UPDATE timeline_items SET title = ?, content = ?, image = ?, image_position = ?, status = ? WHERE id = ?");
                $stmt->execute([$title, $content, $imgName, $imgPos, $status, $itemId]);
            } else {
                $stmt = $pdo->prepare("UPDATE timeline_items SET title = ?, content = ?, image = ?, image_position = ? WHERE id = ?");
                $stmt->execute([$title, $content, $imgName, $imgPos, $itemId]);
            }
        }
    } else {
        if ($hasStatusCol) {
            $stmt = $pdo->prepare("UPDATE timeline_items SET title = ?, content = ?, image_position = ?, status = ? WHERE id = ?");
            $stmt->execute([$title, $content, $imgPos, $status, $itemId]);
        } else {
            $stmt = $pdo->prepare("UPDATE timeline_items SET title = ?, content = ?, image_position = ? WHERE id = ?");
            $stmt->execute([$title, $content, $imgPos, $itemId]);
        }
    }

    // Re-insert elements
    $pdo->prepare("DELETE FROM timeline_elements WHERE item_id = ?")->execute([$itemId]);
    $elements = trim($_POST['elements'] ?? '');
    if ($elements !== '') {
        $tags = array_filter(array_map('trim', explode(',', $elements)));
        $stmtEl = $pdo->prepare("INSERT INTO timeline_elements (item_id, label, sort_order) VALUES (?, ?, ?)");
        foreach ($tags as $i => $tag) {
            $stmtEl->execute([$itemId, $tag, $i + 1]);
        }
    }

    $_SESSION['reopen_modal'] = $itemId;
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Delete item
if (isset($_POST['delete_item'])) {
    $itemId = (int)$_POST['item_id'];

    $stmt = $pdo->prepare("SELECT image FROM timeline_items WHERE id = ?");
    $stmt->execute([$itemId]);
    $img = $stmt->fetchColumn();
    if ($img && file_exists($timelineDir . $img)) {
        unlink($timelineDir . $img);
    }

    $pdo->prepare("DELETE FROM timeline_items WHERE id = ?")->execute([$itemId]);

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Move item up/down
if (isset($_POST['move_item'])) {
    $itemId    = (int)$_POST['item_id'];
    $direction = $_POST['direction']; // 'up' or 'down'

    $items = $pdo->query("SELECT id, sort_order FROM timeline_items ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_column($items, 'id');
    $pos = array_search($itemId, $ids);

    if ($direction === 'up' && $pos > 0) {
        $swapId = $ids[$pos - 1];
        $pdo->prepare("UPDATE timeline_items SET sort_order = ? WHERE id = ?")->execute([$items[$pos - 1]['sort_order'], $itemId]);
        $pdo->prepare("UPDATE timeline_items SET sort_order = ? WHERE id = ?")->execute([$items[$pos]['sort_order'], $swapId]);
    } elseif ($direction === 'down' && $pos < count($ids) - 1) {
        $swapId = $ids[$pos + 1];
        $pdo->prepare("UPDATE timeline_items SET sort_order = ? WHERE id = ?")->execute([$items[$pos + 1]['sort_order'], $itemId]);
        $pdo->prepare("UPDATE timeline_items SET sort_order = ? WHERE id = ?")->execute([$items[$pos]['sort_order'], $swapId]);
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ─── Fetch data ───
$items = $pdo->query("SELECT * FROM timeline_items ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
$elementsByItem = [];
foreach ($items as $item) {
    $stmt = $pdo->prepare("SELECT * FROM timeline_elements WHERE item_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$item['id']]);
    $elementsByItem[$item['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Timeline</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<style>
  .card-dashboard{margin-top:1rem;border-radius:1.25rem;box-shadow:0 0 25px rgba(0,0,0,.1)}
  .tl-thumb{width:100%;height:160px;object-fit:cover;border-radius:.75rem .75rem 0 0}
  .tl-kicker{display:inline-block;font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#ec4899;font-weight:800;padding:4px 10px;background:linear-gradient(135deg,#fdf2f8,#fce7f3);border-radius:100px}
  .tl-amount{font-size:1.15rem;font-weight:800;letter-spacing:-.02em;color:#0f172a;margin:4px 0 8px}
  .tl-pill{display:inline-flex;align-items:center;padding:4px 10px;border-radius:8px;background:#f8fafc;border:1px solid #f1f5f9;color:#64748b;font-weight:600;font-size:11px}
  .tl-order{font-size:12px;color:#94a3b8;font-weight:600}
  .tl-card{border:1px solid rgba(0,0,0,.06);border-radius:.75rem;overflow:hidden;transition:box-shadow .2s}
  .tl-card:hover{box-shadow:0 8px 24px rgba(0,0,0,.08)}
  .sortable-ghost { opacity: 0.4; }
  .drag-handle:hover { color: #ec4899; }

  /* Image position dragger — same ratio as card (480×180) */
  .img-positioner{position:relative;width:100%;max-width:480px;aspect-ratio:480/180;overflow:hidden;border-radius:.75rem;border:2px dashed #e2e8f0;cursor:grab;background:#f1f5f9;user-select:none}
  .img-positioner:active{cursor:grabbing}
  .img-positioner img{position:absolute;top:0;left:0;pointer-events:none;user-select:none;-webkit-user-drag:none;transform-origin:0 0}
  .img-positioner .pos-hint{position:absolute;bottom:8px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.55);color:#fff;font-size:11px;padding:3px 12px;border-radius:20px;pointer-events:none;white-space:nowrap;z-index:2}
  .img-pos-controls{display:flex;align-items:center;gap:10px;margin-top:8px;max-width:480px}
  .img-pos-controls label{font-size:12px;color:#64748b;font-weight:600;white-space:nowrap}
  .img-pos-controls input[type=range]{flex:1;accent-color:#ec4899}
</style>
</head>

<body>

<?php include '../inc/navbar-admin.php'; ?>
  <div class="row g-4">
    <div class="col-12">
      <div class="card-dashboard p-4 shadow-sm rounded-4 bg-white">

        <?php if (isset($_SESSION['reopen_modal'])):
            $reopenId = $_SESSION['reopen_modal'];
            unset($_SESSION['reopen_modal']);
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            var el = document.getElementById('modalEditItem<?= (int)$reopenId ?>');
            if(el){ var m = new bootstrap.Modal(el); m.show(); }
        });
        </script>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
          <h1 class="h3 mb-0">Gestion de la Timeline</h1>
          <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddItem">
            <i class="bi bi-plus-lg me-1"></i>Ajouter un item
          </button>
        </div>

        <?php if (empty($items)): ?>
          <p class="text-muted">Aucun item dans la timeline.</p>
        <?php else: ?>
          <div class="row g-3" id="sortableTimeline">
            <?php foreach ($items as $idx => $item):
                $elLabels = array_map(function($e){ return $e['label']; }, $elementsByItem[$item['id']] ?? []);
                $elString = implode(', ', $elLabels);
                $side = ($idx % 2 === 0) ? 'Gauche' : 'Droite';
            ?>
            <div class="col-md-6 col-lg-4 col-xl-3 sortable-item" data-id="<?= $item['id'] ?>">
              <div class="tl-card bg-white" style="position:relative">
                <?php if ($hasStatusCol): ?>
                  <span class="badge <?= ($item['status'] ?? 'draft') === 'published' ? 'bg-success' : 'bg-warning text-dark' ?>" style="position:absolute;top:10px;right:10px;z-index:2;font-size:0.7rem;padding:4px 10px;border-radius:20px;box-shadow:0 1px 4px rgba(0,0,0,.15);">
                    <?= ($item['status'] ?? 'draft') === 'published' ? 'Publié' : 'Brouillon' ?>
                  </span>
                <?php endif; ?>
                <?php if (!empty($item['image'])):
                    $pr = preg_split('/\s+/', trim($item['image_position'] ?? '50% 50% 1'));
                    $tx = $pr[0] ?? '50%'; $ty = $pr[1] ?? '50%';
                ?>
                  <img src="../files/_TimeLine/<?= htmlspecialchars($item['image']) ?>"
                       class="tl-thumb" alt="<?= htmlspecialchars($item['title']) ?>"
                       style="object-position:<?= $tx ?> <?= $ty ?>">
                <?php else: ?>
                  <div class="tl-thumb d-flex align-items-center justify-content-center" style="background:#fdf2f8">
                    <i class="bi bi-image text-muted" style="font-size:2rem"></i>
                  </div>
                <?php endif; ?>

                <div class="p-3">
                  <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="tl-kicker"><?= htmlspecialchars($item['title']) ?></span>
                    <div class="drag-handle" style="cursor:grab;color:#94a3b8;padding:4px 8px">
                      <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                        <circle cx="5" cy="3" r="1.5"/><circle cx="11" cy="3" r="1.5"/>
                        <circle cx="5" cy="8" r="1.5"/><circle cx="11" cy="8" r="1.5"/>
                        <circle cx="5" cy="13" r="1.5"/><circle cx="11" cy="13" r="1.5"/>
                      </svg>
                    </div>
                  </div>

                  <div class="tl-amount"><?= htmlspecialchars($item['content']) ?></div>

                  <div class="d-flex flex-wrap gap-1 mb-2">
                    <?php foreach ($elLabels as $label): ?>
                      <span class="tl-pill"><?= htmlspecialchars($label) ?></span>
                    <?php endforeach; ?>
                  </div>

                  <div class="d-flex justify-content-between align-items-center">
                    <span class="tl-order">#<?= $item['sort_order'] ?> · <?= $side ?></span>
                    <div class="d-flex gap-1">
                      <a href="../public/accueil.php?preview_timeline=1" target="_blank" class="btn btn-sm btn-outline-secondary" title="Aperçu timeline">
                        <i class="bi bi-eye"></i>
                      </a>
                      <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalEditItem<?= $item['id'] ?>">
                        <i class="bi bi-pencil"></i>
                      </button>
                      <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cet item et ses tags ?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                        <button type="submit" name="delete_item" class="btn btn-sm btn-outline-danger">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Edit Modal for item <?= $item['id'] ?> -->
            <div class="modal fade" id="modalEditItem<?= $item['id'] ?>" tabindex="-1">
              <div class="modal-dialog modal-lg">
                <div class="modal-content">
                  <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                    <div class="modal-header">
                      <h5 class="modal-title">Modifier : <?= htmlspecialchars($item['title']) ?></h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body row g-3">
                      <div class="col-md-6">
                        <label class="form-label">Titre (kicker rose)</label>
                        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($item['title']) ?>" required>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Contenu (montant / texte principal)</label>
                        <input type="text" name="content" class="form-control" value="<?= htmlspecialchars($item['content']) ?>" required>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Image</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Tags / Éléments (séparés par virgule)</label>
                        <input type="text" name="elements" class="form-control" value="<?= htmlspecialchars($elString) ?>">
                      </div>
                      <?php if ($hasStatusCol): ?>
                      <div class="col-md-6">
                        <label class="form-label">Statut</label>
                        <select name="status" class="form-select">
                          <option value="draft" <?= ($item['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Brouillon</option>
                          <option value="published" <?= ($item['status'] ?? 'draft') === 'published' ? 'selected' : '' ?>>Publié</option>
                        </select>
                      </div>
                      <?php endif; ?>
                      <?php if (!empty($item['image'])): ?>
                      <div class="col-12">
                        <label class="form-label">Position de l'image <small class="text-muted">(glissez + zoom)</small></label>
                        <div class="img-positioner" data-field="imgpos_<?= $item['id'] ?>">
                          <img src="../files/_TimeLine/<?= htmlspecialchars($item['image']) ?>" alt="">
                          <span class="pos-hint"><i class="bi bi-arrows-move me-1"></i>Glissez l'image</span>
                        </div>
                        <div class="img-pos-controls">
                          <label><i class="bi bi-zoom-in me-1"></i>Zoom</label>
                          <input type="range" class="zoom-slider" data-field="imgpos_<?= $item['id'] ?>" min="100" max="300" value="100" step="5">
                          <span class="zoom-val" style="font-size:12px;color:#64748b;min-width:36px">100%</span>
                        </div>
                        <input type="hidden" name="image_position" id="imgpos_<?= $item['id'] ?>" value="<?= htmlspecialchars($item['image_position'] ?? '50% 50% 1') ?>">
                      </div>
                      <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                      <button type="submit" name="update_item" class="btn btn-primary">Enregistrer</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </div>

<!-- Add Modal -->
<div class="modal fade" id="modalAddItem" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="modal-header">
          <h5 class="modal-title">Ajouter un item Timeline</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-3">
          <div class="col-md-6">
            <label class="form-label">Titre (kicker rose)</label>
            <input type="text" name="title" class="form-control" placeholder="Ex: Edition 2025" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Contenu (montant / texte principal)</label>
            <input type="text" name="content" class="form-control" placeholder="Ex: 16 000 €" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Image</label>
            <input type="file" name="image" class="form-control" accept="image/*" onchange="previewNewImage(this, 'addItemPositioner', 'imgpos_new')">
          </div>
          <div class="col-md-6">
            <label class="form-label">Tags / Éléments (séparés par virgule)</label>
            <input type="text" name="elements" class="form-control" placeholder="05-08 sept., Les Bureaux du Cœur">
          </div>
          <?php if ($hasStatusCol): ?>
          <div class="col-md-6">
            <label class="form-label">Statut</label>
            <select name="status" class="form-select">
              <option value="draft" selected>Brouillon</option>
              <option value="published">Publié</option>
            </select>
          </div>
          <?php endif; ?>
          <div class="col-12" id="addItemPositioner" style="display:none">
            <label class="form-label">Position de l'image <small class="text-muted">(glissez + zoom)</small></label>
            <div class="img-positioner" data-field="imgpos_new">
              <img src="" alt="">
              <span class="pos-hint"><i class="bi bi-arrows-move me-1"></i>Glissez l'image</span>
            </div>
            <div class="img-pos-controls">
              <label><i class="bi bi-zoom-in me-1"></i>Zoom</label>
              <input type="range" class="zoom-slider" data-field="imgpos_new" min="100" max="300" value="100" step="5">
              <span class="zoom-val" style="font-size:12px;color:#64748b;min-width:36px">100%</span>
            </div>
            <input type="hidden" name="image_position" id="imgpos_new" value="50% 50% 1">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" name="add_item" class="btn btn-success">Ajouter</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include '../inc/admin-footer.php'; ?>
<script>
// ===== Image Position Dragger (X+Y + Zoom) =====
(function(){
  // Store references to avoid duplicate bindings
  var initialized = new WeakSet();

  function initPositioner(container){
    if(initialized.has(container)) return;
    initialized.add(container);

    var img = container.querySelector('img');
    var fieldId = container.dataset.field;
    var input = document.getElementById(fieldId);
    if(!img || !input) return;

    // Find zoom slider for this positioner
    var slider = document.querySelector('.zoom-slider[data-field="'+fieldId+'"]');
    var zoomVal = slider ? slider.closest('.img-pos-controls').querySelector('.zoom-val') : null;

    var dragging = false;
    var startX = 0, startY = 0;
    var startLeft = 0, startTop = 0;
    var scale = 1;

    // Parse stored value: "xPercent% yPercent% scale"
    function parseValue(){
      var parts = (input.value || '50% 50% 1').replace(/%/g,'').split(/\s+/);
      return {
        xPct: parseFloat(parts[0]) || 50,
        yPct: parseFloat(parts[1]) || 50,
        scale: parseFloat(parts[2]) || 1
      };
    }

    function applyPosition(){
      if(!img.naturalWidth || !img.naturalHeight) return;
      var cW = container.offsetWidth;
      var cH = container.offsetHeight;
      if(cW === 0 || cH === 0) return;

      var vals = parseValue();
      scale = vals.scale;

      // Image scaled dimensions
      var imgW = img.naturalWidth * (cW / img.naturalWidth) * scale;
      var imgH = img.naturalHeight * (cW / img.naturalWidth) * scale;

      img.style.width = imgW + 'px';
      img.style.height = imgH + 'px';
      img.style.transform = 'none'; // we use width/height directly

      // Calculate offsets from percentages
      var maxOffX = Math.max(0, imgW - cW);
      var maxOffY = Math.max(0, imgH - cH);
      img.style.left = -(maxOffX * vals.xPct / 100) + 'px';
      img.style.top  = -(maxOffY * vals.yPct / 100) + 'px';

      // Sync slider
      if(slider){
        slider.value = Math.round(scale * 100);
        if(zoomVal) zoomVal.textContent = Math.round(scale * 100) + '%';
      }
    }

    img.addEventListener('load', applyPosition);
    if(img.complete && img.naturalWidth) applyPosition();

    // --- Drag: mouse ---
    container.addEventListener('mousedown', function(e){
      e.preventDefault();
      dragging = true;
      startX = e.clientX; startY = e.clientY;
      startLeft = parseFloat(img.style.left) || 0;
      startTop  = parseFloat(img.style.top)  || 0;
    });
    document.addEventListener('mousemove', function(e){
      if(!dragging) return;
      moveImg(startLeft + e.clientX - startX, startTop + e.clientY - startY);
    });
    document.addEventListener('mouseup', function(){
      if(dragging){ dragging = false; save(); }
    });

    // --- Drag: touch ---
    container.addEventListener('touchstart', function(e){
      if(e.touches.length !== 1) return;
      dragging = true;
      startX = e.touches[0].clientX; startY = e.touches[0].clientY;
      startLeft = parseFloat(img.style.left) || 0;
      startTop  = parseFloat(img.style.top)  || 0;
    }, {passive: true});
    container.addEventListener('touchmove', function(e){
      if(!dragging || e.touches.length !== 1) return;
      e.preventDefault();
      moveImg(startLeft + e.touches[0].clientX - startX, startTop + e.touches[0].clientY - startY);
    }, {passive: false});
    container.addEventListener('touchend', function(){
      if(dragging){ dragging = false; save(); }
    });

    function moveImg(newLeft, newTop){
      var cW = container.offsetWidth;
      var cH = container.offsetHeight;
      var imgW = parseFloat(img.style.width)  || img.offsetWidth;
      var imgH = parseFloat(img.style.height) || img.offsetHeight;

      var maxOffX = Math.max(0, imgW - cW);
      var maxOffY = Math.max(0, imgH - cH);
      newLeft = Math.min(0, Math.max(-maxOffX, newLeft));
      newTop  = Math.min(0, Math.max(-maxOffY, newTop));
      img.style.left = newLeft + 'px';
      img.style.top  = newTop  + 'px';
    }

    function save(){
      var cW = container.offsetWidth;
      var cH = container.offsetHeight;
      var imgW = parseFloat(img.style.width)  || img.offsetWidth;
      var imgH = parseFloat(img.style.height) || img.offsetHeight;

      var maxOffX = Math.max(1, imgW - cW);
      var maxOffY = Math.max(1, imgH - cH);
      var curLeft = parseFloat(img.style.left) || 0;
      var curTop  = parseFloat(img.style.top)  || 0;

      var xPct = Math.round((-curLeft / maxOffX) * 100);
      var yPct = Math.round((-curTop  / maxOffY) * 100);
      xPct = Math.min(100, Math.max(0, xPct));
      yPct = Math.min(100, Math.max(0, yPct));

      input.value = xPct + '% ' + yPct + '% ' + scale;
    }

    // --- Zoom slider ---
    if(slider){
      slider.addEventListener('input', function(){
        scale = parseInt(this.value, 10) / 100;
        if(zoomVal) zoomVal.textContent = this.value + '%';

        // Recalculate image size with new scale
        var cW = container.offsetWidth;
        if(!img.naturalWidth || cW === 0) return;
        var imgW = img.naturalWidth * (cW / img.naturalWidth) * scale;
        var imgH = img.naturalHeight * (cW / img.naturalWidth) * scale;
        img.style.width  = imgW + 'px';
        img.style.height = imgH + 'px';

        // Re-clamp position
        moveImg(parseFloat(img.style.left) || 0, parseFloat(img.style.top) || 0);
        save();
      });
    }
  }

  // Init positioners inside modals on shown event (so dimensions are available)
  document.querySelectorAll('.modal').forEach(function(modal){
    modal.addEventListener('shown.bs.modal', function(){
      modal.querySelectorAll('.img-positioner').forEach(initPositioner);
    });
  });

  // Preview new image in Add modal
  window.previewNewImage = function(fileInput, wrapperId, fieldId){
    var wrapper = document.getElementById(wrapperId);
    if(!wrapper) return;
    var file = fileInput.files[0];
    if(!file) { wrapper.style.display = 'none'; return; }
    var reader = new FileReader();
    reader.onload = function(e){
      var container = wrapper.querySelector('.img-positioner');
      var img = container.querySelector('img');
      img.src = e.target.result;
      wrapper.style.display = '';
      document.getElementById(fieldId).value = '50% 50% 1';
      // Reset zoom slider
      var sl = document.querySelector('.zoom-slider[data-field="'+fieldId+'"]');
      if(sl){ sl.value = 100; var v = sl.closest('.img-pos-controls').querySelector('.zoom-val'); if(v) v.textContent='100%'; }
      // Allow re-init
      initialized.delete(container);
      initPositioner(container);
    };
    reader.readAsDataURL(file);
  };

  // Handle file change in edit modals (replace image)
  document.querySelectorAll('.modal form input[type="file"][name="image"]').forEach(function(fileInput){
    fileInput.addEventListener('change', function(){
      var modal = this.closest('.modal');
      if(!modal) return;
      var container = modal.querySelector('.img-positioner');
      if(!container){
        // No positioner yet (new upload on item without image) — create one dynamically
        return;
      }
      var file = this.files[0];
      if(!file) return;
      var reader = new FileReader();
      reader.onload = function(e){
        var img = container.querySelector('img');
        img.src = e.target.result;
        var input = modal.querySelector('input[name="image_position"]');
        if(input) input.value = '50% 50% 1';
        // Reset zoom slider
        var sl = modal.querySelector('.zoom-slider');
        if(sl){ sl.value = 100; var v = sl.closest('.img-pos-controls').querySelector('.zoom-val'); if(v) v.textContent='100%'; }
        initialized.delete(container);
        initPositioner(container);
      };
      reader.readAsDataURL(file);
    });
  });

})();
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var el = document.getElementById('sortableTimeline');
  if (el) {
    Sortable.create(el, {
      handle: '.drag-handle',
      animation: 150,
      ghostClass: 'sortable-ghost',
      onEnd: function() {
        var ids = [];
        el.querySelectorAll('.sortable-item').forEach(function(item) {
          ids.push(parseInt(item.dataset.id));
        });
        fetch(window.location.pathname, {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: 'reorder_items=1&ids=' + JSON.stringify(ids) + '&csrf_token=' + encodeURIComponent(document.querySelector('input[name="csrf_token"]').value)
        });
      }
    });
  }
});
</script>
</body>
</html>
