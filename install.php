<?php
/**
 * Assistant d'installation — Forbach en Rose v2.0.0
 * Accessible uniquement si la configuration chiffrée (config/config.enc)
 * est absente ou incomplète.
 */
ob_start();

require_once __DIR__ . '/src/core/secure.php';

// ── SECURITE : bloquer si déjà installé ─────────────────────
// 🔒 [SEC-13] Double verrou config.enc + .install.lock (CWE-749)
$confPath = FerSecureConfig::configFile();      // config/config.enc
$lockPath = __DIR__ . '/config/.install.lock';

if (file_exists($lockPath) && file_exists($confPath)) {
    header('Location: login.php');
    exit;
}
if (file_exists($confPath)) {
    // config.enc présent : on considère le site installé (s'il est corrompu,
    // config.php affiche un message explicite — surtout ne pas réinstaller).
    if (!file_exists($lockPath)) {
        @file_put_contents($lockPath, date('Y-m-d H:i:s') . ' — installed');
    }
    header('Location: login.php');
    exit;
}
// Compat migration (site ≤ 1.4.0 mis à jour mais pas encore migré) : un ancien
// config/.env présent = site déjà installé → surtout ne pas réinstaller par-dessus.
// La migration vers config.enc se fait toute seule au premier chargement de page.
// (Supprimable en v3, quand plus aucun site ≤ 1.4.0 n'existera.)
if (file_exists(__DIR__ . '/config/.env')) {
    header('Location: login.php');
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    // Forcer un save_path accessible en écriture
    $candidates = [
        __DIR__ . '/storage/sessions',
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


// 🔒 [SEC-INFO] Les endpoints de diagnostic ?phpinfo / ?phpdiag ont été retirés :
// avant installation (config.enc absent), ils étaient accessibles sans authentification et
// divulguaient versions, chemins et configuration serveur (aide à l'intrusion).

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
        'label'    => 'storage/ accessible en écriture (logs)',
        'detail'   => __DIR__ . '/storage/',
        'ok'       => is_dir(__DIR__ . '/storage') ? is_writable(__DIR__ . '/storage') : is_writable(__DIR__),
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
                        // Mode existant : écrire config.enc + master.key directement (pas d'étape admin)
                        $configDir = __DIR__ . '/config';
                        if (!is_writable($configDir)) {
                            $_SESSION['config_write_error'] = true;
                        } else {
                            FerSecureConfig::write([
                                'DB_HOST'        => $dbHost,
                                'DB_NAME'        => $dbName,
                                'DB_USER'        => $dbUser,
                                'DB_PASS'        => $dbPass,
                                'ENCRYPTION_KEY' => $encryptionKey,
                                // Clé HMAC des adresses des comptes coureurs (lot 1). Vit ici et
                                // JAMAIS en base : un dump compromis livrerait sinon les empreintes
                                // ET le moyen de les recalculer. À sauvegarder avec ENCRYPTION_KEY.
                                'EMAIL_HMAC_KEY' => bin2hex(random_bytes(32)),
                            ]);
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
                            'encryption_key' => $encryptionKey,
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

// --- Étape 3 → 4 : créer admin + écrire config.enc ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (int) ($_POST['step'] ?? 0) === 4) {
    checkCsrf();
    $step = 4;

    if (!isset($_SESSION['install'])) {
        $errors[] = "Session expirée. Veuillez recommencer l'installation.";
        $step = 2;
    } else {
        $inst = $_SESSION['install'];

        if (($inst['db_mode'] ?? 'new') === 'existing') {
            // ── Mode BDD existante : pas d'admin à créer, on écrit config.enc ──
            $configDir = __DIR__ . '/config';
            if (!is_writable($configDir)) {
                $_SESSION['config_write_error'] = true;
                $step = 4;
            } else {
                FerSecureConfig::write([
                    'DB_HOST'        => $inst['db_host'],
                    'DB_NAME'        => $inst['db_name'],
                    'DB_USER'        => $inst['db_user'],
                    'DB_PASS'        => $inst['db_pass'],
                    'ENCRYPTION_KEY' => $inst['encryption_key'] ?? '',
                    // Clé HMAC des adresses des comptes coureurs (lot 1). Vit ici et
                    // JAMAIS en base : un dump compromis livrerait sinon les empreintes
                    // ET le moyen de les recalculer. À sauvegarder avec ENCRYPTION_KEY.
                    'EMAIL_HMAC_KEY' => bin2hex(random_bytes(32)),
                ]);
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

                        $configDir = __DIR__ . '/config';
                        if (!is_writable($configDir)) {
                            $_SESSION['config_write_error'] = true;
                            $step = 4;
                        } else {
                            FerSecureConfig::write([
                                'DB_HOST'        => $inst['db_host'],
                                'DB_NAME'        => $inst['db_name'],
                                'DB_USER'        => $inst['db_user'],
                                'DB_PASS'        => $inst['db_pass'],
                                'ENCRYPTION_KEY' => $encryptionKey,
                                // Clé HMAC des adresses des comptes coureurs (lot 1). Vit ici et
                                // JAMAIS en base : un dump compromis livrerait sinon les empreintes
                                // ET le moyen de les recalculer. À sauvegarder avec ENCRYPTION_KEY.
                                'EMAIL_HMAC_KEY' => bin2hex(random_bytes(32)),
                            ]);
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
          `assoconnect_url` varchar(512) DEFAULT NULL,
          `assoconnect_csp_domains` text DEFAULT NULL,
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
          `flash_info_mode` ENUM('on','off','auto') NOT NULL DEFAULT 'off',
          `flash_info_start` DATETIME DEFAULT NULL,
          `flash_info_end` DATETIME DEFAULT NULL,
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
          `session_lifetime` INT NOT NULL DEFAULT 0,
          `session_absolute_lifetime` INT NOT NULL DEFAULT 0,
          `mail_template_config` TEXT DEFAULT NULL,
          `theme_primary_color` VARCHAR(7) DEFAULT '#db2777',
          `theme_secondary_color` VARCHAR(7) DEFAULT '#0f172a',
          /* ⚠️ `theme_dark_enabled` a été retiré : écrit nulle part, lu nulle
           * part. Le thème sombre est piloté par les couleurs dédiées
           * (`theme_dark_primary_color`, `theme_dark_secondary_color`) et par la
           * préférence du navigateur, pas par cet interrupteur — qui n'a jamais
           * été branché. Ne pas le remettre « au cas où ». */
          `flash_bg_color` VARCHAR(7) DEFAULT '#db2777',
          `flash_text_color` VARCHAR(7) DEFAULT '#ffffff',
          `theme_dark_primary_color` VARCHAR(7) DEFAULT '#f472b6',
          `theme_dark_secondary_color` VARCHAR(7) DEFAULT '#e2e8f0',
          `theme_border_radius` INT DEFAULT 12,
          `theme_font_family` VARCHAR(100) DEFAULT 'Inter',
          /* Couleurs des trois grands aplats de la page publique.
           * VIDE = « couleur du thème » — jamais une valeur recopiée : si
           * quelqu un change la couleur secondaire, le bandeau et le pied de
           * page doivent suivre sans qu on ait à les retoucher un par un.
           * Une valeur ici veut dire « cet élément a SA couleur ». */
          `color_news_band` VARCHAR(7) DEFAULT NULL,
          `color_partners` VARCHAR(7) DEFAULT NULL,
          `color_footer` VARCHAR(7) DEFAULT NULL,
          `color_newsletter` VARCHAR(7) DEFAULT NULL,
          `color_newsletter_deco` VARCHAR(7) DEFAULT NULL,
          `footer_logo` VARCHAR(255) DEFAULT 'logo_blanc.png',
          `footer_logo_height` INT DEFAULT 56,
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
          `notify_recipients` TEXT DEFAULT NULL,
          `notify_toggles` TEXT DEFAULT NULL,
          `accueil_layout` MEDIUMTEXT DEFAULT NULL,
          `accueil_styles` TEXT DEFAULT NULL,
          `accueil_texts` TEXT DEFAULT NULL,
          `accueil_geometry` TEXT DEFAULT NULL,
          `accueil_layout_draft` MEDIUMTEXT DEFAULT NULL,
          `accueil_styles_draft` TEXT DEFAULT NULL,
          `accueil_texts_draft` TEXT DEFAULT NULL,
          `accueil_geometry_draft` TEXT DEFAULT NULL,
          `accueil_draft_updated_at` DATETIME DEFAULT NULL,
          `start_point_address` VARCHAR(255) DEFAULT NULL,
          `start_point_coords` VARCHAR(64) DEFAULT NULL,
          `role_permissions` TEXT DEFAULT NULL,
          `turnstile_sitekey` VARCHAR(255) DEFAULT NULL,
          `turnstile_secret` TEXT DEFAULT NULL,
          `api_enabled` TINYINT(1) NOT NULL DEFAULT 0,
          `api_user` VARCHAR(64) DEFAULT NULL,
          `api_token` TEXT DEFAULT NULL,
          `child_pricing_enabled` TINYINT(1) NOT NULL DEFAULT 0,
          `child_age_threshold` INT(10) NOT NULL DEFAULT 12,
          `child_amount` INT(10) NOT NULL DEFAULT 0,
          `registration_closed_message` TEXT DEFAULT NULL,
          `course_horaires` TEXT DEFAULT NULL,
          `course_rdv` TEXT DEFAULT NULL,
          `tshirt_retrait_info` TEXT DEFAULT NULL,
          `registration_onsite_info` TEXT DEFAULT NULL,
          `legal_mentions` LONGTEXT DEFAULT NULL,
          `legal_privacy` LONGTEXT DEFAULT NULL,
          `chatbot_enabled` TINYINT(1) NOT NULL DEFAULT 1,
          /* ── Espace coureur & application mobile (lot 1) ──────────────────
           * Les valeurs par défaut sont portées par le DEFAULT de la colonne,
           * jamais par un UPDATE : sur une table à ligne unique, ADD COLUMN …
           * DEFAULT x remplit la ligne existante, ce qui rend l'ajout idempotent
           * par nature côté update.php.
           * Aucun écran de réglage dans ce lot : ces colonnes sont lues par les
           * lots suivants, leur interface viendra avec les fonctionnalités. */
          /* Lot 2 — authentification coureur par code à 6 chiffres */
          `participant_code_ttl_min` SMALLINT NOT NULL DEFAULT 15,
          `participant_code_max_tentatives` TINYINT NOT NULL DEFAULT 5,
          `participant_code_max_par_email_15min` TINYINT NOT NULL DEFAULT 3,
          `participant_code_max_par_ip_heure` TINYINT NOT NULL DEFAULT 10,
          `participant_web_remember_jours` SMALLINT NOT NULL DEFAULT 30,
          `participant_rgpd_version` VARCHAR(20) NOT NULL DEFAULT '1.0',
          /* Lot 4 — transferts d'inscription */
          `transferts_deadline_defaut_h` SMALLINT NOT NULL DEFAULT 24,
          `transferts_expiration_jours` SMALLINT NOT NULL DEFAULT 7,
          /* Lot 5 — API mobile */
          `app_version_minimale` VARCHAR(20) NOT NULL DEFAULT '1.0.0',
          `app_access_token_ttl_min` SMALLINT NOT NULL DEFAULT 60,
          /* Interrupteur de l'API mobile, distinct de celui de api/v1.
           * DÉFAUT 0 : elle est fermée tant qu'on ne l'a pas activée dans
           * Réglages → API.
           * Pas de « clé d'application » : elle serait livrée dans
           * l'application installée sur chaque téléphone, donc lisible par
           * quiconque décompile le fichier. Ce qui protège les données, c'est
           * le jeton personnel de chaque coureur. */
          `api_v1_enabled` TINYINT(1) NOT NULL DEFAULT 0,
          /* Lot 6 — page de téléchargement */
          `app_store_url_ios` VARCHAR(255) NULL DEFAULT NULL,
          `app_store_url_android` VARCHAR(255) NULL DEFAULT NULL,
          /* Lot 7 — purges RGPD. 400 jours et non 365 : il faut couvrir une
           * édition entière PLUS la marge de publication des résultats. Ce
           * chiffre doit figurer dans la politique de confidentialité. */
          /* 0 = CONSERVATION ILLIMITÉE (jamais purgé). C est le défaut : le
           * but est de pouvoir revoir son parcours d une année sur l autre.
           * Tenable parce que le suivi GPS est explicitement consenti et que
           * le coureur peut supprimer ses traces lui-même à tout moment. */
          `traces_gps_conservation_jours` SMALLINT NOT NULL DEFAULT 0,
          `auth_codes_conservation_jours` SMALLINT NOT NULL DEFAULT 30,
          /* Un appareil révoqué n'a plus de jeton valide, mais sa ligne garde
           * le modèle du téléphone et l'IP de création. Les transferts EN
           * ATTENTE ne sont jamais purgés, quel que soit ce délai. */
          `devices_revoques_jours` SMALLINT NOT NULL DEFAULT 90,
          `transferts_clos_jours` SMALLINT NOT NULL DEFAULT 365,
          /* Chronométrage — interrupteur unique, lu par chrono_actif().
           *
           * ⚠️ EN DERNIÈRE POSITION, ET CE N'EST PAS UN DÉTAIL. update.php ne
           * peut qu'AJOUTER une colonne, donc à la fin. Placée ailleurs ici, la
           * base d'un nouveau serveur et celle d'un site migré n'auraient pas le
           * même schéma — c'est exactement ce que docs/audit-bdd.php compare, et
           * ce qu'il a refusé la première fois. Toute nouvelle colonne se met à
           * la suite, des deux côtés.
           *
           * DÉFAUT 0 : hors période de course, l'espace coureur ne sert qu'aux
           * inscriptions. Un onglet « Mes résultats » vide et une demande
           * d'autorisation GPS onze mois sur douze ne servent personne.
           * Désactiver ne supprime RIEN : les temps et les traces restent en
           * base et réapparaissent à la réactivation. */
          `chrono_enabled` TINYINT(1) NOT NULL DEFAULT 0,
          /* Notifications de l'application mobile.
           * Les colonnes se mettent À LA SUITE — cf. le commentaire de
           * `chrono_enabled` ci-dessus : update.php ne sait qu'ajouter à la fin,
           * et docs/audit-bdd.php compare les deux schémas colonne par colonne. */
          `app_notifications_actives` TINYINT(1) NOT NULL DEFAULT 1,
          /* Réveil de l'application avant la course, en minutes.
           * L'application programme une notification locale à
           * `heure_depart - ce délai`, pour rappeler de la lancer et d'activer
           * le suivi. 120 = deux heures : le temps de se préparer et de venir.
           * 0 = pas de réveil. */
          `app_reveil_avant_min` SMALLINT NOT NULL DEFAULT 120,
          /* Firebase Cloud Messaging — l'unique voie pour faire sonner un
           * téléphone. Android et iOS bloquent tout le reste.
           *
           * ⚠️ `fcm_service_account` EST UNE CLÉ PRIVÉE, stockée chiffrée par
           * encrypt(). Quiconque la lit peut envoyer des notifications au nom de
           * l'association. Elle ne doit jamais être réaffichée en clair dans
           * l'administration, ni journalisée. */
          `fcm_project_id` VARCHAR(120) DEFAULT NULL,
          `fcm_service_account` TEXT DEFAULT NULL,
          /* Délai de grâce après l'heure PRÉVUE, en minutes.
           * Passé ce délai sans que le départ ait été donné, le calcul retombe
           * sur l'heure prévue plutôt que de laisser tout le monde sans temps.
           * Avant, on ne publie rien : mieux vaut « en course » qu'un temps faux. */
          `depart_grace_min` SMALLINT NOT NULL DEFAULT 10,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `chatbot_unmatched` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `question` varchar(500) NOT NULL,
          `created_at` timestamp NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `chatbot_faq` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `question` varchar(255) NOT NULL,
          `answer` text NOT NULL,
          `keywords` varchar(500) DEFAULT NULL,
          `position` int(11) NOT NULL DEFAULT 0,
          `active` tinyint(1) NOT NULL DEFAULT 1,
          `created_at` timestamp NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
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
          `totp_secret` VARCHAR(64) DEFAULT NULL,
          `totp_pending_secret` VARCHAR(64) DEFAULT NULL,
          `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
          `default_2fa_method` ENUM('email','totp','passkey') NOT NULL DEFAULT 'email',
          `permissions` TEXT DEFAULT NULL,
          `ui_prefs` TEXT DEFAULT NULL,
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
          `required_admin` tinyint(1) NOT NULL DEFAULT 0,
          `is_locked` tinyint(1) NOT NULL DEFAULT 0,
          `is_default` tinyint(1) NOT NULL DEFAULT 1,
          `visible_public` tinyint(1) NOT NULL DEFAULT 1,
          `visible_admin` tinyint(1) NOT NULL DEFAULT 1,
          `visible_saisie` tinyint(1) NOT NULL DEFAULT 1,
          `visible_qr` tinyint(1) NOT NULL DEFAULT 1,
          `visible_saisie_multiple` tinyint(1) NOT NULL DEFAULT 0,
          `required_saisie_multiple` tinyint(1) NOT NULL DEFAULT 0,
          `sort_order` int(11) NOT NULL DEFAULT 0,
          `options_list` text DEFAULT NULL,
          `encrypted` tinyint(1) NOT NULL DEFAULT 0,
          `help_text` text DEFAULT NULL,
          `guardian_section` tinyint(1) NOT NULL DEFAULT 0,
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
          `newsletter_sent_at` timestamp NULL DEFAULT NULL,
          `deleted_at` timestamp NULL DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `email` varchar(255) NOT NULL,
          `status` enum('subscribed','unsubscribed') NOT NULL DEFAULT 'subscribed',
          `created_at` timestamp NULL DEFAULT current_timestamp(),
          `unsubscribed_at` timestamp NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `email_idx` (`email`)
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
          `ville` varchar(255) NOT NULL DEFAULT '',
          `entreprise` varchar(255) DEFAULT NULL,
          `commentaire` text DEFAULT NULL,
          `origine` varchar(40) DEFAULT 'en ligne',
          `paiement_mode` varchar(50) DEFAULT NULL,
          `prestation` varchar(30) DEFAULT NULL,
          `montant_du` decimal(10,2) NOT NULL DEFAULT 0,
          `created_at` timestamp NULL DEFAULT current_timestamp(),
          `date_inscription` datetime DEFAULT current_timestamp(),
          `created_by` int(11) DEFAULT NULL,
          `group_id` varchar(40) DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `inscription_no` (`inscription_no`),
          KEY `created_by` (`created_by`),
          KEY `group_id` (`group_id`),
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
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
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
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
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
          `onsite_mode` tinyint(1) NOT NULL DEFAULT 0,
          `payment_label` varchar(50) DEFAULT 'retrait t-shirt',
          `expires_at` datetime DEFAULT NULL,
          `send_qrcode` tinyint(1) NOT NULL DEFAULT 1,
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

        "CREATE TABLE IF NOT EXISTS `content_logs` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `content_type` VARCHAR(20) NOT NULL,
          `entity_type` VARCHAR(40) DEFAULT NULL,
          `entity_id` INT DEFAULT NULL,
          `entity_title` VARCHAR(255) DEFAULT NULL,
          `action` VARCHAR(20) NOT NULL,
          `user_id` INT DEFAULT NULL,
          `user_email` VARCHAR(255) DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_content` (`content_type`, `created_at`),
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

        "CREATE TABLE IF NOT EXISTS `user_passkeys` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `credential_id` VARCHAR(1024) NOT NULL,
          `public_key` TEXT NOT NULL,
          `sign_count` INT UNSIGNED NOT NULL DEFAULT 0,
          `name` VARCHAR(100) NOT NULL DEFAULT 'Ma clé d\'accès',
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `last_used` DATETIME DEFAULT NULL,
          UNIQUE KEY `idx_cred` (credential_id(255)),
          INDEX `idx_user` (`user_id`)
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

        // Import automatique AssoConnect : configuration à ligne unique (id=1).
        // Mot de passe AssoConnect chiffré (AES-256-GCM, ENCRYPTION_KEY du site) dans ac_password_enc.
        // Le QR n'est PAS une option : il suit le réglage global qrcode_mail_mode.
        // Le token partagé des endpoints (worker_token) est auto-généré et géré depuis l'UI.
        "CREATE TABLE IF NOT EXISTS `sync_assoconnect` (
          `id` TINYINT(1) NOT NULL DEFAULT 1,
          `enabled` TINYINT(1) NOT NULL DEFAULT 0,
          `ac_login_url` VARCHAR(500) DEFAULT NULL,
          `ac_registrants_url` VARCHAR(500) DEFAULT NULL,
          `ac_email` VARCHAR(190) DEFAULT NULL,
          `ac_password_enc` BLOB DEFAULT NULL,
          `worker_token` VARCHAR(64) DEFAULT NULL,
          `import_send_mail` TINYINT(1) NOT NULL DEFAULT 1,
          `interval_min` INT NOT NULL DEFAULT 30,
          `run_requested` TINYINT(1) NOT NULL DEFAULT 0,
          `test_requested` TINYINT(1) NOT NULL DEFAULT 0,
          `last_run_at` DATETIME DEFAULT NULL,
          `last_status` ENUM('ok','error','running','idle') NOT NULL DEFAULT 'idle',
          `last_message` TEXT DEFAULT NULL,
          `last_rows` INT NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // Accès « Remise T-shirts » pour bénévoles (sans compte).
        // tshirt_access : config à ligne unique (id=1). tshirt_access_sessions : une
        // ligne par appareil (nom + statut de validation, liée au token de campagne).
        // tshirt_handout_log : journal des remises (traçabilité).
        "CREATE TABLE IF NOT EXISTS `tshirt_access` (
          `id` TINYINT(1) NOT NULL DEFAULT 1,
          `enabled` TINYINT(1) NOT NULL DEFAULT 0,
          `campaign_token` VARCHAR(64) DEFAULT NULL,
          `opened_at` DATETIME DEFAULT NULL,
          `expires_at` DATETIME DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS `tshirt_access_sessions` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `campaign_token` VARCHAR(64) NOT NULL,
          `device_id` VARCHAR(64) NOT NULL,
          `volunteer_name` VARCHAR(120) DEFAULT NULL,
          `status` ENUM('pending','approved','refused') NOT NULL DEFAULT 'pending',
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `approved_at` DATETIME DEFAULT NULL,
          `approved_by` INT DEFAULT NULL,
          `expires_at` DATETIME DEFAULT NULL,
          `last_seen` DATETIME DEFAULT NULL,
          `ip` VARCHAR(45) DEFAULT NULL,
          `user_agent` VARCHAR(255) DEFAULT NULL,
          UNIQUE KEY `idx_device_campaign` (`device_id`, `campaign_token`),
          INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS `tshirt_handout_log` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `registration_id` INT DEFAULT NULL,
          `inscription_no` VARCHAR(50) DEFAULT NULL,
          `size` VARCHAR(5) DEFAULT NULL,
          `volunteer_name` VARCHAR(120) DEFAULT NULL,
          `device_id` VARCHAR(64) DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_reg` (`registration_id`),
          INDEX `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        /* ═══════════════════════════════════════════════════════════════════
         * ESPACE COUREUR & APPLICATION MOBILE (lot 1)
         * -------------------------------------------------------------------
         * Ces neuf tables désignent un coureur par sa CLÉ MÉTIER — le couple
         * (annee, inscription_no) — et non par `registrations.id`.
         *
         * POURQUOI : le site archive chaque année (route `archive-current` :
         * création de `registrations_<année>`, recopie, puis vidage de
         * `registrations`). Les `id` techniques changent donc de table tous les
         * ans ; une clé étrangère vers `registrations.id` casserait à chaque
         * archivage. « L'inscrit n°142 de l'édition 2026 » survit, lui.
         *
         * Conséquence assumée : aucune clé étrangère vers `registrations`.
         * L'intégrité est vérifiée par update.php?tool=check-integrity.
         * La table `registrations` n'est PAS modifiée par ce lot.
         * ═══════════════════════════════════════════════════════════════════ */

        // Configuration par année. Ne remplace pas `registrations_stats` : elle
        // ajoute la configuration (date, distance, géo, horaires) que celle-ci
        // ne porte pas.
        // ⏱️ `heure_depart` est stockée EN UTC — c'est l'heure du coup de feu,
        // donc la référence de tous les temps calculés. Renseignée en heure
        // locale face à des arrivées en UTC, tous les chronos seraient faux de
        // deux heures, silencieusement.
        "CREATE TABLE IF NOT EXISTS `editions` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `annee` SMALLINT NOT NULL,
          `libelle` VARCHAR(120) NOT NULL,
          `date_course` DATE DEFAULT NULL,
          `distance_km` DECIMAL(5,2) DEFAULT NULL,
          `heure_depart` DATETIME DEFAULT NULL,
          `lat_depart` DECIMAL(10,7) DEFAULT NULL,
          `lon_depart` DECIMAL(10,7) DEFAULT NULL,
          `lat_arrivee` DECIMAL(10,7) DEFAULT NULL,
          `lon_arrivee` DECIMAL(10,7) DEFAULT NULL,
          `temps_min_plausible_s` INT DEFAULT NULL,
          `transferts_deadline` DATETIME DEFAULT NULL,
          /* ⏱️ LE TOP DE DÉPART RÉEL, en UTC. Vide tant que personne n'a appuyé.
           *
           * `heure_depart` est l'heure PRÉVUE ; celle-ci est l'instant où le
           * départ a effectivement été donné. Les deux sont nécessaires : la
           * première sert au rappel et de filet, la seconde fait foi.
           *
           * Une course part rarement à l'heure. Sans cette colonne, corriger un
           * départ retardé de cinq minutes obligerait à modifier l'heure prévue
           * — et on perdrait au passage l'information « c'était prévu à 11 h ». */
          `depart_reel_at` DATETIME(3) DEFAULT NULL,
          `is_active` TINYINT(1) NOT NULL DEFAULT 0,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `idx_annee` (`annee`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // Comptes coureurs. Table STRICTEMENT distincte de `users`, qui reste
        // réservée à l'administration : deux tables, deux sessions, deux
        // systèmes de jetons. AUCUNE colonne de mot de passe — la connexion se
        // fait uniquement par code à 6 chiffres reçu par mail.
        // Deux colonnes pour une seule adresse, et c'est nécessaire :
        //   • `email_hmac`    : HMAC-SHA256 de l'adresse en minuscules.
        //     Déterministe, donc INDEXABLE et UNIQUE — c'est par lui qu'on
        //     retrouve un compte à la connexion. Un HMAC seul ne suffirait pas :
        //     irréversible, on ne pourrait plus envoyer le code à 6 chiffres.
        //   • `email_chiffre` : chiffré par le MÊME mécanisme que
        //     registrations.email (AES-256-GCM, IV aléatoire). Nécessaire pour
        //     retrouver l'adresse en clair au moment de l'envoi. Un chiffrement
        //     seul ne suffirait pas : l'IV aléatoire rend toute recherche par
        //     égalité impossible.
        // La clé HMAC vit dans config/config.enc, JAMAIS en base — sinon un dump
        // compromis livre à la fois les empreintes et le moyen de les recalculer.
        "CREATE TABLE IF NOT EXISTS `participants` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `email_chiffre` TEXT NOT NULL,
          `email_hmac` CHAR(64) NOT NULL,
          `nom` VARCHAR(255) DEFAULT NULL,
          `prenom` VARCHAR(255) DEFAULT NULL,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `rgpd_consent_at` DATETIME DEFAULT NULL,
          `rgpd_consent_version` VARCHAR(20) DEFAULT NULL,
          `derniere_connexion` DATETIME DEFAULT NULL,
          `theme` ENUM('light','dark','system') NOT NULL DEFAULT 'light',
          `accent` VARCHAR(20) NOT NULL DEFAULT 'rose',
          `accent_custom` VARCHAR(7) DEFAULT NULL,
          /* Consentement explicite au suivi GPS. Une trace dit où une personne
           * se trouvait minute par minute : NULL = pas de consentement, donc
           * aucune trace enregistrée. C'est le défaut le plus protecteur. */
          `traces_consent_at` DATETIME DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `idx_email_hmac` (`email_hmac`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // LE LIEN, cœur du dispositif : il remplace la colonne
        // `registrations.participant_id` que l'on ne crée pas.
        // L'index UNIQUE (annee, inscription_no) garantit qu'une inscription
        // appartient à UN compte au maximum : deux comptes ne pourront jamais
        // revendiquer le même coureur le jour de la course.
        // La clé étrangère vers `participants` est légitime : table maîtrisée,
        // jamais archivée.
        "CREATE TABLE IF NOT EXISTS `participant_registrations` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `participant_id` INT NOT NULL,
          `annee` SMALLINT NOT NULL,
          `inscription_no` VARCHAR(50) NOT NULL,
          `revendique_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `origine` ENUM('email','transfert','admin') NOT NULL DEFAULT 'email',
          UNIQUE KEY `idx_inscription` (`annee`, `inscription_no`),
          INDEX `idx_participant` (`participant_id`),
          CONSTRAINT `fk_pr_participant` FOREIGN KEY (`participant_id`)
            REFERENCES `participants`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // Codes de connexion à 6 chiffres. Jamais stockés en clair :
        // password_hash() à l'écriture, password_verify() à la vérification.
        // Hachage LENT volontaire — 6 chiffres = 10^6 combinaisons seulement.
        // `email_hmac` et non l'adresse : cette table journalise les tentatives
        // d'authentification, y compris pour des adresses ne correspondant à
        // aucun compte (anti-énumération). Aucune adresse lisible ici.
        "CREATE TABLE IF NOT EXISTS `participant_auth_codes` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `email_hmac` CHAR(64) NOT NULL,
          `code_hash` VARCHAR(255) NOT NULL,
          `canal` ENUM('web','app') NOT NULL DEFAULT 'web',
          `tentatives` TINYINT NOT NULL DEFAULT 0,
          `consomme_at` DATETIME DEFAULT NULL,
          `expires_at` DATETIME NOT NULL,
          `ip` VARCHAR(45) DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_email_hmac` (`email_hmac`),
          INDEX `idx_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // Appareils de confiance. `token_hash` = SHA-256 : hachage RAPIDE et
        // déterministe, car la recherche se fait PAR LE HASH à chaque appel
        // d'API — un token serveur porte 256 bits d'entropie, il n'y a rien à
        // forcer par force brute. Différence assumée avec la table ci-dessus :
        // lent pour un secret faible, rapide pour un secret fort. Ne pas
        // « harmoniser » les deux.
        // Révocation = renseigner `revoque_at` ; on ne supprime jamais la ligne.
        "CREATE TABLE IF NOT EXISTS `participant_devices` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `participant_id` INT NOT NULL,
          `token_hash` VARCHAR(255) NOT NULL,
          `type` ENUM('web','app') NOT NULL,
          `libelle` VARCHAR(120) DEFAULT NULL,
          `plateforme` VARCHAR(60) DEFAULT NULL,
          `modele` VARCHAR(120) DEFAULT NULL,
          `ip_creation` VARCHAR(45) DEFAULT NULL,
          `user_agent` VARCHAR(500) DEFAULT NULL,
          `derniere_utilisation` DATETIME DEFAULT NULL,
          `expires_at` DATETIME DEFAULT NULL,
          `revoque_at` DATETIME DEFAULT NULL,
          /* Jeton de notification poussée (Firebase Cloud Messaging).
           *
           * ⚠️ RANGÉ SUR L'APPAREIL, ET NON DANS UNE TABLE À PART. C'est ce qui
           * fait qu'une révocation coupe les notifications sans une ligne de
           * code de plus : l'envoi ne lit que les appareils dont `revoque_at`
           * est nul. Une table séparée aurait fallu la purger à la main, et on
           * aurait fini par notifier un téléphone rendu ou perdu.
           *
           * Ce jeton n'est PAS un secret du coureur : il identifie une
           * installation auprès de Google, et se renouvelle tout seul. */
          `push_token` VARCHAR(255) DEFAULT NULL,
          `push_maj_at` DATETIME DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_participant` (`participant_id`),
          UNIQUE KEY `idx_token` (`token_hash`),
          INDEX `idx_expires` (`expires_at`),
          CONSTRAINT `fk_pd_participant` FOREIGN KEY (`participant_id`)
            REFERENCES `participants`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // Transferts d'inscription (double opt-in). `demande_par` référence
        // `participants.id` — à ne pas confondre avec `registrations.created_by`,
        // qui pointe vers `users.id` (un administrateur).
        // Les transferts sont limités à l'édition active : une inscription
        // archivée ne se transfère pas, la course a déjà eu lieu. Conséquence
        // utile : un transfert n'écrit jamais dans une table d'archive.
        // La règle « un seul transfert en attente par inscription » n'est pas
        // exprimable par un index MySQL ; elle est garantie dans le code (lot 4).
        "CREATE TABLE IF NOT EXISTS `registration_transfers` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `annee` SMALLINT NOT NULL,
          `inscription_no` VARCHAR(50) NOT NULL,
          `email_source` VARCHAR(255) NOT NULL,
          `email_cible` VARCHAR(255) NOT NULL,
          `token_hash` VARCHAR(255) NOT NULL,
          `statut` ENUM('en_attente','accepte','annule','expire') NOT NULL DEFAULT 'en_attente',
          `demande_par` INT DEFAULT NULL,
          `expires_at` DATETIME NOT NULL,
          `accepte_at` DATETIME DEFAULT NULL,
          `annule_at` DATETIME DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_inscription` (`annee`, `inscription_no`),
          INDEX `idx_statut` (`statut`),
          UNIQUE KEY `idx_token` (`token_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // Notifications poussées vers l'application mobile.
        //
        // ⚠️ PAS DE DESTINATAIRES NOMMÉS ICI, ET C'EST DÉLIBÉRÉ. Une
        // notification s'adresse à une ÉDITION (`annee`), donc à ses inscrits.
        // Stocker une liste de participants ferait de cette table un fichier de
        // ciblage — une donnée personnelle de plus à protéger, à purger et à
        // justifier, pour un besoin que « tous les inscrits de l'année » couvre.
        //
        // `publie_at` : une notification se prépare à l'avance et sort à l'heure
        // dite. Sans cette colonne, il faudrait être devant l'écran à 6 h du
        // matin le jour de la course pour annoncer un changement de départ.
        //
        // `epingle` : reste affichée dans « Mes inscriptions » au lieu de
        // défiler. C'est ce qui porte les informations pratiques — heure de
        // rendez-vous, parking — qu'on relit trois fois la veille.
        "CREATE TABLE IF NOT EXISTS `app_notifications` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `annee` SMALLINT DEFAULT NULL,
          `type` ENUM('info','course','urgent') NOT NULL DEFAULT 'info',
          /* ⚠️ LE PUSH N'EST PAS UNE PROPRIÉTÉ DU MESSAGE, C'EST UNE ACTION.
           *
           * La première version portait un « canal » (app / système / les deux),
           * et c'était une erreur de modèle : un message est du CONTENU qu'on
           * relit, un push est un ÉVÉNEMENT qui sonne une fois. Un push n'a pas
           * de date de fin, un message ne sonne pas.
           *
           * D'où deux choses distinctes :
           *   `afficher_dans_app` — le message vit-il dans la boîte du coureur ;
           *   `envoye_at` / `envoye_a` — TRACE d'un envoi qui a eu lieu, écrite
           *   par le bouton « Envoyer sur les téléphones ». On ne programme pas
           *   un push : on l'envoie, et on sait quand et à combien. */
          `afficher_dans_app` TINYINT(1) NOT NULL DEFAULT 1,
          `envoye_at` DATETIME DEFAULT NULL,
          `envoye_a` INT DEFAULT NULL,
          `titre` VARCHAR(120) NOT NULL,
          `message` TEXT NOT NULL,
          `publie_at` DATETIME DEFAULT NULL,
          `expire_at` DATETIME DEFAULT NULL,
          `epingle` TINYINT(1) NOT NULL DEFAULT 0,
          `active` TINYINT(1) NOT NULL DEFAULT 1,
          `cree_par` INT DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_diffusion` (`active`, `publie_at`, `expire_at`),
          INDEX `idx_annee` (`annee`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        /* Messages écartés par un coureur de SA boîte de réception.
         *
         * ═══════════════════════════════════════════════════════════════════
         * POURQUOI UNE TABLE, ET PAS UN STOCKAGE LOCAL.
         *
         * La suppression était d'abord retenue par l'appareil —
         * `SharedPreferences` sur le téléphone, `localStorage` dans le
         * navigateur. Un message écarté sur l'ordinateur réapparaissait donc
         * sur le mobile, et l'inverse. Une boîte de réception qui ne se
         * souvient pas de ce qu'on en a retiré n'est pas une boîte.
         *
         * ⚠️ CE N'EST PAS UNE SUPPRESSION. Le message reste intact pour tous
         * les autres coureurs : l'organisation publie pour tout le monde, et
         * une consigne effaçable par son destinataire n'en serait plus une.
         * Cette table ne dit qu'une chose : « untel ne veut plus le voir ».
         *
         * ⚠️ `ON DELETE CASCADE` DES DEUX CÔTÉS. Sur le participant, pour que
         * la suppression d'un compte n'y laisse rien. Sur la notification, pour
         * qu'un message effacé par l'administration n'y laisse pas des lignes
         * pointant dans le vide — que `check-integrity` signalerait ensuite
         * comme des orphelines. */
        "CREATE TABLE IF NOT EXISTS `participant_notifications_masquees` (
          `participant_id` INT NOT NULL,
          `notification_id` INT NOT NULL,
          `masque_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`participant_id`, `notification_id`),
          CONSTRAINT `fk_pnm_participant` FOREIGN KEY (`participant_id`)
            REFERENCES `participants`(`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_pnm_notification` FOREIGN KEY (`notification_id`)
            REFERENCES `app_notifications`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        /* Messages LUS par un coureur.
         *
         * ⚠️ « LU » N'EST PAS « MASQUÉ », ET LES DEUX TABLES DOIVENT RESTER
         * SÉPARÉES. Masquer, c'est écarter un message de sa boîte ; lire,
         * c'est en avoir pris connaissance. On peut lire sans masquer — et
         * c'est le cas courant. Une seule table aurait obligé à choisir entre
         * « la pastille ne descend jamais » et « lire fait disparaître le
         * message ».
         *
         * Cascade des deux côtés, pour la même raison que la table voisine :
         * la suppression d'un compte n'y laisse rien, et un message effacé
         * par l'administration n'y laisse pas de lignes orphelines. */
        "CREATE TABLE IF NOT EXISTS `participant_notifications_lues` (
          `participant_id` INT NOT NULL,
          `notification_id` INT NOT NULL,
          `lu_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`participant_id`, `notification_id`),
          CONSTRAINT `fk_pnl_participant` FOREIGN KEY (`participant_id`)
            REFERENCES `participants`(`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_pnl_notification` FOREIGN KEY (`notification_id`)
            REFERENCES `app_notifications`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // Chronométrage — alimenté plus tard par l'application mobile, mais créé
        // maintenant pour que l'API du lot 5 l'expose sans seconde migration.
        // DATETIME(3) = précision milliseconde. ⏱️ Toutes ces dates sont EN UTC.
        // `valide_par` référence `users.id` : l'administrateur qui a validé ou
        // corrigé un temps. Seule référence vers `users` de tout le lot, et elle
        // désigne un admin, jamais un coureur.
        // `methode` et `precision_s` sont obligatoires à l'affichage : un temps
        // extrapolé au GPS ne doit jamais passer pour un temps beacon.
        "CREATE TABLE IF NOT EXISTS `resultats` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `annee` SMALLINT NOT NULL,
          `inscription_no` VARCHAR(50) NOT NULL,
          `depart_at` DATETIME(3) DEFAULT NULL,
          `arrivee_at` DATETIME(3) DEFAULT NULL,
          `temps_s` DECIMAL(10,3) DEFAULT NULL,
          `methode` ENUM('beacon','gps_ligne','gps_extrapole','gps_distance','manuel','declaratif') DEFAULT NULL,
          `precision_s` INT DEFAULT NULL,
          `distance_m` INT DEFAULT NULL,
          `denivele_positif_m` INT DEFAULT NULL,
          `statut` ENUM('en_course','termine','abandon','non_partant','invalide') NOT NULL DEFAULT 'en_course',
          `valide_par` INT DEFAULT NULL,
          `commentaire` VARCHAR(255) DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY `idx_inscription` (`annee`, `inscription_no`),
          INDEX `idx_classement` (`annee`, `temps_s`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // `points` : JSON COMPRESSÉ par gzencode, d'où le LONGBLOB. À 1000
        // coureurs × ~3600 points, le non-compressé pèserait plusieurs centaines
        // de Mo et alourdirait les sauvegardes. Format documenté dans
        // inc/api-doc.php. `purge_at` porte la date de suppression RGPD.
        "CREATE TABLE IF NOT EXISTS `traces_gps` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `annee` SMALLINT NOT NULL,
          `inscription_no` VARCHAR(50) NOT NULL,
          `device_id` INT DEFAULT NULL,
          `source` ENUM('app','gpx_import') NOT NULL DEFAULT 'app',
          `points` LONGBLOB DEFAULT NULL,
          `nb_points` INT DEFAULT 0,
          `debut_at` DATETIME(3) DEFAULT NULL,
          `fin_at` DATETIME(3) DEFAULT NULL,
          `purge_at` DATE DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_inscription` (`annee`, `inscription_no`),
          INDEX `idx_purge` (`purge_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // Toutes les détections brutes sont conservées ; `retenue` marque celle
        // qui a produit le résultat. On n'en supprime jamais.
        "CREATE TABLE IF NOT EXISTS `detections` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `annee` SMALLINT NOT NULL,
          `inscription_no` VARCHAR(50) NOT NULL,
          `device_id` INT DEFAULT NULL,
          `type` ENUM('beacon','geofence','gps_ligne','manuel') NOT NULL,
          `point` ENUM('depart','arrivee') NOT NULL,
          `detecte_at` DATETIME(3) NOT NULL,
          `recu_at` DATETIME(3) DEFAULT NULL,
          `rssi_pic` SMALLINT DEFAULT NULL,
          `beacon_minor` SMALLINT DEFAULT NULL,
          `confiance` TINYINT DEFAULT NULL,
          `retenue` TINYINT(1) NOT NULL DEFAULT 0,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_inscription` (`annee`, `inscription_no`, `point`),
          /* Rend la réception des détections idempotente : le réseau tombera
           * pendant la course, l'application renverra ses détections, et un
           * même passage devant la balise ne doit pas créer dix lignes. */
          UNIQUE KEY `idx_unicite` (`annee`, `inscription_no`, `type`, `point`, `detecte_at`)
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
          (4, 'required_email',         'Email',            'email',  'email',       1, 1, 0, 1, 1, 1, 1, 1, 3,  NULL, 1),
          (5, 'required_date_of_birth', 'Âge',              'date',   'naissance',   1, 0, 0, 1, 1, 1, 1, 1, 5,  NULL, 1),
          (6, 'required_sex',           'Sexe',             'select', 'sexe',        1, 0, 0, 1, 1, 1, 1, 1, 6,  'H,F,Autre', 0),
          (7, 'required_city',          'Ville',            'text',   'ville',       1, 0, 0, 1, 1, 1, 1, 1, 7,  NULL, 1),
          (8, 'required_company',       'Entreprise / Groupe','text', 'entreprise',  1, 0, 0, 1, 1, 1, 1, 1, 8,  NULL, 1),
          (9, 'required_tshirt',        'Taille T-shirt',   'select', 'tshirt_size', 0, 0, 0, 1, 0, 1, 0, 0, 9,  '-,XS,S,M,L,XL,XXL', 0),
          (10,'required_montant',       'Montant dû',       'number', 'montant_du',  0, 0, 1, 1, 0, 1, 1, 0, 10, NULL, 0),
          (11,'custom_commentaire',     'Commentaire',      'textarea','commentaire',1, 0, 0, 1, 1, 1, 1, 1, 11, NULL, 1),
          (12,'guardian_authorization','Autorisation parentale (mineur)','guardian',NULL,1, 1, 0, 1, 1, 1, 1, 1, 12, '18', 0)",

        // Champ « Date d'inscription » → colonne date_inscription (date réelle d'inscription,
        // distincte de created_at = date d'ajout ; antidatable, pilote le classement QR).
        // Jamais public. INSERT séparé car il faut renseigner visible_saisie_multiple.
        "INSERT IGNORE INTO `forms` (`id`, `fields`, `label`, `field_type`, `bdd_column`, `active`, `required`, `is_locked`, `is_default`, `visible_public`, `visible_admin`, `visible_saisie`, `visible_qr`, `visible_saisie_multiple`, `required_saisie_multiple`, `sort_order`, `options_list`, `encrypted`) VALUES
          (13, 'inscription_date', 'Date d''inscription', 'date', 'date_inscription', 1, 0, 0, 1, 0, 1, 0, 0, 1, 0, 13, NULL, 0)",

        // Flags « Ajout multiple » des champs essentiels (cohérence avec update.php) :
        //  - visibles en bulk : nom, prenom, email, entreprise, montant_du
        //  - OBLIGATOIRES en bulk : nom + prenom uniquement (email/entreprise/montant
        //    restent facultatifs — particulier sans entreprise, inscrit sans email,
        //    montant auto-calculé).
        "UPDATE `forms` SET `visible_saisie_multiple` = 1
          WHERE `bdd_column` IN ('nom', 'prenom', 'email', 'entreprise', 'montant_du')",
        "UPDATE `forms` SET `required_saisie_multiple` = 1
          WHERE `bdd_column` IN ('nom', 'prenom')",

        // Caractère obligatoire SPÉCIFIQUE au formulaire admin (« Nouvel inscrit »),
        // indépendant de `required` (public / saisie / QR) — même principe que
        // `required_saisie_multiple` pour l'« Ajout multiple ». Initialisé sur la
        // valeur de `required` ; l'admin l'ajuste ensuite dans « Gestion des champs ».
        "UPDATE `forms` SET `required_admin` = `required`",

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
          (12, 'date_inscription', 'date de creation'),
          (13, 'montant_du', 'Montant du'),
          (14, 'prestation', 'Prestations')",

        // Compteur atomique pour inscription_no (évite la race condition CWE-362)
        "INSERT IGNORE INTO `inscription_counter` (`id`, `next_no`) VALUES (1, 0)",

        // Ligne unique de configuration de l'import automatique AssoConnect
        "INSERT IGNORE INTO `sync_assoconnect` (`id`) VALUES (1)",

        "INSERT IGNORE INTO `tshirt_access` (`id`) VALUES (1)",

        // Première édition (lot 1) : l'année en cours, marquée active. C'est elle
        // que l'aiguilleur (src/core/registrations_resolver.php) associe à la
        // table `registrations`. L'administrateur complétera ensuite la date de
        // course, la distance et les coordonnées.
        // Base neuve : aucune archive à détecter, aucun backfill.
        "INSERT IGNORE INTO `editions` (`annee`, `libelle`, `is_active`)
          VALUES (YEAR(CURDATE()), CONCAT('Forbach en Rose ', YEAR(CURDATE())), 1)",

        /* ── FAQ de l'espace coureur (lot 6) ─────────────────────────────────
         * Identifiants FIXES à partir de 901, et INSERT IGNORE : c'est ce qui
         * rend le peuplement idempotent, en installation comme en mise à jour.
         * La plage 901+ évite toute collision avec les questions créées par
         * l'administration, numérotées à partir de 1.
         * `position` à 900+ pour qu'elles se placent après les siennes.
         *
         * ⚠️ Conséquence assumée : une question supprimée par l'administration
         * réapparaît au prochain update.php. La désactiver (active = 0) plutôt
         * que la supprimer la fait disparaître définitivement du site.
         *
         * Le champ `keywords` sert au chatbot : ce sont les mots que les gens
         * tapent VRAIMENT, pas le vocabulaire de l'association. « je ne peux
         * plus courir » amène plus de monde que « transfert d'inscription ». */
        "INSERT IGNORE INTO `chatbot_faq` (`id`, `question`, `answer`, `keywords`, `position`, `active`) VALUES
          (901,
           'Comment accéder à mon espace coureur ?',
           'Rendez-vous sur la page de connexion de l''espace coureur et saisissez l''adresse email utilisée lors de votre inscription. Vous recevez aussitôt un code à 6 chiffres par email : recopiez-le, et vous êtes connecté. Il n''y a aucun mot de passe à créer ni à retenir.',
           'espace coureur, connexion, se connecter, mot de passe, code, mon compte',
           901, 1),
          (902,
           'J''ai perdu mon QR code, comment le retrouver ?',
           'Votre QR code est disponible à tout moment dans votre espace coureur, sur la fiche de votre inscription. C''est exactement le même que celui de votre mail de confirmation : vous pouvez le présenter depuis votre téléphone au retrait des t-shirts.',
           'qr code, qrcode, billet, dossard, perdu, retrouver, mail non recu',
           902, 1),
          (903,
           'Je ne peux plus courir. Que faire de mon inscription ?',
           'Vous pouvez la transférer à quelqu''un d''autre depuis votre espace coureur, sans passer par l''organisation. Ouvrez l''inscription concernée, indiquez l''adresse email de la personne : elle reçoit un mail et confirme. Tant qu''elle n''a pas confirmé, vous pouvez annuler et l''inscription reste la vôtre. Une date limite s''applique avant la course.',
           'transfert, transferer, ceder, donner ma place, ne peux plus courir, blesse, empeche, annuler, rembourser',
           903, 1),
          (904,
           'Comment corriger mon nom, mon âge ou mon adresse email ?',
           'Depuis votre espace coureur. Le nom et le prénom se modifient dans « Mon compte », le sexe et l''âge dans le détail de votre inscription. Pour l''adresse email, un code de confirmation est envoyé à la nouvelle adresse — cela évite qu''une faute de frappe vous empêche de vous reconnecter. Attention : le sexe et l''âge ne sont plus modifiables une fois le départ donné, car ils déterminent votre catégorie de classement.',
           'changer, modifier, corriger, erreur, faute, nom, prenom, age, sexe, mail, email, adresse',
           904, 1),
          (905,
           'Existe-t-il une application mobile ?',
           'Une application est prévue : elle apportera le suivi de votre course le jour J, ce qu''une page web ne peut pas faire puisqu''elle s''arrête dès que l''écran s''éteint. En attendant, votre espace coureur fonctionne dans n''importe quel navigateur, sur téléphone comme sur ordinateur, et ne prend aucune place sur votre appareil.',
           'application, appli, app, mobile, telecharger, android, iphone, ios, play store, app store',
           905, 1),
          (906,
           'Je me connecte depuis plusieurs appareils, est-ce un problème ?',
           'Non. Vous pouvez rester connecté sur votre téléphone, votre tablette et votre ordinateur. La rubrique « Mes appareils » de votre espace liste tous les appareils connectés et permet d''en déconnecter un à distance — utile si vous perdez votre téléphone ou si vous vous êtes connecté sur un appareil qui n''est pas le vôtre.',
           'appareils, plusieurs, telephone, ordinateur, deconnecter, perdu, vole, securite',
           906, 1),
          (907,
           'La course est-elle chronométrée ?',
           'Forbach en Rose est avant tout un événement solidaire, à allure libre : l''essentiel est de participer. Un chronométrage par l''application mobile est en préparation. Tant qu''il n''est pas en service, aucun temps n''est enregistré — venez simplement profiter de la marche.',
           'chronometre, chronometrage, chrono, temps, classement, resultat, performance, course',
           907, 1),
          (908,
           'Où verrai-je mon temps et mes résultats ?',
           'Dans la rubrique « Mes résultats » de votre espace coureur, dès que le chronométrage sera en service. La page existe déjà mais reste vide pour le moment : c''est normal, il n''y a encore rien à y afficher. Vous y retrouverez aussi vos éditions précédentes.',
           'mes resultats, mon temps, mon chrono, ou voir, classement, performance',
           908, 1),
          (909,
           'Que fera l''application que le site ne fait pas déjà ?',
           'Le suivi de votre course le jour J. Une page web s''arrête dès que l''écran du téléphone s''éteint : elle ne peut pas enregistrer votre parcours pendant que vous marchez. C''est la seule chose qu''une application installée sait faire. Pour tout le reste — QR code, inscription, transfert, corrections — le site fait déjà le travail, sans rien occuper sur votre téléphone.',
           'application, appli, difference, pourquoi, installer, suivi, gps, parcours, jour j',
           909, 1)",
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
  <title>Installation — Forbach en Rose</title>
  <?php include __DIR__ . '/src/partials/auth-head.php'; ?>
</head>
<body>

<div class="auth">
  <div class="auth-frame">
    <div class="auth-pane">
      <a class="brand" href="index.php">
        <span class="name">Forbach en Rose</span>
      </a>
      <div class="inner is-wide">

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
                     value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>"
                     placeholder="localhost" required>
            </div>

            <div class="oc-form-group">
              <label class="oc-label">Utilisateur MySQL</label>
              <input name="db_user" id="dbUser" class="oc-input"
                     value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>"
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
                    Indispensable pour d&eacute;chiffrer les donn&eacute;es existantes.
                    Elle se trouve dans l'ancien fichier <code>config/.env</code> (sites &le; 1.4.0).
                    Depuis la 2.0.0 : copiez plut&ocirc;t <code>config/config.enc</code> et
                    <code>config/master.key</code> de l'ancien serveur au lieu de r&eacute;installer.
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

          <?php if (isset($_SESSION['config_write_error'])): ?>
            <div class="oc-alert oc-alert-warning">
              Le dossier <code>config/</code> n'est pas accessible en &eacute;criture :
              impossible de cr&eacute;er la configuration chiffr&eacute;e
              (<code>config.enc</code> + <code>master.key</code>).
              Donnez les droits d'&eacute;criture au dossier <code>config/</code>
              (chmod 755 ou 775 selon l'h&eacute;bergeur) puis relancez l'installation.
            </div>
            <?php unset($_SESSION['config_write_error']); ?>
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
                <span class="oc-sum-label">Configuration chiffr&eacute;e</span>
                <span class="oc-sum-value oc-text-success">config.enc + master.key g&eacute;n&eacute;r&eacute;s</span>
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

      </div><!-- /inner -->
    </div><!-- /auth-pane -->
    <?php include __DIR__ . '/src/partials/auth-art.php'; ?>
  </div><!-- /auth-frame -->
</div><!-- /auth -->

</body>
</html>
