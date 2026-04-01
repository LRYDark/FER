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
$navbar_logo = $data['navbar_logo'] ?? 'logo_fer_rose.png';
$title_mobile = $data['title_mobile'] ?? '';
$registration_fee = $data['registration_fee'] ?? 0;

// theme
$theme_primary        = $data['theme_primary_color']        ?? '#db2777';
$theme_secondary      = $data['theme_secondary_color']      ?? '#0f172a';
$theme_dark_primary   = $data['theme_dark_primary_color']   ?? '#f472b6';
$theme_dark_secondary = $data['theme_dark_secondary_color'] ?? '#e2e8f0';
$theme_radius         = (int)($data['theme_border_radius']  ?? 12);
$theme_font           = $data['theme_font_family']          ?? 'Inter';
$flash_bg_color       = $data['flash_bg_color']             ?? '#db2777';
$flash_text_color     = $data['flash_text_color']           ?? '#ffffff';

// accueil
$titleAccueil  = $data['titleAccueil']   ?? '';
$link_instagram  = $data['link_instagram']   ?? '';
$link_facebook = $data['link_facebook'] ?? ''; 
$accueil_active = !empty($data['accueil_active']) ? 1 : 0;
$date_course = $data['date_course'] ?? null;
$date_formatted = $date_course ? date('Y-m-d', strtotime($date_course)) : '';
$picture_partner= $data['picture_partner'] ?? ''; 
$link_cancer = $data['link_cancer'] ?? null;
$titleAccueil_mobile = $data['titleAccueil_mobile'] ?? '';
$subtitle_accueil = $data['subtitle_accueil'] ?? '';
$subtitle_accueil_mobile = $data['subtitle_accueil_mobile'] ?? '';
$flash_info_text = $data['flash_info_text'] ?? '';
$flash_info_active = !empty($data['flash_info_active']) ? 1 : 0;
$qrcode_mail_mode = $data['qrcode_mail_mode'] ?? 'none';
$qrcode_mail_limit = (int) ($data['qrcode_mail_limit'] ?? 0);
$debogage = !empty($data['debogage']) ? 1 : 0;
$video_accueil = $data['video_accueil'] ?? 'FER.mp4';
$maintenance_mode = !empty($data['maintenance_mode']) ? 1 : 0;
$maintenance_message = $data['maintenance_message'] ?? '';

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
                try {
                    $connectionStatus = isGoogleConnectionValid();
                    $message = $connectionStatus ?
                        "✅ Connexion Google OK - Prêt à envoyer des emails" :
                        "❌ Connexion Google non valide";
                    $messageClass = $connectionStatus ? 'success' : 'error';
                } catch (\Throwable $e) {
                    $message = "❌ Connexion Google non valide";
                    $messageClass = 'error';
                    writeLog("❌ Exception test connexion : " . $e->getMessage());
                }
                break;

            case 'send_test_mail':
                try {
                    $adminEmail = $_SESSION['email'] ?? '';
                    if (!$adminEmail) {
                        $message = "❌ Email admin introuvable dans la session.";
                        $messageClass = 'error';
                    } elseif (!isGoogleConnectionValid()) {
                        $message = "❌ Connexion Google non valide. Reconnectez-vous à Gmail.";
                        $messageClass = 'error';
                    } else {
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
                    }
                } catch (\Throwable $e) {
                    $message = "❌ Échec de l'envoi du mail de test";
                    $messageClass = 'error';
                    writeLog("❌ Exception envoi test : " . $e->getMessage());
                }
                break;

            case 'disconnect':
                try {
                    if (revokeGoogleConnection()) {
                        $message = "✅ Déconnexion Google effectuée";
                        $messageClass = 'success';
                    } else {
                        $message = "❌ Erreur lors de la déconnexion";
                        $messageClass = 'error';
                    }
                } catch (\Throwable $e) {
                    $message = "❌ Erreur lors de la déconnexion";
                    $messageClass = 'error';
                    writeLog("❌ Exception déconnexion : " . $e->getMessage());
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
$stmtForms = $pdo->prepare('SELECT * FROM forms ORDER BY sort_order ASC');
$stmtForms->execute();
$allFields = $stmtForms->fetchAll(PDO::FETCH_ASSOC);


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
/* --------------------------------------------------------------------------
   En-tête du site
-------------------------------------------------------------------------- */
if (isset($_POST['save_header'])) {
    $newTitle = $_POST['title'] ?? '';
    $newTitleMobile = $_POST['title_mobile'] ?? '';

    $pdo->prepare('UPDATE setting SET title = :title, title_mobile = :title_mobile WHERE id = 1')
        ->execute(['title' => $newTitle, 'title_mobile' => $newTitleMobile]);

    addToast('success', 'En-tête enregistré !');
    $title = $newTitle;
    $title_mobile = $newTitleMobile;
}

/* --------------------------------------------------------------------------
   Logo de la navbar
-------------------------------------------------------------------------- */
if (isset($_POST['save_navbar_logo'])) {
    $uploadDir = '../files/_logos/';
    $allowed = ['jpg','jpeg','png','gif','webp','svg'];
    $allowedMime = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];

    if (!empty($_FILES['navbar_logo']['name'])) {
        $ext = strtolower(pathinfo($_FILES['navbar_logo']['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['navbar_logo']['tmp_name']);
        if (!in_array($ext, $allowed, true) || !in_array($mime, $allowedMime, true)) {
            addToast('danger', 'Format d\'image non autorisé.');
        } elseif ($_FILES['navbar_logo']['size'] > 5 * 1024 * 1024) {
            addToast('danger', 'Image trop volumineuse (max 5 Mo).');
        } else {
            $safeName = uniqid('logo_', true) . '.' . $ext;
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            if (move_uploaded_file($_FILES['navbar_logo']['tmp_name'], $uploadDir . $safeName)) {
                // Supprimer l'ancien logo si ce n'est pas celui par défaut
                if ($navbar_logo && $navbar_logo !== 'logo_fer_rose.png' && file_exists($uploadDir . $navbar_logo)) {
                    @unlink($uploadDir . $navbar_logo);
                }
                $pdo->prepare('UPDATE setting SET navbar_logo = :l WHERE id = 1')
                    ->execute(['l' => $safeName]);
                $navbar_logo = $safeName;
                addToast('success', 'Logo de la navbar mis à jour !');
            } else {
                addToast('danger', 'Erreur lors de l\'upload.');
            }
        }
    } else {
        addToast('warning', 'Aucune image sélectionnée.');
    }
}

/* --------------------------------------------------------------------------
   Personnalisation — Thème (couleurs, radius, police)
-------------------------------------------------------------------------- */
// Liste des polices autorisées
$allowedFonts = ['system-ui','Inter','Poppins','Roboto','Open Sans','Montserrat','Lato','Nunito',
    'Raleway','Source Sans 3','Work Sans','DM Sans','Outfit','Plus Jakarta Sans','Manrope','Figtree','Quicksand','Cabin','Rubik','Karla'];

if (isset($_POST['save_theme'])) {
    $theme_primary        = $_POST['theme_primary_color']        ?? '#db2777';
    $theme_secondary      = $_POST['theme_secondary_color']      ?? '#0f172a';
    $theme_dark_primary   = $_POST['theme_dark_primary_color']   ?? '#f472b6';
    $theme_dark_secondary = $_POST['theme_dark_secondary_color'] ?? '#e2e8f0';
    $theme_radius         = max(0, min(32, (int)($_POST['theme_border_radius'] ?? 12)));
    $theme_font           = $_POST['theme_font_family']          ?? 'Inter';

    // Valider les couleurs hex
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $theme_primary))        $theme_primary = '#db2777';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $theme_secondary))      $theme_secondary = '#0f172a';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $theme_dark_primary))   $theme_dark_primary = '#f472b6';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $theme_dark_secondary)) $theme_dark_secondary = '#e2e8f0';
    if (!in_array($theme_font, $allowedFonts)) $theme_font = 'Inter';

    $pdo->prepare(
        'UPDATE setting SET theme_primary_color = :p, theme_secondary_color = :s,
         theme_dark_primary_color = :dp, theme_dark_secondary_color = :ds,
         theme_border_radius = :r, theme_font_family = :f WHERE id = 1'
    )->execute([
        'p' => $theme_primary, 's' => $theme_secondary,
        'dp' => $theme_dark_primary, 'ds' => $theme_dark_secondary,
        'r' => $theme_radius, 'f' => $theme_font,
    ]);

    addToast('success', 'Thème mis à jour !');
}

if (isset($_POST['save_flash_colors'])) {
    $flash_bg_color   = $_POST['flash_bg_color']   ?? '#db2777';
    $flash_text_color = $_POST['flash_text_color'] ?? '#ffffff';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $flash_bg_color)) $flash_bg_color = '#db2777';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $flash_text_color)) $flash_text_color = '#ffffff';

    $pdo->prepare('UPDATE setting SET flash_bg_color = :bg, flash_text_color = :txt WHERE id = 1')
        ->execute(['bg' => $flash_bg_color, 'txt' => $flash_text_color]);

    addToast('success', 'Couleurs du bandeau mises à jour !');
}

if (isset($_POST['reset_flash_colors'])) {
    $flash_bg_color = '#db2777'; $flash_text_color = '#ffffff';
    $pdo->prepare('UPDATE setting SET flash_bg_color = :bg, flash_text_color = :txt WHERE id = 1')
        ->execute(['bg' => $flash_bg_color, 'txt' => $flash_text_color]);

    addToast('success', 'Couleurs du bandeau réinitialisées !');
}

if (isset($_POST['reset_theme'])) {
    $theme_primary = '#db2777'; $theme_secondary = '#0f172a';
    $theme_dark_primary = '#f472b6'; $theme_dark_secondary = '#e2e8f0';
    $theme_radius = 12; $theme_font = 'Inter';
    $flash_bg_color = '#db2777'; $flash_text_color = '#ffffff';

    $pdo->prepare(
        'UPDATE setting SET theme_primary_color = :p, theme_secondary_color = :s,
         theme_dark_primary_color = :dp, theme_dark_secondary_color = :ds,
         theme_border_radius = :r, theme_font_family = :f,
         flash_bg_color = :fbg, flash_text_color = :ftxt WHERE id = 1'
    )->execute([
        'p' => $theme_primary, 's' => $theme_secondary,
        'dp' => $theme_dark_primary, 'ds' => $theme_dark_secondary,
        'r' => $theme_radius, 'f' => $theme_font,
        'fbg' => $flash_bg_color, 'ftxt' => $flash_text_color,
    ]);

    addToast('success', 'Thème réinitialisé aux valeurs par défaut !');
}

/* --------------------------------------------------------------------------
   Inscription — Paramètres (montant, nb premiers inscrits, activation)
-------------------------------------------------------------------------- */
if (isset($_POST['save_inscription_params'])) {
    $accueil_active = !empty($_POST['accueil_active']) ? 1 : 0;
    $registration_fee = (int) ($_POST['registration_fee'] ?? 0);
    $qrcode_mail_limit = max(0, (int) ($_POST['qrcode_mail_limit'] ?? 0));

    $pdo->prepare(
        'UPDATE setting SET registration_fee = :fee,
         accueil_active = :accueil_active, qrcode_mail_limit = :qrcode_mail_limit
         WHERE id = 1'
    )->execute([
        'fee' => $registration_fee,
        'accueil_active' => $accueil_active, 'qrcode_mail_limit' => $qrcode_mail_limit,
    ]);

    addToast('success', 'Paramètres d\'inscription enregistrés !');
}

