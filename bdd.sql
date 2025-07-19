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
CREATE DATABASE IF NOT EXISTS `ForbachEnRose` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;
USE `ForbachEnRose`;

-- Listage de la structure de table ForbachEnRose. forms
CREATE TABLE IF NOT EXISTS `forms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fields` varchar(50) DEFAULT NULL,
  `active` int(2) NOT NULL DEFAULT 0,
  `required` int(2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Listage des données de la table ForbachEnRose.forms : ~8 rows (environ)
DELETE FROM `forms`;
INSERT INTO `forms` (`id`, `fields`, `active`, `required`) VALUES
	(1, 'required_name', 0, 1),
	(2, 'required_firstname', 0, 1),
	(3, 'required_phone', 0, 1),
	(4, 'required_email', 0, 1),
	(5, 'required_date_of_birth', 0, 1),
	(6, 'required_sex', 0, 1),
	(7, 'required_city', 0, 1),
	(8, 'required_company', 0, 0);

-- Listage de la structure de table ForbachEnRose. import
CREATE TABLE IF NOT EXISTS `import` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fields_bdd` varchar(50) DEFAULT NULL,
  `fields_excel` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Listage des données de la table ForbachEnRose.import : ~12 rows (environ)
DELETE FROM `import`;
INSERT INTO `import` (`id`, `fields_bdd`, `fields_excel`) VALUES
	(1, 'inscription_no', 'numero billet'),
	(2, 'nom', 'prenom participant'),
	(3, 'prenom', 'nom participant'),
	(4, 'tel', 'telephone mobile'),
	(5, 'email', 'adresse email'),
	(6, 'naissance', 'annee de naissance'),
	(7, 'sexe', 'sexe'),
	(8, 'ville', 'ville'),
	(9, 'entreprise', 'nom de l\\\'equipe'),
	(10, 'paiement_mode', 'Moyen de paiement'),
	(11, 'origine', 'pays'),
	(12, 'created_at', 'date de creation');

-- Listage de la structure de table ForbachEnRose. news
CREATE TABLE IF NOT EXISTS `news` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `img_article` varchar(255) DEFAULT NULL,
  `title_article` varchar(255) DEFAULT NULL,
  `desc_article` mediumtext DEFAULT NULL,
  `date_publication` timestamp NULL DEFAULT NULL,
  `like` int(11) DEFAULT 0,
  `dislike` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Listage des données de la table ForbachEnRose.news : ~1 rows (environ)
DELETE FROM `news`;
INSERT INTO `news` (`id`, `img_article`, `title_article`, `desc_article`, `date_publication`, `like`, `dislike`) VALUES
	(1, 'img_6873979ef27ef6.61742701.jpg', 'TEST', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. In in viverra lectus. Donec elementum nulla ante, at ullamcorper erat cursus et. Sed gravida velit ac laoreet mollis. Vivamus cursus, ipsum id iaculis vulputate, lorem nulla elementum velit, quis auctor diam ante nec dolor. Maecenas rhoncus enim eget velit ultricies molestie. Fusce a magna vel nisi mollis sagittis. Suspendisse potenti. Ut a urna elit. Proin leo orci, bibendum ac pharetra in, pharetra vitae arcu. Nullam vehicula mi id vehicula elementum. Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia curae; Pellentesque habitant morbi tristique senectus et netus et malesuada fames ac turpis egestas. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Nulla aliquam et magna ac egestas.', '2025-07-15 15:11:46', 29, 18);

-- Listage de la structure de table ForbachEnRose. partners_albums
CREATE TABLE IF NOT EXISTS `partners_albums` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year_id` int(11) NOT NULL,
  `album_title` varchar(255) NOT NULL,
  `album_img` varchar(255) DEFAULT NULL,
  `album_desc` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `year_id` (`year_id`),
  CONSTRAINT `partners_albums_ibfk_1` FOREIGN KEY (`year_id`) REFERENCES `partners_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Listage des données de la table ForbachEnRose.partners_albums : ~22 rows (environ)
DELETE FROM `partners_albums`;
INSERT INTO `partners_albums` (`id`, `year_id`, `album_title`, `album_img`, `album_desc`) VALUES
	(7, 5, 'Hyundai', 'image_3261086_20250521_ob_bc7a14_hyundai1.jpg', 'Hyundai, un partenaire essentiel !'),
	(8, 5, 'Yzico', 'image_3261086_20250522_ob_eed44e_yzico1.png', 'Yzico, c\'est l\'accompagnement des entreprises, mais pour Forbach en rose c\'est d\'abord un partenaire indispensable qui soutient notre action depuis de nombreuses années.'),
	(9, 5, 'Nsti', 'image_3261086_20250528_ob_cc5f0e_nsti1.png', 'Ils ont rejoint Forbach en rose en 2024 et nous soutiennent encore pleinement cette année.'),
	(10, 5, 'Cacopardo', 'image_3261086_20250601_ob_dab962_cacopardo.png', 'La boulangerie Cacopardo sera au four, le 6 juillet, sur site, mais également au moulin dans les semaines qui précèdent...'),
	(11, 5, 'TV8', 'image_3261086_20250607_ob_7c937d_tv8.png', 'Avec nous depuis 2022, TV8 soutient notre action avec une belle fidélité.'),
	(12, 5, 'Piscine Olympique de Forbach', 'image_3261086_20250618_ob_65b2fe_piscine-1.png', 'Une fois par an, la Piscine Olympique de Forbach voit tout en rose ! Elle nous soutient en logistique, en eau, en électricité et en communication et elle met ses sanitaires à disposition des participants. Son équipe nous donne un sérieux coup de main le jour de la manifestation et en amont pour les inscriptions. Un grand merci à son directeur et au président de la Communauté d\'Agglomération Forbach Porte de France dont le soutien est essentiel pour Forbach en rose.'),
	(13, 5, 'AVS Santé', 'image_3261086_20250618_ob_a4c489_avs.png', 'Le groupe AVS Santé nous accompagne depuis de nombreuses années et s\'investit dans l\'organisation'),
	(14, 5, 'Profil Coiffure', 'image_3261086_20250618_ob_a67b11_profil-coiffure2025.png', 'Une belle équipe qui est avec nous à chaque édition.'),
	(15, 5, 'Crédit Mutuel', 'image_3261086_20250618_ob_c3fb63_creditmut1.png', 'Le Crédit Mutuel nous a rejoint cette année... Et peut-être les suivantes...'),
	(16, 5, 'Gi One', 'image_3261086_20250621_ob_ad403d_gi-one1.png', 'C\'est Virginie qui mènera le bal, avant le départ.'),
	(17, 5, 'Allianz', 'image_3261086_20250625_ob_bd3110_allianz.png', 'Avec nous depuis très longtemps ! Merci.'),
	(18, 5, 'ForBikes', 'image_3261086_20250625_ob_86e787_forbikes-1.png', 'Indissociable de Forbach en rose !'),
	(19, 5, 'JECA', 'image_3261086_20250625_ob_4014b1_jeca.png', 'JECA : toujours à nos côtés !'),
	(20, 5, 'Banque Populaire', 'image_3261086_20250626_ob_e55662_bpalc1.png', 'Un petit coup de pouce, comme chaque année !'),
	(21, 5, 'J.L.S', 'image_3261086_20250626_ob_41ea24_jls1.png', 'L\'adresse à changé, mais JLS reste fidèle à Forbach en rose'),
	(22, 5, 'Ville de Forbach', 'image_3261086_20250627_ob_14b62d_ville-de-forbach.png', 'Un grand merci aux services de la Ville de Forbach qui se mettent en 4 pour nous faciliter l\'organisation de Forbach en rose'),
	(23, 5, 'Friderich', 'image_3261086_20250630_ob_3ed1d6_friderich1.png', 'Avec nous depuis 2019, avec une belle générosité.'),
	(24, 5, 'NoVelio', 'image_3261086_20250701_ob_2102ee_novelio1.png', 'Un partenaire qui compte...'),
	(25, 5, 'Coccimarket', 'image_3261086_20250701_ob_f61e9d_coccimarket.png', 'Un partenaire de la 1ère heure !'),
	(26, 5, 'Sarreguemines distribution', 'image_3261086_20250702_ob_07422b_22-sgmnes-distribution.jpg', 'En toute discretion...'),
	(27, 5, 'Carré mauve', 'image_3261086_20250702_ob_3755a9_carremauve.jpg', 'C\'est le patron qui offre la musique devant le Carré mauve.'),
	(28, 5, 'Carrefour', 'image_3261086_20250704_ob_60888f_carrefour.png', '');

-- Listage de la structure de table ForbachEnRose. partners_years
CREATE TABLE IF NOT EXISTS `partners_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `img` varchar(255) DEFAULT NULL,
  `desc` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Listage des données de la table ForbachEnRose.partners_years : ~1 rows (environ)
DELETE FROM `partners_years`;
INSERT INTO `partners_years` (`id`, `year`, `title`, `img`, `desc`) VALUES
	(5, 2025, 'Partenaires 2025', 'image_3261086_20250626_ob_cecaa6_panneau-2.png', '<p><span style="color: rgb(255, 0, 255);">Forbach en rose</span>, c\'est d\'abord l\'engagement des b&eacute;n&eacute;voles, avant et pendant cette journ&eacute;e.</p>\r\n<p>Mais elle ne pourrait pas atteindre son but sans le soutien des entreprises et institutions, surtout locales, dont l\'appui est essentiel &agrave; la r&eacute;ussite de l\'&eacute;v&egrave;nement. Cette r&eacute;ussite se mesure au succ&egrave;s populaire qu\'elle rencontre mais aussi, il faut bien le dire, au montant du ch&egrave;que que l\'US Forbach Athl&eacute;tisme sera en mesure de donner &agrave; la Ligue contre le cancer.</p>\r\n<p>Cette rubrique est d&eacute;di&eacute;e &agrave; ces entreprises, elles seront cit&eacute;es avec gratitude, au fur et &agrave; mesure de leur adh&eacute;sion, quel que soit la forme que prendra leur parrainage. En couverture, un panneau qui reprend leur logo et qui s\'&eacute;toffera au fil des semaines.</p>');

-- Listage de la structure de table ForbachEnRose. photo_albums
CREATE TABLE IF NOT EXISTS `photo_albums` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year_id` int(11) NOT NULL,
  `album_title` varchar(255) NOT NULL,
  `album_link` text NOT NULL,
  `album_img` varchar(255) DEFAULT NULL,
  `album_desc` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `year_id` (`year_id`),
  CONSTRAINT `photo_albums_ibfk_1` FOREIGN KEY (`year_id`) REFERENCES `photo_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Listage des données de la table ForbachEnRose.photo_albums : ~1 rows (environ)
DELETE FROM `photo_albums`;
INSERT INTO `photo_albums` (`id`, `year_id`, `album_title`, `album_link`, `album_img`, `album_desc`) VALUES
	(19, 11, 'Forbach en rose 2025', 'https://www.flickr.com/photos/199455542@N02/albums/72177720327352730', 'image_3261086_20250707_ob_96d37b_515923325-1277800833912878-59488173761.jpg', '');

-- Listage de la structure de table ForbachEnRose. photo_years
CREATE TABLE IF NOT EXISTS `photo_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Listage des données de la table ForbachEnRose.photo_years : ~1 rows (environ)
DELETE FROM `photo_years`;
INSERT INTO `photo_years` (`id`, `year`, `title`) VALUES
	(11, 2025, 'Album 2025');

-- Listage de la structure de table ForbachEnRose. qrcodes
CREATE TABLE IF NOT EXISTS `qrcodes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `organisation` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `qr_url` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_token` (`token`),
  KEY `idx_organisation` (`organisation`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Listage des données de la table ForbachEnRose.qrcodes : ~1 rows (environ)
DELETE FROM `qrcodes`;
INSERT INTO `qrcodes` (`id`, `organisation`, `token`, `qr_url`, `description`, `is_active`, `created_at`, `updated_at`, `created_by`) VALUES
	(2, 'USFathlé', '97012365e3d102fc467796977708c3990b976e2aa1e5f5d2c6bde6b384e7a3e1', 'https://jr.zerobug-57.fr/FER/public/register.php?token=97012365e3d102fc467796977708c3990b976e2aa1e5f5d2c6bde6b384e7a3e1', '', 1, '2025-07-19 15:42:04', '2025-07-19 15:58:19', 1);

-- Listage de la structure de table ForbachEnRose. registrations
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
  `paiement_mode` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inscription_no` (`inscription_no`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `registrations_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2167 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Listage des données de la table ForbachEnRose.registrations : ~0 rows (environ)
DELETE FROM `registrations`;

-- Listage de la structure de table ForbachEnRose. registrations_stats
CREATE TABLE IF NOT EXISTS `registrations_stats` (
  `year` int(11) NOT NULL,
  `total_inscrits` int(11) NOT NULL,
  `tshirt_xs` int(11) NOT NULL,
  `tshirt_s` int(11) NOT NULL,
  `tshirt_m` int(11) NOT NULL,
  `tshirt_l` int(11) NOT NULL,
  `tshirt_xl` int(11) NOT NULL,
  `tshirt_xxl` int(11) NOT NULL,
  `age_moyen` decimal(5,2) DEFAULT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `ville_top` varchar(255) DEFAULT NULL,
  `entreprise_top` varchar(255) DEFAULT NULL,
  `plus_vieux_h` varchar(255) DEFAULT NULL,
  `plus_vieille_f` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Listage des données de la table ForbachEnRose.registrations_stats : ~2 rows (environ)
DELETE FROM `registrations_stats`;
INSERT INTO `registrations_stats` (`year`, `total_inscrits`, `tshirt_xs`, `tshirt_s`, `tshirt_m`, `tshirt_l`, `tshirt_xl`, `tshirt_xxl`, `age_moyen`, `table_name`, `ville_top`, `entreprise_top`, `plus_vieux_h`, `plus_vieille_f`, `created_at`) VALUES
	(2025, 438, 4, 3, 2, 1, 0, 1, 41.26, 'registrations_2025', 'Forbach', 'Collectif run', 'Bertrand FELT', 'Marie-Claire GREFF', '2025-07-19 12:04:46'),
	(2026, 236, 0, 0, 0, 0, 0, 0, 41.26, 'registrations_2026', NULL, NULL, NULL, NULL, '2025-07-19 09:47:26');

-- Listage de la structure de table ForbachEnRose. setting
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
  `link_facebook` varchar(255) DEFAULT NULL,
  `link_instagram` varchar(255) DEFAULT NULL,
  `accueil_active` int(2) NOT NULL DEFAULT 0,
  `date_course` timestamp NULL DEFAULT NULL,
  `picture_accueil` varchar(255) DEFAULT NULL,
  `picture_partner` varchar(255) DEFAULT NULL,
  `picture_gradient` varchar(255) DEFAULT NULL,
  `titleParcours` varchar(255) DEFAULT NULL,
  `parcoursDesc` text DEFAULT NULL,
  `picture_parcours` varchar(255) DEFAULT NULL,
  `div_reglementation` mediumtext DEFAULT NULL,
  `social_networks` int(11) NOT NULL DEFAULT 0,
  `link_cancer` varchar(255) DEFAULT NULL,
  `debogage` int(2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Listage des données de la table ForbachEnRose.setting : ~1 rows (environ)
DELETE FROM `setting`;
INSERT INTO `setting` (`id`, `assoconnect_js`, `assoconnect_iframe`, `title`, `title_color`, `picture`, `footer`, `registration_fee`, `titleAccueil`, `edition`, `link_facebook`, `link_instagram`, `accueil_active`, `date_course`, `picture_accueil`, `picture_partner`, `picture_gradient`, `titleParcours`, `parcoursDesc`, `picture_parcours`, `div_reglementation`, `social_networks`, `link_cancer`, `debogage`) VALUES
	(1, '<script src="https://us-forbach-athletisme-61094f42af9e6.assoconnect.com/public/build/js/iframe.js"></script>', '<div class="iframe-asc-container" data-type="collect" data-collect-id="01K0E9TND8ZVCBCANA0MY680X3"></div>', 'Forbach en Rose (Site de test)', '#ffffff', 'img_6873979615d018.75606952.jpg', '© 2025 Forbach en Rose', 12, NULL, 'Édition 2026', 'https://www.facebook.com/share/1CTYnT4xbn/?mibextid=wwXIfr', 'https://www.instagram.com/forbachenrose?igsh=ODdtN3oxejFhZDk5', 1, '2026-07-04 22:00:00', NULL, NULL, 'img_687397ae324891.78948445.jpg', 'Parcours', 'Départ au parking de la piscine, un passage dans la forêt tout proche, une montée au Parc du Schlossberg et retour à la piscine en passant par le centre-ville de Forbach. Des animations musicales seront proposées sur le parcours.', 'img_687397ae326c77.50376868.jpg', '<div class="assoconnect-widget">\r\n<div class="Post-body">\r\n<div class="ob-sections">\r\n<div class="ob-section ob-section-html ">\r\n<p>R&egrave;glement de <strong><em>"<span style="color: rgb(255, 0, 255);">Forbach en rose</span>"&nbsp;</em></strong>dans et autour de la Ville de Forbach</p>\r\n<p><strong>Article 1 : organisateur</strong></p>\r\n<p>Cette &eacute;dition de&nbsp;<strong><em>"Forbach en rose"</em></strong>,&nbsp;est organis&eacute;e par l\'US Forbach Athl&eacute;tisme, en collaboration avec la Ligue Contre le Cancer. En tant qu&rsquo;organisateur, l\'US Forbach Athl&eacute;tisme et ses pr&eacute;pos&eacute;s sont couverts par une assurance Responsabilit&eacute; Civile souscrite aupr&egrave;s de&nbsp;<strong>Mutuelle Assurance MMA</strong></p>\r\n<p>Cette &eacute;dition se d&eacute;roulera le dimanche&nbsp;<strong>6 juillet 2025</strong>.<br>D&eacute;part :&nbsp;<strong>10h30</strong><br>Cl&ocirc;ture :&nbsp;<strong>13h30</strong></p>\r\n<p><br>Les personnes mandat&eacute;es pour l&rsquo;organisation de cette manifestation sont :</p>\r\n<ul>\r\n<li><span lang="EN-GB">Pina Cesarec (LCC)</span></li>\r\n<li><span lang="EN-GB">Thierry Fricker (USF)</span></li>\r\n</ul>\r\n<p>D&eacute;part et arriv&eacute;e : Site de la Piscine, rue F&eacute;lix Barth &agrave; Forbach.</p>\r\n<p>&nbsp;</p>\r\n<p><strong>Article 2 : &Eacute;preuves, distances, tarifs, &acirc;ges</strong></p>\r\n<p>Boucle de 7km &agrave; parcourir en marchant ou en courant.<br>L&rsquo;accord d&rsquo;un des parents ou du repr&eacute;sentant l&eacute;gal est obligatoire pour les participant&middot;es mineur&middot;es.</p>\r\n<p><u>Tarif unique</u>&nbsp;: 12&euro;</p>\r\n<p>L\'inscription n\'est pas obligatoire et sera gratuite pour les moins de 12 ans (n&eacute;&middot;e apr&egrave;s le 7 juillet&nbsp;<strong>2012</strong>). Pour obtenir un t-shirt il faudra n&eacute;anmoins s\'acquitter de la somme de 12&euro;</p>\r\n<p><a href="http://lamessine.eu/index.php/parcours/">Parcours</a>&nbsp;: cf plan en annexe.</p>\r\n<p>&nbsp;</p>\r\n<p><strong>Article 2bis :</strong></p>\r\n<p>L\'ensemble des b&eacute;n&eacute;fices sera attribu&eacute; &agrave; la lutte contre le cancer et tout particuli&egrave;rement &agrave; la Ligue contre le cancer, &agrave; l\'issue de l\'exercice 2025.<br>Les organisateurs se r&eacute;servent cependant le droit de provisionner une somme plafonn&eacute;e &agrave; un maximum de 20% du montant total des gains, pour se garantir contre d\'&eacute;ventuels frais additionnels, et anticiper sur les d&eacute;penses de l\'&eacute;dition future. Cette somme ne pourra cependant pas faire l\'objet d\'une attribution autre que Forbach en Rose.</p>\r\n<p><strong>Article 3 : Modalit&eacute;s d&rsquo;inscription</strong></p>\r\n<p><strong>Certificat m&eacute;dical (Article L. 231-3 du Code du Sport)</strong></p>\r\n<p><strong><em>"Forbach en rose"</em></strong>&nbsp;n\'est pas une comp&eacute;tition et&nbsp;<strong>un</strong>&nbsp;<strong>certificat m&eacute;dical ne sera pas exig&eacute;</strong>.<br>Les inscrit.es souhaitant courir ou marcher le feront sous leur propre responsabilit&eacute;.</p>\r\n<p><strong>Assurances</strong></p>\r\n<p>Les licenci&eacute;&middot;es b&eacute;n&eacute;ficient des garanties li&eacute;es &agrave; leur licence. Il incombe aux engag&eacute;&middot;es non licenci&eacute;&middot;es de s&rsquo;assurer personnellement.<br>Il est express&eacute;ment indiqu&eacute; que les engag&eacute;&middot;es participent sous leur propre et exclusive responsabilit&eacute;.</p>\r\n<p><strong>Article 4 : Engagement</strong></p>\r\n<p>Tout engagement est personnel, ferme et d&eacute;finitif.<br>Il ne peut faire l&rsquo;objet d\'un remboursement pour quelque motif que ce soit, m&ecirc;me en cas d&rsquo;annulation de l&rsquo;&eacute;preuve.</p>\r\n<p><strong>Article 5 : Modalit&eacute;s d&rsquo;organisation des &eacute;preuves</strong></p>\r\n<ul>\r\n<li><strong>Le t-shirt</strong>&nbsp;: En aucun cas le t-shirt ne doit &ecirc;tre modifi&eacute;, les partenaires qui y figurent doivent rester visibles.</li>\r\n<li><strong>Assistance m&eacute;dicale</strong>&nbsp;: un dispositif de 1<sup>er</sup>&nbsp;secours sera install&eacute; sur le site de d&eacute;part et d\'arriv&eacute;e.</li>\r\n<li><strong>Temps maximum</strong>&nbsp;: les "signaleurs" seront actifs pendant 3 heures apr&egrave;s le d&eacute;part. Les participants encore sur le parcours apr&egrave;s ce laps de temps veilleront &agrave;&nbsp; respecter les r&egrave;gles du code de la route, la s&eacute;curit&eacute; n\'&eacute;tant plus assur&eacute;s sur les voies &agrave; nouveau ouvert &agrave; la circulation.</li>\r\n</ul>\r\n<p><strong>Article 6 : R&egrave;gles de s&eacute;curit&eacute;</strong></p>\r\n<ul>\r\n<li>S&eacute;curit&eacute; sur le parcours : les participant&middot;es s&rsquo;engagent &agrave; respecter le Code de la Route et les consignes que l&rsquo;organisateur donnera par l\'interm&eacute;diaire des "signaleurs".&nbsp; Les signaleurs &nbsp;veilleront au respect de ces r&egrave;gles, pour votre s&eacute;curit&eacute;.</li>\r\n<li>Les personnes qui n\'auront pas fini le parcours&nbsp;<strong>apr&egrave;s la cl&ocirc;ture,</strong>&nbsp;veilleront &agrave; rester vigilantes &agrave; la circulation, les voies n\'&eacute;tant plus s&eacute;curis&eacute;es par des signaleurs.</li>\r\n<li>Les bicyclettes (hors organisateurs), engins &agrave; roulettes et/ou motoris&eacute;s sont formellement interdits sur le parcours.</li>\r\n<li><strong>Les chiens,&nbsp;<u>tenus en laisse</u>, sont tol&eacute;r&eacute;s &agrave; condition de prendre le d&eacute;part avec un temps de retard, en toute fin de peloton.</strong></li>\r\n<li><strong>Les marcheurs/coureurs avec poussette doivent la priorit&eacute; aux autres participant&middot;es.</strong></li>\r\n</ul>\r\n<p>&nbsp;</p>\r\n<p>&nbsp;</p>\r\n<p><strong>Article 7 : Droit &agrave; l&rsquo;image</strong></p>\r\n<p>Par sa participation &agrave;&nbsp;<strong><em>"Forbach en rose"</em></strong>&nbsp;&nbsp;chaque participant&middot;e autorise express&eacute;ment les organisateurs &agrave; utiliser ou faire utiliser ou reproduire ou faire reproduire son nom, son image, sa voix et sa prestation sportive en vue de toute exploitation directe ou sous forme d&eacute;riv&eacute;e de l&rsquo;&eacute;preuve et ce, sur tout support, dans le monde entier, par tous les moyens connus ou inconnus &agrave; ce jour.</p>\r\n<p><strong>Article 8 : R&eacute;compenses</strong></p>\r\n<p><strong>T-shirt</strong></p>\r\n<p>Le T-Shirt est offert dans la limite du stock disponible et de l\'ordre d\'inscription. Il est &agrave; retirer au moment de l\'inscription ou avant le d&eacute;part sur pr&eacute;sentation d\'un justificatif d\'inscription ou d\'une pi&egrave;ce d\'identit&eacute;.</p>\r\n<p>Il sera possible d\'acheter un T-Shirt au prix d\'une inscription, pour les moins de 12 ans, toujours dans la limite du stock disponible. Il faudra le pr&eacute;ciser sur le bulletin d\'inscription ou, dans le cadre d\'une inscription en ligne cocher &nbsp;<strong>-12 ans souhaitant un t-shirt</strong>. Cette inscription est payante.</p>\r\n<p>&nbsp;Les dates et lieu de retrait seront pr&eacute;cis&eacute;s ult&eacute;rieurement, par voie de presse &nbsp;et sur les r&eacute;seaux.</p>\r\n<p>L\'inscription en ligne sera close 2 jours avant la manifestation, &agrave; 23h00.<br>&nbsp;Il sera encore possible de s\'inscrire sur place jusqu\'&agrave; 10h00, le jour de la manifestation</p>\r\n<p>&nbsp;</p>\r\n<p><strong>Article 9 : Acceptation expresse</strong></p>\r\n<p>Le fait de s&rsquo;inscrire &agrave; cette &eacute;preuve implique l&rsquo;acceptation pure et simple du pr&eacute;sent r&egrave;glement dans son int&eacute;gralit&eacute; y compris, au fur et &agrave; mesure de leur apparition, ses avenants &eacute;ventuels et ses additifs.<br>Toutes les difficult&eacute;s pratiques d&rsquo;interpr&eacute;tation ou d&rsquo;application du pr&eacute;sent r&egrave;glement seront tranch&eacute;es souverainement par l&rsquo;organisateur.</p>\r\n</div>\r\n</div>\r\n</div>\r\n</div>', 3, 'https://www.ligue-cancer.net', 1);

-- Listage de la structure de table ForbachEnRose. users
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','user','viewer','saisie') NOT NULL DEFAULT 'viewer',
  `organisation` varchar(120) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Listage des données de la table ForbachEnRose.users : ~2 rows (environ)
DELETE FROM `users`;
INSERT INTO `users` (`id`, `username`, `password_hash`, `role`, `organisation`, `created_at`) VALUES
	(1, 'admin', '$2y$10$HUHB4qePa1zZOLbNqTLrue.8tYOZl1tIXVAfETNctrtMUDl2h.Ybq', 'admin', NULL, '2025-07-05 13:13:16'),
	(9, 'tati', '$2y$10$Z3ofKz3hLWy0qzmZYHvpbO8Rv9GaErx6Qt0e0jTCDY38TtYSkwFNC', 'admin', 'USFathlé', '2025-07-07 21:18:19');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
