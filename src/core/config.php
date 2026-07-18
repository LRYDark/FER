<?php
require_once __DIR__ . '/../../vendor/autoload.php';   // charge l'autoloader Composer
require_once __DIR__ . '/debug.php';                // mode debug (barre GLPI + profileur SQL + handlers)
require_once __DIR__ . '/secure.php';               // configuration chiffrée (config.enc + master.key)

// ── Chargement de la configuration chiffrée + garde d'installation ──────────
// v2.0.0 : les secrets vivent dans config/config.enc (chiffré avec
// config/master.key), plus dans un .env en clair. Si un ancien .env complet
// est présent et que config.enc n'existe pas encore, on migre automatiquement
// (le .env est conservé ; update.php le supprimera après vérification).
$_secureCfg = null;

if (FerSecureConfig::exists()) {
    try {
        $_secureCfg = FerSecureConfig::load();
    } catch (\Throwable $_e) {
        // config.enc présent mais indéchiffrable : NE PAS rediriger vers
        // install.php (boucle / risque d'écrasement). Message explicite.
        http_response_code(500);
        error_log('[FER config] ' . $_e->getMessage());
        die('Erreur de configuration : config/config.enc est présent mais ne peut pas être déchiffré. '
          . 'Vérifiez que config/master.key est bien le fichier de clé d\'origine (restaurez-le depuis votre sauvegarde).');
    }
} else {
    // Pas encore de config.enc → tenter la migration depuis l'ancien .env
    try {
        $_secureCfg = FerSecureConfig::migrateFromEnv(dirname(__DIR__, 2) . '/config/.env');
    } catch (\Throwable $_e) {
        error_log('[FER config] Migration .env -> config.enc échouée : ' . $_e->getMessage());
        $_secureCfg = null;
    }
}

if ($_secureCfg === null || !FerSecureConfig::isComplete($_secureCfg)) {
    // Installation absente ou incomplète → rediriger vers install.php
    $_scriptDir = realpath(dirname($_SERVER['SCRIPT_FILENAME']));
    $_rootDir   = realpath(__DIR__ . '/../..');
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

// Compatibilité : injecter la config dans $_ENV / getenv() pour tout le code
// existant ($_ENV['DB_HOST'], ENCRYPTION_KEY, TRUSTED_PROXIES, SYNC_WORKER_TOKEN…)
FerSecureConfig::exportToEnv($_secureCfg);
unset($_secureCfg);
// ── Fin chargement configuration ────────────────────────────────────────────
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=utf8mb4',
    $_ENV['DB_HOST'],
    $_ENV['DB_NAME']
);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

$pdo = new DebugPDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], $options);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET time_zone = '+02:00'");

// 🕒 Aligne le fuseau de PHP sur celui de MySQL (+02:00) pour que strtotime()/date()
// interprètent les dates de la BDD SANS décalage. Etc/GMT-2 = UTC+2 (offset FIXE,
// identique au SET time_zone MySQL ci-dessus — volontairement pas « Europe/Paris »
// qui passerait à +01:00 en hiver et recréerait un décalage). Corrige les calculs
// d'expiration basés sur strtotime (verrouillage compte, code 2FA e-mail, campagnes
// t-shirt) qui, PHP en UTC, se trompaient de ~2 h.
date_default_timezone_set('Etc/GMT-2');

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

// Ne JAMAIS exposer les erreurs PHP côté client (API JSON, pages HTML) : l'affichage
// se fait uniquement via la barre de debug (admin + mode debug), cf. src/core/debug.php.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

$GLOBALS['debogage'] = (int) ($data['debogage'] ?? 0);

$logDir = dirname(__DIR__, 2) . '/storage/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
ini_set('log_errors', 1);
ini_set('error_log', $logDir . '/php-error.log');

// On journalise TOUJOURS le maximum d'informations (toutes les erreurs) dans
// php-error.log, que le mode debug soit actif ou non. Le gestionnaire personnalisé
// respecte l'opérateur @ et laisse PHP journaliser ; il collecte en plus les erreurs
// pour la barre de debug quand celle-ci est active.
error_reporting(E_ALL);
set_error_handler('fer_error_handler');
register_shutdown_function('fer_shutdown_handler');

