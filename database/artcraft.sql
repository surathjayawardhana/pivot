-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Nov 09, 2026 at 09:03 AM
-- Server version: 8.4.7
-- PHP Version: 8.3.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `artcraft`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` int UNSIGNED DEFAULT NULL,
  `actor_username_snapshot` varchar(254) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'System',
  `actor_role_snapshot` enum('student','admin') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` bigint UNSIGNED DEFAULT NULL,
  `entity_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `user_agent` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_logs_created` (`created_at`,`id`),
  KEY `idx_activity_logs_actor` (`actor_user_id`,`created_at`),
  KEY `idx_activity_logs_entity` (`entity_type`,`entity_id`,`created_at`),
  KEY `idx_activity_logs_action` (`action`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `actor_user_id`, `actor_username_snapshot`, `actor_role_snapshot`, `action`, `entity_type`, `entity_id`, `entity_label`, `description`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 2, 'amara.b', 'admin', 'artwork.rejected', 'artwork', 11, 'If you want love..(NF)', 'Rejected artwork #11 “If you want love..(NF)” by amara.b (was approved).', '{\"status\": \"approved\", \"featured_slot\": null}', '{\"status\": \"rejected\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 14:50:14'),
(2, 2, 'amara.b', 'admin', 'artwork.approved', 'artwork', 11, 'If you want love..(NF)', 'Approved artwork #11 “If you want love..(NF)” by amara.b (was rejected).', '{\"status\": \"rejected\", \"featured_slot\": null}', '{\"status\": \"approved\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 14:50:22'),
(3, 2, 'amara.b', 'admin', 'artwork.rejected', 'artwork', 4, 'Life Is Strange', 'Rejected artwork #4 “Life Is Strange” by amara.b (was approved).', '{\"status\": \"approved\", \"featured_slot\": null}', '{\"status\": \"rejected\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 14:57:15'),
(4, 2, 'amara.b', 'admin', 'artwork.approved', 'artwork', 4, 'Life Is Strange', 'Approved artwork #4 “Life Is Strange” by amara.b (was rejected).', '{\"status\": \"rejected\", \"featured_slot\": null}', '{\"status\": \"approved\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 14:57:19'),
(5, 2, 'amara.b', 'admin', 'user.role_changed', 'user', 1, 'jlasjayawardhana', 'Role of jlasjayawardhana changed from student to admin.', '{\"role\": \"student\", \"account_status\": \"active\"}', '{\"role\": \"admin\", \"account_status\": \"active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 15:09:41'),
(6, 2, 'amara.b', 'admin', 'user.role_changed', 'user', 1, 'jlasjayawardhana', 'Role of jlasjayawardhana changed from admin to student.', '{\"role\": \"admin\", \"account_status\": \"active\"}', '{\"role\": \"student\", \"account_status\": \"active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 15:10:31'),
(7, 2, 'amara.b', 'admin', 'artwork.approved', 'artwork', 16, 'Rainy Day Romance 🌧️☕', 'Approved artwork #16 “Rainy Day Romance 🌧️☕” by misrisma (was pending).', '{\"status\": \"pending\", \"featured_slot\": null}', '{\"status\": \"approved\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 15:27:13'),
(8, 2, 'amara.b', 'admin', 'artwork.approved', 'artwork', 17, '🎧 One Song, Two Hearts 🤍', 'Approved artwork #17 “🎧 One Song, Two Hearts 🤍” by misrisma (was pending).', '{\"status\": \"pending\", \"featured_slot\": null}', '{\"status\": \"approved\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 15:30:43'),
(9, 2, 'amara.b', 'admin', 'artwork.approved', 'artwork', 18, '🌿 A Love Story Among the Trees 🤍', 'Approved artwork #18 “🌿 A Love Story Among the Trees 🤍” by misrisma (was pending).', '{\"status\": \"pending\", \"featured_slot\": null}', '{\"status\": \"approved\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 15:32:14'),
(10, 2, 'amara.b', 'admin', 'artwork.approved', 'artwork', 19, '🌃 Midnight Glow – A Night of Her Own 🧡', 'Approved artwork #19 “🌃 Midnight Glow – A Night of Her Own 🧡” by misrisma (was pending).', '{\"status\": \"pending\", \"featured_slot\": null}', '{\"status\": \"approved\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 15:34:00'),
(11, 2, 'amara.b', 'admin', 'artwork.approved', 'artwork', 20, '🌅 Golden Hour Dreams ✨', 'Approved artwork #20 “🌅 Golden Hour Dreams ✨” by misrisma (was pending).', '{\"status\": \"pending\", \"featured_slot\": null}', '{\"status\": \"approved\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36', '2026-11-05 15:37:07'),
(12, 2, 'amara.b', 'admin', 'artwork.approved', 'artwork', 21, '🌿 A Secret Garden – Golden Light ✨', 'Approved artwork #21 “🌿 A Secret Garden – Golden Light ✨” by misrisma (was pending).', '{\"status\": \"pending\", \"featured_slot\": null}', '{\"status\": \"approved\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36', '2026-11-05 15:45:05'),
(13, 2, 'amara.b', 'admin', 'user.role_changed', 'user', 8, 'hakdulanjali', 'Role of hakdulanjali changed from student to admin.', '{\"role\": \"student\", \"account_status\": \"active\"}', '{\"role\": \"admin\", \"account_status\": \"active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 16:00:53'),
(14, 2, 'amara.b', 'admin', 'artwork.approved', 'artwork', 22, 'Crimson Threshold', 'Approved artwork #22 “Crimson Threshold” by misrisma (was pending).', '{\"status\": \"pending\", \"featured_slot\": null}', '{\"status\": \"approved\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36', '2026-11-05 16:23:35'),
(15, 2, 'amara.b', 'admin', 'artwork.approved', 'artwork', 23, '❤️ Captive Heart 🪢', 'Approved artwork #23 “❤️ Captive Heart 🪢” by misrisma (was pending).', '{\"status\": \"pending\", \"featured_slot\": null}', '{\"status\": \"approved\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36', '2026-11-05 16:23:38'),
(16, 2, 'amara.b', 'admin', 'artwork.approved', 'artwork', 24, '💙 Luminous Embrace ✨', 'Approved artwork #24 “💙 Luminous Embrace ✨” by misrisma (was pending).', '{\"status\": \"pending\", \"featured_slot\": null}', '{\"status\": \"approved\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36', '2026-11-05 16:23:40'),
(17, 2, 'amara.b', 'admin', 'artwork.approved', 'artwork', 25, '🕊️ Ascension from the Abyss 🪽', 'Approved artwork #25 “🕊️ Ascension from the Abyss 🪽” by misrisma (was pending).', '{\"status\": \"pending\", \"featured_slot\": null}', '{\"status\": \"approved\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36', '2026-11-05 16:23:42'),
(18, 2, 'amara.b', 'admin', 'user.role_changed', 'user', 9, 'waambandusena', 'Role of waambandusena changed from student to admin.', '{\"role\": \"student\", \"account_status\": \"active\"}', '{\"role\": \"admin\", \"account_status\": \"active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 16:43:33'),
(19, 2, 'amara.b', 'admin', 'artwork.approved', 'artwork', 26, 'The Universe Doesn\'t Know My Name', 'Approved artwork #26 “The Universe Doesn\'t Know My Name” by jlasjayawardhana (was pending).', '{\"status\": \"pending\", \"featured_slot\": null}', '{\"status\": \"approved\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 19:19:32'),
(20, 2, 'amara.b', 'admin', 'user.role_changed', 'user', 7, 'aahmadhikari', 'Role of aahmadhikari changed from student to admin.', '{\"role\": \"student\", \"account_status\": \"active\"}', '{\"role\": \"admin\", \"account_status\": \"active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 19:24:18'),
(22, 2, 'amara.b', 'admin', 'artwork.approved', 'artwork', 27, 'Late Night Melancholy / Mania | 111025.0628', 'Approved artwork #27 “Late Night Melancholy / Mania | 111025.0628” by amara.b (was pending).', '{\"status\": \"pending\", \"featured_slot\": null}', '{\"status\": \"approved\", \"reviewed_by\": 2, \"featured_slot\": null}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-07 01:25:43'),
(23, 2, 'amara.b', 'admin', 'user.suspended', 'user', 4, 'hanman', 'Account of hanman changed from active to suspended.', '{\"role\": \"admin\", \"account_status\": \"active\"}', '{\"role\": \"admin\", \"account_status\": \"suspended\", \"sessions_revoked\": 0}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-07 15:52:46'),
(24, 2, 'amara.b', 'admin', 'user.reactivated', 'user', 4, 'hanman', 'Account of hanman changed from suspended to active.', '{\"role\": \"admin\", \"account_status\": \"suspended\"}', '{\"role\": \"admin\", \"account_status\": \"active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-07 15:55:01'),
(25, 2, 'amara.b', 'admin', 'user.suspended', 'user', 5, 'harry.p', 'Account of harry.p changed from active to suspended.', '{\"role\": \"student\", \"account_status\": \"active\"}', '{\"role\": \"student\", \"account_status\": \"suspended\", \"sessions_revoked\": 0}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-08 23:52:13'),
(26, 2, 'amara.b', 'admin', 'user.reactivated', 'user', 5, 'harry.p', 'Account of harry.p changed from suspended to active.', '{\"role\": \"student\", \"account_status\": \"suspended\"}', '{\"role\": \"student\", \"account_status\": \"active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-08 23:52:36');

