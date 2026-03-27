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
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

if (isset($_POST['update_album'])) {
  $albumId = $_POST['album_id'];
  $album_title = $_POST['album_title'];
  $album_link = $_POST['album_link'];
  $album_desc = $_POST['album_desc'];
  $yearId = $_POST['year_id'];

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
  } else {
    $stmt = $pdo->prepare("UPDATE photo_albums SET album_title = ?, album_link = ?, album_desc = ? WHERE id = ?");
    $stmt->execute([$album_title, $album_link, $album_desc, $albumId]);
  }
  $_SESSION['reopen_modal'] = $yearId;
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

if (isset($_POST['add_album'])) {
  $yearId = $_POST['year_id'];
  $allowedExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
  $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
  $ext  = strtolower(pathinfo($_FILES['album_img']['name'], PATHINFO_EXTENSION));
  $mime = mime_content_type($_FILES['album_img']['tmp_name']);
  if (in_array($ext, $allowedExts) && in_array($mime, $allowedMimes)) {
    $safeName = uniqid('album_', true) . '.' . $ext;
    move_uploaded_file($_FILES['album_img']['tmp_name'], "../files/_albums/" . $safeName);
    $stmt = $pdo->prepare("INSERT INTO photo_albums (year_id, album_title, album_link, album_img, album_desc) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
      $yearId,
      $_POST['album_title'],
      $_POST['album_link'],
      $safeName,
      $_POST['album_desc']
    ]);
  }
  $_SESSION['reopen_modal'] = $yearId;
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

