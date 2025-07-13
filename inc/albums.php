<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../config/config.php';
requireRole(['admin','user','viewer','saisie']);
$role = currentRole();
if ($role !== 'admin') {
  header('Location: ../login.php');
  exit;
}

if (isset($_POST['update_year'])) {
  $yearId = $_POST['year_id'];
  $year = $_POST['year'];
  $title = $_POST['title'];

  // Gestion image si uploadé
  if (!empty($_FILES['year_img']['name'])) {
    $imgName = $_FILES['year_img']['name'];
    move_uploaded_file($_FILES['year_img']['tmp_name'], "../files/_pictures/" . $imgName);
    $stmt = $pdo->prepare("UPDATE photo_years SET year = ?, title = ?, img = ? WHERE id = ?");
    $stmt->execute([$year, $title, $imgName, $yearId]);
  } else {
    $stmt = $pdo->prepare("UPDATE photo_years SET year = ?, title = ? WHERE id = ?");
    $stmt->execute([$year, $title, $yearId]);
  }
}

if (isset($_POST['update_album'])) {
  $albumId = $_POST['album_id'];
  $album_title = $_POST['album_title'];
  $album_link = $_POST['album_link'];
  $album_desc = $_POST['album_desc'];

  if (!empty($_FILES['album_img']['name'])) {
    $imgName = $_FILES['album_img']['name'];
    move_uploaded_file($_FILES['album_img']['tmp_name'], "../files/_pictures/" . $imgName);
    $stmt = $pdo->prepare("UPDATE photo_albums SET album_title = ?, album_link = ?, album_img = ?, album_desc = ? WHERE id = ?");
    $stmt->execute([$album_title, $album_link, $imgName, $album_desc, $albumId]);
  } else {
    $stmt = $pdo->prepare("UPDATE photo_albums SET album_title = ?, album_link = ?, album_desc = ? WHERE id = ?");
    $stmt->execute([$album_title, $album_link, $album_desc, $albumId]);
  }
}

// Traitement ajout année
if (isset($_POST['add_year'])) {
  $stmt = $pdo->prepare("INSERT INTO photo_years (year, title, img) VALUES (?, ?, ?)");
  $imgName = $_FILES['year_img']['name'];
  move_uploaded_file($_FILES['year_img']['tmp_name'], "../files/_pictures/" . $imgName);
  $stmt->execute([$_POST['year'], $_POST['title'], $imgName]);
}

// Traitement ajout album
if (isset($_POST['add_album'])) {
  $stmt = $pdo->prepare("INSERT INTO photo_albums (year_id, album_title, album_link, album_img, album_desc) VALUES (?, ?, ?, ?, ?)");
  $imgName = $_FILES['album_img']['name'];
  move_uploaded_file($_FILES['album_img']['tmp_name'], "../files/_pictures/" . $imgName);
  $stmt->execute([
    $_POST['year_id'],
    $_POST['album_title'],
    $_POST['album_link'],
    $imgName,
    $_POST['album_desc']
  ]);
}

// Traitement suppression album
if (isset($_POST['delete_album'])) {
  $stmt = $pdo->prepare("DELETE FROM photo_albums WHERE id = ?");
  $stmt->execute([$_POST['album_id']]);
}

// Traitement suppression année + albums associés
if (isset($_POST['delete_year'])) {
  $stmt1 = $pdo->prepare("DELETE FROM photo_albums WHERE year_id = ?");
  $stmt1->execute([$_POST['year_id']]);
  $stmt2 = $pdo->prepare("DELETE FROM photo_years WHERE id = ?");
  $stmt2->execute([$_POST['year_id']]);
}

// Récupération des années et albums
$years = $pdo->query("SELECT * FROM photo_years ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
$albumsByYear = [];
foreach ($years as $y) {
  $stmt = $pdo->prepare("SELECT * FROM photo_albums WHERE year_id = ?");
  $stmt->execute([$y['id']]);
  $albumsByYear[$y['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Gestion Albums</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-4">

<h1 class="mb-4">Gestion des Albums par Année</h1>

<!-- Bouton pour ajouter une année -->
<button class="btn btn-primary mb-4" data-bs-toggle="modal" data-bs-target="#modalAddYear">Ajouter une Année</button>

<div class="row g-4">
<?php foreach ($years as $year): ?>
  <div class="col-md-6">
    <div class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong><?= htmlspecialchars($year['year']) ?> - <?= htmlspecialchars($year['title']) ?></strong>
        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalYear<?= $year['id'] ?>">Modifier</button>
      </div>
      <div class="card-body">
        <img src="../files/_pictures/<?= htmlspecialchars($year['img']) ?>" class="img-fluid mb-3" style="max-height:150px;">
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
                <input type="text" name="year" class="form-control" value="<?= htmlspecialchars($year['year']) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">Titre</label>
                <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($year['title']) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">Image</label>
                <input type="file" name="year_img" class="form-control">
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
                  <div class="col-md-3">
                    <input type="text" name="album_title" class="form-control" value="<?= htmlspecialchars($album['album_title']) ?>">
                  </div>
                  <div class="col-md-3">
                    <input type="text" name="album_link" class="form-control" value="<?= htmlspecialchars($album['album_link']) ?>">
                  </div>
                  <div class="col-md-3">
                    <input type="file" name="album_img" class="form-control">
                  </div>
                  <div class="col-md-2">
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

          <h6>Ajouter un nouvel album</h6>
          <form method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="year_id" value="<?= $year['id'] ?>">
            <div class="col-md-4">
              <input type="text" name="album_title" class="form-control" placeholder="Titre" required>
            </div>
            <div class="col-md-4">
              <input type="url" name="album_link" class="form-control" placeholder="Lien">
            </div>
            <div class="col-md-4">
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
          <input type="text" name="year" class="form-control" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Titre</label>
          <input type="text" name="title" class="form-control" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Image</label>
          <input type="file" name="year_img" class="form-control" required>
        </div>
        <div class="col-12">
          <button type="submit" name="add_year" class="btn btn-success">Ajouter</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
