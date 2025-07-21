<?php
require_once __DIR__ . '/../vendor/autoload.php';   // charge l’autoloader Composer

// Charge les variables d’environnement
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

    /*ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);*/
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