/* --------------------------------------------------------------------------
   Carte 1 : Liaison AssoConnect
-------------------------------------------------------------------------- */
if (isset($_POST['LinkAssoConnect'])) {

    /* a) Lecture & validation (CWE-79) */
    $iframe = trim($_POST['assoconnect_iframe'] ?? '');
    $script = trim($_POST['assoconnect_js']     ?? '');

    if ($iframe === '' || $script === '') {
         addToast('danger', 'Les deux champs sont obligatoires.');
    } elseif (!preg_match('#^<(iframe[^>]+src=["\']https://[a-z0-9.-]*\.assoconnect\.com/|div[^>]+class=["\'][^"\']*iframe-asc-container)#i', $iframe)) {
         addToast('danger', 'Le code DIV/iframe doit provenir d\'AssoConnect.');
    } elseif (!preg_match('#^<script[^>]+src=["\']https://[a-z0-9.-]*\.assoconnect\.com/#i', $script)) {
         addToast('danger', 'Le script doit pointer vers un domaine AssoConnect (https://xxx.assoconnect.com).');
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
                addToast('success', 'Liaison AssoConnect enregistrée !');
            } else {
                 addToast('warning', 'Aucun changement détecté.', 10000);
            }

            /* Mettre à jour les variables pour le pré-remplissage */
            $assoconnectIframe = $iframe;
            $assoconnectJs     = $script;
        } else {
            /* $execute a échoué : on affiche le message renvoyé par PDO */
            $msg  = $upd->errorInfo()[2] ?? 'Erreur inconnue';
            addToast('danger', 'Erreur SQL&nbsp;: ' . htmlspecialchars($msg) , 10000);
        }
    }
}

/* --------------------------------------------------------------------------
   Accueil — Hero (titre/image sur la vidéo)
-------------------------------------------------------------------------- */
if (isset($_POST['save_hero'])) {
    $newTitleAccueil = $_POST['titleAccueil'] ?? '';
    $newTitleAccueilMobile = $_POST['titleAccueil_mobile'] ?? '';
    $newSubtitleAccueil = trim($_POST['subtitle_accueil'] ?? '');
    $newSubtitleAccueilMobile = trim($_POST['subtitle_accueil_mobile'] ?? '');

    $pdo->prepare(
        'UPDATE setting SET titleAccueil = :t, titleAccueil_mobile = :tm,
         subtitle_accueil = :st, subtitle_accueil_mobile = :stm WHERE id = 1'
    )->execute([
        't' => $newTitleAccueil, 'tm' => $newTitleAccueilMobile,
        'st' => $newSubtitleAccueil, 'stm' => $newSubtitleAccueilMobile,
    ]);

    addToast('success', 'Contenu enregistré !');
    $titleAccueil = $newTitleAccueil;
    $titleAccueil_mobile = $newTitleAccueilMobile;
    $subtitle_accueil = $newSubtitleAccueil;
    $subtitle_accueil_mobile = $newSubtitleAccueilMobile;
}

/* --------------------------------------------------------------------------
   Accueil — Paramètres (réseaux, date, bandeau...)
-------------------------------------------------------------------------- */
if (isset($_POST['save_accueil_params'])) {
    $link_instagram = $_POST['link_instagram'] ?? '';
    $link_facebook = $_POST['link_facebook'] ?? '';
    $link_cancer = $_POST['link_cancer'] ?? null;
    $date_course = $_POST['date_course'] ?? null;
    $flash_info_text = trim($_POST['flash_info_text'] ?? '');
    $flash_info_active = !empty($_POST['flash_info_active']) ? 1 : 0;

    if ($date_course) {
        $date_course = $date_course . ' 00:00:00';
    } else {
        $date_course = null;
    }

    $uploadDir = '../files/_pictures/';
    $allowed = ['jpg','jpeg','png','gif','webp'];
    $allowedMime = ['image/jpeg','image/png','image/gif','image/webp'];
    $newPicturePartner = $picture_partner;
    if (!empty($_FILES['picture_partner']['name'])) {
        $ext = strtolower(pathinfo($_FILES['picture_partner']['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['picture_partner']['tmp_name']);
        if (in_array($ext, $allowed, true) && in_array($mime, $allowedMime, true) && $_FILES['picture_partner']['size'] <= 5*1024*1024) {
            $safe = uniqid('img_', true) . '.' . $ext;
            if (move_uploaded_file($_FILES['picture_partner']['tmp_name'], $uploadDir . $safe)) $newPicturePartner = $safe;
        }
    }

    $pdo->prepare(
        'UPDATE setting SET link_instagram = :li, link_facebook = :lf, link_cancer = :lc,
         date_course = :dc, picture_partner = :pp,
         flash_info_text = :ft, flash_info_active = :fa WHERE id = 1'
    )->execute([
        'li' => $link_instagram, 'lf' => $link_facebook, 'lc' => $link_cancer,
        'dc' => $date_course, 'pp' => $newPicturePartner,
        'ft' => $flash_info_text, 'fa' => $flash_info_active,
    ]);

    addToast('success', 'Paramètres enregistrés !');
    $picture_partner = $newPicturePartner;
    $date_formatted = $date_course ? date('Y-m-d', strtotime($date_course)) : '';
}

/* --------------------------------------------------------------------------
   Accueil — Vidéo d'accueil
-------------------------------------------------------------------------- */
if (isset($_POST['save_video_accueil'])) {
    $uploadDir = '../files/';
    $allowedVideo = ['mp4', 'webm', 'ogg'];
    $allowedMimeVideo = ['video/mp4', 'video/webm', 'video/ogg'];

    if (!empty($_FILES['video_accueil']['name'])) {
        $ext = strtolower(pathinfo($_FILES['video_accueil']['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['video_accueil']['tmp_name']);
        if (!in_array($ext, $allowedVideo, true) || !in_array($mime, $allowedMimeVideo, true)) {
            addToast('danger', 'Format vidéo non autorisé (mp4, webm, ogg uniquement).');
        } elseif ($_FILES['video_accueil']['size'] > 50 * 1024 * 1024) {
            addToast('danger', 'Vidéo trop volumineuse (max 50 Mo).');
        } else {
            $safeName = uniqid('vid_', true) . '.' . $ext;
            if (move_uploaded_file($_FILES['video_accueil']['tmp_name'], $uploadDir . $safeName)) {
                // Supprimer l'ancienne vidéo si ce n'est pas la vidéo par défaut
                if ($video_accueil && $video_accueil !== 'FER.mp4' && file_exists($uploadDir . $video_accueil)) {
                    @unlink($uploadDir . $video_accueil);
                }
                $pdo->prepare('UPDATE setting SET video_accueil = :v WHERE id = 1')
                    ->execute(['v' => $safeName]);
                $video_accueil = $safeName;
                addToast('success', 'Vidéo d\'accueil mise à jour !');
            } else {
                addToast('danger', 'Erreur lors de l\'upload de la vidéo.');
            }
        }
    } else {
        addToast('warning', 'Aucune vidéo sélectionnée.');
    }
}

/* --------------------------------------------------------------------------
   Configuration générale — Mode maintenance
-------------------------------------------------------------------------- */
if (isset($_POST['save_maintenance'])) {
    $maintenance_mode = !empty($_POST['maintenance_mode']) ? 1 : 0;
    $maintenance_message = trim($_POST['maintenance_message'] ?? '');

    $pdo->prepare('UPDATE setting SET maintenance_mode = :m, maintenance_message = :msg WHERE id = 1')
        ->execute(['m' => $maintenance_mode, 'msg' => $maintenance_message]);

    addToast('success', 'Mode maintenance mis à jour !');
}

/* --------------------------------------------------------------------------
   Carte 4 : PARCOURS
-------------------------------------------------------------------------- */
if (isset($_POST['parcours'])) {

$parcoursDesc = $_POST['parcoursDesc'] ?? '';  

/* 1) Sécuriser / valider le titre */
    $newTitleParcours = trim($_POST['titleParcours'] ?? '');
    if ($newTitleParcours === '') {
         addToast('danger', 'Le titre ne peut pas être vide.');
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
                addToast('danger', 'Format d\'image non autorisé.');
            } elseif ($_FILES['picture_gradient']['size'] > 5 * 1024 * 1024) {
                addToast('danger', 'Image trop volumineuse (max 5 Mo).');
            } else {
                $safeNameGradient = uniqid('img_', true) . '.' . $extGradient;
                $tmpGradient      = $_FILES['picture_gradient']['tmp_name'];

                if (move_uploaded_file($tmpGradient, $uploadDir . $safeNameGradient)) {
                    $newPictureGradient = $safeNameGradient;
                } else {
                    addToast('danger', 'Erreur lors de l\'upload de l\'image.');
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
                addToast('danger', 'Format d\'image non autorisé.');
            } elseif ($_FILES['picture_parcours']['size'] > 5 * 1024 * 1024) {
                addToast('danger', 'Image trop volumineuse (max 5 Mo).');
            } else {
                $safeNameParcours = uniqid('img_', true) . '.' . $extParcours;
                $tmpParcours      = $_FILES['picture_parcours']['tmp_name'];

                if (move_uploaded_file($tmpParcours, $uploadDir . $safeNameParcours)) {
                    $newPictureParcours = $safeNameParcours;
                } else {
                    addToast('danger', 'Erreur lors de l\'upload de l\'image.');
                }
            }
        }

        /* 3) Si pas d'erreur, mise à jour BD */
        $hasError = !empty($_SESSION['toasts']) && array_filter($_SESSION['toasts'], fn($t) => $t['type'] === 'danger');
        if (!$hasError) {
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

            addToast('success', 'Configuration enregistrée !');

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
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $uploadDir = '../files/_parcours/';
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $files = $_FILES['galerieImages'];
    $existing = is_dir($uploadDir) ? array_diff(scandir($uploadDir), ['.', '..']) : [];
    $remaining = 30 - count($existing);
    $uploaded = [];

    if (count($files['name']) > $remaining) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['error' => "Limite: $remaining image(s) restantes", 'uploaded' => []]);
            exit;
        }
        addToast('danger', "Vous ne pouvez importer que $remaining image(s) supplémentaires.");
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
                    $uploaded[] = $safeName;
                    try {
                        $maxStmt = $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM parcours_images");
                        $nextOrder = $maxStmt->fetch(PDO::FETCH_ASSOC)['next_order'];
                        $insStmt = $pdo->prepare("INSERT INTO parcours_images (filename, sort_order) VALUES (?, ?)");
                        $insStmt->execute([$safeName, $nextOrder]);
                    } catch (PDOException $e) {}
                }
            }
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['uploaded' => $uploaded]);
            exit;
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=parcours");
        exit;
    }
}

/* --------------------------------------------------------------------------
   Carte 1 : Liaison AssoConnect
-------------------------------------------------------------------------- */
if (isset($_POST['reglementation'])) {

    /* a) Lecture & sanitisation HTML (CWE-79) */
    $div_reglementation = sanitizeHtml(trim($_POST['div_reglementation'] ?? ''));

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
            addToast('success', 'Réglementation enregistrée !');
        } else {
                addToast('warning', 'Aucun changement détecté.', 10000);
        }
    } else {
        /* $execute a échoué : on affiche le message renvoyé par PDO */
        $msg  = $upd->errorInfo()[2] ?? 'Erreur inconnue';
        addToast('danger', 'Erreur SQL&nbsp;: ' . htmlspecialchars($msg) , 10000);
    }
}

