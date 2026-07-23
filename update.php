<?php
/**
 * update.php — Migrations de base de données
 * Réservé aux administrateurs connectés. À lancer après une mise à jour ;
 * la page propose ensuite de se supprimer elle-même (bouton « Oui / Non »).
 */
require __DIR__ . '/src/core/config.php';
require_once __DIR__ . '/src/security/csrf.php';
require_once __DIR__ . '/src/content/registrations_core.php';

/* ════════════════════════════════════════════════════════════════════════════
 * SÉCURITÉ : accès strictement réservé à un administrateur connecté.
 * ----------------------------------------------------------------------------
 * Ce fichier exécute des migrations SQL et expose un outil d'import de fichier
 * (repair-dates). Sans ce garde, N'IMPORTE QUI atteignant l'URL pourrait les
 * déclencher. On refuse donc tout accès non authentifié / non-admin AVANT toute
 * autre logique — y compris avant le sous-outil repair-dates et le handler de
 * suppression ci-dessous. (Défense en profondeur : reste valable même si le
 * fichier est censé être supprimé après usage.)
 * ════════════════════════════════════════════════════════════════════════════ */
header('X-Robots-Tag: noindex, nofollow', true);
if (!isset($_SESSION['uid']) || (($_SESSION['role'] ?? null) !== 'admin')) {
    http_response_code(403);
    header('Location: login.php');   // update.php et login.php sont à la racine
    exit;
}

/* ════════════════════════════════════════════════════════════════════════════
 * AUTO-SUPPRESSION : « Voulez-vous supprimer update.php ? »
 * ----------------------------------------------------------------------------
 * Déclenché par le bouton « Oui, supprimer » affiché en bas de la page de
 * résultat. POST protégé par CSRF (admin déjà vérifié ci-dessus). On traite ce
 * cas AVANT de rejouer les migrations : cliquer « Oui » ne relance rien, ça
 * supprime juste le fichier et affiche une confirmation. « Non » = simple lien
 * retour dashboard, aucune action.
 * ════════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_self') {
    $csrfOk  = csrf_verify();
    $deleted = $csrfOk ? @unlink(__FILE__) : false;
    ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Suppression de update.php — Forbach en Rose</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #f8f7f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
  .card { background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,.08); max-width: 520px; width: 100%; overflow: hidden; text-align: center; }
  .hd { padding: 28px 32px; color: #fff; }
  .hd.ok  { background: linear-gradient(135deg, #10b981, #059669); }
  .hd.err { background: linear-gradient(135deg, #ef4444, #b91c1c); }
  .hd i { font-size: 40px; margin-bottom: 10px; display: block; }
  .hd h1 { font-size: 20px; font-weight: 700; }
  .bd { padding: 24px 32px 28px; font-size: 14px; color: #475569; line-height: 1.6; }
  .btn { display: inline-flex; align-items: center; gap: 8px; margin-top: 18px; background: linear-gradient(135deg, var(--primary, #f42182), var(--primary-hover, #db2777)); color: var(--primary-text, #fff); border: none; border-radius: 10px; padding: 12px 22px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; }
  code { background: #f1f5f9; padding: 2px 6px; border-radius: 6px; font-size: 13px; }
</style>
</head>
<body>
<div class="card">
  <?php if ($deleted): ?>
  <div class="hd ok"><i class="bi bi-check-circle-fill"></i><h1>update.php supprimé</h1></div>
  <div class="bd">
    Le fichier <code>update.php</code> a bien été supprimé du serveur.
    Le lien « Mise à jour BDD » disparaîtra du menu d'administration.
    <br><a class="btn" href="inc/dashboard.php"><i class="bi bi-arrow-left"></i> Retour au dashboard</a>
  </div>
  <?php elseif (!$csrfOk): ?>
  <div class="hd err"><i class="bi bi-shield-exclamation"></i><h1>Session expirée</h1></div>
  <div class="bd">
    Jeton de sécurité invalide. Rechargez la page et réessayez.
    <br><a class="btn" href="update.php"><i class="bi bi-arrow-clockwise"></i> Recharger</a>
  </div>
  <?php else: ?>
  <div class="hd err"><i class="bi bi-exclamation-triangle-fill"></i><h1>Suppression impossible</h1></div>
  <div class="bd">
    Le serveur n'a pas pu supprimer <code>update.php</code> (permissions du fichier).
    Supprimez-le manuellement via FTP / gestionnaire de fichiers.
    <br><a class="btn" href="inc/dashboard.php"><i class="bi bi-arrow-left"></i> Retour au dashboard</a>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
    <?php
    exit;
}

/* ════════════════════════════════════════════════════════════════════════════
 * OUTIL : Réparation des dates d'inscription (created_at)
 * ----------------------------------------------------------------------------
 * Sous-page dédiée (update.php?tool=repair-dates) : on ouvre le fichier d'export
 * AssoConnect d'origine, et on recorrige UNIQUEMENT les inscriptions dont la date
 * d'ajout a été inversée jour/mois par l'ancien bug d'import. Aperçu par défaut
 * (n'écrit rien) ; l'écriture nécessite de cocher explicitement « Appliquer ».
 * Cette branche se termine par exit : elle ne déclenche JAMAIS les migrations.
 * ════════════════════════════════════════════════════════════════════════════ */
