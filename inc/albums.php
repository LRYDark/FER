<?php
require '../config/config.php';
require_once __DIR__ . '/../config/csrf.php';
requireRole(['admin']);
$role = currentRole();
require 'navbar-data.php';

$stmt = $pdo->prepare(
    'SELECT *
       FROM setting
      WHERE id = :id
      LIMIT 1');
$stmt->execute(['id' => 1]);

$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$footer= $data['footer'] ?? '';

// Check if migration has been applied (deleted_at column on photo_years)
$migrationDone = false;
$hasStatusCol = false;
try {
    $pdo->query("SELECT deleted_at FROM photo_years LIMIT 0");
    $migrationDone = true;
} catch (PDOException $e) {}
try {
    $pdo->query("SELECT status FROM photo_years LIMIT 0");
    $hasStatusCol = true;
} catch (PDOException $e) {}

// Helper: recursively delete a directory and its contents
function deleteDirectory(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

// ─── CSRF check for all POST actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(403);
    die('Invalid CSRF token');
}

if (isset($_POST['update_year'])) {
  $yearId = $_POST['year_id'];
  $year = $_POST['year'];
  $title = $_POST['title'];

  if ($hasStatusCol) {
    $status = isset($_POST['status']) && in_array($_POST['status'], ['published', 'draft']) ? $_POST['status'] : 'draft';
    $stmt = $pdo->prepare("UPDATE photo_years SET year = ?, title = ?, status = ? WHERE id = ?");
    $stmt->execute([$year, $title, $status, $yearId]);
  } else {
    $stmt = $pdo->prepare("UPDATE photo_years SET year = ?, title = ? WHERE id = ?");
    $stmt->execute([$year, $title, $yearId]);
  }

  $_SESSION['reopen_modal'] = $yearId;
  $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Année mise à jour.'];
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

if (isset($_POST['update_album'])) {
  $albumId = $_POST['album_id'];
  $album_title = $_POST['album_title'];
  $album_desc = $_POST['album_desc'];
  $yearId = $_POST['year_id'];
  $deleteImage = !empty($_POST['delete_image']);

  // Get current album to check type
  $stmtCur = $pdo->prepare("SELECT album_type, album_link FROM photo_albums WHERE id = ?");
  $stmtCur->execute([$albumId]);
  $curAlbum = $stmtCur->fetch(PDO::FETCH_ASSOC);
  $isLocal = (($curAlbum['album_type'] ?? 'link') === 'local');

  // For link type, update the link; for local, keep the folder path
  $album_link = $isLocal ? $curAlbum['album_link'] : ($_POST['album_link'] ?? '');

  if (!empty($_FILES['album_img']['name'])) {
    $allowedExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $ext  = strtolower(pathinfo($_FILES['album_img']['name'], PATHINFO_EXTENSION));
    $mime = mime_content_type($_FILES['album_img']['tmp_name']);
    if (in_array($ext, $allowedExts) && in_array($mime, $allowedMimes)) {
      $safeName = uniqid('album_', true) . '.' . $ext;
      move_uploaded_file($_FILES['album_img']['tmp_name'], "../files/_albums/" . $safeName);
      $stmt = $pdo->prepare("UPDATE photo_albums SET album_title = ?, album_link = ?, album_img = ?, album_desc = ? WHERE id = ?");
      $stmt->execute([$album_title, $album_link, $safeName, $album_desc, $albumId]);
    } else {
      $stmt = $pdo->prepare("UPDATE photo_albums SET album_title = ?, album_link = ?, album_desc = ? WHERE id = ?");
      $stmt->execute([$album_title, $album_link, $album_desc, $albumId]);
    }
  } elseif ($deleteImage) {
    // Supprimer l'image de couverture existante
    $stmtOld = $pdo->prepare("SELECT album_img FROM photo_albums WHERE id = ?");
    $stmtOld->execute([$albumId]);
    $oldImg = $stmtOld->fetchColumn();
    if ($oldImg && file_exists("../files/_albums/" . $oldImg)) {
      unlink("../files/_albums/" . $oldImg);
    }
    $stmt = $pdo->prepare("UPDATE photo_albums SET album_title = ?, album_link = ?, album_img = '', album_desc = ? WHERE id = ?");
    $stmt->execute([$album_title, $album_link, $album_desc, $albumId]);
  } else {
    $stmt = $pdo->prepare("UPDATE photo_albums SET album_title = ?, album_link = ?, album_desc = ? WHERE id = ?");
    $stmt->execute([$album_title, $album_link, $album_desc, $albumId]);
  }
  $_SESSION['reopen_modal'] = $yearId;
  $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Album mis à jour.'];
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

if (isset($_POST['add_album'])) {
  $yearId = $_POST['year_id'];
  $albumType = (isset($_POST['album_type']) && $_POST['album_type'] === 'local') ? 'local' : 'link';
  $allowedExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
  $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

  $safeName = null;
  if (!empty($_FILES['album_img']['name'])) {
    $ext  = strtolower(pathinfo($_FILES['album_img']['name'], PATHINFO_EXTENSION));
    $mime = mime_content_type($_FILES['album_img']['tmp_name']);
    if (in_array($ext, $allowedExts) && in_array($mime, $allowedMimes)) {
      $safeName = uniqid('album_', true) . '.' . $ext;
      move_uploaded_file($_FILES['album_img']['tmp_name'], "../files/_albums/" . $safeName);
    }
  }

  if ($albumType === 'local') {
    // Create album first, then create folder with album ID
    $stmt = $pdo->prepare("INSERT INTO photo_albums (year_id, album_title, album_link, album_type, album_img, album_desc) VALUES (?, ?, '', 'local', ?, ?)");
    $stmt->execute([$yearId, $_POST['album_title'], $safeName, $_POST['album_desc']]);
    $newId = $pdo->lastInsertId();
    $basePath = __DIR__ . '/../files/_albums/';
    $folderName = 'album_' . $newId . '_' . bin2hex(random_bytes(6));
    while (is_dir($basePath . $folderName)) {
      $folderName = 'album_' . $newId . '_' . bin2hex(random_bytes(6));
    }
    mkdir($basePath . $folderName, 0755, true);
    $stmt2 = $pdo->prepare("UPDATE photo_albums SET album_link = ? WHERE id = ?");
    $stmt2->execute([$folderName, $newId]);
  } else {
    if ($safeName) {
      $stmt = $pdo->prepare("INSERT INTO photo_albums (year_id, album_title, album_link, album_type, album_img, album_desc) VALUES (?, ?, ?, 'link', ?, ?)");
      $stmt->execute([$yearId, $_POST['album_title'], $_POST['album_link'], $safeName, $_POST['album_desc']]);
    }
  }

  $_SESSION['reopen_modal'] = $yearId;
  $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Album ajouté.'];
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

// ─── Delete album ───
if (isset($_POST['delete_album'])) {
  $albumId = $_POST['album_id'];
  $yearId = $_POST['year_id'];

  // Get album info to check type
  $stmt = $pdo->prepare("SELECT album_img, album_type, album_link FROM photo_albums WHERE id = ?");
  $stmt->execute([$albumId]);
  $albumRow = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($albumRow) {
    // Delete thumbnail
    if (!empty($albumRow['album_img']) && file_exists("../files/_albums/" . $albumRow['album_img'])) {
      unlink("../files/_albums/" . $albumRow['album_img']);
    }
    // Delete local album folder
    if (($albumRow['album_type'] ?? 'link') === 'local' && !empty($albumRow['album_link'])) {
      $folderPath = __DIR__ . '/../files/_albums/' . basename($albumRow['album_link']);
      if (is_dir($folderPath)) {
        deleteDirectory($folderPath);
      }
    }
  }

  // Always hard delete (no trash for albums in modal)
  $stmt = $pdo->prepare("DELETE FROM photo_albums WHERE id = ?");
  $stmt->execute([$albumId]);

  $_SESSION['reopen_modal'] = $yearId;
  $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Album supprimé définitivement.'];
  header("Location: " . $_SERVER['PHP_SELF'] . "?filter=" . ($_GET['filter'] ?? ''));
  exit;
}

if (isset($_POST['add_year'])) {
  if ($hasStatusCol) {
    $status = isset($_POST['status']) && in_array($_POST['status'], ['published', 'draft']) ? $_POST['status'] : 'draft';
    $stmt = $pdo->prepare("INSERT INTO photo_years (year, title, status) VALUES (?, ?, ?)");
    $stmt->execute([$_POST['year'], $_POST['title'], $status]);
  } else {
    $stmt = $pdo->prepare("INSERT INTO photo_years (year, title) VALUES (?, ?)");
    $stmt->execute([$_POST['year'], $_POST['title']]);
  }
  $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Année ajoutée.'];
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

// ─── Reorder albums (AJAX) ───
if (isset($_POST['reorder_albums'])) {
  $ids = json_decode($_POST['album_ids'], true);
  if (is_array($ids)) {
    $stmt = $pdo->prepare("UPDATE photo_albums SET sort_order = ? WHERE id = ?");
    foreach ($ids as $i => $id) {
      $stmt->execute([$i, (int)$id]);
    }
  }
  if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
  }
  $yearId = $_POST['year_id'] ?? '';
  $_SESSION['reopen_modal'] = $yearId;
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

// ─── Delete year ───
if (isset($_POST['delete_year'])) {
  $yearId = $_POST['year_id'];

  if ($migrationDone) {
    // Soft-delete the year
    $stmt = $pdo->prepare("UPDATE photo_years SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$yearId]);
    // Soft-delete all child albums
    $stmt = $pdo->prepare("UPDATE photo_albums SET deleted_at = NOW() WHERE year_id = ?");
    $stmt->execute([$yearId]);
    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Année mise en corbeille.'];
    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=" . ($_GET['filter'] ?? ''));
  } else {
    // Hard delete (old behavior)
    $stmt = $pdo->prepare("SELECT album_img, album_type, album_link FROM photo_albums WHERE year_id = ?");
    $stmt->execute([$yearId]);
    $albumRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($albumRows as $ar) {
      if (!empty($ar['album_img']) && file_exists("../files/_albums/" . $ar['album_img'])) {
        unlink("../files/_albums/" . $ar['album_img']);
      }
      if (($ar['album_type'] ?? 'link') === 'local' && !empty($ar['album_link'])) {
        $fp = __DIR__ . '/../files/_albums/' . basename($ar['album_link']);
        if (is_dir($fp)) deleteDirectory($fp);
      }
    }
    $stmt1 = $pdo->prepare("DELETE FROM photo_albums WHERE year_id = ?");
    $stmt1->execute([$yearId]);
    $stmt2 = $pdo->prepare("DELETE FROM photo_years WHERE id = ?");
    $stmt2->execute([$yearId]);
    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Année supprimée.'];
    header("Location: " . $_SERVER['PHP_SELF']);
  }
  exit;
}

if ($migrationDone) {
  // ─── Restore year from trash ───
  if (isset($_POST['restore_year'])) {
    $yearId = $_POST['year_id'];

    $stmt = $pdo->prepare("UPDATE photo_years SET deleted_at = NULL WHERE id = ?");
    $stmt->execute([$yearId]);

    $stmt = $pdo->prepare("UPDATE photo_albums SET deleted_at = NULL WHERE year_id = ?");
    $stmt->execute([$yearId]);

    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Année restaurée.'];
    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
    exit;
  }

  // ─── Restore album from trash ───
  if (isset($_POST['restore_album'])) {
    $albumId = $_POST['album_id'];
    $yearId = $_POST['year_id'];

    $stmt = $pdo->prepare("UPDATE photo_albums SET deleted_at = NULL WHERE id = ?");
    $stmt->execute([$albumId]);

    $_SESSION['reopen_modal'] = $yearId;
    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Album restauré.'];
    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
    exit;
  }

  // ─── Permanent delete year ───
  if (isset($_POST['permanent_delete_year'])) {
    $yearId = $_POST['year_id'];

    // Delete image files and local folders for all albums
    $stmt = $pdo->prepare("SELECT album_img, album_type, album_link FROM photo_albums WHERE year_id = ?");
    $stmt->execute([$yearId]);
    $albumRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($albumRows as $ar) {
      if (!empty($ar['album_img']) && file_exists("../files/_albums/" . $ar['album_img'])) {
        unlink("../files/_albums/" . $ar['album_img']);
      }
      if (($ar['album_type'] ?? 'link') === 'local' && !empty($ar['album_link'])) {
        $fp = __DIR__ . '/../files/_albums/' . basename($ar['album_link']);
        if (is_dir($fp)) deleteDirectory($fp);
      }
    }

    // Delete albums and year permanently
    $stmt1 = $pdo->prepare("DELETE FROM photo_albums WHERE year_id = ?");
    $stmt1->execute([$yearId]);
    $stmt2 = $pdo->prepare("DELETE FROM photo_years WHERE id = ?");
    $stmt2->execute([$yearId]);

    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Année supprimée définitivement.'];
    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
    exit;
  }

  // ─── Permanent delete album ───
  if (isset($_POST['permanent_delete_album'])) {
    $albumId = $_POST['album_id'];
    $yearId = $_POST['year_id'];

    // Get album info
    $stmt = $pdo->prepare("SELECT album_img, album_type, album_link FROM photo_albums WHERE id = ?");
    $stmt->execute([$albumId]);
    $albumRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($albumRow) {
      $img = $albumRow['album_img'];
      if ($img && file_exists("../files/_albums/" . $img)) {
        unlink("../files/_albums/" . $img);
      }
      // Delete local album folder
      if (($albumRow['album_type'] ?? 'link') === 'local' && !empty($albumRow['album_link'])) {
        $folderPath = __DIR__ . '/../files/_albums/' . basename($albumRow['album_link']);
        if (is_dir($folderPath)) {
          deleteDirectory($folderPath);
        }
      }
    }

    // Delete album permanently
    $stmt = $pdo->prepare("DELETE FROM photo_albums WHERE id = ?");
    $stmt->execute([$albumId]);

    $_SESSION['reopen_modal'] = $yearId;
    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Album supprimé définitivement.'];
    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
    exit;
  }
}

// ─── Filter logic ───
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';
$isTrashed = false;

if ($migrationDone) {
  $isTrashed = ($filter === 'trashed');

  if ($isTrashed) {
    $years = $pdo->query("SELECT * FROM photo_years WHERE deleted_at IS NOT NULL ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
  } elseif ($hasStatusCol && $filter === 'published') {
    $years = $pdo->query("SELECT * FROM photo_years WHERE deleted_at IS NULL AND status = 'published' ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
  } elseif ($hasStatusCol && $filter === 'draft') {
    $years = $pdo->query("SELECT * FROM photo_years WHERE deleted_at IS NULL AND status = 'draft' ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $years = $pdo->query("SELECT * FROM photo_years WHERE deleted_at IS NULL ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
  }

  $albumsByYear = [];
  foreach ($years as $y) {
    if ($isTrashed) {
      $stmt = $pdo->prepare("SELECT * FROM photo_albums WHERE year_id = ? ORDER BY sort_order");
    } else {
      $stmt = $pdo->prepare("SELECT * FROM photo_albums WHERE year_id = ? AND deleted_at IS NULL ORDER BY sort_order");
    }
    $stmt->execute([$y['id']]);
    $albumsByYear[$y['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  // Counts for tab badges
  $countAll     = $pdo->query("SELECT COUNT(*) FROM photo_years WHERE deleted_at IS NULL")->fetchColumn();
  $countTrashed = $pdo->query("SELECT COUNT(*) FROM photo_years WHERE deleted_at IS NOT NULL")->fetchColumn();
  if ($hasStatusCol) {
    $countPublished = $pdo->query("SELECT COUNT(*) FROM photo_years WHERE deleted_at IS NULL AND status = 'published'")->fetchColumn();
    $countDraft     = $pdo->query("SELECT COUNT(*) FROM photo_years WHERE deleted_at IS NULL AND (status = 'draft' OR status IS NULL)")->fetchColumn();
  }
} else {
  $filter = '';
  $years = $pdo->query("SELECT * FROM photo_years ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);

  $albumsByYear = [];
  foreach ($years as $y) {
    $stmt = $pdo->prepare("SELECT * FROM photo_albums WHERE year_id = ? ORDER BY sort_order");
    $stmt->execute([$y['id']]);
    $albumsByYear[$y['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
}
?>

<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Albums</title>

<!-- ─── CSS ─── -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<style>
  .card-dashboard{margin-top:1rem;border-radius:1.25rem;box-shadow:0 0 25px rgba(0,0,0,.1)}

  /* Filter tabs */
  .filter-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0;
    border-bottom: 2px solid #f0e8eb;
    margin-bottom: 1rem;
  }
  .filter-tabs a {
    padding: 0.5rem 1.25rem;
    text-decoration: none;
    color: #1e293b;
    font-weight: 500;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: color .15s, border-color .15s;
  }
  .filter-tabs a:hover {
    color: #1e293b;
    border-bottom-color: #d4c4cb;
  }
  .filter-tabs a.active {
    color: #1e293b;
    border-bottom-color: #ec4899;
    font-weight: 600;
  }
  .filter-tabs .badge {
    font-size: 0.7rem;
    vertical-align: middle;
    margin-left: 4px;
  }

  /* Year list item */
  .year-list-item {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 0.75rem;
    padding: 1rem 1.25rem;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: box-shadow .15s;
  }
  .year-list-item:hover {
    box-shadow: 0 2px 12px rgba(0,0,0,.08);
  }
  .year-list-item .year-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }
  .year-list-item .year-info .year-name {
    font-weight: 700;
    font-size: 1.05rem;
  }
  .album-count-badge {
    font-size: 0.75rem;
    padding: 3px 10px;
    border-radius: 20px;
  }

  /* Trashed style */
  .year-list-item.trashed {
    opacity: 0.7;
    border: 1px dashed #dc3545;
  }
  /* Drag-and-drop albums */
  .drag-handle-album:hover { color: #ec4899 !important; }
  .sortable-ghost-album { opacity: 0.4; background: #ffe5ff !important; }
</style>
</head>

<body>

<?php include '../inc/navbar-admin.php'; ?>

<?php
  $reopenModalId = $_SESSION['reopen_modal'] ?? null;
  $flashForModal = null;
  if ($reopenModalId && isset($_SESSION['flash_message'])) {
      $flashForModal = $_SESSION['flash_message'];
      unset($_SESSION['flash_message']);
  }
?>

<?php if (!$reopenModalId && isset($_SESSION['flash_message'])):
    $flash = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show auto-dismiss" data-dismiss-delay="<?= $flash['type'] === 'success' ? '5000' : '10000' ?>" role="alert">
      <?= htmlspecialchars($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Spinner de chargement -->
<div id="loadingSpinner" class="text-center py-5">
  <div class="spinner-border" role="status" style="width:2.5rem;height:2.5rem;color:#ec4899;"></div>
  <p class="text-muted mt-2 small">Chargement des albums...</p>
</div>

<!-- MAIN -->
    <div class="row g-4 align-items-stretch content-loaded" style="display:none;">
        <div class="col-12 col-lg-12 d-flex flex-column gap-4">
            <div class="card-dashboard p-4 shadow-sm rounded-4 bg-white flex-grow-0">
            <!-- Reopen modal script -->
            <?php if ($reopenModalId):
                unset($_SESSION['reopen_modal']);
            ?>
            <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
            document.addEventListener('DOMContentLoaded', function () {
                var modalId = 'modalYear<?= $reopenModalId ?>';
                var el = document.getElementById(modalId);
                if (el) {
                    var modal = new bootstrap.Modal(el);
                    modal.show();
                }
            });
            </script>
            <?php endif; ?>

            <h1 class="mb-3 fw-bold">Gestion des Albums par Année</h1>

            <?php if (!$migrationDone): ?>
            <div class="alert alert-warning" role="alert">
              <i class="bi bi-exclamation-triangle"></i> Veuillez executer la mise a jour BDD pour activer toutes les fonctionnalites (corbeille, filtres).
            </div>
            <?php endif; ?>

            <?php if ($migrationDone): ?>
            <!-- Filter tabs -->
            <div class="filter-tabs">
              <a href="?filter=" class="<?= $filter === '' ? 'active' : '' ?>">
                Tous <span class="badge bg-secondary"><?= $countAll ?></span>
              </a>
              <?php if ($hasStatusCol): ?>
              <a href="?filter=published" class="<?= $filter === 'published' ? 'active' : '' ?>">
                Publiés <span class="badge bg-success"><?= $countPublished ?></span>
              </a>
              <a href="?filter=draft" class="<?= $filter === 'draft' ? 'active' : '' ?>">
                Brouillons <span class="badge bg-warning text-dark"><?= $countDraft ?></span>
              </a>
              <?php endif; ?>
              <a href="?filter=trashed" class="<?= $filter === 'trashed' ? 'active' : '' ?>">
                <i class="bi bi-trash3"></i> Corbeille <span class="badge bg-danger"><?= $countTrashed ?></span>
              </a>
            </div>
            <?php endif; ?>

            <?php if (!$isTrashed): ?>
            <!-- Bouton pour ajouter une année -->
            <button class="btn btn-primary mb-4" data-bs-toggle="modal" data-bs-target="#modalAddYear">
              <i class="bi bi-plus-lg"></i> Ajouter une Année
            </button>
            <?php endif; ?>

            <?php if (empty($years)): ?>
              <div class="text-center text-muted py-5">
                <i class="bi bi-images" style="font-size:3rem;"></i>
                <p class="mt-2"><?= $isTrashed ? 'La corbeille est vide.' : 'Aucune année trouvée.' ?></p>
              </div>
            <?php else: ?>

            <?php foreach ($years as $year): ?>
              <?php $albumCount = count($albumsByYear[$year['id']]); ?>

              <?php if ($isTrashed): ?>
              <!-- TRASH VIEW: simple row with restore/delete buttons -->
              <div class="year-list-item trashed">
                <div class="year-info">
                  <span class="year-name"><?= htmlspecialchars($year['year']) ?> - <?= htmlspecialchars($year['title']) ?></span>
                  <span class="badge album-count-badge bg-secondary"><?= $albumCount ?> album<?= $albumCount > 1 ? 's' : '' ?></span>
                </div>
                <div class="d-flex gap-2">
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                    <button type="submit" name="restore_year" class="btn btn-sm btn-success">
                      <i class="bi bi-arrow-counterclockwise"></i> Restaurer
                    </button>
                  </form>
                  <form method="post" data-confirm="Supprimer DÉFINITIVEMENT cette année et tous ses albums ? Les fichiers images seront supprimés. Cette action est irréversible.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                    <button type="submit" name="permanent_delete_year" class="btn btn-sm btn-danger">
                      <i class="bi bi-x-circle"></i> Supprimer définitivement
                    </button>
                  </form>
                </div>
              </div>

              <?php else: ?>
              <!-- ACTIVE VIEW: year row with modal button -->
              <div class="year-list-item">
                <div class="year-info">
                  <span class="year-name"><?= htmlspecialchars($year['year']) ?> - <?= htmlspecialchars($year['title']) ?></span>
                  <span class="badge album-count-badge bg-primary"><?= $albumCount ?> album<?= $albumCount > 1 ? 's' : '' ?></span>
                  <?php if ($hasStatusCol): ?>
                  <span class="badge <?= ($year['status'] ?? 'draft') === 'published' ? 'bg-success' : 'bg-warning text-dark' ?>" style="font-size:0.75rem;padding:3px 10px;border-radius:20px;">
                    <?= ($year['status'] ?? 'draft') === 'published' ? 'Publié' : 'Brouillon' ?>
                  </span>
                  <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                  <a href="../public/photos.php?preview_year=<?= $year['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Aperçu">
                    <i class="bi bi-eye"></i> Aperçu
                  </a>
                  <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalYear<?= $year['id'] ?>">
                    <i class="bi bi-pencil"></i> Modifier
                  </button>
                </div>
              </div>

              <!-- Modal de modification année -->
              <div class="modal fade" id="modalYear<?= $year['id'] ?>" tabindex="-1">
                <div class="modal-dialog modal-xl modal-fullscreen-lg-down">
                <div class="modal-content p-4">
                    <div class="modal-header">
                    <h5 class="modal-title">Modifier l'année <?= htmlspecialchars($year['year']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                    <?php if ($flashForModal && $reopenModalId == $year['id']): ?>
                    <div class="alert alert-<?= $flashForModal['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show auto-dismiss" data-dismiss-delay="<?= $flashForModal['type'] === 'success' ? '5000' : '10000' ?>" role="alert">
                      <?= htmlspecialchars($flashForModal['message']) ?>
                      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    <form method="post" enctype="multipart/form-data" class="mb-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                        <div class="row g-3">
                        <div class="<?= $hasStatusCol ? 'col-md-3' : 'col-md-6' ?>">
                            <label class="form-label">Année</label>
                            <input type="number" name="year" class="form-control" value="<?= htmlspecialchars($year['year']) ?>">
                        </div>
                        <div class="<?= $hasStatusCol ? 'col-md-5' : 'col-md-6' ?>">
                            <label class="form-label">Titre</label>
                            <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($year['title']) ?>">
                        </div>
                        <?php if ($hasStatusCol): ?>
                        <div class="col-md-4">
                            <label class="form-label">Statut</label>
                            <select name="status" class="form-select">
                              <option value="draft" <?= ($year['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Brouillon</option>
                              <option value="published" <?= ($year['status'] ?? 'draft') === 'published' ? 'selected' : '' ?>>Publié</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3 mb-4">
                          <button type="submit" name="update_year" class="btn btn-primary">Enregistrer</button>
                    </form>
                          <form method="post" data-confirm="<?= $migrationDone ? 'Mettre cette année et tous ses albums en corbeille ?' : 'Supprimer definitivement cette annee et tous ses albums ?' ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                            <button type="submit" name="delete_year" class="btn btn-danger">
                              <i class="bi bi-trash3"></i> <?= $migrationDone ? 'Mettre en corbeille' : 'Supprimer' ?>
                            </button>
                          </form>
                        </div>

                    <h6>Ajouter un nouvel album</h6>
                    <form method="post" enctype="multipart/form-data" style="border:1px solid #f0e8eb;border-radius:8px;padding:16px;background:#fff" class="add-album-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                        <div class="row g-2 mb-3">
                          <div class="col-12">
                            <label class="form-label" style="font-size:12px;font-weight:600">Type d'album</label>
                            <div class="d-flex gap-3">
                              <div class="form-check">
                                <input class="form-check-input album-type-radio" type="radio" name="album_type" value="link" id="type_link_<?= $year['id'] ?>" checked>
                                <label class="form-check-label" for="type_link_<?= $year['id'] ?>">
                                  <i class="bi bi-link-45deg"></i> Lien externe
                                </label>
                              </div>
                              <div class="form-check">
                                <input class="form-check-input album-type-radio" type="radio" name="album_type" value="local" id="type_local_<?= $year['id'] ?>">
                                <label class="form-check-label" for="type_local_<?= $year['id'] ?>">
                                  <i class="bi bi-images"></i> Album local (photos sur le serveur)
                                </label>
                              </div>
                            </div>
                          </div>
                        </div>
                        <div class="row g-2 align-items-end">
                          <div class="col-md-3">
                            <label class="form-label" style="font-size:12px">Titre</label>
                            <input type="text" name="album_title" class="form-control" placeholder="Titre" required>
                          </div>
                          <div class="col-md-3 album-link-field">
                            <label class="form-label" style="font-size:12px">Lien</label>
                            <input type="url" name="album_link" class="form-control" placeholder="https://...">
                          </div>
                          <div class="col-md-2">
                            <label class="form-label" style="font-size:12px">Image de couverture</label>
                            <input type="file" name="album_img" class="form-control album-img-input">
                          </div>
                          <div class="col-md-3">
                            <label class="form-label" style="font-size:12px">Description</label>
                            <input type="text" name="album_desc" class="form-control" placeholder="Description">
                          </div>
                          <div class="col-auto d-flex align-items-end">
                            <button type="submit" name="add_album" class="btn btn-primary" style="height:38px;width:38px;padding:0;display:flex;align-items:center;justify-content:center;border-radius:8px"><i class="bi bi-plus-lg"></i></button>
                          </div>
                        </div>
                        <div class="album-local-hint mt-2" style="display:none">
                          <small class="text-muted"><i class="bi bi-info-circle"></i> Un dossier sera cree sur le serveur. Vous pourrez ajouter des photos apres la creation.</small>
                        </div>
                    </form>

                    <div class="mb-3"></div>

                    <h5>Albums associes (<?= count($albumsByYear[$year['id']]) ?>)</h5>
                    <div class="mb-3 sortable-albums" data-year-id="<?= $year['id'] ?>">
                        <?php foreach ($albumsByYear[$year['id']] as $album): ?>
                        <?php $isLocalAlbum = (($album['album_type'] ?? 'link') === 'local'); ?>
                        <div class="p-3 mb-2 sortable-album-item" data-album-id="<?= $album['id'] ?>" style="border:1px solid <?= $isLocalAlbum ? '#c4b5fd' : '#f0e8eb' ?>;border-radius:8px;background:<?= $isLocalAlbum ? '#f5f3ff' : '#fdf8f9' ?>">
                        <form method="post" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="album_id" value="<?= $album['id'] ?>">
                            <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                            <div class="row g-2 align-items-end flex-nowrap">
                              <div class="col-auto d-flex align-items-center" style="min-width:30px">
                                <span class="drag-handle-album" style="cursor:grab;color:#94a3b8;font-size:1.2rem" title="Glisser pour réordonner"><i class="bi bi-grip-vertical"></i></span>
                              </div>
                              <div class="col-auto d-flex align-items-center">
                                <?php if ($isLocalAlbum): ?>
                                  <span class="badge bg-purple" style="background:#7c3aed;font-size:0.7rem"><i class="bi bi-images"></i> Local</span>
                                <?php else: ?>
                                  <span class="badge bg-info" style="font-size:0.7rem"><i class="bi bi-link-45deg"></i> Lien</span>
                                <?php endif; ?>
                              </div>
                              <div class="col">
                                <label class="form-label" style="font-size:12px">Titre</label>
                                <input type="text" name="album_title" class="form-control form-control-sm" value="<?= htmlspecialchars($album['album_title']) ?>">
                              </div>
                              <?php if (!$isLocalAlbum): ?>
                              <div class="col">
                                <label class="form-label" style="font-size:12px">Lien</label>
                                <input type="text" name="album_link" class="form-control form-control-sm" value="<?= htmlspecialchars($album['album_link']) ?>">
                              </div>
                              <?php endif; ?>
                              <div class="col-auto" style="min-width:140px">
                                <label class="form-label" style="font-size:12px">Couverture</label>
                                <input type="file" name="album_img" class="form-control form-control-sm">
                                <?php if (!empty($album['album_img'])): ?>
                                <div class="form-check mt-1">
                                  <input type="checkbox" name="delete_image" value="1" class="form-check-input" id="delImgAlbum<?= $album['id'] ?>">
                                  <label class="form-check-label text-danger" style="font-size:11px" for="delImgAlbum<?= $album['id'] ?>">Supprimer</label>
                                </div>
                                <?php endif; ?>
                              </div>
                              <div class="col">
                                <label class="form-label" style="font-size:12px">Description</label>
                                <input type="text" name="album_desc" class="form-control form-control-sm" value="<?= htmlspecialchars($album['album_desc']) ?>">
                              </div>
                              <div class="col-auto text-end">
                                <div class="d-flex gap-1">
                                  <?php if ($isLocalAlbum): ?>
                                  <button type="button" class="btn btn-sm btn-outline-primary btn-manage-photos" data-album-id="<?= $album['id'] ?>" data-album-title="<?= htmlspecialchars($album['album_title']) ?>" title="Gerer les photos"><i class="bi bi-camera"></i></button>
                                  <?php endif; ?>
                                  <button type="submit" name="update_album" class="btn btn-sm btn-success" title="Enregistrer"><i class="bi bi-check-lg"></i></button>
                                  <button type="submit" name="delete_album" class="btn btn-sm btn-outline-danger" title="Supprimer" data-confirm="Supprimer définitivement cet album ?"><i class="bi bi-x-lg"></i></button>
                                </div>
                              </div>
                            </div>
                        </form>
                        <?php if ($isLocalAlbum): ?>
                          <?php
                            $localFolder = __DIR__ . '/../files/_albums/' . basename($album['album_link']);
                            $photoCount = 0;
                            if (is_dir($localFolder)) {
                              $exts = ['jpg','jpeg','png','gif','webp'];
                              foreach (scandir($localFolder) as $f) {
                                if ($f === '.' || $f === '..') continue;
                                if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts)) $photoCount++;
                              }
                            }
                          ?>
                          <div class="mt-2 d-flex align-items-center gap-2">
                            <small class="text-muted photo-count-label" data-album-id="<?= $album['id'] ?>"><i class="bi bi-folder2-open"></i> <span class="photo-count-num"><?= $photoCount ?></span> photo<?= $photoCount > 1 ? 's' : '' ?></small>
                            <small class="text-muted">|</small>
                            <small class="text-muted"><?= htmlspecialchars(basename($album['album_link'])) ?></small>
                          </div>
                        <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    </div>
                </div>
                </div>
              </div>
              <?php endif; ?>

            <?php endforeach; ?>

            <?php endif; ?>

            <?php if (!$isTrashed): ?>
            <!-- Modal ajout année -->
            <div class="modal fade" id="modalAddYear" tabindex="-1">
            <div class="modal-dialog modal-xl modal-fullscreen-lg-down">
                <div class="modal-content p-4">
                <div class="modal-header">
                    <h5 class="modal-title">Ajouter une Année</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" enctype="multipart/form-data" class="modal-body row g-3">
                    <?= csrf_field() ?>
                    <div class="col-md-6">
                    <label class="form-label">Année</label>
                    <input type="number" name="year" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                    <label class="form-label">Titre</label>
                    <input type="text" name="title" class="form-control" required>
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
                    <div class="col-12">
                    <button type="submit" name="add_year" class="btn btn-success">Ajouter</button>
                    </div>
                </form>
                </div>
            </div>
            </div>
            <?php endif; ?>

        </div>
    </div><!-- /row -->

<!-- Modal gestion photos album local -->
<div class="modal fade" id="modalPhotosManager" tabindex="-1">
  <div class="modal-dialog modal-xl modal-fullscreen-lg-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-images"></i> Photos - <span id="pmAlbumTitle"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Upload zone -->
        <div id="pmUploadZone" style="border:2px dashed #c4b5fd;border-radius:12px;padding:30px;text-align:center;background:#f5f3ff;cursor:pointer;transition:all .2s;margin-bottom:20px">
          <i class="bi bi-cloud-arrow-up" style="font-size:2.5rem;color:#7c3aed"></i>
          <p class="mb-1 fw-semibold" style="color:#7c3aed">Glissez vos photos ici ou cliquez pour selectionner</p>
          <p class="text-muted small mb-0">JPG, PNG, GIF, WEBP - Plusieurs fichiers a la fois</p>
          <input type="file" id="pmFileInput" multiple accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
        </div>

        <!-- Progress bar -->
        <div id="pmProgressWrap" style="display:none;margin-bottom:20px">
          <div class="d-flex justify-content-between mb-1">
            <small class="fw-semibold" id="pmProgressLabel">Upload en cours...</small>
            <small id="pmProgressPercent">0%</small>
          </div>
          <div class="progress" style="height:8px;border-radius:4px">
            <div class="progress-bar" id="pmProgressBar" role="progressbar" style="width:0%;background:#7c3aed;transition:width .3s"></div>
          </div>
          <small class="text-muted" id="pmProgressDetail"></small>
        </div>

        <!-- Delete all button -->
        <div id="pmDeleteAllWrap" style="display:none;margin-bottom:15px;text-align:right">
          <button type="button" class="btn btn-sm btn-danger" id="pmDeleteAll"><i class="bi bi-trash3"></i> Tout supprimer</button>
        </div>

        <!-- Photos grid -->
        <div id="pmPhotosGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">
        </div>

        <div id="pmEmpty" style="text-align:center;padding:40px;color:#94a3b8;display:none">
          <i class="bi bi-image" style="font-size:3rem"></i>
          <p class="mt-2">Aucune photo dans cet album</p>
        </div>

        <div id="pmLoading" style="text-align:center;padding:30px">
          <div class="spinner-border" style="color:#7c3aed"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include '../inc/admin-footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
// Masquer spinner, afficher contenu
var sp = document.getElementById('loadingSpinner');
if (sp) sp.style.display = 'none';
document.querySelectorAll('.content-loaded').forEach(function(el) { el.style.display = ''; });

// Auto-dismiss des alertes
document.querySelectorAll('.auto-dismiss').forEach(function(alert) {
  var delay = parseInt(alert.dataset.dismissDelay) || 5000;
  setTimeout(function() {
    var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
    bsAlert.close();
  }, delay);
});

// Sortable albums
document.querySelectorAll('.sortable-albums').forEach(function(container) {
  Sortable.create(container, {
    handle: '.drag-handle-album',
    animation: 150,
    ghostClass: 'sortable-ghost-album',
    onEnd: function() {
      var ids = [];
      container.querySelectorAll('.sortable-album-item').forEach(function(item) {
        ids.push(item.dataset.albumId);
      });
      var yearId = container.dataset.yearId;
      var form = new FormData();
      form.append('reorder_albums', '1');
      form.append('album_ids', JSON.stringify(ids));
      form.append('year_id', yearId);
      form.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');
      fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: form
      });
    }
  });
});

// ─── Album type toggle (add form) ───
document.querySelectorAll('.add-album-form').forEach(function(form) {
  var radios = form.querySelectorAll('.album-type-radio');
  var linkField = form.querySelector('.album-link-field');
  var imgInput = form.querySelector('.album-img-input');
  var hint = form.querySelector('.album-local-hint');

  radios.forEach(function(radio) {
    radio.addEventListener('change', function() {
      if (this.value === 'local') {
        if (linkField) linkField.style.display = 'none';
        if (imgInput) imgInput.removeAttribute('required');
        if (hint) hint.style.display = 'block';
      } else {
        if (linkField) linkField.style.display = '';
        if (hint) hint.style.display = 'none';
      }
    });
  });
});

// ─── Photo manager ───
var csrfToken = '<?= $_SESSION['csrf_token'] ?? '' ?>';
var currentAlbumId = null;
var pmModal = null;
var parentYearModalEl = null;

document.querySelectorAll('.btn-manage-photos').forEach(function(btn) {
  btn.addEventListener('click', function(e) {
    e.preventDefault();
    e.stopPropagation();
    currentAlbumId = this.dataset.albumId;
    document.getElementById('pmAlbumTitle').textContent = this.dataset.albumTitle;

    // Find the parent year modal element
    var yearModalEl = this.closest('.modal');
    parentYearModalEl = yearModalEl;

    if (yearModalEl) {
      // Get or create instance and hide it
      var inst = bootstrap.Modal.getInstance(yearModalEl) || new bootstrap.Modal(yearModalEl);
      inst.hide();

      // Open photos modal after parent finishes closing
      yearModalEl.addEventListener('hidden.bs.modal', function openPhotos() {
        yearModalEl.removeEventListener('hidden.bs.modal', openPhotos);
        if (!pmModal) {
          pmModal = new bootstrap.Modal(document.getElementById('modalPhotosManager'));
        }
        pmModal.show();
        loadPhotos();
      });
    } else {
      if (!pmModal) {
        pmModal = new bootstrap.Modal(document.getElementById('modalPhotosManager'));
      }
      pmModal.show();
      loadPhotos();
    }
  });
});

// Reopen parent year modal when photos modal is closed + update photo count
document.getElementById('modalPhotosManager').addEventListener('hidden.bs.modal', function() {
  // Update photo count via API
  if (currentAlbumId) {
    fetch('album-photos-handler.php?action=list&album_id=' + currentAlbumId)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        var count = (data.photos && data.photos.length) || 0;
        var label = document.querySelector('.photo-count-label[data-album-id="' + currentAlbumId + '"]');
        if (label) {
          label.innerHTML = '<i class="bi bi-folder2-open"></i> ' + count + ' photo' + (count > 1 ? 's' : '');
        }
      });
  }

  if (parentYearModalEl) {
    var inst = bootstrap.Modal.getInstance(parentYearModalEl) || new bootstrap.Modal(parentYearModalEl);
    inst.show();
    parentYearModalEl = null;
  }
});

// Upload zone interactions
var uploadZone = document.getElementById('pmUploadZone');
var fileInput = document.getElementById('pmFileInput');

if (uploadZone && fileInput) {
  uploadZone.addEventListener('click', function() { fileInput.click(); });
  uploadZone.addEventListener('dragover', function(e) {
    e.preventDefault();
    this.style.borderColor = '#7c3aed';
    this.style.background = '#ede9fe';
  });
  uploadZone.addEventListener('dragleave', function(e) {
    e.preventDefault();
    this.style.borderColor = '#c4b5fd';
    this.style.background = '#f5f3ff';
  });
  uploadZone.addEventListener('drop', function(e) {
    e.preventDefault();
    this.style.borderColor = '#c4b5fd';
    this.style.background = '#f5f3ff';
    if (e.dataTransfer.files.length) {
      uploadPhotos(e.dataTransfer.files);
    }
  });
  fileInput.addEventListener('change', function() {
    if (this.files.length) {
      uploadPhotos(this.files);
      this.value = '';
    }
  });
}

function loadPhotos() {
  var grid = document.getElementById('pmPhotosGrid');
  var empty = document.getElementById('pmEmpty');
  var loading = document.getElementById('pmLoading');
  var deleteAllWrap = document.getElementById('pmDeleteAllWrap');
  grid.innerHTML = '';
  empty.style.display = 'none';
  deleteAllWrap.style.display = 'none';
  loading.style.display = 'block';

  fetch('album-photos-handler.php?action=list&album_id=' + currentAlbumId)
    .then(function(r) { return r.json(); })
    .then(function(data) {
      loading.style.display = 'none';
      if (!data.photos || data.photos.length === 0) {
        empty.style.display = 'block';
        return;
      }
      deleteAllWrap.style.display = 'block';
      data.photos.forEach(function(photo) {
        grid.appendChild(createPhotoCard(photo));
      });
    })
    .catch(function() {
      loading.style.display = 'none';
      empty.style.display = 'block';
    });
}

// Delete all photos
document.getElementById('pmDeleteAll').addEventListener('click', function() {
  if (!confirm('Supprimer definitivement TOUTES les photos de cet album ?')) return;
  var form = new FormData();
  form.append('action', 'delete_all');
  form.append('album_id', currentAlbumId);
  form.append('csrf_token', csrfToken);
  fetch('album-photos-handler.php', { method: 'POST', body: form })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.ok) loadPhotos();
    });
});

function createPhotoCard(photo) {
  var card = document.createElement('div');
  card.style.cssText = 'position:relative;border-radius:8px;overflow:hidden;aspect-ratio:1;background:#f1f5f9';
  card.innerHTML =
    '<img src="' + photo.url + '" style="width:100%;height:100%;object-fit:cover;display:block" loading="lazy">' +
    '<div style="position:absolute;top:6px;right:6px;display:flex;gap:4px">' +
      '<button class="btn btn-sm btn-light pm-set-thumb" data-filename="' + photo.filename + '" title="Definir comme couverture" style="width:28px;height:28px;padding:0;border-radius:6px;display:flex;align-items:center;justify-content:center;opacity:0.85"><i class="bi bi-star" style="font-size:12px"></i></button>' +
      '<button class="btn btn-sm btn-danger pm-delete" data-filename="' + photo.filename + '" title="Supprimer" style="width:28px;height:28px;padding:0;border-radius:6px;display:flex;align-items:center;justify-content:center;opacity:0.85"><i class="bi bi-trash3" style="font-size:12px"></i></button>' +
    '</div>';

  card.querySelector('.pm-delete').addEventListener('click', function() {
    if (!confirm('Supprimer cette photo ?')) return;
    var fn = this.dataset.filename;
    var form = new FormData();
    form.append('action', 'delete_photo');
    form.append('album_id', currentAlbumId);
    form.append('filename', fn);
    form.append('csrf_token', csrfToken);
    fetch('album-photos-handler.php', { method: 'POST', body: form })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.ok) {
          card.remove();
          var grid = document.getElementById('pmPhotosGrid');
          if (!grid.children.length) {
            document.getElementById('pmEmpty').style.display = 'block';
            document.getElementById('pmDeleteAllWrap').style.display = 'none';
          }
        }
      });
  });

  card.querySelector('.pm-set-thumb').addEventListener('click', function() {
    var fn = this.dataset.filename;
    var form = new FormData();
    form.append('action', 'set_thumbnail');
    form.append('album_id', currentAlbumId);
    form.append('filename', fn);
    form.append('csrf_token', csrfToken);
    fetch('album-photos-handler.php', { method: 'POST', body: form })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.ok) {
          alert('Couverture mise a jour !');
        }
      });
  });

  return card;
}

function uploadPhotos(files) {
  var progressWrap = document.getElementById('pmProgressWrap');
  var progressBar = document.getElementById('pmProgressBar');
  var progressLabel = document.getElementById('pmProgressLabel');
  var progressPercent = document.getElementById('pmProgressPercent');
  var progressDetail = document.getElementById('pmProgressDetail');

  progressWrap.style.display = 'block';
  progressBar.style.width = '0%';
  progressPercent.textContent = '0%';

  var total = files.length;
  var done = 0;
  var batchSize = 3; // Upload 3 at a time
  var queue = Array.from(files);
  var errors = [];

  progressLabel.textContent = 'Upload en cours...';
  progressDetail.textContent = '0 / ' + total + ' photos';

  function uploadNext() {
    if (queue.length === 0) {
      if (done >= total) {
        progressLabel.textContent = 'Upload termine !';
        progressDetail.textContent = done + ' / ' + total + ' photos' + (errors.length ? ' (' + errors.length + ' erreur(s))' : '');
        setTimeout(function() { progressWrap.style.display = 'none'; }, 2000);
        loadPhotos();
      }
      return;
    }

    var batch = queue.splice(0, batchSize);
    var form = new FormData();
    form.append('action', 'upload');
    form.append('album_id', currentAlbumId);
    form.append('csrf_token', csrfToken);
    batch.forEach(function(file) {
      form.append('photos[]', file);
    });

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'album-photos-handler.php');

    xhr.upload.addEventListener('progress', function(e) {
      if (e.lengthComputable) {
        var batchProgress = e.loaded / e.total;
        var overallProgress = ((done + batchProgress * batch.length) / total) * 100;
        progressBar.style.width = Math.round(overallProgress) + '%';
        progressPercent.textContent = Math.round(overallProgress) + '%';
      }
    });

    xhr.addEventListener('load', function() {
      try {
        var resp = JSON.parse(xhr.responseText);
        done += resp.success_count || batch.length;
        if (resp.errors && resp.errors.length) {
          errors = errors.concat(resp.errors);
        }
      } catch(e) {
        done += batch.length;
      }
      progressDetail.textContent = done + ' / ' + total + ' photos';
      var pct = Math.round((done / total) * 100);
      progressBar.style.width = pct + '%';
      progressPercent.textContent = pct + '%';
      uploadNext();
    });

    xhr.addEventListener('error', function() {
      done += batch.length;
      errors.push('Erreur reseau pour un lot');
      progressDetail.textContent = done + ' / ' + total + ' photos';
      uploadNext();
    });

    xhr.send(form);
  }

  uploadNext();
}

// Confirm dialogs
document.querySelectorAll('[data-confirm]').forEach(function(form) {
  form.addEventListener('submit', function(e) {
    if (!confirm(this.dataset.confirm)) {
      e.preventDefault();
    }
  });
});
</script>
</body>
</html>