/* --------------------------------------------------------------------------
   Carte : Formulaire
-------------------------------------------------------------------------- */
// Sauvegarde des champs formulaire
if (isset($_POST['save_fields'])) {
    foreach ($allFields as $f) {
        $id = $f['id'];
        $isLocked = (int) $f['is_locked'];

        // Champs verrouillés : on ne touche ni active, ni required, ni visibilité
        if ($isLocked) continue;

        $upd = $pdo->prepare(
            'UPDATE forms SET active = :active, required = :req,
             visible_admin = :va, visible_saisie = :vs, visible_qr = :vq
             WHERE id = :id'
        );
        $upd->execute([
            'active' => isset($_POST["active_{$id}"]) ? 1 : 0,
            'req'    => isset($_POST["required_{$id}"]) ? 1 : 0,
            'va'     => isset($_POST["va_{$id}"]) ? 1 : 0,
            'vs'     => isset($_POST["vs_{$id}"]) ? 1 : 0,
            'vq'     => isset($_POST["vq_{$id}"]) ? 1 : 0,
            'id'     => $id,
        ]);
    }
    addToast('success', 'Configuration des champs enregistrée !');
    // Recharger
    $stmtForms = $pdo->prepare('SELECT * FROM forms ORDER BY sort_order ASC');
    $stmtForms->execute();
    $allFields = $stmtForms->fetchAll(PDO::FETCH_ASSOC);
}

// Ajouter un champ personnalisé
if (isset($_POST['add_custom_field'])) {
    $newLabel = trim($_POST['new_label'] ?? '');
    $newType  = $_POST['new_type'] ?? 'text';
    $newOpts  = trim($_POST['new_options'] ?? '');

    if ($newLabel === '') {
        addToast('danger', 'Le libellé du champ ne peut pas être vide.');
    } else {
        // Générer un nom de colonne safe
        $colName = 'custom_' . preg_replace('/[^a-z0-9_]/', '', strtolower(
            str_replace([' ', '-', 'é', 'è', 'ê', 'à', 'ù', 'ô', 'î', 'ï', 'ë', 'ç'],
                        ['_', '_', 'e', 'e', 'e', 'a', 'u', 'o', 'i', 'i', 'e', 'c'], $newLabel)
        ));
        $colName = substr($colName, 0, 50);

        // Vérifier que la colonne n'existe pas déjà
        $exists = $pdo->prepare('SELECT COUNT(*) FROM forms WHERE bdd_column = ?');
        $exists->execute([$colName]);
        if ($exists->fetchColumn() > 0) {
            addToast('danger', 'Un champ avec ce nom existe déjà.');
        } else {
            try {
                // ALTER TABLE pour ajouter la colonne
                $pdo->exec("ALTER TABLE `registrations` ADD COLUMN `{$colName}` VARCHAR(255) DEFAULT NULL");

                // Trouver le prochain sort_order
                $maxSort = (int) $pdo->query('SELECT MAX(sort_order) FROM forms')->fetchColumn();

                $ins = $pdo->prepare(
                    'INSERT INTO forms (fields, label, field_type, bdd_column, active, required,
                     is_locked, is_default, visible_public, visible_admin, visible_saisie, visible_qr,
                     sort_order, options_list, encrypted)
                     VALUES (?, ?, ?, ?, 1, 0, 0, 0, 1, 1, 1, 1, ?, ?, 1)'
                );
                $ins->execute([
                    'custom_' . uniqid(), $newLabel, $newType, $colName,
                    $maxSort + 1, $newOpts ?: null
                ]);

                addToast('success', "Champ « {$newLabel} » ajouté avec succès !");
                // Recharger
                $stmtForms = $pdo->prepare('SELECT * FROM forms ORDER BY sort_order ASC');
                $stmtForms->execute();
                $allFields = $stmtForms->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                addToast('danger', 'Erreur : ' . htmlspecialchars($e->getMessage()));
            }
        }
    }
}

// Supprimer un champ personnalisé
if (isset($_POST['delete_field_id'])) {
    $delId = (int) $_POST['delete_field_id'];
    $delField = $pdo->prepare('SELECT * FROM forms WHERE id = ? AND is_default = 0');
    $delField->execute([$delId]);
    $fieldToDelete = $delField->fetch(PDO::FETCH_ASSOC);

    if ($fieldToDelete) {
        try {
            // DROP la colonne dans registrations
            $col = $fieldToDelete['bdd_column'];
            $pdo->exec("ALTER TABLE `registrations` DROP COLUMN `{$col}`");
            // Supprimer de forms
            $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$delId]);

            addToast('success', "Champ « {$fieldToDelete['label']} » supprimé (colonne et données supprimées).");
            // Recharger
            $stmtForms = $pdo->prepare('SELECT * FROM forms ORDER BY sort_order ASC');
            $stmtForms->execute();
            $allFields = $stmtForms->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            addToast('danger', 'Erreur suppression : ' . htmlspecialchars($e->getMessage()));
        }
    } else {
        addToast('danger', 'Ce champ ne peut pas être supprimé (champ par défaut).');
    }
}

