<?php
require '../config/config.php';
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

// ─── Add news ───
if (isset($_POST['add_news'])) {
    $title = $_POST['title_article'];
    $desc = $_POST['desc_article'];
    $imgName = '';

    if (!empty($_FILES['img_article']['name'])) {
        $imgName = basename($_FILES['img_article']['name']);
        move_uploaded_file($_FILES['img_article']['tmp_name'], "../files/_news/" . $imgName);
    }

    if ($migrationDone) {
        $status = isset($_POST['status']) && in_array($_POST['status'], ['published', 'draft']) ? $_POST['status'] : 'draft';
        $stmt = $pdo->prepare("INSERT INTO news (img_article, title_article, desc_article, date_publication, `like`, `dislike`, status) VALUES (?, ?, ?, NOW(), 0, 0, ?)");
        $stmt->execute([$imgName, $title, $desc, $status]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO news (img_article, title_article, desc_article, date_publication, `like`, `dislike`) VALUES (?, ?, ?, NOW(), 0, 0)");
        $stmt->execute([$imgName, $title, $desc]);
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ─── Update news ───
if (isset($_POST['update_news'])) {
    $id = $_POST['news_id'];
    $title = $_POST['title_article'];
    $desc = $_POST['desc_article'];

    if ($migrationDone) {
        $status = isset($_POST['status']) && in_array($_POST['status'], ['published', 'draft']) ? $_POST['status'] : 'draft';
        if (!empty($_FILES['img_article']['name'])) {
            $imgName = basename($_FILES['img_article']['name']);
            move_uploaded_file($_FILES['img_article']['tmp_name'], "../files/_news/" . $imgName);
            $stmt = $pdo->prepare("UPDATE news SET img_article = ?, title_article = ?, desc_article = ?, status = ? WHERE id = ?");
            $stmt->execute([$imgName, $title, $desc, $status, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE news SET title_article = ?, desc_article = ?, status = ? WHERE id = ?");
            $stmt->execute([$title, $desc, $status, $id]);
        }
    } else {
        if (!empty($_FILES['img_article']['name'])) {
            $imgName = basename($_FILES['img_article']['name']);
            move_uploaded_file($_FILES['img_article']['tmp_name'], "../files/_news/" . $imgName);
            $stmt = $pdo->prepare("UPDATE news SET img_article = ?, title_article = ?, desc_article = ? WHERE id = ?");
            $stmt->execute([$imgName, $title, $desc, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE news SET title_article = ?, desc_article = ? WHERE id = ?");
            $stmt->execute([$title, $desc, $id]);
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ─── Delete news ───
if (isset($_POST['delete_news'])) {
    $id = $_POST['news_id'];
    if ($migrationDone) {
        // Soft delete (move to trash)
        $stmt = $pdo->prepare("UPDATE news SET deleted_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
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

        header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
        exit;
    }
}

// ─── Filter logic ───
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';
$isTrashed = false;

if ($migrationDone) {
    switch ($filter) {
        case 'published':
            $articles = $pdo->query("SELECT * FROM news WHERE deleted_at IS NULL AND status = 'published' ORDER BY date_publication DESC")->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'draft':
            $articles = $pdo->query("SELECT * FROM news WHERE deleted_at IS NULL AND status = 'draft' ORDER BY date_publication DESC")->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'trashed':
            $articles = $pdo->query("SELECT * FROM news WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC")->fetchAll(PDO::FETCH_ASSOC);
            break;
        default:
            $filter = '';
            $articles = $pdo->query("SELECT * FROM news WHERE deleted_at IS NULL ORDER BY date_publication DESC")->fetchAll(PDO::FETCH_ASSOC);
            break;
    }

    // Counts for tab badges
    $countAll      = $pdo->query("SELECT COUNT(*) FROM news WHERE deleted_at IS NULL")->fetchColumn();
    $countPublished = $pdo->query("SELECT COUNT(*) FROM news WHERE deleted_at IS NULL AND status = 'published'")->fetchColumn();
    $countDraft    = $pdo->query("SELECT COUNT(*) FROM news WHERE deleted_at IS NULL AND status = 'draft'")->fetchColumn();
    $countTrashed  = $pdo->query("SELECT COUNT(*) FROM news WHERE deleted_at IS NOT NULL")->fetchColumn();

    $isTrashed = ($filter === 'trashed');
} else {
    $filter = '';
    $articles = $pdo->query("SELECT * FROM news ORDER BY date_publication DESC")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Actualités – Forbach en Rose</title>

<!-- ─── CSS ─── -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-KE9wPQ6…(clé-cdn)…" crossorigin="anonymous"></script>
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
  border-bottom-color: #c4577a;
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
  border-color: #c4577a;
  box-shadow: 0 0 0 3px rgba(196,87,122,.1);
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
    <div class="news-search-bar">
      <div class="input-group input-group-sm">
        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
        <input type="text" id="newsSearchInput" class="form-control" placeholder="Rechercher un article par titre...">
      </div>
    </div>
    <?php if (!$isTrashed): ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddNews">
      <i class="bi bi-plus-lg"></i> Ajouter un article
    </button>
    <?php endif; ?>
  </div>

  <?php if (empty($articles)): ?>
    <div class="text-center text-muted py-5">
      <i class="bi bi-newspaper" style="font-size:3rem;"></i>
      <p class="mt-2"><?= $isTrashed ? 'La corbeille est vide.' : 'Aucun article trouvé.' ?></p>
    </div>
  <?php else: ?>
  <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4" id="newsCardContainer">
    <?php foreach ($articles as $n): ?>
      <div class="col news-card-col" data-title="<?= htmlspecialchars(strtolower($n['title_article'])) ?>">
        <div class="card h-100 shadow-sm border-0 <?= $isTrashed ? 'card-trashed' : '' ?>">
          <?php if ($migrationDone && !$isTrashed): ?>
            <span class="badge-status <?= $n['status'] === 'published' ? 'badge-published' : 'badge-draft' ?>">
              <?= $n['status'] === 'published' ? 'Publié' : 'Brouillon' ?>
            </span>
          <?php endif; ?>
          <?php if (!empty($n['img_article'])): ?>
            <img src="../files/_news/<?= htmlspecialchars($n['img_article']) ?>" class="card-img-top" style="height: 200px; object-fit: cover;">
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
            <div class="mt-auto d-flex gap-2 flex-wrap">
              <?php if ($migrationDone && $isTrashed): ?>
                <!-- Trash view buttons -->
                <form method="post">
                  <input type="hidden" name="news_id" value="<?= $n['id'] ?>">
                  <button type="submit" name="restore_news" class="btn btn-sm btn-success">
                    <i class="bi bi-arrow-counterclockwise"></i> Restaurer
                  </button>
                </form>
                <form method="post" onsubmit="return confirm('Supprimer DÉFINITIVEMENT cet article ? Cette action est irréversible.');">
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
                <form method="post" onsubmit="return confirm('<?= $migrationDone ? 'Mettre cet article en corbeille ?' : 'Supprimer definitivement cet article ?' ?>');">
                  <input type="hidden" name="news_id" value="<?= $n['id'] ?>">
                  <button type="submit" name="delete_news" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash3"></i> <?= $migrationDone ? 'Corbeille' : 'Supprimer' ?>
                  </button>
                </form>
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
              <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabContent<?= $n['id'] ?>">Contenu</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabComments<?= $n['id'] ?>" onclick="loadAdminComments(<?= $n['id'] ?>)">Commentaires</a></li>
              </ul>
              <div class="tab-content">
                <!-- Onglet Contenu -->
                <div class="tab-pane fade show active" id="tabContent<?= $n['id'] ?>">
                  <form method="post" enctype="multipart/form-data">
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
                  <input type="text" class="form-control form-control-sm mb-3 comment-search" data-news-id="<?= $n['id'] ?>" placeholder="Rechercher un commentaire...">
                  <div id="adminCommentsList<?= $n['id'] ?>" class="admin-comments-list">
                    <p class="text-muted text-center py-4">Cliquez sur l'onglet pour charger les commentaires...</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Modal Ajouter -->
  <div class="modal fade" id="modalAddNews" tabindex="-1">
    <div class="modal-dialog modal-xl modal-fullscreen-lg-down">
      <div class="modal-content p-4">
        <form method="post" enctype="multipart/form-data">
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
    <script src="https://cdn.tiny.cloud/1/ocg6h1zh0bqfzq51xcl7ht600996lxdjpymxlculzjx5q3bd/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
        tinymce.init({
            selector: '.tinymce-editor',
            plugins: 'anchor autolink charmap codesample emoticons image link lists media searchreplace table visualblocks wordcount code',
            toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat | code',
            height: 500,
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
<!-- ############################ Description ############################ -->

<?php include 'admin-footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ─── Client-side search filter ───
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('newsSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var query = this.value.toLowerCase().trim();
            var cards = document.querySelectorAll('.news-card-col');
            cards.forEach(function(col) {
                var title = col.getAttribute('data-title') || '';
                col.style.display = title.indexOf(query) !== -1 ? '' : 'none';
            });
        });
    }
});

// ─── Admin Comments Management ───
function loadAdminComments(newsId) {
    var container = document.getElementById('adminCommentsList' + newsId);
    if (!container) return;
    container.innerHTML = '<div class="admin-comments-spinner"><div class="spinner-border spinner-border-sm" role="status"></div> Chargement...</div>';

    $.ajax({
        url: '../public/news_action.php',
        type: 'GET',
        dataType: 'json',
        data: { action: 'get_admin_comments', news_id: newsId },
        success: function(res) {
            if (!res.success) {
                container.innerHTML = '<p class="text-danger text-center py-3">Erreur : ' + (res.error || 'Impossible de charger') + '</p>';
                return;
            }
            if (res.comments.length === 0) {
                container.innerHTML = '<p class="text-muted text-center py-4">Aucun commentaire pour cet article.</p>';
                return;
            }
            var html = '';
            res.comments.forEach(function(c) {
                html += '<div class="admin-comment" data-id="' + c.id + '">';
                html += '<div class="admin-comment-body">';
                html += '<div class="admin-comment-head">';
                html += '<span class="admin-comment-author">' + escHtml(c.author_name) + '</span>';
                html += '<span class="admin-comment-ip">' + escHtml(c.ip_address) + '</span>';
                if (c.is_banned) {
                    html += '<span class="badge-banned">IP bannie</span>';
                }
                if (c.parent_id) {
                    html += '<span class="badge bg-secondary" style="font-size:10px;">Reponse</span>';
                }
                html += '</div>';
                html += '<div class="admin-comment-text">' + escHtml(c.content) + '</div>';
                html += '<div class="admin-comment-meta">';
                html += '<span>' + c.created_at + '</span>';
                html += '<span><i class="bi bi-heart-fill"></i> ' + c.likes + '</span>';
                html += '</div>';
                html += '</div>';
                html += '<div class="admin-comment-actions">';
                html += '<button class="btn btn-outline-danger btn-sm" title="Supprimer" onclick="deleteAdminComment(' + c.id + ', ' + newsId + ')"><i class="bi bi-trash"></i></button>';
                if (!c.is_banned) {
                    html += '<button class="btn btn-outline-warning btn-sm" title="Bannir IP" onclick="banAdminIP(\'' + escHtml(c.ip_address) + '\', ' + newsId + ')"><i class="bi bi-shield-x"></i></button>';
                } else {
                    html += '<button class="btn btn-outline-success btn-sm" title="Debannir IP" onclick="unbanAdminIP(\'' + escHtml(c.ip_address) + '\', ' + newsId + ')"><i class="bi bi-shield-check"></i></button>';
                }
                html += '</div>';
                html += '</div>';
            });
            container.innerHTML = html;
        },
        error: function() {
            container.innerHTML = '<p class="text-danger text-center py-3">Erreur de connexion.</p>';
        }
    });
}

function deleteAdminComment(commentId, newsId) {
    if (!confirm('Supprimer ce commentaire et ses reponses ?')) return;
    $.ajax({
        url: '../public/news_action.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'delete_comment', comment_id: commentId },
        success: function(res) {
            if (res.success) {
                loadAdminComments(newsId);
            } else {
                alert('Erreur : ' + (res.error || 'Impossible de supprimer'));
            }
        }
    });
}

function banAdminIP(ip, newsId) {
    var reason = prompt('Raison du bannissement (optionnel) :');
    if (reason === null) return;
    $.ajax({
        url: '../public/news_action.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'ban_ip', ip_address: ip, reason: reason },
        success: function(res) {
            if (res.success) {
                loadAdminComments(newsId);
            } else {
                alert('Erreur : ' + (res.error || 'Impossible de bannir'));
            }
        }
    });
}

function unbanAdminIP(ip, newsId) {
    if (!confirm('Debannir cette IP ?')) return;
    $.ajax({
        url: '../public/news_action.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'unban_ip', ip_address: ip },
        success: function(res) {
            if (res.success) {
                loadAdminComments(newsId);
            } else {
                alert('Erreur : ' + (res.error || 'Impossible de debannir'));
            }
        }
    });
}

function escHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
}

// Comment search filter
document.addEventListener('input', function(e) {
    if (!e.target.classList.contains('comment-search')) return;
    var newsId = e.target.dataset.newsId;
    var query = e.target.value.toLowerCase();
    var comments = document.querySelectorAll('#adminCommentsList' + newsId + ' .admin-comment');
    comments.forEach(function(c) {
        var text = c.textContent.toLowerCase();
        c.style.display = text.indexOf(query) !== -1 ? '' : 'none';
    });
});
</script>
</body>
</html>
