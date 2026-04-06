<?php
require_once __DIR__ . '/../vendor/autoload.php';   // charge l'autoloader Composer

// ── Garde d'installation ────────────────────────────────────
// Si .env est absent ou incomplet → rediriger vers install.php
$_envPath = __DIR__ . '/.env';
$_needsInstall = false;

if (!file_exists($_envPath)) {
    $_needsInstall = true;
} else {
    $_envRaw = file_get_contents($_envPath);
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'ENCRYPTION_KEY'] as $_k) {
        if (strpos($_envRaw, $_k . '=') === false) {
            $_needsInstall = true;
            break;
        }
    }
    unset($_envRaw, $_k);
}

if ($_needsInstall) {
    // Calculer le chemin relatif vers la racine du projet
    $_scriptDir = realpath(dirname($_SERVER['SCRIPT_FILENAME']));
    $_rootDir   = realpath(__DIR__ . '/..');
    $_relPath   = '';
    if ($_scriptDir !== $_rootDir) {
        $_depth   = substr_count(
            str_replace($_rootDir, '', $_scriptDir),
            DIRECTORY_SEPARATOR
        );
        $_relPath = str_repeat('../', $_depth);
    }
    header('Location: ' . $_relPath . 'install.php');
    exit;
}
unset($_envPath, $_needsInstall);
// ── Fin garde d'installation ────────────────────────────────

// Charge les variables d'environnement
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__); // si .env est à la racine de config
$dotenv->load();

// Les variables sont maintenant dans $_ENV ou getenv()
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=utf8mb4',
    $_ENV['DB_HOST'],
    $_ENV['DB_NAME']
);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

$pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], $options);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET time_zone = '+02:00'");

