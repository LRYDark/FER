<?php
/**
 * Migration : flash_info + QR Code + refonte table forms
 * À supprimer après exécution.
 */
require_once 'config/config.php';

echo "<h2>Migration en cours...</h2>";

// ── 1. Colonnes setting ──
$alterSettings = [
    "ALTER TABLE `setting` ADD COLUMN `flash_info_text` VARCHAR(500) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `flash_info_active` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `setting` ADD COLUMN `qrcode_mail_mode` ENUM('none','all','first_x') NOT NULL DEFAULT 'none'",
    "ALTER TABLE `setting` ADD COLUMN `qrcode_mail_limit` INT(11) NOT NULL DEFAULT 0",
    "ALTER TABLE `setting` MODIFY COLUMN `titleAccueil` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` MODIFY COLUMN `title` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `titleAccueil_mobile` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `title_mobile` TEXT DEFAULT NULL",
];

echo "<h3>1/3 — Table setting</h3>";
foreach ($alterSettings as $sql) {
    try {
        $pdo->exec($sql);
        echo "✅ " . htmlspecialchars($sql) . "<br>";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            echo "ℹ️ Déjà existant<br>";
        } else {
            echo "❌ " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    }
}

// ── 1b. Suppression colonnes obsolètes ──
echo "<h3>1b — Nettoyage colonnes obsolètes</h3>";
$dropCols = [
    "ALTER TABLE `setting` DROP COLUMN `title_color`",
    "ALTER TABLE `setting` DROP COLUMN `edition`",
];
foreach ($dropCols as $sql) {
    try {
        $pdo->exec($sql);
        echo "✅ " . htmlspecialchars($sql) . "<br>";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), "check that column/key exists") || str_contains($e->getMessage(), "Unknown column")) {
            echo "ℹ️ Déjà supprimée<br>";
        } else {
            echo "❌ " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    }
}

// ── 2. Restructurer table forms ──
echo "<h3>2/3 — Table forms</h3>";

$alterForms = [
    "ALTER TABLE `forms` ADD COLUMN `label` VARCHAR(100) DEFAULT NULL AFTER `fields`",
    "ALTER TABLE `forms` ADD COLUMN `field_type` VARCHAR(20) NOT NULL DEFAULT 'text' AFTER `label`",
    "ALTER TABLE `forms` ADD COLUMN `bdd_column` VARCHAR(50) DEFAULT NULL AFTER `field_type`",
    "ALTER TABLE `forms` ADD COLUMN `is_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `required`",
    "ALTER TABLE `forms` ADD COLUMN `is_default` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_locked`",
    "ALTER TABLE `forms` ADD COLUMN `visible_public` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_default`",
    "ALTER TABLE `forms` ADD COLUMN `visible_admin` TINYINT(1) NOT NULL DEFAULT 1 AFTER `visible_public`",
    "ALTER TABLE `forms` ADD COLUMN `visible_saisie` TINYINT(1) NOT NULL DEFAULT 1 AFTER `visible_admin`",
    "ALTER TABLE `forms` ADD COLUMN `visible_qr` TINYINT(1) NOT NULL DEFAULT 1 AFTER `visible_saisie`",
    "ALTER TABLE `forms` ADD COLUMN `sort_order` INT(11) NOT NULL DEFAULT 0 AFTER `visible_qr`",
    "ALTER TABLE `forms` ADD COLUMN `options_list` TEXT DEFAULT NULL AFTER `sort_order`",
    "ALTER TABLE `forms` ADD COLUMN `encrypted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `options_list`",
];

foreach ($alterForms as $sql) {
    try {
        $pdo->exec($sql);
        echo "✅ " . htmlspecialchars($sql) . "<br>";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            echo "ℹ️ Déjà existant<br>";
        } else {
            echo "❌ " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    }
}

// ── 3. Mettre à jour les données existantes des champs par défaut ──
echo "<h3>3/3 — Données champs par défaut</h3>";

// Mapping: fields => [label, bdd_column, field_type, is_locked, is_default, active, required, encrypted, sort_order, options_list]
$defaults = [
    'required_name'          => ['Nom',            'nom',        'text',   1, 1, 1, 1, 1, 1,  null],
    'required_firstname'     => ['Prénom',          'prenom',     'text',   1, 1, 1, 1, 1, 2,  null],
    'required_email'         => ['Email',           'email',      'email',  1, 1, 1, 1, 1, 3,  null],
    'required_phone'         => ['Téléphone',       'tel',        'text',   0, 1, 1, 0, 1, 4,  null],
    'required_date_of_birth' => ['Date de naissance','naissance', 'date',   0, 1, 1, 0, 1, 5,  null],
    'required_sex'           => ['Sexe',            'sexe',       'select', 0, 1, 1, 0, 0, 6,  'H,F,Autre'],
    'required_city'          => ['Ville',           'ville',      'text',   0, 1, 1, 0, 1, 7,  null],
    'required_company'       => ['Entreprise',      'entreprise', 'text',   0, 1, 1, 0, 1, 8,  null],
    'required_tshirt'        => ['Taille T-shirt',  'tshirt_size','select', 0, 1, 0, 0, 0, 9,  '-,XS,S,M,L,XL,XXL'],
];

