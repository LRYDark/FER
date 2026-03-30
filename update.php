<?php
/**
 * update.php — Ajoute la colonne mail_template_config à la table setting
 * Lance ce fichier une seule fois via le navigateur puis supprime-le.
 */
require __DIR__ . '/config/config.php';

$migrations = [
    "ALTER TABLE `setting` ADD COLUMN `mail_template_config` TEXT DEFAULT NULL" ,
];

echo "<h2>Migration — mail_template_config</h2><ul>";

foreach ($migrations as $sql) {
    try {
        $pdo->exec($sql);
        echo "<li style='color:green'>OK : " . htmlspecialchars($sql) . "</li>";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            echo "<li style='color:orange'>SKIP (existe d&eacute;j&agrave;) : " . htmlspecialchars($sql) . "</li>";
        } else {
            echo "<li style='color:red'>ERREUR : " . htmlspecialchars($e->getMessage()) . "</li>";
        }
    }
}

echo "</ul><p><strong>Termin&eacute; !</strong> Tu peux supprimer ce fichier.</p>";
