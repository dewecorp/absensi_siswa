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
) ENGINE=InnoDB AUTO_INCREMENT=370 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_absensi`
--

LOCK TABLES `tb_absensi` WRITE;
/*!40000 ALTER TABLE `tb_absensi` DISABLE KEYS */;
INSERT INTO `tb_absensi` VALUES (56,22,'2026-01-16',NULL,NULL,'Hadir',NULL),(57,23,'2026-01-16',NULL,NULL,'Hadir',NULL),(58,24,'2026-01-16',NULL,NULL,'Hadir',NULL),(59,25,'2026-01-16',NULL,NULL,'Hadir',NULL),(60,26,'2026-01-16',NULL,NULL,'Hadir',NULL),(61,27,'2026-01-16',NULL,NULL,'Hadir',NULL),(62,28,'2026-01-16',NULL,NULL,'Hadir',NULL),(63,29,'2026-01-16',NULL,NULL,'Hadir',NULL),(64,30,'2026-01-16',NULL,NULL,'Sakit',NULL),(65,31,'2026-01-16',NULL,NULL,'Hadir',NULL),(66,32,'2026-01-16',NULL,NULL,'Hadir',NULL),(67,33,'2026-01-16',NULL,NULL,'Hadir',NULL),(68,34,'2026-01-16',NULL,NULL,'Hadir',NULL),(69,35,'2026-01-16',NULL,NULL,'Hadir',NULL),(70,36,'2026-01-16',NULL,NULL,'Sakit',NULL),(71,37,'2026-01-16',NULL,NULL,'Hadir',NULL),(72,38,'2026-01-16',NULL,NULL,'Hadir',NULL),(73,39,'2026-01-16',NULL,NULL,'Alpa',NULL),(74,40,'2026-01-16',NULL,NULL,'Hadir',NULL),(75,41,'2026-01-16',NULL,NULL,'Hadir',NULL),(76,42,'2026-01-16',NULL,NULL,'Hadir',NULL),(77,43,'2026-01-16',NULL,NULL,'Hadir',NULL),(78,44,'2026-01-16',NULL,NULL,'Hadir',NULL),(79,45,'2026-01-16',NULL,NULL,'Hadir',NULL),(80,46,'2026-01-16',NULL,NULL,'Hadir',NULL),(81,47,'2026-01-16',NULL,NULL,'Hadir',NULL),(82,48,'2026-01-16',NULL,NULL,'Hadir',NULL),(83,49,'2026-01-16',NULL,NULL,'Hadir',NULL),(84,50,'2026-01-16',NULL,NULL,'Hadir',NULL),(85,51,'2026-01-16',NULL,NULL,'Hadir',NULL),(86,52,'2026-01-16',NULL,NULL,'Hadir',NULL),(87,53,'2026-01-16',NULL,NULL,'Hadir',NULL),(88,54,'2026-01-16',NULL,NULL,'Hadir',NULL),(89,55,'2026-01-16',NULL,NULL,'Hadir',NULL),(90,56,'2026-01-16',NULL,NULL,'Hadir',NULL),(91,57,'2026-01-16',NULL,NULL,'Hadir',NULL),(92,58,'2026-01-16',NULL,NULL,'Hadir',NULL),(93,59,'2026-01-16',NULL,NULL,'Hadir',NULL),(94,60,'2026-01-16',NULL,NULL,'Hadir',NULL),(95,61,'2026-01-16',NULL,NULL,'Hadir',NULL),(96,62,'2026-01-16',NULL,NULL,'Hadir',NULL),(97,63,'2026-01-16',NULL,NULL,'Hadir',NULL),(98,64,'2026-01-16',NULL,NULL,'Hadir',NULL),(99,65,'2026-01-16',NULL,NULL,'Sakit',NULL),(100,66,'2026-01-16',NULL,NULL,'Hadir',NULL),(101,67,'2026-01-16',NULL,NULL,'Hadir',NULL),(102,68,'2026-01-16',NULL,NULL,'Hadir',NULL),(103,69,'2026-01-16',NULL,NULL,'Hadir',NULL),(104,70,'2026-01-16',NULL,NULL,'Hadir',NULL),(105,71,'2026-01-16',NULL,NULL,'Hadir',NULL),(106,72,'2026-01-16',NULL,NULL,'Hadir',NULL),(107,73,'2026-01-16',NULL,NULL,'Hadir',NULL),(108,74,'2026-01-16',NULL,NULL,'Hadir',NULL),(109,75,'2026-01-16',NULL,NULL,'Hadir',NULL),(110,76,'2026-01-16',NULL,NULL,'Hadir',NULL),(111,77,'2026-01-16',NULL,NULL,'Hadir',NULL),(112,78,'2026-01-16',NULL,NULL,'Hadir',NULL),(113,79,'2026-01-16',NULL,NULL,'Hadir',NULL),(114,80,'2026-01-16',NULL,NULL,'Hadir',NULL),(115,81,'2026-01-16',NULL,NULL,'Hadir',NULL),(116,82,'2026-01-16',NULL,NULL,'Hadir',NULL),(117,83,'2026-01-16',NULL,NULL,'Hadir',NULL),(118,84,'2026-01-16',NULL,NULL,'Hadir',NULL),(119,85,'2026-01-16',NULL,NULL,'Hadir',NULL),(120,86,'2026-01-16',NULL,NULL,'Hadir',NULL),(121,87,'2026-01-16',NULL,NULL,'Hadir',NULL),(122,88,'2026-01-16',NULL,NULL,'Hadir',NULL),(123,89,'2026-01-16',NULL,NULL,'Hadir',NULL),(124,90,'2026-01-16',NULL,NULL,'Hadir',NULL),(125,91,'2026-01-16',NULL,NULL,'Hadir',NULL),(126,92,'2026-01-16',NULL,NULL,'Hadir',NULL),(127,93,'2026-01-16',NULL,NULL,'Hadir',NULL),(128,94,'2026-01-16',NULL,NULL,'Hadir',NULL),(129,95,'2026-01-16',NULL,NULL,'Hadir',NULL),(130,96,'2026-01-16',NULL,NULL,'Alpa',NULL),(131,97,'2026-01-16',NULL,NULL,'Hadir',NULL),(132,98,'2026-01-16',NULL,NULL,'Hadir',NULL),(133,99,'2026-01-16',NULL,NULL,'Hadir',NULL),(134,100,'2026-01-16',NULL,NULL,'Hadir',NULL),(135,101,'2026-01-16',NULL,NULL,'Hadir',NULL),(136,102,'2026-01-16',NULL,NULL,'Hadir',NULL),(137,103,'2026-01-16',NULL,NULL,'Alpa',NULL),(138,104,'2026-01-16',NULL,NULL,'Hadir',NULL),(139,105,'2026-01-16',NULL,NULL,'Izin',NULL),(140,106,'2026-01-16',NULL,NULL,'Hadir',NULL),(141,107,'2026-01-16',NULL,NULL,'Hadir',NULL),(142,108,'2026-01-16',NULL,NULL,'Hadir',NULL),(143,109,'2026-01-16',NULL,NULL,'Hadir',NULL),(144,110,'2026-01-16',NULL,NULL,'Hadir',NULL),(145,111,'2026-01-16',NULL,NULL,'Hadir',NULL),(146,112,'2026-01-16',NULL,NULL,'Hadir',NULL),(147,113,'2026-01-16',NULL,NULL,'Alpa',NULL),(148,114,'2026-01-16',NULL,NULL,'Hadir',NULL),(149,115,'2026-01-16',NULL,NULL,'Hadir',NULL),(150,116,'2026-01-16',NULL,NULL,'Hadir',NULL),(151,117,'2026-01-16',NULL,NULL,'Hadir',NULL),(152,118,'2026-01-16',NULL,NULL,'Hadir',NULL),(153,119,'2026-01-16',NULL,NULL,'Hadir',NULL),(154,120,'2026-01-16',NULL,NULL,'Hadir',NULL),(155,121,'2026-01-16',NULL,NULL,'Hadir',NULL),(156,122,'2026-01-16',NULL,NULL,'Hadir',NULL),(157,123,'2026-01-16',NULL,NULL,'Hadir',NULL),(158,124,'2026-01-16',NULL,NULL,'Hadir',NULL),(159,125,'2026-01-16',NULL,NULL,'Hadir',NULL),(160,126,'2026-01-16',NULL,NULL,'Hadir',NULL),(161,127,'2026-01-16',NULL,NULL,'Hadir',NULL),(162,128,'2026-01-16',NULL,NULL,'Alpa',NULL),(163,129,'2026-01-16',NULL,NULL,'Hadir',NULL),(164,130,'2026-01-16',NULL,NULL,'Hadir',NULL),(165,131,'2026-01-16',NULL,NULL,'Hadir',NULL),(166,132,'2026-01-16',NULL,NULL,'Hadir',NULL),(167,133,'2026-01-16',NULL,NULL,'Hadir',NULL),(168,125,'2026-01-22',NULL,NULL,'Hadir',NULL),(169,126,'2026-01-22',NULL,NULL,'Hadir',NULL),(170,127,'2026-01-22',NULL,NULL,'Hadir',NULL),(171,128,'2026-01-22',NULL,NULL,'Hadir',NULL),(172,129,'2026-01-22',NULL,NULL,'Hadir',NULL),(173,130,'2026-01-22',NULL,NULL,'Hadir',NULL),(174,131,'2026-01-22',NULL,NULL,'Hadir',NULL),(175,132,'2026-01-22',NULL,NULL,'Hadir',NULL),(176,133,'2026-01-22',NULL,NULL,'Izin',NULL),(177,115,'2026-01-22',NULL,NULL,'Hadir',NULL),(178,116,'2026-01-22',NULL,NULL,'Hadir',NULL),(179,117,'2026-01-22',NULL,NULL,'Hadir',NULL),(180,118,'2026-01-22',NULL,NULL,'Hadir',NULL),(181,119,'2026-01-22',NULL,NULL,'Hadir',NULL),(182,120,'2026-01-22',NULL,NULL,'Sakit',NULL),(183,121,'2026-01-22',NULL,NULL,'Hadir',NULL),(184,122,'2026-01-22',NULL,NULL,'Hadir',NULL),(185,123,'2026-01-22',NULL,NULL,'Hadir',NULL),(186,124,'2026-01-22',NULL,NULL,'Hadir',NULL),(187,22,'2026-01-22',NULL,NULL,'Hadir',24),(188,23,'2026-01-22',NULL,NULL,'Hadir',24),(189,24,'2026-01-22',NULL,NULL,'Hadir',24),(190,25,'2026-01-22',NULL,NULL,'Hadir',24),(191,26,'2026-01-22',NULL,NULL,'Hadir',24),(192,27,'2026-01-22',NULL,NULL,'Hadir',24),(193,28,'2026-01-22',NULL,NULL,'Hadir',24),(194,30,'2026-01-22',NULL,NULL,'Hadir',24),(195,29,'2026-01-22',NULL,NULL,'Hadir',24),(196,31,'2026-01-22',NULL,NULL,'Hadir',24),(197,32,'2026-01-22',NULL,NULL,'Hadir',24),(198,33,'2026-01-22',NULL,NULL,'Hadir',24),(199,34,'2026-01-22',NULL,NULL,'Hadir',24),(200,35,'2026-01-22',NULL,NULL,'Hadir',24),(201,36,'2026-01-22',NULL,NULL,'Hadir',24),(202,37,'2026-01-22',NULL,NULL,'Hadir',24),(203,38,'2026-01-22',NULL,NULL,'Hadir',24),(204,39,'2026-01-22',NULL,NULL,'Hadir',24),(205,97,'2026-01-22',NULL,NULL,'Hadir',20),(206,98,'2026-01-22',NULL,NULL,'Hadir',20),(207,99,'2026-01-22',NULL,NULL,'Hadir',20),(208,100,'2026-01-22',NULL,NULL,'Hadir',20),(209,101,'2026-01-22',NULL,NULL,'Hadir',20),(210,102,'2026-01-22',NULL,NULL,'Hadir',20),(211,103,'2026-01-22',NULL,NULL,'Hadir',20),(212,104,'2026-01-22',NULL,NULL,'Hadir',20),(213,105,'2026-01-22',NULL,NULL,'Hadir',20),(214,106,'2026-01-22',NULL,NULL,'Hadir',20),(215,107,'2026-01-22',NULL,NULL,'Hadir',20),(216,108,'2026-01-22',NULL,NULL,'Hadir',20),(217,109,'2026-01-22',NULL,NULL,'Hadir',20),(218,110,'2026-01-22',NULL,NULL,'Hadir',20),(219,111,'2026-01-22',NULL,NULL,'Hadir',20),(220,112,'2026-01-22',NULL,NULL,'Hadir',20),(221,113,'2026-01-22',NULL,NULL,'Hadir',20),(222,114,'2026-01-22',NULL,NULL,'Hadir',20),(223,115,'2026-01-23',NULL,NULL,'Hadir',19),(224,116,'2026-01-23',NULL,NULL,'Hadir',19),(225,117,'2026-01-23',NULL,NULL,'Hadir',19),(226,118,'2026-01-23',NULL,NULL,'Hadir',19),(227,119,'2026-01-23',NULL,NULL,'Hadir',19),(228,120,'2026-01-23',NULL,NULL,'Hadir',19),(229,121,'2026-01-23',NULL,NULL,'Hadir',19),(230,122,'2026-01-23',NULL,NULL,'Sakit',19),(231,123,'2026-01-23',NULL,NULL,'Hadir',19),(232,124,'2026-01-23',NULL,NULL,'Hadir',19),(233,125,'2026-01-23',NULL,NULL,'Hadir',19),(234,126,'2026-01-23',NULL,NULL,'Hadir',19),(235,127,'2026-01-23',NULL,NULL,'Hadir',19),(236,128,'2026-01-23',NULL,NULL,'Hadir',19),(237,129,'2026-01-23',NULL,NULL,'Hadir',19),(238,130,'2026-01-23',NULL,NULL,'Izin',19),(239,131,'2026-01-23',NULL,NULL,'Hadir',19),(240,132,'2026-01-23',NULL,NULL,'Hadir',19),(241,133,'2026-01-23',NULL,NULL,'Hadir',19),(242,40,'2026-01-23',NULL,NULL,'Hadir',24),(243,41,'2026-01-23',NULL,NULL,'Hadir',24),(244,42,'2026-01-23',NULL,NULL,'Hadir',24),(245,43,'2026-01-23',NULL,NULL,'Hadir',24),(246,44,'2026-01-23',NULL,NULL,'Hadir',24),(247,45,'2026-01-23',NULL,NULL,'Hadir',24),(248,46,'2026-01-23',NULL,NULL,'Hadir',24),(249,47,'2026-01-23',NULL,NULL,'Hadir',24),(250,48,'2026-01-23',NULL,NULL,'Hadir',24),(251,49,'2026-01-23',NULL,NULL,'Sakit',24),(252,50,'2026-01-23',NULL,NULL,'Hadir',24),(253,51,'2026-01-23',NULL,NULL,'Hadir',24),(254,52,'2026-01-23',NULL,NULL,'Hadir',24),(255,53,'2026-01-23',NULL,NULL,'Hadir',24),(256,54,'2026-01-23',NULL,NULL,'Hadir',24),(257,55,'2026-01-23',NULL,NULL,'Hadir',24),(258,56,'2026-01-23',NULL,NULL,'Hadir',24),(259,57,'2026-01-23',NULL,NULL,'Hadir',24),(260,58,'2026-01-23',NULL,NULL,'Hadir',24),(261,59,'2026-01-23',NULL,NULL,'Hadir',24),(262,60,'2026-01-23',NULL,NULL,'Hadir',24),(263,61,'2026-01-23',NULL,NULL,'Hadir',24),(264,62,'2026-01-23',NULL,NULL,'Hadir',24),(265,63,'2026-01-23',NULL,NULL,'Hadir',24),(266,97,'2026-01-23',NULL,NULL,'Hadir',20),(267,98,'2026-01-23',NULL,NULL,'Hadir',20),(268,99,'2026-01-23',NULL,NULL,'Hadir',20),(269,100,'2026-01-23',NULL,NULL,'Hadir',20),(270,101,'2026-01-23',NULL,NULL,'Hadir',20),(271,102,'2026-01-23',NULL,NULL,'Hadir',20),(272,103,'2026-01-23',NULL,NULL,'Hadir',20),(273,104,'2026-01-23',NULL,NULL,'Hadir',20),(274,105,'2026-01-23',NULL,NULL,'Sakit',20),(275,106,'2026-01-23',NULL,NULL,'Hadir',20),(276,107,'2026-01-23',NULL,NULL,'Hadir',20),(277,108,'2026-01-23',NULL,NULL,'Hadir',20),(278,109,'2026-01-23',NULL,NULL,'Hadir',20),(279,110,'2026-01-23',NULL,NULL,'Hadir',20),(280,111,'2026-01-23',NULL,NULL,'Hadir',20),(281,112,'2026-01-23',NULL,NULL,'Hadir',20),(282,113,'2026-01-23',NULL,NULL,'Hadir',20),(283,114,'2026-01-23',NULL,NULL,'Hadir',20),(284,22,'2026-01-23',NULL,NULL,'Hadir',NULL),(285,23,'2026-01-23',NULL,NULL,'Hadir',NULL),(286,24,'2026-01-23',NULL,NULL,'Hadir',NULL),(287,25,'2026-01-23',NULL,NULL,'Hadir',NULL),(288,26,'2026-01-23',NULL,NULL,'Hadir',NULL),(289,27,'2026-01-23',NULL,NULL,'Hadir',NULL),(290,28,'2026-01-23',NULL,NULL,'Hadir',NULL),(291,30,'2026-01-23',NULL,NULL,'Hadir',NULL),(292,29,'2026-01-23',NULL,NULL,'Hadir',NULL),(293,31,'2026-01-23',NULL,NULL,'Hadir',NULL),(294,32,'2026-01-23',NULL,NULL,'Hadir',NULL),(295,33,'2026-01-23',NULL,NULL,'Hadir',NULL),(296,34,'2026-01-23',NULL,NULL,'Hadir',NULL),(297,35,'2026-01-23',NULL,NULL,'Hadir',NULL),(298,36,'2026-01-23',NULL,NULL,'Hadir',NULL),(299,37,'2026-01-23',NULL,NULL,'Hadir',NULL),(300,38,'2026-01-23',NULL,NULL,'Hadir',NULL),(301,39,'2026-01-23',NULL,NULL,'Hadir',NULL),(302,115,'2026-01-24',NULL,NULL,'Hadir',NULL),(303,116,'2026-01-24',NULL,NULL,'Hadir',NULL),(304,117,'2026-01-24',NULL,NULL,'Hadir',NULL),(305,118,'2026-01-24',NULL,NULL,'Izin',NULL),(306,119,'2026-01-24',NULL,NULL,'Hadir',NULL),(307,120,'2026-01-24',NULL,NULL,'Hadir',NULL),(308,121,'2026-01-24',NULL,NULL,'Hadir',NULL),(309,122,'2026-01-24',NULL,NULL,'Hadir',NULL),(310,123,'2026-01-24',NULL,NULL,'Hadir',NULL),(311,124,'2026-01-24',NULL,NULL,'Hadir',NULL),(312,125,'2026-01-24',NULL,NULL,'Hadir',NULL),(313,126,'2026-01-24',NULL,NULL,'Hadir',NULL),(314,127,'2026-01-24',NULL,NULL,'Hadir',NULL),(315,128,'2026-01-24',NULL,NULL,'Hadir',NULL),(316,129,'2026-01-24',NULL,NULL,'Hadir',NULL),(317,130,'2026-01-24',NULL,NULL,'Hadir',NULL),(318,131,'2026-01-24',NULL,NULL,'Hadir',NULL),(319,132,'2026-01-24',NULL,NULL,'Hadir',NULL),(320,133,'2026-01-24',NULL,NULL,'Hadir',NULL),(321,115,'2026-01-25',NULL,NULL,'Hadir',NULL),(322,116,'2026-01-25',NULL,NULL,'Hadir',NULL),(323,117,'2026-01-25',NULL,NULL,'Hadir',NULL),(324,118,'2026-01-25',NULL,NULL,'Hadir',NULL),(325,119,'2026-01-25',NULL,NULL,'Hadir',NULL),(326,120,'2026-01-25',NULL,NULL,'Hadir',NULL),(327,121,'2026-01-25',NULL,NULL,'Hadir',NULL),(328,122,'2026-01-25',NULL,NULL,'Hadir',NULL),(329,123,'2026-01-25',NULL,NULL,'Hadir',NULL),(330,124,'2026-01-25',NULL,NULL,'Hadir',NULL),(331,125,'2026-01-25',NULL,NULL,'Hadir',NULL),(332,126,'2026-01-25',NULL,NULL,'Hadir',NULL),(333,127,'2026-01-25',NULL,NULL,'Hadir',NULL),(334,128,'2026-01-25',NULL,NULL,'Hadir',NULL),(335,129,'2026-01-25',NULL,NULL,'Hadir',NULL),(336,130,'2026-01-25',NULL,NULL,'Hadir',NULL),(337,131,'2026-01-25',NULL,NULL,'Hadir',NULL),(338,132,'2026-01-25',NULL,NULL,'Hadir',NULL),(339,133,'2026-01-25',NULL,NULL,'Hadir',NULL),(340,22,'2026-01-25','11:38:41',NULL,'Hadir',NULL),(341,23,'2026-01-25',NULL,NULL,'Hadir',NULL),(342,25,'2026-01-25',NULL,NULL,'Hadir',NULL),(343,27,'2026-01-25',NULL,NULL,'Hadir',NULL),(344,26,'2026-01-25',NULL,NULL,'Hadir',NULL),(345,24,'2026-01-25',NULL,NULL,'Hadir',NULL),(346,100,'2026-01-25','17:53:38',NULL,'Hadir',NULL),(347,98,'2026-01-25','17:53:45',NULL,'Hadir',NULL),(348,97,'2026-01-25','17:53:55',NULL,'Hadir',NULL),(349,99,'2026-01-25','17:54:01',NULL,'Hadir',NULL),(350,101,'2026-01-25','17:54:22',NULL,'Hadir',NULL),(351,125,'2026-01-26',NULL,NULL,'Sakit',NULL),(352,126,'2026-01-26',NULL,NULL,'Hadir',NULL),(353,127,'2026-01-26',NULL,NULL,'Hadir',NULL),(354,128,'2026-01-26',NULL,NULL,'Hadir',NULL),(355,129,'2026-01-26',NULL,NULL,'Hadir',NULL),(356,130,'2026-01-26',NULL,NULL,'Hadir',NULL),(357,131,'2026-01-26',NULL,NULL,'Hadir',NULL),(358,132,'2026-01-26',NULL,NULL,'Hadir',NULL),(359,133,'2026-01-26',NULL,NULL,'Hadir',NULL),(360,115,'2026-01-26',NULL,NULL,'Alpa',NULL),(361,116,'2026-01-26',NULL,NULL,'Alpa',NULL),(362,117,'2026-01-26',NULL,NULL,'Hadir',NULL),(363,118,'2026-01-26',NULL,NULL,'Hadir',NULL),(364,119,'2026-01-26',NULL,NULL,'Hadir',NULL),(365,120,'2026-01-26',NULL,NULL,'Hadir',NULL),(366,121,'2026-01-26',NULL,NULL,'Hadir',NULL),(367,122,'2026-01-26',NULL,NULL,'Hadir',NULL),(368,123,'2026-01-26',NULL,NULL,'Hadir',NULL),(369,124,'2026-01-26',NULL,NULL,'Hadir',NULL);
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
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_absensi_guru`
--

