<?php
/**
 * update.php — Migrations de base de données
 * Lance ce fichier une seule fois via le navigateur puis supprime-le.
 */
require __DIR__ . '/config/config.php';

$migrations = [
    "ALTER TABLE `setting` ADD COLUMN `mail_template_config` TEXT DEFAULT NULL",
    "ALTER TABLE `timeline_items` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL",
    "ALTER TABLE `setting` DROP COLUMN `footer`",
    "ALTER TABLE `setting` ADD COLUMN `theme_primary_color` VARCHAR(7) DEFAULT '#db2777'",
    "ALTER TABLE `setting` ADD COLUMN `theme_secondary_color` VARCHAR(7) DEFAULT '#0f172a'",
    "ALTER TABLE `setting` ADD COLUMN `theme_border_radius` INT DEFAULT 12",
    "ALTER TABLE `setting` ADD COLUMN `theme_font_family` VARCHAR(100) DEFAULT 'Inter'",
    "ALTER TABLE `setting` ADD COLUMN `theme_dark_enabled` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `setting` ADD COLUMN `flash_bg_color` VARCHAR(7) DEFAULT '#db2777'",
    "ALTER TABLE `setting` ADD COLUMN `flash_text_color` VARCHAR(7) DEFAULT '#ffffff'",
    "ALTER TABLE `setting` ADD COLUMN `theme_dark_primary_color` VARCHAR(7) DEFAULT '#f472b6'",
    "ALTER TABLE `setting` ADD COLUMN `theme_dark_secondary_color` VARCHAR(7) DEFAULT '#e2e8f0'",
    "ALTER TABLE `setting` ADD COLUMN `footer_logo` VARCHAR(255) DEFAULT 'logo_blanc.png'",
    "ALTER TABLE `setting` ADD COLUMN `registration_auto_open` DATETIME DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `registration_auto_close` DATETIME DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `mail_provider` ENUM('google','smtp') NOT NULL DEFAULT 'google'",
    "ALTER TABLE `setting` ADD COLUMN `smtp_host` VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `smtp_port` INT DEFAULT 465",
    "ALTER TABLE `setting` ADD COLUMN `smtp_user` VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `smtp_pass` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `smtp_encryption` ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl'",
    "ALTER TABLE `setting` ADD COLUMN `smtp_from_email` VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `smtp_from_name` VARCHAR(255) DEFAULT 'Forbach en Rose'",
    "ALTER TABLE `photo_years` ADD COLUMN `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP",
    "ALTER TABLE `partners_years` ADD COLUMN `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP",
    "ALTER TABLE `setting` ADD COLUMN `course_km` INT(10) DEFAULT 7",
    "ALTER TABLE `setting` ADD COLUMN `notify_recipients` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `notify_toggles` TEXT DEFAULT NULL",
    // Note : accueil_custom_content / accueil_custom_position / accueil_news_before_partners
    // ont été remplacées par accueil_layout (JSON). La migration des données + le DROP de
    // ces 3 colonnes obsolètes sont effectués plus bas (bloc dédié).
    "ALTER TABLE `setting` ADD COLUMN `accueil_layout` MEDIUMTEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `accueil_styles` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `accueil_texts` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `accueil_geometry` TEXT DEFAULT NULL",
    // Système brouillon : chaque réglage de l'accueil a maintenant une version
    // "draft" (modifications en cours dans l'éditeur) et une version "published"
    // (visible sur la vraie page). Le bouton "Publier" copie draft → published.
    "ALTER TABLE `setting` ADD COLUMN `accueil_layout_draft` MEDIUMTEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `accueil_styles_draft` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `accueil_texts_draft` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `accueil_geometry_draft` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `accueil_draft_updated_at` DATETIME DEFAULT NULL",
    // Section "Retrouver le départ" : point de départ de la course (adresse OU coordonnées)
    "ALTER TABLE `setting` ADD COLUMN `start_point_address` VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `start_point_coords` VARCHAR(64) DEFAULT NULL",
    // Newsletter : abonnés + horodatage d'envoi de la notif "nouvel article"
    "CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `email` varchar(255) NOT NULL,
      `status` enum('subscribed','unsubscribed') NOT NULL DEFAULT 'subscribed',
      `created_at` timestamp NULL DEFAULT current_timestamp(),
      `unsubscribed_at` timestamp NULL DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `email_idx` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "ALTER TABLE `news` ADD COLUMN `newsletter_sent_at` TIMESTAMP NULL DEFAULT NULL",
    "ALTER TABLE `users` ADD COLUMN `permissions` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `role_permissions` TEXT DEFAULT NULL",
    // Cloudflare Turnstile : protection anti-bot du formulaire partenaire (et autres)
    "ALTER TABLE `setting` ADD COLUMN `turnstile_sitekey` VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `turnstile_secret` TEXT DEFAULT NULL",
    "ALTER TABLE `users` ADD COLUMN `totp_secret` VARCHAR(64) DEFAULT NULL",
    "ALTER TABLE `users` ADD COLUMN `totp_pending_secret` VARCHAR(64) DEFAULT NULL",
    "ALTER TABLE `users` ADD COLUMN `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `users` ADD COLUMN `default_2fa_method` ENUM('email','totp','passkey') NOT NULL DEFAULT 'email'",
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
    // API externe : permet à des applications tierces de se connecter au site
    // (import Excel, ajout d'inscrit, consultation des statistiques) via un
    // identifiant + token. Le token est stocké chiffré (AES-256-GCM).
    "ALTER TABLE `setting` ADD COLUMN `api_enabled` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `setting` ADD COLUMN `api_user` VARCHAR(64) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `api_token` TEXT DEFAULT NULL",
    // Les appels à l'API sont désormais journalisés dans le fichier
    // config/logs/api.log (et non en BDD) : on supprime l'ancienne table si
    // elle a été créée par une version précédente de cette mise à jour.
    "DROP TABLE IF EXISTS `api_logs`",

    // Suivi du paiement : montant dû par inscrit (0 = non payé / gratuit / enfant -12 ans).
    "ALTER TABLE `registrations` ADD COLUMN `montant_du` DECIMAL(10,2) NOT NULL DEFAULT 0",

    // Catégorie d'inscrit (« prestation » AssoConnect) : tarif_unique / enfant_gratuit / enfant_tshirt.
    // Permet de distinguer un enfant -12 ans AVEC t-shirt (payant, compté pour le QR/t-shirt)
    // d'un adulte « tarif unique » alors qu'ils ont le même montant. NULL = ancien inscrit (= tarif unique).
    "ALTER TABLE `registrations` ADD COLUMN `prestation` VARCHAR(30) DEFAULT NULL",

    // Mode "Ajout multiple" (saisie en lot, ex. entreprise avec N inscrits) :
    //   - visible_saisie_multiple : champ affiché dans le formulaire bulk ?
    //   - required_saisie_multiple : champ obligatoire en mode bulk ?
    // Désactivés par défaut (0) — l'admin choisit explicitement les champs à inclure
    // depuis "Gestion des champs du formulaire".
    "ALTER TABLE `forms` ADD COLUMN `visible_saisie_multiple` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `forms` ADD COLUMN `required_saisie_multiple` TINYINT(1) NOT NULL DEFAULT 0",

    // AssoConnect : lien direct (bouton de repli affiché sous le formulaire intégré).
    "ALTER TABLE `setting` ADD COLUMN `assoconnect_url` VARCHAR(512) DEFAULT NULL",
    // AssoConnect : domaines autorisés dans la CSP (gérables depuis les Réglages).
    "ALTER TABLE `setting` ADD COLUMN `assoconnect_csp_domains` TEXT DEFAULT NULL",

    // Journal d'activité des contenus (albums, partenaires, actualités, timeline) :
    // trace création / modification / corbeille / restauration / suppression définitive
    // et l'auteur de chaque action. Affiché via l'onglet « Logs » (droit content.logs.view).
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
];