if ($GLOBALS['debogage'] == 1) {
    // Mode debug : profiling des requêtes SQL + affichage (barre + cartes rouges) réservé
    // à l'admin sur les pages HTML (jamais sur l'API/JSON), via DebugBar::maybeRender().
    DebugBar::$enabled = true;
    try { $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [DebugPDOStatement::class]); } catch (\Throwable $e) {}
}

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
session_start();

/* ───── IP cliente fiable (anti-spoofing) ──────────────────────────────────
 * 🔒 [SEC-IP] Par défaut on n'utilise QUE REMOTE_ADDR. Les en-têtes
 * X-Forwarded-For / CF-Connecting-IP sont falsifiables par le client : les
 * honorer sans condition permet de contourner les bans/rate-limits et de faire
 * bannir l'IP d'un tiers. Si le site est derrière un proxy/CDN de confiance
 * (Cloudflare, reverse-proxy hébergeur…), lister ses IP/CIDR dans la variable
 * d'environnement TRUSTED_PROXIES (séparées par des virgules) : les en-têtes de
 * forwarding ne sont alors lus QUE si REMOTE_ADDR figure dans cette liste.
 * ────────────────────────────────────────────────────────────────────────── */
function fer_ip_in_cidr(string $ip, string $cidr): bool {
    $cidr = trim($cidr);
    if ($cidr === '') return false;
    if (strpos($cidr, '/') === false) return $ip === $cidr; // IP exacte
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int) $bits;
    $ipBin  = @inet_pton($ip);
    $subBin = @inet_pton($subnet);
    if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) return false;
    $bytes = intdiv($bits, 8);
    $rem   = $bits % 8;
    if ($bytes > 0 && strncmp($ipBin, $subBin, $bytes) !== 0) return false;
    if ($rem === 0) return true;
    $mask = chr((0xff << (8 - $rem)) & 0xff);
    return ($ipBin[$bytes] & $mask) === ($subBin[$bytes] & $mask);
}

function fer_client_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    static $trusted = null;
    if ($trusted === null) {
        $raw = $_ENV['TRUSTED_PROXIES'] ?? (getenv('TRUSTED_PROXIES') ?: '');
        $trusted = array_filter(array_map('trim', explode(',', (string) $raw)));
    }
    if (empty($trusted)) return $remote; // aucun proxy de confiance → REMOTE_ADDR seul
    $isTrusted = false;
    foreach ($trusted as $cidr) {
        if (fer_ip_in_cidr($remote, $cidr)) { $isTrusted = true; break; }
    }
    if (!$isTrusted) return $remote;
    // REMOTE_ADDR est un proxy de confiance : on peut honorer les en-têtes de forwarding.
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])
        && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return $remote;
}

/* ───── Timeout de session par inactivité ──────────────────────────────────
 * 🔒 [SEC-SESSION] Durée configurable (Réglages → setting.session_lifetime, en
 * minutes ; 0 = jamais). N'affecte QUE les sessions authentifiées : les visiteurs
 * publics ne sont jamais déconnectés. À l'expiration, on détruit la session ;
 * les gardes des pages (requirePage/requireRole) redirigent alors vers login.
 * Idempotent si la colonne n'existe pas encore (avant migration).
 * ────────────────────────────────────────────────────────────────────────── */
