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

$partners_desc = $data['partners_desc'] ?? '';
$partners_title = $data['partners_title'] ?? '';
$partners_img = $data['partners_img'] ?? '';

// Check if migration has been applied (deleted_at column on partners_years)
$migrationDone = false;
$hasStatusCol = false;
try {
    $pdo->query("SELECT deleted_at FROM partners_years LIMIT 0");
    $migrationDone = true;
} catch (PDOException $e) {}
try {
    $pdo->query("SELECT status FROM partners_years LIMIT 0");
    $hasStatusCol = true;
} catch (PDOException $e) {}

// ─── CSRF check for all POST actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(403);
    die('Invalid CSRF token');
}

// Sauvegarde description et image générique partenaires
if (isset($_POST['update_partners_desc'])) {
    $partnersTitle = $_POST['partners_title'] ?? '';
    if (!empty($_FILES['partners_img']['name'])) {
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo($_FILES['partners_img']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExts)) {
            $safeName = uniqid('partner_', true) . '.' . $ext;
            move_uploaded_file($_FILES['partners_img']['tmp_name'], "../files/_partners/" . $safeName);
            $stmt = $pdo->prepare("UPDATE setting SET partners_title = ?, partners_desc = ?, partners_img = ? WHERE id = 1");
            $stmt->execute([$partnersTitle, $_POST['partners_desc'], $safeName]);
        } else {
            $stmt = $pdo->prepare("UPDATE setting SET partners_title = ?, partners_desc = ? WHERE id = 1");
            $stmt->execute([$partnersTitle, $_POST['partners_desc']]);
        }
    } else {
        $stmt = $pdo->prepare("UPDATE setting SET partners_title = ?, partners_desc = ? WHERE id = 1");
        $stmt->execute([$partnersTitle, $_POST['partners_desc']]);
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_POST['update_year'])) {
  $yearId = $_POST['year_id'];
  $year = $_POST['year'];
  $title = $_POST['title'];

  if ($hasStatusCol) {
    $status = $_POST['status'] ?? 'draft';
    $stmt = $pdo->prepare("UPDATE partners_years SET year = ?, title = ?, status = ? WHERE id = ?");
    $stmt->execute([$year, $title, $status, $yearId]);
  } else {
    $stmt = $pdo->prepare("UPDATE partners_years SET year = ?, title = ? WHERE id = ?");
    $stmt->execute([$year, $title, $yearId]);
  }
  $_SESSION['reopen_modal'] = $yearId;
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

if (isset($_POST['update_album'])) {
  $albumId = $_POST['album_id'];
  $album_title = $_POST['album_title'];
  $album_desc = $_POST['album_desc'];
  $yearId = $_POST['year_id'];

  if (!empty($_FILES['album_img']['name'])) {
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($_FILES['album_img']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, $allowedExts)) {
      $safeName = uniqid('partner_', true) . '.' . $ext;
      move_uploaded_file($_FILES['album_img']['tmp_name'], "../files/_partners/" . $safeName);
      $stmt = $pdo->prepare("UPDATE partners_albums SET album_title = ?, album_img = ?, album_desc = ? WHERE id = ?");
      $stmt->execute([$album_title, $safeName, $album_desc, $albumId]);
    } else {
      $stmt = $pdo->prepare("UPDATE partners_albums SET album_title = ?, album_desc = ? WHERE id = ?");
      $stmt->execute([$album_title, $album_desc, $albumId]);
    }
  } else {
    $stmt = $pdo->prepare("UPDATE partners_albums SET album_title = ?, album_desc = ? WHERE id = ?");
    $stmt->execute([$album_title, $album_desc, $albumId]);
  }
  $_SESSION['reopen_modal'] = $yearId;
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

if (isset($_POST['add_album'])) {
  $yearId = $_POST['year_id'];
  $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
  $ext = strtolower(pathinfo($_FILES['album_img']['name'], PATHINFO_EXTENSION));
  if (in_array($ext, $allowedExts)) {
    $safeName = uniqid('partner_', true) . '.' . $ext;
    move_uploaded_file($_FILES['album_img']['tmp_name'], "../files/_partners/" . $safeName);
    $stmt = $pdo->prepare("INSERT INTO partners_albums (year_id, album_title, album_img, album_desc) VALUES (?, ?, ?, ?)");
    $stmt->execute([
      $yearId,
      $_POST['album_title'],
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
    $stmt = $pdo->prepare("UPDATE partners_albums SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$albumId]);
    $_SESSION['reopen_modal'] = $yearId;
    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=" . ($_GET['filter'] ?? ''));
  } else {
    // Hard delete (old behavior)
    $stmt = $pdo->prepare("SELECT album_img FROM partners_albums WHERE id = ?");
    $stmt->execute([$albumId]);
    $img = $stmt->fetchColumn();
    if ($img && file_exists("../files/_partners/" . $img)) {
      unlink("../files/_partners/" . $img);
    }
    $stmt = $pdo->prepare("DELETE FROM partners_albums WHERE id = ?");
    $stmt->execute([$albumId]);
    $_SESSION['reopen_modal'] = $yearId;
    header("Location: " . $_SERVER['PHP_SELF']);
  }
  exit;
}

if (isset($_POST['add_year'])) {
  if ($hasStatusCol) {
    $status = $_POST['status'] ?? 'draft';
    $stmt = $pdo->prepare("INSERT INTO partners_years (year, title, status) VALUES (?, ?, ?)");
    $stmt->execute([$_POST['year'], $_POST['title'], $status]);
  } else {
    $stmt = $pdo->prepare("INSERT INTO partners_years (year, title) VALUES (?, ?)");
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
    $stmt = $pdo->prepare("UPDATE partners_years SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$yearId]);
    // Soft-delete all child albums
    $stmt = $pdo->prepare("UPDATE partners_albums SET deleted_at = NOW() WHERE year_id = ?");
    $stmt->execute([$yearId]);
    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=" . ($_GET['filter'] ?? ''));
  } else {
    // Hard delete (old behavior)
    $stmt = $pdo->prepare("SELECT album_img FROM partners_albums WHERE year_id = ?");
    $stmt->execute([$yearId]);
    $albumImgs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($albumImgs as $img) {
      if ($img && file_exists("../files/_partners/" . $img)) {
        unlink("../files/_partners/" . $img);
      }
    }
    $stmt1 = $pdo->prepare("DELETE FROM partners_albums WHERE year_id = ?");
    $stmt1->execute([$yearId]);
    $stmt2 = $pdo->prepare("DELETE FROM partners_years WHERE id = ?");
    $stmt2->execute([$yearId]);
    header("Location: " . $_SERVER['PHP_SELF']);
  }
  exit;
}

if ($migrationDone) {
  // ─── Restore year from trash ───
  if (isset($_POST['restore_year'])) {
    $yearId = $_POST['year_id'];

    $stmt = $pdo->prepare("UPDATE partners_years SET deleted_at = NULL WHERE id = ?");
    $stmt->execute([$yearId]);

    $stmt = $pdo->prepare("UPDATE partners_albums SET deleted_at = NULL WHERE year_id = ?");
    $stmt->execute([$yearId]);

    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
    exit;
  }

  // ─── Restore album from trash ───
  if (isset($_POST['restore_album'])) {
    $albumId = $_POST['album_id'];
    $yearId = $_POST['year_id'];

    $stmt = $pdo->prepare("UPDATE partners_albums SET deleted_at = NULL WHERE id = ?");
    $stmt->execute([$albumId]);

    $_SESSION['reopen_modal'] = $yearId;
    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
    exit;
  }

  // ─── Permanent delete year ───
  if (isset($_POST['permanent_delete_year'])) {
    $yearId = $_POST['year_id'];

    // Delete image files for all albums
    $stmt = $pdo->prepare("SELECT album_img FROM partners_albums WHERE year_id = ?");
    $stmt->execute([$yearId]);
    $albumImgs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($albumImgs as $img) {
      if ($img && file_exists("../files/_partners/" . $img)) {
        unlink("../files/_partners/" . $img);
      }
    }

    // Delete year image if exists
    $stmt = $pdo->prepare("SELECT img FROM partners_years WHERE id = ?");
    $stmt->execute([$yearId]);
    $yearImg = $stmt->fetchColumn();
    if ($yearImg && file_exists("../files/_partners/" . $yearImg)) {
      unlink("../files/_partners/" . $yearImg);
    }

    // Delete albums and year permanently
    $stmt1 = $pdo->prepare("DELETE FROM partners_albums WHERE year_id = ?");
    $stmt1->execute([$yearId]);
    $stmt2 = $pdo->prepare("DELETE FROM partners_years WHERE id = ?");
    $stmt2->execute([$yearId]);

    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
    exit;
  }

  // ─── Permanent delete album ───
  if (isset($_POST['permanent_delete_album'])) {
    $albumId = $_POST['album_id'];
    $yearId = $_POST['year_id'];

    // Delete image file
    $stmt = $pdo->prepare("SELECT album_img FROM partners_albums WHERE id = ?");
    $stmt->execute([$albumId]);
    $img = $stmt->fetchColumn();

    if ($img && file_exists("../files/_partners/" . $img)) {
      unlink("../files/_partners/" . $img);
    }

    // Delete album permanently
    $stmt = $pdo->prepare("DELETE FROM partners_albums WHERE id = ?");
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
    $years = $pdo->query("SELECT * FROM partners_years WHERE deleted_at IS NOT NULL ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
  } elseif ($filter === 'published' && $hasStatusCol) {
    $years = $pdo->query("SELECT * FROM partners_years WHERE deleted_at IS NULL AND status = 'published' ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
  } elseif ($filter === 'draft' && $hasStatusCol) {
    $years = $pdo->query("SELECT * FROM partners_years WHERE deleted_at IS NULL AND status = 'draft' ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $years = $pdo->query("SELECT * FROM partners_years WHERE deleted_at IS NULL ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
  }

  $albumsByYear = [];
  foreach ($years as $y) {
    if ($isTrashed) {
      $stmt = $pdo->prepare("SELECT * FROM partners_albums WHERE year_id = ?");
    } else {
      $stmt = $pdo->prepare("SELECT * FROM partners_albums WHERE year_id = ? AND deleted_at IS NULL");
    }
    $stmt->execute([$y['id']]);
    $albumsByYear[$y['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  // Counts for tab badges
  $countAll     = $pdo->query("SELECT COUNT(*) FROM partners_years WHERE deleted_at IS NULL")->fetchColumn();
  $countTrashed = $pdo->query("SELECT COUNT(*) FROM partners_years WHERE deleted_at IS NOT NULL")->fetchColumn();
  if ($hasStatusCol) {
    $countPublished = $pdo->query("SELECT COUNT(*) FROM partners_years WHERE deleted_at IS NULL AND status = 'published'")->fetchColumn();
    $countDraft     = $pdo->query("SELECT COUNT(*) FROM partners_years WHERE deleted_at IS NULL AND status = 'draft'")->fetchColumn();
  } else {
    $countPublished = 0;
    $countDraft = 0;
  }
} else {
  $filter = '';
  $years = $pdo->query("SELECT * FROM partners_years ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);

  $albumsByYear = [];
  foreach ($years as $y) {
    $stmt = $pdo->prepare("SELECT * FROM partners_albums WHERE year_id = ?");
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
<title>Partenaires</title>

<!-- ─── CSS ─── -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-KE9wPQ6…(clé-cdn)…" crossorigin="anonymous"></script>
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
            <script>
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

            <h1 class="mb-3 fw-bold">Gestion des Partenaires par Année</h1>

            <?php if (!$migrationDone): ?>
            <div class="alert alert-warning" role="alert">
              <i class="bi bi-exclamation-triangle"></i> Veuillez executer la mise a jour BDD pour activer toutes les fonctionnalites (corbeille, filtres).
            </div>
            <?php endif; ?>

            <!-- Zone générique : description et image affichées sur la page Partenaires -->
            <div class="card mb-4">
              <div class="card-header"><strong>Description generique de la page Partenaires</strong></div>
              <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                  <?= csrf_field() ?>
                  <div class="row g-3">
                    <div class="col-md-4">
                      <label class="form-label">Image generique</label>
                      <input type="file" name="partners_img" class="form-control" accept="image/*">
                      <?php if (!empty($partners_img)): ?>
                        <div class="mt-2">
                          <img src="../files/_partners/<?= htmlspecialchars($partners_img) ?>" class="img-fluid rounded" style="max-height:200px;">
                        </div>
                      <?php endif; ?>
                    </div>
                    <div class="col-md-8">
                      <label class="form-label">Titre</label>
                      <input type="text" name="partners_title" class="form-control mb-3" value="<?= htmlspecialchars($partners_title) ?>" placeholder="Titre de la page partenaires">
                      <label class="form-label">Description</label>
                      <textarea class="form-control" id="partners_desc_editor" name="partners_desc" rows="10"><?= htmlspecialchars($partners_desc) ?></textarea>
                    </div>
                  </div>
                  <div class="text-end mt-3">
                    <button type="submit" name="update_partners_desc" class="btn btn-primary">Enregistrer</button>
                  </div>
                </form>
              </div>
            </div>

            <?php if ($migrationDone): ?>
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
                <i class="bi bi-people" style="font-size:3rem;"></i>
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
                  <a href="../public/partenaires.php?preview_year=<?= $year['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Aperçu">
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
                          <div class="<?= $hasStatusCol ? 'col-md-4' : 'col-md-6' ?>">
                              <label class="form-label">Année</label>
                              <input type="number" name="year" class="form-control" value="<?= htmlspecialchars($year['year']) ?>">
                          </div>
                          <div class="<?= $hasStatusCol ? 'col-md-4' : 'col-md-6' ?>">
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
                              <div class="col-md-4">
                                <label class="form-label" style="font-size:12px">Titre</label>
                                <input type="text" name="album_title" class="form-control form-control-sm" value="<?= htmlspecialchars($album['album_title']) ?>">
                              </div>
                              <div class="col-md-3">
                                <label class="form-label" style="font-size:12px">Image</label>
                                <input type="file" name="album_img" class="form-control form-control-sm">
                              </div>
                              <div class="col-md-4">
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

                    <h6>Ajouter un partenaire</h6>
                    <form method="post" enctype="multipart/form-data" style="border:1px solid #f0e8eb;border-radius:8px;padding:16px;background:#fff">
                        <?= csrf_field() ?>
                        <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                        <div class="row g-2 align-items-end">
                          <div class="col-md-4">
                            <label class="form-label" style="font-size:12px">Titre</label>
                            <input type="text" name="album_title" class="form-control" placeholder="Titre" required>
                          </div>
                          <div class="col-md-3">
                            <label class="form-label" style="font-size:12px">Image</label>
                            <input type="file" name="album_img" class="form-control" required>
                          </div>
                          <div class="col-md-4">
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
                <form method="post" class="modal-body row g-3">
                    <?= csrf_field() ?>
                    <div class="<?= $hasStatusCol ? 'col-md-4' : 'col-md-6' ?>">
                    <label class="form-label">Année</label>
                    <input type="number" name="year" class="form-control" required>
                    </div>
                    <div class="<?= $hasStatusCol ? 'col-md-4' : 'col-md-6' ?>">
                    <label class="form-label">Titre</label>
                    <input type="text" name="title" class="form-control" required>
                    </div>
                    <?php if ($hasStatusCol): ?>
                    <div class="col-md-4">
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

<!-- ############################ TinyMCE ############################ -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .card-dashboard {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
        }
        .tox-tinymce {
            border-radius: 0.375rem !important;
        }
    </style>
    <script src="https://cdn.tiny.cloud/1/ocg6h1zh0bqfzq51xcl7ht600996lxdjpymxlculzjx5q3bd/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
        tinymce.init({
            selector: '#partners_desc_editor',
            plugins: 'anchor autolink charmap codesample emoticons image link lists media searchreplace table visualblocks wordcount code',
            toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat | code',
            height: 200,
            menubar: false,
            branding: false,
            content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; }',

            // Configuration des couleurs
            color_map: [
                "000000", "Noir",
                "993300", "Marron foncé",
                "333300", "Vert foncé",
                "003300", "Vert sombre",
                "003366", "Bleu marine",
                "000080", "Bleu",
                "333399", "Indigo",
                "333333", "Gris très foncé",
                "800000", "Marron",
                "FF6600", "Orange",
                "808000", "Olive",
                "008000", "Vert",
                "008080", "Sarcelle",
                "0000FF", "Bleu",
                "666699", "Gris bleu",
                "808080", "Gris",
                "FF0000", "Rouge",
                "FF9900", "Ambre",
                "99CC00", "Vert jaune",
                "339966", "Vert mer",
                "33CCCC", "Turquoise",
                "3366FF", "Bleu royal",
                "800080", "Violet",
                "999999", "Gris moyen",
                "FF00FF", "Magenta",
                "FFCC00", "Or",
                "FFFF00", "Jaune",
                "00FF00", "Lime",
                "00FFFF", "Cyan",
                "00CCFF", "Bleu ciel",
                "993366", "Rouge brun",
                "FFFFFF", "Blanc",
                "FF99CC", "Rose",
                "FFCC99", "Pêche",
                "FFFF99", "Jaune clair",
                "CCFFCC", "Vert clair",
                "CCFFFF", "Cyan clair",
                "99CCFF", "Bleu clair",
                "CC99FF", "Prune"
            ],

            // Permettre tous les éléments HTML
            extended_valid_elements: '*[*]',

            // Configuration du mode code
            toolbar_mode: 'sliding'
        });
    </script>
<!-- ############################ TinyMCE ############################ -->

<?php include '../inc/admin-footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