LOCK TABLES `tb_absensi_guru` WRITE;
/*!40000 ALTER TABLE `tb_absensi_guru` DISABLE KEYS */;
INSERT INTO `tb_absensi_guru` VALUES (1,20,'2026-01-22','hadir','','2026-01-22 19:33:34'),(2,30,'2026-01-22','hadir','','2026-01-22 19:33:34'),(3,19,'2026-01-22','izin','pindah hari','2026-01-22 15:06:43'),(4,24,'2026-01-22','hadir','','2026-01-22 15:36:35'),(5,29,'2026-01-22','hadir','','2026-01-22 15:36:08'),(6,23,'2026-01-22','sakit','sakit panas','2026-01-22 15:36:35'),(7,28,'2026-01-22','hadir','','2026-01-22 15:36:35'),(8,22,'2026-01-22','hadir','','2026-01-22 15:36:47'),(9,26,'2026-01-22','hadir','','2026-01-22 15:36:47'),(10,21,'2026-01-22','alpa','','2026-01-22 19:25:33'),(11,27,'2026-01-22','hadir','','2026-01-22 19:25:33'),(12,18,'2026-01-22','hadir','','2026-01-22 19:33:33'),(13,25,'2026-01-22','hadir','','2026-01-22 19:33:34'),(14,19,'2026-01-23','hadir','','2026-01-23 04:17:25'),(15,24,'2026-01-23','hadir','','2026-01-23 05:59:16'),(16,20,'2026-01-23','hadir','','2026-01-23 06:07:59'),(17,20,'2026-01-24','hadir','','2026-01-24 19:38:58'),(18,30,'2026-01-24','hadir','','2026-01-24 19:38:58'),(19,19,'2026-01-24','hadir','','2026-01-24 19:38:59'),(20,24,'2026-01-24','hadir','','2026-01-24 20:30:41'),(21,29,'2026-01-24','hadir','','2026-01-24 20:30:41'),(22,20,'2026-01-25','hadir','','2026-01-25 13:33:56'),(23,30,'2026-01-25','alpa','','2026-01-25 13:33:56'),(24,19,'2026-01-25','hadir','','2026-01-25 04:27:26'),(25,18,'2026-01-25','hadir','','2026-01-25 13:33:55'),(26,21,'2026-01-25','hadir','','2026-01-25 13:33:33'),(27,25,'2026-01-25','alpa','','2026-01-25 13:33:56'),(28,22,'2026-01-25','hadir','','2026-01-25 16:14:46'),(29,23,'2026-01-25','hadir','','2026-01-25 16:17:54'),(30,19,'2026-01-26','hadir','','2026-01-26 07:20:17');
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
) ENGINE=InnoDB AUTO_INCREMENT=448 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_activity_log`
--

LOCK TABLES `tb_activity_log` WRITE;
/*!40000 ALTER TABLE `tb_activity_log` DISABLE KEYS */;
INSERT INTO `tb_activity_log` VALUES (379,'Admin','Login','User logged in successfully','127.0.0.1','2026-01-25 22:47:56'),(380,'Admin','Tambah Mata Pelajaran','Menambahkan mapel Kepramukaan','127.0.0.1','2026-01-25 22:50:16'),(381,'Admin','Input Absensi','Admin Admin melakukan input absensi harian kelas ID: 6 untuk 19 siswa','127.0.0.1','2026-01-26 00:16:59'),(382,'Admin','Logout','User logged out from admin session','127.0.0.1','2026-01-26 00:20:01'),(383,'Admin','Login','User logged in successfully','127.0.0.1','2026-01-26 00:20:04'),(384,'Admin','Logout','User logged out from admin session','127.0.0.1','2026-01-26 00:20:08'),(385,'5436757658200002','Login','Teacher logged in successfully using NUPTK','127.0.0.1','2026-01-26 00:20:10'),(386,'Nur Huda, S.Pd.I.','Absensi Guru','Nur Huda, S.Pd.I. (Wali) memperbarui kehadiran: hadir','127.0.0.1','2026-01-26 00:20:17'),(387,'5436757658200002','Logout','User logged out from wali session','127.0.0.1','2026-01-26 00:22:43'),(388,'Admin','Login','User logged in successfully','127.0.0.1','2026-01-26 00:22:46'),(389,'Admin','Logout','User logged out from admin session','127.0.0.1','2026-01-26 00:23:30'),(390,'5436757658200002','Login','Teacher logged in successfully using NUPTK','127.0.0.1','2026-01-26 00:23:36'),(391,'5436757658200002','Logout','User logged out from wali session','127.0.0.1','2026-01-26 00:49:24'),(392,'2640755657300002','Login','Teacher logged in successfully using NUPTK','127.0.0.1','2026-01-26 00:49:29'),(393,'2640755657300002','Logout','User logged out from guru session','127.0.0.1','2026-01-26 00:50:29'),(394,'Admin','Login','User logged in successfully','127.0.0.1','2026-01-26 00:50:33'),(395,'Admin','Input Absensi','Admin Admin melakukan input absensi harian kelas ID: 6 untuk 19 siswa','127.0.0.1','2026-01-26 01:22:49'),(396,'Admin','Update Guru','Memperbarui data guru: Abdul Ghofur, S.Pd.I','127.0.0.1','2026-01-26 01:54:51'),(397,'Admin','Update Guru','Memperbarui data guru: Ah. Mustaqim Isom, A.Ma.','127.0.0.1','2026-01-26 01:55:10'),(398,'Admin','Update Guru','Memperbarui data guru: Alfina Martha Sintya, S.Pd.','127.0.0.1','2026-01-26 01:58:10'),(399,'Admin','Update Guru','Memperbarui data guru: Ali Yasin, S.Pd.I','127.0.0.1','2026-01-26 01:58:26'),(400,'Admin','Update Guru','Memperbarui data guru: Hamidah, A.Ma.','127.0.0.1','2026-01-26 01:58:39'),(401,'Admin','Update Guru','Memperbarui data guru: Indasah, A.Ma.','127.0.0.1','2026-01-26 01:58:52'),(402,'Admin','Update Guru','Memperbarui data guru: Khoiruddin, S.Pd.','127.0.0.1','2026-01-26 01:59:07'),(403,'Admin','Update Guru','Memperbarui data guru: Muhamad Junaedi','127.0.0.1','2026-01-26 01:59:23'),(404,'Admin','Update Guru','Memperbarui data guru: Musri`ah, S.Pd.I','127.0.0.1','2026-01-26 01:59:36'),(405,'Admin','Update Guru','Memperbarui data guru: Nanik Purwati, S.Pd.I','127.0.0.1','2026-01-26 01:59:51'),(406,'Admin','Update Guru','Memperbarui data guru: Nur Hidah, S.Pd.I.','127.0.0.1','2026-01-26 02:00:14'),(407,'Admin','Update Guru','Memperbarui data guru: Nur Huda, S.Pd.I.','127.0.0.1','2026-01-26 02:00:33'),(408,'Admin','Update Guru','Memperbarui data guru: Zama`ah, S.Pd.I.','127.0.0.1','2026-01-26 02:00:47'),(409,'Admin','Login','User logged in successfully','127.0.0.1','2026-01-26 02:38:38'),(410,'Admin','Update Mata Pelajaran','Update mapel ID 3 menjadi Akidah Akhlak (2)','127.0.0.1','2026-01-26 02:51:08'),(411,'Admin','Update Mata Pelajaran','Update mapel ID 4 menjadi Al-Quran Hadis (1)','127.0.0.1','2026-01-26 02:51:15'),(412,'Admin','Update Mata Pelajaran','Update mapel ID 2 menjadi Bahasa Arab (5)','127.0.0.1','2026-01-26 02:51:32'),(413,'Admin','Update Mata Pelajaran','Update mapel ID 1 menjadi Bahasa Indonesia (7)','127.0.0.1','2026-01-26 02:51:45'),(414,'Admin','Update Mata Pelajaran','Update mapel ID 10 menjadi Bahasa Inggris (14)','127.0.0.1','2026-01-26 02:51:57'),(415,'Admin','Update Mata Pelajaran','Update mapel ID 15 menjadi Bahasa Jawa (13)','127.0.0.1','2026-01-26 02:52:11'),(416,'Admin','Update Mata Pelajaran','Update mapel ID 14 menjadi BTA (15)','127.0.0.1','2026-01-26 02:52:24'),(417,'Admin','Update Mata Pelajaran','Update mapel ID 16 menjadi Fikih (3)','127.0.0.1','2026-01-26 02:52:40'),(418,'Admin','Update Mata Pelajaran','Update mapel ID 7 menjadi IPAS (20)','127.0.0.1','2026-01-26 02:52:54'),(419,'Admin','Update Mata Pelajaran','Update mapel ID 12 menjadi Ke-NU-an (16)','127.0.0.1','2026-01-26 02:53:05'),(420,'Admin','Update Mata Pelajaran','Update mapel ID 12 menjadi Ke-NU-an (16)','127.0.0.1','2026-01-26 02:56:20'),(421,'Admin','Update Mata Pelajaran','Update mapel ID 17 menjadi Kepramukaan (19)','127.0.0.1','2026-01-26 02:56:49'),(422,'Admin','Update Mata Pelajaran','Update mapel ID 9 menjadi Matematika (10)','127.0.0.1','2026-01-26 02:57:05'),(423,'Admin','Update Mata Pelajaran','Update mapel ID 6 menjadi Pendidikan Pancasila (6)','127.0.0.1','2026-01-26 02:57:18'),(424,'Admin','Update Mata Pelajaran','Update mapel ID 8 menjadi PJOK (11)','127.0.0.1','2026-01-26 02:57:30'),(425,'Admin','Update Mata Pelajaran','Update mapel ID 5 menjadi Sejarah Kebudayaan Islam (4)','127.0.0.1','2026-01-26 02:57:45'),(426,'Admin','Update Mata Pelajaran','Update mapel ID 11 menjadi Seni Budaya (12)','127.0.0.1','2026-01-26 02:57:57'),(427,'Admin','Update Mata Pelajaran','Update mapel ID 13 menjadi Tajwid (17)','127.0.0.1','2026-01-26 02:58:12'),(428,'Admin','Tambah Jam Mengajar','Menambahkan jam ke-1 (11:45 - 11:45) [Ramadhan]','127.0.0.1','2026-01-26 04:38:57'),(429,'Admin','Tambah Mata Pelajaran','Menambahkan mapel Asmaul Husna dan Tadarrus (A)','127.0.0.1','2026-01-26 05:32:50'),(430,'Admin','Tambah Mata Pelajaran','Menambahkan mapel Upacara Bendera (B)','127.0.0.1','2026-01-26 05:33:18'),(431,'Admin','Tambah Jam Mengajar','Menambahkan jam ke-A (06:50 - 07:10) [Reguler]','127.0.0.1','2026-01-26 05:34:23'),(432,'Admin','Tambah Mata Pelajaran','Menambahkan mapel Istirahat I (C)','127.0.0.1','2026-01-26 05:35:08'),(433,'Admin','Tambah Mata Pelajaran','Menambahkan mapel Istirahat II (D)','127.0.0.1','2026-01-26 05:35:21'),(434,'Admin','Tambah Jam Mengajar','Menambahkan jam ke-B (08:55 - 09:20) [Reguler]','127.0.0.1','2026-01-26 11:52:12'),(435,'Admin','Tambah Jam Mengajar','Menambahkan jam ke-C (11:05 - 11:15) [Reguler]','127.0.0.1','2026-01-26 11:52:52'),(436,'Admin','Tambah Jam Mengajar','Menambahkan jam ke-D (06:50 - 07:10) [Reguler]','127.0.0.1','2026-01-26 11:53:25'),(437,'Admin','Update Jam Mengajar','Update jam ke-E (08:55 - 09:20) [Reguler]','127.0.0.1','2026-01-26 12:35:31'),(438,'Admin','Update Jam Mengajar','Update jam ke-F (11:05 - 11:15) [Reguler]','127.0.0.1','2026-01-26 12:35:43'),(439,'Admin','Update Jam Mengajar','Update jam ke-B (06:50 - 07:10) [Reguler]','127.0.0.1','2026-01-26 12:35:58'),(440,'Admin','Update Jam Mengajar','Update jam ke-C (08:55 - 09:20) [Reguler]','127.0.0.1','2026-01-26 12:36:12'),(441,'Admin','Update Jam Mengajar','Update jam ke-D (11:05 - 11:15) [Reguler]','127.0.0.1','2026-01-26 12:36:23'),(442,'Admin','Hapus Jam Mengajar','Menghapus jam ke-B [Reguler]','127.0.0.1','2026-01-26 12:40:39'),(443,'Admin','Update Jam Mengajar','Update jam ke-B (08:55 - 09:20) [Reguler]','127.0.0.1','2026-01-26 12:40:54'),(444,'Admin','Update Jam Mengajar','Update jam ke-C (11:05 - 11:15) [Reguler]','127.0.0.1','2026-01-26 12:41:04'),(445,'Admin','Tambah Mata Pelajaran','Menambahkan mapel Ekstrakurikuler (18)','127.0.0.1','2026-01-26 13:44:58'),(446,'Admin','Hapus Backup','Admin Admin menghapus backup file: backup_2026-01-25_05-07-51.sql','127.0.0.1','2026-01-26 14:19:26'),(447,'Admin','Hapus Backup','Admin Admin menghapus backup file: backup_2026-01-25_05-54-19.sql','127.0.0.1','2026-01-26 14:19:33');
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
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_backup_restore`
--

