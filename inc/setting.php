<?php
require '../config/config.php';
requireRole(['admin','user','viewer','saisie']);
$role = currentRole();

$stmt = $pdo->prepare(
    'SELECT *
       FROM setting
      WHERE id = :id
      LIMIT 1');
$stmt->execute(['id' => 1]);

$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$assoconnectJs      = $data['assoconnect_js']     ?? null;
$assoconnectIframe  = $data['assoconnect_iframe'] ?? null;
$title  = $data['title']   ?? '';
$picture= $data['picture'] ?? '';  
$footer= $data['footer'] ?? '';  
$titleColor = $data['title_color'] ?? '#ffffff'; 
$registration_fee = $data['registration_fee'] ?? 0;

// accueil
$titleAccueil  = $data['titleAccueil']   ?? '';
$edition = $data['edition'] ?? '';  
$link_instagram  = $data['link_instagram']   ?? '';
$link_facebook = $data['link_facebook'] ?? ''; 
$accueil_active = $data['accueil_active'] ? 1 : 0;
$date_course = $data['date_course'] ?? null;
$date_formatted = $date_course ? date('Y-m-d', strtotime($date_course)) : '';
$picture_partner= $data['picture_partner'] ?? ''; 
$picture_accueil= $data['picture_accueil'] ?? '';
$social_networks = $data['social_networks'] ?? 0;
$link_cancer = $data['link_cancer'] ?? null;
$debogage = $data['debogage'] ? 1 : 0;

// parcours
$titleParcours  = $data['titleParcours']   ?? 'test';
$parcoursDesc = $data['parcoursDesc'] ?? '';  
$picture_parcours= $data['picture_parcours'] ?? ''; 
$picture_gradient= $data['picture_gradient'] ?? ''; 

// reglementation
$div_reglementation = $data['div_reglementation'] ?? ''; 

// Formulaire ---------------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT * FROM forms');
$stmt->execute();

$required_fields = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $required_fields[$row['fields']] = $row['required'] ? 1 : 0;
}

$fields = ['required_name','required_firstname','required_phone','required_email','required_date_of_birth','required_sex','required_city','required_company'];
foreach ($fields as $field) {
    $$field = $required_fields[$field] ?? 0;
}


// Import excel ---------------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT * FROM import');
$stmt->execute();

$import_fields = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $import_fields[$row['fields_bdd']] = $row['fields_excel'] ?? '';
}

$fields = ['inscription_no', 'nom', 'prenom', 'tel', 'email', 'naissance', 'sexe', 'ville', 'entreprise', 'paiement_mode', 'created_at'];
foreach ($fields as $field) {
    $$field = $import_fields[$field] ?? '';
}

/******************************************************************
 * Génère une alerte Bootstrap fermable + auto-dismiss
 *  $type    : success | danger | warning | info …
 *  $message : contenu HTML de l’alerte
 *  $delay   : délai ms avant fermeture auto (0 = pas d’auto-close)
 *****************************************************************/
function makeAlert(string $type, string $message, int $delay = 3000): string
{
    return '
    <div class="alert alert-' . $type . ' alert-dismissible fade show"
         role="alert"
         data-auto-dismiss="' . $delay . '">
        ' . $message . '
        <button type="button" class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Fermer"></button>
    </div>';
}

/* --------------------------------------------------------------------------
   Carte 2 : Configuration général
-------------------------------------------------------------------------- */
$alert = '';                           // message à afficher dans la carte 1
if (isset($_POST['config'])) {

    $debogage = isset($_POST['debogage']) ? 1 : 0;
    $footer = $_POST['footer'] ?? '';
    $newColor = $_POST['title_color'] ?? '#ffffff';
    $registration_fee = isset($_POST['registration_fee'])
                    ? (int) $_POST['registration_fee']   // nouvelle valeur du formulaire
                    : 0;                                 // (ou ton défaut)

    /* validation rapide : hexa #RRGGBB */
    if (!preg_match('/^#[0-9a-f]{6}$/i', $newColor)) {
        $alert = makeAlert('danger', 'Couleur invalide.');
    }

    /* 1) Sécuriser / valider le titre */
    $newTitle = trim($_POST['title'] ?? '');
    if ($newTitle === '') {
         $alert = makeAlert('danger', 'Le titre ne peut pas être vide.');
    } else {

        /* 2) Gérer l’upload d’image (optionnel) */
        $newPicture = $picture;            // par défaut on garde l’ancienne

        if (!empty($_FILES['picture']['name'])) {

            $allowed   = ['jpg','jpeg','png','gif','webp'];
            $uploadDir = '../files/_pictures/';
            $origName  = $_FILES['picture']['name'];
            $ext       = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed, true)) {
                $alert = makeAlert('danger', 'Format d\'image non autorisé.');
            } else {
                $safeName = uniqid('img_', true) . '.' . $ext;
                $tmp      = $_FILES['picture']['tmp_name'];

                if (move_uploaded_file($tmp, $uploadDir . $safeName)) {
                    $newPicture = $safeName;
                } else {
                    $alert = makeAlert('danger', 'Erreur lors de l\'upload de l\'image.');
                }
            }
        }

        /* 3) Si pas d’erreur, mise à jour BD */
        if ($alert === '') {
            $upd = $pdo->prepare(
                'UPDATE setting
                    SET title               = :title,
                        picture             = :picture,
                        title_color         = :color,
                        footer              = :footer,
                        registration_fee    = :fee,
                        debogage            = :debogage
                WHERE id = :id'
            );
            $upd->execute([
                'title'     => $newTitle,
                'picture'   => $newPicture,
                'color'     => $newColor,
                'footer'    => $footer,
                'fee'       => $registration_fee,
                'debogage'  => $debogage,
                'id'        => 1
            ]);

            $alert = makeAlert('success', 'Configuration enregistrée !');

            /* 4) Mettre à jour les variables locales
                  (sinon le formulaire afficherait l’ancien titre) */
            $title   = $newTitle;
            $picture = $newPicture;
            $titleColor = $newColor;
        }
    }
}

