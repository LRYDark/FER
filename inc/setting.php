<?php
require '../config/config.php';
require_once __DIR__ . '/../config/csrf.php';
// 🔒 [FIX-SETTING] Chargement lazy de googleMail pour éviter HTTP 500 si lib indisponible (CWE-755)
try {
    require '../config/googleMail.php';
} catch (\Throwable $e) {
    $isConnected = false;
    $authUrl = '#';
    error_log('googleMail load error: ' . $e->getMessage());
}

requireRole(['admin','user','viewer','saisie']);
$role = currentRole();

require 'navbar-data.php';

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
$link_cancer = $data['link_cancer'] ?? null;
$debogage = $data['debogage'] ? 1 : 0;

// parcours
$titleParcours  = $data['titleParcours']   ?? 'test';
$parcoursDesc = $data['parcoursDesc'] ?? '';  
$picture_parcours= $data['picture_parcours'] ?? ''; 
$picture_gradient= $data['picture_gradient'] ?? ''; 

// reglementation
$div_reglementation = $data['div_reglementation'] ?? ''; 

// google
$client_id = decrypt($data['client_id'] ?? '');
$client_secret = decrypt($data['client_secret'] ?? '');
$hasMailFields = false;
try { $pdo->query("SELECT mail_email FROM setting LIMIT 0"); $hasMailFields = true; } catch (PDOException $e) {}
$mail_email = $data['mail_email'] ?? '';
$mail_phone = $data['mail_phone'] ?? '';

// Traitement des messages de retour OAuth
if (isset($_GET['auth'])) {
    if ($_GET['auth'] === 'success') {
        $message = "✅ Connexion Google établie avec succès !";
        $messageClass = 'success';
    } elseif ($_GET['auth'] === 'error') {
        $errorMsg = $_GET['message'] ?? 'Erreur inconnue';
        $message = "❌ Erreur lors de la connexion : " . htmlspecialchars($errorMsg);
        $messageClass = 'error';
    }
}

// ─── CSRF check for all POST actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(403);
    die('Invalid CSRF token');
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'test_connection':
                $connectionStatus = isGoogleConnectionValid();
                $message = $connectionStatus ? 
                    "✅ Connexion Google OK - Prêt à envoyer des emails" : 
                    "❌ Connexion Google non valide";
                $messageClass = $connectionStatus ? 'success' : 'error';
                break;
                
            case 'send_test_mail':
                $adminEmail = $_SESSION['email'] ?? '';
                if ($adminEmail && isGoogleConnectionValid()) {
                    $result = sendMail(
                        $adminEmail,
                        'Mail de test - Forbach en Rose',
                        'Test réussi !',
                        'Ce mail de test confirme que la configuration email fonctionne correctement. Vous pouvez envoyer des emails depuis votre application Forbach en Rose.',
                        null,
                        null,
                        'info'
                    );
                    if ($result) {
                        $message = "✅ Mail de test envoyé avec succès à " . htmlspecialchars($adminEmail);
                        $messageClass = 'success';
                    } else {
                        $message = "❌ Échec de l'envoi du mail de test";
                        $messageClass = 'error';
                    }
                } else {
                    $message = "❌ Connexion Google non valide ou email admin introuvable";
                    $messageClass = 'error';
                }
                break;

            case 'disconnect':
                if (revokeGoogleConnection()) {
                    $message = "✅ Déconnexion Google effectuée";
                    $messageClass = 'success';
                } else {
                    $message = "❌ Erreur lors de la déconnexion";
                    $messageClass = 'error';
                }
                break;
        }
    }
}

