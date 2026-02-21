<?php
/**
 * Migration one-shot : mise à jour de la table users
 * - Renomme username -> email
 * - Ajoute must_change_password, reset_token, reset_token_expires, is_active
 *
 * Usage : migrate_users.php?key=FER-migrate-2026
 * Exécuter une seule fois puis SUPPRIMER ce fichier.
 */
require __DIR__ . '/config/config.php';

// Protection par clé URL (pas de session requise)
if (($_GET['key'] ?? '') !== 'FER-migrate-2026') {
    die('Accès refusé. Utilisez : migrate_users.php?key=FER-migrate-2026');
}

$messages = [];

try {
    // 1. Vérifier si la migration est nécessaire
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('username', $cols) && !in_array('email', $cols)) {
        // Renommer username -> email
        $pdo->exec("ALTER TABLE `users` CHANGE `username` `email` VARCHAR(255) NOT NULL");
        $pdo->exec("ALTER TABLE `users` DROP INDEX `username`");
        $pdo->exec("ALTER TABLE `users` ADD UNIQUE KEY `email` (`email`)");
        $messages[] = "Colonne username renommée en email.";
    } elseif (in_array('email', $cols)) {
        $messages[] = "Colonne email déjà présente, pas de renommage.";
    }

    // 2. Ajouter must_change_password si absent
    if (!in_array('must_change_password', $cols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `organisation`");
        $messages[] = "Colonne must_change_password ajoutée.";
    } else {
        $messages[] = "Colonne must_change_password déjà présente.";
    }

    // 3. Ajouter reset_token si absent
    if (!in_array('reset_token', $cols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `reset_token` VARCHAR(64) DEFAULT NULL AFTER `must_change_password`");
        $messages[] = "Colonne reset_token ajoutée.";
    } else {
        $messages[] = "Colonne reset_token déjà présente.";
    }

    // 4. Ajouter reset_token_expires si absent
    if (!in_array('reset_token_expires', $cols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `reset_token_expires` DATETIME DEFAULT NULL AFTER `reset_token`");
        $messages[] = "Colonne reset_token_expires ajoutée.";
    } else {
        $messages[] = "Colonne reset_token_expires déjà présente.";
    }

    // 5. Ajouter is_active si absent
    if (!in_array('is_active', $cols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `reset_token_expires`");
        $messages[] = "Colonne is_active ajoutée.";
    } else {
        $messages[] = "Colonne is_active déjà présente.";
    }

    echo "<h2>Migration terminée avec succès</h2>";
    echo "<ul>";
    foreach ($messages as $m) {
        echo "<li>$m</li>";
    }
    echo "</ul>";
    echo "<p><strong>Vous pouvez maintenant supprimer ce fichier (migrate_users.php).</strong></p>";
    echo "<p><a href='login.php'>Retour à la connexion</a></p>";

} catch (Exception $e) {
    echo "<h2>Erreur lors de la migration</h2>";
    echo "<p style='color:red'>" . htmlspecialchars($e->getMessage()) . "</p>";
}