/* --------------------------------------------------------------------------
   Carte : Import excel
-------------------------------------------------------------------------- */
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
        'created_at',
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

    addToast('success', 'Configuration enregistrée !');

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
    addToast('success', 'Image supprimée avec succès.');
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
    addToast('success', 'Image supprimée avec succès.');
}
if (isset($_POST['delete_picture_partner']) && $picture_partner) {
    $filePath = '../files/_pictures/' . $picture_partner;
    if (file_exists($filePath)) {
        unlink($filePath); // Supprime le fichier
    }

    // Supprime la référence dans la base de données
    $stmt = $pdo->prepare('UPDATE setting SET picture_partner = NULL WHERE id = :id');
    $stmt->execute(['id' => 1]);

    $picture_partner = '';
    addToast('success', 'Image supprimée avec succès.');
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
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.auto-dismiss').forEach(function(alert) {
    var delay = parseInt(alert.dataset.dismissDelay) || 5000;
    setTimeout(function() {
      var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
      bsAlert.close();
    }, delay);
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
    border-bottom-color: #F42182; background: transparent;
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
  .theme-mode-tab {
    background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0;
    border-radius: 8px; cursor: pointer; transition: all .2s;
  }
  .theme-mode-tab:hover { background: #e2e8f0; color: #1e293b; }
  .theme-mode-tab.active[data-mode="light"] { background: #ffffff; color: #1e293b; border-color: #cbd5e1; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
  .theme-mode-tab.active[data-mode="dark"] { background: #0f172a; color: #e2e8f0; border-color: #334155; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
</style>

<?php
// Determine active tab based on which form was submitted
$activeTab = 'personnalisation';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_maintenance'])) $activeTab = 'maintenance';
    elseif (isset($_POST['save_navbar_logo']) || isset($_POST['save_theme']) || isset($_POST['reset_theme']) || isset($_POST['save_flash_colors']) || isset($_POST['reset_flash_colors'])) $activeTab = 'personnalisation';
    elseif (isset($_POST['save_hero']) || isset($_POST['save_accueil_params']) || isset($_POST['delete_picture_partner']) || isset($_POST['save_video_accueil'])) $activeTab = 'accueil';
    elseif (isset($_POST['save_header']) || isset($_POST['LinkAssoConnect']) || isset($_POST['save_inscription_params'])) $activeTab = 'inscription';
    elseif (isset($_POST['parcours']) || isset($_POST['uploadGalerie']) || isset($_POST['delete_picture_parcours']) || isset($_POST['delete_picture_gradient'])) $activeTab = 'parcours';
    elseif (isset($_POST['reglementation'])) $activeTab = 'reglementation';
    elseif (isset($_POST['save_fields']) || isset($_POST['add_custom_field']) || isset($_POST['delete_field_id'])) $activeTab = 'formulaire';
    elseif (isset($_POST['importExcel'])) $activeTab = 'import';
}
// Also check URL hash
if (isset($_GET['tab']) && in_array($_GET['tab'], ['personnalisation','accueil','inscription','parcours','reglementation','formulaire','import','maintenance'])) {
    $activeTab = $_GET['tab'];
}
?>

<h1 class="mb-3 fw-bold"><i class="bi bi-gear me-2"></i>Réglages</h1>

<!-- Settings Navigation Tabs -->
<ul class="nav settings-tabs" id="settingsTabs">
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'personnalisation' ? 'active' : '' ?>" href="#" data-tab="personnalisation">Personnalisation</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'accueil' ? 'active' : '' ?>" href="#" data-tab="accueil">Accueil</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'inscription' ? 'active' : '' ?>" href="#" data-tab="inscription">Inscription</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'parcours' ? 'active' : '' ?>" href="#" data-tab="parcours">Parcours</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'reglementation' ? 'active' : '' ?>" href="#" data-tab="reglementation">Reglementation</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'formulaire' ? 'active' : '' ?>" href="#" data-tab="formulaire">Formulaire</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'import' ? 'active' : '' ?>" href="#" data-tab="import">Import Excel</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'maintenance' ? 'active' : '' ?>" href="#" data-tab="maintenance">Maintenance</a></li>
</ul>

<!-- ═══ TAB: Personnalisation ═══ -->
<div class="settings-section <?= $activeTab === 'personnalisation' ? 'active' : '' ?>" id="tab-personnalisation">
  <div class="row g-4">

    <!-- Carte : Logo -->
    <div class="col-12">
      <div class="setting-card">
        <h2>Logo de la navbar</h2>
        <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
          <?= csrf_field() ?>
          <div class="col-12">
            <label class="form-label">Changer le logo</label>
            <input type="file" class="form-control" name="navbar_logo" accept="image/*">
            <small class="text-muted">Formats : JPG, PNG, GIF, WebP, SVG — Max 5 Mo</small>
          </div>
          <div class="col-12">
            <label class="form-label">Logo actuel</label>
            <div>
              <?php if ($navbar_logo && file_exists('../files/_logos/' . $navbar_logo)): ?>
                <div class="mb-2"><img src="../files/_logos/<?= rawurlencode($navbar_logo) ?>" alt="Logo actuel" class="img-thumbnail" style="max-height:60px;background:#f8f8f8;"></div>
                <small class="text-muted"><?= htmlspecialchars($navbar_logo) ?></small>
              <?php else: ?>
                <span class="text-muted">Aucun logo</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-12 text-end">
            <button type="submit" name="save_navbar_logo" class="btn btn-primary w-auto">Sauvegarder</button>
          </div>
        </form>
      </div>
    </div><!-- /col-12 -->

    <!-- Carte : Thème -->
    <div class="col-12">
      <div class="setting-card">
        <h2>Thème du site</h2>
        <form action="" method="post" class="needs-validation" id="themeForm">
          <?= csrf_field() ?>

          <!-- Sous-onglets Light / Dark -->
          <div class="d-flex gap-2 mb-3">
            <button type="button" class="btn btn-sm theme-mode-tab active" data-mode="light" style="padding:6px 16px;font-weight:600;font-size:13px">
              <i class="bi bi-sun me-1"></i>Light
            </button>
            <button type="button" class="btn btn-sm theme-mode-tab" data-mode="dark" style="padding:6px 16px;font-weight:600;font-size:13px">
              <i class="bi bi-moon me-1"></i>Dark
            </button>
          </div>

          <div class="row g-3">
            <!-- Light mode colors -->
            <div class="theme-mode-panel" id="themePanelLight">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Couleur primaire <span class="badge bg-light text-dark" style="font-size:10px">Light</span></label>
                  <div class="d-flex align-items-center gap-2">
                    <input type="color" class="form-control form-control-color" id="themePrimary" name="theme_primary_color" value="<?= htmlspecialchars($theme_primary) ?>" style="width:50px;height:38px">
                    <code id="themePrimaryHex"><?= htmlspecialchars($theme_primary) ?></code>
                  </div>
                  <small class="text-muted">Boutons rose, accents, liens actifs</small>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Couleur secondaire <span class="badge bg-light text-dark" style="font-size:10px">Light</span></label>
                  <div class="d-flex align-items-center gap-2">
                    <input type="color" class="form-control form-control-color" id="themeSecondary" name="theme_secondary_color" value="<?= htmlspecialchars($theme_secondary) ?>" style="width:50px;height:38px">
                    <code id="themeSecondaryHex"><?= htmlspecialchars($theme_secondary) ?></code>
                  </div>
                  <small class="text-muted">Topbar, footer, sections sombres</small>
                </div>
              </div>
            </div>

            <!-- Dark mode colors -->
            <div class="theme-mode-panel" id="themePanelDark" style="display:none">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Couleur primaire <span class="badge bg-dark text-light" style="font-size:10px">Dark</span></label>
                  <div class="d-flex align-items-center gap-2">
                    <input type="color" class="form-control form-control-color" id="themeDarkPrimary" name="theme_dark_primary_color" value="<?= htmlspecialchars($theme_dark_primary) ?>" style="width:50px;height:38px">
                    <code id="themeDarkPrimaryHex"><?= htmlspecialchars($theme_dark_primary) ?></code>
                  </div>
                  <small class="text-muted">Version claire du rose pour fond sombre</small>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Couleur secondaire <span class="badge bg-dark text-light" style="font-size:10px">Dark</span></label>
                  <div class="d-flex align-items-center gap-2">
                    <input type="color" class="form-control form-control-color" id="themeDarkSecondary" name="theme_dark_secondary_color" value="<?= htmlspecialchars($theme_dark_secondary) ?>" style="width:50px;height:38px">
                    <code id="themeDarkSecondaryHex"><?= htmlspecialchars($theme_dark_secondary) ?></code>
                  </div>
                  <small class="text-muted">Textes et accents sur fond sombre</small>
                </div>
              </div>
            </div>

            <!-- Arrondi + Police (communs aux deux modes) -->
            <div class="col-md-6">
              <label class="form-label">Arrondi des angles</label>
              <div class="d-flex align-items-center gap-2">
                <input type="range" class="form-range" id="themeRadius" name="theme_border_radius" min="0" max="32" step="2" value="<?= $theme_radius ?>">
                <span class="fw-bold" id="themeRadiusValue" style="min-width:40px"><?= $theme_radius ?>px</span>
              </div>
              <small class="text-muted">Cards, boutons, champs de formulaire</small>
            </div>

            <div class="col-md-6">
              <label class="form-label">Police d'écriture</label>
              <select class="form-select" id="themeFont" name="theme_font_family">
                <?php
                $fontsUI = [
                  'system-ui' => 'Système (par défaut)',
                  'Inter' => 'Inter',
                  'Poppins' => 'Poppins',
                  'Roboto' => 'Roboto',
                  'Open Sans' => 'Open Sans',
                  'Montserrat' => 'Montserrat',
                  'Lato' => 'Lato',
                  'Nunito' => 'Nunito',
                  'Raleway' => 'Raleway',
                  'Source Sans 3' => 'Source Sans 3',
                  'Work Sans' => 'Work Sans',
                  'DM Sans' => 'DM Sans',
                  'Outfit' => 'Outfit',
                  'Plus Jakarta Sans' => 'Plus Jakarta Sans',
                  'Manrope' => 'Manrope',
                  'Figtree' => 'Figtree',
                  'Quicksand' => 'Quicksand',
                  'Cabin' => 'Cabin',
                  'Rubik' => 'Rubik',
                  'Karla' => 'Karla',
                ];
                foreach ($fontsUI as $val => $label):
                ?>
                <option value="<?= $val ?>" <?= $theme_font === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted">Appliqué sur tout le site</small>
            </div>

            <!-- Aperçu en direct -->
            <div class="col-12 mt-3">
              <label class="form-label fw-bold">Aperçu en direct</label>
              <div id="themePreview" style="border:1px solid #e2e8f0;border-radius:12px;padding:24px;transition:background .3s,color .3s;">
                <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
                  <button type="button" class="btn" id="prevBtnPrimary" style="border:none;padding:8px 20px;font-weight:600">Bouton primaire</button>
                  <button type="button" class="btn" id="prevBtnSecondary" style="border:none;padding:8px 20px;font-weight:600">Bouton secondaire</button>
                  <button type="button" class="btn" id="prevBtnOutline" style="background:transparent;border:2px solid;padding:8px 20px;font-weight:600">Bouton outline</button>
                </div>
                <div class="d-flex flex-wrap gap-3">
                  <div id="prevCard" style="border:1px solid;padding:16px;width:200px;">
                    <div style="font-weight:700;margin-bottom:8px" id="prevCardTitle">Exemple de carte</div>
                    <div style="font-size:13px;opacity:.65" id="prevCardText">Contenu avec la police et les angles arrondis.</div>
                  </div>
                  <div id="prevCard2" style="border:1px solid;padding:16px;width:200px;">
                    <input type="text" class="form-control mb-2" id="prevInput" placeholder="Champ texte" disabled>
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" checked disabled id="prevSwitch">
                      <label class="form-check-label" style="font-size:13px">Option activée</label>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-12 d-flex justify-content-between">
              <button type="submit" name="reset_theme" class="btn btn-outline-secondary w-auto" onclick="return confirm('Réinitialiser le thème aux valeurs par défaut ?')">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Par défaut
              </button>
              <button type="submit" name="save_theme" class="btn btn-primary w-auto">Sauvegarder le thème</button>
            </div>
          </div>
        </form>
      </div>
    </div><!-- /col-12 -->

    <!-- Carte : Flash Info -->
    <div class="col-12">
      <div class="setting-card">
        <h2>Couleurs du bandeau Flash Info</h2>
        <form action="" method="post" class="row g-3 needs-validation">
          <?= csrf_field() ?>

          <div class="col-md-4">
            <label class="form-label">Couleur de fond</label>
            <div class="d-flex align-items-center gap-2">
              <input type="color" class="form-control form-control-color" id="flashBgColor" name="flash_bg_color" value="<?= htmlspecialchars($flash_bg_color) ?>" style="width:50px;height:38px">
              <code id="flashBgHex"><?= htmlspecialchars($flash_bg_color) ?></code>
            </div>
          </div>

          <div class="col-md-4">
            <label class="form-label">Couleur du texte</label>
            <div class="d-flex align-items-center gap-2">
              <input type="color" class="form-control form-control-color" id="flashTextColor" name="flash_text_color" value="<?= htmlspecialchars($flash_text_color) ?>" style="width:50px;height:38px">
              <code id="flashTextHex"><?= htmlspecialchars($flash_text_color) ?></code>
            </div>
          </div>

          <div class="col-md-4 d-flex align-items-end">
            <div id="flashPreview" style="width:100%;padding:10px 16px;border-radius:var(--radius);font-weight:600;font-size:13px;text-align:center;background:<?= htmlspecialchars($flash_bg_color) ?>;color:<?= htmlspecialchars($flash_text_color) ?>;">
              Apercu du bandeau flash info
            </div>
          </div>

          <div class="col-12 d-flex justify-content-between">
            <button type="submit" name="reset_flash_colors" class="btn btn-outline-secondary w-auto" onclick="return confirm('Réinitialiser les couleurs du bandeau ?')">
              <i class="bi bi-arrow-counterclockwise me-1"></i>Par défaut
            </button>
            <button type="submit" name="save_flash_colors" class="btn btn-primary w-auto">Sauvegarder</button>
          </div>
        </form>
      </div>
    </div><!-- /col-12 -->

  </div><!-- /row -->
</div><!-- /tab-personnalisation -->

<!-- ═══ TAB: Accueil ═══ -->
<div class="settings-section <?= $activeTab === 'accueil' ? 'active' : '' ?>" id="tab-accueil">
  <div class="row g-4">

    <!-- Carte 1 : Titre / Image sur la vidéo -->
    <div class="col-12">
      <div class="setting-card">
        <h2>Titre / Image sur la vidéo</h2>
        <?php $heroSubTab = $_POST['hero_subtab'] ?? 'heroPC'; ?>
        <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
          <?= csrf_field() ?>
          <input type="hidden" name="hero_subtab" id="hero_subtab" value="<?= htmlspecialchars($heroSubTab) ?>">

          <!-- Sous-onglets PC / Mobile -->
          <div class="col-12">
            <ul class="nav nav-tabs" role="tablist" id="heroTabs">
              <li class="nav-item"><a class="nav-link <?= $heroSubTab === 'heroPC' ? 'active' : '' ?>" data-bs-toggle="tab" href="#heroPC" role="tab">PC</a></li>
              <li class="nav-item"><a class="nav-link <?= $heroSubTab === 'heroMobile' ? 'active' : '' ?>" data-bs-toggle="tab" href="#heroMobile" role="tab">Mobile</a></li>
            </ul>
            <div class="tab-content pt-3">
              <!-- PC -->
              <div class="tab-pane fade <?= $heroSubTab === 'heroPC' ? 'show active' : '' ?>" id="heroPC" role="tabpanel">
                <div class="col-12">
                  <label class="form-label">Contenu (texte, image, ou les deux)</label>
                  <textarea class="form-control" id="titleAccueilEditor" name="titleAccueil" rows="3"><?= htmlspecialchars($titleAccueil) ?></textarea>
                  <small class="text-muted">Utilisez la barre d'outils pour ajouter du texte, des images, les aligner, etc.</small>
                </div>
                <div class="col-12 mt-3">
                  <label class="form-label">Sous-titre</label>
                  <input type="text" class="form-control" name="subtitle_accueil" maxlength="255" placeholder="Ex : Course et marche solidaires contre le cancer." value="<?= htmlspecialchars($subtitle_accueil, ENT_QUOTES, 'UTF-8') ?>">
                  <small class="text-muted">Texte affiché sous le contenu principal. Laissez vide pour ne rien afficher.</small>
                </div>
              </div>
              <!-- Mobile -->
              <div class="tab-pane fade <?= $heroSubTab === 'heroMobile' ? 'show active' : '' ?>" id="heroMobile" role="tabpanel">
                <div class="col-12">
                  <label class="form-label">Contenu (texte, image, ou les deux)</label>
                  <textarea class="form-control" id="titleAccueilMobileEditor" name="titleAccueil_mobile" rows="3"><?= htmlspecialchars($titleAccueil_mobile) ?></textarea>
                  <small class="text-muted">Utilisez la barre d'outils pour ajouter du texte, des images, les aligner, etc.</small>
                </div>
                <div class="col-12 mt-3">
                  <label class="form-label">Sous-titre</label>
                  <input type="text" class="form-control" name="subtitle_accueil_mobile" maxlength="255" placeholder="Ex : Course et marche solidaires contre le cancer." value="<?= htmlspecialchars($subtitle_accueil_mobile, ENT_QUOTES, 'UTF-8') ?>">
                  <small class="text-muted">Texte affiché sous le contenu principal sur mobile. Laissez vide pour ne rien afficher.</small>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 text-end">
            <button type="submit" name="save_hero" class="btn btn-primary w-auto">Sauvegarder</button>
          </div>
        </form>
      </div>
    </div><!-- /col-12 -->

    <!-- Carte 2 : Paramètres page accueil -->
    <div class="col-12">
      <div class="setting-card">
        <h2>Paramètres page accueil</h2>
        <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
          <?= csrf_field() ?>

          <div class="col-md-6"><label class="form-label">Lien Facebook</label>
            <input type="text" class="form-control" name="link_facebook" placeholder="Lien Facebook" value="<?= htmlspecialchars($link_facebook, ENT_QUOTES, 'UTF-8'); ?>">
          </div>
          <div class="col-md-6"><label class="form-label">Lien Instagram</label>
            <input type="text" class="form-control" name="link_instagram" placeholder="Lien Instagram" value="<?= htmlspecialchars($link_instagram, ENT_QUOTES, 'UTF-8'); ?>">
          </div>
          <div class="col-md-6"><label class="form-label">Lien de la Ligue contre le cancer</label>
            <input type="text" class="form-control" name="link_cancer" placeholder="Lien de la Ligue contre le cancer" value="<?= htmlspecialchars($link_cancer, ENT_QUOTES, 'UTF-8'); ?>">
          </div>
          <div class="col-md-6"><label class="form-label">Image des partenaires</label>
            <input type="file" class="form-control" id="picture_partner" name="picture_partner" accept="image/*">
            <?php if ($picture_partner) : ?>
              <small class="text-muted">Image actuelle : <?= htmlspecialchars($picture_partner) ?></small>
              <div class="mb-2">
                <img src="../files/_pictures/<?= rawurlencode($picture_partner) ?>" alt="Image actuelle" class="img-thumbnail" style="max-width:145px;">
              </div>
              <button type="submit" name="delete_picture_partner" value="1" class="btn btn-danger btn-sm">Supprimer l'image</button>
            <?php endif; ?>
          </div>
          <div class="col-md-6">
            <label class="form-label">Date de la course</label>
            <input type="date" class="form-control" name="date_course" value="<?= htmlspecialchars($date_formatted, ENT_QUOTES, 'UTF-8'); ?>">
          </div>

          <div class="col-12"><hr class="my-2"><h6 class="text-muted mb-0">Bandeau Flash Info</h6></div>
          <div class="col-md-8"><label class="form-label">Texte du bandeau défilant</label>
            <input type="text" class="form-control" name="flash_info_text" placeholder="Ex : Inscriptions ouvertes ! Rendez-vous le 5 juillet..." value="<?= htmlspecialchars($flash_info_text, ENT_QUOTES, 'UTF-8'); ?>" maxlength="500">
          </div>
          <div class="col-md-4"><label class="form-label">Activer le bandeau</label>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="flash_info_active" id="flash_info_active" <?= $flash_info_active ? 'checked' : '' ?>>
              <label class="form-check-label" for="flash_info_active">Oui / Non</label>
            </div>
          </div>

          <div class="col-12 text-end">
            <button type="submit" name="save_accueil_params" class="btn btn-primary w-auto">Sauvegarder</button>
          </div>
        </form>
      </div>
    </div><!-- /col-12 -->

    <!-- Carte 3 : Vidéo d'accueil -->
    <div class="col-12">
      <div class="setting-card">
        <h2>Vidéo d'accueil</h2>
        <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
          <?= csrf_field() ?>

          <div class="col-md-8">
            <label class="form-label">Changer la vidéo</label>
            <input type="file" class="form-control" name="video_accueil" accept="video/mp4,video/webm,video/ogg">
            <small class="text-muted">Formats acceptés : MP4, WebM, OGG — Max 50 Mo. La vidéo actuelle sera remplacée.</small>
          </div>

          <div class="col-md-4">
            <label class="form-label">Vidéo actuelle</label>
            <div>
              <?php if ($video_accueil && file_exists('../files/' . $video_accueil)): ?>
                <video style="max-width:100%;max-height:150px;border-radius:8px;border:1px solid #f0e8eb;" autoplay muted loop playsinline>
                  <source src="../files/<?= rawurlencode($video_accueil) ?>" type="video/mp4">
                </video>
                <div class="mt-1"><small class="text-muted"><?= htmlspecialchars($video_accueil) ?></small></div>
              <?php else: ?>
                <span class="text-muted">Aucune vidéo</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="col-12 text-end">
            <button type="submit" name="save_video_accueil" class="btn btn-primary w-auto">Sauvegarder</button>
          </div>
        </form>
      </div>
    </div><!-- /col-12 -->

  </div><!-- /row -->
</div><!-- /tab-accueil -->

<!-- ═══ TAB: Inscription ═══ -->
<div class="settings-section <?= $activeTab === 'inscription' ? 'active' : '' ?>" id="tab-inscription">
  <div class="row g-4">
    <div class="col-12">
      <div class="setting-card">
        <h2>En-tête du site d'inscription</h2>
        <?php $headerSubTab = $_POST['header_subtab'] ?? 'headerPC'; ?>
        <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
          <?= csrf_field() ?>
          <input type="hidden" name="header_subtab" id="header_subtab" value="<?= htmlspecialchars($headerSubTab) ?>">

          <!-- Sous-onglets PC / Mobile -->
          <div class="col-12">
            <ul class="nav nav-tabs" role="tablist" id="headerTabs">
              <li class="nav-item"><a class="nav-link <?= $headerSubTab === 'headerPC' ? 'active' : '' ?>" data-bs-toggle="tab" href="#headerPC" role="tab">PC</a></li>
              <li class="nav-item"><a class="nav-link <?= $headerSubTab === 'headerMobile' ? 'active' : '' ?>" data-bs-toggle="tab" href="#headerMobile" role="tab">Mobile</a></li>
            </ul>
            <div class="tab-content pt-3">
              <!-- PC -->
              <div class="tab-pane fade <?= $headerSubTab === 'headerPC' ? 'show active' : '' ?>" id="headerPC" role="tabpanel">
                <div class="col-12">
                  <label class="form-label">Contenu (texte, image, ou les deux)</label>
                  <textarea class="form-control" id="headerTitleEditor" name="title" rows="3"><?= htmlspecialchars($title) ?></textarea>
                  <small class="text-muted">Utilisez la barre d'outils pour ajouter du texte, des images, les aligner, etc.</small>
                </div>
              </div>
              <!-- Mobile -->
              <div class="tab-pane fade <?= $headerSubTab === 'headerMobile' ? 'show active' : '' ?>" id="headerMobile" role="tabpanel">
                <div class="col-12">
                  <label class="form-label">Contenu (texte, image, ou les deux)</label>
                  <textarea class="form-control" id="headerTitleMobileEditor" name="title_mobile" rows="3"><?= htmlspecialchars($title_mobile) ?></textarea>
                  <small class="text-muted">Utilisez la barre d'outils pour ajouter du texte, des images, les aligner, etc.</small>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 text-end">
            <button type="submit" name="save_header" class="btn btn-primary w-auto">Sauvegarder</button>
          </div>
        </form>
      </div>
    </div><!-- /col-12 -->

    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2>Paramètres d'inscription</h2>
        <form action="" method="post" class="row g-3 needs-validation">
          <?= csrf_field() ?>
          <div class="col-12"><label class="form-label">Montant de l'inscription</label>
            <select id="registration_fee" name="registration_fee" class="form-select">
              <?php for ($i = 0; $i <= 100; $i++): ?>
              <option value="<?= $i ?>" <?= ($i == (int)$registration_fee ? 'selected' : '') ?>><?= $i ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-12"><label class="form-label">Nombre de premiers inscrits</label>
            <input type="number" class="form-control" name="qrcode_mail_limit" min="0" value="<?= $qrcode_mail_limit ?>" placeholder="Ex : 800">
            <small class="text-muted">Utilisé pour la coloration rose dans le dashboard et le QR Code (si mode = X premiers).</small>
          </div>
          <div class="col-12">
            <label class="form-label">Activer les inscriptions</label>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="accueil_active" id="accueil_active_gen" <?= isset($accueil_active) && $accueil_active ? 'checked' : '' ?>>
              <label class="form-check-label" for="accueil_active_gen">Oui / Non</label>
            </div>
          </div>
          <div class="col-12 text-end">
            <button type="submit" name="save_inscription_params" class="btn btn-primary w-auto">Sauvegarder</button>
          </div>
        </form>
      </div>
    </div><!-- /col-lg-6 -->

    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2>Liaison AssoConnect</h2>
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
</div><!-- /tab-inscription -->

<!-- ═══ TAB: Parcours ═══ -->
<div class="settings-section <?= $activeTab === 'parcours' ? 'active' : '' ?>" id="tab-parcours">
  <div class="row g-4">
    <div class="col-12">
      <div class="setting-card">
        <h2>Parcours</h2>
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
          <h5 class="modal-title"><i class="bi bi-images"></i> Galerie d'images du parcours</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
        </div>
        <div class="modal-body">
          <!-- Upload zone drag & drop -->
          <div id="galUploadZone" style="border:2px dashed #93c5fd;border-radius:12px;padding:30px;text-align:center;background:#eff6ff;cursor:pointer;transition:all .2s;margin-bottom:20px">
            <i class="bi bi-cloud-arrow-up" style="font-size:2.5rem;color:#2563eb"></i>
            <p class="mb-1 fw-semibold" style="color:#2563eb">Glissez vos photos ici ou cliquez pour selectionner</p>
            <p class="text-muted small mb-0">JPG, PNG, GIF, WEBP - Max 5 Mo/image - <span id="remainingCount"><?= $remaining ?></span> place(s) restante(s)</p>
            <input type="file" id="galFileInput" multiple accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
          </div>

          <!-- Progress bar -->
          <div id="galProgressWrap" style="display:none;margin-bottom:20px">
            <div class="d-flex justify-content-between mb-1">
              <small class="fw-semibold" id="galProgressLabel">Upload en cours...</small>
              <small id="galProgressPercent">0%</small>
            </div>
            <div class="progress" style="height:8px;border-radius:4px">
              <div class="progress-bar" id="galProgressBar" role="progressbar" style="width:0%;background:#2563eb;transition:width .3s"></div>
            </div>
            <small class="text-muted" id="galProgressDetail"></small>
          </div>

          <!-- Delete all button -->
          <div id="galDeleteAllWrap" style="<?= empty($images) ? 'display:none;' : '' ?>margin-bottom:15px;text-align:right">
            <button type="button" class="btn btn-sm btn-danger" id="galDeleteAll"><i class="bi bi-trash3"></i> Tout supprimer</button>
          </div>

          <!-- Photos grid -->
          <div id="galerieContainer" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">
            <?php foreach ($images as $img): ?>
              <div class="sortable-image-item" data-filename="<?= htmlspecialchars($img) ?>" style="position:relative;border-radius:8px;overflow:hidden;aspect-ratio:1;background:#f1f5f9;cursor:grab">
                <img src="<?= $galerieDir . rawurlencode($img) ?>" style="width:100%;height:100%;object-fit:cover;display:block" loading="lazy">
                <div style="position:absolute;top:6px;right:6px">
                  <button type="button" class="delete-btn btn btn-sm btn-danger" data-filename="<?= htmlspecialchars($img) ?>" title="Supprimer" style="width:28px;height:28px;padding:0;border-radius:6px;display:flex;align-items:center;justify-content:center;opacity:0.85"><i class="bi bi-trash3" style="font-size:12px"></i></button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if (empty($images)): ?>
          <div id="galEmpty" style="text-align:center;padding:40px;color:#94a3b8">
            <i class="bi bi-image" style="font-size:3rem"></i>
            <p class="mt-2">Aucune photo dans la galerie</p>
          </div>
          <?php else: ?>
          <div id="galEmpty" style="text-align:center;padding:40px;color:#94a3b8;display:none">
            <i class="bi bi-image" style="font-size:3rem"></i>
            <p class="mt-2">Aucune photo dans la galerie</p>
          </div>
          <?php endif; ?>
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
        valid_styles: {
            '*': 'text-align,line-height,color,background-color,font-size,font-weight,font-style,text-decoration,padding,padding-left,padding-right,padding-top,padding-bottom,margin,margin-left,margin-right,margin-top,margin-bottom',
            'img': 'width,height,max-width,float,margin,margin-left,margin-right,margin-top,margin-bottom,display',
            'table': 'width,height,border-collapse,border-spacing'
        },
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
          + 'img[src|alt|title|width|height|class|loading|style],'
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

        // Upload images sur le serveur au lieu de base64
        images_upload_handler: (blobInfo) => new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('file', blobInfo.blob(), blobInfo.filename());
            formData.append('csrf_token', '<?= csrf_token() ?>');
            fetch('../inc/tinymce-upload.php', { method: 'POST', body: formData })
                .then(r => { if (!r.ok) throw new Error('Upload failed'); return r.json(); })
                .then(data => { if (data.location) resolve(data.location); else reject(data.error || 'Upload error'); })
                .catch(e => reject(e.message));
        }),
        automatic_uploads: true,
        images_reuse_filename: true,

        // Upload fichiers (PDF, images) via le sélecteur de fichiers
        file_picker_types: 'file image',
        file_picker_callback: (callback, value, meta) => {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = meta.filetype === 'image' ? 'image/*' : 'image/*,.pdf';
            input.addEventListener('change', () => {
                const file = input.files[0];
                if (!file) return;
                const formData = new FormData();
                formData.append('file', file);
                formData.append('csrf_token', '<?= csrf_token() ?>');
                fetch('../inc/tinymce-upload.php', { method: 'POST', body: formData })
                    .then(r => { if (!r.ok) throw new Error('Upload failed'); return r.json(); })
                    .then(data => { if (data.location) { const n = data.title || file.name.replace(/\.[^.]+$/,''); callback(data.location, { title: n, text: n + '.' + file.name.split('.').pop() }); } })
                    .catch(e => alert('Erreur upload: ' + e.message));
            });
            input.click();
        },

        toolbar_mode: 'sliding'
    });
  </script>
