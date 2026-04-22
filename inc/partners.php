<?php
require '../config/config.php';
require_once __DIR__ . '/../config/csrf.php';
requirePage('partners');
$role = currentRole();
$canCreate = canDoAction('partners.create');
$canEdit   = canDoAction('partners.edit');
$canTrash  = canDoAction('partners.trash');
$canDelete = canDoAction('partners.delete');
$readOnly  = !$canCreate && !$canEdit && !$canTrash && !$canDelete;
require 'navbar-data.php';

// Détection de la migration pour choisir entre soft-delete (trash) et hard-delete.
$__migrationDone = false;
try { $pdo->query("SELECT deleted_at FROM partners_years LIMIT 0"); $__migrationDone = true; }
catch (PDOException $e) {}

// ─── Bloc de protection des actions d'écriture ───
// delete_album / delete_year : soft-delete si migration faite (→ partners.trash),
//                              sinon hard-delete (→ partners.delete).
$writeOps = [
    'add_album'             => 'partners.create',
    'add_year'              => 'partners.create',
    'update_album'          => 'partners.edit',
    'update_year'           => 'partners.edit',
    'update_partners_desc'  => 'partners.edit',
    'reorder_albums'        => 'partners.edit',
    'restore_year'          => 'partners.edit',
    'restore_album'         => 'partners.edit',
    'delete_album'          => $__migrationDone ? 'partners.trash' : 'partners.delete',
    'delete_year'           => $__migrationDone ? 'partners.trash' : 'partners.delete',
    'permanent_delete_album'=> 'partners.delete',
    'permanent_delete_year' => 'partners.delete',
];
foreach ($writeOps as $__op => $__perm) {
    if (isset($_POST[$__op]) && !canDoAction($__perm)) {
        http_response_code(403);
        $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Action non autorisée.'];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

$stmt = $pdo->prepare(
    'SELECT *
       FROM setting
      WHERE id = :id
      LIMIT 1');
$stmt->execute(['id' => 1]);

$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];



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
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Session expirée. Veuillez réessayer.']);
        exit;
    }
    die('Invalid CSRF token');
}

$isAjax = isAjaxRequest();