/* --------------------------------------------------------------------------
   Carte 1 : Liaison AssoConnect
-------------------------------------------------------------------------- */
$alertAsso = '';
if (isset($_POST['LinkAssoConnect'])) {

    /* a) Lecture & validation */
    $iframe = trim($_POST['assoconnect_iframe'] ?? '');
    $script = trim($_POST['assoconnect_js']     ?? '');

    if ($iframe === '' || $script === '') {
         $alertAsso = makeAlert('danger', 'Les deux champs sont obligatoires.');
    } else {

        /* b) Requête préparée */
        $upd = $pdo->prepare(
            'UPDATE setting
                SET assoconnect_iframe = :iframe,
                    assoconnect_js     = :script
              WHERE id = :id'
        );

        $ok = $upd->execute([
            'iframe' => $iframe,
            'script' => $script,
            'id'     => 1
        ]);

        /* c) Gestion du résultat */
        if ($ok) {
            if ($upd->rowCount() > 0) {
                $alertAsso = makeAlert('success', 'Liaison AssoConnect enregistrée !');
            } else {
                 $alertAsso = makeAlert('warning', 'Aucun changement détecté.', 0); // pas d’auto-close
            }

            /* Mettre à jour les variables pour le pré-remplissage */
            $assoconnectIframe = $iframe;
            $assoconnectJs     = $script;
        } else {
            /* $execute a échoué : on affiche le message renvoyé par PDO */
            $msg  = $upd->errorInfo()[2] ?? 'Erreur inconnue';
            $alertAsso = makeAlert('danger', 'Erreur SQL&nbsp;: ' . htmlspecialchars($msg) , 0); // pas d’auto-close
        }
    }
}

/* --------------------------------------------------------------------------
   Carte 3 : Accueil
-------------------------------------------------------------------------- */
$alertAccueil = '';
if (isset($_POST['accueil'])) {

$edition = $_POST['edition'] ?? '';  
$link_instagram  = $_POST['link_instagram']   ?? '';
$link_facebook = $_POST['link_facebook'] ?? ''; 
$accueil_active = $_POST['accueil_active'] ? 1 : 0;
$date_course = $_POST['date_course'] ?? null;
$date_course = $_POST['date_course'] ?? null;
$social_networks = $_POST['social_networks'] ?? 0;
$link_cancer = $_POST['link_cancer'] ?? null;

if ($date_course) {
    // Ajoute l'heure pour obtenir un format complet TIMESTAMP
    $date_course = $date_course . ' 00:00:00';
} else {
    $date_course = null;
}

/* 1) Sécuriser / valider le titre */
    $newTitleAccueil = trim($_POST['titleAccueil'] ?? '');
    if ($newTitleAccueil === '') {
         $alertAccueil = makeAlert('danger', 'Le titre ne peut pas être vide.');
    } else {
            $allowed   = ['jpg','jpeg','png','gif','webp'];
            $uploadDir = '../files/_pictures/';

        $newPictureAccueil = $picture_accueil; 
        if (!empty($_FILES['picture_accueil']['name'])) {
            $origNameAccueil  = $_FILES['picture_accueil']['name'];
            $extAccueil       = strtolower(pathinfo($origNameAccueil, PATHINFO_EXTENSION));

            if (!in_array($extAccueil, $allowed, true)) {
                $alertAccueil = makeAlert('danger', 'Format d\'image non autorisé.');
            } else {
                $safeNameAccueil = uniqid('img_', true) . '.' . $extAccueil;
                $tmpAccueil      = $_FILES['picture_accueil']['tmp_name'];

                if (move_uploaded_file($tmpAccueil, $uploadDir . $safeNameAccueil)) {
                    $newPictureAccueil = $safeNameAccueil;
                } else {
                    $alertAccueil = makeAlert('danger', 'Erreur lors de l\'upload de l\'image.');
                }
            }
        }

        $newPicturePartner = $picture_partner; 
        if (!empty($_FILES['picture_partner']['name'])) {
            $origNamePartner  = $_FILES['picture_partner']['name'];
            $extPartner       = strtolower(pathinfo($origNamePartner, PATHINFO_EXTENSION));

            if (!in_array($extPartner, $allowed, true)) {
                $alertAccueil = makeAlert('danger', 'Format d\'image non autorisé.');
            } else {
                $safeNamePartner = uniqid('img_', true) . '.' . $extPartner;
                $tmpPartner      = $_FILES['picture_partner']['tmp_name'];

                if (move_uploaded_file($tmpPartner, $uploadDir . $safeNamePartner)) {
                    $newPicturePartner = $safeNamePartner;
                } else {
                    $alertAccueil = makeAlert('danger', 'Erreur lors de l\'upload de l\'image.');
                }
            }
        }

        /* 3) Si pas d’erreur, mise à jour BD */
        if ($alertAccueil === '') {
            $upd = $pdo->prepare(
                'UPDATE setting
                    SET titleAccueil               = :titleAccueil,
                        picture_partner             = :picture_partner,
                        picture_accueil             = :picture_accueil,
                        edition         = :edition,
                        link_instagram              = :link_instagram,
                        link_facebook              = :link_facebook,
                        accueil_active              = :accueil_active,
                        date_course              = :date_course,
                        social_networks              = :social_networks,
                        link_cancer              = :link_cancer
                WHERE id = :id'
            );
            $upd->execute([
                'titleAccueil'     => $newTitleAccueil,
                'picture_partner'   => $newPicturePartner,
                'picture_accueil'   => $newPictureAccueil,
                'edition'     => $edition,
                'link_instagram'    => $link_instagram,
                'link_facebook'    => $link_facebook,
                'accueil_active'    => $accueil_active,
                'date_course'    => $date_course,
                'social_networks'    => $social_networks,
                'link_cancer'    => $link_cancer,
                'id'        => 1
            ]);

            $alertAccueil = makeAlert('success', 'Configuration enregistrée !');

            /* 4) Mettre à jour les variables locales
                  (sinon le formulaire afficherait l’ancien titre) */
            $titleAccueil  = $newTitleAccueil;
            $picture_partner= $newPicturePartner; 
            $picture_accueil= $newPictureAccueil; 
            $date_formatted = $date_course ? date('Y-m-d', strtotime($date_course)) : '';

        }
    }
}