$results = [];

// Renommer le fichier de logs Google Mails .txt -> .log
$oldLog = __DIR__ . '/config/logs/logs_google_mails.txt';
$newLog = __DIR__ . '/config/logs/logs_google_mails.log';
if (file_exists($oldLog) && !file_exists($newLog)) {
    rename($oldLog, $newLog);
    $results[] = ['status' => 'success', 'sql' => 'RENAME logs_google_mails.txt → logs_google_mails.log', 'msg' => 'Fichier renommé'];
} elseif (file_exists($newLog)) {
    $results[] = ['status' => 'skip', 'sql' => 'RENAME logs_google_mails.txt → logs_google_mails.log', 'msg' => 'Déjà renommé'];
} else {
    $results[] = ['status' => 'skip', 'sql' => 'RENAME logs_google_mails.txt → logs_google_mails.log', 'msg' => 'Fichier source introuvable'];
}
// Migration inscription_no INT → VARCHAR(50) (vérifier avant d'exécuter)
try {
    $colType = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registrations' AND COLUMN_NAME = 'inscription_no'")->fetchColumn();
    if ($colType && stripos($colType, 'varchar') === false) {
        $pdo->exec("ALTER TABLE `registrations` MODIFY COLUMN `inscription_no` VARCHAR(50) NOT NULL");
        $results[] = ['status' => 'success', 'sql' => 'MODIFY inscription_no INT → VARCHAR(50)', 'msg' => 'OK'];
    } else {
        $results[] = ['status' => 'skip', 'sql' => 'MODIFY inscription_no INT → VARCHAR(50)', 'msg' => 'Déjà en VARCHAR'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => 'MODIFY inscription_no INT → VARCHAR(50)', 'msg' => $e->getMessage()];
}

/**
 * Vérifie l'existence d'une table dans la base courante.
 */
$tableExists = function (string $name) use ($pdo): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$name]);
    return (int) $st->fetchColumn() > 0;
};