if (isset($_SESSION['uid'])) {
    $__idleLife = 0; $__absLife = 0;
    try {
        $__srow = $pdo->query('SELECT session_lifetime, session_absolute_lifetime FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
        $__idleLife = (int) ($__srow['session_lifetime'] ?? 0);
        $__absLife  = (int) ($__srow['session_absolute_lifetime'] ?? 0);
    } catch (\Throwable $e) {
        // Colonne "absolute" absente (avant migration) → on ne lit que l'inactivité.
        try { $__idleLife = (int) ($pdo->query('SELECT session_lifetime FROM setting WHERE id = 1 LIMIT 1')->fetchColumn() ?: 0); }
        catch (\Throwable $e2) { $__idleLife = 0; }
    }

    $__now = time();
    $__expired = false;

    // Cap ABSOLU : déconnexion X minutes après la connexion, même si actif.
    // login_time est initialisé ici au 1er passage authentifié (≈ juste après le login).
    if ($__absLife > 0) {
        if (!isset($_SESSION['login_time'])) $_SESSION['login_time'] = $__now;
        if (($__now - (int) $_SESSION['login_time']) > $__absLife * 60) $__expired = true;
    }
    // Timeout d'INACTIVITÉ : X minutes sans requête.
    if (!$__expired && $__idleLife > 0) {
        $__last = $_SESSION['last_activity'] ?? $__now;
        if (($__now - $__last) > $__idleLife * 60) $__expired = true;
    }

    if ($__expired) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $__p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $__p['path'], $__p['domain'], $__p['secure'], $__p['httponly']);
        }
        session_destroy();
        $_SESSION = []; // pour la suite de la requête : utilisateur non authentifié
    } elseif ($__idleLife > 0 || $__absLife > 0) {
        $_SESSION['last_activity'] = $__now;
    }
    unset($__idleLife, $__absLife);
}

/* ───── Enforcement IP ban global ──────────────────────────────────────────
 * Vérifie si l'IP du visiteur est dans login_banned_ips dès le require de
 * config.php. Si c'est le cas, redirige vers /errors/ip-banned.php (sauf
 * exclusions ci-dessous, pour éviter les boucles et préserver les API JSON).
 * ────────────────────────────────────────────────────────────────────────── */
do {
    // 1. Exclusions par chemin pour éviter les boucles + préserver l'API JSON
    $__reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $__reqBase = basename($__reqPath);
    if (in_array($__reqBase, [
        'ip-banned.php', '403.php', '404.php', '500.php', '503.php',
        'maintenance.php', 'api.php',
    ], true)) break;

    // 2. Détecter le nom de la colonne IP dans login_banned_ips (compat ancien schéma)
    try {
        $__cols = $pdo->query("SHOW COLUMNS FROM login_banned_ips")->fetchAll(PDO::FETCH_COLUMN);
        $__ipCol = in_array('ip', $__cols, true) ? 'ip'
                 : (in_array('ip_address', $__cols, true) ? 'ip_address' : null);
        if ($__ipCol === null) break;
    } catch (\Throwable $e) {
        break; // Table absente : pas de check possible
    }

    // 3. Récupérer l'IP réelle (REMOTE_ADDR seul par défaut ; en-têtes de
    //    forwarding honorés uniquement via TRUSTED_PROXIES, cf. fer_client_ip()).
    $__ip = fer_client_ip();
    if (empty($__ip)) break;

    // 4. Vérifier le ban actif (NULL = permanent, future date = temporaire)
    try {
        $__sql = "SELECT reason, expires_at FROM login_banned_ips
                  WHERE `{$__ipCol}` = ? AND (expires_at IS NULL OR expires_at > NOW())
                  LIMIT 1";
        $__st = $pdo->prepare($__sql);
        $__st->execute([$__ip]);
        $__banInfo = $__st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        break; // Erreur SQL : ne pas bloquer
    }

    if (!$__banInfo) break;

    // 5. Construire l'URL absolue vers errors/ip-banned.php
    //    (les coordonnées de contact sont chargées directement par ip-banned.php)
    $__type  = !empty($__banInfo['expires_at']) ? 'temp' : 'permanent';
    $__until = $__banInfo['expires_at'] ?? '';

    // Calculer le préfixe applicatif : on prend SCRIPT_NAME, on retire le nom du fichier,
    // puis on remonte d'un cran si on est dans inc/public/config/errors
    $__dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    $__lastSeg = basename($__dir);
    if (in_array($__lastSeg, ['inc', 'public', 'config', 'errors'], true)) {
        $__appRoot = dirname($__dir);
    } else {
        $__appRoot = $__dir;
    }
    if ($__appRoot === '/' || $__appRoot === '\\' || $__appRoot === '.') $__appRoot = '';

    $__target = $__appRoot . '/errors/ip-banned.php?type=' . urlencode($__type)
              . '&ip=' . urlencode($__ip)
              . ($__until ? '&until=' . urlencode($__until) : '');

    http_response_code(403);
    header('Location: ' . $__target);
    exit;
} while (false);

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