</div><!-- /tab-reglementation -->

<!-- ═══ TAB: Formulaire ═══ -->
<div class="settings-section <?= $activeTab === 'formulaire' ? 'active' : '' ?>" id="tab-formulaire">
  <div class="row g-4">
    <div class="col-12">
      <div class="setting-card">
        <div class="d-flex justify-content-between align-items-center">
          <h2 class="mb-0">Gestion des champs du formulaire</h2>
          <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addFieldModal"><i class="bi bi-plus-lg"></i> Ajouter un champ</button>
        </div>

        <form action="" method="post" class="needs-validation">
          <?= csrf_field() ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-3" style="font-size:13px;">
              <thead class="table-light">
                <tr>
                  <th>Champ</th>
                  <th class="text-center" style="width:70px">Actif</th>
                  <th class="text-center" style="width:70px">Requis</th>
                  <th class="text-center" style="width:70px" title="Modal admin (dashboard)">Admin</th>
                  <th class="text-center" style="width:70px" title="Formulaire saisie">Saisie</th>
                  <th class="text-center" style="width:70px" title="Formulaire d'inscription via QR Code (scan)">Inscr. QR</th>
                  <th class="text-center" style="width:60px">Type</th>
                  <th class="text-center" style="width:70px"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($allFields as $f):
                  $id = $f['id'];
                  $locked = (int) ($f['is_locked'] ?? 0);
                  $default = (int) ($f['is_default'] ?? 1);
                  $active = (int) ($f['active'] ?? 0);
                ?>
                <tr<?= $locked ? ' class="table-light"' : '' ?>>
                  <td>
                    <strong><?= htmlspecialchars($f['label'] ?? $f['fields']) ?></strong>
                    <?php if ($locked): ?><span class="badge bg-secondary ms-1" style="font-size:10px">verrouillé</span><?php endif; ?>
                    <?php if (!$default): ?><span class="badge bg-info ms-1" style="font-size:10px">personnalisé</span><?php endif; ?>
                    <br><small class="text-muted"><?= htmlspecialchars($f['bdd_column'] ?? '') ?></small>
                  </td>
                  <td class="text-center">
                    <?php if ($locked): ?>
                      <input type="checkbox" checked disabled class="form-check-input">
                    <?php else: ?>
                      <input type="checkbox" name="active_<?= $id ?>" class="form-check-input" <?= $active ? 'checked' : '' ?>>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <?php if ($locked): ?>
                      <input type="checkbox" checked disabled class="form-check-input">
                    <?php else: ?>
                      <input type="checkbox" name="required_<?= $id ?>" class="form-check-input" <?= (int)($f['required'] ?? 0) ? 'checked' : '' ?>>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <?php if ($locked): ?>
                      <input type="checkbox" checked disabled class="form-check-input">
                    <?php else: ?>
                      <input type="checkbox" name="va_<?= $id ?>" class="form-check-input" <?= (int)($f['visible_admin'] ?? 1) ? 'checked' : '' ?>>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <?php if ($locked): ?>
                      <input type="checkbox" checked disabled class="form-check-input">
                    <?php else: ?>
                      <input type="checkbox" name="vs_<?= $id ?>" class="form-check-input" <?= (int)($f['visible_saisie'] ?? 1) ? 'checked' : '' ?>>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <?php if ($locked): ?>
                      <input type="checkbox" checked disabled class="form-check-input">
                    <?php else: ?>
                      <input type="checkbox" name="vq_<?= $id ?>" class="form-check-input" <?= (int)($f['visible_qr'] ?? 1) ? 'checked' : '' ?>>
                    <?php endif; ?>
                  </td>
                  <td class="text-center"><small><?= htmlspecialchars($f['field_type'] ?? 'text') ?></small></td>
                  <td class="text-center">
                    <?php if (!$default): ?>
                      <button type="submit" name="delete_field_id" value="<?= $id ?>" class="btn btn-outline-danger btn-sm"
                        onclick="return confirm('ATTENTION : Cela supprimera la colonne « <?= htmlspecialchars($f['label']) ?> » et toutes ses données en base.\n\nSi vous voulez juste masquer le champ, décochez-le et cliquez Sauvegarder.\n\nSupprimer définitivement ?')">
                        <i class="bi bi-trash"></i>
                      </button>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="text-end">
            <button type="submit" name="save_fields" class="btn btn-primary">Sauvegarder</button>
          </div>
        </form>
      </div><!-- /setting-card fields -->
    </div><!-- /col-12 -->

    <!-- Modal ajout champ personnalisé -->
    <div class="modal fade" id="addFieldModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form action="" method="post">
            <?= csrf_field() ?>
            <div class="modal-header">
              <h5 class="modal-title">Ajouter un champ personnalisé</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body row g-3">
              <div class="col-12">
                <label class="form-label">Libellé du champ</label>
                <input type="text" name="new_label" class="form-control" placeholder="Ex : Allergie, Distance..." required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Type</label>
                <select name="new_type" class="form-select">
                  <option value="text">Texte</option>
                  <option value="number">Nombre</option>
                  <option value="date">Date</option>
                  <option value="select">Liste déroulante</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Options (si liste déroulante)</label>
                <input type="text" name="new_options" class="form-control" placeholder="opt1,opt2,opt3">
                <small class="text-muted">Séparées par des virgules</small>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
              <button type="submit" name="add_custom_field" class="btn btn-success">Ajouter</button>
            </div>
          </form>
        </div>
      </div>
    </div>

  </div><!-- /row -->
