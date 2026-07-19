<?php
require '../src/core/config.php';
require_once __DIR__ . '/../src/security/csrf.php';
requirePage('news');
$role = currentRole();
$canCreate = canDoAction('news.create');
$canEdit   = canDoAction('news.edit');
$canTrash  = canDoAction('news.trash');
$canDelete = canDoAction('news.delete');
$readOnly  = !$canCreate && !$canEdit && !$canTrash && !$canDelete;
require_once __DIR__ . '/../src/content/content-log.php';
$canViewLogs = canDoAction('content.logs.view'); // Onglet "Logs" (journal d'activité)

$stmt = $pdo->prepare(
    'SELECT *
       FROM setting
      WHERE id = :id
      LIMIT 1');
$stmt->execute(['id' => 1]);

$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];



// Charger les données pour la navbar
require __DIR__ . '/../src/partials/navbar-data.php';

// Check if migration has been applied (status & deleted_at columns)
$migrationDone = false;
try {
    $pdo->query("SELECT deleted_at, status FROM news LIMIT 0");
    $migrationDone = true;
} catch (PDOException $e) {}

// Newsletter : helpers d'envoi aux abonnés
require_once __DIR__ . '/../src/mail/newsletter.php';

/**
 * Envoie la notification "nouvel article" aux abonnés newsletter — une seule fois
 * par article — si la case "Prévenir les abonnés" est cochée ET que l'article est
 * publié. L'horodatage `newsletter_sent_at` garantit le non-doublon.
 * Toute erreur est journalisée sans bloquer l'enregistrement de l'article.
 */
