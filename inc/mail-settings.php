<?php
require '../src/core/config.php';
require_once '../src/security/csrf.php';
require __DIR__ . '/../src/partials/navbar-data.php';
requirePage('mail-settings');
$role = currentRole();
$canWrite = canDoAction('mail.write'); // Template + Google + Notifications
$canSend  = canDoAction('mail.send');  // Envoi de mail
$canNewsletter = canDoAction('mail.newsletter'); // Abonnés newsletter (voir / supprimer)
// Pas de $pageReadOnly global : on contrôle l'affichage onglet par onglet

// Bloquer toute action POST si l'utilisateur n'a aucun des droits de la page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canWrite && !$canSend && !$canNewsletter) {
    http_response_code(403);
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Action non autorisée (lecture seule).'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

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
$turnstile_sitekey = $data['turnstile_sitekey'] ?? '';
$turnstile_secret  = $data['turnstile_secret']  ?? '';
$notify_recipients_raw = $data['notify_recipients'] ?? null;
$notify_recipients = $notify_recipients_raw ? json_decode($notify_recipients_raw, true) : [];
$notify_toggles_raw = $data['notify_toggles'] ?? null;
$notify_toggles = $notify_toggles_raw ? json_decode($notify_toggles_raw, true) : [];
$notify_toggles += ['mention' => true, 'partner' => true, 'ip_ban' => true, 'twofa' => true, 'lock' => true, 'contact' => true];

// Charger les admins pour le select
$adminUsers = $pdo->query("SELECT email FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY email ASC")->fetchAll(PDO::FETCH_COLUMN);

// Abonnés newsletter (pour le bouton "Newsletter" des destinataires)
require_once '../src/mail/newsletter.php';
$newsletterEmails = newsletterSubscribedEmails($pdo);