try {
    $stmt = $pdo->prepare(
        'SELECT debogage, maintenance_mode, maintenance_message
           FROM setting
          WHERE id = :id
          LIMIT 1');
    $stmt->execute(['id' => 1]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    // Fallback si les nouvelles colonnes n'existent pas encore (avant migration)
    $stmt = $pdo->prepare('SELECT debogage FROM setting WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => 1]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Ne jamais exposer les erreurs PHP côté client (API JSON, pages HTML)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

$GLOBALS['debogage'] = (int) ($data['debogage'] ?? 0);

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
ini_set('log_errors', 1);
ini_set('error_log', $logDir . '/php-error.log');

if($GLOBALS['debogage'] == 1){
    // Debug actif : tout loguer (notices, warnings, errors...)
    error_reporting(E_ALL);
} else {
    // Debug inactif : loguer uniquement les erreurs critiques
    error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR);
}

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
session_start();

/* Helpers ------------------------------------------------------------------ */
function currentRole()   { return $_SESSION['role'] ?? null; }
function currentUserId() { return $_SESSION['uid']  ?? null; }

function requireRole(array $roles)
{
    if (!isset($_SESSION['uid']) || !in_array(currentRole(), $roles, true)) {
        http_response_code(403);
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Vérifie le mode maintenance.
 * Appelé par les pages publiques — redirige vers la page de maintenance si activé.
 * Les admins connectés ne sont pas bloqués.
 */
function checkMaintenance()
{
    global $pdo;
    // Ne pas bloquer les admins connectés
    if (isset($_SESSION['uid']) && in_array(currentRole(), ['admin', 'user'], true)) {
        return;
    }
    try {
        $s = $pdo->query('SELECT maintenance_mode, maintenance_message FROM setting WHERE id = 1 LIMIT 1');
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['maintenance_mode'])) {
            $maintenance_message = $row['maintenance_message'] ?? '';
            http_response_code(503);
            include __DIR__ . '/../errors/maintenance.php';
            exit;
        }
    } catch (\Throwable $e) {
        // Si la colonne n'existe pas encore, ne pas bloquer
    }
}

function currentOrganisation(): ?string
{
    // A-t-on un utilisateur connecté ?
    if (!isset($_SESSION['uid'])) {
        return null;
    }

    // Petit cache pour ne pas refaire la requête si déjà appelée.
    static $org = null;
    if ($org !== null) {
        return $org;
    }

    // Accès au PDO défini dans le fichier de configuration
    global $pdo;        // ← important pour utiliser la connexion déjà créée

    $stmt = $pdo->prepare(
        'SELECT organisation
           FROM users
          WHERE id = :id
          LIMIT 1'
    );
    $stmt->execute(['id' => $_SESSION['uid']]);
    $org = $stmt->fetchColumn();   // renvoie false si aucune ligne

    // Normalise le retour : null si rien trouvé ou chaîne vide
    return $org !== false && $org !== '' ? $org : null;
}

function getAssoConnectCodes(int $id = 1): array
{
    global $pdo;   
    $stmt = $pdo->prepare(
        'SELECT assoconnect_js,
                assoconnect_iframe
           FROM customize
          WHERE id = :id
          LIMIT 1'
    );
    $stmt->execute(['id' => $id]);

    // Retourne ['assoconnect_js' => '…', 'assoconnect_iframe' => '…']
    // ou ['assoconnect_js' => null, …] si la ligne est absente
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'assoconnect_js'      => null,
        'assoconnect_iframe'  => null,
    ];
}

/**
 * Renvoie l'URL absolue vers oauth2callback.php,
 * quel que soit le dossier racine du site.
 */
function oauth2_callback_url(): string
{
    // 🔒 [SEC-01] getAppBaseUrl() au lieu de HTTP_HOST brut (CWE-644)
    $baseUrl = getAppBaseUrl();
    $projectRoot = realpath(__DIR__ . '/..');
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    if ($projectRoot === $docRoot || $projectRoot === false || $docRoot === false) {
        $baseDir = '';
    } else {
        $baseDir = str_replace('\\', '/', substr($projectRoot, strlen($docRoot)));
    }
    return $baseUrl . $baseDir . '/oauth2callback.php';
}

/**
 * Génère un mot de passe temporaire conforme à la politique de sécurité.
 * 14+ caractères, au moins 1 majuscule, 1 chiffre, 1 caractère spécial.
 */
function generateTemporaryPassword(int $length = 16): string
{
    $upper   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lower   = 'abcdefghijklmnopqrstuvwxyz';
    $digits  = '0123456789';
    $special = '!@#$%^&*()-_=+[]{}|;:,.<>?';

    // Garantir au moins un de chaque type requis
    $password  = $upper[random_int(0, strlen($upper) - 1)];
    $password .= $lower[random_int(0, strlen($lower) - 1)];
    $password .= $digits[random_int(0, strlen($digits) - 1)];
    $password .= $special[random_int(0, strlen($special) - 1)];

    // Remplir le reste avec des caractères aléatoires de tous les types
    $all = $upper . $lower . $digits . $special;
    for ($i = 4; $i < $length; $i++) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }

    // Mélanger pour randomiser les positions
    return str_shuffle($password);
}

/**
 * Valide un mot de passe selon la politique de sécurité.
 * Retourne un tableau d'erreurs (vide si valide).
 */
function validatePasswordPolicy(string $password): array
{
    $errors = [];
    if (strlen($password) < 14)                    $errors[] = "Le mot de passe doit contenir au moins 14 caractères.";
    if (!preg_match('/[A-Z]/', $password))          $errors[] = "Le mot de passe doit contenir au moins une majuscule.";
    if (!preg_match('/[0-9]/', $password))          $errors[] = "Le mot de passe doit contenir au moins un chiffre.";
    if (!preg_match('/[^a-zA-Z0-9]/', $password))   $errors[] = "Le mot de passe doit contenir au moins un caractère spécial.";
    return $errors;
}

/* ── Chiffrement AES-256-GCM (authentifié) ──────────────────────────────── */
define('CIPHER_ALGO', 'aes-256-gcm');
define('CIPHER_KEY', base64_decode($_ENV['ENCRYPTION_KEY']));
define('PII_FIELDS', ['nom', 'prenom', 'tel', 'email', 'naissance', 'ville', 'entreprise']);

function encrypt(?string $data): ?string {
    if ($data === null || $data === '') return $data;
    $iv  = random_bytes(12); // 96 bits pour GCM
    $tag = '';
    $encrypted = openssl_encrypt($data, CIPHER_ALGO, CIPHER_KEY, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return base64_encode($iv . $tag . $encrypted);
}

function decrypt(?string $data): ?string {
    if ($data === null || $data === '') return $data;
    $raw = base64_decode($data, true);
    if ($raw === false) return $data; // Donnée non chiffrée, retourner telle quelle
    if (strlen($raw) < 28) return $data; // Trop court pour être chiffré (12 IV + 16 tag)
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $encrypted = substr($raw, 28);
    $result = openssl_decrypt($encrypted, CIPHER_ALGO, CIPHER_KEY, OPENSSL_RAW_DATA, $iv, $tag);
    return $result !== false ? $result : $data; // Fallback si déchiffrement échoue (donnée non chiffrée)
}

function encryptFields(array &$data): void {
    foreach (PII_FIELDS as $f) {
        if (array_key_exists($f, $data)) {
            $data[$f] = encrypt($data[$f]);
        }
    }
}

function decryptRow(array $row): array {
    static $allEncrypted = null;
    if ($allEncrypted === null) {
        $allEncrypted = PII_FIELDS;
        // Ajouter les champs custom marqués encrypted
        try {
            global $pdo;
            if ($pdo) {
                $stmt = $pdo->query("SELECT bdd_column FROM forms WHERE encrypted = 1 AND bdd_column IS NOT NULL");
                $extra = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $allEncrypted = array_unique(array_merge($allEncrypted, $extra));
            }
        } catch (\Throwable $e) { /* ignore */ }
    }
    foreach ($allEncrypted as $f) {
        if (array_key_exists($f, $row)) {
            $row[$f] = decrypt($row[$f]);
        }
    }
    return $row;
}

function decryptRows(array $rows): array {
    return array_map('decryptRow', $rows);
}

/**
 * Ajoute un toast à afficher au prochain chargement de page.
 */
function addToast(string $type, string $msg, int $delay = 4000): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $_SESSION['toasts'][] = ['msg' => $msg, 'type' => $type, 'delay' => $delay];
}

/**
 * Détecte si la requête est AJAX (XMLHttpRequest).
 */
function isAjaxRequest(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Décode un champ HTML encodé en Base64 (contournement WAF).
 */
function decodeHtmlField(string $raw): string {
    $decoded = base64_decode($raw, true);
    if ($decoded !== false && mb_detect_encoding($decoded, 'UTF-8', true)) {
        return $decoded;
    }
    return $raw;
}

/**
 * Upload sécurisé d'une image. Retourne le nom du fichier ou null en cas d'échec.
 */
function uploadImage(array $file, string $uploadDir, string $prefix = 'img_', int $maxSize = 5242880): ?string {
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $allowedExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if ($file['size'] > $maxSize) return null;
    if (!in_array($ext, $allowedExts, true) || !in_array($mime, $allowedMimes, true)) return null;
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
    $safeName = uniqid($prefix, true) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) return null;
    return $safeName;
}

/**
 * Scanne le dossier fonts/ et retourne les fonts custom.
 * Retourne un tableau ['NomFont' => 'chemin/fichier.ttf', ...]
 */
function getCustomFonts(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $dir = __DIR__ . '/../fonts';
    if (!is_dir($dir)) return $cache;
    $files = glob($dir . '/*.{ttf,otf,woff,woff2}', GLOB_BRACE);
    foreach ($files as $file) {
        $basename = pathinfo($file, PATHINFO_FILENAME);
        // Convertir CamelCase/underscore en nom lisible : "BrittanySignature" → "Brittany Signature"
        $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $basename);
        $name = str_replace(['_', '-'], ' ', $name);
        $name = trim($name);
        $cache[$name] = 'fonts/' . basename($file);
    }
    return $cache;
}