/* ───── Système de permissions granulaires ─────────────────────────────────
 * Pages disponibles : dashboard, timeline, news, partners, albums, stats,
 *                     setting, mail-settings, qr_code, connexions, logs,
 *                     page_stats
 *
 * Actions disponibles :
 *   Inscriptions :
 *     - dashboard.create_registration / edit_registration / delete_registration
 *     - dashboard.archive / import_excel / export_excel
 *     - dashboard.scan_qr  (mode "Remise T-shirts" : scanner QR + assigner taille)
 *     - dashboard.bulk_create  (ajout multiple : saisie en lot d'inscrits d'une même entreprise)
 *   Contenus (granularité par page) :
 *     - news.create / news.edit / news.trash / news.delete
 *     - timeline.create / timeline.edit / timeline.trash / timeline.delete
 *     - partners.create / partners.edit / partners.trash / partners.delete
 *     - albums.create / albums.edit / albums.trash / albums.delete
 *     ( .trash = mise en corbeille uniquement ; .delete = onglet Corbeille visible
 *       + suppression définitive. .delete est un superset de .trash. )
 *   Pages d'administration (read = page access, write = action) :
 *     - settings.write       (Réglages)
 *     - mail.write           (Paramètres mail : Template / Google / Notifications)
 *     - mail.send            (Paramètres mail : envoyer des mails — onglet "Envoi de mail")
 *     - qrcode.write         (QR Code create/delete)
 *     - connexions.write     (ban/débannir IPs, révoquer appareils)
 *     - logs.write           (vider les logs)
 *
 * Stockage : users.permissions (JSON) — si NULL, on applique les défauts du rôle.
 * Règle dure unique : admin a tout, jamais bridé.
 * Tous les autres rôles (user, viewer, saisie) sont entièrement personnalisables.
 *
 * Backward compatibility : les anciennes permissions content.create / content.edit /
 * content.delete sont reconnues comme raccourci pour les 4 pages de contenu correspondantes.
 */
/**
 * Permissions par défaut "dures" — utilisées comme fallback si aucune valeur custom
 * n'est stockée en BDD pour les rôles user et viewer (admin/saisie sont toujours fixes).
 */
function hardcodedDefaultPermissions(string $role): array
{
    return match ($role) {
        'admin' => [
            'pages'   => ['*'],
            'actions' => ['*'],
        ],
        'user' => [
            'pages'   => ['dashboard','timeline','news','partners','albums','stats'],
            'actions' => [
                'dashboard.create_registration',
                'dashboard.edit_registration',
                'dashboard.import_excel',
                'dashboard.export_excel',
                // Création + modification sur les 4 contenus, pas de suppression
                'news.create','news.edit',
                'timeline.create','timeline.edit',
                'partners.create','partners.edit',
                'albums.create','albums.edit',
            ],
        ],
        'viewer' => [
            'pages'   => ['dashboard','stats'],
            'actions' => ['dashboard.export_excel'],
        ],
        'saisie' => [
            'pages'   => ['dashboard'],
            'actions' => ['dashboard.create_registration'],
        ],
        default => ['pages' => [], 'actions' => []],
    };
}

/**
 * Catalogue centralisé des pages et actions disponibles dans le système.
 * Utilisé par l'API de validation et par l'UI.
 */
