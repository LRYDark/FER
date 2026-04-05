<?php
/**
 * Assistant d'installation — Forbach en Rose
 * Accessible uniquement si config/.env est absent ou incomplet.
 */
ob_start();

// ── SECURITE : bloquer si déjà installé ─────────────────────
// 🔒 [SEC-13] Double verrou .env + .install.lock (CWE-749)
$envPath  = __DIR__ . '/config/.env';
$lockPath = __DIR__ . '/config/.install.lock';

if (file_exists($lockPath) && file_exists($envPath)) {
    header('Location: login.php');
    exit;
}
if (file_exists($envPath)) {
    $envContent = file_get_contents($envPath);
    $complete   = true;
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'ENCRYPTION_KEY'] as $k) {
        if (strpos($envContent, $k . '=') === false) {
            $complete = false;
            break;
        }
    }
    if ($complete) {
        if (!file_exists($lockPath)) {
            @file_put_contents($lockPath, date('Y-m-d H:i:s') . ' — installed');
        }
        header('Location: login.php');
        exit;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    // Forcer un save_path accessible en écriture
    $candidates = [
        __DIR__ . '/config/sessions',
        sys_get_temp_dir() . '/php_sessions',
        sys_get_temp_dir(),
    ];
    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
            break;
        }
    }
    if (!@session_start()) {
        die('Erreur : impossible de démarrer la session PHP. save_path='
            . htmlspecialchars(session_save_path())
            . ' — Vérifiez que le dossier existe et est accessible en écriture.');
    }
}

// ── CSP nonce ────────────────────────────────────────────────
$csp_nonce = base64_encode(random_bytes(16));
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'nonce-" . $csp_nonce . "' https://cdn.jsdelivr.net; " .
    "style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; " .
    "img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; " .
    "connect-src 'self'; object-src 'none'; base-uri 'self';"
);

