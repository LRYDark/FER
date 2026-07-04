<?php
/**
 * update.php — Migrations de base de données
 * Lance ce fichier une seule fois via le navigateur puis supprime-le.
 */
require __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/registrations_core.php';

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
        $affected = $pdo->exec($sql);
        // 0 ligne affectée = rien à faire :
        //   - INSERT IGNORE → la ligne existe déjà ;
        //   - UPDATE        → la valeur cible est déjà en place (ex. is_locked déjà à 0).
        // On affiche « Déjà appliqué » plutôt que « OK » pour éviter de croire qu'une
        // action a lieu à chaque passage.
        if ($affected === 0 && preg_match('/^\s*INSERT\s+IGNORE/i', $sql)) {
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
    background: linear-gradient(135deg, var(--primary, #f42182), var(--primary-hover, #db2777));
    padding: 28px 32px;
    color: var(--primary-text, #fff);
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
  .update-footer a { color: var(--primary, #f42182); text-decoration: none; font-weight: 600; }
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
    <p style="margin-bottom:12px;">
      <a href="update.php?tool=repair-dates" style="display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,var(--primary, #f42182),var(--primary-hover, #db2777));color:var(--primary-text, #fff);border-radius:10px;padding:10px 18px;text-decoration:none;font-weight:600;font-size:13px;">
        <i class="bi bi-calendar-check"></i> Réparer les dates d'inscription (jour/mois inversés)
      </a>
    </p>
    Terminé — tu peux <a href="inc/dashboard.php">retourner au dashboard</a> et supprimer ce fichier.
  </div>
</div>
</body>
</html>
