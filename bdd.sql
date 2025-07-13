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
  `album_img` varchar(255) DEFAULT NULL,
  `album_desc` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `year_id` (`year_id`),
  CONSTRAINT `photo_albums_ibfk_1` FOREIGN KEY (`year_id`) REFERENCES `photo_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Listage des données de la table ForbachEnRose.photo_albums : ~1 rows (environ)
DELETE FROM `photo_albums`;
INSERT INTO `photo_albums` (`id`, `year_id`, `album_title`, `album_link`, `album_img`, `album_desc`) VALUES
	(17, 7, 'Forbach en rose 2025', 'https://www.flickr.com/photos/199455542@N02/albums/72177720327352730', 'image_3261086_20250707_ob_96d37b_515923325-1277800833912878-59488173761.jpg', 'Photo de tatiana');

-- Listage de la structure de table ForbachEnRose. photo_years
DROP TABLE IF EXISTS `photo_years`;
CREATE TABLE IF NOT EXISTS `photo_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `img` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Listage des données de la table ForbachEnRose.photo_years : ~2 rows (environ)
DELETE FROM `photo_years`;
INSERT INTO `photo_years` (`id`, `year`, `title`, `img`) VALUES
	(6, 20268, 'dsdsd', 'ob_75a84d_img-0002.jpg'),
	(7, 2025, 'photos 2025', 'image_3261086_20250707_ob_96d37b_515923325-1277800833912878-59488173761.jpg');

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

-- Listage des données de la table ForbachEnRose.registrations : ~5 rows (environ)
DELETE FROM `registrations`;
INSERT INTO `registrations` (`id`, `inscription_no`, `nom`, `prenom`, `tel`, `email`, `naissance`, `sexe`, `tshirt_size`, `ville`, `entreprise`, `origine`, `paiement_mode`, `created_at`, `created_by`) VALUES
	(39, 23, 'deded', 'dedede', '', '', NULL, 'H', '-', '', 'JCD', 'Admin', 'espece', '2025-07-08 13:57:14', 1),
	(40, 24, 'ded', 'ededed', '', '', NULL, 'H', '-', '', '', 'en ligne', 'en ligne (CB)', '2025-07-08 14:13:42', 1),
	(41, 25, 'azdexezd', 'edaze', 'ezdd', '', NULL, 'H', '-', 'deded', 'deeded', 'Cacopardo', 'espece', '2025-07-08 14:16:09', 12),
	(42, 26, 'ded', 'dede', '', '', NULL, 'H', '-', '', '', 'Cacopardo', 'espece', '2025-07-08 16:12:36', 12),
	(43, 27, 'de', 'de', '', '', NULL, 'H', '-', '', '', 'Cacopardo', 'espece', '2025-07-08 16:12:50', 12);

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
  `div_reglementation` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Listage des données de la table ForbachEnRose.setting : ~1 rows (environ)
