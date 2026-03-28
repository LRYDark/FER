<?php
/**
 * Migration — Ajoute les colonnes manquantes.
 * A exécuter UNE SEULE FOIS puis supprimer ce fichier.
 */
require __DIR__ . '/config/config.php';

$results = [];

$columns = [
    // [table, colonne, définition SQL]
    ['setting', 'partners_desc',  'MEDIUMTEXT DEFAULT NULL'],
    ['setting', 'partners_img',   'VARCHAR(255) DEFAULT NULL'],
    ['setting', 'link_twitter',   'VARCHAR(255) DEFAULT NULL'],
    ['setting', 'link_youtube',   'VARCHAR(255) DEFAULT NULL'],
];

foreach ($columns as [$table, $column, $definition]) {
    try {
        $pdo->query("SELECT `$column` FROM `$table` LIMIT 0");
        $results[] = "✓ $table.$column — existe déjà";
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            $results[] = "✚ $table.$column — ajoutée avec succès";
        } catch (PDOException $e2) {
            $results[] = "✗ $table.$column — ERREUR : " . $e2->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Migration</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 600px; margin: 60px auto; padding: 0 20px; }
    h1 { font-size: 22px; }
    ul { list-style: none; padding: 0; }
    li { padding: 8px 12px; margin: 4px 0; border-radius: 8px; background: #f1f5f9; font-size: 15px; }
    .warn { background: #fef3c7; color: #92400e; margin-top: 24px; padding: 16px; border-radius: 8px; font-weight: 600; }
  </style>
</head>
<body>
  <h1>Migration terminée</h1>
  <ul>
    <?php foreach ($results as $r): ?>
      <li><?= htmlspecialchars($r) ?></li>
    <?php endforeach; ?>
  </ul>
  <p class="warn">Supprimez ce fichier (migration.php) maintenant.</p>
</body>
</html>