// Liste détaillée des abonnés (onglet "Abonnés newsletter" : statut + date)
$newsletterSubscribers = [];
try {
    $newsletterSubscribers = $pdo->query(
        "SELECT email, status, created_at, unsubscribed_at
           FROM newsletter_subscribers
          ORDER BY (status = 'subscribed') DESC, created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// SMTP / mail provider
$mail_provider   = $data['mail_provider'] ?? 'google';
$smtp_host       = $data['smtp_host'] ?? '';
$smtp_port       = $data['smtp_port'] ?? 465;
$smtp_user       = $data['smtp_user'] ?? '';
$smtp_pass       = !empty($data['smtp_pass']) ? decrypt($data['smtp_pass']) : '';
$smtp_encryption = $data['smtp_encryption'] ?? 'ssl';
$smtp_from_email = $data['smtp_from_email'] ?? '';
$smtp_from_name  = $data['smtp_from_name'] ?? 'Forbach en Rose';

// OAuth — chargement lazy comme setting.php
$isConnected = false;
$authUrl = '#';
if (($data['mail_provider'] ?? 'google') !== 'smtp') {
    try {
        require_once '../src/mail/googleMail.php';
        $isConnected = isGoogleConnectionValid();
        $authUrl = getGoogleAuthUrl('mail-settings.php');
    } catch (\Throwable $e) {
        // Google OAuth not configured or error
    }
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
    'contact_title'=>'Une question ?',
    'title_subtitle'=>"Votre inscription a bien été enregistrée.
Merci de rejoindre cette belle cause."
];
$mtcFont      = $mtc['font'] ?? 'system';
$mtcOrder     = $mtc['section_order'] ?? ['details','tips','description','qrcode','banner','contact'];
$mtcHeaderImg = $mtc['header_image'] ?? '';
$mtcRadius    = ($mtc['radius'] ?? []) + ['card'=>16,'section'=>12,'badge'=>20];
$mtcCardWidth = $mtc['card_width'] ?? 600;
$mtcHeaderImgSize = $mtc['header_image_size'] ?? 80;
$mtcVisibility = ($mtc['visibility'] ?? []) + [
    'details'=>['inscription'],'tips'=>['inscription'],
    'description'=>['inscription','code','new_user','bulk','test','new_article'],
    'qrcode'=>['inscription'],
    'banner'=>['inscription','code','new_user','bulk','test','new_article'],
    'contact'=>['inscription','code','new_user','bulk','test','new_article'],
];

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
            'visibility' => json_decode($_POST['mtc_visibility'] ?? '{}', true) ?: [],
        ];

        // Capture ALL dynamic texts not already in the explicit list
        $knownKeys = array_keys($tplConfig['texts']);
        foreach ($_POST as $k => $v) {
            if (strpos($k, 'mtc_') === 0) {
                $key = substr($k, 4); // remove 'mtc_'
                // Skip known config keys (colors, radius, font, etc.)
                if (in_array($key, $knownKeys)) continue;
                if (in_array($key, ['font','section_order','radius_card','radius_section','radius_badge','card_width','header_image_size'])) continue;
                if (strpos($key, 'font_') === 0 || strpos($key, 'size_') === 0 || strpos($key, 'align_') === 0 || strpos($key, 'color_') === 0) continue;
                if (isset($tplConfig['colors'][$key])) continue;
                // It's a dynamic text
                $tplConfig['texts'][$key] = $v;
            }
        }

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
        $mtcVisibility = $mtc['visibility'];

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

    // Save SMTP config
    if (isset($_POST['save_smtp'])) {
        $newProvider     = in_array($_POST['mail_provider'] ?? '', ['google','smtp']) ? $_POST['mail_provider'] : 'google';
        $newSmtpHost     = trim($_POST['smtp_host'] ?? '');
        $newSmtpPort     = (int)($_POST['smtp_port'] ?? 465);
        $newSmtpUser     = trim($_POST['smtp_user'] ?? '');
        $newSmtpPass     = $_POST['smtp_pass'] ?? '';
        $newSmtpEnc      = in_array($_POST['smtp_encryption'] ?? '', ['ssl','tls','none']) ? $_POST['smtp_encryption'] : 'ssl';
        $newSmtpFromEmail = trim($_POST['smtp_from_email'] ?? '');
        $newSmtpFromName  = trim($_POST['smtp_from_name'] ?? 'Forbach en Rose');

        $encPass = $newSmtpPass !== '' ? encrypt($newSmtpPass) : ($data['smtp_pass'] ?? '');

        $pdo->prepare('UPDATE setting SET mail_provider=:mp, smtp_host=:sh, smtp_port=:sp, smtp_user=:su, smtp_pass=:spw, smtp_encryption=:se, smtp_from_email=:sfe, smtp_from_name=:sfn WHERE id=1')
            ->execute([
                'mp'=>$newProvider, 'sh'=>$newSmtpHost, 'sp'=>$newSmtpPort,
                'su'=>$newSmtpUser, 'spw'=>$encPass, 'se'=>$newSmtpEnc,
                'sfe'=>$newSmtpFromEmail, 'sfn'=>$newSmtpFromName,
            ]);
        $mail_provider = $newProvider;
        $smtp_host = $newSmtpHost; $smtp_port = $newSmtpPort;
        $smtp_user = $newSmtpUser; $smtp_encryption = $newSmtpEnc;
        $smtp_from_email = $newSmtpFromEmail; $smtp_from_name = $newSmtpFromName;
        if ($newSmtpPass !== '') $smtp_pass = $newSmtpPass;
        addToast('success', 'Configuration SMTP enregistrée !');
    }

    // Switch mail provider
    if (isset($_POST['switch_provider'])) {
        $newProvider = in_array($_POST['mail_provider'] ?? '', ['google','smtp']) ? $_POST['mail_provider'] : 'google';
        $pdo->prepare('UPDATE setting SET mail_provider=:mp WHERE id=1')->execute(['mp'=>$newProvider]);
        $mail_provider = $newProvider;
        addToast('success', 'Fournisseur mail changé : ' . ($newProvider === 'google' ? 'Gmail' : 'SMTP'));
    }

    // SMTP test
    if (isset($_POST['action']) && $_POST['action'] === 'send_test_smtp') {
        try {
            require_once __DIR__ . '/../src/mail/googleMail.php';
            $email = trim($_SESSION['email'] ?? '');
            if ($email) {
                writeSmtpLog("Test SMTP — destinataire exact : [$email]");
                $result = sendMailSmtp($email, 'Mail de test SMTP - Forbach en Rose', 'Test SMTP réussi !', 'Ce mail de test confirme que la configuration SMTP fonctionne correctement.');
                if ($result) addToast('success', 'Mail test SMTP envoyé à ' . htmlspecialchars($email));
                else addToast('danger', 'Échec envoi mail test SMTP');
            } else {
                addToast('danger', 'Email introuvable');
            }
        } catch (\Throwable $e) { addToast('danger', 'Échec : ' . $e->getMessage()); }
    }

    // QR Code config
    if (isset($_POST['save_qrcode_config'])) {
        $mode = $_POST['qrcode_mail_mode'] ?? 'none';
        if (!in_array($mode, ['none','all','first_x'], true)) $mode = 'none';
        $pdo->prepare('UPDATE setting SET qrcode_mail_mode = :m WHERE id = 1')->execute(['m'=>$mode]);
        $qrcode_mail_mode = $mode;
        addToast('success', 'Configuration QR Code enregistrée');
    }

    // Cloudflare Turnstile : clés anti-bot du formulaire partenaire
    if (isset($_POST['save_turnstile'])) {
        require_once '../src/security/captcha.php';
        $newKey    = trim($_POST['turnstile_sitekey'] ?? '');
        $newSecret = trim($_POST['turnstile_secret']  ?? '');
        // Le secret est facultatif côté UI : laisser vide = ne pas modifier
        $secretToStore = $newSecret !== '' ? $newSecret : ($data['turnstile_secret'] ?? null);

        // Vérification auprès de Cloudflare si les 2 clés sont fournies
        $proceed = true;
        if ($newKey !== '' && $secretToStore) {
            $test = testTurnstileSecret($secretToStore);
            if ($test['reason'] === 'invalid_secret') {
                addToast('danger', 'Clé secrète Turnstile invalide — refusée par Cloudflare. Enregistrement annulé.');
                $proceed = false;
            } elseif ($test['reason'] === 'unreachable') {
                addToast('warning', 'Cloudflare injoignable : impossible de vérifier la clé. Configuration sauvegardée quand même.');
            }
        }

        if ($proceed) {
            $pdo->prepare('UPDATE setting SET turnstile_sitekey = :k, turnstile_secret = :s WHERE id = 1')->execute([
                'k' => $newKey !== '' ? $newKey : null,
                's' => $secretToStore !== '' ? $secretToStore : null,
            ]);
            $turnstile_sitekey = $newKey;
            if ($newSecret !== '') $turnstile_secret = $newSecret;
            $okMsg = ($newKey !== '' && $secretToStore && isset($test) && $test['reason'] === 'valid')
                ? 'Configuration Turnstile enregistrée (secret validé par Cloudflare — utilisez « Tester les 2 clés » pour vérifier aussi la Site Key)'
                : 'Configuration Turnstile enregistrée';
            addToast('success', $okMsg);
        }
    }


    // Notifications admin : toggles + destinataires
    if (isset($_POST['save_notify_comment'])) {
        $rawRecipients = $_POST['notify_recipients'] ?? '';
        $recipientList = array_values(array_unique(array_filter(array_map(function($e) {
            return strtolower(trim($e));
        }, explode(',', $rawRecipients)), function($e) {
            return filter_var($e, FILTER_VALIDATE_EMAIL);
        })));
        $notifyRecipientsJson = !empty($recipientList) ? json_encode($recipientList) : null;
        $notify_toggles = [
            'mention' => !empty($_POST['notify_mention']),
            'partner' => !empty($_POST['notify_partner']),
            'ip_ban'  => !empty($_POST['notify_ip_ban']),
            'twofa'   => !empty($_POST['notify_twofa']),
            'lock'    => !empty($_POST['notify_lock']),
            'contact' => !empty($_POST['notify_contact']),
        ];
        $togglesJson = json_encode($notify_toggles);
        $pdo->prepare('UPDATE setting SET notify_recipients = :r, notify_toggles = :t WHERE id = 1')->execute([
            'r' => $notifyRecipientsJson,
            't' => $togglesJson
        ]);
        $notify_recipients_raw = $notifyRecipientsJson;
        addToast('success', 'Paramètres de notification enregistrés');
    }

    // Newsletter — suppression manuelle d'un abonné
    if (isset($_POST['delete_newsletter_subscriber']) && $canNewsletter) {
        $delEmail = mb_strtolower(trim($_POST['newsletter_email'] ?? ''));
        if ($delEmail !== '') {
            try {
                $stmt = $pdo->prepare('DELETE FROM newsletter_subscribers WHERE email = :e');
                $stmt->execute(['e' => $delEmail]);
                if ($stmt->rowCount() > 0) {
                    addToast('success', 'Abonné supprimé : ' . htmlspecialchars($delEmail));
                    // Recharger la liste pour refléter la suppression immédiatement
                    try {
                        $newsletterSubscribers = $pdo->query(
                            "SELECT email, status, created_at, unsubscribed_at
                               FROM newsletter_subscribers
                              ORDER BY (status = 'subscribed') DESC, created_at DESC"
                        )->fetchAll(PDO::FETCH_ASSOC);
                    } catch (\Throwable $e) {}
                } else {
                    addToast('warning', 'Abonné introuvable');
                }
            } catch (\Throwable $e) {
                addToast('danger', 'Erreur lors de la suppression');
            }
        }
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
                    $result = sendMail($email, 'Mail de test - Forbach en Rose', 'Test réussi !', 'Ce mail de test confirme que la configuration email fonctionne correctement.', null, null, 'info', null, 'test');
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
    // render() vit dans googleMail.php — pas chargé si le provider mail est "smtp"
    // (le bloc OAuth plus haut ne s'exécute que pour Google). On le charge ici
    // pour que l'aperçu fonctionne quel que soit le provider.
    if (!function_exists('render')) {
        try { require_once __DIR__ . '/../src/mail/googleMail.php'; } catch (\Throwable $e) {}
    }
    if (!function_exists('render')) {
        http_response_code(500);
        echo '<p style="font-family:sans-serif;padding:20px;color:#b91c1c;">Aperçu indisponible : moteur de rendu introuvable.</p>';
        exit;
    }
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
        'mail_subtype'   => (strpos($previewType, 'notif_') === 0) ? 'test' : $previewType,
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
        case 'bulk_recap':
            // Récap d'inscription groupée (inscription multiple depuis le dashboard /
            // register groupé) : liste des membres + UNIQUE QR « groupé ». Le vrai envoi
            // joint ce QR si l'envoi QR est activé (il liste tous les inscrits au scan).
            $recapRows = '';
            foreach ([['S1','Dupont','Marie'],['S2','Dupont','Paul'],['S3','Martin','Léa']] as $rr) {
                $recapRows .= '<tr>'
                    . '<td style="padding:8px;border:1px solid #fbcfe8;font-family:monospace;">'.$rr[0].'</td>'
                    . '<td style="padding:8px;border:1px solid #fbcfe8;">'.$rr[1].'</td>'
                    . '<td style="padding:8px;border:1px solid #fbcfe8;">'.$rr[2].'</td></tr>';
            }
            $vars += [
                'type' => 'info',
                'mailTitle' => 'Inscription groupée confirmée',
                'description' => '<p>Bonjour,</p><p>Nous confirmons l\'inscription des <strong>3</strong> personne(s) ci-dessous.</p>'
                    . '<table style="width:100%;border-collapse:collapse;margin-top:12px;font-size:14px;"><thead><tr style="background: #fdf2f8;">'
                    . '<th style="text-align:left;padding:8px;border:1px solid #fbcfe8;">N° inscription</th>'
                    . '<th style="text-align:left;padding:8px;border:1px solid #fbcfe8;">Nom</th>'
                    . '<th style="text-align:left;padding:8px;border:1px solid #fbcfe8;">Prénom</th>'
                    . '</tr></thead><tbody>'.$recapRows.'</tbody></table>',
                'firstname' => null, 'lastname' => null, 'date' => null,
                'qrcode' => $qrDataUri, 'inscription_no' => null,
            ];
            break;
        case 'test':
            $vars += [
                'type' => 'info',
                'mailTitle' => 'Test réussi !',
                'description' => 'Ce mail de test confirme que la configuration email fonctionne correctement.',
                'firstname' => null, 'lastname' => null, 'date' => null,
                'qrcode' => '', 'inscription_no' => null,
            ];
            break;
        case 'new_article':
            // Aperçu du mail envoyé aux abonnés newsletter à la publication d'un
            // article. Le corps est construit par le MÊME code que l'envoi réel
            // (newsletterArticleMailBody) → l'aperçu reflète exactement l'envoi.
            $vars += [
                'type' => 'info',
                'mailTitle' => '', // le titre figure déjà dans le corps de l'article
                'description' => function_exists('newsletterArticleMailBody')
                    ? newsletterArticleMailBody([
                        'id' => 1,
                        'title_article' => 'Forbach en Rose 2026 : les inscriptions sont ouvertes !',
                        'desc_article' => "<p>La nouvelle édition de la course caritative Forbach en Rose se "
                            . "prépare. Rejoignez-nous pour une journée solidaire au profit de la lutte contre "
                            . "le cancer du sein : parcours, animations, village partenaires… Retrouvez toutes "
                            . "les informations pratiques et le lien d'inscription dans l'article complet.</p>",
                        'img_article' => '',
                      ])
                    : '<p>Aperçu indisponible.</p>',
                'firstname' => null, 'lastname' => null, 'date' => null,
                'qrcode' => '', 'inscription_no' => null,
            ];
            break;

        // ── Notifications admin ──
        case 'notif_mention':
            $baseUrl = getAppBaseUrl();
            $vars += [
                'type' => 'info',
                'mailTitle' => 'Mention @forbachenrose',
                'description' => '<p>Bonjour,</p>'
                    . '<p><strong>Marie Martin</strong> vous a mentionné dans un commentaire sur votre site.</p>'
                    . '<p style="padding:14px 18px;background:#f8fafc;border-radius:8px;border-left:3px solid #F42182;font-style:italic;color: #475569;margin:16px 0;">'
                    . 'Bravo <span style="color:#F42182;font-weight:600;">@forbachenrose</span> pour cette belle initiative ! '
                    . 'Hate d\'y participer cette annee, j\'ai deja convaincu toute mon equipe de venir courir avec nous. '
                    . 'On sera la en force !</p>'
                    . '<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;">'
                    . '<tr><td style="padding:12px 16px;background:#fdf2f8;border-radius:8px;">'
                    . '<p style="margin:0 0 4px;font-size:12px;color: #64748b;text-transform:uppercase;letter-spacing:.5px;">Article concerne</p>'
                    . '<p style="margin:0;font-weight:600;color: #0f172a;">Course caritative 2025 — Toutes les infos pratiques</p>'
                    . '<p style="margin:4px 0 0;font-size:12px;color: #64748b;">Publie le 06/04/2025 — 3 commentaires</p>'
                    . '</td></tr></table>'
                    . '<p style="text-align:center;margin:20px 0 0;"><a href="' . htmlspecialchars($baseUrl) . '/public/news?id=1" style="display:inline-block;padding:12px 32px;background:#F42182;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">Voir le commentaire</a></p>',
                'firstname' => null, 'lastname' => null, 'date' => null,
                'qrcode' => '', 'inscription_no' => null,
            ];
            break;
        case 'notif_partner':
            $vars += [
                'type' => 'info',
                'mailTitle' => 'Nouvelle demande de partenariat',
                'description' => '<p>Bonjour,</p>'
                    . '<p>Une nouvelle demande de partenariat a ete soumise sur le site Forbach en Rose.</p>'
                    . '<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;">'
                    . '<tr><td style="padding:14px 18px;background:#f8fafc;border-radius:8px;">'
                    . '<p style="margin:0 0 8px;font-size:13px;"><strong>Email :</strong> contact@pharmacie-forbach.fr</p>'
                    . '<p style="margin:0 0 8px;font-size:13px;"><strong>Domaine :</strong> pharmacie-forbach.fr</p>'
                    . '<p style="margin:0;font-size:13px;"><strong>Date de la demande :</strong> ' . date('d/m/Y à H:i') . '</p>'
                    . '</td></tr></table>'
                    . '<p>Vous pouvez consulter et gerer les demandes de partenariat depuis l\'espace d\'administration du site.</p>'
                    . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">'
                    . '<p style="color: #64748b;font-size:12px;margin:0;">Notification automatique — Forbach en Rose</p>',
                'firstname' => null, 'lastname' => null, 'date' => null,
                'qrcode' => '', 'inscription_no' => null,
            ];
            break;
        case 'notif_ip_ban':
            $vars += [
                'type' => 'info',
                'mailTitle' => 'IP bannie automatiquement',
                'description' => '<p>Bonjour,</p>'
                    . '<p>Une adresse IP a ete <strong>bannie automatiquement</strong> par le systeme de securite du site.</p>'
                    . '<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;">'
                    . '<tr><td style="padding:14px 18px;background: #fef2f2;border-radius:8px;border-left:3px solid #ef4444;">'
                    . '<p style="margin:0 0 8px;font-size:13px;"><strong>Adresse IP :</strong> 192.168.1.42</p>'
                    . '<p style="margin:0 0 8px;font-size:13px;"><strong>Duree du ban :</strong> 24 heures</p>'
                    . '<p style="margin:0;font-size:13px;"><strong>Raison :</strong> Trop de tentatives de connexion echouees (10 en 30 minutes)</p>'
                    . '</td></tr></table>'
                    . '<p>Le ban expirera automatiquement a la fin de la duree indiquee. Si necessaire, vous pouvez lever ce ban manuellement depuis la page d\'administration des utilisateurs.</p>'
                    . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">'
                    . '<p style="color: #64748b;font-size:12px;margin:0;">Notification de securite — Forbach en Rose</p>',
                'firstname' => null, 'lastname' => null, 'date' => null,
                'qrcode' => '', 'inscription_no' => null,
            ];
            break;
        case 'notif_twofa':
            $vars += [
                'type' => 'info',
                'mailTitle' => 'Connexion sans 2FA',
                'description' => '<p>Bonjour,</p>'
                    . '<p>Un utilisateur s\'est connecte <strong>sans verification a deux facteurs</strong> car l\'envoi du code par e-mail a echoue.</p>'
                    . '<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;">'
                    . '<tr><td style="padding:14px 18px;background: #fffbeb;border-radius:8px;border-left:3px solid #f59e0b;">'
                    . '<p style="margin:0 0 8px;font-size:13px;"><strong>Compte concerne :</strong> admin@forbachenrose.fr</p>'
                    . '<p style="margin:0;font-size:13px;"><strong>Date :</strong> ' . date('d/m/Y à H:i') . '</p>'
                    . '</td></tr></table>'
                    . '<p>Ce probleme peut indiquer un souci avec la configuration de l\'envoi de mails (Gmail ou SMTP). Nous vous recommandons de verifier les parametres dans l\'espace d\'administration, section <strong>Parametres mail</strong>.</p>'
                    . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">'
                    . '<p style="color: #64748b;font-size:12px;margin:0;">Alerte de securite — Forbach en Rose</p>',
                'firstname' => null, 'lastname' => null, 'date' => null,
                'qrcode' => '', 'inscription_no' => null,
            ];
            break;
        case 'notif_contact':
            $vars += [
                'type' => 'info',
                'mailTitle' => 'Nouveau message de contact',
                'description' => '<p>Bonjour,</p>'
                    . '<p>Un nouveau message a ete envoye depuis le <strong>formulaire de contact</strong> du site.</p>'
                    . '<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;">'
                    . '<tr><td style="padding:14px 18px;background:#f8fafc;border-radius:8px;border-left:3px solid #F42182;">'
                    . '<p style="margin:0 0 8px;font-size:13px;"><strong>Nom :</strong> Marie Martin</p>'
                    . '<p style="margin:0 0 8px;font-size:13px;"><strong>Email :</strong> marie.martin@exemple.fr</p>'
                    . '<p style="margin:0 0 8px;font-size:13px;"><strong>Sujet :</strong> Demande d\'informations</p>'
                    . '<p style="margin:0;font-size:13px;"><strong>Date :</strong> ' . date('d/m/Y à H:i') . '</p>'
                    . '</td></tr></table>'
                    . '<p style="padding:14px 18px;background:#fdf2f8;border-radius:8px;color: #475569;margin:16px 0;">'
                    . 'Bonjour, je souhaiterais obtenir plus d\'informations concernant votre prochaine course caritative. '
                    . 'Est-il possible de s\'inscrire en equipe ? Merci d\'avance pour votre retour.</p>'
                    . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">'
                    . '<p style="color: #64748b;font-size:12px;margin:0;">Notification automatique — Forbach en Rose</p>',
                'firstname' => null, 'lastname' => null, 'date' => null,
                'qrcode' => '', 'inscription_no' => null,
            ];
            break;
        case 'notif_lock':
            $vars += [
                'type' => 'info',
                'mailTitle' => 'Compte verrouille apres 3 tentatives',
                'description' => '<p>Bonjour,</p>'
                    . '<p>Un compte utilisateur a ete <strong>verrouille automatiquement</strong> apres 3 tentatives de connexion echouees consecutives.</p>'
                    . '<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;">'
                    . '<tr><td style="padding:14px 18px;background: #fef2f2;border-radius:8px;border-left:3px solid #ef4444;">'
                    . '<p style="margin:0 0 8px;font-size:13px;"><strong>Compte concerne :</strong> user@exemple.fr</p>'
                    . '<p style="margin:0 0 8px;font-size:13px;"><strong>Adresse IP :</strong> 192.168.1.42</p>'
                    . '<p style="margin:0;font-size:13px;"><strong>Date :</strong> ' . date('d/m/Y à H:i') . '</p>'
                    . '</td></tr></table>'
                    . '<p>L\'utilisateur devra utiliser la fonctionnalite <strong>"Mot de passe oublie"</strong> pour reactiver son compte. Vous pouvez egalement le debloquer manuellement depuis la gestion des utilisateurs.</p>'
                    . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">'
                    . '<p style="color: #64748b;font-size:12px;margin:0;">Alerte de securite — Forbach en Rose</p>',
                'firstname' => null, 'lastname' => null, 'date' => null,
                'qrcode' => '', 'inscription_no' => null,
            ];
            break;

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

    echo render(__DIR__ . '/../src/mail/mail_template.php', $vars);
    exit;
}

$activeSubTab = $_POST['active_subtab'] ?? ($_GET['pane'] ?? ($_GET['tab'] ?? 'envoi'));

// Forcer un onglet par défaut accessible à l'utilisateur :
//   - 'envoi'  → nécessite mail.send
//   - 'template' / 'google' / 'notifications' → nécessitent mail.write
$envoiAllowed     = $canSend;
$configAllowed    = $canWrite;
$tabPermAllowed = [
    'envoi'         => $envoiAllowed,
    'template'      => $configAllowed,
    'google'        => $configAllowed,
    'notifications' => $configAllowed,
    'newsletter'    => $canNewsletter,
];
if (empty($tabPermAllowed[$activeSubTab])) {
    // L'onglet demandé n'est pas autorisé : prendre le premier accessible
    foreach ($tabPermAllowed as $k => $allowed) {
        if ($allowed) { $activeSubTab = $k; break; }
    }
}
$navActiveTab = $activeSubTab; // repris par navbar-admin (sidebar + titre)
$activeMailView = $_POST['mail_view'] ?? ($mail_provider === 'google' ? 'google' : 'smtp');

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
    'visibility' => $mtcVisibility,
], JSON_UNESCAPED_UNICODE);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Paramètres mail</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Roboto:wght@400;500;700&family=Open+Sans:wght@400;600;700&family=Lato:wght@400;700&family=Montserrat:wght@400;600;700;900&display=swap" rel="stylesheet">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<script src="../js/tinymce/tinymce.min.js"></script>
</head>
<body>
<?php require __DIR__ . '/../src/partials/navbar-admin.php'; ?>
<link href="../css/gmail-settings.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">