LOCK TABLES `tb_backup_restore` WRITE;
/*!40000 ALTER TABLE `tb_backup_restore` DISABLE KEYS */;
INSERT INTO `tb_backup_restore` VALUES (7,'backup_2026-01-25_17-02-18.sql','2026-01-25 17:02:19','49.31 KB','Backup manual');
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
  `kode_guru` varchar(50) DEFAULT NULL,
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
INSERT INTO `tb_guru` VALUES (18,'K','Abdul Ghofur, S.Pd.I','2444764667200003','Jepara','1986-11-12','Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"5\"]',NULL),(19,'I','Nur Huda, S.Pd.I.','5436757658200002','Jepara','1979-01-04','Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"6\"]','guru_19_1769260235.jpg'),(20,'A','Ah. Mustaqim Isom, A.Ma.','7841746648200002','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"5\",\"6\"]',NULL),(21,'M','Alfina Martha Sintya, S.Pd.','33200111223','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"4\"]',NULL),(22,'E','Ali Yasin, S.Pd.I','9547746647110022','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"3\"]',NULL),(23,'H','Hamidah, A.Ma.','4444747649200002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"2\"]',NULL),(24,'F','Indasah, A.Ma.','2640755657300002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"1\",\"2\"]',NULL),(25,'L','Khoiruddin, S.Pd.','ID20318581190001','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"5\"]',NULL),(26,'C','Musri`ah, S.Pd.I','6956748651300002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"3\"]',NULL),(27,'D','Nanik Purwati, S.Pd.I','6556755656300002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"4\"]',NULL),(28,'G','Nur Hidah, S.Pd.I.','7357760661300003','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"2\"]',NULL),(29,'J','Zama`ah, S.Pd.I.','8041756657300003','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"1\"]',NULL),(30,'B','Muhamad Junaedi','8552750652200002','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"5\",\"6\"]',NULL);
/*!40000 ALTER TABLE `tb_guru` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_jadwal_pelajaran`
--

DROP TABLE IF EXISTS `tb_jadwal_pelajaran`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_jadwal_pelajaran` (
  `id_jadwal` int NOT NULL AUTO_INCREMENT,
  `kelas_id` int NOT NULL,
  `hari` varchar(20) NOT NULL,
  `jam_ke` varchar(10) NOT NULL,
  `mapel_id` int DEFAULT NULL,
  `guru_id` int DEFAULT NULL,
  `jenis` enum('Reguler','Ramadhan') NOT NULL DEFAULT 'Reguler',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_jadwal`),
  UNIQUE KEY `unique_schedule` (`kelas_id`,`hari`,`jam_ke`,`jenis`),
  KEY `mapel_id` (`mapel_id`),
  KEY `guru_id` (`guru_id`),
  CONSTRAINT `tb_jadwal_pelajaran_ibfk_1` FOREIGN KEY (`kelas_id`) REFERENCES `tb_kelas` (`id_kelas`) ON DELETE CASCADE,
  CONSTRAINT `tb_jadwal_pelajaran_ibfk_2` FOREIGN KEY (`mapel_id`) REFERENCES `tb_mata_pelajaran` (`id_mapel`) ON DELETE CASCADE,
  CONSTRAINT `tb_jadwal_pelajaran_ibfk_3` FOREIGN KEY (`guru_id`) REFERENCES `tb_guru` (`id_guru`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=94 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_jadwal_pelajaran`
--

LOCK TABLES `tb_jadwal_pelajaran` WRITE;
/*!40000 ALTER TABLE `tb_jadwal_pelajaran` DISABLE KEYS */;
INSERT INTO `tb_jadwal_pelajaran` VALUES (6,1,'Sabtu','A',18,29,'Reguler','2026-01-26 11:42:51','2026-01-26 11:42:56'),(7,1,'Sabtu','1',3,29,'Reguler','2026-01-26 11:43:04','2026-01-26 11:43:13'),(8,1,'Sabtu','2',2,29,'Reguler','2026-01-26 11:50:18','2026-01-26 11:50:30'),(9,1,'Sabtu','3',2,29,'Reguler','2026-01-26 11:50:40','2026-01-26 11:50:43'),(13,1,'Ahad','A',18,29,'Reguler','2026-01-26 11:56:20','2026-01-26 11:56:25'),(18,6,'Sabtu','A',18,19,'Reguler','2026-01-26 12:31:19','2026-01-26 12:31:23'),(19,6,'Sabtu','1',1,19,'Reguler','2026-01-26 12:31:28','2026-01-26 12:31:35'),(20,6,'Sabtu','2',1,19,'Reguler','2026-01-26 12:31:41','2026-01-26 12:31:47'),(21,6,'Sabtu','3',1,19,'Reguler','2026-01-26 12:31:51','2026-01-26 12:31:57'),(23,6,'Sabtu','4',1,19,'Reguler','2026-01-26 12:32:15','2026-01-26 12:32:24'),(24,6,'Sabtu','5',3,19,'Reguler','2026-01-26 12:32:29','2026-01-26 12:32:33'),(25,6,'Sabtu','6',3,19,'Reguler','2026-01-26 12:32:38','2026-01-26 12:32:43'),(26,6,'Sabtu','C',21,19,'Reguler','2026-01-26 12:32:59','2026-01-26 12:41:45'),(27,6,'Sabtu','7',2,19,'Reguler','2026-01-26 12:33:13','2026-01-26 12:33:18'),(28,6,'Sabtu','8',2,19,'Reguler','2026-01-26 12:33:23','2026-01-26 12:33:27'),(29,6,'Sabtu','9',2,19,'Reguler','2026-01-26 12:33:32','2026-01-26 12:33:36'),(30,1,'Senin','A',19,24,'Reguler','2026-01-26 12:37:36','2026-01-26 12:37:43'),(32,6,'Sabtu','B',20,19,'Reguler','2026-01-26 12:41:33','2026-01-26 12:41:41'),(33,1,'Sabtu','B',20,29,'Reguler','2026-01-26 12:41:56','2026-01-26 12:42:03'),(35,6,'Ahad','A',18,19,'Reguler','2026-01-26 13:41:51','2026-01-26 13:45:12'),(36,6,'Ahad','1',17,19,'Reguler','2026-01-26 13:42:02','2026-01-26 13:45:12'),(37,6,'Ahad','2',17,19,'Reguler','2026-01-26 13:42:15','2026-01-26 13:45:12'),(38,6,'Ahad','3',17,19,'Reguler','2026-01-26 13:42:28','2026-01-26 13:45:12'),(39,6,'Ahad','B',20,19,'Reguler','2026-01-26 13:43:23','2026-01-26 13:45:12'),(40,6,'Ahad','4',22,19,'Reguler','2026-01-26 13:43:40','2026-01-26 13:45:20'),(41,6,'Ahad','5',22,19,'Reguler','2026-01-26 13:45:25','2026-01-26 13:45:48'),(42,6,'Ahad','6',22,19,'Reguler','2026-01-26 13:45:36','2026-01-26 13:45:57'),(43,6,'Senin','A',19,19,'Reguler','2026-01-26 13:46:13','2026-01-26 13:46:37'),(44,6,'Senin','1',6,19,'Reguler','2026-01-26 13:46:27','2026-01-26 13:46:44'),(45,6,'Senin','2',6,19,'Reguler','2026-01-26 13:46:51','2026-01-26 13:47:02'),(46,6,'Senin','3',6,19,'Reguler','2026-01-26 13:47:07','2026-01-26 13:47:16'),(47,6,'Senin','B',20,19,'Reguler','2026-01-26 13:47:22','2026-01-26 13:47:30'),(48,6,'Senin','4',7,19,'Reguler','2026-01-26 13:47:44','2026-01-26 13:47:51'),(49,6,'Senin','5',7,19,'Reguler','2026-01-26 13:47:56','2026-01-26 13:48:02'),(50,6,'Senin','6',1,19,'Reguler','2026-01-26 13:48:07','2026-01-26 13:48:17'),(51,6,'Senin','C',21,19,'Reguler','2026-01-26 13:48:24','2026-01-26 13:48:33'),(52,6,'Senin','7',1,19,'Reguler','2026-01-26 13:48:37','2026-01-26 13:48:47'),(53,6,'Senin','8',11,19,'Reguler','2026-01-26 13:48:51','2026-01-26 13:49:06'),(54,6,'Senin','9',11,19,'Reguler','2026-01-26 13:49:11','2026-01-26 13:49:18'),(55,6,'Selasa','A',18,19,'Reguler','2026-01-26 13:49:33','2026-01-26 13:49:40'),(56,6,'Selasa','1',8,19,'Reguler','2026-01-26 13:49:45','2026-01-26 13:49:54'),(57,6,'Selasa','2',8,19,'Reguler','2026-01-26 13:49:59','2026-01-26 13:50:06'),(58,6,'Selasa','3',8,19,'Reguler','2026-01-26 13:50:10','2026-01-26 13:50:17'),(59,6,'Selasa','B',20,19,'Reguler','2026-01-26 13:50:22','2026-01-26 13:50:30'),(60,6,'Selasa','4',5,19,'Reguler','2026-01-26 13:50:34','2026-01-26 13:50:50'),(61,6,'Selasa','5',5,19,'Reguler','2026-01-26 13:50:56','2026-01-26 13:51:10'),(62,6,'Selasa','6',5,19,'Reguler','2026-01-26 13:51:15','2026-01-26 13:51:22'),(63,6,'Selasa','C',21,19,'Reguler','2026-01-26 13:51:31','2026-01-26 13:51:37'),(64,6,'Selasa','7',7,19,'Reguler','2026-01-26 13:51:44','2026-01-26 13:51:55'),(65,6,'Selasa','8',7,19,'Reguler','2026-01-26 13:51:59','2026-01-26 13:52:05'),(66,6,'Selasa','9',7,19,'Reguler','2026-01-26 13:52:10','2026-01-26 13:52:16'),(67,6,'Rabu','A',18,30,'Reguler','2026-01-26 13:52:31','2026-01-26 13:53:00'),(70,6,'Rabu','1',9,30,'Reguler','2026-01-26 13:55:32','2026-01-26 13:55:40'),(71,6,'Rabu','2',9,30,'Reguler','2026-01-26 13:55:45','2026-01-26 13:56:05'),(72,6,'Rabu','3',9,30,'Reguler','2026-01-26 13:56:13','2026-01-26 13:56:19'),(73,6,'Rabu','B',20,30,'Reguler','2026-01-26 13:56:26','2026-01-26 13:56:32'),(74,6,'Kamis','A',18,20,'Reguler','2026-01-26 13:56:46','2026-01-26 13:56:51'),(75,6,'Rabu','4',9,30,'Reguler','2026-01-26 13:58:27','2026-01-26 13:58:36'),(76,6,'Rabu','5',9,30,'Reguler','2026-01-26 13:58:40','2026-01-26 13:58:46'),(77,6,'Rabu','6',9,30,'Reguler','2026-01-26 13:58:55','2026-01-26 13:59:01'),(78,6,'Rabu','C',21,30,'Reguler','2026-01-26 13:59:06','2026-01-26 13:59:12'),(79,6,'Rabu','7',10,30,'Reguler','2026-01-26 13:59:17','2026-01-26 13:59:26'),(80,6,'Rabu','8',10,30,'Reguler','2026-01-26 14:00:19','2026-01-26 14:00:25'),(81,6,'Rabu','9',10,30,'Reguler','2026-01-26 14:00:29','2026-01-26 14:00:35'),(82,6,'Kamis','1',4,20,'Reguler','2026-01-26 14:00:43','2026-01-26 14:00:49'),(83,6,'Kamis','2',4,20,'Reguler','2026-01-26 14:00:54','2026-01-26 14:00:59'),(84,6,'Kamis','3',13,20,'Reguler','2026-01-26 14:01:08','2026-01-26 14:01:16'),(85,6,'Kamis','B',20,20,'Reguler','2026-01-26 14:01:23','2026-01-26 14:01:30'),(86,6,'Kamis','4',16,20,'Reguler','2026-01-26 14:01:41','2026-01-26 14:01:50'),(87,6,'Kamis','5',16,20,'Reguler','2026-01-26 14:01:55','2026-01-26 14:02:01'),(88,6,'Kamis','6',12,20,'Reguler','2026-01-26 14:02:17','2026-01-26 14:02:23'),(89,6,'Kamis','C',21,20,'Reguler','2026-01-26 14:02:29','2026-01-26 14:02:43'),(90,6,'Kamis','7',12,20,'Reguler','2026-01-26 14:02:36','2026-01-26 14:02:49'),(91,6,'Kamis','8',15,20,'Reguler','2026-01-26 14:02:53','2026-01-26 14:02:59'),(92,6,'Kamis','9',15,20,'Reguler','2026-01-26 14:03:04','2026-01-26 14:03:10'),(93,1,'Sabtu','1',3,29,'Ramadhan','2026-01-26 14:05:16','2026-01-26 14:05:25');
/*!40000 ALTER TABLE `tb_jadwal_pelajaran` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_jam_mengajar`
--

DROP TABLE IF EXISTS `tb_jam_mengajar`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_jam_mengajar` (
  `id_jam` int NOT NULL AUTO_INCREMENT,
  `jam_ke` varchar(50) NOT NULL,
  `waktu_mulai` time NOT NULL,
  `waktu_selesai` time NOT NULL,
  `jenis` enum('Reguler','Ramadhan') DEFAULT 'Reguler',
  PRIMARY KEY (`id_jam`),
  UNIQUE KEY `unique_jam_jenis` (`jam_ke`,`jenis`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_jam_mengajar`
--

LOCK TABLES `tb_jam_mengajar` WRITE;
/*!40000 ALTER TABLE `tb_jam_mengajar` DISABLE KEYS */;
INSERT INTO `tb_jam_mengajar` VALUES (1,'1','07:10:00','07:45:00','Reguler'),(2,'2','07:45:00','08:20:00','Reguler'),(3,'3','08:20:00','08:55:00','Reguler'),(4,'4','09:20:00','09:55:00','Reguler'),(5,'5','09:55:00','10:30:00','Reguler'),(6,'6','10:30:00','11:05:00','Reguler'),(7,'7','11:15:00','11:50:00','Reguler'),(8,'8','11:50:00','12:15:00','Reguler'),(9,'9','12:15:00','12:35:00','Reguler'),(11,'1','11:45:00','11:45:00','Ramadhan'),(12,'A','06:50:00','07:10:00','Reguler'),(13,'B','08:55:00','09:20:00','Reguler'),(14,'C','11:05:00','11:15:00','Reguler');
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
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_jurnal`
--

LOCK TABLES `tb_jurnal` WRITE;
/*!40000 ALTER TABLE `tb_jurnal` DISABLE KEYS */;
INSERT INTO `tb_jurnal` VALUES (3,1,24,'1,2','Al-Quran Hadis','Surat Al-Lahab','2026-01-22','2026-01-22 12:23:45'),(4,5,20,'1,2,3','Al-Quran Hadis','Surat An-Naas','2026-01-22','2026-01-22 12:59:30'),(7,6,19,'1,2,3','IPAS','Tata surya dan planet-planet','2026-01-23','2026-01-22 21:25:58'),(8,6,19,'1,2,3','IPAS','Tata surya dan planet-planet','2026-01-23','2026-01-22 21:26:14'),(9,6,19,'4,5','Bahasa Arab','Fiil Madli','2026-01-23','2026-01-22 22:39:57'),(10,2,24,'1,2','Akidah Akhlak','Akhlak terpuji','2026-01-23','2026-01-22 23:00:01'),(11,5,20,'1,2','Al-Quran Hadis','Hadis Tentang Shodakoh','2026-01-23','2026-01-22 23:08:45'),(12,6,19,'1,2,3','Bahasa Indonesia','Materi Persiapan TKA 2026','2026-01-24','2026-01-24 12:41:46'),(13,6,19,'1,2,3','Pendidikan Pancasila','Mengerjakan Uji Kompetensi Bab 1','2026-01-26','2026-01-26 00:21:04');
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
  `kode_mapel` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_mapel` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id_mapel`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_mata_pelajaran`