</div><!-- /tab-formulaire -->

<!-- ═══ TAB: Import Excel ═══ -->
<div class="settings-section <?= $activeTab === 'import' ? 'active' : '' ?>" id="tab-import">
  <div class="row g-4">
    <div class="col-12">
      <div class="setting-card">
        <h2>Informations d'import excel</h2>
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
    </div><!-- /col-12 -->
  </div><!-- /row -->
</div><!-- /tab-import -->

<!-- ═══ TAB: Maintenance ═══ -->
<div class="settings-section <?= $activeTab === 'maintenance' ? 'active' : '' ?>" id="tab-maintenance">
  <div class="row g-4">
    <div class="col-12">
      <div class="setting-card">
        <h2>Mode maintenance</h2>
        <form action="" method="post" class="row g-3 needs-validation">
          <?= csrf_field() ?>

          <div class="col-12">
            <label class="form-label">Activer le mode maintenance</label>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="maintenance_mode" id="maintenance_mode" <?= $maintenance_mode ? 'checked' : '' ?>>
              <label class="form-check-label" for="maintenance_mode">
                <?= $maintenance_mode ? '<span class="badge bg-danger">Activé</span>' : '<span class="badge bg-secondary">Désactivé</span>' ?>
              </label>
            </div>
            <small class="text-muted">Lorsque activé, toutes les pages publiques afficheront la page de maintenance.</small>
          </div>

          <div class="col-12">
            <label class="form-label">Message de maintenance</label>
            <textarea class="form-control" name="maintenance_message" rows="3" maxlength="500" placeholder="Ex : Le site est en cours de mise à jour. Nous serons de retour très bientôt !"><?= htmlspecialchars($maintenance_message, ENT_QUOTES, 'UTF-8') ?></textarea>
            <small class="text-muted">Ce message sera affiché aux visiteurs. Laissez vide pour le message par défaut.</small>
          </div>

          <div class="col-12 text-end">
            <button type="submit" name="save_maintenance" class="btn btn-primary w-auto">Sauvegarder</button>
          </div>
        </form>
      </div>
    </div><!-- /col-12 -->
  </div><!-- /row -->