function permCatalog(): array
{
    return [
        'pages' => [
            'dashboard','timeline','news','partners','albums','stats',
            'setting','mail-settings','qr_code','connexions','logs','page_stats',
            'tshirt_access',
        ],
        'actions' => [
            // Inscriptions (dashboard)
            'dashboard.create_registration','dashboard.edit_registration',
            'dashboard.delete_registration','dashboard.archive',
            'dashboard.import_excel','dashboard.export_excel',
            'dashboard.scan_qr','dashboard.bulk_create',
            // Contenus — granularité par page
            'news.create','news.edit','news.trash','news.delete',
            'timeline.create','timeline.edit','timeline.trash','timeline.delete',
            'partners.create','partners.edit','partners.trash','partners.delete',
            'albums.create','albums.edit','albums.trash','albums.delete',
            // Journal d'activité des contenus (droit global de consultation)
            'content.logs.view',
            // Pages d'administration (write actions)
            'settings.write','mail.write','mail.send','mail.newsletter','qrcode.write','connexions.write','logs.write',
            // Accès bénévoles (Remise T-shirts) — granularité par action
            'tshirt_access.manage','tshirt_access.approve','tshirt_access.devices_view','tshirt_access.devices_revoke',
            // Sous-onglets des Réglages (granularité par onglet)
            'settings.tab.personnalisation','settings.tab.accueil','settings.tab.inscription',
            'settings.tab.parcours','settings.tab.reglementation','settings.tab.formulaire',
            'settings.tab.import','settings.tab.import_auto','settings.tab.maintenance',
            // Cartes de l'onglet Accueil
            'settings.accueil.params','settings.accueil.custom',
            // Cartes de l'onglet Inscription
            'settings.inscription.header','settings.inscription.params','settings.inscription.assoconnect','settings.inscription.cspdomains',
        ],
    ];
}

function defaultPermissions(string $role): array
{
    // admin : toujours tout (jamais bridé)
    if ($role === 'admin') {
        return hardcodedDefaultPermissions('admin');
    }

    // user, viewer, saisie : charger depuis setting.role_permissions si la conf existe
    static $stored = null;
    if ($stored === null) {
        global $pdo;
        try {
            $row = $pdo->query("SELECT role_permissions FROM setting WHERE id = 1 LIMIT 1")->fetchColumn();
            $stored = $row ? (json_decode($row, true) ?: []) : [];
        } catch (PDOException $e) {
            $stored = [];
        }
    }

    if (isset($stored[$role]) && is_array($stored[$role])
        && isset($stored[$role]['pages'], $stored[$role]['actions'])) {
        return [
            'pages'   => array_values((array)$stored[$role]['pages']),
            'actions' => array_values((array)$stored[$role]['actions']),
        ];
    }

    return hardcodedDefaultPermissions($role);
}

function userPermissions(?int $uid = null): array
{
    static $cache = [];
    $uid = $uid ?? currentUserId();
    if ($uid === null) return ['pages' => [], 'actions' => [], 'role' => null];
    if (isset($cache[$uid])) return $cache[$uid];

    global $pdo;
    // Détection de la colonne permissions (peut ne pas exister si update.php non exécuté)
    static $hasPermsCol = null;
    if ($hasPermsCol === null) {
        try { $pdo->query('SELECT permissions FROM users LIMIT 0'); $hasPermsCol = true; }
        catch (PDOException $e) { $hasPermsCol = false; }
    }

    try {
        $sql = $hasPermsCol
            ? 'SELECT role, permissions FROM users WHERE id = ?'
            : 'SELECT role FROM users WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $row = null;
    }
    if (!$row) return $cache[$uid] = ['pages' => [], 'actions' => [], 'role' => null];

    $role  = $row['role'];
    $perms = (!empty($row['permissions'] ?? null)) ? (json_decode($row['permissions'], true) ?: null) : null;

    if (!$perms || !isset($perms['pages']) || !isset($perms['actions'])) {
        $perms = defaultPermissions($role);
    }

    // Seule règle dure : admin a tout
    if ($role === 'admin') {
        $perms = ['pages' => ['*'], 'actions' => ['*']];
    }

    $perms['role'] = $role;
    return $cache[$uid] = $perms;
}

function canAccessPage(string $page): bool
{
    if (currentRole() === 'admin') return true;
    $perms = userPermissions();
    $pages = $perms['pages'] ?? [];
    return in_array('*', $pages, true) || in_array($page, $pages, true);
}

