<?php
/**
 * Assistant d'installation — Forbach en Rose
 * Accessible uniquement si config/.env est absent ou incomplet.
 */

// ── SECURITE : bloquer si déjà installé ─────────────────────
$envPath = __DIR__ . '/config/.env';
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
        header('Location: login.php');
        exit;
    }
}

session_start();

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

// ── Traitement des étapes ───────────────────────────────────
$step   = (int) ($_POST['step'] ?? 1);
$errors = [];
$dbSuccess = false;

// --- Étape 1 → 2 : tester connexion, créer BDD + tables ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    checkCsrf();

    $dbHost = trim($_POST['db_host'] ?? '');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';

    if ($dbHost === '') $errors[] = "L'hôte de la base de données est requis.";
    if ($dbName === '') $errors[] = "Le nom de la base de données est requis.";
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName) && $dbName !== '') {
        $errors[] = "Le nom de la base ne doit contenir que des lettres, chiffres et underscores.";
    }
    if ($dbUser === '') $errors[] = "L'utilisateur de la base de données est requis.";

    if (empty($errors)) {
        try {
            // Connexion sans base
            $testPdo = new PDO(
                "mysql:host=$dbHost;charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Créer la base si nécessaire
            $testPdo->exec(
                "CREATE DATABASE IF NOT EXISTS `$dbName`
                 DEFAULT CHARACTER SET utf8mb4
                 COLLATE utf8mb4_general_ci"
            );
            $testPdo->exec("USE `$dbName`");

            // Vérifier si des tables existent déjà
            $existingTables = $testPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $dbExisted = count($existingTables) > 0;

            // Créer les tables
            foreach (getCreateTableStatements() as $sql) {
                $testPdo->exec($sql);
            }

            // Insérer les données par défaut
            foreach (getDefaultInserts() as $sql) {
                $testPdo->exec($sql);
            }

            // Stocker en session pour l'étape suivante
            $_SESSION['install'] = [
                'db_host' => $dbHost,
                'db_name' => $dbName,
                'db_user' => $dbUser,
                'db_pass' => $dbPass,
                'db_existed' => $dbExisted,
                'db_existing_tables' => count($existingTables),
            ];

            $dbSuccess = true;
            $step = 2; // afficher le formulaire admin

        } catch (PDOException $e) {
            $errors[] = "Erreur de connexion : " . htmlspecialchars($e->getMessage());
            $step = 1;
        }
    } else {
        $step = 1;
    }
}

// --- Étape 2 → 3 : créer admin + écrire .env ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (int) ($_POST['step'] ?? 0) === 3) {
    checkCsrf();
    $step = 3; // pour le rendu en cas d'erreur

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

    if (empty($errors) && isset($_SESSION['install'])) {
        $inst = $_SESSION['install'];

        try {
            $pdo = new PDO(
                "mysql:host={$inst['db_host']};dbname={$inst['db_name']};charset=utf8mb4",
                $inst['db_user'],
                $inst['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Vérifier si un admin existe déjà
            $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
            $exists->execute([$adminUser]);
            if ($exists->fetchColumn() > 0) {
                $errors[] = "Cette adresse email existe déjà.";
            } else {
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)');
                $stmt->execute([$adminUser, $hash, 'admin']);

                // Générer clé d'encryption
                $encryptionKey = base64_encode(random_bytes(48));

                // Contenu du .env
                $envContent = "DB_HOST={$inst['db_host']}\n"
                            . "DB_NAME={$inst['db_name']}\n"
                            . "DB_USER={$inst['db_user']}\n"
                            . "DB_PASS={$inst['db_pass']}\n"
                            . "\nENCRYPTION_KEY=$encryptionKey\n";

                // Écrire le fichier
                $configDir = __DIR__ . '/config';
                if (!is_writable($configDir)) {
                    $_SESSION['env_manual'] = $envContent;
                    $step = 3;
                } else {
                    file_put_contents($envPath, $envContent);
                    $_SESSION['install_done'] = true;
                    $_SESSION['install_admin'] = $adminUser;
                    unset($_SESSION['install']);
                    $step = 3;
                }
            }
        } catch (PDOException $e) {
            $errors[] = "Erreur base de données : " . htmlspecialchars($e->getMessage());
            $step = 2;
        }
    } elseif (!isset($_SESSION['install'])) {
        $errors[] = "Session expirée. Veuillez recommencer l'installation.";
        $step = 1;
    } else {
        $step = 2;
    }
}