/**
 * Retourne le font-stack du thème actif pour le content_style TinyMCE.
 */
function getThemeFontStack(PDO $pdo): string {
    try {
        $font = $pdo->query("SELECT theme_font_family FROM setting WHERE id = 1 LIMIT 1")->fetchColumn() ?: 'Inter';
    } catch (PDOException $e) {
        $font = 'Inter';
    }
    if ($font === 'system-ui') return 'system-ui, -apple-system, sans-serif';
    // Guillemets doubles pour ne pas casser les apostrophes JS du content_style
    return '"' . addslashes($font) . '", sans-serif';
}

/**
 * Retourne l'URL Google Fonts pour charger toutes les polices disponibles dans TinyMCE.
 */
function getTinyMceGoogleFontsUrl(): string {
    $fonts = ['Inter','Poppins','Roboto','Open+Sans','Montserrat','Lato','Nunito','Raleway',
              'Source+Sans+3','Work+Sans','DM+Sans','Outfit','Plus+Jakarta+Sans','Manrope',
              'Figtree','Quicksand','Cabin','Rubik','Karla','Playfair+Display','Bebas+Neue',
              'Oswald','Dancing+Script','Lobster'];
    $families = array_map(function($f) { return 'family=' . $f . ':wght@300;400;500;600;700;800;900'; }, $fonts);
    return 'https://fonts.googleapis.com/css2?' . implode('&', $families) . '&display=swap';
}

