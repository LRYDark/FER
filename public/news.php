<?php
require '../config/config.php';
require_once '../config/csrf.php';
require_once '../config/tracker.php';
trackPageVisit();
require '../inc/navbar-data.php';

// Récupération des paramètres du site
try {
    $stmt = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => 1]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $data = [];
}

$titleAccueil = $data['titleAccueil'] ?? '';
$picture = $data['picture'] ?? '';
$titleColor = $data['title_color'] ?? '#ffffff';
$edition = $data['edition'] ?? '';
$footer = $data['footer'] ?? null;
$link_instagram = $data['link_instagram'] ?? null;
$link_facebook = $data['link_facebook'] ?? null;
$link_cancer = $data['link_cancer'] ?? null;

// ─── Check status/deleted_at columns exist ───
$hasStatusCol = false;
try { $pdo->query("SELECT status FROM news LIMIT 0"); $hasStatusCol = true; } catch (PDOException $e) {}

// ─── Mode preview (admin only) ───
$isPreview = false;
$previewId = isset($_GET['preview']) ? (int)$_GET['preview'] : 0;

// ─── Mode article unique ───
$articleId = $previewId ?: (isset($_GET['id']) ? (int)$_GET['id'] : 0);
$singleArticle = null;

if ($articleId > 0) {
    try {
        if ($previewId > 0) {
            // Preview mode: admin only, any status
            if (session_status() === PHP_SESSION_NONE) { session_start(); }
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                header('HTTP/1.0 403 Forbidden'); echo 'Accès refusé'; exit;
            }
            $isPreview = true;
            $stmtA = $pdo->prepare('SELECT * FROM news WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $stmtA->execute(['id' => $articleId]);
            $singleArticle = $stmtA->fetch(PDO::FETCH_ASSOC);
        } else {
            // Public mode: only published articles
            $pubSql = $hasStatusCol
                ? 'SELECT * FROM news WHERE id = :id AND deleted_at IS NULL AND status = \'published\' LIMIT 1'
                : 'SELECT * FROM news WHERE id = :id LIMIT 1';
            $stmtA = $pdo->prepare($pubSql);
            $stmtA->execute(['id' => $articleId]);
            $singleArticle = $stmtA->fetch(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $singleArticle = null;
    }
}

// ─── Mode listing ───
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 18;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? '';

$whereConditions = [];
$params = [];

// Only show published, non-deleted articles on public listing
if ($hasStatusCol) {
    $whereConditions[] = "deleted_at IS NULL AND status = 'published'";
}

if (!empty($search)) {
    $whereConditions[] = '(title_article LIKE :search OR desc_article LIKE :search)';
    $params[':search'] = "%$search%";
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

try {
    $countSql = "SELECT COUNT(*) as total FROM news $whereClause";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalArticles = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalArticles / $limit);

    if (!empty($search)) {
        $sql = "SELECT *,
                CASE
                    WHEN title_article LIKE :search THEN 1
                    WHEN desc_article LIKE :search THEN 2
                    ELSE 3
                END as search_priority
                FROM news $whereClause
                ORDER BY search_priority ASC, date_publication DESC
                LIMIT :limit OFFSET :offset";
    } else {
        $sql = "SELECT * FROM news $whereClause ORDER BY date_publication DESC LIMIT :limit OFFSET :offset";
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $totalArticles = 0;
    $totalPages = 0;
    $articles = [];
}

// Comptage des commentaires par article
$commentCounts = [];
try {
    $stmtCC = $pdo->query('SELECT news_id, COUNT(*) as cnt FROM news_comments GROUP BY news_id');
    foreach ($stmtCC->fetchAll(PDO::FETCH_ASSOC) as $ccRow) {
        $commentCounts[(int)$ccRow['news_id']] = (int)$ccRow['cnt'];
    }
} catch (Exception $e) {}

// Extraire la première image du contenu HTML si pas d'image de couverture
function getFirstContentImage(string $html): ?string {
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
        return $m[1];
    }
    return null;
}

// Traitement AJAX
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    ob_start();

    foreach ($articles as $article):
        if (empty($article['title_article'])) continue;
        $imgPath = '../files/_news/' . $article['img_article'];
        $hasImage = !empty($article['img_article']) && is_file($imgPath);
        $contentImg = null;
        if (!$hasImage) {
            $contentImg = getFirstContentImage($article['desc_article'] ?? '');
        }
        $dateFormatted = date('d/m/Y à H\hi', strtotime($article['date_publication']));
        $nbComments = $commentCounts[$article['id']] ?? 0;
        ?>
        <a href="news?id=<?= $article['id'] ?>" class="ncard">
            <div class="ncard-img">
                <?php if ($hasImage): ?>
                    <img src="<?= htmlspecialchars($imgPath) ?>" alt="<?= htmlspecialchars($article['title_article']) ?>" loading="lazy">
                <?php elseif ($contentImg): ?>
                    <img src="<?= htmlspecialchars($contentImg) ?>" alt="<?= htmlspecialchars($article['title_article']) ?>" loading="lazy">
                <?php else: ?>
                    <div class="ncard-placeholder">📰</div>
                <?php endif; ?>
            </div>
            <div class="ncard-body">
                <h3 class="ncard-title"><?= htmlspecialchars($article['title_article']) ?></h3>
                <div class="ncard-meta">
                    <span class="ncard-source">Forbach en Rose</span>
                    <span class="ncard-dot">&middot;</span>
                    <span class="ncard-date"><?= $dateFormatted ?></span>
                </div>
                <div class="ncard-bottom">
                    <div class="ncard-votes" data-stop-propagation>
                        <button class="nvote nvote-like" data-id="<?= $article['id'] ?>" data-action="vote">
                            👍 <span class="nvote-count"><?= $article['like'] ?></span>
                        </button>
                        <button class="nvote nvote-dislike" data-id="<?= $article['id'] ?>" data-action="vote">
                            👎 <span class="nvote-count"><?= $article['dislike'] ?></span>
                        </button>
                    </div>
                    <span class="ncard-comments-badge"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> <?= $nbComments ?></span>
                </div>
            </div>
        </a>
    <?php endforeach;

    $content = ob_get_clean();

    ob_start();
    if ($totalPages > 1): ?>
        <div class="news-pagination">
            <?php if ($page > 1): ?>
                <button class="pgbtn" data-page="<?= $page - 1 ?>">←</button>
            <?php endif; ?>
            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <button class="pgbtn <?= $i == $page ? 'active' : '' ?>" data-page="<?= $i ?>"><?= $i ?></button>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <button class="pgbtn" data-page="<?= $page + 1 ?>">→</button>
            <?php endif; ?>
            <span class="pginfo">Page <?= $page ?>/<?= $totalPages ?> (<?= $totalArticles ?>)</span>
        </div>
    <?php endif;

    $pagination = ob_get_clean();

    echo json_encode(['content' => $content, 'pagination' => $pagination]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
  <title><?= $singleArticle ? htmlspecialchars($singleArticle['title_article']) : 'Actualités' ?></title>
  <link rel="stylesheet" href="../css/fer-modern.css">
  <style>
    .floating-nav { border-bottom: 1px solid rgba(0,0,0,0.06); }

    /* ─── Top bar (album-reg-bar style) ─── */
    .news-hero {
      width: 100%;
      margin: 174px auto 0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }
    @media (max-width: 980px) {
      .news-hero { margin-top: 16px; }
    }

    .news-title-bar {
      display: flex;
      align-items: center;
      gap: 16px;
      flex-shrink: 0;
    }
    .news-title-bar .back-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 40px;
      height: 40px;
      border-radius: 10px;
      background: #0f172a;
      color: #fff;
      text-decoration: none;
      transition: all .25s ease;
      flex-shrink: 0;
    }
    .news-title-bar .back-btn:hover {
      background: var(--pink);
    }
    .news-title-bar-title {
      margin: 0;
      color: var(--page-text);
      font-size: clamp(24px, 3.5vw, 32px);
      font-weight: 800;
      letter-spacing: -0.03em;
      line-height: 1.2;
    }

    /* ─── Search bar (aligned right on listing) ─── */
    .news-search-bar {
      position: relative;
      width: min(100%, 340px);
      flex-shrink: 0;
    }
    .news-search-bar input {
      width: 100%;
      height: 48px;
      border: 1px solid rgba(15,23,42,.12);
      border-radius: 12px;
      padding: 0 42px 0 16px;
      font-size: 15px;
      font-family: inherit;
      color: var(--page-text);
      background: rgba(15,23,42,.03);
      outline: none;
      transition: border-color .2s, box-shadow .2s;
    }
    .news-search-bar input:focus {
      border-color: var(--pink);
      box-shadow: 0 0 0 3px rgba(236,72,153,.1);
    }
    .news-search-bar input::placeholder { color: rgba(15,23,42,.4); }
    .news-search-clear {
      position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
      width: 28px; height: 28px;
      border: none; background: rgba(15,23,42,.08); border-radius: 8px;
      color: rgba(15,23,42,.5); font-size: 14px; cursor: pointer;
      display: none; align-items: center; justify-content: center;
      transition: background .15s;
    }
    .news-search-clear.show { display: flex; }
    .news-search-clear:hover { background: rgba(15,23,42,.14); }
    @media (max-width: 640px) {
      .news-hero { flex-wrap: wrap; }
      .news-search-bar { width: 100%; }
    }

    /* ─── Votes bar (article mode – top right) ─── */
    .news-hero-votes {
      display: flex; align-items: center; gap: 8px;
      flex-shrink: 0;
    }
    .hero-vote {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 8px 14px;
      background: rgba(15,23,42,.04); border: 1px solid rgba(15,23,42,.10);
      border-radius: 10px; cursor: pointer;
      font-size: 15px; font-weight: 500; color: var(--page-text);
      transition: background .15s, transform .1s;
      user-select: none;
    }
    .hero-vote:hover { background: rgba(15,23,42,.08); }
    .hero-vote:active { transform: scale(.96); }
    .hero-vote.voted { background: rgba(236,72,153,.10); border-color: rgba(236,72,153,.25); }

    /* ─── Cards grid (3 colonnes, vignette + texte) ─── */
    .ncards {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 20px 24px;
      margin-top: 32px;
    }
    .ncard {
      display: flex;
      align-items: flex-start;
      gap: 14px;
      padding: 14px;
      background: #fff;
      border-radius: 14px;
      text-decoration: none;
      color: var(--page-text);
      transition: background .18s ease;
    }
    .ncard:hover {
      background: rgba(15,23,42,.04);
    }
    .ncard-img {
      flex: 0 0 160px;
      height: 120px;
      border-radius: 12px;
      overflow: hidden;
      background: rgba(15,23,42,.06);
    }
    .ncard-img img {
      width: 100%; height: 100%;
      object-fit: cover; display: block;
    }
    .ncard-placeholder {
      width: 100%; height: 100%;
      display: flex; align-items: center; justify-content: center;
      font-size: 32px; opacity: .3;
    }
    .ncard-body {
      flex: 1; min-width: 0;
      display: flex; flex-direction: column;
      gap: 5px;
    }
    .ncard-title {
      margin: 0;
      font-size: 15px;
      font-weight: 700; line-height: 1.35;
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .ncard-meta {
      display: flex; align-items: center; gap: 5px;
      font-size: 12.5px; color: rgba(15,23,42,.45); font-weight: 500;
      flex-wrap: wrap;
    }
    .ncard-source { color: var(--pink); font-weight: 600; }
    .ncard-dot { font-size: 8px; }
    .ncard-bottom {
      display: flex; align-items: center; justify-content: space-between;
      gap: 8px; margin-top: 2px;
    }
    .ncard-votes {
      display: flex; gap: 6px;
    }
    .ncard-comments-badge {
      display: inline-flex; align-items: center; gap: 4px;
      font-size: 12px; font-weight: 600;
      color: rgba(15,23,42,.4);
      white-space: nowrap;
    }
    .ncard-comments-badge svg { opacity: .6; }
    .nvote {
      display: inline-flex; align-items: center; gap: 3px;
      padding: 3px 8px;
      background: rgba(15,23,42,.05); border: none; border-radius: 7px;
      cursor: pointer; font-size: 13px; font-weight: 500; color: var(--page-text);
      transition: background .15s, transform .1s;
    }
    .nvote:hover { background: rgba(15,23,42,.1); }
    .nvote:active { transform: scale(.94); }
    .nvote.voted { background: rgba(236,72,153,.12); }
    .nvote-count { font-size: 12px; }

    @media (max-width: 980px) {
      .ncards { grid-template-columns: repeat(2, 1fr); gap: 14px; }
    }
    @media (max-width: 640px) {
      .ncards { grid-template-columns: 1fr; gap: 10px; }
      .ncard-img { flex: 0 0 110px; height: 90px; }
    }

    /* ─── Pagination ─── */
    .news-pagination {
      display: flex; align-items: center; justify-content: center;
      gap: 6px; margin-top: 36px; flex-wrap: wrap;
    }
    .pgbtn {
      min-width: 40px; height: 40px;
      border: 1px solid rgba(15,23,42,.12); border-radius: 10px;
      background: #fff; color: var(--page-text);
      font-size: 14px; font-weight: 600; cursor: pointer;
      transition: all .15s;
    }
    .pgbtn:hover { background: rgba(15,23,42,.06); }
    .pgbtn.active { background: var(--page-text); color: #fff; border-color: var(--page-text); }
    .pginfo { font-size: 13px; color: rgba(15,23,42,.45); margin-left: 8px; }

    /* ─── Spinner ─── */
    .news-spinner {
      display: none; text-align: center; padding: 40px 0;
    }
    .news-spinner.show { display: block; }
    .news-spinner::after {
      content: ''; display: inline-block;
      width: 32px; height: 32px;
      border: 3px solid rgba(15,23,42,.1);
      border-top-color: var(--pink);
      border-radius: 50%;
      animation: spin .6s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ─── Article detail ─── */
    .article-detail {
      margin-top: 32px;
      max-width: 900px;
      margin-left: auto;
      margin-right: auto;
    }
    .article-img {
      width: 100%;
      border-radius: 16px;
      margin-bottom: 28px;
    }
    .article-title {
      font-size: clamp(24px, 3.5vw, 38px);
      font-weight: 800;
      letter-spacing: -.02em;
      line-height: 1.2;
      margin: 0 0 12px;
    }
    .article-meta {
      display: flex; align-items: center; gap: 8px;
      font-size: 14px; color: rgba(15,23,42,.5); font-weight: 500;
      margin-bottom: 28px;
    }
    .article-content {
      font-size: 16px;
      line-height: 1.75;
      color: rgba(15,23,42,.85);
      max-width: 780px;
    }
    .article-content p { margin: 0 0 16px; }
    .article-content table {
      border-collapse: collapse;
      width: 100%;
      margin: 0 0 16px;
    }
    .article-content td, .article-content th {
      border: 1px solid #ddd;
      padding: 8px 12px;
    }
    .article-content img {
      max-width: 100%;
      height: auto;
      border-radius: 8px;
      cursor: pointer;
      transition: transform .2s ease, box-shadow .2s ease;
    }
    .article-content img:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(0,0,0,.12);
    }

    /* Bouton PDF */
    .pdf-link {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      padding: 6px 14px;
      background: #fff;
      border: 1px solid rgba(15,23,42,.12);
      border-radius: 12px;
      color: #0f172a;
      font-size: 14px;
      text-decoration: none;
      box-shadow: 0 1px 3px rgba(0,0,0,.04);
      transition: all .2s ease;
      margin: 8px 0;
    }
    .pdf-link:hover {
      border-color: #ec4899;
      box-shadow: 0 4px 16px rgba(236,72,153,.12);
      transform: translateY(-1px);
    }
    .pdf-link-icon {
      display: flex; align-items: center; justify-content: center;
      width: 38px; height: 38px;
      background: rgba(236,72,153,.08);
      border-radius: 10px; flex-shrink: 0;
    }
    .pdf-link:hover .pdf-link-icon { background: rgba(236,72,153,.14); }
    .pdf-link-icon svg { width: 20px; height: 20px; stroke: #ec4899; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .pdf-link-info { display: flex; flex-direction: column; gap: 2px; }
    .pdf-link-name { font-weight: 600; line-height: 1.3; color: #0f172a; }
    .pdf-link-hint { font-size: 12px; color: rgba(15,23,42,.45); }

    /* Lightbox */
    .tiny-lightbox {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,.9); z-index: 99999;
      align-items: center; justify-content: center; padding: 20px;
    }
    .tiny-lightbox.active { display: flex; }
    .tiny-lightbox-close {
      position: absolute; top: 30px; right: 40px;
      font-size: 48px; color: #fff; cursor: pointer;
      user-select: none; transition: transform .2s ease;
    }
    .tiny-lightbox-close:hover { transform: scale(1.1); }
    .tiny-lightbox-img {
      max-width: 90%; max-height: 90%;
      border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,.5);
    }

    /* ─── Separator + Share ─── */
    .article-separator {
      display: flex; align-items: center; justify-content: space-between;
      margin-top: 40px; padding-top: 24px;
      border-top: 1px solid rgba(15,23,42,.08);
    }
    .share-buttons {
      display: flex; align-items: center; gap: 8px;
    }
    .share-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 6px;
      padding: 7px 14px;
      border: 1px solid rgba(15,23,42,.10);
      border-radius: 10px;
      background: rgba(15,23,42,.03);
      color: var(--page-text);
      font-size: 13px; font-weight: 600;
      cursor: pointer; text-decoration: none;
      transition: background .15s, transform .1s;
    }
    .share-btn:hover { background: rgba(15,23,42,.08); }
    .share-btn:active { transform: scale(.95); }
    .share-btn svg { flex-shrink: 0; }
    .share-btn.copied { background: rgba(16,185,129,.12); border-color: rgba(16,185,129,.3); color: #059669; }

    /* ─── Comments section ─── */
    .comments-section { margin-top: 32px; }
    .comments-header {
      display: flex; align-items: center; gap: 8px;
      margin-bottom: 20px;
    }
    .comments-header h3 {
      margin: 0; font-size: 18px; font-weight: 700;
      color: var(--page-text);
    }
    .comments-count {
      display: inline-flex; align-items: center; justify-content: center;
      min-width: 24px; height: 24px; padding: 0 7px;
      background: rgba(15,23,42,.08); border-radius: 99px;
      font-size: 12px; font-weight: 700; color: rgba(15,23,42,.6);
    }

    /* Comment form */
    .comment-form {
      display: flex; flex-direction: column; gap: 10px;
      margin-bottom: 28px;
      padding: 16px; border-radius: 14px;
      background: rgba(15,23,42,.025);
      border: 1px solid rgba(15,23,42,.06);
    }
    .comment-form-row {
      display: flex; gap: 10px;
    }
    .comment-form input,
    .comment-form textarea {
      width: 100%;
      border: 1px solid rgba(15,23,42,.10);
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 14px; font-family: inherit;
      color: var(--page-text);
      background: #fff;
      outline: none;
      transition: border-color .2s, box-shadow .2s;
      resize: none;
    }
    .comment-form input:focus,
    .comment-form textarea:focus {
      border-color: var(--pink);
      box-shadow: 0 0 0 3px rgba(236,72,153,.08);
    }
    .comment-form input { flex: 1; height: 42px; }
    .comment-form textarea { min-height: 70px; }
    .comment-form-actions {
      display: flex; justify-content: flex-end;
    }
    .comment-submit {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 9px 20px;
      background: var(--page-text); color: #fff;
      border: none; border-radius: 10px;
      font-size: 14px; font-weight: 600;
      cursor: pointer;
      transition: opacity .15s, transform .1s;
    }
    .comment-submit:hover { opacity: .85; }
    .comment-submit:active { transform: scale(.97); }
    .comment-submit:disabled { opacity: .5; cursor: not-allowed; }

    /* Comment items */
    .comment-list { display: flex; flex-direction: column; gap: 0; }
    .comment-item {
      display: flex; gap: 12px;
      padding: 16px 0;
      border-bottom: 1px solid rgba(15,23,42,.06);
    }
    .comment-item:last-child { border-bottom: none; }
    .comment-avatar {
      flex: 0 0 36px; width: 36px; height: 36px;
      border-radius: 50%;
      background: var(--pink);
      color: #fff;
      display: flex; align-items: center; justify-content: center;
      font-size: 15px; font-weight: 700;
      text-transform: uppercase;
      user-select: none;
    }
    .comment-body { flex: 1; min-width: 0; }
    .comment-head {
      display: flex; align-items: center; gap: 8px;
      margin-bottom: 4px;
    }
    .comment-author {
      font-size: 14px; font-weight: 700;
      color: var(--page-text);
    }
    .comment-date {
      font-size: 12px; color: rgba(15,23,42,.4);
      font-weight: 500;
    }
    .comment-text {
      font-size: 14px; line-height: 1.55;
      color: rgba(15,23,42,.8);
      margin-bottom: 8px;
      word-break: break-word;
    }
    .comment-actions {
      display: flex; align-items: center; gap: 12px;
      flex-wrap: wrap;
    }
    .comment-action-btn {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 3px 8px;
      background: none; border: none;
      border-radius: 6px;
      font-size: 12.5px; font-weight: 600;
      color: rgba(15,23,42,.45);
      cursor: pointer;
      transition: color .15s, background .15s;
    }
    .comment-action-btn:hover { color: var(--page-text); background: rgba(15,23,42,.05); }
    .comment-action-btn.liked { color: var(--pink); }
    .comment-action-btn .like-count { font-weight: 700; }

    /* Toggle replies button */
    .comment-toggle-replies {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 3px 10px;
      background: none; border: none;
      border-radius: 6px;
      font-size: 12.5px; font-weight: 600;
      color: var(--pink);
      cursor: pointer;
      transition: background .15s;
      margin-left: auto;
    }
    .comment-toggle-replies:hover { background: rgba(236,72,153,.06); }
    .toggle-replies-arrow {
      transition: transform .2s;
    }
    .comment-toggle-replies.open .toggle-replies-arrow {
      transform: rotate(180deg);
    }

    /* Replies */
    .comment-replies {
      margin-left: 48px;
      border-left: 2px solid rgba(15,23,42,.06);
      padding-left: 16px;
    }
    .comment-replies .comment-item {
      padding: 12px 0;
    }
    .comment-replies .comment-avatar {
      flex: 0 0 28px; width: 28px; height: 28px;
      font-size: 12px;
    }

    /* Reply form inline */
    .reply-form-inline {
      display: flex; flex-direction: column; gap: 8px;
      margin: 8px 0 4px 48px;
      padding: 12px;
      background: rgba(15,23,42,.025);
      border: 1px solid rgba(15,23,42,.06);
      border-radius: 12px;
    }
    .reply-form-inline textarea {
      width: 100%;
      border: 1px solid rgba(15,23,42,.10);
      border-radius: 8px;
      padding: 8px 12px;
      font-size: 13px; font-family: inherit;
      color: var(--page-text);
      background: #fff;
      outline: none; resize: none;
      min-height: 50px;
      transition: border-color .2s;
    }
    .reply-form-inline textarea:focus { border-color: var(--pink); }
    .reply-form-actions {
      display: flex; justify-content: flex-end; gap: 8px;
    }
    .reply-cancel {
      padding: 6px 14px;
      background: none; border: 1px solid rgba(15,23,42,.12);
      border-radius: 8px;
      font-size: 13px; font-weight: 600;
      color: rgba(15,23,42,.6);
      cursor: pointer;
      transition: background .15s;
    }
    .reply-cancel:hover { background: rgba(15,23,42,.05); }
    .reply-submit {
      padding: 6px 14px;
      background: var(--page-text); color: #fff;
      border: none; border-radius: 8px;
      font-size: 13px; font-weight: 600;
      cursor: pointer;
      transition: opacity .15s;
    }
    .reply-submit:hover { opacity: .85; }
    .reply-submit:disabled { opacity: .5; cursor: not-allowed; }

    /* Comment error/info */
    .comment-msg {
      padding: 10px 14px;
      border-radius: 10px;
      font-size: 13px; font-weight: 500;
      margin-bottom: 12px;
      display: none;
      white-space: pre-line;
    }
    .comment-msg.error, .reply-msg.error { display: block; background: rgba(239,68,68,.08); color: #dc2626; border: 1px solid rgba(239,68,68,.15); }
    .comment-msg.success, .reply-msg.success { display: block; background: rgba(16,185,129,.08); color: #059669; border: 1px solid rgba(16,185,129,.15); }
    .reply-msg { padding: 8px 12px; border-radius: 8px; font-size: 12px; font-weight: 500; margin-bottom: 8px; display: none; }

    /* @Mentions */
    .comment-mention {
      color: var(--pink);
      font-weight: 700;
      cursor: default;
    }
    .mention-dropdown {
      position: absolute;
      z-index: 999;
      background: #fff;
      border: 1px solid rgba(15,23,42,.12);
      border-radius: 10px;
      box-shadow: 0 4px 16px rgba(0,0,0,.10);
      max-height: 160px;
      overflow-y: auto;
      display: none;
      min-width: 140px;
    }
    .mention-dropdown.show { display: block; }
    .mention-item {
      padding: 8px 14px;
      font-size: 14px;
      font-weight: 500;
      color: var(--page-text);
      cursor: pointer;
      transition: background .12s;
    }
    .mention-item:first-child { border-radius: 10px 10px 0 0; }
    .mention-item:last-child { border-radius: 0 0 10px 10px; }
    .mention-item:only-child { border-radius: 10px; }
    .mention-item:hover,
    .mention-item.active {
      background: rgba(236,72,153,.08);
      color: var(--pink);
    }

    .comments-empty {
      text-align: center; padding: 32px 16px;
      color: rgba(15,23,42,.35);
      font-size: 14px; font-weight: 500;
    }

    @media (max-width: 640px) {
      .article-separator { flex-direction: column; align-items: flex-start; gap: 12px; }
      .comment-form-row { flex-direction: column; }
      .comment-replies { margin-left: 24px; padding-left: 12px; }
      .reply-form-inline { margin-left: 24px; }
      .share-btn span { display: none; }
    }
  </style>
</head>
<body>

<?php include '../inc/navbar-modern.php'; ?>

<main>

<?php if ($singleArticle): ?>
  <?php
    $imgPath = '../files/_news/' . $singleArticle['img_article'];
    $hasImg = !empty($singleArticle['img_article']) && is_file($imgPath);
    $dateFormatted = date('d/m/Y à H\hi', strtotime($singleArticle['date_publication']));
  ?>

  <!-- ─── Article: top bar ─── -->
  <section class="news-hero">
    <div class="news-title-bar">
      <a href="news" title="Retour" class="back-btn">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="#ffffff"><path d="M3.3 11.3l6.8-6.8c.4-.4.4-1 0-1.4s-1-.4-1.4 0l-7.8 7.8c-.4.4-.4 1 0 1.4l7.8 7.8c.2.2.5.3.7.3s.5-.1.7-.3c.4-.4.4-1 0-1.4L3.3 12.7H22c.6 0 1-.4 1-1s-.4-1-1-1H3.3z"/></svg>
      </a>
      <h1 class="news-title-bar-title">Actualités</h1>
    </div>

    <div class="news-hero-votes">
      <button class="hero-vote nvote-like" data-id="<?= $singleArticle['id'] ?>" data-action="vote">
        👍 <span class="nvote-count"><?= $singleArticle['like'] ?></span>
      </button>
      <button class="hero-vote nvote-dislike" data-id="<?= $singleArticle['id'] ?>" data-action="vote">
        👎 <span class="nvote-count"><?= $singleArticle['dislike'] ?></span>
      </button>
    </div>
  </section>

  <?php if ($isPreview): ?>
    <div style="background:#fd7e14;color:#fff;text-align:center;padding:10px;font-weight:600;font-size:14px;margin-top:12px;border-radius:8px;max-width:1200px;margin-left:auto;margin-right:auto;">
      <i class="bi bi-eye"></i> Aperçu – Cet article n'est pas encore publié
    </div>
  <?php endif; ?>

  <!-- ─── Article content ─── -->
  <div class="article-detail">
    <h2 class="article-title"><?= htmlspecialchars($singleArticle['title_article']) ?></h2>

    <?php if ($hasImg): ?>
      <img src="<?= htmlspecialchars($imgPath) ?>" alt="<?= htmlspecialchars($singleArticle['title_article']) ?>" class="article-img">
    <?php endif; ?>

    <div class="article-meta">
      <span class="ncard-source">Forbach en Rose</span>
      <span class="ncard-dot">&middot;</span>
      <span><?= $dateFormatted ?></span>
    </div>

    <div class="article-content">
      <?= sanitizeHtml($singleArticle['desc_article'] ?? '') ?>
    </div>

    <!-- Separator + Share -->
    <div class="article-separator">
      <div></div>
      <div class="share-buttons">
        <button class="share-btn" data-action="share-facebook" title="Partager sur Facebook">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
          <span>Facebook</span>
        </button>
        <button class="share-btn" data-action="share-x" title="Partager sur X">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
          <span>X</span>
        </button>
        <button class="share-btn" data-action="share-instagram" title="Partager sur Instagram">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
          <span>Instagram</span>
        </button>
        <button class="share-btn" id="copyLinkBtn" data-action="copy-link" title="Copier le lien">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
          <span>Copier</span>
        </button>
      </div>
    </div>

    <!-- Comments section -->
    <div class="comments-section" id="commentsSection" data-news-id="<?= $singleArticle['id'] ?>">
      <div class="comments-header">
        <h3>Commentaires</h3>
        <span class="comments-count" id="commentsCount">0</span>
      </div>

      <div class="comment-msg" id="commentMsg"></div>

      <div class="comment-form" id="commentForm">
        <div class="comment-form-row">
          <input type="text" id="commentName" placeholder="Votre nom" maxlength="100">
        </div>
        <textarea id="commentContent" placeholder="Ecrire un commentaire..." maxlength="2000"></textarea>
        <div class="comment-form-actions">
          <button class="comment-submit" data-action="submit-comment">Publier</button>
        </div>
      </div>

      <div class="mention-dropdown" id="mentionDropdown"></div>

      <div class="comment-list" id="commentList">
        <div class="comments-empty">Chargement des commentaires...</div>
      </div>
      <div id="commentsLoadMore" style="text-align:center;padding:16px 0;display:none;">
        <div class="spinner-border spinner-border-sm" style="color:var(--pink);width:20px;height:20px;" role="status"></div>
      </div>
    </div>
  </div>

<?php else: ?>

  <!-- ─── Listing: top bar with search ─── -->
  <section class="news-hero">
    <div class="news-title-bar">
      <h1 class="news-title-bar-title">Actualités</h1>
    </div>

    <div class="news-search-bar">
      <input type="text" id="searchInput" placeholder="Rechercher une actualité..." value="<?= htmlspecialchars($search) ?>">
      <button type="button" id="searchClear" class="news-search-clear">✕</button>
    </div>
  </section>

  <!-- Spinner -->
  <div class="news-spinner" id="loadingSpinner"></div>

  <!-- Cards list -->
  <div class="ncards" id="articlesContainer">
    <?php if (empty($articles)): ?>
      <div style="text-align:center;padding:60px 20px;color:var(--page-muted);grid-column:1/-1;">
        <p>Aucune actualité pour le moment.</p>
      </div>
    <?php endif; ?>
    <?php foreach ($articles as $article): ?>
      <?php
        if (empty($article['title_article'])) continue;
        $imgPath = '../files/_news/' . $article['img_article'];
        $hasImage = !empty($article['img_article']) && is_file($imgPath);
        $contentImg = null;
        if (!$hasImage) {
            $contentImg = getFirstContentImage($article['desc_article'] ?? '');
        }
        $dateFormatted = date('d/m/Y à H\hi', strtotime($article['date_publication']));
        $nbComments = $commentCounts[$article['id']] ?? 0;
      ?>
      <a href="news?id=<?= $article['id'] ?>" class="ncard">
        <div class="ncard-img">
          <?php if ($hasImage): ?>
            <img src="<?= htmlspecialchars($imgPath) ?>" alt="<?= htmlspecialchars($article['title_article']) ?>" loading="lazy">
          <?php elseif ($contentImg): ?>
            <img src="<?= htmlspecialchars($contentImg) ?>" alt="<?= htmlspecialchars($article['title_article']) ?>" loading="lazy">
          <?php else: ?>
            <div class="ncard-placeholder">📰</div>
          <?php endif; ?>
        </div>
        <div class="ncard-body">
          <h3 class="ncard-title"><?= htmlspecialchars($article['title_article']) ?></h3>
          <div class="ncard-meta">
            <span class="ncard-source">Forbach en Rose</span>
            <span class="ncard-dot">&middot;</span>
            <span class="ncard-date"><?= $dateFormatted ?></span>
          </div>
          <div class="ncard-bottom">
            <div class="ncard-votes" data-stop-propagation>
              <button class="nvote nvote-like" data-id="<?= $article['id'] ?>" data-action="vote">
                👍 <span class="nvote-count"><?= $article['like'] ?></span>
              </button>
              <button class="nvote nvote-dislike" data-id="<?= $article['id'] ?>" data-action="vote">
                👎 <span class="nvote-count"><?= $article['dislike'] ?></span>
              </button>
            </div>
            <span class="ncard-comments-badge"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> <?= $nbComments ?></span>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Pagination -->
  <div id="paginationContainer">
    <?php if ($totalPages > 1): ?>
      <div class="news-pagination">
        <?php if ($page > 1): ?>
          <button class="pgbtn" data-page="<?= $page - 1 ?>">←</button>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
        for ($i = $start; $i <= $end; $i++):
        ?>
          <button class="pgbtn <?= $i == $page ? 'active' : '' ?>" data-page="<?= $i ?>"><?= $i ?></button>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <button class="pgbtn" data-page="<?= $page + 1 ?>">→</button>
        <?php endif; ?>
        <span class="pginfo">Page <?= $page ?>/<?= $totalPages ?> (<?= $totalArticles ?>)</span>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>

</main>

<?php include '../inc/footer-modern.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
// ─── CSRF token pour tous les AJAX POST ───
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
$.ajaxSetup({
    beforeSend: function(xhr, settings) {
        if (settings.type && settings.type.toUpperCase() === 'POST') {
            xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
        }
    }
});

// ─── Vote system ───
function getVoteCookie(id) {
    const name = 'vote_' + id + '=';
    const decoded = decodeURIComponent(document.cookie);
    const parts = decoded.split(';');
    for (let i = 0; i < parts.length; i++) {
        let c = parts[i].trim();
        if (c.indexOf(name) === 0) return c.substring(name.length);
    }
    return null;
}
function setVoteCookie(id, type) {
    const d = new Date();
    d.setFullYear(d.getFullYear() + 1);
    document.cookie = 'vote_' + id + '=' + type + '; expires=' + d.toUTCString() + '; path=/';
}

function handleVote(btn) {
    const id = btn.dataset.id;
    const isLike = btn.classList.contains('nvote-like');
    const type = isLike ? 'like' : 'dislike';
    const currentVote = getVoteCookie(id);

    if (currentVote === type) return;

    btn.style.transform = 'scale(.92)';
    setTimeout(() => { btn.style.transform = ''; }, 120);

    $.ajax({
        url: 'news_action.php',
        type: 'POST',
        dataType: 'json',
        data: { id: id, type: type, remove: currentVote },
        success: function(res) {
            if (!res.success) return;
            // Update all buttons for this article
            document.querySelectorAll('.nvote-like[data-id="' + id + '"] .nvote-count, .hero-vote.nvote-like[data-id="' + id + '"] .nvote-count').forEach(el => el.textContent = res.count.like);
            document.querySelectorAll('.nvote-dislike[data-id="' + id + '"] .nvote-count, .hero-vote.nvote-dislike[data-id="' + id + '"] .nvote-count').forEach(el => el.textContent = res.count.dislike);

            document.querySelectorAll('[data-id="' + id + '"].nvote, [data-id="' + id + '"].hero-vote').forEach(el => el.classList.remove('voted'));
            document.querySelectorAll('.nvote-' + type + '[data-id="' + id + '"]').forEach(el => el.classList.add('voted'));

            setVoteCookie(id, type);
        }
    });
}

function markVotedButtons() {
    document.querySelectorAll('.nvote[data-id], .hero-vote[data-id]').forEach(btn => {
        const id = btn.dataset.id;
        const type = btn.classList.contains('nvote-like') ? 'like' : 'dislike';
        if (getVoteCookie(id) === type) btn.classList.add('voted');
    });
}

markVotedButtons();

<?php if ($singleArticle): ?>
// ─── Comments system (article mode) ───
var newsId = <?= $singleArticle['id'] ?>;

// Restore saved name from localStorage
(function() {
    var saved = localStorage.getItem('comment_name');
    if (saved) document.getElementById('commentName').value = saved;
})();

function timeAgo(dateStr) {
    var now = new Date();
    var date = new Date(dateStr.replace(' ', 'T'));
    var diff = Math.floor((now - date) / 1000);
    if (diff < 60) return 'A l\'instant';
    if (diff < 3600) return Math.floor(diff / 60) + ' min';
    if (diff < 86400) return Math.floor(diff / 3600) + ' h';
    if (diff < 2592000) return Math.floor(diff / 86400) + ' j';
    if (diff < 31536000) return Math.floor(diff / 2592000) + ' mois';
    return Math.floor(diff / 31536000) + ' an(s)';
}

function getLikeCookie(id) {
    return document.cookie.split(';').some(function(c) { return c.trim().indexOf('clike_' + id + '=1') === 0; });
}
function setLikeCookie(id) {
    var d = new Date(); d.setFullYear(d.getFullYear() + 1);
    document.cookie = 'clike_' + id + '=1; expires=' + d.toUTCString() + '; path=/';
}
function removeLikeCookie(id) {
    document.cookie = 'clike_' + id + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/';
}

function renderComment(c, isReply, replyCount) {
    var initial = (c.author_name || '?').charAt(0);
    var liked = getLikeCookie(c.id);
    var likeCount = parseInt(c.likes) || 0;
    var html = '<div class="comment-item" data-id="' + c.id + '">';
    html += '<div class="comment-avatar">' + initial + '</div>';
    html += '<div class="comment-body">';
    html += '<div class="comment-head">';
    html += '<span class="comment-author">' + escapeHtml(c.author_name) + '</span>';
    html += '<span class="comment-date">' + timeAgo(c.created_at) + '</span>';
    html += '</div>';
    html += '<div class="comment-text">' + highlightMentions(escapeHtml(c.content)).replace(/\n/g, '<br>') + '</div>';
    html += '<div class="comment-actions">';
    html += '<button class="comment-action-btn' + (liked ? ' liked' : '') + '" data-action="like-comment" data-comment-id="' + c.id + '">';
    html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="' + (liked ? 'var(--pink)' : 'none') + '" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
    html += ' <span class="like-count">' + (likeCount > 0 ? likeCount : '') + '</span>';
    html += '</button>';
    if (!isReply) {
        html += '<button class="comment-action-btn" data-action="show-reply" data-comment-id="' + c.id + '">Repondre</button>';
        if (replyCount > 0) {
            html += '<button class="comment-toggle-replies" data-action="toggle-replies" data-comment-id="' + c.id + '">';
            html += '<span class="toggle-replies-count">' + replyCount + ' reponse' + (replyCount > 1 ? 's' : '') + '</span>';
            html += '<svg class="toggle-replies-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
            html += '</button>';
        }
    }
    html += '</div>';
    html += '</div></div>';
    return html;
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function highlightMentions(html) {
    return html.replace(/@([\w\u00C0-\u024F\-\.]+)/g, '<span class="comment-mention">@$1</span>');
}

// ─── @Mention autocomplete ───
var mentionNames = [];
var mentionActive = false;
var mentionStart = -1;
var mentionTarget = null;
var mentionIndex = 0;

function loadMentionNames() {
    $.ajax({
        url: 'news_action.php',
        type: 'GET',
        dataType: 'json',
        data: { action: 'get_commenters', news_id: newsId },
        success: function(res) {
            if (res.success) mentionNames = res.names;
        }
    });
}

function showMentionDropdown(textarea, query) {
    var dd = document.getElementById('mentionDropdown');
    var filtered = mentionNames.filter(function(n) {
        return n.toLowerCase().indexOf(query.toLowerCase()) !== -1;
    });
    if (filtered.length === 0) { hideMentionDropdown(); return; }

    mentionIndex = 0;
    var html = '';
    filtered.forEach(function(name, i) {
        html += '<div class="mention-item' + (i === 0 ? ' active' : '') + '" data-name="' + escapeHtml(name) + '" data-action="select-mention">' + escapeHtml(name) + '</div>';
    });
    dd.innerHTML = html;

    // Position the dropdown near the textarea
    var rect = textarea.getBoundingClientRect();
    var scrollY = window.scrollY || window.pageYOffset;
    dd.style.left = rect.left + 'px';
    dd.style.top = (rect.top + scrollY - dd.offsetHeight - 4) + 'px';
    dd.classList.add('show');
    dd.style.position = 'absolute';

    // Reposition if above viewport
    var ddRect = dd.getBoundingClientRect();
    if (ddRect.top < 0) {
        dd.style.top = (rect.bottom + scrollY + 4) + 'px';
    }

    mentionActive = true;
}

function hideMentionDropdown() {
    var dd = document.getElementById('mentionDropdown');
    dd.classList.remove('show');
    dd.innerHTML = '';
    mentionActive = false;
    mentionStart = -1;
}

function selectMention(name) {
    if (!mentionTarget || mentionStart < 0) return;
    var ta = mentionTarget;
    var before = ta.value.substring(0, mentionStart);
    var after = ta.value.substring(ta.selectionStart);
    ta.value = before + '@' + name + ' ' + after;
    var pos = before.length + name.length + 2;
    ta.setSelectionRange(pos, pos);
    ta.focus();
    hideMentionDropdown();
}

function handleMentionKeydown(e) {
    if (!mentionActive) return;
    var dd = document.getElementById('mentionDropdown');
    var items = dd.querySelectorAll('.mention-item');
    if (items.length === 0) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        items[mentionIndex].classList.remove('active');
        mentionIndex = (mentionIndex + 1) % items.length;
        items[mentionIndex].classList.add('active');
        items[mentionIndex].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        items[mentionIndex].classList.remove('active');
        mentionIndex = (mentionIndex - 1 + items.length) % items.length;
        items[mentionIndex].classList.add('active');
        items[mentionIndex].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        var name = items[mentionIndex].getAttribute('data-name');
        selectMention(name);
    } else if (e.key === 'Escape') {
        e.preventDefault();
        hideMentionDropdown();
    }
}

function handleMentionInput(e) {
    var ta = e.target;
    var val = ta.value;
    var pos = ta.selectionStart;
    mentionTarget = ta;

    // Find the @ before cursor
    var textBefore = val.substring(0, pos);
    var atIdx = textBefore.lastIndexOf('@');

    if (atIdx >= 0) {
        // Check that @ is at start or preceded by a space/newline
        var charBefore = atIdx > 0 ? textBefore.charAt(atIdx - 1) : ' ';
        if (charBefore === ' ' || charBefore === '\n' || atIdx === 0) {
            var query = textBefore.substring(atIdx + 1);
            // No space in query — still typing the mention
            if (query.indexOf(' ') === -1 && query.length <= 50) {
                mentionStart = atIdx;
                showMentionDropdown(ta, query);
                return;
            }
        }
    }
    hideMentionDropdown();
}

// Attach mention listeners to a textarea
function attachMentionListeners(textarea) {
    textarea.addEventListener('input', handleMentionInput);
    textarea.addEventListener('keydown', handleMentionKeydown);
    textarea.addEventListener('blur', function() {
        setTimeout(hideMentionDropdown, 150);
    });
}

// Attach to main comment textarea
(function() {
    var mainTA = document.getElementById('commentContent');
    if (mainTA) attachMentionListeners(mainTA);
})();

// Load mention names on page load
loadMentionNames();

var commentsPage = 1;
var commentsLoading = false;
var commentsHasMore = false;

function loadComments(reset) {
    if (commentsLoading) return;
    if (reset) {
        commentsPage = 1;
        $('#commentList').html('<div class="comments-empty">Chargement des commentaires...</div>');
    }
    commentsLoading = true;
    $('#commentsLoadMore').hide();

    $.ajax({
        url: 'news_action.php',
        type: 'GET',
        dataType: 'json',
        data: { action: 'get_comments', news_id: newsId, page: commentsPage },
        success: function(res) {
            commentsLoading = false;
            if (!res.success) {
                if (commentsPage === 1) $('#commentList').html('<div class="comments-empty">Soyez le premier a commenter !</div>');
                return;
            }
            $('#commentsCount').text(res.total);
            if (res.comments.length === 0 && commentsPage === 1) {
                $('#commentList').html('<div class="comments-empty">Soyez le premier a commenter !</div>');
                return;
            }
            var html = '';
            res.comments.forEach(function(c) {
                var rc = (c.replies && c.replies.length) || 0;
                html += renderComment(c, false, rc);
                if (rc > 0) {
                    html += '<div class="comment-replies" id="replies_' + c.id + '" style="display:none;">';
                    c.replies.forEach(function(r) {
                        html += renderComment(r, true, 0);
                    });
                    html += '</div>';
                }
            });
            if (commentsPage === 1) {
                $('#commentList').html(html);
            } else {
                $('#commentList').append(html);
            }
            commentsHasMore = res.has_more;
            if (commentsHasMore) {
                commentsPage++;
                $('#commentsLoadMore').show();
            }
            // Refresh mention names
            loadMentionNames();
        },
        error: function() {
            commentsLoading = false;
            if (commentsPage === 1) $('#commentList').html('<div class="comments-empty">Soyez le premier a commenter !</div>');
        }
    });
}

// IntersectionObserver pour charger plus au scroll
var loadMoreEl = document.getElementById('commentsLoadMore');
if (loadMoreEl) {
    var observer = new IntersectionObserver(function(entries) {
        if (entries[0].isIntersecting && commentsHasMore && !commentsLoading) {
            loadComments(false);
        }
    }, { rootMargin: '200px' });
    observer.observe(loadMoreEl);
}

function submitComment(parentId) {
    var nameEl, contentEl, btn;
    if (parentId) {
        var form = document.getElementById('replyForm_' + parentId);
        if (!form) return;
        contentEl = form.querySelector('textarea');
        nameEl = document.getElementById('commentName');
        btn = form.querySelector('.reply-submit');
    } else {
        nameEl = document.getElementById('commentName');
        contentEl = document.getElementById('commentContent');
        btn = document.querySelector('.comment-submit');
    }

    var name = nameEl.value.trim();
    var content = contentEl.value.trim();
    if (!name || !content) {
        showCommentMsg('Veuillez remplir tous les champs.', 'error');
        return;
    }

    localStorage.setItem('comment_name', name);
    btn.disabled = true;

    // Conteneur du message : inline si réponse, global sinon
    var msgTarget = null;
    if (parentId) {
        var form = document.getElementById('replyForm_' + parentId);
        if (form) {
            msgTarget = form.querySelector('.reply-msg');
            if (!msgTarget) {
                msgTarget = document.createElement('div');
                msgTarget.className = 'reply-msg';
                form.insertBefore(msgTarget, form.firstChild);
            }
        }
    }

    $.ajax({
        url: 'news_action.php',
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'add_comment',
            news_id: newsId,
            author_name: name,
            content: content,
            parent_id: parentId || ''
        },
        success: function(res) {
            btn.disabled = false;
            if (res.success) {
                contentEl.value = '';
                if (parentId) {
                    var rf = document.getElementById('replyForm_' + parentId);
                    if (rf) rf.remove();
                }
                showCommentMsg('Commentaire publié !', 'success');
                loadComments(true);
            } else {
                showCommentMsg(res.error || 'Erreur lors de la publication.', 'error', msgTarget);
            }
        },
        error: function() {
            btn.disabled = false;
            showCommentMsg('Erreur de connexion.', 'error');
        }
    });
}

function toggleReplies(commentId, btn) {
    var el = document.getElementById('replies_' + commentId);
    if (!el) return;
    var isOpen = el.style.display !== 'none';
    el.style.display = isOpen ? 'none' : '';
    btn.classList.toggle('open', !isOpen);
}

function showReplyForm(commentId) {
    // Remove any existing reply form
    document.querySelectorAll('.reply-form-inline').forEach(function(el) { el.remove(); });

    var commentEl = document.querySelector('.comment-item[data-id="' + commentId + '"]');
    if (!commentEl) return;

    // Ouvrir les reponses si elles sont masquees
    var repliesEl = document.getElementById('replies_' + commentId);
    if (repliesEl && repliesEl.style.display === 'none') {
        repliesEl.style.display = '';
        var toggleBtn = commentEl.querySelector('.comment-toggle-replies');
        if (toggleBtn) toggleBtn.classList.add('open');
    }

    var form = document.createElement('div');
    form.className = 'reply-form-inline';
    form.id = 'replyForm_' + commentId;
    form.innerHTML = '<textarea placeholder="Votre reponse..." maxlength="2000"></textarea>'
        + '<div class="reply-form-actions">'
        + '<button class="reply-cancel" data-action="cancel-reply">Annuler</button>'
        + '<button class="reply-submit" data-action="submit-reply" data-comment-id="' + commentId + '">Repondre</button>'
        + '</div>';

    // Insert after the comment item (or after replies block)
    var next = commentEl.nextElementSibling;
    if (next && next.classList.contains('comment-replies')) {
        next.after(form);
    } else {
        commentEl.after(form);
    }
    var replyTA = form.querySelector('textarea');
    attachMentionListeners(replyTA);
    replyTA.focus();
}

function likeComment(commentId, btn) {
    $.ajax({
        url: 'news_action.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'like_comment', comment_id: commentId },
        success: function(res) {
            if (res.success) {
                if (res.liked) {
                    setLikeCookie(commentId);
                    btn.classList.add('liked');
                    btn.querySelector('svg').setAttribute('fill', 'var(--pink)');
                } else {
                    removeLikeCookie(commentId);
                    btn.classList.remove('liked');
                    btn.querySelector('svg').setAttribute('fill', 'none');
                }
                btn.querySelector('.like-count').textContent = res.likes > 0 ? res.likes : '';
            }
        }
    });
}

function showCommentMsg(msg, type, inlineTarget) {
    var el = inlineTarget || document.getElementById('commentMsg');
    el.textContent = msg;
    el.className = (inlineTarget ? 'reply-msg ' : 'comment-msg ') + type;
    if (!inlineTarget) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(function() { el.className = inlineTarget ? 'reply-msg' : 'comment-msg'; el.textContent = ''; }, 10000);
}

// ─── Share functions ───
function shareOnFacebook() {
    var url = encodeURIComponent(window.location.href);
    window.open('https://www.facebook.com/sharer/sharer.php?u=' + url, '_blank', 'width=600,height=400');
}
function shareOnInstagram() {
    var url = encodeURIComponent(window.location.href);
    window.open('https://www.instagram.com/share?url=' + url, '_blank', 'width=600,height=400');
}
function shareOnX() {
    var url = encodeURIComponent(window.location.href);
    var title = encodeURIComponent(document.querySelector('.article-title').textContent);
    window.open('https://x.com/intent/tweet?url=' + url + '&text=' + title, '_blank', 'width=600,height=400');
}
function copyArticleLink() {
    navigator.clipboard.writeText(window.location.href).then(function() {
        var btn = document.getElementById('copyLinkBtn');
        btn.classList.add('copied');
        var spanEl = btn.querySelector('span');
        if (spanEl) spanEl.textContent = 'Copie !';
        setTimeout(function() {
            btn.classList.remove('copied');
            if (spanEl) spanEl.textContent = 'Copier';
        }, 2000);
    });
}

// Load comments on page load
loadComments(true);

<?php endif; ?>

<?php if (!$singleArticle): ?>
// ─── Search & AJAX (listing mode only) ───
let currentPage = <?= $page ?>;
let searchTimeout;

function fetchArticles(page) {
    const search = $('#searchInput').val().trim();
    $('#loadingSpinner').addClass('show');
    $('#articlesContainer').css('opacity', '.4');

    $.ajax({
        url: 'news.php',
        type: 'GET',
        dataType: 'json',
        data: { ajax: 1, search: search, page: page },
        success: function(data) {
            $('#articlesContainer').html(data.content).css('opacity', '1');
            $('#paginationContainer').html(data.pagination);
            currentPage = page;
            $('#loadingSpinner').removeClass('show');
            markVotedButtons();
            updateClear();
            if (page > 1) {
                $('html, body').animate({ scrollTop: $('.news-hero').offset().top - 100 }, 400);
            }
        },
        error: function() {
            $('#loadingSpinner').removeClass('show');
            $('#articlesContainer').css('opacity', '1');
        }
    });
}

function updateClear() {
    const v = $('#searchInput').val().trim();
    $('#searchClear').toggleClass('show', v.length > 0);
}

$(function() {
    updateClear();

    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { fetchArticles(1); }, 300);
        updateClear();
    });

    $('#searchClear').on('click', function() {
        $('#searchInput').val('');
        updateClear();
        fetchArticles(1);
    });

    $(document).on('click', '.pgbtn', function() {
        const p = $(this).data('page');
        if (p && p !== currentPage) fetchArticles(p);
    });
});
<?php endif; ?>

// ─── Event delegation — remplace tous les onclick inline (CSP-compatible) ───
document.addEventListener('click', function(e) {
    var el = e.target.closest('[data-action]');
    if (!el) {
        // Stop propagation pour ncard-votes
        if (e.target.closest('[data-stop-propagation]')) { e.preventDefault(); e.stopPropagation(); }
        return;
    }
    var action = el.dataset.action;
    switch (action) {
        case 'vote':
            e.preventDefault(); e.stopPropagation();
            handleVote(el);
            break;
        case 'share-facebook':
            shareOnFacebook();
            break;
        case 'share-x':
            shareOnX();
            break;
        case 'share-instagram':
            shareOnInstagram();
            break;

        case 'copy-link':
            copyArticleLink();
            break;
        case 'submit-comment':
            submitComment();
            break;
        case 'like-comment':
            likeComment(parseInt(el.dataset.commentId), el);
            break;
        case 'show-reply':
            showReplyForm(parseInt(el.dataset.commentId));
            break;
        case 'toggle-replies':
            toggleReplies(parseInt(el.dataset.commentId), el);
            break;
        case 'cancel-reply':
            var form = el.closest('.reply-form-inline');
            if (form) form.remove();
            break;
        case 'submit-reply':
            submitComment(parseInt(el.dataset.commentId));
            break;
    }
});
// Mention dropdown — mousedown delegation
document.addEventListener('mousedown', function(e) {
    var el = e.target.closest('[data-action="select-mention"]');
    if (el) selectMention(el.dataset.name);
});
</script>

<!-- Lightbox pour images TinyMCE -->
<div class="tiny-lightbox" id="tinyLightbox">
  <span class="tiny-lightbox-close">&times;</span>
  <img class="tiny-lightbox-img" id="tinyLightboxImg" alt="">
</div>

<script src="../js/fer-modern.js"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  const lb = document.getElementById('tinyLightbox');
  const lbImg = document.getElementById('tinyLightboxImg');
  if (!lb) return;
  document.querySelectorAll('.article-content img').forEach(img => {
    img.addEventListener('click', () => { lbImg.src = img.src; lb.classList.add('active'); });
  });
  lb.querySelector('.tiny-lightbox-close').addEventListener('click', () => lb.classList.remove('active'));
  lb.addEventListener('click', e => { if (e.target === lb) lb.classList.remove('active'); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') lb.classList.remove('active'); });

  // Transformer les liens PDF en jolis boutons (dédupliqués)
  const seenPdf = new Set();
  document.querySelectorAll('.article-content a[href$=".pdf"]').forEach(a => {
    const href = a.getAttribute('href');
    if (seenPdf.has(href)) { a.remove(); return; }
    seenPdf.add(href);
    const raw = (a.title || href.split('/').pop()).replace(/\.[^.]+$/, '');
    const name = /^tiny_[a-f0-9.]+$/.test(raw) ? 'Document' : raw;
    a.className = 'pdf-link';
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    a.innerHTML = '<span class="pdf-link-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15l3 3 3-3"/></svg></span><span class="pdf-link-info"><span class="pdf-link-name">' + name + '.pdf</span><span class="pdf-link-hint">Cliquer pour ouvrir</span></span>';
  });
})();
</script>

</body>
</html>