// ── Déterminer l'étape d'affichage ──────────────────────────
$displayStep = $step;
if ($dbSuccess) $displayStep = 2;
if (isset($_SESSION['install_done'])) $displayStep = 3;

// ── Fonctions SQL ───────────────────────────────────────────
function getCreateTableStatements(): array
{
    return [
        // --- Tables sans dépendances FK ---

        "CREATE TABLE IF NOT EXISTS `setting` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `assoconnect_js` longtext DEFAULT NULL,
          `assoconnect_iframe` longtext DEFAULT NULL,
          `title` varchar(50) DEFAULT NULL,
          `title_color` varchar(50) DEFAULT NULL,
          `picture` varchar(50) DEFAULT NULL,
          `footer` varchar(50) DEFAULT NULL,
          `registration_fee` int(10) DEFAULT NULL,
          `titleAccueil` varchar(50) DEFAULT NULL,
          `edition` varchar(50) DEFAULT NULL,
          `link_facebook` varchar(255) DEFAULT NULL,
          `link_instagram` varchar(255) DEFAULT NULL,
          `accueil_active` int(2) NOT NULL DEFAULT 0,
          `date_course` timestamp NULL DEFAULT NULL,
          `picture_accueil` varchar(255) DEFAULT NULL,
          `picture_partner` varchar(255) DEFAULT NULL,
          `picture_gradient` varchar(255) DEFAULT NULL,
          `titleParcours` varchar(255) DEFAULT NULL,
          `parcoursDesc` text DEFAULT NULL,
          `picture_parcours` varchar(255) DEFAULT NULL,
          `div_reglementation` mediumtext DEFAULT NULL,
          `link_cancer` varchar(255) DEFAULT NULL,
          `partners_title` varchar(255) DEFAULT NULL,
          `debogage` int(2) NOT NULL DEFAULT 0,
          `client_id` TEXT DEFAULT NULL,
          `client_secret` TEXT DEFAULT NULL,
          `mail_email` VARCHAR(255) DEFAULT NULL,
          `mail_phone` VARCHAR(50) DEFAULT NULL,
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
          `active` int(2) NOT NULL DEFAULT 0,
          `required` int(2) NOT NULL DEFAULT 0,
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
          `inscription_no` int(11) NOT NULL,
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
          `album_img` varchar(255) DEFAULT NULL,
          `album_desc` text DEFAULT NULL,
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
          `ip_address` VARCHAR(45) NOT NULL,
          `reason` VARCHAR(255) DEFAULT NULL,
          `banned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `banned_by` INT DEFAULT NULL,
          UNIQUE KEY `idx_ip` (`ip_address`)
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
    ];
}

function getDefaultInserts(): array
{
    return [
        "INSERT IGNORE INTO `setting` (`id`, `title`, `title_color`, `footer`, `registration_fee`, `accueil_active`, `debogage`)
         VALUES (1, 'Forbach en Rose', '#ffffff', '© Forbach en Rose', 12, 0, 0)",

        "INSERT IGNORE INTO `customize` (`id`, `assoconnect_js`, `assoconnect_iframe`)
         VALUES (1, NULL, NULL)",

        "INSERT IGNORE INTO `forms` (`id`, `fields`, `active`, `required`) VALUES
          (1, 'required_name', 0, 1),
          (2, 'required_firstname', 0, 1),
          (3, 'required_phone', 0, 1),
          (4, 'required_email', 0, 1),
          (5, 'required_date_of_birth', 0, 1),
          (6, 'required_sex', 0, 1),
          (7, 'required_city', 0, 1),
          (8, 'required_company', 0, 0)",

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
    ];
}

// ── Libellés des étapes ─────────────────────────────────────
$stepLabels = [
    1 => 'Base de données',
    2 => 'Compte Admin',
    3 => 'Terminé',
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
      color: #ec4899;
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
      background: #ec4899;
      color: #fff;
    }

    .oc-step-dot.done {
      background: #ec4899;
      color: #fff;
    }

    .oc-step-line {
      flex: 1;
      height: 2px;
      background: #f0e8eb;
      max-width: 60px;
    }

    .oc-step-line.done {
      background: #ec4899;
    }

    .oc-step-labels {
      display: flex;
      justify-content: center;
      gap: 32px;
      margin-bottom: 20px;
    }

    .oc-step-label {
      font-size: 11px;
      color: #a1a1aa;
      text-align: center;
      min-width: 70px;
    }

    .oc-step-label.active {
      color: #ec4899;
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
      border-color: #ec4899;
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
      background: #ec4899;
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
        <?php foreach ([1, 2, 3] as $i): ?>
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

        <?php // ─── ETAPE 1 : Base de donnees ───────────────── ?>
        <?php if ($displayStep === 1): ?>

          <form method="post" novalidate>
            <input type="hidden" name="step" value="2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <div class="oc-form-group">
              <label class="oc-label">H&ocirc;te MySQL</label>
              <input name="db_host" class="oc-input"
                     value="<?= htmlspecialchars($_POST['db_host'] ?? $existing['DB_HOST'] ?? 'localhost') ?>"
                     placeholder="localhost" required>
            </div>

            <div class="oc-form-group">
              <label class="oc-label">Nom de la base de donn&eacute;es</label>
              <input name="db_name" class="oc-input"
                     value="<?= htmlspecialchars($_POST['db_name'] ?? $existing['DB_NAME'] ?? 'ForbachEnRose') ?>"
                     placeholder="ForbachEnRose" required>
              <div class="oc-form-hint">La base sera cr&eacute;&eacute;e si elle n'existe pas.</div>
            </div>

            <div class="oc-form-group">
              <label class="oc-label">Utilisateur MySQL</label>
              <input name="db_user" class="oc-input"
                     value="<?= htmlspecialchars($_POST['db_user'] ?? $existing['DB_USER'] ?? 'root') ?>"
                     placeholder="root" required>
            </div>

            <div class="oc-form-group" style="margin-bottom:20px">
              <label class="oc-label">Mot de passe MySQL</label>
              <input type="password" name="db_pass" class="oc-input"
                     value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>"
                     placeholder="Mot de passe">
            </div>

            <button type="submit" class="oc-btn">
              Tester la connexion et installer
            </button>
          </form>

        <?php // ─── ETAPE 2 : Compte administrateur ─────────── ?>
        <?php elseif ($displayStep === 2): ?>

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
            <input type="hidden" name="step" value="3">
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

          <script>
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

        <?php // ─── ETAPE 3 : Termine ───────────────────────── ?>
        <?php elseif ($displayStep === 3): ?>

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

            <ul class="oc-summary">
              <li>
                <span class="oc-sum-label">Administrateur</span>
                <span class="oc-sum-value"><?= htmlspecialchars($_SESSION['install_admin'] ?? 'admin') ?></span>
              </li>
              <li>
                <span class="oc-sum-label">Fichier .env</span>
                <span class="oc-sum-value oc-text-success">G&eacute;n&eacute;r&eacute;</span>
              </li>
              <li>
                <span class="oc-sum-label">Tables</span>
                <span class="oc-sum-value oc-text-success">Cr&eacute;&eacute;es</span>
              </li>
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