/**
 * Retourne la config JS commune pour tinymce.init() — à injecter directement dans le JS.
 * $overrides permet de surcharger des options (ex: height, selector, toolbar).
 */
function getTinyMceConfig(PDO $pdo, array $overrides = []): string {
    $fontStyles = getTinyMceFontStyles();
    $fontStack = getThemeFontStack($pdo);
    $fontFormats = getTinyMceFontFormats();
    $googleFontsUrl = getTinyMceGoogleFontsUrl();
    $csrfToken = function_exists('csrf_token') ? csrf_token() : '';

    $defaults = [
        'license_key' => 'gpl',
        'language' => 'fr_FR',
        'plugins' => 'anchor autolink charmap codesample emoticons image link lists media searchreplace table visualblocks wordcount code',
        'toolbar' => 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat | code',
        'height' => 400,
        'menubar' => false,
        'branding' => false,
        'content_style' => $fontStyles . 'body { font-family: ' . $fontStack . '; font-size: 14px; }',
        'content_css' => $googleFontsUrl,
        'font_family_formats' => $fontFormats,
        'automatic_uploads' => true,
        'images_reuse_filename' => true,
        'file_picker_types' => 'file image',
        'toolbar_mode' => 'sliding',
    ];

    $config = array_merge($defaults, $overrides);

    // Construire le JS
    // Options JS complexes (objets/tableaux) qui ne passent pas par le sérialiseur string
    $jsExtras = [];

    // valid_styles — restreint les propriétés CSS autorisées par type d'élément
    $jsExtras[] = "valid_styles: {
                '*': 'text-align,line-height,color,background-color,font-size,font-weight,font-style,font-family,text-decoration,padding,padding-left,padding-right,padding-top,padding-bottom,margin,margin-left,margin-right,margin-top,margin-bottom',
                'img': 'width,height,max-width,float,margin,margin-left,margin-right,margin-top,margin-bottom,display',
                'table': 'width,height,border-collapse,border-spacing'
            }";

    // color_map — palette de couleurs personnalisée
    $jsExtras[] = "color_map: [
                '000000','Noir','993300','Marron fonce','333300','Vert fonce','003300','Vert sombre',
                '003366','Bleu marine','000080','Bleu','333399','Indigo','333333','Gris tres fonce',
                '800000','Marron','FF6600','Orange','808000','Olive','008000','Vert',
                '008080','Sarcelle','0000FF','Bleu vif','666699','Gris bleu','808080','Gris',
                'FF0000','Rouge','FF9900','Ambre','99CC00','Vert jaune','339966','Vert mer',
                '33CCCC','Turquoise','3366FF','Bleu royal','800080','Violet','999999','Gris moyen',
                'FF00FF','Magenta','FFCC00','Or','FFFF00','Jaune','00FF00','Lime',
                '00FFFF','Cyan','00CCFF','Bleu ciel','993366','Rouge brun','FFFFFF','Blanc',
                'FF99CC','Rose','FFCC99','Peche','FFFF99','Jaune clair','CCFFCC','Vert clair',
                'CCFFFF','Cyan clair','99CCFF','Bleu clair','CC99FF','Prune'
            ]";

    // extended_valid_elements — whitelist HTML sécurisée
    $jsExtras[] = "extended_valid_elements: 'a[href|target|title|class|rel],'
              + 'img[src|alt|title|width|height|class|loading|style],'
              + 'p[class|style],span[class|style],div[class|style],'
              + 'table[class|border|cellpadding|cellspacing|style],thead,tbody,tfoot,'
              + 'tr,td[class|style|colspan|rowspan],th[class|style|colspan|rowspan],'
              + 'ul[class],ol[class|type|start],li[class],'
              + 'blockquote[class|cite],pre[class],code,strong/b,em/i,u,s,sub,sup,br,'
              + 'hr[class],h1[class|style],h2[class|style],h3[class|style],'
              + 'h4[class|style],h5[class|style],h6[class|style],'
              + 'figure[class],figcaption,video[src|controls|width|height|class],'
              + 'audio[src|controls|class],source[src|type]'";

    // invalid_elements — éléments HTML bloqués (sécurité XSS)
    $jsExtras[] = "invalid_elements: 'script,iframe,object,embed,form,input,textarea,select,button,applet,meta,link,base'";
    $parts = [];
    foreach ($config as $key => $val) {
        $jsKey = $key;
        if (is_bool($val)) {
            $parts[] = "$jsKey: " . ($val ? 'true' : 'false');
        } elseif (is_int($val)) {
            $parts[] = "$jsKey: $val";
        } else {
            // Escape pour JS single-quote string
            $escaped = str_replace("'", "\\'", (string)$val);
            $parts[] = "$jsKey: '$escaped'";
        }
    }

    // Ajouter les handlers JS (non sérialisables)
    $parts[] = "images_upload_handler: function(blobInfo) {
        return new Promise(function(resolve, reject) {
            var formData = new FormData();
            formData.append('file', blobInfo.blob(), blobInfo.filename());
            formData.append('csrf_token', '$csrfToken');
            fetch('../inc/tinymce-upload.php', { method: 'POST', body: formData })
                .then(function(r) { if (!r.ok) throw new Error('Upload failed'); return r.json(); })
                .then(function(data) { if (data.location) resolve(data.location); else reject(data.error || 'Upload error'); })
                .catch(function(e) { reject(e.message); });
        });
    }";
    $parts[] = "file_picker_callback: function(callback, value, meta) {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = meta.filetype === 'image' ? 'image/*' : 'image/*,.pdf';
        input.addEventListener('change', function() {
            var file = input.files[0];
            if (!file) return;
            var formData = new FormData();
            formData.append('file', file);
            formData.append('csrf_token', '$csrfToken');
            fetch('../inc/tinymce-upload.php', { method: 'POST', body: formData })
                .then(function(r) { if (!r.ok) throw new Error('Upload failed'); return r.json(); })
                .then(function(data) { if (data.location) { var n = data.title || file.name.replace(/\\.[^.]+$/,''); callback(data.location, { title: n, text: n + '.' + file.name.split('.').pop() }); } })
                .catch(function(e) { alert('Erreur upload: ' + e.message); });
        });
        input.click();
    }";

    return implode(",\n            ", array_merge($parts, $jsExtras));
}

