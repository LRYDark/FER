-- Migration: Systeme de commentaires pour les actualites
-- A executer sur la base ForbachEnRose

CREATE TABLE IF NOT EXISTS `news_comments` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `news_comments_likes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `comment_id` INT UNSIGNED NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_unique_like` (`comment_id`, `ip_address`),
  CONSTRAINT `fk_like_comment` FOREIGN KEY (`comment_id`) REFERENCES `news_comments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `news_banned_ips` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `banned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `banned_by` VARCHAR(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