/* --------------------------------------------------------------------------
   Carte 4 : PARCOURS
-------------------------------------------------------------------------- */
$alertParcours = '';
if (isset($_POST['parcours'])) {

$parcoursDesc = $_POST['parcoursDesc'] ?? '';  

/* 1) Sécuriser / valider le titre */
    $newTitleParcours = trim($_POST['titleParcours'] ?? '');
    if ($newTitleParcours === '') {
         $alertParcours = makeAlert('danger', 'Le titre ne peut pas être vide.');
    } else {
            $allowed   = ['jpg','jpeg','png','gif','webp'];
            $uploadDir = '../files/_pictures/';

        $newPictureGradient = $picture_gradient; 
        if (!empty($_FILES['picture_gradient']['name'])) {
            $origNameGradient  = $_FILES['picture_gradient']['name'];
            $extGradient       = strtolower(pathinfo($origNameGradient, PATHINFO_EXTENSION));

            if (!in_array($extGradient, $allowed, true)) {
                $alertParcours = makeAlert('danger', 'Format d\'image non autorisé.');
            } else {
                $safeNameGradient = uniqid('img_', true) . '.' . $extGradient;
                $tmpGradient      = $_FILES['picture_gradient']['tmp_name'];

                if (move_uploaded_file($tmpGradient, $uploadDir . $safeNameGradient)) {
                    $newPictureGradient = $safeNameGradient;
                } else {
                    $alertParcours = makeAlert('danger', 'Erreur lors de l\'upload de l\'image.');
                }
            }
        }

        $newPictureParcours = $picture_parcours; 
        if (!empty($_FILES['picture_parcours']['name'])) {
            $origNameParcours  = $_FILES['picture_parcours']['name'];
            $extParcours       = strtolower(pathinfo($origNameParcours, PATHINFO_EXTENSION));

            if (!in_array($extParcours, $allowed, true)) {
                $alertParcours = makeAlert('danger', 'Format d\'image non autorisé.');
            } else {
                $safeNameParcours = uniqid('img_', true) . '.' . $extParcours;
                $tmpParcours      = $_FILES['picture_parcours']['tmp_name'];

                if (move_uploaded_file($tmpParcours, $uploadDir . $safeNameParcours)) {
                    $newPictureParcours = $safeNameParcours;
                } else {
                    $alertParcours = makeAlert('danger', 'Erreur lors de l\'upload de l\'image.');
                }
            }
        }

        /* 3) Si pas d’erreur, mise à jour BD */
        if ($alertParcours === '') {
            $upd = $pdo->prepare(
                'UPDATE setting
                    SET titleParcours             = :titleParcours,
                        picture_gradient          = :picture_gradient,
                        picture_parcours          = :picture_parcours,
                        parcoursDesc              = :parcoursDesc
                WHERE id = :id'
            );
            $upd->execute([
                'titleParcours'         => $newTitleParcours,
                'picture_gradient'      => $newPictureGradient,
                'picture_parcours'      => $newPictureParcours,
                'parcoursDesc'          => $parcoursDesc,
                'id'        => 1
            ]);

            $alertParcours = makeAlert('success', 'Configuration enregistrée !');

            /* 4) Mettre à jour les variables locales
                  (sinon le formulaire afficherait l’ancien titre) */
            $titleParcours  = $newTitleParcours;
            $picture_gradient = $newPictureGradient; 
            $picture_parcours = $newPictureParcours; 
        }
    }
}

// Upload images
if (isset($_POST['uploadGalerie']) && isset($_FILES['galerieImages'])) {
    $uploadDir = '../files/_parcours/';
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $files = $_FILES['galerieImages'];
    $existing = is_dir($uploadDir) ? array_diff(scandir($uploadDir), ['.', '..']) : [];
    $remaining = 30 - count($existing);

    if (count($files['name']) > $remaining) {
        echo makeAlert('danger', "Vous ne pouvez importer que $remaining image(s) supplémentaires.");
    } else {
        for ($i = 0; $i < count($files['name']); $i++) {
            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $safeName = uniqid('img_', true) . '.' . $ext;
                move_uploaded_file($files['tmp_name'][$i], $uploadDir . $safeName);
            }
        }
        header("Refresh:0"); // recharge la page pour voir les nouvelles images
    }
}