</div><!-- /tab-maintenance -->

<!-- Paramètres mail déplacé vers mail-settings.php -->

<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  // Config commune TinyMCE pour les titres
  var tinyOpts = {
    license_key: 'gpl',
    language: 'fr_FR',
    plugins: 'code image',
    toolbar: 'fontfamily fontsize | bold italic underline | forecolor | alignleft aligncenter alignright | image | removeformat code',
    height: 350,
    resize: true,
    menubar: false,
    branding: false,
    statusbar: true,
    object_resizing: 'img',
    image_advtab: true,
    image_dimensions: true,
    content_style: 'body { font-family: system-ui, sans-serif; font-size: 32px; color: #ffffff; background: #1e293b; text-align: center; padding: 16px; } p { margin: 0; } img { max-width: 100%; height: auto; }',
    font_family_formats: "System=system-ui,sans-serif; Georgia=Georgia,serif; Playfair Display='Playfair Display',serif; Bebas Neue='Bebas Neue',sans-serif; Oswald=Oswald,sans-serif; Montserrat=Montserrat,sans-serif; Dancing Script='Dancing Script',cursive; Lobster=Lobster,cursive; Impact=Impact,sans-serif",
    font_size_formats: '16px 20px 24px 28px 32px 40px 48px 56px 64px 72px 80px',
    content_css: 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Bebas+Neue&family=Oswald:wght@700&family=Montserrat:wght@700;900&family=Dancing+Script:wght@700&family=Lobster&display=swap',
    images_upload_handler: (blobInfo) => new Promise((resolve, reject) => {
      const formData = new FormData();
      formData.append('file', blobInfo.blob(), blobInfo.filename());
      formData.append('csrf_token', '<?= csrf_token() ?>');
      fetch('../inc/tinymce-upload.php', { method: 'POST', body: formData })
        .then(r => { if (!r.ok) throw new Error('Upload failed'); return r.json(); })
        .then(data => { if (data.location) resolve(data.location); else reject(data.error || 'Upload error'); })
        .catch(e => reject(e.message));
    }),
    // Texte sur 1 ligne OU 1 image, pas les deux
    setup: function(editor) {
      // Bloquer Entrée (1 seule ligne)
      editor.on('keydown', function(e) {
        if (e.keyCode === 13) e.preventDefault();
      });
      // Quand du texte est tapé et qu'il y a une image, supprimer l'image
      editor.on('input', function() {
        var body = editor.getBody();
        var imgs = body.querySelectorAll('img');
        if (imgs.length > 0 && body.textContent.replace(/\u00a0/g,'').trim().length > 0) {
          imgs.forEach(function(img) { img.remove(); });
        }
      });
      // Après insertion d'image, supprimer le texte autour
      editor.on('ExecCommand', function(e) {
        if (e.command === 'mceInsertContent' || e.command === 'mceImage') {
          var body = editor.getBody();
          var imgs = body.querySelectorAll('img');
          if (imgs.length > 0) {
            var last = imgs[imgs.length - 1];
            // Supprimer les images en trop
            for (var i = 0; i < imgs.length - 1; i++) imgs[i].remove();
            // Vider le texte, garder juste l'image
            var p = last.parentNode;
            if (p && p.childNodes.length > 1) {
              while (p.firstChild) p.removeChild(p.firstChild);
              p.appendChild(last);
            }
          }
        }
      });
    }
  };

  // Mémoriser les sous-onglets actifs (PC/Mobile) dans les champs cachés
  ['heroTabs', 'headerTabs'].forEach(function(tabsId){
    var hiddenId = tabsId.replace('Tabs','_subtab');
    document.querySelectorAll('#'+tabsId+' .nav-link').forEach(function(t){
      t.addEventListener('click', function(){
        var h = document.getElementById(hiddenId);
        if(h) h.value = this.getAttribute('href').replace('#','');
      });
    });
  });

  // Init TinyMCE pour les 4 éditeurs
  if(typeof tinymce !== 'undefined'){
    ['#titleAccueilEditor', '#titleAccueilMobileEditor', '#headerTitleEditor', '#headerTitleMobileEditor'].forEach(function(sel){
      tinymce.init(Object.assign({}, tinyOpts, { selector: sel }));
    });
  }
})();
</script>

<!-- Drag & drop pour l'ordre des sections mail -->
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  var list = document.getElementById('mtcSortable');
  if (!list) return;
  var hidden = document.getElementById('mtcSectionOrder');
  var dragged = null;

  function updateOrder(){
    var items = list.querySelectorAll('li[data-section]');
    var order = [];
    items.forEach(function(li){ order.push(li.getAttribute('data-section')); });
    hidden.value = order.join(',');
  }

  list.querySelectorAll('li').forEach(function(li){
    li.setAttribute('draggable','true');
    li.addEventListener('dragstart', function(e){
      dragged = this;
      this.style.opacity = '.4';
      e.dataTransfer.effectAllowed = 'move';
    });
    li.addEventListener('dragend', function(){
      this.style.opacity = '1';
      list.querySelectorAll('li').forEach(function(l){ l.classList.remove('border-primary'); });
      dragged = null;
    });
    li.addEventListener('dragover', function(e){
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      this.classList.add('border-primary');
    });
    li.addEventListener('dragleave', function(){
      this.classList.remove('border-primary');
    });
    li.addEventListener('drop', function(e){
      e.preventDefault();
      this.classList.remove('border-primary');
      if (dragged !== this) {
        var allItems = Array.from(list.children);
        var fromIdx = allItems.indexOf(dragged);
        var toIdx = allItems.indexOf(this);
        if (fromIdx < toIdx) { list.insertBefore(dragged, this.nextSibling); }
        else { list.insertBefore(dragged, this); }
        updateOrder();
      }
    });
  });

  // Sync color hex codes when color pickers change
  document.querySelectorAll('input[type="color"]').forEach(function(picker){
    picker.addEventListener('input', function(){
      var code = this.parentElement.querySelector('code');
      if (code) code.textContent = this.value;
    });
  });
})();
</script>

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

// ─── Theme live preview ───
(function(){
  var primary = document.getElementById('themePrimary');
  var secondary = document.getElementById('themeSecondary');
  var darkPrimary = document.getElementById('themeDarkPrimary');
  var darkSecondary = document.getElementById('themeDarkSecondary');
  var radius = document.getElementById('themeRadius');
  var font = document.getElementById('themeFont');
  if (!primary) return;

  var currentMode = 'light';

  var fontMap = {
    'system-ui': "system-ui, -apple-system, 'Segoe UI', sans-serif",
    'Inter': "'Inter', sans-serif", 'Poppins': "'Poppins', sans-serif",
    'Roboto': "'Roboto', sans-serif", 'Open Sans': "'Open Sans', sans-serif",
    'Montserrat': "'Montserrat', sans-serif", 'Lato': "'Lato', sans-serif",
    'Nunito': "'Nunito', sans-serif", 'Raleway': "'Raleway', sans-serif",
    'Source Sans 3': "'Source Sans 3', sans-serif", 'Work Sans': "'Work Sans', sans-serif",
    'DM Sans': "'DM Sans', sans-serif", 'Outfit': "'Outfit', sans-serif",
    'Plus Jakarta Sans': "'Plus Jakarta Sans', sans-serif", 'Manrope': "'Manrope', sans-serif",
    'Figtree': "'Figtree', sans-serif", 'Quicksand': "'Quicksand', sans-serif",
    'Cabin': "'Cabin', sans-serif", 'Rubik': "'Rubik', sans-serif",
    'Karla': "'Karla', sans-serif"
  };

  function luminance(hex) {
    hex = hex.replace('#','');
    var r = parseInt(hex.substring(0,2),16)/255;
    var g = parseInt(hex.substring(2,4),16)/255;
    var b = parseInt(hex.substring(4,6),16)/255;
    r = r <= 0.03928 ? r/12.92 : Math.pow((r+0.055)/1.055, 2.4);
    g = g <= 0.03928 ? g/12.92 : Math.pow((g+0.055)/1.055, 2.4);
    b = b <= 0.03928 ? b/12.92 : Math.pow((b+0.055)/1.055, 2.4);
    return 0.2126*r + 0.7152*g + 0.0722*b;
  }
  function autoText(hex) { return luminance(hex) > 0.4 ? '#1e293b' : '#ffffff'; }
  function darken(hex, f) {
    hex = hex.replace('#','');
    var r = Math.max(0, Math.round(parseInt(hex.substring(0,2),16)*(1-f)));
    var g = Math.max(0, Math.round(parseInt(hex.substring(2,4),16)*(1-f)));
    var b = Math.max(0, Math.round(parseInt(hex.substring(4,6),16)*(1-f)));
    return '#'+r.toString(16).padStart(2,'0')+g.toString(16).padStart(2,'0')+b.toString(16).padStart(2,'0');
  }
  function lighten(hex, f) {
    hex = hex.replace('#','');
    var r = Math.min(255, Math.round(parseInt(hex.substring(0,2),16) + (255 - parseInt(hex.substring(0,2),16)) * f));
    var g = Math.min(255, Math.round(parseInt(hex.substring(2,4),16) + (255 - parseInt(hex.substring(2,4),16)) * f));
    var b = Math.min(255, Math.round(parseInt(hex.substring(4,6),16) + (255 - parseInt(hex.substring(4,6),16)) * f));
    return '#'+r.toString(16).padStart(2,'0')+g.toString(16).padStart(2,'0')+b.toString(16).padStart(2,'0');
  }

  // Mode tabs
  document.querySelectorAll('.theme-mode-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
      document.querySelectorAll('.theme-mode-tab').forEach(function(t) { t.classList.remove('active'); t.style.background=''; t.style.color=''; });
      this.classList.add('active');
      currentMode = this.dataset.mode;
      document.getElementById('themePanelLight').style.display = currentMode === 'light' ? '' : 'none';
      document.getElementById('themePanelDark').style.display = currentMode === 'dark' ? '' : 'none';
      updatePreview();
    });
  });

  function updatePreview() {
    var isDark = currentMode === 'dark';
    var p = isDark ? darkPrimary.value : primary.value;
    var s = isDark ? darkSecondary.value : secondary.value;
    var r = radius.value + 'px', rSm = Math.max(0, radius.value - 4) + 'px';
    var pText = autoText(p), sText = autoText(s);
    var ff = fontMap[font.value] || fontMap['system-ui'];
    var bgColor = isDark ? '#0f172a' : '#f8f7f9';
    var cardBg = isDark ? '#1e293b' : '#ffffff';
    var borderColor = isDark ? '#334155' : '#e2e8f0';
    var textColor = isDark ? '#e2e8f0' : '#1e293b';

    // Hex labels
    document.getElementById('themePrimaryHex').textContent = primary.value;
    document.getElementById('themeSecondaryHex').textContent = secondary.value;
    if (darkPrimary) document.getElementById('themeDarkPrimaryHex').textContent = darkPrimary.value;
    if (darkSecondary) document.getElementById('themeDarkSecondaryHex').textContent = darkSecondary.value;
    document.getElementById('themeRadiusValue').textContent = radius.value + 'px';

    // Preview container
    var prev = document.getElementById('themePreview');
    prev.style.background = bgColor;
    prev.style.borderColor = borderColor;
    prev.style.color = textColor;
    prev.style.borderRadius = r;

    // Preview buttons
    var bp = document.getElementById('prevBtnPrimary');
    bp.style.background = p; bp.style.color = pText; bp.style.borderRadius = r; bp.style.fontFamily = ff;
    var bs = document.getElementById('prevBtnSecondary');
    bs.style.background = s; bs.style.color = sText; bs.style.borderRadius = r; bs.style.fontFamily = ff;
    var bo = document.getElementById('prevBtnOutline');
    bo.style.color = p; bo.style.borderColor = p; bo.style.borderRadius = r; bo.style.fontFamily = ff;

    // Preview cards
    ['prevCard','prevCard2'].forEach(function(id) {
      var c = document.getElementById(id);
      if(c) { c.style.borderRadius = r; c.style.background = cardBg; c.style.borderColor = borderColor; c.style.color = textColor; }
    });
    var pi = document.getElementById('prevInput');
    if(pi) { pi.style.borderRadius = rSm; pi.style.background = isDark ? '#0f172a' : '#fff'; pi.style.borderColor = borderColor; pi.style.color = textColor; }
    document.getElementById('prevCardTitle').style.fontFamily = ff;
    document.getElementById('prevCardTitle').style.color = textColor;
    document.getElementById('prevCardText').style.fontFamily = ff;

    // Preview switch
    var sw = document.getElementById('prevSwitch');
    if (sw) { sw.style.backgroundColor = p; sw.style.borderColor = p; }

    // Live update CSS variables on page (always light for the admin)
    var root = document.documentElement.style;
    root.setProperty('--primary', primary.value);
    root.setProperty('--primary-hover', darken(primary.value, 0.15));
    root.setProperty('--primary-text', autoText(primary.value));
    root.setProperty('--primary-light', lighten(primary.value, 0.85));
    root.setProperty('--secondary', secondary.value);
    root.setProperty('--secondary-hover', darken(secondary.value, 0.15));
    root.setProperty('--secondary-text', autoText(secondary.value));
    root.setProperty('--secondary-light', lighten(secondary.value, 0.85));
    root.setProperty('--radius', r);
    root.setProperty('--radius-sm', rSm);
    root.setProperty('--radius-lg', (parseInt(radius.value) > 0 ? parseInt(radius.value) + 4 : 0) + 'px');
    root.setProperty('--font-family', ff);
  }

  [primary, secondary, darkPrimary, darkSecondary].forEach(function(el) { if(el) el.addEventListener('input', updatePreview); });
  radius.addEventListener('input', updatePreview);
  font.addEventListener('change', updatePreview);
  updatePreview();
})();

