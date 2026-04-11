-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: fsb_portal
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_log`
--

DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `appeal_id` varchar(30) DEFAULT '',
  `detail` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_log`
--

LOCK TABLES `activity_log` WRITE;
/*!40000 ALTER TABLE `activity_log` DISABLE KEYS */;
INSERT INTO `activity_log` VALUES (1,1,'chat','АПО-2026-000001','Комментарий: \"Привет, я трахер\"','2026-04-05 13:30:07'),(2,1,'delete','АПО-2026-000001','Обращение удалено','2026-04-05 13:30:59'),(3,1,'chat','АПО-2026-000002','Комментарий: \"@admin проверь\"','2026-04-11 06:57:49');
/*!40000 ALTER TABLE `activity_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appeal_files`
--

DROP TABLE IF EXISTS `appeal_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appeal_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appeal_db_id` int(11) NOT NULL,
  `filename` varchar(300) NOT NULL,
  `filesize` bigint(20) DEFAULT 0,
  `uploaded_at` datetime DEFAULT current_timestamp(),
  `av_status` varchar(20) DEFAULT 'unknown',
  `av_detail` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `appeal_db_id` (`appeal_db_id`),
  CONSTRAINT `appeal_files_ibfk_1` FOREIGN KEY (`appeal_db_id`) REFERENCES `appeals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appeal_files`
--

LOCK TABLES `appeal_files` WRITE;
/*!40000 ALTER TABLE `appeal_files` DISABLE KEYS */;
/*!40000 ALTER TABLE `appeal_files` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appeal_history`
--

DROP TABLE IF EXISTS `appeal_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appeal_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appeal_db_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `field` varchar(64) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `appeal_db_id` (`appeal_db_id`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `appeal_history_ibfk_1` FOREIGN KEY (`appeal_db_id`) REFERENCES `appeals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appeal_history_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appeal_history`
--

LOCK TABLES `appeal_history` WRITE;
/*!40000 ALTER TABLE `appeal_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `appeal_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appeals`
--

DROP TABLE IF EXISTS `appeals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appeals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appeal_id` varchar(30) NOT NULL,
  `subject` varchar(300) NOT NULL,
  `category` varchar(100) NOT NULL,
  `priority` enum('low','medium','high','critical') NOT NULL DEFAULT 'low',
  `status` enum('new','process','review','done','rejected') NOT NULL DEFAULT 'new',
  `is_anon` tinyint(1) NOT NULL DEFAULT 1,
  `contact_json` text DEFAULT NULL,
  `organ` varchar(300) DEFAULT '',
  `location` varchar(300) DEFAULT '',
  `event_date` date DEFAULT NULL,
  `message` text NOT NULL,
  `ip_address` varchar(45) DEFAULT '',
  `user_agent` text DEFAULT NULL,
  `device_info` varchar(200) DEFAULT '',
  `geo_country` varchar(100) DEFAULT '',
  `geo_region` varchar(100) DEFAULT '',
  `geo_city` varchar(100) DEFAULT '',
  `geo_isp` varchar(200) DEFAULT '',
  `geo_proxy` tinyint(1) DEFAULT 0,
  `geo_hosting` tinyint(1) DEFAULT 0,
  `note` text DEFAULT '',
  `assigned_to` int(11) DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `sender_chat_id` bigint(20) DEFAULT NULL,
  `source` enum('site','telegram') NOT NULL DEFAULT 'site',
  `spam_score` int(11) DEFAULT 0,
  `spam_flags` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `appeal_id` (`appeal_id`),
  KEY `closed_by` (`closed_by`),
  KEY `status` (`status`),
  KEY `category` (`category`),
  KEY `priority` (`priority`),
  KEY `created_at` (`created_at`),
  KEY `assigned_to` (`assigned_to`),
  KEY `sender_chat_id` (`sender_chat_id`),
  KEY `source` (`source`),
  CONSTRAINT `appeals_ibfk_1` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `appeals_ibfk_2` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appeals`
--

LOCK TABLES `appeals` WRITE;
/*!40000 ALTER TABLE `appeals` DISABLE KEYS */;
INSERT INTO `appeals` VALUES (2,'АПО-2026-000001','Вввааа','Коррупция','medium','new',0,'{\"last\":\"Иван\",\"first\":\"Иванович\",\"middle\":\"иванович\",\"phone\":\"+79233318581\",\"telegram\":\"@Nikolaj24rusBorodino\",\"telegram_sender\":\"@Nikolaj24rusBorodino\"}','','',NULL,'Проверка поапаептппиирр','',NULL,'','','','','',0,0,'',NULL,NULL,NULL,'2026-04-11 06:54:45',6692591701,'telegram',0,NULL),(3,'АПО-2026-000002','Требование взятки за выдачу разрешения','Коррупция','high','new',0,'{\"name\":\"Иван Петров\",\"email\":\"ivan.petrov@example.com\",\"phone\":\"+79161234567\"}','Администрация Центрального района','г. Москва, ул. Тверская, 13','2026-04-05','Сотрудник отдела разрешений потребовал 50 000 рублей наличными за ускорение рассмотрения заявления о перепланировке. Имеется аудиозапись разговора.','185.23.44.102',NULL,'','','','','',0,0,'',NULL,NULL,NULL,'2026-04-06 10:12:00',NULL,'site',0,NULL),(4,'АПО-2026-000003','Телефонное мошенничество от имени банка','Мошенничество','medium','process',1,NULL,'','г. Санкт-Петербург','2026-04-08','Поступил звонок с номера +7 (495) 000-00-00. Звонивший представился сотрудником службы безопасности банка, пытался выяснить CVV-код карты и код из SMS. Удалось прервать разговор, деньги не переведены.','95.165.12.8',NULL,'','','','','',0,0,'',NULL,NULL,NULL,'2026-04-08 14:37:00',NULL,'site',0,NULL),(5,'АПО-2026-000004','Фишинговый сайт, копирующий госуслуги','Киберпреступления','critical','review',0,'{\"name\":\"Анна\",\"telegram\":\"@anna_k\"}','','интернет','2026-04-09','Обнаружен домен gosuslugi-lk[.]ru, полностью копирующий дизайн портала госуслуг. Собирает логины, пароли и паспортные данные. Сайт активен, в поисковой выдаче занимает верхние позиции по запросу «вход в госуслуги».','78.140.6.221',NULL,'','','','','',0,0,'',NULL,NULL,NULL,'2026-04-09 09:03:00',NULL,'site',0,NULL),(6,'АПО-2026-000005','Незаконная свалка в лесополосе','Общественная безопасность','medium','new',1,NULL,'','Московская обл., Одинцовский р-н, у д. Подушкино','2026-04-10','На протяжении последних двух недель в лесополосу возле деревни регулярно вывозят строительный мусор. Номера грузовиков зафиксированы на видео. Координаты: 55.7412, 37.1893.','46.188.73.15',NULL,'','','','','',0,0,'',NULL,NULL,NULL,'2026-04-10 18:44:00',NULL,'telegram',0,NULL),(7,'АПО-2026-000006','Подделка медицинских справок','Подделка документов','low','done',0,'{\"name\":\"Сергей\",\"email\":\"s.volkov@example.org\"}','Частная клиника «МедЛайн»','г. Казань, пр-т Ямашева, 45','2026-03-20','В клинике за 3000 рублей без осмотра оформляют медицинские справки формы 086/у. Имеются чеки и фотографии полученных документов.','188.186.201.7',NULL,'','','','','',0,0,'',NULL,NULL,NULL,'2026-03-22 11:20:00',NULL,'site',0,NULL);
/*!40000 ALTER TABLE `appeals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bot_states`
--

DROP TABLE IF EXISTS `bot_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bot_states` (
  `chat_id` bigint(20) NOT NULL,
  `state` varchar(64) NOT NULL DEFAULT '',
  `data` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bot_states`
--

LOCK TABLES `bot_states` WRITE;
/*!40000 ALTER TABLE `bot_states` DISABLE KEYS */;
/*!40000 ALTER TABLE `bot_states` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bot_users`
--

DROP TABLE IF EXISTS `bot_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bot_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` bigint(20) NOT NULL,
  `username` varchar(64) DEFAULT NULL,
  `role` enum('sender','operator') NOT NULL DEFAULT 'sender',
  `user_id` int(11) DEFAULT NULL,
  `notify` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_id` (`chat_id`),
  KEY `idx_role` (`role`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bot_users`
--

LOCK TABLES `bot_users` WRITE;
/*!40000 ALTER TABLE `bot_users` DISABLE KEYS */;
INSERT INTO `bot_users` VALUES (1,6692591701,'Nikolaj24rusBorodino','sender',NULL,1,'2026-04-11 06:46:47'),(2,1324307795,'GrayAltay','sender',NULL,1,'2026-04-11 07:12:22');
/*!40000 ALTER TABLE `bot_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comment_mentions`
--

DROP TABLE IF EXISTS `comment_mentions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comment_mentions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `comment_id` (`comment_id`),
  KEY `user_id` (`user_id`),
  KEY `is_read` (`is_read`),
  CONSTRAINT `comment_mentions_ibfk_1` FOREIGN KEY (`comment_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comment_mentions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comment_mentions`
--

LOCK TABLES `comment_mentions` WRITE;
/*!40000 ALTER TABLE `comment_mentions` DISABLE KEYS */;
INSERT INTO `comment_mentions` VALUES (1,2,2,0,'2026-04-11 06:57:49');
/*!40000 ALTER TABLE `comment_mentions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comments`
--

DROP TABLE IF EXISTS `comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appeal_db_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `text` text NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `appeal_db_id` (`appeal_db_id`),
  CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`appeal_db_id`) REFERENCES `appeals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comments`
--

LOCK TABLES `comments` WRITE;
/*!40000 ALTER TABLE `comments` DISABLE KEYS */;
INSERT INTO `comments` VALUES (2,3,1,'@admin проверь','2026-04-11 06:57:49');
/*!40000 ALTER TABLE `comments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `file_access_log`
--

DROP TABLE IF EXISTS `file_access_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `file_access_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `appeal_id` varchar(30) NOT NULL,
  `filename` varchar(300) NOT NULL,
  `accessed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `appeal_id` (`appeal_id`),
  KEY `accessed_at` (`accessed_at`),
  CONSTRAINT `file_access_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `file_access_log`
--

LOCK TABLES `file_access_log` WRITE;
/*!40000 ALTER TABLE `file_access_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `file_access_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ip_cache`
--

DROP TABLE IF EXISTS `ip_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ip_cache` (
  `ip` varchar(45) NOT NULL,
  `data` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ip_cache`
--

LOCK TABLES `ip_cache` WRITE;
/*!40000 ALTER TABLE `ip_cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `ip_cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `linked_appeals`
--

DROP TABLE IF EXISTS `linked_appeals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `linked_appeals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appeal_id_a` varchar(30) NOT NULL,
  `appeal_id_b` varchar(30) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `appeal_id_a` (`appeal_id_a`,`appeal_id_b`),
  KEY `appeal_id_a_2` (`appeal_id_a`),
  KEY `appeal_id_b` (`appeal_id_b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `linked_appeals`
--

LOCK TABLES `linked_appeals` WRITE;
/*!40000 ALTER TABLE `linked_appeals` DISABLE KEYS */;
/*!40000 ALTER TABLE `linked_appeals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(50) NOT NULL,
  `success` tinyint(1) DEFAULT 0,
  `attempt_time` datetime NOT NULL,
  `ip` varchar(45) DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `login` (`login`),
  KEY `attempt_time` (`attempt_time`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_attempts`
--

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
INSERT INTO `login_attempts` VALUES (1,'superadmin',0,'2026-04-04 20:04:07','::1'),(2,'superadmin',0,'2026-04-04 20:04:13','::1'),(3,'superadmin',0,'2026-04-04 20:04:17','::1'),(4,'superadmin',1,'2026-04-04 20:06:02','::1'),(5,'superadmin',1,'2026-04-05 13:27:10','::1'),(6,'superadmin',1,'2026-04-05 13:29:32','::1'),(7,'superadmin',1,'2026-04-11 06:40:10','::1'),(8,'superadmin',1,'2026-04-11 06:42:22','::1');
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `submit_attempts`
--

DROP TABLE IF EXISTS `submit_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `submit_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `attempt_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ip` (`ip`),
  KEY `attempt_time` (`attempt_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `submit_attempts`
--

LOCK TABLES `submit_attempts` WRITE;
/*!40000 ALTER TABLE `submit_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `submit_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('super','duty') NOT NULL DEFAULT 'duty',
  `last_name` varchar(100) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT '',
  `created_at` datetime NOT NULL,
  `last_active` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`),
  KEY `login_2` (`login`),
  KEY `role` (`role`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'superadmin','$2y$10$7eXTZDXuF4n68FiZ4v1REeCbuO6GjMBqMcQZZVKUD1TTmt/jFzDoO','super','Ковалёв','Дмитрий','Алексеевич','2026-04-05 00:03:17','2026-04-11 06:42:23',1),(2,'admin','$2y$10$waaZo68tvY00Vn/AcsiqZ.vkRxG0aIAOc.JzR9S62y08n7.naVEoW','duty','Иванов','Андрей','Сергеевич','2026-04-05 00:03:17',NULL,1);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'fsb_portal'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-11  7:21:44