/**
 * Retourne le CSS content_style pour TinyMCE avec les @font-face des fonts custom.
 */
function getTinyMceFontStyles(): string {
    $custom = getCustomFonts();
    if (empty($custom)) return '';
    $formatMap = ['otf' => 'opentype', 'woff2' => 'woff2', 'woff' => 'woff', 'ttf' => 'truetype'];
    $css = '';
    foreach ($custom as $name => $path) {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $format = $formatMap[$ext] ?? 'truetype';
        // Utiliser des guillemets doubles dans le CSS pour ne pas casser les apostrophes JS
        $css .= '@font-face { font-family: "' . addslashes($name) . '"; src: url("../' . addslashes($path) . '") format("' . $format . '"); font-weight: normal; font-style: normal; } ';
    }
    return $css;
}

/**
 * Retourne la chaîne font_family_formats pour TinyMCE incluant les fonts custom.
 */
function getTinyMceFontFormats(): string {
    $fonts = [
        'System' => 'system-ui,sans-serif',
        'Inter' => "'Inter',sans-serif",
        'Poppins' => "'Poppins',sans-serif",
        'Roboto' => "'Roboto',sans-serif",
        'Open Sans' => "'Open Sans',sans-serif",
        'Montserrat' => "'Montserrat',sans-serif",
        'Lato' => "'Lato',sans-serif",
        'Nunito' => "'Nunito',sans-serif",
        'Raleway' => "'Raleway',sans-serif",
        'Source Sans 3' => "'Source Sans 3',sans-serif",
        'Work Sans' => "'Work Sans',sans-serif",
        'DM Sans' => "'DM Sans',sans-serif",
        'Outfit' => "'Outfit',sans-serif",
        'Plus Jakarta Sans' => "'Plus Jakarta Sans',sans-serif",
        'Manrope' => "'Manrope',sans-serif",
        'Figtree' => "'Figtree',sans-serif",
        'Quicksand' => "'Quicksand',sans-serif",
        'Cabin' => "'Cabin',sans-serif",
        'Rubik' => "'Rubik',sans-serif",
        'Karla' => "'Karla',sans-serif",
        'Georgia' => 'Georgia,serif',
        'Playfair Display' => "'Playfair Display',serif",
        'Bebas Neue' => "'Bebas Neue',sans-serif",
        'Oswald' => "'Oswald',sans-serif",
        'Dancing Script' => "'Dancing Script',cursive",
        'Lobster' => "'Lobster',cursive",
        'Impact' => 'Impact,sans-serif',
    ];
    // Ajouter les fonts custom
    $custom = getCustomFonts();
    foreach ($custom as $name => $path) {
        if (!isset($fonts[$name])) {
            $fonts[$name] = "'" . $name . "',sans-serif";
        }
    }
    $parts = [];
    foreach ($fonts as $label => $stack) {
        $parts[] = $label . '=' . $stack;
    }
    return implode('; ', $parts);
}