-- --------------------------------------------------------

--
-- Table structure for table `artworks`
--

DROP TABLE IF EXISTS `artworks`;
CREATE TABLE IF NOT EXISTS `artworks` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `category_id` int UNSIGNED NOT NULL,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `admin_feedback` text COLLATE utf8mb4_unicode_ci,
  `reviewed_by` int UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `featured_slot` enum('1','2','3','4') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_artworks_featured_slot` (`featured_slot`),
  KEY `fk_artworks_reviewer` (`reviewed_by`),
  KEY `idx_artworks_status` (`status`),
  KEY `idx_artworks_user` (`user_id`),
  KEY `idx_artworks_category` (`category_id`),
  KEY `idx_artworks_created_at_id` (`created_at`,`id`),
  KEY `idx_artworks_user_status` (`user_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `artworks`
--

INSERT INTO `artworks` (`id`, `user_id`, `category_id`, `title`, `description`, `image_path`, `status`, `admin_feedback`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`, `featured_slot`) VALUES
(1, 1, 4, 'Friends', 'Friends cast from Friends Tv Series', 'uploads/artworks/the_uploaded_jlasjayawardhana_artwork_1.jpg', 'approved', NULL, NULL, NULL, '2026-11-01 10:26:06', '2026-11-05 04:57:11', NULL),
(2, 4, 8, 'Creep', '', 'uploads/artworks/the_uploaded_hanman_artwork_1.png', 'approved', NULL, NULL, NULL, '2026-11-01 11:10:34', '2026-11-05 04:57:24', NULL),
(3, 1, 4, 'Dead Memories', 'what love got to do with it when you don\'t love yourself', 'uploads/artworks/the_uploaded_jlasjayawardhana_artwork_2.jpg', 'approved', NULL, NULL, NULL, '2026-11-01 12:14:31', '2026-11-05 04:57:03', NULL),
(4, 2, 1, 'Life Is Strange', 'In Arcadia Bay, where memories stay, A butterfly dances, changing the day. Through broken hearts and endless rain, Two souls find love amid the pain.\r\n\r\nTime rewinds, but scars remain, Every choice brings joy or pain. Through storms and darkness, they fight, Searching for hope in fading light.\r\n\r\nLife is fragile, moments strange, Nothing lasts, everything will change. And when the final skies turn gray, We\'ll remember Arcadia Bay. 🦋', 'uploads/artworks/the_uploaded_amara_b_artwork_1.png', 'approved', NULL, 2, '2026-11-05 14:57:19', '2026-11-01 13:33:19', '2026-11-05 09:27:19', NULL),
(5, 5, 4, 'Ferret..!', '\"Draco Malfoy is an annoying little git with the personality of a spoiled kneazle. His hair probably gets more attention than his actual brain. Honestly, Ron, I\'ve met talking paintings with better manners. And don\'t even get me started on that stupid smirk. Someone really ought to teach the ferret some humility.\"', 'uploads/artworks/the_uploaded_harry_p_artwork_1.jpg', 'approved', NULL, NULL, NULL, '2026-11-01 18:07:07', '2026-11-01 18:10:21', NULL),
(7, 1, 3, 'K26-M.2', '', 'uploads/artworks/the_uploaded_jlasjayawardhana_artwork_3.jpg', 'approved', NULL, NULL, NULL, '2026-11-02 06:11:55', '2026-11-02 06:12:51', NULL),
(8, 1, 4, 'Living in slow motion', 'When world fades into silence.\r\nSomewhere between love and longing,\r\nyou become a melody I never want to end. 🖤', 'uploads/artworks/the_uploaded_jlasjayawardhana_artwork_4.jpg', 'approved', NULL, NULL, NULL, '2026-11-02 09:36:17', '2026-11-02 09:37:39', NULL),
(9, 2, 1, 'The Cruelty of Loving You', 'I held you close while the heavens turned grey,\r\nYou became a memory that refused to fade away.\r\nYour ghost still kisses the tears on my face,\r\nAnd I find cruel comfort in your empty embrace.\r\n\r\nLove made me yours, grief made you mine,\r\nNow I worship the wound you left behind.\r\nFor even in losing you, my heart stays true—\r\nHow beautiful it is to suffer for you. 🥀', 'uploads/artworks/the_uploaded_amara_b_artwork_2.png', 'approved', NULL, NULL, NULL, '2026-11-03 18:13:12', '2026-11-03 18:16:24', NULL),
(10, 1, 1, 'Trade my joy for my protection', 'Don\'t mistake my silence for pride,\r\nThere\'s a thousand fears I keep inside.\r\nYou offered roses; I offered rain,\r\nAfraid your love would become another pain.\r\n\r\nI turn away before you get too close,\r\nDestroy the very thing I need the most.\r\nPerhaps I\'m not afraid of loving you,\r\nI\'m terrified you\'ll leave me too.', 'uploads/artworks/the_uploaded_jlasjayawardhana_artwork_5.png', 'approved', NULL, NULL, NULL, '2026-11-03 18:30:13', '2026-11-03 18:30:36', NULL),
(11, 2, 4, 'If you want love..(NF)', 'If you want love, you gon\' have to go through the pain\r\nIf you want love, you gon\' have to learn how to change\r\nIf you want trust, you gon\' have to give some away\r\nIf you want love, if you want love...(NF)', 'uploads/artworks/the_uploaded_amara_b_artwork_3.jpg', 'approved', NULL, 2, '2026-11-05 14:50:22', '2026-11-03 18:44:35', '2026-11-05 09:20:22', NULL),
(12, 7, 1, 'A Queen\'s Choice', 'A Queen\'s Choice 👑\r\n\r\nShe bows not because her spirit bends,\r\nNor yields because her courage ends.\r\nHer gentleness is hers to give,\r\nHer silence, a choice by which she lives.\r\n\r\nBut mistake her grace for weakness,\r\nAnd awaken a strength beyond reckoning.\r\nFor when a queen must rise and reign,\r\nNo king shall ever outshine her flame.\r\n\r\nShe kneels by choice, she rules by right;\r\nA velvet soul, a will of iron. 🥀', 'uploads/artworks/the_uploaded_aahmadhikari_artwork_1.png', 'approved', NULL, NULL, NULL, '2026-11-04 12:02:36', '2026-11-04 12:05:42', NULL),
(13, 8, 8, 'calligraphy & Typography', 'A little reminder to keep dreaming, growing, and believing in yourself. ✨\r\n\r\nBeautiful words, delicate details, and a touch of creativity. Let your dreams bloom! 🌸\r\n\r\n#Calligraphy #TypographyArt #CreativeArt #ArtCraft #DreamBigger', 'uploads/artworks/the_uploaded_hakdulanjali_artwork_1.png', 'approved', NULL, NULL, NULL, '2026-11-04 12:24:24', '2026-11-04 12:25:08', NULL),
(14, 9, 2, 'Whispers of the Ocean', 'A captivating pencil illustration of a mermaid resting gracefully on a flowing rock, surrounded by the gentle beauty of her flowing hair and elegant, detailed fish tail. The intricate scales, delicate curves, and soft pencil shading bring this mythical sea creature to life.', 'uploads/artworks/the_uploaded_waambandusena_artwork_1.jpg', 'approved', NULL, NULL, NULL, '2026-11-04 14:14:43', '2026-11-04 14:15:22', NULL),
(15, 10, 8, 'All I Want Is to Be Loved', '# All I Want Is to Be Loved\r\n月亮知道 · The Moon Knows\r\n\r\nI don\'t wish for stars or a perfect life.\r\nI just want to be loved without asking twice.\r\n\r\nSomeone sincere, who shares their ordinary days, \r\ntheir morning smiles, little stories and silent worries.\r\n\r\nI don\'t need endless promises, \r\nonly honest words and a heart that chooses to stay.\r\n\r\nI want to be loved for who I am, \r\nwithout pretending to be someone else.\r\n\r\nJust once, I want a love that feels like home. ❤️', 'uploads/artworks/the_uploaded_misrisma_artwork_1.png', 'approved', NULL, NULL, NULL, '2026-11-05 07:27:25', '2026-11-05 08:30:45', '1'),
(16, 10, 4, 'Rainy Day Romance 🌧️☕', 'Rainy Day Romance 🌧️☕\r\n\r\nA cinematic moment of quiet romance, capturing two souls sharing an intimate conversation in a cozy café while rain paints the city outside. Moody tones, nostalgic atmosphere, soft reflections, and timeless emotions hidden within an ordinary moment. ❤️', 'uploads/artworks/the_uploaded_misrisma_artwork_2.jpg', 'approved', NULL, 2, '2026-11-05 15:27:13', '2026-11-05 09:56:44', '2026-11-05 09:57:13', NULL),
(17, 10, 4, '🎧 One Song, Two Hearts 🤍', '🎧 One Song, Two Hearts 🤍\r\nA beautiful black-and-white moment of two people sharing earphones and enjoying music together. Her joyful laughter and his shy smile create a sweet, playful atmosphere. The vintage music player and soft natural light add a nostalgic touch.\r\n\r\nSometimes, happiness is simply sharing your favourite song with your favourite person. ❤️', 'uploads/artworks/the_uploaded_misrisma_artwork_3.jpg', 'approved', NULL, 2, '2026-11-05 15:30:43', '2026-11-05 09:59:03', '2026-11-05 10:00:43', NULL),
(18, 10, 4, '🌿 A Love Story Among the Trees 🤍', '🌿 A Love Story Among the Trees 🤍\r\nA dreamy, romantic moment captured in the heart of a peaceful forest. Two people sit together on a fallen tree, surrounded by lush greenery and the gentle beauty of nature. Their simple white outfits create a beautiful contrast against the deep green woodland.\r\n\r\nThe soft lighting and quiet surroundings give the photograph a timeless, fairytale-like atmosphere.\r\n\r\n“Some connections feel like poetry, written quietly in the middle of nature.” 🍃', 'uploads/artworks/the_uploaded_misrisma_artwork_4.jpg', 'approved', NULL, 2, '2026-11-05 15:32:14', '2026-11-05 10:01:20', '2026-11-05 10:02:14', NULL),
(19, 10, 4, '🌃 Midnight Glow – A Night of Her Own 🧡', '🌃 Midnight Glow – A Night of Her Own 🧡\r\nA cinematic three-frame portrait capturing a young woman enjoying the beauty of the night. The warm golden streetlights, deep shadows, and blurred city lights create a dreamy, nostalgic atmosphere. Her denim jacket and effortless poses add a touch of vintage street-style aesthetic.\r\nThe photographs express confidence, freedom, individuality, and the quiet beauty of being lost in your own world.\r\n“Some nights are made for finding yourself beneath the city', 'uploads/artworks/the_uploaded_misrisma_artwork_5.jpg', 'approved', NULL, 2, '2026-11-05 15:34:00', '2026-11-05 10:02:14', '2026-11-05 10:04:00', NULL),
(20, 10, 4, '🌅 Golden Hour Dreams ✨', '🌅 Golden Hour Dreams ✨\r\nA woman in a flowing white dress waits at a peaceful bus stop as a bus passes by. Golden sunlight filters through the trees, creating a dreamy atmosphere filled with warmth, nostalgia, and quiet beauty.\r\n\r\n“Some moments are beautiful simply because they don\'t last forever.” 🍂', 'uploads/artworks/the_uploaded_misrisma_artwork_6.jpg', 'approved', NULL, 2, '2026-11-05 15:37:07', '2026-11-05 10:05:51', '2026-11-05 10:07:07', NULL),
(21, 10, 4, '🌿 A Secret Garden – Golden Light ✨', '🌿 A Secret Garden – Golden Light ✨\r\nA woman in a flowing white dress stands in a peaceful forest clearing, surrounded by towering trees and soft golden sunlight. The magical sunbeams, lush greenery, and dreamy atmosphere create a beautiful feeling of freedom, wonder, and tranquillity.\r\n\r\n“Where sunlight touches the earth, the soul finds its peace.” 🍃🤍', 'uploads/artworks/the_uploaded_misrisma_artwork_7.jpg', 'approved', NULL, 2, '2026-11-05 15:45:05', '2026-11-05 10:10:38', '2026-11-05 10:15:05', NULL),
(22, 10, 2, 'Crimson Threshold', 'Crimson Threshold\r\n\r\nA gothic pen-and-ink illustration of two figures nearly kissing across a blood-red waterline. A pale woman with long black hair leans in, eyes closed, while a short-haired figure tilts back, face half-submerged in shadow. One delicate hand with dark-painted nails rises between them. The stark white skin against deep black creates an intimate, haunting mood of desire, surrender, and quiet dread.', 'uploads/artworks/the_uploaded_misrisma_artwork_8.jpg', 'approved', NULL, 2, '2026-11-05 16:23:35', '2026-11-05 10:42:37', '2026-11-05 10:53:35', NULL),
(23, 10, 2, '❤️ Captive Heart 🪢', '❤️ Captive Heart 🪢\r\n\r\nA stylized anime-inspired illustration against a deep crimson backdrop. 🎨 A dark-haired young man sits on a wooden chair, bound with rope and gagged with a cloth, gazing sideways with wide eyes. 😳 Beside him, a long-haired girl in a red blouse rests her chin on her hands, smiling dreamily as small hearts float above her. 💕 The playful, darkly comedic tone twists obsession into romance, with the handwritten caption adding a teasing Hindi-Urdu line. 🌹😏', 'uploads/artworks/the_uploaded_misrisma_artwork_9.jpg', 'approved', NULL, 2, '2026-11-05 16:23:38', '2026-11-05 10:44:23', '2026-11-05 10:53:38', NULL),
(24, 10, 2, '💙 Luminous Embrace ✨', '💙 Luminous Embrace ✨\r\n\r\nA moody digital painting of two figures locked in a tender embrace against a deep teal-blue backdrop. 🌌 A dark-coated silhouette holds a glowing, translucent figure whose body shimmers like ice or spectral light, her eyes closed as she rests her head on his shoulder. 🧊 Soft brushstrokes and radiant cyan highlights create a haunting, dreamlike feeling of comfort, grief, and fragile love. 🌊🤍', 'uploads/artworks/the_uploaded_misrisma_artwork_10.jpg', 'approved', NULL, 2, '2026-11-05 16:23:40', '2026-11-05 10:45:06', '2026-11-05 10:53:40', NULL),
(25, 10, 6, '🕊️ Ascension from the Abyss 🪽', '🕊️ Ascension from the Abyss 🪽\r\n\r\nA haunting stone sculpture of a winged woman rising skyward, her face tilted upward and arms hanging loose as if surrendering to the heavens. 🌑 Her draped robes cling to a weathered, cracked body, and her feathered wings spread wide against a fractured wall. 🪨 At her feet, anguished figures claw and writhe within rough, rocky ground. The muted sepia tones evoke redemption, sorrow, and the struggle between earth and transcendence. ✨', 'uploads/artworks/the_uploaded_misrisma_artwork_11.jpg', 'approved', NULL, 2, '2026-11-05 16:23:42', '2026-11-05 10:46:29', '2026-11-05 10:53:42', NULL),
(26, 1, 5, 'The Universe Doesn\'t Know My Name', 'An elegy for a meaningless existence.\r\n\r\nThe stars burn on without a care,\r\nIndifferent to the lives beneath them.\r\nGalaxies collide in silence,\r\nAnd no one writes their names in stone.\r\n\r\nI searched for meaning in the heavens,\r\nAsked the moon why I was here.\r\nIt offered nothing but its silence,\r\nA pale light drowning in the atmosphere.\r\n\r\nPerhaps there is no grand design,\r\nNo hidden hand, no sacred plan.\r\nPerhaps we\'re just a brief collision\r\nOf dust, of time, of flesh, of chance.\r\n\r\nWe build our kingdoms out of sand,\r\nWrite our histories in fading ink.\r\nWe call it purpose, call it destiny,\r\nWhile eternity forgets to blink.\r\n\r\nAnd what of love? And what of pain?\r\nThe promises we swear to keep?\r\nDo they dissolve into nothingness\r\nWhen the stars go dark and fall asleep?\r\n\r\nPerhaps they do. Perhaps they must.\r\nPerhaps the end is all the same.\r\nYet here I am, beneath the void,\r\nStill searching for a reason to remain.\r\n\r\nFor if the universe is silent,\r\nAnd meaning is a thing we make,\r\nThen let me choose what matters to me,\r\nThough every choice may end in dust.\r\n\r\nNo heaven waits. No answers call.\r\nNo cosmic purpose crowns us all.\r\nAnd still, beneath this endless sky,\r\nI live, I love, I question why.\r\n\r\nPerhaps meaning was never waiting to be found. Perhaps it was ours to invent.', 'uploads/artworks/the_uploaded_jlasjayawardhana_artwork_6.png', 'approved', NULL, 2, '2026-11-05 19:19:32', '2026-11-05 13:49:18', '2026-11-05 13:49:32', NULL),
(27, 2, 5, 'Late Night Melancholy / Mania | 1110250628AM', '(Late Night Melancholy by Rude Boy)\r\n\r\nWhen I listen to this music, \r\nI feel like I am bleeding, \r\nslowly losing my consciousness, \r\nyet somehow, there\'s no pain.\r\n\r\nNo pain at all\r\n\r\nBlue and dark collide and mix; \r\neverything turns purple. \r\nFinally, I am free; \r\nmy pain leaves me with this flowing blood. \r\nI may not live anymore, yet somehow, \r\nI\'m so happy...', 'uploads/artworks/the_uploaded_amara_b_artwork_4.jpg', 'approved', NULL, 2, '2026-11-07 01:25:43', '2026-11-06 19:55:20', '2026-11-07 10:20:58', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `artwork_comments`
--

DROP TABLE IF EXISTS `artwork_comments`;
CREATE TABLE IF NOT EXISTS `artwork_comments` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `artwork_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `parent_comment_id` bigint UNSIGNED DEFAULT NULL,
  `body` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by_user_id` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_artwork_comments_artwork_parent` (`artwork_id`,`parent_comment_id`),
  KEY `idx_artwork_comments_parent_created` (`parent_comment_id`,`created_at`),
  KEY `idx_artwork_comments_created` (`created_at`),
  KEY `idx_artwork_comments_user` (`user_id`,`created_at`),
  KEY `idx_artwork_comments_deleted` (`deleted_at`),
  KEY `idx_artwork_comments_deleted_by` (`deleted_by_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `artwork_comments`
--

INSERT INTO `artwork_comments` (`id`, `artwork_id`, `user_id`, `parent_comment_id`, `body`, `created_at`, `updated_at`, `deleted_at`, `deleted_by_user_id`) VALUES
(4, 26, 2, NULL, 'sbx', '2026-11-07 10:19:58', NULL, NULL, NULL),
(5, 27, 2, NULL, 'xsa', '2026-11-07 10:28:40', NULL, NULL, NULL),
(6, 12, 2, NULL, 'dgdg', '2026-11-08 18:50:16', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `artwork_likes`
--

DROP TABLE IF EXISTS `artwork_likes`;
CREATE TABLE IF NOT EXISTS `artwork_likes` (
  `artwork_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`artwork_id`,`user_id`),
  KEY `idx_artwork_likes_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `artwork_likes`
--

INSERT INTO `artwork_likes` (`artwork_id`, `user_id`, `created_at`) VALUES
(1, 1, '2026-11-04 10:29:58'),
(1, 2, '2026-11-04 10:35:54'),
(4, 2, '2026-11-04 10:50:20'),
(8, 1, '2026-11-04 15:23:19'),
(8, 2, '2026-11-05 06:56:57'),
(9, 4, '2026-11-04 14:09:28'),
(10, 1, '2026-11-04 18:29:25'),
(10, 5, '2026-11-04 11:11:07'),
(10, 7, '2026-11-04 11:58:23'),
(11, 1, '2026-11-04 10:28:23'),
(11, 2, '2026-11-04 10:51:23'),
(11, 5, '2026-11-04 10:57:32'),
(11, 7, '2026-11-05 12:44:13'),
(12, 4, '2026-11-04 12:07:52'),
(12, 7, '2026-11-05 12:44:08'),
(13, 1, '2026-11-04 18:29:23'),
(13, 4, '2026-11-04 12:26:08'),
(13, 7, '2026-11-05 12:44:07'),
(13, 8, '2026-11-04 12:25:42'),
(14, 1, '2026-11-04 14:15:54'),
(14, 4, '2026-11-04 14:16:39'),
(15, 2, '2026-11-05 08:31:01'),
(15, 7, '2026-11-05 12:44:04'),
(16, 2, '2026-11-05 09:58:18'),
(17, 2, '2026-11-05 10:45:59'),
(17, 7, '2026-11-05 12:44:00'),
(18, 7, '2026-11-05 12:43:58'),
(21, 2, '2026-11-05 10:45:51'),
(26, 1, '2026-11-05 14:08:03'),
(26, 2, '2026-11-05 14:21:16'),
(26, 4, '2026-11-05 14:57:43'),
(27, 2, '2026-11-07 10:28:28');

-- --------------------------------------------------------

--
-- Table structure for table `artwork_review_history`
--

DROP TABLE IF EXISTS `artwork_review_history`;
CREATE TABLE IF NOT EXISTS `artwork_review_history` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `artwork_id` int UNSIGNED DEFAULT NULL,
  `artwork_title_snapshot` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `artist_user_id` int UNSIGNED DEFAULT NULL,
  `artist_username_snapshot` varchar(254) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reviewer_user_id` int UNSIGNED DEFAULT NULL,
  `reviewer_username_snapshot` varchar(254) COLLATE utf8mb4_unicode_ci NOT NULL,
  `previous_status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL,
  `new_status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL,
  `feedback` text COLLATE utf8mb4_unicode_ci,
  `reviewed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_review_history_artwork_time` (`artwork_id`,`reviewed_at`,`id`),
  KEY `idx_review_history_status_time` (`new_status`,`reviewed_at`),
  KEY `idx_review_history_reviewer_time` (`reviewer_user_id`,`reviewed_at`),
  KEY `idx_review_history_artist` (`artist_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `artwork_review_history`
--

INSERT INTO `artwork_review_history` (`id`, `artwork_id`, `artwork_title_snapshot`, `artist_user_id`, `artist_username_snapshot`, `reviewer_user_id`, `reviewer_username_snapshot`, `previous_status`, `new_status`, `feedback`, `reviewed_at`) VALUES
(1, 11, 'If you want love..(NF)', 2, 'amara.b', 2, 'amara.b', 'approved', 'rejected', NULL, '2026-11-05 14:50:14'),
(2, 11, 'If you want love..(NF)', 2, 'amara.b', 2, 'amara.b', 'rejected', 'approved', NULL, '2026-11-05 14:50:22'),
(3, 4, 'Life Is Strange', 2, 'amara.b', 2, 'amara.b', 'approved', 'rejected', NULL, '2026-11-05 14:57:15'),
(4, 4, 'Life Is Strange', 2, 'amara.b', 2, 'amara.b', 'rejected', 'approved', NULL, '2026-11-05 14:57:19'),
(5, 16, 'Rainy Day Romance 🌧️☕', 10, 'misrisma', 2, 'amara.b', 'pending', 'approved', NULL, '2026-11-05 15:27:13'),
(6, 17, '🎧 One Song, Two Hearts 🤍', 10, 'misrisma', 2, 'amara.b', 'pending', 'approved', NULL, '2026-11-05 15:30:43'),
(7, 18, '🌿 A Love Story Among the Trees 🤍', 10, 'misrisma', 2, 'amara.b', 'pending', 'approved', NULL, '2026-11-05 15:32:14'),
(8, 19, '🌃 Midnight Glow – A Night of Her Own 🧡', 10, 'misrisma', 2, 'amara.b', 'pending', 'approved', NULL, '2026-11-05 15:34:00'),
(9, 20, '🌅 Golden Hour Dreams ✨', 10, 'misrisma', 2, 'amara.b', 'pending', 'approved', NULL, '2026-11-05 15:37:07'),
(10, 21, '🌿 A Secret Garden – Golden Light ✨', 10, 'misrisma', 2, 'amara.b', 'pending', 'approved', NULL, '2026-11-05 15:45:05'),
(11, 22, 'Crimson Threshold', 10, 'misrisma', 2, 'amara.b', 'pending', 'approved', NULL, '2026-11-05 16:23:35'),
(12, 23, '❤️ Captive Heart 🪢', 10, 'misrisma', 2, 'amara.b', 'pending', 'approved', NULL, '2026-11-05 16:23:38'),
(13, 24, '💙 Luminous Embrace ✨', 10, 'misrisma', 2, 'amara.b', 'pending', 'approved', NULL, '2026-11-05 16:23:40'),
(14, 25, '🕊️ Ascension from the Abyss 🪽', 10, 'misrisma', 2, 'amara.b', 'pending', 'approved', NULL, '2026-11-05 16:23:42'),
(15, 26, 'The Universe Doesn\'t Know My Name', 1, 'jlasjayawardhana', 2, 'amara.b', 'pending', 'approved', NULL, '2026-11-05 19:19:32'),
(16, 27, 'Late Night Melancholy / Mania | 111025.0628', 2, 'amara.b', 2, 'amara.b', 'pending', 'approved', NULL, '2026-11-07 01:25:43');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
CREATE TABLE IF NOT EXISTS `categories` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `description`, `image_path`, `created_at`) VALUES
(1, 'Digital Art', 'Digitally created paintings, illustrations, and experimental artwork.', 'assets/images/Digital Art.jpg', '2026-10-31 10:07:22'),
(2, 'Drawing & Illustration', 'Pencil sketches, ink drawings, portraits, comics, and hand-drawn illustrations.', 'assets/images/Drawing & Illustration.jpg', '2026-10-31 10:07:22'),
(3, 'Painting', 'Watercolor, acrylic, oil, abstract, and other painted artwork.', 'assets/images/Painting.jpg', '2026-10-31 10:07:22'),
(4, 'Photography', 'Portraits, landscapes, street photography, architecture, and creative photography.', 'assets/images/Photography.jpg', '2026-10-31 10:07:22'),
(5, 'Poetry', 'Poems, verses, and spoken words that celebrate life, challenge convention, and give a voice to the thoughts left unspoken.', 'assets/images/poem.jpg', '2026-10-31 10:07:22'),
(6, '3D Art & Sculpture', '3D models, character renders, sculptures, and photographs of sculptural work.', 'assets/images/3D Art & Sculpture.jpg', '2026-10-31 10:07:22'),
(7, 'Crafts & Handmade', 'Paper crafts, pottery, handmade decorations, and creative physical objects.', 'assets/images/Crafts & Handmade.jpg', '2026-10-31 10:07:22'),
(8, 'Calligraphy & Typography', 'Decorative lettering, calligraphy, typographic compositions, and hand lettering.', 'assets/images/Calligraphy & Typography.jpg', '2026-10-31 10:07:22'),
(9, 'Fashion & Textile Design', 'Fashion illustrations, clothing concepts, fabric patterns, and textile artwork.', 'assets/images/Fashion & Textile Design.jpg', '2026-10-31 10:07:22'),
(10, 'Architecture & Interior Design', 'Building concepts, architectural sketches, interior layouts, and design visualizations.', 'assets/images/Architecture & Interior Design.jpg', '2026-10-31 10:07:22');

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

DROP TABLE IF EXISTS `events`;
CREATE TABLE IF NOT EXISTS `events` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_events_created_at` (`created_at`),
  KEY `idx_events_created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`id`, `event_name`, `description`, `image_path`, `created_by`, `created_at`) VALUES
(1, 'NARTCRAFT EXHIBITION !', 'A celebration of creativity and imagination!\r\n\r\nExplore a diverse collection of artworks created by talented NSBM students. From digital illustrations and photography to traditional drawings and paintings, discover different styles, ideas, and perspectives.\r\n\r\nCome experience the creativity of our student community and celebrate the art that brings us together.', 'uploads/events/event_bac4f7c611c8c827.webp', 2, '2026-11-02 10:42:14'),
(2, 'CREATIVE MINDS WORKSHOP', 'Unleash your creativity and turn your ideas into art!\r\n\r\nJoin fellow NSBM students for an interactive creative workshop where imagination meets experimentation. Explore different artistic techniques, discover new ways to express yourself, and learn from the creativity of others.\r\n\r\nWhether you\'re into digital art, illustration, painting, or design, this is an opportunity to learn, create, and connect with fellow artists.', 'uploads/events/event_bbc3e7310e487e0b.png', 2, '2026-11-02 10:56:58'),
(3, 'NARTCRAFT – Art Exhibition 2026', 'Step into a world of creativity, imagination, and artistic expression at **NARTCRAFT – Art Exhibition 2026**, hosted at NSBM Green University.\r\n\r\nDiscover a diverse collection of artworks created by talented artists, showcasing creativity through painting, digital art, photography, illustration, sculpture, and more.\r\n\r\nWhether you\'re an art enthusiast, an aspiring artist, or simply someone who appreciates creativity, this exhibition offers an opportunity to explore inspiring masterpieces, appreciate artistic talent, and connect with the creative community.\r\n\r\n**Come explore, appreciate, and be inspired!**', 'uploads/events/event_17d93fbc05a428c3.png', 2, '2026-11-02 16:37:01');

-- --------------------------------------------------------

--
-- Table structure for table `event_participants`
--

DROP TABLE IF EXISTS `event_participants`;
CREATE TABLE IF NOT EXISTS `event_participants` (
  `event_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `joined_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`,`user_id`),
  KEY `idx_event_participants_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `event_participants`
--

INSERT INTO `event_participants` (`event_id`, `user_id`, `joined_at`) VALUES
(1, 1, '2026-11-02 16:50:00'),
(1, 2, '2026-11-02 10:42:19'),
(1, 5, '2026-11-02 13:24:40'),
(1, 10, '2026-11-05 10:52:05'),
(2, 8, '2026-11-04 12:17:06'),
(2, 9, '2026-11-04 15:03:53'),
(2, 10, '2026-11-05 10:52:02'),
(3, 2, '2026-11-07 10:37:55'),
(3, 8, '2026-11-04 13:35:16'),
(3, 10, '2026-11-05 10:51:58');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `surname` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(254) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `username` varchar(254) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (substring_index(`email`,_utf8mb4'@',1)) STORED,
  `profile_image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('student','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'student',
  `email_verified_at` datetime DEFAULT NULL,
  `account_status` enum('active','suspended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `deleted_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `bio` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_role_status_deleted` (`role`,`account_status`,`deleted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `first_name`, `surname`, `email`, `password_hash`, `created_at`, `profile_image_path`, `role`, `email_verified_at`, `account_status`, `deleted_at`, `last_login_at`, `updated_at`, `bio`) VALUES
(1, 'Surath', 'Jayawardhana', 'jlasjayawardhana@student.nsbm.ac.lk', '$2y$10$y7YcnVtPn.nKyaehJn9/OekwM9jk7aC.gVsXBWLtpdAK2cop1g5FO', '2026-10-28 17:40:01', 'uploads/profiles/profile_1_fca95fd6c11a7194.jpg', 'student', NULL, 'active', NULL, NULL, '2026-11-05 09:40:31', 'We living in a world that, come with plan B'),
(2, 'amara', 'bandara', 'amara.b@l.nsbm.ac.lk', '$2y$10$FsMtHrZqkJyLTCIg6yfhOeyT/oLc8WfuAAQKwkHhecAeLKcmpujju', '2026-10-28 17:51:14', 'uploads/profiles/profile_2_0cc0e7987a88dad9.png', 'admin', NULL, 'active', NULL, NULL, '2026-11-01 13:34:28', NULL),
(4, 'han', 'man', 'hanman@student.nsbm.ac.lk', '$2y$10$bxkiiWaiJcgfjBqLF62xvOtRSlbIK56tMf0Y2s.OvP3ITu0TXcDNG', '2026-10-30 08:20:50', 'uploads/profiles/profile_4_9f764b5fcb244c41.png', 'admin', NULL, 'active', NULL, NULL, '2026-11-07 10:25:01', NULL),
(5, 'Harry', 'Potter', 'harry.p@l.nsbm.ac.lk', '$2y$10$EXVeynCITvR7GV9KYao77.Fpo4G6MtXZn8rlVwUv6e8toImIald8S', '2026-11-01 17:55:41', 'uploads/profiles/profile_5_56b774b34fc64e44.png', 'student', NULL, 'active', NULL, NULL, '2026-11-08 18:22:36', 'Expecto Patronum'),
(6, 'Kamal', 'Perera', 'kamal@perea.nsbm.ac.lk', '$2y$10$d2NV5zalqOZ48p6FXhGIgeNb89V2.nV9twIhEjdpSddRDd7jR2ZCq', '2026-11-02 08:53:58', 'uploads/profiles/profile_6_d0f2d544b6f9388c.jpg', 'student', NULL, 'active', NULL, NULL, '2026-11-05 14:52:45', NULL),
(7, 'Heshani', 'Maleesha', 'aahmadhikari@student.nsbm.ac.lk', '$2y$10$aj2Trvjxa5pxuHjI/kqOAuRtrHqjEIVJKoMT0CXxWQM7KL4hiNyE6', '2026-11-04 11:35:09', NULL, 'admin', NULL, 'active', NULL, NULL, '2026-11-05 13:54:18', NULL),
(8, 'kaveesha', 'dulanjali', 'hakdulanjali@students.nsbm.ac.lk', '$2y$10$iKB14e4VFgODRVZU.CX2ye2CfUNIn4O6Ehifq0fD04GWl0JMoObR.', '2026-11-04 12:11:32', 'uploads/profiles/profile_8_c80738103d220b5e.jpg', 'admin', NULL, 'active', NULL, NULL, '2026-11-05 10:30:53', 'A little space for creativity, beautiful lettering & artistic inspiration. 🌷 Turning words into art, one stroke at a time. ✨'),
(9, 'Asani', 'Bandusena', 'waambandusena@students.nsbm.ac.lk', '$2y$10$QATMLGHvytIL2xyBuOA5lumQvzPRtaWp3hliubLS2TepBoU9Ca4zG', '2026-11-04 13:37:04', 'uploads/profiles/profile_9_399b661412b0b06d.jpg', 'admin', NULL, 'active', NULL, NULL, '2026-11-05 11:13:33', 'A creative soul passionate about pencil art and photography. I love bringing imagination to life through sketches and capturing beautiful moments through my lens, and I always explore new ideas and find inspiration in the world around me.'),
(10, 'sameethu', 'risma', 'misrisma@students.nsbm.ac.lk', '$2y$10$TI5f/cQ5Z5N3IkKe0JpeYuvItN3dPPK4aZaYc0BEVz6iT65lJ9xvW', '2026-11-05 07:05:31', 'uploads/profiles/profile_10_01d00092bf6476ac.jpg', 'student', NULL, 'active', NULL, NULL, '2026-11-05 09:36:50', '🌙 The Unknown Soul🥀 A quiet soul with endless dreams ✨, a curious mind 🦋, and untold stories 📜. Lost between technology 💻, poetry 🖋️, ancient worlds 🏯, and distant skies ☁️. Still learning, still dreaming, still becoming... 🌌'),
(11, 'Senuthmi', 'Atigala', 'avsdatigala@students.nsbm.ac.lk', '$2y$10$LVMk9x6Jwg3EwS4kVRYlM.58tzF5EeNnqlxh/SLbNKBV0W58DEKD6', '2026-11-05 07:07:21', NULL, 'student', NULL, 'active', NULL, NULL, '2026-11-05 07:07:21', NULL),
(12, 'Nethumini', 'Hansamali', 'hlnhwijewantha@students.nsbm.ac.lk', '$2y$10$U2lQDNyirIFsgKVzg4ojyuPDuUAYCPlxYHKeP62Gyxn6sjLRea14C', '2026-11-05 09:20:08', NULL, 'student', NULL, 'active', NULL, NULL, '2026-11-05 09:20:08', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_follows`
--

DROP TABLE IF EXISTS `user_follows`;
CREATE TABLE IF NOT EXISTS `user_follows` (
  `follower_id` int UNSIGNED NOT NULL,
  `followed_id` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`follower_id`,`followed_id`),
  KEY `idx_user_follows_followed` (`followed_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_follows`
--

INSERT INTO `user_follows` (`follower_id`, `followed_id`, `created_at`) VALUES
(4, 1, '2026-11-01 15:07:33'),
(5, 1, '2026-11-03 03:52:23'),
(10, 1, '2026-11-05 10:52:30'),
(2, 1, '2026-11-07 10:36:50'),
(4, 2, '2026-11-01 15:07:48'),
(5, 2, '2026-11-03 03:52:24'),
(1, 2, '2026-11-04 18:28:43'),
(10, 2, '2026-11-05 10:52:28'),
(2, 4, '2026-11-01 18:25:16'),
(10, 5, '2026-11-05 10:52:33'),
(2, 5, '2026-11-07 10:37:30'),
(10, 7, '2026-11-05 10:52:26'),
(10, 8, '2026-11-05 10:52:22'),
(1, 9, '2026-11-04 18:28:20'),
(10, 9, '2026-11-05 10:52:19'),
(2, 10, '2026-11-05 14:40:52');

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED DEFAULT NULL,
  `username_snapshot` varchar(254) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_at_login` enum('student','admin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` enum('active','logged_out','expired','revoked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `login_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` datetime DEFAULT NULL,
  `end_reason` enum('logout','timeout','admin_revoked','account_suspended') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `user_agent` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_sessions_key_hash` (`session_key_hash`),
  KEY `idx_user_sessions_user_login` (`user_id`,`login_at`),
  KEY `idx_user_sessions_presence` (`status`,`last_activity_at`,`ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `fk_activity_logs_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `artworks`
--
ALTER TABLE `artworks`
  ADD CONSTRAINT `fk_artworks_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  ADD CONSTRAINT `fk_artworks_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_artworks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `artwork_comments`
--
ALTER TABLE `artwork_comments`
  ADD CONSTRAINT `fk_artwork_comments_artwork` FOREIGN KEY (`artwork_id`) REFERENCES `artworks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_artwork_comments_deleted_by` FOREIGN KEY (`deleted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_artwork_comments_parent` FOREIGN KEY (`parent_comment_id`) REFERENCES `artwork_comments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_artwork_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `artwork_likes`
--
ALTER TABLE `artwork_likes`
  ADD CONSTRAINT `fk_artwork_likes_artwork` FOREIGN KEY (`artwork_id`) REFERENCES `artworks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_artwork_likes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `artwork_review_history`
--
ALTER TABLE `artwork_review_history`
  ADD CONSTRAINT `fk_review_history_artist` FOREIGN KEY (`artist_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_review_history_artwork` FOREIGN KEY (`artwork_id`) REFERENCES `artworks` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_review_history_reviewer` FOREIGN KEY (`reviewer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `fk_events_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `event_participants`
--
ALTER TABLE `event_participants`
  ADD CONSTRAINT `fk_event_participants_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_event_participants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_follows`
--
ALTER TABLE `user_follows`
  ADD CONSTRAINT `fk_user_follows_followed` FOREIGN KEY (`followed_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_follows_follower` FOREIGN KEY (`follower_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