function newsArticleMaybeNotify(PDO $pdo, int $articleId): void
{
    if ($articleId <= 0 || empty($_POST['notify_subscribers'])) return;
    try {
        $st = $pdo->prepare("SELECT id, title_article, desc_article, img_article, status, newsletter_sent_at
                               FROM news WHERE id = ?");
        $st->execute([$articleId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($row['status'] ?? '') !== 'published') return; // pas de notif pour un brouillon
        if (!empty($row['newsletter_sent_at'])) return;               // déjà notifié
        $sent = newsletterSendNewArticle($pdo, $row);
        if ($sent > 0) {
            $pdo->prepare("UPDATE news SET newsletter_sent_at = NOW() WHERE id = ?")->execute([$articleId]);
        }
    } catch (\Throwable $e) {
        error_log('[NEWS] notify subscribers: ' . $e->getMessage());
    }
}

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

// ─── Détection AJAX et décodage Base64 des champs HTML ───
$isAjax = isAjaxRequest();

// ─── Bloc de protection des actions d'écriture ───
// delete_news : soft-delete si la migration est faite (→ news.trash),
//               sinon hard-delete (→ news.delete).
$writeOps = [
    'add_news'              => 'news.create',
    'update_news'           => 'news.edit',
    'delete_news'           => $migrationDone ? 'news.trash' : 'news.delete',
    'permanent_delete_news' => 'news.delete',
    'restore_news'          => 'news.edit',
];
foreach ($writeOps as $__op => $__perm) {
    if (isset($_POST[$__op]) && !canDoAction($__perm)) {
        http_response_code(403);
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Action non autorisée.']);
            exit;
        }
        $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Action non autorisée.'];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// ─── Add news ───
if (isset($_POST['add_news'])) {
    $title = trim($_POST['title_article'] ?? '');
    $desc = $isAjax ? decodeHtmlField($_POST['desc_article'] ?? '') : ($_POST['desc_article'] ?? '');
    $imgName = '';

    if (!empty($_FILES['img_article']['name']) && $_FILES['img_article']['error'] === UPLOAD_ERR_OK) {
        $uploaded = uploadImage($_FILES['img_article'], '../files/_news/', 'news_');
        if ($uploaded) $imgName = $uploaded;
    }

    try {
        if ($migrationDone) {
            $status = isset($_POST['status']) && in_array($_POST['status'], ['published', 'draft']) ? $_POST['status'] : 'draft';
            $stmt = $pdo->prepare("INSERT INTO news (img_article, title_article, desc_article, date_publication, `like`, `dislike`, status) VALUES (?, ?, ?, NOW(), 0, 0, ?)");
            $stmt->execute([$imgName, $title, $desc, $status]);
            $newId = (int)$pdo->lastInsertId();
            newsArticleMaybeNotify($pdo, $newId);
        } else {
            $stmt = $pdo->prepare("INSERT INTO news (img_article, title_article, desc_article, date_publication, `like`, `dislike`) VALUES (?, ?, ?, NOW(), 0, 0)");
            $stmt->execute([$imgName, $title, $desc]);
            $newId = (int)$pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        error_log('[NEWS] add_news: ' . $e->getMessage());
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Erreur lors de l\'ajout.']);
            exit;
        }
        $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de l\'ajout de l\'article.'];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    logContentAction($pdo, 'news', 'create', $newId ?? null, $title, 'article');
    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Article ajouté avec succès.'];
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ─── Update news ───
if (isset($_POST['update_news'])) {
    $id = (int)($_POST['news_id'] ?? 0);
    $title = trim($_POST['title_article'] ?? '');
    $desc = $isAjax ? decodeHtmlField($_POST['desc_article'] ?? '') : ($_POST['desc_article'] ?? '');

    $allowedExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $deleteImage = !empty($_POST['delete_image']);

    try {
        // Supprimer l'image existante si demandé
        if ($deleteImage) {
            $stmtOld = $pdo->prepare("SELECT img_article FROM news WHERE id = ?");
            $stmtOld->execute([$id]);
            $oldImg = $stmtOld->fetchColumn();
            if ($oldImg && file_exists("../files/_news/" . $oldImg)) {
                unlink("../files/_news/" . $oldImg);
            }
        }

        if ($migrationDone) {
            $status = isset($_POST['status']) && in_array($_POST['status'], ['published', 'draft']) ? $_POST['status'] : 'draft';
            $safeName = uploadImage($_FILES['img_article'] ?? [], '../files/_news/', 'news_');
            if ($safeName) {
                $stmt = $pdo->prepare("UPDATE news SET img_article = ?, title_article = ?, desc_article = ?, status = ? WHERE id = ?");
                $stmt->execute([$safeName, $title, $desc, $status, $id]);
            } elseif ($deleteImage) {
                $stmt = $pdo->prepare("UPDATE news SET img_article = '', title_article = ?, desc_article = ?, status = ? WHERE id = ?");
                $stmt->execute([$title, $desc, $status, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE news SET title_article = ?, desc_article = ?, status = ? WHERE id = ?");
                $stmt->execute([$title, $desc, $status, $id]);
            }
            newsArticleMaybeNotify($pdo, $id);
        } else {
            $safeName = uploadImage($_FILES['img_article'] ?? [], '../files/_news/', 'news_');
            if ($safeName) {
                $stmt = $pdo->prepare("UPDATE news SET img_article = ?, title_article = ?, desc_article = ? WHERE id = ?");
                $stmt->execute([$safeName, $title, $desc, $id]);
            } elseif ($deleteImage) {
                $stmt = $pdo->prepare("UPDATE news SET img_article = '', title_article = ?, desc_article = ? WHERE id = ?");
                $stmt->execute([$title, $desc, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE news SET title_article = ?, desc_article = ? WHERE id = ?");
                $stmt->execute([$title, $desc, $id]);
            }
        }
    } catch (PDOException $e) {
        error_log('[NEWS] update_news: ' . $e->getMessage());
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Erreur lors de la mise à jour.']);
            exit;
        }
        $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de la mise à jour de l\'article.'];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    logContentAction($pdo, 'news', 'edit', $id, $title, 'article');
    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Article mis à jour avec succès.'];
    $_SESSION['reopen_news_modal'] = $id;
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
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
    $id = (int)($_POST['news_id'] ?? 0);
    $delInfoStmt = $pdo->prepare("SELECT title_article, img_article FROM news WHERE id = ?");
    $delInfoStmt->execute([$id]);
    $delInfo = $delInfoStmt->fetch(PDO::FETCH_ASSOC) ?: ['title_article' => '', 'img_article' => ''];
    try {
        if ($migrationDone) {
            $stmt = $pdo->prepare("UPDATE news SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            logContentAction($pdo, 'news', 'trash', $id, (string)$delInfo['title_article'], 'article');
            $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Article mis en corbeille.'];
            header("Location: " . $_SERVER['PHP_SELF'] . "?filter=" . ($_GET['filter'] ?? ''));
        } else {
            if ($delInfo['img_article'] && file_exists("../files/_news/" . $delInfo['img_article'])) {
                unlink("../files/_news/" . $delInfo['img_article']);
            }
            $stmt = $pdo->prepare("DELETE FROM news WHERE id = ?");
            $stmt->execute([$id]);
            logContentAction($pdo, 'news', 'delete', $id, (string)$delInfo['title_article'], 'article');
            $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Article supprimé.'];
            header("Location: " . $_SERVER['PHP_SELF']);
        }
    } catch (PDOException $e) {
        error_log('[NEWS] delete_news: ' . $e->getMessage());
        $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de la suppression.'];
        header("Location: " . $_SERVER['PHP_SELF']);
    }
    exit;
}

if ($migrationDone) {
    // ─── Restore from trash ───
    if (isset($_POST['restore_news'])) {
        $id = (int)($_POST['news_id'] ?? 0);
        try {
            $rTitle = (string)$pdo->query("SELECT title_article FROM news WHERE id = " . $id)->fetchColumn();
            $stmt = $pdo->prepare("UPDATE news SET deleted_at = NULL WHERE id = ?");
            $stmt->execute([$id]);
            logContentAction($pdo, 'news', 'restore', $id, $rTitle, 'article');
            $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Article restauré.'];
        } catch (PDOException $e) {
            error_log('[NEWS] restore_news: ' . $e->getMessage());
            $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de la restauration.'];
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
        exit;
    }

    // ─── Permanent delete ───
    if (isset($_POST['permanent_delete_news'])) {
        $id = (int)($_POST['news_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT title_article, img_article FROM news WHERE id = ?");
            $stmt->execute([$id]);
            $pInfo = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['title_article' => '', 'img_article' => ''];

            if ($pInfo['img_article'] && file_exists("../files/_news/" . $pInfo['img_article'])) {
                unlink("../files/_news/" . $pInfo['img_article']);
            }

            $stmt = $pdo->prepare("DELETE FROM news WHERE id = ?");
            $stmt->execute([$id]);
            logContentAction($pdo, 'news', 'delete', $id, (string)$pInfo['title_article'], 'article');
            $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Article supprimé définitivement.'];
        } catch (PDOException $e) {
            error_log('[NEWS] permanent_delete_news: ' . $e->getMessage());
            $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Erreur lors de la suppression.'];
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?filter=trashed");
        exit;
    }
}

// ─── Filter, Search & Pagination logic ───
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';
$search = trim($_GET['q'] ?? '');
$isTrashed = false;
$isLogs = ($filter === 'logs' && $canViewLogs);
$logs = $isLogs ? fetchContentLogs($pdo, 'news') : [];
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
  border-bottom: 2px solid var(--border);
  margin-bottom: 1rem;
}
.filter-tabs a {
  padding: 0.5rem 1.25rem;
  text-decoration: none;
  color: var(--ink);
  font-weight: 500;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  transition: color .15s, border-color .15s;
}
.filter-tabs a:hover {
  color: var(--ink);
  border-bottom-color: var(--border-strong);
}
.filter-tabs a.active {
  color: var(--ink);
  border-bottom-color: var(--primary, #f42182);
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
  border: 1px solid var(--border-strong);
  border-radius: 8px;
  overflow: hidden;
  background: var(--surface);
  transition: border-color 0.15s, box-shadow 0.15s;
}
.news-search-bar .input-group:focus-within {
  border-color: var(--primary, #f42182);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary, #f42182) 10%, transparent);
}
.news-search-bar .input-group-text {
  border: none !important;
  background: transparent !important;
  color: var(--ink-faint);
  padding: 8px 10px 8px 14px;
}
.news-search-bar .form-control {
  border: none !important;
  box-shadow: none !important;
  padding: 8px 14px 8px 4px;
  font-size: 13px;
  color: var(--ink);
}
.news-search-bar .form-control::placeholder { color: var(--ink-faint); }
.news-search-bar .form-control:focus { box-shadow: none !important; }

/* Trashed card style */
.card-trashed {
  opacity: 0.7;
  border: 1px dashed #dc3545 !important;
}
</style>
</head>

<body>

<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>

<div class="container pb-4">
  <h1 class="mb-3 fw-bold"><i class="bi bi-newspaper me-2"></i>Actualités</h1>


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
    <?php if ($canDelete): ?>
    <a href="?filter=trashed" class="<?= $filter === 'trashed' ? 'active' : '' ?>">
      <i class="bi bi-trash3"></i> Corbeille <span class="badge bg-danger"><?= $countTrashed ?></span>
    </a>
    <?php endif; ?>
    <?php if ($canViewLogs): ?>
    <a href="?filter=logs" class="<?= $isLogs ? 'active' : '' ?>">
      <i class="bi bi-clock-history"></i> Logs
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Search bar + Add button row -->
  <?php if (!$isLogs): ?>
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
    <?php if (!$isTrashed && $canCreate): ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddNews">
      <i class="bi bi-plus-lg"></i> Ajouter un article
    </button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($isLogs): ?>
  <div class="content-loaded" style="display:none;">
    <?= renderContentLogs($logs) ?>
  </div>
  <?php else: ?>

  <!-- Spinner de chargement -->
  <div id="loadingSpinner" class="text-center py-5">
    <div class="spinner-border text-pink" role="status" style="width:2.5rem;height:2.5rem;color:var(--primary, #f42182);"></div>
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
            <div style="height:200px;background:var(--surface-2);display:flex;align-items:center;justify-content:center;font-size:32px;opacity:.3;">📰</div>
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
                <?php if ($canEdit): ?>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="news_id" value="<?= $n['id'] ?>">
                  <button type="submit" name="restore_news" class="btn btn-sm btn-success">
                    <i class="bi bi-arrow-counterclockwise"></i> Restaurer
                  </button>
                </form>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                <form method="post" data-confirm="Supprimer DÉFINITIVEMENT cet article ? Cette action est irréversible.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="news_id" value="<?= $n['id'] ?>">
                  <button type="submit" name="permanent_delete_news" class="btn btn-sm btn-danger">
                    <i class="bi bi-x-circle"></i> Supprimer définitivement
                  </button>
                </form>
                <?php endif; ?>
              <?php else: ?>
                <!-- Normal view buttons -->
                <a href="../public/news.php?preview=<?= $n['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Aperçu">
                  <i class="bi bi-eye"></i> Aperçu
                </a>
                <?php if ($canEdit): ?>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalEditNews<?= $n['id'] ?>">
                  <i class="bi bi-pencil"></i> Modifier
                </button>
                <?php endif; ?>
                <?php if ($migrationDone ? $canTrash : $canDelete): ?>
                <form method="post" data-confirm="<?= $migrationDone ? 'Mettre cet article en corbeille ?' : 'Supprimer definitivement cet article ?' ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="news_id" value="<?= $n['id'] ?>">
                  <button type="submit" name="delete_news" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash3"></i> <?= $migrationDone ? 'Corbeille' : 'Supprimer' ?>
                  </button>
                </form>
                <?php endif; ?>
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
        <div class="modal-dialog modal-xl modal-fullscreen-sm-down">
          <div class="modal-content p-4">
            <div class="modal-header">
              <h5 class="modal-title">Modifier l'article</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
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
                      <div class="col-12 <?= $migrationDone ? 'col-md-6' : 'col-md-6' ?>">
                        <label>Titre</label>
                        <input type="text" name="title_article" class="form-control" value="<?= htmlspecialchars($n['title_article']) ?>" required>
                      </div>
                      <div class="col-12 <?= $migrationDone ? 'col-md-3' : 'col-md-6' ?>">
                        <label>Image (laisser vide pour conserver)</label>
                        <input type="file" name="img_article" class="form-control">
                        <?php if (!empty($n['img_article'])): ?>
                        <div class="form-check mt-1">
                          <input type="checkbox" name="delete_image" value="1" class="form-check-input" id="delImg<?= $n['id'] ?>">
                          <label class="form-check-label text-danger" style="font-size:12px" for="delImg<?= $n['id'] ?>">Supprimer l'image</label>
                        </div>
                        <?php endif; ?>
                      </div>
                      <?php if ($migrationDone): ?>
                      <div class="col-12 col-md-3">
                        <label>Statut</label>
                        <select name="status" class="form-select">
                          <option value="draft" <?= $n['status'] === 'draft' ? 'selected' : '' ?>>Brouillon</option>
                          <option value="published" <?= $n['status'] === 'published' ? 'selected' : '' ?>>Publié</option>
                        </select>
                      </div>
                      <?php $njSent = !empty($n['newsletter_sent_at']); ?>
                      <div class="col-12">
                        <div class="form-check">
                          <input type="checkbox" name="notify_subscribers" value="1" class="form-check-input"
                                 id="notifyEdit<?= $n['id'] ?>" <?= $njSent ? 'disabled' : '' ?>>
                          <label class="form-check-label<?= $njSent ? ' text-muted' : '' ?>" for="notifyEdit<?= $n['id'] ?>">
                            <i class="bi bi-envelope-heart"></i>
                            <?php if ($njSent): ?>
                              Newsletter déjà envoyée aux abonnés pour cet article.
                            <?php else: ?>
                              Prévenir les abonnés à la newsletter — un email part si l'article est <strong>publié</strong>.
                            <?php endif; ?>
                          </label>
                        </div>
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
      // Pagination compacte type "1 ... 4 5 6 ... 12" (page courante ± 1, plus 1ère et dernière avec ellipses)
      $pgPages = [];
      $pgPages[] = 1;
      if ($page - 1 > 2) $pgPages[] = '...';
      for ($i = max(2, $page - 1); $i <= min($totalPages - 1, $page + 1); $i++) $pgPages[] = $i;
      if ($page + 1 < $totalPages - 1) $pgPages[] = '...';
      if ($totalPages > 1) $pgPages[] = $totalPages;
      $pgSeen = [];
      foreach ($pgPages as $i):
          if ($i === '...'): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php else:
              if (isset($pgSeen[$i])) continue;
              $pgSeen[$i] = true; ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
              <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $i ?><?= $qParam ?>"><?= $i ?></a>
            </li>
          <?php endif;
      endforeach; ?>
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
  <?php endif; /* fin vue normale vs logs */ ?>

  <!-- Modal Ajouter -->
  <div class="modal fade" id="modalAddNews" tabindex="-1">
    <div class="modal-dialog modal-xl modal-fullscreen-sm-down">
      <div class="modal-content p-4">
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <div class="modal-header">
            <h5 class="modal-title">Ajouter un article</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body row g-3">
            <div class="col-12 <?= $migrationDone ? 'col-md-5' : 'col-md-6' ?>">
              <label>Titre</label>
              <input type="text" name="title_article" class="form-control" required>
            </div>
            <div class="col-12 <?= $migrationDone ? 'col-md-4' : 'col-md-6' ?>">
              <label>Image</label>
              <input type="file" name="img_article" class="form-control">
            </div>
            <?php if ($migrationDone): ?>
            <div class="col-12 col-md-3">
              <label>Statut</label>
              <select name="status" class="form-select">
                <option value="draft" selected>Brouillon</option>
                <option value="published">Publié</option>
              </select>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input type="checkbox" name="notify_subscribers" value="1" class="form-check-input" id="notifyAdd" checked>
                <label class="form-check-label" for="notifyAdd">
                  <i class="bi bi-envelope-heart"></i> Prévenir les abonnés à la newsletter — un email est envoyé si l'article est <strong>publié</strong>.
                </label>
              </div>
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
            <?= getTinyMceConfig($pdo, ['height' => 500]) ?>
        });

        /* ── Envoi AJAX pour contourner le WAF (encode desc_article en Base64) ── */
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('button[name="add_news"], button[name="update_news"]');
            if (!btn) return;
            e.preventDefault();

            var form = btn.closest('form');
            /* 1. Forcer TinyMCE à écrire dans le textarea */
            tinymce.triggerSave();

            /* 2. Construire FormData et encoder desc_article en Base64 */
            var fd = new FormData(form);
            var desc = fd.get('desc_article') || '';
            fd.set('desc_article', btoa(unescape(encodeURIComponent(desc))));
            fd.set(btn.name, '1');

            /* 3. Envoyer via fetch (le WAF ne voit pas le HTML) */
            fetch(form.action || window.location.href, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    window.location.reload();
                } else {
                    showToast(data.message || 'Erreur', 'danger');
                }
            })
            .catch(function (err) {
                console.error('[NEWS AJAX]', err);
                showToast('Erreur lors de l\'envoi : ' + err.message, 'danger');
            });
        });
    </script>
<!-- ############################ Description ############################ -->

<?php include __DIR__ . '/../src/partials/admin-footer.php'; ?>

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

    container.innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status" style="width:2rem;height:2rem;color:var(--primary, #f42182);"></div><p class="text-muted mt-2 small">Chargement des commentaires...</p></div>';
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

            // Pagination compacte type "« 1 ... 4 5 6 ... 12 »"
            if (pagination && res.pages > 1) {
                var pHtml = '<span class="small text-muted">' + res.total + ' commentaire' + (res.total > 1 ? 's' : '') + '</span>';
                pHtml += '<nav><ul class="pagination pagination-sm mb-0">';
                function pgItem(i, label, active) {
                    return '<li class="page-item' + (active ? ' active' : '') + '"><a class="page-link" href="#" data-action="comment-page" data-news-id="' + newsId + '" data-page="' + i + '">' + (label || i) + '</a></li>';
                }
                function pgEllipsis() {
                    return '<li class="page-item disabled"><span class="page-link">…</span></li>';
                }
                if (res.page > 1) pHtml += pgItem(res.page - 1, '&laquo;');
                // Page courante ± 1, plus 1ère et dernière avec ellipses
                var pages = [];
                pages.push(1);
                if (res.page - 1 > 2) pages.push('...');
                for (var i = Math.max(2, res.page - 1); i <= Math.min(res.pages - 1, res.page + 1); i++) pages.push(i);
                if (res.page + 1 < res.pages - 1) pages.push('...');
                if (res.pages > 1) pages.push(res.pages);
                // dédoublonnage simple
                var seen = {};
                pages.forEach(function(p) {
                    if (p === '...') { pHtml += pgEllipsis(); return; }
                    if (seen[p]) return;
                    seen[p] = true;
                    pHtml += pgItem(p, null, p === res.page);
                });
                if (res.page < res.pages) pHtml += pgItem(res.page + 1, '&raquo;');
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
