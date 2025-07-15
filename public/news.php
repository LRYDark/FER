<?php



require '../config/config.php';

$stmt = $pdo->prepare('SELECT * FROM news ORDER BY date_publication DESC');
$stmt->execute();
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);



$stmt = $pdo->prepare(
    'SELECT *
       FROM setting
      WHERE id = :id
      LIMIT 1');
$stmt->execute(['id' => 1]);

$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$titleAccueil  = $data['titleAccueil']   ?? '';
$picture= $data['picture'] ?? '';  
$titleColor = $data['title_color'] ?? '#ffffff';
$edition = $data['edition'] ?? '';  

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Accueil - Forbach en Rose</title>
  <link rel="stylesheet" href="../css/forbach-style.css">
  <link rel="stylesheet" href="../css/accueil.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <script src="../js/nav-flottante.js"></script>
</head>
<body>

<?php include '../inc/nav.php'; ?> <!-- si nav séparée -->

<style>
.news-wrapper {
  position: relative;
  display: flex;
  align-items: flex-end;
  justify-content: flex-start;
  margin-bottom: 4rem;
  flex-wrap: wrap;
  max-width: 1900px; /* Limiter la largeur totale */
  margin-left: 0; /* Coller à gauche */
  margin-right: auto; /* Centrer si nécessaire */
}

.news-img-container {
  flex: 1 1 50%; /* Réduire légèrement la largeur de l'image */
  max-width: 500px; /* Réduire la largeur max de l'image */
  min-width: 300px;
  margin-right: 0; /* Supprimer toute marge droite */
}

.news-img {
  width: 100%;
  height: auto;
  border-radius: 1rem;
  display: block;
}

.news-text-box {
  background: white;
  border-radius: 1rem;
  padding: 1.5rem;
  position: absolute;
  bottom: 0.5rem;
  left: calc(35% - 1rem); /* Décaler plus vers la gauche */
  transform: translateY(50%);
  width: 60%; /* Agrandir la largeur de la zone de texte */
  max-width: 750px; /* Augmenter la largeur maximale */
  box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
  z-index: 2;
}

.news-title {
  font-weight: bold;
  font-size: 1.3rem;
  color: #e91e63;
  margin-bottom: 0.8rem;
}

.news-desc {
  font-size: 1rem;
  color: #444;
  margin-bottom: 1rem;
}

/* Responsive mobile */
@media (max-width: 768px) {
  .news-wrapper {
    flex-direction: column;
    align-items: center;
    margin-left: auto;
    margin-right: auto;
  }

  .news-text-box {
    position: static;
    transform: none;
    width: 90%;
    margin-top: 1rem;
  }

  .news-img-container {
    width: 100%;
    max-width: 100%;
  }
}

.btn-feedback {
  background-color: #ffe3f0;
  color: #e91e63;
  border: none;
  border-radius: 2rem;
  padding: 0.35rem 0.9rem;
  font-size: 1rem;
  font-weight: bold;
  margin-left: 0.5rem;
  transition: all 0.3s ease;
}

.btn-feedback:hover {
  background-color: #e91e63;
  color: #fff;
}

.count-badge {
  display: inline-block;
  background: white;
  color: #e91e63;
  border-radius: 999px;
  padding: 0.2rem 0.6rem;
  margin-left: 0.4rem;
  font-weight: bold;
  font-size: 0.9rem;
}

/* Optimiser l'utilisation de l'espace du conteneur */
.container {
  max-width: 1900px;
  padding-left: 1rem;
  padding-right: 1rem;
}



</style>

<div class="container my-5">
  <h2 class="text-center mb-4" style="color:#e91e63;">📰 Nos Actualités</h2>
  <?php foreach ($articles as $article): ?>
    <div class="news-wrapper">
        <?php if (!empty($article['img_article'])): ?>
            <div class="news-img-container">
                <img src="../files/_news/<?= htmlspecialchars($article['img_article']) ?>" alt="Article" class="news-img">
            </div>
        <?php endif; ?>

        <div class="news-text-box shadow">
            <h5 class="news-title"><?= htmlspecialchars($article['title_article']) ?></h5>
            <p class="news-desc"><?= nl2br(htmlspecialchars($article['desc_article'])) ?></p>

            <div class="d-flex justify-content-between align-items-center">
            <small class="text-muted"><?= date('d/m/Y', strtotime($article['date_publication'])) ?></small>
<div>
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






<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$(document).ready(function () {
  $('.btn-like, .btn-dislike').on('click', function () {
    const id = $(this).data('id');
    const type = $(this).hasClass('btn-like') ? 'like' : 'dislike';
    const button = $(this);

    $.ajax({
      url: 'news_action.php',
      type: 'POST',
      dataType: 'json',
      data: { id: id, type: type },
      success: function (res) {
        if (res.success) {
          button.find('span').text(res.count);
        } else {
          console.error('Erreur côté serveur :', res.error);
        }
      },
      error: function (xhr, status, error) {
        console.error('Erreur AJAX :', error);
      }
    });
  });
});
</script>



</body>
</html>