function canDoAction(string $action): bool
{
    if (currentRole() === 'admin') return true;
    $perms = userPermissions();
    $actions = $perms['actions'] ?? [];
    if (in_array('*', $actions, true) || in_array($action, $actions, true)) {
        return true;
    }

    // Backward compatibility : ancien content.create / content.edit / content.delete
    // équivaut au droit correspondant sur les 4 pages de contenu.
    static $contentCompat = [
        'news.create'     => 'content.create',
        'timeline.create' => 'content.create',
        'partners.create' => 'content.create',
        'albums.create'   => 'content.create',
        'news.edit'       => 'content.edit',
        'timeline.edit'   => 'content.edit',
        'partners.edit'   => 'content.edit',
        'albums.edit'     => 'content.edit',
        'news.delete'     => 'content.delete',
        'timeline.delete' => 'content.delete',
        'partners.delete' => 'content.delete',
        'albums.delete'   => 'content.delete',
        // content.delete (ancien) couvre aussi la mise en corbeille (.trash)
        'news.trash'      => 'content.delete',
        'timeline.trash'  => 'content.delete',
        'partners.trash'  => 'content.delete',
        'albums.trash'    => 'content.delete',
    ];
    if (isset($contentCompat[$action]) && in_array($contentCompat[$action], $actions, true)) {
        return true;
    }

    // {page}.delete est un superset de {page}.trash : si l'utilisateur peut supprimer
    // définitivement, il peut évidemment mettre en corbeille.
    if (preg_match('/^(news|timeline|partners|albums)\.trash$/', $action, $m)) {
        if (in_array($m[1] . '.delete', $actions, true)) {
            return true;
        }
    }

    return false;
}

function requirePage(string $page)
{
    if (!isset($_SESSION['uid'])) {
        http_response_code(403);
        header('Location: ../login.php');
        exit;
    }
    if (!canAccessPage($page)) {
        // Éviter les boucles de redirection : si dashboard est accessible et qu'on n'est pas déjà dessus, y aller
        if ($page !== 'dashboard' && canAccessPage('dashboard')) {
            header('Location: dashboard.php');
            exit;
        }
        // Sinon : aucun fallback, on déconnecte proprement
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        http_response_code(403);
        header('Location: ../login.php?msg=no_access');
        exit;
    }
}

function requireAction(string $action)
{
    if (!canDoAction($action)) {
        http_response_code(403);
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['ok' => false, 'err' => 'Action non autorisée']);
        exit;
    }
}

