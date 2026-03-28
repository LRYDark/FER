<?php
require '../config/config.php';
require_once __DIR__ . '/../config/csrf.php';
requireRole(['admin']);
$role = currentRole();

$stmt = $pdo->prepare(
    'SELECT *
       FROM setting
      WHERE id = :id
      LIMIT 1');
$stmt->execute(['id' => 1]);

$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$footer= $data['footer'] ?? '';

// Charger les données pour la navbar
require 'navbar-data.php';

// Check if migration has been applied (status & deleted_at columns)
$migrationDone = false;
try {
    $pdo->query("SELECT deleted_at, status FROM news LIMIT 0");
    $migrationDone = true;
} catch (PDOException $e) {}

// ─── CSRF check for all POST actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(403);
    die('Invalid CSRF token');
}

// ─── Add news ───
if (isset($_POST['add_news'])) {
    $title = $_POST['title_article'];
    $desc = $_POST['desc_article'];
    $imgName = '';

    if (!empty($_FILES['img_article']['name']) && $_FILES['img_article']['error'] === UPLOAD_ERR_OK) {
        $allowedExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        // 🔒 [FIX-04] Validation MIME réelle via magic bytes, pas seulement l'extension (CWE-434)
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        // 🔒 [FIX-UPLOAD-SIZE] Limite taille fichier à 5 Mo (CWE-400)
        $ext = strtolower(pathinfo($_FILES['img_article']['name'], PATHINFO_EXTENSION));
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($_FILES['img_article']['tmp_name']);
        if ($_FILES['img_article']['size'] <= 5 * 1024 * 1024
            && in_array($ext, $allowedExts) && in_array($mimeType, $allowedMimes)) {
            $imgName = uniqid('news_', true) . '.' . $ext;
            move_uploaded_file($_FILES['img_article']['tmp_name'], "../files/_news/" . $imgName);
        }
    }

    if ($migrationDone) {
        $status = isset($_POST['status']) && in_array($_POST['status'], ['published', 'draft']) ? $_POST['status'] : 'draft';
        $stmt = $pdo->prepare("INSERT INTO news (img_article, title_article, desc_article, date_publication, `like`, `dislike`, status) VALUES (?, ?, ?, NOW(), 0, 0, ?)");
        $stmt->execute([$imgName, $title, $desc, $status]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO news (img_article, title_article, desc_article, date_publication, `like`, `dislike`) VALUES (?, ?, ?, NOW(), 0, 0)");
        $stmt->execute([$imgName, $title, $desc]);
    }
    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Article ajouté avec succès.'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ─── Update news ───
if (isset($_POST['update_news'])) {
    $id = $_POST['news_id'];
    $title = $_POST['title_article'];
    $desc = $_POST['desc_article'];

    $allowedExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    // 🔒 [FIX-04] Validation MIME réelle via magic bytes, pas seulement l'extension (CWE-434)
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    if ($migrationDone) {
        $status = isset($_POST['status']) && in_array($_POST['status'], ['published', 'draft']) ? $_POST['status'] : 'draft';
        if (!empty($_FILES['img_article']['name']) && $_FILES['img_article']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['img_article']['name'], PATHINFO_EXTENSION));
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($_FILES['img_article']['tmp_name']);
            // 🔒 [FIX-UPLOAD-SIZE] Limite taille fichier à 5 Mo (CWE-400)
            if ($_FILES['img_article']['size'] <= 5 * 1024 * 1024
                && in_array($ext, $allowedExts) && in_array($mimeType, $allowedMimes)) {
                $safeName = uniqid('news_', true) . '.' . $ext;
                move_uploaded_file($_FILES['img_article']['tmp_name'], "../files/_news/" . $safeName);
                $stmt = $pdo->prepare("UPDATE news SET img_article = ?, title_article = ?, desc_article = ?, status = ? WHERE id = ?");
                $stmt->execute([$safeName, $title, $desc, $status, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE news SET title_article = ?, desc_article = ?, status = ? WHERE id = ?");
                $stmt->execute([$title, $desc, $status, $id]);
            }
        } else {
            $stmt = $pdo->prepare("UPDATE news SET title_article = ?, desc_article = ?, status = ? WHERE id = ?");
            $stmt->execute([$title, $desc, $status, $id]);
        }
    } else {
        if (!empty($_FILES['img_article']['name']) && $_FILES['img_article']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['img_article']['name'], PATHINFO_EXTENSION));
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($_FILES['img_article']['tmp_name']);
            // 🔒 [FIX-UPLOAD-SIZE] Limite taille fichier à 5 Mo (CWE-400)
            if ($_FILES['img_article']['size'] <= 5 * 1024 * 1024
                && in_array($ext, $allowedExts) && in_array($mimeType, $allowedMimes)) {
                $safeName = uniqid('news_', true) . '.' . $ext;
                move_uploaded_file($_FILES['img_article']['tmp_name'], "../files/_news/" . $safeName);
                $stmt = $pdo->prepare("UPDATE news SET img_article = ?, title_article = ?, desc_article = ? WHERE id = ?");
                $stmt->execute([$safeName, $title, $desc, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE news SET title_article = ?, desc_article = ? WHERE id = ?");
                $stmt->execute([$title, $desc, $id]);
            }
        } else {
            $stmt = $pdo->prepare("UPDATE news SET title_article = ?, desc_article = ? WHERE id = ?");
            $stmt->execute([$title, $desc, $id]);
        }
    }

    // Rediriger vers la même page/filtre et rouvrir le modal
    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Article mis à jour avec succès.'];
    $_SESSION['reopen_news_modal'] = $id;
    $qs = http_build_query(array_filter([
        'filter' => $_GET['filter'] ?? '',
        'page'   => $_GET['page'] ?? '',
        'q'      => $_GET['q'] ?? '',
    ], fn($v) => $v !== ''));
    header("Location: " . $_SERVER['PHP_SELF'] . ($qs ? "?$qs" : ''));
    exit;
}

// ─── Delete news ───
if (isset($_POST['delete_news'])) {
    $id = $_POST['news_id'];
    if ($migrationDone) {
        // Soft delete (move to trash)
        $stmt = $pdo->prepare("UPDATE news SET deleted_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Article mis en corbeille.'];
        header("Location: " . $_SERVER['PHP_SELF'] . "?filter=" . ($_GET['filter'] ?? ''));
    } else {
        // Hard delete (old behavior)
        $stmt = $pdo->prepare("SELECT img_article FROM news WHERE id = ?");
        $stmt->execute([$id]);
        $img = $stmt->fetchColumn();
        if ($img && file_exists("../files/_news/" . $img)) {
            unlink("../files/_news/" . $img);
        }
        $stmt = $pdo->prepare("DELETE FROM news WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Article supprimé.'];
        header("Location: " . $_SERVER['PHP_SELF']);
    }
    exit;
}

if ($migrationDone) {
    // ─── Restore from trash ───
    if (isset($_POST['restore_news'])) {
        $id = $_POST['news_id'];
        $stmt = $pdo->prepare("UPDATE news SET deleted_at = NULL WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Article restauré.'];
        header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
        exit;
    }

    // ─── Permanent delete ───
    if (isset($_POST['permanent_delete_news'])) {
        $id = $_POST['news_id'];
        $stmt = $pdo->prepare("SELECT img_article FROM news WHERE id = ?");
        $stmt->execute([$id]);
        $img = $stmt->fetchColumn();

        if ($img && file_exists("../files/_news/" . $img)) {
            unlink("../files/_news/" . $img);
        }

        $stmt = $pdo->prepare("DELETE FROM news WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Article supprimé définitivement.'];
        header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
        exit;
    }
}

// ─── Filter, Search & Pagination logic ───
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';
$search = trim($_GET['q'] ?? '');
$isTrashed = false;
$perPage = 12;
$page = max(1, (int) ($_GET['page'] ?? 1));

if ($migrationDone) {
    // Build WHERE clause based on filter
    switch ($filter) {
        case 'published':
            $where = "deleted_at IS NULL AND status = 'published'";
            $orderBy = "date_publication DESC";
            break;
        case 'draft':
            $where = "deleted_at IS NULL AND status = 'draft'";
            $orderBy = "date_publication DESC";
            break;
        case 'trashed':
            $where = "deleted_at IS NOT NULL";
            $orderBy = "deleted_at DESC";
            break;
        default:
            $filter = '';
            $where = "deleted_at IS NULL";
            $orderBy = "date_publication DESC";
            break;
    }

    // Add search condition
    $params = [];
    if ($search !== '') {
        $where .= " AND title_article LIKE ?";
        $params[] = "%$search%";
    }

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM news WHERE $where");
    $stmtCount->execute($params);
    $totalArticles = (int) $stmtCount->fetchColumn();
    $totalPages = max(1, (int) ceil($totalArticles / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $stmtArticles = $pdo->prepare("SELECT * FROM news WHERE $where ORDER BY $orderBy LIMIT $perPage OFFSET $offset");
    $stmtArticles->execute($params);
    $articles = $stmtArticles->fetchAll(PDO::FETCH_ASSOC);

    // Counts for tab badges (not affected by search)
    $countAll      = $pdo->query("SELECT COUNT(*) FROM news WHERE deleted_at IS NULL")->fetchColumn();
    $countPublished = $pdo->query("SELECT COUNT(*) FROM news WHERE deleted_at IS NULL AND status = 'published'")->fetchColumn();
    $countDraft    = $pdo->query("SELECT COUNT(*) FROM news WHERE deleted_at IS NULL AND status = 'draft'")->fetchColumn();
    $countTrashed  = $pdo->query("SELECT COUNT(*) FROM news WHERE deleted_at IS NOT NULL")->fetchColumn();

    $isTrashed = ($filter === 'trashed');
} else {
    $filter = '';
    $params = [];
    $where = '1=1';
    if ($search !== '') {
        $where = "title_article LIKE ?";
        $params[] = "%$search%";
    }
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM news WHERE $where");
    $stmtCount->execute($params);
    $totalArticles = (int) $stmtCount->fetchColumn();
    $totalPages = max(1, (int) ceil($totalArticles / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $stmtArticles = $pdo->prepare("SELECT * FROM news WHERE $where ORDER BY date_publication DESC LIMIT $perPage OFFSET $offset");
    $stmtArticles->execute($params);
    $articles = $stmtArticles->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Actualités</title>

<!-- ─── CSS ─── -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<style>
  .card-dashboard{margin-top:1rem;border-radius:1.25rem;box-shadow:0 0 25px rgba(0,0,0,.1)}
.card {
  border-radius: 12px;
  position: relative;
}
.card-img-top {
  border-top-left-radius: 12px;
  border-top-right-radius: 12px;
}

/* Status badge */
.badge-status {
  position: absolute;
  top: 10px;
  right: 10px;
  z-index: 2;
  font-size: 0.75rem;
  padding: 4px 10px;
  border-radius: 20px;
  font-weight: 600;
  box-shadow: 0 1px 4px rgba(0,0,0,.15);
}
.badge-published {
  background-color: #198754;
  color: #fff;
}
.badge-draft {
  background-color: #fd7e14;
  color: #fff;
}

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

/* Search bar */
.news-search-bar {
  max-width: 350px;
  width: 100%;
}
.news-search-bar .input-group {
  border: 1px solid #d4c4cb;
  border-radius: 8px;
  overflow: hidden;
  background: #fff;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.news-search-bar .input-group:focus-within {
  border-color: #ec4899;
  box-shadow: 0 0 0 3px rgba(236,72,153,.1);
}
.news-search-bar .input-group-text {
  border: none !important;
  background: transparent !important;
  color: #9e8a92;
  padding: 8px 10px 8px 14px;
}
.news-search-bar .form-control {
  border: none !important;
  box-shadow: none !important;
  padding: 8px 14px 8px 4px;
  font-size: 13px;
  color: #1e293b;
}
.news-search-bar .form-control::placeholder { color: #9e8a92; }
.news-search-bar .form-control:focus { box-shadow: none !important; }

/* Trashed card style */
.card-trashed {
  opacity: 0.7;
  border: 1px dashed #dc3545 !important;
}
</style>
</head>

<body>

<?php include 'navbar-admin.php'; ?>

<div class="container py-4">
  <h1 class="mb-3 fw-bold">Actualités</h1>

  <?php if (isset($_SESSION['flash_message']) && !isset($_SESSION['reopen_news_modal'])):
    $flash = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
  ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show auto-dismiss" data-dismiss-delay="<?= $flash['type'] === 'success' ? '5000' : '10000' ?>" role="alert">
      <?= htmlspecialchars($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if (!$migrationDone): ?>
  <div class="alert alert-warning" role="alert">
    <i class="bi bi-exclamation-triangle"></i> Veuillez executer la mise a jour BDD pour activer toutes les fonctionnalites (statut, corbeille, filtres).
  </div>
  <?php endif; ?>

  <?php if ($migrationDone): ?>
  <!-- Filter tabs -->
  <div class="filter-tabs">
    <a href="?filter=" class="<?= $filter === '' ? 'active' : '' ?>">
      Tous <span class="badge bg-secondary"><?= $countAll ?></span>
    </a>
    <a href="?filter=published" class="<?= $filter === 'published' ? 'active' : '' ?>">
      Publiés <span class="badge bg-success"><?= $countPublished ?></span>
    </a>
    <a href="?filter=draft" class="<?= $filter === 'draft' ? 'active' : '' ?>">
      Brouillons <span class="badge bg-warning text-dark"><?= $countDraft ?></span>
    </a>
    <a href="?filter=trashed" class="<?= $filter === 'trashed' ? 'active' : '' ?>">
      <i class="bi bi-trash3"></i> Corbeille <span class="badge bg-danger"><?= $countTrashed ?></span>
    </a>
  </div>
  <?php endif; ?>

  <!-- Search bar + Add button row -->
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <form class="news-search-bar" method="get" action="">
      <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
      <div class="input-group input-group-sm">
        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
        <input type="text" name="q" class="form-control" placeholder="Rechercher un article par titre..." value="<?= htmlspecialchars($search) ?>">
        <?php if ($search !== ''): ?>
          <a href="?filter=<?= htmlspecialchars($filter) ?>" class="btn btn-sm btn-outline-secondary">&times;</a>
        <?php endif; ?>
      </div>
    </form>
    <?php if (!$isTrashed): ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddNews">
      <i class="bi bi-plus-lg"></i> Ajouter un article
    </button>
    <?php endif; ?>
  </div>

  <!-- Spinner de chargement -->
  <div id="loadingSpinner" class="text-center py-5">
    <div class="spinner-border text-pink" role="status" style="width:2.5rem;height:2.5rem;color:#ec4899;"></div>
    <p class="text-muted mt-2 small">Chargement des articles...</p>
  </div>

  <?php if (empty($articles)): ?>
    <div class="text-center text-muted py-5 content-loaded" style="display:none;">
      <i class="bi bi-newspaper" style="font-size:3rem;"></i>
      <p class="mt-2"><?= $isTrashed ? 'La corbeille est vide.' : 'Aucun article trouvé.' ?></p>
    </div>
  <?php else: ?>
  <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 content-loaded" id="newsCardContainer" style="display:none;">
    <?php foreach ($articles as $n): ?>
      <div class="col news-card-col" data-title="<?= htmlspecialchars(strtolower($n['title_article'])) ?>">
        <div class="card h-100 shadow-sm border-0 <?= $isTrashed ? 'card-trashed' : '' ?>">
          <?php if (!empty($n['img_article']) && file_exists("../files/_news/" . $n['img_article'])): ?>
            <img src="../files/_news/<?= htmlspecialchars($n['img_article']) ?>" class="card-img-top" style="height: 200px; object-fit: cover;">
          <?php else: ?>
            <div style="height:200px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:32px;opacity:.3;">📰</div>
          <?php endif; ?>
          <div class="card-body d-flex flex-column">
            <h6 class="card-title fw-bold"><?= htmlspecialchars($n['title_article']) ?></h6>
            <p class="card-text small"><?= substr(strip_tags($n['desc_article']), 0, 120) ?>...</p>
            <p class="text-muted small mb-2">
              <?php if ($migrationDone && $isTrashed): ?>
                Supprimé le <?= date('d/m/Y H:i', strtotime($n['deleted_at'])) ?>
              <?php else: ?>
                Publié le <?= date('d/m/Y H:i', strtotime($n['date_publication'])) ?>
              <?php endif; ?>
            </p>
            <div class="mt-auto d-flex gap-2 flex-wrap align-items-center">
              <?php if ($migrationDone && $isTrashed): ?>
                <!-- Trash view buttons -->
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="news_id" value="<?= $n['id'] ?>">
                  <button type="submit" name="restore_news" class="btn btn-sm btn-success">
                    <i class="bi bi-arrow-counterclockwise"></i> Restaurer
                  </button>
                </form>
                <form method="post" data-confirm="Supprimer DÉFINITIVEMENT cet article ? Cette action est irréversible.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="news_id" value="<?= $n['id'] ?>">
                  <button type="submit" name="permanent_delete_news" class="btn btn-sm btn-danger">
                    <i class="bi bi-x-circle"></i> Supprimer définitivement
                  </button>
                </form>
              <?php else: ?>
                <!-- Normal view buttons -->
                <a href="../public/news.php?preview=<?= $n['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Aperçu">
                  <i class="bi bi-eye"></i> Aperçu
                </a>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalEditNews<?= $n['id'] ?>">
                  <i class="bi bi-pencil"></i> Modifier
                </button>
                <form method="post" data-confirm="<?= $migrationDone ? 'Mettre cet article en corbeille ?' : 'Supprimer definitivement cet article ?' ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="news_id" value="<?= $n['id'] ?>">
                  <button type="submit" name="delete_news" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash3"></i> <?= $migrationDone ? 'Corbeille' : 'Supprimer' ?>
                  </button>
                </form>
                <?php if ($migrationDone): ?>
                  <span class="ms-auto badge <?= $n['status'] === 'published' ? 'bg-success' : 'bg-warning text-dark' ?>">
                    <?= $n['status'] === 'published' ? 'Publié' : 'Brouillon' ?>
                  </span>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <?php if (!$isTrashed): ?>
      <!-- Modal Modifier -->
      <div class="modal fade" id="modalEditNews<?= $n['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-xl modal-fullscreen-lg-down">
          <div class="modal-content p-4">
            <div class="modal-header">
              <h5 class="modal-title">Modifier l'article</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <?php if (isset($_SESSION['flash_message']) && isset($_SESSION['reopen_news_modal']) && $_SESSION['reopen_news_modal'] == $n['id']): ?>
                <div class="alert alert-<?= $_SESSION['flash_message']['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show auto-dismiss" data-dismiss-delay="<?= $_SESSION['flash_message']['type'] === 'success' ? '5000' : '10000' ?>" role="alert">
                  <?= htmlspecialchars($_SESSION['flash_message']['message']) ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['flash_message']); ?>
              <?php endif; ?>
              <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabContent<?= $n['id'] ?>">Contenu</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabComments<?= $n['id'] ?>" data-action="load-comments" data-news-id="<?= $n['id'] ?>">Commentaires</a></li>
              </ul>
              <div class="tab-content">
                <!-- Onglet Contenu -->
                <div class="tab-pane fade show active" id="tabContent<?= $n['id'] ?>">
                  <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="news_id" value="<?= $n['id'] ?>">
                    <div class="row g-3">
                      <div class="<?= $migrationDone ? 'col-md-6' : 'col-md-6' ?>">
                        <label>Titre</label>
                        <input type="text" name="title_article" class="form-control" value="<?= htmlspecialchars($n['title_article']) ?>" required>
                      </div>
                      <div class="<?= $migrationDone ? 'col-md-3' : 'col-md-6' ?>">
                        <label>Image (laisser vide pour conserver)</label>
                        <input type="file" name="img_article" class="form-control">
                      </div>
                      <?php if ($migrationDone): ?>
                      <div class="col-md-3">
                        <label>Statut</label>
                        <select name="status" class="form-select">
                          <option value="draft" <?= $n['status'] === 'draft' ? 'selected' : '' ?>>Brouillon</option>
                          <option value="published" <?= $n['status'] === 'published' ? 'selected' : '' ?>>Publié</option>
                        </select>
                      </div>
                      <?php endif; ?>
                      <div class="col-md-12">
                        <label>Description</label>
                        <textarea class="form-control tinymce-editor" name="desc_article" rows="6"><?= htmlspecialchars($n['desc_article']) ?></textarea>
                      </div>
                    </div>
                    <div class="mt-3 text-end">
                      <button type="submit" name="update_news" class="btn btn-success">Mettre à jour</button>
                    </div>
                  </form>
                </div>
                <!-- Onglet Commentaires -->
                <div class="tab-pane fade" id="tabComments<?= $n['id'] ?>">
                  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                      <label class="small text-muted mb-0">Afficher</label>
                      <select class="form-select form-select-sm comment-per-page" data-news-id="<?= $n['id'] ?>" style="width:75px;">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                      </select>
                      <span class="small text-muted mb-0">entrées</span>
                    </div>
                    <input type="text" class="form-control form-control-sm comment-search" data-news-id="<?= $n['id'] ?>" placeholder="Rechercher..." style="max-width:220px;">
                  </div>
                  <div id="adminCommentsList<?= $n['id'] ?>" class="admin-comments-list">
                    <p class="text-muted text-center py-4">Cliquez sur l'onglet pour charger les commentaires...</p>
                  </div>
                  <div id="adminCommentsPagination<?= $n['id'] ?>" class="d-flex justify-content-between align-items-center mt-3" style="display:none!important;"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <?php
    if ($totalPages > 1):
      // pagination is also hidden initially via content-loaded
      $qParam = $search !== '' ? '&q=' . urlencode($search) : '';
  ?>
  <nav class="d-flex justify-content-center mt-4 content-loaded" style="display:none;">
    <ul class="pagination pagination-sm">
      <?php if ($page > 1): ?>
        <li class="page-item">
          <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $page - 1 ?><?= $qParam ?>">&laquo;</a>
        </li>
      <?php endif; ?>
      <?php
      $start = max(1, $page - 2);
      $end = min($totalPages, $page + 2);
      for ($i = $start; $i <= $end; $i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
          <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $i ?><?= $qParam ?>"><?= $i ?></a>
        </li>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?>
        <li class="page-item">
          <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $page + 1 ?><?= $qParam ?>">&raquo;</a>
        </li>
      <?php endif; ?>
    </ul>
    <span class="text-muted small align-self-center ms-3"><?= $totalArticles ?> article<?= $totalArticles > 1 ? 's' : '' ?></span>
  </nav>
  <?php endif; ?>

  <?php endif; ?>

  <!-- Modal Ajouter -->
  <div class="modal fade" id="modalAddNews" tabindex="-1">
    <div class="modal-dialog modal-xl modal-fullscreen-lg-down">
      <div class="modal-content p-4">
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <div class="modal-header">
            <h5 class="modal-title">Ajouter un article</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body row g-3">
            <div class="<?= $migrationDone ? 'col-md-5' : 'col-md-6' ?>">
              <label>Titre</label>
              <input type="text" name="title_article" class="form-control" required>
            </div>
            <div class="<?= $migrationDone ? 'col-md-4' : 'col-md-6' ?>">
              <label>Image</label>
              <input type="file" name="img_article" class="form-control">
            </div>
            <?php if ($migrationDone): ?>
            <div class="col-md-3">
              <label>Statut</label>
              <select name="status" class="form-select">
                <option value="draft" selected>Brouillon</option>
                <option value="published">Publié</option>
              </select>
            </div>
            <?php endif; ?>
            <div class="col-md-12">
                <label>Description</label>
                <textarea class="form-control tinymce-editor" name="desc_article" rows="6"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" name="add_news" class="btn btn-success">Ajouter</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ############################ Description ############################ -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .card-dashboard {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
        }
        .tox-tinymce {
            border-radius: 0.375rem !important;
        }
        /* Admin comments list */
        .admin-comment {
            display: flex; gap: 12px; padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .admin-comment:last-child { border-bottom: none; }
        .admin-comment-body { flex: 1; min-width: 0; }
        .admin-comment-head {
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 4px; flex-wrap: wrap;
        }
        .admin-comment-author { font-weight: 700; font-size: 14px; }
        .admin-comment-ip {
            font-size: 12px; color: #6c757d;
            font-family: monospace; background: #f1f3f5;
            padding: 1px 6px; border-radius: 4px;
        }
        .admin-comment-date { font-size: 12px; color: #adb5bd; }
        .admin-comment-text {
            font-size: 13px; color: #495057;
            margin-bottom: 6px; word-break: break-word;
        }
        .admin-comment-meta {
            display: flex; align-items: center; gap: 8px;
            font-size: 12px; color: #adb5bd;
        }
        .admin-comment-actions {
            display: flex; gap: 4px; align-items: center;
            flex-shrink: 0;
        }
        .admin-comment-actions .btn { padding: 4px 8px; font-size: 12px; }
        .badge-banned {
            background: #dc3545; color: #fff;
            font-size: 11px; padding: 2px 8px; border-radius: 4px;
        }
        .admin-comments-spinner {
            text-align: center; padding: 24px 0; color: #adb5bd;
        }
    </style>
    <script src="../js/tinymce/tinymce.min.js"></script>
    <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
        tinymce.init({
            selector: '.tinymce-editor',
            license_key: 'gpl',
            language: 'fr_FR',
            plugins: 'anchor autolink charmap codesample emoticons image link lists media searchreplace table visualblocks wordcount code',
            toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat | code',
            height: 500,
            menubar: false,
            branding: false,
            content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; }',
            valid_styles: {
                '*': 'text-align,line-height,color,background-color,font-size,font-weight,font-style,text-decoration,padding,padding-left,padding-right,padding-top,padding-bottom,margin,margin-left,margin-right,margin-top,margin-bottom',
                'img': 'width,height,max-width,float,margin,margin-left,margin-right,margin-top,margin-bottom,display',
                'table': 'width,height,border-collapse,border-spacing'
            },

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

            // 🔒 [SEC-04] Whitelist HTML sécurisée (CWE-79)
            extended_valid_elements: 'a[href|target|title|class|rel],'
              + 'img[src|alt|title|width|height|class|loading|style],'
              + 'p[class|style],span[class|style],div[class|style],'
              + 'table[class|border|cellpadding|cellspacing|style],thead,tbody,tfoot,'
              + 'tr,td[class|style|colspan|rowspan],th[class|style|colspan|rowspan],'
              + 'ul[class],ol[class|type|start],li[class],'
              + 'blockquote[class|cite],pre[class],code,strong/b,em/i,u,s,sub,sup,br,'
              + 'hr[class],h1[class|style],h2[class|style],h3[class|style],'
              + 'h4[class|style],h5[class|style],h6[class|style],'
              + 'figure[class],figcaption,video[src|controls|width|height|class],'
              + 'audio[src|controls|class],source[src|type]',
            invalid_elements: 'script,iframe,object,embed,form,input,textarea,select,button,applet,meta,link,base',

            // Upload images sur le serveur au lieu de base64
            images_upload_handler: (blobInfo) => new Promise((resolve, reject) => {
                const formData = new FormData();
                formData.append('file', blobInfo.blob(), blobInfo.filename());
                formData.append('csrf_token', '<?= csrf_token() ?>');
                fetch('../inc/tinymce-upload.php', { method: 'POST', body: formData })
                    .then(r => { if (!r.ok) throw new Error('Upload failed'); return r.json(); })
                    .then(data => { if (data.location) resolve(data.location); else reject(data.error || 'Upload error'); })
                    .catch(e => reject(e.message));
            }),
            automatic_uploads: true,
            images_reuse_filename: true,

            // Upload fichiers (PDF, images) via le sélecteur de fichiers
            file_picker_types: 'file image',
            file_picker_callback: (callback, value, meta) => {
                const input = document.createElement('input');
                input.type = 'file';
                input.accept = meta.filetype === 'image' ? 'image/*' : 'image/*,.pdf';
                input.addEventListener('change', () => {
                    const file = input.files[0];
                    if (!file) return;
                    const formData = new FormData();
                    formData.append('file', file);
                    formData.append('csrf_token', '<?= csrf_token() ?>');
                    fetch('../inc/tinymce-upload.php', { method: 'POST', body: formData })
                        .then(r => { if (!r.ok) throw new Error('Upload failed'); return r.json(); })
                        .then(data => { if (data.location) { const n = data.title || file.name.replace(/\.[^.]+$/,''); callback(data.location, { title: n, text: n + '.' + file.name.split('.').pop() }); } })
                        .catch(e => alert('Erreur upload: ' + e.message));
                });
                input.click();
            },

            // Configuration du mode code
            toolbar_mode: 'sliding'
        });
    </script>
<!-- ############################ Description ############################ -->

<?php include 'admin-footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
document.addEventListener('DOMContentLoaded', function() {
  // Rouvrir le modal après mise à jour
  <?php if (isset($_SESSION['reopen_news_modal'])):
    $reopenId = $_SESSION['reopen_news_modal'];
    unset($_SESSION['reopen_news_modal']);
  ?>
  var modalEl = document.getElementById('modalEditNews<?= (int)$reopenId ?>');
  if (modalEl) new bootstrap.Modal(modalEl).show();
  <?php endif; ?>

  // Masquer spinner, afficher contenu
  var spinner = document.getElementById('loadingSpinner');
  if (spinner) spinner.style.display = 'none';
  document.querySelectorAll('.content-loaded').forEach(function(el) { el.style.display = ''; });

  // Auto-dismiss des alertes
  document.querySelectorAll('.auto-dismiss').forEach(function(alert) {
    var delay = parseInt(alert.dataset.dismissDelay) || 5000;
    setTimeout(function() {
      var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
      bsAlert.close();
    }, delay);
  });
});

// ─── Admin Comments Management (paginé + recherche serveur) ───
var commentState = {}; // { newsId: { page, perPage, search } }

function loadAdminComments(newsId, page, perPage, search) {
    var state = commentState[newsId] || { page: 1, perPage: 10, search: '' };
    state.page = page || state.page;
    state.perPage = perPage || state.perPage;
    if (typeof search === 'string') state.search = search;
    commentState[newsId] = state;

    var container = document.getElementById('adminCommentsList' + newsId);
    var pagination = document.getElementById('adminCommentsPagination' + newsId);
    if (!container) return;

    container.innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status" style="width:2rem;height:2rem;color:#ec4899;"></div><p class="text-muted mt-2 small">Chargement des commentaires...</p></div>';
    if (pagination) pagination.style.display = 'none';

    $.ajax({
        url: '../public/news_action.php',
        type: 'GET',
        dataType: 'json',
        data: { action: 'get_admin_comments', news_id: newsId, page: state.page, per_page: state.perPage, search: state.search },
        success: function(res) {
            if (!res.success) {
                container.innerHTML = '<p class="text-danger text-center py-3">Erreur : ' + (res.error || 'Impossible de charger') + '</p>';
                return;
            }
            if (res.total === 0) {
                container.innerHTML = '<p class="text-muted text-center py-4">' + (state.search ? 'Aucun résultat pour "' + escHtml(state.search) + '".' : 'Aucun commentaire pour cet article.') + '</p>';
                if (pagination) pagination.style.display = 'none';
                return;
            }
            var html = '';
            res.comments.forEach(function(c) {
                html += '<div class="admin-comment" data-id="' + c.id + '">';
                html += '<div class="admin-comment-body">';
                html += '<div class="admin-comment-head">';
                html += '<span class="admin-comment-author">' + escHtml(c.author_name) + '</span>';
                html += '<span class="admin-comment-ip">' + escHtml(c.ip_address) + '</span>';
                if (c.is_banned) html += '<span class="badge-banned">IP bannie</span>';
                if (c.parent_id) html += '<span class="badge bg-secondary" style="font-size:10px;">Réponse</span>';
                html += '</div>';
                html += '<div class="admin-comment-text">' + escHtml(c.content) + '</div>';
                html += '<div class="admin-comment-meta">';
                html += '<span>' + c.created_at + '</span>';
                html += '<span><i class="bi bi-heart-fill"></i> ' + c.likes + '</span>';
                html += '</div></div>';
                html += '<div class="admin-comment-actions">';
                html += '<button class="btn btn-danger btn-sm" title="Supprimer" data-action="delete-comment" data-comment-id="' + c.id + '" data-news-id="' + newsId + '"><i class="bi bi-trash"></i></button>';
                if (!c.is_banned) {
                    html += '<button class="btn btn-warning btn-sm" title="Bannir IP" data-action="ban-ip" data-ip="' + escHtml(c.ip_address) + '" data-news-id="' + newsId + '"><i class="bi bi-shield-x"></i></button>';
                } else {
                    html += '<button class="btn btn-success btn-sm" title="Débannir IP" data-action="unban-ip" data-ip="' + escHtml(c.ip_address) + '" data-news-id="' + newsId + '"><i class="bi bi-shield-check"></i></button>';
                }
                html += '</div></div>';
            });
            container.innerHTML = html;

            // Pagination
            if (pagination && res.pages > 1) {
                var pHtml = '<span class="small text-muted">' + res.total + ' commentaire' + (res.total > 1 ? 's' : '') + '</span>';
                pHtml += '<nav><ul class="pagination pagination-sm mb-0">';
                if (res.page > 1) pHtml += '<li class="page-item"><a class="page-link" href="#" data-action="comment-page" data-news-id="' + newsId + '" data-page="' + (res.page - 1) + '">&laquo;</a></li>';
                var s = Math.max(1, res.page - 2), e = Math.min(res.pages, res.page + 2);
                for (var i = s; i <= e; i++) {
                    pHtml += '<li class="page-item ' + (i === res.page ? 'active' : '') + '"><a class="page-link" href="#" data-action="comment-page" data-news-id="' + newsId + '" data-page="' + i + '">' + i + '</a></li>';
                }
                if (res.page < res.pages) pHtml += '<li class="page-item"><a class="page-link" href="#" data-action="comment-page" data-news-id="' + newsId + '" data-page="' + (res.page + 1) + '">&raquo;</a></li>';
                pHtml += '</ul></nav>';
                pagination.innerHTML = pHtml;
                pagination.style.display = 'flex';
            } else if (pagination) {
                pagination.innerHTML = '<span class="small text-muted">' + res.total + ' commentaire' + (res.total > 1 ? 's' : '') + '</span>';
                pagination.style.display = 'flex';
            }
        },
        error: function() {
            container.innerHTML = '<p class="text-danger text-center py-3">Erreur de connexion.</p>';
        }
    });
}

function deleteAdminComment(commentId, newsId) {
    if (!confirm('Supprimer ce commentaire et ses réponses ?')) return;
    $.post('../public/news_action.php', { action: 'delete_comment', comment_id: commentId }, function(res) {
        if (res.success) loadAdminComments(newsId);
        else alert('Erreur : ' + (res.error || 'Impossible de supprimer'));
    }, 'json');
}

function banAdminIP(ip, newsId) {
    var reason = prompt('Raison du bannissement (optionnel) :');
    if (reason === null) return;
    $.post('../public/news_action.php', { action: 'ban_ip', ip_address: ip, reason: reason }, function(res) {
        if (res.success) loadAdminComments(newsId);
        else alert('Erreur : ' + (res.error || 'Impossible de bannir'));
    }, 'json');
}

function unbanAdminIP(ip, newsId) {
    if (!confirm('Débannir cette IP ?')) return;
    $.post('../public/news_action.php', { action: 'unban_ip', ip_address: ip }, function(res) {
        if (res.success) loadAdminComments(newsId);
        else alert('Erreur : ' + (res.error || 'Impossible de débannir'));
    }, 'json');
}

function escHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
}

// Recherche serveur (debounced)
var commentSearchTimers = {};
document.addEventListener('input', function(e) {
    if (!e.target.classList.contains('comment-search')) return;
    var newsId = e.target.dataset.newsId;
    clearTimeout(commentSearchTimers[newsId]);
    commentSearchTimers[newsId] = setTimeout(function() {
        loadAdminComments(parseInt(newsId), 1, null, e.target.value.trim());
    }, 400);
});

// Changement nombre d'entrées
document.addEventListener('change', function(e) {
    if (!e.target.classList.contains('comment-per-page')) return;
    var newsId = parseInt(e.target.dataset.newsId);
    loadAdminComments(newsId, 1, parseInt(e.target.value));
});

// ─── Event delegation admin comments (CSP-compatible) ───
document.addEventListener('click', function(e) {
    var el = e.target.closest('[data-action]');
    if (!el) return;
    var action = el.dataset.action;
    if (action === 'load-comments') loadAdminComments(parseInt(el.dataset.newsId));
    if (action === 'comment-page') { e.preventDefault(); loadAdminComments(parseInt(el.dataset.newsId), parseInt(el.dataset.page)); }
    if (action === 'delete-comment') deleteAdminComment(parseInt(el.dataset.commentId), parseInt(el.dataset.newsId));
    if (action === 'ban-ip') banAdminIP(el.dataset.ip, parseInt(el.dataset.newsId));
    if (action === 'unban-ip') unbanAdminIP(el.dataset.ip, parseInt(el.dataset.newsId));
});
</script>
</body>
</html>
