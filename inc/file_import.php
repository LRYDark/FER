<?php
$alert = '';                             // vide par défaut

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['picture'])) {

    $uploadDir  = __DIR__ . '/../files/_pictures/';
    $origName   = $_FILES['picture']['name'];
    $extension  = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed    = ['jpg','jpeg','png','gif','webp'];

    if (!in_array($extension, $allowed, true)) {

        $alert = '<div class="alert alert-danger" role="alert">
                    Format non autorisé
                  </div>';

    } else {

        $safeName = uniqid('img_', true) . '.' . $extension;

        if (move_uploaded_file($_FILES['picture']['tmp_name'], $uploadDir . $safeName)) {

            // succès
            $alert = '<div class="alert alert-success" role="alert">
                        Fichier enregistré : <strong>' . htmlspecialchars($safeName) . '</strong>
                      </div>';

            // ▼ enregistrez $safeName en base si besoin
        } else {
            $alert = '<div class="alert alert-danger" role="alert">
                        Erreur lors de l’envoi du fichier
                      </div>';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['picture_partner'])) {

    $uploadDir  = __DIR__ . '/../files/_pictures/';
    $origName   = $_FILES['picture_partner']['name'];
    $extension  = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed    = ['jpg','jpeg','png','gif','webp'];

    if (!in_array($extension, $allowed, true)) {

        $alert = '<div class="alert alert-danger" role="alert">
                    Format non autorisé
                  </div>';

    } else {

        $safeName = uniqid('img_', true) . '.' . $extension;

        if (move_uploaded_file($_FILES['picture_partner']['tmp_name'], $uploadDir . $safeName)) {

            // succès
            $alert = '<div class="alert alert-success" role="alert">
                        Fichier enregistré : <strong>' . htmlspecialchars($safeName) . '</strong>
                      </div>';

            // ▼ enregistrez $safeName en base si besoin
        } else {
            $alert = '<div class="alert alert-danger" role="alert">
                        Erreur lors de l’envoi du fichier
                      </div>';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['picture_accueil'])) {

    $uploadDir  = __DIR__ . '/../files/_pictures/';
    $origName   = $_FILES['picture_accueil']['name'];
    $extension  = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed    = ['jpg','jpeg','png','gif','webp'];

    if (!in_array($extension, $allowed, true)) {

        $alert = '<div class="alert alert-danger" role="alert">
                    Format non autorisé
                  </div>';

    } else {

        $safeName = uniqid('img_', true) . '.' . $extension;

        if (move_uploaded_file($_FILES['picture_accueil']['tmp_name'], $uploadDir . $safeName)) {

            // succès
            $alert = '<div class="alert alert-success" role="alert">
                        Fichier enregistré : <strong>' . htmlspecialchars($safeName) . '</strong>
                      </div>';

            // ▼ enregistrez $safeName en base si besoin
        } else {
            $alert = '<div class="alert alert-danger" role="alert">
                        Erreur lors de l’envoi du fichier
                      </div>';
        }
    }
}
?>