function isViewer(): bool
{
    return currentRole() === 'viewer';
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
            include __DIR__ . '/../../errors/maintenance.php';
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
    $projectRoot = realpath(__DIR__ . '/../..');
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

/**
 * Dérive les 6 variables d'accent jr-theme (clair/sombre) depuis une couleur hex.
 * Utilisée par l'admin (navbar) et les pages d'authentification (accent par
 * défaut = couleur primaire du site public).
 * @return array{0:string,1:string,2:string,3:string,4:string,5:string}|null
 */
function jr_accent_vars_from_hex(string $hex): ?array
{
    if (!preg_match('/^#?([0-9a-fA-F]{6})$/', $hex, $m)) return null;
    $hex = '#' . strtolower($m[1]);
    $mix = function (string $h, float $t, array $to) {
        $r = hexdec(substr($h, 1, 2)); $g = hexdec(substr($h, 3, 2)); $b = hexdec(substr($h, 5, 2));
        $r = (int) round($r + ($to[0] - $r) * $t);
        $g = (int) round($g + ($to[1] - $g) * $t);
        $b = (int) round($b + ($to[2] - $b) * $t);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    };
    $lum = function (string $h) {
        $r = hexdec(substr($h, 1, 2)) / 255; $g = hexdec(substr($h, 3, 2)) / 255; $b = hexdec(substr($h, 5, 2)) / 255;
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    };
    $light       = $hex;
    $lightStrong = $mix($hex, 0.14, [0, 0, 0]);
    $lightInk    = $lum($hex) > 0.62 ? '#101828' : '#ffffff';
    $dark        = $mix($hex, 0.30, [255, 255, 255]);
    $darkStrong  = $mix($hex, 0.42, [255, 255, 255]);
    $darkInk     = $lum($dark) > 0.62 ? '#0b1030' : '#ffffff';
    return [$light, $lightStrong, $lightInk, $dark, $darkStrong, $darkInk];
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
    $dir = __DIR__ . '/../../fonts';
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

    // Mode permissif : autorise l'admin à coller du HTML/CSS/JS quelconque
    // (utilisé pour les blocs custom de l'accueil par exemple). À NE PAS activer
    // pour des éditeurs accessibles à des utilisateurs non-admin.
    $permissive = !empty($overrides['permissive_html']);
    unset($overrides['permissive_html']);

    $defaults = [
        'license_key' => 'gpl',
        'language' => 'fr_FR',
        'plugins' => 'anchor autolink charmap codesample emoticons image link lists media searchreplace table visualblocks wordcount',
        'toolbar' => 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat',
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
        $toggles += ['mention' => true, 'partner' => true, 'ip_ban' => true, 'twofa' => true, 'lock' => true, 'contact' => true];
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

/**
 * Détermine si le bandeau flash doit être affiché, selon le mode choisi :
 *   - 'on'   → toujours affiché
 *   - 'off'  → jamais
 *   - 'auto' → affiché entre flash_info_start et flash_info_end (bornes optionnelles) :
 *              s'active seul au début et se désactive seul à la fin (évalué à chaque
 *              chargement de page, aucun cron nécessaire).
 * Rétrocompatible : si flash_info_mode est absent (avant migration), retombe sur
 * l'ancien flag flash_info_active.
 */
function flashBannerActive(array $s): bool {
    $mode = $s['flash_info_mode'] ?? (!empty($s['flash_info_active']) ? 'on' : 'off');
    if ($mode === 'on') return true;
    if ($mode === 'auto') {
        $now   = time();
        $start = !empty($s['flash_info_start']) ? strtotime((string) $s['flash_info_start']) : null;
        $end   = !empty($s['flash_info_end'])   ? strtotime((string) $s['flash_info_end'])   : null;
        if ($start !== null && $now < $start) return false;
        if ($end   !== null && $now >= $end)  return false;
        return true;
    }
    return false; // 'off'
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

// ── Domaines AssoConnect autorisés dans la CSP ────────────────────────────────
// Gérables depuis Réglages → Inscription → « Liaison AssoConnect » (colonne
// setting.assoconnect_csp_domains, un domaine par ligne). Injectés dans
// frame-src / script-src / connect-src pour que le formulaire + le paiement
// AssoConnect (Adyen / team.blue) puissent se charger. Si la liste est vide ou
// la colonne absente (avant migration), on retombe sur des valeurs par défaut.
$cspAssoDefault = ['https://*.assoconnect.com', 'https://*.team.blue', 'https://*.adyen.com'];
$cspAssoDomains = [];
try {
    $rawDom = $pdo->query("SELECT assoconnect_csp_domains FROM setting WHERE id = 1")->fetchColumn();
    if ($rawDom) {
        foreach (preg_split('/[\s,]+/', $rawDom) as $d) {
            $d = trim($d);
            // Sécurité : on n'accepte que des origines https valides (sous-domaine
            // joker autorisé). Pas de schéma http, data:, 'unsafe-*', etc.
            if ($d !== '' && preg_match('#^https://(\*\.)?[a-z0-9.-]+\.[a-z]{2,}$#i', $d)) {
                $cspAssoDomains[] = $d;
            }
        }
    }
} catch (\Throwable $e) { /* colonne absente avant migration → défauts */ }
if (empty($cspAssoDomains)) $cspAssoDomains = $cspAssoDefault;
$cspAsso = implode(' ', array_unique($cspAssoDomains));

header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'nonce-" . $GLOBALS['csp_nonce'] . "' " .
        "https://cdn.jsdelivr.net https://code.jquery.com https://cdn.tiny.cloud https://cdn.datatables.net https://challenges.cloudflare.com " . $cspAsso . "; " .
    "style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdn.datatables.net 'unsafe-inline'; " .
    "img-src 'self' data: blob: https:; " .
    "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com https://cdn.datatables.net; " .
    "frame-src 'self' https://challenges.cloudflare.com https://www.google.com https://maps.google.com " . $cspAsso . "; " .
    "connect-src 'self' https://cdn.jsdelivr.net https://challenges.cloudflare.com https://photon.komoot.io " . $cspAsso . "; " .
    "object-src 'none'; " .
    "base-uri 'self';"
);
