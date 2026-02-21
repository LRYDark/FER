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

if($data['debogage'] == 1){
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__.'/php-error.log');
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

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
 * Renvoie l’URL absolue vers oauth2callback.php,
 * quel que soit le dossier racine du site.
 */
function oauth2_callback_url(): string
{
    // 1) Schéma : http ou https ?
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    // 2) Domaine + éventuel port
    $host = $_SERVER['HTTP_HOST'];          // ex. jr.zerobug-57.fr ou jr.zerobug-57.fr:8443

    // 3) Dossier qui contient le script courant
    //    SCRIPT_NAME  = /FER/inc/setting.php   (si site dans /FER)
    //    SCRIPT_NAME  = /inc/setting.php       (si /FER devient DocumentRoot)
    $baseDir = dirname(dirname($_SERVER['SCRIPT_NAME']));  // remonte de 2 niveaux

    // 4) Normalisation : si on est déjà à la racine, $baseDir vaudra '/'
    if ($baseDir === DIRECTORY_SEPARATOR) {
        $baseDir = '';
    }

    // 5) Construction de l’URL cible
    return $scheme . '://' . $host . $baseDir . '/oauth2callback.php';
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

function encrypt($data) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $_ENV['ENCRYPTION_KEY'], 0, $iv);
    return base64_encode($iv . $encrypted);
}

function decrypt($data) {
    $data = base64_decode($data);
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($data, 0, $ivLength);
    $encrypted = substr($data, $ivLength);
    return openssl_decrypt($encrypted, 'aes-256-cbc', $_ENV['ENCRYPTION_KEY'], 0, $iv);
}