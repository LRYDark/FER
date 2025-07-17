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

if (isset($_POST['add_news'])) {
    $title = $_POST['title_article'];
    $desc = $_POST['desc_article'];
    $imgName = '';

    if (!empty($_FILES['img_article']['name'])) {
        $imgName = basename($_FILES['img_article']['name']);
        move_uploaded_file($_FILES['img_article']['tmp_name'], "../files/_news/" . $imgName);
    }

    $stmt = $pdo->prepare("INSERT INTO news (img_article, title_article, desc_article, date_publication, `like`, `dislike`) VALUES (?, ?, ?, NOW(), 0, 0)");
    $stmt->execute([$imgName, $title, $desc]);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_POST['update_news'])) {
    $id = $_POST['news_id'];
    $title = $_POST['title_article'];
    $desc = $_POST['desc_article'];

    if (!empty($_FILES['img_article']['name'])) {
        $imgName = basename($_FILES['img_article']['name']);
        move_uploaded_file($_FILES['img_article']['tmp_name'], "../files/_news/" . $imgName);
        $stmt = $pdo->prepare("UPDATE news SET img_article = ?, title_article = ?, desc_article = ? WHERE id = ?");
        $stmt->execute([$imgName, $title, $desc, $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE news SET title_article = ?, desc_article = ? WHERE id = ?");
        $stmt->execute([$title, $desc, $id]);
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_POST['delete_news'])) {
    $id = $_POST['news_id'];
    $stmt = $pdo->prepare("SELECT img_article FROM news WHERE id = ?");
    $stmt->execute([$id]);
    $img = $stmt->fetchColumn();

    if ($img && file_exists("../files/_news/" . $img)) {
        unlink("../files/_news/" . $img);
    }

    $stmt = $pdo->prepare("DELETE FROM news WHERE id = ?");
    $stmt->execute([$id]);

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$articles = $pdo->query("SELECT * FROM news ORDER BY date_publication DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Actualités – Forbach en Rose</title>

<!-- ─── CSS ─── -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="../css/forbach-style.css" rel="stylesheet">
<link href="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-KE9wPQ6…(clé-cdn)…" crossorigin="anonymous"></script>
<style>
  .first-750 td{background:#ffe5ff!important;font-weight:600}
  .hero{display:flex;align-items:center;justify-content:center;padding:2rem 1rem;background:var(--rose-500);color:#fff;position:relative}
  .hero h1{margin:0;font-size:2.2rem}
  .top-actions{position:absolute;top:1rem;right:1rem;display:flex;gap:.5rem}
  @media (max-width:991.98px){.top-actions{display:none}}
  .card-dashboard{margin-top:1rem;border-radius:2rem;box-shadow:0 0 25px rgba(0,0,0,.1)}
  .quick-search{max-width:450px;width:50%;margin:0 auto .75rem;position:sticky;top:0;z-index:1030}
  tr.filters th[class*="sorting"]::before,
  tr.filters th[class*="sorting"]::after{display:none!important}
  .statCard{min-width:180px}
  .hide-stats #stats {display: none !important;}
.card {
  border-radius: 12px;
}
.card-img-top {
  border-top-left-radius: 12px;
  border-top-right-radius: 12px;
}




</style>
</head>

<body class="d-flex flex-column">

<?php include '../inc/nav-settings.php'; ?>

<div class="container py-4">
  <h1 class="mb-3 fw-bold">Actualités</h1>
  <div class="text-end mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddNews">Ajouter un article</button>
  </div>

  <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
    <?php foreach ($articles as $n): ?>
      <div class="col">
        <div class="card h-100 shadow-sm border-0">
          <?php if (!empty($n['img_article'])): ?>
            <img src="../files/_news/<?= htmlspecialchars($n['img_article']) ?>" class="card-img-top" style="height: 200px; object-fit: cover;">
          <?php endif; ?>
          <div class="card-body d-flex flex-column">
            <p class="card-text"><?= substr(strip_tags($n['desc_article']), 0, 120) ?>...</p>
            <p class="text-muted small">Publié le <?= date('d/m/Y H:i', strtotime($n['date_publication'])) ?></p>
            <div class="mt-auto d-flex gap-2">
              <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalEditNews<?= $n['id'] ?>">Modifier</button>
              <form method="post" onsubmit="return confirm('Supprimer cet article ?');">
                <input type="hidden" name="news_id" value="<?= $n['id'] ?>">
                <button type="submit" name="delete_news" class="btn btn-sm btn-danger">Supprimer</button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal Modifier -->
      <div class="modal fade" id="modalEditNews<?= $n['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content p-4">
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="news_id" value="<?= $n['id'] ?>">
              <div class="modal-header">
                <h5 class="modal-title">Modifier l’article</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body row g-3">
                <div class="col-md-6">
                  <label>Titre</label>
                  <input type="text" name="title_article" class="form-control" value="<?= htmlspecialchars($n['title_article']) ?>" required>
                </div>
                <div class="col-md-6">
                  <label>Image (laisser vide pour conserver)</label>
                  <input type="file" name="img_article" class="form-control">
                </div>
                <div class="col-md-12">
                    <!-- Textarea avec TinyMCE -->
                    <label>Description</label>
                    <textarea class="form-control" id="desc_article" name="desc_article" rows="6" ><?= htmlspecialchars($n['desc_article']) ?></textarea>
                </div>
              </div>
              <div class="modal-footer">
                <button type="submit" name="update_news" class="btn btn-success">Mettre à jour</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Modal Ajouter -->
  <div class="modal fade" id="modalAddNews" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content p-4">
        <form method="post" enctype="multipart/form-data">
          <div class="modal-header">
            <h5 class="modal-title">Ajouter un article</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body row g-3">
            <div class="col-md-6">
              <label>Titre</label>
              <input type="text" name="title_article" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label>Image</label>
              <input type="file" name="img_article" class="form-control">
            </div>
            <div class="col-md-12">
                <!-- Textarea avec TinyMCE -->
                <label>Description</label>
                <textarea class="form-control" id="desc_article" name="desc_article" rows="6" ></textarea>
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
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.7.0/tinymce.min.js"></script>
    <script>
        tinymce.init({
            selector: '#desc_article',
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

<footer class="text-center py-3 small text-muted"><?= htmlspecialchars($footer) ?></footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