/**
 * Vérifie si une notification est activée par son type.
 */
function isNotifyEnabled(PDO $pdo, string $type): bool {
    static $toggles = null;
    if ($toggles === null) {
        $stmt = $pdo->prepare('SELECT notify_toggles FROM setting WHERE id = 1 LIMIT 1');
        $stmt->execute();
        $raw = $stmt->fetchColumn();
        $toggles = $raw ? json_decode($raw, true) : [];
        $toggles += ['mention' => true, 'partner' => true, 'ip_ban' => true, 'twofa' => true, 'lock' => true];
    }
    return !empty($toggles[$type]);
}

/**
 * Retourne les destinataires des notifications admin.
 * Si des destinataires sont configurés dans les settings, les utilise.
 * Sinon, retourne tous les admins actifs.
 */
function getNotifyRecipients(PDO $pdo): array {
    $stmt = $pdo->prepare('SELECT notify_recipients FROM setting WHERE id = 1 LIMIT 1');
    $stmt->execute();
    $raw = $stmt->fetchColumn();
    if ($raw) {
        $list = json_decode($raw, true);
        if (!empty($list)) return $list;
    }
    // Fallback : tous les admins actifs
    return $pdo->query("SELECT email FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
}

// 🔒 [SEC-01] URL de base fiable — empêche le Host header injection (CWE-644)
function getAppBaseUrl(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $host)) {
        error_log('[SECURITY] Rejected malformed Host header: ' . substr($host, 0, 100));
        $host = 'localhost';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host;
}

// 🔒 [SEC-08] Assainissement HTML via DOMDocument — whitelist tags + attributs (CWE-79)
function sanitizeHtml(?string $html): string {
    if ($html === null || $html === '') return '';

    // Tags autorisés et leurs attributs autorisés
    $allowedTags = [
        'p','br','strong','b','em','i','u','s',
        'h1','h2','h3','h4','h5','h6',
        'ul','ol','li','a','img',
        'table','thead','tbody','tfoot','tr','td','th',
        'blockquote','pre','code','div','span','hr',
        'sub','sup','figure','figcaption',
    ];
    $allowedAttrs = [
        'a'     => ['href', 'title', 'target', 'rel'],
        'img'   => ['src', 'alt', 'width', 'height', 'loading', 'style'],
        'td'    => ['colspan', 'rowspan', 'style'],
        'th'    => ['colspan', 'rowspan', 'style'],
        'ol'    => ['start', 'type'],
        'table' => ['border'],
        'p'     => ['style'],
        'div'   => ['style'],
        'span'  => ['style'],
        'h1'    => ['style'],
        'h2'    => ['style'],
        'h3'    => ['style'],
        'h4'    => ['style'],
        'h5'    => ['style'],
        'h6'    => ['style'],
        'li'    => ['style'],
        'blockquote' => ['style'],
        'figure'     => ['style'],
        'figcaption' => ['style'],
    ];
    // Schémes autorisés pour href/src
    $safeSchemes = ['http', 'https', 'mailto'];

    // Parser via DOMDocument
    libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML(
        '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
    );
    libxml_clear_errors();

    $body = $doc->getElementsByTagName('body')->item(0);
    if (!$body) return '';

    // Parcourir récursivement et nettoyer
    _sanitizeNode($body, $allowedTags, $allowedAttrs, $safeSchemes, $doc);

    // Extraire le contenu du <body>
    $result = '';
    foreach ($body->childNodes as $child) {
        $result .= $doc->saveHTML($child);
    }
    return $result;
}