foreach ($migrations as $sql) {
    // CREATE TABLE IF NOT EXISTS et DROP TABLE IF EXISTS ne lèvent jamais
    // d'exception en cas de no-op : on inspecte la BDD avant d'exécuter pour
    // afficher un statut « Existe déjà » / « N'existe pas » plutôt qu'« OK ».
    if (preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?(\w+)`?/i', $sql, $m)) {
        if ($tableExists($m[1])) {
            $results[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'Table « ' . $m[1] . ' » existe déjà'];
            continue;
        }
    } elseif (preg_match('/^\s*DROP\s+TABLE\s+IF\s+EXISTS\s+`?(\w+)`?/i', $sql, $m)) {
        if (!$tableExists($m[1])) {
            $results[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'Table « ' . $m[1] . ' » déjà absente'];
            continue;
        }
    }

    try {
        $pdo->exec($sql);
        $results[] = ['status' => 'success', 'sql' => $sql, 'msg' => 'OK'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Duplicate column') || str_contains($msg, 'check that column/key exists') || str_contains($msg, "Can't DROP")) {
            $results[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'Existe déjà ou déjà appliqué'];
        } else {
            $results[] = ['status' => 'error', 'sql' => $sql, 'msg' => $msg];
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Pré-remplissage de `registrations.montant_du` pour les inscrits existants.
// Idempotent : si toutes les lignes ont déjà un montant > 0, on n'agit pas.
// ─────────────────────────────────────────────────────────────────────────
$initMontantSql = "Pré-remplir registrations.montant_du = registration_fee pour les inscrits existants";
try {
    $remaining = (int) $pdo->query("SELECT COUNT(*) FROM `registrations` WHERE `montant_du` = 0")->fetchColumn();
    if ($remaining === 0) {
        $results[] = ['status' => 'skip', 'sql' => $initMontantSql, 'msg' => 'Déjà appliqué'];
    } else {
        $stmt = $pdo->prepare(
            "UPDATE `registrations` r JOIN `setting` s ON s.id = 1
             SET r.montant_du = COALESCE(s.registration_fee, 0)
             WHERE r.montant_du = 0"
        );
        $stmt->execute();
        $results[] = ['status' => 'success', 'sql' => $initMontantSql, 'msg' => $stmt->rowCount() . ' ligne(s) mises à jour'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $initMontantSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Classement des inscrits existants dans `registrations.prestation`.
// Idempotent : ne touche que les lignes encore NULL/vides. Un ancien enfant
// -12 ans AVEC t-shirt est indissociable d'un adulte (même montant) faute de
// donnée source — il est donc classé « tarif_unique » comme les adultes.
// ─────────────────────────────────────────────────────────────────────────
$initPrestationSql = "Classer registrations.prestation pour les inscrits existants";
try {
    $colExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registrations' AND COLUMN_NAME = 'prestation'"
    )->fetchColumn();
    if ($colExists === 0) {
        $results[] = ['status' => 'skip', 'sql' => $initPrestationSql, 'msg' => 'Colonne absente (migration non appliquée)'];
    } else {
        $remaining = (int) $pdo->query("SELECT COUNT(*) FROM `registrations` WHERE `prestation` IS NULL OR `prestation` = ''")->fetchColumn();
        if ($remaining === 0) {
            $results[] = ['status' => 'skip', 'sql' => $initPrestationSql, 'msg' => 'Déjà appliqué'];
        } else {
            $stmt = $pdo->prepare(
                "UPDATE `registrations`
                 SET `prestation` = CASE
                     WHEN `paiement_mode` = 'enfant_tshirt' THEN 'enfant_tshirt'
                     WHEN `paiement_mode` = 'gratuit' OR `montant_du` <= 0 THEN 'enfant_gratuit'
                     ELSE 'tarif_unique'
                 END
                 WHERE `prestation` IS NULL OR `prestation` = ''"
            );
            $stmt->execute();
            $results[] = ['status' => 'success', 'sql' => $initPrestationSql, 'msg' => $stmt->rowCount() . ' ligne(s) classées'];
        }
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $initPrestationSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Normalisation du mode de paiement : « enfant_tshirt » n'est pas un moyen de
// paiement (l'enfant -12 ans AVEC t-shirt a payé). On le remplace par
// « en ligne (CB) » — la catégorie est déjà conservée dans `prestation`
// (classée juste au-dessus). « gratuit » (vraiment gratuit) est conservé tel quel.
// ─────────────────────────────────────────────────────────────────────────
$normPaiementSql = "Normaliser paiement_mode « enfant_tshirt » → « en ligne (CB) »";
try {
    $stmt = $pdo->prepare("UPDATE `registrations` SET `paiement_mode` = 'en ligne (CB)' WHERE `paiement_mode` = 'enfant_tshirt'");
    $stmt->execute();
    $n = $stmt->rowCount();
    $results[] = ['status' => $n > 0 ? 'success' : 'skip', 'sql' => $normPaiementSql,
                  'msg' => $n > 0 ? ($n . ' ligne(s) mises à jour') : 'Rien à normaliser'];
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $normPaiementSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Mapping de la colonne Excel « Montant dû » dans la table `import`.
// ─────────────────────────────────────────────────────────────────────────
$importMapSql = "Ajouter le mapping import « Montant dû » (id 13)";
try {
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM `import` WHERE `fields_bdd` = 'montant_du'")->fetchColumn();
    if ($exists > 0) {
        $results[] = ['status' => 'skip', 'sql' => $importMapSql, 'msg' => 'Existe déjà'];
    } else {
        $pdo->prepare("INSERT INTO `import` (`id`, `fields_bdd`, `fields_excel`) VALUES (13, 'montant_du', 'Montant du')")
            ->execute();
        $results[] = ['status' => 'success', 'sql' => $importMapSql, 'msg' => 'Mapping ajouté'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $importMapSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Mapping de la colonne Excel « Prestations » dans la table `import`.
// Permet de configurer/renommer la colonne depuis Réglages → Import Excel.
// ─────────────────────────────────────────────────────────────────────────
$importPrestationSql = "Ajouter le mapping import « Prestations » (id 14)";
try {
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM `import` WHERE `fields_bdd` = 'prestation'")->fetchColumn();
    if ($exists > 0) {
        $results[] = ['status' => 'skip', 'sql' => $importPrestationSql, 'msg' => 'Existe déjà'];
    } else {
        $pdo->prepare("INSERT INTO `import` (`id`, `fields_bdd`, `fields_excel`) VALUES (14, 'prestation', 'Prestations')")
            ->execute();
        $results[] = ['status' => 'success', 'sql' => $importPrestationSql, 'msg' => 'Mapping ajouté'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $importPrestationSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Référence de `montant_du` dans la gestion des champs (table `forms`).
// Verrouillée — auto-calculée d'après le paiement, non éditable côté UI.
// ─────────────────────────────────────────────────────────────────────────
$formsMontantSql = "Ajouter la référence montant_du dans `forms` (verrouillée)";
try {
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM `forms` WHERE `bdd_column` = 'montant_du'")->fetchColumn();
    if ($exists > 0) {
        $results[] = ['status' => 'skip', 'sql' => $formsMontantSql, 'msg' => 'Existe déjà'];
    } else {
        $pdo->prepare(
            "INSERT INTO `forms`
              (`fields`, `label`, `field_type`, `bdd_column`, `active`, `required`,
               `is_locked`, `is_default`, `visible_public`, `visible_admin`, `visible_saisie`, `visible_qr`,
               `sort_order`, `options_list`, `encrypted`)
             VALUES ('required_montant', 'Montant dû', 'number', 'montant_du', 0, 0,
                     1, 1, 0, 1, 1, 0, 10, NULL, 0)"
        )->execute();
        $results[] = ['status' => 'success', 'sql' => $formsMontantSql, 'msg' => 'Champ ajouté'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $formsMontantSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Pré-cocher "Bulk visible" + "Bulk requis" pour les 5 champs essentiels du
// mode "Ajout multiple" : nom, prenom, email, entreprise, montant_du.
//   - nom, prenom, montant_du : affichés dans chaque carte "Personne #N"
//   - email, entreprise       : champs partagés dans l'en-tête bulk
//
// Deux blocs :
//   A) First-time : aucun champ encore bulk-visible → coche les 5 essentiels
//   B) Catch-up   : ancienne migration trop stricte → rattrape email,
//                   entreprise, montant_du si encore à 0. Idempotent au
//                   niveau SQL (WHERE visible_saisie_multiple = 0).
// ─────────────────────────────────────────────────────────────────────────
$bulkAutoCheckSql = "Pré-cocher Bulk visible/requis pour les 5 champs essentiels (nom, prenom, email, entreprise, montant_du)";
try {
    // Vérifie que la colonne existe (ALTER a réussi)
    $colCheck = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forms'
            AND COLUMN_NAME = 'visible_saisie_multiple'"
    )->fetchColumn();
    if (!$colCheck) {
        $results[] = ['status' => 'skip', 'sql' => $bulkAutoCheckSql, 'msg' => 'Colonne absente — ALTER non appliqué'];
    } else {
        $alreadyChecked = (int) $pdo->query("SELECT COUNT(*) FROM `forms` WHERE `visible_saisie_multiple` = 1")->fetchColumn();

        if ($alreadyChecked === 0) {
            // Bloc A — first-time : on coche les 5 essentiels
            $stmt = $pdo->prepare(
                "UPDATE `forms`
                    SET `visible_saisie_multiple` = 1, `required_saisie_multiple` = 1
                  WHERE `bdd_column` IN ('nom', 'prenom', 'email', 'entreprise', 'montant_du')"
            );
            $stmt->execute();
            $results[] = ['status' => 'success', 'sql' => $bulkAutoCheckSql, 'msg' => 'First-time : ' . $stmt->rowCount() . ' champ(s) pré-coché(s)'];
        } else {
            // Bloc B — catch-up : rattrape email/entreprise/montant_du si
            // l'ancienne migration les a oubliés (filtres required=1 ou
            // exclusion shared trop stricts dans versions antérieures).
            $stmt = $pdo->prepare(
                "UPDATE `forms`
                    SET `visible_saisie_multiple` = 1, `required_saisie_multiple` = 1
                  WHERE `bdd_column` IN ('email', 'entreprise', 'montant_du')
                    AND `visible_saisie_multiple` = 0"
            );
            $stmt->execute();
            $catchupCount = $stmt->rowCount();
            if ($catchupCount > 0) {
                $results[] = ['status' => 'success', 'sql' => $bulkAutoCheckSql, 'msg' => 'Catch-up : ' . $catchupCount . ' champ(s) ajouté(s)'];
            } else {
                $results[] = ['status' => 'skip', 'sql' => $bulkAutoCheckSql, 'msg' => 'Déjà appliqué (' . $alreadyChecked . ' champs)'];
            }
        }
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $bulkAutoCheckSql, 'msg' => $e->getMessage()];
}

/* ─────────────────────────────────────────────────────────────────────────
 * Migration des permissions content.* vers la granularité par page
 * (news/timeline/partners/albums).create/edit/delete
 *
 * Convertit chaque entrée content.create / content.edit / content.delete
 * trouvée en BDD en 4 entrées granulaires (une par page de contenu).
 * S'applique à :
 *   - users.permissions   (permissions personnalisées par utilisateur)
 *   - setting.role_permissions  (défauts par rôle)
 * ───────────────────────────────────────────────────────────────────────── */
function migrateContentPermissions(array $perms): array
{
    if (!isset($perms['actions']) || !is_array($perms['actions'])) return $perms;
    $map = [
        'content.create' => ['news.create','timeline.create','partners.create','albums.create'],
        'content.edit'   => ['news.edit','timeline.edit','partners.edit','albums.edit'],
        'content.delete' => ['news.delete','timeline.delete','partners.delete','albums.delete'],
    ];
    $newActions = [];
    foreach ($perms['actions'] as $a) {
        if (isset($map[$a])) {
            foreach ($map[$a] as $granular) $newActions[] = $granular;
        } else {
            $newActions[] = $a;
        }
    }
    $perms['actions'] = array_values(array_unique($newActions));
    return $perms;
}

// ─────────────────────────────────────────────────────────────────────────
// Migration : accueil_layout (nouveau format JSON)
// Convertit les anciennes colonnes accueil_custom_content / accueil_custom_position
// + accueil_news_before_partners en un layout JSON unique. Une fois la migration
// faite (accueil_layout != NULL), les colonnes legacy sont supprimées.
// ─────────────────────────────────────────────────────────────────────────
try {
    require_once __DIR__ . '/config/accueil_layout.php';
    $row = $pdo->query('SELECT * FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($row && empty($row['accueil_layout'])) {
        $layout = loadAccueilLayout($row); // gère la migration depuis legacy
        saveAccueilLayout($pdo, $layout);
        $results[] = ['status' => 'success', 'sql' => 'MIGRATE accueil_layout (depuis legacy custom_content)', 'msg' => 'Layout initialisé'];
    } else {
        $results[] = ['status' => 'skip', 'sql' => 'MIGRATE accueil_layout (depuis legacy custom_content)', 'msg' => 'Déjà initialisé'];
    }
} catch (\Throwable $e) {
    $results[] = ['status' => 'error', 'sql' => 'MIGRATE accueil_layout', 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Migration : persiste les sections pré-définies manquantes (start_point,
// newsletter, …) dans le layout. Sans ça, normalizeAccueilLayout() les ajoute
// "à la volée" à chaque chargement → elles restent des lignes transitoires et
// ne se comportent pas comme les autres sections dans l'éditeur. On les persiste
// ici (avec leur id déterministe row_predef_<type>) → vraies lignes de layout.
// ─────────────────────────────────────────────────────────────────────────
try {
    require_once __DIR__ . '/config/accueil_layout.php';
    $row = $pdo->query("SELECT accueil_layout, accueil_layout_draft FROM setting WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $spDone = 0;
    foreach (['accueil_layout', 'accueil_layout_draft'] as $col) {
        $raw = $row[$col] ?? null;
        if (empty($raw)) continue;                       // colonne vide → rien à migrer
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded)) continue;
        // normalizeAccueilLayout() ajoute toute section pré-définie manquante.
        // Si le nombre de lignes change, c'est qu'il en manquait → on persiste.
        $normalized = normalizeAccueilLayout($decoded);
        if (count($normalized) === count($decoded)) continue; // rien à ajouter
        $pdo->prepare("UPDATE setting SET `$col` = :l WHERE id = 1")
            ->execute(['l' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $spDone++;
    }
    $results[] = ['status' => $spDone > 0 ? 'success' : 'skip',
                  'sql' => 'MIGRATE layout : sections pré-définies manquantes',
                  'msg' => $spDone > 0 ? "$spDone colonne(s) mise(s) à jour" : 'Déjà à jour'];
} catch (\Throwable $e) {
    $results[] = ['status' => 'error', 'sql' => 'MIGRATE layout sections', 'msg' => $e->getMessage()];
}

// Suppression des colonnes obsolètes (après migration)
$dropLegacyAccueil = [
    "ALTER TABLE `setting` DROP COLUMN `accueil_custom_content`",
    "ALTER TABLE `setting` DROP COLUMN `accueil_custom_position`",
    "ALTER TABLE `setting` DROP COLUMN `accueil_news_before_partners`",
];
foreach ($dropLegacyAccueil as $sql) {
    try {
        $pdo->exec($sql);
        $results[] = ['status' => 'success', 'sql' => $sql, 'msg' => 'OK'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, "Can't DROP") || str_contains($msg, 'check that column/key exists')) {
            $results[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'Déjà supprimée'];
        } else {
            $results[] = ['status' => 'error', 'sql' => $sql, 'msg' => $msg];
        }
    }
}

// Migration users.permissions
try {
    $stmt = $pdo->query("SELECT id, permissions FROM users WHERE permissions IS NOT NULL AND permissions != ''");
    $migratedUsers = 0;
    foreach ($stmt as $row) {
        $perms = json_decode($row['permissions'], true);
        if (!is_array($perms)) continue;
        $had = json_encode($perms);
        $perms = migrateContentPermissions($perms);
        $now = json_encode($perms);
        if ($had !== $now) {
            $pdo->prepare("UPDATE users SET permissions = ? WHERE id = ?")->execute([$now, $row['id']]);
            $migratedUsers++;
        }
    }
    $results[] = ['status' => 'success', 'sql' => 'MIGRATE users.permissions content.* → granular', 'msg' => "$migratedUsers utilisateur(s) migré(s)"];
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => 'MIGRATE users.permissions', 'msg' => $e->getMessage()];
}

// Migration login_banned_ips : la colonne d'IP doit s'appeler `ip`
//   Anciens schémas peuvent avoir `ip_address` → on rename
//   Si la table existe sans aucune colonne IP → on ajoute
try {
    $cols = $pdo->query("SHOW COLUMNS FROM login_banned_ips")->fetchAll(PDO::FETCH_COLUMN);
    $hasIp        = in_array('ip', $cols, true);
    $hasIpAddress = in_array('ip_address', $cols, true);
    if (!$hasIp && $hasIpAddress) {
        $pdo->exec("ALTER TABLE `login_banned_ips` CHANGE `ip_address` `ip` VARCHAR(45) NOT NULL");
        $results[] = ['status' => 'success', 'sql' => 'RENAME login_banned_ips.ip_address → ip', 'msg' => 'Colonne renommée'];
    } elseif (!$hasIp && !$hasIpAddress) {
        $pdo->exec("ALTER TABLE `login_banned_ips` ADD COLUMN `ip` VARCHAR(45) NOT NULL");
        $results[] = ['status' => 'success', 'sql' => 'ADD COLUMN login_banned_ips.ip', 'msg' => 'Colonne ajoutée'];
    } else {
        $results[] = ['status' => 'skip', 'sql' => 'login_banned_ips.ip', 'msg' => 'Déjà présente'];
    }

    // Vérifier aussi que la colonne expires_at existe
    $cols2 = $pdo->query("SHOW COLUMNS FROM login_banned_ips")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('expires_at', $cols2, true)) {
        $pdo->exec("ALTER TABLE `login_banned_ips` ADD COLUMN `expires_at` DATETIME NULL DEFAULT NULL");
        $results[] = ['status' => 'success', 'sql' => 'ADD COLUMN login_banned_ips.expires_at', 'msg' => 'Colonne ajoutée'];
    } else {
        $results[] = ['status' => 'skip', 'sql' => 'login_banned_ips.expires_at', 'msg' => 'Déjà présente'];
    }

    // Vérifier la UNIQUE KEY sur ip (évite les doublons)
    $idx = $pdo->query("SHOW INDEX FROM login_banned_ips WHERE Column_name = 'ip'")->fetchAll(PDO::FETCH_ASSOC);
    $hasUnique = false;
    foreach ($idx as $i) { if ((int)$i['Non_unique'] === 0) { $hasUnique = true; break; } }
    if (!$hasUnique) {
        // Avant d'ajouter UNIQUE, on déduplique
        $pdo->exec("DELETE t1 FROM login_banned_ips t1
                    INNER JOIN login_banned_ips t2
                    WHERE t1.id < t2.id AND t1.ip = t2.ip");
        $pdo->exec("ALTER TABLE `login_banned_ips` ADD UNIQUE KEY `idx_ip` (`ip`)");
        $results[] = ['status' => 'success', 'sql' => 'ADD UNIQUE KEY login_banned_ips.idx_ip', 'msg' => 'Index unique ajouté'];
    } else {
        $results[] = ['status' => 'skip', 'sql' => 'login_banned_ips.idx_ip', 'msg' => 'Déjà unique'];
    }
} catch (\Throwable $e) {
    $results[] = ['status' => 'error', 'sql' => 'login_banned_ips schema fix', 'msg' => $e->getMessage()];
}

// Migration setting.role_permissions
try {
    $row = $pdo->query("SELECT role_permissions FROM setting WHERE id = 1")->fetchColumn();
    if ($row) {
        $data = json_decode($row, true);
        if (is_array($data)) {
            $had = json_encode($data);
            foreach (['user','viewer','saisie'] as $r) {
                if (isset($data[$r])) {
                    $data[$r] = migrateContentPermissions($data[$r]);
                }
            }
            $now = json_encode($data);
            if ($had !== $now) {
                $pdo->prepare("UPDATE setting SET role_permissions = ? WHERE id = 1")->execute([$now]);
                $results[] = ['status' => 'success', 'sql' => 'MIGRATE setting.role_permissions content.* → granular', 'msg' => 'OK'];
            } else {
                $results[] = ['status' => 'skip', 'sql' => 'MIGRATE setting.role_permissions', 'msg' => 'Rien à migrer'];
            }
        }
    } else {
        $results[] = ['status' => 'skip', 'sql' => 'MIGRATE setting.role_permissions', 'msg' => 'Aucune conf à migrer'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => 'MIGRATE setting.role_permissions', 'msg' => $e->getMessage()];
}

$countOk   = count(array_filter($results, fn($r) => $r['status'] === 'success'));
$countSkip = count(array_filter($results, fn($r) => $r['status'] === 'skip'));
$countErr  = count(array_filter($results, fn($r) => $r['status'] === 'error'));
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mise à jour BDD — Forbach en Rose</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
    background: #f8f7f9;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
  }
  .update-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,.08);
    max-width: 700px;
    width: 100%;
    overflow: hidden;
  }
  .update-header {
    background: linear-gradient(135deg, #F42182, #db2777);
    padding: 28px 32px;
    color: #fff;
  }
  .update-header h1 {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 4px;
  }
  .update-header p {
    font-size: 13px;
    opacity: .85;
  }
  .update-body { padding: 24px 32px 32px; }

  .summary {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
  }
  .summary-item {
    flex: 1;
    text-align: center;
    padding: 14px 12px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
  }
  .summary-item .num {
    display: block;
    font-size: 28px;
    font-weight: 800;
    line-height: 1.1;
    margin-bottom: 2px;
  }
  .summary-ok   { background: #ecfdf5; color: #065f46; }
  .summary-skip { background: #fffbeb; color: #92400e; }
  .summary-err  { background: #fef2f2; color: #991b1b; }

  .migration-list { list-style: none; }
  .migration-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid #f0e8eb;
    font-size: 13px;
  }
  .migration-item:last-child { border-bottom: none; }

  .migration-icon {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 14px;
  }
  .icon-success { background: #d1fae5; color: #059669; }
  .icon-skip    { background: #fef3c7; color: #d97706; }
  .icon-error   { background: #fee2e2; color: #dc2626; }

  .migration-sql {
    font-family: 'SFMono-Regular', Consolas, monospace;
    font-size: 12px;
    color: #64748b;
    word-break: break-all;
    margin-top: 2px;
  }
  .migration-msg { font-weight: 600; color: #1e293b; }

  .update-footer {
    padding: 16px 32px;
    background: #faf7f8;
    border-top: 1px solid #f0e8eb;
    text-align: center;
    font-size: 12px;
    color: #94a3b8;
  }
  .update-footer a { color: #F42182; text-decoration: none; font-weight: 600; }
  .update-footer a:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="update-card">
  <div class="update-header">
    <h1><i class="bi bi-database-gear me-2"></i>Mise à jour de la base de données</h1>
    <p><?= count($migrations) ?> migration(s) traitée(s)</p>
  </div>

  <div class="update-body">
    <div class="summary">
      <div class="summary-item summary-ok">
        <span class="num"><?= $countOk ?></span> Appliquée(s)
      </div>
      <div class="summary-item summary-skip">
        <span class="num"><?= $countSkip ?></span> Ignorée(s)
      </div>
      <div class="summary-item summary-err">
        <span class="num"><?= $countErr ?></span> Erreur(s)
      </div>
    </div>

    <ul class="migration-list">
      <?php foreach ($results as $r): ?>
      <li class="migration-item">
        <div class="migration-icon <?= $r['status'] === 'success' ? 'icon-success' : ($r['status'] === 'skip' ? 'icon-skip' : 'icon-error') ?>">
          <i class="bi <?= $r['status'] === 'success' ? 'bi-check-lg' : ($r['status'] === 'skip' ? 'bi-dash-lg' : 'bi-x-lg') ?>"></i>
        </div>
        <div>
          <div class="migration-msg"><?= htmlspecialchars($r['msg']) ?></div>
          <div class="migration-sql"><?= htmlspecialchars($r['sql']) ?></div>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="update-footer">
    Terminé — tu peux <a href="inc/dashboard.php">retourner au dashboard</a> et supprimer ce fichier.
  </div>
</div>
</body>
</html>
