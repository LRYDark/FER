-- --------------------------------------------------------
-- Hôte:                         192.168.12.211
-- Version du serveur:           10.11.11-MariaDB-0+deb12u1 - Debian 12
-- SE du serveur:                debian-linux-gnu
-- HeidiSQL Version:             12.10.0.7000
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Listage de la structure de la base pour ForbachEnRose
DROP DATABASE IF EXISTS `ForbachEnRose`;
CREATE DATABASE IF NOT EXISTS `ForbachEnRose` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;
USE `ForbachEnRose`;

-- Listage de la structure de table ForbachEnRose. photo_albums
DROP TABLE IF EXISTS `photo_albums`;
CREATE TABLE IF NOT EXISTS `photo_albums` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year_id` int(11) NOT NULL,
  `album_title` varchar(255) NOT NULL,
  `album_link` text NOT NULL,
  `album_img` varchar(50) DEFAULT NULL,
  `album_desc` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `year_id` (`year_id`),
  CONSTRAINT `photo_albums_ibfk_1` FOREIGN KEY (`year_id`) REFERENCES `photo_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Les données exportées n'étaient pas sélectionnées.

-- Listage de la structure de table ForbachEnRose. photo_years
DROP TABLE IF EXISTS `photo_years`;
CREATE TABLE IF NOT EXISTS `photo_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `img` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Les données exportées n'étaient pas sélectionnées.

-- Listage de la structure de table ForbachEnRose. registrations
DROP TABLE IF EXISTS `registrations`;
CREATE TABLE IF NOT EXISTS `registrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inscription_no` int(11) NOT NULL,
  `nom` varchar(80) NOT NULL,
  `prenom` varchar(80) NOT NULL,
  `tel` varchar(30) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `naissance` varchar(50) DEFAULT NULL,
  `sexe` enum('H','F','Autre') DEFAULT 'H',
  `tshirt_size` enum('-','XS','S','M','L','XL','XXL') DEFAULT '-',
  `ville` varchar(120) NOT NULL,
  `entreprise` varchar(120) DEFAULT NULL,
  `origine` varchar(40) DEFAULT 'en ligne',
  `paiement_mode` enum('en ligne (CB)','espece','cheque','CB') DEFAULT 'espece',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inscription_no` (`inscription_no`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `registrations_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=44 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Les données exportées n'étaient pas sélectionnées.

-- Listage de la structure de table ForbachEnRose. setting
DROP TABLE IF EXISTS `setting`;
CREATE TABLE IF NOT EXISTS `setting` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assoconnect_js` longtext DEFAULT NULL,
  `assoconnect_iframe` longtext DEFAULT NULL,
  `title` varchar(50) DEFAULT NULL,
  `title_color` varchar(50) DEFAULT NULL,
  `picture` varchar(50) DEFAULT NULL,
  `footer` varchar(50) DEFAULT NULL,
  `registration_fee` int(10) DEFAULT NULL,
  `titleAccueil` varchar(50) DEFAULT NULL,
  `edition` varchar(50) DEFAULT NULL,
  `link_facebook` varchar(50) DEFAULT NULL,
  `link_instagram` varchar(50) DEFAULT NULL,
  `accueil_active` int(2) DEFAULT NULL,
  `date_course` timestamp NULL DEFAULT NULL,
  `picture_accueil` varchar(50) DEFAULT NULL,
  `picture_partner` varchar(50) DEFAULT NULL,
  `picture_gradient` varchar(50) DEFAULT NULL,
  `titleParcours` varchar(50) DEFAULT NULL,
  `parcoursDesc` text DEFAULT NULL,
  `picture_parcours` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Les données exportées n'étaient pas sélectionnées.

-- Listage de la structure de table ForbachEnRose. users
DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','user','viewer','saisie') NOT NULL DEFAULT 'viewer',
  `organisation` varchar(120) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Les données exportées n'étaient pas sélectionnées.

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
