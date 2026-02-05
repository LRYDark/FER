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

if (isset($_POST['update_year'])) {
  $yearId = $_POST['year_id'];
  $year = $_POST['year'];
  $title = $_POST['title'];
  $partner_desc = $_POST['partner_desc'];

  if (!empty($_FILES['year_img']['name'])) {
    $imgName = $_FILES['year_img']['name'];
    move_uploaded_file($_FILES['year_img']['tmp_name'], "../files/_partners/" . $imgName);
    $stmt = $pdo->prepare("UPDATE partners_years SET year = ?, title = ?, img = ?, `desc` = ? WHERE id = ?");
    $stmt->execute([$year, $title, $imgName, $partner_desc, $yearId]);
  } else {
    $stmt = $pdo->prepare("UPDATE partners_years SET year = ?, title = ?, `desc` = ? WHERE id = ?");
    $stmt->execute([$year, $title, $partner_desc, $yearId]);
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
    $imgName = $_FILES['album_img']['name'];
    move_uploaded_file($_FILES['album_img']['tmp_name'], "../files/_partners/" . $imgName);
    $stmt = $pdo->prepare("UPDATE partners_albums SET album_title = ?, album_img = ?, album_desc = ? WHERE id = ?");
    $stmt->execute([$album_title, $imgName, $album_desc, $albumId]);
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
  $stmt = $pdo->prepare("INSERT INTO partners_albums (year_id, album_title, album_img, album_desc) VALUES (?, ?, ?, ?)");
  $imgName = $_FILES['album_img']['name'];
  move_uploaded_file($_FILES['album_img']['tmp_name'], "../files/_partners/" . $imgName);
  $stmt->execute([
    $yearId,
    $_POST['album_title'],
    $imgName,
    $_POST['album_desc']
  ]);
  $_SESSION['reopen_modal'] = $yearId;
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

if (isset($_POST['delete_album'])) {
  $albumId = $_POST['album_id'];
  $yearId = $_POST['year_id'];

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
  exit;
}

if (isset($_POST['add_year'])) {
  $stmt = $pdo->prepare("INSERT INTO partners_years (year, title, img, `desc`) VALUES (?, ?, ?, ?)");
  $imgName = $_FILES['year_img']['name'];
  move_uploaded_file($_FILES['year_img']['tmp_name'], "../files/_partners/" . $imgName);
  $stmt->execute([$_POST['year'], $_POST['title'], $imgName, $_POST['partner_desc']]);
}

if (isset($_POST['delete_year'])) {
  $yearId = $_POST['year_id'];

  $stmt = $pdo->prepare("SELECT album_img FROM partners_albums WHERE year_id = ?");
  $stmt->execute([$yearId]);
  $albumImgs = $stmt->fetchAll(PDO::FETCH_COLUMN);
  foreach ($albumImgs as $img) {
    if ($img && file_exists("../files/_partners/" . $img)) {
      unlink("../files/_partners/" . $img);
    }
  }

  $stmt = $pdo->prepare("SELECT img FROM partners_years WHERE id = ?");
  $stmt->execute([$yearId]);
  $yearImg = $stmt->fetchColumn();
  if ($yearImg && file_exists("../files/_partners/" . $yearImg)) {
    unlink("../files/_partners/" . $yearImg);
  }

  $stmt1 = $pdo->prepare("DELETE FROM partners_albums WHERE year_id = ?");
  $stmt1->execute([$yearId]);
  $stmt2 = $pdo->prepare("DELETE FROM partners_years WHERE id = ?");
  $stmt2->execute([$yearId]);

  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

$years = $pdo->query("SELECT * FROM partners_years ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
$albumsByYear = [];
foreach ($years as $y) {
  $stmt = $pdo->prepare("SELECT * FROM partners_albums WHERE year_id = ?");
  $stmt->execute([$y['id']]);
  $albumsByYear[$y['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>


<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Réglages – Forbach en Rose</title>

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
  .card-dashboard{margin-top:1rem;border-radius:1.25rem;box-shadow:0 0 25px rgba(0,0,0,.1)}
  .quick-search{max-width:450px;width:50%;margin:0 auto .75rem;position:sticky;top:0;z-index:1030}
  tr.filters th[class*="sorting"]::before,
  tr.filters th[class*="sorting"]::after{display:none!important}
  .statCard{min-width:180px}
  .hide-stats #stats {display: none !important;}
</style>
</head>

<body class="d-flex flex-column">

<?php include '../inc/nav-settings.php'; ?>

<!-- ═════════ MAIN ═════════ -->
<main class="container-fluid flex-grow-1">
    <!-- Une seule .row -->
    <div class="row g-4 align-items-stretch"><!-- align-items-stretch => les cartes prennent la même hauteur -->
        <!-- Colonne GAUCHE : 2 petites cartes empilées -->
        <div class="col-12 col-lg-12 d-flex flex-column gap-4">
            <div class="card-dashboard p-4 shadow-sm rounded-4 bg-white flex-grow-0">
            <!-- Place this before </body> -->
            <?php if (isset($_SESSION['reopen_modal'])): ?>
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                var modalId = 'modalYear<?= $_SESSION['reopen_modal'] ?>';
                var modal = new bootstrap.Modal(document.getElementById(modalId));
                modal.show();
            });
            </script>
            <?php unset($_SESSION['reopen_modal']); ?>
            <?php endif; ?>

            <body class="container py-4">

            <h1 class="mb-4">Gestion des Partenaires par Année</h1>

            <!-- Bouton pour ajouter une année -->
            <button class="btn btn-primary mb-4" data-bs-toggle="modal" data-bs-target="#modalAddYear">Ajouter une Année</button>

            <div class="row g-4">
            <?php foreach ($years as $year): ?>
            <div class="col-md-3">
                <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><?= htmlspecialchars($year['year']) ?> - <?= htmlspecialchars($year['title']) ?></strong>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalYear<?= $year['id'] ?>">Modifier</button>
                </div>
                <div class="card-body">
                    <img src="../files/_partners/<?= htmlspecialchars($year['img']) ?>" class="img-fluid mb-3" style="max-height:150px;">
                    <ul class="list-group">
                </div>
                </div>
            </div>

            <!-- Modal de modification année -->
            <div class="modal fade" id="modalYear<?= $year['id'] ?>" tabindex="-1">
                <div class="modal-dialog modal-xl">
                <div class="modal-content p-4">
                    <div class="modal-header">
                    <h5 class="modal-title">Modifier l'année <?= htmlspecialchars($year['year']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                    <form method="post" enctype="multipart/form-data" class="mb-4">
                        <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                        <div class="row g-3">
                          <div class="col-md-4">
                              <label class="form-label">Année</label>
                              <input type="number" name="year" class="form-control" value="<?= htmlspecialchars($year['year']) ?>">
                          </div>
                          <div class="col-md-4">
                              <label class="form-label">Titre</label>
                              <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($year['title']) ?>">
                          </div>
                          <div class="col-md-4">
                              <label class="form-label">Image</label>
                              <input type="file" name="year_img" class="form-control">
                          </div>
                          <div class="col-md-12">
                            <!-- Textarea avec TinyMCE -->
                            <textarea class="form-control" id="partner_desc" name="partner_desc" rows="10" placeholder="Description"><?= htmlspecialchars($year['desc']) ?></textarea>
                          </div>
                        </div>
                        <button type="submit" name="update_year" class="btn btn-primary mt-3">Enregistrer</button>
                    </form>

                    <form method="post" onsubmit="return confirm('Supprimer cette année et tous ses albums ?');">
                        <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                        <button type="submit" name="delete_year" class="btn btn-danger mb-4">Supprimer l'année</button>
                    </form>

                    <h5>Albums associés</h5>
                    <ul class="list-group mb-3">
                        <?php foreach ($albumsByYear[$year['id']] as $album): ?>
                        <li class="list-group-item">
                            <form method="post" enctype="multipart/form-data" class="row g-2 align-items-center">
                            <input type="hidden" name="album_id" value="<?= $album['id'] ?>">
                            <div class="col-md-4">
                                <input type="text" name="album_title" class="form-control" value="<?= htmlspecialchars($album['album_title']) ?>">
                            </div>
                            <div class="col-md-4">
                                <input type="file" name="album_img" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="album_desc" class="form-control" value="<?= htmlspecialchars($album['album_desc']) ?>">
                            </div>
                            <div class="col-md-1 d-grid">
                                
                          <div class="d-flex gap-2">
                            <button type="submit" name="update_album" class="btn btn-sm btn-success">✔</button>
                            <button type="submit" name="delete_album" class="btn btn-sm btn-danger" onclick="return confirm('Supprimer cet album ?');">🗑</button>
                          </div>

                            </div>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>

                    <h6>Ajouter un nouveau Partenaires</h6>
                    <form method="post" enctype="multipart/form-data" class="row g-3">
                        <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
                        <div class="col-md-6">
                        <input type="text" name="album_title" class="form-control" placeholder="Titre" required>
                        </div>
                        <div class="col-md-6">
                        <input type="file" name="album_img" class="form-control" required>
                        </div>
                        <div class="col-md-12">
                        <textarea name="album_desc" class="form-control" placeholder="Description"></textarea>
                        </div>
                        <div class="col-12">
                        <button type="submit" name="add_album" class="btn btn-primary">Ajouter l'album</button>
                        </div>
                    </form>
                    </div>
                </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <!-- Modal ajout année -->
            <div class="modal fade" id="modalAddYear" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content p-4">
                <div class="modal-header">
                    <h5 class="modal-title">Ajouter une Année</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" enctype="multipart/form-data" class="modal-body row g-3">
                    <div class="col-md-4">
                    <label class="form-label">Année</label>
                    <input type="number" name="year" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                    <label class="form-label">Titre</label>
                    <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                    <label class="form-label">Image</label>
                    <input type="file" name="year_img" class="form-control" required>
                    </div>
                    <div class="col-md-12">
                       <!-- Textarea avec TinyMCE -->
                        <textarea class="form-control" id="partner_desc" name="partner_desc" rows="10" placeholder="Description"></textarea>
                    </div>
                    <div class="col-12">
                    <button type="submit" name="add_year" class="btn btn-success">Ajouter</button>
                    </div>
                </form>
                </div>
            </div>
            </div>
        </div>
    </div><!-- /row -->
</main>

<!-- ############################ Réglementation course ############################ -->
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
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <script src="https://cdn.tiny.cloud/1/ocg6h1zh0bqfzq51xcl7ht600996lxdjpymxlculzjx5q3bd/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
        tinymce.init({
            selector: '#partner_desc',
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
<!-- ############################ Réglementation course ############################ -->

<footer class="text-center py-3 small text-muted"><?= htmlspecialchars($footer) ?></footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