/* --------------------------------------------------------------------------
   Carte 1 : Liaison AssoConnect
-------------------------------------------------------------------------- */
$alertReglementation = '';
if (isset($_POST['reglementation'])) {

    /* a) Lecture & validation */
    $div_reglementation = trim($_POST['div_reglementation'] ?? '');

    /* b) Requête préparée */
    $upd = $pdo->prepare(
        'UPDATE setting
            SET div_reglementation = :div_reglementation
            WHERE id = :id'
    );

    $ok = $upd->execute([
        'div_reglementation' => $div_reglementation,
        'id'     => 1
    ]);

    /* c) Gestion du résultat */
    if ($ok) {
        if ($upd->rowCount() > 0) {
            $alertReglementation = makeAlert('success', 'Réglementation enregistrée !');
        } else {
                $alertReglementation = makeAlert('warning', 'Aucun changement détecté.', 0); // pas d’auto-close
        }
    } else {
        /* $execute a échoué : on affiche le message renvoyé par PDO */
        $msg  = $upd->errorInfo()[2] ?? 'Erreur inconnue';
        $alertReglementation = makeAlert('danger', 'Erreur SQL&nbsp;: ' . htmlspecialchars($msg) , 0); // pas d’auto-close
    }
}

/* --------------------------------------------------------------------------
   Carte : Formulaire
-------------------------------------------------------------------------- */
$alertRequired = '';
if (isset($_POST['required'])) {
    $field_keys = [
        'required_name',
        'required_firstname',
        'required_phone',
        'required_email',
        'required_date_of_birth',
        'required_sex',
        'required_city',
        'required_company',
    ];

    $required_fields = [];
    foreach ($field_keys as $field) {
        $required_fields[$field] = isset($_POST[$field]) ? 1 : 0;
    }

    $upd = $pdo->prepare('UPDATE forms SET required = :required WHERE fields = :fields');

    foreach ($required_fields as $field => $required) {
        $upd->execute([
            'required' => $required,
            'fields' => $field
        ]);
    }

    $alertRequired = makeAlert('success', 'Configuration enregistrée !');

    // Création dynamique des variables
    foreach ($field_keys as $field) {
        $$field = $_POST[$field] ?? 0;
    }
}

/* --------------------------------------------------------------------------
   Carte : Import excel
-------------------------------------------------------------------------- */
$alertImport = '';
if (isset($_POST['importExcel'])) {
    $field_keys = [
        'inscription_no',
        'nom',
        'prenom',
        'tel',
        'email',
        'naissance',
        'sexe',
        'ville',
        'paiement_mode',
        'date',
        'entreprise',
    ];

    $import_fields = [];
    foreach ($field_keys as $key) {
        $import_fields[$key] = $_POST[$key] ?? '';
    }

    $upd = $pdo->prepare('UPDATE import SET fields_excel = :fields_excel WHERE fields_bdd = :fields_bdd');

    foreach ($import_fields as $bdd_field => $import) {
        $upd->execute([
            'fields_excel' => $import,
            'fields_bdd' => $bdd_field
        ]);
    }

    $alertImport = makeAlert('success', 'Configuration enregistrée !');

    foreach ($field_keys as $key) {
        $$key = $_POST[$key] ?? '';
    }
}

/* --------------------------------------------------------------------------
   Suppression image
-------------------------------------------------------------------------- */
if (isset($_POST['delete_picture_parcours']) && $picture_parcours) {
    $filePath = '../files/_pictures/' . $picture_parcours;
    if (file_exists($filePath)) {
        unlink($filePath); // Supprime le fichier
    }

    // Supprime la référence dans la base de données
    $stmt = $pdo->prepare('UPDATE setting SET picture_parcours = NULL WHERE id = :id');
    $stmt->execute(['id' => 1]);

    $picture_parcours = ''; // Met à jour la variable locale
    $alertParcours = makeAlert('success', 'Image supprimée avec succès.');
}
if (isset($_POST['delete_picture_gradient']) && $picture_gradient) {
    $filePath = '../files/_pictures/' . $picture_gradient;
    if (file_exists($filePath)) {
        unlink($filePath); // Supprime le fichier
    }

    // Supprime la référence dans la base de données
    $stmt = $pdo->prepare('UPDATE setting SET picture_gradient = NULL WHERE id = :id');
    $stmt->execute(['id' => 1]);

    $picture_gradient = ''; // Met à jour la variable locale
    $alertParcours = makeAlert('success', 'Image supprimée avec succès.');
}
if (isset($_POST['delete_picture_accueil']) && $picture_accueil) {
    $filePath = '../files/_pictures/' . $picture_accueil;
    if (file_exists($filePath)) {
        unlink($filePath); // Supprime le fichier
    }

    // Supprime la référence dans la base de données
    $stmt = $pdo->prepare('UPDATE setting SET picture_accueil = NULL WHERE id = :id');
    $stmt->execute(['id' => 1]);

    $picture_accueil = ''; // Met à jour la variable locale
    $alertAccueil = makeAlert('success', 'Image supprimée avec succès.');
}
if (isset($_POST['delete_picture_partner']) && $picture_partner) {
    $filePath = '../files/_pictures/' . $picture_partner;
    if (file_exists($filePath)) {
        unlink($filePath); // Supprime le fichier
    }

    // Supprime la référence dans la base de données
    $stmt = $pdo->prepare('UPDATE setting SET picture_partner = NULL WHERE id = :id');
    $stmt->execute(['id' => 1]);

    $picture_partner = ''; // Met à jour la variable locale
    $alertAccueil = makeAlert('success', 'Image supprimée avec succès.');
}
if (isset($_POST['delete_picture']) && $picture) {
    $filePath = '../files/_pictures/' . $picture;
    if (file_exists($filePath)) {
        unlink($filePath); // Supprime le fichier
    }

    // Supprime la référence dans la base de données
    $stmt = $pdo->prepare('UPDATE setting SET picture = NULL WHERE id = :id');
    $stmt->execute(['id' => 1]);

    $picture = ''; // Met à jour la variable locale
    $alert = makeAlert('success', 'Image supprimée avec succès.');
}