// Vérifier l'état actuel de la connexion
$isConnected = false;
$authUrl = '#';
try {
    $isConnected = isGoogleConnectionValid();
    $authUrl = getGoogleAuthUrl('setting.php');
} catch (\Throwable $e) {
    // Google OAuth not configured or error - ignore
}

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
 *  $message : contenu HTML de l'alerte
 *  $delay   : délai ms avant fermeture auto (0 = pas d'auto-close)
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

        /* 2) Gérer l'upload d'image (optionnel) */
        $newPicture = $picture;            // par défaut on garde l'ancienne

        if (!empty($_FILES['picture']['name'])) {

            $allowed      = ['jpg','jpeg','png','gif','webp'];
            $allowedMime  = ['image/jpeg','image/png','image/gif','image/webp'];
            $uploadDir    = '../files/_pictures/';
            $ext          = strtolower(pathinfo($_FILES['picture']['name'], PATHINFO_EXTENSION));
            $finfo        = new finfo(FILEINFO_MIME_TYPE);
            $mimeType     = $finfo->file($_FILES['picture']['tmp_name']);

            if (!in_array($ext, $allowed, true) || !in_array($mimeType, $allowedMime, true)) {
                $alert = makeAlert('danger', 'Format d\'image non autorisé.');
            } elseif ($_FILES['picture']['size'] > 5 * 1024 * 1024) {
                $alert = makeAlert('danger', 'Image trop volumineuse (max 5 Mo).');
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

        /* 3) Si pas d'erreur, mise à jour BD */
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
                  (sinon le formulaire afficherait l'ancien titre) */
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
                 $alertAsso = makeAlert('warning', 'Aucun changement détecté.', 0); // pas d'auto-close
            }

            /* Mettre à jour les variables pour le pré-remplissage */
            $assoconnectIframe = $iframe;
            $assoconnectJs     = $script;
        } else {
            /* $execute a échoué : on affiche le message renvoyé par PDO */
            $msg  = $upd->errorInfo()[2] ?? 'Erreur inconnue';
            $alertAsso = makeAlert('danger', 'Erreur SQL&nbsp;: ' . htmlspecialchars($msg) , 0); // pas d'auto-close
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
$accueil_active = !empty($_POST['accueil_active']) ? 1 : 0;
$date_course = $_POST['date_course'] ?? null;
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
            $extAccueil      = strtolower(pathinfo($_FILES['picture_accueil']['name'], PATHINFO_EXTENSION));
            $finfoA          = new finfo(FILEINFO_MIME_TYPE);
            $mimeAccueil     = $finfoA->file($_FILES['picture_accueil']['tmp_name']);
            $allowedMimeImg  = ['image/jpeg','image/png','image/gif','image/webp'];

            if (!in_array($extAccueil, $allowed, true) || !in_array($mimeAccueil, $allowedMimeImg, true)) {
                $alertAccueil = makeAlert('danger', 'Format d\'image non autorisé.');
            } elseif ($_FILES['picture_accueil']['size'] > 5 * 1024 * 1024) {
                $alertAccueil = makeAlert('danger', 'Image trop volumineuse (max 5 Mo).');
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
            $extPartner      = strtolower(pathinfo($_FILES['picture_partner']['name'], PATHINFO_EXTENSION));
            $finfoP          = new finfo(FILEINFO_MIME_TYPE);
            $mimePartner     = $finfoP->file($_FILES['picture_partner']['tmp_name']);
            $allowedMimeImg2 = ['image/jpeg','image/png','image/gif','image/webp'];

            if (!in_array($extPartner, $allowed, true) || !in_array($mimePartner, $allowedMimeImg2, true)) {
                $alertAccueil = makeAlert('danger', 'Format d\'image non autorisé.');
            } elseif ($_FILES['picture_partner']['size'] > 5 * 1024 * 1024) {
                $alertAccueil = makeAlert('danger', 'Image trop volumineuse (max 5 Mo).');
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

        /* 3) Si pas d'erreur, mise à jour BD */
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
                'link_cancer'    => $link_cancer,
                'id'        => 1
            ]);

            $alertAccueil = makeAlert('success', 'Configuration enregistrée !');

            /* 4) Mettre à jour les variables locales
                  (sinon le formulaire afficherait l'ancien titre) */
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
            $extGradient     = strtolower(pathinfo($_FILES['picture_gradient']['name'], PATHINFO_EXTENSION));
            $finfoG          = new finfo(FILEINFO_MIME_TYPE);
            $mimeGradient    = $finfoG->file($_FILES['picture_gradient']['tmp_name']);
            $allowedMimeImg3 = ['image/jpeg','image/png','image/gif','image/webp'];

            if (!in_array($extGradient, $allowed, true) || !in_array($mimeGradient, $allowedMimeImg3, true)) {
                $alertParcours = makeAlert('danger', 'Format d\'image non autorisé.');
            } elseif ($_FILES['picture_gradient']['size'] > 5 * 1024 * 1024) {
                $alertParcours = makeAlert('danger', 'Image trop volumineuse (max 5 Mo).');
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
            $extParcours     = strtolower(pathinfo($_FILES['picture_parcours']['name'], PATHINFO_EXTENSION));
            $finfoParc       = new finfo(FILEINFO_MIME_TYPE);
            $mimeParcours    = $finfoParc->file($_FILES['picture_parcours']['tmp_name']);
            $allowedMimeImg4 = ['image/jpeg','image/png','image/gif','image/webp'];

            if (!in_array($extParcours, $allowed, true) || !in_array($mimeParcours, $allowedMimeImg4, true)) {
                $alertParcours = makeAlert('danger', 'Format d\'image non autorisé.');
            } elseif ($_FILES['picture_parcours']['size'] > 5 * 1024 * 1024) {
                $alertParcours = makeAlert('danger', 'Image trop volumineuse (max 5 Mo).');
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

        /* 3) Si pas d'erreur, mise à jour BD */
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
                  (sinon le formulaire afficherait l'ancien titre) */
            $titleParcours  = $newTitleParcours;
            $picture_gradient = $newPictureGradient; 
            $picture_parcours = $newPictureParcours; 
        }
    }
}

// Reorder gallery (AJAX)
if (isset($_POST['reorder_gallery'])) {
    $filenames = json_decode($_POST['filenames'], true);
    if (is_array($filenames)) {
        try {
            $stmt = $pdo->prepare("UPDATE parcours_images SET sort_order = ? WHERE filename = ?");
            foreach ($filenames as $i => $fn) {
                $stmt->execute([$i + 1, $fn]);
            }
        } catch (PDOException $e) {} // Table may not exist yet
    }
    echo 'OK';
    exit;
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
        $allowedGalMime = ['image/jpeg','image/png','image/gif','image/webp'];
    for ($i = 0; $i < count($files['name']); $i++) {
            $ext      = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            $finfoGal = new finfo(FILEINFO_MIME_TYPE);
            $mimeGal  = $finfoGal->file($files['tmp_name'][$i]);
            if (in_array($ext, $allowed) && in_array($mimeGal, $allowedGalMime)
                && $files['size'][$i] <= 5 * 1024 * 1024) {
                $safeName = uniqid('img_', true) . '.' . $ext;
                if (move_uploaded_file($files['tmp_name'][$i], $uploadDir . $safeName)) {
                    try {
                        $maxStmt = $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM parcours_images");
                        $nextOrder = $maxStmt->fetch(PDO::FETCH_ASSOC)['next_order'];
                        $insStmt = $pdo->prepare("INSERT INTO parcours_images (filename, sort_order) VALUES (?, ?)");
                        $insStmt->execute([$safeName, $nextOrder]);
                    } catch (PDOException $e) {} // Table may not exist yet
                }
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
                $alertReglementation = makeAlert('warning', 'Aucun changement détecté.', 0); // pas d'auto-close
        }
    } else {
        /* $execute a échoué : on affiche le message renvoyé par PDO */
        $msg  = $upd->errorInfo()[2] ?? 'Erreur inconnue';
        $alertReglementation = makeAlert('danger', 'Erreur SQL&nbsp;: ' . htmlspecialchars($msg) , 0); // pas d'auto-close
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
   Carte : Google
-------------------------------------------------------------------------- */
$alertGoogle = '';
if (isset($_POST['google'])) {

    /* a) Lecture & validation */
    $client_id = encrypt($_POST['client_id'] ?? '');
    $client_secret = encrypt($_POST['client_secret'] ?? '');

    /* b) Sauvegarder mail_email et mail_phone si colonnes existent */
    $newMailEmail = trim($_POST['mail_email'] ?? '');
    $newMailPhone = trim($_POST['mail_phone'] ?? '');
    if ($hasMailFields) {
        $upd = $pdo->prepare(
            'UPDATE setting
                SET client_id = :client_id,
                    client_secret = :client_secret,
                    mail_email = :mail_email,
                    mail_phone = :mail_phone
                WHERE id = :id'
        );
        $ok = $upd->execute([
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'mail_email' => $newMailEmail ?: null,
            'mail_phone' => $newMailPhone ?: null,
            'id' => 1
        ]);
        $mail_email = $newMailEmail;
        $mail_phone = $newMailPhone;
    } else {
        $upd = $pdo->prepare(
            'UPDATE setting
                SET client_id = :client_id,
                    client_secret = :client_secret
                WHERE id = :id'
        );
        $ok = $upd->execute([
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'id' => 1
        ]);
    }

    /* c) Gestion du résultat */
    if ($ok) {
        if ($upd->rowCount() > 0) {
            $alertGoogle = makeAlert('success', 'Clés google enregistrées !');
        } else {
                $alertGoogle = makeAlert('warning', 'Aucun changement détecté.', 0); // pas d'auto-close
        }
    } else {
        /* $execute a échoué : on affiche le message renvoyé par PDO */
        $msg  = $upd->errorInfo()[2] ?? 'Erreur inconnue';
        $alertGoogle = makeAlert('danger', 'Erreur SQL&nbsp;: ' . htmlspecialchars($msg) , 0); // pas d'auto-close
    }

    $client_id = decrypt($client_id);
    $client_secret = decrypt($client_secret);
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
            try {
                $delStmt = $pdo->prepare("DELETE FROM parcours_images WHERE filename = ?");
                $delStmt->execute([$fileToDelete]);
            } catch (PDOException $e) {}
            echo 'OK';
            exit;
        } else {
            http_response_code(500);
            echo 'Erreur lors de la suppression du fichier.';
            exit;
        }
    } else {
        // File gone from disk, clean DB too
        try {
            $delStmt = $pdo->prepare("DELETE FROM parcours_images WHERE filename = ?");
            $delStmt->execute([$fileToDelete]);
        } catch (PDOException $e) {}
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
<title>Réglages</title>

<!-- ─── CSS ─── -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
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
  .sortable-ghost{opacity:.4;background:#ffe5ff;border-radius:8px}
  .card-dashboard{margin-top:1rem;border-radius:1.25rem;box-shadow:0 0 25px rgba(0,0,0,.1)}
</style>
</head>

<body>

<?php include '../inc/navbar-admin.php'; ?>

<style>
  .settings-tabs { border-bottom: 2px solid #f0e8eb; margin-bottom: 24px; gap: 0; }
  .settings-tabs .nav-link {
    color: #1e293b; font-weight: 500; font-size: 14px;
    padding: 10px 18px; border: none; border-bottom: 2px solid transparent;
    margin-bottom: -2px; border-radius: 0; background: transparent;
  }
  .settings-tabs .nav-link:hover { color: #1e293b; border-bottom-color: #d4c4cb; }
  .settings-tabs .nav-link.active {
    color: #1e293b; font-weight: 600;
    border-bottom-color: #ec4899; background: transparent;
  }
  .settings-section { display: none; }
  .settings-section.active { display: block; }
  .setting-card {
    background: #fff; border: 1px solid #f0e8eb; border-radius: 12px;
    padding: 24px; margin-bottom: 20px;
  }
  .setting-card h2 {
    font-size: 18px; font-weight: 700; color: #1e293b; margin-bottom: 16px;
    padding-bottom: 12px; border-bottom: 1px solid #f0e8eb;
  }
</style>

<?php
// Determine active tab based on which form was submitted
$activeTab = 'general';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['accueil'])) $activeTab = 'accueil';
    elseif (isset($_POST['parcours']) || isset($_POST['uploadGalerie'])) $activeTab = 'parcours';
    elseif (isset($_POST['reglementation'])) $activeTab = 'reglementation';
    elseif (isset($_POST['required']) || isset($_POST['importExcel'])) $activeTab = 'formulaire';
    elseif (isset($_POST['google']) || isset($_POST['action'])) $activeTab = 'google';
}
// Also check URL hash
if (isset($_GET['tab']) && in_array($_GET['tab'], ['general','accueil','parcours','reglementation','formulaire','google'])) {
    $activeTab = $_GET['tab'];
}
?>

<!-- Settings Navigation Tabs -->
<ul class="nav settings-tabs" id="settingsTabs">
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'general' ? 'active' : '' ?>" href="#" data-tab="general">General</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'accueil' ? 'active' : '' ?>" href="#" data-tab="accueil">Accueil</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'parcours' ? 'active' : '' ?>" href="#" data-tab="parcours">Parcours</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'reglementation' ? 'active' : '' ?>" href="#" data-tab="reglementation">Reglementation</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'formulaire' ? 'active' : '' ?>" href="#" data-tab="formulaire">Formulaire</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'google' ? 'active' : '' ?>" href="#" data-tab="google">Google / Email</a></li>
</ul>

<!-- ═══ TAB: General ═══ -->
<div class="settings-section <?= $activeTab === 'general' ? 'active' : '' ?>" id="tab-general">
  <div class="row g-4">
    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2>Configuration generale</h2>
        <?php if ($alert) echo $alert; ?>
                    <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
                        <?= csrf_field() ?>
                        <div class="col-md-6"><label class="form-label">Titre</label>
                            <input type="text"
                                class="form-control"
                                name="title"
                                placeholder="Titre"
                                value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>"
                                required>
                        </div>
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
                            <label for="picture" class="form-label">Image d'entete</label>
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
                        <div class="col-md-6"><label class="form-label">Montant de l'inscription</label>
                            <select id="registration_fee" name="registration_fee" class="form-select">
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
      </div><!-- /setting-card config -->
    </div><!-- /col-lg-6 -->

    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2>Liaison AssoConnect</h2>
        <?php if ($alertAsso) echo $alertAsso; ?>
                    <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
                        <?= csrf_field() ?>
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
      </div><!-- /setting-card asso -->
    </div><!-- /col-lg-6 -->
  </div><!-- /row -->
</div><!-- /tab-general -->

<!-- ═══ TAB: Accueil ═══ -->
<div class="settings-section <?= $activeTab === 'accueil' ? 'active' : '' ?>" id="tab-accueil">
  <div class="row g-4">
    <div class="col-12">
      <div class="setting-card">
        <h2>Reglage page accueil</h2>
                <?php if ($alertAccueil) echo $alertAccueil; ?>
                <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
                    <?= csrf_field() ?>

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
                    <div class="col-md-6"><label class="form-label">Lien de la ligne contre le cancer</label>
                        <input type="text" class="form-control" name="link_cancer" placeholder="Lien de la ligne contre le cancer" value="<?= htmlspecialchars($link_cancer, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" name="accueil" class="btn btn-primary w-auto">Sauvegarder</button>
                    </div>
                </form>
      </div><!-- /setting-card accueil -->
    </div><!-- /col-12 -->
  </div><!-- /row -->
</div><!-- /tab-accueil -->

<!-- ═══ TAB: Parcours ═══ -->
<div class="settings-section <?= $activeTab === 'parcours' ? 'active' : '' ?>" id="tab-parcours">
  <div class="row g-4">
    <div class="col-12">
      <div class="setting-card">
        <h2>Parcours</h2>
                <?php if ($alertParcours) echo $alertParcours; ?>
                <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
                    <?= csrf_field() ?>
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
                    <div class="col-md-6"><label class="form-label">Image du denivele</label>
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
                            Gerer la galerie d'images
                        </button>
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" name="parcours" class="btn btn-primary w-auto">Sauvegarder</button>
                    </div>
                </form>
      </div><!-- /setting-card parcours -->
    </div><!-- /col-12 -->
  </div><!-- /row -->

  <!-- Modal Galerie -->
  <?php
      $galerieDir = '../files/_parcours/';
      $diskFiles = is_dir($galerieDir) ? array_diff(scandir($galerieDir), ['.', '..']) : [];

      // Check if parcours_images table exists
      $tableExists = false;
      try {
          $pdo->query("SELECT 1 FROM parcours_images LIMIT 1");
          $tableExists = true;
      } catch (PDOException $e) {}

      $images = [];
      if ($tableExists) {
          // Sync filesystem with DB
          $dbFiles = [];
          $dbStmt = $pdo->query("SELECT filename FROM parcours_images");
          while ($r = $dbStmt->fetch(PDO::FETCH_ASSOC)) {
              $dbFiles[] = $r['filename'];
          }

          // Add files on disk but not in DB
          foreach ($diskFiles as $df) {
              if (!in_array($df, $dbFiles)) {
                  $maxStmt = $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM parcours_images");
                  $nextOrder = $maxStmt->fetch(PDO::FETCH_ASSOC)['next_order'];
                  $insStmt = $pdo->prepare("INSERT INTO parcours_images (filename, sort_order) VALUES (?, ?)");
                  $insStmt->execute([$df, $nextOrder]);
              }
          }

          // Remove DB records whose file no longer exists
          foreach ($dbFiles as $dbf) {
              if (!in_array($dbf, $diskFiles)) {
                  $delStmt = $pdo->prepare("DELETE FROM parcours_images WHERE filename = ?");
                  $delStmt->execute([$dbf]);
              }
          }

          // Load images ordered by sort_order
          $orderedStmt = $pdo->query("SELECT filename FROM parcours_images ORDER BY sort_order ASC");
          while ($r = $orderedStmt->fetch(PDO::FETCH_ASSOC)) {
              $images[] = $r['filename'];
          }
      } else {
          // Fallback: just use filesystem order
          $images = array_values($diskFiles);
      }

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
            <?= csrf_field() ?>
            <label for="galerieImages" class="form-label">
              Importer jusqu'a <span id="remainingCount"><?= $remaining ?></span> image(s) :
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
              <div class="col-md-3 text-center mb-4 sortable-image-item" data-img="<?= htmlspecialchars($img) ?>" data-filename="<?= htmlspecialchars($img) ?>" style="position:relative;cursor:grab">
                <img src="<?= $galerieDir . rawurlencode($img) ?>" class="img-thumbnail" style="max-height: 150px;">
                <form class="deleteForm">
                  <?= csrf_field() ?>
                  <input type="hidden" name="deleteImage" value="<?= htmlspecialchars($img) ?>">
                  <button type="button" class="delete-btn" style="position:absolute;top:4px;right:4px;background:rgba(0,0,0,.6);color:#fff;border:none;border-radius:50%;width:24px;height:24px;font-size:14px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center" title="Supprimer">&times;</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div><!-- /tab-parcours -->

<!-- ═══ TAB: Reglementation ═══ -->
<div class="settings-section <?= $activeTab === 'reglementation' ? 'active' : '' ?>" id="tab-reglementation">
  <style>
    .tox-tinymce { border-radius: 0.375rem !important; }
  </style>
  <div class="row g-4">
    <div class="col-12">
      <div class="setting-card">
        <h2>Reglement de la course</h2>
                 <?php if ($alertReglementation) echo $alertReglementation; ?>
                <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
                    <?= csrf_field() ?>
                    <div class="form-group mb-3">
                        <label for="divReglementation" class="form-label">Reglement de la course</label>
                        <textarea class="form-control" id="divReglementation" name="div_reglementation" rows="10" required>
                            <?= htmlspecialchars($div_reglementation) ?>
                        </textarea>
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" name="reglementation" class="btn btn-primary w-auto">Sauvegarder</button>
                    </div>
                </form>
      </div><!-- /setting-card reglementation -->
    </div><!-- /col-12 -->
  </div><!-- /row -->

  <script src="../js/tinymce/tinymce.min.js"></script>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
    tinymce.init({
        selector: '#divReglementation',
        license_key: 'gpl',
        language: 'fr_FR',
        plugins: 'anchor autolink charmap codesample emoticons image link lists media searchreplace table visualblocks wordcount code',
        toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat | code',
        height: 430,
        menubar: false,
        branding: false,
        content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; }',
        color_map: [
            "000000", "Noir",
            "993300", "Marron fonce",
            "333300", "Vert fonce",
            "003300", "Vert sombre",
            "003366", "Bleu marine",
            "000080", "Bleu",
            "333399", "Indigo",
            "333333", "Gris tres fonce",
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
            "FFCC99", "Peche",
            "FFFF99", "Jaune clair",
            "CCFFCC", "Vert clair",
            "CCFFFF", "Cyan clair",
            "99CCFF", "Bleu clair",
            "CC99FF", "Prune"
        ],
        // 🔒 [SEC-06] Whitelist HTML sécurisée (CWE-79)
        extended_valid_elements: 'a[href|target|title|class|rel],'
          + 'img[src|alt|title|width|height|class|loading],'
          + 'p[class|style],span[class|style],div[class|style],'
          + 'table[class|border|cellpadding|cellspacing|style],thead,tbody,tfoot,'
          + 'tr,td[class|style|colspan|rowspan],th[class|style|colspan|rowspan],'
          + 'ul[class],ol[class|type|start],li[class],'
          + 'blockquote[class|cite],pre[class],code,strong/b,em/i,u,s,sub,sup,br,'
          + 'hr[class],h1[class|style],h2[class|style],h3[class|style],'
          + 'h4[class|style],h5[class|style],h6[class|style],'
          + 'figure[class],figcaption,video[src|controls|width|height|class],'
          + 'audio[src|controls|class],source[src|type]',
        invalid_elements: 'script,iframe,object,embed,form,input,textarea,select,button,applet,meta,link,base',
        toolbar_mode: 'sliding'
    });
  </script>
</div><!-- /tab-reglementation -->

<!-- ═══ TAB: Formulaire ═══ -->
<div class="settings-section <?= $activeTab === 'formulaire' ? 'active' : '' ?>" id="tab-formulaire">
  <div class="row g-4">
    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2>Formulaire : Champs requis</h2>
                 <?php if ($alertRequired) echo $alertRequired; ?>
                <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
                    <?= csrf_field() ?>
                    <div class="col-md-6">
                        <label class="form-label">Nom</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="required_name" id="required_name" <?= isset($required_name) && $required_name ? 'checked' : '' ?>>
                            <label class="form-check-label" for="required_name">Oui / Non</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Prenom</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="required_firstname" id="required_firstname" <?= isset($required_firstname) && $required_firstname ? 'checked' : '' ?>>
                            <label class="form-check-label" for="required_firstname">Oui / Non</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Telephone</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="required_phone" id="required_phone" <?= isset($required_phone) && $required_phone ? 'checked' : '' ?>>
                            <label class="form-check-label" for="required_phone">Oui / Non</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
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
      </div><!-- /setting-card required -->
    </div><!-- /col-lg-6 -->

    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2>Informations d'import excel</h2>
                 <?php if ($alertImport) echo $alertImport; ?>
                <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
                    <?= csrf_field() ?>
                    <div class="col-md-4"><label class="form-label">N d'inscription =</label>
                        <input type="text" class="form-control" name="inscription_no" value="<?= htmlspecialchars($inscription_no, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label">Nom = </label>
                        <input type="text" class="form-control" name="nom" value="<?= htmlspecialchars($nom, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label">Prenom =</label>
                        <input type="text" class="form-control" name="prenom" value="<?= htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label">Telephone =</label>
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
      </div><!-- /setting-card import -->
    </div><!-- /col-lg-6 -->
  </div><!-- /row -->
</div><!-- /tab-formulaire -->

<!-- ═══ TAB: Google ═══ -->
<link href="../css/gmail-settings.css" rel="stylesheet">
<div class="settings-section <?= $activeTab === 'google' ? 'active' : '' ?>" id="tab-google">
  <div class="row g-4">
    <div class="col-12">
      <div class="setting-card">
        <h2>Parametres Gmail</h2>
                <div class="header">
                    <p>Gestion de la connexion avec l'API Gmail de Google</p>
                </div>

                <?php if ($alertGoogle) echo $alertGoogle; ?>
                <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
                    <?= csrf_field() ?>
                    <div class="col-md-6"><label class="form-label">Client ID</label>
                        <input type="text" class="form-control" name="client_id" value="<?= htmlspecialchars($client_id, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-6"><label class="form-label">Client secret</label>
                        <input type="text" class="form-control" name="client_secret" value="<?= htmlspecialchars($client_secret, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <?php if ($hasMailFields): ?>
                    <div class="col-12" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--oc-border,#e2e8f0)">
                        <h3 style="margin-bottom:12px">Contact dans les emails</h3>
                        <p style="font-size:13px;color:#64748b;margin-bottom:12px">Ces informations apparaissent dans le pied de page des emails envoyés. Laissez vide pour ne pas les afficher.</p>
                    </div>
                    <div class="col-md-6"><label class="form-label">Email de contact</label>
                        <input type="email" class="form-control" name="mail_email" value="<?= htmlspecialchars($mail_email) ?>" placeholder="contact@forbachenrose.fr">
                    </div>
                    <div class="col-md-6"><label class="form-label">Téléphone</label>
                        <input type="text" class="form-control" name="mail_phone" value="<?= htmlspecialchars($mail_phone) ?>" placeholder="03 XX XX XX XX">
                    </div>
                    <?php endif; ?>
                    <div class="col-12 text-end">
                        <button type="submit" name="google" class="btn btn-primary w-auto">Sauvegarder</button>
                    </div>
                </form>

                <?php if (isset($message)): ?>
                    <div class="message <?php echo $messageClass; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="status <?php echo $isConnected ? 'connected' : 'disconnected'; ?>">
                    <div>
                        <strong>Statut de la connexion :</strong>
                        <?php if ($isConnected): ?>
                            Connecte a Gmail - Pret a envoyer des emails
                        <?php else: ?>
                            Non connecte - Configuration requise
                        <?php endif; ?>
                    </div>
                </div>

                <div class="actions">
                    <?php if ($isConnected): ?>
                        <form method="post" style="display: inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="test_connection">
                            <button type="submit" class="btn btn-success">
                                Tester la connexion
                            </button>
                        </form>

                        <form method="post" style="display: inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="send_test_mail">
                            <button type="submit" class="btn btn-primary">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                Envoyer un mail test
                            </button>
                        </form>

                        <form method="post" style="display: inline;" data-confirm="Etes-vous sur de vouloir vous deconnecter de Gmail ?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="disconnect">
                            <button type="submit" class="btn btn-danger">
                                Se deconnecter
                            </button>
                        </form>

                    <?php else: ?>
                        <form method="post" style="display: inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="test_connection">
                            <button type="submit" class="btn btn-warning">
                                Verifier la connexion
                            </button>
                        </form>

                        <a href="<?php echo htmlspecialchars($authUrl); ?>" class="btn btn-primary">
                            <svg class="google-icon" viewBox="0 0 24 24">
                                <path fill="currentColor" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                <path fill="currentColor" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                <path fill="currentColor" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                                <path fill="currentColor" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                            </svg>
                            Se connecter avec Google
                        </a>
                    <?php endif; ?>
                </div>

                <div class="info">
                    <h3>Informations</h3>
                    <ul>
                        <li><strong>Fichier token :</strong> <?php echo file_exists(__DIR__ . '/../token.json') ? 'Present' : 'Absent'; ?></li>
                        <li><strong>Derniere verification :</strong> <?php echo date('d/m/Y H:i:s'); ?></li>
                        <li><strong>Scopes requis :</strong> Gmail Send (envoi d'emails)</li>
                    </ul>

                    <?php if (!$isConnected): ?>
                        <hr>
                        <p><strong>Actions requises :</strong></p>
                        <ol>
                            <li>API Google : ajoute dans "URI de redirection autorises" -> <?= oauth2_callback_url() ?></li>
                            <li>Cliquez sur "Se connecter avec Google"</li>
                            <li>Autorisez l'acces a votre compte Gmail</li>
                            <li>Vous serez redirige automatiquement</li>
                        </ol>
                    <?php endif; ?>
                </div>
      </div><!-- /setting-card google -->
    </div><!-- /col-12 -->
  </div><!-- /row -->
</div><!-- /tab-google -->

<?php include '../inc/admin-footer.php'; ?>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.js" integrity="sha384-3wB6mhez87GBdPpEqKMU2wAH2Cjcvj8ynU/n7blM/JW4BLpVD0aTrx4ZE7IwFLSH" crossorigin="anonymous"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
// Settings tabs switching
document.querySelectorAll('#settingsTabs .nav-link').forEach(function(tab) {
  tab.addEventListener('click', function(e) {
    e.preventDefault();
    document.querySelectorAll('#settingsTabs .nav-link').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.settings-section').forEach(function(s) { s.classList.remove('active'); });
    this.classList.add('active');
    document.getElementById('tab-' + this.dataset.tab).classList.add('active');
  });
});

// Galerie images - validation
document.getElementById('galerieImages')?.addEventListener('change', function () {
  const max = <?= $remaining ?>;
  if (this.files.length > max) {
    alert('Vous ne pouvez selectionner que ' + max + ' image(s) maximum.');
    this.value = '';
  }
});

document.addEventListener('DOMContentLoaded', function() {
  var maxImages = 30;
  var input = document.getElementById('galerieImages');
  var countSpan = document.getElementById('remainingCount');
  var uploadBtn = document.querySelector('button[name="uploadGalerie"]');

  // Validation dynamique
  if (input) {
    input.addEventListener('change', function () {
      var remaining = parseInt(countSpan ? countSpan.textContent : '0');
      if (this.files.length > remaining) {
        alert('Vous ne pouvez selectionner que ' + remaining + ' image(s) maximum.');
        this.value = '';
      }
    });
  }

  // Drag & drop reordering
  var galerieEl = document.getElementById('galerieContainer');
  if (galerieEl) {
    Sortable.create(galerieEl, {
      animation: 150,
      ghostClass: 'sortable-ghost',
      onEnd: function() {
        var filenames = [];
        galerieEl.querySelectorAll('.sortable-image-item').forEach(function(item) {
          filenames.push(item.dataset.filename);
        });
        fetch('', {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: 'reorder_gallery=1&filenames=' + JSON.stringify(filenames) + '&csrf_token=' + encodeURIComponent(document.querySelector('input[name="csrf_token"]').value)
        });
      }
    });
  }

  // Suppression dynamique
  document.querySelectorAll('.delete-btn').forEach(function(btn) {
    btn.addEventListener('click', function () {
      var form = this.closest('.deleteForm');
      var imageName = form.querySelector('input[name="deleteImage"]').value;

      fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ deleteImage: imageName, csrf_token: document.querySelector('input[name="csrf_token"]').value })
      })
      .then(function(response) { return response.text(); })
      .then(function(result) {
        if (result.trim() === 'OK') {
          var container = form.closest('[data-img]');
          container.remove();

          // Met a jour le compteur
          var current = parseInt(countSpan.textContent);
          if (!isNaN(current) && current < maxImages) {
            current += 1;
            countSpan.textContent = current;
          }

          // Reactive le champ d'import si desactive
          if (input && input.disabled) input.disabled = false;
          if (uploadBtn && uploadBtn.disabled) uploadBtn.disabled = false;
        } else {
          alert("Erreur lors de la suppression : " + result);
        }
      })
      .catch(function(error) {
        alert("Erreur reseau : " + error);
      });
    });
  });
});
</script>