<style>
/* ── Setting card (same as setting.php) ── */
.setting-card{background: var(--surface);border:1px solid var(--border);border-radius:12px;padding:24px}
.setting-card h2{font-size:18px;font-weight:700;color: var(--ink);margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border)}

/* ═══ Mail send styles ═══ */
.recipients-counter { font-size: 0.875rem; color: #6c757d; margin-top: 0.25rem; }
.select-all-btn { font-size: 0.8rem; padding: 0.2rem 0.5rem; }
#mailDescription { min-height: 300px; }
#selectedRecipients .badge { font-size: 0.8rem !important; }
#selectedRecipients .btn-close { padding: 0.2rem; font-size: 0.6rem; }
#selectedRecipients:empty::after { content: "Aucun destinataire sélectionné"; color: #6c757d; font-size: 0.875rem; font-style: italic; }
.email-search-container { position: relative; }
.email-suggestions { position: absolute; top: 100%; left: 0; right: 0; background: var(--surface); border: 1px solid var(--border); border-top: none; border-radius: 0 0 0.375rem 0.375rem; max-height: 200px; overflow-y: auto; z-index: 1050; display: none; }
.suggestion-item { padding: 0.5rem 0.75rem; cursor: pointer; border-bottom: 1px solid #eee; }
.suggestion-item:hover { background-color: var(--surface-2); }
.suggestion-item:last-child { border-bottom: none; }

.ed-pane{width:100%}
.ed-wrap{width:100%;flex:0 0 100%}

/* ── Layout ── */
.ed-wrap{position:relative;height:calc(100vh - 265px);min-height:540px;margin:8px 0 0;border-radius:12px;overflow:hidden;background:var(--card)}
.ed-sidebar{
  position:absolute;top:8px;left:8px;bottom:8px;z-index:20;
  width:300px;background: var(--surface);
  font-size:13px;overflow:hidden;
  border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.15);
}
.ed-sidebar #sidebar{padding:16px}
.ed-preview-area{
  position:absolute;top:0;left:0;right:0;bottom:0;
  background:var(--bg);overflow-y:auto;padding:32px 32px 32px 320px;
  display:flex;justify-content:center;align-items:flex-start;
  
}
/* Custom scrollbar */
.ed-preview-area::-webkit-scrollbar{width:8px}
.ed-preview-area::-webkit-scrollbar-track{background:transparent;margin:12px 0}
.ed-preview-area::-webkit-scrollbar-thumb{background:rgba(255,255,255,.3);border-radius:4px}
.ed-preview-area::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.5)}
.ed-sidebar #sidebar::-webkit-scrollbar{width:5px}
.ed-sidebar #sidebar::-webkit-scrollbar-track{background:transparent}
.ed-sidebar #sidebar::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
.ed-sidebar #sidebar::-webkit-scrollbar-thumb:hover{background:var(--border-strong)}
/* Settings tabs (sous-onglets) */
.settings-tabs{border-bottom:2px solid var(--border);margin-bottom:24px;gap:0}
.settings-tabs .nav-link{color: var(--ink);font-weight:500;font-size:14px;padding:10px 18px;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;border-radius:0;background:transparent;cursor:pointer;transition:.15s}
.settings-tabs .nav-link:hover{color: var(--ink);border-bottom-color: var(--ink-faint)}
.settings-tabs .nav-link.active{color: var(--ink);font-weight:600;border-bottom-color:var(--accent);background:transparent}

/* Sidebar tabs */
.sb-tabs{display:flex;border-bottom:2px solid var(--border);flex-shrink:0}
.sb-tab{
  flex:1;padding:10px 0;font-size:12px;font-weight:600;color: var(--ink-faint);
  background:transparent;border:none;border-bottom:2px solid transparent;
  margin-bottom:-2px;cursor:pointer;transition:.15s;
}
.sb-tab:hover{color: var(--ink-dim)}
.sb-tab.active{color:var(--accent);border-bottom-color:var(--accent)}
/* Preview buttons */
.preview-btn{
  padding:10px 14px;font-size:12px;border:1px solid var(--border);text-align:left;
  background: var(--surface);color: var(--ink-dim);border-radius:8px;cursor:pointer;transition:.15s;display:block;
}
.preview-btn:hover{border-color:var(--accent);background:var(--accent-soft)}
.preview-btn.loading{opacity:.6;pointer-events:none}

.ed-pane{display:none}.ed-pane.active{display:flex;flex-wrap:wrap}
#paneEnvoi.active{display:block}