DELETE FROM `setting`;
INSERT INTO `setting` (`id`, `assoconnect_js`, `assoconnect_iframe`, `title`, `title_color`, `picture`, `footer`, `registration_fee`, `titleAccueil`, `edition`, `link_facebook`, `link_instagram`, `accueil_active`, `date_course`, `picture_accueil`, `picture_partner`, `picture_gradient`, `titleParcours`, `parcoursDesc`, `picture_parcours`, `div_reglementation`) VALUES
	(1, '<script src="https://us-forbach-athletisme-61094f42af9e6.assoconnect.com/public/build/js/iframe.js" defer></script>', '<div class="iframe-asc-container" data-type="collect" data-collect-id="01JRG20SZVPRBQHA6XTSHP03JV"></div>', 'Forbach en Rose (Site de test)', '#ffffff', 'img_6873979615d018.75606952.jpg', '© 2025 Forbach en Rose', 12, 'Forbach en Rose (Site de test)', 'Édition 2026', 'https://www.google.fr', 'https://www.google.fr', 0, '2026-07-04 22:00:00', 'img_6873979ef27ef6.61742701.jpg', 'img_6873979ef35a25.30199021.png', 'img_687397ae324891.78948445.jpg', 'Parcours', 'Départ au parking de la piscine, un passage dans la forêt tout proche, une montée au Parc du Schlossberg et retour à la piscine en passant par le centre-ville de Forbach. Des animations musicales seront proposées sur le parcours.', 'img_687397ae326c77.50376868.jpg', '<div class="assoconnect-widget">\r\n<div class="Post-body">\r\n<div class="ob-sections">\r\n<div class="ob-section ob-section-html ">\r\n<p>R&egrave;glement de <strong><em>"<span style="color: rgb(255, 0, 255);">Forbach en rose</span>"&nbsp;</em></strong>dans et autour de la Ville de Forbach</p>\r\n<p><strong>Article 1 : organisateur</strong></p>\r\n<p>Cette &eacute;dition de&nbsp;<strong><em>"Forbach en rose"</em></strong>,&nbsp;est organis&eacute;e par l\'US Forbach Athl&eacute;tisme, en collaboration avec la Ligue Contre le Cancer. En tant qu&rsquo;organisateur, l\'US Forbach Athl&eacute;tisme et ses pr&eacute;pos&eacute;s sont couverts par une assurance Responsabilit&eacute; Civile souscrite aupr&egrave;s de&nbsp;<strong>Mutuelle Assurance MMA</strong></p>\r\n<p>Cette &eacute;dition se d&eacute;roulera le dimanche&nbsp;<strong>6 juillet 2025</strong>.<br>D&eacute;part :&nbsp;<strong>10h30</strong><br>Cl&ocirc;ture :&nbsp;<strong>13h30</strong></p>\r\n<p><br>Les personnes mandat&eacute;es pour l&rsquo;organisation de cette manifestation sont :</p>\r\n<ul>\r\n<li><span lang="EN-GB">Pina Cesarec (LCC)</span></li>\r\n<li><span lang="EN-GB">Thierry Fricker (USF)</span></li>\r\n</ul>\r\n<p>D&eacute;part et arriv&eacute;e : Site de la Piscine, rue F&eacute;lix Barth &agrave; Forbach.</p>\r\n<p>&nbsp;</p>\r\n<p><strong>Article 2 : &Eacute;preuves, distances, tarifs, &acirc;ges</strong></p>\r\n<p>Boucle de 7km &agrave; parcourir en marchant ou en courant.<br>L&rsquo;accord d&rsquo;un des parents ou du repr&eacute;sentant l&eacute;gal est obligatoire pour les participant&middot;es mineur&middot;es.</p>\r\n<p><u>Tarif unique</u>&nbsp;: 12&euro;</p>\r\n<p>L\'inscription n\'est pas obligatoire et sera gratuite pour les moins de 12 ans (n&eacute;&middot;e apr&egrave;s le 7 juillet&nbsp;<strong>2012</strong>). Pour obtenir un t-shirt il faudra n&eacute;anmoins s\'acquitter de la somme de 12&euro;</p>\r\n<p><a href="http://lamessine.eu/index.php/parcours/">Parcours</a>&nbsp;: cf plan en annexe.</p>\r\n<p>&nbsp;</p>\r\n<p><strong>Article 2bis :</strong></p>\r\n<p>L\'ensemble des b&eacute;n&eacute;fices sera attribu&eacute; &agrave; la lutte contre le cancer et tout particuli&egrave;rement &agrave; la Ligue contre le cancer, &agrave; l\'issue de l\'exercice 2025.<br>Les organisateurs se r&eacute;servent cependant le droit de provisionner une somme plafonn&eacute;e &agrave; un maximum de 20% du montant total des gains, pour se garantir contre d\'&eacute;ventuels frais additionnels, et anticiper sur les d&eacute;penses de l\'&eacute;dition future. Cette somme ne pourra cependant pas faire l\'objet d\'une attribution autre que Forbach en Rose.</p>\r\n<p><strong>Article 3 : Modalit&eacute;s d&rsquo;inscription</strong></p>\r\n<p><strong>Certificat m&eacute;dical (Article L. 231-3 du Code du Sport)</strong></p>\r\n<p><strong><em>"Forbach en rose"</em></strong>&nbsp;n\'est pas une comp&eacute;tition et&nbsp;<strong>un</strong>&nbsp;<strong>certificat m&eacute;dical ne sera pas exig&eacute;</strong>.<br>Les inscrit.es souhaitant courir ou marcher le feront sous leur propre responsabilit&eacute;.</p>\r\n<p><strong>Assurances</strong></p>\r\n<p>Les licenci&eacute;&middot;es b&eacute;n&eacute;ficient des garanties li&eacute;es &agrave; leur licence. Il incombe aux engag&eacute;&middot;es non licenci&eacute;&middot;es de s&rsquo;assurer personnellement.<br>Il est express&eacute;ment indiqu&eacute; que les engag&eacute;&middot;es participent sous leur propre et exclusive responsabilit&eacute;.</p>\r\n<p><strong>Article 4 : Engagement</strong></p>\r\n<p>Tout engagement est personnel, ferme et d&eacute;finitif.<br>Il ne peut faire l&rsquo;objet d\'un remboursement pour quelque motif que ce soit, m&ecirc;me en cas d&rsquo;annulation de l&rsquo;&eacute;preuve.</p>\r\n<p><strong>Article 5 : Modalit&eacute;s d&rsquo;organisation des &eacute;preuves</strong></p>\r\n<ul>\r\n<li><strong>Le t-shirt</strong>&nbsp;: En aucun cas le t-shirt ne doit &ecirc;tre modifi&eacute;, les partenaires qui y figurent doivent rester visibles.</li>\r\n<li><strong>Assistance m&eacute;dicale</strong>&nbsp;: un dispositif de 1<sup>er</sup>&nbsp;secours sera install&eacute; sur le site de d&eacute;part et d\'arriv&eacute;e.</li>\r\n<li><strong>Temps maximum</strong>&nbsp;: les "signaleurs" seront actifs pendant 3 heures apr&egrave;s le d&eacute;part. Les participants encore sur le parcours apr&egrave;s ce laps de temps veilleront &agrave;&nbsp; respecter les r&egrave;gles du code de la route, la s&eacute;curit&eacute; n\'&eacute;tant plus assur&eacute;s sur les voies &agrave; nouveau ouvert &agrave; la circulation.</li>\r\n</ul>\r\n<p><strong>Article 6 : R&egrave;gles de s&eacute;curit&eacute;</strong></p>\r\n<ul>\r\n<li>S&eacute;curit&eacute; sur le parcours : les participant&middot;es s&rsquo;engagent &agrave; respecter le Code de la Route et les consignes que l&rsquo;organisateur donnera par l\'interm&eacute;diaire des "signaleurs".&nbsp; Les signaleurs &nbsp;veilleront au respect de ces r&egrave;gles, pour votre s&eacute;curit&eacute;.</li>\r\n<li>Les personnes qui n\'auront pas fini le parcours&nbsp;<strong>apr&egrave;s la cl&ocirc;ture,</strong>&nbsp;veilleront &agrave; rester vigilantes &agrave; la circulation, les voies n\'&eacute;tant plus s&eacute;curis&eacute;es par des signaleurs.</li>\r\n<li>Les bicyclettes (hors organisateurs), engins &agrave; roulettes et/ou motoris&eacute;s sont formellement interdits sur le parcours.</li>\r\n<li><strong>Les chiens,&nbsp;<u>tenus en laisse</u>, sont tol&eacute;r&eacute;s &agrave; condition de prendre le d&eacute;part avec un temps de retard, en toute fin de peloton.</strong></li>\r\n<li><strong>Les marcheurs/coureurs avec poussette doivent la priorit&eacute; aux autres participant&middot;es.</strong></li>\r\n</ul>\r\n<p>&nbsp;</p>\r\n<p>&nbsp;</p>\r\n<p><strong>Article 7 : Droit &agrave; l&rsquo;image</strong></p>\r\n<p>Par sa participation &agrave;&nbsp;<strong><em>"Forbach en rose"</em></strong>&nbsp;&nbsp;chaque participant&middot;e autorise express&eacute;ment les organisateurs &agrave; utiliser ou faire utiliser ou reproduire ou faire reproduire son nom, son image, sa voix et sa prestation sportive en vue de toute exploitation directe ou sous forme d&eacute;riv&eacute;e de l&rsquo;&eacute;preuve et ce, sur tout support, dans le monde entier, par tous les moyens connus ou inconnus &agrave; ce jour.</p>\r\n<p><strong>Article 8 : R&eacute;compenses</strong></p>\r\n<p><strong>T-shirt</strong></p>\r\n<p>Le T-Shirt est offert dans la limite du stock disponible et de l\'ordre d\'inscription. Il est &agrave; retirer au moment de l\'inscription ou avant le d&eacute;part sur pr&eacute;sentation d\'un justificatif d\'inscription ou d\'une pi&egrave;ce d\'identit&eacute;.</p>\r\n<p>Il sera possible d\'acheter un T-Shirt au prix d\'une inscription, pour les moins de 12 ans, toujours dans la limite du stock disponible. Il faudra le pr&eacute;ciser sur le bulletin d\'inscription ou, dans le cadre d\'une inscription en ligne cocher &nbsp;<strong>-12 ans souhaitant un t-shirt</strong>. Cette inscription est payante.</p>\r\n<p>&nbsp;Les dates et lieu de retrait seront pr&eacute;cis&eacute;s ult&eacute;rieurement, par voie de presse &nbsp;et sur les r&eacute;seaux.</p>\r\n<p>L\'inscription en ligne sera close 2 jours avant la manifestation, &agrave; 23h00.<br>&nbsp;Il sera encore possible de s\'inscrire sur place jusqu\'&agrave; 10h00, le jour de la manifestation</p>\r\n<p>&nbsp;</p>\r\n<p><strong>Article 9 : Acceptation expresse</strong></p>\r\n<p>Le fait de s&rsquo;inscrire &agrave; cette &eacute;preuve implique l&rsquo;acceptation pure et simple du pr&eacute;sent r&egrave;glement dans son int&eacute;gralit&eacute; y compris, au fur et &agrave; mesure de leur apparition, ses avenants &eacute;ventuels et ses additifs.<br>Toutes les difficult&eacute;s pratiques d&rsquo;interpr&eacute;tation ou d&rsquo;application du pr&eacute;sent r&egrave;glement seront tranch&eacute;es souverainement par l&rsquo;organisateur.</p>\r\n</div>\r\n</div>\r\n</div>\r\n</div>');

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

-- Listage des données de la table ForbachEnRose.users : ~4 rows (environ)
DELETE FROM `users`;
INSERT INTO `users` (`id`, `username`, `password_hash`, `role`, `organisation`, `created_at`) VALUES
	(1, 'admin', '$2y$10$HUHB4qePa1zZOLbNqTLrue.8tYOZl1tIXVAfETNctrtMUDl2h.Ybq', 'admin', NULL, '2025-07-05 13:13:16'),
	(9, 'tati', '$2y$10$hcxk6yWjwub49wxmn66gf.5SZzaVpimU/PoPY1jgyc.Y/dMjcZpPa', 'user', 'USFathlé', '2025-07-07 21:18:19'),
	(11, 'OfficeDuTourisme', '$2y$10$mCZJ2tyaam70xKdm1aFiiufLC0mm2tIiMBTBRYGq5YXtNXcEpxEpK', 'saisie', 'Office du tourisme', '2025-07-08 13:28:54'),
	(12, 'Cacopardo', '$2y$10$lqODJHdwfAZXQtiU.5ZNHuiDNVE.l.AM421rcRkHE9LW8X431E5s.', 'saisie', 'Cacopardo', '2025-07-08 13:29:29');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