$upd = $pdo->prepare(
    'UPDATE forms SET label = :label, bdd_column = :bdd, field_type = :ftype,
     is_locked = :locked, is_default = :def, active = :active, required = :req,
     encrypted = :enc, sort_order = :sort, options_list = :opts
     WHERE fields = :fields'
);

foreach ($defaults as $fields => $d) {
    $upd->execute([
        'label'  => $d[0], 'bdd'    => $d[1], 'ftype' => $d[2],
        'locked' => $d[3], 'def'    => $d[4], 'active' => $d[5],
        'req'    => $d[6], 'enc'    => $d[7], 'sort'  => $d[8],
        'opts'   => $d[9], 'fields' => $fields
    ]);
    $affected = $upd->rowCount();
    echo ($affected > 0 ? "✅" : "⚠️ Non trouvé") . " $fields → {$d[0]}<br>";
}

// Ajouter tshirt_size s'il n'existe pas encore
$check = $pdo->prepare('SELECT COUNT(*) FROM forms WHERE fields = ?');
$check->execute(['required_tshirt']);
if ($check->fetchColumn() == 0) {
    $ins = $pdo->prepare(
        'INSERT INTO forms (fields, label, field_type, bdd_column, active, required, is_locked, is_default,
         visible_public, visible_admin, visible_saisie, visible_qr, sort_order, options_list, encrypted)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute(['required_tshirt', 'Taille T-shirt', 'select', 'tshirt_size', 0, 0, 0, 1, 0, 1, 0, 0, 9, '-,XS,S,M,L,XL,XXL', 0]);
    echo "✅ Champ tshirt_size ajouté (désactivé par défaut)<br>";
}

// ── 4. Colonnes vidéo accueil + mode maintenance ──
echo "<h3>4 — Vidéo accueil + Mode maintenance</h3>";

$alterNew = [
    "ALTER TABLE `setting` ADD COLUMN `navbar_logo` VARCHAR(255) DEFAULT 'logo_fer_rose.png'",
    "ALTER TABLE `setting` ADD COLUMN `subtitle_accueil` VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `subtitle_accueil_mobile` VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `video_accueil` VARCHAR(255) DEFAULT 'FER.mp4'",
    "ALTER TABLE `setting` ADD COLUMN `maintenance_mode` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `setting` ADD COLUMN `maintenance_message` VARCHAR(500) DEFAULT NULL",
];

foreach ($alterNew as $sql) {
    try {
        $pdo->exec($sql);
        echo "✅ " . htmlspecialchars($sql) . "<br>";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            echo "ℹ️ Déjà existant<br>";
        } else {
            echo "❌ " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    }
}

// Mettre la valeur par défaut pour les installations existantes
try {
    $pdo->exec("UPDATE `setting` SET `video_accueil` = 'FER.mp4' WHERE `id` = 1 AND (`video_accueil` IS NULL OR `video_accueil` = '')");
    echo "✅ Vidéo par défaut initialisée (FER.mp4)<br>";
} catch (PDOException $e) {
    echo "❌ " . htmlspecialchars($e->getMessage()) . "<br>";
}

// ── 5. Nettoyage colonnes obsolètes (tout passe par TinyMCE maintenant) ──
echo "<h3>5 — Nettoyage colonnes obsolètes</h3>";

$dropOld = [
    "ALTER TABLE `setting` DROP COLUMN `picture`",
    "ALTER TABLE `setting` DROP COLUMN `picture_mobile`",
    "ALTER TABLE `setting` DROP COLUMN `picture_accueil`",
    "ALTER TABLE `setting` DROP COLUMN `picture_accueil_mobile`",
    "ALTER TABLE `setting` DROP COLUMN `header_mode`",
    "ALTER TABLE `setting` DROP COLUMN `header_mode_mobile`",
    "ALTER TABLE `setting` DROP COLUMN `hero_mode`",
    "ALTER TABLE `setting` DROP COLUMN `hero_mode_mobile`",
    "ALTER TABLE `setting` DROP COLUMN `header_img_align`",
    "ALTER TABLE `setting` DROP COLUMN `header_img_align_mobile`",
    "ALTER TABLE `setting` DROP COLUMN `hero_img_align`",
    "ALTER TABLE `setting` DROP COLUMN `hero_img_align_mobile`",
];

foreach ($dropOld as $sql) {
    try {
        $pdo->exec($sql);
        echo "✅ " . htmlspecialchars($sql) . "<br>";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), "check that column/key exists") || str_contains($e->getMessage(), "Unknown column")) {
            echo "ℹ️ Déjà supprimée<br>";
        } else {
            echo "❌ " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    }
}

echo "<br><strong>Migration terminée. Vous pouvez supprimer ce fichier.</strong>";
