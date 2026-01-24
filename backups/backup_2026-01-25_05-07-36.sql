-- MySQL dump 10.13  Distrib 8.0.30, for Win64 (x86_64)
--
-- Host: localhost    Database: db_absensi
-- ------------------------------------------------------
-- Server version	8.4.3

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `tb_absensi`
--

DROP TABLE IF EXISTS `tb_absensi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_absensi` (
  `id_absensi` int NOT NULL AUTO_INCREMENT,
  `id_siswa` int NOT NULL,
  `tanggal` date NOT NULL,
  `jam_masuk` time DEFAULT NULL,
  `jam_keluar` time DEFAULT NULL,
  `keterangan` enum('Hadir','Sakit','Izin','Alpa') NOT NULL,
  `id_guru` int DEFAULT NULL,
  PRIMARY KEY (`id_absensi`),
  KEY `id_siswa` (`id_siswa`),
  KEY `id_guru` (`id_guru`),
  CONSTRAINT `tb_absensi_ibfk_1` FOREIGN KEY (`id_siswa`) REFERENCES `tb_siswa` (`id_siswa`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `tb_absensi_ibfk_2` FOREIGN KEY (`id_guru`) REFERENCES `tb_guru` (`id_guru`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=340 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_absensi`
--

LOCK TABLES `tb_absensi` WRITE;
/*!40000 ALTER TABLE `tb_absensi` DISABLE KEYS */;
INSERT INTO `tb_absensi` VALUES (56,22,'2026-01-16',NULL,NULL,'Hadir',NULL),(57,23,'2026-01-16',NULL,NULL,'Hadir',NULL),(58,24,'2026-01-16',NULL,NULL,'Hadir',NULL),(59,25,'2026-01-16',NULL,NULL,'Hadir',NULL),(60,26,'2026-01-16',NULL,NULL,'Hadir',NULL),(61,27,'2026-01-16',NULL,NULL,'Hadir',NULL),(62,28,'2026-01-16',NULL,NULL,'Hadir',NULL),(63,29,'2026-01-16',NULL,NULL,'Hadir',NULL),(64,30,'2026-01-16',NULL,NULL,'Sakit',NULL),(65,31,'2026-01-16',NULL,NULL,'Hadir',NULL),(66,32,'2026-01-16',NULL,NULL,'Hadir',NULL),(67,33,'2026-01-16',NULL,NULL,'Hadir',NULL),(68,34,'2026-01-16',NULL,NULL,'Hadir',NULL),(69,35,'2026-01-16',NULL,NULL,'Hadir',NULL),(70,36,'2026-01-16',NULL,NULL,'Sakit',NULL),(71,37,'2026-01-16',NULL,NULL,'Hadir',NULL),(72,38,'2026-01-16',NULL,NULL,'Hadir',NULL),(73,39,'2026-01-16',NULL,NULL,'Alpa',NULL),(74,40,'2026-01-16',NULL,NULL,'Hadir',NULL),(75,41,'2026-01-16',NULL,NULL,'Hadir',NULL),(76,42,'2026-01-16',NULL,NULL,'Hadir',NULL),(77,43,'2026-01-16',NULL,NULL,'Hadir',NULL),(78,44,'2026-01-16',NULL,NULL,'Hadir',NULL),(79,45,'2026-01-16',NULL,NULL,'Hadir',NULL),(80,46,'2026-01-16',NULL,NULL,'Hadir',NULL),(81,47,'2026-01-16',NULL,NULL,'Hadir',NULL),(82,48,'2026-01-16',NULL,NULL,'Hadir',NULL),(83,49,'2026-01-16',NULL,NULL,'Hadir',NULL),(84,50,'2026-01-16',NULL,NULL,'Hadir',NULL),(85,51,'2026-01-16',NULL,NULL,'Hadir',NULL),(86,52,'2026-01-16',NULL,NULL,'Hadir',NULL),(87,53,'2026-01-16',NULL,NULL,'Hadir',NULL),(88,54,'2026-01-16',NULL,NULL,'Hadir',NULL),(89,55,'2026-01-16',NULL,NULL,'Hadir',NULL),(90,56,'2026-01-16',NULL,NULL,'Hadir',NULL),(91,57,'2026-01-16',NULL,NULL,'Hadir',NULL),(92,58,'2026-01-16',NULL,NULL,'Hadir',NULL),(93,59,'2026-01-16',NULL,NULL,'Hadir',NULL),(94,60,'2026-01-16',NULL,NULL,'Hadir',NULL),(95,61,'2026-01-16',NULL,NULL,'Hadir',NULL),(96,62,'2026-01-16',NULL,NULL,'Hadir',NULL),(97,63,'2026-01-16',NULL,NULL,'Hadir',NULL),(98,64,'2026-01-16',NULL,NULL,'Hadir',NULL),(99,65,'2026-01-16',NULL,NULL,'Sakit',NULL),(100,66,'2026-01-16',NULL,NULL,'Hadir',NULL),(101,67,'2026-01-16',NULL,NULL,'Hadir',NULL),(102,68,'2026-01-16',NULL,NULL,'Hadir',NULL),(103,69,'2026-01-16',NULL,NULL,'Hadir',NULL),(104,70,'2026-01-16',NULL,NULL,'Hadir',NULL),(105,71,'2026-01-16',NULL,NULL,'Hadir',NULL),(106,72,'2026-01-16',NULL,NULL,'Hadir',NULL),(107,73,'2026-01-16',NULL,NULL,'Hadir',NULL),(108,74,'2026-01-16',NULL,NULL,'Hadir',NULL),(109,75,'2026-01-16',NULL,NULL,'Hadir',NULL),(110,76,'2026-01-16',NULL,NULL,'Hadir',NULL),(111,77,'2026-01-16',NULL,NULL,'Hadir',NULL),(112,78,'2026-01-16',NULL,NULL,'Hadir',NULL),(113,79,'2026-01-16',NULL,NULL,'Hadir',NULL),(114,80,'2026-01-16',NULL,NULL,'Hadir',NULL),(115,81,'2026-01-16',NULL,NULL,'Hadir',NULL),(116,82,'2026-01-16',NULL,NULL,'Hadir',NULL),(117,83,'2026-01-16',NULL,NULL,'Hadir',NULL),(118,84,'2026-01-16',NULL,NULL,'Hadir',NULL),(119,85,'2026-01-16',NULL,NULL,'Hadir',NULL),(120,86,'2026-01-16',NULL,NULL,'Hadir',NULL),(121,87,'2026-01-16',NULL,NULL,'Hadir',NULL),(122,88,'2026-01-16',NULL,NULL,'Hadir',NULL),(123,89,'2026-01-16',NULL,NULL,'Hadir',NULL),(124,90,'2026-01-16',NULL,NULL,'Hadir',NULL),(125,91,'2026-01-16',NULL,NULL,'Hadir',NULL),(126,92,'2026-01-16',NULL,NULL,'Hadir',NULL),(127,93,'2026-01-16',NULL,NULL,'Hadir',NULL),(128,94,'2026-01-16',NULL,NULL,'Hadir',NULL),(129,95,'2026-01-16',NULL,NULL,'Hadir',NULL),(130,96,'2026-01-16',NULL,NULL,'Alpa',NULL),(131,97,'2026-01-16',NULL,NULL,'Hadir',NULL),(132,98,'2026-01-16',NULL,NULL,'Hadir',NULL),(133,99,'2026-01-16',NULL,NULL,'Hadir',NULL),(134,100,'2026-01-16',NULL,NULL,'Hadir',NULL),(135,101,'2026-01-16',NULL,NULL,'Hadir',NULL),(136,102,'2026-01-16',NULL,NULL,'Hadir',NULL),(137,103,'2026-01-16',NULL,NULL,'Alpa',NULL),(138,104,'2026-01-16',NULL,NULL,'Hadir',NULL),(139,105,'2026-01-16',NULL,NULL,'Izin',NULL),(140,106,'2026-01-16',NULL,NULL,'Hadir',NULL),(141,107,'2026-01-16',NULL,NULL,'Hadir',NULL),(142,108,'2026-01-16',NULL,NULL,'Hadir',NULL),(143,109,'2026-01-16',NULL,NULL,'Hadir',NULL),(144,110,'2026-01-16',NULL,NULL,'Hadir',NULL),(145,111,'2026-01-16',NULL,NULL,'Hadir',NULL),(146,112,'2026-01-16',NULL,NULL,'Hadir',NULL),(147,113,'2026-01-16',NULL,NULL,'Alpa',NULL),(148,114,'2026-01-16',NULL,NULL,'Hadir',NULL),(149,115,'2026-01-16',NULL,NULL,'Hadir',NULL),(150,116,'2026-01-16',NULL,NULL,'Hadir',NULL),(151,117,'2026-01-16',NULL,NULL,'Hadir',NULL),(152,118,'2026-01-16',NULL,NULL,'Hadir',NULL),(153,119,'2026-01-16',NULL,NULL,'Hadir',NULL),(154,120,'2026-01-16',NULL,NULL,'Hadir',NULL),(155,121,'2026-01-16',NULL,NULL,'Hadir',NULL),(156,122,'2026-01-16',NULL,NULL,'Hadir',NULL),(157,123,'2026-01-16',NULL,NULL,'Hadir',NULL),(158,124,'2026-01-16',NULL,NULL,'Hadir',NULL),(159,125,'2026-01-16',NULL,NULL,'Hadir',NULL),(160,126,'2026-01-16',NULL,NULL,'Hadir',NULL),(161,127,'2026-01-16',NULL,NULL,'Hadir',NULL),(162,128,'2026-01-16',NULL,NULL,'Alpa',NULL),(163,129,'2026-01-16',NULL,NULL,'Hadir',NULL),(164,130,'2026-01-16',NULL,NULL,'Hadir',NULL),(165,131,'2026-01-16',NULL,NULL,'Hadir',NULL),(166,132,'2026-01-16',NULL,NULL,'Hadir',NULL),(167,133,'2026-01-16',NULL,NULL,'Hadir',NULL),(168,125,'2026-01-22',NULL,NULL,'Hadir',NULL),(169,126,'2026-01-22',NULL,NULL,'Hadir',NULL),(170,127,'2026-01-22',NULL,NULL,'Hadir',NULL),(171,128,'2026-01-22',NULL,NULL,'Hadir',NULL),(172,129,'2026-01-22',NULL,NULL,'Hadir',NULL),(173,130,'2026-01-22',NULL,NULL,'Hadir',NULL),(174,131,'2026-01-22',NULL,NULL,'Hadir',NULL),(175,132,'2026-01-22',NULL,NULL,'Hadir',NULL),(176,133,'2026-01-22',NULL,NULL,'Izin',NULL),(177,115,'2026-01-22',NULL,NULL,'Hadir',NULL),(178,116,'2026-01-22',NULL,NULL,'Hadir',NULL),(179,117,'2026-01-22',NULL,NULL,'Hadir',NULL),(180,118,'2026-01-22',NULL,NULL,'Hadir',NULL),(181,119,'2026-01-22',NULL,NULL,'Hadir',NULL),(182,120,'2026-01-22',NULL,NULL,'Sakit',NULL),(183,121,'2026-01-22',NULL,NULL,'Hadir',NULL),(184,122,'2026-01-22',NULL,NULL,'Hadir',NULL),(185,123,'2026-01-22',NULL,NULL,'Hadir',NULL),(186,124,'2026-01-22',NULL,NULL,'Hadir',NULL),(187,22,'2026-01-22',NULL,NULL,'Hadir',24),(188,23,'2026-01-22',NULL,NULL,'Hadir',24),(189,24,'2026-01-22',NULL,NULL,'Hadir',24),(190,25,'2026-01-22',NULL,NULL,'Hadir',24),(191,26,'2026-01-22',NULL,NULL,'Hadir',24),(192,27,'2026-01-22',NULL,NULL,'Hadir',24),(193,28,'2026-01-22',NULL,NULL,'Hadir',24),(194,30,'2026-01-22',NULL,NULL,'Hadir',24),(195,29,'2026-01-22',NULL,NULL,'Hadir',24),(196,31,'2026-01-22',NULL,NULL,'Hadir',24),(197,32,'2026-01-22',NULL,NULL,'Hadir',24),(198,33,'2026-01-22',NULL,NULL,'Hadir',24),(199,34,'2026-01-22',NULL,NULL,'Hadir',24),(200,35,'2026-01-22',NULL,NULL,'Hadir',24),(201,36,'2026-01-22',NULL,NULL,'Hadir',24),(202,37,'2026-01-22',NULL,NULL,'Hadir',24),(203,38,'2026-01-22',NULL,NULL,'Hadir',24),(204,39,'2026-01-22',NULL,NULL,'Hadir',24),(205,97,'2026-01-22',NULL,NULL,'Hadir',20),(206,98,'2026-01-22',NULL,NULL,'Hadir',20),(207,99,'2026-01-22',NULL,NULL,'Hadir',20),(208,100,'2026-01-22',NULL,NULL,'Hadir',20),(209,101,'2026-01-22',NULL,NULL,'Hadir',20),(210,102,'2026-01-22',NULL,NULL,'Hadir',20),(211,103,'2026-01-22',NULL,NULL,'Hadir',20),(212,104,'2026-01-22',NULL,NULL,'Hadir',20),(213,105,'2026-01-22',NULL,NULL,'Hadir',20),(214,106,'2026-01-22',NULL,NULL,'Hadir',20),(215,107,'2026-01-22',NULL,NULL,'Hadir',20),(216,108,'2026-01-22',NULL,NULL,'Hadir',20),(217,109,'2026-01-22',NULL,NULL,'Hadir',20),(218,110,'2026-01-22',NULL,NULL,'Hadir',20),(219,111,'2026-01-22',NULL,NULL,'Hadir',20),(220,112,'2026-01-22',NULL,NULL,'Hadir',20),(221,113,'2026-01-22',NULL,NULL,'Hadir',20),(222,114,'2026-01-22',NULL,NULL,'Hadir',20),(223,115,'2026-01-23',NULL,NULL,'Hadir',19),(224,116,'2026-01-23',NULL,NULL,'Hadir',19),(225,117,'2026-01-23',NULL,NULL,'Hadir',19),(226,118,'2026-01-23',NULL,NULL,'Hadir',19),(227,119,'2026-01-23',NULL,NULL,'Hadir',19),(228,120,'2026-01-23',NULL,NULL,'Hadir',19),(229,121,'2026-01-23',NULL,NULL,'Hadir',19),(230,122,'2026-01-23',NULL,NULL,'Sakit',19),(231,123,'2026-01-23',NULL,NULL,'Hadir',19),(232,124,'2026-01-23',NULL,NULL,'Hadir',19),(233,125,'2026-01-23',NULL,NULL,'Hadir',19),(234,126,'2026-01-23',NULL,NULL,'Hadir',19),(235,127,'2026-01-23',NULL,NULL,'Hadir',19),(236,128,'2026-01-23',NULL,NULL,'Hadir',19),(237,129,'2026-01-23',NULL,NULL,'Hadir',19),(238,130,'2026-01-23',NULL,NULL,'Izin',19),(239,131,'2026-01-23',NULL,NULL,'Hadir',19),(240,132,'2026-01-23',NULL,NULL,'Hadir',19),(241,133,'2026-01-23',NULL,NULL,'Hadir',19),(242,40,'2026-01-23',NULL,NULL,'Hadir',24),(243,41,'2026-01-23',NULL,NULL,'Hadir',24),(244,42,'2026-01-23',NULL,NULL,'Hadir',24),(245,43,'2026-01-23',NULL,NULL,'Hadir',24),(246,44,'2026-01-23',NULL,NULL,'Hadir',24),(247,45,'2026-01-23',NULL,NULL,'Hadir',24),(248,46,'2026-01-23',NULL,NULL,'Hadir',24),(249,47,'2026-01-23',NULL,NULL,'Hadir',24),(250,48,'2026-01-23',NULL,NULL,'Hadir',24),(251,49,'2026-01-23',NULL,NULL,'Sakit',24),(252,50,'2026-01-23',NULL,NULL,'Hadir',24),(253,51,'2026-01-23',NULL,NULL,'Hadir',24),(254,52,'2026-01-23',NULL,NULL,'Hadir',24),(255,53,'2026-01-23',NULL,NULL,'Hadir',24),(256,54,'2026-01-23',NULL,NULL,'Hadir',24),(257,55,'2026-01-23',NULL,NULL,'Hadir',24),(258,56,'2026-01-23',NULL,NULL,'Hadir',24),(259,57,'2026-01-23',NULL,NULL,'Hadir',24),(260,58,'2026-01-23',NULL,NULL,'Hadir',24),(261,59,'2026-01-23',NULL,NULL,'Hadir',24),(262,60,'2026-01-23',NULL,NULL,'Hadir',24),(263,61,'2026-01-23',NULL,NULL,'Hadir',24),(264,62,'2026-01-23',NULL,NULL,'Hadir',24),(265,63,'2026-01-23',NULL,NULL,'Hadir',24),(266,97,'2026-01-23',NULL,NULL,'Hadir',20),(267,98,'2026-01-23',NULL,NULL,'Hadir',20),(268,99,'2026-01-23',NULL,NULL,'Hadir',20),(269,100,'2026-01-23',NULL,NULL,'Hadir',20),(270,101,'2026-01-23',NULL,NULL,'Hadir',20),(271,102,'2026-01-23',NULL,NULL,'Hadir',20),(272,103,'2026-01-23',NULL,NULL,'Hadir',20),(273,104,'2026-01-23',NULL,NULL,'Hadir',20),(274,105,'2026-01-23',NULL,NULL,'Sakit',20),(275,106,'2026-01-23',NULL,NULL,'Hadir',20),(276,107,'2026-01-23',NULL,NULL,'Hadir',20),(277,108,'2026-01-23',NULL,NULL,'Hadir',20),(278,109,'2026-01-23',NULL,NULL,'Hadir',20),(279,110,'2026-01-23',NULL,NULL,'Hadir',20),(280,111,'2026-01-23',NULL,NULL,'Hadir',20),(281,112,'2026-01-23',NULL,NULL,'Hadir',20),(282,113,'2026-01-23',NULL,NULL,'Hadir',20),(283,114,'2026-01-23',NULL,NULL,'Hadir',20),(284,22,'2026-01-23',NULL,NULL,'Hadir',NULL),(285,23,'2026-01-23',NULL,NULL,'Hadir',NULL),(286,24,'2026-01-23',NULL,NULL,'Hadir',NULL),(287,25,'2026-01-23',NULL,NULL,'Hadir',NULL),(288,26,'2026-01-23',NULL,NULL,'Hadir',NULL),(289,27,'2026-01-23',NULL,NULL,'Hadir',NULL),(290,28,'2026-01-23',NULL,NULL,'Hadir',NULL),(291,30,'2026-01-23',NULL,NULL,'Hadir',NULL),(292,29,'2026-01-23',NULL,NULL,'Hadir',NULL),(293,31,'2026-01-23',NULL,NULL,'Hadir',NULL),(294,32,'2026-01-23',NULL,NULL,'Hadir',NULL),(295,33,'2026-01-23',NULL,NULL,'Hadir',NULL),(296,34,'2026-01-23',NULL,NULL,'Hadir',NULL),(297,35,'2026-01-23',NULL,NULL,'Hadir',NULL),(298,36,'2026-01-23',NULL,NULL,'Hadir',NULL),(299,37,'2026-01-23',NULL,NULL,'Hadir',NULL),(300,38,'2026-01-23',NULL,NULL,'Hadir',NULL),(301,39,'2026-01-23',NULL,NULL,'Hadir',NULL),(302,115,'2026-01-24',NULL,NULL,'Hadir',NULL),(303,116,'2026-01-24',NULL,NULL,'Hadir',NULL),(304,117,'2026-01-24',NULL,NULL,'Hadir',NULL),(305,118,'2026-01-24',NULL,NULL,'Izin',NULL),(306,119,'2026-01-24',NULL,NULL,'Hadir',NULL),(307,120,'2026-01-24',NULL,NULL,'Hadir',NULL),(308,121,'2026-01-24',NULL,NULL,'Hadir',NULL),(309,122,'2026-01-24',NULL,NULL,'Hadir',NULL),(310,123,'2026-01-24',NULL,NULL,'Hadir',NULL),(311,124,'2026-01-24',NULL,NULL,'Hadir',NULL),(312,125,'2026-01-24',NULL,NULL,'Hadir',NULL),(313,126,'2026-01-24',NULL,NULL,'Hadir',NULL),(314,127,'2026-01-24',NULL,NULL,'Hadir',NULL),(315,128,'2026-01-24',NULL,NULL,'Hadir',NULL),(316,129,'2026-01-24',NULL,NULL,'Hadir',NULL),(317,130,'2026-01-24',NULL,NULL,'Hadir',NULL),(318,131,'2026-01-24',NULL,NULL,'Hadir',NULL),(319,132,'2026-01-24',NULL,NULL,'Hadir',NULL),(320,133,'2026-01-24',NULL,NULL,'Hadir',NULL),(321,115,'2026-01-25',NULL,NULL,'Hadir',NULL),(322,116,'2026-01-25',NULL,NULL,'Hadir',NULL),(323,117,'2026-01-25',NULL,NULL,'Hadir',NULL),(324,118,'2026-01-25',NULL,NULL,'Hadir',NULL),(325,119,'2026-01-25',NULL,NULL,'Hadir',NULL),(326,120,'2026-01-25',NULL,NULL,'Hadir',NULL),(327,121,'2026-01-25',NULL,NULL,'Hadir',NULL),(328,122,'2026-01-25',NULL,NULL,'Hadir',NULL),(329,123,'2026-01-25',NULL,NULL,'Hadir',NULL),(330,124,'2026-01-25',NULL,NULL,'Hadir',NULL),(331,125,'2026-01-25',NULL,NULL,'Hadir',NULL),(332,126,'2026-01-25',NULL,NULL,'Hadir',NULL),(333,127,'2026-01-25',NULL,NULL,'Hadir',NULL),(334,128,'2026-01-25',NULL,NULL,'Hadir',NULL),(335,129,'2026-01-25',NULL,NULL,'Hadir',NULL),(336,130,'2026-01-25',NULL,NULL,'Hadir',NULL),(337,131,'2026-01-25',NULL,NULL,'Hadir',NULL),(338,132,'2026-01-25',NULL,NULL,'Hadir',NULL),(339,133,'2026-01-25',NULL,NULL,'Hadir',NULL);
/*!40000 ALTER TABLE `tb_absensi` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_absensi_guru`
--

DROP TABLE IF EXISTS `tb_absensi_guru`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_absensi_guru` (
  `id_absensi` int NOT NULL AUTO_INCREMENT,
  `id_guru` int NOT NULL,
  `tanggal` date NOT NULL,
  `status` enum('hadir','sakit','izin','alpa') COLLATE utf8mb4_unicode_ci NOT NULL,
  `keterangan` text COLLATE utf8mb4_unicode_ci,
  `waktu_input` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_absensi`),
  KEY `id_guru` (`id_guru`),
  CONSTRAINT `tb_absensi_guru_ibfk_1` FOREIGN KEY (`id_guru`) REFERENCES `tb_guru` (`id_guru`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_absensi_guru`
--

LOCK TABLES `tb_absensi_guru` WRITE;
/*!40000 ALTER TABLE `tb_absensi_guru` DISABLE KEYS */;
INSERT INTO `tb_absensi_guru` VALUES (1,20,'2026-01-22','hadir','','2026-01-22 19:33:34'),(2,30,'2026-01-22','hadir','','2026-01-22 19:33:34'),(3,19,'2026-01-22','izin','pindah hari','2026-01-22 15:06:43'),(4,24,'2026-01-22','hadir','','2026-01-22 15:36:35'),(5,29,'2026-01-22','hadir','','2026-01-22 15:36:08'),(6,23,'2026-01-22','sakit','sakit panas','2026-01-22 15:36:35'),(7,28,'2026-01-22','hadir','','2026-01-22 15:36:35'),(8,22,'2026-01-22','hadir','','2026-01-22 15:36:47'),(9,26,'2026-01-22','hadir','','2026-01-22 15:36:47'),(10,21,'2026-01-22','alpa','','2026-01-22 19:25:33'),(11,27,'2026-01-22','hadir','','2026-01-22 19:25:33'),(12,18,'2026-01-22','hadir','','2026-01-22 19:33:33'),(13,25,'2026-01-22','hadir','','2026-01-22 19:33:34'),(14,19,'2026-01-23','hadir','','2026-01-23 04:17:25'),(15,24,'2026-01-23','hadir','','2026-01-23 05:59:16'),(16,20,'2026-01-23','hadir','','2026-01-23 06:07:59'),(17,20,'2026-01-24','hadir','','2026-01-24 19:38:58'),(18,30,'2026-01-24','hadir','','2026-01-24 19:38:58'),(19,19,'2026-01-24','hadir','','2026-01-24 19:38:59'),(20,24,'2026-01-24','hadir','','2026-01-24 20:30:41'),(21,29,'2026-01-24','hadir','','2026-01-24 20:30:41'),(22,20,'2026-01-25','alpa','','2026-01-25 04:27:26'),(23,30,'2026-01-25','alpa','','2026-01-25 04:27:26'),(24,19,'2026-01-25','hadir','','2026-01-25 04:27:26');
/*!40000 ALTER TABLE `tb_absensi_guru` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_activity_log`
--

DROP TABLE IF EXISTS `tb_activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_activity_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=348 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_activity_log`
--

LOCK TABLES `tb_activity_log` WRITE;
/*!40000 ALTER TABLE `tb_activity_log` DISABLE KEYS */;
INSERT INTO `tb_activity_log` VALUES (325,'Admin','Login','User logged in successfully','127.0.0.1','2026-01-24 11:53:59'),(326,'Admin','Login','User logged in successfully','127.0.0.1','2026-01-24 12:36:15'),(327,'Admin','Hapus Backup','Admin Admin menghapus backup file: backup_2026-01-13_10-59-31.sql','127.0.0.1','2026-01-24 12:37:01'),(328,'Admin','Hapus Backup','Admin Admin menghapus backup file: backup_2026-01-16_21-05-12.sql','127.0.0.1','2026-01-24 12:37:11'),(329,'Admin','Input Absensi Guru','Menyimpan absensi guru untuk tanggal 2026-01-24','127.0.0.1','2026-01-24 12:38:59'),(330,'Admin','Input Absensi','Admin Admin melakukan input absensi harian kelas ID: 6 untuk 19 siswa','127.0.0.1','2026-01-24 12:39:17'),(331,'Admin','Input Absensi','Admin Admin melakukan input absensi harian kelas ID: 6 untuk 19 siswa','127.0.0.1','2026-01-24 12:40:30'),(332,'Admin','Logout','User logged out from admin session','127.0.0.1','2026-01-24 12:41:05'),(333,'5436757658200002','Login','Teacher logged in successfully using NUPTK','127.0.0.1','2026-01-24 12:41:09'),(334,'5436757658200002','Logout','User logged out from wali session','127.0.0.1','2026-01-24 13:24:18'),(335,'7841746648200002','Login','Teacher logged in successfully using NUPTK','127.0.0.1','2026-01-24 13:24:24'),(336,'Ah. Mustaqim Isom, A.Ma.','Absensi Guru','Ah. Mustaqim Isom, A.Ma. memperbarui kehadiran: hadir','127.0.0.1','2026-01-24 13:24:46'),(337,'7841746648200002','Logout','User logged out from guru session','127.0.0.1','2026-01-24 13:25:11'),(338,'5436757658200002','Login','Teacher logged in successfully using NUPTK','127.0.0.1','2026-01-24 13:25:14'),(339,'5436757658200002','Logout','User logged out from wali session','127.0.0.1','2026-01-24 13:25:20'),(340,'Admin','Login','User logged in successfully','127.0.0.1','2026-01-24 13:25:23'),(341,'Admin','Input Absensi Guru','Menyimpan absensi guru untuk tanggal 2026-01-24','127.0.0.1','2026-01-24 13:30:41'),(342,'Admin','Input Absensi','Admin Admin melakukan input absensi harian kelas ID: 6 untuk 19 siswa','127.0.0.1','2026-01-24 21:26:54'),(343,'Admin','Input Absensi Guru','Menyimpan absensi guru untuk tanggal 2026-01-25','127.0.0.1','2026-01-24 21:27:26'),(344,'Admin','Logout','User logged out from admin session','127.0.0.1','2026-01-24 21:57:04'),(345,'5436757658200002','Login','Teacher logged in successfully using NUPTK','127.0.0.1','2026-01-24 21:57:08'),(346,'5436757658200002','Logout','User logged out from wali session','127.0.0.1','2026-01-24 21:57:26'),(347,'Admin','Login','User logged in successfully','127.0.0.1','2026-01-24 21:57:29');
/*!40000 ALTER TABLE `tb_activity_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_backup_restore`
--

DROP TABLE IF EXISTS `tb_backup_restore`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_backup_restore` (
  `id_backup` int NOT NULL AUTO_INCREMENT,
  `nama_file` varchar(200) NOT NULL,
  `tanggal_backup` datetime NOT NULL,
  `ukuran_file` varchar(20) NOT NULL,
  `keterangan` text,
  PRIMARY KEY (`id_backup`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_backup_restore`
--

LOCK TABLES `tb_backup_restore` WRITE;
/*!40000 ALTER TABLE `tb_backup_restore` DISABLE KEYS */;
INSERT INTO `tb_backup_restore` VALUES (3,'backup_2026-01-22_19-37-05.sql','2026-01-22 19:37:05','35.93 KB','Backup manual');
/*!40000 ALTER TABLE `tb_backup_restore` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_guru`
--

DROP TABLE IF EXISTS `tb_guru`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_guru` (
  `id_guru` int NOT NULL AUTO_INCREMENT,
  `nama_guru` varchar(100) NOT NULL,
  `nuptk` varchar(50) NOT NULL,
  `tempat_lahir` varchar(50) NOT NULL,
  `tanggal_lahir` date DEFAULT NULL,
  `jenis_kelamin` enum('Laki-laki','Perempuan') NOT NULL,
  `wali_kelas` varchar(50) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `password_plain` varchar(255) DEFAULT NULL,
  `mengajar` text,
  `foto` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_guru`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_guru`
--

LOCK TABLES `tb_guru` WRITE;
/*!40000 ALTER TABLE `tb_guru` DISABLE KEYS */;
INSERT INTO `tb_guru` VALUES (18,'Abdul Ghofur, S.Pd.I','2444764667200003','Jepara','1986-11-12','Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"5\"]',NULL),(19,'Nur Huda, S.Pd.I.','5436757658200002','Jepara','1979-01-04','Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"6\"]','guru_19_1769260235.jpg'),(20,'Ah. Mustaqim Isom, A.Ma.','7841746648200002','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"V\",\"VI\"]',NULL),(21,'Alfina Martha Sintya, S.Pd.','33200111223','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"4\"]',NULL),(22,'Ali Yasin, S.Pd.I','9547746647110022','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"III\"]',NULL),(23,'Hamidah, A.Ma.','4444747649200002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"II\"]',NULL),(24,'Indasah, A.Ma.','2640755657300002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"1\",\"2\"]',NULL),(25,'Khoiruddin, S.Pd.','ID20318581190001','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"V\"]',NULL),(26,'Musri`ah, S.Pd.I','6956748651300002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"III\"]',NULL),(27,'Nanik Purwati, S.Pd.I','6556755656300002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"IV\"]',NULL),(28,'Nur Hidah, S.Pd.I.','7357760661300003','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"II\"]',NULL),(29,'Zama`ah, S.Pd.I.','8041756657300003','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"I\"]',NULL),(30,'Muhamad Junaedi','8552750652200002','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"V\",\"VI\"]',NULL);
/*!40000 ALTER TABLE `tb_guru` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_jam_mengajar`
--

DROP TABLE IF EXISTS `tb_jam_mengajar`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_jam_mengajar` (
  `id_jam` int NOT NULL AUTO_INCREMENT,
  `jam_ke` int NOT NULL,
  `waktu_mulai` time NOT NULL,
  `waktu_selesai` time NOT NULL,
  PRIMARY KEY (`id_jam`),
  UNIQUE KEY `unique_jam` (`jam_ke`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_jam_mengajar`
--

LOCK TABLES `tb_jam_mengajar` WRITE;
/*!40000 ALTER TABLE `tb_jam_mengajar` DISABLE KEYS */;
INSERT INTO `tb_jam_mengajar` VALUES (1,1,'07:10:00','07:45:00'),(2,2,'07:45:00','08:20:00'),(3,3,'08:20:00','08:55:00'),(4,4,'09:20:00','09:55:00'),(5,5,'09:55:00','10:30:00'),(6,6,'10:30:00','11:05:00'),(7,7,'11:15:00','11:50:00'),(8,8,'11:50:00','12:15:00'),(9,9,'12:15:00','12:35:00');
/*!40000 ALTER TABLE `tb_jam_mengajar` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_jurnal`
--

DROP TABLE IF EXISTS `tb_jurnal`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_jurnal` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_kelas` int NOT NULL,
  `id_guru` int DEFAULT NULL,
  `jam_ke` varchar(50) NOT NULL,
  `mapel` varchar(100) NOT NULL,
  `materi` text NOT NULL,
  `tanggal` date NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `id_kelas` (`id_kelas`),
  KEY `id_guru` (`id_guru`),
  CONSTRAINT `tb_jurnal_ibfk_1` FOREIGN KEY (`id_kelas`) REFERENCES `tb_kelas` (`id_kelas`) ON DELETE CASCADE,
  CONSTRAINT `tb_jurnal_ibfk_2` FOREIGN KEY (`id_guru`) REFERENCES `tb_guru` (`id_guru`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_jurnal`
--

LOCK TABLES `tb_jurnal` WRITE;
/*!40000 ALTER TABLE `tb_jurnal` DISABLE KEYS */;
INSERT INTO `tb_jurnal` VALUES (3,1,24,'1,2','Al-Quran Hadis','Surat Al-Lahab','2026-01-22','2026-01-22 12:23:45'),(4,5,20,'1,2,3','Al-Quran Hadis','Surat An-Naas','2026-01-22','2026-01-22 12:59:30'),(7,6,19,'1,2,3','IPAS','Tata surya dan planet-planet','2026-01-23','2026-01-22 21:25:58'),(8,6,19,'1,2,3','IPAS','Tata surya dan planet-planet','2026-01-23','2026-01-22 21:26:14'),(9,6,19,'4,5','Bahasa Arab','Fiil Madli','2026-01-23','2026-01-22 22:39:57'),(10,2,24,'1,2','Akidah Akhlak','Akhlak terpuji','2026-01-23','2026-01-22 23:00:01'),(11,5,20,'1,2','Al-Quran Hadis','Hadis Tentang Shodakoh','2026-01-23','2026-01-22 23:08:45'),(12,6,19,'1,2,3','Bahasa Indonesia','Materi Persiapan TKA 2026','2026-01-24','2026-01-24 12:41:46');
/*!40000 ALTER TABLE `tb_jurnal` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_kelas`
--

DROP TABLE IF EXISTS `tb_kelas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_kelas` (
  `id_kelas` int NOT NULL AUTO_INCREMENT,
  `nama_kelas` varchar(50) NOT NULL,
  `wali_kelas` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id_kelas`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_kelas`
--

LOCK TABLES `tb_kelas` WRITE;
/*!40000 ALTER TABLE `tb_kelas` DISABLE KEYS */;
INSERT INTO `tb_kelas` VALUES (1,'I','Zama`ah, S.Pd.I.'),(2,'II','Nur Hidah, S.Pd.I.'),(3,'III','Ali Yasin, S.Pd.I'),(4,'IV','Nanik Purwati, S.Pd.I'),(5,'V','Abdul Ghofur, S.Pd.I'),(6,'VI','Nur Huda, S.Pd.I.');
/*!40000 ALTER TABLE `tb_kelas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_mata_pelajaran`
--

DROP TABLE IF EXISTS `tb_mata_pelajaran`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_mata_pelajaran` (
  `id_mapel` int NOT NULL AUTO_INCREMENT,
  `nama_mapel` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id_mapel`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_mata_pelajaran`
--

LOCK TABLES `tb_mata_pelajaran` WRITE;
/*!40000 ALTER TABLE `tb_mata_pelajaran` DISABLE KEYS */;
INSERT INTO `tb_mata_pelajaran` VALUES (1,'Bahasa Indonesia'),(2,'Bahasa Arab'),(3,'Akidah Akhlak'),(4,'Al-Quran Hadis'),(5,'Sejarah Kebudayaan Islam'),(6,'Pendidikan Pancasila'),(7,'IPAS'),(8,'PJOK'),(9,'Matematika'),(10,'Bahasa Inggris'),(11,'Seni Budaya'),(12,'Ke-NU-an'),(13,'Tajwid'),(14,'BTA'),(15,'Bahasa Jawa'),(16,'Fikih');
/*!40000 ALTER TABLE `tb_mata_pelajaran` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_notifikasi`
--

DROP TABLE IF EXISTS `tb_notifikasi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_notifikasi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `message` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_notifikasi`
--

LOCK TABLES `tb_notifikasi` WRITE;
/*!40000 ALTER TABLE `tb_notifikasi` DISABLE KEYS */;
INSERT INTO `tb_notifikasi` VALUES (11,'Nur Huda, S.Pd.I. telah mengisi jurnal mengajar kelas VI','jurnal_mengajar.php',1,'2026-01-24 12:41:47'),(12,'Ah. Mustaqim Isom, A.Ma. telah mengirim kehadiran pada pukul 20:24 tanggal 24-01-2026','absensi_guru.php',1,'2026-01-24 13:24:46');
/*!40000 ALTER TABLE `tb_notifikasi` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_pengguna`
--

DROP TABLE IF EXISTS `tb_pengguna`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_pengguna` (
  `id_pengguna` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `level` enum('admin','guru','wali','kepala_madrasah') NOT NULL DEFAULT 'guru',
  `id_guru` int DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_pengguna`),
  KEY `id_guru` (`id_guru`),
  CONSTRAINT `tb_pengguna_ibfk_1` FOREIGN KEY (`id_guru`) REFERENCES `tb_guru` (`id_guru`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_pengguna`
--

LOCK TABLES `tb_pengguna` WRITE;
/*!40000 ALTER TABLE `tb_pengguna` DISABLE KEYS */;
INSERT INTO `tb_pengguna` VALUES (1,'Admin','$2y$10$AGvfJEpOAtAWYnws4pFgRutCpx3cjBB7tT5OzabhkHgR.HceF7ZIq','admin',NULL,'user_1769126130_LOGO.png'),(2,'Kepala','$2y$10$wKqi4ukTK96K3pUDi5/tq..vwpSS6JsLXOOD28zYfbY3Ceginvd.e','kepala_madrasah',NULL,NULL);
/*!40000 ALTER TABLE `tb_pengguna` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_profil_madrasah`
--

DROP TABLE IF EXISTS `tb_profil_madrasah`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_profil_madrasah` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nama_madrasah` varchar(200) NOT NULL,
  `kepala_madrasah` varchar(100) DEFAULT NULL,
  `tahun_ajaran` varchar(20) DEFAULT NULL,
  `semester` enum('Semester 1','Semester 2') DEFAULT NULL,
  `logo` varchar(100) DEFAULT NULL,
  `dashboard_hero_image` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_profil_madrasah`
--

LOCK TABLES `tb_profil_madrasah` WRITE;
/*!40000 ALTER TABLE `tb_profil_madrasah` DISABLE KEYS */;
INSERT INTO `tb_profil_madrasah` VALUES (1,'MI Sultan Fattah Sukosono','Musriah, S.Pd.I.','2025/2026','Semester 2','logo_1768301957.png','hero_1769291816.jpg');
/*!40000 ALTER TABLE `tb_profil_madrasah` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_siswa`
--

DROP TABLE IF EXISTS `tb_siswa`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_siswa` (
  `id_siswa` int NOT NULL AUTO_INCREMENT,
  `nama_siswa` varchar(100) NOT NULL,
  `nisn` varchar(20) NOT NULL,
  `jenis_kelamin` enum('L','P') DEFAULT NULL,
  `id_kelas` int DEFAULT NULL,
  PRIMARY KEY (`id_siswa`),
  KEY `id_kelas` (`id_kelas`),
  CONSTRAINT `tb_siswa_ibfk_1` FOREIGN KEY (`id_kelas`) REFERENCES `tb_kelas` (`id_kelas`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=134 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_siswa`
--

LOCK TABLES `tb_siswa` WRITE;
/*!40000 ALTER TABLE `tb_siswa` DISABLE KEYS */;
INSERT INTO `tb_siswa` VALUES (22,'ABDULLAH HASAN','3184602457','L',1),(23,'ABIZAR HABIBILLAH','3184275775','L',1),(24,'ADHITAMA ELVAN SYAHREZA','3180229036','L',1),(25,'AHMAD MANUTHO MUHAMMAD','3182663303','L',1),(26,'AIRA ZAHWA SAFIRA','3194980092','P',1),(27,'ARFAN MIYAZ ALINDRA','3182355082','L',1),(28,'DELISA ALYA SAFIQNA','3195153075','P',1),(29,'DIAN AIRA','3195813730','P',1),(30,'DHIRA QALESYA','3184245017','P',1),(31,'HIBAT ALMALIK','3183882033','L',1),(32,'JIHAN FADHILLAH','3194274202','P',1),(33,'KAYLA PUTRI AMALIA','3177681680','P',1),(34,'LAILATUL JANNATU AZZA','3190992049','P',1),(35,'MAUWAFIQ KHOIRUL FAJAR','3172404776','L',1),(36,'NORREIN NABIHA','3198116081','P',1),(37,'RHEVA PUTRI RAMADHANI','3186829907','P',1),(38,'SALMA SHAFIRA RAYYANA','3188013385','P',1),(39,'TUSAMMA SALSABILA','3184514039','P',1),(40,'ABIZARD ALTAN MUTTAQI','3179401623','L',2),(41,'AFWAN SETYO MANGGALA PUTRA','3174187068','L',2),(42,'AIRA DWI MAULIDA','3178039730','P',2),(43,'AKHMAD SONY BINTANG ADITYA','3174857338','L',2),(44,'ALIF SAPUTRA','3181022161','L',2),(45,'ALMAQVIRA AULIA SHALIHA','3175785706','P',2),(46,'AURA LATISHA AQUINA','3181233911','P',2),(47,'HAFIZ AL RASHAAD','3171065604','L',2),(48,'HANIA NATASHA ADINDA AZZAHRA','3170498904','P',2),(49,'MAHIR RIZQI ABDILLAH','3171957808','L',2),(50,'MAULANA SYAFIQ RAMADHAN','3187786956','L',2),(51,'MUHAMMAD AINUN NAJIB','3189060879','L',2),(52,'MUHAMMAD AZKA DHIYA’UL HAQ','3187039124','L',2),(53,'MUHAMMAD HAFIZ MAULANA','3189975601','L',2),(54,'MUHAMMAD MAHDI','3187106516','L',2),(55,'Muhammad Nadaril Saputra','3175823960','L',2),(56,'MUHAMMAD RIZQI MAULANA','3182266699','L',2),(57,'MUHAMMAD SABRIEL RAYYAN','3180027333','L',2),(58,'Nikeisya Sherin Aqila','0177572457','P',2),(59,'NUR ERLINA ARDIRA','3171432750','P',2),(60,'RAHMA SHEKHA ADINDA PUTRI','3176934840','P',2),(61,'Salsabilatun Najah','3184286316','P',2),(62,'SRI HANDAYANI','3174413024','P',2),(63,'ZALFA KHAIRUNNISA','3175059536','P',2),(64,'AKBAR AL HANAN','3162535127','L',3),(65,'ALMA NAFI\'A','3169440189','P',3),(66,'ALZAM MAUZA ALINDRA','3164665566','L',3),(67,'ANNISA MAZROATUL AHSANI','0166159155','P',3),(68,'ARINI NIHAYATUS SHOLIHAH','0163291739','P',3),(69,'AURORA DIYATUL FILARDI','3169539885','P',3),(70,'CITRA DWI LESTARI','3165720620','P',3),(71,'JOVAN PRATAMA','3163104174','L',3),(72,'KAYLA ANANDA SELFIA','3163851089','P',3),(73,'LAILATUZZAHRA','3168585316','P',3),(74,'MUHAMMAD ARDIAN ERFA SAPUTRA','3168502077','L',3),(75,'MUHAMMAD NUR YUSUF','3169048580','L',3),(76,'NUR ALIFATUL ZAHIRA','3177478000','P',3),(77,'RAFA RASYIQUL UMAR','3165088631','L',3),(78,'REVA RAMADHANI','3163117591','P',3),(79,'RIZQY AWWALUN PUTRA AHMAD','3168692801','L',3),(80,'SRI AISYAH AILANI ARKA','0166304991','P',3),(81,'SYIFANA AWWALIYA','3162918924','P',3),(82,'TITANIA WICENZA SETYA','3166299389','P',3),(83,'VEBY STEVANIE','3151044603','P',3),(84,'AHMAD DAFA SAPUTRA','3157310312','L',4),(85,'ANDHARA NAURA LATIFAH','0159344416','P',4),(86,'ASTI DAYINTA ELOK WIGUNA','3138636681','P',4),(87,'AZKA ARDA YOGA','0158273255','L',4),(88,'BATARI ARSYAVIDYA AHMAD','0136511810','P',4),(89,'FADLIL A\'LA','3156182504','L',4),(90,'HAYU FALISHA LAIL','0159328720','P',4),(91,'MAFATIHUL KHOIR','0154732385','P',4),(92,'MUHAMMAD ALGA NOVA','3151306061','L',4),(93,'NILAM CAHYA RANI','3154268018','P',4),(94,'NOR ALVIAN AZ ZAHRAN','0155498460','L',4),(95,'QIYANU JABAR MASA\'ID','3164754146','L',4),(96,'RATU VIOLA ZAZKIA ANNISA','3155089697','P',4),(97,'AHMAD ARJUNNAJATA ILAMAULA','0146901301','L',5),(98,'AHMAD ARSYIL ROHAN','0141948658','L',5),(99,'ANGGITA CITRA ANINDITA','0153488311','P',5),(100,'BILQIS ANINDITA HUDA','3153353973','P',5),(101,'EVA FANI VEBRIANI','3140721776','P',5),(102,'FARID LINTANG ARDIANSYAH','0144317238','L',5),(103,'FURJAH SABDA KENCANA','0144565120','P',5),(104,'MUHAMMAD BAYU ALHABSYI HIKMAWAN','0146016714','L',5),(105,'MUHAMMAD FAHRI ARDIANSYAH','3148398924','L',5),(106,'MUHAMMAD MIRZA RAMADHAN','0134799480','L',5),(107,'MUHAMMAD NAILUL FAIZ','0145967375','L',5),(108,'MUHAMMAD ROMY PRAYADINATA','0143035500','L',5),(109,'MUHAMMAD SAMSUL AD\'N','3140219560','L',5),(110,'NAF\'AN KAIS AHMAD','3140985027','L',5),(111,'Rizal Arahman','3130235731','L',5),(112,'SAILIN ANJA SAFARA','0141467581','P',5),(113,'SEKAR NURI MAULIDA','3132469215','P',5),(114,'ZANETA AQILA ANDIENAMIRA','3146697836','P',5),(115,'ADIBA NUHA AZZAHRA','3146588936','P',6),(116,'Amrina Rosyada','3137563185','P',6),(117,'Aqilah Khoirurrosyadah','3135628625','P',6),(118,'Bilqis Fahiya Rifda','3132163433','P',6),(119,'Dewi Khuzaimah Annisa','3138275600','P',6),(120,'Diyah Ayu Prawesti','0137840437','P',6),(121,'Dzakira Talita Azzahra','3137847985','P',6),(122,'Fabregas Alviano','3133372371','L',6),(123,'Indana Zulfa','3146510193','P',6),(124,'Lidia Aura Citra','3133041280','P',6),(125,'Muhammad Agung Susilo Sugiono','3149297726','L',6),(126,'Muhammad Daris Alfurqon Aqim','3130180823','L',6),(127,'Muhammad Egi Ferdiansyah','3140702123','L',6),(128,'Muhammad Elga Saputra','3130250384','L',6),(129,'Najwah Fadia Amalia Fitri','3141710676','P',6),(130,'Putra Sadewa Saifunnawas','3136264986','L',6),(131,'Rizquna Halalan Thoyyiba','3137207114','P',6),(132,'Siti Afifah Nauvalyn Fikriyah','3131634863','P',6),(133,'Siti Mei Listiana','3139561428','P',6);
/*!40000 ALTER TABLE `tb_siswa` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-01-25  5:07:41