--

LOCK TABLES `tb_mata_pelajaran` WRITE;
/*!40000 ALTER TABLE `tb_mata_pelajaran` DISABLE KEYS */;
INSERT INTO `tb_mata_pelajaran` VALUES (1,'7','Bahasa Indonesia'),(2,'5','Bahasa Arab'),(3,'2','Akidah Akhlak'),(4,'1','Al-Quran Hadis'),(5,'4','Sejarah Kebudayaan Islam'),(6,'6','Pendidikan Pancasila'),(7,'20','IPAS'),(8,'11','PJOK'),(9,'10','Matematika'),(10,'14','Bahasa Inggris'),(11,'12','Seni Budaya'),(12,'16','Ke-NU-an'),(13,'17','Tajwid'),(14,'15','BTA'),(15,'13','Bahasa Jawa'),(16,'3','Fikih'),(17,'19','Kepramukaan'),(18,'A','Asmaul Husna dan Tadarrus'),(19,'B','Upacara Bendera'),(20,'C','Istirahat I'),(21,'D','Istirahat II'),(22,'18','Ekstrakurikuler');
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
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_notifikasi`
--

LOCK TABLES `tb_notifikasi` WRITE;
/*!40000 ALTER TABLE `tb_notifikasi` DISABLE KEYS */;
INSERT INTO `tb_notifikasi` VALUES (13,'Nur Huda, S.Pd.I. (Wali) telah mengirim kehadiran pada pukul 07:20 tanggal 26-01-2026','absensi_guru.php',1,'2026-01-26 00:20:17'),(14,'Nur Huda, S.Pd.I. telah mengisi jurnal mengajar kelas VI','jurnal_mengajar.php',1,'2026-01-26 00:21:05');
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
  `level` enum('admin','guru','wali','kepala_madrasah','tata_usaha') NOT NULL DEFAULT 'guru',
  `id_guru` int DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_pengguna`),
  KEY `id_guru` (`id_guru`),
  CONSTRAINT `tb_pengguna_ibfk_1` FOREIGN KEY (`id_guru`) REFERENCES `tb_guru` (`id_guru`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_pengguna`
--

LOCK TABLES `tb_pengguna` WRITE;
/*!40000 ALTER TABLE `tb_pengguna` DISABLE KEYS */;
INSERT INTO `tb_pengguna` VALUES (1,'Admin','$2y$10$AGvfJEpOAtAWYnws4pFgRutCpx3cjBB7tT5OzabhkHgR.HceF7ZIq','admin',NULL,'user_1769126130_LOGO.png'),(2,'Kepala','$2y$10$wKqi4ukTK96K3pUDi5/tq..vwpSS6JsLXOOD28zYfbY3Ceginvd.e','kepala_madrasah',NULL,NULL),(3,'TU','$2y$10$wHZGfb.iEFeJzSQ3EAZYYOGbVXVKheuH4.ihYVIg2Du7m20m5uP/W','tata_usaha',NULL,NULL);
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
  `nama_yayasan` varchar(255) DEFAULT 'YAYASAN PENDIDIKAN ISLAM',
  `nama_madrasah` varchar(200) NOT NULL,
  `kepala_madrasah` varchar(100) DEFAULT NULL,
  `tahun_ajaran` varchar(20) DEFAULT NULL,
  `semester` enum('Semester 1','Semester 2') DEFAULT NULL,
  `logo` varchar(100) DEFAULT NULL,
  `dashboard_hero_image` varchar(255) DEFAULT NULL,
  `tanggal_jadwal` date DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_profil_madrasah`
--

LOCK TABLES `tb_profil_madrasah` WRITE;
/*!40000 ALTER TABLE `tb_profil_madrasah` DISABLE KEYS */;
INSERT INTO `tb_profil_madrasah` VALUES (1,'YAYASAN SULTAN FATTAH JEPARA','MI Sultan Fattah Sukosono','Musriah, S.Pd.I.','2025/2026','Semester 2','logo_1768301957.png','hero_1769291816.jpg','2025-07-14');
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
  `password` varchar(255) DEFAULT NULL,
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
INSERT INTO `tb_siswa` VALUES (22,'ABDULLAH HASAN','3184602457','L',1,'$2y$10$LmIhsicDZbNcuG6olyGkIO2C8ahVGgEx4AHLQQJZhHKyHGiiiuuhq'),(23,'ABIZAR HABIBILLAH','3184275775','L',1,'$2y$10$g2hTsLpRKbNvFsGkO9upwukGnJ2We6aeAvDiq.gFyzk4od3RXLrBa'),(24,'ADHITAMA ELVAN SYAHREZA','3180229036','L',1,'$2y$10$2L6RpsdmgspXSsYALOsv/OuHdQWFP3VSQaaks9eQzmRhkf6/daTim'),(25,'AHMAD MANUTHO MUHAMMAD','3182663303','L',1,'$2y$10$Lt.wvuZBSNAdipc0wFaJ.eheVUM9ypKie3cXGPqCClTcTgq52anhi'),(26,'AIRA ZAHWA SAFIRA','3194980092','P',1,'$2y$10$PA8TqMgMxbBaEEiFOnK/aOm/a/5rbakklsxjyu7WMh1xnZ2Is9H36'),(27,'ARFAN MIYAZ ALINDRA','3182355082','L',1,'$2y$10$UY7Pb/npFYj.vrWTLFATaO9G4XAfRawutxA3H9p5D6ikYLUYZHU.W'),(28,'DELISA ALYA SAFIQNA','3195153075','P',1,'$2y$10$ogCQovvwZdhD4uwUkME0VOosqzKaIydLZbVzB3Z6PhInZmnAr48Gi'),(29,'DIAN AIRA','3195813730','P',1,'$2y$10$3wZH0oP6Rrq81YhZEVWpcO/cOR6n7.okHrfopWkrrVnPrjBE.7cXm'),(30,'DHIRA QALESYA','3184245017','P',1,'$2y$10$plqI0PgGXAbMor5DErKvGetEJL92GgK0cgI/EhkvXGqmVOsaVqa4W'),(31,'HIBAT ALMALIK','3183882033','L',1,'$2y$10$Ko2FfsB1ye45jTH5Joeg.eg315RTCPuSejDTAdIqKsR3ABx4MgKda'),(32,'JIHAN FADHILLAH','3194274202','P',1,'$2y$10$YPMPBTYqM.FHgYqt2gllvuzEcU8XrqbDDYzrXYE3aBjCI39E9Ba..'),(33,'KAYLA PUTRI AMALIA','3177681680','P',1,'$2y$10$K30WPyMNBKyTRU05vsWFQe8Mw8fXNzyCVVXcrQB6RW46g01h6h1/a'),(34,'LAILATUL JANNATU AZZA','3190992049','P',1,'$2y$10$6KggFknKafZ0ROK65dV83u85U4pm.aRO20evQLh/1hwBdXWzSPT/6'),(35,'MAUWAFIQ KHOIRUL FAJAR','3172404776','L',1,'$2y$10$/7A0geAlcuqxndtyuqTTB.o.bxx9lV/3zmxDvHVn5bGhgWcV9EofW'),(36,'NORREIN NABIHA','3198116081','P',1,'$2y$10$m64PLAbB2BdjU0fJDjRO.OkyHwOpi9wBK/CWj.VYWhKC3ZuFVQgwK'),(37,'RHEVA PUTRI RAMADHANI','3186829907','P',1,'$2y$10$Lzil3MynYlkpQJGRd/MLpOkjZubtYivlE4oxFFFZckhtNtCWev9fq'),(38,'SALMA SHAFIRA RAYYANA','3188013385','P',1,'$2y$10$S0OAmCbn5qfzSD1UoKz4YOwLVv99bKvoGoV2/u5kA1nwY7y7.rnn.'),(39,'TUSAMMA SALSABILA','3184514039','P',1,'$2y$10$RziHqMaTSBlJnT966KMzHe1EIjSP6zZpjaXXfK3LMuukFqqvtgE8C'),(40,'ABIZARD ALTAN MUTTAQI','3179401623','L',2,'$2y$10$a0O0w/fyyFAmsjJXey2nfO/uC.IS.ksG33IhQzPt6EkY9IEkE.3d6'),(41,'AFWAN SETYO MANGGALA PUTRA','3174187068','L',2,'$2y$10$EgRinF.w4mRuRbj1hHRyM.UeEWfOB7pkcdfGC/WW.PkWgqN0odHVy'),(42,'AIRA DWI MAULIDA','3178039730','P',2,'$2y$10$aN5642LYkotRVmqx5vrMcOaWGCRUIaQ52mOCBSj0aqMeMxJpKbeRy'),(43,'AKHMAD SONY BINTANG ADITYA','3174857338','L',2,'$2y$10$R0uYKErLeVhWCyn7vmY2s.PN8Gv250kjJwisMj.mOg8bVZtTzVY16'),(44,'ALIF SAPUTRA','3181022161','L',2,'$2y$10$jq./WQ4D31z1kTEmBywsXuTMVv8/kVCtBi8kjkEUIKJitDnMOs9za'),(45,'ALMAQVIRA AULIA SHALIHA','3175785706','P',2,'$2y$10$eBfBfVjE/nbEJkwLmdG9TOb1Q5OUyHAHbItUkIl0zBg4AkuNqDVpK'),(46,'AURA LATISHA AQUINA','3181233911','P',2,'$2y$10$zpzsXE9Sm18GihEo.KV2femh7yzwn0zDIAB8QRJXcG50blWbUlYGi'),(47,'HAFIZ AL RASHAAD','3171065604','L',2,'$2y$10$Yv4nvKHF94tvqUTkeD/CheKy5BazLlXn3EIuJZdVcj9EbE9Tzdlmy'),(48,'HANIA NATASHA ADINDA AZZAHRA','3170498904','P',2,'$2y$10$Tp60bwN0ak6v/Kg4hOpNhOWk1G8V1k1w1lkUEJK8lpTFXxfYzDOt2'),(49,'MAHIR RIZQI ABDILLAH','3171957808','L',2,'$2y$10$svpC/iVCewUYaNW/afrVcO2nMnikOC4MX3d9N.UWWaue9DBhZGCVm'),(50,'MAULANA SYAFIQ RAMADHAN','3187786956','L',2,'$2y$10$BE5OzWzvp1BHYF081oo4w.RdSgu9GwFkjeFWM78HOBRYUu/.XYzCa'),(51,'MUHAMMAD AINUN NAJIB','3189060879','L',2,'$2y$10$qywtHiW9FrLcZDQ2OqAfLuIgBpmai3kJc8YOVTuYmZjJgDq8/1Qra'),(52,'MUHAMMAD AZKA DHIYA’UL HAQ','3187039124','L',2,'$2y$10$WHSprOvHDd9xkuhKVLONGubF/UFG0hleiy6NPiTzpdZt1rAqoyMfe'),(53,'MUHAMMAD HAFIZ MAULANA','3189975601','L',2,'$2y$10$AU4i/K/.8/UI38CpZ7iFK.1ddBlGV63tHJfyfcXD6/9iIV2mdnG.K'),(54,'MUHAMMAD MAHDI','3187106516','L',2,'$2y$10$p5TMq0l4o1HUejayFH.eG.ACc1zZkX2WcAa9a4y20wIJpCbgSODnC'),(55,'Muhammad Nadaril Saputra','3175823960','L',2,'$2y$10$HMPHQwTPJOsdmwdhB5HiDuBraf/ObCla0L92PnWBS/bQwT.FcC4Z6'),(56,'MUHAMMAD RIZQI MAULANA','3182266699','L',2,'$2y$10$GnEqMPZUa/a3ozT9KP50VurHrF8c.B7zrMTT5nHUvC2CgKUP5CEiu'),(57,'MUHAMMAD SABRIEL RAYYAN','3180027333','L',2,'$2y$10$a3Vk..kQAKKt18QjNTjrVOb/ILSV00n/alXFSf0bIurxz3/a690SC'),(58,'Nikeisya Sherin Aqila','0177572457','P',2,'$2y$10$B7/7gH/uW.A6GmkE8O3fqes9B3UcpHKpTweCttzw07Bs4cuiG3D.K'),(59,'NUR ERLINA ARDIRA','3171432750','P',2,'$2y$10$YB8AHTPHerXbLVoHGvSfHODl1Cp9rdcHHqAehJ1LI.uDckwFha3Um'),(60,'RAHMA SHEKHA ADINDA PUTRI','3176934840','P',2,'$2y$10$q8.bSd2e22A7wNFa22fR0uLOH7dKHTjs0JjBXm0DcyThbCivwfOhu'),(61,'Salsabilatun Najah','3184286316','P',2,'$2y$10$IWvCcgjL6BQjinv6g2i1yetH7B//t.6PDpaI7XhFg6rba3dzV0Qiy'),(62,'SRI HANDAYANI','3174413024','P',2,'$2y$10$CaexEcUIrRtvPeHptS8xGubkj0O3o7Ow6VoKczq3B4L1EP7BobO52'),(63,'ZALFA KHAIRUNNISA','3175059536','P',2,'$2y$10$JoYhqYk34H8UkALMTda6tOssLj2A2JCTkMHGrCuhZdf7BEvvgLwly'),(64,'AKBAR AL HANAN','3162535127','L',3,'$2y$10$iAXF5VtNx4yXX/Kj.B5v7OiHY8MaxeHkg0VzHhDzwAZYEZY4LMp0S'),(65,'ALMA NAFI\'A','3169440189','P',3,'$2y$10$SqU.k5JaMVFYJlF13PeoDOMrWxFzwiti3B4gm8shu9hxEuLW0A5CG'),(66,'ALZAM MAUZA ALINDRA','3164665566','L',3,'$2y$10$Erv0BrzEYFEciTSB1bw.ruyv7Zi7xaNcMDtqplUUpJiUJ87ayEUgS'),(67,'ANNISA MAZROATUL AHSANI','0166159155','P',3,'$2y$10$Jd0XMZgxpi./0lOp2YRBbuWyR9WcGlJf2HxfY1YLv4sTFNY5DsL7u'),(68,'ARINI NIHAYATUS SHOLIHAH','0163291739','P',3,'$2y$10$VG8lmptYK.6lCynpFXrUZeS4.wx/1yLDgm5QJWRD7GGp.Zm0ENcO2'),(69,'AURORA DIYATUL FILARDI','3169539885','P',3,'$2y$10$FDIRJqGCKJ6mf1ZbZ5ejYuQZnWs7RBmoZ9AUjo5YjpML.z8lSjgRi'),(70,'CITRA DWI LESTARI','3165720620','P',3,'$2y$10$i0lOo2EZYKnULunNo/3kfuPKpy6Bfijc754YXKvqKIoWrZmhp8zeq'),(71,'JOVAN PRATAMA','3163104174','L',3,'$2y$10$.ef5o1NhUYN2.I1lmA99G.OcdSGt3H1h.rx4Uv5n0rx45H5d2cHue'),(72,'KAYLA ANANDA SELFIA','3163851089','P',3,'$2y$10$zEgvul5s1pAEdfCSRaVVk.VJR/nycY9pl.LuT/y/QiG6gX/G3xwMG'),(73,'LAILATUZZAHRA','3168585316','P',3,'$2y$10$dO4FMp9BJ.Vf5QajYIYV0.gKXPfr4R0Nv8t2p68lm3DFhg.q8hTF2'),(74,'MUHAMMAD ARDIAN ERFA SAPUTRA','3168502077','L',3,'$2y$10$fmlFZv4jdc/3KnXw0jJ/heQWvPAXWDLetP9AbUjrYvzrnX9BVbJVm'),(75,'MUHAMMAD NUR YUSUF','3169048580','L',3,'$2y$10$8..J/nS4TXoe9nvaqXfsR.o6Qm7PDz0srdfgdOfAwgBbsMen2AhBW'),(76,'NUR ALIFATUL ZAHIRA','3177478000','P',3,'$2y$10$HkPkHTIfT511A23Q.FrIROPeRc2gMDUfsC3OW0qjvPo8jmNmL3j3e'),(77,'RAFA RASYIQUL UMAR','3165088631','L',3,'$2y$10$F3JGz/ftzopE2VURTEF50.WxxiNeqgItfs.YRTXVHhvnQp8FQG6cm'),(78,'REVA RAMADHANI','3163117591','P',3,'$2y$10$5fuypDcAvU6QmqfASutDieMKADNxoaib6rwdDL71l4J6bwKzmdEgG'),(79,'RIZQY AWWALUN PUTRA AHMAD','3168692801','L',3,'$2y$10$CQ5C9I9.Vq6XRdjqKQCAJujWNlr1z9PjukjB547eAu3y4hsJlaqnO'),(80,'SRI AISYAH AILANI ARKA','0166304991','P',3,'$2y$10$zKL9EGfIRS14vc24F.8fW.ugIXCUBJfY2Xk.CZRpbcq6MQF2WmBNq'),(81,'SYIFANA AWWALIYA','3162918924','P',3,'$2y$10$XOdqeQztNN72jrBgHv12gukSBEXs2uM3b9ay2hOCQZT7OLd50x1ty'),(82,'TITANIA WICENZA SETYA','3166299389','P',3,'$2y$10$EBtU1ALFpl4gfr2Ff3gR7OLgW6/7IeKlxMsdis5NQ0M5dKJxqawFG'),(83,'VEBY STEVANIE','3151044603','P',3,'$2y$10$WEroFN5p6ST3LhpYSi3FJ.3WzlM3znCzGFUOYVCbRAkeJ/ePGHveS'),(84,'AHMAD DAFA SAPUTRA','3157310312','L',4,'$2y$10$HcTvk.LbARnoQcHPLS4SxO4aBrPvtmQQ5y9ht.046MPjzT/xvM5nK'),(85,'ANDHARA NAURA LATIFAH','0159344416','P',4,'$2y$10$iVIV0gLuw7hOy0Y1mePk.OUIML.oxQJeeWojwBZl25UlXjGGSFjG6'),(86,'ASTI DAYINTA ELOK WIGUNA','3138636681','P',4,'$2y$10$BsZPnuyjNSbn7viHZL3zluIJaKQoFptbMwhXan5ZTW994EWBp1J3y'),(87,'AZKA ARDA YOGA','0158273255','L',4,'$2y$10$gOmb4DhtvaB/tDMq9TTuPOGf0qM/0EKouNVIyQIa13em9.zHAqdje'),(88,'BATARI ARSYAVIDYA AHMAD','0136511810','P',4,'$2y$10$5prDJP7nDlH1dikYIZaPzuqSJKofYQrhU0dnw0nM6L9TSTVbwjHwy'),(89,'FADLIL A\'LA','3156182504','L',4,'$2y$10$2WN9wP/FZm/S8.R/EUeGZ.uMnEWvAWzpB.XnL533EBZPUvDFfrvcO'),(90,'HAYU FALISHA LAIL','0159328720','P',4,'$2y$10$7WO.97MJ8C.TccMDrvEUiuy1p3LT6sK4xtpfxIvjVUKDSxlm0Wx4q'),(91,'MAFATIHUL KHOIR','0154732385','P',4,'$2y$10$0hCklfyHherSsMFnxFnxCeO6E7PIQVXZqQyKR40EtAMw5SupmvGrO'),(92,'MUHAMMAD ALGA NOVA','3151306061','L',4,'$2y$10$omeHHeDGUwrjmBJkx.L/BOs0ydVfq8ru0lBjbWl/.uXn1SAXW0zj6'),(93,'NILAM CAHYA RANI','3154268018','P',4,'$2y$10$44NNuUsL9dh9AIkF2EHp0.N9n47mZ3kq4sNnkUVUUWjqmGCd0FOoK'),(94,'NOR ALVIAN AZ ZAHRAN','0155498460','L',4,'$2y$10$2V7s/Vz2IOTdZHObAWOctOkx2AvdaWZp0VNrZtkzbhuvEBISE3QrK'),(95,'QIYANU JABAR MASA\'ID','3164754146','L',4,'$2y$10$24HYk7kZuLamoGZVcTz81ekeeTFlh7PKP3U96.Q6J9zReC32IvswS'),(96,'RATU VIOLA ZAZKIA ANNISA','3155089697','P',4,'$2y$10$aB9X0idMFoRPm5qsapXoGOjvWdw4cZA8Tw4WJQ9wPchIZlBLTYCR2'),(97,'AHMAD ARJUNNAJATA ILAMAULA','0146901301','L',5,'$2y$10$TNsRglG3EgdFOmGIEQHlZuWPgJEj9T3Mt2p9TLF0HtbxY44UiZqaG'),(98,'AHMAD ARSYIL ROHAN','0141948658','L',5,'$2y$10$kjmELtF/Id5N/0fl8nLoXeJyiPqt5v1UWE4yEvLIEKgqStGo/Qqq.'),(99,'ANGGITA CITRA ANINDITA','0153488311','P',5,'$2y$10$4SUPaNGIAyxlQc6gtgMpb.sEjGSSWJrKBjjma89q.h4K1pJs.mQfe'),(100,'BILQIS ANINDITA HUDA','3153353973','P',5,'$2y$10$gicrVNs1DHmocPGLCoRJe.GJBNtnZcUkTmBraPRZpfiZFKHK5b/.u'),(101,'EVA FANI VEBRIANI','3140721776','P',5,'$2y$10$VOa/TvaHgFgjJAvF78YkEOPIGUpQJYik3H6i1.v5k2MdEewQhcGH6'),(102,'FARID LINTANG ARDIANSYAH','0144317238','L',5,'$2y$10$nyyJoy0hgxd8JnDIHbrU7..rA/CVJAhQU1WdBhthLIBa6g.vfduc.'),(103,'FURJAH SABDA KENCANA','0144565120','P',5,'$2y$10$fNkxvRqzW0UQfO/IMMcJbuUg0t.U/A/hrFjS1U99UZj.t5VqoZ2Vq'),(104,'MUHAMMAD BAYU ALHABSYI HIKMAWAN','0146016714','L',5,'$2y$10$jaRObP1Aw6D9sOjKR0LzdO2XsReDokwJMwhoPaD4IwMwuEKc0c/si'),(105,'MUHAMMAD FAHRI ARDIANSYAH','3148398924','L',5,'$2y$10$IDS3ilZDQLLQsBGK3a6tNu0OgnMiMfuO.9hW8jDYBwDTjP3fODM9i'),(106,'MUHAMMAD MIRZA RAMADHAN','0134799480','L',5,'$2y$10$aSNPKWlo2Z9B6E7pedNbrO47uW3uNAtRRCuLSb2bJEqNS.mkXPn5K'),(107,'MUHAMMAD NAILUL FAIZ','0145967375','L',5,'$2y$10$yrTg421dHKIsEJeWOGX.DeU8UZhqLXOcCuPx8fWkyyUCrhRXUYpYq'),(108,'MUHAMMAD ROMY PRAYADINATA','0143035500','L',5,'$2y$10$Z6QpcpwG.NQIvFxYIiHvjuhvN0nXHqP2Jq2floWH/B8gsRe.RpJVK'),(109,'MUHAMMAD SAMSUL AD\'N','3140219560','L',5,'$2y$10$7wSgl.GXpzUb5beZjBl40ub8td8hFB8K4oOcjKtxoD7C2ayV6dgbC'),(110,'NAF\'AN KAIS AHMAD','3140985027','L',5,'$2y$10$GOW6FPjQRg9som60khFfmeNL3p5KYvsJe2vxDwWLhZDOiMipgAan.'),(111,'Rizal Arahman','3130235731','L',5,'$2y$10$sZaKj/9P9JkACvJOuRGrP.9ovBEhCQZZjx3DCbOmvOnNJzkeGh07W'),(112,'SAILIN ANJA SAFARA','0141467581','P',5,'$2y$10$nqjnwXdnMiB7z3jw5.3XVedq2yL/bBs5PizOAz3KeWWGkltw00pKW'),(113,'SEKAR NURI MAULIDA','3132469215','P',5,'$2y$10$ESbr5DYk2r9O3owApYeLO.RGeENT6sZyPqCqzUeRDNP38xpJ3ihu2'),(114,'ZANETA AQILA ANDIENAMIRA','3146697836','P',5,'$2y$10$.6YGroc3Kz/iBVcTrvwTrudAMN8fAAEgZSHRNEd6b0t68UTsex8t2'),(115,'ADIBA NUHA AZZAHRA','3146588936','P',6,'$2y$10$AhUxjaEtaeGZ2A6Lc8eyOuBEbRdAOZN3JaHKATXgsClhfQR2Naplu'),(116,'Amrina Rosyada','3137563185','P',6,'$2y$10$n97MFu6GQ73VG3544et2SOr3Em7xyHC6DXtEHUZvsSZFivrsSSvvq'),(117,'Aqilah Khoirurrosyadah','3135628625','P',6,'$2y$10$lUCH4OVV8agCj5jPG/53suqkThapwYg05OA4171YZGmZJwbAL16im'),(118,'Bilqis Fahiya Rifda','3132163433','P',6,'$2y$10$RFuAOGds4Nit9opwn74ehu3w1gS3dF7US5uGy.lxTGDSzDHtG5ER6'),(119,'Dewi Khuzaimah Annisa','3138275600','P',6,'$2y$10$deasWlBm7eZzJ3vR.VcbvevcqATaGRCvymP4RX2w0cRhbx3CsJP1m'),(120,'Diyah Ayu Prawesti','0137840437','P',6,'$2y$10$y505WD53PZ1Gfxjk7H77WennnniY1bBNx4fJnn20FXmq6VlrZ/5aG'),(121,'Dzakira Talita Azzahra','3137847985','P',6,'$2y$10$Vxk5OEVfqqukOn5fKM1Jw.j3oOy5xbRu.SaFE/5RSvkKc2R5kSPNy'),(122,'Fabregas Alviano','3133372371','L',6,'$2y$10$Bs185DSmv3iHLVTut/3sSevKmdShu2njxbRRKCNEvYZbZO6rn1d8.'),(123,'Indana Zulfa','3146510193','P',6,'$2y$10$Y9BhPiQlkovVYZQNkRq5VOEpNmp0JGVwz17OPjW6t9WZyr2qLaet6'),(124,'Lidia Aura Citra','3133041280','P',6,'$2y$10$pwOcqmzt4XFV87wkIy8hbuqCoujx65H.fY6oiWM0eoT1Xfo..msva'),(125,'Muhammad Agung Susilo Sugiono','3149297726','L',6,'$2y$10$Z7Me28NlHDepJAbuwbj/cujkw4pSx8f70qk3hZcB.xuckAvBncZU6'),(126,'Muhammad Daris Alfurqon Aqim','3130180823','L',6,'$2y$10$3BDlb8A2byhaShjO0YCNUuV2MQl4tWzhWPDZgrfip2wDZjJMtC9TC'),(127,'Muhammad Egi Ferdiansyah','3140702123','L',6,'$2y$10$FdMmIIR6MO0Br82/VkLBUevGztL9Vf61oBv4Mflws2HanrsxaqHsS'),(128,'Muhammad Elga Saputra','3130250384','L',6,'$2y$10$5EIf4K7Zxhen7ojE4cqmqesftlyvquMod38RmQPgP4L3PNWou1AUW'),(129,'Najwah Fadia Amalia Fitri','3141710676','P',6,'$2y$10$DoajKv9AO5CjIqr16y3kXut2h4i9YNL14mtFARQi3XByJqO.fsHC6'),(130,'Putra Sadewa Saifunnawas','3136264986','L',6,'$2y$10$NaK/5u21J4/WooFdH7mJceGVibNOWIUFs7Nxqphyrz928mKQlGXvm'),(131,'Rizquna Halalan Thoyyiba','3137207114','P',6,'$2y$10$zlezIOvKrT/Q0RCOWiyJNemw94fn/moozLoqFu2rb/uvLfVIykH3W'),(132,'Siti Afifah Nauvalyn Fikriyah','3131634863','P',6,'$2y$10$sStNnZ6ywtrbftbxcJLKUOhQmw2BqYtHmxKTqdkSOQpko2DTri8ka'),(133,'Siti Mei Listiana','3139561428','P',6,'$2y$10$sRGsF6K7qSLT0Tq6jWbYOeTP.6htZLqOqN4Y5fD/09dFXEGcpGZMm');
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

-- Dump completed on 2026-01-26 21:19:39
