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

requirePage('setting');
$role = currentRole();
$canWrite     = canDoAction('settings.write');
$pageReadOnly = !$canWrite;

// ── Helpers de sous-permissions (admin a tout via canDoAction) ─────────────────
$canTab  = function(string $tab): bool { return canDoAction('settings.tab.' . $tab); };
$canCard = function(string $tab, string $card): bool {
    return canDoAction('settings.' . $tab . '.' . $card);
};

// Mapping des save-buttons POST → (tab, card) pour blocage serveur
$postCardMap = [
    // Personnalisation
    'save_navbar_logo'    => ['personnalisation', null],
    'save_footer_logo'    => ['personnalisation', null],
    'save_theme'          => ['personnalisation', null],
    'reset_theme'         => ['personnalisation', null],
    'save_flash_colors'   => ['personnalisation', null],
    'reset_flash_colors'  => ['personnalisation', null],
    // Accueil
    'save_accueil_params'     => ['accueil', 'params'],
    'delete_picture_partner'  => ['accueil', 'params'],
    // 'save_accueil_layout' arrive en JSON (pas dans $_POST) → permission vérifiée plus bas dans le handler dédié
    // Inscription
    'save_header'              => ['inscription', 'header'],
    'save_inscription_params'  => ['inscription', 'params'],
    'LinkAssoConnect'          => ['inscription', 'assoconnect'],
    'save_csp_domains'         => ['inscription', 'cspdomains'],
    // Parcours
    'parcours'                 => ['parcours', null],
    'uploadGalerie'            => ['parcours', null],
    'delete_picture_parcours'  => ['parcours', null],
    'delete_picture_gradient'  => ['parcours', null],
    // Reglementation
    'reglementation'           => ['reglementation', null],
    // Formulaire
    'save_fields'              => ['formulaire', null],
    'add_custom_field'         => ['formulaire', null],
    'delete_field_id'          => ['formulaire', null],
    // Import
    'importExcel'              => ['import', null],
    // Maintenance
    'save_maintenance'         => ['maintenance', null],
    // API
    'save_api'                 => ['api', null],
    'regenerate_api'           => ['api', null],
    // Import automatique AssoConnect
    'save_import_auto'         => ['import_auto', null],
    'regenerate_worker_token'  => ['import_auto', null],
];

// Bloquer toute action POST si pas le droit d'écriture
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canWrite) {
    http_response_code(403);
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Action non autorisée (lecture seule).'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Bloquer aussi si la sous-permission (onglet ou carte) manque
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canWrite) {
    foreach ($postCardMap as $postKey => [$tab, $card]) {
        if (!isset($_POST[$postKey])) continue;
        $allowed = $canTab($tab) && ($card === null || $canCard($tab, $card));
        if (!$allowed) {
            http_response_code(403);
            $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Action non autorisée (permission manquante).'];
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
        break; // un seul bouton soumis à la fois
    }
}

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
$assoconnectUrl     = $data['assoconnect_url']    ?? '';
// Domaines autorisés dans la CSP pour AssoConnect (carte dédiée). Vide = défauts
// appliqués par config.php. On préremplit l'affichage avec ces défauts.
$assoconnectCspDomains = trim((string)($data['assoconnect_csp_domains'] ?? ''));
$assoconnectCspDefault = "https://*.assoconnect.com\nhttps://*.team.blue\nhttps://*.adyen.com";
$title  = $data['title']   ?? '';
$navbar_logo = $data['navbar_logo'] ?? 'logo_fer_rose.png';
$footer_logo = $data['footer_logo'] ?? 'logo_blanc.png';
$title_mobile = $data['title_mobile'] ?? '';
$registration_fee = $data['registration_fee'] ?? 0;
$course_km = $data['course_km'] ?? 7;

// Tarif enfant automatique selon l'âge (cf. import Excel / ajout multiple)
$child_pricing_enabled = !empty($data['child_pricing_enabled']) ? 1 : 0;
$child_age_threshold   = (int) ($data['child_age_threshold'] ?? 12);
$child_amount          = (int) ($data['child_amount'] ?? 0);

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
$registration_auto_open  = $data['registration_auto_open']  ?? null;
$registration_auto_close = $data['registration_auto_close'] ?? null;
$registration_closed_message = $data['registration_closed_message'] ?? '';
$date_course = $data['date_course'] ?? null;
$date_formatted = $date_course ? date('Y-m-d', strtotime($date_course)) : '';
$picture_partner= $data['picture_partner'] ?? '';
$link_cancer = $data['link_cancer'] ?? null;

// API externe (onglet API)
$api_enabled = (int)($data['api_enabled'] ?? 0);
$api_user    = $data['api_user'] ?? '';
$api_token   = !empty($data['api_token']) ? decrypt($data['api_token']) : '';

// URL absolue de l'API (api.php est à la racine du projet)
$api_baseUrl     = getAppBaseUrl();
$api_projectRoot = realpath(__DIR__ . '/..');
$api_docRoot     = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
if ($api_projectRoot === $api_docRoot || $api_projectRoot === false || $api_docRoot === false) {
    $api_baseDir = '';
} else {
    $api_baseDir = str_replace('\\', '/', substr($api_projectRoot, strlen($api_docRoot)));
}
$api_url = $api_baseUrl . $api_baseDir . '/api.php';
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
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Session expirée. Veuillez réessayer.']);
        exit;
    }
    die('Invalid CSRF token');
}

$isAjax = isAjaxRequest();

// ─── Handler AJAX : PUBLIER le brouillon de l'accueil ───
// Copie tous les champs *_draft vers les champs publiés, puis vide le brouillon.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_accueil'])) {
    header('Content-Type: application/json');
    if (!$canTab('accueil') || !$canCard('accueil', 'custom')) {
        http_response_code(403); echo json_encode(['ok' => false, 'err' => 'Action non autorisée.']); exit;
    }
    try {
        require_once __DIR__ . '/../config/accueil_layout.php';
        publishAccueilDraft($pdo);
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        error_log('[PUBLISH_ACCUEIL] ' . $e->getMessage());
        http_response_code(500); echo json_encode(['ok' => false, 'err' => 'Erreur serveur.']);
    }
    exit;
}

// ─── Handler AJAX : DISCARD le brouillon (annuler modifications non publiées) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['discard_accueil_draft'])) {
    header('Content-Type: application/json');
    if (!$canTab('accueil') || !$canCard('accueil', 'custom')) {
        http_response_code(403); echo json_encode(['ok' => false, 'err' => 'Action non autorisée.']); exit;
    }
    try {
        require_once __DIR__ . '/../config/accueil_layout.php';
        discardAccueilDraft($pdo);
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        error_log('[DISCARD_ACCUEIL_DRAFT] ' . $e->getMessage());
        http_response_code(500); echo json_encode(['ok' => false, 'err' => 'Erreur serveur.']);
    }
    exit;
}

// ─── Handler dédié AJAX : sauvegarde d'un champ individuel d'une section accueil ───
// Appelé depuis l'éditeur visuel WYSIWYG quand l'utilisateur édite un champ inline
// (titre, sous-titre, image, vidéo, etc.). Le champ doit être whitelisté pour des
// raisons de sécurité.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_accueil_field'])) {
    header('Content-Type: application/json');
    if (!$canTab('accueil') || !$canCard('accueil', 'custom')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Action non autorisée.']);
        exit;
    }
    // Whitelist des champs modifiables via cet endpoint
    $allowedFields = [
        'titleAccueil'            => ['type' => 'tinymce'],
        'titleAccueil_mobile'     => ['type' => 'tinymce'],
        'subtitle_accueil'        => ['type' => 'text', 'maxlen' => 500],
        'subtitle_accueil_mobile' => ['type' => 'text', 'maxlen' => 500],
        'video_accueil'           => ['type' => 'file', 'dir' => '../files/', 'accept' => ['mp4','webm','ogg']],
        'picture_partner'         => ['type' => 'file', 'dir' => '../files/_pictures/', 'accept' => ['jpg','jpeg','png','gif','webp']],
    ];
    $field = (string)($_POST['field'] ?? '');
    // La vidéo est COMMUNE desktop/mobile : `video_accueil_mobile` (nom affiché côté
    // éditeur en mode mobile) est normalisé vers la colonne unique `video_accueil`.
    if ($field === 'video_accueil_mobile') {
        $field = 'video_accueil';
    }
    if (!isset($allowedFields[$field])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'err' => 'Champ non autorisé.']);
        exit;
    }
    $meta = $allowedFields[$field];
    try {
        if ($meta['type'] === 'file') {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'err' => 'Fichier invalide ou absent.']);
                exit;
            }
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $meta['accept'], true)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'err' => 'Extension non autorisée. Accepté : ' . implode(', ', $meta['accept'])]);
                exit;
            }
            $newName = bin2hex(random_bytes(8)) . '.' . $ext;
            $destPath = $meta['dir'] . $newName;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'err' => 'Échec de l\'enregistrement du fichier.']);
                exit;
            }
            $value = $newName;
        } elseif ($meta['type'] === 'tinymce') {
            $value = sanitizeHtml((string)($_POST['value'] ?? ''));
        } else { // text
            $value = trim((string)($_POST['value'] ?? ''));
            if (isset($meta['maxlen'])) $value = mb_substr($value, 0, $meta['maxlen']);
        }
        $pdo->prepare("UPDATE setting SET `{$field}` = :v WHERE id = 1")->execute(['v' => $value]);
        echo json_encode(['ok' => true, 'value' => $value]);
    } catch (\Throwable $e) {
        error_log('[SAVE_ACCUEIL_FIELD] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'err' => 'Erreur serveur.']);
    }
    exit;
}

// ─── Handler AJAX : point de départ de la section "Retrouver le départ" ───
// Stocke SOIT une adresse SOIT des coordonnées (jamais les deux) dans les colonnes
// dédiées `start_point_address` / `start_point_coords`. Le mode choisi détermine la
// colonne renseignée ; l'autre est vidée (NULL) pour garantir l'exclusivité.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_start_point'])) {
    header('Content-Type: application/json');
    if (!$canTab('accueil') || !$canCard('accueil', 'custom')) {
        http_response_code(403); echo json_encode(['ok' => false, 'err' => 'Action non autorisée.']); exit;
    }
    $mode  = (string)($_POST['mode'] ?? '');
    $value = trim((string)($_POST['value'] ?? ''));
    if (!in_array($mode, ['address', 'coords'], true)) {
        http_response_code(400); echo json_encode(['ok' => false, 'err' => 'Mode invalide.']); exit;
    }
    if ($mode === 'coords' && $value !== '') {
        $value = str_replace(' ', '', $value);
        if (!preg_match('/^-?\d{1,2}(\.\d+)?,-?\d{1,3}(\.\d+)?$/', $value)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'Format invalide. Attendu : latitude,longitude (ex : 49.1869,6.8983).']);
            exit;
        }
        [$lat, $lng] = array_map('floatval', explode(',', $value));
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'Coordonnées hors limites (lat -90..90, lng -180..180).']);
            exit;
        }
    }
    $value = mb_substr($value, 0, $mode === 'address' ? 255 : 64);
    try {
        if ($mode === 'address') {
            $pdo->prepare('UPDATE setting SET start_point_address = :v, start_point_coords = NULL WHERE id = 1')
                ->execute(['v' => $value !== '' ? $value : null]);
            echo json_encode(['ok' => true, 'address' => $value, 'coords' => '']);
        } else {
            $pdo->prepare('UPDATE setting SET start_point_coords = :v, start_point_address = NULL WHERE id = 1')
                ->execute(['v' => $value !== '' ? $value : null]);
            echo json_encode(['ok' => true, 'address' => '', 'coords' => $value]);
        }
    } catch (\Throwable $e) {
        error_log('[SAVE_START_POINT] ' . $e->getMessage());
        http_response_code(500); echo json_encode(['ok' => false, 'err' => 'Erreur serveur.']);
    }
    exit;
}

