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

$stmt = $pdo->prepare(
    'SELECT debogage
       FROM setting
      WHERE id = :id
      LIMIT 1');
$stmt->execute(['id' => 1]);
$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Ne jamais exposer les erreurs PHP côté client (API JSON, pages HTML)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

if($data['debogage'] == 1){
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__.'/logs/php-error.log');
    error_reporting(E_ALL);
} else {
    error_reporting(0);
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
    foreach (PII_FIELDS as $f) {
        if (array_key_exists($f, $row)) {
            $row[$f] = decrypt($row[$f]);
        }
    }
    return $row;
}

function decryptRows(array $rows): array {
    return array_map('decryptRow', $rows);
}

// 🔒 [SEC-01] URL de base fiable — empêche le Host header injection (CWE-644)
function getAppBaseUrl(): string {
    if (!empty($_ENV['APP_URL'])) {
        return rtrim($_ENV['APP_URL'], '/');
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $host)) {
        error_log('[SECURITY] Rejected malformed Host header: ' . substr($host, 0, 100));
        $host = 'localhost';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host;
}

// 🔒 [SEC-08] Assainissement HTML pour contenu riche (CWE-79)
function sanitizeHtml(?string $html): string {
    if ($html === null || $html === '') return '';
    $allowed = '<p><br><strong><b><em><i><u><s><h1><h2><h3><h4><h5><h6>'
             . '<ul><ol><li><a><img><table><thead><tbody><tfoot><tr><td><th>'
             . '<blockquote><pre><code><div><span><hr><sub><sup><figure><figcaption>';
    $html = strip_tags($html, $allowed);
    $html = preg_replace('/\bon\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html);
    $html = preg_replace('/(href|src|action|formaction)\s*=\s*["\']?\s*(?:javascript|vbscript|data)\s*:/i', '$1="', $html);
    return $html;
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
        "https://cdn.jsdelivr.net https://code.jquery.com https://cdn.tiny.cloud https://cdn.datatables.net; " .
    "style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdn.datatables.net 'unsafe-inline'; " .
    "img-src 'self' data: blob: https:; " .
    "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com https://cdn.datatables.net; " .
    "frame-src 'self' https://*.assoconnect.com; " .
    "connect-src 'self'; " .
    "object-src 'none'; " .
    "base-uri 'self';"
);