/* ── Sidebar controls ── */
.sb-title{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color: var(--ink-faint);font-weight:700;margin:16px 0 8px;padding-top:8px;border-top:1px solid var(--surface-2)}
.sb-title:first-child{margin-top:0;border:0;padding:0}
.sb-row{display:flex;align-items:center;gap:8px;margin-bottom:6px}
.sb-row label{flex:1;color: var(--ink-dim);font-size:12px;white-space:nowrap}
.sb-row input[type="color"]{width:28px;height:28px;border:1px solid #e2e8f0;border-radius:6px;padding:1px;cursor:pointer;flex-shrink:0}
.sb-row select,.sb-row input[type="range"]{flex:1}
.sb-row .v{font-size:11px;color: var(--ink-faint);min-width:28px;text-align:right}
.sb-hint{font-size:11px;color: var(--ink-faint);margin-bottom:8px}
.sb-btn{padding:6px 12px;border-radius:8px;border:1px solid var(--border);background: var(--surface);font-size:12px;cursor:pointer;transition:.15s}
.sb-btn:hover{border-color:var(--accent);color:var(--accent)}
.sb-btn-danger{color:#ef4444;border-color:#fca5a5}
.sb-btn-danger:hover{background: color-mix(in srgb, var(--danger) 12%, var(--surface));border-color:#ef4444}
.sb-align{display:flex;gap:4px}
.sb-align button{flex:1;padding:4px;border:1px solid var(--border);border-radius:6px;background: var(--surface);cursor:pointer;font-size:14px}
.sb-align button.active{background: var(--accent-soft);border-color:var(--accent);color:var(--accent)}

/* ── Preview ── */
.prev-email{width:<?= $mtcCardWidth ?>px;max-width:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}

/* Sections */
.prev-sec{
  position:relative;cursor:pointer;transition:.12s;
  outline:2px solid transparent;outline-offset:2px;border-radius:4px;
}
.prev-sec:hover{outline-color:rgba(244,33,130,.35)}
.prev-sec.selected{outline-color:var(--accent);outline-offset:3px}
/* Header/Title/Footer: inside card table, outline gets clipped → use inset box-shadow */
#prevCard>.prev-sec,#prevCard tr>td.prev-sec{outline:none !important}
#prevCard tr>td.prev-sec:hover{box-shadow:inset 0 0 0 3px rgba(244,33,130,.35)}
#prevCard tr>td.prev-sec.selected{box-shadow:inset 0 0 0 3px var(--accent)}
.prev-sec .sec-actions{
  display:none;position:absolute;top:-14px;right:-8px;z-index:10;
  background: var(--surface);border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.15);
  padding:2px;gap:2px;
}
.prev-sec.selected .sec-actions,.prev-sec:hover .sec-actions{display:flex}
.sec-act{
  width:26px;height:26px;display:flex;align-items:center;justify-content:center;
  border:0;background:transparent;border-radius:6px;cursor:pointer;font-size:13px;transition:.1s;
}
.sec-act:hover{background:var(--surface-2)}
.sec-act.del:hover{background: color-mix(in srgb, var(--danger) 12%, var(--surface));color:#ef4444}

/* Add section button between sections */
.prev-add{
  display:flex;align-items:center;justify-content:center;
  height:20px;position:relative;z-index:5;margin:-4px 0;
}
.prev-add button{
  width:28px;height:28px;border-radius:50%;border:2px dashed var(--border-strong);
  background: var(--surface);color: var(--ink-faint);font-size:18px;cursor:pointer;transition:.15s;
  display:flex;align-items:center;justify-content:center;opacity:0;
}
.prev-add:hover button,.prev-add button:focus{opacity:1;border-color:var(--accent);color:var(--accent)}

/* Editable text */
[data-ed]{position:relative;transition:.12s;outline:1px dashed transparent;outline-offset:1px;cursor:pointer;min-height:1em}
[data-ed]:hover{outline-color:rgba(244,33,130,.4)}
[data-ed].editing{outline-color:var(--accent);outline-style:solid;cursor:text;background:color-mix(in srgb, var(--accent) 8%, transparent)}
/* Element inline actions (trash + arrows) */
.el-actions{
  position:absolute;top:-10px;right:4px;z-index:10;
  display:none;gap:2px;align-items:center;
  background: var(--surface);border-radius:10px;padding:1px 3px;
  box-shadow:0 1px 6px rgba(0,0,0,.15);
}
.el-act{
  width:18px;height:18px;border-radius:50%;border:0;
  background:transparent;color: var(--ink-faint);font-size:10px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  transition:.1s;line-height:1;padding:0;
}
.el-act:hover{background:var(--surface-2);color: var(--ink)}
.el-act.del:hover{background: color-mix(in srgb, var(--danger) 12%, var(--surface));color:#ef4444}
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
  border-radius:3px;position:absolute;top:-10px;left:0;white-space:nowrap;pointer-events:none;
  opacity:0;transition:.15s;z-index:11;
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
  .ed-wrap{height:auto;overflow:visible}
  .ed-sidebar{
    position:static;
    width:100%;min-width:0;
    max-height:50vh;overflow-y:auto;
    border-right:0;border-bottom:1px solid var(--border);
    border-radius:12px 12px 0 0;box-shadow:none;
  }
  .ed-preview-area{
    position:static;
    padding:16px;
    border-radius:0 0 12px 12px;
  }
  .prev-email{width:100%}
}
</style>

<h1 class="mb-3 fw-bold"><i class="bi bi-envelope me-2"></i>Paramètres mail</h1>

<?php if (!$canWrite && !$canSend && !$canNewsletter): ?>
  <!-- Accès en lecture seule : aucune section disponible -->
  <div class="text-center py-5 my-4" style="background: var(--surface);border-radius:12px;box-shadow:0 0 25px rgba(0,0,0,.05)">
    <i class="bi bi-shield-lock" style="font-size:4rem;color: var(--ink-faint)"></i>
    <h4 class="mt-3 mb-2 fw-bold">Accès en lecture seule</h4>
    <p class="text-muted mb-0 px-4" style="max-width:560px;margin:0 auto">
      Vous avez accès à cette page en consultation uniquement.<br>
      Les sections de configuration des emails nécessitent le droit
      <strong>« Modifier les paramètres mail »</strong> ou <strong>« Envoyer des mails »</strong>.<br>
      Contactez un administrateur pour obtenir une de ces permissions.
    </p>
  </div>
<?php else: ?>

<!-- ═══ TABS ═══ -->
<ul class="nav settings-tabs" id="mailSettingsTabs">
  <?php if ($canSend): ?>
  <li class="nav-item"><a class="nav-link <?= $activeSubTab==='envoi'?'active':'' ?>" href="#" data-pane="paneEnvoi">Envoi de mail</a></li>
  <?php endif; ?>
  <?php if ($canWrite): ?>
  <li class="nav-item"><a class="nav-link <?= $activeSubTab==='template'?'active':'' ?>" href="#" data-pane="paneTemplate">Template email</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeSubTab==='google'?'active':'' ?>" href="#" data-pane="paneGoogle">Google / Email</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeSubTab==='notifications'?'active':'' ?>" href="#" data-pane="paneNotifications">Notifications</a></li>
  <?php endif; ?>
  <?php if ($canNewsletter): ?>
  <li class="nav-item"><a class="nav-link <?= $activeSubTab==='newsletter'?'active':'' ?>" href="#" data-pane="paneNewsletter">Abonnés newsletter</a></li>
  <?php endif; ?>
</ul>

<!-- ═══ ENVOI DE MAIL PANE (mail.send) ═══ -->
<?php if ($canSend): ?>
<div class="ed-pane <?= $activeSubTab==='envoi'?'active':'' ?>" id="paneEnvoi">
<div class="bg-white p-4" style="border-radius:12px;">
  <form id="fMail">
    <div class="row g-3">
      <!-- Destinataires -->
      <div class="col-12">
        <label for="mailRecipients" class="form-label">
          <i class="bi bi-people"></i> Destinataires
        </label>
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div>
            <button type="button" class="btn btn-outline-primary select-all-btn" id="selectAllBtn">
              Tout sélectionner
            </button>
            <button type="button" class="btn btn-outline-secondary select-all-btn" id="clearAllBtn">
              Tout désélectionner
            </button>
            <button type="button" class="btn btn-outline-success select-all-btn" id="newsletterBtn"
                    title="Sélectionner uniquement les abonnés à la newsletter">
              <i class="bi bi-envelope-heart"></i> Newsletter (<?= count($newsletterEmails) ?>)
            </button>
          </div>
        </div>

        <!-- Zone de recherche unique -->
        <div class="email-search-container">
            <input type="text" id="emailSearchInput" class="form-control" placeholder="Tapez un nom, prénom ou email (ou plusieurs emails séparés par des virgules) puis appuyez sur Entrée">
          <div id="emailSuggestions" class="email-suggestions"></div>
        </div>

        <!-- Zone d'affichage des destinataires sélectionnés -->
        <div id="selectedRecipients" class="border rounded p-2 bg-light mt-3" style="min-height: 120px; max-height: 200px; overflow-y: auto;">
          <small class="text-muted">Aucun destinataire sélectionné</small>
        </div>

        <div class="recipients-counter mt-2" id="recipientsCounter">
          0 destinataire(s) sélectionné(s)
        </div>

        <!-- Input caché pour stocker les emails sélectionnés -->
        <input type="hidden" name="recipients" id="hiddenRecipients">
      </div>

      <!-- Objet du mail -->
      <div class="col-12">
        <label for="mailSubject" class="form-label">
          <i class="bi bi-tag"></i> Objet du mail
        </label>
        <input type="text" name="subject" id="mailSubject" class="form-control"
               placeholder="Objet de votre mail" required maxlength="255">
      </div>

      <!-- Titre du contenu -->
      <div class="col-12">
        <label for="mailTitle" class="form-label">
          <i class="bi bi-type-h1"></i> Titre du contenu
        </label>
        <input type="text" name="mail_title" id="mailTitle" class="form-control"
               placeholder="Titre qui apparaîtra dans le mail" maxlength="255">
        <small class="form-text text-muted">
          Ce titre sera affiché en tant que titre principal dans le contenu du mail
        </small>
      </div>

      <!-- Description avec éditeur de texte enrichi -->
      <div class="col-12">
        <label for="mailDescription" class="form-label">
          <i class="bi bi-file-text"></i> Contenu du mail
        </label>

        <textarea name="description" id="mailDescription" class="form-control">
        </textarea>
        <small class="form-text text-muted">
          Utilisez l'éditeur pour formater votre message avec du texte en gras, des couleurs, des listes, etc.
        </small>
      </div>
    </div>

    <div class="mt-3">
      <button type="submit" class="btn btn-success">
        <i class="bi bi-send"></i> Envoyer le mail
      </button>
    </div>
  </form>
</div>
</div><!-- /paneEnvoi -->
<?php endif; // $canSend ?>

<!-- ═══ TEMPLATE PANE (mail.write) ═══ -->
<?php if ($canWrite): ?>
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
    <div id="sbSection" style="display:none;margin-top:16px;padding-top:16px;border-top:2px solid var(--border)">
      <p class="sb-title" style="border:0;padding:0"><span id="sbSecName"></span></p>
      <div id="sbSecColors"></div>
      <div id="sbSecDeleteWrap" style="margin-top:8px">
        <button type="button" class="sb-btn sb-btn-danger" id="sbSecDelete">Supprimer cette section</button>
      </div>
    </div>

    <!-- Contextual: text formatting (shown below global when a text is clicked) -->
    <div id="sbText" style="display:none;margin-top:16px;padding-top:16px;border-top:2px solid var(--border)">
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
      <p style="font-size:13px;color: var(--ink-dim);margin:0 0 16px;line-height:1.5">Prévisualisation réelle du mail tel qu'il sera envoyé, avec tous les paramètres actuels.</p>
      <button type="button" class="preview-btn" data-preview="inscription" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Inscription</span><br><span style="font-size:11px;color: var(--ink-faint)">Confirmation avec QR code si activé</span>
      </button>
      <button type="button" class="preview-btn" data-preview="code" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Code de connexion</span><br><span style="font-size:11px;color: var(--ink-faint)">Vérification 2FA</span>
      </button>
      <button type="button" class="preview-btn" data-preview="new_user" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Nouveau compte</span><br><span style="font-size:11px;color: var(--ink-faint)">Mot de passe temporaire</span>
      </button>
      <button type="button" class="preview-btn" data-preview="bulk" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Envoi groupé</span><br><span style="font-size:11px;color: var(--ink-faint)">Mail personnalisé (depuis utilisateurs)</span>
      </button>
      <button type="button" class="preview-btn" data-preview="bulk_recap" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Récap groupé</span><br><span style="font-size:11px;color: var(--ink-faint)">Inscription multiple — avec QR groupé</span>
      </button>
      <button type="button" class="preview-btn" data-preview="test" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Mail test</span><br><span style="font-size:11px;color: var(--ink-faint)">Test simple de configuration</span>
      </button>
      <button type="button" class="preview-btn" data-preview="new_article" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Nouvel article (newsletter)</span><br><span style="font-size:11px;color: var(--ink-faint)">Mail envoyé aux abonnés à la publication</span>
      </button>
      <hr style="margin:16px 0;border-color:var(--border)">
      <p style="font-size:12px;font-weight:700;color: var(--ink-dim);text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px"><i class="bi bi-bell me-1"></i>Notifications admin</p>
      <button type="button" class="preview-btn" data-preview="notif_mention" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Mention @forbachenrose</span><br><span style="font-size:11px;color: var(--ink-faint)">Identification dans un commentaire</span>
      </button>
      <button type="button" class="preview-btn" data-preview="notif_partner" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Demande de partenariat</span><br><span style="font-size:11px;color: var(--ink-faint)">Nouvelle demande entreprise</span>
      </button>
      <button type="button" class="preview-btn" data-preview="notif_ip_ban" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Ban IP automatique</span><br><span style="font-size:11px;color: var(--ink-faint)">IP bannie apres tentatives</span>
      </button>
      <button type="button" class="preview-btn" data-preview="notif_twofa" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Echec 2FA</span><br><span style="font-size:11px;color: var(--ink-faint)">Connexion sans verification</span>
      </button>
      <button type="button" class="preview-btn" data-preview="notif_lock" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Verrouillage compte</span><br><span style="font-size:11px;color: var(--ink-faint)">3 tentatives echouees</span>
      </button>
      <button type="button" class="preview-btn" data-preview="notif_contact" style="width:100%;margin-bottom:8px">
        <span style="font-weight:600">Demande de contact</span><br><span style="font-size:11px;color: var(--ink-faint)">Message du formulaire contact</span>
      </button>
      <button type="button" class="preview-btn" data-preview="editor" style="width:100%;margin-top:8px;background:var(--surface-2);font-weight:600">
        &#8592; Retour éditeur
      </button>
    </div>

    </div><!-- /sidebar -->
    <div style="padding:12px 16px;border-top:1px solid var(--border);background: var(--surface);flex-shrink:0;border-radius:0 0 12px 12px;text-align:center">
      <button type="submit" name="save_mail_template" class="btn btn-primary w-auto" style="padding:8px 32px;font-size:13px;font-weight:600;white-space:nowrap">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px;margin-right:3px"><path d="M19 21 H5 A2 2 0 0 1 3 19 V5 A2 2 0 0 1 5 3 H16 L21 8 V19 A2 2 0 0 1 19 21 Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Sauvegarder
      </button>
    </div>
  </form>

  <!-- ── Preview ── -->
  <div class="ed-preview-area" id="previewArea">
    <iframe id="previewIframe" style="display:none;width:100%;height:100%;border:0;background: var(--surface);border-radius:8px"></iframe>
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
            <h2 data-dyn="prénom" style="font-size:22px;font-weight:700;color: #0f172a;margin:0 0 8px">Bienvenue Jean !</h2>
            <p data-ed="title_subtitle" style="font-size:15px;color: #475569;margin:0;line-height:1.6"><?= str_replace("\n", '<br>', htmlspecialchars($mtcTexts['title_subtitle'])) ?></p>
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
                    <div data-dyn="nom du participant" style="font-size:16px;color: #0f172a;font-weight:600;margin-top:4px">DUPONT Jean</div>
                  </td></tr>
                  <tr><td data-acc="left" style="padding:18px 24px;background:#f8fafc;border-bottom:1px solid #f1f5f9;border-left:3px solid <?= $mtcColors['accent'] ?>">
                    <div data-acc="txt" data-ed="label_date" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:<?= $mtcColors['accent'] ?>"><?= htmlspecialchars($mtcTexts['label_date']) ?></div>
                    <div data-dyn="date de la course" style="font-size:16px;color: #0f172a;font-weight:600;margin-top:4px">Dimanche 5 octobre 2025</div>
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
                    <div style="display:flex;align-items:baseline;gap:0;margin:0 0 4px"><span data-acc="txt" style="font-weight:700;margin-right:8px;color:<?= $mtcColors['accent'] ?>;flex-shrink:0">&#9656;</span><span data-ed="tips_1" style="font-size:14px;color:rgba(255,255,255,.75);line-height:1.8"><?= htmlspecialchars($mtcTexts['tips_1']) ?></span></div>
                    <div style="display:flex;align-items:baseline;gap:0;margin:0 0 4px"><span data-acc="txt" style="font-weight:700;margin-right:8px;color:<?= $mtcColors['accent'] ?>;flex-shrink:0">&#9656;</span><span data-ed="tips_2" style="font-size:14px;color:rgba(255,255,255,.75);line-height:1.8"><?= htmlspecialchars($mtcTexts['tips_2']) ?></span></div>
                    <div style="display:flex;align-items:baseline;gap:0"><span data-acc="txt" style="font-weight:700;margin-right:8px;color:<?= $mtcColors['accent'] ?>;flex-shrink:0">&#9656;</span><span data-ed="tips_3" style="font-size:14px;color:rgba(255,255,255,.75);line-height:1.8"><?= htmlspecialchars($mtcTexts['tips_3']) ?></span></div>
                  </td></tr>
                </table>
<?php elseif($sec==='description'): ?>
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px">
                  <tr><td class="sec-r" data-acc="left" style="padding:24px;background:#f8fafc;border-left:3px solid <?= $mtcColors['accent'] ?>">
                    <div data-dyn="contenu du mail" style="font-size:15px;line-height:1.7;color: #475569">Votre message personnalisé ici...</div>
                  </td></tr>
                </table>
<?php elseif($sec==='qrcode'): ?>
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px">
                  <tr><td class="sec-r" data-acc="border" style="padding:28px;background:#fff;text-align:center;border:2px dashed <?= $mtcColors['accent'] ?>">
                    <p data-acc="txt" data-ed="qrcode_title" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin:0 0 6px;color:<?= $mtcColors['accent'] ?>"><?= htmlspecialchars($mtcTexts['qrcode_title']) ?></p>
                    <p style="font-size:13px;color: #475569;margin:0 0 4px"><span data-dyn="n° inscription">Billet n° 42</span></p>
                    <p data-ed="qrcode_subtitle" style="font-size:13px;color:#64748b;margin:0 0 16px"><?= htmlspecialchars($mtcTexts['qrcode_subtitle']) ?></p>
                    <div style="width:120px;height:120px;background:#f1f5f9;border-radius:8px;margin:0 auto;display:flex;align-items:center;justify-content:center;color: #64748b;font-size:11px">QR</div>
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
                    <p data-dyn="email de contact" style="font-size:14px;color: #475569;margin:0">contact@forbachenrose.fr</p>
                  </td></tr>
                </table>
<?php elseif(strpos($sec,'custom')===0): ?>
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px">
                  <tr><td class="sec-r" style="padding:24px;background:#f1f5f9;text-align:center">
<?php
  // Render all texts for this custom section (key starts with section id)
  $rendered = false;
  foreach ($mtcTexts as $tKey => $tVal) {
      if (strpos($tKey, $sec.'_') === 0) {
          echo '<div data-ed="'.htmlspecialchars($tKey).'" style="font-size:15px;line-height:1.7;color:#334155;margin-bottom:8px">'.htmlspecialchars($tVal).'</div>';
          $rendered = true;
      }
  }
  if (!$rendered) {
      echo '<div data-ed="'.htmlspecialchars($sec).'_text" style="font-size:15px;line-height:1.7;color:#334155">Texte personnalisé...</div>';
  }
?>
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


</div><!-- /ed-wrap -->

  <!-- ═══ Carte « QR Code dans les mails » (déplacée de Notifications, v2 : contenu des mails) ═══ -->
  <div class="row g-4 mt-2" style="flex:0 0 100%">
    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2><i class="bi bi-qr-code me-2"></i>QR Code dans les mails</h2>
        <p class="text-muted mb-3">Inclure un QR Code dans le mail de confirmation d'inscription. Le participant pourra le presenter le jour de l'evenement pour etre identifie ou recuperer son t-shirt.</p>
        <form action="" method="post" class="row g-3">
          <?= csrf_field() ?><input type="hidden" name="active_subtab" value="template">
          <div class="col-12">
            <label class="form-label fw-semibold">Mode d'inclusion</label>
            <select class="form-select" name="qrcode_mail_mode">
              <option value="none" <?= $qrcode_mail_mode==='none'?'selected':'' ?>>Aucun — pas de QR Code</option>
              <option value="all" <?= $qrcode_mail_mode==='all'?'selected':'' ?>>Pour tous les inscrits</option>
              <option value="first_x" <?= $qrcode_mail_mode==='first_x'?'selected':'' ?>>Pour les X premiers inscrits</option>
            </select>
          </div>
          <div class="col-12 text-end"><button type="submit" name="save_qrcode_config" class="btn btn-primary w-auto">Sauvegarder</button></div>
        </form>
      </div>
    </div>
  </div>
</div><!-- /paneTemplate -->

<!-- ═══ GOOGLE / SMTP PANE ═══ -->
<div class="ed-pane <?= $activeSubTab==='google'?'active':'' ?>" id="paneGoogle" style="display:<?= $activeSubTab==='google'?'block':'none' ?>">
  <div class="row g-4">

    <!-- ── Provider switch (affichage seulement, pas de submit) ── -->
    <div class="col-12">
      <div class="setting-card">
        <h2>
          <i class="bi bi-envelope-gear me-2"></i>Fournisseur d'envoi
          <span class="badge <?= $mail_provider==='google'?'bg-danger':'bg-primary' ?>" style="font-size:13px;vertical-align:middle;margin-left:8px">
            <i class="bi <?= $mail_provider==='google'?'bi-google':'bi-server' ?> me-1"></i>
            Actif : <?= $mail_provider==='google'?'Gmail':'SMTP' ?>
          </span>
        </h2>
        <div class="seg2" role="radiogroup" aria-label="Fournisseur d'envoi">
          <input type="radio" name="prov_view" value="google" id="provViewGoogle" <?= $activeMailView==='google'?'checked':'' ?>>
          <label for="provViewGoogle"><i class="bi bi-google me-1"></i>Gmail (OAuth)</label>
          <input type="radio" name="prov_view" value="smtp" id="provViewSmtp" <?= $activeMailView==='smtp'?'checked':'' ?>>
          <label for="provViewSmtp"><i class="bi bi-server me-1"></i>Serveur SMTP</label>
        </div>
      </div>
    </div>

    <!-- ══ GMAIL CONFIG ══ -->
    <div class="col-12 col-lg-6 panel-gmail" <?= $activeMailView!=='google'?'style="display:none"':'' ?>>
      <div class="setting-card">
        <h2><i class="bi bi-google me-2"></i>Param&egrave;tres Gmail</h2>
        <form action="" method="post" class="row g-3">
          <?= csrf_field() ?><input type="hidden" name="active_subtab" value="google"><input type="hidden" name="mail_view" value="google">
          <div class="col-12"><label class="form-label">Client ID</label><input type="text" class="form-control" name="client_id" value="<?= htmlspecialchars($client_id) ?>"></div>
          <div class="col-12"><label class="form-label">Client Secret</label><input type="text" class="form-control" name="client_secret" value="<?= htmlspecialchars($client_secret) ?>"></div>
          <div class="col-12 text-end"><button type="submit" name="google" class="btn btn-primary w-auto">Sauvegarder</button></div>
        </form>
      </div>
    </div>

    <!-- Connexion Google Status -->
    <div class="col-12 col-lg-6 panel-gmail" <?= $activeMailView!=='google'?'style="display:none"':'' ?>>
      <div class="setting-card">
        <h2><i class="bi bi-plug me-2"></i>Connexion Google</h2>
        <div class="p-3 rounded mb-3 <?= $isConnected?'bg-success-subtle':'bg-danger-subtle' ?>">
          <strong>Statut :</strong> <?= $isConnected?'Connect&eacute; &agrave; Gmail':'Non connect&eacute;' ?>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <?php if($isConnected): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="active_subtab" value="google"><input type="hidden" name="mail_view" value="google"><input type="hidden" name="action" value="test_connection"><button type="submit" class="btn btn-success w-auto">Tester</button></form>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="active_subtab" value="google"><input type="hidden" name="mail_view" value="google"><input type="hidden" name="action" value="send_test_mail"><button type="submit" class="btn btn-primary w-auto">Mail test</button></form>
            <form method="post" style="display:inline" data-confirm="D&eacute;connecter ?"><?= csrf_field() ?><input type="hidden" name="active_subtab" value="google"><input type="hidden" name="mail_view" value="google"><input type="hidden" name="action" value="disconnect"><button type="submit" class="btn btn-danger w-auto">D&eacute;connecter</button></form>
            <?php if($mail_provider !== 'google'): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="active_subtab" value="google"><input type="hidden" name="mail_view" value="google"><input type="hidden" name="mail_provider" value="google"><input type="hidden" name="switch_provider" value="1"><button type="submit" class="btn btn-warning w-auto"><i class="bi bi-check-circle me-1"></i>Passer Gmail en actif</button></form>
            <?php endif; ?>
          <?php else: ?>
            <a href="<?= htmlspecialchars($authUrl) ?>" class="btn btn-primary w-auto">Se connecter avec Google</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ══ SMTP CONFIG ══ -->
    <div class="col-12 col-lg-6 panel-smtp" <?= $activeMailView!=='smtp'?'style="display:none"':'' ?>>
      <div class="setting-card">
        <h2><i class="bi bi-server me-2"></i>Param&egrave;tres SMTP</h2>
        <form action="" method="post" class="row g-3">
          <?= csrf_field() ?><input type="hidden" name="active_subtab" value="google"><input type="hidden" name="mail_view" value="smtp">
          <input type="hidden" name="mail_provider" value="<?= htmlspecialchars($mail_provider) ?>">
          <div class="col-12">
            <label class="form-label">Serveur SMTP</label>
            <input type="text" class="form-control" name="smtp_host" value="<?= htmlspecialchars($smtp_host) ?>" placeholder="node141-eu.n0c.com">
          </div>
          <div class="col-6">
            <label class="form-label">Port sortant (SMTP)</label>
            <input type="number" class="form-control" name="smtp_port" value="<?= (int)$smtp_port ?>" placeholder="465">
            <div class="form-text">465 (SSL) ou 587 (TLS). Ne pas utiliser les ports entrants (993/995).</div>
          </div>
          <div class="col-6">
            <label class="form-label">Chiffrement</label>
            <select class="form-select" name="smtp_encryption">
              <option value="ssl" <?= $smtp_encryption==='ssl'?'selected':'' ?>>SSL (port 465)</option>
              <option value="tls" <?= $smtp_encryption==='tls'?'selected':'' ?>>TLS / STARTTLS (port 587)</option>
              <option value="none" <?= $smtp_encryption==='none'?'selected':'' ?>>Aucun</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Nom d'utilisateur</label>
            <input type="text" class="form-control" name="smtp_user" value="<?= htmlspecialchars($smtp_user) ?>" placeholder="no_reply@forbachenrose.com">
          </div>
          <div class="col-12">
            <label class="form-label">Mot de passe</label>
            <input type="password" class="form-control" name="smtp_pass" value="" placeholder="<?= $smtp_pass ? '********' : 'Mot de passe du compte' ?>">
            <?php if($smtp_pass): ?><div class="form-text text-success"><i class="bi bi-check-circle"></i> Mot de passe enregistr&eacute; (laisser vide pour ne pas changer)</div><?php endif; ?>
          </div>
          <div class="col-12">
            <label class="form-label">Email exp&eacute;diteur (From)</label>
            <input type="email" class="form-control" name="smtp_from_email" value="<?= htmlspecialchars($smtp_from_email) ?>" placeholder="no_reply@forbachenrose.com">
          </div>
          <div class="col-12">
            <label class="form-label">Nom exp&eacute;diteur</label>
            <input type="text" class="form-control" name="smtp_from_name" value="<?= htmlspecialchars($smtp_from_name) ?>" placeholder="Forbach en Rose">
          </div>
          <div class="col-12 text-end"><button type="submit" name="save_smtp" class="btn btn-primary w-auto">Sauvegarder</button></div>
        </form>
      </div>
    </div>

    <!-- SMTP Test + Activation -->
    <div class="col-12 col-lg-6 panel-smtp" <?= $activeMailView!=='smtp'?'style="display:none"':'' ?>>
      <div class="setting-card">
        <h2><i class="bi bi-lightning me-2"></i>Connexion SMTP</h2>
        <?php $smtpConfigured = !empty($smtp_host) && !empty($smtp_user) && !empty($smtp_pass); ?>
        <div class="p-3 rounded mb-3 <?= $smtpConfigured?'bg-success-subtle':'bg-warning-subtle' ?>">
          <strong>Statut :</strong> <?= $smtpConfigured ? 'Connect&eacute; &agrave; <strong>' . htmlspecialchars($smtp_host) . ':' . (int)$smtp_port . '</strong> (' . htmlspecialchars($smtp_encryption) . ') &mdash; ' . htmlspecialchars($smtp_from_email) : 'Non configur&eacute; (remplissez les champs SMTP)' ?>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <?php if($smtpConfigured): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="active_subtab" value="google"><input type="hidden" name="mail_view" value="smtp">
            <input type="hidden" name="action" value="send_test_smtp">
            <button type="submit" class="btn btn-primary w-auto"><i class="bi bi-send me-1"></i>Envoyer un mail test</button>
          </form>
          <?php if($mail_provider !== 'smtp'): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="active_subtab" value="google"><input type="hidden" name="mail_view" value="smtp">
            <input type="hidden" name="mail_provider" value="smtp"><input type="hidden" name="switch_provider" value="1">
            <button type="submit" class="btn btn-warning w-auto"><i class="bi bi-check-circle me-1"></i>Passer SMTP en actif</button>
          </form>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Coordonnées (template mail) -->
    <?php if($hasMailFields): ?>
    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2><i class="bi bi-envelope-at me-2"></i>Coordonn&eacute;es</h2>
        <form action="" method="post" class="row g-3">
          <?= csrf_field() ?><input type="hidden" name="active_subtab" value="google"><input type="hidden" name="mail_view" value="<?= htmlspecialchars($activeMailView) ?>">
          <div class="col-12"><label class="form-label">Email de contact</label><input type="email" class="form-control" name="mail_email" value="<?= htmlspecialchars($mail_email) ?>" placeholder="contact@forbachenrose.fr"></div>
          <div class="col-12"><label class="form-label">T&eacute;l&eacute;phone</label><input type="text" class="form-control" name="mail_phone" value="<?= htmlspecialchars($mail_phone) ?>"></div>
          <div class="col-12 text-end"><button type="submit" name="google" class="btn btn-primary w-auto">Sauvegarder</button></div>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- ═══ NOTIFICATIONS PANE ═══ -->
<div class="ed-pane <?= $activeSubTab==='notifications'?'active':'' ?>" id="paneNotifications" style="display:<?= $activeSubTab==='notifications'?'block':'none' ?>;">
  <div class="row g-4">
    <div class="col-12 col-lg-8">
      <div class="setting-card">
        <h2><i class="bi bi-bell me-2"></i>Notifications admin</h2>
        <p class="text-muted mb-3">Choisissez les notifications a envoyer et les destinataires.</p>
        <form action="" method="post" class="row g-3">
          <?= csrf_field() ?><input type="hidden" name="active_subtab" value="notifications">
          <div class="col-12">
            <div class="list-group">
              <label class="list-group-item d-flex align-items-center gap-3" style="cursor:pointer;">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" name="notify_mention" id="notify_mention" <?= !empty($notify_toggles['mention']) ? 'checked' : '' ?>>
                </div>
                <div>
                  <i class="bi bi-chat-left-dots text-primary me-1"></i>
                  <strong>Mention @forbachenrose</strong>
                  <small class="d-block text-muted">Quand @forbachenrose est identifie dans un commentaire</small>
                </div>
              </label>
              <label class="list-group-item d-flex align-items-center gap-3" style="cursor:pointer;">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" name="notify_partner" id="notify_partner" <?= !empty($notify_toggles['partner']) ? 'checked' : '' ?>>
                </div>
                <div>
                  <i class="bi bi-people text-primary me-1"></i>
                  <strong>Demande de partenariat</strong>
                  <small class="d-block text-muted">Quand une entreprise envoie une demande de partenariat</small>
                </div>
              </label>
              <label class="list-group-item d-flex align-items-center gap-3" style="cursor:pointer;">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" name="notify_ip_ban" id="notify_ip_ban" <?= !empty($notify_toggles['ip_ban']) ? 'checked' : '' ?>>
                </div>
                <div>
                  <i class="bi bi-shield-exclamation text-primary me-1"></i>
                  <strong>Bannissement automatique d'IP</strong>
                  <small class="d-block text-muted">Quand une IP est bannie suite a trop de tentatives</small>
                </div>
              </label>
              <label class="list-group-item d-flex align-items-center gap-3" style="cursor:pointer;">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" name="notify_twofa" id="notify_twofa" <?= !empty($notify_toggles['twofa']) ? 'checked' : '' ?>>
                </div>
                <div>
                  <i class="bi bi-key text-primary me-1"></i>
                  <strong>Echec d'envoi du code 2FA</strong>
                  <small class="d-block text-muted">Quand un utilisateur se connecte sans 2FA car le mail a echoue</small>
                </div>
              </label>
              <label class="list-group-item d-flex align-items-center gap-3" style="cursor:pointer;">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" name="notify_lock" id="notify_lock" <?= !empty($notify_toggles['lock']) ? 'checked' : '' ?>>
                </div>
                <div>
                  <i class="bi bi-lock text-primary me-1"></i>
                  <strong>Verrouillage de compte</strong>
                  <small class="d-block text-muted">Quand un compte est verrouille apres 3 tentatives echouees</small>
                </div>
              </label>
              <label class="list-group-item d-flex align-items-center gap-3" style="cursor:pointer;">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" name="notify_contact" id="notify_contact" <?= !empty($notify_toggles['contact']) ? 'checked' : '' ?>>
                </div>
                <div>
                  <i class="bi bi-envelope-paper text-primary me-1"></i>
                  <strong>Demande de contact</strong>
                  <small class="d-block text-muted">Quand un message est envoye via le formulaire de contact</small>
                </div>
              </label>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Destinataires</label>
            <select class="form-select" id="notifyRecipients" name="notify_recipients_select[]" multiple>
              <?php foreach ($adminUsers as $email): ?>
                <option value="<?= htmlspecialchars($email) ?>" <?= in_array($email, $notify_recipients) ? 'selected' : '' ?>><?= htmlspecialchars($email) ?></option>
              <?php endforeach; ?>
              <?php foreach ($notify_recipients as $r):
                if (!in_array($r, $adminUsers)): ?>
                <option value="<?= htmlspecialchars($r) ?>" selected><?= htmlspecialchars($r) ?></option>
              <?php endif; endforeach; ?>
            </select>
            <input type="hidden" name="notify_recipients" id="notifyRecipientsHidden" value="<?= htmlspecialchars(implode(',', $notify_recipients)) ?>">
            <small class="text-muted">Selectionnez des admins ou saisissez un e-mail externe. Si vide, tous les admins recevront les notifications.</small>
          </div>
          <div class="col-12 text-end"><button type="submit" name="save_notify_comment" class="btn btn-primary w-auto">Sauvegarder</button></div>
        </form>
      </div>
    </div>


    <div class="col-12 col-lg-4">
      <div class="setting-card">
        <h2><i class="bi bi-shield-check me-2"></i>Anti-bot (Cloudflare Turnstile)</h2>
        <p class="text-muted mb-3">
          Protège le formulaire de demande de partenariat contre les bots et IA.
          Créez un site gratuit sur
          <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">dash.cloudflare.com/turnstile</a>
          puis collez les deux clés ci-dessous. Sans clés, un captcha mathématique de secours est utilisé.
        </p>
        <form action="" method="post" class="row g-3">
          <?= csrf_field() ?><input type="hidden" name="active_subtab" value="notifications">
          <div class="col-12">
            <label class="form-label fw-semibold">Site Key (publique)</label>
            <input type="text" class="form-control" name="turnstile_sitekey" value="<?= htmlspecialchars($turnstile_sitekey) ?>" placeholder="0x4AAAAAAA...">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Secret Key (privée)</label>
            <input type="password" class="form-control" name="turnstile_secret" value="" placeholder="<?= $turnstile_secret ? '********' : '0x4AAAAAAA...' ?>" autocomplete="off">
            <?php if ($turnstile_secret): ?>
              <div class="form-text text-success"><i class="bi bi-check-circle"></i> Clé secrète enregistrée (laisser vide pour ne pas changer)</div>
            <?php endif; ?>
          </div>
          <div class="col-12">
            <?php $tsConfigured = !empty($turnstile_sitekey) && !empty($turnstile_secret); ?>
            <?php if ($tsConfigured): ?>
              <div class="alert alert-success py-2 mb-0"><i class="bi bi-check-circle me-1"></i>Turnstile actif sur le formulaire partenaire.</div>
            <?php else: ?>
              <div class="alert alert-warning py-2 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Captcha mathématique de secours utilisé (facilement résolu par les IA).</div>
            <?php endif; ?>
          </div>
          <div class="col-12 d-flex justify-content-end gap-2">
            <button type="button" id="btnTurnstileTest" class="btn btn-outline-secondary w-auto"><i class="bi bi-cloud-check me-1"></i>Tester les 2 clés</button>
            <button type="submit" name="save_turnstile" class="btn btn-primary w-auto">Sauvegarder</button>
          </div>
          <div class="col-12">
            <div id="turnstileTestPanel" style="display:none;margin-top:.5rem;padding:1rem;background:var(--surface-2);border:1px solid var(--border);border-radius:.55rem;">
              <div style="font-size:.85rem;color: var(--ink-dim);margin-bottom:.6rem;">Complétez le widget ci-dessous pour vérifier que la <strong>Site Key</strong> et la <strong>Secret Key</strong> fonctionnent bien ensemble :</div>
              <div id="turnstileTestWidget" style="display:flex;justify-content:center;min-height:70px;"></div>
              <div id="turnstileTestResult" style="margin-top:.6rem;text-align:center;font-size:.9rem;"></div>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ═══ ABONNÉS NEWSLETTER PANE (mail.newsletter) ═══ -->
<?php if ($canNewsletter): ?>
<div class="ed-pane <?= $activeSubTab==='newsletter'?'active':'' ?>" id="paneNewsletter" style="display:<?= $activeSubTab==='newsletter'?'block':'none' ?>;">
  <div class="setting-card">
    <?php
      $nbSub = 0; $nbUnsub = 0;
      foreach ($newsletterSubscribers as $s) {
          if (($s['status'] ?? '') === 'subscribed') $nbSub++; else $nbUnsub++;
      }
      $nbTotal = count($newsletterSubscribers);
    ?>
    <h2><i class="bi bi-envelope-heart me-2"></i>Abonnés newsletter</h2>
    <p class="text-muted mb-3">
      <strong><?= $nbSub ?></strong> abonné<?= $nbSub > 1 ? 's' : '' ?> actif<?= $nbSub > 1 ? 's' : '' ?> à la newsletter.
      Les inscriptions se font depuis la section « Rester informé » de la page d'accueil.
      Vous pouvez supprimer manuellement une adresse de la liste.
    </p>
    <?php if (empty($newsletterSubscribers)): ?>
      <div class="text-center py-5 text-muted">
        <i class="bi bi-inbox" style="font-size:3rem;opacity:.4"></i>
        <p class="mt-3 mb-0">Aucun abonné pour le moment.</p>
      </div>
    <?php else: ?>
      <div class="jr-segbtn mb-3" role="group" id="nlFilter">
        <button type="button" class="active" data-filter="all">
          Tous <span class="jr-seg-count"><?= $nbTotal ?></span>
        </button>
        <button type="button" data-filter="subscribed">
          Abonnés <span class="jr-seg-count"><?= $nbSub ?></span>
        </button>
        <button type="button" data-filter="unsubscribed">
          Désabonnés <span class="jr-seg-count"><?= $nbUnsub ?></span>
        </button>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>Email</th>
              <th>Statut</th>
              <th>Date d'inscription</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody id="nlTableBody">
            <?php foreach ($newsletterSubscribers as $s):
              $isSub = ($s['status'] ?? '') === 'subscribed'; ?>
              <tr data-status="<?= $isSub ? 'subscribed' : 'unsubscribed' ?>">
                <td><?= htmlspecialchars($s['email']) ?></td>
                <td>
                  <?php if ($isSub): ?>
                    <span class="badge bg-success">Abonné</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Désabonné</span>
                  <?php endif; ?>
                </td>
                <td><?= !empty($s['created_at']) ? htmlspecialchars(date('d/m/Y à H:i', strtotime($s['created_at']))) : '—' ?></td>
                <td class="text-end">
                  <form method="post" style="display:inline" data-confirm="Supprimer définitivement <?= htmlspecialchars($s['email']) ?> de la liste ?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="active_subtab" value="newsletter">
                    <input type="hidden" name="newsletter_email" value="<?= htmlspecialchars($s['email']) ?>">
                    <button type="submit" name="delete_newsletter_subscriber" value="1" class="btn btn-sm btn-outline-danger" title="Supprimer"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div id="nlEmptyFilter" class="text-center py-4 text-muted" style="display:none;">
        <i class="bi bi-funnel" style="font-size:2rem;opacity:.4"></i>
        <p class="mt-2 mb-0">Aucun abonné dans cette catégorie.</p>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  var group = document.getElementById('nlFilter');
  if (!group) return;
  var rows = Array.prototype.slice.call(document.querySelectorAll('#nlTableBody tr[data-status]'));
  var empty = document.getElementById('nlEmptyFilter');
  group.addEventListener('click', function(e){
    var b = e.target.closest('button[data-filter]');
    if (!b) return;
    group.querySelectorAll('button').forEach(function(x){ x.classList.remove('active'); });
    b.classList.add('active');
    var f = b.dataset.filter, shown = 0;
    rows.forEach(function(tr){
      var ok = (f === 'all') || (tr.dataset.status === f);
      tr.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });
    if (empty) empty.style.display = shown === 0 ? 'block' : 'none';
  });
})();
</script>

<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  var btn = document.getElementById('btnTurnstileTest');
  if (!btn) return;
  var panel  = document.getElementById('turnstileTestPanel');
  var widget = document.getElementById('turnstileTestWidget');
  var result = document.getElementById('turnstileTestResult');
  var csrfTok = <?= json_encode(csrf_token()) ?>;
  var loading = null;
  var widgetId = null;

  function loadTurnstile() {
    if (window.turnstile) return Promise.resolve();
    if (loading) return loading;
    loading = new Promise(function(res, rej){
      var s = document.createElement('script');
      s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
      s.async = true; s.defer = true;
      s.onload = function(){ res(); };
      s.onerror = function(){ loading = null; rej(); };
      document.head.appendChild(s);
    });
    return loading;
  }

  function setResult(html, color) {
    result.innerHTML = '<span style="color:' + (color || '#64748b') + '">' + html + '</span>';
  }

  btn.addEventListener('click', async function(){
    var keyInput = document.querySelector('input[name="turnstile_sitekey"]');
    var secInput = document.querySelector('input[name="turnstile_secret"]');
    var sitekey = keyInput ? keyInput.value.trim() : '';
    var secret  = secInput ? secInput.value.trim() : '';
    panel.style.display = 'block';
    if (!sitekey) { setResult('Saisissez d\'abord une <strong>Site Key</strong>.', '#b91c1c'); return; }
    widget.innerHTML = '';
    setResult('Chargement du widget Turnstile…');
    try {
      await loadTurnstile();
    } catch(e) {
      setResult('Impossible de charger le script Turnstile. Vérifiez votre connexion.', '#b91c1c');
      return;
    }
    if (widgetId !== null) {
      try { window.turnstile.remove(widgetId); } catch(e){}
      widgetId = null;
    }
    setResult('Complétez la vérification ci-dessus…');
    try {
      widgetId = window.turnstile.render(widget, {
        sitekey: sitekey,
        theme: 'light',
        callback: function(token){
          setResult('Vérification côté serveur…');
          fetch('../admin-api.php?route=turnstile-test', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfTok },
            body: JSON.stringify({ token: token, secret: secret })
          })
          .then(function(r){ return r.json(); })
          .then(function(d){
            if (d.ok) setResult('<i class="bi bi-check-circle-fill"></i> ' + d.msg, '#16a34a');
            else      setResult('<i class="bi bi-exclamation-triangle-fill"></i> ' + (d.msg || d.err || 'Erreur'), '#b91c1c');
          })
          .catch(function(){ setResult('Erreur réseau pendant la vérification.', '#b91c1c'); });
        },
        'error-callback': function(code){
          var hint = '';
          var c = String(code || '');
          // Mapping des codes Cloudflare Turnstile les plus courants
          // https://developers.cloudflare.com/turnstile/troubleshooting/client-side-errors/error-codes/
          if (c.indexOf('110100') === 0)      hint = 'Site Key inconnue de Cloudflare.';
          else if (c.indexOf('110110') === 0) hint = 'Site Key mal formée.';
          else if (c.indexOf('110200') === 0) hint = 'Domaine non autorisé pour cette Site Key.';
          else if (c.indexOf('110500') === 0) hint = 'Navigateur incompatible.';
          else if (c.indexOf('110600') === 0) hint = 'Mauvais type de widget (vérifie Managed/Invisible dans Cloudflare).';
          else if (c.indexOf('300') === 0)    hint = 'Erreur d\'exécution du challenge — réessaie.';
          else if (c.indexOf('400') === 0)    hint = 'Site Key invalide ou mal configurée (domaine non autorisé ?).';
          else if (c.indexOf('600') === 0)    hint = 'Le widget n\'a pas pu s\'exécuter — réessaie.';
          else                                hint = 'Site Key invalide ou domaine non autorisé.';

          var html = '<i class="bi bi-exclamation-triangle-fill"></i> Erreur Turnstile <code>' + c + '</code> — ' + hint;
          html += '<div style="margin-top:.5rem;font-size:.8rem;color: var(--ink-dim);line-height:1.5;">';
          html += '<strong>À vérifier :</strong><br>';
          html += '1. La Site Key copiée est bien identique à celle du dashboard Cloudflare<br>';
          html += '2. Le domaine actuel (<code>' + location.hostname + '</code>) figure dans <em>Hostname management</em> du widget<br>';
          html += '3. Le widget est en mode <em>Managed</em> dans Cloudflare';
          html += '</div>';
          setResult(html, '#b91c1c');
        },
        'expired-callback': function(){
          setResult('Captcha expiré — cliquez à nouveau sur « Tester ».', '#b45309');
        }
      });
    } catch(e) {
      setResult('Impossible de rendre le widget : <strong>Site Key invalide</strong>.', '#b91c1c');
    }
  });
})();
</script>