if (($_GET['tool'] ?? '') === 'repair-dates') {

    $report   = null;
    $errorMsg = null;
    $applied  = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            $errorMsg = 'Jeton de sécurité invalide. Rechargez la page et réessayez.';
        } elseif (empty($_FILES['repair_file']) || ($_FILES['repair_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errorMsg = 'Aucun fichier reçu (ou upload échoué). Sélectionnez un fichier Excel (.xlsx / .xls).';
        } else {
            $apply  = !empty($_POST['apply']);
            $report = regcore_repairCreatedAtDates(
                $pdo,
                $_FILES['repair_file']['tmp_name'],
                (string) $_FILES['repair_file']['name'],
                $apply
            );
            if (empty($report['ok'])) {
                $errorMsg = $report['message'] ?? 'Erreur inconnue lors du traitement du fichier.';
                $report   = null;
            } else {
                $applied = $apply;
            }
        }
    }

    /* Affichage JJ/MM/AAAA d'une valeur 'Y-m-d H:i:s' */
    $fmtDate = static function ($ymdhms): string {
        $p = explode('-', substr((string) $ymdhms, 0, 10));
        return count($p) === 3 ? "{$p[2]}/{$p[1]}/{$p[0]}" : htmlspecialchars((string) $ymdhms);
    };
    ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Réparer les dates d'inscription — Forbach en Rose</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #f8f7f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
  .card { background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,.08); max-width: 760px; width: 100%; overflow: hidden; }
  .hd { background: linear-gradient(135deg, var(--primary, #f42182), var(--primary-hover, #db2777)); padding: 28px 32px; color: var(--primary-text, #fff); }
  .hd h1 { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
  .hd p { font-size: 13px; opacity: .9; }
  .bd { padding: 24px 32px 28px; }
  .intro { font-size: 13px; color: #475569; line-height: 1.6; margin-bottom: 20px; }
  .field { margin-bottom: 16px; }
  .field label.lbl { display: block; font-size: 13px; font-weight: 600; color: #1e293b; margin-bottom: 6px; }
  input[type=file] { width: 100%; font-size: 13px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; background: #faf7f8; }
  .apply-row { display: flex; align-items: center; gap: 8px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 12px 14px; font-size: 13px; color: #92400e; margin-bottom: 16px; }
  .apply-row input { width: 16px; height: 16px; }
  .btn { display: inline-flex; align-items: center; gap: 8px; background: linear-gradient(135deg, var(--primary, #f42182), var(--primary-hover, #db2777)); color: var(--primary-text, #fff); border: none; border-radius: 10px; padding: 12px 22px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; }
  .btn:hover { opacity: .92; }
  .btn-sec { background: #f1f5f9; color: #475569; }
  .alert { border-radius: 10px; padding: 14px 16px; font-size: 13px; margin-bottom: 18px; line-height: 1.5; }
  .alert-err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .alert-ok  { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
  .alert-info{ background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
  .alert-warn{ background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; margin: 8px 0 4px; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #f0e8eb; }
  th { color: #64748b; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
  td.no { font-family: 'SFMono-Regular', Consolas, monospace; }
  .old { color: #dc2626; text-decoration: line-through; }
  .new { color: #059669; font-weight: 700; }
  .scroll { max-height: 320px; overflow: auto; border: 1px solid #f0e8eb; border-radius: 10px; }
  .ft { padding: 16px 32px; background: #faf7f8; border-top: 1px solid #f0e8eb; text-align: center; font-size: 12px; color: #94a3b8; }
  .ft a { color: var(--primary, #f42182); text-decoration: none; font-weight: 600; }
</style>
</head>
<body>
<div class="card">
  <div class="hd">
    <h1><i class="bi bi-calendar-check me-2"></i>Réparer les dates d'inscription</h1>
    <p>Recorrige les « dates d'ajout » inversées (jour/mois) à partir du fichier d'export AssoConnect d'origine.</p>
  </div>
  <div class="bd">

    <?php if ($errorMsg !== null): ?>
      <div class="alert alert-err"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <?php if ($report !== null): ?>
      <?php
        $nbFix    = count($report['fixes']);
        $nbFuture = count($report['future_unmatched']);
      ?>
      <?php if ($applied): ?>
        <div class="alert alert-ok"><i class="bi bi-check-circle me-1"></i>
          <strong><?= (int) $report['applied'] ?></strong> date(s) corrigée(s) en base.</div>
      <?php elseif ($nbFix > 0): ?>
        <div class="alert alert-info"><i class="bi bi-eye me-1"></i>
          <strong>Aperçu</strong> — <?= $nbFix ?> date(s) seraient corrigées. <em>Rien n'a été modifié.</em>
          Pour appliquer : re-sélectionnez le fichier, cochez « Appliquer » puis relancez.</div>
      <?php else: ?>
        <div class="alert alert-ok"><i class="bi bi-check-circle me-1"></i>
          Aucune date à corriger : tout est cohérent avec le fichier
          (<?= (int) $report['source_count'] ?> inscription(s) lues).</div>
      <?php endif; ?>

      <?php if ($nbFix > 0): ?>
        <div class="scroll">
          <table>
            <thead><tr><th>N° inscription</th><th>Date actuelle</th><th></th><th>Date corrigée</th></tr></thead>
            <tbody>
            <?php foreach ($report['fixes'] as $f): ?>
              <tr>
                <td class="no"><?= htmlspecialchars($f['no']) ?></td>
                <td class="old"><?= $fmtDate($f['old']) ?></td>
                <td><i class="bi bi-arrow-right"></i></td>
                <td class="new"><?= $fmtDate($f['new']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($nbFuture > 0): ?>
        <div class="alert alert-warn" style="margin-top:16px;">
          <i class="bi bi-exclamation-triangle me-1"></i>
          <strong><?= $nbFuture ?> inscription(s)</strong> ont une date dans le futur mais ne sont
          <strong>pas</strong> dans ce fichier — à corriger à la main, ou relancez avec le fichier
          d'import qui les contient :
          <div class="scroll" style="margin-top:8px;max-height:160px;">
            <table>
              <thead><tr><th>N° inscription</th><th>Date actuelle</th></tr></thead>
              <tbody>
              <?php foreach ($report['future_unmatched'] as $u): ?>
                <tr><td class="no"><?= htmlspecialchars($u['no']) ?></td><td><?= $fmtDate($u['created_at']) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <p class="intro">
        Sélectionnez le <strong>fichier d'export AssoConnect</strong> (le même que pour l'import).
        L'outil relit la vraie date de création (valeur brute, non ambiguë) et compare avec la base.
        Par défaut c'est un <strong>aperçu</strong> : rien n'est modifié tant que vous ne cochez pas
        « Appliquer ». <strong>Pensez à sauvegarder la base avant d'appliquer.</strong>
      </p>
    <?php endif; ?>

    <form method="post" action="?tool=repair-dates" enctype="multipart/form-data" style="margin-top:8px;">
      <?= csrf_field() ?>
      <div class="field">
        <label class="lbl" for="repair_file">Fichier Excel d'export (.xlsx / .xls)</label>
        <input type="file" id="repair_file" name="repair_file" accept=".xlsx,.xls" required>
      </div>
      <label class="apply-row">
        <input type="checkbox" name="apply" value="1">
        <span><strong>Appliquer réellement</strong> les corrections en base (sinon : aperçu seulement)</span>
      </label>
      <button type="submit" class="btn"><i class="bi bi-play-fill"></i> Analyser / Corriger</button>
      <a href="inc/dashboard.php" class="btn btn-sec"><i class="bi bi-arrow-left"></i> Dashboard</a>
    </form>

  </div>
  <div class="ft">
    Outil ponctuel — pensez à <a href="update.php">revenir aux migrations</a> ou à supprimer ce fichier après usage.
  </div>
</div>
</body>
</html>
    <?php
    exit;
}

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
    // storage/logs/api.log (et non en BDD) : on supprime l'ancienne table si
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

    // Import automatique AssoConnect : table de configuration à ligne unique (id=1).
    // Le mot de passe AssoConnect est chiffré (AES-256-GCM, ENCRYPTION_KEY du site) dans ac_password_enc.
    // Le QR n'est PAS une option : il suit le réglage global qrcode_mail_mode, comme l'import manuel.
    // Le token partagé des endpoints (worker_token) est AUTO-GÉNÉRÉ et géré depuis l'UI.
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
    "INSERT IGNORE INTO `sync_assoconnect` (`id`) VALUES (1)",
    // Tables déjà créées par une version antérieure : on ajoute la colonne du token.
    "ALTER TABLE `sync_assoconnect` ADD COLUMN `worker_token` VARCHAR(64) DEFAULT NULL",

    // Champ libre « Commentaire » sur chaque inscription. Sert aussi à stocker
    // l'autorisation du représentant légal pour les inscrits mineurs (nom/prénom).
    // Chiffré (encrypted=1 dans `forms`) car il peut contenir des données personnelles.
    "ALTER TABLE `registrations` ADD COLUMN `commentaire` TEXT DEFAULT NULL",

    // Déverrouillage du champ « Email » : il était figé (is_locked=1) et donc non
    // modifiable depuis « Gestion des champs du formulaire ». On le déverrouille pour
    // permettre à l'admin de gérer son caractère obligatoire et sa visibilité.
    "UPDATE `forms` SET `is_locked` = 0 WHERE `bdd_column` = 'email'",

    // Préférences d'interface par utilisateur (JSON) : ordre des colonnes du tableau
    // du dashboard, et toute future préférence d'affichage propre à chaque compte.
    "ALTER TABLE `users` ADD COLUMN `ui_prefs` TEXT DEFAULT NULL",

    // Tarif enfant automatique selon l'âge (import Excel / ajout multiple) :
    //   - child_pricing_enabled : active la surcharge du montant pour les < seuil
    //   - child_age_threshold   : âge seuil (12 par défaut, sert aussi aux libellés « -N ans »)
    //   - child_amount          : montant appliqué aux enfants sous le seuil (0 = gratuit)
    "ALTER TABLE `setting` ADD COLUMN `child_pricing_enabled` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `setting` ADD COLUMN `child_age_threshold` INT(10) NOT NULL DEFAULT 12",
    "ALTER TABLE `setting` ADD COLUMN `child_amount` INT(10) NOT NULL DEFAULT 0",

    // ─────────────────────────────────────────────────────────────────────
    // Accès « Remise T-shirts » pour bénévoles (sans compte).
    //   tshirt_access            : config à ligne unique (id=1) — interrupteur
    //                              ON/OFF, token de campagne (régénérer = tout
    //                              invalider), ouverture + expiration (auto-off).
    //   tshirt_access_sessions   : une ligne par appareil bénévole. Le bénévole
    //                              saisit son nom → demande (status=pending) →
    //                              l'admin valide (approved) ou refuse. La session
    //                              est liée au campaign_token courant : régénérer
    //                              le token invalide toutes les sessions d'un coup.
    //   tshirt_handout_log       : journal des remises (qui/quelle taille/quand)
    //                              pour la traçabilité, faute d'authentification forte.
    // ─────────────────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS `tshirt_access` (
      `id` TINYINT(1) NOT NULL DEFAULT 1,
      `enabled` TINYINT(1) NOT NULL DEFAULT 0,
      `campaign_token` VARCHAR(64) DEFAULT NULL,
      `opened_at` DATETIME DEFAULT NULL,
      `expires_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "INSERT IGNORE INTO `tshirt_access` (`id`) VALUES (1)",
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

    // Inscription « sur place » via QR : chaque QR peut afficher un choix de
    // prestation (au lieu du champ Paiement) et enregistrer une méthode de paiement
    // masquée personnalisée (ex. « retrait t-shirt »), définie à la création du QR.
    "ALTER TABLE `qrcodes` ADD COLUMN `onsite_mode` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `qrcodes` ADD COLUMN `payment_label` VARCHAR(50) DEFAULT 'retrait t-shirt'",

    // Date de fermeture propre au QR code : indépendante de la fermeture des
    // inscriptions en ligne (setting.registration_auto_close). Passé cette date,
    // le lien avec token devient inactif ; un QR valide non expiré reste utilisable
    // même quand les inscriptions en ligne sont fermées. NULL = pas d'expiration.
    "ALTER TABLE `qrcodes` ADD COLUMN `expires_at` DATETIME DEFAULT NULL",

    // Par QR : décider si le mail de confirmation d'une inscription issue de CE QR
    // inclut le QR code (1 = suit la config globale qrcode_mail_mode) ou jamais
    // (0 = mail envoyé sans QR code, quel que soit le réglage du site).
    "ALTER TABLE `qrcodes` ADD COLUMN `send_qrcode` TINYINT(1) NOT NULL DEFAULT 1",

    // Texte d'aide / consentement affiché sous un champ (notamment le bloc
    // « Autorisation parentale (mineur) » : mention de consentement du responsable légal).
    "ALTER TABLE `forms` ADD COLUMN `help_text` TEXT DEFAULT NULL",
    "UPDATE `forms` SET `help_text` = 'En renseignant le nom et le prénom du responsable légal ci-dessus, je certifie être le représentant légal de l''enfant mineur inscrit, j''autorise sa participation à l''événement et je consens au traitement de ces informations.' WHERE `field_type` = 'guardian' AND (`help_text` IS NULL OR `help_text` = '')",

    // Champ personnalisé rattaché au bloc « Autorisation parentale (mineur) » : il
    // n'a pas de colonne BDD (bdd_column NULL) et sa valeur est injectée dans le
    // commentaire (comme le nom/prénom du responsable). guardian_section = 1 le marque.
    "ALTER TABLE `forms` ADD COLUMN `guardian_section` TINYINT(1) NOT NULL DEFAULT 0",

    // Message d'information complémentaire affiché sous « Les inscriptions sont
    // actuellement fermées » sur la page publique d'inscription. Permet à l'admin
    // d'indiquer, par ex., où et quand s'inscrire / récupérer son t-shirt sur place.
    "ALTER TABLE `setting` ADD COLUMN `registration_closed_message` TEXT DEFAULT NULL",

    // Le champ « naissance » ne stocke plus une date : on ne conserve que l'ÂGE
    // (âge saisi tel quel, année ou date convertie en âge). On renomme le libellé
    // « Date de naissance » → « Âge » UNIQUEMENT s'il porte encore la valeur d'origine
    // (personnalisation admin préservée). Idempotent : 0 ligne au 2ᵉ passage.
    "UPDATE `forms` SET `label` = 'Âge' WHERE `bdd_column` = 'naissance' AND `label` = 'Date de naissance'",

    // Le champ « Entreprise » peut aussi désigner un groupe / une famille / une
    // association : libellé élargi (mêmes conditions que ci-dessus).
    "UPDATE `forms` SET `label` = 'Entreprise / Groupe' WHERE `bdd_column` = 'entreprise' AND `label` = 'Entreprise'",

    // Inscriptions groupées (formulaire QR multi-personnes + ajout multiple récap) :
    // un identifiant de groupe partagé relie les inscrits d'un même lot. Sert au QR
    // « groupé » (un seul QR encode « G:<group_id> ») qui, au scan, affiche TOUS les
    // membres du groupe pour valider les tailles d'un coup.
    "ALTER TABLE `registrations` ADD COLUMN `group_id` VARCHAR(40) DEFAULT NULL, ADD INDEX `group_id` (`group_id`)",

    // Le champ « Ville » est désactivable dans l'admin (is_locked=0) : l'INSERT
    // dynamique (registrations_core / admin-api) l'omet alors, et MySQL en mode
    // strict refuse (erreur 1364 « Field 'ville' doesn't have a default value »).
    // Un défaut '' rend la colonne omissible sans changer le comportement existant.
    "ALTER TABLE `registrations` MODIFY COLUMN `ville` VARCHAR(255) NOT NULL DEFAULT ''",

    // Mode maintenance (Réglages → Maintenance) : bloque les pages publiques via
    // checkMaintenance() (src/core/config.php). Colonnes présentes dans install.php
    // mais jamais migrées ici → sur une base mise à jour, la vérification échouait
    // silencieusement et le mode maintenance était sans effet.
    "ALTER TABLE `setting` ADD COLUMN `maintenance_mode` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `setting` ADD COLUMN `maintenance_message` VARCHAR(500) DEFAULT NULL",

    // 🔒 [SEC-SESSION] Timeout de session par inactivité (minutes ; 0 = jamais).
    // Configurable dans Réglages → Personnalisation. Enforcé dans src/core/config.php.
    "ALTER TABLE `setting` ADD COLUMN `session_lifetime` INT NOT NULL DEFAULT 0",

    // 🔒 [SEC-SESSION] Durée de vie ABSOLUE de session (minutes ; 0 = jamais) : déconnexion
    // X minutes après la connexion, même si l'utilisateur est actif. Complémentaire de
    // session_lifetime (inactivité). Enforcé dans src/core/config.php.
    "ALTER TABLE `setting` ADD COLUMN `session_absolute_lifetime` INT NOT NULL DEFAULT 0",

    // Bandeau flash : mode on/off/auto + fenêtre de programmation (début/fin).
    "ALTER TABLE `setting` ADD COLUMN `flash_info_mode` ENUM('on','off','auto') NOT NULL DEFAULT 'off'",
    "ALTER TABLE `setting` ADD COLUMN `flash_info_start` DATETIME DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `flash_info_end` DATETIME DEFAULT NULL",
    // Report de l'état existant : un bandeau actuellement activé reste en mode « on ».
    "UPDATE `setting` SET `flash_info_mode` = 'on' WHERE `flash_info_active` = 1 AND `flash_info_mode` = 'off'",

    // ── Assistant virtuel (chatbot) : infos pratiques + activation ──
    // Horaires de la course (texte libre), point de rendez-vous, modalités de
    // retrait des t-shirts — réponses servies par le chatbot du site public.
    "ALTER TABLE `setting` ADD COLUMN `course_horaires` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `course_rdv` TEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `tshirt_retrait_info` TEXT DEFAULT NULL",
    // Inscription sur place (lieu + horaires) : proposée par le chatbot quand un
    // visiteur signale un problème d'inscription en ligne (réglable dans
    // l'admin Assistant / FAQ, vide = non proposée).
    "ALTER TABLE `setting` ADD COLUMN `registration_onsite_info` TEXT DEFAULT NULL",
    // Pages légales éditables (Réglages → Pages légales) : mentions légales +
    // politique de confidentialité, affichées sur /mentions-legales et
    // /politique-confidentialite (liens du footer).
    "ALTER TABLE `setting` ADD COLUMN `legal_mentions` LONGTEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `legal_privacy` LONGTEXT DEFAULT NULL",
    "ALTER TABLE `setting` ADD COLUMN `chatbot_enabled` TINYINT(1) NOT NULL DEFAULT 1",
    // Questions incomprises par le chatbot (journal anonyme, consultable dans
    // Réglages pour enrichir les réponses au fil du temps).
    "CREATE TABLE IF NOT EXISTS `chatbot_unmatched` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `question` varchar(500) NOT NULL,
      `created_at` timestamp NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // ── FAQ de l'assistant virtuel : questions/réponses gérées depuis l'admin
    // (page Assistant / FAQ), servies par le chatbot via mots-clés et par la
    // page publique faq.php.
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

    // ── Questions par défaut de la FAQ : insérées UNIQUEMENT si la FAQ est
    // vide (jamais réinsérées après modification/suppression dans l'admin).
    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT s.q, s.a, s.k, s.p, 1 FROM (
       SELECT 'Les enfants peuvent-ils participer ?' AS q,
              'Bien sûr ! Les enfants sont les bienvenus, accompagnés d''un adulte. Un tarif réduit s''applique aux plus jeunes — tous les détails sur la page d''inscription : /register' AS a,
              'enfant, enfants, age, ado, ados, mineur, mineurs, jeune, jeunes, famille, fils, fille, bebe' AS k, 1 AS p
       UNION ALL SELECT 'La marche est-elle réservée aux femmes ?',
              'Non ! La marche est ouverte à toutes et à tous — femmes, hommes, enfants. L''important, c''est de se mobiliser ensemble contre le cancer du sein.',
              'femme, femmes, homme, hommes, mari, garcon, garcons, monsieur, messieurs, masculin, mixte, reservee', 2
       UNION ALL SELECT 'Faut-il un certificat médical ?',
              'Non : il s''agit d''une marche ouverte à tous, à allure libre — aucun certificat médical ni licence n''est demandé. Le règlement complet est consultable sur la page d''inscription (bouton « Règlement ») : /register',
              'certificat, medical, licence, sante, medecin, attestation, justificatif', 3
       UNION ALL SELECT 'Comment modifier ou annuler mon inscription ?',
              'Écrivez-nous via le formulaire de contact (bouton « Nous écrire » de l''assistant) en précisant l''e-mail utilisé lors de l''inscription : nous nous en occupons rapidement.',
              'modifier, modification, changer, changement, corriger, annuler, annulation, desinscrire, desinscription, remboursement, rembourse, trompe, faute', 4
       UNION ALL SELECT 'Et s''il pleut, la marche est-elle annulée ?',
              'La marche a lieu même en cas de petite pluie — prévoyez simplement une tenue adaptée ! En cas de conditions exceptionnelles, l''information serait publiée sur le site et nos réseaux sociaux.',
              'pluie, pleut, meteo, intemperies, orage, tempete, neige, vent, canicule, mauvais temps, reporte, reportee, report, annule, annulee', 5
       UNION ALL SELECT 'Puis-je venir avec mon chien ou une poussette ?',
              'Les poussettes sont les bienvenues sur le parcours. Les chiens tenus en laisse sont acceptés, sous la responsabilité de leur maître. En cas de doute, écrivez-nous !',
              'chien, chiens, chat, chats, toutou, animal, animaux, poussette, poussettes, landau, laisse', 6
       UNION ALL SELECT 'Le parcours est-il accessible aux personnes à mobilité réduite ?',
              'Nous faisons notre possible pour que le parcours soit accessible au plus grand nombre. Pour une situation particulière (fauteuil roulant, mobilité réduite), écrivez-nous : nous vous conseillerons au mieux.',
              'pmr, fauteuil, roulant, handicap, handicape, handicapee, mobilite, accessible, accessibilite, bequilles', 7
       UNION ALL SELECT 'Y a-t-il une buvette ou des animations sur place ?',
              'Oui, un village d''accueil vous attend le jour J (buvette, stands, animations). Le programme détaillé est annoncé à l''approche de l''événement sur le site et nos réseaux.',
              'buvette, restauration, manger, boire, boisson, boissons, nourriture, sandwich, cafe, eau, ravitaillement, animation, animations, stand, stands, village, musique, concert, toilettes, wc, sanitaires, vestiaire, vestiaires, consigne', 8
       UNION ALL SELECT 'Comment devenir bénévole ?',
              'Merci pour votre élan ! Écrivez-nous via le formulaire de contact en indiquant vos disponibilités : l''équipe organisatrice reviendra vers vous.',
              'benevole, benevoles, benevolat, volontaire, volontaires, aider, coup de main', 9
       UNION ALL SELECT 'Comment devenir partenaire ou sponsor ?',
              'Découvrez nos partenaires actuels : /partenaires — pour rejoindre l''aventure (don, lot, mécénat, visibilité), écrivez-nous via le formulaire de contact : nous vous enverrons les modalités.',
              'partenaire, partenaires, partenariat, sponsor, sponsors, sponsoriser, sponsoring, entreprise, societe, mecenat, mecene', 10
       UNION ALL SELECT 'Je n''ai pas reçu mon mail de confirmation / QR code',
              'Vérifiez d''abord votre dossier spam / indésirables. Vous pouvez demander un renvoi automatique directement à l''assistant du site (tapez « je n''ai pas reçu mon QR code ») : le mail est renvoyé à l''adresse utilisée lors de l''inscription.',
              'confirmation, spam, indesirable, indesirables, courrier', 11
     ) AS s
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` LIMIT 1)",

    // ── FAQ : problème d'inscription en ligne — insérée même dans une FAQ déjà
    // remplie (garde-fou sur la question elle-même, jamais de doublon).
    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'L''inscription en ligne ne fonctionne pas, que faire ?',
            'Pas de panique ! Réessayez d''abord un peu plus tard, idéalement depuis un autre navigateur — il s''agit le plus souvent d''un souci passager. Si le problème persiste, écrivez-nous via l''assistant du site (bouton « Nous écrire ») en décrivant l''erreur rencontrée : nous vous aiderons rapidement. Selon les modalités annoncées, une inscription sur place peut aussi être possible — demandez à l''assistant.',
            'inscription en ligne, probleme d inscription, probleme inscription, erreur inscription, erreur d inscription, inscription impossible, inscription bloquee, paiement refuse, paiement impossible', 12, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'L''inscription en ligne ne fonctionne pas%')",


    // ── FAQ : nouvelles questions (paiement, date limite, groupe, dossard,
    // chrono) — insérées même dans une FAQ remplie, garde-fou par question.
    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Comment payer mon inscription ?',
            'Le paiement se fait en ligne, de façon sécurisée, à la fin du formulaire d''inscription : /register — aucune avance, aucun frais caché. Pour toute autre modalité (chèque, espèces, inscription sur place), écrivez-nous via l''assistant du site.',
            'payer, paiement, carte, cb, cheque, especes, liquide, virement, paypal', 13, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Comment payer mon inscription%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Jusqu''à quand peut-on s''inscrire ?',
            'Les inscriptions en ligne restent ouvertes tant que la page d''inscription est active : /register — ne tardez pas ! Selon les modalités annoncées, une inscription sur place peut aussi être possible : demandez à l''assistant.',
            'date limite, jusqu a quand, cloture, dernier jour, encore possible', 14, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Jusqu''à quand peut-on s''inscrire%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Peut-on s''inscrire en groupe (entreprise, association) ?',
            'Oui ! Le formulaire d''inscription permet d''inscrire plusieurs personnes en une seule fois : /register — idéal en famille ou entre collègues. Pour un grand groupe, une entreprise ou une association, écrivez-nous via l''assistant : nous vous faciliterons les choses.',
            'groupe, groupes, equipe, equipes, entreprise, association, collegues, plusieurs', 15, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Peut-on s''inscrire en groupe%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Y a-t-il un dossard ? Comment présenter mon billet ?',
            'Pas de dossard papier : le QR code reçu par e-mail après votre inscription fait office de billet le jour J. Gardez-le sur votre téléphone ou imprimez-le. Vous ne l''avez pas reçu ? Demandez à l''assistant « je n''ai pas reçu mon QR code » : il vous le renvoie automatiquement.',
            'dossard, dossards, billet, billets, qr, qr code, imprimer', 16, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Y a-t-il un dossard%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'La marche est-elle chronométrée ? Y a-t-il un classement ?',
            'L''événement est avant tout solidaire et à allure libre : chacun avance à son rythme, l''essentiel est de participer et de soutenir la cause. Pour toute précision sur le chronométrage, consultez la page /parcours ou écrivez-nous.',
            'chrono, chronometre, chronometree, chronometrage, classement, resultats', 17, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'La marche est-elle chronométrée%')",


    // ── FAQ : dress code, allure, transfert de place, spectateurs, RGPD,
    // secours, bénéfices, objets perdus — garde-fou par question.
    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Faut-il venir habillé en rose ?',
            'Le rose est à l''honneur et fortement encouragé — mais rien d''obligatoire : venez comme vous êtes ! Et selon les modalités d''inscription, un t-shirt de l''événement est prévu.',
            'rose, tenue, habille, habiller, vetement, vetements, dress code, deguisement', 18, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Faut-il venir habillé en rose%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Peut-on courir ou faut-il marcher ?',
            'Allure totalement libre : marche tranquille, marche rapide ou course — chacun avance à son rythme, l''essentiel est de participer ! Le tracé complet est sur /parcours.',
            'courir, course a pied, jogging, footing, allure, rythme', 19, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Peut-on courir ou faut-il marcher%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Je ne peux plus venir : puis-je céder ma place ?',
            'Écrivez-nous via l''assistant (bouton « Nous écrire ») avec l''e-mail utilisé lors de l''inscription et les coordonnées de la personne à qui vous souhaitez céder votre place : nous verrons ensemble ce qui est possible.',
            'ceder, cede, transferer, transfert, revendre, donner ma place, remplacer, peux plus venir', 20, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Je ne peux plus venir%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Peut-on venir accompagner sans participer ?',
            'Bien sûr ! Le village, les animations et l''ambiance sont ouverts à toutes et à tous — seule la participation à la marche nécessite une inscription. Venez encourager les participants !',
            'accompagner, accompagnant, accompagnants, spectateur, spectateurs, encourager, assister', 21, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Peut-on venir accompagner%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Que faites-vous de mes données personnelles ?',
            'Vos données servent uniquement à la gestion de votre inscription et de l''événement — elles ne sont jamais revendues. Tout est détaillé dans notre politique de confidentialité : /politique-confidentialite — pour toute demande (accès, suppression), écrivez-nous.',
            'donnees, rgpd, confidentialite, vie privee', 22, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Que faites-vous de mes données%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'Y a-t-il des secours sur le parcours ?',
            'Un dispositif de sécurité et de premiers secours est prévu le jour de l''événement. En cas de besoin, signalez-vous aux bénévoles ou à l''accueil du village. Pour toute question particulière (condition médicale…), écrivez-nous.',
            'secours, securite, secouriste, secouristes, urgence, malaise, blessure, ambulance', 23, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'Y a-t-il des secours%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'À qui sont reversés les bénéfices ?',
            'L''intégralité des bénéfices de l''événement est reversée à la lutte contre le cancer. Pour en savoir plus sur la cause et nos actions, consultez nos actualités ou écrivez-nous.',
            'benefices, reverses, reverse, recolte, argent', 24, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'À qui sont reversés%')",

    "INSERT INTO `chatbot_faq` (question, answer, keywords, position, active)
     SELECT 'J''ai perdu un objet pendant l''événement, que faire ?',
            'Écrivez-nous via l''assistant en décrivant l''objet (et l''endroit où vous pensez l''avoir laissé) : nous vérifions les objets retrouvés et revenons vers vous.',
            'perdu, objet, objets trouves, egare, oublie', 25, 1
     WHERE NOT EXISTS (SELECT 1 FROM `chatbot_faq` WHERE question LIKE 'J''ai perdu un objet%')",


    // ── Enrichissement des mots-clés des questions FAQ par défaut :
    // appliqué UNIQUEMENT si les mots-clés sont encore ceux d'origine
    // (une personnalisation admin n'est jamais écrasée).
    "UPDATE `chatbot_faq` SET keywords = 'enfant, enfants, age, ado, ados, mineur, mineurs, jeune, jeunes, famille, fils, fille, bebe' WHERE keywords = 'enfant, enfants, age, ado, mineur, famille'",
    "UPDATE `chatbot_faq` SET keywords = 'femme, femmes, homme, hommes, mari, garcon, garcons, monsieur, messieurs, masculin, mixte, reservee' WHERE keywords = 'femme, femmes, homme, hommes, mixte, reservee'",
    "UPDATE `chatbot_faq` SET keywords = 'certificat, medical, licence, sante, medecin, attestation, justificatif' WHERE keywords = 'certificat, medical, licence, sante'",
    "UPDATE `chatbot_faq` SET keywords = 'modifier, modification, changer, changement, corriger, annuler, annulation, desinscrire, desinscription, remboursement, rembourse, trompe, faute' WHERE keywords = 'modifier, changer, annuler, annulation, remboursement, rembourse, trompe'",
    "UPDATE `chatbot_faq` SET keywords = 'pluie, pleut, meteo, intemperies, orage, tempete, neige, vent, canicule, mauvais temps, reporte, reportee, report, annule, annulee' WHERE keywords = 'pluie, meteo, intemperies, orage, reporte, mauvais temps'",
    "UPDATE `chatbot_faq` SET keywords = 'chien, chiens, chat, chats, toutou, animal, animaux, poussette, poussettes, landau, laisse' WHERE keywords = 'chien, chiens, animal, animaux, poussette, laisse'",
    "UPDATE `chatbot_faq` SET keywords = 'pmr, fauteuil, roulant, handicap, handicape, handicapee, mobilite, accessible, accessibilite, bequilles' WHERE keywords = 'pmr, fauteuil, handicap, mobilite, accessible, accessibilite'",
    "UPDATE `chatbot_faq` SET keywords = 'buvette, restauration, manger, boire, boisson, boissons, nourriture, sandwich, cafe, eau, ravitaillement, animation, animations, stand, stands, village, musique, concert' WHERE keywords = 'buvette, restauration, manger, boire, animations, stands, village, musique'",
    "UPDATE `chatbot_faq` SET keywords = 'buvette, restauration, manger, boire, boisson, boissons, nourriture, sandwich, cafe, eau, ravitaillement, animation, animations, stand, stands, village, musique, concert, toilettes, wc, sanitaires, vestiaire, vestiaires, consigne' WHERE keywords = 'buvette, restauration, manger, boire, boisson, boissons, nourriture, sandwich, cafe, eau, ravitaillement, animation, animations, stand, stands, village, musique, concert'",
    "UPDATE `chatbot_faq` SET keywords = 'benevole, benevoles, benevolat, volontaire, volontaires, aider, coup de main' WHERE keywords = 'benevole, benevoles, volontaire, aider, coup de main'",
    "UPDATE `chatbot_faq` SET keywords = 'partenaire, partenaires, partenariat, sponsor, sponsors, sponsoriser, sponsoring, entreprise, societe, mecenat, mecene' WHERE keywords = 'partenaire, partenaires, sponsor, sponsoring, entreprise, mecenat'",
    "UPDATE `chatbot_faq` SET keywords = 'confirmation, spam, indesirable, indesirables, courrier' WHERE keywords = 'confirmation, spam, indesirables'",
    "UPDATE `chatbot_faq` SET keywords = 'inscription en ligne, probleme d inscription, probleme inscription, erreur inscription, erreur d inscription, inscription impossible, inscription bloquee, paiement refuse, paiement impossible' WHERE keywords = 'probleme, erreur, bug, impossible, bloque, fonctionne pas, marche pas'",
    "UPDATE `chatbot_faq` SET keywords = 'inscription en ligne, probleme d inscription, probleme inscription, erreur inscription, erreur d inscription, inscription impossible, inscription bloquee, paiement refuse, paiement impossible' WHERE keywords = 'probleme, souci, erreur, bug, impossible, bloque, plante, echec, fonctionne pas, marche pas, passe pas'",

    // ── Contenu par défaut des pages légales : semé UNIQUEMENT si vide
    // (les éditions faites dans Réglages → Pages légales ne sont jamais écrasées).
    "UPDATE `setting` SET legal_mentions = '<h2>Éditeur du site</h2> <p>Ce site est réalisé, édité et maintenu <strong>à titre bénévole et non professionnel</strong>, au profit de l''événement solidaire « Forbach en Rose ».</p> <p>Conformément à l''article 6, III-2 de la loi n° 2004-575 du 21 juin 2004 pour la confiance dans l''économie numérique (LCEN), l''éditeur non professionnel de ce site a choisi de préserver son anonymat ; l''identité de l''hébergeur, qui assure le stockage du site, figure ci-dessous.</p> <p>Contact : via le <a href=''accueil?chat=contact''>formulaire de contact</a> du site.</p> <h2>L''événement</h2> <p>« Forbach en Rose » est organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach — téléphone : 03 87 84 56 95), en partenariat avec la Ligue contre le cancer. L''intégralité des bénéfices est reversée à la lutte contre le cancer.</p> <h2>Hébergement</h2> <p>Le site est hébergé par <strong>PlanetHoster</strong>, société canadienne — siège : 4416 Louis-B.-Mayer, Laval, Québec, H7P 0G1, Canada — dont les centres de données sont situés en France (Paris), en Suisse et au Canada — téléphone (France) : +33 (0)1 76 60 41 43 — <a href=''https://www.planethoster.com'' target=''_blank'' rel=''noopener''>www.planethoster.com</a>.</p> <h2>Propriété intellectuelle</h2> <p>L''ensemble des contenus du site (textes, visuels, logos, photographies, vidéos) est protégé par le droit de la propriété intellectuelle. Toute reproduction ou réutilisation, totale ou partielle, sans autorisation écrite préalable est interdite. Les photographies des éditions peuvent représenter des participants ; toute personne souhaitant le retrait d''une image la concernant peut en faire la demande via le formulaire de contact.</p> <h2>Responsabilité</h2> <p>Les informations publiées (horaires, parcours, tarifs…) sont données à titre indicatif et peuvent évoluer. Le site peut contenir des liens vers des sites tiers (partenaires, réseaux sociaux, plateforme d''inscription) dont l''éditeur ne maîtrise pas le contenu.</p> <h2>Données personnelles</h2> <p>Le traitement des données personnelles collectées sur ce site est détaillé dans la <a href=''politique-confidentialite''>politique de confidentialité</a>.</p>' WHERE id = 1 AND legal_mentions IS NULL",
    "UPDATE `setting` SET legal_mentions = '<h2>Éditeur du site</h2> <p>Ce site est réalisé, édité et maintenu <strong>à titre bénévole et non professionnel</strong>, au profit de l''événement solidaire « Forbach en Rose ».</p> <p>Conformément à l''article 6, III-2 de la loi n° 2004-575 du 21 juin 2004 pour la confiance dans l''économie numérique (LCEN), l''éditeur non professionnel de ce site a choisi de préserver son anonymat ; l''identité de l''hébergeur, qui assure le stockage du site, figure ci-dessous.</p> <p>Contact : via le <a href=''accueil?chat=contact''>formulaire de contact</a> du site.</p> <h2>L''événement</h2> <p>« Forbach en Rose » est organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach — téléphone : 03 87 84 56 95), en partenariat avec la Ligue contre le cancer. L''intégralité des bénéfices est reversée à la lutte contre le cancer.</p> <h2>Hébergement</h2> <p>Le site est hébergé par <strong>PlanetHoster</strong>, société canadienne — siège : 4416 Louis-B.-Mayer, Laval, Québec, H7P 0G1, Canada — dont les centres de données sont situés en France (Paris), en Suisse et au Canada — téléphone (France) : +33 (0)1 76 60 41 43 — <a href=''https://www.planethoster.com'' target=''_blank'' rel=''noopener''>www.planethoster.com</a>.</p> <h2>Propriété intellectuelle</h2> <p>L''ensemble des contenus du site (textes, visuels, logos, photographies, vidéos) est protégé par le droit de la propriété intellectuelle. Toute reproduction ou réutilisation, totale ou partielle, sans autorisation écrite préalable est interdite. Les photographies des éditions peuvent représenter des participants ; toute personne souhaitant le retrait d''une image la concernant peut en faire la demande via le formulaire de contact.</p> <h2>Responsabilité</h2> <p>Les informations publiées (horaires, parcours, tarifs…) sont données à titre indicatif et peuvent évoluer. Le site peut contenir des liens vers des sites tiers (partenaires, réseaux sociaux, plateforme d''inscription) dont l''éditeur ne maîtrise pas le contenu.</p> <h2>Données personnelles</h2> <p>Le traitement des données personnelles collectées sur ce site est détaillé dans la <a href=''politique-confidentialite''>politique de confidentialité</a>.</p>' WHERE id = 1 AND legal_mentions = '<h2>Éditeur du site</h2> <p>Ce site est réalisé, édité et maintenu <strong>à titre bénévole et non professionnel</strong>, au profit de l''événement solidaire « Forbach en Rose ».</p> <p>Conformément à l''article 6, III-2 de la loi n° 2004-575 du 21 juin 2004 pour la confiance dans l''économie numérique (LCEN), l''éditeur non professionnel de ce site a choisi de préserver son anonymat ; l''identité de l''hébergeur, qui assure le stockage du site, figure ci-dessous.</p> <p>Contact : via le <a href=''accueil?chat=contact''>formulaire de contact</a> du site.</p> <h2>L''événement</h2> <p>« Forbach en Rose » est organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach — téléphone : 03 87 84 56 95), en partenariat avec la Ligue contre le cancer. L''intégralité des bénéfices est reversée à la lutte contre le cancer.</p> <h2>Hébergement</h2> <p>Le site est hébergé par <strong>PlanetHoster</strong> — 4416 Louis-B.-Mayer, Laval, Québec, H7P 0G1, Canada — téléphone (France) : +33 (0)1 76 60 41 43 — <a href=''https://www.planethoster.com'' target=''_blank'' rel=''noopener''>www.planethoster.com</a>.</p> <h2>Propriété intellectuelle</h2> <p>L''ensemble des contenus du site (textes, visuels, logos, photographies, vidéos) est protégé par le droit de la propriété intellectuelle. Toute reproduction ou réutilisation, totale ou partielle, sans autorisation écrite préalable est interdite. Les photographies des éditions peuvent représenter des participants ; toute personne souhaitant le retrait d''une image la concernant peut en faire la demande via le formulaire de contact.</p> <h2>Responsabilité</h2> <p>Les informations publiées (horaires, parcours, tarifs…) sont données à titre indicatif et peuvent évoluer. Le site peut contenir des liens vers des sites tiers (partenaires, réseaux sociaux, plateforme d''inscription) dont l''éditeur ne maîtrise pas le contenu.</p> <h2>Données personnelles</h2> <p>Le traitement des données personnelles collectées sur ce site est détaillé dans la <a href=''politique-confidentialite''>politique de confidentialité</a>.</p>'",
    "UPDATE `setting` SET legal_mentions = '<h2>Éditeur du site</h2> <p>Ce site est réalisé, édité et maintenu <strong>à titre bénévole et non professionnel</strong>, au profit de l''événement solidaire « Forbach en Rose ».</p> <p>Conformément à l''article 6, III-2 de la loi n° 2004-575 du 21 juin 2004 pour la confiance dans l''économie numérique (LCEN), l''éditeur non professionnel de ce site a choisi de préserver son anonymat ; l''identité de l''hébergeur, qui assure le stockage du site, figure ci-dessous.</p> <p>Contact : via le <a href=''accueil?chat=contact''>formulaire de contact</a> du site.</p> <h2>L''événement</h2> <p>« Forbach en Rose » est organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach — téléphone : 03 87 84 56 95), en partenariat avec la Ligue contre le cancer. L''intégralité des bénéfices est reversée à la lutte contre le cancer.</p> <h2>Hébergement</h2> <p>Le site est hébergé par <strong>PlanetHoster</strong> — 4416 Louis-B.-Mayer, Laval, Québec, H7P 0G1, Canada — téléphone (France) : +33 (0)1 76 60 41 43 — <a href=''https://www.planethoster.com'' target=''_blank'' rel=''noopener''>www.planethoster.com</a>.</p> <h2>Propriété intellectuelle</h2> <p>L''ensemble des contenus du site (textes, visuels, logos, photographies, vidéos) est protégé par le droit de la propriété intellectuelle. Toute reproduction ou réutilisation, totale ou partielle, sans autorisation écrite préalable est interdite. Les photographies des éditions peuvent représenter des participants ; toute personne souhaitant le retrait d''une image la concernant peut en faire la demande via le formulaire de contact.</p> <h2>Responsabilité</h2> <p>Les informations publiées (horaires, parcours, tarifs…) sont données à titre indicatif et peuvent évoluer. Le site peut contenir des liens vers des sites tiers (partenaires, réseaux sociaux, plateforme d''inscription) dont l''éditeur ne maîtrise pas le contenu.</p> <h2>Données personnelles</h2> <p>Le traitement des données personnelles collectées sur ce site est détaillé dans la <a href=''politique-confidentialite''>politique de confidentialité</a>.</p>' WHERE id = 1 AND legal_mentions = '<h2>Éditeur du site</h2> <p>Le site <strong>forbachenrose.com</strong> est édité par l''association <strong>US Forbach Athlétisme</strong>, association organisatrice de l''événement solidaire « Forbach en Rose », en partenariat avec la Ligue contre le cancer.</p> <ul> <li>Siège social : Stade du Schlossberg, rue du Parc, 57600 Forbach — France</li> <li>SIREN : 384 589 073 — SIRET (siège) : 384 589 073 00020</li> <li>Téléphone : 03 87 84 56 95</li> <li>Contact : via le <a href=''accueil?chat=contact''>formulaire de contact</a> du site</li> </ul> <p><strong>Directeur·rice de la publication :</strong> [À compléter : nom du président ou de la présidente de l''association].</p> <h2>Hébergement</h2> <p>Le site est hébergé par <strong>LWS (Ligne Web Services)</strong>, SAS au capital de 500 000 €, 10 rue Penthièvre, 75008 Paris — France, RCS Paris 851 993 683 — <a href=''https://www.lws.fr'' target=''_blank'' rel=''noopener''>www.lws.fr</a>.</p> <h2>Propriété intellectuelle</h2> <p>L''ensemble des contenus du site (textes, visuels, logos, photographies, vidéos) est protégé par le droit de la propriété intellectuelle. Toute reproduction ou réutilisation, totale ou partielle, sans autorisation écrite préalable de l''association est interdite. Les photographies des éditions peuvent représenter des participants ; toute personne souhaitant le retrait d''une image la concernant peut en faire la demande via le formulaire de contact.</p> <h2>Responsabilité</h2> <p>Les informations publiées (horaires, parcours, tarifs…) sont données à titre indicatif et peuvent évoluer ; l''association s''efforce d''en assurer l''exactitude. Le site peut contenir des liens vers des sites tiers (partenaires, réseaux sociaux, plateforme d''inscription) dont l''association ne maîtrise pas le contenu.</p> <h2>Données personnelles</h2> <p>Le traitement des données personnelles collectées sur ce site est détaillé dans la <a href=''politique-confidentialite''>politique de confidentialité</a>.</p> <h2>Crédits</h2> <p>« Forbach en Rose » est un événement caritatif : l''intégralité des bénéfices est reversée à la lutte contre le cancer.</p>'",
    "UPDATE `setting` SET legal_privacy = '<p>La protection de vos données personnelles nous tient à cœur. La présente politique explique, en toute transparence, quelles données sont collectées sur le site forbachenrose.com, pourquoi, et quels sont vos droits.</p> <h2>Responsable du traitement</h2> <p>Les données sont traitées pour les besoins de l''organisation de l''événement « Forbach en Rose », organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach). Le site est administré à titre bénévole pour le compte de l''organisation. Contact : via le <a href=''accueil?chat=contact''>formulaire du site</a> ou par courrier à l''adresse ci-dessus.</p> <h2>Données collectées et finalités</h2> <h3>Inscription à l''événement</h3> <p>Nom, prénom, e-mail, téléphone, âge, sexe, taille de t-shirt, ville, entreprise (facultatif) et commentaire libre ; pour les mineurs, l''identité du responsable légal (autorisation parentale). Ces données servent exclusivement à la gestion de votre participation : enregistrement, envoi de la confirmation et du QR code d''accès, remise des t-shirts, organisation le jour J. Base légale : exécution du contrat d''inscription.</p> <h3>Paiement</h3> <p>Le paiement s''effectue via la plateforme <strong>AssoConnect</strong> et son prestataire de paiement sécurisé. <strong>Aucune donnée bancaire n''est collectée ni conservée sur ce site.</strong></p> <h3>Formulaire de contact (assistant)</h3> <p>Nom, e-mail, sujet, message et pièces jointes éventuelles : transmis par e-mail à l''équipe pour vous répondre, non conservés en base de données sur le site. Base légale : intérêt légitime à répondre à vos demandes.</p> <h3>Newsletter</h3> <p>Adresse e-mail uniquement, avec votre consentement explicite (case à cocher). Vous pouvez vous désinscrire à tout moment via le lien présent dans chaque envoi ou la page newsletter. Base légale : consentement.</p> <h3>Commentaires des actualités</h3> <p>Pseudo, contenu du commentaire et adresse IP (utilisée uniquement pour prévenir les abus). Base légale : intérêt légitime.</p> <h3>Assistant virtuel</h3> <p>Les questions que l''assistant ne comprend pas sont journalisées de façon anonyme afin d''améliorer ses réponses — merci de ne pas y saisir de données personnelles. Les vérifications par e-mail (inscription, t-shirt, renvoi du QR code) n''affichent jamais de données personnelles dans la conversation : le mail est renvoyé uniquement à l''adresse de l''inscrit.</p> <h3>Statistiques de visite</h3> <p>Mesure d''audience interne et respectueuse : page consultée, type de navigateur, site de provenance et adresse IP <strong>anonymisée</strong>. Aucun profilage, aucun outil d''analyse tiers (pas de Google Analytics, pas de pixel publicitaire).</p> <h2>Sécurité</h2> <p>Des mesures techniques et organisationnelles appropriées protègent vos données : données personnelles chiffrées, connexion sécurisée (HTTPS) et accès strictement limité aux personnes habilitées.</p> <h2>Destinataires et sous-traitants</h2> <ul> <li><strong>Équipe organisatrice</strong> et, le jour J, bénévoles habilités (remise des t-shirts) ;</li> <li><strong>PlanetHoster</strong> — hébergement du site et acheminement des e-mails, sur des centres de données situés en France, en Suisse ou au Canada ;</li> <li><strong>AssoConnect</strong> et son prestataire de paiement — plateforme d''inscription et de paiement ;</li> <li><strong>Google</strong> — polices de caractères et carte interactive (Google Maps) affichées sur la page d''accueil ;</li> <li><strong>Cloudflare</strong> — vérification anti-robots sur les formulaires, qui reçoit l''adresse IP lors de la vérification ;</li> <li><strong>CDN techniques</strong> (jsDelivr, jQuery) — chargement de fichiers techniques.</li> </ul> <p>Vos données ne sont <strong>jamais vendues ni cédées</strong>. Certains prestataires peuvent traiter des données en dehors de l''Union européenne, dans le cadre des garanties prévues par le RGPD (décision d''adéquation ou clauses contractuelles types).</p> <h2>Cookies et stockage local</h2> <p>Le site utilise uniquement un <strong>cookie de session technique</strong>, nécessaire au fonctionnement et à la sécurité (protection des formulaires) — exempté de consentement. Aucun cookie publicitaire ni traceur tiers. Le stockage local de votre navigateur peut mémoriser des préférences fonctionnelles : thème clair/sombre, préférences de l''assistant (position, message d''accueil) et votre confirmation d''inscription sur votre propre appareil.</p> <h2>Durées de conservation</h2> <ul> <li>Données d''inscription : le temps de l''organisation de l''édition concernée, puis au maximum [3 ans] après votre dernière participation ;</li> <li>Newsletter : jusqu''à votre désinscription ;</li> <li>Journaux techniques et de sécurité : durée limitée nécessaire à la protection du site ;</li> <li>Statistiques de visite : données anonymisées dès la collecte.</li> </ul> <h2>Vos droits</h2> <p>Conformément au RGPD, vous disposez des droits d''accès, de rectification, d''effacement, de limitation, d''opposition et de portabilité sur vos données. Pour les exercer : le <a href=''accueil?chat=contact''>formulaire de contact</a> du site ou un courrier à l''adresse indiquée plus haut. Vous pouvez également introduire une réclamation auprès de la CNIL (<a href=''https://www.cnil.fr'' target=''_blank'' rel=''noopener''>www.cnil.fr</a>).</p> <h2>Mineurs</h2> <p>La participation des mineurs nécessite l''autorisation d''un responsable légal, recueillie lors de l''inscription.</p> <p><em>Dernière mise à jour : juillet 2026.</em></p>' WHERE id = 1 AND legal_privacy IS NULL",
    "UPDATE `setting` SET legal_privacy = '<p>La protection de vos données personnelles nous tient à cœur. La présente politique explique, en toute transparence, quelles données sont collectées sur le site forbachenrose.com, pourquoi, et quels sont vos droits.</p> <h2>Responsable du traitement</h2> <p>Les données sont traitées pour les besoins de l''organisation de l''événement « Forbach en Rose », organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach). Le site est administré à titre bénévole pour le compte de l''organisation. Contact : via le <a href=''accueil?chat=contact''>formulaire du site</a> ou par courrier à l''adresse ci-dessus.</p> <h2>Données collectées et finalités</h2> <h3>Inscription à l''événement</h3> <p>Nom, prénom, e-mail, téléphone, âge, sexe, taille de t-shirt, ville, entreprise (facultatif) et commentaire libre ; pour les mineurs, l''identité du responsable légal (autorisation parentale). Ces données servent exclusivement à la gestion de votre participation : enregistrement, envoi de la confirmation et du QR code d''accès, remise des t-shirts, organisation le jour J. Base légale : exécution du contrat d''inscription.</p> <h3>Paiement</h3> <p>Le paiement s''effectue via la plateforme <strong>AssoConnect</strong> et son prestataire de paiement sécurisé. <strong>Aucune donnée bancaire n''est collectée ni conservée sur ce site.</strong></p> <h3>Formulaire de contact (assistant)</h3> <p>Nom, e-mail, sujet, message et pièces jointes éventuelles : transmis par e-mail à l''équipe pour vous répondre, non conservés en base de données sur le site. Base légale : intérêt légitime à répondre à vos demandes.</p> <h3>Newsletter</h3> <p>Adresse e-mail uniquement, avec votre consentement explicite (case à cocher). Vous pouvez vous désinscrire à tout moment via le lien présent dans chaque envoi ou la page newsletter. Base légale : consentement.</p> <h3>Commentaires des actualités</h3> <p>Pseudo, contenu du commentaire et adresse IP (utilisée uniquement pour prévenir les abus). Base légale : intérêt légitime.</p> <h3>Assistant virtuel</h3> <p>Les questions que l''assistant ne comprend pas sont journalisées de façon anonyme afin d''améliorer ses réponses — merci de ne pas y saisir de données personnelles. Les vérifications par e-mail (inscription, t-shirt, renvoi du QR code) n''affichent jamais de données personnelles dans la conversation : le mail est renvoyé uniquement à l''adresse de l''inscrit.</p> <h3>Statistiques de visite</h3> <p>Mesure d''audience interne et respectueuse : page consultée, type de navigateur, site de provenance et adresse IP <strong>anonymisée</strong>. Aucun profilage, aucun outil d''analyse tiers (pas de Google Analytics, pas de pixel publicitaire).</p> <h2>Sécurité</h2> <p>Des mesures techniques et organisationnelles appropriées protègent vos données : données personnelles chiffrées, connexion sécurisée (HTTPS) et accès strictement limité aux personnes habilitées.</p> <h2>Destinataires et sous-traitants</h2> <ul> <li><strong>Équipe organisatrice</strong> et, le jour J, bénévoles habilités (remise des t-shirts) ;</li> <li><strong>PlanetHoster</strong> — hébergement du site et acheminement des e-mails, sur des centres de données situés en France, en Suisse ou au Canada ;</li> <li><strong>AssoConnect</strong> et son prestataire de paiement — plateforme d''inscription et de paiement ;</li> <li><strong>Google</strong> — polices de caractères et carte interactive (Google Maps) affichées sur la page d''accueil ;</li> <li><strong>Cloudflare</strong> — vérification anti-robots sur les formulaires, qui reçoit l''adresse IP lors de la vérification ;</li> <li><strong>CDN techniques</strong> (jsDelivr, jQuery) — chargement de fichiers techniques.</li> </ul> <p>Vos données ne sont <strong>jamais vendues ni cédées</strong>. Certains prestataires peuvent traiter des données en dehors de l''Union européenne, dans le cadre des garanties prévues par le RGPD (décision d''adéquation ou clauses contractuelles types).</p> <h2>Cookies et stockage local</h2> <p>Le site utilise uniquement un <strong>cookie de session technique</strong>, nécessaire au fonctionnement et à la sécurité (protection des formulaires) — exempté de consentement. Aucun cookie publicitaire ni traceur tiers. Le stockage local de votre navigateur peut mémoriser des préférences fonctionnelles : thème clair/sombre, préférences de l''assistant (position, message d''accueil) et votre confirmation d''inscription sur votre propre appareil.</p> <h2>Durées de conservation</h2> <ul> <li>Données d''inscription : le temps de l''organisation de l''édition concernée, puis au maximum [3 ans] après votre dernière participation ;</li> <li>Newsletter : jusqu''à votre désinscription ;</li> <li>Journaux techniques et de sécurité : durée limitée nécessaire à la protection du site ;</li> <li>Statistiques de visite : données anonymisées dès la collecte.</li> </ul> <h2>Vos droits</h2> <p>Conformément au RGPD, vous disposez des droits d''accès, de rectification, d''effacement, de limitation, d''opposition et de portabilité sur vos données. Pour les exercer : le <a href=''accueil?chat=contact''>formulaire de contact</a> du site ou un courrier à l''adresse indiquée plus haut. Vous pouvez également introduire une réclamation auprès de la CNIL (<a href=''https://www.cnil.fr'' target=''_blank'' rel=''noopener''>www.cnil.fr</a>).</p> <h2>Mineurs</h2> <p>La participation des mineurs nécessite l''autorisation d''un responsable légal, recueillie lors de l''inscription.</p> <p><em>Dernière mise à jour : juillet 2026.</em></p>' WHERE id = 1 AND legal_privacy = '<p>La protection de vos données personnelles nous tient à cœur. La présente politique explique, en toute transparence, quelles données sont collectées sur le site forbachenrose.com, pourquoi, et quels sont vos droits.</p> <h2>Responsable du traitement</h2> <p>Les données sont traitées pour les besoins de l''organisation de l''événement « Forbach en Rose », organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach). Le site est administré à titre bénévole pour le compte de l''organisation. Contact : via le <a href=''accueil?chat=contact''>formulaire du site</a> ou par courrier à l''adresse ci-dessus.</p> <h2>Données collectées et finalités</h2> <h3>Inscription à l''événement</h3> <p>Nom, prénom, e-mail, téléphone, âge, sexe, taille de t-shirt, ville, entreprise (facultatif) et commentaire libre ; pour les mineurs, l''identité du responsable légal (autorisation parentale). Ces données servent exclusivement à la gestion de votre participation : enregistrement, envoi de la confirmation et du QR code d''accès, remise des t-shirts, organisation le jour J. Base légale : exécution du contrat d''inscription.</p> <h3>Paiement</h3> <p>Le paiement s''effectue via la plateforme <strong>AssoConnect</strong> et son prestataire de paiement sécurisé. <strong>Aucune donnée bancaire n''est collectée ni conservée sur ce site.</strong></p> <h3>Formulaire de contact (assistant)</h3> <p>Nom, e-mail, sujet, message et pièces jointes éventuelles : transmis par e-mail à l''équipe pour vous répondre, non conservés en base de données sur le site. Base légale : intérêt légitime à répondre à vos demandes.</p> <h3>Newsletter</h3> <p>Adresse e-mail uniquement, avec votre consentement explicite (case à cocher). Vous pouvez vous désinscrire à tout moment via le lien présent dans chaque envoi ou la page newsletter. Base légale : consentement.</p> <h3>Commentaires des actualités</h3> <p>Pseudo, contenu du commentaire et adresse IP (utilisée uniquement pour prévenir les abus). Base légale : intérêt légitime.</p> <h3>Assistant virtuel</h3> <p>Les questions que l''assistant ne comprend pas sont journalisées de façon anonyme afin d''améliorer ses réponses — merci de ne pas y saisir de données personnelles. Les vérifications par e-mail (inscription, t-shirt, renvoi du QR code) n''affichent jamais de données personnelles dans la conversation : le mail est renvoyé uniquement à l''adresse de l''inscrit.</p> <h3>Statistiques de visite</h3> <p>Mesure d''audience interne et respectueuse : page consultée, type de navigateur, site de provenance et adresse IP <strong>anonymisée</strong>. Aucun profilage, aucun outil d''analyse tiers (pas de Google Analytics, pas de pixel publicitaire).</p> <h2>Sécurité</h2> <p>Des mesures techniques et organisationnelles appropriées protègent vos données : données personnelles chiffrées, connexion sécurisée (HTTPS) et accès strictement limité aux personnes habilitées.</p> <h2>Destinataires et sous-traitants</h2> <ul> <li><strong>Équipe organisatrice</strong> et, le jour J, bénévoles habilités (remise des t-shirts) ;</li> <li><strong>PlanetHoster</strong> — hébergement du site et acheminement des e-mails (serveur de messagerie de l''hébergeur) ;</li> <li><strong>AssoConnect</strong> et son prestataire de paiement — plateforme d''inscription et de paiement ;</li> <li><strong>Google</strong> — polices de caractères et carte interactive (Google Maps) affichées sur la page d''accueil ;</li> <li><strong>Cloudflare</strong> — vérification anti-robots sur les formulaires, qui reçoit l''adresse IP lors de la vérification ;</li> <li><strong>CDN techniques</strong> (jsDelivr, jQuery) — chargement de fichiers techniques.</li> </ul> <p>Vos données ne sont <strong>jamais vendues ni cédées</strong>. Certains prestataires peuvent traiter des données en dehors de l''Union européenne, dans le cadre des garanties prévues par le RGPD (décision d''adéquation ou clauses contractuelles types).</p> <h2>Cookies et stockage local</h2> <p>Le site utilise uniquement un <strong>cookie de session technique</strong>, nécessaire au fonctionnement et à la sécurité (protection des formulaires) — exempté de consentement. Aucun cookie publicitaire ni traceur tiers. Le stockage local de votre navigateur peut mémoriser des préférences fonctionnelles : thème clair/sombre, préférences de l''assistant (position, message d''accueil) et votre confirmation d''inscription sur votre propre appareil.</p> <h2>Durées de conservation</h2> <ul> <li>Données d''inscription : le temps de l''organisation de l''édition concernée, puis au maximum [3 ans] après votre dernière participation ;</li> <li>Newsletter : jusqu''à votre désinscription ;</li> <li>Journaux techniques et de sécurité : durée limitée nécessaire à la protection du site ;</li> <li>Statistiques de visite : données anonymisées dès la collecte.</li> </ul> <h2>Vos droits</h2> <p>Conformément au RGPD, vous disposez des droits d''accès, de rectification, d''effacement, de limitation, d''opposition et de portabilité sur vos données. Pour les exercer : le <a href=''accueil?chat=contact''>formulaire de contact</a> du site ou un courrier à l''adresse indiquée plus haut. Vous pouvez également introduire une réclamation auprès de la CNIL (<a href=''https://www.cnil.fr'' target=''_blank'' rel=''noopener''>www.cnil.fr</a>).</p> <h2>Mineurs</h2> <p>La participation des mineurs nécessite l''autorisation d''un responsable légal, recueillie lors de l''inscription.</p> <p><em>Dernière mise à jour : juillet 2026.</em></p>'",
    "UPDATE `setting` SET legal_privacy = '<p>La protection de vos données personnelles nous tient à cœur. La présente politique explique, en toute transparence, quelles données sont collectées sur le site forbachenrose.com, pourquoi, et quels sont vos droits.</p> <h2>Responsable du traitement</h2> <p>Les données sont traitées pour les besoins de l''organisation de l''événement « Forbach en Rose », organisé par <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach). Le site est administré à titre bénévole pour le compte de l''organisation. Contact : via le <a href=''accueil?chat=contact''>formulaire du site</a> ou par courrier à l''adresse ci-dessus.</p> <h2>Données collectées et finalités</h2> <h3>Inscription à l''événement</h3> <p>Nom, prénom, e-mail, téléphone, âge, sexe, taille de t-shirt, ville, entreprise (facultatif) et commentaire libre ; pour les mineurs, l''identité du responsable légal (autorisation parentale). Ces données servent exclusivement à la gestion de votre participation : enregistrement, envoi de la confirmation et du QR code d''accès, remise des t-shirts, organisation le jour J. Base légale : exécution du contrat d''inscription.</p> <h3>Paiement</h3> <p>Le paiement s''effectue via la plateforme <strong>AssoConnect</strong> et son prestataire de paiement sécurisé. <strong>Aucune donnée bancaire n''est collectée ni conservée sur ce site.</strong></p> <h3>Formulaire de contact (assistant)</h3> <p>Nom, e-mail, sujet, message et pièces jointes éventuelles : transmis par e-mail à l''équipe pour vous répondre, non conservés en base de données sur le site. Base légale : intérêt légitime à répondre à vos demandes.</p> <h3>Newsletter</h3> <p>Adresse e-mail uniquement, avec votre consentement explicite (case à cocher). Vous pouvez vous désinscrire à tout moment via le lien présent dans chaque envoi ou la page newsletter. Base légale : consentement.</p> <h3>Commentaires des actualités</h3> <p>Pseudo, contenu du commentaire et adresse IP (utilisée uniquement pour prévenir les abus). Base légale : intérêt légitime.</p> <h3>Assistant virtuel</h3> <p>Les questions que l''assistant ne comprend pas sont journalisées de façon anonyme afin d''améliorer ses réponses — merci de ne pas y saisir de données personnelles. Les vérifications par e-mail (inscription, t-shirt, renvoi du QR code) n''affichent jamais de données personnelles dans la conversation : le mail est renvoyé uniquement à l''adresse de l''inscrit.</p> <h3>Statistiques de visite</h3> <p>Mesure d''audience interne et respectueuse : page consultée, type de navigateur, site de provenance et adresse IP <strong>anonymisée</strong>. Aucun profilage, aucun outil d''analyse tiers (pas de Google Analytics, pas de pixel publicitaire).</p> <h2>Sécurité</h2> <p>Des mesures techniques et organisationnelles appropriées protègent vos données : données personnelles chiffrées, connexion sécurisée (HTTPS) et accès strictement limité aux personnes habilitées.</p> <h2>Destinataires et sous-traitants</h2> <ul> <li><strong>Équipe organisatrice</strong> et, le jour J, bénévoles habilités (remise des t-shirts) ;</li> <li><strong>PlanetHoster</strong> — hébergement du site et acheminement des e-mails (serveur de messagerie de l''hébergeur) ;</li> <li><strong>AssoConnect</strong> et son prestataire de paiement — plateforme d''inscription et de paiement ;</li> <li><strong>Google</strong> — polices de caractères et carte interactive (Google Maps) affichées sur la page d''accueil ;</li> <li><strong>Cloudflare</strong> — vérification anti-robots sur les formulaires, qui reçoit l''adresse IP lors de la vérification ;</li> <li><strong>CDN techniques</strong> (jsDelivr, jQuery) — chargement de fichiers techniques.</li> </ul> <p>Vos données ne sont <strong>jamais vendues ni cédées</strong>. Certains prestataires peuvent traiter des données en dehors de l''Union européenne, dans le cadre des garanties prévues par le RGPD (décision d''adéquation ou clauses contractuelles types).</p> <h2>Cookies et stockage local</h2> <p>Le site utilise uniquement un <strong>cookie de session technique</strong>, nécessaire au fonctionnement et à la sécurité (protection des formulaires) — exempté de consentement. Aucun cookie publicitaire ni traceur tiers. Le stockage local de votre navigateur peut mémoriser des préférences fonctionnelles : thème clair/sombre, préférences de l''assistant (position, message d''accueil) et votre confirmation d''inscription sur votre propre appareil.</p> <h2>Durées de conservation</h2> <ul> <li>Données d''inscription : le temps de l''organisation de l''édition concernée, puis au maximum [3 ans] après votre dernière participation ;</li> <li>Newsletter : jusqu''à votre désinscription ;</li> <li>Journaux techniques et de sécurité : durée limitée nécessaire à la protection du site ;</li> <li>Statistiques de visite : données anonymisées dès la collecte.</li> </ul> <h2>Vos droits</h2> <p>Conformément au RGPD, vous disposez des droits d''accès, de rectification, d''effacement, de limitation, d''opposition et de portabilité sur vos données. Pour les exercer : le <a href=''accueil?chat=contact''>formulaire de contact</a> du site ou un courrier à l''adresse indiquée plus haut. Vous pouvez également introduire une réclamation auprès de la CNIL (<a href=''https://www.cnil.fr'' target=''_blank'' rel=''noopener''>www.cnil.fr</a>).</p> <h2>Mineurs</h2> <p>La participation des mineurs nécessite l''autorisation d''un responsable légal, recueillie lors de l''inscription.</p> <p><em>Dernière mise à jour : juillet 2026.</em></p>' WHERE id = 1 AND legal_privacy = '<p>La protection de vos données personnelles nous tient à cœur. La présente politique explique, en toute transparence, quelles données sont collectées sur le site forbachenrose.com, pourquoi, et quels sont vos droits.</p> <h2>Responsable du traitement</h2> <p>L''association <strong>US Forbach Athlétisme</strong> (Stade du Schlossberg, rue du Parc, 57600 Forbach — SIREN 384 589 073), organisatrice de « Forbach en Rose ». Contact : via le <a href=''accueil?chat=contact''>formulaire du site</a> ou par courrier au siège.</p> <h2>Données collectées et finalités</h2> <h3>Inscription à l''événement</h3> <p>Nom, prénom, e-mail, téléphone, âge, sexe, taille de t-shirt, ville, entreprise (facultatif) et commentaire libre ; pour les mineurs, l''identité du responsable légal (autorisation parentale). Ces données servent exclusivement à la gestion de votre participation : enregistrement, envoi de la confirmation et du QR code d''accès, remise des t-shirts, organisation le jour J. Base légale : exécution du contrat d''inscription.</p> <h3>Paiement</h3> <p>Le paiement s''effectue via la plateforme <strong>AssoConnect</strong> et son prestataire de paiement sécurisé (Adyen). <strong>Aucune donnée bancaire n''est collectée ni conservée sur ce site.</strong></p> <h3>Formulaire de contact (assistant)</h3> <p>Nom, e-mail, sujet, message et pièces jointes éventuelles : transmis par e-mail à l''équipe organisatrice pour vous répondre, non conservés en base de données sur le site. Base légale : intérêt légitime à répondre à vos demandes.</p> <h3>Newsletter</h3> <p>Adresse e-mail uniquement, avec votre consentement explicite (case à cocher). Vous pouvez vous désinscrire à tout moment via le lien présent dans chaque envoi ou la page newsletter. Base légale : consentement.</p> <h3>Commentaires des actualités</h3> <p>Pseudo, contenu du commentaire et adresse IP (utilisée uniquement pour prévenir les abus). Base légale : intérêt légitime.</p> <h3>Assistant virtuel</h3> <p>Les questions que l''assistant ne comprend pas sont journalisées de façon anonyme afin d''améliorer ses réponses — merci de ne pas y saisir de données personnelles. Les vérifications par e-mail (inscription, t-shirt, renvoi du QR code) n''affichent jamais de données personnelles dans la conversation : le mail est renvoyé uniquement à l''adresse de l''inscrit.</p> <h3>Statistiques de visite</h3> <p>Mesure d''audience interne et respectueuse : page consultée, type de navigateur, site de provenance et adresse IP <strong>anonymisée</strong> (dernier octet supprimé). Aucun profilage, aucun outil d''analyse tiers (pas de Google Analytics, pas de pixel publicitaire).</p> <h2>Sécurité</h2> <p>Les données personnelles d''inscription (nom, prénom, e-mail, téléphone, âge, ville, entreprise) sont <strong>chiffrées en base de données (AES-256-GCM)</strong>. Le site est servi en HTTPS, les secrets de configuration sont chiffrés, l''accès à l''administration est restreint (authentification forte) et les formulaires sont protégés contre les robots.</p> <h2>Destinataires et sous-traitants</h2> <ul> <li><strong>Équipe organisatrice</strong> et, le jour J, bénévoles habilités (remise des t-shirts) ;</li> <li><strong>LWS</strong> — hébergement du site (France) ;</li> <li><strong>AssoConnect / Adyen</strong> — plateforme d''inscription et de paiement ;</li> <li><strong>Google</strong> — acheminement des e-mails du site ; polices de caractères et carte interactive (Google Maps) sur la page d''accueil ;</li> <li><strong>Cloudflare</strong> — vérification anti-robots (Turnstile) sur les formulaires, qui reçoit l''adresse IP lors de la vérification ;</li> <li><strong>CDN techniques</strong> (jsDelivr, jQuery) — chargement de fichiers techniques.</li> </ul> <p>Vos données ne sont <strong>jamais vendues ni cédées</strong>. Certains prestataires (Google, Cloudflare) peuvent traiter des données en dehors de l''Union européenne, dans le cadre des garanties prévues par le RGPD (clauses contractuelles types).</p> <h2>Cookies et stockage local</h2> <p>Le site utilise uniquement un <strong>cookie de session technique</strong>, nécessaire au fonctionnement et à la sécurité (protection des formulaires) — exempté de consentement. Aucun cookie publicitaire ni traceur tiers. Le stockage local de votre navigateur peut mémoriser des préférences fonctionnelles : thème clair/sombre, préférences de l''assistant (position, message d''accueil) et votre confirmation d''inscription sur votre propre appareil.</p> <h2>Durées de conservation</h2> <ul> <li>Données d''inscription : le temps de l''organisation de l''édition concernée, puis au maximum [3 ans] après votre dernière participation ;</li> <li>Newsletter : jusqu''à votre désinscription ;</li> <li>Journaux techniques et de sécurité : durée limitée nécessaire à la protection du site ;</li> <li>Statistiques de visite : données anonymisées dès la collecte.</li> </ul> <h2>Vos droits</h2> <p>Conformément au RGPD, vous disposez des droits d''accès, de rectification, d''effacement, de limitation, d''opposition et de portabilité sur vos données. Pour les exercer : le <a href=''accueil?chat=contact''>formulaire de contact</a> du site ou un courrier au siège de l''association. Vous pouvez également introduire une réclamation auprès de la CNIL (<a href=''https://www.cnil.fr'' target=''_blank'' rel=''noopener''>www.cnil.fr</a>).</p> <h2>Mineurs</h2> <p>La participation des mineurs nécessite l''autorisation d''un responsable légal, recueillie lors de l''inscription.</p> <p><em>Dernière mise à jour : juillet 2026.</em></p>'",
];

$results = [];

/* ─────────────────────────────────────────────────────────────────────────
 * v2.0.0 — Configuration chiffrée : .env → config.enc + master.key
 * -------------------------------------------------------------------------
 * src/core/config.php (chargé en tête de ce fichier) a déjà migré automatiquement
 * l'ancien .env vers config.enc au premier chargement. Ici on vérifie que la
 * config chiffrée est bien fonctionnelle, puis on supprime les fichiers
 * devenus inutiles : config/.env (secrets en clair !) et config/.env.example.
 * ───────────────────────────────────────────────────────────────────────── */
$cfgMigrateSql = 'MIGRATE config/.env → config.enc + master.key (v2.0.0)';
$cfgOk = false;
try {
    if (FerSecureConfig::exists()) {
        $cfgData = FerSecureConfig::load();
        if (FerSecureConfig::isComplete($cfgData)) {
            $cfgOk = true;
            // « Appliquée » uniquement lors de la vraie migration (un .env est
            // encore présent) ; ensuite l'étape est simplement ignorée.
            $results[] = file_exists(__DIR__ . '/config/.env')
                ? ['status' => 'success', 'sql' => $cfgMigrateSql, 'msg' => 'Configuration chiffrée vérifiée — le .env va être supprimé']
                : ['status' => 'skip',    'sql' => $cfgMigrateSql, 'msg' => 'Déjà migré'];
        } else {
            $results[] = ['status' => 'error', 'sql' => $cfgMigrateSql, 'msg' => 'config.enc incomplet (clés manquantes)'];
        }
    } else {
        $results[] = ['status' => 'error', 'sql' => $cfgMigrateSql, 'msg' => 'config.enc absent — migration non effectuée'];
    }
} catch (\Throwable $e) {
    $results[] = ['status' => 'error', 'sql' => $cfgMigrateSql, 'msg' => $e->getMessage()];
}

// Suppression de config/.env — UNIQUEMENT si config.enc est vérifié fonctionnel
$envDeleteSql = 'DELETE config/.env (secrets en clair, remplacé par config.enc)';
$oldEnv = __DIR__ . '/config/.env';
if (!file_exists($oldEnv)) {
    $results[] = ['status' => 'skip', 'sql' => $envDeleteSql, 'msg' => 'Déjà supprimé'];
} elseif (!$cfgOk) {
    $results[] = ['status' => 'skip', 'sql' => $envDeleteSql, 'msg' => 'Conservé : config.enc non vérifié'];
} elseif (@unlink($oldEnv)) {
    $results[] = ['status' => 'success', 'sql' => $envDeleteSql, 'msg' => 'Fichier supprimé'];
} else {
    $results[] = ['status' => 'error', 'sql' => $envDeleteSql, 'msg' => 'Suppression impossible (permissions) — supprimez-le via FTP'];
}

// Suppression de config/.env.example (obsolète : install.php génère config.enc)
$exampleDeleteSql = 'DELETE config/.env.example (obsolète en v2.0.0)';
$oldExample = __DIR__ . '/config/.env.example';
if (!file_exists($oldExample)) {
    $results[] = ['status' => 'skip', 'sql' => $exampleDeleteSql, 'msg' => 'Déjà supprimé'];
} elseif (@unlink($oldExample)) {
    $results[] = ['status' => 'success', 'sql' => $exampleDeleteSql, 'msg' => 'Fichier supprimé'];
} else {
    $results[] = ['status' => 'error', 'sql' => $exampleDeleteSql, 'msg' => 'Suppression impossible (permissions)'];
}

/* ─────────────────────────────────────────────────────────────────────────
 * v2.0.0 — Nouvelle arborescence : les librairies PHP de config/ ont été
 * déplacées vers src/ (core / security / mail / content) et config/api.php
 * est devenu admin-api.php à la racine. Sur une installation existante mise
 * à jour par écrasement des fichiers, les anciens exemplaires restent dans
 * config/ : on les supprime UNIQUEMENT si leur remplaçant existe bien.
 * config/ ne contient plus que : config.enc, master.key, token.json, logs/.
 * ───────────────────────────────────────────────────────────────────────── */
$movedLibs = [
    'config/config.php'             => 'src/core/config.php',
    'config/secure.php'             => 'src/core/secure.php',
    'config/debug.php'              => 'src/core/debug.php',
    'config/csrf.php'               => 'src/security/csrf.php',
    'config/captcha.php'            => 'src/security/captcha.php',
    'config/totp.php'               => 'src/security/totp.php',
    'config/webauthn.php'           => 'src/security/webauthn.php',
    'config/googleMail.php'         => 'src/mail/googleMail.php',
    'config/mail_template.php'      => 'src/mail/mail_template.php',
    'config/newsletter.php'         => 'src/mail/newsletter.php',
    'config/theme.php'              => 'src/content/theme.php',
    'config/tracker.php'            => 'src/content/tracker.php',
    'config/content-log.php'        => 'src/content/content-log.php',
    'config/accueil_layout.php'     => 'src/content/accueil_layout.php',
    'config/accueil_sections.php'   => 'src/content/accueil_sections.php',
    'config/form_fields.php'        => 'src/content/form_fields.php',
    'config/registrations_core.php' => 'src/content/registrations_core.php',
    'config/assoconnect_client.php' => 'src/content/assoconnect_client.php',
    'config/sync_assoconnect.php'   => 'src/content/sync_assoconnect.php',
    'config/api.php'                => 'admin-api.php',
    // Fragments d'interface (includes purs, jamais des URLs) : inc/ → src/partials/
    'inc/navbar-admin.php'          => 'src/partials/navbar-admin.php',
    'inc/navbar-data.php'           => 'src/partials/navbar-data.php',
    'inc/navbar-modern.php'         => 'src/partials/navbar-modern.php',
    'inc/footer-modern.php'         => 'src/partials/footer-modern.php',
    'inc/admin-footer.php'          => 'src/partials/admin-footer.php',
    'inc/toast.php'                 => 'src/partials/toast.php',
    'inc/profile-modal.php'         => 'src/partials/profile-modal.php',
    'inc/_stats-more-modal.php'     => 'src/partials/_stats-more-modal.php',
];
$movedSql = 'DELETE anciennes librairies config/*.php + fragments inc/*.php (déplacés vers src/, v2.0.0)';
$movedDeleted = 0; $movedKept = 0; $movedErrors = [];
foreach ($movedLibs as $old => $new) {
    $oldPath = __DIR__ . '/' . $old;
    if (!file_exists($oldPath)) continue;
    if (!file_exists(__DIR__ . '/' . $new)) {
        $movedKept++;
        $movedErrors[] = "$old conservé ($new introuvable)";
        continue;
    }
    if (@unlink($oldPath)) { $movedDeleted++; }
    else { $movedKept++; $movedErrors[] = "$old : suppression impossible (permissions)"; }
}
if ($movedDeleted === 0 && $movedKept === 0) {
    $results[] = ['status' => 'skip', 'sql' => $movedSql, 'msg' => 'Déjà nettoyé'];
} elseif ($movedKept === 0) {
    $results[] = ['status' => 'success', 'sql' => $movedSql, 'msg' => "$movedDeleted fichier(s) supprimé(s)"];
} else {
    $results[] = ['status' => 'error', 'sql' => $movedSql, 'msg' => "$movedDeleted supprimé(s), $movedKept conservé(s) : " . implode(' ; ', $movedErrors)];
}

// Divers fichiers/dossiers obsolètes en v2.0.0
$obsoleteSql = 'DELETE fichiers obsolètes divers (fonts/Version-1.0.3.md, config/sessions/)';
$obsoleteDone = [];
$oldNotes = __DIR__ . '/fonts/Version-1.0.3.md';
if (is_file($oldNotes) && @unlink($oldNotes)) { $obsoleteDone[] = 'fonts/Version-1.0.3.md'; }
$oldSessions = __DIR__ . '/config/sessions';
if (is_dir($oldSessions)) {
    foreach (glob($oldSessions . '/{,.}*', GLOB_BRACE) ?: [] as $sf) {
        if (is_file($sf)) { @unlink($sf); }
    }
    if (@rmdir($oldSessions)) { $obsoleteDone[] = 'config/sessions/'; }
}
$results[] = empty($obsoleteDone)
    ? ['status' => 'skip', 'sql' => $obsoleteSql, 'msg' => 'Déjà nettoyé']
    : ['status' => 'success', 'sql' => $obsoleteSql, 'msg' => 'Supprimé : ' . implode(', ', $obsoleteDone)];

/* ─────────────────────────────────────────────────────────────────────────
 * v2.0.0 — Les logs quittent config/ pour storage/logs/ (config/ = config
 * pure uniquement : config.enc, master.key, token.json). On déplace les
 * fichiers existants puis on supprime l'ancien dossier config/logs.
 * ───────────────────────────────────────────────────────────────────────── */
$logsMoveSql = 'MOVE config/logs/* → storage/logs/ (v2.0.0)';
$oldLogsDir = __DIR__ . '/config/logs';
$newLogsDir = __DIR__ . '/storage/logs';
if (!is_dir($oldLogsDir)) {
    $results[] = ['status' => 'skip', 'sql' => $logsMoveSql, 'msg' => 'Déjà déplacé'];
} else {
    if (!is_dir($newLogsDir)) { @mkdir($newLogsDir, 0755, true); }
    $logsMoved = 0; $logsFailed = [];
    foreach (glob($oldLogsDir . '/*') ?: [] as $f) {
        $dest = $newLogsDir . '/' . basename($f);
        // Si un fichier du même nom existe déjà côté storage (log recréé entre
        // la mise à jour des fichiers et l'exécution d'update.php), on fusionne.
        if (is_file($dest) && is_file($f)) {
            if (@file_put_contents($dest, (string) @file_get_contents($f), FILE_APPEND) !== false && @unlink($f)) {
                $logsMoved++;
            } else {
                $logsFailed[] = basename($f);
            }
        } elseif (@rename($f, $dest)) {
            $logsMoved++;
        } else {
            $logsFailed[] = basename($f);
        }
    }
    @unlink($oldLogsDir . '/.gitkeep');
    if (empty($logsFailed) && @rmdir($oldLogsDir)) {
        $results[] = ['status' => 'success', 'sql' => $logsMoveSql, 'msg' => "$logsMoved fichier(s) déplacé(s), config/logs supprimé"];
    } elseif (empty($logsFailed)) {
        $results[] = ['status' => 'success', 'sql' => $logsMoveSql, 'msg' => "$logsMoved fichier(s) déplacé(s) — supprimez config/logs manuellement"];
    } else {
        $results[] = ['status' => 'error', 'sql' => $logsMoveSql, 'msg' => "$logsMoved déplacé(s), échec : " . implode(', ', $logsFailed)];
    }
}

// Renommer le fichier de logs Google Mails .txt -> .log
$oldLog = __DIR__ . '/storage/logs/logs_google_mails.txt';
$newLog = __DIR__ . '/storage/logs/logs_google_mails.log';
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
        $affected = $pdo->exec($sql);
        // 0 ligne affectée = rien à faire :
        //   - INSERT IGNORE → la ligne existe déjà ;
        //   - UPDATE        → la valeur cible est déjà en place (ex. is_locked déjà à 0).
        // On affiche « Déjà appliqué » plutôt que « OK » pour éviter de croire qu'une
        // action a lieu à chaque passage.
        if ($affected === 0 && preg_match('/^\s*INSERT\b/i', $sql)) {
            $results[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'Déjà présent'];
        } elseif ($affected === 0 && preg_match('/^\s*UPDATE\s/i', $sql)) {
            $results[] = ['status' => 'skip', 'sql' => $sql, 'msg' => 'Déjà appliqué'];
        } else {
            $results[] = ['status' => 'success', 'sql' => $sql, 'msg' => 'OK'];
        }
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
// jr-theme : le dossier n'existe plus, ses fichiers vivent dans css/, js/ et
// fonts/ (chemins standard du site). Si une ancienne copie jr-theme/ traîne
// encore sur le serveur, on déplace ce qui manque puis on supprime le dossier.
// ─────────────────────────────────────────────────────────────────────────
$jrMoveSql = 'Déplacer jr-theme/ vers css/, js/ et fonts/ puis supprimer le dossier';
$jrDir = __DIR__ . '/jr-theme';
if (is_dir($jrDir)) {
    $jrMoved = 0; $jrErrs = [];
    $jrFiles = [
        'css/tokens.css', 'css/base.css', 'css/components.css', 'css/app.css',
        'js/theme.js', 'js/ui.js',
        'fonts/Inter-var.woff2', 'fonts/JetBrainsMono-var.woff2',
    ];
    foreach ($jrFiles as $rel) {
        $src = $jrDir . '/' . $rel;
        $dst = __DIR__ . '/' . $rel;
        if (!file_exists($src)) continue;
        if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0755, true);
        if (file_exists($dst)) { @unlink($src); continue; } // nouvelle version déjà déployée : on garde
        if (@rename($src, $dst)) $jrMoved++; else $jrErrs[] = $rel;
    }
    // rmdir ne supprime que des dossiers vides : un fichier inattendu est préservé
    foreach (['css', 'js', 'fonts'] as $sub) { @rmdir($jrDir . '/' . $sub); }
    @rmdir($jrDir);
    $results[] = $jrErrs
        ? ['status' => 'error', 'sql' => $jrMoveSql, 'msg' => 'Échec sur : ' . implode(', ', $jrErrs) . ' (droits d\'écriture ?)']
        : ['status' => 'success', 'sql' => $jrMoveSql, 'msg' => $jrMoved . ' fichier(s) déplacé(s), dossier jr-theme/ supprimé'];
} else {
    $results[] = ['status' => 'skip', 'sql' => $jrMoveSql, 'msg' => 'Dossier jr-theme/ absent (déjà migré)'];
}

// ─────────────────────────────────────────────────────────────────────────
// Colonne `required_admin` : caractère obligatoire SPÉCIFIQUE au formulaire
// « Nouvel inscrit » (admin), indépendant de `required` (public / saisie / QR)
// — même principe que `required_saisie_multiple` pour l'« Ajout multiple ».
// Ajout + initialisation en UN passage : à la création de la colonne, on la
// pré-remplit avec la valeur de `required` (comportement identique à l'existant).
// Une fois la colonne créée, on n'y touche PLUS JAMAIS → les choix de l'admin
// dans « Gestion des champs » sont préservés à chaque nouveau lancement d'update.php.
// ─────────────────────────────────────────────────────────────────────────
$requiredAdminSql = "Ajouter la colonne `required_admin` (obligatoire admin) dans `forms`";
try {
    $colExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forms' AND COLUMN_NAME = 'required_admin'"
    )->fetchColumn();
    if ($colExists > 0) {
        $results[] = ['status' => 'skip', 'sql' => $requiredAdminSql, 'msg' => 'Existe déjà'];
    } else {
        $pdo->exec("ALTER TABLE `forms` ADD COLUMN `required_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `required`");
        // Initialisation UNIQUE (à la création) : reprend la valeur de `required`.
        $pdo->exec("UPDATE `forms` SET `required_admin` = `required`");
        $results[] = ['status' => 'success', 'sql' => $requiredAdminSql, 'msg' => 'Colonne ajoutée et initialisée'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $requiredAdminSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Pré-remplissage de `registrations.montant_du` pour les inscrits existants.
// Idempotent : si toutes les lignes ont déjà un montant > 0, on n'agit pas.
// IMPORTANT : on EXCLUT les inscrits « gratuit » (enfant -12 ans sans t-shirt),
// qui doivent rester à 0 € — sinon ils seraient à tort facturés au tarif.
// ─────────────────────────────────────────────────────────────────────────
$initMontantSql = "Pré-remplir registrations.montant_du = registration_fee pour les inscrits existants";
try {
    $remaining = (int) $pdo->query("SELECT COUNT(*) FROM `registrations` WHERE `montant_du` = 0 AND (`paiement_mode` IS NULL OR `paiement_mode` <> 'gratuit')")->fetchColumn();
    if ($remaining === 0) {
        $results[] = ['status' => 'skip', 'sql' => $initMontantSql, 'msg' => 'Déjà appliqué'];
    } else {
        $stmt = $pdo->prepare(
            "UPDATE `registrations` r JOIN `setting` s ON s.id = 1
             SET r.montant_du = COALESCE(s.registration_fee, 0)
             WHERE r.montant_du = 0 AND (r.paiement_mode IS NULL OR r.paiement_mode <> 'gratuit')"
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
// Correction : un enfant -12 ans GRATUIT (sans t-shirt) doit avoir un montant
// de 0 €. Les versions antérieures du pré-remplissage ci-dessus avaient pu les
// passer au tarif (12 €) ; on les remet à 0 € (sinon comptés à tort pour le QR).
// ─────────────────────────────────────────────────────────────────────────
$fixGratuitMontantSql = "Remettre à 0 € le montant des enfants -12 ans gratuits";
try {
    $stmt = $pdo->prepare(
        "UPDATE `registrations` SET `montant_du` = 0
         WHERE (`prestation` = 'enfant_gratuit' OR `paiement_mode` = 'gratuit') AND `montant_du` <> 0"
    );
    $stmt->execute();
    $n = $stmt->rowCount();
    $results[] = ['status' => $n > 0 ? 'success' : 'skip', 'sql' => $fixGratuitMontantSql,
                  'msg' => $n > 0 ? ($n . ' ligne(s) corrigées') : 'Rien à corriger'];
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $fixGratuitMontantSql, 'msg' => $e->getMessage()];
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
// Colonne `date_inscription` (registrations) : date réelle d'inscription, distincte
// de `created_at` (= date d'AJOUT dans le logiciel). Antidatable, elle pilote le
// classement QR. Backfill UNE SEULE FOIS (à la création de la colonne) avec
// `created_at` → les inscrits existants gardent exactement leur classement actuel.
// Idempotent : un re-run saute le backfill, donc les dates antidatées sont préservées.
// ─────────────────────────────────────────────────────────────────────────
$dateInscriptionColSql = "Ajouter la colonne `date_inscription` (registrations) + backfill = created_at";
try {
    $colExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registrations' AND COLUMN_NAME = 'date_inscription'"
    )->fetchColumn();
    if ($colExists > 0) {
        $results[] = ['status' => 'skip', 'sql' => $dateInscriptionColSql, 'msg' => 'Existe déjà (backfill non rejoué)'];
    } else {
        $pdo->exec("ALTER TABLE `registrations` ADD COLUMN `date_inscription` DATETIME DEFAULT CURRENT_TIMESTAMP AFTER `created_at`");
        // Backfill initial : les inscrits existants prennent leur date d'ajout.
        $pdo->exec("UPDATE `registrations` SET `date_inscription` = `created_at`");
        $results[] = ['status' => 'success', 'sql' => $dateInscriptionColSql, 'msg' => 'Colonne ajoutée + backfill = created_at'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $dateInscriptionColSql, 'msg' => $e->getMessage()];
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
// Champ « Commentaire » dans la gestion des champs (table `forms`).
// Zone de texte libre, visible partout (admin / saisie / QR), chiffrée.
// Stocke aussi l'autorisation du représentant légal des inscrits mineurs.
// ─────────────────────────────────────────────────────────────────────────
$formsCommentaireSql = "Ajouter le champ commentaire dans `forms` (zone de texte)";
try {
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM `forms` WHERE `bdd_column` = 'commentaire'")->fetchColumn();
    if ($exists > 0) {
        $results[] = ['status' => 'skip', 'sql' => $formsCommentaireSql, 'msg' => 'Existe déjà'];
    } else {
        $pdo->prepare(
            "INSERT INTO `forms`
              (`fields`, `label`, `field_type`, `bdd_column`, `active`, `required`,
               `is_locked`, `is_default`, `visible_public`, `visible_admin`, `visible_saisie`, `visible_qr`,
               `sort_order`, `options_list`, `encrypted`)
             VALUES ('custom_commentaire', 'Commentaire', 'textarea', 'commentaire', 1, 0,
                     0, 1, 1, 1, 1, 1, 11, NULL, 1)"
        )->execute();
        $results[] = ['status' => 'success', 'sql' => $formsCommentaireSql, 'msg' => 'Champ ajouté'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $formsCommentaireSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Ligne « Autorisation parentale (mineur) » dans la gestion des champs (`forms`).
// Champ spécial (field_type='guardian', sans colonne BDD) : affiche un bloc
// « responsable légal » quand l'âge saisi est inférieur au seuil (options_list).
// Paramétrable depuis « Gestion des champs » : actif / requis / âge / visibilité.
// ─────────────────────────────────────────────────────────────────────────
$formsGuardianSql = "Ajouter le champ 'guardian' (autorisation parentale) dans `forms`";
try {
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM `forms` WHERE `field_type` = 'guardian' OR `fields` = 'guardian_authorization'")->fetchColumn();
    if ($exists > 0) {
        $results[] = ['status' => 'skip', 'sql' => $formsGuardianSql, 'msg' => 'Existe déjà'];
    } else {
        $pdo->prepare(
            "INSERT INTO `forms`
              (`fields`, `label`, `field_type`, `bdd_column`, `active`, `required`,
               `is_locked`, `is_default`, `visible_public`, `visible_admin`, `visible_saisie`, `visible_qr`,
               `sort_order`, `options_list`, `encrypted`)
             VALUES ('guardian_authorization', 'Autorisation parentale (mineur)', 'guardian', NULL, 1, 1,
                     0, 1, 1, 1, 1, 1, 12, '18', 0)"
        )->execute();
        $results[] = ['status' => 'success', 'sql' => $formsGuardianSql, 'msg' => 'Champ ajouté'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $formsGuardianSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Champ « Date d'inscription » dans la gestion des champs (table `forms`).
// Pointe vers la colonne `date_inscription` (date réelle d'inscription, distincte de
// `created_at` = date d'ajout). Antidatable en Ajout multiple / Inscrit unique (admin)
// et mappable depuis un Excel. Vide → DEFAULT du jour. Jamais exposé hors admin/bulk.
// Géré explicitement à l'insertion (date_inscription est dans la liste réservée de
// getAllActiveFieldColumns) → pas de double insertion.
// NB : une version antérieure de cette migration créait ce champ sur `created_at` ;
// on le REPOINTE ici vers `date_inscription` (identifié par fields='inscription_date').
// ─────────────────────────────────────────────────────────────────────────
$formsDateInscSql = "Ajouter / repointer le champ 'Date d'inscription' (date_inscription) dans `forms`";
try {
    $existsField = (int) $pdo->query("SELECT COUNT(*) FROM `forms` WHERE `fields` = 'inscription_date'")->fetchColumn();
    if ($existsField > 0) {
        // Repointe l'ancien champ (qui pouvait viser created_at) vers date_inscription,
        // remet le bon libellé et force les visibilités sûres (admin/bulk uniquement).
        $st = $pdo->prepare("UPDATE `forms`
                          SET `bdd_column` = 'date_inscription', `label` = 'Date d''inscription',
                              `field_type` = 'date', `is_locked` = 0,
                              `visible_public` = 0, `visible_saisie` = 0, `visible_qr` = 0
                        WHERE `fields` = 'inscription_date'
                          AND NOT (`bdd_column` = 'date_inscription' AND `field_type` = 'date'
                                   AND `is_locked` = 0 AND `visible_public` = 0
                                   AND `visible_saisie` = 0 AND `visible_qr` = 0)");
        $st->execute();
        $n = $st->rowCount();
        $results[] = ['status' => $n > 0 ? 'success' : 'skip', 'sql' => $formsDateInscSql,
                      'msg' => $n > 0 ? 'Champ repointé vers date_inscription' : 'Déjà repointé'];
    } else {
        // is_locked=0 → l'admin gère actif/obligatoire/visible admin/bulk. public/saisie/QR=0
        // → jamais exposé hors admin. is_default=1 → pas de bouton « supprimer ».
        $pdo->prepare(
            "INSERT INTO `forms`
              (`fields`, `label`, `field_type`, `bdd_column`, `active`, `required`,
               `is_locked`, `is_default`, `visible_public`, `visible_admin`, `visible_saisie`, `visible_qr`,
               `visible_saisie_multiple`, `required_saisie_multiple`, `sort_order`, `options_list`, `encrypted`)
             VALUES ('inscription_date', 'Date d''inscription', 'date', 'date_inscription', 1, 0,
                     0, 1, 0, 1, 0, 0, 1, 0, 13, NULL, 0)"
        )->execute();
        $results[] = ['status' => 'success', 'sql' => $formsDateInscSql, 'msg' => 'Champ ajouté'];
    }
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $formsDateInscSql, 'msg' => $e->getMessage()];
}

// ─────────────────────────────────────────────────────────────────────────
// Alias d'import AssoConnect : la colonne Excel « date de creation » alimente
// désormais `date_inscription` (date réelle d'inscription) et non plus `created_at`.
// ─────────────────────────────────────────────────────────────────────────
$importAliasSql = "Repointer l'alias d'import 'created_at' → 'date_inscription'";
try {
    $st = $pdo->prepare("UPDATE `import` SET `fields_bdd` = 'date_inscription' WHERE `fields_bdd` = 'created_at'");
    $st->execute();
    $n = $st->rowCount();
    $results[] = ['status' => $n > 0 ? 'success' : 'skip', 'sql' => $importAliasSql,
                  'msg' => $n > 0 ? 'OK' : 'Déjà repointé'];
} catch (PDOException $e) {
    $results[] = ['status' => 'error', 'sql' => $importAliasSql, 'msg' => $e->getMessage()];
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
        // Visibilité bulk des 5 champs essentiels (idempotent : ne touche que ceux à 0).
        $visStmt = $pdo->prepare(
            "UPDATE `forms` SET `visible_saisie_multiple` = 1
              WHERE `bdd_column` IN ('nom', 'prenom', 'email', 'entreprise', 'montant_du')
                AND `visible_saisie_multiple` = 0"
        );
        $visStmt->execute();

        // Requis bulk : UNIQUEMENT nom + prénom. email / entreprise / montant_du sont
        // FACULTATIFS (particulier sans entreprise, inscrit sans email, montant auto-calculé).
        // → corrige aussi les installs où l'ancienne migration les avait rendus requis.
        // L'admin peut toujours rendre un champ requis via « Gestion des champs » (Bulk requis) ;
        // update.php étant supprimé après la mise à jour, ce réglage ne sera pas réécrasé.
        $reqStmt = $pdo->prepare("UPDATE `forms` SET `required_saisie_multiple` = 1
                        WHERE `bdd_column` IN ('nom', 'prenom') AND `required_saisie_multiple` = 0");
        $reqStmt->execute();
        $unreq = $pdo->prepare("UPDATE `forms` SET `required_saisie_multiple` = 0
                        WHERE `bdd_column` IN ('email', 'entreprise', 'montant_du') AND `required_saisie_multiple` = 1");
        $unreq->execute();
        $changed = $visStmt->rowCount() + $reqStmt->rowCount() + $unreq->rowCount();
        $results[] = ['status' => $changed > 0 ? 'success' : 'skip', 'sql' => $bulkAutoCheckSql,
                      'msg' => $changed > 0
                          ? ('Bulk : ' . $changed . ' champ(s) mis à jour (nom/prénom requis ; email/entreprise/montant facultatifs)')
                          : 'Déjà appliqué'];
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
    require_once __DIR__ . '/src/content/accueil_layout.php';
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
    require_once __DIR__ . '/src/content/accueil_layout.php';
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
    $results[] = ['status' => $migratedUsers > 0 ? 'success' : 'skip', 'sql' => 'MIGRATE users.permissions content.* → granular', 'msg' => "$migratedUsers utilisateur(s) migré(s)"];
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

// ─────────────────────────────────────────────────────────────────────────
// Import auto : le token partagé passe de config/.env vers la base (géré depuis
// l'UI, plus aucune édition de fichier). On reprend l'éventuel SYNC_WORKER_TOKEN
// déjà présent dans .env pour ne PAS casser un worker déjà configuré ; sinon il
// sera auto-généré au premier affichage de l'onglet « Import auto ».
// ─────────────────────────────────────────────────────────────────────────
$syncTokenSql = "Reprendre SYNC_WORKER_TOKEN (.env) → sync_assoconnect.worker_token";
try {
    $colExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sync_assoconnect'
            AND COLUMN_NAME = 'worker_token'"
    )->fetchColumn();
    if ($colExists === 0) {
        $results[] = ['status' => 'skip', 'sql' => $syncTokenSql, 'msg' => 'Colonne absente — ALTER non appliqué'];
    } else {
        $cur    = $pdo->query("SELECT worker_token FROM sync_assoconnect WHERE id = 1")->fetchColumn();
        $envTok = trim((string) ($_ENV['SYNC_WORKER_TOKEN'] ?? getenv('SYNC_WORKER_TOKEN') ?: ''));
        if (!empty($cur)) {
            $results[] = ['status' => 'skip', 'sql' => $syncTokenSql, 'msg' => 'Token déjà en base'];
        } elseif ($envTok !== '') {
            $pdo->prepare("UPDATE sync_assoconnect SET worker_token = ? WHERE id = 1")->execute([$envTok]);
            $results[] = ['status' => 'success', 'sql' => $syncTokenSql, 'msg' => 'Token repris depuis .env'];
        } else {
            $results[] = ['status' => 'skip', 'sql' => $syncTokenSql, 'msg' => "Aucun token .env — auto-généré dans l'UI"];
        }
    }
} catch (\Throwable $e) {
    $results[] = ['status' => 'error', 'sql' => $syncTokenSql, 'msg' => $e->getMessage()];
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
<?php include __DIR__ . '/src/partials/auth-head.php'; ?>
</head>
<body>
<div class="auth">
  <div class="auth-frame">
    <div class="auth-pane">
      <a class="brand" href="inc/dashboard.php">
        <?php if (file_exists(__DIR__ . '/files/_logos/logo_fer_rose.png')): ?>
          <img src="files/_logos/logo_fer_rose.png" alt="" style="height:32px;width:auto">
        <?php endif; ?>
        <span class="name">Forbach en Rose</span>
      </a>
      <div class="inner is-wide">
        <div class="oc-icon-area">
          <div class="oc-icon-circle">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
          </div>
          <h1 class="oc-title">Mise à jour de la base de données</h1>
          <p class="oc-subtitle"><?= count($migrations) ?> migration(s) traitée(s)</p>
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

    <?php
      // Détail replié par défaut (la liste est devenue très longue) —
      // déplié automatiquement s'il y a au moins une erreur.
      $updShowDetails = $countErr > 0;
    ?>
    <!-- Même rangée flex que .summary (3 colonnes égales, même gap) : le bouton
         occupe TOUTE la colonne du milieu → mêmes bords gauche/droite que la
         tuile « Ignorées » -->
    <div style="display:flex;gap:var(--sp-3);margin:14px 0 6px;">
      <div style="flex:1"></div>
      <button type="button" id="updToggleDetails" class="oc-btn-secondary" style="flex:1;min-width:0;white-space:nowrap;box-sizing:border-box;">
        <i class="bi bi-list-ul"></i> <span><?= $updShowDetails ? 'Masquer' : 'Détails (' . count($results) . ')' ?></span>
      </button>
      <div style="flex:1"></div>
    </div>

    <ul class="migration-list" id="updMigrationList"<?= $updShowDetails ? '' : ' hidden' ?>>
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

    <script nonce="<?= htmlspecialchars($GLOBALS['csp_nonce'] ?? '') ?>">
    (function () {
      var btn = document.getElementById('updToggleDetails');
      var list = document.getElementById('updMigrationList');
      if (!btn || !list) return;
      var lbl = btn.querySelector('span');
      btn.addEventListener('click', function () {
        list.hidden = !list.hidden;
        lbl.textContent = list.hidden ? 'Détails (<?= count($results) ?>)' : 'Masquer';
        if (!list.hidden) list.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    })();
    </script>
  </div>

  <div class="update-footer">
    <p style="margin-bottom:16px;">
      <a href="update.php?tool=repair-dates" class="oc-btn-secondary" style="width:auto;text-decoration:none">
        <i class="bi bi-calendar-check"></i> Réparer les dates d'inscription (jour/mois inversés)
      </a>
    </p>

    <!-- Auto-suppression : proposée maintenant que la mise à jour est terminée.
         « Oui » supprime le fichier (POST + CSRF) ; « Non » retourne au dashboard
         sans rien faire. -->
    <div class="upd-danger-box">
      <div class="upd-danger-title">
        <i class="bi bi-shield-lock"></i>
        Voulez-vous supprimer <code>update.php</code> ?
      </div>
      <div class="upd-danger-text">
        La mise à jour est terminée. Par sécurité, il est recommandé de supprimer ce
        fichier du serveur. Vous pourrez le réinstaller lors de la prochaine mise à jour.
      </div>
      <div class="upd-actions">
        <form method="post" action="update.php" style="margin:0;"
              onsubmit="return confirm('Supprimer définitivement update.php du serveur ?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_self">
          <button type="submit" class="upd-btn-danger">
            <i class="bi bi-trash3"></i> Oui, supprimer update.php
          </button>
        </form>
        <a href="inc/dashboard.php" class="oc-btn-secondary" style="width:auto;text-decoration:none">
          <i class="bi bi-x-lg"></i> Non, garder le fichier
        </a>
      </div>
    </div>

    <p style="margin-top:16px"><a href="inc/dashboard.php" class="oc-back"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:15px;height:15px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg> Retour au tableau de bord</a></p>
  </div>

      </div><!-- /inner -->
    </div><!-- /auth-pane -->
    <?php include __DIR__ . '/src/partials/auth-art.php'; ?>
  </div><!-- /auth-frame -->
</div><!-- /auth -->
</body>
</html>
