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
          `social_networks` int(11) NOT NULL DEFAULT 0,
          `link_cancer` varchar(255) DEFAULT NULL,
          `debogage` int(2) NOT NULL DEFAULT 0,
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
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `partners_albums` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `year_id` int(11) NOT NULL,
          `album_title` varchar(255) NOT NULL,
          `album_img` varchar(255) DEFAULT NULL,
          `album_desc` text DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `year_id` (`year_id`),
          CONSTRAINT `partners_albums_ibfk_1` FOREIGN KEY (`year_id`) REFERENCES `partners_years` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `photo_years` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `year` int(11) NOT NULL,
          `title` varchar(255) NOT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        "CREATE TABLE IF NOT EXISTS `photo_albums` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `year_id` int(11) NOT NULL,
          `album_title` varchar(255) NOT NULL,
          `album_link` text NOT NULL,
          `album_img` varchar(255) DEFAULT NULL,
          `album_desc` text DEFAULT NULL,
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
    ];
}

function getDefaultInserts(): array
{
    return [
        "INSERT IGNORE INTO `setting` (`id`, `title`, `title_color`, `footer`, `registration_fee`, `accueil_active`, `debogage`, `social_networks`)
         VALUES (1, 'Forbach en Rose', '#ffffff', '© Forbach en Rose', 12, 0, 0, 0)",

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
  <title>Installation – Forbach en Rose</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      min-height: 100vh;
      background: linear-gradient(135deg, #fdf4f8 0%, #ffffff 100%);
    }

    .install-container {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem 1rem;
    }

    .install-card {
      background: white;
      border-radius: 20px;
      box-shadow: 0 20px 60px rgba(236, 72, 153, 0.15);
      max-width: 540px;
      width: 100%;
      overflow: hidden;
    }

    .install-header {
      background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
      padding: 2.5rem 2rem 1.5rem;
      text-align: center;
      color: white;
    }

    .install-header h1 {
      font-size: 1.75rem;
      font-weight: 700;
      margin: 0 0 0.25rem;
    }

    .install-header p {
      margin: 0;
      opacity: 0.95;
      font-size: 0.95rem;
    }

    /* ── Indicateur d'étapes ── */
    .wizard-steps {
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 1.5rem 2rem 0.5rem;
      gap: 0;
    }

    .wizard-step {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 0.85rem;
      background: #e2e8f0;
      color: #94a3b8;
      flex-shrink: 0;
      transition: all 0.3s;
    }

    .wizard-step.active {
      background: linear-gradient(135deg, #ec4899, #db2777);
      color: white;
      box-shadow: 0 4px 12px rgba(236, 72, 153, 0.3);
      transform: scale(1.1);
    }

    .wizard-step.done {
      background: #ec4899;
      color: white;
    }

    .wizard-line {
      flex: 1;
      height: 3px;
      background: #e2e8f0;
      max-width: 80px;
    }

    .wizard-line.done {
      background: #ec4899;
    }

    .step-labels {
      display: flex;
      justify-content: center;
      gap: 40px;
      padding: 0.5rem 2rem 0;
      margin-bottom: 0;
    }

    .step-label {
      font-size: 0.75rem;
      color: #94a3b8;
      text-align: center;
      min-width: 80px;
    }

    .step-label.active {
      color: #ec4899;
      font-weight: 600;
    }

    /* ── Formulaire ── */
    .install-body {
      padding: 2rem;
    }

    .form-label {
      font-weight: 600;
      color: #334155;
      font-size: 0.9rem;
      margin-bottom: 0.5rem;
    }

    .form-control {
      border: 2px solid #e2e8f0;
      border-radius: 12px;
      padding: 0.75rem 1rem;
      font-size: 0.95rem;
      transition: all 0.2s;
    }

    .form-control:focus {
      border-color: #ec4899;
      box-shadow: 0 0 0 4px rgba(236, 72, 153, 0.1);
    }

    .btn-install {
      background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
      border: none;
      border-radius: 12px;
      padding: 0.875rem 1.5rem;
      font-weight: 600;
      font-size: 1rem;
      color: white;
      transition: all 0.2s;
      box-shadow: 0 4px 12px rgba(236, 72, 153, 0.3);
    }

    .btn-install:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(236, 72, 153, 0.4);
      background: linear-gradient(135deg, #db2777 0%, #be185d 100%);
      color: white;
    }

    .btn-install:active {
      transform: translateY(0);
    }

    .alert {
      border-radius: 12px;
      border: none;
      padding: 0.875rem 1rem;
    }

    .install-footer {
      text-align: center;
      padding: 1.25rem;
      color: #64748b;
      font-size: 0.85rem;
      position: relative;
      overflow: hidden;
      isolation: isolate;
    }

    .install-footer::after {
      content: "";
      position: absolute;
      right: 12px;
      bottom: 8px;
      width: clamp(110px, 16vw, 150px);
      aspect-ratio: 7680 / 3561;
      background: url('files/_logos/logo_fer_noir2.png') no-repeat center / contain;
      opacity: 0.12;
      pointer-events: none;
      z-index: 0;
    }

    .install-footer .footer-copy {
      position: relative;
      z-index: 1;
    }

    .icon-db {
      width: 60px;
      height: 60px;
      margin: 0 auto 1rem;
      background: white;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .icon-db svg {
      width: 32px;
      height: 32px;
      color: #ec4899;
    }

    .success-icon {
      width: 80px;
      height: 80px;
      margin: 0 auto 1.5rem;
      background: linear-gradient(135deg, #10b981, #059669);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 16px rgba(16, 185, 129, 0.3);
    }

    .success-icon svg {
      width: 40px;
      height: 40px;
      color: white;
    }

    .summary-list {
      list-style: none;
      padding: 0;
      margin: 0;
    }

    .summary-list li {
      padding: 0.625rem 0;
      border-bottom: 1px solid #f1f5f9;
      display: flex;
      justify-content: space-between;
      font-size: 0.9rem;
    }

    .summary-list li:last-child {
      border-bottom: none;
    }

    .summary-list .label {
      color: #64748b;
      font-weight: 500;
    }

    .summary-list .value {
      color: #1e293b;
      font-weight: 600;
    }

    /* ── Checks mot de passe ── */
    .pw-checks {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .pw-check {
      font-size: 0.8rem;
      color: #94a3b8;
      display: flex;
      align-items: center;
      gap: 6px;
      transition: color 0.2s;
    }

    .pw-check .pw-icon {
      font-size: 0.9rem;
      width: 16px;
      text-align: center;
    }

    .pw-check.pw-ok {
      color: #10b981;
      font-weight: 600;
    }

    .pw-check.pw-fail {
      color: #94a3b8;
    }

    .env-manual {
      background: #1e293b;
      color: #e2e8f0;
      border-radius: 12px;
      padding: 1rem;
      font-family: 'Courier New', monospace;
      font-size: 0.85rem;
      white-space: pre-wrap;
      word-break: break-all;
    }

    @media (max-width: 575.98px) {
      .install-card {
        border-radius: 0;
        box-shadow: none;
        min-height: 100vh;
      }

      .install-header {
        padding: 3rem 1.5rem 1.5rem;
      }
    }
  </style>
</head>
<body>

<div class="install-container">
  <div class="install-card">

    <!-- En-tête -->
    <div class="install-header">
      <div class="icon-db">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
        </svg>
      </div>
      <h1>Installation</h1>
      <p>Configuration de Forbach en Rose</p>
    </div>

    <!-- Indicateur d'étapes -->
    <div class="wizard-steps">
      <?php foreach ([1, 2, 3] as $i): ?>
        <?php if ($i > 1): ?>
          <div class="wizard-line <?= $displayStep > $i - 1 ? 'done' : '' ?>"></div>
        <?php endif; ?>
        <div class="wizard-step <?= $displayStep === $i ? 'active' : ($displayStep > $i ? 'done' : '') ?>">
          <?php if ($displayStep > $i): ?>
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
            </svg>
          <?php else: ?>
            <?= $i ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="step-labels">
      <?php foreach ($stepLabels as $i => $label): ?>
        <span class="step-label <?= $displayStep === $i ? 'active' : '' ?>"><?= $label ?></span>
      <?php endforeach; ?>
    </div>

    <!-- Contenu -->
    <div class="install-body">

      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-3">
          <ul class="mb-0 ps-3">
            <?php foreach ($errors as $e): ?>
              <li><?= $e ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php // ─── ÉTAPE 1 : Base de données ───────────────── ?>
      <?php if ($displayStep === 1): ?>

        <form method="post" novalidate>
          <input type="hidden" name="step" value="2">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

          <div class="mb-3">
            <label class="form-label">Hôte MySQL</label>
            <input name="db_host" class="form-control"
                   value="<?= htmlspecialchars($_POST['db_host'] ?? $existing['DB_HOST'] ?? 'localhost') ?>"
                   placeholder="localhost" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Nom de la base de données</label>
            <input name="db_name" class="form-control"
                   value="<?= htmlspecialchars($_POST['db_name'] ?? $existing['DB_NAME'] ?? 'ForbachEnRose') ?>"
                   placeholder="ForbachEnRose" required>
            <div class="form-text">La base sera créée si elle n'existe pas.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Utilisateur MySQL</label>
            <input name="db_user" class="form-control"
                   value="<?= htmlspecialchars($_POST['db_user'] ?? $existing['DB_USER'] ?? 'root') ?>"
                   placeholder="root" required>
          </div>

          <div class="mb-4">
            <label class="form-label">Mot de passe MySQL</label>
            <input type="password" name="db_pass" class="form-control"
                   value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>"
                   placeholder="Mot de passe">
          </div>

          <button type="submit" class="btn btn-install w-100">
            Tester la connexion et installer
          </button>
        </form>

      <?php // ─── ÉTAPE 2 : Compte administrateur ─────────── ?>
      <?php elseif ($displayStep === 2): ?>

        <?php if ($dbSuccess): ?>
          <?php if (!empty($_SESSION['install']['db_existed'])): ?>
            <div class="alert alert-warning mb-3">
              La base de données <strong><?= htmlspecialchars($_SESSION['install']['db_name'] ?? '') ?></strong> existait déjà avec <?= (int)($_SESSION['install']['db_existing_tables'] ?? 0) ?> table(s). Les tables manquantes ont été ajoutées.
            </div>
          <?php else: ?>
            <div class="alert alert-success mb-3">
              Base de données configurée avec succès ! Toutes les tables ont été créées.
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <p class="text-muted mb-3">Créez le compte administrateur principal.</p>

        <form method="post" novalidate id="adminForm">
          <input type="hidden" name="step" value="3">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

          <div class="mb-3">
            <label class="form-label">Adresse email</label>
            <input name="admin_email" type="email" class="form-control"
                   value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>"
                   placeholder="admin@example.com" required autofocus>
          </div>

          <div class="mb-3">
            <label class="form-label">Mot de passe</label>
            <input type="password" name="admin_password" id="adminPass" class="form-control"
                   placeholder="Min. 14 car., majuscule, chiffre, spécial" required>
            <div class="pw-checks mt-2">
              <div class="pw-check" id="ck-length"><span class="pw-icon">&#9675;</span> 14 caractères minimum</div>
              <div class="pw-check" id="ck-upper"><span class="pw-icon">&#9675;</span> Une majuscule</div>
              <div class="pw-check" id="ck-digit"><span class="pw-icon">&#9675;</span> Un chiffre</div>
              <div class="pw-check" id="ck-special"><span class="pw-icon">&#9675;</span> Un caractère spécial</div>
            </div>
          </div>

          <div class="mb-4">
            <label class="form-label">Confirmer le mot de passe</label>
            <input type="password" name="admin_password_confirm" id="adminPassConfirm" class="form-control"
                   placeholder="Retapez le mot de passe" required>
            <div class="pw-checks mt-2">
              <div class="pw-check" id="ck-match"><span class="pw-icon">&#9675;</span> Les mots de passe correspondent</div>
            </div>
          </div>

          <button type="submit" class="btn btn-install w-100" id="btnSubmitAdmin" disabled>
            Créer le compte et terminer
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
            el.querySelector('.pw-icon').innerHTML = ok ? '&#10003;' : '&#9675;';
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

      <?php // ─── ÉTAPE 3 : Terminé ───────────────────────── ?>
      <?php elseif ($displayStep === 3): ?>

        <?php if (isset($_SESSION['env_manual'])): ?>
          <div class="alert alert-warning mb-3">
            Le dossier <code>config/</code> n'est pas accessible en écriture.
            Créez manuellement le fichier <code>config/.env</code> avec le contenu suivant :
          </div>
          <div class="env-manual"><?= htmlspecialchars($_SESSION['env_manual']) ?></div>
          <?php unset($_SESSION['env_manual']); ?>
        <?php else: ?>
          <div class="text-center mb-4">
            <div class="success-icon">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
              </svg>
            </div>
            <h4 class="fw-bold text-success mb-1">Installation terminée !</h4>
            <p class="text-muted">Votre site est prêt à être utilisé.</p>
          </div>

          <ul class="summary-list mb-4">
            <li>
              <span class="label">Administrateur</span>
              <span class="value"><?= htmlspecialchars($_SESSION['install_admin'] ?? 'admin') ?></span>
            </li>
            <li>
              <span class="label">Fichier .env</span>
              <span class="value text-success">Généré</span>
            </li>
            <li>
              <span class="label">Tables</span>
              <span class="value text-success">Créées</span>
            </li>
          </ul>

          <a href="login.php" class="btn btn-install w-100 text-center text-decoration-none">
            Accéder au site
          </a>
        <?php endif; ?>

        <?php
          // Nettoyage session
          unset($_SESSION['install_done'], $_SESSION['install_admin'], $_SESSION['csrf_install']);
        ?>

      <?php endif; ?>

    </div>

    <!-- Footer -->
    <div class="install-footer">
      <span class="footer-copy">Forbach en Rose &mdash; Assistant d'installation</span>
    </div>

  </div>
</div>

</body>
</html>