/** @internal Recursively sanitize DOM nodes — whitelist approach */
function _sanitizeNode(DOMNode $node, array $allowedTags, array $allowedAttrs, array $safeSchemes, DOMDocument $doc): void {
    $toRemove = [];
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($child->nodeName);
            if (!in_array($tag, $allowedTags, true)) {
                // Tag interdit : remplacer par ses enfants (conserver le texte)
                $toRemove[] = $child;
            } else {
                // Tag autorisé : nettoyer ses attributs
                $attrsToRemove = [];
                foreach ($child->attributes as $attr) {
                    $attrName = strtolower($attr->nodeName);
                    // Bloquer tous les event handlers (on*)
                    if (str_starts_with($attrName, 'on')) {
                        $attrsToRemove[] = $attr->nodeName;
                        continue;
                    }
                    // Attribut pas dans la whitelist de ce tag
                    $tagAllowed = $allowedAttrs[$tag] ?? [];
                    if (!in_array($attrName, $tagAllowed, true)) {
                        $attrsToRemove[] = $attr->nodeName;
                        continue;
                    }
                    // Valider les URLs (href, src)
                    if (in_array($attrName, ['href', 'src'], true)) {
                        $val = trim($attr->nodeValue);
                        $scheme = strtolower(parse_url($val, PHP_URL_SCHEME) ?? '');
                        // Autoriser data:image/* uniquement sur les <img> (ancien contenu TinyMCE)
                        $isDataImage = ($tag === 'img' && $scheme === 'data'
                            && preg_match('#^data:image/(jpeg|png|gif|webp);base64,#i', $val));
                        if ($scheme !== '' && !$isDataImage && !in_array($scheme, $safeSchemes, true)) {
                            $attrsToRemove[] = $attr->nodeName;
                        }
                    }
                }
                foreach ($attrsToRemove as $aName) {
                    $child->removeAttribute($aName);
                }
                // Sanitiser le style (n'autoriser que certaines propriétés CSS sûres)
                if ($child->hasAttribute('style')) {
                    $rawStyle = $child->getAttribute('style');
                    $safeProps = [];
                    foreach (explode(';', $rawStyle) as $decl) {
                        $decl = trim($decl);
                        if ($decl === '') continue;
                        // width, height, max-width (images et autres)
                        if (preg_match('/^(width|height|max-width)\s*:\s*[\d.]+(px|%|em|rem|auto)\s*$/i', $decl)) {
                            $safeProps[] = $decl;
                        }
                        // text-align (center, left, right, justify)
                        if (preg_match('/^text-align\s*:\s*(left|center|right|justify)\s*$/i', $decl)) {
                            $safeProps[] = $decl;
                        }
                        // line-height
                        if (preg_match('/^line-height\s*:\s*[\d.]+(px|%|em|rem|)?\s*$/i', $decl)) {
                            $safeProps[] = $decl;
                        }
                        // margin (avec auto pour centrer les images)
                        if (preg_match('/^(margin|margin-left|margin-right|margin-top|margin-bottom)\s*:\s*([\d.]+(px|%|em|rem)|auto)(\s+([\d.]+(px|%|em|rem)|auto))*\s*$/i', $decl)) {
                            $safeProps[] = $decl;
                        }
                        // display: block/inline/inline-block (pour centrer les images)
                        if (preg_match('/^display\s*:\s*(block|inline|inline-block)\s*$/i', $decl)) {
                            $safeProps[] = $decl;
                        }
                        // float (left, right, none)
                        if (preg_match('/^float\s*:\s*(left|right|none)\s*$/i', $decl)) {
                            $safeProps[] = $decl;
                        }
                        // font-family (noms de polices entre guillemets ou sans)
                        if (preg_match('/^font-family\s*:/i', $decl) && !preg_match('/expression|url|javascript/i', $decl)) {
                            $safeProps[] = $decl;
                        }
                        // font-size
                        if (preg_match('/^font-size\s*:\s*[\d.]+(px|pt|em|rem|%)\s*$/i', $decl)) {
                            $safeProps[] = $decl;
                        }
                        // font-weight
                        if (preg_match('/^font-weight\s*:\s*(normal|bold|bolder|lighter|\d{3})\s*$/i', $decl)) {
                            $safeProps[] = $decl;
                        }
                        // font-style
                        if (preg_match('/^font-style\s*:\s*(normal|italic|oblique)\s*$/i', $decl)) {
                            $safeProps[] = $decl;
                        }
                        // color
                        if (preg_match('/^color\s*:\s*(#[0-9a-fA-F]{3,8}|rgb[a]?\([^)]+\)|[a-z]+)\s*$/i', $decl)) {
                            $safeProps[] = $decl;
                        }
                        // background-color
                        if (preg_match('/^background-color\s*:\s*(#[0-9a-fA-F]{3,8}|rgb[a]?\([^)]+\)|[a-z]+|transparent)\s*$/i', $decl)) {
                            $safeProps[] = $decl;
                        }
                        // text-decoration
                        if (preg_match('/^text-decoration\s*:\s*(none|underline|overline|line-through)\s*$/i', $decl)) {
                            $safeProps[] = $decl;
                        }
                        // padding
                        if (preg_match('/^(padding|padding-left|padding-right|padding-top|padding-bottom)\s*:\s*[\d.]+(px|%|em|rem)(\s+[\d.]+(px|%|em|rem))*\s*$/i', $decl)) {
                            $safeProps[] = $decl;
                        }
                    }
                    if (!empty($safeProps)) {
                        $child->setAttribute('style', implode('; ', $safeProps));
                    } else {
                        $child->removeAttribute('style');
                    }
                }
                // Supprimer les <a> vides (aucun texte ni enfant visible)
                if ($tag === 'a' && trim($child->textContent) === '' && $child->getElementsByTagName('img')->length === 0) {
                    $toRemove[] = $child;
                    continue;
                }
                // Forcer rel=noopener sur les liens avec target
                if ($tag === 'a' && $child->hasAttribute('target')) {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
                // Récursion sur les enfants
                _sanitizeNode($child, $allowedTags, $allowedAttrs, $safeSchemes, $doc);
            }
        }
    }
    // Remplacer les tags interdits par leurs enfants
    foreach ($toRemove as $badNode) {
        $fragment = $doc->createDocumentFragment();
        while ($badNode->firstChild) {
            $fragment->appendChild($badNode->firstChild);
        }
        $badNode->parentNode->replaceChild($fragment, $badNode);
    }
    // Relancer sur les nœuds déplacés
    if (!empty($toRemove)) {
        _sanitizeNode($node, $allowedTags, $allowedAttrs, $safeSchemes, $doc);
    }
}

// ── CSP nonce par requête ─────────────────────────────────────────────────────
// Généré ici pour que TOUS les templates qui require config.php l'aient.
// Le header CSP est émis ici (pas dans .htaccess) pour embarquer la valeur dynamique.
// 🔒 [SEC-10] style-src 'unsafe-inline' conservé — requis par les attributs style="" du site
// 🔒 [SEC-15] img-src https: requis pour les images externes du contenu riche
// 🔒 [SEC-17] frame-src *.assoconnect.com — idéalement spécifier le sous-domaine exact
$GLOBALS['csp_nonce'] = base64_encode(random_bytes(16));
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'nonce-" . $GLOBALS['csp_nonce'] . "' " .
        "https://cdn.jsdelivr.net https://code.jquery.com https://cdn.tiny.cloud https://cdn.datatables.net https://*.assoconnect.com; " .
    "style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdn.datatables.net 'unsafe-inline'; " .
    "img-src 'self' data: blob: https:; " .
    "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com https://cdn.datatables.net; " .
    "frame-src 'self' https://*.assoconnect.com; " .
    "connect-src 'self' https://*.assoconnect.com https://cdn.jsdelivr.net; " .
    "object-src 'none'; " .
    "base-uri 'self';"
);
