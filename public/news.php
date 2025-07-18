<?php
require '../config/config.php';

// Configuration pagination
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 9; // 9 articles par page (3x3)
$offset = ($page - 1) * $limit;

// Récupération des paramètres
$search = $_GET['search'] ?? '';

// Construction de la requête avec recherche
$whereConditions = [];
$params = [];

if (!empty($search)) {
    $whereConditions[] = '(title_article LIKE :search OR desc_article LIKE :search)';
    $params[':search'] = "%$search%";
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Requête pour compter le total d'articles
$countSql = "SELECT COUNT(*) as total FROM news $whereClause";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalArticles = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalArticles / $limit);

// Requête pour récupérer les articles avec tri par priorité de recherche
if (!empty($search)) {
    // Prioriser les résultats trouvés dans le titre
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
    // Tri par date par défaut
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

// Récupération des paramètres du site
$stmt = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
$stmt->execute(['id' => 1]);
$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$titleAccueil = $data['titleAccueil'] ?? '';
$picture = $data['picture'] ?? '';
$titleColor = $data['title_color'] ?? '#ffffff';
$edition = $data['edition'] ?? '';
$footer= $data['footer'] ?? null;  
$link_instagram  = $data['link_instagram'] ?? null;
$link_facebook = $data['link_facebook'] ?? null; 
$link_cancer = $data['link_cancer'] ?? null;

// Fonction pour tronquer le texte
function truncateText($text, $maxLength = 180) {
    if (strlen($text) <= $maxLength) return $text;
    return substr($text, 0, $maxLength) . '...';
}

// Fonction pour surligner les termes trouvés
function highlightSearch($text, $search) {
    if (empty($search)) return $text;
    
    $highlighted = preg_replace(
        '/(' . preg_quote($search, '/') . ')/i',
        '<mark class="search-highlight">$1</mark>',
        $text
    );
    
    return $highlighted;
}

// Traitement AJAX pour les filtres
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    ob_start();
    
    // Afficher les articles
    foreach ($articles as $article):
        $imgPath = '../files/_news/' . $article['img_article'];
        $hasImage = !empty($article['img_article']) && is_file($imgPath);
        
        // Déterminer si la recherche a été trouvée dans le titre ou la description
        $titleFound = !empty($search) && stripos($article['title_article'], $search) !== false;
        $descFound = !empty($search) && stripos($article['desc_article'], $search) !== false;
        
        // Surligner uniquement si trouvé dans la description et pas dans le titre
        $displayTitle = $article['title_article'];
        $displayDesc = $article['desc_article'];
        
        if (!empty($search) && !$titleFound && $descFound) {
            $displayDesc = highlightSearch($displayDesc, $search);
        }
        
        $displayDesc = truncateText($displayDesc, 180);
        ?>
        <div class="news-card">
            <?php if ($hasImage): ?>
                <img src="<?= htmlspecialchars($imgPath) ?>" alt="<?= htmlspecialchars($article['title_article']) ?>" class="news-card-img">
            <?php else: ?>
                <div class="news-card-img news-card-placeholder">
                    <span>📰</span>
                </div>
            <?php endif; ?>
            
            <div class="news-card-body">
                <h5 class="news-card-title"><?= htmlspecialchars($displayTitle) ?></h5>
                <p class="news-card-excerpt"><?= $displayDesc ?></p>
                <button class="btn-read-more" onclick="openModal(<?= $article['id'] ?>)">Lire plus</button>
                
                <div class="news-card-footer">
                    <small class="news-date"><?= date('d/m/Y', strtotime($article['date_publication'])) ?></small>
                    <div class="news-actions">
                        <button class="btn-feedback btn-like" data-id="<?= $article['id'] ?>">
                            👍<span class="count-badge like-count"><?= $article['like'] ?></span>
                        </button>
                        <button class="btn-feedback btn-dislike" data-id="<?= $article['id'] ?>">
                            👎<span class="count-badge dislike-count"><?= $article['dislike'] ?></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach;
    
    $content = ob_get_clean();
    
    // Générer la pagination
    ob_start();
    if ($totalPages > 1): ?>
        <div class="pagination-container">
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <button class="pagination-btn pagination-prev" data-page="<?= $page - 1 ?>">←</button>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <button class="pagination-btn <?= $i == $page ? 'active' : '' ?>" data-page="<?= $i ?>">
                        <?= $i ?>
                    </button>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <button class="pagination-btn pagination-next" data-page="<?= $page + 1 ?>">→</button>
                <?php endif; ?>
            </div>
            <div class="pagination-info">
                Page <?= $page ?> sur <?= $totalPages ?> (<?= $totalArticles ?> articles)
            </div>
        </div>
    <?php endif;
    
    $pagination = ob_get_clean();
    
    echo json_encode([
        'content' => $content,
        'pagination' => $pagination,
        'articles' => $articles // Pour le JavaScript
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Actualités - Forbach en Rose</title>
  <link rel="stylesheet" href="../css/forbach-style.css">
  <link rel="stylesheet" href="../css/accueil.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <script src="../js/nav-flottante.js"></script>
</head>
<body>

<?php include '../inc/nav.php'; ?>

<link rel="stylesheet" href="../css/news.css">
<div class="news-section">
    <h2 class="section-title">📰 Nos Actualités</h2>
    
    <!-- Barre de recherche simplifiée -->
    <div class="news-search">
        <div class="search-container">
            <input type="text" id="searchInput" class="search-input" placeholder="🔍 Rechercher dans les actualités...">
            <button type="button" id="searchClear" class="search-clear">✕</button>
        </div>
    </div>
    
    <!-- Spinner de chargement -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Chargement...</span>
        </div>
    </div>
    
    <!-- Conteneur des articles -->
    <div class="news-grid" id="articlesContainer">
        <?php foreach ($articles as $article): ?>
            <?php 
            $imgPath = '../files/_news/' . $article['img_article'];
            
            // Déterminer si la recherche a été trouvée dans le titre ou la description
            $titleFound = !empty($search) && stripos($article['title_article'], $search) !== false;
            $descFound = !empty($search) && stripos($article['desc_article'], $search) !== false;
            
            // Surligner uniquement si trouvé dans la description et pas dans le titre
            $displayTitle = htmlspecialchars($article['title_article'] ?? '');
            $displayDesc = $article['desc_article'];
            
            if (!empty($search) && !$titleFound && $descFound) {
                $displayDesc = highlightSearch($displayDesc, $search);
            }
            
            $displayDesc = truncateText($displayDesc, 180);
            ?>
            
            <div class="news-card">
                <?php if (!empty($article['img_article']) && is_file($imgPath)): ?>
                    <img src="<?= htmlspecialchars($imgPath) ?>" alt="<?= htmlspecialchars($article['title_article']) ?>" class="news-card-img">
                <?php else: ?>
                    <div class="news-card-img news-card-placeholder">
                        <span>📰</span>
                    </div>
                <?php endif; ?>
                
                <div class="news-card-body">
                    <h5 class="news-card-title"><?= $displayTitle ?></h5>
                    <p class="news-card-excerpt"><?= $displayDesc ?></p>
                    <button class="btn-read-more" onclick="openModal(<?= $article['id'] ?>)">Lire plus</button>
                    
                    <div class="news-card-footer">
                        <small class="news-date"><?= date('d/m/Y', strtotime($article['date_publication'])) ?></small>
                        <div class="news-actions">
                            <button class="btn-feedback btn-like" data-id="<?= $article['id'] ?>">
                                👍<span class="count-badge like-count"><?= $article['like'] ?></span>
                            </button>
                            <button class="btn-feedback btn-dislike" data-id="<?= $article['id'] ?>">
                                👎<span class="count-badge dislike-count"><?= $article['dislike'] ?></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Pagination -->
    <div id="paginationContainer">
        <?php if ($totalPages > 1): ?>
            <div class="pagination-container">
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <button class="pagination-btn pagination-prev" data-page="<?= $page - 1 ?>">←</button>
                    <?php endif; ?>
                    
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <button class="pagination-btn <?= $i == $page ? 'active' : '' ?>" data-page="<?= $i ?>">
                            <?= $i ?>
                        </button>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <button class="pagination-btn pagination-next" data-page="<?= $page + 1 ?>">→</button>
                    <?php endif; ?>
                </div>
                <div class="pagination-info">
                    Page <?= $page ?> sur <?= $totalPages ?> (<?= $totalArticles ?> articles)
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal pour "Lire plus" -->
<div class="modal fade" id="newsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle"></h5>
                <button type="button" class="btn-custom-close" data-bs-dismiss="modal" aria-label="Fermer">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Contenu dynamique -->
            </div>
            <div class="modal-footer">
                <div class="w-100 d-flex justify-content-between align-items-center">
                    <small class="text-muted" id="modalDate"></small>
                    <div class="news-actions" id="modalActions">
                        <!-- Boutons like/dislike -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Footer -->
<?php if (!empty($link_facebook) || !empty($link_instagram)) : ?>
  <footer>
    <div class="top-logos-footer">
      <a href="<?= htmlspecialchars($link_cancer, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" aria-label="Ligue contre le Cancer">
        <img src="../files/_logos/ligue-cancer-blanc.png" alt="Ligue contre le cancer">
      </a>  
      <?php if (!empty($link_instagram)) : ?>
        <a href="<?= htmlspecialchars($link_instagram, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" aria-label="Instagram">
          <img src="../files/_logos/instagram.png" alt="Instagram">
        </a>
      <?php endif; ?>
      <?php if (!empty($link_facebook)) : ?>
        <a href="<?= htmlspecialchars($link_facebook, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" aria-label="Facebook">
          <img src="../files/_logos/facebook.png" alt="Facebook">
        </a>
      <?php endif; ?>
    </div>
    <?php if (!empty($footer)) : ?>
      <?= htmlspecialchars($footer) ?>
    <?php endif; ?>
  </footer>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Données des articles (généré par PHP)
let articles = <?= json_encode($articles) ?>;
let currentPage = <?= $page ?>;
let searchTimeout;

// Fonction pour formater la date
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('fr-FR');
}

// Fonction pour ouvrir le modal
function openModal(articleId) {
    const article = articles.find(a => a.id == articleId);
    if (!article) return;

    document.getElementById('modalTitle').textContent = article.title_article;
    document.getElementById('modalDate').textContent = formatDate(article.date_publication);
    
    const modalBody = document.getElementById('modalBody');
    let imgHtml = '';
    
    if (article.img_article) {
        const imgPath = '../files/_news/' + article.img_article;
        imgHtml = `<img src="${imgPath}" alt="${article.title_article}" class="modal-img">`;
    }
    
    modalBody.innerHTML = `
        ${imgHtml}
        <p>${article.desc_article.replace(/\n/g, '<br>')}</p>
    `;

    const modalActions = document.getElementById('modalActions');
    modalActions.innerHTML = `
        <button class="btn-feedback btn-like" data-id="${article.id}">
            👍<span class="count-badge like-count">${article.like}</span>
        </button>
        <button class="btn-feedback btn-dislike" data-id="${article.id}">
            👎<span class="count-badge dislike-count">${article.dislike}</span>
        </button>
    `;

    const modal = new bootstrap.Modal(document.getElementById('newsModal'));
    modal.show();
}

// Fonction pour récupérer les articles avec AJAX
function fetchArticles(page = 1) {
    const search = $('#searchInput').val().trim();
    
    // Afficher le spinner
    $('#loadingSpinner').addClass('show');
    $('#articlesContainer').addClass('loading');
    
    $.get('', { 
        ajax: 1, 
        search: search,
        page: page 
    }, function(response) {
        const data = JSON.parse(response);
        
        // Mettre à jour le contenu
        $('#articlesContainer').html(data.content);
        $('#paginationContainer').html(data.pagination);
        
        // Mettre à jour les données JavaScript
        articles = data.articles;
        currentPage = page;
        
        // Cacher le spinner
        $('#loadingSpinner').removeClass('show');
        $('#articlesContainer').removeClass('loading');
        
        // Marquer les boutons votés
        markVotedButtons();
        
        // Mettre à jour le bouton clear
        updateClearButton();
        
        // Scroll vers le haut si ce n'est pas la première page
        if (page > 1) {
            $('html, body').animate({
                scrollTop: $('.news-section').offset().top - 100
            }, 500);
        }
    }).fail(function() {
        $('#loadingSpinner').removeClass('show');
        $('#articlesContainer').removeClass('loading');
        alert('Erreur lors du chargement des articles');
    });
}

// Fonction pour mettre à jour le bouton clear
function updateClearButton() {
    const search = $('#searchInput').val().trim();
    if (search) {
        $('#searchClear').addClass('show');
    } else {
        $('#searchClear').removeClass('show');
    }
}

$(document).ready(function() {
    // Mettre à jour le bouton clear au chargement
    updateClearButton();
    
    // Gestion de la recherche avec debounce
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        const search = $(this).val().trim();
        
        searchTimeout = setTimeout(function() {
            fetchArticles(1);
        }, 300);
        
        updateClearButton();
    });

    // Bouton clear search
    $('#searchClear').on('click', function() {
        $('#searchInput').val('');
        updateClearButton();
        fetchArticles(1);
    });

    // Gestion de la pagination
    $(document).on('click', '.pagination-btn', function() {
        const page = $(this).data('page');
        if (page && page !== currentPage) {
            fetchArticles(page);
        }
    });

    // Gestion des votes avec cookies
    function getVoteCookie(articleId) {
        const name = `vote_${articleId}=`;
        const decodedCookie = decodeURIComponent(document.cookie);
        const ca = decodedCookie.split(';');
        for (let i = 0; i < ca.length; i++) {
            let c = ca[i].trim();
            if (c.indexOf(name) === 0) {
                return c.substring(name.length, c.length);
            }
        }
        return null;
    }

    function setVoteCookie(articleId, type) {
        const d = new Date();
        d.setFullYear(d.getFullYear() + 1);
        document.cookie = `vote_${articleId}=${type}; expires=${d.toUTCString()}; path=/`;
    }

    // Marquer les boutons déjà votés
    function markVotedButtons() {
        $('.btn-like, .btn-dislike').each(function() {
            const id = $(this).data('id');
            const type = $(this).hasClass('btn-like') ? 'like' : 'dislike';
            const currentVote = getVoteCookie(id);
            
            if (currentVote === type) {
                $(this).addClass('voted');
            }
        });
    }

    // Appeler au chargement
    markVotedButtons();

    // Gestion des clics sur les boutons like/dislike
    $(document).on('click', '.btn-like, .btn-dislike', function() {
        const id = $(this).data('id');
        const type = $(this).hasClass('btn-like') ? 'like' : 'dislike';
        const opposite = type === 'like' ? 'dislike' : 'like';
        const currentVote = getVoteCookie(id);

        if (currentVote === type) {
            return; // Déjà voté
        }

        // Animation de clic
        $(this).css('transform', 'scale(0.95)');
        setTimeout(() => {
            $(this).css('transform', 'scale(1)');
        }, 100);

        $.ajax({
            url: 'news_action.php',
            type: 'POST',
            dataType: 'json',
            data: { id: id, type: type, remove: currentVote },
            success: function(res) {
                if (res.success) {
                    // Mettre à jour tous les compteurs pour cet article
                    $(`.btn-${type}[data-id="${id}"] .count-badge`).text(res.count[type]);
                    if (currentVote) {
                        $(`.btn-${opposite}[data-id="${id}"] .count-badge`).text(res.count[opposite]);
                    }
                    
                    // Mettre à jour les styles
                    $(`.btn-feedback[data-id="${id}"]`).removeClass('voted');
                    $(`.btn-${type}[data-id="${id}"]`).addClass('voted');
                    
                    setVoteCookie(id, type);
                } else {
                    alert(res.error || "Erreur serveur.");
                }
            },
            error: function() {
                alert("Erreur AJAX.");
            }
        });
    });

    // Remarquer les boutons après un chargement AJAX
    $(document).ajaxComplete(function() {
        markVotedButtons();
    });
});
</script>

</body>
</html>