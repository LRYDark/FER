-- ============================================================================
--  Forbach en Rose — ANNULATION DE L'ANCIEN LOT 1 (§ 0 du lot 1 révisé)
-- ============================================================================
--
--  À EXÉCUTER UNE SEULE FOIS, AVANT de relancer update.php.
--
--  L'ancien lot 1 modifiait la table `registrations` et créait des tables
--  indexées sur `registrations.id` / `edition_id`. Le lot 1 révisé abandonne
--  cette approche : les nouvelles tables désignent un coureur par sa CLÉ MÉTIER
--  (annee, inscription_no), et `registrations` n'est plus touchée du tout.
--
--  Ce script :
--    1. supprime les lignes d'archive éventuellement consolidées dans
--       `registrations` (les tables `registrations_AAAA` n'ont jamais été
--       modifiées : rien n'est perdu) ;
--    2. supprime la clé étrangère, les index et les colonnes ajoutés à
--       `registrations` ;
--    3. restaure l'index UNIQUE d'origine `inscription_no` (inscription_no) ;
--    4. supprime les tables de l'ancien lot 1.
--
--  ⚠️ POURQUOI L'ÉTAPE 4 EST INDISPENSABLE
--  Les tables `editions`, `participants`, `participant_auth_codes`,
--  `participant_devices`, `registration_transfers`, `resultats`, `traces_gps`
--  et `detections` sont RECRÉÉES par le lot révisé avec une STRUCTURE
--  DIFFÉRENTE (annee + inscription_no au lieu de registration_id/edition_id).
--  update.php utilisant `CREATE TABLE IF NOT EXISTS`, il ignorerait
--  silencieusement les anciennes tables et vous garderiez un schéma faux.
--  update.php refuse d'ailleurs de continuer tant qu'il détecte une ancienne
--  structure — c'est ce message qui vous a amené ici.
--
--  ⚠️ SAUVEGARDEZ LA BASE AVANT (mysqldump / export phpMyAdmin).
--
--  UTILISATION
--    mysql -u <user> -p <base> < rollback_lot1.sql
--    ou phpMyAdmin → onglet SQL → coller ce fichier → Exécuter
--
--  IDEMPOTENT : chaque opération teste son existence dans INFORMATION_SCHEMA
--  et s'exécute en SQL dynamique. Rejouable, y compris sur une base où
--  l'ancien lot 1 n'a jamais été appliqué. Volontairement écrit SANS
--  `DROP ... IF EXISTS` sur les colonnes et index (syntaxe MariaDB absente de
--  MySQL 8) : le fichier fonctionne sur les deux moteurs. Aucune instruction
--  DELIMITER ni procédure stockée.
-- ============================================================================

SET @DB := DATABASE();

-- ────────────────────────────────────────────────────────────────────────────
-- 1. Retirer de `registrations` les lignes issues d'une consolidation
--    d'archives (toute ligne rattachée à une édition NON active).
--    Avant l'ancien lot 1, `registrations` ne contenait que l'édition en cours :
--    cette suppression est donc l'exact inverse de la consolidation.
--    Les tables `registrations_AAAA` ne sont JAMAIS touchées.
-- ────────────────────────────────────────────────────────────────────────────
SET @sql := (
  SELECT IF(COUNT(*) = 2,
    'DELETE r FROM `registrations` r JOIN `editions` e ON e.id = r.edition_id WHERE e.is_active = 0',
    'SELECT ''[skip] colonne edition_id ou table editions absente'' AS info')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @DB
    AND ((TABLE_NAME = 'registrations' AND COLUMN_NAME = 'edition_id')
      OR (TABLE_NAME = 'editions'      AND COLUMN_NAME = 'is_active'))
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ────────────────────────────────────────────────────────────────────────────
-- 2. Clé étrangère registrations.edition_id → editions.id
--    (à retirer AVANT la colonne et AVANT la table `editions`)
-- ────────────────────────────────────────────────────────────────────────────
SET @sql := (
  SELECT IF(COUNT(*) > 0,
    'ALTER TABLE `registrations` DROP FOREIGN KEY `fk_registrations_edition`',
    'SELECT ''[skip] FK fk_registrations_edition absente'' AS info')
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = @DB AND TABLE_NAME = 'registrations'
    AND CONSTRAINT_NAME = 'fk_registrations_edition' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ────────────────────────────────────────────────────────────────────────────
-- 3. Index UNIQUE composite (edition_id, inscription_no)
-- ────────────────────────────────────────────────────────────────────────────
SET @sql := (
  SELECT IF(COUNT(*) > 0,
    'ALTER TABLE `registrations` DROP INDEX `idx_edition_inscription`',
    'SELECT ''[skip] index idx_edition_inscription absent'' AS info')
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @DB AND TABLE_NAME = 'registrations' AND INDEX_NAME = 'idx_edition_inscription'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ────────────────────────────────────────────────────────────────────────────
-- 4. Restaurer l'index UNIQUE d'origine sur `inscription_no` seul
--    Nom d'origine, cf. install.php : `inscription_no`.
--    Échouerait si des doublons subsistaient : l'étape 1 les a supprimés.
-- ────────────────────────────────────────────────────────────────────────────
SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `registrations` ADD UNIQUE KEY `inscription_no` (`inscription_no`)',
    'SELECT ''[skip] index UNIQUE inscription_no déjà présent'' AS info')
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @DB AND TABLE_NAME = 'registrations' AND INDEX_NAME = 'inscription_no'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ────────────────────────────────────────────────────────────────────────────
-- 5. Index secondaires ajoutés par l'ancien lot 1
-- ────────────────────────────────────────────────────────────────────────────
SET @sql := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `registrations` DROP INDEX `idx_edition`',
  'SELECT ''[skip] idx_edition absent'' AS info') FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @DB AND TABLE_NAME = 'registrations' AND INDEX_NAME = 'idx_edition');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `registrations` DROP INDEX `idx_email_norm`',
  'SELECT ''[skip] idx_email_norm absent'' AS info') FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @DB AND TABLE_NAME = 'registrations' AND INDEX_NAME = 'idx_email_norm');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `registrations` DROP INDEX `idx_participant`',
  'SELECT ''[skip] idx_participant absent'' AS info') FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @DB AND TABLE_NAME = 'registrations' AND INDEX_NAME = 'idx_participant');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ────────────────────────────────────────────────────────────────────────────
