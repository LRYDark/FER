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

<div class="container my-5">
  <h2 class="text-center mb-4" style="color:#e91e63;">📰 Nos Actualités</h2>

  <?php foreach ($articles as $article): ?>
    <div class="card mb-4 shadow-sm">
      <?php if (!empty($article['img_article'])): ?>
        <img src="../files/news/<?= htmlspecialchars($article['img_article']) ?>" class="card-img-top" alt="Illustration">
      <?php endif; ?>
      <div class="card-body">
        <h5 class="card-title"><?= htmlspecialchars($article['title_article']) ?></h5>
        <p class="card-text"><?= nl2br(htmlspecialchars($article['desc_article'])) ?></p>
        <div class="d-flex justify-content-between align-items-center">
          <small class="text-muted"><?= date('d/m/Y', strtotime($article['date_publication'])) ?></small>
          <div>
            <button class="btn btn-outline-success btn-sm btn-like" data-id="<?= $article['id'] ?>">👍 <span class="like-count"><?= $article['like'] ?></span></button>
            <button class="btn btn-outline-danger btn-sm btn-dislike" data-id="<?= $article['id'] ?>">👎 <span class="dislike-count"><?= $article['dislike'] ?></span></button>
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
