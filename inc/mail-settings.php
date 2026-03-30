<?php
require '../config/config.php';
require_once '../config/csrf.php';
require 'navbar-data.php';
requireRole(['admin']);

// ── Load settings ──
$stmt = $pdo->prepare('SELECT * FROM setting WHERE id = 1 LIMIT 1');
$stmt->execute();
$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Google / Email vars
$client_id     = decrypt($data['client_id'] ?? '');
$client_secret = decrypt($data['client_secret'] ?? '');
$hasMailFields = false;
try { $pdo->query("SELECT mail_email FROM setting LIMIT 0"); $hasMailFields = true; } catch (PDOException $e) {}
$mail_email = $data['mail_email'] ?? '';
$mail_phone = $data['mail_phone'] ?? '';
$qrcode_mail_mode = $data['qrcode_mail_mode'] ?? 'none';

// OAuth — chargement lazy comme setting.php
$isConnected = false;
$authUrl = '#';
try {
    require_once '../config/googleMail.php';
    $isConnected = isGoogleConnectionValid();
    $authUrl = getGoogleAuthUrl('mail-settings.php');
} catch (\Throwable $e) {
    // Google OAuth not configured or error
}

// Mail template config
$mtcRaw = $data['mail_template_config'] ?? null;
$mtc = $mtcRaw ? json_decode($mtcRaw, true) : [];
$mtcColors = ($mtc['colors'] ?? []) + [
    'bg'=>'#f1f5f9','card_bg'=>'#ffffff','header_bg1'=>'#F42182','header_bg2'=>'#db2777',
    'accent'=>'#F42182','title_bg'=>'#fdf2f8','tips_bg'=>'#0f172a',
    'banner_bg1'=>'#fdf2f8','banner_bg2'=>'#fce7f3','banner_border'=>'#fbcfe8','footer_bg'=>'#0f172a'
];
$mtcTexts = ($mtc['texts'] ?? []) + [
    'header_title'=>'Forbach en Rose','header_subtitle'=>'Course caritative contre le cancer du sein',
    'banner_title'=>'Ensemble contre le cancer du sein',
    'banner_text'=>'Merci de votre participation et de votre engagement pour cette belle cause.',
    'footer_title'=>'Forbach en Rose','footer_subtitle'=>'Course caritative contre le cancer du sein',
    'label_participant'=>'Participant','label_date'=>"Date de l'événement",'label_lieu'=>'Lieu de départ',
    'value_lieu'=>'Piscine de Forbach, Moselle','qrcode_title'=>'Votre QR Code','qrcode_subtitle'=>'Présentez-le le jour J',
    'tips_title'=>'Conseils pour le jour J','tips_1'=>'Arrivez 30 min avant',
    'tips_2'=>'Portez du rose','tips_3'=>'Chaussures confortables',
    'contact_title'=>'Une question ?'
];
$mtcFont      = $mtc['font'] ?? 'system';
$mtcOrder     = $mtc['section_order'] ?? ['details','tips','description','qrcode','banner','contact'];
$mtcHeaderImg = $mtc['header_image'] ?? '';
$mtcRadius    = ($mtc['radius'] ?? []) + ['card'=>16,'section'=>12,'badge'=>20];
$mtcCardWidth = $mtc['card_width'] ?? 600;
$mtcHeaderImgSize = $mtc['header_image_size'] ?? 80;

// ── POST handlers ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {

    // Save template
    if (isset($_POST['save_mail_template'])) {
        $headerImage = $_POST['mtc_header_image_current'] ?? '';
        if (!empty($_FILES['mtc_header_image_file']['name']) && $_FILES['mtc_header_image_file']['error'] === UPLOAD_ERR_OK) {
            $imgFile = $_FILES['mtc_header_image_file'];
            $allowedImg = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml'];
            $imgExt = strtolower(pathinfo($imgFile['name'], PATHINFO_EXTENSION));
            $imgMime = (new finfo(FILEINFO_MIME_TYPE))->file($imgFile['tmp_name']);
            if (isset($allowedImg[$imgExt]) && $allowedImg[$imgExt] === $imgMime && $imgFile['size'] <= 5*1024*1024) {
                $imgDir = __DIR__ . '/../files/_imagemail/';
                if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
                $imgName = 'header_' . uniqid() . '.' . $imgExt;
                if (move_uploaded_file($imgFile['tmp_name'], $imgDir . $imgName)) {
                    $headerImage = '../files/_imagemail/' . $imgName;
                }
            }
        }
        if (!empty($_POST['mtc_header_image_delete'])) $headerImage = '';

        $tplConfig = [
            'colors' => [
                'bg'           => $_POST['mtc_bg']           ?? '#f1f5f9',
                'card_bg'      => $_POST['mtc_card_bg']      ?? '#ffffff',
                'header_bg1'   => $_POST['mtc_header_bg1']   ?? '#F42182',
                'header_bg2'   => $_POST['mtc_header_bg2']   ?? '#db2777',
                'accent'       => $_POST['mtc_accent']       ?? '#F42182',
                'title_bg'     => $_POST['mtc_title_bg']     ?? '#fdf2f8',
                'tips_bg'      => $_POST['mtc_tips_bg']      ?? '#0f172a',
                'banner_bg1'   => $_POST['mtc_banner_bg1']   ?? '#fdf2f8',
                'banner_bg2'   => $_POST['mtc_banner_bg2']   ?? '#fce7f3',
                'banner_border'=> $_POST['mtc_banner_border'] ?? '#fbcfe8',
                'footer_bg'    => $_POST['mtc_footer_bg']    ?? '#0f172a',
            ],
            'texts' => [
                'header_title'    => $_POST['mtc_header_title']    ?? 'Forbach en Rose',
                'header_subtitle' => $_POST['mtc_header_subtitle'] ?? '',
                'banner_title'    => $_POST['mtc_banner_title']    ?? '',
                'banner_text'     => $_POST['mtc_banner_text']     ?? '',
                'footer_title'    => $_POST['mtc_footer_title']    ?? 'Forbach en Rose',
                'footer_subtitle' => $_POST['mtc_footer_subtitle'] ?? '',
                'label_participant' => $_POST['mtc_label_participant'] ?? 'Participant',
                'label_date'      => $_POST['mtc_label_date']      ?? "Date de l'événement",
                'label_lieu'      => $_POST['mtc_label_lieu']      ?? 'Lieu de départ',
                'value_lieu'      => $_POST['mtc_value_lieu']      ?? 'Piscine de Forbach',
                'qrcode_title'    => $_POST['mtc_qrcode_title']    ?? 'Votre QR Code',
                'qrcode_subtitle' => $_POST['mtc_qrcode_subtitle'] ?? 'Présentez-le le jour J',
                'tips_title'      => $_POST['mtc_tips_title']      ?? 'Conseils pour le jour J',
                'tips_1'          => $_POST['mtc_tips_1']          ?? 'Arrivez 30 min avant',
                'tips_2'          => $_POST['mtc_tips_2']          ?? 'Portez du rose',
                'tips_3'          => $_POST['mtc_tips_3']          ?? 'Chaussures confortables',
                'contact_title'   => $_POST['mtc_contact_title']   ?? 'Une question ?',
            ],
            'font'          => $_POST['mtc_font'] ?? 'system',
            'section_order' => array_filter(explode(',', $_POST['mtc_section_order'] ?? 'details,tips,description,qrcode,banner,contact')),
            'header_image'  => $headerImage,
            'radius' => [
                'card'    => (int)($_POST['mtc_radius_card']    ?? 16),
                'section' => (int)($_POST['mtc_radius_section'] ?? 12),
                'badge'   => (int)($_POST['mtc_radius_badge']   ?? 20),
            ],
            'card_width' => (int)($_POST['mtc_card_width'] ?? 600),
            'header_image_size' => (int)($_POST['mtc_header_image_size'] ?? 80),
        ];

        $json = json_encode($tplConfig, JSON_UNESCAPED_UNICODE);
        $pdo->prepare('UPDATE setting SET mail_template_config = :cfg WHERE id = 1')->execute(['cfg' => $json]);
        $data['mail_template_config'] = $json;

        // Reload
        $mtc = $tplConfig;
        $mtcColors = $mtc['colors']; $mtcTexts = $mtc['texts'];
        $mtcFont = $mtc['font']; $mtcOrder = $mtc['section_order'];
        $mtcHeaderImg = $mtc['header_image']; $mtcRadius = $mtc['radius'];
        $mtcCardWidth = $mtc['card_width'];
        $mtcHeaderImgSize = $mtc['header_image_size'];

        addToast('success', 'Template email enregistré !');
    }

    // Save Google config
    if (isset($_POST['google'])) {
        $enc_id = encrypt($_POST['client_id'] ?? '');
        $enc_secret = encrypt($_POST['client_secret'] ?? '');
        $newEmail = trim($_POST['mail_email'] ?? '');
        $newPhone = trim($_POST['mail_phone'] ?? '');
        if ($hasMailFields) {
            $pdo->prepare('UPDATE setting SET client_id=:ci, client_secret=:cs, mail_email=:me, mail_phone=:mp WHERE id=1')
                ->execute(['ci'=>$enc_id,'cs'=>$enc_secret,'me'=>$newEmail?:null,'mp'=>$newPhone?:null]);
            $mail_email = $newEmail; $mail_phone = $newPhone;
        } else {
            $pdo->prepare('UPDATE setting SET client_id=:ci, client_secret=:cs WHERE id=1')
                ->execute(['ci'=>$enc_id,'cs'=>$enc_secret]);
        }
        $client_id = decrypt($enc_id); $client_secret = decrypt($enc_secret);
        addToast('success', 'Configuration Google enregistrée !');
    }

    // QR Code config
    if (isset($_POST['save_qrcode_config'])) {
        $mode = $_POST['qrcode_mail_mode'] ?? 'none';
        if (!in_array($mode, ['none','all','first_x'], true)) $mode = 'none';
        $pdo->prepare('UPDATE setting SET qrcode_mail_mode = :m WHERE id = 1')->execute(['m'=>$mode]);
        $qrcode_mail_mode = $mode;
        addToast('success', 'Configuration QR Code enregistrée');
    }

    // Gmail actions
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        if ($action === 'test_connection') {
            try {
                if (isGoogleConnectionValid()) addToast('success', 'Connexion Google OK !');
                else addToast('danger', 'Connexion Google non valide');
            } catch (\Throwable $e) { addToast('danger', 'Erreur connexion Google'); }
        } elseif ($action === 'send_test_mail') {
            try {
                $email = $_SESSION['email'] ?? '';
                if ($email && isGoogleConnectionValid()) {
                    $result = sendMail($email, 'Mail de test - Forbach en Rose', 'Test réussi !', 'Ce mail de test confirme que la configuration email fonctionne correctement.', null, null, 'info');
                    if ($result) addToast('success', 'Mail test envoyé à ' . htmlspecialchars($email));
                    else addToast('danger', 'Échec envoi mail test');
                } else { addToast('danger', 'Email introuvable ou connexion invalide'); }
            } catch (\Throwable $e) { addToast('danger', 'Échec envoi mail test'); }
        } elseif ($action === 'disconnect') {
            try {
                revokeGoogleConnection();
                $isConnected = false;
                addToast('success', 'Déconnecté de Gmail');
            } catch (\Throwable $e) { addToast('danger', 'Erreur déconnexion'); }
        }
    }
}