// ─── Flash info color preview ───
(function(){
  var fbg = document.getElementById('flashBgColor');
  var ftxt = document.getElementById('flashTextColor');
  var prev = document.getElementById('flashPreview');
  if (!fbg || !ftxt || !prev) return;
  function up() {
    prev.style.background = fbg.value;
    prev.style.color = ftxt.value;
    document.getElementById('flashBgHex').textContent = fbg.value;
    document.getElementById('flashTextHex').textContent = ftxt.value;
  }
  fbg.addEventListener('input', up);
  ftxt.addEventListener('input', up);
})();

// ─── Galerie parcours ───
document.addEventListener('DOMContentLoaded', function() {
  var maxImages = 30;
  var csrfVal = document.querySelector('input[name="csrf_token"]')?.value || '';
  var countSpan = document.getElementById('remainingCount');
  var galerieEl = document.getElementById('galerieContainer');
  var galEmpty = document.getElementById('galEmpty');
  var galDeleteAllWrap = document.getElementById('galDeleteAllWrap');
  var uploadZone = document.getElementById('galUploadZone');
  var fileInput = document.getElementById('galFileInput');

  function getRemaining() { return parseInt(countSpan?.textContent || '0'); }
  function setRemaining(val) { if (countSpan) countSpan.textContent = val; }

  function galAddCard(filename) {
    var card = document.createElement('div');
    card.className = 'sortable-image-item';
    card.dataset.filename = filename;
    card.style.cssText = 'position:relative;border-radius:8px;overflow:hidden;aspect-ratio:1;background:#f1f5f9;cursor:grab';
    card.innerHTML =
      '<img src="../files/_parcours/' + encodeURIComponent(filename) + '" style="width:100%;height:100%;object-fit:cover;display:block" loading="lazy">' +
      '<div style="position:absolute;top:6px;right:6px">' +
        '<button type="button" class="delete-btn btn btn-sm btn-danger" data-filename="' + filename + '" title="Supprimer" style="width:28px;height:28px;padding:0;border-radius:6px;display:flex;align-items:center;justify-content:center;opacity:0.85"><i class="bi bi-trash3" style="font-size:12px"></i></button>' +
      '</div>';
    galerieEl.appendChild(card);
  }

  function updateEmpty() {
    var count = galerieEl ? galerieEl.querySelectorAll('.sortable-image-item').length : 0;
    if (galEmpty) galEmpty.style.display = count === 0 ? 'block' : 'none';
    if (galDeleteAllWrap) galDeleteAllWrap.style.display = count === 0 ? 'none' : '';
  }

  // Upload zone interactions
  if (uploadZone && fileInput) {
    uploadZone.addEventListener('click', function() { fileInput.click(); });
    uploadZone.addEventListener('dragover', function(e) {
      e.preventDefault();
      this.style.borderColor = '#2563eb';
      this.style.background = '#dbeafe';
    });
    uploadZone.addEventListener('dragleave', function(e) {
      e.preventDefault();
      this.style.borderColor = '#93c5fd';
      this.style.background = '#eff6ff';
    });
    uploadZone.addEventListener('drop', function(e) {
      e.preventDefault();
      this.style.borderColor = '#93c5fd';
      this.style.background = '#eff6ff';
      if (e.dataTransfer.files.length) galUploadFiles(e.dataTransfer.files);
    });
    fileInput.addEventListener('change', function() {
      if (this.files.length) { galUploadFiles(this.files); this.value = ''; }
    });
  }

  function galUploadFiles(files) {
    var remaining = getRemaining();
    var fileList = Array.from(files);
    if (fileList.length > remaining) {
      alert('Vous ne pouvez importer que ' + remaining + ' image(s) supplementaires.');
      fileList = fileList.slice(0, remaining);
    }
    if (fileList.length === 0) return;

    var progressWrap = document.getElementById('galProgressWrap');
    var progressBar = document.getElementById('galProgressBar');
    var progressLabel = document.getElementById('galProgressLabel');
    var progressPercent = document.getElementById('galProgressPercent');
    var progressDetail = document.getElementById('galProgressDetail');

    progressWrap.style.display = 'block';
    progressBar.style.width = '0%';
    progressPercent.textContent = '0%';
    progressLabel.textContent = 'Upload en cours...';

    var total = fileList.length;
    var done = 0;
    var batchSize = 3;
    var queue = fileList.slice();

    progressDetail.textContent = '0 / ' + total + ' photos';

    function uploadNext() {
      if (queue.length === 0) {
        if (done >= total) {
          progressLabel.textContent = 'Upload termine !';
          progressDetail.textContent = done + ' / ' + total + ' photos';
          setTimeout(function() { progressWrap.style.display = 'none'; }, 2000);
        }
        return;
      }

      var batch = queue.splice(0, batchSize);
      var form = new FormData();
      form.append('uploadGalerie', '1');
      form.append('csrf_token', csrfVal);
      batch.forEach(function(file) { form.append('galerieImages[]', file); });

      var xhr = new XMLHttpRequest();
      xhr.open('POST', window.location.pathname);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

      xhr.upload.addEventListener('progress', function(e) {
        if (e.lengthComputable) {
          var batchProgress = e.loaded / e.total;
          var overallProgress = ((done + batchProgress * batch.length) / total) * 100;
          progressBar.style.width = Math.round(overallProgress) + '%';
          progressPercent.textContent = Math.round(overallProgress) + '%';
        }
      });

      xhr.addEventListener('load', function() {
        try {
          var resp = JSON.parse(xhr.responseText);
          if (resp.uploaded && resp.uploaded.length) {
            resp.uploaded.forEach(function(filename) {
              galAddCard(filename);
            });
            done += resp.uploaded.length;
            setRemaining(getRemaining() - resp.uploaded.length);
          } else {
            done += batch.length;
          }
        } catch(e) {
          done += batch.length;
        }
        progressDetail.textContent = done + ' / ' + total + ' photos';
        var pct = Math.round((done / total) * 100);
        progressBar.style.width = pct + '%';
        progressPercent.textContent = pct + '%';
        updateEmpty();
        uploadNext();
      });

      xhr.addEventListener('error', function() {
        done += batch.length;
        progressDetail.textContent = done + ' / ' + total + ' photos (erreur reseau)';
        uploadNext();
      });

      xhr.send(form);
    }

    uploadNext();
  }

  // Drag & drop reordering
  if (galerieEl && typeof Sortable !== 'undefined') {
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
          body: 'reorder_gallery=1&filenames=' + JSON.stringify(filenames) + '&csrf_token=' + encodeURIComponent(csrfVal)
        });
      }
    });
  }

  // Suppression dynamique (delegation)
  if (galerieEl) {
    galerieEl.addEventListener('click', function(e) {
      var btn = e.target.closest('.delete-btn');
      if (!btn) return;
      if (!confirm('Supprimer cette photo ?')) return;
      var imageName = btn.dataset.filename;
      var card = btn.closest('.sortable-image-item');

      fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ deleteImage: imageName, csrf_token: csrfVal })
      })
      .then(function(r) { return r.text(); })
      .then(function(result) {
        if (result.trim() === 'OK') {
          card.remove();
          setRemaining(getRemaining() + 1);
          updateEmpty();
        } else {
          alert('Erreur : ' + result);
        }
      });
    });
  }

  // Delete all
  var galDeleteAllBtn = document.getElementById('galDeleteAll');
  if (galDeleteAllBtn) {
    galDeleteAllBtn.addEventListener('click', function() {
      if (!confirm('Supprimer definitivement TOUTES les photos de la galerie ?')) return;
      var items = galerieEl.querySelectorAll('.sortable-image-item');
      var filenames = [];
      items.forEach(function(item) { filenames.push(item.dataset.filename); });

      var deleted = 0;
      var total = filenames.length;

      filenames.forEach(function(fn) {
        fetch('', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ deleteImage: fn, csrf_token: csrfVal })
        })
        .then(function(r) { return r.text(); })
        .then(function() {
          deleted++;
          if (deleted >= total) {
            galerieEl.innerHTML = '';
            setRemaining(maxImages);
            updateEmpty();
          }
        });
      });
    });
  }

  updateEmpty();
});
</script>