// ── CSRF ────────────────────────────────────────────────────
if (empty($_SESSION['csrf_install'])) {
    $_SESSION['csrf_install'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_install'];

function checkCsrf(): void
{
    if (!hash_equals($_SESSION['csrf_install'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('Jeton CSRF invalide. Veuillez recharger la page.');
    }
}

// ── Lire les valeurs .env partielles existantes ─────────────
$existing = [];
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && $line[0] !== '#') {
            [$key, $val] = explode('=', $line, 2);
            $existing[trim($key)] = trim($val);
        }
    }
}

// ── DEBUG TEMPORAIRE — à supprimer après diagnostic ────────
// install.php?phpinfo → phpinfo() complet
if (isset($_GET['phpinfo'])) {
    phpinfo();
    exit;
}
// install.php?phpdiag → diagnostic texte
if (isset($_GET['phpdiag'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== DIAGNOSTIC PHP ===\n\n";
    echo "PHP version  : " . PHP_VERSION . "\n";
    echo "PHP SAPI     : " . PHP_SAPI . "\n";
    echo "PHP binary   : " . PHP_BINARY . "\n";
    echo "php.ini      : " . php_ini_loaded_file() . "\n";
    echo "Scan dir     : " . (php_ini_scanned_files() ?: 'aucun') . "\n";
    echo "OPcache      : " . (function_exists('opcache_get_status') ? 'actif' : 'inactif') . "\n\n";

    echo "--- Tests fonctionnels ---\n";
    echo "class PDO             : " . (class_exists('PDO') ? 'OUI' : 'NON') . "\n";
    echo "PDO drivers           : " . (class_exists('PDO') ? implode(', ', PDO::getAvailableDrivers()) : 'N/A') . "\n";
    echo "mb_strlen             : " . (function_exists('mb_strlen') ? 'OUI' : 'NON') . "\n";
    echo "json_encode           : " . (function_exists('json_encode') ? 'OUI' : 'NON') . "\n";
    echo "DOMDocument           : " . (class_exists('DOMDocument') ? 'OUI' : 'NON') . "\n";
    echo "finfo_open            : " . (function_exists('finfo_open') ? 'OUI' : 'NON') . "\n";
    echo "mime_content_type     : " . (function_exists('mime_content_type') ? 'OUI' : 'NON') . "\n";
    echo "imagecreatetruecolor  : " . (function_exists('imagecreatetruecolor') ? 'OUI' : 'NON') . "\n";
    echo "iconv                 : " . (function_exists('iconv') ? 'OUI' : 'NON') . "\n";
    echo "XMLReader             : " . (class_exists('XMLReader') ? 'OUI' : 'NON') . "\n";
    echo "XMLWriter             : " . (class_exists('XMLWriter') ? 'OUI' : 'NON') . "\n";
    echo "ZipArchive            : " . (class_exists('ZipArchive') ? 'OUI' : 'NON') . "\n";
    echo "gzopen                : " . (function_exists('gzopen') ? 'OUI' : 'NON') . "\n";
    echo "openssl_encrypt       : " . (function_exists('openssl_encrypt') ? 'OUI' : 'NON') . "\n";
    echo "curl_init             : " . (function_exists('curl_init') ? 'OUI' : 'NON') . "\n";

    echo "\n--- extension_loaded() ---\n";
    foreach (['pdo','pdo_mysql','nd_pdo_mysql','PDO','PDO_MYSQL','ND_PDO_MYSQL',
              'mbstring','dom','fileinfo','gd','xmlreader','xmlwriter','zip'] as $e) {
        echo str_pad($e, 20) . ": " . (extension_loaded($e) ? 'OUI' : 'NON') . "\n";
    }

    echo "\n--- get_loaded_extensions() (toutes) ---\n";
    $exts = get_loaded_extensions();
    sort($exts);
    echo implode(', ', $exts) . "\n";

    echo "\n--- Fichiers .ini additionnels ---\n";
    $scanned = php_ini_scanned_files();
    if ($scanned) {
        echo $scanned . "\n";
    } else {
        echo "(aucun fichier .ini additionnel détecté)\n";
    }

    echo "\n--- Fichier install.php ---\n";
    echo "Dernière modif : " . date('Y-m-d H:i:s', filemtime(__FILE__)) . "\n";
    echo "Taille         : " . filesize(__FILE__) . " octets\n";
    exit;
}

// ── Vérification des prérequis PHP ─────────────────────────
function checkPhpPrerequisites(): array
{
    $checks = [];

    // Version PHP minimale
    $checks[] = [
        'label'    => 'PHP 8.1 ou supérieur',
        'detail'   => 'Version actuelle : ' . PHP_VERSION . ' — SAPI : ' . PHP_SAPI,
        'ok'       => version_compare(PHP_VERSION, '8.1.0', '>='),
        'required' => true,
    ];

    // ── Détection double : extension_loaded() + test fonctionnel ──
    // Règle : JAMAIS utiliser "ext-xxx", toujours le vrai nom PHP.
    // On fait extension_loaded('nom') || fallback fonctionnel pour
    // couvrir les cas où l'extension est compilée dans le core.
    $extChecks = [
        // [nom extension_loaded, label, fallback fonctionnel, requis]
        ['pdo',        'PDO — couche d\'accès base de données',
            fn() => class_exists('PDO'), true],
        ['pdo_mysql',  'PDO MySQL — driver MySQL',
            fn() => class_exists('PDO') && in_array('mysql', \PDO::getAvailableDrivers(), true), true],
        ['mbstring',   'Mbstring — chaînes multi-octets',
            fn() => function_exists('mb_strlen'), true],
        ['json',       'JSON — encodage/décodage',
            fn() => function_exists('json_encode'), true],
        ['dom',        'DOM — manipulation XML/HTML',
            fn() => class_exists('DOMDocument'), true],
        ['fileinfo',   'Fileinfo — détection MIME des fichiers',
            fn() => function_exists('finfo_open') || function_exists('mime_content_type'), true],
        ['gd',         'GD — traitement d\'images (QR codes, exports)',
            fn() => function_exists('imagecreatetruecolor'), true],
        ['iconv',      'Iconv — conversion d\'encodage',
            fn() => function_exists('iconv'), true],
        ['libxml',     'Libxml — support XML de base',
            fn() => function_exists('libxml_use_internal_errors'), true],
        ['simplexml',  'SimpleXML — lecture XML simplifiée',
            fn() => class_exists('SimpleXMLElement'), true],
        ['xml',        'XML — parseur XML',
            fn() => function_exists('xml_parser_create'), true],
        ['xmlreader',  'XMLReader — lecture XML en flux',
            fn() => class_exists('XMLReader'), true],
        ['xmlwriter',  'XMLWriter — écriture XML en flux',
            fn() => class_exists('XMLWriter'), true],
        ['zip',        'Zip — archives ZIP (imports Excel)',
            fn() => class_exists('ZipArchive'), true],
        ['zlib',       'Zlib — compression de données',
            fn() => function_exists('gzopen'), true],
        ['ctype',      'Ctype — vérification de types de caractères',
            fn() => function_exists('ctype_alpha'), true],
        ['openssl',    'OpenSSL — chiffrement et sécurité',
            fn() => function_exists('openssl_encrypt'), true],
        // Recommandées
        ['curl',       'cURL — requêtes HTTP (Google API, mails)',
            fn() => function_exists('curl_init'), false],
        ['intl',       'Intl — internationalisation (dates, nombres)',
            fn() => class_exists('NumberFormatter') || class_exists('IntlDateFormatter'), false],
    ];

    foreach ($extChecks as [$ext, $label, $fallback, $required]) {
        $byExtLoaded = extension_loaded($ext);
        $byFallback  = $fallback();
        $ok          = $byExtLoaded || $byFallback;

        // Détail : montrer quelle méthode a détecté l'extension
        if ($ok) {
            $method = $byExtLoaded ? 'extension_loaded' : 'fallback fonctionnel';
            $detail = $ext . ' — détecté via ' . $method;
        } else {
            $detail = $ext . ' — non détecté (extension_loaded=non, fallback=non)';
        }

        $checks[] = [
            'label'    => $label,
            'detail'   => $detail,
            'ok'       => $ok,
            'required' => $required,
        ];
    }

    // Vérifications fonctionnelles
    $checks[] = [
        'label'    => 'config/ accessible en écriture',
        'detail'   => __DIR__ . '/config/',
        'ok'       => is_writable(__DIR__ . '/config'),
        'required' => true,
    ];

    $checks[] = [
        'label'    => 'Session PHP fonctionnelle',
        'detail'   => 'save_path : ' . session_save_path(),
        'ok'       => session_status() === PHP_SESSION_ACTIVE,
        'required' => true,
    ];

    return $checks;
}

$phpChecks    = checkPhpPrerequisites();
$allRequired  = true;
foreach ($phpChecks as $c) {
    if ($c['required'] && !$c['ok']) {
        $allRequired = false;
        break;
    }
}

// ── Traitement des étapes ───────────────────────────────────
$step   = (int) ($_POST['step'] ?? 1);
$errors = [];
$dbSuccess = false;

// --- AJAX : lister les bases de données existantes ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'list_databases') {
    checkCsrf();
    $ajaxHost = trim($_POST['db_host'] ?? '');
    $ajaxUser = trim($_POST['db_user'] ?? '');
    $ajaxPass = $_POST['db_pass'] ?? '';
    header('Content-Type: application/json');
    try {
        $ajaxPdo = new PDO(
            "mysql:host=$ajaxHost;charset=utf8mb4",
            $ajaxUser,
            $ajaxPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $systemDbs = ['information_schema', 'mysql', 'performance_schema', 'sys', 'phpmyadmin'];
        $dbs = $ajaxPdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        $dbs = array_values(array_filter($dbs, fn($d) => !in_array($d, $systemDbs)));
        echo json_encode(['ok' => true, 'databases' => $dbs]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- Étape 2 → 3 : tester connexion BDD ---
$dbMode = $_POST['db_mode'] ?? 'new';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    checkCsrf();

    $dbHost = trim($_POST['db_host'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';

    if ($dbHost === '') $errors[] = "L'hôte de la base de données est requis.";
    if ($dbUser === '') $errors[] = "L'utilisateur de la base de données est requis.";

    if ($dbMode === 'new') {
        // ── Mode nouvelle BDD ──
        $dbName = trim($_POST['db_name'] ?? '');
        if ($dbName === '') $errors[] = "Le nom de la base de données est requis.";
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName) && $dbName !== '') {
            $errors[] = "Le nom de la base ne doit contenir que des lettres, chiffres et underscores.";
        }

        if (empty($errors)) {
            try {
                $testPdo = new PDO(
                    "mysql:host=$dbHost;charset=utf8mb4",
                    $dbUser,
                    $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $testPdo->exec(
                    "CREATE DATABASE IF NOT EXISTS `$dbName`
                     DEFAULT CHARACTER SET utf8mb4
                     COLLATE utf8mb4_general_ci"
                );
                $testPdo->exec("USE `$dbName`");

                $existingTables = $testPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                $dbExisted = count($existingTables) > 0;

                foreach (getCreateTableStatements() as $sql) {
                    $testPdo->exec($sql);
                }
                foreach (getDefaultInserts() as $sql) {
                    $testPdo->exec($sql);
                }

                $_SESSION['install'] = [
                    'db_host' => $dbHost,
                    'db_name' => $dbName,
                    'db_user' => $dbUser,
                    'db_pass' => $dbPass,
                    'db_mode' => 'new',
                    'db_existed' => $dbExisted,
                    'db_existing_tables' => count($existingTables),
                ];
                $dbSuccess = true;
                $step = 3;

            } catch (PDOException $e) {
                $errors[] = "Erreur de connexion : " . htmlspecialchars($e->getMessage());
                $step = 2;
            }
        } else {
            $step = 2;
        }

    } else {
        // ── Mode BDD existante ──
        $dbName       = trim($_POST['db_name_existing'] ?? '');
        $encryptionKey = trim($_POST['encryption_key'] ?? '');

        if ($dbName === '') $errors[] = "Veuillez sélectionner une base de données.";
        if ($encryptionKey === '') $errors[] = "La clé de chiffrement (ENCRYPTION_KEY) est requise pour une BDD existante.";

        if (empty($errors)) {
            try {
                $testPdo = new PDO(
                    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                    $dbUser,
                    $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );

                // Vérifier que la table users existe (signe d'une BDD FER valide)
                $tables = $testPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('users', $tables) || !in_array('setting', $tables)) {
                    $errors[] = "Cette base ne semble pas être une base Forbach en Rose valide (tables 'users' et 'setting' introuvables).";
                    $step = 2;
                } else {
                    // Vérifier qu'un admin existe
                    $adminCheck = $testPdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
                    if ((int)$adminCheck === 0) {
                        $errors[] = "Aucun compte administrateur trouvé dans cette base. Utilisez le mode 'Nouvelle installation'.";
                        $step = 2;
                    } else {
                        // Mode existant : écrire le .env directement (pas d'étape admin)
                        $envContent = "DB_HOST=$dbHost\n"
                                    . "DB_NAME=$dbName\n"
                                    . "DB_USER=$dbUser\n"
                                    . "DB_PASS=$dbPass\n"
                                    . "\nENCRYPTION_KEY=$encryptionKey\n";

                        $configDir = __DIR__ . '/config';
                        if (!is_writable($configDir)) {
                            $_SESSION['env_manual'] = $envContent;
                        } else {
                            file_put_contents($envPath, $envContent);
                            @file_put_contents($lockPath, date('Y-m-d H:i:s') . ' — installed (existing db)');
                        }
                        $_SESSION['install_done'] = true;
                        $_SESSION['install_admin'] = '(compte existant)';
                        $_SESSION['install'] = [
                            'db_host' => $dbHost,
                            'db_name' => $dbName,
                            'db_user' => $dbUser,
                            'db_pass' => $dbPass,
                            'db_mode' => 'existing',
                        ];
                        $dbSuccess = true;
                        $step = 4;
                    }
                }

            } catch (PDOException $e) {
                $errors[] = "Erreur de connexion : " . htmlspecialchars($e->getMessage());
                $step = 2;
            }
        } else {
            $step = 2;
        }
    }
}

// --- Étape 3 → 4 : créer admin + écrire .env ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (int) ($_POST['step'] ?? 0) === 4) {
    checkCsrf();
    $step = 4;

    if (!isset($_SESSION['install'])) {
        $errors[] = "Session expirée. Veuillez recommencer l'installation.";
        $step = 2;
    } else {
        $inst = $_SESSION['install'];

        if (($inst['db_mode'] ?? 'new') === 'existing') {
            // ── Mode BDD existante : pas d'admin à créer, on écrit le .env ──
            $encryptionKey = $inst['encryption_key'];
            $envContent = "DB_HOST={$inst['db_host']}\n"
                        . "DB_NAME={$inst['db_name']}\n"
                        . "DB_USER={$inst['db_user']}\n"
                        . "DB_PASS={$inst['db_pass']}\n"
                        . "\nENCRYPTION_KEY=$encryptionKey\n";

            $configDir = __DIR__ . '/config';
            if (!is_writable($configDir)) {
                $_SESSION['env_manual'] = $envContent;
                $step = 4;
            } else {
                file_put_contents($envPath, $envContent);
                @file_put_contents($lockPath, date('Y-m-d H:i:s') . ' — installed (existing db)');
                $_SESSION['install_done'] = true;
                $_SESSION['install_admin'] = '(compte existant)';
                unset($_SESSION['install']);
                $step = 4;
            }

        } else {
            // ── Mode nouvelle BDD : créer l'admin ──
            $adminUser  = trim($_POST['admin_email'] ?? '');
            $adminPass  = $_POST['admin_password'] ?? '';
            $adminPass2 = $_POST['admin_password_confirm'] ?? '';

            if ($adminUser === '')          $errors[] = "L'adresse email est requise.";
            if (!filter_var($adminUser, FILTER_VALIDATE_EMAIL)) $errors[] = "L'adresse email n'est pas valide.";
            if (strlen($adminPass) < 14)    $errors[] = "Le mot de passe doit contenir au moins 14 caractères.";
            if (!preg_match('/[A-Z]/', $adminPass))  $errors[] = "Le mot de passe doit contenir au moins une majuscule.";
            if (!preg_match('/[0-9]/', $adminPass))  $errors[] = "Le mot de passe doit contenir au moins un chiffre.";
            if (!preg_match('/[^a-zA-Z0-9]/', $adminPass)) $errors[] = "Le mot de passe doit contenir au moins un caractère spécial.";
            if ($adminPass !== $adminPass2) $errors[] = "Les mots de passe ne correspondent pas.";

            if (empty($errors)) {
                try {
                    $pdo = new PDO(
                        "mysql:host={$inst['db_host']};dbname={$inst['db_name']};charset=utf8mb4",
                        $inst['db_user'],
                        $inst['db_pass'],
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );

                    $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
                    $exists->execute([$adminUser]);
                    if ($exists->fetchColumn() > 0) {
                        $errors[] = "Cette adresse email existe déjà.";
                    } else {
                        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)');
                        $stmt->execute([$adminUser, $hash, 'admin']);

                        $encryptionKey = base64_encode(random_bytes(48));
                        $envContent = "DB_HOST={$inst['db_host']}\n"
                                    . "DB_NAME={$inst['db_name']}\n"
                                    . "DB_USER={$inst['db_user']}\n"
                                    . "DB_PASS={$inst['db_pass']}\n"
                                    . "\nENCRYPTION_KEY=$encryptionKey\n";

                        $configDir = __DIR__ . '/config';
                        if (!is_writable($configDir)) {
                            $_SESSION['env_manual'] = $envContent;
                            $step = 4;
                        } else {
                            file_put_contents($envPath, $envContent);
                            @file_put_contents($lockPath, date('Y-m-d H:i:s') . ' — installed');
                            $_SESSION['install_done'] = true;
                            $_SESSION['install_admin'] = $adminUser;
                            unset($_SESSION['install']);
                            $step = 4;
                        }
                    }
                } catch (PDOException $e) {
                    $errors[] = "Erreur base de données : " . htmlspecialchars($e->getMessage());
                    $step = 3;
                }
            } else {
                $step = 3;
            }
        }
    }
}

// ── Déterminer l'étape d'affichage ──────────────────────────
$displayStep = $step;
if ($dbSuccess && !isset($_SESSION['install_done'])) $displayStep = 3;
if (isset($_SESSION['install_done'])) $displayStep = 4;

// ── Fonctions SQL ───────────────────────────────────────────
function getCreateTableStatements(): array
{
    return [
        // --- Tables sans dépendances FK ---

        "CREATE TABLE IF NOT EXISTS `setting` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `assoconnect_js` longtext DEFAULT NULL,
          `assoconnect_iframe` longtext DEFAULT NULL,
          `title` TEXT DEFAULT NULL,
          `registration_fee` int(10) DEFAULT NULL,
          `course_km` int(10) DEFAULT 7,
          `titleAccueil` TEXT DEFAULT NULL,
          `link_facebook` varchar(255) DEFAULT NULL,
          `link_instagram` varchar(255) DEFAULT NULL,
          `accueil_active` int(2) NOT NULL DEFAULT 0,
          `date_course` timestamp NULL DEFAULT NULL,
          `picture_partner` varchar(255) DEFAULT NULL,
          `picture_gradient` varchar(255) DEFAULT NULL,
          `titleParcours` varchar(255) DEFAULT NULL,
          `parcoursDesc` text DEFAULT NULL,
          `picture_parcours` varchar(255) DEFAULT NULL,
          `div_reglementation` mediumtext DEFAULT NULL,
          `link_cancer` varchar(255) DEFAULT NULL,
          `partners_title` varchar(255) DEFAULT NULL,
          `partners_desc` mediumtext DEFAULT NULL,
          `partners_img` varchar(255) DEFAULT NULL,
          `link_twitter` varchar(255) DEFAULT NULL,
          `link_youtube` varchar(255) DEFAULT NULL,
          `debogage` int(2) NOT NULL DEFAULT 0,
          `client_id` TEXT DEFAULT NULL,
          `client_secret` TEXT DEFAULT NULL,
          `mail_email` VARCHAR(255) DEFAULT NULL,
          `mail_phone` VARCHAR(50) DEFAULT NULL,
          `flash_info_text` VARCHAR(500) DEFAULT NULL,
          `flash_info_active` TINYINT(1) NOT NULL DEFAULT 0,
          `qrcode_mail_mode` ENUM('none','all','first_x') NOT NULL DEFAULT 'none',
          `qrcode_mail_limit` INT(11) NOT NULL DEFAULT 0,
          `titleAccueil_mobile` TEXT DEFAULT NULL,
          `title_mobile` TEXT DEFAULT NULL,
          `navbar_logo` VARCHAR(255) DEFAULT 'logo_fer_rose.png',
          `subtitle_accueil` VARCHAR(255) DEFAULT NULL,
          `subtitle_accueil_mobile` VARCHAR(255) DEFAULT NULL,
          `video_accueil` VARCHAR(255) DEFAULT 'FER.mp4',
          `maintenance_mode` TINYINT(1) NOT NULL DEFAULT 0,
          `maintenance_message` VARCHAR(500) DEFAULT NULL,
          `mail_template_config` TEXT DEFAULT NULL,
          `theme_primary_color` VARCHAR(7) DEFAULT '#db2777',
          `theme_secondary_color` VARCHAR(7) DEFAULT '#0f172a',
          `theme_dark_enabled` TINYINT(1) NOT NULL DEFAULT 0,
          `flash_bg_color` VARCHAR(7) DEFAULT '#db2777',
          `flash_text_color` VARCHAR(7) DEFAULT '#ffffff',
          `theme_dark_primary_color` VARCHAR(7) DEFAULT '#f472b6',
          `theme_dark_secondary_color` VARCHAR(7) DEFAULT '#e2e8f0',
          `theme_border_radius` INT DEFAULT 12,
          `theme_font_family` VARCHAR(100) DEFAULT 'Inter',
          `footer_logo` VARCHAR(255) DEFAULT 'logo_blanc.png',
          `registration_auto_open` DATETIME DEFAULT NULL,
          `registration_auto_close` DATETIME DEFAULT NULL,
          `mail_provider` ENUM('google','smtp') NOT NULL DEFAULT 'google',
          `smtp_host` VARCHAR(255) DEFAULT NULL,
          `smtp_port` INT DEFAULT 465,
          `smtp_user` VARCHAR(255) DEFAULT NULL,
          `smtp_pass` TEXT DEFAULT NULL,
          `smtp_encryption` ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl',
          `smtp_from_email` VARCHAR(255) DEFAULT NULL,
          `smtp_from_name` VARCHAR(255) DEFAULT 'Forbach en Rose',
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `customize` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `assoconnect_js` longtext DEFAULT NULL,
          `assoconnect_iframe` longtext DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `users` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `email` varchar(255) NOT NULL,
          `password_hash` varchar(255) NOT NULL,
          `role` enum('admin','user','viewer','saisie') NOT NULL DEFAULT 'viewer',
          `organisation` varchar(120) DEFAULT NULL,
          `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
          `reset_token` VARCHAR(64) DEFAULT NULL,
          `reset_token_expires` DATETIME DEFAULT NULL,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `failed_attempts` TINYINT NOT NULL DEFAULT 0,
          `locked_at` DATETIME DEFAULT NULL,
          `twofa_code` VARCHAR(6) DEFAULT NULL,
          `twofa_expires` DATETIME DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `forms` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `fields` varchar(50) DEFAULT NULL,
          `label` varchar(100) DEFAULT NULL,
          `field_type` varchar(20) NOT NULL DEFAULT 'text',
          `bdd_column` varchar(50) DEFAULT NULL,
          `active` int(2) NOT NULL DEFAULT 0,
          `required` int(2) NOT NULL DEFAULT 0,
          `is_locked` tinyint(1) NOT NULL DEFAULT 0,
          `is_default` tinyint(1) NOT NULL DEFAULT 1,
          `visible_public` tinyint(1) NOT NULL DEFAULT 1,
          `visible_admin` tinyint(1) NOT NULL DEFAULT 1,
          `visible_saisie` tinyint(1) NOT NULL DEFAULT 1,
          `visible_qr` tinyint(1) NOT NULL DEFAULT 1,
          `sort_order` int(11) NOT NULL DEFAULT 0,
          `options_list` text DEFAULT NULL,
          `encrypted` tinyint(1) NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `import` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `fields_bdd` varchar(50) DEFAULT NULL,
          `fields_excel` varchar(50) DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `news` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `img_article` varchar(255) DEFAULT NULL,
          `title_article` varchar(255) DEFAULT NULL,
          `desc_article` mediumtext DEFAULT NULL,
          `date_publication` timestamp NULL DEFAULT NULL,
          `like` int(11) DEFAULT 0,
          `dislike` int(11) DEFAULT 0,
          `status` enum('published','draft') NOT NULL DEFAULT 'published',
          `deleted_at` timestamp NULL DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `registrations_stats` (
          `year` int(11) NOT NULL,
          `total_inscrits` int(11) NOT NULL,
          `tshirt_xs` int(11) NOT NULL,
          `tshirt_s` int(11) NOT NULL,
          `tshirt_m` int(11) NOT NULL,
          `tshirt_l` int(11) NOT NULL,
          `tshirt_xl` int(11) NOT NULL,
          `tshirt_xxl` int(11) NOT NULL,
          `age_moyen` decimal(5,2) DEFAULT NULL,
          `table_name` varchar(50) DEFAULT NULL,
          `ville_top` varchar(255) DEFAULT NULL,
          `entreprise_top` varchar(255) DEFAULT NULL,
          `plus_vieux_h` varchar(255) DEFAULT NULL,
          `plus_vieille_f` varchar(255) DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`year`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // --- Tables avec FK vers les précédentes ---

        "CREATE TABLE IF NOT EXISTS `registrations` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `inscription_no` VARCHAR(50) NOT NULL,
          `nom` varchar(255) NOT NULL,
          `prenom` varchar(255) NOT NULL,
          `tel` varchar(255) DEFAULT NULL,
          `email` varchar(255) DEFAULT NULL,
          `naissance` varchar(255) DEFAULT NULL,
          `sexe` enum('H','F','Autre') DEFAULT 'H',
          `tshirt_size` enum('-','XS','S','M','L','XL','XXL') DEFAULT '-',
          `ville` varchar(255) NOT NULL,
          `entreprise` varchar(255) DEFAULT NULL,
          `origine` varchar(40) DEFAULT 'en ligne',
          `paiement_mode` varchar(50) DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT current_timestamp(),
          `created_by` int(11) DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `inscription_no` (`inscription_no`),
          KEY `created_by` (`created_by`),
          CONSTRAINT `registrations_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `partners_years` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `year` int(11) NOT NULL,
          `title` varchar(255) NOT NULL,
          `img` varchar(255) DEFAULT NULL,
          `desc` mediumtext DEFAULT NULL,
          `status` varchar(20) NOT NULL DEFAULT 'published',
          `deleted_at` timestamp NULL DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `partners_albums` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `year_id` int(11) NOT NULL,
          `album_title` varchar(255) NOT NULL,
          `album_img` varchar(255) DEFAULT NULL,
          `album_desc` text DEFAULT NULL,
          `sort_order` int(11) NOT NULL DEFAULT 0,
          `deleted_at` timestamp NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `year_id` (`year_id`),
          CONSTRAINT `partners_albums_ibfk_1` FOREIGN KEY (`year_id`) REFERENCES `partners_years` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `photo_years` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `year` int(11) NOT NULL,
          `title` varchar(255) NOT NULL,
          `status` varchar(20) NOT NULL DEFAULT 'published',
          `deleted_at` timestamp NULL DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `photo_albums` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `year_id` int(11) NOT NULL,
          `album_title` varchar(255) NOT NULL,
          `album_link` text NOT NULL,
          `album_type` varchar(10) NOT NULL DEFAULT 'link',
          `album_img` varchar(255) DEFAULT NULL,
          `album_desc` text DEFAULT NULL,
          `sort_order` int(11) NOT NULL DEFAULT 0,
          `deleted_at` timestamp NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `year_id` (`year_id`),
          CONSTRAINT `photo_albums_ibfk_1` FOREIGN KEY (`year_id`) REFERENCES `photo_years` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `qrcodes` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `organisation` varchar(255) NOT NULL,
          `token` varchar(64) NOT NULL,
          `qr_url` varchar(500) NOT NULL,
          `description` text DEFAULT NULL,
          `is_active` tinyint(1) DEFAULT 1,
          `created_at` timestamp NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          `created_by` int(11) DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `token` (`token`),
          KEY `idx_token` (`token`),
          KEY `idx_organisation` (`organisation`),
          KEY `idx_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `timeline_items` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `title` varchar(255) NOT NULL,
          `content` varchar(255) NOT NULL,
          `image` varchar(255) DEFAULT NULL,
          `image_position` varchar(50) DEFAULT '50% 50% 1',
          `sort_order` int(11) NOT NULL DEFAULT 0,
          `status` varchar(20) NOT NULL DEFAULT 'published',
          `deleted_at` datetime DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `timeline_elements` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `item_id` int(11) NOT NULL,
          `label` varchar(255) NOT NULL,
          `sort_order` int(11) NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          KEY `item_id` (`item_id`),
          CONSTRAINT `timeline_elements_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `timeline_items` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `parcours_images` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `filename` VARCHAR(255) NOT NULL,
          `sort_order` INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // --- Tables commentaires ---

        "CREATE TABLE IF NOT EXISTS `news_comments` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `news_id` INT NOT NULL,
          `parent_id` INT UNSIGNED DEFAULT NULL,
          `author_name` VARCHAR(100) NOT NULL,
          `content` TEXT NOT NULL,
          `ip_address` VARCHAR(45) NOT NULL,
          `likes` INT UNSIGNED NOT NULL DEFAULT 0,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          INDEX `idx_news_id` (`news_id`),
          INDEX `idx_parent_id` (`parent_id`),
          CONSTRAINT `fk_comment_news` FOREIGN KEY (`news_id`) REFERENCES `news`(`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_comment_parent` FOREIGN KEY (`parent_id`) REFERENCES `news_comments`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `news_comments_likes` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `comment_id` INT UNSIGNED NOT NULL,
          `ip_address` VARCHAR(45) NOT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE INDEX `idx_unique_like` (`comment_id`, `ip_address`),
          CONSTRAINT `fk_like_comment` FOREIGN KEY (`comment_id`) REFERENCES `news_comments`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `news_banned_ips` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `ip_address` VARCHAR(45) NOT NULL,
          `reason` VARCHAR(255) DEFAULT NULL,
          `banned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `banned_by` VARCHAR(100) DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE INDEX `idx_ip` (`ip_address`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `login_logs` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT DEFAULT NULL,
          `email` VARCHAR(255) NOT NULL,
          `ip_address` VARCHAR(45) NOT NULL,
          `user_agent` VARCHAR(500) DEFAULT NULL,
          `success` TINYINT(1) NOT NULL DEFAULT 0,
          `reason` VARCHAR(255) DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_ip` (`ip_address`),
          INDEX `idx_user` (`user_id`),
          INDEX `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `login_banned_ips` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `ip` VARCHAR(45) NOT NULL,
          `reason` VARCHAR(255) DEFAULT NULL,
          `banned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `expires_at` DATETIME NULL DEFAULT NULL,
          `banned_by` INT DEFAULT NULL,
          UNIQUE KEY `idx_ip` (`ip`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `trusted_devices` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `token` VARCHAR(64) NOT NULL,
          `ip_address` VARCHAR(45) NOT NULL,
          `user_agent` VARCHAR(500) DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `expires_at` TIMESTAMP NOT NULL,
          UNIQUE KEY `idx_token` (`token`),
          INDEX `idx_user` (`user_id`),
          INDEX `idx_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `page_visits` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `page_url` VARCHAR(500) NOT NULL,
          `visitor_ip` VARCHAR(45) NOT NULL,
          `user_agent` VARCHAR(500) DEFAULT NULL,
          `referer` VARCHAR(500) DEFAULT NULL,
          `visited_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_visited_at` (`visited_at`),
          INDEX `idx_page_url` (`page_url`(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `inscription_counter` (
          `id`      int(11) NOT NULL,
          `next_no` int(11) NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];
}

function getDefaultInserts(): array
{
    return [
        "INSERT IGNORE INTO `setting` (`id`, `title`, `registration_fee`, `course_km`, `accueil_active`, `debogage`)
         VALUES (1, 'Forbach en Rose', 12, 7, 0, 0)",

        "INSERT IGNORE INTO `customize` (`id`, `assoconnect_js`, `assoconnect_iframe`)
         VALUES (1, NULL, NULL)",

        "INSERT IGNORE INTO `forms` (`id`, `fields`, `label`, `field_type`, `bdd_column`, `active`, `required`, `is_locked`, `is_default`, `visible_public`, `visible_admin`, `visible_saisie`, `visible_qr`, `sort_order`, `options_list`, `encrypted`) VALUES
          (1, 'required_name',          'Nom',              'text',   'nom',         1, 1, 1, 1, 1, 1, 1, 1, 1,  NULL, 1),
          (2, 'required_firstname',     'Prénom',           'text',   'prenom',      1, 1, 1, 1, 1, 1, 1, 1, 2,  NULL, 1),
          (3, 'required_phone',         'Téléphone',        'text',   'tel',         1, 0, 0, 1, 1, 1, 1, 1, 4,  NULL, 1),
          (4, 'required_email',         'Email',            'email',  'email',       1, 1, 1, 1, 1, 1, 1, 1, 3,  NULL, 1),
          (5, 'required_date_of_birth', 'Date de naissance','date',   'naissance',   1, 0, 0, 1, 1, 1, 1, 1, 5,  NULL, 1),
          (6, 'required_sex',           'Sexe',             'select', 'sexe',        1, 0, 0, 1, 1, 1, 1, 1, 6,  'H,F,Autre', 0),
          (7, 'required_city',          'Ville',            'text',   'ville',       1, 0, 0, 1, 1, 1, 1, 1, 7,  NULL, 1),
          (8, 'required_company',       'Entreprise',       'text',   'entreprise',  1, 0, 0, 1, 1, 1, 1, 1, 8,  NULL, 1),
          (9, 'required_tshirt',        'Taille T-shirt',   'select', 'tshirt_size', 0, 0, 0, 1, 0, 1, 0, 0, 9,  '-,XS,S,M,L,XL,XXL', 0)",

        "INSERT IGNORE INTO `import` (`id`, `fields_bdd`, `fields_excel`) VALUES
          (1, 'inscription_no', 'numero billet'),
          (2, 'nom', 'prenom participant'),
          (3, 'prenom', 'nom participant'),
          (4, 'tel', 'telephone mobile'),
          (5, 'email', 'adresse email'),
          (6, 'naissance', 'annee de naissance'),
          (7, 'sexe', 'sexe'),
          (8, 'ville', 'ville'),
          (9, 'entreprise', 'nom de l\\'equipe'),
          (10, 'paiement_mode', 'Moyen de paiement'),
          (11, 'origine', 'pays'),
          (12, 'created_at', 'date de creation')",

        // Compteur atomique pour inscription_no (évite la race condition CWE-362)
        "INSERT IGNORE INTO `inscription_counter` (`id`, `next_no`) VALUES (1, 0)",
    ];
}

// ── Libellés des étapes ─────────────────────────────────────
$stepLabels = [
    1 => 'Prérequis',
    2 => 'Base de données',
    3 => 'Compte Admin',
    4 => 'Terminé',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Installation</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      background: #0f172a;
      overflow: hidden;
      height: 100vh;
    }

    /* ── Topbar ── */
    .oc-topbar {
      height: 52px;
      background: #0f172a;
      margin: 6px 0;
      display: flex;
      align-items: center;
      padding: 0 20px;
    }

    .oc-topbar-title {
      color: #fff;
      font-size: 15px;
      font-weight: 700;
      letter-spacing: 0.3px;
    }

    /* ── Main area ── */
    .oc-main {
      background: #fff;
      border-radius: 12px;
      margin: 0 6px 6px 6px;
      height: calc(100vh - 70px);
      display: flex;
      align-items: flex-start;
      justify-content: center;
      overflow-y: auto;
    }

    /* ── Install wrapper ── */
    .oc-install-wrapper {
      width: 100%;
      max-width: 480px;
      padding: 32px 24px;
    }

    /* ── Icon area ── */
    .oc-icon-area {
      text-align: center;
      margin-bottom: 20px;
    }

    .oc-icon-circle {
      width: 56px;
      height: 56px;
      border-radius: 50%;
      background: #fdf2f8;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 16px;
    }

    .oc-icon-circle svg {
      width: 28px;
      height: 28px;
      color: #F42182;
    }

    .oc-title {
      font-size: 20px;
      font-weight: 700;
      color: #1a1a2e;
      margin-bottom: 4px;
    }

    .oc-subtitle {
      font-size: 13px;
      color: #71717a;
    }

    /* ── Card ── */
    .oc-card {
      background: #fff;
      border: 1px solid #f0e8eb;
      border-radius: 12px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
      padding: 32px;
    }

    /* ── Step indicator ── */
    .oc-steps {
      display: flex;
      justify-content: center;
      align-items: center;
      margin-bottom: 8px;
      gap: 0;
    }

    .oc-step-dot {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 13px;
      background: #f0e8eb;
      color: #a1a1aa;
      flex-shrink: 0;
      transition: all 0.2s;
    }

    .oc-step-dot.active {
      background: #F42182;
      color: #fff;
    }

    .oc-step-dot.done {
      background: #F42182;
      color: #fff;
    }

    .oc-step-line {
      flex: 1;
      height: 2px;
      background: #f0e8eb;
      max-width: 40px;
    }

    .oc-step-line.done {
      background: #F42182;
    }

    .oc-step-labels {
      display: flex;
      justify-content: center;
      gap: 16px;
      margin-bottom: 20px;
    }

    .oc-step-label {
      font-size: 10px;
      color: #a1a1aa;
      text-align: center;
      min-width: 60px;
    }

    .oc-step-label.active {
      color: #F42182;
      font-weight: 600;
    }

    /* ── Form elements ── */
    .oc-form-group {
      margin-bottom: 16px;
    }

    .oc-label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      color: #374151;
      margin-bottom: 6px;
    }

    .oc-input {
      width: 100%;
      height: 36px;
      border: 1px solid #d4c4cb;
      border-radius: 4px;
      padding: 0 10px;
      font-size: 13px;
      font-family: inherit;
      color: #1a1a2e;
      background: #fff;
      transition: border-color 0.15s, box-shadow 0.15s;
      outline: none;
    }

    .oc-input::placeholder {
      color: #a1a1aa;
    }

    .oc-input:focus {
      border-color: #F42182;
      box-shadow: 0 0 0 3px rgba(196,87,122,0.1);
    }

    .oc-form-hint {
      font-size: 11px;
      color: #a1a1aa;
      margin-top: 4px;
    }

    /* ── Button ── */
    .oc-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      height: 36px;
      background: #F42182;
      color: #fff;
      border: none;
      border-radius: 4px;
      font-size: 13px;
      font-weight: 700;
      font-family: inherit;
      cursor: pointer;
      transition: background 0.15s;
      text-decoration: none;
    }

    .oc-btn:hover {
      background: #a8476a;
      color: #fff;
    }

    .oc-btn:active {
      background: #933d5c;
    }

    .oc-btn:disabled {
      background: #d4c4cb;
      cursor: not-allowed;
    }

    /* ── Error messages ── */
    .oc-error-list {
      border: 1px solid #BA1A1A;
      background: transparent;
      border-radius: 4px;
      padding: 12px 14px;
      margin-bottom: 16px;
    }

    .oc-error-list ul {
      margin: 0;
      padding: 0 0 0 18px;
      font-size: 13px;
      color: #BA1A1A;
    }

    .oc-error-list li {
      margin-bottom: 2px;
    }

    /* ── Alert boxes ── */
    .oc-alert {
      border-radius: 4px;
      padding: 10px 14px;
      margin-bottom: 16px;
      font-size: 13px;
    }

    .oc-alert-success {
      border: 1px solid #16a34a;
      color: #16a34a;
      background: #f0fdf4;
    }

    .oc-alert-warning {
      border: 1px solid #d97706;
      color: #92400e;
      background: #fffbeb;
    }

    .oc-alert-info {
      color: #71717a;
      font-size: 13px;
      margin-bottom: 16px;
    }

    /* ── Password checks ── */
    .oc-pw-checks {
      display: flex;
      flex-direction: column;
      gap: 3px;
      margin-top: 6px;
    }

    .oc-pw-check {
      font-size: 12px;
      color: #a1a1aa;
      display: flex;
      align-items: center;
      gap: 6px;
      transition: color 0.15s;
    }

    .oc-pw-icon {
      font-size: 13px;
      width: 16px;
      text-align: center;
    }

    .oc-pw-check.pw-ok {
      color: #16a34a;
      font-weight: 600;
    }

    .oc-pw-check.pw-fail {
      color: #a1a1aa;
    }

    /* ── Success icon ── */
    .oc-success-icon {
      width: 64px;
      height: 64px;
      margin: 0 auto 16px;
      background: #16a34a;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .oc-success-icon svg {
      width: 32px;
      height: 32px;
      color: #fff;
    }

    .oc-success-title {
      font-size: 18px;
      font-weight: 700;
      color: #16a34a;
      margin-bottom: 4px;
    }

    .oc-success-subtitle {
      font-size: 13px;
      color: #71717a;
    }

    /* ── Summary list ── */
    .oc-summary {
      list-style: none;
      padding: 0;
      margin: 20px 0;
    }

    .oc-summary li {
      padding: 8px 0;
      border-bottom: 1px solid #f0e8eb;
      display: flex;
      justify-content: space-between;
      font-size: 13px;
    }

    .oc-summary li:last-child {
      border-bottom: none;
    }

    .oc-summary .oc-sum-label {
      color: #71717a;
      font-weight: 500;
    }

    .oc-summary .oc-sum-value {
      color: #1a1a2e;
      font-weight: 600;
    }

    .oc-sum-value.oc-text-success {
      color: #16a34a;
    }

    /* ── Env manual ── */
    .oc-env-manual {
      background: #1a1a2e;
      color: #e2e8f0;
      border-radius: 6px;
      padding: 14px;
      font-family: 'Courier New', monospace;
      font-size: 12px;
      white-space: pre-wrap;
      word-break: break-all;
    }

    /* ── Footer ── */
    .oc-footer {
      text-align: center;
      margin-top: 20px;
      font-size: 12px;
      color: #a1a1aa;
    }

    /* ── DB mode selector ── */
    .oc-mode-selector {
      display: flex;
      gap: 8px;
      margin-bottom: 20px;
    }

    .oc-mode-btn {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 10px 12px;
      border: 2px solid #e5e7eb;
      border-radius: 8px;
      background: #fff;
      color: #71717a;
      font-size: 12px;
      font-weight: 600;
      font-family: inherit;
      cursor: pointer;
      transition: all 0.2s;
    }

    .oc-mode-btn:hover {
      border-color: #d4c4cb;
      color: #374151;
    }

    .oc-mode-btn.active {
      border-color: #F42182;
      background: #fdf2f8;
      color: #F42182;
    }

    /* ── Prerequisites checklist ── */
    .oc-check-list {
      list-style: none;
      padding: 0;
      margin: 0 0 20px 0;
    }

    .oc-check-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 0;
      border-bottom: 1px solid #f0e8eb;
      font-size: 13px;
    }

    .oc-check-item:last-child {
      border-bottom: none;
    }

    .oc-check-badge {
      width: 24px;
      height: 24px;
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .oc-check-badge svg {
      width: 14px;
      height: 14px;
    }

    .oc-check-badge.ok {
      background: #dcfce7;
      color: #16a34a;
    }

    .oc-check-badge.fail {
      background: #fef2f2;
      color: #dc2626;
    }

    .oc-check-badge.warn {
      background: #fffbeb;
      color: #d97706;
    }

    .oc-check-info {
      flex: 1;
      min-width: 0;
    }

    .oc-check-label {
      font-weight: 600;
      color: #374151;
    }

    .oc-check-detail {
      font-size: 11px;
      color: #a1a1aa;
      margin-top: 1px;
    }

    .oc-check-tag {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      padding: 2px 6px;
      border-radius: 4px;
      flex-shrink: 0;
    }

    .oc-check-tag.required {
      background: #fef2f2;
      color: #dc2626;
    }

    .oc-check-tag.recommended {
      background: #fffbeb;
      color: #d97706;
    }

    .oc-prereq-summary {
      text-align: center;
      padding: 12px;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 16px;
    }

    .oc-prereq-summary.all-ok {
      background: #dcfce7;
      color: #16a34a;
    }

    .oc-prereq-summary.has-errors {
      background: #fef2f2;
      color: #dc2626;
    }

    .oc-btn-secondary {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      height: 36px;
      background: #fff;
      color: #F42182;
      border: 2px solid #F42182;
      border-radius: 4px;
      font-size: 13px;
      font-weight: 700;
      font-family: inherit;
      cursor: pointer;
      transition: background 0.15s;
      text-decoration: none;
    }

    .oc-btn-secondary:hover {
      background: #fdf2f8;
    }

    /* ── Responsive ── */
    @media (max-width: 480px) {
      .oc-topbar { padding: 0 12px; }
      .oc-main { margin: 0 4px 4px 4px; border-radius: 10px; height: calc(100vh - 66px); }
      .oc-install-wrapper { padding: 24px 16px; }
      .oc-card { padding: 24px 20px; }
    }
  </style>
</head>
<body>

  <!-- Topbar -->
  <div class="oc-topbar">
    <span class="oc-topbar-title">Forbach en Rose</span>
  </div>

  <!-- Main area -->
  <div class="oc-main">
    <div class="oc-install-wrapper">

      <!-- Icon area -->
      <div class="oc-icon-area">
        <div class="oc-icon-circle">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
          </svg>
        </div>
        <h1 class="oc-title">Installation</h1>
        <p class="oc-subtitle">Configuration de Forbach en Rose</p>
      </div>

      <!-- Step indicator -->
      <div class="oc-steps">
        <?php foreach ([1, 2, 3, 4] as $i): ?>
          <?php if ($i > 1): ?>
            <div class="oc-step-line <?= $displayStep > $i - 1 ? 'done' : '' ?>"></div>
          <?php endif; ?>
          <div class="oc-step-dot <?= $displayStep === $i ? 'active' : ($displayStep > $i ? 'done' : '') ?>">
            <?php if ($displayStep > $i): ?>
              <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
              </svg>
            <?php else: ?>
              <?= $i ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="oc-step-labels">
        <?php foreach ($stepLabels as $i => $label): ?>
          <span class="oc-step-label <?= $displayStep === $i ? 'active' : '' ?>"><?= $label ?></span>
        <?php endforeach; ?>
      </div>

      <!-- Card -->
      <div class="oc-card">

        <?php if (!empty($errors)): ?>
          <div class="oc-error-list">
            <ul>
              <?php foreach ($errors as $e): ?>
                <li><?= $e ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php // ─── ETAPE 1 : Prérequis PHP ────────────────── ?>
        <?php if ($displayStep === 1): ?>

          <?php
            $reqOk   = 0;
            $reqFail = 0;
            $recWarn = 0;
            foreach ($phpChecks as $c) {
                if ($c['required'] && $c['ok'])  $reqOk++;
                if ($c['required'] && !$c['ok']) $reqFail++;
                if (!$c['required'] && !$c['ok']) $recWarn++;
            }
          ?>

          <?php if ($allRequired): ?>
            <div class="oc-prereq-summary all-ok">
              Tous les pr&eacute;requis sont satisfaits (<?= $reqOk ?>/<?= $reqOk ?>)
            </div>
          <?php else: ?>
            <div class="oc-prereq-summary has-errors">
              <?= $reqFail ?> extension(s) requise(s) manquante(s)
            </div>
          <?php endif; ?>

          <ul class="oc-check-list">
            <?php foreach ($phpChecks as $c): ?>
              <li class="oc-check-item">
                <div class="oc-check-badge <?= $c['ok'] ? 'ok' : ($c['required'] ? 'fail' : 'warn') ?>">
                  <?php if ($c['ok']): ?>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                  <?php else: ?>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                  <?php endif; ?>
                </div>
                <div class="oc-check-info">
                  <div class="oc-check-label"><?= htmlspecialchars($c['label']) ?></div>
                  <div class="oc-check-detail"><?= htmlspecialchars($c['detail']) ?></div>
                </div>
                <?php if (!$c['ok']): ?>
                  <span class="oc-check-tag <?= $c['required'] ? 'required' : 'recommended' ?>">
                    <?= $c['required'] ? 'Requis' : 'Recommand&eacute;' ?>
                  </span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>

          <?php if ($allRequired): ?>
            <form method="post">
              <input type="hidden" name="step" value="2">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <button type="submit" class="oc-btn">Suivant</button>
            </form>
          <?php else: ?>
            <a href="install.php" class="oc-btn-secondary">R&eacute;actualiser</a>
          <?php endif; ?>

        <?php // ─── ETAPE 2 : Base de donnees ───────────────── ?>
        <?php elseif ($displayStep === 2): ?>

          <!-- Sélecteur de mode -->
          <div class="oc-mode-selector">
            <button type="button" class="oc-mode-btn active" id="modeNewBtn">
              <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
              Nouvelle installation
            </button>
            <button type="button" class="oc-mode-btn" id="modeExistingBtn">
              <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
              BDD existante
            </button>
          </div>

          <!-- Champs communs -->
          <form method="post" novalidate id="dbForm">
            <input type="hidden" name="step" value="3">
            <input type="hidden" name="db_mode" value="new" id="dbModeInput">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <div class="oc-form-group">
              <label class="oc-label">H&ocirc;te MySQL</label>
              <input name="db_host" id="dbHost" class="oc-input"
                     value="<?= htmlspecialchars($_POST['db_host'] ?? $existing['DB_HOST'] ?? 'localhost') ?>"
                     placeholder="localhost" required>
            </div>

            <div class="oc-form-group">
              <label class="oc-label">Utilisateur MySQL</label>
              <input name="db_user" id="dbUser" class="oc-input"
                     value="<?= htmlspecialchars($_POST['db_user'] ?? $existing['DB_USER'] ?? 'root') ?>"
                     placeholder="root" required>
            </div>

            <div class="oc-form-group">
              <label class="oc-label">Mot de passe MySQL</label>
              <input type="password" name="db_pass" id="dbPassInput" class="oc-input"
                     value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>"
                     placeholder="Mot de passe">
            </div>

            <!-- Mode NOUVELLE BDD -->
            <div id="panelNew">
              <div class="oc-form-group">
                <label class="oc-label">Nom de la nouvelle base</label>
                <input name="db_name" class="oc-input"
                       value="<?= htmlspecialchars($_POST['db_name'] ?? 'ForbachEnRose') ?>"
                       placeholder="ForbachEnRose" required>
                <div class="oc-form-hint">La base sera cr&eacute;&eacute;e si elle n'existe pas.</div>
              </div>
            </div>

            <!-- Mode BDD EXISTANTE -->
            <div id="panelExisting" style="display:none">
              <div class="oc-form-group">
                <button type="button" class="oc-btn-secondary" id="btnLoadDbs">
                  <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right:6px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>
                  Tester la connexion et charger les bases
                </button>
              </div>

              <div id="dbListWrapper" style="display:none">
                <div class="oc-form-group">
                  <label class="oc-label">Base de donn&eacute;es</label>
                  <select name="db_name_existing" id="dbNameExisting" class="oc-input">
                    <option value="">-- Connexion requise --</option>
                  </select>
                </div>

                <div class="oc-form-group">
                  <label class="oc-label">Cl&eacute; de chiffrement (ENCRYPTION_KEY)</label>
                  <input name="encryption_key" class="oc-input" id="encKeyInput"
                         placeholder="Collez votre ENCRYPTION_KEY ici" required>
                  <div class="oc-form-hint">
                    Indispensable pour d&eacute;chiffrer les donn&eacute;es existantes. Consultez votre ancien fichier <code>config/.env</code>.
                  </div>
                </div>
              </div>

              <div id="dbConnError" style="display:none" class="oc-error-list">
                <ul><li id="dbConnErrorMsg"></li></ul>
              </div>

              <div id="dbConnSuccess" style="display:none" class="oc-alert oc-alert-success"></div>
            </div>

            <div style="margin-top:20px">
              <button type="submit" class="oc-btn" id="btnDbSubmit">
                <span id="btnDbLabel">Cr&eacute;er la base et continuer</span>
              </button>
            </div>
          </form>

          <script nonce="<?= $csp_nonce ?>">
          var currentDbMode = 'new';

          document.getElementById('modeNewBtn').addEventListener('click', function() { switchDbMode('new'); });
          document.getElementById('modeExistingBtn').addEventListener('click', function() { switchDbMode('existing'); });
          document.getElementById('btnLoadDbs').addEventListener('click', function() { loadDatabases(); });

          function switchDbMode(mode) {
              currentDbMode = mode;
              document.getElementById('dbModeInput').value = mode;
              document.getElementById('panelNew').style.display = mode === 'new' ? '' : 'none';
              document.getElementById('panelExisting').style.display = mode === 'existing' ? '' : 'none';
              document.getElementById('modeNewBtn').classList.toggle('active', mode === 'new');
              document.getElementById('modeExistingBtn').classList.toggle('active', mode === 'existing');

              if (mode === 'new') {
                  document.getElementById('btnDbLabel').innerHTML = 'Cr&eacute;er la base et continuer';
              } else {
                  document.getElementById('btnDbLabel').innerHTML = 'Connecter et terminer';
              }
          }

          function loadDatabases() {
              var btn = document.getElementById('btnLoadDbs');
              var host = document.getElementById('dbHost').value;
              var user = document.getElementById('dbUser').value;
              var pass = document.getElementById('dbPassInput').value;

              btn.disabled = true;
              btn.innerHTML = 'Connexion en cours...';
              document.getElementById('dbConnError').style.display = 'none';
              document.getElementById('dbConnSuccess').style.display = 'none';

              var fd = new FormData();
              fd.append('ajax_action', 'list_databases');
              fd.append('step', '0');
              fd.append('db_host', host);
              fd.append('db_user', user);
              fd.append('db_pass', pass);
              fd.append('csrf_token', '<?= htmlspecialchars($csrf) ?>');

              fetch('install.php', { method: 'POST', body: fd })
                  .then(function(r) { return r.json(); })
                  .then(function(data) {
                      btn.disabled = false;
                      btn.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right:6px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg> Tester la connexion et charger les bases';
                      if (data.ok) {
                          var sel = document.getElementById('dbNameExisting');
                          sel.innerHTML = '<option value="">-- S&eacute;lectionnez une base --</option>';
                          data.databases.forEach(function(db) {
                              var opt = document.createElement('option');
                              opt.value = db;
                              opt.textContent = db;
                              sel.appendChild(opt);
                          });
                          document.getElementById('dbListWrapper').style.display = '';
                          document.getElementById('dbConnSuccess').style.display = '';
                          document.getElementById('dbConnSuccess').textContent = 'Connexion r\u00e9ussie — ' + data.databases.length + ' base(s) trouv\u00e9e(s)';
                      } else {
                          document.getElementById('dbListWrapper').style.display = 'none';
                          document.getElementById('dbConnError').style.display = '';
                          document.getElementById('dbConnErrorMsg').textContent = data.error;
                      }
                  })
                  .catch(function(err) {
                      btn.disabled = false;
                      btn.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right:6px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg> Tester la connexion et charger les bases';
                      document.getElementById('dbConnError').style.display = '';
                      document.getElementById('dbConnErrorMsg').textContent = 'Erreur r\u00e9seau : ' + err.message;
                  });
          }
          </script>

        <?php // ─── ETAPE 3 : Compte administrateur ─────────── ?>
        <?php elseif ($displayStep === 3): ?>

          <?php if ($dbSuccess): ?>
            <?php if (!empty($_SESSION['install']['db_existed'])): ?>
              <div class="oc-alert oc-alert-warning">
                La base de donn&eacute;es <strong><?= htmlspecialchars($_SESSION['install']['db_name'] ?? '') ?></strong> existait d&eacute;j&agrave; avec <?= (int)($_SESSION['install']['db_existing_tables'] ?? 0) ?> table(s). Les tables manquantes ont &eacute;t&eacute; ajout&eacute;es.
              </div>
            <?php else: ?>
              <div class="oc-alert oc-alert-success">
                Base de donn&eacute;es configur&eacute;e avec succ&egrave;s ! Toutes les tables ont &eacute;t&eacute; cr&eacute;&eacute;es.
              </div>
            <?php endif; ?>
          <?php endif; ?>

          <p class="oc-alert-info">Cr&eacute;ez le compte administrateur principal.</p>

          <form method="post" novalidate id="adminForm">
            <input type="hidden" name="step" value="4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <div class="oc-form-group">
              <label class="oc-label">Adresse email</label>
              <input name="admin_email" type="email" class="oc-input"
                     value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>"
                     placeholder="admin@example.com" required autofocus>
            </div>

            <div class="oc-form-group">
              <label class="oc-label">Mot de passe</label>
              <input type="password" name="admin_password" id="adminPass" class="oc-input"
                     placeholder="Min. 14 car., majuscule, chiffre, sp&eacute;cial" required>
              <div class="oc-pw-checks">
                <div class="oc-pw-check" id="ck-length"><span class="oc-pw-icon">&#9675;</span> 14 caract&egrave;res minimum</div>
                <div class="oc-pw-check" id="ck-upper"><span class="oc-pw-icon">&#9675;</span> Une majuscule</div>
                <div class="oc-pw-check" id="ck-digit"><span class="oc-pw-icon">&#9675;</span> Un chiffre</div>
                <div class="oc-pw-check" id="ck-special"><span class="oc-pw-icon">&#9675;</span> Un caract&egrave;re sp&eacute;cial</div>
              </div>
            </div>

            <div class="oc-form-group" style="margin-bottom:20px">
              <label class="oc-label">Confirmer le mot de passe</label>
              <input type="password" name="admin_password_confirm" id="adminPassConfirm" class="oc-input"
                     placeholder="Retapez le mot de passe" required>
              <div class="oc-pw-checks">
                <div class="oc-pw-check" id="ck-match"><span class="oc-pw-icon">&#9675;</span> Les mots de passe correspondent</div>
              </div>
            </div>

            <button type="submit" class="oc-btn" id="btnSubmitAdmin" disabled>
              Cr&eacute;er le compte et terminer
            </button>
          </form>

          <script nonce="<?= $csp_nonce ?>">
          (function() {
            var pass  = document.getElementById('adminPass');
            var conf  = document.getElementById('adminPassConfirm');
            var btn   = document.getElementById('btnSubmitAdmin');
            var checks = {
              length:  document.getElementById('ck-length'),
              upper:   document.getElementById('ck-upper'),
              digit:   document.getElementById('ck-digit'),
              special: document.getElementById('ck-special'),
              match:   document.getElementById('ck-match')
            };

            function setCheck(el, ok) {
              el.classList.toggle('pw-ok', ok);
              el.classList.toggle('pw-fail', !ok);
              el.querySelector('.oc-pw-icon').innerHTML = ok ? '&#10003;' : '&#9675;';
            }

            function validate() {
              var v = pass.value;
              var c = conf.value;
              var ok = {
                length:  v.length >= 14,
                upper:   /[A-Z]/.test(v),
                digit:   /[0-9]/.test(v),
                special: /[^a-zA-Z0-9]/.test(v),
                match:   v.length > 0 && v === c
              };
              for (var k in ok) setCheck(checks[k], ok[k]);
              btn.disabled = !(ok.length && ok.upper && ok.digit && ok.special && ok.match);
            }

            pass.addEventListener('input', validate);
            conf.addEventListener('input', validate);
          })();
          </script>

        <?php // ─── ETAPE 4 : Termine ───────────────────────── ?>
        <?php elseif ($displayStep === 4): ?>

          <?php if (isset($_SESSION['env_manual'])): ?>
            <div class="oc-alert oc-alert-warning">
              Le dossier <code>config/</code> n'est pas accessible en &eacute;criture.
              Cr&eacute;ez manuellement le fichier <code>config/.env</code> avec le contenu suivant :
            </div>
            <div class="oc-env-manual"><?= htmlspecialchars($_SESSION['env_manual']) ?></div>
            <?php unset($_SESSION['env_manual']); ?>
          <?php else: ?>
            <div style="text-align:center;margin-bottom:20px">
              <div class="oc-success-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                </svg>
              </div>
              <h4 class="oc-success-title">Installation termin&eacute;e !</h4>
              <p class="oc-success-subtitle">Votre site est pr&ecirc;t &agrave; &ecirc;tre utilis&eacute;.</p>
            </div>

            <?php $wasExisting = ($_SESSION['install']['db_mode'] ?? '') === 'existing'; ?>
            <ul class="oc-summary">
              <li>
                <span class="oc-sum-label">Mode</span>
                <span class="oc-sum-value"><?= $wasExisting ? 'BDD existante' : 'Nouvelle installation' ?></span>
              </li>
              <li>
                <span class="oc-sum-label">Administrateur</span>
                <span class="oc-sum-value"><?= htmlspecialchars($_SESSION['install_admin'] ?? 'admin') ?></span>
              </li>
              <li>
                <span class="oc-sum-label">Fichier .env</span>
                <span class="oc-sum-value oc-text-success">G&eacute;n&eacute;r&eacute;</span>
              </li>
              <?php if (!$wasExisting): ?>
              <li>
                <span class="oc-sum-label">Tables</span>
                <span class="oc-sum-value oc-text-success">Cr&eacute;&eacute;es</span>
              </li>
              <?php else: ?>
              <li>
                <span class="oc-sum-label">Base</span>
                <span class="oc-sum-value oc-text-success">Connect&eacute;e</span>
              </li>
              <?php endif; ?>
            </ul>

            <a href="login.php" class="oc-btn">
              Acc&eacute;der au site
            </a>
          <?php endif; ?>

          <?php
            // Nettoyage session
            unset($_SESSION['install_done'], $_SESSION['install_admin'], $_SESSION['csrf_install']);
          ?>

        <?php endif; ?>

      </div>

      <!-- Footer -->
      <div class="oc-footer">
        Forbach en Rose &mdash; Assistant d'installation
      </div>

    </div>
  </div>

</body>
</html>