// ── AJAX Preview handler ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_type']) && csrf_verify()) {
    header('Content-Type: text/html; charset=utf-8');
    $previewType = $_POST['preview_type'];
    $mtcRaw2 = $data['mail_template_config'] ?? null;
    $mtcPreview = $mtcRaw2 ? json_decode($mtcRaw2, true) : [];

    // Test data
    $testFirstname = 'Jean';
    $testLastname = 'DUPONT';
    $testDate = 'Dimanche 5 octobre 2025 — 10h00';
    $testEmail = $data['mail_email'] ?? 'contact@forbachenrose.fr';
    $testPhone = $data['mail_phone'] ?? '';

    // QR code: check real settings
    $qrMode = $data['qrcode_mail_mode'] ?? 'none';
    $qrDataUri = '';
    if ($qrMode !== 'none' && function_exists('generateQrCodeDataUri')) {
        try { $qrDataUri = generateQrCodeDataUri(42); } catch (\Throwable $e) {}
    }

    $vars = [
        'instagram'      => $data['link_instagram'] ?? '',
        'facebook'       => $data['link_facebook'] ?? '',
        'cancer'         => $data['link_cancer'] ?? '',
        'mail_email'     => $testEmail,
        'mail_phone'     => $testPhone,
        'mtc'            => $mtcPreview,
    ];

    switch ($previewType) {
        case 'inscription':
            $vars += [
                'type' => 'inscription',
                'mailTitle' => 'Inscription confirmée',
                'description' => null,
                'firstname' => $testFirstname,
                'lastname' => $testLastname,
                'date' => $testDate,
                'qrcode' => $qrDataUri,
                'inscription_no' => 42,
            ];
            break;
        case 'code':
            $vars += [
                'type' => 'info',
                'mailTitle' => 'Code de verification',
                'description' => '<p>Votre code de verification est :</p><p style="font-size:32px;font-weight:700;letter-spacing:8px;text-align:center;color:#F42182;margin:20px 0">847293</p><p>Ce code est valable 15 minutes.</p><p>Si vous n\'avez pas demande cette connexion, ignorez ce message.</p>',
                'firstname' => null, 'lastname' => null, 'date' => null,
                'qrcode' => '', 'inscription_no' => null,
            ];
            break;
        case 'new_user':
            $vars += [
                'type' => 'info',
                'mailTitle' => 'Bienvenue sur Forbach en Rose',
                'description' => '<p>Votre compte a été créé.</p>'
                    . '<p><strong>Email :</strong> exemple@email.com</p>'
                    . '<p><strong>Mot de passe temporaire :</strong> Tp#x9Kw2m!</p>'
                    . '<p>Vous devrez changer votre mot de passe lors de votre première connexion.</p>',
                'firstname' => null, 'lastname' => null, 'date' => null,
                'qrcode' => '', 'inscription_no' => null,
            ];
            break;
        case 'bulk':
            $vars += [
                'type' => 'info',
                'mailTitle' => 'Rappel — Course Forbach en Rose',
                'description' => '<p>Bonjour,</p><p>Nous vous rappelons que la course <strong>Forbach en Rose</strong> aura lieu ce dimanche.</p><p>N\'oubliez pas de venir en rose !</p><p>À très bientôt,<br>L\'équipe Forbach en Rose</p>',
                'firstname' => null, 'lastname' => null, 'date' => null,
                'qrcode' => '', 'inscription_no' => null,
            ];
            break;
        case 'test':
        default:
            $vars += [
                'type' => 'info',
                'mailTitle' => 'Test réussi !',
                'description' => 'Ce mail de test confirme que la configuration email fonctionne correctement.',
                'firstname' => null, 'lastname' => null, 'date' => null,
                'qrcode' => '', 'inscription_no' => null,
            ];
            break;
    }

    echo render(__DIR__ . '/../config/mail_template.php', $vars);
    exit;
}

$activeSubTab = $_POST['active_subtab'] ?? ($_GET['tab'] ?? 'template');

// Build full config JSON for JS
$jsConfig = json_encode([
    'colors' => $mtcColors,
    'texts' => $mtcTexts,
    'font' => $mtcFont,
    'order' => $mtcOrder,
    'headerImage' => $mtcHeaderImg,
    'radius' => $mtcRadius,
    'cardWidth' => $mtcCardWidth,
    'headerImageSize' => $mtcHeaderImgSize,
], JSON_UNESCAPED_UNICODE);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Paramètres mail</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Roboto:wght@400;500;700&family=Open+Sans:wght@400;600;700&family=Lato:wght@400;700&family=Montserrat:wght@400;600;700;900&display=swap" rel="stylesheet">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
</head>
<body>
<?php require 'navbar-admin.php'; ?>
<link href="../css/gmail-settings.css" rel="stylesheet">