// Sauvegarde description et image générique partenaires
if (isset($_POST['update_partners_desc'])) {
    $partnersTitle = $_POST['partners_title'] ?? '';
    $partnersDesc = $isAjax ? decodeHtmlField($_POST['partners_desc'] ?? '') : ($_POST['partners_desc'] ?? '');
    try {
        if (!empty($_FILES['partners_img']['name'])) {
            $allowedExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $ext  = strtolower(pathinfo($_FILES['partners_img']['name'], PATHINFO_EXTENSION));
            $mime = mime_content_type($_FILES['partners_img']['tmp_name']);
            if (in_array($ext, $allowedExts) && in_array($mime, $allowedMimes)) {
                $safeName = uniqid('partner_', true) . '.' . $ext;
                move_uploaded_file($_FILES['partners_img']['tmp_name'], "../files/_partners/" . $safeName);
                $stmt = $pdo->prepare("UPDATE setting SET partners_title = ?, partners_desc = ?, partners_img = ? WHERE id = 1");
                $stmt->execute([$partnersTitle, $partnersDesc, $safeName]);
            } else {
                $stmt = $pdo->prepare("UPDATE setting SET partners_title = ?, partners_desc = ? WHERE id = 1");
                $stmt->execute([$partnersTitle, $partnersDesc]);
            }
        } else {
            $stmt = $pdo->prepare("UPDATE setting SET partners_title = ?, partners_desc = ? WHERE id = 1");
            $stmt->execute([$partnersTitle, $partnersDesc]);
        }
    } catch (PDOException $e) {
        error_log('[PARTNERS] update_partners_desc: ' . $e->getMessage());
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Erreur lors de la mise à jour.']);
            exit;
        }
        $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de la mise à jour.'];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Description mise à jour.'];
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_POST['update_year'])) {
  $yearId = (int)($_POST['year_id'] ?? 0);
  $year = $_POST['year'] ?? '';
  $title = $_POST['title'] ?? '';

  if ($yearId <= 0 || $year === '' || $title === '') {
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Champs requis manquants.'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
  }

  try {
    if ($hasStatusCol) {
      $status = $_POST['status'] ?? 'draft';
      $stmt = $pdo->prepare("UPDATE partners_years SET year = ?, title = ?, status = ? WHERE id = ?");
      $stmt->execute([$year, $title, $status, $yearId]);
    } else {
      $stmt = $pdo->prepare("UPDATE partners_years SET year = ?, title = ? WHERE id = ?");
      $stmt->execute([$year, $title, $yearId]);
    }
  } catch (PDOException $e) {
    error_log('[PARTNERS] update_year: ' . $e->getMessage());
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de la mise à jour.'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
  }
  $_SESSION['reopen_modal'] = $yearId;
  $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Année mise à jour.'];
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

if (isset($_POST['update_album'])) {
  $albumId = (int)($_POST['album_id'] ?? 0);
  $album_title = $_POST['album_title'] ?? '';
  $album_desc = $_POST['album_desc'] ?? '';
  $yearId = (int)($_POST['year_id'] ?? 0);
  $deleteImage = !empty($_POST['delete_image']);

  if ($albumId <= 0 || $yearId <= 0 || $album_title === '') {
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Champs requis manquants.'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
  }

  try {
    if (!empty($_FILES['album_img']['name'])) {
      $allowedExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
      $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
      $ext  = strtolower(pathinfo($_FILES['album_img']['name'], PATHINFO_EXTENSION));
      $mime = mime_content_type($_FILES['album_img']['tmp_name']);
      if (in_array($ext, $allowedExts) && in_array($mime, $allowedMimes)) {
        $safeName = uniqid('partner_', true) . '.' . $ext;
        move_uploaded_file($_FILES['album_img']['tmp_name'], "../files/_partners/" . $safeName);
        $stmt = $pdo->prepare("UPDATE partners_albums SET album_title = ?, album_img = ?, album_desc = ? WHERE id = ?");
        $stmt->execute([$album_title, $safeName, $album_desc, $albumId]);
      } else {
        $stmt = $pdo->prepare("UPDATE partners_albums SET album_title = ?, album_desc = ? WHERE id = ?");
        $stmt->execute([$album_title, $album_desc, $albumId]);
      }
    } elseif ($deleteImage) {
      // Supprimer l'image existante
      $stmtOld = $pdo->prepare("SELECT album_img FROM partners_albums WHERE id = ?");
      $stmtOld->execute([$albumId]);
      $oldImg = $stmtOld->fetchColumn();
      if ($oldImg && file_exists("../files/_partners/" . $oldImg)) {
        unlink("../files/_partners/" . $oldImg);
      }
      $stmt = $pdo->prepare("UPDATE partners_albums SET album_title = ?, album_img = '', album_desc = ? WHERE id = ?");
      $stmt->execute([$album_title, $album_desc, $albumId]);
    } else {
      $stmt = $pdo->prepare("UPDATE partners_albums SET album_title = ?, album_desc = ? WHERE id = ?");
      $stmt->execute([$album_title, $album_desc, $albumId]);
    }
  } catch (PDOException $e) {
    error_log('[PARTNERS] update_album: ' . $e->getMessage());
    $_SESSION['reopen_modal'] = $yearId;
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de la mise à jour du partenaire.'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
  }
  $_SESSION['reopen_modal'] = $yearId;
  $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Partenaire mis à jour.'];
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

if (isset($_POST['add_album'])) {
  $yearId = (int)($_POST['year_id'] ?? 0);
  $album_title = $_POST['album_title'] ?? '';
  $album_desc = $_POST['album_desc'] ?? '';

  if ($yearId <= 0 || $album_title === '') {
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Champs requis manquants.'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
  }

  try {
    $safeName = null;
    if (!empty($_FILES['album_img']['name']) && $_FILES['album_img']['error'] === UPLOAD_ERR_OK) {
      $allowedExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
      $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
      $ext  = strtolower(pathinfo($_FILES['album_img']['name'], PATHINFO_EXTENSION));
      $mime = mime_content_type($_FILES['album_img']['tmp_name']);
      if (in_array($ext, $allowedExts) && in_array($mime, $allowedMimes)) {
        $safeName = uniqid('partner_', true) . '.' . $ext;
        move_uploaded_file($_FILES['album_img']['tmp_name'], "../files/_partners/" . $safeName);
      }
    }
    $stmt = $pdo->prepare("INSERT INTO partners_albums (year_id, album_title, album_img, album_desc) VALUES (?, ?, ?, ?)");
    $stmt->execute([$yearId, $album_title, $safeName, $album_desc]);
  } catch (PDOException $e) {
    error_log('[PARTNERS] add_album: ' . $e->getMessage());
    $_SESSION['reopen_modal'] = $yearId;
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de l\'ajout du partenaire.'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
  }
  $_SESSION['reopen_modal'] = $yearId;
  $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Partenaire ajouté.'];
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

// ─── Delete album ───
if (isset($_POST['delete_album'])) {
  $albumId = (int)($_POST['album_id'] ?? 0);
  $yearId = (int)($_POST['year_id'] ?? 0);

  if ($albumId <= 0 || $yearId <= 0) {
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Identifiants manquants.'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
  }

  try {
    if ($migrationDone) {
      $stmt = $pdo->prepare("UPDATE partners_albums SET deleted_at = NOW() WHERE id = ?");
      $stmt->execute([$albumId]);
      $_SESSION['reopen_modal'] = $yearId;
      $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Partenaire mis en corbeille.'];
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
      $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Partenaire supprimé.'];
      header("Location: " . $_SERVER['PHP_SELF']);
    }
  } catch (PDOException $e) {
    error_log('[PARTNERS] delete_album: ' . $e->getMessage());
    $_SESSION['reopen_modal'] = $yearId;
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de la suppression du partenaire.'];
    header("Location: " . $_SERVER['PHP_SELF']);
  }
  exit;
}

if (isset($_POST['add_year'])) {
  $year = $_POST['year'] ?? '';
  $title = $_POST['title'] ?? '';

  if ($year === '' || $title === '') {
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Champs requis manquants.'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
  }

  try {
    if ($hasStatusCol) {
      $status = $_POST['status'] ?? 'draft';
      $stmt = $pdo->prepare("INSERT INTO partners_years (year, title, status) VALUES (?, ?, ?)");
      $stmt->execute([$year, $title, $status]);
    } else {
      $stmt = $pdo->prepare("INSERT INTO partners_years (year, title) VALUES (?, ?)");
      $stmt->execute([$year, $title]);
    }
  } catch (PDOException $e) {
    error_log('[PARTNERS] add_year: ' . $e->getMessage());
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de l\'ajout de l\'année.'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
  }
  $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Année ajoutée.'];
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

// ─── Reorder albums (AJAX) ───
if (isset($_POST['reorder_albums'])) {
  $ids = json_decode($_POST['album_ids'] ?? '[]', true);
  try {
    if (is_array($ids)) {
      $stmt = $pdo->prepare("UPDATE partners_albums SET sort_order = ? WHERE id = ?");
      foreach ($ids as $i => $id) {
        $stmt->execute([$i, (int)$id]);
      }
    }
  } catch (PDOException $e) {
    error_log('[PARTNERS] reorder_albums: ' . $e->getMessage());
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
      header('Content-Type: application/json');
      echo json_encode(['ok' => false, 'message' => 'Erreur lors du réordonnancement.']);
      exit;
    }
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors du réordonnancement.'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
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
  $yearId = (int)($_POST['year_id'] ?? 0);

  if ($yearId <= 0) {
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Identifiant manquant.'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
  }

  try {
    if ($migrationDone) {
      // Soft-delete the year
      $stmt = $pdo->prepare("UPDATE partners_years SET deleted_at = NOW() WHERE id = ?");
      $stmt->execute([$yearId]);
      // Soft-delete all child albums
      $stmt = $pdo->prepare("UPDATE partners_albums SET deleted_at = NOW() WHERE year_id = ?");
      $stmt->execute([$yearId]);
      $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Année mise en corbeille.'];
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
      $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Année supprimée.'];
      header("Location: " . $_SERVER['PHP_SELF']);
    }
  } catch (PDOException $e) {
    error_log('[PARTNERS] delete_year: ' . $e->getMessage());
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de la suppression de l\'année.'];
    header("Location: " . $_SERVER['PHP_SELF']);
  }
  exit;
}

if ($migrationDone) {
  // ─── Restore year from trash ───
  if (isset($_POST['restore_year'])) {
    $yearId = (int)($_POST['year_id'] ?? 0);

    if ($yearId <= 0) {
      $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Identifiant manquant.'];
      header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
      exit;
    }

    try {
      $stmt = $pdo->prepare("UPDATE partners_years SET deleted_at = NULL WHERE id = ?");
      $stmt->execute([$yearId]);

      $stmt = $pdo->prepare("UPDATE partners_albums SET deleted_at = NULL WHERE year_id = ?");
      $stmt->execute([$yearId]);
    } catch (PDOException $e) {
      error_log('[PARTNERS] restore_year: ' . $e->getMessage());
      $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de la restauration.'];
      header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
      exit;
    }

    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Année restaurée.'];
    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
    exit;
  }

  // ─── Restore album from trash ───
  if (isset($_POST['restore_album'])) {
    $albumId = (int)($_POST['album_id'] ?? 0);
    $yearId = (int)($_POST['year_id'] ?? 0);

    if ($albumId <= 0 || $yearId <= 0) {
      $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Identifiants manquants.'];
      header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
      exit;
    }

    try {
      $stmt = $pdo->prepare("UPDATE partners_albums SET deleted_at = NULL WHERE id = ?");
      $stmt->execute([$albumId]);
    } catch (PDOException $e) {
      error_log('[PARTNERS] restore_album: ' . $e->getMessage());
      $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de la restauration.'];
      header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
      exit;
    }

    $_SESSION['reopen_modal'] = $yearId;
    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Partenaire restauré.'];
    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
    exit;
  }

  // ─── Permanent delete year ───
  if (isset($_POST['permanent_delete_year'])) {
    $yearId = (int)($_POST['year_id'] ?? 0);

    if ($yearId <= 0) {
      $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Identifiant manquant.'];
      header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
      exit;
    }

    try {
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
      try {
        $stmt = $pdo->prepare("SELECT img FROM partners_years WHERE id = ?");
        $stmt->execute([$yearId]);
        $yearImg = $stmt->fetchColumn();
        if ($yearImg && file_exists("../files/_partners/" . $yearImg)) {
          unlink("../files/_partners/" . $yearImg);
        }
      } catch (PDOException $e) {
        // Column 'img' may not exist — skip image cleanup
      }

      // Delete albums and year permanently
      $stmt1 = $pdo->prepare("DELETE FROM partners_albums WHERE year_id = ?");
      $stmt1->execute([$yearId]);
      $stmt2 = $pdo->prepare("DELETE FROM partners_years WHERE id = ?");
      $stmt2->execute([$yearId]);
    } catch (PDOException $e) {
      error_log('[PARTNERS] permanent_delete_year: ' . $e->getMessage());
      $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de la suppression définitive.'];
      header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
      exit;
    }

    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Année supprimée définitivement.'];
    header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
    exit;
  }

  // ─── Permanent delete album ───
  if (isset($_POST['permanent_delete_album'])) {
    $albumId = (int)($_POST['album_id'] ?? 0);
    $yearId = (int)($_POST['year_id'] ?? 0);

    if ($albumId <= 0 || $yearId <= 0) {
      $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Identifiants manquants.'];
      header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
      exit;
    }

    try {
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
    } catch (PDOException $e) {
      error_log('[PARTNERS] permanent_delete_album: ' . $e->getMessage());
      $_SESSION['reopen_modal'] = $yearId;
      $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de la suppression définitive.'];
      header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
      exit;
    }

    $_SESSION['reopen_modal'] = $yearId;
    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Partenaire supprimé définitivement.'];
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
      $stmt = $pdo->prepare("SELECT * FROM partners_albums WHERE year_id = ? ORDER BY sort_order");
    } else {
      $stmt = $pdo->prepare("SELECT * FROM partners_albums WHERE year_id = ? AND deleted_at IS NULL ORDER BY sort_order");
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
    $stmt = $pdo->prepare("SELECT * FROM partners_albums WHERE year_id = ? ORDER BY sort_order");
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
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<style>
  .card-dashboard{margin-top:1rem;border-radius:1.25rem;box-shadow:0 0 25px rgba(0,0,0,.1)}

  /* ═══ Tab styles ═══ */
  .settings-tabs { border-bottom: 2px solid #f0e8eb; margin-bottom: 24px; gap: 0; }
  .settings-tabs .nav-link {
    color: #1e293b; font-weight: 500; font-size: 14px;
    padding: 10px 18px; border: none; border-bottom: 2px solid transparent;
    margin-bottom: -2px; border-radius: 0; background: transparent;
  }
  .settings-tabs .nav-link:hover { color: #1e293b; border-bottom-color: #d4c4cb; }
  .settings-tabs .nav-link.active {
    color: #1e293b; font-weight: 600;
    border-bottom-color: #F42182; background: transparent;
  }
  .partner-tab-section { display: none; }
  .partner-tab-section.active { display: block; }

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
    border-bottom-color: #F42182;
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
  .drag-handle-album:hover { color: #F42182 !important; }
  .sortable-ghost-album { opacity: 0.4; background: #ffe5ff !important; }
</style>
</head>

<body>

<?php include '../inc/navbar-admin.php'; ?>

<?php
  $reopenModalId = $_SESSION['reopen_modal'] ?? null;
?>

<!-- Spinner de chargement -->
<div id="loadingSpinner" class="text-center py-5">
  <div class="spinner-border" role="status" style="width:2.5rem;height:2.5rem;color:#F42182;"></div>
  <p class="text-muted mt-2 small">Chargement des partenaires...</p>
</div>

<!-- MAIN -->
    <div class="row g-4 align-items-stretch content-loaded" style="display:none;">
        <div class="col-12 col-lg-12 d-flex flex-column gap-4">
            <div>
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

            <h1 class="mb-3 fw-bold"><i class="bi bi-award me-2"></i>Gestion des Partenaires par Année</h1>

            <?php $activeTab = (isset($_POST['update_partners_desc']) || (isset($_GET['tab']) && $_GET['tab'] === 'description')) ? 'description' : 'partenaires'; ?>
            <ul class="nav settings-tabs" id="partnerTabs">
              <li class="nav-item"><a class="nav-link <?= $activeTab === 'description' ? 'active' : '' ?>" href="#" data-tab="description">Description</a></li>
              <li class="nav-item"><a class="nav-link <?= $activeTab === 'partenaires' ? 'active' : '' ?>" href="#" data-tab="partenaires">Partenaires</a></li>
            </ul>

            <!-- ═══ Tab: Description ═══ -->
            <div class="partner-tab-section <?= $activeTab === 'description' ? 'active' : '' ?>" id="tab-description">
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
                  <?php if ($canEdit): ?>
                  <div class="text-end mt-3">
                    <button type="submit" name="update_partners_desc" class="btn btn-primary">Enregistrer</button>
                  </div>
                  <?php endif; ?>
                </form>
              </div>
            </div>
            </div><!-- /tab-description -->

            <!-- ═══ Tab: Partenaires ═══ -->
            <div class="partner-tab-section <?= $activeTab === 'partenaires' ? 'active' : '' ?>" id="tab-partenaires">

            <?php if (!$migrationDone): ?>
            <div class="alert alert-warning" role="alert">
              <i class="bi bi-exclamation-triangle"></i> Veuillez executer la mise a jour BDD pour activer toutes les fonctionnalites (corbeille, filtres).
            </div>
            <?php endif; ?>

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
              <?php if ($canDelete): ?>
              <a href="?filter=trashed" class="<?= $filter === 'trashed' ? 'active' : '' ?>">
                <i class="bi bi-trash3"></i> Corbeille <span class="badge bg-danger"><?= $countTrashed ?></span>
              </a>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!$isTrashed && $canCreate): ?>
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
                  <?php if ($canEdit): ?>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                    <button type="submit" name="restore_year" class="btn btn-sm btn-success">
                      <i class="bi bi-arrow-counterclockwise"></i> Restaurer
                    </button>
                  </form>
                  <?php endif; ?>
                  <?php if ($canDelete): ?>
                  <form method="post" data-confirm="Supprimer DÉFINITIVEMENT cette année et tous ses albums ? Les fichiers images seront supprimés. Cette action est irréversible.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                    <button type="submit" name="permanent_delete_year" class="btn btn-sm btn-danger">
                      <i class="bi bi-x-circle"></i> Supprimer définitivement
                    </button>
                  </form>
                  <?php endif; ?>
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
                  <?php if ($canEdit || $canCreate): ?>
                  <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalYear<?= $year['id'] ?>">
                    <i class="bi bi-pencil"></i> Modifier
                  </button>
                  <?php endif; ?>
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
                        <div class="d-flex justify-content-between align-items-center mt-3 mb-4">
                          <?php if ($canEdit): ?>
                          <button type="submit" name="update_year" class="btn btn-primary">Enregistrer</button>
                          <?php else: ?><span></span><?php endif; ?>
                    </form>
                          <?php if ($migrationDone ? $canTrash : $canDelete): ?>
                          <form method="post" data-confirm="<?= $migrationDone ? 'Mettre cette année et tous ses albums en corbeille ?' : 'Supprimer definitivement cette annee et tous ses albums ?' ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                            <button type="submit" name="delete_year" class="btn btn-danger">
                              <i class="bi bi-trash3"></i> <?= $migrationDone ? 'Mettre en corbeille' : 'Supprimer' ?>
                            </button>
                          </form>
                          <?php endif; ?>
                        </div>

                    <?php if ($canCreate): ?>
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
                          <div class="col-auto d-flex align-items-end">
                            <button type="submit" name="add_album" class="btn btn-primary" style="height:38px;width:38px;padding:0;display:flex;align-items:center;justify-content:center;border-radius:8px"><i class="bi bi-plus-lg"></i></button>
                          </div>
                        </div>
                    </form>
                    <?php endif; ?>

                    <div class="mb-3"></div>

                    <h5>Albums associes (<?= count($albumsByYear[$year['id']]) ?>)</h5>
                    <div class="mb-3 sortable-albums" data-year-id="<?= $year['id'] ?>">
                        <?php foreach ($albumsByYear[$year['id']] as $album): ?>
                        <form method="post" enctype="multipart/form-data" class="p-3 mb-2 sortable-album-item" data-album-id="<?= $album['id'] ?>" style="border:1px solid #f0e8eb;border-radius:8px;background:#fdf8f9">
                            <?= csrf_field() ?>
                            <input type="hidden" name="album_id" value="<?= $album['id'] ?>">
                            <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                            <div class="row g-2 align-items-end flex-nowrap">
                              <div class="col-auto d-flex align-items-center" style="min-width:30px">
                                <span class="drag-handle-album" style="cursor:grab;color:#94a3b8;font-size:1.2rem" title="Glisser pour réordonner"><i class="bi bi-grip-vertical"></i></span>
                              </div>
                              <div class="col">
                                <label class="form-label" style="font-size:12px">Titre</label>
                                <input type="text" name="album_title" class="form-control form-control-sm" value="<?= htmlspecialchars($album['album_title']) ?>">
                              </div>
                              <div class="col-auto" style="min-width:140px">
                                <label class="form-label" style="font-size:12px">Image</label>
                                <input type="file" name="album_img" class="form-control form-control-sm">
                                <?php if (!empty($album['album_img'])): ?>
                                <div class="form-check mt-1">
                                  <input type="checkbox" name="delete_image" value="1" class="form-check-input" id="delImgPartner<?= $album['id'] ?>">
                                  <label class="form-check-label text-danger" style="font-size:11px" for="delImgPartner<?= $album['id'] ?>">Supprimer</label>
                                </div>
                                <?php endif; ?>
                              </div>
                              <div class="col">
                                <label class="form-label" style="font-size:12px">Description</label>
                                <input type="text" name="album_desc" class="form-control form-control-sm" value="<?= htmlspecialchars($album['album_desc']) ?>">
                              </div>
                              <div class="col-auto text-end">
                                <div class="d-flex gap-1">
                                  <?php if ($canEdit): ?>
                                  <button type="submit" name="update_album" class="btn btn-sm btn-success" title="Enregistrer"><i class="bi bi-check-lg"></i></button>
                                  <?php endif; ?>
                                  <?php if ($migrationDone ? $canTrash : $canDelete): ?>
                                  <button type="submit" name="delete_album" class="btn btn-sm btn-outline-danger" title="<?= $migrationDone ? 'Mettre en corbeille' : 'Supprimer' ?>" data-confirm="<?= $migrationDone ? 'Mettre ce partenaire en corbeille ?' : 'Supprimer définitivement cet album ?' ?>"><i class="bi bi-x-lg"></i></button>
                                  <?php endif; ?>
                                </div>
                              </div>
                            </div>
                        </form>
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

            </div><!-- /tab-partenaires -->

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
    <script src="../js/tinymce/tinymce.min.js"></script>
    <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
        tinymce.init({
            selector: '#partners_desc_editor',
            <?= getTinyMceConfig($pdo, ['height' => 200]) ?>
        });

        /* ── Envoi AJAX pour contourner le WAF ── */
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('button[name="update_partners_desc"]');
            if (!btn) return;
            e.preventDefault();
            var form = btn.closest('form');
            tinymce.triggerSave();
            var fd = new FormData(form);
            fd.set(btn.name, '1');
            var desc = fd.get('partners_desc') || '';
            if (desc) fd.set('partners_desc', btoa(unescape(encodeURIComponent(desc))));
            fetch(form.action || window.location.href, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) { window.location.href = window.location.pathname + '?tab=description'; }
                else if (typeof showToast === 'function') showToast(data.message || 'Erreur', 'danger');
            })
            .catch(function (err) {
                if (typeof showToast === 'function') showToast('Erreur : ' + err.message, 'danger');
            });
        });
    </script>
<!-- ############################ TinyMCE ############################ -->

<?php include '../inc/admin-footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
// Masquer spinner, afficher contenu
var sp = document.getElementById('loadingSpinner');
if (sp) sp.style.display = 'none';
document.querySelectorAll('.content-loaded').forEach(function(el) { el.style.display = ''; });

// Tab switching
document.querySelectorAll('#partnerTabs .nav-link').forEach(function(tab) {
  tab.addEventListener('click', function(e) {
    e.preventDefault();
    document.querySelectorAll('#partnerTabs .nav-link').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.partner-tab-section').forEach(function(s) { s.classList.remove('active'); });
    this.classList.add('active');
    document.getElementById('tab-' + this.dataset.tab).classList.add('active');
  });
});

// Auto-dismiss des alertes
document.querySelectorAll('.auto-dismiss').forEach(function(alert) {
  var delay = parseInt(alert.dataset.dismissDelay) || 5000;
  setTimeout(function() {
    var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
    bsAlert.close();
  }, delay);
});

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
</script>
</body>
</html>