// ─── Delete album ───
if (isset($_POST['delete_album'])) {
  $albumId = $_POST['album_id'];
  $yearId = $_POST['year_id'];

  if ($migrationDone) {
    $stmt = $pdo->prepare("UPDATE photo_albums SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$albumId]);
    $_SESSION['reopen_modal'] = $yearId;
    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=" . ($_GET['filter'] ?? ''));
  } else {
    // Hard delete (old behavior)
    $stmt = $pdo->prepare("SELECT album_img FROM photo_albums WHERE id = ?");
    $stmt->execute([$albumId]);
    $img = $stmt->fetchColumn();
    if ($img && file_exists("../files/_albums/" . $img)) {
      unlink("../files/_albums/" . $img);
    }
    $stmt = $pdo->prepare("DELETE FROM photo_albums WHERE id = ?");
    $stmt->execute([$albumId]);
    $_SESSION['reopen_modal'] = $yearId;
    header("Location: " . $_SERVER['PHP_SELF']);
  }
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
    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=" . ($_GET['filter'] ?? ''));
  } else {
    // Hard delete (old behavior)
    $stmt = $pdo->prepare("SELECT album_img FROM photo_albums WHERE year_id = ?");
    $stmt->execute([$yearId]);
    $albumImgs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($albumImgs as $img) {
      if ($img && file_exists("../files/_albums/" . $img)) {
        unlink("../files/_albums/" . $img);
      }
    }
    $stmt1 = $pdo->prepare("DELETE FROM photo_albums WHERE year_id = ?");
    $stmt1->execute([$yearId]);
    $stmt2 = $pdo->prepare("DELETE FROM photo_years WHERE id = ?");
    $stmt2->execute([$yearId]);
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
    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
    exit;
  }

  // ─── Permanent delete year ───
  if (isset($_POST['permanent_delete_year'])) {
    $yearId = $_POST['year_id'];

    // Delete image files for all albums
    $stmt = $pdo->prepare("SELECT album_img FROM photo_albums WHERE year_id = ?");
    $stmt->execute([$yearId]);
    $albumImgs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($albumImgs as $img) {
      if ($img && file_exists("../files/_albums/" . $img)) {
        unlink("../files/_albums/" . $img);
      }
    }

    // Delete albums and year permanently
    $stmt1 = $pdo->prepare("DELETE FROM photo_albums WHERE year_id = ?");
    $stmt1->execute([$yearId]);
    $stmt2 = $pdo->prepare("DELETE FROM photo_years WHERE id = ?");
    $stmt2->execute([$yearId]);

    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
    exit;
  }

  // ─── Permanent delete album ───
  if (isset($_POST['permanent_delete_album'])) {
    $albumId = $_POST['album_id'];
    $yearId = $_POST['year_id'];

    // Delete image file
    $stmt = $pdo->prepare("SELECT album_img FROM photo_albums WHERE id = ?");
    $stmt->execute([$albumId]);
    $img = $stmt->fetchColumn();

    if ($img && file_exists("../files/_albums/" . $img)) {
      unlink("../files/_albums/" . $img);
    }

    // Delete album permanently
    $stmt = $pdo->prepare("DELETE FROM photo_albums WHERE id = ?");
    $stmt->execute([$albumId]);

    $_SESSION['reopen_modal'] = $yearId;
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
      $stmt = $pdo->prepare("SELECT * FROM photo_albums WHERE year_id = ?");
    } else {
      $stmt = $pdo->prepare("SELECT * FROM photo_albums WHERE year_id = ? AND deleted_at IS NULL");
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
    $stmt = $pdo->prepare("SELECT * FROM photo_albums WHERE year_id = ?");
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
</style>
</head>

<body>

<?php include '../inc/navbar-admin.php'; ?>

<!-- MAIN -->
    <div class="row g-4 align-items-stretch">
        <div class="col-12 col-lg-12 d-flex flex-column gap-4">
            <div class="card-dashboard p-4 shadow-sm rounded-4 bg-white flex-grow-0">
            <!-- Reopen modal script -->
            <?php if (isset($_SESSION['reopen_modal'])): ?>
            <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
            document.addEventListener('DOMContentLoaded', function () {
                var modalId = 'modalYear<?= $_SESSION['reopen_modal'] ?>';
                var el = document.getElementById(modalId);
                if (el) {
                    var modal = new bootstrap.Modal(el);
                    modal.show();
                }
            });
            </script>
            <?php unset($_SESSION['reopen_modal']); ?>
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
                  <form method="post" onsubmit="return confirm('Supprimer DÉFINITIVEMENT cette année et tous ses albums ? Les fichiers images seront supprimés. Cette action est irréversible.');">
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
                        <button type="submit" name="update_year" class="btn btn-primary mt-3">Enregistrer</button>
                    </form>

                    <form method="post" onsubmit="return confirm('<?= $migrationDone ? 'Mettre cette année et tous ses albums en corbeille ?' : 'Supprimer definitivement cette annee et tous ses albums ?' ?>');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                        <button type="submit" name="delete_year" class="btn btn-outline-danger mb-4">
                          <i class="bi bi-trash3"></i> <?= $migrationDone ? 'Mettre en corbeille' : 'Supprimer' ?>
                        </button>
                    </form>

                    <h5>Albums associes (<?= count($albumsByYear[$year['id']]) ?>)</h5>
                    <div class="mb-3">
                        <?php foreach ($albumsByYear[$year['id']] as $album): ?>
                        <form method="post" enctype="multipart/form-data" class="p-3 mb-2" style="border:1px solid #f0e8eb;border-radius:8px;background:#fdf8f9">
                            <?= csrf_field() ?>
                            <input type="hidden" name="album_id" value="<?= $album['id'] ?>">
                            <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                            <div class="row g-2 align-items-end">
                              <div class="col-md-3">
                                <label class="form-label" style="font-size:12px">Titre</label>
                                <input type="text" name="album_title" class="form-control form-control-sm" value="<?= htmlspecialchars($album['album_title']) ?>">
                              </div>
                              <div class="col-md-3">
                                <label class="form-label" style="font-size:12px">Lien</label>
                                <input type="text" name="album_link" class="form-control form-control-sm" value="<?= htmlspecialchars($album['album_link']) ?>">
                              </div>
                              <div class="col-md-2">
                                <label class="form-label" style="font-size:12px">Image</label>
                                <input type="file" name="album_img" class="form-control form-control-sm">
                              </div>
                              <div class="col-md-3">
                                <label class="form-label" style="font-size:12px">Description</label>
                                <input type="text" name="album_desc" class="form-control form-control-sm" value="<?= htmlspecialchars($album['album_desc']) ?>">
                              </div>
                              <div class="col-md-1 text-end">
                                <div class="d-flex gap-1 justify-content-end">
                                  <button type="submit" name="update_album" class="btn btn-sm btn-success" title="Enregistrer"><i class="bi bi-check-lg"></i></button>
                                  <button type="submit" name="delete_album" class="btn btn-sm btn-outline-danger" title="<?= $migrationDone ? 'Corbeille' : 'Supprimer' ?>" onclick="return confirm('<?= $migrationDone ? 'Mettre en corbeille ?' : 'Supprimer ?' ?>');"><i class="bi bi-x-lg"></i></button>
                                </div>
                              </div>
                            </div>
                        </form>
                        <?php endforeach; ?>
                    </div>

                    <h6>Ajouter un nouvel album</h6>
                    <form method="post" enctype="multipart/form-data" style="border:1px solid #f0e8eb;border-radius:8px;padding:16px;background:#fff">
                        <?= csrf_field() ?>
                        <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                        <div class="row g-2 align-items-end">
                          <div class="col-md-3">
                            <label class="form-label" style="font-size:12px">Titre</label>
                            <input type="text" name="album_title" class="form-control" placeholder="Titre" required>
                          </div>
                          <div class="col-md-3">
                            <label class="form-label" style="font-size:12px">Lien</label>
                            <input type="url" name="album_link" class="form-control" placeholder="Lien">
                          </div>
                          <div class="col-md-2">
                            <label class="form-label" style="font-size:12px">Image</label>
                            <input type="file" name="album_img" class="form-control" required>
                          </div>
                          <div class="col-md-3">
                            <label class="form-label" style="font-size:12px">Description</label>
                            <input type="text" name="album_desc" class="form-control" placeholder="Description">
                          </div>
                          <div class="col-md-1">
                            <button type="submit" name="add_album" class="btn btn-primary btn-sm w-100">+</button>
                          </div>
                        </div>
                    </form>
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

<?php include '../inc/admin-footer.php'; ?>

</body>
</html>