<style>
/* ── Setting card (same as setting.php) ── */
.setting-card{background:#fff;border:1px solid #f0e8eb;border-radius:12px;padding:24px}
.setting-card h2{font-size:18px;font-weight:700;color:#1e293b;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #f0e8eb}

/* ── Kill oc-content padding for editor pane only ── */
#oc-content{overflow:hidden !important;border-radius:0 !important;width:100% !important;max-width:100% !important}
#oc-app-container{border-radius:0 !important;margin:0 !important;height:calc(100vh - var(--oc-topbar-h,52px)) !important}
.ed-pane,.ed-pane-google{width:100%}
.ed-wrap{width:100%}

/* ── Layout ── */
.ed-wrap{position:relative;height:calc(100vh - var(--oc-topbar-h,52px) - 42px - 80px);margin:8px 12px 20px;border-radius:12px;overflow:hidden}
.ed-sidebar{
  position:absolute;top:8px;left:8px;bottom:8px;z-index:20;
  width:300px;background:#fff;
  font-size:13px;overflow:hidden;
  border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.15);
}
.ed-sidebar #sidebar{padding:16px}
.ed-preview-area{
  position:absolute;top:0;left:0;right:0;bottom:0;
  background:#94a3b8;overflow-y:auto;padding:32px 32px 32px 320px;
  display:flex;justify-content:center;align-items:flex-start;
  border-radius:12px;
}
/* Custom scrollbar */
.ed-preview-area::-webkit-scrollbar{width:8px}
.ed-preview-area::-webkit-scrollbar-track{background:transparent;margin:12px 0}
.ed-preview-area::-webkit-scrollbar-thumb{background:rgba(255,255,255,.3);border-radius:4px}
.ed-preview-area::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.5)}
.ed-sidebar #sidebar::-webkit-scrollbar{width:5px}
.ed-sidebar #sidebar::-webkit-scrollbar-track{background:transparent}
.ed-sidebar #sidebar::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:3px}
.ed-sidebar #sidebar::-webkit-scrollbar-thumb:hover{background:#cbd5e1}
/* Sidebar tabs */
.sb-tabs{display:flex;border-bottom:2px solid #f0e8eb;flex-shrink:0}
.sb-tab{
  flex:1;padding:10px 0;font-size:12px;font-weight:600;color:#94a3b8;
  background:transparent;border:none;border-bottom:2px solid transparent;
  margin-bottom:-2px;cursor:pointer;transition:.15s;
}
.sb-tab:hover{color:#475569}
.sb-tab.active{color:#F42182;border-bottom-color:#F42182}
/* Preview buttons */
.preview-btn{
  padding:10px 14px;font-size:12px;border:1px solid #e2e8f0;text-align:left;
  background:#fff;color:#475569;border-radius:8px;cursor:pointer;transition:.15s;display:block;
}
.preview-btn:hover{border-color:#F42182;background:#fdf2f8}
.preview-btn.loading{opacity:.6;pointer-events:none}

.ed-pane{display:none}.ed-pane.active{display:flex}
.ed-pane-google{display:none;padding:28px 32px;overflow-y:auto;height:calc(100vh - var(--oc-topbar-h,52px) - 42px)}.ed-pane-google.active{display:block}

/* ── Sidebar controls ── */
.sb-title{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;font-weight:700;margin:16px 0 8px;padding-top:8px;border-top:1px solid #f1f5f9}
.sb-title:first-child{margin-top:0;border:0;padding:0}
.sb-row{display:flex;align-items:center;gap:8px;margin-bottom:6px}
.sb-row label{flex:1;color:#475569;font-size:12px;white-space:nowrap}
.sb-row input[type="color"]{width:28px;height:28px;border:1px solid #e2e8f0;border-radius:6px;padding:1px;cursor:pointer;flex-shrink:0}
.sb-row select,.sb-row input[type="range"]{flex:1}
.sb-row .v{font-size:11px;color:#94a3b8;min-width:28px;text-align:right}
.sb-hint{font-size:11px;color:#94a3b8;margin-bottom:8px}
.sb-btn{padding:6px 12px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;font-size:12px;cursor:pointer;transition:.15s}
.sb-btn:hover{border-color:#F42182;color:#F42182}
.sb-btn-danger{color:#ef4444;border-color:#fca5a5}
.sb-btn-danger:hover{background:#fef2f2;border-color:#ef4444}
.sb-align{display:flex;gap:4px}
.sb-align button{flex:1;padding:4px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;font-size:14px}
.sb-align button.active{background:#fce7f3;border-color:#F42182;color:#F42182}

/* ── Preview ── */
.prev-email{width:<?= $mtcCardWidth ?>px;max-width:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}

/* Sections */
.prev-sec{
  position:relative;cursor:pointer;transition:.12s;
  outline:2px solid transparent;outline-offset:2px;border-radius:4px;
}
.prev-sec:hover{outline-color:rgba(244,33,130,.35)}
.prev-sec.selected{outline-color:#F42182;outline-offset:3px}
/* Header/Title/Footer: inside card table, outline gets clipped → use inset box-shadow */
#prevCard>.prev-sec,#prevCard tr>td.prev-sec{outline:none !important}
#prevCard tr>td.prev-sec:hover{box-shadow:inset 0 0 0 3px rgba(244,33,130,.35)}
#prevCard tr>td.prev-sec.selected{box-shadow:inset 0 0 0 3px #F42182}
.prev-sec .sec-actions{
  display:none;position:absolute;top:-14px;right:-8px;z-index:10;
  background:#fff;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.15);
  padding:2px;gap:2px;
}
.prev-sec.selected .sec-actions,.prev-sec:hover .sec-actions{display:flex}
.sec-act{
  width:26px;height:26px;display:flex;align-items:center;justify-content:center;
  border:0;background:transparent;border-radius:6px;cursor:pointer;font-size:13px;transition:.1s;
}
.sec-act:hover{background:#f1f5f9}
.sec-act.del:hover{background:#fef2f2;color:#ef4444}

/* Add section button between sections */
.prev-add{
  display:flex;align-items:center;justify-content:center;
  height:20px;position:relative;z-index:5;margin:-4px 0;
}
.prev-add button{
  width:28px;height:28px;border-radius:50%;border:2px dashed #cbd5e1;
  background:#fff;color:#94a3b8;font-size:18px;cursor:pointer;transition:.15s;
  display:flex;align-items:center;justify-content:center;opacity:0;
}
.prev-add:hover button,.prev-add button:focus{opacity:1;border-color:#F42182;color:#F42182}

/* Editable text */
[data-ed]{position:relative;transition:.12s;outline:1px dashed transparent;outline-offset:1px;cursor:pointer;min-height:1em}
[data-ed]:hover{outline-color:rgba(244,33,130,.4)}
[data-ed].editing{outline-color:#F42182;outline-style:solid;cursor:text;background:rgba(244,33,130,.05)}
/* Element inline actions (trash + arrows) */
.el-actions{
  position:absolute;top:-10px;right:4px;z-index:10;
  display:none;gap:2px;align-items:center;
  background:#fff;border-radius:10px;padding:1px 3px;
  box-shadow:0 1px 6px rgba(0,0,0,.15);
}
.el-act{
  width:18px;height:18px;border-radius:50%;border:0;
  background:transparent;color:#94a3b8;font-size:10px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  transition:.1s;line-height:1;padding:0;
}
.el-act:hover{background:#f1f5f9;color:#0f172a}
.el-act.del:hover{background:#fef2f2;color:#ef4444}
[data-ed]:hover>.el-actions,[data-dyn]:hover>.el-actions,.el-reorder:hover>.el-actions{display:flex}
[data-ed].editing>.el-actions{display:none !important}

/* Reorderable non-ed/non-dyn elements (QR placeholder, etc.) */
.el-reorder{position:relative}
.el-reorder:hover{outline:1px dashed rgba(100,116,139,.3);outline-offset:2px;border-radius:4px}
/* Inner element dragging */
[data-ed][draggable="true"],[data-dyn][draggable="true"],.el-reorder[draggable="true"]{cursor:grab}
.el-dragging{opacity:.3 !important}
.el-drop-above{border-top:2px solid #F42182 !important}
.el-drop-below{border-bottom:2px solid #F42182 !important}

/* Dynamic text (content non-editable, but style IS editable) */
[data-dyn]{position:relative;cursor:pointer;transition:.12s;outline:1px dashed transparent;outline-offset:1px}
[data-dyn]:hover{outline-color:rgba(100,116,139,.4)}
[data-dyn]::after{
  content:"🔒 " attr(data-dyn);font-size:9px;background:#64748b;color:#fff;padding:1px 5px;
  border-radius:3px;position:absolute;top:-10px;right:0;white-space:nowrap;pointer-events:none;
  opacity:0;transition:.15s;
}
[data-dyn]:hover::after{opacity:1}

/* Fixed section (non-deletable) */
.prev-sec[data-fixed] .sec-act.del{display:none !important}

/* Drag indicator */
.drag-bar{
  position:absolute;left:50%;top:-12px;transform:translateX(-50%);
  width:40px;height:5px;background:#F42182;border-radius:3px;opacity:0;transition:.15s;cursor:grab;
}
.prev-sec:hover .drag-bar,.prev-sec.selected .drag-bar{opacity:.5}
.prev-sec:hover .drag-bar:hover{opacity:1}

@media(max-width:900px){
  .ed-wrap{flex-direction:column;height:auto}
  .ed-sidebar{width:100%;min-width:0;max-height:40vh;border-right:0;border-bottom:1px solid #e2e8f0}
  .ed-preview-area{padding:16px}
  .prev-email{width:100%}
}
</style>

<!-- ═══ TABS ═══ -->
<ul class="nav settings-tabs" id="mailSettingsTabs">
  <li class="nav-item"><a class="nav-link <?= $activeSubTab==='template'?'active':'' ?>" href="#" data-pane="paneTemplate">Template email</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeSubTab==='google'?'active':'' ?>" href="#" data-pane="paneGoogle">Google / Email</a></li>
</ul>

<!-- ═══ TEMPLATE PANE ═══ -->
<div class="ed-pane <?= $activeSubTab==='template'?'active':'' ?>" id="paneTemplate">
<div class="ed-wrap">

  <!-- ── Sidebar: contextual controls ── -->
  <form action="" method="post" enctype="multipart/form-data" id="tplForm" class="ed-sidebar" style="display:flex;flex-direction:column">
    <?= csrf_field() ?>
    <input type="hidden" name="active_subtab" value="template">
    <input type="hidden" name="mtc_section_order" id="hOrder" value="<?= htmlspecialchars(implode(',', $mtcOrder)) ?>">
    <input type="hidden" name="mtc_header_image_current" id="hHeaderImg" value="<?= htmlspecialchars($mtcHeaderImg) ?>">
    <div id="hFields"></div>
    <input type="file" name="mtc_header_image_file" id="hImgFile" accept="image/*" style="display:none">
    <input type="hidden" name="mtc_header_image_delete" id="hImgDel" value="">
    <!-- Sidebar inner tabs -->
    <div class="sb-tabs">
      <button type="button" class="sb-tab active" data-sbtab="sbEditor">Éditeur</button>
      <button type="button" class="sb-tab" data-sbtab="sbPreview">Prévisualiser</button>
    </div>
    <div id="sidebar" style="flex:1;overflow-y:auto">

    <!-- ── Tab: Editor ── -->
    <div id="sbEditor">
    <!-- Always visible: global settings -->
    <div id="sbGlobal">
      <p class="sb-title" style="border:0;margin:0">Général</p>
      <div class="sb-row"><label>Fond page</label><input type="color" data-c="bg" value="<?= $mtcColors['bg'] ?>"></div>
      <div class="sb-row"><label>Fond carte</label><input type="color" data-c="card_bg" value="<?= $mtcColors['card_bg'] ?>"></div>
      <div class="sb-row"><label>Arrondi carte</label><input type="range" data-r="card" min="0" max="32" step="2" value="<?= $mtcRadius['card'] ?>"><span class="v"><?= $mtcRadius['card'] ?></span></div>
      <div class="sb-row"><label>Arrondi sections</label><input type="range" data-r="section" min="0" max="24" step="2" value="<?= $mtcRadius['section'] ?>"><span class="v"><?= $mtcRadius['section'] ?></span></div>
      <p class="sb-title">Dimensions</p>
      <div class="sb-row"><label>Largeur carte</label><input type="range" id="sbCardWidth" min="400" max="900" step="10" value="<?= $mtcCardWidth ?>"><span class="v" id="sbCardWidthV"><?= $mtcCardWidth ?></span></div>
    </div>

    <!-- Contextual: section colors (shown below global when a section is selected) -->
    <div id="sbSection" style="display:none;margin-top:16px;padding-top:16px;border-top:2px solid #e2e8f0">
      <p class="sb-title" style="border:0;padding:0"><span id="sbSecName"></span></p>
      <div id="sbSecColors"></div>
      <div id="sbSecDeleteWrap" style="margin-top:8px">
        <button type="button" class="sb-btn sb-btn-danger" id="sbSecDelete">Supprimer cette section</button>
      </div>
    </div>

    <!-- Contextual: text formatting (shown below global when a text is clicked) -->
    <div id="sbText" style="display:none;margin-top:16px;padding-top:16px;border-top:2px solid #e2e8f0">
      <p class="sb-title" style="border:0;padding:0">Texte sélectionné</p>
      <div class="sb-row"><label>Police</label>
        <select id="sbTxtFont" class="form-select form-select-sm">
          <option value="inherit">Par défaut</option>
          <option value="Poppins">Poppins</option>
          <option value="Roboto">Roboto</option>
          <option value="Open Sans">Open Sans</option>
          <option value="Lato">Lato</option>
          <option value="Montserrat">Montserrat</option>
          <option value="Georgia,serif">Georgia</option>
        </select>
      </div>
      <p class="sb-title">Taille</p>
      <div class="sb-row">
        <input type="range" id="sbTxtSize" min="10" max="36" step="1" value="14"><span class="v" id="sbTxtSizeV">14</span>
      </div>
      <p class="sb-title">Couleur</p>
      <div class="sb-row"><label>Texte</label><input type="color" id="sbTxtColor" value="#0f172a"></div>
      <p class="sb-title">Alignement</p>
      <div class="sb-align">
        <button type="button" data-align="left" title="Gauche">&#8676;</button>
        <button type="button" data-align="center" title="Centre">&#8596;</button>
        <button type="button" data-align="right" title="Droite">&#8677;</button>
      </div>
      <div id="sbTxtDelete" style="display:none;margin-top:12px">
        <button type="button" class="sb-btn sb-btn-danger" style="width:100%" id="sbTxtDeleteBtn">Supprimer ce texte</button>
      </div>
    </div>
    </div><!-- /sbEditor -->

    <!-- ── Tab: Preview ── -->
    <div id="sbPreview" style="display:none;padding:16px">
      <p style="font-size:13px;color:#64748b;margin:0 0 16px;line-height:1.5">Prévisualisation réelle du mail tel qu'il sera envoyé, avec tous les paramètres actuels.</p>
      <button type="button" class="preview-btn" data-preview="inscription" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Inscription</span><br><span style="font-size:11px;color:#94a3b8">Confirmation avec QR code si activé</span>
      </button>
      <button type="button" class="preview-btn" data-preview="code" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Code de connexion</span><br><span style="font-size:11px;color:#94a3b8">Vérification 2FA</span>
      </button>
      <button type="button" class="preview-btn" data-preview="new_user" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Nouveau compte</span><br><span style="font-size:11px;color:#94a3b8">Mot de passe temporaire</span>
      </button>
      <button type="button" class="preview-btn" data-preview="bulk" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Envoi groupé</span><br><span style="font-size:11px;color:#94a3b8">Mail personnalisé (depuis utilisateurs)</span>
      </button>
      <button type="button" class="preview-btn" data-preview="test" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Mail test</span><br><span style="font-size:11px;color:#94a3b8">Test simple de configuration</span>
      </button>
      <button type="button" class="preview-btn" data-preview="editor" style="width:100%;margin-top:8px;background:#f1f5f9;font-weight:600">
        &#8592; Retour éditeur
      </button>
    </div>

    </div><!-- /sidebar -->
    <div style="padding:12px 16px;border-top:1px solid #e2e8f0;background:#fff;flex-shrink:0;border-radius:0 0 12px 12px;text-align:center">
      <button type="submit" name="save_mail_template" class="btn btn-primary w-auto" style="padding:8px 32px;font-size:13px;font-weight:600">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px;margin-right:3px"><path d="M19 21 H5 A2 2 0 0 1 3 19 V5 A2 2 0 0 1 5 3 H16 L21 8 V19 A2 2 0 0 1 19 21 Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Sauvegarder
      </button>
    </div>
  </form>

  <!-- ── Preview ── -->
  <div class="ed-preview-area" id="previewArea">
    <iframe id="previewIframe" style="display:none;width:100%;height:100%;border:0;background:#fff;border-radius:8px"></iframe>
    <div class="prev-email" id="prevEmail">
      <table width="100%" cellpadding="0" cellspacing="0" id="prevOuter" style="padding:24px 12px 40px">
        <tr><td align="center">
        <table width="<?= $mtcCardWidth ?>" cellpadding="0" cellspacing="0" id="prevCard" style="max-width:<?= $mtcCardWidth ?>px;width:100%;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.07)">

          <!-- Header -->
          <tr><td id="prevHeader" class="prev-sec" data-zone="header" data-fixed style="padding:40px 40px 36px;text-align:center">
            <div class="drag-bar"></div>
            <?php if(!empty($mtcHeaderImg)): ?>
              <img src="<?= htmlspecialchars($mtcHeaderImg) ?>" style="max-width:<?= $mtcHeaderImgSize ?>%;height:auto">
            <?php else: ?>
              <h1 data-ed="header_title" style="color:#fff;font-size:24px;font-weight:700;margin:0 0 6px;letter-spacing:-.02em"><?= htmlspecialchars($mtcTexts['header_title']) ?></h1>
            <?php endif; ?>
            <p data-ed="header_subtitle" style="color:rgba(255,255,255,.75);font-size:14px;margin:0"><?= htmlspecialchars($mtcTexts['header_subtitle']) ?></p>
          </td></tr>

          <!-- Title -->
          <tr><td id="prevTitleBg" class="prev-sec" data-zone="title" data-fixed style="padding:32px 40px 28px;text-align:center">
            <div class="drag-bar"></div>
            <p id="prevBadge" data-dyn="dynamique" data-acc="badge" style="display:inline-block;font-size:12px;font-weight:700;padding:5px 16px;margin:0 0 16px;text-transform:uppercase;letter-spacing:.08em;color:<?= $mtcColors['accent'] ?>;background:<?= $mtcColors['card_bg'] ?>;border-radius:<?= $mtcRadius['badge'] ?>px">Inscription confirmée</p>
            <h2 data-dyn="prénom" style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px">Bienvenue Jean !</h2>
            <p data-dyn="automatique" style="font-size:15px;color:#64748b;margin:0;line-height:1.6">Votre inscription a bien été enregistrée.</p>
          </td></tr>

          <!-- Sections -->
          <tr><td style="padding:32px 40px 36px">
            <div id="prevSections">
<?php foreach($mtcOrder as $sec): ?>

              <div class="prev-add"><button type="button" data-action="add-section" title="Ajouter une section">+</button></div>

              <div class="prev-sec" data-zone="<?= $sec ?>" data-section="<?= $sec ?>" draggable="true" <?= in_array($sec,['details','qrcode','description'])?'data-fixed':'' ?>>
                <div class="drag-bar"></div>
                <div class="sec-actions">
                  <button type="button" class="sec-act" title="Monter" data-action="move-up">&#8593;</button>
                  <button type="button" class="sec-act" title="Descendre" data-action="move-down">&#8595;</button>
                  <button type="button" class="sec-act del" title="Supprimer" data-action="remove-section">&#10005;</button>
                </div>

<?php if($sec==='details'): ?>
                <table width="100%" cellpadding="0" cellspacing="0" class="sec-r" style="overflow:hidden;margin-bottom:28px">
                  <tr><td data-acc="left" style="padding:18px 24px;background:#f8fafc;border-bottom:1px solid #f1f5f9;border-left:3px solid <?= $mtcColors['accent'] ?>">
                    <div data-acc="txt" data-ed="label_participant" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:<?= $mtcColors['accent'] ?>"><?= htmlspecialchars($mtcTexts['label_participant']) ?></div>
                    <div data-dyn="nom du participant" style="font-size:16px;color:#0f172a;font-weight:600;margin-top:4px">DUPONT Jean</div>
                  </td></tr>
                  <tr><td data-acc="left" style="padding:18px 24px;background:#f8fafc;border-bottom:1px solid #f1f5f9;border-left:3px solid <?= $mtcColors['accent'] ?>">
                    <div data-acc="txt" data-ed="label_date" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:<?= $mtcColors['accent'] ?>"><?= htmlspecialchars($mtcTexts['label_date']) ?></div>
                    <div data-dyn="date de la course" style="font-size:16px;color:#0f172a;font-weight:600;margin-top:4px">Dimanche 5 octobre 2025</div>
                  </td></tr>
                  <tr><td data-acc="left" style="padding:18px 24px;background:#f8fafc;border-left:3px solid <?= $mtcColors['accent'] ?>">
                    <div data-acc="txt" data-ed="label_lieu" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:<?= $mtcColors['accent'] ?>"><?= htmlspecialchars($mtcTexts['label_lieu']) ?></div>
                    <div data-ed="value_lieu" style="font-size:16px;color:#0f172a;font-weight:600;margin-top:4px"><?= htmlspecialchars($mtcTexts['value_lieu']) ?></div>
                  </td></tr>
                </table>
<?php elseif($sec==='tips'): ?>
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px">
                  <tr><td id="prevTips" class="sec-r" style="padding:24px">
                    <p data-ed="tips_title" style="font-size:14px;font-weight:700;color:#fff;margin:0 0 14px"><?= htmlspecialchars($mtcTexts['tips_title']) ?></p>
                    <p data-ed="tips_1" style="font-size:14px;color:rgba(255,255,255,.75);margin:0 0 4px;line-height:1.8"><span data-acc="txt" style="font-weight:700;margin-right:8px;color:<?= $mtcColors['accent'] ?>">&#9656;</span><?= htmlspecialchars($mtcTexts['tips_1']) ?></p>
                    <p data-ed="tips_2" style="font-size:14px;color:rgba(255,255,255,.75);margin:0 0 4px;line-height:1.8"><span data-acc="txt" style="font-weight:700;margin-right:8px;color:<?= $mtcColors['accent'] ?>">&#9656;</span><?= htmlspecialchars($mtcTexts['tips_2']) ?></p>
                    <p data-ed="tips_3" style="font-size:14px;color:rgba(255,255,255,.75);margin:0;line-height:1.8"><span data-acc="txt" style="font-weight:700;margin-right:8px;color:<?= $mtcColors['accent'] ?>">&#9656;</span><?= htmlspecialchars($mtcTexts['tips_3']) ?></p>
                  </td></tr>
                </table>
<?php elseif($sec==='description'): ?>
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px">
                  <tr><td class="sec-r" data-acc="left" style="padding:24px;background:#f8fafc;border-left:3px solid <?= $mtcColors['accent'] ?>">
                    <div data-dyn="contenu du mail" style="font-size:15px;line-height:1.7;color:#334155">Votre message personnalisé ici...</div>
                  </td></tr>
                </table>
<?php elseif($sec==='qrcode'): ?>
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px">
                  <tr><td class="sec-r" data-acc="border" style="padding:28px;background:#fff;text-align:center;border:2px dashed <?= $mtcColors['accent'] ?>">
                    <p data-acc="txt" data-ed="qrcode_title" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin:0 0 6px;color:<?= $mtcColors['accent'] ?>"><?= htmlspecialchars($mtcTexts['qrcode_title']) ?></p>
                    <p style="font-size:13px;color:#64748b;margin:0 0 4px"><span data-dyn="n° inscription">Billet n° 42</span></p>
                    <p data-ed="qrcode_subtitle" style="font-size:13px;color:#64748b;margin:0 0 16px"><?= htmlspecialchars($mtcTexts['qrcode_subtitle']) ?></p>
                    <div style="width:120px;height:120px;background:#f1f5f9;border-radius:8px;margin:0 auto;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:11px">QR</div>
                  </td></tr>
                </table>
<?php elseif($sec==='banner'): ?>
                <table width="100%" cellpadding="0" cellspacing="0" class="sec-r" style="overflow:hidden;margin-bottom:28px">
                  <tr><td id="prevBanner" style="padding:24px 28px;text-align:center">
                    <p data-ed="banner_title" style="font-size:15px;font-weight:700;margin:0 0 6px"><?= htmlspecialchars($mtcTexts['banner_title']) ?></p>
                    <p data-ed="banner_text" style="font-size:13px;margin:0"><?= htmlspecialchars($mtcTexts['banner_text']) ?></p>
                  </td></tr>
                </table>
<?php elseif($sec==='contact'): ?>
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:28px">
                  <tr><td style="padding-top:24px;border-top:1px solid #e2e8f0;text-align:center">
                    <p data-ed="contact_title" style="font-size:14px;font-weight:700;color:#0f172a;margin:0 0 8px"><?= htmlspecialchars($mtcTexts['contact_title']) ?></p>
                    <p data-dyn="email de contact" style="font-size:14px;color:#64748b;margin:0">contact@forbachenrose.fr</p>
                  </td></tr>
                </table>
<?php elseif($sec==='custom'): ?>
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px">
                  <tr><td class="sec-r" style="padding:24px;background:#f8fafc;text-align:center">
                    <div data-ed="custom_text" style="font-size:15px;line-height:1.7;color:#334155">Texte personnalisé...</div>
                  </td></tr>
                </table>
<?php endif; ?>
              </div>

<?php endforeach; ?>
              <div class="prev-add"><button type="button" data-action="add-section" title="Ajouter une section">+</button></div>
            </div>
          </td></tr>

          <!-- Footer -->
          <tr><td id="prevFooter" class="prev-sec" data-zone="footer" data-fixed style="padding:32px 40px;text-align:center">
            <div class="drag-bar"></div>
            <p style="margin:0 0 16px">
              <span style="display:inline-block;margin:0 6px;padding:8px 16px;background:rgba(255,255,255,.08);border-radius:6px;color:rgba(255,255,255,.7);font-size:13px">Facebook</span>
              <span style="display:inline-block;margin:0 6px;padding:8px 16px;background:rgba(255,255,255,.08);border-radius:6px;color:rgba(255,255,255,.7);font-size:13px">Instagram</span>
            </p>
            <p data-ed="footer_title" style="font-size:14px;color:rgba(255,255,255,.7);margin:0 0 4px;font-weight:600"><?= htmlspecialchars($mtcTexts['footer_title']) ?></p>
            <p data-ed="footer_subtitle" style="font-size:12px;color:rgba(255,255,255,.4);margin:0;line-height:1.6"><?= htmlspecialchars($mtcTexts['footer_subtitle']) ?></p>
          </td></tr>

        </table>
        </td></tr>
      </table>
    </div>
  </div>

</div>
</div>

<!-- ═══ GOOGLE PANE ═══ -->
<div class="ed-pane-google <?= $activeSubTab==='google'?'active':'' ?>" id="paneGoogle">
  <div class="row g-4">

    <!-- Gmail Config -->
    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2>Paramètres Gmail</h2>
        <form action="" method="post" class="row g-3">
          <?= csrf_field() ?><input type="hidden" name="active_subtab" value="google">
          <div class="col-12"><label class="form-label">Client ID</label><input type="text" class="form-control" name="client_id" value="<?= htmlspecialchars($client_id) ?>"></div>
          <div class="col-12"><label class="form-label">Client Secret</label><input type="text" class="form-control" name="client_secret" value="<?= htmlspecialchars($client_secret) ?>"></div>
          <?php if($hasMailFields): ?>
          <div class="col-12"><label class="form-label">Email de contact</label><input type="email" class="form-control" name="mail_email" value="<?= htmlspecialchars($mail_email) ?>" placeholder="contact@forbachenrose.fr"></div>
          <div class="col-12"><label class="form-label">Téléphone</label><input type="text" class="form-control" name="mail_phone" value="<?= htmlspecialchars($mail_phone) ?>"></div>
          <?php endif; ?>
          <div class="col-12 text-end"><button type="submit" name="google" class="btn btn-primary w-auto">Sauvegarder</button></div>
        </form>
      </div>
    </div>

    <!-- Connexion Status -->
    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2>Connexion Google</h2>
        <div class="p-3 rounded mb-3 <?= $isConnected?'bg-success-subtle':'bg-danger-subtle' ?>">
          <strong>Statut :</strong> <?= $isConnected?'Connecté à Gmail':'Non connecté' ?>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <?php if($isConnected): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="active_subtab" value="google"><input type="hidden" name="action" value="test_connection"><button type="submit" class="btn btn-success w-auto">Tester</button></form>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="active_subtab" value="google"><input type="hidden" name="action" value="send_test_mail"><button type="submit" class="btn btn-primary w-auto">Mail test</button></form>
            <form method="post" style="display:inline" data-confirm="Déconnecter ?"><?= csrf_field() ?><input type="hidden" name="active_subtab" value="google"><input type="hidden" name="action" value="disconnect"><button type="submit" class="btn btn-danger w-auto">Déconnecter</button></form>
          <?php else: ?>
            <a href="<?= htmlspecialchars($authUrl) ?>" class="btn btn-primary w-auto">Se connecter avec Google</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- QR Code -->
    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2>QR Code</h2>
        <form action="" method="post" class="row g-3">
          <?= csrf_field() ?><input type="hidden" name="active_subtab" value="google">
          <div class="col-12">
            <select class="form-select" name="qrcode_mail_mode">
              <option value="none" <?= $qrcode_mail_mode==='none'?'selected':'' ?>>Aucun</option>
              <option value="all" <?= $qrcode_mail_mode==='all'?'selected':'' ?>>Pour tous</option>
              <option value="first_x" <?= $qrcode_mail_mode==='first_x'?'selected':'' ?>>X premiers</option>
            </select>
          </div>
          <div class="col-12 text-end"><button type="submit" name="save_qrcode_config" class="btn btn-primary w-auto">Sauvegarder</button></div>
        </form>
      </div>
    </div>

  </div>
</div>

<!-- ═══ JAVASCRIPT ═══ -->
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  // ── Helpers ──
  var $ = function(s){ return document.querySelector(s); };
  var $$ = function(s){ return document.querySelectorAll(s); };
  function grad(a,b){ return 'linear-gradient(135deg,'+a+' 0%,'+b+' 100%)'; }
  function hexDark(hex,f){
    hex=hex.replace('#','');
    var r=Math.max(0,Math.round(parseInt(hex.substring(0,2),16)*(1-f)));
    var g=Math.max(0,Math.round(parseInt(hex.substring(2,4),16)*(1-f)));
    var b=Math.max(0,Math.round(parseInt(hex.substring(4,6),16)*(1-f)));
    return '#'+r.toString(16).padStart(2,'0')+g.toString(16).padStart(2,'0')+b.toString(16).padStart(2,'0');
  }
  function rgbHex(rgb){
    if(!rgb) return '#000000';
    if(rgb.charAt(0)==='#') return rgb;
    var m=rgb.match(/(\d+)/g);
    return m&&m.length>=3 ? '#'+((1<<24)+(+m[0]<<16)+(+m[1]<<8)+(+m[2])).toString(16).slice(1) : '#000000';
  }

  var CFG = <?= $jsConfig ?>;
  var selected = null;
  var editingText = null;
  var container = $('#prevSections');

  // ── Tab switching ──
  $$('#mailSettingsTabs .nav-link').forEach(function(t){
    t.addEventListener('click', function(e){
      e.preventDefault();
      $$('#mailSettingsTabs .nav-link').forEach(function(x){ x.classList.remove('active'); });
      this.classList.add('active');
      var id = this.dataset.pane;
      $('#paneTemplate').classList.toggle('active', id==='paneTemplate');
      $('#paneGoogle').classList.toggle('active', id==='paneGoogle');
    });
  });

  // ── Apply all styles to preview ──
  function applyAllStyles(){
    var C=CFG.colors, R=CFG.radius;
    $('#previewArea').style.background = C.bg;
    $('#prevOuter').style.background = C.bg;
    $('#prevCard').style.background = C.card_bg;
    $('#prevCard').style.borderRadius = R.card+'px';
    $('#prevHeader').style.background = grad(C.header_bg1, C.header_bg2);
    $('#prevTitleBg').style.background = C.title_bg;
    var tips=$('#prevTips'); if(tips) tips.style.background = C.tips_bg;
    var banner=$('#prevBanner'); if(banner) banner.style.background = grad(C.banner_bg1, C.banner_bg2);
    $('#prevFooter').style.background = C.footer_bg;
    $('#prevBadge').style.background = C.card_bg;
    $('#prevBadge').style.borderRadius = R.badge+'px';
    $$('.sec-r').forEach(function(e){ e.style.borderRadius=R.section+'px'; });
    var bt=$('[data-ed="banner_title"]'); if(bt) bt.style.color=hexDark(C.accent,.45);
    var bx=$('[data-ed="banner_text"]'); if(bx) bx.style.color=hexDark(C.accent,.25);
  }
  applyAllStyles();

  // ── Global color/radius bindings ──
  $$('#sbGlobal input[type="color"]').forEach(function(inp){
    inp.addEventListener('input', function(){ CFG.colors[this.dataset.c]=this.value; applyAllStyles(); });
  });
  $$('#sbGlobal input[type="range"]').forEach(function(inp){
    inp.addEventListener('input', function(){
      CFG.radius[this.dataset.r]=+this.value;
      this.nextElementSibling.textContent=this.value;
      applyAllStyles();
    });
  });

  // ── Card width slider ──
  $('#sbCardWidth').addEventListener('input', function(){
    var w=this.value;
    $('#sbCardWidthV').textContent=w;
    $('#prevCard').style.maxWidth=w+'px';
    $('#prevCard').setAttribute('width',w);
    $('.prev-email').style.width=w+'px';
  });

  // ── Inner element drag & drop ──
  var innerDragged=null;
  function makeInnerDraggable(el){
    el.setAttribute('draggable','true');
    el.addEventListener('dragstart', function(e){
      if(editingText) return e.preventDefault(); // don't drag while editing
      innerDragged=this;
      this.classList.add('el-dragging');
      e.dataTransfer.effectAllowed='move';
      e.stopPropagation();
    });
    el.addEventListener('dragend', function(){
      this.classList.remove('el-dragging');
      $$('.el-drop-above,.el-drop-below').forEach(function(x){ x.classList.remove('el-drop-above','el-drop-below'); });
      innerDragged=null;
    });
    el.addEventListener('dragover', function(e){
      if(!innerDragged||innerDragged===this) return;
      // Only allow drop within same parent
      if(innerDragged.parentNode!==this.parentNode) return;
      e.preventDefault(); e.stopPropagation();
      e.dataTransfer.dropEffect='move';
      var rect=this.getBoundingClientRect();
      var mid=rect.top+rect.height/2;
      this.classList.toggle('el-drop-above',e.clientY<mid);
      this.classList.toggle('el-drop-below',e.clientY>=mid);
    });
    el.addEventListener('dragleave', function(){
      this.classList.remove('el-drop-above','el-drop-below');
    });
    el.addEventListener('drop', function(e){
      e.preventDefault(); e.stopPropagation();
      if(!innerDragged||innerDragged===this||innerDragged.parentNode!==this.parentNode) return;
      var rect=this.getBoundingClientRect();
      if(e.clientY<rect.top+rect.height/2){
        this.parentNode.insertBefore(innerDragged,this);
      } else {
        this.parentNode.insertBefore(innerDragged,this.nextSibling);
      }
      this.classList.remove('el-drop-above','el-drop-below');
    });
  }

  // ── Section selection ──
  function deselectAll(){
    if(editingText){ editingText.contentEditable='false'; editingText.classList.remove('editing'); editingText=null; }
    $$('.prev-sec').forEach(function(e){ e.classList.remove('selected'); });
    selected=null;
    $('#sbSection').style.display='none';
    $('#sbText').style.display='none';
  }

  $$('.prev-sec').forEach(function(el){
    el.addEventListener('click', function(e){
      if(e.target.closest('[data-ed]')||e.target.closest('[data-dyn]')||e.target.closest('.sec-actions')||e.target.closest('.drag-bar')) return;
      deselectAll();
      selected=el;
      el.classList.add('selected');
      showSectionPanel(el);
    });
  });

  $('#previewArea').addEventListener('click', function(e){ if(e.target===this) deselectAll(); });

  // ── Section panel ──
  var zoneNames={header:'En-tête',title:'Section titre',footer:'Pied de page',details:'Infos participant',tips:'Conseils',description:'Message',qrcode:'QR Code',banner:'Bannière',contact:'Contact',custom:'Zone personnalisée'};
  var zoneColors={
    header:'header_panel',
    title:[['title_bg','Fond']],footer:[['footer_bg','Fond']],
    tips:[['tips_bg','Fond']],
    banner:[['banner_bg1','Couleur 1'],['banner_bg2','Couleur 2'],['banner_border','Bordure']],
    details:'section_accent',qrcode:'section_accent',description:'section_accent',
    contact:[],custom:'direct'
  };

  // ── Header image file input (always works, no callback needed) ──
  var hImgFileEl=$('#hImgFile');
  hImgFileEl.addEventListener('click', function(){ this.value=''; }); // reset so change fires even for same file
  hImgFileEl.addEventListener('change', function(){
    if(!this.files||!this.files[0]) return;
    var reader=new FileReader();
    reader.onload=function(ev){
      var dataUrl=ev.target.result;
      var hd=$('#prevHeader');
      hd.innerHTML='<div class="drag-bar"></div>'+
        '<img src="'+dataUrl+'" style="max-width:80%;height:auto">'+
        '<p data-ed="header_subtitle" style="color:rgba(255,255,255,.75);font-size:14px;margin:0">'+
        (CFG.texts.header_subtitle||'')+'</p>';
      hd.querySelectorAll('[data-ed]').forEach(bindTextEl);
      $('#hImgDel').value='';
      // Re-select header to refresh panel
      deselectAll();
      selected=hd;
      hd.classList.add('selected');
      showSectionPanel(hd);
    };
    reader.readAsDataURL(this.files[0]);
  });

  function showSectionPanel(el){
    var zone=el.dataset.zone;
    $('#sbText').style.display='none';
    $('#sbSection').style.display='';
    $('#sbSecName').textContent=zoneNames[zone]||zone;
    var cc=$('#sbSecColors'); cc.innerHTML='';
    var colorsDef=zoneColors[zone]||[];

    if(colorsDef==='header_panel'){
      // Colors
      [['header_bg1','Couleur 1'],['header_bg2','Couleur 2']].forEach(function(c){
        var row=document.createElement('div'); row.className='sb-row';
        row.innerHTML='<label>'+c[1]+'</label><input type="color" value="'+(CFG.colors[c[0]]||'#000')+'">';
        cc.appendChild(row);
        row.querySelector('input').addEventListener('input', function(){ CFG.colors[c[0]]=this.value; applyAllStyles(); });
      });

      // Image / Text toggle
      var headerTd=$('#prevHeader');
      var hasImg=!!headerTd.querySelector('img');
      var modeTitle=document.createElement('p'); modeTitle.className='sb-title'; modeTitle.textContent='Contenu';
      cc.appendChild(modeTitle);

      var modeRow=document.createElement('div'); modeRow.className='sb-align'; modeRow.style.marginBottom='8px';
      modeRow.innerHTML='<button type="button" data-hmode="text" '+(hasImg?'':'class="active"')+'>Texte</button><button type="button" data-hmode="image" '+(hasImg?'class="active"':'')+'>Image</button>';
      cc.appendChild(modeRow);

      var imgPreviewWrap=document.createElement('div'); imgPreviewWrap.id='sbHeaderImgPreview';
      cc.appendChild(imgPreviewWrap);

      function updateHeaderImgPreview(){
        var curImg=headerTd.querySelector('img');
        imgPreviewWrap.innerHTML='';
        if(curImg){
          var prev=document.createElement('div');
          prev.style.cssText='background:#1e293b;padding:8px;border-radius:8px;text-align:center;margin-bottom:8px';
          prev.innerHTML='<img src="'+curImg.src+'" style="max-height:50px;border-radius:4px">';
          imgPreviewWrap.appendChild(prev);
          // Image size slider
          var curW=parseInt(curImg.style.maxWidth)||80;
          var sizeRow=document.createElement('div'); sizeRow.className='sb-row';
          sizeRow.innerHTML='<label>Taille image</label><input type="range" min="20" max="100" step="5" value="'+curW+'"><span class="v">'+curW+'%</span>';
          imgPreviewWrap.appendChild(sizeRow);
          var sizeInp=sizeRow.querySelector('input');
          var sizeV=sizeRow.querySelector('.v');
          sizeInp.addEventListener('input', function(){
            var img=headerTd.querySelector('img');
            if(img) img.style.maxWidth=this.value+'%';
            sizeV.textContent=this.value+'%';
          });
          var delBtn=document.createElement('button');
          delBtn.type='button';
          delBtn.className='sb-btn sb-btn-danger'; delBtn.style.marginBottom='8px'; delBtn.style.marginTop='8px';
          delBtn.textContent='Supprimer image';
          delBtn.addEventListener('click', function(){
            switchToText();
          });
          imgPreviewWrap.appendChild(delBtn);
        }
        var chooseBtn=document.createElement('button');
        chooseBtn.type='button';
        chooseBtn.className='sb-btn'; chooseBtn.textContent='Choisir une image';
        chooseBtn.addEventListener('click', function(){ $('#hImgFile').click(); });
        imgPreviewWrap.appendChild(chooseBtn);
        imgPreviewWrap.style.display=headerTd.querySelector('img')?'':'none';
      }

      function switchToText(){
        headerTd.innerHTML='<div class="drag-bar"></div>'+
          '<h1 data-ed="header_title" style="color:#fff;font-size:24px;font-weight:700;margin:0 0 6px;letter-spacing:-.02em">'+
          (CFG.texts.header_title||'Forbach en Rose')+'</h1>'+
          '<p data-ed="header_subtitle" style="color:rgba(255,255,255,.75);font-size:14px;margin:0">'+
          (CFG.texts.header_subtitle||'')+'</p>';
        headerTd.querySelectorAll('[data-ed]').forEach(bindTextEl);
        $('#hImgDel').value='1'; $('#hHeaderImg').value='';
        modeRow.querySelectorAll('button').forEach(function(b){ b.classList.toggle('active',b.dataset.hmode==='text'); });
        imgPreviewWrap.style.display='none';
      }
      function switchToImage(){
        $('#hImgFile').click();
        modeRow.querySelectorAll('button').forEach(function(b){ b.classList.toggle('active',b.dataset.hmode==='image'); });
      }

      modeRow.querySelectorAll('button').forEach(function(b){
        b.addEventListener('click', function(e){
          e.stopPropagation();
          if(this.dataset.hmode==='text') switchToText();
          else switchToImage();
        });
      });

      updateHeaderImgPreview();
    } else if(colorsDef==='section_accent'){
      // Per-section accent color — only affects elements inside this section
      var curAccent=CFG.colors.accent;
      var accTxt=el.querySelector('[data-acc="txt"]');
      if(accTxt) curAccent=rgbHex(accTxt.style.color||CFG.colors.accent);
      var row=document.createElement('div'); row.className='sb-row';
      row.innerHTML='<label>Accent</label><input type="color" value="'+curAccent+'">';
      cc.appendChild(row);
      row.querySelector('input').addEventListener('input', function(){
        var c=this.value;
        el.querySelectorAll('[data-acc="txt"]').forEach(function(e){ e.style.color=c; });
        el.querySelectorAll('[data-acc="left"]').forEach(function(e){ e.style.borderLeft='3px solid '+c; });
        el.querySelectorAll('[data-acc="border"]').forEach(function(e){ e.style.border='2px dashed '+c; });
        el.querySelectorAll('[data-acc="badge"]').forEach(function(e){ e.style.color=c; });
      });
    } else if(colorsDef==='direct'){
      // Direct background color for custom sections
      var td=el.querySelector('td');
      var curBg=td?rgbHex(td.style.backgroundColor||'#f8fafc'):'#f8fafc';
      var row=document.createElement('div'); row.className='sb-row';
      row.innerHTML='<label>Fond</label><input type="color" value="'+curBg+'">';
      cc.appendChild(row);
      row.querySelector('input').addEventListener('input', function(){
        if(td) td.style.backgroundColor=this.value;
      });
    } else if(Array.isArray(colorsDef)){
      colorsDef.forEach(function(c){
        var row=document.createElement('div'); row.className='sb-row';
        row.innerHTML='<label>'+c[1]+'</label><input type="color" value="'+(CFG.colors[c[0]]||'#000')+'">';
        cc.appendChild(row);
        row.querySelector('input').addEventListener('input', function(){ CFG.colors[c[0]]=this.value; applyAllStyles(); });
      });
    }
    // Add text button for all content sections
    if(zone!=='header'&&zone!=='title'&&zone!=='footer'){
      var addTxtRow=document.createElement('div'); addTxtRow.style.marginTop='10px';
      addTxtRow.innerHTML='<button type="button" class="sb-btn" style="width:100%">+ Ajouter un texte</button>';
      cc.appendChild(addTxtRow);
      addTxtRow.querySelector('button').addEventListener('click', function(){
        var target=el.querySelector('td')||el;
        var newEl=document.createElement('div');
        newEl.setAttribute('data-ed','txt_'+Date.now());
        newEl.style.cssText='font-size:15px;line-height:1.7;color:#334155;margin-top:12px';
        newEl.textContent='Nouveau texte...';
        target.appendChild(newEl);
        bindTextEl(newEl);
      });
    }

    $('#sbSecDelete').style.display=(el.hasAttribute('data-fixed')||zone==='header'||zone==='title'||zone==='footer')?'none':'';
    $('#sbSecDelete').onclick=function(){ doRemoveSection(el); };
  }

  // ── Text editing ──
  // ── Element actions (arrows + trash) ──
  function addElActions(el){
    if(el.querySelector('.el-actions')) return;
    var isDyn=el.hasAttribute('data-dyn');
    var isEd=el.hasAttribute('data-ed');
    var wrap=document.createElement('span');
    wrap.className='el-actions'; wrap.setAttribute('draggable','false');
    wrap.innerHTML=
      '<button class="el-act" type="button" title="Monter" data-mv="up">&#8593;</button>'+
      '<button class="el-act" type="button" title="Descendre" data-mv="down">&#8595;</button>'+
      (isDyn?'':'<button class="el-act del" type="button" title="Supprimer">&#10005;</button>');
    wrap.addEventListener('click', function(e){
      e.stopPropagation(); e.preventDefault();
      var btn=e.target.closest('[data-mv]');
      if(btn){
        var parent=el.parentNode;
        // Get movable siblings (skip .drag-bar, .sec-actions, .el-actions, <br>)
        var sibs=Array.from(parent.children).filter(function(c){
          return !c.classList.contains('drag-bar')&&!c.classList.contains('sec-actions')&&c.tagName!=='BR';
        });
        var idx=sibs.indexOf(el);
        if(btn.dataset.mv==='up'&&idx>0){
          parent.insertBefore(el,sibs[idx-1]);
        } else if(btn.dataset.mv==='down'&&idx<sibs.length-1){
          var next=sibs[idx+1];
          parent.insertBefore(el,next.nextSibling);
        }
        return;
      }
      var delBtn=e.target.closest('.del');
      if(delBtn&&!isDyn){
        el.remove();
        $('#sbText').style.display='none';
      }
    });
    el.appendChild(wrap);
    makeInnerDraggable(el);
  }

  function bindTextEl(el){
    addElActions(el);
    el.addEventListener('click', function(e){
      if(e.target.closest('.el-actions')) return;
      e.stopPropagation(); showTextPanel(this);
    });
    el.addEventListener('dblclick', function(e){
      e.preventDefault(); e.stopPropagation();
      if(this.hasAttribute('data-dyn')) return;
      deselectAll();
      editingText=this;
      this.classList.add('editing');
      this.contentEditable='true';
      this.focus();
      var r=document.createRange(); r.selectNodeContents(this);
      var s=window.getSelection(); s.removeAllRanges(); s.addRange(r);
      showTextPanel(this);
    });
  }
  $$('[data-ed]').forEach(bindTextEl);
  $$('[data-dyn]').forEach(function(el){
    addElActions(el);
    el.addEventListener('click', function(e){
      if(e.target.closest('.el-actions')) return;
      e.stopPropagation(); showTextPanel(this);
    });
    el.addEventListener('dblclick', function(e){ e.preventDefault(); e.stopPropagation(); });
  });

  // Make non-ed/non-dyn block elements reorderable (QR placeholder, tables, etc.)
  function bindReorderChildren(parentTd){
    Array.from(parentTd.children).forEach(function(child){
      if(child.hasAttribute('data-ed')||child.hasAttribute('data-dyn')||child.classList.contains('el-actions')) return;
      if(child.classList.contains('drag-bar')||child.classList.contains('sec-actions')) return;
      if(child.tagName==='BR') { child.remove(); return; }
      if(child.nodeType!==1) return;
      child.classList.add('el-reorder');
      addElActions(child);
    });
  }
  // Sections inside #prevSections
  container.querySelectorAll('.prev-sec[data-section] td').forEach(bindReorderChildren);
  // Header, Title, Footer (outside container)
  ['#prevHeader','#prevTitleBg','#prevFooter'].forEach(function(sel){
    var td=$(sel); if(td) bindReorderChildren(td);
  });

  document.addEventListener('keydown', function(e){
    if(!editingText) return;
    if(e.key==='Escape'||e.key==='Enter'){ e.preventDefault(); editingText.contentEditable='false'; editingText.classList.remove('editing'); editingText=null; }
  });

  function showTextPanel(el){
    $('#sbSection').style.display='none';
    $('#sbText').style.display='';
    var cs=window.getComputedStyle(el);
    $('#sbTxtSize').value=parseInt(cs.fontSize)||14;
    $('#sbTxtSizeV').textContent=parseInt(cs.fontSize)||14;
    $('#sbTxtColor').value=rgbHex(cs.color);
    var curFont=el.style.fontFamily||cs.fontFamily||'';
    var fontSel=$('#sbTxtFont'); fontSel.value='inherit';
    Array.from(fontSel.options).forEach(function(opt){ if(opt.value!=='inherit'&&curFont.indexOf(opt.value)!==-1) fontSel.value=opt.value; });
    $$('.sb-align button').forEach(function(b){ b.classList.toggle('active',cs.textAlign===b.dataset.align||(cs.textAlign==='start'&&b.dataset.align==='left')); });
    // Wire controls to this element
    $('#sbTxtFont').onchange=function(){ el.style.fontFamily=this.value==='inherit'?'':this.value; };
    $('#sbTxtSize').oninput=function(){ el.style.fontSize=this.value+'px'; $('#sbTxtSizeV').textContent=this.value; };
    $('#sbTxtColor').oninput=function(){ el.style.color=this.value; };
    $$('.sb-align button').forEach(function(b){
      b.onclick=function(){ $$('.sb-align button').forEach(function(x){x.classList.remove('active')}); this.classList.add('active'); el.style.textAlign=this.dataset.align; };
    });
    // Delete button — only for non-locked (data-ed, not data-dyn) elements
    var delWrap=$('#sbTxtDelete');
    var isDyn=el.hasAttribute('data-dyn');
    delWrap.style.display=isDyn?'none':'';
    $('#sbTxtDeleteBtn').onclick=function(){
      if(isDyn) return;
      el.remove();
      $('#sbText').style.display='none';
    };
  }

  // ── Drag & drop ──
  var dragged=null;
  function bindDrag(el){
    el.addEventListener('dragstart',function(e){dragged=this;this.style.opacity='.3';e.dataTransfer.effectAllowed='move';});
    el.addEventListener('dragend',function(){this.style.opacity='1';dragged=null;});
    el.addEventListener('dragover',function(e){
      e.preventDefault();
      if(!dragged||dragged===this) return;
      var rect=this.getBoundingClientRect();
      if(e.clientY<rect.top+rect.height/2) container.insertBefore(dragged,this);
      else container.insertBefore(dragged,this.nextSibling);
      updateOrder();
    });
  }
  container.querySelectorAll('.prev-sec[data-section]').forEach(bindDrag);

  function updateOrder(){
    var items=container.querySelectorAll('.prev-sec[data-section]');
    var arr=[]; items.forEach(function(e){arr.push(e.dataset.section)});
    $('#hOrder').value=arr.join(',');
  }

  // ── Delegated event handler for all data-action buttons ──
  document.addEventListener('click', function(e){
    var btn=e.target.closest('[data-action]');
    if(!btn) return;
    var action=btn.dataset.action;
    e.stopPropagation();

    if(action==='add-section'){
      var parent=btn.closest('.prev-add');
      var tpl='<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px"><tr><td class="sec-r" style="padding:24px;background:#f8fafc;text-align:center"><div data-ed="custom_text" style="font-size:15px;line-height:1.7;color:#334155">Texte personnalisé...</div></td></tr></table>';
      var wrap=document.createElement('div');
      wrap.className='prev-sec'; wrap.dataset.zone='custom'; wrap.dataset.section='custom'; wrap.draggable=true;
      wrap.innerHTML='<div class="drag-bar"></div><div class="sec-actions"><button type="button" class="sec-act" data-action="move-up" title="Monter">&#8593;</button><button type="button" class="sec-act" data-action="move-down" title="Descendre">&#8595;</button><button type="button" class="sec-act del" data-action="remove-section" title="Supprimer">&#10005;</button></div>'+tpl;
      var addBtn=document.createElement('div');
      addBtn.className='prev-add';
      addBtn.innerHTML='<button type="button" data-action="add-section" title="Ajouter">+</button>';
      parent.after(wrap);
      wrap.after(addBtn);
      bindDrag(wrap);
      wrap.querySelectorAll('[data-ed]').forEach(bindTextEl);
      wrap.addEventListener('click', function(ev){
        if(!ev.target.closest('[data-ed]')&&!ev.target.closest('.sec-actions')) {
          deselectAll(); selected=wrap; wrap.classList.add('selected'); showSectionPanel(wrap);
        }
      });
      updateOrder();
      applyAllStyles();
    }

    if(action==='move-up'||action==='move-down'){
      var sec=btn.closest('.prev-sec');
      var secs=Array.from(container.querySelectorAll('.prev-sec[data-section]'));
      var idx=secs.indexOf(sec);
      if(action==='move-up'&&idx>0){
        var target=secs[idx-1];
        var bef=target.previousElementSibling;
        container.insertBefore(sec,bef&&bef.classList.contains('prev-add')?bef:target);
      }
      if(action==='move-down'&&idx<secs.length-1){
        var next=secs[idx+1];
        var nn=next.nextElementSibling;
        if(nn&&nn.classList.contains('prev-add')) nn=nn.nextElementSibling;
        container.insertBefore(sec,nn||null);
      }
      updateOrder();
    }

    if(action==='remove-section'){
      doRemoveSection(btn.closest('.prev-sec'));
    }
  });

  function doRemoveSection(sec){
    if(!sec||!sec.dataset.section||sec.hasAttribute('data-fixed')) return;
    var prev=sec.previousElementSibling;
    var next=sec.nextElementSibling;
    if(prev&&prev.classList.contains('prev-add')) prev.remove();
    else if(next&&next.classList.contains('prev-add')) next.remove();
    sec.remove();
    deselectAll();
    updateOrder();
  }

  // ── Sync config to hidden fields before submit ──
  $('#tplForm').addEventListener('submit', function(){
    var h=$('#hFields'); h.innerHTML='';
    function add(n,v){var i=document.createElement('input');i.type='hidden';i.name=n;i.value=v;h.appendChild(i);}
    Object.keys(CFG.colors).forEach(function(k){add('mtc_'+k,CFG.colors[k]);});
    add('mtc_radius_card',CFG.radius.card);
    add('mtc_radius_section',CFG.radius.section);
    add('mtc_radius_badge',CFG.radius.badge);
    add('mtc_card_width',$('#sbCardWidth').value);
    var hdrImg=$('#prevHeader').querySelector('img');
    add('mtc_header_image_size',hdrImg?parseInt(hdrImg.style.maxWidth)||80:80);
    add('mtc_font',CFG.font);
    $$('[data-ed]').forEach(function(el){
      var clone=el.cloneNode(true);
      clone.querySelectorAll('.el-actions').forEach(function(a){a.remove();});
      add('mtc_'+el.dataset.ed,clone.textContent.trim());
      if(el.style.fontFamily) add('mtc_font_'+el.dataset.ed,el.style.fontFamily);
      if(el.style.fontSize) add('mtc_size_'+el.dataset.ed,el.style.fontSize);
      if(el.style.textAlign) add('mtc_align_'+el.dataset.ed,el.style.textAlign);
      if(el.style.color) add('mtc_color_'+el.dataset.ed,el.style.color);
    });
    $$('[data-dyn]').forEach(function(el){
      var key='dyn_'+(el.dataset.dyn||'').replace(/\s+/g,'_');
      if(el.style.fontFamily) add('mtc_font_'+key,el.style.fontFamily);
      if(el.style.fontSize) add('mtc_size_'+key,el.style.fontSize);
      if(el.style.textAlign) add('mtc_align_'+key,el.style.textAlign);
      if(el.style.color) add('mtc_color_'+key,el.style.color);
    });
    // Custom section backgrounds
    var ci=0;
    container.querySelectorAll('.prev-sec[data-zone="custom"]').forEach(function(sec){
      var td=sec.querySelector('td');
      if(td&&td.style.backgroundColor) add('mtc_custom_bg_'+ci,rgbHex(td.style.backgroundColor));
      ci++;
    });
    updateOrder();
  });

  // ── Sidebar tabs (Editor / Preview) ──
  $$('.sb-tab').forEach(function(tab){
    tab.addEventListener('click', function(){
      $$('.sb-tab').forEach(function(t){t.classList.remove('active')});
      this.classList.add('active');
      var target=this.dataset.sbtab;
      $('#sbEditor').style.display=target==='sbEditor'?'':'none';
      $('#sbPreview').style.display=target==='sbPreview'?'':'none';
      // Switch back to editor view when clicking Editor tab
      if(target==='sbEditor'){
        $('#previewIframe').style.display='none';
        $('#prevEmail').style.display='';
      }
    });
  });

  // ── Preview buttons ──
  var csrfToken=$('meta[name="csrf-token"]').getAttribute('content');
  $$('.preview-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var type=this.dataset.preview;
      var iframe=$('#previewIframe');
      var editor=$('#prevEmail');
      if(type==='editor'){
        iframe.style.display='none';
        editor.style.display='';
        // Switch to editor tab
        $$('.sb-tab').forEach(function(t){t.classList.remove('active')});
        $$('.sb-tab')[0].classList.add('active');
        $('#sbEditor').style.display='';
        $('#sbPreview').style.display='none';
        return;
      }
      this.classList.add('loading');
      var self=this;
      var body=new FormData();
      body.append('preview_type',type);
      body.append('csrf_token',csrfToken);
      fetch('',{method:'POST',body:body})
        .then(function(r){return r.text()})
        .then(function(html){
          editor.style.display='none';
          iframe.style.display='';
          iframe.srcdoc=html;
          self.classList.remove('loading');
        })
        .catch(function(){
          self.classList.remove('loading');
        });
    });
  });

  // ── Confirm dialogs ──
  document.addEventListener('submit', function(e){
    var f=e.target.closest('form[data-confirm]');
    if(f&&!confirm(f.dataset.confirm)) e.preventDefault();
  });
})();
</script>

<?php require 'admin-footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
</body>
</html>