// ─── Handler AJAX : restaure les valeurs par défaut du hero (purge tout) ───
// Supprime du JSON `accueil_geometry_draft` ET/OU `accueil_styles_draft` toutes les
// clés liées aux éléments du hero pour le device demandé (mobile, desktop ou both).
// Après ça, le CSS d'origine de `css/accueil.css` reprend la main (positions par
// défaut : badges flex flow, video_toggle bottom-right desktop ou top-right mobile,
// social card column desktop ou row mobile static).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_accueil_hero_defaults'])) {
    header('Content-Type: application/json');
    if (!$canTab('accueil') || !$canCard('accueil', 'custom')) {
        http_response_code(403); echo json_encode(['ok' => false, 'err' => 'Action non autorisée.']); exit;
    }
    // Scope du reset : 'mobile' = ne touche que les variantes _mobile / _size_mobile,
    // 'desktop' = ne touche que les clés sans suffixe, 'both' = nettoie tout (rare).
    $scope = (string)($_POST['scope'] ?? 'both');
    if (!in_array($scope, ['mobile', 'desktop', 'both'], true)) {
        http_response_code(400); echo json_encode(['ok' => false, 'err' => 'Scope invalide.']); exit;
    }
    // Toutes les clés "base" du hero ; les variantes _mobile sont dérivées par suffixe.
    $heroBaseFields = [
        'hero_timer', 'titleAccueil', 'subtitle_accueil',
        'badge_fee', 'badge_km',
        'video_toggle', 'video_social_card',
        'hero.cta_register',
    ];
    $heroBaseSizes = [
        'hero_timer_size', 'titleAccueil_size', 'subtitle_accueil_size',
        'badge_fee_size', 'badge_km_size',
    ];
    // Helper : doit-on supprimer cette clé selon le scope ?
    $shouldDrop = function(string $key) use ($scope): bool {
        $isMobile = substr($key, -7) === '_mobile';
        if ($scope === 'mobile')  return $isMobile;
        if ($scope === 'desktop') return !$isMobile;
        return true; // 'both'
    };
    try {
        // Geometry
        $row = $pdo->query('SELECT COALESCE(accueil_geometry_draft, accueil_geometry) AS g FROM setting WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $geom = [];
        if ($row && !empty($row['g'])) {
            $decoded = json_decode($row['g'], true);
            if (is_array($decoded)) $geom = $decoded;
        }
        foreach ($heroBaseFields as $f) {
            if ($shouldDrop($f))             unset($geom[$f]);
            if ($shouldDrop($f . '_mobile')) unset($geom[$f . '_mobile']);
        }
        // IMPORTANT : on encode TOUJOURS le tableau, même vide (`{}`/`[]`), pour ne PAS
        // stocker la chaîne vide. La chaîne vide est falsy, donc le PHP de accueil.php
        // (qui fait `accueil_geometry_draft ?: accueil_geometry`) basculerait sur la
        // version PUBLIÉE et l'admin verrait à nouveau les anciennes positions au lieu
        // du CSS par défaut. Avec `{}`, le draft reste truthy → lecture du draft vide
        // → aucune position → CSS pur reprend. C'est exactement ce qu'on veut.
        $geomEncoded = json_encode($geom ?: new stdClass(), JSON_UNESCAPED_UNICODE);
        $pdo->prepare('UPDATE setting SET accueil_geometry_draft = :g, accueil_draft_updated_at = NOW() WHERE id = 1')
            ->execute(['g' => $geomEncoded]);
        // Styles (tailles + alignements). Les alignements (text_align__*) restent —
        // l'utilisateur n'a pas demandé à les reset. Seules les tailles sont purgées.
        $rowS = $pdo->query('SELECT COALESCE(accueil_styles_draft, accueil_styles) AS s FROM setting WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $styles = [];
        if ($rowS && !empty($rowS['s'])) {
            $decodedS = json_decode($rowS['s'], true);
            if (is_array($decodedS)) $styles = $decodedS;
        }
        foreach ($heroBaseSizes as $s) {
            if ($shouldDrop($s))             unset($styles[$s]);
            if ($shouldDrop($s . '_mobile')) unset($styles[$s . '_mobile']);
        }
        $stylesEncoded = json_encode($styles ?: new stdClass(), JSON_UNESCAPED_UNICODE);
        $pdo->prepare('UPDATE setting SET accueil_styles_draft = :s, accueil_draft_updated_at = NOW() WHERE id = 1')
            ->execute(['s' => $stylesEncoded]);
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        error_log('[RESTORE_ACCUEIL_HERO_DEFAULTS] ' . $e->getMessage());
        http_response_code(500); echo json_encode(['ok' => false, 'err' => 'Erreur serveur.']);
    }
    exit;
}

// ─── Handler AJAX : sauvegarde de la géométrie (x/y/w/h) d'un élément éditable ───
// Utilisé pour drag libre + resize 4-coins de certains éléments (image partenaires, timer Hero, etc.)
// ─── Handler AJAX : reset (suppression) de la géométrie d'un champ hero ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_accueil_geometry'])) {
    header('Content-Type: application/json');
    if (!$canTab('accueil') || !$canCard('accueil', 'custom')) {
        http_response_code(403); echo json_encode(['ok' => false, 'err' => 'Action non autorisée.']); exit;
    }
    $allowedResetFields = [
        'picture_partner',
        'hero_timer', 'hero_timer_mobile',
        'titleAccueil', 'titleAccueil_mobile',
        'subtitle_accueil', 'subtitle_accueil_mobile',
        'badge_fee', 'badge_fee_mobile',
        'badge_km',  'badge_km_mobile',
        'video_toggle', 'video_toggle_mobile',
        'video_social_card', 'video_social_card_mobile',
        'hero.cta_register', 'hero.cta_register_mobile',
    ];
    $field  = (string)($_POST['field'] ?? '');
    $device = (string)($_POST['device'] ?? 'desktop');
    // En mode mobile, on cible UNIQUEMENT la variante `_mobile` (la position desktop
    // reste intacte). Inverse pour mode desktop. Si le champ finit déjà par `_mobile`
    // (cas titleAccueil_mobile / subtitle_accueil_mobile), on garde tel quel.
    if ($device === 'mobile' && substr($field, -7) !== '_mobile') {
        $field .= '_mobile';
    }
    if (!in_array($field, $allowedResetFields, true)) {
        http_response_code(400); echo json_encode(['ok' => false, 'err' => 'Champ non autorisé.']); exit;
    }
    try {
        // Lit le brouillon en cours (avec fallback sur la version publiée)
        $row = $pdo->query('SELECT COALESCE(accueil_geometry_draft, accueil_geometry) AS g FROM setting WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $all = [];
        if ($row && !empty($row['g'])) {
            $decoded = json_decode($row['g'], true);
            if (is_array($decoded)) $all = $decoded;
        }
        unset($all[$field]);
        // Écrit dans le BROUILLON. CRITIQUE : si `$all` devient vide après le reset,
        // on stocke '{}' (objet JSON vide, TRUTHY) et NON null. Sinon `COALESCE(NULL, prod)`
        // au prochain load retomberait sur la PRODUCTION (qui contient encore les positions)
        // → l'admin verrait sa position publiée RÉAPPARAÎTRE au refresh juste après le reset.
        // Avec '{}' le draft existe mais ne contient aucune position → PHP n'émet pas
        // d'attrs → CSS natural appliqué partout.
        $payload = $all ? json_encode($all, JSON_UNESCAPED_UNICODE) : '{}';
        $pdo->prepare('UPDATE setting SET accueil_geometry_draft = :g, accueil_draft_updated_at = NOW() WHERE id = 1')
            ->execute(['g' => $payload]);
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        error_log('[RESET_ACCUEIL_GEOMETRY] ' . $e->getMessage());
        http_response_code(500); echo json_encode(['ok' => false, 'err' => 'Erreur serveur.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_accueil_geometry'])) {
    header('Content-Type: application/json');
    if (!$canTab('accueil') || !$canCard('accueil', 'custom')) {
        http_response_code(403); echo json_encode(['ok' => false, 'err' => 'Action non autorisée.']); exit;
    }
    $allowedFields = [
        'picture_partner',
        'hero_timer', 'hero_timer_mobile',
        'titleAccueil', 'titleAccueil_mobile',
        'subtitle_accueil', 'subtitle_accueil_mobile',
        'badge_fee', 'badge_fee_mobile',
        'badge_km',  'badge_km_mobile',
        'video_toggle', 'video_toggle_mobile',
        'video_social_card', 'video_social_card_mobile',
        'hero.cta_register', 'hero.cta_register_mobile',
    ];
    $field  = (string)($_POST['field'] ?? '');
    $device = (string)($_POST['device'] ?? 'desktop');
    // Si l'éditeur est en mode "mobile", on enregistre dans la variante _mobile du champ.
    if ($device === 'mobile' && substr($field, -7) !== '_mobile') {
        $field .= '_mobile';
    }
    if (!in_array($field, $allowedFields, true)) {
        http_response_code(400); echo json_encode(['ok' => false, 'err' => 'Champ non autorisé.']); exit;
    }
    $rawGeom = $_POST['geometry'] ?? '';
    $geom = is_string($rawGeom) ? json_decode($rawGeom, true) : (is_array($rawGeom) ? $rawGeom : null);
    if (!is_array($geom)) {
        http_response_code(400); echo json_encode(['ok' => false, 'err' => 'Géométrie invalide.']); exit;
    }
    $clean = [
        'x' => max(-2000, min(2000, (int)($geom['x'] ?? 0))),
        'y' => max(-2000, min(2000, (int)($geom['y'] ?? 0))),
        'w' => max(20,    min(2000, (int)($geom['w'] ?? 100))),
        'h' => max(20,    min(2000, (int)($geom['h'] ?? 100))),
    ];
    // Préserve le scale (slider de taille) — sinon, à chaque sauvegarde de drag/resize,
    // l'élément reprenait sa taille d'origine au rechargement.
    if (isset($geom['scale'])) {
        $sc = (float)$geom['scale'];
        if ($sc > 0.1 && $sc < 10) $clean['scale'] = round($sc, 4);
    }
    // Position en POURCENTAGE du parent : format historique conservé uniquement pour relire
    // les anciennes géométries. Les nouvelles sauvegardes privilégient anchor+offset en pixels.
    if (isset($geom['topPct']) && isset($geom['leftPct'])) {
        $tp = (float)$geom['topPct'];
        $lp = (float)$geom['leftPct'];
        if ($tp >= -50 && $tp <= 150 && $lp >= -50 && $lp <= 150) {
            $clean['topPct']  = round($tp, 4);
            $clean['leftPct'] = round($lp, 4);
        }
    }
    // Ancre + offset en PIXELS (nouveau modèle — position visuelle figée à pixel près
    // quelle que soit la largeur de l'écran). Les nouvelles sauvegardes utilisent left/top ;
    // right/bottom restent acceptés uniquement pour relire d'anciennes géométries.
    if (isset($geom['anchorX']) && isset($geom['anchorY'])
        && in_array($geom['anchorX'], ['left', 'right'], true)
        && in_array($geom['anchorY'], ['top', 'bottom'], true)
        && isset($geom['offsetX']) && isset($geom['offsetY'])) {
        $ox = (float)$geom['offsetX'];
        $oy = (float)$geom['offsetY'];
        if ($ox >= -2000 && $ox <= 4000 && $oy >= -2000 && $oy <= 4000) {
            $clean['anchorX'] = $geom['anchorX'];
            $clean['anchorY'] = $geom['anchorY'];
            $clean['offsetX'] = round($ox, 2);
            $clean['offsetY'] = round($oy, 2);
            unset($clean['topPct'], $clean['leftPct']);
        }
    }
    // Mode d'ancrage : permet à l'admin de figer un axe au centre de .demo-card
    // pour que l'élément reste visuellement centré même si la carte change de taille.
    //  - 'free' (défaut) : anchor+offset libre sur les deux axes
    //  - 'centerX'       : centré horizontalement, libre verticalement
    //  - 'centerY'       : centré verticalement, libre horizontalement
    //  - 'center'        : centré sur les deux axes (immobile)
    if (isset($geom['mode'])) {
        $mode = (string)$geom['mode'];
        if (in_array($mode, ['free', 'centerX', 'centerY', 'center'], true)) {
            $clean['mode'] = $mode;
        }
    }
    try {
        $row = $pdo->query('SELECT COALESCE(accueil_geometry_draft, accueil_geometry) AS g FROM setting WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $all = [];
        if ($row && !empty($row['g'])) {
            $decoded = json_decode($row['g'], true);
            if (is_array($decoded)) $all = $decoded;
        }
        $all[$field] = $clean;
        $pdo->prepare('UPDATE setting SET accueil_geometry_draft = :g, accueil_draft_updated_at = NOW() WHERE id = 1')
            ->execute(['g' => json_encode($all, JSON_UNESCAPED_UNICODE)]);
        // On renvoie la clé effective écrite (potentiellement suffixée _mobile) :
        // le JS peut ainsi confirmer visuellement à l'admin que sa sauvegarde a bien
        // été routée vers la variante mobile/desktop attendue.
        echo json_encode(['ok' => true, 'savedKey' => $field, 'device' => $device]);
    } catch (\Throwable $e) {
        error_log('[SAVE_ACCUEIL_GEOMETRY] ' . $e->getMessage());
        http_response_code(500); echo json_encode(['ok' => false, 'err' => 'Erreur serveur.']);
    }
    exit;
}

// ─── Handler AJAX : sauvegarde d'un texte éditable hardcodé ───
// Pour les textes des sections (Vérifier mon inscription, Déjà inscrits, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_accueil_text'])) {
    header('Content-Type: application/json');
    if (!$canTab('accueil') || !$canCard('accueil', 'custom')) {
        http_response_code(403); echo json_encode(['ok' => false, 'err' => 'Action non autorisée.']); exit;
    }
    require_once __DIR__ . '/../config/accueil_sections.php';
    $allowed = accueilEditableTexts();
    $key = (string)($_POST['textKey'] ?? '');
    $val = trim((string)($_POST['textValue'] ?? ''));
    if (!isset($allowed[$key])) {
        http_response_code(400); echo json_encode(['ok' => false, 'err' => 'Clé non autorisée.']); exit;
    }
    if (mb_strlen($val) > 2000) {
        http_response_code(400); echo json_encode(['ok' => false, 'err' => 'Texte trop long (max 2000).']); exit;
    }
    try {
        $row = $pdo->query('SELECT COALESCE(accueil_texts_draft, accueil_texts) AS t FROM setting WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $texts = [];
        if ($row && !empty($row['t'])) {
            $decoded = json_decode($row['t'], true);
            if (is_array($decoded)) $texts = $decoded;
        }
        if ($val === '') {
            unset($texts[$key]); // valeur vide = on retire l'override (revient au défaut)
        } else {
            $texts[$key] = $val;
        }
        $json = json_encode($texts, JSON_UNESCAPED_UNICODE);
        $pdo->prepare('UPDATE setting SET accueil_texts_draft = :t, accueil_draft_updated_at = NOW() WHERE id = 1')->execute(['t' => $json]);
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        error_log('[SAVE_ACCUEIL_TEXT] ' . $e->getMessage());
        http_response_code(500); echo json_encode(['ok' => false, 'err' => 'Erreur serveur.']);
    }
    exit;
}

// ─── Handler AJAX : reset des dimensions de la vidéo Hero (hauteur, largeur, pleine
// largeur) pour le device demandé. Supprime les clés du JSON accueil_styles_draft
// → au reload, la vidéo retombe sur les valeurs CSS par défaut (clamp 70vh, width 100%). ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_hero_card_dimensions'])) {
    header('Content-Type: application/json');
    if (!$canTab('accueil') || !$canCard('accueil', 'custom')) {
        http_response_code(403); echo json_encode(['ok' => false, 'err' => 'Action non autorisée.']); exit;
    }
    $scope = (string)($_POST['scope'] ?? 'desktop');
    if (!in_array($scope, ['mobile', 'desktop', 'both'], true)) {
        http_response_code(400); echo json_encode(['ok' => false, 'err' => 'Scope invalide.']); exit;
    }
    try {
        $row = $pdo->query('SELECT COALESCE(accueil_styles_draft, accueil_styles) AS s FROM setting WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $styles = [];
        if ($row && !empty($row['s'])) {
            $decoded = json_decode($row['s'], true);
            if (is_array($decoded)) $styles = $decoded;
        }
        $keysDesktop = ['hero_card_height', 'hero_card_width', 'hero_card_fullwidth'];
        $keysMobile  = ['hero_card_height_mobile', 'hero_card_width_mobile', 'hero_card_fullwidth_mobile'];
        $toDrop = [];
        if ($scope === 'desktop' || $scope === 'both') $toDrop = array_merge($toDrop, $keysDesktop);
        if ($scope === 'mobile'  || $scope === 'both') $toDrop = array_merge($toDrop, $keysMobile);
        foreach ($toDrop as $k) unset($styles[$k]);
        $payload = $styles ? json_encode($styles, JSON_UNESCAPED_UNICODE) : null;
        $stmt = $pdo->prepare('UPDATE setting SET accueil_styles_draft = :s, accueil_draft_updated_at = NOW() WHERE id = 1');
        $stmt->bindValue(':s', $payload, $payload === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        error_log('[RESET_HERO_CARD_DIMENSIONS] ' . $e->getMessage());
        http_response_code(500); echo json_encode(['ok' => false, 'err' => 'Erreur serveur.']);
    }
    exit;
}

// ─── Handler AJAX : reset des dimensions de la carte "Retrouver le départ" ───
// Supprime les clés start_point_map_* du JSON accueil_styles_draft pour le device
// demandé → au reload, la carte retombe sur ses dimensions CSS par défaut.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_start_point_map_dimensions'])) {
    header('Content-Type: application/json');
    if (!$canTab('accueil') || !$canCard('accueil', 'custom')) {
        http_response_code(403); echo json_encode(['ok' => false, 'err' => 'Action non autorisée.']); exit;
    }
    $scope = (string)($_POST['scope'] ?? 'desktop');
    if (!in_array($scope, ['mobile', 'desktop', 'both'], true)) {
        http_response_code(400); echo json_encode(['ok' => false, 'err' => 'Scope invalide.']); exit;
    }
    try {
        $row = $pdo->query('SELECT COALESCE(accueil_styles_draft, accueil_styles) AS s FROM setting WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $styles = [];
        if ($row && !empty($row['s'])) {
            $decoded = json_decode($row['s'], true);
            if (is_array($decoded)) $styles = $decoded;
        }
        $keysDesktop = ['start_point_map_height', 'start_point_map_width'];
        $keysMobile  = ['start_point_map_height_mobile', 'start_point_map_width_mobile'];
        $toDrop = [];
        if ($scope === 'desktop' || $scope === 'both') $toDrop = array_merge($toDrop, $keysDesktop);
        if ($scope === 'mobile'  || $scope === 'both') $toDrop = array_merge($toDrop, $keysMobile);
        foreach ($toDrop as $k) unset($styles[$k]);
        $payload = $styles ? json_encode($styles, JSON_UNESCAPED_UNICODE) : null;
        $stmt = $pdo->prepare('UPDATE setting SET accueil_styles_draft = :s, accueil_draft_updated_at = NOW() WHERE id = 1');
        $stmt->bindValue(':s', $payload, $payload === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        error_log('[RESET_START_POINT_MAP] ' . $e->getMessage());
        http_response_code(500); echo json_encode(['ok' => false, 'err' => 'Erreur serveur.']);
    }
    exit;
}

// ─── Handler AJAX : sauvegarde d'un style (taille) d'un élément du Hero ───
// Le champ 'sizeKey' doit être whitelisté ; valeur en pourcent (50-250).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_accueil_style'])) {
    header('Content-Type: application/json');
    if (!$canTab('accueil') || !$canCard('accueil', 'custom')) {
        http_response_code(403); echo json_encode(['ok' => false, 'err' => 'Action non autorisée.']); exit;
    }
    $allowedSizeKeys  = [
        'titleAccueil_size', 'titleAccueil_size_mobile',
        'subtitle_accueil_size', 'subtitle_accueil_size_mobile',
        'hero_timer_size', 'hero_timer_size_mobile',
        'badge_fee_size', 'badge_fee_size_mobile',
        'badge_km_size',  'badge_km_size_mobile',
        // Hauteur de la vidéo Hero, en pixels. Plage 300–1500 (cf. validation infra).
        'hero_card_height', 'hero_card_height_mobile',
        // Largeur de la vidéo Hero, en POURCENTAGE de la viewport (30-100 %).
        // 100 % = pleine largeur de la fenêtre du navigateur.
        'hero_card_width', 'hero_card_width_mobile',
        // Carte "Retrouver le départ" : hauteur (px) + largeur (% du conteneur),
        // séparées desktop / mobile.
        'start_point_map_height', 'start_point_map_height_mobile',
        'start_point_map_width',  'start_point_map_width_mobile',
        // Tailles des textes de la card inscriptions (reg_bar), en % (50-300).
        // Appliquées via les CSS vars --rb-scale / --rb-scale-m (cf. accueil.css).
        // Les deux variantes (base + _mobile) doivent être listées : le suffixe
        // _mobile est ajouté AVANT le contrôle in_array ci-dessous.
        'reg_bar.kicker_size',       'reg_bar.kicker_size_mobile',
        'reg_bar.value_size',        'reg_bar.value_size_mobile',
        'reg_bar.msg_title_size',    'reg_bar.msg_title_size_mobile',
        'reg_bar.msg_text_size',     'reg_bar.msg_text_size_mobile',
        'reg_bar.title_search_size', 'reg_bar.title_search_size_mobile',
        'reg_bar.btn_check_size',    'reg_bar.btn_check_size_mobile',
        'reg_bar.hint_size',         'reg_bar.hint_size_mobile',
    ];
    // Options (enum) : clé → valeurs autorisées
    $allowedOptionKeys = [
        'news.card_style' => ['simple', 'with-image', 'with-image-side'],
        // Version du bloc gauche de la card inscriptions (compteur / urgence / solidaire)
        'reg_bar.display_style' => ['counter', 'urgency', 'motivation'],
        // Source du grand nombre de la version compteur : en direct ou dernière archive
        'reg_bar.counter_source' => ['live', 'archive'],
        // Pleine largeur du Hero (vidéo qui sort de la container du main).
        'hero_card_fullwidth' => ['0', '1'],
        'hero_card_fullwidth_mobile' => ['0', '1'],
    ];
    $key    = (string)($_POST['sizeKey'] ?? '');
    $device = (string)($_POST['device']  ?? 'desktop');
    $rawVal = $_POST['sizeValue'] ?? '';
    // En mode mobile, on suffixe `_mobile` à la clé de taille (sauf si déjà suffixée).
    // Les clés d'alignement (text_align__*) et d'options ne sont pas device-aware.
    if ($device === 'mobile' && strpos($key, '_size') !== false && substr($key, -7) !== '_mobile') {
        $key .= '_mobile';
    }
    $isSize   = in_array($key, $allowedSizeKeys, true);
    $isAlign  = strpos($key, 'text_align__') === 0;
    $isOption = isset($allowedOptionKeys[$key]);
    if (!$isSize && !$isAlign && !$isOption) {
        http_response_code(400); echo json_encode(['ok' => false, 'err' => 'Clé non autorisée.']); exit;
    }
    if ($isSize) {
        $val = (int)$rawVal;
        // Plage adaptée par clé :
        //  - hauteur du hero : 300-1500 px
        //  - largeur du hero : 30-100 %
        //  - tailles d'éléments standards : 50-300 %
        $isHeroHeight = ($key === 'hero_card_height' || $key === 'hero_card_height_mobile');
        $isHeroWidth  = ($key === 'hero_card_width'  || $key === 'hero_card_width_mobile');
        $isMapHeight  = ($key === 'start_point_map_height' || $key === 'start_point_map_height_mobile');
        $isMapWidth   = ($key === 'start_point_map_width'  || $key === 'start_point_map_width_mobile');
        if ($isHeroHeight)      { $min = 300; $max = 1500; }
        elseif ($isHeroWidth)   { $min = 50;  $max = 100;  }
        elseif ($isMapHeight)   { $min = 180; $max = 900;  }
        elseif ($isMapWidth)    { $min = 40;  $max = 100;  }
        else                    { $min = 50;  $max = 300;  }
        if ($val < $min || $val > $max) {
            http_response_code(400); echo json_encode(['ok' => false, 'err' => "Valeur hors limites ($min-$max)."]); exit;
        }
    } elseif ($isAlign) {
        $val = (string)$rawVal;
        if (!in_array($val, ['left','center','right'], true)) {
            http_response_code(400); echo json_encode(['ok' => false, 'err' => 'Alignement invalide.']); exit;
        }
    } else { // isOption
        $val = (string)$rawVal;
        if (!in_array($val, $allowedOptionKeys[$key], true)) {
            http_response_code(400); echo json_encode(['ok' => false, 'err' => 'Valeur option invalide.']); exit;
        }
    }
    try {
        $row = $pdo->query('SELECT COALESCE(accueil_styles_draft, accueil_styles) AS s FROM setting WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $styles = [];
        if ($row && !empty($row['s'])) {
            $decoded = json_decode($row['s'], true);
            if (is_array($decoded)) $styles = $decoded;
        }
        $styles[$key] = $val;
        $json = json_encode($styles, JSON_UNESCAPED_UNICODE);
        $pdo->prepare('UPDATE setting SET accueil_styles_draft = :s, accueil_draft_updated_at = NOW() WHERE id = 1')->execute(['s' => $json]);
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        error_log('[SAVE_ACCUEIL_STYLE] ' . $e->getMessage());
        http_response_code(500); echo json_encode(['ok' => false, 'err' => 'Erreur serveur.']);
    }
    exit;
}

// ─── Handler dédié AJAX : sauvegarde du layout de l'accueil (JSON body) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (($_SERVER['CONTENT_TYPE'] ?? '') === 'application/json' || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0)) {
    $rawBody = file_get_contents('php://input');
    $jsonIn  = $rawBody ? json_decode($rawBody, true) : null;
    if (is_array($jsonIn) && !empty($jsonIn['save_accueil_layout'])) {
        header('Content-Type: application/json');
        if (!$canTab('accueil') || !$canCard('accueil', 'custom')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'err' => 'Action non autorisée.']);
            exit;
        }
        $incoming = $jsonIn['layout'] ?? null;
        if (!is_array($incoming)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'Layout invalide.']);
            exit;
        }
        // Sanitisation conditionnelle des blocs custom :
        // - kind='text' (TinyMCE WYSIWYG) → sanitizeHtml() classique (XSS protection)
        // - kind='html' (bloc code brut, admin-only) → contenu brut préservé tel quel
        foreach ($incoming as &$row) {
            if (!is_array($row) || empty($row['columns']) || !is_array($row['columns'])) continue;
            foreach ($row['columns'] as &$col) {
                if (!is_array($col) || !isset($col['section']) || !is_array($col['section'])) continue;
                if (($col['section']['type'] ?? '') !== 'custom') continue;
                if (!isset($col['section']['content'])) continue;
                $kind = (string)($col['section']['kind'] ?? 'text');
                if ($kind === 'text') {
                    $col['section']['content'] = sanitizeHtml((string)$col['section']['content']);
                }
                // html → préservé tel quel
            }
            unset($col);
        }
        unset($row);
        unset($row);
        require_once __DIR__ . '/../config/accueil_layout.php';
        try {
            saveAccueilLayout($pdo, $incoming);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            error_log('[ACCUEIL_LAYOUT] save error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'err' => 'Erreur enregistrement.']);
        }
        exit;
    }
}

// decodeHtmlField() est dans config.php


// Vérifier l'état actuel de la connexion
$isConnected = false;
$authUrl = '#';
if (($data['mail_provider'] ?? 'google') !== 'smtp') {
    try {
        $isConnected = isGoogleConnectionValid();
        $authUrl = getGoogleAuthUrl('setting.php');
    } catch (\Throwable $e) {
        // Google OAuth not configured or error - ignore
    }
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

$fields = ['inscription_no', 'nom', 'prenom', 'tel', 'email', 'naissance', 'sexe', 'ville', 'entreprise', 'paiement_mode', 'prestation', 'montant_du', 'created_at'];
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
    $newTitle = $isAjax ? decodeHtmlField($_POST['title'] ?? '') : ($_POST['title'] ?? '');
    $newTitleMobile = $isAjax ? decodeHtmlField($_POST['title_mobile'] ?? '') : ($_POST['title_mobile'] ?? '');

    $pdo->prepare('UPDATE setting SET title = :title, title_mobile = :title_mobile WHERE id = 1')
        ->execute(['title' => $newTitle, 'title_mobile' => $newTitleMobile]);

    addToast('success', 'En-tête enregistré !');
    $title = $newTitle;
    $title_mobile = $newTitleMobile;
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
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
   Logo du footer
-------------------------------------------------------------------------- */
if (isset($_POST['save_footer_logo'])) {
    $uploadDir = '../files/_logos/';
    $allowed = ['jpg','jpeg','png','gif','webp','svg'];
    $allowedMime = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];

    if (!empty($_FILES['footer_logo']['name'])) {
        $ext = strtolower(pathinfo($_FILES['footer_logo']['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['footer_logo']['tmp_name']);
        if (!in_array($ext, $allowed, true) || !in_array($mime, $allowedMime, true)) {
            addToast('danger', 'Format d\'image non autorisé.');
        } elseif ($_FILES['footer_logo']['size'] > 5 * 1024 * 1024) {
            addToast('danger', 'Image trop volumineuse (max 5 Mo).');
        } else {
            $safeName = uniqid('footer_', true) . '.' . $ext;
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            if (move_uploaded_file($_FILES['footer_logo']['tmp_name'], $uploadDir . $safeName)) {
                if ($footer_logo && $footer_logo !== 'logo_blanc.png' && file_exists($uploadDir . $footer_logo)) {
                    @unlink($uploadDir . $footer_logo);
                }
                $pdo->prepare('UPDATE setting SET footer_logo = :l WHERE id = 1')
                    ->execute(['l' => $safeName]);
                $footer_logo = $safeName;
                addToast('success', 'Logo du footer mis à jour !');
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
// Ajouter les fonts custom du dossier fonts/
$customFonts = getCustomFonts();
$allowedFonts = array_merge($allowedFonts, array_keys($customFonts));

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
    $course_km = max(1, (int) ($_POST['course_km'] ?? 7));
    $qrcode_mail_limit = max(0, (int) ($_POST['qrcode_mail_limit'] ?? 0));

    // Tarif enfant selon l'âge
    $child_pricing_enabled = !empty($_POST['child_pricing_enabled']) ? 1 : 0;
    $child_age_threshold   = min(120, max(1, (int) ($_POST['child_age_threshold'] ?? 12)));
    $child_amount          = min(100, max(0, (int) ($_POST['child_amount'] ?? 0)));

    $registration_auto_open  = !empty($_POST['registration_auto_open'])  ? date('Y-m-d H:i:s', strtotime($_POST['registration_auto_open']))  : null;
    $registration_auto_close = !empty($_POST['registration_auto_close']) ? date('Y-m-d H:i:s', strtotime($_POST['registration_auto_close'])) : null;

    $pdo->prepare(
        'UPDATE setting SET registration_fee = :fee, course_km = :course_km,
         accueil_active = :accueil_active, qrcode_mail_limit = :qrcode_mail_limit,
         child_pricing_enabled = :child_enabled, child_age_threshold = :child_age, child_amount = :child_amount,
         registration_auto_open = :auto_open, registration_auto_close = :auto_close
         WHERE id = 1'
    )->execute([
        'fee' => $registration_fee,
        'course_km' => $course_km,
        'accueil_active' => $accueil_active,
        'qrcode_mail_limit' => $qrcode_mail_limit,
        'child_enabled' => $child_pricing_enabled,
        'child_age' => $child_age_threshold,
        'child_amount' => $child_amount,
        'auto_open' => $registration_auto_open,
        'auto_close' => $registration_auto_close,
    ]);

    addToast('success', 'Paramètres d\'inscription enregistrés !');
}

/* --------------------------------------------------------------------------
   Message affiché quand les inscriptions sont fermées (TinyMCE)
   Même mécanisme que la réglementation : champ HTML encodé en Base64 côté JS
   (contournement WAF), décodé si AJAX puis nettoyé par sanitizeHtml (CWE-79).
-------------------------------------------------------------------------- */
if (isset($_POST['save_closed_message'])) {
    $rawMsg = $_POST['registration_closed_message'] ?? '';
    $registration_closed_message = sanitizeHtml(trim($isAjax ? decodeHtmlField($rawMsg) : $rawMsg));

    $upd = $pdo->prepare(
        'UPDATE setting SET registration_closed_message = :msg WHERE id = :id'
    );
    $ok = $upd->execute([
        'msg' => ($registration_closed_message !== '' ? $registration_closed_message : null),
        'id'  => 1,
    ]);

    if ($ok) {
        if ($upd->rowCount() > 0) {
            addToast('success', 'Message de fermeture enregistré !');
        } else {
            addToast('warning', 'Aucun changement détecté.', 10000);
        }
    } else {
        $msg = $upd->errorInfo()[2] ?? 'Erreur inconnue';
        addToast('danger', 'Erreur SQL&nbsp;: ' . htmlspecialchars($msg), 10000);
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
}

/* --------------------------------------------------------------------------
   Carte 1 : Liaison AssoConnect
-------------------------------------------------------------------------- */
if (isset($_POST['LinkAssoConnect'])) {

    /* a) Décodage Base64 des champs HTML (encodés côté JS pour contourner le WAF) */
    $iframe = $isAjax ? trim(decodeHtmlField($_POST['assoconnect_iframe'] ?? '')) : trim($_POST['assoconnect_iframe'] ?? '');
    $script = $isAjax ? trim(decodeHtmlField($_POST['assoconnect_js']     ?? '')) : trim($_POST['assoconnect_js']     ?? '');
    $url    = trim($_POST['assoconnect_url'] ?? '');

    /* b) Validation */
    $errors = [];

    // Lien direct (facultatif) : doit être une URL https valide si fourni
    if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $url))) {
        $errors[] = 'Le lien direct AssoConnect doit être une URL valide commençant par https://.';
    }

    // Code DIV + Script : obligatoires et au bon format
    if ($iframe === '' || $script === '') {
        $errors[] = 'Le code DIV et le code script sont obligatoires.';
    } else {
        if (!preg_match('#^<div[^>]+data-collect-id=["\'][A-Z0-9]{26}["\']#i', $iframe)) {
            $errors[] = 'Le code DIV doit contenir un data-collect-id AssoConnect valide.';
        }
        if (!preg_match('#^<script[^>]+src=["\']https://[a-z0-9.-]*\.assoconnect\.com/#i', $script)) {
            $errors[] = 'Le script doit pointer vers un domaine AssoConnect (https://xxx.assoconnect.com).';
        }
    }

    if (!empty($errors)) {
        foreach ($errors as $e) addToast('danger', $e, 10000);
    } else {

        /* c) Requête préparée — code DIV + script + lien direct */
        $upd = $pdo->prepare(
            'UPDATE setting
                SET assoconnect_iframe = :iframe,
                    assoconnect_js     = :script,
                    assoconnect_url    = :url
              WHERE id = :id'
        );

        $ok = $upd->execute([
            'iframe' => $iframe,
            'script' => $script,
            'url'    => $url !== '' ? $url : null,
            'id'     => 1
        ]);

        /* d) Gestion du résultat */
        if ($ok) {
            if ($upd->rowCount() > 0) {
                addToast('success', 'Liaison AssoConnect enregistrée !');
            } else {
                 addToast('warning', 'Aucun changement détecté.', 10000);
            }

            $assoconnectIframe = $iframe;
            $assoconnectJs     = $script;
            $assoconnectUrl    = $url;
        } else {
            $msg  = $upd->errorInfo()[2] ?? 'Erreur inconnue';
            addToast('danger', 'Erreur SQL&nbsp;: ' . htmlspecialchars($msg) , 10000);
        }
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
}

/* --------------------------------------------------------------------------
   Domaines autorisés AssoConnect (CSP) — carte dédiée
-------------------------------------------------------------------------- */
if (isset($_POST['save_csp_domains'])) {
    $raw   = (string)($_POST['assoconnect_csp_domains'] ?? '');
    $valid = [];
    $rejected = [];
    foreach (preg_split('/[\r\n,]+/', $raw) as $d) {
        $d = trim($d);
        if ($d === '') continue;
        // Sécurité : uniquement des origines https (sous-domaine joker autorisé).
        if (preg_match('#^https://(\*\.)?[a-z0-9.-]+\.[a-z]{2,}$#i', $d)) {
            $valid[$d] = true; // clé = dédoublonnage
        } else {
            $rejected[] = $d;
        }
    }
    $store = implode("\n", array_keys($valid));
    $pdo->prepare('UPDATE setting SET assoconnect_csp_domains = :d WHERE id = 1')
        ->execute(['d' => $store !== '' ? $store : null]);
    $assoconnectCspDomains = $store;
    if (!empty($rejected)) {
        addToast('warning', count($rejected) . ' domaine(s) ignoré(s) (format invalide, attendu https://...) : ' . htmlspecialchars(implode(', ', $rejected)), 10000);
    }
    addToast('success', 'Domaines autorisés enregistrés. Rechargez la page d\'inscription pour appliquer.');
}

/* --------------------------------------------------------------------------
   Accueil — Hero (titre/image sur la vidéo)
-------------------------------------------------------------------------- */
if (isset($_POST['save_hero'])) {
    $newTitleAccueil = $isAjax ? decodeHtmlField($_POST['titleAccueil'] ?? '') : ($_POST['titleAccueil'] ?? '');
    $newTitleAccueilMobile = $isAjax ? decodeHtmlField($_POST['titleAccueil_mobile'] ?? '') : ($_POST['titleAccueil_mobile'] ?? '');
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
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
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
    $uploaded = uploadImage($_FILES['picture_partner'] ?? [], $uploadDir);
    if ($uploaded) $newPicturePartner = $uploaded;

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
   Onglet API : activation + génération des identifiants
-------------------------------------------------------------------------- */
if (isset($_POST['save_api'])) {
    try {
        $apiEnabledNew = !empty($_POST['api_enabled']) ? 1 : 0;
        // Générer des identifiants si l'API est activée et qu'il n'en existe pas encore
        if ($apiEnabledNew && (empty($data['api_user']) || empty($data['api_token']))) {
            $api_user  = 'fer_' . bin2hex(random_bytes(8));
            $api_token = bin2hex(random_bytes(24));
            $pdo->prepare('UPDATE setting SET api_enabled = ?, api_user = ?, api_token = ? WHERE id = 1')
                ->execute([$apiEnabledNew, $api_user, encrypt($api_token)]);
        } else {
            $pdo->prepare('UPDATE setting SET api_enabled = ? WHERE id = 1')->execute([$apiEnabledNew]);
        }
        $api_enabled = $apiEnabledNew;
        addToast('success', $apiEnabledNew ? 'API activée !' : 'API désactivée.');
    } catch (\Throwable $e) {
        addToast('danger', "Impossible de mettre à jour l'API. Exécutez update.php pour appliquer les migrations.");
    }
}

if (isset($_POST['regenerate_api'])) {
    try {
        $api_user  = 'fer_' . bin2hex(random_bytes(8));
        $api_token = bin2hex(random_bytes(24));
        $pdo->prepare('UPDATE setting SET api_user = ?, api_token = ? WHERE id = 1')
            ->execute([$api_user, encrypt($api_token)]);
        addToast('success', 'Nouveaux identifiants API générés ! Pensez à les copier.');
    } catch (\Throwable $e) {
        addToast('danger', "Impossible de générer les identifiants. Exécutez update.php.");
    }
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
    $isAjax = isAjaxRequest();
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
    $rawRegl = $_POST['div_reglementation'] ?? '';
    $div_reglementation = sanitizeHtml(trim($isAjax ? decodeHtmlField($rawRegl) : $rawRegl));

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
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
}

/* --------------------------------------------------------------------------
   Carte : Formulaire
-------------------------------------------------------------------------- */
// Sauvegarde des champs formulaire
if (isset($_POST['save_fields'])) {
    // Détecte la présence des colonnes "saisie multiple" (peuvent être absentes
    // si update.php n'a pas encore été lancé pour appliquer la migration).
    $hasBulkCols = true;
    try {
        $pdo->query('SELECT visible_saisie_multiple FROM forms LIMIT 0');
    } catch (\PDOException $e) {
        $hasBulkCols = false;
    }

    // Colonne « Admin requis » (required_admin) : peut être absente si update.php
    // n'a pas encore été lancé. On l'inclut dans l'UPDATE seulement si présente.
    $hasReqAdmin = true;
    try {
        $pdo->query('SELECT required_admin FROM forms LIMIT 0');
    } catch (\PDOException $e) {
        $hasReqAdmin = false;
    }

    $sqlBase = 'UPDATE forms SET active = :active, required = :req,
                 visible_admin = :va, visible_saisie = :vs, visible_qr = :vq';
    $sqlReqAdmin = $hasReqAdmin ? ', required_admin = :ra' : '';
    $sqlBulk = $hasBulkCols
        ? ', visible_saisie_multiple = :vsm, required_saisie_multiple = :rsm'
        : '';
    $sqlUpd = $sqlBase . $sqlReqAdmin . $sqlBulk . ' WHERE id = :id';

    // Si l'« Autorisation parentale (mineur) » est active, le champ Commentaire doit
    // rester actif (les infos du responsable légal y sont enregistrées) : on force
    // son « actif » côté serveur, en plus du verrouillage de la case dans l'UI.
    $guardianFieldId = null;
    foreach ($allFields as $gf) {
        if (($gf['field_type'] ?? '') === 'guardian') { $guardianFieldId = $gf['id']; break; }
    }
    $guardianActivePost = $guardianFieldId !== null && isset($_POST["active_{$guardianFieldId}"]);

    foreach ($allFields as $f) {
        $id = $f['id'];
        $isLocked = (int) $f['is_locked'];

        if ($isLocked) {
            // Champs verrouillés : on autorise UNIQUEMENT la modification
            // de Bulk visible / Bulk requis. Les autres colonnes (active,
            // required, visible_admin/saisie/qr) restent figées.
            if ($hasBulkCols) {
                $updBulk = $pdo->prepare(
                    'UPDATE forms SET visible_saisie_multiple = :vsm,
                                       required_saisie_multiple = :rsm
                     WHERE id = :id'
                );
                $updBulk->execute([
                    'vsm' => isset($_POST["vsm_{$id}"]) ? 1 : 0,
                    'rsm' => isset($_POST["rsm_{$id}"]) ? 1 : 0,
                    'id'  => $id,
                ]);
            }
            continue;
        }

        // Champs non-verrouillés : update complet
        $upd = $pdo->prepare($sqlUpd);
        $params = [
            'active' => isset($_POST["active_{$id}"]) ? 1 : 0,
            'req'    => isset($_POST["required_{$id}"]) ? 1 : 0,
            'va'     => isset($_POST["va_{$id}"]) ? 1 : 0,
            'vs'     => isset($_POST["vs_{$id}"]) ? 1 : 0,
            'vq'     => isset($_POST["vq_{$id}"]) ? 1 : 0,
            'id'     => $id,
        ];
        if ($hasBulkCols) {
            $params['vsm'] = isset($_POST["vsm_{$id}"]) ? 1 : 0;
            $params['rsm'] = isset($_POST["rsm_{$id}"]) ? 1 : 0;
        }
        if ($hasReqAdmin) {
            $params['ra'] = isset($_POST["ra_{$id}"]) ? 1 : 0;
        }
        // Le champ Commentaire ne peut pas être désactivé tant que l'autorisation
        // parentale est active (« Requis » et visibilité, eux, restent libres).
        if (($f['bdd_column'] ?? '') === 'commentaire' && $guardianActivePost) {
            $params['active'] = 1;
        }
        // « Date d'inscription » (date_inscription) : champ réservé admin + ajout multiple.
        // On force Saisie/QR à 0 quoi qu'il arrive (contextes grand public interdits) ;
        // visible_public n'est de toute façon jamais touché par cette requête.
        if (($f['bdd_column'] ?? '') === 'date_inscription') {
            $params['vs'] = 0;
            $params['vq'] = 0;
        }
        $upd->execute($params);

        // Champ « Autorisation parentale (mineur) » : l'âge seuil de déclenchement
        // est stocké dans options_list (réutilisée comme paramètre du champ).
        if (($f['field_type'] ?? '') === 'guardian' && isset($_POST['guardian_age'])) {
            $gAge = (int) $_POST['guardian_age'];
            if ($gAge < 1)   $gAge = 18;
            if ($gAge > 120) $gAge = 120;
            $pdo->prepare('UPDATE forms SET options_list = :a WHERE id = :id')
                ->execute(['a' => (string) $gAge, 'id' => $id]);
        }

        // Texte de consentement du bloc « Autorisation parentale » (stocké dans help_text).
        if (($f['field_type'] ?? '') === 'guardian' && isset($_POST['guardian_consent'])) {
            $consent = mb_substr(trim((string) $_POST['guardian_consent']), 0, 1000);
            try {
                $pdo->prepare('UPDATE forms SET help_text = :h WHERE id = :id')
                    ->execute(['h' => ($consent !== '' ? $consent : null), 'id' => $id]);
            } catch (\PDOException $e) { /* colonne help_text absente avant migration */ }
        }
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

        // Emplacement : formulaire classique (colonne BDD) ou bloc « autorisation
        // parentale » (pas de colonne, valeur injectée dans le commentaire).
        $isGuardianField = (($_POST['new_section'] ?? 'form') === 'guardian');

        // Détecte la présence de la colonne guardian_section (migration jouée ?).
        $hasGuardianCol = true;
        try { $pdo->query('SELECT guardian_section FROM forms LIMIT 0'); }
        catch (\PDOException $e) { $hasGuardianCol = false; }

        // Vérification d'unicité uniquement pour un champ classique (colonne BDD).
        $exists = null;
        if (!$isGuardianField) {
            $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM forms WHERE bdd_column = ?');
            $existsStmt->execute([$colName]);
            $exists = (int) $existsStmt->fetchColumn();
        }

        if ($isGuardianField && !$hasGuardianCol) {
            addToast('danger', 'Champs « autorisation parentale » indisponibles : lancez update.php pour appliquer les migrations.');
        } elseif (!$isGuardianField && $exists > 0) {
            addToast('danger', 'Un champ avec ce nom existe déjà.');
        } else {
            try {
                // Colonne BDD uniquement pour un champ classique.
                if (!$isGuardianField) {
                    $pdo->exec("ALTER TABLE `registrations` ADD COLUMN `{$colName}` VARCHAR(255) DEFAULT NULL");
                }

                $maxSort = (int) $pdo->query('SELECT MAX(sort_order) FROM forms')->fetchColumn();

                // INSERT dynamique : les colonnes « saisie multiple » ont des valeurs
                // par défaut → on peut les omettre. On ajoute guardian_section si dispo.
                $cols = ['fields','label','field_type','bdd_column','active','required',
                         'is_locked','is_default','visible_public','visible_admin',
                         'visible_saisie','visible_qr','sort_order','options_list','encrypted'];
                $vals = ['custom_' . uniqid(), $newLabel, $newType,
                         $isGuardianField ? null : $colName,   // bdd_column
                         1, 0, 0, 0, 1, 1, 1, 1,
                         $maxSort + 1, ($newOpts ?: null),
                         $isGuardianField ? 0 : 1];             // encrypted
                if ($hasGuardianCol) {
                    $cols[] = 'guardian_section';
                    $vals[] = $isGuardianField ? 1 : 0;
                }
                $colList = '`' . implode('`,`', $cols) . '`';
                $ph      = implode(',', array_fill(0, count($cols), '?'));
                $pdo->prepare("INSERT INTO forms ($colList) VALUES ($ph)")->execute($vals);

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
            // DROP la colonne dans registrations UNIQUEMENT si le champ en possède une.
            // Les champs « autorisation parentale » (guardian_section) n'ont pas de
            // colonne BDD (bdd_column NULL, valeur injectée dans le commentaire).
            $col = trim((string) ($fieldToDelete['bdd_column'] ?? ''));
            $hadColumn = ($col !== '');
            if ($hadColumn) {
                $pdo->exec("ALTER TABLE `registrations` DROP COLUMN `{$col}`");
            }
            // Supprimer de forms
            $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$delId]);

            addToast('success', "Champ « {$fieldToDelete['label']} » supprimé"
                . ($hadColumn ? ' (colonne et données supprimées).' : '.'));
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
        'prestation',
        'montant_du',
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
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<title>Réglages</title>

<!-- ─── CSS ─── -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php if ($canCard('accueil', 'custom')): ?>
<!-- accueil.css : chargé pour le rendu réel des sections dans l'éditeur "Mise en page" -->
<link href="../css/accueil.css" rel="stylesheet">
<!-- CodeMirror 5 : éditeur de code pour les blocs HTML/CSS/JS custom -->
<link href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/lib/codemirror.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/theme/eclipse.min.css" rel="stylesheet">
<style>
  /* Override CodeMirror pour qu'il fill son container */
  .CodeMirror { height: 100% !important; font-size: 13px; font-family: 'SF Mono', 'Consolas', monospace; }
</style>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<!-- SheetJS : lecture côté client du fichier Excel pour l'aperçu d'import manuel (onglet Import AssoConnect) -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
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
    border-bottom-color: var(--primary, #f42182); background: transparent;
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

  /* ─────────────────────────────────────────────
     ÉDITEUR VISUEL « Mise en page accueil »
     ───────────────────────────────────────────── */
  .layout-editor {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
  }
  .le-rows { display: flex; flex-direction: column; gap: 12px; }
  .le-row {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    overflow: hidden; transition: box-shadow .15s, border-color .15s;
  }
  .le-row:hover { border-color: var(--primary, #f42182); }
  .le-row.le-row-ghost { opacity: 0.4; background: #fce7f3; border-style: dashed; }
  .le-row.le-row-chosen { box-shadow: 0 4px 14px color-mix(in srgb, var(--primary, #f42182) 25%, transparent); }
  .le-row-toolbar {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 10px; background: #f1f5f9;
    border-bottom: 1px solid #e2e8f0; font-size: 12px;
  }
  .le-row-info { color: #64748b; font-weight: 500; }
  .le-row-cols {
    display: flex; gap: 8px; padding: 8px;
    align-items: stretch; min-height: 80px;
  }
  .le-col {
    flex: var(--col-flex, 12) 0 0;
    min-width: 0;
    background: #fdf8fa; border: 1px solid #f0e8eb; border-radius: 8px;
    display: flex; flex-direction: column;
    transition: opacity .15s, border-color .15s;
  }
  .le-col.is-hidden { opacity: 0.5; background: repeating-linear-gradient(45deg, #f8fafc, #f8fafc 8px, #fdf2f8 8px, #fdf2f8 16px); }
  .le-col.le-col-ghost { opacity: 0.4; border-style: dashed; }
  .le-col.le-col-chosen { box-shadow: 0 2px 8px color-mix(in srgb, var(--primary, #f42182) 25%, transparent); }
  .le-col-toolbar {
    display: flex; align-items: center; gap: 6px;
    padding: 6px 8px; border-bottom: 1px solid #f0e8eb;
    font-size: 12px;
  }
  .le-col-preview { padding: 10px; flex: 1; min-height: 60px; }

  .le-handle { cursor: grab; color: #94a3b8; padding: 2px; user-select: none; }
  .le-handle:hover { color: var(--primary, #f42182); }
  .le-handle.le-row-handle { font-size: 16px; }
  .le-handle.le-col-handle { font-size: 14px; }

  /* ── Conteneur d'aperçu réel des sections (sans scroll, taille naturelle comme la page réelle) ── */
  .accueil-edit-preview {
    background: #fff;
    border-radius: 6px;
    pointer-events: none;
    user-select: none;
    overflow: visible;
  }
  .accueil-edit-preview img { max-width: 100%; height: auto; }
  .accueil-edit-preview .demo-card,
  .accueil-edit-preview .timeline-wrap { max-width: 100%; }

  /* ── Layout 2 colonnes : SIDEBAR À GAUCHE + main (preview) à droite ── */
  .layout-editor.le-with-sidebar {
    display: flex;
    gap: 16px;
    align-items: flex-start;
    flex-direction: row-reverse; /* main à droite, sidebar à gauche */
  }
  .le-main { flex: 1; min-width: 0; }
  .le-sidebar {
    width: 300px;
    flex-shrink: 0;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    position: sticky;
    top: 12px;
    max-height: calc(100vh - 100px);
    display: flex;
    flex-direction: column;
  }
  .le-sb-tabs { display: flex; border-bottom: 1px solid #e2e8f0; flex-shrink: 0; }
  .le-sb-tab {
    flex: 1; padding: 10px 8px; border: 0; background: transparent;
    font-size: 13px; font-weight: 600; color: #64748b; cursor: pointer;
    border-bottom: 2px solid transparent;
  }
  .le-sb-tab:hover { color: #0f172a; }
  .le-sb-tab.active { color: var(--primary, #f42182); border-bottom-color: var(--primary, #f42182); }
  .le-sb-content { padding: 14px; flex: 1; overflow-y: auto; }
  .le-sb-pane { display: none; }
  .le-sb-pane.active { display: block; }
  .le-sb-empty {
    text-align: center; color: #94a3b8; font-size: 13px;
    padding: 32px 8px;
  }
  .le-sb-title {
    font-size: 11px; text-transform: uppercase; letter-spacing: .08em;
    font-weight: 700; color: #94a3b8; margin: 0 0 10px;
  }
  .le-sb-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 8px; margin-bottom: 10px; font-size: 13px;
  }
  .le-sb-row label { color: #475569; font-size: 12px; flex-shrink: 0; }
  .le-sb-row select { width: auto; min-width: 80px; }
  .le-sb-add-btn {
    display: flex; align-items: center; gap: 10px;
    width: 100%; padding: 10px 12px; margin-bottom: 6px;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
    text-align: left; cursor: pointer; transition: .15s;
  }
  .le-sb-add-btn:hover { border-color: var(--primary, #f42182); background: #fdf2f8; }
  .le-sb-add-btn i { font-size: 18px; color: #9d174d; flex-shrink: 0; }
  .le-sb-add-btn strong { display: block; font-size: 13px; color: #1e293b; }
  .le-sb-add-btn small { font-size: 11px; color: #64748b; }

  /* ── Bouton "+" entre les rows ── */
  .le-add-row {
    display: flex; justify-content: center; align-items: center;
    height: 24px; margin: 2px 0; position: relative;
  }
  .le-add-row button {
    width: 28px; height: 28px; border-radius: 50%;
    border: 2px dashed #cbd5e1; background: #fff;
    color: #94a3b8; font-size: 16px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    opacity: 0.4; transition: .15s;
  }
  .le-add-row:hover button { opacity: 1; border-color: var(--primary, #f42182); color: var(--primary, #f42182); border-style: solid; }

  /* ── Section sélectionnée (par clic) ── */
  .le-col.is-selected,
  .le-section-block.is-selected {
    outline: 3px solid var(--primary, #f42182);
    outline-offset: 2px;
    border-radius: 8px;
  }

  /* ════════════════════════════════════════════════════════════
     IFRAME EDITOR (ife-*) — nouvelle architecture WYSIWYG
     ════════════════════════════════════════════════════════════ */
  .ife-layout {
    display: flex; gap: 16px; align-items: stretch;
    min-height: 70vh;
  }
  .ife-sidebar {
    width: 300px; flex-shrink: 0;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    display: flex; flex-direction: column;
    max-height: 80vh; position: sticky; top: 12px;
  }
  .ife-sb-tabs { display: flex; border-bottom: 1px solid #e2e8f0; }
  .ife-sb-tab {
    flex: 1; padding: 10px 8px; border: 0; background: transparent;
    font-size: 13px; font-weight: 600; color: #64748b; cursor: pointer;
    border-bottom: 2px solid transparent;
  }
  .ife-sb-tab:hover { color: #0f172a; }
  .ife-sb-tab.active { color: var(--primary, #f42182); border-bottom-color: var(--primary, #f42182); }
  .ife-sb-content { padding: 14px; flex: 1; overflow-y: auto; }
  .ife-sb-pane { display: none; }
  .ife-sb-pane.active { display: block; }
  .ife-sb-empty { text-align: center; color: #94a3b8; font-size: 13px; padding: 32px 8px; }
  .ife-sb-title {
    font-size: 11px; text-transform: uppercase; letter-spacing: .08em;
    font-weight: 700; color: #94a3b8; margin: 0 0 10px;
  }
  .ife-sb-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 8px; margin-bottom: 10px; font-size: 13px;
  }
  .ife-sb-row label { color: #475569; font-size: 12px; }
  .ife-sb-row select { min-width: 90px; padding-right: 28px; }
  /* Tableau de la ligne (multi-col) */
  .ife-sb-grid-label {
    display: block; color: #475569; font-size: 12px;
    margin: 6px 0 6px;
  }
  .ife-sb-grid {
    display: flex; gap: 4px; margin-bottom: 8px;
    border: 1px dashed #cbd5e1; border-radius: 6px;
    padding: 4px; background: #f8fafc; overflow: hidden;
  }
  .ife-sb-grid-cell {
    flex: 1 1 auto;
    background: #fff; border: 1px solid #e2e8f0;
    border-radius: 4px; padding: 6px 4px;
    display: flex; flex-direction: column; gap: 4px;
    text-align: center; min-width: 0;
    transition: border-color .15s;
  }
  .ife-sb-grid-cell:hover { border-color: var(--primary, #f42182); }
  .ife-sb-grid-cell .label {
    font-size: 10px; color: #64748b;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .ife-sb-grid-cell input {
    width: 100%; border: 0; background: #f8fafc;
    border-radius: 3px; text-align: center;
    font-size: 12px; font-weight: 600; color: #0f172a;
    padding: 2px;
  }
  .ife-sb-grid-cell input:focus { outline: 2px solid var(--primary, #f42182); }
  .ife-sb-grid-cell.is-overflow { border-color: #ef4444; background: #fef2f2; }
  .ife-sb-grid-cell.is-hidden { opacity: 0.55; background: repeating-linear-gradient(45deg, transparent, transparent 6px, rgba(148,163,184,.08) 6px, rgba(148,163,184,.08) 12px); }
  /* Cellule en cours de drag (Sortable) */
  .ife-sb-grid-cell.is-dragging { opacity: .5; }
  .ife-sb-grid-cell .drag-handle {
    font-size: 11px; color: #94a3b8; cursor: grab;
    line-height: 1;
  }
  .ife-sb-grid-cell .drag-handle:active { cursor: grabbing; }
  /* Boutons d'action par cellule (edit / delete / hide) */
  .ife-sb-grid-cell .cell-actions {
    display: flex; gap: 2px; justify-content: center;
    margin-top: 4px; border-top: 1px solid #e2e8f0; padding-top: 4px;
  }
  .ife-sb-grid-cell .cell-btn {
    border: 0; background: transparent; padding: 3px 5px;
    border-radius: 4px; color: #64748b; cursor: pointer;
    font-size: 12px; line-height: 1;
    transition: background .12s, color .12s;
  }
  .ife-sb-grid-cell .cell-btn:hover { background: #f1f5f9; color: #0f172a; }
  .ife-sb-grid-cell .cell-btn-danger:hover { background: #fef2f2; color: #ef4444; }
  .ife-sb-grid-presets {
    display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 8px;
  }
  .ife-sb-grid-presets .btn { font-size: 11px; padding: 2px 8px; }
  .ife-sb-align-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 8px; margin: 6px 0 12px; font-size: 12px;
  }
  .ife-sb-align-row label { color: #475569; font-size: 12px; }
  .ife-sb-align-row .btn { padding: 2px 8px; }
  .ife-sb-align-row .btn.active { background: var(--primary, #f42182); color: var(--primary-text, #fff); border-color: var(--primary, #f42182); }
  /* Labels de section dans la sidebar (clarifient ce qui suit) */
  .ife-sb-section-label {
    font-size: 10px; text-transform: uppercase; letter-spacing: .06em;
    font-weight: 700; color: #94a3b8;
    margin: 12px 0 6px; padding-top: 8px; border-top: 1px solid #e2e8f0;
  }
  .ife-sb-section-label:first-child { border-top: 0; padding-top: 0; margin-top: 0; }
  /* Blocs d'options de section empilés (ex: reg_bar + news dans une même ligne) :
     chaque bloc est un div wrapper, donc son label est :first-child — on restaure
     le séparateur pour tous les blocs sauf le tout premier du panneau. */
  .ife-sb-section-options > div:not(:first-child) > .ife-sb-section-label:first-child {
    border-top: 1px solid #e2e8f0; padding-top: 8px; margin-top: 12px;
  }
  /* Icône info à côté d'un label de section : déclenche un tooltip Bootstrap */
  .ife-sb-info-icon {
    color: #94a3b8; font-size: 12px; margin-left: 4px;
    cursor: help; vertical-align: middle;
    transition: color .12s;
  }
  .ife-sb-info-icon:hover, .ife-sb-info-icon:focus { color: var(--primary, #f42182); outline: none; }
  /* Tooltip personnalisé (plus large + texte lisible) */
  .ife-sb-tooltip .tooltip-inner {
    max-width: 280px; text-align: left;
    background: #0f172a; color: #fff;
    padding: 8px 12px; font-size: 12px; line-height: 1.4;
  }
  .ife-sb-tooltip .tooltip-inner strong { color: #fdf2f8; }
  .ife-sb-tooltip .tooltip-inner em { color: #fbcfe8; font-style: italic; }
  .ife-sb-tooltip.bs-tooltip-end .tooltip-arrow::before { border-right-color: #0f172a; }
  .ife-sb-tooltip.bs-tooltip-start .tooltip-arrow::before { border-left-color: #0f172a; }
  .ife-sb-tooltip.bs-tooltip-top .tooltip-arrow::before { border-top-color: #0f172a; }
  .ife-sb-tooltip.bs-tooltip-bottom .tooltip-arrow::before { border-bottom-color: #0f172a; }
  /* Espacement de ligne (margin haut/bas) */
  .ife-sb-spacing-row { margin: 8px 0; }
  .ife-sb-spacing-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
  }
  .ife-sb-spacing-grid label { font-size: 11px; display: block; margin-bottom: 2px; }
  /* Badge "Modifications non publiées" affiché au-dessus du bouton Publier */
  .ife-draft-badge {
    display: flex; align-items: center; gap: 8px;
    background: #fef3c7; border: 1px solid #fcd34d; color: #92400e;
    padding: 8px 12px; border-radius: 8px;
    font-size: 12px; font-weight: 600;
    margin-bottom: 10px;
    animation: ife-draft-pulse 2s ease-in-out infinite;
  }
  .ife-draft-badge i {
    color: #f59e0b; font-size: 10px;
    animation: ife-draft-blink 1.5s ease-in-out infinite;
  }
  @keyframes ife-draft-blink {
    50% { opacity: .3; }
  }
  @keyframes ife-draft-pulse {
    50% { background: #fde68a; }
  }

  /* Liste des éléments éditables dans la sidebar (sous les contrôles d'une row) */
  .ife-sb-editable-list { margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0; }
  .ife-sb-editable-head {
    font-size: 11px; text-transform: uppercase; letter-spacing: .08em;
    font-weight: 700; color: #94a3b8; margin: 0 0 8px;
  }
  .ife-sb-editable-btn {
    display: flex; align-items: center; gap: 10px;
    width: 100%; padding: 8px 10px; margin-bottom: 4px;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
    text-align: left; cursor: pointer; transition: .12s;
    font-size: 13px;
  }
  .ife-sb-editable-btn:hover { border-color: var(--primary, #f42182); background: #fdf2f8; }
  .ife-sb-editable-btn > i:first-child { color: var(--primary, #f42182); font-size: 16px; flex-shrink: 0; }
  .ife-sb-editable-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 1px; }
  .ife-sb-editable-info strong { font-size: 12px; color: #0f172a; font-weight: 600; }
  .ife-sb-editable-kind { font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
  .ife-sb-editable-preview {
    font-size: 11px; color: #64748b; font-style: italic;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .ife-sb-editable-chev { color: #cbd5e1; font-size: 12px; flex-shrink: 0; }
  /* Options spécifiques d'une section (ex: news.card_style) */
  .ife-sb-section-options { margin-top: 12px; }
  .ife-sb-toggle-row {
    display: flex; flex-direction: column; gap: 6px;
    margin-bottom: 8px;
  }
  .ife-sb-toggle-row label {
    font-size: 12px; color: #475569; font-weight: 600;
  }
  .ife-sb-toggle-row .btn-group .btn {
    font-size: 12px; padding: 6px 10px;
  }
  .ife-sb-toggle-row .btn-group .btn.active {
    background: var(--primary, #f42182); color: var(--primary-text, #fff); border-color: var(--primary, #f42182);
  }
  .ife-sb-toggle-row .btn-group .btn i { margin-right: 4px; font-size: 12px; }
  /* Barre d'alignement (6 boutons) sous une entry de bloc HTML */
  .ife-sb-html-align-bar {
    margin: -2px 0 6px 0; padding: 8px 10px;
    background: #f8fafc; border: 1px solid #e2e8f0; border-top: 0;
    border-radius: 0 0 8px 8px;
    display: flex; flex-direction: column; gap: 6px;
  }
  .ife-sb-html-align-row {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
  }
  .ife-sb-html-align-label {
    font-size: 11px; color: #64748b; font-weight: 600;
    text-transform: uppercase; letter-spacing: .04em;
  }
  .ife-sb-html-align-bar .btn { padding: 2px 6px; font-size: 11px; }
  .ife-sb-html-align-bar .btn.active { background: var(--primary, #f42182); color: var(--primary-text, #fff); border-color: var(--primary, #f42182); }

  .ife-sb-add-btn {
    display: flex; align-items: center; gap: 10px;
    width: 100%; padding: 10px 12px; margin-bottom: 6px;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
    text-align: left; cursor: pointer; transition: .15s;
  }
  .ife-sb-add-btn:hover { border-color: var(--primary, #f42182); background: #fdf2f8; }
  .ife-sb-add-btn i { font-size: 18px; color: #9d174d; flex-shrink: 0; }
  .ife-sb-add-btn strong { display: block; font-size: 13px; color: #1e293b; }
  .ife-sb-add-btn small { font-size: 11px; color: #64748b; }
  .ife-sb-footer { padding: 12px; border-top: 1px solid #e2e8f0; }

  /* ── Aperçu iframe + overlay ── */
  .ife-preview-wrap {
    flex: 1; min-width: 0;
    position: relative;
    /* Volontairement SANS overflow : un conteneur de défilement ici casserait le
       position:sticky de la toolbar. Le visuel (bordure/fond/coins arrondis) et le
       scroll horizontal de l'iframe large sont portés par .ife-preview-scroll. */
  }
  /* Conteneur interne : scroll horizontal (iframe min-width:1100 en desktop) + clip. */
  .ife-preview-scroll {
    position: relative;
    overflow-x: auto;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
  }
  /* Spinner de chargement de l'éditeur : recouvre l'aperçu (.ife-preview-wrap est
     position:relative) jusqu'à ce que l'iframe + les overlays soient prêts. */
  .ife-loader {
    position: absolute; inset: 0; z-index: 50;
    /* centré horizontalement, mais en HAUT (pas centré verticalement) */
    display: flex; flex-direction: column; align-items: center; justify-content: flex-start;
    padding-top: 48px;
    gap: 14px; background: #fff; border-radius: 10px;
    color: #64748b; font-size: 14px; font-weight: 500;
    transition: opacity .35s ease;
  }
  .ife-loader.is-hidden { opacity: 0; pointer-events: none; }
  .ife-loader p { margin: 0; }
  .ife-loader-spin {
    width: 2.75rem; height: 2.75rem;
    border: 3px solid #fce7f3; border-top-color: var(--primary, #f42182);
    border-radius: 50%;
    animation: ife-spin .8s linear infinite;
  }
  @keyframes ife-spin { to { transform: rotate(360deg); } }
  .ife-preview-wrap iframe {
    display: block; width: 100%; min-height: 600px;
    /* CRITIQUE : min-width simule un viewport "large desktop" (>1040px) pour que
       le CSS mobile d'accueil.css ne s'active PAS dans l'iframe quand la zone du
       parent est étroite (sidebar 300px + écran 1280px = iframe ~960px → sans
       min-width, accueil.php basculait en mode mobile et la social-card s'étirait
       sur toute la largeur même en édition Desktop). */
    min-width: 1100px;
    border: 0; background: #fff;
    /* PAS de transition CSS sur max/min-width : sinon, au toggle Mobile/Desktop, l'iframe
       met 200ms à se redimensionner et applyHeroPositionPct calcule avec une cardRect
       intermédiaire → video_toggle (et autres) atterrissent à un endroit incorrect.
       Sans transition, le resize est INSTANTANÉ → la 1re reapply est correcte. */
    /* La hauteur est ajustée dynamiquement par JS au contenu réel */
  }
  /* Mode mobile : largeur d'aperçu contrainte pour simuler un téléphone.
     Le min-width:1100px est retiré : l'iframe doit pouvoir être étroite (≤420px)
     pour que le CSS @media max-width:1040px s'active dans accueil.php. */
  /* En mode mobile : fond sombre + iframe centrée en max-width:420px, sur le conteneur
     interne (qui porte désormais l'overflow). overflow:hidden masque la scrollbar
     horizontale parasite (la nav interne se fait via l'iframe). */
  .ife-preview-wrap.is-mobile .ife-preview-scroll { background: #1f2937; overflow: hidden; }
  .ife-preview-wrap.is-mobile iframe {
    max-width: 420px;
    min-width: 0;
    margin: 0 auto;
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
  }
  /* Barre d'outils : toggle device + migration/restauration.
     - float:right + placée juste avant le <h2> → elle s'affiche EN FACE du titre
       « Mise en page de l'accueil » (le titre coule à sa gauche).
     - position:sticky;top:8px → elle reste collée en haut au scroll. Son conteneur
       de stickiness est la carte (.setting-card, haute) → elle reste accrochée
       PENDANT TOUT le défilement de l'éditeur, puis se détache quand on dépasse la carte.
     - À droite ⇒ aucun chevauchement avec la sidebar (sticky, à gauche). */
  .ife-preview-toolbar {
    /* top négatif : compense le padding-top (~28px) de #oc-content pour que, une
       fois collée, la barre soit proche de la barre d'admin fixe (moins d'espace).
       margin-top négatif : remonte la barre flottée pour la CENTRER sur le texte
       du titre (la barre ~38px est plus haute que le texte) — elle ne coupe donc
       plus le trait sans avoir à descendre celui-ci. */
    position: sticky; top: -20px;
    float: right;
    margin: -15px 0 6px 12px;
    display: flex; gap: 6px; align-items: center; flex-wrap: wrap;
    z-index: 1000; pointer-events: auto;
    background: rgba(255,255,255,.96);
    border: 1px solid #e2e8f0; border-radius: 8px;
    padding: 4px; box-shadow: 0 4px 14px rgba(0,0,0,.12);
  }
  .ife-preview-toolbar .ife-device-group {
    display: inline-flex; gap: 2px;
    background: #f1f5f9; border-radius: 6px; padding: 2px;
  }
  .ife-preview-toolbar .ife-device-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px; font-size: 12px; font-weight: 600;
    color: #475569; background: transparent;
    border: 0; border-radius: 5px; cursor: pointer;
    transition: background .15s, color .15s;
  }
  .ife-preview-toolbar .ife-device-btn:hover { color: #1e293b; }
  .ife-preview-toolbar .ife-device-btn.is-active {
    background: #fff; color: var(--primary, #f42182);
    box-shadow: 0 1px 2px rgba(0,0,0,.06);
  }
  .ife-preview-toolbar .ife-migrate-btn,
  .ife-preview-toolbar .ife-restore-btn {
    padding: 5px 10px; font-size: 12px; font-weight: 600;
    color: #475569; background: #fff;
    border: 1px solid #cbd5e1; border-radius: 6px; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .ife-preview-toolbar .ife-migrate-btn:hover,
  .ife-preview-toolbar .ife-restore-btn:hover {
    border-color: var(--primary, #f42182); color: var(--primary, #f42182);
  }
  .ife-preview-toolbar .ife-migrate-btn[disabled],
  .ife-preview-toolbar .ife-restore-btn[disabled] {
    opacity: .5; cursor: not-allowed;
  }
  /* Bouton "Restaurer hero" : variante warning pour signaler l'action destructive. */
  .ife-preview-toolbar .ife-restore-btn { color: #b45309; border-color: #fcd34d; background: #fffbeb; }
  .ife-preview-toolbar .ife-restore-btn:hover { color: #fff; background: #f59e0b; border-color: #f59e0b; }
  /* Les <i> / <span> à l'intérieur des boutons ne doivent pas voler le clic
     (sinon event.target n'est pas le bouton et la délégation rate). */
  .ife-preview-toolbar .ife-device-btn > *,
  .ife-preview-toolbar .ife-migrate-btn > *,
  .ife-preview-toolbar .ife-restore-btn > * { pointer-events: none; }
  .ife-overlay {
    position: absolute; inset: 0; pointer-events: none;
    z-index: 10;
  }
  /* Liste de suggestions d'adresses (autocomplétion du point de départ). */
  .ife-sp-suggest {
    position: absolute; left: 0; right: 0; top: 100%;
    margin-top: 2px; z-index: 30;
    background: #fff; border: 1px solid #cbd5e1; border-radius: 6px;
    box-shadow: 0 8px 22px rgba(0,0,0,.12);
    max-height: 220px; overflow-y: auto;
  }
  .ife-sp-suggest-item {
    padding: 7px 10px; font-size: 12px; color: #334155;
    cursor: pointer; border-bottom: 1px solid #f1f5f9;
  }
  .ife-sp-suggest-item:last-child { border-bottom: 0; }
  .ife-sp-suggest-item:hover { background: #fdf2f8; color: var(--primary-hover, #be185d); }
  /* outline (et pas border) : ne prend AUCUN espace dans le layout, donc le rail
     de drag ne se décale plus au hover → plus de clignotement infini */
  .ife-row-overlay {
    position: absolute; pointer-events: none;
    /* Harmonisé avec les pointillés des éléments du hero : 3 px dashed + 4 px gap. */
    outline: 3px dashed transparent; outline-offset: 4px; border-radius: 4px;
    transition: outline-color .15s;
  }
  .ife-row-overlay:hover { outline-color: color-mix(in srgb, var(--primary, #f42182) 50%, transparent); }
  .ife-row-overlay.is-selected { outline-color: var(--primary, #f42182); }
  /* Rail de drag visible toujours à gauche pour pouvoir glisser la section */
  .ife-row-drag-rail {
    position: absolute;
    left: 0; top: 0; bottom: 0; width: 14px;
    background: color-mix(in srgb, var(--primary, #f42182) 15%, transparent);
    border-radius: 4px 0 0 4px;
    cursor: grab;
    pointer-events: auto;
    z-index: 11;
    transition: background .15s, width .15s;
    display: flex; align-items: center; justify-content: center;
    color: color-mix(in srgb, var(--primary, #f42182) 60%, transparent);
    font-size: 14px;
  }
  .ife-row-drag-rail:hover, .ife-row-drag-rail:active {
    background: color-mix(in srgb, var(--primary, #f42182) 45%, transparent); width: 22px;
    color: #fff;
  }
  .ife-row-drag-rail:active { cursor: grabbing; }
  .ife-row-actions {
    position: absolute; top: 4px; right: 4px;
    pointer-events: auto;
    display: none; gap: 4px;
    background: #fff; border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,.2);
    padding: 2px; z-index: 12;
  }
  .ife-row-overlay:hover .ife-row-actions,
  .ife-row-overlay.is-selected .ife-row-actions { display: flex; }
  .ife-row-actions button {
    width: 28px; height: 28px; border: 0; background: transparent;
    border-radius: 6px; cursor: pointer; color: #475569; padding: 0;
    display: flex; align-items: center; justify-content: center;
  }
  .ife-row-actions button:hover { background: #f1f5f9; }
  .ife-row-actions button.danger:hover { background: #fef2f2; color: #ef4444; }
  .ife-row-actions .ife-handle { cursor: grab; color: #94a3b8; }
  .ife-add-marker {
    position: absolute; pointer-events: auto;
    height: 28px; left: 0; right: 0;
    display: flex; align-items: center; justify-content: center;
    z-index: 11;
  }
  .ife-add-marker button {
    width: 28px; height: 28px; border-radius: 50%;
    border: 2px dashed #cbd5e1; background: #fff;
    color: #94a3b8; font-size: 16px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: .15s;
  }
  .ife-add-marker:hover button { opacity: 1; border-color: var(--primary, #f42182); color: var(--primary, #f42182); border-style: solid; }
  .ife-overlay:hover .ife-add-marker button { opacity: 0.4; }

  /* Menu contextuel (clic droit) flottant au-dessus de l'iframe */
  .ife-ctx-menu {
    position: fixed; z-index: 10000;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
    box-shadow: 0 10px 32px rgba(0,0,0,.18);
    padding: 4px;
    min-width: 220px;
    font-size: 13px;
    animation: ife-ctx-in .12s ease-out;
  }
  @keyframes ife-ctx-in {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .ife-ctx-item {
    display: flex; align-items: center; gap: 10px;
    width: 100%; padding: 8px 12px;
    background: transparent; border: 0; border-radius: 6px;
    text-align: left; cursor: pointer; color: #0f172a;
    font-size: 13px;
  }
  .ife-ctx-item i { color: #64748b; font-size: 14px; }
  .ife-ctx-item:hover { background: #f1f5f9; }
  .ife-ctx-item.is-danger:hover { background: #fef2f2; color: #ef4444; }
  .ife-ctx-item.is-danger:hover i { color: #ef4444; }
  .ife-ctx-sep {
    height: 1px; background: #e2e8f0; margin: 4px 6px;
  }

  /* Drop overlay (full-screen fixed, contient as-col + new-row) */
  .ife-drop-overlay { pointer-events: none; }

  /* Drop slots verticaux (as-col) — insérer comme colonne dans la ligne */
  .ife-drop-slot {
    pointer-events: none;
    border: 2px dashed color-mix(in srgb, var(--primary, #f42182) 75%, transparent);
    background: color-mix(in srgb, var(--primary, #f42182) 18%, transparent);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    transition: background .12s, border-color .12s, transform .12s;
    box-sizing: border-box;
  }
  .ife-drop-slot span {
    font-size: 12px; color: var(--primary-text, #fff); font-weight: 700;
    background: var(--primary, #f42182); padding: 6px 14px; border-radius: 14px;
    box-shadow: 0 4px 12px color-mix(in srgb, var(--primary, #f42182) 40%, transparent);
    white-space: nowrap;
    letter-spacing: 0.02em;
  }
  .ife-drop-slot.is-hot {
    background: color-mix(in srgb, var(--primary, #f42182) 38%, transparent);
    border-color: var(--primary, #f42182); border-style: solid; border-width: 3px;
    transform: scale(1.01);
  }
  .ife-drop-slot.is-hot span {
    background: var(--primary-hover, #be185d);
    transform: scale(1.08);
  }

  /* Drop bands horizontales (new-row) — insérer comme nouvelle ligne */
  .ife-drop-band {
    pointer-events: none;
    background: #6366f1;
    border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    transition: background .12s, transform .12s, box-shadow .12s;
    box-sizing: border-box;
    box-shadow: 0 0 0 1px rgba(99,102,241,.4);
  }
  .ife-drop-band span {
    font-size: 11px; color: #fff; font-weight: 700;
    background: #6366f1; padding: 5px 12px; border-radius: 14px;
    box-shadow: 0 3px 10px rgba(99,102,241,.5);
    white-space: nowrap;
    position: relative; top: 0;
  }
  .ife-drop-band.is-hot {
    background: #4338ca;
    transform: scaleY(2);
    box-shadow: 0 0 0 2px #4338ca, 0 6px 20px rgba(67,56,202,.5);
  }
  .ife-drop-band.is-hot span {
    background: #4338ca;
    transform: scale(1.15);
  }

  /* Drop zone "merge en colonnes" pendant le drag */
  .ife-merge-hint {
    position: absolute; inset: 4px;
    display: flex; align-items: center; justify-content: center;
    background: color-mix(in srgb, var(--primary, #f42182) 85%, transparent);
    color: var(--primary-text, #fff); font-weight: 700; font-size: 13px;
    border-radius: 6px;
    pointer-events: none; z-index: 30;
    text-shadow: 0 1px 2px rgba(0,0,0,.4);
    animation: ife-merge-pulse 0.6s ease-in-out infinite alternate;
  }
  @keyframes ife-merge-pulse {
    from { background: color-mix(in srgb, var(--primary, #f42182) 70%, transparent); }
    to   { background: color-mix(in srgb, var(--primary, #f42182) 95%, transparent); }
  }
  .ife-row-overlay.is-merge-target { box-shadow: 0 0 0 4px var(--primary, #f42182); }

  /* ════════════════════════════════════════════════════════════
     ANCIEN ÉDITEUR (le-*) — DEAD CODE après refonte iframe
     ════════════════════════════════════════════════════════════ */

  /* Contenairs : transparents, pas de bordure visible */
  .le-row, .le-section-block, .le-col {
    background: transparent !important;
    border: none !important;
    padding: 0 !important;
    margin: 0 !important;
    position: relative;
    border-radius: 4px;
  }
  .le-rows { gap: 8px; display: flex; flex-direction: column; }
  .le-row-cols { padding: 0 !important; gap: 8px !important; display: flex; align-items: stretch; }
  .le-col-preview { padding: 0 !important; }

  /* Outline au hover/select */
  .le-row, .le-section-block {
    outline: 2px solid transparent; outline-offset: 4px;
    transition: outline .12s;
  }
  .le-row:hover, .le-section-block:hover { outline-color: color-mix(in srgb, var(--primary, #f42182) 35%, transparent); }
  .le-row.is-selected, .le-section-block.is-selected { outline-color: var(--primary, #f42182) !important; outline-offset: 6px; }

  .le-col {
    /* Harmonisé avec les pointillés des éléments du hero : 3 px dashed + 4 px gap. */
    outline: 3px dashed transparent; outline-offset: 4px;
    transition: outline .12s;
  }
  .le-col:hover { outline-color: color-mix(in srgb, var(--primary, #f42182) 35%, transparent); }
  .le-col.is-selected { outline-color: var(--primary, #f42182) !important; outline-style: solid; }

  /* Toolbars : mini-float top-right INTERNE (style sec-actions du mail editor) */
  .le-row-toolbar, .le-section-toolbar, .le-col-toolbar {
    position: absolute;
    top: -14px; right: -8px; z-index: 20;
    display: none;
    align-items: center;
    gap: 2px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,.15);
    padding: 2px;
  }
  .le-row:hover > .le-row-toolbar,
  .le-row.is-selected > .le-row-toolbar,
  .le-section-block:hover > .le-section-toolbar,
  .le-section-block.is-selected > .le-section-toolbar,
  .le-col:hover > .le-col-toolbar,
  .le-col.is-selected > .le-col-toolbar { display: flex; }

  /* Boutons mini dans les toolbars */
  .le-row-toolbar button,
  .le-section-toolbar button,
  .le-col-toolbar button {
    width: 26px; height: 26px;
    border: 0; background: transparent; border-radius: 6px;
    cursor: pointer; font-size: 13px;
    display: flex; align-items: center; justify-content: center;
    color: #475569; transition: .1s;
    padding: 0;
  }
  .le-row-toolbar button:hover,
  .le-section-toolbar button:hover,
  .le-col-toolbar button:hover { background: #f1f5f9; }
  .le-row-toolbar button.btn-outline-danger,
  .le-col-toolbar button.btn-outline-danger { color: #ef4444; }
  .le-row-toolbar button.btn-outline-danger:hover,
  .le-col-toolbar button.btn-outline-danger:hover { background: #fef2f2; }

  /* Cache les éléments décoratifs anciens : tag/hint/info */
  .le-section-hint, .le-row-info, .le-section-tag { display: none !important; }
  /* Cache les selects de largeur dans le toolbar (la largeur se règle dans la sidebar maintenant) */
  .le-row-toolbar .le-width-select,
  .le-col-toolbar .le-width-select { display: none; }
  /* Drag handle : petit, en haut-gauche du toolbar, déclenche le drag Sortable */
  .le-row-toolbar .le-handle,
  .le-col-toolbar .le-handle {
    color: #94a3b8; cursor: grab; padding: 4px 6px;
    font-size: 14px; line-height: 1;
  }
  .le-row-toolbar .le-handle:hover,
  .le-col-toolbar .le-handle:hover { color: var(--primary, #f42182); }
  .le-row-toolbar .ms-auto, .le-col-toolbar .ms-auto { margin-left: 0 !important; }

  /* ── Éléments éditables (cliquables dans l'aperçu) ── */
  .accueil-edit-preview [data-edit-field] {
    cursor: pointer !important;
    pointer-events: auto !important;
    transition: outline .15s;
    /* Harmonisé avec le style commun : 3 px dashed + 4 px gap. */
    outline: 3px dashed transparent;
    outline-offset: 4px;
  }
  .accueil-edit-preview [data-edit-field]:hover {
    outline-color: var(--primary, #f42182);
  }
  .accueil-edit-preview [data-edit-field]::after {
    content: "✏️"; position: absolute; top: 4px; right: 4px;
    background: var(--primary, #f42182); color: var(--primary-text, #fff); padding: 2px 6px;
    border-radius: 999px; font-size: 11px; opacity: 0;
    transition: .15s; pointer-events: none;
  }
  .accueil-edit-preview [data-edit-field]:hover::after { opacity: 1; }
  .accueil-edit-preview [data-edit-field][data-edit-kind="image"],
  .accueil-edit-preview [data-edit-field][data-edit-kind="video"] {
    position: relative;
  }
  .accueil-edit-preview [data-edit-field].le-edit-selected {
    outline: 3px solid var(--primary, #f42182) !important;
    outline-offset: 4px;
    background: color-mix(in srgb, var(--primary, #f42182) 5%, transparent);
  }
</style>

<?php
// ── Import automatique AssoConnect : sauvegarde de la configuration ──────────
// (CSRF + permission settings.tab.import_auto déjà vérifiés plus haut.)
// Le bouton porte name="save_import_auto" value="save|run|test" : on enregistre
// toujours la config saisie, et on positionne le drapeau run/test si demandé.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_import_auto'])) {
    require_once __DIR__ . '/../config/sync_assoconnect.php';

    $action = in_array($_POST['save_import_auto'], ['save', 'run', 'test'], true) ? $_POST['save_import_auto'] : 'save';

    $enabled        = isset($_POST['ac_enabled']) ? 1 : 0;
    $importSendMail = isset($_POST['ac_import_send_mail']) ? 1 : 0;
    $loginUrl       = trim((string) ($_POST['ac_login_url'] ?? ''));
    $registUrl      = trim((string) ($_POST['ac_registrants_url'] ?? ''));
    $email          = trim((string) ($_POST['ac_email'] ?? ''));
    $interval       = (int) ($_POST['ac_interval_min'] ?? 30);
    if ($interval < 5)    $interval = 5;     // borne basse = résolution du cron (5 min)
    if ($interval > 1440) $interval = 1440;  // borne haute = 1 jour

    // URLs : autorisées vides, sinon https:// valide.
    $urlOk = static function (string $u): bool {
        return $u === '' || (filter_var($u, FILTER_VALIDATE_URL) !== false && str_starts_with($u, 'https://'));
    };

    if (!$urlOk($loginUrl) || !$urlOk($registUrl)) {
        addToast('danger', 'URL AssoConnect invalide (elle doit commencer par https://).');
    } else {
        $fields = ['enabled = ?', 'ac_login_url = ?', 'ac_registrants_url = ?',
                   'ac_email = ?', 'import_send_mail = ?', 'interval_min = ?'];
        $params = [$enabled, $loginUrl ?: null, $registUrl ?: null, $email ?: null, $importSendMail, $interval];

        // Mot de passe WRITE-ONLY : on ne le (re)chiffre que s'il a été saisi.
        // Chiffrement = celui du site (encrypt()/ENCRYPTION_KEY, AES-256-GCM).
        $newPass = (string) ($_POST['ac_password'] ?? '');
        if ($newPass !== '') {
            $fields[] = 'ac_password_enc = ?';
            $params[] = encrypt($newPass);
        }

        if ($action === 'run') { $fields[] = 'run_requested = 1'; }

        try {
            $pdo->prepare('UPDATE sync_assoconnect SET ' . implode(', ', $fields) . ' WHERE id = 1')
                ->execute($params);

            if ($action === 'test') {
                // Test SYNCHRONE : connexion + export immédiats (sans import) → résultat tout de suite.
                @set_time_limit(120);
                $r = sync_run_import($pdo, 'test');
                if (!empty($r['ok'])) addToast('success', 'Test réussi : connexion + export AssoConnect OK.');
                else                  addToast('danger', 'Test échoué : ' . ($r['message'] ?? 'erreur inconnue'));
            } elseif ($action === 'run') {
                addToast('info', 'Import lancé — il sera traité au prochain passage du cron.');
            } else {
                addToast('success', "Configuration de l'import automatique enregistrée.");
            }
        } catch (\Throwable $e) {
            addToast('danger', "Erreur lors de l'enregistrement : " . $e->getMessage());
        }
    }
}

// Régénération du token (sécurise l'URL du cron : commande à mettre à jour dans le panel).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate_worker_token'])) {
    require_once __DIR__ . '/../config/sync_assoconnect.php';
    try {
        sync_regenerate_token($pdo);
        addToast('success', "Nouveau token généré. Mettez à jour la commande du cron (URL) dans votre panel d'hébergement.");
    } catch (\Throwable $e) {
        addToast('danger', 'Impossible de régénérer le token : ' . $e->getMessage());
    }
}

// Determine active tab based on which form was submitted
$activeTab = 'personnalisation';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_maintenance'])) $activeTab = 'maintenance';
    elseif (isset($_POST['save_navbar_logo']) || isset($_POST['save_footer_logo']) || isset($_POST['save_theme']) || isset($_POST['reset_theme']) || isset($_POST['save_flash_colors']) || isset($_POST['reset_flash_colors'])) $activeTab = 'personnalisation';
    elseif (isset($_POST['save_hero']) || isset($_POST['save_accueil_params']) || isset($_POST['delete_picture_partner']) || isset($_POST['save_video_accueil']) || isset($_POST['save_custom_content'])) $activeTab = 'accueil';
    elseif (isset($_POST['save_header']) || isset($_POST['LinkAssoConnect']) || isset($_POST['save_inscription_params']) || isset($_POST['save_csp_domains']) || isset($_POST['save_closed_message'])) $activeTab = 'inscription';
    elseif (isset($_POST['parcours']) || isset($_POST['uploadGalerie']) || isset($_POST['delete_picture_parcours']) || isset($_POST['delete_picture_gradient'])) $activeTab = 'parcours';
    elseif (isset($_POST['reglementation'])) $activeTab = 'reglementation';
    elseif (isset($_POST['save_fields']) || isset($_POST['add_custom_field']) || isset($_POST['delete_field_id'])) $activeTab = 'formulaire';
    elseif (isset($_POST['importExcel'])) $activeTab = 'import';
    elseif (isset($_POST['save_api']) || isset($_POST['regenerate_api'])) $activeTab = 'api';
    elseif (isset($_POST['save_import_auto']) || isset($_POST['regenerate_worker_token'])) $activeTab = 'import_auto';
}
// Also check URL hash
if (isset($_GET['tab']) && in_array($_GET['tab'], ['personnalisation','accueil','inscription','parcours','reglementation','formulaire','import','import_auto','maintenance','api'])) {
    $activeTab = $_GET['tab'];
}

// Si l'onglet actif n'est pas accessible, basculer sur le premier accessible
$allTabs = ['personnalisation','accueil','inscription','parcours','reglementation','formulaire','import','import_auto','maintenance','api'];
if (!$canTab($activeTab)) {
    $activeTab = '';
    foreach ($allTabs as $t) { if ($canTab($t)) { $activeTab = $t; break; } }
}
?>

<h1 class="mb-3 fw-bold"><i class="bi bi-gear me-2"></i>Réglages</h1>

<!-- Settings Navigation Tabs -->
<ul class="nav settings-tabs" id="settingsTabs">
  <?php if ($canTab('personnalisation')): ?><li class="nav-item"><a class="nav-link <?= $activeTab === 'personnalisation' ? 'active' : '' ?>" href="#" data-tab="personnalisation">Personnalisation</a></li><?php endif; ?>
  <?php if ($canTab('accueil')): ?><li class="nav-item"><a class="nav-link <?= $activeTab === 'accueil' ? 'active' : '' ?>" href="#" data-tab="accueil">Accueil</a></li><?php endif; ?>
  <?php if ($canTab('inscription')): ?><li class="nav-item"><a class="nav-link <?= $activeTab === 'inscription' ? 'active' : '' ?>" href="#" data-tab="inscription">Inscription</a></li><?php endif; ?>
  <?php if ($canTab('parcours')): ?><li class="nav-item"><a class="nav-link <?= $activeTab === 'parcours' ? 'active' : '' ?>" href="#" data-tab="parcours">Parcours</a></li><?php endif; ?>
  <?php if ($canTab('reglementation')): ?><li class="nav-item"><a class="nav-link <?= $activeTab === 'reglementation' ? 'active' : '' ?>" href="#" data-tab="reglementation">Reglementation</a></li><?php endif; ?>
  <?php if ($canTab('formulaire')): ?><li class="nav-item"><a class="nav-link <?= $activeTab === 'formulaire' ? 'active' : '' ?>" href="#" data-tab="formulaire">Formulaire</a></li><?php endif; ?>
  <?php if ($canTab('import')): ?><li class="nav-item"><a class="nav-link <?= $activeTab === 'import' ? 'active' : '' ?>" href="#" data-tab="import">Import Excel</a></li><?php endif; ?>
  <?php if ($canTab('import_auto') || canDoAction('dashboard.import_excel')): ?><li class="nav-item"><a class="nav-link <?= $activeTab === 'import_auto' ? 'active' : '' ?>" href="#" data-tab="import_auto">Import AssoConnect</a></li><?php endif; ?>
  <?php if ($canTab('maintenance')): ?><li class="nav-item"><a class="nav-link <?= $activeTab === 'maintenance' ? 'active' : '' ?>" href="#" data-tab="maintenance">Maintenance</a></li><?php endif; ?>
  <?php if ($canTab('api')): ?><li class="nav-item"><a class="nav-link <?= $activeTab === 'api' ? 'active' : '' ?>" href="#" data-tab="api">API</a></li><?php endif; ?>
</ul>
<?php if ($activeTab === ''): ?>
<div class="alert alert-warning mt-3"><i class="bi bi-exclamation-triangle me-2"></i>Vous n'avez accès à aucun onglet des Réglages.</div>
<?php endif; ?>

<!-- ═══ TAB: Personnalisation ═══ -->
<?php if ($canTab('personnalisation')): ?>
<div class="settings-section <?= $activeTab === 'personnalisation' ? 'active' : '' ?>" id="tab-personnalisation">
  <div class="row g-4">

    <!-- Carte : Logo -->
    <div class="col-12 col-lg-6">
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
    </div><!-- /col-lg-6 -->

    <!-- Carte : Logo du footer -->
    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2>Logo du footer</h2>
        <form action="" method="post" enctype="multipart/form-data" class="row g-3 needs-validation">
          <?= csrf_field() ?>
          <div class="col-12">
            <label class="form-label">Changer le logo</label>
            <input type="file" class="form-control" name="footer_logo" accept="image/*">
            <small class="text-muted">Formats : JPG, PNG, GIF, WebP, SVG — Max 5 Mo</small>
          </div>
          <div class="col-12">
            <label class="form-label">Logo actuel</label>
            <div>
              <?php if ($footer_logo && file_exists('../files/_logos/' . $footer_logo)): ?>
                <div class="mb-2"><img src="../files/_logos/<?= rawurlencode($footer_logo) ?>" alt="Logo footer actuel" class="img-thumbnail" style="max-height:60px;background:#222;"></div>
                <small class="text-muted"><?= htmlspecialchars($footer_logo) ?></small>
              <?php else: ?>
                <span class="text-muted">Aucun logo</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-12 text-end">
            <button type="submit" name="save_footer_logo" class="btn btn-primary w-auto">Sauvegarder</button>
          </div>
        </form>
      </div>
    </div><!-- /col-lg-6 -->

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
              <input type="hidden" id="themeFont" name="theme_font_family" value="<?= htmlspecialchars($theme_font) ?>">
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
                $allFontsForPicker = $fontsUI;
                foreach ($customFonts as $name => $path) {
                    $allFontsForPicker[$name] = $name;
                }
              ?>
              <div class="font-picker-wrapper" style="position:relative;">
                <div class="font-picker-selected form-select" id="fontPickerToggle" style="cursor:pointer;">
                  <span id="fontPickerLabel" style="font-family:<?= $theme_font === 'system-ui' ? 'system-ui' : "'" . htmlspecialchars($theme_font) . "'" ?>;"><?= htmlspecialchars($allFontsForPicker[$theme_font] ?? $theme_font) ?></span>
                </div>
                <div class="font-picker-dropdown" id="fontPickerDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:100;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:320px;overflow-y:auto;margin-top:4px;">
                  <?php foreach ($allFontsForPicker as $val => $label):
                    $isCustom = isset($customFonts[$val]);
                    $ff = $val === 'system-ui' ? 'system-ui, sans-serif' : "'" . htmlspecialchars($val) . "', sans-serif";
                  ?>
                  <div class="font-picker-item<?= $val === $theme_font ? ' active' : '' ?>" data-value="<?= htmlspecialchars($val) ?>" style="padding:10px 16px;cursor:pointer;font-family:<?= $ff ?>;font-size:15px;transition:background .15s;<?= $isCustom ? 'border-left:3px solid var(--primary, #f42182);' : '' ?>" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background=this.classList.contains('active')?'#fdf2f8':''">
                    <?= htmlspecialchars($label) ?>
                    <?php if ($isCustom): ?><span style="font-size:10px;color:var(--primary, #f42182);font-family:system-ui;margin-left:6px;">custom</span><?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <small class="text-muted">Appliqué sur tout le site</small>
            </div>

            <!-- Aperçu en direct -->
            <div class="col-12 mt-3">
              <label class="form-label fw-bold">Aperçu en direct</label>
              <div id="themePreview" style="border:1px solid #e2e8f0;border-radius:12px;padding:24px;transition:background .3s,color .3s;">
                <div id="prevFontSample" style="margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid #e2e8f0;">
                  <div style="font-size:28px;font-weight:700;margin-bottom:4px;">Forbach en Rose</div>
                  <div style="font-size:16px;opacity:.7;">Course caritative contre le cancer du sein — abcdefghijklmnopqrstuvwxyz 0123456789</div>
                </div>
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
<?php endif; // canTab('personnalisation') ?>

<!-- ═══ TAB: Accueil ═══ -->
<?php if ($canTab('accueil')): ?>
<div class="settings-section <?= $activeTab === 'accueil' ? 'active' : '' ?>" id="tab-accueil">
  <div class="row g-4">

    <!-- Carte 1 : Titre / Image sur la vidéo (SUPPRIMÉE — édition désormais via l'éditeur visuel "Mise en page de l'accueil" plus bas) -->

    <!-- Carte 2 : Paramètres page accueil -->
    <?php if ($canCard('accueil', 'params')): ?>
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
          <!-- Image des partenaires : SUPPRIMÉ — édition désormais via clic sur l'image dans l'éditeur "Mise en page de l'accueil" -->
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
    <?php endif; // canCard('accueil','params') ?>

    <!-- Carte 3 : Vidéo d'accueil (SUPPRIMÉE — édition désormais via clic sur la vidéo dans l'éditeur visuel "Mise en page de l'accueil") -->

    <!-- Carte 4 : Mise en page de l'accueil (éditeur visuel WYSIWYG) -->
    <?php if ($canCard('accueil', 'custom')):
      require_once __DIR__ . '/../config/accueil_layout.php';
      require_once __DIR__ . '/../config/accueil_sections.php';
      // useDraft=true : #ifeLayoutData (modèle interne de l'éditeur) DOIT refléter la
      // même version que l'iframe accueil.php?editor=1 (qui charge le brouillon avec
      // fallback publié). Sinon les IDs de lignes divergent entre l'éditeur et l'iframe
      // → selectRow() ne retrouve pas la ligne cliquée et n'affiche aucune propriété.
      $accueilLayout = loadAccueilLayout($data, true);
      $predefinedSections = accueilPredefinedSections();
      $allowedWidths = accueilAllowedWidths();
      // Contexte pour le rendu réel des sections (mêmes données que la home)
      // useDraft=true → l'aperçu admin lit la version brouillon (avec fallback publié)
      $sectionCtx = buildAccueilSectionContext($pdo, $data, $actualites ?? [], true);
    ?>
    <div class="col-12">
      <div class="setting-card">
        <!-- Barre d'outils : flottée À DROITE (en face du titre) + position:sticky.
             Son conteneur est la carte (.setting-card, haute) → elle reste collée
             en haut PENDANT TOUT le scroll de l'éditeur. À droite ⇒ aucun
             chevauchement avec la sidebar (qui est sticky à gauche).
             La délégation de clic est rebranchée sur la carte (voir JS). -->
        <div class="ife-preview-toolbar">
          <div class="ife-device-group" role="group" aria-label="Aperçu device">
            <button type="button" class="ife-device-btn is-active" data-device="desktop" title="Aperçu desktop">
              <i class="bi bi-laptop"></i><span>Desktop</span>
            </button>
            <button type="button" class="ife-device-btn" data-device="mobile" title="Aperçu mobile">
              <i class="bi bi-phone"></i><span>Mobile</span>
            </button>
          </div>
          <button type="button" class="ife-migrate-btn" id="ifeMigrateBtn" title="Convertir les anciennes positions en pourcentage vers le nouveau format ancré">
            <i class="bi bi-arrow-repeat"></i><span>Migrer positions</span>
          </button>
          <button type="button" class="ife-restore-btn" id="ifeRestoreBtn" title="Restaurer toutes les positions et tailles du hero aux valeurs par défaut pour le device courant (CSS d'origine)">
            <i class="bi bi-arrow-counterclockwise"></i><span>Restaurer hero</span>
          </button>
        </div>
        <h2><i class="bi bi-grid-3x3-gap-fill me-2"></i>Mise en page de l'accueil</h2>
        <p class="text-muted mb-3">
          L'aperçu ci-dessous est le <strong>vrai rendu de la page d'accueil</strong>. Survolez une section pour voir les contrôles, cliquez pour la sélectionner. Cliquez sur un texte/image éditable pour l'ouvrir dans le menu de gauche.
        </p>

        <div class="ife-layout">
          <!-- ── Sidebar GAUCHE : propriétés + ajouter ── -->
          <aside class="ife-sidebar">
            <div class="ife-sb-tabs">
              <button type="button" class="ife-sb-tab active" data-sb-tab="props"><i class="bi bi-sliders me-1"></i>Propriétés</button>
              <button type="button" class="ife-sb-tab" data-sb-tab="add"><i class="bi bi-plus-circle me-1"></i>Ajouter</button>
            </div>
            <div class="ife-sb-content">
              <!-- Pane PROPRIÉTÉS -->
              <div class="ife-sb-pane active" data-sb-pane="props">
                <div id="ifeSbEmpty" class="ife-sb-empty">
                  <i class="bi bi-cursor" style="font-size:2rem;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
                  Cliquez sur une section dans l'aperçu pour voir ses propriétés ici.
                </div>
                <div id="ifeSbProps" style="display:none;">
                  <h4 class="ife-sb-title" id="ifeSbTitle">Section</h4>
                  <div class="ife-sb-row" id="ifeSbWidthRow">
                    <label>Largeur (sur 12)</label>
                    <select class="form-select form-select-sm" id="ifeSbWidth" style="width:auto;">
                      <?php foreach ($allowedWidths as $aw): ?>
                        <option value="<?= $aw ?>"><?= $aw ?>/12</option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <!-- Tableau de la ligne : 1 case par colonne, largeur éditable. Visible uniquement si row multi-col. -->
                  <div id="ifeSbGridRow" style="display:none;">
                    <label class="ife-sb-grid-label">Grille de la ligne (total = 12)</label>
                    <div id="ifeSbGrid" class="ife-sb-grid"></div>
                    <div class="ife-sb-grid-presets">
                      <!-- 2 colonnes : équilibrées, puis croissantes G→D, puis miroir D→G -->
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="6,6"  title="2 colonnes égales">6/6</button>
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="8,4"  title="Gauche large, droite étroite">8/4</button>
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="9,3"  title="Gauche très large, droite mince">9/3</button>
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="10,2" title="Gauche dominante, droite minuscule">10/2</button>
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="4,8"  title="Gauche étroite, droite large">4/8</button>
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="3,9"  title="Gauche mince, droite très large">3/9</button>
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="2,10" title="Gauche minuscule, droite dominante">2/10</button>
                      <!-- 3 colonnes : égales, puis grosse à gauche, puis grosse au centre, puis grosse à droite -->
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="4,4,4" title="3 colonnes égales">4/4/4</button>
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="6,3,3" title="Grande à gauche">6/3/3</button>
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="3,6,3" title="Grande au centre">3/6/3</button>
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="3,3,6" title="Grande à droite">3/3/6</button>
                    </div>
                    <div class="ife-sb-section-label">Position des colonnes dans la ligne</div>
                    <div class="ife-sb-align-row">
                      <label>Horizontal</label>
                      <div class="btn-group btn-group-sm" role="group" id="ifeSbAlignGroup">
                        <button type="button" class="btn btn-outline-secondary" data-align="left"   title="Aligner à gauche"><i class="bi bi-align-start"></i></button>
                        <button type="button" class="btn btn-outline-secondary" data-align="center" title="Centrer horizontalement"><i class="bi bi-align-center"></i></button>
                        <button type="button" class="btn btn-outline-secondary" data-align="right"  title="Aligner à droite"><i class="bi bi-align-end"></i></button>
                      </div>
                    </div>
                    <div class="ife-sb-align-row">
                      <label>Vertical</label>
                      <div class="btn-group btn-group-sm" role="group" id="ifeSbValignGroup">
                        <button type="button" class="btn btn-outline-secondary" data-valign="top"    title="Aligner en haut"><i class="bi bi-align-top"></i></button>
                        <button type="button" class="btn btn-outline-secondary" data-valign="center" title="Centrer verticalement"><i class="bi bi-align-middle"></i></button>
                        <button type="button" class="btn btn-outline-secondary" data-valign="bottom" title="Aligner en bas"><i class="bi bi-align-bottom"></i></button>
                      </div>
                    </div>
                  </div>
                  <!-- Espacement vertical de la ligne (margin haut/bas indépendants) -->
                  <div class="ife-sb-spacing-row" id="ifeSbSpacingRow">
                    <div class="ife-sb-section-label">
                      Espacement de la ligne (en rem)
                      <i class="bi bi-info-circle ife-sb-info-icon"
                         tabindex="0"
                         data-bs-toggle="tooltip"
                         data-bs-html="true"
                         data-bs-placement="right"
                         data-bs-custom-class="ife-sb-tooltip"
                         title="<strong>Au-dessus</strong> = espace avant cette ligne. <strong>En-dessous</strong> = espace après.<br><br>L'écart visible entre 2 lignes = <em>'en-dessous' de la 1<sup>re</sup></em> + <em>'au-dessus' de la 2<sup>e</sup></em>.<br><br>Pour coller deux lignes : mettre <strong>0</strong> en bas de la 1<sup>re</sup> ET 0 en haut de la 2<sup>e</sup>."></i>
                    </div>
                    <div class="ife-sb-spacing-grid">
                      <div>
                        <label class="small text-muted">Au-dessus</label>
                        <div class="input-group input-group-sm">
                          <input type="number" class="form-control" id="ifeSbSpaceTop" min="0" max="20" step="0.5">
                          <span class="input-group-text">rem</span>
                        </div>
                      </div>
                      <div>
                        <label class="small text-muted">En-dessous</label>
                        <div class="input-group input-group-sm">
                          <input type="number" class="form-control" id="ifeSbSpaceBottom" min="0" max="20" step="0.5">
                          <span class="input-group-text">rem</span>
                        </div>
                      </div>
                    </div>
                    <button type="button" class="btn btn-link btn-sm p-0 mt-1" id="ifeSbSpaceReset" title="Remettre aux défauts"><i class="bi bi-arrow-counterclockwise me-1"></i>Réinitialiser (5 / 0 rem)</button>
                  </div>
                  <div class="ife-sb-row" id="ifeSbVisRow">
                    <label for="ifeSbVis">Visible</label>
                    <div class="form-check form-switch m-0">
                      <input class="form-check-input" type="checkbox" id="ifeSbVis" checked>
                    </div>
                  </div>
                  <div class="ife-sb-row" id="ifeSbSizeRow" style="display:none;">
                    <label for="ifeSbSize">Taille</label>
                    <input type="range" class="form-range" id="ifeSbSize" min="50" max="300" step="5" value="100" style="flex:1;">
                    <span class="small text-muted" id="ifeSbSizeVal" style="min-width:40px;">100%</span>
                  </div>
                  <hr>
                  <button type="button" class="btn btn-sm btn-primary w-100 mb-2" id="ifeSbBtnEdit" style="display:none;"><i class="bi bi-pencil me-1"></i>Modifier le contenu</button>
                  <button type="button" class="btn btn-sm btn-outline-danger w-100" id="ifeSbBtnDelete"><i class="bi bi-trash3 me-1"></i>Supprimer</button>
                </div>
              </div>
              <!-- Pane AJOUTER -->
              <div class="ife-sb-pane" data-sb-pane="add">
                <h4 class="ife-sb-title">Nouveau bloc</h4>
                <button type="button" class="ife-sb-add-btn" id="btnAddCustomBlock">
                  <i class="bi bi-text-paragraph"></i>
                  <div><strong>Bloc texte</strong><small>Mise en page WYSIWYG : texte, images, couleurs, listes…</small></div>
                </button>
                <button type="button" class="ife-sb-add-btn" id="btnAddHtmlBlock">
                  <i class="bi bi-code-slash"></i>
                  <div><strong>Bloc HTML / CSS / JS</strong><small>Code brut avec preview live (style, button, script…)</small></div>
                </button>
                <h4 class="ife-sb-title mt-3">Sections pré-définies</h4>
                <div id="restoreMenu">
                  <?php foreach ($predefinedSections as $type => $meta): ?>
                    <button type="button" class="ife-sb-add-btn" data-restore-type="<?= htmlspecialchars($type) ?>">
                      <i class="bi <?= $meta['icon'] ?>"></i><div><strong><?= htmlspecialchars($meta['label']) ?></strong></div>
                    </button>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <div class="ife-sb-footer">
              <!-- Badge "Modifications non publiées" : visible quand un brouillon existe -->
              <div id="ifeDraftBadge" class="ife-draft-badge" style="display:none;">
                <i class="bi bi-circle-fill"></i>
                <span>Modifications non publiées</span>
              </div>
              <!-- Bouton principal : pousse le brouillon en production -->
              <button type="button" class="btn btn-success w-100" id="btnPublishLayout">
                <i class="bi bi-send me-1"></i>Publier sur le site
              </button>
              <!-- Bouton secondaire : annule le brouillon (revient à la version publiée) -->
              <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-2" id="btnDiscardDraft" style="display:none;">
                <i class="bi bi-x-circle me-1"></i>Annuler les modifications
              </button>
              <div id="layoutSaveStatus" class="mt-2 small text-muted text-center" style="display:none"></div>
            </div>
          </aside>

          <!-- ── Aperçu : iframe avec la home + overlay layer ── -->
          <div class="ife-preview-wrap" id="ifePreviewWrap">
            <!-- Spinner de chargement : couvre l'aperçu tant que l'iframe + les
                 overlays (traits/marqueurs « Glisser pour réorganiser… ») ne sont
                 pas prêts. Masqué dès le 1er message editor-layout (voir JS). -->
            <div id="ifeLoader" class="ife-loader">
              <div class="ife-loader-spin" aria-hidden="true"></div>
              <p>Chargement de l'aperçu…</p>
            </div>
            <!-- Conteneur interne porteur du scroll horizontal : il isole le
                 overflow de l'iframe pour que .ife-preview-wrap ne soit PAS un
                 conteneur de défilement → la toolbar peut devenir position:sticky
                 (collée en haut de l'éditeur) relative à la page. L'overlay reste
                 dans ce conteneur avec l'iframe → alignement des marqueurs conservé. -->
            <div class="ife-preview-scroll">
              <iframe id="ifePreview" src="../public/accueil.php?editor=1" frameborder="0"></iframe>
              <div id="ifeOverlay" class="ife-overlay"></div>
            </div>
          </div>
          <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
          (function(){
            // ── Bascule Desktop / Mobile dans l'éditeur ─────────────────────
            // L'iframe charge accueil.php?editor=1 qui héberge le drag/save.
            // Quand l'admin clique "Mobile", on rétrécit l'iframe (CSS media
            // queries de l'accueil basculent en layout mobile ≤1040px) et on
            // signale à l'iframe que les saves doivent cibler les variantes
            // _mobile du JSON de géométrie.
            function init(){
              var wrap   = document.getElementById('ifePreviewWrap');
              var iframe = document.getElementById('ifePreview');
              if (!wrap || !iframe) return;
              // La barre d'outils a été déplacée HORS de #ifePreviewWrap (en face du
              // titre). On délègue donc les clics sur la carte entière, qui contient
              // à la fois la barre ET l'aperçu.
              var clickRoot = wrap.closest('.setting-card') || wrap;

              // ── Spinner de chargement ──────────────────────────────────────
              // Couvre l'aperçu tant que tout n'est pas prêt. On le masque dès que
              // les overlays sont dessinés = 1er message 'editor-layout' (+ petit
              // délai pour laisser les traits apparaître). Replis : au load de
              // l'iframe (+1,5 s) et un filet de sécurité à 20 s.
              (function(){
                var loader = document.getElementById('ifeLoader');
                if (!loader) return;
                var done = false;
                function hideLoader(){
                  if (done) return; done = true;
                  loader.classList.add('is-hidden');
                  setTimeout(function(){ loader.style.display = 'none'; }, 400);
                }
                window.addEventListener('message', function(e){
                  if (e && e.data && e.data.type === 'editor-layout') setTimeout(hideLoader, 300);
                });
                iframe.addEventListener('load', function(){ setTimeout(hideLoader, 1500); });
                setTimeout(hideLoader, 20000);
              })();

              var STORE_KEY = 'ife_editor_device';
              var device = 'desktop';
              try { device = localStorage.getItem(STORE_KEY) || 'desktop'; } catch(e) {}

              function notifyIframe(){
                try {
                  if (iframe.contentWindow) {
                    iframe.contentWindow.postMessage(
                      { type: 'editor-set-device', device: device },
                      '*'
                    );
                  }
                } catch(e) {}
              }

              function getMigrateBtn(){ return document.getElementById('ifeMigrateBtn'); }
              function migrateLabel(txt){
                var b = getMigrateBtn(); if (!b) return;
                var sp = b.querySelector('span'); if (sp) sp.textContent = txt;
              }

              function applyDevice(next){
                device = (next === 'mobile') ? 'mobile' : 'desktop';
                try { localStorage.setItem(STORE_KEY, device); } catch(e) {}
                wrap.classList.toggle('is-mobile', device === 'mobile');
                // Les boutons device sont désormais hors de #ifePreviewWrap (barre en
                // face du titre) → on les cherche dans la carte entière.
                clickRoot.querySelectorAll('.ife-device-btn').forEach(function(b){
                  b.classList.toggle('is-active', b.getAttribute('data-device') === device);
                });
                notifyIframe();
                // Rebase les widgets device-aware de la sidebar (dimensions carte, etc.)
                try {
                  if (window.AccueilEditor && typeof window.AccueilEditor.refreshSelection === 'function') {
                    window.AccueilEditor.refreshSelection();
                  }
                } catch(e) {}
              }

              // Délégation de clic : robuste même si les boutons sont recréés.
              clickRoot.addEventListener('click', function(e){
                var devBtn = e.target.closest && e.target.closest('.ife-device-btn');
                if (devBtn && clickRoot.contains(devBtn)) {
                  e.preventDefault();
                  applyDevice(devBtn.getAttribute('data-device'));
                  return;
                }
                var mig = e.target.closest && e.target.closest('#ifeMigrateBtn');
                if (mig && !mig.disabled) {
                  e.preventDefault();
                  mig.disabled = true;
                  migrateLabel('Migration…');
                  try {
                    if (iframe.contentWindow) {
                      iframe.contentWindow.postMessage({ type: 'editor-migrate-legacy' }, '*');
                    }
                  } catch(err) {}
                  // Sécurité : on relâche le bouton si l'iframe ne répond pas.
                  setTimeout(function(){
                    var b = getMigrateBtn();
                    if (b && b.disabled) {
                      b.disabled = false;
                      migrateLabel('Migrer positions');
                    }
                  }, 5000);
                }

                // Bouton "Restaurer hero" : purge geometry + tailles du hero pour le
                // device courant (mobile OU desktop), puis recharge l'iframe pour que
                // les valeurs CSS d'origine reprennent la main visuellement.
                var restoreBtn = e.target.closest && e.target.closest('#ifeRestoreBtn');
                if (restoreBtn && !restoreBtn.disabled) {
                  e.preventDefault();
                  var deviceLabel = device === 'mobile' ? 'MOBILE' : 'DESKTOP';
                  if (!confirm('Restaurer toutes les positions et tailles du hero (badges, timer, bouton play, social, titre, sous-titre) aux valeurs par défaut pour la version ' + deviceLabel + ' ?\n\nCette action affecte uniquement le brouillon, vous devrez Publier pour appliquer sur le site.')) {
                    return;
                  }
                  restoreBtn.disabled = true;
                  var restoreSpan = restoreBtn.querySelector('span');
                  var restoreOrig = restoreSpan ? restoreSpan.textContent : '';
                  if (restoreSpan) restoreSpan.textContent = 'Restauration…';
                  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
                  var csrfTok  = csrfMeta ? csrfMeta.getAttribute('content') : '';
                  var fd = new FormData();
                  fd.append('restore_accueil_hero_defaults', '1');
                  fd.append('scope', device); // 'mobile' ou 'desktop'
                  fd.append('csrf_token', csrfTok);
                  fetch('', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfTok, 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(j){
                      restoreBtn.disabled = false;
                      if (restoreSpan) restoreSpan.textContent = restoreOrig || 'Restaurer hero';
                      if (j && j.ok) {
                        // Marque comme draft en cours (badge "Modifications non publiées")
                        if (window.AccueilEditor) window.AccueilEditor.hasDraft = true;
                        var badge = document.getElementById('ifeDraftBadge');
                        var btnDiscard = document.getElementById('btnDiscardDraft');
                        if (badge) badge.style.display = '';
                        if (btnDiscard) btnDiscard.style.display = '';
                        // Recharge l'iframe : le PHP émettra les attrs sans les clés supprimées,
                        // le CSS d'origine prend la main visuellement.
                        try { iframe.contentWindow.location.reload(); } catch(err) { iframe.src = iframe.src; }
                      } else {
                        alert('Erreur restauration : ' + ((j && j.err) || 'inconnue'));
                      }
                    })
                    .catch(function(){
                      restoreBtn.disabled = false;
                      if (restoreSpan) restoreSpan.textContent = restoreOrig || 'Restaurer hero';
                      alert('Erreur réseau lors de la restauration.');
                    });
                }
              });

              // Sync device dès que l'iframe est prête. Si elle est déjà complète,
              // on notifie tout de suite (sinon on rate le 'load' event).
              function tryNotifyWhenReady(){
                try {
                  var d = iframe.contentDocument;
                  if (d && d.readyState === 'complete') { notifyIframe(); return; }
                } catch(e) {}
                setTimeout(notifyIframe, 100);
              }
              iframe.addEventListener('load', function(){ setTimeout(notifyIframe, 50); });
              tryNotifyWhenReady();

              applyDevice(device);

              // L'iframe signale le résultat de la migration.
              window.addEventListener('message', function(ev){
                var data = ev && ev.data;
                if (!data || typeof data !== 'object') return;
                if (data.type === 'editor-migrate-done') {
                  var b = getMigrateBtn(); if (!b) return;
                  b.disabled = false;
                  migrateLabel(data.count ? (data.count + ' positions migrées') : 'Aucune à migrer');
                  setTimeout(function(){ migrateLabel('Migrer positions'); }, 2500);
                }
              });
            }
            if (document.readyState === 'loading') {
              document.addEventListener('DOMContentLoaded', init);
            } else {
              init();
            }
          })();
          </script>
        </div>

        <!-- État interne du layout (utilisé par JS) -->
        <script type="application/json" id="ifeLayoutData"><?= json_encode($accueilLayout, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      </div>
    </div><!-- /col-12 -->

    <!-- Modal universel : édition d'un champ d'une section pré-définie (titre, sous-titre, image, vidéo) -->
    <div class="modal fade" id="fieldEditModal" tabindex="-1">
      <div class="modal-dialog modal-xl">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="fieldEditTitle">Modifier</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="fieldEditFieldName">
            <input type="hidden" id="fieldEditKind">
            <!-- Mode TinyMCE (pour titleAccueil + titleAccueil_mobile) -->
            <div id="fieldEditTinymceWrap" style="display:none;">
              <p class="text-muted small mb-2">Utilisez la barre d'outils pour ajouter du texte, des images, les aligner, etc. — ou clic sur <code>&lt;/&gt;</code> pour HTML brut.</p>
              <textarea id="fieldEditTinymce"></textarea>
            </div>
            <!-- Mode texte simple (pour subtitle_accueil) -->
            <div id="fieldEditTextWrap" style="display:none;">
              <input type="text" class="form-control" id="fieldEditText" maxlength="500">
              <div class="form-text">Maximum 500 caractères.</div>
            </div>
            <!-- Mode upload fichier (image / vidéo) -->
            <div id="fieldEditFileWrap" style="display:none;">
              <p id="fieldEditFileCurrent" class="text-muted small mb-2"></p>
              <input type="file" class="form-control" id="fieldEditFile">
              <div class="form-text" id="fieldEditFileHint"></div>
            </div>
            <div id="fieldEditStatus" class="mt-3 small" style="display:none;"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
            <button type="button" class="btn btn-primary" id="btnSaveField">Enregistrer</button>
          </div>
        </div>
      </div>
    </div>
    <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
    // ── Attachement DIRECT et autonome pour #btnSaveField ─────────────────
    // Ce script est inline juste après le bouton et NE dépend d'aucune autre
    // IIFE de la page. Si la grande IIFE plus bas plante avant son propre
    // attachement (erreur silencieuse type getElementById null), le bouton
    // restait inerte. On attache donc le handler ici, directement au nœud,
    // avec déduplication via `data-save-attached` posé par le handler principal.
    (function(){
      var btnEl = document.getElementById('btnSaveField');
      if (!btnEl) return;
      // Capture phase + délégation document : robuste face à un focus-trap de
      // TinyMCE ou un overlay Bootstrap qui pourrait avaler le clic en bubble.
      function handler(e){
        var t = e.target && e.target.closest ? e.target.closest('#btnSaveField') : null;
        if (!t) return;
        var btn = t;
        if (btn.dataset.saveAttached === '1') return; // handler principal opère
        if (btn.disabled) return;
        e.preventDefault();
        e.stopPropagation();
        var status = document.getElementById('fieldEditStatus');
        var field  = (document.getElementById('fieldEditFieldName') || {}).value || '';
        var kind   = (document.getElementById('fieldEditKind') || {}).value || '';
        function showErr(msg){
          btn.disabled = false;
          if (status) {
            status.style.display = 'block';
            status.style.color = '#dc2626';
            status.textContent = '✗ ' + msg;
          } else { alert(msg); }
        }
        try {
          btn.disabled = true;
          if (status) {
            status.style.display = 'block';
            status.style.color = '#64748b';
            status.textContent = 'Enregistrement…';
          }
          var csrfMeta = document.querySelector('meta[name="csrf-token"]');
          var csrfTok  = csrfMeta ? csrfMeta.getAttribute('content') : '';
          var fd = new FormData();
          fd.append('save_accueil_field', '1');
          fd.append('field', field);
          fd.append('csrf_token', csrfTok);
          if (kind === 'tinymce') {
            var ed = (typeof tinymce !== 'undefined') ? tinymce.get('fieldEditTinymce') : null;
            var content = (ed && typeof ed.getContent === 'function')
              ? ed.getContent()
              : ((document.getElementById('fieldEditTinymce') || {}).value || '');
            fd.append('value', content);
          } else if (kind === 'text') {
            fd.append('value', (document.getElementById('fieldEditText') || {}).value || '');
          } else if (kind === 'image' || kind === 'video') {
            var file = (document.getElementById('fieldEditFile') || {}).files && document.getElementById('fieldEditFile').files[0];
            if (!file) { showErr('Sélectionnez un fichier.'); return; }
            fd.append('file', file);
          } else {
            showErr('Type de champ inconnu : ' + kind);
            return;
          }
          fetch('', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfTok, 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
          })
          .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
          .then(function(j){
            btn.disabled = false;
            if (j && j.ok) {
              if (status) { status.style.color = '#16a34a'; status.textContent = '✓ Enregistré. Rechargement…'; }
              setTimeout(function(){ location.reload(); }, 800);
            } else {
              showErr((j && j.err) || 'Échec de l\'enregistrement.');
            }
          })
          .catch(function(err){
            showErr('Erreur réseau' + (err && err.message ? ' (' + err.message + ')' : '') + '.');
          });
        } catch (err) {
          console.error('[btnSaveField backup]', err);
          showErr('Erreur JS : ' + (err && err.message ? err.message : err));
        }
      }
      // Capture sur document : avant tout autre listener, et ne dépend pas du
      // bouton lui-même (si Bootstrap recréait la modal, la délégation reste OK).
      document.addEventListener('click', handler, true);
      // Attachement direct aussi : ceinture + bretelles si un script remplace le node.
      btnEl.addEventListener('click', handler);
    })();
    </script>

    <!-- Modal : édition d'un bloc personnalisé -->
    <!-- data-bs-focus="false" : Bootstrap n'impose pas son focus-trap → les dialogs
         enfants (TinyMCE windowManager, Source code, Insérer HTML) peuvent recevoir
         le clavier et accepter input/paste -->
    <div class="modal fade" id="customBlockModal" tabindex="-1" data-bs-focus="false">
      <div class="modal-dialog modal-xl">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="customBlockModalTitle">Ajouter un bloc personnalisé</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="customBlockEditId">
            <div class="mb-3">
              <label class="form-label fw-semibold">Nom interne (facultatif)</label>
              <input type="text" class="form-control" id="customBlockTitle" placeholder="Ex : Promo printemps, Annonce spéciale...">
              <div class="form-text">Sert uniquement à repérer le bloc dans l'éditeur. Non affiché publiquement.</div>
            </div>
            <div>
              <label class="form-label fw-semibold">Contenu</label>
              <p class="text-muted small mb-2">Utilisez la barre d'outils pour le contenu visuel, ou cliquez sur l'icône <code>&lt;/&gt;</code> pour saisir du HTML brut.</p>
              <textarea id="customBlockEditor"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
            <button type="button" class="btn btn-primary" id="btnSaveCustomBlock">Valider</button>
          </div>
        </div>
      </div>
    </div>
    <!-- Modal : éditeur HTML/CSS/JS avec split code + preview live -->
    <div class="modal fade" id="htmlBlockModal" tabindex="-1" data-bs-focus="false">
      <div class="modal-dialog modal-xl modal-fullscreen-lg-down">
        <div class="modal-content" style="height:90vh;">
          <div class="modal-header">
            <h5 class="modal-title" id="htmlBlockModalTitle">Bloc HTML / CSS / JS</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-0 d-flex flex-column" style="overflow:hidden;">
            <div class="px-3 pt-3 pb-2 border-bottom">
              <input type="hidden" id="htmlBlockEditId">
              <div class="d-flex gap-3 align-items-end flex-wrap">
                <div class="flex-grow-1" style="min-width:200px;">
                  <label class="form-label fw-semibold mb-1">Nom interne (facultatif)</label>
                  <input type="text" class="form-control form-control-sm" id="htmlBlockTitle" placeholder="Ex : Promo button, Compteur custom...">
                </div>
                <div>
                  <label class="form-label fw-semibold mb-1 small">Alignement horizontal</label>
                  <div class="btn-group btn-group-sm" role="group" id="htmlBlockAlignGroup">
                    <button type="button" class="btn btn-outline-secondary" data-align="left"   title="Gauche"><i class="bi bi-align-start"></i></button>
                    <button type="button" class="btn btn-outline-secondary" data-align="center" title="Centre"><i class="bi bi-align-center"></i></button>
                    <button type="button" class="btn btn-outline-secondary" data-align="right"  title="Droite"><i class="bi bi-align-end"></i></button>
                  </div>
                </div>
                <div>
                  <label class="form-label fw-semibold mb-1 small">Alignement vertical</label>
                  <div class="btn-group btn-group-sm" role="group" id="htmlBlockValignGroup">
                    <button type="button" class="btn btn-outline-secondary" data-valign="top"    title="Haut"><i class="bi bi-align-top"></i></button>
                    <button type="button" class="btn btn-outline-secondary" data-valign="center" title="Milieu"><i class="bi bi-align-middle"></i></button>
                    <button type="button" class="btn btn-outline-secondary" data-valign="bottom" title="Bas"><i class="bi bi-align-bottom"></i></button>
                  </div>
                </div>
              </div>
            </div>
            <div class="d-flex flex-grow-1" style="overflow:hidden;min-height:0;">
              <div class="d-flex flex-column" style="width:50%;border-right:1px solid #e2e8f0;">
                <div class="px-3 py-2 d-flex align-items-center justify-content-between" style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                  <span class="small fw-semibold text-secondary"><i class="bi bi-code-slash me-1"></i>CODE (HTML / CSS / JS)</span>
                  <span class="small text-muted">Modification appliquée à la preview en temps réel</span>
                </div>
                <div id="htmlBlockCode" style="flex:1;min-height:0;overflow:auto;"></div>
              </div>
              <div class="d-flex flex-column" style="width:50%;">
                <div class="px-3 py-2 d-flex align-items-center justify-content-between" style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                  <span class="small fw-semibold text-secondary"><i class="bi bi-eye me-1"></i>PREVIEW LIVE</span>
                  <button type="button" class="btn btn-sm btn-link p-0" id="htmlBlockReloadPreview" title="Rafraîchir manuellement"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                <iframe id="htmlBlockPreview" style="flex:1;min-height:0;border:0;background:#fff;width:100%;" sandbox="allow-scripts allow-same-origin"></iframe>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
            <button type="button" class="btn btn-primary" id="btnSaveHtmlBlock"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
          </div>
        </div>
      </div>
    </div>

    <?php endif; // canCard('accueil','custom') ?>

  </div><!-- /row -->
</div><!-- /tab-accueil -->
<?php endif; // canTab('accueil') ?>

<!-- ═══ TAB: Inscription ═══ -->
<?php if ($canTab('inscription')): ?>
<div class="settings-section <?= $activeTab === 'inscription' ? 'active' : '' ?>" id="tab-inscription">
  <div class="row g-4">
    <?php if ($canCard('inscription', 'header')): ?>
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
    <?php endif; // canCard('inscription','header') ?>

    <?php if ($canCard('inscription', 'params')): ?>
    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2>Paramètres d'inscription</h2>
        <form action="" method="post" class="row g-3 needs-validation">
          <?= csrf_field() ?>
          <div class="col-md-6"><label class="form-label">Montant de l'inscription</label>
            <select id="registration_fee" name="registration_fee" class="form-select">
              <?php for ($i = 0; $i <= 100; $i++): ?>
              <option value="<?= $i ?>" <?= ($i == (int)$registration_fee ? 'selected' : '') ?>><?= $i ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">Kilomètres de la course</label>
            <input type="number" id="course_km" class="form-control" name="course_km" min="1" max="100" value="<?= (int)$course_km ?>" placeholder="Ex : 7">
          </div>
          <div class="col-12"><label class="form-label">Nombre de premiers inscrits</label>
            <input type="number" class="form-control" name="qrcode_mail_limit" min="0" value="<?= $qrcode_mail_limit ?>" placeholder="Ex : 800">
            <small class="text-muted">Utilisé pour la coloration rose dans le dashboard et le QR Code (si mode = X premiers).</small>
          </div>
          <div class="col-12">
            <label class="form-label">Activer les inscriptions (manuel)</label>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="accueil_active" id="accueil_active_gen" <?= isset($accueil_active) && $accueil_active ? 'checked' : '' ?>>
              <label class="form-check-label" for="accueil_active_gen">Oui / Non</label>
            </div>
          </div>
          <div class="col-12"><hr class="my-2"><h6 class="text-muted mb-0">Tarif enfant selon l'âge</h6></div>
          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="child_pricing_enabled" id="child_pricing_enabled" <?= !empty($child_pricing_enabled) ? 'checked' : '' ?>>
              <label class="form-check-label" for="child_pricing_enabled">Appliquer automatiquement un tarif enfant selon l'âge</label>
            </div>
            <small class="text-muted">À l'import Excel / ajout multiple : si l'âge est renseigné et inférieur au seuil, le montant dû devient le « montant enfant ». Sinon, comportement habituel. L'âge seuil sert aussi aux libellés « -N ans ».</small>
          </div>
          <div class="col-md-6">
            <label class="form-label">Âge seuil enfant</label>
            <input type="number" class="form-control" name="child_age_threshold" min="1" max="120" value="<?= (int)$child_age_threshold ?>" placeholder="Ex : 12">
          </div>
          <div class="col-md-6">
            <label class="form-label">Montant enfant (€)</label>
            <select name="child_amount" class="form-select">
              <?php for ($i = 0; $i <= 100; $i++): ?>
              <option value="<?= $i ?>" <?= ($i == (int)$child_amount ? 'selected' : '') ?>><?= $i ?></option>
              <?php endfor; ?>
            </select>
            <small class="text-muted">0 = gratuit pour les enfants sous le seuil.</small>
          </div>
          <div class="col-12"><hr class="my-2"><h6 class="text-muted mb-0">Ouverture / Fermeture automatique</h6></div>
          <div class="col-md-6">
            <label class="form-label">Ouverture automatique</label>
            <input type="datetime-local" class="form-control" name="registration_auto_open" value="<?= $registration_auto_open ? date('Y-m-d\TH:i', strtotime($registration_auto_open)) : '' ?>">
            <small class="text-muted">Les inscriptions s'ouvriront automatiquement à cette date et heure.</small>
          </div>
          <div class="col-md-6">
            <label class="form-label">Fermeture automatique</label>
            <input type="datetime-local" class="form-control" name="registration_auto_close" value="<?= $registration_auto_close ? date('Y-m-d\TH:i', strtotime($registration_auto_close)) : '' ?>">
            <small class="text-muted">Les inscriptions se fermeront automatiquement à cette date et heure.</small>
          </div>
          <div class="col-12 text-end">
            <button type="submit" name="save_inscription_params" class="btn btn-primary w-auto">Sauvegarder</button>
          </div>
        </form>
      </div>
    </div><!-- /col-lg-6 -->

    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2>Message « inscriptions fermées »</h2>
        <form action="" method="post" class="row g-3 needs-validation">
          <?= csrf_field() ?>
          <div class="col-12">
            <label class="form-label" for="registrationClosedMessageEditor">Information complémentaire affichée quand les inscriptions sont fermées</label>
            <textarea class="form-control" id="registrationClosedMessageEditor" name="registration_closed_message" rows="6"><?= htmlspecialchars($registration_closed_message) ?></textarea>
            <small class="text-muted">S'affiche sous « 🚫 Les inscriptions sont actuellement fermées » sur la page publique d'inscription. Utilisez la barre d'outils pour la mise en forme (gras, couleurs, liens…). Laisser vide pour n'afficher que le message par défaut.</small>
          </div>
          <div class="col-12 text-end">
            <button type="submit" name="save_closed_message" class="btn btn-primary w-auto">Sauvegarder</button>
          </div>
        </form>
      </div>
    </div><!-- /col-lg-6 -->
    <?php endif; // canCard('inscription','params') ?>

    <?php if ($canCard('inscription', 'assoconnect')): ?>
    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2>Liaison AssoConnect</h2>
        <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
          .ac-form .ac-label{font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;display:flex;align-items:center;gap:6px}
          .ac-form .ac-label .ac-opt{font-weight:500;color:#94a3b8;font-size:11px}
          .ac-hint{font-size:12px;color:#94a3b8;margin-top:6px;line-height:1.45}
          .ac-field{margin-bottom:14px}.ac-field:last-child{margin-bottom:0}
          .ac-form input.ac-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px}
          .ac-form input::placeholder{color:#cbd5e1;opacity:1;font-style:italic}
          .ac-form input::-webkit-input-placeholder{color:#cbd5e1;font-style:italic}
          .ac-form input:-ms-input-placeholder{color:#cbd5e1;font-style:italic}
          .ac-divider{border:0;border-top:1px solid #f0e8eb;margin:18px 0}
        </style>
                    <form action="" method="post" enctype="multipart/form-data" class="ac-form">
                        <?= csrf_field() ?>

                        <p class="ac-hint" style="margin-top:0">Collez le code fourni par AssoConnect (onglet « Diffusion » &rarr; « Afficher le formulaire de campagne sur un site externe »).</p>

                        <div class="ac-field">
                            <label class="ac-label" for="divCode"><i class="bi bi-file-earmark-code"></i>Code DIV</label>
                            <input type="text" class="form-control ac-code" id="divCode" name="assoconnect_iframe"
                                placeholder='<div class="iframe-asc-container" data-type="collect" ...></div>'
                                value="<?= htmlspecialchars($assoconnectIframe, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="ac-field">
                            <label class="ac-label" for="scriptCode"><i class="bi bi-filetype-js"></i>Code Script</label>
                            <input type="text" class="form-control ac-code" id="scriptCode" name="assoconnect_js"
                                placeholder='<script src="https://....assoconnect.com/..."></script>'
                                value="<?= htmlspecialchars($assoconnectJs, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <hr class="ac-divider">

                        <!-- Lien direct (bouton de repli) -->
                        <div class="ac-field">
                            <label class="ac-label" for="acUrl"><i class="bi bi-box-arrow-up-right"></i>Lien direct AssoConnect <span class="ac-opt">(facultatif)</span></label>
                            <input type="url" class="form-control" id="acUrl" name="assoconnect_url"
                                placeholder="https://www.assoconnect.com/collect/..."
                                value="<?= htmlspecialchars($assoconnectUrl, ENT_QUOTES, 'UTF-8'); ?>">
                            <p class="ac-hint">Affiché comme bouton de secours sous le formulaire sur la page d'inscription, dès qu'un lien valide est saisi — utile si le formulaire intégré ne se charge pas.</p>
                        </div>

                        <div class="text-end mt-3">
                            <button type="submit" name="LinkAssoConnect" class="btn btn-primary w-auto">Sauvegarder</button>
                        </div>
                    </form>
      </div><!-- /setting-card asso -->
    </div><!-- /col-lg-6 -->
    <?php endif; // canCard('inscription','assoconnect') ?>

    <?php if ($canCard('inscription', 'cspdomains')): ?>
    <div class="col-12 col-lg-6">
      <div class="setting-card">
        <h2>Domaines autorisés (AssoConnect)</h2>
        <form action="" method="post" class="row g-3">
          <?= csrf_field() ?>
          <input type="hidden" name="active_tab" value="inscription">
          <div class="col-12">
            <label class="form-label fw-semibold" for="cspDomains"><i class="bi bi-shield-lock me-1"></i>Domaines autorisés dans la politique de sécurité (CSP)</label>
            <textarea class="form-control" id="cspDomains" name="assoconnect_csp_domains" rows="5"
              style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px"
              placeholder="https://*.assoconnect.com&#10;https://*.team.blue&#10;https://*.adyen.com"><?= htmlspecialchars($assoconnectCspDomains !== '' ? $assoconnectCspDomains : $assoconnectCspDefault, ENT_QUOTES, 'UTF-8'); ?></textarea>
            <div class="form-text">
              Un domaine par ligne (format <code>https://...</code>, sous-domaine joker autorisé, ex&nbsp;: <code>https://*.assoconnect.com</code>).
              Ces domaines sont autorisés à charger le formulaire et le paiement AssoConnect (iframe, scripts, paiement Adyen).
              Si AssoConnect change un domaine et que le formulaire ne se charge plus, ajoutez-le ici — sans toucher au code.
              Laisser vide réapplique les domaines par défaut.
            </div>
          </div>
          <div class="col-12 text-end">
            <button type="submit" name="save_csp_domains" class="btn btn-primary w-auto">Sauvegarder</button>
          </div>
        </form>
      </div><!-- /setting-card csp -->
    </div><!-- /col-lg-6 -->
    <?php endif; // canCard('inscription','cspdomains') ?>

  </div><!-- /row -->
</div><!-- /tab-inscription -->
<?php endif; // canTab('inscription') ?>

<!-- ═══ TAB: Parcours ═══ -->
<?php if ($canTab('parcours')): ?>
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
<?php endif; // canTab('parcours') ?>

<!-- ═══ TAB: Reglementation ═══ -->
<?php if ($canTab('reglementation')): ?>
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
        <?= getTinyMceConfig($pdo, ['height' => 430]) ?>
    });
  </script>
</div><!-- /tab-reglementation -->
<?php endif; // canTab('reglementation') ?>

<!-- ═══ TAB: Formulaire ═══ -->
<?php if ($canTab('formulaire')): ?>
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
                  <th class="text-center" style="width:70px" title="Le champ apparaît dans les formulaires. Décoché = champ complètement retiré (invisible partout, même si les autres cases sont cochées).">Actif <i class="bi bi-info-circle text-muted" style="font-size:10px"></i></th>
                  <th class="text-center" style="width:70px" title="Champ OBLIGATOIRE dans les formulaires grand public : inscription en ligne / QR et espace Saisie. L'inscrit ne peut pas valider sans le remplir. Ne concerne PLUS le formulaire admin (voir « Admin requis »), ni l'« Ajout multiple » (voir « Bulk requis »).">Requis <i class="bi bi-info-circle text-muted" style="font-size:10px"></i></th>
                  <th class="text-center" style="width:70px" title="Le champ s'affiche dans le formulaire « Nouvel inscrit » du tableau de bord (admin).">Admin <i class="bi bi-info-circle text-muted" style="font-size:10px"></i></th>
                  <th class="text-center" style="width:75px" title="Champ obligatoire UNIQUEMENT dans le formulaire « Nouvel inscrit » (admin). Indépendant de « Requis » (public / QR / saisie) : l'admin peut donc avoir une obligation différente du grand public. Les champs verrouillés (Nom / Prénom) restent obligatoires.">Admin requis <i class="bi bi-info-circle text-muted" style="font-size:10px"></i></th>
                  <th class="text-center" style="width:70px" title="Le champ s'affiche dans le formulaire de l'espace Saisie.">Saisie <i class="bi bi-info-circle text-muted" style="font-size:10px"></i></th>
                  <th class="text-center" style="width:70px" title="Le champ s'affiche dans le formulaire d'inscription public ouvert via le scan d'un QR Code.">Inscr. QR <i class="bi bi-info-circle text-muted" style="font-size:10px"></i></th>
                  <th class="text-center" style="width:75px" title="Le champ s'affiche dans le formulaire « Ajout multiple » (saisie en lot, ex. une entreprise avec plusieurs inscrits).">Bulk visible <i class="bi bi-info-circle text-muted" style="font-size:10px"></i></th>
                  <th class="text-center" style="width:75px" title="Champ obligatoire UNIQUEMENT dans le mode « Ajout multiple » (saisie en lot). N'a AUCUN effet sur les formulaires normaux — c'est la différence avec « Requis ».">Bulk requis <i class="bi bi-info-circle text-muted" style="font-size:10px"></i></th>
                  <th class="text-center" style="width:60px" title="Type de saisie du champ : texte, nombre, date, liste déroulante, zone de texte, etc.">Type <i class="bi bi-info-circle text-muted" style="font-size:10px"></i></th>
                  <th class="text-center" style="width:70px"></th>
                </tr>
              </thead>
              <tbody>
                <?php
                  // Dépendance : si l'« Autorisation parentale (mineur) » est active, le
                  // champ Commentaire ne peut pas être désactivé (les infos du responsable
                  // légal y sont enregistrées). Seule sa case « Actif » est alors figée —
                  // « Requis » et la visibilité restent librement modifiables.
                  $guardianActiveNow = false;
                  foreach ($allFields as $gf) {
                      if (($gf['field_type'] ?? '') === 'guardian' && (int) ($gf['active'] ?? 0) === 1) { $guardianActiveNow = true; break; }
                  }

                  // Affichage uniquement : on regroupe les champs verrouillés en tête du
                  // tableau (nom, prénom, email, montant dû…), puis les autres — chacun
                  // gardant son ordre habituel. N'affecte PAS l'ordre dans les formulaires.
                  $displayFields = $allFields;
                  usort($displayFields, function ($a, $b) {
                      $la = (int) ($a['is_locked'] ?? 0);
                      $lb = (int) ($b['is_locked'] ?? 0);
                      if ($la !== $lb) return $lb <=> $la; // verrouillés d'abord
                      return (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0);
                  });
                ?>
                <?php foreach ($displayFields as $f):
                  $id = $f['id'];
                  $locked = (int) ($f['is_locked'] ?? 0);
                  $default = (int) ($f['is_default'] ?? 1);
                  $active = (int) ($f['active'] ?? 0);
                  $commentaireLocked = (($f['bdd_column'] ?? '') === 'commentaire' && $guardianActiveNow);
                  // Champ réservé à l'admin + ajout multiple : les contextes grand public
                  // (Saisie, Inscr. QR) sont interdits → cases désactivées et forcées à 0.
                  $adminOnlyField = (($f['bdd_column'] ?? '') === 'date_inscription');
                ?>
                <tr<?= $locked ? ' class="table-light"' : '' ?>>
                  <td>
                    <strong><?= htmlspecialchars($f['label'] ?? $f['fields']) ?></strong>
                    <?php if ($locked): ?><span class="badge bg-secondary ms-1" style="font-size:10px">verrouillé</span><?php endif; ?>
                    <?php if (!$default): ?><span class="badge bg-info ms-1" style="font-size:10px">personnalisé</span><?php endif; ?>
                    <?php if (!empty($f['guardian_section'])): ?><span class="badge ms-1" style="font-size:10px;background:#9a3412" title="Ce champ s'affiche dans le bloc « Autorisation parentale (mineur) » et sa valeur est enregistrée dans le commentaire (aucune colonne en base)."><i class="bi bi-shield-check me-1"></i>Autorisation parentale</span><?php endif; ?>
                    <?php if ($commentaireLocked): ?><span class="badge bg-warning text-dark ms-1" style="font-size:10px" title="Les nom/prénom du responsable légal y sont enregistrés">requis par l'autorisation parentale</span><?php endif; ?>
                    <br><small class="text-muted"><?= !empty($f['guardian_section']) ? '↳ injecté dans le commentaire' : htmlspecialchars($f['bdd_column'] ?? '') ?></small>
                    <?php if (($f['field_type'] ?? '') === 'guardian'): ?>
                      <div class="mt-1 d-flex align-items-center gap-1">
                        <label class="form-label mb-0" style="font-size:11px">Mineur si &lt;</label>
                        <input type="number" name="guardian_age" min="1" max="120" value="<?= (int) ($f['options_list'] ?? 18) ?>" class="form-control form-control-sm" style="width:72px">
                        <small class="text-muted" style="font-size:11px">ans</small>
                      </div>
                      <div class="mt-1">
                        <label class="form-label mb-0" style="font-size:11px">Texte de consentement affiché sous la carte</label>
                        <textarea name="guardian_consent" class="form-control form-control-sm" rows="2" style="font-size:11px" placeholder="Ex : En renseignant ces informations, je certifie être le représentant légal…"><?= htmlspecialchars($f['help_text'] ?? '') ?></textarea>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <?php if ($locked): ?>
                      <input type="checkbox" checked disabled class="form-check-input">
                    <?php elseif ($commentaireLocked): ?>
                      <input type="checkbox" checked disabled class="form-check-input" title="Actif requis par l'autorisation parentale (mineur)">
                      <input type="hidden" name="active_<?= $id ?>" value="1">
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
                    <?php // « Admin requis » : obligation propre au formulaire « Nouvel inscrit ».
                          // Verrouillés (Nom / Prénom) : figés obligatoires comme la colonne « Requis ». ?>
                    <?php if ($locked): ?>
                      <input type="checkbox" checked disabled class="form-check-input">
                    <?php else: ?>
                      <input type="checkbox" name="ra_<?= $id ?>" class="form-check-input" <?= (int)($f['required_admin'] ?? 0) ? 'checked' : '' ?>>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <?php if ($locked): ?>
                      <input type="checkbox" checked disabled class="form-check-input">
                    <?php elseif ($adminOnlyField): ?>
                      <span class="text-muted" title="Non applicable : champ réservé à l'admin / ajout multiple (jamais en saisie publique)">—</span>
                    <?php else: ?>
                      <input type="checkbox" name="vs_<?= $id ?>" class="form-check-input" <?= (int)($f['visible_saisie'] ?? 1) ? 'checked' : '' ?>>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <?php if ($locked): ?>
                      <input type="checkbox" checked disabled class="form-check-input">
                    <?php elseif ($adminOnlyField): ?>
                      <span class="text-muted" title="Non applicable : champ réservé à l'admin / ajout multiple (jamais via QR Code)">—</span>
                    <?php else: ?>
                      <input type="checkbox" name="vq_<?= $id ?>" class="form-check-input" <?= (int)($f['visible_qr'] ?? 1) ? 'checked' : '' ?>>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <input type="checkbox" name="vsm_<?= $id ?>" class="form-check-input" <?= (int)($f['visible_saisie_multiple'] ?? 0) ? 'checked' : '' ?>>
                  </td>
                  <td class="text-center">
                    <input type="checkbox" name="rsm_<?= $id ?>" class="form-check-input" <?= (int)($f['required_saisie_multiple'] ?? 0) ? 'checked' : '' ?>>
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
                  <option value="textarea">Zone de texte (commentaire)</option>
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
              <div class="col-12">
                <label class="form-label">Emplacement du champ</label>
                <select name="new_section" class="form-select">
                  <option value="form" selected>Formulaire (colonne enregistrée en base)</option>
                  <option value="guardian">Autorisation parentale (mineur) — injecté dans le commentaire</option>
                </select>
                <small class="text-muted">« Autorisation parentale » : le champ s'affiche avec le bloc du responsable légal (ex. téléphone des parents) et sa valeur est enregistrée dans le commentaire de l'inscrit.</small>
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
<?php endif; // canTab('formulaire') ?>

<!-- ═══ TAB: Import Excel ═══ -->
<?php if ($canTab('import')): ?>
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
                    <div class="col-md-4"><label class="form-label">Prestation =</label>
                        <input type="text" class="form-control" name="prestation" value="<?= htmlspecialchars($prestation, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="form-text">Colonne AssoConnect qui distingue « Enfant -12 ans avec t-shirt » (laisser « Prestations » par défaut).</div>
                    </div>
                    <div class="col-md-4"><label class="form-label">Montant dû =</label>
                        <input type="text" class="form-control" name="montant_du" value="<?= htmlspecialchars($montant_du, ENT_QUOTES, 'UTF-8'); ?>">
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
<?php endif; // canTab('import') ?>

<!-- ═══ TAB: Import automatique ═══ -->
<?php
// L'onglet « Import AssoConnect » regroupe DEUX fonctionnalités à droits distincts :
//   • Import manuel d'un fichier Excel  → droit dashboard.import_excel
//   • Import automatique (config + cron) → droit settings.tab.import_auto
// L'onglet s'affiche dès que l'un des deux droits est accordé ; chaque bloc est
// gardé indépendamment pour pouvoir autoriser l'un sans l'autre.
$canImportXlsManual = canDoAction('dashboard.import_excel');
if ($canTab('import_auto') || $canImportXlsManual):
?>
<div class="settings-section <?= $activeTab === 'import_auto' ? 'active' : '' ?>" id="tab-import_auto">

  <?php if ($canImportXlsManual): ?>
  <!-- Carte : import manuel (bouton) — droit dashboard.import_excel -->
  <div class="row g-4 mb-1">
    <div class="col-12">
      <div class="setting-card">
        <h2><i class="bi bi-file-earmark-excel me-2"></i>Import manuel d'un fichier Excel</h2>
        <p class="text-muted" style="font-size:14px">
          Importez un fichier Excel AssoConnect téléchargé manuellement. Mêmes règles que l'import automatique :
          doublons ignorés, QR Code selon le réglage global (Réglages → QR Code).
        </p>
        <button type="button" class="btn btn-rose" data-bs-toggle="modal" data-bs-target="#importModal">
          <i class="bi bi-upload me-1"></i>Importer un fichier Excel AssoConnect
        </button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($canTab('import_auto')):
    require_once __DIR__ . '/../config/sync_assoconnect.php';
    $syncCfg     = sync_get_config($pdo);
    $acEnabled   = (int) ($syncCfg['enabled'] ?? 0) === 1;
    $acSendMail  = (int) ($syncCfg['import_send_mail'] ?? 1) === 1;
    $acHasPass   = !empty($syncCfg['ac_password_enc']);
    $acInterval  = (int) ($syncCfg['interval_min'] ?? 30);
    $acStatus    = $syncCfg['last_status'] ?? 'idle';
    $acStBadge   = ['ok'=>'success','error'=>'danger','running'=>'info','idle'=>'secondary'][$acStatus] ?? 'secondary';
    $acStLabel   = ['ok'=>'OK','error'=>'Erreur','running'=>'En cours','idle'=>'Au repos'][$acStatus] ?? $acStatus;
    $acPresets   = [5=>'Toutes les 5 min', 15=>'Toutes les 15 min', 30=>'Toutes les 30 min', 60=>'Toutes les heures', 360=>'Toutes les 6 heures', 1440=>'Une fois par jour'];

    // ── Automatisation (cron) : commande prête à coller dans le panel d'hébergement ──
    $wkToken = sync_get_or_create_token($pdo); // token du cron, auto-généré en base
    $wk_projectRoot = realpath(__DIR__ . '/..');
    $wk_docRoot     = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    if ($wk_projectRoot === $wk_docRoot || $wk_projectRoot === false || $wk_docRoot === false) {
        $wk_baseDir = '';
    } else {
        $wk_baseDir = str_replace('\\', '/', substr($wk_projectRoot, strlen($wk_docRoot)));
    }
    $cronUrl = rtrim(getAppBaseUrl(), '/') . $wk_baseDir . '/inc/import_auto_cron.php';
    $cronAbs = str_replace('\\', '/', (string) realpath(__DIR__ . '/import_auto_cron.php'));
    // Forme wget (URL + token) — recommandée : marche sans connaître le chemin serveur.
    $cronWget = "wget -O - -q '{$cronUrl}?token={$wkToken}' --user-agent=\"CRON\" >/dev/null 2>&1";
    // Forme PHP-CLI (pas de token : exécution locale par le compte d'hébergement).
    $cronCli  = $cronAbs !== '' ? "php -q {$cronAbs} >/dev/null 2>&1" : 'php -q /home/<votre-compte>/.../inc/import_auto_cron.php >/dev/null 2>&1';
?>
  <div class="row g-4">

    <!-- Carte : configuration -->
    <div class="col-12 col-lg-7">
      <div class="setting-card">
        <h2><i class="bi bi-arrow-repeat me-2"></i>Import automatique AssoConnect</h2>
        <p class="text-muted" style="font-size:14px">
          Récupère automatiquement le fichier des inscrits depuis AssoConnect et l'importe
          avec <strong>exactement</strong> la même logique que l'import manuel. L'option « Envoyer les mails »
          ci-dessous se comporte comme la case de l'import manuel ; le QR Code suit le réglage global
          (Réglages → QR Code), comme en manuel.
        </p>

        <form action="" method="post" class="row g-3 needs-validation">
          <?= csrf_field() ?>

          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="ac_enabled" id="ac_enabled" <?= $acEnabled ? 'checked' : '' ?>>
              <label class="form-check-label" for="ac_enabled">
                Activer l'import automatique
                <?= $acEnabled ? '<span class="badge bg-success ms-1">Activé</span>' : '<span class="badge bg-secondary ms-1">Désactivé</span>' ?>
              </label>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label">URL de connexion AssoConnect</label>
            <input type="url" class="form-control" name="ac_login_url" placeholder="https://…/contacts/login"
                   value="<?= htmlspecialchars($syncCfg['ac_login_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">URL de la liste des inscrits</label>
            <input type="url" class="form-control" name="ac_registrants_url" placeholder="https://…/collect/registrants/<ULID>"
                   value="<?= htmlspecialchars($syncCfg['ac_registrants_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-text">L'identifiant (ULID) change à chaque édition : il suffit de mettre cette URL à jour ici.</div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Identifiant (email) AssoConnect</label>
            <input type="email" class="form-control" name="ac_email" autocomplete="off"
                   value="<?= htmlspecialchars($syncCfg['ac_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Mot de passe AssoConnect</label>
            <input type="password" class="form-control" name="ac_password" autocomplete="new-password"
                   placeholder="<?= $acHasPass ? '•••••••• (laisser vide pour conserver)' : 'Saisir le mot de passe' ?>">
            <div class="form-text">
              <i class="bi bi-shield-lock me-1"></i>Chiffré (AES-256-GCM) avant stockage — jamais réaffiché.
            </div>
          </div>

          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="ac_import_send_mail" id="ac_import_send_mail" <?= $acSendMail ? 'checked' : '' ?>>
              <label class="form-check-label" for="ac_import_send_mail">Envoyer les mails d'inscription (avec QR Code selon le réglage global)</label>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Fréquence d'exécution</label>
            <select name="ac_interval_min" class="form-select">
              <?php foreach ($acPresets as $val => $lbl): ?>
                <option value="<?= $val ?>" <?= $acInterval === $val ? 'selected' : '' ?>><?= $lbl ?></option>
              <?php endforeach; ?>
              <?php if (!isset($acPresets[$acInterval])): ?>
                <option value="<?= (int) $acInterval ?>" selected>Personnalisé : <?= (int) $acInterval ?> min</option>
              <?php endif; ?>
            </select>
            <div class="form-text">Prend effet immédiatement, sans toucher au cron (qui tourne toutes les 5 min).</div>
          </div>

          <div class="col-12 d-flex flex-wrap gap-2 justify-content-end">
            <button type="submit" name="save_import_auto" value="test" class="btn btn-outline-secondary w-auto">
              <i class="bi bi-plug me-1"></i>Tester la connexion
            </button>
            <button type="submit" name="save_import_auto" value="run" class="btn btn-outline-primary w-auto">
              <i class="bi bi-play-fill me-1"></i>Lancer maintenant
            </button>
            <button type="submit" name="save_import_auto" value="save" class="btn btn-primary w-auto">
              <i class="bi bi-save me-1"></i>Enregistrer
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Carte : statut -->
    <div class="col-12 col-lg-5">
      <div class="setting-card">
        <h2><i class="bi bi-activity me-2"></i>Statut</h2>
        <ul class="list-unstyled mb-0" style="font-size:14px">
          <li class="d-flex justify-content-between py-2 border-bottom">
            <span class="text-muted">État</span>
            <span class="badge bg-<?= $acStBadge ?>"><?= htmlspecialchars($acStLabel, ENT_QUOTES, 'UTF-8') ?></span>
          </li>
          <li class="d-flex justify-content-between py-2 border-bottom">
            <span class="text-muted">Dernière exécution</span>
            <strong><?= $syncCfg['last_run_at'] ? htmlspecialchars($syncCfg['last_run_at'], ENT_QUOTES, 'UTF-8') : '—' ?></strong>
          </li>
          <li class="d-flex justify-content-between py-2 border-bottom">
            <span class="text-muted">Lignes importées (dernier run)</span>
            <strong><?= (int) ($syncCfg['last_rows'] ?? 0) ?></strong>
          </li>
          <li class="pt-2">
            <span class="text-muted d-block mb-1">Dernier message</span>
            <div class="border rounded p-2 bg-light" style="font-size:13px;white-space:pre-wrap;word-break:break-word">
              <?= $syncCfg['last_message'] ? htmlspecialchars($syncCfg['last_message'], ENT_QUOTES, 'UTF-8') : '—' ?>
            </div>
          </li>
        </ul>
        <p class="text-muted mt-3 mb-0" style="font-size:12px">
          <i class="bi bi-info-circle me-1"></i>« Tester » s'exécute immédiatement. « Lancer maintenant » et l'import
          automatique sont déclenchés par la tâche Cron (ci-dessous), à la fréquence choisie ci-contre.
        </p>
      </div>
    </div>

  </div><!-- /row -->

  <!-- Carte : automatisation (tâche Cron de l'hébergeur) -->
  <div class="row g-4 mt-1">
    <div class="col-12">
      <div class="setting-card">
        <h2><i class="bi bi-clock-history me-2"></i>Automatisation (tâche Cron)</h2>
        <p class="text-muted" style="font-size:14px">
          L'import manuel (« Lancer maintenant ») fonctionne déjà depuis cette page. Pour qu'il se déclenche
          <strong>tout seul</strong> à la fréquence choisie ci-dessus, ajoutez <strong>une seule</strong> tâche Cron
          chez votre hébergeur (PlanetHoster → <em>Crons</em> → <em>Ajouter</em>) avec la commande ci-dessous,
          réglée par exemple sur <code>*/5</code> minutes. Rien à installer, aucun accès serveur.
        </p>

        <div class="mb-3">
          <label class="form-label">Commande à coller <span class="badge bg-success ms-1">recommandée</span></label>
          <div class="input-group">
            <input type="text" class="form-control" id="cronWgetField" value="<?= htmlspecialchars($cronWget, ENT_QUOTES, 'UTF-8') ?>" readonly style="font-family:Consolas,monospace;font-size:12px">
            <button class="btn btn-outline-secondary" type="button" data-wkcopy="cronWgetField"><i class="bi bi-clipboard"></i> Copier</button>
          </div>
          <div class="form-text">PlanetHoster : <em>Crons → Ajouter</em>, mettez « <code>*/5</code> » dans Minute, et collez ceci dans le champ « Commande ».</div>
        </div>

        <div class="mb-3">
          <label class="form-label">Variante en ligne de commande (PHP)</label>
          <div class="input-group">
            <input type="text" class="form-control" id="cronCliField" value="<?= htmlspecialchars($cronCli, ENT_QUOTES, 'UTF-8') ?>" readonly style="font-family:Consolas,monospace;font-size:12px">
            <button class="btn btn-outline-secondary" type="button" data-wkcopy="cronCliField"><i class="bi bi-clipboard"></i> Copier</button>
          </div>
          <div class="form-text">Au choix : certains préfèrent cette forme (exécution locale, sans token dans l'URL).</div>
        </div>

        <div class="d-flex gap-2 flex-wrap align-items-center">
          <input type="password" id="cronTokenView" value="<?= htmlspecialchars($wkToken, ENT_QUOTES, 'UTF-8') ?>" readonly class="form-control" style="max-width:330px;font-family:Consolas,monospace;font-size:12px">
          <button class="btn btn-outline-secondary" type="button" data-wktoggle="cronTokenView"><i class="bi bi-eye"></i> Voir le token</button>
          <form action="" method="post" class="d-inline" id="regenWorkerForm">
            <?= csrf_field() ?>
            <button type="submit" name="regenerate_worker_token" class="btn btn-outline-danger">
              <i class="bi bi-arrow-repeat me-1"></i>Régénérer le token
            </button>
          </form>
        </div>
        <small class="text-muted d-block mt-2">
          <i class="bi bi-shield-lock me-1"></i>Le token sécurise l'URL du cron (comparé en temps constant, HTTPS).
          Régénérer le token impose de mettre à jour la commande dans le panel de l'hébergeur.
        </small>
      </div>
    </div>
  </div><!-- /row cron -->
  <?php endif; // fin bloc automatisation (canTab('import_auto')) ?>
</div><!-- /tab-import_auto -->

<?php if ($canImportXlsManual): ?>
<!-- ═══ Modale d'import manuel (déplacée du dashboard) — droit dashboard.import_excel ═══ -->
<div class="modal fade" id="importModal" tabindex="-1"><div class="modal-dialog modal-lg">
 <div class="modal-content"><div class="modal-header">
   <h5 class="modal-title">Import Excel AssoConnect</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="fImport" enctype="multipart/form-data"><div class="modal-body">
    <input type="file" name="file" id="importFileInput" accept=".xlsx,.xls" class="form-control" required>
    <div class="form-check form-switch mt-3">
      <input class="form-check-input" type="checkbox" name="send_mails" id="importSendMails" checked>
      <label class="form-check-label" for="importSendMails">Envoyer les mails d'inscription</label>
    </div>
    <div id="importPreview" class="mt-3" style="display:none">
      <div id="importPreviewLoading" class="text-center text-muted py-2" style="display:none">
        <span class="spinner-border spinner-border-sm me-1"></span>Analyse du fichier…
      </div>
      <div id="importPreviewResult" style="display:none"></div>
    </div>
    <div id="importProgress" class="mt-3" style="display:none">
      <div id="importProgressLog" style="max-height:300px;overflow-y:auto;font-size:13px;background:#f8f9fa;border-radius:8px;padding:12px;font-family:monospace;"></div>
      <div id="importRecap" class="mt-3" style="display:none"></div>
    </div>
  </div><div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
    <button type="button" id="btnImportClose" class="btn btn-primary" style="display:none">Fermer et actualiser</button>
    <button type="submit" id="btnImportSubmit" class="btn btn-rose" disabled>Importer</button>
  </div></form></div></div></div>

<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  // Token CSRF lu depuis le meta (le dashboard exposait un global _csrfToken ;
  // ici on le reconstruit localement pour rester autonome dans cette page).
  var _csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

document.getElementById('importFileInput').addEventListener('change', async function() {
  const file = this.files[0];
  const preview = document.getElementById('importPreview');
  const loading = document.getElementById('importPreviewLoading');
  const result  = document.getElementById('importPreviewResult');
  const btnImport = document.getElementById('btnImportSubmit');

  btnImport.disabled = true;
  result.style.display = 'none';
  result.innerHTML = '';

  if (!file) { preview.style.display = 'none'; return; }

  preview.style.display = 'block';
  loading.style.display = 'block';

  try {
    const data = await file.arrayBuffer();
    const wb   = XLSX.read(data, {type:'array'});
    const ws   = wb.Sheets[wb.SheetNames[0]];

    (function fixSheetRange(){
      var minR = Infinity, minC = Infinity, maxR = -1, maxC = -1;
      for (var addr in ws) {
        if (!ws.hasOwnProperty(addr) || addr.charAt(0) === '!') continue;
        var cell = XLSX.utils.decode_cell(addr);
        if (cell.r < minR) minR = cell.r;
        if (cell.c < minC) minC = cell.c;
        if (cell.r > maxR) maxR = cell.r;
        if (cell.c > maxC) maxC = cell.c;
      }
      if (maxR >= 0) {
        var realRef = XLSX.utils.encode_range({s:{r:minR,c:minC}, e:{r:maxR,c:maxC}});
        if (ws['!ref'] !== realRef) ws['!ref'] = realRef;
      }
    })();

    const rows = XLSX.utils.sheet_to_json(ws, {header:1});

    if (rows.length < 2) {
      loading.style.display = 'none';
      result.style.display = 'block';
      result.innerHTML = '<div class="alert alert-warning mb-0 py-2"><i class="bi bi-exclamation-triangle me-1"></i>Le fichier semble vide.</div>';
      return;
    }

    const header = rows[0].map(function(h){ return (h||'').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9 ]/g,'').trim(); });

    var dataRows = [];
    for (var r = 1; r < rows.length; r++) {
      var row = rows[r];
      if (!row || !row.length) continue;
      var hasData = false;
      for (var c = 0; c < row.length; c++) {
        if (row[c] !== null && row[c] !== undefined && row[c].toString().trim() !== '') { hasData = true; break; }
      }
      if (hasData) dataRows.push(row);
    }
    const totalRows = dataRows.length;

    var ticketCol = -1;
    for (var i = 0; i < header.length; i++) {
      if (header[i].indexOf('numero') !== -1 && header[i].indexOf('billet') !== -1) { ticketCol = i; break; }
    }

    var tickets = [];
    if (ticketCol >= 0) {
      for (var r = 0; r < dataRows.length; r++) {
        var v = dataRows[r][ticketCol];
        if (v && !isNaN(v)) tickets.push(parseInt(v));
      }
    }

    var dupCount = 0;
    var dupTickets = [];
    if (tickets.length > 0) {
      try {
        var res = await fetch('../config/api.php?route=check-duplicates', {
          method: 'POST',
          headers: {'Content-Type':'application/json', 'X-CSRF-TOKEN': _csrfToken},
          body: JSON.stringify({tickets: tickets}),
          credentials: 'same-origin'
        });
        var json = await res.json();
        dupTickets = json.duplicates || [];
        dupCount = dupTickets.length;
      } catch(e) { /* ignore, non-blocking */ }
    }

    loading.style.display = 'none';
    result.style.display = 'block';

    var html = '<div class="d-flex gap-3 flex-wrap">';
    html += '<div class="border rounded px-3 py-2 text-center flex-fill" style="min-width:120px">';
    html += '<div class="text-muted" style="font-size:12px">Inscrits</div>';
    html += '<div class="fw-bold fs-5 text-primary">' + totalRows + '</div></div>';

    if (dupCount > 0) {
      html += '<div class="border rounded px-3 py-2 text-center flex-fill border-warning" style="min-width:120px">';
      html += '<div class="text-muted" style="font-size:12px">Doublons</div>';
      html += '<div class="fw-bold fs-5 text-warning">' + dupCount + '</div></div>';
    } else {
      html += '<div class="border rounded px-3 py-2 text-center flex-fill border-success" style="min-width:120px">';
      html += '<div class="text-muted" style="font-size:12px">Doublons</div>';
      html += '<div class="fw-bold fs-5 text-success">0</div></div>';
    }

    var newRows = totalRows - dupCount;
    html += '<div class="border rounded px-3 py-2 text-center flex-fill" style="min-width:120px">';
    html += '<div class="text-muted" style="font-size:12px">Nouveaux</div>';
    html += '<div class="fw-bold fs-5 text-success">' + newRows + '</div></div>';
    html += '</div>';

    if (dupCount > 0) {
      html += '<div class="alert alert-warning mt-2 mb-0 py-2" style="font-size:13px">';
      html += '<i class="bi bi-info-circle me-1"></i>' + dupCount + ' doublon(s) seront ignorés lors de l\'import.';
      html += '</div>';
    }

    result.innerHTML = html;
    btnImport.disabled = false;

  } catch(err) {
    loading.style.display = 'none';
    result.style.display = 'block';
    result.innerHTML = '<div class="alert alert-danger mb-0 py-2">Impossible de lire le fichier : ' + err.message + '</div>';
  }
});

// Reset preview when modal closes
document.getElementById('importModal').addEventListener('hidden.bs.modal', function() {
  document.getElementById('fImport').reset();
  document.getElementById('importPreview').style.display = 'none';
  document.getElementById('importPreviewResult').innerHTML = '';
  document.getElementById('importProgress').style.display = 'none';
  document.getElementById('importProgressLog').innerHTML = '';
  document.getElementById('importRecap').style.display = 'none';
  document.getElementById('btnImportSubmit').disabled = true;
  document.getElementById('btnImportSubmit').style.display = 'inline-block';
  document.getElementById('btnImportClose').style.display = 'none';
});

document.getElementById('btnImportClose').addEventListener('click', function() {
  location.reload();
});

/* ══ IMPORT EXCEL — Submit ════ */
document.getElementById('fImport').addEventListener('submit', async (e) => {
  e.preventDefault();

  const form   = e.target;
  const button = document.getElementById('btnImportSubmit');
  const data   = new FormData(form);
  const progressDiv = document.getElementById('importProgress');
  const logDiv = document.getElementById('importProgressLog');
  const recapDiv = document.getElementById('importRecap');
  const closeBtn = document.getElementById('btnImportClose');

  button.disabled = true;
  button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Import en cours…';
  progressDiv.style.display = 'block';
  logDiv.innerHTML = '';
  recapDiv.style.display = 'none';

  function addLog(icon, text, color) {
    const line = document.createElement('div');
    line.style.cssText = 'padding:3px 0;color:' + (color || '#333');
    line.innerHTML = icon + ' ' + text;
    logDiv.appendChild(line);
    logDiv.scrollTop = logDiv.scrollHeight;
  }

  addLog('⏳', 'Import en cours…', '#666');

  try {
    const res = await fetch('../config/api.php?route=import-excel', {
      method:      'POST',
      headers:     {'X-CSRF-TOKEN': _csrfToken},
      body:        data,
      credentials: 'same-origin'
    });

    if (!res.ok) {
      let msg = res.status + ' ' + res.statusText;
      try {
        const j = await res.json();
        if (j && j.error) {
          msg = j.error;
          if (j.missing && j.missing.length) msg += ' — voir logs pour plus d\'infos';
        }
      } catch (e) { /* corps non-JSON, on garde le code HTTP */ }
      throw new Error(msg);
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let finalResult = null;

    while (true) {
      const {done, value} = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, {stream: true});

      const lines = buffer.split('\n');
      buffer = lines.pop();

      for (const line of lines) {
        if (!line.trim()) continue;
        try {
          const evt = JSON.parse(line);
          if (evt.type === 'import_ok') {
            addLog('✅', '<strong>' + evt.count + '</strong> inscription(s) importée(s) en BDD', '#198754');
          } else if (evt.type === 'import_skip') {
            addLog('⚠️', evt.count + ' ligne(s) ignorée(s) — ' + evt.duplicates + ' doublon(s)', '#e67e22');
          } else if (evt.type === 'mail_sent') {
            addLog('📧', 'Mail envoyé pour <strong>' + evt.inscription_no + '</strong>' + (evt.qrcode ? ' (avec QR code)' : ''), '#0d6efd');
          } else if (evt.type === 'mail_error') {
            addLog('❌', 'Échec mail pour ' + evt.inscription_no + ' : ' + evt.error, '#dc3545');
          } else if (evt.type === 'mail_skip') {
            addLog('⏭️', 'Mails non envoyés (désactivé)', '#666');
          } else if (evt.type === 'done') {
            finalResult = evt;
          } else if (evt.type === 'error') {
            addLog('❌', 'Erreur : ' + evt.message, '#dc3545');
          }
        } catch(e) {}
      }
    }

    if (finalResult) {
      recapDiv.style.display = 'block';
      recapDiv.innerHTML = '<div class="d-flex gap-3 flex-wrap">'
        + '<div class="border rounded px-3 py-2 text-center flex-fill border-success"><div class="text-muted" style="font-size:12px">Importées</div><div class="fw-bold fs-5 text-success">' + finalResult.rows_added + '</div></div>'
        + '<div class="border rounded px-3 py-2 text-center flex-fill border-warning"><div class="text-muted" style="font-size:12px">Ignorées</div><div class="fw-bold fs-5 text-warning">' + finalResult.rows_skipped + '</div></div>'
        + '<div class="border rounded px-3 py-2 text-center flex-fill border-primary"><div class="text-muted" style="font-size:12px">Mails envoyés</div><div class="fw-bold fs-5 text-primary">' + finalResult.mails_sent + '</div></div>'
        + '</div>';
    }

    button.style.display = 'none';
    closeBtn.style.display = 'inline-block';

  } catch (err) {
    addLog('❌', 'Erreur : ' + err.message, '#dc3545');
    button.innerHTML = 'Importer';
    button.style.display = 'inline-block';
    button.disabled = false;
  }
});
})();
</script>
<?php endif; // canImportXlsManual : modale + JS import manuel ?>

<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  var root = document.getElementById('tab-import_auto');
  if(!root) return;
  // Copier (champ ou textarea) — attributs dédiés (data-wkcopy/data-wktoggle) pour
  // ne jamais entrer en conflit avec le JS de l'onglet API (data-copy global).
  root.querySelectorAll('[data-wkcopy]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var field = document.getElementById(btn.getAttribute('data-wkcopy'));
      if(!field) return;
      var wasPassword = field.type === 'password';
      if(wasPassword) field.type = 'text';
      field.select();
      try { field.setSelectionRange(0, 99999); } catch(e){}
      var done = function(){
        var old = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2"></i> Copié';
        setTimeout(function(){ btn.innerHTML = old; }, 1500);
      };
      if(navigator.clipboard){
        navigator.clipboard.writeText(field.value).then(done, function(){ try{document.execCommand('copy');done();}catch(e){} });
      } else {
        try{ document.execCommand('copy'); done(); }catch(e){}
      }
      if(wasPassword) field.type = 'password';
      if(window.getSelection) window.getSelection().removeAllRanges();
    });
  });
  // Afficher / masquer le token
  root.querySelectorAll('[data-wktoggle]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var field = document.getElementById(btn.getAttribute('data-wktoggle'));
      if(!field) return;
      field.type = (field.type === 'password') ? 'text' : 'password';
      btn.innerHTML = (field.type === 'password') ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
    });
  });
  // Confirmation avant régénération du token
  var regen = document.getElementById('regenWorkerForm');
  if(regen){
    regen.addEventListener('submit', function(e){
      if(!confirm('Régénérer le token ? Le cron cessera de fonctionner tant que vous n\'aurez pas mis à jour la commande (avec le nouveau token) dans votre panel d\'hébergement.')){
        e.preventDefault();
      }
    });
  }
})();
</script>
<?php endif; // fin onglet Import AssoConnect (canTab('import_auto') OU dashboard.import_excel) ?>

<!-- ═══ TAB: Maintenance ═══ -->
<?php if ($canTab('maintenance')): ?>
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
<?php endif; // canTab('maintenance') ?>

<!-- ═══ TAB: API ═══ -->
<?php if ($canTab('api')): ?>
<div class="settings-section <?= $activeTab === 'api' ? 'active' : '' ?>" id="tab-api">
  <div class="row g-4">

    <!-- Carte : activation -->
    <div class="col-12">
      <div class="setting-card">
        <h2>API — Connexion d'applications externes</h2>
        <p class="text-muted">
          L'API permet à d'autres logiciels de se connecter au site de manière sécurisée :
          importer un fichier Excel, ajouter un inscrit ou consulter les statistiques,
          exactement comme depuis le tableau de bord.
        </p>
        <form action="" method="post" class="row g-3 needs-validation">
          <?= csrf_field() ?>
          <div class="col-12">
            <label class="form-label">Activer l'API</label>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="api_enabled" id="api_enabled" <?= $api_enabled ? 'checked' : '' ?>>
              <label class="form-check-label" for="api_enabled">
                <?= $api_enabled ? '<span class="badge bg-success">Activée</span>' : '<span class="badge bg-secondary">Désactivée</span>' ?>
              </label>
            </div>
            <small class="text-muted">
              Quand l'API est désactivée, toutes les requêtes externes sont refusées.
              Un identifiant et un token sont générés automatiquement à la première activation.
            </small>
          </div>
          <div class="col-12 text-end">
            <button type="submit" name="save_api" class="btn btn-primary w-auto">Enregistrer</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Carte : identifiants -->
    <div class="col-12">
      <div class="setting-card">
        <h2>Identifiants de connexion</h2>

        <div class="mb-3">
          <label class="form-label">URL de l'API</label>
          <div class="input-group">
            <input type="text" class="form-control" id="apiUrlField" value="<?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>" readonly>
            <button class="btn btn-outline-secondary" type="button" data-copy="apiUrlField"><i class="bi bi-clipboard"></i> Copier</button>
          </div>
          <small class="text-muted">Adresse à utiliser depuis vos applications externes. Exemple : <code><?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?endpoint=ping</code></small>
        </div>

        <?php if ($api_user && $api_token): ?>
          <div class="alert alert-warning">
            <i class="bi bi-shield-lock me-2"></i>
            Ne partagez jamais ces identifiants. Toute application qui les détient peut agir
            sur vos inscrits. L'API n'accepte que les connexions HTTPS (le HTTP est
            automatiquement bloqué).
          </div>
          <div class="mb-3">
            <label class="form-label">Identifiant (X-Api-User)</label>
            <div class="input-group">
              <input type="text" class="form-control" id="apiUserField" value="<?= htmlspecialchars($api_user, ENT_QUOTES, 'UTF-8') ?>" readonly>
              <button class="btn btn-outline-secondary" type="button" data-copy="apiUserField"><i class="bi bi-clipboard"></i> Copier</button>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Token (X-Api-Token)</label>
            <div class="input-group">
              <input type="password" class="form-control" id="apiTokenField" value="<?= htmlspecialchars($api_token, ENT_QUOTES, 'UTF-8') ?>" readonly>
              <button class="btn btn-outline-secondary" type="button" data-toggle-visibility="apiTokenField"><i class="bi bi-eye"></i></button>
              <button class="btn btn-outline-secondary" type="button" data-copy="apiTokenField"><i class="bi bi-clipboard"></i> Copier</button>
            </div>
          </div>
          <div class="d-flex gap-2 flex-wrap align-items-center">
            <a href="api-doc.php" target="_blank" rel="noopener" class="btn btn-info">
              <i class="bi bi-book me-1"></i>Voir la documentation
            </a>
            <form action="" method="post" class="d-inline" id="regenApiForm">
              <?= csrf_field() ?>
              <button type="submit" name="regenerate_api" class="btn btn-outline-danger">
                <i class="bi bi-arrow-repeat me-1"></i>Régénérer les identifiants
              </button>
            </form>
          </div>
          <small class="text-muted d-block mt-2">
            Régénérer crée un nouvel identifiant et un nouveau token. Les applications
            utilisant les anciens identifiants devront être mises à jour.
          </small>
        <?php else: ?>
          <p class="text-muted">
            Activez l'API ci-dessus : un identifiant et un token seront générés automatiquement
            et s'afficheront ici.
          </p>
          <a href="api-doc.php" target="_blank" rel="noopener" class="btn btn-info">
            <i class="bi bi-book me-1"></i>Voir la documentation
          </a>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /row -->
</div><!-- /tab-api -->
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  // Copier un champ dans le presse-papiers
  document.querySelectorAll('[data-copy]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var field = document.getElementById(btn.getAttribute('data-copy'));
      if(!field) return;
      var wasPassword = field.type === 'password';
      if(wasPassword) field.type = 'text';
      field.select();
      field.setSelectionRange(0, 99999);
      var done = function(){
        var old = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2"></i> Copié';
        setTimeout(function(){ btn.innerHTML = old; }, 1500);
      };
      if(navigator.clipboard){
        navigator.clipboard.writeText(field.value).then(done, function(){ try{document.execCommand('copy');done();}catch(e){} });
      } else {
        try{ document.execCommand('copy'); done(); }catch(e){}
      }
      if(wasPassword) field.type = 'password';
      window.getSelection().removeAllRanges();
    });
  });
  // Afficher / masquer le token
  document.querySelectorAll('[data-toggle-visibility]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var field = document.getElementById(btn.getAttribute('data-toggle-visibility'));
      if(!field) return;
      field.type = (field.type === 'password') ? 'text' : 'password';
      btn.innerHTML = (field.type === 'password') ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
    });
  });
  // Confirmation avant régénération
  var regenForm = document.getElementById('regenApiForm');
  if(regenForm){
    regenForm.addEventListener('submit', function(e){
      if(!confirm('Régénérer les identifiants ? Les applications utilisant les anciens identifiants ne fonctionneront plus.')){
        e.preventDefault();
      }
    });
  }
})();
</script>
<?php endif; // canTab('api') ?>

<!-- Paramètres mail déplacé vers mail-settings.php -->

<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  // Config commune TinyMCE pour les titres (hérite de la config centralisée)
  var tinyOpts = {
    <?= getTinyMceConfig($pdo, [
        'plugins' => 'code image',
        'toolbar' => 'fontfamily fontsize | bold italic underline | forecolor | alignleft aligncenter alignright | image | removeformat code',
        'height' => 350,
    ]) ?>,
    resize: true,
    statusbar: true,
    object_resizing: 'img',
    image_advtab: true,
    image_dimensions: true,
    content_style: '<?= getTinyMceFontStyles() ?>body { font-family: <?= getThemeFontStack($pdo) ?>; font-size: 32px; color: #ffffff; background: #1e293b; text-align: center; padding: 16px; } p { margin: 0; } img { max-width: 100%; height: auto; }',
    font_size_formats: '16px 20px 24px 28px 32px 40px 48px 56px 64px 72px 80px',
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

<!-- Éditeur riche du message « inscriptions fermées » (config complète, multi-lignes) -->
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  var TA_ID = 'registrationClosedMessageEditor';

  function initEditor(){
    if (typeof tinymce === 'undefined') return false;      // pas encore chargé
    if (!document.getElementById(TA_ID)) return true;      // champ absent : rien à faire
    if (tinymce.get(TA_ID)) return true;                   // déjà initialisé : ne pas dupliquer
    tinymce.init({
      selector: '#' + TA_ID,
      <?= getTinyMceConfig($pdo, ['height' => 320]) ?>
    });
    return true;
  }

  // Cas normal : TinyMCE est déjà chargé (par l'onglet Réglementation) → on initialise.
  if (initEditor()) return;

  // Rôle sans onglet Réglementation : TinyMCE n'est pas chargé. On le charge UNE seule
  // fois pour garantir que ce champ soit un vrai éditeur (sinon l'alignement / la mise en
  // forme ne seraient jamais transmis au serveur → « Aucun changement détecté »).
  var loader = document.querySelector('script[data-tinymce-fallback]');
  if (!loader) {
    loader = document.createElement('script');
    loader.src = '../js/tinymce/tinymce.min.js';
    loader.setAttribute('data-tinymce-fallback', '1');
    document.head.appendChild(loader);
  }
  loader.addEventListener('load', initEditor);
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
  <?php foreach ($customFonts as $name => $path): ?>
  fontMap['<?= addslashes($name) ?>'] = "'<?= addslashes($name) ?>', sans-serif";
  <?php endforeach; ?>
  var loadedGoogleFonts = {};
  var customFontNames = <?= json_encode(array_keys($customFonts)) ?>;

  // Font picker dropdown
  var fpToggle = document.getElementById('fontPickerToggle');
  var fpDrop = document.getElementById('fontPickerDropdown');
  var fpLabel = document.getElementById('fontPickerLabel');
  if (fpToggle) {
    fpToggle.addEventListener('click', function() {
      fpDrop.style.display = fpDrop.style.display === 'none' ? '' : 'none';
    });
    document.addEventListener('click', function(e) {
      if (!e.target.closest('.font-picker-wrapper')) fpDrop.style.display = 'none';
    });
    fpDrop.querySelectorAll('.font-picker-item').forEach(function(item) {
      item.addEventListener('click', function() {
        var val = this.dataset.value;
        font.value = val;
        fpLabel.textContent = this.textContent.replace(/\s*custom\s*$/, '').trim();
        fpLabel.style.fontFamily = fontMap[val] || "'" + val + "', sans-serif";
        fpDrop.querySelectorAll('.font-picker-item').forEach(function(i) { i.classList.remove('active'); i.style.background = ''; });
        this.classList.add('active');
        this.style.background = '#fdf2f8';
        fpDrop.style.display = 'none';
        // Charger dynamiquement la Google Font si nécessaire
        if (val !== 'system-ui' && customFontNames.indexOf(val) === -1 && !loadedGoogleFonts[val]) {
          var link = document.createElement('link');
          link.rel = 'stylesheet';
          link.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(val) + ':wght@300;400;500;600;700;800;900&display=swap';
          document.head.appendChild(link);
          loadedGoogleFonts[val] = true;
        }
        updatePreview();
      });
    });
    // Précharger les Google Fonts visibles pour l'aperçu dans le dropdown
    var gfToLoad = [];
    fpDrop.querySelectorAll('.font-picker-item').forEach(function(item) {
      var v = item.dataset.value;
      if (v !== 'system-ui' && customFontNames.indexOf(v) === -1) gfToLoad.push(v);
    });
    if (gfToLoad.length > 0) {
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = 'https://fonts.googleapis.com/css2?family=' + gfToLoad.map(function(f) { return encodeURIComponent(f) + ':wght@400;700'; }).join('&family=') + '&display=swap';
      document.head.appendChild(link);
    }
  }

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
    var fs = document.getElementById('prevFontSample');
    if (fs) { fs.style.fontFamily = ff; fs.style.borderColor = borderColor; }

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

<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
/* ── Envoi AJAX pour contourner le WAF (encode les champs HTML en Base64) ── */
(function () {

    /* Fonction générique d'envoi AJAX */
    function ajaxSubmit(btn, fieldsToEncode, tab) {
        var form = btn.closest('form');
        if (!form) return;

        /* TinyMCE : forcer la sauvegarde si disponible */
        if (typeof tinymce !== 'undefined') tinymce.triggerSave();

        var fd = new FormData(form);
        fd.set(btn.name, '1');

        /* Encoder les champs sensibles en Base64 */
        fieldsToEncode.forEach(function (name) {
            var val = fd.get(name) || '';
            if (val) fd.set(name, btoa(unescape(encodeURIComponent(val))));
        });

        fetch(form.action || window.location.href, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                var url = window.location.pathname + (tab ? '?tab=' + tab : '');
                window.location.href = url;
            }
            else if (typeof showToast === 'function') showToast(data.message || 'Erreur', 'danger');
        })
        .catch(function (err) {
            if (typeof showToast === 'function') showToast('Erreur : ' + err.message, 'danger');
        });
    }

    /* AssoConnect */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('button[name="LinkAssoConnect"]');
        if (btn) { e.preventDefault(); ajaxSubmit(btn, ['assoconnect_iframe', 'assoconnect_js'], 'inscription'); }
    });

    /* Réglementation (TinyMCE) */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('button[name="reglementation"]');
        if (btn) { e.preventDefault(); ajaxSubmit(btn, ['div_reglementation'], 'reglementation'); }
    });

    /* Message « inscriptions fermées » (TinyMCE) */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('button[name="save_closed_message"]');
        if (btn) { e.preventDefault(); ajaxSubmit(btn, ['registration_closed_message'], 'inscription'); }
    });

    /* Titre / Image sur la vidéo (TinyMCE) */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('button[name="save_hero"]');
        if (btn) { e.preventDefault(); ajaxSubmit(btn, ['titleAccueil', 'titleAccueil_mobile'], 'accueil'); }
    });

    /* En-tête du site d'inscription (TinyMCE) */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('button[name="save_header"]');
        if (btn) { e.preventDefault(); ajaxSubmit(btn, ['title', 'title_mobile'], 'inscription'); }
    });

})();
</script>

<?php if ($canCard('accueil', 'custom')): ?>
<!-- Sortable.js (drag & drop pour l'éditeur de mise en page accueil) -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<!-- CodeMirror 5 : éditeur de code pour les blocs HTML/CSS/JS -->
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/lib/codemirror.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/css/css.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/javascript/javascript.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/edit/closetag.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/edit/matchbrackets.min.js"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  var rowsEl = document.getElementById('leRows');
  if (!rowsEl) return;
  var statusEl = document.getElementById('layoutSaveStatus');
  var modalEl  = document.getElementById('customBlockModal');
  var modalTitle = document.getElementById('customBlockModalTitle');
  var editIdInput = document.getElementById('customBlockEditId');
  var titleInput = document.getElementById('customBlockTitle');
  var bsModal = null;
  var pendingCol = null; // colonne en cours d'édition (pour update vs add)

  var PREDEF = <?= json_encode($predefinedSections, JSON_UNESCAPED_UNICODE) ?>;
  var ALLOWED_W = <?= json_encode($allowedWidths) ?>;

  function escHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // ── Init Sortable sur les lignes ──
  Sortable.create(rowsEl, {
    handle: '.le-row-handle',
    animation: 150,
    ghostClass: 'le-row-ghost',
    chosenClass: 'le-row-chosen',
  });

  // ── Init Sortable sur les colonnes de chaque ligne (groupes pour permettre déplacement entre lignes) ──
  function initColSortable(rowEl) {
    var colsEl = rowEl.querySelector('.le-row-cols');
    if (!colsEl || colsEl.dataset.sortableInit) return;
    colsEl.dataset.sortableInit = '1';
    Sortable.create(colsEl, {
      handle: '.le-col-handle',
      animation: 150,
      group: 'le-cols',
      ghostClass: 'le-col-ghost',
      chosenClass: 'le-col-chosen',
      onEnd: function() { refreshAllRowsInfo(); enforceRowMax(); }
    });
  }
  rowsEl.querySelectorAll('.le-row').forEach(initColSortable);

  function refreshAllRowsInfo() {
    rowsEl.querySelectorAll('.le-row').forEach(function(row) {
      var nb = row.querySelectorAll('.le-col').length;
      var info = row.querySelector('.le-row-info-text');
      if (info) info.textContent = 'Ligne (' + nb + ' col.)';
      // Toggle bouton split (pas plus de 3 colonnes)
      var splitBtn = row.querySelector('button[data-act="split"]');
      if (splitBtn) splitBtn.style.display = nb >= 3 ? 'none' : '';
    });
  }

  function enforceRowMax() {
    // Supprime les lignes vides
    rowsEl.querySelectorAll('.le-row').forEach(function(row) {
      if (row.querySelectorAll('.le-col').length === 0) row.remove();
    });
  }

  // ── Helpers ──
  function newRowId() { return 'row_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6); }
  function newCustomId() { return 'custom_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6); }

  function makeColEl(opts) {
    // opts = { type, width, visible, id?, title?, content? }
    var col = document.createElement('div');
    col.className = 'le-col' + (opts.visible === false ? ' is-hidden' : '');
    col.dataset.sectionType = opts.type;
    col.dataset.width = opts.width;
    col.style.setProperty('--col-flex', opts.width);
    if (opts.type === 'custom') {
      col.dataset.sectionId      = opts.id || newCustomId();
      col.dataset.sectionTitle   = opts.title || '';
      col.dataset.sectionContent = opts.content || '';
    }

    var widthOpts = ALLOWED_W.map(function(w) {
      return '<option value="' + w + '"' + (w == opts.width ? ' selected' : '') + '>' + w + '/12</option>';
    }).join('');

    var actions = '';
    actions += '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="toggle-vis" title="' + (opts.visible !== false ? 'Masquer' : 'Afficher') + '">';
    actions += '  <i class="bi ' + (opts.visible !== false ? 'bi-eye' : 'bi-eye-slash') + '"></i></button>';
    if (opts.type === 'custom') {
      actions += '<button type="button" class="btn btn-sm btn-outline-primary" data-act="edit" title="Modifier"><i class="bi bi-pencil"></i></button>';
      actions += '<button type="button" class="btn btn-sm btn-outline-danger" data-act="del-col" title="Supprimer ce bloc"><i class="bi bi-trash3"></i></button>';
    } else {
      actions += '<button type="button" class="btn btn-sm btn-outline-danger" data-act="del-col" title="Retirer cette colonne"><i class="bi bi-x-lg"></i></button>';
    }

    col.innerHTML = ''
      + '<div class="le-col-toolbar">'
      + '  <span class="le-handle le-col-handle" title="Glisser"><i class="bi bi-grip-vertical"></i></span>'
      + '  <select class="form-select form-select-sm le-width-select" title="Largeur" style="width:auto;flex-shrink:0;">' + widthOpts + '</select>'
      + '  <div class="ms-auto d-flex gap-1">' + actions + '</div>'
      + '</div>'
      + '<div class="le-col-preview">' + thumbHtml(opts) + '</div>';
    return col;
  }

  function thumbHtml(opts) {
    // Pour les blocs custom : on rend exactement le même HTML que PHP (custom-content-section + custom-content-inner)
    if (opts.type === 'custom') {
      var content = opts.content || '';
      if (content.trim() === '') {
        return '<div style="padding:40px 20px;text-align:center;color:#94a3b8;background:#fdf8fa;border-radius:6px;">'
             + '<i class="bi bi-text-paragraph" style="font-size:24px;display:block;margin-bottom:8px;"></i>'
             + '<em>Bloc personnalisé vide — clic sur ✏️ pour éditer</em></div>';
      }
      return '<section class="custom-content-section">'
           + '  <div class="custom-content-inner">' + content + '</div>'
           + '</section>';
    }
    // Pour les pré-définis ajoutés à la volée par JS : placeholder (le rendu réel apparaîtra après save+reload)
    var meta = PREDEF[opts.type] || { label: opts.type, icon: 'bi-square' };
    return '<div style="padding:40px 20px;text-align:center;background:linear-gradient(135deg,#fce7f3,#fdf2f8);border-radius:6px;">'
         + '  <i class="bi ' + meta.icon + '" style="font-size:32px;color:#9d174d;display:block;margin-bottom:8px;"></i>'
         + '  <div style="font-weight:700;color:#1e293b;margin-bottom:4px;">' + escHtml(meta.label) + '</div>'
         + '  <div style="font-size:11px;color:#9d174d;background:#fff;display:inline-block;padding:2px 10px;border-radius:999px;margin-top:4px;">Sauvegardez pour voir l\'aperçu réel</div>'
         + '</div>';
  }

  function makeRowEl(cols) {
    // cols = array of opts
    var row = document.createElement('div');
    row.className = 'le-row';
    row.dataset.rowId = newRowId();
    row.innerHTML = ''
      + '<div class="le-row-toolbar">'
      + '  <span class="le-handle le-row-handle" title="Réorganiser la ligne"><i class="bi bi-arrows-move"></i></span>'
      + '  <span class="le-row-info"><i class="bi bi-layout-three-columns"></i> <span class="le-row-info-text">Ligne (' + cols.length + ' col.)</span></span>'
      + '  <div class="ms-auto d-flex gap-1">'
      + '    <button type="button" class="btn btn-sm btn-outline-primary" data-act="split" title="Diviser : ajouter une colonne"><i class="bi bi-layout-split"></i></button>'
      + '    <button type="button" class="btn btn-sm btn-outline-danger" data-act="del-row" title="Supprimer la ligne"><i class="bi bi-trash3"></i></button>'
      + '  </div>'
      + '</div>'
      + '<div class="le-row-cols"></div>';
    var colsContainer = row.querySelector('.le-row-cols');
    cols.forEach(function(c) { colsContainer.appendChild(makeColEl(c)); });
    return row;
  }

  // ── Délégation des actions ──
  rowsEl.addEventListener('click', function(e) {
    var btn = e.target.closest('button[data-act]');
    if (!btn) return;
    var act = btn.dataset.act;
    var row = btn.closest('.le-row');
    var col = btn.closest('.le-col');

    if (act === 'del-row') {
      if (!confirm('Supprimer cette ligne entière (toutes ses colonnes) ?')) return;
      row.remove();
      return;
    }
    if (act === 'split') {
      var nb = row.querySelectorAll('.le-col').length;
      if (nb >= 3) return;
      // Calcule largeur restante
      var total = 0;
      row.querySelectorAll('.le-col').forEach(function(c) { total += parseInt(c.dataset.width, 10) || 0; });
      var newW = 12 - total;
      if (newW < 3) {
        // Réduit toutes les colonnes pour faire de la place
        row.querySelectorAll('.le-col').forEach(function(c) {
          var w = parseInt(c.dataset.width, 10) || 0;
          var nw = Math.max(3, w - 3);
          c.dataset.width = nw;
          c.style.setProperty('--col-flex', nw);
          var sel = c.querySelector('.le-width-select'); if (sel) sel.value = nw;
        });
        total = 0;
        row.querySelectorAll('.le-col').forEach(function(c) { total += parseInt(c.dataset.width, 10) || 0; });
        newW = Math.max(3, 12 - total);
      }
      var newCol = makeColEl({ type: 'custom', width: newW, visible: true, id: newCustomId(), title: '', content: '' });
      row.querySelector('.le-row-cols').appendChild(newCol);
      refreshAllRowsInfo();
      return;
    }
    if (act === 'del-col') {
      if (col.dataset.sectionType === 'custom') {
        if (!confirm('Supprimer ce bloc personnalisé ?')) return;
      }
      col.remove();
      enforceRowMax();
      refreshAllRowsInfo();
      return;
    }
    if (act === 'toggle-vis') {
      var nowHidden = col.classList.toggle('is-hidden');
      var icon = btn.querySelector('i');
      icon.className = 'bi ' + (nowHidden ? 'bi-eye-slash' : 'bi-eye');
      btn.title = nowHidden ? 'Afficher' : 'Masquer';
      return;
    }
    if (act === 'edit') {
      pendingCol = col;
      modalTitle.textContent = 'Modifier le bloc personnalisé';
      editIdInput.value = col.dataset.sectionId || '';
      titleInput.value  = col.dataset.sectionTitle || '';
      var content = col.dataset.sectionContent || '';
      openModal(content);
      return;
    }
  });

  // ── Délégation : changement de largeur ──
  rowsEl.addEventListener('change', function(e) {
    var sel = e.target.closest('.le-width-select');
    if (!sel) return;
    var col = sel.closest('.le-col');
    var newW = parseInt(sel.value, 10);
    var row = col.closest('.le-row');
    // Vérif somme ≤ 12
    var others = 0;
    row.querySelectorAll('.le-col').forEach(function(c) {
      if (c !== col) others += parseInt(c.dataset.width, 10) || 0;
    });
    if (newW + others > 12) {
      alert('Largeur impossible : la somme dépasserait 12. Réduisez d\'abord les autres colonnes de cette ligne.');
      sel.value = col.dataset.width;
      return;
    }
    col.dataset.width = newW;
    col.style.setProperty('--col-flex', newW);
  });

  // ── Modal TinyMCE ──
  function openModal(initialContent) {
    if (!bsModal && typeof bootstrap !== 'undefined') bsModal = new bootstrap.Modal(modalEl);
    if (bsModal) bsModal.show();
    modalEl.addEventListener('shown.bs.modal', function once() {
      modalEl.removeEventListener('shown.bs.modal', once);
      initEditor(initialContent);
    });
  }
  function initEditor(initialContent) {
    if (typeof tinymce === 'undefined') return;
    var ed = tinymce.get('customBlockEditor');
    if (ed) ed.remove();
    tinymce.init({
      selector: '#customBlockEditor',
      <?= getTinyMceConfig($pdo) ?>
    }).then(function(editors) {
      if (editors && editors[0]) editors[0].setContent(initialContent || '');
    });
  }
  function getEditorContent() {
    if (typeof tinymce === 'undefined') return '';
    var ed = tinymce.get('customBlockEditor');
    return ed ? ed.getContent() : (document.getElementById('customBlockEditor').value || '');
  }

  // ── Ajouter un bloc personnalisé (nouvelle ligne pleine largeur) ──
  document.getElementById('btnAddCustomBlock').addEventListener('click', function() {
    pendingCol = null;
    modalTitle.textContent = 'Ajouter un bloc personnalisé';
    editIdInput.value = '';
    titleInput.value  = '';
    openModal('');
  });

  // ── Replacer une section pré-définie (clic sur bouton dans sidebar Ajouter) ──
  var restoreMenu = document.getElementById('restoreMenu');
  if (restoreMenu) {
    restoreMenu.addEventListener('click', function(e) {
      var item = e.target.closest('button[data-restore-type], a[data-restore-type]');
      if (!item) return;
      e.preventDefault();
      var type = item.dataset.restoreType;
      var newRow = makeRowEl([{ type: type, width: 12, visible: true }]);
      rowsEl.appendChild(newRow);
      initColSortable(newRow);
      refreshAllRowsInfo();
    });
  }

  // ── Valider modal ──
  document.getElementById('btnSaveCustomBlock').addEventListener('click', function() {
    var id = editIdInput.value.trim();
    var title = titleInput.value.trim();
    var content = getEditorContent();

    if (id && pendingCol) {
      // Update
      pendingCol.dataset.sectionTitle = title;
      pendingCol.dataset.sectionContent = content;
      pendingCol.querySelector('.le-col-preview').innerHTML = thumbHtml({
        type: 'custom', title: title, content: content
      });
    } else {
      // Add as new full-width row
      var newRow = makeRowEl([{
        type: 'custom', width: 12, visible: true,
        id: newCustomId(), title: title, content: content
      }]);
      rowsEl.appendChild(newRow);
      initColSortable(newRow);
      refreshAllRowsInfo();
    }
    pendingCol = null;
    if (bsModal) bsModal.hide();
  });

  // ── Sauvegarde du layout (POST AJAX du JSON) ──
  document.getElementById('btnSaveLayout').addEventListener('click', function() {
    var layout = [];
    rowsEl.querySelectorAll('.le-row').forEach(function(row) {
      var cols = [];
      row.querySelectorAll('.le-col').forEach(function(col) {
        var sec = {
          type: col.dataset.sectionType,
          visible: !col.classList.contains('is-hidden'),
        };
        if (sec.type === 'custom') {
          sec.id      = col.dataset.sectionId || '';
          sec.title   = col.dataset.sectionTitle || '';
          sec.content = col.dataset.sectionContent || '';
        }
        cols.push({ width: parseInt(col.dataset.width, 10) || 12, section: sec });
      });
      if (cols.length === 0) return;
      layout.push({ id: row.dataset.rowId, columns: cols });
    });

    var btn = this;
    btn.disabled = true;
    statusEl.style.display = 'block';
    statusEl.style.color = '#64748b';
    statusEl.textContent = 'Enregistrement…';

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfTok  = csrfMeta ? csrfMeta.getAttribute('content') : '';

    fetch('', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfTok, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ save_accueil_layout: 1, layout: layout, csrf_token: csrfTok })
    })
    .then(function(r) { return r.json(); })
    .then(function(j) {
      btn.disabled = false;
      if (j && j.ok) {
        statusEl.style.color = '#16a34a';
        statusEl.textContent = '✓ Mise en page enregistrée.';
      } else {
        statusEl.style.color = '#dc2626';
        statusEl.textContent = '✗ ' + ((j && j.err) || 'Échec de l\'enregistrement.');
      }
      setTimeout(function() { statusEl.style.display = 'none'; }, 4000);
    })
    .catch(function() {
      btn.disabled = false;
      statusEl.style.color = '#dc2626';
      statusEl.textContent = '✗ Erreur réseau.';
    });
  });

  // ════════════════════════════════════════════════════════════════════
  // NOUVELLES INTERACTIONS (sidebar droite + selection + edit modal universel)
  // ════════════════════════════════════════════════════════════════════

  // ── Tabs sidebar (Propriétés / Ajouter) ──
  document.querySelectorAll('.le-sb-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
      var target = tab.dataset.sbTab;
      document.querySelectorAll('.le-sb-tab').forEach(function(t) { t.classList.toggle('active', t === tab); });
      document.querySelectorAll('.le-sb-pane').forEach(function(p) { p.classList.toggle('active', p.dataset.sbPane === target); });
    });
  });

  // ── Sélection au clic sur une section (col ou bloc Hero) ──
  var currentSelected = null;
  function selectSection(el) {
    if (currentSelected) currentSelected.classList.remove('is-selected');
    currentSelected = el;
    if (el) {
      el.classList.add('is-selected');
      populateSidebarProps(el);
      // Switch to Propriétés tab automatically
      document.querySelector('.le-sb-tab[data-sb-tab="props"]').click();
    } else {
      document.getElementById('leSbPropsEmpty').style.display = '';
      document.getElementById('leSbPropsContent').style.display = 'none';
    }
  }

  function populateSidebarProps(el) {
    var sectionType = el.dataset.sectionType || 'unknown';
    document.getElementById('leSbPropsEmpty').style.display = 'none';
    var content = document.getElementById('leSbPropsContent');
    content.style.display = '';

    var labels = {
      hero: 'Héro (vidéo + titre)',
      reg_bar: "Compteur d'inscrits",
      partners: 'Bandeau Partenaires',
      timeline: 'Historique (Timeline)',
      news: 'Dernières actualités',
      custom: 'Bloc personnalisé'
    };
    document.getElementById('leSbSelType').textContent = labels[sectionType] || sectionType;

    // Restaure les rows section (au cas où ils ont été cachés par sélection d'un sous-élément)
    var widthRow = document.getElementById('leSbWidth').closest('.le-sb-row');
    var visRow   = document.getElementById('leSbVisCheck').closest('.le-sb-row');
    var sizeRow  = document.getElementById('leSbSizeRow');
    widthRow.style.display = (sectionType === 'hero') ? 'none' : '';
    visRow.style.display = (sectionType === 'hero') ? 'none' : '';
    sizeRow.style.display = 'none';

    if (sectionType !== 'hero') {
      var widthSel = document.getElementById('leSbWidth');
      widthSel.value = parseInt(el.dataset.width || '12', 10);

      var visCheck = document.getElementById('leSbVisCheck');
      var hidden = el.classList.contains('is-hidden');
      visCheck.checked = !hidden;
      document.getElementById('leSbVisLabel').textContent = hidden ? 'Masqué' : 'Visible';
    }

    var editWrap = document.getElementById('leSbActionEdit');
    if (sectionType === 'custom') {
      editWrap.style.display = '';
      document.getElementById('leSbBtnEdit').onclick = function() {
        var btn = el.querySelector('button[data-act="edit"]');
        if (btn) btn.click();
      };
    } else {
      editWrap.style.display = 'none';
    }

    var delBtn = document.getElementById('leSbBtnDelete');
    delBtn.disabled = (sectionType === 'hero');
    delBtn.style.display = (sectionType === 'hero') ? 'none' : '';
  }

  // Click sur une colonne/section pour sélectionner (hors data-edit-field qui ouvre le modal)
  document.addEventListener('click', function(e) {
    // Si clic sur élément éditable, on laisse le handler dédié gérer
    if (e.target.closest('[data-edit-field]')) return;
    var sec = e.target.closest('.le-col, .le-section-block');
    if (sec) {
      selectSection(sec);
    } else if (!e.target.closest('.le-sidebar')) {
      // Click hors d'une section et hors sidebar → désélectionne
      // selectSection(null);  // commenté pour éviter désélection involontaire
    }
  });

  // Width change depuis la sidebar
  document.getElementById('leSbWidth').addEventListener('change', function() {
    if (!currentSelected || currentSelected.classList.contains('le-section-hero')) return;
    var newW = parseInt(this.value, 10);
    var sel = currentSelected.querySelector('.le-width-select');
    if (sel) { sel.value = newW; sel.dispatchEvent(new Event('change', { bubbles: true })); }
  });

  // Toggle visibility via checkbox
  document.getElementById('leSbVisCheck').addEventListener('change', function() {
    if (!currentSelected || currentSelected.classList.contains('le-section-hero')) return;
    var btn = currentSelected.querySelector('button[data-act="toggle-vis"]');
    if (btn) btn.click();
    document.getElementById('leSbVisLabel').textContent = this.checked ? 'Visible' : 'Masqué';
  });

  // Slider de taille (pour élément éditable sélectionné dans le Hero)
  var currentEditEl = null; // élément data-edit-field actuellement sélectionné
  var sizeRange = document.getElementById('leSbSizeRange');
  var sizeVal = document.getElementById('leSbSizeVal');
  var sizeRow = document.getElementById('leSbSizeRow');
  var sizeSaveTimer = null;

  sizeRange.addEventListener('input', function() {
    if (!currentEditEl) return;
    var val = parseInt(this.value, 10);
    sizeVal.textContent = val + '%';
    var sizeKey = currentEditEl.dataset.editSize;
    // Application visuelle immédiate
    if (sizeKey === 'hero_timer_size') {
      currentEditEl.style.transform = 'scale(' + (val / 100) + ')';
      currentEditEl.style.transformOrigin = 'left bottom';
    } else {
      currentEditEl.style.fontSize = (val / 100) + 'em';
    }
    // Save AJAX (debounced)
    clearTimeout(sizeSaveTimer);
    sizeSaveTimer = setTimeout(function() { saveStyle(sizeKey, val); }, 400);
  });

  function saveStyle(sizeKey, val) {
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfTok = csrfMeta ? csrfMeta.getAttribute('content') : '';
    var fd = new FormData();
    fd.append('save_accueil_style', '1');
    fd.append('sizeKey', sizeKey);
    fd.append('sizeValue', val);
    fd.append('csrf_token', csrfTok);
    fetch('', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfTok, 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
      .then(function(r) { return r.json(); })
      .then(function(j) {
        if (j && j.ok) { /* OK silencieux */ }
      });
  }

  // Edit (custom uniquement) depuis la sidebar
  document.getElementById('leSbBtnEdit').addEventListener('click', function() {
    if (!currentSelected) return;
    var btn = currentSelected.querySelector('button[data-act="edit"]');
    if (btn) btn.click();
  });

  // Delete depuis la sidebar
  document.getElementById('leSbBtnDelete').addEventListener('click', function() {
    if (!currentSelected || currentSelected.classList.contains('le-section-hero')) return;
    var btn = currentSelected.querySelector('button[data-act="del-col"]');
    if (btn) { btn.click(); selectSection(null); }
  });

  // ── Bouton "+" entre rows : insère une nouvelle ligne (bloc custom vide) ──
  document.querySelectorAll('.le-add-row').forEach(function(addBtn) {
    addBtn.addEventListener('click', function(e) {
      var pos = parseInt(addBtn.dataset.addPosition || '0', 10);
      var newRow = makeRowEl([{ type: 'custom', width: 12, visible: true, id: newCustomId(), title: '', content: '' }]);
      var allRows = rowsEl.querySelectorAll('.le-row');
      if (pos >= allRows.length) {
        rowsEl.appendChild(newRow);
      } else {
        // Trouver le bon point d'insertion : avant la row à l'index pos
        var targetRow = allRows[pos];
        rowsEl.insertBefore(newRow, targetRow);
        // Aussi insérer un bouton + après la nouvelle row
      }
      initColSortable(newRow);
      refreshAllRowsInfo();
    });
  });

  // ── Modal universel : édition d'un champ (titre, sous-titre, image, vidéo) ──
  var fieldModalEl = document.getElementById('fieldEditModal');
  var fieldModal = null;
  function openFieldModal(field, kind, currentValue, label) {
    if (!fieldModal && typeof bootstrap !== 'undefined') fieldModal = new bootstrap.Modal(fieldModalEl);
    document.getElementById('fieldEditTitle').textContent = 'Modifier : ' + (label || field);
    document.getElementById('fieldEditFieldName').value = field;
    document.getElementById('fieldEditKind').value = kind;
    document.getElementById('fieldEditStatus').style.display = 'none';
    document.getElementById('fieldEditTinymceWrap').style.display = 'none';
    document.getElementById('fieldEditTextWrap').style.display = 'none';
    document.getElementById('fieldEditFileWrap').style.display = 'none';

    if (kind === 'tinymce') {
      document.getElementById('fieldEditTinymceWrap').style.display = '';
      var ed = tinymce.get('fieldEditTinymce');
      if (ed) ed.remove();
      tinymce.init({
        selector: '#fieldEditTinymce',
        <?= getTinyMceConfig($pdo) ?>
      }).then(function(eds) {
        if (eds && eds[0]) eds[0].setContent(currentValue || '');
      });
    } else if (kind === 'text') {
      document.getElementById('fieldEditTextWrap').style.display = '';
      document.getElementById('fieldEditText').value = currentValue || '';
    } else if (kind === 'image' || kind === 'video') {
      document.getElementById('fieldEditFileWrap').style.display = '';
      document.getElementById('fieldEditFile').value = '';
      var hint = document.getElementById('fieldEditFileHint');
      if (kind === 'image') {
        hint.textContent = 'Formats : JPG, PNG, GIF, WEBP. Max ~5 Mo.';
        document.getElementById('fieldEditFile').accept = 'image/jpeg,image/png,image/gif,image/webp';
      } else {
        hint.textContent = 'Formats : MP4, WebM, OGG. Max 50 Mo.';
        document.getElementById('fieldEditFile').accept = 'video/mp4,video/webm,video/ogg';
      }
      var current = document.getElementById('fieldEditFileCurrent');
      current.textContent = currentValue ? ('Fichier actuel : ' + currentValue) : 'Aucun fichier actuellement.';
    }
    if (fieldModal) fieldModal.show();
  }

  // ── Click simple sur élément éditable : SÉLECTIONNE + montre slider taille dans sidebar ──
  document.addEventListener('click', function(e) {
    var el = e.target.closest('[data-edit-field]');
    if (!el) return;
    e.preventDefault();
    e.stopPropagation();

    // Sélection visuelle
    document.querySelectorAll('.le-edit-selected').forEach(function(x){ x.classList.remove('le-edit-selected'); });
    el.classList.add('le-edit-selected');
    currentEditEl = el;

    // Mise à jour de la sidebar : titre, slider, bouton "modifier contenu"
    document.querySelector('.le-sb-tab[data-sb-tab="props"]').click();
    document.getElementById('leSbPropsEmpty').style.display = 'none';
    document.getElementById('leSbPropsContent').style.display = '';
    document.getElementById('leSbSelType').textContent = (el.dataset.editField || 'élément') + ' (Hero)';

    // Cache les options de section (largeur, visibilité) pour un élément éditable du Hero
    document.getElementById('leSbWidth').closest('.le-sb-row').style.display = 'none';
    document.getElementById('leSbVisCheck').closest('.le-sb-row').style.display = 'none';
    document.getElementById('leSbBtnDelete').style.display = 'none';

    // Affiche le slider de taille s'il y a un data-edit-size
    if (el.dataset.editSize) {
      sizeRow.style.display = '';
      var current = parseInt(el.dataset.editSizeCurrent || '100', 10);
      sizeRange.value = current;
      sizeVal.textContent = current + '%';
    } else {
      sizeRow.style.display = 'none';
    }

    // Bouton "Modifier le contenu" si le kind n'est pas 'size-only' (timer = size only)
    var editWrap = document.getElementById('leSbActionEdit');
    if (el.dataset.editKind && el.dataset.editKind !== 'size-only') {
      editWrap.style.display = '';
      var btn = document.getElementById('leSbBtnEdit');
      btn.onclick = function() { openFieldModalFromEl(el); };
    } else {
      editWrap.style.display = 'none';
    }
  });

  // ── Double-clic sur élément éditable : ouvre directement le modal d'édition ──
  document.addEventListener('dblclick', function(e) {
    var el = e.target.closest('[data-edit-field]');
    if (!el || el.dataset.editKind === 'size-only') return;
    e.preventDefault();
    e.stopPropagation();
    openFieldModalFromEl(el);
  });

  function openFieldModalFromEl(el) {
    var field = el.dataset.editField;
    var kind = el.dataset.editKind;
    var currentValue = '';
    if (kind === 'tinymce') currentValue = el.innerHTML;
    else if (kind === 'text') currentValue = el.textContent.trim();
    else if (kind === 'image') currentValue = el.getAttribute('src') ? el.getAttribute('src').split('/').pop() : '';
    else if (kind === 'video') {
      var src = el.querySelector('source');
      currentValue = src ? src.getAttribute('src').split('/').pop() : '';
    }
    openFieldModal(field, kind, currentValue, field);
  }

  // Save field — robuste : capture toute erreur silencieuse pour la rendre visible
  // (sinon le bouton apparaît inerte alors qu'une exception JS bloque le flux).
  // On marque le bouton avec data-save-attached='1' pour neutraliser le backup
  // (cf. <script> juste après la modal HTML) et éviter une double sauvegarde.
  var __btnSaveField = document.getElementById('btnSaveField');
  if (__btnSaveField) __btnSaveField.dataset.saveAttached = '1';
  if (__btnSaveField) __btnSaveField.addEventListener('click', function() {
    var btn = this;
    var status = document.getElementById('fieldEditStatus');
    function showError(msg) {
      btn.disabled = false;
      if (status) {
        status.style.display = 'block';
        status.style.color = '#dc2626';
        status.textContent = '✗ ' + msg;
      } else {
        alert(msg);
      }
    }
    try {
      var field = document.getElementById('fieldEditFieldName').value;
      var kind  = document.getElementById('fieldEditKind').value;
      btn.disabled = true;
      status.style.display = 'block';
      status.style.color = '#64748b';
      status.textContent = 'Enregistrement…';

      var csrfMeta = document.querySelector('meta[name="csrf-token"]');
      var csrfTok = csrfMeta ? csrfMeta.getAttribute('content') : '';

      var fd = new FormData();
      fd.append('save_accueil_field', '1');
      fd.append('field', field);
      fd.append('csrf_token', csrfTok);

      if (kind === 'tinymce') {
        // Fallback : si TinyMCE n'est pas (encore) initialisé ou a perdu son
        // instance, on lit le contenu brut du <textarea>. Sinon on perdrait
        // silencieusement la saisie utilisateur (apparence "rien ne se passe").
        var ed = (typeof tinymce !== 'undefined') ? tinymce.get('fieldEditTinymce') : null;
        var content = '';
        if (ed && typeof ed.getContent === 'function') {
          content = ed.getContent();
        } else {
          var ta = document.getElementById('fieldEditTinymce');
          content = ta ? (ta.value || '') : '';
        }
        fd.append('value', content);
      } else if (kind === 'text') {
        fd.append('value', document.getElementById('fieldEditText').value);
      } else if (kind === 'image' || kind === 'video') {
        var file = document.getElementById('fieldEditFile').files[0];
        if (!file) {
          showError('Sélectionnez un fichier.');
          return;
        }
        fd.append('file', file);
      } else {
        showError('Type de champ inconnu : ' + kind);
        return;
      }

      fetch('', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfTok, 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(function(r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(function(j) {
          btn.disabled = false;
          if (j && j.ok) {
            status.style.color = '#16a34a';
            status.textContent = '✓ Enregistré. Rechargement de l\'aperçu…';
            setTimeout(function() { location.reload(); }, 800);
          } else {
            showError((j && j.err) || 'Échec de l\'enregistrement.');
          }
        })
        .catch(function(err) {
          showError('Erreur réseau' + (err && err.message ? ' (' + err.message + ')' : '') + '.');
        });
    } catch (err) {
      console.error('[btnSaveField] exception:', err);
      showError('Erreur JS : ' + (err && err.message ? err.message : err));
    }
  });

})();

// ════════════════════════════════════════════════════════════════════════
// BOOTSTRAP de l'éditeur d'accueil (config injectée pour js/accueil-editor.js)
// ════════════════════════════════════════════════════════════════════════
window.AccueilEditor = window.AccueilEditor || {};
window.AccueilEditor.layoutData = (function() {
  var el = document.getElementById('ifeLayoutData');
  return el ? JSON.parse(el.textContent) : [];
})();
window.AccueilEditor.predefinedSections = <?= json_encode($predefinedSections, JSON_UNESCAPED_UNICODE) ?>;
window.AccueilEditor.allowedWidths = <?= json_encode($allowedWidths) ?>;
// État du brouillon au chargement : true si modifications non publiées en BDD
window.AccueilEditor.hasDraft = <?= hasAccueilDraft($data) ? 'true' : 'false' ?>;
// Styles courants (lecture brouillon avec fallback publié) — utilisé par les toggles
// d'options de section (ex: news.card_style)
window.AccueilEditor.accueilStyles = <?php
  $stylesForJs = [];
  $stylesRaw = $data['accueil_styles_draft'] ?? null;
  if (!$stylesRaw) $stylesRaw = $data['accueil_styles'] ?? null;
  if ($stylesRaw) {
    $decoded = json_decode($stylesRaw, true);
    if (is_array($decoded)) $stylesForJs = $decoded;
  }
  echo json_encode($stylesForJs);
?>;
// Point de départ courant (colonnes SQL dédiées) — lu par le widget de la section
// "Retrouver le départ" pour pré-remplir le champ adresse/coordonnées.
window.AccueilEditor.startPoint = {
  address: <?= json_encode((string)($data['start_point_address'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
  coords:  <?= json_encode((string)($data['start_point_coords']  ?? ''), JSON_UNESCAPED_UNICODE) ?>
};
// Helper pour init TinyMCE avec la config PHP partagée (appelé depuis l'externe)
window.AccueilEditor.initTinyMce = function(selector, content) {
  if (typeof tinymce === 'undefined') return;
  var id = selector.replace('#', '');
  var ed = tinymce.get(id);
  if (ed) ed.remove();
  return tinymce.init({
    selector: selector,
    <?= getTinyMceConfig($pdo) ?>
  }).then(function(eds) {
    if (eds && eds[0] && content !== undefined) eds[0].setContent(content || '');
    return eds;
  });
};
// IIFE de l'éditeur déplacée dans js/accueil-editor.js (inclus juste après)
// (les ~600 lignes ci-dessous sont supprimées et déplacées dans le fichier externe)
</script>
<!-- Éditeur visuel WYSIWYG (logique principale) -->
<script src="../js/accueil-editor.js?v=<?= @filemtime(__DIR__ . '/../js/accueil-editor.js') ?: time() ?>" nonce="<?= $GLOBALS['csp_nonce'] ?>"></script>
<?php endif; ?>