-- 6. Colonnes ajoutées par l'ancien lot 1
--    ⚠️ `naissance` n'a JAMAIS été modifiée : il n'y a rien à y restaurer.
-- ────────────────────────────────────────────────────────────────────────────
SET @sql := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `registrations` DROP COLUMN `naissance_source`',
  'SELECT ''[skip] naissance_source absente'' AS info') FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @DB AND TABLE_NAME = 'registrations' AND COLUMN_NAME = 'naissance_source');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `registrations` DROP COLUMN `date_naissance`',
  'SELECT ''[skip] date_naissance absente'' AS info') FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @DB AND TABLE_NAME = 'registrations' AND COLUMN_NAME = 'date_naissance');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `registrations` DROP COLUMN `annee_naissance`',
  'SELECT ''[skip] annee_naissance absente'' AS info') FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @DB AND TABLE_NAME = 'registrations' AND COLUMN_NAME = 'annee_naissance');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `registrations` DROP COLUMN `participant_id`',
  'SELECT ''[skip] participant_id absente'' AS info') FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @DB AND TABLE_NAME = 'registrations' AND COLUMN_NAME = 'participant_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `registrations` DROP COLUMN `email_normalise`',
  'SELECT ''[skip] email_normalise absente'' AS info') FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @DB AND TABLE_NAME = 'registrations' AND COLUMN_NAME = 'email_normalise');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `registrations` DROP COLUMN `edition_id`',
  'SELECT ''[skip] edition_id absente'' AS info') FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @DB AND TABLE_NAME = 'registrations' AND COLUMN_NAME = 'edition_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ────────────────────────────────────────────────────────────────────────────
-- 7. Tables de l'ancien lot 1 — elles seront RECRÉÉES avec la nouvelle
--    structure (annee, inscription_no) au prochain lancement de update.php.
--    ⚠️ Les tables d'archive `registrations_AAAA` ne sont JAMAIS supprimées.
-- ────────────────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `detections`;
DROP TABLE IF EXISTS `traces_gps`;
DROP TABLE IF EXISTS `resultats`;
DROP TABLE IF EXISTS `registration_transfers`;
DROP TABLE IF EXISTS `participant_devices`;
DROP TABLE IF EXISTS `participant_auth_codes`;
DROP TABLE IF EXISTS `participant_registrations`;
DROP TABLE IF EXISTS `participants`;
DROP TABLE IF EXISTS `editions`;

-- ────────────────────────────────────────────────────────────────────────────
-- 8. Rapport de contrôle
--    Tout doit valoir 0, SAUF `unique_inscription_no` qui doit valoir 1.
-- ────────────────────────────────────────────────────────────────────────────
SELECT
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = @DB AND TABLE_NAME IN
       ('editions','participants','participant_registrations','participant_auth_codes',
        'participant_devices','registration_transfers','resultats','traces_gps','detections'))  AS tables_restantes,
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @DB AND TABLE_NAME = 'registrations' AND COLUMN_NAME IN
       ('edition_id','email_normalise','participant_id',
        'annee_naissance','date_naissance','naissance_source'))                                 AS colonnes_restantes,
  (SELECT COUNT(DISTINCT INDEX_NAME) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = @DB AND TABLE_NAME = 'registrations' AND INDEX_NAME IN
       ('idx_edition','idx_edition_inscription','idx_email_norm','idx_participant'))            AS index_restants,
  (SELECT COUNT(DISTINCT INDEX_NAME) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = @DB AND TABLE_NAME = 'registrations'
       AND INDEX_NAME = 'inscription_no' AND NON_UNIQUE = 0)                                    AS unique_inscription_no,
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @DB AND TABLE_NAME = 'registrations'
       AND CONSTRAINT_NAME = 'fk_registrations_edition')                                        AS fk_restantes;