// Suppression image modal
if (isset($_POST['deleteImage'])) {
    $fileToDelete = basename($_POST['deleteImage']);
    $path = '../files/_parcours/' . $fileToDelete;
    if (file_exists($path)) {
        if (unlink($path)) {
            echo 'OK';
            exit; // ✅ Ajoute ceci pour empêcher le reste du HTML d’être renvoyé
        } else {
            http_response_code(500);
            echo 'Erreur lors de la suppression du fichier.';
            exit;
        }
    } else {
        http_response_code(404);
        echo 'Fichier introuvable.';
        exit;
    }
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
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.alert').forEach(alertEl => {
    // ferme après 3 000 ms
    setTimeout(() => {
      // ferme proprement (même animation que le bouton « X »)
      bootstrap.Alert.getOrCreateInstance(alertEl).close();
    }, 5000);
  });
});
</script>
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
</style>
</head>

<body class="d-flex flex-column">

<?php include '../inc/nav-settings.php'; ?>

<!-- ═════════ MAIN ═════════ -->
<main class="container-fluid flex-grow-1">

    <!-- Une seule .row -->
    <div class="row g-4 align-items-stretch"><!-- align-items-stretch => les cartes prennent la même hauteur -->
        <!-- Colonne GAUCHE : 2 petites cartes empilées -->
        <div class="col-12 col-lg-4 d-flex flex-column gap-4">
            <!-- Carte 1  -->
            <div class="card-dashboard p-4 shadow-sm rounded-4 bg-white flex-grow-0">
                <!-- …contenu Liaison AssoConnect (carte 1)… -->
                <h2 class="mb-4">Liaison AssoConnect</h2>
                    <?php if ($alertAsso) echo $alertAsso; ?>
                    <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
                        <div class="form-group mb-3">
                            <label for="divCode">Code DIV Assoconnect</label>
                            <input type="text"
                                class="form-control"
                                id="divCode"
                                name="assoconnect_iframe"
                                placeholder="&lt;div class=…&gt;"
                                value="<?= htmlspecialchars($assoconnectIframe, ENT_QUOTES, 'UTF-8'); ?>"
                                required>
                        </div>

                        <div class="form-group mb-3">
                            <label for="scriptCode">Code Script Assoconnect</label>
                            <input type="text"
                                class="form-control"
                                id="scriptCode"
                                name="assoconnect_js"
                                placeholder="&lt;script src=…&gt;"
                                value="<?= htmlspecialchars($assoconnectJs, ENT_QUOTES, 'UTF-8'); ?>"
                                required>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" name="LinkAssoConnect" class="btn btn-primary w-auto">Sauvegarder</button>
                        </div>
                    </form>
            </div>

            <!-- Carte 2 -->
            <div class="card-dashboard p-4 shadow-sm rounded-4 bg-white flex-grow-0">
                <!-- …contenu Configuration générale (carte 2)… -->
                <h2 class="mb-4">Configuration générale</h2>
                    <!-- Message de succès / erreur -->
                    <?php if ($alert) echo $alert; ?>
                    <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
                        <div class="col-md-6"><label class="form-label">Titre</label>
                            <input type="text"
                                class="form-control"
                                id="divCode"
                                name="title"
                                placeholder="Titre"
                                value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>"
                                required>
                        </div>
                        <!-- COULEUR DU TITRE (nouveau) -->
                        <div class="col-md-3"><label class="form-label">Couleur du titre</label>
                            <input type="color"
                                class="form-control form-control-color"
                                id="titleColor"
                                name="title_color"
                                value="<?= htmlspecialchars($titleColor ?? '#000000'); ?>"
                                title="Choisissez la couleur">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Activer le debogage</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="debogage" id="debogage" <?= isset($debogage) && $debogage ? 'checked' : '' ?>>
                                <label class="form-check-label" for="debogage">Oui / Non</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="picture" class="form-label">Image d'entête</label>
                            <input type="file"
                                class="form-control"
                                id="picture"
                                name="picture"
                                accept="image/*">
                            <?php if ($picture) : ?>
                                <small class="text-muted">Image actuelle : <?= htmlspecialchars($picture) ?></small>
                                <div class="mb-2">
                                    <img src="../files/_pictures/<?= rawurlencode($picture) ?>"
                                        alt="Image actuelle"
                                        class="img-thumbnail"
                                        style="max-width:145px;">
                                </div>
                                <button type="submit" name="delete_picture" value="1" class="btn btn-danger btn-sm">
                                    Supprimer l'image
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6"><label class="form-label">Bas de page</label>
                            <input type="text"
                                class="form-control"
                                id="footer"
                                name="footer"
                                placeholder="Bas de page"
                                value="<?= htmlspecialchars($footer, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6"><label class="form-label">Montant de l’inscription</label>
                            <select id="registration_fee" name="registration_fee"class="form-select">
                                <?php for ($i = 0; $i <= 100; $i++): ?>
                                <option value="<?= $i ?>"
                                        <?= ($i == (int)$registration_fee ? 'selected' : '') ?>>
                                    <?= $i ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" name="config" class="btn btn-primary w-auto">Sauvegarder</button>
                        </div>
                    </form>
            </div>

        </div><!-- /col gauche -->

        <!-- Colonne DROITE : 1 grande carte -->
        <div class="col-12 col-lg-8">
        <div class="card-dashboard p-4 shadow-sm rounded-4 bg-white h-100">
            <!-- …contenu Grandes infos (carte 3)… -->
            <h2 class="mb-4">Réglage page accueil</h2>
                <?php if ($alertAccueil) echo $alertAccueil; ?>
                <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">

                    <div class="col-md-6"><label class="form-label">Titre de l'accueil</label>
                        <input type="text" class="form-control" name="titleAccueil" placeholder="Titre de l'accueil" value="<?= htmlspecialchars($titleAccueil, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-6"><label class="form-label">Edition</label>
                        <input type="text" class="form-control" name="edition" placeholder="Edition" value="<?= htmlspecialchars($edition, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-6"><label class="form-label">Lien Facebook</label>
                        <input type="text" class="form-control" name="link_facebook" placeholder="Lien Facebook" value="<?= htmlspecialchars($link_facebook, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-6"><label class="form-label">Lien Instagram</label>
                        <input type="text" class="form-control" name="link_instagram" placeholder="Lien Instagram" value="<?= htmlspecialchars($link_instagram, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Date de publication</label>
                        <input type="date" class="form-control" name="date_course" value="<?= htmlspecialchars($date_formatted, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Activer les inscriptions</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="accueil_active" id="accueil_active" <?= isset($accueil_active) && $accueil_active ? 'checked' : '' ?>>
                            <label class="form-check-label" for="accueil_active">Oui / Non</label>
                        </div>
                    </div>
                    <div class="col-md-6"><label class="form-label">Image d'accueil</label>
                        <input type="file"
                            class="form-control"
                            id="picture_accueil"
                            name="picture_accueil"
                            accept="image/*">
                        <?php if ($picture_accueil) : ?>
                            <small class="text-muted">Image actuelle : <?= htmlspecialchars($picture_accueil) ?></small>
                            <div class="mb-2">
                                <img src="../files/_pictures/<?= rawurlencode($picture_accueil) ?>"
                                    alt="Image actuelle"
                                    class="img-thumbnail"
                                    style="max-width:145px;">
                            </div>
                            <button type="submit" name="delete_picture_accueil" value="1" class="btn btn-danger btn-sm">
                                Supprimer l'image
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6"><label class="form-label">Image des partenaires</label>
                        <input type="file"
                            class="form-control"
                            id="picture_partner"
                            name="picture_partner"
                            accept="image/*">
                    <?php if ($picture_partner) : ?>
                        <small class="text-muted">Image actuelle : <?= htmlspecialchars($picture_partner) ?></small>
                        <div class="mb-2">
                            <img src="../files/_pictures/<?= rawurlencode($picture_partner) ?>"
                                alt="Image actuelle"
                                class="img-thumbnail"
                                style="max-width:145px;">
                        </div>
                        <button type="submit" name="delete_picture_partner" value="1" class="btn btn-danger btn-sm">
                            Supprimer l'image
                        </button>
                    <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label for="social_networks" class="form-label">Position - Réseaux sociaux</label>
                        <select name="social_networks" id="social_networks" class="form-select">
                            <option value="0" <?= $social_networks == 0 ? 'selected' : '' ?>>Désactivé</option>
                            <option value="1" <?= $social_networks == 1 ? 'selected' : '' ?>>Gauche</option>
                            <option value="2" <?= $social_networks == 2 ? 'selected' : '' ?>>Droite</option>
                            <option value="3" <?= $social_networks == 3 ? 'selected' : '' ?>>Centré</option>
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label">Lien de la ligne contre le cancer</label>
                        <input type="text" class="form-control" name="link_cancer" placeholder="Lien de la ligne contre le cancer" value="<?= htmlspecialchars($link_cancer, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" name="accueil" class="btn btn-primary w-auto">Sauvegarder</button>
                    </div>
                </form>
        </div>
        </div><!-- /col droite -->

        <!-- Colonne DROITE : 1 grande carte -->
        <div class="col-12 col-lg-6">
        <div class="card-dashboard p-4 shadow-sm rounded-4 bg-white h-100">
            <!-- …contenu Grandes infos (carte 3)… -->
            <h2 class="mb-4">Parcours</h2>
                <?php if ($alertParcours) echo $alertParcours; ?>
                <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
                    <div class="col-md-6"><label class="form-label">Titre de l'image principale</label>
                        <input type="text" class="form-control" name="titleParcours" placeholder="Titre de l'image principale" value="<?= htmlspecialchars($titleParcours, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Description du parcours</label>
                        <textarea class="form-control" name="parcoursDesc" placeholder="Description du parcours" rows="3"><?= htmlspecialchars($parcoursDesc, ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <div class="col-md-6"><label class="form-label">Image principale</label>
                        <input type="file"
                            class="form-control"
                            id="picture_parcours"
                            name="picture_parcours"
                            accept="image/*">
                    <?php if ($picture_parcours) : ?>
                        <small class="text-muted">Image actuelle : <?= htmlspecialchars($picture_parcours) ?></small>
                        <div class="mb-2">
                            <img src="../files/_pictures/<?= rawurlencode($picture_parcours) ?>"
                                alt="Image actuelle"
                                class="img-thumbnail"
                                style="max-width:145px;">
                        </div>
                        <button type="submit" name="delete_picture_parcours" value="1" class="btn btn-danger btn-sm">
                            Supprimer l'image
                        </button>
                    <?php endif; ?>
                    </div>
                    <div class="col-md-6"><label class="form-label">Image du dénivelé</label>
                        <input type="file"
                            class="form-control"
                            id="picture_gradient"
                            name="picture_gradient"
                            accept="image/*">
                    <?php if ($picture_gradient) : ?>
                        <small class="text-muted">Image actuelle : <?= htmlspecialchars($picture_gradient) ?></small>
                        <div class="mb-2">
                            <img src="../files/_pictures/<?= rawurlencode($picture_gradient) ?>"
                                alt="Image actuelle"
                                class="img-thumbnail"
                                style="max-width:145px;">
                        </div>
                        <button type="submit" name="delete_picture_gradient" value="1" class="btn btn-danger btn-sm">
                            Supprimer l'image
                        </button>
                    <?php endif; ?>
                    </div>
                    <div class="col-12">
                        <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#modalGalerie">
                            Gérer la galerie d'images
                        </button>
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" name="parcours" class="btn btn-primary w-auto">Sauvegarder</button>
                    </div>
                </form>
        </div>
        </div><!-- /col droite -->

        <?php
            $galerieDir = '../files/_parcours/';
            $images = is_dir($galerieDir) ? array_diff(scandir($galerieDir), ['.', '..']) : [];
            $maxImages = 30;
            $remaining = $maxImages - count($images);
        ?>
        <div class="modal fade" id="modalGalerie" tabindex="-1" aria-labelledby="modalGalerieLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Galerie d'images du parcours</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <!-- Formulaire d'import -->
                <form id="uploadForm" action="" method="post" enctype="multipart/form-data" class="mb-4">
                    <label for="galerieImages" class="form-label">
                    Importer jusqu'à <span id="remainingCount"><?= $remaining ?></span> image(s) :
                    </label>
                    <input type="file" name="galerieImages[]" id="galerieImages" class="form-control" accept="image/*" multiple <?= $remaining <= 0 ? 'disabled' : '' ?>>
                <button type="submit" name="uploadGalerie" class="btn btn-primary mt-2" <?= $remaining <= 0 ? 'disabled' : '' ?>>Importer</button>
                <?php if ($remaining <= 0): ?>
                    <div class="text-danger mt-2">Limite de 30 images atteinte. Supprimez des images pour en ajouter.</div>
                <?php endif; ?>
                </form>

                <!-- Galerie d'images -->
                <div class="row" id="galerieContainer">
                <?php foreach ($images as $img): ?>
                    <div class="col-md-3 text-center mb-4" data-img="<?= htmlspecialchars($img) ?>">
                        <img src="<?= $galerieDir . rawurlencode($img) ?>" class="img-thumbnail" style="max-height: 150px;">
                        <form class="deleteForm mt-2">
                            <input type="hidden" name="deleteImage" value="<?= htmlspecialchars($img) ?>">
                            <button type="button" class="btn btn-sm btn-danger delete-btn">Supprimer</button>
                        </form>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            </div>
        </div>
        </div>

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

        <div class="col-12 col-lg-6 d-flex flex-column gap-4">
            <!-- Carte 1  -->
            <div class="card-dashboard p-4 shadow-sm rounded-4 bg-white flex-grow-0">
                <h2 class="mb-4">Réglement de la course</h2>
                 <?php if ($alertReglementation) echo $alertReglementation; ?>
                <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
                    <div class="form-group mb-3">
                        <label for="divReglementation" class="form-label">Réglement de la course</label>
                        
                        <!-- Textarea avec TinyMCE -->
                        <textarea class="form-control" id="divReglementation" name="div_reglementation" rows="10" required>
                            <?= htmlspecialchars($div_reglementation) ?>
                        </textarea>
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" name="reglementation" class="btn btn-primary w-auto">Sauvegarder</button>
                    </div>
                </form>
            </div>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.7.0/tinymce.min.js"></script>
            <script>
                tinymce.init({
                    selector: '#divReglementation',
                    plugins: 'anchor autolink charmap codesample emoticons image link lists media searchreplace table visualblocks wordcount code',
                    toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat | code',
                    height: 430,
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

                        <div class="col-12 col-lg-6 d-flex flex-column gap-4">
            <!-- Carte 7 -->
            <div class="card-dashboard p-4 shadow-sm rounded-4 bg-white flex-grow-0">
                <h2 class="mb-4">Informations d'import excel</h2>
                 <?php if ($alertImport) echo $alertImport; ?>
                <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
                    <div class="col-md-4"><label class="form-label">N° d'inscription</label>
                        <input type="text" class="form-control" name="inscription_no" value="<?= htmlspecialchars($inscription_no, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label">Nom = </label>
                        <input type="text" class="form-control" name="nom" value="<?= htmlspecialchars($nom, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label">Prénom =</label>
                        <input type="text" class="form-control" name="prenom" value="<?= htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label">Téléphone =</label>
                        <input type="text" class="form-control" name="tel" value="<?= htmlspecialchars($tel, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label">Email =</label>
                        <input type="text" class="form-control" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label">Date de naissance =</label>
                        <input type="text" class="form-control" name="naissance" value="<?= htmlspecialchars($naissance, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label">Sexe =</label>
                        <input type="text" class="form-control" name="sexe" value="<?= htmlspecialchars($sexe, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label">Ville =</label>
                        <input type="text" class="form-control" name="ville" value="<?= htmlspecialchars($ville, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label">Entreprise =</label>
                        <input type="text" class="form-control" name="entreprise" value="<?= htmlspecialchars($entreprise, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label">Moyen de paiement =</label>
                        <input type="text" class="form-control" name="paiement_mode" value="<?= htmlspecialchars($paiement_mode, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label">Date d'inscription =</label>
                        <input type="text" class="form-control" name="created_at" value="<?= htmlspecialchars($created_at, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" name="importExcel" class="btn btn-primary w-auto">Sauvegarder</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-12 col-lg-6 d-flex flex-column gap-4">
            <!-- Carte 6 -->
            <div class="card-dashboard p-4 shadow-sm rounded-4 bg-white flex-grow-0">
                <h2 class="mb-4">Formulaire : Champs requis</h2>
                 <?php if ($alertRequired) echo $alertRequired; ?>
                <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
                    <div class="col-md-6">
                        <label class="form-label">Nom</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="required_name" id="required_name" <?= isset($required_name) && $required_name ? 'checked' : '' ?>>
                            <label class="form-check-label" for="required_name">Oui / Non</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Prénom</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="required_firstname" id="required_firstname" <?= isset($required_firstname) && $required_firstname ? 'checked' : '' ?>>
                            <label class="form-check-label" for="required_firstname">Oui / Non</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Téléphone</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="required_phone" id="required_phone" <?= isset($required_phone) && $required_phone ? 'checked' : '' ?>>
                            <label class="form-check-label" for="required_phone">Oui / Non</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email </label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="required_email" id="required_email" <?= isset($required_email) && $required_email ? 'checked' : '' ?>>
                            <label class="form-check-label" for="required_email">Oui / Non</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Date de naissance</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="required_date_of_birth" id="required_date_of_birth" <?= isset($required_date_of_birth) && $required_date_of_birth ? 'checked' : '' ?>>
                            <label class="form-check-label" for="required_date_of_birth">Oui / Non</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Sexe</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="required_sex" id="required_sex" <?= isset($required_sex) && $required_sex ? 'checked' : '' ?>>
                            <label class="form-check-label" for="required_sex">Oui / Non</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Ville</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="required_city" id="required_city" <?= isset($required_city) && $required_city ? 'checked' : '' ?>>
                            <label class="form-check-label" for="required_city">Oui / Non</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Entreprise</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="required_company" id="required_company" <?= isset($required_company) && $required_company ? 'checked' : '' ?>>
                            <label class="form-check-label" for="required_company">Oui / Non</label>
                        </div>
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" name="required" class="btn btn-primary w-auto">Sauvegarder</button>
                    </div>
                </form>
            </div>
        </div>
    </div><!-- /row -->
</main>

<footer class="text-center py-3 small text-muted"><?= htmlspecialchars($footer) ?></footer>

<!-- ═════════ JS ═════════ -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.js"></script>
<script>
/* ══ LOGOUT ════ */
$('#logout, #logout_m').on('click',e=>{
  e.preventDefault();
  fetch('../config/api.php?route=logout').then(()=>location='../login.php');
});

// images parcours
document.getElementById('galerieImages')?.addEventListener('change', function () {
  const max = <?= $remaining ?>;
  if (this.files.length > max) {
    alert(`Vous ne pouvez sélectionner que ${max} image(s) maximum.`);
    this.value = '';
  }
});

document.addEventListener('DOMContentLoaded', () => {
  const maxImages = 30;
  const input = document.getElementById('galerieImages');
  const countSpan = document.getElementById('remainingCount');
  const uploadBtn = document.querySelector('button[name="uploadGalerie"]');

  // Validation dynamique à la sélection
  input?.addEventListener('change', function () {
    const remaining = parseInt(countSpan?.textContent || '0');
    if (this.files.length > remaining) {
      alert(`Vous ne pouvez sélectionner que ${remaining} image(s) maximum.`);
      this.value = '';
    }
  });

  // Suppression dynamique
  document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const form = this.closest('.deleteForm');
      const imageName = form.querySelector('input[name="deleteImage"]').value;

      fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ deleteImage: imageName })
      })
      .then(response => response.text())
      .then(result => {
        if (result.trim() === 'OK') {
          const container = form.closest('[data-img]');
          container.remove();

          // 🔄 Met à jour le compteur
          let current = parseInt(countSpan.textContent);
          if (!isNaN(current) && current < maxImages) {
            current += 1;
            countSpan.textContent = current;
          }

          // ✅ Réactive le champ d'import si désactivé
          if (input && input.disabled) input.disabled = false;
          if (uploadBtn && uploadBtn.disabled) uploadBtn.disabled = false;
        } else {
          alert("Erreur lors de la suppression : " + result);
        }
      })
      .catch(error => {
        alert("Erreur réseau : " + error);
      });
    });
  });
});

</script>