<?php endif; // $canWrite — fin Template + Google + Notifications ?>

<?php endif; // !$canWrite && !$canSend — fin du else global ?>

<!-- ═══ JAVASCRIPT ═══ -->
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
// Provider view toggle (affichage seulement, pas de sauvegarde)
document.querySelectorAll('input[name="prov_view"]').forEach(function(radio) {
  radio.addEventListener('change', function() {
    var isGoogle = this.value === 'google';
    document.querySelectorAll('.panel-gmail').forEach(function(el) { el.style.display = isGoogle ? '' : 'none'; });
    document.querySelectorAll('.panel-smtp').forEach(function(el) { el.style.display = isGoogle ? 'none' : ''; });
  });
});

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
      ['paneEnvoi','paneTemplate','paneGoogle','paneNotifications','paneNewsletter'].forEach(function(pId){
        var el = $('#' + pId);
        if (el) {
          el.classList.toggle('active', id === pId);
          el.style.display = (id === pId) ? 'block' : 'none';
        }
      });
      // Initialize TinyMCE when envoi tab is shown
      if (id === 'paneEnvoi') initMailTinyMCE();
    });
  });

  // ── Apply all styles to preview ──
  function applyAllStyles(){
    var C=CFG.colors, R=CFG.radius;
    // La grande zone d'aperçu (#previewArea) garde le fond de l'INTERFACE admin
    // (défini en CSS) → coins sombres cohérents. Seul le conteneur du mail
    // (#prevOuter) reçoit le fond du mail.
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
      var curBg=td?rgbHex(td.style.backgroundColor||'var(--surface-2)'):'var(--surface-2)';
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
        var isTips=(zone==='tips');
        if(isTips){
          // Add with bullet
          var wrap=document.createElement('div');
          wrap.style.cssText='display:flex;align-items:baseline;gap:0;margin:0 0 4px';
          var bullet=document.createElement('span');
          bullet.setAttribute('data-acc','txt');
          bullet.style.cssText='font-weight:700;margin-right:8px;color:'+CFG.colors.accent+';flex-shrink:0';
          bullet.innerHTML='&#9656;';
          var txt=document.createElement('span');
          txt.setAttribute('data-ed','txt_'+Date.now());
          txt.style.cssText='font-size:14px;color:rgba(255,255,255,.75);line-height:1.8';
          txt.textContent='Nouveau conseil...';
          wrap.appendChild(bullet);
          wrap.appendChild(txt);
          target.appendChild(wrap);
          bindTextEl(txt);
        } else {
          // Use section ID as prefix so PHP can restore it
          var secId=el.dataset.section||'txt';
          var newEl=document.createElement('div');
          newEl.setAttribute('data-ed',secId+'_'+Date.now());
          newEl.style.cssText='font-size:15px;line-height:1.7;color:#475569;margin-top:12px';
          newEl.textContent='Nouveau texte...';
          target.appendChild(newEl);
          bindTextEl(newEl);
        }
      });
    }

    // Visibility checkboxes (not for header/title/footer)
    if(zone!=='header'&&zone!=='title'&&zone!=='footer'){
      var secKey=el.dataset.section||zone;
      var visTitle=document.createElement('p'); visTitle.className='sb-title'; visTitle.textContent='Visible dans';
      cc.appendChild(visTitle);
      var mailTypes=[
        ['inscription','Inscription'],['code','Code connexion'],
        ['new_user','Nouveau compte'],['bulk','Envoi groupé'],['test','Mail test'],
        ['new_article','Nouvel article (newsletter)']
      ];
      var curVis=CFG.visibility[secKey]||[];
      if(!CFG.visibility[secKey]){ CFG.visibility[secKey]=[]; }
      mailTypes.forEach(function(mt){
        var lbl=document.createElement('label');
        lbl.style.cssText='display:flex;align-items:center;gap:6px;font-size:12px;color: var(--ink-dim);margin-bottom:4px;cursor:pointer';
        var cb=document.createElement('input');
        cb.type='checkbox'; cb.checked=curVis.indexOf(mt[0])!==-1;
        cb.style.cssText='accent-color:var(--accent)';
        cb.addEventListener('change', function(){
          var arr=CFG.visibility[secKey]||[];
          if(this.checked){ if(arr.indexOf(mt[0])===-1) arr.push(mt[0]); }
          else { arr=arr.filter(function(v){return v!==mt[0]}); }
          CFG.visibility[secKey]=arr;
        });
        lbl.appendChild(cb);
        lbl.appendChild(document.createTextNode(mt[1]));
        cc.appendChild(lbl);
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
    if(e.key==='Escape'){ e.preventDefault(); editingText.contentEditable='false'; editingText.classList.remove('editing'); editingText=null; }
    else if(e.key==='Enter'){ e.preventDefault(); document.execCommand('insertLineBreak'); } // saut de ligne (validation : Échap ou clic ailleurs)
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
      var cid='custom_'+Date.now();
      var tpl='<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px"><tr><td class="sec-r" style="padding:24px;background:#f8fafc;text-align:center"><div data-ed="'+cid+'_text" style="font-size:15px;line-height:1.7;color:#334155">Texte personnalisé...</div></td></tr></table>';
      var wrap=document.createElement('div');
      wrap.className='prev-sec'; wrap.dataset.zone='custom'; wrap.dataset.section=cid; wrap.draggable=true;
      CFG.visibility[cid]=[];
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
    add('mtc_visibility',JSON.stringify(CFG.visibility));
    $$('[data-ed]').forEach(function(el){
      var clone=el.cloneNode(true);
      clone.querySelectorAll('.el-actions').forEach(function(a){a.remove();});
      // Préserve les sauts de ligne (<br> ou <div> issus de contenteditable) sous forme de \n
      clone.innerHTML=clone.innerHTML.replace(/<div><br\s*\/?><\/div>/gi,'\n').replace(/<\/div><div>/gi,'\n').replace(/<div>/gi,'\n').replace(/<br\s*\/?>/gi,'\n');
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

<?php require __DIR__ . '/../src/partials/admin-footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
$(document).ready(function() {
    var $sel = $('#notifyRecipients');
    if ($sel.length) {
        $sel.select2({
            theme: 'bootstrap-5',
            tags: true,
            tokenSeparators: [',', ' '],
            placeholder: 'Sélectionnez ou saisissez un e-mail...',
            allowClear: true,
            createTag: function(params) {
                var val = params.term.trim();
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) return null;
                return { id: val, text: val };
            }
        });
        // Synchroniser avec le champ hidden avant submit
        $sel.closest('form').on('submit', function() {
            var vals = $sel.val() || [];
            $('#notifyRecipientsHidden').val(vals.join(','));
        });
    }
});
</script>

<!-- ═══ Envoi de mail JS ═══ -->
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  var _csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var availableEmails = [];
  var selectedRecipients = [];
  var mailTinymceInitialized = false;

  /* ══ TinyMCE init (lazy) ════ */
  window.initMailTinyMCE = function() {
    if (mailTinymceInitialized) return;
    tinymce.init({
      selector: '#mailDescription',
      <?= getTinyMceConfig($pdo, [
          'toolbar' => 'undo redo | formatselect | bold italic forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat | help',
      ]) ?>
    });
    mailTinymceInitialized = true;
  };

  // Init TinyMCE immediately if envoi tab is active
  if (document.getElementById('paneEnvoi') && document.getElementById('paneEnvoi').classList.contains('active')) {
    initMailTinyMCE();
  }

  /* ══ Load available emails from registrations API ════ */
  fetch('../admin-api.php?route=registrations')
    .then(function(r) { return r.json(); })
    .then(function(data) {
      var sorted = data.sort(function(a, b) { return new Date(a.created_at) - new Date(b.created_at); });
      availableEmails = [];
      sorted.forEach(function(person) {
        if (person.email && person.email.trim() !== '') {
          availableEmails.push({
            email: person.email,
            name: ((person.prenom || '') + ' ' + (person.nom || '')).trim(),
            id: person.id
          });
        }
      });
    })
    .catch(function(err) { console.error('Erreur chargement emails:', err); });

  /* ══ Helpers ════ */
  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  function searchEmails(query) {
    if (!query || query.length < 1) return [];
    query = query.toLowerCase();
    return availableEmails.filter(function(p) {
      return p.name.toLowerCase().includes(query) || p.email.toLowerCase().includes(query);
    });
  }

  function showEmailSuggestions(suggestions) {
    var div = document.getElementById('emailSuggestions');
    if (suggestions.length === 0) { div.style.display = 'none'; return; }
    var html = '';
    suggestions.forEach(function(p) {
      html += '<div class="suggestion-item" data-email="' + p.email + '" data-name="' + p.name + '" data-id="' + p.id + '">'
        + '<strong>' + p.name + '</strong><br><small class="text-muted">' + p.email + '</small></div>';
    });
    div.innerHTML = html;
    div.style.display = 'block';
  }

  function hideEmailSuggestions() {
    setTimeout(function() { document.getElementById('emailSuggestions').style.display = 'none'; }, 200);
  }

  function addRecipient(email, name, id) {
    if (selectedRecipients.find(function(r) { return r.email === email; })) return;
    selectedRecipients.push({ email: email, name: name || 'Email externe', id: id || null });
    updateDisplay();
  }

  function removeRecipient(email) {
    selectedRecipients = selectedRecipients.filter(function(r) { return r.email !== email; });
    updateDisplay();
  }

  function updateDisplay() {
    var container = document.getElementById('selectedRecipients');
    if (!container) return;
    if (selectedRecipients.length === 0) {
      container.innerHTML = '<small class="text-muted">Aucun destinataire sélectionné</small>';
    } else {
      var html = '';
      selectedRecipients.forEach(function(r) {
        html += '<span class="badge bg-primary me-2 mb-2 d-inline-flex align-items-center" style="font-size:0.8rem;">'
          + '<span class="me-2">' + r.name + ' (' + r.email + ')</span>'
          + '<button type="button" class="btn-close" data-action="remove-recipient" data-email="' + r.email + '" style="font-size:0.6rem;" title="Supprimer"></button>'
          + '</span>';
      });
      container.innerHTML = html;
    }
    var counter = document.getElementById('recipientsCounter');
    if (counter) counter.textContent = selectedRecipients.length + ' destinataire(s) sélectionné(s)';
    var hidden = document.getElementById('hiddenRecipients');
    if (hidden) hidden.value = JSON.stringify(selectedRecipients);
  }

  /* ══ Event listeners ════ */
  document.addEventListener('DOMContentLoaded', function() {
    var emailSearchInput = document.getElementById('emailSearchInput');
    if (emailSearchInput) {
      emailSearchInput.addEventListener('input', function() {
        var query = this.value.trim();
        if (query.length === 0) { hideEmailSuggestions(); return; }
        showEmailSuggestions(searchEmails(query));
      });
      emailSearchInput.addEventListener('blur', hideEmailSuggestions);
      emailSearchInput.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var query = this.value.trim();
        if (!query) return;

        if (query.includes(',')) {
          var emails = query.split(',');
          var invalidEmails = [], duplicateEmails = [];
          emails.forEach(function(em) {
            var clean = em.trim();
            if (!clean) return;
            if (isValidEmail(clean)) {
              if (selectedRecipients.find(function(r) { return r.email === clean; })) duplicateEmails.push(clean);
              else addRecipient(clean, 'Email externe', null);
            } else { invalidEmails.push(clean); }
          });
          var message = '';
          if (invalidEmails.length > 0) message += '\n' + invalidEmails.length + ' email(s) invalide(s): ' + invalidEmails.join(', ');
          if (duplicateEmails.length > 0) message += '\n' + duplicateEmails.length + ' email(s) déjà sélectionné(s): ' + duplicateEmails.join(', ');
          if (message) alert(message);
          this.value = '';
          hideEmailSuggestions();
        } else {
          if (isValidEmail(query)) {
            if (selectedRecipients.find(function(r) { return r.email === query; })) alert('Cet email est déjà sélectionné.');
            else { addRecipient(query, 'Email externe', null); this.value = ''; hideEmailSuggestions(); }
          } else {
            var first = document.querySelector('.suggestion-item');
            if (first) first.click();
            else alert('Email invalide et aucune suggestion trouvée.');
          }
        }
      });
    }

    // Suggestion clicks
    document.addEventListener('click', function(e) {
      var si = e.target.closest('.suggestion-item');
      if (si) { addRecipient(si.dataset.email, si.dataset.name, si.dataset.id); document.getElementById('emailSearchInput').value = ''; hideEmailSuggestions(); }
    });

    // Select all / Clear all
    var selectAllBtn = document.getElementById('selectAllBtn');
    var clearAllBtn = document.getElementById('clearAllBtn');
    if (selectAllBtn) selectAllBtn.addEventListener('click', function() { availableEmails.forEach(function(p) { addRecipient(p.email, p.name, p.id); }); });
    if (clearAllBtn) clearAllBtn.addEventListener('click', function() { selectedRecipients = []; updateDisplay(); });
    // Bouton "Newsletter" : remplace la sélection par les seuls abonnés à la newsletter.
    var newsletterBtn = document.getElementById('newsletterBtn');
    if (newsletterBtn) newsletterBtn.addEventListener('click', function() {
      var subs = <?= json_encode($newsletterEmails, JSON_UNESCAPED_UNICODE) ?>;
      selectedRecipients = [];
      subs.forEach(function(email) { addRecipient(email, 'Abonné newsletter', null); });
      updateDisplay();
      if (subs.length === 0) alert('Aucun abonné à la newsletter pour le moment.');
    });

    // Form submit
    var mailForm = document.getElementById('fMail');
    if (mailForm) {
      mailForm.addEventListener('submit', function(e) {
        e.preventDefault();
        if (selectedRecipients.length === 0) { alert('Veuillez sélectionner au moins un destinataire.'); return; }
        var description = tinymce.get('mailDescription') ? tinymce.get('mailDescription').getContent() : '';
        var mailData = {
          recipients: selectedRecipients,
          subject: document.getElementById('mailSubject').value,
          mail_title: document.getElementById('mailTitle').value,
          description: btoa(unescape(encodeURIComponent(description)))
        };
        var fd = new FormData();
        fd.append('csrf_token', _csrfToken);
        fd.append('mail_data', JSON.stringify(mailData));
        fetch('send-mail.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) window.location.reload();
            else if (typeof showToast === 'function') showToast(data.message || 'Erreur', 'danger');
            else alert(data.message || 'Erreur');
        })
        .catch(function (err) {
            if (typeof showToast === 'function') showToast('Erreur : ' + err.message, 'danger');
            else alert('Erreur : ' + err.message);
        });
      });
    }
  });

  // Event delegation for remove recipient
  document.addEventListener('click', function(e) {
    var el = e.target.closest('[data-action="remove-recipient"]');
    if (el) removeRecipient(el.dataset.email);
  });
})();
</script>
</body>
</html>
