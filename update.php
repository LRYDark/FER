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

foreach ($migrations as $sql) {
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
